<?php
// ============================================================================
// RegistrArc - helloasso.php
// Import des paiements faits en ligne (export tableur HelloAsso)
// 1. envoi du fichier  2. choix des colonnes  3. vérification puis enregistrement
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc_helloasso.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadWrite);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

if (!$TourId) {
    die('Tournoi non défini');
}

$paymentModes = registrarc_load_payment_modes($TourId);
$tournament = registrarc_get_tournament_info($TourId);
$sessionKey = 'RegistrArc_helloasso_' . $TourId;
$step = 'upload';
$error = '';
$matches = [];

$roles = [
    'licence' => 'Numéro de licence',
    'nom' => 'Nom',
    'prenom' => 'Prénom',
    'montant' => 'Montant payé',
    'statut' => 'Statut du paiement',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        $error = $formError;
    } elseif (isset($_POST['upload'])) {
        if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            $error = 'Aucun fichier sélectionné.';
        } else {
            $parsed = registrarc_helloasso_parse(file_get_contents($_FILES['csv_file']['tmp_name']));

            if (isset($parsed['error'])) {
                $error = $parsed['error'];
            } else {
                $_SESSION[$sessionKey] = $parsed + ['filename' => (string)$_FILES['csv_file']['name']];
                $step = 'columns';
            }
        }
    } elseif (isset($_POST['preview']) && !empty($_SESSION[$sessionKey])) {
        $columns = [];

        foreach (array_keys($roles) as $role) {
            $columns[$role] = isset($_POST['col_' . $role]) ? intval($_POST['col_' . $role]) : -1;
        }

        if ($columns['licence'] < 0 && ($columns['nom'] < 0 || $columns['prenom'] < 0)) {
            $error = 'Choisissez la colonne du numéro de licence, ou à défaut celles du nom et du prénom.';
            $step = 'columns';
        } else {
            $_SESSION[$sessionKey]['columns'] = $columns;
            $_SESSION[$sessionKey]['method'] = registrarc_clean_payment_method(isset($_POST['method']) ? $_POST['method'] : '', $paymentModes);
            $step = 'preview';
        }
    } elseif (isset($_POST['apply']) && !empty($_SESSION[$sessionKey]['columns'])) {
        // Le rapprochement est refait côté serveur : seules les lignes cochées sont retenues
        $matches = registrarc_helloasso_match($TourId, $_SESSION[$sessionKey]['rows'], $_SESSION[$sessionKey]['columns']);
        $checked = isset($_POST['lines']) && is_array($_POST['lines']) ? array_map('intval', $_POST['lines']) : [];
        $ids = [];

        foreach ($matches as $match) {
            if (in_array($match['line'], $checked, true) && in_array($match['status'], ['ok', 'amount'], true)) {
                $ids = array_merge($ids, $match['ids']);
            }
        }

        $result = registrarc_record_payments($TourId, $ids, $_SESSION[$sessionKey]['method'], false, 'helloasso');
        unset($_SESSION[$sessionKey]);

        registrarc_flash('helloasso', $result['ok']
            ? count($result['done']) . ' engagement(s) marqué(s) payé(s).' . registrarc_skipped_message($TourId, $result['skipped'])
            : "Enregistrement impossible." . registrarc_storage_error_suffix(), $result['ok'] ? 'success' : 'error');

        header('Location: helloasso.php');
        exit();
    } elseif (isset($_POST['restart'])) {
        unset($_SESSION[$sessionKey]);
    }
}

$import = isset($_SESSION[$sessionKey]) ? $_SESSION[$sessionKey] : null;

if ($step === 'preview') {
    $matches = registrarc_helloasso_match($TourId, $import['rows'], $import['columns']);
}

$guess = $import ? (isset($import['columns']) ? $import['columns'] : registrarc_helloasso_guess_columns($import['headers'])) : [];
$defaultMethod = isset($paymentModes['VIR']) ? 'VIR' : (string)key($paymentModes);
$statusLabels = ['ok' => 'À encaisser', 'amount' => 'Montant insuffisant', 'paid' => 'Déjà réglé', 'unknown' => 'Introuvable', 'ignored' => 'Ignoré'];
$flash = registrarc_flash('helloasso');

$PAGE_TITLE = 'RegistrArc - Import HelloAsso';
include('Common/Templates/head.php');
registrarc_page_open('Paiements HelloAsso', $tournament['name'], 'dashboard');
registrarc_render_flash($flash);

if ($error !== '') {
    registrarc_render_flash(['message' => $error, 'type' => 'error']);
}
?>

<?php if ($step === 'upload'): ?>
    <div class="rg-card">
        <h2>1. Le fichier HelloAsso</h2>
        <p class="rg-help">
            Dans HelloAsso, ouvrez la campagne d'inscription, puis exportez la liste des participants ou des commandes au format tableur (CSV).
            Le numéro de licence doit faire partie des informations demandées ; à défaut, les archers sont retrouvés par leur nom et prénom.
        </p>

        <form method="POST" enctype="multipart/form-data" class="rg-form">
            <?php echo registrarc_form_fields($TourId); ?>
            <div class="rg-field">
                <label for="csv_file">Fichier exporté</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv">
            </div>
            <button type="submit" name="upload" value="1" class="rg-btn rg-btn-primary">Continuer</button>
        </form>
    </div>
<?php elseif ($step === 'columns'): ?>
    <div class="rg-card">
        <h2>2. Les colonnes</h2>
        <p class="rg-help">
            <?php echo htmlspecialchars($import['filename']); ?> : <?php echo count($import['rows']); ?> ligne(s).
            Vérifiez quelle colonne contient chaque information (elles ont été devinées d'après leurs titres).
        </p>

        <form method="POST" class="rg-form">
            <?php echo registrarc_form_fields($TourId); ?>

            <?php foreach ($roles as $role => $label): ?>
                <div class="rg-field">
                    <label for="col_<?php echo $role; ?>"><?php echo htmlspecialchars($label); ?></label>
                    <select name="col_<?php echo $role; ?>" id="col_<?php echo $role; ?>">
                        <option value="-1">— aucune —</option>
                        <?php foreach ($import['headers'] as $index => $header): ?>
                            <option value="<?php echo $index; ?>" <?php echo isset($guess[$role]) && intval($guess[$role]) === $index ? 'selected' : ''; ?>><?php echo htmlspecialchars($header); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>

            <div class="rg-field">
                <label for="method">Enregistrer comme</label>
                <select name="method" id="method">
                    <?php foreach ($paymentModes as $code => $label): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === $defaultMethod ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" name="preview" value="1" class="rg-btn rg-btn-primary">Voir les correspondances</button>
            <button type="submit" name="restart" value="1" class="rg-btn">Changer de fichier</button>
        </form>
    </div>
<?php else: ?>
    <?php
    $toRecord = array_filter($matches, function ($m) {
        return $m['status'] === 'ok';
    });
    ?>
    <div class="rg-card">
        <h2>3. Vérification</h2>
        <p class="rg-help">
            <?php echo count($toRecord); ?> ligne(s) prête(s) à enregistrer en « <?php echo htmlspecialchars(isset($paymentModes[$import['method']]) ? $paymentModes[$import['method']] : $import['method']); ?> ».
            Les lignes cochées seront marquées payées ; décochez celles à écarter.
        </p>

        <form method="POST">
            <?php echo registrarc_form_fields($TourId); ?>
            <div class="rg-table-wrap">
                <table class="rg-table">
                    <thead><tr><th></th><th>Ligne</th><th>Archer</th><th class="right">Payé en ligne</th><th class="right">Dû</th><th>Résultat</th></tr></thead>
                    <tbody>
                        <?php foreach ($matches as $match): ?>
                            <?php $selectable = in_array($match['status'], ['ok', 'amount'], true); ?>
                            <tr>
                                <td><?php if ($selectable): ?><input type="checkbox" name="lines[]" value="<?php echo intval($match['line']); ?>" <?php echo $match['status'] === 'ok' ? 'checked' : ''; ?>><?php endif; ?></td>
                                <td class="num"><?php echo intval($match['line']); ?></td>
                                <td><?php echo htmlspecialchars($match['label']); ?></td>
                                <td class="right num"><?php echo $match['amount'] !== null ? registrarc_format_montant($match['amount']) . ' €' : '—'; ?></td>
                                <td class="right num"><?php echo $match['due'] > 0 ? registrarc_format_montant($match['due']) . ' €' : '—'; ?></td>
                                <td class="<?php echo $match['status'] === 'ok' ? 'rg-paid' : ($match['status'] === 'amount' || $match['status'] === 'unknown' ? 'rg-unpaid' : 'rg-muted'); ?>">
                                    <strong><?php echo htmlspecialchars($statusLabels[$match['status']]); ?></strong> · <?php echo htmlspecialchars($match['message']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="rg-form" style="margin-top:14px;">
                <button type="submit" name="apply" value="1" class="rg-btn rg-btn-success" onclick="return confirm('Marquer payés les engagements des lignes cochées ?');">Enregistrer les paiements cochés</button>
                <button type="submit" name="restart" value="1" class="rg-btn">Recommencer</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php
registrarc_page_close();
include('Common/Templates/tail.php');
