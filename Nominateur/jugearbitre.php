<?php
/**
 * NIJAC – Gestion des Juges-Arbitres (E007)
 *
 * Importe et gère la liste des Juges-Arbitres (JA) depuis un fichier Excel FFTT.
 * Permet de visualiser, modifier et activer/désactiver les fiches JA.
 * Normalise automatiquement les noms de ville et enrichit les données
 * (code postal, ville, coordonnées GPS) depuis la table laposte.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 * Déplacé dans Nominateur/ : 2026-06-12
 */
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../index.php');
    exit;
}
$moi     = $_SESSION['utilisateur'];
$isAdmin = !empty($moi['is_admin']);

// ── Formater un numéro de téléphone ───────────────────────────────────────────
// Normalise un nom de ville : majuscules, tirets et apostrophes → espace, espaces multiples réduits
function normaliserVille(string $ville): string
{
    $v = mb_strtoupper(trim($ville), 'UTF-8');
    $v = str_replace(['-', "'", "\u{2019}"], ' ', $v); // tiret, apostrophe droite et typographique → espace
    $v = preg_replace('/\s+/', ' ', $v);               // espaces multiples → un seul
    return trim($v);
}

function formaterTelephone(?string $tel): ?string
{
    if ($tel === null || $tel === '') return null;
    $t = preg_replace('/\D/', '', $tel); // garder uniquement les chiffres
    if (strlen($t) === 10) {
        return implode('.', str_split($t, 2));
    }
    return $tel;
}

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    // Actions réservées aux administrateurs
    $actionsAdmin = ['importer_excel', 'sauvegarder', 'supprimer', 'maj_laposte'];
    if (in_array($action, $actionsAdmin) && !$isAdmin) {
        echo json_encode(['ok' => false, 'err' => 'Accès refusé']);
        exit;
    }

    try {
        $pdo = getPDO();

        // Ajout automatique des colonnes Cp / Ville si elles n'existent pas encore
        $cols = array_column($pdo->query('SHOW COLUMNS FROM ja')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('Cp', $cols)) {
            $pdo->exec("ALTER TABLE ja ADD COLUMN Cp VARCHAR(10) NULL, ADD COLUMN Ville VARCHAR(100) NULL");
        }
        if (!in_array('Defiscalisation', $cols)) {
            $pdo->exec("ALTER TABLE ja ADD COLUMN Defiscalisation TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('Nationale', $cols)) {
            $pdo->exec("ALTER TABLE ja ADD COLUMN Nationale TINYINT(1) NOT NULL DEFAULT 0");
        }
        // Backfill : remplir Cp/Ville depuis laposte pour les lignes qui ont Id_LaPoste mais pas encore Cp
        $pdo->exec("UPDATE ja j
                    JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    SET j.Cp = lp.CodePostal, j.Ville = lp.Nom
                    WHERE j.Cp IS NULL AND j.Id_LaPoste IS NOT NULL");

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            $dept = isset($_POST['dept']) && $_POST['dept'] !== '' ? $_POST['dept'] : null;

            $whereDept = $dept !== null
                ? 'WHERE j.Id_Club IN (
                       SELECT s.Id_Club
                       FROM Salle   s
                       JOIN laposte lf ON lf.Id_LaPoste = s.Id_Laposte
                       WHERE s.EstPrincipale = 1
                         AND LEFT(lf.CodePostal, 2) = ?
                   )'
                : '';

            $stmt = $pdo->prepare(
                'SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.Telephone,
                        j.Grade, j.Actif, j.Id_Club, j.DistanceMaxKm, j.Id_LaPoste,
                        j.Defiscalisation, j.Nationale,
                        cl.Nom AS NomClub,
                        COALESCE(lp.CodePostal, j.Cp)   AS CodePostalJA,
                        COALESCE(lp.Nom,        j.Ville) AS VilleJA,
                        (SELECT COUNT(*) FROM disponible d
                         WHERE d.Id_JA = j.Id_JA
                           AND d.DateCompetition >= (
                               SELECT MIN(r2.Date) FROM rencontre r2
                               WHERE r2.Saison = (SELECT Saison FROM rencontre ORDER BY Saison DESC LIMIT 1)
                           )
                        ) AS NbDispo
                 FROM ja j
                 LEFT JOIN Club    cl ON cl.Id_Club    = j.Id_Club
                 LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                 ' . $whereDept . '
                 ORDER BY j.Nom, j.Prenom'
            );
            $stmt->execute($dept !== null ? [str_pad((string)$dept, 2, '0', STR_PAD_LEFT)] : []);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Normalisation des clés pour éviter les problèmes de casse PDO
            $rows = array_map(function($r) {
                // Retrouver les clés indépendamment de la casse
                $find = function($row, $key) {
                    if (isset($row[$key])) return $row[$key];
                    $keyLow = strtolower($key);
                    foreach ($row as $k => $v) {
                        if (strtolower($k) === $keyLow) return $v;
                    }
                    return null;
                };
                return [
                    'Id_JA'          => $find($r, 'Id_JA'),
                    'Nom'            => $find($r, 'Nom'),
                    'Prenom'         => $find($r, 'Prenom'),
                    'Email'          => $find($r, 'Email'),
                    'Telephone'      => $find($r, 'Telephone'),
                    'Grade'          => $find($r, 'Grade'),
                    'Actif'          => $find($r, 'Actif'),
                    'Id_Club'        => $find($r, 'Id_Club'),
                    'DistanceMaxKm'  => $find($r, 'DistanceMaxKm'),
                    'Id_LaPoste'     => $find($r, 'Id_LaPoste'),
                    'Defiscalisation'=> $find($r, 'Defiscalisation'),
                    'Nationale'      => $find($r, 'Nationale'),
                    'NbDispo'        => $find($r, 'NbDispo'),
                    'NomClub'        => $find($r, 'NomClub'),
                    'CP'             => $find($r, 'CodePostalJA'),
                    'Ville'          => $find($r, 'VilleJA'),
                ];
            }, $rows);

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Recherche laposte par CP et/ou Ville ───────────────────────────
        if ($action === 'recherche_laposte') {
            $cp    = trim($_POST['cp']    ?? '');
            $ville = normaliserVille($_POST['ville'] ?? '');

            if ($cp === '' && $ville === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'CP et Ville vides.']);
                exit;
            }

            // 1) Correspondance exacte CP + Ville
            if ($cp !== '' && $ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? AND UPPER(REPLACE(REPLACE(Nom, '-', ' '), '''', ' ')) = ? LIMIT 1");
                $stmt->execute([$cp, $ville]);
                $row = $stmt->fetch();
                if ($row) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                    exit;
                }
            }

            // 2) CP seul → toutes les villes correspondantes
            if ($cp !== '') {
                $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
                $stmt->execute([$cp]);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                    exit;
                }
                if (count($rows) > 1) {
                    $sugg = array_map(function($r) { return ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']]; }, $rows);
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                    exit;
                }
            }

            // 3) Ville seule → correspondance partielle
            if ($ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE UPPER(REPLACE(REPLACE(Nom, '-', ' '), '''', ' ')) LIKE ? ORDER BY CodePostal, Nom LIMIT 20");
                $stmt->execute([$ville . '%']);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                    exit;
                }
                if (count($rows) > 1) {
                    $sugg = array_map(function($r) { return ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']]; }, $rows);
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]);
                    exit;
                }
            }

            ob_end_clean();
            echo json_encode(['ok' => false, 'msg' => 'Aucune commune trouvée.']);
            exit;
        }

        // ── Importer Excel ─────────────────────────────────────────────────
        if ($action === 'importer_excel') {
            if (empty($_FILES['fichier'])) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Aucun fichier reçu (post_max_size = ' . ini_get('post_max_size') . ').']);
                exit;
            }
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE   => 'Fichier trop volumineux (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE  => 'Fichier trop volumineux (limite formulaire).',
                UPLOAD_ERR_PARTIAL    => 'Transfert incomplet.',
                UPLOAD_ERR_NO_FILE    => 'Aucun fichier sélectionné.',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant.',
                UPLOAD_ERR_CANT_WRITE => 'Impossible d\'écrire le fichier temporaire.',
                UPLOAD_ERR_EXTENSION  => 'Upload bloqué par une extension PHP.',
            ];
            if ($_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                $code = $_FILES['fichier']['error'];
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => $uploadErrors[$code] ?? "Erreur upload (code $code)."]);
                exit;
            }
            if (strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
                exit;
            }

            if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'PhpSpreadsheet non installé sur ce serveur. Contactez l\'administrateur.']);
                exit;
            }
            require_once __DIR__ . '/../vendor/autoload.php';
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['fichier']['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = $sheet->getHighestRow();

            // Charger tous les clubs pour enrichir nom_club
            $clubsMap = [];
            foreach ($pdo->query('SELECT Id_Club, Nom FROM Club')->fetchAll() as $c) {
                $clubsMap[(int)$c['Id_Club']] = $c['Nom'];
            }

            // Charger la table laposte : clé = "CP|VILLE_NORMALISEE" → Id_LaPoste
            $laposteMap = [];
            foreach ($pdo->query('SELECT Id_LaPoste, CodePostal, Nom FROM laposte')->fetchAll() as $lp) {
                $cle = trim($lp['CodePostal']) . '|' . normaliserVille($lp['Nom']);
                $laposteMap[$cle] = $lp['Id_LaPoste'];
            }

            $lignes = [];
            // Colonnes : A=Id_JA, B=Nom, C=Prénom, H=Id_Club, J=Grade,
            //            N=Actif, R=CodePostal, S=Ville, T=Email, U=Téléphone(alt), V=Téléphone
            for ($row = 3; $row <= $maxRow; $row++) {
                $grade = trim((string)$sheet->getCell('J' . $row)->getValue());

                // Filtrer : ne garder que les grades commençant par "JA"
                if (strncasecmp($grade, 'JA', 2) !== 0) continue;

                $idJA    = trim((string)$sheet->getCell('A' . $row)->getValue());
                $nom     = trim((string)$sheet->getCell('B' . $row)->getValue());
                $prenom  = trim((string)$sheet->getCell('C' . $row)->getValue());
                $idClub  = trim((string)$sheet->getCell('H' . $row)->getValue());
                $actifRaw= trim((string)$sheet->getCell('N' . $row)->getValue());
                $cp      = trim((string)$sheet->getCell('R' . $row)->getValue());
                $ville   = trim((string)$sheet->getCell('S' . $row)->getValue());
                $email   = trim((string)$sheet->getCell('T' . $row)->getValue());
                $telU    = trim((string)$sheet->getCell('U' . $row)->getValue());
                $telV    = trim((string)$sheet->getCell('V' . $row)->getValue());

                if ($nom === '' && $prenom === '') continue;

                // Téléphone : V en priorité, sinon U
                $tel = $telV !== '' ? $telV : $telU;

                // Actif : "Actif" → 1, tout autre valeur → 0
                $actif = (strtolower(trim($actifRaw)) === 'actif') ? 1 : 0;

                // Recherche Id_LaPoste par CP + Ville (normalisation majuscules)
                $idLaPoste = null;
                if ($cp !== '' && $ville !== '') {
                    $cle = $cp . '|' . normaliserVille($ville);
                    $idLaPoste = $laposteMap[$cle] ?? null;
                }

                $lignes[] = [
                    'id'              => $idJA !== '' ? (int)$idJA : 0,
                    'nom'             => mb_strtoupper($nom, 'UTF-8'),
                    'prenom'          => mb_convert_case($prenom, MB_CASE_TITLE, 'UTF-8'),
                    'email'           => $email !== '' ? $email : null,
                    'telephone'       => formaterTelephone($tel !== '' ? $tel : null),
                    'grade'           => $grade,
                    'actif'           => $actif,
                    'id_club'         => $idClub !== '' ? (int)$idClub : null,
                    'nom_club'        => $idClub !== '' ? ($clubsMap[(int)$idClub] ?? '') : '',
                    'distance_max_km' => 999,
                    'id_laposte'      => $idLaPoste,
                    'cp'              => $cp,
                    'ville'           => $ville,
                ];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
            exit;
        }

        // ── Mise à jour immédiate Id_LaPoste ───────────────────────────────
        if ($action === 'maj_laposte') {
            $idJA      = (int)($_POST['id_ja'] ?? 0);
            $idLaPoste = ($_POST['id_laposte'] ?? '') !== '' ? (int)$_POST['id_laposte'] : null;
            $cpVal     = trim($_POST['cp']    ?? '') ?: null;
            $villeVal  = trim($_POST['ville'] ?? '') ?: null;
            if ($idJA <= 0) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Id_JA invalide.']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?');
            $stmt->execute([$idLaPoste, $cpVal, $villeVal, $idJA]);
            ob_end_clean();
            echo json_encode(['ok' => true]);
            exit;
        }

        // ── Mettre à jour la BDD (UPSERT) ─────────────────────────────────
        if ($action === 'maj_bdd') {
            $lignes = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Données invalides.']);
                exit;
            }

            $inserts = 0;
            $updates = 0;
            $erreurs = [];

            $stmtCheck  = $pdo->prepare('SELECT COUNT(*) FROM ja WHERE Id_JA = ?');
            $stmtInsert = $pdo->prepare(
                'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Telephone, Grade, Actif,
                                 Id_Club, DistanceMaxKm, Id_LaPoste, Cp, Ville, Defiscalisation, Nationale)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtUpdate = $pdo->prepare(
                'UPDATE ja SET Nom=?, Prenom=?, Email=?, Telephone=?, Grade=?,
                               Actif=?, Id_Club=?, DistanceMaxKm=?, Id_LaPoste=?, Cp=?, Ville=?,
                               Defiscalisation=?, Nationale=?
                 WHERE Id_JA=?'
            );

            foreach ($lignes as $l) {
                $id      = (int)($l['id']              ?? 0);
                $nom     = trim($l['nom']              ?? '');
                $prenom  = trim($l['prenom']           ?? '');
                $email   = $l['email']   !== '' && $l['email']   !== null ? $l['email']   : null;
                $tel     = formaterTelephone($l['telephone'] !== '' && $l['telephone'] !== null ? $l['telephone'] : null);
                $grade   = trim($l['grade']            ?? '');
                $actif     = !empty($l['actif']) ? 1 : 0;
                $defisc    = !empty($l['defiscalisation']) ? 1 : 0;
                $nationale = !empty($l['nationale']) ? 1 : 0;
                $idClub  = $l['id_club'] !== '' && $l['id_club'] !== null ? (int)$l['id_club'] : null;
                $distMax = $l['distance_max_km'] !== '' && $l['distance_max_km'] !== null ? (int)$l['distance_max_km'] : 999;
                $idLap   = $l['id_laposte'] !== '' && $l['id_laposte'] !== null ? (int)$l['id_laposte'] : null;
                $cpVal   = $l['cp']    !== '' && $l['cp']    !== null ? trim($l['cp'])    : null;
                $villeVal= $l['ville'] !== '' && $l['ville'] !== null ? trim($l['ville']) : null;

                if ($nom === '') continue;

                try {
                    if ($id > 0) {
                        $stmtCheck->execute([$id]);
                        if ((int)$stmtCheck->fetchColumn() > 0) {
                            $stmtUpdate->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $distMax, $idLap, $cpVal, $villeVal, $defisc, $nationale, $id]);
                            $updates++;
                        } else {
                            $stmtInsert->execute([$id, $nom, $prenom, $email, $tel, $grade, $actif, $idClub, $distMax, $idLap, $cpVal, $villeVal, $defisc, $nationale]);
                            $inserts++;
                        }
                    } else {
                        // Pas d'Id_JA → INSERT auto-increment
                        $pdo->prepare(
                            'INSERT INTO ja (Nom, Prenom, Email, Telephone, Grade, Actif,
                                             Id_Club, DistanceMaxKm, Id_LaPoste, Cp, Ville, Defiscalisation, Nationale)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        )->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $distMax, $idLap, $cpVal, $villeVal, $defisc, $nationale]);
                        $inserts++;
                    }
                } catch (PDOException $ex) {
                    $erreurs[] = "$nom $prenom : " . $ex->getMessage();
                }
            }

            $msg = "Mise à jour terminée : $inserts insérés, $updates mis à jour.";
            if ($erreurs) $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            ob_end_clean();
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] jugearbitre.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur Excel : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] jugearbitre.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
$isAdminJs   = $isAdmin ? 'true' : 'false';
$deptUserJs  = $isAdmin ? "''" : "'" . addslashes($moi['id_departement'] ?? '') . "'";
$deptActifs  = getDeptActifs();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Juges-Arbitres (E007)</title>

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

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 260px;
        }

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Grille ── */
        #grid-wrapper { flex: 1; overflow: auto; }

        #tbl-ja {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 1000px;
        }

        #tbl-ja thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
            user-select: none;
        }
        #tbl-ja thead th:hover { background: #d4dff0; }
        #tbl-ja thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-ja thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-ja thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-ja thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-ja tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-ja tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-ja tbody tr:hover   { background: #dce8f8; }
        #tbl-ja tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-ja tbody td { border: 1px solid #e0e8f0; padding: 0; }

        .cell-inner {
            display: block;
            padding: .28rem .45rem;
            min-height: 28px;
            outline: none;
            white-space: nowrap;
            overflow: hidden;
        }
        .cell-inner[contenteditable="true"] {
            background: #fffbe6;
            outline: 2px solid #f0a000;
            outline-offset: -2px;
        }

        td.col-readonly { background: #f0f4fa; }
        td.col-readonly .cell-inner { color: #6b7280; font-style: italic; }

        /* Actif badge */
        .badge-actif     { background: #d1fae5; color: #065f46; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-inactif   { background: #fee2e2; color: #991b1b; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-defisc    { background: #dbeafe; color: #1e40af; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }
        .badge-no-defisc { background: #f3f4f6; color: #6b7280; border-radius: 10px; padding: .1rem .45rem; font-size: .75rem; font-weight: 600; }

        /* ── Label compteur ── */
        #lbl-count {
            margin-left: .75rem;
            padding: .2rem .6rem;
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            font-size: .82rem;
            color: #1a3a6b;
            font-weight: 600;
        }


        /* ── Toggle Tous / Actifs ── */
        #toggle-actif {
            display: inline-flex;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            overflow: hidden;
            font-size: .82rem;
        }
        #toggle-actif button {
            padding: .2rem .65rem;
            border: none;
            background: #fff;
            cursor: pointer;
            color: #374151;
            transition: background .15s, color .15s;
        }
        #toggle-actif button:hover { background: #e8eef7; }
        #toggle-actif button.active {
            background: #1a3a6b;
            color: #fff;
            font-weight: 600;
        }
        #btn-erreurs-cp { color: #92400e; }
        #btn-erreurs-cp:hover { background: #fef3c7; }
        #btn-erreurs-cp.active { background: #d97706; color: #fff; }

        /* ── Toast ── */
        #toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 9999; }

        /* ── Spinner ── */
        #spinner {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.3);
            z-index: 99999;
            align-items: center;
            justify-content: center;
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

<!-- MenuStrip -->
<div id="menu-strip">
    <?php if ($isAdmin): ?>
    <button class="menu-item" id="btn-importer">
        <i class="bi bi-file-earmark-arrow-up"></i>Importer Excel
    </button>
    <button class="menu-item" id="btn-maj-bdd">
        <i class="bi bi-database-fill-up"></i>Mettre à jour la Base de données
    </button>
    <input type="file" id="file-input" accept=".xlsx" style="display:none">
    <?php endif; ?>
    <span id="lbl-count">0 JA</span>
    <div id="toggle-actif" style="margin-left:.5rem">
        <button id="btn-tous"         class="active">Tous</button>
        <button id="btn-actifs">Actifs seulement</button>
        <button id="btn-erreurs-cp">⚠ Erreurs CP/Ville</button>
    </div>
    &nbsp;Site utile : <a href="https://www.dcode.fr/code-postal" target="_blank" class="text-decoration-none">dCode code-postal</a>
    <span style="flex:1"></span>
    <?php if ($isAdmin): ?>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-person-badge-fill me-2"></i>Gestion des Juges-Arbitres
    <small class="opacity-75 ms-2">(E007)</small>
    <a href="menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour au menu
    </a>
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-ja">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id">N° JA<span class="sort-icon"></span></th>
                <th style="width:55px"  data-field="grade">Grade<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:140px" data-field="prenom">Prénom<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="email">Email<span class="sort-icon"></span></th>
                <th style="width:120px" data-field="telephone">Téléphone<span class="sort-icon"></span></th>
                <th style="width:65px"  data-field="actif">Actif<span class="sort-icon"></span></th>
                <th style="width:75px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="defiscalisation">Défiscalisation<span class="sort-icon"></span></th>
                <th style="width:85px"  data-field="nationale">Nationale<span class="sort-icon"></span></th>
                <th style="width:80px"  data-field="distance_max_km">Dist. max<span class="sort-icon"></span></th>
                <th style="width:75px"  data-field="cp">CP<span class="sort-icon"></span></th>
                <th style="width:160px" data-field="ville">Ville<span class="sort-icon"></span></th>
                <th style="width:75px"  class="no-sort">Lien dispo</th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="16" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<?php $statusInitial = 'Prêt. — Cliquez sur une cellule puis F2 pour modifier.'; ?>

<!-- Toast -->
<div id="toast-container"></div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
    <script src="../asset/js/nijac-csrf.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let lignes     = [];
let filtreActif   = false;  // false = tous, true = actifs seulement
let filtreErreursCp = false; // true = uniquement les lignes sans id_laposte
let cellActive = null;
let sortField  = 'nom';
let sortDir    = 'asc';
let searchTerm = '';
const isAdmin  = <?= $isAdminJs ?>;
let deptFiltre = <?= $deptUserJs ?>; // nominateur : filtré sur son dept

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function toast(msg, ok = true) {
    const id  = 't' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-container').append(
        `<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show">
           <div class="d-flex">
             <div class="toast-body">${msg}</div>
             <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
           </div>
         </div>`
    );
    setTimeout(() => $(`#${id}`).remove(), 4000);
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let source = [...lignes];
    if (filtreActif)     source = source.filter(l => l.actif === 1);
    if (filtreErreursCp) source = source.filter(l => l.id_laposte == null);
    let result = term
        ? source.filter(l =>
            String(l.id              ?? '').toLowerCase().includes(term) ||
            String(l.nom             ?? '').toLowerCase().includes(term) ||
            String(l.prenom          ?? '').toLowerCase().includes(term) ||
            String(l.email           ?? '').toLowerCase().includes(term) ||
            String(l.telephone       ?? '').toLowerCase().includes(term) ||
            String(l.grade           ?? '').toLowerCase().includes(term) ||
            String(l.id_club         ?? '').toLowerCase().includes(term) ||
            String(l.nom_club        ?? '').toLowerCase().includes(term) ||
            String(l.cp              ?? '').toLowerCase().includes(term) ||
            String(l.ville           ?? '').toLowerCase().includes(term))
        : source;

    const numFields = ['id', 'id_club', 'distance_max_km'];
    result.sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        if (sortField === 'actif') {
            return sortDir === 'asc' ? a.actif - b.actif : b.actif - a.actif;
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function majEnteteTri() {
    $('#tbl-ja thead th').each(function () {
        const f = $(this).data('field');
        $(this).removeClass('sort-asc sort-desc');
        if (f === sortField) $(this).addClass(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    majEnteteTri();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun juge-arbitre.';
        $body.append(`<tr><td colspan="15" class="text-center text-muted py-3">${msg}</td></tr>`);
    } else {
        affichees.forEach(l => {
            const idx  = l._idx;          // index stable, indépendant du filtre/tri
            const $tr  = $('<tr>').attr('data-idx', idx);
            const actifHtml = l.actif
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const defiscHtml = l.defiscalisation
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';
            const nationaleHtml = l.nationale
                ? '<span class="badge-actif">Oui</span>'
                : '<span class="badge-inactif">Non</span>';

            $tr.append(makeTd(l.id,              idx, 'id',              true));
            $tr.append(makeTd(l.grade,            idx, 'grade',           false));
            $tr.append(makeTd(l.nom,              idx, 'nom',             false));
            $tr.append(makeTd(l.prenom,           idx, 'prenom',          false));
            $tr.append(makeTd(l.email,            idx, 'email',           false));
            $tr.append(makeTd(l.telephone,        idx, 'telephone',       false));
            $tr.append(makeTdHtml(actifHtml,      idx, 'actif'));
            $tr.append(makeTd(l.id_club,          idx, 'id_club',         true));
            $tr.append(makeTd(l.nom_club,         idx, 'nom_club',        true));
            $tr.append(makeTdHtml(defiscHtml,     idx, 'defiscalisation'));
            $tr.append(makeTdHtml(nationaleHtml,  idx, 'nationale'));
            $tr.append(makeTd(l.distance_max_km,  idx, 'distance_max_km', false));
            $tr.append(makeTdLaPoste(l.cp,        idx, 'cp'));
            $tr.append(makeTdLaPoste(l.ville,     idx, 'ville'));
            // Bouton lien disponibilité
            const $tdLien = $('<td>').css({textAlign:'center', verticalAlign:'middle', padding:'.2rem'});
            const dispoCls   = l.nb_dispo > 0 ? 'btn-success'         : 'btn-outline-secondary';
            const dispoTitle = l.nb_dispo > 0 ? `Disponibilités saisies (${l.nb_dispo} journée(s))` : 'Aucune disponibilité saisie — cliquez pour ouvrir';
            $tdLien.html(`<button class="btn btn-sm ${dispoCls} btn-lien-dispo" data-id="${l.id}" title="${dispoTitle}"><i class="bi bi-calendar2-check"></i></button>`);
            $tr.append($tdLien);
            $body.append($tr);
        });
    }

    const info = searchTerm
        ? `${affichees.length} résultat(s) sur ${lignes.length}`
        : `${lignes.length} JA`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    const nbActifs    = lignes.filter(l => l.actif === 1).length;
    const nbErreursCp = lignes.filter(l => l.id_laposte == null).length;
    let   lblTxt = `${lignes.length} JA dont ${nbActifs} actif${nbActifs > 1 ? 's' : ''}`;
    if (nbErreursCp > 0) lblTxt += ` — ⚠ ${nbErreursCp} erreur${nbErreursCp > 1 ? 's' : ''} CP`;
    $('#lbl-count').text(lblTxt);
}

const CHAMPS_NUMERIQUES = ['distance_max_km'];

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? 'col-readonly' : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        if (CHAMPS_NUMERIQUES.includes(field)) {
            // Clic direct → édition immédiate pour les champs numériques
            $td.on('click', function (e) {
                e.stopPropagation();
                selectionnerCellule($(this));
                const $inner = $(this).find('.cell-inner');
                if ($inner.attr('contenteditable') === 'false') {
                    $inner.attr('contenteditable', 'true').trigger('focus');
                    const range = document.createRange();
                    range.selectNodeContents($inner[0]);
                    window.getSelection().removeAllRanges();
                    window.getSelection().addRange(range);
                }
            });
        } else {
            $td.on('click', function (e) { e.stopPropagation(); selectionnerCellule($(this)); });
        }
    }
    return $td;
}

function makeTdHtml(html, idx, field) {
    const $td = $('<td>').attr('data-idx', idx).attr('data-field', field)
                         .css({textAlign: 'center', verticalAlign: 'middle', padding: '.2rem'});
    $td.html(html);
    // Clic → bascule la valeur booléenne du champ
    $td.on('click', function () {
        lignes[idx][field] = lignes[idx][field] ? 0 : 1;
        renderGrille();
        setStatus('Modification locale. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
    });
    return $td;
}

// ── Cellule CP / Ville avec code couleur laposte ──────────────────────────────
function makeTdLaPoste(val, idx, field) {
    const l      = lignes[idx];
    const trouve = l ? l.id_laposte != null : false;
    const vide   = !val || String(val).trim() === '';
    const bg     = trouve ? '#d1fae5' : '#fee2e2';
    const $td = $('<td>')
        .attr('data-idx', idx)
        .attr('data-field', field)
        .css({ background: bg });
    const affichage = vide && !trouve ? '—' : (val ?? '');
    const $div = $('<div class="cell-inner">').text(affichage).attr('contenteditable', 'false');
    $td.append($div);

    // Clic direct → édition immédiate
    $td.on('click', function (e) {
        e.stopPropagation();
        selectionnerCellule($(this));
        const $inner = $(this).find('.cell-inner');
        if ($inner.attr('contenteditable') === 'false') {
            $inner.attr('contenteditable', 'true').trigger('focus');
            const range = document.createRange();
            range.selectNodeContents($inner[0]);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
        }
    });
    return $td;
}

// ── Sauvegarde immédiate Id_LaPoste en base ───────────────────────────────────
function sauvegarderLaPoste(idx) {
    const l = lignes[idx];
    if (!l || !(+l.id > 0)) {
        // Ligne pas encore en base : informer seulement
        setStatus('Commune trouvée. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
        return;
    }
    $.post('jugearbitre.php', { action: 'maj_laposte', id_ja: l.id, id_laposte: l.id_laposte ?? '', cp: l.cp ?? '', ville: l.ville ?? '' }, function (res) {
        if (res.ok) {
            setStatus(`Commune mise à jour en base pour ${l.nom} ${l.prenom}.`);
            toast(`Commune sauvegardée : ${l.cp} ${l.ville}.`);
        } else {
            toast(`Erreur sauvegarde : ${res.msg}`, false);
        }
    }, 'json').fail(() => toast('Erreur réseau lors de la sauvegarde.', false));
}

// ── Recherche laposte après édition CP ou Ville ───────────────────────────────
function rechercherLaPoste(idx) {
    const cp    = String(lignes[idx].cp    ?? '').trim();
    const ville = String(lignes[idx].ville ?? '').trim();
    if (cp === '' && ville === '') return;

    $.post('jugearbitre.php', { action: 'recherche_laposte', cp, ville }, function (res) {
        if (!res.ok && !res.multi) {
            // Non trouvé : effacer id_laposte, garder les valeurs saisies, fond rouge
            lignes[idx].id_laposte = null;
            renderGrille();
            toast(`Commune non trouvée (${cp} ${ville}).`, false);
            return;
        }
        if (res.multi) {
            afficherSuggestions(idx, res.suggestions);
            return;
        }
        // Trouvé : mettre à jour cp, ville, id_laposte
        lignes[idx].id_laposte = res.id_laposte;
        lignes[idx].cp         = res.cp;
        lignes[idx].ville      = res.ville;
        renderGrille();
        // Sauvegarde immédiate si le JA existe déjà en base (id > 0)
        sauvegarderLaPoste(idx);
    }, 'json').fail(() => toast('Erreur réseau lors de la recherche laposte.', false));
}

// ── Popup de suggestions ──────────────────────────────────────────────────────
function afficherSuggestions(idx, suggestions) {
    $('#laposte-popup').remove();
    const $pop = $('<div id="laposte-popup">').css({
        position: 'fixed', top: '50%', left: '50%',
        transform: 'translate(-50%,-50%)',
        background: '#fff', border: '1px solid #c8d4e8',
        borderRadius: '8px', boxShadow: '0 8px 32px rgba(0,0,0,.18)',
        zIndex: 9999, minWidth: '280px', maxWidth: '400px',
        maxHeight: '60vh', overflowY: 'auto', padding: '1rem'
    });
    $pop.append('<div style="font-weight:700;margin-bottom:.5rem;color:#1a3a6b">Plusieurs communes trouvées — choisissez :</div>');
    suggestions.forEach(s => {
        $('<button>').text(`${s.cp}  ${s.ville}`)
            .css({ display: 'block', width: '100%', textAlign: 'left', padding: '.35rem .6rem',
                   margin: '.15rem 0', border: '1px solid #c8d4e8', borderRadius: '4px',
                   background: '#f8faff', cursor: 'pointer', fontSize: '.88rem' })
            .on('click', function () {
                lignes[idx].id_laposte = s.id_laposte;
                lignes[idx].cp         = s.cp;
                lignes[idx].ville      = s.ville;
                $('#laposte-popup').remove();
                renderGrille();
                sauvegarderLaPoste(idx);
            })
            .appendTo($pop);
    });
    $('<button>').text('Annuler')
        .css({ marginTop: '.5rem', padding: '.25rem .8rem', background: '#e5e7eb',
               border: 'none', borderRadius: '4px', cursor: 'pointer' })
        .on('click', () => $('#laposte-popup').remove())
        .appendTo($pop);
    $('body').append($pop);
}

// ── Sélection / Edition ───────────────────────────────────────────────────────
function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    $td.closest('tr').addClass('selected');
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

$(document).on('keydown', function (e) {
    if (!cellActive) return;
    const $inner = cellActive.find('.cell-inner');

    if (e.key === 'F2' && $inner.attr('contenteditable') === 'false') {
        e.preventDefault();
        $inner.attr('contenteditable', 'true').trigger('focus');
        const range = document.createRange();
        range.selectNodeContents($inner[0]);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    } else if (e.key === 'Escape') {
        const idx   = +cellActive.attr('data-idx');
        const field = cellActive.attr('data-field');
        $inner.text(lignes[idx]?.[field] ?? '').attr('contenteditable', 'false');
        setStatus('Modification annulée.');
    } else if (e.key === 'Enter' && $inner.attr('contenteditable') === 'true') {
        e.preventDefault();
        validerCellule($inner, cellActive);
    }
});

$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

// Bloquer la saisie non numérique dans Distance
$(document).on('keypress', '.cell-inner[contenteditable="true"]', function (e) {
    const field = $(this).closest('td').attr('data-field');
    if (CHAMPS_NUMERIQUES.includes(field)) {
        if (!/[0-9]/.test(e.key)) { e.preventDefault(); }
    }
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    let   val   = $inner.text().trim();

    // Validation numérique pour Distance
    if (CHAMPS_NUMERIQUES.includes(field)) {
        const n = parseInt(val, 10);
        if (val !== '' && (isNaN(n) || n < 0)) {
            toast('Valeur numérique entière ≥ 0 attendue.', false);
            $inner.text(lignes[idx]?.[field] ?? '');  // restaurer
            return;
        }
        val = val !== '' ? n : null;
    }

    if (lignes[idx]) lignes[idx][field] = val !== '' ? val : null;

    // Si CP ou Ville modifié → relancer la recherche laposte
    if (['cp', 'ville'].includes(field)) {
        rechercherLaPoste(idx);
        return;
    }
    setStatus('Modification locale. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
}

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.post('jugearbitre.php', { action: 'liste', dept: deptFiltre }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map((r, i) => ({
            _idx:            i,
            id:              r.Id_JA,
            nom:             r.Nom,
            prenom:          r.Prenom,
            email:           r.Email,
            telephone:       r.Telephone,
            grade:           r.Grade,
            actif:           +r.Actif,
            id_club:         r.Id_Club,
            nom_club:        r.NomClub ?? '',
            distance_max_km: r.DistanceMaxKm,
            id_laposte:       r.Id_LaPoste,
            defiscalisation:  +r.Defiscalisation,
            nationale:        +r.Nationale,
            nb_dispo:         +r.NbDispo,
            cp:               r.CP    ?? '',
            ville:            r.Ville ?? '',
        }));
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Importer Excel ────────────────────────────────────────────────────────────
$('#btn-importer').on('click', () => $('#file-input').trigger('click'));

$('#file-input').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('action',  'importer_excel');
    fd.append('fichier', file);

    spinner(true);
    $.ajax({
        url: 'jugearbitre.php', type: 'POST',
        data: fd, processData: false, contentType: false, dataType: 'json',
        success(res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            // Les clés PHP sont déjà en minuscules (snake_case) côté import
            lignes = res.data.map((r, i) => Object.assign(r, {
                _idx:           i,
                actif:          +r.actif,
                defiscalisation: r.defiscalisation != null ? +r.defiscalisation : 0,
                nationale:       r.nationale != null ? +r.nationale : 0,
                cp:             r.cp    ?? '',
                ville:          r.ville ?? '',
            }));
            renderGrille();
            toast(`${res.count} JA importé(s) depuis Excel (filtre grade JA*).`);
            setStatus(`${res.count} JA importé(s). Vérifiez les données puis cliquez sur « Mettre à jour la BDD ».`);
        },
        error() { spinner(false); toast("Erreur lors de l'import.", false); }
    });
    this.value = '';
});

// ── Mettre à jour la BDD ──────────────────────────────────────────────────────
$('#btn-maj-bdd').on('click', function () {
    if (!lignes.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Mettre à jour la base de données avec ${lignes.length} JA ?`)) return;

    spinner(true);
    $.post('jugearbitre.php', {
        action: 'maj_bdd',
        lignes: JSON.stringify(lignes),
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-ja thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    sortDir   = sortField === f ? (sortDir === 'asc' ? 'desc' : 'asc') : 'asc';
    sortField = f;
    renderGrille();
});

// ── Filtre département ────────────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Toggles filtres ───────────────────────────────────────────────────────────
$('#btn-tous').on('click', function () {
    // Réinitialise tous les filtres
    filtreActif = false; filtreErreursCp = false;
    $('#btn-tous').addClass('active');
    $('#btn-actifs, #btn-erreurs-cp').removeClass('active');
    renderGrille();
});
$('#btn-actifs').on('click', function () {
    filtreActif = !filtreActif;
    $(this).toggleClass('active', filtreActif);
    // Si aucun filtre actif, revenir à "Tous"
    if (!filtreActif && !filtreErreursCp) $('#btn-tous').addClass('active');
    else $('#btn-tous').removeClass('active');
    renderGrille();
});
$('#btn-erreurs-cp').on('click', function () {
    filtreErreursCp = !filtreErreursCp;
    $(this).toggleClass('active', filtreErreursCp);
    if (!filtreActif && !filtreErreursCp) $('#btn-tous').addClass('active');
    else $('#btn-tous').removeClass('active');
    renderGrille();
});

// ── Bouton "Ouvrir la page de disponibilité du JA" ───────────────────────────
$(document).on('click', '.btn-lien-dispo', function (e) {
    e.stopPropagation();
    const id = $(this).data('id');
    $.getJSON('disponibilite_ja.php', { action: 'token', id: id }, function (r) {
        if (!r.ok) { alert('Erreur lors de la génération du lien.'); return; }
        window.open(r.url, '_blank');
    });
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
