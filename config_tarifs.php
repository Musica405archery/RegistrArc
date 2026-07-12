<?php
// ============================================================================
// RegistrArc - config_tarifs.php
// Configuration des tarifs, moyens de paiement et règles avancées
//
// Stockage principal en JSON local :
// Modules/Custom/RegistrArc/data/settings_<TourId>.json
//
// Export / Import JSON :
// - inclut le code tournoi pour faciliter l’échange entre installations
// - alerte si le fichier importé semble plus ancien
// - alerte si le code tournoi importé diffère du code tournoi courant
//
// Règles avancées :
// - Catégorie
// - Région : 2 premiers chiffres du code club
// - Département : 3e et 4e chiffres du code club
// - Qualification individuelle
// - Qualification équipe
// - Finale individuelle si Events contient EvTeamEvent = 0 pour le tournoi
// - Finale équipe si Events contient EvTeamEvent = 1 pour le tournoi
// - Double mixte si Events contient EvTeamEvent = 1 et EvMixedTeam = 1
//
// La structure des règles reste : scope / match / action fixed_price,
// comme dans la logique initiale des règles avancées du module [1].
// ============================================================================

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_GET['ToId']) ? intval($_GET['ToId']) : (isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0);

if (!$TourId) {
    die('Tournoi non défini');
}

// ---------------------------------------------------------------------------
// Dossier / fichier JSON
// ---------------------------------------------------------------------------
function registrarc_data_dir() {
    return dirname(__FILE__) . '/data';
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

    $testFile = $dir . '/.registrarc_settings_write_test';

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
// Paramètres module avec compatibilité ancien Greffe2
// ---------------------------------------------------------------------------
function registrarc_get_module_parameter($name, $default = '') {
    global $ModuleName, $LegacyModuleName;

    $value = getModuleParameter($ModuleName, $name, '');

    if ($value !== '' && $value !== null) {
        return $value;
    }

    return getModuleParameter($LegacyModuleName, $name, $default);
}

function registrarc_set_module_parameter($name, $value) {
    global $ModuleName;

    setModuleParameter($ModuleName, $name, $value);
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
    return trim($value, '_');
}

function registrarc_extract_tournament_code_from_settings($settings) {
    if (!is_array($settings)) {
        return '';
    }

    if (!empty($settings['tournament_code'])) {
        return trim((string)$settings['tournament_code']);
    }

    if (!empty($settings['tournament']) && is_array($settings['tournament']) && !empty($settings['tournament']['code'])) {
        return trim((string)$settings['tournament']['code']);
    }

    return '';
}

// ---------------------------------------------------------------------------
// Détection des phases finales via Events
// ---------------------------------------------------------------------------
function registrarc_get_events_final_presence($TourId) {
    $TourId = intval($TourId);

    $presence = [
        'individual' => false,
        'team' => false,
        'mixed' => false,
    ];

    $query = "
        SELECT
            SUM(CASE WHEN EvTeamEvent = 0 THEN 1 ELSE 0 END) AS NbIndividual,
            SUM(CASE WHEN EvTeamEvent = 1 THEN 1 ELSE 0 END) AS NbTeam,
            SUM(CASE WHEN EvTeamEvent = 1 AND IFNULL(EvMixedTeam, 0) = 1 THEN 1 ELSE 0 END) AS NbMixed
        FROM Events
        WHERE EvTournament = $TourId
    ";

    $rs = safe_r_sql($query);

    if ($row = safe_fetch($rs)) {
        $presence['individual'] = intval($row->NbIndividual) > 0;
        $presence['team'] = intval($row->NbTeam) > 0;
        $presence['mixed'] = intval($row->NbMixed) > 0;
    }

    return $presence;
}

function registrarc_allowed_rule_scopes(array $finalPresence) {
    $scopes = [
        'categorie'   => 'Catégorie',
        'region'      => 'Région',
        'departement' => 'Département',
        'qualif_ind'  => 'Qualification individuelle',
        'qualif_team' => 'Qualification équipe',
    ];

    if (!empty($finalPresence['individual'])) {
        $scopes['finale_ind'] = 'Finale individuelle';
    }

    if (!empty($finalPresence['team'])) {
        $scopes['finale_team'] = 'Finale équipe';
    }

    if (!empty($finalPresence['mixed'])) {
        $scopes['double_mixte'] = 'Double mixte';
    }

    return $scopes;
}

function registrarc_default_match_for_scope($scope) {
    $scope = trim((string)$scope);

    if ($scope === 'categorie') {
        return 'CL';
    }

    if ($scope === 'region') {
        return '34';
    }

    if ($scope === 'departement') {
        return '34';
    }

    return 'OUI';
}

$finalPresence = registrarc_get_events_final_presence($TourId);
$allowedRuleScopes = registrarc_allowed_rule_scopes($finalPresence);

// ---------------------------------------------------------------------------
// Valeurs par défaut
// ---------------------------------------------------------------------------
function registrarc_default_tarifs() {
    return [
        'clubs_autres' => [
            'jeunes' => [1 => 8, 2 => 14],
            'adultes' => [1 => 10, 2 => 18],
        ],
        'organizer' => [
            'jeunes' => [1 => 4, 2 => 8],
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

function registrarc_default_settings($TourId) {
    return [
        'module' => 'RegistrArc',
        'version' => 1,
        'tour_id' => intval($TourId),
        'tournament_code' => registrarc_current_tournament_code(),
        'exported_at' => null,
        'saved_at' => null,
        'tarifs' => registrarc_default_tarifs(),
        'payment_modes' => registrarc_default_payment_modes(),
        'rules' => [],
    ];
}

// ---------------------------------------------------------------------------
// Codes paiement
// ---------------------------------------------------------------------------
function registrarc_normalize_payment_code($code) {
    $code = strtoupper(trim((string)$code));

    $map = [
        'ESPECE' => 'ESP',
        'ESPECES' => 'ESP',
        'ESPÈCE' => 'ESP',
        'ESPÈCES' => 'ESP',
        'CHEQUE' => 'CHQ',
        'CHÈQUE' => 'CHQ',
        'VIREMENT' => 'VIR',
        'GRATUIT' => 'GRA',
    ];

    return isset($map[$code]) ? $map[$code] : $code;
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------
function registrarc_timestamp_from_value($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    $ts = strtotime($value);
    return $ts ? $ts : 0;
}

function registrarc_settings_timestamp(array $settings, $fallbackFile = '') {
    $savedAt = isset($settings['saved_at']) ? registrarc_timestamp_from_value($settings['saved_at']) : 0;

    if ($savedAt > 0) {
        return $savedAt;
    }

    $exportedAt = isset($settings['exported_at']) ? registrarc_timestamp_from_value($settings['exported_at']) : 0;

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

function registrarc_format_timestamp_for_message($timestamp) {
    $timestamp = intval($timestamp);

    if ($timestamp <= 0) {
        return 'date inconnue';
    }

    return date('d/m/Y H:i:s', $timestamp);
}

// ---------------------------------------------------------------------------
// Normalisation
// ---------------------------------------------------------------------------
function registrarc_normalize_tarifs($tarifs) {
    $default = registrarc_default_tarifs();

    if (!is_array($tarifs)) {
        return $default;
    }

    $out = $default;

    foreach (['clubs_autres', 'organizer'] as $group) {
        foreach (['jeunes', 'adultes'] as $age) {
            foreach ([1, 2] as $idx) {
                if (isset($tarifs[$group][$age][$idx])) {
                    $out[$group][$age][$idx] = (int)$tarifs[$group][$age][$idx];
                } elseif (isset($tarifs[$group][$age][(string)$idx])) {
                    $out[$group][$age][$idx] = (int)$tarifs[$group][$age][(string)$idx];
                }
            }
        }
    }

    return $out;
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

function registrarc_legacy_scope_to_current($scope, array $allowedRuleScopes) {
    $scope = trim((string)$scope);

    $map = [
        'finales_ind' => 'finale_ind',
        'finales_team' => 'finale_team',
        'finales_mix' => 'double_mixte',
    ];

    if (isset($map[$scope])) {
        $scope = $map[$scope];
    }

    if ($scope === 'finales') {
        if (isset($allowedRuleScopes['finale_ind'])) {
            return 'finale_ind';
        }

        if (isset($allowedRuleScopes['finale_team'])) {
            return 'finale_team';
        }

        return '';
    }

    return $scope;
}

function registrarc_normalize_rules($rules, array $allowedRuleScopes) {
    if (!is_array($rules)) {
        return [];
    }

    $clean = [];

    foreach ($rules as $idx => $r) {
        if (!is_array($r)) {
            continue;
        }

        $label = isset($r['label']) ? trim((string)$r['label']) : '';
        $scope = isset($r['scope']) ? registrarc_legacy_scope_to_current($r['scope'], $allowedRuleScopes) : '';
        $match = isset($r['match']) && is_array($r['match']) ? $r['match'] : [];
        $active = !empty($r['active']);
        $value = isset($r['action']['value']) ? (float)$r['action']['value'] : 0;

        $match = array_values(array_filter(array_map('trim', $match)));

        if ($label === '' || $scope === '') {
            continue;
        }

        if (!isset($allowedRuleScopes[$scope])) {
            continue;
        }

        if (empty($match)) {
            $match = [registrarc_default_match_for_scope($scope)];
        }

        $clean[] = [
            'id' => isset($r['id']) && trim((string)$r['id']) !== '' ? trim((string)$r['id']) : 'R' . ($idx + 1),
            'label' => $label,
            'active' => $active,
            'scope' => $scope,
            'match' => $match,
            'action' => [
                'type' => 'fixed_price',
                'value' => $value,
            ],
        ];
    }

    return $clean;
}

function registrarc_normalize_settings($TourId, $settings, array $allowedRuleScopes) {
    $default = registrarc_default_settings($TourId);

    if (!is_array($settings)) {
        return $default;
    }

    $out = $default;

    $out['module'] = isset($settings['module']) ? trim((string)$settings['module']) : 'RegistrArc';
    $out['version'] = isset($settings['version']) ? (int)$settings['version'] : 1;
    $out['tour_id'] = intval($TourId);
    $out['tournament_code'] = registrarc_extract_tournament_code_from_settings($settings);
    $out['exported_at'] = isset($settings['exported_at']) ? $settings['exported_at'] : null;
    $out['saved_at'] = isset($settings['saved_at']) ? $settings['saved_at'] : null;

    if ($out['tournament_code'] === '') {
        $out['tournament_code'] = registrarc_current_tournament_code();
    }

    $out['tarifs'] = registrarc_normalize_tarifs(isset($settings['tarifs']) ? $settings['tarifs'] : null);
    $out['payment_modes'] = registrarc_normalize_payment_modes(isset($settings['payment_modes']) ? $settings['payment_modes'] : null);
    $out['rules'] = registrarc_normalize_rules(isset($settings['rules']) ? $settings['rules'] : [], $allowedRuleScopes);

    return $out;
}

// ---------------------------------------------------------------------------
// Chargement / sauvegarde
// ---------------------------------------------------------------------------
function registrarc_load_settings($TourId, array $allowedRuleScopes) {
    $file = registrarc_settings_file_path($TourId);

    if (is_file($file)) {
        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (is_array($data)) {
            return registrarc_normalize_settings($TourId, $data, $allowedRuleScopes);
        }
    }

    return registrarc_default_settings($TourId);
}

function registrarc_save_settings($TourId, array $settings, array $allowedRuleScopes) {
    if (!registrarc_ensure_data_dir()) {
        return false;
    }

    $settings = registrarc_normalize_settings($TourId, $settings, $allowedRuleScopes);
    $settings['saved_at'] = date('Y-m-d H:i:s');

    $file = registrarc_settings_file_path($TourId);
    $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return file_put_contents($file, $json, LOCK_EX) !== false;
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

$dataDirStatus = registrarc_data_dir_status();

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $settings = registrarc_load_settings($TourId, $allowedRuleScopes);
    $settings['exported_at'] = date('Y-m-d H:i:s');
    $settings['tournament_code'] = $tournamentCode;
    $settings['tournament'] = [
        'id' => $TourId,
        'code' => $tournamentCode,
        'name' => $tournamentName,
        'organizer_code' => $organizerClubCode,
        'organizer_name' => $organizerClubName,
    ];

    $codePart = registrarc_safe_filename_part($tournamentCode);
    $filename = 'RegistrArc_settings_' . ($codePart !== '' ? $codePart . '_' : '') . intval($TourId) . '_' . date('Ymd_His') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['import_settings'])) {
        if (!$dataDirStatus['ok']) {
            $message = "Import impossible : le dossier JSON n'est pas accessible en écriture.";
            $messageType = 'error';
        } elseif (empty($_FILES['settings_file']['tmp_name']) || !is_uploaded_file($_FILES['settings_file']['tmp_name'])) {
            $message = "Aucun fichier JSON sélectionné.";
            $messageType = 'error';
        } else {
            $json = file_get_contents($_FILES['settings_file']['tmp_name']);
            $data = json_decode($json, true);

            if (!is_array($data)) {
                $message = "Le fichier importé n'est pas un JSON valide.";
                $messageType = 'error';
            } else {
                $currentSettings = registrarc_load_settings($TourId, $allowedRuleScopes);
                $currentTimestamp = registrarc_settings_timestamp($currentSettings, registrarc_settings_file_path($TourId));
                $settings = registrarc_normalize_settings($TourId, $data, $allowedRuleScopes);
                $importTimestamp = registrarc_settings_timestamp($settings, $_FILES['settings_file']['tmp_name']);
                $importCode = registrarc_extract_tournament_code_from_settings($data);
                $currentCode = $tournamentCode;

                $warnings = [];

                if ($importTimestamp > 0 && $currentTimestamp > 0 && $importTimestamp < $currentTimestamp) {
                    $warnings[] = "le JSON importé semble plus ancien";
                }

                if ($importCode !== '' && $currentCode !== '' && strtoupper($importCode) !== strtoupper($currentCode)) {
                    $warnings[] = "le code tournoi importé ($importCode) est différent du code courant ($currentCode)";
                }

                if (registrarc_save_settings($TourId, $settings, $allowedRuleScopes)) {
                    if (!empty($warnings)) {
                        $message = "Paramétrage importé avec succès, mais attention : " . implode(' ; ', $warnings) . ".";
                        $messageType = 'warning';
                    } else {
                        $message = "Paramétrage importé avec succès.";
                        $messageType = 'success';
                    }
                } else {
                    $message = "Erreur lors de l'écriture du fichier JSON.";
                    $messageType = 'error';
                }
            }
        }
    }

    if (isset($_POST['save_settings'])) {
        if (!$dataDirStatus['ok']) {
            $message = "Enregistrement impossible : le dossier JSON n'est pas accessible en écriture.";
            $messageType = 'error';
        } else {
            $OJA1 = isset($_POST['OJA1']) ? (int)$_POST['OJA1'] : 0;
            $OJA2 = isset($_POST['OJA2']) ? (int)$_POST['OJA2'] : 0;
            $OAA1 = isset($_POST['OAA1']) ? (int)$_POST['OAA1'] : 0;
            $OAA2 = isset($_POST['OAA2']) ? (int)$_POST['OAA2'] : 0;

            $PJA1 = isset($_POST['PJA1']) ? (int)$_POST['PJA1'] : 0;
            $PJA2 = isset($_POST['PJA2']) ? (int)$_POST['PJA2'] : 0;
            $PAA1 = isset($_POST['PAA1']) ? (int)$_POST['PAA1'] : 0;
            $PAA2 = isset($_POST['PAA2']) ? (int)$_POST['PAA2'] : 0;

            $tarifs = [
                'clubs_autres' => [
                    'jeunes' => [1 => $OJA1, 2 => $OJA1 + $OJA2],
                    'adultes' => [1 => $OAA1, 2 => $OAA1 + $OAA2],
                ],
                'organizer' => [
                    'jeunes' => [1 => $PJA1, 2 => $PJA1 + $PJA2],
                    'adultes' => [1 => $PAA1, 2 => $PAA1 + $PAA2],
                ],
            ];

            $modesCodes = isset($_POST['pm_code']) && is_array($_POST['pm_code']) ? $_POST['pm_code'] : [];
            $modesLabels = isset($_POST['pm_label']) && is_array($_POST['pm_label']) ? $_POST['pm_label'] : [];

            $modes = [];

            foreach ($modesCodes as $idx => $code) {
                $code = registrarc_normalize_payment_code($code);
                $label = isset($modesLabels[$idx]) ? trim((string)$modesLabels[$idx]) : '';

                if ($code === '') {
                    continue;
                }

                $modes[$code] = $label !== '' ? $label : $code;
            }

            $rules = [];

            if (!empty($_POST['rule_label']) && is_array($_POST['rule_label'])) {
                $labels = $_POST['rule_label'];
                $actives = isset($_POST['rule_active']) ? $_POST['rule_active'] : [];
                $scopes = isset($_POST['rule_scope']) ? $_POST['rule_scope'] : [];
                $matches = isset($_POST['rule_match']) ? $_POST['rule_match'] : [];
                $values = isset($_POST['rule_value']) ? $_POST['rule_value'] : [];

                foreach ($labels as $idx => $label) {
                    $label = trim((string)$label);

                    if ($label === '') {
                        continue;
                    }

                    $scope = isset($scopes[$idx]) ? trim((string)$scopes[$idx]) : '';
                    $matchStr = isset($matches[$idx]) ? trim((string)$matches[$idx]) : '';
                    $matchArr = array_values(array_filter(array_map('trim', explode(',', $matchStr))));
                    $value = isset($values[$idx]) ? (float)$values[$idx] : 0;

                    if ($scope === '') {
                        continue;
                    }

                    if (!isset($allowedRuleScopes[$scope])) {
                        continue;
                    }

                    if (empty($matchArr)) {
                        $matchArr = [registrarc_default_match_for_scope($scope)];
                    }

                    $rules[] = [
                        'id' => 'R' . ($idx + 1),
                        'label' => $label,
                        'active' => !empty($actives[$idx]),
                        'scope' => $scope,
                        'match' => $matchArr,
                        'action' => [
                            'type' => 'fixed_price',
                            'value' => $value,
                        ],
                    ];
                }
            }

            $settings = [
                'module' => 'RegistrArc',
                'version' => 1,
                'tour_id' => $TourId,
                'tournament_code' => $tournamentCode,
                'exported_at' => null,
                'tarifs' => $tarifs,
                'payment_modes' => $modes,
                'rules' => $rules,
            ];

            $settings = registrarc_normalize_settings($TourId, $settings, $allowedRuleScopes);

            if (registrarc_save_settings($TourId, $settings, $allowedRuleScopes)) {
                $message = "Paramètres enregistrés dans le JSON.";
                $messageType = 'success';
            } else {
                $message = "Erreur lors de l'écriture du fichier JSON.";
                $messageType = 'error';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Valeurs actuelles
// ---------------------------------------------------------------------------
$currentSettings = registrarc_load_settings($TourId, $allowedRuleScopes);
$currentTarifs = $currentSettings['tarifs'];
$currentModes = $currentSettings['payment_modes'];
$currentRules = $currentSettings['rules'];

$currentSettingsTimestamp = registrarc_settings_timestamp($currentSettings, registrarc_settings_file_path($TourId));
$currentSettingsTimestampLabel = registrarc_format_timestamp_for_message($currentSettingsTimestamp);

$OJA1 = (int)$currentTarifs['clubs_autres']['jeunes'][1];
$OJA2 = (int)$currentTarifs['clubs_autres']['jeunes'][2] - $OJA1;
$OAA1 = (int)$currentTarifs['clubs_autres']['adultes'][1];
$OAA2 = (int)$currentTarifs['clubs_autres']['adultes'][2] - $OAA1;

$PJA1 = (int)$currentTarifs['organizer']['jeunes'][1];
$PJA2 = (int)$currentTarifs['organizer']['jeunes'][2] - $PJA1;
$PAA1 = (int)$currentTarifs['organizer']['adultes'][1];
$PAA2 = (int)$currentTarifs['organizer']['adultes'][2] - $PAA1;

// ---------------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------------
$PAGE_TITLE = "RegistrArc - Paramètres";
$IncludeJquery = true;
include('Common/Templates/head.php');
?>

<style>
    :root {
        --primary-color: #2563eb;
        --primary-dark: #1d4ed8;
        --success-color: #16a34a;
        --warning-color: #d97706;
        --danger-color: #dc2626;
        --border-radius: 6px;
        --shadow-soft: 0 4px 12px rgba(15, 23, 42, 0.08);
        --text-muted: #6b7280;
        --text-strong: #111827;
    }

    body {
        background: #f9fafb;
    }

    .registrarc-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 16px;
        box-sizing: border-box;
    }

    .registrarc-header {
        margin-bottom: 16px;
    }

    .registrarc-header-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-strong);
    }

    .registrarc-header-sub {
        font-size: 13px;
        color: var(--text-muted);
        margin-top: 4px;
    }

    .card {
        background: #fff;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-soft);
        padding: 12px 14px;
        margin-bottom: 12px;
        box-sizing: border-box;
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

    .grid-2,
    .import-export-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .btn-primary,
    .btn-success,
    .btn-ghost,
    .btn-warning {
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

    .btn-warning {
        background: var(--warning-color);
        color: #fff;
    }

    .btn-ghost {
        background: transparent;
        color: var(--text-muted);
        border: 1px solid #d1d5db;
    }

    .alert {
        padding: 10px 12px;
        border-radius: var(--border-radius);
        font-size: 13px;
        margin-bottom: 10px;
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

    .alert-warning {
        background-color: #fef3c7;
        border: 1px solid #fde68a;
        color: #92400e;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    th,
    td {
        padding: 6px 8px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
    }

    thead {
        background: #f9fafb;
    }

    th {
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 600;
    }

    input[type="text"],
    input[type="number"],
    select,
    .import-input {
        width: 100%;
        box-sizing: border-box;
        padding: 5px 7px;
        border-radius: 999px;
        border: 1px solid #d1d5db;
        font-size: 12px;
        background: #f9fafb;
    }

    .tarif-input {
        max-width: 80px;
        text-align: right;
    }

    .delete-btn {
        border: none;
        background: none;
        color: var(--danger-color);
        cursor: pointer;
        font-size: 16px;
    }

    .form-footer {
        margin-top: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }

    .back-link {
        font-size: 13px;
        color: var(--primary-color);
        text-decoration: none;
    }

    .chip {
        display: inline-flex;
        align-items: center;
        padding: 3px 8px;
        border-radius: 999px;
        background: #e5e7eb;
        font-size: 11px;
        color: #374151;
    }

    @media (max-width: 768px) {
        .grid-2,
        .import-export-row {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="registrarc-container">
    <div class="registrarc-header">
        <div class="registrarc-header-title">Gestion des engagements - Registr’Arc</div>
        <div class="registrarc-header-sub">Le module de Greffe pour I@nseo</div>
    </div>

    <?php if (!$dataDirStatus['ok']): ?>
        <div class="alert alert-warning">
            <strong>Attention :</strong>
            <?php echo htmlspecialchars($dataDirStatus['message']); ?>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">Export / Import du paramétrage</div>
        <div class="card-help">
            Les tarifs, moyens de paiement et règles avancées sont enregistrés dans un fichier JSON local.
        </div>

        <div class="import-export-row">
            <div>
                <div style="font-weight:600;margin-bottom:6px;">Exporter</div>
                <a href="?ToId=<?php echo intval($TourId); ?>&action=export" class="btn-primary">
                    Exporter le JSON
                </a>
            </div>

            <div>
                <div style="font-weight:600;margin-bottom:6px;">Importer</div>
                <form
                    method="POST"
                    enctype="multipart/form-data"
                    id="importSettingsForm"
                    data-current-ts="<?php echo intval($currentSettingsTimestamp); ?>"
                    data-current-label="<?php echo htmlspecialchars($currentSettingsTimestampLabel); ?>"
                    data-current-code="<?php echo htmlspecialchars($tournamentCode); ?>"
                    style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;"
                >
                    <input type="file" name="settings_file" id="settings_file" accept="application/json,.json" class="import-input" style="max-width:320px;">
                    <button type="submit" name="import_settings" value="1" class="btn-warning">
                        Importer
                    </button>
                </form>
            </div>
        </div>
    </div>

    <form method="POST">
        <div class="card">
            <div class="card-title">Tarifs d'inscription</div>
            <div class="card-help">
                Montants en euros. Le deuxième départ est calculé comme :
                <span class="chip">1er départ + supplément</span>.
            </div>

            <div class="grid-2">
                <div>
                    <strong>Clubs extérieurs</strong>
                    <table>
                        <thead>
                            <tr>
                                <th>Catégorie</th>
                                <th>1er départ</th>
                                <th>Supplément</th>
                                <th>Total 2 départs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Jeunes</td>
                                <td><input type="number" name="OJA1" value="<?php echo $OJA1; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><input type="number" name="OJA2" value="<?php echo $OJA2; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><?php echo $OJA1 + $OJA2; ?> €</td>
                            </tr>
                            <tr>
                                <td>Adultes</td>
                                <td><input type="number" name="OAA1" value="<?php echo $OAA1; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><input type="number" name="OAA2" value="<?php echo $OAA2; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><?php echo $OAA1 + $OAA2; ?> €</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div>
                    <strong>Club organisateur</strong>
                    <table>
                        <thead>
                            <tr>
                                <th>Catégorie</th>
                                <th>1er départ</th>
                                <th>Supplément</th>
                                <th>Total 2 départs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Jeunes</td>
                                <td><input type="number" name="PJA1" value="<?php echo $PJA1; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><input type="number" name="PJA2" value="<?php echo $PJA2; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><?php echo $PJA1 + $PJA2; ?> €</td>
                            </tr>
                            <tr>
                                <td>Adultes</td>
                                <td><input type="number" name="PAA1" value="<?php echo $PAA1; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><input type="number" name="PAA2" value="<?php echo $PAA2; ?>" min="0" step="1" class="tarif-input"></td>
                                <td><?php echo $PAA1 + $PAA2; ?> €</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Règles avancées de tarification</div>
            <div class="card-help">
                Valeurs possibles pour les portées liées aux engagements : <strong>OUI</strong> ou <strong>NON</strong>.
                <?php if (empty($finalPresence['individual']) && empty($finalPresence['team']) && empty($finalPresence['mixed'])): ?>
                    <br><strong>Aucune phase finale détectée :</strong> les règles liées aux finales ne sont pas proposées.
                <?php elseif (empty($finalPresence['individual'])): ?>
                    <br><strong>Pas de finale individuelle détectée :</strong> la règle finale individuelle n’est pas proposée.
                <?php elseif (empty($finalPresence['team'])): ?>
                    <br><strong>Pas de finale équipe détectée :</strong> la règle finale équipe n’est pas proposée.
                <?php endif; ?>
                <?php if (empty($finalPresence['mixed'])): ?>
                    <br><strong>Pas de double mixte détecté :</strong> la règle double mixte n’est pas proposée.
                <?php endif; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:50px;">Actif</th>
                        <th>Nom</th>
                        <th style="width:210px;">Portée</th>
                        <th>Valeur</th>
                        <th style="width:100px;">Prix fixe</th>
                        <th style="width:40px;">Suppr.</th>
                    </tr>
                </thead>

                <tbody id="rulesTableBody">
                    <?php
                    $rulesRows = $currentRules;
                    $rulesRows[] = [
                        'label' => '',
                        'active' => false,
                        'scope' => 'categorie',
                        'match' => [registrarc_default_match_for_scope('categorie')],
                        'action' => ['type' => 'fixed_price', 'value' => 0],
                    ];

                    foreach ($rulesRows as $idx => $r):
                        $label = isset($r['label']) ? $r['label'] : '';
                        $active = !empty($r['active']);
                        $scope = isset($r['scope']) ? $r['scope'] : 'categorie';
                        $match = isset($r['match']) && is_array($r['match']) ? implode(',', $r['match']) : registrarc_default_match_for_scope($scope);
                        $value = isset($r['action']['value']) ? $r['action']['value'] : 0;

                        if (!isset($allowedRuleScopes[$scope])) {
                            $scope = 'categorie';
                            $match = registrarc_default_match_for_scope($scope);
                        }

                        if (trim((string)$match) === '') {
                            $match = registrarc_default_match_for_scope($scope);
                        }
                    ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" name="rule_active[<?php echo $idx; ?>]" value="1" <?php echo $active ? 'checked' : ''; ?>>
                            </td>

                            <td>
                                <input type="text" name="rule_label[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($label); ?>" placeholder="ex : Tarif finale">
                            </td>

                            <td>
                                <select name="rule_scope[<?php echo $idx; ?>]" onchange="updateRuleDefaultValue(this)">
                                    <?php foreach ($allowedRuleScopes as $scopeCode => $scopeLabel): ?>
                                        <option
                                            value="<?php echo htmlspecialchars($scopeCode); ?>"
                                            data-default="<?php echo htmlspecialchars(registrarc_default_match_for_scope($scopeCode)); ?>"
                                            <?php echo ($scope === $scopeCode ? 'selected' : ''); ?>
                                        >
                                            <?php echo htmlspecialchars($scopeLabel); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <input
                                    type="text"
                                    name="rule_match[<?php echo $idx; ?>]"
                                    value="<?php echo htmlspecialchars($match); ?>"
                                    data-auto-value="1"
                                >
                            </td>

                            <td>
                                <input type="number" name="rule_value[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($value); ?>" step="0.01">
                            </td>

                            <td style="text-align:center;">
                                <button type="button" class="delete-btn" onclick="deleteRuleRow(this)">🗑</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="form-footer">
                <button type="button" class="btn-ghost" onclick="addRuleRow()">+ Ajouter une règle</button>
            </div>
        </div>

        <div class="card">
            <div class="card-title">Modes de paiement</div>
            <div class="card-help">
                Codes recommandés :
                <span class="chip">ESP = Espèces</span>
                <span class="chip">CHQ = Chèque</span>
                <span class="chip">VIR = Virement</span>
                <span class="chip">GRA = Gratuit</span>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:25%;">Code</th>
                        <th>Libellé</th>
                        <th style="width:40px;">Suppr.</th>
                    </tr>
                </thead>

                <tbody id="pmTableBody">
                    <?php
                    $rows = $currentModes;
                    $rows[''] = '';

                    foreach ($rows as $code => $label):
                        $code = trim((string)$code);
                        $label = trim((string)$label);
                    ?>
                        <tr>
                            <td><input type="text" name="pm_code[]" value="<?php echo htmlspecialchars($code); ?>" placeholder="ESP"></td>
                            <td><input type="text" name="pm_label[]" value="<?php echo htmlspecialchars($label); ?>" placeholder="Espèces"></td>
                            <td style="text-align:center;">
                                <button type="button" class="delete-btn" onclick="deletePaymentModeRow(this)">🗑</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="form-footer">
                <button type="button" class="btn-ghost" onclick="addPaymentModeRow()">+ Ajouter un mode</button>
            </div>
        </div>

        <div class="form-footer">
            <button type="submit" name="save_settings" value="1" class="btn-success">
                Enregistrer les paramètres
            </button>

            <a href="index.php" class="back-link">← Retour à la liste des engagements</a>
        </div>
    </form>
</div>

<script>
const registrarcAllowedRuleScopes = <?php echo json_encode($allowedRuleScopes, JSON_UNESCAPED_UNICODE); ?>;
const registrarcDefaultRuleMatches = {};

Object.keys(registrarcAllowedRuleScopes).forEach(function(scopeCode) {
    if (scopeCode === 'categorie') {
        registrarcDefaultRuleMatches[scopeCode] = 'CL';
    } else if (scopeCode === 'region') {
        registrarcDefaultRuleMatches[scopeCode] = '34';
    } else if (scopeCode === 'departement') {
        registrarcDefaultRuleMatches[scopeCode] = '34';
    } else {
        registrarcDefaultRuleMatches[scopeCode] = 'OUI';
    }
});

function buildRuleScopeOptionsHtml(selectedValue) {
    let html = '';

    Object.keys(registrarcAllowedRuleScopes).forEach(function(scopeCode) {
        const selected = scopeCode === selectedValue ? ' selected' : '';
        html += '<option value="' + scopeCode + '" data-default="' + registrarcDefaultRuleMatches[scopeCode] + '"' + selected + '>' + registrarcAllowedRuleScopes[scopeCode] + '</option>';
    });

    return html;
}

function updateRuleDefaultValue(selectEl) {
    const row = selectEl.closest('tr');

    if (!row) {
        return;
    }

    const input = row.querySelector('input[name^="rule_match"]');

    if (!input) {
        return;
    }

    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const newDefault = selectedOption ? selectedOption.getAttribute('data-default') : '';

    const currentValue = input.value.trim();
    const knownDefaults = Object.values(registrarcDefaultRuleMatches);

    if (currentValue === '' || knownDefaults.includes(currentValue)) {
        input.value = newDefault || '';
        input.dataset.autoValue = '1';
    }
}

function parseRegistrArcDate(value) {
    if (!value || typeof value !== 'string') {
        return 0;
    }

    const normalized = value.trim().replace(' ', 'T');
    const timestamp = Date.parse(normalized);

    if (Number.isNaN(timestamp)) {
        return 0;
    }

    return Math.floor(timestamp / 1000);
}

function formatTimestampForAlert(timestamp) {
    timestamp = parseInt(timestamp, 10);

    if (!timestamp || timestamp <= 0) {
        return 'date inconnue';
    }

    const d = new Date(timestamp * 1000);
    return d.toLocaleString('fr-FR');
}

function getImportedJsonTimestamp(data, file) {
    if (data && typeof data === 'object') {
        if (data.saved_at) {
            const savedTs = parseRegistrArcDate(String(data.saved_at));

            if (savedTs > 0) {
                return {
                    timestamp: savedTs,
                    source: 'saved_at'
                };
            }
        }

        if (data.exported_at) {
            const exportedTs = parseRegistrArcDate(String(data.exported_at));

            if (exportedTs > 0) {
                return {
                    timestamp: exportedTs,
                    source: 'exported_at'
                };
            }
        }
    }

    if (file && file.lastModified) {
        return {
            timestamp: Math.floor(file.lastModified / 1000),
            source: 'date du fichier'
        };
    }

    return {
        timestamp: 0,
        source: 'aucune balise de date'
    };
}

function getImportedTournamentCode(data) {
    if (!data || typeof data !== 'object') {
        return '';
    }

    if (data.tournament_code) {
        return String(data.tournament_code).trim();
    }

    if (data.tournament && typeof data.tournament === 'object' && data.tournament.code) {
        return String(data.tournament.code).trim();
    }

    return '';
}

function handleImportSubmit(event) {
    const form = event.currentTarget;

    if (form.dataset.confirmed === '1') {
        return true;
    }

    const fileInput = document.getElementById('settings_file');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        alert('Aucun fichier JSON sélectionné.');
        event.preventDefault();
        return false;
    }

    event.preventDefault();

    const file = fileInput.files[0];
    const currentTs = parseInt(form.dataset.currentTs || '0', 10);
    const currentLabel = form.dataset.currentLabel || formatTimestampForAlert(currentTs);
    const currentCode = String(form.dataset.currentCode || '').trim();

    const reader = new FileReader();

    reader.onload = function(e) {
        let data = null;

        try {
            data = JSON.parse(e.target.result);
        } catch (err) {
            alert('Le fichier sélectionné n’est pas un JSON valide.');
            return;
        }

        const imported = getImportedJsonTimestamp(data, file);
        const importedTs = imported.timestamp;
        const importedLabel = formatTimestampForAlert(importedTs);
        const importedCode = getImportedTournamentCode(data);

        let warnings = [];

        if (importedTs <= 0) {
            warnings.push(
                'Impossible de lire une balise de date dans ce JSON.\n' +
                'Balises attendues : saved_at ou exported_at.'
            );
        } else if (currentTs > 0 && importedTs < currentTs) {
            warnings.push(
                'Le JSON importé semble plus ancien que le paramétrage actuellement utilisé.\n' +
                'Date du JSON importé (' + imported.source + ') : ' + importedLabel + '\n' +
                'Date du paramétrage actuel : ' + currentLabel
            );
        }

        if (importedCode !== '' && currentCode !== '' && importedCode.toUpperCase() !== currentCode.toUpperCase()) {
            warnings.push(
                'Le code tournoi du JSON importé est différent du code tournoi courant.\n' +
                'Code importé : ' + importedCode + '\n' +
                'Code courant : ' + currentCode
            );
        }

        if (importedCode === '') {
            warnings.push('Le JSON importé ne contient pas de code tournoi.');
        }

        let message = '';

        if (warnings.length > 0) {
            message =
                'Attention avant import :\n\n' +
                warnings.join('\n\n') +
                '\n\nImporter quand même et remplacer le paramétrage actuel ?';
        } else {
            message =
                'Importer ce fichier JSON et remplacer le paramétrage actuel ?\n\n' +
                'Code tournoi détecté : ' + importedCode + '\n' +
                'Date détectée (' + imported.source + ') : ' + importedLabel;
        }

        if (!confirm(message)) {
            return;
        }

        form.dataset.confirmed = '1';
        form.submit();
    };

    reader.onerror = function() {
        alert('Impossible de lire le fichier sélectionné.');
    };

    reader.readAsText(file);

    return false;
}

function addPaymentModeRow() {
    const tbody = document.getElementById('pmTableBody');

    if (!tbody) {
        return;
    }

    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td><input type="text" name="pm_code[]" value="" placeholder="ESP"></td>
        <td><input type="text" name="pm_label[]" value="" placeholder="Espèces"></td>
        <td style="text-align:center;">
            <button type="button" class="delete-btn" onclick="deletePaymentModeRow(this)">🗑</button>
        </td>
    `;

    tbody.appendChild(tr);
}

function deletePaymentModeRow(btn) {
    const tr = btn.closest('tr');

    if (tr) {
        tr.remove();
    }
}

function addRuleRow() {
    const tbody = document.getElementById('rulesTableBody');

    if (!tbody) {
        return;
    }

    const index = tbody.querySelectorAll('tr').length;
    const tr = document.createElement('tr');

    tr.innerHTML = `
        <td style="text-align:center;">
            <input type="checkbox" name="rule_active[${index}]" value="1">
        </td>
        <td>
            <input type="text" name="rule_label[${index}]" value="" placeholder="ex : Tarif spécial">
        </td>
        <td>
            <select name="rule_scope[${index}]" onchange="updateRuleDefaultValue(this)">
                ${buildRuleScopeOptionsHtml('categorie')}
            </select>
        </td>
        <td>
            <input type="text" name="rule_match[${index}]" value="CL" data-auto-value="1">
        </td>
        <td>
            <input type="number" name="rule_value[${index}]" value="0" step="0.01">
        </td>
        <td style="text-align:center;">
            <button type="button" class="delete-btn" onclick="deleteRuleRow(this)">🗑</button>
        </td>
    `;

    tbody.appendChild(tr);
}

function deleteRuleRow(btn) {
    const tr = btn.closest('tr');

    if (tr) {
        tr.remove();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.getElementById('importSettingsForm');

    if (importForm) {
        importForm.addEventListener('submit', handleImportSubmit);
    }
});
</script>

<?php include('Common/Templates/tail.php'); ?>