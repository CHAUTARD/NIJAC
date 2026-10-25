<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Sécurité TOTP (EA99)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        #page-header {
            background: var(--nijac-blue);
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

        #main-area { flex: 1; display: flex; justify-content: center; align-items: flex-start; padding: 1.5rem 1rem; }

        .carte-totp {
            background: #fff;
            border: 1px solid #e0e8f0;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(26,58,107,.08);
            width: 100%;
            max-width: 640px;
            overflow: hidden;
        }
        .carte-totp .carte-titre {
            background: #1a3a6b;
            color: #fff;
            font-size: .9rem;
            font-weight: 700;
            padding: .6rem 1rem;
        }
        .carte-totp .carte-corps { padding: 1rem 1.25rem; font-size: .9rem; }

        #qr { display: flex; justify-content: center; padding: .5rem; }
        #qr svg { max-width: 100%; height: auto; border: 1px solid #e0e8f0; }

        .secret-texte {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 1.05rem;
            letter-spacing: .08em;
            background: #f4f7fb;
            border: 1px dashed #c8d4e8;
            border-radius: 6px;
            padding: .4rem .75rem;
            word-break: break-all;
            user-select: all;
        }
        .uri-texte { font-family: 'Consolas', 'Courier New', monospace; font-size: .75rem; word-break: break-all; color: #6b7280; }

        .champ-code {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 1.4rem;
            letter-spacing: .3em;
            text-align: center;
            max-width: 220px;
        }

        #bloc-secours-codes ol {
            columns: 2;
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 1.05rem;
            background: #fff8e1;
            border: 1px solid #f0c36d;
            border-radius: 6px;
            padding: .75rem 1rem .75rem 2.5rem;
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

        @media print { #page-header, #toolbar, #page-footer, .no-print { display: none !important; } }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'shield-lock-fill', 'phTitle' => 'Sécurité TOTP — double authentification', 'phCode' => 'EA99',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu') . '#tab-dba', 'phBackUrl' => site_url('admin-menu') . '#tab-dba',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => '', 'tbShowPwdWarning' => false]) ?>

<div id="main-area">
    <div class="carte-totp">

    <?php if ($mode === 'absent'): ?>
        <div class="carte-titre"><i class="bi bi-exclamation-triangle-fill me-2"></i>Double authentification indisponible</div>
        <div class="carte-corps">
            <p>Les colonnes TOTP de la table <code>utilisateur</code> n'existent pas encore : chargez une fois
               l'écran EA98 pour les créer. En attendant, EA96 / EA97 / EA98 restent accessibles sans code (journalisé).</p>
            <a href="<?= site_url('db-admin') ?>" class="btn btn-primary btn-sm"><i class="bi bi-database-fill-gear me-1"></i>Ouvrir EA98</a>
        </div>

    <?php elseif ($mode === 'erreur'): ?>
        <div class="carte-titre"><i class="bi bi-x-octagon-fill me-2"></i>Erreur</div>
        <div class="carte-corps"><div class="alert alert-danger mb-0"><?= esc($erreur) ?></div></div>

    <?php elseif ($mode === 'activation'): ?>
        <div class="carte-titre"><i class="bi bi-qr-code me-2"></i>Activer la double authentification TOTP</div>
        <div class="carte-corps">
            <div id="bloc-activation">
                <p class="mb-2">
                    L'accès à <strong><?= esc($retourCode) ?></strong> (et aux autres écrans réservés EA96 / EA97 / EA98)
                    exige désormais un code d'une application d'authentification (Google Authenticator, Microsoft
                    Authenticator, FreeOTP…).
                </p>
                <ol class="mb-3">
                    <li>Dans l'application, ajoutez un compte en scannant ce QR code :</li>
                </ol>
                <div id="qr" data-uri="<?= esc($uri, 'attr') ?>"></div>
                <p class="mb-1 mt-2">Ou saisissez manuellement cette clé (type « basée sur l'heure ») :</p>
                <div class="secret-texte mb-2"><?= esc(trim(chunk_split($secret, 4, ' '))) ?></div>
                <details class="mb-3">
                    <summary class="small text-muted">Afficher l'URI otpauth://</summary>
                    <div class="uri-texte mt-1"><?= esc($uri) ?></div>
                </details>
                <div id="qr-erreur" class="alert alert-warning small d-none">
                    Le QR code n'a pas pu être généré : utilisez la saisie manuelle de la clé ci-dessus.
                </div>

                <ol start="2" class="mb-2">
                    <li>Confirmez avec votre mot de passe et le premier code affiché par l'application.</li>
                </ol>
                <form id="form-activer" autocomplete="off">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-6">
                            <label for="act-password" class="form-label small fw-semibold mb-1">Mot de passe</label>
                            <input type="password" id="act-password" class="form-control" autocomplete="current-password" required>
                        </div>
                        <div class="col-sm-6">
                            <label for="act-code" class="form-label small fw-semibold mb-1">Code à 6 chiffres</label>
                            <input type="text" id="act-code" class="form-control champ-code" inputmode="numeric"
                                   pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mt-3"><i class="bi bi-shield-check me-1"></i>Activer</button>
                </form>
                <p class="small text-muted mt-3 mb-0">La clé proposée reste valable 15 minutes ; au-delà, rechargez la page (une nouvelle clé sera générée).</p>
            </div>

            <div id="bloc-secours-codes" class="d-none">
                <div class="alert alert-success py-2"><i class="bi bi-check-circle-fill me-1"></i>Double authentification TOTP activée.</div>
                <p class="mb-1"><strong>Codes de secours</strong> — à usage unique, à conserver hors ligne (papier, coffre-fort de mots de passe).
                   Ils permettent d'accéder aux écrans si le téléphone est perdu.</p>
                <p class="text-danger fw-semibold small mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>Ils ne seront plus jamais affichés.</p>
                <ol id="liste-secours"></ol>
                <div class="d-flex gap-2 no-print">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-imprimer"><i class="bi bi-printer me-1"></i>Imprimer</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btn-fichier-secours"><i class="bi bi-download me-1"></i>Enregistrer dans un fichier texte</button>
                    <a href="<?= esc($retourUrl, 'attr') ?>" class="btn btn-primary btn-sm" id="btn-continuer">
                        <i class="bi bi-arrow-right-circle me-1"></i>J'ai noté mes codes — continuer vers <?= esc($retourCode) ?>
                    </a>
                </div>
            </div>
        </div>

    <?php elseif ($mode === 'deverrouille'): ?>
        <div class="carte-titre"><i class="bi bi-unlock-fill me-2"></i>Session déverrouillée</div>
        <div class="carte-corps">
            <p>Le code TOTP a été validé pour cette session (30 minutes, prolongées à chaque utilisation).
               Codes de secours restants : <strong><?= (int) $nbSecours ?></strong>.</p>
            <a href="<?= esc($retourUrl, 'attr') ?>" class="btn btn-primary btn-sm"><i class="bi bi-arrow-right-circle me-1"></i>Continuer vers <?= esc($retourCode) ?></a>
        </div>

    <?php else: ?>
        <div class="carte-titre"><i class="bi bi-shield-lock-fill me-2"></i>Code de double authentification</div>
        <div class="carte-corps">
            <?php if ($erreur !== ''): ?>
            <div class="alert alert-danger small"><?= esc($erreur) ?></div>
            <?php endif; ?>
            <p class="mb-2">Saisissez le code à 6 chiffres affiché par votre application d'authentification pour accéder à
               <strong><?= esc($retourCode) ?></strong>.</p>
            <form id="form-verifier" autocomplete="off" class="d-flex gap-2 align-items-center flex-wrap">
                <input type="text" id="ver-code" class="form-control champ-code" inputmode="numeric"
                       pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required autofocus>
                <button type="submit" class="btn btn-primary"><i class="bi bi-unlock me-1"></i>Valider</button>
            </form>

            <details class="mt-4">
                <summary class="small">Téléphone indisponible ? Utiliser un code de secours (<?= (int) $nbSecours ?> restant(s))</summary>
                <form id="form-secours" autocomplete="off" class="d-flex gap-2 align-items-center flex-wrap mt-2">
                    <input type="text" id="sec-code" class="form-control" style="max-width:200px;font-family:Consolas,monospace;"
                           placeholder="XXXXX-XXXXX" maxlength="11" required>
                    <button type="submit" class="btn btn-outline-primary btn-sm">Utiliser ce code</button>
                </form>
                <p class="small text-muted mt-2 mb-0">Chaque code de secours n'est valable qu'une fois.</p>
            </details>
        </div>
    <?php endif; ?>

    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-qrcode.js') ?>"></script>
<script>
'use strict';
const TOTP_BASE = '<?= site_url('securite-totp') ?>';
const RETOUR    = <?= json_encode($retour) ?>;

function setStatus(msg, ok = true) { $('#status-bar').text(msg).css('color', ok ? '#374151' : '#c00'); }

// ── Activation : QR code dessiné côté navigateur (aucun service externe) ──
// typeof : si asset/js/nijac-qrcode.js est absent (non déployé, 404), on affiche le message
// de repli au lieu d'une ReferenceError qui couperait tout ce script (formulaire d'activation compris).
const $qr = $('#qr');
if ($qr.length && (typeof nijacQrCode !== 'function' || !nijacQrCode($qr[0], $qr.attr('data-uri'), 5))) {
    $('#qr-erreur').removeClass('d-none');
}

$('#form-activer').on('submit', function (e) {
    e.preventDefault();
    const $btn = $(this).find('button[type=submit]').prop('disabled', true);
    $.post(`${TOTP_BASE}/activer`, {
        password: $('#act-password').val(),
        code:     $('#act-code').val(),
        retour:   RETOUR,
    }, function (res) {
        $btn.prop('disabled', false);
        if (!res.ok) { toast(res.msg, false); setStatus(res.msg, false); return; }
        $('#act-password, #act-code').val('');
        const $ol = $('#liste-secours').empty();
        (res.codes || []).forEach(c => $('<li>').text(c).appendTo($ol));
        $('#bloc-activation').remove();
        $('#bloc-secours-codes').removeClass('d-none');
        toast(res.msg);
        setStatus(res.msg);
    }, 'json').fail(() => { $btn.prop('disabled', false); toast('Erreur réseau.', false); });
});

$('#btn-imprimer').on('click', () => window.print());

// Fichier texte des codes de secours, généré dans le navigateur (Blob, aucun appel serveur).
// UTF-8 avec BOM + fins de ligne CRLF : lisible tel quel dans le Bloc-notes Windows.
const LOGIN_TOTP = <?= json_encode((string) ($_SESSION['utilisateur']['login'] ?? 'CHAUTARD')) ?>;
$('#btn-fichier-secours').on('click', function () {
    const codes = $('#liste-secours li').map((i, li) => $(li).text().trim()).get();
    if (!codes.length) return;
    const d   = new Date();
    const p2  = n => String(n).padStart(2, '0');
    const ymd = `${d.getFullYear()}${p2(d.getMonth() + 1)}${p2(d.getDate())}`;
    const lignes = [
        'NIJAC – Codes de secours TOTP (EA99)',
        '',
        `Login : ${LOGIN_TOTP}`,
        `Date  : ${p2(d.getDate())}/${p2(d.getMonth() + 1)}/${d.getFullYear()} ${p2(d.getHours())}:${p2(d.getMinutes())}`,
        '',
        ...codes.map((c, i) => `${i + 1}. ${c}`),
        '',
        "Chaque code ne sert qu'une fois. Conservez ce fichier en lieu sûr (hors de ce poste si possible) et supprimez-le après usage. Procédure d'urgence : voir l'administrateur de la base.",
        '',
    ];
    const blob = new Blob(['﻿' + lignes.join('\r\n')], { type: 'text/plain;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    const $a   = $('<a>').attr({ href: url, download: `nijac-codes-secours-${ymd}.txt` }).appendTo('body');
    $a[0].click();
    $a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    nijacToast('Fichier enregistré : pensez à le ranger en lieu sûr', 'success');
});

// ── Saisie du code / code de secours ──
function deverrouiller(action, code, $form) {
    const $btn = $form.find('button[type=submit]').prop('disabled', true);
    $.post(`${TOTP_BASE}/${action}`, { code, retour: RETOUR }, function (res) {
        $btn.prop('disabled', false);
        if (!res.ok) { toast(res.msg, false); setStatus(res.msg, false); $form.find('input').val('').trigger('focus'); return; }
        toast(res.msg);
        window.location.href = res.url;
    }, 'json').fail(() => { $btn.prop('disabled', false); toast('Erreur réseau.', false); });
}

$('#form-verifier').on('submit', function (e) {
    e.preventDefault();
    deverrouiller('verifier', $('#ver-code').val(), $(this));
});

$('#form-secours').on('submit', function (e) {
    e.preventDefault();
    deverrouiller('secours', $('#sec-code').val(), $(this));
});
</script>
</body>
</html>
