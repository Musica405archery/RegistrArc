<?php
// ============================================================================
// RegistrArc - fdm.php
// Feuilles de marque d'un ou plusieurs archers, avec les flèches saisies dans
// I@nseo, le total de chaque volée et le cumul. Tout est fait par I@nseo :
// - un archer : CreateSessionScorecard(), l'impression « feuille personnelle
//   remplie » d'I@nseo (toutes les distances du départ sur la même feuille) ;
// - plusieurs archers : les mêmes fonctions d'I@nseo (QualificationScorecards()
//   pour les données, ScorePDF::DrawScoreNew() pour le dessin), une page par
//   archer dans un seul PDF, placées comme le fait CreateSessionScorecard().
// Lecture seule : rien n'est écrit dans I@nseo.
//
// fdm.php?ids=12,15,40 : numéros d'engagement (EnId), dans l'ordre d'impression
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();
// Mêmes droits que l'impression des feuilles de marque dans I@nseo
checkFullACL(AclQualification, '', AclReadOnly);

require_once('Common/pdf/ScorePDF.inc.php');
require_once('Common/Fun_FormatText.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once('Common/Lib/ScorecardsLib.php');

$message = function ($text) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit();
};

// ---------------------------------------------------------------------------
// Archers demandés : seulement des engagements de ce tournoi, placés sur un départ
// ---------------------------------------------------------------------------
$engagements = registrarc_load_engagements($TourId);
$ids = [];

foreach (explode(',', isset($_GET['ids']) ? (string)$_GET['ids'] : '') as $id) {
    $id = intval($id);

    if ($id > 0 && isset($engagements[$id]) && $engagements[$id]['session'] !== '' && !in_array($id, $ids, true)) {
        $ids[] = $id;
    }
}

$ids = array_slice($ids, 0, 300);

if (empty($ids)) {
    $message("Aucune feuille de marque à imprimer : choisissez des archers placés sur un départ.");
}

// Toutes les distances du concours sur la même feuille
$numDist = 1;
$rs = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId = " . intval($TourId));

if ($row = safe_fetch($rs)) {
    $numDist = max(1, min(8, intval($row->ToNumDist)));
}

$field3D = !empty($_SESSION['TourField3D']);

// Réglages de la page d'impression des feuilles de marque d'I@nseo
// (Qualification/PrintScore.php), avec ses choix par défaut pour l'en-tête
$options = [
    'ScoreDraw' => 'CompleteTotals',    // feuille complète (schéma + données) + colonne des totaux progressifs
    'PersonalScore' => '1',             // toutes les distances de l'archer sur une seule page
    'ScoreFilled' => '1',               // avec les résultats des flèches
    'ScoreDist' => range(1, $numDist),
    'ScoreFlags' => '1',                // drapeau / logo du club
    'ScorePageHeaderFooter' => '1',     // en-tête et pied de page sur la page entière…
    'ScoreHeader' => '0',               // …qui remplace l'en-tête et le logo de la compétition
    'ScoreLogos' => '0',
    'ScoreBarcode' => '0',
];

if ($field3D) {
    $options['TourField3D'] = $_SESSION['TourField3D'];
}

$fileName = 'FdM_' . registrarc_safe_filename_part(registrarc_current_tournament_code()) . '_' . date('Ymd_His') . '.pdf';

// ---------------------------------------------------------------------------
// Un archer : l'impression d'I@nseo telle quelle
// ---------------------------------------------------------------------------
if (count($ids) === 1) {
    CreateSessionScorecard(intval($engagements[$ids[0]]['session']), 1, 999, $options + ['Entry' => $ids[0]])->Output($fileName, 'I');
    exit();
}

// Parcours, 3D ou plus de 4 distances : mises en page propres à I@nseo, non reprises ici
if ($field3D || $numDist > 4) {
    $message("Pour ce type de concours, imprimez les feuilles de marque une par une (bouton « Imprimer » de chaque ligne).");
}

// ---------------------------------------------------------------------------
// Plusieurs archers : une page par archer dans un seul PDF, dessinée par I@nseo
// ---------------------------------------------------------------------------
// Mêmes réglages, dans le même ordre, que CreateSessionScorecard() avec $options
$pdf = new ScorePDF(true);
$pdf->Aruco = 0;
$pdf->ArucoSize = 0;
$pdf->ArucoType = 0;
$pdf->ArucoMarker = null;
$pdf->FillWithArrows = true;
$pdf->HideHeader();
$pdf->HideLogo();
$pdf->FullHeaderShow(true);
$pdf->PrintTotalColumns();

// Taille et position des feuilles : mêmes calculs que CreateSessionScorecard()
// pour une feuille personnelle en tir sur cible (une feuille par distance)
$margin = $pdf->getSideMargin();
$width = ($pdf->GetPageWidth() - $margin * 3) / 2;
$height = ($pdf->GetPageHeight() - $margin * 3 - ($pdf->NoTensOnlyX ? 7 : 0)) / 2;
$x = $margin;
$x2 = $x + $margin + $width;
$y = $margin;
$y2 = $y + $margin + $height - ($pdf->PrintFullHeader ? 15 : 0);

if ($numDist <= 2) {
    $x = $margin * 3;
    $height = $pdf->GetPageWidth() - $margin * 2 - ($pdf->NoTensOnlyX ? 7 : 0) - ($pdf->PrintFullHeader ? 10 : 0);
    $width = ($pdf->GetPageHeight() - $x * 3) / 2;
} elseif ($numDist === 3) {
    $height = $pdf->GetPageWidth() - $margin * 2 - ($pdf->PrintFullHeader ? 10 : 0);
    $width = ($pdf->GetPageHeight() - $margin * 4) / 3;
}

$origins = [
    1 => [[$x, $y]],
    2 => [[$x, $y], [2 * $x + $width, $y]],
    3 => [[$x, $y], [2 * $x + $width, $y], [3 * $x + 2 * $width, $y]],
    4 => [[$x, $y], [$x2, $y], [$x, $y2], [$x2, $y2]],
][$numDist];

if ($numDist <= 3) {
    $pdf->setPageOrientation('L');
}

foreach ($ids as $id) {
    $data = QualificationScorecards(intval($engagements[$id]['session']), 1, 999, true, 'Complete', true, false, true, $options + ['Entry' => $id]);
    $pdf->LastUpdate = max((string)$pdf->LastUpdate, (string)$data->QuTimestamp);

    foreach ($data->Scores as $cards) {
        foreach ($cards as $card) {
            $card['SecondScorer'] = false;
            $pdf->AddPage();

            foreach ($options['ScoreDist'] as $k => $distance) {
                // Distance sans volée pour cet archer : I@nseo ne la dessine pas non plus
                if (empty($card['NumEnds' . $distance]) || (isset($card['D' . $distance]) && $card['D' . $distance] == '-')) {
                    continue;
                }

                $pdf->DrawScoreNew($origins[$k][0], $origins[$k][1], $width, $height, $distance, $card);
            }
        }
    }
}

$pdf->Output($fileName, 'I');
