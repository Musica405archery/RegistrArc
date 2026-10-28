<?php
// ============================================================================
// RegistrArc - tests/bootstrap.php
// Environnement de test sans I@nseo : fonctions SQL et droits remplacés par des
// doublures, données JSON dans un dossier temporaire vidé avant chaque test.
// ============================================================================

// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('mb_substr')) {
    fwrite(STDERR, "L'extension mbstring est nécessaire (php -d extension=mbstring tests/run.php).\n");
    exit(1);
}

define('REGISTRARC_DATA_DIR', sys_get_temp_dir() . '/registrarc_tests_' . getmypid());

define('AclRoot', 'root');
define('AclParticipants', 'participants');
define('AclReadOnly', 1);
define('AclReadWrite', 2);

$_SESSION = [];

// Configuration I@nseo : images de cibles dans un dossier factice
$CFG = (object)['ROOT_DIR' => '/', 'DOCUMENT_PATH' => REGISTRARC_DATA_DIR . '_ianseo/'];
$_POST = [];
$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

// ---------------------------------------------------------------------------
// Base I@nseo simulée : tables Tournament et Entries (avec Countries / Qualifications)
// ---------------------------------------------------------------------------
$GLOBALS['test_db'] = ['tournament' => null, 'entries' => [], 'targetfaces' => [], 'defaultfaces' => []];

function test_set_tournament($organizerCode, $organizerName = 'Club organisateur', $name = 'Concours test') {
    $GLOBALS['test_db']['tournament'] = (object)[
        'ToCommitee' => $organizerCode,
        'ToComDescr' => $organizerName,
        'ToName' => $name,
        'ToWhere' => 'Salle',
        'ToWhenFrom' => '2026-10-17',
        'ToWhenTo' => '2026-10-18',
    ];
}

// Ajoute un départ ; retourne son EnId
function test_add_entry($licence, $categorie, $clubCode, $session = 1, $target = '1', $letter = 'A', array $events = []) {
    $id = count($GLOBALS['test_db']['entries']) + 1;
    $GLOBALS['test_db']['entries'][] = (object)($events + [
        'engagement_id' => $id,
        'licence' => strtoupper($licence),
        'nom' => 'NOM' . $id,
        'prenom' => 'Prénom' . $id,
        'categorie' => $categorie,
        'class_code' => '',
        'age_class' => '',
        'club' => 'Club ' . $clubCode,
        'club_full' => '',
        'country_code' => $clubCode,
        'session_no' => $session,
        'target' => $target,
        'letter' => $letter,
        'EnIndClEvent' => '1',
        'EnTeamClEvent' => '0',
        'EnIndFEvent' => '0',
        'EnTeamFEvent' => '0',
        'EnTeamMixEvent' => '0',
    ]);

    return $id;
}

function safe_r_sql($query) {
    // Blasons : blasons possibles de la catégorie, triés comme I@nseo (premier = par défaut)
    if (strpos($query, 'TfDefault') !== false) {
        return ['rows' => $GLOBALS['test_db']['defaultfaces']];
    }

    // Blasons : jointure Entries / TargetFaces / Tournament
    if (strpos($query, 'TargetFaces') !== false) {
        return ['rows' => $GLOBALS['test_db']['targetfaces']];
    }

    if (strpos($query, 'FROM Tournament') !== false) {
        return ['rows' => $GLOBALS['test_db']['tournament'] ? [$GLOBALS['test_db']['tournament']] : []];
    }

    if (strpos($query, 'FROM Entries') !== false) {
        $rows = $GLOBALS['test_db']['entries'];

        // Même ordre que la requête : licence, départ, cible, EnId
        usort($rows, function ($a, $b) {
            return [$a->licence, $a->session_no, $a->target, $a->letter, $a->engagement_id]
                <=> [$b->licence, $b->session_no, $b->target, $b->letter, $b->engagement_id];
        });

        return ['rows' => $rows];
    }

    return ['rows' => []];
}

function safe_fetch(&$rs) {
    return empty($rs['rows']) ? null : array_shift($rs['rows']);
}

function StrSafe_DB($value) {
    return "'" . addslashes((string)$value) . "'";
}

function getModuleParameter($module, $name, $default = '') {
    return $default;
}

function hasACL($feature, $level) {
    return true;
}

require_once(dirname(dirname(__FILE__)) . '/lib/registrarc.inc.php');
require_once(dirname(dirname(__FILE__)) . '/lib/registrarc_update.inc.php');

// ---------------------------------------------------------------------------
// Mini lanceur de tests
// ---------------------------------------------------------------------------
$GLOBALS['test_results'] = ['passed' => 0, 'failed' => []];

function test_reset() {
    $dir = REGISTRARC_DATA_DIR;

    if (is_dir($dir)) {
        foreach (scandir($dir) as $f) {
            if (is_file($dir . '/' . $f)) {
                unlink($dir . '/' . $f);
            }
        }
    }

    $GLOBALS['test_db'] = ['tournament' => null, 'entries' => [], 'targetfaces' => [], 'defaultfaces' => []];
    $_POST = ['operator' => 'Testeur'];
    test_set_tournament('1034000');
}

function test($name, callable $fn) {
    test_reset();

    try {
        $fn();
        $GLOBALS['test_results']['passed']++;
        echo '.';
    } catch (Throwable $e) {
        $GLOBALS['test_results']['failed'][] = $name . "\n    " . $e->getMessage()
            . "\n    " . basename($e->getFile()) . ':' . $e->getLine();
        echo 'F';
    }
}

function assert_same($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new Exception(($message !== '' ? $message . ' : ' : '')
            . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

// Montant relu depuis le JSON : 10 et 10.0 sont le même montant
function assert_amount($expected, $actual, $message = '') {
    if (!is_numeric($actual) || abs((float)$expected - (float)$actual) > 0.001) {
        throw new Exception(($message !== '' ? $message . ' : ' : '')
            . 'montant attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

function assert_true($value, $message = '') {
    assert_same(true, (bool)$value, $message);
}

// Écrit les paramètres du tournoi de test (tarifs par défaut sauf mention)
function test_settings(array $settings, $TourId = 1) {
    @mkdir(REGISTRARC_DATA_DIR, 0775, true);
    file_put_contents(registrarc_settings_file_path($TourId), json_encode($settings));
}

function test_cleanup() {
    test_reset();
    @rmdir(REGISTRARC_DATA_DIR);
}
