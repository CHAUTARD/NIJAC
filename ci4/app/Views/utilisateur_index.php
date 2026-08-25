<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des utilisateurs (E009)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">

    <style>
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; }
        #panel-liste { width: 54%; }
        #tbl-utilisateurs tbody tr.inactif td { color: #bbb; }
        #txt-id { background: #f0f4fa; width: 80px; }
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php -->
<?= view('partials/page_header', [
    'phIcon' => 'people-fill', 'phTitle' => 'Gestion des utilisateurs', 'phCode' => 'E009',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
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

        <div class="row g-2 mb-2">
            <div class="col-auto">
                <label class="form-label">Id :</label>
                <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-login">Login (*) :</label>
            <input type="text" id="txt-login" class="form-control form-control-sm" maxlength="50" autocomplete="off">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Nom (*) :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-prenom">Prénom (*) :</label>
            <input type="text" id="txt-prenom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-email">Adresse email :</label>
            <input type="email" id="txt-email" class="form-control form-control-sm" maxlength="150">
        </div>

        <div class="mb-2">
            <label class="form-label" for="cbo-role">Rôle (*) :</label>
            <select id="cbo-role" class="form-select form-select-sm">
                <?php foreach ($roles as $r): ?>
                <option value="<?= esc($r) ?>" <?= $r === 'Nominateur' ? 'selected' : '' ?>><?= esc($r) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-2">
            <label class="form-label" for="cbo-dept">Département (*) :</label>
            <select id="cbo-dept" class="form-select form-select-sm" style="max-width:280px">
                <option value="">— Sélectionner —</option>
                <?php foreach ($deptActifs as $d): ?>
                <option value="<?= (int) $d['code'] ?>"><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-mdp">
                Mot de passe (*) :
                <small class="fw-normal text-muted" id="mdp-hint">(laisser vide = inchangé)</small>
            </label>
            <div class="input-group" style="max-width:320px">
                <input type="password" id="txt-mdp" class="form-control form-control-sm" maxlength="100" autocomplete="new-password">
                <button class="btn btn-sm btn-outline-secondary" type="button" id="btn-toggle-mdp" tabindex="-1" title="Afficher / masquer le mot de passe">
                    <i id="eye-mdp" class="bi bi-eye-slash"></i>
                </button>
            </div>
        </div>

        <div class="mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-actif" checked>
                <label class="form-check-label fw-bold" for="chk-actif">Actif</label>
            </div>
        </div>

        <div class="mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-change-login" checked>
                <label class="form-check-label" for="chk-change-login">Forcer changement de mot de passe</label>
            </div>
        </div>

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
        <div class="small text-muted mt-2">(*) Champs obligatoires (mot de passe obligatoire uniquement à la création)</div>

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
let   currentId = null; // null = nouvel utilisateur
const sortState = { col: null, asc: true };

// ── Utilitaires ───────────────────────────────────────────────────────────────
function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
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
                    $('<td>').text(u.Role),
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
        $('#txt-id').val(currentId);
        $('#txt-login').val(u.Login);
        $('#txt-nom').val(u.Nom);
        $('#txt-prenom').val(u.Prenom);
        $('#txt-email').val(u.Email || '');
        $('#cbo-role').val(u.Role);
        $('#cbo-dept').val(u.Id_Departement);
        $('#txt-mdp').val('');
        $('#chk-actif').prop('checked', parseInt(u.Actif) === 1);
        $('#chk-change-login').prop('checked', parseInt(u.ChangeLogin) === 1);
        $('#mdp-hint').show();
        $('#btn-supprimer').prop('disabled', currentId === MOI_ID);
        setStatus('');
    }, 'json');
}

// ── Nouveau ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-login').val('').trigger('focus');
    $('#txt-nom').val('');
    $('#txt-prenom').val('');
    $('#txt-email').val('');
    $('#cbo-role').val('Nominateur');
    $('#cbo-dept').val('');
    $('#txt-mdp').val('Change_On_Install');
    $('#chk-actif').prop('checked', true);
    $('#chk-change-login').prop('checked', true);
    $('#mdp-hint').hide();
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    const isNew = currentId === null;
    const payload = {
        login:        $('#txt-login').val().trim(),
        nom:          $('#txt-nom').val().trim(),
        prenom:       $('#txt-prenom').val().trim(),
        email:        $('#txt-email').val().trim(),
        role:         $('#cbo-role').val(),
        dept:         $('#cbo-dept').val(),
        mdp:          $('#txt-mdp').val(),
        actif:        $('#chk-actif').is(':checked') ? '1' : '0',
        change_login: $('#chk-change-login').is(':checked') ? '1' : '0',
    };
    const url    = isNew ? UTILISATEUR_BASE : `${UTILISATEUR_BASE}/${currentId}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
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

// ── Afficher / masquer le mot de passe ───────────────────────────────────────
$('#btn-toggle-mdp').on('click', function () {
    const $input = $('#txt-mdp');
    const hidden = $input.attr('type') === 'password';
    $input.attr('type', hidden ? 'text' : 'password');
    $('#eye-mdp').toggleClass('bi-eye-slash', !hidden).toggleClass('bi-eye', hidden);
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
