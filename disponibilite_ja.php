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
 * Date de création : 2026-06-11
 */
require __DIR__ . '/config/db.php';
require __DIR__ . '/Classes/Obfuscator.php';

// ── Décodage du paramètre obfusqué ?ja=TOKEN ─────────────────────────────────
$_obf = new Obfuscator(OBFUSCATOR_SEED);
if (isset($_GET['ja']) && $_GET['ja'] !== '') {
    $decoded = $_obf->deobfuscate(trim($_GET['ja']));
    if ($decoded > 0) {
        $_GET['id_ja'] = $decoded;
    }
}

$pdo = getPDO();

// ── Initialisation : colonne Preference dans disponible ──────────────────────
// Reponse ENUM('O','P','N') et Id_Rencontre nullable existent déjà en base.
// On ajoute uniquement Preference si absente (pour les rencontres en mode Partiel).
try {
    $cols = array_column($pdo->query('DESCRIBE disponible')->fetchAll(), 'Field');
    if (!in_array('Preference', $cols)) {
        $pdo->exec("ALTER TABLE disponible ADD COLUMN Preference TINYINT NULL COMMENT '1=faible … 10=forte'");
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
        // Détecter si ja.Cp existe (ajoutée par jugearbitre.php)
        $jaCols = array_column($pdo->query('DESCRIBE ja')->fetchAll(), 'Field');
        $hasCp    = in_array('Cp',    $jaCols);
        $hasVille = in_array('Ville', $jaCols);
        $cpExpr    = $hasCp    ? 'COALESCE(lp.CodePostal, ja.Cp)'    : 'lp.CodePostal';
        $villeExpr = $hasVille ? 'COALESCE(lp.Nom,        ja.Ville)' : 'lp.Nom';
        $stmt = $pdo->prepare("
            SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade,
                   $cpExpr    AS Cp,
                   $villeExpr AS Ville
            FROM ja
            LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Id_JA = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'err' => 'Introuvable']);
        exit;
    }

    // ── Saisons disponibles ───────────────────────────────────────────────
    if ($action === 'saisons') {
        $rows = $pdo->query(
            "SELECT DISTINCT Saison FROM rencontre WHERE Saison IS NOT NULL ORDER BY Saison DESC"
        )->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }

    // ── Journées d'une saison (niveau 1 du calendrier) ───────────────────
    // Chaque (Journee, Date) donne un cartouche indépendant (ex: sam. + dim.)
    // Statut lu dans disponible : DateCompetition = Date exacte, Id_Rencontre IS NULL
    if ($action === 'journees') {
        $idJa   = (int)($_GET['id_ja']  ?? 0);
        $saison = trim($_GET['saison']  ?? '');
        if (!$idJa)  { echo json_encode(['ok' => false, 'err' => 'JA manquant']);     exit; }
        if (!$saison){ echo json_encode(['ok' => false, 'err' => 'Saison manquante']); exit; }

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
                dj.Reponse          AS Statut,
                COALESCE(sel.Nb, 0) AS NbSelectionnes,
                dist.MinKm,
                dist.MaxKm
            FROM (
                -- Un cartouche par (Journée × Date), hors club du JA
                SELECT r.Journee, r.Date, COUNT(*) AS NbRencontres
                FROM rencontre r
                JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                WHERE r.Saison = ?
                  AND NOT EXISTS (
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
                WHERE r2.Saison = ?
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
                WHERE r3.Saison = ?
                  AND lp_v.Latitude  IS NOT NULL
                  AND lp_ja.Latitude IS NOT NULL
                  AND (lp_ja.JaClub IS NULL OR ed3.Id_Club != lp_ja.JaClub)
                GROUP BY r3.Journee, r3.Date
            ) dist ON dist.Journee = j.Journee AND dist.Date = j.Date
            ORDER BY j.Journee, j.Date
        ");
        $stmt->execute([$saison, $idJa, $idJa, $idJa, $saison, $idJa, $saison]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll()]);
        exit;
    }

    // ── Rencontres d'une journée (niveau 2 — Partiel) ────────────────────
    if ($action === 'rencontres_journee') {
        $idJa   = (int)($_GET['id_ja']  ?? 0);
        $saison  = trim($_GET['saison'] ?? '');
        $journee = (int)($_GET['journee'] ?? 0);
        $date    = trim($_GET['date'] ?? '');
        if (!$idJa || !$saison || !$journee || !$date) {
            echo json_encode(['ok' => false, 'err' => 'Paramètres manquants']);
            exit;
        }

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
            WHERE r.Saison = ? AND r.Journee = ? AND r.Date = ?
              -- Exclure les rencontres dont l'équipe domicile appartient au club du JA
              AND (lp_ja.JaClub IS NULL OR ed.Id_Club != lp_ja.JaClub)
            ORDER BY r.Heure, d.Ord
        ");
        $stmt->execute([$idJa, $idJa, $saison, $journee, $date]);
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
        $saison      = trim($_POST['saison']    ?? '');
        $journee     = (int)($_POST['journee']  ?? 0);
        $dateJournee = trim($_POST['date']      ?? '');
        $statut      = strtoupper(trim($_POST['statut'] ?? ''));
        if (!$idJa || !$saison || !$journee || !$dateJournee || !in_array($statut, ['O','P','N'])) {
            echo json_encode(['ok' => false, 'err' => 'Paramètres invalides']);
            exit;
        }
        // Validation basique du format de date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateJournee)) {
            echo json_encode(['ok' => false, 'err' => 'Date invalide']); exit;
        }

        // 1. Entrée journée-niveau : DELETE + INSERT (Id_Rencontre IS NULL → pas de contrainte UNIQUE utilisable)
        $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND DateCompetition = ? AND Id_Rencontre IS NULL")
            ->execute([$idJa, $dateJournee]);
        $pdo->prepare("INSERT INTO disponible (Id_JA, DateCompetition, Reponse, DateReponse) VALUES (?, ?, ?, CURDATE())")
            ->execute([$idJa, $dateJournee, $statut]);

        // 2. Rencontres de ce cartouche (journée + date exacte)
        $stmtIds = $pdo->prepare("SELECT Id_Rencontre, Date FROM rencontre WHERE Saison = ? AND Journee = ? AND Date = ?");
        $stmtIds->execute([$saison, $journee, $dateJournee]);
        $tousRencontres = $stmtIds->fetchAll(); // [Id_Rencontre, Date]
        $tousIds = array_column($tousRencontres, 'Id_Rencontre');

        if ($statut === 'O') {
            // Disponible → upsert toutes les rencontres Reponse='O'
            $stmtUps = $pdo->prepare("
                INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse)
                VALUES (?, ?, ?, 'O', CURDATE())
                ON DUPLICATE KEY UPDATE Reponse='O', DateCompetition=VALUES(DateCompetition), DateReponse=CURDATE()
            ");
            foreach ($tousRencontres as $r) $stmtUps->execute([$idJa, $r['Id_Rencontre'], $r['Date']]);

        } elseif ($statut === 'N') {
            // Non disponible → supprimer toutes les rencontres-niveau
            if ($tousIds) {
                $in = implode(',', array_fill(0, count($tousIds), '?'));
                $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND Id_Rencontre IN ($in)")
                    ->execute(array_merge([$idJa], $tousIds));
            }

        } elseif ($statut === 'P') {
            // Partiel → upsert sélectionnées, supprimer les autres
            $selIds = array_map('intval', (array)($_POST['rencontres'] ?? []));
            // Construire un map Id_Rencontre → Date pour les sélectionnées
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

    echo json_encode(['ok' => false, 'err' => 'Action inconnue']);

    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'err' => 'BDD : ' . $e->getMessage()]);
    }
    exit;
}

// ── Saison courante par défaut ────────────────────────────────────────────────
$saisonDefaut = '';
try {
    $row = $pdo->query("SELECT Saison FROM rencontre WHERE Saison IS NOT NULL ORDER BY Saison DESC LIMIT 1")->fetch();
    if ($row) $saisonDefaut = $row['Saison'];
} catch (PDOException $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Disponibilités JA (E012)</title>
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
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

        /* ── Toast ───────────────────────────────────────────────────────── */
        #toast-zone { position: fixed; bottom: 4.5rem; right: 1rem; z-index: 9999; }

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
</div>

<!-- Message d'erreur si aucun paramètre JA dans l'URL -->
<div id="section-erreur" style="display:none" class="alert alert-warning m-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    Aucun identifiant JA fourni. Veuillez utiliser le lien personnalisé qui vous a été communiqué.
</div>

<!-- ── Barre saison (après sélection JA) ─────────────────────────────────── -->
<div id="barre-saison">
    <i class="bi bi-funnel me-1 text-secondary"></i>
    <label class="fw-bold me-1" style="font-size:.85rem">Saison :</label>
    <select id="sel-saison" class="form-select form-select-sm" style="width:140px"></select>
    <span id="barre-spin" style="display:none"><span class="spin-sm"></span></span>
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

<!-- Toasts -->
<div id="toast-zone"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let idJaCourant  = null;
let nomJaCourant = '';
let saisonCourante = '<?= htmlspecialchars($saisonDefaut) ?>';

// Etat local : { "saison_journee": { statut:'O'|'P'|'N', rencontres:[ids selec] } }
let etatJournees = {};

// ── Utilitaires ───────────────────────────────────────────────────────────────
function toast(msg, ok = true) {
    const id  = 't' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-zone').append(
        `<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show">
           <div class="d-flex">
             <div class="toast-body">${msg}</div>
             <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
           </div>
         </div>`
    );
    setTimeout(() => $(`#${id}`).remove(), 3500);
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
    });
}

function chargerSaisons() {
    $.getJSON('disponibilite_ja.php?action=saisons', function (r) {
        const $s = $('#sel-saison').empty();
        if (r.ok && r.data.length) {
            r.data.forEach(s => $s.append(`<option value="${s}"${s===saisonCourante?' selected':''}>${s}</option>`));
            if (!r.data.includes(saisonCourante) && r.data.length)
                saisonCourante = r.data[0];
        }
        // Afficher le calendrier
        $('#barre-save').css('display','flex');
        chargerJournees();
    });
}

// ── Chargement des journées (niveau 1) ───────────────────────────────────────
function chargerJournees() {
    const saison = $('#sel-saison').val() || saisonCourante;
    if (!idJaCourant || !saison) return;
    $('#barre-spin').show();
    $('#section-calendrier').show().html(
        '<div class="text-muted text-center py-5"><span class="spin-sm me-2"></span>Chargement des journées…</div>'
    );

    $.getJSON('disponibilite_ja.php', {
        action: 'journees',
        id_ja:  idJaCourant,
        saison: saison,
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
        renderCalendrier(r.data, saison);
        majRecapSave();
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
                    <i class="bi bi-geo-alt-fill"></i>Lieux qui reçoivent — cochez les rencontres que vous pouvez arbitrer
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
        saison:   saison,
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
        saison:     saison,
        journee:    journee,
        date:       date,
        statut:     e.statut,
        rencontres: e.rencontres,
    }, function (r) {
        if (!r.ok) toast('Erreur : ' + r.err, false);
    }, 'json');
}

// ── Bouton "Tout enregistrer" (confirmation globale) ─────────────────────────
$('#btn-tout-sauvegarder').on('click', function () {
    const nb = Object.values(etatJournees).filter(e => e.statut).length;
    if (!nb) { toast('Aucune disponibilité à enregistrer.', false); return; }
    toast(`✓ ${nb} journée${nb>1?'s':''} enregistrée${nb>1?'s':''}. Merci !`);
});

// ── Changer de saison ─────────────────────────────────────────────────────────
$('#sel-saison').on('change', function () {
    saisonCourante = $(this).val();
    etatJournees   = {};
    chargerJournees();
});


// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    // L'identifiant JA est résolu côté PHP (?ja=TOKEN ou ?id_ja=X)
    const idJaUrl = <?= isset($_GET['id_ja']) ? (int)$_GET['id_ja'] : 0 ?>;

    if (idJaUrl > 0) {
        idJaCourant = idJaUrl;
        chargerInfosJA();
        chargerSaisons(); // enchaîne → chargerJournees()
    } else {
        $('#section-erreur').show();
    }
});
</script>
</body>
</html>
