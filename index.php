<?php
// ============================================================================
// Greffe2 - index.php
// Paiements indépendants par engagement / départ
// Licence en UPPERCASE
// Nom de l'archer en UPPERCASE
// Numéro d'engagement en première colonne
// Position construite avec QuTarget + QuLetter
// Archer non affecté uniquement si QuTarget ou QuLetter est NULL, vide ou égal à 0
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;
if (!$TourId) {
    die('Tournoi non défini');
}

// ---------------------------------------------------------------------------
// 0. Réinitialisation des filtres
// ---------------------------------------------------------------------------
if (isset($_GET['reset'])) {
    unset($_SESSION['Greffe2_filters']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ---------------------------------------------------------------------------
// 1. Filtres et tri
// ---------------------------------------------------------------------------
$filters = [
    'club'     => isset($_GET['club_filter'])     ? $_GET['club_filter']     : (isset($_SESSION['Greffe2_filters']['club'])     ? $_SESSION['Greffe2_filters']['club']     : 'all'),
    'category' => isset($_GET['category_filter']) ? $_GET['category_filter'] : (isset($_SESSION['Greffe2_filters']['category']) ? $_SESSION['Greffe2_filters']['category'] : 'all'),
    'payment'  => isset($_GET['payment_filter'])  ? $_GET['payment_filter']  : (isset($_SESSION['Greffe2_filters']['payment'])  ? $_SESSION['Greffe2_filters']['payment']  : 'all'),
    'session'  => isset($_GET['session_filter'])  ? $_GET['session_filter']  : (isset($_SESSION['Greffe2_filters']['session'])  ? $_SESSION['Greffe2_filters']['session']  : 'all'),
    'search'   => isset($_GET['search'])          ? trim($_GET['search'])    : (isset($_SESSION['Greffe2_filters']['search'])   ? $_SESSION['Greffe2_filters']['search']   : ''),
];

$_SESSION['Greffe2_filters'] = $filters;

$sortColumn    = isset($_GET['sort']) ? $_GET['sort'] : 'nom';
$sortDirection = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'desc' : 'asc';

// ---------------------------------------------------------------------------
// 2. Infos tournoi + club organisateur
// ---------------------------------------------------------------------------
$tournamentQuery = "
    SELECT ToCommitee, ToComDescr, ToName
    FROM Tournament
    WHERE ToId = $TourId
";
$tournamentRs = safe_r_sql($tournamentQuery);

$organizerClubCode = null;
$organizerClubName = null;
$tournamentName    = null;

if ($t = safe_fetch($tournamentRs)) {
    $organizerClubCode = $t->ToCommitee;
    $organizerClubName = $t->ToComDescr;
    $tournamentName    = $t->ToName;

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
// 3. Tarifs et modes de paiement
// ---------------------------------------------------------------------------
function greffe2_load_tarifs($TourId, $organizerClubCode, $organizerClubName) {
    $paramName = 'Tarifs_' . intval($TourId);
    $json      = getModuleParameter('Greffe2', $paramName, '');

    if ($json) {
        $data = json_decode($json, true);

        if (is_array($data) && isset($data['clubs_autres'], $data['organizer'])) {
            $data['organizer_club_code'] = $organizerClubCode;
            $data['organizer_club_name'] = $organizerClubName;
            return $data;
        }
    }

    return [
        'clubs_autres' => [
            'jeunes'  => [1 => 8,  2 => 14],
            'adultes' => [1 => 10, 2 => 18],
        ],
        'organizer' => [
            'jeunes'  => [1 => 4, 2 => 8],
            'adultes' => [1 => 5, 2 => 10],
        ],
        'organizer_club_code' => $organizerClubCode,
        'organizer_club_name' => $organizerClubName,
    ];
}

function loadPaymentModesForTournament($TourId) {
    $paramName = 'PaymentModes_' . intval($TourId);
    $json      = getModuleParameter('Greffe2', $paramName, '');

    if ($json) {
        $data = json_decode($json, true);

        if (is_array($data) && !empty($data)) {
            return $data;
        }
    }

    return [
        'ESPECE'   => 'Espèce',
        'CHEQUE'   => 'Chèque',
        'VIREMENT' => 'Virement',
        'GRATUIT'  => 'Gratuit',
    ];
}

function getAgeCategoryFromCategorie($categorie_code) {
    $categorie_code = (string)$categorie_code;

    $jeunes  = ['U11', 'U13', 'U15', 'U18'];
    $adultes = ['U21', 'S1', 'S2', 'S3'];

    foreach ($jeunes as $c) {
        if (stripos($categorie_code, $c) !== false) {
            return 'jeunes';
        }
    }

    foreach ($adultes as $c) {
        if (stripos($categorie_code, $c) !== false) {
            return 'adultes';
        }
    }

    return 'adultes';
}

function calculerPrixEngagement($club_country_code, $categorie_code, $numero_engagement, $tarifs, $organizerClubCode) {
    $is_organizer = ($club_country_code == $organizerClubCode);
    $age          = getAgeCategoryFromCategorie($categorie_code);

    if ($is_organizer) {
        $p1 = isset($tarifs['organizer'][$age][1]) ? floatval($tarifs['organizer'][$age][1]) : 0;
        $p2 = isset($tarifs['organizer'][$age][2]) ? floatval($tarifs['organizer'][$age][2]) : $p1;
    } else {
        $p1 = isset($tarifs['clubs_autres'][$age][1]) ? floatval($tarifs['clubs_autres'][$age][1]) : 0;
        $p2 = isset($tarifs['clubs_autres'][$age][2]) ? floatval($tarifs['clubs_autres'][$age][2]) : $p1;
    }

    $supplement = $p2 - $p1;

    if ($numero_engagement <= 1) {
        return $p1;
    }

    return $supplement;
}

function formatMontant($montant) {
    $montant = floatval($montant);

    if (floor($montant) == $montant) {
        return intval($montant);
    }

    return number_format($montant, 2, ',', ' ');
}

function buildRedirectParams($filters, $sortColumn, $sortDirection) {
    $params = [];

    foreach ($filters as $key => $value) {
        if ($key === 'search') {
            if ($value !== '') {
                $params['search'] = $value;
            }
        } elseif ($value !== 'all') {
            if ($key === 'session') {
                $params['session_filter'] = $value;
            } else {
                $params[$key . '_filter'] = $value;
            }
        }
    }

    $params['sort'] = $sortColumn;
    $params['dir']  = $sortDirection;

    return http_build_query($params);
}

function cleanPaymentMethod($method, $paymentModes) {
    $method = strtoupper(trim((string)$method));

    if ($method === '') {
        return 'ESPECE';
    }

    if (!isset($paymentModes[$method])) {
        return 'ESPECE';
    }

    return $method;
}

function isNullEmptyOrZero($value) {
    if ($value === null) {
        return true;
    }

    $value = trim((string)$value);

    if ($value === '') {
        return true;
    }

    if (is_numeric($value) && floatval($value) == 0) {
        return true;
    }

    return false;
}

$tarifs       = greffe2_load_tarifs($TourId, $organizerClubCode, $organizerClubName);
$paymentModes = loadPaymentModesForTournament($TourId);

// ---------------------------------------------------------------------------
// 4. Actions POST : paiement individuel ou en masse par engagement
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectQuery = buildRedirectParams($filters, $sortColumn, $sortDirection);

    // Paiement en masse
    if (isset($_POST['bulk_action']) && !empty($_POST['engagements']) && is_array($_POST['engagements'])) {
        $action = $_POST['bulk_action'];
        $ids = [];

        foreach ($_POST['engagements'] as $id) {
            $id = intval($id);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        $bulkPaymentMethod = isset($_POST['bulk_payment_method'])
            ? cleanPaymentMethod($_POST['bulk_payment_method'], $paymentModes)
            : 'ESPECE';

        if (!empty($ids)) {
            if ($action === 'validate') {
                $paymentStatus  = 'PAYE|' . $bulkPaymentMethod;
                $successMessage = 'Paiement validé pour les engagements sélectionnés.';
                $messageType    = 'success';
            } else {
                $paymentStatus  = 'NON_PAYE';
                $successMessage = 'Engagements sélectionnés marqués comme non payés.';
                $messageType    = 'info';
            }

            $idsSql = implode(',', $ids);
            $paymentStatusSql = StrSafe_DB($paymentStatus);

            $updateQuery = "
                UPDATE Qualifications
                SET QuNotes = $paymentStatusSql
                WHERE QuId IN (
                    SELECT EnId
                    FROM Entries
                    WHERE EnTournament = $TourId
                      AND EnId IN ($idsSql)
                )
            ";

            $result = safe_w_sql($updateQuery);

            if ($result) {
                $_SESSION['Greffe2_payment_message'] = $successMessage;
                $_SESSION['Greffe2_message_type']    = $messageType;
            } else {
                $_SESSION['Greffe2_payment_message'] = 'Erreur lors de la mise à jour en lot.';
                $_SESSION['Greffe2_message_type']    = 'error';
            }
        } else {
            $_SESSION['Greffe2_payment_message'] = 'Aucun engagement sélectionné.';
            $_SESSION['Greffe2_message_type']    = 'error';
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

    // Paiement individuel
    if (isset($_POST['validate_payment']) && isset($_POST['engagement_id'])) {
        $engagementId = intval($_POST['engagement_id']);
        $action       = $_POST['validate_payment'];

        $paymentMethod = isset($_POST['payment_method'])
            ? cleanPaymentMethod($_POST['payment_method'], $paymentModes)
            : 'ESPECE';

        if ($engagementId > 0) {
            if ($action === 'validate') {
                $paymentStatus  = 'PAYE|' . $paymentMethod;
                $successMessage = 'Paiement de cet engagement validé.';
                $messageType    = 'success';
            } else {
                $paymentStatus  = 'NON_PAYE';
                $successMessage = 'Cet engagement est maintenant marqué comme non payé.';
                $messageType    = 'info';
            }

            $paymentStatusSql = StrSafe_DB($paymentStatus);

            $updateQuery = "
                UPDATE Qualifications
                SET QuNotes = $paymentStatusSql
                WHERE QuId IN (
                    SELECT EnId
                    FROM Entries
                    WHERE EnTournament = $TourId
                      AND EnId = $engagementId
                )
            ";

            $result = safe_w_sql($updateQuery);

            if ($result) {
                $_SESSION['Greffe2_payment_message'] = $successMessage;
                $_SESSION['Greffe2_message_type']    = $messageType;
            } else {
                $_SESSION['Greffe2_payment_message'] = 'Erreur lors de la mise à jour.';
                $_SESSION['Greffe2_message_type']    = 'error';
            }
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }
}

// ---------------------------------------------------------------------------
// 5. Requête : une ligne par engagement
// ---------------------------------------------------------------------------
$query = "
    SELECT
        e.EnId AS engagement_id,
        UPPER(TRIM(e.EnCode)) AS licence,
        e.EnFirstName AS prenom,
        UPPER(TRIM(e.EnName)) AS nom,
        CONCAT(e.EnDivision, e.EnClass) AS categorie,
        c.CoName AS club,
        c.CoCode AS country_code,
        q.QuSession AS session_no,
        q.QuTarget AS target,
        q.QuLetter AS letter,
        TRIM(CONCAT(COALESCE(q.QuTarget, ''), COALESCE(q.QuLetter, ''))) AS target_no,
        q.QuNotes AS qu_notes,
        CASE WHEN q.QuNotes LIKE 'PAYE%' THEN 1 ELSE 0 END AS payment_status,
        CASE WHEN q.QuNotes LIKE 'PAYE%' THEN SUBSTRING_INDEX(q.QuNotes, '|', -1) ELSE NULL END AS payment_method
    FROM Entries e
    LEFT JOIN Countries c
        ON e.EnCountry = c.CoId
       AND e.EnTournament = c.CoTournament
    LEFT JOIN Qualifications q
        ON e.EnId = q.QuId
    WHERE e.EnTournament = $TourId
      AND e.EnAthlete = 1
      AND TRIM(e.EnCode) <> ''
    ORDER BY UPPER(TRIM(e.EnCode)), q.QuSession, q.QuTarget, q.QuLetter, e.EnId
";

$Rs = safe_r_sql($query);
$engagementsData = [];

if ($Rs) {
    while ($row = safe_fetch($Rs)) {
        $session = '';

        if (trim((string)$row->session_no) !== '') {
            $session = trim((string)$row->session_no);
        }

        $target = $row->target;
        $letter = $row->letter;

        $targetTrim = trim((string)$target);
        $letterTrim = trim((string)$letter);
        $targetNo   = trim($targetTrim . $letterTrim);

        $targetIsUnassigned = false;

        if (isNullEmptyOrZero($target) || isNullEmptyOrZero($letter)) {
            $targetIsUnassigned = true;
        }

        if ($targetIsUnassigned) {
            $targetLabel = 'Archer non affecté';
            $targetNo = '';
        } else {
            if ($session !== '') {
                $targetLabel = 'D' . $session . ' - ' . $targetNo;
            } else {
                $targetLabel = $targetNo;
            }
        }

        $engagementsData[] = [
            'engagement_id'        => intval($row->engagement_id),
            'licence'              => strtoupper(trim((string)$row->licence)),
            'prenom'               => $row->prenom,
            'nom'                  => strtoupper(trim((string)$row->nom)),
            'categorie'            => $row->categorie,
            'club'                 => $row->club,
            'country_code'         => $row->country_code,
            'session'              => $session,
            'target_no'            => $targetNo,
            'target_label'         => $targetLabel,
            'target_is_unassigned' => $targetIsUnassigned,
            'payment_status'       => intval($row->payment_status),
            'payment_method'       => $row->payment_method,
        ];
    }
}

// ---------------------------------------------------------------------------
// 6. Numérotation des engagements par licence
// ---------------------------------------------------------------------------
$counterByLicence = [];

foreach ($engagementsData as $idx => $engagement) {
    $licence = strtoupper(trim((string)$engagement['licence']));

    if (!isset($counterByLicence[$licence])) {
        $counterByLicence[$licence] = 0;
    }

    $counterByLicence[$licence]++;

    $numeroEngagement = $counterByLicence[$licence];

    $montantBase = calculerPrixEngagement(
        $engagement['country_code'],
        $engagement['categorie'],
        $numeroEngagement,
        $tarifs,
        $organizerClubCode
    );

    $mode = strtoupper((string)$engagement['payment_method']);
    $montant = ($mode === 'GRATUIT') ? 0 : $montantBase;

    $engagementsData[$idx]['numero_engagement'] = $numeroEngagement;
    $engagementsData[$idx]['montant_base']      = $montantBase;
    $engagementsData[$idx]['montant']           = $montant;
}

// ---------------------------------------------------------------------------
// 7. Listes de filtres
// ---------------------------------------------------------------------------
$clubs = [];
$categories = [];
$sessions = [];

foreach ($engagementsData as $e) {
    if (!empty($e['club'])) {
        $clubs[$e['club']] = true;
    }

    if (!empty($e['categorie'])) {
        $categories[$e['categorie']] = true;
    }

    if ($e['session'] !== '') {
        $sessions[$e['session']] = true;
    }
}

$clubs = array_keys($clubs);
sort($clubs, SORT_NATURAL | SORT_FLAG_CASE);

$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$sessions = array_keys($sessions);
sort($sessions, SORT_NUMERIC);

// ---------------------------------------------------------------------------
// 8. Tri
// ---------------------------------------------------------------------------
usort($engagementsData, function($a, $b) use ($sortColumn, $sortDirection) {
    $dir = ($sortDirection === 'desc') ? -1 : 1;

    switch ($sortColumn) {
        case 'engagement':
            return $dir * ($a['numero_engagement'] <=> $b['numero_engagement']);

        case 'licence':
            return $dir * strcmp((string)$a['licence'], (string)$b['licence']);

        case 'prenom':
            return $dir * strcmp(mb_strtolower((string)$a['prenom']), mb_strtolower((string)$b['prenom']));

        case 'nom':
            return $dir * strcmp(mb_strtolower((string)$a['nom']), mb_strtolower((string)$b['nom']));

        case 'club':
            return $dir * strcmp(mb_strtolower((string)$a['club']), mb_strtolower((string)$b['club']));

        case 'categorie':
            return $dir * strcmp(mb_strtolower((string)$a['categorie']), mb_strtolower((string)$b['categorie']));

        case 'depart':
            return $dir * strcmp((string)$a['session'], (string)$b['session']);

        case 'cible':
            return $dir * strcmp((string)$a['target_no'], (string)$b['target_no']);

        case 'montant':
            return $dir * ($a['montant'] <=> $b['montant']);

        case 'statut':
            return $dir * ($a['payment_status'] <=> $b['payment_status']);

        case 'paiement':
            return $dir * strcmp((string)$a['payment_method'], (string)$b['payment_method']);

        default:
            return $dir * strcmp(mb_strtolower((string)$a['nom']), mb_strtolower((string)$b['nom']));
    }
});

// ---------------------------------------------------------------------------
// 9. Affichage
// ---------------------------------------------------------------------------
$PAGE_TITLE    = "Greffe - Paiements des engagements";
$IncludeJquery = true;
include('Common/Templates/head.php');
?>

<style>
    :root {
        --primary-color: #2563eb;
        --primary-dark: #1d4ed8;
        --success-color: #16a34a;
        --danger-color: #dc2626;
        --border-radius: 6px;
        --shadow-soft: 0 4px 12px rgba(15, 23, 42, 0.08);
        --text-muted: #6b7280;
        --text-strong: #111827;
        --chip-bg: #e5e7eb;
    }

    body {
        background: #f9fafb;
    }

    .greffe-container {
        width: fit-content;
        max-width: 100%;
        margin: 0 auto;
        padding: 16px;
        box-sizing: border-box;
    }

    .greffe-header {
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 10px;
        align-items: center;
        margin-bottom: 16px;
        width: 100%;
    }

    .greffe-header-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-strong);
    }

    .greffe-header-sub {
        font-size: 13px;
        color: var(--text-muted);
    }

    .greffe-header-sub strong {
        color: var(--text-strong);
    }

    .btn-primary,
    .btn-success,
    .btn-danger,
    .btn-ghost {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-primary {
        background: var(--primary-color);
        color: #fff;
    }

    .btn-primary:hover {
        background: var(--primary-dark);
    }

    .btn-success {
        background: var(--success-color);
        color: #fff;
    }

    .btn-danger {
        background: var(--danger-color);
        color: #fff;
    }

    .btn-ghost {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid #d1d5db;
    }

    .card {
        background: #fff;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-soft);
        padding: 12px 14px;
        margin-bottom: 12px;
        box-sizing: border-box;
        width: 100%;
    }

    .card-title {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: var(--text-strong);
    }

    .card-help {
        font-size: 12px;
        color: var(--text-muted);
        margin-bottom: 8px;
    }

    .filters-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: flex-end;
    }

    .filter-group {
        flex: 0 1 auto;
        min-width: 170px;
    }

    .filter-group label {
        display: block;
        font-size: 12px;
        margin-bottom: 4px;
        color: var(--text-muted);
        font-weight: 500;
    }

    .filter-group select,
    .filter-group input[type="text"] {
        width: auto;
        min-width: 170px;
        max-width: 260px;
        padding: 7px 10px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        font-size: 13px;
        box-sizing: border-box;
        background: #f9fafb;
    }

    .filter-group input[type="text"] {
        min-width: 220px;
    }

    .filter-actions {
        display: flex;
        gap: 6px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .alert {
        padding: 10px 12px;
        border-radius: var(--border-radius);
        font-size: 13px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        box-sizing: border-box;
    }

    .alert-success {
        background-color: #dcfce7;
        border: 1px solid #bbf7d0;
        color: #166534;
    }

    .alert-error {
        background-color: #fee2e2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }

    .alert-info {
        background-color: #e0f2fe;
        border: 1px solid #bae6fd;
        color: #075985;
    }

    .table-wrapper {
        border-radius: var(--border-radius);
        background: #fff;
        box-shadow: var(--shadow-soft);
        overflow: hidden;
        width: 100%;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    table {
        border-collapse: collapse;
        font-size: 13px;
        width: max-content;
        min-width: 100%;
        table-layout: auto;
    }

    thead {
        background: #f9fafb;
    }

    th,
    td {
        padding: 8px 10px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
        white-space: nowrap;
        width: auto;
    }

    th {
        font-weight: 600;
        font-size: 12px;
        color: var(--text-muted);
    }

    th a {
        color: inherit;
        text-decoration: none;
    }

    th a:hover {
        text-decoration: underline;
    }

    tbody tr:hover {
        background-color: #f9fafb;
    }

    .col-check {
        width: 32px;
        text-align: center;
    }

    .status-paid {
        color: var(--success-color);
        font-weight: 600;
        font-size: 12px;
    }

    .status-unpaid {
        color: var(--danger-color);
        font-weight: 600;
        font-size: 12px;
    }

    .target-unassigned {
        color: #dc2626;
        font-weight: 700;
    }

    .chip {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        background: var(--chip-bg);
        font-size: 11px;
        color: #374151;
        white-space: nowrap;
    }

    .chip-engagement {
        background: #dbeafe;
        color: #1d4ed8;
        font-weight: 700;
    }

    .chip.target-unassigned {
        color: #dc2626;
        font-weight: 700;
        background: #fee2e2;
    }

    .payment-inline {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        width: auto;
        white-space: nowrap;
    }

    .payment-inline select {
        width: auto;
        min-width: max-content;
        max-width: 180px;
        padding: 3px 6px;
        font-size: 11px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        background: #fff;
    }

    .summary-footer {
        margin-top: 8px;
        font-size: 13px;
        color: var(--text-muted);
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 6px;
        width: 100%;
    }

    .summary-footer strong {
        color: var(--text-strong);
    }

    @media (max-width: 768px) {
        .greffe-container {
            width: 100%;
            padding: 12px;
        }

        .filter-group,
        .filter-group select,
        .filter-group input[type="text"] {
            width: 100%;
            max-width: 100%;
        }

        .table-wrapper {
            display: none;
        }

        .mobile-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .mobile-card {
            background: #fff;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-soft);
            padding: 10px 12px;
            font-size: 13px;
        }

        .mobile-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 6px;
        }

        .mobile-name {
            font-weight: 600;
        }

        .mobile-meta {
            font-size: 12px;
            color: var(--text-muted);
        }

        .mobile-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .mobile-actions {
            margin-top: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .greffe-header {
            align-items: flex-start;
        }
    }

    @media (min-width: 769px) {
        .mobile-list {
            display: none;
        }
    }
</style>

<div class="greffe-container">
    <div class="greffe-header">
        <div>
            <div class="greffe-header-title">Gestion des paiements par engagement</div>
            <div class="greffe-header-sub">
                Concours :
                <strong><?php echo htmlspecialchars($tournamentName ?: "n° $TourId"); ?></strong>
                <?php if ($organizerClubName || $organizerClubCode): ?>
                    – Club organisateur :
                    <strong><?php echo htmlspecialchars($organizerClubName ?: $organizerClubCode); ?></strong>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/Greffe2/config_tarifs.php?ToId=<?php echo $TourId; ?>" class="btn-primary">
                ⚙ Paramétrer tarifs & paiements
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['Greffe2_payment_message'])): ?>
        <?php
        $type = isset($_SESSION['Greffe2_message_type']) ? $_SESSION['Greffe2_message_type'] : 'success';
        $msg  = $_SESSION['Greffe2_payment_message'];
        unset($_SESSION['Greffe2_payment_message'], $_SESSION['Greffe2_message_type']);
        ?>
        <div class="alert alert-<?php echo htmlspecialchars($type); ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">Filtres</div>

        <form method="GET" id="filterForm">
            <div class="filters-row">
                <div class="filter-group">
                    <label for="club_filter">Club</label>
                    <select name="club_filter" id="club_filter">
                        <option value="all">Tous les clubs</option>
                        <?php foreach ($clubs as $club): ?>
                            <option value="<?php echo htmlspecialchars($club); ?>" <?php echo ($filters['club'] === $club ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($club); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="category_filter">Catégorie</label>
                    <select name="category_filter" id="category_filter">
                        <option value="all">Toutes catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filters['category'] === $cat ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="session_filter">Départ</label>
                    <select name="session_filter" id="session_filter">
                        <option value="all">Tous les départs</option>
                        <?php foreach ($sessions as $sess): ?>
                            <option value="<?php echo htmlspecialchars($sess); ?>" <?php echo ($filters['session'] === (string)$sess ? 'selected' : ''); ?>>
                                Départ <?php echo htmlspecialchars($sess); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="payment_filter">Statut de paiement</label>
                    <select name="payment_filter" id="payment_filter">
                        <option value="all"    <?php echo ($filters['payment'] === 'all')    ? 'selected' : ''; ?>>Tous</option>
                        <option value="paid"   <?php echo ($filters['payment'] === 'paid')   ? 'selected' : ''; ?>>Payés</option>
                        <option value="unpaid" <?php echo ($filters['payment'] === 'unpaid') ? 'selected' : ''; ?>>Non payés</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="search">Recherche globale</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Licence, nom, club...">
                </div>

                <div class="filter-actions">
                    <button type="submit" name="go" value="1" class="btn-primary">Appliquer</button>
                    <button type="submit" name="reset" value="1" class="btn-ghost">Réinitialiser</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">Actions sur les engagements sélectionnés</div>
        <div class="card-help">
            Tu peux sélectionner seulement certains départs d’un archer. Les autres départs ne seront pas modifiés.
        </div>

        <form method="POST" id="bulkForm">
            <div class="filters-row">
                <div class="filter-group">
                    <label for="bulk_payment_method">Mode de paiement</label>
                    <select name="bulk_payment_method" id="bulk_payment_method">
                        <?php foreach ($paymentModes as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>">
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" name="bulk_action" value="validate" class="btn-success" onclick="return confirmBulkAction('validate');">
                        Valider le paiement
                    </button>

                    <button type="submit" name="bulk_action" value="unvalidate" class="btn-danger" onclick="return confirmBulkAction('unvalidate');">
                        Retirer le paiement
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php
    $total = 0;
    $displayCount = 0;
    $searchFilter = mb_strtolower($filters['search']);
    ?>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'engagement', 'dir' => ($sortColumn == 'engagement' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Engagement
                            </a>
                        </th>

                        <th class="col-check">
                            <input type="checkbox" onclick="toggleSelectAll(this)">
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'licence', 'dir' => ($sortColumn == 'licence' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Licence
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'prenom', 'dir' => ($sortColumn == 'prenom' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Prénom
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'nom', 'dir' => ($sortColumn == 'nom' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Nom
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'club', 'dir' => ($sortColumn == 'club' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Club
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'categorie', 'dir' => ($sortColumn == 'categorie' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Catégorie
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'depart', 'dir' => ($sortColumn == 'depart' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Départ
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'cible', 'dir' => ($sortColumn == 'cible' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Cible
                            </a>
                        </th>

                        <th style="text-align:right;">
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'montant', 'dir' => ($sortColumn == 'montant' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Montant
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'statut', 'dir' => ($sortColumn == 'statut' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Statut
                            </a>
                        </th>

                        <th>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'paiement', 'dir' => ($sortColumn == 'paiement' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">
                                Paiement
                            </a>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($engagementsData as $e): ?>
                        <?php
                        if ($filters['club'] !== 'all' && $filters['club'] !== '' && stripos((string)$e['club'], $filters['club']) === false) {
                            continue;
                        }

                        if ($filters['category'] !== 'all' && $filters['category'] !== '' && stripos((string)$e['categorie'], $filters['category']) === false) {
                            continue;
                        }

                        $is_paid = ($e['payment_status'] == 1);

                        if ($filters['payment'] === 'paid' && !$is_paid) {
                            continue;
                        }

                        if ($filters['payment'] === 'unpaid' && $is_paid) {
                            continue;
                        }

                        if ($filters['session'] !== 'all' && (string)$e['session'] !== (string)$filters['session']) {
                            continue;
                        }

                        if ($searchFilter !== '') {
                            $haystack = mb_strtolower(
                                $e['licence'] . ' ' .
                                $e['nom'] . ' ' .
                                $e['prenom'] . ' ' .
                                $e['club'] . ' ' .
                                $e['country_code'] . ' ' .
                                $e['categorie']
                            );

                            if (strpos($haystack, $searchFilter) === false) {
                                continue;
                            }
                        }

                        $total += $e['montant'];
                        $displayCount++;
                        ?>

                        <tr>
                            <td>
                                <span class="chip chip-engagement">
                                    n° <?php echo intval($e['numero_engagement']); ?>
                                </span>
                            </td>

                            <td class="col-check">
                                <input
                                    type="checkbox"
                                    name="engagements[]"
                                    value="<?php echo intval($e['engagement_id']); ?>"
                                    class="engagement-checkbox"
                                    form="bulkForm"
                                >
                            </td>

                            <td><?php echo htmlspecialchars($e['licence']); ?></td>
                            <td><?php echo htmlspecialchars($e['prenom']); ?></td>
                            <td><?php echo htmlspecialchars($e['nom']); ?></td>
                            <td><?php echo htmlspecialchars(trim($e['country_code'] . ' - ' . $e['club'])); ?></td>
                            <td><?php echo htmlspecialchars($e['categorie']); ?></td>

                            <td>
                                <?php if ($e['session'] !== ''): ?>
                                    Départ <?php echo htmlspecialchars($e['session']); ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (!empty($e['target_is_unassigned'])): ?>
                                    <span class="target-unassigned">
                                        <?php echo htmlspecialchars($e['target_label']); ?>
                                    </span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['target_label']); ?>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:right;">
                                <?php echo formatMontant($e['montant']); ?> €
                            </td>

                            <td>
                                <span class="<?php echo $is_paid ? 'status-paid' : 'status-unpaid'; ?>">
                                    <?php echo $is_paid ? 'Payé' : 'Non payé'; ?>
                                </span>
                            </td>

                            <td>
                                <?php if ($is_paid): ?>
                                    <div style="margin-bottom:4px;font-size:12px;">
                                        <strong><?php echo htmlspecialchars($e['payment_method'] ?: '—'); ?></strong>
                                    </div>

                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                                        <input type="hidden" name="validate_payment" value="unvalidate">

                                        <button
                                            type="submit"
                                            class="btn-ghost"
                                            style="padding:3px 8px;font-size:11px;border-radius:999px;border-color:#fecaca;color:#b91c1c;"
                                            onclick="return confirm('Annuler le paiement de cet engagement ?');"
                                        >
                                            Annuler
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" class="payment-inline">
                                        <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                                        <input type="hidden" name="validate_payment" value="validate">

                                        <select name="payment_method">
                                            <?php foreach ($paymentModes as $code => $label): ?>
                                                <option value="<?php echo htmlspecialchars($code); ?>">
                                                    <?php echo htmlspecialchars($label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button
                                            type="submit"
                                            class="btn-success"
                                            style="padding:3px 8px;font-size:11px;border-radius:999px;"
                                            onclick="return confirm('Valider le paiement de cet engagement ?');"
                                        >
                                            Valider
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($displayCount === 0): ?>
                        <tr>
                            <td colspan="12" style="text-align:center;padding:20px;">
                                Aucun engagement trouvé.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($displayCount > 0): ?>
                    <tfoot>
                        <tr>
                            <td colspan="9" style="text-align:right;font-weight:600;">
                                Total affiché :
                            </td>
                            <td style="text-align:right;font-weight:600;">
                                <?php echo formatMontant($total); ?> €
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="mobile-list">
        <?php foreach ($engagementsData as $e): ?>
            <?php
            if ($filters['club'] !== 'all' && $filters['club'] !== '' && stripos((string)$e['club'], $filters['club']) === false) {
                continue;
            }

            if ($filters['category'] !== 'all' && $filters['category'] !== '' && stripos((string)$e['categorie'], $filters['category']) === false) {
                continue;
            }

            $is_paid = ($e['payment_status'] == 1);

            if ($filters['payment'] === 'paid' && !$is_paid) {
                continue;
            }

            if ($filters['payment'] === 'unpaid' && $is_paid) {
                continue;
            }

            if ($filters['session'] !== 'all' && (string)$e['session'] !== (string)$filters['session']) {
                continue;
            }

            if ($searchFilter !== '') {
                $haystack = mb_strtolower(
                    $e['licence'] . ' ' .
                    $e['nom'] . ' ' .
                    $e['prenom'] . ' ' .
                    $e['club'] . ' ' .
                    $e['country_code'] . ' ' .
                    $e['categorie']
                );

                if (strpos($haystack, $searchFilter) === false) {
                    continue;
                }
            }
            ?>

            <div class="mobile-card">
                <div class="mobile-card-header">
                    <div>
                        <div style="margin-bottom:4px;">
                            <span class="chip chip-engagement">
                                Engagement n° <?php echo intval($e['numero_engagement']); ?>
                            </span>
                        </div>

                        <div class="mobile-name">
                            <?php echo htmlspecialchars($e['prenom'] . ' ' . $e['nom']); ?>
                        </div>

                        <div class="mobile-meta">
                            <?php echo htmlspecialchars($e['licence']); ?> • <?php echo htmlspecialchars($e['categorie']); ?>
                        </div>
                    </div>

                    <div>
                        <input
                            type="checkbox"
                            name="engagements[]"
                            value="<?php echo intval($e['engagement_id']); ?>"
                            class="engagement-checkbox"
                            form="bulkForm"
                        >
                    </div>
                </div>

                <div class="mobile-badges">
                    <span class="chip"><?php echo htmlspecialchars(trim($e['country_code'] . ' - ' . $e['club'])); ?></span>

                    <?php if ($e['session'] !== ''): ?>
                        <span class="chip">Départ <?php echo htmlspecialchars($e['session']); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($e['target_is_unassigned'])): ?>
                        <span class="chip target-unassigned">
                            <?php echo htmlspecialchars($e['target_label']); ?>
                        </span>
                    <?php else: ?>
                        <span class="chip">
                            <?php echo htmlspecialchars($e['target_label']); ?>
                        </span>
                    <?php endif; ?>

                    <span class="chip">Montant : <?php echo formatMontant($e['montant']); ?> €</span>

                    <span class="chip <?php echo $is_paid ? 'status-paid' : 'status-unpaid'; ?>">
                        <?php echo $is_paid ? 'Payé' : 'Non payé'; ?>
                    </span>

                    <?php if ($is_paid && $e['payment_method']): ?>
                        <span class="chip"><?php echo htmlspecialchars($e['payment_method']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="mobile-actions">
                    <?php if ($is_paid): ?>
                        <form method="POST">
                            <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                            <input type="hidden" name="validate_payment" value="unvalidate">

                            <button
                                type="submit"
                                class="btn-ghost"
                                style="padding:4px 10px;font-size:12px;border-radius:999px;border-color:#fecaca;color:#b91c1c;"
                                onclick="return confirm('Annuler le paiement de cet engagement ?');"
                            >
                                Annuler paiement
                            </button>
                        </form>
                    <?php else: ?>
                        <form method="POST" class="payment-inline">
                            <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                            <input type="hidden" name="validate_payment" value="validate">

                            <select name="payment_method">
                                <?php foreach ($paymentModes as $code => $label): ?>
                                    <option value="<?php echo htmlspecialchars($code); ?>">
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button
                                type="submit"
                                class="btn-success"
                                style="padding:4px 10px;font-size:12px;border-radius:999px;"
                                onclick="return confirm('Valider le paiement de cet engagement ?');"
                            >
                                Valider
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="summary-footer">
        <div>
            Engagements affichés :
            <strong><?php echo intval($displayCount); ?></strong>
        </div>

        <div>
            Montant total affiché :
            <strong><?php echo formatMontant($total); ?> €</strong>
            <span style="font-size:12px;color:var(--text-muted);">
                Les engagements en mode GRATUIT comptent 0 €.
            </span>
        </div>
    </div>
</div>

<script>
function toggleSelectAll(master) {
    const checked = master.checked;

    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.checked = checked;
    });
}

function confirmBulkAction(type) {
    const selected = document.querySelectorAll('.engagement-checkbox:checked');

    const uniqueValues = new Set();

    selected.forEach(cb => {
        if (cb.value !== '') {
            uniqueValues.add(cb.value);
        }
    });

    const count = uniqueValues.size;

    if (count === 0) {
        alert('Aucun engagement sélectionné.');
        return false;
    }

    const actionText = (type === 'validate')
        ? 'valider le paiement'
        : 'retirer le paiement';

    return confirm(
        'Vous allez ' + actionText + ' pour ' + count + ' engagement(s).\n\nConfirmer ?'
    );
}
</script>

<?php include('Common/Templates/tail.php'); ?>