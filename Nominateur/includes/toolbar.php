<?php
/**
 * NIJAC – Bandeau utilisateur mutualisé (pages Nominateur)
 *
 * Prérequis : les variables suivantes doivent être définies avant l'include :
 *   $nomComplet  (string) – nom complet de l'utilisateur
 *   $departement (string) – département (peut être vide)
 *   $changeLogin (bool)   – true si le mot de passe doit être changé
 *   $isAdmin     (bool)   – true si l'utilisateur est administrateur
 *
 * Usage : <?php require __DIR__ . '/includes/toolbar.php'; ?>
 */
?>
<style>
    #toolbar {
        background: #c0ffff;
        border-bottom: 1px solid #90cccc;
        padding: .3rem 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: .85rem;
        flex-shrink: 0;
        gap: .75rem;
    }
    #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
    #toolbar .ts-pwd-warning {
        display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
        align-items: center;
        gap: .35rem;
        color: #c00;
        font-weight: 700;
        cursor: pointer;
        text-decoration: underline dotted;
    }
    #toolbar .ts-pwd-warning:hover { color: #900; }
    #btn-switch-admin {
        display: <?= $isAdmin ? 'inline-flex' : 'none' ?>;
        align-items: center;
        gap: .35rem;
        padding: .25rem .75rem;
        background: var(--nijac-blue);
        color: #fff;
        border: none;
        border-radius: 5px;
        font-size: .82rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: background .15s;
    }
    #btn-switch-admin:hover { background: #0f2550; color: #fff; }
</style>

<!-- Toolbar -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
    </span>
    <a class="ts-pwd-warning" href="../changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
    <a id="btn-switch-admin" href="../admin_menu.php" title="Basculer vers le menu administrateur">
        <i class="bi bi-shield-lock-fill"></i>Menu administrateur
    </a>
</div>
