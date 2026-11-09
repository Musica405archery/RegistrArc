<?php
// ============================================================================
// RegistrArc - lib/registrarc_caisse_view.inc.php
// Affichage de la caisse : totaux, fond de caisse, clôture, opérations,
// clôtures précédentes. Utilisé par la page Trésorier du tableau de bord
// (caisse en cours, avec les formulaires) et par caisse.php (rapports
// imprimables). Les formulaires sont envoyés à caisse.php.
// ============================================================================

// Grille des pièces et billets : saisie des quantités ($name) ou lecture seule ($name = '')
function registrarc_render_cash_grid($name, array $detail) {
    $denominations = registrarc_cash_denominations();
    $groups = ['Pièces' => $denominations['coins'], 'Billets' => $denominations['notes']];
    ?>
    <div class="rg-cash-grid" data-cash-grid>
        <?php foreach ($groups as $title => $values): ?>
            <table class="rg-table rg-cash-table">
                <thead>
                    <tr><th><?php echo $title; ?></th><th class="right">Quantité</th><th class="right">Montant</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($values as $cents): ?>
                        <?php $count = isset($detail[$cents]) ? intval($detail[$cents]) : 0; ?>
                        <tr>
                            <td class="num"><?php echo htmlspecialchars(registrarc_cash_denomination_label($cents)); ?></td>
                            <td class="right num">
                                <?php if ($name !== ''): ?>
                                    <input type="number" name="<?php echo htmlspecialchars($name); ?>[<?php echo $cents; ?>]" value="<?php echo $count ?: ''; ?>" min="0" max="100000" step="1" inputmode="numeric" data-cents="<?php echo $cents; ?>" aria-label="<?php echo htmlspecialchars('Quantité de ' . registrarc_cash_denomination_label($cents)); ?>">
                                <?php else: ?>
                                    <?php echo $count ?: '<span class="rg-muted">—</span>'; ?>
                                <?php endif; ?>
                            </td>
                            <td class="right num" data-amount><?php echo $count ? registrarc_format_montant($cents * $count / 100) . ' €' : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
        <p class="rg-cash-total">Total : <strong class="num" data-cash-total><?php echo registrarc_format_montant(registrarc_cash_detail_total($detail)); ?> €</strong></p>
    </div>
    <?php
}

// $closure : clôture à afficher (rapport), ou null pour la caisse en cours.
// $mode : 'panel' (tableau de bord, avec les formulaires) ou 'print' (page imprimable).
function registrarc_render_caisse($TourId, $closure = null, $mode = 'panel') {
    $canWrite = $mode === 'panel' && registrarc_can_write($TourId);
    $paymentModes = registrarc_load_payment_modes($TourId);
    $closures = registrarc_load_closures($TourId);
    $backUrl = 'dashboard.php?page=tresorier#caisse';

    if ($closure) {
        $summary = registrarc_cash_summary($TourId, intval($closure['journal_from']), intval($closure['journal_to']));
        // Les clôtures antérieures au fond de caisse n'ont pas ces champs
        $floatTotal = isset($closure['cash_float']) ? (float)$closure['cash_float'] : 0;
        $floatDetail = isset($closure['cash_float_detail']) ? registrarc_normalize_cash_detail($closure['cash_float_detail']) : [];
        $countedDetail = isset($closure['cash_counted_detail']) ? registrarc_normalize_cash_detail($closure['cash_counted_detail']) : [];
        $cashExpected = (float)$closure['cash_expected'];
    } else {
        $period = registrarc_current_cash_period($TourId);
        $periodStart = $period['since'];
        $summary = registrarc_cash_summary($TourId, $period['index']);
        $float = registrarc_load_cash_float($TourId);
        $floatTotal = $float['total'];
        $floatDetail = $float['detail'];
        $cashExpected = round($floatTotal + (isset($summary['totals']['ESP']) ? $summary['totals']['ESP'] : 0), 2);
    }

    $cashTakings = round($cashExpected - $floatTotal, 2);

    $modeLabel = function ($code) use ($paymentModes) {
        return isset($paymentModes[$code]) ? $paymentModes[$code] : $code;
    };

    $formatDate = function ($value) {
        return $value !== '' ? date('d/m/Y H:i', strtotime($value)) : 'début du concours';
    };
    ?>
    <link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/caisse.css'); ?>">

    <div class="rg-card">
        <?php if ($closure): ?>
            <h2>Clôture <?php echo htmlspecialchars($closure['id']); ?></h2>
            <p class="rg-help">
                Du <?php echo htmlspecialchars($formatDate((string)$closure['from'])); ?>
                au <?php echo htmlspecialchars($formatDate((string)$closure['to'])); ?>
                <?php echo !empty($closure['operator']) ? ' · clôturée par ' . htmlspecialchars($closure['operator']) : ''; ?>
                <?php echo !empty($closure['comment']) ? ' · ' . htmlspecialchars($closure['comment']) : ''; ?>
            </p>
        <?php else: ?>
            <h2>Caisse en cours</h2>
            <p class="rg-help">Opérations depuis le <?php echo htmlspecialchars($formatDate($periodStart)); ?>.</p>
        <?php endif; ?>

        <div class="rg-grid">
            <div>
                <table class="rg-table">
                    <thead>
                        <tr><th>Mode de paiement</th><th class="right">Montant</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['totals'] as $code => $amount): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($modeLabel($code)); ?></td>
                                <td class="right num"><?php echo registrarc_format_montant($amount); ?> €</td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($summary['totals'])): ?>
                            <tr><td colspan="2" class="rg-muted">Aucune opération.</td></tr>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr><td>Total (<?php echo intval($summary['count']); ?> opération(s))</td><td class="right num"><?php echo registrarc_format_montant($summary['total']); ?> €</td></tr>
                    </tfoot>
                </table>
            </div>

            <div>
                <div class="rg-figures">
                    <div class="rg-figure">
                        <strong><?php echo registrarc_format_montant($floatTotal); ?> €</strong>
                        <span>Fond de caisse</span>
                    </div>
                    <div class="rg-figure">
                        <strong><?php echo registrarc_format_montant($cashTakings); ?> €</strong>
                        <span>Encaissements espèces</span>
                    </div>
                    <div class="rg-figure">
                        <strong><?php echo registrarc_format_montant($cashExpected); ?> €</strong>
                        <span>Espèces attendues</span>
                    </div>

                    <?php if ($closure && $closure['cash_counted'] !== null): ?>
                        <div class="rg-figure">
                            <strong><?php echo registrarc_format_montant($closure['cash_counted']); ?> €</strong>
                            <span>Espèces comptées</span>
                        </div>
                        <div class="rg-figure">
                            <strong class="<?php echo (float)$closure['cash_difference'] == 0 ? 'rg-paid' : 'rg-unpaid'; ?>"><?php echo ((float)$closure['cash_difference'] > 0 ? '+' : '') . registrarc_format_montant($closure['cash_difference']); ?> €</strong>
                            <span>Écart</span>
                        </div>
                        <?php if ($floatTotal > 0): ?>
                            <div class="rg-figure">
                                <strong><?php echo registrarc_format_montant(round((float)$closure['cash_counted'] - $floatTotal, 2)); ?> €</strong>
                                <span>À retirer en laissant le fond</span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="rg-form rg-noprint" style="margin-top:14px;">
                    <?php if ($mode === 'panel'): ?>
                        <a class="rg-btn" href="caisse.php?imprimer=1" target="_blank">Imprimer</a>
                    <?php else: ?>
                        <button type="button" class="rg-btn" onclick="window.print();">Imprimer</button>
                        <a class="rg-btn" href="<?php echo $backUrl; ?>">Retour au tableau de bord</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($closure): ?>
        <?php if (!empty($countedDetail) || !empty($floatDetail)): ?>
            <div class="rg-card">
                <div class="rg-grid">
                    <?php if (!empty($countedDetail)): ?>
                        <div>
                            <h2>Comptage</h2>
                            <?php registrarc_render_cash_grid('', $countedDetail); ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($floatDetail)): ?>
                        <div>
                            <h2>Fond de caisse</h2>
                            <?php registrarc_render_cash_grid('', $floatDetail); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="rg-card">
            <h2>Fond de caisse</h2>
            <p class="rg-help">
                Argent mis dans la caisse pour rendre la monnaie, détaillé par pièces et billets ou en un seul montant. Il s'ajoute aux encaissements en espèces dans les espèces attendues
                et reste le même à chaque clôture : après une clôture, on retire la recette en laissant ce fond dans la caisse.
                <?php if ($float['updated_at'] !== ''): ?>
                    <br>Modifié le <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($float['updated_at']))); ?><?php echo $float['operator'] !== '' ? ' par ' . htmlspecialchars($float['operator']) : ''; ?>.
                <?php endif; ?>
            </p>

            <?php if ($canWrite): ?>
                <form method="POST" action="caisse.php" data-float-form>
                    <?php echo registrarc_form_fields($TourId); ?>

                    <div class="rg-float-modes rg-noprint" role="radiogroup" aria-label="Type de fond de caisse">
                        <label><input type="radio" name="float_mode" value="detail" <?php echo $float['mode'] === 'detail' ? 'checked' : ''; ?>> Détaillé : pièces et billets</label>
                        <label><input type="radio" name="float_mode" value="simple" <?php echo $float['mode'] === 'simple' ? 'checked' : ''; ?>> Simplifié : un seul montant</label>
                    </div>

                    <div data-float-mode="detail" <?php echo $float['mode'] === 'detail' ? '' : 'hidden'; ?>>
                        <?php registrarc_render_cash_grid('float', $floatDetail); ?>
                    </div>

                    <div data-float-mode="simple" class="rg-form" <?php echo $float['mode'] === 'simple' ? '' : 'hidden'; ?>>
                        <div class="rg-field">
                            <label for="float_amount">Montant du fond de caisse (€)</label>
                            <input type="text" inputmode="decimal" name="float_amount" id="float_amount" value="<?php echo $float['mode'] === 'simple' ? registrarc_format_montant($float['amount']) : ''; ?>" placeholder="Ex. : 50" size="10">
                        </div>
                    </div>

                    <div class="rg-form rg-noprint">
                        <button type="submit" name="save_float" value="1" class="rg-btn">Enregistrer le fond de caisse</button>
                    </div>
                </form>
            <?php elseif ($float['mode'] === 'simple'): ?>
                <p>Montant : <strong class="num"><?php echo registrarc_format_montant($float['amount']); ?> €</strong></p>
            <?php else: ?>
                <?php registrarc_render_cash_grid('', $floatDetail); ?>
            <?php endif; ?>
        </div>

        <?php if ($canWrite): ?>
            <div class="rg-card rg-noprint">
                <h2>Clôturer la caisse</h2>
                <?php if ($float['mode'] === 'simple'): ?>
                    <p class="rg-help">Compter les espèces présentes dans la caisse, fond compris. Sans montant, la clôture est enregistrée sans écart.</p>
                <?php else: ?>
                    <p class="rg-help">Compter les pièces et billets présents dans la caisse, fond compris. Sans comptage, la clôture est enregistrée sans écart.</p>
                <?php endif; ?>

                <form method="POST" action="caisse.php" onsubmit="return confirm('Clôturer la caisse ? Les prochaines opérations compteront pour la clôture suivante.');">
                    <?php echo registrarc_form_fields($TourId); ?>
                    <?php if ($float['mode'] !== 'simple'): ?>
                        <?php registrarc_render_cash_grid('count', []); ?>
                    <?php endif; ?>
                    <p class="rg-help">Espèces attendues : <strong class="num"><?php echo registrarc_format_montant($cashExpected); ?> €</strong></p>
                    <div class="rg-form">
                        <?php if ($float['mode'] === 'simple'): ?>
                            <div class="rg-field">
                                <label for="cash_counted">Espèces comptées (€)</label>
                                <input type="text" inputmode="decimal" name="cash_counted" id="cash_counted" placeholder="<?php echo registrarc_format_montant($cashExpected); ?>" size="10">
                            </div>
                        <?php endif; ?>
                        <div class="rg-field">
                            <label for="comment">Commentaire</label>
                            <input type="text" name="comment" id="comment" maxlength="200" placeholder="Ex. : samedi matin" size="22">
                        </div>
                        <button type="submit" name="close_cash" value="1" class="rg-btn rg-btn-primary">Clôturer la caisse</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="rg-card">
        <h2>Opérations</h2>
        <p class="rg-help">Encaissements, annulations et remboursements, du plus récent au plus ancien.</p>

        <div class="rg-table-wrap<?php echo $mode === 'panel' ? ' rg-cash-ops' : ''; ?>">
            <table class="rg-table">
                <thead>
                    <tr><th>Heure</th><th>Opération</th><th>Archer</th><th>Mode</th><th class="right">Montant</th><th>Opérateur</th><th>Poste</th></tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($summary['operations']) as $operation): ?>
                        <tr>
                            <td class="num"><?php echo htmlspecialchars(date('d/m H:i', strtotime($operation['at']))); ?></td>
                            <td><?php echo htmlspecialchars(registrarc_journal_type_label($operation['type'])); ?><?php echo !empty($operation['label']) ? ' <span class="rg-muted">(' . htmlspecialchars($operation['label']) . ')</span>' : ''; ?></td>
                            <td><?php echo htmlspecialchars(isset($operation['archer']) ? $operation['archer'] : ''); ?></td>
                            <td><?php echo htmlspecialchars($modeLabel(isset($operation['method']) ? $operation['method'] : '')); ?></td>
                            <td class="right num <?php echo (float)$operation['amount'] < 0 ? 'rg-unpaid' : ''; ?>"><?php echo registrarc_format_montant($operation['amount']); ?> €</td>
                            <td><?php echo htmlspecialchars(isset($operation['operator']) ? $operation['operator'] : ''); ?></td>
                            <td class="rg-muted"><?php echo htmlspecialchars(isset($operation['device']) ? $operation['device'] : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($summary['operations'])): ?>
                        <tr><td colspan="7" class="rg-muted">Aucune opération sur cette période.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($closures) && $mode === 'panel'): ?>
        <div class="rg-card rg-noprint">
            <h2>Clôtures précédentes</h2>
            <div class="rg-table-wrap">
                <table class="rg-table">
                    <thead>
                        <tr><th>Clôture</th><th>Période</th><th class="right">Opérations</th><th class="right">Total</th><th class="right">Fond</th><th class="right">Espèces attendues</th><th class="right">Comptées</th><th class="right">Écart</th><th>Opérateur</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse($closures) as $item): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($item['id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($formatDate((string)$item['from']) . ' → ' . $formatDate((string)$item['to'])); ?></td>
                                <td class="right num"><?php echo intval($item['count']); ?></td>
                                <td class="right num"><?php echo registrarc_format_montant($item['total']); ?> €</td>
                                <td class="right num"><?php echo registrarc_format_montant(isset($item['cash_float']) ? $item['cash_float'] : 0); ?> €</td>
                                <td class="right num"><?php echo registrarc_format_montant($item['cash_expected']); ?> €</td>
                                <td class="right num"><?php echo $item['cash_counted'] !== null ? registrarc_format_montant($item['cash_counted']) . ' €' : '—'; ?></td>
                                <td class="right num <?php echo $item['cash_difference'] !== null && (float)$item['cash_difference'] != 0 ? 'rg-unpaid' : ''; ?>"><?php echo $item['cash_difference'] !== null ? registrarc_format_montant($item['cash_difference']) . ' €' : '—'; ?></td>
                                <td><?php echo htmlspecialchars(isset($item['operator']) ? $item['operator'] : ''); ?></td>
                                <td><a href="caisse.php?cloture=<?php echo rawurlencode($item['id']); ?>" target="_blank">Voir / imprimer</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <script src="<?php echo registrarc_asset_url('assets/js/caisse.js'); ?>"></script>
    <?php
}
