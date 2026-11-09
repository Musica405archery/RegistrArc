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
    assert_same(0.0, $e[$a1]['amount_due'], 'organisateur adulte, 1er départ : gratuit par défaut');
    assert_same(0.0, $e[$a2]['amount_due'], 'organisateur adulte, supplément : gratuit par défaut');
    assert_same('free', $e[$a1]['state']);
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

test('inscription tardive : simple information, sans majoration', function () {
    // Une ancienne majoration encore présente dans les paramètres n'est plus appliquée
    test_settings(['options' => ['cap_per_archer' => 10, 'late_fee' => 3]]);
    $d1 = test_add_entry('A1', 'CLS1H', '1099000', 1);
    $d2 = test_add_entry('A1', 'CLS1H', '1099000', 2);
    file_put_contents(registrarc_archers_file_path(1), json_encode([(string)$d2 => ['late' => true, 'note' => ' arrivé hier ']]));

    $e = registrarc_load_engagements(1, true);
    assert_same(10.0, $e[$d1]['amount_due']);
    assert_same(0.0, $e[$d2]['amount_due'], 'plafonné à 0, pas de majoration');
    assert_true($e[$d2]['late']);
    assert_same('arrivé hier', $e[$d2]['note']);
    assert_same('Standard · Plafond par archer', registrarc_engagement_tarif_label($e[$d2]));
    assert_same(['cap_per_archer' => 10.0], registrarc_load_pricing_options(1));
});

test('passage au greffe : pointé, conservé avec la note, puis annulé', function () {
    $d1 = test_add_entry('A1', 'CLS1H', '1099000', 1);
    $d2 = test_add_entry('A1', 'CLS1H', '1099000', 2);

    assert_same('', registrarc_load_engagements(1, true)[$d1]['checked_in'], 'pas encore passé');

    assert_true(registrarc_toggle_checkin(1, $d1));
    $e = registrarc_load_engagements(1, true);
    assert_true($e[$d1]['checked_in'] !== '', 'pointé');
    assert_same('', $e[$d2]['checked_in'], 'seulement ce départ');

    // Une note enregistrée ensuite ne retire pas le pointage
    assert_true(registrarc_set_archer_note(1, $d1, 'cible changée', false));
    $e = registrarc_load_engagements(1, true);
    assert_true($e[$d1]['checked_in'] !== '');
    assert_same('cible changée', $e[$d1]['note']);

    // Annulé, puis note vidée : plus rien n'est gardé pour cet engagement
    assert_true(registrarc_toggle_checkin(1, $d1));
    assert_true(registrarc_set_archer_note(1, $d1, '', false));
    assert_same([], registrarc_load_archer_info(1));
    assert_same(false, registrarc_toggle_checkin(1, 0));
});

test('paramètres illisibles : tarifs par défaut', function () {
    @mkdir(REGISTRARC_DATA_DIR, 0775, true);
    file_put_contents(registrarc_settings_file_path(1), '{ tronqué');
    $id = test_add_entry('A1', 'CLS1H', '1099000');

    $e = registrarc_load_engagements(1, true);
    assert_same(10.0, $e[$id]['amount_due']);
});

test('surclassé : catégorie d\'âge différente de la catégorie de classement', function () {
    $up = test_add_entry('A1', 'CLU18F', '1099000', 1, '1', 'A', ['class_code' => 'U18F', 'age_class' => 'U15F']);
    $same = test_add_entry('B1', 'CLU18F', '1099000', 1, '1', 'B', ['class_code' => 'U18F', 'age_class' => 'u18f']);
    $unknown = test_add_entry('C1', 'CLS1H', '1099000', 1, '1', 'C', ['class_code' => 'S1H', 'age_class' => '']);

    $e = registrarc_load_engagements(1, true);
    assert_true($e[$up]['is_upgraded'], 'U15 qui tire en U18');
    assert_same('U15F', $e[$up]['age_class']);
    assert_same(false, $e[$same]['is_upgraded'], 'même catégorie, casse différente');
    assert_same(false, $e[$unknown]['is_upgraded'], 'date de naissance inconnue : pas signalé');
});
