<?php
// ============================================================================
// RegistrArc - invoice.php
// Facture individuelle ou groupée
//
// Appel individuel : invoice.php?ids=123
// Appel groupé     : invoice.php?ids=123,124,125
//
// Paiements lus depuis : Modules/Custom/RegistrArc/data/payments_<TourId>.json
// Aucun paiement n'est lu ou écrit dans Qualifications.QuNotes
//
// Tarifs :
// - tarifs de base depuis data/settings_<TourId>.json
// - règles avancées cumulées si plusieurs règles correspondent
// - si aucune règle avancée ne correspond : tarif de base
//
// Visuels compétition :
// - ToLeft / ToRight via ScorePDF si disponibles
// - ToBottom via ScorePDF si disponible
// - aucune logique QRCode prise en compte ici
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');
require_once('Common/pdf/ScorePDF.inc.php');

$TourId = registrarc_page_tour_id();

// ---------------------------------------------------------------------------
// Lecture des IDs demandés
// ---------------------------------------------------------------------------
$idsParam = '';

if (isset($_GET['ids'])) {
    $idsParam = $_GET['ids'];
} elseif (isset($_GET['id'])) {
    $idsParam = $_GET['id'];
}

$invoiceParam = isset($_GET['num']) ? trim((string)$_GET['num']) : '';
$engagementIds = [];

foreach (explode(',', $idsParam) as $id) {
    $id = intval(trim($id));

    if ($id > 0) {
        $engagementIds[] = $id;
    }
}

$engagementIds = array_values(array_unique($engagementIds));

// ---------------------------------------------------------------------------
// Images
// ---------------------------------------------------------------------------
function registrarc_image_to_data_uri($path) {
    if (!$path || !is_file($path)) {
        return '';
    }

    $info = @getimagesize($path);

    if ($info === false || empty($info['mime'])) {
        return '';
    }

    $content = @file_get_contents($path);

    if ($content === false) {
        return '';
    }

    return 'data:' . $info['mime'] . ';base64,' . base64_encode($content);
}

// ---------------------------------------------------------------------------
// Visuels compétition via ScorePDF
// ---------------------------------------------------------------------------
function registrarc_get_competition_visuals(array $tournament) {
    $visuals = [
        'name' => isset($tournament['name']) ? $tournament['name'] : '',
        'where' => isset($tournament['where']) ? $tournament['where'] : '',
        'when' => isset($tournament['when_label']) ? $tournament['when_label'] : '',
        'left_logo_src' => '',
        'right_logo_src' => '',
        'bottom_image_src' => '',
        'has_left_logo' => false,
        'has_right_logo' => false,
        'has_bottom_image' => false,
    ];

    if (!class_exists('ScorePDF')) {
        return $visuals;
    }

    try {
        $pdf = new ScorePDF(true);

        if (!empty($pdf->Name)) {
            $visuals['name'] = $pdf->Name;
        }

        if (!empty($pdf->Where)) {
            $visuals['where'] = $pdf->Where;
        }

        if (!empty($pdf->WhenF) || !empty($pdf->WhenT)) {
            $dateLabel = registrarc_tournament_date_string($pdf->WhenF, $pdf->WhenT);

            if ($dateLabel !== '') {
                $visuals['when'] = $dateLabel;
            }
        }

        if (!empty($pdf->PrintLogo) && !empty($pdf->ToPaths) && is_array($pdf->ToPaths)) {
            if (!empty($pdf->ToPaths['ToLeft']) && file_exists($pdf->ToPaths['ToLeft'])) {
                $visuals['left_logo_src'] = registrarc_image_to_data_uri($pdf->ToPaths['ToLeft']);
                $visuals['has_left_logo'] = ($visuals['left_logo_src'] !== '');
            }

            if (!empty($pdf->ToPaths['ToRight']) && file_exists($pdf->ToPaths['ToRight'])) {
                $visuals['right_logo_src'] = registrarc_image_to_data_uri($pdf->ToPaths['ToRight']);
                $visuals['has_right_logo'] = ($visuals['right_logo_src'] !== '');
            }
        }

        if (!empty($pdf->BottomImage) && !empty($pdf->ToPaths) && is_array($pdf->ToPaths)) {
            if (!empty($pdf->ToPaths['ToBottom']) && file_exists($pdf->ToPaths['ToBottom'])) {
                $visuals['bottom_image_src'] = registrarc_image_to_data_uri($pdf->ToPaths['ToBottom']);
                $visuals['has_bottom_image'] = ($visuals['bottom_image_src'] !== '');
            }
        }
    } catch (Exception $e) {
        return $visuals;
    }

    return $visuals;
}

// ---------------------------------------------------------------------------
// Lignes de la facture (calcul commun à toutes les pages)
// ---------------------------------------------------------------------------
function registrarc_invoice_rows($TourId, array $engagementIds, array $paymentModes) {
    $wanted = array_flip(array_map('intval', $engagementIds));
    $rows = [];

    // Ordre licence / départ / cible, comme dans la liste
    foreach (registrarc_load_engagements($TourId) as $id => $e) {
        if (!isset($wanted[$id])) {
            continue;
        }

        $method = $e['payment_method'];
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
            'numero_engagement' => $e['numero'],
            'session_label' => $e['session'] !== '' ? 'Départ ' . $e['session'] : '—',
            'target_label' => $e['target_label'],
            'payment_status_label' => registrarc_state_label($e['state']),
            'payment_method' => $method,
            'payment_method_label' => $method !== '' ? (isset($paymentModes[$method]) ? $paymentModes[$method] : $method) : '—',
            'tarif_rule_label' => $specialLabel,
            'tarif_type_label' => $specialLabel !== '' ? ($e['rule_count'] > 1 ? 'Cumul : ' : '') . $specialLabel : 'Standard',
            'tarif_type_class' => $specialLabel !== '' ? 'tarif-rule' : 'tarif-standard',
            'amount' => $e['amount'],
        ];
    }

    return $rows;
}

// ---------------------------------------------------------------------------
// Destinataire groupé par club
// ---------------------------------------------------------------------------
function registrarc_build_invoice_customer_groups(array $rows) {
    $groups = [];

    foreach ($rows as $row) {
        $club = trim((string)$row['club']);

        if ($club === '') {
            $club = 'Sans club';
        }

        $archer = trim($row['nom'] . ' ' . $row['prenom']);

        if ($archer === '') {
            continue;
        }

        if (!isset($groups[$club])) {
            $groups[$club] = [];
        }

        $groups[$club][$archer] = true;
    }

    $out = [];

    foreach ($groups as $club => $archers) {
        $list = array_keys($archers);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);

        $out[] = [
            'club' => $club,
            'archers' => $list,
        ];
    }

    usort($out, function($a, $b) {
        return strcmp($a['club'], $b['club']);
    });

    return $out;
}

// ---------------------------------------------------------------------------
// Erreur
// ---------------------------------------------------------------------------
function registrarc_render_error($title, $message) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            body {
                font-family: Arial, Helvetica, sans-serif;
                background: #f9fafb;
                color: #111827;
                padding: 30px;
            }

            .box {
                max-width: 700px;
                margin: 0 auto;
                background: #fff;
                border-radius: 8px;
                padding: 20px;
                box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            }

            h1 {
                font-size: 20px;
                margin-top: 0;
            }

            .error {
                color: #b91c1c;
                font-weight: 700;
            }

            .btn {
                display: inline-flex;
                margin-top: 12px;
                padding: 8px 14px;
                background: #2563eb;
                color: #fff;
                text-decoration: none;
                border-radius: 999px;
                font-size: 13px;
                font-weight: 700;
            }
        </style>
    </head>

    <body>
        <div class="box">
            <h1><?php echo htmlspecialchars($title); ?></h1>
            <p class="error"><?php echo htmlspecialchars($message); ?></p>
            <a href="index.php" class="btn">Retour</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ---------------------------------------------------------------------------
// Données
// ---------------------------------------------------------------------------
$issuedInvoice = null;

if ($invoiceParam !== '') {
    $issuedInvoice = registrarc_find_invoice($invoiceParam);

    if (!$issuedInvoice || intval($issuedInvoice['tour_id']) !== $TourId) {
        registrarc_render_error('Facture introuvable', 'Aucune facture n° ' . $invoiceParam . ' pour ce concours.');
    }

    $engagementIds = $issuedInvoice['engagement_ids'];
}

if (empty($engagementIds)) {
    registrarc_render_error(
        'Facture impossible',
        'Aucun engagement sélectionné pour générer la facture.'
    );
}

$tournament = registrarc_get_tournament_info($TourId);
$competitionVisuals = registrarc_get_competition_visuals($tournament);
$paymentModes = registrarc_load_payment_modes($TourId);

$canWrite = registrarc_can_write($TourId);

if ($issuedInvoice) {
    // Facture émise : contenu figé au moment de l'émission
    $rows = $issuedInvoice['snapshot']['rows'];
} else {
    $rows = registrarc_invoice_rows($TourId, $engagementIds, $paymentModes);
}

if (empty($rows)) {
    registrarc_render_error(
        'Facture impossible',
        'Aucun engagement trouvé pour cette facture.'
    );
}

$total = 0;

foreach ($rows as $row) {
    $total += $row['amount'];
}

$customerGroups = registrarc_build_invoice_customer_groups($rows);
$isGroupedInvoice = count($rows) > 1;

// ---------------------------------------------------------------------------
// Émettre la facture : numéro définitif et copie figée
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_invoice']) && !$issuedInvoice) {
    checkACL(AclParticipants, AclReadWrite);

    $formError = registrarc_check_form($TourId);

    if ($formError !== '') {
        registrarc_render_error('Facture non émise', $formError);
    }

    $number = registrarc_issue_invoice($TourId, $engagementIds, [
        'rows' => $rows,
        'total' => $total,
    ]);

    if ($number === '') {
        registrarc_render_error('Facture non émise', "Erreur lors de l'enregistrement." . registrarc_storage_error_suffix());
    }

    header('Location: invoice.php?num=' . rawurlencode($number));
    exit();
}

$existingInvoice = $issuedInvoice ? null : registrarc_invoice_for_ids($TourId, $engagementIds);

if ($issuedInvoice) {
    $invoiceNumber = $issuedInvoice['number'];
    $invoiceDate = date('d/m/Y', strtotime($issuedInvoice['issued_at']));
} else {
    $invoiceNumber = 'aperçu, non numérotée';
    $invoiceDate = date('d/m/Y');
}

// ---------------------------------------------------------------------------
// HTML
// ---------------------------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facture <?php echo htmlspecialchars($invoiceNumber); ?></title>

    <link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/invoice.css'); ?>">
</head>

<body>
    <?php if (!$issuedInvoice): ?>
        <div class="notice no-print">
            <?php if ($existingInvoice): ?>
                Une facture a déjà été émise pour ces engagements :
                <a href="invoice.php?num=<?php echo rawurlencode($existingInvoice['number']); ?>">facture n° <?php echo htmlspecialchars($existingInvoice['number']); ?></a>.
            <?php else: ?>
                Aperçu : cette facture n'a pas encore de numéro. « Émettre la facture » lui attribue un numéro définitif
                et l'enregistre telle quelle, pour pouvoir la réimprimer à l'identique.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="invoice-page">
        <div class="competition-header">
            <div class="competition-logo">
                <?php if (!empty($competitionVisuals['has_left_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($competitionVisuals['left_logo_src']); ?>" alt="Logo gauche">
                <?php endif; ?>
            </div>

            <div class="competition-info">
                <div class="competition-name">
                    <?php echo htmlspecialchars($competitionVisuals['name']); ?>
                </div>

                <?php if (!empty($competitionVisuals['where'])): ?>
                    <div class="competition-where">
                        <?php echo htmlspecialchars($competitionVisuals['where']); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($competitionVisuals['when'])): ?>
                    <div class="competition-date">
                        <?php echo htmlspecialchars($competitionVisuals['when']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="competition-logo">
                <?php if (!empty($competitionVisuals['has_right_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($competitionVisuals['right_logo_src']); ?>" alt="Logo droit">
                <?php endif; ?>
            </div>
        </div>

        <div class="invoice-header">
            <div>
                <div class="brand-title">Facture</div>
                <div class="brand-subtitle">Gestion des engagements - Registr'Arc</div>
                <div class="brand-subtitle">Le module de Greffe pour I@nseo</div>
            </div>

            <div class="invoice-meta">
                <strong>Facture n° :</strong> <?php echo htmlspecialchars($invoiceNumber); ?><br>
                <strong>Date :</strong> <?php echo htmlspecialchars($invoiceDate); ?><br>
                <strong>Type :</strong> <?php echo $isGroupedInvoice ? 'Facture groupée' : 'Facture individuelle'; ?>
            </div>
        </div>

        <div class="box-grid">
            <div class="box">
                <div class="box-title">Concours</div>
                <div class="box-content">
<?php echo htmlspecialchars($tournament['name']); ?>

<?php if ($tournament['organizer_name'] || $tournament['organizer_code']): ?>
Organisateur : <?php echo htmlspecialchars($tournament['organizer_name'] ?: $tournament['organizer_code']); ?>
<?php endif; ?>

<?php if (!empty($tournament['where'])): ?>
Lieu : <?php echo htmlspecialchars($tournament['where']); ?>
<?php endif; ?>

<?php if (!empty($tournament['when_label'])): ?>
Date : <?php echo htmlspecialchars($tournament['when_label']); ?>
<?php endif; ?>
                </div>
            </div>

            <div class="box">
                <div class="box-title">Facturé à</div>

                <?php if (!empty($customerGroups)): ?>
                    <div class="customer-groups">
                        <?php foreach ($customerGroups as $group): ?>
                            <div class="customer-group">
                                <div class="customer-club">
                                    <?php echo htmlspecialchars($group['club']); ?>
                                </div>

                                <div class="customer-archers">
                                    <?php foreach ($group['archers'] as $archer): ?>
                                        <div class="customer-archer">
                                            <?php echo htmlspecialchars($archer); ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="box-content">—</div>
                <?php endif; ?>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:7%;">Eng.</th>
                    <th style="width:11%;">Licence</th>
                    <th style="width:18%;">Archer</th>
                    <th style="width:9%;">Catégorie</th>
                    <th style="width:10%;">Départ</th>
                    <th style="width:10%;">Cible</th>
                    <th style="width:9%;" class="right">Montant</th>
                    <th style="width:12%;">Tarif</th>
                    <th style="width:7%;">Statut</th>
                    <th style="width:7%;">Paiement</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="center">n° <?php echo intval($row['numero_engagement']); ?></td>
                        <td><?php echo htmlspecialchars($row['licence']); ?></td>
                        <td><?php echo htmlspecialchars(trim($row['nom'] . ' ' . $row['prenom'])); ?></td>
                        <td><?php echo htmlspecialchars($row['categorie']); ?></td>
                        <td><?php echo htmlspecialchars($row['session_label']); ?></td>
                        <td><?php echo htmlspecialchars($row['target_label']); ?></td>
                        <td class="right"><?php echo registrarc_format_montant($row['amount']); ?> €</td>
                        <td>
                            <span class="<?php echo htmlspecialchars($row['tarif_type_class']); ?>">
                                <?php echo htmlspecialchars($row['tarif_type_label']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo ($row['payment_status_label'] !== 'Non payé') ? 'status-paid' : 'status-unpaid'; ?>">
                                <?php echo htmlspecialchars($row['payment_status_label']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['payment_method_label']); ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="total-row">
                    <td colspan="6" class="right">Total</td>
                    <td class="right"><?php echo registrarc_format_montant($total); ?> €</td>
                    <td colspan="3"></td>
                </tr>
            </tbody>
        </table>

        <?php if (!empty($competitionVisuals['has_bottom_image'])): ?>
            <div class="bottom-image-wrapper">
                <img src="<?php echo htmlspecialchars($competitionVisuals['bottom_image_src']); ?>" alt="Logo / sponsors" class="bottom-image">
            </div>
        <?php endif; ?>
    </div>

    <div class="actions">
        <button type="button" class="btn btn-ghost" onclick="closeInvoiceTab();">
            Retour
        </button>

        <?php if (!$issuedInvoice && !$existingInvoice && $canWrite): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Émettre la facture ? Elle recevra un numéro définitif.');">
                <?php echo registrarc_form_fields($TourId); ?>
                <button type="submit" name="issue_invoice" value="1" class="btn btn-success">Émettre la facture</button>
            </form>
        <?php endif; ?>

        <button type="button" class="btn btn-primary" onclick="window.print();">
            Imprimer / Enregistrer en PDF
        </button>
    </div>

    <script>
        function closeInvoiceTab() {
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