<?php
session_start();

// Accès : tout utilisateur connecté (nominateur ou admin)
if (!isset($_SESSION['utilisateur'])) {
    header('Location: index.php');
    exit;
}

$u = $_SESSION['utilisateur'];

$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
$isAdmin     = !empty($u['is_admin']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Menu nominateur</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
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

        /* ── Barre d'outils ── */
        #toolbar {
            background: #c0ffff;
            border-bottom: 1px solid #90cccc;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            gap: .75rem;
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

        /* Bouton bascule menu admin (visible admins seulement) */
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

        #btn-switch-admin:hover {
            background: #0f2550;
            color: #fff;
        }

        /* ── En-tête de page ── */
        #page-header {
            background: #2e7d32;
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

        /* Couleurs */
        .btn-nomination   { background-color: #b3e5fc; }
        .btn-ja           { background-color: #fff9c4; }
        .btn-competition  { background-color: #8080ff; }
        .btn-planning     { background-color: #c8e6c9; }
        .btn-rapport      { background-color: #ffe0b2; }
        .btn-messagerie   { background-color: #f8bbd0; }

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
        <a class="ts-pwd-warning" href="changer_mot_de_passe.php">
            <i class="bi bi-key-fill"></i>Mot de passe à modifier
        </a>
        <a id="btn-switch-admin" href="admin_menu.php" title="Basculer vers le menu administrateur">
            <i class="bi bi-shield-lock-fill"></i>Menu administrateur
        </a>
    </div>

    <!-- En-tête -->
    <div id="page-header">
        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu nominateur
    </div>

    <!-- Grille -->
    <div id="menu-grid">

        <!-- Ligne 1 -->
        <a href="encours.php" class="menu-btn btn-nomination">
            <img src="img/JA.png" alt="Nomination JA">
            <span>Nomination JA</span>
        </a>

        <a href="jugearbitre.php" class="menu-btn btn-ja">
            <img src="img/Arbitre.png" alt="Juge-Arbitre">
            <span>Juge-Arbitre</span>
        </a>

        <a href="encours.php" class="menu-btn btn-competition">
            <img src="img/Competition.png" alt="Compétition">
            <span>Compétition</span>
        </a>

        <a href="encours.php" class="menu-btn btn-planning">
            <img src="img/Phases.png" alt="Planning">
            <span>Planning</span>
        </a>

        <!-- Ligne 2 -->
        <a href="encours.php" class="menu-btn btn-rapport">
            <img src="img/MiseaJour.png" alt="Rapports">
            <span>Rapports</span>
        </a>

        <a href="encours.php" class="menu-btn btn-messagerie">
            <img src="img/Correspondant.png" alt="Messagerie">
            <span>Messagerie</span>
        </a>

        <!-- Déconnexion -->
        <a href="logout.php" class="menu-btn" style="background:#f8d7da; grid-column: 4;">
            <i class="bi bi-box-arrow-right" style="font-size:5rem; color:#842029;"></i>
            <span style="color:#842029;">Se déconnecter</span>
        </a>

    </div>

    <!-- Pied de page -->
    <div id="page-footer">
        &copy; <?= date('Y') ?> NIJAC &mdash; Tous droits réservés
    </div>

    <script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/bootstrap.bundle.min.js"></script>
    <script>
    'use strict';
    $('a[href="logout.php"]').on('click', function (e) {
        if (!confirm('Voulez-vous vous déconnecter ?')) {
            e.preventDefault();
        }
    });
    </script>

</body>
</html>
