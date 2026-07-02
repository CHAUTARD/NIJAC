<?php
/**
 * NIJAC – Mise à jour adresse domicile du JA (E029)
 *
 * Page publique (sans session) permettant à un Juge-Arbitre de renseigner
 * ou corriger son code postal et sa ville à partir de la table laposte.
 * Accessible via un lien tokenisé (obfusqué) généré par le nominateur.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-30
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';
require __DIR__ . '/../Classes/Obfuscator.php';

$_obf = new Obfuscator(OBFUSCATOR_SEED);
$pdo  = getPDO();

// ── Décodage du paramètre obfusqué ?ja=TOKEN ─────────────────────────────────
if (isset($_GET['ja']) && $_GET['ja'] !== '') {
    $decoded = $_obf->deobfuscate(trim($_GET['ja']));
    if ($decoded > 0) $_GET['id_ja'] = $decoded;
}
$idJa = (int)($_GET['id_ja'] ?? 0);

// ── ACTIONS AJAX ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // ── Générer le lien tokenisé (requiert une session nominateur) ────────
    if ($action === 'token') {
        require __DIR__ . '/../includes/auth_required.php';
        $id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'err' => 'ID manquant']); exit; }
        $token = $_obf->obfuscate($id);
        $base  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
               . '://' . $_SERVER['HTTP_HOST']
               . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/adresse_ja.php';
        echo json_encode(['ok' => true, 'token' => $token, 'url' => "$base?ja=$token"]);
        exit;
    }

    // ── Envoyer le message de demande d'adresse (requiert session) ───────
    if ($action === 'envoyer_demande_adresse') {
        $authRedirect = '../index.php';
        require __DIR__ . '/../includes/auth_required.php';
        csrfVerify(true);
        require_once __DIR__ . '/../config/app_config.php';
        require_once __DIR__ . '/../config/helpers.php';

        $idJaPost = (int)($_POST['id_ja'] ?? 0);
        if (!$idJaPost) { echo json_encode(['ok' => false, 'err' => 'JA manquant.']); exit; }

        try {
            $moi = $_SESSION['utilisateur'];

            // Charger le JA
            $stmtJa = $pdo->prepare('SELECT Id_JA, Nom, Prenom, Email FROM ja WHERE Id_JA = ?');
            $stmtJa->execute([$idJaPost]);
            $ja = $stmtJa->fetch();
            if (!$ja)          { echo json_encode(['ok' => false, 'err' => 'JA introuvable.']); exit; }
            if (!$ja['Email']) { echo json_encode(['ok' => false, 'err' => 'Ce JA n\'a pas d\'adresse email.']); exit; }

            // Charger le modèle de message "Demande adresse" (message système 5)
            $stmtMsg = $pdo->prepare("SELECT Sujet, Message FROM messagerie WHERE Type = 'Demande adresse' ORDER BY Id_Messagerie LIMIT 1");
            $stmtMsg->execute();
            $modele = $stmtMsg->fetch();
            if (!$modele) { echo json_encode(['ok' => false, 'err' => 'Modèle "Demande adresse" introuvable en base.']); exit; }

            // Construire l'URL personnalisée pour ce JA
            $token = $_obf->obfuscate($idJaPost);
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $urlAdresseJa = $proto . '://' . $_SERVER['HTTP_HOST']
                . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
                . '/adresse_ja.php?ja=' . $token;

            // Remplacement des marqueurs
            $cherche  = ['{NOM}','{PRENOM}','{NOM_COMPLET}','{ID_JA}','{URL_ADRESSE_JA}',
                         '{UTI_NOM}','{UTI_PRENOM}','{URL_LIGUE}','{YEAR_PHASE}'];
            $remplace = [$ja['Nom'],$ja['Prenom'],$ja['Prenom'].' '.$ja['Nom'],$token,$urlAdresseJa,
                         $moi['nom']??'',$moi['prenom']??'',getConfig('url_ligue','https://www.ligue-normandie-tt.fr'),getAnneePhase()];

            $corps  = str_replace($cherche, $remplace, $modele['Message']);
            $sujet  = str_replace($cherche, $remplace, $modele['Sujet']);
            $corps  = preg_replace('/ {3,}/', ' ', $corps);

            // Envoi
            $errRl = checkRateLimit(1);
            if ($errRl !== null) { echo json_encode(['ok' => false, 'err' => $errRl]); exit; }

            $dest   = getEmailDestinataire($ja['Email']);
            $isHtml = strip_tags($corps) !== $corps;
            $mail   = getNijacMailer();
            $mail->isHTML($isHtml);
            $mail->addAddress($dest, $ja['Prenom'] . ' ' . $ja['Nom']);
            $modeDev = isModeDeveloppement();
            $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? "[DEV] $sujet" : $sujet;
            $mail->Body    = $corps;
            if ($isHtml) $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $corps));
            $mail->send();
            enregistrerEnvois(1);

            echo json_encode(['ok' => true, 'nom' => $ja['Prenom'] . ' ' . $ja['Nom'], 'url' => $urlAdresseJa]);
        } catch (\Exception $e) {
            echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
        }
        exit;
    }

    try {

    // ── Recherche laposte (publique) ─────────────────────────────────────
    if ($action === 'recherche_laposte') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);
        require_once __DIR__ . '/../config/helpers.php';
        $cp    = trim($_POST['cp']    ?? '');
        $ville = normaliserVille($_POST['ville'] ?? '');

        if ($cp === '' && $ville === '') {
            echo json_encode(['ok' => false, 'msg' => 'CP et ville vides.']); exit;
        }

        if ($cp !== '' && $ville !== '') {
            $stmt = $pdo->prepare(
                "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                 WHERE CodePostal = ?
                   AND REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
                 LIMIT 1"
            );
            $stmt->execute([$cp, $ville . '%']);
            $row = $stmt->fetch();
            if ($row) {
                echo json_encode(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                exit;
            }
        }

        if ($cp !== '') {
            $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
            $stmt->execute([$cp]);
            $rows = $stmt->fetchAll();
            if (count($rows) === 1) {
                echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                exit;
            }
            if (count($rows) > 1) {
                $sugg = array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);
                echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]); exit;
            }
        }

        if ($ville !== '') {
            $stmt = $pdo->prepare(
                "SELECT Id_LaPoste, CodePostal, Nom FROM laposte
                 WHERE REPLACE(REPLACE(Nom, '-', ' '), 'SAINT ', 'ST ') LIKE ?
                 ORDER BY CodePostal, Nom LIMIT 20"
            );
            $stmt->execute([$ville . '%']);
            $rows = $stmt->fetchAll();
            if (count($rows) === 1) {
                echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                exit;
            }
            if (count($rows) > 1) {
                $sugg = array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);
                echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]); exit;
            }
        }

        echo json_encode(['ok' => false, 'msg' => 'Commune non trouvée.']); exit;
    }

    // ── Sauvegarder l'adresse ─────────────────────────────────────────────
    if ($action === 'sauvegarder') {
        csrfVerify(true);
        $id        = (int)($_POST['id_ja']     ?? 0);
        $idLaPoste = ($_POST['id_laposte'] ?? '') !== '' ? (int)$_POST['id_laposte'] : null;
        $cp        = trim($_POST['cp']      ?? '');
        $ville     = trim($_POST['ville']   ?? '');

        if (!$id) { echo json_encode(['ok' => false, 'err' => 'JA manquant.']); exit; }
        if (!$idLaPoste) { echo json_encode(['ok' => false, 'err' => 'Veuillez sélectionner une commune valide dans la liste laposte.']); exit; }

        // Vérifier que le JA existe
        $check = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
        $check->execute([$id]);
        if (!$check->fetch()) { echo json_encode(['ok' => false, 'err' => 'JA introuvable.']); exit; }

        // Auto-migration colonnes si nécessaire
        $colsJa = array_column($pdo->query('SHOW COLUMNS FROM ja')->fetchAll(), 'Field');
        foreach (['Cp' => "VARCHAR(10) NULL", 'Ville' => "VARCHAR(100) NULL"] as $col => $def) {
            if (!in_array($col, $colsJa)) $pdo->exec("ALTER TABLE ja ADD COLUMN $col $def");
        }

        $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
            ->execute([$idLaPoste, $cp ?: null, $ville ?: null, $id]);

        echo json_encode(['ok' => true]); exit;
    }

    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'err' => $e->getMessage()]); exit;
    }

    echo json_encode(['ok' => false, 'err' => 'Action inconnue.']); exit;
}

// ── Chargement de la fiche JA ─────────────────────────────────────────────────
$ja     = null;
$erreur = '';

if ($idJa > 0) {
    try {
        $colsJa = array_column($pdo->query('SHOW COLUMNS FROM ja')->fetchAll(), 'Field');
        $stmt = $pdo->prepare("
            SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                   ja.Id_LaPoste,
                   COALESCE(lp.CodePostal, ja.Cp)  AS Cp,
                   COALESCE(lp.Nom,        ja.Ville) AS Ville
            FROM ja
            LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Id_JA = ?
        ");
        $stmt->execute([$idJa]);
        $ja = $stmt->fetch();
        if (!$ja) $erreur = "Juge-Arbitre #$idJa introuvable.";
    } catch (PDOException $e) {
        $erreur = 'Erreur BDD : ' . $e->getMessage();
    }
} else {
    $erreur = 'Lien invalide ou paramètre manquant.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<title>Adresse domicile JA (E029)<?= $ja ? ' – ' . htmlspecialchars($ja['Nom'] . ' ' . $ja['Prenom']) : '' ?></title>
<link rel="stylesheet" href="../asset/css/bootstrap.min.css">
<link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
<style>
    body { background: #f0f4f8; font-family: Arial, Helvetica, sans-serif; }

    #bandeau-normandie {
        max-width: 520px;
        margin: 1.5rem auto .5rem;
        padding: 0 .5rem;
    }
    #bandeau-normandie img {
        width: 100%;
        display: block;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(26,58,107,.2);
    }

    .page-header {
        background: #003087;
        color: #fff;
        padding: .75rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .page-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; }
    .page-header .badge-ecran {
        background: rgba(255,255,255,.2);
        font-size: .7rem;
        padding: .25em .6em;
        border-radius: .25rem;
        letter-spacing: .5px;
    }

    .card-adresse {
        max-width: 520px;
        margin: 2rem auto;
        border-radius: .75rem;
        box-shadow: 0 4px 20px rgba(0,0,0,.12);
    }
    .card-adresse .card-header {
        background: #003087;
        color: #fff;
        border-radius: .75rem .75rem 0 0;
        font-weight: 700;
        font-size: 1rem;
        padding: 1rem 1.25rem;
    }

    .ja-identity {
        background: #e8f0fb;
        border-radius: .5rem;
        padding: .75rem 1rem;
        margin-bottom: 1.25rem;
    }
    .ja-identity .ja-nom { font-size: 1.15rem; font-weight: 700; color: #003087; }
    .ja-identity .ja-grade { font-size: .85rem; color: #555; }

    .adresse-actuelle {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        padding: .6rem 1rem;
        margin-bottom: 1.25rem;
        font-size: .9rem;
    }
    .adresse-actuelle .lbl { font-weight: 700; color: #444; }
    .adresse-actuelle .val { color: #003087; font-weight: 600; }
    .adresse-actuelle .val.none { color: #999; font-style: italic; }

    #suggestions-bloc {
        display: none;
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: .4rem;
        padding: .5rem .75rem;
        margin-top: .5rem;
    }
    #suggestions-bloc .titre { font-size: .8rem; font-weight: 700; color: #856404; margin-bottom: .4rem; }
    #suggestions-bloc .btn-suggestion {
        margin: .2rem .2rem 0 0;
        font-size: .8rem;
    }

    #laposte-status { min-height: 1.4em; font-size: .85rem; margin-top: .3rem; }
    #laposte-status.ok   { color: #065f46; }
    #laposte-status.err  { color: #c00; }

    #btn-valider {
        width: 100%;
        padding: .65rem;
        font-size: 1rem;
        font-weight: 700;
    }

    .succes-bloc {
        display: none;
        text-align: center;
        padding: 2rem 1rem;
    }
    .succes-bloc .bi { font-size: 3rem; color: #198754; }
    .succes-bloc p { font-size: 1.05rem; margin-top: .75rem; color: #155724; font-weight: 600; }
</style>
</head>
<body>

<div class="page-header">
    <h1><i class="bi bi-geo-alt-fill me-2"></i>Adresse domicile – Juge-Arbitre</h1>
    <span class="badge-ecran">E029</span>
</div>

<div class="container-fluid">

<div id="bandeau-normandie">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="../img/FFTT_LIGUE.png" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<?php if ($erreur): ?>
    <div class="alert alert-danger mt-4 mx-auto" style="max-width:520px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($erreur) ?>
    </div>
<?php else: ?>

<div class="card card-adresse">
    <div class="card-header">
        <i class="bi bi-house-fill me-2"></i>Renseigner mon adresse domicile
    </div>
    <div class="card-body p-4">

        <!-- Identité JA -->
        <div class="ja-identity">
            <div class="ja-nom"><?= htmlspecialchars(strtoupper($ja['Nom']) . ' ' . $ja['Prenom']) ?></div>
            <div class="ja-grade"><?= htmlspecialchars($ja['Grade'] ?? '') ?></div>
        </div>

        <!-- Adresse actuelle -->
        <div class="adresse-actuelle">
            <span class="lbl">Adresse actuelle&nbsp;: </span>
            <?php if ($ja['Cp'] || $ja['Ville']): ?>
                <span class="val"><?= htmlspecialchars(trim(($ja['Cp'] ?? '') . ' ' . ($ja['Ville'] ?? ''))) ?></span>
            <?php else: ?>
                <span class="val none">Non renseignée</span>
            <?php endif; ?>
        </div>

        <!-- Formulaire -->
        <div id="form-adresse">
            <div class="row g-2 mb-2">
                <div class="col-4">
                    <label class="form-label fw-semibold" for="inp-cp">Code postal</label>
                    <input type="text" id="inp-cp" class="form-control"
                           maxlength="5" placeholder="Exp :76000"
                           value="<?= htmlspecialchars($ja['Cp'] ?? '') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold" for="inp-ville">Ville</label>
                    <div class="input-group">
                        <input type="text" id="inp-ville" class="form-control"
                               maxlength="80" placeholder="Exp :ROUEN"
                               value="<?= htmlspecialchars($ja['Ville'] ?? '') ?>">
                        <button class="btn btn-outline-secondary" id="btn-chercher" type="button" title="Rechercher">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div id="laposte-status"></div>

            <!-- Suggestions (CP ambigu) -->
            <div id="suggestions-bloc">
                <div class="titre"><i class="bi bi-list-ul me-1"></i>Plusieurs communes trouvées — choisissez :</div>
                <div id="suggestions-list"></div>
            </div>

            <div class="mt-3">
                <button id="btn-valider" class="btn btn-success" disabled>
                    <i class="bi bi-floppy me-1"></i>Enregistrer mon adresse
                </button>
            </div>
        </div>

        <!-- Message succès -->
        <div class="succes-bloc" id="succes-bloc">
            <i class="bi bi-check-circle-fill"></i>
            <p>Votre adresse a bien été enregistrée.</p>
            <div id="succes-adresse" class="text-muted mb-3" style="font-size:.9rem"></div>
            <p style="font-size:1rem;color:#1a3a6b;font-weight:700;">
                Merci pour votre contribution !<br>
                <span style="font-size:.9rem;font-weight:400;color:#555;">
                    La Ligue Normandie de Tennis de Table vous remercie d'avoir renseigné votre adresse domicile.
                </span>
            </p>
        </div>

    </div>
</div>

<?php endif; ?>
</div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/nijac-csrf.js"></script>
<script>
'use strict';
const ID_JA = <?= (int)$idJa ?>;
let idLaPoste = <?= $ja && $ja['Id_LaPoste'] ? (int)$ja['Id_LaPoste'] : 'null' ?>;

function setStatus(msg, type) {
    $('#laposte-status').text(msg).removeClass('ok err').addClass(type);
}

function activerBouton() {
    $('#btn-valider').prop('disabled', !idLaPoste);
}

function choisirCommune(cp, ville, id) {
    idLaPoste = id;
    $('#inp-cp').val(cp);
    $('#inp-ville').val(ville);
    $('#suggestions-bloc').hide();
    setStatus('✓ ' + cp + ' ' + ville, 'ok');
    activerBouton();
}

function rechercherLaPoste() {
    const cp    = $('#inp-cp').val().trim();
    const ville = $('#inp-ville').val().trim();
    if (!cp && !ville) {
        idLaPoste = null;
        setStatus('', '');
        activerBouton();
        return;
    }

    setStatus('Recherche…', '');
    idLaPoste = null;
    activerBouton();
    $('#suggestions-bloc').hide();
    $('#suggestions-list').empty();

    $.post('adresse_ja.php', { action: 'recherche_laposte', cp, ville }, function (res) {
        if (res.multi) {
            setStatus('', '');
            const $list = $('#suggestions-list').empty();
            res.suggestions.forEach(function (s) {
                $('<button>').addClass('btn btn-sm btn-outline-primary btn-suggestion')
                    .text(s.cp + ' ' + s.ville)
                    .on('click', function () { choisirCommune(s.cp, s.ville, s.id_laposte); })
                    .appendTo($list);
            });
            $('#suggestions-bloc').show();
            return;
        }
        if (res.ok) {
            choisirCommune(res.cp, res.ville, res.id_laposte);
        } else {
            setStatus('Commune non trouvée — vérifiez le code postal ou la ville.', 'err');
        }
    }, 'json').fail(function () {
        setStatus('Erreur réseau.', 'err');
    });
}

$('#btn-chercher').on('click', rechercherLaPoste);
$('#inp-cp, #inp-ville').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); rechercherLaPoste(); }
});
$('#inp-cp, #inp-ville').on('input', function () {
    idLaPoste = null;
    $('#laposte-status').text('').removeClass('ok err');
    $('#suggestions-bloc').hide();
    activerBouton();
});

$('#btn-valider').on('click', function () {
    if (!idLaPoste) return;
    const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…');
    $.post('adresse_ja.php', {
        action:     'sauvegarder',
        id_ja:      ID_JA,
        id_laposte: idLaPoste,
        cp:         $('#inp-cp').val().trim(),
        ville:      $('#inp-ville').val().trim(),
    }, function (r) {
        if (r.ok) {
            $('#form-adresse').hide();
            $('#succes-adresse').text($('#inp-cp').val().trim() + ' ' + $('#inp-ville').val().trim());
            $('#succes-bloc').show();
        } else {
            $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mon adresse');
            setStatus(r.err || 'Erreur inconnue.', 'err');
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mon adresse');
        setStatus('Erreur réseau.', 'err');
    });
});

// Activer le bouton si une adresse est déjà connue
activerBouton();
<?php if ($ja && $ja['Cp']): ?>
if (idLaPoste) setStatus('✓ <?= addslashes(($ja['Cp'] ?? '') . ' ' . ($ja['Ville'] ?? '')) ?>', 'ok');
<?php endif; ?>
</script>
</body>
</html>
