<?php
// ============================================================================
// RegistrArc - lib/registrarc_engagements.inc.php
// Engagements du tournoi et montants dus, calculés une seule fois pour toutes
// les pages (liste, kiosque, factures, remise de chèques, caisse, tableau de
// bord, export).
//
// Calcul d'un engagement :
// 1. prix de base : règle avancée si elle s'applique, sinon tarif selon le club,
//    l'âge et le numéro du départ (1er départ / supplément)
// 2. plafond par archer : le total d'un archer ne dépasse pas le plafond
// 3. majoration pour inscription tardive, ajoutée après le plafond
//
// Fichiers JSON propres au tournoi :
// - archers_<TourId>.json  : note et inscription tardive, par engagement
// ============================================================================

// ---------------------------------------------------------------------------
// Options de tarification (réglages du tournoi)
// ---------------------------------------------------------------------------
function registrarc_default_pricing_options() {
    return [
        'cap_per_archer' => 0,
        'late_fee' => 0,
    ];
}

function registrarc_normalize_pricing_options($options) {
    $out = registrarc_default_pricing_options();

    if (!is_array($options)) {
        return $out;
    }

    foreach (array_keys($out) as $key) {
        if (isset($options[$key]) && is_numeric($options[$key])) {
            $out[$key] = max(0, round((float)$options[$key], 2));
        }
    }

    return $out;
}

function registrarc_load_pricing_options($TourId) {
    $settings = registrarc_load_settings($TourId);

    return registrarc_normalize_pricing_options(isset($settings['options']) ? $settings['options'] : null);
}

// ---------------------------------------------------------------------------
// Note et inscription tardive, par engagement
// ---------------------------------------------------------------------------
function registrarc_archers_file_path($TourId) {
    return registrarc_data_dir() . '/archers_' . intval($TourId) . '.json';
}

function registrarc_load_archer_info($TourId) {
    return registrarc_load_json_file(registrarc_archers_file_path($TourId));
}

function registrarc_update_archer_info($TourId, callable $mutator) {
    return registrarc_update_json_file(registrarc_archers_file_path($TourId), $mutator);
}

// ---------------------------------------------------------------------------
// Statut d'un paiement
// ---------------------------------------------------------------------------
// 'paid' payé, 'refunded' remboursé, 'free' rien à payer, 'due' à payer
function registrarc_engagement_state(array $payment, $amountDue) {
    $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : 'NON_PAYE';

    if ($status === 'PAYE') {
        return 'paid';
    }

    if ($status === 'REMBOURSE') {
        return 'refunded';
    }

    return registrarc_nothing_due($amountDue) ? 'free' : 'due';
}

function registrarc_state_label($state) {
    $labels = [
        'paid' => 'Payé',
        'refunded' => 'Remboursé',
        'free' => 'Gratuit',
        'due' => 'Non payé',
    ];

    return isset($labels[$state]) ? $labels[$state] : $state;
}

// ---------------------------------------------------------------------------
// Chargement et calcul
// ---------------------------------------------------------------------------
// Retourne [EnId => engagement], dans l'ordre licence / départ / cible.
// Mis en cache pour la requête ; $refresh force un nouveau calcul (après une écriture).
function registrarc_load_engagements($TourId, $refresh = false) {
    static $cache = [];

    $TourId = intval($TourId);

    if (!$refresh && isset($cache[$TourId])) {
        return $cache[$TourId];
    }

    $tournament = registrarc_get_tournament_info($TourId);
    $organizerClubCode = $tournament['organizer_code'];
    $tarifs = registrarc_load_tarifs($TourId, $organizerClubCode, $tournament['organizer_name']);
    $ageClasses = registrarc_load_age_classes($TourId);
    $rules = registrarc_load_tarif_rules($TourId);
    $options = registrarc_load_pricing_options($TourId);
    $payments = registrarc_load_payments($TourId);
    $archerInfo = registrarc_load_archer_info($TourId);

    $query = "
        SELECT
            e.EnId AS engagement_id,
            UPPER(TRIM(e.EnCode)) AS licence,
            UPPER(TRIM(e.EnFirstName)) AS nom,
            TRIM(e.EnName) AS prenom,
            CONCAT(e.EnDivision, e.EnClass) AS categorie,
            c.CoName AS club,
            c.CoCode AS country_code,
            q.QuSession AS session_no,
            q.QuTarget AS target,
            q.QuLetter AS letter,
            e.EnIndClEvent,
            e.EnTeamClEvent,
            e.EnIndFEvent,
            e.EnTeamFEvent,
            e.EnTeamMixEvent
        FROM Entries e
        LEFT JOIN Countries c
            ON e.EnCountry = c.CoId
           AND e.EnTournament = c.CoTournament
        LEFT JOIN Qualifications q
            ON e.EnId = q.QuId
        WHERE e.EnTournament = $TourId
          AND e.EnAthlete = 1
          AND TRIM(e.EnCode) <> ''
        ORDER BY UPPER(TRIM(e.EnCode)), q.QuSession, q.QuTarget, q.QuLetter, e.EnId
    ";

    $rs = safe_r_sql($query);
    $engagements = [];
    $byLicence = [];

    while ($rs && ($row = safe_fetch($rs))) {
        $id = intval($row->engagement_id);
        $licence = strtoupper(trim((string)$row->licence));
        $byLicence[$licence][] = $id;

        $target = trim((string)$row->target);
        $letter = trim((string)$row->letter);
        $unassigned = registrarc_is_null_empty_or_zero($row->target) || registrarc_is_null_empty_or_zero($row->letter);

        $entry = [
            'engagement_id' => $id,
            'licence' => $licence,
            'nom' => strtoupper(trim((string)$row->nom)),
            'prenom' => trim((string)$row->prenom),
            'categorie' => trim((string)$row->categorie),
            'club' => trim((string)$row->club),
            'country_code' => trim((string)$row->country_code),
            'session' => trim((string)$row->session_no),
            'target' => $target,
            'letter' => $letter,
            'target_no' => $unassigned ? '' : trim($target . $letter),
            'target_label' => $unassigned ? 'Archer non affecté' : trim($target . $letter),
            'target_is_unassigned' => $unassigned,
            'EnIndClEvent' => $row->EnIndClEvent,
            'EnTeamClEvent' => $row->EnTeamClEvent,
            'EnIndFEvent' => $row->EnIndFEvent,
            'EnTeamFEvent' => $row->EnTeamFEvent,
            'EnTeamMixEvent' => $row->EnTeamMixEvent,
            'numero' => count($byLicence[$licence]),
        ];

        // 1. Prix de base
        $ruleResult = registrarc_apply_tarif_rules($entry, $rules);

        if (!empty($ruleResult['matched'])) {
            $entry['base_amount'] = (float)$ruleResult['amount'];
            $entry['rule_label'] = $ruleResult['label'];
            $entry['rule_count'] = intval($ruleResult['count']);
        } else {
            $entry['base_amount'] = (float)registrarc_prix_engagement(
                $entry['country_code'],
                $entry['categorie'],
                $entry['numero'],
                $tarifs,
                $organizerClubCode,
                $ageClasses
            );
            $entry['rule_label'] = '';
            $entry['rule_count'] = 0;
        }

        $info = isset($archerInfo[(string)$id]) && is_array($archerInfo[(string)$id]) ? $archerInfo[(string)$id] : [];
        $entry['note'] = isset($info['note']) ? trim((string)$info['note']) : '';
        $entry['late'] = !empty($info['late']);

        $entry['adjustments'] = [];
        $entry['amount_due'] = $entry['base_amount'];

        $engagements[$id] = $entry;
    }

    // 2. Plafond par archer : on retire l'excédent en partant du dernier départ
    if ($options['cap_per_archer'] > 0) {
        foreach ($byLicence as $licence => $ids) {
            $total = 0;

            foreach ($ids as $id) {
                $total += $engagements[$id]['amount_due'];
            }

            $excess = round($total - $options['cap_per_archer'], 2);

            for ($i = count($ids) - 1; $i >= 0 && $excess > 0; $i--) {
                $reduce = min($engagements[$ids[$i]]['amount_due'], $excess);

                if ($reduce > 0) {
                    registrarc_engagement_adjust($engagements[$ids[$i]], 'Plafond par archer', -$reduce);
                    $excess = round($excess - $reduce, 2);
                }
            }
        }
    }

    // 3. Majoration pour inscription tardive
    if ($options['late_fee'] > 0) {
        foreach ($engagements as $id => $entry) {
            if ($entry['late']) {
                registrarc_engagement_adjust($engagements[$id], 'Inscription tardive', $options['late_fee']);
            }
        }
    }

    // Paiement et statut
    foreach ($engagements as $id => $entry) {
        $payment = isset($payments[(string)$id]) && is_array($payments[(string)$id]) ? $payments[(string)$id] : [];
        $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';
        $state = registrarc_engagement_state($payment, $entry['amount_due']);

        $engagements[$id]['payment'] = $payment;
        $engagements[$id]['payment_method'] = $method;
        $engagements[$id]['receipt_required'] = !empty($payment['receipt_required']);
        $engagements[$id]['deposit_id'] = isset($payment['deposit_id']) ? (string)$payment['deposit_id'] : '';
        $engagements[$id]['state'] = $state;
        $engagements[$id]['is_paid'] = ($state === 'paid');

        // Montant affiché : 0 pour un engagement réglé en mode Gratuit
        $engagements[$id]['amount'] = ($state === 'paid' && registrarc_is_free_method($method)) ? 0 : $entry['amount_due'];
    }

    $cache[$TourId] = $engagements;

    return $engagements;
}

function registrarc_engagement_adjust(array &$entry, $label, $delta) {
    $before = $entry['amount_due'];
    $entry['amount_due'] = round(max(0, $before + $delta), 2);
    $applied = round($entry['amount_due'] - $before, 2);

    if ($applied != 0) {
        $entry['adjustments'][] = ['label' => $label, 'amount' => $applied];
    }
}

// Libellé du tarif : règle avancée ou "Standard", puis ajustements
function registrarc_engagement_tarif_label(array $entry) {
    if ($entry['rule_label'] !== '') {
        $label = ($entry['rule_count'] > 1 ? 'Cumul : ' : '') . $entry['rule_label'];
    } else {
        $label = 'Standard';
    }

    foreach ($entry['adjustments'] as $adjustment) {
        $label .= ' · ' . $adjustment['label'];
    }

    return $label;
}

// Prix spécial : règle avancée et ajustements ; '' pour le tarif standard
function registrarc_engagement_special_label(array $entry) {
    $parts = [];

    if ($entry['rule_label'] !== '') {
        $parts[] = $entry['rule_label'];
    }

    foreach ($entry['adjustments'] as $adjustment) {
        $parts[] = $adjustment['label'];
    }

    return implode(' + ', $parts);
}

function registrarc_engagements_by_ids($TourId, array $ids) {
    $all = registrarc_load_engagements($TourId);
    $out = [];

    foreach ($ids as $id) {
        $id = intval($id);

        if (isset($all[$id])) {
            $out[$id] = $all[$id];
        }
    }

    return $out;
}
