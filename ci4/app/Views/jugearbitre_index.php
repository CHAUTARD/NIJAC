<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Juges-Arbitres (EN11)</title>

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

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 260px;
        }

        /* ── En-tête ── */
        #page-header {
            background: #2e7d32;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── MenuStrip ── */
        #menu-strip {
            display: flex;
            align-items: center;
            gap: .5rem;
            padding: .35rem .75rem;
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            flex-shrink: 0;
        }

        /* ── Grille ── */
        #grid-wrapper { flex: 1; overflow: auto; }

        #tbl-ja {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 1000px;
        }

        #tbl-ja thead th {
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
        #tbl-ja thead th:hover { background: #d4dff0; }
        #tbl-ja thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-ja thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-ja thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-ja thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        /* Menu « Colonnes » (affichage/masquage des colonnes, mémorisé par le navigateur) */
        #menu-colonnes { position: relative; }
        #menu-colonnes > summary { list-style: none; cursor: pointer; display: inline-flex; align-items: center; }
        #menu-colonnes > summary::-webkit-details-marker { display: none; }
        #menu-colonnes-list {
            position: absolute; right: 0; top: calc(100% + 4px); z-index: 60;
            background: #fff; border: 1px solid #ccc; border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,.15); padding: .35rem;
            min-width: 180px; max-height: 60vh; overflow: auto;
        }
        #menu-colonnes-list label {
            display: flex; align-items: center; gap: .45rem;
            padding: .25rem .4rem; font-size: .85rem; cursor: pointer; white-space: nowrap;
        }
        #menu-colonnes-list label:hover { background: #eef4ff; border-radius: 4px; }

        #tbl-ja tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-ja tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-ja tbody tr:hover   { background: #dce8f8; }
        #tbl-ja tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-ja tbody td { border: 1px solid #e0e8f0; padding: 0; }

        .cell-inner {
            display: block;
            padding: .28rem .45rem;
            min-height: 28px;
            outline: none;
            white-space: nowrap;
            overflow: hidden;
        }
        td.col-readonly { background: #f0f4fa; }
        td.col-readonly .cell-inner { color: #6b7280; font-style: italic; }
        td[data-field="codedept"] .cell-inner { text-align: center; }

        /* Actif badge */
        .badge-actif     { background: #d1fae5; color: #065f46; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-inactif   { background: #fee2e2; color: #991b1b; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-defisc    { background: #dbeafe; color: #1e40af; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-no-defisc { background: #f3f4f6; color: #6b7280; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }

        /* ── Toggle Tous / Actifs ── */
        #toggle-actif {
            display: inline-flex;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            overflow: hidden;
            font-size: .82rem;
        }
        #toggle-actif button {
            padding: .2rem .65rem;
            border: none;
            background: #fff;
            cursor: pointer;
            color: #374151;
            transition: background .15s, color .15s;
        }
        #toggle-actif button:hover { background: #e8eef7; }
        #toggle-actif button.active {
            background: #1a3a6b;
            color: #fff;
            font-weight: 600;
        }
        #btn-erreurs-cp { color: #92400e; }
        #btn-erreurs-cp:hover { background: #fef3c7; }
        #btn-erreurs-cp.active { background: #d97706; color: #fff; }

        /* ── Menu déroulant style Windows ── */
        .win-menu-wrap { position: relative; }
        .win-menu-btn {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .25rem .75rem;
            background: #fff;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            font-size: .83rem;
            cursor: pointer;
            white-space: nowrap;
            color: #212529;
            user-select: none;
        }
        .win-menu-btn:hover,
        .win-menu-btn.open { background: #e8eef7; border-color: #a0b4d0; }
        .win-menu-btn i.caret { font-size: .7rem; margin-left: .15rem; }
        .win-menu-drop {
            display: none;
            position: absolute;
            top: calc(100% + 2px);
            left: 0;
            min-width: 230px;
            background: #fff;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            box-shadow: 2px 4px 12px rgba(0,0,0,.15);
            z-index: 9000;
            padding: .25rem 0;
        }
        .win-menu-drop.open { display: block; }
        .win-menu-drop .drop-item {
            display: flex;
            align-items: center;
            gap: .55rem;
            padding: .35rem 1rem;
            font-size: .84rem;
            color: #212529;
            cursor: pointer;
            white-space: nowrap;
            text-decoration: none;
            background: none;
            border: none;
            width: 100%;
            text-align: left;
        }
        .win-menu-drop .drop-item:hover { background: #e8eef7; color: #1a3a6b; }
        .win-menu-drop .drop-item i { font-size: 1rem; width: 1.1rem; text-align: center; }
        .win-menu-drop .drop-sep { border: none; border-top: 1px solid #dee2e6; margin: .25rem 0; }
        .win-menu-drop .drop-item.green { color: #1a6b2b; font-weight: 600; }
        .win-menu-drop .drop-item.green:hover { background: #d1fae5; }

        /* ── Spinner ── */

        #lbl-count {
            margin-left: .75rem;
            padding: .2rem .6rem;
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            font-size: .82rem;
            color: #1a3a6b;
            font-weight: 600;
        }

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
    'phIcon' => 'person-badge-fill', 'phTitle' => 'Gestion des Juges-Arbitres', 'phCode' => 'EN11',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Input file caché pour import XLSX JA -->
<input type="file" id="file-input" accept=".csv,.txt" style="display:none">
<input type="file" id="file-input-ebp" accept=".csv,text/csv" style="display:none">

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<!-- MenuStrip -->
<div id="menu-strip">
    <!-- Menu déroulant style Windows -->
    <div class="win-menu-wrap">
        <button class="win-menu-btn" id="win-menu-trigger">
            <i class="bi bi-grid-3x3-gap-fill"></i>Actions
            <i class="bi bi-chevron-down caret"></i>
        </button>
        <div class="win-menu-drop" id="win-menu-drop">
            <?php if ($isAdmin): ?>
            <button class="drop-item" id="btn-import-fftt-dept" data-bs-toggle="modal" data-bs-target="#modal-import-fftt">
                <i class="bi bi-cloud-arrow-down-fill"></i>Importer JA1/JA2/JA3 depuis FFTT (par département)
            </button>
            <button class="drop-item" id="btn-importer">
                <i class="bi bi-file-earmark-spreadsheet"></i>Importer depuis fichier FFTT (102_*.csv)
            </button>
            <hr class="drop-sep">
            <?php endif; ?>
            <button class="drop-item" id="btn-import-ebp">
                <i class="bi bi-file-earmark-text"></i>Importer numéros de compte EBP (CSV)
            </button>
            <hr class="drop-sep">
            <button class="drop-item green" id="btn-nouveau-ja">
                <i class="bi bi-person-plus-fill"></i>Nouveau JA
            </button>
            <a class="drop-item" href="https://www.dcode.fr/code-postal" target="_blank">
                <i class="bi bi-globe2"></i>dCode code-postal
            </a>
        </div>
    </div>
    <div id="toggle-actif" style="margin-left:.5rem">
        <button id="btn-tous">Tous</button>
        <button id="btn-actifs"       class="active">Actifs seulement</button>
        <button id="btn-erreurs-cp">⚠ Erreurs CP/Ville</button>
    </div>
    <span id="lbl-count">0 JA</span>
    <span style="flex:1"></span>
    <details id="menu-colonnes">
        <summary class="menu-item" style="border-color:transparent;"><i class="bi bi-layout-three-columns me-1"></i>Colonnes</summary>
        <div id="menu-colonnes-list"></div>
    </details>
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int) $d['code'] ?>" <?= ((string) (int) $d['code'] === (string) (int) $deptUser) ? 'selected' : '' ?>><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?></option>
        <?php endforeach; ?>
        <?php if ($deptLimitrophes): ?>
        <option disabled>── Limitrophes ──</option>
        <?php foreach ($deptLimitrophes as $d): ?>
        <option value="<?= (int) $d['code'] ?>" <?= ((string) (int) $d['code'] === (string) (int) $deptUser) ? 'selected' : '' ?>><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?> (<?= esc($d['region']) ?>)</option>
        <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <button class="menu-item" id="btn-filtre-region" title="Cliquer pour n'afficher que les JA de la région, ou tous les JA" style="border-color:transparent;">
        <i class="bi bi-geo-alt me-1"></i><span id="lbl-filtre-region">Région</span>
    </button>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-ja">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id">N° JA<span class="sort-icon"></span></th>
                <th style="width:55px;display:none"  data-field="grade">Grade<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:140px" data-field="prenom">Prénom<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="email">Email<span class="sort-icon"></span></th>
                <th style="width:120px" data-field="telephone">Téléphone<span class="sort-icon"></span></th>
                <th style="width:65px"  data-field="actif">Actif<span class="sort-icon"></span></th>
                <th style="width:100px;display:none" data-field="date_validation_fftt">Date Validation<span class="sort-icon"></span></th>
                <th style="width:75px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
                <th style="width:65px"  data-field="codedept">Exerce<span class="sort-icon"></span></th>
                <th style="width:90px;display:none"  data-field="defiscalisation">Défiscalisation<span class="sort-icon"></span></th>
                <th style="width:85px;display:none"  data-field="nationale">Nationale<span class="sort-icon"></span></th>
                <th style="width:110px;display:none" data-field="num_compte_ebp">Cpte EBP<span class="sort-icon"></span></th>
                <th style="width:95px;display:none"  data-field="arbitre_autres_depts">Arb. autres dépts<span class="sort-icon"></span></th>
                <th style="width:120px;display:none" data-field="depts_arbitrage">Dépts arbitrage<span class="sort-icon"></span></th>
                <th style="width:75px"  data-field="cp">CP<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="ville">Ville<span class="sort-icon"></span></th>
                <th style="width:75px"  class="no-sort">Lien dispo</th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="19" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<?= view('partials/page_footer', [
    'pfStatusText' => 'Prêt. — Double-cliquez sur une ligne pour la modifier.',
    'pfStatusAlign' => 'left',
]) ?>

<!-- Modale rapport import EBP -->
<div class="modal fade" id="modal-import-ebp" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a3a6b;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-file-earmark-text me-1"></i>Import numéros de compte EBP</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="ebp-resume" class="mb-2" style="font-size:.9rem;"></div>
        <div id="ebp-echecs-bloc" style="display:none;">
          <div class="fw-semibold text-danger mb-1" style="font-size:.85rem;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>Lignes non traitées :
          </div>
          <table class="table table-sm table-bordered mb-2" style="font-size:.82rem;">
            <thead class="table-danger">
              <tr><th>#</th><th>Nom / Prénom (CSV)</th><th>Raison</th></tr>
            </thead>
            <tbody id="ebp-echecs-body"></tbody>
          </table>
          <button class="btn btn-sm btn-outline-secondary" id="btn-ebp-telecharger">
            <i class="bi bi-download me-1"></i>Télécharger le rapport CSV
          </button>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- Modale progression import Excel -->
<div class="modal fade" id="modal-import-excel" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#198754;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Import CSV — Juges-Arbitres</h6>
      </div>
      <div class="modal-body pb-2">
        <div class="mb-3">
          <div class="d-flex justify-content-between small mb-1">
            <span id="xlsx-progress-label">Envoi du fichier…</span>
            <span id="xlsx-progress-pct">0 %</span>
          </div>
          <div class="progress" style="height:20px;">
            <div id="xlsx-progress-bar"
                 class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                 style="width:0%;transition:width .3s ease;"></div>
          </div>
        </div>
        <div id="xlsx-log"
             style="max-height:260px;overflow-y:auto;font-size:.78rem;
                    background:#f8fafc;border:1px solid #e0e8f0;
                    border-radius:4px;padding:.5rem;font-family:monospace;"></div>
      </div>
      <div class="modal-footer py-2" id="xlsx-footer" style="display:none;">
        <button type="button" class="btn btn-success btn-sm" id="btn-xlsx-ok" data-bs-dismiss="modal">
          <i class="bi bi-check-lg me-1"></i>OK — afficher la grille
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modale Import FFTT par département -->
<div class="modal fade" id="modal-import-fftt" tabindex="-1" aria-labelledby="modal-import-fftt-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header" style="background:#0d6efd;color:#fff;">
        <h5 class="modal-title" id="modal-import-fftt-titre"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Importer les JA1 / JA2 / JA3 depuis l'API FFTT</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="btn-fermer-import-fftt"></button>
      </div>
      <div class="modal-body">

        <!-- Étape 1 : choix département -->
        <div id="import-fftt-step1">
          <p class="text-muted small mb-3">
            Sélectionnez un département. L'import parcourt tous les clubs,
            vérifie chaque licencié via <code>xml_licence_b</code> et insère ou met à jour
            les JA1/JA2/JA3 trouvés. Les AR sont exclus. <strong>CP et Ville ne sont pas écrasés.</strong>
          </p>
          <div class="input-group mb-3" style="max-width:380px">
            <label class="input-group-text" for="import-fftt-dept"><i class="bi bi-map me-1"></i>Département</label>
            <select id="import-fftt-dept" class="form-select">
              <option value="">— Choisir —</option>
              <?php foreach ($deptActifs as $d): ?>
              <option value="<?= (int) $d['code'] ?>"><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?></option>
              <?php endforeach; ?>
              <?php if ($deptLimitrophes): ?>
              <option disabled>── Limitrophes ──</option>
              <?php foreach ($deptLimitrophes as $d): ?>
              <option value="<?= (int) $d['code'] ?>"><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?> (<?= esc($d['region']) ?>)</option>
              <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <button class="btn btn-primary" id="btn-lancer-import-fftt">
            <i class="bi bi-play-fill me-1"></i>Lancer l'import
          </button>
        </div>

        <!-- Étape 2 : progression -->
        <div id="import-fftt-step2" style="display:none;">
          <div class="d-flex align-items-center gap-2 mb-2 px-2 py-1 rounded" style="background:#e8f0fe;border:1px solid #c5d5f8;">
            <i class="bi bi-geo-alt-fill text-primary"></i>
            <span class="small fw-bold text-primary">Département en cours d'import :</span>
            <span id="import-fftt-dept-label" class="small fw-semibold text-dark"></span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span id="import-fftt-label" class="fw-semibold small text-primary">Récupération des clubs…</span>
            <span id="import-fftt-pct" class="small text-muted">0 %</span>
          </div>
          <div class="progress mb-3" style="height:18px;">
            <div id="import-fftt-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 style="width:0%" role="progressbar"></div>
          </div>
          <div class="row text-center mb-3">
            <div class="col-3">
              <div class="fw-bold fs-5 text-success" id="cnt-nouveaux">0</div>
              <div class="small text-muted" id="cnt-nouveaux-label">Nouveaux JAs</div>
            </div>
            <div class="col-3" id="cnt-maj-col">
              <div class="fw-bold fs-5 text-info" id="cnt-maj">0</div>
              <div class="small text-muted">Mis à jour</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-secondary" id="cnt-membres">0</div>
              <div class="small text-muted">Membres vérifiés</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-warning" id="cnt-erreurs">0</div>
              <div class="small text-muted">Erreurs API</div>
            </div>
          </div>
          <!-- Log des JAs trouvés -->
          <div id="import-fftt-log" style="max-height:200px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

        <!-- Étape 2b : sélection des JA (départements limitrophes uniquement) -->
        <div id="import-fftt-step2b" style="display:none;">
          <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
            <div>
              <span class="fw-bold text-primary fs-6">Sélectionnez les JA à importer</span>
              <span class="text-muted small ms-2" id="import-fftt-2b-sous-titre"></span>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-secondary btn-sm" id="btn-2b-tout-cocher"><i class="bi bi-check-all me-1"></i>Tout cocher</button>
              <button class="btn btn-outline-secondary btn-sm" id="btn-2b-tout-decocher"><i class="bi bi-square me-1"></i>Tout décocher</button>
            </div>
          </div>
          <div id="import-fftt-2b-list" style="max-height:360px;overflow-y:auto;border:1px solid #dee2e6;border-radius:6px;padding:.5rem .75rem;"></div>
          <div id="import-fftt-2b-erreurs" class="text-danger small mt-1" style="display:none;"></div>
          <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
            <button class="btn btn-success" id="btn-2b-valider">
              <i class="bi bi-check-lg me-1"></i>Valider l'import
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="btn-2b-retour">
              <i class="bi bi-arrow-left me-1"></i>Retour
            </button>
            <span class="text-muted small" id="import-fftt-2b-nb-sel"></span>
          </div>
        </div>

        <!-- Étape 3 : résumé final -->
        <div id="import-fftt-step3" style="display:none;">
          <div class="alert alert-success mb-3" id="import-fftt-resume"></div>
          <div id="import-fftt-log-final" style="max-height:250px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>

<!-- Modale Nouveau JA -->
<div class="modal fade" id="modal-nouveau-ja" tabindex="-1" aria-labelledby="modal-nouveau-ja-titre" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#1a3a6b;color:#fff;">
        <h5 class="modal-title" id="modal-nouveau-ja-titre"><i class="bi bi-person-plus-fill me-2"></i>Créer un nouveau Juge-Arbitre</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-nouveau-ja" novalidate>
          <div class="row g-3">
            <div class="col-md-2">
              <label class="form-label fw-semibold" id="nja-id-label">N° Licence <span class="text-danger">*</span></label>
              <input type="number" class="form-control form-control-sm" id="nja-id" min="1" placeholder="N° licence FFTT">
            </div>
            <div class="col-md-2">
              <label class="form-label fw-semibold">Grade <span class="text-danger">*</span></label>
              <select class="form-select form-select-sm" id="nja-grade" required>
                <option value="">— Choisir —</option>
                <option value="JA1">JA1</option>
                <option value="JA2">JA2</option>
                <option value="JA3">JA3</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm text-uppercase" id="nja-nom" required placeholder="NOM">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Prénom <span class="text-danger">*</span></label>
              <input type="text" class="form-control form-control-sm" id="nja-prenom" required placeholder="Prénom">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" class="form-control form-control-sm" id="nja-email" placeholder="adresse@email.fr">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Téléphone</label>
              <input type="text" class="form-control form-control-sm" id="nja-telephone" placeholder="06.12.34.56.78">
            </div>
            <div class="col-12">
              <div class="row g-2">
                <div class="col-md-5">
                  <label class="form-label fw-semibold">Exerce dans</label>
                  <select class="form-select form-select-sm" id="nja-dept">
                    <option value="">— Tous —</option>
                    <?php foreach ($deptActifs as $d): ?>
                    <option value="<?= (int) $d['code'] ?>"><?= (int) $d['code'] ?> — <?= esc($d['nom']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-7">
                  <label class="form-label fw-semibold">Club</label>
                  <select class="form-select form-select-sm" id="nja-id-club">
                    <option value="">— Sélectionnez d'abord un département —</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Code postal / Ville</label>
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="nja-cp" placeholder="76000" maxlength="10" style="max-width:90px">
                <input type="text" class="form-control text-uppercase" id="nja-ville" placeholder="ROUEN">
              </div>
              <div id="nja-laposte-msg" class="form-text" style="min-height:1.2em;"></div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">N° Compte EBP</label>
              <input type="text" class="form-control form-control-sm" id="nja-cpte-ebp" placeholder="">
            </div>
            <div class="col-12">
              <div class="d-flex gap-4 mt-1">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nja-actif" checked>
                  <label class="form-check-label" for="nja-actif">Actif</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nja-defisc">
                  <label class="form-check-label" for="nja-defisc">Défiscalisation</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="nja-nationale">
                  <label class="form-check-label" for="nja-nationale">Nationale</label>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="nja-arb-voisins">
                <label class="form-check-label" for="nja-arb-voisins">Accepte d'arbitrer dans un ou plusieurs départements voisins</label>
              </div>
              <div id="nja-arb-voisins-depts" class="d-none ms-4 mt-1">
                <div class="d-flex flex-wrap gap-3">
                  <?php foreach (['14' => 'Calvados', '27' => 'Eure', '50' => 'Manche', '61' => 'Orne', '76' => 'Seine-Maritime'] as $d => $nom): ?>
                  <label style="font-size:.9rem">
                    <input type="checkbox" class="form-check-input me-1 nja-arb-dept" value="<?= $d ?>"><?= $d ?> <?= $nom ?>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </form>
        <!-- Zone suggestions laposte -->
        <div id="nja-suggestions" class="mt-2" style="display:none;">
          <div class="fw-semibold text-primary mb-1">Plusieurs communes trouvées — choisissez :</div>
          <div id="nja-suggestions-list" class="d-flex flex-wrap gap-1"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-success btn-sm" id="btn-enregistrer-ja">
          <i class="bi bi-check-lg me-1"></i>Créer le JA
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const JUGEARBITRE_BASE = '<?= site_url('jugearbitre') ?>';
const LAPOSTE_BASE = '<?= site_url('laposte') ?>';
const DISPONIBILITE_JA_BASE = '<?= site_url('disponibilite-ja') ?>';

let lignes     = [];
let filtreActif   = true;   // false = tous, true = actifs seulement
let filtreErreursCp = false; // true = uniquement les lignes sans id_laposte
let filtreEnRegion = true; // true = En région uniquement (par défaut), false = Tous
const sortState = { col: 'nom', asc: true };
let searchTerm = '';
const isAdmin  = <?= $isAdmin ? 'true' : 'false' ?>;
let deptFiltre = <?= json_encode($deptUser) ?>; // filtré par défaut sur le département de l'utilisateur connecté (admin inclus)
const DEPTS_REGION = new Set(<?= json_encode(array_column($deptActifs, 'code')) ?>);

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function deptDeJA(l) {
    return String(l.codedept ?? '');
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let source = [...lignes];
    if (filtreActif)     source = source.filter(l => l.actif === 1);
    if (filtreErreursCp) source = source.filter(l => l.id_laposte == null);
    if (filtreEnRegion)  source = source.filter(l => DEPTS_REGION.has(deptDeJA(l)));
    let result = term
        ? source.filter(l =>
            String(l.id              ?? '').toLowerCase().includes(term) ||
            String(l.nom             ?? '').toLowerCase().includes(term) ||
            String(l.prenom          ?? '').toLowerCase().includes(term) ||
            String(l.email           ?? '').toLowerCase().includes(term) ||
            String(l.telephone       ?? '').toLowerCase().includes(term) ||
            String(l.grade           ?? '').toLowerCase().includes(term) ||
            String(l.id_club         ?? '').toLowerCase().includes(term) ||
            String(l.nom_club        ?? '').toLowerCase().includes(term) ||
            String(l.cp              ?? '').toLowerCase().includes(term) ||
            String(l.ville           ?? '').toLowerCase().includes(term))
        : source;

    const numFields = ['id'];
    const sortField = sortState.col, sortDir = sortState.asc ? 'asc' : 'desc';
    result.sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        if (sortField === 'actif') {
            return sortDir === 'asc' ? a.actif - b.actif : b.actif - a.actif;
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    refreshTriEntetes();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun juge-arbitre.';
        $body.append(`<tr><td colspan="19" class="text-center text-muted py-3">${msg}</td></tr>`);
    } else {
        affichees.forEach(l => {
            const idx  = l._idx;          // index stable, indépendant du filtre/tri
            const $tr  = $('<tr>').attr('data-idx', idx);
            const actifHtml = l.actif
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const defiscHtml = l.defiscalisation
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const arbVoisinsHtml = l.arbitre_autres_depts
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const nationaleHtml = l.nationale
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';

            $tr.append(makeTd(l.id,              idx, 'id',              true));
            $tr.append(makeTd(l.grade,            idx, 'grade',           false));
            $tr.append(makeTd(l.nom,              idx, 'nom',             false));
            $tr.append(makeTd(l.prenom,           idx, 'prenom',          false));
            $tr.append(makeTd(l.email,            idx, 'email',           false));
            $tr.append(makeTd(l.telephone,        idx, 'telephone',       false));
            $tr.append(makeTdHtml(actifHtml,      idx, 'actif'));
            $tr.append(makeTd(l.date_validation_fftt, idx, 'date_validation_fftt', true));
            $tr.append(makeTd(l.id_club,          idx, 'id_club',         true));
            $tr.append(makeTd(l.nom_club,         idx, 'nom_club',        true));
            $tr.append(makeTd(l.codedept,         idx, 'codedept',        true));
            $tr.append(makeTdHtml(defiscHtml,     idx, 'defiscalisation'));
            $tr.append(makeTdHtml(nationaleHtml,  idx, 'nationale'));
            $tr.append(makeTd(l.num_compte_ebp,   idx, 'num_compte_ebp', false));
            $tr.append(makeTdHtml(arbVoisinsHtml, idx, 'arbitre_autres_depts'));
            $tr.append(makeTd(l.depts_arbitrage,  idx, 'depts_arbitrage', true));
            $tr.append(makeCpTd(l,    idx));
            $tr.append(makeVilleTd(l, idx));
            // Boutons lien dispo / adresse — masqués si le JA n'a pas d'email
            const $tdLien = $('<td>').css({textAlign:'center', verticalAlign:'middle', padding:'.2rem'});
            if (l.email) {
                const dispoCls   = l.nb_dispo > 0 ? 'btn-success'         : 'btn-outline-secondary';
                const dispoTitle = l.nb_dispo > 0 ? `Disponibilités saisies (${l.nb_dispo} journée(s))` : 'Aucune disponibilité saisie — cliquez pour ouvrir';
                const adrTitle = l.id_laposte ? 'Envoyer la demande de mise à jour d\'adresse' : 'Adresse manquante — envoyer la demande';
                $tdLien.html(`<button class="btn btn-sm ${dispoCls} btn-lien-dispo" data-id="${l.id}" title="${dispoTitle}"><i class="bi bi-calendar2-check"></i></button>`
                    + ` <button class="btn btn-sm ${l.id_laposte ? 'btn-outline-secondary' : 'btn-warning'} btn-lien-adresse" data-id="${l.id}" data-nom="${escHtml(l.prenom + ' ' + l.nom)}" data-email="${escHtml(l.email ?? '')}" title="${adrTitle}"><i class="bi bi-geo-alt"></i></button>`);
            } else {
                $tdLien.html('<span class="text-muted small" title="Email manquant">—</span>');
            }
            $tr.append($tdLien);
            $body.append($tr);
        });
    }

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}. ` : '';
    setStatus(`${info}Double-cliquez sur une ligne pour la modifier.`);
    $('#lbl-count').text(`${affichees.length}/${lignes.length} JA`);

    appliquerColonnesCachees();
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? 'col-readonly' : '').attr('data-idx', idx).attr('data-field', field);
    $('<div class="cell-inner">').text(val ?? '').appendTo($td);
    return $td;
}

function makeTdHtml(html, idx, field) {
    return $('<td>').attr('data-idx', idx).attr('data-field', field)
                     .css({textAlign: 'center', verticalAlign: 'middle', padding: '.2rem'})
                     .html(html);
}

// ── Cellules CP / Ville (lecture seule — modification via bouton adresse) ────
function makeCpTd(l, idx) {
    const trouve = l.id_laposte != null;
    const bg     = trouve ? '#d1fae5' : (l.cp ? '#fee2e2' : '');
    const $td = $('<td>').attr('data-idx', idx).attr('data-field', 'cp').css({ background: bg });
    $('<div class="cell-inner">').text(l.cp ?? '').appendTo($td);
    return $td;
}

function makeVilleTd(l, idx) {
    const trouve = l.id_laposte != null;
    const bg     = trouve ? '#d1fae5' : (l.ville ? '#fee2e2' : '');
    const $td = $('<td>').attr('data-idx', idx).attr('data-field', 'ville').css({ background: bg });
    $('<div class="cell-inner">').text(l.ville ?? '').appendTo($td);
    return $td;
}


// ── Sélection / Édition ───────────────────────────────────────────────────────
$(document).on('click', '#tbody-grille tr', function () {
    $('#tbody-grille tr').removeClass('selected');
    $(this).addClass('selected');
});

$(document).on('dblclick', '#tbody-grille tr', function () {
    const idx = +$(this).attr('data-idx');
    if (lignes[idx]) ouvrirModaleJa(lignes[idx]);
});

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.get(`${JUGEARBITRE_BASE}/liste`, { dept: deptFiltre }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map((r, i) => ({
            _idx:            i,
            id:              r.Id_JA,
            nom:             r.Nom,
            prenom:          r.Prenom,
            email:           r.Email,
            telephone:       r.Telephone,
            grade:           r.Grade,
            actif:           +r.Actif,
            id_club:         r.Id_Club,
            codedept:        r.CodeDept ?? '',
            nom_club:        r.NomClub ?? '',
            id_laposte:       r.Id_LaPoste,
            num_compte_ebp:   r.NumCompteEBP ?? '',
            defiscalisation:  +r.Defiscalisation,
            nationale:        +r.Nationale,
            arbitre_autres_depts: +r.ArbitreAutresDepts,
            depts_arbitrage:      r.DeptsArbitrage ?? '',
            nb_dispo:               +r.NbDispo,
            cp:                     r.CP    ?? '',
            ville:                  r.Ville ?? '',
            date_validation_fftt:     r.DateValidationFFTT ?? null,
        }));
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Menu déroulant Windows ────────────────────────────────────────────────────
$('#win-menu-trigger').on('click', function (e) {
    e.stopPropagation();
    const open = $('#win-menu-drop').toggleClass('open').hasClass('open');
    $(this).toggleClass('open', open);
});
$(document).on('click', function () {
    $('#win-menu-drop').removeClass('open');
    $('#win-menu-trigger').removeClass('open');
});
// Ferme le menu « Colonnes » au clic hors de celui-ci
$(document).on('click', function (e) {
    if (!$(e.target).closest('#menu-colonnes').length) $('#menu-colonnes').removeAttr('open');
});
$('#win-menu-drop').on('click', '.drop-item', function () {
    $('#win-menu-drop').removeClass('open');
    $('#win-menu-trigger').removeClass('open');
});

<?php if ($isAdmin): ?>
// ── Import FFTT par département ───────────────────────────────────────────────
const DEPT_ACTIFS_CODES = <?= json_encode(array_map('strval', array_column($deptActifs, 'code'))) ?>;
let importFfttEnCours = false;
<?php endif; ?>

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

<?php if ($isAdmin): ?>
let _scanJAs = []; // JAs trouvés lors du scan limitrophe

$('#modal-import-fftt').on('hidden.bs.modal', function () {
    if (!importFfttEnCours) resetImportFftt();
});

function resetImportFftt() {
    $('#import-fftt-step1').show();
    $('#import-fftt-step2, #import-fftt-step2b, #import-fftt-step3').hide();
    $('#import-fftt-dept').val('');
    $('#import-fftt-bar').css('width', '0%');
    $('#import-fftt-label').text('Récupération des clubs…');
    $('#import-fftt-pct').text('0 %');
    $('#cnt-nouveaux-label').text('Nouveaux JAs');
    $('#cnt-maj-col').show();
    ['cnt-nouveaux','cnt-maj','cnt-membres','cnt-erreurs'].forEach(id => $(`#${id}`).text('0'));
    $('#import-fftt-log, #import-fftt-log-final').empty();
    $('#import-fftt-2b-list').empty();
    importFfttEnCours = false;
    _scanJAs = [];
}

// ── Utilitaire : lancer la progression (scan ou import direct) ────────────────
function lancerProgressionClubs(dep, depText, modeScan) {
    importFfttEnCours = true;
    $('#import-fftt-step1').hide();
    $('#import-fftt-step2').show();
    $('#btn-fermer-import-fftt').prop('disabled', true);
    $('#import-fftt-dept-label').text(depText);

    if (modeScan) {
        $('#cnt-nouveaux-label').text('JAs trouvés');
        $('#cnt-maj-col').hide();
    }

    // Réinitialise Actif=0 pour tout le département avant l'import : seuls les
    // JA effectivement retrouvés dans le rapport FFTT ci-dessous repasseront
    // à Actif=1 (voir reinitialiserActifDept()).
    $.post(`${JUGEARBITRE_BASE}/fftt/reset-actif-dept`, { dep }, function (resReset) {
        if (!resReset.ok) {
            nijacToast('Erreur : ' + (resReset.msg || 'réinitialisation du département'), 'danger');
            resetImportFftt();
            $('#btn-fermer-import-fftt').prop('disabled', false);
            return;
        }

        $('#import-fftt-log').append(`<div class="text-primary">↻ Remise à Actif = Non de tous les JA du département <strong>${escHtml(depText)}</strong>.</div>`).scrollTop(9999);

        lancerImportApresReset(dep, depText, modeScan);
    }, 'json').fail(() => {
        nijacToast('Erreur réseau lors de la réinitialisation du département.', 'danger');
        resetImportFftt();
        $('#btn-fermer-import-fftt').prop('disabled', false);
    });
}

function lancerImportApresReset(dep, depText, modeScan) {
    let totalNouveaux = 0, totalMaj = 0, totalMembres = 0, totalErreurs = 0;
    const logLines = [];
    _scanJAs = [];

    $.post(`${JUGEARBITRE_BASE}/fftt/clubs-dept`, { dep }, function (res) {
        if (!res.ok) {
            nijacToast('Erreur : ' + res.msg, 'danger');
            resetImportFftt();
            $('#btn-fermer-import-fftt').prop('disabled', false);
            return;
        }

        const clubs = res.clubs.filter(c => c.numero);
        const total = clubs.length;
        let done = 0;

        $('#import-fftt-label').text(`0 / ${total} clubs traités…`);

        const urlAction = modeScan ? `${JUGEARBITRE_BASE}/fftt/scan-club` : `${JUGEARBITRE_BASE}/fftt/import-club`;

        function traiterClub() {
            if (done >= total) {
                importFfttEnCours = false;
                $('#btn-fermer-import-fftt').prop('disabled', false);
                $('#import-fftt-step2').hide();

                if (modeScan) {
                    // Afficher l'étape de sélection
                    afficherSelectionJAs(_scanJAs, totalMembres, totalErreurs, logLines, depText);
                } else {
                    $('#import-fftt-step3').show();
                    $('#import-fftt-resume').html(
                        `<i class="bi bi-check-circle-fill me-2"></i>` +
                        `Import terminé pour le département <strong>${escHtml(depText)}</strong> — ` +
                        `<strong>${totalNouveaux}</strong> nouveau(x) JA, ` +
                        `<strong>${totalMaj}</strong> mis à jour, ` +
                        `<strong>${totalMembres}</strong> membres vérifiés` +
                        (totalErreurs ? `, <strong>${totalErreurs}</strong> erreur(s) API` : '') + '.'
                    );
                    $('#import-fftt-log-final').html(logLines.join(''));
                    chargerListe();
                }
                return;
            }

            const club = clubs[done];
            const pct  = Math.round(done / total * 100);
            $('#import-fftt-bar').css('width', pct + '%');
            $('#import-fftt-pct').text(pct + ' %');
            $('#import-fftt-label').text(`${done + 1} / ${total} — ${club.nom}`);

            $.post(urlAction, { num_club: club.numero }, function (r) {
                if (r.ok) {
                    totalMembres += r.total_membres;
                    totalErreurs += r.erreurs;
                    if (r.erreurs_msgs && r.erreurs_msgs.length) {
                        r.erreurs_msgs.forEach(msg => {
                            const line = `<div class="text-danger">⚠ ${msg}</div>`;
                            logLines.push(line);
                            $('#import-fftt-log').append(line).scrollTop(9999);
                        });
                    }
                    r.trouves.forEach(ja => {
                        if (modeScan) {
                            _scanJAs.push(ja);
                            totalNouveaux = _scanJAs.length;
                            const cls  = ja.en_base ? 'text-secondary' : 'text-success';
                            const lbl  = ja.en_base ? '≡ EN BASE' : '✚ NOUVEAU';
                            const line = `<div class="${cls}">[${lbl}] ${ja.grade} — ${ja.nom} ${ja.prenom} (${ja.licence})</div>`;
                            logLines.push(line);
                            $('#import-fftt-log').append(line).scrollTop(9999);
                        } else {
                            const cls   = ja.statut === 'nouveau' ? 'text-success' : 'text-info';
                            const label = ja.statut === 'nouveau' ? '✚ NOUVEAU' : '↻ MAJ';
                            const line  = `<div class="${cls}">[${label}] ${ja.grade} — ${ja.nom} ${ja.prenom} (${ja.licence})</div>`;
                            logLines.push(line);
                            $('#import-fftt-log').append(line).scrollTop(9999);
                            if (ja.statut === 'nouveau') totalNouveaux++; else totalMaj++;
                        }
                    });
                    $('#cnt-nouveaux').text(totalNouveaux);
                    if (!modeScan) $('#cnt-maj').text(totalMaj);
                    $('#cnt-membres').text(totalMembres);
                    $('#cnt-erreurs').text(totalErreurs);
                }
                done++;
                traiterClub();
            }, 'json').fail(() => {
                totalErreurs++;
                $('#cnt-erreurs').text(totalErreurs);
                done++;
                traiterClub();
            });
        }

        traiterClub();
    }, 'json').fail(() => {
        nijacToast('Erreur réseau lors de la récupération des clubs.', 'danger');
        resetImportFftt();
        $('#btn-fermer-import-fftt').prop('disabled', false);
    });
}

// ── Afficher l'écran de sélection des JAs (limitrophes) ──────────────────────
function afficherSelectionJAs(jas, totalMembres, totalErreurs, logLines, depText) {
    const $list = $('#import-fftt-2b-list').empty();

    if (!jas.length) {
        $list.html('<div class="text-muted text-center py-4"><i class="bi bi-person-x fs-2 d-block mb-2"></i>Aucun JA trouvé dans ce département.</div>');
    } else {
        const gradeBg = { JA1: 'primary', JA2: 'success', JA3: 'warning text-dark' };
        jas.forEach((ja, i) => {
            const bg    = gradeBg[ja.grade] || 'secondary';
            const lieu  = [ja.cp, ja.ville].filter(Boolean).join(' ');
            const badge = ja.en_base
                ? '<span class="badge bg-secondary ms-auto" style="flex-shrink:0">Déjà en base</span>'
                : '<span class="badge bg-success ms-auto" style="flex-shrink:0">Nouveau</span>';
            $list.append(`
                <div class="d-flex align-items-center gap-2 py-1 border-bottom">
                    <input type="checkbox" class="form-check-input ja-sel-check" data-idx="${i}" ${!ja.en_base ? 'checked' : ''} style="flex-shrink:0;width:1.1em;height:1.1em">
                    <span class="badge bg-${bg}" style="flex-shrink:0">${ja.grade}</span>
                    <span class="fw-semibold small" style="min-width:0">${escHtml(ja.prenom)} ${escHtml(ja.nom)}</span>
                    <span class="text-muted small text-truncate">${escHtml(lieu)}</span>
                    ${badge}
                </div>
            `);
        });
    }

    const sousTitre = `${jas.length} JA(s) trouvé(s) dans <strong>${escHtml(depText)}</strong> — ${totalMembres} membres vérifiés` +
        (totalErreurs ? ` — <span class="text-danger">${totalErreurs} erreur(s)</span>` : '');
    $('#import-fftt-2b-sous-titre').html(sousTitre);
    $('#import-fftt-2b-erreurs').hide();
    majNbSel();

    $('#import-fftt-step2b').show();
}

function majNbSel() {
    const n = $('.ja-sel-check:checked').length;
    $('#import-fftt-2b-nb-sel').text(n > 0 ? `${n} JA(s) sélectionné(s)` : 'Aucune sélection');
}

$(document).on('change', '.ja-sel-check', majNbSel);

$('#btn-2b-tout-cocher').on('click', function () {
    $('.ja-sel-check').prop('checked', true);
    majNbSel();
});
$('#btn-2b-tout-decocher').on('click', function () {
    $('.ja-sel-check').prop('checked', false);
    majNbSel();
});

$('#btn-2b-retour').on('click', function () {
    $('#import-fftt-step2b').hide();
    resetImportFftt();
});

$('#btn-2b-valider').on('click', function () {
    const selected = [];
    $('.ja-sel-check:checked').each(function () {
        const idx = parseInt($(this).data('idx'), 10);
        if (!isNaN(idx) && _scanJAs[idx]) selected.push(_scanJAs[idx]);
    });
    if (!selected.length) {
        nijacToast('Cochez au moins un JA à importer.', 'warning');
        return;
    }

    $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Import…');

    $.post(`${JUGEARBITRE_BASE}/fftt/import-selected`, { licences: JSON.stringify(selected) }, function (r) {
        $('#btn-2b-valider').prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Valider l\'import');
        if (!r.ok) { nijacToast('Erreur : ' + r.msg, 'danger'); return; }
        $('#import-fftt-step2b').hide();
        $('#import-fftt-step3').show();
        $('#import-fftt-resume').html(
            `<i class="bi bi-check-circle-fill me-2"></i>` +
            `Import terminé — <strong>${r.nouveaux}</strong> nouveau(x) JA, ` +
            `<strong>${r.maj}</strong> mis à jour.`
        );
        $('#import-fftt-log-final').empty();
        chargerListe();
    }, 'json').fail(() => {
        $('#btn-2b-valider').prop('disabled', false).html('<i class="bi bi-check-lg me-1"></i>Valider l\'import');
        nijacToast('Erreur réseau lors de l\'import.', 'danger');
    });
});

$('#btn-lancer-import-fftt').on('click', function () {
    const dep = $('#import-fftt-dept').val();
    if (!dep) { nijacToast('Sélectionnez un département.', 'warning'); return; }

    const depText  = $('#import-fftt-dept option:selected').text().trim();
    const modeScan = !DEPT_ACTIFS_CODES.includes(dep);
    lancerProgressionClubs(dep, depText, modeScan);
});
<?php endif; ?>

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé en fin de page, donc pas encore
// défini si on l'appelait ici de façon synchrone.
let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-ja thead th[data-field]', 'field', sortState, renderGrille);
});

// ── Affichage / masquage des colonnes (mémorisé dans le navigateur) ──────────
const LS_COLONNES = 'nijac_en11_colonnes_cachees';
// Colonnes masquées tant que l'utilisateur n'a rien choisi (toutes restent
// activables depuis le menu « Colonnes »).
const COLONNES_CACHEES_DEFAUT = ['grade', 'date_validation_fftt', 'defiscalisation',
    'nationale', 'num_compte_ebp', 'arbitre_autres_depts', 'depts_arbitrage'];
let colonnesCachees;
try {
    const brut = localStorage.getItem(LS_COLONNES);
    colonnesCachees = new Set(brut !== null ? JSON.parse(brut) : COLONNES_CACHEES_DEFAUT);
} catch (e) {
    colonnesCachees = new Set(COLONNES_CACHEES_DEFAUT);
}

function appliquerColonnesCachees() {
    document.querySelectorAll('#tbl-ja [data-field]').forEach(el => {
        el.style.display = colonnesCachees.has(el.getAttribute('data-field')) ? 'none' : '';
    });
}

function construireMenuColonnes() {
    const $box = $('#menu-colonnes-list').empty();
    document.querySelectorAll('#tbl-ja thead th[data-field]').forEach(th => {
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

// ── Filtre département ────────────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
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
    renderGrille();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Toggles filtres ───────────────────────────────────────────────────────────
$('#btn-tous').on('click', function () {
    // Réinitialise tous les filtres
    filtreActif = false; filtreErreursCp = false;
    $('#btn-tous').addClass('active');
    $('#btn-actifs, #btn-erreurs-cp').removeClass('active');
    renderGrille();
});
$('#btn-actifs').on('click', function () {
    filtreActif = !filtreActif;
    $(this).toggleClass('active', filtreActif);
    // Si aucun filtre actif, revenir à "Tous"
    if (!filtreActif && !filtreErreursCp) $('#btn-tous').addClass('active');
    else $('#btn-tous').removeClass('active');
    renderGrille();
});
$('#btn-erreurs-cp').on('click', function () {
    filtreErreursCp = !filtreErreursCp;
    $(this).toggleClass('active', filtreErreursCp);
    if (!filtreActif && !filtreErreursCp) $('#btn-tous').addClass('active');
    else $('#btn-tous').removeClass('active');
    renderGrille();
});

// ── Bouton "Ouvrir la page de disponibilité du JA" ───────────────────────────
$(document).on('click', '.btn-lien-dispo', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    $.getJSON(`${DISPONIBILITE_JA_BASE}/token`, { id: id }, function (r) {
        if (!r.ok) { nijacToast('Erreur lors de la génération du lien.', 'danger'); return; }
        window.open(r.url, '_blank');
    });
});

// ── Bouton "Envoyer la demande de mise à jour d'adresse" ─────────────────────
$(document).on('click', '.btn-lien-adresse', function (e) {
    e.stopPropagation();
    const id    = $(this).data('id');
    const nom   = $(this).data('nom')   || `JA #${id}`;
    const email = $(this).data('email') || '';

    if (!email) {
        nijacToast(`${nom} n'a pas d'adresse email — impossible d'envoyer le message.`, 'warning');
        return;
    }

    nijacConfirm(
        `Envoyer le message de demande d'adresse à ${nom} ?`,
        function () {
            $.post('<?= site_url('adresse-ja/envoyer-demande-adresse') ?>', { id_ja: id }, function (r) {
                if (r.ok) {
                    nijacToast(`Message envoyé à ${r.nom}.`, 'success');
                } else {
                    nijacToast('Erreur : ' + (r.err || 'inconnue'), 'danger');
                }
            }, 'json').fail(function () {
                nijacToast('Erreur réseau.', 'danger');
            });
        },
        null,
        { type: 'question', confirmLabel: 'Envoyer', cancelLabel: 'Annuler' }
    );
});

// ── Modale Créer/Modifier un JA ───────────────────────────────────────────────
let njaIdLaPoste = null;
let njaEditId    = null; // null = création, sinon Id_JA en cours de modification

function ouvrirModaleJa(record) {
    $('#form-nouveau-ja')[0].reset();
    njaIdLaPoste = null;
    $('#nja-laposte-msg').text('').css('color', '');
    $('#nja-suggestions').hide();
    $('#nja-suggestions-list').empty();
    njaSyncArbVoisins();

    if (record) {
        njaEditId = record.id;
        $('#modal-nouveau-ja-titre').html('<i class="bi bi-pencil-fill me-2"></i>Modifier le Juge-Arbitre');
        $('#btn-enregistrer-ja').html('<i class="bi bi-check-lg me-1"></i>Enregistrer');
        $('#nja-id-label').html('N° Licence');
        $('#nja-id').val(record.id).prop('disabled', true);
        $('#nja-grade').val(record.grade || '');
        $('#nja-nom').val(record.nom || '');
        $('#nja-prenom').val(record.prenom || '');
        $('#nja-email').val(record.email || '');
        $('#nja-telephone').val(record.telephone || '');
        $('#nja-cp').val(record.cp || '');
        $('#nja-ville').val(record.ville || '');
        $('#nja-cpte-ebp').val(record.num_compte_ebp || '');
        $('#nja-actif').prop('checked', !!record.actif);
        $('#nja-defisc').prop('checked', !!record.defiscalisation);
        $('#nja-nationale').prop('checked', !!record.nationale);
        $('#nja-arb-voisins').prop('checked', !!record.arbitre_autres_depts);
        const arbSel = new Set((record.depts_arbitrage || '').split(',').filter(Boolean));
        $('.nja-arb-dept').each(function () { $(this).prop('checked', arbSel.has(this.value)); });
        njaSyncArbVoisins();
        njaIdLaPoste = record.id_laposte ?? null;
        if (njaIdLaPoste && (record.cp || record.ville)) {
            $('#nja-laposte-msg').text(`✓ ${record.cp} ${record.ville}`).css('color', '#065f46');
        }
        $('#nja-dept').val(record.codedept || '');
        njaChargerClubs('', record.id_club || '');
    } else {
        njaEditId = null;
        $('#modal-nouveau-ja-titre').html('<i class="bi bi-person-plus-fill me-2"></i>Créer un nouveau Juge-Arbitre');
        $('#btn-enregistrer-ja').html('<i class="bi bi-check-lg me-1"></i>Créer le JA');
        $('#nja-id-label').html('N° Licence <span class="text-danger">*</span>');
        $('#nja-id').val('').prop('disabled', false);
        $('#nja-actif').prop('checked', true);
        $('#nja-id-club').html('<option value="">— Sélectionnez d\'abord un département —</option>').prop('disabled', false);
    }

    new bootstrap.Modal('#modal-nouveau-ja').show();
}

$('#btn-nouveau-ja').on('click', function () { ouvrirModaleJa(null); });

// Recherche laposte dans la modale
function njaRechercherLaPoste() {
    const cp    = $('#nja-cp').val().trim();
    const ville = $('#nja-ville').val().trim();
    if (cp === '' && ville === '') { njaIdLaPoste = null; return; }

    $.post(`${LAPOSTE_BASE}/recherche-laposte`, { cp, ville }, function (res) {
        $('#nja-suggestions').hide();
        $('#nja-suggestions-list').empty();
        if (!res.ok && !res.multi) {
            njaIdLaPoste = null;
            $('#nja-laposte-msg').text('Commune non trouvée.').css('color', '#c00');
            return;
        }
        if (res.multi) {
            $('#nja-laposte-msg').text('').css('color', '');
            const $list = $('#nja-suggestions-list').empty();
            res.suggestions.forEach(s => {
                $('<button>').addClass('btn btn-sm btn-outline-primary')
                    .text(`${s.cp} ${s.ville}`)
                    .on('click', function () {
                        njaIdLaPoste = s.id_laposte;
                        $('#nja-cp').val(s.cp);
                        $('#nja-ville').val(s.ville);
                        $('#nja-laposte-msg').text(`✓ ${s.cp} ${s.ville}`).css('color', '#065f46');
                        $('#nja-suggestions').hide();
                    })
                    .appendTo($list);
            });
            $('#nja-suggestions').show();
            return;
        }
        njaIdLaPoste = res.id_laposte;
        $('#nja-cp').val(res.cp);
        $('#nja-ville').val(res.ville);
        $('#nja-laposte-msg').text(`✓ ${res.cp} ${res.ville}`).css('color', '#065f46');
    }, 'json').fail(() => { njaIdLaPoste = null; $('#nja-laposte-msg').text('Erreur réseau.').css('color', '#c00'); });
}

// Chargement des clubs selon le département sélectionné
function njaChargerClubs(dept, selectionner) {
    const $sel = $('#nja-id-club');
    $sel.html('<option value="">Chargement…</option>').prop('disabled', true);
    $.get(`${JUGEARBITRE_BASE}/clubs`, { dept }, function (res) {
        $sel.prop('disabled', false);
        if (!res.ok || !res.clubs.length) {
            $sel.html('<option value="">— Aucun club trouvé —</option>');
            return;
        }
        let opts = '<option value="">— Choisir un club —</option>';
        res.clubs.forEach(c => {
            opts += `<option value="${c.Id_Club}">${c.Id_Club} — ${$('<span>').text(c.Nom).html()}</option>`;
        });
        $sel.html(opts);
        if (selectionner) $sel.val(selectionner);
    }, 'json').fail(() => {
        $sel.prop('disabled', false).html('<option value="">— Erreur chargement —</option>');
    });
}

$('#nja-dept').on('change', function () {
    njaChargerClubs($(this).val());
});

// Cartouche « arbitre dans les départements voisins » (même case que EN22)
function njaSyncArbVoisins() {
    const on = $('#nja-arb-voisins').is(':checked');
    $('#nja-arb-voisins-depts').toggleClass('d-none', !on);
    if (!on) $('.nja-arb-dept').prop('checked', false);
}
$('#nja-arb-voisins').on('change', njaSyncArbVoisins);

$('#nja-cp, #nja-ville').on('blur', function () { njaRechercherLaPoste(); });
$('#nja-nom').on('input', function () { $(this).val($(this).val().toUpperCase()); });

// Enregistrer
$('#btn-enregistrer-ja').on('click', function () {
    const grade  = $('#nja-grade').val().trim();
    const nom    = $('#nja-nom').val().trim().toUpperCase();
    const prenom = $('#nja-prenom').val().trim();

    if (!grade || !nom || !prenom) {
        toast('Grade, Nom et Prénom sont obligatoires.', false);
        return;
    }

    let idJa = njaEditId;
    if (!idJa) {
        idJa = parseInt($('#nja-id').val(), 10);
        if (!idJa || idJa <= 0) {
            toast('Le N° JA (numéro de licence FFTT) est obligatoire à la création.', false);
            return;
        }
    }

    const record = {
        id:              idJa,
        grade,
        nom,
        prenom,
        email:           $('#nja-email').val().trim() || null,
        telephone:       $('#nja-telephone').val().trim() || null,
        id_club:         $('#nja-id-club').val() || null,
        dept:            $('#nja-dept').val() || null,
        cp:              $('#nja-cp').val().trim() || null,
        ville:           $('#nja-ville').val().trim() || null,
        id_laposte:      njaIdLaPoste,
        num_compte_ebp:  $('#nja-cpte-ebp').val().trim() || null,
        actif:           $('#nja-actif').is(':checked') ? 1 : 0,
        defiscalisation: $('#nja-defisc').is(':checked') ? 1 : 0,
        nationale:       $('#nja-nationale').is(':checked') ? 1 : 0,
    };

    spinner(true);
    $.post(`${JUGEARBITRE_BASE}/maj-bdd`, { lignes: JSON.stringify([record]) }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) {
            // Préférence « arbitre dans les départements voisins » : endpoint
            // partagé avec EN22 (gate session/token — l'admin connecté passe).
            $.post('<?= site_url('disponibilite-ja/sauvegarder-arbitrage-voisins') ?>', {
                id_ja:        idJa,
                actif:        $('#nja-arb-voisins').is(':checked') ? 1 : 0,
                departements: $('.nja-arb-dept:checked').map(function () { return this.value; }).get(),
            }, function (r) {
                if (!r.ok) toast('JA enregistré, mais préférence départements voisins non sauvée : ' + r.err, false);
            }, 'json');
            bootstrap.Modal.getInstance('#modal-nouveau-ja')?.hide();
            chargerListe();
        }
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Import CSV numéros de compte EBP ─────────────────────────────────────────
$('#btn-import-ebp').on('click', () => $('#file-input-ebp').val('').trigger('click'));

$('#file-input-ebp').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        const text = e.target.result;
        // Parsing CSV : séparateur virgule, ignorer lignes vides
        const lignesCsv = text.split(/\r?\n/)
            .map(l => l.trim())
            .filter(Boolean)
            .map((l, i) => {
                const idx = l.indexOf(';');
                if (idx === -1) return { num: l.trim(), nom: '', _src: l };
                return {
                    num: l.substring(0, idx).trim(),
                    nom: l.substring(idx + 1).trim().replace(/^"|"$/g, ''),
                    _src: l,
                };
            });

        if (!lignesCsv.length) {
            nijacToast('Fichier CSV vide ou illisible.', 'warning');
            return;
        }

        spinner(true);
        $.post(`${JUGEARBITRE_BASE}/import-csv-ebp`, { lignes: JSON.stringify(lignesCsv) }, function (r) {
            spinner(false);
            if (!r.ok) { nijacToast('Erreur : ' + (r.msg || 'inconnue'), 'danger'); return; }

            // Résumé
            $('#ebp-resume').html(
                `<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>${r.maj} compte(s) mis à jour.</span>` +
                (r.echecs.length ? ` &nbsp;<span class="text-danger">${r.echecs.length} ligne(s) non traitée(s).</span>` : '')
            );

            // Tableau des échecs
            const $tbody = $('#ebp-echecs-body').empty();
            if (r.echecs.length) {
                r.echecs.forEach(ec => {
                    $tbody.append(`<tr><td>${ec.ligne}</td><td>${$('<span>').text(ec.texte).html()}</td><td class="text-danger">${$('<span>').text(ec.raison).html()}</td></tr>`);
                });
                $('#ebp-echecs-bloc').show();

                // Rapport CSV téléchargeable
                $('#btn-ebp-telecharger').off('click').on('click', function () {
                    const csv = 'Ligne,Nom / Prénom,Raison\n' +
                        r.echecs.map(ec => `${ec.ligne},"${ec.texte.replace(/"/g, '""')}","${ec.raison.replace(/"/g, '""')}"`).join('\n');
                    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
                    const url  = URL.createObjectURL(blob);
                    const a    = document.createElement('a');
                    a.href     = url;
                    a.download = 'rapport_import_ebp.csv';
                    a.click();
                    URL.revokeObjectURL(url);
                });
            } else {
                $('#ebp-echecs-bloc').hide();
            }

            new bootstrap.Modal(document.getElementById('modal-import-ebp')).show();
            if (r.maj > 0) chargerListe();
        }, 'json').fail(() => { spinner(false); nijacToast('Erreur réseau.', 'danger'); });
    };
    reader.readAsText(file, 'UTF-8');
});

<?php if ($isAdmin): ?>
// ── Importer CSV FFTT (102_*.csv) ─────────────────────────────────────────────
// Ouvre la popup d'aide (où trouver le fichier 102 sur le site FFTT) ; c'est
// elle qui déclenche ensuite le sélecteur de fichier de cette fenêtre.
$('#btn-importer').on('click', function () {
    window.open('<?= base_url('asset/aide/import-102.html') ?>', 'aideImport102', 'width=640,height=680,resizable=yes,scrollbars=yes');
});

(function () {
    let _modalImportExcel = null;
    function getModalImportExcel() {
        if (!_modalImportExcel) _modalImportExcel = new bootstrap.Modal(document.getElementById('modal-import-excel'));
        return _modalImportExcel;
    }

    function xlsxLog(html, cls) {
        const $d = $('<div>').html(html);
        if (cls) $d.addClass(cls);
        $('#xlsx-log').append($d).scrollTop(9999);
    }

    function xlsxProgress(pct, label) {
        $('#xlsx-progress-bar').css('width', pct + '%');
        $('#xlsx-progress-pct').text(pct + ' %');
        if (label !== undefined) $('#xlsx-progress-label').text(label);
    }

    function importerFichier102(file) {
        if (!file) return;

        // Réinitialiser la modale
        $('#xlsx-log').empty();
        $('#xlsx-footer').hide();
        $('#xlsx-progress-bar')
            .css('width', '0%')
            .removeClass('bg-danger')
            .addClass('bg-success progress-bar-animated progress-bar-striped');
        xlsxProgress(5, 'Envoi du fichier…');
        getModalImportExcel().show();
        xlsxLog(`<i class="bi bi-cloud-upload"></i> Envoi de <strong>${file.name}</strong> (${(file.size/1024).toFixed(0)} Ko)…`);

        const fd = new FormData();
        fd.append('fichier', file);

        $.ajax({
            url: `${JUGEARBITRE_BASE}/import-excel`, type: 'POST',
            data: fd, processData: false, contentType: false, dataType: 'json',

            xhr() {
                const xhr = $.ajaxSettings.xhr();
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        const pct = Math.round(e.loaded / e.total * 30);
                        xlsxProgress(5 + pct, pct < 30 ? 'Envoi du fichier…' : 'Analyse du fichier CSV…');
                    }
                });
                return xhr;
            },

            success(res) {
                if (!res.ok) {
                    xlsxLog(`<i class="bi bi-x-circle-fill text-danger"></i> ${res.msg}`, 'text-danger fw-semibold');
                    xlsxProgress(100, 'Erreur');
                    $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                    $('#xlsx-footer').show();
                    return;
                }

                const rows = res.data;
                xlsxProgress(40, `${rows.length} JA trouvés — résolution des communes…`);
                xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${rows.length}</strong> Juge(s)-Arbitre(s) trouvé(s) dans le fichier.`);
                if (res.clubs_crees && res.clubs_crees.length) {
                    const noms = res.clubs_crees.map(c => c.nom).join(', ');
                    xlsxLog(`<i class="bi bi-building-fill-add text-success"></i> <strong>${res.clubs_crees.length}</strong> club(s) créé(s) automatiquement via l'API FFTT : ${noms}`);
                }

                // Initialiser le tableau de lignes (id_laposte = null pour l'instant)
                lignes = rows.map((r, i) => Object.assign({}, r, {
                    _idx:            i,
                    actif:           +r.actif,
                    defiscalisation: r.defiscalisation != null ? +r.defiscalisation : 0,
                    nationale:       r.nationale       != null ? +r.nationale       : 0,
                    cp:              r.cp    ?? '',
                    ville:           r.ville ?? '',
                    id_laposte:      null,
                }));

                // Collecter les paires CP/Ville uniques à résoudre
                const pairesUniques = {};
                lignes.forEach(l => {
                    if (l.cp || l.ville) {
                        const cle = (l.cp || '') + '|' + (l.ville || '');
                        if (!pairesUniques[cle]) pairesUniques[cle] = { cp: l.cp, ville: l.ville, id_laposte: null, resolved: false };
                    }
                });
                const paires = Object.entries(pairesUniques); // [[cle, obj], ...]
                const total  = paires.length;

                if (total === 0) {
                    finaliserImportExcel(0, 0, 0);
                    return;
                }

                xlsxLog(`<i class="bi bi-geo-alt"></i> Résolution de <strong>${total}</strong> commune(s) unique(s)…`);
                let done = 0, resolues = 0, multiples = 0, inconnues = 0;

                function resoudreProchaine() {
                    if (done >= total) {
                        // Appliquer les résultats sur les lignes
                        lignes.forEach(l => {
                            const cle = (l.cp || '') + '|' + (l.ville || '');
                            const r = pairesUniques[cle];
                            if (r && r.resolved) {
                                l.id_laposte = r.id_laposte;
                                l.cp         = r.cp;
                                l.ville      = r.ville;
                            }
                        });
                        finaliserImportExcel(resolues, multiples, inconnues);
                        return;
                    }

                    const pct = 40 + Math.round(done / total * 55);
                    xlsxProgress(pct, `Communes : ${done + 1} / ${total}`);

                    const [cle, obj] = paires[done];
                    $.post(`${LAPOSTE_BASE}/recherche-laposte`,
                        { cp: obj.cp, ville: obj.ville },
                        function (r) {
                            if (r.ok && !r.multi) {
                                obj.id_laposte = r.id_laposte;
                                obj.cp         = r.cp;
                                obj.ville      = r.ville;
                                obj.resolved   = true;
                                resolues++;
                            } else if (r.multi) {
                                // Plusieurs communes — laisser null, l'utilisateur corrigera via la modale
                                obj.resolved = true;
                                multiples++;
                                xlsxLog(`<span class="text-warning">⚠ Plusieurs communes pour CP <strong>${obj.cp}</strong> — à préciser dans la grille.</span>`);
                            } else {
                                inconnues++;
                                xlsxLog(`<span class="text-danger">✗ Commune non trouvée : ${obj.cp} ${obj.ville}</span>`);
                            }
                            done++;
                            resoudreProchaine();
                        }, 'json'
                    ).fail(() => { inconnues++; done++; resoudreProchaine(); });
                }

                resoudreProchaine();
            },

            error() {
                xlsxLog('<i class="bi bi-x-circle-fill text-danger"></i> Erreur réseau lors de l\'import.', 'fw-semibold');
                xlsxProgress(100, 'Erreur réseau');
                $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                $('#xlsx-footer').show();
            }
        });

        function finaliserImportExcel(resolues, multiples, inconnues) {
            xlsxLog(`<hr class="my-1">`);
            xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${resolues}</strong> commune(s) résolue(s).`);
            if (multiples) xlsxLog(`<span class="text-warning"><i class="bi bi-exclamation-triangle-fill"></i> <strong>${multiples}</strong> CP avec plusieurs communes — à préciser (clic CP/Ville dans la grille).</span>`);
            if (inconnues) xlsxLog(`<span class="text-danger"><i class="bi bi-x-circle-fill"></i> <strong>${inconnues}</strong> commune(s) introuvable(s) — à corriger dans la grille.</span>`);
            xlsxLog(`<hr class="my-1"><i class="bi bi-person-dash-fill text-primary"></i> Passage de tous les JA à Inactif avant import…`);
            xlsxProgress(95, 'Réinitialisation des JA…');

            $.post(`${JUGEARBITRE_BASE}/fftt/reset-actif-tous`, {}, function (resReset) {
                if (resReset.ok) {
                    xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${resReset.maj}</strong> JA passé(s) à Inactif.`);
                } else {
                    xlsxLog(`<span class="text-warning"><i class="bi bi-exclamation-triangle-fill"></i> ${resReset.msg || resReset.err || 'Échec de la réinitialisation.'}</span>`);
                }
                enregistrerImportExcel();
            }, 'json').fail(() => {
                xlsxLog(`<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Erreur réseau lors de la réinitialisation — import annulé.</span>`);
                xlsxProgress(100, 'Erreur');
                $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                $('#xlsx-footer').show();
            });
        }

        function enregistrerImportExcel() {
            xlsxLog(`<hr class="my-1"><i class="bi bi-database-fill-up text-primary"></i> Enregistrement en base de données…`);
            xlsxProgress(98, 'Enregistrement en base…');

            $.post(`${JUGEARBITRE_BASE}/maj-bdd`, { lignes: JSON.stringify(lignes) }, function (res) {
                xlsxProgress(100, 'Terminé');
                $('#xlsx-progress-bar').removeClass('progress-bar-animated progress-bar-striped');
                if (res.ok) {
                    xlsxLog(`<i class="bi bi-check-circle-fill text-success"></i> <strong>${res.msg}</strong>`);
                } else {
                    xlsxLog(`<span class="text-warning"><i class="bi bi-exclamation-triangle-fill"></i> ${res.msg}</span>`);
                }
                xlsxLog(`<strong class="text-primary">Cliquez « OK » pour afficher la grille à jour.</strong>`);
                renderGrille();
                setStatus(`Import terminé. ${res.msg}`);
                $('#xlsx-footer').show();
                chargerListe();
            }, 'json').fail(() => {
                xlsxProgress(100, 'Erreur réseau');
                xlsxLog(`<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Erreur réseau lors de l'enregistrement.</span>`);
                $('#xlsx-progress-bar').removeClass('bg-success progress-bar-animated').addClass('bg-danger');
                xlsxLog(`<strong>Cliquez « OK » pour afficher la grille — utilisez « Mettre à jour la BDD » pour réessayer.</strong>`);
                renderGrille();
                $('#xlsx-footer').show();
            });
        }

        $('#btn-xlsx-ok').off('click').on('click', function () {
            nijacToast(`${lignes.length} JA importé(s) depuis le CSV.`);
        });
    }

    $('#file-input').on('change', function () { importerFichier102(this.files[0]); });
    // Exposée pour la popup d'aide (asset/aide/import-102.html), qui sélectionne
    // son propre fichier local et le transmet directement à cette fenêtre.
    window.importerFichier102 = importerFichier102;
}());
<?php endif; ?>

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
