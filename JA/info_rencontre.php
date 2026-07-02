<?php
/**
 * NIJAC – Fiche personnelle du Juge-Arbitre connecté (E030)
 *
 * Page d'accueil réservée aux JA qui se connectent via leur nom + numéro de licence.
 * Affiche leurs informations personnelles, leurs nominations à venir
 * et leurs disponibilités pour la saison en cours.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-30
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../Classes/Obfuscator.php';

// ── Sécurité : accès réservé aux JA connectés ────────────────────────────────
$authRedirect = '../index.php';
$allowJa      = true;
require __DIR__ . '/../includes/auth_required.php';

$moi = $_SESSION['utilisateur'];
if ($moi['role'] !== 'JA') {
    header('Location: ../Nominateur/menu.php');
    exit;
}

$idJa = $moi['id_ja'] ?? $moi['login'];
$pdo  = getPDO();

// ── Chargement de la fiche JA ─────────────────────────────────────────────────
$stmtJa = $pdo->prepare(
    'SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.Telephone, j.Grade, j.GradeFFTT,
            j.Actif, j.Id_Club, j.Id_LaPoste, j.Defiscalisation, j.Nationale,
            j.Classement, j.DateValidationFFTT,
            COALESCE(j.Cp,    lp.CodePostal) AS CodePostal,
            COALESCE(j.Ville, lp.Nom)        AS Ville,
            cl.Nom AS NomClub
     FROM ja j
     LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
     LEFT JOIN Club    cl ON cl.Id_Club     = j.Id_Club
     WHERE j.Id_JA = :id_ja'
);
$stmtJa->execute([':id_ja' => $idJa]);
$ja = $stmtJa->fetch();

if (!$ja) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// ── Actions AJAX ─────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    csrfVerify(true);

    if ($action === 'se_designer') {
        $idRencontre = (int)($_POST['id_rencontre'] ?? 0);
        if (!$idRencontre) { echo json_encode(['ok' => false, 'msg' => 'Rencontre invalide.']); exit; }

        // Charger la rencontre + vérifier qu'elle appartient au club du JA
        $stmtR = $pdo->prepare(
            'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                    dv.Division, RIGHT(dv.Division, 1) AS SexeCode,
                    ed.Nom AS NomDom, ev.Nom AS NomExt,
                    s.Nom AS SalleNom, s.Adresse AS SalleAdresse,
                    lps.CodePostal AS SalleCP, lps.Nom AS SalleVille,
                    cl.CorNom AS CorrNom, cl.CorEmail AS CorrEmail, cl.CorTelephone AS CorrTel
             FROM rencontre r
             JOIN division dv   ON dv.Id_Division = r.Id_Division
             JOIN equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
             LEFT JOIN equipe ev ON ev.Id_Equipe  = r.Id_EquipeExt
             LEFT JOIN salle s   ON s.Id_Salle    = r.Id_Salle
             LEFT JOIN laposte lps ON lps.Id_LaPoste = s.Id_Laposte
             LEFT JOIN Club cl   ON cl.Id_Club    = ed.Id_Club
             WHERE r.Id_Rencontre = ? AND ed.Id_Club = ?'
        );
        $stmtR->execute([$idRencontre, $ja['Id_Club']]);
        $renc = $stmtR->fetch();
        if (!$renc) { echo json_encode(['ok' => false, 'msg' => 'Rencontre introuvable ou non autorisée.']); exit; }

        // Vérifier qu'aucun JA n'est déjà désigné
        $already = $pdo->prepare('SELECT Id_Nomination FROM nomination WHERE Id_Rencontre = ?');
        $already->execute([$idRencontre]);
        if ($already->fetch()) { echo json_encode(['ok' => false, 'msg' => 'Un JA est déjà désigné pour cette rencontre.']); exit; }

        // Insérer dans disponible si pas déjà présent
        $dispo = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $dispo->execute([$idJa, $idRencontre]);
        $idDispo = $dispo->fetchColumn();
        if (!$idDispo) {
            $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse) VALUES (?, ?, ?, 'P', CURDATE())")
                ->execute([$idJa, $idRencontre, $renc['Date']]);
            $idDispo = (int)$pdo->lastInsertId();
        }

        // Créer la nomination
        $pdo->prepare('INSERT INTO nomination (Id_Rencontre, Id_Disponible, DateNomination, Valide, EmailEnvoye) VALUES (?, ?, CURDATE(), 1, 0)')
            ->execute([$idRencontre, $idDispo]);
        $idNomination = (int)$pdo->lastInsertId();

        // Charger le modèle convocation et envoyer l'email
        $msgRow = $pdo->query("SELECT Sujet, Message FROM messagerie WHERE Type = 'Convocation' LIMIT 1")->fetch();
        $emailSent = false;
        if ($msgRow && !empty($ja['Email'])) {
            $_obf = new Obfuscator(OBFUSCATOR_SEED);
            $token = $_obf->obfuscate((int)$idJa);
            $sexe  = ($renc['SexeCode'] ?? '') === 'F' ? 'Féminin' : (($renc['SexeCode'] ?? '') === 'M' ? 'Mixte' : '');
            $vars  = [
                '{NOM}'           => $ja['Nom'],
                '{PRENOM}'        => $ja['Prenom'],
                '{NOM_COMPLET}'   => $ja['Prenom'] . ' ' . $ja['Nom'],
                '{ID_JA}'         => $token,
                '{ID_CONVOCATION}'=> (string)$idNomination,
                '{SEXE}'          => $sexe,
                '{UTI_NOM}'       => '',
                '{UTI_PRENOM}'    => '',
                '{URL_LIGUE}'     => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
                '{DATE}'          => $renc['Date'] ? date('d/m/Y', strtotime($renc['Date'])) : '',
                '{HEURE}'         => substr($renc['Heure'] ?? '', 0, 5),
                '{JOURNEE}'       => $renc['Journee'] ?? '',
                '{POULE}'         => $renc['Poule'] ?? '',
                '{DIVISION}'      => $renc['Division'] ?? '',
                '{DOM}'           => $renc['NomDom'] ?? '',
                '{EXT}'           => $renc['NomExt'] ?? '',
                '{SALLE_NOM}'     => $renc['SalleNom'] ?? '',
                '{SALLE_ADRESSE}' => $renc['SalleAdresse'] ?? '',
                '{SALLE_CP}'      => $renc['SalleCP'] ?? '',
                '{SALLE_VILLE}'   => $renc['SalleVille'] ?? '',
                '{CORR_NOM}'      => $renc['CorrNom'] ?? '',
                '{CORR_EMAIL}'    => $renc['CorrEmail'] ?? '',
                '{CORR_TEL}'      => $renc['CorrTel'] ?? '',
            ];
            $corps = str_replace(array_keys($vars), array_values($vars), $msgRow['Message']);
            $sujet = str_replace(array_keys($vars), array_values($vars), $msgRow['Sujet']);
            try {
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
                $pdo->prepare('UPDATE nomination SET EmailEnvoye = 1 WHERE Id_Nomination = ?')->execute([$idNomination]);
                $emailSent = true;
            } catch (\Exception $e) {
                error_log('[NIJAC] info_rencontre se_designer mail : ' . $e->getMessage());
            }
        }

        $msg = $emailSent
            ? 'Désignation enregistrée. Un email de convocation vous a été envoyé.'
            : 'Désignation enregistrée (email non envoyé).';
        echo json_encode(['ok' => true, 'msg' => $msg, 'id_ja' => $idJa]);
        exit;
    }

    if ($action === 'recherche_laposte') {
        require_once __DIR__ . '/../config/helpers.php';
        $cp    = trim($_POST['cp']    ?? '');
        $ville = normaliserVille($_POST['ville'] ?? '');
        if ($cp === '' && $ville === '') { echo json_encode(['ok' => false, 'msg' => 'CP et ville vides.']); exit; }

        if ($cp !== '' && $ville !== '') {
            $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? AND REPLACE(REPLACE(Nom,'-',' '),'SAINT ','ST ') LIKE ? LIMIT 1");
            $stmt->execute([$cp, $ville . '%']);
            $row = $stmt->fetch();
            if ($row) { echo json_encode(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]); exit; }
        }
        if ($cp !== '') {
            $rows = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
            $rows->execute([$cp]); $rows = $rows->fetchAll();
            if (count($rows) === 1) { echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]); exit; }
            if (count($rows) > 1)  { echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows)]); exit; }
        }
        if ($ville !== '') {
            $rows = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE REPLACE(REPLACE(Nom,'-',' '),'SAINT ','ST ') LIKE ? ORDER BY CodePostal, Nom LIMIT 20");
            $rows->execute([$ville . '%']); $rows = $rows->fetchAll();
            if (count($rows) === 1) { echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]); exit; }
            if (count($rows) > 1)  { echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows)]); exit; }
        }
        echo json_encode(['ok' => false, 'msg' => 'Commune non trouvée.']); exit;
    }

    if ($action === 'sauvegarder_adresse') {
        $idLaPoste = ($_POST['id_laposte'] ?? '') !== '' ? (int)$_POST['id_laposte'] : null;
        $cp        = trim($_POST['cp']    ?? '');
        $ville     = trim($_POST['ville'] ?? '');
        if (!$idLaPoste) { echo json_encode(['ok' => false, 'msg' => 'Sélectionnez une commune valide.']); exit; }
        $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
            ->execute([$idLaPoste, $cp ?: null, $ville ?: null, $idJa]);
        echo json_encode(['ok' => true, 'cp' => $cp, 'ville' => $ville]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']); exit;
}

// ── Mes nominations (à venir + déjà arbitrées) ────────────────────────────────
$nominations = $pdo->prepare(
    'SELECT n.Id_Nomination, r.Date AS DateRencontre, r.Heure AS HeureRencontre,
            s.Nom AS NomSalle, s.Adresse AS AdresseSalle,
            lps.CodePostal AS CpSalle, lps.Nom AS VilleSalle,
            d.Division AS DivisionCode, d.Color AS DivisionColor,
            ec.Nom AS NomClubDomicile,
            ev.Nom AS NomClubVisiteur,
            (r.Date >= CURDATE()) AS AVenir
     FROM Nomination n
     JOIN disponible dp ON dp.Id_Disponible = n.Id_Disponible
     JOIN Rencontre r ON r.Id_Rencontre = n.Id_Rencontre
     LEFT JOIN Salle s   ON s.Id_Salle   = r.Id_Salle
     LEFT JOIN laposte lps ON lps.Id_LaPoste = s.Id_LaPoste
     LEFT JOIN Division d  ON d.Id_Division = r.Id_Division
     LEFT JOIN equipe ede ON ede.Id_Equipe = r.Id_EquipeDom
     LEFT JOIN Club  ec  ON ec.Id_Club = ede.Id_Club
     LEFT JOIN equipe eve ON eve.Id_Equipe = r.Id_EquipeExt
     LEFT JOIN Club  ev  ON ev.Id_Club = eve.Id_Club
     WHERE dp.Id_JA = :id_ja
     ORDER BY (r.Date >= CURDATE()) DESC,
              CASE WHEN r.Date >= CURDATE() THEN r.Date END ASC,
              CASE WHEN r.Date <  CURDATE() THEN r.Date END DESC
     LIMIT 15'
);
$nominations->execute([':id_ja' => $idJa]);
$prochaines = $nominations->fetchAll();

// ── Rencontres R3/R4 à venir ─────────────────────────────────────────────────
$stmtR3R4 = $pdo->prepare(
    'SELECT r.Id_Rencontre, r.Date AS DateRencontre, r.Heure AS HeureRencontre,
            r.Journee, r.Poule,
            dv.Division AS DivisionCode, dv.Nom AS DivisionNom, dv.Color AS DivisionColor,
            ed.Nom AS NomDom, ev.Nom AS NomExt,
            COALESCE(s_r.Nom,  s_c.Nom)         AS NomSalle,
            COALESCE(lp_r.CodePostal, lp_c.CodePostal) AS CpSalle,
            COALESCE(lp_r.Nom,        lp_c.Nom)        AS VilleSalle,
            d_n.Id_JA AS IdJaAffecte,
            CONCAT(ja_n.Prenom, \' \', ja_n.Nom) AS NomJaAffecte
     FROM rencontre r
     JOIN division dv   ON dv.Id_Division = r.Id_Division
     JOIN equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
     LEFT JOIN equipe ev ON ev.Id_Equipe  = r.Id_EquipeExt
     LEFT JOIN salle   s_r  ON s_r.Id_Salle   = r.Id_Salle
     LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
     LEFT JOIN salle   s_c  ON s_c.Id_Club = ed.Id_Club AND s_c.EstPrincipale = 1
     LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
     LEFT JOIN nomination n   ON n.Id_Rencontre = r.Id_Rencontre
     LEFT JOIN disponible d_n ON d_n.Id_Disponible = n.Id_Disponible
     LEFT JOIN ja ja_n        ON ja_n.Id_JA     = d_n.Id_JA
     WHERE (dv.ArbitrageObligatoire = 1 OR ed.JAdemande = 1 OR dv.Division IN (\'R3M\', \'R4M\'))
       AND ed.Id_Club = :id_club
       AND r.Date >= CURDATE()
     ORDER BY r.Date ASC, r.Heure ASC, dv.Ord ASC'
);
$stmtR3R4->execute([':id_club' => $ja['Id_Club']]);
$rencontresR3R4 = $stmtR3R4->fetchAll();

// ── En-tête de page ──────────────────────────────────────────────────────────
$pageIcon  = 'bi-person-badge';
$pageTitle = 'Ma fiche Juge-Arbitre';
$pageCode  = 'E030';
$backUrl   = null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Ma fiche JA (E030)</title>
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
        .container-fluid { flex: 1; }
    </style>
</head>
<body>
<?php require __DIR__ . '/../includes/page_header.php'; ?>

<div class="container-fluid px-3 pb-4 pt-3">

    <!-- ── Identité ─────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold bg-primary text-white">
            <i class="bi bi-person-circle me-2"></i>Identité
        </div>
        <div class="card-body">
            <h4 class="mb-1"><?= htmlspecialchars($ja['Prenom'] . ' ' . $ja['Nom']) ?></h4>
            <p class="text-muted mb-2 small">Licence&nbsp;: <strong><?= htmlspecialchars($ja['Id_JA']) ?></strong></p>
            <table class="table table-sm table-borderless mb-0" style="max-width:400px">
                <?php if ($ja['NomClub']): ?>
                <tr>
                    <th class="text-muted fw-normal ps-0" style="width:40%">Club</th>
                    <td><?= htmlspecialchars($ja['NomClub']) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="background:#eef4ff;border-radius:6px;">
                    <th class="text-muted fw-normal ps-0" style="border-left:3px solid #1a3a6b;padding-left:.5rem !important;">Domicile</th>
                    <td>
                        <span id="lbl-domicile" class="fw-semibold">
                            <?= htmlspecialchars(trim(($ja['CodePostal'] ?? '') . ' ' . ($ja['Ville'] ?? ''))) ?: '<em class="text-muted fw-normal">Non renseigné</em>' ?>
                        </span>
                        <button class="btn btn-primary btn-sm ms-2 py-0 px-2" data-bs-toggle="modal" data-bs-target="#modal-adresse" title="Modifier mon adresse">
                            <i class="bi bi-pencil-fill me-1"></i>Modifier
                        </button>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ── Nominations (à venir + déjà arbitrées) ──────────────────────────── -->
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-check me-2"></i>Mes nominations</span>
            <span class="badge bg-light text-dark"><?= count($prochaines) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($prochaines)): ?>
            <p class="text-muted p-3 mb-0"><i class="bi bi-info-circle me-2"></i>Aucune nomination pour le moment.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Heure</th>
                            <th>Division</th>
                            <th>Rencontre</th>
                            <th>Salle</th>
                            <th>Lieu</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prochaines as $n):
                            $dateFr = $n['DateRencontre']
                                ? (new DateTime($n['DateRencontre']))->format('d/m/Y')
                                : '—';
                            $heure  = $n['HeureRencontre']
                                ? substr($n['HeureRencontre'], 0, 5)
                                : '—';
                            $rencontre = htmlspecialchars(($n['NomClubDomicile'] ?? '?') . ' – ' . ($n['NomClubVisiteur'] ?? '?'));
                            $lieu = htmlspecialchars(trim(($n['CpSalle'] ?? '') . ' ' . ($n['VilleSalle'] ?? '')));
                            $bgN  = $n['DivisionColor'] ?: '#6c757d';
                            $hexN = ltrim($bgN, '#');
                            $lumN = strlen($hexN) === 6
                                ? (0.299*hexdec(substr($hexN,0,2)) + 0.587*hexdec(substr($hexN,2,2)) + 0.114*hexdec(substr($hexN,4,2))) / 255
                                : 0;
                            $fgN     = $lumN > 0.55 ? '#111' : '#fff';
                            $aVenir  = (bool)$n['AVenir'];
                        ?>
                        <tr<?= $aVenir ? '' : ' style="opacity:.6"' ?>>
                            <td class="fw-semibold"><?= $dateFr ?></td>
                            <td><?= $heure ?></td>
                            <td><span class="badge" style="background:<?= htmlspecialchars($bgN) ?>;color:<?= $fgN ?>"><?= htmlspecialchars($n['DivisionCode'] ?? '—') ?></span></td>
                            <td><?= $rencontre ?></td>
                            <td><?= htmlspecialchars($n['NomSalle'] ?? '—') ?></td>
                            <td class="text-muted small"><?= $lieu ?></td>
                            <td>
                                <?php if ($aVenir): ?>
                                <span class="badge bg-primary"><i class="bi bi-hourglass-split me-1"></i>À venir</span>
                                <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-check-circle me-1"></i>Arbitrée</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Rencontres R3 / R4 à venir ──────────────────────────────────── -->
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header fw-semibold bg-warning text-dark d-flex justify-content-between align-items-center">
            <span><i class="bi bi-trophy me-2"></i>Rencontres R3M / R4M à venir</span>
            <span class="badge bg-dark"><?= count($rencontresR3R4) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($rencontresR3R4)): ?>
            <p class="text-muted p-3 mb-0"><i class="bi bi-info-circle me-2"></i>Aucune rencontre R3M/R4M à venir.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Heure</th>
                            <th>Journée</th>
                            <th>Division</th>
                            <th>Domicile</th>
                            <th>Extérieur</th>
                            <th>Salle</th>
                            <th>Lieu</th>
                            <th>JA désigné</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rencontresR3R4 as $rc):
                            $dateFr = $rc['DateRencontre']
                                ? (new DateTime($rc['DateRencontre']))->format('d/m/Y')
                                : '—';
                            $heure  = $rc['HeureRencontre']
                                ? substr($rc['HeureRencontre'], 0, 5)
                                : '—';
                            $color  = $rc['DivisionColor'] ?: '#6c757d';
                            // Contraste automatique selon luminosité du fond
                            $hex = ltrim($color, '#');
                            if (strlen($hex) === 6) {
                                $lum = (0.299 * hexdec(substr($hex,0,2)) + 0.587 * hexdec(substr($hex,2,2)) + 0.114 * hexdec(substr($hex,4,2))) / 255;
                                $textColor = $lum > 0.55 ? '#111' : '#fff';
                            } else {
                                $textColor = '#fff';
                            }
                            $isMe      = ($rc['IdJaAffecte'] === $idJa);
                            $diffJours = $rc['DateRencontre']
                                ? (new DateTime('today'))->diff(new DateTime($rc['DateRencontre']))->days
                                : 99;
                            $lointain  = $diffJours > 5;
                        ?>
                        <tr<?= $isMe ? ' class="table-success fw-semibold"' : ($lointain ? ' style="opacity:.45;background:#f0f0f0"' : '') ?>>
                            <td class="fw-semibold"><?= $dateFr ?></td>
                            <td><?= $heure ?></td>
                            <td class="text-muted small">J<?= htmlspecialchars($rc['Journee'] ?? '—') ?></td>
                            <td>
                                <span class="badge" style="background:<?= $lointain ? '#adb5bd' : htmlspecialchars($color) ?>;color:<?= $lointain ? '#fff' : $textColor ?>">
                                    <?= htmlspecialchars($rc['DivisionCode'] ?? '—') ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($rc['NomDom'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($rc['NomExt'] ?? '—') ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($rc['NomSalle'] ?? '—') ?></td>
                            <td class="text-muted small"><?= htmlspecialchars(trim(($rc['CpSalle'] ?? '') . ' ' . ($rc['VilleSalle'] ?? ''))) ?></td>
                            <td>
                                <?php if ($rc['IdJaAffecte']): ?>
                                    <?php if ($isMe): ?>
                                    <span class="badge bg-success"><i class="bi bi-person-check me-1"></i><?= htmlspecialchars($rc['NomJaAffecte']) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($rc['NomJaAffecte']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($lointain): ?>
                                    <span class="badge bg-secondary"><i class="bi bi-exclamation-circle me-1"></i>Non désigné</span>
                                    <?php else: ?>
                                    <button class="btn btn-danger btn-sm py-0 px-2"
                                            onclick="seDesigner(this, <?= (int)$rc['Id_Rencontre'] ?>)"
                                            title="Me désigner pour cette rencontre">
                                        <i class="bi bi-exclamation-circle me-1"></i>Non désigné
                                    </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-end mt-3">
        <a href="../logout.php" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-box-arrow-left me-2"></i>Se déconnecter
        </a>
    </div>

</div>

<!-- ── Modale modification adresse ───────────────────────────────────────── -->
<div class="modal fade" id="modal-adresse" tabindex="-1" aria-labelledby="modal-adresse-titre" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white py-2">
        <h6 class="modal-title" id="modal-adresse-titre"><i class="bi bi-geo-alt-fill me-2"></i>Mon adresse domicile</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-2">
          <div class="col-5">
            <label class="form-label form-label-sm fw-semibold mb-1">Code postal</label>
            <input type="text" id="adr-cp" class="form-control form-control-sm" maxlength="5" placeholder="76000"
                   value="<?= htmlspecialchars($ja['CodePostal'] ?? '') ?>">
          </div>
          <div class="col-7">
            <label class="form-label form-label-sm fw-semibold mb-1">Ville</label>
            <div class="input-group input-group-sm">
              <input type="text" id="adr-ville" class="form-control" maxlength="80" placeholder="ROUEN"
                     value="<?= htmlspecialchars($ja['Ville'] ?? '') ?>">
              <button class="btn btn-outline-secondary" id="adr-btn-chercher" type="button"><i class="bi bi-search"></i></button>
            </div>
          </div>
        </div>
        <div id="adr-status" class="small mb-1"></div>
        <div id="adr-suggestions" class="d-none p-2 rounded mb-2" style="background:#fff3cd;border:1px solid #ffc107">
          <div class="small fw-bold text-warning-emphasis mb-1">Plusieurs communes — choisissez :</div>
          <div id="adr-suggestions-list"></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" id="adr-btn-valider" class="btn btn-success btn-sm" disabled>
          <i class="bi bi-floppy me-1"></i>Enregistrer
        </button>
      </div>
    </div>
  </div>
</div>

<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF = <?= json_encode(csrfToken()) ?>;

// ── Modale adresse domicile ───────────────────────────────────────────────────
let adrIdLaPoste = <?= ($ja['Id_LaPoste'] ?? 0) ? (int)$ja['Id_LaPoste'] : 'null' ?>;

function adrSetStatus(msg, ok) {
    const el = document.getElementById('adr-status');
    el.textContent = msg;
    el.className = 'small mb-1 ' + (ok === true ? 'text-success' : ok === false ? 'text-danger' : 'text-muted');
}

function adrChoisir(cp, ville, id) {
    adrIdLaPoste = id;
    document.getElementById('adr-cp').value    = cp;
    document.getElementById('adr-ville').value = ville;
    document.getElementById('adr-suggestions').classList.add('d-none');
    adrSetStatus('✓ ' + cp + ' ' + ville, true);
    document.getElementById('adr-btn-valider').disabled = false;
}

async function adrChercher() {
    const cp    = document.getElementById('adr-cp').value.trim();
    const ville = document.getElementById('adr-ville').value.trim();
    if (!cp && !ville) return;
    adrSetStatus('Recherche…', null);
    adrIdLaPoste = null;
    document.getElementById('adr-btn-valider').disabled = true;
    document.getElementById('adr-suggestions').classList.add('d-none');

    const body = new URLSearchParams({ action: 'recherche_laposte', cp, ville, _csrf: CSRF });
    try {
        const r = await fetch('info_rencontre.php', { method: 'POST', body });
        const d = await r.json();
        if (d.multi) {
            adrSetStatus('', null);
            const list = document.getElementById('adr-suggestions-list');
            list.innerHTML = '';
            d.suggestions.forEach(s => {
                const b = document.createElement('button');
                b.className = 'btn btn-sm btn-outline-primary me-1 mb-1';
                b.textContent = s.cp + ' ' + s.ville;
                b.addEventListener('click', () => adrChoisir(s.cp, s.ville, s.id_laposte));
                list.appendChild(b);
            });
            document.getElementById('adr-suggestions').classList.remove('d-none');
        } else if (d.ok) {
            adrChoisir(d.cp, d.ville, d.id_laposte);
        } else {
            adrSetStatus(d.msg || 'Commune non trouvée.', false);
        }
    } catch { adrSetStatus('Erreur réseau.', false); }
}

document.getElementById('adr-btn-chercher').addEventListener('click', adrChercher);
['adr-cp', 'adr-ville'].forEach(id => {
    document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); adrChercher(); } });
    document.getElementById(id).addEventListener('input', () => {
        adrIdLaPoste = null;
        document.getElementById('adr-status').textContent = '';
        document.getElementById('adr-btn-valider').disabled = true;
        document.getElementById('adr-suggestions').classList.add('d-none');
    });
});

document.getElementById('adr-btn-valider').addEventListener('click', async () => {
    if (!adrIdLaPoste) return;
    const btn = document.getElementById('adr-btn-valider');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…';
    const cp    = document.getElementById('adr-cp').value.trim();
    const ville = document.getElementById('adr-ville').value.trim();
    const body  = new URLSearchParams({ action: 'sauvegarder_adresse', id_laposte: adrIdLaPoste, cp, ville, _csrf: CSRF });
    try {
        const r = await fetch('info_rencontre.php', { method: 'POST', body });
        const d = await r.json();
        if (d.ok) {
            document.getElementById('lbl-domicile').textContent = (d.cp + ' ' + d.ville).trim();
            bootstrap.Modal.getInstance(document.getElementById('modal-adresse')).hide();
            nijacToast('Adresse mise à jour.', 'success');
        } else {
            adrSetStatus(d.msg || 'Erreur.', false);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Enregistrer';
        }
    } catch {
        adrSetStatus('Erreur réseau.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Enregistrer';
    }
});

// Réinitialiser le statut à chaque ouverture de la modale
document.getElementById('modal-adresse').addEventListener('show.bs.modal', () => {
    adrSetStatus(adrIdLaPoste ? '✓ Adresse déjà renseignée' : '', adrIdLaPoste ? true : null);
    document.getElementById('adr-btn-valider').disabled = !adrIdLaPoste;
    document.getElementById('adr-suggestions').classList.add('d-none');
    document.getElementById('adr-btn-valider').innerHTML = '<i class="bi bi-floppy me-1"></i>Enregistrer';
});

// ── Auto-désignation ──────────────────────────────────────────────────────────
const JA_NOM_COMPLET = <?= json_encode($ja['Prenom'] . ' ' . $ja['Nom']) ?>;

async function seDesigner(btn, idRencontre) {
    if (!confirm('Vous désigner comme JA pour cette rencontre et envoyer une convocation ?')) return;

    const tr = btn.closest('tr');
    const td = btn.closest('td');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

    try {
        const body = new URLSearchParams({ action: 'se_designer', id_rencontre: idRencontre, _csrf: CSRF });
        const r = await fetch('info_rencontre.php', { method: 'POST', body });
        const text = await r.text();
        let d;
        try { d = JSON.parse(text); } catch { alert('Réponse invalide : ' + text.substring(0, 200)); btn.disabled = false; btn.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Non désigné'; return; }
        if (d.ok) {
            td.innerHTML = '<span class="badge bg-success"><i class="bi bi-person-check me-1"></i>' + JA_NOM_COMPLET + '</span>';
            tr.style.cssText = '';
            tr.className = 'table-success fw-semibold';
            alert(d.msg);
        } else {
            alert(d.msg);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Non désigné';
        }
    } catch (e) {
        alert('Erreur : ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>Non désigné';
    }
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
