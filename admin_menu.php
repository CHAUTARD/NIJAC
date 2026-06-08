<?php
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
            justify-content: flex-end;
            gap: 10px;
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

        .menu-btn img {
            width: 96px;
            height: 96px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .menu-btn span {
            line-height: 1.25;
        }

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
        <a id="btn-switch-nominateur" href="menu.php" title="Basculer vers le menu nominateur">
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
            <img src="img/Competition.png" alt="Compétition">
            <span>Type de compétition</span>
        </a>

        <a href="club.php" class="menu-btn btn-club">
            <img src="img/Association.png" alt="Club / Association">
            <span>Club / Association</span>
        </a>

        <a href="salle.php" class="menu-btn btn-salle">
            <img src="img/Salle.png" alt="Salle">
            <span>Salle</span>
        </a>

        <a href="saison.php" class="menu-btn btn-saison">
            <img src="img/Phases.png" alt="Saison">
            <span>Saison</span>
        </a>

        <!-- Ligne 2 -->
        <a href="utilisateur.php" class="menu-btn btn-utilisateur">
            <img src="img/Utilisateur.png" alt="Utilisateur">
            <span>Utilisateur</span>
        </a>

        <a href="correspondant.php" class="menu-btn btn-correspondant">
            <img src="img/Correspondant.png" alt="Correspondant Club">
            <span>Correspondant Club</span>
        </a>

        <a href="communes.php" class="menu-btn btn-communes">
            <img src="img/La_Poste.png" alt="Communes">
            <span>Communes</span>
        </a>

        <a href="division.php" class="menu-btn btn-division">
            <img src="img/podium.png" alt="Division">
            <span>Division</span>
        </a>

        <!-- Ligne 3 : Configuration seul + déconnexion -->
        <a href="configuration.php" class="menu-btn btn-configuration">
            <img src="img/Parametres.png" alt="Configuration">
            <span>Configuration</span>
        </a>

        <a href="jugearbitre.php" class="menu-btn btn-configuration">
            <img src="img/ja.png" alt="Juge Arbitre">
            <span>Juge Arbitre</span>
        </a>

        <!-- Déconnexion (pas dans le C# original, mais utile en web) -->
        <a href="logout.php" class="menu-btn" style="background:#f8d7da; grid-column: 4;">
            <i class="bi bi-box-arrow-right" style="font-size:5rem; color:#842029;"></i>
            <span style="color:#842029;">Se déconnecter</span>
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
