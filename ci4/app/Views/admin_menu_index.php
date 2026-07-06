<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Menu paramètres (E002)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
        }

        #toolbar .ts-user {
            color: #1a3a6b;
            font-weight: 600;
        }

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

        #btn-switch-nominateur {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            background: #2e7d32;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background .15s;
        }
        #btn-switch-nominateur:hover { background: #1b5e20; color: #fff; }

        #page-header {
            background: var(--nijac-blue);
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

        #menu-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 16px;
            padding: 24px;
            flex: 1;
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

        .btn-club         { background-color: #e8f5e9; }
        .btn-salle        { background-color: #e0f2f1; }
        .btn-saison       { background-color: #fff8e1; }
        .btn-division     { background-color: #fbe9e7; }
        .btn-utilisateur  { background-color: #e3f2fd; }
        .btn-communes     { background-color: #e0f7fa; }
        .btn-configuration{ background-color: #eceff1; }
        .btn-dbadmin      { background-color: #fce4ec; }
        .btn-region       { background-color: #e8eaf6; }
        .btn-departement  { background-color: #e1f5fe; }

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
    </style>
</head>
<body>

    <div id="page-header" style="display:flex;align-items:center;gap:.5rem;">
        <div style="flex:1;min-width:0;">
            <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu pour les paramètres
            <small class="opacity-75 ms-2">(E002)</small>
        </div>
    </div>

    <?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbSwitchTo' => 'nominateur']) ?>

    <?php require __DIR__ . '/_modal_mdp.php'; ?>

    <div id="menu-grid">

        <a href="<?= site_url('club') ?>" class="menu-btn btn-club">
            <span class="btn-code">E008</span>
            <div class="btn-icon"><img src="<?= base_url('img/Association.png') ?>" alt="Club / Association"></div>
            <span>Club / Association</span>
            <span class="btn-desc">Gérer les clubs et associations affiliés</span>
        </a>

        <a href="<?= site_url('salle') ?>" class="menu-btn btn-salle">
            <span class="btn-code">E005</span>
            <div class="btn-icon"><img src="<?= base_url('img/Salle.png') ?>" alt="Salle"></div>
            <span>Salle</span>
            <span class="btn-desc">Référencer les salles de compétition et leur adresse</span>
        </a>

        <a href="<?= site_url('utilisateur') ?>" class="menu-btn btn-utilisateur">
            <span class="btn-code">E009</span>
            <div class="btn-icon"><img src="<?= base_url('img/Utilisateur.png') ?>" alt="Utilisateur"></div>
            <span>Utilisateur</span>
            <span class="btn-desc">Gérer les comptes et droits d'accès</span>
        </a>

        <a href="<?= site_url('commune') ?>" class="menu-btn btn-communes">
            <span class="btn-code">E006</span>
            <div class="btn-icon"><img src="<?= base_url('img/La_Poste.png') ?>" alt="Communes"></div>
            <span>Communes</span>
            <span class="btn-desc">Base des codes postaux et coordonnées GPS</span>
        </a>

        <a href="<?= site_url('division') ?>" class="menu-btn btn-division">
            <span class="btn-code">E010</span>
            <div class="btn-icon"><img src="<?= base_url('img/podium.png') ?>" alt="Division"></div>
            <span>Division</span>
            <span class="btn-desc">Définir les divisions et leur niveau</span>
        </a>

        <a href="<?= site_url('import-rencontres') ?>" class="menu-btn btn-club">
            <span class="btn-code">E011</span>
            <div class="btn-icon"><img src="<?= base_url('img/Competition.png') ?>" alt="Import Rencontres"></div>
            <span>Import Rencontres</span>
            <span class="btn-desc">Importer les rencontres depuis un fichier FFTT</span>
        </a>

        <a href="<?= site_url('import-rencontres-nat') ?>" class="menu-btn btn-club">
            <span class="btn-code">E017</span>
            <div class="btn-icon"><img src="<?= base_url('img/ImportExcel_32.png') ?>" alt="Import Nationales"></div>
            <span>Import Rencontres Nationales</span>
            <span class="btn-desc">Importer les rencontres de divisions Nationales</span>
        </a>

        <a href="<?= site_url('region') ?>" class="menu-btn btn-region">
            <span class="btn-code">E012</span>
            <div class="btn-icon"><i class="bi bi-map-fill" style="font-size:6rem;color:#3949ab;"></i></div>
            <span>Régions</span>
            <span class="btn-desc">Gérer les régions et leur gentilé</span>
        </a>

        <a href="<?= site_url('departement') ?>" class="menu-btn btn-departement">
            <span class="btn-code">E013</span>
            <div class="btn-icon"><i class="bi bi-geo-alt-fill" style="font-size:6rem;color:#0277bd;"></i></div>
            <span>Départements</span>
            <span class="btn-desc">Gérer les départements et leur région</span>
        </a>

        <a href="<?= site_url('clean') ?>" class="menu-btn btn-saison" style="grid-column: 1;">
            <span class="btn-code">E016</span>
            <div class="btn-icon"><img src="<?= base_url('img/Nettoyage.png') ?>" alt="Saison"></div>
            <span>Saison</span>
            <span class="btn-desc">Suppression des informations sur la saison dernière</span>
        </a>

        <a href="<?= site_url('configuration') ?>" class="menu-btn btn-configuration">
            <span class="btn-code">E015</span>
            <div class="btn-icon"><img src="<?= base_url('img/Parametres.png') ?>" alt="Configuration"></div>
            <span>Configuration</span>
            <span class="btn-desc">Paramètres généraux de l'application</span>
        </a>

        <?php if ($isChautard): ?>
        <a href="<?= site_url('fftt-test') ?>" class="menu-btn btn-configuration">
            <span class="btn-code">E018</span>
            <div class="btn-icon"><i class="bi bi-plug-fill" style="font-size:6rem;color:#0d6efd;"></i></div>
            <span>Test API FFTT</span>
            <span class="btn-desc">Vérifier la connexion à l'API FFTT</span>
        </a>
        <?php endif; ?>

        <?php if ($isChautard): ?>
        <a href="<?= site_url('db-admin') ?>" class="menu-btn btn-dbadmin" style="background: repeating-linear-gradient(45deg, #fce4ec, #fce4ec 10px, #ffcdd2 10px, #ffcdd2 20px); border: 2px solid #c62828;">
            <span class="btn-code">E099</span>
            <div class="btn-icon"><img src="<?= base_url('img/database.png') ?>" alt="Base de données"></div>
            <span>Base de données</span>
            <span class="btn-desc">Administration directe de la base de données</span>
        </a>
        <?php endif; ?>

        <a href="<?= site_url('logout') ?>" id="lnk-logout" class="menu-btn" style="background:#f8d7da; grid-column: 6;">
            <div class="btn-icon"><i class="bi bi-box-arrow-right" style="color:#842029;"></i></div>
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

    <script>
    'use strict';

    $('#lnk-logout').on('click', function (e) {
        e.preventDefault();
        const href = this.href;
        nijacConfirm('Voulez-vous vous déconnecter ?', function () {
            window.location.href = href;
        }, null, { type: 'question', title: 'Déconnexion', confirmLabel: 'Se déconnecter' });
    });
    </script>
</body>
</html>
