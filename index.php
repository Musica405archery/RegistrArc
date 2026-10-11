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

define('debug', false);

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once('Common/Fun_Various.inc.php');
require_once('Common/Fun_Sessions.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc.inc.php');

CheckTourSession(true);
checkACL(AclParticipants, AclReadOnly);

$ModuleName = 'RegistrArc';
$LegacyModuleName = 'Greffe2';

$TourId = isset($_SESSION['TourId']) ? intval($_SESSION['TourId']) : 0;

if (!$TourId) {
    die('Tournoi non défini');
}

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
];

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

        if ($value === '' || $value === 'all') {
            continue;
        }

        if ($key === 'search') {
            $params['search'] = $value;
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
$tournamentQuery = "
    SELECT ToCommitee, ToComDescr, ToName
    FROM Tournament
    WHERE ToId = $TourId
";
$tournamentRs = safe_r_sql($tournamentQuery);

$organizerClubCode = null;
$organizerClubName = null;
$tournamentName = null;
$tournamentCode = registrarc_current_tournament_code();

if ($t = safe_fetch($tournamentRs)) {
    $organizerClubCode = $t->ToCommitee;
    $organizerClubName = $t->ToComDescr;
    $tournamentName = $t->ToName;

    if (empty($organizerClubName) && $organizerClubCode) {
        $organizerNameQuery = "
            SELECT CoName
            FROM Countries
            WHERE CoCode = " . StrSafe_DB($organizerClubCode) . "
              AND CoTournament = $TourId
        ";
        $organizerNameRs = safe_r_sql($organizerNameQuery);

        if ($on = safe_fetch($organizerNameRs)) {
            $organizerClubName = $on->CoName;
        }
    }
}

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
            $reportPayment(registrarc_cancel_payments($TourId, $postedIds, 'liste'), 'Engagements sélectionnés marqués comme non payés.');
        } elseif ($action === 'refund') {
            $reportPayment(registrarc_refund_payments($TourId, $postedIds, $bulkMethod, 'liste'), 'Remboursement enregistré pour les engagements sélectionnés.');
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
                $reportPayment(registrarc_refund_payments($TourId, [$engagementId], $method, 'liste'), 'Remboursement enregistré.');
            } else {
                $reportPayment(registrarc_cancel_payments($TourId, [$engagementId], 'liste'), 'Cet engagement est maintenant marqué comme non payé.');
            }
        }

        $redirect();
    }

    if (isset($_POST['save_archer_info']) && isset($_POST['engagement_id'])) {
        $engagementId = intval($_POST['engagement_id']);
        $note = isset($_POST['note']) ? mb_substr(trim((string)$_POST['note']), 0, 200) : '';
        $late = !empty($_POST['late']);

        $saved = $engagementId > 0 && registrarc_update_archer_info($TourId, function (array &$info) use ($engagementId, $note, $late) {
            if ($note === '' && !$late) {
                unset($info[(string)$engagementId]);
            } else {
                $info[(string)$engagementId] = ['note' => $note, 'late' => $late, 'updated_at' => date('Y-m-d H:i:s')];
            }
        });

        $setMessage($saved ? 'Note enregistrée.' : "Erreur : impossible d'enregistrer la note." . registrarc_storage_error_suffix(), $saved ? 'success' : 'error');
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
        'club'                 => $engagement['club'],
        'country_code'         => $engagement['country_code'],
        'session'              => $engagement['session'],
        'target'               => $engagement['target'],
        'letter'               => $engagement['letter'],
        'target_no'            => $engagement['target_no'],
        'target_label'         => $engagement['target_label'],
        'target_is_unassigned' => $engagement['target_is_unassigned'],
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
        'late'                 => $engagement['late'],
        'deposit_id'           => $engagement['deposit_id'],
        'refund_method'        => isset($engagement['payment']['refund_method']) ? (string)$engagement['payment']['refund_method'] : '',
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
$searchFilter = mb_strtolower($filters['search']);

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

    if ($searchFilter !== '') {
        $haystack = mb_strtolower(
            $e['licence'] . ' ' .
            $e['nom'] . ' ' .
            $e['prenom'] . ' ' .
            $e['club'] . ' ' .
            $e['country_code'] . ' ' .
            $e['categorie']
        );

        if (strpos($haystack, $searchFilter) === false) {
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

<style>
    :root {
        --text: #1f2933;
        --muted: #6b7280;
        --line: #e3e7ee;
        --line-strong: #cfd6e1;
        --bg: #eef1f6;
        --surface: #ffffff;
        --accent: #14336b;
        --accent-soft: #e9effa;
        --on-accent: #ffffff;
        --on-accent-muted: #c9d5ec;
        --paid: #15803d;
        --paid-soft: #e8f5ec;
        --unpaid: #b91c1c;
        --unpaid-soft: #fdecec;
        --warn: #b45309;
        --warn-soft: #fdf3e2;
        --radius: 8px;
    }

    body {
        background: var(--bg);
    }

    .registrarc-container {
        max-width: 1480px;
        margin: 0 auto;
        padding: 0 16px 32px;
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        color: var(--text);
    }

    .num {
        font-variant-numeric: tabular-nums;
    }

    /* --- En-tête -------------------------------------------------------------- */
    .registrarc-header {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px 24px;
        background: var(--accent);
        color: var(--on-accent);
        margin: 0 -16px 16px;
        padding: 14px 16px;
    }

    .registrarc-header-title {
        font-size: 20px;
        font-weight: 700;
    }

    .registrarc-header-sub {
        color: var(--on-accent-muted);
        font-size: 13px;
        margin-top: 2px;
    }

    .header-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
    }

    .header-actions a {
        display: inline-flex;
        align-items: center;
        min-height: 34px;
        padding: 0 12px;
        border: 1px solid rgba(255, 255, 255, 0.35);
        border-radius: var(--radius);
        color: var(--on-accent);
        text-decoration: none;
        font-weight: 600;
        font-size: 13px;
    }

    .header-actions a:hover {
        background: rgba(255, 255, 255, 0.12);
    }

    .header-actions a.btn-update {
        background: var(--paid);
        border-color: var(--paid);
    }

    .header-actions a[hidden] {
        display: none;
    }

    /* --- Messages ------------------------------------------------------------- */
    .alert {
        padding: 10px 14px;
        border-radius: var(--radius);
        border: 1px solid;
        margin-bottom: 12px;
    }

    .alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
    .alert-error { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
    .alert-info { background: #eff6ff; border-color: #bfdbfe; color: #1e3a8a; }
    .alert-warning { background: #fffbeb; border-color: #fde68a; color: #78350f; }

    .storage-path {
        font-family: ui-monospace, Consolas, monospace;
        background: rgba(0, 0, 0, 0.06);
        padding: 1px 5px;
        border-radius: 4px;
    }

    /* --- Cartes et formulaires ------------------------------------------------ */
    .card {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: 0 1px 2px rgba(20, 51, 107, 0.12);
        padding: 14px 16px;
        margin-bottom: 14px;
    }

    .card-title {
        font-size: 15px;
        font-weight: 700;
        margin-bottom: 10px;
    }

    .filters-row {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: 10px 14px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .filter-group label {
        color: var(--muted);
        font-size: 12px;
    }

    .filter-group select,
    .filter-group input[type="text"] {
        height: 34px;
        padding: 0 10px;
        border: 1px solid var(--line-strong);
        border-radius: var(--radius);
        background: var(--surface);
        font: inherit;
        min-width: 150px;
    }

    .filter-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }

    .actions-spacer {
        flex: 1;
    }

    .import-input {
        font: inherit;
    }

    /* --- Boutons -------------------------------------------------------------- */
    .btn-primary,
    .btn-ghost,
    .btn-success,
    .btn-danger,
    .btn-invoice,
    .btn-export,
    .btn-import,
    .btn-cheque,
    .btn-print-list,
    .btn-small {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 34px;
        padding: 0 12px;
        border-radius: var(--radius);
        border: 1px solid var(--line-strong);
        background: var(--surface);
        color: var(--text);
        font: inherit;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        cursor: pointer;
    }

    .btn-primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
    .btn-success { background: var(--paid); border-color: var(--paid); color: #fff; }
    .btn-danger { color: var(--unpaid); border-color: #f3c4c4; }
    .btn-ghost:hover, .btn-invoice:hover, .btn-export:hover, .btn-import:hover, .btn-cheque:hover, .btn-print-list:hover, .btn-danger:hover { background: var(--accent-soft); }

    .btn-small {
        min-height: 28px;
        padding: 0 9px;
        font-size: 12px;
    }

    .btn-small.btn-success { background: var(--paid); border-color: var(--paid); color: #fff; }
    .btn-small.btn-danger { background: var(--surface); }

    .operator-field input {
        min-width: 0;
        width: 130px;
    }

    /* --- Tableau -------------------------------------------------------------- */
    .table-wrapper {
        background: var(--surface);
        border-radius: var(--radius);
        box-shadow: 0 1px 2px rgba(20, 51, 107, 0.12);
    }

    .table-responsive {
        overflow-x: auto;
    }

    .table-wrapper table {
        width: 100%;
        border-collapse: collapse;
    }

    .table-wrapper th {
        position: sticky;
        top: 0;
        background: #f6f8fb;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: var(--muted);
        padding: 9px 10px;
        border-bottom: 1px solid var(--line-strong);
        white-space: nowrap;
    }

    .table-wrapper th a {
        color: inherit;
        text-decoration: none;
    }

    .table-wrapper th a:hover {
        color: var(--accent);
    }

    .table-wrapper td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--line);
        vertical-align: top;
    }

    /* Engagement, licence, départ, cible, montant, statut, actions : sur une ligne */
    .table-wrapper td:nth-child(2),
    .table-wrapper td:nth-child(3),
    .table-wrapper td:nth-child(8),
    .table-wrapper td:nth-child(9),
    .table-wrapper td:nth-child(10),
    .table-wrapper td:nth-child(11) {
        white-space: nowrap;
    }

    .table-wrapper td:nth-child(6) {
        min-width: 170px;
    }

    .table-wrapper td:nth-child(7) {
        min-width: 160px;
    }

    .table-wrapper td:nth-child(12) {
        min-width: 290px;
    }

    .table-wrapper tbody tr:hover td {
        background: #fafbfd;
    }

    .table-wrapper tfoot td {
        border-bottom: none;
        background: #f6f8fb;
    }

    .col-check {
        width: 30px;
    }

    .col-check input {
        width: 17px;
        height: 17px;
        accent-color: var(--accent);
    }

    .chip {
        display: inline-block;
        padding: 1px 7px;
        border-radius: 999px;
        font-size: 12px;
        background: #f1f4f8;
        color: var(--muted);
        white-space: nowrap;
    }

    .chip-rule {
        background: var(--accent-soft);
        color: var(--accent);
        white-space: normal;
        border-radius: 8px;
        margin-top: 3px;
    }

    .row-extra {
        min-width: 140px;
    }

    .row-name {
        font-weight: 600;
    }

    .row-extra {
        margin-top: 3px;
        font-size: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 2px 10px;
    }

    .row-note { color: var(--muted); font-style: italic; }
    .row-late { color: var(--warn); }

    .target-unassigned {
        color: var(--unpaid);
    }

    .status-paid { color: var(--paid); font-weight: 600; }
    .status-unpaid { color: var(--unpaid); font-weight: 600; }
    .status-free { color: var(--paid); }
    .status-refunded { color: var(--muted); font-weight: 600; }

    .payment-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px;
    }

    .payment-actions form {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0;
    }

    .payment-actions select {
        height: 28px;
        padding: 0 6px;
        border: 1px solid var(--line-strong);
        border-radius: var(--radius);
        font: inherit;
        font-size: 12px;
    }

    .payment-method-label {
        font-weight: 600;
    }

    .payment-receipt-label,
    .payment-deposit-label {
        font-size: 12px;
        padding: 1px 6px;
        border-radius: 999px;
    }

    .payment-receipt-label { background: var(--warn-soft); color: var(--warn); }
    .payment-deposit-label { background: var(--accent-soft); color: var(--accent); }

    .summary-footer {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        color: var(--muted);
        padding: 10px 2px;
    }

    .summary-footer strong {
        color: var(--text);
    }

    /* --- Fenêtres (note, remboursement) --------------------------------------- */
    dialog.rg-dialog {
        border: none;
        border-radius: 10px;
        padding: 18px 20px;
        width: min(440px, calc(100vw - 32px));
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.3);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        color: var(--text);
    }

    dialog.rg-dialog::backdrop {
        background: rgba(15, 23, 42, 0.45);
    }

    .rg-dialog h3 {
        margin: 0 0 4px;
        font-size: 17px;
    }

    .rg-dialog .dialog-sub {
        color: var(--muted);
        margin: 0 0 14px;
    }

    .rg-dialog label {
        display: block;
        margin-bottom: 12px;
    }

    .rg-dialog textarea,
    .rg-dialog select {
        display: block;
        width: 100%;
        margin-top: 4px;
        padding: 8px 10px;
        border: 1px solid var(--line-strong);
        border-radius: var(--radius);
        font: inherit;
    }

    .rg-dialog .check-line {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .rg-dialog .dialog-actions {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        margin-top: 6px;
    }
</style>

<?php
// Lien de tri d'une colonne
$sortLink = function ($key, $label) use ($sortColumn, $sortDirection) {
    $dir = ($sortColumn == $key && $sortDirection == 'asc') ? 'desc' : 'asc';
    $arrow = $sortColumn == $key ? ($sortDirection == 'asc' ? ' ▲' : ' ▼') : '';

    return '<a href="?' . htmlspecialchars(http_build_query(array_merge($_GET, ['sort' => $key, 'dir' => $dir]))) . '">' . htmlspecialchars($label) . $arrow . '</a>';
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
            <a href="caisse.php">Caisse</a>
            <a href="kiosk.php">Mode Kiosk</a>
            <a href="config_tarifs.php">⚙ Paramètres</a>
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
                    <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="Licence, nom, prénom, club...">
                </div>

                <div class="filter-actions">
                    <button type="submit" name="go" value="1" class="btn-primary">Appliquer</button>
                    <button type="submit" name="reset" value="1" class="btn-ghost">Réinitialiser</button>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">Actions sur les engagements sélectionnés</div>

        <form method="POST" id="bulkForm">
            <?php echo registrarc_form_fields($TourId); ?>
            <input type="hidden" name="create_cheque_group_after_payment" id="create_cheque_group_after_payment" value="0">

            <div class="filters-row">
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

                <div class="filter-actions">
                    <?php if ($canWrite): ?>
                        <button type="submit" name="bulk_action" value="validate" class="btn-success" title="Valider le paiement des engagements cochés" onclick="return confirmBulkAction('validate');">Valider</button>
                        <button type="submit" name="bulk_action" value="unvalidate" class="btn-danger" title="Retirer le paiement des engagements cochés" onclick="return confirmBulkAction('unvalidate');">Retirer</button>
                        <button type="submit" name="bulk_action" value="refund" class="btn-ghost" onclick="return confirmBulkAction('refund');">Rembourser</button>
                    <?php endif; ?>

                    <button type="button" id="bulk_invoice_btn" class="btn-invoice bulk-invoice-button" onclick="return factureGroupePrompt();">Facture groupée</button>
                </div>

                <div class="filter-actions">
                    <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['action' => 'export_registrations']))); ?>" class="btn-export" title="Exporter les inscriptions affichées et leurs paiements">Exporter</a>

                    <?php if ($canWrite): ?>
                        <button type="button" class="btn-import" title="Importer les paiements d'un autre poste RegistrArc" onclick="toggleImportRegistrations();">Importer</button>
                    <?php endif; ?>

                    <button type="button" class="btn-cheque" onclick="return openChequeDeposit();">Remise de chèque</button>
                    <button type="button" class="btn-print-list" onclick="return printGreffeList();">Liste Greffe</button>
                </div>

                <div class="actions-spacer"></div>

                <?php if ($canWrite): ?>
                    <div class="filter-group operator-field">
                        <label for="operatorName">Opérateur</label>
                        <input type="text" id="operatorName" placeholder="Votre prénom" maxlength="40" autocomplete="off">
                    </div>
                <?php endif; ?>
            </div>

        </form>

        <?php if ($canWrite): ?>
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
                                <?php if ($e['note'] !== '' || !empty($e['late'])): ?>
                                    <div class="row-extra">
                                        <?php if (!empty($e['late'])): ?>
                                            <span class="row-late">Inscription tardive</span>
                                        <?php endif; ?>
                                        <?php if ($e['note'] !== ''): ?>
                                            <span class="row-note"><?php echo htmlspecialchars($e['note']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($e['prenom']); ?></td>
                            <td><?php echo htmlspecialchars(trim($e['country_code'] . ' - ' . $e['club'])); ?></td>
                            <td>
                                <?php echo htmlspecialchars($e['categorie']); ?>
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
                                                <button type="submit" class="btn-small btn-danger" onclick="return confirm(<?php echo htmlspecialchars(json_encode('Annuler le paiement de ' . $archerLabel . ' ?'), ENT_QUOTES); ?>);">Annuler</button>
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
                                        <button type="button" class="btn-small" title="Note et inscription tardive" data-id="<?php echo intval($e['engagement_id']); ?>" data-archer="<?php echo htmlspecialchars($archerLabel); ?>" data-note="<?php echo htmlspecialchars($e['note']); ?>" data-late="<?php echo !empty($e['late']) ? '1' : '0'; ?>" onclick="openNoteDialog(this);">✎</button>
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
        <label class="check-line">
            <input type="checkbox" name="late" id="noteLate" value="1">
            Inscription tardive (majoration prévue dans les paramètres)
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
        <div class="dialog-actions">
            <button type="button" class="btn-ghost" onclick="this.closest('dialog').close();">Annuler</button>
            <button type="submit" class="btn-danger">Enregistrer le remboursement</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<script>
function getSelectedEngagementIds() {
    const selected = document.querySelectorAll('.engagement-checkbox:checked');
    const uniqueValues = new Set();

    selected.forEach(cb => {
        if (cb.value !== '') {
            uniqueValues.add(cb.value);
        }
    });

    return Array.from(uniqueValues);
}

function updateBulkInvoiceButton() {
    const btn = document.getElementById('bulk_invoice_btn');

    if (!btn) {
        return;
    }

    const selectedIds = getSelectedEngagementIds();

    if (selectedIds.length >= 2) {
        btn.style.display = 'inline-flex';
        btn.textContent = 'Facture groupée (' + selectedIds.length + ')';
    } else {
        btn.style.display = 'none';
        btn.textContent = 'Facture groupée';
    }
}

function toggleSelectAll(master) {
    const checked = master.checked;

    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.checked = checked;
    });

    updateBulkInvoiceButton();
}

function confirmBulkAction(type) {
    const selectedIds = getSelectedEngagementIds();
    const count = selectedIds.length;
    const groupInput = document.getElementById('create_cheque_group_after_payment');
    const bulkMethod = document.getElementById('bulk_payment_method');

    if (groupInput) {
        groupInput.value = '0';
    }

    if (count === 0) {
        alert('Aucun engagement sélectionné.');
        return false;
    }

    const actionTexts = {
        validate: 'valider le paiement',
        unvalidate: 'retirer le paiement',
        refund: 'enregistrer le remboursement'
    };
    const actionText = actionTexts[type] || type;

    if (type !== 'validate') {
        return confirm(
            'Vous allez ' + actionText + ' pour ' + count + ' engagement(s).\n\nConfirmer ?'
        );
    }

    const method = bulkMethod ? String(bulkMethod.value || '').toUpperCase() : '';

    if (method === 'CHQ' && count >= 2) {
        const createGroup = confirm(
            'Vous allez valider ' + count + ' engagement(s) en paiement par chèque.\n\n' +
            'Créer un groupement de chèque pour ces engagements ?'
        );

        if (groupInput) {
            groupInput.value = createGroup ? '1' : '0';
        }

        return true;
    }

    return confirm(
        'Vous allez ' + actionText + ' pour ' + count + ' engagement(s).\n\nConfirmer ?'
    );
}

function openNoteDialog(btn) {
    document.getElementById('noteEngagementId').value = btn.dataset.id;
    document.getElementById('noteArcher').textContent = btn.dataset.archer;
    document.getElementById('noteText').value = btn.dataset.note || '';
    document.getElementById('noteLate').checked = btn.dataset.late === '1';
    document.getElementById('noteDialog').showModal();
}

function openRefundDialog(btn) {
    document.getElementById('refundEngagementId').value = btn.dataset.id;
    document.getElementById('refundArcher').textContent = btn.dataset.archer + ' · ' + btn.dataset.amount + ' €';
    document.getElementById('refundDialog').showModal();
}

// Opérateur : prénom mémorisé sur ce poste et joint à chaque envoi de formulaire
const OPERATOR_KEY = 'RegistrArcOperator';

function initOperator() {
    const input = document.getElementById('operatorName');
    let name = '';

    try {
        name = localStorage.getItem(OPERATOR_KEY) || '';
    } catch (e) {
        name = '';
    }

    if (input) {
        input.value = name;
        input.addEventListener('input', function () {
            try {
                localStorage.setItem(OPERATOR_KEY, input.value.trim());
            } catch (e) {
                // mémorisation impossible : sans conséquence
            }
        });
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;

        if (!form || String(form.method).toUpperCase() !== 'POST') {
            return;
        }

        let field = form.querySelector('input[name="operator"]');

        if (!field) {
            field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'operator';
            form.appendChild(field);
        }

        field.value = input ? input.value.trim() : name;
    }, true);
}

function factureEngagementPrompt(engagementId) {
    if (!engagementId) {
        alert('Aucun engagement sélectionné pour la facture.');
        return false;
    }

    window.open('invoice.php?ids=' + encodeURIComponent(engagementId), '_blank');
    return false;
}

function factureGroupePrompt() {
    const selectedIds = getSelectedEngagementIds();

    if (selectedIds.length < 2) {
        alert('Sélectionne au moins 2 engagements pour générer une facture groupée.');
        updateBulkInvoiceButton();
        return false;
    }

    window.open('invoice.php?ids=' + encodeURIComponent(selectedIds.join(',')), '_blank');
    return false;
}

function openChequeDeposit() {
    const selectedIds = getSelectedEngagementIds();
    let url = 'bankchequedeposite.php';

    if (selectedIds.length > 0) {
        url += '?ids=' + encodeURIComponent(selectedIds.join(','));
    }

    window.open(url, '_blank');
    return false;
}

function toggleImportRegistrations() {
    const form = document.getElementById('importRegistrationsForm');

    if (!form) {
        return false;
    }

    form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    return false;
}

function confirmImportRegistrations() {
    const input = document.getElementById('registrations_file');

    if (!input || !input.files || input.files.length === 0) {
        alert('Sélectionne un fichier JSON inscriptions.');
        return false;
    }

    return confirm(
        'Importer ce fichier d\'inscriptions ?\n\n' +
        'Les états de paiement correspondants seront appliqués aux inscriptions locales trouvées.\n' +
        'Aucune inscription I@nseo ne sera créée ou supprimée.'
    );
}

function printGreffeList() {
    const table = document.querySelector('.table-wrapper table');

    if (!table) {
        alert('Aucune liste à imprimer.');
        return false;
    }

    const clonedTable = table.cloneNode(true);

    clonedTable.querySelectorAll('tfoot').forEach(tfoot => {
        tfoot.remove();
    });

    clonedTable.querySelectorAll('tr').forEach(row => {
        let cells = row.querySelectorAll('th, td');

        if (cells.length > 0) {
            cells[0].remove();
        }

        cells = row.querySelectorAll('th, td');

        if (cells.length > 0) {
            cells[cells.length - 1].remove();
        }

        cells = row.querySelectorAll('th, td');

        if (cells.length >= 8) {
            const departCell = cells[6];
            const cibleCell = cells[7];

            if (departCell && cibleCell) {
                const departText = departCell.textContent.trim();
                const cibleText = cibleCell.textContent.trim();

                if (departText !== '' && departText !== '—' && cibleText !== '') {
                    cibleCell.textContent = departText + ' - ' + cibleText;
                } else if (cibleText !== '') {
                    cibleCell.textContent = cibleText;
                } else {
                    cibleCell.textContent = departText;
                }
            }

            cells[6].remove();
        }

        cells = row.querySelectorAll('th, td');

        if (cells.length >= 10) {
            cells[8].remove();
        }
    });

    clonedTable.querySelectorAll('thead tr').forEach(row => {
        const cells = row.querySelectorAll('th');

        if (cells.length > 0) {
            cells[0].textContent = 'Eng.';
        }

        if (cells.length > 6) {
            cells[6].textContent = 'Départ - Cible';
        }

        const paiementTh = document.createElement('th');
        paiementTh.textContent = 'Paiement';
        row.appendChild(paiementTh);

        const recuTh = document.createElement('th');
        recuTh.textContent = 'Reçu';
        row.appendChild(recuTh);
    });

    clonedTable.querySelectorAll('tbody tr').forEach(row => {
        const cells = row.querySelectorAll('td');

        if (cells.length <= 1) {
            return;
        }

        const paiementTd = document.createElement('td');
        paiementTd.className = 'manual-payment';
        paiementTd.innerHTML = '<span class="manual-line"></span>';
        row.appendChild(paiementTd);

        const recuTd = document.createElement('td');
        recuTd.className = 'manual-recu';
        recuTd.innerHTML = '<span class="manual-checkbox"></span>';
        row.appendChild(recuTd);
    });

    clonedTable.querySelectorAll('a').forEach(link => {
        const span = document.createElement('span');
        span.textContent = link.textContent.replace(/[▲▼]/g, '').trim();
        link.replaceWith(span);
    });

    clonedTable.querySelectorAll('button, input, select, form').forEach(el => {
        el.remove();
    });

    const tbody = clonedTable.querySelector('tbody');

    if (tbody) {
        let currentLetter = '';

        Array.from(tbody.querySelectorAll('tr')).forEach(row => {
            const cells = row.querySelectorAll('td');

            if (cells.length <= 1) {
                return;
            }

            const nomCell = cells[2];

            if (!nomCell) {
                return;
            }

            const rawName = nomCell.textContent.trim();

            if (rawName === '') {
                return;
            }

            let firstLetter = rawName.charAt(0).toUpperCase();

            if (firstLetter.normalize) {
                firstLetter = firstLetter
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '');
            }

            if (!/^[A-Z]$/.test(firstLetter)) {
                firstLetter = '#';
            }

            if (firstLetter !== currentLetter) {
                currentLetter = firstLetter;

                const letterRow = document.createElement('tr');
                letterRow.className = 'letter-separator-row';

                const letterCell = document.createElement('td');
                letterCell.colSpan = cells.length;
                letterCell.textContent = currentLetter;

                letterRow.appendChild(letterCell);
                tbody.insertBefore(letterRow, row);
            }
        });
    }

    const printWindow = window.open('', '_blank', 'width=1200,height=900');

    if (!printWindow) {
        alert('La fenêtre d\'impression a été bloquée par le navigateur.');
        return false;
    }

    const title = "Liste Greffe - Registr'Arc";

    printWindow.document.open();
    printWindow.document.write(`
        <!DOCTYPE html>
        <html lang="fr">
        <head>
            <meta charset="UTF-8">
            <title>${title}</title>
            <style>
                @page {
                    size: landscape;
                    margin: 5mm;
                }

                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    padding: 0;
                    font-family: Arial, Helvetica, sans-serif;
                    color: #000;
                    background: #fff;
                }

                .print-title {
                    font-size: 13px;
                    font-weight: 700;
                    margin-bottom: 2px;
                }

                .print-subtitle {
                    font-size: 8px;
                    margin-bottom: 5px;
                    color: #333;
                }

                table {
                    width: 100%;
                    border-collapse: collapse;
                    table-layout: fixed;
                    font-size: 7px;
                }

                thead {
                    display: table-header-group;
                }

                th,
                td {
                    border: 1px solid #999;
                    border-bottom: 1.5px solid #555;
                    padding: 2px 2px;
                    text-align: left;
                    vertical-align: top;
                    line-height: 1.1;
                    word-break: break-word;
                    overflow-wrap: anywhere;
                }

                tbody tr {
                    border-bottom: 2px solid #333;
                }

                tbody tr:nth-child(even) {
                    background: #f7f7f7;
                }

                th {
                    background: #e5e5e5;
                    font-weight: 700;
                    border-bottom: 2px solid #333;
                }

                .letter-separator-row td {
                    background: #111827 !important;
                    color: #fff;
                    font-size: 10px;
                    font-weight: 800;
                    text-align: left;
                    padding: 3px 2px;
                    padding-left: 5px;
                    border: 1px solid #111827;
                    letter-spacing: 0.08em;
                }

                th:nth-child(1),
                td:nth-child(1) {
                    width: 3.5%;
                    text-align: center;
                    white-space: nowrap;
                    word-break: normal;
                    overflow-wrap: normal;
                    padding-left: 1px;
                    padding-right: 1px;
                }

                th:nth-child(2),
                td:nth-child(2) {
                    width: 8%;
                }

                th:nth-child(3),
                td:nth-child(3) {
                    width: 10%;
                }

                th:nth-child(4),
                td:nth-child(4) {
                    width: 9%;
                }

                th:nth-child(5),
                td:nth-child(5) {
                    width: 19%;
                }

                th:nth-child(6),
                td:nth-child(6) {
                    width: 7%;
                }

                th:nth-child(7),
                td:nth-child(7) {
                    width: 12%;
                }

                th:nth-child(8),
                td:nth-child(8) {
                    width: 6%;
                    text-align: right;
                    white-space: nowrap;
                }

                th:nth-child(9),
                td:nth-child(9) {
                    width: 7%;
                }

                th:nth-child(10),
                td:nth-child(10) {
                    width: 13%;
                }

                th:nth-child(11),
                td:nth-child(11) {
                    width: 5.5%;
                    text-align: center;
                }

                .status-paid,
                .status-unpaid,
                .target-unassigned {
                    color: #000;
                    font-weight: 700;
                }

                .chip,
                .chip-engagement,
                .payment-method-label,
                .payment-receipt-label {
                    display: inline;
                    padding: 0;
                    background: transparent;
                    color: #000;
                    font-size: inherit;
                    font-weight: inherit;
                }

                .manual-payment {
                    background: #fff;
                }

                .manual-line {
                    display: block;
                    height: 10px;
                    border-bottom: 1px solid #333;
                }

                .manual-recu {
                    text-align: center;
                    background: #fff;
                }

                .manual-checkbox {
                    display: inline-block;
                    width: 10px;
                    height: 10px;
                    border: 1.5px solid #000;
                    vertical-align: middle;
                }

                .print-footer {
                    margin-top: 4px;
                    font-size: 7px;
                    color: #333;
                }
            </style>
        </head>
        <body>
            <div class="print-title">Gestion des engagements - Registr'Arc</div>
            <div class="print-subtitle">Liste Greffe</div>

            ${clonedTable.outerHTML}

            <div class="print-footer">
                Document généré depuis Registr'Arc.
            </div>

            <script>
                window.onload = function() {
                    window.focus();
                    window.print();
                };
            <\/script>
        </body>
        </html>
    `);

    printWindow.document.close();

    return false;
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.engagement-checkbox').forEach(cb => {
        cb.addEventListener('change', updateBulkInvoiceButton);
    });

    updateBulkInvoiceButton();
    checkModuleUpdate();
    initOperator();
});

// Bouton "Mise à jour" affiché seulement si une version plus récente existe
function checkModuleUpdate() {
    const btn = document.getElementById('registrarcUpdateBtn');

    if (!btn || !window.fetch) {
        return;
    }

    fetch('update.php?action=check', { credentials: 'same-origin' })
        .then(response => response.ok ? response.json() : null)
        .then(data => {
            if (data && data.update) {
                btn.textContent = '⬆ Mise à jour ' + data.remote;
                btn.title = 'Version installée : ' + data.local;
                btn.hidden = false;
            }
        })
        .catch(() => {});
}
</script>

<?php include('Common/Templates/tail.php'); ?>