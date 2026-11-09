<?php
// ============================================================================
// RegistrArc - lib/registrarc_caisse.inc.php
// Opérations de caisse et leur suivi
//
// - Encaisser, annuler, rembourser : toujours par registrarc_apply_payment_changes,
//   qui met à jour les paiements et ajoute une ligne au journal (montant, mode,
//   heure, opérateur, poste). Le journal ne fait que grandir.
// - Remises de chèques déposées à la banque
// - Clôtures de caisse (totaux par mode depuis la clôture précédente)
// - Concours clôturé : plus aucune modification possible jusqu'à réouverture
//
// Fichiers JSON propres au tournoi :
// journal_<id>.json, deposits_<id>.json, closures_<id>.json, state_<id>.json
// ============================================================================

// ---------------------------------------------------------------------------
// Opérateur et poste
// ---------------------------------------------------------------------------
// Prénom saisi sur le poste (mémorisé par le navigateur et envoyé avec chaque
// formulaire), sinon le compte I@nseo s'il y en a un
function registrarc_operator_name() {
    $name = isset($_POST['operator']) ? trim((string)$_POST['operator']) : '';

    if ($name === '' && !empty($_SESSION['AUTH_User'])) {
        $name = trim((string)$_SESSION['AUTH_User']);
    }

    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

    return mb_substr((string)$name, 0, 40);
}

function registrarc_operator_device() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

    if (preg_match('/iPad|Tablet/i', $ua)) {
        $kind = 'Tablette';
    } elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) {
        $kind = 'Téléphone';
    } else {
        $kind = 'Ordinateur';
    }

    return trim($kind . ($ip !== '' ? ' ' . $ip : ''));
}

// ---------------------------------------------------------------------------
// Journal
// ---------------------------------------------------------------------------
function registrarc_journal_file_path($TourId) {
    return registrarc_data_dir() . '/journal_' . intval($TourId) . '.json';
}

function registrarc_load_journal($TourId) {
    return array_values(registrarc_load_json_file(registrarc_journal_file_path($TourId)));
}

function registrarc_journal_append($TourId, array $entries) {
    if (empty($entries)) {
        return true;
    }

    $base = [
        'at' => date('Y-m-d H:i:s'),
        'operator' => registrarc_operator_name(),
        'device' => registrarc_operator_device(),
    ];

    return registrarc_update_json_file(registrarc_journal_file_path($TourId), function (array &$journal) use ($entries, $base) {
        $journal = array_values($journal);

        foreach ($entries as $entry) {
            $journal[] = $base + $entry;
        }
    });
}

function registrarc_journal_type_label($type) {
    $labels = [
        'payment' => 'Encaissement',
        'cancel' => 'Annulation',
        'refund' => 'Remboursement',
        'deposit' => 'Remise de chèques',
        'deposit_cancel' => 'Remise annulée',
        'closure' => 'Clôture de caisse',
        'closed' => 'Concours clôturé',
        'reopened' => 'Concours rouvert',
        'restore' => 'Restauration',
    ];

    return isset($labels[$type]) ? $labels[$type] : $type;
}

// Opérations qui font bouger la caisse
function registrarc_journal_is_cash($type) {
    return in_array($type, ['payment', 'cancel', 'refund'], true);
}

// ---------------------------------------------------------------------------
// Opérations de paiement
// ---------------------------------------------------------------------------
// $changes : liste de ['id' => EnId, 'action' => 'pay'|'cancel'|'refund',
//                      'method' => code, 'receipt' => bool]
// $source : 'liste', 'kiosque', 'import', 'helloasso'
// Retourne ['ok' => bool, 'done' => [ids], 'skipped' => [id => raison]]
function registrarc_apply_payment_changes($TourId, array $changes, $source) {
    $engagements = registrarc_load_engagements($TourId, true);
    $journal = [];
    $done = [];
    $skipped = [];
    $now = date('Y-m-d H:i:s');
    $operator = registrarc_operator_name();

    $ok = registrarc_update_payments($TourId, function (array &$payments) use ($changes, $engagements, $source, $now, $operator, &$journal, &$done, &$skipped) {
        foreach ($changes as $change) {
            $id = intval($change['id']);

            if (!isset($engagements[$id])) {
                $skipped[$id] = "engagement introuvable dans ce concours";
                continue;
            }

            $e = $engagements[$id];
            $prev = isset($payments[(string)$id]) && is_array($payments[(string)$id]) ? $payments[(string)$id] : [];
            $prevStatus = isset($prev['status']) ? strtoupper((string)$prev['status']) : 'NON_PAYE';
            $prevMethod = isset($prev['method']) ? registrarc_normalize_payment_code($prev['method']) : '';
            $prevAmount = isset($prev['amount']) ? (float)$prev['amount'] : (registrarc_is_free_method($prevMethod) ? 0.0 : (float)$e['amount_due']);
            $archer = trim($e['nom'] . ' ' . $e['prenom']);
            $line = ['engagement_id' => $id, 'archer' => $archer, 'source' => $source];

            // Un chèque déjà déposé à la banque ne peut plus être modifié, seulement remboursé
            if ($prevStatus === 'PAYE' && !empty($prev['deposit_id']) && $change['action'] !== 'refund') {
                $skipped[$id] = "chèque déjà déposé (remise " . $prev['deposit_id'] . ")";
                continue;
            }

            if ($change['action'] === 'pay') {
                $method = registrarc_normalize_payment_code($change['method']);
                $amount = registrarc_is_free_method($method) ? 0.0 : (float)$e['amount_due'];

                if ($prevStatus === 'PAYE') {
                    if ($prevMethod === $method) {
                        // Déjà payé de la même façon : seul le reçu peut changer
                        $payments[(string)$id]['receipt_required'] = !empty($change['receipt']);
                        $payments[(string)$id]['updated_at'] = $now;
                        $done[] = $id;
                        continue;
                    }

                    $journal[] = $line + ['type' => 'cancel', 'amount' => -$prevAmount, 'method' => $prevMethod, 'label' => 'Changement de mode de paiement'];
                }

                $payments[(string)$id] = [
                    'status' => 'PAYE',
                    'method' => $method,
                    'receipt_required' => !empty($change['receipt']),
                    'amount' => $amount,
                    'paid_at' => $now,
                    'updated_at' => $now,
                    'operator' => $operator,
                ];
                $journal[] = $line + ['type' => 'payment', 'amount' => $amount, 'method' => $method];
                $done[] = $id;
            } elseif ($change['action'] === 'cancel') {
                if ($prevStatus !== 'PAYE') {
                    $skipped[$id] = "pas de paiement à annuler";
                    continue;
                }

                $payments[(string)$id] = ['status' => 'NON_PAYE', 'method' => '', 'receipt_required' => false, 'updated_at' => $now];
                $journal[] = $line + ['type' => 'cancel', 'amount' => -$prevAmount, 'method' => $prevMethod];
                $done[] = $id;
            } elseif ($change['action'] === 'refund') {
                if ($prevStatus !== 'PAYE') {
                    $skipped[$id] = "pas de paiement à rembourser";
                    continue;
                }

                $refundMethod = registrarc_normalize_payment_code($change['method']);
                $payments[(string)$id] = $prev;
                $payments[(string)$id]['status'] = 'REMBOURSE';
                $payments[(string)$id]['refund_method'] = $refundMethod;
                $payments[(string)$id]['refund_amount'] = $prevAmount;
                $payments[(string)$id]['refunded_at'] = $now;
                $payments[(string)$id]['updated_at'] = $now;
                $journal[] = $line + ['type' => 'refund', 'amount' => -$prevAmount, 'method' => $refundMethod];
                $done[] = $id;
            }
        }
    });

    if ($ok) {
        registrarc_journal_append($TourId, $journal);
    }

    registrarc_load_engagements($TourId, true);

    return ['ok' => $ok, 'done' => $done, 'skipped' => $skipped];
}

function registrarc_record_payments($TourId, array $ids, $method, $receipt, $source) {
    $changes = [];

    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $changes[] = ['id' => $id, 'action' => 'pay', 'method' => $method, 'receipt' => $receipt];
    }

    return registrarc_apply_payment_changes($TourId, $changes, $source);
}

function registrarc_cancel_payments($TourId, array $ids, $source) {
    $changes = [];

    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $changes[] = ['id' => $id, 'action' => 'cancel'];
    }

    return registrarc_apply_payment_changes($TourId, $changes, $source);
}

function registrarc_refund_payments($TourId, array $ids, $refundMethod, $source) {
    $changes = [];

    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $changes[] = ['id' => $id, 'action' => 'refund', 'method' => $refundMethod];
    }

    return registrarc_apply_payment_changes($TourId, $changes, $source);
}

// Message lisible pour les engagements non traités
function registrarc_skipped_message($TourId, array $skipped) {
    if (empty($skipped)) {
        return '';
    }

    $engagements = registrarc_load_engagements($TourId);
    $parts = [];

    foreach ($skipped as $id => $reason) {
        $name = isset($engagements[$id]) ? trim($engagements[$id]['nom'] . ' ' . $engagements[$id]['prenom']) : 'engagement ' . $id;
        $parts[] = $name . ' : ' . $reason;
    }

    return ' Non traité(s) : ' . implode(' ; ', $parts) . '.';
}

// ---------------------------------------------------------------------------
// Remises de chèques déposées
// ---------------------------------------------------------------------------
function registrarc_deposits_file_path($TourId) {
    return registrarc_data_dir() . '/deposits_' . intval($TourId) . '.json';
}

function registrarc_load_deposits($TourId) {
    return registrarc_load_json_file(registrarc_deposits_file_path($TourId));
}

// $cheques : liste de ['group_id' => id ou '', 'engagement_ids' => [...], 'amount' => total]
// Retourne l'identifiant de la remise, ou '' si rien n'a pu être enregistré
function registrarc_create_deposit($TourId, array $cheques) {
    $engagements = registrarc_load_engagements($TourId, true);
    $kept = [];

    // Seuls les chèques encore payés et pas encore déposés
    foreach ($cheques as $cheque) {
        $ids = [];

        foreach ($cheque['engagement_ids'] as $id) {
            $id = intval($id);

            if (isset($engagements[$id]) && $engagements[$id]['is_paid'] && $engagements[$id]['payment_method'] === 'CHQ' && $engagements[$id]['deposit_id'] === '') {
                $ids[] = $id;
            }
        }

        if (!empty($ids)) {
            $amount = 0;

            foreach ($ids as $id) {
                $amount += $engagements[$id]['amount'];
            }

            $kept[] = ['group_id' => (string)$cheque['group_id'], 'engagement_ids' => $ids, 'amount' => round($amount, 2)];
        }
    }

    if (empty($kept)) {
        return '';
    }

    $depositId = '';
    $total = 0;

    foreach ($kept as $cheque) {
        $total += $cheque['amount'];
    }

    $saved = registrarc_update_json_file(registrarc_deposits_file_path($TourId), function (array &$deposits) use ($kept, $total, &$depositId) {
        $number = 1;

        foreach ($deposits as $deposit) {
            $number = max($number, intval(isset($deposit['number']) ? $deposit['number'] : 0) + 1);
        }

        $depositId = 'R' . $number;
        $deposits[$depositId] = [
            'id' => $depositId,
            'number' => $number,
            'created_at' => date('Y-m-d H:i:s'),
            'operator' => registrarc_operator_name(),
            'cheques' => $kept,
            'count' => count($kept),
            'total' => round($total, 2),
        ];
    });

    if (!$saved) {
        return '';
    }

    registrarc_update_payments($TourId, function (array &$payments) use ($kept, $depositId) {
        foreach ($kept as $cheque) {
            foreach ($cheque['engagement_ids'] as $id) {
                if (isset($payments[(string)$id])) {
                    $payments[(string)$id]['deposit_id'] = $depositId;
                }
            }
        }
    });

    registrarc_journal_append($TourId, [[
        'type' => 'deposit',
        'amount' => round($total, 2),
        'method' => 'CHQ',
        'label' => 'Remise ' . $depositId . ' : ' . count($kept) . ' chèque(s)',
    ]]);

    registrarc_load_engagements($TourId, true);

    return $depositId;
}

// Annule une remise enregistrée par erreur : les chèques redeviennent à déposer
function registrarc_cancel_deposit($TourId, $depositId) {
    $depositId = (string)$depositId;
    $deposits = registrarc_load_deposits($TourId);

    if (!isset($deposits[$depositId])) {
        return false;
    }

    registrarc_update_payments($TourId, function (array &$payments) use ($depositId) {
        foreach ($payments as $id => $payment) {
            if (is_array($payment) && isset($payment['deposit_id']) && (string)$payment['deposit_id'] === $depositId) {
                unset($payments[$id]['deposit_id']);
            }
        }
    });

    registrarc_update_json_file(registrarc_deposits_file_path($TourId), function (array &$all) use ($depositId) {
        unset($all[$depositId]);
    });

    registrarc_journal_append($TourId, [[
        'type' => 'deposit_cancel',
        'amount' => (float)$deposits[$depositId]['total'],
        'method' => 'CHQ',
        'label' => 'Remise ' . $depositId . ' annulée',
    ]]);

    registrarc_load_engagements($TourId, true);

    return true;
}

// ---------------------------------------------------------------------------
// Clôtures de caisse
// ---------------------------------------------------------------------------
function registrarc_closures_file_path($TourId) {
    return registrarc_data_dir() . '/closures_' . intval($TourId) . '.json';
}

function registrarc_load_closures($TourId) {
    return array_values(registrarc_load_json_file(registrarc_closures_file_path($TourId)));
}

// Opérations de caisse du journal entre deux positions : de $fromIndex (inclus)
// à $toIndex (exclu, null = jusqu'à la fin). Découper par position plutôt que
// par heure : une opération enregistrée pendant une clôture, ou par un appareil
// dont l'heure est fausse, ne peut être ni perdue ni comptée deux fois.
// Retourne ['operations' => [...], 'totals' => [mode => montant], 'count' => n,
//           'total' => x, 'end' => position de fin]
function registrarc_cash_summary($TourId, $fromIndex = 0, $toIndex = null, $journal = null) {
    if ($journal === null) {
        $journal = registrarc_load_journal($TourId);
    }

    $end = $toIndex === null ? count($journal) : min(intval($toIndex), count($journal));
    $operations = [];
    $totals = [];
    $total = 0;

    for ($i = max(0, intval($fromIndex)); $i < $end; $i++) {
        $entry = $journal[$i];

        if (!isset($entry['type']) || !registrarc_journal_is_cash($entry['type'])) {
            continue;
        }

        $method = isset($entry['method']) && $entry['method'] !== '' ? (string)$entry['method'] : '?';
        $amount = isset($entry['amount']) ? (float)$entry['amount'] : 0;

        $totals[$method] = round((isset($totals[$method]) ? $totals[$method] : 0) + $amount, 2);
        $total = round($total + $amount, 2);
        $operations[] = $entry;
    }

    ksort($totals);

    return ['operations' => $operations, 'totals' => $totals, 'count' => count($operations), 'total' => $total, 'end' => $end];
}

// Période en cours : ['index' => position de départ dans le journal, 'since' => date de la dernière clôture]
function registrarc_current_cash_period($TourId) {
    $closures = registrarc_load_closures($TourId);

    if (empty($closures)) {
        return ['index' => 0, 'since' => ''];
    }

    $last = $closures[count($closures) - 1];

    return ['index' => intval($last['journal_to']), 'since' => (string)$last['to']];
}

// ---------------------------------------------------------------------------
// Fond de caisse et comptage par pièces et billets
// Un détail est une liste [valeur en centimes => quantité] : les centimes
// évitent les arrondis des nombres à virgule.
// ---------------------------------------------------------------------------
function registrarc_cash_denominations() {
    return [
        'coins' => [1, 2, 5, 10, 20, 50, 100, 200],
        'notes' => [500, 1000, 2000, 5000, 10000, 20000],
    ];
}

function registrarc_cash_denomination_label($cents) {
    $cents = intval($cents);

    return ($cents % 100 === 0 ? (string)($cents / 100) : number_format($cents / 100, 2, ',', '')) . ' €';
}

// Garde les valeurs connues et les quantités entières positives
function registrarc_normalize_cash_detail($detail) {
    $out = [];

    if (!is_array($detail)) {
        return $out;
    }

    $denominations = registrarc_cash_denominations();

    foreach (array_merge($denominations['coins'], $denominations['notes']) as $cents) {
        $count = isset($detail[$cents]) ? trim((string)$detail[$cents]) : '';

        if ($count !== '' && ctype_digit($count) && intval($count) > 0) {
            $out[$cents] = min(intval($count), 100000);
        }
    }

    return $out;
}

function registrarc_cash_detail_total(array $detail) {
    $cents = 0;

    foreach (registrarc_normalize_cash_detail($detail) as $value => $count) {
        $cents += $value * $count;
    }

    return round($cents / 100, 2);
}

function registrarc_cash_float_file_path($TourId) {
    return registrarc_data_dir() . '/cash_float_' . intval($TourId) . '.json';
}

// Montant saisi ("12,50", "12.5") : nombre positif arrondi au centime, null si vide ou invalide
function registrarc_parse_cash_amount($value) {
    $value = trim(str_replace([',', ' ', "\xC2\xA0", '€'], ['.', '', '', ''], (string)$value));

    if ($value === '' || !is_numeric($value) || (float)$value < 0) {
        return null;
    }

    return round(min((float)$value, 1000000), 2);
}

// Fond de caisse :
// ['mode' => 'detail' (pièces et billets) ou 'simple' (un seul montant),
//  'detail' => [...], 'amount' => montant simplifié, 'total' => x, 'updated_at' => ..., 'operator' => ...]
function registrarc_load_cash_float($TourId) {
    $data = registrarc_load_json_file(registrarc_cash_float_file_path($TourId));
    $mode = isset($data['mode']) && $data['mode'] === 'simple' ? 'simple' : 'detail';
    $detail = $mode === 'detail' ? registrarc_normalize_cash_detail(isset($data['detail']) ? $data['detail'] : []) : [];
    $amount = $mode === 'simple' && isset($data['amount']) ? (float)registrarc_parse_cash_amount($data['amount']) : 0;

    return [
        'mode' => $mode,
        'detail' => $detail,
        'amount' => $amount,
        'total' => $mode === 'simple' ? $amount : registrarc_cash_detail_total($detail),
        'updated_at' => isset($data['updated_at']) ? (string)$data['updated_at'] : '',
        'operator' => isset($data['operator']) ? (string)$data['operator'] : '',
    ];
}

// $mode 'detail' : $detail = quantités par valeur ; $mode 'simple' : $amount = montant global
function registrarc_save_cash_float($TourId, $detail, $mode = 'detail', $amount = null) {
    $mode = $mode === 'simple' ? 'simple' : 'detail';
    $detail = $mode === 'detail' ? registrarc_normalize_cash_detail($detail) : [];
    $amount = $mode === 'simple' ? (float)registrarc_parse_cash_amount($amount) : 0;

    return registrarc_update_json_file(registrarc_cash_float_file_path($TourId), function (array &$data) use ($mode, $detail, $amount) {
        $data = [
            'mode' => $mode,
            'detail' => $detail,
            'amount' => $amount,
            'updated_at' => date('Y-m-d H:i:s'),
            'operator' => registrarc_operator_name(),
        ];
    });
}

// $countedCash : espèces comptées dans la caisse, fond compris (null si non compté).
// $countedDetail : comptage par pièces et billets ; s'il est rempli, il donne le total.
// Les espèces attendues comprennent le fond de caisse, figé dans la clôture.
function registrarc_close_cash($TourId, $countedCash, $comment, $countedDetail = null) {
    $period = registrarc_current_cash_period($TourId);
    $journal = registrarc_load_journal($TourId);
    $summary = registrarc_cash_summary($TourId, $period['index'], null, $journal);
    $takings = isset($summary['totals']['ESP']) ? $summary['totals']['ESP'] : 0;
    $float = registrarc_load_cash_float($TourId);
    $cash = round($float['total'] + $takings, 2);
    $countedDetail = registrarc_normalize_cash_detail($countedDetail);
    $closureId = '';

    if (!empty($countedDetail)) {
        $countedCash = registrarc_cash_detail_total($countedDetail);
    }

    $saved = registrarc_update_json_file(registrarc_closures_file_path($TourId), function (array &$closures) use ($period, $summary, $countedCash, $countedDetail, $cash, $takings, $float, $comment, &$closureId) {
        $closures = array_values($closures);
        $closureId = 'C' . (count($closures) + 1);
        $closures[] = [
            'id' => $closureId,
            'from' => $period['since'],
            'to' => date('Y-m-d H:i:s'),
            'journal_from' => $period['index'],
            'journal_to' => $summary['end'],
            'totals' => $summary['totals'],
            'total' => $summary['total'],
            'count' => $summary['count'],
            'cash_float_mode' => $float['mode'],
            'cash_float' => (float)$float['total'],
            'cash_float_detail' => $float['detail'],
            'cash_takings' => (float)$takings,
            'cash_expected' => (float)$cash,
            'cash_counted' => $countedCash === null ? null : (float)$countedCash,
            'cash_counted_detail' => $countedDetail,
            'cash_difference' => $countedCash === null ? null : (float)round($countedCash - $cash, 2),
            'operator' => registrarc_operator_name(),
            'comment' => mb_substr(trim((string)$comment), 0, 200),
        ];
    });

    if ($saved) {
        registrarc_journal_append($TourId, [[
            'type' => 'closure',
            'amount' => $summary['total'],
            'method' => '',
            'label' => 'Clôture ' . $closureId . ' : ' . $summary['count'] . ' opération(s)',
        ]]);
    }

    return $saved ? $closureId : '';
}

// ---------------------------------------------------------------------------
// Concours clôturé
// ---------------------------------------------------------------------------
function registrarc_state_file_path($TourId) {
    return registrarc_data_dir() . '/state_' . intval($TourId) . '.json';
}

function registrarc_load_state($TourId) {
    return registrarc_load_json_file(registrarc_state_file_path($TourId));
}

function registrarc_is_closed($TourId) {
    $state = registrarc_load_state($TourId);

    return !empty($state['closed']);
}

function registrarc_set_closed($TourId, $closed) {
    $saved = registrarc_save_json_file(registrarc_state_file_path($TourId), [
        'closed' => (bool)$closed,
        'changed_at' => date('Y-m-d H:i:s'),
        'operator' => registrarc_operator_name(),
    ]);

    if ($saved) {
        registrarc_journal_append($TourId, [['type' => $closed ? 'closed' : 'reopened', 'amount' => 0, 'method' => '']]);
    }

    return $saved;
}

// Droit de modifier : écriture sur les participants, et concours non clôturé
function registrarc_can_write($TourId) {
    return hasACL(AclParticipants, AclReadWrite) && !registrarc_is_closed($TourId);
}
