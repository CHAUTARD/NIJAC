<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Communes / La Poste (EA87)</title>

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

        #import-options {
            display: none;
            align-items: center;
            gap: .5rem;
            padding: .15rem .5rem;
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: 4px;
            font-size: .82rem;
        }
        #import-options.show { display: inline-flex; }

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

        #tbl-communes {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 600px;
        }

        #tbl-communes thead th {
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
        #tbl-communes thead th:hover { background: #d4dff0; }
        #tbl-communes thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-communes thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-communes thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-communes thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-communes tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-communes tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-communes tbody tr:hover { background: #dce8f8; }

        #tbl-communes tbody td {
            border: 1px solid #e0e8f0;
            padding: .28rem .5rem;
            white-space: nowrap;
        }
        td.col-id { color: #6b7280; font-style: italic; background: #f0f4fa; }

        #pagination-bar {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .25rem 1rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .82rem;
            flex-shrink: 0;
        }
        #pagination-bar button {
            padding: .15rem .5rem;
            font-size: .82rem;
            border: 1px solid #c8d4e8;
            border-radius: 3px;
            background: #fff;
            cursor: pointer;
        }
        #pagination-bar button:disabled { opacity: .4; cursor: default; }
        #pagination-bar button:not(:disabled):hover { background: #e8eef7; }
        #quick-jump { display: inline-flex; gap: .25rem; margin-left: .75rem; }

        #btn-sans-coords.actif {
            background: #fff3cd; border-color: #f59e0b; color: #78350f; font-weight: 700;
        }
        #btn-sans-coords.actif i { color: #d97706; }

        #tbl-communes tbody tr { cursor: pointer; }
        #tbl-communes tbody tr.row-selected td { background: #b8d0f0 !important; }
        #tbl-communes tbody tr:hover:not(.row-selected) td { background: #dce8f8; }
        td.col-coords { font-style: italic; color: #2557a7; }

        .coords-row { display: flex; gap: .5rem; align-items: flex-start; }
        .coords-row .coords-field { flex: 1; }
        .coords-field label { font-size: .82rem; font-weight: 600; color: #374151; margin-bottom: .2rem; display: block; }
        .coords-field input { width: 100%; border: 1px solid #ced4da; border-radius: 4px; padding: .35rem .6rem; font-size: .88rem; }
        .coords-field input:focus { outline: none; border-color: #1a3a6b; box-shadow: 0 0 0 2px rgba(26,58,107,.15); }
        .btn-coller {
            padding: .35rem .75rem; font-size: .8rem; font-weight: 600;
            background: #e8eef7; border: 1px solid #c8d4e8; border-radius: 4px;
            cursor: pointer; white-space: nowrap; color: #1a3a6b;
            transition: background .15s;
        }
        .btn-coller:hover { background: #d0dff0; }


        #import-progress {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        #import-progress.show { display: flex; }
        #import-progress .box {
            background: #fff;
            border-radius: 8px;
            padding: 2rem 2.5rem;
            min-width: 340px;
            text-align: center;
        }
        #import-progress .box p { margin: .5rem 0 1rem; font-size: .9rem; color: #374151; }

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
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'mailbox2', 'phTitle' => 'Gestion des communes (La Poste)', 'phCode' => 'EA87',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<!-- Overlay import -->
<div id="import-progress">
    <div class="box">
        <div class="spinner-border text-primary mb-2" style="width:2.5rem;height:2.5rem;"></div>
        <p id="import-msg">Import en cours, veuillez patienter…</p>
        <div class="progress mb-2" style="height:8px;">
            <div id="progress-bar-file" class="progress-bar bg-primary" style="width:0%;transition:width .3s;"></div>
        </div>
        <small id="import-detail" class="text-muted"></small>
    </div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-nouvelle-commune" style="color:#155724;background:#d4edda;border-color:#c3e6cb;"
            data-bs-toggle="modal" data-bs-target="#modal-ajouter">
        <i class="bi bi-plus-circle-fill me-1" style="font-size:1rem;"></i>Nouvelle commune
    </button>
    <button class="menu-item" id="btn-importer">
        <i class="bi bi-file-earmark-arrow-up"></i>Importation CSV
    </button>
    <button class="menu-item" id="btn-exporter">
        <i class="bi bi-file-earmark-arrow-down"></i>Exportation CSV
    </button>
    <div id="import-options">
        <label style="display:flex;align-items:center;gap:.3rem;cursor:pointer;">
            <input type="checkbox" id="chk-vider"> Vider la table avant import
        </label>
        <button class="menu-item" id="btn-confirmer-import" style="background:#d4edda;border-color:#c3e6cb;">
            <i class="bi bi-check-lg"></i> Confirmer
        </button>
        <button class="menu-item" id="btn-annuler-import" style="background:#f8d7da;border-color:#f5c6cb;">
            <i class="bi bi-x-lg"></i> Annuler
        </button>
    </div>
    <input type="file" id="file-input" accept=".csv,.001,.002,.003,.004,.005" multiple style="display:none">
    <button class="menu-item" id="btn-sans-coords" title="Afficher uniquement les communes sans latitude/longitude">
        <i class="bi bi-geo-alt" style="font-size:1rem;margin-right:.35rem;"></i>Sans géolocalisation
    </button>
    <button class="menu-item" id="btn-aide-coords" style="color:#1a3a6b;" data-bs-toggle="modal" data-bs-target="#modal-aide-coords">
        <i class="bi bi-question-circle-fill" style="font-size:1.1rem;margin-right:.35rem;color:#2557a7;"></i>Comment obtenir les coordonnées GPS ?
    </button>
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($departements as $d): ?>
        <option value="<?= esc($d['CodeDept']) ?>"><?= esc($d['CodeDept']) ?> — <?= esc($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="menu-item" id="btn-filtre-region" title="Cliquer pour n'afficher que les communes de la région, ou toutes les communes" style="border-color:transparent;">
        <i class="bi bi-geo-alt me-1"></i><span id="lbl-filtre-region">Région</span>
    </button>
    <input type="search" id="search-input" placeholder="🔍 Code postal ou commune (jokers * et ?)…" title="* remplace plusieurs caractères, ? un seul — ex : SAINT*, 14?00, *SUR MER">
</div>

<!-- ── Modale : aide coordonnées GPS ── -->
<div class="modal fade" id="modal-aide-coords" tabindex="-1" aria-labelledby="modal-aide-titre" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">

            <div class="modal-header" style="background:#1a3a6b;color:#fff;">
                <h5 class="modal-title" id="modal-aide-titre">
                    <i class="bi bi-geo-alt-fill me-2"></i>Comment obtenir la latitude et la longitude d'une commune
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>

            <div class="modal-body" style="font-size:.88rem;line-height:1.7;">

                <ol style="padding-left:1.3rem;margin:0;">
                    <li style="margin-bottom:1rem;">
                        <strong>Ouvrir Google Maps</strong><br>
                        Rendez-vous sur
                        <a href="https://www.google.com/maps/" target="_blank" rel="noopener">
                            <i class="bi bi-box-arrow-up-right me-1"></i>google.com/maps
                        </a>.
                    </li>

                    <li style="margin-bottom:1rem;">
                        <strong>Rechercher la commune</strong><br>
                        Saisissez le nom de la commune dans la barre de recherche en haut à gauche,
                        puis validez avec <kbd>Entrée</kbd>. La carte se centre sur la commune.
                    </li>

                    <li style="margin-bottom:1rem;">
                        <strong>Clic droit sur la carte</strong><br>
                        Faites un <strong>clic droit</strong> directement sur le centre de la commune
                        (ou sur l'épingle rouge si elle est affichée).
                    </li>

                    <li style="margin-bottom:1rem;">
                        <strong>Copier les coordonnées</strong><br>
                        Un menu contextuel apparaît. La <strong>première ligne</strong> affiche
                        les coordonnées sous la forme&nbsp;:<br>
                        <code style="background:#f0f4fa;padding:.2rem .5rem;border-radius:4px;font-size:.9rem;display:inline-block;margin:.3rem 0;">
                            49.1234567, -0.3456789
                        </code><br>
                        Cliquez sur cette ligne pour <strong>copier automatiquement</strong>
                        les deux valeurs dans le presse-papier (latitude <em>et</em> longitude séparées par une virgule).
                    </li>

                    <li>
                        <strong>Coller dans la fiche de la commune</strong><br>
                        Sélectionnez la commune dans la liste (double-clic ou touche <kbd>F2</kbd>).
                        Dans la modale qui s'ouvre, deux méthodes&nbsp;:<br>
                        <ul style="margin-top:.5rem;margin-bottom:.3rem;">
                            <li style="margin-bottom:.4rem;">
                                <strong>Méthode rapide</strong> — cliquez dans le champ
                                <strong>Latitude</strong> puis faites <kbd>Ctrl</kbd>+<kbd>V</kbd>&nbsp;:
                                les deux champs (Latitude et Longitude) sont remplis automatiquement.
                            </li>
                            <li>
                                <strong>Bouton «&nbsp;Coller depuis Google Maps&nbsp;»</strong> — cliquez
                                le bouton en haut de la zone coordonnées&nbsp;; il tente de lire
                                le presse-papier directement et remplit les deux champs.
                            </li>
                        </ul>
                        En cas d'échec du bouton (HTTP local), utilisez la méthode <kbd>Ctrl</kbd>+<kbd>V</kbd>.
                    </li>
                </ol>

                <div style="margin-top:1.1rem;padding:.75rem 1rem;background:#fff3cd;border:1px solid #f59e0b;border-radius:6px;font-size:.82rem;color:#78350f;">
                    <i class="bi bi-lightbulb-fill me-1"></i>
                    <strong>Astuce&nbsp;:</strong> Vous pouvez cliquer <em>n'importe où</em> sur la carte,
                    pas uniquement sur le marqueur. Utile pour les communes dont la position affichée est imprécise.
                </div>

            </div>

            <div class="modal-footer">
                <a href="https://www.google.com/maps/" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
                    <i class="bi bi-map-fill me-1"></i>Ouvrir Google Maps
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>

        </div>
    </div>
</div>

<!-- ── Modale : ajouter une commune ── -->
<div class="modal fade" id="modal-ajouter" tabindex="-1" aria-labelledby="modal-ajouter-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:500px">
        <div class="modal-content">
            <div class="modal-header" style="background:#155724;color:#fff;">
                <h5 class="modal-title" id="modal-ajouter-titre">
                    <i class="bi bi-plus-circle-fill me-2"></i>Ajouter une commune
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">N° INSEE <span class="text-danger">*</span></label>
                    <div class="input-group input-group-sm">
                        <input type="number" id="add-insee" class="form-control form-control-sm" placeholder="ex : 14118" min="1">
                        <a class="btn btn-outline-secondary" href="https://www.insee.fr/fr/recherche/recherche-geographique?debut=0"
                           target="_blank" rel="noopener noreferrer" title="Rechercher le code INSEE sur insee.fr">
                            <i class="bi bi-search me-1"></i>insee.fr
                        </a>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Nom de la commune <span class="text-danger">*</span></label>
                    <input type="text" id="add-nom" class="form-control form-control-sm" placeholder="ex : CAEN">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Code postal <span class="text-danger">*</span></label>
                    <input type="text" id="add-cp" class="form-control form-control-sm" placeholder="ex : 14000" maxlength="10">
                </div>
                <hr style="margin:.75rem 0;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold" style="font-size:.82rem;">Coordonnées GPS</span>
                    <button type="button" class="btn-coller" id="add-btn-coller">
                        <i class="bi bi-clipboard me-1"></i>Coller depuis Google Maps
                    </button>
                </div>
                <div class="coords-row mb-1">
                    <div class="coords-field">
                        <label>Latitude</label>
                        <input type="text" id="add-lat" placeholder="ex : 49.1825">
                    </div>
                    <div class="coords-field">
                        <label>Longitude</label>
                        <input type="text" id="add-lon" placeholder="ex : -0.3708">
                    </div>
                </div>
                <div id="add-msg" style="font-size:.8rem;min-height:18px;margin-top:.4rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-success btn-sm" id="add-btn-ok">
                    <i class="bi bi-check-lg me-1"></i>Ajouter
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modale : modifier les coordonnées ── -->
<div class="modal fade" id="modal-modifier" tabindex="-1" aria-labelledby="modal-modifier-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content">
            <div class="modal-header" style="background:#1a3a6b;color:#fff;">
                <h5 class="modal-title" id="modal-modifier-titre">
                    <i class="bi bi-geo-alt-fill me-2"></i>Modifier les coordonnées GPS
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                <p id="mod-nom" class="fw-bold mb-3" style="color:#1a3a6b;font-size:.92rem;"></p>
                <input type="hidden" id="mod-insee">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="fw-semibold" style="font-size:.82rem;">Nouvelles coordonnées GPS</span>
                    <button type="button" class="btn-coller" id="mod-btn-coller">
                        <i class="bi bi-clipboard me-1"></i>Coller depuis Google Maps
                    </button>
                </div>
                <div class="coords-row mb-1">
                    <div class="coords-field">
                        <label>Latitude</label>
                        <input type="text" id="mod-lat" placeholder="ex : 49.1825">
                    </div>
                    <div class="coords-field">
                        <label>Longitude</label>
                        <input type="text" id="mod-lon" placeholder="ex : -0.3708">
                    </div>
                </div>
                <div id="mod-msg" style="font-size:.8rem;min-height:18px;margin-top:.4rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary btn-sm" id="mod-btn-ok">
                    <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-communes">
        <thead>
            <tr>
                <th style="width:70px"  data-field="Id_LaPoste">N°<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="CodePostal">Code postal<span class="sort-icon"></span></th>
                <th style="width:280px" data-field="Nom">Commune<span class="sort-icon"></span></th>
                <th style="width:110px" data-field="Latitude">Latitude<span class="sort-icon"></span></th>
                <th style="width:110px" data-field="Longitude">Longitude<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="NomDept">Département<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="6" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Pagination -->
<div id="pagination-bar">
    <span id="page-info">—</span>
    <span id="quick-jump" title="Saut rapide : 5 repères espacés d'un cinquième des résultats"></span>
</div>

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const COMMUNE_BASE = '<?= site_url('commune') ?>';

let lignes           = [];
let totalRows        = 0;
const sortState      = { col: 'CodePostal', asc: true };
let refreshTriEntetes = () => {};
let searchTerm       = '';
let searchTimer      = null;
let fichiersCSV      = [];
let sansCoords       = false;
let deptFilter       = '';
let filtreEnRegion   = true; // true = En région uniquement (par défaut), false = Tous
let ligneSelectionnee = null;

function spinner(show) { $('#spinner').toggleClass('show', show); }
function importProgress(show, msg) {
    $('#import-progress').toggleClass('show', show);
    if (msg) $('#import-msg').text(msg);
}

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function lignesTriees() {
    const numFields = ['Id_LaPoste', 'Latitude', 'Longitude'];
    return [...lignes].sort((a, b) => {
        if (numFields.includes(sortState.col)) {
            return sortState.asc ? (+a[sortState.col]) - (+b[sortState.col]) : (+b[sortState.col]) - (+a[sortState.col]);
        }
        const va = String(a[sortState.col] ?? '').toLowerCase();
        const vb = String(b[sortState.col] ?? '').toLowerCase();
        return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
}

function renderGrille() {
    const $body = $('#tbody-grille').empty();
    refreshTriEntetes();

    const affichees = lignesTriees();

    if (!affichees.length) {
        $body.append('<tr><td colspan="6" class="text-center text-muted py-3">Aucun résultat.</td></tr>');
        return;
    }

    // Tout est rendu sur une seule page (jusqu'à 20 000 lignes) : on construit
    // le HTML en une passe et on l'injecte d'un bloc plutôt que ligne par ligne.
    const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
    const html = affichees.map(r => {
        const latVal = r.Latitude  ?? '';
        const lonVal = r.Longitude ?? '';
        const latCls = latVal === '' ? 'col-coords text-muted fst-italic' : 'col-coords';
        const lonCls = lonVal === '' ? 'col-coords text-muted fst-italic' : 'col-coords';
        return `<tr data-insee="${esc(r.Id_LaPoste)}" data-nom="${esc(r.Nom)}" data-cp="${esc(r.CodePostal)}" data-lat="${esc(latVal)}" data-lon="${esc(lonVal)}">`
            + `<td class="col-id">${String(r.Id_LaPoste).padStart(5, '0')}</td>`
            + `<td>${esc(r.CodePostal)}</td>`
            + `<td>${esc(r.Nom)}</td>`
            + `<td class="${latCls}">${latVal !== '' ? esc(latVal) : '—'}</td>`
            + `<td class="${lonCls}">${lonVal !== '' ? esc(lonVal) : '—'}</td>`
            + `<td>${esc(r.CodeDept)} ${r.NomDept ? '– ' + esc(r.NomDept) : ''}</td>`
            + `</tr>`;
    }).join('');
    $body.html(html);
}

// Sélection déléguée (une seule liaison, quel que soit le nombre de lignes).
$('#tbody-grille').on('click', 'tr', function () {
    $('#tbody-grille tr').removeClass('row-selected');
    $(this).addClass('row-selected');
    ligneSelectionnee = $(this);
    setStatus(`<b>${$(this).data('nom')}</b> (${$(this).data('cp')}) sélectionnée — appuyez sur <kbd>F2</kbd> pour modifier les coordonnées.`);
});
$('#tbody-grille').on('dblclick', 'tr', function () {
    $('#tbody-grille tr').removeClass('row-selected');
    $(this).addClass('row-selected');
    ligneSelectionnee = $(this);
    ouvrirModaleModif($(this));
});

function majPagination() {
    const affichees = $('#tbody-grille tr').length;
    $('#page-info').text(
        totalRows > affichees
            ? `${affichees.toLocaleString('fr-FR')} affichées sur ${totalRows.toLocaleString('fr-FR')} (résultat tronqué — affinez le filtre)`
            : `${totalRows.toLocaleString('fr-FR')} commune(s)`
    );

    // 5 repères de saut rapide : défilement de la grille au 1/5, 2/5… des lignes
    // affichées (pas = nombre de lignes / 5). Masqués s'il n'y a qu'un écran.
    const $qj = $('#quick-jump').empty();
    if (affichees > 100) {
        const step = Math.floor(affichees / 5);
        for (let i = 0; i < 5; i++) {
            const row = i * step;
            $qj.append(
                $('<button>')
                    .text((row + 1).toLocaleString('fr-FR'))
                    .attr('data-row', row)
                    .attr('title', `Défiler jusqu'à la ligne ${(row + 1).toLocaleString('fr-FR')}`)
            );
        }
    }
}

function chargerListe() {
    spinner(true);
    ligneSelectionnee = null;
    $.get(`${COMMUNE_BASE}/data`, { q: searchTerm, sans_coords: sansCoords ? 1 : 0, dept: deptFilter, region: filtreEnRegion ? 1 : 0 }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes    = res.data;
        totalRows = res.total;
        renderGrille();
        majPagination();
        $('#grid-wrapper').scrollTop(0);
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

$('#quick-jump').on('click', 'button', function () {
    const row = parseInt($(this).attr('data-row'), 10) || 0;
    const $tr = $('#tbody-grille tr').eq(row);
    if ($tr.length) $tr[0].scrollIntoView({ block: 'start' });
});

$('#search-input').on('input', function () {
    clearTimeout(searchTimer);
    const val = $(this).val().trim();
    searchTimer = setTimeout(() => {
        searchTerm = val;
        chargerListe();
    }, 400);
});

$('#sel-dept').on('change', function () {
    deptFilter = $(this).val();
    chargerListe();
});

function appliquerStyleFiltreRegion() {
    $('#lbl-filtre-region').text(filtreEnRegion ? 'Région' : 'Tous');
    $('#btn-filtre-region').css({
        background:  filtreEnRegion ? '#166534' : '',
        color:       filtreEnRegion ? '#fff'    : '',
        borderColor: filtreEnRegion ? '#166534' : 'transparent',
    });
}
appliquerStyleFiltreRegion();

$('#btn-filtre-region').on('click', function () {
    filtreEnRegion = !filtreEnRegion;
    appliquerStyleFiltreRegion();
    chargerListe();
});

$('#btn-exporter').on('click', function () {
    window.location = `${COMMUNE_BASE}/export`;
});

$('#btn-importer').on('click', () => $('#file-input').trigger('click'));

$('#file-input').on('change', function () {
    if (!this.files.length) return;
    fichiersCSV = Array.from(this.files).sort((a, b) => a.name.localeCompare(b.name));
    const noms  = fichiersCSV.map(f => f.name).join(', ');
    $('#import-options').addClass('show');
    setStatus(`${fichiersCSV.length} fichier(s) sélectionné(s) : ${noms}`);
    this.value = '';
});

$('#btn-annuler-import').on('click', function () {
    fichiersCSV = [];
    $('#import-options').removeClass('show');
    setStatus('Import annulé.');
});

$('#btn-confirmer-import').on('click', function () {
    if (!fichiersCSV.length) return;

    const vider = $('#chk-vider').is(':checked');
    if (vider && !confirm('Vider toute la table laposte avant import ?\n\nCette opération est irréversible.')) return;

    $('#import-options').removeClass('show');

    const files   = [...fichiersCSV];
    fichiersCSV   = [];
    let idx       = 0;
    let totalIns  = 0;
    let totalUpd  = 0;
    const erreurs = [];

    importProgress(true);

    function envoyerFichier() {
        if (idx >= files.length) {
            importProgress(false);
            const msg = `Import terminé : ${totalIns} insérée(s), ${totalUpd} mise(s) à jour.`
                      + (erreurs.length ? ' Erreurs : ' + erreurs.join(' | ') : '');
            toast(msg, !erreurs.length);
            chargerListe();
            return;
        }

        const file      = files[idx];
        const isFirst   = idx === 0;
        const pct       = Math.round((idx / files.length) * 100);

        $('#progress-bar-file').css('width', pct + '%');
        $('#import-msg').text(`Fichier ${idx + 1} / ${files.length} : ${file.name}`);
        $('#import-detail').text('Envoi en cours…');

        const fd = new FormData();
        fd.append('fichier',    file);
        fd.append('has_header', '1');
        if (isFirst && vider) fd.append('vider', '1');

        $.ajax({
            url: `${COMMUNE_BASE}/import`, type: 'POST',
            data: fd, processData: false, contentType: false, dataType: 'json',
            timeout: 300000,
            success(res) {
                if (res.ok) {
                    totalIns += res.inserts ?? 0;
                    totalUpd += res.updates ?? 0;
                    const ign = res.ignores ?? 0;
                    $('#import-detail').text(`✔ ${res.inserts ?? 0} insérée(s), ${res.updates ?? 0} mise(s) à jour, ${ign} ignorée(s)`);
                } else {
                    erreurs.push(`${file.name} : ${res.msg}`);
                    $('#import-detail').text(`✖ ${res.msg}`);
                }
                idx++;
                setTimeout(envoyerFichier, 200);
            },
            error(xhr) {
                const msg = xhr.responseJSON?.msg ?? 'Erreur réseau.';
                erreurs.push(`${file.name} : ${msg}`);
                idx++;
                setTimeout(envoyerFichier, 200);
            }
        });
    }

    envoyerFichier();
});

function ouvrirModaleModif($tr) {
    $('#mod-insee').val($tr.data('insee'));
    $('#mod-nom').text($tr.data('nom') + ' (' + $tr.data('cp') + ')');
    $('#mod-lat').val($tr.data('lat'));
    $('#mod-lon').val($tr.data('lon'));
    $('#mod-msg').text('');
    const el = document.getElementById('modal-modifier');
    let modal = bootstrap.Modal.getInstance(el);
    if (!modal) modal = new bootstrap.Modal(el);
    modal.show();
    document.getElementById('modal-modifier').addEventListener('shown.bs.modal', () => {
        $('#mod-lat').trigger('focus').trigger('select');
    }, { once: true });
}

$(document).on('keydown', function (e) {
    if (e.key === 'F2' && ligneSelectionnee) {
        e.preventDefault();
        ouvrirModaleModif(ligneSelectionnee);
    }
    if (e.key === 'Escape' && ligneSelectionnee && !$('.modal.show').length) {
        ligneSelectionnee.removeClass('row-selected');
        ligneSelectionnee = null;
        setStatus('Prêt.');
    }
});

$('#modal-modifier').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('#mod-btn-ok').trigger('click'); }
});

$('#btn-sans-coords').on('click', function () {
    sansCoords = !sansCoords;
    $(this).toggleClass('actif', sansCoords);
    $(this).find('i').toggleClass('bi-geo-alt', !sansCoords).toggleClass('bi-geo-alt-fill', sansCoords);
    chargerListe();
});

function parserCoords(text, idLat, idLon, idMsg) {
    const parts = text.trim().split(/,\s*/);
    if (parts.length >= 2) {
        const lat = parts[0].trim();
        const lon = parts[1].trim();
        if (!isNaN(parseFloat(lat)) && !isNaN(parseFloat(lon))
                && isFinite(lat) && isFinite(lon)) {
            $('#' + idLat).val(lat);
            $('#' + idLon).val(lon);
            $('#' + idMsg).html('<span class="text-success">✔ Coordonnées collées.</span>');
            return true;
        }
    }
    $('#' + idMsg).html('<span class="text-danger">✖ Format non reconnu. Attendu : « lat, lon »</span>');
    return false;
}

function collerCoords(idLat, idLon, idMsg) {
    if (navigator.clipboard && navigator.clipboard.readText) {
        navigator.clipboard.readText().then(text => {
            parserCoords(text, idLat, idLon, idMsg);
        }).catch(() => {
            $('#' + idMsg).html('<span class="text-warning">⚠ Cliquez sur le champ Latitude puis faites <kbd>Ctrl+V</kbd>.</span>');
            $('#' + idLat).trigger('focus').trigger('select');
        });
    } else {
        $('#' + idMsg).html('<span class="text-warning">⚠ Cliquez sur le champ Latitude puis faites <kbd>Ctrl+V</kbd>.</span>');
        $('#' + idLat).trigger('focus').trigger('select');
    }
}

function bindPasteCoords(idLat, idLon, idMsg) {
    $('#' + idLat).on('paste', function (e) {
        const text = (e.originalEvent.clipboardData || window.clipboardData).getData('text');
        if (text.indexOf(',') !== -1) {
            e.preventDefault();
            parserCoords(text, idLat, idLon, idMsg);
        }
    });
}

$('#add-btn-coller').on('click', () => collerCoords('add-lat', 'add-lon', 'add-msg'));
$('#mod-btn-coller').on('click', () => collerCoords('mod-lat', 'mod-lon', 'mod-msg'));
bindPasteCoords('add-lat', 'add-lon', 'add-msg');
bindPasteCoords('mod-lat', 'mod-lon', 'mod-msg');

$('#add-btn-ok').on('click', function () {
    $('#add-msg').text('');
    const insee = $('#add-insee').val().trim();
    const nom   = $('#add-nom').val().trim();
    const cp    = $('#add-cp').val().trim();
    const lat   = $('#add-lat').val().trim();
    const lon   = $('#add-lon').val().trim();

    if (!insee || !nom || !cp) {
        $('#add-msg').html('<span class="text-danger">N° INSEE, nom et code postal sont obligatoires.</span>');
        return;
    }
    spinner(true);
    $.post(COMMUNE_BASE, { insee, nom, cp, lat, lon }, function (res) {
        spinner(false);
        if (res.ok) {
            toast(res.msg, true);
            bootstrap.Modal.getInstance(document.getElementById('modal-ajouter')).hide();
            $('#add-insee, #add-nom, #add-cp, #add-lat, #add-lon').val('');
            $('#add-msg').text('');
            chargerListe();
        } else {
            $('#add-msg').html('<span class="text-danger">✖ ' + res.msg + '</span>');
        }
    }, 'json').fail(() => { spinner(false); $('#add-msg').html('<span class="text-danger">Erreur réseau.</span>'); });
});

$('#modal-ajouter').on('show.bs.modal', () => $('#add-msg').text(''));

$('#mod-btn-ok').on('click', function () {
    $('#mod-msg').text('');
    const insee = $('#mod-insee').val();
    const lat   = $('#mod-lat').val().trim();
    const lon   = $('#mod-lon').val().trim();

    if (!lat || !lon) {
        $('#mod-msg').html('<span class="text-danger">Latitude et longitude sont obligatoires.</span>');
        return;
    }
    spinner(true);
    $.ajax({ url: `${COMMUNE_BASE}/${insee}`, method: 'PUT', data: { lat, lon }, dataType: 'json' }).done(function (res) {
        spinner(false);
        if (res.ok) {
            toast(res.msg, true);
            bootstrap.Modal.getInstance(document.getElementById('modal-modifier')).hide();
            const $tr = $(`#tbody-grille tr[data-insee="${insee}"]`);
            $tr.attr('data-lat', res.lat).attr('data-lon', res.lon);
            $tr.find('td:eq(3)').text(res.lat).removeClass('text-muted fst-italic').addClass('col-coords');
            $tr.find('td:eq(4)').text(res.lon).removeClass('text-muted fst-italic').addClass('col-coords');
        } else {
            $('#mod-msg').html('<span class="text-danger">✖ ' + res.msg + '</span>');
        }
    }).fail(() => { spinner(false); $('#mod-msg').html('<span class="text-danger">Erreur réseau.</span>'); });
});

$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-communes thead th[data-field]', 'field', sortState, renderGrille);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
