<?php
// ============================================================================
// RegistrArc - bankchequedeposite.php
// Remise de chèque
//
// Cas possibles :
// - bankchequedeposite.php
//   Tous les groupes de chèques, puis les paiements CHQ non groupés.
// - bankchequedeposite.php?ids=123,124
//   Paiements sélectionnés PAYE en CHQ.
// - bankchequedeposite.php?group=CG-...
//   Groupe de chèque enregistré dans data/cheque_groups_<TourId>.json.
//
// Paiements lus depuis : data/payments_<TourId>.json
// Groupes lus depuis   : data/cheque_groups_<TourId>.json
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
// Lecture paramètres
// ---------------------------------------------------------------------------
$groupId = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
$idsParam = isset($_GET['ids']) ? trim((string)$_GET['ids']) : '';

$selectedIds = [];

if ($idsParam !== '') {
    foreach (explode(',', $idsParam) as $id) {
        $id = intval(trim($id));

        if ($id > 0) {
            $selectedIds[] = $id;
        }
    }

    $selectedIds = array_values(array_unique($selectedIds));
}

$isGroupMode = ($groupId !== '');
$isSelectionMode = !$isGroupMode && !empty($selectedIds);
$isAllMode = !$isGroupMode && !$isSelectionMode;

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

function registrarc_cheque_groups_file_path($TourId) {
    return registrarc_data_dir() . '/cheque_groups_' . intval($TourId) . '.json';
}

// ---------------------------------------------------------------------------
// JSON
// ---------------------------------------------------------------------------
function registrarc_load_json_file($file) {
    if (!is_file($file)) {
        return [];
    }

    $json = file_get_contents($file);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function registrarc_load_payments($TourId) {
    return registrarc_load_json_file(registrarc_payments_file_path($TourId));
}

function registrarc_load_cheque_groups($TourId) {
    return registrarc_load_json_file(registrarc_cheque_groups_file_path($TourId));
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
            if (empty($data['tarifs']) || !is_array($data['tarifs'])) {
                $data['tarifs'] = registrarc_default_tarifs();
            }

            if (empty($data['payment_modes']) || !is_array($data['payment_modes'])) {
                $data['payment_modes'] = registrarc_default_payment_modes();
            }

            if (empty($data['rules']) || !is_array($data['rules'])) {
                $data['rules'] = [];
            }

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
        'label'   => implode(' + ', $labels),
        'amount'  => $total,
        'count'   => $matchedCount,
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

    return ($numeroEngagement <= 1) ? $p1 : ($p2 - $p1);
}

function registrarc_format_montant($montant) {
    $montant = floatval($montant);

    if (floor($montant) == $montant) {
        return intval($montant);
    }

    return number_format($montant, 2, ',', ' ');
}

// ---------------------------------------------------------------------------
// Dates / tournoi
// ---------------------------------------------------------------------------
function registrarc_format_date_fr($date) {
    $date = trim((string)$date);

    if ($date === '' || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);

    return $ts ? date('d/m/Y', $ts) : $date;
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

function registrarc_get_tournament_info($TourId) {
    $TourId = intval($TourId);

    $data = [
        'name' => 'Concours n° ' . $TourId,
        'where' => '',
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
// Engagements
// ---------------------------------------------------------------------------
function registrarc_get_cheque_engagements($TourId, array $selectedIds) {
    $TourId = intval($TourId);
    $whereIds = '';

    if (!empty($selectedIds)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $selectedIds))));

        if (!empty($ids)) {
            $whereIds = ' AND e.EnId IN (' . implode(',', $ids) . ') ';
        }
    }

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
          $whereIds
        ORDER BY c.CoName, UPPER(TRIM(e.EnFirstName)), TRIM(e.EnName), q.QuSession, q.QuTarget, q.QuLetter, e.EnId
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

function registrarc_build_cheque_rows(array $rows, array $payments, array $paymentModes, array $tarifs, $organizerClubCode, array $engagementNumbers, array $tarifRules) {
    $result = [];

    foreach ($rows as $row) {
        $engagementId = intval($row['engagement_id']);

        $payment = isset($payments[(string)$engagementId]) && is_array($payments[(string)$engagementId])
            ? $payments[(string)$engagementId]
            : [];

        $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : 'NON_PAYE';
        $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';

        if ($status !== 'PAYE' || $method !== 'CHQ') {
            continue;
        }

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
                $organizerClubCode
            );

            $ruleLabel = '';
            $tarifTypeLabel = 'Standard';
            $tarifTypeClass = 'tarif-standard';
        }

        $row['numero_engagement'] = $numeroEngagement;
        $row['session_label'] = $row['session_no'] !== '' ? 'Départ ' . $row['session_no'] : '—';
        $row['target_label'] = $targetLabel;
        $row['payment_method'] = $method;
        $row['payment_method_label'] = isset($paymentModes[$method]) ? $paymentModes[$method] : $method;
        $row['tarif_rule_label'] = $ruleLabel;
        $row['tarif_type_label'] = $tarifTypeLabel;
        $row['tarif_type_class'] = $tarifTypeClass;
        $row['amount'] = registrarc_is_free_method($method) ? 0 : $amountBase;

        $result[] = $row;
    }

    return $result;
}

function registrarc_sum_rows(array $rows) {
    $total = 0;

    foreach ($rows as $row) {
        $total += isset($row['amount']) ? floatval($row['amount']) : 0;
    }

    return $total;
}

function registrarc_get_group_ids_map(array $groups) {
    $map = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $ids = isset($group['engagement_ids']) && is_array($group['engagement_ids'])
            ? $group['engagement_ids']
            : [];

        foreach ($ids as $id) {
            $id = intval($id);

            if ($id > 0) {
                $map[$id] = true;
            }
        }
    }

    return $map;
}

function registrarc_filter_rows_not_in_map(array $rows, array $groupedIdsMap) {
    $out = [];

    foreach ($rows as $row) {
        $id = isset($row['engagement_id']) ? intval($row['engagement_id']) : 0;

        if ($id > 0 && isset($groupedIdsMap[$id])) {
            continue;
        }

        $out[] = $row;
    }

    return $out;
}

function registrarc_render_entries_table(array $rows) {
    ?>
    <table>
        <thead>
            <tr>
                <th style="width:7%;">Eng.</th>
                <th style="width:11%;">Licence</th>
                <th style="width:17%;">Archer</th>
                <th style="width:22%;">Club</th>
                <th style="width:8%;">Cat.</th>
                <th style="width:8%;">Départ</th>
                <th style="width:8%;">Cible</th>
                <th style="width:8%;" class="right">Montant</th>
                <th style="width:11%;">Tarif</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="entry-row">
                    <td class="center">n° <?php echo intval($row['numero_engagement']); ?></td>
                    <td><?php echo htmlspecialchars($row['licence']); ?></td>
                    <td><?php echo htmlspecialchars(trim($row['nom'] . ' ' . $row['prenom'])); ?></td>
                    <td><?php echo htmlspecialchars(trim($row['country_code'] . ' - ' . $row['club'])); ?></td>
                    <td><?php echo htmlspecialchars($row['categorie']); ?></td>
                    <td><?php echo htmlspecialchars($row['session_label']); ?></td>
                    <td><?php echo htmlspecialchars($row['target_label']); ?></td>
                    <td class="right"><?php echo registrarc_format_montant($row['amount']); ?> €</td>
                    <td>
                        <span class="<?php echo htmlspecialchars($row['tarif_type_class']); ?>">
                            <?php echo htmlspecialchars($row['tarif_type_label']); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function registrarc_render_manual_fields() {
    ?>
    <div class="manual-fields">
        <div class="manual-field">
            <div class="manual-label">N° chèque</div>
            <div class="manual-write-line"></div>
        </div>

        <div class="manual-field">
            <div class="manual-label">Banque émettrice</div>
            <div class="manual-write-line"></div>
        </div>
    </div>
    <?php
}

function registrarc_group_created_label(array $group) {
    if (empty($group['created_at'])) {
        return '';
    }

    $ts = strtotime($group['created_at']);

    if (!$ts) {
        return trim((string)$group['created_at']);
    }

    return date('d/m/Y H:i', $ts);
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$payments = registrarc_load_payments($TourId);
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifs = registrarc_load_tarifs($TourId, $tournament['organizer_code'], $tournament['organizer_name']);
$tarifRules = registrarc_load_tarif_rules($TourId);
$engagementNumbers = registrarc_get_engagement_numbers($TourId);
$chequeGroups = registrarc_load_cheque_groups($TourId);

$groupData = null;
$groupBlocks = [];
$singleRows = [];

if ($isGroupMode) {
    if (isset($chequeGroups[$groupId]) && is_array($chequeGroups[$groupId])) {
        $groupData = $chequeGroups[$groupId];
        $selectedIds = isset($groupData['engagement_ids']) && is_array($groupData['engagement_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $groupData['engagement_ids']))))
            : [];

        $rawRows = registrarc_get_cheque_engagements($TourId, $selectedIds);

        $rows = registrarc_build_cheque_rows(
            $rawRows,
            $payments,
            $paymentModes,
            $tarifs,
            $tournament['organizer_code'],
            $engagementNumbers,
            $tarifRules
        );

        $groupBlocks[] = [
            'id' => $groupId,
            'data' => $groupData,
            'rows' => $rows,
            'total' => registrarc_sum_rows($rows),
        ];
    }
} elseif ($isSelectionMode) {
    $rawRows = registrarc_get_cheque_engagements($TourId, $selectedIds);

    $singleRows = registrarc_build_cheque_rows(
        $rawRows,
        $payments,
        $paymentModes,
        $tarifs,
        $tournament['organizer_code'],
        $engagementNumbers,
        $tarifRules
    );
} else {
    foreach ($chequeGroups as $id => $group) {
        if (!is_array($group)) {
            continue;
        }

        $ids = isset($group['engagement_ids']) && is_array($group['engagement_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $group['engagement_ids']))))
            : [];

        if (empty($ids)) {
            continue;
        }

        $rawRows = registrarc_get_cheque_engagements($TourId, $ids);

        $rows = registrarc_build_cheque_rows(
            $rawRows,
            $payments,
            $paymentModes,
            $tarifs,
            $tournament['organizer_code'],
            $engagementNumbers,
            $tarifRules
        );

        if (empty($rows)) {
            continue;
        }

        $groupBlocks[] = [
            'id' => $id,
            'data' => $group,
            'rows' => $rows,
            'total' => registrarc_sum_rows($rows),
        ];
    }

    $groupedIdsMap = registrarc_get_group_ids_map($chequeGroups);
    $rawRows = registrarc_get_cheque_engagements($TourId, []);

    $allChequeRows = registrarc_build_cheque_rows(
        $rawRows,
        $payments,
        $paymentModes,
        $tarifs,
        $tournament['organizer_code'],
        $engagementNumbers,
        $tarifRules
    );

    $singleRows = registrarc_filter_rows_not_in_map($allChequeRows, $groupedIdsMap);
}

$total = 0;
$countEntries = 0;
$countCheques = 0;

foreach ($groupBlocks as $block) {
    $total += $block['total'];
    $countEntries += count($block['rows']);
    $countCheques++;
}

foreach ($singleRows as $row) {
    $total += $row['amount'];
    $countEntries++;
    $countCheques++;
}

$documentDate = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Remise de chèque - RegistrArc</title>

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

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 14mm;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .title {
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .subtitle {
            color: #4b5563;
            font-size: 12px;
            line-height: 1.4;
        }

        .meta {
            text-align: right;
            line-height: 1.5;
            font-size: 12px;
        }

        .summary {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }

        .summary-item {
            border-radius: 999px;
            background: #eef2ff;
            color: #3730a3;
            padding: 6px 12px;
            font-weight: 700;
        }

        .section-title {
            font-size: 15px;
            font-weight: 800;
            color: #111827;
            margin: 16px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #d1d5db;
        }

        .group-block {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 14px;
            page-break-inside: avoid;
        }

        .group-title {
            font-size: 14px;
            font-weight: 800;
            margin-bottom: 4px;
            color: #111827;
        }

        .group-subtitle {
            color: #6b7280;
            font-size: 10.5px;
            margin-bottom: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 10px;
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
            color: #374151;
        }

        tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        .empty {
            text-align: center;
            padding: 22px;
            color: #991b1b;
            font-weight: 700;
            border: 1px solid #fecaca;
            background: #fee2e2;
            border-radius: 6px;
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

        .manual-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            align-items: end;
            padding: 8px 0 2px;
        }

        .manual-field {
            min-height: 42px;
        }

        .manual-label {
            font-size: 10px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 14px;
        }

        .manual-write-line {
            height: 24px;
            border-bottom: 1.5px solid #111827;
        }

        .single-entry-manual {
            border: 1px solid #d1d5db;
            border-top: none;
            padding: 8px 10px 12px;
            margin-bottom: 12px;
            page-break-inside: avoid;
        }

        .single-entry-card {
            page-break-inside: avoid;
            margin-bottom: 12px;
        }

        .single-entry-card table {
            margin-bottom: 0;
        }

        .total-line {
            text-align: right;
            font-weight: 800;
            font-size: 13px;
            margin-top: 8px;
        }

        .signature-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-top: 18px;
            page-break-inside: avoid;
        }

        .signature-box {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            min-height: 42px;
            padding: 8px 10px;
        }

        .signature-title {
            font-weight: 700;
            color: #374151;
            margin-bottom: 12px;
        }

        .signature-line {
            border-bottom: 1px solid #6b7280;
            height: 18px;
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

            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 5mm;
                box-shadow: none;
            }

            .actions {
                display: none;
            }

            .header,
            .group-block,
            .single-entry-card,
            .signature-grid {
                break-inside: avoid;
            }

            thead {
                display: table-header-group;
            }

            .manual-write-line {
                border-bottom-color: #000;
            }
        }

        @media (max-width: 700px) {
            .page {
                width: 100%;
                min-height: auto;
                margin: 0;
                padding: 14px;
                box-shadow: none;
            }

            .header,
            .signature-grid,
            .manual-fields {
                display: block;
            }

            .meta {
                text-align: left;
                margin-top: 12px;
            }

            .signature-box,
            .manual-field {
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
    <div class="page">
        <div class="header">
            <div>
                <div class="title">Remise de chèque</div>
                <div class="subtitle">
                    Document interne - Registr'Arc
                </div>
            </div>

            <div class="meta">
                <strong>Date :</strong> <?php echo htmlspecialchars($documentDate); ?>
            </div>
        </div>

        <div class="summary">
            <div class="summary-item">
                Nombre de chèques : <?php echo intval($countCheques); ?>
            </div>

            <div class="summary-item">
                Nombre d'engagements : <?php echo intval($countEntries); ?>
            </div>

            <div class="summary-item">
                Total remise : <?php echo registrarc_format_montant($total); ?> €
            </div>
        </div>

        <?php if ($isGroupMode && !$groupData): ?>
            <div class="empty">
                Groupe de chèque introuvable.
            </div>
        <?php elseif ($countEntries === 0): ?>
            <div class="empty">
                Aucun paiement par chèque trouvé pour ce périmètre.
            </div>
        <?php else: ?>

            <?php if (!empty($groupBlocks)): ?>
                <?php if ($isAllMode): ?>
                    <div class="section-title">Groupes de chèques</div>
                <?php endif; ?>

                <?php foreach ($groupBlocks as $block): ?>
                    <?php
                    $createdLabel = registrarc_group_created_label($block['data']);
                    $entriesCount = count($block['rows']);
                    ?>
                    <div class="group-block">
                        <div class="group-title">
                            Chèque groupé <?php echo htmlspecialchars($block['id']); ?>
                        </div>

                        <?php if ($createdLabel !== ''): ?>
                            <div class="group-subtitle">
                                Créé le <?php echo htmlspecialchars($createdLabel); ?> ·
                                <?php echo intval($entriesCount); ?> engagement(s)
                            </div>
                        <?php else: ?>
                            <div class="group-subtitle">
                                <?php echo intval($entriesCount); ?> engagement(s)
                            </div>
                        <?php endif; ?>

                        <?php registrarc_render_entries_table($block['rows']); ?>

                        <div class="total-line">
                            Total du chèque : <?php echo registrarc_format_montant($block['total']); ?> €
                        </div>

                        <?php registrarc_render_manual_fields(); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($singleRows)): ?>
                <?php if ($isAllMode): ?>
                    <div class="section-title">Chèque paiement individuel</div>
                <?php endif; ?>

                <?php foreach ($singleRows as $row): ?>
                    <div class="single-entry-card">
                        <?php registrarc_render_entries_table([$row]); ?>

                        <div class="single-entry-manual">
                            <div class="total-line">
                                Montant du chèque : <?php echo registrarc_format_montant($row['amount']); ?> €
                            </div>

                            <?php registrarc_render_manual_fields(); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($countEntries > 0): ?>
            <div class="signature-grid">
                <div class="signature-box">
                    <div class="signature-title">Préparé par</div>
                    <div class="signature-line"></div>
                </div>

                <div class="signature-box">
                    <div class="signature-title">Déposé le</div>
                    <div class="signature-line"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="closeDepositTab();">
            Retour
        </button>

        <button type="button" class="btn btn-primary" onclick="window.print();">
            Imprimer / Enregistrer en PDF
        </button>
    </div>

    <script>
        function closeDepositTab() {
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