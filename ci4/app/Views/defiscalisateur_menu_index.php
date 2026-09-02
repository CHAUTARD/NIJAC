<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Menu Défiscalisateur (E005)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            background: #fff3e0;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── En-tête (orange, propre à E005/Défiscalisateur — distinct du bleu Admin,
           du vert Nominateur et du violet CSR) ── */
        #page-header {
            background: #e65100;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
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

        /* ── Toolbar ── */
        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            gap: .75rem;
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

        /* Visible seulement pour un Administrateur qui prévisualise le menu Défiscalisateur. */
        #btn-switch-admin {
            display: <?= $isAdmin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            background: var(--nijac-blue);
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }
        #btn-switch-admin:hover { background: #0f2550; color: #fff; }

        /* ── Grille de boutons (mêmes classes et même style que E002/E004, pour une
           apparence identique) ── */
        #menu-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            padding: 24px;
            flex: 1;
            /* E005 n'a qu'une seule ligne à moitié remplie : sans ça, elle s'étire pour
               occuper tout l'espace vertical laissé par flex:1. */
            align-content: start;
        }

        .menu-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            padding: 20px 12px 18px;
            border: 2px solid rgba(0,0,0,.12);
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            font-size: 1.1rem;
            font-weight: 700;
            font-family: 'Segoe UI', system-ui, sans-serif;
            color: #222;
            transition: filter .15s, transform .1s, box-shadow .15s;
            box-shadow: 2px 2px 6px rgba(0,0,0,.15);
            text-align: center;
            min-height: 190px;
            position: relative;
        }

        .menu-btn .btn-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 150px;
            height: 150px;
            flex-shrink: 0;
        }

        .menu-btn img {
            max-width: 150px;
            max-height: 150px;
            width: 150px;
            height: 150px;
            object-fit: contain;
        }

        .menu-btn .btn-icon i {
            font-size: 6rem;
            line-height: 1;
        }

        .menu-btn span {
            line-height: 1.25;
            margin-top: 10px;
        }

        .menu-btn .btn-desc {
            font-size: .72rem;
            font-weight: 400;
            color: #555;
            margin-top: 4px;
            line-height: 1.3;
        }
        .menu-btn:hover .btn-desc { color: #333; }

        .menu-btn:hover {
            filter: brightness(1.08);
            transform: translateY(-2px);
            box-shadow: 4px 6px 14px rgba(0,0,0,.22);
            color: #000;
        }

        .menu-btn:active {
            transform: translateY(0);
            box-shadow: 1px 2px 4px rgba(0,0,0,.15);
        }

        .btn-defiscalisation { background-color: #e8f5e9; }
        .btn-attestation     { background-color: #ffe0b2; }
        .btn-attestations    { background-color: #ffe9d1; }

        .btn-code {
            position: absolute;
            top: 6px;
            right: 8px;
            font-size: .62rem;
            font-weight: 600;
            color: rgba(0,0,0,.32);
            letter-spacing: .03em;
            pointer-events: none;
        }

        #zone-deconnexion {
            padding: 0 24px 24px;
            display: flex;
            justify-content: center;
        }
        #zone-deconnexion .menu-btn {
            max-width: 260px;
            width: 100%;
        }
    </style>
</head>
<body>

<!-- En-tête : pas de bouton Retour, page racine du rôle Défiscalisateur -->
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu Défiscalisateur
        <small class="opacity-75 ms-2">(E005)</small>
    </div>
</div>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbSwitchTo' => 'admin']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Grille -->
<div id="menu-grid">

    <a href="<?= site_url('defiscalisation') ?>" class="menu-btn btn-defiscalisation">
        <span class="btn-code">ED51</span>
        <div class="btn-icon"><img src="<?= base_url('img/Defiscalisation.png') ?>" alt="Défiscalisation JA"></div>
        <span>Défiscalisation JA</span>
        <span class="btn-desc">Frais cumulés des JA défiscalisés sur une période, export CSV</span>
    </a>

    <a href="<?= site_url('attestation-defisc') ?>" class="menu-btn btn-attestation">
        <span class="btn-code">ED53</span>
        <div class="btn-icon"><img src="<?= base_url('img/AttestationHonneur.png') ?>" alt="Attestation sur l'honneur"></div>
        <span>Attestation sur l'honneur</span>
        <span class="btn-desc">Document à compléter, signer à l'écran et imprimer</span>
    </a>

    <a href="<?= site_url('attestations-defisc') ?>" class="menu-btn btn-attestations">
        <span class="btn-code">ED54</span>
        <div class="btn-icon"><img src="<?= base_url('img/DirDefiscalisation.png') ?>" alt="Attestations reçues"></div>
        <span>Attestations reçues</span>
        <span class="btn-desc">Liste des attestations signées déposées par les JA</span>
    </a>

</div>

<!-- Déconnexion : ligne à part, centrée -->
<div id="zone-deconnexion">
    <a href="<?= site_url('logout') ?>" id="lnk-logout" class="menu-btn" style="background:#f8d7da;">
        <div class="btn-icon"><img src="<?= base_url('img/Quitter.png') ?>" alt="Se déconnecter"></div>
        <span style="color:#842029;">Se déconnecter</span>
        <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
    </a>
</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
