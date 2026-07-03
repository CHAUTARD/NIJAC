<?php
/**
 * NIJAC – Désidératas clubs (E027)
 *
 * Sélection des clubs ayant des équipes de la Pré-Nationale à la R4M,
 * puis envoi du questionnaire de désidératas de saison (modèle système
 * n°6) contenant un lien vers le formulaire public E023 (desiderata_club.php).
 * Le souhait de désignation JA (CRA/Club) saisi par le club pour ses
 * équipes R3M/R4M pilote automatiquement JAdemande et ArbitrageObligatoire.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-17
 * Modifié le : 2026-07-02 — remplacement du toggle JA demandé par un envoi
 * groupé du questionnaire de désidératas aux clubs (remplace l'Excel).
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';

// ── Sécurité : accès admin et nominateurs ────────────────────────────────────
$authRedirect = '../index.php';
require __DIR__ . '/../includes/auth_required.php';
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();
        try { initTableConfiguration($pdo); } catch (\Throwable $ignored) {}

        $saison = getConfig('saison', '');

        // ── Liste des clubs de la région ayant des équipes PN à R4M ───────────
        if ($action === 'liste') {
            $dept = (int)($_GET['dept'] ?? 0);

            // Ne retenir que les clubs des départements actifs de la région (pas les clubs Hors région)
            $deptsRegion = array_map('intval', array_column(getDeptActifs(), 'code'));
            if (!$deptsRegion) {
                echo json_encode(['ok' => true, 'data' => [], 'saison' => $saison]);
                exit;
            }

            $sql = "SELECT c.Id_Club, c.Nom AS NomClub, c.CorNom, c.CorEmail,
                           c.DesiderataSaison, c.DesiderataDate, c.DesiderataEmailDate, c.DesiderataNote,
                           SUBSTRING(c.Id_Club, 3, 2) AS Departement,
                           COUNT(e.Id_Equipe) AS NbEquipes,
                           GROUP_CONCAT(DISTINCT d.Division ORDER BY d.Ord SEPARATOR ',') AS Divisions,
                           GROUP_CONCAT(CONCAT(d.Division, '§', e.Nom) ORDER BY d.Ord SEPARATOR '||') AS DivisionsEquipes
                    FROM club c
                    JOIN equipe e   ON e.Id_Club = c.Id_Club
                    JOIN division d ON d.Id_Division = e.Id_Division
                    WHERE d.Ord BETWEEN 70 AND 150";
            $params = [];
            if ($dept > 0) {
                $sql .= " AND SUBSTRING(c.Id_Club, 3, 2) = ?";
                $params[] = $dept;
            } else {
                $ph = implode(',', array_fill(0, count($deptsRegion), '?'));
                $sql .= " AND SUBSTRING(c.Id_Club, 3, 2) IN ($ph)";
                $params = array_merge($params, $deptsRegion);
            }
            $sql .= " GROUP BY c.Id_Club, c.Nom, c.CorNom, c.CorEmail,
                               c.DesiderataSaison, c.DesiderataDate, c.DesiderataEmailDate, c.DesiderataNote,
                               SUBSTRING(c.Id_Club, 3, 2)
                      ORDER BY c.Nom";

            $rows = $pdo->prepare($sql);
            $rows->execute($params);
            echo json_encode(['ok' => true, 'data' => $rows->fetchAll(), 'saison' => $saison]);
            exit;
        }

        // ── Lire les départements actifs depuis la configuration ───────────
        if ($action === 'departements') {
            $stmt = $pdo->query(
                "SELECT valeur FROM configuration WHERE cle = 'departements_actifs'"
            );
            $row   = $stmt->fetch();
            $codes = $row
                ? array_map('intval', array_filter(array_map('trim', explode(',', $row['valeur']))))
                : [];
            if (!$codes) { echo json_encode(['ok' => true, 'data' => []]); exit; }
            sort($codes);
            $ph   = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $pdo->prepare(
                "SELECT CAST(code AS UNSIGNED) AS code, nom
                 FROM departement
                 WHERE CAST(code AS UNSIGNED) IN ($ph)
                 ORDER BY CAST(code AS UNSIGNED)"
            );
            $stmt->execute($codes);
            echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }

        // ── Détail des désidératas d'un club (récapitulatif équipes) ──────────
        if ($action === 'detail') {
            $idClub = trim($_GET['club'] ?? '');
            if ($idClub === '') { echo json_encode(['ok' => false, 'msg' => 'Club manquant.']); exit; }

            $stmtC = $pdo->prepare('SELECT Id_Club, Nom, DesiderataNote FROM club WHERE Id_Club = ?');
            $stmtC->execute([$idClub]);
            $club = $stmtC->fetch();
            if (!$club) { echo json_encode(['ok' => false, 'msg' => 'Club introuvable.']); exit; }

            $stmtE = $pdo->prepare(
                "SELECT e.Id_Equipe, e.Nom AS NomEquipe, e.ReEngagement, e.JourSouhaite, e.SouhaitJA,
                        d.Id_Division, d.Division, d.Nom AS NomDivision
                 FROM equipe e
                 JOIN division d ON d.Id_Division = e.Id_Division
                 WHERE e.Id_Club = ? AND d.Ord BETWEEN 70 AND 150
                 ORDER BY d.Ord, e.Nom"
            );
            $stmtE->execute([$idClub]);
            $equipes = $stmtE->fetchAll();

            echo json_encode(['ok' => true, 'club' => $club, 'equipes' => $equipes]);
            exit;
        }

        // ── Liste des JA d'un club (nom, prénom, numéro de licence) ───────────
        if ($action === 'ja_club') {
            $idClub = trim($_GET['club'] ?? '');
            if ($idClub === '') { echo json_encode(['ok' => false, 'msg' => 'Club manquant.']); exit; }

            $stmtC = $pdo->prepare('SELECT Id_Club, Nom FROM club WHERE Id_Club = ?');
            $stmtC->execute([$idClub]);
            $club = $stmtC->fetch();
            if (!$club) { echo json_encode(['ok' => false, 'msg' => 'Club introuvable.']); exit; }

            $stmtJ = $pdo->prepare(
                'SELECT Id_JA, Nom, Prenom, Grade FROM ja WHERE Id_Club = ? AND Actif = 1 ORDER BY Nom, Prenom'
            );
            $stmtJ->execute([$idClub]);
            $jas = $stmtJ->fetchAll();

            require_once __DIR__ . '/../Classes/Obfuscator.php';
            $obf = new Obfuscator(OBFUSCATOR_SEED);
            foreach ($jas as &$j) {
                $j['Token'] = $obf->obfuscate((int)$j['Id_JA']);
            }
            unset($j);

            $stmtAC = $pdo->prepare(
                "SELECT COUNT(*) FROM equipe e JOIN division d ON d.Id_Division = e.Id_Division
                 WHERE e.Id_Club = ? AND d.Division IN ('R3M','R4M') AND e.SouhaitJA = 'Club'"
            );
            $stmtAC->execute([$idClub]);
            $arbitrageClub = (bool)$stmtAC->fetchColumn();

            echo json_encode(['ok' => true, 'club' => $club, 'jas' => $jas, 'arbitrageClub' => $arbitrageClub]);
            exit;
        }

        // ── Aperçu du message n°6 (avec marqueurs résolus) ────────────────────
        if ($action === 'apercu') {
            $msgRow = $pdo->query('SELECT Sujet, Message FROM messagerie WHERE Id_Messagerie = 6')->fetch();
            if (!$msgRow) {
                echo json_encode(['ok' => false, 'msg' => "Modèle de message n°6 introuvable (à créer dans E026 — Gestion des messages)."]); exit;
            }

            $idClub = trim($_GET['club'] ?? '');
            $club = null;
            if ($idClub !== '') {
                $stmtC = $pdo->prepare('SELECT Id_Club, Nom, CorNom FROM club WHERE Id_Club = ?');
                $stmtC->execute([$idClub]);
                $club = $stmtC->fetch();
            }

            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base  = $proto . '://' . $_SERVER['HTTP_HOST']
                   . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/desiderata_club.php';

            $vars = [
                '{NOM_CLUB}'       => $club['Nom']    ?? 'Nom du club',
                '{CORR_NOM}'       => $club['CorNom'] ?? 'Nom du correspondant',
                '{URL_DESIDERATA}' => $base . '?club=' . urlencode($club['Id_Club'] ?? '09760136'),
                '{URL_LIGUE}'      => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
                '{YEAR_PHASE}'     => getAnneePhase(),
                '{UTI_NOM}'        => $moi['nom']    ?? '',
                '{UTI_PRENOM}'     => $moi['prenom'] ?? '',
            ];
            $corps = str_replace(array_keys($vars), array_values($vars), $msgRow['Message']);
            $sujet = str_replace(array_keys($vars), array_values($vars), $msgRow['Sujet']);

            echo json_encode(['ok' => true, 'sujet' => $sujet, 'corps' => $corps]);
            exit;
        }

        // ── Envoyer le questionnaire de désidératas (message système n°6) ────
        if ($action === 'envoyer') {
            $ids = json_decode($_POST['ids'] ?? '[]', true);
            if (!is_array($ids) || !$ids) {
                echo json_encode(['ok' => false, 'msg' => 'Aucun club sélectionné.']); exit;
            }

            $errRl = checkRateLimit(count($ids));
            if ($errRl !== null) {
                echo json_encode(['ok' => false, 'msg' => $errRl]); exit;
            }

            $msgRow = $pdo->query('SELECT Sujet, Message FROM messagerie WHERE Id_Messagerie = 6')->fetch();
            if (!$msgRow) {
                echo json_encode(['ok' => false, 'msg' => "Modèle de message n°6 introuvable (à créer dans E026 — Gestion des messages)."]); exit;
            }

            $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base   = $proto . '://' . $_SERVER['HTTP_HOST']
                    . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/desiderata_club.php';
            $modeDev = isModeDeveloppement();

            $ph = implode(',', array_fill(0, count($ids), '?'));
            $stmtClubs = $pdo->prepare("SELECT Id_Club, Nom, CorNom, CorEmail FROM club WHERE Id_Club IN ($ph)");
            $stmtClubs->execute($ids);
            $clubs = $stmtClubs->fetchAll();

            $envoyes = 0;
            $sansEmail = [];
            $erreurs = [];

            foreach ($clubs as $c) {
                if (empty($c['CorEmail'])) { $sansEmail[] = $c['Nom']; continue; }

                $urlDesiderata = $base . '?club=' . urlencode($c['Id_Club']);
                $vars = [
                    '{NOM_CLUB}'        => $c['Nom'],
                    '{CORR_NOM}'        => $c['CorNom'] ?? '',
                    '{URL_DESIDERATA}'  => $urlDesiderata,
                    '{URL_LIGUE}'       => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
                    '{YEAR_PHASE}'      => getAnneePhase(),
                    '{UTI_NOM}'         => $moi['nom']    ?? '',
                    '{UTI_PRENOM}'      => $moi['prenom'] ?? '',
                ];
                $corps = str_replace(array_keys($vars), array_values($vars), $msgRow['Message']);
                $sujet = str_replace(array_keys($vars), array_values($vars), $msgRow['Sujet']);

                try {
                    $dest   = getEmailDestinataire($c['CorEmail']);
                    $isHtml = strip_tags($corps) !== $corps;
                    $mail   = getNijacMailer();
                    $mail->isHTML($isHtml);
                    $mail->addAddress($dest, $c['CorNom'] ?: $c['Nom']);
                    $mail->Subject = ($modeDev && $dest !== $c['CorEmail']) ? "[DEV] $sujet" : $sujet;
                    $mail->Body    = $corps;
                    if ($isHtml) $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
                    $mail->send();
                    enregistrerEnvois(1);
                    $envoyes++;

                    $pdo->prepare('UPDATE club SET DesiderataEmailDate = NOW() WHERE Id_Club = ?')
                        ->execute([$c['Id_Club']]);
                } catch (\Exception $e) {
                    $erreurs[] = $c['Nom'] . ' : ' . $e->getMessage();
                }
            }

            $msg = "$envoyes email(s) envoyé(s).";
            if ($sansEmail) $msg .= ' Sans email : ' . implode(', ', $sansEmail) . '.';
            if ($erreurs)   $msg .= ' Erreurs : ' . implode(' | ', $erreurs);

            echo json_encode(['ok' => true, 'msg' => $msg, 'envoyes' => $envoyes]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] JA_R3R4.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ───────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
$isAdmin     = !empty($moi['is_admin']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Désidératas clubs</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; --R3M4-color: #ede7f6; }

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
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        /* ── Barre de filtres ── */
        #filter-bar {
            background: var(--R3M4-color);
            border-bottom: 2px solid #c5b0e8;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        #filter-bar label { font-size: .85rem; font-weight: 700; margin-bottom: 0; }
        #filter-bar select { font-size: .85rem; width: auto; }
        #txt-recherche { font-size: .85rem; width: 220px; }

        /* ── Barre d'actions ── */
        #action-bar {
            background: #fff;
            border-bottom: 1px solid #e0e8f0;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: .6rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        /* ── Zone principale ── */
        #main-area {
            flex: 1;
            overflow-y: auto;
            padding: 1rem 1.25rem;
        }

        /* ── Table ── */
        #tbl-clubs {
            width: 100%;
            font-size: .88rem;
            border-collapse: collapse;
        }

        #tbl-clubs thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .4rem .6rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        #tbl-clubs thead th[data-col] { cursor: pointer; user-select: none; }
        #tbl-clubs thead th[data-col]:hover { background: #d8e4f4; }
        #tbl-clubs thead th.sort-asc  .sort-icon::after { content: ' ▲'; font-size: .7rem; color: var(--nijac-blue); }
        #tbl-clubs thead th.sort-desc .sort-icon::after { content: ' ▼'; font-size: .7rem; color: var(--nijac-blue); }
        #tbl-clubs thead th[data-col]:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: ' ⇅'; font-size: .65rem; color: #aaa; }

        #tbl-clubs tbody tr { border-bottom: 1px solid #e0e8f0; cursor: pointer; }
        #tbl-clubs tbody tr:nth-child(even) { background: #f5f7fb; }
        #tbl-clubs tbody tr:hover { background: #f3effe; }
        #tbl-clubs tbody td { padding: .35rem .6rem; vertical-align: middle; }

        tr.club-selected td { background: #ede7f6 !important; }

        .badge-dept { background: #5c6bc0; color: #fff; font-size: .75rem; }
        .badge-soumis   { background: #2e7d32; color: #fff; }
        .badge-attente  { background: #bdbdbd; color: #333; }
        .badge-nb       { background: #1a3a6b; color: #fff; }
        .badge-div      { background: #7e57c2; color: #fff; font-size: .72rem; margin: 1px; }

        /* ── Spinner ── */
        #spinner {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,.45);
            z-index: 900;
            align-items: center;
            justify-content: center;
        }
        #spinner.visible { display: flex; }

        /* ── Modales (aperçu message, récapitulatif club, liste JA) ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .modal-overlay.visible { display: flex; }
        .modal-overlay .modal-card {
            background: #fff;
            border-radius: .5rem;
            width: 100%;
            max-width: 640px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,.3);
        }
        #modal-apercu .modal-card { max-width: 760px; }
        .modal-overlay .modal-card-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .6rem 1rem;
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .modal-overlay .modal-card-header .sujet { font-weight: 700; font-size: .9rem; flex: 1; }
        .modal-overlay .modal-card-header button {
            background: none; border: none; color: #fff; font-size: 1.2rem; line-height: 1;
        }
        .modal-overlay .modal-card-body { padding: 1rem; overflow-y: auto; }
        #modal-apercu iframe { flex: 1; border: 0; width: 100%; min-height: 400px; }
        .modal-overlay .note-club {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-radius: .35rem;
            padding: .5rem .75rem;
            font-size: .82rem;
            margin-bottom: .75rem;
        }
        #tbl-detail, #tbl-ja { width: 100%; font-size: .85rem; border-collapse: collapse; }
        #tbl-detail th, #tbl-ja th {
            background: #e8eef7;
            padding: .4rem .5rem;
            text-align: center;
            font-size: .78rem;
            white-space: nowrap;
        }
        #tbl-detail th:first-child, #tbl-detail td:first-child,
        #tbl-ja th:first-child, #tbl-ja td:first-child { text-align: left; }
        #tbl-detail td, #tbl-ja td { padding: .4rem .5rem; border-top: 1px solid #e8eef7; text-align: center; vertical-align: middle; }
        #tbl-detail .badge-oui   { background: #2e7d32; color: #fff; }
        #tbl-detail .badge-non   { background: #c62828; color: #fff; }
        #tbl-detail .badge-jour  { background: #5c6bc0; color: #fff; }
        #tbl-detail .badge-cra   { background: #1a3a6b; color: #fff; }
        #tbl-detail .badge-club  { background: #7e57c2; color: #fff; }
        #tbl-ja .badge-grade     { background: #1a3a6b; color: #fff; }
        #tbl-ja tbody tr:nth-child(even) { background: #f5f7fb; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-clipboard2-check'; $pageTitle = 'Désidératas clubs (PN à R4)'; $pageCode = 'E027'; $backUrl = $isAdmin ? '../admin_menu.php' : 'menu.php'; $backBtnClass = 'ms-auto btn btn-sm btn-outline-light'; require __DIR__ . '/../includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Barre de filtres -->
<div id="filter-bar">
    <label for="sel-dept"><i class="bi bi-geo-alt me-1"></i>Département :</label>
    <select id="sel-dept" class="form-select form-select-sm">
        <option value="0">— Tous —</option>
    </select>

    <label for="sel-statut" class="ms-3">Statut :</label>
    <select id="sel-statut" class="form-select form-select-sm">
        <option value="">— Tous —</option>
        <option value="soumis">Soumis (saison en cours)</option>
        <option value="attente">En attente</option>
    </select>

    <label for="txt-recherche" class="ms-3"><i class="bi bi-search me-1"></i>Recherche :</label>
    <input type="search" id="txt-recherche" class="form-control form-control-sm"
           placeholder="Club…" autocomplete="off">

    <span id="lbl-count" class="ms-3 text-muted" style="font-size:.82rem;"></span>
</div>

<!-- Barre d'actions -->
<div id="action-bar">
    <button class="btn btn-sm btn-outline-secondary" id="btn-tout-selectionner">
        <i class="bi bi-check2-square me-1"></i>Tout sélectionner
    </button>
    <button class="btn btn-sm btn-outline-secondary" id="btn-tout-deselectionner">
        <i class="bi bi-square me-1"></i>Tout désélectionner
    </button>
    <span id="lbl-selection" class="text-muted" style="font-size:.82rem;">0 club(s) sélectionné(s)</span>
    <button class="btn btn-sm btn-outline-primary ms-auto" id="btn-apercu-message">
        <i class="bi bi-eye me-1"></i>Visualiser le message
    </button>
    <button class="btn btn-sm btn-primary" id="btn-envoyer" disabled>
        <i class="bi bi-envelope-fill me-1"></i>Envoyer le questionnaire (message n°6)
    </button>
</div>

<!-- Tableau principal -->
<div id="main-area">
    <table id="tbl-clubs">
        <thead>
            <tr>
                <th style="width:2.2rem; text-align:center;"><input type="checkbox" id="chk-all"></th>
                <th style="width:3rem;"   data-col="dept">Dépt<span class="sort-icon"></span></th>
                <th                       data-col="club">Club<span class="sort-icon"></span></th>
                <th                       data-col="correspondant">Correspondant<span class="sort-icon"></span></th>
                <th style="width:6rem; text-align:center;" data-col="equipes">Équipes<span class="sort-icon"></span></th>
                <th                       data-col="divisions">Divisions<span class="sort-icon"></span></th>
                <th style="width:9rem; text-align:center;" data-col="statut">Statut<span class="sort-icon"></span></th>
                <th style="width:4rem; text-align:center;" data-col="note">Note<span class="sort-icon"></span></th>
                <th style="width:9rem; text-align:center;" data-col="envoi">Dernier envoi<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-clubs">
            <tr><td colspan="9" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Spinner -->
<div id="spinner"><div class="spinner-border text-primary"></div></div>

<!-- Modale aperçu message -->
<div id="modal-apercu" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-header">
            <span class="sujet" id="apercu-sujet"></span>
            <button type="button" id="btn-apercu-fermer" aria-label="Fermer">&times;</button>
        </div>
        <iframe id="apercu-iframe"></iframe>
    </div>
</div>

<!-- Modale récapitulatif désidératas club -->
<div id="modal-detail" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-header">
            <span class="sujet" id="detail-sujet"></span>
            <button type="button" id="btn-detail-fermer" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-card-body">
            <div id="detail-note" class="note-club" style="display:none;"></div>
            <table id="tbl-detail">
                <thead>
                    <tr>
                        <th>Équipe</th>
                        <th>Maintenu</th>
                        <th>Jour</th>
                        <th>Souhait JA</th>
                    </tr>
                </thead>
                <tbody id="tbody-detail"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modale liste des JA du club -->
<div id="modal-ja" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-header">
            <span class="sujet" id="ja-sujet"></span>
            <button type="button" id="btn-ja-fermer" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-card-body">
            <table id="tbl-ja">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Grade</th>
                        <th>N° licence</th>
                        <th id="th-ja-arbitrage" style="display:none;">Arbitrage club</th>
                    </tr>
                </thead>
                <tbody id="tbody-ja"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

// ── Utilitaires ───────────────────────────────────────────────────────────────
function toast(msg, type = 'ok') {
    nijacToast(msg, type === 'err' ? 'danger' : 'success');
}

function spin(on) {
    document.getElementById('spinner').classList.toggle('visible', on);
}

async function apiGet(params) {
    const qs = new URLSearchParams(params).toString();
    const r  = await fetch('JA_R3R4.php?' + qs);
    return r.json();
}

async function apiPost(data) {
    data._csrf = CSRF;
    const r = await fetch('JA_R3R4.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString()
    });
    return r.json();
}

function escHtml(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Données brutes + sélection + tri ──────────────────────────────────────────
let tousClubs = [];
let saisonActuelle = '';
let selection = new Set();
const sortState = { col: 'club', asc: true };

// ── Charger les départements disponibles ─────────────────────────────────────
async function chargerDepartements() {
    const res = await apiGet({ action: 'departements' });
    if (!res.ok) return;
    const sel = document.getElementById('sel-dept');
    res.data.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.code;
        opt.textContent = d.code + ' — ' + d.nom;
        sel.appendChild(opt);
    });
}

// ── Charger la liste des clubs ────────────────────────────────────────────────
async function chargerListe() {
    spin(true);
    const dept = document.getElementById('sel-dept').value;
    const res  = await apiGet({ action: 'liste', dept });
    spin(false);
    if (!res.ok) { toast(res.msg, 'err'); return; }
    tousClubs = res.data;
    saisonActuelle = res.saison || '';
    selection.clear();
    filtrerEtAfficher();
}

function estSoumis(c) {
    return c.DesiderataSaison && c.DesiderataSaison === saisonActuelle;
}

// ── Valeur de tri pour une colonne ───────────────────────────────────────────
function valTri(c, col) {
    switch (col) {
        case 'dept':          return (c.Departement || (c.Id_Club || '').substring(2, 4) || '').trim();
        case 'club':          return (c.NomClub || '').toLowerCase();
        case 'correspondant': return (c.CorNom || c.CorEmail || '').toLowerCase();
        case 'equipes':       return +c.NbEquipes;
        case 'divisions':     return (c.Divisions || '').toLowerCase();
        case 'statut':        return estSoumis(c) ? 1 : 0;
        case 'note':          return c.DesiderataNote && c.DesiderataNote.trim() ? 1 : 0;
        case 'envoi':         return c.DesiderataEmailDate ? new Date(c.DesiderataEmailDate).getTime() : 0;
        default:               return '';
    }
}

// ── Filtrer et afficher ───────────────────────────────────────────────────────
function filtrerEtAfficher() {
    const statutFilter = document.getElementById('sel-statut').value;
    const recherche    = document.getElementById('txt-recherche').value.trim().toLowerCase();

    const data = tousClubs.filter(c => {
        if (statutFilter === 'soumis'  && !estSoumis(c))  return false;
        if (statutFilter === 'attente' &&  estSoumis(c))  return false;
        if (recherche && !c.NomClub.toLowerCase().includes(recherche)) return false;
        return true;
    });

    data.sort((a, b) => {
        const va = valTri(a, sortState.col), vb = valTri(b, sortState.col);
        const cmp = va < vb ? -1 : va > vb ? 1 : 0;
        return sortState.asc ? cmp : -cmp;
    });

    const tbody = document.getElementById('tbody-clubs');
    tbody.innerHTML = '';

    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Aucun club trouvé.</td></tr>';
        document.getElementById('lbl-count').textContent = '';
        majBarreSelection();
        return;
    }

    document.getElementById('lbl-count').textContent = `${data.length} club(s)`;

    data.forEach(c => {
        const tr = document.createElement('tr');
        tr.dataset.id = c.Id_Club;
        if (selection.has(c.Id_Club)) tr.classList.add('club-selected');

        const soumis   = estSoumis(c);
        const statutEl = soumis
            ? `<span class="badge badge-soumis" style="cursor:pointer" title="Voir le récapitulatif">Soumis</span>`
            : `<span class="badge badge-attente">En attente</span>`;
        const dernierEnvoi = c.DesiderataEmailDate
            ? new Date(c.DesiderataEmailDate).toLocaleDateString('fr-FR')
            : '<span class="text-muted">Jamais</span>';
        const checked = selection.has(c.Id_Club) ? 'checked' : '';
        // Recalculé côté client à partir de Id_Club pour toujours afficher le département,
        // même si la valeur agrégée côté serveur venait à manquer.
        const deptTxt = (c.Departement || (c.Id_Club || '').substring(2, 4) || '').trim();
        const divTeamMap = {};
        (c.DivisionsEquipes || '').split('||').filter(Boolean).forEach(pair => {
            const [div, eqName] = pair.split('§');
            if (!div) return;
            (divTeamMap[div] = divTeamMap[div] || []).push(eqName || '');
        });
        const divisionsHtml = (c.Divisions || '').split(',').filter(Boolean)
            .map(d => `<span class="badge badge-div" title="${escHtml((divTeamMap[d] || []).join(', '))}">${escHtml(d)}</span>`).join('') || '<span class="text-muted">—</span>';
        const noteEl = c.DesiderataNote && c.DesiderataNote.trim()
            ? `<i class="bi bi-chat-square-text-fill text-warning" title="${escHtml(c.DesiderataNote)}"></i>`
            : '<span class="text-muted">—</span>';

        tr.innerHTML = `
            <td style="text-align:center;"><input type="checkbox" class="chk-club" data-id="${escHtml(c.Id_Club)}" ${checked}></td>
            <td><span class="badge badge-dept">${escHtml(deptTxt || '—')}</span></td>
            <td>${escHtml(c.NomClub)}</td>
            <td>${c.CorEmail ? escHtml(c.CorNom || '') + ' <span class="text-muted">(' + escHtml(c.CorEmail) + ')</span>' : '<span class="text-danger">Pas d\'email</span>'}</td>
            <td style="text-align:center;"><span class="badge badge-nb" style="cursor:pointer" title="Voir les JA du club">${c.NbEquipes}</span></td>
            <td>${divisionsHtml}</td>
            <td style="text-align:center;">${statutEl}</td>
            <td style="text-align:center;">${noteEl}</td>
            <td style="text-align:center;">${dernierEnvoi}</td>`;
        tbody.appendChild(tr);
    });

    majBarreSelection();
}

function majBarreSelection() {
    document.getElementById('lbl-selection').textContent = `${selection.size} club(s) sélectionné(s)`;
    document.getElementById('btn-envoyer').disabled = selection.size === 0;
    document.getElementById('chk-all').checked =
        document.querySelectorAll('#tbody-clubs .chk-club').length > 0 &&
        document.querySelectorAll('#tbody-clubs .chk-club:not(:checked)').length === 0;
}

// ── Sélection individuelle ────────────────────────────────────────────────────
document.getElementById('tbody-clubs').addEventListener('change', ev => {
    const chk = ev.target.closest('.chk-club');
    if (!chk) return;
    const id = chk.dataset.id;
    if (chk.checked) selection.add(id); else selection.delete(id);
    chk.closest('tr').classList.toggle('club-selected', chk.checked);
    majBarreSelection();
});

// ── Clic sur la ligne = bascule la case à cocher ─────────────────────────────
document.getElementById('tbody-clubs').addEventListener('click', ev => {
    if (ev.target.closest('.chk-club')) return; // déjà géré nativement
    const badgeSoumis = ev.target.closest('.badge-soumis');
    if (badgeSoumis) {
        const tr = badgeSoumis.closest('tr[data-id]');
        if (tr) ouvrirModalDetail(tr.dataset.id);
        return;
    }
    const badgeNb = ev.target.closest('.badge-nb');
    if (badgeNb) {
        const tr = badgeNb.closest('tr[data-id]');
        if (tr) ouvrirModalJa(tr.dataset.id);
        return;
    }
    const tr = ev.target.closest('tr[data-id]');
    if (!tr) return;
    const chk = tr.querySelector('.chk-club');
    if (!chk) return;
    chk.checked = !chk.checked;
    chk.dispatchEvent(new Event('change', { bubbles: true }));
});

document.getElementById('chk-all').addEventListener('change', ev => {
    document.querySelectorAll('#tbody-clubs .chk-club').forEach(chk => {
        chk.checked = ev.target.checked;
        const id = chk.dataset.id;
        if (chk.checked) selection.add(id); else selection.delete(id);
        chk.closest('tr').classList.toggle('club-selected', chk.checked);
    });
    majBarreSelection();
});

// ── Tout sélectionner / désélectionner (tous clubs filtrés, toutes pages) ────
document.getElementById('btn-tout-selectionner').addEventListener('click', () => {
    tousClubs.forEach(c => selection.add(c.Id_Club));
    filtrerEtAfficher();
});
document.getElementById('btn-tout-deselectionner').addEventListener('click', () => {
    selection.clear();
    filtrerEtAfficher();
});

// ── Envoi du questionnaire ─────────────────────────────────────────────────────
document.getElementById('btn-envoyer').addEventListener('click', async () => {
    if (selection.size === 0) return;
    const $btn = document.getElementById('btn-envoyer');
    $btn.disabled = true;
    spin(true);
    const res = await apiPost({ action: 'envoyer', ids: JSON.stringify(Array.from(selection)) });
    spin(false);
    $btn.disabled = false;

    if (!res.ok) { toast(res.msg, 'err'); return; }
    toast(res.msg, 'ok');
    await chargerListe();
});

// ── Tri par clic sur en-tête ─────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé en fin de page (includes/footer.php),
// donc pas encore défini si on l'appelait ici de façon synchrone.
document.addEventListener('DOMContentLoaded', () => {
    nijacSortableTable('#tbl-clubs thead th[data-col]', 'col', sortState, filtrerEtAfficher);
});

// ── Aperçu du message n°6 ──────────────────────────────────────────────────────
document.getElementById('btn-apercu-message').addEventListener('click', async () => {
    const club = selection.size > 0 ? Array.from(selection)[0] : '';
    spin(true);
    const res = await apiGet({ action: 'apercu', club });
    spin(false);
    if (!res.ok) { toast(res.msg, 'err'); return; }
    document.getElementById('apercu-sujet').textContent = res.sujet;
    document.getElementById('apercu-iframe').srcdoc = res.corps;
    document.getElementById('modal-apercu').classList.add('visible');
});
document.getElementById('btn-apercu-fermer').addEventListener('click', () => {
    document.getElementById('modal-apercu').classList.remove('visible');
});
document.getElementById('modal-apercu').addEventListener('click', ev => {
    if (ev.target.id === 'modal-apercu') ev.currentTarget.classList.remove('visible');
});

// ── Récapitulatif des désidératas d'un club ──────────────────────────────────
async function ouvrirModalDetail(idClub) {
    spin(true);
    const res = await apiGet({ action: 'detail', club: idClub });
    spin(false);
    if (!res.ok) { toast(res.msg, 'err'); return; }

    document.getElementById('detail-sujet').textContent = res.club.Nom;

    const $note = document.getElementById('detail-note');
    if (res.club.DesiderataNote && res.club.DesiderataNote.trim()) {
        $note.innerHTML = `<i class="bi bi-chat-square-text-fill me-1 text-warning"></i>${escHtml(res.club.DesiderataNote)}`;
        $note.style.display = '';
    } else {
        $note.style.display = 'none';
    }

    const tbody = document.getElementById('tbody-detail');
    tbody.innerHTML = '';
    if (!res.equipes.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Aucune équipe (Pré-Nationale à R4) pour ce club.</td></tr>';
    } else {
        res.equipes.forEach(e => {
            const maintenuEl = e.ReEngagement === 'O'
                ? '<span class="badge badge-oui">Oui</span>'
                : e.ReEngagement === 'N'
                    ? '<span class="badge badge-non">Non</span>'
                    : '<span class="text-muted">—</span>';
            const jourEl = e.JourSouhaite
                ? `<span class="badge badge-jour">${escHtml(e.JourSouhaite)}</span>`
                : '<span class="text-muted">—</span>';
            const isR34 = e.Division === 'R3M' || e.Division === 'R4M';
            const souhaitEl = !isR34
                ? '<span class="text-muted">—</span>'
                : e.SouhaitJA === 'CRA'
                    ? '<span class="badge badge-cra">CRA</span>'
                    : e.SouhaitJA === 'Club'
                        ? '<span class="badge badge-club">Club</span>'
                        : '<span class="text-muted">—</span>';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${escHtml(e.NomEquipe)} <span class="badge div-badge" style="background:#5c6bc0">${escHtml(e.Division)}</span></td>
                <td>${maintenuEl}</td>
                <td>${jourEl}</td>
                <td>${souhaitEl}</td>`;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('modal-detail').classList.add('visible');
}
document.getElementById('btn-detail-fermer').addEventListener('click', () => {
    document.getElementById('modal-detail').classList.remove('visible');
});
document.getElementById('modal-detail').addEventListener('click', ev => {
    if (ev.target.id === 'modal-detail') ev.currentTarget.classList.remove('visible');
});

// ── Liste des JA d'un club ────────────────────────────────────────────────────
async function ouvrirModalJa(idClub) {
    spin(true);
    const res = await apiGet({ action: 'ja_club', club: idClub });
    spin(false);
    if (!res.ok) { toast(res.msg, 'err'); return; }

    document.getElementById('ja-sujet').textContent = 'JA du club — ' + res.club.Nom;
    document.getElementById('th-ja-arbitrage').style.display = res.arbitrageClub ? '' : 'none';

    const tbody = document.getElementById('tbody-ja');
    tbody.innerHTML = '';
    if (!res.jas.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Aucun JA rattaché à ce club.</td></tr>';
    } else {
        res.jas.forEach(j => {
            const tr = document.createElement('tr');
            const colArbitrage = res.arbitrageClub
                ? `<td><a href="../JA/info_rencontre.php?ja=${escHtml(j.Token)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                       <i class="bi bi-calendar2-check me-1"></i>Choisir les dates
                   </a></td>`
                : '';
            tr.innerHTML = `
                <td>${escHtml(j.Nom)} ${escHtml(j.Prenom || '')}</td>
                <td>${j.Grade ? `<span class="badge badge-grade">${escHtml(j.Grade)}</span>` : '<span class="text-muted">—</span>'}</td>
                <td>${escHtml(j.Id_JA)}</td>
                ${colArbitrage}`;
            tbody.appendChild(tr);
        });
    }

    document.getElementById('modal-ja').classList.add('visible');
}
document.getElementById('btn-ja-fermer').addEventListener('click', () => {
    document.getElementById('modal-ja').classList.remove('visible');
});
document.getElementById('modal-ja').addEventListener('click', ev => {
    if (ev.target.id === 'modal-ja') ev.currentTarget.classList.remove('visible');
});

// ── Filtres ──────────────────────────────────────────────────────────────────
document.getElementById('sel-dept').addEventListener('change', chargerListe);
document.getElementById('sel-statut').addEventListener('change', filtrerEtAfficher);
document.getElementById('txt-recherche').addEventListener('input', filtrerEtAfficher);

// ── Init ─────────────────────────────────────────────────────────────────────
(async () => {
    await chargerDepartements();
    await chargerListe();
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
