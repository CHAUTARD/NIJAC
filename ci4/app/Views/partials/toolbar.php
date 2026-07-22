<?php
/**
 * Barre utilisateur #toolbar : nom/département + rappel changement de mot de passe + bouton de bascule optionnel.
 * Requis : $tbNomComplet, $tbDepartement (chaîne vide/null si aucun département).
 * Optionnel : $tbId (def. 'toolbar'), $tbShowLabel (def. true — préfixe "Utilisateur : "),
 *             $tbShowPwdWarning (def. true — nécessite $changeLogin dans le <style> de la vue pour le CSS ts-pwd-warning),
 *             $tbSwitchTo (def. null — 'admin' pour le bouton "Menu administrateur", 'nominateur' pour "Menu nominateur"),
 *             $tbShowCsr (def. false — affiche le bouton "Menu CSR", avant $tbSwitchTo ; nécessite le CSS #btn-switch-csr dans la vue).
 */
$tbId             = $tbId ?? 'toolbar';
$tbShowLabel      = $tbShowLabel ?? true;
$tbShowPwdWarning = $tbShowPwdWarning ?? true;
$tbSwitchTo       = $tbSwitchTo ?? null;
$tbShowCsr        = $tbShowCsr ?? false;
?>
<div id="<?= esc($tbId) ?>">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i><?= $tbShowLabel ? 'Utilisateur : ' : '' ?><?= esc($tbNomComplet) ?><?= $tbDepartement ? ' (' . esc($tbDepartement) . ')' : '' ?>
    </span>
    <?php if ($tbShowPwdWarning): ?>
    <a class="ts-pwd-warning" href="<?= site_url('changer-mot-de-passe') ?>" id="lnk-chg-pwd" data-base="<?= site_url('changer-mot-de-passe') ?>">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
    <?php endif; ?>
    <?php if ($tbShowCsr || $tbSwitchTo): ?>
    <div style="display:flex;align-items:center;gap:.5rem;">
        <?php if ($tbShowCsr): ?>
        <a id="btn-switch-csr" href="<?= site_url('csr-menu') ?>" title="Basculer vers le menu CSR">
            <i class="bi bi-award-fill"></i>Menu CSR
        </a>
        <?php endif; ?>
        <?php if ($tbSwitchTo === 'admin'): ?>
        <a id="btn-switch-admin" href="<?= site_url('admin-menu') ?>" title="Basculer vers le menu administrateur">
            <i class="bi bi-shield-lock-fill"></i>Menu administrateur
        </a>
        <?php elseif ($tbSwitchTo === 'nominateur'): ?>
        <a id="btn-switch-nominateur" href="<?= site_url('nominateur-menu') ?>" title="Basculer vers le menu nominateur">
            <i class="bi bi-people-fill"></i>Menu nominateur
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
