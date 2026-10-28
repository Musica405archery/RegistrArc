<?php
// ============================================================================
// RegistrArc - lib/registrarc_update.inc.php
// Mise à jour du module depuis son dépôt GitHub
//
// - module.json  : dépôt / branche / dossier d'origine. Configuration locale,
//                  jamais écrasée par une mise à jour.
// - version.json : version publiée et liste des fichiers du module (files[]).
//                  Il vient du dépôt : son contenu est contrôlé avant usage.
//
// Dernière version : avec "github_ref": "latest" (valeur par défaut, déclarée
// dans module.json), le module demande à l'API GitHub le dernier commit de la
// branche, puis lit version.json et tous les fichiers à ce commit précis : aucun
// cache de raw.githubusercontent.com, jamais de mélange de deux versions. Si
// l'API ne répond pas (hors ligne, quota), repli sur la branche comme avant.
//
// Vérification : mise en cache dans data/update_check.json pour ne pas
// interroger GitHub à chaque affichage, et rester rapide sans internet.
//
// Installation :
// - tous les fichiers sont d'abord téléchargés et contrôlés dans un dossier
//   temporaire ; rien n'est remplacé si un seul téléchargement échoue
// - chaque fichier doit avoir l'empreinte SHA-256 indiquée dans version.json
//   (sha256), calculée par tools/version_hashes.php au moment de la publication ;
//   un version.json à l'ancien format, sans sha256, reste accepté sans contrôle.
//   Les installations 1.1.0 et avant ignorent sha256 : le format reste compatible
//   (version, date, notes, files inchangés, sha256 en plus)
// - les fichiers remplacés sont sauvegardés dans data/backup_<version>_<date>/
// - version.json est remplacé en dernier
// - data/ et module.json ne sont jamais touchés
// ============================================================================

define('REGISTRARC_UPDATE_TTL_OK', 6 * 3600);
define('REGISTRARC_UPDATE_TTL_ERROR', 1800);

function registrarc_module_dir() {
    return dirname(dirname(__FILE__));
}

// Droit de mettre à jour : administrateur ianseo
function registrarc_can_update() {
    return hasACL(AclRoot, AclReadWrite);
}

// ---------------------------------------------------------------------------
// Configuration et versions
// ---------------------------------------------------------------------------
function registrarc_update_config() {
    $cfg = [
        'github_url' => '',
        'github_branch' => 'main',
        'github_path' => '',
        'github_token' => '',
        // 'latest' : dernier commit de la branche (aucun cache) ; 'branch' : ancienne méthode
        'github_ref' => 'latest',
    ];

    $data = registrarc_storage_read(registrarc_module_dir() . '/module.json');

    return is_array($data) ? array_merge($cfg, $data) : $cfg;
}

// [propriétaire, dépôt] d'après l'adresse GitHub, ou null
function registrarc_update_github_repo(array $cfg) {
    if (!preg_match('#github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#i', trim((string)$cfg['github_url']), $m)) {
        return null;
    }

    return [$m[1], $m[2]];
}

function registrarc_update_branch(array $cfg) {
    return trim((string)$cfg['github_branch']) !== '' ? trim((string)$cfg['github_branch']) : 'main';
}

// Adresse des fichiers bruts, à la branche ou à un commit précis ($ref)
function registrarc_update_raw_base(array $cfg, $ref = '') {
    $repo = registrarc_update_github_repo($cfg);

    if (!$repo) {
        return '';
    }

    $ref = $ref !== '' ? $ref : registrarc_update_branch($cfg);
    $path = trim((string)$cfg['github_path'], '/');

    return 'https://raw.githubusercontent.com/' . $repo[0] . '/' . $repo[1] . '/'
        . str_replace('%2F', '/', rawurlencode($ref))
        . ($path !== '' ? '/' . $path : '');
}

// Dernier commit de la branche (40 caractères), ou '' si désactivé ou injoignable
function registrarc_update_latest_commit(array $cfg, $timeout = 15) {
    $repo = registrarc_update_github_repo($cfg);

    if (!$repo || strtolower(trim((string)$cfg['github_ref'])) !== 'latest') {
        return '';
    }

    $url = 'https://api.github.com/repos/' . $repo[0] . '/' . $repo[1] . '/commits/'
        . str_replace('%2F', '/', rawurlencode(registrarc_update_branch($cfg)));

    // Réponse réduite au seul identifiant du commit
    $res = registrarc_update_http_get($url, (string)$cfg['github_token'], $timeout, ['Accept: application/vnd.github.sha']);
    $sha = strtolower(trim($res['body']));

    return $res['ok'] && preg_match('/^[0-9a-f]{40}$/', $sha) ? $sha : '';
}

function registrarc_local_version() {
    $data = registrarc_storage_read(registrarc_module_dir() . '/version.json');

    return is_array($data) && !empty($data['version']) ? $data : null;
}

function registrarc_local_version_number() {
    $local = registrarc_local_version();

    return $local ? (string)$local['version'] : '0.0.0';
}

// ---------------------------------------------------------------------------
// Téléchargement
// ---------------------------------------------------------------------------
// Retourne ['ok' => bool, 'body' => string, 'error' => string]
function registrarc_update_http_get($url, $token = '', $timeout = 15, array $extraHeaders = []) {
    $headers = array_merge(['User-Agent: RegistrArc-updater'], $extraHeaders);

    if ($token !== '') {
        $headers[] = 'Authorization: token ' . $token;
    }

    $body = false;
    $code = 0;
    $error = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
    }

    // Repli sans cURL, ou si cURL échoue (certificats absents sous Windows...)
    if ($body === false && ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'header' => implode("\r\n", $headers) . "\r\n",
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]]);

        $body = false;
        $code = 0;
        $fp = @fopen($url, 'r', false, $ctx);

        if ($fp !== false) {
            $body = stream_get_contents($fp);
            $meta = stream_get_meta_data($fp);
            fclose($fp);

            $responseHeaders = isset($meta['wrapper_data']) && is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : [];

            foreach ($responseHeaders as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int)$m[1];
                }
            }
        }
    }

    if ($body === false) {
        return ['ok' => false, 'body' => '', 'error' => "GitHub injoignable" . ($error !== '' ? " ($error)" : '') . "."];
    }

    // Une erreur HTTP renvoie aussi un contenu ("404: Not Found") : ne jamais l'utiliser
    if ($code !== 200) {
        return ['ok' => false, 'body' => '', 'error' => "Réponse HTTP $code pour " . basename($url) . "."];
    }

    return ['ok' => true, 'body' => (string)$body, 'error' => ''];
}

// Retourne le version.json distant validé, ou ['_error' => message].
// '_commit' : commit lu ('' si lu à la branche) ; '_base' : adresse des fichiers
// de ce même commit, à utiliser pour toute l'installation
function registrarc_update_fetch_remote(array $cfg, $timeout = 15) {
    $commit = registrarc_update_latest_commit($cfg, $timeout);
    $base = registrarc_update_raw_base($cfg, $commit);

    if ($base === '') {
        return ['_error' => "module.json : adresse du dépôt GitHub invalide."];
    }

    $res = registrarc_update_http_get($base . '/version.json', (string)$cfg['github_token'], $timeout);

    if (!$res['ok']) {
        return ['_error' => $res['error']];
    }

    $data = json_decode($res['body'], true);

    if (!is_array($data) || empty($data['version']) || !preg_match('/^\d+(\.\d+){0,3}$/', (string)$data['version'])) {
        return ['_error' => "version.json distant illisible ou sans numéro de version valide."];
    }

    if (empty($data['files']) || !is_array($data['files'])) {
        return ['_error' => "version.json distant sans liste de fichiers."];
    }

    $data['_commit'] = $commit;
    $data['_base'] = $base;

    return $data;
}

// ---------------------------------------------------------------------------
// État de la mise à jour (avec cache)
// ---------------------------------------------------------------------------
function registrarc_update_cache_file() {
    return registrarc_data_dir() . '/update_check.json';
}

// Retourne ['local', 'remote', 'date', 'notes', 'update' (bool), 'error', 'checked_at']
function registrarc_update_status($force = false) {
    $localVer = registrarc_local_version_number();
    $cacheFile = registrarc_update_cache_file();
    $cache = registrarc_storage_read($cacheFile);

    if (!$force && is_array($cache) && isset($cache['checked_at'], $cache['local']) && $cache['local'] === $localVer) {
        $ttl = !empty($cache['error']) ? REGISTRARC_UPDATE_TTL_ERROR : REGISTRARC_UPDATE_TTL_OK;

        if (time() - (int)$cache['checked_at'] < $ttl) {
            return $cache;
        }
    }

    // Délai court pour la vérification automatique : la page ne doit pas attendre GitHub
    $remote = registrarc_update_fetch_remote(registrarc_update_config(), $force ? 15 : 4);

    $status = [
        'local' => $localVer,
        'remote' => '',
        'date' => '',
        'notes' => '',
        'update' => false,
        'error' => '',
        'checked_at' => time(),
        'commit' => '',
    ];

    if (isset($remote['_error'])) {
        $status['error'] = $remote['_error'];
    } else {
        $status['remote'] = (string)$remote['version'];
        $status['date'] = isset($remote['date']) ? (string)$remote['date'] : '';
        $status['notes'] = isset($remote['notes']) ? (string)$remote['notes'] : '';
        $status['update'] = version_compare($status['remote'], $localVer, '>');
        $status['commit'] = substr((string)$remote['_commit'], 0, 7);
    }

    if (registrarc_ensure_data_dir()) {
        registrarc_storage_replace($cacheFile, $status);
    }

    return $status;
}

// ---------------------------------------------------------------------------
// Installation
// ---------------------------------------------------------------------------
// Chemin relatif sûr : pas de remontée, pas de data/, pas de module.json
function registrarc_update_safe_path($rel) {
    if (!is_string($rel) || $rel === '' || strpos($rel, "\0") !== false) {
        return false;
    }

    if ($rel[0] === '/' || strpos($rel, '\\') !== false || strpos($rel, '..') !== false) {
        return false;
    }

    if (!preg_match('#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$#', $rel)) {
        return false;
    }

    return $rel !== 'module.json' && $rel !== 'data' && strpos($rel, 'data/') !== 0;
}

// Empreintes SHA-256 attendues pour $files (sans version.json).
// Retourne ['hashes' => [fichier => empreinte] ou null, 'error' => message]
// - version.json à l'ancien format (sans "sha256", versions 1.1.0 et avant) :
//   hashes null, installation sans contrôle d'empreinte comme auparavant
// - "sha256" présent : chaque fichier doit avoir une empreinte valide
function registrarc_update_expected_hashes(array $remote, array $files) {
    if (!array_key_exists('sha256', $remote)) {
        return ['hashes' => null, 'error' => ''];
    }

    $hashes = is_array($remote['sha256']) ? $remote['sha256'] : [];

    foreach ($files as $rel) {
        if (!isset($hashes[$rel]) || !preg_match('/^[0-9a-f]{64}$/', (string)$hashes[$rel])) {
            return ['hashes' => [], 'error' => "version.json distant refusé : empreinte manquante ou invalide pour $rel. Aucune modification effectuée."];
        }
    }

    return ['hashes' => $hashes, 'error' => ''];
}

function registrarc_update_rrmdir($dir) {
    if (!is_dir($dir)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            @unlink($f->getPathname());
        }
    }

    @rmdir($dir);
}

// Retourne ['ok' => bool, 'message' => string, 'from' => , 'to' => , 'files' => [], 'backup' => '']
function registrarc_update_apply() {
    $moduleDir = registrarc_module_dir();
    $localVer = registrarc_local_version_number();
    $result = ['ok' => false, 'message' => '', 'from' => $localVer, 'to' => '', 'files' => [], 'backup' => ''];

    if (!registrarc_ensure_data_dir()) {
        $result['message'] = "Le dossier data/ n'est pas accessible en écriture.";
        return $result;
    }

    $lock = @fopen(registrarc_data_dir() . '/update.lock', 'c');

    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        $result['message'] = "Une mise à jour est déjà en cours.";
        return $result;
    }

    $stage = registrarc_data_dir() . '/update_tmp_' . bin2hex(random_bytes(6));

    try {
        $cfg = registrarc_update_config();
        $remote = registrarc_update_fetch_remote($cfg, 20);

        if (isset($remote['_error'])) {
            $result['message'] = $remote['_error'];
            return $result;
        }

        $result['to'] = (string)$remote['version'];

        if (!version_compare($result['to'], $localVer, '>')) {
            $result['message'] = "Le module est déjà à jour (version $localVer).";
            return $result;
        }

        // Liste des fichiers : contrôlée, version.json toujours présent et en dernier
        $files = [];

        foreach ($remote['files'] as $rel) {
            if (!registrarc_update_safe_path($rel)) {
                $result['message'] = "version.json distant refusé : chemin de fichier non autorisé (" . (is_string($rel) ? $rel : '?') . ").";
                return $result;
            }

            if ($rel !== 'version.json') {
                $files[] = $rel;
            }
        }

        $files = array_values(array_unique($files));

        $expected = registrarc_update_expected_hashes($remote, $files);

        if ($expected['error'] !== '') {
            $result['message'] = $expected['error'];
            return $result;
        }

        $hashes = $expected['hashes'];
        $files[] = 'version.json';

        // Droits d'écriture vérifiés avant de toucher à quoi que ce soit
        $notWritable = [];

        foreach ($files as $rel) {
            $dest = $moduleDir . '/' . $rel;
            $target = is_file($dest) ? $dest : dirname($dest);

            while (!file_exists($target) && $target !== dirname($target)) {
                $target = dirname($target);
            }

            if (!is_writable($target)) {
                $notWritable[] = $rel;
            }
        }

        if (!empty($notWritable)) {
            $result['message'] = "Fichiers non modifiables par le serveur web : " . implode(', ', $notWritable) . ". Aucune modification effectuée.";
            return $result;
        }

        // 1. Téléchargement complet dans le dossier temporaire
        if (!@mkdir($stage, 0775, true)) {
            $result['message'] = "Création du dossier temporaire impossible dans data/.";
            return $result;
        }

        // Même commit que le version.json lu : jamais de mélange de deux versions
        $base = $remote['_base'];

        foreach ($files as $rel) {
            $res = registrarc_update_http_get($base . '/' . $rel, (string)$cfg['github_token'], 20);

            if (!$res['ok'] || $res['body'] === '') {
                $result['message'] = "Téléchargement impossible pour $rel : " . ($res['error'] !== '' ? $res['error'] : 'fichier vide.') . " Aucune modification effectuée.";
                return $result;
            }

            if ($hashes !== null && $rel !== 'version.json' && !hash_equals((string)$hashes[$rel], hash('sha256', $res['body']))) {
                $result['message'] = "Fichier $rel différent de celui annoncé par version.json (publication peut-être en cours sur GitHub : réessayez dans quelques minutes). Aucune modification effectuée.";
                return $result;
            }

            if (substr($rel, -4) === '.php' && strpos(ltrim($res['body'], "\xEF\xBB\xBF \t\r\n"), '<?php') !== 0) {
                $result['message'] = "Contenu inattendu pour $rel. Aucune modification effectuée.";
                return $result;
            }

            $staged = $stage . '/' . $rel;

            if (!is_dir(dirname($staged))) {
                @mkdir(dirname($staged), 0775, true);
            }

            if (@file_put_contents($staged, $res['body']) !== strlen($res['body'])) {
                $result['message'] = "Écriture temporaire impossible pour $rel. Aucune modification effectuée.";
                return $result;
            }
        }

        // 2. Sauvegarde des fichiers actuels puis remplacement
        $backup = registrarc_data_dir() . '/backup_' . preg_replace('/[^0-9.]/', '', $localVer) . '_' . date('Ymd-His');
        $failed = [];

        foreach ($files as $rel) {
            $dest = $moduleDir . '/' . $rel;

            if (is_file($dest)) {
                if (!is_dir(dirname($backup . '/' . $rel))) {
                    @mkdir(dirname($backup . '/' . $rel), 0775, true);
                }

                @copy($dest, $backup . '/' . $rel);
            }

            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0775, true);
            }

            if (@rename($stage . '/' . $rel, $dest) || @copy($stage . '/' . $rel, $dest)) {
                $result['files'][] = $rel;

                if (function_exists('opcache_invalidate')) {
                    @opcache_invalidate($dest, true);
                }
            } else {
                $failed[] = $rel;
            }
        }

        $result['backup'] = is_dir($backup) ? 'data/' . basename($backup) : '';
        @unlink(registrarc_update_cache_file());

        if (!empty($failed)) {
            $result['message'] = "Mise à jour incomplète : " . implode(', ', $failed) . " n'ont pas pu être remplacés."
                . ($result['backup'] !== '' ? " Les anciens fichiers sont dans " . $result['backup'] . "." : '');
            return $result;
        }

        $result['ok'] = true;
        $result['message'] = "Module mis à jour de la version " . $localVer . " vers la version " . $result['to'] . ".";

        return $result;
    } finally {
        registrarc_update_rrmdir($stage);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
