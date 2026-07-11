<?php
// ============================================================================
// RegistrArc - invoice.php
// Facture individuelle ou groupée
// Appel individuel : invoice.php?ids=123
// Appel groupé     : invoice.php?ids=123,124,125
//
// Paiements lus depuis : Modules/Custom/RegistrArc/data/payments_<TourId>.json
// Aucun paiement n'est lu ou écrit dans Qualifications.QuNotes
//
// Visuels compétition :
// - ToLeft / ToRight via ScorePDF si disponibles
// - ToBottom via ScorePDF si disponible
// - aucune logique QRCode prise en compte ici
//
// Mise en page : A4 portrait propre
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once('Common/pdf/ScorePDF.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

if (!$TourId) {
    die('Tournoi non défini');
}

// ---------------------------------------------------------------------------
// Lecture des IDs demandés
// ---------------------------------------------------------------------------
$idsParam = '';

if (isset($_GET['ids'])) {
    $idsParam = $_GET['ids'];
} elseif (isset($_GET['id'])) {
    $idsParam = $_GET['id'];
}

$engagementIds = [];

foreach (explode(',', $idsParam) as $id) {
    $id = intval(trim($id));

    if ($id > 0) {
        $engagementIds[] = $id;
    }
}

$engagementIds = array_values(array_unique($engagementIds));

// ---------------------------------------------------------------------------
// Paramètres module
// ---------------------------------------------------------------------------
function registrarc_get_module_parameter($name, $default = '') {
    global $ModuleName, $LegacyModuleName;

    $value = getModuleParameter($ModuleName, $name, '');

    if ($value !== '' && $value !== null) {
        return $value;
    }

    return getModuleParameter($LegacyModuleName, $name, $default);
}

// ---------------------------------------------------------------------------
// Images
// ---------------------------------------------------------------------------
function registrarc_image_to_data_uri($path) {
    if (!$path || !is_file($path)) {
        return '';
    }

    $info = @getimagesize($path);

    if ($info === false || empty($info['mime'])) {
        return '';
    }

    $content = @file_get_contents($path);

    if ($content === false) {
        return '';
    }

    return 'data:' . $info['mime'] . ';base64,' . base64_encode($content);
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------
function registrarc_format_date_fr($date) {
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);

    if (!$ts) {
        return $date;
    }

    return date('d/m/Y', $ts);
}

function registrarc_tournament_date_string($from, $to) {
    $fromLabel = registrarc_format_date_fr($from);
    $toLabel = registrarc_format_date_fr($to);

    if ($fromLabel === '' && $toLabel === '') {
        return '';
    }

    if ($toLabel === '' || $toLabel === $fromLabel) {
        return $fromLabel;
    }

    return 'Du ' . $fromLabel . ' au ' . $toLabel;
}

// ---------------------------------------------------------------------------
// Visuels compétition via ScorePDF
// ---------------------------------------------------------------------------
function registrarc_get_competition_visuals(array $tournament) {
    $visuals = [
        'name' => isset($tournament['name']) ? $tournament['name'] : '',
        'where' => isset($tournament['where']) ? $tournament['where'] : '',
        'when' => isset($tournament['when_label']) ? $tournament['when_label'] : '',
        'left_logo_src' => '',
        'right_logo_src' => '',
        'bottom_image_src' => '',
        'has_left_logo' => false,
        'has_right_logo' => false,
        'has_bottom_image' => false,
    ];

    if (!class_exists('ScorePDF')) {
        return $visuals;
    }

    try {
        $pdf = new ScorePDF(true);

        if (!empty($pdf->Name)) {
            $visuals['name'] = $pdf->Name;
        }

        if (!empty($pdf->Where)) {
            $visuals['where'] = $pdf->Where;
        }

        if (!empty($pdf->WhenF) || !empty($pdf->WhenT)) {
            $dateLabel = registrarc_tournament_date_string($pdf->WhenF, $pdf->WhenT);

            if ($dateLabel !== '') {
                $visuals['when'] = $dateLabel;
            }
        }

        if (!empty($pdf->PrintLogo) && !empty($pdf->ToPaths) && is_array($pdf->ToPaths)) {
            if (!empty($pdf->ToPaths['ToLeft']) && file_exists($pdf->ToPaths['ToLeft'])) {
                $visuals['left_logo_src'] = registrarc_image_to_data_uri($pdf->ToPaths['ToLeft']);
                $visuals['has_left_logo'] = ($visuals['left_logo_src'] !== '');
            }

            if (!empty($pdf->ToPaths['ToRight']) && file_exists($pdf->ToPaths['ToRight'])) {
                $visuals['right_logo_src'] = registrarc_image_to_data_uri($pdf->ToPaths['ToRight']);
                $visuals['has_right_logo'] = ($visuals['right_logo_src'] !== '');
            }
        }

        if (!empty($pdf->BottomImage) && !empty($pdf->ToPaths) && is_array($pdf->ToPaths)) {
            if (!empty($pdf->ToPaths['ToBottom']) && file_exists($pdf->ToPaths['ToBottom'])) {
                $visuals['bottom_image_src'] = registrarc_image_to_data_uri($pdf->ToPaths['ToBottom']);
                $visuals['has_bottom_image'] = ($visuals['bottom_image_src'] !== '');
            }
        }
    } catch (Throwable $e) {
        return $visuals;
    }

    return $visuals;
}

// ---------------------------------------------------------------------------
// JSON paiements
// ---------------------------------------------------------------------------
function registrarc_data_dir() {
    return dirname(__FILE__) . '/data';
}

function registrarc_payments_file_path($TourId) {
    return registrarc_data_dir() . '/payments_' . intval($TourId) . '.json';
}

function registrarc_settings_file_path($TourId) {
    return registrarc_data_dir() . '/settings_' . intval($TourId) . '.json';
}

function registrarc_load_payments($TourId) {
    $file = registrarc_payments_file_path($TourId);

    if (!file_exists($file)) {
        return [];
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [];
    }

    return $data;
}

// ---------------------------------------------------------------------------
// Paramètres JSON tarifs / paiements
// ---------------------------------------------------------------------------
function registrarc_default_tarifs() {
    return [
        'clubs_autres' => [
            'jeunes'  => [1 => 8,  2 => 14],
            'adultes' => [1 => 10, 2 => 18],
        ],
        'organizer' => [
            'jeunes'  => [1 => 4, 2 => 8],
            'adultes' => [1 => 5, 2 => 10],
        ],
    ];
}

function registrarc_default_payment_modes() {
    return [
        'ESP' => 'Espèces',
        'CHQ' => 'Chèque',
        'VIR' => 'Virement',
        'GRA' => 'Gratuit',
    ];
}

function registrarc_normalize_payment_code($code) {
    $code = strtoupper(trim((string)$code));

    $map = [
        'ESPECE'   => 'ESP',
        'ESPECES'  => 'ESP',
        'ESPÈCE'   => 'ESP',
        'ESPÈCES'  => 'ESP',
        'CHEQUE'   => 'CHQ',
        'CHÈQUE'   => 'CHQ',
        'VIREMENT' => 'VIR',
        'GRATUIT'  => 'GRA',
    ];

    return isset($map[$code]) ? $map[$code] : $code;
}

function registrarc_normalize_payment_modes($modes) {
    if (!is_array($modes) || empty($modes)) {
        return registrarc_default_payment_modes();
    }

    $clean = [];

    foreach ($modes as $code => $label) {
        $code = registrarc_normalize_payment_code($code);
        $label = trim((string)$label);

        if ($code === '') {
            continue;
        }

        if ($label === '') {
            if ($code === 'ESP') {
                $label = 'Espèces';
            } elseif ($code === 'CHQ') {
                $label = 'Chèque';
            } elseif ($code === 'VIR') {
                $label = 'Virement';
            } elseif ($code === 'GRA') {
                $label = 'Gratuit';
            } else {
                $label = $code;
            }
        }

        $clean[$code] = $label;
    }

    return empty($clean) ? registrarc_default_payment_modes() : $clean;
}

function registrarc_load_settings($TourId) {
    $file = registrarc_settings_file_path($TourId);

    if (is_file($file)) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (is_array($data)) {
            return $data;
        }
    }

    $settings = [
        'tarifs' => registrarc_default_tarifs(),
        'payment_modes' => registrarc_default_payment_modes(),
        'rules' => [],
    ];

    $tarifsJson = registrarc_get_module_parameter('Tarifs_' . intval($TourId), '');
    if ($tarifsJson) {
        $tarifsData = json_decode($tarifsJson, true);
        if (is_array($tarifsData) && isset($tarifsData['clubs_autres'], $tarifsData['organizer'])) {
            $settings['tarifs'] = $tarifsData;
        }
    }

    $modesJson = registrarc_get_module_parameter('PaymentModes_' . intval($TourId), '');
    if ($modesJson) {
        $modesData = json_decode($modesJson, true);
        if (is_array($modesData)) {
            $settings['payment_modes'] = registrarc_normalize_payment_modes($modesData);
        }
    }

    return $settings;
}

function registrarc_load_tarifs($TourId, $organizerClubCode, $organizerClubName) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['tarifs']) && is_array($settings['tarifs']) && isset($settings['tarifs']['clubs_autres'], $settings['tarifs']['organizer'])) {
        $settings['tarifs']['organizer_club_code'] = $organizerClubCode;
        $settings['tarifs']['organizer_club_name'] = $organizerClubName;
        return $settings['tarifs'];
    }

    $default = registrarc_default_tarifs();
    $default['organizer_club_code'] = $organizerClubCode;
    $default['organizer_club_name'] = $organizerClubName;
    return $default;
}

function registrarc_load_payment_modes($TourId) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['payment_modes']) && is_array($settings['payment_modes'])) {
        return registrarc_normalize_payment_modes($settings['payment_modes']);
    }

    return registrarc_default_payment_modes();
}

// ---------------------------------------------------------------------------
// Tarifs
// ---------------------------------------------------------------------------
function registrarc_age_category($categorie) {
    $categorie = (string)$categorie;

    foreach (['U11', 'U13', 'U15', 'U18'] as $c) {
        if (stripos($categorie, $c) !== false) {
            return 'jeunes';
        }
    }

    return 'adultes';
}

function registrarc_prix_engagement($clubCode, $categorie, $numeroEngagement, $tarifs, $organizerClubCode) {
    $isOrganizer = ($clubCode == $organizerClubCode);
    $age = registrarc_age_category($categorie);

    if ($isOrganizer) {
        $p1 = isset($tarifs['organizer'][$age][1]) ? floatval($tarifs['organizer'][$age][1]) : 0;
        $p2 = isset($tarifs['organizer'][$age][2]) ? floatval($tarifs['organizer'][$age][2]) : $p1;
    } else {
        $p1 = isset($tarifs['clubs_autres'][$age][1]) ? floatval($tarifs['clubs_autres'][$age][1]) : 0;
        $p2 = isset($tarifs['clubs_autres'][$age][2]) ? floatval($tarifs['clubs_autres'][$age][2]) : $p1;
    }

    if ($numeroEngagement <= 1) {
        return $p1;
    }

    return $p2 - $p1;
}

function registrarc_format_montant($montant) {
    $montant = floatval($montant);

    if (floor($montant) == $montant) {
        return intval($montant);
    }

    return number_format($montant, 2, ',', ' ');
}

function registrarc_is_free_method($method) {
    $method = registrarc_normalize_payment_code($method);
    return in_array($method, ['GRA', 'GRATUIT'], true);
}

function registrarc_is_null_empty_or_zero($value) {
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

// ---------------------------------------------------------------------------
// Infos tournoi
// ---------------------------------------------------------------------------
function registrarc_get_tournament_info($TourId) {
    $TourId = intval($TourId);

    $data = [
        'name' => 'Concours n° ' . $TourId,
        'where' => '',
        'when_from' => '',
        'when_to' => '',
        'when_label' => '',
        'organizer_code' => '',
        'organizer_name' => '',
    ];

    $query = "
        SELECT ToCommitee, ToComDescr, ToName, ToWhere, ToWhenFrom, ToWhenTo
        FROM Tournament
        WHERE ToId = $TourId
    ";

    $rs = safe_r_sql($query);

    if ($row = safe_fetch($rs)) {
        $data['name'] = $row->ToName;
        $data['where'] = $row->ToWhere;
        $data['when_from'] = $row->ToWhenFrom;
        $data['when_to'] = $row->ToWhenTo;
        $data['when_label'] = registrarc_tournament_date_string($row->ToWhenFrom, $row->ToWhenTo);
        $data['organizer_code'] = $row->ToCommitee;
        $data['organizer_name'] = $row->ToComDescr;

        if (empty($data['organizer_name']) && $data['organizer_code']) {
            $organizerNameQuery = "
                SELECT CoName
                FROM Countries
                WHERE CoCode = " . StrSafe_DB($data['organizer_code']) . "
                  AND CoTournament = $TourId
            ";

            $organizerNameRs = safe_r_sql($organizerNameQuery);

            if ($on = safe_fetch($organizerNameRs)) {
                $data['organizer_name'] = $on->CoName;
            }
        }
    }

    return $data;
}

// ---------------------------------------------------------------------------
// Numérotation globale des engagements par licence
// ---------------------------------------------------------------------------
function registrarc_get_engagement_numbers($TourId) {
    $TourId = intval($TourId);
    $numbers = [];
    $counterByLicence = [];

    $query = "
        SELECT
            e.EnId AS engagement_id,
            UPPER(TRIM(e.EnCode)) AS licence,
            q.QuSession AS session_no,
            q.QuTarget AS target,
            q.QuLetter AS letter
        FROM Entries e
        LEFT JOIN Qualifications q
            ON e.EnId = q.QuId
        WHERE e.EnTournament = $TourId
          AND e.EnAthlete = 1
          AND TRIM(e.EnCode) <> ''
        ORDER BY UPPER(TRIM(e.EnCode)), q.QuSession, q.QuTarget, q.QuLetter, e.EnId
    ";

    $rs = safe_r_sql($query);

    if ($rs) {
        while ($row = safe_fetch($rs)) {
            $licence = strtoupper(trim((string)$row->licence));

            if (!isset($counterByLicence[$licence])) {
                $counterByLicence[$licence] = 0;
            }

            $counterByLicence[$licence]++;
            $numbers[intval($row->engagement_id)] = $counterByLicence[$licence];
        }
    }

    return $numbers;
}

// ---------------------------------------------------------------------------
// Récupération des engagements
// ---------------------------------------------------------------------------
function registrarc_get_invoice_engagements($TourId, array $engagementIds) {
    $TourId = intval($TourId);
    $ids = array_values(array_unique(array_filter(array_map('intval', $engagementIds))));

    if (empty($ids)) {
        return [];
    }

    $idsSql = implode(',', $ids);

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
            q.QuLetter AS letter
        FROM Entries e
        LEFT JOIN Countries c
            ON e.EnCountry = c.CoId
           AND e.EnTournament = c.CoTournament
        LEFT JOIN Qualifications q
            ON e.EnId = q.QuId
        WHERE e.EnTournament = $TourId
          AND e.EnId IN ($idsSql)
        ORDER BY UPPER(TRIM(e.EnCode)), q.QuSession, q.QuTarget, q.QuLetter, e.EnId
    ";

    $rs = safe_r_sql($query);
    $rows = [];

    if ($rs) {
        while ($row = safe_fetch($rs)) {
            $rows[] = [
                'engagement_id' => intval($row->engagement_id),
                'licence' => strtoupper(trim((string)$row->licence)),
                'nom' => strtoupper(trim((string)$row->nom)),
                'prenom' => trim((string)$row->prenom),
                'categorie' => $row->categorie,
                'club' => $row->club,
                'country_code' => $row->country_code,
                'session_no' => trim((string)$row->session_no),
                'target' => $row->target,
                'letter' => $row->letter,
            ];
        }
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Enrichissement
// ---------------------------------------------------------------------------
function registrarc_enrich_invoice_rows(array $rows, array $tarifs, $organizerClubCode, array $payments, array $engagementNumbers, array $paymentModes) {
    $result = [];

    foreach ($rows as $row) {
        $engagementId = intval($row['engagement_id']);
        $numeroEngagement = isset($engagementNumbers[$engagementId]) ? intval($engagementNumbers[$engagementId]) : 1;

        $target = trim((string)$row['target']);
        $letter = trim((string)$row['letter']);
        $targetNo = trim($target . $letter);

        $targetIsUnassigned = registrarc_is_null_empty_or_zero($row['target']) || registrarc_is_null_empty_or_zero($row['letter']);

        if ($targetIsUnassigned) {
            $targetLabel = 'Archer non affecté';
        } else {
            $targetLabel = $targetNo;
        }

        $sessionLabel = $row['session_no'] !== '' ? 'Départ ' . $row['session_no'] : '—';

        if (!$targetIsUnassigned && $row['session_no'] !== '') {
            $targetLabel = 'D' . $row['session_no'] . ' - ' . $targetNo;
        }

        $payment = isset($payments[(string)$engagementId]) && is_array($payments[(string)$engagementId])
            ? $payments[(string)$engagementId]
            : [];

        $isPaid = isset($payment['status']) && $payment['status'] === 'PAYE';
        $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';

        $methodLabel = '—';

        if ($method !== '') {
            $methodLabel = isset($paymentModes[$method]) ? $paymentModes[$method] : $method;
        }

        $amountBase = registrarc_prix_engagement(
            $row['country_code'],
            $row['categorie'],
            $numeroEngagement,
            $tarifs,
            $organizerClubCode
        );

        $amount = registrarc_is_free_method($method) ? 0 : $amountBase;

        $row['numero_engagement'] = $numeroEngagement;
        $row['session_label'] = $sessionLabel;
        $row['target_label'] = $targetLabel;
        $row['payment_status_label'] = $isPaid ? 'Payé' : 'Non payé';
        $row['payment_method'] = $method;
        $row['payment_method_label'] = $methodLabel;
        $row['amount'] = $amount;

        $result[] = $row;
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Destinataire
// ---------------------------------------------------------------------------
function registrarc_build_invoice_customer_label(array $rows) {
    if (empty($rows)) {
        return '';
    }

    $clubs = [];
    $archers = [];

    foreach ($rows as $row) {
        if (!empty($row['club'])) {
            $clubs[$row['club']] = true;
        }

        $archer = trim($row['nom'] . ' ' . $row['prenom']);

        if ($archer !== '') {
            $archers[$archer] = true;
        }
    }

    $clubs = array_keys($clubs);
    $archers = array_keys($archers);

    if (count($clubs) === 1 && count($archers) > 1) {
        return $clubs[0] . "\n" . implode("\n", $archers);
    }

    if (count($archers) === 1) {
        return $archers[0];
    }

    if (count($clubs) === 1) {
        return $clubs[0];
    }

    return 'Facture groupée' . "\n" . implode("\n", $archers);
}

// ---------------------------------------------------------------------------
// Numéro de facture non persistant
// ---------------------------------------------------------------------------
function registrarc_generate_invoice_number($TourId, array $ids) {
    $TourId = intval($TourId);
    $suffix = implode('-', array_slice(array_map('intval', $ids), 0, 3));

    if (count($ids) > 3) {
        $suffix .= '-G' . count($ids);
    }

    return 'RA-' . $TourId . '-' . date('Ymd-His') . '-' . $suffix;
}

// ---------------------------------------------------------------------------
// Erreur
// ---------------------------------------------------------------------------
function registrarc_render_error($title, $message) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            body {
                font-family: Arial, Helvetica, sans-serif;
                background: #f9fafb;
                color: #111827;
                padding: 30px;
            }

            .box {
                max-width: 700px;
                margin: 0 auto;
                background: #fff;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            }

            h1 {
                font-size: 20px;
                margin-top: 0;
            }

            .error {
                color: #b91c1c;
                font-weight: 700;
            }

            .btn {
                display: inline-flex;
                margin-top: 12px;
                padding: 8px 14px;
                background: #2563eb;
                color: #fff;
                text-decoration: none;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 700;
            }
        </style>
    </head>
    <body>
        <div class="box">
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p class="error"><?php echo htmlspecialchars($message); ?></p>
            <a href="index.php" class="btn">Retour</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
if (empty($engagementIds)) {
    registrarc_render_error(
        'Facture impossible',
        'Aucun engagement sélectionné pour générer la facture.'
    );
}

$tournament = registrarc_get_tournament_info($TourId);
$competitionVisuals = registrarc_get_competition_visuals($tournament);
$payments = registrarc_load_payments($TourId);
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifs = registrarc_load_tarifs($TourId, $tournament['organizer_code'], $tournament['organizer_name']);
$engagementNumbers = registrarc_get_engagement_numbers($TourId);

$rows = registrarc_get_invoice_engagements($TourId, $engagementIds);

if (empty($rows)) {
    registrarc_render_error(
        'Facture impossible',
        'Aucun engagement trouvé pour cette facture.'
    );
}

$rows = registrarc_enrich_invoice_rows(
    $rows,
    $tarifs,
    $tournament['organizer_code'],
    $payments,
    $engagementNumbers,
    $paymentModes
);

$total = 0;

foreach ($rows as $row) {
    $total += $row['amount'];
}

$invoiceNumber = registrarc_generate_invoice_number($TourId, $engagementIds);
$invoiceDate = date('d/m/Y');
$customerLabel = registrarc_build_invoice_customer_label($rows);
$isGroupedInvoice = count($rows) > 1;

// ---------------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture <?php echo htmlspecialchars($invoiceNumber); ?></title>

    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #111827;
            background: #f3f4f6;
            font-size: 12px;
        }

        .invoice-page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 14mm;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        }

        .competition-header {
            display: grid;
            grid-template-columns: 25mm 1fr 25mm;
            align-items: center;
            gap: 8mm;
            min-height: 18mm;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 4mm;
            margin-bottom: 6mm;
        }

        .competition-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 16mm;
        }

        .competition-logo img {
            max-height: 15mm;
            max-width: 24mm;
            object-fit: contain;
        }

        .competition-info {
            text-align: center;
        }

        .competition-name {
            font-size: 16px;
            font-weight: 800;
            color: #111827;
            margin-bottom: 2px;
        }

        .competition-where {
            font-size: 12px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 1px;
        }

        .competition-date {
            font-size: 12px;
            color: #4b5563;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .brand-title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .brand-subtitle {
            font-size: 12px;
            color: #4b5563;
        }

        .invoice-meta {
            text-align: right;
            font-size: 12px;
            line-height: 1.5;
        }

        .invoice-meta strong {
            font-weight: 700;
        }

        .box-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .box {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 10px 12px;
            min-height: 82px;
            background: #fff;
        }

        .box-title {
            font-weight: 700;
            margin-bottom: 6px;
            color: #111827;
            font-size: 12px;
        }

        .box-content {
            line-height: 1.4;
            white-space: pre-line;
            font-size: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-top: 8px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 6px 7px;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f4f6;
            font-weight: 700;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .total-row td {
            font-weight: 800;
            background: #f9fafb;
            font-size: 12px;
        }

        .status-paid {
            color: #166534;
            font-weight: 700;
        }

        .status-unpaid {
            color: #991b1b;
            font-weight: 700;
        }

        .bottom-image-wrapper {
            margin-top: 12mm;
            text-align: center;
            page-break-inside: avoid;
        }

        .bottom-image {
            max-width: 100%;
            max-height: 7.5mm;
            object-fit: contain;
        }

        .actions {
            width: 210mm;
            margin: 12px auto 24px;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            border: none;
            text-decoration: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-ghost {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }

        @media print {
            body {
                background: #fff;
            }

            .invoice-page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            .actions {
                display: none;
            }

            .box,
            .competition-header,
            .invoice-header,
            .bottom-image-wrapper {
                break-inside: avoid;
            }

            table {
                page-break-inside: auto;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            thead {
                display: table-header-group;
            }
        }

        @media (max-width: 700px) {
            .invoice-page {
                width: 100%;
                min-height: auto;
                margin: 0;
                padding: 14px;
                box-shadow: none;
            }

            .competition-header {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .invoice-header,
            .box-grid {
                display: block;
            }

            .invoice-meta {
                text-align: left;
                margin-top: 12px;
            }

            .box {
                margin-bottom: 10px;
            }

            .actions {
                width: auto;
                margin: 10px;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>
    <div class="invoice-page">
        <div class="competition-header">
            <div class="competition-logo">
                <?php if (!empty($competitionVisuals['has_left_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($competitionVisuals['left_logo_src']); ?>" alt="Logo gauche">
                <?php endif; ?>
            </div>

            <div class="competition-info">
                <div class="competition-name">
                    <?php echo htmlspecialchars($competitionVisuals['name']); ?>
                </div>

                <?php if (!empty($competitionVisuals['where'])): ?>
                    <div class="competition-where">
                        <?php echo htmlspecialchars($competitionVisuals['where']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($competitionVisuals['when'])): ?>
                    <div class="competition-date">
                        <?php echo htmlspecialchars($competitionVisuals['when']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="competition-logo">
                <?php if (!empty($competitionVisuals['has_right_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($competitionVisuals['right_logo_src']); ?>" alt="Logo droit">
                <?php endif; ?>
            </div>
        </div>

        <div class="invoice-header">
            <div>
                <div class="brand-title">Facture</div>
                <div class="brand-subtitle">Gestion des engagements - Registr’Arc</div>
                <div class="brand-subtitle">Le module de Greffe pour I@nseo</div>
            </div>

            <div class="invoice-meta">
                <strong>Facture n° :</strong> <?php echo htmlspecialchars($invoiceNumber); ?><br>
                <strong>Date :</strong> <?php echo htmlspecialchars($invoiceDate); ?><br>
                <strong>Type :</strong> <?php echo $isGroupedInvoice ? 'Facture groupée' : 'Facture individuelle'; ?>
            </div>
        </div>

        <div class="box-grid">
            <div class="box">
                <div class="box-title">Concours</div>
                <div class="box-content">
<?php echo htmlspecialchars($tournament['name']); ?>

<?php if ($tournament['organizer_name'] || $tournament['organizer_code']): ?>
Organisateur : <?php echo htmlspecialchars($tournament['organizer_name'] ?: $tournament['organizer_code']); ?>
<?php endif; ?>

<?php if (!empty($tournament['where'])): ?>
Lieu : <?php echo htmlspecialchars($tournament['where']); ?>
<?php endif; ?>

<?php if (!empty($tournament['when_label'])): ?>
Date : <?php echo htmlspecialchars($tournament['when_label']); ?>
<?php endif; ?>
                </div>
            </div>

            <div class="box">
                <div class="box-title">Facturé à</div>
                <div class="box-content"><?php echo htmlspecialchars($customerLabel); ?></div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:8%;">Eng.</th>
                    <th style="width:12%;">Licence</th>
                    <th style="width:20%;">Archer</th>
                    <th style="width:10%;">Catégorie</th>
                    <th style="width:12%;">Départ</th>
                    <th style="width:12%;">Cible</th>
                    <th style="width:10%;">Statut</th>
                    <th style="width:10%;">Paiement</th>
                    <th style="width:8%;" class="right">Montant</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="center">n° <?php echo intval($row['numero_engagement']); ?></td>
                        <td><?php echo htmlspecialchars($row['licence']); ?></td>
                        <td><?php echo htmlspecialchars(trim($row['nom'] . ' ' . $row['prenom'])); ?></td>
                        <td><?php echo htmlspecialchars($row['categorie']); ?></td>
                        <td><?php echo htmlspecialchars($row['session_label']); ?></td>
                        <td><?php echo htmlspecialchars($row['target_label']); ?></td>
                        <td>
                            <span class="<?php echo ($row['payment_status_label'] === 'Payé') ? 'status-paid' : 'status-unpaid'; ?>">
                                <?php echo htmlspecialchars($row['payment_status_label']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['payment_method_label']); ?></td>
                        <td class="right"><?php echo registrarc_format_montant($row['amount']); ?> €</td>
                    </tr>
                <?php endforeach; ?>

                <tr class="total-row">
                    <td colspan="8" class="right">Total</td>
                    <td class="right"><?php echo registrarc_format_montant($total); ?> €</td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($competitionVisuals['has_bottom_image'])): ?>
            <div class="bottom-image-wrapper">
                <img src="<?php echo htmlspecialchars($competitionVisuals['bottom_image_src']); ?>" alt="Logo / sponsors" class="bottom-image">
            </div>
        <?php endif; ?>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="closeInvoiceTab();">
            Retour
        </button>

        <button type="button" class="btn btn-primary" onclick="window.print();">
            Imprimer / Enregistrer en PDF
        </button>
    </div>

    <script>
        function closeInvoiceTab() {
            window.close();

            setTimeout(function() {
                if (!window.closed) {
                    window.location.href = 'index.php';
                }
            }, 300);

            return false;
        }
    </script>
</body>
</html>