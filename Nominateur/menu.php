<?php
/**
 * NIJAC – Menu nominateur (E020)
 *
 * Menu principal pour les nominateurs. Donne accès aux fonctions de nomination :
 * gestion des Juges-Arbitres, saisie des disponibilités par département,
 * interface de nomination et consultation des convocations.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();

// Accès : tout utilisateur connecté (nominateur ou admin)
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../index.php');
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
    <title>NIJAC – Menu Nominateur (E020)</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">

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

        #toolbar .ts-screen-id {
            font-size: .78rem; font-weight: 700;
            color: #1a3a6b; background: #ddeeff;
            padding: .1rem .45rem; border-radius: 4px;
            border: 1px solid #99bbdd; letter-spacing: .03em;
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

        /* Couleurs */
        .btn-nomination   { background-color: #b3e5fc; }
        .btn-ja           { background-color: #fff9c4; }
        .btn-competition  { background-color: #8080ff; }
        .btn-planning     { background-color: #c8e6c9; }
        .btn-rapport      { background-color: #ffe0b2; }
        .btn-messagerie   { background-color: #f8bbd0; }
        .btn-convocation  { background-color: #fff3e0; }
        .btn-disponibilite { background-color: #e8f5e9; }

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
        <a class="ts-pwd-warning" href="../changer_mot_de_passe.php">
            <i class="bi bi-key-fill"></i>Mot de passe à modifier
        </a>
        <a id="btn-switch-admin" href="../admin_menu.php" title="Basculer vers le menu administrateur">
            <i class="bi bi-shield-lock-fill"></i>Menu administrateur
        </a>
    </div>

    <!-- En-tête -->
    <div id="page-header">
        <i class="bi bi-grid-3x3-gap-fill me-2"></i>Menu nominateur &nbsp;<small class="opacity-75">(E020)</small>
    </div>

    <!-- Grille -->
    <div id="menu-grid">

        <!-- Ligne 1 -->
        <a href="../jugearbitre.php" class="menu-btn btn-ja">
            <div class="btn-icon"><img src="../img/Arbitre_filet.png" alt="Juge-Arbitre"></div>
            <span>Juge-Arbitre</span>
            <span class="btn-desc">Gérer la liste des juges-arbitres, grades et coordonnées</span>
        </a>

        <a href="disponibilites.php" class="menu-btn" style="background-color:#e8eaf6;">
            <div class="btn-icon"><img src="../img/Dispo.png" alt="Disponibilités JA"></div>
            <span>Disponibilités JA</span>
            <span class="btn-desc">Saisir ou modifier les disponibilités d'un JA par département</span>
        </a>

        <a href="nomination.php" class="menu-btn btn-nomination">
            <div class="btn-icon"><img src="../img/Nomination.png" alt="Nomination JA"></div>
            <span>Nomination JA</span>
            <span class="btn-desc">Affecter les JA aux rencontres et valider les nominations</span>
        </a>

        <!-- Ligne 2 -->

        <a href="../encours.php" class="menu-btn btn-messagerie">
            <div class="btn-icon"><img src="../img/Correspondant.png" alt="Messagerie"></div>
            <span>Messagerie</span>
            <span class="btn-desc">Envoyer des messages aux JA et correspondants de club</span>
        </a>

        <!-- Déconnexion -->
        <a href="../logout.php" class="menu-btn" style="background:#f8d7da; grid-column: 4;">
            <div class="btn-icon"><i class="bi bi-box-arrow-right" style="color:#842029;"></i></div>
            <span style="color:#842029;">Se déconnecter</span>
            <span class="btn-desc" style="color:#842029;">Fermer la session en cours</span>
        </a>

    </div>

    <!-- Pied de page -->
    <div id="page-footer">
        &copy; <?= date('Y') ?> NIJAC &mdash; Tous droits réservés
    </div>

    <script src="../asset/js/jquery-3.7.1.min.js"></script>
    <script src="../asset/js/bootstrap.bundle.min.js"></script>
    <script>
    'use strict';
    $('a[href="../logout.php"]').on('click', function (e) {
        if (!confirm('Voulez-vous vous déconnecter ?')) {
            e.preventDefault();
        }
    });
    </script>

</body>
</html>
