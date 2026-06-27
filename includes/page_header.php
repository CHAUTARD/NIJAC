<?php
/**
 * NIJAC – En-tête de page mutualisé
 *
 * Prérequis : les variables suivantes doivent être définies avant l'include :
 *   $pageIcon    (string)      – classe Bootstrap Icon sans le préfixe "bi " (ex. 'bi-gear-fill')
 *   $pageTitle   (string)      – intitulé de la page (ex. 'Configuration générale')
 *   $pageCode    (string)      – code écran (ex. 'E015')
 *   $backUrl     (string|null) – URL du bouton Retour ; null = pas de bouton (pages menu)
 *
 * Paramètres optionnels :
 *   $pageIconClass (string)    – classes supplémentaires de l'icône (défaut : 'me-2')
 *   $backBtnClass (string)     – classes complètes du bouton (défaut : 'btn btn-sm btn-light float-end py-0')
 *
 * Usage depuis la racine :
 *   $pageIcon  = 'bi-gear-fill';
 *   $pageTitle = 'Configuration générale';
 *   $pageCode  = 'E015';
 *   $backUrl   = 'admin_menu.php';
 *   require __DIR__ . '/includes/page_header.php';
 *
 * Usage depuis Nominateur/ :
 *   $backUrl = 'menu.php';
 *   require __DIR__ . '/../includes/page_header.php';
 */

$pageIconClass ??= 'me-2';
$backBtnClass  ??= 'btn btn-sm btn-light py-0';

// Fil d'Ariane : détection automatique depuis $backUrl
$_bcParent = null;
if (!empty($backUrl)) {
    if (str_contains($backUrl, 'admin_menu.php')) {
        $_bcParent = ['label' => 'Admin', 'url' => $backUrl];
    } elseif (str_contains($backUrl, 'menu.php')) {
        $_bcParent = ['label' => 'Nominateur', 'url' => $backUrl];
    }
}
?>
<?php if (function_exists('csrfToken')): ?>
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<?php endif; ?>
<?php if (function_exists('getConfig') && !empty($_SESSION['utilisateur'])): ?>
<script>
const NIJAC_CFG = <?= json_encode([
    'saison'       => getConfig('saison', ''),
    'departements' => getDeptActifs(),
    'isAdmin'      => !empty($_SESSION['utilisateur']['is_admin']),
    'dept'         => $_SESSION['utilisateur']['id_departement'] ?? null,
    'nom'          => trim(($_SESSION['utilisateur']['prenom'] ?? '') . ' ' . ($_SESSION['utilisateur']['nom'] ?? '')),
    'pageCode'     => $pageCode,
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
<!-- En-tête -->
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <?php if ($_bcParent): ?>
        <span style="font-size:.78rem;font-weight:400;opacity:.65;">
            <a href="<?= htmlspecialchars($_bcParent['url']) ?>" class="text-white text-decoration-none"><?= htmlspecialchars($_bcParent['label']) ?></a>
            <span class="mx-1">›</span>
        </span>
        <?php endif; ?>
        <i class="bi <?= htmlspecialchars($pageIcon) ?> <?= htmlspecialchars($pageIconClass) ?>"></i><?= htmlspecialchars($pageTitle) ?>
        <small class="opacity-75 ms-2">(<?= htmlspecialchars($pageCode) ?>)</small>
    </div>
    <?php if (!empty($backUrl)): ?>
    <a href="<?= htmlspecialchars($backUrl) ?>" class="<?= htmlspecialchars($backBtnClass) ?>" style="flex-shrink:0;">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
    <?php endif; ?>
</div>
