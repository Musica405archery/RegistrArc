<?php
// ============================================================================
// RegistrArc - bankchequedeposite.php
// Remise de chèque
//
// Cas possibles :
// - bankchequedeposite.php
//   Tous les groupes de chèques, puis les paiements CHQ non groupés.
// - bankchequedeposite.php?ids=123,124
//   Paiements sélectionnés PAYE en CHQ.
// - bankchequedeposite.php?group=CG-...
//   Groupe de chèque enregistré dans data/cheque_groups_<TourId>.json.
//
// Paiements lus depuis : data/payments_<TourId>.json
// Groupes lus depuis   : data/cheque_groups_<TourId>.json
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

// ---------------------------------------------------------------------------
// Lecture paramètres
// ---------------------------------------------------------------------------
$groupId = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
$idsParam = isset($_GET['ids']) ? trim((string)$_GET['ids']) : '';

$selectedIds = [];

if ($idsParam !== '') {
    foreach (explode(',', $idsParam) as $id) {
        $id = intval(trim($id));

        if ($id > 0) {
            $selectedIds[] = $id;
        }
    }

    $selectedIds = array_values(array_unique($selectedIds));
}

$depositParam = isset($_GET['remise']) ? trim((string)$_GET['remise']) : '';

$isDepositMode = ($depositParam !== '');
$isGroupMode = !$isDepositMode && ($groupId !== '');
$isSelectionMode = !$isDepositMode && !$isGroupMode && !empty($selectedIds);
$isAllMode = !$isDepositMode && !$isGroupMode && !$isSelectionMode;
$canWrite = registrarc_can_write($TourId);

// ---------------------------------------------------------------------------
// Engagements
// ---------------------------------------------------------------------------
// Chèques : engagements payés par chèque (calcul commun à toutes les pages)
// $selectedIds vide = tous les engagements
// ---------------------------------------------------------------------------
function registrarc_cheque_rows($TourId, array $selectedIds, array $paymentModes, $includeDeposited = false) {
    $wanted = empty($selectedIds) ? null : array_flip(array_map('intval', $selectedIds));
    $rows = [];

    foreach (registrarc_load_engagements($TourId) as $id => $e) {
        if ($wanted !== null && !isset($wanted[$id])) {
            continue;
        }

        if (!$e['is_paid'] || $e['payment_method'] !== 'CHQ') {
            continue;
        }

        // Un chèque déjà déposé à la banque ne figure plus sur les bordereaux à préparer
        if (!$includeDeposited && $e['deposit_id'] !== '') {
            continue;
        }

        $specialLabel = registrarc_engagement_special_label($e);

        $rows[] = [
            'engagement_id' => $id,
            'licence' => $e['licence'],
            'nom' => $e['nom'],
            'prenom' => $e['prenom'],
            'categorie' => $e['categorie'],
            'club' => $e['club'],
            'country_code' => $e['country_code'],
            'session_no' => $e['session'],
            'target' => $e['target'],
            'letter' => $e['letter'],
            'numero_engagement' => $e['numero'],
            'session_label' => $e['session'] !== '' ? 'Départ ' . $e['session'] : '—',
            'target_label' => $e['target_label'],
            'payment_method' => 'CHQ',
            'payment_method_label' => isset($paymentModes['CHQ']) ? $paymentModes['CHQ'] : 'CHQ',
            'deposit_id' => $e['deposit_id'],
            'tarif_rule_label' => $specialLabel,
            'tarif_type_label' => $specialLabel !== '' ? ($e['rule_count'] > 1 ? 'Cumul : ' : '') . $specialLabel : 'Standard',
            'tarif_type_class' => $specialLabel !== '' ? 'tarif-rule' : 'tarif-standard',
            'amount' => $e['amount'],
        ];
    }

    // Ordre du bordereau : club, nom, prénom, départ
    usort($rows, function ($a, $b) {
        return [$a['club'], $a['nom'], $a['prenom'], $a['session_no'], $a['target'], $a['letter'], $a['engagement_id']]
            <=> [$b['club'], $b['nom'], $b['prenom'], $b['session_no'], $b['target'], $b['letter'], $b['engagement_id']];
    });

    return $rows;
}

function registrarc_sum_rows(array $rows) {
    $total = 0;

    foreach ($rows as $row) {
        $total += isset($row['amount']) ? floatval($row['amount']) : 0;
    }

    return $total;
}

function registrarc_get_group_ids_map(array $groups) {
    $map = [];

    foreach ($groups as $group) {
        if (!is_array($group)) {
            continue;
        }

        $ids = isset($group['engagement_ids']) && is_array($group['engagement_ids'])
            ? $group['engagement_ids']
            : [];

        foreach ($ids as $id) {
            $id = intval($id);

            if ($id > 0) {
                $map[$id] = true;
            }
        }
    }

    return $map;
}

function registrarc_filter_rows_not_in_map(array $rows, array $groupedIdsMap) {
    $out = [];

    foreach ($rows as $row) {
        $id = isset($row['engagement_id']) ? intval($row['engagement_id']) : 0;

        if ($id > 0 && isset($groupedIdsMap[$id])) {
            continue;
        }

        $out[] = $row;
    }

    return $out;
}

function registrarc_render_entries_table(array $rows) {
    ?>
    <table>
        <thead>
            <tr>
                <th style="width:7%;">Eng.</th>
                <th style="width:11%;">Licence</th>
                <th style="width:17%;">Archer</th>
                <th style="width:22%;">Club</th>
                <th style="width:8%;">Cat.</th>
                <th style="width:8%;">Départ</th>
                <th style="width:8%;">Cible</th>
                <th style="width:8%;" class="right">Montant</th>
                <th style="width:11%;">Tarif</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr class="entry-row">
                    <td class="center">n° <?php echo intval($row['numero_engagement']); ?></td>
                    <td><?php echo htmlspecialchars($row['licence']); ?></td>
                    <td><?php echo htmlspecialchars(trim($row['nom'] . ' ' . $row['prenom'])); ?></td>
                    <td><?php echo htmlspecialchars(trim($row['country_code'] . ' - ' . $row['club'])); ?></td>
                    <td><?php echo htmlspecialchars($row['categorie']); ?></td>
                    <td><?php echo htmlspecialchars($row['session_label']); ?></td>
                    <td><?php echo htmlspecialchars($row['target_label']); ?></td>
                    <td class="right"><?php echo registrarc_format_montant($row['amount']); ?> €</td>
                    <td>
                        <span class="<?php echo htmlspecialchars($row['tarif_type_class']); ?>">
                            <?php echo htmlspecialchars($row['tarif_type_label']); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

function registrarc_render_manual_fields() {
    ?>
    <div class="manual-fields">
        <div class="manual-field">
            <div class="manual-label">N° chèque</div>
            <div class="manual-write-line"></div>
        </div>

        <div class="manual-field">
            <div class="manual-label">Banque émettrice</div>
            <div class="manual-write-line"></div>
        </div>
    </div>
    <?php
}

function registrarc_group_created_label(array $group) {
    if (empty($group['created_at'])) {
        return '';
    }

    $ts = strtotime($group['created_at']);

    if (!$ts) {
        return trim((string)$group['created_at']);
    }

    return date('d/m/Y H:i', $ts);
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$payments = registrarc_load_payments($TourId);
$paymentModes = registrarc_load_payment_modes($TourId);
$tarifs = registrarc_load_tarifs($TourId, $tournament['organizer_code'], $tournament['organizer_name']);
$tarifRules = registrarc_load_tarif_rules($TourId);
$ageClasses = registrarc_load_age_classes($TourId);
$chequeGroups = registrarc_load_cheque_groups($TourId);

$groupData = null;
$groupBlocks = [];
$singleRows = [];
$deposits = registrarc_load_deposits($TourId);
$depositData = null;

if ($isDepositMode) {
    if (isset($deposits[$depositParam]) && is_array($deposits[$depositParam])) {
        $depositData = $deposits[$depositParam];

        foreach ($depositData['cheques'] as $index => $cheque) {
            $rows = registrarc_cheque_rows($TourId, $cheque['engagement_ids'], $paymentModes, true);

            if (count($cheque['engagement_ids']) > 1) {
                $groupBlocks[] = [
                    'id' => $cheque['group_id'] !== '' ? $cheque['group_id'] : 'n° ' . ($index + 1),
                    'data' => ['created_at' => $depositData['created_at']],
                    'rows' => $rows,
                    'total' => registrarc_sum_rows($rows),
                ];
            } else {
                $singleRows = array_merge($singleRows, $rows);
            }
        }
    }
} elseif ($isGroupMode) {
    if (isset($chequeGroups[$groupId]) && is_array($chequeGroups[$groupId])) {
        $groupData = $chequeGroups[$groupId];
        $selectedIds = isset($groupData['engagement_ids']) && is_array($groupData['engagement_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $groupData['engagement_ids']))))
            : [];

        $rows = registrarc_cheque_rows($TourId, $selectedIds, $paymentModes);

        $groupBlocks[] = [
            'id' => $groupId,
            'data' => $groupData,
            'rows' => $rows,
            'total' => registrarc_sum_rows($rows),
        ];
    }
} elseif ($isSelectionMode) {
    $singleRows = registrarc_cheque_rows($TourId, $selectedIds, $paymentModes);
} else {
    foreach ($chequeGroups as $id => $group) {
        if (!is_array($group)) {
            continue;
        }

        $ids = isset($group['engagement_ids']) && is_array($group['engagement_ids'])
            ? array_values(array_unique(array_filter(array_map('intval', $group['engagement_ids']))))
            : [];

        if (empty($ids)) {
            continue;
        }

        $rows = registrarc_cheque_rows($TourId, $ids, $paymentModes);

        if (empty($rows)) {
            continue;
        }

        $groupBlocks[] = [
            'id' => $id,
            'data' => $group,
            'rows' => $rows,
            'total' => registrarc_sum_rows($rows),
        ];
    }

    $groupedIdsMap = registrarc_get_group_ids_map($chequeGroups);
    $allChequeRows = registrarc_cheque_rows($TourId, [], $paymentModes);

    $singleRows = registrarc_filter_rows_not_in_map($allChequeRows, $groupedIdsMap);
}

$total = 0;
$countEntries = 0;
$countCheques = 0;

foreach ($groupBlocks as $block) {
    $total += $block['total'];
    $countEntries += count($block['rows']);
    $countCheques++;
}

foreach ($singleRows as $row) {
    $total += $row['amount'];
    $countEntries++;
    $countCheques++;
}

$documentDate = date('d/m/Y');

if ($depositData) {
    $documentDate = date('d/m/Y', strtotime($depositData['created_at']));
}

// ---------------------------------------------------------------------------
// Enregistrer ou annuler une remise
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);
    $redirect = 'bankchequedeposite.php';

    if ($formError !== '') {
        $_SESSION['RegistrArc_deposit_message'] = $formError;
    } elseif (isset($_POST['deposit_create']) && !$isDepositMode) {
        // Chèques du périmètre affiché : un par groupe, un par paiement individuel
        $cheques = [];

        foreach ($groupBlocks as $block) {
            $cheques[] = ['group_id' => (string)$block['id'], 'engagement_ids' => array_column($block['rows'], 'engagement_id'), 'amount' => $block['total']];
        }

        foreach ($singleRows as $row) {
            $cheques[] = ['group_id' => '', 'engagement_ids' => [$row['engagement_id']], 'amount' => $row['amount']];
        }

        $newDeposit = registrarc_create_deposit($TourId, $cheques);

        if ($newDeposit !== '') {
            $_SESSION['RegistrArc_deposit_message'] = 'Remise ' . $newDeposit . ' enregistrée comme déposée. Ses chèques ne figureront plus sur les prochains bordereaux.';
            $redirect = 'bankchequedeposite.php?remise=' . rawurlencode($newDeposit);
        } else {
            $_SESSION['RegistrArc_deposit_message'] = "Aucun chèque à déposer n'a pu être enregistré." . registrarc_storage_error_suffix();
        }
    } elseif (isset($_POST['deposit_cancel'])) {
        $cancelId = (string)$_POST['deposit_cancel'];
        $_SESSION['RegistrArc_deposit_message'] = registrarc_cancel_deposit($TourId, $cancelId)
            ? 'Remise ' . $cancelId . ' annulée : ses chèques sont de nouveau à déposer.'
            : 'Remise introuvable.';
    }

    header('Location: ' . $redirect);
    exit();
}

$depositMessage = '';

if (isset($_SESSION['RegistrArc_deposit_message'])) {
    $depositMessage = $_SESSION['RegistrArc_deposit_message'];
    unset($_SESSION['RegistrArc_deposit_message']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Remise de chèque - RegistrArc</title>

    <link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/bankchequedeposite.css'); ?>">
</head>

<body>
    <?php if ($depositMessage !== ''): ?>
        <div class="notice no-print"><?php echo htmlspecialchars($depositMessage); ?></div>
    <?php endif; ?>

    <div class="page">
        <div class="header">
            <div>
                <div class="title">Remise de chèque<?php echo $depositData ? ' ' . htmlspecialchars($depositData['id']) : ''; ?></div>
                <div class="subtitle">
                    <?php if ($depositData): ?>
                        Enregistrée comme déposée le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($depositData['created_at']))); ?><?php echo !empty($depositData['operator']) ? ' par ' . htmlspecialchars($depositData['operator']) : ''; ?>
                    <?php else: ?>
                        Document interne - RegistrArc
                    <?php endif; ?>
                </div>
            </div>

            <div class="meta">
                <strong>Date :</strong> <?php echo htmlspecialchars($documentDate); ?>
            </div>
        </div>

        <div class="summary">
            <div class="summary-item">
                Nombre de chèques : <?php echo intval($countCheques); ?>
            </div>

            <div class="summary-item">
                Nombre d'engagements : <?php echo intval($countEntries); ?>
            </div>

            <div class="summary-item">
                Total remise : <?php echo registrarc_format_montant($total); ?> €
            </div>
        </div>

        <?php if ($isDepositMode && !$depositData): ?>
            <div class="empty">
                Remise introuvable.
            </div>
        <?php elseif ($isGroupMode && !$groupData): ?>
            <div class="empty">
                Groupe de chèque introuvable.
            </div>
        <?php elseif ($countEntries === 0): ?>
            <div class="empty">
                Aucun paiement par chèque trouvé pour ce périmètre.
            </div>
        <?php else: ?>

            <?php if (!empty($groupBlocks)): ?>
                <?php if ($isAllMode): ?>
                    <div class="section-title">Groupes de chèques</div>
                <?php endif; ?>

                <?php foreach ($groupBlocks as $block): ?>
                    <?php
                    $createdLabel = registrarc_group_created_label($block['data']);
                    $entriesCount = count($block['rows']);
                    ?>
                    <div class="group-block">
                        <div class="group-title">
                            Chèque groupé <?php echo htmlspecialchars($block['id']); ?>
                        </div>

                        <?php if ($createdLabel !== ''): ?>
                            <div class="group-subtitle">
                                Créé le <?php echo htmlspecialchars($createdLabel); ?> ·
                                <?php echo intval($entriesCount); ?> engagement(s)
                            </div>
                        <?php else: ?>
                            <div class="group-subtitle">
                                <?php echo intval($entriesCount); ?> engagement(s)
                            </div>
                        <?php endif; ?>

                        <?php registrarc_render_entries_table($block['rows']); ?>

                        <div class="total-line">
                            Total du chèque : <?php echo registrarc_format_montant($block['total']); ?> €
                        </div>

                        <?php registrarc_render_manual_fields(); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($singleRows)): ?>
                <?php if ($isAllMode): ?>
                    <div class="section-title">Chèque paiement individuel</div>
                <?php endif; ?>

                <?php foreach ($singleRows as $row): ?>
                    <div class="single-entry-card">
                        <?php registrarc_render_entries_table([$row]); ?>

                        <div class="single-entry-manual">
                            <div class="total-line">
                                Montant du chèque : <?php echo registrarc_format_montant($row['amount']); ?> €
                            </div>

                            <?php registrarc_render_manual_fields(); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

        <?php endif; ?>

        <?php if ($countEntries > 0): ?>
            <div class="signature-grid">
                <div class="signature-box">
                    <div class="signature-title">Préparé par</div>
                    <div class="signature-line"></div>
                </div>

                <div class="signature-box">
                    <div class="signature-title">Déposé le</div>
                    <div class="signature-line"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="closeDepositTab();">
            Retour
        </button>

        <button type="button" class="btn btn-primary" onclick="window.print();">
            Imprimer / Enregistrer en PDF
        </button>

        <?php if ($canWrite && !$isDepositMode && $countCheques > 0): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Enregistrer ces ' . $countCheques . ' chèque(s) (' . registrarc_format_montant($total) . ' €) comme déposés à la banque ?'), ENT_QUOTES); ?>);">
                <?php echo registrarc_form_fields($TourId); ?>
                <button type="submit" name="deposit_create" value="1" class="btn btn-success">Enregistrer comme déposée</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($deposits)): ?>
        <div class="history no-print">
            <h2>Remises déjà déposées</h2>
            <table>
                <thead>
                    <tr>
                        <th>Remise</th>
                        <th>Date</th>
                        <th>Opérateur</th>
                        <th class="right">Chèques</th>
                        <th class="right">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($deposits, true) as $deposit): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($deposit['id']); ?></strong></td>
                            <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($deposit['created_at']))); ?></td>
                            <td><?php echo htmlspecialchars(isset($deposit['operator']) ? $deposit['operator'] : ''); ?></td>
                            <td class="right"><?php echo intval($deposit['count']); ?></td>
                            <td class="right"><?php echo registrarc_format_montant($deposit['total']); ?> €</td>
                            <td class="right">
                                <a href="bankchequedeposite.php?remise=<?php echo rawurlencode($deposit['id']); ?>">Voir / réimprimer</a>
                                <?php if ($canWrite): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode('Annuler la remise ' . $deposit['id'] . ' ? Ses chèques seront de nouveau à déposer.'), ENT_QUOTES); ?>);">
                                        <?php echo registrarc_form_fields($TourId); ?>
                                        <button type="submit" name="deposit_cancel" value="<?php echo htmlspecialchars($deposit['id']); ?>" class="link-btn">Annuler</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <script>
        function closeDepositTab() {
            window.close();

            setTimeout(function() {
                if (!window.closed) {
                    window.location.href = 'index.php';
                }
            }, 300);

            return false;
        }
    </script>
</body>
</html>
