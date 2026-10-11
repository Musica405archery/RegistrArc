<?php
// ============================================================================
// RegistrArc - qrcodes.php
// Étiquettes à imprimer (ordinateur du greffe) : un QR code par archer.
// Scanné avec l'appareil photo d'un téléphone, il ouvre le mode Kiosk
// directement sur cet archer (kiosk.php?q=licence).
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
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #eef1f6;
            color: #1f2933;
            font-size: 13px;
        }

        .controls {
            max-width: 210mm;
            margin: 16px auto;
            background: #fff;
            border-radius: 8px;
            padding: 14px 18px;
            box-shadow: 0 1px 2px rgba(20, 51, 107, 0.12);
        }

        .controls h1 {
            font-size: 18px;
            margin: 0 0 4px;
        }

        .controls p {
            color: #6b7280;
            margin: 0 0 12px;
        }

        .controls .row {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 10px 14px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .field label {
            font-size: 12px;
            color: #6b7280;
        }

        .field input[type="text"],
        .field select {
            height: 34px;
            padding: 0 10px;
            border: 1px solid #cfd6e1;
            border-radius: 8px;
            font: inherit;
        }

        .field input[type="text"] {
            width: 380px;
            max-width: 100%;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            min-height: 34px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid #cfd6e1;
            background: #fff;
            color: #1f2933;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .btn-primary {
            background: #14336b;
            border-color: #14336b;
            color: #fff;
        }

        .warning {
            margin-top: 10px;
            padding: 8px 12px;
            border-radius: 8px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #78350f;
        }

        .sheet {
            width: 210mm;
            margin: 0 auto 32px;
            background: #fff;
            padding: 8mm;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4mm;
        }

        .label {
            border: 1px dashed #cfd6e1;
            border-radius: 3mm;
            padding: 3mm;
            display: grid;
            grid-template-columns: 26mm 1fr;
            gap: 3mm;
            align-items: center;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .label[hidden] {
            display: none;
        }

        .qr svg {
            width: 26mm;
            height: 26mm;
            display: block;
        }

        .name {
            font-weight: 700;
            font-size: 12px;
            line-height: 1.2;
        }

        .meta {
            margin-top: 1.5mm;
            font-size: 9px;
            color: #4b5563;
            line-height: 1.35;
        }

        @media print {
            body {
                background: #fff;
            }

            .controls {
                display: none;
            }

            .sheet {
                width: auto;
                margin: 0;
                padding: 0;
            }
        }
    </style>
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
    <script>
        const URL_KEY = 'RegistrArcKioskUrl';

        function renderCodes() {
            const base = document.getElementById('kioskUrl').value.trim();

            document.querySelectorAll('.label').forEach(label => {
                const qr = qrcode(0, 'M');
                qr.addData(base + '?q=' + encodeURIComponent(label.dataset.licence));
                qr.make();
                label.querySelector('.qr').innerHTML = qr.createSvgTag(2, 0);
            });
        }

        function applyFilters() {
            const session = document.getElementById('sessionFilter').value;
            const dueOnly = document.getElementById('dueOnly').value === '1';

            document.querySelectorAll('.label').forEach(label => {
                const matchesSession = session === '' || label.dataset.sessions.indexOf(',' + session + ',') !== -1;
                const matchesDue = !dueOnly || label.dataset.due === '1';
                label.hidden = !(matchesSession && matchesDue);
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            const input = document.getElementById('kioskUrl');

            try {
                const saved = localStorage.getItem(URL_KEY);

                if (saved) {
                    input.value = saved;
                }
            } catch (e) {
                // adresse proposée par défaut
            }

            input.addEventListener('change', function () {
                try {
                    localStorage.setItem(URL_KEY, input.value.trim());
                } catch (e) {
                    // mémorisation impossible : sans conséquence
                }

                renderCodes();
            });

            document.getElementById('sessionFilter').addEventListener('change', applyFilters);
            document.getElementById('dueOnly').addEventListener('change', applyFilters);

            renderCodes();
        });
    </script>
</body>
</html>
