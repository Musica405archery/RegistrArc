<?php
// ============================================================================
// RegistrArc - aide.php
// Mode d'emploi du module : le README.md, affiché dans I@nseo.
//
// - le README est lu tel quel et mis en forme dans le navigateur par
//   lib/js/marked.min.js (aucun accès à internet nécessaire)
// - les captures d'écran viennent de docs/images/, distribuées avec le module
// - assets/js/aide.js reproduit l'affichage de GitHub : ancres des titres,
//   encadrés (> [!TIP]…), sections repliables ; les liens vers des fichiers
//   non distribués (docs/MAINTENANCE.md) ouvrent le dépôt GitHub
// ============================================================================

require_once(dirname(__FILE__) . '/lib/registrarc_page.inc.php');
require_once(dirname(__FILE__) . '/lib/registrarc_update.inc.php');

$TourId = registrarc_page_tour_id();
$tournament = registrarc_get_tournament_info($TourId);

$readme = @file_get_contents(dirname(__FILE__) . '/README.md');

// Adresse des fichiers du dépôt, pour les liens vers ce qui n'est pas installé
$updateConfig = registrarc_update_config();
$repoBase = '';

if (preg_match('#github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#i', trim((string)$updateConfig['github_url']), $m)) {
    $branch = trim((string)$updateConfig['github_branch']) !== '' ? trim((string)$updateConfig['github_branch']) : 'main';
    $repoBase = 'https://github.com/' . $m[1] . '/' . $m[2] . '/blob/' . str_replace('%2F', '/', rawurlencode($branch)) . '/';
}

$PAGE_TITLE = 'RegistrArc - Mode d\'emploi';
include('Common/Templates/head.php');
registrarc_page_open('Mode d\'emploi', 'RegistrArc ' . registrarc_local_version_number() . ' · ' . $tournament['name'], 'aide');
?>
<link rel="stylesheet" href="<?php echo registrarc_asset_url('assets/css/aide.css'); ?>">

<?php if ($readme === false || trim($readme) === ''): ?>
    <div class="rg-alert rg-alert-warning">
        Le fichier <strong>README.md</strong> est introuvable dans le dossier du module.
        <?php if ($repoBase !== ''): ?>
            Le mode d'emploi est aussi consultable <a href="<?php echo htmlspecialchars($repoBase . 'README.md'); ?>" target="_blank" rel="noopener">sur GitHub</a>.
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="rg-doc-toolbar rg-noprint">
        <button type="button" class="rg-btn" data-doc-action="open">Tout déplier</button>
        <button type="button" class="rg-btn" data-doc-action="close">Tout replier</button>
        <button type="button" class="rg-btn" data-doc-action="print">Imprimer</button>
    </div>

    <div class="rg-doc-shell">
        <article class="rg-card rg-doc" id="rgDoc" data-repo="<?php echo htmlspecialchars($repoBase); ?>">
            <noscript>Le mode d'emploi a besoin de JavaScript pour s'afficher.</noscript>
        </article>

        <aside class="rg-toc rg-noprint" id="rgDocToc" aria-label="Sommaire" hidden>
            <button type="button" class="rg-toc-toggle" aria-expanded="false" aria-controls="rgDocTocList">Sommaire</button>
            <div class="rg-toc-panel" id="rgDocTocList">
                <div class="rg-toc-title">Sommaire</div>
                <nav></nav>
                <a href="#" class="rg-toc-top">↑ Haut de page</a>
            </div>
        </aside>
    </div>

    <script type="application/json" id="rgDocSource"><?php echo json_encode($readme, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
    <script src="<?php echo registrarc_asset_url('lib/js/marked.min.js'); ?>"></script>
    <script src="<?php echo registrarc_asset_url('assets/js/aide.js'); ?>"></script>
<?php endif; ?>
<?php
registrarc_page_close();
include('Common/Templates/tail.php');
