<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Comptes EBP des JA (EN16)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 62%; }
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; }

        /* Bandeau de filtres : mêmes comboboxes « label en encoche » que EN11
           (nijac.css .combo-field + habillage nijac-skin.css). Le fond exact du
           bandeau vient de nijac-skin.css (var(--en-bg)) ; on aligne --strip-bg
           dessus pour que l'encoche du label s'y fonde. */
        #menu-strip {
            --strip-bg: var(--en-bg, #f8fafc);
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        /* Badge de comptage « n / n » : centré horizontalement et verticalement,
           largeur stable pour ne pas sauter quand les nombres changent. */
        #lbl-count { justify-content: center; text-align: center; min-width: 5.5rem; }

        #ja-list-wrapper { flex: 1; overflow-y: auto; }
        #tbl-ja { width: 100%; font-size: .82rem; border-collapse: collapse; }
        #tbl-ja thead th {
            background: #e8eef7; border-bottom: 2px solid #c8d4e8;
            padding: .3rem .4rem; position: sticky; top: 0; z-index: 1; white-space: nowrap;
        }
        #tbl-ja tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-ja tbody tr:hover { background: #f4f7fc; }
        #tbl-ja tbody td { padding: .28rem .5rem; }
        .col-sort { cursor: pointer; user-select: none; }
        td.col-num { text-align: right; font-variant-numeric: tabular-nums; }
        td.col-center { text-align: center; }
        tr.ja-inactif td { color: #9aa5b8; }

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
        #import-result .ir-search { color: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }
        #import-result .ir-search:hover { text-decoration: none; }
    </style>
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'person-vcard', 'phTitle' => 'Comptes EBP des JA', 'phCode' => 'EN16',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="menu-strip">
            <button type="button" class="btn btn-sm btn-light" id="btn-importer-csv" title="Importer une balance EBP (.csv)">
                <i class="bi bi-upload me-1"></i>Importer CSV
            </button>
            <button type="button" class="btn btn-sm btn-light" id="btn-exporter-csv" title="Exporter n° EBP ; Nom + Prénom">
                <i class="bi bi-download me-1"></i>Exporter CSV
            </button>

            <span class="combo-field">
                <label for="search-input">Recherche</label>
                <input type="search" id="search-input" placeholder="Nom / prénom…" style="width:170px;">
            </span>
            <span class="combo-field">
                <label for="sel-compte">Compte EBP</label>
                <select id="sel-compte">
                    <option value="">Tous</option>
                    <option value="sans" selected>Sans compte</option>
                    <option value="avec">Avec compte</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-defisc">Défisc.</label>
                <select id="sel-defisc">
                    <option value="">Tous</option>
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-actif">Actif</label>
                <select id="sel-actif">
                    <option value="" selected>Tous</option>
                    <option value="1">Oui</option>
                    <option value="0">Non</option>
                </select>
            </span>
            <button type="button" class="btn btn-sm btn-light" id="btn-reset-filtres" title="Réinitialiser les filtres">
                <i class="bi bi-x-circle"></i>
            </button>

            <span style="flex:1"></span>
            <span class="count-badge" id="lbl-count">0 / 0</span>
        </div>
        <input type="file" id="file-input-csv" accept=".csv" style="display:none">
        <div id="import-result" style="display:none;"></div>
        <div id="ja-list-wrapper">
            <table id="tbl-ja">
                <thead>
                    <tr>
                        <th class="col-sort" data-col="0">Nom <span class="sort-icon">↕</span></th>
                        <th class="col-sort" data-col="1">Prénom <span class="sort-icon">↕</span></th>
                        <th class="col-sort" style="width:55px" data-col="2">Actif <span class="sort-icon">↕</span></th>
                        <th class="col-sort" style="width:70px" data-col="3" title="Défiscalisation demandée">Défisc. <span class="sort-icon">↕</span></th>
                        <th class="col-sort" style="width:95px" data-col="4" title="Total des kilomètres arbitrés">Km total <span class="sort-icon">↕</span></th>
                        <th class="col-sort" style="width:55px" data-col="5" title="Puissance fiscale (CV)">CV <span class="sort-icon">↕</span></th>
                        <th class="col-sort" style="width:85px" data-col="6">Énergie <span class="sort-icon">↕</span></th>
                        <th class="col-sort" style="width:145px" data-col="7">N° compte EBP <span class="sort-icon">↕</span></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez un JA dans la liste pour saisir son n° de compte EBP.</div>

        <div id="form-ja" style="display:none;">
            <div class="row g-2 mb-2">
                <div class="col-auto">
                    <span class="form-label d-block">Nom</span>
                    <div class="form-readonly" id="txt-nom"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Prénom</span>
                    <div class="form-readonly" id="txt-prenom"></div>
                </div>
                <div class="col-auto">
                    <span class="form-label d-block">Actif</span>
                    <div class="form-readonly" id="txt-actif"></div>
                </div>
            </div>

            <div id="grp-defisc" class="mb-2" style="display:none;">
                <span class="form-label d-block">Défiscalisation</span>
                <div class="form-readonly" id="txt-defisc"></div>
            </div>

            <hr>

            <div class="mb-2">
                <label class="form-label" for="txt-compte">N° de compte EBP</label>
                <input type="text" id="txt-compte" class="form-control form-control-sm" maxlength="20" style="max-width:220px;" placeholder="ex. 4010682">
                <div class="small text-muted mt-1">Laisser vide pour effacer le n° de compte.</div>
            </div>

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
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
const COMPTA_BASE = '<?= site_url('compta') ?>';
let lignes      = [];
let currentId   = null;
let searchTerm  = '';
const sortState = { col: null, asc: true };

function h(s) {
    return String(s ?? '').replace(/[&<>"]/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c]));
}
function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}
function fmtKm(v) {
    return (parseFloat(v) || 0).toLocaleString('fr-FR');
}
function energie(l) {
    return +l.VehiculeElectrique ? 'Élec.' : 'Therm.';
}

function lignesFiltrees() {
    const term    = searchTerm.toLowerCase();
    const fCompte = $('#sel-compte').val();
    const fDefisc = $('#sel-defisc').val();
    const fActif  = $('#sel-actif').val();
    return lignes.filter(l => {
        const aCompte = String(l.NumCompteEBP ?? '') !== '';
        if (fCompte === 'sans' && aCompte) return false;
        if (fCompte === 'avec' && !aCompte) return false;
        if (fDefisc !== '' && String(+l.Defiscalisation) !== fDefisc) return false;
        if (fActif !== '' && String(+l.Actif) !== fActif) return false;
        if (term && !`${l.Nom ?? ''} ${l.Prenom ?? ''}`.toLowerCase().includes(term)) return false;
        return true;
    });
}

// Valeur de tri d'une ligne pour la colonne demandée (Km / CV numériques).
function valeurTri(l, col) {
    const def = +l.Defiscalisation === 1;
    switch (col) {
        case 0:  return (l.Nom ?? '').toLowerCase();
        case 1:  return (l.Prenom ?? '').toLowerCase();
        case 2:  return +l.Actif ? 'oui' : 'non';
        case 3:  return def ? 'oui' : 'non';
        case 4:  return def ? (parseFloat(l.KmTotal) || 0) : -1;
        case 5:  return def ? (parseInt(l.PuissanceFiscale, 10) || 0) : -1;
        case 6:  return def ? energie(l) : '';
        case 7:  return String(l.NumCompteEBP ?? '');
        default: return '';
    }
}

function trierAffichees(arr) {
    $('#tbl-ja .sort-icon').text('↕');
    if (sortState.col == null) return arr;
    const c = parseInt(sortState.col, 10);
    $(`#tbl-ja .col-sort[data-col="${c}"] .sort-icon`).text(sortState.asc ? '↑' : '↓');
    return arr.sort((a, b) => {
        const va = valeurTri(a, c), vb = valeurTri(b, c);
        const cmp = (typeof va === 'number' && typeof vb === 'number')
            ? va - vb
            : String(va).localeCompare(String(vb), 'fr');
        return sortState.asc ? cmp : -cmp;
    });
}

function chargerListe(selectId = null) {
    $.get(`${COMPTA_BASE}/ja-sans-compte`, { tous: 1 }, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data;
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = trierAffichees(lignesFiltrees());
    $('#lbl-count').text(`${affichees.length} / ${lignes.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="8" class="text-center text-muted py-3">Aucun JA.</td></tr>');
        return;
    }

    affichees.forEach(l => {
        const def = +l.Defiscalisation === 1;
        $('<tr>').attr('data-id', l.Id_JA).toggleClass('ja-inactif', !+l.Actif).append(
            $('<td>').text(l.Nom ?? ''),
            $('<td>').text(l.Prenom ?? ''),
            $('<td>').addClass('col-center').text(+l.Actif ? 'Oui' : 'Non'),
            $('<td>').addClass('col-center').text(def ? 'Oui' : 'Non'),
            $('<td>').addClass('col-num').text(def ? fmtKm(l.KmTotal) : ''),
            $('<td>').addClass('col-center').text(def ? (l.PuissanceFiscale ?? '') : ''),
            $('<td>').addClass('col-center').text(def ? energie(l) : ''),
            $('<td>').text(l.NumCompteEBP ?? '')
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
    const l  = lignes.find(x => x.Id_JA == id);
    if (!l) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-ja').show();
    $('#txt-nom').text(l.Nom ?? '');
    $('#txt-prenom').text(l.Prenom ?? '');
    $('#txt-actif').text(+l.Actif ? 'Oui' : 'Non');
    if (+l.Defiscalisation === 1) {
        const cv = l.PuissanceFiscale ? `${l.PuissanceFiscale} CV` : 'CV non renseigné';
        $('#txt-defisc').text(`${cv} · ${(+l.VehiculeElectrique ? 'électrique' : 'thermique')} · ${fmtKm(l.KmTotal)} km cumulés`);
        $('#grp-defisc').show();
    } else {
        $('#grp-defisc').hide();
    }
    $('#txt-compte').val(l.NumCompteEBP ?? '');
    setStatus('');
}

$('#btn-enregistrer').on('click', function () {
    if (!currentId) return;
    const val = $('#txt-compte').val().trim();
    $.post(`${COMPTA_BASE}/maj-compte`, { id_ja: currentId, num_compte: val })
        .done(function (res) {
            if (!res.ok) { toast(res.msg, false); setStatus(res.msg, false); return; }
            const l = lignes.find(x => x.Id_JA == currentId);
            if (l) l.NumCompteEBP = val;
            toast(res.msg);
            setStatus(res.msg);
            renderListe();
        })
        .fail(() => toast('Erreur réseau.', false));
});

$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderListe();
});
$('#sel-compte, #sel-defisc, #sel-actif').on('change', renderListe);
$('#btn-reset-filtres').on('click', function () {
    searchTerm = '';
    $('#search-input').val('');
    $('#sel-compte').val('sans');
    $('#sel-defisc').val('');
    $('#sel-actif').val('');
    renderListe();
});

// ── Export CSV « n° EBP ; Nom + Prénom » ─────────────────────────────────────
$('#btn-exporter-csv').on('click', function () {
    $.get(`${COMPTA_BASE}/export-csv`)
        .done(function (res) {
            if (!res.ok) { toast(res.msg || 'Erreur.', false); return; }
            if (!res.csv || res.csv.indexOf('\n') === -1) { toast('Aucun compte EBP renseigné à exporter.', false); return; }
            const blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = 'comptes_ebp_ja.csv'; a.click();
            URL.revokeObjectURL(url);
            toast('Export téléchargé.');
        })
        .fail(() => toast('Erreur réseau.', false));
});

// ── Import d'une balance EBP (.csv) ──────────────────────────────────────────
$('#btn-importer-csv').on('click', () => $('#file-input-csv').val('').trigger('click'));

$('#file-input-csv').on('change', function () {
    const file = this.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('fichier', file);

    $('#import-result').hide();
    toast('Import en cours…');
    $.ajax({
        url: `${COMPTA_BASE}/import-ebp`, type: 'POST',
        data: fd, processData: false, contentType: false, dataType: 'json',
    }).done(function (res) {
        if (!res.ok) { afficherResultatImport(false, res.msg); return; }
        afficherResultatImport(true, res);
        chargerListe(currentId);
    }).fail(() => afficherResultatImport(false, 'Erreur réseau.'));
});

function afficherResultatImport(ok, res) {
    const $box = $('#import-result').removeClass('ok err').addClass(ok ? 'ok' : 'err');
    let html = '<button type="button" class="ir-close" title="Fermer">✕</button>';

    if (!ok) {
        html += `<div><strong>❌ ${h(res)}</strong></div>`;
        $box.html(html).show();
        $box.find('.ir-close').on('click', () => $box.hide());
        return;
    }

    const g = { maj: [], inchange: [], introuvable: [], ambigu: [] };
    res.resultats.forEach(r => { (g[r.statut] || (g[r.statut] = [])).push(r); });

    const sect = (titre, arr, clickable) =>
        `<h6>${titre}</h6><ul>` + arr.map(r => {
            const nom = clickable
                ? `<a href="#" class="ir-search" data-nom="${h(r.nom)}">${h(r.nom)}</a>`
                : h(r.nom);
            return `<li>${nom} → ${h(r.compte)}</li>`;
        }).join('') + '</ul>';

    html += `<div><strong>✅ ${res.nb_maj} compte(s) mis à jour sur ${res.nb_lignes} ligne(s) analysée(s)</strong></div>`;
    if (g.introuvable.length) html += sect(`Sans correspondance (${g.introuvable.length}) — cliquer un nom pour le chercher dans la liste`, g.introuvable, true);
    if (g.ambigu.length)      html += sect(`Ambigus, non modifiés (${g.ambigu.length})`, g.ambigu, true);
    if (g.maj.length)         html += sect(`Renseignés (${g.maj.length})`, g.maj);
    if (g.inchange.length)    html += `<h6>Déjà à jour (${g.inchange.length})</h6>`;

    $box.html(html).show();
    $box.find('.ir-close').on('click', () => $box.hide());
    $box.find('.ir-search').on('click', function (e) {
        e.preventDefault();
        // Recherche sur le 1er mot (le nom de famille) : plus tolérant aux
        // écarts de graphie prénom (« JEANCLAUDE » vs « Jean-Claude »).
        const motNom = String($(this).data('nom')).trim().split(/\s+/)[0] || '';
        searchTerm = motNom.toLowerCase();
        $('#search-input').val(motNom);
        $('#sel-compte').val('');
        $('#sel-actif').val('');
        renderListe();
        $('#ja-list-wrapper').scrollTop(0);
    });
}

// ── Tri sur clic en-tête (nijac-sortable-table.js chargé après ce script) ────
$(function () {
    nijacSortableTable('#tbl-ja thead th.col-sort', 'col', sortState, renderListe);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
