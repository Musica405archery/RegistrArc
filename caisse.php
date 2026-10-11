<?php
// ============================================================================
// RegistrArc - caisse.php
// Caisse : opérations depuis la dernière clôture, totaux par mode de paiement,
// clôture avec comptage des espèces, historique des clôtures.
//
// caisse.php             : caisse en cours
// caisse.php?cloture=C2  : rapport d'une clôture (imprimable)
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

$canWrite = registrarc_can_write($TourId);
$paymentModes = registrarc_load_payment_modes($TourId);
$tournament = registrarc_get_tournament_info($TourId);

// ---------------------------------------------------------------------------
// Clôturer la caisse
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_cash'])) {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        registrarc_flash('caisse', $formError, 'error');
    } else {
        $counted = isset($_POST['cash_counted']) ? trim(str_replace(',', '.', (string)$_POST['cash_counted'])) : '';
        $countedCash = ($counted !== '' && is_numeric($counted)) ? round((float)$counted, 2) : null;
        $closureId = registrarc_close_cash($TourId, $countedCash, isset($_POST['comment']) ? $_POST['comment'] : '');

        if ($closureId !== '') {
            registrarc_flash('caisse', 'Caisse clôturée (' . $closureId . ').', 'success');
            header('Location: caisse.php?cloture=' . rawurlencode($closureId));
            exit();
        }

        registrarc_flash('caisse', "Clôture impossible : erreur d'écriture." . registrarc_storage_error_suffix(), 'error');
    }

    header('Location: caisse.php');
    exit();
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$closures = registrarc_load_closures($TourId);
$closureParam = isset($_GET['cloture']) ? (string)$_GET['cloture'] : '';
$closure = null;

foreach ($closures as $item) {
    if ((string)$item['id'] === $closureParam) {
        $closure = $item;
    }
}

if ($closure) {
    $summary = registrarc_cash_summary($TourId, intval($closure['journal_from']), intval($closure['journal_to']));
} else {
    $period = registrarc_current_cash_period($TourId);
    $periodStart = $period['since'];
    $summary = registrarc_cash_summary($TourId, $period['index']);
}

$cashExpected = isset($summary['totals']['ESP']) ? $summary['totals']['ESP'] : 0;

$modeLabel = function ($code) use ($paymentModes) {
    return isset($paymentModes[$code]) ? $paymentModes[$code] : $code;
};

$formatDate = function ($value) {
    return $value !== '' ? date('d/m/Y H:i', strtotime($value)) : 'début du concours';
};

$flash = registrarc_flash('caisse');

$PAGE_TITLE = 'RegistrArc - Caisse';
include('Common/Templates/head.php');
registrarc_page_open('Caisse', $tournament['name'], 'caisse');
registrarc_render_flash($flash);
?>

<div class="rg-card">
    <?php if ($closure): ?>
        <h2>Clôture <?php echo htmlspecialchars($closure['id']); ?></h2>
        <p class="rg-help">
            Du <?php echo htmlspecialchars($formatDate((string)$closure['from'])); ?>
            au <?php echo htmlspecialchars($formatDate((string)$closure['to'])); ?>
            <?php echo !empty($closure['operator']) ? ' · clôturée par ' . htmlspecialchars($closure['operator']) : ''; ?>
            <?php echo !empty($closure['comment']) ? ' · ' . htmlspecialchars($closure['comment']) : ''; ?>
        </p>
    <?php else: ?>
        <h2>Caisse en cours</h2>
        <p class="rg-help">Opérations depuis le <?php echo htmlspecialchars($formatDate($periodStart)); ?>.</p>
    <?php endif; ?>

    <div class="rg-grid">
        <div>
            <table class="rg-table">
                <thead>
                    <tr><th>Mode de paiement</th><th class="right">Montant</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($summary['totals'] as $code => $amount): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($modeLabel($code)); ?></td>
                            <td class="right num"><?php echo registrarc_format_montant($amount); ?> €</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($summary['totals'])): ?>
                        <tr><td colspan="2" class="rg-muted">Aucune opération.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr><td>Total (<?php echo intval($summary['count']); ?> opération(s))</td><td class="right num"><?php echo registrarc_format_montant($summary['total']); ?> €</td></tr>
                </tfoot>
            </table>
        </div>

        <div>
            <div class="rg-figures">
                <div class="rg-figure">
                    <strong><?php echo registrarc_format_montant($cashExpected); ?> €</strong>
                    <span>Espèces attendues</span>
                </div>

                <?php if ($closure && $closure['cash_counted'] !== null): ?>
                    <div class="rg-figure">
                        <strong><?php echo registrarc_format_montant($closure['cash_counted']); ?> €</strong>
                        <span>Espèces comptées</span>
                    </div>
                    <div class="rg-figure">
                        <strong class="<?php echo (float)$closure['cash_difference'] == 0 ? 'rg-paid' : 'rg-unpaid'; ?>"><?php echo ((float)$closure['cash_difference'] > 0 ? '+' : '') . registrarc_format_montant($closure['cash_difference']); ?> €</strong>
                        <span>Écart</span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$closure && $canWrite): ?>
                <form method="POST" class="rg-form rg-noprint" style="margin-top:14px;" onsubmit="return confirm('Clôturer la caisse ? Les prochaines opérations compteront pour la clôture suivante.');">
                    <?php echo registrarc_form_fields($TourId); ?>
                    <div class="rg-field">
                        <label for="cash_counted">Espèces comptées (€)</label>
                        <input type="text" inputmode="decimal" name="cash_counted" id="cash_counted" placeholder="<?php echo registrarc_format_montant($cashExpected); ?>" size="10">
                    </div>
                    <div class="rg-field">
                        <label for="comment">Commentaire</label>
                        <input type="text" name="comment" id="comment" maxlength="200" placeholder="Ex. : samedi matin" size="22">
                    </div>
                    <button type="submit" name="close_cash" value="1" class="rg-btn rg-btn-primary">Clôturer la caisse</button>
                </form>
            <?php endif; ?>

            <div class="rg-form rg-noprint" style="margin-top:14px;">
                <button type="button" class="rg-btn" onclick="window.print();">Imprimer</button>
                <?php if ($closure): ?>
                    <a class="rg-btn" href="caisse.php">Retour à la caisse en cours</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="rg-card">
    <h2>Opérations</h2>
    <p class="rg-help">Encaissements, annulations et remboursements, dans l'ordre où ils ont été saisis.</p>

    <div class="rg-table-wrap">
        <table class="rg-table">
            <thead>
                <tr><th>Heure</th><th>Opération</th><th>Archer</th><th>Mode</th><th class="right">Montant</th><th>Opérateur</th><th>Poste</th></tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($summary['operations']) as $operation): ?>
                    <tr>
                        <td class="num"><?php echo htmlspecialchars(date('d/m H:i', strtotime($operation['at']))); ?></td>
                        <td><?php echo htmlspecialchars(registrarc_journal_type_label($operation['type'])); ?><?php echo !empty($operation['label']) ? ' <span class="rg-muted">(' . htmlspecialchars($operation['label']) . ')</span>' : ''; ?></td>
                        <td><?php echo htmlspecialchars(isset($operation['archer']) ? $operation['archer'] : ''); ?></td>
                        <td><?php echo htmlspecialchars($modeLabel(isset($operation['method']) ? $operation['method'] : '')); ?></td>
                        <td class="right num <?php echo (float)$operation['amount'] < 0 ? 'rg-unpaid' : ''; ?>"><?php echo registrarc_format_montant($operation['amount']); ?> €</td>
                        <td><?php echo htmlspecialchars(isset($operation['operator']) ? $operation['operator'] : ''); ?></td>
                        <td class="rg-muted"><?php echo htmlspecialchars(isset($operation['device']) ? $operation['device'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($summary['operations'])): ?>
                    <tr><td colspan="7" class="rg-muted">Aucune opération sur cette période.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($closures)): ?>
    <div class="rg-card rg-noprint">
        <h2>Clôtures précédentes</h2>
        <div class="rg-table-wrap">
            <table class="rg-table">
                <thead>
                    <tr><th>Clôture</th><th>Période</th><th class="right">Opérations</th><th class="right">Total</th><th class="right">Espèces attendues</th><th class="right">Comptées</th><th class="right">Écart</th><th>Opérateur</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($closures) as $item): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($formatDate((string)$item['from']) . ' → ' . $formatDate((string)$item['to'])); ?></td>
                            <td class="right num"><?php echo intval($item['count']); ?></td>
                            <td class="right num"><?php echo registrarc_format_montant($item['total']); ?> €</td>
                            <td class="right num"><?php echo registrarc_format_montant($item['cash_expected']); ?> €</td>
                            <td class="right num"><?php echo $item['cash_counted'] !== null ? registrarc_format_montant($item['cash_counted']) . ' €' : '—'; ?></td>
                            <td class="right num <?php echo $item['cash_difference'] !== null && (float)$item['cash_difference'] != 0 ? 'rg-unpaid' : ''; ?>"><?php echo $item['cash_difference'] !== null ? registrarc_format_montant($item['cash_difference']) . ' €' : '—'; ?></td>
                            <td><?php echo htmlspecialchars(isset($item['operator']) ? $item['operator'] : ''); ?></td>
                            <td><a href="caisse.php?cloture=<?php echo rawurlencode($item['id']); ?>">Voir</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
registrarc_page_close();
include('Common/Templates/tail.php');
