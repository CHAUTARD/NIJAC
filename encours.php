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

<?php require __DIR__ . '/includes/toolbar.php'; ?>

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
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
