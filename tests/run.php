<?php
// ============================================================================
// RegistrArc - tests/run.php
// Lance tous les tests (tests/test_*.php), sans I@nseo ni base de données :
//   php tests/run.php
// Avec le PHP fourni par I@nseo sous Windows :
//   C:\ianseo\php\php.exe -n -d extension_dir=C:\ianseo\php\ext -d extension=mbstring tests\run.php
// Ce dossier n'est pas distribué aux installations.
// ============================================================================

// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once(dirname(__FILE__) . '/bootstrap.php');

foreach (glob(dirname(__FILE__) . '/test_*.php') as $file) {
    require $file;
}

test_cleanup();

$failed = $GLOBALS['test_results']['failed'];
echo "\n\n" . $GLOBALS['test_results']['passed'] . " test(s) réussi(s), " . count($failed) . " échec(s).\n";

foreach ($failed as $f) {
    echo "\nÉCHEC : " . $f . "\n";
}

exit(empty($failed) ? 0 : 1);
