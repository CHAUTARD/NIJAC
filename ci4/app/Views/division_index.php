<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= esc($csrfToken) ?>">
    <title>NIJAC – Gestion des divisions (E010)</title>

    <link rel="stylesheet" href="/asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="/asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

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

        #split-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        #panel-liste {
            width: 55%;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
        }

        #liste-header {
            background: steelblue;
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            padding: .4rem .75rem;
            flex-shrink: 0;
        }

        #table-wrapper { flex: 1; overflow-y: auto; }

        #tbl-divisions {
            width: 100%;
            font-size: .85rem;
            border-collapse: collapse;
        }

        #tbl-divisions thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        #tbl-divisions thead th[data-col] { cursor: pointer; user-select: none; }
        #tbl-divisions thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-divisions thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-divisions thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-divisions thead th[data-col]:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-divisions tbody tr {
            cursor: pointer;
            border-bottom: 1px solid #e0e8f0;
        }
        #tbl-divisions tbody tr:nth-child(even) { background: #f0f5fb; }
        #tbl-divisions tbody tr:hover   { background: #dce8f8; }
        #tbl-divisions tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-divisions tbody td { padding: .3rem .5rem; }

        #panel-form {
            flex: 1;
            padding: 1rem 1.5rem;
            overflow-y: auto;
            background: #fff;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        .form-control, .form-select { font-size: .9rem; }

        #txt-id { background: #f0f4fa; width: 100px; }

        #panel-boutons { display: flex; gap: .6rem; margin-top: 1.5rem; }

        .btn-nouveau     { background: #fff; border: 1px solid #aaa; }
        .btn-enregistrer { background: #c6efce; border: 1px solid #82c88e; font-weight: 600; }
        .btn-supprimer   { background: #ffc7ce; border: 1px solid #e09090; font-weight: 600; }

        .btn-nouveau:hover     { background: #e8e8e8; }
        .btn-enregistrer:hover { background: #a8dfb0; }
        .btn-supprimer:hover   { background: #f0a0a8; }
        .btn-supprimer:disabled { opacity: .5; cursor: not-allowed; }
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php -->
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <span style="font-size:.78rem;font-weight:400;">
            <a href="<?= site_url('admin-menu') ?>" style="color:#cfe0ff;text-decoration:none;">Admin</a>
            <span class="mx-1" style="color:#cfe0ff;">&rsaquo;</span>
        </span>
        <i class="bi bi-diagram-3-fill me-2"></i>Gestion des divisions
        <small class="ms-2" style="color:#cfe0ff;">(E010)</small>
    </div>
    <a href="<?= site_url('admin-menu') ?>" class="btn btn-sm py-0" style="flex-shrink:0;background:#fff;color:#1a3a6b;border:1px solid #fff;">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
</div>

<!-- Toolbar : recopié de includes/toolbar.php -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= esc($nomComplet) ?><?= $departement ? ' (' . esc($departement) . ')' : '' ?>
    </span>
    <a class="ts-pwd-warning" href="/changer_mot_de_passe.php" id="lnk-chg-pwd" data-base="/changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<?php require __DIR__ . '/../../../includes/modal_mdp.php'; ?>

<!-- Split -->
<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">Divisions</div>
        <div id="table-wrapper">
            <table id="tbl-divisions">
                <thead>
                    <tr>
                        <th style="width:50px" data-col="0">Ord<span class="sort-icon"></span></th>
                        <th style="width:80px" data-col="1">Division<span class="sort-icon"></span></th>
                        <th data-col="2">Nom<span class="sort-icon"></span></th>
                        <th style="width:50px;text-align:center">Couleur</th>
                        <th style="width:120px;text-align:center" data-col="4">Arbitrage CRA<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div class="mb-2">
            <label class="form-label">Id Division :</label>
            <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Division :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="num-ord">Ordre :</label>
            <input type="number" id="num-ord" class="form-control form-control-sm" min="1" max="300" value="1" style="width:120px">
        </div>
        <div class="mb-2">
            <label class="form-label" for="txt-nom-long">Nom :</label>
            <input type="text" id="txt-nom-long" class="form-control form-control-sm" maxlength="255">
        </div>

        <div class="mb-2">
            <label class="form-label" for="color-couleur">Couleur :</label>
            <input type="color" id="color-couleur" class="form-control form-control-sm" value="#1565c0" style="width:60px;padding:.2rem">
        </div>

        <div class="mb-3">
            <label class="form-label">Arbitrage CRA :</label>
            <div class="d-flex gap-3">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="arbitrage" id="radio-oblig" value="1" checked>
                    <label class="form-check-label" for="radio-oblig">
                        <span class="badge" style="background:#1565c0">Obligatoire</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="arbitrage" id="radio-demande" value="0">
                    <label class="form-check-label" for="radio-demande">
                        <span class="badge" style="background:#e65100">Sur demande du club</span>
                    </label>
                </div>
            </div>
            <div class="form-text">Indique si un JA doit être désigné automatiquement pour chaque rencontre.</div>
        </div>

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

        <div id="form-status" class="mt-3 small fw-bold"></div>

    </div>
</div>

<script src="/asset/js/jquery-3.7.1.min.js"></script>
<script src="/asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';
const DIVISION_BASE = '<?= site_url('division') ?>';
let currentId = null;
const sortState = { col: null, asc: true };

function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg)
        .removeClass('text-danger text-success')
        .addClass(ok ? 'text-success' : 'text-danger');
}

function chargerListe(selectId = null) {
    $.get(`${DIVISION_BASE}/data`, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="5" class="text-center text-muted py-3">Aucune division.</td></tr>');
            return;
        }
        res.data.forEach(d => {
            const arb = +d.ArbitrageCRA === 1
                ? '<span class="badge" style="background:#1565c0;font-size:.75rem">Obligatoire</span>'
                : '<span class="badge" style="background:#e65100;font-size:.75rem">Sur demande</span>';
            const couleur = `<span style="display:inline-block;width:16px;height:16px;border-radius:3px;border:1px solid #999;background:${d.Color || '#1565c0'}"></span>`;
            const $tr = $('<tr>')
                .attr('data-id', d.Id_Division)
                .append(
                    $('<td class="text-center">').text(d.Ord),
                    $('<td>').text(d.Division),
                    $('<td>').text(d.Nom),
                    $('<td class="text-center">').html(couleur),
                    $('<td class="text-center">').html(arb)
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
    $.get(`${DIVISION_BASE}/data/${id}`, function (res) {
        if (!res.ok) return;
        const d = res.data;
        currentId = parseInt(d.Id_Division);
        $('#txt-id').val(currentId);
        $('#txt-nom').val(d.Division);
        $('#num-ord').val(d.Ord);
        $('#txt-nom-long').val(d.Nom);
        $('#color-couleur').val(d.Color || '#1565c0');
        $('input[name="arbitrage"]').filter(`[value="${+d.ArbitrageCRA}"]`).prop('checked', true);
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-nom').val('').trigger('focus');
    $('#num-ord').val(1);
    $('#txt-nom-long').val('');
    $('#color-couleur').val('#1565c0');
    $('#radio-oblig').prop('checked', true);
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

$('#btn-enregistrer').on('click', function () {
    const isNew = currentId === null;
    const payload = {
        nom:                   $('#txt-nom').val().trim(),
        ord:                   $('#num-ord').val(),
        nom_long:              $('#txt-nom-long').val().trim(),
        color:                 $('#color-couleur').val(),
        arbitrage_obligatoire: $('input[name="arbitrage"]:checked').val(),
    };
    const url    = isNew ? DIVISION_BASE : `${DIVISION_BASE}/${currentId}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const nom = $('#txt-nom').val();
    nijacConfirm(`Supprimer la division « ${nom} » ?`, function () {
        $.ajax({ url: `${DIVISION_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-divisions thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="/asset/js/nijac-csrf.js"></script>
<script src="/asset/js/nijac-toast.js"></script>
<script src="/asset/js/nijac-sortable-table.js"></script>
</body>
</html>
