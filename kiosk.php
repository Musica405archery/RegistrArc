<?php
// ============================================================================
// RegistrArc - kiosk.php
// Interface mobile simplifiée pour validation des paiements
//
// Objectif :
// - page autonome sans menu Ianseo
// - rendu type application mobile
// - sélection d'un ou plusieurs départs avec persistance après rechargement
// - par défaut : affiche uniquement les archers restant à payer
// - option pour afficher aussi les archers déjà payés
// - affichage compact : nom, catégorie, départ, cible, prix par engagement
// - affichage des étiquettes tarif spécial / cumul / gratuit
// - total dû dynamique selon les archers sélectionnés
// - paiement en masse via sélection
// - choix du moyen de paiement en bas de page
// - demande de reçu
// - création automatique d'un groupe de chèque si plusieurs engagements sont validés en CHQ
// - aucune modification de tarif depuis cette page
// - stockage uniquement dans data/payments_<TourId>.json
// - groupes chèques dans data/cheque_groups_<TourId>.json
// - aucune écriture dans Qualifications.QuNotes
//
// Fichiers JSON :
// - Paiements       : Modules/Custom/RegistrArc/data/payments_<TourId>.json
// - Paramètres      : Modules/Custom/RegistrArc/data/settings_<TourId>.json
// - Groupes chèques : Modules/Custom/RegistrArc/data/cheque_groups_<TourId>.json
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

function registrarc_cheque_groups_file_path($TourId) {
    return registrarc_data_dir() . '/cheque_groups_' . intval($TourId) . '.json';
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

    $testFile = $dir . '/.registrarc_kiosk_write_test';

    if (@file_put_contents($testFile, 'ok', LOCK_EX) === false) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => "Le dossier JSON semble accessible, mais l'écriture d'un fichier de test a échoué.",
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
// JSON générique
// ---------------------------------------------------------------------------
function registrarc_load_json_file($file) {
    if (!is_file($file)) {
        return [];
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function registrarc_save_json_file($file, array $data) {
    if (!registrarc_ensure_data_dir()) {
        return false;
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return file_put_contents($file, $json, LOCK_EX) !== false;
}

// ---------------------------------------------------------------------------
// Paiements JSON
// ---------------------------------------------------------------------------
function registrarc_load_payments($TourId) {
    return registrarc_load_json_file(registrarc_payments_file_path($TourId));
}

function registrarc_save_payments($TourId, array $data) {
    return registrarc_save_json_file(registrarc_payments_file_path($TourId), $data);
}

// ---------------------------------------------------------------------------
// Groupes de chèques JSON
// ---------------------------------------------------------------------------
function registrarc_load_cheque_groups($TourId) {
    return registrarc_load_json_file(registrarc_cheque_groups_file_path($TourId));
}

function registrarc_save_cheque_groups($TourId, array $data) {
    return registrarc_save_json_file(registrarc_cheque_groups_file_path($TourId), $data);
}

function registrarc_generate_cheque_group_id(array $groups) {
    $base = 'CG-' . date('Ymd-His');
    $idx = 1;

    do {
        $id = $base . '-' . str_pad($idx, 3, '0', STR_PAD_LEFT);
        $idx++;
    } while (isset($groups[$id]));

    return $id;
}

function registrarc_create_cheque_group($TourId, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (count($ids) < 2) {
        return [
            'ok' => false,
            'message' => 'Il faut au moins 2 engagements pour créer un groupe de chèque.',
            'group_id' => '',
        ];
    }

    $groups = registrarc_load_cheque_groups($TourId);
    $groupId = registrarc_generate_cheque_group_id($groups);

    $groups[$groupId] = [
        'id' => $groupId,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'label' => 'Chèque groupé',
        'engagement_ids' => $ids,
        'archers_count' => count($ids),
    ];

    if (!registrarc_save_cheque_groups($TourId, $groups)) {
        return [
            'ok' => false,
            'message' => "Erreur lors de l'écriture du JSON des groupes de chèques.",
            'group_id' => '',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Groupe de chèque créé.',
        'group_id' => $groupId,
    ];
}

// ---------------------------------------------------------------------------
// Paramètres JSON
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

    if (!isset($clean['GRA'])) {
        $clean['GRA'] = 'Gratuit';
    }

    return empty($clean) ? registrarc_default_payment_modes() : $clean;
}

function registrarc_normalize_settings($TourId, $settings) {
    $out = [
        'tarifs' => registrarc_default_tarifs(),
        'payment_modes' => registrarc_default_payment_modes(),
        'rules' => [],
    ];

    if (!is_array($settings)) {
        return $out;
    }

    if (isset($settings['tarifs']) && is_array($settings['tarifs'])) {
        $out['tarifs'] = $settings['tarifs'];
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

    return isset($entry[$map[$scope]]) ? $entry[$map[$scope]] : '';
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
        'labels' => $labels,
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

// ---------------------------------------------------------------------------
// Paiement JSON setters
// ---------------------------------------------------------------------------
function registrarc_set_payment_in_array(array &$data, $engagementId, $method, $receiptRequired = false) {
    $engagementId = intval($engagementId);

    if ($engagementId <= 0) {
        return false;
    }

    $method = registrarc_normalize_payment_code($method);

    $data[(string)$engagementId] = [
        'status' => 'PAYE',
        'method' => $method,
        'receipt_required' => !empty($receiptRequired),
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
        'status' => 'NON_PAYE',
        'method' => '',
        'receipt_required' => false,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    return true;
}

// ---------------------------------------------------------------------------
// Format
// ---------------------------------------------------------------------------
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
$tarifRules = registrarc_load_tarif_rules($TourId);
$dataDirStatus = registrarc_data_dir_status();

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$dataDirStatus['ok']) {
        $_SESSION['RegistrArc_kiosk_message'] = "Action impossible : le dossier JSON n'est pas accessible en écriture.";
        $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        header('Location: kiosk.php');
        exit();
    }

    $payments = registrarc_load_payments($TourId);

    if (isset($_POST['single_action']) && isset($_POST['engagement_id'])) {
        $engagementId = intval($_POST['engagement_id']);
        $action = trim((string)$_POST['single_action']);

        $method = isset($_POST['payment_method'])
            ? registrarc_clean_payment_method($_POST['payment_method'], $paymentModes)
            : 'ESP';

        $receiptRequired = !empty($_POST['receipt_required']);

        if ($engagementId > 0) {
            if ($action === 'validate') {
                registrarc_set_payment_in_array(
                    $payments,
                    $engagementId,
                    $method,
                    $receiptRequired
                );

                $_SESSION['RegistrArc_kiosk_message'] = 'Paiement validé.';
                $_SESSION['RegistrArc_kiosk_message_type'] = 'success';
            } elseif ($action === 'unvalidate') {
                registrarc_unset_payment_in_array($payments, $engagementId);
                $_SESSION['RegistrArc_kiosk_message'] = 'Paiement retiré.';
                $_SESSION['RegistrArc_kiosk_message_type'] = 'info';
            }

            if (!registrarc_save_payments($TourId, $payments)) {
                $_SESSION['RegistrArc_kiosk_message'] = "Erreur lors de l'écriture du JSON paiements.";
                $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
            }
        }

        header('Location: kiosk.php');
        exit();
    }

    if (isset($_POST['bulk_action']) && !empty($_POST['engagements']) && is_array($_POST['engagements'])) {
        $action = trim((string)$_POST['bulk_action']);

        $method = isset($_POST['bulk_payment_method'])
            ? registrarc_clean_payment_method($_POST['bulk_payment_method'], $paymentModes)
            : 'ESP';

        $receiptRequired = !empty($_POST['bulk_receipt_required']);

        $ids = [];

        foreach ($_POST['engagements'] as $id) {
            $id = intval($id);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!empty($ids)) {
            foreach ($ids as $id) {
                if ($action === 'validate') {
                    registrarc_set_payment_in_array($payments, $id, $method, $receiptRequired);
                } elseif ($action === 'unvalidate') {
                    registrarc_unset_payment_in_array($payments, $id);
                }
            }

            if (registrarc_save_payments($TourId, $payments)) {
                if ($action === 'validate') {
                    $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés pour ' . count($ids) . ' engagement(s).';
                    $_SESSION['RegistrArc_kiosk_message_type'] = 'success';
                } else {
                    $_SESSION['RegistrArc_kiosk_message'] = 'Paiements retirés pour ' . count($ids) . ' engagement(s).';
                    $_SESSION['RegistrArc_kiosk_message_type'] = 'info';
                }
            } else {
                $_SESSION['RegistrArc_kiosk_message'] = "Erreur lors de l'écriture du JSON paiements.";
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
// Numérotation engagements
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
    ORDER BY q.QuSession, q.QuTarget, q.QuLetter, UPPER(TRIM(e.EnFirstName)), TRIM(e.EnName), e.EnId
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
            $targetNo = '';
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
                $organizerClubCode
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

$sessions = array_keys($sessions);
sort($sessions, SORT_NUMERIC);

$message = '';
$messageType = 'success';

if (isset($_SESSION['RegistrArc_kiosk_message'])) {
    $message = $_SESSION['RegistrArc_kiosk_message'];
    $messageType = isset($_SESSION['RegistrArc_kiosk_message_type']) ? $_SESSION['RegistrArc_kiosk_message_type'] : 'success';
    unset($_SESSION['RegistrArc_kiosk_message'], $_SESSION['RegistrArc_kiosk_message_type']);
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
            font-family: Arial, Helvetica, sans-serif;
            -webkit-font-smoothing: antialiased;
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
            padding: 12px 12px 10px;
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
            padding: 12px;
            padding-bottom: calc(170px + var(--safe-bottom));
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

        .btn-danger {
            background: var(--danger);
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
            gap: 9px;
            margin-top: 10px;
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
        }

        .session-pill input {
            width: 15px;
            height: 15px;
            margin: 0;
        }

        .show-paid-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 900;
            color: #374151;
        }

        .show-paid-row input {
            width: 18px;
            height: 18px;
            margin: 0;
        }

        .search-card {
            padding: 10px;
        }

        label {
            font-size: 11px;
            color: var(--muted);
            font-weight: 800;
            display: block;
            margin-bottom: 5px;
        }

        select,
        input[type="text"] {
            width: 100%;
            box-sizing: border-box;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            background: #fff;
            font-size: 14px;
        }

        .search-input-wrap {
            position: relative;
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
            margin-top: 7px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
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
        }

        .row-main {
            min-width: 0;
        }

        .row-line-primary {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            align-items: flex-start;
        }

        .archer-name {
            font-size: 15px;
            font-weight: 900;
            line-height: 1.15;
            min-width: 0;
        }

        .amount-compact {
            flex-shrink: 0;
            font-size: 16px;
            font-weight: 950;
            color: var(--strong);
            line-height: 1.1;
            white-space: nowrap;
        }

        .row-line-secondary {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 8px;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.25;
            margin-top: 4px;
        }

        .row-line-secondary strong {
            color: #374151;
        }

        .target-strong {
            font-size: 22px;
            font-weight: 950;
            color: #111827;
            line-height: 1;
        }

        .category-strong {
            font-size: 16px;
            font-weight: 950;
            color: #111827;
        }

        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            margin-top: 5px;
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

        .badge-paid {
            background: #dcfce7;
            color: #166534;
        }

        .badge-unpaid {
            background: #fee2e2;
            color: #991b1b;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 900;
            color: #374151;
        }

        .bulk-total {
            color: var(--strong);
            font-size: 13px;
        }

        .bulk-controls {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 8px;
            align-items: center;
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
        }

        .bulk-receipt input {
            width: 16px;
            height: 16px;
            margin: 0;
        }

        .bulk-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .empty {
            text-align: center;
            color: var(--muted);
            font-weight: 800;
            padding: 24px;
        }

        @media (min-width: 768px) {
            .app-shell,
            .bottom-bulk-inner {
                max-width: 920px;
            }

            .app-content {
                padding-bottom: calc(155px + var(--safe-bottom));
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
                    <div class="app-subtitle">Mode guichet mobile</div>
                </div>

                <div class="app-header-actions">
                    <a href="index.php?desktop=1" class="btn btn-ghost btn-small">Version complète</a>
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

            <div class="card search-card">
                <label for="kioskSearch">Rechercher un archer</label>
                <div class="search-input-wrap">
                    <input type="text" id="kioskSearch" placeholder="Nom, prénom, licence, club..." oninput="filterCards();" autocomplete="off">
                    <button type="button" class="search-clear" onclick="clearSearch();">×</button>
                </div>

                <div class="results-count">
                    <span id="visibleCount"><?php echo count($rows); ?></span> engagement(s) affiché(s)
                </div>
            </div>

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
                        <div class="row-line-primary">
                            <div class="archer-name">
                                <?php echo htmlspecialchars(trim($row['nom'] . ' ' . $row['prenom'])); ?>
                            </div>

                            <div class="amount-compact">
                                <?php echo registrarc_format_montant($row['amount_due']); ?> €
                            </div>
                        </div>

                        <div class="row-line-secondary">
                            <span>
                                <?php echo $row['session'] !== '' ? htmlspecialchars('Départ ' . $row['session']) : '—'; ?>
                            </span>

                            <span>
                                Cible
                                <?php if (!empty($row['target_is_unassigned'])): ?>
                                    <strong class="target-strong" style="color:#991b1b;"><?php echo htmlspecialchars($row['target_label']); ?></strong>
                                <?php else: ?>
                                    <strong class="target-strong"><?php echo htmlspecialchars($row['target_label']); ?></strong>
                                <?php endif; ?>
                            </span>

                            <span>
                                Cat.
                                <strong class="category-strong"><?php echo htmlspecialchars($row['categorie']); ?></strong>
                            </span>

                            <span>
                                Eng. n° <strong><?php echo intval($row['numero_engagement']); ?></strong>
                            </span>
                        </div>

                        <div class="badges">
                            <?php if ($isPaid): ?>
                                <span class="badge badge-paid">Payé</span>
                            <?php else: ?>
                                <span class="badge badge-unpaid">À payer</span>
                            <?php endif; ?>

                            <?php if ($row['payment_method'] !== ''): ?>
                                <span class="badge badge-method">
                                    <?php echo htmlspecialchars($row['payment_method_label']); ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($row['receipt_required'])): ?>
                                <span class="badge badge-receipt">Reçu requis</span>
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
        <div class="bottom-bulk-inner">
            <div class="bulk-summary">
                <span><span id="bulkCount">0</span> sélectionné(s)</span>
                <span class="bulk-total">Total dû : <span id="bulkTotal">0 €</span></span>

                <label class="bulk-receipt">
                    <input type="checkbox" name="bulk_receipt_required" id="bulkReceiptRequired" value="1" autocomplete="off">
                    Reçu
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

                <button type="button" class="btn btn-ghost btn-small" onclick="clearBulkSelection();">
                    Effacer
                </button>
            </div>

            <div class="bulk-buttons">
                <button type="submit" name="bulk_action" value="validate" class="btn btn-success" onclick="return confirmBulk('validate');">
                    Valider
                </button>

                <button type="submit" name="bulk_action" value="unvalidate" class="btn btn-danger" onclick="return confirmBulk('unvalidate');">
                    Retirer
                </button>
            </div>
        </div>
    </form>

    <script>
        const KIOSK_FILTERS_KEY = 'RegistrArcKioskFilters_<?php echo intval($TourId); ?>';

        function selectedSessions() {
            const checked = Array.from(document.querySelectorAll('.session-filter:checked'));
            return checked.map(input => String(input.value || ''));
        }

        function saveKioskFiltersState() {
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
            let state = null;

            try {
                const raw = localStorage.getItem(KIOSK_FILTERS_KEY);

                if (raw) {
                    state = JSON.parse(raw);
                }
            } catch (e) {
                state = null;
            }

            const sessionCheckboxes = Array.from(document.querySelectorAll('.session-filter'));
            const searchInput = document.getElementById('kioskSearch');
            const showPaidCheckbox = document.getElementById('showPaidCheckbox');

            if (state && Array.isArray(state.selectedSessions)) {
                sessionCheckboxes.forEach(cb => {
                    cb.checked = state.selectedSessions.indexOf(String(cb.value || '')) !== -1;
                });
            } else {
                sessionCheckboxes.forEach(cb => {
                    cb.checked = true;
                });
            }

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

        function clearBulkSelection() {
            document.querySelectorAll('.bulk-checkbox').forEach(cb => {
                cb.checked = false;
            });

            updateBulkSummary();
        }

        function confirmBulk(type) {
            const count = selectedBulkCount();
            const total = selectedBulkTotal();

            if (count === 0) {
                alert('Aucun engagement sélectionné.');
                return false;
            }

            if (type === 'validate') {
                return confirm(
                    'Valider le paiement pour ' + count + ' engagement(s) ?\n\n' +
                    'Total dû : ' + formatEuro(total)
                );
            }

            return confirm(
                'Retirer le paiement pour ' + count + ' engagement(s) ?\n\n' +
                'Total concerné : ' + formatEuro(total)
            );
        }

        function toggleCardSelection(event, card) {
            if (event.target.closest('input, button, select, a, label')) {
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