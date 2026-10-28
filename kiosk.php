<?php
// ============================================================================
// RegistrArc - kiosk.php
// Interface mobile simplifiée pour validation des paiements
//
// Objectif :
// - page autonome sans menu Ianseo
// - rendu type application mobile
// - recherche et filtres dans l'entête sticky
// - validation groupée avec notion de reçu sauvegardée dans le JSON
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

// ---------------------------------------------------------------------------
// Infos tournoi
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$organizerClubCode = $tournament['organizer_code'];
$organizerClubName = $tournament['organizer_name'];
$tournamentName = $tournament['name'];

// ---------------------------------------------------------------------------
// Chargement données globales
// ---------------------------------------------------------------------------
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifs = registrarc_load_tarifs($TourId, $organizerClubCode, $organizerClubName);
$ageClasses = registrarc_load_age_classes($TourId);
$tarifRules = registrarc_load_tarif_rules($TourId);
$dataDirStatus = registrarc_data_dir_status();
$canWrite = registrarc_can_write($TourId);
$isClosed = registrarc_is_closed($TourId);

// ---------------------------------------------------------------------------
// POST
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        $_SESSION['RegistrArc_kiosk_message'] = $formError;
        $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        header('Location: kiosk.php');
        exit();
    }

    if (!$dataDirStatus['ok']) {
        $_SESSION['RegistrArc_kiosk_message'] = "Action impossible : le dossier JSON n'est pas accessible en écriture.";
        $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        header('Location: kiosk.php');
        exit();
    }

    if (isset($_POST['bulk_action']) && $_POST['bulk_action'] === 'validate' && !empty($_POST['engagements']) && is_array($_POST['engagements'])) {
        $method = isset($_POST['bulk_payment_method'])
            ? registrarc_clean_payment_method($_POST['bulk_payment_method'], $paymentModes)
            : 'ESP';

        $receiptRequired = !empty($_POST['bulk_receipt_required']);

        $createChequeGroupAfterPayment = !empty($_POST['create_cheque_group_after_payment'])
            && $_POST['create_cheque_group_after_payment'] === '1'
            && $method === 'CHQ';

        $ids = [];

        foreach ($_POST['engagements'] as $id) {
            $id = intval($id);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!empty($ids)) {
            $paymentResult = registrarc_record_payments($TourId, $ids, $method, $receiptRequired, 'kiosque');
            $saved = $paymentResult['ok'];
            $skippedMessage = registrarc_skipped_message($TourId, $paymentResult['skipped']);

            if ($saved) {
                if ($createChequeGroupAfterPayment && count($paymentResult['done']) >= 2) {
                    $result = registrarc_create_cheque_group($TourId, $paymentResult['done'], registrarc_load_payments($TourId));

                    if (!empty($result['ok'])) {
                        $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés et chèque groupé créé.';
                        $_SESSION['RegistrArc_kiosk_message_type'] = 'success';
                    } else {
                        $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés, mais le chèque groupé n’a pas pu être créé : ' . $result['message'];
                        $_SESSION['RegistrArc_kiosk_message_type'] = 'warning';
                    }
                } else {
                    $_SESSION['RegistrArc_kiosk_message'] = 'Paiements validés pour ' . count($paymentResult['done']) . ' engagement(s).';
                    $_SESSION['RegistrArc_kiosk_message_type'] = 'success';
                }

                if ($skippedMessage !== '') {
                    $_SESSION['RegistrArc_kiosk_message'] .= $skippedMessage;
                    $_SESSION['RegistrArc_kiosk_message_type'] = 'warning';
                }
            } else {
                $_SESSION['RegistrArc_kiosk_message'] = "Erreur lors de l'écriture du JSON paiements." . registrarc_storage_error_suffix();
                $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
            }
        } else {
            $_SESSION['RegistrArc_kiosk_message'] = 'Aucun engagement sélectionné.';
            $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
        }

        header('Location: kiosk.php');
        exit();
    }

    $_SESSION['RegistrArc_kiosk_message'] = 'Aucune action effectuée.';
    $_SESSION['RegistrArc_kiosk_message_type'] = 'error';
    header('Location: kiosk.php');
    exit();
}

// ---------------------------------------------------------------------------
// Chargement engagements (calcul commun à toutes les pages)
// ---------------------------------------------------------------------------
$rows = [];
$sessions = [];
$targetFaces = registrarc_load_target_faces($TourId);

foreach (registrarc_load_engagements($TourId) as $engagement) {
    if ($engagement['session'] !== '') {
        $sessions[$engagement['session']] = true;
    }

    $method = $engagement['payment_method'];
    $specialLabel = registrarc_engagement_special_label($engagement);

    $rows[] = [
        'engagement_id' => $engagement['engagement_id'],
        'numero_engagement' => $engagement['numero'],
        'licence' => $engagement['licence'],
        'nom' => $engagement['nom'],
        'prenom' => $engagement['prenom'],
        'categorie' => $engagement['categorie'],
        'age_class' => $engagement['age_class'],
        'class_code' => $engagement['class_code'],
        'is_upgraded' => $engagement['is_upgraded'],
        'club' => $engagement['club'],
        'country_code' => $engagement['country_code'],
        'session' => $engagement['session'],
        'target_label' => $engagement['target_label'],
        'target_is_unassigned' => $engagement['target_is_unassigned'],
        'payment_status' => $engagement['is_paid'] ? 'PAYE' : 'NON_PAYE',
        'state' => $engagement['state'],
        'payment_method' => $method,
        'payment_method_label' => ($method !== '' && isset($paymentModes[$method])) ? $paymentModes[$method] : $method,
        'receipt_required' => $engagement['receipt_required'],
        'amount_due' => $engagement['amount_due'],
        'tarif_label' => $specialLabel !== '' ? ($engagement['rule_count'] > 1 ? 'Cumul : ' : '') . $specialLabel : 'Standard',
        'has_special_tarif' => $specialLabel !== '',
        'is_free' => registrarc_nothing_due($engagement['amount_due']) || ($engagement['is_paid'] && $method === 'GRA'),
        'nothing_due' => registrarc_nothing_due($engagement['amount_due']),
        'note' => $engagement['note'],
        'target_face' => isset($targetFaces[$engagement['engagement_id']]) ? $targetFaces[$engagement['engagement_id']] : null,
    ];
}

// Tri final demandé : nom, prénom, numéro d'engagement
usort($rows, function($a, $b) {
    $nomCompare = strcmp(mb_strtolower((string)$a['nom']), mb_strtolower((string)$b['nom']));

    if ($nomCompare !== 0) {
        return $nomCompare;
    }

    $prenomCompare = strcmp(mb_strtolower((string)$a['prenom']), mb_strtolower((string)$b['prenom']));

    if ($prenomCompare !== 0) {
        return $prenomCompare;
    }

    return intval($a['numero_engagement']) <=> intval($b['numero_engagement']);
});

$sessions = array_keys($sessions);
sort($sessions, SORT_NUMERIC);

$message = '';
$messageType = 'success';

if (isset($_SESSION['RegistrArc_kiosk_message'])) {
    $message = $_SESSION['RegistrArc_kiosk_message'];
    $messageType = isset($_SESSION['RegistrArc_kiosk_message_type']) ? $_SESSION['RegistrArc_kiosk_message_type'] : 'success';
    unset($_SESSION['RegistrArc_kiosk_message'], $_SESSION['RegistrArc_kiosk_message_type']);
}

$paidRows = 0;
$freeRows = 0;
$unpaidRows = 0;
$receiptRows = 0;
$unpaidAmount = 0;

foreach ($rows as $r) {
    if ($r['payment_status'] === 'PAYE') {
        $paidRows++;
    } elseif (!empty($r['nothing_due'])) {
        $freeRows++;
    } else {
        $unpaidRows++;
        $unpaidAmount += (float)$r['amount_due'];
    }

    if (!empty($r['receipt_required'])) {
        $receiptRows++;
    }
}


?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>RegistrArc - Kiosk</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/kiosk.css'); ?>">
</head>

<body>
    <div class="app-shell">
        <header class="topbar">
            <div class="topbar-line">
                <div>
                    <div class="title">Encaissement</div>
                    <div class="subtitle"><?php echo htmlspecialchars($tournamentName ?: 'RegistrArc'); ?></div>
                </div>

                <span class="topbar-links">
                    <a href="aide.php#encaisser-sur-un-téléphone">Aide</a>
                    <a href="index.php?desktop=1">Version complète</a>
                </span>
            </div>

            <?php if ($canWrite): ?>
                <label class="operator">
                    Opérateur
                    <input type="text" id="operatorName" placeholder="Votre prénom" maxlength="40" autocomplete="off">
                </label>
            <?php endif; ?>

            <div class="summary num">
                <span class="is-unpaid"><strong><?php echo intval($unpaidRows); ?></strong> à encaisser</span>
                <span><strong><?php echo registrarc_format_montant($unpaidAmount); ?> €</strong> restant dû</span>
                <span class="is-paid"><strong><?php echo intval($paidRows); ?></strong> payé(s)</span>
                <?php if ($freeRows > 0): ?>
                    <span class="is-paid"><strong><?php echo intval($freeRows); ?></strong> gratuit(s)</span>
                <?php endif; ?>
                <span><strong><?php echo intval($receiptRows); ?></strong> reçu(s) demandé(s)</span>
            </div>
        </header>

        <div class="toolbar">
            <div class="search-input-wrap">
                <input type="text" id="kioskSearch" placeholder="Rechercher un nom, une licence, un club" oninput="filterCards();" autocomplete="off">
                <button type="button" class="search-clear" onclick="clearSearch();" aria-label="Effacer la recherche">×</button>
            </div>

            <div class="filters">
                <?php if (!empty($sessions)): ?>
                    <div class="segmented" role="group" aria-label="Départs">
                        <?php foreach ($sessions as $session): ?>
                            <label class="session-pill">
                                <input
                                    type="checkbox"
                                    class="session-filter"
                                    value="<?php echo htmlspecialchars($session); ?>"
                                    checked
                                    autocomplete="off"
                                    onchange="filterCards();"
                                >
                                Départ <?php echo htmlspecialchars($session); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <label class="check switch">
                    <input type="checkbox" id="showPaidCheckbox" autocomplete="off" onchange="filterCards();">
                    Afficher payés et gratuits
                </label>

                <div class="results">
                    <span class="results-count num"><span id="visibleCount"><?php echo count($rows); ?></span> affiché(s)</span>
                    <button type="button" id="selectAllBtn" class="select-all" onclick="toggleSelectAllVisible();">Tout sélectionner</button>
                </div>
            </div>
        </div>

        <main class="app-content">
            <?php if ($isClosed): ?>
                <div class="alert alert-info">Concours clôturé : consultation seulement.</div>
            <?php endif; ?>

            <?php if (!$dataDirStatus['ok']): ?>
                <div class="alert alert-warning">
                    <strong>Attention :</strong>
                    <?php echo htmlspecialchars($dataDirStatus['message']); ?>
                </div>
            <?php endif; ?>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <div class="list">
                <?php if (empty($rows)): ?>
                    <div class="empty-state">Aucun engagement dans ce tournoi.</div>
                <?php else: ?>
                    <div class="empty-state" id="noResults" hidden>Aucun archer ne correspond à la recherche ou aux filtres.</div>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <?php
                    $isPaid = ($row['state'] === 'paid');
                    // Payé, gratuit ou remboursé : rien à encaisser, masqué par défaut
                    $isSettled = ($row['state'] !== 'due');
                    $amountData = number_format((float)$row['amount_due'], 2, '.', '');
                    $faceLabel = registrarc_target_face_label($row['target_face']);
                    $searchText = mb_strtolower(
                        $faceLabel . ' ' .
                        $row['licence'] . ' ' .
                        $row['nom'] . ' ' .
                        $row['prenom'] . ' ' .
                        $row['club'] . ' ' .
                        $row['country_code'] . ' ' .
                        $row['categorie'] . ' ' .
                        (!empty($row['is_upgraded']) ? 'surclassé ' : '') .
                        $row['tarif_label']
                    );
                    // Départ et catégorie d'abord : le club, souvent long, est coupé en premier
                    // Départ, catégorie (en rouge si surclassé), club ; chaque morceau échappé
                    $categoryHtml = htmlspecialchars($row['categorie']);

                    if (!empty($row['is_upgraded'])) {
                        $categoryHtml = '<span class="is-upgraded" title="'
                            . htmlspecialchars('Surclassé : catégorie d\x27âge ' . $row['age_class'] . ', tire en ' . $row['class_code']) . '">'
                            . $categoryHtml . '</span>';
                    }

                    $meta = array_filter([
                        $row['session'] !== '' ? htmlspecialchars('Départ ' . $row['session']) : '',
                        $row['categorie'] !== '' ? $categoryHtml : '',
                        htmlspecialchars($row['club']),
                    ]);
                    ?>
                    <div
                        class="kiosk-card <?php echo $isSettled ? 'is-paid' : ''; ?>"
                        data-search="<?php echo htmlspecialchars($searchText); ?>"
                        data-session="<?php echo htmlspecialchars($row['session']); ?>"
                        data-paid="<?php echo $isSettled ? '1' : '0'; ?>"
                        data-amount="<?php echo htmlspecialchars($amountData); ?>"
                        onclick="toggleCardSelection(event, this);"
                    >
                        <input
                            type="checkbox"
                            name="engagements[]"
                            value="<?php echo intval($row['engagement_id']); ?>"
                            form="bulkForm"
                            class="bulk-checkbox row-check"
                            autocomplete="off"
                            aria-label="<?php echo htmlspecialchars('Sélectionner ' . trim($row['nom'] . ' ' . $row['prenom'])); ?>"
                            onchange="updateBulkSummary();"
                        >

                        <div class="row-target num <?php echo !empty($row['target_is_unassigned']) ? 'is-unassigned' : ''; ?>">
                            <?php echo !empty($row['target_is_unassigned']) ? '—' : htmlspecialchars($row['target_label']); ?>
                        </div>

                        <div class="row-main">
                            <div class="row-name">
                                <?php echo htmlspecialchars($row['nom']); ?>
                                <span><?php echo htmlspecialchars($row['prenom']); ?></span>
                            </div>

                            <div class="row-meta"><?php echo implode(' · ', $meta); ?></div>

                            <?php if (!empty($row['target_is_unassigned']) || !empty($row['receipt_required']) || !empty($row['has_special_tarif']) || $row['note'] !== ''): ?>
                                <div class="row-flags">
                                    <?php if (!empty($row['target_is_unassigned'])): ?>
                                        <span class="flag-warn">Sans cible</span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['receipt_required'])): ?>
                                        <span class="flag-receipt">Reçu demandé</span>
                                    <?php endif; ?>

                                    <?php if (!empty($row['has_special_tarif'])): ?>
                                        <span class="flag-tarif"><?php echo htmlspecialchars($row['tarif_label']); ?></span>
                                    <?php endif; ?>

                                    <?php if ($row['note'] !== ''): ?>
                                        <span class="flag-note"><?php echo htmlspecialchars($row['note']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Blason de l'archer (images de cibles d'I@nseo), entre le nom et le prix -->
                        <?php
                        // En rouge : blason différent du blason par défaut de la catégorie dans I@nseo
                        $faceNotDefault = $faceLabel !== '' && empty($row['target_face']['is_default']);
                        $faceTitle = $faceLabel === '' ? '' : 'Blason : ' . $faceLabel
                            . ($faceNotDefault ? ' (blason par défaut de la catégorie : ' . $row['target_face']['default_name'] . ')' : '');
                        ?>
                        <div class="row-face<?php echo $faceNotDefault ? ' is-not-default' : ''; ?>"<?php echo $faceTitle !== '' ? ' title="' . htmlspecialchars($faceTitle) . '"' : ''; ?>>
                            <?php if ($faceLabel !== ''): ?>
                                <div class="face-images">
                                    <?php foreach ($row['target_face']['faces'] as $face): ?>
                                        <?php if ($face['image'] !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($face['image']); ?>" alt="" width="26" height="26" loading="lazy">
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <div class="face-text">
                                    <?php if ($row['target_face']['name'] !== ''): ?>
                                        <div class="face-name"><?php echo htmlspecialchars($row['target_face']['name']); ?></div>
                                    <?php endif; ?>
                                    <div class="face-size num"><?php echo htmlspecialchars(registrarc_target_face_sizes($row['target_face'])); ?></div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="row-amount">
                            <div class="amount num">
                                <?php echo !empty($row['is_free']) ? 'Gratuit' : registrarc_format_montant($row['amount_due']) . ' €'; ?>
                            </div>
                            <div class="state <?php echo $isSettled ? 'state-paid' : 'state-unpaid'; ?>">
                                <?php
                                if ($isPaid) {
                                    echo htmlspecialchars('Payé' . ($row['payment_method_label'] !== '' ? ' · ' . $row['payment_method_label'] : ''));
                                } elseif ($row['state'] === 'free') {
                                    echo 'Rien à payer';
                                } elseif ($row['state'] === 'refunded') {
                                    echo 'Remboursé';
                                } else {
                                    echo 'À payer';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>

    <form method="POST" id="bulkForm" class="bottom-bulk-bar">
        <?php echo registrarc_form_fields($TourId); ?>
        <input type="hidden" name="create_cheque_group_after_payment" id="create_cheque_group_after_payment" value="0">

        <select name="bulk_payment_method" id="bulk_payment_method" class="sr-only" tabindex="-1" aria-hidden="true" autocomplete="off">
            <?php foreach ($paymentModes as $code => $label): ?>
                <option value="<?php echo htmlspecialchars($code); ?>">
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="bottom-bulk-inner">
            <div class="bulk-head num">
                <span><strong id="bulkCount">0</strong> sélectionné(s)</span>
                <span class="bulk-total">Total <strong id="bulkTotal">0 €</strong></span>
            </div>

            <div class="bulk-details">
                <div class="segmented pay-modes" role="group" aria-label="Mode de paiement">
                    <?php foreach ($paymentModes as $code => $label): ?>
                        <button type="button" class="pay-mode" data-code="<?php echo htmlspecialchars($code); ?>" onclick="selectPaymentMode(this);">
                            <?php echo htmlspecialchars($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="bulk-actions">
                    <label class="check pay-receipt">
                        <input type="checkbox" name="bulk_receipt_required" id="bulkReceiptRequired" value="1" autocomplete="off">
                        Reçu demandé
                    </label>

                    <?php if ($canWrite): ?>
                        <button type="submit" name="bulk_action" value="validate" id="bulkSubmit" class="btn-validate-wide" onclick="return confirmBulk();">
                            Valider
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn-validate-wide" disabled title="Droits en lecture seule">
                            Lecture seule
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>

    <script>const KIOSK_FILTERS_KEY = 'RegistrArcKioskFilters_<?php echo intval($TourId); ?>';</script>
    <script src="<?php echo registrarc_asset_url('assets/js/kiosk.js'); ?>"></script>
</body>
</html>