<?php
require_once __DIR__ . '/../config/db.php';

if (!isset($statusInitial)) $statusInitial = '';
$_logoBase = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/Nominateur/') ? '../' : '';
?>
<style>
#page-footer {
    background: #e8eef7;
    border-top: 1px solid #c8d4e8;
    padding: .25rem 1rem;
    font-size: .8rem;
    font-family: 'Segoe UI', system-ui, sans-serif;
    display: flex;
    justify-content: center;
    align-items: center;
    flex-shrink: 0;
    gap: 1rem;
}
#status-bar {
    color: #374151;
    min-height: 18px;
}
.footer-copyright {
    color: #6b7280;
    white-space: nowrap;
}
.footer-logo {
    height: 20px;
    width: auto;
    opacity: .75;
}
</style>
<div id="page-footer">
    <span id="status-bar"><?= $statusInitial ?></span>
    <span class="footer-copyright">
        &copy; <?= date('Y') ?> &mdash; Tous droits réservés<?= ($footerBreak ?? false) ? '<br>' : ' &mdash; ' ?>
        <img src="<?= $_logoBase ?>img/logo_region.png" alt="" class="footer-logo" aria-hidden="true">
        Ligue Normandie de Tennis de Table &mdash; Version&nbsp;: <?= defined('APP_VERSION') ? APP_VERSION : '' ?>
    </span>
</div>
</body>
</html>
