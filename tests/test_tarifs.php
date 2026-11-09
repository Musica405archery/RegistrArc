<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Tarifs de base, classes d'âge et règles avancées

test('classe d\'âge : listes jeunes / adultes puis règle Uxx', function () {
    assert_same('jeunes', registrarc_age_category('CLU15H'));
    assert_same('jeunes', registrarc_age_category('COU18F'));
    assert_same('adultes', registrarc_age_category('CLU21H'), 'U21 est adulte');
    assert_same('adultes', registrarc_age_category('CLS1F'));
    assert_same('jeunes', registrarc_age_category('BBU16H'), 'U16, absent des listes, reste jeune');
    assert_same('adultes', registrarc_age_category('TLSH'));
});

test('classe d\'âge : listes du tournoi', function () {
    $classes = ['jeunes' => ['U21'], 'adultes' => ['S1']];
    assert_same('jeunes', registrarc_age_category('CLU21H', $classes));
});

test('tarif de base : 1er départ puis supplément', function () {
    $tarifs = registrarc_default_tarifs();

    // Autre club, adulte : 10 € le 1er départ, 18 € les deux, donc 8 € le supplément
    assert_same(10.0, registrarc_prix_engagement('1099000', 'CLS1H', 1, $tarifs, '1034000'));
    assert_same(8.0, registrarc_prix_engagement('1099000', 'CLS1H', 2, $tarifs, '1034000'));
    assert_same(8.0, registrarc_prix_engagement('1099000', 'CLS1H', 3, $tarifs, '1034000'));

    // Club organisateur : gratuit par défaut, jeunes et adultes
    assert_same(0.0, registrarc_prix_engagement('1034000', 'CLU15H', 1, $tarifs, '1034000'));
    assert_same(0.0, registrarc_prix_engagement('1034000', 'CLS1H', 2, $tarifs, '1034000'));
});

test('tarif dégressif sur plusieurs départs', function () {
    // 10 € le 1er départ, 17 € les deux, 21 € les trois
    $tarifs = registrarc_default_tarifs();
    $tarifs['clubs_autres']['adultes'] = [1 => 10, 2 => 17, 3 => 21];

    assert_same(10.0, registrarc_prix_engagement('1099000', 'CLS1H', 1, $tarifs, '1034000'));
    assert_same(7.0, registrarc_prix_engagement('1099000', 'CLS1H', 2, $tarifs, '1034000'));
    assert_same(4.0, registrarc_prix_engagement('1099000', 'CLS1H', 3, $tarifs, '1034000'));
    assert_same(4.0, registrarc_prix_engagement('1099000', 'CLS1H', 7, $tarifs, '1034000'), 'au-delà : dernier supplément');

    assert_same(0.0, registrarc_tarif_step([1 => 10], 2), 'ancien tarif sans 2e départ');
    assert_same(3, registrarc_tarif_tiers_count($tarifs));
    assert_same(2, registrarc_tarif_tiers_count(registrarc_default_tarifs()));
});

test('tarifs : paliers alignés, palier manquant = supplément précédent', function () {
    $tarifs = registrarc_normalize_tarifs([
        'clubs_autres' => ['jeunes' => ['1' => 8, '2' => 14], 'adultes' => [1 => 10, 2 => 17, 3 => 21]],
        'organizer' => ['jeunes' => [1 => 4, 2 => 8], 'adultes' => [1 => 5, 2 => 10]],
    ]);

    assert_same([1 => 10, 2 => 17, 3 => 21], $tarifs['clubs_autres']['adultes']);
    assert_same([1 => 8, 2 => 14, 3 => 20], $tarifs['clubs_autres']['jeunes'], 'le supplément de 6 € continue');
    assert_same([1 => 4, 2 => 8, 3 => 12], $tarifs['organizer']['jeunes']);
    assert_same([1 => 0, 2 => 0], registrarc_normalize_tarifs(null)['organizer']['adultes'], 'organisateur gratuit par défaut');
    assert_same(registrarc_default_tarifs(), registrarc_normalize_tarifs(null));
});

test('règle catégorie à prix fixe', function () {
    $rules = [['active' => true, 'scope' => 'categorie', 'match' => ['BB'], 'label' => 'Bare bow', 'action' => ['type' => 'fixed_price', 'value' => 6]]];

    $r = registrarc_apply_tarif_rules(['categorie' => 'BBS1H', 'country_code' => ''], $rules);
    assert_true($r['matched']);
    assert_same(6.0, (float)$r['amount']);
    assert_same('Bare bow', $r['label']);

    $r = registrarc_apply_tarif_rules(['categorie' => 'CLS1H', 'country_code' => ''], $rules);
    assert_same(false, $r['matched']);
});

test('règle inactive ignorée', function () {
    $rules = [['active' => false, 'scope' => 'categorie', 'match' => ['CL'], 'action' => ['type' => 'fixed_price', 'value' => 1]]];
    assert_same(false, registrarc_apply_tarif_rules(['categorie' => 'CLS1H', 'country_code' => ''], $rules)['matched']);
});

test('règles région et département sur le code club', function () {
    $entry = ['categorie' => 'CLS1H', 'country_code' => '1134055'];

    assert_true(registrarc_entry_rule_matches('region', ['11'], $entry));
    assert_same(false, registrarc_entry_rule_matches('region', ['34'], $entry));
    assert_true(registrarc_entry_rule_matches('departement', ['34'], $entry));
    assert_same(false, registrarc_entry_rule_matches('departement', ['11'], $entry));
});

test('règles OUI / NON sur les épreuves', function () {
    $entry = ['categorie' => 'CLS1H', 'country_code' => '', 'EnIndClEvent' => '1', 'EnIndFEvent' => '0', 'EnTeamFEvent' => '', 'EnTeamMixEvent' => null];

    assert_true(registrarc_entry_rule_matches('qualif_ind', ['OUI'], $entry));
    assert_same(false, registrarc_entry_rule_matches('qualif_ind', ['NON'], $entry));
    assert_true(registrarc_entry_rule_matches('finale_ind', ['NON'], $entry));
    assert_same('NON', registrarc_rule_entry_value('finales', $entry));
    assert_same(false, registrarc_entry_rule_matches('inconnu', ['OUI'], $entry), 'portée inconnue');
});

test('règles cumulées : montants additionnés', function () {
    $rules = [
        ['active' => true, 'scope' => 'categorie', 'match' => ['CL'], 'label' => 'Classique', 'action' => ['type' => 'fixed_price', 'value' => 7]],
        ['active' => true, 'scope' => 'finale_ind', 'match' => ['OUI'], 'label' => 'Finale', 'action' => ['type' => 'fixed_price', 'value' => 3.5]],
    ];
    $entry = ['categorie' => 'CLS1H', 'country_code' => '', 'EnIndFEvent' => '1'];

    $r = registrarc_apply_tarif_rules($entry, $rules);
    assert_same(10.5, (float)$r['amount']);
    assert_same(2, $r['count']);
    assert_same('Classique + Finale', $r['label']);
});
