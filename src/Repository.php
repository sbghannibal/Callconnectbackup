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
}
