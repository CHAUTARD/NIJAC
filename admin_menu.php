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

// Accès réservé aux administrateurs connectés
if (!isset($_SESSION['utilisateur'])) {
    header('Location: index.php');
    exit;
}

$u = $_SESSION['utilisateur'];

// Vérification du rôle administrateur (adapter selon votre modèle)
if (empty($u['is_admin'])) {
    header('Location: index.php');
    exit;
}

$nomComplet   = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement  = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin  = !empty($u['change_login']); // true = forcer le changement de mot de passe
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
            background: #c0ffff;
            border-bottom: 1px solid #90cccc;
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
            grid-template-columns: repeat(4, 1fr);
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
            font-family: 'Microsoft Sans Serif', 'Segoe UI', sans-serif;
            color: #222;
            transition: filter .15s, transform .1s, box-shadow .15s;
            box-shadow: 2px 2px 6px rgba(0,0,0,.15);
            text-align: center;
            min-height: 190px;
        }

        /* Conteneur image à hauteur fixe — même position pour toutes les cartes */
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
        .btn-club:hover .btn-desc { color: #ddd; }

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

        /* Couleurs individuelles (fidèles au C#) */
        .btn-competition  { background-color: #8080ff; }
        .btn-club         { background-color: #228b22; color: #fff; }
        .btn-salle        { background-color: #c0ffc0; }
        .btn-saison       { background-color: #c0ffc0; }
        .btn-division     { background-color: #ffd700; }
        .btn-utilisateur  { background-color: #ffffc0; }
        .btn-correspondant{ background-color: #ffc080; }
        .btn-communes     { background-color: #ffffc0; }
        .btn-configuration{ background-color: #80ff80; }

        .btn-club:hover   { color: #fff; }

        /* ── Pied de page ── */
        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .5rem 1.25rem;
            font-size: .75rem;
            color: #6b7280;
            text-align: right;
        }
    </style>
</head>
<body>

    <!-- ToolStrip -->
    <div id="toolbar">
        <span class="ts-user">
            <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
        </span>
        <a class="ts-pwd-warning" href="changer_mot_de_passe.php" id="lnk-chg-pwd">
            <i class="bi bi-key-fill"></i>Mot de passe à modifier
        </a>
        <a id="btn-switch-nominateur" href="Nominateur/menu.php" title="Basculer vers le menu nominateur">
            <i class="bi bi-people-fill"></i>Menu nominateur
        </a>
    </div>

    <!-- En-tête -->
    <div id="page-header">
        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu pour les paramètres &nbsp;<small class="opacity-75">(E002)</small>
    </div>

    <!-- Grille 4 × 2 + bouton configuration -->
    <div id="menu-grid">

        <!-- Ligne 1 -->
        <a href="competition.php" class="menu-btn btn-competition">
            <div class="btn-icon"><img src="img/Competition.png" alt="Compétition"></div>
            <span>Type de compétition</span>
            <span class="btn-desc">Créer et paramétrer les types de compétitions</span>
        </a>

        <a href="club.php" class="menu-btn btn-club">
            <div class="btn-icon"><img src="img/Association.png" alt="Club / Association"></div>
            <span>Club / Association</span>
            <span class="btn-desc" style="color:#ccc;">Gérer les clubs et associations affiliés</span>
        </a>

        <a href="salle.php" class="menu-btn btn-salle">
            <div class="btn-icon"><img src="img/Salle.png" alt="Salle"></div>
            <span>Salle</span>
            <span class="btn-desc">Référencer les salles de compétition et leur adresse</span>
        </a>

        <a href="clean.php" class="menu-btn btn-saison">
            <div class="btn-icon"><img src="img/Nettoyage.png" alt="Saison"></div>
            <span>Saison</span>
            <span class="btn-desc">Suppression des informations sur la saison dernière</span>
        </a>

        <!-- Ligne 2 -->
        <a href="utilisateur.php" class="menu-btn btn-utilisateur">
            <div class="btn-icon"><img src="img/Utilisateur.png" alt="Utilisateur"></div>
            <span>Utilisateur</span>
            <span class="btn-desc">Gérer les comptes et droits d'accès</span>
        </a>

        <a href="correspondant.php" class="menu-btn btn-correspondant">
            <div class="btn-icon"><img src="img/Correspondant.png" alt="Correspondant Club"></div>
            <span>Correspondant Club</span>
            <span class="btn-desc">Contacts référents de chaque club</span>
        </a>

        <a href="communes.php" class="menu-btn btn-communes">
            <div class="btn-icon"><img src="img/La_Poste.png" alt="Communes"></div>
            <span>Communes</span>
            <span class="btn-desc">Base des codes postaux et coordonnées GPS</span>
        </a>

        <a href="division.php" class="menu-btn btn-division">
            <div class="btn-icon"><img src="img/podium.png" alt="Division"></div>
            <span>Division</span>
            <span class="btn-desc">Définir les divisions et leur niveau</span>
        </a>

        <!-- Ligne 3 -->
        <a href="configuration.php" class="menu-btn btn-configuration">
            <div class="btn-icon"><img src="img/Parametres.png" alt="Configuration"></div>
            <span>Configuration</span>
            <span class="btn-desc">Paramètres généraux de l'application</span>
        </a>

        <a href="jugearbitre.php" class="menu-btn btn-configuration">
            <div class="btn-icon"><img src="img/JA.png" alt="Juge Arbitre"></div>
            <span>Juge Arbitre</span>
            <span class="btn-desc">Importer et gérer les fiches JA</span>
        </a>

        <a href="import_rencontres.php" class="menu-btn" style="background-color:#e8f5e9;">
            <div class="btn-icon"><img src="img/Competition.png" alt="Import Rencontres"></div>
            <span>Import Rencontres</span>
            <span class="btn-desc">Importer les rencontres depuis un fichier FFTT</span>
        </a>

        <!--
        <a href="disponibilite_ja.php" class="menu-btn" style="background-color:#e8eaf6;">
            <div class="btn-icon"><img src="img/Arbitre.png" alt="Disponibilités JA"></div>
            <span>Disponibilités JA</span>
            <span class="btn-desc">Consulter les disponibilités déclarées par les JA</span>
        </a>
        -->

        <!-- Déconnexion -->
        <a href="logout.php" class="menu-btn" style="background:#f8d7da; grid-column: 4;">
            <div class="btn-icon"><i class="bi bi-box-arrow-right" style="color:#842029;"></i></div>
            <span style="color:#842029;">Se déconnecter</span>
            <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
        </a>

    </div>

    <!-- Pied de page -->
    <div id="page-footer">
        &copy; <?= date('Y') ?> NIJAC &mdash; Tous droits réservés
    </div>

    <!-- jQuery + Bootstrap JS (local) -->
    <script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/bootstrap.bundle.min.js"></script>

    <script>
    'use strict';

    // Confirmation avant déconnexion
    $('a[href="logout.php"]').on('click', function (e) {
        if (!confirm('Voulez-vous vous déconnecter ?')) {
            e.preventDefault();
        }
    });
    </script>

</body>
</html>
