<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Mot de passe oublié (E007)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        :root { --nijac-blue-light: #2557a7; }
        body {
            background: linear-gradient(135deg, #e8eef7 0%, #c8d8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .pwd-card {
            width: 100%;
            max-width: 460px;
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(26, 58, 107, 0.18);
            overflow: hidden;
        }
        .pwd-header { background: var(--nijac-blue); color: #fff; padding: 1rem 1.5rem; }
        .pwd-header h5 { font-size: .95rem; margin: 0; font-weight: 600; }
        .pwd-body { padding: 1.75rem 1.75rem 1.25rem; }
        .form-label { font-size: .85rem; font-weight: 600; color: #374151; margin-bottom: .25rem; }
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
        #lbl-status { font-weight: 600; font-size: .875rem; min-height: 1.25rem; text-align: center; }
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

<div class="pwd-card card">

    <div class="pwd-header">
        <h5><i class="bi bi-key-fill me-2"></i>Mot de passe oublié <small class="opacity-75">(E007)</small></h5>
    </div>

    <div class="pwd-body">

        <?php if (!$envoye): ?>
        <p class="text-muted mb-3" style="font-size:.85rem;">
            Indiquez votre identifiant de connexion ou l'adresse email de votre compte.
            Un lien de réinitialisation vous sera envoyé par email.
        </p>

        <form method="POST" action="<?= site_url('mot-de-passe-oublie') ?>" novalidate>
            <?= csrf_field() ?>

            <div class="mb-3">
                <label for="identifiant" class="form-label">Identifiant ou email :</label>
                <input type="text" class="form-control" id="identifiant" name="identifiant" autocomplete="username" autofocus>
            </div>

            <div id="lbl-status" class="mb-3 <?= $statutClass ?>">
                <i class="bi bi-info-circle me-1"></i><?= esc($status) ?>
            </div>

            <button type="submit" class="btn btn-valider w-100">
                <i class="bi bi-envelope-arrow-up me-1"></i>Envoyer le lien
            </button>
        </form>
        <?php else: ?>
            <div id="lbl-status" class="mb-3 <?= $statutClass ?>">
                <i class="bi bi-check-circle-fill me-1"></i><?= esc($status) ?>
            </div>
            <a href="<?= site_url('login') ?>" class="btn btn-valider w-100">
                <i class="bi bi-arrow-right-circle me-1"></i>Retour à la connexion
            </a>
        <?php endif; ?>

    </div>

    <div class="pwd-footer">
        <span>NIJAC</span>
        <a href="<?= site_url('login') ?>" class="text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>Connexion
        </a>
    </div>

</div>

<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
</body>
</html>
