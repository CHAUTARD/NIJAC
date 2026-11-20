<?php
// Page 404 NIJAC : reprend le bandeau FFTT / Ligue de Normandie et la carte de la page de connexion (E001).
helper('url');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page introuvable – NIJAC (404)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
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
        #bandeau-fftt {
            width: 100%;
            max-width: 560px;
            margin-bottom: 5rem;
        }
        #bandeau-fftt img {
            width: 100%;
            display: block;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(26,58,107,.2);
        }
        .err-card {
            width: 100%;
            max-width: 560px;
            border: none;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(26, 58, 107, 0.18);
            overflow: hidden;
        }
        .err-header {
            background: var(--nijac-blue, #1a3a6b);
            color: #fff;
            padding: 1rem 1.5rem;
        }
        .err-header h5 {
            font-size: .95rem;
            margin: 0;
            font-weight: 600;
            letter-spacing: .02em;
        }
        .err-body { padding: 2rem 1.75rem; text-align: center; }
        .err-code { font-size: 4.5rem; font-weight: 300; line-height: 1; color: var(--nijac-blue, #1a3a6b); }
        .btn-retour {
            background-color: var(--nijac-blue, #1a3a6b);
            border-color: var(--nijac-blue, #1a3a6b);
            color: #fff;
            font-weight: 600;
        }
        .btn-retour:hover { background-color: #2557a7; border-color: #2557a7; color: #fff; }
        .err-footer {
            background: #f1f5fb;
            padding: .6rem 1.75rem;
            font-size: .75rem;
            color: #6b7280;
            border-top: 1px solid #dde5f0;
            text-align: center;
        }
        @media (max-width: 575.98px) {
            #bandeau-fftt { margin-bottom: 2rem; }
            .err-header h5 { font-size: .85rem; }
        }
    </style>
</head>
<body>

<div id="bandeau-fftt">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<div class="err-card card">
    <div class="err-header">
        <h5><i class="bi bi-person-badge me-2"></i>NIJAC &mdash; Nomination Informatisée des JA &mdash; Championnat <small class="opacity-75">(404)</small></h5>
    </div>

    <div class="err-body">
        <div class="err-code">404</div>
        <h6 class="mt-3 mb-2">Page introuvable</h6>
        <p class="text-muted small mb-1">
            <?php if (ENVIRONMENT !== 'production') : ?>
                <?= nl2br(esc($message)) ?>
            <?php else : ?>
                Désolé, la page demandée n'existe pas ou n'existe plus.
            <?php endif; ?>
        </p>
        <a href="<?= site_url('/') ?>" class="btn btn-retour mt-3">
            <i class="bi bi-house-door-fill me-1"></i>Retour à l'accueil
        </a>
    </div>

    <div class="err-footer">
        &copy; <?= date('Y') ?> Patrick CHAUTARD &mdash; Ligue Normandie de Tennis de Table<?= defined('APP_VERSION') ? ' &mdash; Version&nbsp;: ' . APP_VERSION : '' ?>
    </div>
</div>

</body>
</html>
