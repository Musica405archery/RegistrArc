<?php
// ============================================================================
// RegistrArc - index.php
// Gestion des engagements / paiements
// Paiements stockés uniquement en JSON local.
// Rien n'est écrit dans Qualifications.QuNotes.
//
// Fichiers JSON :
// - Paiements  : Modules/Custom/RegistrArc/data/payments_<TourId>.json
// - Paramètres : Modules/Custom/RegistrArc/data/settings_<TourId>.json
//
// Tarification :
// - tarifs de base jeunes/adultes, club extérieur/organisateur
// - règles avancées JSON
// - règles possibles sur Entries :
//   EnIndClEvent, EnTeamClEvent, EnIndFEvent, EnTeamFEvent, EnTeamMixEvent
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

if (!$TourId) {
    die('Tournoi non défini');
}

// ---------------------------------------------------------------------------
// Reset filtres
// ---------------------------------------------------------------------------
if (isset($_GET['reset'])) {
    unset($_SESSION['RegistrArc_filters']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ---------------------------------------------------------------------------
// Filtres / tri
// ---------------------------------------------------------------------------
$filters = [
    'club'     => isset($_GET['club_filter'])     ? $_GET['club_filter']     : (isset($_SESSION['RegistrArc_filters']['club'])     ? $_SESSION['RegistrArc_filters']['club']     : 'all'),
    'category' => isset($_GET['category_filter']) ? $_GET['category_filter'] : (isset($_SESSION['RegistrArc_filters']['category']) ? $_SESSION['RegistrArc_filters']['category'] : 'all'),
    'payment'  => isset($_GET['payment_filter'])  ? $_GET['payment_filter']  : (isset($_SESSION['RegistrArc_filters']['payment'])  ? $_SESSION['RegistrArc_filters']['payment']  : 'all'),
    'session'  => isset($_GET['session_filter'])  ? $_GET['session_filter']  : (isset($_SESSION['RegistrArc_filters']['session'])  ? $_SESSION['RegistrArc_filters']['session']  : 'all'),
    'search'   => isset($_GET['search'])          ? trim($_GET['search'])    : (isset($_SESSION['RegistrArc_filters']['search'])   ? $_SESSION['RegistrArc_filters']['search']   : ''),
];

$_SESSION['RegistrArc_filters'] = $filters;

$sortColumn = isset($_GET['sort']) ? $_GET['sort'] : 'nom';
$sortDirection = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'desc' : 'asc';

// ---------------------------------------------------------------------------
// Paramètres module avec fallback ancien Greffe2
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
// Dossiers / fichiers JSON
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

function registrarc_data_dir_status() {
    $dir = registrarc_data_dir();

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_dir($dir)) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => "Le dossier de stockage JSON n'existe pas et n'a pas pu être créé automatiquement.",
        ];
    }

    if (!is_writable($dir)) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => "Le dossier de stockage JSON existe, mais il n'est pas accessible en écriture.",
        ];
    }

    $testFile = $dir . '/.registrarc_write_test';

    if (@file_put_contents($testFile, 'ok', LOCK_EX) === false) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => "Le dossier de stockage JSON semble accessible, mais l'écriture d'un fichier de test a échoué.",
        ];
    }

    @unlink($testFile);

    return [
        'ok' => true,
        'path' => $dir,
        'message' => '',
    ];
}

function registrarc_ensure_data_dir() {
    $status = registrarc_data_dir_status();
    return !empty($status['ok']);
}

// ---------------------------------------------------------------------------
// Code tournoi
// ---------------------------------------------------------------------------
function registrarc_current_tournament_code() {
    if (!empty($_SESSION['TourCodeSafe'])) {
        return trim((string)$_SESSION['TourCodeSafe']);
    }

    if (!empty($_SESSION['TourCode'])) {
        return trim((string)$_SESSION['TourCode']);
    }

    return '';
}

function registrarc_safe_filename_part($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $value);
    $value = trim($value, '_');

    return $value;
}

function registrarc_extract_tournament_code_from_export($data) {
    if (!is_array($data)) {
        return '';
    }

    if (!empty($data['tournament_code'])) {
        return trim((string)$data['tournament_code']);
    }

    if (!empty($data['tournament']) && is_array($data['tournament']) && !empty($data['tournament']['code'])) {
        return trim((string)$data['tournament']['code']);
    }

    return '';
}

// ---------------------------------------------------------------------------
// Dates export/import
// ---------------------------------------------------------------------------
function registrarc_timestamp_from_value($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    $ts = strtotime($value);

    return $ts ? $ts : 0;
}

function registrarc_export_timestamp(array $data, $fallbackFile = '') {
    $savedAt = isset($data['saved_at']) ? registrarc_timestamp_from_value($data['saved_at']) : 0;

    if ($savedAt > 0) {
        return $savedAt;
    }

    $exportedAt = isset($data['exported_at']) ? registrarc_timestamp_from_value($data['exported_at']) : 0;

    if ($exportedAt > 0) {
        return $exportedAt;
    }

    if ($fallbackFile !== '' && is_file($fallbackFile)) {
        $mtime = filemtime($fallbackFile);

        if ($mtime) {
            return $mtime;
        }
    }

    return 0;
}

// ---------------------------------------------------------------------------
// Paiements JSON
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

function registrarc_save_payments($TourId, array $data) {
    if (!registrarc_ensure_data_dir()) {
        return false;
    }

    $file = registrarc_payments_file_path($TourId);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return file_put_contents($file, $json, LOCK_EX) !== false;
}

function registrarc_set_payment_in_array(array &$data, $engagementId, $method) {
    $engagementId = intval($engagementId);

    if ($engagementId <= 0) {
        return false;
    }

    $method = strtoupper(trim((string)$method));

    $data[(string)$engagementId] = [
        'status'     => 'PAYE',
        'method'     => $method,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    return true;
}

function registrarc_unset_payment_in_array(array &$data, $engagementId) {
    $engagementId = intval($engagementId);

    if ($engagementId <= 0) {
        return false;
    }

    $data[(string)$engagementId] = [
        'status'     => 'NON_PAYE',
        'method'     => '',
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    return true;
}

function registrarc_set_payment($TourId, $engagementId, $method) {
    $data = registrarc_load_payments($TourId);
    registrarc_set_payment_in_array($data, $engagementId, $method);
    return registrarc_save_payments($TourId, $data);
}

function registrarc_unset_payment($TourId, $engagementId) {
    $data = registrarc_load_payments($TourId);
    registrarc_unset_payment_in_array($data, $engagementId);
    return registrarc_save_payments($TourId, $data);
}

function registrarc_set_payment_bulk($TourId, array $ids, $method) {
    $data = registrarc_load_payments($TourId);

    foreach ($ids as $id) {
        registrarc_set_payment_in_array($data, $id, $method);
    }

    return registrarc_save_payments($TourId, $data);
}

function registrarc_unset_payment_bulk($TourId, array $ids) {
    $data = registrarc_load_payments($TourId);

    foreach ($ids as $id) {
        registrarc_unset_payment_in_array($data, $id);
    }

    return registrarc_save_payments($TourId, $data);
}

// ---------------------------------------------------------------------------
// Paramètres JSON : tarifs / modes / règles
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

    $rulesJson = registrarc_get_module_parameter('TarifsRules_' . intval($TourId), '');
    if ($rulesJson) {
        $rulesData = json_decode($rulesJson, true);
        if (is_array($rulesData)) {
            $settings['rules'] = $rulesData;
        }
    }

    if (!isset($settings['rules']) || !is_array($settings['rules'])) {
        $settings['rules'] = [];
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

function registrarc_load_tarif_rules($TourId) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['rules']) && is_array($settings['rules'])) {
        return $settings['rules'];
    }

    return [];
}

// ---------------------------------------------------------------------------
// Tarifs / règles
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

function registrarc_is_free_method($method) {
    $method = registrarc_normalize_payment_code($method);
    return in_array($method, ['GRA', 'GRATUIT'], true);
}

function registrarc_clean_payment_method($method, $paymentModes) {
    $method = registrarc_normalize_payment_code($method);

    if ($method === '') {
        return 'ESP';
    }

    if (!isset($paymentModes[$method])) {
        return 'ESP';
    }

    return $method;
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
        $clubCode = strtoupper((string)$entry['country_code']);

        foreach ($matches as $match) {
            $match = strtoupper(trim((string)$match));

            if ($match === '') {
                continue;
            }

            $prefix = rtrim($match, '%');

            if ($prefix !== '' && strpos($clubCode, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    if ($scope === 'departement') {
        $clubCode = strtoupper((string)$entry['country_code']);

        foreach ($matches as $match) {
            $match = strtoupper(trim((string)$match));

            if ($match === '') {
                continue;
            }

            $prefix = rtrim($match, '%');

            if ($prefix !== '' && strpos($clubCode, $prefix) === 0) {
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
            return [
                'matched' => true,
                'label' => isset($rule['label']) ? $rule['label'] : '',
                'amount' => isset($rule['action']['value']) ? (float)$rule['action']['value'] : 0,
            ];
        }
    }

    return [
        'matched' => false,
        'label' => '',
        'amount' => null,
    ];
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

// ---------------------------------------------------------------------------
// Redirection filtres
// ---------------------------------------------------------------------------
function registrarc_redirect_params($filters, $sortColumn, $sortDirection) {
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

// ---------------------------------------------------------------------------
// Clé stable inscription
// ---------------------------------------------------------------------------
function registrarc_registration_key($licence, $categorie, $session, $target, $letter) {
    return strtoupper(trim((string)$licence))
        . '|'
        . strtoupper(trim((string)$categorie))
        . '|'
        . trim((string)$session)
        . '|'
        . trim((string)$target)
        . '|'
        . strtoupper(trim((string)$letter));
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
$tournamentCode = registrarc_current_tournament_code();

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

$tarifs = registrarc_load_tarifs($TourId, $organizerClubCode, $organizerClubName);
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifRules = registrarc_load_tarif_rules($TourId);
$dataDirStatus = registrarc_data_dir_status();

// ---------------------------------------------------------------------------
// POST : paiements + import inscriptions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectQuery = registrarc_redirect_params($filters, $sortColumn, $sortDirection);

    if (!$dataDirStatus['ok']) {
        $_SESSION['RegistrArc_payment_message'] = "Action impossible : le dossier JSON n'est pas accessible en écriture.";
        $_SESSION['RegistrArc_message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

    if (isset($_POST['import_registrations'])) {
        if (empty($_FILES['registrations_file']['tmp_name']) || !is_uploaded_file($_FILES['registrations_file']['tmp_name'])) {
            $_SESSION['RegistrArc_payment_message'] = 'Aucun fichier JSON sélectionné pour l’import inscriptions.';
            $_SESSION['RegistrArc_message_type'] = 'error';
            header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
            exit();
        }

        $json = file_get_contents($_FILES['registrations_file']['tmp_name']);
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['registrations']) || !is_array($data['registrations'])) {
            $_SESSION['RegistrArc_payment_message'] = 'Le fichier importé n’est pas un JSON inscriptions valide.';
            $_SESSION['RegistrArc_message_type'] = 'error';
            header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
            exit();
        }

        $importCode = registrarc_extract_tournament_code_from_export($data);
        $currentCode = $tournamentCode;

        $currentPaymentsFile = registrarc_payments_file_path($TourId);
        $currentPaymentsTs = is_file($currentPaymentsFile) ? filemtime($currentPaymentsFile) : 0;
        $importTs = registrarc_export_timestamp($data, $_FILES['registrations_file']['tmp_name']);

        $localMap = [];
        $localQuery = "
            SELECT
                e.EnId AS engagement_id,
                UPPER(TRIM(e.EnCode)) AS licence,
                CONCAT(e.EnDivision, e.EnClass) AS categorie,
                q.QuSession AS session_no,
                q.QuTarget AS target,
                q.QuLetter AS letter
            FROM Entries e
            LEFT JOIN Qualifications q
                ON e.EnId = q.QuId
            WHERE e.EnTournament = $TourId
              AND e.EnAthlete = 1
              AND TRIM(e.EnCode) <> ''
        ";
        $localRs = safe_r_sql($localQuery);

        if ($localRs) {
            while ($r = safe_fetch($localRs)) {
                $key = registrarc_registration_key(
                    $r->licence,
                    $r->categorie,
                    $r->session_no,
                    $r->target,
                    $r->letter
                );
                $localMap[$key] = intval($r->engagement_id);
            }
        }

        $payments = registrarc_load_payments($TourId);
        $matched = 0;
        $unmatched = 0;
        $paidImported = 0;
        $unpaidImported = 0;

        foreach ($data['registrations'] as $reg) {
            if (!is_array($reg)) {
                $unmatched++;
                continue;
            }

            $key = isset($reg['key']) ? trim((string)$reg['key']) : '';

            if ($key === '') {
                $key = registrarc_registration_key(
                    isset($reg['licence']) ? $reg['licence'] : '',
                    isset($reg['categorie']) ? $reg['categorie'] : '',
                    isset($reg['session']) ? $reg['session'] : '',
                    isset($reg['target']) ? $reg['target'] : '',
                    isset($reg['letter']) ? $reg['letter'] : ''
                );
            }

            if (!isset($localMap[$key])) {
                $unmatched++;
                continue;
            }

            $engagementId = $localMap[$key];
            $payment = isset($reg['payment']) && is_array($reg['payment']) ? $reg['payment'] : [];
            $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : 'NON_PAYE';
            $method = isset($payment['method']) ? registrarc_clean_payment_method($payment['method'], $paymentModes) : '';

            if ($status === 'PAYE') {
                registrarc_set_payment_in_array($payments, $engagementId, $method !== '' ? $method : 'ESP');
                $paidImported++;
            } else {
                registrarc_unset_payment_in_array($payments, $engagementId);
                $unpaidImported++;
            }

            $matched++;
        }

        if (registrarc_save_payments($TourId, $payments)) {
            $warnings = [];

            if ($importCode !== '' && $currentCode !== '' && strtoupper($importCode) !== strtoupper($currentCode)) {
                $warnings[] = "code tournoi différent : importé $importCode / courant $currentCode";
            }

            if ($importTs > 0 && $currentPaymentsTs > 0 && $importTs < $currentPaymentsTs) {
                $warnings[] = "fichier importé plus ancien que les paiements locaux";
            }

            $msg = "Import inscriptions terminé : $matched correspondance(s), $unmatched non trouvée(s), $paidImported payée(s), $unpaidImported non payée(s).";

            if (!empty($warnings)) {
                $msg .= " Attention : " . implode(' ; ', $warnings) . ".";
                $_SESSION['RegistrArc_message_type'] = 'warning';
            } else {
                $_SESSION['RegistrArc_message_type'] = 'success';
            }

            $_SESSION['RegistrArc_payment_message'] = $msg;
        } else {
            $_SESSION['RegistrArc_payment_message'] = "Import impossible : erreur lors de l'écriture du JSON paiements.";
            $_SESSION['RegistrArc_message_type'] = 'error';
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

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

        $bulkMethod = isset($_POST['bulk_payment_method'])
            ? registrarc_clean_payment_method($_POST['bulk_payment_method'], $paymentModes)
            : 'ESP';

        if (!empty($ids)) {
            if ($action === 'validate') {
                $saved = registrarc_set_payment_bulk($TourId, $ids, $bulkMethod);

                if ($saved) {
                    $_SESSION['RegistrArc_payment_message'] = 'Paiement validé pour les engagements sélectionnés.';
                    $_SESSION['RegistrArc_message_type'] = 'success';
                } else {
                    $_SESSION['RegistrArc_payment_message'] = "Erreur : impossible d'écrire le fichier JSON de paiements.";
                    $_SESSION['RegistrArc_message_type'] = 'error';
                }
            } else {
                $saved = registrarc_unset_payment_bulk($TourId, $ids);

                if ($saved) {
                    $_SESSION['RegistrArc_payment_message'] = 'Engagements sélectionnés marqués comme non payés.';
                    $_SESSION['RegistrArc_message_type'] = 'info';
                } else {
                    $_SESSION['RegistrArc_payment_message'] = "Erreur : impossible d'écrire le fichier JSON de paiements.";
                    $_SESSION['RegistrArc_message_type'] = 'error';
                }
            }
        } else {
            $_SESSION['RegistrArc_payment_message'] = 'Aucun engagement sélectionné.';
            $_SESSION['RegistrArc_message_type'] = 'error';
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

    if (isset($_POST['validate_payment']) && isset($_POST['engagement_id'])) {
        $engagementId = intval($_POST['engagement_id']);
        $action = $_POST['validate_payment'];

        $method = isset($_POST['payment_method'])
            ? registrarc_clean_payment_method($_POST['payment_method'], $paymentModes)
            : 'ESP';

        if ($engagementId > 0) {
            if ($action === 'validate') {
                $saved = registrarc_set_payment($TourId, $engagementId, $method);

                if ($saved) {
                    $_SESSION['RegistrArc_payment_message'] = 'Paiement de cet engagement validé.';
                    $_SESSION['RegistrArc_message_type'] = 'success';
                } else {
                    $_SESSION['RegistrArc_payment_message'] = "Erreur : impossible d'écrire le fichier JSON de paiements.";
                    $_SESSION['RegistrArc_message_type'] = 'error';
                }
            } else {
                $saved = registrarc_unset_payment($TourId, $engagementId);

                if ($saved) {
                    $_SESSION['RegistrArc_payment_message'] = 'Cet engagement est maintenant marqué comme non payé.';
                    $_SESSION['RegistrArc_message_type'] = 'info';
                } else {
                    $_SESSION['RegistrArc_payment_message'] = "Erreur : impossible d'écrire le fichier JSON de paiements.";
                    $_SESSION['RegistrArc_message_type'] = 'error';
                }
            }
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }
}

// ---------------------------------------------------------------------------
// Requête engagements
// ---------------------------------------------------------------------------
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
        TRIM(CONCAT(COALESCE(q.QuTarget, ''), COALESCE(q.QuLetter, ''))) AS target_no,
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
    ORDER BY UPPER(TRIM(e.EnCode)), q.QuSession, q.QuTarget, q.QuLetter, e.EnId
";

$Rs = safe_r_sql($query);
$paymentsData = registrarc_load_payments($TourId);
$engagementsData = [];

if ($Rs) {
    while ($row = safe_fetch($Rs)) {
        $engagementId = intval($row->engagement_id);

        $payment = isset($paymentsData[(string)$engagementId]) && is_array($paymentsData[(string)$engagementId])
            ? $paymentsData[(string)$engagementId]
            : [];

        $paymentStatus = isset($payment['status']) && $payment['status'] === 'PAYE' ? 1 : 0;
        $paymentMethod = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';

        $session = trim((string)$row->session_no);
        $target = $row->target;
        $letter = $row->letter;

        $targetTrim = trim((string)$target);
        $letterTrim = trim((string)$letter);
        $targetNo = trim($targetTrim . $letterTrim);

        $targetIsUnassigned = registrarc_is_null_empty_or_zero($target) || registrarc_is_null_empty_or_zero($letter);

        if ($targetIsUnassigned) {
            $targetLabel = 'Archer non affecté';
            $targetNo = '';
        } else {
            $targetLabel = ($session !== '') ? 'D' . $session . ' - ' . $targetNo : $targetNo;
        }

        $key = registrarc_registration_key(
            $row->licence,
            $row->categorie,
            $session,
            $target,
            $letter
        );

        $engagementsData[] = [
            'engagement_id'        => $engagementId,
            'key'                  => $key,
            'licence'              => strtoupper(trim((string)$row->licence)),
            'nom'                  => strtoupper(trim((string)$row->nom)),
            'prenom'               => trim((string)$row->prenom),
            'categorie'            => $row->categorie,
            'club'                 => $row->club,
            'country_code'         => $row->country_code,
            'session'              => $session,
            'target'               => $targetTrim,
            'letter'               => $letterTrim,
            'target_no'            => $targetNo,
            'target_label'         => $targetLabel,
            'target_is_unassigned' => $targetIsUnassigned,
            'payment_status'       => $paymentStatus,
            'payment_method'       => $paymentMethod,
            'EnIndClEvent'         => $row->EnIndClEvent,
            'EnTeamClEvent'        => $row->EnTeamClEvent,
            'EnIndFEvent'          => $row->EnIndFEvent,
            'EnTeamFEvent'         => $row->EnTeamFEvent,
            'EnTeamMixEvent'       => $row->EnTeamMixEvent,
        ];
    }
}

// ---------------------------------------------------------------------------
// Numérotation + montant
// ---------------------------------------------------------------------------
$counterByLicence = [];

foreach ($engagementsData as $idx => $e) {
    $licence = $e['licence'];

    if (!isset($counterByLicence[$licence])) {
        $counterByLicence[$licence] = 0;
    }

    $counterByLicence[$licence]++;
    $numero = $counterByLicence[$licence];

    $ruleResult = registrarc_apply_tarif_rules($e, $tarifRules);

    if (!empty($ruleResult['matched'])) {
        $montantBase = (float)$ruleResult['amount'];
        $ruleLabel = $ruleResult['label'];
    } else {
        $montantBase = registrarc_prix_engagement(
            $e['country_code'],
            $e['categorie'],
            $numero,
            $tarifs,
            $organizerClubCode
        );
        $ruleLabel = '';
    }

    $mode = strtoupper((string)$e['payment_method']);
    $montant = registrarc_is_free_method($mode) ? 0 : $montantBase;

    $engagementsData[$idx]['numero_engagement'] = $numero;
    $engagementsData[$idx]['montant_base'] = $montantBase;
    $engagementsData[$idx]['montant'] = $montant;
    $engagementsData[$idx]['tarif_rule_label'] = $ruleLabel;
}

// ---------------------------------------------------------------------------
// Listes filtres
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
// Filtrage affiché
// ---------------------------------------------------------------------------
$displayEngagements = [];
$searchFilter = mb_strtolower($filters['search']);

foreach ($engagementsData as $e) {
    if ($filters['club'] !== 'all' && $filters['club'] !== '' && stripos((string)$e['club'], $filters['club']) === false) {
        continue;
    }

    if ($filters['category'] !== 'all' && $filters['category'] !== '' && stripos((string)$e['categorie'], $filters['category']) === false) {
        continue;
    }

    $isPaid = ($e['payment_status'] == 1);

    if ($filters['payment'] === 'paid' && !$isPaid) {
        continue;
    }

    if ($filters['payment'] === 'unpaid' && $isPaid) {
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

    $displayEngagements[] = $e;
}

// ---------------------------------------------------------------------------
// Tri
// ---------------------------------------------------------------------------
usort($displayEngagements, function($a, $b) use ($sortColumn, $sortDirection) {
    $dir = ($sortDirection === 'desc') ? -1 : 1;

    switch ($sortColumn) {
        case 'engagement':
            return $dir * ($a['numero_engagement'] <=> $b['numero_engagement']);

        case 'licence':
            return $dir * strcmp($a['licence'], $b['licence']);

        case 'nom':
            return $dir * strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom']));

        case 'prenom':
            return $dir * strcmp(mb_strtolower($a['prenom']), mb_strtolower($b['prenom']));

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
            return $dir * strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom']));
    }
});

// ---------------------------------------------------------------------------
// Export inscriptions affichées
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_registrations') {
    $exportRows = [];

    foreach ($displayEngagements as $e) {
        $exportRows[] = [
            'key' => $e['key'],
            'licence' => $e['licence'],
            'nom' => $e['nom'],
            'prenom' => $e['prenom'],
            'categorie' => $e['categorie'],
            'club' => $e['club'],
            'country_code' => $e['country_code'],
            'session' => $e['session'],
            'target' => $e['target'],
            'letter' => $e['letter'],
            'entries_flags' => [
                'EnIndClEvent' => $e['EnIndClEvent'],
                'EnTeamClEvent' => $e['EnTeamClEvent'],
                'EnIndFEvent' => $e['EnIndFEvent'],
                'EnTeamFEvent' => $e['EnTeamFEvent'],
                'EnTeamMixEvent' => $e['EnTeamMixEvent'],
            ],
            'payment' => [
                'status' => $e['payment_status'] ? 'PAYE' : 'NON_PAYE',
                'method' => $e['payment_status'] ? $e['payment_method'] : '',
            ],
        ];
    }

    $payload = [
        'module' => 'RegistrArc',
        'type' => 'registrations_export',
        'version' => 1,
        'tour_id' => $TourId,
        'tournament_code' => $tournamentCode,
        'exported_at' => date('Y-m-d H:i:s'),
        'filters' => $filters,
        'registrations_count' => count($exportRows),
        'registrations' => $exportRows,
    ];

    $codePart = registrarc_safe_filename_part($tournamentCode);
    $filename = 'RegistrArc_inscriptions_' . ($codePart !== '' ? $codePart . '_' : '') . intval($TourId) . '_' . date('Ymd_His') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ---------------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------------
$PAGE_TITLE = "RegistrArc - Paiements des engagements";
$IncludeJquery = true;
include('Common/Templates/head.php');
?>

<style>
    :root {
        --primary-color: #2563eb;
        --primary-dark: #1d4ed8;
        --success-color: #16a34a;
        --danger-color: #dc2626;
        --invoice-color: #475569;
        --invoice-dark: #334155;
        --print-color: #0f766e;
        --print-dark: #115e59;
        --warning-color: #d97706;
        --export-color: #7c3aed;
        --export-dark: #6d28d9;
        --import-color: #be185d;
        --import-dark: #9d174d;
        --settings-color: #0891b2;
        --settings-dark: #0e7490;
        --border-radius: 6px;
        --shadow-soft: 0 4px 12px rgba(15, 23, 42, 0.08);
        --text-muted: #6b7280;
        --text-strong: #111827;
        --chip-bg: #e5e7eb;
    }

    body {
        background: #f9fafb;
    }

    .registrarc-container {
        width: fit-content;
        max-width: 100%;
        margin: 0 auto;
        padding: 16px;
        box-sizing: border-box;
    }

    .registrarc-header,
    .filters-row,
    .filter-actions,
    .payment-actions,
    .payment-actions form {
        display: flex;
        flex-wrap: nowrap;
        gap: 8px;
        align-items: center;
    }

    .registrarc-header {
        justify-content: space-between;
        margin-bottom: 16px;
        width: 100%;
    }

    .registrarc-header-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-strong);
    }

    .registrarc-header-sub {
        font-size: 13px;
        color: var(--text-muted);
    }

    .btn-primary,
    .btn-success,
    .btn-danger,
    .btn-ghost,
    .btn-invoice,
    .btn-print-list,
    .btn-warning,
    .btn-export,
    .btn-import,
    .btn-settings {
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
        line-height: 1.2;
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

    .btn-invoice {
        background: var(--invoice-color);
        color: #fff;
    }

    .btn-invoice:hover {
        background: var(--invoice-dark);
    }

    .btn-print-list {
        background: var(--print-color);
        color: #fff;
    }

    .btn-print-list:hover {
        background: var(--print-dark);
    }

    .btn-warning {
        background: var(--warning-color);
        color: #fff;
    }

    .btn-export {
        background: var(--export-color);
        color: #fff;
    }

    .btn-export:hover {
        background: var(--export-dark);
    }

    .btn-import {
        background: var(--import-color);
        color: #fff;
    }

    .btn-import:hover {
        background: var(--import-dark);
    }

    .btn-settings {
        background: var(--settings-color);
        color: #fff;
    }

    .btn-settings:hover {
        background: var(--settings-dark);
    }

    .bulk-invoice-button {
        display: none;
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

    .filters-row {
        flex-wrap: wrap;
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
    .filter-group input[type="text"],
    .payment-actions select,
    .import-input {
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

    .payment-actions select {
        min-width: max-content;
        padding: 3px 6px;
        font-size: 11px;
        background: #fff;
    }

    .actions-spacer {
        flex: 1 1 auto;
        min-width: 20px;
    }

    .alert {
        padding: 10px 12px;
        border-radius: var(--border-radius);
        font-size: 13px;
        margin-bottom: 10px;
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

    .alert-warning {
        background-color: #fef3c7;
        border: 1px solid #fde68a;
        color: #92400e;
    }

    .storage-path {
        display: inline-block;
        margin-top: 4px;
        font-family: monospace;
        font-size: 12px;
        background: rgba(255,255,255,0.55);
        padding: 2px 6px;
        border-radius: 4px;
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
        position: relative;
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

    tbody tr:hover .col-check {
        background-color: #f9fafb;
    }

    .col-check {
        position: sticky;
        left: 0;
        z-index: 5;
        width: 42px;
        min-width: 42px;
        max-width: 42px;
        text-align: center;
        background: #fff;
        box-shadow: 2px 0 4px rgba(15, 23, 42, 0.06);
    }

    thead .col-check {
        z-index: 10;
        background: #f9fafb;
    }

    tfoot .col-check {
        background: #fff;
    }

    .status-paid {
        color: var(--success-color);
        font-weight: 600;
        font-size: 12px;
    }

    .status-unpaid,
    .target-unassigned {
        color: var(--danger-color);
        font-weight: 700;
        font-size: 12px;
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

    .chip-rule {
        background: #ede9fe;
        color: #5b21b6;
        font-weight: 700;
    }

    .payment-actions {
        width: max-content;
        max-width: none;
        white-space: nowrap;
    }

    .payment-actions form {
        margin: 0;
        width: max-content;
        max-width: none;
        white-space: nowrap;
    }

    .payment-method-label {
        display: inline-flex;
        align-items: center;
        padding: 3px 7px;
        border-radius: 999px;
        background: #dcfce7;
        color: #166534;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
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
        .registrarc-container {
            width: 100%;
            padding: 12px;
        }

        .registrarc-header,
        .filters-row,
        .filter-actions {
            flex-wrap: wrap;
        }

        .filter-group,
        .filter-group select,
        .filter-group input[type="text"],
        .import-input {
            width: 100%;
            max-width: 100%;
        }

        .actions-spacer {
            display: none;
        }

        .table-wrapper {
            overflow-x: auto;
        }
    }
</style>

<div class="registrarc-container">
    <div class="registrarc-header">
        <div>
            <div class="registrarc-header-title">Gestion des engagements - Registr’Arc</div>
            <div class="registrarc-header-sub">Le module de Greffe pour I@nseo</div>
        </div>

        <div>
            <a href="<?php echo $CFG->ROOT_DIR; ?>Modules/Custom/RegistrArc/config_tarifs.php?ToId=<?php echo $TourId; ?>" class="btn-settings">
                ⚙ Paramétrer tarifs & paiements
            </a>
        </div>
    </div>

    <?php if (!$dataDirStatus['ok']): ?>
        <div class="alert alert-warning">
            <strong>Attention :</strong>
            <?php echo htmlspecialchars($dataDirStatus['message']); ?><br>
            Les actions JSON ne pourront pas être enregistrées tant que ce problème n’est pas corrigé.<br>
            Dossier concerné :
            <span class="storage-path"><?php echo htmlspecialchars($dataDirStatus['path']); ?></span><br>
            Commande Ubuntu possible :
            <span class="storage-path">mkdir -p Modules/Custom/RegistrArc/data && chmod 775 Modules/Custom/RegistrArc/data</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['RegistrArc_payment_message'])): ?>
        <?php
        $type = isset($_SESSION['RegistrArc_message_type']) ? $_SESSION['RegistrArc_message_type'] : 'success';
        $msg = $_SESSION['RegistrArc_payment_message'];
        unset($_SESSION['RegistrArc_payment_message'], $_SESSION['RegistrArc_message_type']);
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
                    <label for="payment_filter">Statut paiement</label>
                    <select name="payment_filter" id="payment_filter">
                        <option value="all" <?php echo ($filters['payment'] === 'all') ? 'selected' : ''; ?>>Tous</option>
                        <option value="paid" <?php echo ($filters['payment'] === 'paid') ? 'selected' : ''; ?>>Payés</option>
                        <option value="unpaid" <?php echo ($filters['payment'] === 'unpaid') ? 'selected' : ''; ?>>Non payés</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="search">Recherche</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Licence, nom, prénom, club...">
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
                        Valider paiement
                    </button>

                    <button type="submit" name="bulk_action" value="unvalidate" class="btn-danger" onclick="return confirmBulkAction('unvalidate');">
                        Retirer paiement
                    </button>

                    <button type="button" id="bulk_invoice_btn" class="btn-invoice bulk-invoice-button" onclick="return factureGroupePrompt();">
                        Facture groupée
                    </button>
                </div>

                <div class="actions-spacer"></div>

                <div class="filter-actions">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['action' => 'export_registrations'])); ?>" class="btn-export">
                        Exporter inscriptions
                    </a>

                    <button type="button" class="btn-import" onclick="toggleImportRegistrations();">
                        Importer inscriptions
                    </button>

                    <button type="button" class="btn-print-list" onclick="return printGreffeList();">
                        Liste Greffe
                    </button>
                </div>
            </div>
        </form>

        <form method="POST" enctype="multipart/form-data" id="importRegistrationsForm" style="display:none;margin-top:10px;">
            <div class="filters-row">
                <div class="filter-group">
                    <label for="registrations_file">Fichier JSON inscriptions</label>
                    <input type="file" name="registrations_file" id="registrations_file" accept="application/json,.json" class="import-input">
                </div>

                <div class="filter-actions">
                    <button type="submit" name="import_registrations" value="1" class="btn-import" onclick="return confirmImportRegistrations();">
                        Confirmer l’import
                    </button>

                    <button type="button" class="btn-ghost" onclick="toggleImportRegistrations();">
                        Annuler
                    </button>
                </div>
            </div>
        </form>
    </div>

    <?php
    $total = 0;
    $displayCount = count($displayEngagements);

    foreach ($displayEngagements as $e) {
        $total += $e['montant'];
    }
    ?>

    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th class="col-check">
                            <input type="checkbox" onclick="toggleSelectAll(this)" title="Tout sélectionner">
                        </th>

                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'engagement', 'dir' => ($sortColumn == 'engagement' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Engagement</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'licence', 'dir' => ($sortColumn == 'licence' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Licence</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'nom', 'dir' => ($sortColumn == 'nom' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Nom</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'prenom', 'dir' => ($sortColumn == 'prenom' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Prénom</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'club', 'dir' => ($sortColumn == 'club' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Club</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'categorie', 'dir' => ($sortColumn == 'categorie' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Catégorie</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'depart', 'dir' => ($sortColumn == 'depart' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Départ</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'cible', 'dir' => ($sortColumn == 'cible' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Cible</a></th>
                        <th style="text-align:right;"><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'montant', 'dir' => ($sortColumn == 'montant' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Montant</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'statut', 'dir' => ($sortColumn == 'statut' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Statut</a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'paiement', 'dir' => ($sortColumn == 'paiement' && $sortDirection == 'asc' ? 'desc' : 'asc')])); ?>">Paiement / Actions</a></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($displayEngagements as $e): ?>
                        <?php
                        $isPaid = ($e['payment_status'] == 1);
                        $archerLabel = trim($e['nom'] . ' ' . $e['prenom']);
                        ?>
                        <tr>
                            <td class="col-check">
                                <input
                                    type="checkbox"
                                    name="engagements[]"
                                    value="<?php echo intval($e['engagement_id']); ?>"
                                    class="engagement-checkbox"
                                    form="bulkForm"
                                    onchange="updateBulkInvoiceButton();"
                                >
                            </td>

                            <td><span class="chip chip-engagement">n° <?php echo intval($e['numero_engagement']); ?></span></td>
                            <td><?php echo htmlspecialchars($e['licence']); ?></td>
                            <td><?php echo htmlspecialchars($e['nom']); ?></td>
                            <td><?php echo htmlspecialchars($e['prenom']); ?></td>
                            <td><?php echo htmlspecialchars(trim($e['country_code'] . ' - ' . $e['club'])); ?></td>
                            <td>
                                <?php echo htmlspecialchars($e['categorie']); ?>
                                <?php if (!empty($e['tarif_rule_label'])): ?>
                                    <span class="chip chip-rule" title="Règle tarifaire appliquée">
                                        <?php echo htmlspecialchars($e['tarif_rule_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ($e['session'] !== '') ? 'Départ ' . htmlspecialchars($e['session']) : '—'; ?></td>

                            <td>
                                <?php if (!empty($e['target_is_unassigned'])): ?>
                                    <span class="target-unassigned"><?php echo htmlspecialchars($e['target_label']); ?></span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['target_label']); ?>
                                <?php endif; ?>
                            </td>

                            <td style="text-align:right;"><?php echo registrarc_format_montant($e['montant']); ?> €</td>

                            <td>
                                <span class="<?php echo $isPaid ? 'status-paid' : 'status-unpaid'; ?>">
                                    <?php echo $isPaid ? 'Payé' : 'Non payé'; ?>
                                </span>
                            </td>

                            <td>
                                <div class="payment-actions">
                                    <?php if ($isPaid): ?>
                                        <span class="payment-method-label"><?php echo htmlspecialchars($e['payment_method'] ?: '—'); ?></span>

                                        <form method="POST">
                                            <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                                            <input type="hidden" name="validate_payment" value="unvalidate">

                                            <button
                                                type="submit"
                                                class="btn-ghost"
                                                style="padding:3px 8px;font-size:11px;border-radius:999px;border-color:#fecaca;color:#b91c1c;"
                                                onclick="return confirm('Annuler le paiement de <?php echo htmlspecialchars($archerLabel, ENT_QUOTES); ?> ?');"
                                            >
                                                Annuler
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST">
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
                                                onclick="return confirm('Valider le paiement de <?php echo htmlspecialchars($archerLabel, ENT_QUOTES); ?> ?');"
                                            >
                                                Valider
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        class="btn-invoice"
                                        style="padding:3px 8px;font-size:11px;border-radius:999px;"
                                        onclick="return factureEngagementPrompt('<?php echo intval($e['engagement_id']); ?>');"
                                    >
                                        Facture
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($displayCount === 0): ?>
                        <tr>
                            <td class="col-check"></td>
                            <td colspan="11" style="text-align:center;padding:20px;">Aucun engagement trouvé.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($displayCount > 0): ?>
                    <tfoot>
                        <tr>
                            <td class="col-check"></td>
                            <td colspan="8" style="text-align:right;font-weight:600;">Total affiché :</td>
                            <td style="text-align:right;font-weight:600;"><?php echo registrarc_format_montant($total); ?> €</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="summary-footer">
        <div>
            Engagements affichés :
            <strong><?php echo intval($displayCount); ?></strong>
        </div>

        <div>
            Montant total affiché :
            <strong><?php echo registrarc_format_montant($total); ?> €</strong>
        </div>
    </div>
</div>

<script>
function getSelectedEngagementIds() {
    const selected = document.querySelectorAll('.engagement-checkbox:checked');
    const uniqueValues = new Set();

    selected.forEach(cb => {
        if (cb.value !== '') {
            uniqueValues.add(cb.value);
        }
    });

    return Array.from(uniqueValues);
}

function updateBulkInvoiceButton() {
    const btn = document.getElementById('bulk_invoice_btn');

    if (!btn) {
        return;
    }

    const selectedIds = getSelectedEngagementIds();

    if (selectedIds.length >= 2) {
        btn.style.display = 'inline-flex';
        btn.textContent = 'Facture groupée (' + selectedIds.length + ')';
    } else {
        btn.style.display = 'none';
        btn.textContent = 'Facture groupée';
    }
}

function toggleSelectAll(master) {
    const checked = master.checked;

    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.checked = checked;
    });

    updateBulkInvoiceButton();
}

function confirmBulkAction(type) {
    const selectedIds = getSelectedEngagementIds();
    const count = selectedIds.length;

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

function factureEngagementPrompt(engagementId) {
    if (!engagementId) {
        alert('Aucun engagement sélectionné pour la facture.');
        return false;
    }

    window.open('invoice.php?ids=' + encodeURIComponent(engagementId), '_blank');
    return false;
}

function factureGroupePrompt() {
    const selectedIds = getSelectedEngagementIds();

    if (selectedIds.length < 2) {
        alert('Sélectionne au moins 2 engagements pour générer une facture groupée.');
        updateBulkInvoiceButton();
        return false;
    }

    window.open('invoice.php?ids=' + encodeURIComponent(selectedIds.join(',')), '_blank');
    return false;
}

function toggleImportRegistrations() {
    const form = document.getElementById('importRegistrationsForm');

    if (!form) {
        return false;
    }

    form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    return false;
}

function confirmImportRegistrations() {
    const input = document.getElementById('registrations_file');

    if (!input || !input.files || input.files.length === 0) {
        alert('Sélectionne un fichier JSON inscriptions.');
        return false;
    }

    return confirm(
        'Importer ce fichier d’inscriptions ?\n\n' +
        'Les états de paiement correspondants seront appliqués aux inscriptions locales trouvées.\n' +
        'Aucune inscription I@nseo ne sera créée ou supprimée.'
    );
}

function printGreffeList() {
    const table = document.querySelector('.table-wrapper table');

    if (!table) {
        alert('Aucune liste à imprimer.');
        return false;
    }

    const clonedTable = table.cloneNode(true);

    clonedTable.querySelectorAll('tfoot').forEach(tfoot => {
        tfoot.remove();
    });

    clonedTable.querySelectorAll('tr').forEach(row => {
        const cells = row.querySelectorAll('th, td');

        if (cells.length > 0) {
            cells[0].remove();
        }

        const updatedCells = row.querySelectorAll('th, td');

        if (updatedCells.length > 0) {
            updatedCells[updatedCells.length - 1].remove();
        }
    });

    clonedTable.querySelectorAll('a').forEach(link => {
        const span = document.createElement('span');
        span.textContent = link.textContent.trim();
        link.replaceWith(span);
    });

    clonedTable.querySelectorAll('button, input, select, form').forEach(el => {
        el.remove();
    });

    const printWindow = window.open('', '_blank', 'width=1200,height=900');

    if (!printWindow) {
        alert('La fenêtre d’impression a été bloquée par le navigateur.');
        return false;
    }

    const title = 'Liste Greffe - Registr’Arc';

    printWindow.document.open();
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <title>${title}</title>
            <style>
                @page {
                    size: landscape;
                    margin: 6mm;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    padding: 0;
                    font-family: Arial, Helvetica, sans-serif;
                    color: #000;
                    background: #fff;
                }

                .print-title {
                    font-size: 14px;
                    font-weight: 700;
                    margin-bottom: 2px;
                }

                .print-subtitle {
                    font-size: 9px;
                    margin-bottom: 6px;
                    color: #333;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    table-layout: fixed;
                    font-size: 8px;
                }

                thead {
                    display: table-header-group;
                }

                th,
                td {
                    border: 1px solid #999;
                    border-bottom: 1.5px solid #555;
                    padding: 2px 3px;
                    text-align: left;
                    vertical-align: top;
                    line-height: 1.15;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                tbody tr {
                    border-bottom: 2px solid #333;
                }

                tbody tr:nth-child(even) {
                    background: #f7f7f7;
                }

                th {
                    background: #e5e5e5;
                    font-weight: 700;
                    border-bottom: 2px solid #333;
                }

                th:nth-child(1),
                td:nth-child(1) {
                    width: 3.5%;
                    min-width: 22px;
                    max-width: 28px;
                    text-align: center;
                    white-space: nowrap;
                    word-break: normal;
                    overflow-wrap: normal;
                    padding-left: 1px;
                    padding-right: 1px;
                }

                th:nth-child(2),
                td:nth-child(2) {
                    width: 9%;
                }

                th:nth-child(3),
                td:nth-child(3) {
                    width: 12%;
                }

                th:nth-child(4),
                td:nth-child(4) {
                    width: 11%;
                }

                th:nth-child(5),
                td:nth-child(5) {
                    width: 20%;
                }

                th:nth-child(6),
                td:nth-child(6) {
                    width: 8%;
                }

                th:nth-child(7),
                td:nth-child(7) {
                    width: 8%;
                }

                th:nth-child(8),
                td:nth-child(8) {
                    width: 12%;
                }

                th:nth-child(9),
                td:nth-child(9) {
                    width: 7%;
                    text-align: right;
                }

                th:nth-child(10),
                td:nth-child(10) {
                    width: 9.5%;
                }

                .status-paid,
                .status-unpaid,
                .target-unassigned {
                    color: #000;
                    font-weight: 700;
                }

                .chip,
                .chip-engagement,
                .payment-method-label {
                    display: inline;
                    padding: 0;
                    background: transparent;
                    color: #000;
                    font-size: inherit;
                    font-weight: inherit;
                }

                .print-footer {
                    margin-top: 4px;
                    font-size: 8px;
                    color: #333;
                }
            </style>
        </head>
        <body>
            <div class="print-title">Gestion des engagements - Registr’Arc</div>
            <div class="print-subtitle">Liste Greffe</div>

            ${clonedTable.outerHTML}

            <div class="print-footer">
                Document généré depuis Registr’Arc.
            </div>

            <script>
                window.onload = function() {
                    window.focus();
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);

    printWindow.document.close();

    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkInvoiceButton);
    });

    updateBulkInvoiceButton();
});
</script>

<?php include('Common/Templates/tail.php'); ?>