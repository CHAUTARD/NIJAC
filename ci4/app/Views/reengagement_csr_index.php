<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Message Réengagement (E040)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* Violet, propre au rôle CSR — voir E034/E035. */
        #page-header {
            background: #6a1b9a;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            flex-shrink: 0;
        }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
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

        #split-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        #panel-form {
            width: 50%;
            padding: 1.25rem 1.5rem;
            overflow-y: auto;
            background: #fff;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
        }

        #panel-rendu {
            width: 50%;
            display: flex;
            flex-direction: column;
            background: #fff;
        }

        #rendu-header {
            background: steelblue;
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            padding: .4rem .75rem;
            flex-shrink: 0;
        }

        #iframe-rendu {
            flex: 1;
            border: 0;
            width: 100%;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        .form-control, .form-select { font-size: .9rem; }

        #txt-message {
            resize: vertical;
            min-height: 320px;
        }

        #panel-boutons {
            display: flex;
            gap: .6rem;
            margin-top: 1.25rem;
        }

        .btn-enregistrer { background:#c6efce; border:1px solid #82c88e; font-weight:600; }
        .btn-enregistrer:hover { background:#a8dfb0; }

        #cartouche-marqueurs code {
            display: inline-block;
            background: #e8eef7;
            border: 1px solid #b8cce4;
            border-radius: 3px;
            padding: .05rem .3rem;
            font-size: .76rem;
            cursor: pointer;
            user-select: none;
            transition: background .12s, transform .1s;
        }

        #cartouche-marqueurs code:hover {
            background: #cfe0f8;
            border-color: #7aaddf;
            transform: scale(1.06);
        }

        #cartouche-marqueurs code:active {
            transform: scale(.95);
        }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'envelope-fill', 'phTitle' => 'Message Réengagement', 'phCode' => 'E040',
    'phCrumbLabel' => 'CSR', 'phCrumbUrl' => site_url('csr-menu'), 'phBackUrl' => site_url('csr-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- ── Deux colonnes : formulaire à gauche, rendu HTML en direct à droite ── -->
<div id="split-container">

    <!-- ── Formulaire (partie droite d'E026, réduite au message Réengagement) ── -->
    <div id="panel-form">

        <div class="mb-2 flex-grow-1 d-flex flex-column">
            <label class="form-label mb-0" for="txt-message">Message :</label>
            <textarea id="txt-message" class="form-control form-control-sm flex-grow-1"></textarea>
        </div>

        <!-- Bouton -->
        <div id="panel-boutons">
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer">
                <i class="bi bi-floppy me-1"></i>Enregistrer
            </button>
        </div>

        <!-- Cartouche des marqueurs disponibles -->
        <div id="cartouche-marqueurs" class="mt-3 border rounded p-2" style="background:#f8f9fa;font-size:.78rem;">
            <div class="fw-bold text-secondary mb-1" style="font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                <i class="bi bi-braces me-1"></i>Marqueurs disponibles
            </div>
            <div class="mb-1">
                <span class="badge bg-secondary me-1 fw-normal">Tous types</span>
                <code data-marqueur="{PRENOM}" class="me-2">{PRENOM}</code>
                <code data-marqueur="{NOM}" class="me-2">{NOM}</code>
                <code data-marqueur="{NOM_COMPLET}" class="me-2">{NOM_COMPLET}</code>
                <code data-marqueur="{ID_JA}" class="me-2">{ID_JA}</code>
                <code data-marqueur="{UTI_NOM}" class="me-2">{UTI_NOM}</code>
                <code data-marqueur="{UTI_PRENOM}" class="me-2">{UTI_PRENOM}</code>
                <code data-marqueur="{URL_LIGUE}" class="me-2">{URL_LIGUE}</code>
                <code data-marqueur="{YEAR_PHASE}" class="me-2">{YEAR_PHASE}</code>
                <code data-marqueur="{PHASE}" class="me-2">{PHASE}</code>
                <code data-marqueur="{URL_INFO_RENCONTRE}" class="me-2">{URL_INFO_RENCONTRE}</code>
            </div>
            <div>
                <span class="badge me-1 fw-normal" style="background:#c2185b;">Désidératas club (E027 / E035 CSR)</span>
                <code data-marqueur="{NOM_CLUB}" class="me-2">{NOM_CLUB}</code>
                <code data-marqueur="{CORR_NOM}" class="me-2">{CORR_NOM}</code>
                <code data-marqueur="{URL_DESIDERATA}" class="me-2">{URL_DESIDERATA}</code>
            </div>
        </div>

        <!-- Statut -->
        <div id="form-status" class="mt-2 small fw-bold"></div>

    </div><!-- /panel-form -->

    <!-- ── Rendu HTML en direct ── -->
    <div id="panel-rendu">
        <div id="rendu-header"><i class="bi bi-eye me-1"></i>Rendu du message</div>
        <iframe id="iframe-rendu"></iframe>
    </div>

</div><!-- /split-container -->

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const MESSAGERIE_BASE = '<?= site_url('messagerie') ?>';
const ID_MESSAGE       = <?= (int) $idMessage ?>;

// ── Valeurs d'exemple pour l'aperçu des marqueurs ──────────────────────────────
const MARQUEURS_EXEMPLE = {
    '{PRENOM}':            'Jean',
    '{NOM}':                'Dupont',
    '{NOM_COMPLET}':        'Jean Dupont',
    '{ID_JA}':              '12345',
    '{UTI_NOM}':            'Martin',
    '{UTI_PRENOM}':         'Sophie',
    '{URL_LIGUE}':          'https://www.ligue-normandie-tt.fr',
    '{URL_INFO_RENCONTRE}': <?= json_encode(site_url('info-rencontre') . '?ja=AbCd1234') ?>,
    '{YEAR_PHASE}':         <?= json_encode(getAnneePhase()) ?>,
    '{PHASE}':              <?= json_encode(getConfig('phase', '1')) ?>,
    '{NOM_CLUB}':           'ASSUN TT',
    '{CORR_NOM}':           'Durand',
    '{URL_DESIDERATA}':     <?= json_encode(site_url('desiderata-club') . '?club=09760136') ?>
};

function resoudreMarqueurs(txt) {
    let out = String(txt ?? '');
    for (const [m, v] of Object.entries(MARQUEURS_EXEMPLE)) {
        out = out.split(m).join(v);
    }
    return out;
}

function majRendu() {
    document.getElementById('iframe-rendu').srcdoc = resoudreMarqueurs($('#txt-message').val());
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

// ── Charger le message Réengagement ──────────────────────────────────────────
function chargerMessage() {
    $.get(`${MESSAGERIE_BASE}/data/${ID_MESSAGE}`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        $('#txt-message').val(res.data.Message || '');
        majRendu();
        setStatus('');
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    const message = $('#txt-message').val().trim();
    if (message === '') {
        setStatus('Le message ne peut pas être vide.', false);
        return;
    }

    $.ajax({ url: `${MESSAGERIE_BASE}/${ID_MESSAGE}`, method: 'PUT', data: { message }, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); setStatus(res.msg); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

// ── Clic sur un marqueur : insérer à la position du curseur ──────────────────
$(document).on('click', '[data-marqueur]', function () {
    const ta    = document.getElementById('txt-message');
    const texte = $(this).data('marqueur');
    const debut = ta.selectionStart ?? ta.value.length;
    const fin   = ta.selectionEnd   ?? debut;
    ta.setRangeText(texte, debut, fin, 'end');
    ta.focus();
    majRendu();
});

// ── Actualisation du rendu dès la première modification ──────────────────────
$('#txt-message').on('input', majRendu);

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    chargerMessage();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
