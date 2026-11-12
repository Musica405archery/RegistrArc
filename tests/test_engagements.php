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

test('ancienne inscription tardive et ancienne majoration : ignorées', function () {
    // Données d'une ancienne version : majoration dans les paramètres, case « tardive » sur un engagement
    test_settings(['options' => ['cap_per_archer' => 10, 'late_fee' => 3]]);
    $d1 = test_add_entry('A1', 'CLS1H', '1099000', 1);
    $d2 = test_add_entry('A1', 'CLS1H', '1099000', 2);
    file_put_contents(registrarc_archers_file_path(1), json_encode([(string)$d2 => ['late' => true, 'note' => ' arrivé hier ']]));

    $e = registrarc_load_engagements(1, true);
    assert_same(10.0, $e[$d1]['amount_due']);
    assert_same(0.0, $e[$d2]['amount_due'], 'plafonné à 0, pas de majoration');
    assert_true(!isset($e[$d2]['late']), 'plus de notion d\'inscription tardive');
    assert_same('arrivé hier', $e[$d2]['note']);
    assert_same('Standard · Plafond par archer', registrarc_engagement_tarif_label($e[$d2]));
    assert_same(['cap_per_archer' => 10.0], registrarc_load_pricing_options(1));

    // Enregistrer la note efface l'ancienne case
    registrarc_set_archer_note(1, $d2, 'arrivé hier');
    assert_true(!isset(registrarc_load_archer_info(1)[(string)$d2]['late']));
});

test('remboursement : motif obligatoire, affiché dans la caisse', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000', 1);
    registrarc_record_payments(1, [$a], 'CHQ', false, 'liste');

    $r = registrarc_refund_payments(1, [$a], 'ESP', 'liste', '   ');
    assert_same([], $r['done'], 'sans motif : rien n\'est remboursé');
    assert_same('motif du remboursement manquant', $r['skipped'][$a]);
    assert_same('paid', registrarc_load_engagements(1, true)[$a]['state']);

    $r = registrarc_refund_payments(1, [$a], 'ESP', 'liste', 'Blessure');
    assert_same([$a], $r['done']);
    $e = registrarc_load_engagements(1, true)[$a];
    assert_same('refunded', $e['state']);
    assert_same('Blessure', $e['payment']['refund_reason']);

    $journal = registrarc_load_journal(1);
    $last = end($journal);
    assert_same(['refund', -10.0, 'ESP', 'Blessure'], [$last['type'], (float)$last['amount'], $last['method'], $last['label']]);
});

test('supprimer le paiement d\'un engagement remboursé : annule aussi le remboursement', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000', 1);
    registrarc_record_payments(1, [$a], 'CHQ', false, 'liste');
    registrarc_refund_payments(1, [$a], 'ESP', 'liste', 'Désistement');

    $r = registrarc_cancel_payments(1, [$a], 'liste');
    assert_same([$a], $r['done']);
    assert_same('due', registrarc_load_engagements(1, true)[$a]['state'], 'redevient non payé');

    // Caisse juste par mode : chèque +10 -10, espèces -10 +10
    $summary = registrarc_cash_summary(1);
    assert_amount(0, $summary['totals']['CHQ']);
    assert_amount(0, $summary['totals']['ESP']);
    $types = array_column(array_slice(registrarc_load_journal(1), -2), 'type');
    assert_same(['refund_cancel', 'cancel'], $types);

    // Chèque remboursé mais déjà déposé : refusé
    $b = test_add_entry('B1', 'CLS1H', '1099000', 1);
    registrarc_record_payments(1, [$b], 'CHQ', false, 'liste');
    registrarc_create_deposit(1, [['group_id' => '', 'engagement_ids' => [$b]]]);
    registrarc_refund_payments(1, [$b], 'CHQ', 'liste', 'Erreur d\'inscription');
    $r = registrarc_cancel_payments(1, [$b], 'liste');
    assert_same([], $r['done']);
    assert_true(strpos($r['skipped'][$b], 'déposé') !== false);
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

test('paiement au comptoir : pointe au greffe seulement si l\'option est cochée', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000', 1);
    $b = test_add_entry('B1', 'CLS1H', '1099000', 1);
    $c = test_add_entry('C1', 'CLS1H', '1099000', 2);

    // Option cochée par défaut ; décochée : le paiement ne pointe personne
    assert_same(true, registrarc_load_greffe_options(1)['checkin_on_payment']);
    test_settings(['greffe' => ['checkin_on_payment' => false]]);
    assert_same(false, registrarc_load_greffe_options(1)['checkin_on_payment']);
    registrarc_record_payments(1, [$a], 'ESP', false, 'liste');
    assert_same('', registrarc_load_engagements(1, true)[$a]['checked_in']);

    // Option cochée : liste et mode Kiosk pointent, pas un paiement en ligne
    test_settings(['greffe' => ['checkin_on_payment' => true]]);
    assert_same(true, registrarc_load_greffe_options(1)['checkin_on_payment']);
    registrarc_record_payments(1, [$b], 'CHQ', false, 'kiosque');
    registrarc_record_payments(1, [$c], 'CB', false, 'helloasso');

    $e = registrarc_load_engagements(1, true);
    assert_true($e[$b]['checked_in'] !== '', 'payé au kiosque : pointé');
    assert_same('', $e[$c]['checked_in'], 'payé en ligne : pas pointé');

    // Déjà pointé : changer de mode de paiement garde l'heure du pointage
    $info = registrarc_load_archer_info(1);
    $info[(string)$b]['checked_in'] = '2026-11-12 08:00:00';
    file_put_contents(registrarc_archers_file_path(1), json_encode($info));
    registrarc_record_payments(1, [$b], 'ESP', false, 'liste');
    assert_same('2026-11-12 08:00:00', registrarc_load_engagements(1, true)[$b]['checked_in']);
});

test('supprimer le paiement retire aussi le pointage au greffe', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000', 1);
    $b = test_add_entry('B1', 'CLS1H', '1099000', 1);
    $c = test_add_entry('C1', 'CLS1H', '1099000', 1);

    // Option décochée : le pointage manuel est retiré quand même
    test_settings(['greffe' => ['checkin_on_payment' => false]]);
    registrarc_record_payments(1, [$a, $b, $c], 'ESP', false, 'liste');
    registrarc_mark_checked_in(1, [$a, $b, $c]);
    registrarc_set_archer_note(1, $a, 'Licence à vérifier');

    registrarc_cancel_payments(1, [$a], 'liste');
    registrarc_cancel_payments(1, [$c], 'import');
    $e = registrarc_load_engagements(1, true);
    assert_same('', $e[$a]['checked_in'], 'paiement supprimé : plus pointé');
    assert_same('Licence à vérifier', registrarc_load_archer_info(1)[(string)$a]['note'], 'la note reste');
    assert_true($e[$b]['checked_in'] !== '', 'les autres restent pointés');
    assert_true($e[$c]['checked_in'] !== '', 'annulation importée : pointage gardé');

    // Un remboursement annulé par « Supprimer le paiement » : pareil
    registrarc_refund_payments(1, [$b], 'ESP', 'liste', 'Forfait');
    registrarc_cancel_payments(1, [$b], 'liste');
    assert_same('', registrarc_load_engagements(1, true)[$b]['checked_in']);
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
