<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * All database access for records, parameters and the log tables.
 */
final class Repository
{
    public const STATUS_IN_SYNC = 'in_sync';
    public const STATUS_MODIFIED = 'modified';
    public const STATUS_PUSHED = 'pushed';
    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly PDO $pdo)
    {
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    /* ------------------------------------------------------------ login log */

    public function startLoginAttempt(string $username): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO login_log (started_at, username) VALUES (?, ?)');
        $stmt->execute([$this->now(), $username]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finishLoginAttempt(int $id, bool $success, ?int $statusCode, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE login_log SET finished_at = ?, success = ?, status_code = ?, message = ? WHERE id = ?'
        );
        $stmt->execute([$this->now(), $success ? 1 : 0, $statusCode, $message, $id]);
    }

    public function listLoginLog(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM login_log ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function lastLogin(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM login_log ORDER BY id DESC LIMIT 1')->fetch();

        return $row === false ? null : $row;
    }

    /* -------------------------------------------------------------- records */

    /**
     * Inserts or updates a record plus its parameters. Local edits that were
     * not pushed yet are kept; only the remote value is refreshed.
     *
     * @param array<string, scalar|null> $parameters
     * @param array<string, mixed>       $raw
     */
    public function upsertRecord(string $externalId, string $label, array $parameters, array $raw): int
    {
        $now = $this->now();
        $stmt = $this->pdo->prepare(
            'INSERT INTO records (external_id, label, raw_json, imported_at, updated_at)
             VALUES (:external_id, :label, :raw_json, :imported_at, :updated_at)
             ON DUPLICATE KEY UPDATE label = VALUES(label), raw_json = VALUES(raw_json), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            ':external_id' => $externalId,
            ':label' => $label,
            ':raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':imported_at' => $now,
            ':updated_at' => $now,
        ]);

        $recordId = $this->findRecordId($externalId);
        foreach ($parameters as $name => $value) {
            $this->upsertParameter($recordId, (string) $name, $value === null ? '' : (string) $value);
        }

        return $recordId;
    }

    public function findRecordId(string $externalId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM records WHERE external_id = ?');
        $stmt->execute([$externalId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException(sprintf('Record "%s" not found', $externalId));
        }

        return (int) $id;
    }

    public function upsertParameter(int $recordId, string $name, string $remoteValue): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM parameters WHERE record_id = ? AND name = ?');
        $stmt->execute([$recordId, $name]);
        $existing = $stmt->fetch();

        if ($existing === false) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO parameters (record_id, name, remote_value, local_value, selected, status, updated_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)'
            );
            $stmt->execute([$recordId, $name, $remoteValue, $remoteValue, self::STATUS_IN_SYNC, $this->now()]);

            return;
        }

        $keepLocal = in_array($existing['status'], [self::STATUS_MODIFIED, self::STATUS_FAILED], true);
        $localValue = $keepLocal ? $existing['local_value'] : $remoteValue;
        $status = $keepLocal ? $existing['status'] : self::STATUS_IN_SYNC;

        $stmt = $this->pdo->prepare(
            'UPDATE parameters SET remote_value = ?, local_value = ?, status = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$remoteValue, $localValue, $status, $this->now(), (int) $existing['id']]);
    }

    /**
     * @return array<int, array<string, mixed>> records with a "parameters" key
     */
    public function listRecords(string $search = ''): array
    {
        if ($search === '') {
            $records = $this->pdo->query('SELECT * FROM records ORDER BY label, external_id')->fetchAll();
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM records WHERE label LIKE ? OR external_id LIKE ? ORDER BY label, external_id'
            );
            $like = '%' . $search . '%';
            $stmt->execute([$like, $like]);
            $records = $stmt->fetchAll();
        }

        $parameters = $this->pdo->query('SELECT * FROM parameters ORDER BY name')->fetchAll();
        foreach ($records as &$record) {
            $record['parameters'] = array_values(array_filter(
                $parameters,
                static fn (array $parameter): bool => (int) $parameter['record_id'] === (int) $record['id']
            ));
        }

        return $records;
    }

    public function getParameter(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM parameters WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Parameters that were marked (selected) in the portal and still contain a
     * change that has to be pushed back to CallConnect.
     */
    public function listSelectedParameters(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, r.external_id FROM parameters p
             INNER JOIN records r ON r.id = p.record_id
             WHERE p.selected = 1 AND p.status IN (?, ?)
             ORDER BY r.external_id, p.name'
        );
        $stmt->execute([self::STATUS_MODIFIED, self::STATUS_FAILED]);

        return $stmt->fetchAll();
    }

    public function countSelectedParameters(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM parameters WHERE selected = 1 AND status IN (?, ?)'
        );
        $stmt->execute([self::STATUS_MODIFIED, self::STATUS_FAILED]);

        return (int) $stmt->fetchColumn();
    }

    /* -------------------------------------------------------- portal edits */

    public function updateParameterValue(int $id, string $localValue): ?array
    {
        $parameter = $this->getParameter($id);
        if ($parameter === null) {
            return null;
        }

        if ($parameter['status'] === self::STATUS_PUSHED && $localValue === (string) $parameter['local_value']) {
            return $parameter;
        }

        $status = $localValue === (string) $parameter['remote_value'] ? self::STATUS_IN_SYNC : self::STATUS_MODIFIED;
        $stmt = $this->pdo->prepare(
            'UPDATE parameters SET local_value = ?, status = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$localValue, $status, $this->now(), $id]);

        return $this->getParameter($id);
    }

    public function setParameterSelected(int $id, bool $selected): ?array
    {
        if ($this->getParameter($id) === null) {
            return null;
        }

        $stmt = $this->pdo->prepare('UPDATE parameters SET selected = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$selected ? 1 : 0, $this->now(), $id]);

        return $this->getParameter($id);
    }

    public function markParameterPushed(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE parameters SET remote_value = local_value, selected = 0, status = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([self::STATUS_PUSHED, $this->now(), $id]);
    }

    public function markParameterFailed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE parameters SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([self::STATUS_FAILED, $this->now(), $id]);
    }

    /* ------------------------------------------------------------ push log */

    public function logPush(string $externalId, array $payload, bool $success, ?int $statusCode, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO push_log (created_at, external_id, payload_json, success, status_code, message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->now(),
            $externalId,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $success ? 1 : 0,
            $statusCode,
            $message,
        ]);
    }

    public function listPushLog(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM push_log ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /* ----------------------------------------------------------- discovery */

    /**
     * @param array<int, string> $seeds
     */
    public function startDiscoveryRun(array $seeds): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO discovery_runs (started_at, seeds) VALUES (?, ?)');
        $stmt->execute([$this->now(), implode(', ', $seeds)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function finishDiscoveryRun(int $runId, bool $success, int $pages, int $fields, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE discovery_runs SET finished_at = ?, success = ?, pages = ?, fields = ?, message = ? WHERE id = ?'
        );
        $stmt->execute([$this->now(), $success ? 1 : 0, $pages, $fields, $message, $runId]);
    }

    /**
     * Stores one crawled page plus everything that was extracted from it. The
     * raw HTML is always kept, so fields that are not recognised yet can still
     * be reviewed later.
     *
     * @param array<string, mixed>             $page
     * @param array<int, array<string, mixed>> $fields
     */
    public function saveDiscoveredPage(int $runId, array $page, array $fields): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO discovery_pages
                (run_id, url, path, title, depth, status_code, content_type, tabs_json, links_json, forms_json, raw_html, fetched_at)
             VALUES (:run_id, :url, :path, :title, :depth, :status_code, :content_type, :tabs, :links, :forms, :raw_html, :fetched_at)
             ON DUPLICATE KEY UPDATE
                url = VALUES(url), title = VALUES(title), depth = VALUES(depth), status_code = VALUES(status_code),
                content_type = VALUES(content_type), tabs_json = VALUES(tabs_json), links_json = VALUES(links_json),
                forms_json = VALUES(forms_json), raw_html = VALUES(raw_html), fetched_at = VALUES(fetched_at)'
        );
        $stmt->execute([
            ':run_id' => $runId,
            ':url' => mb_substr((string) ($page['url'] ?? ''), 0, 1024),
            ':path' => mb_substr((string) ($page['path'] ?? ''), 0, 190),
            ':title' => mb_substr((string) ($page['title'] ?? ''), 0, 255),
            ':depth' => (int) ($page['depth'] ?? 0),
            ':status_code' => $page['status_code'] ?? null,
            ':content_type' => mb_substr((string) ($page['content_type'] ?? ''), 0, 190),
            ':tabs' => $this->encode($page['tabs'] ?? []),
            ':links' => $this->encode($page['links'] ?? []),
            ':forms' => $this->encode($page['forms'] ?? []),
            ':raw_html' => (string) ($page['raw_html'] ?? ''),
            ':fetched_at' => $this->now(),
        ]);

        $pageId = (int) $this->pdo->lastInsertId();
        if ($pageId === 0) {
            $stmt = $this->pdo->prepare('SELECT id FROM discovery_pages WHERE run_id = ? AND path = ?');
            $stmt->execute([$runId, mb_substr((string) ($page['path'] ?? ''), 0, 190)]);
            $pageId = (int) $stmt->fetchColumn();
        }

        $this->pdo->prepare('DELETE FROM discovery_fields WHERE page_id = ?')->execute([$pageId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO discovery_fields
                (page_id, kind, section, label, name, element_id, field_type, value, selected, options_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($fields as $field) {
            $selected = $field['selected'] ?? null;
            $insert->execute([
                $pageId,
                mb_substr((string) ($field['kind'] ?? 'detail'), 0, 32),
                mb_substr((string) ($field['section'] ?? ''), 0, 255),
                mb_substr((string) ($field['label'] ?? ''), 0, 255),
                mb_substr((string) ($field['name'] ?? ''), 0, 255),
                mb_substr((string) ($field['element_id'] ?? ''), 0, 255),
                mb_substr((string) ($field['type'] ?? ''), 0, 64),
                (string) ($field['value'] ?? ''),
                $selected === null ? null : ($selected ? 1 : 0),
                $this->encode($field['options'] ?? []),
            ]);
        }

        return $pageId;
    }

    public function lastDiscoveryRun(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM discovery_runs ORDER BY id DESC LIMIT 1')->fetch();

        return $row === false ? null : $row;
    }

    public function listDiscoveryRuns(int $limit = 25): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discovery_runs ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDiscoveredPages(?int $runId = null): array
    {
        if ($runId === null) {
            $runId = (int) ($this->lastDiscoveryRun()['id'] ?? 0);
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.*, (SELECT COUNT(*) FROM discovery_fields f WHERE f.page_id = p.id) AS field_count
             FROM discovery_pages p WHERE p.run_id = ? ORDER BY p.depth, p.path'
        );
        $stmt->execute([$runId]);

        return $stmt->fetchAll();
    }

    public function getDiscoveredPage(int $pageId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discovery_pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDiscoveredFields(int $pageId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM discovery_fields WHERE page_id = ? ORDER BY id');
        $stmt->execute([$pageId]);

        return $stmt->fetchAll();
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
