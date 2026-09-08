<?php

declare(strict_types=1);

require_once __DIR__ . '/layout.php';

[$config, $repository] = bootstrap();

$loginLog = $repository->listLoginLog(50);
$pushLog = $repository->listPushLog(50);

render_header('Logboek', 'logs');
?>
<section>
    <h2>Login op CallConnect</h2>
    <table>
        <thead>
        <tr>
            <th>Gestart</th>
            <th>Beëindigd</th>
            <th>Gebruiker</th>
            <th>Resultaat</th>
            <th>HTTP</th>
            <th>Bericht</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($loginLog as $row): ?>
            <tr class="<?= (int) $row['success'] === 1 ? 'ok' : 'nok' ?>">
                <td><?= e((string) $row['started_at']) ?></td>
                <td><?= e((string) ($row['finished_at'] ?? '')) ?></td>
                <td><?= e((string) $row['username']) ?></td>
                <td><?= (int) $row['success'] === 1 ? 'gelukt' : 'mislukt' ?></td>
                <td><?= e((string) ($row['status_code'] ?? '')) ?></td>
                <td><?= e((string) ($row['message'] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($loginLog === []): ?>
            <tr><td colspan="6">Nog geen loginpogingen geregistreerd.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section>
    <h2>Push naar CallConnect</h2>
    <table>
        <thead>
        <tr>
            <th>Tijdstip</th>
            <th>Record</th>
            <th>Resultaat</th>
            <th>HTTP</th>
            <th>Bericht</th>
            <th>Payload</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($pushLog as $row): ?>
            <tr class="<?= (int) $row['success'] === 1 ? 'ok' : 'nok' ?>">
                <td><?= e((string) $row['created_at']) ?></td>
                <td><?= e((string) $row['external_id']) ?></td>
                <td><?= (int) $row['success'] === 1 ? 'gelukt' : 'mislukt' ?></td>
                <td><?= e((string) ($row['status_code'] ?? '')) ?></td>
                <td><?= e((string) ($row['message'] ?? '')) ?></td>
                <td class="payload"><?= e((string) $row['payload_json']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($pushLog === []): ?>
            <tr><td colspan="6">Nog geen pushes uitgevoerd.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>
<?php
render_footer();
