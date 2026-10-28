<?php
// ============================================================================
// RegistrArc - update.php
// Mise à jour du module depuis GitHub
//
// - update.php?action=check : JSON appelé en arrière-plan par index.php pour
//   afficher le bouton "Mise à jour" (réponse en cache, voir lib)
// - update.php              : versions installée / disponible, notes, bouton
//   d'installation (administrateur ianseo uniquement)
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc_update.inc.php');

// ---------------------------------------------------------------------------
// Vérification en arrière-plan
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'check') {
    checkACL(AclParticipants, AclReadOnly);

    $response = ['update' => false, 'local' => registrarc_local_version_number(), 'remote' => ''];

    // Seul un administrateur peut installer : inutile d'interroger GitHub sinon
    if (registrarc_can_update()) {
        $status = registrarc_update_status(false);
        $response['update'] = !empty($status['update']);
        $response['remote'] = $status['remote'];
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($response);
    exit();
}

// ---------------------------------------------------------------------------
// Page de mise à jour
// ---------------------------------------------------------------------------
checkACL(AclRoot, AclReadWrite);

$result = null;
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = registrarc_check_token();

    if ($message === '' && isset($_POST['apply_update'])) {
        $result = registrarc_update_apply();
    }
}

$status = registrarc_update_status(isset($_GET['force']) || $result !== null);
$cfg = registrarc_update_config();
$local = registrarc_local_version();

$PAGE_TITLE = "RegistrArc - Mise à jour";
include('Common/Templates/head.php');
?>
<style>
    .registrarc-update {
        max-width: 900px;
        margin: 20px auto;
        padding: 0 16px;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        color: #1f2937;
    }

    .registrarc-update h1 {
        font-size: 22px;
        margin: 0 0 16px;
    }

    .update-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 16px 18px;
        margin-bottom: 16px;
    }

    .update-versions {
        display: grid;
        grid-template-columns: max-content 1fr;
        gap: 6px 16px;
        margin: 0;
    }

    .update-versions dt {
        color: #6b7280;
    }

    .update-versions dd {
        margin: 0;
        font-weight: 600;
    }

    .update-notes {
        white-space: pre-wrap;
        background: #f9fafb;
        border-radius: 8px;
        padding: 10px 12px;
        margin: 10px 0 0;
        font-size: 13px;
    }

    .update-alert {
        border-radius: 8px;
        padding: 10px 14px;
        margin-bottom: 16px;
    }

    .update-alert-success { background: #dcfce7; color: #166534; }
    .update-alert-error { background: #fee2e2; color: #991b1b; }
    .update-alert-info { background: #e0f2fe; color: #075985; }

    .update-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
    }

    .btn-update,
    .btn-update-ghost {
        display: inline-flex;
        align-items: center;
        padding: 8px 16px;
        border-radius: 999px;
        font-size: 14px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        text-decoration: none;
    }

    .btn-update { background: #16a34a; color: #fff; }
    .btn-update:hover { background: #15803d; }
    .btn-update-ghost { background: #f3f4f6; color: #1f2937; }

    .update-files {
        font-size: 13px;
        color: #4b5563;
        margin: 6px 0 0;
    }
</style>

<div class="registrarc-update">
    <h1>Mise à jour du module RegistrArc</h1>

    <?php if ($message !== ''): ?>
        <div class="update-alert update-alert-error"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="update-alert <?php echo $result['ok'] ? 'update-alert-success' : 'update-alert-error'; ?>">
            <?php echo htmlspecialchars($result['message']); ?>

            <?php if (!empty($result['files'])): ?>
                <div class="update-files">
                    Fichiers remplacés : <?php echo htmlspecialchars(implode(', ', $result['files'])); ?>
                </div>
            <?php endif; ?>

            <?php if ($result['backup'] !== ''): ?>
                <div class="update-files">
                    Anciens fichiers sauvegardés dans <?php echo htmlspecialchars($result['backup']); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="update-card">
        <dl class="update-versions">
            <dt>Version installée</dt>
            <dd>
                <?php echo htmlspecialchars($status['local']); ?>
                <?php if ($local && !empty($local['date'])): ?>
                    <span style="font-weight:400;color:#6b7280;">(<?php echo htmlspecialchars($local['date']); ?>)</span>
                <?php endif; ?>
            </dd>

            <dt>Version disponible</dt>
            <dd>
                <?php if ($status['error'] !== ''): ?>
                    <span style="color:#991b1b;font-weight:400;">Vérification impossible : <?php echo htmlspecialchars($status['error']); ?></span>
                <?php else: ?>
                    <?php echo htmlspecialchars($status['remote']); ?>
                    <?php if ($status['date'] !== '' || !empty($status['commit'])): ?>
                        <span style="font-weight:400;color:#6b7280;">(<?php echo htmlspecialchars(implode(' · ', array_filter([$status['date'], !empty($status['commit']) ? 'commit ' . $status['commit'] : '']))); ?>)</span>
                    <?php endif; ?>
                <?php endif; ?>
            </dd>

            <dt>Source</dt>
            <dd style="font-weight:400;">
                <?php echo htmlspecialchars($cfg['github_url']); ?>
                — branche <?php echo htmlspecialchars($cfg['github_branch']); ?>
            </dd>

            <dt>Dernière vérification</dt>
            <dd style="font-weight:400;"><?php echo date('d/m/Y H:i', (int)$status['checked_at']); ?></dd>
        </dl>

        <?php if (!empty($status['update']) && $status['notes'] !== ''): ?>
            <div class="update-notes"><?php echo htmlspecialchars($status['notes']); ?></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($status['update'])): ?>
        <div class="update-alert update-alert-info">
            Les données du module (paiements, paramètres, remises de chèques dans data/) ne sont pas modifiées.
            Les fichiers remplacés sont sauvegardés dans data/ avant l'installation.
        </div>
    <?php elseif ($status['error'] === ''): ?>
        <div class="update-alert update-alert-success">Le module est à jour.</div>
    <?php endif; ?>

    <div class="update-actions">
        <?php if (!empty($status['update'])): ?>
            <form method="POST" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Installer la version ' . $status['remote'] . ' du module RegistrArc ?'), ENT_QUOTES); ?>);">
                <?php echo registrarc_form_fields(0); ?>
                <button type="submit" name="apply_update" value="1" class="btn-update">
                    ⬆ Installer la version <?php echo htmlspecialchars($status['remote']); ?>
                </button>
            </form>
        <?php endif; ?>

        <a href="update.php?force=1" class="btn-update-ghost">Vérifier à nouveau</a>
        <a href="index.php" class="btn-update-ghost">← Retour à la liste des engagements</a>
    </div>
</div>

<?php include('Common/Templates/tail.php'); ?>
