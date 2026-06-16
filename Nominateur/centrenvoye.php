<?php
/**
 * NIJAC – Centre d'envoi de messages (E024)
 *
 * Envoie les 4 types de messages aux JA actifs du département connecté.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-14
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../Classes/Obfuscator.php';

$_obf = new Obfuscator(OBFUSCATOR_SEED);

if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../index.php');
    exit;
}
$moi     = $_SESSION['utilisateur'];
$isAdmin = !empty($moi['is_admin']);

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo  = getPDO();
        $dept = str_pad((string)($moi['id_departement'] ?? '76'), 2, '0', STR_PAD_LEFT);

        // Saison courante
        $saisonRow = $pdo->query("SELECT Saison FROM rencontre ORDER BY Saison DESC LIMIT 1")->fetch();
        $saison    = $saisonRow['Saison'] ?? null;

        // ── Liste des journées (pour onglet Nomination) ─────────────────────
        if ($action === 'liste_journees') {
            if (!$saison) { echo json_encode(['ok' => true, 'data' => []]); exit; }
            $rows = $pdo->prepare("
                SELECT r.Journee,
                       MIN(r.Date) AS DateMin,
                       MAX(r.Date) AS DateMax,
                       COUNT(DISTINCT n.Id_JA) AS NbJA
                FROM rencontre r
                LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
                WHERE r.Saison = ?
                GROUP BY r.Journee
                ORDER BY r.Journee
            ");
            $rows->execute([$saison]);
            echo json_encode(['ok' => true, 'data' => $rows->fetchAll(), 'saison' => $saison]);
            exit;
        }

        // ── Liste des JA selon le type ──────────────────────────────────────
        if ($action === 'liste_ja') {
            $type    = $_POST['type']    ?? 'Disponibilites';
            $journee = (int)($_POST['journee'] ?? 0);

            switch ($type) {

                // ── Disponibilités : tous JA actifs du dept ─────────────────
                case 'Disponibilites':
                    $stmt = $pdo->prepare("
                        SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                               COALESCE(lp.CodePostal, j.Cp) AS CP
                        FROM ja j
                        LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                        WHERE j.Actif = 1
                          AND LEFT(COALESCE(lp.CodePostal, j.Cp, ''), 2) = ?
                        ORDER BY j.Nom, j.Prenom
                    ");
                    $stmt->execute([$dept]);
                    $rows = $stmt->fetchAll();
                    break;

                // ── Rappel dispo : JA sans dispo dans la saison courante ────
                case 'Rappel dispo':
                    if (!$saison) { $rows = []; break; }
                    $stmt = $pdo->prepare("
                        SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                               COALESCE(lp.CodePostal, j.Cp) AS CP
                        FROM ja j
                        LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                        WHERE j.Actif = 1
                          AND LEFT(COALESCE(lp.CodePostal, j.Cp, ''), 2) = ?
                          AND NOT EXISTS (
                              SELECT 1 FROM disponible d
                              JOIN rencontre r ON r.Date = d.DateCompetition
                              WHERE d.Id_JA = j.Id_JA AND r.Saison = ?
                          )
                        ORDER BY j.Nom, j.Prenom
                    ");
                    $stmt->execute([$dept, $saison]);
                    $rows = $stmt->fetchAll();
                    break;

                // ── Convocation : JA nominés pour une journée ───────────────
                case 'Convocation':
                    if (!$saison || !$journee) { $rows = []; break; }
                    $stmt = $pdo->prepare("
                        SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                               r.Date, r.Heure, r.Journee, r.Poule,
                               dv.Division,
                               ed.Nom AS NomDom, ee.Nom AS NomExt,
                               n.Id_Rencontre,
                               s.Nom        AS SalleNom,
                               s.Adresse    AS SalleAdresse,
                               lps.CodePostal AS SalleCP,
                               lps.Nom      AS SalleVille,
                               co.Nom       AS CorrNom,
                               co.Email     AS CorrEmail,
                               co.Telephone AS CorrTel
                        FROM nomination n
                        JOIN ja j              ON j.Id_JA         = n.Id_JA
                        JOIN rencontre r        ON r.Id_Rencontre  = n.Id_Rencontre
                        JOIN equipe ed          ON ed.Id_Equipe    = r.Id_EquipeDom
                        LEFT JOIN equipe ee     ON ee.Id_Equipe    = r.Id_EquipeExt
                        JOIN division dv        ON dv.Id_Division  = r.Id_Division
                        LEFT JOIN salle s           ON s.Id_Salle      = r.id_Salle
                        LEFT JOIN laposte lps       ON lps.Id_LaPoste  = s.Id_Laposte
                        LEFT JOIN correspondant co ON co.Id_Correspondant = (
                            SELECT c2.Id_Correspondant
                            FROM correspondant c2
                            WHERE c2.Id_Club = j.Id_Club
                            ORDER BY c2.Id_Correspondant
                            LIMIT 1
                        )
                        WHERE r.Saison = ? AND r.Journee = ? AND n.Valide = 1 AND j.Actif = 1
                        ORDER BY j.Nom, j.Prenom, r.Date
                    ");
                    $stmt->execute([$saison, $journee]);
                    $rows = $stmt->fetchAll();
                    break;

                // ── Liste nomination : JA ayant des nominations dans la phase ─
                case 'Liste nomination':
                    if (!$saison) { $rows = []; break; }
                    $stmt = $pdo->prepare("
                        SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                               COUNT(n.Id_JA) AS NbNominations
                        FROM ja j
                        LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                        JOIN nomination n ON n.Id_JA = j.Id_JA
                        JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
                        WHERE r.Saison = ?
                          AND j.Actif = 1
                          AND LEFT(COALESCE(lp.CodePostal, j.Cp, ''), 2) = ?
                        GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Email
                        ORDER BY j.Nom, j.Prenom
                    ");
                    $stmt->execute([$saison, $dept]);
                    $rows = $stmt->fetchAll();
                    break;

                default:
                    $rows = [];
            }

            foreach ($rows as &$row) {
                $row['token'] = $_obf->obfuscate((int)$row['Id_JA']);
            }
            unset($row);
            echo json_encode(['ok' => true, 'data' => $rows, 'dept' => $dept, 'saison' => $saison]);
            exit;
        }

        // ── Envoyer les emails ──────────────────────────────────────────────
        // ── Vérification initiale avant envoi groupé (rate limit + comptage sans-email) ──
        if ($action === 'envoyer') {
            $ids    = json_decode($_POST['ids'] ?? '[]', true);
            $sujet  = trim($_POST['sujet']   ?? '');
            $message= trim($_POST['message'] ?? '');

            if ($sujet === '' || $message === '') {
                echo json_encode(['ok' => false, 'msg' => 'Sujet et message obligatoires.']); exit;
            }
            if (empty($ids)) {
                echo json_encode(['ok' => false, 'msg' => 'Aucun destinataire sélectionné.']); exit;
            }

            // Rate limiting : vérifier globalement avant de commencer
            $errRl = checkRateLimit(count($ids));
            if ($errRl !== null) {
                echo json_encode(['ok' => false, 'msg' => $errRl]); exit;
            }

            // Compter les JA sans email (info pour le JS)
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM ja WHERE Id_JA IN ($placeholders) AND (Email IS NULL OR Email = '')");
            $stmt2->execute(array_map('intval', $ids));
            $sans_email = (int)$stmt2->fetchColumn();

            echo json_encode(['ok' => true, 'sans_email' => $sans_email, 'mode_dev' => isModeDeveloppement()]);
            exit;
        }

        // ── Envoi d'un seul email (appelé séquentiellement par le JS) ──────────
        if ($action === 'envoyer_un') {
            $type    = trim($_POST['type']    ?? 'Disponibilites');
            $sujet   = trim($_POST['sujet']   ?? '');
            $message = trim($_POST['message'] ?? '');
            $idJa    = (int)($_POST['id_ja']  ?? 0);
            $saison  = trim($_POST['saison']  ?? ($saison ?? ''));

            if (!$idJa || $sujet === '' || $message === '') {
                echo json_encode(['ok' => false, 'msg' => 'Paramètres manquants.']); exit;
            }

            // Rate limiting unitaire
            $errRl = checkRateLimit(1);
            if ($errRl !== null) {
                echo json_encode(['ok' => false, 'msg' => $errRl]); exit;
            }

            $ja = $pdo->prepare("
                SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                       n.Id_Rencontre,
                       r.Date, r.Heure, r.Journee, r.Poule,
                       dv.Division,
                       ed.Nom AS NomDom, ee.Nom AS NomExt,
                       s.Nom          AS SalleNom,
                       s.Adresse      AS SalleAdresse,
                       lps.CodePostal AS SalleCP,
                       lps.Nom        AS SalleVille,
                       co.Nom         AS CorrNom,
                       co.Email       AS CorrEmail,
                       co.Telephone   AS CorrTel
                FROM ja j
                LEFT JOIN nomination n     ON n.Id_JA = j.Id_JA AND n.Valide = 1
                LEFT JOIN rencontre r      ON r.Id_Rencontre = n.Id_Rencontre
                LEFT JOIN equipe ed        ON ed.Id_Equipe   = r.Id_EquipeDom
                LEFT JOIN equipe ee        ON ee.Id_Equipe   = r.Id_EquipeExt
                LEFT JOIN division dv      ON dv.Id_Division = r.Id_Division
                LEFT JOIN salle s          ON s.Id_Salle     = r.id_Salle
                LEFT JOIN laposte lps      ON lps.Id_LaPoste = s.Id_Laposte
                LEFT JOIN correspondant co ON co.Id_Correspondant = (
                    SELECT c2.Id_Correspondant FROM correspondant c2
                    WHERE c2.Id_Club = j.Id_Club ORDER BY c2.Id_Correspondant LIMIT 1
                )
                WHERE j.Id_JA = ? AND j.Actif = 1
                GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Email,
                         n.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                         dv.Division, ed.Nom, ee.Nom,
                         s.Nom, s.Adresse, lps.CodePostal, lps.Nom,
                         co.Nom, co.Email, co.Telephone
            ");
            $ja->execute([$idJa]);
            $ja = $ja->fetch();

            if (!$ja || empty($ja['Email'])) {
                echo json_encode(['ok' => false, 'skip' => true, 'msg' => 'Pas d\'email.']); exit;
            }

            // Générer la liste des nominations pour "Liste nomination"
            $listeNoms = '';
            if ($type === 'Liste nomination' && $saison) {
                $stmtNoms = $pdo->prepare("
                    SELECT r.Date, r.Heure, dv.Division, ed.Nom AS Dom, ee.Nom AS Ext
                    FROM nomination n
                    JOIN rencontre r    ON r.Id_Rencontre = n.Id_Rencontre
                    JOIN equipe ed      ON ed.Id_Equipe   = r.Id_EquipeDom
                    LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                    JOIN division dv    ON dv.Id_Division = r.Id_Division
                    WHERE n.Id_JA = ? AND r.Saison = ? ORDER BY r.Date, r.Heure
                ");
                $stmtNoms->execute([$idJa, $saison]);
                $noms = $stmtNoms->fetchAll();
                if ($noms) {
                    $listeNoms = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">'
                        . '<tr style="background:#1a3a6b;color:#fff;"><th>Date</th><th>Heure</th><th>Division</th><th>Domicile</th><th>Extérieur</th></tr>';
                    foreach ($noms as $idx => $n) {
                        $bg = ($idx % 2 === 0) ? '#f0f4fa' : '#ffffff';
                        $listeNoms .= sprintf(
                            '<tr style="background:%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                            $bg,
                            htmlspecialchars($n['Date'] ? date('d/m/Y', strtotime($n['Date'])) : ''),
                            htmlspecialchars($n['Heure'] ?? ''),
                            htmlspecialchars($n['Division']),
                            htmlspecialchars($n['Dom']),
                            htmlspecialchars($n['Ext'] ?? '')
                        );
                    }
                    $listeNoms .= '</table>';
                } else {
                    $listeNoms = '<em>(aucune nomination)</em>';
                }
            }

            $token = $_obf->obfuscate((int)$ja['Id_JA']);
            $corps = str_replace(
                ['{NOM}','{PRENOM}','{NOM_COMPLET}','{ID_JA}',
                 '{UTI_NOM}','{UTI_PRENOM}',
                 '{DATE}','{HEURE}','{JOURNEE}','{POULE}','{DIVISION}','{DOM}','{EXT}',
                 '{SALLE_NOM}','{SALLE_ADRESSE}','{SALLE_CP}','{SALLE_VILLE}',
                 '{CORR_NOM}','{CORR_EMAIL}','{CORR_TEL}'],
                [$ja['Nom'],$ja['Prenom'],$ja['Prenom'].' '.$ja['Nom'],$token,
                 $moi['nom']??'',$moi['prenom']??'',
                 $ja['Date'] ? date('d/m/Y', strtotime($ja['Date'])) : '',$ja['Heure']??'',
                 $ja['Journee']??'',$ja['Poule']??'',$ja['Division']??'',
                 $ja['NomDom']??'',$ja['NomExt']??'',
                 $ja['SalleNom']??'',$ja['SalleAdresse']??'',$ja['SalleCP']??'',$ja['SalleVille']??'',
                 $ja['CorrNom']??'',$ja['CorrEmail']??'',$ja['CorrTel']??''],
                $message
            );
            $corps = nl2br(htmlspecialchars($corps, ENT_NOQUOTES, 'UTF-8'));
            $corps = str_replace('{LISTE_NOMINATIONS}', $listeNoms, $corps);

            $modeDev = isModeDeveloppement();
            $dest    = getEmailDestinataire($ja['Email']);
            try {
                $mail = getNijacMailer();
                $mail->isHTML(true);
                $mail->addAddress($dest, $ja['Prenom'] . ' ' . $ja['Nom']);
                if (!empty($moi['email_lntt'])) {
                    $mail->addReplyTo($moi['email_lntt'], $moi['nom'] . ' ' . $moi['prenom']);
                }
                $mail->Subject = ($modeDev && $dest !== $ja['Email'])
                    ? "[DEV → {$ja['Email']}] $sujet"
                    : $sujet;
                $mail->Body    = $corps;
                $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $corps));
                $mail->send();
                enregistrerEnvois(1);
                echo json_encode(['ok' => true, 'nom' => $ja['Prenom'] . ' ' . $ja['Nom']]);
            } catch (\Exception $e) {
                error_log('[NIJAC] centrenvoye mail error : ' . $e->getMessage());
                echo json_encode(['ok' => false, 'nom' => $ja['Prenom'] . ' ' . $ja['Nom'], 'msg' => $e->getMessage()]);
            }
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] centrenvoye.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Chargement des modèles de messages ───────────────────────────────────────
$pdo      = getPDO();
$modeles  = [];
$rows = $pdo->query("SELECT Type, Sujet, Message FROM messagerie ORDER BY Id_Messagerie")->fetchAll();
foreach ($rows as $r) {
    if (!isset($modeles[$r['Type']])) {
        $modeles[$r['Type']] = ['sujet' => $r['Sujet'], 'message' => $r['Message']];
    }
}

$dept        = str_pad((string)($moi['id_departement'] ?? '76'), 2, '0', STR_PAD_LEFT);
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);

$types = ['Disponibilites', 'Rappel dispo', 'Convocation', 'Liste nomination'];
$modeleJson = json_encode($modeles, JSON_HEX_TAG | JSON_HEX_APOS);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Centre d'envoi (E024)</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../asset/css/nijac.css">

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

        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        #split-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* ── Panneau message (gauche) ── */
        #panel-message {
            width: 55%;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
            background: #fff;
            overflow: hidden;
        }

        /* ── Onglets ── */
        #type-tabs {
            display: flex;
            border-bottom: 2px solid #c8d4e8;
            flex-shrink: 0;
            background: #f0f4fa;
        }

        .type-tab {
            padding: .45rem .9rem;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            border-bottom: 3px solid transparent;
            background: none;
            color: #6b7280;
            white-space: nowrap;
            transition: color .15s, border-color .15s;
        }

        .type-tab:hover   { color: var(--nijac-blue); }
        .type-tab.active  { color: var(--nijac-blue); border-bottom-color: var(--nijac-blue); background: #fff; }

        /* ── Corps du panneau message ── */
        #panel-message-body {
            flex: 1;
            overflow-y: auto;
            padding: .75rem 1rem;
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        #txt-message {
            resize: vertical;
            min-height: 220px;
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: .88rem;
            flex: 1;
        }

        /* ── Cartouche marqueurs ── */
        #cartouche-marqueurs {
            font-size: .76rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: .4rem .6rem;
            flex-shrink: 0;
        }

        #cartouche-marqueurs .cart-titre {
            font-size: .7rem;
            font-weight: 700;
            color: #6b7280;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: .25rem;
        }

        #cartouche-marqueurs code {
            display: inline-block;
            background: #e8eef7;
            border: 1px solid #b8cce4;
            border-radius: 3px;
            padding: .02rem .28rem;
            font-size: .73rem;
            cursor: pointer;
            user-select: none;
            transition: background .12s, transform .1s;
            margin: .1rem .15rem .1rem 0;
        }

        #cartouche-marqueurs code:hover  { background: #cfe0f8; border-color: #7aaddf; transform: scale(1.06); }
        #cartouche-marqueurs code:active { transform: scale(.95); }

        /* ── Barre d'envoi ── */
        #envoi-bar {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .4rem .75rem;
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        #result-envoi { font-size: .82rem; flex: 1; }

        /* ── Panneau JA (droite) ── */
        #panel-ja {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #ja-header {
            background: steelblue;
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            padding: .4rem .75rem;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: .6rem;
        }

        .badge-dept {
            background: #fff;
            color: steelblue;
            font-size: .75rem;
            padding: .1rem .4rem;
            border-radius: 3px;
            font-weight: 700;
        }

        /* ── Sélecteur journée (Nomination) ── */
        #journee-bar {
            background: #fff8e1;
            border-bottom: 1px solid #ffe082;
            padding: .35rem .6rem;
            display: none;
            align-items: center;
            gap: .5rem;
            flex-shrink: 0;
            font-size: .83rem;
        }

        #journee-bar.visible { display: flex; }

        /* ── Barre de recherche ── */
        #ja-search-bar {
            background: #f0f4fa;
            border-bottom: 1px solid #c8d4e8;
            padding: .3rem .6rem;
            display: flex;
            align-items: center;
            gap: .4rem;
            flex-shrink: 0;
        }

        #txt-recherche-ja {
            flex: 1;
            font-size: .82rem;
            padding: .2rem .4rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
        }

        #ja-list-wrapper { flex: 1; overflow-y: auto; }

        #tbl-ja {
            width: 100%;
            font-size: .82rem;
            border-collapse: collapse;
        }

        #tbl-ja thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .3rem .4rem;
            position: sticky;
            top: 0;
            z-index: 1;
            white-space: nowrap;
        }

        #tbl-ja tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-ja tbody tr:hover { background: #f4f7fc; }
        #tbl-ja tbody td { padding: .22rem .4rem; }

        .col-sort { cursor: pointer; user-select: none; }
        .no-email { color: #bbb; font-style: italic; font-size: .75rem; }
        tr.masque { display: none; }

        /* ── Toast ── */
        #toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 9999; }
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/toolbar.php'; ?>

<div id="page-header">
    <i class="bi bi-send-fill me-2"></i>Centre d'envoi
    <small class="opacity-75 ms-2">(E024)</small>
    <a href="menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<div id="split-container">

    <!-- ── Panneau message ── -->
    <div id="panel-message">

        <!-- Onglets -->
        <div id="type-tabs">
            <button class="type-tab active" data-type="Disponibilites">
                <i class="bi bi-calendar-check me-1"></i>Disponibilités
            </button>
            <button class="type-tab" data-type="Rappel dispo">
                <i class="bi bi-bell me-1"></i>Rappel dispo
            </button>
            <button class="type-tab" data-type="Convocation">
                <i class="bi bi-person-check me-1"></i>Convocation
            </button>
            <button class="type-tab" data-type="Liste nomination">
                <i class="bi bi-list-check me-1"></i>Liste nomination
            </button>
        </div>

        <!-- Corps -->
        <div id="panel-message-body">

            <div class="mb-2">
                <label class="form-label" for="txt-sujet">Sujet :</label>
                <input type="text" id="txt-sujet" class="form-control form-control-sm" maxlength="150">
            </div>

            <div class="mb-1 d-flex flex-column flex-grow-1">
                <label class="form-label" for="txt-message">Message :</label>
                <textarea id="txt-message" class="form-control form-control-sm flex-grow-1"></textarea>
            </div>

            <!-- Cartouche marqueurs (mis à jour selon l'onglet actif) -->
            <div id="cartouche-marqueurs" class="mb-1">
                <div class="cart-titre"><i class="bi bi-braces me-1"></i>Marqueurs disponibles — clic pour insérer</div>
                <div id="cart-communs">
                    <span class="badge bg-secondary me-1 fw-normal" style="font-size:.68rem;">Tous</span>
                    <code data-cible="message" data-marqueur="{PRENOM}">{PRENOM}</code>
                    <code data-cible="message" data-marqueur="{NOM}">{NOM}</code>
                    <code data-cible="message" data-marqueur="{NOM_COMPLET}">{NOM_COMPLET}</code>
                    <code data-cible="message" data-marqueur="{ID_JA}">{ID_JA}</code>
                    <code data-cible="message" data-marqueur="{UTI_NOM}">{UTI_NOM}</code>
                    <code data-cible="message" data-marqueur="{UTI_PRENOM}">{UTI_PRENOM}</code>
                </div>
                <div id="cart-convocation" style="display:none;margin-top:.2rem;">
                    <span class="badge me-1 fw-normal" style="font-size:.68rem;background:#1a7f4b;">Convocation</span>
                    <code data-cible="message" data-marqueur="{DATE}">{DATE}</code>
                    <code data-cible="message" data-marqueur="{HEURE}">{HEURE}</code>
                    <code data-cible="message" data-marqueur="{JOURNEE}">{JOURNEE}</code>
                    <code data-cible="message" data-marqueur="{POULE}">{POULE}</code>
                    <code data-cible="message" data-marqueur="{DIVISION}">{DIVISION}</code>
                    <code data-cible="message" data-marqueur="{DOM}">{DOM}</code>
                    <code data-cible="message" data-marqueur="{EXT}">{EXT}</code>
                    <code data-cible="message" data-marqueur="{SALLE_NOM}">{SALLE_NOM}</code>
                    <code data-cible="message" data-marqueur="{SALLE_ADRESSE}">{SALLE_ADRESSE}</code>
                    <code data-cible="message" data-marqueur="{SALLE_CP}">{SALLE_CP}</code>
                    <code data-cible="message" data-marqueur="{SALLE_VILLE}">{SALLE_VILLE}</code>
                    <code data-cible="message" data-marqueur="{CORR_NOM}">{CORR_NOM}</code>
                    <code data-cible="message" data-marqueur="{CORR_EMAIL}">{CORR_EMAIL}</code>
                    <code data-cible="message" data-marqueur="{CORR_TEL}">{CORR_TEL}</code>
                </div>
                <div id="cart-liste-nom" style="display:none;margin-top:.2rem;">
                    <span class="badge me-1 fw-normal" style="font-size:.68rem;background:#6f42c1;">Liste nomination</span>
                    <code data-cible="message" data-marqueur="{LISTE_NOMINATIONS}">{LISTE_NOMINATIONS}</code>
                </div>
            </div>

        </div>

        <!-- Barre d'envoi -->
        <div id="envoi-bar">
            <button class="btn btn-sm btn-success px-3" id="btn-envoyer">
                <i class="bi bi-send me-1"></i>Envoyer
            </button>
            <div id="result-envoi"></div>
        </div>

        <!-- Barre de progression (masquée par défaut) -->
        <div id="envoi-progress" style="display:none;margin-top:.6rem;">
            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:.78rem;">
                <span id="progress-label" class="text-secondary fw-semibold"></span>
                <span id="progress-counts" class="text-muted"></span>
            </div>
            <div class="progress" style="height:14px;border-radius:8px;">
                <div id="progress-bar"
                     class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                     role="progressbar" style="width:0%;transition:width .3s ease;"></div>
            </div>
            <div id="progress-erreurs" class="mt-1 text-danger" style="font-size:.72rem;"></div>
        </div>

    </div>

    <!-- ── Panneau JA ── -->
    <div id="panel-ja">

        <div id="ja-header">
            <span id="ja-header-titre">JA actifs</span>
            <span class="badge-dept">Dép. <?= htmlspecialchars($dept) ?></span>
            <span id="nb-ja" class="ms-auto opacity-75" style="font-weight:400;font-size:.78rem;"></span>
        </div>

        <!-- Sélecteur journée (visible seulement pour Nomination) -->
        <div id="journee-bar">
            <i class="bi bi-calendar3 text-warning"></i>
            <label class="fw-bold mb-0" style="font-size:.82rem;">Journée :</label>
            <select id="cbo-journee" class="form-select form-select-sm" style="max-width:300px;">
                <option value="">— Sélectionner —</option>
            </select>
            <span id="nb-ja-journee" class="text-muted" style="font-size:.78rem;"></span>
        </div>

        <!-- Recherche -->
        <div id="ja-search-bar">
            <i class="bi bi-search text-muted" style="font-size:.82rem;"></i>
            <input type="text" id="txt-recherche-ja" placeholder="Rechercher…">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" id="btn-effacer-recherche" title="Effacer">
                <i class="bi bi-x"></i>
            </button>
            <span id="nb-visibles" class="text-muted" style="font-size:.76rem;white-space:nowrap;"></span>
        </div>

        <div id="ja-list-wrapper">
            <table id="tbl-ja">
                <thead id="tbl-ja-thead">
                    <tr>
                        <th style="width:28px">
                            <input type="checkbox" id="chk-header" checked title="Tout cocher/décocher">
                        </th>
                        <th class="col-sort" data-col="1">Nom <span class="sort-icon">↕</span></th>
                        <th class="col-sort" data-col="2">Prénom <span class="sort-icon">↕</span></th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody id="tbody-ja">
                    <tr><td colspan="4" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>

    </div>
</div>

<div id="toast-container"></div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
    <script src="../asset/js/nijac-csrf.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const MODELES = <?= $modeleJson ?>;


const TITRES_JA = {
    'Disponibilites':  'JA actifs du département',
    'Rappel dispo':    'JA sans disponibilités saisies',
    'Convocation':     'JA nominés — journée sélectionnée',
    'Liste nomination':'JA avec nominations dans la phase',
};

let typeActif  = 'Disponibilites';
let saisonCourante = null;
let sortCol = 1, sortAsc = true;

// ── Utilitaires ───────────────────────────────────────────────────────────────
function toast(msg, ok = true) {
    const id  = 'toast-' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-container').append(
        `<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show" role="alert">
           <div class="d-flex">
             <div class="toast-body">${msg}</div>
             <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
           </div>
         </div>`
    );
    setTimeout(() => { $(`#${id}`).remove(); }, 4500);
}

// ── Onglets ───────────────────────────────────────────────────────────────────
$('.type-tab').on('click', function () {
    typeActif = $(this).data('type');
    $('.type-tab').removeClass('active');
    $(this).addClass('active');
    chargerModele(typeActif);
    majColonnesJA(typeActif);
    const isConv = typeActif === 'Convocation';
    $('#journee-bar').toggleClass('visible', isConv);
    if (isConv) {
        chargerJournees();
    } else {
        chargerJA();
    }
});

function chargerModele(type) {
    const m = MODELES[type] || { sujet: '', message: '' };
    $('#txt-sujet').val(m.sujet || '');
    $('#txt-message').val(m.message || '');
    // Afficher/masquer les sections du cartouche selon le type actif
    $('#cart-convocation').toggle(type === 'Convocation');
    $('#cart-liste-nom').toggle(type === 'Liste nomination');
    $('#ja-header-titre').text(TITRES_JA[type] || 'JA');
    $('#result-envoi').html('');
}

function majColonnesJA(type) {
    const $thead = $('#tbl-ja-thead tr');
    $thead.find('th:gt(3)').remove(); // supprimer colonnes extra
    if (type === 'Convocation') {
        $thead.append(
            '<th>Date</th><th>Heure</th><th>Division</th><th>Dom vs Ext</th>'
        );
    } else if (type === 'Liste nomination') {
        $thead.append('<th class="text-center">Nominations</th>');
    }
}

// ── Journées (Convocation) ───────────────────────────────────────────────────
function chargerJournees() {
    $.post('centrenvoye.php', { action: 'liste_journees' }, function (res) {
        const $cbo = $('#cbo-journee').empty().append('<option value="">— Sélectionner —</option>');
        if (res.ok) {
            saisonCourante = res.saison;
            res.data.forEach(j => {
                $cbo.append($('<option>').val(j.Journee).text(
                    `J${j.Journee}  (${j.DateMin} → ${j.DateMax})  — ${j.NbJA} JA`
                ));
            });
        }
        $('#tbody-ja').html('<tr><td colspan="8" class="text-center text-muted py-2">Sélectionner une journée</td></tr>');
    }, 'json');
}

$('#cbo-journee').on('change', function () {
    if ($(this).val()) chargerJA();
    else $('#tbody-ja').html('<tr><td colspan="8" class="text-center text-muted py-2">Sélectionner une journée</td></tr>');
});

// ── Chargement JA ─────────────────────────────────────────────────────────────
function chargerJA() {
    const data = { action: 'liste_ja', type: typeActif };
    if (typeActif === 'Convocation') {
        data.journee = $('#cbo-journee').val();
        if (!data.journee) return;
    }

    const colSpan = typeActif === 'Convocation' ? 8 : (typeActif === 'Liste nomination' ? 5 : 4);
    $('#tbody-ja').html(`<tr><td colspan="${colSpan}" class="text-center text-muted py-2"><i class="bi bi-hourglass-split me-1"></i>Chargement…</td></tr>`);

    $.post('centrenvoye.php', data, function (res) {
        saisonCourante = res.saison;
        const $body = $('#tbody-ja').empty();
        if (!res.ok || !res.data.length) {
            $body.append(`<tr><td colspan="${colSpan}" class="text-center text-muted py-3">Aucun JA.</td></tr>`);
            $('#nb-ja').text('');
            return;
        }
        let nbEmail = 0;
        res.data.forEach(ja => {
            const aEmail = ja.Email && ja.Email.trim() !== '';
            if (aEmail) nbEmail++;
            const emailCell = aEmail ? `<span>${ja.Email}</span>`
                : `<span class="no-email"><i class="bi bi-exclamation-triangle me-1"></i>Pas d'email</span>`;

            const $tr = $('<tr>').attr('data-id', ja.Id_JA).append(
                $('<td>').append($('<input type="checkbox">').prop('checked', aEmail).prop('disabled', !aEmail).addClass('chk-ja')),
                $('<td>').text(ja.Nom),
                $('<td>').text(ja.Prenom),
                $('<td>').html(emailCell)
            );

            if (typeActif === 'Convocation') {
                $tr.append(
                    $('<td>').text(ja.Date ?? ''),
                    $('<td>').text(ja.Heure ?? ''),
                    $('<td>').text(ja.Division ?? ''),
                    $('<td>').text((ja.NomDom ?? '') + (ja.NomExt ? ' vs ' + ja.NomExt : ''))
                );
            } else if (typeActif === 'Liste nomination') {
                $tr.append($('<td class="text-center">').text(ja.NbNominations ?? ''));
            }
            $body.append($tr);
        });
        $('#nb-ja').text(`${res.data.length} JA — ${nbEmail} avec email`);
    }, 'json');
}

// ── Clic sur un marqueur : insérer à la position du curseur ──────────────────
let dernierChamp = 'message'; // 'sujet' ou 'message'
$('#txt-sujet').on('focus', () => { dernierChamp = 'sujet'; });
$('#txt-message').on('focus', () => { dernierChamp = 'message'; });

$(document).on('click', '[data-marqueur]', function () {
    const ta    = document.getElementById(dernierChamp === 'sujet' ? 'txt-sujet' : 'txt-message');
    const texte = $(this).data('marqueur');
    const debut = ta.selectionStart ?? ta.value.length;
    const fin   = ta.selectionEnd   ?? debut;
    ta.setRangeText(texte, debut, fin, 'end');
    ta.focus();
});

// ── Tri ───────────────────────────────────────────────────────────────────────
function trierJA(col) {
    sortAsc = (sortCol === col) ? !sortAsc : true;
    sortCol = col;
    $('.sort-icon').text('↕');
    $(`.col-sort[data-col="${col}"] .sort-icon`).text(sortAsc ? '↑' : '↓');
    const rows = $('#tbody-ja tr').toArray();
    rows.sort((a, b) => {
        const va = $(a).find('td').eq(col).text().trim().toLowerCase();
        const vb = $(b).find('td').eq(col).text().trim().toLowerCase();
        return sortAsc ? va.localeCompare(vb, 'fr') : vb.localeCompare(va, 'fr');
    });
    rows.forEach(r => $('#tbody-ja').append(r));
    filtrerJA();
}

$('#tbl-ja').on('click', '.col-sort', function () { trierJA(parseInt($(this).data('col'))); });

// ── Recherche ─────────────────────────────────────────────────────────────────
function filtrerJA() {
    const terme = $('#txt-recherche-ja').val().toLowerCase().trim();
    let visibles = 0;
    $('#tbody-ja tr').each(function () {
        const ok = terme === '' || $(this).text().toLowerCase().includes(terme);
        $(this).toggleClass('masque', !ok);
        if (ok) visibles++;
    });
    $('#nb-visibles').text(terme ? `${visibles} / ${$('#tbody-ja tr').length}` : '');
}

$('#txt-recherche-ja').on('input', filtrerJA);
$('#btn-effacer-recherche').on('click', () => $('#txt-recherche-ja').val('').trigger('input').trigger('focus'));

// ── Cocher/décocher ───────────────────────────────────────────────────────────
$('#chk-header').on('change', function () { $('.chk-ja:not(:disabled)').prop('checked', this.checked); });
$('#tbody-ja').on('change', '.chk-ja', function () {
    const total  = $('.chk-ja:not(:disabled)').length;
    const coches = $('.chk-ja:not(:disabled):checked').length;
    $('#chk-header').prop('checked', coches === total);
});

// ── Envoi ─────────────────────────────────────────────────────────────────────
$('#btn-envoyer').on('click', function () {
    const sujet   = $('#txt-sujet').val().trim();
    const message = $('#txt-message').val().trim();
    const ids = [], noms = [];

    $('#tbody-ja tr:not(.masque)').each(function () {
        if ($(this).find('.chk-ja').is(':checked')) {
            ids.push($(this).data('id'));
            const tds = $(this).find('td');
            noms.push(`${tds.eq(1).text().trim()} ${tds.eq(2).text().trim()}`);
        }
    });

    if (!sujet || !message) { toast('Le sujet et le message sont obligatoires.', false); return; }
    if (!ids.length)         { toast('Aucun destinataire sélectionné.', false); return; }
    if (!confirm(`Envoyer le message à ${ids.length} JA ?\n\n${noms.join('\n')}`)) return;

    const total   = ids.length;
    let envoyes   = 0, echecs = 0, sansEmail = 0;
    const erreursDetail = [];

    function demarrerProgress() {
        $('#btn-envoyer').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Envoi en cours…');
        $('#result-envoi').html('');
        $('#envoi-progress').show();
        $('#progress-bar').css('width', '0%').removeClass('bg-danger').addClass('bg-success progress-bar-animated');
        $('#progress-erreurs').html('');
        majProgress(0);
    }

    function majProgress(fait) {
        const pct   = total > 0 ? Math.round(fait / total * 100) : 0;
        $('#progress-bar').css('width', pct + '%').attr('aria-valuenow', pct);
        $('#progress-label').text(`Envoi ${fait} / ${total}…`);
        $('#progress-counts').html(
            `<span class="text-success">${envoyes} ✓</span>` +
            (echecs   > 0 ? ` &nbsp;<span class="text-danger">${echecs} ✗</span>` : '') +
            (sansEmail > 0 ? ` &nbsp;<span class="text-muted">${sansEmail} sans email</span>` : '')
        );
    }

    function terminerProgress() {
        const ok = echecs === 0;
        $('#progress-bar')
            .css('width', '100%')
            .removeClass('progress-bar-animated' + (ok ? '' : ' bg-success'))
            .addClass(ok ? '' : 'bg-danger');
        $('#progress-label').html(ok
            ? `<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>Envoi terminé</span>`
            : `<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>Envoi terminé avec ${echecs} erreur(s)</span>`
        );
        $('#progress-counts').html(
            `<span class="text-success fw-semibold">${envoyes} envoyé(s)</span>` +
            (echecs   > 0 ? ` &nbsp;<span class="text-danger">${echecs} échec(s)</span>` : '') +
            (sansEmail > 0 ? ` &nbsp;<span class="text-muted">${sansEmail} sans email</span>` : '')
        );
        if (erreursDetail.length) {
            $('#progress-erreurs').html(erreursDetail.map(e => `<i class="bi bi-x-circle me-1"></i>${e.nom} — ${e.msg}`).join('<br>'));
        }
        $('#btn-envoyer').prop('disabled', false).html('<i class="bi bi-send me-1"></i>Envoyer');
        toast(ok ? `${envoyes} email(s) envoyé(s).` : `${envoyes} envoyé(s), ${echecs} échec(s).`, ok);
    }

    // Vérification initiale (rate limit global + comptage sans-email)
    $.post('centrenvoye.php', {
        action: 'envoyer',
        sujet, message,
        ids: JSON.stringify(ids),
    }, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        sansEmail = res.sans_email || 0;

        demarrerProgress();

        // Envoi séquentiel un par un
        let idx = 0;
        function envoyerSuivant() {
            if (idx >= ids.length) { terminerProgress(); return; }
            const id = ids[idx++];
            $.post('centrenvoye.php', {
                action:  'envoyer_un',
                type:    typeActif,
                sujet, message,
                id_ja:   id,
                saison:  saisonCourante ?? '',
            }, function (r) {
                if (r.ok) {
                    envoyes++;
                } else if (r.skip) {
                    // pas d'email, déjà compté dans sansEmail
                } else {
                    echecs++;
                    erreursDetail.push({ nom: r.nom || `JA #${id}`, msg: r.msg || 'Erreur inconnue' });
                }
                majProgress(idx);
                envoyerSuivant();
            }, 'json').fail(function () {
                echecs++;
                erreursDetail.push({ nom: `JA #${id}`, msg: 'Erreur réseau' });
                majProgress(idx);
                envoyerSuivant();
            });
        }
        envoyerSuivant();

    }, 'json').fail(function () {
        toast('Erreur réseau lors de la vérification.', false);
    });
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    chargerModele(typeActif);
    chargerJA();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
