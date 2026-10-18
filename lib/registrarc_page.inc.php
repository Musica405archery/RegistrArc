<?php
// ============================================================================
// RegistrArc - lib/registrarc_page.inc.php
// Début commun à toutes les pages du module : environnement I@nseo,
// bibliothèques RegistrArc et noms du module.
//
// Utilisation, en tout premier dans la page :
//   require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');
//   $TourId = registrarc_page_tour_id();
// ============================================================================

if (!defined('debug')) {
    define('debug', false);
}

require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once(dirname(__FILE__) . '/registrarc.inc.php');

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

// Tournoi ouvert en session, après contrôle du droit demandé sur les participants
function registrarc_page_tour_id($access = AclReadOnly) {
    CheckTourSession(true);
    checkACL(AclParticipants, $access);

    $TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

    if (!$TourId) {
        die('Tournoi non défini');
    }

    return $TourId;
}
