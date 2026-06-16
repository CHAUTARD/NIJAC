<?php
/**
 * NIJAC – Bandeau utilisateur mutualisé
 *
 * Prérequis : les variables suivantes doivent être définies avant l'include :
 *   $nomComplet  (string) – nom complet de l'utilisateur
 *   $departement (string) – département (peut être vide)
 *   $changeLogin (bool)   – true si le mot de passe doit être changé
 *
 * Usage :
 *   Dans <head> : <?php require __DIR__ . '/includes/toolbar.php'; ?>  ← pour le CSS
 *   Dans <body> : idem, le CSS est ignoré une fois déjà émis
 *
 * En pratique : placer l'include une seule fois juste après <body>.
 * Le bloc <style> en début de body est valide HTML5.
 */
?>
<style>
    #toolbar {
        background: #f8fafc;
        border-bottom: 1px solid #dde5f0;
        padding: .3rem 1rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: .85rem;
        flex-shrink: 0;
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
</style>

<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">

<!-- Toolbar -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
    </span>
    <a class="ts-pwd-warning" href="changer_mot_de_passe.php" id="lnk-chg-pwd" data-base="changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<?php require __DIR__ . '/modal_mdp.php'; ?>
