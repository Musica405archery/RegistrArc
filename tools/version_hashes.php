<?php
// ============================================================================
// RegistrArc - tools/version_hashes.php
// À lancer avant de publier une version (depuis la racine du dépôt) :
//   php tools/version_hashes.php
//
// - vérifie que chaque fichier de "files" existe et qu'aucun fichier du module
//   suivi par git n'a été oublié dans la liste
// - écrit dans version.json l'empreinte SHA-256 (sha256) de chaque fichier,
//   telle que GitHub le servira : contenu normalisé par git (fins de ligne du
//   dépôt), et non la copie de travail Windows en CRLF
//
// Les installations refusent une mise à jour dont un fichier ne correspond pas.
// Ce dossier tools/ n'est pas distribué aux installations.
// ============================================================================

// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(dirname(__FILE__));
$versionFile = $root . '/version.json';
$version = json_decode((string)file_get_contents($versionFile), true);

if (!is_array($version) || empty($version['files']) || !is_array($version['files'])) {
    fwrite(STDERR, "version.json illisible ou sans liste de fichiers.\n");
    exit(1);
}

function run_git($root, $args) {
    $out = [];
    $code = 0;
    exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1', $out, $code);

    return [$code, $out];
}

// Contenu d'un objet git, lu en binaire (images comprises)
function git_blob($root, $oid) {
    $proc = proc_open(
        'git -C ' . escapeshellarg($root) . ' cat-file blob ' . escapeshellarg($oid),
        [1 => ['pipe', 'wb'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (!is_resource($proc)) {
        return null;
    }

    $content = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($proc) === 0 ? $content : null;
}

// Fichiers du module suivis par git, hors documentation technique, tests et outils
// (les captures de docs/images/ sont distribuées : le mode d'emploi les affiche)
[$code, $tracked] = run_git($root, 'ls-files');

if ($code !== 0) {
    fwrite(STDERR, "git ls-files a échoué : " . implode("\n", $tracked) . "\n");
    exit(1);
}

$errors = [];
$listed = array_values(array_unique($version['files']));

foreach ($tracked as $rel) {
    if (preg_match('#^(docs/(?!images/)|tests/|tools/|\.git)#', $rel) || $rel === 'module.json') {
        continue;
    }

    if (!in_array($rel, $listed, true)) {
        $errors[] = "$rel est suivi par git mais absent de \"files\" : il ne serait pas installé.";
    }
}

$hashes = [];

foreach ($listed as $rel) {
    if ($rel === 'version.json') {
        continue;
    }

    if (!is_file($root . '/' . $rel)) {
        $errors[] = "$rel est listé dans \"files\" mais n'existe pas.";
        continue;
    }

    // Un fichier ignoré par git ne serait jamais publié : la mise à jour échouerait
    [$code] = run_git($root, 'check-ignore -q --no-index -- ' . escapeshellarg($rel));

    if ($code === 0) {
        $errors[] = "$rel est ignoré par .gitignore : il ne serait pas publié sur GitHub.";
        continue;
    }

    // hash-object -w applique la normalisation de git puis range le contenu,
    // cat-file le relit exactement comme il sera publié
    // (git peut aussi écrire un avertissement sur les fins de ligne : seul l'identifiant compte)
    [$code, $out] = run_git($root, 'hash-object -w -- ' . escapeshellarg($rel));
    $oid = preg_grep('/^[0-9a-f]{40,64}$/', array_map('trim', $out));

    if ($code !== 0 || empty($oid)) {
        $errors[] = "$rel : git hash-object a échoué (" . implode(' ', $out) . ").";
        continue;
    }

    $content = git_blob($root, reset($oid));

    if (!is_string($content)) {
        $errors[] = "$rel : lecture du contenu normalisé impossible.";
        continue;
    }

    $hashes[$rel] = hash('sha256', $content);
}

if (!empty($errors)) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

ksort($hashes);
$version['sha256'] = $hashes;

$json = json_encode($version, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($versionFile, $json);

echo count($hashes) . " empreintes écrites dans version.json (version " . $version['version'] . ").\n";
