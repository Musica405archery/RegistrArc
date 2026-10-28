<?php
// ============================================================================
// RegistrArc - lib/registrarc_clubs.inc.php
// Ville de chaque club, pour la carte du tableau de bord.
//
// I@nseo ne connaît pas la ville d'un club. RegistrArc la trouve ainsi :
// 1. correction saisie dans la page « Villes des clubs » (clubs.php), mémorisée
//    par numéro de club pour tous les concours (data/club_cities.json) ;
// 2. sinon, détection : une commune du département du club (3e et 4e chiffres
//    du numéro FFTA) dont le nom figure dans le nom du club ;
// 3. sinon, centre du département (position approximative).
//
// Communes : lib/data/communes.json (geo.api.gouv.fr, Licence Ouverte),
// livré avec le module : aucun appel extérieur.
// ============================================================================

// Mots trop courants dans les noms de clubs pour désigner une commune
define('REGISTRARC_CITY_STOPWORDS', ' arc arcs les arcs tir club cible or fleche archers compagnie ');

function registrarc_club_cities_file_path() {
    return registrarc_data_dir() . '/club_cities.json';
}

// Communes d'un département : [[nom, latitude, longitude], ...] ; « 20 » = toute la Corse
function registrarc_communes($dep) {
    static $all = null;

    if ($all === null) {
        $data = @file_get_contents(dirname(__FILE__) . '/data/communes.json');
        $all = $data !== false ? json_decode($data, true) : [];
        $all = is_array($all) ? $all : [];
    }

    if ($dep === '20') {
        return array_merge(isset($all['2A']) ? $all['2A'] : [], isset($all['2B']) ? $all['2B'] : []);
    }

    return isset($all[$dep]) && is_array($all[$dep]) ? $all[$dep] : [];
}

// Forme comparable d'un nom : minuscules sans accents, « St » = « Saint », mots séparés d'une espace
function registrarc_normalize_place($text) {
    $text = mb_strtolower(trim((string)$text), 'UTF-8');
    $text = strtr($text, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ú' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'æ' => 'ae',
    ]);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = preg_replace('/\bste\b/', 'sainte', $text);
    $text = preg_replace('/\bst\b/', 'saint', $text);

    return trim(preg_replace('/\s+/', ' ', $text));
}

// Commune du département dont le nom apparaît dans le nom du club (la plus longue l'emporte)
// Retourne [nom, latitude, longitude] ou null
function registrarc_detect_club_city($clubNames, $dep) {
    static $normalized = [];

    $haystack = ' ' . registrarc_normalize_place(is_array($clubNames) ? implode(' ', $clubNames) : $clubNames) . ' ';

    if (trim($haystack) === '' || $dep === '') {
        return null;
    }

    if (!isset($normalized[$dep])) {
        $normalized[$dep] = [];

        foreach (registrarc_communes($dep) as $commune) {
            $name = registrarc_normalize_place($commune[0]);

            if (strlen($name) >= 3 && strpos(REGISTRARC_CITY_STOPWORDS, ' ' . $name . ' ') === false) {
                $normalized[$dep][] = [$name, $commune];
            }
        }
    }

    $best = null;

    foreach ($normalized[$dep] as $item) {
        if (strpos($haystack, ' ' . $item[0] . ' ') !== false && ($best === null || strlen($item[0]) > strlen($best[0]))) {
            $best = $item;
        }
    }

    return $best ? $best[1] : null;
}

// Corrections saisies : [numéro de club => [nom de commune, latitude, longitude]]
function registrarc_load_club_cities() {
    return registrarc_load_json_file(registrarc_club_cities_file_path());
}

// Enregistre (ou efface si $city est vide) la ville d'un club ; la commune doit
// appartenir au département du club. Retourne '' si tout va bien, sinon le message d'erreur
function registrarc_set_club_city($clubCode, $city) {
    $clubCode = trim((string)$clubCode);
    $city = trim((string)$city);
    $found = null;

    if ($clubCode === '') {
        return 'Numéro de club manquant.';
    }

    if ($city !== '') {
        $dep = registrarc_club_departement($clubCode);
        $wanted = registrarc_normalize_place($city);

        foreach ($dep !== '' ? registrarc_communes($dep) : [] as $commune) {
            if (registrarc_normalize_place($commune[0]) === $wanted) {
                $found = $commune;
                break;
            }
        }

        if ($found === null) {
            return '« ' . $city . ' » n\'est pas une commune du département ' . ($dep !== '' ? $dep : 'du club') . '.';
        }
    }

    $saved = registrarc_update_json_file(registrarc_club_cities_file_path(), function (array &$cities) use ($clubCode, $found) {
        if ($found === null) {
            unset($cities[$clubCode]);
        } else {
            $cities[$clubCode] = $found;
        }
    });

    return $saved ? '' : 'Enregistrement impossible.' . registrarc_storage_error_suffix();
}

// Position d'un club : ['city' => commune ou '', 'lat', 'lon', 'source' => manual|auto|departement]
// ou null si son département est inconnu (club étranger, numéro sans département)
function registrarc_club_location($clubCode, $clubNames, $cities = null) {
    $clubCode = trim((string)$clubCode);
    $dep = registrarc_club_departement($clubCode);

    if ($dep === '') {
        return null;
    }

    if ($cities === null) {
        $cities = registrarc_load_club_cities();
    }

    if (isset($cities[$clubCode]) && is_array($cities[$clubCode])) {
        $c = $cities[$clubCode];

        return ['city' => (string)$c[0], 'lat' => (float)$c[1], 'lon' => (float)$c[2], 'source' => 'manual'];
    }

    $found = registrarc_detect_club_city($clubNames, $dep);

    if ($found) {
        return ['city' => $found[0], 'lat' => $found[1], 'lon' => $found[2], 'source' => 'auto'];
    }

    $center = registrarc_departements()[$dep];

    return ['city' => '', 'lat' => $center[1], 'lon' => $center[2], 'source' => 'departement'];
}
