<?php
/**
 * NIJAC – Page de connexion (E001)
 *
 * Point d'entrée de l'application : saisie du login et du mot de passe.
 * Vérifie les identifiants en base, initialise la session et redirige
 * l'utilisateur vers le menu adapté à son rôle (Administrateur ou Nominateur).
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/Classes/SecurePasswordHasher.php';

// Si déjà connecté, rediriger selon le rôle
if (isset($_SESSION['utilisateur'])) {
    $role = $_SESSION['utilisateur']['role'];
    if ($role === 'Administrateur') {
        header('Location: admin_menu.php');
    } elseif ($role === 'JA') {
        header('Location: JA/info_rencontre.php');
    } else {
        header('Location: Nominateur/menu.php');
    }
    exit;
}

$status       = 'Prêt.';
$statut_class = 'text-secondary';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify(false);
    $login    = trim($_POST['login']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($login === '' || $password === '') {
        $status       = 'Veuillez remplir tous les champs.';
        $statut_class = 'text-warning';
    } else {
        try {
            $pdo  = getPDO();

            // Récupération du hash et des infos utilisateur en une seule requête paramétrée
            $stmt = $pdo->prepare(
                'SELECT Id_Utilisateur, Password, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin
                 FROM Utilisateur
                 WHERE Login = :login
                 LIMIT 1'
            );
            $stmt->execute([':login' => $login]);
            $row = $stmt->fetch();


            if ($row && (bool)$row['Actif'] && SecurePasswordHasher::verify($password, $row['Password'])) {
                // Authentification réussie — on purge et recrée la session proprement
                session_unset();
                session_regenerate_id(true);

                $_SESSION['utilisateur'] = [
                    'id'             => $row['Id_Utilisateur'],
                    'login'          => $login,
                    'nom'            => $row['Nom'],
                    'prenom'         => $row['Prenom'],
                    'role'           => $row['Role'],
                    'id_departement' => $row['Id_Departement'],
                    'change_login'   => (bool)$row['ChangeLogin'],
                    'is_admin'       => ($row['Role'] === 'Administrateur'),
                ];

                if ($row['Role'] === 'JA') {
                    $stmtIdJa = $pdo->prepare(
                        'SELECT Id_JA FROM ja WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(:nom)) LIMIT 1'
                    );
                    $stmtIdJa->execute([':nom' => $row['Nom']]);
                    $_SESSION['utilisateur']['id_ja'] = $stmtIdJa->fetchColumn() ?: null;
                }

                $redirect = match ($row['Role']) {
                    'Administrateur' => 'admin_menu.php',
                    'JA'             => 'JA/info_rencontre.php',
                    default          => 'Nominateur/menu.php',
                };
                header('Location: ' . $redirect);
                exit;
            }

            // ── Authentification JA : Nom + numéro de licence ────────────────
            // Étape 1 : le nom existe-t-il dans la table ja ?
            $stmtJaNom = $pdo->prepare(
                'SELECT Id_JA, Nom, Prenom, Email, Grade, Id_Club, SUBSTRING(Id_Club, 3, 2) AS Departement
                 FROM ja
                 WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(:nom)) AND Actif = 1
                 LIMIT 1'
            );
            $stmtJaNom->execute([':nom' => $login]);
            $jaNom = $stmtJaNom->fetch();

            if (!$jaNom) {
                $status       = 'Nom « ' . htmlspecialchars($login) . ' » introuvable dans la liste des JA actifs.';
                $statut_class = 'text-danger';
            } else {
                // Étape 2 : le numéro de licence correspond-il ?
                $stmtJa = $pdo->prepare(
                    'SELECT Id_JA, Nom, Prenom, Email, Grade, Id_Club,
                            SUBSTRING(Id_Club, 3, 2) AS Departement
                     FROM ja
                     WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(:nom)) AND TRIM(Id_JA) = TRIM(:licence) AND Actif = 1
                     LIMIT 1'
                );
                $stmtJa->execute([':nom' => $login, ':licence' => $password]);
                $ja = $stmtJa->fetch();
            }

            if ($jaNom && !isset($ja)) {
                $status       = 'Numéro de licence incorrect pour le JA « ' . htmlspecialchars($jaNom['Nom']) . ' ».';
                $statut_class = 'text-danger';
            }

            if (isset($ja) && $ja) {
                $idDept = $ja['Departement'] ?? '';

                // Vérifier que le club du JA a des rencontres R3M ou R4M à venir
                $stmtCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM rencontre r
                     JOIN division dv  ON dv.Id_Division = r.Id_Division
                     JOIN equipe   ed  ON ed.Id_Equipe   = r.Id_EquipeDom
                     WHERE (dv.ArbitrageCRA = 1 OR ed.JAdemande = 1 OR dv.Division IN (\'R3M\', \'R4M\'))
                       AND ed.Id_Club = :id_club
                       AND r.Date BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY) AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)'
                );
                $stmtCheck->execute([':id_club' => $ja['Id_Club']]);
                $nbRencontres = (int)$stmtCheck->fetchColumn();

                if ($nbRencontres === 0) {
                    // Supprimer l'enregistrement Utilisateur existant s'il avait été créé
                    $pdo->prepare('DELETE FROM Utilisateur WHERE Login = :login AND Role = \'JA\'')
                        ->execute([':login' => $ja['Nom']]);
                    $status       = 'Accès refusé : aucune rencontre R3/R4 pour votre club.';
                    $statut_class = 'text-danger';
                } else {
                    // Vérifier si un compte Utilisateur existe déjà pour ce JA (Login = Nom)
                    $stmtU = $pdo->prepare(
                        'SELECT Id_Utilisateur FROM Utilisateur WHERE Login = :login LIMIT 1'
                    );
                    $stmtU->execute([':login' => $ja['Nom']]);
                    $utilisateur = $stmtU->fetch();

                    if (!$utilisateur) {
                        // Créer le compte Utilisateur JA (login = Nom, password = numéro de licence hashé)
                        $hashedPwd = SecurePasswordHasher::hash($password);
                        $pdo->prepare(
                            'INSERT INTO Utilisateur (Login, Password, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin)
                             VALUES (:login, :pwd, :nom, :prenom, \'JA\', :dept, 1, 0)'
                        )->execute([
                            ':login'  => $ja['Nom'],
                            ':pwd'    => $hashedPwd,
                            ':nom'    => $ja['Nom'],
                            ':prenom' => $ja['Prenom'],
                            ':dept'   => $idDept,
                        ]);
                        $idUtilisateur = (int)$pdo->lastInsertId();
                    } else {
                        $idUtilisateur = (int)$utilisateur['Id_Utilisateur'];
                    }

                    session_unset();
                    session_regenerate_id(true);

                    $_SESSION['utilisateur'] = [
                        'id'             => $idUtilisateur,
                        'login'          => $ja['Nom'],
                        'nom'            => $ja['Nom'],
                        'prenom'         => $ja['Prenom'],
                        'role'           => 'JA',
                        'id_departement' => $idDept,
                        'change_login'   => false,
                        'is_admin'       => false,
                        'id_ja'          => $ja['Id_JA'],
                    ];

                    header('Location: JA/info_rencontre.php');
                    exit;
                }
            }

            if ($status === 'Prêt.') {
                $status       = 'Échec : Identifiants invalides.';
                $statut_class = 'text-danger';
            }

        } catch (PDOException $e) {
            $status       = 'Erreur système : impossible de contacter la base de données.';
            $statut_class = 'text-danger';
            error_log('[NIJAC] PDOException login : ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Nomination Informatisé des JA (E001)</title>

    <!-- Bootstrap 5 (local) -->
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <!-- Bootstrap Icons (local) -->
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

    <style>
        :root {
            --nijac-blue: #1a3a6b;
            --nijac-blue-light: #2557a7;
        }

        body {
            background: linear-gradient(135deg, #e8eef7 0%, #c8d8f0 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding-top: 1.5rem;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        #bandeau-fftt {
            width: 560px;
            margin-bottom: 5rem;
        }
        #bandeau-fftt img {
            width: 100%;
            display: block;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(26,58,107,.2);
        }

        .login-card {
            width: 560px;
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(26, 58, 107, 0.18);
            overflow: hidden;
        }

        .login-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: 1rem 1.5rem;
        }

        .login-header h5 {
            font-size: .95rem;
            margin: 0;
            font-weight: 600;
            letter-spacing: .02em;
        }

        .login-body {
            display: flex;
            gap: 0;
        }

        /* Panneau gauche : formulaire */
        .form-panel {
            flex: 1;
            padding: 1.75rem 1.75rem 1.25rem;
        }

        /* Panneau droit : illustration */
        .img-panel {
            width: 175px;
            min-height: 240px;
            background: #dce8f8;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        .img-panel .logo-normandie {
            position: absolute;
            top: 8px;
            left: 8px;
            height: 38px;
            width: auto;
            filter: drop-shadow(0 1px 2px rgba(0,0,0,.25));
        }

        .img-panel img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .img-panel .img-placeholder {
            color: #90a4c8;
            font-size: 4rem;
        }

        /* Champs */
        .form-label {
            font-size: .85rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .25rem;
        }

        .form-control {
            font-size: .9rem;
        }

        .form-control:focus {
            border-color: var(--nijac-blue-light);
            box-shadow: 0 0 0 .2rem rgba(37, 87, 167, .2);
        }

        /* Avertissement Caps Lock */
        #caps-warning {
            display: none;
            font-size: .8rem;
            font-style: italic;
        }

        /* Boutons */
        .btn-login {
            background-color: var(--nijac-blue);
            border-color: var(--nijac-blue);
            color: #fff;
            font-weight: 600;
        }

        .btn-login:hover:not(:disabled) {
            background-color: var(--nijac-blue-light);
            border-color: var(--nijac-blue-light);
            color: #fff;
        }

        .btn-login:disabled {
            opacity: .65;
            cursor: not-allowed;
        }

        /* Ligne de statut */
        #lbl-status {
            font-weight: 600;
            font-size: .875rem;
            min-height: 1.25rem;
        }

        /* Pied de carte */
        .login-footer {
            background: #f1f5fb;
            padding: .6rem 1.75rem;
            font-size: .75rem;
            color: #6b7280;
            border-top: 1px solid #dde5f0;
        }
    </style>
</head>
<body>

<div id="bandeau-fftt">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="img/FFTT_LIGUE.png" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<div class="login-card card">

    <!-- En-tête -->
    <div class="login-header">
        <h5><i class="bi bi-person-badge me-2"></i>NIJAC &mdash; Nomination Informatisée des JA &mdash; Championnat <small class="opacity-75">(E001)</small></h5>
    </div>

    <!-- Corps : formulaire + image -->
    <div class="login-body card-body p-0">

        <!-- Formulaire -->
        <div class="form-panel">
            <form method="POST" action="index.php" id="form-login" novalidate>
                <?= csrfField() ?>

                <!-- Login -->
                <div class="mb-3">
                    <label for="login" class="form-label">Nom de login utilisateur :</label>
                    <input
                        type="text"
                        class="form-control"
                        id="login"
                        name="login"
                        value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                        autocomplete="username"
                        autofocus
                    >
                </div>

                <!-- Mot de passe -->
                <div class="mb-1">
                    <label for="password" class="form-label">Mot de passe :</label>
                    <div class="input-group">
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            autocomplete="current-password"
                        >
                        <button class="btn btn-outline-secondary" type="button" id="btn-toggle-pwd" tabindex="-1" title="Afficher / masquer le mot de passe">
                            <i id="eye-icon" class="bi bi-eye-slash"></i>
                        </button>
                    </div>
                </div>

                <!-- Boutons -->
                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-login flex-fill" id="btn-login">
                        <img src="img/se-connecter.png" alt="" width="20" height="20" class="me-1">Se connecter
                    </button>
                    <button type="button" class="btn btn-outline-secondary flex-fill" id="btn-cancel">
                        <img src="img/Annuler_32.png" alt="" width="20" height="20" class="me-1">Annuler
                    </button>
                </div>

                <!-- Ligne de statut -->
                <div class="mt-3">
                    <span id="lbl-status" class="<?= $statut_class ?>"><?= htmlspecialchars($status) ?></span>
                </div>

            </form>
        </div>

        <!-- Illustration droite -->
        <div class="img-panel">
            <img src="img/Arbitre_filet.png" alt="Arbitre">
            <img src="img/logo_region.png" alt="Ligue Normandie de Tennis de Table" class="logo-normandie">
        </div>

    </div>

    <!-- Pied de page -->
    <?php $footerBreak = true; require __DIR__ . '/includes/footer.php'; ?>

</div>

<!-- jQuery + Bootstrap JS (local) -->
<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>

<script>
'use strict';

$(function () {

    /* ── 1. Afficher / masquer le mot de passe ── */
    $('#btn-toggle-pwd').on('click', function () {
        const $pwd  = $('#password');
        const $icon = $('#eye-icon');
        if ($pwd.attr('type') === 'password') {
            $pwd.attr('type', 'text');
            $icon.removeClass('bi-eye-slash').addClass('bi-eye');
        } else {
            $pwd.attr('type', 'password');
            $icon.removeClass('bi-eye').addClass('bi-eye-slash');
        }
    });

    /* ── 2. Détection Verr Maj ── */
    function checkCapsLock(e) {
        const capsOn = e.getModifierState && e.getModifierState('CapsLock');
        $('#caps-warning').toggle(!!capsOn);
    }

    $('#password').on('keyup focus', function (e) { checkCapsLock(e.originalEvent); });
    $('#password').on('blur',        function ()  { $('#caps-warning').hide(); });

    /* ── 3. Touche Entrée dans les deux champs ── */
    $('#login, #password').on('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#form-login').trigger('submit');
        }
    });

    /* ── 4. Soumission : feedback visuel ── */
    $('#form-login').on('submit', function () {
        const login = $.trim($('#login').val());
        const pwd   = $.trim($('#password').val());

        if (login === '' || pwd === '') {
            setStatus('Veuillez remplir tous les champs.', 'text-warning');
            return false; // Annule la soumission
        }

        const $btn = $('#btn-login');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Vérification...');
        setStatus('Hachage et comparaison en cours…', 'text-primary');
        return true; // Laisse le formulaire soumettre en POST
    });

    /* ── 5. Bouton Annuler ── */
    $('#btn-cancel').on('click', function () {
        $('#login').val('');
        $('#password').val('');
        setStatus('Prêt.', 'text-secondary');
        $('#login').trigger('focus');
    });

    /* ── Utilitaire : mise à jour du statut ── */
    function setStatus(msg, cssClass) {
        $('#lbl-status')
            .text(msg)
            .removeClass('text-warning text-danger text-primary text-success text-secondary')
            .addClass(cssClass);
    }

});
</script>
