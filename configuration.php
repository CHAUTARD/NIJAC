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
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';

// ── Sécurité ──────────────────────────────────────────────────────────────────
require __DIR__ . '/includes/admin_required.php';
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Lire tous les paramètres ──────────────────────────────────────────
        if ($action === 'lire') {
            $rows = $pdo->query(
                'SELECT cle, valeur, description FROM configuration ORDER BY cle'
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

            // Validation URL pour url_ligue
            if ($cle === 'url_ligue') {
                if ($valeur === '' || !filter_var($valeur, FILTER_VALIDATE_URL)) {
                    ob_end_clean();
                    echo json_encode(['ok' => false, 'msg' => 'Adresse du site invalide.']);
                    exit;
                }
            }

            // Validation numérique pour indemnité forfaitaire et frais kilométriques
            if (in_array($cle, ['indemnite_forfaitaire', 'frais_kilometrique'], true)) {
                $valeurNum = str_replace(',', '.', $valeur);
                if ($valeurNum === '' || !is_numeric($valeurNum) || (float)$valeurNum < 0) {
                    ob_end_clean();
                    echo json_encode(['ok' => false, 'msg' => 'Valeur numérique positive attendue.']);
                    exit;
                }
                $valeur = number_format((float)$valeurNum, 2, '.', '');
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

            // Validation départements_limitrophes : liste de numéros séparés par virgule
            if ($cle === 'departements_limitrophes') {
                $deptsValides = ['28', '35', '53', '60', '72', '78', '80', '95'];
                $depts = array_filter(array_map('trim', explode(',', $valeur)));
                foreach ($depts as $d) {
                    if (!in_array($d, $deptsValides, true)) {
                        ob_end_clean();
                        echo json_encode(['ok' => false, 'msg' => "Département limitrophe « $d » non reconnu."]);
                        exit;
                    }
                }
                $valeur = implode(',', $depts);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO configuration (cle, valeur) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
            );
            $stmt->execute([$cle, $valeur]);

            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Paramètre enregistré.', 'cle' => $cle, 'valeur' => $valeur]);
            exit;
        }

        // ── Test SMTP production ──────────────────────────────────────────────
        if ($action === 'smtp_test_prod') {
            $destinataire = trim($_POST['destinataire'] ?? '');
            if ($destinataire === '' || !filter_var($destinataire, FILTER_VALIDATE_EMAIL)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Adresse destinataire invalide.']);
                exit;
            }
            $mail = getNijacMailer('smtp_');
            $mail->addAddress($destinataire);
            $mail->Subject = '[NIJAC] Test SMTP production';
            $mail->isHTML(false);
            $mail->Body = "Cet email confirme que la configuration SMTP de production est opérationnelle.\n\nEnvoyé depuis " . gethostname();
            $mail->send();
            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => "Email de test envoyé à $destinataire."]);
            exit;
        }

        // ── Gestion complète : créer une ligne ─────────────────────────────
        if ($action === 'table_creer') {
            $cle         = trim($_POST['cle']         ?? '');
            $valeur      = (string)($_POST['valeur']      ?? '');
            $description = (string)($_POST['description'] ?? '');

            if ($cle === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'La clé est obligatoire.']);
                exit;
            }
            if (!preg_match('/^[a-z0-9_]+$/i', $cle)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'La clé ne doit contenir que lettres, chiffres et underscores.']);
                exit;
            }

            $existe = $pdo->prepare('SELECT 1 FROM configuration WHERE cle = ?');
            $existe->execute([$cle]);
            if ($existe->fetchColumn()) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => "La clé « $cle » existe déjà."]);
                exit;
            }

            $pdo->prepare('INSERT INTO configuration (cle, valeur, description) VALUES (?, ?, ?)')
                ->execute([$cle, $valeur, $description ?: null]);

            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Ligne créée.']);
            exit;
        }

        // ── Gestion complète : modifier une ligne (tous les champs) ────────
        if ($action === 'table_modifier') {
            $cleOriginale = trim($_POST['cle_originale'] ?? '');
            $cle          = trim($_POST['cle']           ?? '');
            $valeur       = (string)($_POST['valeur']       ?? '');
            $description  = (string)($_POST['description']  ?? '');

            if ($cleOriginale === '' || $cle === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Clé manquante.']);
                exit;
            }
            if (!preg_match('/^[a-z0-9_]+$/i', $cle)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'La clé ne doit contenir que lettres, chiffres et underscores.']);
                exit;
            }

            if ($cle !== $cleOriginale) {
                $existe = $pdo->prepare('SELECT 1 FROM configuration WHERE cle = ?');
                $existe->execute([$cle]);
                if ($existe->fetchColumn()) {
                    ob_end_clean();
                    echo json_encode(['ok' => false, 'msg' => "La clé « $cle » existe déjà."]);
                    exit;
                }
            }

            $pdo->prepare('UPDATE configuration SET cle = ?, valeur = ?, description = ? WHERE cle = ?')
                ->execute([$cle, $valeur, $description ?: null, $cleOriginale]);

            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Ligne modifiée.']);
            exit;
        }

        // ── Gestion complète : supprimer une ligne ──────────────────────────
        if ($action === 'table_supprimer') {
            $cle = trim($_POST['cle'] ?? '');
            if ($cle === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Clé manquante.']);
                exit;
            }
            $pdo->prepare('DELETE FROM configuration WHERE cle = ?')->execute([$cle]);
            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Ligne supprimée.']);
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
    $deptsLimitrophes = getConfig('departements_limitrophes', '60,80,95,78,28,53,72,35');
    $urlLigue         = getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr');
    $indemniteForfait  = getConfig('indemnite_forfaitaire', '25.00');
    $fraisKm           = getConfig('frais_kilometrique', '0.30');
    $cpteFreisKm       = getConfig('compte_frais_km', '62511');
    $cptePrestations   = getConfig('compte_prestations', '62261');
    $codeAnalytique    = getConfig('code_analytique_compta', '04EPR232');
    $phase1Debut       = getConfig('phase1_debut', '09-01');
    $phase1Fin         = getConfig('phase1_fin',   '01-31');
    $phase2Debut       = getConfig('phase2_debut', '02-01');
    $phase2Fin         = getConfig('phase2_fin',   '06-30');
    // SMTP production
    $smtpProdHost      = getConfig('smtp_host',      '');
    $smtpProdPort      = getConfig('smtp_port',      '587');
    $smtpProdSecure    = getConfig('smtp_secure',    'tls');
    $smtpProdAuth      = getConfig('smtp_auth',      '1');
    $smtpProdUser      = getConfig('smtp_user',      '');
    $smtpProdPassword  = getConfig('smtp_password',  '');
    $smtpProdFrom      = getConfig('smtp_from',      '');
    $smtpProdFromName  = getConfig('smtp_from_name', '');
} catch (\Throwable $e) {
    $etatCourant      = 'Developpement';
    $emailDev         = 'patrick.chautard@free.fr';
    $deptsActifs      = '14,27,50,61,76';
    $reglesDepts      = '{"76":["27"]}';
    $urlLigue         = 'https://www.ligue-normandie-tt.fr';
    $indemniteForfait = '25.00';
    $fraisKm          = '0.30';
    $cpteFreisKm      = '62511';
    $cptePrestations  = '62261';
    $codeAnalytique   = '04EPR232';
    $phase1Debut      = '09-01';
    $phase1Fin        = '01-31';
    $phase2Debut      = '02-01';
    $phase2Fin        = '06-30';
    $smtpProdHost      = $smtpProdPort = $smtpProdUser = $smtpProdPassword = $smtpProdFrom = $smtpProdFromName = '';
    $smtpProdSecure    = 'tls'; $smtpProdAuth = '1';
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
            height: 100vh;
            overflow: hidden;
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
        #input-email-dev, #input-url-ligue, #input-indemnite, #input-frais-km {
            flex: 1; min-width: 0;
            border: 2px solid #c8d4e8; border-radius: 6px;
            padding: .42rem .75rem; font-size: .9rem;
            transition: border-color .2s;
        }
        #input-email-dev:focus, #input-url-ligue:focus,
        #input-indemnite:focus, #input-frais-km:focus { outline: none; border-color: #1a3a6b; }
        #input-email-dev.is-invalid, #input-url-ligue.is-invalid,
        #input-indemnite.is-invalid, #input-frais-km.is-invalid { border-color: #dc2626; }
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

        /* ── Bouton départements ── */
        #btn-sauvegarder-depts {
            padding: .42rem 1.4rem;
            font-size: .88rem; font-weight: 700;
            background: var(--nijac-blue); color: #fff;
            border: none; border-radius: 6px; cursor: pointer;
            white-space: nowrap; transition: background .2s;
        }
        #btn-sauvegarder-depts:hover:not(:disabled) { background: #2557a7; }
        #btn-sauvegarder-depts:disabled { opacity: .5; cursor: default; }

        /* ── Onglets ── */
        #config-tabs .nav-link {
            font-size: .85rem; font-weight: 600; color: #555;
            border: none; border-bottom: 3px solid transparent;
            padding: .65rem 1.1rem;
        }
        #config-tabs .nav-link.active {
            color: var(--nijac-blue); border-bottom-color: var(--nijac-blue);
            background: transparent;
        }
        .tab-content { display: flex; flex-direction: column; }
        #tab-table { flex: 1; display: flex; flex-direction: column; padding: 1.25rem 1.5rem; }

        /* ── Gestion complète : table configuration ── */
        #table-toolbar {
            display: flex; align-items: center; gap: .75rem;
            margin-bottom: .9rem;
        }
        #search-input-config {
            font-size: .85rem; padding: .2rem .5rem;
            border: 1px solid #c8d4e8; border-radius: 4px; width: 240px;
        }
        #table-grid-wrapper { flex: 1; overflow: auto; }
        .table-config {
            width: 100%; font-size: .83rem; border-collapse: collapse;
            background: #fff;
        }
        .table-config thead th {
            background: #e8eef7; border: 1px solid #c8d4e8;
            padding: .4rem .6rem; text-align: left; white-space: nowrap;
            position: sticky; top: 0; z-index: 1;
            cursor: pointer; user-select: none;
        }
        .table-config thead th:hover { background: #d4dff0; }
        .table-config thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        .table-config thead th.sort-asc .sort-icon::after  { content: '▲'; opacity: 1; }
        .table-config thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        .table-config thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        .table-config thead th[style*="width:90px"] { cursor: default; }
        .table-config thead th[style*="width:90px"]:hover { background: #e8eef7; }

        .table-config tbody tr { border-bottom: 1px solid #e0e8f0; }
        .table-config tbody tr:nth-child(even) { background: #f7faff; }
        .table-config tbody tr:hover { background: #dce8f8; }
        .table-config tbody tr.selected { background: #b8d0f0 !important; }
        .table-config tbody tr.new-row { background: #fffbe6 !important; }
        .table-config tbody td { border: 1px solid #e0e8f0; padding: 0; vertical-align: top; }

        .table-config .cell-inner {
            display: block; padding: .28rem .5rem; min-height: 28px;
            outline: none; white-space: pre-wrap; word-break: break-word;
        }
        .table-config .cell-inner[contenteditable="true"] {
            background: #fffbe6; outline: 2px solid #f0a000; outline-offset: -2px;
        }
        .table-config td.col-cle .cell-inner { font-weight: 700; font-family: monospace; }

        .table-config .row-actions { display: flex; gap: .35rem; white-space: nowrap; padding: .25rem .4rem; }
        .table-config .row-actions button {
            font-size: .78rem; padding: .2rem .5rem; border-radius: 4px;
            border: 1px solid #c8d4e8; background: #f7faff; cursor: pointer;
        }
        .table-config .row-actions .btn-save { color: #1565c0; }
        .table-config .row-actions .btn-del  { color: #c0392b; }
        .table-config .row-actions button:hover { background: #e8eef7; }

        /* ── Carte SMTP ── */
        .smtp-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }
        .smtp-panel {
            border: 2px solid #c8d4e8;
            border-radius: 8px;
            overflow: hidden;
        }
        .smtp-panel-head {
            padding: .55rem 1rem;
            font-size: .88rem; font-weight: 700;
            display: flex; align-items: center; gap: .5rem;
        }
        .smtp-panel-head.local { background: #fffbe6; color: #78350f; border-bottom: 2px solid #f59e0b; }
        .smtp-panel-head.prod  { background: #f0fdf4; color: #065f46; border-bottom: 2px solid #22c55e; }
        .smtp-panel-body { padding: 1rem; }
        .smtp-field { margin-bottom: .85rem; }
        .smtp-field:last-child { margin-bottom: 0; }
        .smtp-field label { font-size: .82rem; font-weight: 600; color: #374151; display: block; margin-bottom: .25rem; }
        .smtp-field input, .smtp-field select {
            width: 100%; border: 2px solid #c8d4e8; border-radius: 6px;
            padding: .38rem .65rem; font-size: .88rem;
            transition: border-color .2s;
        }
        .smtp-field input:focus, .smtp-field select:focus { outline: none; border-color: #1a3a6b; }
        .smtp-field input.is-invalid { border-color: #dc2626; }
        .smtp-save-row { display: flex; align-items: center; gap: .75rem; margin-top: 1rem; }
        .smtp-save-row button {
            padding: .42rem 1.4rem; font-size: .88rem; font-weight: 700;
            color: #fff; border: none; border-radius: 6px; cursor: pointer;
            white-space: nowrap; transition: background .2s;
        }
        .smtp-save-row button.local { background: #d97706; }
        .smtp-save-row button.local:hover:not(:disabled) { background: #b45309; }
        .smtp-save-row button.prod  { background: #16a34a; }
        .smtp-save-row button.prod:hover:not(:disabled)  { background: #15803d; }
        .smtp-save-row button:disabled { opacity: .5; cursor: default; }
        .smtp-msg { font-size: .82rem; min-height: 18px; }

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

<?php $pageIcon = 'bi-gear-fill'; $pageTitle = 'Configuration générale'; $pageCode = 'E015'; $backUrl = 'admin_menu.php'; require __DIR__ . '/includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs" id="config-tabs" role="tablist" style="background:#fff;padding:0 1.5rem;flex-shrink:0;">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-params-btn" data-bs-toggle="tab" data-bs-target="#tab-params" type="button" role="tab">
            <i class="bi bi-sliders me-1"></i>Paramètres
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-table-btn" data-bs-toggle="tab" data-bs-target="#tab-table" type="button" role="tab">
            <i class="bi bi-table me-1"></i>Gestion complète
        </button>
    </li>
</ul>

<div class="tab-content" style="flex:1;overflow:auto;">
<div class="tab-pane fade show active" id="tab-params" role="tabpanel">
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

    <!-- ── Paramètre : Site de la ligue ── -->
    <div class="param-card" style="grid-column:1 / -1">
        <div class="param-card-head">
            <i class="bi bi-globe2 param-icon"></i>
            <div>
                <h2>Site de la ligue</h2>
                <small>Adresse du serveur de la Ligue de Normandie de Tennis de Table</small>
            </div>
        </div>
        <div class="param-card-body">

            <p style="font-size:.85rem;color:#374151;margin-bottom:1.1rem;">
                Adresse utilisée pour les liens vers le site de la ligue depuis l'application.
            </p>

            <div class="email-dev-group">
                <label for="input-url-ligue">
                    <i class="bi bi-link-45deg me-1"></i>URL du site
                </label>
                <div class="email-dev-row">
                    <input type="url" id="input-url-ligue"
                           value="<?= htmlspecialchars($urlLigue) ?>"
                           placeholder="https://www.ligue-normandie-tt.fr"
                           autocomplete="off">
                    <button id="btn-sauvegarder-url-ligue">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-url-ligue"></div>
            </div>

        </div>
    </div>

    <!-- ── Paramètre : Frais JA (ex-table Competition) ── -->
    <div class="param-card">
        <div class="param-card-head">
            <i class="bi bi-cash-coin param-icon"></i>
            <div>
                <h2>Indemnités et frais JA</h2>
                <small>Barème appliqué aux convocations (remplace la table Compétition)</small>
            </div>
        </div>
        <div class="param-card-body">

            <p style="font-size:.85rem;color:#374151;margin-bottom:1.1rem;">
                Indemnité forfaitaire et barème kilométrique utilisés par défaut sur les convocations des JA.
            </p>

            <div class="email-dev-group mb-3">
                <label for="input-indemnite">
                    <i class="bi bi-cash me-1"></i>Indemnité forfaitaire (€)
                </label>
                <div class="email-dev-row">
                    <input type="number" id="input-indemnite" step="0.01" min="0"
                           value="<?= htmlspecialchars($indemniteForfait) ?>"
                           autocomplete="off">
                    <button id="btn-sauvegarder-indemnite">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-indemnite"></div>
            </div>

            <div class="email-dev-group">
                <label for="input-frais-km">
                    <i class="bi bi-signpost-split me-1"></i>Frais kilométriques (€/km)
                </label>
                <div class="email-dev-row">
                    <input type="number" id="input-frais-km" step="0.01" min="0"
                           value="<?= htmlspecialchars($fraisKm) ?>"
                           autocomplete="off">
                    <button id="btn-sauvegarder-frais-km">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-frais-km"></div>
            </div>

            <hr style="margin:1.2rem 0;">
            <p style="font-size:.85rem;color:#374151;margin-bottom:1rem;">
                <i class="bi bi-journal-text me-1"></i><strong>Paramètres comptables EBP</strong> — utilisés pour l'export du journal AC (E025).
            </p>

            <div class="email-dev-group mb-3">
                <label for="input-cpte-frais-km">
                    <i class="bi bi-hash me-1"></i>N° compte frais km + péages (62511)
                </label>
                <div class="email-dev-row">
                    <input type="text" id="input-cpte-frais-km" maxlength="20"
                           value="<?= htmlspecialchars($cpteFreisKm) ?>"
                           autocomplete="off">
                    <button id="btn-sauvegarder-cpte-frais-km">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-cpte-frais-km"></div>
            </div>

            <div class="email-dev-group mb-3">
                <label for="input-cpte-prestations">
                    <i class="bi bi-hash me-1"></i>N° compte prestations / indemnités (62261)
                </label>
                <div class="email-dev-row">
                    <input type="text" id="input-cpte-prestations" maxlength="20"
                           value="<?= htmlspecialchars($cptePrestations) ?>"
                           autocomplete="off">
                    <button id="btn-sauvegarder-cpte-prestations">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-cpte-prestations"></div>
            </div>

            <div class="email-dev-group">
                <label for="input-code-analytique">
                    <i class="bi bi-tag me-1"></i>Code analytique (poste analytique dépenses)
                </label>
                <div class="email-dev-row">
                    <input type="text" id="input-code-analytique" maxlength="20"
                           value="<?= htmlspecialchars($codeAnalytique) ?>"
                           autocomplete="off">
                    <button id="btn-sauvegarder-code-analytique">
                        <i class="bi bi-floppy-fill me-1"></i>Enregistrer
                    </button>
                </div>
                <div id="msg-result-code-analytique"></div>
            </div>

            <hr style="margin:1.2rem 0;">
            <p style="font-size:.85rem;color:#374151;margin-bottom:1rem;">
                <i class="bi bi-calendar2-range me-1"></i><strong>Phases de saison</strong> — bornes utilisées pour le filtre rapide dans E025 (format MM-JJ).
            </p>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                <div class="email-dev-group">
                    <label><i class="bi bi-1-circle me-1"></i>Phase 1 — début (ex. MM-JJ)</label>
                    <div class="email-dev-row">
                        <input type="text" id="input-phase1-debut" maxlength="5" placeholder="MM-JJ"
                               value="<?= htmlspecialchars($phase1Debut) ?>" autocomplete="off">
                        <button id="btn-phase1-debut"><i class="bi bi-floppy-fill me-1"></i>Enregistrer</button>
                    </div>
                    <div id="msg-phase1-debut"></div>
                </div>
                <div class="email-dev-group">
                    <label><i class="bi bi-1-circle me-1"></i>Phase 1 — fin (ex. MM-JJ)</label>
                    <div class="email-dev-row">
                        <input type="text" id="input-phase1-fin" maxlength="5" placeholder="MM-JJ"
                               value="<?= htmlspecialchars($phase1Fin) ?>" autocomplete="off">
                        <button id="btn-phase1-fin"><i class="bi bi-floppy-fill me-1"></i>Enregistrer</button>
                    </div>
                    <div id="msg-phase1-fin"></div>
                </div>
                <div class="email-dev-group">
                    <label><i class="bi bi-2-circle me-1"></i>Phase 2 — début (ex. MM-JJ)</label>
                    <div class="email-dev-row">
                        <input type="text" id="input-phase2-debut" maxlength="5" placeholder="MM-JJ"
                               value="<?= htmlspecialchars($phase2Debut) ?>" autocomplete="off">
                        <button id="btn-phase2-debut"><i class="bi bi-floppy-fill me-1"></i>Enregistrer</button>
                    </div>
                    <div id="msg-phase2-debut"></div>
                </div>
                <div class="email-dev-group">
                    <label><i class="bi bi-2-circle me-1"></i>Phase 2 — fin (ex. MM-JJ)</label>
                    <div class="email-dev-row">
                        <input type="text" id="input-phase2-fin" maxlength="5" placeholder="MM-JJ"
                               value="<?= htmlspecialchars($phase2Fin) ?>" autocomplete="off">
                        <button id="btn-phase2-fin"><i class="bi bi-floppy-fill me-1"></i>Enregistrer</button>
                    </div>
                    <div id="msg-phase2-fin"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Paramètre : Départements & règles d'association ── -->
    <div class="param-card">
        <div class="param-card-head">
            <i class="bi bi-map-fill param-icon"></i>
            <div>
                <h2>Départements concernés &amp; règle particulière</h2>
                <small>Départements gérés par la ligue et règles d'association entre départements</small>
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

    <!-- ── Paramètre : Départements limitrophes ── -->
    <div class="param-card" style="grid-column:1 / -1">
        <div class="param-card-head">
            <i class="bi bi-sign-intersection-fill param-icon"></i>
            <div>
                <h2>Départements limitrophes</h2>
                <small>Départements des régions voisines concernés par les rencontres inter-régionales</small>
            </div>
        </div>
        <div class="param-card-body">
            <p style="font-size:.85rem;color:#374151;margin-bottom:1.1rem;">
                Cochez les départements limitrophes à prendre en compte. Ces départements
                appartiennent aux régions voisines de la Normandie et peuvent accueillir
                des rencontres inter-régionales.
            </p>

            <?php
            $limitrophesCoches = array_filter(array_map('trim', explode(',', $deptsLimitrophes)));
            $groupes = [
                'Hauts-de-France'       => [['60','Oise'], ['80','Somme']],
                'Île-de-France / Centre'=> [['95',"Val-d'Oise"], ['78','Yvelines'], ['28','Eure-et-Loir']],
                'Pays de la Loire'      => [['53','Mayenne'], ['72','Sarthe']],
                'Bretagne'              => [['35','Ille-et-Vilaine']],
            ];
            foreach ($groupes as $region => $depts): ?>
            <div style="margin-bottom:.85rem;">
                <div style="font-size:.8rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.4rem;">
                    <?= htmlspecialchars($region) ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;">
                    <?php foreach ($depts as [$code, $nom]): ?>
                    <label style="display:flex;align-items:center;gap:.4rem;font-size:.88rem;cursor:pointer;user-select:none;">
                        <input type="checkbox" class="limitrophe-check" value="<?= $code ?>"
                               <?= in_array($code, $limitrophesCoches, true) ? 'checked' : '' ?>
                               style="width:1rem;height:1rem;cursor:pointer;">
                        <span><?= $code ?> — <?= htmlspecialchars($nom) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="d-flex align-items-center gap-3 mt-3">
                <button id="btn-sauvegarder-limitrophes">
                    <i class="bi bi-floppy-fill me-1"></i>Enregistrer les départements limitrophes
                </button>
                <div id="msg-result-limitrophes" style="font-size:.82rem;min-height:18px;"></div>
            </div>
        </div>
    </div>

    <!-- ── Paramètre : Configuration SMTP ── -->
    <div class="param-card" style="grid-column:1 / -1">
        <div class="param-card-head">
            <i class="bi bi-send-fill param-icon"></i>
            <div>
                <h2>Configuration SMTP</h2>
                <small>Serveurs d'envoi d'emails — local (développement) et distant (production)</small>
            </div>
        </div>
        <div class="param-card-body">

            <p style="font-size:.85rem;color:#374151;margin-bottom:1.25rem;">
                Le serveur utilisé est sélectionné automatiquement selon l'environnement d'exécution
                (présence du fichier <code>.env.production</code> ou variable <code>NIJAC_ENV=production</code>).
            </p>

            <div class="smtp-grid">

                <!-- Production -->
                <div class="smtp-panel">
                    <div class="smtp-panel-head prod">
                        <i class="bi bi-cloud-upload-fill"></i>Distant (production)
                    </div>
                    <div class="smtp-panel-body">
                        <div class="smtp-field">
                            <label for="smtp-prod-host"><i class="bi bi-hdd-network me-1"></i>Serveur SMTP (host)</label>
                            <input type="text" id="smtp-prod-host" value="<?= htmlspecialchars($smtpProdHost) ?>" placeholder="ex : mail.ligue-normandie-tt.fr">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                            <div class="smtp-field">
                                <label for="smtp-prod-port"><i class="bi bi-plug me-1"></i>Port</label>
                                <input type="number" id="smtp-prod-port" value="<?= htmlspecialchars($smtpProdPort) ?>" min="1" max="65535" placeholder="587">
                            </div>
                            <div class="smtp-field">
                                <label for="smtp-prod-secure"><i class="bi bi-shield-lock me-1"></i>Sécurité</label>
                                <select id="smtp-prod-secure">
                                    <option value="tls"  <?= $smtpProdSecure === 'tls'  ? 'selected' : '' ?>>STARTTLS</option>
                                    <option value="ssl"  <?= $smtpProdSecure === 'ssl'  ? 'selected' : '' ?>>SSL/TLS</option>
                                    <option value=""     <?= $smtpProdSecure === ''     ? 'selected' : '' ?>>Aucune</option>
                                </select>
                            </div>
                        </div>
                        <div class="smtp-field">
                            <label for="smtp-prod-user"><i class="bi bi-person me-1"></i>Utilisateur</label>
                            <input type="text" id="smtp-prod-user" value="<?= htmlspecialchars($smtpProdUser) ?>" autocomplete="off">
                        </div>
                        <div class="smtp-field">
                            <label for="smtp-prod-password"><i class="bi bi-key me-1"></i>Mot de passe</label>
                            <div class="input-group">
                                <input type="password" id="smtp-prod-password" class="form-control" value="<?= htmlspecialchars($smtpProdPassword) ?>" autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary btn-toggle-pwd" data-target="smtp-prod-password" tabindex="-1">
                                    <i class="bi bi-eye-slash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="smtp-field">
                            <label for="smtp-prod-from"><i class="bi bi-envelope me-1"></i>Adresse expéditeur (From)</label>
                            <input type="email" id="smtp-prod-from" value="<?= htmlspecialchars($smtpProdFrom) ?>" placeholder="noreply@domaine.fr">
                        </div>
                        <div class="smtp-field">
                            <label for="smtp-prod-from-name"><i class="bi bi-person-badge me-1"></i>Nom expéditeur</label>
                            <input type="text" id="smtp-prod-from-name" value="<?= htmlspecialchars($smtpProdFromName) ?>" placeholder="NIJAC">
                        </div>
                        <div class="smtp-save-row">
                            <button class="prod" id="btn-smtp-prod"><i class="bi bi-floppy-fill me-1"></i>Enregistrer (distant)</button>
                            <span class="smtp-msg" id="msg-smtp-prod"></span>
                        </div>

                        <hr style="margin:.9rem 0;border-color:#e0e8f0;">

                        <div class="smtp-field" style="margin-bottom:.6rem;">
                            <label for="smtp-test-dest"><i class="bi bi-send-check me-1"></i>Destinataire du test</label>
                            <input type="email" id="smtp-test-dest"
                                   value="<?= htmlspecialchars($emailDev) ?>"
                                   placeholder="test@domaine.fr">
                        </div>
                        <div class="smtp-save-row">
                            <button class="prod" id="btn-smtp-test-prod" style="background:#1a3a6b;">
                                <i class="bi bi-envelope-check me-1"></i>Envoyer un email de test
                            </button>
                            <span class="smtp-msg" id="msg-smtp-test-prod"></span>
                        </div>
                    </div>
                </div>

            </div><!-- /smtp-grid -->
        </div>
    </div>

</div><!-- /main-content -->
</div><!-- /tab-params -->

<!-- ── Onglet : Gestion complète de la table configuration ── -->
<div class="tab-pane fade" id="tab-table" role="tabpanel">
    <div id="table-toolbar">
        <button class="btn btn-sm btn-success" id="btn-table-ajouter">
            <i class="bi bi-plus-circle me-1"></i>Ajouter une ligne
        </button>
        <span style="flex:1"></span>
        <span id="table-msg" style="font-size:.82rem;margin-right:.75rem"></span>
        <input type="search" id="search-input-config" placeholder="🔍 Rechercher…">
    </div>
    <div id="table-grid-wrapper">
        <table id="tbl-config" class="table-config">
            <thead>
                <tr>
                    <th style="width:220px" data-field="cle">Clé<span class="sort-icon"></span></th>
                    <th data-field="valeur">Valeur<span class="sort-icon"></span></th>
                    <th data-field="description">Description<span class="sort-icon"></span></th>
                    <th style="width:90px">Actions</th>
                </tr>
            </thead>
            <tbody id="tbody-config">
                <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
            </tbody>
        </table>
    </div>
</div><!-- /tab-table -->

</div><!-- /tab-content -->

<?php $statusInitial = 'État actuel : ' . ($etatCourant === 'Developpement' ? 'Développement — emails redirigés' : 'Opérationnel — emails réels'); ?>

<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
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

// ── Enregistrement URL du site de la ligue ───────────────────────────────────
$('#btn-sauvegarder-url-ligue').on('click', function () {
    const val = $('#input-url-ligue').val().trim();

    if (!/^https?:\/\/.+/i.test(val)) {
        $('#input-url-ligue').addClass('is-invalid');
        $('#msg-result-url-ligue').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Adresse du site invalide.</span>');
        return;
    }
    $('#input-url-ligue').removeClass('is-invalid');

    spinner(true);
    $(this).prop('disabled', true);
    $('#msg-result-url-ligue').text('');

    $.post('configuration.php', {
        action: 'enregistrer',
        cle:    'url_ligue',
        valeur: val
    }, function (res) {
        spinner(false);
        $('#btn-sauvegarder-url-ligue').prop('disabled', false);
        if (res.ok) {
            $('#msg-result-url-ligue').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
        } else {
            $('#input-url-ligue').addClass('is-invalid');
            $('#msg-result-url-ligue').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-sauvegarder-url-ligue').prop('disabled', false);
        $('#msg-result-url-ligue').html('<span class="text-danger">Erreur réseau.</span>');
    });
});

$('#input-url-ligue').on('input', function () {
    $(this).removeClass('is-invalid');
    $('#msg-result-url-ligue').text('');
});

// ── Enregistrement indemnité forfaitaire / frais kilométriques ──────────────
function sauvegarderMontant(cle, $input, $msg, $btn) {
    const val = $input.val().trim().replace(',', '.');
    if (val === '' || isNaN(val) || parseFloat(val) < 0) {
        $input.addClass('is-invalid');
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Valeur numérique positive attendue.</span>');
        return;
    }
    $input.removeClass('is-invalid');

    spinner(true);
    $btn.prop('disabled', true);
    $msg.text('');

    $.post('configuration.php', { action: 'enregistrer', cle, valeur: val }, function (res) {
        spinner(false);
        $btn.prop('disabled', false);
        if (res.ok) {
            $msg.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
            $input.val(res.valeur);
        } else {
            $input.addClass('is-invalid');
            $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $btn.prop('disabled', false);
        $msg.html('<span class="text-danger">Erreur réseau.</span>');
    });
}

$('#btn-sauvegarder-indemnite').on('click', function () {
    sauvegarderMontant('indemnite_forfaitaire', $('#input-indemnite'), $('#msg-result-indemnite'), $(this));
});
$('#btn-sauvegarder-frais-km').on('click', function () {
    sauvegarderMontant('frais_kilometrique', $('#input-frais-km'), $('#msg-result-frais-km'), $(this));
});
$('#input-indemnite, #input-frais-km').on('input', function () {
    $(this).removeClass('is-invalid');
    $(this).closest('.email-dev-group').find('[id^=msg-result]').text('');
});

function sauvegarderTexte(cle, $input, $msg, $btn) {
    const val = $input.val().trim();
    if (val === '') {
        $input.addClass('is-invalid');
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Valeur obligatoire.</span>');
        return;
    }
    $input.removeClass('is-invalid');
    spinner(true);
    $btn.prop('disabled', true);
    $msg.text('');
    $.post('configuration.php', { action: 'enregistrer', cle, valeur: val }, function (res) {
        spinner(false);
        $btn.prop('disabled', false);
        if (res.ok) {
            $msg.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
            $input.val(res.valeur);
        } else {
            $input.addClass('is-invalid');
            $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $btn.prop('disabled', false);
        $msg.html('<span class="text-danger">Erreur réseau.</span>');
    });
}

$('#btn-sauvegarder-cpte-frais-km').on('click', function () {
    sauvegarderTexte('compte_frais_km', $('#input-cpte-frais-km'), $('#msg-result-cpte-frais-km'), $(this));
});
$('#btn-sauvegarder-cpte-prestations').on('click', function () {
    sauvegarderTexte('compte_prestations', $('#input-cpte-prestations'), $('#msg-result-cpte-prestations'), $(this));
});
$('#btn-sauvegarder-code-analytique').on('click', function () {
    sauvegarderTexte('code_analytique_compta', $('#input-code-analytique'), $('#msg-result-code-analytique'), $(this));
});

function validerPhaseMD($input, $msg) {
    const val = $input.val().trim();
    if (!/^\d{2}-\d{2}$/.test(val)) {
        $input.addClass('is-invalid');
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Format MM-JJ attendu (ex. 09-01).</span>');
        return false;
    }
    $input.removeClass('is-invalid');
    return true;
}
[
    ['btn-phase1-debut', 'phase1_debut', 'input-phase1-debut', 'msg-phase1-debut'],
    ['btn-phase1-fin',   'phase1_fin',   'input-phase1-fin',   'msg-phase1-fin'],
    ['btn-phase2-debut', 'phase2_debut', 'input-phase2-debut', 'msg-phase2-debut'],
    ['btn-phase2-fin',   'phase2_fin',   'input-phase2-fin',   'msg-phase2-fin'],
].forEach(([btnId, cle, inputId, msgId]) => {
    $(`#${btnId}`).on('click', function () {
        const $input = $(`#${inputId}`);
        const $msg   = $(`#${msgId}`);
        if (!validerPhaseMD($input, $msg)) return;
        sauvegarderTexte(cle, $input, $msg, $(this));
    });
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

// ── Enregistrement départements limitrophes ───────────────────────────────────
$('#btn-sauvegarder-limitrophes').on('click', function () {
    const depts = [];
    $('.limitrophe-check:checked').each(function () { depts.push($(this).val()); });

    spinner(true);
    $(this).prop('disabled', true);
    $('#msg-result-limitrophes').text('');

    $.post('configuration.php', {
        action: 'enregistrer',
        cle:    'departements_limitrophes',
        valeur: depts.join(',')
    }, function (res) {
        spinner(false);
        $('#btn-sauvegarder-limitrophes').prop('disabled', false);
        if (res.ok) {
            $('#msg-result-limitrophes').html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
        } else {
            $('#msg-result-limitrophes').html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-sauvegarder-limitrophes').prop('disabled', false);
        $('#msg-result-limitrophes').html('<span class="text-danger">Erreur réseau.</span>');
    });
});

// ── Onglet : Gestion complète de la table configuration ──────────────────────
let configRows      = [];
let tableChargee    = false;
let cellActiveConfig = null;
let sortFieldConfig  = 'cle';
let sortDirConfig    = 'asc';
let searchTermConfig = '';

function tableMsg(msg, ok = true) {
    $('#table-msg').html(msg ? `<span class="${ok ? 'text-success' : 'text-danger'}">${msg}</span>` : '');
}

function chargerTableConfig() {
    spinner(true);
    $.post('configuration.php', { action: 'lire' }, function (res) {
        spinner(false);
        if (!res.ok) { tableMsg('Erreur de chargement.', false); return; }
        configRows = res.params.map(p => ({ ...p, _nouveau: false, cle_originale: p.cle }));
        renderTableConfig();
    }, 'json').fail(() => { spinner(false); tableMsg('Erreur réseau.', false); });
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesConfigFiltreesTriees() {
    const term = searchTermConfig.toLowerCase();
    let result = term
        ? configRows.filter(r =>
            String(r.cle         ?? '').toLowerCase().includes(term) ||
            String(r.valeur      ?? '').toLowerCase().includes(term) ||
            String(r.description ?? '').toLowerCase().includes(term))
        : [...configRows];

    result.sort((a, b) => {
        const va = String(a[sortFieldConfig] ?? '').toLowerCase();
        const vb = String(b[sortFieldConfig] ?? '').toLowerCase();
        return sortDirConfig === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function majEnteteTriConfig() {
    $('#tbl-config thead th').each(function () {
        const f = $(this).data('field');
        $(this).removeClass('sort-asc sort-desc');
        if (f && f === sortFieldConfig) $(this).addClass(sortDirConfig === 'asc' ? 'sort-asc' : 'sort-desc');
    });
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderTableConfig() {
    const $body = $('#tbody-config').empty();
    majEnteteTriConfig();

    const affichees = lignesConfigFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTermConfig ? 'Aucun résultat pour cette recherche.' : 'Aucun paramètre.';
        $body.append(`<tr><td colspan="5" class="text-center text-muted py-3">${msg}</td></tr>`);
        return;
    }

    affichees.forEach((r) => {
        const idx = configRows.indexOf(r);
        const $tr = $('<tr>').attr('data-idx', idx).toggleClass('new-row', !!r._nouveau);

        $tr.append(makeTdConfig(r.cle,         idx, 'cle'));
        $tr.append(makeTdConfig(r.valeur,      idx, 'valeur'));
        $tr.append(makeTdConfig(r.description, idx, 'description'));

        const $actions = $('<div class="row-actions">').append(
            $('<button type="button" class="btn-save" title="Enregistrer"><i class="bi bi-check-lg"></i></button>')
                .on('click', () => sauvegarderLigneConfig(idx)),
            $('<button type="button" class="btn-del" title="Supprimer"><i class="bi bi-trash3"></i></button>')
                .on('click', () => supprimerLigneConfig(idx))
        );
        $tr.append($('<td>').append($actions));
        $body.append($tr);
    });

    $('#table-msg').text(searchTermConfig
        ? `${affichees.length} résultat(s) sur ${configRows.length}`
        : `${configRows.length} paramètre(s)`);
}

const PWD_KEYS = ['smtp_password'];

function makeTdConfig(val, idx, field) {
    const $td  = $('<td>').addClass(field === 'cle' ? 'col-cle' : '').attr('data-idx', idx).attr('data-field', field);
    const cle  = configRows[idx]?.cle ?? '';

    if (field === 'valeur' && PWD_KEYS.includes(cle)) {
        const $wrap = $('<div>').css({ display: 'flex', alignItems: 'center', gap: '.35rem', padding: '.28rem .5rem' });
        const $div  = $('<div class="cell-inner">').text('••••••••').attr('contenteditable', 'false')
                        .attr('data-real', val ?? '').attr('data-masked', '1')
                        .css({ flex: '1', padding: '0' });
        const $btn  = $('<button type="button" title="Afficher / masquer">')
                        .css({ background: 'none', border: 'none', cursor: 'pointer', padding: '0', lineHeight: '1', color: '#6b7280' })
                        .html('<i class="bi bi-eye-slash"></i>')
                        .on('click', function (e) {
                            e.stopPropagation();
                            const $i = $(this).find('i');
                            const masked = $div.attr('data-masked') === '1';
                            if (masked) {
                                $div.text($div.attr('data-real')).attr('data-masked', '0');
                                $i.removeClass('bi-eye-slash').addClass('bi-eye');
                            } else {
                                $div.text('••••••••').attr('data-masked', '1');
                                $i.removeClass('bi-eye').addClass('bi-eye-slash');
                            }
                        });
        $wrap.append($div, $btn);
        $td.append($wrap);
        $td.on('click', function (e) {
            if (!$(e.target).closest('button').length) selectionnerCelluleConfig($(this));
        });
    } else {
        const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
        $td.append($div);
        $td.on('click', function () { selectionnerCelluleConfig($(this)); });
    }

    return $td;
}

// ── Sélection de cellule ──────────────────────────────────────────────────────
function selectionnerCelluleConfig($td) {
    if (cellActiveConfig) {
        cellActiveConfig.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActiveConfig.closest('tr').removeClass('selected');
    }
    cellActiveConfig = $td;
    $td.closest('tr').addClass('selected');
    tableMsg('Cellule sélectionnée — F2 pour modifier, Échap pour annuler.');
}

// ── Clavier : F2 / Échap / Entrée ────────────────────────────────────────────
$(document).on('keydown', function (e) {
    if (!cellActiveConfig || !$('#tab-table').hasClass('active')) return;
    const $inner = cellActiveConfig.find('.cell-inner');

    if (e.key === 'F2' && $inner.attr('contenteditable') === 'false') {
        e.preventDefault();
        $inner.attr('contenteditable', 'true').trigger('focus');
        const range = document.createRange();
        range.selectNodeContents($inner[0]);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

    } else if (e.key === 'Escape') {
        const idx   = +cellActiveConfig.attr('data-idx');
        const field = cellActiveConfig.attr('data-field');
        const orig  = configRows[idx]?.[field] ?? '';
        if ($inner.attr('data-masked') !== undefined) {
            $inner.text('••••••••').attr('data-real', orig).attr('data-masked', '1').attr('contenteditable', 'false');
            cellActiveConfig.find('i').removeClass('bi-eye').addClass('bi-eye-slash');
        } else {
            $inner.text(orig).attr('contenteditable', 'false');
        }
        tableMsg('Modification annulée.');

    } else if (e.key === 'Enter' && $inner.attr('contenteditable') === 'true') {
        e.preventDefault();
        validerCelluleConfig($inner, cellActiveConfig);
    }
});

$(document).on('blur', '#tab-table .cell-inner[contenteditable="true"]', function () {
    validerCelluleConfig($(this), $(this).closest('td'));
});

function validerCelluleConfig($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    if (configRows[idx]) {
        const val = $inner.attr('data-masked') === '1' ? $inner.attr('data-real') : $inner.text();
        configRows[idx][field] = val;
        if ($inner.attr('data-real') !== undefined) $inner.attr('data-real', val);
    }
    tableMsg('Modification locale. Cliquez sur ✓ pour enregistrer cette ligne.');
}

// ── Enregistrer / Supprimer une ligne ─────────────────────────────────────────
function sauvegarderLigneConfig(idx) {
    const r = configRows[idx];
    const cle = (r.cle ?? '').trim();

    if (!cle) { tableMsg('La clé est obligatoire.', false); return; }

    spinner(true);
    const action = r._nouveau ? 'table_creer' : 'table_modifier';
    const data = { action, cle, valeur: r.valeur ?? '', description: r.description ?? '' };
    if (!r._nouveau) data.cle_originale = r.cle_originale ?? r.cle;

    $.post('configuration.php', data, function (res) {
        spinner(false);
        tableMsg(res.msg, res.ok);
        if (res.ok) chargerTableConfig();
    }, 'json').fail(() => { spinner(false); tableMsg('Erreur réseau.', false); });
}

function supprimerLigneConfig(idx) {
    const r = configRows[idx];
    if (r._nouveau) { configRows.splice(idx, 1); renderTableConfig(); return; }
    if (!confirm(`Supprimer le paramètre « ${r.cle} » ?`)) return;

    spinner(true);
    $.post('configuration.php', { action: 'table_supprimer', cle: r.cle }, function (res) {
        spinner(false);
        tableMsg(res.msg, res.ok);
        if (res.ok) chargerTableConfig();
    }, 'json').fail(() => { spinner(false); tableMsg('Erreur réseau.', false); });
}

$('#btn-table-ajouter').on('click', function () {
    configRows.push({ cle: '', valeur: '', description: '', _nouveau: true });
    renderTableConfig();
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-config thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    if (sortFieldConfig === f) {
        sortDirConfig = sortDirConfig === 'asc' ? 'desc' : 'asc';
    } else {
        sortFieldConfig = f;
        sortDirConfig   = 'asc';
    }
    renderTableConfig();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input-config').on('input', function () {
    searchTermConfig = $(this).val().trim();
    renderTableConfig();
});

$('#tab-table-btn').on('shown.bs.tab', function () {
    if (!tableChargee) { tableChargee = true; chargerTableConfig(); }
});

// ── Enregistrement SMTP ────────────────────────────────────────────────────────
function sauvegarderSmtp(env) {
    const p = 'smtp_';
    const $btn    = $('#btn-smtp-' + env);
    const $msg    = $('#msg-smtp-' + env);

    const host     = $('#smtp-' + env + '-host').val().trim();
    const port     = $('#smtp-' + env + '-port').val().trim();
    const secure   = $('#smtp-' + env + '-secure').val();
    const user     = $('#smtp-' + env + '-user').val().trim();
    const password = $('#smtp-' + env + '-password').val();
    const from     = $('#smtp-' + env + '-from').val().trim();
    const fromName = $('#smtp-' + env + '-from-name').val().trim();

    if (host === '') {
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Le serveur SMTP est obligatoire.</span>');
        return;
    }
    if (!port || isNaN(port) || +port < 1 || +port > 65535) {
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Port invalide (1–65535).</span>');
        return;
    }
    if (from !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(from)) {
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Adresse expéditeur invalide.</span>');
        return;
    }

    spinner(true);
    $btn.prop('disabled', true);
    $msg.text('');

    const champs = [
        [p + 'host',      host],
        [p + 'port',      port],
        [p + 'secure',    secure],
        [p + 'auth',      user !== '' ? '1' : '0'],
        [p + 'user',      user],
        [p + 'password',  password],
        [p + 'from',      from],
        [p + 'from_name', fromName],
    ];

    let remaining = champs.length;
    let hasError  = false;

    champs.forEach(([cle, valeur]) => {
        $.post('configuration.php', { action: 'enregistrer', cle, valeur }, function (res) {
            if (!res.ok) hasError = true;
            remaining--;
            if (remaining === 0) {
                spinner(false);
                $btn.prop('disabled', false);
                if (hasError) {
                    $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Erreur lors de l\'enregistrement.</span>');
                } else {
                    $msg.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>Configuration SMTP enregistrée.</span>');
                }
            }
        }, 'json').fail(() => {
            hasError = true;
            remaining--;
            if (remaining === 0) {
                spinner(false);
                $btn.prop('disabled', false);
                $msg.html('<span class="text-danger">Erreur réseau.</span>');
            }
        });
    });
}

// ── Afficher / masquer les mots de passe SMTP ────────────────────────────────
$(document).on('click', '.btn-toggle-pwd', function () {
    const $input = $('#' + $(this).data('target'));
    const $icon  = $(this).find('i');
    const isHidden = $input.attr('type') === 'password';
    $input.attr('type', isHidden ? 'text' : 'password');
    $icon.toggleClass('bi-eye-slash', !isHidden).toggleClass('bi-eye', isHidden);
});

$('#btn-smtp-prod').on('click',  () => sauvegarderSmtp('prod'));

// ── Test SMTP production ──────────────────────────────────────────────────────
$('#btn-smtp-test-prod').on('click', function () {
    const dest = $('#smtp-test-dest').val().trim();
    const $msg = $('#msg-smtp-test-prod');

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(dest)) {
        $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Adresse destinataire invalide.</span>');
        return;
    }

    spinner(true);
    $(this).prop('disabled', true);
    $msg.text('');

    $.post('configuration.php', { action: 'smtp_test_prod', destinataire: dest }, function (res) {
        spinner(false);
        $('#btn-smtp-test-prod').prop('disabled', false);
        if (res.ok) {
            $msg.html('<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + res.msg + '</span>');
        } else {
            $msg.html('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + res.msg + '</span>');
        }
    }, 'json').fail(() => {
        spinner(false);
        $('#btn-smtp-test-prod').prop('disabled', false);
        $msg.html('<span class="text-danger">Erreur réseau.</span>');
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
