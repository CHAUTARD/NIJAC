<?php
/**
 * NIJAC – Configuration générale (E015)
 *
 * Gestion des paramètres applicatifs stockés dans la table `configuration`.
 * Premier paramètre : état du logiciel (Opérationnel / Développement).
 * En mode Développement, tous les emails sont redirigés vers
 * patrick.chautard@free.fr au lieu du destinataire réel.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app_config.php';

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = getPDO();

        // ── Lire tous les paramètres ──────────────────────────────────────────
        if ($action === 'lire') {
            $rows = $pdo->query(
                'SELECT cle, valeur, libelle, description FROM configuration ORDER BY cle'
            )->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'params' => $rows]);
            exit;
        }

        // ── Enregistrer un paramètre ──────────────────────────────────────────
        if ($action === 'enregistrer') {
            $cle    = trim($_POST['cle']    ?? '');
            $valeur = trim($_POST['valeur'] ?? '');

            if ($cle === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Clé manquante.']);
                exit;
            }

            // Valeurs autorisées pour etat_logiciel
            if ($cle === 'etat_logiciel' && !in_array($valeur, ['Operationnel', 'Developpement'], true)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Valeur invalide pour ce paramètre.']);
                exit;
            }

            // Validation email pour email_developpement
            if ($cle === 'email_developpement') {
                if ($valeur === '' || !filter_var($valeur, FILTER_VALIDATE_EMAIL)) {
                    ob_end_clean();
                    echo json_encode(['ok' => false, 'msg' => 'Adresse email invalide.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE configuration SET valeur = ? WHERE cle = ?'
            );
            $stmt->execute([$valeur, $cle]);

            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Paramètre enregistré.', 'cle' => $cle, 'valeur' => $valeur]);
            exit;
        }

    } catch (\PDOException $e) {
        error_log('[NIJAC] configuration.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] configuration.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Init table + lecture paramètres courants pour le rendu ───────────────────
try {
    $pdo = getPDO();
    initTableConfiguration($pdo);
    $etatCourant = getConfig('etat_logiciel', 'Developpement');
    $emailDev    = getConfig('email_developpement', 'patrick.chautard@free.fr');
} catch (\Throwable $e) {
    $etatCourant = 'Developpement';
    $emailDev    = 'patrick.chautard@free.fr';
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Configuration (E015)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

/* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff; padding: .5rem 1.25rem;
            font-size: .9rem; font-weight: 600; flex-shrink: 0;
        }

        /* ── Contenu ── */
        #main-content {
            flex: 1;
            display: flex; flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
            gap: 1.25rem;
        }

        /* ── Carte paramètre ── */
        .param-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 3px 14px rgba(0,0,0,.10);
            width: 100%; max-width: 720px;
            overflow: hidden;
        }
        .param-card-head {
            background: var(--nijac-blue);
            color: #fff;
            padding: .75rem 1.5rem;
            display: flex; align-items: center; gap: .75rem;
        }
        .param-card-head .param-icon { font-size: 1.3rem; }
        .param-card-head h2 { font-size: .95rem; font-weight: 700; margin: 0; }
        .param-card-head small { font-size: .78rem; opacity: .75; }
        .param-card-body { padding: 1.5rem; }

        /* ── Bascule état logiciel ── */
        .etat-toggle {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .etat-btn {
            flex: 1;
            border: 2px solid transparent;
            border-radius: 10px;
            padding: 1.1rem 1rem;
            cursor: pointer;
            text-align: center;
            transition: all .2s;
            background: #f3f4f6;
        }
        .etat-btn input[type=radio] { display: none; }
        .etat-btn .etat-icon { font-size: 2rem; display: block; margin-bottom: .5rem; }
        .etat-btn .etat-label { font-size: .95rem; font-weight: 700; display: block; }
        .etat-btn .etat-desc  { font-size: .78rem; color: #6b7280; display: block; margin-top: .3rem; }

        /* État Développement */
        .etat-btn.dev { border-color: #d97706; }
        .etat-btn.dev:hover { background: #fffbeb; }
        .etat-btn.dev.selected {
            background: #fffbeb;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245,158,11,.2);
        }
        .etat-btn.dev .etat-icon { color: #d97706; }
        .etat-btn.dev .etat-label { color: #92400e; }

        /* État Opérationnel */
        .etat-btn.ope { border-color: #16a34a; }
        .etat-btn.ope:hover { background: #f0fdf4; }
        .etat-btn.ope.selected {
            background: #f0fdf4;
            border-color: #22c55e;
            box-shadow: 0 0 0 3px rgba(34,197,94,.2);
        }
        .etat-btn.ope .etat-icon { color: #16a34a; }
        .etat-btn.ope .etat-label { color: #14532d; }

        /* ── Bandeau info email ── */
        .email-info {
            border-radius: 8px;
            padding: .85rem 1.1rem;
            font-size: .85rem;
            display: flex; align-items: flex-start; gap: .7rem;
            margin-bottom: 1.25rem;
        }
        .email-info.dev-mode {
            background: #fff3cd; border: 1px solid #f59e0b; color: #78350f;
        }
        .email-info.ope-mode {
            background: #d1fae5; border: 1px solid #6ee7b7; color: #065f46;
        }
        .email-info i { font-size: 1.2rem; flex-shrink: 0; margin-top: .05rem; }

        /* ── Bouton sauvegarder ── */
        #btn-sauvegarder {
            padding: .55rem 2rem;
            font-size: .9rem; font-weight: 700;
            background: var(--nijac-blue); color: #fff;
            border: none; border-radius: 6px; cursor: pointer;
            transition: background .2s;
        }
        #btn-sauvegarder:hover { background: #2557a7; }
        #btn-sauvegarder:disabled { opacity: .5; cursor: default; }

        /* ── Message résultat ── */
        #msg-result {
            font-size: .85rem; margin-top: .85rem; min-height: 20px;
        }

        #status-bar {
            background: #e8eef7; border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem; font-size: .8rem; color: #374151; flex-shrink: 0;
        }

        /* ── Champ email dev ── */
        .email-dev-group label {
            font-size: .85rem; font-weight: 600; color: #374151;
            margin-bottom: .3rem; display: block;
        }
        .email-dev-row {
            display: flex; gap: .6rem; align-items: center;
        }
        #input-email-dev {
            flex: 1; min-width: 0;
            border: 2px solid #c8d4e8; border-radius: 6px;
            padding: .42rem .75rem; font-size: .9rem;
            transition: border-color .2s;
        }
        #input-email-dev:focus      { outline: none; border-color: #1a3a6b; }
        #input-email-dev.is-invalid { border-color: #dc2626; }
        #btn-sauvegarder-email {
            padding: .42rem 1.4rem;
            font-size: .88rem; font-weight: 700;
            background: var(--nijac-blue); color: #fff;
            border: none; border-radius: 6px; cursor: pointer;
            white-space: nowrap; transition: background .2s;
        }
        #btn-sauvegarder-email:hover:not(:disabled) { background: #2557a7; }
        #btn-sauvegarder-email:disabled { opacity: .5; cursor: default; }
        #msg-result-email { font-size: .82rem; margin-top: .4rem; min-height: 18px; }

        /* ── Spinner ── */
        #spinner {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.3); z-index: 99999;
            align-items: center; justify-content: center;
        }
        #spinner.show { display: flex; }
    </style>
</head>
<body>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-gear-fill me-2"></i>Configuration générale
    <small class="opacity-75 ms-2">(E015)</small>
    <a href="admin_menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Contenu -->
<div id="main-content">

    <!-- ── Paramètre : État du logiciel ── -->
    <div class="param-card">
        <div class="param-card-head">
            <i class="bi bi-toggle2-on param-icon"></i>
            <div>
                <h2>État du logiciel</h2>
                <small>Contrôle le comportement des envois d'email</small>
            </div>
        </div>
        <div class="param-card-body">

            <!-- Bascule visuelle -->
            <div class="etat-toggle" id="etat-toggle">

                <label class="etat-btn dev<?= $etatCourant === 'Developpement' ? ' selected' : '' ?>"
                       id="btn-dev" data-valeur="Developpement">
                    <input type="radio" name="etat_logiciel" value="Developpement"
                           <?= $etatCourant === 'Developpement' ? 'checked' : '' ?>>
                    <span class="etat-icon"><i class="bi bi-tools"></i></span>
                    <span class="etat-label">Développement</span>
                    <span class="etat-desc">Tous les emails sont redirigés vers<br>
                        <strong id="desc-email-dev"><?= htmlspecialchars($emailDev) ?></strong></span>
                </label>

                <label class="etat-btn ope<?= $etatCourant === 'Operationnel' ? ' selected' : '' ?>"
                       id="btn-ope" data-valeur="Operationnel">
                    <input type="radio" name="etat_logiciel" value="Operationnel"
                           <?= $etatCourant === 'Operationnel' ? 'checked' : '' ?>>
                    <span class="etat-icon"><i class="bi bi-check-circle-fill"></i></span>
                    <span class="etat-label">Opérationnel</span>
                    <span class="etat-desc">Les emails sont envoyés aux<br>
                        <strong>destinataires réels</strong></span>
                </label>

            </div>

            <!-- Bandeau info dynamique -->
            <div class="email-info <?= $etatCourant === 'Developpement' ? 'dev-mode' : 'ope-mode' ?>" id="email-info">
                <?php if ($etatCourant === 'Developpement'): ?>
                    <i class="bi bi-envelope-exclamation-fill"></i>
                    <span>Mode <strong>Développement</strong> actif — tous les emails sont redirigés vers
                    <strong id="bandeau-email-dev"><?= htmlspecialchars($emailDev) ?></strong> quel que soit le destinataire configuré.</span>
                <?php else: ?>
                    <i class="bi bi-envelope-check-fill"></i>
                    <span>Mode <strong>Opérationnel</strong> actif — les emails sont envoyés aux destinataires réels.</span>
                <?php endif; ?>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button id="btn-sauvegarder">
                    <i class="bi bi-floppy-fill me-2"></i>Enregistrer
                </button>
                <div id="msg-result"></div>
            </div>

        </div>
    </div>

    <!-- ── Paramètre : Email de développement ── -->
    <div class="param-card">
        <div class="param-card-head">
            <i class="bi bi-envelope-at-fill param-icon"></i>
            <div>
                <h2>Email de développement</h2>
                <small>Destinataire de tous les emails en mode Développement</small>
            </div>
        </div>
        <div class="param-card-body">

            <p style="font-size:.85rem;color:#374151;margin-bottom:1.1rem;">
                En mode <strong>Développement</strong>, chaque email envoyé par l'application
                est redirigé vers cette adresse au lieu du destinataire réel.
                Modifiez-la si vous souhaitez recevoir les emails de test sur une autre boîte.
            </p>

            <div class="email-dev-group">
                <label for="input-email-dev">
                    <i class="bi bi-envelope-fill me-1"></i>Adresse email de redirection
                </label>
                <div class="email-dev-row">
                    <input type="email" id="input-email-dev"
                           value="<?= htmlspecialchars($emailDev) ?>"
                           placeholder="ex : dev@mondomaine.fr"
                           autocomplete="off">
                    <button id="btn-sauvegarder-email">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-email"></div>
            </div>

        </div>
    </div>

</div><!-- /main-content -->

<!-- Barre d'état -->
<div id="status-bar">État actuel : <?= $etatCourant === 'Developpement' ? 'Développement — emails redirigés' : 'Opérationnel — emails réels' ?></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let etatSelectionne = '<?= $etatCourant ?>';

function spinner(show) { $('#spinner').toggleClass('show', show); }

// ── Sélection d'un état ───────────────────────────────────────────────────────
$('.etat-btn').on('click', function () {
    const val = $(this).data('valeur');
    etatSelectionne = val;

    $('.etat-btn').removeClass('selected');
    $(this).addClass('selected');
    $(this).find('input[type=radio]').prop('checked', true);

    // Mise à jour du bandeau info
    const $info   = $('#email-info');
    const emailDev = $('#input-email-dev').val() || '<?= htmlspecialchars($emailDev) ?>';
    if (val === 'Developpement') {
        $info.removeClass('ope-mode').addClass('dev-mode').html(
            '<i class="bi bi-envelope-exclamation-fill"></i>' +
            '<span>Mode <strong>Développement</strong> actif — tous les emails sont redirigés vers ' +
            '<strong id="bandeau-email-dev">' + $('<span>').text(emailDev).html() + '</strong> quel que soit le destinataire configuré.</span>'
        );
    } else {
        $info.removeClass('dev-mode').addClass('ope-mode').html(
            '<i class="bi bi-envelope-check-fill"></i>' +
            '<span>Mode <strong>Opérationnel</strong> actif — les emails sont envoyés aux destinataires réels.</span>'
        );
    }
    $('#msg-result').text('');
});

// ── Enregistrement état logiciel ──────────────────────────────────────────────
$('#btn-sauvegarder').on('click', function () {
    spinner(true);
    $(this).prop('disabled', true);
    $('#msg-result').text('');

    $.post('configuration.php', {
        action: 'enregistrer',
        cle:    'etat_logiciel',
        valeur: etatSelectionne
    }, function (res) {
        spinner(false);
        $('#btn-sauvegarder').prop('disabled', false);
        if (res.ok) {
            $('#msg-result').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
            const label = etatSelectionne === 'Developpement' ? 'Développement — emails redirigés' : 'Opérationnel — emails réels';
            $('#status-bar').text('État actuel : ' + label);
        } else {
            $('#msg-result').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-sauvegarder').prop('disabled', false);
        $('#msg-result').html('<span class="text-danger">Erreur réseau.</span>');
    });
});

// ── Enregistrement email développement ───────────────────────────────────────
$('#btn-sauvegarder-email').on('click', function () {
    const val = $('#input-email-dev').val().trim();

    // Validation côté client
    const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRe.test(val)) {
        $('#input-email-dev').addClass('is-invalid');
        $('#msg-result-email').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Adresse email invalide.</span>');
        return;
    }
    $('#input-email-dev').removeClass('is-invalid');

    spinner(true);
    $(this).prop('disabled', true);
    $('#msg-result-email').text('');

    $.post('configuration.php', {
        action: 'enregistrer',
        cle:    'email_developpement',
        valeur: val
    }, function (res) {
        spinner(false);
        $('#btn-sauvegarder-email').prop('disabled', false);
        if (res.ok) {
            $('#msg-result-email').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
            // Mettre à jour les références visuelles dans la carte 1
            $('#desc-email-dev').text(val);
            $('#bandeau-email-dev').text(val);
        } else {
            $('#input-email-dev').addClass('is-invalid');
            $('#msg-result-email').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-sauvegarder-email').prop('disabled', false);
        $('#msg-result-email').html('<span class="text-danger">Erreur réseau.</span>');
    });
});

// Effacer l'erreur dès que l'utilisateur retape
$('#input-email-dev').on('input', function () {
    $(this).removeClass('is-invalid');
    $('#msg-result-email').text('');
});
</script>
</body>
</html>
