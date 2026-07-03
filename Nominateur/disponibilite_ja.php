<?php
/**
 * NIJAC – Déclaration de disponibilité JA (E012)
 *
 * Page publique (sans session administrateur) permettant à chaque Juge-Arbitre
 * de déclarer ses disponibilités pour les journées de la saison en cours.
 * Niveau 1 : choix Disponible / Partiel / Non disponible par journée.
 * Niveau 2 (si Partiel) : sélection des lieux de rencontres souhaités.
 * Accessible via un lien direct ou via un token obfusqué envoyé par email.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-26
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';
require __DIR__ . '/../Classes/Obfuscator.php';

// ── Décodage du paramètre obfusqué ?ja=TOKEN ─────────────────────────────────
$_obf = new Obfuscator(OBFUSCATOR_SEED);
if (isset($_GET['ja']) && $_GET['ja'] !== '') {
    $decoded = $_obf->deobfuscate(trim($_GET['ja']));
    if ($decoded > 0) {
        $_GET['id_ja'] = $decoded;
    }
}

$pdo = getPDO();

// ── Initialisation : colonnes optionnelles ────────────────────────────────────
try {
    // Colonne Note dans JA
    $jaCols = array_column($pdo->query('DESCRIBE JA')->fetchAll(), 'Field');
    if (!in_array('Note', $jaCols)) {
        $pdo->exec("ALTER TABLE JA ADD COLUMN Note TEXT NULL COMMENT 'Note à destination des nominateurs'");
    }
    // Contrainte unicité rencontre-niveau (peut déjà exister)
    try {
        $pdo->exec("ALTER TABLE disponible ADD UNIQUE KEY uq_dispo (Id_JA, Id_Rencontre)");
    } catch (PDOException $e) { /* déjà présente */ }
} catch (PDOException $e) { /* ignore */ }

// ── ACTIONS AJAX ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start(); ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {

    // ── Liste des JA actifs ───────────────────────────────────────────────
    if ($action === 'liste_ja') {
        $rows = $pdo->query(
            'SELECT Id_JA, Nom, Prenom, Grade, Ville FROM ja WHERE Actif = 1 ORDER BY Nom, Prenom'
        )->fetchAll();
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── Infos JA ─────────────────────────────────────────────────────────
    if ($action === 'ja') {
        $id = (int)($_GET['id'] ?? 0);
        $cpExpr    = 'lp.CodePostal';
        $villeExpr = 'lp.Nom';
        $hasDefisc = in_array('Defiscalisation', $jaCols);
        $stmt = $pdo->prepare("
            SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                   $cpExpr    AS Cp,
                   $villeExpr AS Ville" .
                   ($hasDefisc ? ", ja.Defiscalisation" : ", 0 AS Defiscalisation") . "
            FROM ja
            LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Id_JA = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'err' => 'Introuvable']);
        exit;
    }

    // ── Journées (niveau 1 du calendrier) ───────────────────────────────
    // Chaque (Journee, Date) donne un cartouche indépendant (ex: sam. + dim.)
    // Statut lu dans disponible : DateCompetition = Date exacte, Id_Rencontre IS NULL
    if ($action === 'journees') {
        $idJa   = (int)($_GET['id_ja']  ?? 0);
        if (!$idJa)  { echo json_encode(['ok' => false, 'err' => 'JA manquant']); exit; }

        // Formule Haversine (km) entre le JA et la salle principale du club domicile
        $haversine = "
            ROUND(6371 * ACOS(GREATEST(-1, LEAST(1,
                COS(RADIANS(lp_ja.Latitude))  * COS(RADIANS(lp_v.Latitude))
              * COS(RADIANS(lp_v.Longitude)   - RADIANS(lp_ja.Longitude))
              + SIN(RADIANS(lp_ja.Latitude))  * SIN(RADIANS(lp_v.Latitude))
            ))))
        ";

        $stmt = $pdo->prepare("
            SELECT
                j.Journee,
                j.Date,
                j.NbRencontres,
                CASE
                    WHEN dj.Reponse IS NOT NULL THEN dj.Reponse
                    WHEN COALESCE(sel.Nb, 0) > 0 THEN 'P'
                    ELSE NULL
                END                 AS Statut,
                COALESCE(sel.Nb, 0) AS NbSelectionnes,
                dist.MinKm,
                dist.MaxKm
            FROM (
                -- Un cartouche par (Journée × Date), hors club du JA
                SELECT r.Journee, r.Date, COUNT(*) AS NbRencontres
                FROM rencontre r
                JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                WHERE NOT EXISTS (
                      SELECT 1 FROM ja ja0
                      WHERE ja0.Id_JA = ? AND ja0.Id_Club IS NOT NULL AND ja0.Id_Club = ed.Id_Club
                  )
                GROUP BY r.Journee, r.Date
            ) j
            LEFT JOIN disponible dj
                ON  dj.Id_JA           = ?
                AND dj.DateCompetition = j.Date
                AND dj.Id_Rencontre    IS NULL
            LEFT JOIN (
                SELECT r2.Journee, r2.Date, COUNT(*) AS Nb
                FROM rencontre r2
                JOIN disponible d2
                    ON  d2.Id_Rencontre = r2.Id_Rencontre
                    AND d2.Id_JA        = ?
                GROUP BY r2.Journee, r2.Date
            ) sel ON sel.Journee = j.Journee AND sel.Date = j.Date
            LEFT JOIN (
                SELECT r3.Journee, r3.Date,
                       MIN($haversine) AS MinKm,
                       MAX($haversine) AS MaxKm
                FROM rencontre r3
                JOIN  equipe  ed3 ON ed3.Id_Equipe  = r3.Id_EquipeDom
                LEFT JOIN salle   s3  ON  s3.Id_Club    = ed3.Id_Club AND s3.EstPrincipale = 1
                LEFT JOIN laposte lp_v ON lp_v.Id_LaPoste = s3.Id_Laposte
                CROSS JOIN (
                    SELECT lp2.Latitude, lp2.Longitude, ja.Id_Club AS JaClub
                    FROM ja LEFT JOIN laposte lp2 ON lp2.Id_LaPoste = ja.Id_LaPoste
                    WHERE ja.Id_JA = ?
                ) lp_ja
                WHERE lp_v.Latitude  IS NOT NULL
                  AND lp_ja.Latitude IS NOT NULL
                  AND (lp_ja.JaClub IS NULL OR ed3.Id_Club != lp_ja.JaClub)
                GROUP BY r3.Journee, r3.Date
            ) dist ON dist.Journee = j.Journee AND dist.Date = j.Date
            ORDER BY j.Journee, j.Date
        ");
        $stmt->execute([$idJa, $idJa, $idJa, $idJa]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ── Rencontres d'une journée (niveau 2 — Partiel) ────────────────────
    if ($action === 'rencontres_journee') {
        $idJa   = (int)($_GET['id_ja']  ?? 0);
        $journee = (int)($_GET['journee'] ?? 0);
        $date    = trim($_GET['date'] ?? '');
        if (!$idJa || !$journee || !$date) {
            echo json_encode(['ok' => false, 'err' => 'Paramètres manquants']);
            exit;
        }

        // Déterminer le(s) département(s) du JA pour filtrer les rencontres (mode Partiel)
        $jaRow    = $pdo->prepare("SELECT LEFT(lp2.CodePostal, 2) AS Dept FROM ja LEFT JOIN laposte lp2 ON lp2.Id_LaPoste = ja.Id_LaPoste WHERE ja.Id_JA = ?");
        $jaRow->execute([$idJa]);
        $jaDept   = $jaRow->fetchColumn() ?: '';

        // Départements à inclure : si 76, ajouter ceux définis dans la config
        $depts = $jaDept ? [$jaDept] : [];
        if ($jaDept === '76') {
            $extra = trim(getConfig('dept_76_includes', '27'));
            foreach (array_filter(array_map('trim', explode(',', $extra))) as $d) {
                if (!in_array($d, $depts)) $depts[] = $d;
            }
        }

        // Clause de filtre sur le CP de la salle (optionnel si aucun dept trouvé)
        $deptClause = '';
        if ($depts) {
            $ph = implode(',', array_fill(0, count($depts), '?'));
            $deptClause = "AND LEFT(COALESCE(lp_r.CodePostal, lp_c.CodePostal), 2) IN ($ph)";
        }

        $params = [$idJa, $idJa, $journee, $date];
        if ($depts) $params = array_merge($params, $depts);

        $stmt = $pdo->prepare("
            SELECT
                r.Id_Rencontre,
                r.Date,
                r.Heure,
                r.Poule,
                d.Division   AS DivisionCode,
                d.Nom        AS DivisionNom,
                ed.Nom       AS NomDom,
                ee.Nom       AS NomExt,
                -- Salle : priorité rencontre.id_Salle, sinon salle principale du club
                COALESCE(s_r.Nom,  s_c.Nom)        AS NomSalle,
                COALESCE(lp_r.Nom, lp_c.Nom)       AS VilleSalle,
                COALESCE(lp_r.CodePostal, lp_c.CodePostal) AS CpSalle,
                -- Distance Haversine JA ↔ lieu (km)
                CASE
                    WHEN lp_ja.Latitude IS NOT NULL
                     AND COALESCE(lp_r.Latitude, lp_c.Latitude) IS NOT NULL
                    THEN ROUND(6371 * ACOS(GREATEST(-1, LEAST(1,
                            COS(RADIANS(lp_ja.Latitude))
                          * COS(RADIANS(COALESCE(lp_r.Latitude,  lp_c.Latitude)))
                          * COS(RADIANS(COALESCE(lp_r.Longitude, lp_c.Longitude))
                                - RADIANS(lp_ja.Longitude))
                          + SIN(RADIANS(lp_ja.Latitude))
                          * SIN(RADIANS(COALESCE(lp_r.Latitude,  lp_c.Latitude)))
                        ))))
                    ELSE NULL
                END AS DistanceKm,
                disp.Reponse AS ReponseDisp
            FROM rencontre r
            JOIN  division d    ON d.Id_Division   = r.Id_Division
            JOIN  equipe   ed   ON ed.Id_Equipe    = r.Id_EquipeDom
            LEFT JOIN equipe ee ON ee.Id_Equipe    = r.Id_EquipeExt
            -- Salle de la rencontre (si renseignée)
            LEFT JOIN salle   s_r  ON s_r.Id_Salle  = r.id_Salle
            LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
            -- Salle principale du club domicile (fallback)
            LEFT JOIN salle   s_c  ON s_c.Id_Club   = ed.Id_Club AND s_c.EstPrincipale = 1
            LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
            -- GPS du JA (et Id_Club pour filtre)
            CROSS JOIN (
                SELECT lp2.Latitude, lp2.Longitude, ja.Id_Club AS JaClub
                FROM ja
                LEFT JOIN laposte lp2 ON lp2.Id_LaPoste = ja.Id_LaPoste
                WHERE ja.Id_JA = ?
            ) lp_ja
            LEFT JOIN disponible disp
                ON disp.Id_Rencontre = r.Id_Rencontre AND disp.Id_JA = ?
            WHERE r.Journee = ? AND r.Date = ?
              -- Exclure les rencontres dont l'équipe domicile appartient au club du JA
              AND (lp_ja.JaClub IS NULL OR ed.Id_Club != lp_ja.JaClub)
              -- Filtrer sur le département du JA
              $deptClause
            ORDER BY DistanceKm IS NULL, DistanceKm ASC, r.Heure, d.Ord
        ");
        $stmt->execute($params);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ── Sauvegarder disponibilité d'une journée ───────────────────────────
    // POST : id_ja, saison, journee, date (YYYY-MM-DD), statut (O/P/N), rencontres[] (pour Partiel)
    // Stockage dans disponible :
    //   Niveau journée  : DateCompetition = date exacte du cartouche, Id_Rencontre = NULL, Reponse = O/P/N
    //   Niveau rencontre: DateCompetition = Date match,               Id_Rencontre = id,   Reponse = O
    if ($action === 'sauvegarder_dispo_journee') {
        $idJa        = (int)($_POST['id_ja']    ?? 0);
        $journee     = (int)($_POST['journee']  ?? 0);
        $dateJournee = trim($_POST['date']      ?? '');
        $statut      = strtoupper(trim($_POST['statut'] ?? ''));
        if (!$idJa || !$journee || !$dateJournee || !in_array($statut, ['O','P','N'])) {
            echo json_encode(['ok' => false, 'err' => 'Paramètres invalides']);
            exit;
        }
        // Validation basique du format de date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateJournee)) {
            echo json_encode(['ok' => false, 'err' => 'Date invalide']); exit;
        }

        // Rencontres de ce cartouche (journée + date exacte)
        $stmtIds = $pdo->prepare("SELECT Id_Rencontre, Date FROM rencontre WHERE Journee = ? AND Date = ?");
        $stmtIds->execute([$journee, $dateJournee]);
        $tousRencontres = $stmtIds->fetchAll();
        $tousIds = array_column($tousRencontres, 'Id_Rencontre');

        if ($statut === 'O' || $statut === 'N') {
            // Un seul enregistrement journée-niveau, aucun enregistrement rencontre
            $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND DateCompetition = ? AND Id_Rencontre IS NULL")
                ->execute([$idJa, $dateJournee]);
            $pdo->prepare("INSERT INTO disponible (Id_JA, DateCompetition, Reponse, DateReponse) VALUES (?, ?, ?, CURDATE())")
                ->execute([$idJa, $dateJournee, $statut]);
            // Supprimer les éventuels enregistrements rencontre-niveau
            if ($tousIds) {
                $in = implode(',', array_fill(0, count($tousIds), '?'));
                $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND Id_Rencontre IN ($in)")
                    ->execute(array_merge([$idJa], $tousIds));
            }

        } elseif ($statut === 'P') {
            // Pas d'enregistrement journée-niveau, uniquement les rencontres sélectionnées
            $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND DateCompetition = ? AND Id_Rencontre IS NULL")
                ->execute([$idJa, $dateJournee]);
            $selIds = array_map('intval', (array)($_POST['rencontres'] ?? []));
            $mapDates = array_column($tousRencontres, 'Date', 'Id_Rencontre');
            $stmtUps = $pdo->prepare("
                INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse)
                VALUES (?, ?, ?, 'O', CURDATE())
                ON DUPLICATE KEY UPDATE Reponse='O', DateCompetition=VALUES(DateCompetition), DateReponse=CURDATE()
            ");
            $stmtDel = $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?");
            foreach ($tousIds as $idR) {
                if (in_array($idR, $selIds)) {
                    $stmtUps->execute([$idJa, $idR, $mapDates[$idR] ?? $dateJournee]);
                } else {
                    $stmtDel->execute([$idJa, $idR]);
                }
            }
        }

        echo json_encode(['ok' => true, 'statut' => $statut]);
        exit;
    }

    // ── Générer le token obfusqué pour un JA ─────────────────────────────
    if ($action === 'token') {
        $id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'err' => 'ID manquant']); exit; }
        $token = $_obf->obfuscate($id);
        $base  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
               . '://' . $_SERVER['HTTP_HOST']
               . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/disponibilite_ja.php';
        echo json_encode(['ok' => true, 'token' => $token, 'url' => "$base?ja=$token"]);
        exit;
    }

    // ── Lire la note du JA ────────────────────────────────────────────────
    if ($action === 'lire_note') {
        $id = (int)($_GET['id_ja'] ?? $_POST['id_ja'] ?? 0);
        if (!$id) { echo json_encode(['ok' => false, 'err' => 'ID manquant']); exit; }
        $stmt = $pdo->prepare("SELECT Note FROM JA WHERE Id_JA = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        echo json_encode(['ok' => true, 'note' => $row ? ($row['Note'] ?? '') : '']);
        exit;
    }

    // ── Sauvegarder la note du JA ─────────────────────────────────────────
    if ($action === 'sauvegarder_note') {
        $id   = (int)($_POST['id_ja'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if (!$id) { echo json_encode(['ok' => false, 'err' => 'ID manquant']); exit; }
        $pdo->prepare("UPDATE JA SET Note = ? WHERE Id_JA = ?")
            ->execute([$note === '' ? null : $note, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Sauvegarder la défiscalisation du JA ──────────────────────────────
    if ($action === 'sauvegarder_defiscalisation') {
        $id    = (int)($_POST['id_ja'] ?? 0);
        $defisc = !empty($_POST['defiscalisation']) ? 1 : 0;
        if (!$id) { echo json_encode(['ok' => false, 'err' => 'ID manquant']); exit; }
        $pdo->prepare("UPDATE JA SET Defiscalisation = ? WHERE Id_JA = ?")
            ->execute([$defisc, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'err' => 'Action inconnue']);

    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'err' => 'BDD : ' . $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title>NIJAC – Disponibilités JA (E012)</title>
    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
    <style>
        :root {
            --nijac-blue:  #1a3a6b;
            --col-dispo:   #2e7d32;
            --col-partiel: #e65100;
            --col-nodispo: #c62828;
        }

        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }

        /* ── En-tête ─────────────────────────────────────────────────────── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .6rem 1.25rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .55rem;
            position: sticky;
            top: 0;
            z-index: 200;
        }
        #page-header h1 { font-size: 1rem; font-weight: 700; margin: 0; }

        /* ── Identité JA inline dans l'en-tête ──────────────────────────── */
        .ja-header-sep { opacity: .4; font-weight: 300; font-size: 1.1rem; }
        #ja-info-bar .ja-ib-nom {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
        }
        #ja-info-bar .ja-ib-loc {
            font-size: .82rem;
            color: rgba(255,255,255,.8);
            display: flex;
            align-items: center;
            gap: .3rem;
        }
        #ja-info-bar .ja-ib-grade {
            background: rgba(255,255,255,.22);
            color: #fff;
            border-radius: 10px;
            padding: .1rem .5rem;
            font-size: .75rem;
            font-weight: 600;
        }

        /* ── Barre saison ────────────────────────────────────────────────── */
        #barre-saison {
            background: #fff;
            border-bottom: 1px solid #d0d8e8;
            padding: .5rem 1.25rem;
            display: none;
            align-items: center;
            gap: 1rem;
        }

        /* ── Corps calendrier ────────────────────────────────────────────── */
        #section-calendrier {
            max-width: 860px;
            margin: 0 auto;
            padding: .75rem 1rem 5rem;
            display: none;
        }

        /* ── Carte journée ───────────────────────────────────────────────── */
        .journee-card {
            background: #fff;
            border: 1px solid #d0d8e8;
            border-radius: 10px;
            margin-bottom: .9rem;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
            transition: box-shadow .15s;
        }
        .journee-card:hover { box-shadow: 0 3px 10px rgba(0,0,0,.12); }

        /* Bande de statut (bordure gauche colorée selon statut) */
        .journee-card.statut-O { border-left: 5px solid var(--col-dispo);   }
        .journee-card.statut-P { border-left: 5px solid var(--col-partiel); }
        .journee-card.statut-N { border-left: 5px solid var(--col-nodispo); }

        .journee-body {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: .7rem 1rem;
            flex-wrap: wrap;
        }

        /* Infos journée */
        .j-info { flex: 1; min-width: 200px; }
        .j-num  { font-size: .97rem; font-weight: 800; color: var(--nijac-blue); line-height: 1.4; }

        /* Boutons de statut */
        .j-btns { display: flex; gap: .45rem; flex-wrap: wrap; }

        .btn-statut {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .38rem .85rem;
            border-radius: 20px;
            border: 2px solid currentColor;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            background: #fff;
            transition: background .15s, color .15s, transform .08s;
            white-space: nowrap;
        }
        .btn-statut:active { transform: scale(.96); }

        /* Disponible */
        .btn-statut.dispo  { color: var(--col-dispo);   }
        .btn-statut.dispo.actif  {
            background: var(--col-dispo);
            color: #fff;
        }
        /* Partiel */
        .btn-statut.partiel { color: var(--col-partiel); }
        .btn-statut.partiel.actif {
            background: var(--col-partiel);
            color: #fff;
        }
        /* Non dispo */
        .btn-statut.nodispo { color: var(--col-nodispo); }
        .btn-statut.nodispo.actif {
            background: var(--col-nodispo);
            color: #fff;
        }

        /* Badge compteur sélection */
        .j-selec-badge {
            font-size: .75rem;
            background: #e8f5e9;
            color: var(--col-dispo);
            border-radius: 10px;
            padding: .1rem .55rem;
            display: none;
        }
        .statut-P .j-selec-badge { display: inline; }

        /* ── Panneau Partiel (rencontres) ────────────────────────────────── */
        .panel-partiel {
            border-top: 1px solid #e0e8f0;
            background: #fafbfd;
            display: none;
        }
        .panel-partiel.ouvert { display: block; }

        .panel-partiel-titre {
            padding: .45rem 1rem;
            font-size: .8rem;
            font-weight: 700;
            color: var(--col-partiel);
            border-bottom: 1px solid #eef0f4;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .panel-partiel-titre .sel-tout-btn {
            margin-left: auto;
            font-size: .75rem;
            font-weight: 600;
            color: var(--nijac-blue);
            cursor: pointer;
            text-decoration: underline dotted;
            background: none;
            border: none;
            padding: 0;
        }

        /* Ligne de rencontre dans le panneau Partiel */
        .renc-row {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .55rem 1rem;
            border-bottom: 1px solid #eef0f4;
            cursor: pointer;
            transition: background .1s;
        }
        .renc-row:last-child { border-bottom: none; }
        .renc-row:hover { background: #f0f4fa; }
        .renc-row.selectionne { background: #e8f5e9; }
        .renc-row.selectionne .renc-check { color: var(--col-dispo); }

        /* Case à cocher stylisée */
        .renc-check {
            width: 22px;
            height: 22px;
            border: 2px solid #adb5bd;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: transparent;
            font-size: 1rem;
            transition: border-color .1s, color .1s, background .1s;
        }
        .renc-row.selectionne .renc-check {
            background: var(--col-dispo);
            border-color: var(--col-dispo);
            color: #fff;
        }

        /* Infos rencontre */
        .renc-info { flex: 1; min-width: 0; }
        .renc-heure  { font-size: .8rem; color: #555; font-weight: 600; width: 42px; flex-shrink: 0; }
        .renc-div    { font-size: .75rem; background: var(--nijac-blue); color: #fff;
                       border-radius: 8px; padding: .1rem .45rem; white-space: nowrap; flex-shrink: 0; }
        .renc-match  { font-size: .87rem; font-weight: 600; }
        .renc-lieu   { font-size: .77rem; color: #666; margin-top: .1rem; }
        .renc-lieu i { color: #e65100; }

        /* Badge de distance JA ↔ lieu */
        .dist-badge {
            font-size: .75rem;
            font-weight: 700;
            border-radius: 10px;
            padding: .1rem .55rem;
            flex-shrink: 0;
            white-space: nowrap;
        }
        .dist-ok   { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; } /* ≤ 40 km  */
        .dist-mid  { background: #fff8e1; color: #e65100; border: 1px solid #ffcc80; } /* 40-80 km */
        .dist-far  { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; } /* > 80 km  */
        .dist-none { background: #f5f5f5; color: #9e9e9e; border: 1px solid #e0e0e0; } /* inconnu  */

        /* Badge de distance dans la carte journée */
        .j-dist {
            font-size: .78rem;
            color: #555;
            margin-top: .2rem;
        }
        .j-dist .dist-badge { font-size: .72rem; }

        /* ── Barre sticky de sauvegarde ──────────────────────────────────── */
        #barre-save {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-top: 2px solid #c8d4e8;
            padding: .5rem 1.25rem;
            display: none;
            align-items: center;
            gap: 1rem;
            z-index: 150;
        }
        #lbl-recap-save { font-size: .83rem; color: #555; flex: 1; }

        /* ── Spinner inline ──────────────────────────────────────────────── */
        .spin-sm {
            display: inline-block;
            width: 1rem; height: 1rem;
            border: 2px solid #dee2e6;
            border-top-color: var(--nijac-blue);
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Vue calendrier ──────────────────────────────────────────────── */
        #section-cal-grille {
            max-width: 960px; margin: 0 auto;
            padding: .75rem 1rem 1rem;
            display: none;
        }
        .cal-legende {
            display: flex; gap: 1.2rem; flex-wrap: wrap;
            align-items: center; margin-bottom: .75rem;
            font-size: .78rem; color: #555;
        }
        .cal-legende-item { display: flex; align-items: center; gap: .35rem; }
        .cal-dot {
            width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0;
        }
        .dot-O { background: var(--col-dispo); }
        .dot-P { background: var(--col-partiel); }
        .dot-N { background: var(--col-nodispo); }
        .dot-vide { background: #cbd5e1; border: 1px solid #94a3b8; }

        .cal-mois-grille {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1rem;
        }
        .cal-mois {
            background: #fff; border: 1px solid #d0d8e8;
            border-radius: 10px; overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
        }
        .cal-mois-titre {
            background: var(--nijac-blue); color: #fff;
            text-align: center; font-size: .85rem; font-weight: 700;
            padding: .4rem;
        }
        .cal-semaine-header {
            display: grid; grid-template-columns: repeat(7, 1fr);
            text-align: center; font-size: .7rem; font-weight: 700;
            color: #888; padding: .3rem 0 .1rem;
        }
        .cal-semaine-header span:last-child { color: #c62828; } /* Dim */
        .cal-grid {
            display: grid; grid-template-columns: repeat(7, 1fr);
            padding: .2rem .25rem .4rem;
        }
        .cal-jour {
            aspect-ratio: 1; display: flex; align-items: center;
            justify-content: center; border-radius: 50%;
            font-size: .78rem; margin: 1px;
            cursor: default; user-select: none;
        }
        .cal-jour.vide { color: transparent; }
        .cal-jour.autre-mois { color: #ccc; }
        .cal-jour.jour-journee {
            font-weight: 700; cursor: pointer;
            transition: filter .12s, transform .1s;
        }
        .cal-jour.jour-journee:hover { filter: brightness(1.12); transform: scale(1.1); }
        .cal-jour.statut-O { background: var(--col-dispo);   color: #fff; }
        .cal-jour.statut-P { background: var(--col-partiel); color: #fff; }
        .cal-jour.statut-N { background: var(--col-nodispo); color: #fff; }
        .cal-jour.statut-vide { background: #e2e8f0; color: #475569; }
        .cal-jour.today { outline: 2px solid var(--nijac-blue); outline-offset: 1px; }
    </style>
</head>
<body>

<!-- ── En-tête unifié ─────────────────────────────────────────────────────── -->
<div id="page-header">
    <i class="bi bi-calendar2-check fs-5 flex-shrink-0"></i>
    <h1>Disponibilités JA <small class="opacity-75" style="font-size:.75rem;">(E012)</small></h1>
    <!-- Séparateur + identité du JA sur la même ligne -->
    <span id="ja-info-bar" style="display:none;align-items:center;gap:.65rem;flex-wrap:wrap">
        <span class="ja-header-sep">|</span>
        <i class="bi bi-person-badge flex-shrink-0" style="opacity:.7"></i>
        <span class="ja-ib-nom" id="ja-ib-nom"></span>
        <span class="ja-ib-grade" id="ja-ib-grade" style="display:none"></span>
        <span class="ja-ib-loc" id="ja-ib-loc" style="display:none">
            <i class="bi bi-geo-alt-fill" style="color:rgba(255,255,255,.6)"></i>
            <span id="ja-ib-cp"></span> <span id="ja-ib-ville"></span>
        </span>
    </span>
    <!-- Case défiscalisation (visible quand JA chargé) -->
    <label id="lbl-defisc" class="d-none ms-auto align-items-center gap-2"
           style="font-size:.84rem;font-weight:700;color:#fff;cursor:pointer;user-select:none;">
        <input type="checkbox" id="chk-defisc" style="width:1.1rem;height:1.1rem;cursor:pointer;accent-color:#2e7d32;">
        Défiscalisation
    </label>
    <!-- Bouton plaquette PDF -->
    <a href="../JA/Plaquette_Defiscalisation.pdf" target="_blank"
       class="btn btn-sm ms-1"
       style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;font-size:.84rem;font-weight:700;"
       title="Ouvrir la plaquette de défiscalisation (PDF)">
        <i class="bi bi-file-earmark-pdf-fill me-1"></i>Défiscalisation
    </a>
    <!-- Bouton Note (visible seulement quand un JA est chargé) -->
    <button id="btn-note" class="btn btn-sm ms-1 d-none"
            style="background:#f0c040;color:#1a3a6b;border:none;font-size:.84rem;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,.3)"
            data-bs-toggle="modal" data-bs-target="#modal-note">
        <i class="bi bi-sticky-fill me-1"></i>Note
    </button>
</div>

<?php
// Variables requises par toolbar.php — page publique, session optionnelle
$u           = $_SESSION['utilisateur'] ?? [];
$nomComplet  = isset($u['prenom'], $u['nom']) ? $u['prenom'] . ' ' . $u['nom'] : '';
$departement = $u['id_departement'] ?? '';
$changeLogin = !empty($u['change_login']);
$isAdmin     = !empty($u['is_admin']);
require __DIR__ . '/includes/toolbar.php'; ?>

<!-- ── Modale Note ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="modal-note" tabindex="-1" aria-labelledby="modal-note-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--nijac-blue);color:#fff">
                <h5 class="modal-title" id="modal-note-label">
                    <i class="bi bi-sticky me-2"></i>Note à destination des nominateurs
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.83rem">
                    Cette note est visible par les nominateurs lors de la désignation des juges-arbitres.
                </p>
                <textarea id="note-texte" class="form-control" rows="6"
                          placeholder="Saisissez ici vos informations (contraintes ponctuelles, préférences, indisponibilités particulières…)"></textarea>
            </div>
            <div class="modal-footer">
                <span id="note-spin" class="spin-sm d-none me-auto"></span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="btn-save-note" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-floppy me-1"></i>Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modale Journée ──────────────────────────────────────────────────────── -->
<div class="modal fade" id="modal-journee" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--nijac-blue);color:#fff;padding:.6rem 1rem">
                <h5 class="modal-title" id="mj-titre" style="font-size:.95rem;font-weight:700">
                    <i class="bi bi-calendar-week me-2"></i>Journée
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Boutons de statut -->
                <div class="d-flex gap-2 flex-wrap p-3 border-bottom" id="mj-btns">
                    <button class="btn-statut dispo"   data-statut="O" id="mj-btn-O">
                        <i class="bi bi-check-circle"></i>Disponible
                    </button>
                    <button class="btn-statut partiel" data-statut="P" id="mj-btn-P">
                        <i class="bi bi-exclamation-circle"></i>Partiel
                    </button>
                    <button class="btn-statut nodispo" data-statut="N" id="mj-btn-N">
                        <i class="bi bi-x-circle"></i>Non disponible
                    </button>
                    <span id="mj-spin" class="spin-sm ms-auto align-self-center d-none"></span>
                </div>
                <!-- Panneau Partiel -->
                <div id="mj-panel-partiel" style="display:none">
                    <div class="panel-partiel-titre">
                        <i class="bi bi-geo-alt-fill"></i>Lieux qui reçoivent — cochez les rencontres
                        <button class="sel-tout-btn" id="mj-sel-tout">Tout sélectionner</button>
                    </div>
                    <div id="mj-renc-body" style="max-height:340px;overflow-y:auto"></div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Message d'erreur si aucun paramètre JA dans l'URL -->
<div id="section-erreur" style="display:none" class="alert alert-warning m-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    Aucun identifiant JA fourni. Veuillez utiliser le lien personnalisé qui vous a été communiqué.
</div>

<!-- ── Barre spin ─────────────────────────────────────────────────────────── -->
<div id="barre-saison" style="display:none">
    <span id="barre-spin" style="display:none"><span class="spin-sm"></span></span>
</div>

<!-- ── Vue calendrier mensuelle ───────────────────────────────────────── -->
<div id="section-cal-grille">
    <!-- Barre de navigation saison -->
    <div id="nav-saison" style="display:flex;align-items:center;gap:.75rem;padding:.5rem 0 .6rem;flex-wrap:wrap;">
        <button id="btn-saison-prev" class="btn btn-sm btn-outline-secondary" style="font-size:.82rem;font-weight:600;">
            <i class="bi bi-chevron-left"></i> Saison précédente
        </button>
        <span id="lbl-saison-cal" style="font-size:.92rem;font-weight:700;color:var(--nijac-blue);flex:1;text-align:center;"></span>
        <button id="btn-saison-next" class="btn btn-sm btn-outline-secondary" style="font-size:.82rem;font-weight:600;">
            Saison suivante <i class="bi bi-chevron-right"></i>
        </button>
    </div>
    <div class="cal-legende">
        <span class="cal-legende-item"><span class="cal-dot dot-O"></span>Disponible</span>
        <span class="cal-legende-item"><span class="cal-dot dot-P"></span>Partiel</span>
        <span class="cal-legende-item"><span class="cal-dot dot-N"></span>Non disponible</span>
        <span class="cal-legende-item"><span class="cal-dot dot-vide"></span>Pas de réponse</span>
    </div>
    <div class="cal-mois-grille" id="cal-mois-grille"></div>
</div>

<!-- ── Calendrier des journées ───────────────────────────────────────────── -->
<div id="section-calendrier"></div>

<!-- ── Barre sticky de sauvegarde ────────────────────────────────────────── -->
<div id="barre-save">
    <span id="lbl-recap-save">Choisissez vos disponibilités pour chaque journée.</span>
    <button id="btn-tout-sauvegarder" class="btn btn-success btn-sm px-4">
        <i class="bi bi-floppy me-1"></i>Tout enregistrer
    </button>
    <span id="save-spinner" class="spin-sm d-none"></span>
</div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

// Saison courante lue depuis la configuration (ex : "2026/2027")
const CONFIG_SAISON = <?= json_encode(getConfig('saison') ?: '') ?>;

// Année de début de la saison config (ex : 2026 pour "2026/2027")
// Fallback : septembre courant si config vide
(function () {
    const m = CONFIG_SAISON.match(/(\d{4})/);
    window._yrDebutConfig = m ? +m[1] : (function () {
        const n = new Date(); return n.getMonth() >= 8 ? n.getFullYear() : n.getFullYear() - 1;
    })();
})();

// Offset de navigation : 0 = saison config, -1 = précédente, +1 = suivante
let saisonOffset = 0;

let idJaCourant  = null;
let nomJaCourant = '';

// Etat local : { "saison_journee": { statut:'O'|'P'|'N', rencontres:[ids selec] } }
let etatJournees = {};

// ── Utilitaires ───────────────────────────────────────────────────────────────
function toast(msg, ok = true) {
    nijacToast(msg, ok ? 'success' : 'danger');
}

const JOURS      = ['dim.','lun.','mar.','mer.','jeu.','ven.','sam.'];
const MOIS       = ['jan','fév','mar','avr','mai','juin','juil','août','sep','oct','nov','déc'];
const JOURS_LONG = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
const MOIS_LONG  = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

// ── Badge kilométrique ────────────────────────────────────────────────────────
function distBadge(km, label) {
    if (km === null || km === undefined || km === '') {
        return `<span class="dist-badge dist-none" title="Lieu inconnu"><i class="bi bi-geo-alt"></i> —</span>`;
    }
    const v   = +km;
    const cls = v <= 40 ? 'dist-ok' : (v <= 80 ? 'dist-mid' : 'dist-far');
    const txt = label !== undefined ? label : `${v} km`;
    return `<span class="dist-badge ${cls}" title="${v} km du domicile du JA"><i class="bi bi-signpost-split"></i> ${txt}</span>`;
}

function formatDateCourt(d) {
    if (!d) return '';
    const [y, m, j] = d.split('-').map(Number);
    const wd = new Date(y, m-1, j).getDay();
    return `${JOURS[wd]} ${j} ${MOIS[m-1]}. ${y}`;
}

function formatDateLong(d) {
    if (!d) return '';
    const [y, m, j] = d.split('-').map(Number);
    const wd = new Date(y, m-1, j).getDay();
    return `${JOURS_LONG[wd]} ${j} ${MOIS_LONG[m-1]}`;
}

// Clé unique par cartouche : saison + journée + date exacte
function cle(saison, journee, date) {
    return `${saison}_J${journee}_${date}`;
}


function chargerInfosJA() {
    $.getJSON('disponibilite_ja.php', { action: 'ja', id: idJaCourant }, function (r) {
        if (!r.ok) return;
        nomJaCourant = r.data.Nom + ' ' + r.data.Prenom;

        $('#ja-ib-nom').text(nomJaCourant);
        if (r.data.Grade) {
            $('#ja-ib-grade').text(r.data.Grade).show();
        } else {
            $('#ja-ib-grade').hide();
        }
        const hasloc = r.data.Cp || r.data.Ville;
        if (hasloc) {
            $('#ja-ib-cp').text(r.data.Cp || '');
            $('#ja-ib-ville').text(r.data.Ville || '');
            $('#ja-ib-loc').show();
        } else {
            $('#ja-ib-loc').hide();
        }
        $('#ja-info-bar').css('display', 'flex');
        // Case défiscalisation
        $('#chk-defisc').prop('checked', +r.data.Defiscalisation === 1);
        $('#lbl-defisc').addClass('d-flex').removeClass('d-none');
        $('#btn-note').removeClass('d-none');
    });
}

// ── Sauvegarde défiscalisation ────────────────────────────────────────────────
$('#chk-defisc').on('change', function () {
    $.post('disponibilite_ja.php', {
        action:          'sauvegarder_defiscalisation',
        id_ja:           idJaCourant,
        defiscalisation: this.checked ? 1 : 0,
    }, function (r) {
        if (!r.ok) toast('Erreur défiscalisation : ' + r.err, false);
    }, 'json');
});

// ── Modale Note ───────────────────────────────────────────────────────────────
$('#modal-note').on('show.bs.modal', function () {
    $('#note-texte').val('').prop('disabled', true);
    $('#note-spin').removeClass('d-none');
    $.getJSON('disponibilite_ja.php', { action: 'lire_note', id_ja: idJaCourant }, function (r) {
        $('#note-spin').addClass('d-none');
        $('#note-texte').val(r.ok ? (r.note || '') : '').prop('disabled', false).trigger('focus');
    });
});

$('#btn-save-note').on('click', function () {
    const note = $('#note-texte').val();
    $('#note-spin').removeClass('d-none');
    $('#btn-save-note').prop('disabled', true);
    $.post('disponibilite_ja.php', {
        action: 'sauvegarder_note',
        id_ja:  idJaCourant,
        note:   note,
    }, function (r) {
        $('#note-spin').addClass('d-none');
        $('#btn-save-note').prop('disabled', false);
        if (r.ok) {
            bootstrap.Modal.getInstance($('#modal-note')[0]).hide();
            toast('Note enregistrée.');
        } else {
            toast('Erreur : ' + r.err, false);
        }
    }, 'json').fail(function () {
        $('#note-spin').addClass('d-none');
        $('#btn-save-note').prop('disabled', false);
        toast('Erreur réseau.', false);
    });
});

// ── Chargement des journées (niveau 1) ───────────────────────────────────────
function chargerJournees() {
    if (!idJaCourant) return;
    $('#barre-save').css('display','flex');
    $('#barre-spin').show();
    $('#section-calendrier').show().html(
        '<div class="text-muted text-center py-5"><span class="spin-sm me-2"></span>Chargement des journées…</div>'
    );

    $.getJSON('disponibilite_ja.php', {
        action: 'journees',
        id_ja:  idJaCourant,
    }, function (r) {
        $('#barre-spin').hide();
        if (!r.ok) {
            $('#section-calendrier').html(`<div class="alert alert-danger m-3">${r.err}</div>`);
            return;
        }
        if (!r.data.length) {
            $('#section-calendrier').html(
                '<div class="alert alert-info m-3"><i class="bi bi-info-circle me-2"></i>Aucune rencontre pour cette saison.</div>'
            );
            return;
        }
        // Initialiser l'état depuis la BDD (un cartouche par Journee+Date)
        const saison = 'courante';
        r.data.forEach(function (j) {
            const k = cle(saison, j.Journee, j.Date);
            if (!etatJournees[k]) {
                etatJournees[k] = {
                    statut: j.Statut || null,
                    rencontres: [],  // ids sélectionnés en mode Partiel
                    modifie: false,
                    date: j.Date,
                };
            }
        });
        renderCalendrier(r.data, 'courante');
        majRecapSave();
        renderCalendrierMensuel();
        afficherVueInitiale();
    });
}

// ── Rendu du calendrier ───────────────────────────────────────────────────────
function renderCalendrier(journees, saison) {
    const $zone = $('#section-calendrier').empty();

    journees.forEach(function (j) {
        const k      = cle(saison, j.Journee, j.Date);
        const statut = etatJournees[k]?.statut || null;
        const nbSel  = etatJournees[k]?.rencontres?.length || 0;
        const nb     = +j.NbRencontres;
        const date   = j.Date; // YYYY-MM-DD

        // Titre : "Journée 1 — Samedi 20 Septembre"
        const dateTitre = formatDateLong(date);

        // Plage de distances — affichée uniquement si ≥ 20 rencontres
        let distHtml = '';
        if (nb >= 20 && j.MinKm !== null && j.MinKm !== undefined && j.MinKm !== '') {
            const dMin = +j.MinKm, dMax = +j.MaxKm;
            distHtml = `<div class="j-dist">` +
                (dMin === dMax
                    ? distBadge(dMin)
                    : `${distBadge(dMin, dMin+' km')} – ${distBadge(dMax, dMax+' km')}`) +
                `</div>`;
        }

        const $card = $(`<div class="journee-card${statut?' statut-'+statut:''}" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">`);

        $card.append(`
            <div class="journee-body">
                <div class="j-info">
                    <div class="j-num">
                        <i class="bi bi-calendar-week me-1"></i>Journée ${j.Journee} — ${dateTitre}
                        <span class="j-selec-badge ms-2">${nbSel}/${nb} lieu${nbSel>1?'x':''}</span>
                    </div>
                    ${distHtml}
                </div>
                <div class="j-btns">
                    <button class="btn-statut dispo${statut==='O'?' actif':''}"
                            data-statut="O" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">
                        <i class="bi bi-check-circle${statut==='O'?'-fill':''}"></i>Disponible
                    </button>
                    <button class="btn-statut partiel${statut==='P'?' actif':''}"
                            data-statut="P" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">
                        <i class="bi bi-exclamation-circle${statut==='P'?'-fill':''}"></i>Partiel
                    </button>
                    <button class="btn-statut nodispo${statut==='N'?' actif':''}"
                            data-statut="N" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">
                        <i class="bi bi-x-circle${statut==='N'?'-fill':''}"></i>Non disponible
                    </button>
                </div>
            </div>
            <div class="panel-partiel${statut==='P'?' ouvert':''}" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">
                <div class="panel-partiel-titre">
                    <i class="bi bi-geo-alt-fill"></i>Lieux qui reçoivent — cochez les rencontres que vous aimeriez arbitrer
                    <button class="sel-tout-btn" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">Tout sélectionner</button>
                </div>
                <div class="panel-partiel-body" data-journee="${j.Journee}" data-date="${date}" data-saison="${saison}">
                    <div class="text-muted text-center py-3"><span class="spin-sm me-2"></span>Chargement…</div>
                </div>
            </div>
        `);

        $zone.append($card);

        // Si Partiel et panel ouvert → charger les rencontres
        if (statut === 'P') {
            chargerRencontresJournee(j.Journee, date, saison);
        }
    });
}

// ── Clic sur un bouton de statut ─────────────────────────────────────────────
$(document).on('click', '.btn-statut', function () {
    const $btn    = $(this);
    const statut  = $btn.data('statut');
    const journee = +$btn.data('journee');
    const date    = $btn.data('date');
    const saison  = $btn.data('saison');
    const k       = cle(saison, journee, date);
    const $card   = $(`.journee-card[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"]`);
    const $panel  = $(`.panel-partiel[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"]`);

    const ancien = etatJournees[k]?.statut;
    if (ancien === statut) return; // déjà sélectionné

    // Mise à jour état
    if (!etatJournees[k]) etatJournees[k] = { statut: null, rencontres: [], modifie: false, date };
    etatJournees[k].statut  = statut;
    etatJournees[k].modifie = true;
    if (statut !== 'P') etatJournees[k].rencontres = [];

    // Mise à jour visuelle de la carte
    $card.removeClass('statut-O statut-P statut-N').addClass('statut-' + statut);

    // Boutons : retirer actif de tous, activer le cliqué
    $card.find('.btn-statut').each(function () {
        const s = $(this).data('statut');
        const cls = s === 'D' ? 'dispo' : (s === 'P' ? 'partiel' : 'nodispo');
        $(this).removeClass('actif')
               .find('i').removeClass('bi-check-circle-fill bi-exclamation-circle-fill bi-x-circle-fill')
                          .addClass(s==='D'?'bi-check-circle':(s==='P'?'bi-exclamation-circle':'bi-x-circle'));
    });
    $btn.addClass('actif');
    $btn.find('i')
        .removeClass('bi-check-circle bi-exclamation-circle bi-x-circle')
        .addClass(statut==='O'?'bi-check-circle-fill':(statut==='P'?'bi-exclamation-circle-fill':'bi-x-circle-fill'));

    // Panneau Partiel
    if (statut === 'P') {
        $panel.addClass('ouvert');
        chargerRencontresJournee(journee, date, saison);
    } else {
        $panel.removeClass('ouvert');
    }

    majRecapSave();
    sauvegarderJournee(journee, date, saison); // sauvegarde immédiate
});

// ── Charger les rencontres d'une journée (panneau Partiel) ───────────────────
function chargerRencontresJournee(journee, date, saison) {
    const $body = $(`.panel-partiel-body[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"]`);
    if ($body.data('loaded')) return; // déjà chargé

    $.getJSON('disponibilite_ja.php', {
        action:   'rencontres_journee',
        id_ja:    idJaCourant,
        journee:  journee,
        date:     date,
    }, function (r) {
        if (!r.ok) {
            $body.html(`<div class="alert alert-danger m-2">${r.err}</div>`);
            return;
        }
        $body.data('loaded', true);

        const k = cle(saison, journee, date);
        // Initialiser la sélection depuis BDD (rencontres déjà enregistrées avec Reponse='O')
        const selBDD = r.data
            .filter(renc => renc.ReponseDisp === 'O')
            .map(renc => +renc.Id_Rencontre);
        if (selBDD.length > 0 && (!etatJournees[k].rencontres || etatJournees[k].rencontres.length === 0)) {
            etatJournees[k].rencontres = selBDD;
        }

        renderRencontresPartiel($body, r.data, journee, date, saison);
        majBadgeJournee(journee, date, saison);
    });
}

// ── Rendu des rencontres dans le panneau Partiel ──────────────────────────────
function renderRencontresPartiel($body, rencontres, journee, date, saison) {
    $body.empty();
    const k   = cle(saison, journee, date);
    const sel = new Set(etatJournees[k]?.rencontres || []);

    rencontres.forEach(function (r) {
        const id    = +r.Id_Rencontre;
        const actif = sel.has(id);

        // Lieu : salle si disponible, sinon ville du club domicile
        let lieu = '';
        if (r.NomSalle) {
            lieu = `<i class="bi bi-geo-alt-fill" style="color:var(--col-partiel)"></i> ${r.NomSalle}`;
            if (r.VilleSalle) lieu += ` — ${r.CpSalle ? r.CpSalle + ' ' : ''}${r.VilleSalle}`;
        } else if (r.VilleSalle) {
            lieu = `<i class="bi bi-geo-alt" style="color:var(--col-partiel)"></i> ${r.CpSalle ? r.CpSalle + ' ' : ''}${r.VilleSalle}`;
        }

        $body.append(`
            <div class="renc-row${actif?' selectionne':''}" data-id="${id}" data-journee="${journee}" data-date="${date}" data-saison="${saison}">
                <div class="renc-check"><i class="bi bi-check-lg"></i></div>
                <div class="renc-heure">${(r.Heure||'').substring(0,5)}</div>
                <div class="renc-div">${r.DivisionCode}</div>
                <div class="renc-info">
                    <div class="renc-match">${r.NomDom || '—'} <span style="color:#999;font-size:.8em">vs</span> ${r.NomExt || '—'}</div>
                    ${lieu ? `<div class="renc-lieu">${lieu}</div>` : ''}
                </div>
                ${distBadge(r.DistanceKm)}
            </div>
        `);
    });
}

// ── Clic sur une rencontre (Partiel) ─────────────────────────────────────────
$(document).on('click', '.renc-row', function () {
    const $row    = $(this);
    const id      = +$row.data('id');
    const journee = +$row.data('journee');
    const date    = $row.data('date');
    const saison  = $row.data('saison');
    const k       = cle(saison, journee, date);

    $row.toggleClass('selectionne');
    const actif = $row.hasClass('selectionne');

    // Mise à jour état
    if (!etatJournees[k]) etatJournees[k] = { statut: 'P', rencontres: [], modifie: false, date };
    const idx = etatJournees[k].rencontres.indexOf(id);
    if (actif && idx === -1)  etatJournees[k].rencontres.push(id);
    if (!actif && idx !== -1) etatJournees[k].rencontres.splice(idx, 1);
    etatJournees[k].modifie = true;

    majBadgeJournee(journee, date, saison);
    majRecapSave();
    sauvegarderJournee(journee, date, saison); // sauvegarde immédiate
});

// ── Bouton "Tout sélectionner" dans panneau Partiel ───────────────────────────
$(document).on('click', '.sel-tout-btn', function (e) {
    e.stopPropagation();
    const journee = +$(this).data('journee');
    const date    = $(this).data('date');
    const saison  = $(this).data('saison');
    const k       = cle(saison, journee, date);
    const $rows   = $(`.renc-row[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"]`);

    const toutSelec = $rows.length > 0 && $rows.toArray().every(el => $(el).hasClass('selectionne'));

    if (toutSelec) {
        $rows.removeClass('selectionne');
        etatJournees[k].rencontres = [];
        $(this).text('Tout sélectionner');
    } else {
        const ids = [];
        $rows.each(function () {
            $(this).addClass('selectionne');
            ids.push(+$(this).data('id'));
        });
        etatJournees[k].rencontres = ids;
        $(this).text('Tout désélectionner');
    }
    etatJournees[k].modifie = true;
    majBadgeJournee(journee, date, saison);
    majRecapSave();
    sauvegarderJournee(journee, date, saison);
});

// ── Badge compteur de sélection ───────────────────────────────────────────────
function majBadgeJournee(journee, date, saison) {
    const k       = cle(saison, journee, date);
    const nb      = (etatJournees[k]?.rencontres || []).length;
    const $badge  = $(`.journee-card[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"] .j-selec-badge`);
    const nbRenc  = $(`.panel-partiel-body[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"] .renc-row`).length;
    $badge.text(`${nb}/${nbRenc} lieu${nb>1?'x':''}`);
    const toutSelec = nb > 0 && nb === nbRenc;
    $(`.sel-tout-btn[data-journee="${journee}"][data-date="${date}"][data-saison="${saison}"]`)
        .text(toutSelec ? 'Tout désélectionner' : 'Tout sélectionner');
}

// ── Récap dans la barre de save ───────────────────────────────────────────────
function majRecapSave() {
    let nbOui = 0, nbPartiel = 0, nbNon = 0;
    Object.values(etatJournees).forEach(function (e) {
        if (e.statut === 'O') nbOui++;
        else if (e.statut === 'P') nbPartiel++;
        else if (e.statut === 'N') nbNon++;
    });
    const total = nbOui + nbPartiel + nbNon;
    const parts = [];
    if (nbOui)   parts.push(`<span style="color:var(--col-dispo);font-weight:700">${nbOui} disponible${nbOui>1?'s':''}</span>`);
    if (nbPartiel) parts.push(`<span style="color:var(--col-partiel);font-weight:700">${nbPartiel} partielle${nbPartiel>1?'s':''}</span>`);
    if (nbNon)     parts.push(`<span style="color:var(--col-nodispo);font-weight:700">${nbNon} non disponible${nbNon>1?'s':''}</span>`);
    const txt = parts.length
        ? parts.join(', ') + ` sur ${Object.keys(etatJournees).length} journée${Object.keys(etatJournees).length>1?'s':''}`
        : 'Aucun choix enregistré — les journées non sélectionnées restent sans réponse.';
    $('#lbl-recap-save').html(txt);
}

// ── Sauvegarde immédiate d'une journée ────────────────────────────────────────
function sauvegarderJournee(journee, date, saison) {
    const k = cle(saison, journee, date);
    const e = etatJournees[k];
    if (!e || !e.statut) return;

    $.post('disponibilite_ja.php', {
        action:     'sauvegarder_dispo_journee',
        id_ja:      idJaCourant,
        journee:    journee,
        date:       date,
        statut:     e.statut,
        rencontres: e.rencontres,
    }, function (r) {
        if (!r.ok) toast('Erreur : ' + r.err, false);
        else rafraichirCalSiActif();
    }, 'json');
}

// ── Bouton "Tout enregistrer" (confirmation globale) ─────────────────────────
$('#btn-tout-sauvegarder').on('click', function () {
    const nb = Object.values(etatJournees).filter(e => e.statut).length;
    if (!nb) { toast('Aucune disponibilité à enregistrer.', false); return; }
    toast(`✓ ${nb} journée${nb>1?'s':''} enregistrée${nb>1?'s':''}. Merci !`);
});

// ── Vue calendrier ────────────────────────────────────────────────────────────
const MOIS_NOMS = ['Janvier','Février','Mars','Avril','Mai','Juin',
                   'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
const JOURS_COURTS = ['L','M','M','J','V','S','D'];

// jourDetailMap : "YYYY-MM-DD" → { journee, saison, statut }
let jourDetailMap = {};

function renderCalendrierMensuel() {
    const saison = 'courante';

    // Reconstruire jourDetailMap depuis etatJournees
    // Clé format : "saison_J<n>_YYYY-MM-DD"
    jourDetailMap = {};
    Object.entries(etatJournees).forEach(([k, e]) => {
        const m = k.match(/^(.+)_J(\d+)_(\d{4}-\d{2}-\d{2})$/);
        if (!m) return;
        const [, s, jn, date] = m;
        if (s !== saison) return;
        jourDetailMap[date] = { journee: +jn, saison: s, statut: e.statut || 'vide' };
    });

    const today = new Date();
    today.setHours(0,0,0,0);

    // Année de début de la saison affichée (config + offset de navigation)
    const yrDebut = window._yrDebutConfig + saisonOffset;

    // Libellé de la saison dans la barre de navigation
    $('#lbl-saison-cal').text(`Saison ${yrDebut} / ${yrDebut + 1}`);

    // Plage fixe : septembre → juin de la saison
    const moisSaison = [
        [yrDebut,     8],  // Septembre
        [yrDebut,     9],  // Octobre
        [yrDebut,    10],  // Novembre
        [yrDebut,    11],  // Décembre
        [yrDebut + 1, 0],  // Janvier
        [yrDebut + 1, 1],  // Février
        [yrDebut + 1, 2],  // Mars
        [yrDebut + 1, 3],  // Avril
        [yrDebut + 1, 4],  // Mai
        [yrDebut + 1, 5],  // Juin
    ];

    const $grille = $('#cal-mois-grille').empty();
    moisSaison.forEach(([annee, mois]) => {
        $grille.append(buildMonthGrid(annee, mois, today));
    });
}

// ── Navigation saison ─────────────────────────────────────────────────────────
$('#btn-saison-prev').on('click', function () {
    saisonOffset--;
    renderCalendrierMensuel();
});
$('#btn-saison-next').on('click', function () {
    saisonOffset++;
    renderCalendrierMensuel();
});

function buildMonthGrid(annee, mois, today) {
    const $wrap = $('<div class="cal-mois">');
    $wrap.append(`<div class="cal-mois-titre">${MOIS_NOMS[mois]} ${annee}</div>`);

    const $hdr = $('<div class="cal-semaine-header">');
    JOURS_COURTS.forEach(j => $hdr.append(`<span>${j}</span>`));
    $wrap.append($hdr);

    const $grid = $('<div class="cal-grid">');

    // 1er du mois → décalage Lundi=0 … Dimanche=6
    const premier = (new Date(annee, mois, 1).getDay() + 6) % 7;
    for (let i = 0; i < premier; i++) {
        $grid.append('<div class="cal-jour vide"></div>');
    }

    const nbJours = new Date(annee, mois + 1, 0).getDate();
    for (let j = 1; j <= nbJours; j++) {
        const dateStr = `${annee}-${String(mois+1).padStart(2,'0')}-${String(j).padStart(2,'0')}`;
        const detail  = jourDetailMap[dateStr];
        const isToday = (new Date(annee, mois, j).getTime() === today.getTime());

        let cls = 'cal-jour';
        if (detail) cls += ' jour-journee statut-' + detail.statut;
        if (isToday) cls += ' today';

        const $jour = $(`<div class="${cls}">${j}</div>`);

        if (detail) {
            $jour.attr('title', `Journée ${detail.journee} — ${formatDateLong(dateStr)}\nCliquez pour modifier`);
            $jour.on('click', () => ouvrirModaleJournee(dateStr, detail));
        }

        $grid.append($jour);
    }

    $wrap.append($grid);
    return $wrap;
}

// ── Modale journée ────────────────────────────────────────────────────────────
let modalJournee = null;

function ouvrirModaleJournee(dateStr, detail) {
    const { journee, saison, statut } = detail;
    const k = cle(saison, journee, dateStr);

    // Titre
    $('#mj-titre').html(`<i class="bi bi-calendar-week me-2"></i>Journée ${journee} — ${formatDateLong(dateStr)}`);

    // Boutons statut : rafraîchir l'état visuel
    majBtnsModale(statut === 'vide' ? null : statut);

    // Panneau partiel
    if (statut === 'P') {
        $('#mj-panel-partiel').show();
        chargerRencontresModale(journee, dateStr, saison, k);
    } else {
        $('#mj-panel-partiel').hide().data('loaded', false);
        $('#mj-renc-body').empty();
    }

    // Stocker le contexte dans la modale
    $('#modal-journee').data({ journee, date: dateStr, saison, k });

    if (!modalJournee) modalJournee = new bootstrap.Modal($('#modal-journee')[0]);
    modalJournee.show();
}

function majBtnsModale(statut) {
    ['O','P','N'].forEach(s => {
        const $b = $(`#mj-btn-${s}`);
        $b.toggleClass('actif', s === statut);
        const icons = { O: ['bi-check-circle','bi-check-circle-fill'],
                        P: ['bi-exclamation-circle','bi-exclamation-circle-fill'],
                        N: ['bi-x-circle','bi-x-circle-fill'] };
        $b.find('i').removeClass(icons[s][0] + ' ' + icons[s][1])
                    .addClass(s === statut ? icons[s][1] : icons[s][0]);
    });
}

// Clic sur un bouton statut dans la modale
$('#mj-btns').on('click', '.btn-statut', function (e) {
    e.stopPropagation();
    const $btn   = $(this);
    const statut = $btn.data('statut');
    const { journee, date, saison, k } = $('#modal-journee').data();

    if (!etatJournees[k]) etatJournees[k] = { statut: null, rencontres: [], modifie: false, date };
    const ancien = etatJournees[k].statut;
    if (ancien === statut) return;

    etatJournees[k].statut  = statut;
    etatJournees[k].modifie = true;
    if (statut !== 'P') etatJournees[k].rencontres = [];

    majBtnsModale(statut);

    if (statut === 'P') {
        $('#mj-panel-partiel').show();
        chargerRencontresModale(journee, date, saison, k);
    } else {
        $('#mj-panel-partiel').hide().data('loaded', false);
        $('#mj-renc-body').empty();
    }

    majRecapSave();
    sauvegarderJournee(journee, date, saison);

    // Fermer automatiquement la modale pour Disponible et Non disponible
    if (statut === 'O' || statut === 'N') {
        modalJournee.hide();
    }

    // Mettre à jour la cellule du calendrier
    const $cellule = $(`.cal-jour.jour-journee`).filter(function () {
        return $(this).closest('.cal-mois').length &&
               jourDetailMap[date] && true;
    });
    // Reconstruire la map et re-render pour refléter le nouveau statut
    jourDetailMap[date].statut = statut;
    // Mettre à jour la classe de la cellule directement
    $(`.cal-jour.jour-journee[title*="${formatDateLong(date)}"]`)
        .removeClass('statut-O statut-P statut-N statut-vide')
        .addClass('statut-' + statut);
});

function chargerRencontresModale(journee, date, saison, k) {
    if ($('#mj-panel-partiel').data('loaded')) return;

    $('#mj-renc-body').html('<div class="text-muted text-center py-3"><span class="spin-sm me-2"></span>Chargement…</div>');

    $.getJSON('disponibilite_ja.php', {
        action: 'rencontres_journee',
        id_ja:  idJaCourant,
        journee, date,
    }, function (r) {
        if (!r.ok) {
            $('#mj-renc-body').html(`<div class="alert alert-danger m-2">${r.err}</div>`);
            return;
        }
        $('#mj-panel-partiel').data('loaded', true);

        // Initialiser sélection depuis BDD
        const selBDD = r.data.filter(renc => renc.ReponseDisp === 'O').map(renc => +renc.Id_Rencontre);
        if (!etatJournees[k]) etatJournees[k] = { statut: 'P', rencontres: [], modifie: false, date };
        if (selBDD.length && !etatJournees[k].rencontres.length) etatJournees[k].rencontres = selBDD;

        renderRencontresModale(r.data, journee, date, saison, k);
        majBadgeModale(k);
    });
}

function renderRencontresModale(rencontres, journee, date, saison, k) {
    const $body = $('#mj-renc-body').empty();
    const sel   = new Set(etatJournees[k]?.rencontres || []);

    rencontres.forEach(function (r) {
        const id    = +r.Id_Rencontre;
        const actif = sel.has(id);

        let lieu = '';
        if (r.NomSalle) {
            lieu = `<i class="bi bi-geo-alt-fill" style="color:var(--col-partiel)"></i> ${r.NomSalle}`;
            if (r.VilleSalle) lieu += ` — ${r.CpSalle ? r.CpSalle + ' ' : ''}${r.VilleSalle}`;
        } else if (r.VilleSalle) {
            lieu = `<i class="bi bi-geo-alt" style="color:var(--col-partiel)"></i> ${r.CpSalle ? r.CpSalle + ' ' : ''}${r.VilleSalle}`;
        }

        const $row = $(`
            <div class="renc-row${actif?' selectionne':''}" data-id="${id}" data-journee="${journee}" data-date="${date}" data-saison="${saison}" data-modal="1">
                <div class="renc-check"><i class="bi bi-check-lg"></i></div>
                <div class="renc-heure">${(r.Heure||'').substring(0,5)}</div>
                <div class="renc-div">${r.DivisionCode}</div>
                <div class="renc-info">
                    <div class="renc-match">${r.NomDom || '—'} <span style="color:#999;font-size:.8em">vs</span> ${r.NomExt || '—'}</div>
                    ${lieu ? `<div class="renc-lieu">${lieu}</div>` : ''}
                </div>
                ${distBadge(r.DistanceKm)}
            </div>
        `);
        $body.append($row);
    });
}

// Clic rencontre dans la modale
$('#mj-renc-body').on('click', '.renc-row', function (e) {
    e.stopPropagation();
    const $row    = $(this);
    const id      = +$row.data('id');
    const journee = +$row.data('journee');
    const date    = $row.data('date');
    const saison  = $row.data('saison');
    const k       = cle(saison, journee, date);

    $row.toggleClass('selectionne');
    const actif = $row.hasClass('selectionne');

    if (!etatJournees[k]) etatJournees[k] = { statut: 'P', rencontres: [], modifie: false, date };
    const idx = etatJournees[k].rencontres.indexOf(id);
    if (actif && idx === -1)  etatJournees[k].rencontres.push(id);
    if (!actif && idx !== -1) etatJournees[k].rencontres.splice(idx, 1);
    etatJournees[k].modifie = true;

    majBadgeModale(k);
    majRecapSave();
    sauvegarderJournee(journee, date, saison);
});

// Bouton "Tout sélectionner" dans la modale
$('#mj-sel-tout').on('click', function () {
    const { journee, date, saison, k } = $('#modal-journee').data();
    const $rows = $('#mj-renc-body .renc-row');
    const toutSelec = $rows.length && $rows.toArray().every(el => $(el).hasClass('selectionne'));

    if (toutSelec) {
        $rows.removeClass('selectionne');
        etatJournees[k].rencontres = [];
        $(this).text('Tout sélectionner');
    } else {
        const ids = [];
        $rows.each(function () { $(this).addClass('selectionne'); ids.push(+$(this).data('id')); });
        etatJournees[k].rencontres = ids;
        $(this).text('Tout désélectionner');
    }
    etatJournees[k].modifie = true;
    majBadgeModale(k);
    majRecapSave();
    sauvegarderJournee(journee, date, saison);
});

function majBadgeModale(k) {
    const nb    = (etatJournees[k]?.rencontres || []).length;
    const total = $('#mj-renc-body .renc-row').length;
    const toutSelec = nb > 0 && nb === total;
    $('#mj-sel-tout').text(toutSelec ? 'Tout désélectionner' : 'Tout sélectionner');
}

// Afficher vue calendrier par défaut dès que le calendrier est chargé
function afficherVueInitiale() {
    $('#section-calendrier').hide();
    $('#section-cal-grille').show();
}

// Rafraîchir la vue calendrier si elle est active (après sauvegarde)
function rafraichirCalSiActif() {
    renderCalendrierMensuel();
}

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    // L'identifiant JA est résolu côté PHP (?ja=TOKEN ou ?id_ja=X)
    const idJaUrl = <?= isset($_GET['id_ja']) ? (int)$_GET['id_ja'] : 0 ?>;

    if (idJaUrl > 0) {
        idJaCourant = idJaUrl;
        chargerInfosJA();
        chargerJournees();
    } else {
        $('#section-erreur').show();
    }
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
