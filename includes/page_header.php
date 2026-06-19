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
$backBtnClass  ??= 'btn btn-sm btn-light float-end py-0';
?>
<!-- En-tête -->
<div id="page-header">
    <i class="bi <?= htmlspecialchars($pageIcon) ?> <?= htmlspecialchars($pageIconClass) ?>"></i><?= htmlspecialchars($pageTitle) ?>
    <small class="opacity-75 ms-2">(<?= htmlspecialchars($pageCode) ?>)</small>
    <?php if (!empty($backUrl)): ?>
    <a href="<?= htmlspecialchars($backUrl) ?>" class="<?= htmlspecialchars($backBtnClass) ?>">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
    <?php endif; ?>
</div>
