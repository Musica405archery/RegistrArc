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

// $countedCash : espèces comptées dans la caisse (null si non compté)
function registrarc_close_cash($TourId, $countedCash, $comment) {
    $period = registrarc_current_cash_period($TourId);
    $journal = registrarc_load_journal($TourId);
    $summary = registrarc_cash_summary($TourId, $period['index'], null, $journal);
    $cash = isset($summary['totals']['ESP']) ? $summary['totals']['ESP'] : 0;
    $closureId = '';

    $saved = registrarc_update_json_file(registrarc_closures_file_path($TourId), function (array &$closures) use ($period, $summary, $countedCash, $cash, $comment, &$closureId) {
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
            'cash_expected' => (float)$cash,
            'cash_counted' => $countedCash === null ? null : (float)$countedCash,
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
