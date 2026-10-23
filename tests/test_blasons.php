<?php
// Outil de développement : uniquement en ligne de commande, jamais depuis le navigateur
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Blasons : lecture de TargetFaces, images d'I@nseo, libellé du kiosque

test('blasons : un type par cible / diamètre distinct, image d\'I@nseo ou générique', function () {
    global $CFG;

    $images = $CFG->DOCUMENT_PATH . 'Common/Images/Targets';
    @mkdir($images, 0775, true);
    file_put_contents($images . '/5.svg', '<svg/>');
    file_put_contents($images . '/99.svg', '<svg/>');

    $face = function ($id, $name, $numDist, array $t) {
        $row = ['EnId' => $id, 'TfId' => $id, 'TfName' => $name, 'ToNumDist' => $numDist];

        for ($i = 1; $i <= 8; $i++) {
            $row['TfT' . $i] = isset($t[$i - 1]) ? $t[$i - 1][0] : 0;
            $row['TfW' . $i] = isset($t[$i - 1]) ? $t[$i - 1][1] : 0;
        }

        return (object)$row;
    };

    $GLOBALS['test_db']['targetfaces'] = [
        $face(1, 'Piquet Bleu', 2, [[5, 80], [5, 80], [7, 122]]),   // 3e distance hors concours : ignorée
        $face(2, 'Mixte', 2, [[5, 40], [42, 60]]),                  // pas d'image 42 : image générique
        $face(3, '', 1, [[0, 0]]),                                   // aucune cible
    ];

    try {
        $faces = registrarc_load_target_faces(1);

        assert_same(1, count($faces[1]['faces']), 'mêmes cible et diamètre sur les deux distances');
        assert_same(['target' => 5, 'size' => 80, 'image' => '/Common/Images/Targets/5.svg'], $faces[1]['faces'][0]);
        assert_same('Piquet Bleu · 80 cm', registrarc_target_face_label($faces[1]));

        assert_same('/Common/Images/Targets/99.svg', $faces[2]['faces'][1]['image'], 'image générique');
        assert_same('Mixte · 40 cm / 60 cm', registrarc_target_face_label($faces[2]));

        assert_same([], $faces[3]['faces']);
        assert_same('', registrarc_target_face_label($faces[3]));
        assert_same('', registrarc_target_face_label(null), 'archer sans blason');
    } finally {
        @unlink($images . '/5.svg');
        @unlink($images . '/99.svg');
        @rmdir($images);
        @rmdir(dirname($images));
        @rmdir(dirname(dirname($images)));
        @rmdir($CFG->DOCUMENT_PATH);
    }
});

test('blasons : rouge seulement si la catégorie a un blason par défaut et que l\'archer en a un autre', function () {
    $face = function ($id, $tfId, $name) {
        $row = ['EnId' => $id, 'TfId' => $tfId, 'TfName' => $name, 'ToNumDist' => 1];

        for ($i = 1; $i <= 8; $i++) {
            $row['TfT' . $i] = $i === 1 ? 5 : 0;
            $row['TfW' . $i] = $i === 1 ? 80 : 0;
        }

        return (object)$row;
    };
    $candidate = function ($id, $tfId, $name, $default) {
        return (object)['EnId' => $id, 'TfId' => $tfId, 'TfName' => $name, 'TfDefault' => $default];
    };

    $GLOBALS['test_db']['targetfaces'] = [
        $face(1, 2, 'Piquet Bleu'),   // blason par défaut de sa catégorie
        $face(2, 3, 'Piquet Blanc'),  // autre que le défaut (Piquet Bleu)
        $face(3, 4, 'Spécial'),       // catégorie sans blason marqué par défaut
    ];

    // Déjà triés comme la requête : par archer, le premier est celui d'I@nseo
    $GLOBALS['test_db']['defaultfaces'] = [
        $candidate(1, 2, 'Piquet Bleu', 1),
        $candidate(1, 3, 'Piquet Blanc', 0),
        $candidate(2, 2, 'Piquet Bleu', 1),
        $candidate(2, 3, 'Piquet Blanc', 0),
        $candidate(3, 4, 'Spécial', 0),
        $candidate(3, 5, 'Autre', 0),
    ];

    $faces = registrarc_load_target_faces(1);

    assert_true($faces[1]['is_default'], 'blason par défaut');
    assert_same(false, $faces[2]['is_default'], 'blason changé');
    assert_same('Piquet Bleu', $faces[2]['default_name']);
    assert_true($faces[3]['is_default'], 'pas de blason par défaut défini : pas de rouge');
});
