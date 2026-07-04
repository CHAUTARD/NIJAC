<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Salles (E005)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

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

        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 250px;
        }

        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        #grid-wrapper { flex: 1; overflow: auto; }

        #tbl-salles {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 800px;
        }

        #tbl-salles thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
            user-select: none;
        }
        #tbl-salles thead th:hover { background: #d4dff0; }
        #tbl-salles thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-salles thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-salles thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-salles thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-salles tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-salles tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-salles tbody tr:hover   { background: #dce8f8; }
        #tbl-salles tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-salles tbody tr.new-row  { background: #fffbe6 !important; }
        #tbl-salles tbody td { border: 1px solid #e0e8f0; padding: 0; }

        .cell-inner {
            display: block;
            padding: .28rem .45rem;
            min-height: 28px;
            outline: none;
            white-space: nowrap;
            overflow: hidden;
        }
        .cell-inner[contenteditable="true"] {
            background: #fffbe6;
            outline: 2px solid #f0a000;
            outline-offset: -2px;
        }

        td.col-id, td.col-nom-club { background: #f0f4fa; }
        td.col-id .cell-inner, td.col-nom-club .cell-inner { color: #6b7280; font-style: italic; }

        td.col-principale { text-align: center; vertical-align: middle; }
        td.col-principale input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }

        #spinner {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.3);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        #spinner.show { display: flex; }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
            gap: 1rem;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php -->
<?php $backUrl = $isAdmin ? site_url('admin-menu') : '/Nominateur/menu.php'; ?>
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <span style="font-size:.78rem;font-weight:400;">
            <a href="<?= esc($backUrl) ?>" style="color:#cfe0ff;text-decoration:none;">
                <?= $isAdmin ? 'Admin' : 'Nominateur' ?>
            </a>
            <span class="mx-1" style="color:#cfe0ff;">&rsaquo;</span>
        </span>
        <i class="bi bi-building-fill me-2"></i>Gestion des salles
        <small class="ms-2" style="color:#cfe0ff;">(E005)</small>
    </div>
    <a href="<?= esc($backUrl) ?>" class="btn btn-sm py-0" style="flex-shrink:0;background:#fff;color:#1a3a6b;border:1px solid #fff;">
        <i class="bi bi-arrow-left me-1"></i>Retour
    </a>
</div>

<!-- Toolbar : recopié de includes/toolbar.php -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= esc($nomComplet) ?><?= $departement ? ' (' . esc($departement) . ')' : '' ?>
    </span>
    <a class="ts-pwd-warning" href="<?= site_url('changer-mot-de-passe') ?>" id="lnk-chg-pwd" data-base="<?= site_url('changer-mot-de-passe') ?>">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
<?php if ($isAdmin): ?>
    <button class="menu-item" id="btn-sync-fftt" data-bs-toggle="modal" data-bs-target="#modal-sync-fftt">
        <i class="bi bi-cloud-arrow-down-fill"></i>Synchroniser depuis FFTT
    </button>
    <button class="menu-item" id="btn-ajouter">
        <i class="bi bi-plus-circle"></i>Ajouter
    </button>
    <button class="menu-item danger" id="btn-supprimer">
        <i class="bi bi-trash3"></i>Supprimer
    </button>
    <button class="menu-item" id="btn-sauvegarder">
        <i class="bi bi-database-fill-up"></i>Enregistrer dans la Base de données
    </button>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int) $d['code'] ?>"><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
<?php else: ?>
    <span style="padding:.2rem .6rem; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; font-size:.82rem; color:#856404;">
        <i class="bi bi-eye-fill me-1"></i>Consultation — département <?= esc($departement) ?>
    </span>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
<?php endif; ?>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-salles">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id_salle">N°<span class="sort-icon"></span></th>
                <th style="width:80px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:260px" data-field="adresse">Adresse<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="cp">Code postal<span class="sort-icon"></span></th>
                <th style="width:170px" data-field="ville">Ville<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="est_principale">Principale<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Modale saisie CP / Ville -->
<div class="modal fade" id="modal-cp-ville" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#0d6efd;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-geo-alt-fill me-1"></i>Code postal / Ville</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text">CP</span>
          <input type="text" id="mcv-cp" class="form-control" placeholder="76000" maxlength="10" style="max-width:90px">
          <input type="text" id="mcv-ville" class="form-control text-uppercase" placeholder="ROUEN">
        </div>
        <div id="mcv-msg" class="form-text" style="min-height:1.2em;"></div>
        <div id="mcv-suggestions" style="display:none;">
          <div class="fw-semibold text-primary small mb-1">Plusieurs communes — choisissez :</div>
          <div id="mcv-suggestions-list" class="d-flex flex-wrap gap-1"></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-success btn-sm" id="btn-mcv-ok"><i class="bi bi-check-lg me-1"></i>Valider</button>
      </div>
    </div>
  </div>
</div>

<!-- Modale Synchronisation FFTT -->
<div class="modal fade" id="modal-sync-fftt" tabindex="-1" aria-labelledby="modal-sync-fftt-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#0d6efd;color:#fff;">
        <h5 class="modal-title" id="modal-sync-fftt-titre"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Synchroniser salles principales depuis FFTT</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="btn-fermer-sync-fftt"></button>
      </div>
      <div class="modal-body">

        <div id="sync-fftt-step1">
          <p class="text-muted small mb-3">
            Seuls les clubs <strong>déjà présents dans votre base</strong> et appartenant au département choisi
            sont traités. Pour chaque club, la salle principale est créée ou mise à jour via <code>xml_club_detail</code>.
          </p>
          <div class="input-group mb-3" style="max-width:420px">
            <label class="input-group-text" for="sync-fftt-dept"><i class="bi bi-map me-1"></i>Département</label>
            <select id="sync-fftt-dept" class="form-select">
              <option value="">— Choisir —</option>
              <?php foreach ($deptActifs as $d): ?>
              <option value="<?= (int) $d['code'] ?>"><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-primary" id="btn-lancer-sync-fftt">
            <i class="bi bi-play-fill me-1"></i>Lancer la synchronisation
          </button>
        </div>

        <div id="sync-fftt-step2" style="display:none;">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span id="sync-fftt-label" class="fw-semibold small text-primary">Récupération des clubs…</span>
            <span id="sync-fftt-pct" class="small text-muted">0 %</span>
          </div>
          <div class="progress mb-3" style="height:18px;">
            <div id="sync-fftt-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 style="width:0%" role="progressbar"></div>
          </div>
          <div class="row text-center mb-3">
            <div class="col-3">
              <div class="fw-bold fs-5 text-primary"  id="sync-cnt-clubs">0</div>
              <div class="small text-muted">Clubs traités</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-success"  id="sync-cnt-new">0</div>
              <div class="small text-muted">Salles créées</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-info"     id="sync-cnt-maj">0</div>
              <div class="small text-muted">Salles mises à jour</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-warning"  id="sync-cnt-erreurs">0</div>
              <div class="small text-muted">Erreurs</div>
            </div>
          </div>
          <div id="sync-fftt-log" style="max-height:200px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

        <div id="sync-fftt-step3" style="display:none;">
          <div class="alert alert-success mb-3" id="sync-fftt-resume"></div>
          <div id="sync-fftt-log-final" style="max-height:260px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<div id="page-footer">
    <span id="status-bar">Prêt.</span>
    <span class="footer-copyright">
        &copy; <?= date('Y') ?> &mdash; Tous droits réservés &mdash;
        <img src="<?= base_url('img/logo_region.png') ?>" alt="" class="footer-logo" aria-hidden="true">
        Ligue Normandie de Tennis de Table &mdash; Version&nbsp;: <?= defined('APP_VERSION') ? APP_VERSION : '' ?>
    </span>
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const SALLE_BASE = '<?= site_url('salle') ?>';
const LAPOSTE_BASE = '<?= site_url('laposte') ?>';

const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const DEPT_USER = <?= json_encode($deptUser) ?>;

let lignes     = [];
let clubs      = [];
let cellActive = null;
let rowActive  = null;
const sortState = { col: 'nom_club', asc: true };
let searchTerm = '';
let deptFiltre = IS_ADMIN ? '' : (DEPT_USER ?? '');

function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
}

function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let result = term
        ? lignes.filter(l =>
            String(l.id_salle   ?? '').toLowerCase().includes(term) ||
            String(l.nom        ?? '').toLowerCase().includes(term) ||
            String(l.adresse    ?? '').toLowerCase().includes(term) ||
            String(l.cp         ?? '').toLowerCase().includes(term) ||
            String(l.ville      ?? '').toLowerCase().includes(term) ||
            String(l.id_club    ?? '').toLowerCase().includes(term) ||
            String(l.nom_club   ?? '').toLowerCase().includes(term))
        : [...lignes];

    const numFields = ['id_salle', 'id_laposte'];
    result.sort((a, b) => {
        if (numFields.includes(sortState.col)) {
            return sortState.asc ? (+a[sortState.col]) - (+b[sortState.col]) : (+b[sortState.col]) - (+a[sortState.col]);
        }
        if (sortState.col === 'est_principale') {
            return sortState.asc ? a.est_principale - b.est_principale : b.est_principale - a.est_principale;
        }
        const va = String(a[sortState.col] ?? '').toLowerCase();
        const vb = String(b[sortState.col] ?? '').toLowerCase();
        return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function renderGrille() {
    const $body = $('#tbody-grille').empty();
    refreshTriEntetes();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat.' : 'Aucune salle.';
        $body.append(`<tr><td colspan="8" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} salle(s).` : 'Aucune salle enregistrée.');
        return;
    }

    affichees.forEach((l) => {
        const idx = lignes.indexOf(l);
        const isNew = !!l._nouveau;
        const $tr = $('<tr>').attr('data-idx', idx).toggleClass('new-row', isNew);

        $tr.append(makeTd(isNew ? '(nouveau)' : l.id_salle, idx, 'id_salle', true));
        $tr.append(makeTd(l.id_club  ?? '', idx, 'id_club',  true));
        $tr.append(makeTd(l.nom_club ?? '', idx, 'nom_club', true));
        $tr.append(makeTd(l.nom,        idx, 'nom',        false));
        $tr.append(makeTd(l.adresse,    idx, 'adresse',    false));
        $tr.append(makeCpTd(l, idx));
        $tr.append(makeVilleTd(l, idx));
        $tr.append(makePrincipaleTd(l, idx));

        $tr.on('click', function () { selectionnerLigne(idx); });
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}` : `${lignes.length} salle(s)`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    $('#lbl-count').text(`${lignes.length} salle(s)`);
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? `col-${field.replace('_','-')}` : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        $td.on('click', function (e) { e.stopPropagation(); selectionnerCellule($(this)); });
    }
    return $td;
}

let mcvIdx = null;
let mcvIdLaPoste = null;
let _modalCpVille = null;
function getModalCpVille() {
    if (!_modalCpVille) _modalCpVille = new bootstrap.Modal(document.getElementById('modal-cp-ville'));
    return _modalCpVille;
}

function ouvrirModalCpVille(idx) {
    mcvIdx = idx;
    mcvIdLaPoste = lignes[idx].id_laposte ?? null;
    $('#mcv-cp').val(lignes[idx].cp ?? '');
    $('#mcv-ville').val(lignes[idx].ville ?? '');
    $('#mcv-msg').text('').css('color', '');
    $('#mcv-suggestions').hide();
    $('#mcv-suggestions-list').empty();
    getModalCpVille().show();
    setTimeout(() => $('#mcv-cp').trigger('focus'), 300);
}

function mcvRechercher() {
    const cp    = $('#mcv-cp').val().trim();
    const ville = $('#mcv-ville').val().trim();
    if (!cp && !ville) return;
    $('#mcv-suggestions').hide();
    $('#mcv-suggestions-list').empty();
    $.post(`${LAPOSTE_BASE}/recherche-laposte`, { cp, ville }, function (res) {
        if (!res.ok) {
            $('#mcv-msg').text(res.msg ?? 'Commune non trouvée.').css('color', '#c00');
            mcvIdLaPoste = null;
            return;
        }
        if (res.multi) {
            $('#mcv-msg').text('').css('color', '');
            const $list = $('#mcv-suggestions-list').empty();
            res.suggestions.forEach(s => {
                $('<button class="btn btn-sm btn-outline-primary">')
                    .text(`${s.cp} ${s.ville}`)
                    .on('click', function () {
                        $('#mcv-cp').val(s.cp);
                        $('#mcv-ville').val(s.ville);
                        mcvIdLaPoste = s.id_laposte;
                        $('#mcv-msg').text(`✓ ${s.cp} ${s.ville}`).css('color', '#065f46');
                        $('#mcv-suggestions').hide();
                    }).appendTo($list);
            });
            $('#mcv-suggestions').show();
            mcvIdLaPoste = null;
        } else {
            $('#mcv-cp').val(res.cp);
            $('#mcv-ville').val(res.ville);
            mcvIdLaPoste = res.id_laposte;
            $('#mcv-msg').text(`✓ ${res.cp} ${res.ville}`).css('color', '#065f46');
        }
    }, 'json').fail(() => $('#mcv-msg').text('Erreur réseau.').css('color', '#c00'));
}

$('#mcv-cp, #mcv-ville').on('blur', function () { mcvRechercher(); });
$('#mcv-cp').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#mcv-ville').trigger('focus'); } });
$('#mcv-ville').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); mcvRechercher(); } });

$('#btn-mcv-ok').on('click', function () {
    if (mcvIdx === null) return;
    const cp    = $('#mcv-cp').val().trim() || null;
    const ville = $('#mcv-ville').val().trim().toUpperCase() || null;
    lignes[mcvIdx].cp         = cp;
    lignes[mcvIdx].ville      = ville;
    lignes[mcvIdx].id_laposte = mcvIdLaPoste;
    getModalCpVille().hide();
    renderGrille();
    setStatus(cp ? `CP/Ville mis à jour : ${cp} ${ville ?? ''}.` : 'CP/Ville effacés.');
});

function makeVilleTd(l, idx) {
    const $td  = $('<td>').attr('data-idx', idx).attr('data-field', 'ville');
    const $div = $('<div class="cell-inner">').text(l.ville ?? '');
    $td.append($div);
    if (IS_ADMIN) {
        $td.css('cursor', 'pointer').on('click', function (e) { e.stopPropagation(); ouvrirModalCpVille(idx); });
    }
    return $td;
}

function makeCpTd(l, idx) {
    const $td  = $('<td>').attr('data-idx', idx).attr('data-field', 'cp');
    const $div = $('<div class="cell-inner">').text(l.cp ?? '');
    $td.append($div);
    if (IS_ADMIN) {
        $td.css('cursor', 'pointer').on('click', function (e) { e.stopPropagation(); ouvrirModalCpVille(idx); });
    }
    return $td;
}

function makePrincipaleTd(l, idx) {
    const $td  = $('<td class="col-principale">').attr('data-idx', idx).attr('data-field', 'est_principale');
    const $cb  = $('<input type="checkbox">').prop('checked', !!l.est_principale);
    $cb.on('change', function () {
        lignes[idx].est_principale = this.checked ? 1 : 0;
        setStatus('Modification locale. Cliquez sur « Enregistrer » pour sauvegarder.');
    });
    $td.append($cb);
    return $td;
}

function selectionnerLigne(idx) {
    rowActive = idx;
    $('#tbody-grille tr').removeClass('selected');
    $(`#tbody-grille tr[data-idx="${idx}"]`).addClass('selected');
}

function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    const idx = +$td.attr('data-idx');
    selectionnerLigne(idx);
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

$(document).on('keydown', function (e) {
    if (!cellActive) return;
    const $inner = cellActive.find('.cell-inner');

    if (e.key === 'F2' && $inner.attr('contenteditable') === 'false') {
        e.preventDefault();
        $inner.attr('contenteditable', 'true').trigger('focus');
        const range = document.createRange();
        range.selectNodeContents($inner[0]);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

    } else if (e.key === 'Escape') {
        const idx   = +cellActive.attr('data-idx');
        const field = cellActive.attr('data-field');
        $inner.text(lignes[idx]?.[field] ?? '').attr('contenteditable', 'false');
        setStatus('Modification annulée.');

    } else if (e.key === 'Enter' && $inner.attr('contenteditable') === 'true') {
        e.preventDefault();
        if (cellActive.attr('data-field') === 'cp') {
            $inner.trigger('blur');
        } else {
            validerCellule($inner, cellActive);
        }
    }
});

$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    const val   = $inner.text().trim();
    if (lignes[idx]) {
        lignes[idx][field] = val !== '' ? val : null;
    }
    setStatus('Modification locale. Cliquez sur « Enregistrer » pour sauvegarder.');
}

function chargerClubs() {
    return $.get(`${SALLE_BASE}/clubs`, function (res) {
        if (res.ok) clubs = res.data.map(r => ({ id_club: r.Id_Club, nom: r.Nom }));
    }, 'json');
}

function chargerListe() {
    spinner(true);
    chargerClubs().then(() => {
        $.get(`${SALLE_BASE}/data`, { dept: deptFiltre }, function (res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            lignes = res.data.map(r => ({
                id_salle:       r.Id_Salle,
                nom:            r.Nom,
                adresse:        r.Adresse,
                id_laposte:     r.Id_Laposte,
                cp:             r.Cp    ?? '',
                ville:          r.Ville ?? '',
                cp_ville:       r.CpVille ?? '',
                id_club:        r.Id_Club,
                nom_club:       r.NomClub ?? '',
                est_principale: r.EstPrincipale,
            }));
            renderGrille();
        }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
    });
}

$('#btn-ajouter').on('click', function () {
    lignes.push({
        id_salle: 0, nom: '', adresse: null,
        id_laposte: null, id_club: null, nom_club: '', est_principale: 0,
    });
    renderGrille();
    const newIdx = lignes.length - 1;
    selectionnerLigne(newIdx);
    const $tr = $(`#tbody-grille tr[data-idx="${newIdx}"]`);
    $tr[0]?.scrollIntoView({ block: 'nearest' });
    const $nomTd = $tr.find('td[data-field="nom"]');
    selectionnerCellule($nomTd);
    $nomTd.find('.cell-inner').attr('contenteditable', 'true').trigger('focus');
});

$('#btn-supprimer').on('click', function () {
    if (rowActive === null) { toast('Sélectionnez une ligne à supprimer.', false); return; }
    const l = lignes[rowActive];
    if (!l) return;

    const label = l.nom || '(nouvelle ligne)';
    if (!confirm(`Supprimer la salle « ${label} » ?`)) return;

    if (l.id_salle === 0) {
        lignes.splice(rowActive, 1);
        rowActive = null;
        renderGrille();
        return;
    }

    spinner(true);
    $.ajax({ url: `${SALLE_BASE}/${l.id_salle}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) { rowActive = null; chargerListe(); }
    }).fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

$('#btn-sauvegarder').on('click', function () {
    const modifiees = lignes
        .filter(l => l.nom !== '' && l.nom !== null)
        .map(l => l._nouveau ? { ...l, id_salle: 0 } : l);
    if (!modifiees.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Enregistrer ${modifiees.length} salle(s) ?`)) return;

    spinner(true);
    $.post(`${SALLE_BASE}/save`, {
        lignes: JSON.stringify(modifiees),
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-salles thead th[data-field]', 'field', sortState, renderGrille);
});

$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

let syncEnCours = false;

function resetSyncFftt() {
    $('#sync-fftt-step1').show();
    $('#sync-fftt-step2, #sync-fftt-step3').hide();
    $('#sync-fftt-dept').val('');
    $('#sync-fftt-bar').css('width', '0%');
    $('#sync-fftt-label').text('Récupération des clubs…');
    $('#sync-fftt-pct').text('0 %');
    ['sync-cnt-clubs','sync-cnt-new','sync-cnt-maj','sync-cnt-erreurs'].forEach(id => $(`#${id}`).text('0'));
    $('#sync-fftt-log, #sync-fftt-log-final').empty();
    syncEnCours = false;
}

$('#modal-sync-fftt').on('hidden.bs.modal', function () {
    if (!syncEnCours) resetSyncFftt();
});

$('#btn-lancer-sync-fftt').on('click', function () {
    const dep = $('#sync-fftt-dept').val();
    if (!dep) { alert('Sélectionnez un département.'); return; }

    syncEnCours = true;
    $('#sync-fftt-step1').hide();
    $('#sync-fftt-step2').show();
    $('#btn-fermer-sync-fftt').prop('disabled', true);

    let cntClubs = 0, cntNew = 0, cntMaj = 0, cntErreurs = 0;
    const logLines = [];

    $.post(`${SALLE_BASE}/fftt/clubs`, { dep }, function (res) {
        if (!res.ok) {
            alert('Erreur : ' + res.msg);
            resetSyncFftt();
            $('#sync-fftt-step1').show();
            $('#sync-fftt-step2').hide();
            $('#btn-fermer-sync-fftt').prop('disabled', false);
            return;
        }

        const clubs = res.clubs;
        const total = clubs.length;
        let done = 0;

        if (total === 0) {
            syncEnCours = false;
            $('#btn-fermer-sync-fftt').prop('disabled', false);
            $('#sync-fftt-step2').hide();
            $('#sync-fftt-step3').show();
            $('#sync-fftt-resume').html('<i class="bi bi-info-circle-fill me-2"></i>Aucun club de ce département trouvé dans votre base.');
            return;
        }

        $('#sync-fftt-label').text(`0 / ${total} clubs…`);

        function traiterClub() {
            if (done >= total) {
                syncEnCours = false;
                $('#btn-fermer-sync-fftt').prop('disabled', false);
                $('#sync-fftt-step2').hide();
                $('#sync-fftt-step3').show();
                $('#sync-fftt-resume').html(
                    `<i class="bi bi-check-circle-fill me-2"></i>` +
                    `Synchronisation terminée — <strong>${cntClubs}</strong> club(s) traité(s), ` +
                    `<strong>${cntNew}</strong> salle(s) créée(s), ` +
                    `<strong>${cntMaj}</strong> mise(s) à jour` +
                    (cntErreurs ? `, <strong>${cntErreurs}</strong> erreur(s)` : '') + '.'
                );
                $('#sync-fftt-log-final').html(logLines.join(''));
                chargerListe();
                return;
            }

            const club = clubs[done];
            const pct  = Math.round(done / total * 100);
            $('#sync-fftt-bar').css('width', pct + '%');
            $('#sync-fftt-pct').text(pct + ' %');
            $('#sync-fftt-label').text(`${done + 1} / ${total} — ${club.nom}`);

            $.post(`${SALLE_BASE}/fftt/sync`, { num_club: club.numero }, function (r) {
                cntClubs++;
                $(`#sync-cnt-clubs`).text(cntClubs);
                if (r.ok && r.op) {
                    cntNew += r.cnt_new ?? 0;
                    cntMaj += r.cnt_maj ?? 0;
                    $(`#sync-cnt-new`).text(cntNew);
                    $(`#sync-cnt-maj`).text(cntMaj);
                    (r.ops ?? []).forEach(op => {
                        const cls  = op.includes('Créée') ? 'text-success' : 'text-info';
                        const line = `<div class="${cls}">[${club.numero}] ${op}</div>`;
                        logLines.push(line);
                        $('#sync-fftt-log').append(line).scrollTop(9999);
                    });
                } else if (r.ok && r.op === 'vide') {
                    const line = `<div class="text-warning">[${club.numero}] ${r.msg}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                } else if (r.ok && !r.op) {
                    const line = `<div class="text-secondary">[${club.numero}] ${r.msg ?? 'Ignoré'}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                } else {
                    cntErreurs++;
                    $(`#sync-cnt-erreurs`).text(cntErreurs);
                    const line = `<div class="text-danger">[${club.numero}] Erreur : ${r.msg ?? 'inconnue'}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                }
            }, 'json').fail(() => {
                cntErreurs++;
                $(`#sync-cnt-erreurs`).text(cntErreurs);
            }).always(() => {
                done++;
                setTimeout(traiterClub, 0);
            });
        }

        traiterClub();

    }, 'json').fail(() => {
        alert('Erreur réseau lors de la récupération des clubs.');
        resetSyncFftt();
        $('#sync-fftt-step1').show();
        $('#sync-fftt-step2').hide();
        $('#btn-fermer-sync-fftt').prop('disabled', false);
    });
});

$(function () { chargerListe(); });
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
