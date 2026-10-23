<?php
// ============================================================================
// RegistrArc - lib/registrarc.inc.php
// Fonctions communes à toutes les pages du module : chemins et stockage JSON,
// paramètres (tarifs, classes d'âge, modes de paiement, règles), paiements,
// groupes de chèques, tarification.
//
// Les pages définissent $ModuleName et $LegacyModuleName avant l'appel.
// ============================================================================

require_once(dirname(__FILE__) . '/registrarc_storage.inc.php');
require_once(dirname(__FILE__) . '/registrarc_engagements.inc.php');
require_once(dirname(__FILE__) . '/registrarc_caisse.inc.php');
require_once(dirname(__FILE__) . '/registrarc_ui.inc.php');
require_once(dirname(__FILE__) . '/registrarc_invoices.inc.php');
require_once(dirname(__FILE__) . '/registrarc_backup.inc.php');

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
// REGISTRARC_DATA_DIR permet aux tests de travailler dans un dossier temporaire
function registrarc_data_dir() {
    return defined('REGISTRARC_DATA_DIR') ? REGISTRARC_DATA_DIR : dirname(dirname(__FILE__)) . '/data';
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

    $testFile = $dir . '/.registrarc_write_test';

    if (@file_put_contents($testFile, 'ok', LOCK_EX) === false) {
        return [
            'ok' => false,
            'path' => $dir,
            'message' => "Le dossier de stockage JSON semble accessible, mais l'écriture d'un fichier de test a échoué.",
        ];
    }

    @unlink($testFile);
    registrarc_storage_protect_dir($dir);

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
    $data = registrarc_storage_read($file);

    return is_array($data) ? $data : [];
}

function registrarc_save_json_file($file, array $data) {
    if (!registrarc_ensure_data_dir()) {
        return false;
    }

    return registrarc_storage_replace($file, $data);
}

function registrarc_update_json_file($file, callable $mutator) {
    if (!registrarc_ensure_data_dir()) {
        return false;
    }

    return registrarc_storage_update($file, $mutator);
}

// ---------------------------------------------------------------------------
// Code tournoi / infos tournoi
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

// Cellule de tableur : une valeur saisie (note, nom, club) qui commence par
// = + - @ serait exécutée comme une formule par Excel ou LibreOffice.
// Une apostrophe en tête la garde en texte ; les nombres (-12,50) restent des nombres.
function registrarc_csv_cell($value) {
    if (!is_string($value) || $value === '') {
        return $value;
    }

    if (preg_match('/^-?\d+([.,]\d+)?$/', $value)) {
        return $value;
    }

    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
}

function registrarc_timestamp_from_value($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return 0;
    }

    $ts = strtotime($value);

    return $ts ? $ts : 0;
}

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

// ---------------------------------------------------------------------------
// Paiements JSON
// ---------------------------------------------------------------------------
function registrarc_load_payments($TourId) {
    return registrarc_load_json_file(registrarc_payments_file_path($TourId));
}

function registrarc_save_payments($TourId, array $data) {
    return registrarc_save_json_file(registrarc_payments_file_path($TourId), $data);
}

// Toute modification de paiement resynchronise les groupes de chèques
function registrarc_update_payments($TourId, callable $mutator) {
    $saved = registrarc_update_json_file(registrarc_payments_file_path($TourId), $mutator);

    if ($saved) {
        registrarc_sync_cheque_groups($TourId);
    }

    return $saved;
}

function registrarc_is_cheque_payment($payments, $engagementId) {
    $payment = isset($payments[(string)$engagementId]) && is_array($payments[(string)$engagementId])
        ? $payments[(string)$engagementId]
        : [];

    $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : 'NON_PAYE';
    $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';

    return $status === 'PAYE' && $method === 'CHQ';
}

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

function registrarc_create_cheque_group($TourId, array $ids, array $payments) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (count($ids) < 2) {
        return [
            'ok' => false,
            'message' => 'Sélectionne au moins 2 engagements pour créer une remise groupée.',
            'group_id' => '',
        ];
    }

    $invalid = [];

    foreach ($ids as $id) {
        if (!registrarc_is_cheque_payment($payments, $id)) {
            $invalid[] = $id;
        }
    }

    if (!empty($invalid)) {
        return [
            'ok' => false,
            'message' => 'Tous les engagements sélectionnés doivent être payés par chèque.',
            'group_id' => '',
        ];
    }

    $groupId = '';
    $conflictGroups = [];
    $conflictCount = 0;

    $saved = registrarc_update_json_file(
        registrarc_cheque_groups_file_path($TourId),
        function (array &$groups) use ($ids, &$groupId, &$conflictGroups, &$conflictCount) {
            $alreadyGrouped = registrarc_cheque_group_membership($groups);

            foreach ($ids as $id) {
                if (isset($alreadyGrouped[$id])) {
                    $conflictCount++;
                    $conflictGroups[$alreadyGrouped[$id]] = true;
                }
            }

            if ($conflictCount > 0) {
                return false;
            }

            $groupId = registrarc_generate_cheque_group_id($groups);

            $groups[$groupId] = [
                'id' => $groupId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'label' => 'Chèque groupé',
                'engagement_ids' => $ids,
                'archers_count' => count($ids),
            ];
        }
    );

    if (!$saved) {
        return [
            'ok' => false,
            'message' => "Erreur : impossible d'écrire le fichier JSON des remises de chèques." . registrarc_storage_error_suffix(),
            'group_id' => '',
        ];
    }

    if ($conflictCount > 0) {
        return [
            'ok' => false,
            'message' => $conflictCount . " engagement(s) sélectionné(s) font déjà partie d'une remise groupée ("
                . implode(', ', array_keys($conflictGroups)) . ").",
            'group_id' => '',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Remise groupée créée.',
        'group_id' => $groupId,
    ];
}

// [EnId => id du groupe] pour tous les engagements déjà groupés
function registrarc_cheque_group_membership(array $groups) {
    $map = [];

    foreach ($groups as $groupId => $group) {
        $ids = is_array($group) && isset($group['engagement_ids']) && is_array($group['engagement_ids'])
            ? $group['engagement_ids']
            : [];

        foreach ($ids as $id) {
            $id = intval($id);

            if ($id > 0 && !isset($map[$id])) {
                $map[$id] = $groupId;
            }
        }
    }

    return $map;
}

// Retire des groupes les engagements qui ne sont plus payés par chèque ou qui
// figurent déjà dans un groupe plus ancien ; supprime les groupes devenus
// inférieurs à 2 engagements (même règle qu'à la création).
function registrarc_sync_cheque_groups($TourId) {
    $file = registrarc_cheque_groups_file_path($TourId);

    if (!is_file($file)) {
        return true;
    }

    $payments = registrarc_load_payments($TourId);

    return registrarc_update_json_file($file, function (array &$groups) use ($payments) {
        $seen = [];
        $changed = false;

        foreach ($groups as $groupId => $group) {
            if (!is_array($group)) {
                continue;
            }

            $ids = isset($group['engagement_ids']) && is_array($group['engagement_ids'])
                ? array_map('intval', $group['engagement_ids'])
                : [];

            $kept = [];

            foreach ($ids as $id) {
                if ($id <= 0 || isset($seen[$id]) || !registrarc_is_cheque_payment($payments, $id)) {
                    continue;
                }

                $seen[$id] = true;
                $kept[] = $id;
            }

            if (count($kept) < 2) {
                unset($groups[$groupId]);
                $changed = true;
            } elseif ($kept !== $ids) {
                $groups[$groupId]['engagement_ids'] = $kept;
                $groups[$groupId]['archers_count'] = count($kept);
                $groups[$groupId]['updated_at'] = date('Y-m-d H:i:s');
                $changed = true;
            }
        }

        return $changed ? null : false;
    });
}

// ---------------------------------------------------------------------------
// Paramètres : valeurs par défaut et normalisation
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

    if (trim((string)$clean['GRA']) === '') {
        $clean['GRA'] = 'Gratuit';
    }

    return empty($clean) ? registrarc_default_payment_modes() : $clean;
}

function registrarc_normalize_settings($TourId, $settings) {
    $out = [
        'tarifs' => registrarc_default_tarifs(),
        'age_classes' => registrarc_default_age_classes(),
        'payment_modes' => registrarc_default_payment_modes(),
        'rules' => [],
        'options' => registrarc_default_pricing_options(),
    ];

    if (!is_array($settings)) {
        return $out;
    }

    if (isset($settings['options'])) {
        $out['options'] = registrarc_normalize_pricing_options($settings['options']);
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
    $data = registrarc_storage_read(registrarc_settings_file_path($TourId));

    if (!empty($data)) {
        return registrarc_normalize_settings($TourId, $data);
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

// ---------------------------------------------------------------------------
// Paramètres : accès
// ---------------------------------------------------------------------------
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

function registrarc_load_age_classes($TourId) {
    $settings = registrarc_load_settings($TourId);

    if (isset($settings['age_classes'])) {
        return registrarc_normalize_age_classes($settings['age_classes']);
    }

    return registrarc_default_age_classes();
}

// ---------------------------------------------------------------------------
// Tarification
// ---------------------------------------------------------------------------
// Ordre : liste "jeunes", puis liste "adultes", puis règle par défaut :
// une catégorie Uxx en dessous de U21 (U11, U13, U15, U18...) est jeune.
function registrarc_age_category($categorie, $ageClasses = null) {
    $categorie = (string)$categorie;

    if (!is_array($ageClasses) || !isset($ageClasses['jeunes'])) {
        $ageClasses = registrarc_default_age_classes();
    }

    foreach (['jeunes', 'adultes'] as $group) {
        $list = isset($ageClasses[$group]) && is_array($ageClasses[$group]) ? $ageClasses[$group] : [];

        foreach ($list as $c) {
            $c = trim((string)$c);

            if ($c !== '' && stripos($categorie, $c) !== false) {
                return $group;
            }
        }
    }

    if (preg_match('/U(\d{1,2})/i', $categorie, $m) && (int)$m[1] < 21) {
        return 'jeunes';
    }

    return 'adultes';
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

function registrarc_rule_entry_value($scope, array $entry) {
    $map = [
        'qualif_ind' => 'EnIndClEvent',
        'qualif_team' => 'EnTeamClEvent',
        'finale_ind' => 'EnIndFEvent',
        'finale_team' => 'EnTeamFEvent',
        'double_mixte' => 'EnTeamMixEvent',
        'finales_ind' => 'EnIndFEvent',
        'finales_team' => 'EnTeamFEvent',
        'finales_mix' => 'EnTeamMixEvent',
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

function registrarc_is_free_method($method) {
    $method = registrarc_normalize_payment_code($method);
    return in_array($method, ['GRA', 'GRATUIT'], true);
}

// Engagement gratuit (montant dû à 0 €) : l'archer n'a rien à payer,
// il n'est ni "à payer" ni en attente d'encaissement.
function registrarc_nothing_due($amount) {
    return (float)$amount <= 0;
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

function registrarc_format_montant($montant) {
    $montant = floatval($montant);

    if (floor($montant) == $montant) {
        return intval($montant);
    }

    return number_format($montant, 2, ',', ' ');
}

// ---------------------------------------------------------------------------
// Sécurité des formulaires
// - jeton anti-CSRF propre à la session
// - tournoi affiché à l'écran : refuse un envoi fait depuis un onglet resté
//   ouvert sur un autre tournoi que celui actuellement ouvert en session
// ---------------------------------------------------------------------------
function registrarc_form_token() {
    if (empty($_SESSION['RegistrArc_form_token'])) {
        $_SESSION['RegistrArc_form_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['RegistrArc_form_token'];
}

// Champs cachés à placer dans chaque formulaire POST
function registrarc_form_fields($TourId) {
    return '<input type="hidden" name="registrarc_token" value="' . htmlspecialchars(registrarc_form_token()) . '">'
        . '<input type="hidden" name="registrarc_tour" value="' . intval($TourId) . '">';
}

// Jeton seul, pour les pages qui ne dépendent pas d'un tournoi (mise à jour)
// Retourne '' si l'envoi est valide, sinon le message d'erreur à afficher
function registrarc_check_token() {
    if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
        return "Envoi rejeté : les données dépassent la taille maximale acceptée par le serveur (post_max_size).";
    }

    $token = isset($_POST['registrarc_token']) ? (string)$_POST['registrarc_token'] : '';

    if ($token === '' || empty($_SESSION['RegistrArc_form_token']) || !hash_equals($_SESSION['RegistrArc_form_token'], $token)) {
        return "Formulaire expiré ou invalide : aucune modification enregistrée. Recharge la page et recommence.";
    }

    return '';
}

// Retourne '' si l'envoi est valide, sinon le message d'erreur à afficher.
// Un concours clôturé refuse toute modification, sauf sa réouverture ($allowWhenClosed).
function registrarc_check_form($TourId, $allowWhenClosed = false) {
    $tokenError = registrarc_check_token();

    if ($tokenError !== '') {
        return $tokenError;
    }

    if (!$allowWhenClosed && registrarc_is_closed($TourId)) {
        return "Le concours est clôturé : aucune modification n'est possible. Il peut être rouvert depuis le tableau de bord.";
    }

    $postedTour = isset($_POST['registrarc_tour']) ? intval($_POST['registrarc_tour']) : 0;

    if ($postedTour !== intval($TourId)) {
        $code = registrarc_current_tournament_code();

        return "Le tournoi ouvert a changé depuis l'affichage de cette page"
            . ($code !== '' ? " (tournoi actuellement ouvert : " . $code . ")" : '')
            . " : aucune modification enregistrée. Recharge la page.";
    }

    return '';
}
