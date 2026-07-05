<?php
/**
 * Pied de page #page-footer : barre de statut + mention copyright/version.
 * Optionnel : $pfStatusText (def. 'Prêt.'), $pfStatusAlign (def. null — 'left' pour mettre le
 *             message de statut à gauche et le copyright centré ; nécessite la CSS .pf-status-left
 *             dans la vue appelante).
 */
$pfStatusText  = $pfStatusText ?? 'Prêt.';
$pfStatusAlign = $pfStatusAlign ?? null;
?>
<div id="page-footer"<?= $pfStatusAlign === 'left' ? ' class="pf-status-left"' : '' ?>>
    <span id="status-bar"><?= esc($pfStatusText) ?></span>
    <span class="footer-copyright">
        &copy; <?= date('Y') ?> &mdash; Tous droits réservés &mdash;
        <img src="<?= base_url('img/logo_region.png') ?>" alt="" class="footer-logo" aria-hidden="true">
        Ligue Normandie de Tennis de Table &mdash; Version&nbsp;: <?= defined('APP_VERSION') ? APP_VERSION : '' ?>
    </span>
</div>
