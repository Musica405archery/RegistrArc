<?php
// ============================================================================
// RegistrArc - lib/registrarc_dashboard.inc.php
// Données du tableau de bord (dashboard.php), mises en forme dans le navigateur
// par assets/js/dashboard.js : pages Greffe, Trésorier et Terrain.
//
// Aucun calcul de montant ici : tout vient de registrarc_load_engagements(),
// du journal de caisse, des remises et des clôtures. Aucun nom d'archer ni
// numéro de licence n'est transmis : chaque archer devient un simple numéro.
// ============================================================================

function registrarc_dashboard_data($TourId) {
    $engagements = registrarc_load_engagements($TourId);
    $ageClasses = registrarc_load_age_classes($TourId);

    $clubs = [];
    $clubDeps = [];
    $clubPlaces = [];
    $clubCities = registrarc_load_club_cities();
    $clubIndex = [];
    $archerIndex = [];
    $sessions = [];
    $records = [];

    foreach ($engagements as $e) {
        $club = $e['club'] !== '' ? $e['club'] : 'Sans club';

        if (!isset($clubIndex[$club])) {
            $clubIndex[$club] = count($clubs);
            $clubs[] = $club;
            $clubDeps[] = registrarc_club_departement($e['country_code']);

            // Ville du club (corrigée, détectée dans son nom, ou centre du département)
            $place = registrarc_club_location($e['country_code'], [$club, $e['club_full']], $clubCities);
            $clubPlaces[] = $place ? [$place['city'], round($place['lat'], 4), round($place['lon'], 4), $place['source']] : null;
        }

        if (!isset($archerIndex[$e['licence']])) {
            $archerIndex[$e['licence']] = count($archerIndex);
        }

        $sessions[$e['session']] = true;
        $special = registrarc_engagement_special_label($e);

        $records[] = [
            's' => (string)$e['session'],
            'c' => $clubIndex[$club],
            'a' => $archerIndex[$e['licence']],
            'st' => $e['state'],                                            // paid, due, free, refunded
            'm' => $e['state'] === 'paid' ? $e['payment_method'] : '',
            'p' => $e['state'] === 'paid' ? round((float)$e['amount'], 2) : 0,           // encaissé
            'd' => $e['state'] === 'due' ? round((float)$e['amount_due'], 2) : 0,        // reste dû
            'cat' => $e['categorie'],
            'age' => registrarc_age_category($e['categorie'], $ageClasses),            // jeunes, adultes
            't' => $special !== '' ? $special : 'Standard',                            // tarif
            'u' => $e['target_is_unassigned'] ? 1 : 0,                                 // sans cible
            'l' => $e['late'] ? 1 : 0,                                                 // inscription tardive
            'up' => !empty($e['is_upgraded']) ? 1 : 0,                                 // surclassé
            'r' => $e['receipt_required'] ? 1 : 0,                                     // reçu demandé
            'n' => $e['note'] !== '' ? 1 : 0,                                          // note
            'dep' => $e['deposit_id'] !== '' ? 1 : 0,                                  // chèque déposé
        ];
    }

    $sessions = array_map('strval', array_keys($sessions));
    sort($sessions, SORT_NATURAL);

    // Opérations de caisse : [date, type, montant, mode, départ, opérateur, poste, origine]
    $journal = [];

    foreach (registrarc_load_journal($TourId) as $entry) {
        if (!isset($entry['type']) || !registrarc_journal_is_cash($entry['type'])) {
            continue;
        }

        $id = isset($entry['engagement_id']) ? intval($entry['engagement_id']) : 0;
        $device = isset($entry['device']) ? trim((string)$entry['device']) : '';

        $journal[] = [
            isset($entry['at']) ? (string)$entry['at'] : '',
            (string)$entry['type'],
            round(isset($entry['amount']) ? (float)$entry['amount'] : 0, 2),
            isset($entry['method']) ? (string)$entry['method'] : '',
            isset($engagements[$id]) ? (string)$engagements[$id]['session'] : '',
            isset($entry['operator']) && trim((string)$entry['operator']) !== '' ? trim((string)$entry['operator']) : '',
            $device !== '' ? strtok($device, ' ') : '',                                // Téléphone, Ordinateur, Tablette
            isset($entry['source']) ? (string)$entry['source'] : '',
        ];
    }

    $deposits = [];

    foreach (registrarc_load_deposits($TourId) as $deposit) {
        $deposits[] = [
            'id' => (string)$deposit['id'],
            'number' => intval($deposit['number']),
            'at' => (string)$deposit['created_at'],
            'count' => intval($deposit['count']),
            'total' => round((float)$deposit['total'], 2),
        ];
    }

    usort($deposits, function ($a, $b) {
        return $a['number'] - $b['number'];
    });

    $closures = [];

    foreach (registrarc_load_closures($TourId) as $closure) {
        $closures[] = [
            'id' => (string)$closure['id'],
            'to' => (string)$closure['to'],
            'expected' => round((float)$closure['cash_expected'], 2),
            'counted' => $closure['cash_counted'] === null ? null : round((float)$closure['cash_counted'], 2),
            'diff' => $closure['cash_difference'] === null ? null : round((float)$closure['cash_difference'], 2),
        ];
    }

    // Carte : seulement les départements des clubs présents
    $allDeps = registrarc_departements();
    $departements = [];

    foreach (array_unique(array_filter($clubDeps)) as $code) {
        $departements[$code] = $allDeps[$code];
    }

    return [
        'now' => date('Y-m-d H:i:s'),
        'records' => $records,
        'clubs' => $clubs,
        'club_deps' => $clubDeps,          // département de chaque club ('' si inconnu)
        'club_places' => $clubPlaces,      // [commune, latitude, longitude, manual|auto|departement] ou null
        'departements' => (object)$departements,   // code => [nom, latitude, longitude], toujours un objet JSON
        'sessions' => $sessions,
        'modes' => registrarc_load_payment_modes($TourId),
        'journal' => $journal,
        'deposits' => $deposits,
        'closures' => $closures,
    ];
}

// Département d'un club d'après son numéro FFTA (3e et 4e chiffres, comme les
// règles de tarif par département) ; '' si le numéro ne correspond à aucun département
function registrarc_club_departement($clubCode) {
    $digits = preg_replace('/\D+/', '', (string)$clubCode);

    if (strlen($digits) < 4) {
        return '';
    }

    $code = substr($digits, 2, 2);
    $departements = registrarc_departements();

    return isset($departements[$code]) ? $code : '';
}
