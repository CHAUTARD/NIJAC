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
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-chrome.css') ?>">

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

        #toolbar { gap: .75rem; }

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
        #btn-switch-nominateur, #btn-switch-csr, #btn-switch-craconvoc {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }
        #btn-switch-nominateur { background: #2e7d32; }
        #btn-switch-nominateur:hover { background: #1b5e20; color: #fff; }
        #btn-switch-csr { background: #6a1b9a; }
        #btn-switch-csr:hover { background: #4a148c; color: #fff; }
        #btn-switch-craconvoc { background: #00838f; }
        #btn-switch-craconvoc:hover { background: #006064; color: #fff; }

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
            line-height: 1.3;
            /* En haut du bouton, sur la ligne du code écran (top: 6px), sans le chevaucher */
            order: -1;
            margin: -14px 0 4px;
            padding: 0 28px;
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
        .btn-compta          { background-color: #e3f2fd; }
        /* Bouton documentation : fond hachuré pour le distinguer des écrans */
        .btn-doc {
            background-color: #eceff1;
            background-image: repeating-linear-gradient(45deg,
                rgba(0,0,0,.07) 0, rgba(0,0,0,.07) 7px,
                transparent 7px, transparent 15px);
        }

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
            flex-wrap: wrap;
            gap: 16px;
        }
        #zone-deconnexion .menu-btn {
            max-width: 260px;
            width: 100%;
        }

        /* Écrans moyens / petits : la grille de 5 colonnes passe à 3, 2 puis 1 (comme E003) */
        @media (max-width: 1199.98px) {
            #menu-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 767.98px) {
            #menu-grid { grid-template-columns: repeat(2, 1fr); padding: 12px; gap: 12px; }
            .menu-btn .btn-icon { width: 100px; height: 100px; }
            .menu-btn img { width: 100px; height: 100px; max-width: 100px; max-height: 100px; }
            .menu-btn { min-height: 0; padding: 14px 8px 12px; font-size: .95rem; }
            .menu-btn .btn-desc { margin-top: -8px; }
            .menu-btn .btn-icon i { font-size: 4rem; }
            #page-header { padding: .5rem .75rem; }
            #toolbar { flex-wrap: wrap; padding: .3rem .75rem; }
        }
        @media (max-width: 479.98px) {
            #menu-grid { grid-template-columns: 1fr; }
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
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowCsr' => !empty($menuCsrVisible), 'tbShowCraConvoc' => !empty($menuCraVisible), 'tbShowNominateur' => !empty($isAdmin), 'tbSwitchTo' => $menuSwitchTo]) ?>

<!-- Grille -->
<div id="menu-grid">

    <a href="<?= site_url('defiscalisation') ?>" class="menu-btn btn-defiscalisation">
        <span class="btn-code">ED51</span>
        <div class="btn-icon"><img src="<?= base_url('img/Defiscalisation.webp') ?>" alt="Défiscalisation JA"></div>
        <span>Défiscalisation JA</span>
        <span class="btn-desc">Frais cumulés des JA défiscalisés sur une période, export CSV</span>
    </a>

    <a href="<?= site_url('attestation-defisc') ?>?retour=menu" class="menu-btn btn-attestation">
        <span class="btn-code">ED53</span>
        <div class="btn-icon"><img src="<?= base_url('img/AttestationHonneur.webp') ?>" alt="Attestation sur l'honneur"></div>
        <span>Attestation sur l'honneur</span>
        <span class="btn-desc">Document à compléter, signer à l'écran et imprimer</span>
    </a>

    <a href="<?= site_url('attestations-defisc') ?>" class="menu-btn btn-attestations">
        <span class="btn-code">ED54</span>
        <div class="btn-icon"><img src="<?= base_url('img/DirDefiscalisation.webp') ?>" alt="Attestations reçues"></div>
        <span>Attestations reçues</span>
        <span class="btn-desc">Liste des attestations signées déposées par les JA</span>
    </a>

    <a href="<?= site_url('compta') ?>" class="menu-btn btn-compta">
        <span class="btn-code">ED55</span>
        <div class="btn-icon"><img src="<?= base_url('img/Compta.webp') ?>" alt="Comptabilite"></div>
        <span>Comptes EBP des JA</span>
        <span class="btn-desc">Import CSV et saisie du n° de compte EBP des JA</span>
    </a>

</div>

<!-- Déconnexion + Documentation : ligne à part, centrée -->
<div id="zone-deconnexion">
    <form method="post" action="<?= site_url('logout') ?>" id="form-logout" class="d-none"><?= csrf_field() ?></form>
    <a href="<?= site_url('logout') ?>" id="lnk-logout" class="menu-btn" style="background:#f8d7da;" onclick="document.getElementById('form-logout').submit(); return false;">
        <div class="btn-icon"><img src="<?= base_url('img/Quitter.webp') ?>" alt="Se déconnecter"></div>
        <span style="color:#842029;">Se déconnecter</span>
        <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
    </a>
    <a href="<?= base_url('Documentation/guide-ecrans-defiscalisateur.html') ?>" target="_blank" rel="noopener" class="menu-btn btn-doc">
        <div class="btn-icon"><img src="<?= base_url('img/Documentation.png') ?>" alt="Documentation"></div>
        <span>Documentation</span>
        <span class="btn-desc">Guide des écrans (nouvelle page)</span>
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
