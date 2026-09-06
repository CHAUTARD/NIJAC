<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Souhaits des équipes (ES33)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        /* En-tête violet, propre au rôle CSR (voir E004/ES31) */
        #page-header { background: #6a1b9a; }
        #panel-liste { width: 62%; }
        #liste-header { justify-content: space-between; }
        #search-input {
            font-size: .82rem;
            padding: .32rem .7rem;
            border: 1.5px solid #d3dae6;
            border-radius: 12px;
            width: 200px;
        }
        #search-input:focus { outline: none; border-color: var(--nijac-blue); }
        #liste-header label { font-size: .82rem; color: #374151; display: flex; align-items: center; gap: .3rem; }
        #hint-division { font-size: .8rem; color: #92400e; background: #fff3cd; border: 1px solid #f59e0b; border-radius: 6px; padding: .4rem .6rem; }
        tr.hors-saisie td { color: #9ca3af; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar-week', 'phTitle' => 'Souhaits des équipes', 'phCode' => 'ES33',
    'phCrumbLabel' => 'CSR', 'phCrumbUrl' => site_url('csr-menu'), 'phBackUrl' => site_url('csr-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">
            <span>Équipes <span id="lbl-count">0 / 0</span></span>
            <div style="display:flex;align-items:center;gap:.75rem;">
                <button type="button" class="btn btn-sm btn-light" id="btn-import-xlsx" title="Importer le classeur Excel des engagements CRA">
                    <i class="bi bi-box-arrow-in-down"></i> Importer engagements
                </button>
                <button type="button" class="btn btn-sm btn-light" id="btn-exec-csv" title="Mettre à jour la table equipe depuis un CSV">
                    <i class="bi bi-filetype-csv"></i> Exécuter le CSV
                </button>
                <button type="button" class="btn btn-sm btn-light" id="btn-rapports" title="Consulter les rapports d'exécution">
                    <i class="bi bi-clock-history"></i> Rapports
                </button>
                <input type="file" id="file-csv" accept=".csv,text/csv" class="d-none">
                <label><input type="checkbox" id="chk-r3r4" checked> Divisions R3M/R4M seulement</label>
                <input type="search" id="search-input" placeholder="🔍 Rechercher…">
            </div>
        </div>
        <div id="table-wrapper">
            <table id="tbl-equipes">
                <thead>
                    <tr>
                        <th style="width:100px" data-col="0">Id_Club<span class="sort-icon"></span></th>
                        <th data-col="1">Club<span class="sort-icon"></span></th>
                        <th data-col="2">Équipe<span class="sort-icon"></span></th>
                        <th style="width:70px" data-col="3">Division<span class="sort-icon"></span></th>
                        <th style="width:100px" data-col="4">Jour souh.<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="5">Arbitrage<span class="sort-icon"></span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="6" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une équipe dans la liste pour saisir ses souhaits.</div>

        <div id="form-equipe" style="display:none;">
            <div class="row g-2 mb-2">
                <div class="col-auto">
                    <span class="form-label d-block">Id_Club</span>
                    <div class="form-readonly" id="txt-idclub"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Club</span>
                    <div class="form-readonly" id="txt-club"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Division</span>
                    <div class="form-readonly" id="txt-division"></div>
                </div>
            </div>
            <div class="mb-2">
                <span class="form-label d-block">Équipe</span>
                <div class="form-readonly" id="txt-nom"></div>
            </div>

            <hr>

            <div id="hint-division" class="mb-2" style="display:none;">
                Le jour souhaité et l'arbitrage ne se saisissent que pour les divisions R3M et R4M.
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
                <label class="form-label" for="sel-arbitrage">Arbitrage</label>
                <select id="sel-arbitrage" class="form-select form-select-sm">
                    <option value="">—</option>
                    <option value="CRA">CRA</option>
                    <option value="Club">Club</option>
                </select>
            </div>

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
                <button class="btn btn-sm btn-nouveau px-3" id="btn-annuler">Annuler</button>
            </div>

            <div id="form-status" class="mt-3 small fw-bold"></div>
        </div>
    </div>
</div>

<!-- Modale « Importer engagements » -->
<div class="modal fade" id="modalImportEng" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6"><i class="bi bi-box-arrow-in-down me-2"></i>Importer les engagements CRA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2">
                    Sélectionnez le classeur <em>« Fichiers CRA engagements juges-arbitrages »</em> (.xlsx).
                    Le CSV produit liste <strong>toutes les équipes régionales de NIJAC</strong> (hors nationales,
                    une ligne par équipe). Le classeur (feuille <strong>SECTEUR&nbsp;…</strong>, en-tête ligne&nbsp;3)
                    sert à renseigner le <em>jour souhaité</em> (colonne E, « Dimanche » sinon Samedi) et, pour
                    <strong>R3M / R4M</strong> uniquement, l'<em>arbitrage</em> (colonne G : « … du club » → Club, sinon CRA).
                </p>
                <p class="small text-muted mb-3">
                    CSV <em>N° Club ; Équipe ; Division ; Jour souhaité ; Arbitrage</em>, téléchargé et copié dans
                    <code>Importation/Souhait_R3M-R4M/</code>. <strong>Exécuter le CSV</strong> met alors à jour
                    <code>equipe.JourSouhaite</code> (toutes divisions) et <code>equipe.SouhaitJA</code> (R3M / R4M).
                </p>
                <input type="file" id="file-xlsx-modal" accept=".xlsx" class="form-control form-control-sm">
                <div id="import-eng-status" class="small mt-2"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modale « Rapports d'exécution » -->
<div class="modal fade" id="modalRapports" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6"><i class="bi bi-clock-history me-2"></i>Rapports d'exécution CSV (2 derniers)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="rapports-body">
                <div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span> Chargement…</div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const SOUHAIT_BASE = '<?= site_url('souhait-equipe') ?>';
let lignes           = [];
let divisionsSaisie  = ['R3M', 'R4M'];
let currentId        = null;
let searchTerm       = '';
let r3r4Seulement    = true;
const sortState      = { col: null, asc: true };

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function saisissable(division) {
    return divisionsSaisie.includes(division);
}

function lignesFiltrees() {
    const term = searchTerm.toLowerCase();
    return lignes.filter(l => {
        if (r3r4Seulement && !saisissable(l.Division)) return false;
        if (!term) return true;
        return String(l.Nom ?? '').toLowerCase().includes(term)
            || String(l.NomClub ?? '').toLowerCase().includes(term)
            || String(l.Id_Club ?? '').toLowerCase().includes(term)
            || String(l.Division ?? '').toLowerCase().includes(term);
    });
}

function chargerListe(selectId = null) {
    $.get(`${SOUHAIT_BASE}/liste`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data;
        if (Array.isArray(res.divisionsSaisie)) divisionsSaisie = res.divisionsSaisie;
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
        $body.append(`<tr><td colspan="6" class="text-center text-muted py-3">${searchTerm ? 'Aucun résultat.' : 'Aucune équipe.'}</td></tr>`);
        return;
    }

    affichees.forEach(l => {
        $('<tr>').attr('data-id', l.Id_Equipe).toggleClass('hors-saisie', !saisissable(l.Division)).append(
            $('<td>').text(l.Id_Club ?? ''),
            $('<td>').text(l.NomClub ?? ''),
            $('<td>').text(l.Nom ?? ''),
            $('<td>').text(l.Division ?? ''),
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
    const l  = lignes.find(x => x.Id_Equipe == id);
    if (!l) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-equipe').show();
    $('#txt-idclub').text(l.Id_Club ?? '');
    $('#txt-club').text(l.NomClub ?? '');
    $('#txt-division').text(l.Division ?? '');
    $('#txt-nom').text(l.Nom ?? '');
    $('#sel-jour-souhaite').val(l.JourSouhaite ?? '');
    $('#sel-arbitrage').val(l.SouhaitJA ?? '');

    const ok = saisissable(l.Division);
    $('#hint-division').toggle(!ok);
    $('#sel-jour-souhaite, #sel-arbitrage').prop('disabled', !ok);
    $('#btn-enregistrer').prop('disabled', !ok);
    setStatus('');
}

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;
    const payload = {
        jour_souhaite: $('#sel-jour-souhaite').val(),
        souhait_ja:    $('#sel-arbitrage').val(),
    };

    $.ajax({ url: `${SOUHAIT_BASE}/${currentId}`, method: 'PUT', data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(currentId); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

$('#btn-annuler').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#form-equipe').hide();
    $('#no-selection').show();
});

$('#search-input').on('input', function () { searchTerm = $(this).val().trim(); renderListe(); });
$('#chk-r3r4').on('change', function () { r3r4Seulement = this.checked; renderListe(); });

// ── Importer engagements (xlsx → csv) ────────────────────────────────────────
$('#btn-import-xlsx').on('click', function () {
    $('#file-xlsx-modal').val('');
    $('#import-eng-status').text('').removeClass('text-danger text-success');
    new bootstrap.Modal('#modalImportEng').show();
});

$('#file-xlsx-modal').on('change', function () {
    const f = this.files[0];
    if (!f) return;
    const $st = $('#import-eng-status').removeClass('text-danger text-success').text('Lecture du classeur…');
    const fd = new FormData();
    fd.append('xlsx', f);
    $.ajax({
        url: `${SOUHAIT_BASE}/xlsx-csv`, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
    }).done(function (res) {
        if (!res.ok) { $st.addClass('text-danger').text(res.msg); return; }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([res.csv], { type: 'text/csv;charset=utf-8' }));
        a.download = res.nom || 'souhaits_R3M_R4M.csv';
        document.body.appendChild(a); a.click(); a.remove();
        URL.revokeObjectURL(a.href);
        bootstrap.Modal.getInstance('#modalImportEng')?.hide();
        const ign = (res.ignorees || []).length;
        toast(`${res.nb} équipe(s) régionale(s), ${res.nb_dim} au dimanche.`
            + (res.chemin ? ` Enregistré dans ${res.chemin} (et téléchargé).` : ` « ${a.download} » téléchargé.`)
            + (ign ? ` ${ign} ligne(s) du classeur sans équipe NIJAC (voir console).` : ''),
            ign === 0);
        if (ign) console.warn('Lignes CRA ignorées :\n' + res.ignorees.join('\n'));
    }).fail(() => $st.addClass('text-danger').text('Erreur réseau.'));
});

// ── Exécuter le CSV (maj table equipe) ──────────────────────────────────────
$('#btn-exec-csv').on('click', () => $('#file-csv').click());

$('#file-csv').on('change', function () {
    const f = this.files[0];
    this.value = '';
    if (!f) return;
    const fd = new FormData();
    fd.append('csv', f);
    toast('Mise à jour en cours…');
    $.ajax({
        url: `${SOUHAIT_BASE}/maj-csv`, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
    }).done(function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        toast(res.msg + ' Rapport enregistré.', !(res.problemes && res.problemes.length));
        chargerListe(currentId);
    }).fail(() => toast('Erreur réseau.', false));
});

// ── Rapports d'exécution ────────────────────────────────────────────────────
function escHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

$('#btn-rapports').on('click', function () {
    $('#rapports-body').html('<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm"></span> Chargement…</div>');
    new bootstrap.Modal('#modalRapports').show();

    $.get(`${SOUHAIT_BASE}/rapports`, function (res) {
        if (!res.ok) { $('#rapports-body').html(`<div class="text-danger">${escHtml(res.msg)}</div>`); return; }
        if (!res.rapports.length) { $('#rapports-body').html('<div class="text-muted">Aucun rapport enregistré.</div>'); return; }

        const html = res.rapports.map(r => {
            const pbs = (r.Problemes || []).map(p => `<li>${escHtml(p)}</li>`).join('');
            const detail = pbs
                ? `<details class="mt-1"><summary style="cursor:pointer;font-size:.8rem;color:#991b1b;">${r.NbProblemes} non rapprochée(s)</summary><ul class="small mb-0" style="color:#991b1b;">${pbs}</ul></details>`
                : '<div class="small text-success">Aucun problème.</div>';
            return `<div class="border rounded p-2 mb-2">
                <div class="d-flex justify-content-between small">
                    <span><strong>${escHtml(r.DateExecution)}</strong> — ${escHtml(r.Operateur || '?')}</span>
                    <span class="text-muted">${escHtml(r.NomFichier || '')}</span>
                </div>
                <div class="small"><span class="text-success">${r.NbMaj} mise(s) à jour</span>, ${r.NbProblemes} non rapprochée(s)</div>
                ${detail}
            </div>`;
        }).join('');
        $('#rapports-body').html(html);
    }, 'json').fail(() => $('#rapports-body').html('<div class="text-danger">Erreur réseau.</div>'));
});

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
