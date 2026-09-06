<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Clubs et Associations (EN27)</title>

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

        /* ── En-tête ── */
        #page-header {
            background: #2e7d32;   /* vert nominateur, comme EN11/EN14/EN15 */
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
            font-size: .82rem;
            border-collapse: collapse;
            table-layout: fixed;   /* respecte les largeurs des <th> et tronque le contenu — pas de scroll horizontal */
        }

        #tbl-clubs thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .3rem .45rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
        }

        #tbl-clubs tbody tr { border-bottom: 1px solid #e0e8f0; cursor: pointer; }
        #tbl-clubs tbody tr:hover   { background: #eef4ff; }
        /* .en-region / .hors-region : voir asset/css/nijac-skin.css */
        #tbl-clubs tbody td { border: 1px solid #e0e8f0; padding: 0; }

        .cell-inner {
            display: block;
            padding: .28rem .4rem;
            min-height: 28px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Colonne actions : 2 boutons icônes serrés */
        #tbl-clubs td:last-child { padding: 0 2px; }
        #tbl-clubs td:last-child .btn { padding: .15rem .3rem; }
        #tbl-clubs td:last-child .btn.me-1 { margin-right: 2px !important; }

        /* Recherche / comboboxes / badge : style partagé (asset/css/nijac.css) */

        /* ── En-têtes triables ── */
        #tbl-clubs thead th { cursor: pointer; user-select: none; }
        #tbl-clubs thead th:hover { background: #d4dff0; }
        #tbl-clubs thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-clubs thead th.sort-asc .sort-icon::after  { content: '▲'; opacity: 1; }
        #tbl-clubs thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-clubs thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        /* ── Spinner ── */

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

    </style>
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'building', 'phTitle' => 'Gestion des clubs et Associations', 'phCode' => 'EN27',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-sync-fftt" data-bs-toggle="modal" data-bs-target="#modal-sync-fftt">
        <i class="bi bi-cloud-arrow-down-fill"></i>Synchroniser depuis FFTT
    </button>
    <button class="menu-item" id="btn-import-club-numero" data-bs-toggle="modal" data-bs-target="#modal-import-club-numero">
        <i class="bi bi-search"></i>Importer un club (N°)
    </button>
    <span class="count-badge" id="lbl-count">0 club(s)</span>
    <span style="flex:1"></span>
    <span class="combo-field">
        <details id="menu-colonnes">
            <summary id="menu-colonnes-resume">Colonnes</summary>
            <div id="menu-colonnes-list"></div>
        </details>
    </span>
    <span style="flex:1"></span>
    <?php
        $codesRegion = array_column($deptActifs, 'CodeDept');
        $deptsAutres = array_filter($tousDepts, fn ($d) => !in_array($d['CodeDept'], $codesRegion, true));
    ?>
    <span class="combo-field">
        <label for="sel-dept">Département</label>
        <select id="sel-dept">
            <option value="">Tous les départements</option>
            <optgroup label="Région">
            <?php foreach ($deptActifs as $d): ?>
            <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
            <?php endforeach; ?>
            </optgroup>
            <optgroup label="Autres départements">
            <?php foreach ($deptsAutres as $d): ?>
            <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
            <?php endforeach; ?>
            </optgroup>
        </select>
    </span>
    <span class="combo-field" id="wrap-salles">
        <label for="sel-salles">Salles</label>
        <select id="sel-salles">
            <option value="0">Toutes les salles</option>
            <option value="1">Plusieurs salles</option>
        </select>
    </span>
    <span class="combo-field">
        <label for="sel-perimetre">Périmètre</label>
        <select id="sel-perimetre">
            <option value="1">Région uniquement</option>
            <option value="0">Tous les clubs</option>
        </select>
    </span>
    <span class="combo-field">
        <label for="search-input">Recherche</label>
        <input type="search" id="search-input" placeholder="Rechercher…">
    </span>
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-clubs">
        <thead>
            <tr>
                <th style="width:5%"  data-field="id_club" title="N° FFTT">N°<span class="sort-icon"></span></th>
                <th style="width:16%" data-field="nom">Nom club<span class="sort-icon"></span></th>
                <th style="width:7%"  data-field="equipe_nom" title="Nom de base utilisé pour les équipes de ce club dans les imports FFTT (ex. « ROUEN SPO » pour « ROUEN SPO 2 »)">Nom équipe<span class="sort-icon"></span></th>
                <th style="width:9%"  data-field="cor_nom">Correspondant<span class="sort-icon"></span></th>
                <th style="width:10%" data-field="cor_email">Email Corres.<span class="sort-icon"></span></th>
                <th style="width:6%"  data-field="cor_tel">Téléphone Corres.<span class="sort-icon"></span></th>
                <th style="width:9%"  data-field="ref_nom">Référent<span class="sort-icon"></span></th>
                <th style="width:10%" data-field="ref_mail">Email Réf.<span class="sort-icon"></span></th>
                <th style="width:6%"  data-field="ref_tel">Téléphone Réf.<span class="sort-icon"></span></th>
                <th style="width:8%"  data-field="salle_nom">Salle principale<span class="sort-icon"></span></th>
                <th style="width:3%"  data-field="salle_cp">CP<span class="sort-icon"></span></th>
                <th style="width:7%"  data-field="salle_ville">Ville<span class="sort-icon"></span></th>
                <th style="width:4%"></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="13" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<?= view('partials/page_footer', [
    'pfStatusText' => 'Prêt.',
    'pfStatusAlign' => 'left',
]) ?>

<!-- Modale : modifier un club -->
<div class="modal fade" id="modal-modifier-club" tabindex="-1" aria-labelledby="modal-modifier-club-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a3a6b;color:#fff;">
                <h5 class="modal-title" id="modal-modifier-club-titre">
                    <i class="bi bi-building me-2"></i>Modifier le club
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                <input type="hidden" id="mod-club-idx">
                <div class="mb-2 d-flex align-items-center gap-2">
                    <label class="form-label fw-semibold mb-0" style="font-size:.82rem;white-space:nowrap;">N° FFTT :</label>
                    <span id="mod-club-id" class="fw-bold" style="color:#6b7280;"></span>
                </div>
                <div class="mb-2 d-flex align-items-center gap-2">
                    <label for="mod-club-nom" class="form-label fw-semibold mb-0" style="font-size:.82rem;white-space:nowrap;">Nom du club <span class="text-danger">*</span> :</label>
                    <input type="text" id="mod-club-nom" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Nom équipe (imports FFTT)</label>
                    <input type="text" id="mod-club-equipe-nom" class="form-control form-control-sm" placeholder="ex. ROUEN SPO">
                    <div class="form-text" style="font-size:.75rem;">Nom de base utilisé pour retrouver ce club lors des imports de rencontres Nationale/Régionale — renseigné automatiquement dès qu'une équipe lui est associée.</div>
                </div>
                <fieldset style="border:1px solid #c8d4e8;border-radius:6px;padding:.5rem .7rem .2rem;margin-bottom:.6rem;">
                    <legend class="float-none w-auto px-2 mb-1 fw-semibold" style="font-size:.8rem;color:#1a3a6b;">Correspondant</legend>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Nom</label>
                        <input type="text" id="mod-club-cor-nom" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Email</label>
                        <input type="email" id="mod-club-cor-email" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Téléphone</label>
                        <input type="text" id="mod-club-cor-tel" class="form-control form-control-sm">
                    </div>
                </fieldset>
                <fieldset style="border:1px solid #c8d4e8;border-radius:6px;padding:.5rem .7rem .2rem;margin-bottom:.6rem;">
                    <legend class="float-none w-auto px-2 mb-1 fw-semibold" style="font-size:.8rem;color:#1a3a6b;">Référent</legend>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Nom</label>
                        <input type="text" id="mod-club-ref-nom" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Email</label>
                        <input type="email" id="mod-club-ref-mail" class="form-control form-control-sm">
                        <div class="form-text" style="font-size:.75rem;">Mis en copie (Cc) de la demande de JA envoyée au correspondant (EN14).</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:.82rem;">Téléphone</label>
                        <input type="text" id="mod-club-ref-tel" class="form-control form-control-sm">
                    </div>
                </fieldset>
                <div id="mod-club-msg" style="font-size:.8rem;min-height:18px;margin-top:.4rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary btn-sm" id="mod-club-btn-ok">
                    <i class="bi bi-floppy-fill me-1"></i>Valider
                </button>
            </div>
        </div>
    </div>
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
              <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
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

<!-- Modale Import d'un club par numéro -->
<div class="modal fade" id="modal-import-club-numero" tabindex="-1" aria-labelledby="modal-import-club-numero-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:#0d6efd;color:#fff;">
        <h5 class="modal-title" id="modal-import-club-numero-titre"><i class="bi bi-search me-2"></i>Importer un club par numéro FFTT</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small mb-3">
          Récupère le club via <code>xml_club_detail</code> (nom du club, salle principale, correspondant)
          et l'insère ou le met à jour en base — identique à la synchronisation par département, mais pour un seul club.
        </p>
        <div class="input-group mb-2">
          <label class="input-group-text" for="import-club-numero-input"><i class="bi bi-hash me-1"></i>N° club</label>
          <input type="text" class="form-control" id="import-club-numero-input" placeholder="09760168" maxlength="20">
          <button class="btn btn-primary" id="btn-lancer-import-club-numero">
            <i class="bi bi-play-fill me-1"></i>Importer
          </button>
        </div>
        <div id="import-club-numero-resultat" class="small mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const CLUB_BASE = '<?= site_url('club') ?>';
const DEPTS_REGION = new Set(<?= json_encode(array_column($deptActifs, 'CodeDept')) ?>);

function deptDeClub(idClub) {
    // Format : 0[9][dept 2 chiffres][4 chiffres] — ex. 09760442 → '76'
    return String(idClub ?? '').substring(2, 4);
}

let lignes           = [];
const sortState      = { col: 'id_club', asc: true };
let searchTerm       = '';
let deptFiltre       = '';   // filtré côté JS
let filtreMultiSalle = false;
let filtreEnRegion = true; // true = En région uniquement (par défaut), false = Tous

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let result = [...lignes];
    if (deptFiltre)      result = result.filter(l => deptDeClub(l.id_club) === deptFiltre);
    if (filtreMultiSalle) result = result.filter(l => (l.nb_salles ?? 0) > 1);
    if (filtreEnRegion) result = result.filter(l => DEPTS_REGION.has(deptDeClub(l.id_club)));
    if (term) result = result.filter(l =>
        String(l.id_club     ?? '').toLowerCase().includes(term) ||
        String(l.nom         ?? '').toLowerCase().includes(term) ||
        String(l.equipe_nom  ?? '').toLowerCase().includes(term) ||
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
// Cache des lignes déjà construites pour l'état « En région » / « Hors région »,
// afin d'éviter de reconstruire le tableau (coûteux vu le nombre de clubs hors
// région) à chaque bascule du filtre. Invalidé dès que les données ou un autre
// filtre changent (voir invaliderCacheRendu()).
let renduCache = { true: null, false: null }; // { signature, rows, total }

function signatureFiltres() {
    return JSON.stringify({ deptFiltre, filtreMultiSalle, searchTerm, col: sortState.col, asc: sortState.asc });
}

function invaliderCacheRendu() {
    renduCache = { true: null, false: null };
}

function renderGrille() {
    refreshTriEntetes();
    const $body = $('#tbody-grille').empty();

    const sig    = signatureFiltres();
    const cached = renduCache[filtreEnRegion];
    let rows, total;

    if (cached && cached.signature === sig) {
        rows  = cached.rows;
        total = cached.total;
    } else {
        const affichees = lignesFiltreesTriees();
        rows  = affichees.map(construireLigne);
        total = affichees.length;
        renduCache[filtreEnRegion] = { signature: sig, rows, total };
    }

    if (!rows.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun club.';
        $body.append(`<tr><td colspan="13" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} club(s).` : 'Aucun club enregistré.');
        $('#lbl-count').text(`0 / ${lignes.length} club(s)`);
        return;
    }

    rows.forEach((tr) => $body.append(tr));
    appliquerColonnesCachees();

    const info = searchTerm ? `${total} résultat(s) sur ${lignes.length}. ` : '';
    setStatus(`${info}Prêt.`);
    $('#lbl-count').text(`${total} / ${lignes.length} club(s)`);
}

function construireLigne(l) {
    const idx  = lignes.indexOf(l);
    const dept = deptDeClub(l.id_club);
    const $tr  = $('<tr>').attr('data-idx', idx);
    if (dept && !DEPTS_REGION.has(dept)) $tr.addClass('hors-region').attr('title', `Département ${dept} hors région`);
    else if (dept) $tr.addClass('en-region');
    $tr.append(makeTd(l.id_club,     'id_club'));
    $tr.append(makeTd(l.nom,         'nom'));
    $tr.append(makeTd(l.equipe_nom,  'equipe_nom'));
    $tr.append(makeTd(l.cor_nom,     'cor_nom'));
    $tr.append(makeTd(l.cor_email,   'cor_email'));
    $tr.append(makeTd(l.cor_tel,     'cor_tel'));
    $tr.append(makeTd(l.ref_nom,     'ref_nom'));
    $tr.append(makeTd(l.ref_mail,    'ref_mail'));
    $tr.append(makeTd(l.ref_tel,     'ref_tel'));
    $tr.append(makeTd(l.salle_nom,   'salle_nom'));
    $tr.append(makeTd(l.salle_cp,    'salle_cp'));
    $tr.append(makeTd(l.salle_ville, 'salle_ville'));
    const $tdActions = $('<td class="text-center">');
    $tdActions.append(
        $('<button type="button" class="btn btn-sm btn-outline-primary btn-modifier-club me-1" title="Modifier">')
            .attr('data-idx', idx)
            .html('<i class="bi bi-pencil-fill"></i>')
    );
    $tdActions.append(
        $('<button type="button" class="btn btn-sm btn-outline-danger btn-supprimer-club" title="Supprimer">')
            .attr('data-idx', idx)
            .html('<i class="bi bi-trash-fill"></i>')
    );
    $tr.append($tdActions);
    return $tr;
}

function makeTd(val, field) {
    const $td  = $('<td>').attr('data-field', field);
    if (field === 'id_club') $td.addClass('col-id');
    const $div = $('<div class="cell-inner">').text(val ?? '');
    $td.append($div);
    return $td;
}

$('#tbody-grille').on('click', '.btn-modifier-club', function () {
    ouvrirModaleModifClub(+$(this).attr('data-idx'));
});

$('#tbody-grille').on('click', '.btn-supprimer-club', function (e) {
    e.stopPropagation();
    const l = lignes[+$(this).attr('data-idx')];
    if (!l) return;

    nijacConfirm(
        `Supprimer le club « ${l.nom ?? l.id_club} » (${l.id_club}) ?`,
        function () {
            $.ajax({
                url: `${CLUB_BASE}/${encodeURIComponent(l.id_club)}`,
                method: 'DELETE',
            }).done(function (res) {
                if (res.ok) {
                    nijacToast(res.msg, 'success');
                    chargerListe();
                } else {
                    nijacToast(res.msg, 'danger');
                }
            }).fail(function () {
                nijacToast('Erreur réseau.', 'danger');
            });
        },
        null,
        { type: 'danger', title: 'Supprimer le club', confirmLabel: 'Supprimer' }
    );
});

$('#tbody-grille').on('dblclick', 'tr[data-idx]', function () {
    ouvrirModaleModifClub(+$(this).attr('data-idx'));
});

function ouvrirModaleModifClub(idx) {
    const l = lignes[idx];
    if (!l) return;
    $('#mod-club-idx').val(idx);
    $('#mod-club-id').text(l.id_club ?? '');
    $('#mod-club-nom').val(l.nom ?? '');
    $('#mod-club-equipe-nom').val(l.equipe_nom ?? '');
    $('#mod-club-cor-nom').val(l.cor_nom ?? '');
    $('#mod-club-cor-email').val(l.cor_email ?? '');
    $('#mod-club-cor-tel').val(l.cor_tel ?? '');
    $('#mod-club-ref-nom').val(l.ref_nom ?? '');
    $('#mod-club-ref-mail').val(l.ref_mail ?? '');
    $('#mod-club-ref-tel').val(l.ref_tel ?? '');
    $('#mod-club-msg').text('');
    const el = document.getElementById('modal-modifier-club');
    let modal = bootstrap.Modal.getInstance(el);
    if (!modal) modal = new bootstrap.Modal(el);
    modal.show();
    el.addEventListener('shown.bs.modal', () => {
        $('#mod-club-nom').trigger('focus').trigger('select');
    }, { once: true });
}

$('#modal-modifier-club').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('#mod-club-btn-ok').trigger('click'); }
});

$('#mod-club-btn-ok').on('click', function () {
    $('#mod-club-msg').text('');
    const idx      = +$('#mod-club-idx').val();
    const l        = lignes[idx];
    if (!l) return;
    const nom       = $('#mod-club-nom').val().trim();
    const equipeNom = $('#mod-club-equipe-nom').val().trim();
    const corNom    = $('#mod-club-cor-nom').val().trim();
    const corEmail  = $('#mod-club-cor-email').val().trim();
    const corTel    = $('#mod-club-cor-tel').val().trim();
    const refNom    = $('#mod-club-ref-nom').val().trim();
    const refMail   = $('#mod-club-ref-mail').val().trim();
    const refTel    = $('#mod-club-ref-tel').val().trim();

    if (!nom) {
        $('#mod-club-msg').html('<span class="text-danger">Le nom du club est obligatoire.</span>');
        return;
    }

    spinner(true);
    $.ajax({
        url: `${CLUB_BASE}/${encodeURIComponent(l.id_club)}`,
        method: 'PUT',
        data: { nom, equipe_nom: equipeNom, cor_nom: corNom, cor_email: corEmail, cor_tel: corTel, ref_nom: refNom, ref_mail: refMail, ref_tel: refTel },
        dataType: 'json',
    }).done(function (res) {
        spinner(false);
        if (res.ok) {
            toast(res.msg, true);
            bootstrap.Modal.getInstance(document.getElementById('modal-modifier-club')).hide();
            l.nom        = nom;
            l.equipe_nom = equipeNom;
            l.cor_nom    = corNom;
            l.cor_email  = corEmail;
            l.cor_tel    = corTel;
            l.ref_nom    = refNom;
            l.ref_mail   = refMail;
            l.ref_tel    = refTel;
            invaliderCacheRendu();
            renderGrille();
        } else {
            $('#mod-club-msg').html('<span class="text-danger">✖ ' + res.msg + '</span>');
        }
    }).fail(() => { spinner(false); $('#mod-club-msg').html('<span class="text-danger">Erreur réseau.</span>'); });
});

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.get(`${CLUB_BASE}/liste`, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map(r => ({
            id_club:      r.Id_Club,
            nom:          r.Nom,
            equipe_nom:   r.EquipeNom ?? '',
            nb_salles:    +(r.NbSalles ?? 0),
            cor_nom:      r.CorNom      ?? '',
            cor_email:    r.CorEmail    ?? '',
            cor_tel:      r.CorTelephone ?? '',
            ref_nom:      r.RefNom      ?? '',
            ref_mail:     r.RefMail     ?? '',
            ref_tel:      r.RefTelephone ?? '',
            salle_nom:    r.SallePrincipaleNom  ?? '',
            salle_cp:     r.SallePrincipaleCp   ?? '',
            salle_ville:  r.SallePrincipaleVille ?? '',
        }));
        invaliderCacheRendu();
        const aMultiSalles = lignes.some(l => l.nb_salles > 1);
        $('#wrap-salles').toggle(aMultiSalles);
        if (!aMultiSalles && filtreMultiSalle) {
            filtreMultiSalle = false;
            $('#sel-salles').val('0');
        }
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé en fin de page, donc pas encore
// défini si on l'appelait ici de façon synchrone.
let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-clubs thead th[data-field]', 'field', sortState, renderGrille);
});

// ── Affichage / masquage des colonnes (mémorisé dans le navigateur) ──────────
const LS_COLONNES = 'nijac_en27_colonnes_cachees';
let colonnesCachees;
try {
    const brut = localStorage.getItem(LS_COLONNES);
    colonnesCachees = new Set(brut !== null ? JSON.parse(brut) : []);
} catch (e) {
    colonnesCachees = new Set();
}

function appliquerColonnesCachees() {
    document.querySelectorAll('#tbl-clubs [data-field]').forEach(el => {
        el.style.display = colonnesCachees.has(el.getAttribute('data-field')) ? 'none' : '';
    });
    const total = document.querySelectorAll('#tbl-clubs thead th[data-field]').length;
    $('#menu-colonnes-resume').text(`Colonnes ${total - colonnesCachees.size}/${total}`);
}

function construireMenuColonnes() {
    const $box = $('#menu-colonnes-list').empty();
    document.querySelectorAll('#tbl-clubs thead th[data-field]').forEach(th => {
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

// ── Filtre plusieurs salles ───────────────────────────────────────────────────
$('#sel-salles').on('change', function () {
    filtreMultiSalle = $(this).val() === '1';
    renderGrille();
});

// ── Filtre région / tous ─────────────────────────────────────────────────────
$('#sel-perimetre').on('change', function () {
    filtreEnRegion = $(this).val() === '1';

    const cached = renduCache[filtreEnRegion];
    if (cached && cached.signature === signatureFiltres()) {
        renderGrille(); // déjà construit précédemment, affichage instantané
        return;
    }

    spinner(true);
    setStatus('Chargement en cours…');
    setTimeout(function () {
        renderGrille();
        spinner(false);
    }, 10);
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
// L'API FFTT bloque les requêtes trop rapprochées (anti-flood) : au-delà de
// quelques appels xml_club_detail par seconde elle cesse de répondre.
const SYNC_FFTT_DELAI_MS = 600;
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
    const dep      = $('#sync-fftt-dept').val();
    const depLabel = $('#sync-fftt-dept option:selected').text();
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
                    `Synchronisation terminée pour le département <strong>${depLabel}</strong> — ` +
                    `<strong>${cntClubs}</strong> club(s), ` +
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
                setTimeout(traiterClub, SYNC_FFTT_DELAI_MS);
            }, 'json').fail(() => {
                cntErreurs++;
                $('#sync-cnt-erreurs').text(cntErreurs);
                done++;
                setTimeout(traiterClub, SYNC_FFTT_DELAI_MS);
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

// ── Import d'un club par numéro ───────────────────────────────────────────────
$('#modal-import-club-numero').on('hidden.bs.modal', function () {
    $('#import-club-numero-input').val('');
    $('#import-club-numero-resultat').empty();
});

function lancerImportClubNumero() {
    const numClub = $('#import-club-numero-input').val().trim();
    if (!numClub) { nijacToast('Saisissez un numéro de club.', 'warning'); return; }

    $('#btn-lancer-import-club-numero').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Import…');
    $('#import-club-numero-resultat').empty();

    $.post(`${CLUB_BASE}/fftt/sync`, { num_club: numClub }, function (r) {
        $('#btn-lancer-import-club-numero').prop('disabled', false).html('<i class="bi bi-play-fill me-1"></i>Importer');
        if (!r.ok) {
            $('#import-club-numero-resultat').html(`<div class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>${r.msg}</div>`);
            return;
        }
        const lignes = r.ops.length
            ? r.ops.map(op => `<div class="text-success"><i class="bi bi-check-circle-fill me-1"></i>${op}</div>`).join('')
            : '<div class="text-muted">Aucune modification (données déjà à jour ou absentes de la fiche FFTT).</div>';
        $('#import-club-numero-resultat').html(lignes);
        nijacToast(`Club ${r.club} importé.`, 'success');
        chargerListe();
    }, 'json').fail(() => {
        $('#btn-lancer-import-club-numero').prop('disabled', false).html('<i class="bi bi-play-fill me-1"></i>Importer');
        $('#import-club-numero-resultat').html('<div class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Erreur réseau.</div>');
    });
}

$('#btn-lancer-import-club-numero').on('click', lancerImportClubNumero);
$('#import-club-numero-input').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); lancerImportClubNumero(); }
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
