<?php
// ============================================================================
// Greffe2 - Configuration des tarifs et modes de paiement (par concours)
// Tarifs de base + règles avancées (simple) + modes de paiement
// Interface modernisée et responsive
// ============================================================================

define('debug', false);

// /modules/custom/Greffe2/ → remonter 3 niveaux vers la racine Ianseo
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$TourId = isset($_GET['ToId']) ? intval($_GET['ToId']) : (isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0);
if (!$TourId) {
    die('Tournoi non défini');
}

// ---------------------------------------------------------------------------
// Fonctions utilitaires pour paramètres JSON
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

function greffe2_save_tarifs($TourId, array $tarifs) {
    $paramName = 'Tarifs_' . intval($TourId);
    $data = [
        'clubs_autres' => [
            'jeunes'  => [
                1 => (int)$tarifs['clubs_autres']['jeunes'][1],
                2 => (int)$tarifs['clubs_autres']['jeunes'][2],
            ],
            'adultes' => [
                1 => (int)$tarifs['clubs_autres']['adultes'][1],
                2 => (int)$tarifs['clubs_autres']['adultes'][2],
            ],
        ],
        'organizer' => [
            'jeunes'  => [
                1 => (int)$tarifs['organizer']['jeunes'][1],
                2 => (int)$tarifs['organizer']['jeunes'][2],
            ],
            'adultes' => [
                1 => (int)$tarifs['organizer']['adultes'][1],
                2 => (int)$tarifs['organizer']['adultes'][2],
            ],
        ],
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    setModuleParameter('Greffe2', $paramName, $json);
}

function greffe2_load_payment_modes($TourId) {
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

function greffe2_save_payment_modes($TourId, array $modes) {
    $paramName = 'PaymentModes_' . intval($TourId);
    $clean = [];
    foreach ($modes as $code => $label) {
        $code  = strtoupper(trim($code));
        $label = trim($label);
        if ($code === '' || $label === '') continue;
        $clean[$code] = $label;
    }
    if (empty($clean)) {
        $clean = [
            'ESPECE'   => 'Espèce',
            'CHEQUE'   => 'Chèque',
            'VIREMENT' => 'Virement',
            'GRATUIT'  => 'Gratuit',
        ];
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    setModuleParameter('Greffe2', $paramName, $json);
}

// Règles avancées
function greffe2_load_tarif_rules($TourId) {
    $paramName = 'TarifsRules_' . intval($TourId);
    $json      = getModuleParameter('Greffe2', $paramName, '');
    if ($json) {
        $data = json_decode($json, true);
        if (is_array($data)) {
            return $data;
        }
    }
    return [];
}

function greffe2_save_tarif_rules($TourId, array $rules) {
    $paramName = 'TarifsRules_' . intval($TourId);
    $clean = [];
    foreach ($rules as $r) {
        if (empty($r['label'])) continue;
        if (empty($r['scope'])) continue;
        if (empty($r['match'])) continue;
        $clean[] = $r;
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    setModuleParameter('Greffe2', $paramName, $json);
}

// ---------------------------------------------------------------------------
// Infos tournoi / club organisateur
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
            WHERE CoCode = '" . $organizerClubCode . "'
              AND CoTournament = $TourId
        ";
        $organizerNameRs = safe_r_sql($organizerNameQuery);
        if ($on = safe_fetch($organizerNameRs)) {
            $organizerClubName = $on->CoName;
        }
    }
}

// ---------------------------------------------------------------------------
// Traitement POST
// ---------------------------------------------------------------------------
$message     = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- Tarifs de base ----
    $tarifs = [
        'clubs_autres' => [
            'jeunes'  => [1 => 0, 2 => 0],
            'adultes' => [1 => 0, 2 => 0],
        ],
        'organizer' => [
            'jeunes'  => [1 => 0, 2 => 0],
            'adultes' => [1 => 0, 2 => 0],
        ],
    ];

    $OJA1 = isset($_POST['OJA1']) ? (int)$_POST['OJA1'] : 0;
    $OJA2 = isset($_POST['OJA2']) ? (int)$_POST['OJA2'] : 0;
    $OAA1 = isset($_POST['OAA1']) ? (int)$_POST['OAA1'] : 0;
    $OAA2 = isset($_POST['OAA2']) ? (int)$_POST['OAA2'] : 0;

    $PJA1 = isset($_POST['PJA1']) ? (int)$_POST['PJA1'] : 0;
    $PJA2 = isset($_POST['PJA2']) ? (int)$_POST['PJA2'] : 0;
    $PAA1 = isset($_POST['PAA1']) ? (int)$_POST['PAA1'] : 0;
    $PAA2 = isset($_POST['PAA2']) ? (int)$_POST['PAA2'] : 0;

    $tarifs['clubs_autres']['jeunes'][1]  = $OJA1;
    $tarifs['clubs_autres']['jeunes'][2]  = $OJA1 + $OJA2;
    $tarifs['clubs_autres']['adultes'][1] = $OAA1;
    $tarifs['clubs_autres']['adultes'][2] = $OAA1 + $OAA2;

    $tarifs['organizer']['jeunes'][1]  = $PJA1;
    $tarifs['organizer']['jeunes'][2]  = $PJA1 + $PJA2;
    $tarifs['organizer']['adultes'][1] = $PAA1;
    $tarifs['organizer']['adultes'][2] = $PAA1 + $PAA2;

    // ---- Modes de paiement ----
    $modesCodes  = isset($_POST['pm_code'])  && is_array($_POST['pm_code'])  ? $_POST['pm_code']  : [];
    $modesLabels = isset($_POST['pm_label']) && is_array($_POST['pm_label']) ? $_POST['pm_label'] : [];

    $modes = [];
    foreach ($modesCodes as $idx => $code) {
        $code  = strtoupper(trim($code));
        $label = isset($modesLabels[$idx]) ? trim($modesLabels[$idx]) : '';
        if ($code === '' && $label === '') continue;
        if ($code === '') continue;
        $modes[$code] = ($label !== '' ? $label : $code);
    }

    // ---- Règles avancées ----
    $rules = [];
    if (!empty($_POST['rule_label']) && is_array($_POST['rule_label'])) {
        $labels  = $_POST['rule_label'];
        $actives = isset($_POST['rule_active']) ? $_POST['rule_active'] : [];
        $scopes  = isset($_POST['rule_scope'])  ? $_POST['rule_scope']  : [];
        $matches = isset($_POST['rule_match'])  ? $_POST['rule_match']  : [];
        $values  = isset($_POST['rule_value'])  ? $_POST['rule_value']  : [];

        foreach ($labels as $idx => $label) {
            $label = trim($label);
            if ($label === '') continue;

            $scope    = $scopes[$idx] ?? '';
            $matchStr = $matches[$idx] ?? '';
            $matchArr = array_filter(array_map('trim', explode(',', $matchStr)));
            $value    = $values[$idx] ?? '0';

            if ($scope === '' || empty($matchArr)) continue;

            $rules[] = [
                'id'     => 'R' . ($idx + 1),
                'label'  => $label,
                'active' => !empty($actives[$idx]),
                'scope'  => $scope,
                'match'  => $matchArr,
                'action' => [
                    'type'  => 'fixed_price',
                    'value' => (float)$value,
                ],
            ];
        }
    }

    try {
        greffe2_save_tarifs($TourId, $tarifs);
        greffe2_save_payment_modes($TourId, $modes);
        greffe2_save_tarif_rules($TourId, $rules);
        $message     = "Paramètres enregistrés pour le concours n° $TourId.";
        $messageType = 'success';
    } catch (Exception $e) {
        $message     = "Erreur lors de l'enregistrement : " . $e->getMessage();
        $messageType = 'error';
    }
}

// ---------------------------------------------------------------------------
// Chargement des valeurs actuelles
// ---------------------------------------------------------------------------
$currentTarifs = greffe2_load_tarifs($TourId, $organizerClubCode, $organizerClubName);
$currentModes  = greffe2_load_payment_modes($TourId);
$currentRules  = greffe2_load_tarif_rules($TourId);

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
$PAGE_TITLE    = "Greffe2 - Paramètres tarifs / paiements";
$IncludeJquery = true;
include('Common/Templates/head.php');
?>

<style>
    :root {
        --primary-color:   #2563eb;
        --primary-dark:    #1d4ed8;
        --success-color:   #16a34a;
        --danger-color:    #dc2626;
        --bg-soft:         #f3f4f6;
        --border-radius:   6px;
        --shadow-soft:     0 4px 12px rgba(15, 23, 42, 0.08);
        --text-muted:      #6b7280;
        --text-strong:     #111827;
    }

    body {
        background: #f9fafb;
    }

    .greffe-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 16px;
        box-sizing: border-box;
    }

    .greffe-header {
        margin-bottom: 16px;
    }

    .greffe-header-title {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-strong);
    }

    .greffe-header-sub {
        font-size: 13px;
        color: var(--text-muted);
        margin-top: 4px;
    }

    .greffe-header-sub strong {
        color: var(--text-strong);
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

    .grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0,1fr));
        gap: 8px;
    }

    @media (max-width: 768px) {
        .grid-2 {
            grid-template-columns: 1fr;
        }
    }

    .btn-primary,
    .btn-success,
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

    .tarif-table,
    .rules-table,
    .pm-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .tarif-table th,
    .tarif-table td,
    .rules-table th,
    .rules-table td,
    .pm-table th,
    .pm-table td {
        padding: 6px 8px;
        border-bottom: 1px solid #e5e7eb;
        text-align: left;
    }

    .tarif-table thead,
    .rules-table thead,
    .pm-table thead {
        background: #f9fafb;
    }

    .tarif-table th,
    .rules-table th,
    .pm-table th {
        font-size: 12px;
        color: var(--text-muted);
        font-weight: 600;
    }

    .tarif-input,
    .rules-table input[type="text"],
    .rules-table input[type="number"],
    .rules-table select,
    .pm-table input[type="text"] {
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

    .rules-table input[type="number"] {
        text-align: right;
    }

    .rules-delete-btn,
    .pm-delete-btn {
        border: none;
        background: none;
        color: #dc2626;
        cursor: pointer;
        font-size: 16px;
    }

    .section-footer {
        margin-top: 10px;
        font-size: 12px;
        color: var(--text-muted);
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

    .back-link:hover {
        text-decoration: underline;
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
</style>

<div class="greffe-container">
    <div class="greffe-header">
        <div class="greffe-header-title">Paramétrage des tarifs & paiements</div>
        <div class="greffe-header-sub">
            Concours :
            <strong><?php echo htmlspecialchars($tournamentName ?: "ToId $TourId"); ?></strong>
            <?php if ($organizerClubName || $organizerClubCode): ?>
                – Club organisateur :
                <strong><?php echo htmlspecialchars($organizerClubName ?: $organizerClubCode); ?></strong>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <!-- TARIFS DE BASE -->
        <div class="card">
            <div class="card-title">Tarifs d'inscription (base)</div>
            <div class="card-help">
                Montants en euros. Le 2<sup>e</sup> départ est calculé comme :
                <span class="chip">1er départ + supplément</span>. À partir du 3<sup>e</sup> départ,
                le même supplément que pour le 2<sup>e</sup> départ est appliqué.
            </div>

            <div class="grid-2">
                <div>
                    <div style="font-weight:600;margin-bottom:4px;">Clubs extérieurs</div>
                    <table class="tarif-table">
                        <thead>
                            <tr>
                                <th>Catégorie</th>
                                <th style="text-align:center;">1er départ</th>
                                <th style="text-align:center;">Suppl. 2e départ</th>
                                <th style="text-align:center;">Total 2 départs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Jeunes (U11 à U18)</td>
                                <td style="text-align:center;">
                                    <input type="number" name="OJA1" value="<?php echo $OJA1; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;">
                                    <input type="number" name="OJA2" value="<?php echo $OJA2; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;"><?php echo $OJA1 + $OJA2; ?> €</td>
                            </tr>
                            <tr>
                                <td>Adultes (U21, S1, S2, S3)</td>
                                <td style="text-align:center;">
                                    <input type="number" name="OAA1" value="<?php echo $OAA1; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;">
                                    <input type="number" name="OAA2" value="<?php echo $OAA2; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;"><?php echo $OAA1 + $OAA2; ?> €</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div>
                    <div style="font-weight:600;margin-bottom:4px;">
                        Club organisateur
                        <?php if ($organizerClubName || $organizerClubCode): ?>
                            <span style="font-size:11px;color:var(--text-muted);">
                                (<?php echo htmlspecialchars($organizerClubName ?: $organizerClubCode); ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                    <table class="tarif-table">
                        <thead>
                            <tr>
                                <th>Catégorie</th>
                                <th style="text-align:center;">1er départ</th>
                                <th style="text-align:center;">Suppl. 2e départ</th>
                                <th style="text-align:center;">Total 2 départs</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Jeunes (U11 à U18)</td>
                                <td style="text-align:center;">
                                    <input type="number" name="PJA1" value="<?php echo $PJA1; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;">
                                    <input type="number" name="PJA2" value="<?php echo $PJA2; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;"><?php echo $PJA1 + $PJA2; ?> €</td>
                            </tr>
                            <tr>
                                <td>Adultes (U21, S1, S2, S3)</td>
                                <td style="text-align:center;">
                                    <input type="number" name="PAA1" value="<?php echo $PAA1; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;">
                                    <input type="number" name="PAA2" value="<?php echo $PAA2; ?>"
                                           min="0" step="1" class="tarif-input">
                                </td>
                                <td style="text-align:center;"><?php echo $PAA1 + $PAA2; ?> €</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section-footer">
                Ces tarifs de base sont utilisés sur la page de paiement des archers.  
                Ils peuvent être surchargés par les règles avancées ci‑dessous.
            </div>
        </div>

        <!-- RÈGLES AVANCÉES -->
        <div class="card">
            <div class="card-title">Règles avancées de tarification</div>
            <div class="card-help">
                Chaque règle définit un <strong>prix fixe</strong> pour les archers correspondant à un critère :
                catégorie, région/département du club, ou participation aux finales.  
                La première règle active qui matche est appliquée.
            </div>

            <table class="rules-table">
                <thead>
                    <tr>
                        <th style="width:50px;">Actif</th>
                        <th>Nom de la règle</th>
                        <th style="width:180px;">Portée</th>
                        <th>Valeurs à matcher</th>
                        <th style="width:100px;">Prix fixe (€)</th>
                        <th style="width:40px;">Suppr.</th>
                    </tr>
                </thead>
                <tbody id="rulesTableBody">
                    <?php
                    $rulesRows = $currentRules;
                    // Ligne vide pour faciliter l'ajout
                    $rulesRows[] = [
                        'label'  => '',
                        'active' => false,
                        'scope'  => 'categorie',
                        'match'  => [],
                        'action' => ['type' => 'fixed_price', 'value' => ''],
                    ];
                    foreach ($rulesRows as $idx => $r):
                        $label = $r['label'] ?? '';
                        $active= !empty($r['active']);
                        $scope = $r['scope'] ?? 'categorie';
                        $match = isset($r['match']) && is_array($r['match']) ? implode(',', $r['match']) : '';
                        $value = isset($r['action']['value']) ? $r['action']['value'] : '';
                    ?>
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" name="rule_active[<?php echo $idx; ?>]" value="1" <?php echo $active ? 'checked' : ''; ?>>
                        </td>
                        <td>
                            <input type="text" name="rule_label[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($label); ?>">
                        </td>
                        <td>
                            <select name="rule_scope[<?php echo $idx; ?>]">
                                <option value="categorie"   <?php echo ($scope==='categorie'   ? 'selected' : ''); ?>>Catégorie</option>
                                <option value="region"      <?php echo ($scope==='region'      ? 'selected' : ''); ?>>Région (2 premiers chiffres)</option>
                                <option value="departement" <?php echo ($scope==='departement' ? 'selected' : ''); ?>>Département (4 premiers chiffres)</option>
                                <option value="finales"     <?php echo ($scope==='finales'     ? 'selected' : ''); ?>>Finales (au moins une)</option>
                                <option value="finales_ind" <?php echo ($scope==='finales_ind' ? 'selected' : ''); ?>>Finales individuelles</option>
                                <option value="finales_team"<?php echo ($scope==='finales_team'? 'selected' : ''); ?>>Finales par équipe</option>
                                <option value="finales_mix" <?php echo ($scope==='finales_mix' ? 'selected' : ''); ?>>Finales doubles mixtes</option>
                            </select>
                        </td>
                        <td>
                            <input type="text" name="rule_match[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($match); ?>"
                                   placeholder="ex: CL,CO ou 34%,30% ou OUI">
                        </td>
                        <td>
                            <input type="number" name="rule_value[<?php echo $idx; ?>]" value="<?php echo htmlspecialchars($value); ?>"
                                   step="0.01">
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="rules-delete-btn" onclick="deleteRuleRow(this)" title="Supprimer cette règle">
                                🗑
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="form-footer">
                <button type="button" class="btn-ghost" onclick="addRuleRow()">+ Ajouter une règle</button>
                <div style="font-size:12px;color:var(--text-muted);">
                    Exemple : portée <strong>Catégorie</strong>, valeurs <code>CL,CO</code>, prix fixe <code>15</code> → toutes les
                    catégories contenant CL ou CO seront à 15 €.
                </div>
            </div>
        </div>

        <!-- MODES DE PAIEMENT -->
        <div class="card">
            <div class="card-title">Modes de paiement</div>
            <div class="card-help">
                Les codes sont stockés dans <code>QuNotes</code> au format <code>PAYE|CODE</code>.  
                Le mode <strong>GRATUIT</strong> peut être utilisé pour fixer un montant à 0 sur la page de paiement.
            </div>

            <table class="pm-table">
                <thead>
                    <tr>
                        <th style="width:25%;">Code</th>
                        <th>Libellé affiché</th>
                        <th style="width:40px;">Suppr.</th>
                    </tr>
                </thead>
                <tbody id="pmTableBody">
                    <?php
                    $rows = $currentModes;
                    // Une seule ligne vide par défaut (au lieu de deux)
                    $rows[''] = '';
                    foreach ($rows as $code => $label):
                        $code  = trim($code);
                        $label = trim($label);
                    ?>
                    <tr>
                        <td>
                            <input type="text" name="pm_code[]" value="<?php echo htmlspecialchars($code); ?>"
                                   placeholder="ESPECE, CHEQUE, VIREMENT…">
                        </td>
                        <td>
                            <input type="text" name="pm_label[]" value="<?php echo htmlspecialchars($label); ?>"
                                   placeholder="Libellé affiché (ex. Espèce, Chèque…)">
                        </td>
                        <td style="text-align:center;">
                            <button type="button" class="pm-delete-btn" onclick="deletePaymentModeRow(this)" title="Supprimer ce mode">
                                🗑
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="form-footer">
                <button type="button" class="btn-ghost" onclick="addPaymentModeRow()">+ Ajouter un mode</button>
                <div style="font-size:12px;color:var(--text-muted);">
                    Code conseillé pour le gratuit : <code>GRATUIT</code>.  
                    Il sera interprété comme un montant 0 € dans la page de paiements.
                </div>
            </div>
        </div>

        <div class="form-footer">
            <button type="submit" class="btn-success">Enregistrer les paramètres</button>
            <a href="index.php" class="back-link">← Retour à la liste des archers</a>
        </div>
    </form>
</div>

<script>
function addPaymentModeRow() {
    const tbody = document.getElementById('pmTableBody');
    if (!tbody) return;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="text" name="pm_code[]" value=""
                   placeholder="ESPECE, CHEQUE, VIREMENT…">
        </td>
        <td>
            <input type="text" name="pm_label[]" value=""
                   placeholder="Libellé affiché (ex. Espèce, Chèque…)">
        </td>
        <td style="text-align:center;">
            <button type="button" class="pm-delete-btn" onclick="deletePaymentModeRow(this)" title="Supprimer ce mode">
                🗑
            </button>
        </td>
    `;
    tbody.appendChild(tr);
}

function deletePaymentModeRow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    tr.parentNode.removeChild(tr);
}

function addRuleRow() {
    const tbody = document.getElementById('rulesTableBody');
    if (!tbody) return;
    const index = tbody.querySelectorAll('tr').length;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="text-align:center;">
            <input type="checkbox" name="rule_active[${index}]" value="1">
        </td>
        <td>
            <input type="text" name="rule_label[${index}]" value="">
        </td>
        <td>
            <select name="rule_scope[${index}]">
                <option value="categorie">Catégorie</option>
                <option value="region">Région (2 premiers chiffres)</option>
                <option value="departement">Département (4 premiers chiffres)</option>
                <option value="finales">Finales (au moins une)</option>
                <option value="finales_ind">Finales individuelles</option>
                <option value="finales_team">Finales par équipe</option>
                <option value="finales_mix">Finales doubles mixtes</option>
            </select>
        </td>
        <td>
            <input type="text" name="rule_match[${index}]" value=""
                   placeholder="ex: CL,CO ou 34%,30% ou OUI">
        </td>
        <td>
            <input type="number" name="rule_value[${index}]" value=""
                   step="0.01">
        </td>
        <td style="text-align:center;">
            <button type="button" class="rules-delete-btn" onclick="deleteRuleRow(this)" title="Supprimer cette règle">
                🗑
            </button>
        </td>
    `;
    tbody.appendChild(tr);
}

function deleteRuleRow(btn) {
    const tr = btn.closest('tr');
    if (!tr) return;
    tr.parentNode.removeChild(tr);
}
</script>

<?php include('Common/Templates/tail.php'); ?>