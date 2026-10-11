<?php
// ============================================================================
// RegistrArc - lib/registrarc_helloasso.inc.php
// Import des paiements faits en ligne (export tableur HelloAsso)
//
// 1. lecture du fichier (séparateur et encodage détectés)
// 2. colonnes devinées d'après leurs titres, modifiables par l'utilisateur
// 3. rapprochement : licence, sinon nom + prénom ; seuls les engagements
//    encore à payer sont proposés
// ============================================================================

// Retourne ['headers' => [...], 'rows' => [[...], ...]] ou ['error' => message]
function registrarc_helloasso_parse($content) {
    $content = (string)$content;

    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }

    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    $content = str_replace(["\r\n", "\r"], "\n", $content);
    $firstLine = strtok($content, "\n");

    if ($firstLine === false || trim($firstLine) === '') {
        return ['error' => 'Le fichier est vide.'];
    }

    $delimiter = ';';
    $best = 0;

    foreach ([';', ',', "\t"] as $candidate) {
        $count = substr_count($firstLine, $candidate);

        if ($count > $best) {
            $best = $count;
            $delimiter = $candidate;
        }
    }

    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $headers = null;
    $rows = [];

    while (($line = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if ($line === [null] || (count($line) === 1 && trim((string)$line[0]) === '')) {
            continue;
        }

        $line = array_map(function ($value) {
            return trim((string)$value);
        }, $line);

        if ($headers === null) {
            $headers = $line;
            continue;
        }

        $rows[] = array_pad(array_slice($line, 0, count($headers)), count($headers), '');
    }

    fclose($handle);

    if ($headers === null || empty($rows)) {
        return ['error' => 'Aucune ligne de paiement trouvée dans le fichier.'];
    }

    return ['headers' => $headers, 'rows' => $rows];
}

// Colonnes devinées d'après les titres : [rôle => index ou -1]
function registrarc_helloasso_guess_columns(array $headers) {
    $guess = ['licence' => -1, 'nom' => -1, 'prenom' => -1, 'montant' => -1, 'statut' => -1];

    foreach ($headers as $index => $header) {
        $h = mb_strtolower(registrarc_helloasso_plain($header));

        if ($guess['licence'] < 0 && strpos($h, 'licen') !== false) {
            $guess['licence'] = $index;
        } elseif ($guess['prenom'] < 0 && strpos($h, 'prenom') !== false) {
            $guess['prenom'] = $index;
        } elseif ($guess['nom'] < 0 && preg_match('/\bnom\b/', $h) && strpos($h, 'payeur') === false) {
            $guess['nom'] = $index;
        } elseif ($guess['montant'] < 0 && preg_match('/montant|amount|prix|tarif/', $h)) {
            $guess['montant'] = $index;
        } elseif ($guess['statut'] < 0 && preg_match('/statut|status|etat/', $h)) {
            $guess['statut'] = $index;
        }
    }

    return $guess;
}

// Texte sans accents ni espaces superflus, pour comparer des noms
function registrarc_helloasso_plain($value) {
    $value = trim((string)$value);
    $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if ($plain === false) {
        $plain = $value;
    }

    return preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]/', ' ', $plain));
}

function registrarc_helloasso_amount($value) {
    $value = preg_replace('/[^0-9,.\-]/', '', (string)$value);

    if ($value === '') {
        return null;
    }

    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        $value = str_replace('.', '', $value);
    }

    return (float)str_replace(',', '.', $value);
}

// Rapprochement de chaque ligne avec les engagements du concours.
// $columns : [rôle => index ou -1]
// Retourne une liste de lignes : ['line' => n°, 'label', 'amount', 'ids' => [...],
//   'due' => montant dû, 'status' => 'ok'|'amount'|'paid'|'unknown'|'ignored', 'message']
function registrarc_helloasso_match($TourId, array $rows, array $columns) {
    $engagements = registrarc_load_engagements($TourId);
    $byLicence = [];
    $byName = [];

    foreach ($engagements as $id => $e) {
        $byLicence[$e['licence']][] = $id;
        $byName[strtoupper(registrarc_helloasso_plain($e['nom'] . ' ' . $e['prenom']))][] = $id;
    }

    $get = function (array $row, $role) use ($columns) {
        $index = isset($columns[$role]) ? intval($columns[$role]) : -1;

        return $index >= 0 && isset($row[$index]) ? $row[$index] : '';
    };

    $result = [];

    foreach ($rows as $n => $row) {
        $licence = strtoupper(preg_replace('/\s+/', '', $get($row, 'licence')));
        $name = trim($get($row, 'nom') . ' ' . $get($row, 'prenom'));
        $amount = registrarc_helloasso_amount($get($row, 'montant'));
        $status = mb_strtolower(registrarc_helloasso_plain($get($row, 'statut')));
        $line = ['line' => $n + 2, 'label' => trim($name . ($licence !== '' ? ' (' . $licence . ')' : '')), 'amount' => $amount, 'ids' => [], 'due' => 0.0];

        if ($status !== '' && !preg_match('/valid|pay|paye|termin|ok/', $status)) {
            $result[] = $line + ['status' => 'ignored', 'message' => 'Paiement non validé (« ' . $get($row, 'statut') . ' »)'];
            continue;
        }

        $ids = [];

        if ($licence !== '' && isset($byLicence[$licence])) {
            $ids = $byLicence[$licence];
        } elseif ($name !== '' && isset($byName[strtoupper(registrarc_helloasso_plain($name))])) {
            $ids = $byName[strtoupper(registrarc_helloasso_plain($name))];
        }

        if (empty($ids)) {
            $result[] = $line + ['status' => 'unknown', 'message' => 'Archer introuvable dans ce concours'];
            continue;
        }

        $dueIds = [];
        $due = 0.0;

        foreach ($ids as $id) {
            if ($engagements[$id]['state'] === 'due') {
                $dueIds[] = $id;
                $due += $engagements[$id]['amount_due'];
            }
        }

        $first = $engagements[$ids[0]];
        $line['label'] = trim($first['nom'] . ' ' . $first['prenom']) . ' (' . $first['licence'] . ')';

        if (empty($dueIds)) {
            $result[] = $line + ['status' => 'paid', 'message' => 'Rien à encaisser : déjà payé ou gratuit'];
            continue;
        }

        $line['ids'] = $dueIds;
        $line['due'] = round($due, 2);

        if ($amount !== null && round($amount, 2) < round($due, 2)) {
            $result[] = $line + ['status' => 'amount', 'message' => 'Montant payé (' . registrarc_format_montant($amount) . ' €) inférieur au montant dû (' . registrarc_format_montant($due) . ' €)'];
            continue;
        }

        $result[] = $line + ['status' => 'ok', 'message' => count($dueIds) . ' départ(s) à encaisser'];
    }

    return $result;
}
