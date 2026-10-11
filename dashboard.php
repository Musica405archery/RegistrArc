<?php
// ============================================================================
// RegistrArc - dashboard.php
// Tableau de bord du concours : chiffres, répartition par mode / départ / club,
// factures émises, exports, sauvegarde, clôture du concours.
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

if (!$TourId) {
    die('Tournoi non défini');
}

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
// Chiffres
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$paymentModes = registrarc_load_payment_modes($TourId);
$engagements = registrarc_load_engagements($TourId);

$counts = ['due' => 0, 'paid' => 0, 'free' => 0, 'refunded' => 0];
$collected = 0;
$remaining = 0;
$byMode = [];
$bySession = [];
$byClub = [];

foreach ($engagements as $e) {
    $counts[$e['state']]++;

    $paidAmount = $e['state'] === 'paid' ? (float)$e['amount'] : 0;
    $dueAmount = $e['state'] === 'due' ? (float)$e['amount_due'] : 0;
    $collected += $paidAmount;
    $remaining += $dueAmount;

    if ($e['state'] === 'paid') {
        $mode = $e['payment_method'];
        $byMode[$mode] = ['count' => (isset($byMode[$mode]) ? $byMode[$mode]['count'] : 0) + 1, 'amount' => (isset($byMode[$mode]) ? $byMode[$mode]['amount'] : 0) + $paidAmount];
    }

    foreach ([[&$bySession, $e['session'] !== '' ? 'Départ ' . $e['session'] : 'Sans départ'], [&$byClub, $e['club'] !== '' ? $e['club'] : 'Sans club']] as $group) {
        $key = $group[1];

        if (!isset($group[0][$key])) {
            $group[0][$key] = ['count' => 0, 'due_count' => 0, 'collected' => 0, 'remaining' => 0];
        }

        $group[0][$key]['count']++;
        $group[0][$key]['due_count'] += $e['state'] === 'due' ? 1 : 0;
        $group[0][$key]['collected'] += $paidAmount;
        $group[0][$key]['remaining'] += $dueAmount;
    }
}

ksort($bySession, SORT_NATURAL);
ksort($byClub, SORT_NATURAL | SORT_FLAG_CASE);
$expected = $collected + $remaining;
$percent = $expected > 0 ? (int)round($collected * 100 / $expected) : 0;
$invoices = registrarc_invoices_for_tour($TourId);
$flash = registrarc_flash('dashboard');

$modeLabel = function ($code) use ($paymentModes) {
    return isset($paymentModes[$code]) ? $paymentModes[$code] : $code;
};

$PAGE_TITLE = 'RegistrArc - Tableau de bord';
include('Common/Templates/head.php');
registrarc_page_open('Tableau de bord', $tournament['name'], 'dashboard');
registrarc_render_flash($flash);
?>

<?php if ($isClosed): ?>
    <div class="rg-alert rg-alert-info"><strong>Concours clôturé :</strong> plus aucune modification n'est possible. Il peut être rouvert en bas de cette page.</div>
<?php endif; ?>

<div class="rg-card">
    <h2>Encaissement</h2>
    <div class="rg-figures">
        <div class="rg-figure"><strong><?php echo registrarc_format_montant($expected); ?> €</strong><span>attendu</span></div>
        <div class="rg-figure"><strong class="rg-paid"><?php echo registrarc_format_montant($collected); ?> €</strong><span>encaissé</span></div>
        <div class="rg-figure"><strong class="rg-unpaid"><?php echo registrarc_format_montant($remaining); ?> €</strong><span>reste dû</span></div>
        <div class="rg-figure"><strong><?php echo intval($counts['due']); ?></strong><span>engagement(s) à payer</span></div>
        <div class="rg-figure"><strong><?php echo intval($counts['paid']); ?></strong><span>payé(s)</span></div>
        <div class="rg-figure"><strong><?php echo intval($counts['free']); ?></strong><span>gratuit(s)</span></div>
        <div class="rg-figure"><strong><?php echo intval($counts['refunded']); ?></strong><span>remboursé(s)</span></div>
    </div>
    <div class="rg-bar" title="<?php echo $percent; ?> % encaissé"><span style="width: <?php echo $percent; ?>%;"></span></div>
    <p class="rg-help" style="margin-top:6px;"><?php echo $percent; ?> % du montant attendu est encaissé.</p>
</div>

<div class="rg-grid">
    <div class="rg-card">
        <h2>Par mode de paiement</h2>
        <table class="rg-table">
            <thead><tr><th>Mode</th><th class="right">Engagements</th><th class="right">Montant</th></tr></thead>
            <tbody>
                <?php foreach ($byMode as $code => $line): ?>
                    <tr><td><?php echo htmlspecialchars($modeLabel($code)); ?></td><td class="right num"><?php echo intval($line['count']); ?></td><td class="right num"><?php echo registrarc_format_montant($line['amount']); ?> €</td></tr>
                <?php endforeach; ?>
                <?php if (empty($byMode)): ?>
                    <tr><td colspan="3" class="rg-muted">Aucun paiement.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="rg-card">
        <h2>Par départ</h2>
        <table class="rg-table">
            <thead><tr><th>Départ</th><th class="right">Engagements</th><th class="right">Encaissé</th><th class="right">Reste dû</th></tr></thead>
            <tbody>
                <?php foreach ($bySession as $name => $line): ?>
                    <tr><td><?php echo htmlspecialchars($name); ?></td><td class="right num"><?php echo intval($line['count']); ?></td><td class="right num"><?php echo registrarc_format_montant($line['collected']); ?> €</td><td class="right num <?php echo $line['remaining'] > 0 ? 'rg-unpaid' : ''; ?>"><?php echo registrarc_format_montant($line['remaining']); ?> €</td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="rg-card">
    <h2>Par club</h2>
    <div class="rg-table-wrap">
        <table class="rg-table">
            <thead><tr><th>Club</th><th class="right">Engagements</th><th class="right">À payer</th><th class="right">Encaissé</th><th class="right">Reste dû</th></tr></thead>
            <tbody>
                <?php foreach ($byClub as $name => $line): ?>
                    <tr><td><?php echo htmlspecialchars($name); ?></td><td class="right num"><?php echo intval($line['count']); ?></td><td class="right num"><?php echo intval($line['due_count']); ?></td><td class="right num"><?php echo registrarc_format_montant($line['collected']); ?> €</td><td class="right num <?php echo $line['remaining'] > 0 ? 'rg-unpaid' : ''; ?>"><?php echo registrarc_format_montant($line['remaining']); ?> €</td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

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
