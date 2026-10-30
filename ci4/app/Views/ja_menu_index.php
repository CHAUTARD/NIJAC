<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Menu JA (E011)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            background: #fff8e1;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── En-tête (ambre, propre à E011/JA — distinct du bleu Admin, du vert Nominateur,
           du violet CSR, de l'orange Défiscalisateur et du sarcelle CRA Convoc) ── */
        #page-header {
            background: #8d6e00;
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
        /* Bouton de barre de retour du mode JA (« Menu administrateur » / « Menu nominateur »…), même style que « Menu JA » d'E002/E003 */
        #btn-switch-retour {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            background: #1565c0;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }
        #btn-switch-retour:hover { background: #0d47a1; color: #fff; }

        /* ── Boutons (mêmes classes et même style que E002/E004/E005/E009) ── */
        #menu-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-content: start;
            gap: 16px;
            padding: 24px;
            flex: 1;
        }
        #menu-grid .menu-btn { width: 260px; }
        #menu-grid .menu-break { flex-basis: 100%; height: 0; }

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
        .menu-btn .btn-icon i { font-size: 6rem; line-height: 1; }
        .menu-btn span { line-height: 1.25; margin-top: 10px; }
        .menu-btn .btn-desc {
            font-size: .72rem;
            font-weight: 400;
            color: #555;
            line-height: 1.3;
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

        @media (max-width: 767.98px) {
            #menu-grid { padding: 12px; gap: 12px; }
            .menu-btn .btn-icon { width: 100px; height: 100px; }
            .menu-btn img { width: 100px; height: 100px; max-width: 100px; max-height: 100px; }
            .menu-btn { min-height: 0; padding: 14px 8px 12px; font-size: .95rem; }
            .menu-btn .btn-desc { margin-top: -8px; }
            .menu-btn .btn-icon i { font-size: 4rem; }
            #page-header { padding: .5rem .75rem; }
            #toolbar { flex-wrap: wrap; padding: .3rem .75rem; }
        }
        @media (max-width: 479.98px) {
            #menu-grid .menu-btn { width: 100%; }
        }
    </style>
</head>
<body>

<!-- En-tête : pas de bouton Retour, page racine du rôle JA -->
<div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
    <div style="flex:1;min-width:0;">
        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu Juge-Arbitre
        <small class="opacity-75 ms-2">(E011)</small>
    </div>
</div>

<?= view('partials/toolbar', [
    'tbNomComplet'    => $nomComplet,
    'tbDepartement'   => $departement,
    // Mode JA d'un compte non-JA (fiche liée, POST changer-compte action=ja) : bouton de retour dans la barre.
    'tbRetourCompte'  => !empty($modeJa),
    'tbRetourLibelle' => $libelleMenuOrigine ?? 'Menu nominateur',
    'tbRetourIcone'   => $iconeMenuOrigine ?? 'bi-people-fill',
]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="menu-grid">

    <a href="<?= site_url('ja/disponibilites') ?>" class="menu-btn" style="background:#ffecb3;">
        <span class="btn-code">EJA2</span>
        <div class="btn-icon"><img src="<?= base_url('img/Dispo.webp') ?>" alt="Mes disponibilités"></div>
        <span>Mes disponibilités</span>
        <span class="btn-desc">Journées du championnat régional</span>
    </a>

    <a href="<?= site_url('ja/frais') ?>" class="menu-btn" style="background:#ffecb3;">
        <span class="btn-code">EJA4</span>
        <div class="btn-icon"><img src="<?= base_url('img/Nomination.webp') ?>" alt="Mes nominations et frais"></div>
        <span>Mes nominations et frais</span>
        <span class="btn-desc">Rencontres, accusés, péages, kilomètres, défiscalisation</span>
    </a>

    <a href="<?= site_url('ja/defiscalisation') ?>" class="menu-btn" style="background:#ffecb3;">
        <span class="btn-code">EJA3</span>
        <div class="btn-icon"><img src="<?= base_url('img/Defiscalisation.webp') ?>" alt="Ma défiscalisation"></div>
        <span>Ma défiscalisation</span>
        <span class="btn-desc">Explications, véhicule, carte grise, attestation</span>
    </a>

    <div class="menu-break"></div>

    <form method="post" action="<?= site_url('logout') ?>" id="form-logout" class="d-none"><?= csrf_field() ?></form>
    <a href="<?= site_url('logout') ?>" id="lnk-logout" class="menu-btn" style="background:#f8d7da;" onclick="document.getElementById('form-logout').submit(); return false;">
        <div class="btn-icon"><img src="<?= base_url('img/Quitter.webp') ?>" alt="Se déconnecter"></div>
        <span style="color:#842029;">Se déconnecter</span>
        <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
    </a>

    <a href="<?= site_url('changer-mot-de-passe') ?>" class="menu-btn" style="background:#e3e8f0;">
        <span class="btn-code">E006</span>
        <div class="btn-icon"><i class="bi bi-key-fill" style="color:#1a3a6b;"></i></div>
        <span>Mot de passe</span>
        <span class="btn-desc">Changer mon mot de passe</span>
    </a>

    <a href="<?= base_url('Documentation/guide-ecrans-ja.html') ?>" target="_blank" rel="noopener" class="menu-btn btn-doc">
        <div class="btn-icon"><img src="<?= base_url('img/Documentation.png') ?>" alt="Documentation"></div>
        <span>Documentation</span>
        <span class="btn-desc">Guide des écrans (nouvelle page)</span>
    </a>

</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
