<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Encaissement, annulation, remboursement, journal, remises et clôtures

test('encaissement : paiement enregistré et journalisé', function () {
    $id = test_add_entry('A1', 'CLS1H', '1099000');

    $r = registrarc_record_payments(1, [$id], 'ESP', true, 'liste');
    assert_true($r['ok']);
    assert_same([$id], $r['done']);

    $e = registrarc_load_engagements(1, true)[$id];
    assert_same('paid', $e['state']);
    assert_same('ESP', $e['payment_method']);
    assert_true($e['receipt_required']);
    assert_amount(10, $e['payment']['amount']);
    assert_same('Testeur', $e['payment']['operator']);

    $journal = registrarc_load_journal(1);
    assert_same(1, count($journal));
    assert_same('payment', $journal[0]['type']);
    assert_amount(10, $journal[0]['amount']);
    assert_same('liste', $journal[0]['source']);
    assert_same('Testeur', $journal[0]['operator']);
});

test('mode Gratuit : montant à 0 dans la caisse et à l\'affichage', function () {
    $id = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$id], 'GRA', false, 'liste');

    $e = registrarc_load_engagements(1, true)[$id];
    assert_same('paid', $e['state']);
    assert_same(0, $e['amount']);
    assert_same(0.0, registrarc_cash_summary(1)['total']);
});

test('changement de mode : annulation de l\'ancien puis nouvel encaissement', function () {
    $id = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$id], 'ESP', false, 'liste');
    registrarc_record_payments(1, [$id], 'CHQ', false, 'kiosque');

    $types = array_column(registrarc_load_journal(1), 'type');
    assert_same(['payment', 'cancel', 'payment'], $types);

    $summary = registrarc_cash_summary(1);
    assert_same(['CHQ' => 10.0, 'ESP' => 0.0], $summary['totals']);
    assert_same(10.0, $summary['total']);
});

test('même mode une seconde fois : seul le reçu change, rien au journal', function () {
    $id = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$id], 'ESP', false, 'liste');
    registrarc_record_payments(1, [$id], 'ESP', true, 'liste');

    assert_same(1, count(registrarc_load_journal(1)));
    assert_true(registrarc_load_engagements(1, true)[$id]['receipt_required']);
});

test('annulation et remboursement', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000');
    $b = test_add_entry('B1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$a, $b], 'ESP', false, 'liste');

    registrarc_cancel_payments(1, [$a], 'liste');
    registrarc_refund_payments(1, [$b], 'VIR', 'liste', 'Désistement');

    $e = registrarc_load_engagements(1, true);
    assert_same('due', $e[$a]['state']);
    assert_same('refunded', $e[$b]['state']);
    assert_same('VIR', $e[$b]['payment']['refund_method']);
    assert_amount(10, $e[$b]['payment']['refund_amount']);

    $summary = registrarc_cash_summary(1);
    assert_same(['ESP' => 10.0, 'VIR' => -10.0], $summary['totals'], 'ESP : +10 +10 -10 ; VIR : -10');
    assert_same(0.0, $summary['total']);
});

test('opérations refusées : rien à annuler ou à rembourser, engagement inconnu', function () {
    $id = test_add_entry('A1', 'CLS1H', '1099000');

    $r = registrarc_cancel_payments(1, [$id], 'liste');
    assert_same([], $r['done']);
    assert_true(isset($r['skipped'][$id]));

    $r = registrarc_refund_payments(1, [$id], 'ESP', 'liste', 'Blessure');
    assert_true(isset($r['skipped'][$id]));

    $r = registrarc_record_payments(1, [999], 'ESP', false, 'liste');
    assert_same('engagement introuvable dans ce concours', $r['skipped'][999]);
    assert_same([], registrarc_load_journal(1));
});

test('remise de chèques : chèque déposé verrouillé sauf remboursement', function () {
    $chq = test_add_entry('A1', 'CLS1H', '1099000');
    $esp = test_add_entry('B1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$chq], 'CHQ', false, 'liste');
    registrarc_record_payments(1, [$esp], 'ESP', false, 'liste');

    $depositId = registrarc_create_deposit(1, [['group_id' => '', 'engagement_ids' => [$chq, $esp]]]);
    assert_same('R1', $depositId);

    $deposit = registrarc_load_deposits(1)['R1'];
    assert_same(1, $deposit['count'], 'les espèces ne vont pas dans la remise');
    assert_amount(10, $deposit['total']);
    assert_same('R1', registrarc_load_engagements(1, true)[$chq]['deposit_id']);

    $r = registrarc_cancel_payments(1, [$chq], 'liste');
    assert_true(isset($r['skipped'][$chq]), 'annulation refusée');

    $r = registrarc_refund_payments(1, [$chq], 'CHQ', 'liste', 'Erreur d\'inscription');
    assert_same([$chq], $r['done'], 'remboursement accepté');

    assert_same('', registrarc_create_deposit(1, [['group_id' => '', 'engagement_ids' => [$chq]]]), 'déjà déposé');
});

test('annulation d\'une remise : chèques de nouveau à déposer', function () {
    $chq = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$chq], 'CHQ', false, 'liste');
    registrarc_create_deposit(1, [['group_id' => '', 'engagement_ids' => [$chq]]]);

    assert_true(registrarc_cancel_deposit(1, 'R1'));
    assert_same('', registrarc_load_engagements(1, true)[$chq]['deposit_id']);
    assert_same([], registrarc_load_deposits(1));
    assert_same('R1', registrarc_create_deposit(1, [['group_id' => '', 'engagement_ids' => [$chq]]]), 'numéro réutilisable');
});

test('clôtures de caisse découpées par position dans le journal', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000');
    $b = test_add_entry('B1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$a], 'ESP', false, 'liste');

    assert_same('C1', registrarc_close_cash(1, 12.5, 'fin de matinée'));

    $c1 = registrarc_load_closures(1)[0];
    assert_same(0, $c1['journal_from']);
    assert_same(1, $c1['journal_to']);
    assert_amount(10, $c1['cash_expected']);
    assert_amount(2.5, $c1['cash_difference']);

    registrarc_record_payments(1, [$b], 'ESP', false, 'liste');

    $period = registrarc_current_cash_period(1);
    $summary = registrarc_cash_summary(1, $period['index']);
    assert_same(1, $summary['count'], 'seul le 2e paiement est dans la période en cours');
    assert_same(10.0, $summary['total']);

    assert_same('C2', registrarc_close_cash(1, null, ''));
    $c2 = registrarc_load_closures(1)[1];
    assert_same(null, $c2['cash_difference']);
    assert_same(1, $c2['count']);
});

test('fond de caisse : détail par pièces et billets, valeurs inconnues écartées', function () {
    assert_same([], registrarc_load_cash_float(1)['detail'], 'aucun fond par défaut');
    assert_amount(0, registrarc_load_cash_float(1)['total']);

    assert_true(registrarc_save_cash_float(1, ['1000' => '3', '200' => '5', '50' => '4', '300' => '9', '500' => '-2', '100' => 'x', '20' => '0']));

    $float = registrarc_load_cash_float(1);
    assert_same([50 => 4, 200 => 5, 1000 => 3], $float['detail']);
    assert_amount(42, $float['total'], '3 × 10 € + 5 × 2 € + 4 × 0,50 €');
    assert_same('0,05 €', registrarc_cash_denomination_label(5));
    assert_same('20 €', registrarc_cash_denomination_label(2000));
    assert_amount(0.3, registrarc_cash_detail_total([10 => 1, 20 => 1]), 'pas d\'erreur d\'arrondi');
});

test('clôture : fond compris dans les espèces attendues, comptage détaillé', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_save_cash_float(1, [1000 => 3, 200 => 5, 50 => 4]);
    registrarc_record_payments(1, [$a], 'ESP', false, 'liste');

    assert_same('C1', registrarc_close_cash(1, 999, '', [2000 => 2, 1000 => 1, 200 => 1]));

    $c1 = registrarc_load_closures(1)[0];
    assert_amount(42, $c1['cash_float']);
    assert_amount(10, $c1['cash_takings']);
    assert_amount(52, $c1['cash_expected'], '42 € de fond + 10 € encaissés');
    assert_amount(52, $c1['cash_counted'], 'le comptage détaillé l\'emporte sur le total saisi');
    assert_amount(0, $c1['cash_difference']);
    assert_same([200 => 1, 1000 => 1, 2000 => 2], registrarc_normalize_cash_detail($c1['cash_counted_detail']));

    // Le fond modifié ensuite ne change pas la clôture passée
    registrarc_save_cash_float(1, []);
    assert_amount(42, registrarc_load_closures(1)[0]['cash_float']);

    assert_true(in_array(registrarc_cash_float_file_path(1), registrarc_backup_files(1), true), 'fond de caisse sauvegardé');
});

test('clôture simplifiée (un seul montant) avec un fond de caisse détaillé', function () {
    $a = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_save_cash_float(1, [1000 => 3, 200 => 5, 50 => 4]);
    registrarc_record_payments(1, [$a], 'ESP', false, 'liste');

    // Comptage simplifié : seul le montant saisi, aucun détail
    assert_same('C1', registrarc_close_cash(1, registrarc_parse_cash_amount('51,50'), '', []));

    $c1 = registrarc_load_closures(1)[0];
    assert_same('detail', $c1['cash_float_mode'], 'le fond reste détaillé');
    assert_amount(52, $c1['cash_expected']);
    assert_amount(51.5, $c1['cash_counted']);
    assert_amount(-0.5, $c1['cash_difference']);
    assert_same([], registrarc_normalize_cash_detail($c1['cash_counted_detail']));
});

test('fond de caisse simplifié : un seul montant, sans détail', function () {
    assert_same('detail', registrarc_load_cash_float(1)['mode'], 'détaillé par défaut');

    assert_true(registrarc_save_cash_float(1, [1000 => 3], 'simple', '75,50 €'));

    $float = registrarc_load_cash_float(1);
    assert_same('simple', $float['mode']);
    assert_same([], $float['detail'], 'le détail saisi est ignoré');
    assert_amount(75.5, $float['total']);

    assert_same(null, registrarc_parse_cash_amount('-3'));
    assert_same(null, registrarc_parse_cash_amount('abc'));
    assert_same(null, registrarc_parse_cash_amount(''));
    assert_amount(1250, registrarc_parse_cash_amount('1 250,00'));

    $a = test_add_entry('A1', 'CLS1H', '1099000');
    registrarc_record_payments(1, [$a], 'ESP', false, 'liste');
    assert_same('C1', registrarc_close_cash(1, 85, ''));

    $c1 = registrarc_load_closures(1)[0];
    assert_same('simple', $c1['cash_float_mode']);
    assert_amount(85.5, $c1['cash_expected'], '75,50 € de fond + 10 € encaissés');
    assert_amount(-0.5, $c1['cash_difference']);

    // Retour au détaillé : le montant simplifié n'est plus compté
    registrarc_save_cash_float(1, [500 => 2], 'detail', '75,50');
    assert_amount(10, registrarc_load_cash_float(1)['total']);
});

test('concours clôturé : plus de droit d\'écriture', function () {
    assert_true(registrarc_can_write(1));
    registrarc_set_closed(1, true);
    assert_same(false, registrarc_can_write(1));
    assert_true(registrarc_is_closed(1));
    registrarc_set_closed(1, false);
    assert_true(registrarc_can_write(1));
});
