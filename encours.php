<?php
/**
 * NIJAC – Page "En cours de développement" (E013)
 *
 * Page générique affichée lorsqu'une fonctionnalité est en cours de réalisation.
 * Utilisée comme cible temporaire pour les écrans saison.php et configuration.php
 * en attendant leur implémentation.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – En cours de développement</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ── Toolbar ── */
        #toolbar {
            background: #c0ffff;
            border-bottom: 1px solid #90cccc;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            flex-shrink: 0;
        }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-screen-id {
            font-size: .78rem; font-weight: 700;
            color: #1a3a6b; background: #ddeeff;
            padding: .1rem .45rem; border-radius: 4px;
            border: 1px solid #99bbdd; letter-spacing: .03em;
        }
        #toolbar .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center; gap: .35rem;
            color: #c00; font-weight: 700;
            cursor: pointer; text-decoration: underline dotted;
        }

        /* ── Corps ── */
        #main-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2rem;
        }

        #main-body img {
            max-width: 420px;
            width: 60%;
            object-fit: contain;
        }
    </style>
</head>
<body>

<!-- Toolbar -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
    </span>
    <a class="ts-pwd-warning" href="changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
    <span class="ts-screen-id">(E013)</span>
</div>

<!-- Corps -->
<div id="main-body">
    <img src="img/EnCours.png" alt="En cours de développement">
    Le développement de cette fonctionnalité n'est pas encore terminé.<br />Veuillez revenir plus tard pour découvrir cette nouveauté !
    <a href="admin_menu.php" class="btn btn-primary">
        <i class="bi bi-arrow-left me-2"></i>Retour au menu
    </a>
</div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
</body>
</html>
