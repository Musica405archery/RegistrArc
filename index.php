<?php
// ============================================================================
// RegistrArc - index.php
// Gestion des engagements / paiements
// Paiements stockés uniquement en JSON local.
// Rien n'est écrit dans Qualifications.QuNotes.
//
// Fichiers JSON :
// - Paiements        : Modules/Custom/RegistrArc/data/payments_<TourId>.json
// - Paramètres       : Modules/Custom/RegistrArc/data/settings_<TourId>.json
// - Groupes chèques  : Modules/Custom/RegistrArc/data/cheque_groups_<TourId>.json
//
// Tarification :
// - tarifs de base jeunes/adultes, club extérieur/organisateur
// - règles avancées JSON cumulatives définies dans config_tarifs.php
// - règles possibles sur Entries :
//   EnIndClEvent, EnTeamClEvent, EnIndFEvent, EnTeamFEvent, EnTeamMixEvent
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

// ---------------------------------------------------------------------------
// Reset filtres
// ---------------------------------------------------------------------------
$filtersKey = 'RegistrArc_filters_' . $TourId;

if (isset($_GET['reset'])) {
    unset($_SESSION[$filtersKey]);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// ---------------------------------------------------------------------------
// Filtres / tri
// ---------------------------------------------------------------------------
$filters = [
    'club'     => isset($_GET['club_filter'])     ? $_GET['club_filter']     : (isset($_SESSION[$filtersKey]['club'])     ? $_SESSION[$filtersKey]['club']     : 'all'),
    'category' => isset($_GET['category_filter']) ? $_GET['category_filter'] : (isset($_SESSION[$filtersKey]['category']) ? $_SESSION[$filtersKey]['category'] : 'all'),
    'payment'  => isset($_GET['payment_filter'])  ? $_GET['payment_filter']  : (isset($_SESSION[$filtersKey]['payment'])  ? $_SESSION[$filtersKey]['payment']  : 'all'),
    'session'  => isset($_GET['session_filter'])  ? $_GET['session_filter']  : (isset($_SESSION[$filtersKey]['session'])  ? $_SESSION[$filtersKey]['session']  : 'all'),
    'search'   => isset($_GET['search'])          ? trim($_GET['search'])    : (isset($_SESSION[$filtersKey]['search'])   ? $_SESSION[$filtersKey]['search']   : ''),
    // Vue : 'engagement' (une ligne par départ), 'archer' (une ligne par archer) ou 'fdm' (feuilles de marque)
    'view'     => isset($_GET['view'])            ? $_GET['view']            : (isset($_SESSION[$filtersKey]['view'])     ? $_SESSION[$filtersKey]['view']     : 'engagement'),
];

$filters['view'] = in_array($filters['view'], ['archer', 'fdm'], true) ? $filters['view'] : 'engagement';

$_SESSION[$filtersKey] = $filters;

$sortColumn = isset($_GET['sort']) ? $_GET['sort'] : 'nom';
$sortDirection = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'desc' : 'asc';

// ---------------------------------------------------------------------------
// Code tournoi
// ---------------------------------------------------------------------------
function registrarc_extract_tournament_code_from_export($data) {
    if (!is_array($data)) {
        return '';
    }

    if (!empty($data['tournament_code'])) {
        return trim((string)$data['tournament_code']);
    }

    if (!empty($data['tournament']) && is_array($data['tournament']) && !empty($data['tournament']['code'])) {
        return trim((string)$data['tournament']['code']);
    }

    return '';
}

// ---------------------------------------------------------------------------
// Dates export/import
// ---------------------------------------------------------------------------
function registrarc_export_timestamp(array $data, $fallbackFile = '') {
    $savedAt = isset($data['saved_at']) ? registrarc_timestamp_from_value($data['saved_at']) : 0;

    if ($savedAt > 0) {
        return $savedAt;
    }

    $exportedAt = isset($data['exported_at']) ? registrarc_timestamp_from_value($data['exported_at']) : 0;

    if ($exportedAt > 0) {
        return $exportedAt;
    }

    if ($fallbackFile !== '' && is_file($fallbackFile)) {
        $mtime = filemtime($fallbackFile);

        if ($mtime) {
            return $mtime;
        }
    }

    return 0;
}

// ---------------------------------------------------------------------------
// Redirection filtres
// ---------------------------------------------------------------------------
function registrarc_redirect_params($filters, $sortColumn, $sortDirection) {
    $params = [];

    foreach ($filters as $key => $value) {
        $value = trim((string)$value);

        if ($value === '' || $value === 'all' || ($key === 'view' && $value === 'engagement')) {
            continue;
        }

        if ($key === 'search' || $key === 'view') {
            $params[$key] = $value;
        } elseif ($key === 'session') {
            $params['session_filter'] = $value;
        } else {
            $params[$key . '_filter'] = $value;
        }
    }

    $sortColumn = trim((string)$sortColumn);
    $sortDirection = strtolower(trim((string)$sortDirection)) === 'desc' ? 'desc' : 'asc';

    if ($sortColumn !== '') {
        $params['sort'] = $sortColumn;
        $params['dir'] = $sortDirection;
    }

    return http_build_query($params);
}

// ---------------------------------------------------------------------------
// Clé stable inscription
// ---------------------------------------------------------------------------
function registrarc_registration_key($licence, $categorie, $session, $target, $letter) {
    return strtoupper(trim((string)$licence))
        . '|'
        . strtoupper(trim((string)$categorie))
        . '|'
        . trim((string)$session)
        . '|'
        . trim((string)$target)
        . '|'
        . strtoupper(trim((string)$letter));
}

// ---------------------------------------------------------------------------
// Infos tournoi
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$organizerClubCode = $tournament['organizer_code'];
$organizerClubName = $tournament['organizer_name'];
$tournamentName = $tournament['name'];
$tournamentCode = registrarc_current_tournament_code();

$tarifs = registrarc_load_tarifs($TourId, $organizerClubCode, $organizerClubName);
$ageClasses = registrarc_load_age_classes($TourId);
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifRules = registrarc_load_tarif_rules($TourId);
$dataDirStatus = registrarc_data_dir_status();
$canWrite = registrarc_can_write($TourId);
$isClosed = registrarc_is_closed($TourId);

// ---------------------------------------------------------------------------
// POST : paiements + import inscriptions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkACL(AclParticipants, AclReadWrite);

    $redirectQuery = registrarc_redirect_params($filters, $sortColumn, $sortDirection);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        $_SESSION['RegistrArc_payment_message'] = $formError;
        $_SESSION['RegistrArc_message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

    if (!$dataDirStatus['ok']) {
        $_SESSION['RegistrArc_payment_message'] = "Action impossible : le dossier JSON n'est pas accessible en écriture.";
        $_SESSION['RegistrArc_message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

    if (isset($_POST['import_registrations'])) {
        if (empty($_FILES['registrations_file']['tmp_name']) || !is_uploaded_file($_FILES['registrations_file']['tmp_name'])) {
            $_SESSION['RegistrArc_payment_message'] = "Aucun fichier JSON sélectionné pour l'import inscriptions.";
            $_SESSION['RegistrArc_message_type'] = 'error';
            header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
            exit();
        }

        $json = file_get_contents($_FILES['registrations_file']['tmp_name']);
        $data = json_decode($json, true);

        if (!is_array($data) || empty($data['registrations']) || !is_array($data['registrations'])) {
            $_SESSION['RegistrArc_payment_message'] = "Le fichier importé n'est pas un JSON inscriptions valide.";
            $_SESSION['RegistrArc_message_type'] = 'error';
            header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
            exit();
        }

        $importCode = registrarc_extract_tournament_code_from_export($data);
        $currentCode = $tournamentCode;

        $currentPaymentsFile = registrarc_payments_file_path($TourId);
        $currentPaymentsTs = is_file($currentPaymentsFile) ? filemtime($currentPaymentsFile) : 0;
        $importTs = registrarc_export_timestamp($data, $_FILES['registrations_file']['tmp_name']);

        $localMap = [];
        $localQuery = "
            SELECT
                e.EnId AS engagement_id,
                UPPER(TRIM(e.EnCode)) AS licence,
                CONCAT(e.EnDivision, e.EnClass) AS categorie,
                q.QuSession AS session_no,
                q.QuTarget AS target,
                q.QuLetter AS letter
            FROM Entries e
            LEFT JOIN Qualifications q
                ON e.EnId = q.QuId
            WHERE e.EnTournament = $TourId
              AND e.EnAthlete = 1
              AND TRIM(e.EnCode) <> ''
        ";
        $localRs = safe_r_sql($localQuery);

        if ($localRs) {
            while ($r = safe_fetch($localRs)) {
                $key = registrarc_registration_key(
                    $r->licence,
                    $r->categorie,
                    $r->session_no,
                    $r->target,
                    $r->letter
                );
                $localMap[$key] = intval($r->engagement_id);
            }
        }

        $matched = 0;
        $unmatched = 0;
        $paidImported = 0;
        $unpaidImported = 0;

        $changes = [];

        // Changements à appliquer d'après le fichier importé
        foreach ($data['registrations'] as $reg) {
            if (!is_array($reg)) {
                $unmatched++;
                continue;
            }

            $key = isset($reg['key']) ? trim((string)$reg['key']) : '';

            if ($key === '') {
                $key = registrarc_registration_key(
                    isset($reg['licence']) ? $reg['licence'] : '',
                    isset($reg['categorie']) ? $reg['categorie'] : '',
                    isset($reg['session']) ? $reg['session'] : '',
                    isset($reg['target']) ? $reg['target'] : '',
                    isset($reg['letter']) ? $reg['letter'] : ''
                );
            }

            if (!isset($localMap[$key])) {
                $unmatched++;
                continue;
            }

            $engagementId = $localMap[$key];
            $payment = isset($reg['payment']) && is_array($reg['payment']) ? $reg['payment'] : [];
            $status = isset($payment['status']) ? strtoupper(trim((string)$payment['status'])) : 'NON_PAYE';
            $method = isset($payment['method']) ? registrarc_clean_payment_method($payment['method'], $paymentModes) : '';
            $receiptRequired = !empty($payment['receipt_required']);

            if ($status === 'PAYE') {
                $changes[] = ['id' => $engagementId, 'action' => 'pay', 'method' => $method !== '' ? $method : 'ESP', 'receipt' => $receiptRequired];
                $paidImported++;
            } else {
                $changes[] = ['id' => $engagementId, 'action' => 'cancel'];
                $unpaidImported++;
            }

            $matched++;
        }

        $importResult = registrarc_apply_payment_changes($TourId, $changes, 'import');
        $saved = $importResult['ok'];

        if ($saved) {
            $warnings = [];

            if ($importCode !== '' && $currentCode !== '' && strtoupper($importCode) !== strtoupper($currentCode)) {
                $warnings[] = "code tournoi différent : importé $importCode / courant $currentCode";
            }

            if ($importTs > 0 && $currentPaymentsTs > 0 && $importTs < $currentPaymentsTs) {
                $warnings[] = "fichier importé plus ancien que les paiements locaux";
            }

            $msg = "Import inscriptions terminé : $matched correspondance(s), $unmatched non trouvée(s), $paidImported payée(s), $unpaidImported non payée(s).";

            if (!empty($warnings)) {
                $msg .= " Attention : " . implode(' ; ', $warnings) . ".";
                $_SESSION['RegistrArc_message_type'] = 'warning';
            } else {
                $_SESSION['RegistrArc_message_type'] = 'success';
            }

            $_SESSION['RegistrArc_payment_message'] = $msg;
        } else {
            $_SESSION['RegistrArc_payment_message'] = "Import impossible : erreur lors de l'écriture du JSON paiements." . registrarc_storage_error_suffix();
            $_SESSION['RegistrArc_message_type'] = 'error';
        }

        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    }

    $redirect = function () use ($redirectQuery) {
        header('Location: ' . $_SERVER['PHP_SELF'] . ($redirectQuery ? '?' . $redirectQuery : ''));
        exit();
    };

    $setMessage = function ($message, $type) {
        $_SESSION['RegistrArc_payment_message'] = $message;
        $_SESSION['RegistrArc_message_type'] = $type;
    };

    // Message après une opération de paiement (avec les engagements non traités)
    $reportPayment = function (array $result, $okMessage) use ($TourId, $setMessage) {
        if (!$result['ok']) {
            $setMessage("Erreur : impossible d'écrire le fichier JSON de paiements." . registrarc_storage_error_suffix(), 'error');
            return;
        }

        $skippedMessage = registrarc_skipped_message($TourId, $result['skipped']);
        $setMessage((empty($result['done']) ? 'Aucune modification.' : $okMessage) . $skippedMessage, $skippedMessage !== '' ? 'warning' : 'success');
    };

    $postedIds = [];

    if (!empty($_POST['engagements']) && is_array($_POST['engagements'])) {
        foreach ($_POST['engagements'] as $id) {
            if (intval($id) > 0) {
                $postedIds[] = intval($id);
            }
        }
    }

    $postedIds = array_values(array_unique($postedIds));

    if (isset($_POST['bulk_action'])) {
        $action = (string)$_POST['bulk_action'];

        if (empty($postedIds)) {
            $setMessage('Aucun engagement sélectionné.', 'error');
            $redirect();
        }

        $bulkMethod = isset($_POST['bulk_payment_method'])
            ? registrarc_clean_payment_method($_POST['bulk_payment_method'], $paymentModes)
            : 'ESP';

        if ($action === 'validate') {
            $result = registrarc_record_payments($TourId, $postedIds, $bulkMethod, false, 'liste');
            $reportPayment($result, 'Paiement validé pour les engagements sélectionnés.');

            $createGroup = !empty($_POST['create_cheque_group_after_payment'])
                && $_POST['create_cheque_group_after_payment'] === '1'
                && $bulkMethod === 'CHQ';

            if ($result['ok'] && $createGroup && count($result['done']) >= 2) {
                $groupResult = registrarc_create_cheque_group($TourId, $result['done'], registrarc_load_payments($TourId));

                if (!empty($groupResult['ok'])) {
                    $setMessage('Paiement validé et chèque groupé créé. Il sera visible dans la remise de chèque.', 'success');
                } else {
                    $setMessage("Paiement validé, mais le chèque groupé n'a pas pu être créé : " . $groupResult['message'], 'warning');
                }
            }
        } elseif ($action === 'unvalidate') {
            $reportPayment(registrarc_cancel_payments($TourId, $postedIds, 'liste'), 'Paiement supprimé : les engagements sélectionnés sont non payés, plus pointés au greffe (remboursements annulés le cas échéant).');
        } elseif ($action === 'refund') {
            $reason = isset($_POST['refund_reason']) ? trim((string)$_POST['refund_reason']) : '';

            if ($reason === '') {
                $setMessage('Remboursement non enregistré : le motif du remboursement est obligatoire.', 'error');
            } else {
                $reportPayment(registrarc_refund_payments($TourId, $postedIds, $bulkMethod, 'liste', $reason), 'Remboursement enregistré pour les engagements sélectionnés.');
            }
        }

        $redirect();
    }

    if (isset($_POST['validate_payment']) && isset($_POST['engagement_id'])) {
        $engagementId = intval($_POST['engagement_id']);
        $action = (string)$_POST['validate_payment'];

        if ($engagementId > 0) {
            if ($action === 'validate') {
                $method = isset($_POST['payment_method']) ? registrarc_clean_payment_method($_POST['payment_method'], $paymentModes) : 'ESP';
                $reportPayment(registrarc_record_payments($TourId, [$engagementId], $method, !empty($_POST['receipt_required']), 'liste'), 'Paiement de cet engagement validé.');
            } elseif ($action === 'refund') {
                $method = isset($_POST['refund_method']) ? registrarc_clean_payment_method($_POST['refund_method'], $paymentModes) : 'ESP';
                $reason = isset($_POST['refund_reason']) ? trim((string)$_POST['refund_reason']) : '';

                if ($reason === '') {
                    $setMessage('Remboursement non enregistré : le motif du remboursement est obligatoire.', 'error');
                } else {
                    $reportPayment(registrarc_refund_payments($TourId, [$engagementId], $method, 'liste', $reason), 'Remboursement enregistré.');
                }
            } else {
                $reportPayment(registrarc_cancel_payments($TourId, [$engagementId], 'liste'), 'Paiement supprimé : cet engagement est maintenant non payé et plus pointé au greffe.');
            }
        }

        $redirect();
    }

    if (isset($_POST['save_archer_info']) && isset($_POST['engagement_id'])) {
        $saved = registrarc_set_archer_note($TourId, $_POST['engagement_id'], isset($_POST['note']) ? $_POST['note'] : '');

        $setMessage($saved ? 'Note enregistrée.' : "Erreur : impossible d'enregistrer la note." . registrarc_storage_error_suffix(), $saved ? 'success' : 'error');
        $redirect();
    }

    // Vue par archer : présenté au greffe pour un départ (croix rouge / coche verte)
    if (isset($_POST['toggle_checkin'])) {
        if (!registrarc_toggle_checkin($TourId, $_POST['toggle_checkin'])) {
            $setMessage("Erreur : impossible d'enregistrer le passage au greffe." . registrarc_storage_error_suffix(), 'error');
        }

        $redirect();
    }
}

// ---------------------------------------------------------------------------
// Engagements et montants (calcul commun à toutes les pages)
// ---------------------------------------------------------------------------
$engagementsData = [];

foreach (registrarc_load_engagements($TourId) as $engagement) {
    $engagementsData[] = [
        'engagement_id'        => $engagement['engagement_id'],
        'key'                  => registrarc_registration_key(
            $engagement['licence'],
            $engagement['categorie'],
            $engagement['session'],
            $engagement['target'],
            $engagement['letter']
        ),
        'licence'              => $engagement['licence'],
        'nom'                  => $engagement['nom'],
        'prenom'               => $engagement['prenom'],
        'categorie'            => $engagement['categorie'],
        'age_class'            => $engagement['age_class'],
        'class_code'           => $engagement['class_code'],
        'is_upgraded'          => $engagement['is_upgraded'],
        'club'                 => $engagement['club'],
        'country_code'         => $engagement['country_code'],
        'session'              => $engagement['session'],
        'target'               => $engagement['target'],
        'letter'               => $engagement['letter'],
        'target_no'            => $engagement['target_no'],
        'target_label'         => $engagement['target_label'],
        'target_is_unassigned' => $engagement['target_is_unassigned'],
        'score'                => $engagement['score'],
        'hits'                 => $engagement['hits'],
        'payment_status'       => $engagement['is_paid'] ? 1 : 0,
        'payment_method'       => $engagement['payment_method'],
        'receipt_required'     => $engagement['receipt_required'],
        'numero_engagement'    => $engagement['numero'],
        'montant_base'         => $engagement['amount_due'],
        'montant'              => $engagement['amount'],
        'tarif_rule_label'     => registrarc_engagement_special_label($engagement),
        'state'                => $engagement['state'],
        'is_free'              => registrarc_nothing_due($engagement['amount_due']),
        'note'                 => $engagement['note'],
        'checked_in'           => $engagement['checked_in'],
        'checked_in_by'        => $engagement['checked_in_by'],
        'deposit_id'           => $engagement['deposit_id'],
        'refund_method'        => isset($engagement['payment']['refund_method']) ? (string)$engagement['payment']['refund_method'] : '',
        'refund_reason'        => isset($engagement['payment']['refund_reason']) ? (string)$engagement['payment']['refund_reason'] : '',
        // Ordre du tri par statut : non payé, gratuit ou remboursé, payé
        'status_rank'          => $engagement['is_paid'] ? 2 : ($engagement['state'] === 'due' ? 0 : 1),
    ];
}

// ---------------------------------------------------------------------------
// Listes filtres
// ---------------------------------------------------------------------------
$clubs = [];
$categories = [];
$sessions = [];

foreach ($engagementsData as $e) {
    if (!empty($e['club'])) {
        $clubs[$e['club']] = true;
    }

    if (!empty($e['categorie'])) {
        $categories[$e['categorie']] = true;
    }

    if ($e['session'] !== '') {
        $sessions[$e['session']] = true;
    }
}

$clubs = array_keys($clubs);
sort($clubs, SORT_NATURAL | SORT_FLAG_CASE);

$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$sessions = array_keys($sessions);
sort($sessions, SORT_NUMERIC);

// ---------------------------------------------------------------------------
// Filtrage affiché
// ---------------------------------------------------------------------------
$displayEngagements = [];
// « ; » sépare plusieurs recherches : un engagement est affiché s'il correspond à l'une d'elles
$searchTerms = array_values(array_filter(array_map('trim', explode(';', mb_strtolower($filters['search']))), 'strlen'));

foreach ($engagementsData as $e) {
    if ($filters['club'] !== 'all' && $filters['club'] !== '' && stripos((string)$e['club'], $filters['club']) === false) {
        continue;
    }

    if ($filters['category'] !== 'all' && $filters['category'] !== '' && stripos((string)$e['categorie'], $filters['category']) === false) {
        continue;
    }

    $stateFilters = ['paid' => 'paid', 'unpaid' => 'due', 'free' => 'free', 'refunded' => 'refunded'];

    if (isset($stateFilters[$filters['payment']]) && $e['state'] !== $stateFilters[$filters['payment']]) {
        continue;
    }

    if ($filters['session'] !== 'all' && (string)$e['session'] !== (string)$filters['session']) {
        continue;
    }

    if (!empty($searchTerms)) {
        $haystack = mb_strtolower(
            $e['licence'] . ' ' .
            $e['nom'] . ' ' .
            $e['prenom'] . ' ' .
            $e['club'] . ' ' .
            $e['country_code'] . ' ' .
            $e['categorie']
        );
        $found = false;

        foreach ($searchTerms as $term) {
            if (strpos($haystack, $term) !== false) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            continue;
        }
    }

    $displayEngagements[] = $e;
}

// ---------------------------------------------------------------------------
// Tri
// ---------------------------------------------------------------------------
usort($displayEngagements, function($a, $b) use ($sortColumn, $sortDirection) {
    $dir = ($sortDirection === 'desc') ? -1 : 1;

    switch ($sortColumn) {
        case 'engagement':
            return $dir * ($a['numero_engagement'] <=> $b['numero_engagement']);

        case 'licence':
            return $dir * strcmp($a['licence'], $b['licence']);

        case 'nom':
            return $dir * strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom']));

        case 'prenom':
            return $dir * strcmp(mb_strtolower($a['prenom']), mb_strtolower($b['prenom']));

        case 'club':
            return $dir * strcmp(mb_strtolower((string)$a['club']), mb_strtolower((string)$b['club']));

        case 'categorie':
            return $dir * strcmp(mb_strtolower((string)$a['categorie']), mb_strtolower((string)$b['categorie']));

        case 'depart':
            return $dir * strcmp((string)$a['session'], (string)$b['session']);

        case 'cible':
            return $dir * strcmp((string)$a['target_no'], (string)$b['target_no']);

        case 'montant':
            return $dir * ($a['montant'] <=> $b['montant']);

        case 'statut':
            return $dir * ($a['status_rank'] <=> $b['status_rank']);

        case 'paiement':
            return $dir * strcmp((string)$a['payment_method'], (string)$b['payment_method']);

        default:
            return $dir * strcmp(mb_strtolower($a['nom']), mb_strtolower($b['nom']));
    }
});

// ---------------------------------------------------------------------------
// Vue par archer : une ligne par licence, une colonne par départ. Un archer est
// affiché si l'un de ses engagements passe les filtres ; ses colonnes montrent
// tous ses départs.
// ---------------------------------------------------------------------------
$displayArchers = [];

if ($filters['view'] === 'archer') {
    $archerKey = function (array $e) {
        return $e['licence'] !== '' ? 'L' . strtoupper($e['licence']) : 'E' . $e['engagement_id'];
    };

    $allByArcher = [];

    foreach ($engagementsData as $e) {
        $allByArcher[$archerKey($e)][] = $e;
    }

    // Ordre du tri choisi : celui du premier engagement affiché de l'archer
    foreach ($displayEngagements as $e) {
        $key = $archerKey($e);

        if (isset($displayArchers[$key])) {
            continue;
        }

        $bySession = [];
        $categoriesOfArcher = [];

        // Une catégorie par valeur distincte, gardée avec un engagement surclassé s'il y en a un
        foreach ($allByArcher[$key] as $item) {
            $bySession[(string)$item['session']][] = $item;

            if (!isset($categoriesOfArcher[$item['categorie']]) || (!empty($item['is_upgraded']) && empty($categoriesOfArcher[$item['categorie']]['is_upgraded']))) {
                $categoriesOfArcher[$item['categorie']] = $item;
            }
        }

        $displayArchers[$key] = [
            'archer' => $e,
            'categories' => array_values($categoriesOfArcher),
            'sessions' => $bySession,
        ];
    }

    $archerTargetFaces = registrarc_load_target_faces($TourId);
}

// ---------------------------------------------------------------------------
// Export inscriptions affichées
// ---------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export_registrations') {
    $exportRows = [];

    foreach ($displayEngagements as $e) {
        $exportRows[] = [
            'key' => $e['key'],
            'licence' => $e['licence'],
            'nom' => $e['nom'],
            'prenom' => $e['prenom'],
            'categorie' => $e['categorie'],
            'club' => $e['club'],
            'country_code' => $e['country_code'],
            'session' => $e['session'],
            'target' => $e['target'],
            'letter' => $e['letter'],
            'entries_flags' => [
                'EnIndClEvent' => $e['EnIndClEvent'],
                'EnTeamClEvent' => $e['EnTeamClEvent'],
                'EnIndFEvent' => $e['EnIndFEvent'],
                'EnTeamFEvent' => $e['EnTeamFEvent'],
                'EnTeamMixEvent' => $e['EnTeamMixEvent'],
            ],
            'payment' => [
                'status' => $e['payment_status'] ? 'PAYE' : 'NON_PAYE',
                'method' => $e['payment_status'] ? $e['payment_method'] : '',
                'receipt_required' => !empty($e['receipt_required']),
            ],
        ];
    }

    $payload = [
        'module' => 'RegistrArc',
        'type' => 'registrations_export',
        'version' => 1,
        'tour_id' => $TourId,
        'tournament_code' => $tournamentCode,
        'exported_at' => date('Y-m-d H:i:s'),
        'filters' => $filters,
        'registrations_count' => count($exportRows),
        'registrations' => $exportRows,
    ];

    $codePart = registrarc_safe_filename_part($tournamentCode);
    $filename = 'RegistrArc_inscriptions_' . ($codePart !== '' ? $codePart . '_' : '') . intval($TourId) . '_' . date('Ymd_His') . '.json';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ---------------------------------------------------------------------------
// Affichage
// ---------------------------------------------------------------------------
$PAGE_TITLE = "RegistrArc - Paiements des engagements";
$IncludeJquery = true;
include('Common/Templates/head.php');
?>

<link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/index.css'); ?>">

<?php
// Lien de tri d'une colonne
$sortLink = function ($key, $label) use ($sortColumn, $sortDirection) {
    $dir = ($sortColumn == $key && $sortDirection == 'asc') ? 'desc' : 'asc';
    $arrow = $sortColumn == $key ? ($sortDirection == 'asc' ? ' ▲' : ' ▼') : '';

    return '<a href="?' . htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $key, 'dir' => $dir]))) . '">' . htmlspecialchars($label) . $arrow . '</a>';
};

// Catégorie, en rouge si l'archer est surclassé (EnAgeClass différente de EnClass), comme dans le kiosque
$categoryHtml = function (array $e) {
    $html = htmlspecialchars($e['categorie']);

    if (empty($e['is_upgraded'])) {
        return $html;
    }

    return '<span class="is-upgraded" title="' . htmlspecialchars('Surclassé : ' . $e['age_class'] . ' > ' . $e['class_code']) . '">' . $html . '</span>';
};
?>

<div class="registrarc-container">
    <div class="registrarc-header">
        <div>
            <div class="registrarc-header-title">Gestion des engagements</div>
            <div class="registrarc-header-sub"><?php echo htmlspecialchars($tournamentName ?: "RegistrArc, le module de greffe pour I@nseo"); ?></div>
        </div>

        <div class="header-actions">
            <a href="update.php" id="registrarcUpdateBtn" class="btn-update" hidden>⬆ Mise à jour</a>
            <a href="dashboard.php">Tableau de bord</a>
            <a href="kiosk.php">Mode Kiosk</a>
            <a href="config_tarifs.php">⚙ Paramètres</a>
            <a href="aide.php">? Aide</a>
        </div>
    </div>

    <?php if ($isClosed): ?>
        <div class="alert alert-info">
            <strong>Concours clôturé :</strong> la liste est en lecture seule. Il peut être rouvert depuis le <a href="dashboard.php">tableau de bord</a>.
        </div>
    <?php endif; ?>

    <?php if (!$dataDirStatus['ok']): ?>
        <div class="alert alert-warning">
            <strong>Attention :</strong>
            <?php echo htmlspecialchars($dataDirStatus['message']); ?><br>
            Les paiements ne pourront pas être enregistrés tant que ce problème n'est pas corrigé.<br>
            Dossier concerné :
            <span class="storage-path"><?php echo htmlspecialchars($dataDirStatus['path']); ?></span><br>
            Commande Ubuntu possible :
            <span class="storage-path">mkdir -p Modules/Custom/RegistrArc/data && chmod 775 Modules/Custom/RegistrArc/data</span>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['RegistrArc_payment_message'])): ?>
        <?php
        $type = isset($_SESSION['RegistrArc_message_type']) ? $_SESSION['RegistrArc_message_type'] : 'success';
        $msg = $_SESSION['RegistrArc_payment_message'];
        unset($_SESSION['RegistrArc_payment_message'], $_SESSION['RegistrArc_message_type']);
        ?>
        <div class="alert alert-<?php echo htmlspecialchars($type); ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">Filtres</div>

        <form method="GET" id="filterForm">
            <div class="filters-row">
                <div class="filter-group">
                    <label for="club_filter">Club</label>
                    <select name="club_filter" id="club_filter">
                        <option value="all">Tous les clubs</option>
                        <?php foreach ($clubs as $club): ?>
                            <option value="<?php echo htmlspecialchars($club); ?>" <?php echo ($filters['club'] === $club ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($club); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="category_filter">Catégorie</label>
                    <select name="category_filter" id="category_filter">
                        <option value="all">Toutes catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($filters['category'] === $cat ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="session_filter">Départ</label>
                    <select name="session_filter" id="session_filter">
                        <option value="all">Tous les départs</option>
                        <?php foreach ($sessions as $sess): ?>
                            <option value="<?php echo htmlspecialchars($sess); ?>" <?php echo ($filters['session'] === (string)$sess ? 'selected' : ''); ?>>
                                Départ <?php echo htmlspecialchars($sess); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="payment_filter">Statut paiement</label>
                    <select name="payment_filter" id="payment_filter">
                        <option value="all" <?php echo ($filters['payment'] === 'all') ? 'selected' : ''; ?>>Tous</option>
                        <option value="paid" <?php echo ($filters['payment'] === 'paid') ? 'selected' : ''; ?>>Payés</option>
                        <option value="unpaid" <?php echo ($filters['payment'] === 'unpaid') ? 'selected' : ''; ?>>Non payés</option>
                        <option value="free" <?php echo ($filters['payment'] === 'free') ? 'selected' : ''; ?>>Gratuits</option>
                        <option value="refunded" <?php echo ($filters['payment'] === 'refunded') ? 'selected' : ''; ?>>Remboursés</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="search">Recherche</label>
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Licence, nom, prénom, club..." autocomplete="off">
                </div>

                <div class="filter-actions">
                    <a href="?reset=1" id="filterReset" class="btn-reset" title="Réinitialiser les filtres" aria-label="Réinitialiser les filtres">✕</a>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <!-- À gauche : titre et barre d'action ; à droite : choix de la vue (à la hauteur du titre), l'opérateur dessous -->
        <div class="actions-layout">
        <div class="actions-main">
        <div class="card-title">Actions sur les engagements sélectionnés</div>

        <form method="POST" id="bulkForm">
            <?php echo registrarc_form_fields($TourId); ?>
            <input type="hidden" name="create_cheque_group_after_payment" id="create_cheque_group_after_payment" value="0">
            <input type="hidden" name="refund_reason" id="bulk_refund_reason" value="">

            <div class="filters-row bulk-row">
                <div class="filter-group">
                    <label for="bulk_payment_method">Mode de paiement</label>
                    <select name="bulk_payment_method" id="bulk_payment_method">
                        <?php foreach ($paymentModes as $code => $label): ?>
                            <option value="<?php echo htmlspecialchars($code); ?>">
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions bulk-actions">
                    <!-- Nombre d'engagements cochés : affiché une seule fois, pour garder la barre compacte -->
                    <span class="bulk-count" id="bulkCount" hidden></span>

                    <?php if ($canWrite): ?>
                        <!-- Valider, Supprimer le paiement, Rembourser : actifs à partir de 2 engagements cochés (un seul : boutons de la ligne) -->
                        <button type="submit" name="bulk_action" value="validate" class="btn-success" data-bulk-min="2" data-title="Valider le paiement des engagements cochés" disabled onclick="return confirmBulkAction('validate');">Valider</button>
                        <button type="submit" name="bulk_action" value="unvalidate" class="btn-danger" data-bulk-min="2" data-title="Supprimer le paiement des engagements cochés" disabled onclick="return confirmBulkAction('unvalidate');">Supprimer le paiement</button>
                        <button type="submit" name="bulk_action" value="refund" class="btn-ghost" data-bulk-min="2" data-title="Rembourser les engagements cochés" disabled onclick="return confirmBulkAction('refund');">Rembourser</button>
                    <?php endif; ?>

                    <button type="button" id="bulk_invoice_btn" class="btn-invoice bulk-invoice-button" onclick="return factureGroupePrompt();">Facture groupée</button>
                </div>

                <div class="filter-actions bulk-tools">
                    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['action' => 'export_registrations']))); ?>" id="exportLink" class="btn-export" title="Exporter les inscriptions affichées et leurs paiements">Exporter</a>

                    <?php if ($canWrite): ?>
                        <button type="button" class="btn-import" title="Importer les paiements d'un autre poste RegistrArc" onclick="toggleImportRegistrations();">Importer</button>
                    <?php endif; ?>

                    <button type="button" class="btn-cheque" onclick="return openChequeDeposit();">Remise de chèque</button>
                    <button type="button" class="btn-print-list" onclick="return printGreffeList();">Liste Greffe</button>
                </div>
            </div>

        </form>
        </div>

        <div class="actions-side">
            <!-- Vue de la liste : rattachée au formulaire des filtres (recherche en direct) -->
            <div class="view-switch" role="radiogroup" aria-label="Vue de la liste">
                <label><input type="radio" name="view" value="engagement" form="filterForm" <?php echo $filters['view'] === 'engagement' ? 'checked' : ''; ?>><span>Par engagement</span></label>
                <label><input type="radio" name="view" value="archer" form="filterForm" <?php echo $filters['view'] === 'archer' ? 'checked' : ''; ?>><span>Par archer</span></label>
                <label title="Feuilles de marque"><input type="radio" name="view" value="fdm" form="filterForm" <?php echo $filters['view'] === 'fdm' ? 'checked' : ''; ?>><span>FdM</span></label>
            </div>

            <?php if ($canWrite): ?>
                <div class="filter-group operator-field">
                    <label for="operatorName">Opérateur</label>
                    <input type="text" id="operatorName" placeholder="Votre prénom" maxlength="40" autocomplete="off">
                </div>
            <?php endif; ?>
        </div>
        </div>

        <?php if ($canWrite): ?>
        <!-- Pointage au greffe (vue par archer) : les boutons ✕ / ✓ de la liste envoient ce formulaire -->
        <form method="POST" id="checkinForm" hidden>
            <?php echo registrarc_form_fields($TourId); ?>
        </form>

        <form method="POST" enctype="multipart/form-data" id="importRegistrationsForm" style="display:none;margin-top:12px;">
            <?php echo registrarc_form_fields($TourId); ?>
            <div class="filters-row">
                <div class="filter-group">
                    <label for="registrations_file">Fichier d'inscriptions exporté par RegistrArc</label>
                    <input type="file" name="registrations_file" id="registrations_file" accept="application/json,.json" class="import-input">
                </div>

                <div class="filter-actions">
                    <button type="submit" name="import_registrations" value="1" class="btn-primary" onclick="return confirmImportRegistrations();">Confirmer l'import</button>
                    <button type="button" class="btn-ghost" onclick="toggleImportRegistrations();">Annuler</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <?php
    $total = 0;
    $displayCount = count($displayEngagements);

    foreach ($displayEngagements as $e) {
        $total += $e['montant'];
    }

    $statusClasses = ['paid' => 'status-paid', 'due' => 'status-unpaid', 'free' => 'status-free', 'refunded' => 'status-refunded'];
    ?>

    <!-- Remplacé pendant la recherche en direct (assets/js/index.js) -->
    <div id="engagementsList">
    <?php if ($filters['view'] === 'fdm'): ?>
        <?php
        // Feuilles de marque : une ligne par départ d'un archer ; la feuille est
        // imprimée par fdm.php (dessin I@nseo, flèches saisies, totaux et cumul)
        $fdmPrintable = 0;

        foreach ($displayEngagements as $e) {
            $fdmPrintable += $e['session'] !== '' ? 1 : 0;
        }
        ?>
        <div class="fdm-bar">
            <button type="button" class="btn-primary" id="fdmPrintSelected" disabled>Imprimer les feuilles cochées</button>
            <span class="fdm-help">Feuille de marque avec les flèches saisies dans I@nseo, le total de chaque volée et le cumul. Cochez un ou plusieurs archers : leurs feuilles sont réunies dans un seul PDF.</span>
        </div>

        <div class="table-wrapper">
            <div class="table-responsive">
                <table class="fdm-view">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <input type="checkbox" id="fdmSelectAll" title="Tout sélectionner" <?php echo $fdmPrintable ? '' : 'disabled'; ?>>
                            </th>
                            <th><?php echo $sortLink('licence', 'Licence'); ?></th>
                            <th><?php echo $sortLink('nom', 'Nom'); ?></th>
                            <th><?php echo $sortLink('prenom', 'Prénom'); ?></th>
                            <th><?php echo $sortLink('club', 'Club'); ?></th>
                            <th><?php echo $sortLink('categorie', 'Catégorie'); ?></th>
                            <th><?php echo $sortLink('depart', 'Départ'); ?></th>
                            <th><?php echo $sortLink('cible', 'Cible'); ?></th>
                            <th style="text-align:right;">Score saisi</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayEngagements as $e): ?>
                            <?php $printable = $e['session'] !== ''; ?>
                            <tr>
                                <td class="col-check">
                                    <input type="checkbox" class="fdm-checkbox" value="<?php echo intval($e['engagement_id']); ?>" <?php echo $printable ? '' : 'disabled title="Archer sans départ : pas de feuille de marque"'; ?>>
                                </td>
                                <td class="num"><?php echo htmlspecialchars($e['licence']); ?></td>
                                <td><strong><?php echo htmlspecialchars($e['nom']); ?></strong></td>
                                <td><?php echo htmlspecialchars($e['prenom']); ?></td>
                                <td><?php echo htmlspecialchars($e['club']); ?></td>
                                <td><?php echo $categoryHtml($e); ?></td>
                                <td><?php echo $printable ? 'Départ ' . htmlspecialchars($e['session']) : '—'; ?></td>
                                <td class="<?php echo !empty($e['target_is_unassigned']) ? 'target-unassigned' : ''; ?>"><?php echo htmlspecialchars($e['target_label']); ?></td>
                                <td class="num" style="text-align:right;">
                                    <?php if ($e['hits'] > 0): ?>
                                        <strong><?php echo intval($e['score']); ?></strong> <span class="fdm-hits">(<?php echo intval($e['hits']); ?> flèches)</span>
                                    <?php else: ?>
                                        <span class="fdm-hits">pas encore saisi</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($printable): ?>
                                        <a class="btn-small" href="fdm.php?ids=<?php echo intval($e['engagement_id']); ?>" target="_blank" rel="noopener">Imprimer</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($displayEngagements)): ?>
                            <tr>
                                <td colspan="10" style="text-align:center;padding:20px;color:var(--muted);">Aucun engagement trouvé.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-footer num">
            <div>Feuilles affichées : <strong><?php echo intval($fdmPrintable); ?></strong></div>
        </div>
    <?php elseif ($filters['view'] === 'archer'): ?>
        <?php
        // Colonnes : les départs du concours (et « Sans départ » s'il y en a)
        $archerSessions = $sessions;

        foreach ($engagementsData as $e) {
            if ($e['session'] === '') {
                $archerSessions[] = '';
                break;
            }
        }

        // Reste à passer au greffe, par départ, parmi les archers affichés :
        // archers attendus sur le départ / archers pas encore pointés
        $sessionExpected = array_fill_keys(array_map('strval', $archerSessions), 0);
        $sessionRemaining = $sessionExpected;

        foreach ($displayArchers as $row) {
            foreach ($row['sessions'] as $sess => $items) {
                if (!isset($sessionExpected[(string)$sess])) {
                    continue;
                }

                $sessionExpected[(string)$sess]++;

                foreach ($items as $item) {
                    if ($item['checked_in'] === '') {
                        $sessionRemaining[(string)$sess]++;
                        break;
                    }
                }
            }
        }
        ?>

        <div class="table-wrapper">
            <div class="table-responsive">
                <table class="archer-view">
                    <thead>
                        <tr>
                            <th><?php echo $sortLink('licence', 'Licence'); ?></th>
                            <th><?php echo $sortLink('nom', 'Nom'); ?></th>
                            <th><?php echo $sortLink('prenom', 'Prénom'); ?></th>
                            <th><?php echo $sortLink('club', 'Club'); ?></th>
                            <th><?php echo $sortLink('categorie', 'Catégorie'); ?></th>
                            <?php foreach ($archerSessions as $sess): ?>
                                <?php
                                $expected = $sessionExpected[(string)$sess];
                                $remaining = $sessionRemaining[(string)$sess];
                                ?>
                                <th class="col-session">
                                    <?php echo $sess !== '' ? 'Départ ' . htmlspecialchars($sess) : 'Sans départ'; ?>
                                    <?php if ($expected > 0): ?>
                                        <?php if ($remaining > 0): ?>
                                            <span class="session-left num" title="<?php echo intval($remaining); ?> archer(s) à passer au greffe sur <?php echo intval($expected); ?> attendu(s)"><?php echo intval($remaining); ?>/<?php echo intval($expected); ?></span>
                                        <?php else: ?>
                                            <span class="session-left is-complete num" title="Les <?php echo intval($expected); ?> archer(s) attendu(s) sont passés au greffe">✓ <?php echo intval($expected); ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($displayArchers as $row): ?>
                            <?php $a = $row['archer']; ?>
                            <tr>
                                <td class="num"><?php echo htmlspecialchars($a['licence']); ?></td>
                                <td><strong><?php echo htmlspecialchars($a['nom']); ?></strong></td>
                                <td><?php echo htmlspecialchars($a['prenom']); ?></td>
                                <td><?php echo htmlspecialchars($a['club']); ?></td>
                                <td><?php echo implode(', ', array_map($categoryHtml, $row['categories'])); ?></td>
                                <?php foreach ($archerSessions as $sess): ?>
                                    <td class="col-session">
                                        <?php if (empty($row['sessions'][(string)$sess])): ?>
                                            <span class="checkin-none" title="Pas inscrit sur ce départ">-</span>
                                        <?php else: ?>
                                            <?php foreach ($row['sessions'][(string)$sess] as $item): ?>
                                                <?php
                                                $done = $item['checked_in'] !== '';
                                                $face = isset($archerTargetFaces[$item['engagement_id']]) ? $archerTargetFaces[$item['engagement_id']] : null;
                                                $faceLabel = registrarc_target_face_label($face);
                                                $faceNotDefault = $faceLabel !== '' && empty($face['is_default']);
                                                $checkinTitle = $done
                                                    ? 'Passé au greffe le ' . date('d/m à H:i', strtotime($item['checked_in'])) . ($item['checked_in_by'] !== '' ? ' (' . $item['checked_in_by'] . ')' : '')
                                                    : 'Pas encore passé au greffe';
                                                ?>
                                                <div class="checkin">
                                                    <?php if ($canWrite): ?>
                                                        <button type="submit" form="checkinForm" name="toggle_checkin" value="<?php echo intval($item['engagement_id']); ?>" class="checkin-btn <?php echo $done ? 'is-done' : 'is-todo'; ?>" title="<?php echo htmlspecialchars($checkinTitle . ($done ? ' : cliquer pour annuler' : ' : cliquer pour le pointer')); ?>" aria-label="<?php echo htmlspecialchars($checkinTitle); ?>"><?php echo $done ? '✓' : '✕'; ?></button>
                                                    <?php else: ?>
                                                        <span class="checkin-btn <?php echo $done ? 'is-done' : 'is-todo'; ?>" title="<?php echo htmlspecialchars($checkinTitle); ?>"><?php echo $done ? '✓' : '✕'; ?></span>
                                                    <?php endif; ?>

                                                    <?php if ($done): ?>
                                                        <div class="checkin-info">
                                                            <div class="checkin-target<?php echo !empty($item['target_is_unassigned']) ? ' target-unassigned' : ''; ?>">
                                                                <?php echo !empty($item['target_is_unassigned']) ? htmlspecialchars($item['target_label']) : 'Cible ' . htmlspecialchars($item['target_label']); ?>
                                                            </div>
                                                            <?php if ($faceLabel !== ''): ?>
                                                                <!-- Blason, comme dans le kiosque : en rouge s'il n'est pas celui de la catégorie -->
                                                                <div class="checkin-face<?php echo $faceNotDefault ? ' is-not-default' : ''; ?>" title="<?php echo htmlspecialchars('Blason : ' . $faceLabel . ($faceNotDefault ? ' (blason par défaut de la catégorie : ' . $face['default_name'] . ')' : '')); ?>">
                                                                    <?php foreach ($face['faces'] as $faceImage): ?>
                                                                        <?php if ($faceImage['image'] !== ''): ?>
                                                                            <img src="<?php echo htmlspecialchars($faceImage['image']); ?>" alt="" width="20" height="20" loading="lazy">
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                    <span><?php echo htmlspecialchars($face['name'] !== '' ? $face['name'] . ' · ' . registrarc_target_face_sizes($face) : registrarc_target_face_sizes($face)); ?></span>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($displayArchers)): ?>
                            <tr>
                                <td colspan="<?php echo 5 + count($archerSessions); ?>" style="text-align:center;padding:20px;color:var(--muted);">Aucun archer trouvé.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-footer num">
            <div>Archers affichés : <strong><?php echo count($displayArchers); ?></strong></div>
        </div>
    <?php else: ?>
    <div class="table-wrapper">
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th class="col-check">
                            <input type="checkbox" onclick="toggleSelectAll(this)" title="Tout sélectionner">
                        </th>
                        <th><?php echo $sortLink('engagement', 'Engagement'); ?></th>
                        <th><?php echo $sortLink('licence', 'Licence'); ?></th>
                        <th><?php echo $sortLink('nom', 'Nom'); ?></th>
                        <th><?php echo $sortLink('prenom', 'Prénom'); ?></th>
                        <th><?php echo $sortLink('club', 'Club'); ?></th>
                        <th><?php echo $sortLink('categorie', 'Catégorie'); ?></th>
                        <th><?php echo $sortLink('depart', 'Départ'); ?></th>
                        <th><?php echo $sortLink('cible', 'Cible'); ?></th>
                        <th style="text-align:right;"><?php echo $sortLink('montant', 'Montant'); ?></th>
                        <th><?php echo $sortLink('statut', 'Statut'); ?></th>
                        <th><?php echo $sortLink('paiement', 'Paiement / Actions'); ?></th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($displayEngagements as $e): ?>
                        <?php
                        $state = $e['state'];
                        $archerLabel = trim($e['nom'] . ' ' . $e['prenom']);
                        $methodLabel = isset($paymentModes[$e['payment_method']]) ? $paymentModes[$e['payment_method']] : $e['payment_method'];
                        ?>
                        <tr>
                            <td class="col-check">
                                <input
                                    type="checkbox"
                                    name="engagements[]"
                                    value="<?php echo intval($e['engagement_id']); ?>"
                                    class="engagement-checkbox"
                                    form="bulkForm"
                                    onchange="updateBulkInvoiceButton();"
                                >
                            </td>

                            <td><span class="chip chip-engagement">n° <?php echo intval($e['numero_engagement']); ?></span></td>
                            <td class="num"><?php echo htmlspecialchars($e['licence']); ?></td>
                            <td>
                                <div class="row-name"><?php echo htmlspecialchars($e['nom']); ?></div>
                                <?php if ($e['note'] !== ''): ?>
                                    <div class="row-extra">
                                        <span class="row-note"><?php echo htmlspecialchars($e['note']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($e['prenom']); ?></td>
                            <td><?php echo htmlspecialchars(trim($e['country_code'] . ' - ' . $e['club'])); ?></td>
                            <td>
                                <?php echo $categoryHtml($e); ?>
                                <?php if (!empty($e['tarif_rule_label'])): ?>
                                    <span class="chip chip-rule" title="Prix spécial appliqué"><?php echo htmlspecialchars($e['tarif_rule_label']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ($e['session'] !== '') ? 'Départ ' . htmlspecialchars($e['session']) : '—'; ?></td>
                            <td>
                                <?php if (!empty($e['target_is_unassigned'])): ?>
                                    <span class="target-unassigned"><?php echo htmlspecialchars($e['target_label']); ?></span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['target_label']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="num" style="text-align:right;"><?php echo registrarc_format_montant($e['montant']); ?> €</td>
                            <td>
                                <span class="<?php echo $statusClasses[$state]; ?>"><?php echo registrarc_state_label($state); ?></span>
                            </td>
                            <td>
                                <div class="payment-actions">
                                    <?php if ($state === 'paid'): ?>
                                        <span class="payment-method-label"><?php echo htmlspecialchars($methodLabel ?: '—'); ?></span>

                                        <?php if (!empty($e['receipt_required'])): ?>
                                            <span class="payment-receipt-label">Reçu</span>
                                        <?php endif; ?>

                                        <?php if ($e['deposit_id'] !== ''): ?>
                                            <span class="payment-deposit-label">Déposé (<?php echo htmlspecialchars($e['deposit_id']); ?>)</span>
                                        <?php endif; ?>

                                        <?php if ($canWrite && $e['deposit_id'] === ''): ?>
                                            <form method="POST">
                                                <?php echo registrarc_form_fields($TourId); ?>
                                                <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                                                <input type="hidden" name="validate_payment" value="unvalidate">
                                                <button type="submit" class="btn-small btn-danger" onclick="return confirm(<?php echo htmlspecialchars(json_encode('Annuler le paiement de ' . $archerLabel . ' ? Son pointage au greffe sera aussi retiré.'), ENT_QUOTES); ?>);">Annuler</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($canWrite): ?>
                                            <button type="button" class="btn-small" data-id="<?php echo intval($e['engagement_id']); ?>" data-archer="<?php echo htmlspecialchars($archerLabel); ?>" data-amount="<?php echo htmlspecialchars(registrarc_format_montant($e['montant'])); ?>" onclick="openRefundDialog(this);">Rembourser</button>
                                        <?php endif; ?>
                                    <?php elseif ($state === 'refunded'): ?>
                                        <?php
                                        $refundMethod = isset($e['refund_method']) ? $e['refund_method'] : '';
                                        $refundLabel = isset($paymentModes[$refundMethod]) ? $paymentModes[$refundMethod] : $refundMethod;
                                        ?>
                                        <span class="status-refunded"><?php echo htmlspecialchars('Remboursé' . ($refundLabel !== '' ? ' · ' . $refundLabel : '')); ?></span>
                                        <?php if ($e['refund_reason'] !== ''): ?>
                                            <span class="refund-reason" title="Motif du remboursement"><?php echo htmlspecialchars($e['refund_reason']); ?></span>
                                        <?php endif; ?>

                                        <?php if ($canWrite && $e['deposit_id'] === ''): ?>
                                            <form method="POST">
                                                <?php echo registrarc_form_fields($TourId); ?>
                                                <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                                                <input type="hidden" name="validate_payment" value="unvalidate">
                                                <button type="submit" class="btn-small btn-danger" title="Annule le remboursement et supprime le paiement : l'engagement redevient non payé et n'est plus pointé au greffe" onclick="return confirm(<?php echo htmlspecialchars(json_encode('Supprimer le paiement et le remboursement de ' . $archerLabel . ' ?'), ENT_QUOTES); ?>);">Supprimer le paiement</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php elseif ($state === 'free'): ?>
                                        <span class="status-free">Rien à payer</span>
                                    <?php elseif ($canWrite): ?>
                                        <form method="POST">
                                            <?php echo registrarc_form_fields($TourId); ?>
                                            <input type="hidden" name="engagement_id" value="<?php echo intval($e['engagement_id']); ?>">
                                            <input type="hidden" name="validate_payment" value="validate">

                                            <select name="payment_method">
                                                <?php foreach ($paymentModes as $code => $label): ?>
                                                    <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button type="submit" class="btn-small btn-success" onclick="return confirm(<?php echo htmlspecialchars(json_encode('Valider le paiement de ' . $archerLabel . ' ?'), ENT_QUOTES); ?>);">Valider</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canWrite): ?>
                                        <button type="button" class="btn-small" title="Note visible par le greffe" data-id="<?php echo intval($e['engagement_id']); ?>" data-archer="<?php echo htmlspecialchars($archerLabel); ?>" data-note="<?php echo htmlspecialchars($e['note']); ?>" onclick="openNoteDialog(this);">✎</button>
                                    <?php endif; ?>

                                    <button type="button" class="btn-small" onclick="return factureEngagementPrompt('<?php echo intval($e['engagement_id']); ?>');">Facture</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if ($displayCount === 0): ?>
                        <tr>
                            <td class="col-check"></td>
                            <td colspan="11" style="text-align:center;padding:20px;color:var(--muted);">Aucun engagement trouvé.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>

                <?php if ($displayCount > 0): ?>
                    <tfoot>
                        <tr>
                            <td class="col-check"></td>
                            <td colspan="8" style="text-align:right;font-weight:600;">Total affiché :</td>
                            <td class="num" style="text-align:right;font-weight:600;"><?php echo registrarc_format_montant($total); ?> €</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <div class="summary-footer num">
        <div>Engagements affichés : <strong><?php echo intval($displayCount); ?></strong></div>
        <div>Montant total affiché : <strong><?php echo registrarc_format_montant($total); ?> €</strong></div>
    </div>
    <?php endif; ?>
    </div>
</div>

<?php if ($canWrite): ?>
<dialog class="rg-dialog" id="noteDialog">
    <form method="POST">
        <?php echo registrarc_form_fields($TourId); ?>
        <input type="hidden" name="save_archer_info" value="1">
        <input type="hidden" name="engagement_id" id="noteEngagementId" value="">
        <h3>Note</h3>
        <p class="dialog-sub" id="noteArcher"></p>
        <label>
            Note visible par le greffe
            <textarea name="note" id="noteText" rows="3" maxlength="200" placeholder="Ex. : paiera dimanche, chèque à l'ordre du club…"></textarea>
        </label>
        <div class="dialog-actions">
            <button type="button" class="btn-ghost" onclick="this.closest('dialog').close();">Annuler</button>
            <button type="submit" class="btn-primary">Enregistrer</button>
        </div>
    </form>
</dialog>

<dialog class="rg-dialog" id="refundDialog">
    <form method="POST">
        <?php echo registrarc_form_fields($TourId); ?>
        <input type="hidden" name="validate_payment" value="refund">
        <input type="hidden" name="engagement_id" id="refundEngagementId" value="">
        <h3>Rembourser</h3>
        <p class="dialog-sub" id="refundArcher"></p>
        <label>
            Remboursé en
            <select name="refund_method">
                <?php foreach ($paymentModes as $code => $label): ?>
                    <?php if ($code !== 'GRA'): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Motif du remboursement (obligatoire)
            <textarea name="refund_reason" id="refundReason" rows="2" maxlength="200" required placeholder="Ex. : blessure, désistement avant le concours, erreur d'inscription…"></textarea>
        </label>
        <div class="dialog-actions">
            <button type="button" class="btn-ghost" onclick="this.closest('dialog').close();">Annuler</button>
            <button type="submit" class="btn-danger">Enregistrer le remboursement</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script src="<?php echo registrarc_asset_url('assets/js/index.js'); ?>"></script>

<?php include('Common/Templates/tail.php'); ?>