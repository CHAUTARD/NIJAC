<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Salles (EA81)</title>

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
        #tbl-salles tbody td { border: 1px solid #e0e8f0; padding: 0; }

        .cell-inner {
            display: block;
            padding: .28rem .45rem;
            min-height: 28px;
            outline: none;
            white-space: nowrap;
            overflow: hidden;
        }

        td.col-id, td.col-nom-club { background: #f0f4fa; }
        td.col-id .cell-inner, td.col-nom-club .cell-inner { color: #6b7280; font-style: italic; }

        td.col-principale { text-align: center; vertical-align: middle; }
        td.col-principale input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }


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
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }

        /* Comboboxes de filtre, badge de comptage et menu « Colonnes » :
           style partagé dans asset/css/nijac.css (.combo-field, .count-badge). */
    </style>
</head>
<body>

<?php $backUrl = $isAdmin ? site_url('admin-menu') : site_url('nominateur-menu'); ?>
<?= view('partials/page_header', [
    'phIcon' => 'building-fill', 'phTitle' => 'Gestion des salles', 'phCode' => 'EA81',
    'phCrumbLabel' => $isAdmin ? 'Admin' : 'Nominateur', 'phCrumbUrl' => $backUrl, 'phBackUrl' => $backUrl,
]) ?>
</div>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<!-- MenuStrip -->
<div id="menu-strip">
<?php if ($isAdmin): ?>
    <button class="btn btn-sm btn-outline-primary" id="btn-sync-fftt" data-bs-toggle="modal" data-bs-target="#modal-sync-fftt">
        <i class="bi bi-cloud-arrow-down-fill me-1"></i>Synchroniser depuis FFTT
    </button>
    <button class="btn btn-sm btn-outline-success" id="btn-ajouter">
        <i class="bi bi-plus-circle me-1"></i>Ajouter
    </button>
    <span class="count-badge" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
    <span class="combo-field">
        <details id="menu-colonnes">
            <summary id="menu-colonnes-resume">Colonnes</summary>
            <div id="menu-colonnes-list"></div>
        </details>
    </span>
    <span style="flex:1"></span>
    <span class="combo-field">
        <label for="sel-dept">Département</label>
        <select id="sel-dept">
            <option value="">Tous les départements</option>
            <?php foreach ($deptActifs as $d): ?>
            <option value="<?= (int) $d['CodeDept'] ?>"><?= (int) $d['CodeDept'] ?> — <?= esc($d['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </span>
    <span class="combo-field">
        <label for="sel-perimetre">Périmètre</label>
        <select id="sel-perimetre">
            <option value="1">Région uniquement</option>
            <option value="0">Tous les départements</option>
        </select>
    </span>
<?php else: ?>
    <span style="padding:.2rem .6rem; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; font-size:.82rem; color:#856404;">
        <i class="bi bi-eye-fill me-1"></i>Consultation — département <?= esc($departement) ?>
    </span>
    <span class="count-badge" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
    <span class="combo-field">
        <details id="menu-colonnes">
            <summary id="menu-colonnes-resume">Colonnes</summary>
            <div id="menu-colonnes-list"></div>
        </details>
    </span>
    <span style="flex:1"></span>
<?php endif; ?>
    <span class="combo-field">
        <label for="sel-laposte">Coordonnées</label>
        <select id="sel-laposte">
            <option value="0">Toutes les salles</option>
            <option value="1">Sans coordonnées La Poste</option>
        </select>
    </span>
    <span class="combo-field">
        <label for="search-input">Recherche</label>
        <input type="search" id="search-input" placeholder="Nom, ville, club…">
    </span>
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-salles">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id_salle">Id_Salle<span class="sort-icon"></span></th>
                <th style="width:80px"  data-field="id_club">Id_Club<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="nom_club" title="Clubs liés à la salle (★ = salle principale du club)">Clubs liés<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:230px" data-field="adresse">Adresse<span class="sort-icon"></span></th>
                <th style="width:120px" data-field="telephone">Téléphone<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="cp">Code postal<span class="sort-icon"></span></th>
                <th style="width:170px" data-field="ville">Ville<span class="sort-icon"></span></th>
                <th style="width:80px"  data-field="la_poste">La Poste<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="est_principale" title="Salle principale d'au moins un de ses clubs (à modifier dans la fiche)">Principale<span class="sort-icon"></span></th>
                <?php if ($isAdmin): ?>
                <th style="width:96px"></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="11" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Modale Ajouter / Modifier une salle -->
<div class="modal fade" id="modal-modifier-salle" tabindex="-1" aria-labelledby="modal-modifier-salle-titre" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a3a6b;color:#fff;">
        <h6 class="modal-title mb-0" id="modal-modifier-salle-titre"><i class="bi bi-building me-2"></i>Modifier la salle</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="font-size:.88rem;">
        <input type="hidden" id="mms-idx">
        <div class="mb-2" id="mms-numero-wrap" style="display:none;">
          <label class="form-label fw-semibold" style="font-size:.82rem;">Id_Salle</label>
          <input type="text" id="mms-numero" class="form-control form-control-sm" readonly>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.82rem;">Clubs liés</label>
          <div id="mms-liens" class="mb-1"></div>
          <div class="input-group input-group-sm">
            <input type="text" id="mms-club" class="form-control" list="mms-club-list" placeholder="Id_Club à lier, ex. 07640001" autocomplete="off">
            <button type="button" class="btn btn-outline-success" id="mms-btn-lier"><i class="bi bi-link-45deg me-1"></i>Lier</button>
          </div>
          <datalist id="mms-club-list"></datalist>
          <div id="mms-club-nom" class="form-text" style="min-height:1.1em;"></div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.82rem;">Nom <span class="text-danger">*</span></label>
          <input type="text" id="mms-nom" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.82rem;">Adresse</label>
          <input type="text" id="mms-adresse" class="form-control form-control-sm">
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.82rem;">Téléphone</label>
          <input type="text" id="mms-telephone" class="form-control form-control-sm" placeholder="00.00.00.00.00" maxlength="14">
        </div>
        <div class="mb-1">
          <label class="form-label fw-semibold" style="font-size:.82rem;">Code postal / Ville</label>
          <div class="input-group input-group-sm">
            <input type="text" id="mms-cp" class="form-control" placeholder="76000" maxlength="10" style="max-width:90px">
            <input type="text" id="mms-ville" class="form-control text-uppercase" placeholder="ROUEN">
          </div>
        </div>
        <div id="mms-cp-msg" class="form-text" style="min-height:1.2em;"></div>
        <div id="mms-suggestions" style="display:none;">
          <div class="fw-semibold text-primary small mb-1">Plusieurs communes — choisissez :</div>
          <div id="mms-suggestions-list" class="d-flex flex-wrap gap-1"></div>
        </div>
        <div id="mms-msg" style="font-size:.8rem;min-height:18px;margin-top:.4rem;"></div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-primary btn-sm" id="mms-btn-ok"><i class="bi bi-floppy-fill me-1"></i>Enregistrer</button>
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
            sont traités. Pour chaque club, ses salles sont créées ou mises à jour via <code>xml_club_detail</code> et liées
            au club (la première devient sa salle principale) ; une salle déjà enregistrée au même lieu est réutilisée, pas dupliquée.
          </p>
          <div class="input-group mb-3" style="max-width:420px">
            <label class="input-group-text" for="sync-fftt-dept"><i class="bi bi-map me-1"></i>Département</label>
            <select id="sync-fftt-dept" class="form-select">
              <option value="">— Choisir —</option>
              <?php foreach ($deptActifs as $d): ?>
              <option value="<?= (int) $d['CodeDept'] ?>"><?= (int) $d['CodeDept'] ?> — <?= esc($d['nom']) ?></option>
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
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
const SALLE_BASE = '<?= site_url('salle') ?>';
const LAPOSTE_BASE = '<?= site_url('laposte') ?>';

const IS_ADMIN  = <?= $isAdmin ? 'true' : 'false' ?>;
const DEPT_USER = <?= json_encode($deptUser) ?>;
const DEPTS_REGION = new Set(<?= json_encode(array_column($deptActifs, 'CodeDept')) ?>);

let lignes     = [];
let clubs      = [];
let rowActive  = null;
const sortState = { col: 'nom_club', asc: true };
let searchTerm = '';
let filtreLaPosteManquant = false; // true = uniquement les salles sans coordonnées La Poste
let deptFiltre = IS_ADMIN ? '' : (DEPT_USER ?? '');
let filtreEnRegion = true; // true = En région uniquement (par défaut), false = Tous

function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function deptDeSalle(l) {
    return String(l.cp ?? '').substring(0, 2);
}

function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let result = [...lignes];
    if (IS_ADMIN && filtreEnRegion) result = result.filter(l => DEPTS_REGION.has(deptDeSalle(l)));
    if (filtreLaPosteManquant) result = result.filter(l => !l.id_laposte);
    if (term) result = result.filter(l =>
            String(l.id_salle   ?? '').toLowerCase().includes(term) ||
            String(l.nom        ?? '').toLowerCase().includes(term) ||
            String(l.adresse    ?? '').toLowerCase().includes(term) ||
            String(l.telephone  ?? '').toLowerCase().includes(term) ||
            String(l.cp         ?? '').toLowerCase().includes(term) ||
            String(l.ville      ?? '').toLowerCase().includes(term) ||
            String(l.id_club    ?? '').toLowerCase().includes(term) ||
            String(l.nom_club   ?? '').toLowerCase().includes(term));

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
        $body.append(`<tr><td colspan="11" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} salle(s).` : 'Aucune salle enregistrée.');
        $('#lbl-count').text(`0 / ${lignes.length} salle(s)`);
        return;
    }

    affichees.forEach((l) => {
        const idx = lignes.indexOf(l);
        const $tr = $('<tr>').attr('data-idx', idx);

        $tr.append(makeTd(l.id_salle, idx, 'id_salle', true));
        $tr.append(makeTd(l.id_club  ?? '', idx, 'id_club',  true));
        $tr.append(makeTd(l.nom_club ?? '', idx, 'nom_club', true));
        $tr.append(makeTd(l.nom,        idx, 'nom',        false));
        $tr.append(makeTd(l.adresse,    idx, 'adresse',    false));
        $tr.append(makeTd(l.telephone,  idx, 'telephone',  false));
        $tr.append(makeTd(l.cp,         idx, 'cp',         false));
        $tr.append(makeTd(l.ville,      idx, 'ville',      false));
        $tr.append(makeTd(l.la_poste,   idx, 'la_poste',   true));
        $tr.append(makePrincipaleTd(l, idx));
        if (IS_ADMIN) $tr.append(makeActionsTd(idx));

        $tr.on('click', function () { selectionnerLigne(idx); });
        if (IS_ADMIN) $tr.on('dblclick', function () { ouvrirModalModifierSalle(idx); });
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}. ` : '';
    setStatus(`${info}Prêt.`);
    $('#lbl-count').text(`${affichees.length} / ${lignes.length} salle(s)`);
    appliquerColonnesCachees();
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? `col-${field.replace('_','-')}` : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '');
    $td.append($div);
    return $td;
}

function makeActionsTd(idx) {
    const $td = $('<td class="text-center">');
    $td.append(
        $('<button type="button" class="btn btn-sm btn-outline-primary btn-modifier-salle me-1" title="Modifier">')
            .attr('data-idx', idx)
            .html('<i class="bi bi-pencil-fill"></i>')
    );
    $td.append(
        $('<button type="button" class="btn btn-sm btn-outline-danger btn-supprimer-salle" title="Supprimer">')
            .attr('data-idx', idx)
            .html('<i class="bi bi-trash-fill"></i>')
    );
    return $td;
}

// Lecture seule : la salle principale se choisit par club dans la fiche (club_salle, une par club).
function makePrincipaleTd(l, idx) {
    const $td  = $('<td class="col-principale">').attr('data-idx', idx).attr('data-field', 'est_principale');
    const $cb  = $('<input type="checkbox">').prop('checked', !!l.est_principale).prop('disabled', true);
    $td.append($cb);
    return $td;
}

function selectionnerLigne(idx) {
    rowActive = idx;
    $('#tbody-grille tr').removeClass('selected');
    $(`#tbody-grille tr[data-idx="${idx}"]`).addClass('selected');
}

// Envoie l'état complet de la ligne (fusionné avec les overrides) au serveur ;
// une salle existante doit toujours être sauvegardée dans son intégralité,
// PUT /salle/{id} remplaçant toutes les colonnes.
function persisterSalle(idx, overrides, onSuccess, onError) {
    const l = lignes[idx];
    if (!l) return;
    const payload = {
        nom: l.nom, adresse: l.adresse, telephone: l.telephone, cp: l.cp, ville: l.ville,
        id_laposte: l.id_laposte,
        ...overrides,
    };
    spinner(true);
    $.ajax({ url: `${SALLE_BASE}/${l.id_salle}`, method: 'PUT', data: payload, dataType: 'json' })
        .done(function (res) {
            spinner(false);
            if (res.ok) { Object.assign(l, overrides); onSuccess?.(res); }
            else onError?.(res);
        })
        .fail(() => { spinner(false); onError?.({ msg: 'Erreur réseau.' }); });
}

function chargerClubs() {
    return $.get(`${SALLE_BASE}/clubs`, function (res) {
        if (res.ok) {
            clubs = res.data.map(r => ({ id_club: r.Id_Club, nom: r.Nom }));
            const $dl = $('#mms-club-list').empty();
            clubs.forEach(c => $('<option>').attr('value', c.id_club).text(c.nom).appendTo($dl));
        }
    }, 'json');
}

// Affiche le nom du club correspondant au N° saisi dans la modale salle
function majClubNom() {
    const v = $('#mms-club').val().trim();
    if (!v) { $('#mms-club-nom').text('').css('color', ''); return; }
    const c = clubs.find(c => String(c.id_club) === v);
    $('#mms-club-nom')
        .text(c ? `✓ ${c.nom}` : 'Id_Club inconnu')
        .css('color', c ? '#065f46' : '#c00');
}
$('#mms-club').on('input', majClubNom);

// Clubs liés à la salle en cours d'édition (club_salle) : [{id_club, nom, principale}].
// Envoyés en JSON (champ `liens`) à l'enregistrement ; « Principale » = salle principale
// de CE club (cocher la remplace pour le club, une seule par club côté serveur).
let mmsLiens   = [];
let multiClubs = true;   // false tant que EA98 n'a pas créé club_salle : un seul club par salle

function renderLiens() {
    const $box = $('#mms-liens').empty();
    if (!mmsLiens.length) {
        $box.append('<div class="text-muted small fst-italic">Aucun club lié.</div>');
        return;
    }
    mmsLiens.forEach((c, i) => {
        const $row = $('<div class="d-flex align-items-center gap-2 small mb-1">');
        $row.append($('<span class="flex-grow-1">').text(`${c.id_club} — ${c.nom}`));
        const $cb = $('<input type="checkbox" class="form-check-input mt-0">').prop('checked', c.principale)
            .attr('title', 'Salle principale de ce club')
            .on('change', function () { c.principale = this.checked; });
        $row.append($('<label class="d-flex align-items-center gap-1 mb-0">').append($cb).append('Principale'));
        $row.append($('<button type="button" class="btn btn-sm btn-outline-danger py-0" title="Délier (la salle est conservée)">')
            .html('<i class="bi bi-x-lg"></i>')
            .on('click', function () { mmsLiens.splice(i, 1); renderLiens(); }));
        $box.append($row);
    });
}

$('#mms-btn-lier').on('click', function () {
    const v = $('#mms-club').val().trim();
    const c = clubs.find(c => String(c.id_club) === v);
    if (!c) { $('#mms-club-nom').text('Id_Club inconnu').css('color', '#c00'); return; }
    if (mmsLiens.some(l => l.id_club === String(c.id_club))) { $('#mms-club-nom').text('Club déjà lié.').css('color', '#c00'); return; }
    if (!multiClubs && mmsLiens.length) { $('#mms-club-nom').text('Un seul club par salle tant que EA98 n\'a pas été chargé.').css('color', '#c00'); return; }
    mmsLiens.push({ id_club: String(c.id_club), nom: c.nom, principale: false });
    $('#mms-club').val('');
    majClubNom();
    renderLiens();
});
$('#mms-club').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); $('#mms-btn-lier').trigger('click'); } });

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
                telephone:      r.Telephone ?? '',
                id_laposte:     r.Id_Laposte,
                la_poste:       r.Id_Laposte ? 'Oui' : '',
                cp:             r.Cp    ?? '',
                ville:          r.Ville ?? '',
                cp_ville:       r.CpVille ?? '',
                id_club:        r.Id_Club ?? '',
                nom_club:       r.NomClub ?? '',
                est_principale: r.EstPrincipale,
                clubs:          (r.Clubs ?? []).map(c => ({ id_club: String(c.Id_Club), nom: c.Nom, principale: c.EstPrincipale == 1 })),
            }));
            multiClubs = !!res.multiClubs;
            renderGrille();
        }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
    });
}

let mmsIdLaposteVerifie = null;

// Formate au fil de la saisie sous la forme 00.00.00.00.00 (10 chiffres groupés par deux).
function formaterTelephone(val) {
    const chiffres = (val ?? '').replace(/\D/g, '').substring(0, 10);
    return chiffres.match(/.{1,2}/g)?.join('.') ?? '';
}

$('#mms-telephone').on('input', function () {
    this.value = formaterTelephone(this.value);
});

function ouvrirModalModifierSalle(idx) {
    const l = idx !== null ? lignes[idx] : null;
    $('#mms-idx').val(idx !== null ? idx : '');
    $('#modal-modifier-salle-titre').html(l
        ? '<i class="bi bi-building me-2"></i>Modifier la salle'
        : '<i class="bi bi-building me-2"></i>Ajouter une salle');
    $('#mms-numero-wrap').toggle(!!l);
    $('#mms-numero').val(l ? l.id_salle : '');
    $('#mms-nom').val(l ? (l.nom ?? '') : '');
    $('#mms-club').val('');
    majClubNom();
    mmsLiens = l ? l.clubs.map(c => ({ ...c })) : [];
    renderLiens();
    $('#mms-adresse').val(l ? (l.adresse ?? '') : '');
    $('#mms-telephone').val(formaterTelephone(l ? (l.telephone ?? '') : ''));
    $('#mms-cp').val(l ? (l.cp ?? '') : '');
    $('#mms-ville').val(l ? (l.ville ?? '') : '');
    mmsIdLaposteVerifie = l ? (l.id_laposte ?? null) : null;
    $('#mms-cp-msg').text('').css('color', '');
    $('#mms-suggestions').hide();
    $('#mms-suggestions-list').empty();
    $('#mms-msg').text('');
    const el = document.getElementById('modal-modifier-salle');
    let modal = bootstrap.Modal.getInstance(el);
    if (!modal) modal = new bootstrap.Modal(el);
    modal.show();
    el.addEventListener('shown.bs.modal', () => {
        $('#mms-nom').trigger('focus').trigger('select');
    }, { once: true });
}

$('#tbody-grille').on('click', '.btn-modifier-salle', function (e) {
    e.stopPropagation();
    ouvrirModalModifierSalle(+$(this).attr('data-idx'));
});

$('#btn-ajouter').on('click', function () {
    ouvrirModalModifierSalle(null);
});

// Enter valide la modale, sauf sur CP/Ville où Enter sert à enchaîner
// le champ suivant / déclencher la vérification laposte (voir plus bas).
$('#modal-modifier-salle').on('keydown', function (e) {
    if (e.key === 'Enter' && e.target.id !== 'mms-cp' && e.target.id !== 'mms-ville') {
        e.preventDefault();
        $('#mms-btn-ok').trigger('click');
    }
});

// Vérifie le CP/Ville saisis auprès de la table laposte et résout
// Id_Laposte en conséquence (mêmes règles que la recherche club/commune).
function mmsVerifierLaposte() {
    const cp    = $('#mms-cp').val().trim();
    const ville = $('#mms-ville').val().trim();
    $('#mms-suggestions').hide();
    $('#mms-suggestions-list').empty();
    if (!cp && !ville) {
        $('#mms-cp-msg').text('').css('color', '');
        mmsIdLaposteVerifie = null;
        return;
    }
    $.post(`${LAPOSTE_BASE}/recherche-laposte`, { cp, ville }, function (res) {
        if (!res.ok) {
            $('#mms-cp-msg').text(res.msg ?? 'Commune non trouvée.').css('color', '#c00');
            mmsIdLaposteVerifie = null;
            return;
        }
        if (res.multi) {
            $('#mms-cp-msg').text('').css('color', '');
            const $list = $('#mms-suggestions-list').empty();
            res.suggestions.forEach(s => {
                $('<button type="button" class="btn btn-sm btn-outline-primary">')
                    .text(`${s.cp} ${s.ville}`)
                    .on('click', function () {
                        $('#mms-cp').val(s.cp);
                        $('#mms-ville').val(s.ville);
                        mmsIdLaposteVerifie = s.id_laposte;
                        $('#mms-cp-msg').text(`✓ ${s.cp} ${s.ville}`).css('color', '#065f46');
                        $('#mms-suggestions').hide();
                    }).appendTo($list);
            });
            $('#mms-suggestions').show();
            mmsIdLaposteVerifie = null;
        } else {
            $('#mms-cp').val(res.cp);
            $('#mms-ville').val(res.ville);
            mmsIdLaposteVerifie = res.id_laposte;
            $('#mms-cp-msg').text(`✓ ${res.cp} ${res.ville}`).css('color', '#065f46');
        }
    }, 'json').fail(() => $('#mms-cp-msg').text('Erreur réseau.').css('color', '#c00'));
}

$('#mms-cp, #mms-ville').on('blur', function () { mmsVerifierLaposte(); });
$('#mms-cp').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#mms-ville').trigger('focus'); } });
$('#mms-ville').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); mmsVerifierLaposte(); } });

$('#mms-btn-ok').on('click', function () {
    const idxVal        = $('#mms-idx').val();
    const nom           = $('#mms-nom').val().trim();
    const adresse       = $('#mms-adresse').val().trim() || null;
    const telephone     = $('#mms-telephone').val().trim() || null;
    const cp            = $('#mms-cp').val().trim() || null;
    const ville         = $('#mms-ville').val().trim().toUpperCase() || null;

    if (!nom) {
        $('#mms-msg').html('<span class="text-danger">Le nom est obligatoire.</span>');
        return;
    }
    if (telephone && telephone.replace(/\D/g, '').length !== 10) {
        $('#mms-msg').html('<span class="text-danger">Le téléphone doit être saisi sous la forme 00.00.00.00.00.</span>');
        return;
    }
    if ($('#mms-club').val().trim() !== '') {
        $('#mms-msg').html('<span class="text-danger">Cliquez sur « Lier » pour ajouter le club saisi, ou videz le champ.</span>');
        return;
    }

    const champs = {
        nom, adresse, telephone, cp, ville,
        id_laposte: mmsIdLaposteVerifie,
        liens: JSON.stringify(mmsLiens.map(c => ({ id_club: c.id_club, principale: c.principale ? 1 : 0 }))),
    };

    if (idxVal === '') {
        spinner(true);
        $.post(SALLE_BASE, champs, function (res) {
            spinner(false);
            if (res.ok) {
                toast(res.msg, true);
                bootstrap.Modal.getInstance(document.getElementById('modal-modifier-salle')).hide();
                chargerListe();
            } else {
                $('#mms-msg').html('<span class="text-danger">✖ ' + escHtml(res.msg) + '</span>');
            }
        }, 'json').fail(() => { spinner(false); $('#mms-msg').html('<span class="text-danger">Erreur réseau.</span>'); });
        return;
    }

    persisterSalle(+idxVal, champs,
        (res) => {
            // Rechargement : les liens d'autres salles du club peuvent avoir changé (principale unique).
            bootstrap.Modal.getInstance(document.getElementById('modal-modifier-salle')).hide();
            toast(res.msg, true);
            chargerListe();
        },
        (res) => $('#mms-msg').html('<span class="text-danger">✖ ' + escHtml(res.msg ?? 'Erreur réseau.') + '</span>'));
});

$('#tbody-grille').on('click', '.btn-supprimer-salle', function (e) {
    e.stopPropagation();
    const l = lignes[+$(this).attr('data-idx')];
    if (!l) return;

    nijacConfirm(`Supprimer la salle « ${l.nom || '(salle)'} » ?`, function () {
        spinner(true);
        $.ajax({ url: `${SALLE_BASE}/${l.id_salle}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            spinner(false);
            toast(res.msg, res.ok);
            if (res.ok) { rowActive = null; chargerListe(); }
        }).fail(() => { spinner(false); toast('Erreur réseau.', false); });
    }, null, { type: 'danger' });
});

let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-salles thead th[data-field]', 'field', sortState, renderGrille);
});

// ── Affichage / masquage des colonnes (mémorisé dans le navigateur) ──────────
const LS_COLONNES = 'nijac_ea81_colonnes_cachees';
const COLONNES_CACHEES_DEFAUT = ['id_salle'];   // N° masqué tant que l'utilisateur n'a rien choisi
let colonnesCachees;
try {
    const brut = localStorage.getItem(LS_COLONNES);
    colonnesCachees = new Set(brut !== null ? JSON.parse(brut) : COLONNES_CACHEES_DEFAUT);
} catch (e) {
    colonnesCachees = new Set(COLONNES_CACHEES_DEFAUT);
}

function appliquerColonnesCachees() {
    document.querySelectorAll('#tbl-salles [data-field]').forEach(el => {
        el.style.display = colonnesCachees.has(el.getAttribute('data-field')) ? 'none' : '';
    });
    const total = document.querySelectorAll('#tbl-salles thead th[data-field]').length;
    $('#menu-colonnes-resume').text(`Colonnes ${total - colonnesCachees.size}/${total}`);
}

function construireMenuColonnes() {
    const $box = $('#menu-colonnes-list').empty();
    document.querySelectorAll('#tbl-salles thead th[data-field]').forEach(th => {
        const field = th.getAttribute('data-field');
        const label = (th.textContent || '').replace(/[⇅▲▼]/g, '').trim();
        const $chk  = $('<input type="checkbox">').prop('checked', !colonnesCachees.has(field));
        $chk.on('change', function () {
            if (this.checked) colonnesCachees.delete(field);
            else              colonnesCachees.add(field);
            try { localStorage.setItem(LS_COLONNES, JSON.stringify([...colonnesCachees])); } catch (e) {}
            appliquerColonnesCachees();
        });
        $('<label>').append($chk).append(document.createTextNode(' ' + label)).appendTo($box);
    });
}
construireMenuColonnes();
appliquerColonnesCachees();

// Ferme le menu « Colonnes » au clic hors de celui-ci
$(document).on('click', function (e) {
    if (!$(e.target).closest('#menu-colonnes').length) $('#menu-colonnes').removeAttr('open');
});

$('#sel-laposte').on('change', function () {
    filtreLaPosteManquant = $(this).val() === '1';
    renderGrille();
});

$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

$('#sel-perimetre').on('change', function () {
    filtreEnRegion = $(this).val() === '1';
    renderGrille();
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
