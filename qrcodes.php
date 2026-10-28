<?php
// ============================================================================
// RegistrArc - qrcodes.php
// Étiquettes à imprimer (ordinateur du greffe) : un QR code par archer.
// Scanné avec l'appareil photo d'un téléphone, il ouvre le mode Kiosk
// directement sur cet archer (kiosk.php?q=licence).
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');

$TourId = registrarc_page_tour_id();

$tournament = registrarc_get_tournament_info($TourId);

// Un archer par licence, avec ses départs
$archers = [];

foreach (registrarc_load_engagements($TourId) as $e) {
    if (!isset($archers[$e['licence']])) {
        $archers[$e['licence']] = [
            'licence' => $e['licence'],
            'name' => trim($e['nom'] . ' ' . $e['prenom']),
            'club' => $e['club'],
            'sessions' => [],
            'starts' => [],
            'due' => false,
        ];
    }

    $archers[$e['licence']]['sessions'][] = $e['session'];
    $archers[$e['licence']]['starts'][] = ($e['session'] !== '' ? 'Départ ' . $e['session'] : '') . ($e['target_no'] !== '' ? ' · ' . $e['target_no'] : '');
    $archers[$e['licence']]['due'] = $archers[$e['licence']]['due'] || $e['state'] === 'due';
}

uasort($archers, function ($a, $b) {
    return strcmp($a['name'], $b['name']);
});

$sessions = [];

foreach ($archers as $archer) {
    foreach ($archer['sessions'] as $session) {
        if ($session !== '') {
            $sessions[$session] = true;
        }
    }
}

ksort($sessions, SORT_NATURAL);

// Adresse du kiosque vue depuis les téléphones
$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$path = rtrim(str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/')), '/');
if ($path === '.' || $path === '') {
    $path = '';
} elseif ($path[0] !== '/') {
    $path = '/' . $path;
}

$isLocal = preg_match('/^(localhost|127\.|\[::1\])/i', $host) === 1;

if ($isLocal && !empty($_SERVER['SERVER_ADDR']) && !preg_match('/^(127\.|::1)/', $_SERVER['SERVER_ADDR'])) {
    $host = $_SERVER['SERVER_ADDR'] . (isset($_SERVER['SERVER_PORT']) && !in_array((string)$_SERVER['SERVER_PORT'], ['80', '443'], true) ? ':' . $_SERVER['SERVER_PORT'] : '');
}

$kioskUrl = ($https ? 'https' : 'http') . '://' . $host . $path . '/kiosk.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>QR codes - RegistrArc</title>
    <link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/qrcodes.css'); ?>">
</head>
<body>
    <div class="controls">
        <h1>QR codes des archers</h1>
        <p>
            Collez une étiquette sur la feuille de marque ou la fiche de l'archer. Scanné avec l'appareil photo d'un téléphone
            connecté au même réseau, le QR code ouvre le mode Kiosk directement sur cet archer.
            <?php echo htmlspecialchars($tournament['name']); ?> · <?php echo count($archers); ?> archer(s).
        </p>

        <div class="row">
            <div class="field">
                <label for="kioskUrl">Adresse du kiosque depuis les téléphones</label>
                <input type="text" id="kioskUrl" value="<?php echo htmlspecialchars($kioskUrl); ?>">
            </div>

            <div class="field">
                <label for="sessionFilter">Départ</label>
                <select id="sessionFilter">
                    <option value="">Tous</option>
                    <?php foreach (array_keys($sessions) as $session): ?>
                        <option value="<?php echo htmlspecialchars($session); ?>">Départ <?php echo htmlspecialchars($session); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="dueOnly">Archers</label>
                <select id="dueOnly">
                    <option value="0">Tous</option>
                    <option value="1">Seulement ceux qui doivent payer</option>
                </select>
            </div>

            <button type="button" class="btn btn-primary" onclick="window.print();">Imprimer</button>
            <a class="btn" href="dashboard.php">Retour</a>
        </div>

        <?php if ($isLocal): ?>
            <div class="warning">
                Cet ordinateur ouvre I@nseo par une adresse locale, que les téléphones ne peuvent pas joindre.
                Vérifiez que l'adresse ci-dessus est bien celle du serveur sur le réseau (par exemple http://192.168.1.10/…).
            </div>
        <?php endif; ?>
    </div>

    <div class="sheet" id="sheet">
        <?php foreach ($archers as $archer): ?>
            <div class="label" data-licence="<?php echo htmlspecialchars($archer['licence']); ?>" data-sessions="<?php echo htmlspecialchars(',' . implode(',', $archer['sessions']) . ','); ?>" data-due="<?php echo $archer['due'] ? '1' : '0'; ?>">
                <div class="qr"></div>
                <div>
                    <div class="name"><?php echo htmlspecialchars($archer['name']); ?></div>
                    <div class="meta">
                        <?php echo htmlspecialchars($archer['licence']); ?><br>
                        <?php echo htmlspecialchars($archer['club']); ?><br>
                        <?php echo htmlspecialchars(implode(' / ', array_filter($archer['starts']))); ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script src="lib/js/qrcode.js"></script>
    <script src="<?php echo registrarc_asset_url('assets/js/qrcodes.js'); ?>"></script>
</body>
</html>
