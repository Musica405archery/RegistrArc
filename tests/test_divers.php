<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Stockage JSON, protection de data/, export tableur, mise à jour

test('stockage : écriture, copie .bak et protection du dossier', function () {
    $file = registrarc_payments_file_path(1);

    assert_true(registrarc_save_json_file($file, ['a' => 1]));
    assert_true(registrarc_update_json_file($file, function (array &$data) { $data['b'] = 2; }));

    assert_same(['a' => 1, 'b' => 2], registrarc_load_json_file($file));
    assert_same(['a' => 1], json_decode(file_get_contents($file . '.bak'), true));

    foreach (['.htaccess', 'web.config', 'index.html'] as $protection) {
        assert_true(is_file(REGISTRARC_DATA_DIR . '/' . $protection), $protection);
    }
});

test('stockage : refus d\'écrire par-dessus un fichier illisible', function () {
    @mkdir(REGISTRARC_DATA_DIR, 0775, true);
    $file = registrarc_payments_file_path(1);
    file_put_contents($file, '{"1": {"status": "PA');

    $ok = registrarc_update_json_file($file, function (array &$data) { $data = []; });
    assert_same(false, $ok);
    assert_true(strpos(registrarc_storage_error(), 'illisible') !== false);
    assert_same('{"1": {"status": "PA', file_get_contents($file), 'fichier laissé intact');
});

test('stockage : mutateur qui renvoie false, rien n\'est écrit', function () {
    $file = registrarc_payments_file_path(1);
    registrarc_save_json_file($file, ['a' => 1]);

    assert_true(registrarc_update_json_file($file, function (array &$data) { $data['x'] = 1; return false; }));
    assert_same(['a' => 1], registrarc_load_json_file($file));
});

test('tableur : valeurs saisies neutralisées, nombres conservés', function () {
    assert_same("'=HYPERLINK(\"x\")", registrarc_csv_cell('=HYPERLINK("x")'));
    assert_same("'+33", registrarc_csv_cell('+33'));
    assert_same("'@SOMME(A1)", registrarc_csv_cell('@SOMME(A1)'));
    assert_same("'-test", registrarc_csv_cell('-test'));
    assert_same('-12,50', registrarc_csv_cell('-12,50'));
    assert_same('10.5', registrarc_csv_cell('10.5'));
    assert_same('Dupont', registrarc_csv_cell('Dupont'));
    assert_same('', registrarc_csv_cell(''));
    assert_same(3, registrarc_csv_cell(3));
});

test('mise à jour : chemins de fichiers autorisés', function () {
    assert_true(registrarc_update_safe_path('index.php'));
    assert_true(registrarc_update_safe_path('assets/css/index.css'));
    assert_true(registrarc_update_safe_path('lib/js/qrcode.js'));

    foreach (['', '../config.php', 'lib/../../x.php', '/etc/passwd', 'data/payments_1.json', 'data', 'module.json', 'lib\\x.php', "a\0b"] as $bad) {
        assert_same(false, registrarc_update_safe_path($bad), var_export($bad, true));
    }
});

test('mise à jour : adresse brute GitHub', function () {
    assert_same(
        'https://raw.githubusercontent.com/Musica405archery/RegistrArc/main',
        registrarc_update_raw_base(['github_url' => 'https://github.com/Musica405archery/RegistrArc.git', 'github_branch' => '', 'github_path' => ''])
    );
    assert_same('', registrarc_update_raw_base(['github_url' => 'https://exemple.fr/x', 'github_branch' => 'main', 'github_path' => '']));
});

test('mise à jour : version.json à l\'ancien format accepté sans empreintes', function () {
    $r = registrarc_update_expected_hashes(['version' => '1.1.0', 'files' => ['index.php']], ['index.php']);
    assert_same(['hashes' => null, 'error' => ''], $r);
});

test('mise à jour : empreintes présentes, toutes obligatoires', function () {
    $hash = str_repeat('a', 64);

    $r = registrarc_update_expected_hashes(['sha256' => ['index.php' => $hash, 'kiosk.php' => $hash]], ['index.php', 'kiosk.php']);
    assert_same('', $r['error']);
    assert_same($hash, $r['hashes']['kiosk.php']);

    $r = registrarc_update_expected_hashes(['sha256' => ['index.php' => $hash]], ['index.php', 'kiosk.php']);
    assert_true(strpos($r['error'], 'kiosk.php') !== false, 'empreinte manquante');

    $r = registrarc_update_expected_hashes(['sha256' => ['index.php' => 'abc']], ['index.php']);
    assert_true($r['error'] !== '', 'empreinte invalide');

    $r = registrarc_update_expected_hashes(['sha256' => 'n\'importe quoi'], ['index.php']);
    assert_true($r['error'] !== '', 'sha256 qui n\'est pas une liste');
});

test('version.json : chaque fichier distribué existe et a son empreinte', function () {
    $root = dirname(dirname(__FILE__));
    $version = json_decode(file_get_contents($root . '/version.json'), true);

    foreach ($version['files'] as $rel) {
        assert_true(registrarc_update_safe_path($rel), $rel);
        assert_true(is_file($root . '/' . $rel), "$rel existe");

        if ($rel !== 'version.json') {
            assert_true(isset($version['sha256'][$rel]), "empreinte de $rel (lancer tools/version_hashes.php)");
        }
    }

    foreach (['lib/registrarc_page.inc.php', 'assets/js/index.js', 'assets/css/admin.css'] as $required) {
        assert_true(in_array($required, $version['files'], true), "$required distribué");
    }
});

test('mise à jour : dernière version lue à un commit précis', function () {
    $cfg = ['github_url' => 'https://github.com/Musica405archery/RegistrArc', 'github_branch' => 'main', 'github_path' => '', 'github_token' => '', 'github_ref' => 'latest'];
    $sha = str_repeat('ab', 20);

    assert_same('https://raw.githubusercontent.com/Musica405archery/RegistrArc/' . $sha, registrarc_update_raw_base($cfg, $sha), 'fichiers lus au commit');
    assert_same('https://raw.githubusercontent.com/Musica405archery/RegistrArc/main', registrarc_update_raw_base($cfg), 'repli sur la branche');
    assert_same(['Musica405archery', 'RegistrArc'], registrarc_update_github_repo($cfg));
    assert_same('', registrarc_update_latest_commit(['github_ref' => 'branch'] + $cfg), 'ancienne méthode : pas d\'appel à l\'API');
    assert_same('', registrarc_update_latest_commit(['github_url' => 'https://exemple.fr/x'] + $cfg), 'adresse invalide');
});

test('mise à jour : "latest" par défaut, même sans la ligne dans module.json', function () {
    $repo = json_decode(file_get_contents(dirname(dirname(__FILE__)) . '/module.json'), true);
    assert_same('latest', $repo['github_ref'], 'module.json du dépôt');
    assert_same('', registrarc_update_github_repo(['github_url' => '']) === null ? '' : 'x');

    $defaults = registrarc_update_config();
    assert_same('latest', $defaults['github_ref']);
});
