<?php
// ============================================================================
// RegistrArc - lib/registrarc_ui.inc.php
// Habillage commun des pages d'administration (tableau de bord, caisse,
// import HelloAsso, QR codes) : bandeau, navigation et styles, au même style
// que la liste des engagements.
//
// Utilisation (head.php et tail.php d'I@nseo restent chargés par la page
// elle-même, au niveau principal du script) :
//   $PAGE_TITLE = 'RegistrArc - …'; include('Common/Templates/head.php');
//   registrarc_page_open(…); … registrarc_page_close();
//   include('Common/Templates/tail.php');
// ============================================================================

// Adresse d'un fichier de assets/ (relative aux pages du module), suivie de sa
// date de modification : après une mise à jour, le navigateur recharge le fichier
function registrarc_asset_url($rel) {
    $file = dirname(dirname(__FILE__)) . '/' . $rel;

    return htmlspecialchars($rel . (is_file($file) ? '?v=' . filemtime($file) : ''));
}

function registrarc_page_open($title, $subtitle, $active) {
    $links = [
        'index' => ['index.php', 'Liste'],
        'dashboard' => ['dashboard.php', 'Tableau de bord'],
        'caisse' => ['caisse.php', 'Caisse'],
        'kiosk' => ['kiosk.php', 'Mode Kiosk'],
        'settings' => ['config_tarifs.php', '⚙ Paramètres'],
        'aide' => ['aide.php', '? Aide'],
    ];
    ?>
    <link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/admin.css'); ?>">

    <div class="rg">
        <div class="rg-header">
            <div>
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <div class="rg-sub"><?php echo htmlspecialchars($subtitle); ?></div>
            </div>

            <nav class="rg-nav">
                <?php foreach ($links as $key => $link): ?>
                    <a href="<?php echo $link[0]; ?>" class="<?php echo $key === $active ? 'is-active' : ''; ?>"><?php echo htmlspecialchars($link[1]); ?></a>
                <?php endforeach; ?>
            </nav>
        </div>
    <?php
}

function registrarc_page_close() {
    ?>
    </div>
    <script>
        // Opérateur : prénom mémorisé sur ce poste (saisi dans la liste ou le kiosque),
        // joint à chaque envoi de formulaire
        document.addEventListener('submit', function (event) {
            const form = event.target;

            if (!form || String(form.method).toUpperCase() !== 'POST') {
                return;
            }

            let name = '';

            try {
                name = localStorage.getItem('RegistrArcOperator') || '';
            } catch (e) {
                name = '';
            }

            let field = form.querySelector('input[name="operator"]');

            if (!field) {
                field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'operator';
                form.appendChild(field);
            }

            field.value = name;
        }, true);
    </script>
    <?php
}

// Message passé d'une page à l'autre après une action
function registrarc_flash($key, $message = null, $type = 'success') {
    if ($message !== null) {
        $_SESSION['RegistrArc_flash_' . $key] = ['message' => $message, 'type' => $type];
        return null;
    }

    if (empty($_SESSION['RegistrArc_flash_' . $key])) {
        return null;
    }

    $flash = $_SESSION['RegistrArc_flash_' . $key];
    unset($_SESSION['RegistrArc_flash_' . $key]);

    return $flash;
}

function registrarc_render_flash($flash) {
    if ($flash) {
        echo '<div class="rg-alert rg-alert-' . htmlspecialchars($flash['type']) . '">' . htmlspecialchars($flash['message']) . '</div>';
    }
}
