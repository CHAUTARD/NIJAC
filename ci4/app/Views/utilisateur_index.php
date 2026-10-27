<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des utilisateurs (EA86)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">

    <style>
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; }
        #panel-liste { width: 54%; }
        #tbl-utilisateurs tbody tr.inactif td { color: #bbb; }
        #txt-id { background: #f0f4fa; width: 80px; }

        /* Cadres (fieldset) du formulaire */
        .cadre-form { border: 1px solid #c8d4e8; border-radius: 6px; padding: .5rem .8rem .7rem; margin-bottom: .85rem; }
        .cadre-form > legend {
            float: none; width: auto; font-size: .8rem; font-weight: 700;
            color: #1a3a6b; padding: 0 .4rem; margin-bottom: .35rem;
        }
        .cadre-form .form-label { margin-bottom: .1rem; }
        #mdp-genere-box code { user-select: all; font-weight: 700; }
        #roles-box .role-ligne + .role-ligne { border-top: 1px dashed #e3e9f3; }
        #roles-box .role-ligne { padding: .1rem 0; }
        .role-principal { font-weight: 700; }
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php -->
<?= view('partials/page_header', [
    'phIcon' => 'people-fill', 'phTitle' => 'Gestion des utilisateurs', 'phCode' => 'EA86',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu') . '#tab-tables', 'phBackUrl' => site_url('admin-menu') . '#tab-tables',
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Split -->
<div id="split-container">

    <!-- ── Liste ── -->
    <div id="panel-liste">
        <div id="liste-header">Utilisateurs</div>
        <div id="table-wrapper">
            <table id="tbl-utilisateurs">
                <thead>
                    <tr>
                        <th data-col="0">Login<span class="sort-icon"></span></th>
                        <th data-col="1">Nom<span class="sort-icon"></span></th>
                        <th data-col="2">Prénom<span class="sort-icon"></span></th>
                        <th data-col="3">Rôle<span class="sort-icon"></span></th>
                        <th data-col="4">Dept<span class="sort-icon"></span></th>
                        <th data-col="5">Actif<span class="sort-icon"></span></th>
                        <th data-col="6">Email<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="7" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Formulaire ── -->
    <div id="panel-form">

        <fieldset class="cadre-form">
            <legend>Identifiant</legend>
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label">Id :</label>
                    <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
                </div>
                <div class="col">
                    <label class="form-label" for="txt-login">Login (*) :</label>
                    <input type="text" id="txt-login" class="form-control form-control-sm" maxlength="150" autocomplete="off">
                </div>
            </div>
        </fieldset>

        <fieldset class="cadre-form">
            <legend>Information personnelle</legend>
            <div class="row g-2 mb-2">
                <div class="col">
                    <label class="form-label" for="txt-prenom">Prénom (*) :</label>
                    <input type="text" id="txt-prenom" class="form-control form-control-sm" maxlength="100">
                </div>
                <div class="col">
                    <label class="form-label" for="txt-nom">Nom (*) :</label>
                    <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
                </div>
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-email">Adresse email (*) :</label>
                <input type="email" id="txt-email" class="form-control form-control-sm" maxlength="150" required
                       title="Obligatoire : le code de sécurité de connexion est envoyé à cette adresse">
                <div class="form-text small">Le code de sécurité de connexion (E010) est envoyé à cette adresse.</div>
            </div>

            <div class="mb-2">
                <label class="form-label">Rôles (*) <span class="small text-muted fw-normal">— cochez au moins un rôle, puis choisissez le principal (menu d'arrivée) ; le rôle JA est exclusif (aucun autre rôle)</span> :</label>
                <div id="roles-box" class="border rounded px-2 py-1">
                    <?php foreach ($roles as $code => $libelle): $idr = 'role-' . preg_replace('/[^A-Za-z0-9]/', '_', (string) $code); ?>
                    <div class="role-ligne d-flex align-items-center justify-content-between">
                        <div class="form-check mb-0">
                            <input class="form-check-input chk-role" type="checkbox" id="chk-<?= esc($idr, 'attr') ?>" value="<?= esc((string) $code, 'attr') ?>">
                            <label class="form-check-label" for="chk-<?= esc($idr, 'attr') ?>"><?= esc($libelle) ?></label>
                        </div>
                        <div class="form-check mb-0 small">
                            <input class="form-check-input rad-principal" type="radio" name="role-principal" id="rad-<?= esc($idr, 'attr') ?>" value="<?= esc((string) $code, 'attr') ?>">
                            <label class="form-check-label text-muted" for="rad-<?= esc($idr, 'attr') ?>">principal</label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col">
                    <label class="form-label" for="cbo-dept">Département (*) :</label>
                    <select id="cbo-dept" class="form-select form-select-sm">
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($deptActifs as $d): ?>
                        <option value="<?= (int) $d['CodeDept'] ?>"><?= (int) $d['CodeDept'] ?> — <?= esc($d['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Fiche juge-arbitre (rôle JA uniquement : l'identifiant du compte = n° de la fiche, menu E011) -->
            <div id="bloc-fiche-ja" class="mb-2 p-2 border rounded bg-light d-none">
                <label class="form-label" for="txt-fiche-ja">Fiche juge-arbitre (*) :</label>
                <input type="hidden" id="hid-id-ja" value="">
                <div id="lbl-fiche-ja" class="small fw-bold mb-1 text-secondary">Aucune fiche sélectionnée.</div>
                <input type="search" id="txt-fiche-ja" class="form-control form-control-sm" autocomplete="off"
                       placeholder="Rechercher par nom, prénom ou n° de licence (2 caractères min.)">
                <div id="res-fiche-ja" class="list-group small mt-1" style="max-height:180px;overflow:auto;"></div>
                <div class="form-text small">Obligatoire pour le rôle JA (JA actifs JA1 proposés en premier) ; l'identifiant du compte sera le n° de la fiche (une fiche = un seul compte).</div>
            </div>
            <!-- Compte de rôle JA : convention Id_Utilisateur = n° de fiche, Login = Email, seul l'email modifiable -->
            <div id="info-compte-ja" class="alert alert-warning py-1 px-2 small mb-2 d-none"></div>

            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-actif" checked>
                <label class="form-check-label fw-bold" for="chk-actif">Actif</label>
            </div>
        </fieldset>

        <fieldset class="cadre-form">
            <legend>Écraser mot de passe</legend>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-ecraser">
                <label class="form-check-label fw-bold" for="chk-ecraser">Écraser</label>
            </div>
            <div class="small text-muted mt-1">
                Coché : un mot de passe aléatoire est généré et l'utilisateur devra le changer à sa première connexion.
                <span id="ecraser-force" class="d-none fw-bold text-secondary">— obligatoire à la création.</span>
            </div>
            <div id="mdp-genere-box" class="alert alert-warning py-1 px-2 mt-2 mb-0 small d-none">
                Mot de passe provisoire à communiquer : <code id="mdp-genere-code"></code>
            </div>
        </fieldset>

        <!-- Boutons -->
        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3" id="btn-nouveau">
                <i class="bi bi-plus-circle me-1"></i>Nouveau
            </button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer">
                <i class="bi bi-floppy me-1"></i>Enregistrer
            </button>
            <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer" disabled>
                <i class="bi bi-trash3 me-1"></i>Supprimer
            </button>
        </div>

        <!-- Légende champs obligatoires -->
        <div class="small text-muted mt-2">(*) Champs obligatoires</div>

        <!-- Statut -->
        <div id="form-status" class="mt-3 small fw-bold"></div>

    </div><!-- /panel-form -->
</div><!-- /split-container -->

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const UTILISATEUR_BASE = '<?= site_url('utilisateur') ?>';
const MOI_ID = <?= (int) $moiId ?>;
const ROLES  = <?= json_encode((object) $roles, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>; // Code => Libellé
let   currentId = null; // null = nouvel utilisateur
let   loginCharge = ''; // login chargé dans le formulaire (détection renommage vs création oubliée)
let   rolesCharges = []; // rôles du compte chargé ([] = nouveau) — ['JA'] (exclusif) : tout verrouillé sauf l'email
const sortState = { col: null, asc: true };

// ── Utilitaires ───────────────────────────────────────────────────────────────
function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

// ── Rôles (cases à cocher + rôle principal) ──────────────────────────────────
function rolesCoches()  { return $('.chk-role:checked').map((_, e) => e.value).get(); }
function rolePrincipal() { return $('.rad-principal:checked').val() || ''; }
function definirRoles(roles, principal) {
    $('.chk-role').each(function () { this.checked = roles.includes(this.value); });
    $('.rad-principal').each(function () { this.checked = this.value === principal; });
    majRoles();
}
// Compte JA existant (JA seul) : rôles verrouillés. Compte hérité cumulant JA et d'autres rôles
// (antérieur à la règle JA exclusif) : à régulariser (JA seul, ou sans JA).
function estCompteJa()   { return currentId !== null && rolesCharges.length === 1 && rolesCharges[0] === 'JA'; }
function estJaCumule()   { return currentId !== null && rolesCharges.includes('JA') && rolesCharges.length > 1; }
// Rôle JA EXCLUSIF : JA coché → les autres rôles décochés et désactivés (sauf compte hérité cumulé, dont
// les rôles cochés restent décochables) ; compte existant non-JA : JA non cochable (Id_Utilisateur = n° de
// fiche) ; compte JA existant : tout verrouillé. Principal ⊂ cochés (JA seul → JA principal) ; son propre
// compte : Administrateur non retirable, Actif verrouillé. Tout est revérifié côté serveur.
function majRoles() {
    const coches = rolesCoches();
    const jaCoche = coches.includes('JA');
    const compteJa = estCompteJa();
    $('.chk-role').each(function () {
        let off;
        if (compteJa) {
            off = true;
        } else if (this.value === 'JA') {
            off = currentId !== null && !rolesCharges.includes('JA');
        } else {
            off = jaCoche && !this.checked;
        }
        if (this.value === 'Administrateur' && currentId !== null && currentId === MOI_ID && rolesCharges.includes('Administrateur')) off = true;
        $(this).prop('disabled', off);
    });
    $('.rad-principal').each(function () {
        const possible = !compteJa && coches.includes(this.value) && (!jaCoche || coches.length === 1);
        $(this).prop('disabled', !possible);
        if (!possible && !(compteJa && this.value === 'JA')) this.checked = false;
    });
    if (jaCoche && coches.length === 1) $('.rad-principal').filter(function () { return this.value === 'JA'; }).prop('checked', true);
    if (!rolePrincipal() && coches.length) {
        $('.rad-principal').filter(function () { return this.value === coches[0]; }).prop('checked', true);
    }
}
function celluleRoles(u) {
    const $td = $('<td>');
    (u.Roles || '').split(',').filter(Boolean).forEach((r, i) => {
        if (i) $td.append(', ');
        $td.append($('<span>').toggleClass('role-principal', r === u.RolePrincipal)
            .attr('title', r === u.RolePrincipal ? 'Rôle principal' : '').text(ROLES[r] || r));
    });
    return $td;
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function chargerListe(selectId = null) {
    $.get(`${UTILISATEUR_BASE}/data`, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="7" class="text-center text-muted py-3">Aucun utilisateur.</td></tr>');
            return;
        }
        res.data.forEach(u => {
            const actif = parseInt(u.Actif) === 1;
            const $tr = $('<tr>')
                .toggleClass('inactif', !actif)
                .attr('data-id', u.Id_Utilisateur)
                .append(
                    $('<td>').text(u.Login),
                    $('<td>').text(u.Nom),
                    $('<td>').text(u.Prenom),
                    celluleRoles(u),
                    $('<td class="text-center">').text(u.Id_Departement),
                    $('<td class="text-center">').text(actif ? '✔' : ''),
                    $('<td>').text(u.Email || '')
                )
                .on('click', function () { selectionnerLigne($(this)); });
            $body.append($tr);
        });

        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json');
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = $tr.data('id');
    $.get(`${UTILISATEUR_BASE}/data/${id}`, function (res) {
        if (!res.ok) return;
        const u = res.data;
        currentId = parseInt(u.Id_Utilisateur);
        loginCharge = u.Login || '';
        rolesCharges = Array.isArray(u.Roles) ? u.Roles : [];
        $('#txt-id').val(currentId);
        $('#txt-login').val(u.Login);
        $('#txt-nom').val(u.Nom);
        $('#txt-prenom').val(u.Prenom);
        $('#txt-email').val(u.Email || '');
        definirRoles(rolesCharges, u.RolePrincipal || '');
        definirFicheJa(rolesCharges.includes('JA') ? u.Id_Utilisateur : '', u.FicheJa || ''); // compte JA : fiche de même Id
        majBlocFicheJa();
        $('#cbo-dept').val(u.Id_Departement);
        $('#chk-actif').prop('checked', parseInt(u.Actif) === 1);
        $('#chk-ecraser').prop('checked', false).prop('disabled', false);
        $('#ecraser-force').addClass('d-none');
        $('#mdp-genere-box').addClass('d-none');
        $('#btn-supprimer').prop('disabled', currentId === MOI_ID);
        majRoles();
        majVerrousJa();
        setStatus('');
    }, 'json');
}

// ── Nouveau ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    currentId = null;
    loginCharge = '';
    rolesCharges = [];
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-login').val('').trigger('focus');
    $('#txt-nom').val('');
    $('#txt-prenom').val('');
    $('#txt-email').val('');
    definirRoles(['Nominateur'], 'Nominateur');
    definirFicheJa('', '');
    majBlocFicheJa();
    $('#cbo-dept').val('');
    $('#chk-actif').prop('checked', true);
    // À la création, le mot de passe aléatoire est toujours généré : case forcée.
    $('#chk-ecraser').prop('checked', true).prop('disabled', true);
    $('#ecraser-force').removeClass('d-none');
    $('#mdp-genere-box').addClass('d-none');
    $('#btn-supprimer').prop('disabled', true);
    majVerrousJa();
    setStatus('');
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    const isNew = currentId === null;
    const roles = rolesCoches();
    const jaCreation = isNew && roles.includes('JA');
    if (jaCreation || estCompteJa()) $('#txt-login').val($('#txt-email').val().trim()); // Login = Email
    const payload = {
        login:     $('#txt-login').val().trim(),
        nom:       $('#txt-nom').val().trim(),
        prenom:    $('#txt-prenom').val().trim(),
        email:     $('#txt-email').val().trim(),
        roles:     roles,
        principal: rolePrincipal(),
        id_ja:     jaCreation ? $('#hid-id-ja').val() : '',
        dept:    $('#cbo-dept').val(),
        actif:   $('#chk-actif').is(':checked') ? '1' : '0',
        ecraser: (isNew || $('#chk-ecraser').is(':checked')) ? '1' : '0',
    };
    // Email obligatoire (code de sécurité E010) — revérifié côté serveur.
    if (payload.email === '' || !document.getElementById('txt-email').checkValidity()) {
        const msg = payload.email === '' ? "L'adresse email est obligatoire." : 'Adresse email invalide.';
        toast(msg, false); setStatus(msg, false); $('#txt-email').trigger('focus');
        return;
    }
    // Rôle JA exclusif — revérifié côté serveur.
    if (roles.includes('JA') && roles.length > 1) {
        const msg = 'Le rôle JA est exclusif : décochez JA ou les autres rôles.';
        toast(msg, false); setStatus(msg, false);
        return;
    }
    // Au moins un rôle + rôle principal parmi les cochés — revérifié côté serveur.
    if (!roles.length || !roles.includes(payload.principal)) {
        const msg = !roles.length ? 'Cochez au moins un rôle.' : 'Choisissez le rôle principal parmi les rôles cochés.';
        toast(msg, false); setStatus(msg, false);
        return;
    }
    // Création d'un compte JA : fiche juge-arbitre obligatoire — revérifié côté serveur.
    if (jaCreation && !payload.id_ja) {
        const msg = 'La fiche juge-arbitre est obligatoire pour le rôle JA.';
        toast(msg, false); setStatus(msg, false); $('#txt-fiche-ja').trigger('focus');
        return;
    }
    const url    = isNew ? UTILISATEUR_BASE : `${UTILISATEUR_BASE}/${currentId}`;
    const method = isNew ? 'POST' : 'PUT';

    function envoyer() {
    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (!res.ok) { toast(res.msg, false); setStatus(res.msg, false); return; }
        toast(res.msg);
        currentId = res.id;
        loginCharge = payload.login;
        rolesCharges = payload.roles.slice();
        $('#txt-id').val(res.id);
        $('#chk-ecraser').prop('checked', false).prop('disabled', false);
        $('#ecraser-force').addClass('d-none');
        majRoles();
        majBlocFicheJa();
        majVerrousJa();
        if (res.mdp) {
            $('#mdp-genere-code').text(res.mdp);
            $('#mdp-genere-box').removeClass('d-none');
        } else {
            $('#mdp-genere-box').addClass('d-none');
        }
        chargerListe();   // rafraîchit la liste sans re-sélection (préserve l'encadré mot de passe)
        $('#btn-supprimer').prop('disabled', currentId === MOI_ID);
    });
    }

    // Garde-fou : on est en modification et le login a changé -> soit renommage
    // volontaire, soit « Nouveau » oublié après avoir sélectionné une ligne.
    if (!isNew && payload.login !== loginCharge) {
        nijacConfirm(
            `Vous modifiez le compte n°${currentId} : son login passe de « ${loginCharge} » à « ${payload.login} ».\n\n`
            + `Pour créer un NOUVEAU compte, cliquez d'abord sur « Nouveau ».`,
            envoyer,
            null,
            { type: 'warning', title: 'Modification du login', confirmLabel: 'Modifier ce compte' }
        );
        return;
    }
    envoyer();
});

// ── Supprimer ─────────────────────────────────────────────────────────────────
$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const login = $('#txt-login').val();
    const nom   = ($('#txt-nom').val() + ' ' + $('#txt-prenom').val()).trim();
    nijacConfirm(`Supprimer l'utilisateur « ${login} » (${nom}) ?`, function () {
        $.ajax({ url: `${UTILISATEUR_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// ── Fiche juge-arbitre (rôle JA) ──────────────────────────────────────────────
function definirFicheJa(id, libelle) {
    $('#hid-id-ja').val(id);
    $('#lbl-fiche-ja').text(id ? libelle || String(id) : 'Aucune fiche sélectionnée.')
        .toggleClass('text-success', !!id).toggleClass('text-secondary', !id);
    $('#txt-fiche-ja').val('');
    $('#res-fiche-ja').empty();
}
function majBlocFicheJa() {
    $('#bloc-fiche-ja').toggleClass('d-none', !rolesCoches().includes('JA'));
}
$('.chk-role').on('change', function () {
    // Rôle JA exclusif : cocher JA décoche les autres rôles ; cocher un autre rôle décoche JA.
    if (this.checked) {
        const ja = this.value === 'JA';
        $('.chk-role').filter(function () { return ja ? this.value !== 'JA' : this.value === 'JA'; }).prop('checked', false);
    }
    majRoles();
    majBlocFicheJa();
    majVerrousJa();
});

// Compte JA (EA86, rôle JA exclusif) : création = login, nom, prénom déduits (Login = Email, Nom = fiche,
// Prénom vide, Id = n° de fiche, JA seul) ; modification = SEUL l'email est modifiable (le login suit),
// tout le reste est verrouillé. Compte hérité cumulant JA et d'autres rôles : avertissement, champs libres
// (à régulariser : JA seul, ou sans JA). Revérifié côté serveur.
function majVerrousJa() {
    const jaExistant = estCompteJa();
    const jaCreation = currentId === null && rolesCoches().includes('JA');
    const cumule     = estJaCumule();
    const ja = jaExistant || jaCreation;
    $('#txt-login').prop('disabled', ja);
    $('#txt-nom, #txt-prenom').prop('disabled', ja);
    $('#cbo-dept').prop('disabled', jaExistant);
    $('#chk-actif').prop('disabled', jaExistant || (currentId !== null && currentId === MOI_ID));
    if (jaExistant) $('#chk-ecraser').prop('checked', false).prop('disabled', true);
    $('#txt-fiche-ja').prop('disabled', currentId !== null);
    if (ja) $('#txt-login').val($('#txt-email').val().trim());
    if (jaCreation) $('#txt-prenom').val('');
    $('#info-compte-ja').toggleClass('d-none', !ja && !cumule).text(cumule
        ? 'Compte cumulant JA et d\'autres rôles (antérieur à la règle « JA exclusif ») : décochez JA, ou gardez JA seul (fiche de même identifiant requise).'
        : jaExistant
        ? 'Compte JA (rôle exclusif) : seule l\'adresse email est modifiable, le login la suit ; nouveau mot de passe depuis l\'onglet « Invitation JA » d\'EN15.'
        : 'Compte JA : identifiant = n° de la fiche, login = adresse email, nom repris de la fiche, prénom vide, JA seul rôle (exclusif). Mot de passe provisoire affiché après création.');
}
$('#txt-email').on('input', function () {
    if (estCompteJa() || (currentId === null && rolesCoches().includes('JA'))) {
        $('#txt-login').val($(this).val().trim());
    }
});

let rechercheJaTimer = null;
$('#txt-fiche-ja').on('input', function () {
    clearTimeout(rechercheJaTimer);
    const q = $(this).val().trim();
    const $res = $('#res-fiche-ja').empty();
    if (q.length < 2) return;
    rechercheJaTimer = setTimeout(function () {
        $.getJSON(`${UTILISATEUR_BASE}/fiches-ja`, { q }, function (r) {
            $res.empty();
            if (!r.ok) return;
            if (!r.data.length) { $res.append($('<div class="list-group-item text-muted">').text('Aucune fiche trouvée.')); return; }
            r.data.forEach(j => {
                const lib = j.Id_JA + ' — ' + ((j.Nom || '') + ' ' + (j.Prenom || '')).trim();
                // Identifiant du compte = n° de fiche : déjà porté par un compte (JA ou autre rôle) → indisponible.
                const prise = !!j.compte_login && parseInt(j.Id_JA, 10) !== currentId;
                const $it = $('<button type="button" class="list-group-item list-group-item-action py-1">')
                    .text(lib + (parseInt(j.JA1, 10) === 1 ? '' : ' (inactif JA1)')
                        + (prise ? ((j.compte_role || '').split(',').includes('JA') ? ' — compte JA déjà créé (« ' + j.compte_login + ' »)'
                                                           : ' — identifiant déjà pris par « ' + j.compte_login + ' » (' + j.compte_role + ')') : ''))
                    .prop('disabled', prise)
                    .on('click', function () {
                        definirFicheJa(j.Id_JA, lib);
                        // Compte JA : nom de la fiche, prénom vide, login = email (déduits, revérifiés côté serveur).
                        $('#txt-nom').val(j.Nom || '');
                        $('#txt-prenom').val('');
                        if (!$('#txt-email').val().trim()) $('#txt-email').val(j.Email || '');
                        majVerrousJa();
                    });
                $res.append($it);
            });
        });
    }, 300);
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-utilisateurs thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
