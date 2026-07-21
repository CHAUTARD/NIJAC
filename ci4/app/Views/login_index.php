<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Nomination Informatisé des JA (E001)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        :root {
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

        .form-panel {
            flex: 1;
            padding: 1.75rem 1.75rem 1.25rem;
        }

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

        #caps-warning {
            display: none;
            font-size: .8rem;
            font-style: italic;
        }

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

        #lbl-status {
            font-weight: 600;
            font-size: .875rem;
            min-height: 1.25rem;
        }

        .login-footer {
            background: #f1f5fb;
            padding: .6rem 1.75rem;
            font-size: .75rem;
            color: #6b7280;
            border-top: 1px solid #dde5f0;
            display: flex;
            justify-content: center;
        }
    </style>
</head>
<body>

<div id="bandeau-fftt">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<div class="login-card card">

    <div class="login-header">
        <h5><i class="bi bi-person-badge me-2"></i>NIJAC &mdash; Nomination Informatisée des JA &mdash; Championnat <small class="opacity-75">(E001)</small></h5>
    </div>

    <div class="login-body card-body p-0">

        <div class="form-panel">
            <form method="POST" action="<?= site_url('login') ?>" id="form-login" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="login" class="form-label">Nom de login utilisateur :</label>
                    <input
                        type="text"
                        class="form-control"
                        id="login"
                        name="login"
                        value="<?= htmlspecialchars($loginValue) ?>"
                        autocomplete="username"
                        autofocus
                    >
                    <div class="form-text">JA : indiquez votre numéro de licence FFTT.</div>
                </div>

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
                    <div class="form-text">JA : indiquez votre nom de famille.</div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-login flex-fill" id="btn-login">
                        <img src="<?= base_url('img/se-connecter.png') ?>" alt="" width="20" height="20" class="me-1">Se connecter
                    </button>
                    <button type="button" class="btn btn-outline-secondary flex-fill" id="btn-cancel">
                        <img src="<?= base_url('img/Annuler_32.png') ?>" alt="" width="20" height="20" class="me-1">Annuler
                    </button>
                </div>

                <div class="mt-3">
                    <span id="lbl-status" class="<?= $statutClass ?>"><?= htmlspecialchars($status) ?></span>
                </div>

            </form>
        </div>

        <div class="img-panel">
            <img src="<?= base_url('img/Arbitre_filet.png') ?>" alt="Arbitre">
            <img src="<?= base_url('img/logo_region.png') ?>" alt="Ligue Normandie de Tennis de Table" class="logo-normandie">
        </div>

    </div>

    <div class="login-footer">
        <span>&copy; <?= date('Y') ?> &mdash; Ligue Normandie de Tennis de Table &mdash; Version&nbsp;: <?= defined('APP_VERSION') ? APP_VERSION : '' ?></span>
    </div>

</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>

<script>
'use strict';

$(function () {

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

    function checkCapsLock(e) {
        const capsOn = e.getModifierState && e.getModifierState('CapsLock');
        $('#caps-warning').toggle(!!capsOn);
    }

    $('#password').on('keyup focus', function (e) { checkCapsLock(e.originalEvent); });
    $('#password').on('blur',        function ()  { $('#caps-warning').hide(); });

    $('#login, #password').on('keypress', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#form-login').trigger('submit');
        }
    });

    $('#form-login').on('submit', function () {
        const login = $.trim($('#login').val());
        const pwd   = $.trim($('#password').val());

        if (login === '' || pwd === '') {
            setStatus('Veuillez remplir tous les champs.', 'text-warning');
            return false;
        }

        const $btn = $('#btn-login');
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Vérification...');
        setStatus('Hachage et comparaison en cours…', 'text-primary');
        return true;
    });

    $('#btn-cancel').on('click', function () {
        $('#login').val('');
        $('#password').val('');
        setStatus('Prêt.', 'text-secondary');
        $('#login').trigger('focus');
    });

    function setStatus(msg, cssClass) {
        $('#lbl-status')
            .text(msg)
            .removeClass('text-warning text-danger text-primary text-success text-secondary')
            .addClass(cssClass);
    }

});
</script>
</body>
</html>
