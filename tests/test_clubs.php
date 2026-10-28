<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Ville des clubs pour la carte : détection dans le nom, corrections, repli au département

test('villes : nom normalisé (accents, St, tirets)', function () {
    assert_same('les archers de saint priest', registrarc_normalize_place('Les Archers de St-Priest'));
    assert_same('compagnie d arc de sainte foy les lyon', registrarc_normalize_place("Compagnie d'Arc de Ste-Foy-lès-Lyon"));
});

test('villes : détection dans le département du club, la commune la plus longue l\'emporte', function () {
    $found = registrarc_detect_club_city('Les Archers de St Priest', '69');
    assert_same('Saint-Priest', $found[0]);
    assert_true(abs($found[1] - 45.70) < 0.05 && abs($found[2] - 4.95) < 0.05, 'coordonnées de Saint-Priest');

    assert_same('Saint-Genis-Laval', registrarc_detect_club_city('Arc Club de Saint-Genis-Laval', '69')[0], 'pas Saint-Genis-les-Ollières');
    assert_same('Brest', registrarc_detect_club_city(['Arc Club', 'Union Sportive des Archers de Brest'], '29')[0], 'nom complet du club');
    assert_same(null, registrarc_detect_club_city('Flèche d\'Or', '12'), 'aucune ville dans le nom');
    assert_same(null, registrarc_detect_club_city('Les Archers de Brest', '69'), 'Brest n\'est pas dans le Rhône');
});

test('villes : correction mémorisée par numéro de club, contrôlée dans son département', function () {
    assert_same('', registrarc_set_club_city('1069012', 'saint priest'));
    assert_same(['Saint-Priest'], array_column(registrarc_load_club_cities(), 0), 'nom officiel enregistré');

    assert_true(registrarc_set_club_city('1069012', 'Brest') !== '', 'Brest refusé pour un club du Rhône');

    $loc = registrarc_club_location('1069012', 'Les Archers de Villeurbanne');
    assert_same(['Saint-Priest', 'manual'], [$loc['city'], $loc['source']], 'la correction l\'emporte sur la détection');

    assert_same('', registrarc_set_club_city('1069012', ''));
    $loc = registrarc_club_location('1069012', 'Les Archers de Villeurbanne');
    assert_same(['Villeurbanne', 'auto'], [$loc['city'], $loc['source']], 'correction effacée : détection');
});

test('villes : centre du département à défaut, rien pour un club étranger', function () {
    $loc = registrarc_club_location('1012001', 'Flèche d\'Or');
    assert_same('departement', $loc['source']);
    assert_same(registrarc_departements()['12'][1], $loc['lat']);

    assert_same(null, registrarc_club_location('ESP', 'Club de Tiro'));
    assert_same(null, registrarc_club_location('1099000', 'Club inconnu'));
});

test('villes : position de chaque club transmise au tableau de bord', function () {
    test_add_entry('A1', 'CLS1H', '1069012');
    test_add_entry('B1', 'CLS1H', '1099000');
    $GLOBALS['test_db']['entries'][0]->club = 'Archers de Saint-Priest';
    registrarc_load_engagements(1, true);

    $d = registrarc_dashboard_data(1);
    assert_same(['Saint-Priest', 'auto'], [$d['club_places'][0][0], $d['club_places'][0][3]]);
    assert_same(null, $d['club_places'][1], 'club sans département');
});
