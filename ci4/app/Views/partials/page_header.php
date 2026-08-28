<?php
/**
 * Cartouche #page-header standard : breadcrumb optionnel + icône + titre + badge écran + bouton Retour.
 * Requis : $phIcon (suffixe bootstrap-icons, ex. 'people-fill'), $phTitle, $phCode (ex. 'EA86'), $phBackUrl.
 * Optionnel : $phCrumbLabel/$phCrumbUrl (pas de breadcrumb si absents), $phCrumbColor (def. #cfe0ff),
 *             $phBadgeColor (def. #cfe0ff), $phBadgeOpacityOnly (def. false — variante "opacity-75" sans couleur, cf. EA98).
 */
$phCrumbColor       = $phCrumbColor ?? '#cfe0ff';
$phBadgeColor       = $phBadgeColor ?? '#cfe0ff';
$phBadgeOpacityOnly = $phBadgeOpacityOnly ?? false;
?>
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <?php if (!empty($phCrumbLabel)): ?>
        <span style="font-size:.78rem;font-weight:400;">
            <a href="<?= esc($phCrumbUrl) ?>" style="color:<?= esc($phCrumbColor) ?>;text-decoration:none;"><?= esc($phCrumbLabel) ?></a>
            <span class="mx-1" style="color:<?= esc($phCrumbColor) ?>;">&rsaquo;</span>
        </span>
        <?php endif; ?>
        <i class="bi bi-<?= esc($phIcon) ?> me-2"></i><?= esc($phTitle) ?>
        <?php if ($phBadgeOpacityOnly): ?>
        <small class="opacity-75 ms-2">(<?= esc($phCode) ?>)</small>
        <?php else: ?>
        <small class="ms-2" style="color:<?= esc($phBadgeColor) ?>;">(<?= esc($phCode) ?>)</small>
        <?php endif; ?>
    </div>
    <a href="<?= esc($phBackUrl) ?>" class="btn btn-sm py-0" style="flex-shrink:0;background:#fff;color:#1a3a6b;border:1px solid #fff;">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
</div>
