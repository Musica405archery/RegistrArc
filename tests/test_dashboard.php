<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Données du tableau de bord : complètes, cohérentes, sans donnée personnelle

test('tableau de bord : données par engagement et opérations de caisse, sans nom ni licence', function () {
    $paid = test_add_entry('LIC001', 'CLU15F', '1099000', 1);
    $chq = test_add_entry('LIC001', 'CLU15F', '1099000', 2);
    $due = test_add_entry('LIC002', 'CLS1H', '1077000', 1, '0', '');
    file_put_contents(registrarc_archers_file_path(1), json_encode([(string)$due => ['late' => true, 'note' => 'paiera demain']]));

    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Mobile';
    registrarc_record_payments(1, [$paid], 'ESP', true, 'kiosque');
    registrarc_record_payments(1, [$chq], 'CHQ', false, 'kiosque');
    registrarc_create_deposit(1, [['group_id' => '', 'engagement_ids' => [$chq]]]);
    registrarc_close_cash(1, 8, '');

    $d = registrarc_dashboard_data(1);
    $r = $d['records'];

    assert_same(3, count($r));
    assert_same($r[0]['a'], $r[1]['a'], 'même archer pour les deux départs de LIC001');
    assert_true($r[0]['a'] !== $r[2]['a']);
    assert_same('jeunes', $r[0]['age']);
    assert_same(['paid', 'paid', 'due'], array_column($r, 'st'));
    assert_amount(10, $r[2]['d']);
    assert_same(1, $r[1]['dep'], 'chèque déposé');
    assert_same(1, $r[0]['r'], 'reçu demandé');
    assert_same([1, 1, 1], [$r[2]['u'], $r[2]['l'], $r[2]['n']], 'sans cible, tardive, note');
    assert_same('Standard', $r[0]['t']);

    assert_same(2, count($d['journal']));
    assert_same(['payment', 8.0, 'ESP', '1', 'Testeur', 'Téléphone', 'kiosque'], array_slice($d['journal'][0], 1));
    assert_same(1, count($d['deposits']));
    assert_amount(0, $d['closures'][0]['diff'], '8 € comptés pour 8 € attendus');
    assert_same(['1', '2'], $d['sessions']);

    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    foreach (['LIC001', 'LIC002', 'NOM1', 'Prénom1', 'paiera demain'] as $private) {
        assert_same(false, strpos($json, $private), "« $private » ne doit pas être transmis");
    }
});

test('carte : département du club d\'après son numéro FFTA, départements utiles seulement', function () {
    assert_same('69', registrarc_club_departement('1069012'));
    assert_same('29', registrarc_club_departement('0829001'));
    assert_same('', registrarc_club_departement('1099000'), '99 n\'est pas un département');
    assert_same('', registrarc_club_departement('ESP'), 'club étranger');
    assert_same('', registrarc_club_departement(''));

    $deps = registrarc_departements();
    assert_same('Rhône', $deps['69'][0]);
    assert_true($deps['69'][1] > 45 && $deps['69'][1] < 46.5 && $deps['69'][2] > 4 && $deps['69'][2] < 5, 'centre du Rhône');

    test_add_entry('A1', 'CLS1H', '1069012');
    test_add_entry('B1', 'CLS1H', '1099000');
    registrarc_load_engagements(1, true);  // vide le cache du test précédent
    $d = registrarc_dashboard_data(1);

    assert_same(['69', ''], $d['club_deps']);
    assert_same('{"69":["Rhône",', substr(json_encode($d['departements'], JSON_UNESCAPED_UNICODE), 0, 16), 'objet JSON');
    assert_same(['69'], array_map('strval', array_keys((array)$d['departements'])), 'seuls les départements des clubs présents');
});
