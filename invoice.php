<?php
// ============================================================================
// RegistrArc - invoice.php
// Facture individuelle ou groupée
//
// Appel individuel : invoice.php?ids=123
// Appel groupé     : invoice.php?ids=123,124,125
//
// Paiements lus depuis : Modules/Custom/RegistrArc/data/payments_<TourId>.json
// Aucun paiement n'est lu ou écrit dans Qualifications.QuNotes
//
// Tarifs :
// - tarifs de base depuis data/settings_<TourId>.json
// - règles avancées cumulées si plusieurs règles correspondent
// - si aucune règle avancée ne correspond : tarif de base
//
// Visuels compétition :
// - ToLeft / ToRight via ScorePDF si disponibles
// - ToBottom via ScorePDF si disponible
// - aucune logique QRCode prise en compte ici
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
// Chemins JSON
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
    } catch (Exception $e) {
        return $visuals;
    }

    return $visuals;
}

// ---------------------------------------------------------------------------
// JSON paiements
// ---------------------------------------------------------------------------
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
// Paramètres JSON tarifs / paiements / règles
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

function registrarc_default_age_classes() {
    return [
        'jeunes' => ['U11', 'U13', 'U15', 'U18'],
        'adultes' => ['U21', 'S1', 'S2', 'S3', 'Senior', 'Scratch'],
    ];
}

function registrarc_split_age_class_string($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[,;\r\n]+/', $value);
    $out = [];

    foreach ($parts as $part) {
        $part = strtoupper(trim((string)$part));

        if ($part !== '') {
            $out[] = $part;
        }
    }

    return array_values(array_unique($out));
}

function registrarc_normalize_age_classes($ageClasses) {
    $default = registrarc_default_age_classes();

    if (!is_array($ageClasses)) {
        return $default;
    }

    $jeunes = isset($ageClasses['jeunes']) ? $ageClasses['jeunes'] : [];
    $adultes = isset($ageClasses['adultes']) ? $ageClasses['adultes'] : [];

    if (!is_array($jeunes)) {
        $jeunes = registrarc_split_age_class_string($jeunes);
    }

    if (!is_array($adultes)) {
        $adultes = registrarc_split_age_class_string($adultes);
    }

    $cleanJeunes = [];
    foreach ($jeunes as $cat) {
        $cat = strtoupper(trim((string)$cat));
        if ($cat !== '') {
            $cleanJeunes[] = $cat;
        }
    }

    $cleanAdultes = [];
    foreach ($adultes as $cat) {
        $cat = strtoupper(trim((string)$cat));
        if ($cat !== '') {
            $cleanAdultes[] = $cat;
        }
    }

    $cleanJeunes = array_values(array_unique($cleanJeunes));
    $cleanAdultes = array_values(array_unique($cleanAdultes));

    if (empty($cleanJeunes)) {
        $cleanJeunes = $default['jeunes'];
    }

    if (empty($cleanAdultes)) {
        $cleanAdultes = $default['adultes'];
    }

    return [
        'jeunes' => $cleanJeunes,
        'adultes' => $cleanAdultes,
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

function registrarc_normalize_settings($TourId, $settings) {
    $out = [
        'tarifs' => registrarc_default_tarifs(),
        'age_classes' => registrarc_default_age_classes(),
        'payment_modes' => registrarc_default_payment_modes(),
        'rules' => [],
    ];

    if (!is_array($settings)) {
        return $out;
    }

    if (isset($settings['tarifs']) && is_array($settings['tarifs'])) {
        $out['tarifs'] = $settings['tarifs'];
    }

    if (isset($settings['age_classes'])) {
        $out['age_classes'] = registrarc_normalize_age_classes($settings['age_classes']);
    }

    if (isset($settings['payment_modes']) && is_array($settings['payment_modes'])) {
        $out['payment_modes'] = registrarc_normalize_payment_modes($settings['payment_modes']);
    }

    if (isset($settings['rules']) && is_array($settings['rules'])) {
        $out['rules'] = $settings['rules'];
    }

    return $out;
}

function registrarc_load_settings($TourId) {
    $file = registrarc_settings_file_path($TourId);

    if (is_file($file)) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (is_array($data)) {
            return registrarc_normalize_settings($TourId, $data);
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

    $rulesJson = registrarc_get_module_parameter('TarifsRules_' . intval($TourId), '');
    if ($rulesJson) {
        $rulesData = json_decode($rulesJson, true);
        if (is_array($rulesData)) {
            $settings['rules'] = $rulesData;
        }
    }

    return registrarc_normalize_settings($TourId, $settings);
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

function registrarc_load_age_classes($TourId) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['age_classes'])) {
        return registrarc_normalize_age_classes($settings['age_classes']);
    }

    return registrarc_default_age_classes();
}

function registrarc_load_payment_modes($TourId) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['payment_modes']) && is_array($settings['payment_modes'])) {
        return registrarc_normalize_payment_modes($settings['payment_modes']);
    }

    return registrarc_default_payment_modes();
}

function registrarc_load_tarif_rules($TourId) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['rules']) && is_array($settings['rules'])) {
        return $settings['rules'];
    }

    return [];
}

// ---------------------------------------------------------------------------
// Tarifs / règles avancées
// ---------------------------------------------------------------------------
function registrarc_age_category($categorie, $ageClasses = null) {
    $categorie = (string)$categorie;

    if (!is_array($ageClasses) || !isset($ageClasses['jeunes'])) {
        $ageClasses = registrarc_default_age_classes();
    }

    foreach ($ageClasses['jeunes'] as $c) {
        $c = trim((string)$c);

        if ($c !== '' && stripos($categorie, $c) !== false) {
            return 'jeunes';
        }
    }

    return 'adultes';
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

function registrarc_rule_entry_value($scope, array $entry) {
    $map = [
        'qualif_ind'    => 'EnIndClEvent',
        'qualif_team'   => 'EnTeamClEvent',
        'finale_ind'    => 'EnIndFEvent',
        'finale_team'   => 'EnTeamFEvent',
        'double_mixte'  => 'EnTeamMixEvent',
        'finales_ind'   => 'EnIndFEvent',
        'finales_team'  => 'EnTeamFEvent',
        'finales_mix'   => 'EnTeamMixEvent',
    ];

    if ($scope === 'finales') {
        $values = [
            isset($entry['EnIndFEvent']) ? $entry['EnIndFEvent'] : '',
            isset($entry['EnTeamFEvent']) ? $entry['EnTeamFEvent'] : '',
            isset($entry['EnTeamMixEvent']) ? $entry['EnTeamMixEvent'] : '',
        ];

        foreach ($values as $v) {
            if (!registrarc_is_null_empty_or_zero($v)) {
                return 'OUI';
            }
        }

        return 'NON';
    }

    if (!isset($map[$scope])) {
        return null;
    }

    $field = $map[$scope];

    return isset($entry[$field]) ? $entry[$field] : '';
}

function registrarc_entry_rule_matches($scope, $matches, array $entry) {
    $scope = trim((string)$scope);

    if (!is_array($matches)) {
        $matches = [$matches];
    }

    if ($scope === 'categorie') {
        $categorie = strtoupper((string)$entry['categorie']);

        foreach ($matches as $match) {
            $match = strtoupper(trim((string)$match));

            if ($match !== '' && stripos($categorie, $match) !== false) {
                return true;
            }
        }

        return false;
    }

    if ($scope === 'region') {
        $clubCode = preg_replace('/\D+/', '', (string)$entry['country_code']);
        $region = strlen($clubCode) >= 2 ? substr($clubCode, 0, 2) : '';

        foreach ($matches as $match) {
            $match = preg_replace('/\D+/', '', (string)$match);

            if ($match !== '' && $region === $match) {
                return true;
            }
        }

        return false;
    }

    if ($scope === 'departement') {
        $clubCode = preg_replace('/\D+/', '', (string)$entry['country_code']);
        $departement = strlen($clubCode) >= 4 ? substr($clubCode, 2, 2) : '';

        foreach ($matches as $match) {
            $match = preg_replace('/\D+/', '', (string)$match);

            if ($match !== '' && $departement === $match) {
                return true;
            }
        }

        return false;
    }

    $value = registrarc_rule_entry_value($scope, $entry);

    if ($value === null) {
        return false;
    }

    $valueString = strtoupper(trim((string)$value));
    $hasValue = !registrarc_is_null_empty_or_zero($value);

    foreach ($matches as $match) {
        $match = strtoupper(trim((string)$match));

        if ($match === '') {
            continue;
        }

        if ($match === 'OUI' || $match === 'YES' || $match === '1') {
            if ($hasValue) {
                return true;
            }
        } elseif ($match === 'NON' || $match === 'NO' || $match === '0') {
            if (!$hasValue) {
                return true;
            }
        } elseif ($valueString === $match) {
            return true;
        }
    }

    return false;
}

function registrarc_apply_tarif_rules(array $entry, array $rules) {
    $total = 0;
    $labels = [];
    $matchedCount = 0;

    foreach ($rules as $rule) {
        if (empty($rule['active'])) {
            continue;
        }

        $scope = isset($rule['scope']) ? $rule['scope'] : '';
        $matches = isset($rule['match']) ? $rule['match'] : [];

        if (!registrarc_entry_rule_matches($scope, $matches, $entry)) {
            continue;
        }

        if (isset($rule['action']['type']) && $rule['action']['type'] === 'fixed_price') {
            $value = isset($rule['action']['value']) ? (float)$rule['action']['value'] : 0;

            $total += $value;
            $matchedCount++;

            if (!empty($rule['label'])) {
                $labels[] = trim((string)$rule['label']);
            }
        }
    }

    return [
        'matched' => $matchedCount > 0,
        'label' => implode(' + ', $labels),
        'amount' => $total,
        'count' => $matchedCount,
    ];
}

function registrarc_prix_engagement($clubCode, $categorie, $numeroEngagement, $tarifs, $organizerClubCode, $ageClasses = null) {
    $isOrganizer = ($clubCode == $organizerClubCode);
    $age = registrarc_age_category($categorie, $ageClasses);

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
                'EnIndClEvent' => $row->EnIndClEvent,
                'EnTeamClEvent' => $row->EnTeamClEvent,
                'EnIndFEvent' => $row->EnIndFEvent,
                'EnTeamFEvent' => $row->EnTeamFEvent,
                'EnTeamMixEvent' => $row->EnTeamMixEvent,
            ];
        }
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Enrichissement
// ---------------------------------------------------------------------------
function registrarc_enrich_invoice_rows(array $rows, array $tarifs, $organizerClubCode, array $payments, array $engagementNumbers, array $paymentModes, array $tarifRules, $ageClasses = null) {
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

        $payment = isset($payments[(string)$engagementId]) && is_array($payments[(string)$engagementId])
            ? $payments[(string)$engagementId]
            : [];

        $isPaid = isset($payment['status']) && $payment['status'] === 'PAYE';
        $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';

        $methodLabel = '—';

        if ($method !== '') {
            $methodLabel = isset($paymentModes[$method]) ? $paymentModes[$method] : $method;
        }

        $entryForRules = [
            'categorie' => $row['categorie'],
            'country_code' => $row['country_code'],
            'EnIndClEvent' => $row['EnIndClEvent'],
            'EnTeamClEvent' => $row['EnTeamClEvent'],
            'EnIndFEvent' => $row['EnIndFEvent'],
            'EnTeamFEvent' => $row['EnTeamFEvent'],
            'EnTeamMixEvent' => $row['EnTeamMixEvent'],
        ];

        $ruleResult = registrarc_apply_tarif_rules($entryForRules, $tarifRules);

        if (!empty($ruleResult['matched'])) {
            $amountBase = (float)$ruleResult['amount'];
            $ruleLabel = $ruleResult['label'];

            if (!empty($ruleResult['count']) && intval($ruleResult['count']) > 1) {
                $tarifTypeLabel = 'Cumul : ' . $ruleLabel;
            } else {
                $tarifTypeLabel = $ruleLabel !== '' ? $ruleLabel : 'Spécial';
            }

            $tarifTypeClass = 'tarif-rule';
        } else {
            $amountBase = registrarc_prix_engagement(
                $row['country_code'],
                $row['categorie'],
                $numeroEngagement,
                $tarifs,
                $organizerClubCode,
                $ageClasses
            );

            $ruleLabel = '';
            $tarifTypeLabel = 'Standard';
            $tarifTypeClass = 'tarif-standard';
        }

        $amount = registrarc_is_free_method($method) ? 0 : $amountBase;

        $row['numero_engagement'] = $numeroEngagement;
        $row['session_label'] = $sessionLabel;
        $row['target_label'] = $targetLabel;
        $row['payment_status_label'] = $isPaid ? 'Payé' : 'Non payé';
        $row['payment_method'] = $method;
        $row['payment_method_label'] = $methodLabel;
        $row['tarif_rule_label'] = $ruleLabel;
        $row['tarif_type_label'] = $tarifTypeLabel;
        $row['tarif_type_class'] = $tarifTypeClass;
        $row['amount'] = $amount;

        $result[] = $row;
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Destinataire groupé par club
// ---------------------------------------------------------------------------
function registrarc_build_invoice_customer_groups(array $rows) {
    $groups = [];

    foreach ($rows as $row) {
        $club = trim((string)$row['club']);

        if ($club === '') {
            $club = 'Sans club';
        }

        $archer = trim($row['nom'] . ' ' . $row['prenom']);

        if ($archer === '') {
            continue;
        }

        if (!isset($groups[$club])) {
            $groups[$club] = [];
        }

        $groups[$club][$archer] = true;
    }

    $out = [];

    foreach ($groups as $club => $archers) {
        $list = array_keys($archers);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        $out[] = [
            'club' => $club,
            'archers' => $list,
        ];
    }

    usort($out, function($a, $b) {
        return strcmp($a['club'], $b['club']);
    });

    return $out;
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
$ageClasses = registrarc_load_age_classes($TourId);
$tarifRules = registrarc_load_tarif_rules($TourId);
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
    $paymentModes,
    $tarifRules,
    $ageClasses
);

$total = 0;

foreach ($rows as $row) {
    $total += $row['amount'];
}

$invoiceNumber = registrarc_generate_invoice_number($TourId, $engagementIds);
$invoiceDate = date('d/m/Y');
$customerGroups = registrarc_build_invoice_customer_groups($rows);
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

        .customer-groups {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .customer-group {
            break-inside: avoid;
        }

        .customer-club {
            font-weight: 700;
            font-size: 11px;
            color: #111827;
            margin-bottom: 3px;
            padding-bottom: 2px;
            border-bottom: 1px solid #e5e7eb;
        }

        .customer-archers {
            column-width: 105px;
            column-gap: 14px;
            font-size: 11px;
            line-height: 1.35;
        }

        .customer-archer {
            break-inside: avoid;
            page-break-inside: avoid;
            margin-bottom: 2px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
            margin-top: 8px;
        }

        th,
        td {
            border: 1px solid #d1d5db;
            padding: 5px 6px;
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

        .tarif-standard {
            display: inline-flex;
            padding: 2px 6px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            font-size: 9px;
            font-weight: 700;
            white-space: normal;
        }

        .tarif-rule {
            display: inline-flex;
            padding: 2px 6px;
            border-radius: 999px;
            background: #ede9fe;
            color: #5b21b6;
            font-size: 9px;
            font-weight: 700;
            white-space: normal;
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
                padding: 5mm;
                box-shadow: none;
                border: 1px solid transparent;
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

            .customer-archers {
                column-width: auto;
                column-count: 1;
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
                <div class="brand-subtitle">Gestion des engagements - Registr'Arc</div>
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

                <?php if (!empty($customerGroups)): ?>
                    <div class="customer-groups">
                        <?php foreach ($customerGroups as $group): ?>
                            <div class="customer-group">
                                <div class="customer-club">
                                    <?php echo htmlspecialchars($group['club']); ?>
                                </div>

                                <div class="customer-archers">
                                    <?php foreach ($group['archers'] as $archer): ?>
                                        <div class="customer-archer">
                                            <?php echo htmlspecialchars($archer); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="box-content">—</div>
                <?php endif; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:7%;">Eng.</th>
                    <th style="width:11%;">Licence</th>
                    <th style="width:18%;">Archer</th>
                    <th style="width:9%;">Catégorie</th>
                    <th style="width:10%;">Départ</th>
                    <th style="width:10%;">Cible</th>
                    <th style="width:9%;" class="right">Montant</th>
                    <th style="width:12%;">Tarif</th>
                    <th style="width:7%;">Statut</th>
                    <th style="width:7%;">Paiement</th>
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
                        <td class="right"><?php echo registrarc_format_montant($row['amount']); ?> €</td>
                        <td>
                            <span class="<?php echo htmlspecialchars($row['tarif_type_class']); ?>">
                                <?php echo htmlspecialchars($row['tarif_type_label']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo ($row['payment_status_label'] === 'Payé') ? 'status-paid' : 'status-unpaid'; ?>">
                                <?php echo htmlspecialchars($row['payment_status_label']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['payment_method_label']); ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="total-row">
                    <td colspan="6" class="right">Total</td>
                    <td class="right"><?php echo registrarc_format_montant($total); ?> €</td>
                    <td colspan="3"></td>
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