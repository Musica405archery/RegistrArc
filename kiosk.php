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

foreach ($rows as $r) {
    if ($r['payment_status'] === 'PAYE') {
        $paidRows++;
    } else {
        $unpaidRows++;
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
            --primary: #2563eb;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
            --muted: #6b7280;
            --strong: #111827;
            --bg: #f9fafb;
            --card: #ffffff;
            --border: #e5e7eb;
            --shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            --safe-bottom: env(safe-area-inset-bottom, 0px);
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: var(--bg);
        }

        body {
            min-height: 100%;
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--strong);
            font-family: Aptos, "Aptos Display", "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        .app-shell {
            max-width: 760px;
            margin: 0 auto;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            background: var(--bg);
        }

        .app-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(249, 250, 251, 0.97);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 10px 12px 9px;
        }

        .app-title-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .app-title {
            font-size: 22px;
            font-weight: 900;
            line-height: 1.1;
        }

        .app-subtitle {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .app-header-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            flex-shrink: 0;
        }

        .app-content {
            padding: 10px 12px;
            padding-bottom: calc(150px + var(--safe-bottom));
        }

        .btn {
            border: none;
            border-radius: 999px;
            padding: 9px 13px;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            line-height: 1.2;
            min-height: 38px;
        }

        .btn-small {
            min-height: 32px;
            padding: 7px 10px;
            font-size: 12px;
        }

        .btn-success {
            background: var(--success);
            color: #fff;
        }

        .btn-ghost {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        .alert {
            padding: 10px 12px;
            border-radius: 12px;
            font-size: 13px;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .alert-success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-info {
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            color: #075985;
        }

        .alert-warning {
            background: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        .status-details {
            margin-top: 8px;
            border: 1px solid var(--border);
            border-radius: 13px;
            background: #fff;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .status-details summary {
            list-style: none;
            cursor: pointer;
            padding: 8px 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 12px;
            font-weight: 900;
            color: #374151;
            user-select: none;
        }

        .status-details summary::-webkit-details-marker {
            display: none;
        }

        .status-summary-left {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }

        .status-arrow {
            display: inline-flex;
            width: 18px;
            height: 18px;
            border-radius: 999px;
            align-items: center;
            justify-content: center;
            background: #eef2ff;
            color: #3730a3;
            font-size: 11px;
            transition: transform .16s ease;
            flex-shrink: 0;
        }

        .status-details[open] .status-arrow {
            transform: rotate(90deg);
        }

        .status-summary-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-summary-counts {
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            white-space: nowrap;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
            padding: 0 8px 8px;
        }

        .stat {
            background: #f9fafb;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 7px 8px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .stat-value {
            margin-top: 3px;
            font-size: 18px;
            font-weight: 950;
            line-height: 1;
        }

        .success-text {
            color: var(--success);
        }

        .danger-text {
            color: var(--danger);
        }

        .warning-text {
            color: var(--warning);
        }

        .search-panel {
            margin-top: 9px;
        }

        .search-input-wrap {
            position: relative;
        }

        .search-input-wrap input[type="text"] {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 42px 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            background: #fff;
            font-size: 14px;
            font-weight: 800;
            outline: none;
        }

        .search-clear {
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            border-radius: 999px;
            background: #e5e7eb;
            color: #374151;
            font-size: 14px;
            font-weight: 900;
            width: 30px;
            height: 30px;
            cursor: pointer;
        }

        .results-count {
            margin-top: 6px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .card {
            background: var(--card);
            border-radius: 13px;
            box-shadow: var(--shadow);
            padding: 9px 10px;
            margin-bottom: 8px;
            border: 1px solid var(--border);
        }

        .filter-panel {
            display: grid;
            gap: 8px;
            margin-top: 9px;
        }

        .filter-title {
            font-size: 11px;
            font-weight: 900;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .session-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .session-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 9px;
            border-radius: 999px;
            background: #fff;
            border: 1px solid #d1d5db;
            font-size: 12px;
            font-weight: 900;
            color: #374151;
            user-select: none;
        }

        .session-pill input {
            width: 15px;
            height: 15px;
            margin: 0;
            accent-color: var(--primary);
        }

        .show-paid-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 900;
            color: #374151;
            user-select: none;
        }

        .show-paid-row input {
            width: 18px;
            height: 18px;
            margin: 0;
            accent-color: var(--primary);
        }

        .row-card {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 8px;
            align-items: start;
            cursor: pointer;
        }

        .row-card.is-paid {
            opacity: .78;
        }

        .row-card.is-selected {
            opacity: 1;
            border-color: var(--primary);
            background: #eff6ff;
        }

        .row-check {
            display: flex;
            align-items: center;
            padding-top: 3px;
        }

        .row-check input {
            width: 23px;
            height: 23px;
            accent-color: var(--primary);
        }

        .row-main {
            min-width: 0;
            display: grid;
            gap: 6px;
        }

        .row-line-archer {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: center;
        }

        .archer-identity {
            min-width: 0;
            display: flex;
            align-items: baseline;
            gap: 6px;
            flex-wrap: wrap;
            line-height: 1.1;
        }

        .archer-name {
            font-size: 15px;
            font-weight: 950;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .archer-club {
            font-size: 12px;
            font-style: italic;
            font-weight: 700;
            color: var(--muted);
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-mini {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 22px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 950;
            white-space: nowrap;
        }

        .status-mini-paid {
            background: #dcfce7;
            color: #166534;
        }

        .status-mini-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }

        .row-tile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            min-width: 0;
        }

        .mini-tile {
            min-width: 0;
            min-height: 42px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 6px 8px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #f9fafb;
        }

        .mini-tile-label {
            color: var(--muted);
            font-size: 9px;
            font-weight: 950;
            text-transform: uppercase;
            letter-spacing: .04em;
            line-height: 1;
        }

        .mini-tile-value {
            margin-top: 4px;
            color: #111827;
            font-size: 13px;
            font-weight: 950;
            line-height: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .mini-tile-target {
            font-size: 20px;
            letter-spacing: -.04em;
        }

        .mini-tile-category {
            font-size: 15px;
            letter-spacing: -.03em;
        }

        .mini-tile-price {
            background: #111827;
            border-color: #111827;
        }

        .mini-tile-price .mini-tile-label {
            color: #cbd5e1;
        }

        .mini-tile-amount {
            color: #fff;
            font-size: 18px;
            text-align: right;
            letter-spacing: -.04em;
        }

        .mini-tile-danger {
            color: #991b1b;
            font-size: 12px;
            line-height: 1.05;
            white-space: normal;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 1px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 7px;
            border-radius: 999px;
            font-size: 10.5px;
            font-weight: 900;
            white-space: nowrap;
        }

        .badge-method {
            background: #e0f2fe;
            color: #075985;
        }

        .badge-receipt {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-target-warning {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-tarif {
            background: #ede9fe;
            color: #5b21b6;
        }

        .badge-free {
            background: #dcfce7;
            color: #166534;
        }

        .bottom-bulk-bar {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 60;
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(10px);
            border-top: 1px solid var(--border);
            padding: 9px 12px calc(9px + var(--safe-bottom));
        }

        .bottom-bulk-inner {
            max-width: 760px;
            margin: 0 auto;
            display: grid;
            gap: 7px;
        }

        .bulk-summary {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 900;
            color: #374151;
        }

        .bulk-total {
            color: var(--strong);
            font-size: 13px;
            white-space: nowrap;
        }

        .bulk-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 8px;
            align-items: center;
        }

        select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            background: #fff;
            font-size: 14px;
            font-weight: 800;
        }

        .bulk-receipt {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            border: 1px solid #fde68a;
            background: #fef3c7;
            color: #92400e;
            border-radius: 999px;
            padding: 7px 9px;
            font-size: 11px;
            font-weight: 900;
            white-space: nowrap;
            margin: 0;
            user-select: none;
        }

        .bulk-receipt input {
            width: 16px;
            height: 16px;
            margin: 0;
            accent-color: var(--warning);
        }

        .bulk-buttons {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }

        .btn-validate-wide {
            width: 100%;
            min-height: 48px;
            font-size: 15px;
        }

        .empty {
            text-align: center;
            color: var(--muted);
            font-weight: 800;
            padding: 24px;
        }

        @media (max-width: 520px) {
            .app-header {
                padding-left: 8px;
                padding-right: 8px;
            }

            .app-content {
                padding-left: 8px;
                padding-right: 8px;
                padding-bottom: calc(154px + var(--safe-bottom));
            }

            .app-title {
                font-size: 21px;
            }

            .status-details summary {
                padding: 7px 8px;
            }

            .status-summary-counts {
                display: none;
            }

            .card {
                padding: 8px;
                margin-bottom: 7px;
            }

            .row-card {
                gap: 7px;
            }

            .row-check {
                padding-top: 1px;
            }

            .row-check input {
                width: 22px;
                height: 22px;
            }

            .row-main {
                gap: 5px;
            }

            .archer-identity {
                display: grid;
                gap: 2px;
            }

            .archer-name,
            .archer-club {
                max-width: 100%;
            }

            .archer-club {
                font-size: 11px;
            }

            .mini-tile {
                min-height: 40px;
                padding: 6px 7px;
                border-radius: 11px;
            }

            .mini-tile-target {
                font-size: 19px;
            }

            .mini-tile-category {
                font-size: 14px;
            }

            .mini-tile-amount {
                font-size: 17px;
            }

            .badges {
                display: none;
            }

            .bulk-summary {
                grid-template-columns: minmax(0, 1fr) auto auto;
                gap: 6px;
            }

            .bulk-total {
                font-size: 12px;
            }

            .bulk-receipt span {
                display: none;
            }

            .bulk-receipt {
                width: 38px;
                height: 34px;
                padding: 0;
            }
        }

        @media (min-width: 768px) {
            .app-shell,
            .bottom-bulk-inner {
                max-width: 920px;
            }

            .app-content {
                padding-bottom: calc(150px + var(--safe-bottom));
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <header class="app-header">
            <div class="app-title-line">
                <div>
                    <div class="app-title">RegistrArc</div>
                    <div class="app-subtitle">
                        <?php echo htmlspecialchars($tournamentName ?: 'Mode guichet mobile'); ?>
                    </div>
                </div>

                <div class="app-header-actions">
                    <a href="index.php?desktop=1" class="btn btn-ghost btn-small">Version complète</a>
                </div>
            </div>

            <details class="status-details">
                <summary>
                    <span class="status-summary-left">
                        <span class="status-arrow">›</span>
                        <span class="status-summary-text">Reçu / En attente / Payé</span>
                    </span>

                    <span class="status-summary-counts">
                        <?php echo intval($receiptRows); ?> reçu ·
                        <?php echo intval($unpaidRows); ?> attente ·
                        <?php echo intval($paidRows); ?> payé
                    </span>
                </summary>

                <div class="stats">
                    <div class="stat">
                        <div class="stat-label">Reçu</div>
                        <div class="stat-value warning-text"><?php echo intval($receiptRows); ?></div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">En attente</div>
                        <div class="stat-value danger-text"><?php echo intval($unpaidRows); ?></div>
                    </div>

                    <div class="stat">
                        <div class="stat-label">Payé</div>
                        <div class="stat-value success-text"><?php echo intval($paidRows); ?></div>
                    </div>
                </div>
            </details>

            <div class="search-panel">
                <div class="search-input-wrap">
                    <input type="text" id="kioskSearch" placeholder="Rechercher nom, licence, club..." oninput="filterCards();" autocomplete="off">
                    <button type="button" class="search-clear" onclick="clearSearch();" aria-label="Effacer la recherche">×</button>
                </div>

                <div class="results-count">
                    <span id="visibleCount"><?php echo count($rows); ?></span> engagement(s) affiché(s)
                </div>
            </div>

            <div class="filter-panel">
                <div>
                    <div class="filter-title">Départs</div>

                    <?php if (empty($sessions)): ?>
                        <div class="session-list">
                            <label class="session-pill">
                                <input type="checkbox" checked disabled>
                                Aucun départ
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="session-list">
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
                </div>

                <label class="show-paid-row">
                    <input type="checkbox" id="showPaidCheckbox" autocomplete="off" onchange="filterCards();">
                    Afficher aussi les archers déjà payés
                </label>
            </div>
        </header>

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

            <?php if (empty($rows)): ?>
                <div class="card empty">
                    Aucun engagement trouvé.
                </div>
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
                ?>
                <div
                    class="card row-card kiosk-card <?php echo $isPaid ? 'is-paid' : ''; ?>"
                    data-search="<?php echo htmlspecialchars($searchText); ?>"
                    data-session="<?php echo htmlspecialchars($row['session']); ?>"
                    data-paid="<?php echo $isPaid ? '1' : '0'; ?>"
                    data-amount="<?php echo htmlspecialchars($amountData); ?>"
                    onclick="toggleCardSelection(event, this);"
                >
                    <div class="row-check">
                        <input
                            type="checkbox"
                            name="engagements[]"
                            value="<?php echo intval($row['engagement_id']); ?>"
                            form="bulkForm"
                            class="bulk-checkbox"
                            autocomplete="off"
                            onchange="updateBulkSummary();"
                        >
                    </div>

                    <div class="row-main">
                        <div class="row-line-archer">
                            <div class="archer-identity">
                                <span class="archer-name">
                                    <?php echo htmlspecialchars(trim($row['nom'] . ' ' . $row['prenom'])); ?>
                                </span>

                                <?php if ($row['club'] !== ''): ?>
                                    <span class="archer-club">
                                        <?php echo htmlspecialchars($row['club']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="status-mini <?php echo $isPaid ? 'status-mini-paid' : 'status-mini-unpaid'; ?>">
                                <?php echo $isPaid ? 'Payé' : 'À payer'; ?>
                            </div>
                        </div>

                        <div class="row-tile-grid">
                            <div class="mini-tile">
                                <div class="mini-tile-label">Départ</div>
                                <div class="mini-tile-value">
                                    <?php echo $row['session'] !== '' ? htmlspecialchars('Départ ' . $row['session']) : '—'; ?>
                                </div>
                            </div>

                            <div class="mini-tile">
                                <div class="mini-tile-label">Cible</div>
                                <div class="mini-tile-value mini-tile-target <?php echo !empty($row['target_is_unassigned']) ? 'mini-tile-danger' : ''; ?>">
                                    <?php echo htmlspecialchars($row['target_label']); ?>
                                </div>
                            </div>
                        </div>

                        <div class="row-tile-grid">
                            <div class="mini-tile">
                                <div class="mini-tile-label">Catégorie</div>
                                <div class="mini-tile-value mini-tile-category">
                                    <?php echo htmlspecialchars($row['categorie']); ?>
                                </div>
                            </div>

                            <div class="mini-tile mini-tile-price">
                                <div class="mini-tile-label">Prix</div>
                                <div class="mini-tile-value mini-tile-amount">
                                    <?php echo registrarc_format_montant($row['amount_due']); ?> €
                                </div>
                            </div>
                        </div>

                        <div class="badges">
                            <?php if ($row['payment_method'] !== ''): ?>
                                <span class="badge badge-method">
                                    <?php echo htmlspecialchars($row['payment_method_label']); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($row['receipt_required'])): ?>
                                <span class="badge badge-receipt">Reçu demandé</span>
                            <?php endif; ?>

                            <?php if (!empty($row['target_is_unassigned'])): ?>
                                <span class="badge badge-target-warning">Sans affectation</span>
                            <?php endif; ?>

                            <?php if (!empty($row['has_special_tarif'])): ?>
                                <span class="badge badge-tarif">
                                    Tarif spécial : <?php echo htmlspecialchars($row['tarif_label']); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($row['is_free'])): ?>
                                <span class="badge badge-free">Gratuit</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </main>
    </div>

    <form method="POST" id="bulkForm" class="bottom-bulk-bar">
        <?php echo registrarc_form_fields($TourId); ?>
        <input type="hidden" name="create_cheque_group_after_payment" id="create_cheque_group_after_payment" value="0">

        <div class="bottom-bulk-inner">
            <div class="bulk-summary">
                <span><span id="bulkCount">0</span> sélectionné(s)</span>
                <span class="bulk-total">Total dû : <span id="bulkTotal">0 €</span></span>

                <label class="bulk-receipt" title="Reçu demandé">
                    <input type="checkbox" name="bulk_receipt_required" id="bulkReceiptRequired" value="1" autocomplete="off">
                    <span>Reçu</span>
                </label>
            </div>

            <div class="bulk-controls">
                <select name="bulk_payment_method" id="bulk_payment_method" autocomplete="off">
                    <?php foreach ($paymentModes as $code => $label): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>">
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bulk-buttons">
                <?php if ($canWrite): ?>
                    <button type="submit" name="bulk_action" value="validate" class="btn btn-success btn-validate-wide" onclick="return confirmBulk();">
                        Valider
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-validate-wide" disabled title="Droits en lecture seule">
                        Lecture seule
                    </button>
                <?php endif; ?>
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

            updateBulkSummary();
            saveKioskFiltersState();
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
            updateBulkSummary();
            filterCards();
        });

        window.addEventListener('pageshow', function() {
            loadKioskFiltersState();
            updateBulkSummary();
            filterCards();
        });
    </script>
</body>
</html>