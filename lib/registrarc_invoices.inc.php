<?php
// ============================================================================
// RegistrArc - lib/registrarc_invoices.inc.php
// Factures émises : numérotation continue par année (2026-0001, 2026-0002…),
// commune à tous les concours, et copie figée de chaque facture pour la
// réimprimer à l'identique.
//
// Fichier : data/invoices.json
// { "counters": { "2026": 12 }, "invoices": { "2026-0012": { … } } }
// ============================================================================

function registrarc_invoices_file_path() {
    return registrarc_data_dir() . '/invoices.json';
}

function registrarc_load_invoice_registry() {
    $data = registrarc_load_json_file(registrarc_invoices_file_path());

    return [
        'counters' => isset($data['counters']) && is_array($data['counters']) ? $data['counters'] : [],
        'invoices' => isset($data['invoices']) && is_array($data['invoices']) ? $data['invoices'] : [],
    ];
}

// $snapshot : contenu figé (lignes, total, destinataires, concours)
// Retourne le numéro attribué, ou '' en cas d'échec
function registrarc_issue_invoice($TourId, array $ids, array $snapshot) {
    $number = '';
    $year = date('Y');

    $saved = registrarc_update_json_file(registrarc_invoices_file_path(), function (array &$registry) use ($TourId, $ids, $snapshot, $year, &$number) {
        if (!isset($registry['counters']) || !is_array($registry['counters'])) {
            $registry['counters'] = [];
        }

        if (!isset($registry['invoices']) || !is_array($registry['invoices'])) {
            $registry['invoices'] = [];
        }

        $next = (isset($registry['counters'][$year]) ? intval($registry['counters'][$year]) : 0) + 1;
        $number = $year . '-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
        $registry['counters'][$year] = $next;

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $registry['invoices'][$number] = [
            'number' => $number,
            'tour_id' => intval($TourId),
            'tour_code' => registrarc_current_tournament_code(),
            'engagement_ids' => $ids,
            'issued_at' => date('Y-m-d H:i:s'),
            'operator' => registrarc_operator_name(),
            'snapshot' => $snapshot,
        ];
    });

    return $saved ? $number : '';
}

function registrarc_find_invoice($number) {
    $registry = registrarc_load_invoice_registry();

    return isset($registry['invoices'][$number]) ? $registry['invoices'][$number] : null;
}

// Factures émises pour ce concours, de la plus récente à la plus ancienne
function registrarc_invoices_for_tour($TourId) {
    $out = [];

    foreach (registrarc_load_invoice_registry()['invoices'] as $number => $invoice) {
        if (intval($invoice['tour_id']) === intval($TourId)) {
            $out[$number] = $invoice;
        }
    }

    krsort($out);

    return $out;
}

// Facture déjà émise pour exactement ces engagements, ou null
function registrarc_invoice_for_ids($TourId, array $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);

    foreach (registrarc_invoices_for_tour($TourId) as $invoice) {
        if ($invoice['engagement_ids'] === $ids) {
            return $invoice;
        }
    }

    return null;
}
