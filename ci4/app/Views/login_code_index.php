<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Code de sécurité (E010)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        /* Charte recopiée de login_index.php (E001) */
        :root { --nijac-blue-light: #2557a7; }

        body {
            background: linear-gradient(135deg, #e8eef7 0%, #c8d8f0 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 1.5rem 1rem 1rem;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        #bandeau-fftt { width: 100%; max-width: 560px; margin-bottom: 5rem; }
        #bandeau-fftt img { width: 100%; display: block; border-radius: 8px; box-shadow: 0 2px 8px rgba(26,58,107,.2); }

        .login-card { width: 100%; max-width: 560px; border: none; border-radius: 12px; box-shadow: 0 8px 32px rgba(26, 58, 107, 0.18); overflow: hidden; }
        .login-header { background: var(--nijac-blue); color: #fff; padding: 1rem 1.5rem; }
        .login-header h5 { font-size: .95rem; margin: 0; font-weight: 600; letter-spacing: .02em; }
        .form-panel { padding: 1.75rem 1.75rem 1.25rem; }
        .form-label { font-size: .85rem; font-weight: 600; color: #374151; margin-bottom: .25rem; }

        #code {
            font-size: 1.6rem;
            letter-spacing: .5em;
            text-align: center;
            font-variant-numeric: tabular-nums;
        }
        #code:focus { border-color: var(--nijac-blue-light); box-shadow: 0 0 0 .2rem rgba(37, 87, 167, .2); }

        .btn-login { background-color: var(--nijac-blue); border-color: var(--nijac-blue); color: #fff; font-weight: 600; }
        .btn-login:hover:not(:disabled) { background-color: var(--nijac-blue-light); border-color: var(--nijac-blue-light); color: #fff; }
        .btn-login:disabled { opacity: .65; cursor: not-allowed; }
        #lbl-status { font-weight: 600; }

        .login-footer {
            background: #f1f5fb; padding: .6rem 1.75rem; font-size: .75rem; color: #6b7280;
            border-top: 1px solid #dde5f0; display: flex; justify-content: center; text-align: center;
        }

        @media (max-width: 575.98px) {
            .form-panel { padding: 1.5rem 1.25rem 1.25rem; }
            .login-header { padding: .85rem 1.25rem; }
            .login-header h5 { font-size: .85rem; }
            #bandeau-fftt { margin-bottom: 2rem; }
        }
    </style>
</head>
<body>

<?php
$alerte = match ($statutClass ?? '') {
    'text-danger'  => 'alert-danger',
    'text-success' => 'alert-success',
    default        => 'alert-warning',
};
?>

<div id="bandeau-fftt">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<div class="login-card card">

    <div class="login-header">
        <h5><i class="bi bi-shield-lock me-2"></i>NIJAC &mdash; Code de sécurité <small class="opacity-75">(E010)</small></h5>
    </div>

    <div class="form-panel">
        <p class="small text-muted mb-3">
            Un code de sécurité à 6 chiffres vient d'être envoyé à l'adresse email enregistrée sur votre compte.
            Il est valable 10 minutes.
        </p>

        <form method="POST" action="<?= site_url('login/code') ?>" id="form-code" novalidate>
            <?= csrf_field() ?>

            <div id="lbl-status" role="alert" class="alert <?= $alerte ?> py-2 px-3 small mb-3"<?= $status === '' ? ' hidden' : '' ?>><?= esc($status) ?></div>

            <label for="code" class="form-label">Code de sécurité :</label>
            <input type="text" class="form-control" id="code" name="code"
                   inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                   autocomplete="one-time-code" required autofocus>

            <button type="submit" class="btn btn-login w-100 mt-3" id="btn-valider">
                <i class="bi bi-check2-circle me-1"></i>Valider
            </button>
        </form>

        <form method="POST" action="<?= site_url('login/code/renvoi') ?>" class="mt-2 text-center">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-link btn-sm text-decoration-none" id="btn-renvoi"
                    data-attente="<?= (int) $attente ?>"<?= $attente > 0 ? ' disabled' : '' ?>>
                Recevoir un nouveau code<span id="lbl-attente"></span>
            </button>
            <span class="mx-1 text-muted">·</span>
            <a href="<?= site_url('logout') ?>" class="text-decoration-none small">Annuler</a>
        </form>
    </div>

    <div class="login-footer">
        <span>&copy; <?= date('Y') ?> &mdash; Ligue Normandie de Tennis de Table &mdash; Version&nbsp;: <?= defined('APP_VERSION') ? APP_VERSION : '' ?></span>
    </div>

</div>

<script>
'use strict';
(function () {
    const champ = document.getElementById('code');
    // Chiffres uniquement (espaces et autres caractères retirés à la saisie / au collage)
    champ.addEventListener('input', function () {
        const v = champ.value.replace(/\D/g, '').slice(0, 6);
        if (v !== champ.value) champ.value = v;
    });

    document.getElementById('form-code').addEventListener('submit', function (e) {
        if (!/^[0-9]{6}$/.test(champ.value)) {
            e.preventDefault();
            const s = document.getElementById('lbl-status');
            s.textContent = 'Le code doit contenir 6 chiffres.';
            s.className = 'alert alert-warning py-2 px-3 small mb-3';
            s.hidden = false;
            champ.focus();
            return;
        }
        document.getElementById('btn-valider').disabled = true;
    });

    // Compte à rebours avant un nouvel envoi possible (contrôlé de toute façon côté serveur)
    const btn = document.getElementById('btn-renvoi');
    const lbl = document.getElementById('lbl-attente');
    let reste = parseInt(btn.dataset.attente, 10) || 0;
    function tic() {
        if (reste <= 0) { btn.disabled = false; lbl.textContent = ''; return; }
        lbl.textContent = ' (dans ' + reste + ' s)';
        reste--;
        setTimeout(tic, 1000);
    }
    tic();

    // Retour arrière (bfcache) : évite un bouton figé
    window.addEventListener('pageshow', function (e) { if (e.persisted) location.reload(); });
})();
</script>
</body>
</html>
