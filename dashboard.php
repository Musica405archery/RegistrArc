<?php
// ============================================================================
// RegistrArc - dashboard.php
// Tableau de bord du concours : chiffres, répartition par mode / départ / club,
// factures émises, exports, sauvegarde, clôture du concours.
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

$hasWriteRight = hasACL(AclParticipants, AclReadWrite);
$isClosed = registrarc_is_closed($TourId);

// ---------------------------------------------------------------------------
// Actions : clôturer / rouvrir le concours, restaurer une sauvegarde
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkACL(AclParticipants, AclReadWrite);

    $reopening = isset($_POST['reopen']);
    $formError = registrarc_check_form($TourId, $reopening);

    if ($formError !== '') {
        registrarc_flash('dashboard', $formError, 'error');
    } elseif (isset($_POST['close_tournament'])) {
        registrarc_set_closed($TourId, true);
        registrarc_flash('dashboard', 'Concours clôturé : plus aucune modification n\'est possible.', 'info');
    } elseif ($reopening) {
        registrarc_set_closed($TourId, false);
        registrarc_flash('dashboard', 'Concours rouvert.', 'success');
    } elseif (isset($_POST['restore_backup'])) {
        if (empty($_FILES['backup_file']['tmp_name']) || !is_uploaded_file($_FILES['backup_file']['tmp_name'])) {
            registrarc_flash('dashboard', 'Aucun fichier de sauvegarde sélectionné.', 'error');
        } else {
            $bundle = json_decode((string)file_get_contents($_FILES['backup_file']['tmp_name']), true);
            $result = registrarc_restore_backup($TourId, $bundle);
            registrarc_flash(
                'dashboard',
                $result['message'] . ($result['warning'] !== '' ? ' Attention : ' . $result['warning'] : ''),
                $result['ok'] ? ($result['warning'] !== '' ? 'warning' : 'success') : 'error'
            );
        }
    }

    header('Location: dashboard.php');
    exit();
}

// ---------------------------------------------------------------------------
// Données du tableau de bord : lib/registrarc_dashboard.inc.php. Les pages
// Greffe, Trésorier et Terrain sont dessinées dans le navigateur par
// assets/js/dashboard.js, sans bibliothèque externe. Aucun nom d'archer n'est transmis.
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$vizData = registrarc_dashboard_data($TourId);

$invoices = registrarc_invoices_for_tour($TourId);
$flash = registrarc_flash('dashboard');

$PAGE_TITLE = 'RegistrArc - Tableau de bord';
include('Common/Templates/head.php');
registrarc_page_open('Tableau de bord', $tournament['name'], 'dashboard');
registrarc_render_flash($flash);
?>
<link rel="stylesheet" href="<?php echo registrarc_asset_url('lib/js/leaflet/leaflet.css'); ?>">
<link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/dashboard.css'); ?>">

<?php if ($isClosed): ?>
    <div class="rg-alert rg-alert-info"><strong>Concours clôturé :</strong> plus aucune modification n'est possible. Il peut être rouvert en bas de cette page.</div>
<?php endif; ?>

<section class="rg-viz" id="rgViz" aria-label="Chiffres du concours">
    <div class="rg-viz-filters rg-noprint">
        <div class="rg-field">
            <span class="rg-viz-label" id="rgVizPageLabel">Page</span>
            <div class="rg-seg rg-viz-pages" role="tablist" aria-labelledby="rgVizPageLabel">
                <button type="button" role="tab" data-page="greffe" aria-selected="true">Greffe</button>
                <button type="button" role="tab" data-page="tresorier" aria-selected="false">Trésorier</button>
                <button type="button" role="tab" data-page="terrain" aria-selected="false">Terrain</button>
            </div>
        </div>
        <div class="rg-field">
            <label for="rgVizSession">Départ</label>
            <select id="rgVizSession">
                <option value="*">Tous les départs</option>
                <?php foreach ($vizData['sessions'] as $session): ?>
                    <option value="<?php echo htmlspecialchars($session); ?>"><?php echo htmlspecialchars($session !== '' ? 'Départ ' . $session : 'Sans départ'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div id="rgVizPage" role="tabpanel">
        <noscript><div class="rg-card"><p class="rg-help">Les chiffres et graphiques ont besoin de JavaScript.</p></div></noscript>
    </div>
</section>

<script type="application/json" id="rgVizData"><?php echo json_encode($vizData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
<!-- Carte des clubs : Leaflet 1.9.4 (BSD-2-Clause), fond OpenStreetMap chargé en ligne -->
<script src="<?php echo registrarc_asset_url('lib/js/leaflet/leaflet.js'); ?>"></script>
<script src="<?php echo registrarc_asset_url('assets/js/dashboard.js'); ?>"></script>

<div class="rg-card">
    <h2>Factures émises</h2>
    <?php if (empty($invoices)): ?>
        <p class="rg-help">Aucune facture émise pour ce concours. Une facture reçoit un numéro quand on clique sur « Émettre la facture » depuis son aperçu.</p>
    <?php else: ?>
        <div class="rg-table-wrap">
            <table class="rg-table">
                <thead><tr><th>Numéro</th><th>Date</th><th>Archers</th><th class="right">Total</th><th>Émise par</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($invoices as $number => $invoice): ?>
                        <?php
                        $names = [];

                        foreach ($invoice['snapshot']['rows'] as $row) {
                            $names[trim($row['nom'] . ' ' . $row['prenom'])] = true;
                        }
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($number); ?></strong></td>
                            <td class="num"><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($invoice['issued_at']))); ?></td>
                            <td><?php echo htmlspecialchars(implode(', ', array_keys($names))); ?></td>
                            <td class="right num"><?php echo registrarc_format_montant($invoice['snapshot']['total']); ?> €</td>
                            <td><?php echo htmlspecialchars(isset($invoice['operator']) ? $invoice['operator'] : ''); ?></td>
                            <td><a href="invoice.php?num=<?php echo rawurlencode($number); ?>" target="_blank">Voir / réimprimer</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="rg-grid">
    <div class="rg-card">
        <h2>Pour le trésorier</h2>
        <p class="rg-help">Fichiers à ouvrir dans un tableur (Excel, LibreOffice).</p>
        <div class="rg-form">
            <a class="rg-btn" href="export.php?type=engagements">Liste des engagements</a>
            <a class="rg-btn" href="export.php?type=journal">Journal des opérations</a>
            <a class="rg-btn" href="bankchequedeposite.php" target="_blank">Remises de chèques</a>
            <a class="rg-btn" href="caisse.php">Caisse</a>
        </div>
    </div>

    <div class="rg-card">
        <h2>Outils</h2>
        <p class="rg-help">Paiements faits en ligne et étiquettes pour retrouver un archer au kiosque.</p>
        <div class="rg-form">
            <a class="rg-btn" href="helloasso.php">Importer des paiements HelloAsso</a>
            <a class="rg-btn" href="qrcodes.php">Imprimer les QR codes</a>
            <a class="rg-btn" href="clubs.php">Villes des clubs (carte)</a>
        </div>
    </div>
</div>

<div class="rg-grid">
    <div class="rg-card">
        <h2>Sauvegarde</h2>
        <p class="rg-help">Un seul fichier avec tous les paiements, réglages, notes, remises, clôtures et factures de ce concours.</p>
        <div class="rg-form">
            <a class="rg-btn rg-btn-primary" href="export.php?type=backup">Télécharger une sauvegarde</a>
        </div>

        <?php if ($hasWriteRight && !$isClosed): ?>
            <form method="POST" enctype="multipart/form-data" class="rg-form" style="margin-top:14px;" onsubmit="return confirm('Restaurer cette sauvegarde ? Les données actuelles du concours seront remplacées (une copie de secours est conservée).');">
                <?php echo registrarc_form_fields($TourId); ?>
                <div class="rg-field">
                    <label for="backup_file">Restaurer une sauvegarde</label>
                    <input type="file" name="backup_file" id="backup_file" accept="application/json,.json">
                </div>
                <button type="submit" name="restore_backup" value="1" class="rg-btn">Restaurer</button>
            </form>
        <?php endif; ?>
    </div>

    <div class="rg-card">
        <h2>Fin du concours</h2>
        <?php if ($isClosed): ?>
            <p class="rg-help">Le concours est clôturé. Le rouvrir permet de nouveau d'encaisser et de modifier.</p>
            <?php if ($hasWriteRight): ?>
                <form method="POST">
                    <?php echo registrarc_form_fields($TourId); ?>
                    <button type="submit" name="reopen" value="1" class="rg-btn">Rouvrir le concours</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="rg-help">Une fois les comptes faits, clôturez le concours : plus personne ne pourra encaisser ni modifier, jusqu'à sa réouverture.</p>
            <?php if ($hasWriteRight): ?>
                <form method="POST" onsubmit="return confirm('Clôturer le concours ? Plus aucune modification ne sera possible jusqu\'à sa réouverture.');">
                    <?php echo registrarc_form_fields($TourId); ?>
                    <button type="submit" name="close_tournament" value="1" class="rg-btn rg-btn-danger">Clôturer le concours</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
registrarc_page_close();
include('Common/Templates/tail.php');
