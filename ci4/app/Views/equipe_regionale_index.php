<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Équipes régionales (E019)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
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
        #split-container { display: flex; flex: 1; overflow: hidden; }
        #panel-liste {
            width: 65%;
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
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        #search-input {
            font-size: .82rem;
            padding: .15rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 200px;
        }
        #lbl-count {
            display: inline-block;
            padding: .12rem .5rem;
            background: rgba(255,255,255,.85);
            border: 1px solid rgba(0,0,0,.1);
            border-radius: 4px;
            font-size: .8rem;
            font-weight: 700;
            color: #1a3a6b;
        }
        #table-wrapper { flex: 1; overflow-y: auto; }
        #tbl-equipes { width: 100%; font-size: .84rem; border-collapse: collapse; }
        #tbl-equipes thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            cursor: pointer;
            user-select: none;
        }
        #tbl-equipes thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-equipes thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-equipes thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-equipes thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        #tbl-equipes tbody tr { cursor: pointer; border-bottom: 1px solid #e0e8f0; }
        #tbl-equipes tbody tr:hover { background: #dce8f8; }
        #tbl-equipes tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-equipes tbody td { padding: .3rem .5rem; }
        #panel-form {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            background: #fff;
        }
        .form-label { font-size: .82rem; font-weight: 700; color: #374151; margin-bottom: .2rem; }
        .form-control, .form-select { font-size: .9rem; }
        .form-readonly { font-size: .9rem; color: #374151; padding: .3rem 0; }
        #panel-boutons { display: flex; gap: .6rem; margin-top: 1.25rem; }
        .btn-enregistrer { background:#c6efce; border:1px solid #82c88e; font-weight:600; }
        .btn-supprimer   { background:#ffc7ce; border:1px solid #e09090; font-weight:600; }
        .btn-enregistrer:hover { background:#a8dfb0; }
        .btn-supprimer:hover   { background:#f0a0a8; }
        .btn-enregistrer:disabled, .btn-supprimer:disabled { opacity:.5; cursor:not-allowed; }
        #no-selection { color:#9ca3af; font-size:.85rem; text-align:center; margin-top:2rem; }

        #import-result {
            margin: .6rem .75rem 0;
            padding: .75rem 1rem;
            border-radius: 6px;
            font-size: .82rem;
            position: relative;
            max-height: 45vh;
            overflow-y: auto;
        }
        #import-result.ok  { background:#d1fae5; border:1px solid #6ee7b7; color:#065f46; }
        #import-result.err { background:#fee2e2; border:1px solid #fca5a5; color:#991b1b; }
        #import-result .ir-close {
            position: absolute; top: .4rem; right: .5rem;
            background: none; border: none; font-size: 1rem;
            opacity: .6; cursor: pointer; line-height: 1;
        }
        #import-result .ir-close:hover { opacity: 1; }
        #import-result h6 { font-size: .85rem; font-weight: 700; margin: .6rem 0 .3rem; }
        #import-result ul { margin: 0; padding-left: 1.1rem; }
        #import-result li { margin-bottom: .15rem; }
        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'people-fill', 'phTitle' => 'Équipes régionales', 'phCode' => 'E019',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">
            <span>Équipes <span id="lbl-count">0 / 0</span></span>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <button type="button" class="btn btn-sm btn-light" id="btn-importer-txt" title="Importer club_Reg_R4.PN.txt">
                    <i class="bi bi-upload me-1"></i>Importer .txt
                </button>
                <select id="sel-division" class="form-select form-select-sm" style="width:auto;font-size:.82rem;">
                    <option value="">— Toutes divisions —</option>
                </select>
                <input type="search" id="search-input" placeholder="🔍 Rechercher…">
            </div>
        </div>
        <input type="file" id="file-input-txt" accept=".txt,.csv" style="display:none">
        <div id="import-result" style="display:none;"></div>
        <div id="table-wrapper">
            <table id="tbl-equipes">
                <thead>
                    <tr>
                        <th data-col="0">Nom<span class="sort-icon"></span></th>
                        <th style="width:70px" data-col="1">Division<span class="sort-icon"></span></th>
                        <th data-col="2">Club<span class="sort-icon"></span></th>
                        <th data-col="3">Club2<span class="sort-icon"></span></th>
                        <th data-col="4">Club3<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="5">Réengag.<span class="sort-icon"></span></th>
                        <th style="width:100px" data-col="6">Jour souh.<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="7">Souhait JA<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une équipe dans la liste pour l'éditer.</div>

        <div id="form-equipe" style="display:none;">
            <div class="mb-2">
                <span class="form-label d-block">Nom</span>
                <div class="form-readonly" id="txt-nom"></div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-auto">
                    <span class="form-label d-block">Division</span>
                    <div class="form-readonly" id="txt-division"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Club</span>
                    <div class="form-readonly" id="txt-club"></div>
                </div>
                <div class="col-auto" id="grp-club2" style="display:none;">
                    <span class="form-label d-block">Club 2 (entente)</span>
                    <div class="form-readonly" id="txt-club2"></div>
                </div>
                <div class="col-auto" id="grp-club3" style="display:none;">
                    <span class="form-label d-block">Club 3 (entente)</span>
                    <div class="form-readonly" id="txt-club3"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">JA demandé</span>
                    <div class="form-readonly" id="txt-jademande"></div>
                </div>
            </div>

            <hr>

            <div class="mb-2">
                <label class="form-label" for="sel-reengagement">Réengagement</label>
                <select id="sel-reengagement" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="O">Oui</option>
                    <option value="N">Non</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-jour-souhaite">Jour souhaité</label>
                <select id="sel-jour-souhaite" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="Samedi">Samedi</option>
                    <option value="Dimanche">Dimanche</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="sel-souhait-ja">Souhait JA</label>
                <select id="sel-souhait-ja" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="CRA">CRA</option>
                    <option value="Club">Club</option>
                </select>
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-desiderata-saison">Saison désidérata</label>
                <input type="text" id="txt-desiderata-saison" class="form-control form-control-sm" maxlength="9" placeholder="2025-2026">
            </div>

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
                <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer"><i class="bi bi-trash3 me-1"></i>Supprimer</button>
            </div>

            <div id="form-status" class="mt-3 small fw-bold"></div>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const EQUIPE_BASE = '<?= site_url('equipe-regionale') ?>';
let lignes         = [];
let currentId       = null;
let searchTerm      = '';
let divisionFiltre  = '';
const sortState     = { col: null, asc: true };

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function lignesFiltrees() {
    const term = searchTerm.toLowerCase();
    return lignes.filter(l => {
        if (divisionFiltre && l.Division !== divisionFiltre) return false;
        if (!term) return true;
        return String(l.Nom ?? '').toLowerCase().includes(term) ||
            String(l.NomClub ?? '').toLowerCase().includes(term) ||
            String(l.Division ?? '').toLowerCase().includes(term);
    });
}

function majComboDivisions() {
    const divisions = [...new Set(lignes.map(l => l.Division).filter(Boolean))].sort();
    const $sel = $('#sel-division');
    const val  = $sel.val();
    $sel.find('option:not(:first)').remove();
    divisions.forEach(d => $sel.append(new Option(d, d)));
    if (divisions.includes(val)) $sel.val(val);
    else { divisionFiltre = ''; $sel.val(''); }
}

function chargerListe(selectId = null) {
    $.get(`${EQUIPE_BASE}/liste`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data;
        majComboDivisions();
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = lignesFiltrees();
    $('#lbl-count').text(`${affichees.length} / ${lignes.length}`);

    if (!affichees.length) {
        $body.append(`<tr><td colspan="8" class="text-center text-muted py-3">${searchTerm ? 'Aucun résultat.' : 'Aucune équipe.'}</td></tr>`);
        return;
    }

    affichees.forEach(l => {
        $('<tr>').attr('data-id', l.Id_Equipe).append(
            $('<td>').text(l.Nom ?? ''),
            $('<td>').text(l.Division ?? ''),
            $('<td>').text(l.NomClub ?? ''),
            $('<td>').text(l.NomClub2 ?? ''),
            $('<td>').text(l.NomClub3 ?? ''),
            $('<td>').text(l.ReEngagement ?? ''),
            $('<td>').text(l.JourSouhaite ?? ''),
            $('<td>').text(l.SouhaitJA ?? '')
        ).on('click', function () { selectionnerLigne($(this)); }).appendTo($body);
    });

    if (currentId) {
        const $tr = $(`#tbody-liste tr[data-id="${currentId}"]`);
        if ($tr.length) $tr.addClass('selected');
    }
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = +$tr.attr('data-id');
    const l  = lignes.find(x => x.Id_Equipe === id);
    if (!l) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-equipe').show();
    $('#txt-nom').text(l.Nom ?? '');
    $('#txt-division').text(l.Division ?? '');
    $('#txt-club').text(l.NomClub ?? '');
    if (l.NomClub2) { $('#grp-club2').show(); $('#txt-club2').text(l.NomClub2); }
    else { $('#grp-club2').hide(); $('#txt-club2').text(''); }
    if (l.NomClub3) { $('#grp-club3').show(); $('#txt-club3').text(l.NomClub3); }
    else { $('#grp-club3').hide(); $('#txt-club3').text(''); }
    $('#txt-jademande').text(+l.JAdemande ? 'Oui' : 'Non');
    $('#sel-reengagement').val(l.ReEngagement ?? '');
    $('#sel-jour-souhaite').val(l.JourSouhaite ?? '');
    $('#sel-souhait-ja').val(l.SouhaitJA ?? '');
    $('#txt-desiderata-saison').val(l.DesiderataSaison ?? '');
    setStatus('');
}

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;
    const payload = {
        re_engagement:      $('#sel-reengagement').val(),
        jour_souhaite:      $('#sel-jour-souhaite').val(),
        souhait_ja:         $('#sel-souhait-ja').val(),
        desiderata_saison:  $('#txt-desiderata-saison').val().trim(),
    };

    $.ajax({ url: `${EQUIPE_BASE}/${currentId}`, method: 'PUT', data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(currentId); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const nom = $('#txt-nom').text();
    nijacConfirm(`Supprimer l'équipe « ${nom} » ?`, function () {
        $.ajax({ url: `${EQUIPE_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) {
                toast(res.msg);
                currentId = null;
                $('#form-equipe').hide();
                $('#no-selection').show();
                chargerListe();
            } else {
                toast(res.msg, false);
            }
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'danger' });
});

$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderListe();
});

$('#sel-division').on('change', function () {
    divisionFiltre = $(this).val();
    renderListe();
});

// ── Import du fichier texte (club_Reg_R4.PN.txt) ─────────────────────────────
$('#btn-importer-txt').on('click', () => $('#file-input-txt').val('').trigger('click'));

$('#file-input-txt').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('fichier', file);

    $('#import-result').hide();
    toast('Import en cours…');
    $.ajax({
        url: `${EQUIPE_BASE}/import-txt`, type: 'POST',
        data: fd, processData: false, contentType: false, dataType: 'json',
    }).done(function (res) {
        if (!res.ok) { afficherResultatImport(false, res.msg, [], []); return; }
        afficherResultatImport(true, res.msg, res.erreurs || [], res.avertissements || []);
        chargerListe();
    }).fail(() => afficherResultatImport(false, 'Erreur réseau.', [], []));
});

function afficherResultatImport(ok, msg, erreurs, avertissements) {
    const $box = $('#import-result').removeClass('ok err').addClass(ok ? 'ok' : 'err');
    let html = '<button type="button" class="ir-close" title="Fermer">✕</button>';
    html += `<div><strong>${ok ? '✅' : '❌'} ${msg}</strong></div>`;
    if (avertissements.length) {
        html += `<h6>Avertissements (${avertissements.length})</h6><ul>` +
            avertissements.map(a => `<li>${a}</li>`).join('') + '</ul>';
    }
    if (erreurs.length) {
        html += `<h6>Erreurs (${erreurs.length})</h6><ul>` +
            erreurs.map(e => `<li>${e}</li>`).join('') + '</ul>';
    }
    $box.html(html).show();
    $box.find('.ir-close').on('click', () => $box.hide());
}

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé après ce script (voir plus bas),
// donc pas encore défini si on l'appelait ici de façon synchrone.
$(function () {
    nijacSortableTable('#tbl-equipes thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
