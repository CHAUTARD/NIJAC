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

            // Validation JSON pour regles_departements
            if ($cle === 'regles_departements' && $valeur !== '') {
                $decoded = json_decode($valeur, true);
                if (!is_array($decoded)) {
                    ob_end_clean();
                    echo json_encode(['ok' => false, 'msg' => 'Format de règles invalide (JSON attendu).']);
                    exit;
                }
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

            // Validation départements_actifs : liste de numéros séparés par virgule
            if ($cle === 'departements_actifs') {
                $deptsValides = ['14', '27', '50', '61', '76'];
                $depts = array_filter(array_map('trim', explode(',', $valeur)));
                foreach ($depts as $d) {
                    if (!in_array($d, $deptsValides, true)) {
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'msg' => "Département « $d » non reconnu."]);
                        exit;
                    }
                }
                $valeur = implode(',', $depts);
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
    $etatCourant   = getConfig('etat_logiciel', 'Developpement');
    $emailDev      = getConfig('email_developpement', 'patrick.chautard@free.fr');
    $deptsActifs      = getConfig('departements_actifs', '14,27,50,61,76');
    $reglesDepts      = getConfig('regles_departements', '{"76":["27"]}');
} catch (\Throwable $e) {
    $etatCourant      = 'Developpement';
    $emailDev         = 'patrick.chautard@free.fr';
    $deptsActifs      = '14,27,50,61,76';
    $reglesDepts      = '{"76":["27"]}';
}
$deptsActifsArray = array_map('trim', explode(',', $deptsActifs));

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
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            align-items: start;
            padding: 2rem 1.5rem;
            gap: 1.25rem;
        }

        /* ── Carte paramètre ── */
        .param-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 3px 14px rgba(0,0,0,.10);
            width: 100%;
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

        /* ── Bouton départements & règle 76 ── */
        #btn-sauvegarder-depts, #btn-sauvegarder-regle76 {
            padding: .42rem 1.4rem;
            font-size: .88rem; font-weight: 700;
            background: var(--nijac-blue); color: #fff;
            border: none; border-radius: 6px; cursor: pointer;
            white-space: nowrap; transition: background .2s;
        }
        #btn-sauvegarder-depts:hover:not(:disabled),
        #btn-sauvegarder-regle76:hover:not(:disabled) { background: #2557a7; }
        #btn-sauvegarder-depts:disabled,
        #btn-sauvegarder-regle76:disabled { opacity: .5; cursor: default; }
        #textarea-regle76:focus { outline: none; border-color: #1a3a6b; }

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

    <!-- ── Paramètre : Départements & règle 76 ── -->
    <div class="param-card">
        <div class="param-card-head">
            <i class="bi bi-map-fill param-icon"></i>
            <div>
                <h2>Départements concernés &amp; règle particulière</h2>
                <small>Départements gérés par la ligue et règle spécifique au 76</small>
            </div>
        </div>
        <div class="param-card-body">

            <p style="font-size:.85rem;color:#374151;margin-bottom:1.1rem;">
                Cochez les départements pris en charge par la ligue.
                Seuls les clubs et salles de ces départements apparaîtront dans les filtres.
            </p>

            <!-- Sélection par région -->
            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.1rem;">
                <label for="cbo-region" style="font-size:.85rem;font-weight:600;color:#374151;white-space:nowrap;">
                    <i class="bi bi-geo-alt-fill me-1"></i>Sélection par région :
                </label>
                <select id="cbo-region" class="form-select form-select-sm" style="max-width:320px;">
                    <option value="">— Choisir une région —</option>
                    <option value="auvergne-rhone-alpes">Auvergne-Rhône-Alpes</option>
                    <option value="bourgogne-franche-comte">Bourgogne-Franche-Comté</option>
                    <option value="bretagne">Bretagne</option>
                    <option value="centre-val-de-loire">Centre-Val de Loire</option>
                    <option value="corse">Corse</option>
                    <option value="grand-est">Grand Est</option>
                    <option value="guadeloupe">Guadeloupe</option>
                    <option value="guyane">Guyane</option>
                    <option value="hauts-de-france">Hauts-de-France</option>
                    <option value="ile-de-france">Île-de-France</option>
                    <option value="la-reunion">La Réunion</option>
                    <option value="martinique">Martinique</option>
                    <option value="mayotte">Mayotte</option>
                    <option value="normandie">Normandie</option>
                    <option value="nouvelle-aquitaine">Nouvelle-Aquitaine</option>
                    <option value="occitanie">Occitanie</option>
                    <option value="pays-de-la-loire">Pays de la Loire</option>
                    <option value="provence-alpes-cote-dazur">Provence-Alpes-Côte d'Azur</option>
                </select>
            </div>

            <!-- Cases à cocher départements (rendues par JS) -->
            <div style="display:flex;flex-wrap:wrap;gap:.6rem 1.4rem;margin-bottom:1.4rem;min-height:32px;" id="depts-checks">
                <span class="text-muted" style="font-size:.83rem;">Sélectionnez une région pour afficher ses départements.</span>
            </div>

            <div class="d-flex align-items-center gap-3 mb-4">
                <button id="btn-sauvegarder-depts">
                    <i class="bi bi-floppy-fill me-1"></i>Enregistrer les départements
                </button>
                <div id="msg-result-depts" style="font-size:.82rem;min-height:18px;"></div>
            </div>

            <hr style="border-color:#e0e8f0;">

            <!-- Règles d'association génériques -->
            <div style="margin-top:1.1rem;">
                <p style="font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.3rem;">
                    <i class="bi bi-link-45deg me-1 text-warning"></i>
                    Règles d'association entre départements
                </p>
                <p style="font-size:.82rem;color:#6b7280;margin-bottom:.75rem;">
                    Pour chaque département, cochez ceux qui seront <strong>automatiquement inclus</strong>
                    lorsqu'il est sélectionné. Sélectionnez d'abord une région ci-dessus.
                </p>
                <div id="regles-container">
                    <span class="text-muted" style="font-size:.82rem;">Sélectionnez d'abord une région pour configurer les règles.</span>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3 mt-3">
                <button id="btn-sauvegarder-regles">
                    <i class="bi bi-floppy-fill me-1"></i>Enregistrer les règles
                </button>
                <div id="msg-result-regles" style="font-size:.82rem;min-height:18px;"></div>
            </div>

        </div>
    </div>

</div><!-- /main-content -->

<?php $statusInitial = 'État actuel : ' . ($etatCourant === 'Developpement' ? 'Développement — emails redirigés' : 'Opérationnel — emails réels'); ?>

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

// ── Référentiel complet départements ─────────────────────────────────────────
const deptNoms = {
    '01':'Ain','02':'Aisne','03':'Allier','04':'Alpes-de-Haute-Provence',
    '05':'Hautes-Alpes','06':'Alpes-Maritimes','07':'Ardèche','08':'Ardennes',
    '09':'Ariège','10':'Aube','11':'Aude','12':'Aveyron','13':'Bouches-du-Rhône',
    '14':'Calvados','15':'Cantal','16':'Charente','17':'Charente-Maritime',
    '18':'Cher','19':'Corrèze','2A':'Corse-du-Sud','2B':'Haute-Corse',
    '21':'Côte-d\'Or','22':'Côtes-d\'Armor','23':'Creuse','24':'Dordogne',
    '25':'Doubs','26':'Drôme','27':'Eure','28':'Eure-et-Loir','29':'Finistère',
    '30':'Gard','31':'Haute-Garonne','32':'Gers','33':'Gironde','34':'Hérault',
    '35':'Ille-et-Vilaine','36':'Indre','37':'Indre-et-Loire','38':'Isère',
    '39':'Jura','40':'Landes','41':'Loir-et-Cher','42':'Loire',
    '43':'Haute-Loire','44':'Loire-Atlantique','45':'Loiret','46':'Lot',
    '47':'Lot-et-Garonne','48':'Lozère','49':'Maine-et-Loire','50':'Manche',
    '51':'Marne','52':'Haute-Marne','53':'Mayenne','54':'Meurthe-et-Moselle',
    '55':'Meuse','56':'Morbihan','57':'Moselle','58':'Nièvre','59':'Nord',
    '60':'Oise','61':'Orne','62':'Pas-de-Calais','63':'Puy-de-Dôme',
    '64':'Pyrénées-Atlantiques','65':'Hautes-Pyrénées','66':'Pyrénées-Orientales',
    '67':'Bas-Rhin','68':'Haut-Rhin','69':'Rhône','70':'Haute-Saône',
    '71':'Saône-et-Loire','72':'Sarthe','73':'Savoie','74':'Haute-Savoie',
    '75':'Paris','76':'Seine-Maritime','77':'Seine-et-Marne','78':'Yvelines',
    '79':'Deux-Sèvres','80':'Somme','81':'Tarn','82':'Tarn-et-Garonne',
    '83':'Var','84':'Vaucluse','85':'Vendée','86':'Vienne','87':'Haute-Vienne',
    '88':'Vosges','89':'Yonne','90':'Territoire de Belfort','91':'Essonne',
    '92':'Hauts-de-Seine','93':'Seine-Saint-Denis','94':'Val-de-Marne',
    '95':'Val-d\'Oise','971':'Guadeloupe','972':'Martinique','973':'Guyane',
    '974':'La Réunion','976':'Mayotte',
};

const regionsMap = {
    'auvergne-rhone-alpes':      ['01','03','07','15','26','38','42','43','63','69','73','74'],
    'bourgogne-franche-comte':   ['21','25','39','58','70','71','89','90'],
    'bretagne':                  ['22','29','35','56'],
    'centre-val-de-loire':       ['18','28','36','37','41','45'],
    'corse':                     ['2A','2B'],
    'grand-est':                 ['08','10','51','52','54','55','57','67','68','88'],
    'guadeloupe':                ['971'],
    'guyane':                    ['973'],
    'hauts-de-france':           ['02','59','60','62','80'],
    'ile-de-france':             ['75','77','78','91','92','93','94','95'],
    'la-reunion':                ['974'],
    'martinique':                ['972'],
    'mayotte':                   ['976'],
    'normandie':                 ['14','27','50','61','76'],
    'nouvelle-aquitaine':        ['16','17','19','23','24','33','40','47','64','79','86','87'],
    'occitanie':                 ['09','11','12','30','31','32','34','46','48','65','66','81','82'],
    'pays-de-la-loire':          ['44','49','53','72','85'],
    'provence-alpes-cote-dazur': ['04','05','06','13','83','84'],
};

// Départements actuellement sauvegardés (depuis PHP)
let deptsInitiaux = <?= json_encode($deptsActifsArray) ?>;

// Règles d'association sauvegardées : JSON {"76":["27"], "14":["61"], ...}
let regles = (function () {
    try { return JSON.parse(<?= json_encode($reglesDepts) ?>) || {}; }
    catch (e) { return {}; }
})();

// Reconstruit les cases à cocher de la liste principale
function rendreCheckboxes(depts, cochés) {
    const $zone = $('#depts-checks').empty();
    if (!depts.length) {
        $zone.append('<span class="text-muted" style="font-size:.83rem;">Aucun département pour cette région.</span>');
        rendreRegles([]);
        return;
    }
    depts.forEach(num => {
        const nom     = deptNoms[num] ?? num;
        const checked = cochés.includes(num) ? 'checked' : '';
        $zone.append(`
            <div class="form-check" style="min-width:200px">
                <input class="form-check-input dept-check" type="checkbox"
                       id="chk-dept-${num}" value="${num}" ${checked}>
                <label class="form-check-label" for="chk-dept-${num}" style="font-size:.88rem;">
                    <strong>${num}</strong> — ${nom}
                </label>
            </div>`);
    });

    appliquerRegles();

    // Réappliquer à chaque changement de case
    $('#depts-checks').on('change', '.dept-check', appliquerRegles);

    rendreRegles(depts);
}

// Applique toutes les règles d'association :
// si un département source est coché, ses associés sont cochés et verrouillés
function appliquerRegles() {
    // D'abord déverrouiller tout
    $('.dept-check').prop('disabled', false);

    Object.entries(regles).forEach(([src, associes]) => {
        const $src = $('#chk-dept-' + src);
        if (!$src.length) return;
        associes.forEach(num => {
            const $chk = $('#chk-dept-' + num);
            if (!$chk.length) return;
            if ($src.is(':checked')) {
                $chk.prop('checked', true).prop('disabled', true);
            }
        });
    });
}

// Reconstruit le tableau des règles d'association (une ligne par département)
function rendreRegles(depts) {
    const $container = $('#regles-container').empty();
    if (!depts.length) {
        $container.append('<span class="text-muted" style="font-size:.82rem;">Sélectionnez d\'abord une région pour configurer les règles.</span>');
        return;
    }

    const $table = $(`
        <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
            <thead>
                <tr style="background:#e8eef7;">
                    <th style="padding:.4rem .6rem;border:1px solid #c8d4e8;white-space:nowrap;">Si ce département est coché…</th>
                    <th style="padding:.4rem .6rem;border:1px solid #c8d4e8;">… inclure automatiquement</th>
                </tr>
            </thead>
            <tbody id="regles-tbody"></tbody>
        </table>`);

    depts.forEach(src => {
        const autresDepts = depts.filter(d => d !== src);
        const associesSauv = Array.isArray(regles[src]) ? regles[src] : [];

        const cases = autresDepts.map(num => {
            const ch = associesSauv.includes(num) ? 'checked' : '';
            return `<div class="form-check form-check-inline mb-0" style="margin-right:.8rem">
                <input class="form-check-input regle-check" type="checkbox"
                       id="r-${src}-${num}" value="${num}" data-src="${src}" ${ch}>
                <label class="form-check-label" for="r-${src}-${num}">
                    <strong>${num}</strong> ${deptNoms[num] ?? num}
                </label>
            </div>`;
        }).join('');

        $table.find('#regles-tbody').append(`
            <tr>
                <td style="padding:.4rem .6rem;border:1px solid #e0e8f0;white-space:nowrap;font-weight:700;background:#f7faff;">
                    ${src} — ${deptNoms[src] ?? src}
                </td>
                <td style="padding:.4rem .6rem;border:1px solid #e0e8f0;">
                    ${cases.length ? cases : '<span class="text-muted">Aucun autre département dans cette région.</span>'}
                </td>
            </tr>`);
    });

    $container.append($table);
}

$('#cbo-region').on('change', function () {
    const region = $(this).val();
    if (!region) return;
    const depts = regionsMap[region] || [];
    rendreCheckboxes(depts, depts);
    $('#msg-result-depts').text('');
});

// Initialisation : si des départements sont déjà sauvegardés, déduire la région
(function init() {
    if (!deptsInitiaux.length) return;
    for (const [region, depts] of Object.entries(regionsMap)) {
        if (deptsInitiaux.every(d => depts.includes(d)) && deptsInitiaux.some(d => depts.includes(d))) {
            $('#cbo-region').val(region);
            rendreCheckboxes(depts, deptsInitiaux);
            return;
        }
    }
    rendreCheckboxes(deptsInitiaux, deptsInitiaux);
})();

// ── Enregistrement départements actifs ───────────────────────────────────────
$('#btn-sauvegarder-depts').on('click', function () {
    const depts = [];
    $('.dept-check:checked').each(function () { depts.push($(this).val()); });

    if (!depts.length) {
        $('#msg-result-depts').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Sélectionnez au moins un département.</span>');
        return;
    }

    spinner(true);
    $(this).prop('disabled', true);
    $('#msg-result-depts').text('');

    $.post('configuration.php', {
        action: 'enregistrer',
        cle:    'departements_actifs',
        valeur: depts.join(',')
    }, function (res) {
        spinner(false);
        $('#btn-sauvegarder-depts').prop('disabled', false);
        if (res.ok) {
            $('#msg-result-depts').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
        } else {
            $('#msg-result-depts').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-sauvegarder-depts').prop('disabled', false);
        $('#msg-result-depts').html('<span class="text-danger">Erreur réseau.</span>');
    });
});

// ── Enregistrement règles d'association ──────────────────────────────────────
$('#btn-sauvegarder-regles').on('click', function () {
    // Construire l'objet {src: [associes]} depuis les cases cochées
    const nouvellesRegles = {};
    $('.regle-check:checked').each(function () {
        const src = $(this).data('src');
        if (!nouvellesRegles[src]) nouvellesRegles[src] = [];
        nouvellesRegles[src].push($(this).val());
    });
    regles = nouvellesRegles;

    spinner(true);
    $(this).prop('disabled', true);
    $('#msg-result-regles').text('');

    $.post('configuration.php', {
        action: 'enregistrer',
        cle:    'regles_departements',
        valeur: JSON.stringify(nouvellesRegles)
    }, function (res) {
        spinner(false);
        $('#btn-sauvegarder-regles').prop('disabled', false);
        if (res.ok) {
            $('#msg-result-regles').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
        } else {
            $('#msg-result-regles').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-sauvegarder-regles').prop('disabled', false);
        $('#msg-result-regles').html('<span class="text-danger">Erreur réseau.</span>');
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
