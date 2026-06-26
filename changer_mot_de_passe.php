<?php
/**
 * NIJAC – Changement du mot de passe
 *
 * Accessible depuis toutes les pages connectées via le lien
 * "Mot de passe à modifier" du bandeau utilisateur (toolbar).
 * Demande le mot de passe actuel puis le nouveau (avec confirmation),
 * met à jour le hash en base et réinitialise le flag ChangeLogin.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-16
 */
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/Classes/SecurePasswordHasher.php';

require __DIR__ . '/includes/auth_required.php';

$moi      = $_SESSION['utilisateur'];
$retour   = !empty($moi['is_admin']) ? 'admin_menu.php' : 'Nominateur/menu.php';
$isAjax   = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$status       = !empty($moi['change_login'])
    ? 'Pour des raisons de sécurité, vous devez changer votre mot de passe.'
    : 'Modifiez votre mot de passe ci-dessous.';
$statut_class = !empty($moi['change_login']) ? 'text-warning' : 'text-secondary';
$succes       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify(false);

    $actuel   = trim($_POST['mdp_actuel']   ?? '');
    $nouveau  = trim($_POST['mdp_nouveau']  ?? '');
    $confirme = trim($_POST['mdp_confirme'] ?? '');

    if ($actuel === '' || $nouveau === '' || $confirme === '') {
        $status       = 'Veuillez remplir tous les champs.';
        $statut_class = 'text-warning';
    } elseif (strlen($nouveau) < 8) {
        $status       = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        $statut_class = 'text-warning';
    } elseif ($nouveau !== $confirme) {
        $status       = 'Les deux saisies du nouveau mot de passe ne correspondent pas.';
        $statut_class = 'text-warning';
    } else {
        try {
            $pdo  = getPDO();
            $stmt = $pdo->prepare('SELECT Password FROM Utilisateur WHERE Id_Utilisateur = ? LIMIT 1');
            $stmt->execute([$moi['id']]);
            $row = $stmt->fetch();

            if (!$row || !SecurePasswordHasher::verify($actuel, $row['Password'])) {
                $status       = 'Mot de passe actuel incorrect.';
                $statut_class = 'text-danger';
            } else {
                $hash = SecurePasswordHasher::hash($nouveau);
                $pdo->prepare('UPDATE Utilisateur SET Password = ?, ChangeLogin = 0 WHERE Id_Utilisateur = ?')
                    ->execute([$hash, $moi['id']]);

                $_SESSION['utilisateur']['change_login'] = false;

                $status       = 'Mot de passe modifié avec succès.';
                $statut_class = 'text-success';
                $succes       = true;
            }
        } catch (PDOException $e) {
            $status       = 'Erreur système : impossible de contacter la base de données.';
            $statut_class = 'text-danger';
            error_log('[NIJAC] PDOException changer_mot_de_passe : ' . $e->getMessage());
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $succes, 'msg' => $status, 'retour' => $retour]);
        exit;
    }
}

// ── Réponse partielle (AJAX / modale) : uniquement le contenu du formulaire ───
if ($isAjax) {
    ?>
        <p class="text-muted mb-3" style="font-size:.85rem;">
            <i class="bi bi-person-fill me-1"></i>Connecté en tant que
            <strong><?= htmlspecialchars(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')) ?></strong>
        </p>

        <form method="POST" action="" id="form-mdp" novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="mdp_actuel" class="form-label">Mot de passe actuel :</label>
                <input type="password" class="form-control" id="mdp_actuel" name="mdp_actuel" autocomplete="current-password" autofocus>
            </div>

            <div class="mb-3">
                <label for="mdp_nouveau" class="form-label">Nouveau mot de passe :</label>
                <input type="password" class="form-control" id="mdp_nouveau" name="mdp_nouveau" autocomplete="new-password" minlength="8">
                <small class="text-muted" style="font-size:.78rem;">8 caractères minimum.</small>
            </div>

            <div class="mb-3">
                <label for="mdp_confirme" class="form-label">Confirmer le nouveau mot de passe :</label>
                <input type="password" class="form-control" id="mdp_confirme" name="mdp_confirme" autocomplete="new-password" minlength="8">
            </div>

            <div id="lbl-status" class="mb-3 <?= $statut_class ?>">
                <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($status) ?>
            </div>

            <button type="submit" class="btn w-100" style="background-color:#1a3a6b;border-color:#1a3a6b;color:#fff;font-weight:600;">
                <i class="bi bi-check-circle me-1"></i>Valider
            </button>
        </form>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Changer le mot de passe</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; --nijac-blue-light: #2557a7; }

        body {
            background: linear-gradient(135deg, #e8eef7 0%, #c8d8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .pwd-card {
            width: 460px;
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(26, 58, 107, 0.18);
            overflow: hidden;
        }

        .pwd-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: 1rem 1.5rem;
        }

        .pwd-header h5 {
            font-size: .95rem;
            margin: 0;
            font-weight: 600;
        }

        .pwd-body { padding: 1.75rem 1.75rem 1.25rem; }

        .form-label {
            font-size: .85rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .25rem;
        }

        .form-control { font-size: .9rem; }
        .form-control:focus {
            border-color: var(--nijac-blue-light);
            box-shadow: 0 0 0 .2rem rgba(37, 87, 167, .2);
        }

        .btn-valider {
            background-color: var(--nijac-blue);
            border-color: var(--nijac-blue);
            color: #fff;
            font-weight: 600;
        }
        .btn-valider:hover { background-color: var(--nijac-blue-light); border-color: var(--nijac-blue-light); color: #fff; }

        #lbl-status { font-weight: 600; font-size: .875rem; min-height: 1.25rem; }

        .pwd-footer {
            background: #f1f5fb;
            padding: .6rem 1.75rem;
            font-size: .75rem;
            color: #6b7280;
            border-top: 1px solid #dde5f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<div class="pwd-card card">

    <div class="pwd-header">
        <h5><i class="bi bi-key-fill me-2"></i>Changer le mot de passe</h5>
    </div>

    <div class="pwd-body">

        <p class="text-muted mb-3" style="font-size:.85rem;">
            <i class="bi bi-person-fill me-1"></i>Connecté en tant que
            <strong><?= htmlspecialchars(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')) ?></strong>
        </p>

        <?php if (!$succes): ?>
        <form method="POST" action="changer_mot_de_passe.php" id="form-mdp" novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="mdp_actuel" class="form-label">Mot de passe actuel :</label>
                <input type="password" class="form-control" id="mdp_actuel" name="mdp_actuel" autocomplete="current-password" autofocus>
            </div>

            <div class="mb-3">
                <label for="mdp_nouveau" class="form-label">Nouveau mot de passe :</label>
                <input type="password" class="form-control" id="mdp_nouveau" name="mdp_nouveau" autocomplete="new-password" minlength="8">
                <small class="text-muted" style="font-size:.78rem;">8 caractères minimum.</small>
            </div>

            <div class="mb-3">
                <label for="mdp_confirme" class="form-label">Confirmer le nouveau mot de passe :</label>
                <input type="password" class="form-control" id="mdp_confirme" name="mdp_confirme" autocomplete="new-password" minlength="8">
            </div>

            <div id="lbl-status" class="mb-3 <?= $statut_class ?>">
                <i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($status) ?>
            </div>

            <button type="submit" class="btn btn-valider w-100">
                <i class="bi bi-check-circle me-1"></i>Valider
            </button>
        </form>
        <?php else: ?>
            <div id="lbl-status" class="mb-3 <?= $statut_class ?>">
                <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars($status) ?>
            </div>
            <a href="<?= htmlspecialchars($retour) ?>" class="btn btn-valider w-100">
                <i class="bi bi-arrow-right-circle me-1"></i>Continuer
            </a>
        <?php endif; ?>

    </div>

    <div class="pwd-footer">
        <span>NIJAC</span>
        <a href="<?= htmlspecialchars($retour) ?>" class="text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>Retour au menu
        </a>
    </div>

</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
