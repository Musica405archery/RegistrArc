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
//
// Fichiers JSON propres au tournoi :
// - archers_<TourId>.json  : note, passage au greffe et signalement « absent »,
//   par engagement
//
// Un engagement payé (ou remboursé) puis supprimé d'I@nseo reste dans la liste,
// marqué « removed », d'après la photo gardée avec son paiement : l'argent
// encaissé ne disparaît pas et l'engagement peut encore être remboursé.
// ============================================================================

// ---------------------------------------------------------------------------
// Options de tarification (réglages du tournoi)
// ---------------------------------------------------------------------------
function registrarc_default_pricing_options() {
    return [
        'cap_per_archer' => 0,
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
// Note et passage au greffe, par engagement
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

// Note d'un engagement ; le pointage au greffe est conservé
function registrarc_set_archer_note($TourId, $engagementId, $note) {
    $key = (string)intval($engagementId);
    $note = mb_substr(trim((string)$note), 0, 200);

    return intval($engagementId) > 0 && registrarc_update_archer_info($TourId, function (array &$info) use ($key, $note) {
        $entry = isset($info[$key]) && is_array($info[$key]) ? $info[$key] : [];
        $entry['note'] = $note;
        // Ancienne « inscription tardive » (retirée en 1.2.6) : effacée à l'enregistrement
        unset($entry['late']);
        $entry['updated_at'] = date('Y-m-d H:i:s');
        $info[$key] = $entry;
        registrarc_archer_info_cleanup($info, $key);
    });
}

// Archer présenté au greffe pour ce départ, ou non. Retourne false en cas d'erreur d'écriture.
// Pointer un archer signalé absent retire le signalement : il est arrivé.
function registrarc_toggle_checkin($TourId, $engagementId) {
    $key = (string)intval($engagementId);

    return intval($engagementId) > 0 && registrarc_update_archer_info($TourId, function (array &$info) use ($key) {
        $entry = isset($info[$key]) && is_array($info[$key]) ? $info[$key] : [];

        if (!empty($entry['checked_in'])) {
            unset($entry['checked_in'], $entry['checked_in_by']);
        } else {
            $entry['checked_in'] = date('Y-m-d H:i:s');
            $entry['checked_in_by'] = registrarc_operator_name();
            unset($entry['absent'], $entry['absent_by']);
        }

        $info[$key] = $entry;
        registrarc_archer_info_cleanup($info, $key);
    });
}

// Pointe des engagements comme présentés au greffe (ceux déjà pointés gardent leur heure)
function registrarc_mark_checked_in($TourId, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));

    if (empty($ids)) {
        return true;
    }

    return registrarc_update_archer_info($TourId, function (array &$info) use ($ids) {
        $now = date('Y-m-d H:i:s');
        $operator = registrarc_operator_name();

        foreach ($ids as $id) {
            $key = (string)$id;
            $entry = isset($info[$key]) && is_array($info[$key]) ? $info[$key] : [];

            if (empty($entry['checked_in'])) {
                $entry['checked_in'] = $now;
                $entry['checked_in_by'] = $operator;
                unset($entry['absent'], $entry['absent_by']);
                $info[$key] = $entry;
            }
        }
    });
}

// Signale des engagements absents (mode Kiosk), ou retire le signalement ($absent = false).
// Un archer signalé absent n'est plus pointé au greffe ; le pointer retire le signalement.
// Ceux déjà signalés gardent l'heure et l'opérateur du premier signalement.
function registrarc_set_absent($TourId, array $ids, $absent) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (empty($ids)) {
        return true;
    }

    return registrarc_update_archer_info($TourId, function (array &$info) use ($ids, $absent) {
        $now = date('Y-m-d H:i:s');
        $operator = registrarc_operator_name();

        foreach ($ids as $id) {
            $key = (string)$id;
            $entry = isset($info[$key]) && is_array($info[$key]) ? $info[$key] : [];

            if ($absent) {
                if (empty($entry['absent'])) {
                    $entry['absent'] = $now;
                    $entry['absent_by'] = $operator;
                }

                unset($entry['checked_in'], $entry['checked_in_by']);
            } else {
                unset($entry['absent'], $entry['absent_by']);
            }

            $info[$key] = $entry;
            registrarc_archer_info_cleanup($info, $key);
        }
    });
}

// Retire le pointage au greffe de ces engagements (la note est conservée)
function registrarc_clear_checked_in($TourId, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));

    if (empty($ids)) {
        return true;
    }

    return registrarc_update_archer_info($TourId, function (array &$info) use ($ids) {
        foreach ($ids as $id) {
            $key = (string)$id;

            if (isset($info[$key]) && is_array($info[$key]) && !empty($info[$key]['checked_in'])) {
                unset($info[$key]['checked_in'], $info[$key]['checked_in_by']);
                registrarc_archer_info_cleanup($info, $key);
            }
        }
    });
}

// ---------------------------------------------------------------------------
// Options du greffe (paramètres du tournoi, clé "greffe")
// - checkin_on_payment : valider un paiement au comptoir (liste ou mode Kiosk)
//   pointe aussi l'archer comme présenté au greffe pour ce départ
// ---------------------------------------------------------------------------
function registrarc_default_greffe_options() {
    return ['checkin_on_payment' => true];
}

function registrarc_normalize_greffe_options($options) {
    $out = registrarc_default_greffe_options();

    if (is_array($options) && isset($options['checkin_on_payment'])) {
        $out['checkin_on_payment'] = !empty($options['checkin_on_payment']);
    }

    return $out;
}

function registrarc_load_greffe_options($TourId) {
    $settings = registrarc_load_settings($TourId);

    return registrarc_normalize_greffe_options(isset($settings['greffe']) ? $settings['greffe'] : null);
}

// Retire un engagement sans note, ni pointage, ni signalement « absent »
function registrarc_archer_info_cleanup(array &$info, $key) {
    $entry = $info[$key];

    if ((!isset($entry['note']) || $entry['note'] === '') && empty($entry['checked_in']) && empty($entry['absent'])) {
        unset($info[$key]);
    }
}

// ---------------------------------------------------------------------------
// Engagements supprimés d'I@nseo après paiement
// ---------------------------------------------------------------------------
// Photo d'un engagement, gardée avec son paiement (clé « snapshot ») : si
// l'archer est ensuite supprimé d'I@nseo, RegistrArc l'affiche encore.
function registrarc_engagement_snapshot(array $e) {
    $snap = [];

    foreach (['licence', 'nom', 'prenom', 'categorie', 'class_code', 'age_class', 'club', 'club_full', 'country_code', 'session', 'target', 'letter'] as $key) {
        $snap[$key] = isset($e[$key]) ? (string)$e[$key] : '';
    }

    $snap['numero'] = isset($e['numero']) ? intval($e['numero']) : 0;

    return $snap;
}

// Met à jour la photo de chaque paiement (payé ou remboursé) dont l'engagement
// est encore dans I@nseo. Appelé à chaque écriture des paiements.
function registrarc_payments_refresh_snapshots(array &$payments, array $engagements) {
    foreach ($payments as $key => $payment) {
        $id = intval($key);

        if (!is_array($payment) || !isset($engagements[$id]) || !empty($engagements[$id]['removed'])) {
            continue;
        }

        $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : '';

        if ($status !== 'PAYE' && $status !== 'REMBOURSE') {
            continue;
        }

        $snap = registrarc_engagement_snapshot($engagements[$id]);

        if (!isset($payment['snapshot']) || $payment['snapshot'] !== $snap) {
            $payments[$key]['snapshot'] = $snap;
        }
    }
}

// Engagement supprimé d'I@nseo, reconstitué d'après la photo de son paiement
// (ou, pour un paiement plus ancien sans photo, le nom noté dans le journal).
// Son montant est celui encaissé ; il n'a ni score, ni pointage, ni épreuve.
function registrarc_removed_engagement($id, array $payment, array $info, $journalName = '') {
    $snap = isset($payment['snapshot']) && is_array($payment['snapshot']) ? $payment['snapshot'] : [];
    $get = function ($key) use ($snap) {
        return isset($snap[$key]) ? trim((string)$snap[$key]) : '';
    };

    $nom = strtoupper($get('nom'));
    $prenom = $get('prenom');

    if ($nom === '' && $prenom === '') {
        $nom = trim((string)$journalName) !== '' ? trim((string)$journalName) : 'Engagement n° ' . intval($id);
    }

    $target = $get('target');
    $letter = $get('letter');
    $unassigned = registrarc_is_null_empty_or_zero($target) || registrarc_is_null_empty_or_zero($letter);
    $method = isset($payment['method']) ? registrarc_normalize_payment_code($payment['method']) : '';
    $amount = isset($payment['amount']) ? round((float)$payment['amount'], 2) : 0.0;
    $state = registrarc_engagement_state($payment, $amount);
    $ageClass = $get('age_class');
    $classCode = $get('class_code');

    return [
        'engagement_id' => intval($id),
        'licence' => strtoupper($get('licence')),
        'nom' => $nom,
        'prenom' => $prenom,
        'categorie' => $get('categorie'),
        'class_code' => $classCode,
        'age_class' => $ageClass,
        'is_upgraded' => $ageClass !== '' && strtoupper($ageClass) !== strtoupper($classCode),
        'club' => $get('club'),
        'club_full' => $get('club_full'),
        'country_code' => $get('country_code'),
        'session' => $get('session'),
        'target' => $target,
        'letter' => $letter,
        'target_no' => $unassigned ? '' : trim($target . $letter),
        'target_label' => $unassigned ? 'Archer non affecté' : trim($target . $letter),
        'target_is_unassigned' => $unassigned,
        'score' => 0,
        'hits' => 0,
        'EnIndClEvent' => '0',
        'EnTeamClEvent' => '0',
        'EnIndFEvent' => '0',
        'EnTeamFEvent' => '0',
        'EnTeamMixEvent' => '0',
        'numero' => isset($snap['numero']) ? intval($snap['numero']) : 0,
        'base_amount' => $amount,
        'rule_label' => '',
        'rule_count' => 0,
        'note' => isset($info['note']) ? trim((string)$info['note']) : '',
        'checked_in' => '',
        'checked_in_by' => '',
        'absent' => '',
        'absent_by' => '',
        'adjustments' => [],
        'amount_due' => $amount,
        'payment' => $payment,
        'payment_method' => $method,
        'receipt_required' => !empty($payment['receipt_required']),
        'deposit_id' => isset($payment['deposit_id']) ? (string)$payment['deposit_id'] : '',
        'state' => $state,
        'is_paid' => $state === 'paid',
        'amount' => $amount,
        'removed' => true,
    ];
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

    // Finale individuelle : la case de l'archer ne suffit pas (cochée par défaut
    // dans I@nseo) ; sa catégorie doit aussi appartenir à au moins une épreuve
    // individuelle qui a des phases finales (EvFinalFirstPhase différent de 0)
    $query = "
        SELECT
            e.EnId AS engagement_id,
            UPPER(TRIM(e.EnCode)) AS licence,
            UPPER(TRIM(e.EnFirstName)) AS nom,
            TRIM(e.EnName) AS prenom,
            CONCAT(e.EnDivision, e.EnClass) AS categorie,
            TRIM(e.EnClass) AS class_code,
            TRIM(e.EnAgeClass) AS age_class,
            c.CoName AS club,
            TRIM(c.CoNameComplete) AS club_full,
            c.CoCode AS country_code,
            q.QuSession AS session_no,
            q.QuTarget AS target,
            q.QuLetter AS letter,
            q.QuScore AS score,
            q.QuHits AS hits,
            e.EnIndClEvent,
            e.EnTeamClEvent,
            IF(e.EnIndFEvent <> 0 AND EXISTS (
                SELECT 1
                FROM EventClass ec
                INNER JOIN Events ev
                    ON ev.EvTournament = ec.EcTournament
                   AND ev.EvCode = ec.EcCode
                   AND ev.EvTeamEvent = 0
                WHERE ec.EcTournament = e.EnTournament
                  AND ec.EcTeamEvent = 0
                  AND ec.EcDivision = e.EnDivision
                  AND ec.EcClass = e.EnClass
                  AND (ec.EcSubClass = '' OR ec.EcSubClass = e.EnSubClass)
                  AND ev.EvFinalFirstPhase <> 0
            ), 1, 0) AS EnIndFEvent,
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
            // Surclassé : la catégorie d'âge (date de naissance) diffère de la catégorie de classement
            'class_code' => trim((string)$row->class_code),
            'age_class' => trim((string)$row->age_class),
            'is_upgraded' => trim((string)$row->age_class) !== '' && strtoupper(trim((string)$row->age_class)) !== strtoupper(trim((string)$row->class_code)),
            'club' => trim((string)$row->club),
            'club_full' => trim((string)$row->club_full),
            'country_code' => trim((string)$row->country_code),
            'session' => trim((string)$row->session_no),
            'target' => $target,
            'letter' => $letter,
            'target_no' => $unassigned ? '' : trim($target . $letter),
            'target_label' => $unassigned ? 'Archer non affecté' : trim($target . $letter),
            'target_is_unassigned' => $unassigned,
            // Score de qualification saisi dans I@nseo (feuille de marque) et nombre de flèches
            'score' => isset($row->score) ? intval($row->score) : 0,
            'hits' => isset($row->hits) ? intval($row->hits) : 0,
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
        // Présenté au greffe : date du pointage, '' sinon
        $entry['checked_in'] = isset($info['checked_in']) ? (string)$info['checked_in'] : '';
        $entry['checked_in_by'] = isset($info['checked_in_by']) ? (string)$info['checked_in_by'] : '';
        // Signalé absent (mode Kiosk) : date du signalement, '' sinon
        $entry['absent'] = isset($info['absent']) ? (string)$info['absent'] : '';
        $entry['absent_by'] = isset($info['absent_by']) ? (string)$info['absent_by'] : '';
        $entry['removed'] = false;

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

    // Engagements payés (ou remboursés) puis supprimés d'I@nseo : gardés, d'après
    // la photo de leur paiement, pour ne pas perdre l'argent encaissé
    $journalNames = null;

    foreach ($payments as $key => $payment) {
        $id = intval($key);

        if ($id <= 0 || isset($engagements[$id]) || !is_array($payment)) {
            continue;
        }

        $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : '';

        if ($status !== 'PAYE' && $status !== 'REMBOURSE') {
            continue;
        }

        // Paiement plus ancien, sans photo : le nom de l'archer noté dans le journal
        if (empty($payment['snapshot']) && $journalNames === null) {
            $journalNames = [];

            if (function_exists('registrarc_load_journal')) {
                foreach (registrarc_load_journal($TourId) as $line) {
                    if (is_array($line) && !empty($line['engagement_id']) && isset($line['archer']) && trim((string)$line['archer']) !== '') {
                        $journalNames[intval($line['engagement_id'])] = (string)$line['archer'];
                    }
                }
            }
        }

        $info = isset($archerInfo[(string)$id]) && is_array($archerInfo[(string)$id]) ? $archerInfo[(string)$id] : [];
        $engagements[$id] = registrarc_removed_engagement($id, $payment, $info, isset($journalNames[$id]) ? $journalNames[$id] : '');
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

// ---------------------------------------------------------------------------
// Blasons (fonctionnement d'I@nseo, voir Partecipants/Fun_Targets.php et
// Api/ISK-NG/config.php::getTargetFaces) :
// Entries.EnTargetFace -> TargetFaces (nom, puis pour chaque distance n :
// TfTn = type de cible Targets.TarId, TfWn = diamètre en cm)
// Images : Common/Images/Targets/<TarId>.svg, 99 = image générique
// ---------------------------------------------------------------------------
// Retourne [EnId => ['id' => TfId, 'name' => nom du blason, 'faces' => [['target' => TarId,
//           'size' => cm, 'image' => adresse], ...], 'is_default' => bool,
//           'default_name' => nom du blason par défaut de la catégorie]], un type par cible/diamètre distinct
function registrarc_load_target_faces($TourId) {
    $TourId = intval($TourId);
    $columns = [];

    for ($i = 1; $i <= 8; $i++) {
        $columns[] = "tf.TfT$i, tf.TfW$i";
    }

    $query = "
        SELECT e.EnId, tf.TfId, tf.TfName, t.ToNumDist, " . implode(', ', $columns) . "
        FROM Entries e
        INNER JOIN TargetFaces tf
            ON tf.TfTournament = e.EnTournament
           AND tf.TfId = e.EnTargetFace
        INNER JOIN Tournament t
            ON t.ToId = e.EnTournament
        WHERE e.EnTournament = $TourId
          AND e.EnAthlete = 1
    ";

    $rs = safe_r_sql($query);
    $out = [];

    while ($rs && ($row = safe_fetch($rs))) {
        $numDist = max(1, min(8, intval($row->ToNumDist)));
        $faces = [];

        for ($i = 1; $i <= $numDist; $i++) {
            $target = intval($row->{'TfT' . $i});
            $size = intval($row->{'TfW' . $i});

            if ($target <= 0 || isset($faces[$target . '-' . $size])) {
                continue;
            }

            $faces[$target . '-' . $size] = ['target' => $target, 'size' => $size, 'image' => registrarc_target_image_url($target)];
        }

        // Nom traduit comme dans I@nseo : "~Nom" est une chaîne traduisible, le reste est affiché tel quel
        $name = trim((string)$row->TfName);

        if ($name !== '' && function_exists('get_text')) {
            $name = get_text($name, 'Tournament', '', true);
        }

        $out[intval($row->EnId)] = ['id' => intval($row->TfId), 'name' => $name, 'faces' => array_values($faces), 'is_default' => true, 'default_name' => ''];
    }

    // Blason par défaut de la catégorie de chaque archer, avec le tri d'I@nseo
    // (Participants/getRows.php::getTargets) : blason marqué par défaut, puis le plus
    // précis (expression régulière, code exact, motif). Seulement si la catégorie en a un.
    $query = "
        SELECT e.EnId, tf.TfId, tf.TfName, tf.TfDefault
        FROM Entries e
        INNER JOIN TargetFaces tf
            ON tf.TfTournament = e.EnTournament
           AND IF(tf.TfRegExp > '', CONCAT(TRIM(e.EnDivision), TRIM(e.EnClass)) REGEXP tf.TfRegExp, CONCAT(TRIM(e.EnDivision), TRIM(e.EnClass)) LIKE tf.TfClasses)
        WHERE e.EnTournament = $TourId
          AND e.EnAthlete = 1
        ORDER BY
            e.EnId,
            tf.TfDefault DESC,
            tf.TfRegExp > '' DESC,
            CONCAT(TRIM(e.EnDivision), TRIM(e.EnClass)) = tf.TfClasses DESC,
            LEFT(tf.TfClasses, 1) != '_' AND LEFT(tf.TfClasses, 1) != '%' DESC,
            LEFT(tf.TfClasses, 1) = '_' DESC,
            tf.TfClasses DESC,
            tf.TfClasses = '%'
    ";

    $rs = safe_r_sql($query);
    $seen = [];

    while ($rs && ($row = safe_fetch($rs))) {
        $id = intval($row->EnId);

        if (isset($seen[$id])) {
            continue;
        }

        $seen[$id] = true;

        if (isset($out[$id]) && intval($row->TfDefault) === 1 && intval($row->TfId) !== $out[$id]['id']) {
            $defaultName = trim((string)$row->TfName);

            if ($defaultName !== '' && function_exists('get_text')) {
                $defaultName = get_text($defaultName, 'Tournament', '', true);
            }

            $out[$id]['is_default'] = false;
            $out[$id]['default_name'] = $defaultName;
        }
    }

    return $out;
}

// Adresse de l'image SVG d'un type de cible fournie par I@nseo, ou '' si absente
function registrarc_target_image_url($targetId) {
    global $CFG;
    static $cache = [];

    $targetId = intval($targetId);

    if (isset($cache[$targetId])) {
        return $cache[$targetId];
    }

    $cache[$targetId] = '';

    if (isset($CFG->ROOT_DIR, $CFG->DOCUMENT_PATH)) {
        foreach ([$targetId, 99] as $id) {
            if (is_file($CFG->DOCUMENT_PATH . 'Common/Images/Targets/' . $id . '.svg')) {
                $cache[$targetId] = $CFG->ROOT_DIR . 'Common/Images/Targets/' . $id . '.svg';
                break;
            }
        }
    }

    return $cache[$targetId];
}

// Diamètres du blason : "80 cm", ou "40 cm / 60 cm" si les distances diffèrent
function registrarc_target_face_sizes($face) {
    $sizes = [];

    foreach (empty($face) ? [] : $face['faces'] as $f) {
        if ($f['size'] > 0) {
            $sizes[$f['size'] . ' cm'] = true;
        }
    }

    return implode(' / ', array_keys($sizes));
}

// Libellé complet : "Piquet Bleu · 80 cm"
function registrarc_target_face_label($face) {
    if (empty($face)) {
        return '';
    }

    return trim($face['name'] . ' · ' . registrarc_target_face_sizes($face), ' ·');
}
