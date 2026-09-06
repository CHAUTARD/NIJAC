<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Nettoyage / Restauration (EA85)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
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

        /* ── Mise en page : onglets + rangées de cartes ── */
        #main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1.5rem 1rem;
        }
        #e016-tabs {
            margin-bottom: -1px;
            border-bottom: none;
            gap: .3rem;
        }
        #e016-tabs .nav-link {
            font-weight: 700;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 10px 10px 0 0;
            padding: .5rem 1.4rem;
        }
        /* Onglet Sauvegarde : vert pastel */
        #e016-tabs .nav-sauvegarde          { color: #065f46; background: #d1fae5; }
        #e016-tabs .nav-sauvegarde:hover    { color: #065f46; }
        #e016-tabs .nav-sauvegarde.active   { background: #ecfdf5; border-color: #6ee7b7; color: #065f46; }
        /* Onglet Restauration : bleu pastel */
        #e016-tabs .nav-restauration        { color: #1e3a8a; background: #dbeafe; }
        #e016-tabs .nav-restauration:hover  { color: #1e3a8a; }
        #e016-tabs .nav-restauration.active { background: #eff6ff; border-color: #93c5fd; color: #1e3a8a; }
        /* Onglet Fin de phase : ambre */
        #e016-tabs .nav-fin-phase        { color: #92400e; background: #fef3c7; }
        #e016-tabs .nav-fin-phase:hover  { color: #92400e; }
        #e016-tabs .nav-fin-phase.active { background: #fffbeb; border-color: #fcd34d; color: #92400e; }
        /* Onglet Fin de saison : violet */
        #e016-tabs .nav-fin-saison        { color: #5b21b6; background: #ede9fe; }
        #e016-tabs .nav-fin-saison:hover  { color: #5b21b6; }
        #e016-tabs .nav-fin-saison.active { background: #f5f3ff; border-color: #c4b5fd; color: #5b21b6; }

        .tab-content { width: 100%; }
        .cards-row {
            align-items: stretch;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
            padding: 1.75rem 1.5rem;
            border-radius: 0 12px 12px 12px;
        }
        .tab-content > .tab-pane.cards-row.active { display: flex; }
        #pane-sauvegarde   { background: #ecfdf5; border: 1px solid #6ee7b7; }
        #pane-restauration { background: #eff6ff; border: 1px solid #93c5fd; }
        #pane-fin-phase    { background: #fffbeb; border: 1px solid #fcd34d; }
        #pane-fin-saison   { background: #f5f3ff; border: 1px solid #c4b5fd; }

        /* ── Cartes récapitulatives (Fin de phase / Fin de saison) ── */
        .op-card.card-recap { width: 720px; }
        .card-recap .card-head    { background: #f3f4f6; border-color: #9ca3af; }
        .card-recap .card-head h2 { color: #1f2937; }
        .recap-intro { font-size: .84rem; color: #6b7280; margin: 0 0 1rem; }
        .recap-list  { margin: 0; padding-left: 1.4rem; font-size: .86rem; color: #374151; }
        .recap-list li   { margin-bottom: .55rem; }
        .recap-list code { background: #eef2ff; padding: .05rem .35rem; border-radius: 4px; font-size: .8rem; }

        /* ── Carte commune ── */
        .op-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 18px rgba(0,0,0,.12);
            width: 560px;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .op-card .card-body-custom { flex: 1; }

        /* ── Bloc en-tête de carte ── */
        .card-head {
            padding: 1.1rem 1.5rem .9rem;
            border-bottom: 3px solid transparent;
        }
        .card-head h2 {
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0 0 .6rem;
        }
        .card-head .warn-icon { margin-right: .5rem; }

        /* ── Nettoyage : fond orange ── */
        .card-clean .card-head   { background: #fff3cd; border-color: #f59e0b; }
        .card-clean .card-head h2 { color: #92400e; }
        .warn-list {
            margin: 0;
            padding-left: 1.2rem;
            font-size: .85rem;
            color: #78350f;
        }
        .warn-list li { margin-bottom: .25rem; }

        /* ── Restauration : fond bleu clair ── */
        .card-restore .card-head  { background: #dbeafe; border-color: #3b82f6; }
        .card-restore .card-head h2 { color: #1e3a8a; }
        .info-list {
            margin: 0;
            padding-left: 1.2rem;
            font-size: .85rem;
            color: #1e3a8a;
        }
        .info-list li { margin-bottom: .25rem; }

        /* ── Sauvegarde totale : fond vert ── */
        .card-full .card-head   { background: #d1fae5; border-color: #10b981; }
        .card-full .card-head h2 { color: #065f46; }
        .full-list {
            margin: 0;
            padding-left: 1.2rem;
            font-size: .85rem;
            color: #065f46;
        }
        .full-list li { margin-bottom: .25rem; }
        .card-full .tables-badge span {
            background: #a7f3d0;
            border-color: #10b981;
            color: #065f46;
        }
        .btn-full { background: #059669; color: #fff; }
        .btn-full:hover:not(:disabled) { background: #047857; }

        /* ── Badges tables ── */
        .tables-badge {
            display: flex;
            flex-wrap: wrap;
            gap: .35rem;
            margin-top: .75rem;
        }
        .tables-badge span {
            background: #fde68a;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            padding: .12rem .5rem;
            font-size: .78rem;
            font-weight: 700;
            color: #78350f;
            font-family: monospace;
        }
        .card-restore .tables-badge span {
            background: #bfdbfe;
            border-color: #3b82f6;
            color: #1e3a8a;
        }

        /* ── Corps de carte ── */
        .card-body-custom {
            padding: 1.25rem 1.5rem 1.5rem;
        }
        .card-body-custom h3 {
            font-size: .9rem;
            font-weight: 700;
            color: #1a3a6b;
            margin-bottom: .9rem;
        }

        /* ── Champ mot de passe ── */
        .pwd-group { margin-bottom: 1.1rem; }
        .pwd-group label { font-size: .82rem; font-weight: 600; color: #374151; margin-bottom: .25rem; display: block; }
        .pwd-input {
            flex: 1;
            min-width: 0;
            border: 2px solid #c8d4e8;
            border-radius: 6px 0 0 6px;
            padding: .4rem .7rem;
            font-size: .88rem;
            transition: border-color .2s;
        }
        .pwd-input:focus      { outline: none; border-color: #1a3a6b; }
        .pwd-input.is-invalid { border-color: #dc2626; }
        .pwd-msg { font-size: .8rem; margin-top: .3rem; min-height: 16px; }

        /* ── Sélecteur de fichier ── */
        #select-fichier {
            width: 100%;
            border: 2px solid #c8d4e8;
            border-radius: 6px;
            padding: .4rem .7rem;
            font-size: .85rem;
            margin-bottom: 1rem;
            background: #fff;
        }
        #select-fichier:focus { outline: none; border-color: #1a3a6b; }

        .fichier-meta {
            font-size: .78rem;
            color: #6b7280;
            margin-bottom: .9rem;
            min-height: 18px;
        }

        /* ── Boutons d'action ── */
        .btn-action {
            width: 100%;
            padding: .6rem;
            font-size: .9rem;
            font-weight: 700;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background .2s;
        }
        .btn-action:disabled { opacity: .5; cursor: default; }

        .btn-clean   { background: #dc2626; color: #fff; }
        .btn-clean:hover:not(:disabled)   { background: #b91c1c; }

        .btn-restore { background: #2563eb; color: #fff; }
        .btn-restore:hover:not(:disabled) { background: #1d4ed8; }

        /* ── Zone résultat ── */
        .result-zone { display: none; padding: 1rem 1.5rem 1.25rem; border-top: 1px solid #e5e7eb; }
        .result-zone.show { display: block; }
        .result-ok {
            background: #d1fae5; border: 1px solid #6ee7b7;
            border-radius: 6px; padding: .9rem 1.1rem;
            color: #065f46; font-size: .85rem;
        }
        .result-err {
            background: #fee2e2; border: 1px solid #fca5a5;
            border-radius: 6px; padding: .9rem 1.1rem;
            color: #991b1b; font-size: .85rem;
        }

        /* ── Bandeau mot de passe ── */
        #pwd-banner {
            background: #f0f4fa;
            border-bottom: 2px solid #c8d4e8;
            padding: .45rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .75rem;
            flex-shrink: 0;
            font-size: .88rem;
        }
        #pwd-banner label {
            font-weight: 600;
            color: #1a3a6b;
            white-space: nowrap;
            margin: 0;
        }
        #pwd-banner .pwd-wrap {
            display: flex;
            align-items: center;
            gap: 0;
        }
        #pwd-global {
            border: 2px solid #c8d4e8;
            border-right: none;
            border-radius: 6px 0 0 6px;
            padding: .3rem .7rem;
            font-size: .88rem;
            width: 220px;
            transition: border-color .2s;
        }
        #pwd-global:focus { outline: none; border-color: #1a3a6b; }
        #pwd-global.is-invalid { border-color: #dc2626; }
        #pwd-global.is-valid   { border-color: #16a34a; }
        #pwd-toggle-btn {
            border: 2px solid #c8d4e8;
            border-left: none;
            border-radius: 0 6px 6px 0;
            background: #fff;
            padding: 0 .6rem;
            cursor: pointer;
            transition: border-color .2s;
            display: flex;
            align-items: center;
            align-self: stretch;
        }
        #pwd-global.is-invalid ~ #pwd-toggle-btn { border-color: #dc2626; }
        #pwd-global.is-valid   ~ #pwd-toggle-btn { border-color: #16a34a; }
        #pwd-msg-global {
            font-size: .82rem;
            min-width: 180px;
        }

        /* ── Spinner overlay ── */

        /* ── Restauration totale : fond rouge foncé ── */
        .card-full-restore .card-head  { background: #fee2e2; border-color: #ef4444; }
        .card-full-restore .card-head h2 { color: #7f1d1d; }
        .btn-restore-total { background: #dc2626; color: #fff; }
        .btn-restore-total:hover:not(:disabled) { background: #b91c1c; }

        /* ── Restauration table unique ── */
        .card-table-restore .card-head  { background: #ede9fe; border-color: #7c3aed; }
        .card-table-restore .card-head h2 { color: #3b0764; }
        .btn-table-restore { background: #7c3aed; color: #fff; }
        .btn-table-restore:hover:not(:disabled) { background: #6d28d9; }

        /* ── Sauvegarde d'une table : fond turquoise ── */
        .card-table-save .card-head   { background: #ccfbf1; border-color: #14b8a6; }
        .card-table-save .card-head h2 { color: #115e59; }
        .save-list { margin: 0; padding-left: 1.2rem; font-size: .85rem; color: #115e59; }
        .save-list li { margin-bottom: .25rem; }
        .btn-table-save { background: #0d9488; color: #fff; }
        .btn-table-save:hover:not(:disabled) { background: #0f766e; }
        #select-save-table {
            width: 100%;
            border: 2px solid #c8d4e8;
            border-radius: 6px;
            padding: .4rem .7rem;
            font-size: .85rem;
            margin-bottom: .75rem;
            background: #fff;
        }
        #select-save-table:focus { outline: none; border-color: #14b8a6; }

        #select-table-fichier, #select-table-nom {
            width: 100%;
            border: 2px solid #c8d4e8;
            border-radius: 6px;
            padding: .4rem .7rem;
            font-size: .85rem;
            margin-bottom: .75rem;
            background: #fff;
        }
        #select-table-fichier:focus, #select-table-nom:focus { outline: none; border-color: #7c3aed; }

        /* ── Aucune sauvegarde ── */
        .no-backup {
            text-align: center;
            padding: 1.5rem;
            color: #6b7280;
            font-size: .85rem;
        }
        .no-backup i { font-size: 2rem; display: block; margin-bottom: .5rem; color: #d1d5db; }

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
    'phIcon' => 'database-fill-gear', 'phTitle' => 'Nettoyage / Restauration de phase', 'phCode' => 'EA85',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Bandeau mot de passe -->
<div id="pwd-banner">
    <label for="pwd-global"><i class="bi bi-key-fill me-1"></i>Mot de passe administrateur :</label>
    <div class="pwd-wrap">
        <input type="password" id="pwd-global" data-pwd-toggle="1" autocomplete="current-password" placeholder="Entrez votre mot de passe…">
        <button type="button" id="pwd-toggle-btn" tabindex="-1" title="Afficher / masquer le mot de passe">
            <span id="pwd-eye">👁️</span>
        </button>
    </div>
    <div id="pwd-msg-global"></div>
</div>

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<!-- ════════════════════════ CONTENU PRINCIPAL ════════════════════════ -->
<div id="main-content">

    <ul class="nav nav-tabs" id="e016-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link nav-sauvegarde active" data-bs-toggle="tab" data-bs-target="#pane-sauvegarde" type="button" role="tab">
                <i class="bi bi-database-fill-down me-1"></i>Sauvegarde
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link nav-restauration" data-bs-toggle="tab" data-bs-target="#pane-restauration" type="button" role="tab">
                <i class="bi bi-database-fill-up me-1"></i>Restauration
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link nav-fin-phase" data-bs-toggle="tab" data-bs-target="#pane-fin-phase" type="button" role="tab">
                <i class="bi bi-calendar2-check me-1"></i>Fin de phase
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link nav-fin-saison" data-bs-toggle="tab" data-bs-target="#pane-fin-saison" type="button" role="tab">
                <i class="bi bi-calendar2-x me-1"></i>Fin de saison
            </button>
        </li>
    </ul>

    <div class="tab-content">

    <div class="tab-pane fade show active cards-row" id="pane-sauvegarde">

    <!-- ── CARTE 1 : Nettoyage ── -->
    <div class="op-card card-clean">
        <div class="card-head">
            <h2>
                <i class="bi bi-exclamation-triangle-fill warn-icon"></i>
                Nettoyage — Nouvelle phase
            </h2>
            <ul class="warn-list">
                <li>Supprime <strong>définitivement</strong> les données de la phase en cours.</li>
                <li>Un fichier SQL est créé dans <code>/SQL/</code> avant toute suppression.</li>
                <li>Si la sauvegarde échoue, <strong>aucune suppression</strong> n'est effectuée.</li>
            </ul>
            <div class="tables-badge">
                <span>Competition_Regionale</span><span>Disponible</span><span>Equipe</span>
                <span>Equipe_Nationale</span><span>JA</span><span>Nomination</span><span>Rencontre</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-clean">

            <button id="btn-executer" class="btn-action btn-clean" disabled>
                <i class="bi bi-calendar2-plus-fill me-2"></i>Sauvegarder et démarrer nouvelle phase
            </button>
        </div>

        <div id="clean-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 3 : Sauvegarde totale ── -->
    <div class="op-card card-full">
        <div class="card-head">
            <h2>
                <i class="bi bi-database-fill-down warn-icon"></i>
                Sauvegarde totale de la base de données
            </h2>
            <ul class="full-list">
                <li>Exporte <strong>toutes les tables</strong> (structure + données) dans un fichier SQL.</li>
                <li>Le fichier est créé dans <code>/SQL/</code> sous la forme <code>Full_*.sql</code>.</li>
                <li>Aucune suppression n'est effectuée — opération <strong>non destructive</strong>.</li>
            </ul>
            <div class="tables-badge" id="full-tables-badge">
                <span>Toutes les tables</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-full">

            <button id="btn-full" class="btn-action btn-full" disabled>
                <i class="bi bi-database-fill-down me-2"></i>Sauvegarder toute la base de données
            </button>

            <!-- Liste des sauvegardes totales existantes -->
            <div id="full-liste-zone" style="margin-top:1.1rem;"></div>
        </div>

        <div id="full-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 3bis : Sauvegarde d'une table ── -->
    <div class="op-card card-table-save">
        <div class="card-head">
            <h2>
                <i class="bi bi-file-earmark-arrow-down warn-icon"></i>
                Sauvegarde d'une table
            </h2>
            <ul class="save-list">
                <li>Exporte <strong>une seule table</strong> (structure + données) dans un fichier SQL.</li>
                <li>Le fichier est créé dans <code>/SQL/</code> sous la forme <code>Table_&lt;nom&gt;_*.sql</code>.</li>
                <li>Aucune suppression n'est effectuée — opération <strong>non destructive</strong>.</li>
            </ul>
        </div>

        <div class="card-body-custom" id="section-table-save">
            <div style="margin-bottom:.8rem;">
                <label for="select-save-table" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                    <i class="bi bi-table me-1"></i>Table à sauvegarder
                </label>
                <select id="select-save-table">
                    <option value="">— Choisir une table —</option>
                    <?php foreach ($tablesBdd as $t): ?>
                    <option value="<?= esc($t['TABLE_NAME']) ?>">
                        <?= esc($t['TABLE_NAME']) ?><?= $t['TABLE_COMMENT'] !== '' ? ' — ' . esc($t['TABLE_COMMENT']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button id="btn-save-table" class="btn-action btn-table-save" disabled>
                <i class="bi bi-file-earmark-arrow-down me-2"></i>Sauvegarder cette table
            </button>
        </div>

        <div id="table-save-result" class="result-zone"></div>
    </div>

    </div><!-- /#pane-sauvegarde -->

    <div class="tab-pane fade cards-row" id="pane-restauration">

    <!-- ── CARTE 2 : Restauration d'une sauvegarde de phase ── -->
    <div class="op-card card-restore">
        <div class="card-head">
            <h2>
                <i class="bi bi-arrow-counterclockwise warn-icon"></i>
                Restauration d'une sauvegarde
            </h2>
            <ul class="info-list">
                <li>Sélectionnez un fichier de sauvegarde dans la liste ci-dessous.</li>
                <li>Les tables actuelles seront <strong>écrasées</strong> par les données du fichier.</li>
                <li>Opération irréversible — assurez-vous du fichier choisi.</li>
            </ul>
            <div class="tables-badge">
                <span>Competition_Regionale</span><span>Disponible</span><span>Equipe</span>
                <span>Equipe_Nationale</span><span>JA</span><span>Nomination</span><span>Rencontre</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-restore">

            <!-- Liste des sauvegardes -->
            <div id="restore-file-zone">
                <div class="no-backup" id="restore-loading">
                    <i class="bi bi-hourglass-split"></i>Chargement des sauvegardes…
                </div>
            </div>

            <!-- Formulaire (masqué jusqu'au chargement de la liste) -->
            <div id="restore-form" style="display:none">
                <div style="margin-bottom:.8rem;">
                    <label for="select-fichier" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-file-earmark-code me-1"></i>Fichier de sauvegarde
                    </label>
                    <select id="select-fichier"></select>
                    <div id="fichier-meta" class="fichier-meta"></div>
                </div>

                <button id="btn-restaurer" class="btn-action btn-restore" disabled>
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Restaurer ce fichier
                </button>
                <button id="btn-suppr-sauve" class="btn-action" style="display:none;background:#6b7280;color:#fff;margin-top:.5rem;font-size:.8rem;padding:.3rem .7rem;">
                    <i class="bi bi-trash me-1"></i>
                </button>
            </div>
        </div>

        <div id="restore-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 4 : Restauration totale ── -->
    <div class="op-card card-full-restore">
        <div class="card-head">
            <h2>
                <i class="bi bi-database-fill-up warn-icon"></i>
                Restauration totale de la base
            </h2>
            <ul class="warn-list" style="color:#7c2d12;">
                <li>Restaure <strong>toutes les tables</strong> (structure + données) depuis un fichier <code>Full_*.sql</code>.</li>
                <li>Chaque table est <strong>supprimée puis recréée</strong> — opération <strong>irréversible</strong>.</li>
                <li>Utilisez uniquement pour repartir d'une sauvegarde complète validée.</li>
            </ul>
            <div class="tables-badge" id="full-restore-tables-badge">
                <span style="background:#fecaca;border-color:#ef4444;color:#7f1d1d;">Toutes les tables</span>
            </div>
        </div>

        <div class="card-body-custom" id="section-full-restore">

            <div id="full-restore-file-zone">
                <div class="no-backup" id="full-restore-loading">
                    <i class="bi bi-hourglass-split"></i>Chargement des sauvegardes totales…
                </div>
            </div>

            <div id="full-restore-form" style="display:none">
                <div style="margin-bottom:.8rem;">
                    <label for="select-full-fichier" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-file-earmark-code me-1"></i>Fichier de sauvegarde totale
                    </label>
                    <select id="select-full-fichier" style="width:100%;border:2px solid #c8d4e8;border-radius:6px;padding:.4rem .7rem;font-size:.85rem;margin-bottom:1rem;background:#fff;"></select>
                    <div id="full-fichier-meta" class="fichier-meta"></div>
                </div>

                <button id="btn-restaurer-total" class="btn-action" style="background:#dc2626;color:#fff;" disabled>
                    <i class="bi bi-database-fill-up me-2"></i>Restaurer toute la base de données
                </button>
            </div>
        </div>

        <div id="full-restore-result" class="result-zone"></div>
    </div>

    <!-- ── CARTE 5 : Restauration d'une table depuis une sauvegarde totale ── -->
    <div class="op-card card-table-restore">
        <div class="card-head">
            <h2>
                <i class="bi bi-table warn-icon"></i>
                Restauration d'une table
            </h2>
            <ul class="info-list" style="color:#3b0764;">
                <li>Sélectionnez un fichier <code>Full_*.sql</code> ou <code>Table_*.sql</code>, puis la table à restaurer.</li>
                <li>Seule la table sélectionnée sera supprimée et recréée.</li>
                <li>Les autres tables ne sont pas affectées.</li>
            </ul>
        </div>

        <div class="card-body-custom" id="section-table-restore">

            <div id="table-restore-file-zone">
                <div class="no-backup" id="table-restore-loading">
                    <i class="bi bi-hourglass-split"></i>Chargement des sauvegardes totales…
                </div>
            </div>

            <div id="table-restore-form" style="display:none">
                <div style="margin-bottom:.25rem;">
                    <label for="select-table-fichier" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-file-earmark-code me-1"></i>Fichier de sauvegarde totale
                    </label>
                    <select id="select-table-fichier"></select>
                </div>

                <div id="table-select-zone">
                    <label for="select-table-nom" style="font-size:.82rem;font-weight:600;color:#374151;display:block;margin-bottom:.25rem;">
                        <i class="bi bi-table me-1"></i>Table à restaurer
                    </label>
                    <select id="select-table-nom">
                        <option value="">— Choisir une table —</option>
                        <?php foreach ($tablesBdd as $t): ?>
                        <option value="<?= esc($t['TABLE_NAME']) ?>">
                            <?= esc($t['TABLE_NAME']) ?><?= $t['TABLE_COMMENT'] !== '' ? ' — ' . esc($t['TABLE_COMMENT']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="table-restore-meta" class="fichier-meta"></div>
                </div>

                <button id="btn-restaurer-table" class="btn-action btn-table-restore" disabled>
                    <i class="bi bi-table me-2"></i>Restaurer cette table
                </button>
            </div>
        </div>

        <div id="table-restore-result" class="result-zone"></div>
    </div>

    </div><!-- /#pane-restauration -->

    <!-- ════════════════════ ONGLET FIN DE PHASE ════════════════════ -->
    <div class="tab-pane fade cards-row" id="pane-fin-phase">
    <div class="op-card card-recap">
        <div class="card-head">
            <h2><i class="bi bi-calendar2-check warn-icon"></i>Fin de phase — opérations à réaliser</h2>
            <p class="recap-intro">
                Ordre conseillé pour clôturer la phase en cours et démarrer la phase suivante
                (bascule phase&nbsp;1 → phase&nbsp;2). Rien n'est exécuté ici : ce n'est qu'un rappel.
            </p>
        </div>
        <div class="card-body-custom">
            <ol class="recap-list">
                <li><strong>Comptabilité</strong> — générer le journal comptable de la phase (<code>EN16</code>) et transmettre les pièces ; les indemnités JA sont soldées en fin de phase.</li>
                <li><strong>Sauvegarde totale</strong> — onglet « Sauvegarde » → « Sauvegarder toute la base de données » (fichier <code>Full_*.sql</code>, à conserver hors serveur).</li>
                <li><strong>Vérifier la configuration</strong> (<code>EA91</code>) — bornes <code>phase2_debut</code> / <code>phase2_fin</code> et saison courante.</li>
                <li><strong>Nettoyage de phase</strong> — onglet « Sauvegarde » → « Sauvegarder et démarrer nouvelle phase » : sauvegarde SQL puis vidage de <code>Disponible</code>, <code>Equipe</code>, <code>Equipe_Nationale</code>, <code>Rencontre</code>, <code>Nomination</code>, <code>Competition_Regionale</code> ; désactivation des JA (<code>Actif&nbsp;=&nbsp;0</code>, conservés) ; purge des <code>.xlsx</code> de <code>Importation/Rencontres</code>.</li>
                <li><strong>Ré-importer les équipes régionales</strong> (<code>EA92</code>) si la composition change pour la nouvelle phase.</li>
                <li><strong>Ré-importer les rencontres</strong> — régionales (<code>EA82</code>) puis nationales (<code>EA83</code>) ; recharger le calendrier régional (<code>EA84</code>) si nécessaire.</li>
                <li><strong>Réactiver les JA</strong> concernés (<code>EN11</code>) et vérifier grades / dates de validation FFTT.</li>
                <li><strong>Relancer le recueil des disponibilités</strong> (<code>EN13</code>) et l'envoi aux JA (<code>EN15</code>).</li>
            </ol>
        </div>
    </div>
    </div><!-- /#pane-fin-phase -->

    <!-- ════════════════════ ONGLET FIN DE SAISON ════════════════════ -->
    <div class="tab-pane fade cards-row" id="pane-fin-saison">
    <div class="op-card card-recap">
        <div class="card-head">
            <h2><i class="bi bi-calendar2-x warn-icon"></i>Fin de saison — opérations à réaliser</h2>
            <p class="recap-intro">
                À réaliser en fin de saison sportive, avant de basculer sur la nouvelle année.
                Rien n'est exécuté ici : ce n'est qu'un rappel.
            </p>
        </div>
        <div class="card-body-custom">
            <ol class="recap-list">
                <li><strong>Clôture comptable</strong> de la dernière phase (<code>EN16</code>) — toutes les pièces transmises, indemnités soldées.</li>
                <li><strong>Sauvegarde totale + archivage</strong> — onglet « Sauvegarde » → « Sauvegarder toute la base », puis récupérer le fichier <code>Full_*.sql</code> hors du serveur : c'est l'archive de la saison.</li>
                <li><strong>Changer la saison courante</strong> dans <code>EA91</code> (clé <code>saison</code>, ex. <code>2026-2027</code>) et ajuster les bornes de phase.</li>
                <li><strong>Ré-engagement des clubs</strong> — envoi du questionnaire de désidératas (menu CSR <code>ES31</code> / <code>ES32</code>, ou <code>EN12</code>) → saisie par les clubs via <code>EN18</code>.</li>
                <li><strong>Nettoyage complet</strong> — onglet « Sauvegarde » → « Sauvegarder et démarrer nouvelle phase » (même opération : vidage des tables de compétition, désactivation des JA, purge des imports).</li>
                <li><strong>Mise à jour des JA</strong> — import FFTT (<code>EN11</code>), réactivation, vérification coordonnées / clubs / dates de validation.</li>
                <li><strong>Import de la nouvelle saison</strong> — équipes régionales (<code>EA92</code>), rencontres régionales (<code>EA82</code>) et nationales (<code>EA83</code>), calendrier régional (<code>EA84</code>).</li>
                <li><strong>Vérifier la configuration</strong> (<code>EA91</code>) — départements actifs, règles départements, barème kilométrique / indemnité forfaitaire, comptes EBP, nombre de candidats JA (EN14), SMTP.</li>
                <li><strong>Purger les anciennes sauvegardes</strong> — boutons « Supprimer les anciennes sauvegardes » des onglets Sauvegarde / Restauration.</li>
            </ol>
        </div>
    </div>
    </div><!-- /#pane-fin-saison -->

    </div><!-- /.tab-content -->

</div><!-- /main-content -->

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const CLEAN_BASE = '<?= site_url('clean') ?>';

function spinner(show) { $('#spinner').toggleClass('show', show); }
function setStatus(msg) { $('#status-bar').text(msg); }

// ═══════════════════════════════════════════════════════════════════
//  Mot de passe partagé
// ═══════════════════════════════════════════════════════════════════
let pwdOk    = false;
let pwdTimer = null;

function majBoutonsAvecPwd() {
    $('#btn-executer').prop('disabled', !pwdOk);
    $('#btn-full').prop('disabled', !pwdOk);
    // Ces boutons ne s'activent que si des fichiers sont déjà chargés
    if ($('#restore-form').is(':visible'))       $('#btn-restaurer').prop('disabled', !pwdOk);
    if ($('#full-restore-form').is(':visible'))  $('#btn-restaurer-total').prop('disabled', !pwdOk);
    if ($('#select-table-nom').val())            $('#btn-restaurer-table').prop('disabled', !pwdOk);
    if ($('#select-save-table').val())           $('#btn-save-table').prop('disabled', !pwdOk);
}

$('#pwd-global').on('input', function () {
    const val = $(this).val();
    pwdOk = false;
    majBoutonsAvecPwd();
    $('#pwd-msg-global').text('').removeClass('text-danger text-success');
    $(this).removeClass('is-invalid is-valid');

    clearTimeout(pwdTimer);
    if (val.length < 3) return;

    pwdTimer = setTimeout(() => {
        $.post(`${CLEAN_BASE}/verifier-mdp`, { password: val }, res => {
            if (res.ok) {
                pwdOk = true;
                $('#pwd-global').removeClass('is-invalid').addClass('is-valid');
                $('#pwd-msg-global').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>Mot de passe correct.</span>');
            } else {
                $('#pwd-global').addClass('is-invalid').removeClass('is-valid');
                $('#pwd-msg-global').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
            }
            majBoutonsAvecPwd();
        }, 'json');
    }, 600);
});

$('#pwd-toggle-btn').on('click', function () {
    const $i = $('#pwd-global');
    const isHidden = $i.attr('type') === 'password';
    $i.attr('type', isHidden ? 'text' : 'password');
    $('#pwd-eye').text(isHidden ? '🙈' : '👁️');
});

$('#btn-executer').on('click', function () {
    if (!pwdOk) return;
    nijacConfirm(
        'DERNIÈRE CONFIRMATION\n\n' +
        '• Les tables Competition_Regionale, Disponible, Equipe, Equipe_Nationale, Nomination et Rencontre seront vidées.\n' +
        '• Tous les JA seront désactivés (Actif = 0) mais conservés.\n' +
        '• Les fichiers .xlsx du dossier Importation/Rencontres seront supprimés.\n\n' +
        'Une sauvegarde sera effectuée avant le nettoyage.\n\n' +
        'Cette opération est IRRÉVERSIBLE.',
        function () { executerNettoyage(); },
        null,
        { type: 'danger', title: 'Nouvelle phase', confirmLabel: 'Nettoyer' }
    );
});

function executerNettoyage() {
    spinner(true);
    setStatus('Sauvegarde en cours…');
    $('#btn-executer').prop('disabled', true);

    $.post(`${CLEAN_BASE}/executer`, { password: $('#pwd-global').val() }, res => {
        spinner(false);
        const $box = $('#clean-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Nouvelle phase démarrée !</strong><br>
                   Sauvegarde&nbsp;: <code>${res.fichier}</code> (${res.lignes} lignes)<br>
                   Tables Competition_Regionale, Disponible, Equipe, Equipe_Nationale, Nomination, Rencontre vidées.<br>
                   ${res.ja_desactives} JA désactivé(s) (conservés en base).<br>
                   ${res.xlsx_supprimes} fichier(s) .xlsx supprimé(s) de Importation/Rencontres.
                 </div>`
            );
            $('#section-clean').hide();
            setStatus('Nettoyage terminé — ' + res.fichier);
            chargerListeSauvegardes();
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur nettoyage : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#clean-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
}

// ═══════════════════════════════════════════════════════════════════
//  Bloc RESTAURATION
// ═══════════════════════════════════════════════════════════════════
let fichiers = [];

function chargerListeSauvegardes() {
    $.get(`${CLEAN_BASE}/sauvegardes`, res => {
        fichiers = res.fichiers || [];
        const $zone = $('#restore-file-zone');

        if (!fichiers.length) {
            $zone.html(
                '<div class="no-backup"><i class="bi bi-inbox"></i>Aucune sauvegarde disponible dans <code>/SQL/</code></div>'
            );
            $('#restore-form').hide();
            return;
        }

        $zone.html('');
        const $sel = $('#select-fichier').empty();
        fichiers.forEach(f => {
            $sel.append(new Option(`${f.nom}  (${f.taille} Ko — ${f.date})`, f.nom));
        });
        majFichierMeta();
        $('#restore-loading').hide();
        $('#restore-form').show();
        $('#btn-restaurer').prop('disabled', !pwdOk);
        if (fichiers.length > 1) {
            $('#btn-suppr-sauve').show().text(`Supprimer les ${fichiers.length - 1} ancienne(s) sauvegarde(s)`);
        } else {
            $('#btn-suppr-sauve').hide();
        }
    }, 'json').fail(() => {
        $('#restore-file-zone').html('<div class="no-backup"><i class="bi bi-wifi-off"></i>Erreur lors du chargement des sauvegardes.</div>');
    });
}

function majFichierMeta() {
    const nom = $('#select-fichier').val();
    const f   = fichiers.find(x => x.nom === nom);
    if (f) {
        $('#fichier-meta').text(`Taille : ${f.taille} Ko — Créé le ${f.date}`);
    }
}

$('#select-fichier').on('change', majFichierMeta);

$('#btn-restaurer').on('click', function () {
    if (!pwdOk) return;
    const fichier = $('#select-fichier').val();
    if (!fichier) return;

    nijacConfirm(
        'RESTAURATION\n\n' +
        'Le fichier « ' + fichier + ' » va écraser\n' +
        'les données actuelles des tables Competition_Regionale,\n' +
        'Disponible, Equipe, Equipe_Nationale, JA, Nomination\n' +
        'et Rencontre.\n\n' +
        'Cette opération est IRRÉVERSIBLE.',
        function () { restaurerFichier(fichier); },
        null,
        { type: 'danger', title: 'Restauration', confirmLabel: 'Restaurer' }
    );
});

function restaurerFichier(fichier) {
    spinner(true);
    setStatus('Restauration en cours : ' + fichier + ' …');
    $('#btn-restaurer').prop('disabled', true);

    $.post(`${CLEAN_BASE}/restaurer`, {
        fichier:  fichier,
        password: $('#pwd-global').val()
    }, res => {
        spinner(false);
        const $box = $('#restore-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Restauration réussie !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code> — ${res.executed} instruction(s) exécutée(s).
                 </div>`
            );
            $('#section-restore').hide();
            setStatus('Restauration terminée — ' + res.fichier);
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur restauration : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#restore-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
}

// ═══════════════════════════════════════════════════════════════════
//  Bloc SAUVEGARDE TOTALE
// ═══════════════════════════════════════════════════════════════════
function chargerListeSauvegardesTotal() {
    $.get(`${CLEAN_BASE}/sauvegardes-total`, res => {
        const $zone = $('#full-liste-zone');
        if (!res.ok || !res.fichiers.length) {
            $zone.html('<p class="text-muted" style="font-size:.8rem;margin:0;">Aucune sauvegarde totale existante.</p>');
            return;
        }
        let html = '<p style="font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.4rem;"><i class="bi bi-clock-history me-1"></i>Sauvegardes existantes</p><ul style="font-size:.8rem;color:#374151;padding-left:1.1rem;margin:0;">';
        res.fichiers.forEach(f => {
            html += `<li><code>${f.nom}</code> &mdash; ${f.taille} Ko &mdash; ${f.date}</li>`;
        });
        html += '</ul>';
        if (res.fichiers.length > 1) {
            html += `<button id="btn-suppr-full" class="btn-action" style="background:#6b7280;color:#fff;margin-top:.6rem;font-size:.8rem;padding:.3rem .7rem;width:auto;">
                       <i class="bi bi-trash me-1"></i>Supprimer les ${res.fichiers.length - 1} ancienne(s) sauvegarde(s)
                     </button>`;
        }
        $zone.html(html);
    }, 'json');
}

$('#btn-full').on('click', function () {
    if (!pwdOk) return;
    nijacConfirm(
        'SAUVEGARDE TOTALE\n\n' +
        'Toutes les tables de la base de données vont être\n' +
        'exportées (structure + données) dans un fichier SQL.',
        function () { executerSauvegardeTotale(); },
        null,
        { type: 'question', title: 'Sauvegarde totale', confirmLabel: 'Sauvegarder' }
    );
});

function executerSauvegardeTotale() {
    spinner(true);
    setStatus('Sauvegarde totale en cours…');
    $('#btn-full').prop('disabled', true);

    $.post(`${CLEAN_BASE}/sauvegarde-totale`, { password: $('#pwd-global').val() }, res => {
        spinner(false);
        const $box = $('#full-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Sauvegarde totale réussie !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code><br>
                   ${res.tables} table(s) exportée(s) — ${res.taille} Ko
                 </div>`
            );
            setStatus('Sauvegarde totale terminée — ' + res.fichier);
            chargerListeSauvegardesTotal();
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur sauvegarde totale : ' + res.msg);
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#full-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        majBoutonsAvecPwd();
    });
}

// ═══════════════════════════════════════════════════════════════════
//  Bloc SAUVEGARDE D'UNE TABLE
// ═══════════════════════════════════════════════════════════════════
$('#select-save-table').on('change', function () {
    $('#btn-save-table').prop('disabled', !pwdOk || !$(this).val());
});

$('#btn-save-table').on('click', function () {
    if (!pwdOk) return;
    const table = $('#select-save-table').val();
    if (!table) return;

    nijacConfirm(
        'SAUVEGARDE DE TABLE\n\n' +
        'La table « ' + table + ' » va être exportée\n' +
        '(structure + données) dans un fichier SQL.',
        function () { executerSauvegardeTable(table); },
        null,
        { type: 'question', title: 'Sauvegarde de table', confirmLabel: 'Sauvegarder' }
    );
});

function executerSauvegardeTable(table) {
    spinner(true);
    setStatus('Sauvegarde de la table ' + table + '…');
    $('#btn-save-table').prop('disabled', true);

    $.post(`${CLEAN_BASE}/sauvegarde-table`, { table, password: $('#pwd-global').val() }, res => {
        spinner(false);
        const $box = $('#table-save-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Table sauvegardée !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code><br>
                   Table&nbsp;: <code>${res.table}</code> — ${res.lignes} ligne(s), ${res.taille} Ko
                 </div>`
            );
            setStatus('Sauvegarde table terminée — ' + res.fichier);
            chargerFichiersTableRestore();
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            setStatus('Erreur sauvegarde table : ' + res.msg);
        }
        $('#btn-save-table').prop('disabled', !pwdOk || !$('#select-save-table').val());
    }, 'json').fail(() => {
        spinner(false);
        $('#table-save-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
        $('#btn-save-table').prop('disabled', !pwdOk || !$('#select-save-table').val());
    });
}

// ═══════════════════════════════════════════════════════════════════
//  Bloc RESTAURATION TOTALE
// ═══════════════════════════════════════════════════════════════════
let fullFichiers = [];

function chargerListeSauvegardesTotal2() {
    $.get(`${CLEAN_BASE}/sauvegardes-total`, res => {
        fullFichiers = res.fichiers || [];
        const $zone  = $('#full-restore-file-zone');

        if (!fullFichiers.length) {
            $zone.html('<div class="no-backup"><i class="bi bi-inbox"></i>Aucune sauvegarde totale disponible dans <code>/SQL/</code></div>');
            $('#full-restore-form').hide();
            return;
        }

        $zone.html('');
        const $sel = $('#select-full-fichier').empty();
        fullFichiers.forEach(f => {
            $sel.append(new Option(`${f.nom}  (${f.taille} Ko — ${f.date})`, f.nom));
        });
        majFullFichierMeta();
        $('#full-restore-loading').hide();
        $('#full-restore-form').show();
        $('#btn-restaurer-total').prop('disabled', !pwdOk);
    }, 'json').fail(() => {
        $('#full-restore-file-zone').html('<div class="no-backup"><i class="bi bi-wifi-off"></i>Erreur lors du chargement des sauvegardes.</div>');
    });
}

function majFullFichierMeta() {
    const nom = $('#select-full-fichier').val();
    const f   = fullFichiers.find(x => x.nom === nom);
    if (f) $('#full-fichier-meta').text(`Taille : ${f.taille} Ko — Créé le ${f.date}`);
}

$('#select-full-fichier').on('change', majFullFichierMeta);

$('#btn-restaurer-total').on('click', function () {
    if (!pwdOk) return;
    const fichier = $('#select-full-fichier').val();
    if (!fichier) return;

    nijacConfirm(
        'RESTAURATION TOTALE DE LA BASE\n\n' +
        'Le fichier « ' + fichier + ' » va être rejoué.\n' +
        'TOUTES les tables seront supprimées puis recréées\n' +
        'à partir de ce fichier.\n\n' +
        'Cette opération est IRRÉVERSIBLE.',
        function () { restaurerTotal(fichier); },
        null,
        { type: 'danger', title: 'Restauration totale', confirmLabel: 'Restaurer' }
    );
});

function restaurerTotal(fichier) {
    spinner(true);
    setStatus('Restauration totale en cours : ' + fichier + ' …');
    $('#btn-restaurer-total').prop('disabled', true);

    $.ajax({
        url: `${CLEAN_BASE}/restaurer-total`,
        method: 'POST',
        dataType: 'json',
        timeout: 600000, // 10 min : une restauration complète peut être longue
        data: { fichier: fichier, password: $('#pwd-global').val() }
    }).done(res => {
        spinner(false);
        const $box = $('#full-restore-result').addClass('show');
        if (res.ok) {
            $box.html(
                `<div class="result-ok">
                   ✅ <strong>Restauration totale réussie !</strong><br>
                   Fichier&nbsp;: <code>${res.fichier}</code> — ${res.executed} instruction(s) exécutée(s).
                 </div>`
            );
            $('#section-full-restore').hide();
            setStatus('Restauration totale terminée — ' + res.fichier);
        } else {
            $box.html(`<div class="result-err"><strong>Erreur :</strong> ${res.msg}</div>`);
            majBoutonsAvecPwd();
            setStatus('Erreur restauration totale : ' + res.msg);
        }
    }).fail((xhr, textStatus) => {
        spinner(false);
        const msg = textStatus === 'timeout'
            ? 'La restauration dépasse 10 min — elle continue peut-être côté serveur, vérifiez la base avant de relancer.'
            : 'Erreur réseau.';
        $('#full-restore-result').addClass('show').html(`<div class="result-err"><strong>${msg}</strong></div>`);
        majBoutonsAvecPwd();
    });
}

// ═══════════════════════════════════════════════════════════════════
//  Bloc RESTAURATION TABLE UNIQUE
// ═══════════════════════════════════════════════════════════════════
let tableRestoreFichiers = [];

function chargerFichiersTableRestore(url) {
    // Repli sur /sauvegardes-total si /sauvegardes-table n'est pas déployé
    // (déploiement FTP partiel : vue à jour mais route/contrôleur pas encore).
    url = url || `${CLEAN_BASE}/sauvegardes-table`;
    $.get(url, res => {
        tableRestoreFichiers = res.fichiers || [];
        const $zone = $('#table-restore-file-zone');

        if (!tableRestoreFichiers.length) {
            $zone.html('<div class="no-backup"><i class="bi bi-inbox"></i>Aucune sauvegarde <code>Full_*.sql</code> ou <code>Table_*.sql</code> dans <code>/SQL/</code></div>');
            $('#table-restore-form').hide();
            return;
        }

        $zone.html('');
        const $sel = $('#select-table-fichier').empty();
        tableRestoreFichiers.forEach(f => {
            $sel.append(new Option(`${f.nom}  (${f.taille} Ko — ${f.date})`, f.nom));
        });
        $('#table-restore-loading').hide();
        $('#table-restore-form').show();
    }, 'json').fail(() => {
        if (url.endsWith('/sauvegardes-table')) {
            chargerFichiersTableRestore(`${CLEAN_BASE}/sauvegardes-total`);
            return;
        }
        $('#table-restore-file-zone').html('<div class="no-backup"><i class="bi bi-wifi-off"></i>Erreur lors du chargement des sauvegardes.</div>');
    });
}

function majTableRestoreMeta() {
    const table = $('#select-table-nom').val();
    if (table) {
        $('#table-restore-meta').text(`La table « ${table} » sera supprimée et recréée depuis la sauvegarde.`);
        $('#btn-restaurer-table').prop('disabled', !pwdOk);
    } else {
        $('#table-restore-meta').text('');
        $('#btn-restaurer-table').prop('disabled', true);
    }
}

$('#select-table-fichier').on('change', function () {
    // Un fichier Table_<nom>_<date>.sql ne contient qu'une table : la présélectionner.
    const m = /^Table_([A-Za-z0-9_]+)_\d{12}\.sql$/.exec($(this).val() || '');
    if (m) $('#select-table-nom').val(m[1]).trigger('change');
});

$('#select-table-nom').on('change', majTableRestoreMeta);

$('#btn-restaurer-table').on('click', function () {
    if (!pwdOk) return;
    const fichier = $('#select-table-fichier').val();
    const table   = $('#select-table-nom').val();
    if (!fichier || !table) return;

    nijacConfirm(
        'La table « ' + table + ' » va être supprimée puis recréée\n' +
        'depuis le fichier « ' + fichier + ' ».\n\n' +
        'Cette opération est IRRÉVERSIBLE.',
        function () {
            spinner(true);
            setStatus('Restauration de la table ' + table + ' en cours…');
            $('#btn-restaurer-table').prop('disabled', true);
            $('#table-restore-result').removeClass('show').html('');

            $.post(`${CLEAN_BASE}/restaurer-table`, {
                fichier,
                table,
                password: $('#pwd-global').val()
            }, function (res) {
                spinner(false);
                const $box = $('#table-restore-result').addClass('show');
                if (res.ok) {
                    const icons = { drop: '🗑️', create: '🏗️', insert: '➕', alter: '🔧', other: '▶️' };
                    let logHtml = '';
                    if (res.log && res.log.length) {
                        const lignes = res.log.map(function (l) {
                            return '<li style="font-family:monospace;font-size:.78rem;">' + (icons[l.type] || '▶️') + ' ' + l.label + '</li>';
                        }).join('');
                        logHtml =
                            '<details style="margin-top:.6rem;">' +
                            '<summary style="cursor:pointer;font-size:.78rem;color:#555;">Détail des ' + res.executed + ' instruction(s) exécutée(s)</summary>' +
                            '<ul style="margin:.4rem 0 0 1rem;padding:0;list-style:none;">' + lignes + '</ul>' +
                            '</details>';
                    }
                    $box.html(
                        '<div class="result-ok">' +
                        '✅ <strong>Table restaurée avec succès !</strong><br>' +
                        'Table&nbsp;: <code>' + res.table + '</code> — ' + res.executed + ' instruction(s) exécutée(s).<br>' +
                        'Source&nbsp;: <code>' + res.fichier + '</code>' +
                        logHtml +
                        '</div>'
                    );
                    setStatus('✅ Restauration de « ' + res.table + ' » terminée.');
                    nijacToast('Table « ' + res.table + ' » restaurée avec succès.', 'success', 5000);
                } else {
                    $box.html('<div class="result-err"><strong>Erreur :</strong> ' + res.msg + '</div>');
                    majTableRestoreMeta();
                    setStatus('Erreur restauration table : ' + res.msg);
                }
            }, 'json').fail(function () {
                spinner(false);
                $('#table-restore-result').addClass('show').html('<div class="result-err"><strong>Erreur réseau.</strong></div>');
                majTableRestoreMeta();
            });
        },
        null,
        { type: 'danger', title: 'Restauration de table', confirmLabel: 'Restaurer' }
    );
});

// ═══════════════════════════════════════════════════════════════════
//  Suppression des anciennes sauvegardes
// ═══════════════════════════════════════════════════════════════════
$('#btn-suppr-sauve').on('click', function () {
    if (!pwdOk) { nijacToast('Mot de passe requis.', 'danger'); return; }
    nijacConfirm(
        'Supprimer toutes les sauvegardes Sauve_*.sql sauf la plus récente ?\n\nCette opération est irréversible.',
        function () {
            spinner(true);
            $.post(`${CLEAN_BASE}/supprimer-anciennes`, { type: 'sauve', password: $('#pwd-global').val() }, res => {
                spinner(false);
                if (res.ok) {
                    setStatus(res.msg);
                    chargerListeSauvegardes();
                } else {
                    nijacToast('Erreur : ' + res.msg, 'danger');
                }
            }, 'json').fail(() => { spinner(false); nijacToast('Erreur réseau.', 'danger'); });
        },
        null,
        { type: 'danger', confirmLabel: 'Supprimer' }
    );
});

$(document).on('click', '#btn-suppr-full', function () {
    if (!pwdOk) { nijacToast('Mot de passe requis.', 'danger'); return; }
    nijacConfirm(
        'Supprimer toutes les sauvegardes Full_*.sql sauf la plus récente ?\n\nCette opération est irréversible.',
        function () {
            spinner(true);
            $.post(`${CLEAN_BASE}/supprimer-anciennes`, { type: 'full', password: $('#pwd-global').val() }, res => {
                spinner(false);
                if (res.ok) {
                    setStatus(res.msg);
                    chargerListeSauvegardesTotal();
                    chargerListeSauvegardesTotal2();
                    chargerFichiersTableRestore();
                } else {
                    nijacToast('Erreur : ' + res.msg, 'danger');
                }
            }, 'json').fail(() => { spinner(false); nijacToast('Erreur réseau.', 'danger'); });
        },
        null,
        { type: 'danger', confirmLabel: 'Supprimer' }
    );
});

// Réévalue l'état des boutons du panneau qui devient visible (un mdp saisi
// sous l'onglet Sauvegarde doit activer les boutons de l'onglet Restauration).
$('#e016-tabs button[data-bs-toggle="tab"]').on('shown.bs.tab', majBoutonsAvecPwd);

// ── Initialisation ────────────────────────────────────────────────
$(function () {
    chargerListeSauvegardes();
    chargerListeSauvegardesTotal();
    chargerListeSauvegardesTotal2();
    chargerFichiersTableRestore();
});
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
