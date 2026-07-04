<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= esc($csrfToken) ?>">
    <title>NIJAC – Clubs et Associations (E008)</title>

    <link rel="stylesheet" href="/asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="/asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/asset/css/nijac.css">

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

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Grille ── */
        #grid-wrapper { flex: 1; overflow: auto; }

        #tbl-clubs {
            width: 100%;
            font-size: .85rem;
            border-collapse: collapse;
            min-width: 400px;
        }

        #tbl-clubs thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .35rem .6rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
        }

        #tbl-clubs tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-clubs tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-clubs tbody tr:hover   { background: #dce8f8; }
        #tbl-clubs tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-clubs tbody tr.en-region { background: #d1fae5; }
        #tbl-clubs tbody tr.en-region:nth-child(even) { background: #a7f3d0; }
        #tbl-clubs tbody tr.en-region:hover { background: #6ee7b7; }
        #tbl-clubs tbody tr.en-region.selected { background: #b8d0f0 !important; }
        #tbl-clubs tbody tr.hors-region { background: #e5e7eb; }
        #tbl-clubs tbody tr.hors-region:nth-child(even) { background: #d1d5db; }
        #tbl-clubs tbody tr.hors-region:hover { background: #9ca3af; }
        #tbl-clubs tbody tr.hors-region.selected { background: #b8d0f0 !important; }
        #tbl-clubs tbody td { border: 1px solid #e0e8f0; padding: 0; }

        /* Cellule éditable */
        .cell-inner {
            display: block;
            padding: .28rem .5rem;
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

        td.col-id .cell-inner {
            color: #6b7280;
            font-style: italic;
            background: #f0f4fa;
        }
        td.col-id.id-modifie .cell-inner {
            color: #b45309;
            font-style: normal;
            font-weight: 700;
            background: #fef3c7;
        }

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 220px;
        }

        /* ── En-têtes triables ── */
        #tbl-clubs thead th { cursor: pointer; user-select: none; }
        #tbl-clubs thead th:hover { background: #d4dff0; }
        #tbl-clubs thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-clubs thead th.sort-asc .sort-icon::after  { content: '▲'; opacity: 1; }
        #tbl-clubs thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-clubs thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        /* ── Spinner ── */
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
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <span style="font-size:.78rem;font-weight:400;">
            <a href="<?= site_url('admin-menu') ?>" style="color:#cfe0ff;text-decoration:none;">Admin</a>
            <span class="mx-1" style="color:#cfe0ff;">&rsaquo;</span>
        </span>
        <i class="bi bi-building me-2"></i>Gestion des clubs et Associations
        <small class="ms-2" style="color:#cfe0ff;">(E008)</small>
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

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-sync-fftt" data-bs-toggle="modal" data-bs-target="#modal-sync-fftt">
        <i class="bi bi-cloud-arrow-down-fill"></i>Synchroniser depuis FFTT
    </button>
    <button class="menu-item" id="btn-maj-bdd">
        <i class="bi bi-database-fill-up"></i>Mettre à jour la Base de données
    </button>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 club(s)</span>
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= esc($d['code']) ?>"><?= esc($d['code']) ?> — <?= esc($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="menu-item" id="btn-plusieurs-salles" title="Afficher uniquement les clubs ayant plusieurs salles" style="border-color:transparent;">
        <i class="bi bi-door-open me-1"></i>Plusieurs salles
    </button>
    <button class="menu-item" id="btn-filtre-region" title="Cliquer pour filtrer par région" style="border-color:transparent;">
        <i class="bi bi-geo-alt me-1"></i><span id="lbl-filtre-region">Région</span>
    </button>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-clubs">
        <thead>
            <tr>
                <th style="width:120px" data-field="id_club">N° FFTT<span class="sort-icon"></span></th>
                <th data-field="nom">Nom club<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="cor_nom">Correspondant<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="cor_email">Email cor.<span class="sort-icon"></span></th>
                <th style="width:120px" data-field="cor_tel">Tél. cor.<span class="sort-icon"></span></th>
                <th style="width:100px" data-field="code_postal">Code postal<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="ville">Ville<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="7" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<div id="page-footer">
    <span id="status-bar">Prêt. — Cliquez sur une cellule puis appuyez sur F2 pour modifier.</span>
    <span class="footer-copyright">
        &copy; <?= date('Y') ?> &mdash; Tous droits réservés &mdash;
        <img src="/img/logo_region.png" alt="" class="footer-logo" aria-hidden="true">
        Ligue Normandie de Tennis de Table &mdash; Version&nbsp;: <?= defined('APP_VERSION') ? APP_VERSION : '' ?>
    </span>
</div>

<!-- Modale Synchronisation FFTT -->
<div class="modal fade" id="modal-sync-fftt" tabindex="-1" aria-labelledby="modal-sync-fftt-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#0d6efd;color:#fff;">
        <h5 class="modal-title" id="modal-sync-fftt-titre"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Synchroniser clubs / salles / correspondants depuis FFTT</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="btn-fermer-sync-fftt"></button>
      </div>
      <div class="modal-body">

        <!-- Étape 1 : choix département -->
        <div id="sync-fftt-step1">
          <p class="text-muted small mb-3">
            Pour le département choisi, chaque club est mis à jour via <code>xml_club_detail</code> :
            nom du club, salle principale (nom, adresse, commune) et correspondant (nom, email, téléphone).
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

        <!-- Étape 2 : progression -->
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
              <div class="fw-bold fs-5 text-primary" id="sync-cnt-clubs">0</div>
              <div class="small text-muted">Clubs traités</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-success" id="sync-cnt-salles">0</div>
              <div class="small text-muted">Salles sync.</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-info" id="sync-cnt-cors">0</div>
              <div class="small text-muted">Correspondants</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-warning" id="sync-cnt-erreurs">0</div>
              <div class="small text-muted">Erreurs</div>
            </div>
          </div>
          <div id="sync-fftt-log" style="max-height:200px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

        <!-- Étape 3 : résumé -->
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

<script src="/asset/js/jquery-3.7.1.min.js"></script>
<script src="/asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const CLUB_BASE = '<?= site_url('club') ?>';
const DEPTS_REGION = new Set(<?= json_encode(array_column($deptActifs, 'code')) ?>);

function deptDeClub(idClub) {
    // Format : 0[9][dept 2 chiffres][4 chiffres] — ex. 09760442 → '76'
    return String(idClub ?? '').substring(2, 4);
}

let lignes           = [];
let cellActive       = null;
const sortState      = { col: 'id_club', asc: true };
let searchTerm       = '';
let deptFiltre       = '';   // filtré côté JS
let filtreMultiSalle = false;
let filtreRegion = 0; // 0 = Tous, 1 = En région, 2 = Hors région

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let result = [...lignes];
    if (deptFiltre)      result = result.filter(l => deptDeClub(l.id_club) === deptFiltre);
    if (filtreMultiSalle) result = result.filter(l => (l.nb_salles ?? 0) > 1);
    if (filtreRegion === 1) result = result.filter(l =>  DEPTS_REGION.has(deptDeClub(l.id_club)));
    if (filtreRegion === 2) result = result.filter(l => !DEPTS_REGION.has(deptDeClub(l.id_club)));
    if (term) result = result.filter(l =>
        String(l.id_club     ?? '').toLowerCase().includes(term) ||
        String(l.nom         ?? '').toLowerCase().includes(term) ||
        String(l.code_postal ?? '').toLowerCase().includes(term) ||
        String(l.ville       ?? '').toLowerCase().includes(term) ||
        String(l.cor_nom     ?? '').toLowerCase().includes(term) ||
        String(l.cor_email   ?? '').toLowerCase().includes(term));

    result.sort((a, b) => {
        const va = String(a[sortState.col] ?? '').toLowerCase();
        const vb = String(b[sortState.col] ?? '').toLowerCase();
        return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    refreshTriEntetes();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun club.';
        $body.append(`<tr><td colspan="7" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} club(s).` : 'Aucun club enregistré.');
        return;
    }

    affichees.forEach((l) => {
        const idx  = lignes.indexOf(l);
        const dept = deptDeClub(l.id_club);
        const $tr  = $('<tr>').attr('data-idx', idx);
        if (dept && !DEPTS_REGION.has(dept)) $tr.addClass('hors-region').attr('title', `Département ${dept} hors région`);
        else if (dept) $tr.addClass('en-region');
        $tr.append(makeTd(l.id_club,     idx, 'id_club',     false));
        $tr.append(makeTd(l.nom,         idx, 'nom',         false));
        const horsRegion = dept && !DEPTS_REGION.has(dept);
        $tr.append(makeTd(l.cor_nom,     idx, 'cor_nom',     horsRegion));
        $tr.append(makeTd(l.cor_email,   idx, 'cor_email',   horsRegion));
        $tr.append(makeTd(l.cor_tel,     idx, 'cor_tel',     horsRegion));
        $tr.append(makeTd(l.code_postal, idx, 'code_postal', true));
        $tr.append(makeTd(l.ville,       idx, 'ville',       true));
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}` : `${lignes.length} club(s)`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    $('#lbl-count').text(`${lignes.length} club(s)`);
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? 'col-id' : '').attr('data-idx', idx).attr('data-field', field);
    if (field === 'id_club') {
        $td.addClass('col-id');
        const l = lignes[idx];
        if (l && l.id_club_orig !== undefined && String(val) !== String(l.id_club_orig)) {
            $td.addClass('id-modifie').attr('title', `Ancien N° : ${l.id_club_orig}`);
        }
    }
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        $td.on('click', function () { selectionnerCellule($(this)); });
    }
    return $td;
}

function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    $td.closest('tr').addClass('selected');
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

// ── Clavier : F2 / Échap / Entrée ────────────────────────────────────────────
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
        validerCellule($inner, cellActive);
    }
});

$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx    = +$td.attr('data-idx');
    const field  = $td.attr('data-field');
    const newVal = $inner.text().trim() || null;

    function appliquer() {
        if (lignes[idx]) lignes[idx][field] = newVal;
        renderGrille();
        setStatus('Modification locale. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
    }

    if (field === 'id_club' && lignes[idx]) {
        const oldId = lignes[idx].id_club_orig ?? lignes[idx].id_club;
        const newId = newVal ? +newVal : 0;
        if (newId && newId !== +oldId) {
            nijacConfirm(
                `Modifier le N° FFTT ${oldId} → ${newId} ?\n\nCette modification mettra également à jour toutes les tables liées (salles, correspondants, équipes, JA).`,
                appliquer,
                function () {
                    $inner.text(lignes[idx].id_club ?? '');
                    setStatus('Modification annulée.');
                },
                { type: 'warning', confirmLabel: 'Modifier' }
            );
            return;
        }
    }

    appliquer();
}

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.get(`${CLUB_BASE}/liste`, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map(r => ({
            id_club:      r.Id_Club,
            id_club_orig: r.Id_Club,
            nom:          r.Nom,
            code_postal:  r.CodePostal ?? '',
            ville:        r.Ville      ?? '',
            nb_salles:    +(r.NbSalles ?? 0),
            cor_nom:      r.CorNom      ?? '',
            cor_email:    r.CorEmail    ?? '',
            cor_tel:      r.CorTelephone ?? '',
        }));
        const aMultiSalles = lignes.some(l => l.nb_salles > 1);
        $('#btn-plusieurs-salles').toggle(aMultiSalles);
        if (!aMultiSalles && filtreMultiSalle) {
            filtreMultiSalle = false;
            $('#btn-plusieurs-salles').css({ background: '', color: '', borderColor: 'transparent' });
        }
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Mettre à jour la BDD ──────────────────────────────────────────────────────
$('#btn-maj-bdd').on('click', function () {
    if (!lignes.length) { toast('Aucune donnée à enregistrer.', false); return; }
    nijacConfirm(`Mettre à jour la base de données avec ${lignes.length} club(s) ?`, function () {
        spinner(true);
        $.post(`${CLUB_BASE}/maj-bdd`, {
            lignes: JSON.stringify(lignes),
        }, function (res) {
            spinner(false);
            toast(res.msg, res.ok);
            if (res.ok) chargerListe();
        }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
    });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé en fin de page, donc pas encore
// défini si on l'appelait ici de façon synchrone.
let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-clubs thead th[data-field]', 'field', sortState, renderGrille);
});

// ── Filtre plusieurs salles ───────────────────────────────────────────────────
$('#btn-plusieurs-salles').on('click', function () {
    filtreMultiSalle = !filtreMultiSalle;
    $(this).toggleClass('active', filtreMultiSalle)
           .css({
               background:   filtreMultiSalle ? '#1a3a6b' : '',
               color:        filtreMultiSalle ? '#fff'    : '',
               borderColor:  filtreMultiSalle ? '#1a3a6b' : 'transparent',
           });
    renderGrille();
});

// ── Filtre région / hors région (3 états) ────────────────────────────────────
$('#btn-filtre-region').on('click', function () {
    filtreRegion = (filtreRegion + 1) % 3;
    const labels = ['Région', 'En région', 'Hors région'];
    const bgs    = ['',        '#166534',   '#be123c'];
    const colors = ['',        '#fff',       '#fff'];
    const bords  = ['transparent', '#166534', '#be123c'];
    $('#lbl-filtre-region').text(labels[filtreRegion]);
    $(this).css({ background: bgs[filtreRegion], color: colors[filtreRegion], borderColor: bords[filtreRegion] });
    renderGrille();
});

// ── Filtre département ────────────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    renderGrille();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Synchronisation FFTT ─────────────────────────────────────────────────────
let syncFfttEnCours = false;

$('#modal-sync-fftt').on('hidden.bs.modal', function () {
    if (!syncFfttEnCours) resetSyncFftt();
});

function resetSyncFftt() {
    $('#sync-fftt-step1').show();
    $('#sync-fftt-step2, #sync-fftt-step3').hide();
    $('#sync-fftt-dept').val('');
    $('#sync-fftt-bar').css('width', '0%');
    $('#sync-fftt-label').text('Récupération des clubs…');
    $('#sync-fftt-pct').text('0 %');
    ['sync-cnt-clubs','sync-cnt-salles','sync-cnt-cors','sync-cnt-erreurs'].forEach(id => $(`#${id}`).text('0'));
    $('#sync-fftt-log, #sync-fftt-log-final').empty();
    syncFfttEnCours = false;
}

$('#btn-lancer-sync-fftt').on('click', function () {
    const dep = $('#sync-fftt-dept').val();
    if (!dep) { nijacToast('Sélectionnez un département.', 'warning'); return; }

    syncFfttEnCours = true;
    $('#sync-fftt-step1').hide();
    $('#sync-fftt-step2').show();
    $('#btn-fermer-sync-fftt').prop('disabled', true);

    let cntClubs = 0, cntSalles = 0, cntCors = 0, cntErreurs = 0;
    const logLines = [];

    $.post(`${CLUB_BASE}/fftt/clubs-dept`, { dep }, function (res) {
        if (!res.ok) {
            nijacToast('Erreur : ' + res.msg, 'danger');
            resetSyncFftt();
            $('#sync-fftt-step1').show();
            $('#sync-fftt-step2').hide();
            $('#btn-fermer-sync-fftt').prop('disabled', false);
            return;
        }

        const clubs = res.clubs;
        const total = clubs.length;
        let done = 0;

        $('#sync-fftt-label').text(`0 / ${total} clubs…`);

        function traiterClub() {
            if (done >= total) {
                syncFfttEnCours = false;
                $('#btn-fermer-sync-fftt').prop('disabled', false);
                $('#sync-fftt-step2').hide();
                $('#sync-fftt-step3').show();
                $('#sync-fftt-resume').html(
                    `<i class="bi bi-check-circle-fill me-2"></i>` +
                    `Synchronisation terminée — <strong>${cntClubs}</strong> club(s), ` +
                    `<strong>${cntSalles}</strong> salle(s), ` +
                    `<strong>${cntCors}</strong> correspondant(s)` +
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

            $.post(`${CLUB_BASE}/fftt/sync`, { num_club: club.numero }, function (r) {
                if (r.ok) {
                    cntClubs++;
                    r.ops.forEach(op => {
                        let cls = 'text-secondary';
                        if (op.includes('Salle'))        { cls = 'text-success'; cntSalles++; }
                        if (op.includes('Correspondant')){ cls = 'text-info';    cntCors++;   }
                        const line = `<div class="${cls}">[${club.numero}] ${op}</div>`;
                        logLines.push(line);
                        $('#sync-fftt-log').append(line).scrollTop(9999);
                    });
                    $('#sync-cnt-clubs').text(cntClubs);
                    $('#sync-cnt-salles').text(cntSalles);
                    $('#sync-cnt-cors').text(cntCors);
                } else {
                    cntErreurs++;
                    $('#sync-cnt-erreurs').text(cntErreurs);
                    const line = `<div class="text-danger">[${club.numero}] Erreur : ${r.msg}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                }
                done++;
                traiterClub();
            }, 'json').fail(() => {
                cntErreurs++;
                $('#sync-cnt-erreurs').text(cntErreurs);
                done++;
                traiterClub();
            });
        }

        traiterClub();
    }, 'json').fail(() => {
        nijacToast('Erreur réseau.', 'danger');
        resetSyncFftt();
        $('#sync-fftt-step1').show();
        $('#sync-fftt-step2').hide();
        $('#btn-fermer-sync-fftt').prop('disabled', false);
    });
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<script src="/asset/js/nijac-csrf.js"></script>
<script src="/asset/js/nijac-toast.js"></script>
<script src="/asset/js/nijac-sortable-table.js"></script>
</body>
</html>
