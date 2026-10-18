<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Calcul des montants dus : tarif, règle, plafond, majoration, statut

test('montants par départ : organisateur et autre club', function () {
    $a1 = test_add_entry('A1', 'CLS1H', '1034000', 1);
    $a2 = test_add_entry('A1', 'CLS1H', '1034000', 2);
    $b1 = test_add_entry('B1', 'CLU15F', '1099000', 1);

    $e = registrarc_load_engagements(1, true);

    assert_same(1, $e[$a1]['numero']);
    assert_same(2, $e[$a2]['numero']);
    assert_same(5.0, $e[$a1]['amount_due'], 'organisateur adulte, 1er départ');
    assert_same(5.0, $e[$a2]['amount_due'], 'organisateur adulte, supplément');
    assert_same(8.0, $e[$b1]['amount_due'], 'autre club jeune');
    assert_same('due', $e[$b1]['state']);
    assert_same('Standard', registrarc_engagement_tarif_label($e[$b1]));
});

test('numéro de départ dans l\'ordre des départs, pas des inscriptions', function () {
    $second = test_add_entry('A1', 'CLS1H', '1099000', 2);
    $first = test_add_entry('A1', 'CLS1H', '1099000', 1);

    $e = registrarc_load_engagements(1, true);
    assert_same(1, $e[$first]['numero']);
    assert_same(10.0, $e[$first]['amount_due']);
    assert_same(2, $e[$second]['numero']);
    assert_same(8.0, $e[$second]['amount_due']);
});

test('archer non affecté', function () {
    $id = test_add_entry('A1', 'CLS1H', '1099000', 1, '0', '');
    $e = registrarc_load_engagements(1, true);

    assert_true($e[$id]['target_is_unassigned']);
    assert_same('Archer non affecté', $e[$id]['target_label']);
});

test('règle avancée prioritaire sur le tarif de base', function () {
    test_settings(['rules' => [['active' => true, 'scope' => 'categorie', 'match' => ['BB'], 'label' => 'Bare bow', 'action' => ['type' => 'fixed_price', 'value' => 6]]]]);
    $id = test_add_entry('A1', 'BBS1H', '1099000');

    $e = registrarc_load_engagements(1, true);
    assert_same(6.0, $e[$id]['amount_due']);
    assert_same('Bare bow', registrarc_engagement_special_label($e[$id]));
});

test('règle à 0 € : engagement gratuit', function () {
    test_settings(['rules' => [['active' => true, 'scope' => 'categorie', 'match' => ['U11'], 'label' => 'Poussins', 'action' => ['type' => 'fixed_price', 'value' => 0]]]]);
    $id = test_add_entry('A1', 'CLU11H', '1099000');

    $e = registrarc_load_engagements(1, true);
    assert_same(0.0, $e[$id]['amount_due']);
    assert_same('free', $e[$id]['state']);
});

test('plafond par archer : excédent retiré en partant du dernier départ', function () {
    test_settings(['options' => ['cap_per_archer' => 12]]);
    $d1 = test_add_entry('A1', 'CLS1H', '1099000', 1);  // 10
    $d2 = test_add_entry('A1', 'CLS1H', '1099000', 2);  // 8 -> 2
    $d3 = test_add_entry('A1', 'CLS1H', '1099000', 3);  // 8 -> 0
    $other = test_add_entry('B1', 'CLS1H', '1099000', 1);

    $e = registrarc_load_engagements(1, true);
    assert_same(10.0, $e[$d1]['amount_due']);
    assert_same(2.0, $e[$d2]['amount_due']);
    assert_same(0.0, $e[$d3]['amount_due']);
    assert_same(10.0, $e[$other]['amount_due'], 'archer sous le plafond inchangé');
    assert_same([['label' => 'Plafond par archer', 'amount' => -8.0]], $e[$d3]['adjustments']);
});

test('majoration pour inscription tardive, ajoutée après le plafond', function () {
    test_settings(['options' => ['cap_per_archer' => 10, 'late_fee' => 3]]);
    $d1 = test_add_entry('A1', 'CLS1H', '1099000', 1);
    $d2 = test_add_entry('A1', 'CLS1H', '1099000', 2);
    file_put_contents(registrarc_archers_file_path(1), json_encode([(string)$d2 => ['late' => true, 'note' => ' arrivé hier ']]));

    $e = registrarc_load_engagements(1, true);
    assert_same(10.0, $e[$d1]['amount_due']);
    assert_same(3.0, $e[$d2]['amount_due'], 'plafonné à 0 puis majoré de 3');
    assert_same('arrivé hier', $e[$d2]['note']);
    assert_same('Standard · Plafond par archer · Inscription tardive', registrarc_engagement_tarif_label($e[$d2]));
});

test('paramètres illisibles : tarifs par défaut', function () {
    @mkdir(REGISTRARC_DATA_DIR, 0775, true);
    file_put_contents(registrarc_settings_file_path(1), '{ tronqué');
    $id = test_add_entry('A1', 'CLS1H', '1099000');

    $e = registrarc_load_engagements(1, true);
    assert_same(10.0, $e[$id]['amount_due']);
});
