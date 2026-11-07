<?php
/**
 * Barre utilisateur #toolbar : nom/département + rappel changement de mot de passe + bouton de bascule optionnel.
 * Requis : $tbNomComplet, $tbDepartement (chaîne vide/null si aucun département).
 * Optionnel : $tbId (def. 'toolbar'), $tbShowLabel (def. true — préfixe "Utilisateur : "),
 *             $tbShowPwdWarning (def. true — l'alerte « Mot de passe à modifier » + la modale E006 ne sont rendues que si change_login est vrai en session),
 *             $tbSwitchTo (def. null — 'admin' pour le bouton "Menu administrateur", 'nominateur' pour "Menu nominateur"),
 *             $tbShowCsr (def. false — affiche le bouton "Menu CSR", avant $tbSwitchTo ; nécessite le CSS #btn-switch-csr dans la vue),
 *             $tbShowDefisc (def. false — affiche le bouton "Menu Défiscalisateur", avant $tbSwitchTo ; nécessite le CSS #btn-switch-defisc dans la vue),
 *             $tbShowCraConvoc (def. false — affiche le bouton "Menu CRA Convoc", avant $tbSwitchTo ; nécessite le CSS #btn-switch-craconvoc dans la vue),
 *             $tbShowJa (def. false — affiche le bouton "Menu JA" (E011), avant $tbSwitchTo ; nécessite le CSS #btn-switch-ja dans la vue),
 *             $tbJaActif (def. false — fiche JA liée utilisable : bouton = POST changer-compte action=ja ; sinon bouton grisé inactif « Aucune fiche JA liée : voir EA86 »),
 *             $tbRetourCompte (def. false — mode JA d'un compte non-JA (E011) : bouton de retour = POST changer-compte action=retour ; nécessite le CSS #btn-switch-retour dans la vue),
 *             $tbExtra (def. '' — HTML brut ajouté après le nom, ex. un indicateur d'état propre à l'écran),
 *             $tbRetourLibelle (def. 'Menu nominateur'), $tbRetourIcone (def. 'bi-people-fill') — affichage seulement, le retour relit les rôles en base.
 */
$tbId             = $tbId ?? 'toolbar';
$tbShowLabel      = $tbShowLabel ?? true;
$tbShowPwdWarning = $tbShowPwdWarning ?? true;
$tbSwitchTo       = $tbSwitchTo ?? null;
$tbShowCsr        = $tbShowCsr ?? false;
$tbShowDefisc     = $tbShowDefisc ?? false;
$tbShowCraConvoc  = $tbShowCraConvoc ?? false;
$tbShowJa         = $tbShowJa ?? false;
$tbJaActif        = $tbJaActif ?? false;
$tbRetourCompte   = $tbRetourCompte ?? false;
$tbRetourLibelle  = $tbRetourLibelle ?? 'Menu nominateur';
$tbRetourIcone    = $tbRetourIcone ?? 'bi-people-fill';
$tbExtra          = $tbExtra ?? '';
// L'alerte mot de passe (et sa modale E006) n'est rendue que si le changement est exigé : plus de CSS d'affichage dans les vues.
$tbChangeLogin    = $tbShowPwdWarning && !empty($_SESSION['utilisateur']['change_login']);
?>
<div id="<?= esc($tbId) ?>">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i><?= $tbShowLabel ? 'Utilisateur : ' : '' ?><?= esc($tbNomComplet) ?><?= $tbDepartement ? ' (' . esc($tbDepartement) . ')' : '' ?>
    </span>
    <?= $tbExtra ?>
    <?php if ($tbChangeLogin): ?>
    <a class="ts-pwd-warning" href="<?= site_url('changer-mot-de-passe') ?>" id="lnk-chg-pwd" data-base="<?= site_url('changer-mot-de-passe') ?>">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
    <?php endif; ?>
    <?php if ($tbShowCsr || $tbShowDefisc || $tbShowCraConvoc || $tbShowJa || $tbSwitchTo || $tbRetourCompte): ?>
    <div style="display:flex;align-items:center;gap:.5rem;">
        <?php if ($tbShowCsr): ?>
        <a id="btn-switch-csr" href="<?= site_url('csr-menu') ?>" title="Basculer vers le menu CSR">
            <i class="bi bi-award-fill"></i>Menu CSR
        </a>
        <?php endif; ?>
        <?php if ($tbShowDefisc): ?>
        <a id="btn-switch-defisc" href="<?= site_url('defiscalisateur-menu') ?>" title="Basculer vers le menu Défiscalisateur">
            <i class="bi bi-cash-coin"></i>Menu Défiscalisateur
        </a>
        <?php endif; ?>
        <?php if ($tbShowCraConvoc): ?>
        <a id="btn-switch-craconvoc" href="<?= site_url('cra-convoc-menu') ?>" title="Basculer vers le menu CRA Convoc">
            <i class="bi bi-envelope-paper-fill"></i>Menu CRA Convoc
        </a>
        <?php endif; ?>
        <?php if ($tbShowJa): ?>
        <?php if ($tbJaActif): ?>
        <?php /* Bascule en mode JA (E011) sans reconnexion : fiche liée relue en base côté serveur (ChangerCompte). */ ?>
        <form method="post" action="<?= site_url('changer-compte') ?>" class="m-0 d-inline-flex"><?= csrf_field() ?><input type="hidden" name="action" value="ja">
            <button type="submit" id="btn-switch-ja" title="Basculer vers le menu JA (E011)">
                <i class="bi bi-person-badge-fill"></i>Menu JA
            </button>
        </form>
        <?php else: ?>
        <span id="btn-switch-ja" class="tb-inactif" role="link" aria-disabled="true" title="Aucune fiche JA liée : voir EA86">
            <i class="bi bi-person-badge-fill"></i>Menu JA
        </span>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($tbRetourCompte): ?>
        <?php /* Mode JA d'un compte non-JA : retour au compte d'origine sans reconnexion (rôles relus en base par ChangerCompte). */ ?>
        <form method="post" action="<?= site_url('changer-compte') ?>" class="m-0 d-inline-flex"><?= csrf_field() ?><input type="hidden" name="action" value="retour">
            <button type="submit" id="btn-switch-retour" title="Revenir au menu d'origine, sans vous reconnecter">
                <i class="bi <?= esc($tbRetourIcone, 'attr') ?>"></i><?= esc($tbRetourLibelle) ?>
            </button>
        </form>
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
<?php if ($tbChangeLogin) { require __DIR__ . '/../_modal_mdp.php'; } ?>
<?php /* Si la vue a un #page-header, la barre utilisateur y est regroupée (avant le bouton Retour) ; sinon elle reste en place. */ ?>
<script>
(function () {
    var tb = document.getElementById(<?= json_encode($tbId) ?>), ph = document.getElementById('page-header');
    if (!tb || !ph || ph.contains(tb)) return;
    var retour = (function (e) { return e && /^(A|BUTTON)$/.test(e.tagName) ? e : null; })(ph.lastElementChild);
    ph.insertBefore(tb, retour);
    tb.classList.add('in-header');
    /* Boutons « Menu … » à droite, avec « Retour ». */
    var droite = document.createElement('div');
    droite.className = 'ph-droite';
    var menus = tb.querySelector(':scope > div');
    ph.querySelectorAll(':scope > [data-ph-droite]').forEach(function (e) { droite.appendChild(e); });
    if (menus) droite.appendChild(menus);
    if (retour) droite.appendChild(retour);
    ph.appendChild(droite);
})();
</script>
