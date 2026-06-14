<?php $statusInitial ??= ''; ?>
<style>
#page-footer {
    background: #e8eef7;
    border-top: 1px solid #c8d4e8;
    padding: .25rem 1rem;
    font-size: .8rem;
    font-family: 'Segoe UI', system-ui, sans-serif;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    gap: 1rem;
}
#status-bar {
    color: #374151;
    flex: 1;
    min-height: 18px;
}
.footer-copyright {
    color: #6b7280;
    white-space: nowrap;
}
</style>
<div id="page-footer">
    <span id="status-bar"><?= $statusInitial ?></span>
    <span class="footer-copyright">&copy; <?= date('Y') ?> NIJAC &mdash; Tous droits réservés &mdash; Ligue Normandie de Tennis de Table</span>
</div>
