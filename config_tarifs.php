<?php
// ============================================================================
// RegistrArc - config_tarifs.php
// Configuration des tarifs, moyens de paiement, classes d'âge et règles avancées
//
// Stockage principal en JSON local :
// Modules/Custom/RegistrArc/data/settings_<TourId>.json
//
// Export / Import JSON :
// - inclut le code tournoi pour faciliter l'échange entre installations
// - alerte si le fichier importé semble plus ancien
// - alerte si le code tournoi importé diffère du code tournoi courant
//
// Classes d'âge :
// - l'utilisateur définit quelles catégories sont "jeunes"
// - l'utilisateur définit quelles catégories sont "adultes"
// - les valeurs sont stockées dans age_classes.jeunes et age_classes.adultes
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
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

// ---------------------------------------------------------------------------
// Paramètres module avec compatibilité ancien Greffe2
// ---------------------------------------------------------------------------
function registrarc_set_module_parameter($name, $value) {
    global $ModuleName;

    setModuleParameter($ModuleName, $name, $value);
}

// ---------------------------------------------------------------------------
// Code tournoi
// ---------------------------------------------------------------------------
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
// Catégories (Classes) existantes pour le tournoi
// ---------------------------------------------------------------------------
function registrarc_get_tournament_classes($TourId) {
    $TourId = intval($TourId);
    $classes = [];

    // On ne garde que les catégories réellement utilisées par des athlètes
    // (ClAthlete != '0') pour ce tournoi précis.
    $query = "
        SELECT ClId, ClDescription
        FROM Classes
        WHERE ClTournament = $TourId
          AND ClAthlete != '0'
        ORDER BY ClViewOrder ASC, ClId ASC
    ";

    $rs = safe_r_sql($query);

    while ($row = safe_fetch($rs)) {
        $classes[] = [
            'id' => trim((string)$row->ClId),
            'description' => trim((string)$row->ClDescription),
        ];
    }

    return $classes;
}

$tournamentClasses = registrarc_get_tournament_classes($TourId);

function registrarc_render_age_card($classId, $classLabel, $group) {
    $otherGroup = ($group === 'jeunes') ? 'adultes' : 'jeunes';
    $arrow = ($group === 'jeunes') ? '→' : '←';
    $otherLabelText = ($otherGroup === 'jeunes') ? 'Jeunes' : 'Adultes';

    $html = '<div class="age-card" draggable="true" data-class-id="' . htmlspecialchars($classId) . '" data-group="' . htmlspecialchars($group) . '"';
    $html .= ' ondragstart="handleAgeCardDragStart(event);" ondragend="handleAgeCardDragEnd(event);">';
    $html .= '<span class="age-card-label" title="' . htmlspecialchars($classLabel) . '">' . htmlspecialchars($classLabel) . '</span>';
    $html .= '<button type="button" class="age-card-move" data-target="' . htmlspecialchars($otherGroup) . '"';
    $html .= ' title="Déplacer vers ' . htmlspecialchars($otherLabelText) . '" onclick="handleAgeCardMoveClick(this);">' . $arrow . '</button>';
    $html .= '</div>';

    return $html;
}

// ---------------------------------------------------------------------------
// Valeurs par défaut
// ---------------------------------------------------------------------------
function registrarc_default_settings($TourId) {
    return [
        'module' => 'RegistrArc',
        'version' => 2,
        'tour_id' => intval($TourId),
        'tournament_code' => registrarc_current_tournament_code(),
        'exported_at' => null,
        'saved_at' => null,
        'age_classes' => registrarc_default_age_classes(),
        'tarifs' => registrarc_default_tarifs(),
        'payment_modes' => registrarc_default_payment_modes(),
        'rules' => [],
        'options' => registrarc_default_pricing_options(),
    ];
}

// ---------------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------------
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

function registrarc_config_normalize_settings($TourId, $settings, array $allowedRuleScopes) {
    $default = registrarc_default_settings($TourId);

    if (!is_array($settings)) {
        return $default;
    }

    $out = $default;

    $out['module'] = isset($settings['module']) ? trim((string)$settings['module']) : 'RegistrArc';
    $out['version'] = isset($settings['version']) ? (int)$settings['version'] : 2;
    $out['tour_id'] = intval($TourId);
    $out['tournament_code'] = registrarc_extract_tournament_code_from_settings($settings);
    $out['exported_at'] = isset($settings['exported_at']) ? $settings['exported_at'] : null;
    $out['saved_at'] = isset($settings['saved_at']) ? $settings['saved_at'] : null;

    if ($out['tournament_code'] === '') {
        $out['tournament_code'] = registrarc_current_tournament_code();
    }

    $out['age_classes'] = registrarc_normalize_age_classes(isset($settings['age_classes']) ? $settings['age_classes'] : null);
    $out['tarifs'] = registrarc_normalize_tarifs(isset($settings['tarifs']) ? $settings['tarifs'] : null);
    $out['payment_modes'] = registrarc_normalize_payment_modes(isset($settings['payment_modes']) ? $settings['payment_modes'] : null);
    $out['rules'] = registrarc_normalize_rules(isset($settings['rules']) ? $settings['rules'] : [], $allowedRuleScopes);
    $out['options'] = registrarc_normalize_pricing_options(isset($settings['options']) ? $settings['options'] : null);

    return $out;
}

// ---------------------------------------------------------------------------
// Chargement / sauvegarde
// ---------------------------------------------------------------------------
function registrarc_config_load_settings($TourId, array $allowedRuleScopes) {
    $data = registrarc_storage_read(registrarc_settings_file_path($TourId));

    if (!empty($data)) {
        return registrarc_config_normalize_settings($TourId, $data, $allowedRuleScopes);
    }

    return registrarc_default_settings($TourId);
}

function registrarc_save_settings($TourId, array $settings, array $allowedRuleScopes) {
    if (!registrarc_ensure_data_dir()) {
        return false;
    }

    $settings = registrarc_config_normalize_settings($TourId, $settings, $allowedRuleScopes);
    $settings['saved_at'] = date('Y-m-d H:i:s');

    return registrarc_storage_replace(registrarc_settings_file_path($TourId), $settings);
}

// ---------------------------------------------------------------------------
// Infos tournoi
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$organizerClubCode = $tournament['organizer_code'];
$organizerClubName = $tournament['organizer_name'];
$tournamentName = $tournament['name'];
$tournamentCode = registrarc_current_tournament_code();

$dataDirStatus = registrarc_data_dir_status();
$canWrite = registrarc_can_write($TourId);

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $settings = registrarc_config_load_settings($TourId, $allowedRuleScopes);
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
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        $message = $formError;
        $messageType = 'error';
    } elseif (isset($_POST['import_settings'])) {
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
                $currentSettings = registrarc_config_load_settings($TourId, $allowedRuleScopes);
                $currentTimestamp = registrarc_settings_timestamp($currentSettings, registrarc_settings_file_path($TourId));
                $settings = registrarc_config_normalize_settings($TourId, $data, $allowedRuleScopes);
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
                    $message = "Erreur lors de l'écriture du fichier JSON." . registrarc_storage_error_suffix();
                    $messageType = 'error';
                }
            }
        }
    } elseif (isset($_POST['save_settings'])) {
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

            $ageClasses = [
                'jeunes' => isset($_POST['age_classes_jeunes']) ? registrarc_split_age_class_string($_POST['age_classes_jeunes']) : [],
                'adultes' => isset($_POST['age_classes_adultes']) ? registrarc_split_age_class_string($_POST['age_classes_adultes']) : [],
            ];

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

            if (!isset($modes['GRA'])) {
                $modes['GRA'] = 'Gratuit';
            }

            if (trim((string)$modes['GRA']) === '') {
                $modes['GRA'] = 'Gratuit';
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
                'version' => 2,
                'tour_id' => $TourId,
                'tournament_code' => $tournamentCode,
                'exported_at' => null,
                'age_classes' => $ageClasses,
                'tarifs' => $tarifs,
                'payment_modes' => $modes,
                'rules' => $rules,
                'options' => [
                    'cap_per_archer' => isset($_POST['cap_per_archer']) ? str_replace(',', '.', $_POST['cap_per_archer']) : 0,
                    'late_fee' => isset($_POST['late_fee']) ? str_replace(',', '.', $_POST['late_fee']) : 0,
                ],
            ];

            $settings = registrarc_config_normalize_settings($TourId, $settings, $allowedRuleScopes);

            if (registrarc_save_settings($TourId, $settings, $allowedRuleScopes)) {
                $message = "Paramètres enregistrés dans le JSON.";
                $messageType = 'success';
            } else {
                $message = "Erreur lors de l'écriture du fichier JSON." . registrarc_storage_error_suffix();
                $messageType = 'error';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Valeurs actuelles
// ---------------------------------------------------------------------------
$currentSettings = registrarc_config_load_settings($TourId, $allowedRuleScopes);
$currentAgeClasses = isset($currentSettings['age_classes']) ? registrarc_normalize_age_classes($currentSettings['age_classes']) : registrarc_default_age_classes();
$currentTarifs = $currentSettings['tarifs'];
$currentModes = $currentSettings['payment_modes'];
$currentRules = $currentSettings['rules'];
$currentOptions = registrarc_normalize_pricing_options(isset($currentSettings['options']) ? $currentSettings['options'] : null);

$currentSettingsTimestamp = registrarc_settings_timestamp($currentSettings, registrarc_settings_file_path($TourId));
$currentSettingsTimestampLabel = registrarc_format_timestamp_for_message($currentSettingsTimestamp);

$ageClassesJeunesText = implode(', ', $currentAgeClasses['jeunes']);
$ageClassesAdultesText = implode(', ', $currentAgeClasses['adultes']);

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

<link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/config_tarifs.css'); ?>">

<div class="registrarc-container">
    <div class="registrarc-header">
        <div>
            <div class="registrarc-header-title">Paramètres</div>
            <div class="registrarc-header-sub"><?php echo htmlspecialchars($tournamentName ?: "RegistrArc, le module de greffe pour I@nseo"); ?></div>
        </div>

        <div class="header-actions">
            <a href="index.php">Liste</a>
            <a href="dashboard.php">Tableau de bord</a>
            <a href="caisse.php">Caisse</a>
            <a href="kiosk.php">Mode Kiosk</a>
            <a href="config_tarifs.php" class="is-active">⚙ Paramètres</a>
            <a href="aide.php#régler-les-tarifs-et-les-paiements">? Aide</a>
        </div>
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
            Les classes d'âge, tarifs, moyens de paiement et règles avancées sont enregistrés dans un fichier JSON local.
        </div>

        <div class="import-export-row">
            <div>
                <div style="font-weight:600;margin-bottom:6px;">Exporter</div>
                <a href="?action=export" class="btn-primary">
                    Exporter le JSON
                </a>
            </div>

            <?php if ($canWrite): ?>
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
                    <?php echo registrarc_form_fields($TourId); ?>
                    <input type="file" name="settings_file" id="settings_file" accept="application/json,.json" class="import-input" style="max-width:320px;">
                    <button type="submit" name="import_settings" value="1" class="btn-warning">
                        Importer
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" id="mainSettingsForm">
        <?php echo registrarc_form_fields($TourId); ?>
        <div class="card">
            <div class="card-title">Classes d'âge</div>
            <div class="card-help">
                Toutes les catégories réellement présentes dans ce tournoi (table <strong>Classes</strong>) sont réparties
                dans l'une des deux colonnes ci-dessous. <strong>Glisse-dépose</strong> une catégorie d'une colonne à l'autre,
                ou clique sur la flèche de sa carte pour la faire basculer instantanément.
            </div>

            <!-- Ces deux champs cachés contiennent la liste finale (jeunes / adultes), reconstruite automatiquement
                 à partir du contenu des deux colonnes ci-dessous. Ils sont utilisés tels quels par le reste du code (sauvegarde). -->
            <input type="hidden" name="age_classes_jeunes" id="age_classes_jeunes" value="<?php echo htmlspecialchars($ageClassesJeunesText); ?>">
            <input type="hidden" name="age_classes_adultes" id="age_classes_adultes" value="<?php echo htmlspecialchars($ageClassesAdultesText); ?>">

            <?php if (empty($tournamentClasses)): ?>
                <div class="card-help" style="color:#a33;">
                    Aucune catégorie avec engagement (ClAthlete ≠ 0) n'a été trouvée dans la table <code>Classes</code> pour ce tournoi.
                </div>
            <?php else: ?>
                <input
                    type="text"
                    class="age-search-input"
                    placeholder="Filtrer les catégories…"
                    oninput="filterAgeCards(this.value);"
                >

                <div class="age-transfer">
                    <div class="age-transfer-col">
                        <div class="age-transfer-col-header">
                            <span class="age-transfer-col-title">Jeunes</span>
                            <span class="age-transfer-count" id="jeunesCount">0</span>
                        </div>
                        <div
                            class="age-dropzone"
                            data-group="jeunes"
                            id="jeunesZone"
                            ondragover="handleZoneDragOver(event);"
                            ondragleave="handleZoneDragLeave(event);"
                            ondrop="handleZoneDrop(event);"
                        >
                            <?php foreach ($tournamentClasses as $class):
                                $classId = $class['id'];
                                $classLabel = $classId . ($class['description'] !== '' ? ' — ' . $class['description'] : '');
                                $isJeune = registrarc_age_category($classId, $currentAgeClasses) === 'jeunes';

                                if (!$isJeune) {
                                    continue;
                                }

                                echo registrarc_render_age_card($classId, $classLabel, 'jeunes');
                            endforeach; ?>
                        </div>
                    </div>

                    <div class="age-transfer-col">
                        <div class="age-transfer-col-header">
                            <span class="age-transfer-col-title">Adultes</span>
                            <span class="age-transfer-count" id="adultesCount">0</span>
                        </div>
                        <div
                            class="age-dropzone"
                            data-group="adultes"
                            id="adultesZone"
                            ondragover="handleZoneDragOver(event);"
                            ondragleave="handleZoneDragLeave(event);"
                            ondrop="handleZoneDrop(event);"
                        >
                            <?php foreach ($tournamentClasses as $class):
                                $classId = $class['id'];
                                $classLabel = $classId . ($class['description'] !== '' ? ' — ' . $class['description'] : '');
                                $isJeune = registrarc_age_category($classId, $currentAgeClasses) === 'jeunes';

                                if ($isJeune) {
                                    continue;
                                }

                                echo registrarc_render_age_card($classId, $classLabel, 'adultes');
                            endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="grid-2">
                <div>
                    <strong>Aperçu Jeunes</strong>
                    <div class="age-preview" id="agePreviewJeunes"></div>
                </div>

                <div>
                    <strong>Aperçu Adultes</strong>
                    <div class="age-preview" id="agePreviewAdultes"></div>
                </div>
            </div>
        </div>

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
                                <th>Classe d'âge</th>
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
                                <th>Classe d'âge</th>
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
            <div class="card-title">Réductions et majorations</div>
            <div class="card-help">
                Laisser à 0 pour ne pas utiliser une option. Elles s'appliquent après le tarif normal ou la règle avancée,
                dans cet ordre : plafond, puis majoration.
            </div>

            <table>
                <tbody>
                    <tr>
                        <td style="width:34%;"><strong>Plafond par archer</strong></td>
                        <td style="width:16%;"><input type="number" name="cap_per_archer" value="<?php echo htmlspecialchars((string)$currentOptions['cap_per_archer']); ?>" min="0" step="0.5" class="tarif-input"> €</td>
                        <td>Montant maximal payé par un archer pour l'ensemble de ses départs.</td>
                    </tr>
                    <tr>
                        <td><strong>Majoration inscription tardive</strong></td>
                        <td><input type="number" name="late_fee" value="<?php echo htmlspecialchars((string)$currentOptions['late_fee']); ?>" min="0" step="0.5" class="tarif-input"> €</td>
                        <td>Ajoutée aux départs marqués « inscription tardive » depuis la liste des engagements.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <div class="card-title">Règles avancées de tarification</div>
            <div class="card-help">
                Valeurs possibles pour les portées liées aux engagements : <strong>OUI</strong> ou <strong>NON</strong>.
                <?php if (empty($finalPresence['individual']) && empty($finalPresence['team']) && empty($finalPresence['mixed'])): ?>
                    <br><strong>Aucune phase finale détectée :</strong> les règles liées aux finales ne sont pas proposées.
                <?php elseif (empty($finalPresence['individual'])): ?>
                    <br><strong>Pas de finale individuelle détectée :</strong> la règle finale individuelle n'est pas proposée.
                <?php elseif (empty($finalPresence['team'])): ?>
                    <br><strong>Pas de finale équipe détectée :</strong> la règle finale équipe n'est pas proposée.
                <?php endif; ?>
                <?php if (empty($finalPresence['mixed'])): ?>
                    <br><strong>Pas de double mixte détecté :</strong> la règle double mixte n'est pas proposée.
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

                    if (!isset($rows['GRA'])) {
                        $rows['GRA'] = 'Gratuit';
                    }

                    $rows[''] = '';

                    foreach ($rows as $code => $label):
                        $code = trim((string)$code);
                        $label = trim((string)$label);
                        $isProtectedGra = ($code === 'GRA');
                    ?>
                        <tr>
                            <td>
                                <input
                                    type="text"
                                    name="pm_code[]"
                                    value="<?php echo htmlspecialchars($code); ?>"
                                    placeholder="ESP"
                                    <?php echo $isProtectedGra ? 'readonly data-protected-code="GRA"' : ''; ?>
                                >
                            </td>
                            <td>
                                <input
                                    type="text"
                                    name="pm_label[]"
                                    value="<?php echo htmlspecialchars($label); ?>"
                                    placeholder="Espèces"
                                >
                            </td>
                            <td style="text-align:center;">
                                <?php if ($isProtectedGra): ?>
                                    <button type="button" class="delete-btn" disabled title="Le mode GRA / Gratuit est protégé">🔒</button>
                                <?php else: ?>
                                    <button type="button" class="delete-btn" onclick="deletePaymentModeRow(this)">🗑</button>
                                <?php endif; ?>
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
            <?php if ($canWrite): ?>
                <button type="submit" name="save_settings" value="1" class="btn-success">
                    Enregistrer les paramètres
                </button>
            <?php else: ?>
                <span class="chip">Lecture seule : modification réservée aux droits en écriture</span>
            <?php endif; ?>

            <a href="index.php" class="back-link">← Retour à la liste des engagements</a>
        </div>
    </form>
</div>

<script>const registrarcAllowedRuleScopes = <?php echo json_encode($allowedRuleScopes, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?php echo registrarc_asset_url('assets/js/config_tarifs.js'); ?>"></script>

<?php include('Common/Templates/tail.php'); ?>