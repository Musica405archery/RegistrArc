<?php
// ============================================================================
// RegistrArc - clubs.php
// Villes des clubs, pour la carte du tableau de bord : ville trouvée dans le
// nom du club, et correction possible. Les corrections sont mémorisées par
// numéro de club pour tous les concours (data/club_cities.json).
// Calcul : lib/registrarc_clubs.inc.php
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

$canWrite = hasACL(AclParticipants, AclReadWrite);

// ---------------------------------------------------------------------------
// Enregistrement des corrections
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkACL(AclParticipants, AclReadWrite);

    // Ces villes ne touchent pas aux paiements : modifiables même concours clôturé
    $formError = registrarc_check_form($TourId, true);

    if ($formError !== '') {
        registrarc_flash('clubs', $formError, 'error');
    } else {
        $errors = [];
        $changed = 0;
        $current = registrarc_load_club_cities();
        $posted = isset($_POST['city']) && is_array($_POST['city']) ? $_POST['city'] : [];

        foreach ($posted as $code => $city) {
            $code = trim((string)$code);
            $city = trim((string)$city);
            $before = isset($current[$code]) ? registrarc_normalize_place($current[$code][0]) : '';

            if (registrarc_normalize_place($city) === $before) {
                continue;
            }

            $error = registrarc_set_club_city($code, $city);

            if ($error !== '') {
                $errors[] = $code . ' : ' . $error;
            } else {
                $changed++;
            }
        }

        if (!empty($errors)) {
            registrarc_flash('clubs', 'Non enregistré : ' . implode(' ; ', $errors), 'error');
        } else {
            registrarc_flash('clubs', $changed ? $changed . ' ville(s) enregistrée(s).' : 'Aucune modification.', $changed ? 'success' : 'info');
        }
    }

    header('Location: clubs.php');
    exit();
}

// ---------------------------------------------------------------------------
// Clubs du concours et leur ville
// ---------------------------------------------------------------------------
$tournament = registrarc_get_tournament_info($TourId);
$cities = registrarc_load_club_cities();
$departements = registrarc_departements();
$clubs = [];

foreach (registrarc_load_engagements($TourId) as $e) {
    $code = trim((string)$e['country_code']);

    if (!isset($clubs[$code])) {
        $dep = registrarc_club_departement($code);
        $detected = $dep !== '' ? registrarc_detect_club_city([$e['club'], $e['club_full']], $dep) : null;

        $clubs[$code] = [
            'code' => $code,
            'name' => $e['club'] !== '' ? $e['club'] : 'Sans club',
            'full' => $e['club_full'],
            'dep' => $dep,
            'detected' => $detected ? $detected[0] : '',
            'manual' => isset($cities[$code]) ? (string)$cities[$code][0] : '',
            'archers' => [],
        ];
    }

    $clubs[$code]['archers'][$e['licence']] = true;
}

uasort($clubs, function ($a, $b) {
    return strcmp($a['dep'] === '' ? 'zz' : $a['dep'], $b['dep'] === '' ? 'zz' : $b['dep']) ?: strcasecmp($a['name'], $b['name']);
});

$toCheck = 0;

foreach ($clubs as $club) {
    if ($club['dep'] !== '' && $club['manual'] === '' && $club['detected'] === '') {
        $toCheck++;
    }
}

$flash = registrarc_flash('clubs');

$PAGE_TITLE = 'RegistrArc - Villes des clubs';
include('Common/Templates/head.php');
registrarc_page_open('Villes des clubs', $tournament['name'], 'dashboard');
registrarc_render_flash($flash);
?>

<div class="rg-card">
    <h2>Où placer chaque club sur la carte ?</h2>
    <p class="rg-help">
        I@nseo ne connaît pas la ville des clubs. RegistrArc la cherche dans le nom du club, parmi les communes de son département
        (3e et 4e chiffres du numéro de club FFTA). Si elle n'est pas trouvée ou pas juste, indiquez-la ici : la correction est
        retenue pour tous les concours. Laissez le champ vide pour revenir à la ville trouvée automatiquement.
    </p>
    <p class="rg-help">
        <?php echo count($clubs); ?> club(s) dans ce concours<?php echo $toCheck ? ' · <strong>' . $toCheck . ' ville(s) à préciser</strong> (placés au centre de leur département)' : ''; ?>.
        <a href="dashboard.php">← Retour au tableau de bord</a>
    </p>
</div>

<div class="rg-card">
    <form method="POST">
        <?php echo registrarc_form_fields($TourId); ?>
        <div class="rg-table-wrap">
            <table class="rg-table">
                <thead>
                    <tr><th>Club</th><th>N° club</th><th>Département</th><th class="right">Archers</th><th>Ville trouvée</th><th>Ville à utiliser</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($clubs as $club): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($club['name']); ?>
                                <?php if ($club['full'] !== '' && $club['full'] !== $club['name']): ?>
                                    <div class="rg-muted" style="font-size:12px;"><?php echo htmlspecialchars($club['full']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?php echo htmlspecialchars($club['code']); ?></td>
                            <td><?php echo $club['dep'] !== '' ? htmlspecialchars($departements[$club['dep']][0] . ' (' . $club['dep'] . ')') : '<span class="rg-muted">inconnu</span>'; ?></td>
                            <td class="right num"><?php echo count($club['archers']); ?></td>
                            <td><?php echo $club['detected'] !== '' ? htmlspecialchars($club['detected']) : '<span class="rg-unpaid">non trouvée</span>'; ?></td>
                            <td>
                                <?php if ($club['dep'] === ''): ?>
                                    <span class="rg-muted">club non placé sur la carte</span>
                                <?php else: ?>
                                    <input type="text" name="city[<?php echo htmlspecialchars($club['code']); ?>]"
                                        value="<?php echo htmlspecialchars($club['manual']); ?>"
                                        placeholder="<?php echo htmlspecialchars($club['detected'] !== '' ? $club['detected'] : 'Commune du ' . $club['dep']); ?>"
                                        list="communes-<?php echo htmlspecialchars($club['dep']); ?>"
                                        autocomplete="off" class="rg-clubs-city"
                                        <?php echo $canWrite ? '' : 'disabled'; ?>>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($clubs)): ?>
                        <tr><td colspan="6" class="rg-muted">Aucun club dans ce concours.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php // Suggestions : les communes de chaque département présent ?>
        <?php foreach (array_unique(array_filter(array_column($clubs, 'dep'))) as $dep): ?>
            <datalist id="communes-<?php echo htmlspecialchars($dep); ?>">
                <?php foreach (registrarc_communes($dep) as $commune): ?>
                    <option value="<?php echo htmlspecialchars($commune[0]); ?>">
                <?php endforeach; ?>
            </datalist>
        <?php endforeach; ?>

        <?php if ($canWrite && !empty($clubs)): ?>
            <div class="rg-form" style="margin-top:14px;">
                <button type="submit" class="rg-btn rg-btn-primary">Enregistrer les villes</button>
            </div>
        <?php endif; ?>
    </form>
</div>

<?php
registrarc_page_close();
include('Common/Templates/tail.php');
