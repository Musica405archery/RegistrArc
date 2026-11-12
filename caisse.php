<?php
// ============================================================================
// RegistrArc - caisse.php
// La caisse s'affiche dans la page Trésorier du tableau de bord
// (lib/registrarc_caisse_view.inc.php). Cette page reçoit ses formulaires et
// affiche les rapports imprimables.
//
// caisse.php?cloture=C2  : rapport d'une clôture (imprimable)
// caisse.php?imprimer=1  : caisse en cours (imprimable)
// caisse.php             : renvoie vers le tableau de bord
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc_caisse_view.inc.php');

$TourId = registrarc_page_tour_id();

$tournament = registrarc_get_tournament_info($TourId);
$dashboardUrl = 'dashboard.php?page=tresorier#caisse';

// ---------------------------------------------------------------------------
// Enregistrer le fond de caisse
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_float'])) {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        registrarc_flash('caisse', $formError, 'error');
    } elseif (registrarc_save_cash_float(
        $TourId,
        isset($_POST['float']) ? $_POST['float'] : [],
        isset($_POST['float_mode']) ? (string)$_POST['float_mode'] : 'detail',
        isset($_POST['float_amount']) ? $_POST['float_amount'] : ''
    )) {
        registrarc_flash('caisse', 'Fond de caisse enregistré.', 'success');
    } else {
        registrarc_flash('caisse', "Fond de caisse non enregistré : erreur d'écriture." . registrarc_storage_error_suffix(), 'error');
    }

    header('Location: ' . $dashboardUrl);
    exit();
}

// ---------------------------------------------------------------------------
// Clôturer la caisse
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_cash'])) {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        registrarc_flash('caisse', $formError, 'error');
    } else {
        // Comptage au choix : détaillé (pièces et billets, qui donnent le total) ou
        // simplifié (un seul montant). Seul le comptage choisi est pris en compte.
        $countMode = isset($_POST['count_mode']) && $_POST['count_mode'] === 'simple' ? 'simple' : 'detail';
        $countedCash = $countMode === 'simple' ? registrarc_parse_cash_amount(isset($_POST['cash_counted']) ? $_POST['cash_counted'] : '') : null;
        $countedDetail = $countMode === 'detail' && isset($_POST['count']) ? $_POST['count'] : [];
        $closureId = registrarc_close_cash($TourId, $countedCash, isset($_POST['comment']) ? $_POST['comment'] : '', $countedDetail);

        if ($closureId !== '') {
            registrarc_flash('caisse', 'Caisse clôturée (' . $closureId . '). Son rapport est dans « Clôtures précédentes ».', 'success');
        } else {
            registrarc_flash('caisse', "Clôture impossible : erreur d'écriture." . registrarc_storage_error_suffix(), 'error');
        }
    }

    header('Location: ' . $dashboardUrl);
    exit();
}

// ---------------------------------------------------------------------------
// Rapport imprimable : une clôture, ou la caisse en cours
// ---------------------------------------------------------------------------
$closure = null;
$closureParam = isset($_GET['cloture']) ? (string)$_GET['cloture'] : '';

foreach (registrarc_load_closures($TourId) as $item) {
    if ((string)$item['id'] === $closureParam) {
        $closure = $item;
    }
}

if (!$closure && !isset($_GET['imprimer'])) {
    header('Location: ' . $dashboardUrl);
    exit();
}

$PAGE_TITLE = 'RegistrArc - Caisse';
include('Common/Templates/head.php');
registrarc_page_open($closure ? 'Clôture de caisse ' . $closure['id'] : 'Caisse en cours', $tournament['name'], 'dashboard');
registrarc_render_caisse($TourId, $closure, 'print');
registrarc_page_close();
include('Common/Templates/tail.php');
