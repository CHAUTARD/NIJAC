<?php
/**
 * NIJAC – Menu paramètres administrateur (E002)
 *
 * Menu principal réservé aux administrateurs. Donne accès à tous les écrans
 * de paramétrage de l'application : compétitions, clubs, salles, saisons,
 * utilisateurs, correspondants, communes, divisions, configuration, JA,
 * import des rencontres et disponibilités.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();

require __DIR__ . '/includes/admin_required.php';
$u = $_SESSION['utilisateur'];

$nomComplet   = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement  = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin  = !empty($u['change_login']);
$isAdmin      = !empty($u['is_admin']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Menu paramètres (E002)</title>

    <!-- Bootstrap 5 (local) -->
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <!-- Bootstrap Icons (local) -->
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

    <style>
        :root {
            --nijac-blue: #1a3a6b;
        }

        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Barre d'outils (ToolStrip) ── */
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

        /* Bouton bascule menu nominateur */
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

        /* ── En-tête de page ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .65rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
        }

        /* ── Grille de boutons ── */
        #menu-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 16px;
            padding: 24px;
            flex: 1;
        }

        /* ── Carte bouton ── */
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

        /* Couleurs individuelles */
        .btn-club         { background-color: #e8f5e9; }
        .btn-salle        { background-color: #e0f2f1; }
        .btn-saison       { background-color: #fff8e1; }
        .btn-division     { background-color: #fbe9e7; }
        .btn-r34          { background-color: #ede7f6; }
        .btn-utilisateur  { background-color: #e3f2fd; }
        .btn-correspondant{ background-color: #f3e5f5; }
        .btn-communes     { background-color: #e0f7fa; }
        .btn-configuration{ background-color: #eceff1; }
        .btn-dbadmin      { background-color: #fce4ec; }
        .btn-region       { background-color: #e8eaf6; }
        .btn-departement  { background-color: #e1f5fe; }

        /* Code écran en haut à droite de chaque bouton */
        .menu-btn { position: relative; }
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

    <?php $pageIcon = 'bi-grid-3x3-gap-fill'; $pageTitle = 'Menu pour les paramètres'; $pageCode = 'E002'; $backUrl = null; require __DIR__ . '/includes/page_header.php'; ?>

    <!-- ToolStrip -->
    <div id="toolbar">
        <span class="ts-user">
            <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
        </span>
        <a class="ts-pwd-warning" href="changer_mot_de_passe.php" id="lnk-chg-pwd" data-base="changer_mot_de_passe.php">
            <i class="bi bi-key-fill"></i>Mot de passe à modifier
        </a>
        <a id="btn-switch-nominateur" href="Nominateur/menu.php" title="Basculer vers le menu nominateur">
            <i class="bi bi-people-fill"></i>Menu nominateur
        </a>
    </div>

    <?php require __DIR__ . '/includes/modal_mdp.php'; ?>

    <!-- Grille de boutons -->
    <div id="menu-grid">

        <a href="club.php" class="menu-btn btn-club">
            <span class="btn-code">E008</span>
            <div class="btn-icon"><img src="img/Association.png" alt="Club / Association"></div>
            <span>Club / Association</span>
            <span class="btn-desc">Gérer les clubs et associations affiliés</span>
        </a>

        <a href="salle.php" class="menu-btn btn-salle">
            <span class="btn-code">E005</span>
            <div class="btn-icon"><img src="img/Salle.png" alt="Salle"></div>
            <span>Salle</span>
            <span class="btn-desc">Référencer les salles de compétition et leur adresse</span>
        </a>

        <a href="utilisateur.php" class="menu-btn btn-utilisateur">
            <span class="btn-code">E009</span>
            <div class="btn-icon"><img src="img/Utilisateur.png" alt="Utilisateur"></div>
            <span>Utilisateur</span>
            <span class="btn-desc">Gérer les comptes et droits d'accès</span>
        </a>

        <a href="communes.php" class="menu-btn btn-communes">
            <span class="btn-code">E006</span>
            <div class="btn-icon"><img src="img/La_Poste.png" alt="Communes"></div>
            <span>Communes</span>
            <span class="btn-desc">Base des codes postaux et coordonnées GPS</span>
        </a>

        <a href="division.php" class="menu-btn btn-division">
            <span class="btn-code">E010</span>
            <div class="btn-icon"><img src="img/podium.png" alt="Division"></div>
            <span>Division</span>
            <span class="btn-desc">Définir les divisions et leur niveau</span>
        </a>

        <a href="import_rencontres.php" class="menu-btn btn-club">
            <span class="btn-code">E011</span>
            <div class="btn-icon"><img src="img/Competition.png" alt="Import Rencontres"></div>
            <span>Import Rencontres</span>
            <span class="btn-desc">Importer les rencontres depuis un fichier FFTT</span>
        </a>

        <a href="import_rencontres_nat.php" class="menu-btn btn-club">
            <span class="btn-code">E017</span>
            <div class="btn-icon"><img src="img/ImportExcel_32.png" alt="Import Nationales"></div>
            <span>Import Rencontres Nationales</span>
            <span class="btn-desc">Importer les rencontres de divisions Nationales</span>
        </a>

        <a href="region.php" class="menu-btn btn-region">
            <span class="btn-code">E012</span>
            <div class="btn-icon"><i class="bi bi-map-fill" style="font-size:6rem;color:#3949ab;"></i></div>
            <span>Régions</span>
            <span class="btn-desc">Gérer les régions et leur gentilé</span>
        </a>

        <a href="departement.php" class="menu-btn btn-departement">
            <span class="btn-code">E013</span>
            <div class="btn-icon"><i class="bi bi-geo-alt-fill" style="font-size:6rem;color:#0277bd;"></i></div>
            <span>Départements</span>
            <span class="btn-desc">Gérer les départements et leur région</span>
        </a>

        <a href="clean.php" class="menu-btn btn-saison" style="grid-column: 1;">
            <span class="btn-code">E016</span>
            <div class="btn-icon"><img src="img/Nettoyage.png" alt="Saison"></div>
            <span>Saison</span>
            <span class="btn-desc">Suppression des informations sur la saison dernière</span>
        </a>

        <a href="configuration.php" class="menu-btn btn-configuration">
            <span class="btn-code">E015</span>
            <div class="btn-icon"><img src="img/Parametres.png" alt="Configuration"></div>
            <span>Configuration</span>
            <span class="btn-desc">Paramètres généraux de l'application</span>
        </a>

        <?php if (($_SESSION['utilisateur']['login'] ?? '') === 'CHAUTARD') : ?>
        <a href="fftt_test.php" class="menu-btn btn-configuration">
            <span class="btn-code">E018</span>
            <div class="btn-icon"><i class="bi bi-plug-fill" style="font-size:6rem;color:#0d6efd;"></i></div>
            <span>Test API FFTT</span>
            <span class="btn-desc">Vérifier la connexion à l'API FFTT</span>
        </a>
        <?php endif; ?>

        <?php if (($_SESSION['utilisateur']['login'] ?? '') === 'CHAUTARD') : ?>
        <a href="db-admin.php" class="menu-btn btn-dbadmin" target="_blank" style="background: repeating-linear-gradient(45deg, #fce4ec, #fce4ec 10px, #ffcdd2 10px, #ffcdd2 20px); border: 2px solid #c62828;">
            <span class="btn-code">E099</span>
            <div class="btn-icon"><img src="img/database.png" alt="Base de données"></div>
            <span>Base de données</span>
            <span class="btn-desc">Administration directe de la base de données</span>
        </a>
        <?php endif; ?>

        <a href="logout.php" class="menu-btn" style="background:#f8d7da; grid-column: 5;">
            <div class="btn-icon"><i class="bi bi-box-arrow-right" style="color:#842029;"></i></div>
            <span style="color:#842029;">Se déconnecter</span>
            <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
        </a>

    </div>


    <!-- Pied de page -->

    <!-- jQuery + Bootstrap JS (local) -->
    <script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
    <script src="asset/js/bootstrap.bundle.min.js"></script>

    <script>
    'use strict';

    $('a[href="logout.php"]').on('click', function (e) {
        if (!confirm('Voulez-vous vous déconnecter ?')) {
            e.preventDefault();
        }
    });
    </script>
    <?php require __DIR__ . '/includes/footer.php'; ?>
