<?php

declare(strict_types=1);

use App\ImportService;
use App\PushService;
use App\Repository;

require_once __DIR__ . '/layout.php';

[$config, $repository, $client] = bootstrap();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? null)) {
        flash('error', 'Ongeldige of verlopen sessie, probeer opnieuw.');
        redirect('index.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save' || $action === 'save_and_push') {
        $values = (array) ($_POST['value'] ?? []);
        $selected = array_map('intval', array_keys((array) ($_POST['selected'] ?? [])));
        $changed = 0;

        foreach ($values as $id => $value) {
            $id = (int) $id;
            $parameter = $repository->getParameter($id);
            if ($parameter === null) {
                continue;
            }
            if ((string) $parameter['local_value'] !== (string) $value) {
                $repository->updateParameterValue($id, (string) $value);
                $changed++;
            }
            $repository->setParameterSelected($id, in_array($id, $selected, true));
        }

        flash('success', sprintf('%d parameter(s) aangepast, %d aangeduid voor push.', $changed, count($selected)));
    }

    if ($action === 'push' || $action === 'save_and_push') {
        $result = (new PushService($repository, $client, $config))->run();
        flash($result['success'] ? 'success' : 'error', $result['message']);
    }

    if ($action === 'import') {
        $result = (new ImportService($repository, $client, $config))->run();
        flash($result['success'] ? 'success' : 'error', $result['message']);
    }

    redirect('index.php' . (isset($_POST['search']) && $_POST['search'] !== ''
        ? '?search=' . urlencode((string) $_POST['search'])
        : ''));
}

$search = trim((string) ($_GET['search'] ?? ''));
$records = $repository->listRecords($search);
$lastLogin = $repository->lastLogin();
$selectedCount = $repository->countSelectedParameters();

render_header('Data & parameters', 'data');
?>
<section class="status">
    <?php if ($lastLogin === null): ?>
        <p>Nog geen login op CallConnect uitgevoerd.</p>
    <?php else: ?>
        <p class="<?= $lastLogin['success'] ? 'ok' : 'nok' ?>">
            Laatste login op CallConnect: <strong><?= $lastLogin['success'] ? 'gelukt' : 'mislukt' ?></strong>
            op <?= e((string) $lastLogin['started_at']) ?>
            <?= $lastLogin['message'] !== null && $lastLogin['message'] !== '' ? '(' . e((string) $lastLogin['message']) . ')' : '' ?>
        </p>
    <?php endif; ?>
    <p><?= (int) $selectedCount ?> parameter(s) aangeduid om terug te pushen.</p>
</section>

<form method="post" action="index.php" class="toolbar">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="search" value="<?= e($search) ?>">
    <button type="submit">Ophalen uit CallConnect</button>
</form>

<form method="get" action="index.php" class="toolbar">
    <input type="search" name="search" value="<?= e($search) ?>" placeholder="Zoek op naam of id">
    <button type="submit">Zoeken</button>
</form>

<form method="post" action="index.php">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="search" value="<?= e($search) ?>">

    <?php if ($records === []): ?>
        <p class="empty">Geen data in de database. Klik op &laquo;Ophalen uit CallConnect&raquo; om te importeren.</p>
    <?php endif; ?>

    <?php foreach ($records as $record): ?>
        <section class="record">
            <h2><?= e((string) $record['label']) ?> <span class="id"><?= e((string) $record['external_id']) ?></span></h2>
            <table>
                <thead>
                <tr>
                    <th class="col-select">Push</th>
                    <th>Parameter</th>
                    <th>Waarde in CallConnect</th>
                    <th>Waarde in portaal</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($record['parameters'] as $parameter): ?>
                    <?php $id = (int) $parameter['id']; ?>
                    <tr class="status-<?= e((string) $parameter['status']) ?>">
                        <td class="col-select">
                            <input type="checkbox" name="selected[<?= $id ?>]" value="1"
                                   aria-label="Markeer <?= e((string) $parameter['name']) ?> om te pushen"
                                <?= (int) $parameter['selected'] === 1 ? 'checked' : '' ?>>
                        </td>
                        <td><?= e((string) $parameter['name']) ?></td>
                        <td class="remote"><?= e((string) $parameter['remote_value']) ?></td>
                        <td>
                            <input type="text" name="value[<?= $id ?>]" value="<?= e((string) $parameter['local_value']) ?>">
                        </td>
                        <td><?= e(status_label((string) $parameter['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endforeach; ?>

    <?php if ($records !== []): ?>
        <div class="actions">
            <button type="submit" name="action" value="save">Opslaan in database</button>
            <button type="submit" name="action" value="save_and_push">Opslaan en aangeduide naar CallConnect pushen</button>
            <button type="submit" name="action" value="push">Enkel pushen</button>
        </div>
    <?php endif; ?>
</form>
<?php
render_footer();

function status_label(string $status): string
{
    return match ($status) {
        Repository::STATUS_IN_SYNC => 'gelijk',
        Repository::STATUS_MODIFIED => 'gewijzigd',
        Repository::STATUS_PUSHED => 'gepusht',
        Repository::STATUS_FAILED => 'push mislukt',
        default => $status,
    };
}
