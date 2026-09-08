<?php

declare(strict_types=1);

use App\DiscoveryService;

require_once __DIR__ . '/layout.php';

[$config, $repository, $client] = bootstrap();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash('error', 'Ongeldige of verlopen sessie, probeer opnieuw.');
        redirect('discovery.php');
    }

    if ((string) ($_POST['action'] ?? '') === 'discover') {
        $result = (new DiscoveryService($repository, $client, $config))->run();
        flash($result['success'] ? 'success' : 'error', $result['message']);
    }

    redirect('discovery.php');
}

$runs = $repository->listDiscoveryRuns();
$runId = (int) ($_GET['run'] ?? ($runs[0]['id'] ?? 0));
$pages = $runId > 0 ? $repository->listDiscoveredPages($runId) : [];

$pageId = (int) ($_GET['page'] ?? 0);
$page = $pageId > 0 ? $repository->getDiscoveredPage($pageId) : null;
if ($page !== null && (int) $page['run_id'] !== $runId) {
    $page = null;
}
$fields = $page === null ? [] : $repository->listDiscoveredFields((int) $page['id']);

render_header('Ontdekte pagina\'s', 'discovery');
?>
<section class="status">
    <p>
        Ontdekking doorloopt na de login de CallConnect-pagina's vanaf de ingestelde startpagina's
        (<code><?= e(implode(', ', $config->seedPaths())) ?></code>), maximaal
        <?= (int) $config->discoveryMaxDepth ?> niveau(s) diep en <?= (int) $config->discoveryMaxPages ?> pagina('s).
    </p>
</section>

<form method="post" action="discovery.php" class="toolbar">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="discover">
    <button type="submit">Pagina's zoeken in CallConnect</button>
</form>

<section>
    <h2>Ontdekkingsronden</h2>
    <table>
        <thead>
        <tr><th>Gestart</th><th>Startpagina's</th><th>Pagina's</th><th>Velden</th><th>Bericht</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($runs as $row): ?>
            <tr class="<?= (int) $row['success'] === 1 ? 'ok' : 'nok' ?>">
                <td><?= e((string) $row['started_at']) ?></td>
                <td><?= e((string) ($row['seeds'] ?? '')) ?></td>
                <td><?= (int) $row['pages'] ?></td>
                <td><?= (int) $row['fields'] ?></td>
                <td><?= e((string) ($row['message'] ?? '')) ?></td>
                <td><a href="discovery.php?run=<?= (int) $row['id'] ?>">bekijken</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($runs === []): ?>
            <tr><td colspan="6">Nog geen ontdekking uitgevoerd.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<section>
    <h2>Gevonden pagina's</h2>
    <table>
        <thead>
        <tr><th>Pad</th><th>Titel</th><th>Diepte</th><th>HTTP</th><th>Tabbladen</th><th>Velden</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($pages as $row): ?>
            <?php $tabs = json_decode((string) ($row['tabs_json'] ?? '[]'), true) ?: []; ?>
            <tr>
                <td><?= e((string) $row['path']) ?></td>
                <td><?= e((string) $row['title']) ?></td>
                <td><?= (int) $row['depth'] ?></td>
                <td><?= e((string) ($row['status_code'] ?? '')) ?></td>
                <td><?= e(implode(', ', array_map('strval', $tabs))) ?></td>
                <td><?= (int) $row['field_count'] ?></td>
                <td><a href="discovery.php?run=<?= $runId ?>&amp;page=<?= (int) $row['id'] ?>">velden</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($pages === []): ?>
            <tr><td colspan="7">Nog geen pagina's gevonden voor deze ronde.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php if ($page !== null): ?>
    <section>
        <h2>Velden op <?= e((string) $page['path']) ?></h2>
        <table>
            <thead>
            <tr><th>Sectie</th><th>Soort</th><th>Label</th><th>Naam / id</th><th>Type</th><th>Waarde</th><th>Aangevinkt</th><th>Keuzes</th></tr>
            </thead>
            <tbody>
            <?php foreach ($fields as $field): ?>
                <?php $options = json_decode((string) ($field['options_json'] ?? '[]'), true) ?: []; ?>
                <tr>
                    <td><?= e((string) $field['section']) ?></td>
                    <td><?= e((string) $field['kind']) ?></td>
                    <td><?= e((string) $field['label']) ?></td>
                    <td><?= e(trim((string) $field['name'] . ' ' . (string) $field['element_id'])) ?></td>
                    <td><?= e((string) $field['field_type']) ?></td>
                    <td><?= e((string) $field['value']) ?></td>
                    <td><?= $field['selected'] === null ? '' : ((int) $field['selected'] === 1 ? 'ja' : 'nee') ?></td>
                    <td><?= e(implode(', ', array_map(
                        static fn (array $option): string => (string) ($option['label'] ?? $option['value'] ?? ''),
                        array_filter($options, 'is_array')
                    ))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($fields === []): ?>
                <tr><td colspan="8">Geen gestructureerde velden gevonden; bekijk de ruwe HTML hieronder.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <details>
            <summary>Ruwe HTML</summary>
            <pre class="payload"><?= e((string) ($page['raw_html'] ?? '')) ?></pre>
        </details>
    </section>
<?php endif; ?>
<?php
render_footer();
