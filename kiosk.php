<?php
// ============================================================================
// RegistrArc - kiosk.php
// Interface mobile simplifiée pour validation des paiements
//
// Objectif :
// - page autonome sans menu Ianseo
// - rendu type application mobile
// - recherche et filtres dans l'entête sticky
// - validation groupée avec notion de reçu sauvegardée dans le JSON
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

// ---------------------------------------------------------------------------
// Infos tournoi
// ---------------------------------------------------------------------------
$tournamentQuery = "
    SELECT ToCommitee, ToComDescr, ToName
    FROM Tournament
    WHERE ToId = $TourId
";
$tournamentRs = safe_r_sql($tournamentQuery);

$organizerClubCode = null;
$organizerClubName = null;
$tournamentName = null;

if ($t = safe_fetch($tournamentRs)) {
    $organizerClubCode = $t->ToCommitee;
    $organizerClubName = $t->ToComDescr;
    $tournamentName = $t->ToName;

    if (empty($organizerClubName) && $organizerClubCode) {
        $organizerNameQuery = "
            SELECT CoName
            FROM Countries
            WHERE CoCode = " . StrSafe_DB($organizerClubCode) . "
              AND CoTournament = $TourId
        ";
        $organizerNameRs = safe_r_sql($organizerNameQuery);

        if ($on = safe_fetch($organizerNameRs)) {
            $organizerClubName = $on->CoName;
        }
    }
}

// ---------------------------------------------------------------------------
// Chargement données globales
// ---------------------------------------------------------------------------
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifs = registrarc_load_tarifs($TourId, $organizerClubCode, $organizerClubName);
$ageClasses = registrarc_load_age_classes($TourId);
$tarifRules = registrarc_load_tarif_rules($TourId);
$dataDirStatus = registrarc_data_dir_status();
$canWrite = hasACL(AclParticipants, AclReadWrite);

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        $_SESSION['RegistrArc_kiosk_message'] = $formError;
        $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        header('Location: kiosk.php');
        exit();
    }

    if (!$dataDirStatus['ok']) {
        $_SESSION['RegistrArc_kiosk_message'] = "Action impossible : le dossier JSON n'est pas accessible en écriture.";
        $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        header('Location: kiosk.php');
        exit();
    }

    if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'validate' && !empty($_POST['engagements']) && is_array($_POST['engagements'])) {
        $method = isset($_POST['bulk_payment_method'])
            ? registrarc_clean_payment_method($_POST['bulk_payment_method'], $paymentModes)
            : 'ESP';

        $receiptRequired = !empty($_POST['bulk_receipt_required']);

        $createChequeGroupAfterPayment = !empty($_POST['create_cheque_group_after_payment'])
            && $_POST['create_cheque_group_after_payment'] === '1'
            && $method === 'CHQ';

        $ids = [];

        foreach ($_POST['engagements'] as $id) {
            $id = intval($id);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!empty($ids)) {
            $saved = registrarc_update_payments($TourId, function (array &$payments) use ($ids, $method, $receiptRequired) {
                foreach ($ids as $id) {
                    registrarc_set_payment_in_array($payments, $id, $method, $receiptRequired);
                }
            });

            if ($saved) {
                if ($createChequeGroupAfterPayment && count($ids) >= 2) {
                    $paymentsAfterSave = registrarc_load_payments($TourId);
                    $result = registrarc_create_cheque_group($TourId, $ids, $paymentsAfterSave);

                    if (!empty($result['ok'])) {
                        $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés et chèque groupé créé.';
                        $_SESSION['RegistrArc_kiosk_message_type'] = 'success';
                    } else {
                        $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés, mais le chèque groupé n’a pas pu être créé : ' . $result['message'];
                        $_SESSION['RegistrArc_kiosk_message_type'] = 'warning';
                    }
                } else {
                    $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés pour ' . count($ids) . ' engagement(s).';
                    $_SESSION['RegistrArc_kiosk_message_type'] = 'success';
                }
            } else {
                $_SESSION['RegistrArc_kiosk_message'] = "Erreur lors de l'écriture du JSON paiements." . registrarc_storage_error_suffix();
                $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
            }
        } else {
            $_SESSION['RegistrArc_kiosk_message'] = 'Aucun engagement sélectionné.';
            $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        }

        header('Location: kiosk.php');
        exit();
    }

    $_SESSION['RegistrArc_kiosk_message'] = 'Aucune action effectuée.';
    $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
    header('Location: kiosk.php');
    exit();
}

// ---------------------------------------------------------------------------
// Chargement engagements
// ---------------------------------------------------------------------------
$paymentsData = registrarc_load_payments($TourId);
$engagementNumbers = registrarc_get_engagement_numbers($TourId);

$query = "
    SELECT
        e.EnId AS engagement_id,
        UPPER(TRIM(e.EnCode)) AS licence,
        UPPER(TRIM(e.EnFirstName)) AS nom,
        TRIM(e.EnName) AS prenom,
        CONCAT(e.EnDivision, e.EnClass) AS categorie,
        c.CoName AS club,
        c.CoCode AS country_code,
        q.QuSession AS session_no,
        q.QuTarget AS target,
        q.QuLetter AS letter,
        e.EnIndClEvent,
        e.EnTeamClEvent,
        e.EnIndFEvent,
        e.EnTeamFEvent,
        e.EnTeamMixEvent
    FROM Entries e
    LEFT JOIN Countries c
        ON e.EnCountry = c.CoId
       AND e.EnTournament = c.CoTournament
    LEFT JOIN Qualifications q
        ON e.EnId = q.QuId
    WHERE e.EnTournament = $TourId
      AND e.EnAthlete = 1
      AND TRIM(e.EnCode) <> ''
    ORDER BY UPPER(TRIM(e.EnFirstName)), TRIM(e.EnName), e.EnId
";

$rs = safe_r_sql($query);
$rows = [];
$sessions = [];

if ($rs) {
    while ($row = safe_fetch($rs)) {
        $engagementId = intval($row->engagement_id);
        $numeroEngagement = isset($engagementNumbers[$engagementId]) ? intval($engagementNumbers[$engagementId]) : 1;

        $payment = isset($paymentsData[(string)$engagementId]) && is_array($paymentsData[(string)$engagementId])
            ? $paymentsData[(string)$engagementId]
            : [];

        $status = isset($payment['status']) && $payment['status'] === 'PAYE' ? 'PAYE' : 'NON_PAYE';
        $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';
        $receiptRequired = !empty($payment['receipt_required']);

        $session = trim((string)$row->session_no);
        $target = trim((string)$row->target);
        $letter = trim((string)$row->letter);
        $targetNo = trim($target . $letter);

        if ($session !== '') {
            $sessions[$session] = true;
        }

        $targetIsUnassigned = registrarc_is_null_empty_or_zero($target) || registrarc_is_null_empty_or_zero($letter);

        if ($targetIsUnassigned) {
            $targetLabel = 'Archer non affecté';
        } else {
            $targetLabel = $targetNo;
        }

        $entryForRules = [
            'categorie' => $row->categorie,
            'country_code' => $row->country_code,
            'EnIndClEvent' => $row->EnIndClEvent,
            'EnTeamClEvent' => $row->EnTeamClEvent,
            'EnIndFEvent' => $row->EnIndFEvent,
            'EnTeamFEvent' => $row->EnTeamFEvent,
            'EnTeamMixEvent' => $row->EnTeamMixEvent,
        ];

        $ruleResult = registrarc_apply_tarif_rules($entryForRules, $tarifRules);

        $hasSpecialTarif = false;

        if (!empty($ruleResult['matched'])) {
            $amountDue = (float)$ruleResult['amount'];
            $tarifLabel = $ruleResult['label'] !== '' ? $ruleResult['label'] : 'Spécial';
            $hasSpecialTarif = true;

            if (!empty($ruleResult['count']) && intval($ruleResult['count']) > 1) {
                $tarifLabel = 'Cumul : ' . $tarifLabel;
            }
        } else {
            $amountDue = registrarc_prix_engagement(
                $row->country_code,
                $row->categorie,
                $numeroEngagement,
                $tarifs,
                $organizerClubCode,
                $ageClasses
            );

            $tarifLabel = 'Standard';
        }

        $isFree = (floatval($amountDue) == 0 || $method === 'GRA');

        $rows[] = [
            'engagement_id' => $engagementId,
            'numero_engagement' => $numeroEngagement,
            'licence' => strtoupper(trim((string)$row->licence)),
            'nom' => strtoupper(trim((string)$row->nom)),
            'prenom' => trim((string)$row->prenom),
            'categorie' => trim((string)$row->categorie),
            'club' => trim((string)$row->club),
            'country_code' => trim((string)$row->country_code),
            'session' => $session,
            'target_label' => $targetLabel,
            'target_is_unassigned' => $targetIsUnassigned,
            'payment_status' => $status,
            'payment_method' => $method,
            'payment_method_label' => ($method !== '' && isset($paymentModes[$method])) ? $paymentModes[$method] : $method,
            'receipt_required' => $receiptRequired,
            'amount_due' => $amountDue,
            'tarif_label' => $tarifLabel,
            'has_special_tarif' => $hasSpecialTarif,
            'is_free' => $isFree,
        ];
    }
}

// Tri final demandé : nom, prénom, numéro d'engagement
usort($rows, function($a, $b) {
    $nomCompare = strcmp(mb_strtolower((string)$a['nom']), mb_strtolower((string)$b['nom']));

    if ($nomCompare !== 0) {
        return $nomCompare;
    }

    $prenomCompare = strcmp(mb_strtolower((string)$a['prenom']), mb_strtolower((string)$b['prenom']));

    if ($prenomCompare !== 0) {
        return $prenomCompare;
    }

    return intval($a['numero_engagement']) <=> intval($b['numero_engagement']);
});

$sessions = array_keys($sessions);
sort($sessions, SORT_NUMERIC);

$message = '';
$messageType = 'success';

if (isset($_SESSION['RegistrArc_kiosk_message'])) {
    $message = $_SESSION['RegistrArc_kiosk_message'];
    $messageType = isset($_SESSION['RegistrArc_kiosk_message_type']) ? $_SESSION['RegistrArc_kiosk_message_type'] : 'success';
    unset($_SESSION['RegistrArc_kiosk_message'], $_SESSION['RegistrArc_kiosk_message_type']);
}

$paidRows = 0;
$unpaidRows = 0;
$receiptRows = 0;
$unpaidAmount = 0;

foreach ($rows as $r) {
    if ($r['payment_status'] === 'PAYE') {
        $paidRows++;
    } else {
        $unpaidRows++;
        $unpaidAmount += (float)$r['amount_due'];
    }

    if (!empty($r['receipt_required'])) {
        $receiptRows++;
    }
}


?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>RegistrArc - Kiosk</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <style>
        :root {
            --text: #1f2933;
            --muted: #6b7280;
            --line: #e5e7eb;
            --line-strong: #d1d5db;
            --bg: #f3f4f6;
            --surface: #ffffff;
            --accent: #1d4ed8;
            --accent-soft: #eff4ff;
            --paid: #15803d;
            --unpaid: #b91c1c;
            --warn: #b45309;
            --radius: 8px;
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            min-height: 100%;
            background: var(--bg);
        }

        body {
            color: var(--text);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            font-size: 15px;
            line-height: 1.35;
            -webkit-font-smoothing: antialiased;
        }

        button,
        input,
        select {
            font: inherit;
            color: inherit;
        }

        .num {
            font-variant-numeric: tabular-nums;
        }

        .sr-only {
            position: absolute !important;
            width: 1px;
            height: 1px;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .app-shell {
            max-width: 860px;
            margin: 0 auto;
        }

        /* --- En-tête ---------------------------------------------------------- */
        .topbar {
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            padding: 12px 14px 10px;
        }

        .topbar-line {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
        }

        .title {
            font-size: 18px;
            font-weight: 700;
        }

        .subtitle {
            color: var(--muted);
            font-size: 13px;
            margin-top: 1px;
        }

        .topbar a {
            color: var(--accent);
            font-size: 14px;
            text-decoration: none;
            white-space: nowrap;
        }

        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 16px;
            margin-top: 8px;
            font-size: 13px;
            color: var(--muted);
        }

        .summary strong {
            color: var(--text);
            font-weight: 600;
        }

        .summary .is-unpaid strong {
            color: var(--unpaid);
        }

        .summary .is-paid strong {
            color: var(--paid);
        }

        /* --- Recherche et filtres --------------------------------------------- */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: var(--bg);
            padding: 10px 14px 8px;
            border-bottom: 1px solid var(--line);
        }

        .search-input-wrap {
            position: relative;
        }

        .search-input-wrap input[type="text"] {
            width: 100%;
            height: 42px;
            padding: 0 40px 0 12px;
            border: 1px solid var(--line-strong);
            border-radius: var(--radius);
            background: var(--surface);
            font-size: 16px;
            outline: none;
        }

        .search-input-wrap input[type="text"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.15);
        }

        .search-clear {
            position: absolute;
            right: 4px;
            top: 4px;
            width: 34px;
            height: 34px;
            border: none;
            background: none;
            color: var(--muted);
            font-size: 20px;
            cursor: pointer;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px 14px;
            margin-top: 8px;
        }

        .segmented {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 1px;
            background: var(--line-strong);
            border: 1px solid var(--line-strong);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .session-pill,
        .pay-mode {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 12px;
            background: var(--surface);
            border: none;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
        }

        .session-pill input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .session-pill.is-on,
        .pay-mode.is-active {
            background: var(--accent);
            color: #fff;
        }

        .check {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
        }

        .check input,
        .row-check {
            width: 20px;
            height: 20px;
            margin: 0;
            accent-color: var(--accent);
        }

        .results-count {
            margin-left: auto;
            color: var(--muted);
            font-size: 13px;
        }

        /* --- Liste ------------------------------------------------------------ */
        .app-content {
            padding: 12px 14px;
            padding-bottom: calc(210px + var(--safe-bottom));
        }

        .alert {
            padding: 10px 12px;
            border-radius: var(--radius);
            border: 1px solid;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
        .alert-error { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
        .alert-info { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
        .alert-warning { background: #fffbeb; border-color: #fde68a; color: #78350f; }

        .list {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .empty-state {
            padding: 28px 16px;
            text-align: center;
            color: var(--muted);
        }

        .kiosk-card {
            display: grid;
            grid-template-columns: auto 48px minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            border-top: 1px solid var(--line);
            cursor: pointer;
        }

        .list > .kiosk-card:first-child,
        #noResults[hidden] + .kiosk-card {
            border-top: none;
        }

        .kiosk-card.is-selected {
            background: var(--accent-soft);
            box-shadow: inset 3px 0 0 var(--accent);
        }

        .row-target {
            font-size: 17px;
            font-weight: 700;
            text-align: center;
            white-space: nowrap;
        }

        .row-target.is-unassigned {
            color: var(--muted);
            font-weight: 400;
        }

        .row-main {
            min-width: 0;
        }

        .row-name {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .row-name span {
            font-weight: 400;
        }

        .row-meta {
            margin-top: 2px;
            color: var(--muted);
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .row-flags {
            margin-top: 3px;
            font-size: 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 2px 10px;
        }

        .flag-warn { color: var(--unpaid); }
        .flag-receipt { color: var(--warn); }
        .flag-tarif { color: var(--accent); }

        .row-amount {
            text-align: right;
            white-space: nowrap;
        }

        .amount {
            font-size: 17px;
            font-weight: 600;
        }

        .state {
            margin-top: 2px;
            font-size: 12px;
        }

        .state-unpaid { color: var(--unpaid); }
        .state-paid { color: var(--paid); }

        .kiosk-card.is-paid .row-target,
        .kiosk-card.is-paid .row-name,
        .kiosk-card.is-paid .amount {
            color: var(--muted);
        }

        /* --- Barre d'encaissement --------------------------------------------- */
        .bottom-bulk-bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 60;
            background: var(--surface);
            border-top: 1px solid var(--line-strong);
            padding: 10px 14px calc(10px + var(--safe-bottom));
        }

        .bottom-bulk-inner {
            max-width: 832px;
            margin: 0 auto;
            display: grid;
            gap: 10px;
        }

        .bulk-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 12px;
            font-size: 14px;
            color: var(--muted);
        }

        .bulk-head strong {
            color: var(--text);
            font-weight: 600;
        }

        .bulk-total strong {
            font-size: 20px;
        }

        .bulk-details {
            display: none;
            gap: 10px;
        }

        .has-selection .bulk-details {
            display: grid;
        }

        .pay-modes {
            display: flex;
        }

        .pay-modes .pay-mode {
            flex: 1 1 0;
        }

        .bulk-actions {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .btn-validate-wide {
            flex: 1;
            height: 48px;
            border: none;
            border-radius: var(--radius);
            background: var(--paid);
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-validate-wide:disabled {
            background: var(--line-strong);
            color: var(--muted);
            cursor: default;
        }

        @media (max-width: 520px) {
            .kiosk-card {
                grid-template-columns: auto 42px minmax(0, 1fr) auto;
                gap: 10px;
                padding: 10px 12px;
            }

            .app-content {
                padding-left: 0;
                padding-right: 0;
            }

            .list {
                border-left: none;
                border-right: none;
                border-radius: 0;
            }

            .results-count {
                margin-left: 0;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <header class="topbar">
            <div class="topbar-line">
                <div>
                    <div class="title">Encaissement</div>
                    <div class="subtitle"><?php echo htmlspecialchars($tournamentName ?: 'RegistrArc'); ?></div>
                </div>

                <a href="index.php?desktop=1">Version complète</a>
            </div>

            <div class="summary num">
                <span class="is-unpaid"><strong><?php echo intval($unpaidRows); ?></strong> à encaisser</span>
                <span><strong><?php echo registrarc_format_montant($unpaidAmount); ?> €</strong> restant dû</span>
                <span class="is-paid"><strong><?php echo intval($paidRows); ?></strong> payé(s)</span>
                <span><strong><?php echo intval($receiptRows); ?></strong> reçu(s) demandé(s)</span>
            </div>
        </header>

        <div class="toolbar">
            <div class="search-input-wrap">
                <input type="text" id="kioskSearch" placeholder="Rechercher un nom, une licence, un club" oninput="filterCards();" autocomplete="off">
                <button type="button" class="search-clear" onclick="clearSearch();" aria-label="Effacer la recherche">×</button>
            </div>

            <div class="filters">
                <?php if (!empty($sessions)): ?>
                    <div class="segmented" role="group" aria-label="Départs">
                        <?php foreach ($sessions as $session): ?>
                            <label class="session-pill">
                                <input
                                    type="checkbox"
                                    class="session-filter"
                                    value="<?php echo htmlspecialchars($session); ?>"
                                    checked
                                    autocomplete="off"
                                    onchange="filterCards();"
                                >
                                Départ <?php echo htmlspecialchars($session); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <label class="check switch">
                    <input type="checkbox" id="showPaidCheckbox" autocomplete="off" onchange="filterCards();">
                    Afficher les payés
                </label>

                <span class="results-count num"><span id="visibleCount"><?php echo count($rows); ?></span> affiché(s)</span>
            </div>
        </div>

        <main class="app-content">
            <?php if (!$dataDirStatus['ok']): ?>
                <div class="alert alert-warning">
                    <strong>Attention :</strong>
                    <?php echo htmlspecialchars($dataDirStatus['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="list">
                <?php if (empty($rows)): ?>
                    <div class="empty-state">Aucun engagement dans ce tournoi.</div>
                <?php else: ?>
                    <div class="empty-state" id="noResults" hidden>Aucun archer ne correspond à la recherche ou aux filtres.</div>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <?php
                    $isPaid = ($row['payment_status'] === 'PAYE');
                    $amountData = number_format((float)$row['amount_due'], 2, '.', '');
                    $searchText = mb_strtolower(
                        $row['licence'] . ' ' .
                        $row['nom'] . ' ' .
                        $row['prenom'] . ' ' .
                        $row['club'] . ' ' .
                        $row['country_code'] . ' ' .
                        $row['categorie'] . ' ' .
                        $row['tarif_label']
                    );
                    // Départ et catégorie d'abord : le club, souvent long, est coupé en premier
                    $meta = array_filter([
                        $row['session'] !== '' ? 'Départ ' . $row['session'] : '',
                        $row['categorie'],
                        $row['club'],
                    ]);
                    ?>
                    <div
                        class="kiosk-card <?php echo $isPaid ? 'is-paid' : ''; ?>"
                        data-search="<?php echo htmlspecialchars($searchText); ?>"
                        data-session="<?php echo htmlspecialchars($row['session']); ?>"
                        data-paid="<?php echo $isPaid ? '1' : '0'; ?>"
                        data-amount="<?php echo htmlspecialchars($amountData); ?>"
                        onclick="toggleCardSelection(event, this);"
                    >
                        <input
                            type="checkbox"
                            name="engagements[]"
                            value="<?php echo intval($row['engagement_id']); ?>"
                            form="bulkForm"
                            class="bulk-checkbox row-check"
                            autocomplete="off"
                            aria-label="<?php echo htmlspecialchars('Sélectionner ' . trim($row['nom'] . ' ' . $row['prenom'])); ?>"
                            onchange="updateBulkSummary();"
                        >

                        <div class="row-target num <?php echo !empty($row['target_is_unassigned']) ? 'is-unassigned' : ''; ?>">
                            <?php echo !empty($row['target_is_unassigned']) ? '—' : htmlspecialchars($row['target_label']); ?>
                        </div>

                        <div class="row-main">
                            <div class="row-name">
                                <?php echo htmlspecialchars($row['nom']); ?>
                                <span><?php echo htmlspecialchars($row['prenom']); ?></span>
                            </div>

                            <div class="row-meta"><?php echo htmlspecialchars(implode(' · ', $meta)); ?></div>

                            <?php if (!empty($row['target_is_unassigned']) || !empty($row['receipt_required']) || !empty($row['has_special_tarif'])): ?>
                                <div class="row-flags">
                                    <?php if (!empty($row['target_is_unassigned'])): ?>
                                        <span class="flag-warn">Sans cible</span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['receipt_required'])): ?>
                                        <span class="flag-receipt">Reçu demandé</span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['has_special_tarif'])): ?>
                                        <span class="flag-tarif"><?php echo htmlspecialchars($row['tarif_label']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="row-amount">
                            <div class="amount num">
                                <?php echo !empty($row['is_free']) ? 'Gratuit' : registrarc_format_montant($row['amount_due']) . ' €'; ?>
                            </div>
                            <div class="state <?php echo $isPaid ? 'state-paid' : 'state-unpaid'; ?>">
                                <?php
                                if ($isPaid) {
                                    echo htmlspecialchars('Payé' . ($row['payment_method_label'] !== '' ? ' · ' . $row['payment_method_label'] : ''));
                                } else {
                                    echo 'À payer';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <form method="POST" id="bulkForm" class="bottom-bulk-bar">
        <?php echo registrarc_form_fields($TourId); ?>
        <input type="hidden" name="create_cheque_group_after_payment" id="create_cheque_group_after_payment" value="0">

        <select name="bulk_payment_method" id="bulk_payment_method" class="sr-only" tabindex="-1" aria-hidden="true" autocomplete="off">
            <?php foreach ($paymentModes as $code => $label): ?>
                <option value="<?php echo htmlspecialchars($code); ?>">
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="bottom-bulk-inner">
            <div class="bulk-head num">
                <span><strong id="bulkCount">0</strong> sélectionné(s)</span>
                <span class="bulk-total">Total <strong id="bulkTotal">0 €</strong></span>
            </div>

            <div class="bulk-details">
                <div class="segmented pay-modes" role="group" aria-label="Mode de paiement">
                    <?php foreach ($paymentModes as $code => $label): ?>
                        <button type="button" class="pay-mode" data-code="<?php echo htmlspecialchars($code); ?>" onclick="selectPaymentMode(this);">
                            <?php echo htmlspecialchars($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="bulk-actions">
                    <label class="check pay-receipt">
                        <input type="checkbox" name="bulk_receipt_required" id="bulkReceiptRequired" value="1" autocomplete="off">
                        Reçu demandé
                    </label>

                    <?php if ($canWrite): ?>
                        <button type="submit" name="bulk_action" value="validate" id="bulkSubmit" class="btn-validate-wide" onclick="return confirmBulk();">
                            Valider
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn-validate-wide" disabled title="Droits en lecture seule">
                            Lecture seule
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <script>
        const KIOSK_FILTERS_KEY = 'RegistrArcKioskFilters_<?php echo intval($TourId); ?>';

        let KIOSK_FILTERS_LOADING = false;

        function sessionCheckboxes() {
            return Array.from(document.querySelectorAll('.session-filter'));
        }

        function ensureAtLeastTwoSessionsSelected() {
            const boxes = sessionCheckboxes();

            if (boxes.length === 0) {
                return;
            }

            const checked = boxes.filter(cb => cb.checked);

            if (checked.length > 0) {
                return;
            }

            boxes.slice(0, Math.min(2, boxes.length)).forEach(cb => {
                cb.checked = true;
            });
        }

        function selectedSessions() {
            ensureAtLeastTwoSessionsSelected();

            const checked = Array.from(document.querySelectorAll('.session-filter:checked'));
            return checked.map(input => String(input.value || ''));
        }

        function saveKioskFiltersState() {
            if (KIOSK_FILTERS_LOADING) {
                return;
            }

            ensureAtLeastTwoSessionsSelected();

            const searchInput = document.getElementById('kioskSearch');
            const showPaidCheckbox = document.getElementById('showPaidCheckbox');

            const state = {
                selectedSessions: selectedSessions(),
                showPaid: showPaidCheckbox ? !!showPaidCheckbox.checked : false,
                search: searchInput ? String(searchInput.value || '') : ''
            };

            try {
                localStorage.setItem(KIOSK_FILTERS_KEY, JSON.stringify(state));
            } catch (e) {
                // localStorage indisponible : pas bloquant
            }
        }

        function loadKioskFiltersState() {
            KIOSK_FILTERS_LOADING = true;

            let state = null;

            try {
                const raw = localStorage.getItem(KIOSK_FILTERS_KEY);

                if (raw) {
                    state = JSON.parse(raw);
                }
            } catch (e) {
                state = null;
            }

            const boxes = sessionCheckboxes();
            const searchInput = document.getElementById('kioskSearch');
            const showPaidCheckbox = document.getElementById('showPaidCheckbox');

            if (state && Array.isArray(state.selectedSessions)) {
                boxes.forEach(cb => {
                    cb.checked = state.selectedSessions.indexOf(String(cb.value || '')) !== -1;
                });
            } else {
                boxes.forEach(cb => {
                    cb.checked = true;
                });
            }

            ensureAtLeastTwoSessionsSelected();

            if (showPaidCheckbox) {
                showPaidCheckbox.checked = state ? !!state.showPaid : false;
            }

            if (searchInput) {
                searchInput.value = state && typeof state.search === 'string' ? state.search : '';
            }

            document.querySelectorAll('.bulk-checkbox').forEach(cb => {
                cb.checked = false;
            });

            document.querySelectorAll('.kiosk-card').forEach(card => {
                card.classList.remove('is-selected');
            });

            const groupInput = document.getElementById('create_cheque_group_after_payment');

            if (groupInput) {
                groupInput.value = '0';
            }

            KIOSK_FILTERS_LOADING = false;
        }

        function formatEuro(value) {
            const amount = Number(value || 0);

            if (Number.isNaN(amount)) {
                return '0 €';
            }

            if (Math.floor(amount) === amount) {
                return String(amount) + ' €';
            }

            return amount.toLocaleString('fr-FR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }) + ' €';
        }

        function filterCards() {
            ensureAtLeastTwoSessionsSelected();

            const input = document.getElementById('kioskSearch');
            const query = String(input ? input.value : '').toLowerCase();
            const showPaid = document.getElementById('showPaidCheckbox')
                ? document.getElementById('showPaidCheckbox').checked
                : false;

            const selected = selectedSessions();
            const hasSessionFilter = selected.length > 0;
            const cards = document.querySelectorAll('.kiosk-card');
            let visible = 0;

            cards.forEach(card => {
                const haystack = String(card.dataset.search || '');
                const session = String(card.dataset.session || '');
                const isPaid = String(card.dataset.paid || '0') === '1';

                const matchesSearch = query === '' || haystack.indexOf(query) !== -1;
                const matchesSession = !hasSessionFilter || selected.indexOf(session) !== -1;
                const matchesPaid = showPaid || !isPaid;

                if (matchesSearch && matchesSession && matchesPaid) {
                    card.style.display = '';
                    visible++;
                } else {
                    card.style.display = 'none';

                    const checkbox = card.querySelector('.bulk-checkbox');

                    if (checkbox) {
                        checkbox.checked = false;
                    }

                    card.classList.remove('is-selected');
                }
            });

            const visibleCount = document.getElementById('visibleCount');

            if (visibleCount) {
                visibleCount.textContent = String(visible);
            }

            const noResults = document.getElementById('noResults');

            if (noResults) {
                noResults.hidden = visible > 0;
            }

            syncToggles();
            updateBulkSummary();
            saveKioskFiltersState();
        }

        // Aspect "activé" des pastilles de départ, de l'interrupteur Payés et du bouton Reçu
        function syncToggles() {
            document.querySelectorAll('.session-pill, .switch, .pay-receipt').forEach(label => {
                const input = label.querySelector('input');
                label.classList.toggle('is-on', !!(input && input.checked));
            });
        }

        // Les boutons de mode de paiement pilotent la liste cachée bulk_payment_method
        function selectPaymentMode(btn) {
            const select = document.getElementById('bulk_payment_method');

            if (select) {
                select.value = String(btn.dataset.code || '');
            }

            syncPaymentModes();
        }

        function syncPaymentModes() {
            const current = currentBulkPaymentMethod();

            document.querySelectorAll('.pay-mode').forEach(btn => {
                btn.classList.toggle('is-active', String(btn.dataset.code || '').toUpperCase() === current);
            });
        }

        function clearSearch() {
            const input = document.getElementById('kioskSearch');

            if (input) {
                input.value = '';
                input.focus();
            }

            filterCards();
        }

        function selectedBulkCheckboxes() {
            return Array.from(document.querySelectorAll('.bulk-checkbox:checked'));
        }

        function selectedBulkCount() {
            return selectedBulkCheckboxes().length;
        }

        function selectedBulkTotal() {
            let total = 0;

            selectedBulkCheckboxes().forEach(cb => {
                const card = cb.closest('.kiosk-card');

                if (!card) {
                    return;
                }

                const amount = Number(card.dataset.amount || 0);

                if (!Number.isNaN(amount)) {
                    total += amount;
                }
            });

            return total;
        }

        function updateBulkSummary() {
            const count = selectedBulkCount();
            const total = selectedBulkTotal();

            const countEl = document.getElementById('bulkCount');
            const totalEl = document.getElementById('bulkTotal');

            if (countEl) {
                countEl.textContent = String(count);
            }

            if (totalEl) {
                totalEl.textContent = formatEuro(total);
            }

            const form = document.getElementById('bulkForm');

            if (form) {
                form.classList.toggle('has-selection', count > 0);
            }

            const submit = document.getElementById('bulkSubmit');

            if (submit) {
                submit.disabled = count === 0;
                submit.textContent = count === 0
                    ? 'Valider'
                    : (total > 0 ? 'Encaisser ' + formatEuro(total) : 'Valider ' + count + ' engagement(s)');
            }

            document.querySelectorAll('.kiosk-card').forEach(card => {
                const cb = card.querySelector('.bulk-checkbox');

                if (cb && cb.checked) {
                    card.classList.add('is-selected');
                } else {
                    card.classList.remove('is-selected');
                }
            });
        }

        function currentBulkPaymentMethod() {
            const select = document.getElementById('bulk_payment_method');
            return select ? String(select.value || '').toUpperCase() : '';
        }

        function confirmBulk() {
            const count = selectedBulkCount();
            const total = selectedBulkTotal();
            const method = currentBulkPaymentMethod();
            const groupInput = document.getElementById('create_cheque_group_after_payment');

            if (groupInput) {
                groupInput.value = '0';
            }

            if (count === 0) {
                alert('Aucun engagement sélectionné.');
                return false;
            }

            if (method === 'CHQ' && count >= 2) {
                const createGroup = confirm(
                    'Valider le paiement par chèque pour ' + count + ' engagement(s) ?\n\n' +
                    'Total dû : ' + formatEuro(total) + '\n\n' +
                    'Créer un chèque groupé pour ces engagements ?'
                );

                if (groupInput) {
                    groupInput.value = createGroup ? '1' : '0';
                }

                return true;
            }

            return confirm(
                'Valider le paiement pour ' + count + ' engagement(s) ?\n\n' +
                'Total dû : ' + formatEuro(total)
            );
        }

        function toggleCardSelection(event, card) {
            if (event.target.closest('input, button, select, a, label, summary')) {
                return;
            }

            const checkbox = card.querySelector('.bulk-checkbox');

            if (!checkbox) {
                return;
            }

            checkbox.checked = !checkbox.checked;
            updateBulkSummary();
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadKioskFiltersState();
            syncPaymentModes();
            updateBulkSummary();
            filterCards();
        });

        window.addEventListener('pageshow', function() {
            loadKioskFiltersState();
            syncPaymentModes();
            updateBulkSummary();
            filterCards();
        });
    </script>
</body>
</html>