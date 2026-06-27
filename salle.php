<?php
/**
 * NIJAC – Gestion des salles (E005)
 *
 * Référencement des salles de compétition avec leur adresse, code postal/ville
 * et rattachement à un club. Permet de désigner la salle principale d'un club.
 * Les coordonnées GPS sont récupérées via la table laposte.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Sécurité ──────────────────────────────────────────────────────────────────
require __DIR__ . '/includes/auth_required.php';
$moi     = $_SESSION['utilisateur'];
$isAdmin = !empty($moi['is_admin']);

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            // Auto-migration colonnes Cp et Ville dans Salle
            $colsSalle = array_column($pdo->query('SHOW COLUMNS FROM Salle')->fetchAll(), 'Field');
            if (!in_array('Cp', $colsSalle))    $pdo->exec("ALTER TABLE Salle ADD COLUMN Cp VARCHAR(10) NULL AFTER Adresse");
            if (!in_array('Ville', $colsSalle)) $pdo->exec("ALTER TABLE Salle ADD COLUMN Ville VARCHAR(100) NULL AFTER Cp");

            $sql = 'SELECT s.Id_Salle, COALESCE(s.Nom, cl.Nom) AS Nom, s.Adresse, s.Id_Laposte, s.Id_Club,
                           s.EstPrincipale, s.Cp, s.Ville, cl.Nom AS NomClub,
                           COALESCE(
                               NULLIF(CONCAT(lp.CodePostal, \' \', lp.Nom), \' \'),
                               NULLIF(CONCAT(COALESCE(s.Cp,\'\'), \' \', COALESCE(s.Ville,\'\')), \' \')
                           ) AS CpVille
                    FROM Salle s
                    LEFT JOIN Club    cl ON cl.Id_Club    = s.Id_Club
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte';
            $params = [];

            if (!$isAdmin) {
                // Nominateur : restreint à son département + ceux associés (configuration.php)
                $depts = getDepartementsAutorises($moi['id_departement'] ?? null);
                if (!$depts) {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'data' => []]);
                    exit;
                }
                $deptPh = implode(',', array_fill(0, count($depts), '?'));
                $sql .= " WHERE (LEFT(lp.CodePostal, 2) IN ($deptPh) OR LEFT(s.Cp, 2) IN ($deptPh))";
                foreach ($depts as $d) $params[] = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
                foreach ($depts as $d) $params[] = str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            } elseif (isset($_POST['dept']) && $_POST['dept'] !== '') {
                // Administrateur avec filtre optionnel (département exact, sans association)
                $cp2 = str_pad((string)$_POST['dept'], 2, '0', STR_PAD_LEFT);
                $sql .= ' WHERE (LEFT(lp.CodePostal, 2) = ? OR LEFT(s.Cp, 2) = ?)';
                $params = [$cp2, $cp2];
            }
            $sql .= ' ORDER BY cl.Nom, s.Nom';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Résolution CP → laposte ───────────────────────────────────────
        if ($action === 'recherche_laposte') {
            $cp    = trim($_POST['cp']    ?? '');
            $ville = mb_strtoupper(trim($_POST['ville'] ?? ''), 'UTF-8');
            // Normalise : tirets et apostrophes → espace, espaces multiples réduits
            $villeN = preg_replace('/\s+/', ' ', str_replace(['-', "'", "'"], ' ', $ville));

            if ($cp === '' && $ville === '') {
                ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'CP et ville vides.']); exit;
            }
            if ($cp !== '' && $ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal=? AND UPPER(REPLACE(REPLACE(REPLACE(Nom,'-',' '),''',' '),'\\'','  ')) LIKE ? LIMIT 1");
                $stmt->execute([$cp, $villeN . '%']);
                $row = $stmt->fetch();
                if ($row) { ob_end_clean(); echo json_encode(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]); exit; }
            }
            if ($cp !== '') {
                $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal=? ORDER BY Nom');
                $stmt->execute([$cp]);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) { ob_end_clean(); echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]); exit; }
                if (count($rows) > 1) {
                    $sugg = array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);
                    ob_end_clean(); echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]); exit;
                }
            }
            if ($ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE UPPER(REPLACE(REPLACE(Nom,'-',' '),''',' ')) LIKE ? ORDER BY CodePostal, Nom LIMIT 20");
                $stmt->execute([$villeN . '%']);
                $rows = $stmt->fetchAll();
                if (count($rows) === 1) { ob_end_clean(); echo json_encode(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]); exit; }
                if (count($rows) > 1) {
                    $sugg = array_map(fn($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows);
                    ob_end_clean(); echo json_encode(['ok' => true, 'multi' => true, 'suggestions' => $sugg]); exit;
                }
            }
            ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Commune non trouvée.']); exit;
        }

        if ($action === 'lookup_laposte') {
            $cp = trim($_POST['cp'] ?? '');
            if ($cp === '') { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'CP vide.']); exit; }
            $stmt = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
            $stmt->execute([$cp]);
            $rows = $stmt->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'communes' => array_map(fn($r) => [
                'id'  => $r['Id_LaPoste'],
                'cp'  => $r['CodePostal'],
                'nom' => $r['Nom'],
            ], $rows)]);
            exit;
        }

        // ── Max Id_Salle actuel ────────────────────────────────────────────
        if ($action === 'max_id') {
            $max = (int)$pdo->query('SELECT COALESCE(MAX(Id_Salle), 0) FROM Salle')->fetchColumn();
            ob_end_clean();
            echo json_encode(['ok' => true, 'max' => $max]);
            exit;
        }

        // ── Liste clubs pour le sélecteur ──────────────────────────────────
        if ($action === 'liste_clubs') {
            $rows = $pdo->query('SELECT Id_Club, Nom FROM Club ORDER BY Nom')->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Actions réservées aux administrateurs ──────────────────────────
        if (!$isAdmin && in_array($action, ['importer_excel', 'sauvegarder', 'supprimer'], true)) {
            ob_end_clean();
            echo json_encode(['ok' => false, 'msg' => 'Accès refusé.']);
            exit;
        }

        // ── Importer Excel ─────────────────────────────────────────────────
        if ($action === 'importer_excel') {
            if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
                exit;
            }
            if (strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
                exit;
            }

            $spreadsheet = IOFactory::load($_FILES['fichier']['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = $sheet->getHighestRow();

            // Préparer le lookup LaPoste (CodePostal + Nom → Id_LaPoste)
            $stmtLaposte = $pdo->prepare(
                'SELECT Id_LaPoste FROM laposte WHERE CodePostal = ? AND Nom = ? LIMIT 1'
            );
            $stmtLaposteCp = $pdo->prepare(
                'SELECT Id_LaPoste FROM laposte WHERE CodePostal = ? LIMIT 1'
            );

            $lignes      = [];
            $clubsVus    = []; // suivi : premier club → salle principale
            // Colonnes : A=Id_Club, AN=Nom, AP+AQ=Adresse, AR=CodePostal, AS=Ville
            for ($row = 3; $row <= $maxRow; $row++) {
                $idClub  = trim((string)$sheet->getCell('A'  . $row)->getValue());
                $nom     = trim((string)$sheet->getCell('AN' . $row)->getValue());
                $adr1    = trim((string)$sheet->getCell('AP' . $row)->getValue());
                $adr2    = trim((string)$sheet->getCell('AQ' . $row)->getValue());
                $cp      = trim((string)$sheet->getCell('AR' . $row)->getValue());
                $ville   = trim((string)$sheet->getCell('AS' . $row)->getValue());

                // Pas d'enregistrement vide
                if ($nom === '') continue;

                // Concaténer adresse
                $adresse = trim($adr1 . ($adr1 !== '' && $adr2 !== '' ? ' ' : '') . $adr2) ?: null;

                // Résoudre Id_LaPoste
                $idLaposte = null;
                if ($cp !== '') {
                    $stmtLaposte->execute([$cp, mb_strtoupper($ville, 'UTF-8')]);
                    $found = $stmtLaposte->fetchColumn();
                    if ($found === false) {
                        $stmtLaposteCp->execute([$cp]);
                        $found = $stmtLaposteCp->fetchColumn();
                    }
                    $idLaposte = $found !== false ? (int)$found : null;
                }

                $idClubInt = $idClub !== '' ? (int)$idClub : null;

                // Première salle trouvée pour ce club = principale
                $estPrincipale = 0;
                if ($idClubInt !== null) {
                    if (!isset($clubsVus[$idClubInt])) {
                        $clubsVus[$idClubInt] = true;
                        $estPrincipale = 1;
                    }
                }

                $lignes[] = [
                    'id_salle'      => 0,
                    'nom'           => mb_strtoupper($nom, 'UTF-8'),
                    'adresse'       => $adresse,
                    'id_laposte'    => $idLaposte,
                    'cp_ville'      => trim("$cp $ville"),
                    'id_club'       => $idClubInt,
                    'nom_club'      => '',
                    'est_principale'=> $estPrincipale,
                ];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
            exit;
        }

        // ── Sauvegarder (INSERT ou UPDATE) ─────────────────────────────────
        if ($action === 'sauvegarder') {
            $lignes  = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Données invalides.']);
                exit;
            }

            $vider = !empty($_POST['vider']);
            if ($vider) {
                $pdo->exec('DELETE FROM Salle');
                $pdo->exec('ALTER TABLE Salle AUTO_INCREMENT = 1');
            }

            $inserts = 0;
            $updates = 0;
            $erreurs = [];

            $stmtInsert = $pdo->prepare(
                'INSERT INTO Salle (Nom, Adresse, Cp, Ville, Id_Laposte, Id_Club, EstPrincipale)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmtUpdate = $pdo->prepare(
                'UPDATE Salle SET Nom=?, Adresse=?, Cp=?, Ville=?, Id_Laposte=?, Id_Club=?, EstPrincipale=?
                 WHERE Id_Salle=?'
            );

            foreach ($lignes as $l) {
                $id            = (int)($l['id_salle']      ?? 0);
                $nom           = trim($l['nom']            ?? '');
                $adresse       = trim($l['adresse']        ?? '') ?: null;
                $cp            = trim($l['cp']             ?? '') ?: null;
                $ville         = trim($l['ville']          ?? '') ?: null;
                $idLaposte     = $l['id_laposte']  !== '' && $l['id_laposte'] !== null ? (int)$l['id_laposte'] : null;
                $idClub        = ($l['id_club'] ?? '') !== '' ? trim($l['id_club']) : null;
                $estPrincipale = !empty($l['est_principale']) ? 1 : 0;

                if ($nom === '') {
                    $erreurs[] = "Ligne id=$id : le nom est obligatoire.";
                    continue;
                }

                try {
                    if ($id === 0) {
                        $stmtInsert->execute([$nom, $adresse, $cp, $ville, $idLaposte, $idClub, $estPrincipale]);
                        $inserts++;
                    } else {
                        $stmtUpdate->execute([$nom, $adresse, $cp, $ville, $idLaposte, $idClub, $estPrincipale, $id]);
                        $updates++;
                    }
                } catch (PDOException $ex) {
                    $erreurs[] = "Ligne id=$id : " . $ex->getMessage();
                }
            }

            $msg = "Mise à jour terminée : $inserts insérée(s), $updates modifiée(s).";
            if ($erreurs) $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            ob_end_clean();
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg]);
            exit;
        }

        // ── Liste clubs FFTT d'un département (région uniquement) ─────────
        if ($action === 'get_clubs_dept_fftt') {
            if (!$isAdmin) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Accès refusé.']); exit; }
            $dep = trim($_POST['dep'] ?? '');
            if ($dep === '') { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Département manquant.']); exit; }

            $api   = getFfttApi();
            $clubs = $api->getClubsDepartement($dep);

            // Garder seulement les clubs déjà présents dans notre BDD
            $numeros = array_values(array_filter(array_map(fn($c) => $c['numero'] ?? $c['numclu'] ?? '', $clubs)));
            if ($numeros) {
                $ph     = implode(',', array_fill(0, count($numeros), '?'));
                $stmtEx = $pdo->prepare("SELECT Id_Club FROM Club WHERE Id_Club IN ($ph)");
                $stmtEx->execute($numeros);
                $existants = array_column($stmtEx->fetchAll(), 'Id_Club');
            } else {
                $existants = [];
            }

            $result = array_values(array_filter(
                array_map(fn($c) => [
                    'numero' => $c['numero'] ?? $c['numclu'] ?? '',
                    'nom'    => $c['nom'] ?? '',
                ], $clubs),
                fn($c) => $c['numero'] !== '' && in_array($c['numero'], $existants, true)
            ));

            ob_end_clean();
            echo json_encode(['ok' => true, 'clubs' => $result]);
            exit;
        }

        // ── Synchroniser salles d'un club depuis FFTT ────────────────────
        if ($action === 'sync_fftt_salle') {
            if (!$isAdmin) { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Accès refusé.']); exit; }
            $numClub = trim($_POST['num_club'] ?? '');
            if ($numClub === '') { ob_end_clean(); echo json_encode(['ok' => false, 'msg' => 'Numéro de club manquant.']); exit; }

            set_time_limit(30);
            $api    = getFfttApi();
            $detail = $api->getClubDetail($numClub);
            if (empty($detail)) {
                ob_end_clean();
                echo json_encode(['ok' => true, 'op' => null, 'msg' => "Club $numClub : aucune donnée FFTT"]);
                exit;
            }

            // Normaliser : scalaire → tableau à 1 élément, tableau → tel quel, [] → tableau vide
            $toArr = fn($v) => is_array($v) ? (isset($v[0]) ? $v : []) : ($v !== '' && $v !== null ? [(string)$v] : []);

            $nomsalles  = $toArr($detail['nomsalle']      ?? '');
            $adrs1      = $toArr($detail['adressesalle1'] ?? '');
            $adrs2      = $toArr($detail['adressesalle2'] ?? '');
            $adrs3      = $toArr($detail['adressesalle3'] ?? '');
            $cps        = $toArr($detail['codepsalle']    ?? '');
            $villes     = $toArr($detail['villesalle']    ?? '');

            $nbSalles = count($nomsalles);
            if ($nbSalles === 0) {
                // Aucune salle dans l'API : vider les champs salle de l'enregistrement existant
                $stmtChkP = $pdo->prepare('SELECT Id_Salle FROM Salle WHERE Id_Club=? AND EstPrincipale=1 LIMIT 1');
                $stmtChkP->execute([$numClub]);
                $idSalleExist = $stmtChkP->fetchColumn();
                if ($idSalleExist) {
                    $nomClubRow = $pdo->prepare('SELECT Nom FROM Club WHERE Id_Club=?');
                    $nomClubRow->execute([$numClub]);
                    $nomClub = $nomClubRow->fetchColumn() ?: null;
                    $pdo->prepare('UPDATE Salle SET Nom=?, Adresse=NULL, Cp=NULL, Ville=NULL, Id_Laposte=NULL WHERE Id_Salle=?')
                        ->execute([$nomClub, $idSalleExist]);
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'op' => 'vide', 'msg' => "Club $numClub : aucune salle FFTT — champs vidés"]);
                } else {
                    ob_end_clean();
                    echo json_encode(['ok' => true, 'op' => null, 'msg' => "Club $numClub : aucune salle dans FFTT"]);
                }
                exit;
            }

            $stmtExact  = $pdo->prepare("SELECT Id_LaPoste FROM laposte WHERE CodePostal=? AND UPPER(Nom)=? LIMIT 1");
            $stmtCpOnly = $pdo->prepare('SELECT Id_LaPoste FROM laposte WHERE CodePostal=? LIMIT 2');
            $stmtUpd    = $pdo->prepare('UPDATE Salle SET Nom=?, Adresse=COALESCE(?,Adresse), Cp=COALESCE(?,Cp), Ville=COALESCE(?,Ville), Id_Laposte=COALESCE(?,Id_Laposte), EstPrincipale=? WHERE Id_Salle=?');
            $stmtIns    = $pdo->prepare('INSERT INTO Salle (Nom, Adresse, Cp, Ville, Id_Laposte, Id_Club, EstPrincipale) VALUES (?,?,?,?,?,?,?)');

            // Récupérer les salles existantes du club triées par Id_Salle :
            // index 0 = principale (EstPrincipale=1), puis secondaires dans l'ordre de création
            $stmtExist = $pdo->prepare(
                'SELECT Id_Salle FROM Salle WHERE Id_Club=? ORDER BY EstPrincipale DESC, Id_Salle ASC'
            );
            $stmtExist->execute([$numClub]);
            $idsSallesExist = array_column($stmtExist->fetchAll(), 'Id_Salle');

            $ops = []; $cntNew = 0; $cntMaj = 0;

            for ($i = 0; $i < $nbSalles; $i++) {
                $nom        = trim($nomsalles[$i] ?? '');
                if ($nom === '') continue;
                $adr1       = trim($adrs1[$i] ?? '');
                $adr2Raw    = $adrs2[$i] ?? '';
                $adr2       = is_array($adr2Raw) ? '' : trim((string)$adr2Raw);
                $adr3Raw    = $adrs3[$i] ?? '';
                $adr3       = is_array($adr3Raw) ? '' : trim((string)$adr3Raw);
                $adresse    = trim(implode(' ', array_filter([$adr1, $adr2, $adr3]))) ?: null;
                $cp         = trim($cps[$i]    ?? '');
                $ville      = mb_strtoupper(trim($villes[$i] ?? ''), 'UTF-8');
                $estPrinc   = ($i === 0) ? 1 : 0;

                // Lookup Id_LaPoste
                $idLaPoste = null;
                if ($cp !== '') {
                    $stmtExact->execute([$cp, $ville]);
                    $idLaPoste = $stmtExact->fetchColumn() ?: null;
                    if (!$idLaPoste) {
                        $stmtCpOnly->execute([$cp]);
                        $lpRows = $stmtCpOnly->fetchAll();
                        if (count($lpRows) === 1) $idLaPoste = $lpRows[0]['Id_LaPoste'];
                    }
                }

                // Chercher la salle existante par rang (évite le problème des anciens "Array")
                $idSalle = $idsSallesExist[$i] ?? null;

                if ($idSalle) {
                    $stmtUpd->execute([$nom, $adresse, $cp ?: null, $ville ?: null, $idLaPoste, $estPrinc, $idSalle]);
                    $ops[] = "Mise à jour : $nom ($cp $ville)" . ($estPrinc ? ' [principale]' : '');
                    $cntMaj++;
                } else {
                    $stmtIns->execute([$nom, $adresse, $cp ?: null, $ville ?: null, $idLaPoste, $numClub, $estPrinc]);
                    $ops[] = "Créée : $nom ($cp $ville)" . ($estPrinc ? ' [principale]' : '');
                    $cntNew++;
                }
            }

            ob_end_clean();
            echo json_encode([
                'ok'      => true,
                'op'      => $cntNew > 0 ? 'new' : ($cntMaj > 0 ? 'maj' : null),
                'cnt_new' => $cntNew,
                'cnt_maj' => $cntMaj,
                'nb'      => $nbSalles,
                'nom_salle' => $nomsalles[0] ?? '',
                'cp'        => $cps[0] ?? '',
                'ville'     => $villes[0] ?? '',
                'ops'       => $ops,
            ]);
            exit;
        }

        // ── Supprimer ──────────────────────────────────────────────────────
        if ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === 0) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Id invalide.']);
                exit;
            }
            $pdo->prepare('DELETE FROM Salle WHERE Id_Salle = ?')->execute([$id]);
            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Salle supprimée.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] salle.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur Excel : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] salle.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet     = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement    = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin    = !empty($moi['change_login']);
$isAdminJs      = $isAdmin ? 'true' : 'false';
$deptUserJs     = json_encode($moi['id_departement'] ?? null);

$deptActifs = getDeptActifs();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Salles (E005)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="asset/css/nijac.css">

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
            width: 250px;
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

        #tbl-salles {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 800px;
        }

        #tbl-salles thead th {
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
        #tbl-salles thead th:hover { background: #d4dff0; }
        #tbl-salles thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-salles thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-salles thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-salles thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #tbl-salles tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-salles tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-salles tbody tr:hover   { background: #dce8f8; }
        #tbl-salles tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-salles tbody tr.new-row  { background: #fffbe6 !important; }
        #tbl-salles tbody td { border: 1px solid #e0e8f0; padding: 0; }

        /* Cellule éditable */
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

        td.col-id, td.col-nom-club { background: #f0f4fa; }
        td.col-id .cell-inner, td.col-nom-club .cell-inner { color: #6b7280; font-style: italic; }

        /* Checkbox principale */
        td.col-principale { text-align: center; vertical-align: middle; }
        td.col-principale input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; }



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

<?php $pageIcon = 'bi-building-fill'; $pageTitle = 'Gestion des salles'; $pageCode = 'E005'; $backUrl = $isAdmin ? 'admin_menu.php' : 'Nominateur/menu.php'; require __DIR__ . '/includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
<?php if ($isAdmin): ?>
    <button class="menu-item" id="btn-sync-fftt" data-bs-toggle="modal" data-bs-target="#modal-sync-fftt">
        <i class="bi bi-cloud-arrow-down-fill"></i>Synchroniser depuis FFTT
    </button>
    <button class="menu-item" id="btn-ajouter">
        <i class="bi bi-plus-circle"></i>Ajouter
    </button>
    <button class="menu-item danger" id="btn-supprimer">
        <i class="bi bi-trash3"></i>Supprimer
    </button>
    <button class="menu-item" id="btn-sauvegarder">
        <i class="bi bi-database-fill-up"></i>Enregistrer dans la Base de données
    </button>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
    <!-- Filtre département (admin uniquement) -->
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
<?php else: ?>
    <span style="padding:.2rem .6rem; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; font-size:.82rem; color:#856404;">
        <i class="bi bi-eye-fill me-1"></i>Consultation — département <?= $departement ?>
    </span>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 salle(s)</span>
    <span style="flex:1"></span>
<?php endif; ?>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-salles">
        <thead>
            <tr>
                <th style="width:70px"  data-field="id_salle">N°<span class="sort-icon"></span></th>
                <th style="width:80px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:210px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:260px" data-field="adresse">Adresse<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="cp">Code postal<span class="sort-icon"></span></th>
                <th style="width:170px" data-field="ville">Ville<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="est_principale">Principale<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<?php $statusInitial = 'Prêt.'; ?>

<!-- Toast -->
<div id="toast-container"></div>

<!-- Modale saisie CP / Ville -->
<div class="modal fade" id="modal-cp-ville" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#0d6efd;color:#fff;">
        <h6 class="modal-title mb-0"><i class="bi bi-geo-alt-fill me-1"></i>Code postal / Ville</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body pb-2">
        <div class="input-group input-group-sm mb-1">
          <span class="input-group-text">CP</span>
          <input type="text" id="mcv-cp" class="form-control" placeholder="76000" maxlength="10" style="max-width:90px">
          <input type="text" id="mcv-ville" class="form-control text-uppercase" placeholder="ROUEN">
        </div>
        <div id="mcv-msg" class="form-text" style="min-height:1.2em;"></div>
        <div id="mcv-suggestions" style="display:none;">
          <div class="fw-semibold text-primary small mb-1">Plusieurs communes — choisissez :</div>
          <div id="mcv-suggestions-list" class="d-flex flex-wrap gap-1"></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-success btn-sm" id="btn-mcv-ok"><i class="bi bi-check-lg me-1"></i>Valider</button>
      </div>
    </div>
  </div>
</div>

<!-- Modale Synchronisation FFTT -->
<div class="modal fade" id="modal-sync-fftt" tabindex="-1" aria-labelledby="modal-sync-fftt-titre" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:#0d6efd;color:#fff;">
        <h5 class="modal-title" id="modal-sync-fftt-titre"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Synchroniser salles principales depuis FFTT</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="btn-fermer-sync-fftt"></button>
      </div>
      <div class="modal-body">

        <!-- Étape 1 : choix département -->
        <div id="sync-fftt-step1">
          <p class="text-muted small mb-3">
            Seuls les clubs <strong>déjà présents dans votre base</strong> et appartenant au département choisi
            sont traités. Pour chaque club, la salle principale est créée ou mise à jour via <code>xml_club_detail</code>.
          </p>
          <div class="input-group mb-3" style="max-width:420px">
            <label class="input-group-text" for="sync-fftt-dept"><i class="bi bi-map me-1"></i>Département</label>
            <select id="sync-fftt-dept" class="form-select">
              <option value="">— Choisir —</option>
              <?php foreach ($deptActifs as $d): ?>
              <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-primary" id="btn-lancer-sync-fftt">
            <i class="bi bi-play-fill me-1"></i>Lancer la synchronisation
          </button>
        </div>

        <!-- Étape 2 : progression -->
        <div id="sync-fftt-step2" style="display:none;">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span id="sync-fftt-label" class="fw-semibold small text-primary">Récupération des clubs…</span>
            <span id="sync-fftt-pct" class="small text-muted">0 %</span>
          </div>
          <div class="progress mb-3" style="height:18px;">
            <div id="sync-fftt-bar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary"
                 style="width:0%" role="progressbar"></div>
          </div>
          <div class="row text-center mb-3">
            <div class="col-3">
              <div class="fw-bold fs-5 text-primary"  id="sync-cnt-clubs">0</div>
              <div class="small text-muted">Clubs traités</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-success"  id="sync-cnt-new">0</div>
              <div class="small text-muted">Salles créées</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-info"     id="sync-cnt-maj">0</div>
              <div class="small text-muted">Salles mises à jour</div>
            </div>
            <div class="col-3">
              <div class="fw-bold fs-5 text-warning"  id="sync-cnt-erreurs">0</div>
              <div class="small text-muted">Erreurs</div>
            </div>
          </div>
          <div id="sync-fftt-log" style="max-height:200px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

        <!-- Étape 3 : résumé -->
        <div id="sync-fftt-step3" style="display:none;">
          <div class="alert alert-success mb-3" id="sync-fftt-resume"></div>
          <div id="sync-fftt-log-final" style="max-height:260px;overflow-y:auto;font-size:.78rem;background:#f8fafc;border:1px solid #e0e8f0;border-radius:4px;padding:.5rem;font-family:monospace;"></div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
      </div>
    </div>
  </div>
</div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const IS_ADMIN  = <?= $isAdminJs ?>;
const DEPT_USER = <?= $deptUserJs ?>;

let lignes     = [];
let clubs      = [];   // [{id_club, nom}]
let cellActive = null;
let rowActive  = null; // idx de la ligne sélectionnée
let sortField  = 'nom_club';
let sortDir    = 'asc';
let searchTerm = '';
let deptFiltre = IS_ADMIN ? '' : (DEPT_USER ?? '');

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
    let result = term
        ? lignes.filter(l =>
            String(l.id_salle   ?? '').toLowerCase().includes(term) ||
            String(l.nom        ?? '').toLowerCase().includes(term) ||
            String(l.adresse    ?? '').toLowerCase().includes(term) ||
            String(l.cp         ?? '').toLowerCase().includes(term) ||
            String(l.ville      ?? '').toLowerCase().includes(term) ||
            String(l.id_club    ?? '').toLowerCase().includes(term) ||
            String(l.nom_club   ?? '').toLowerCase().includes(term))
        : [...lignes];

    const numFields = ['id_salle', 'id_laposte'];
    result.sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        if (sortField === 'est_principale') {
            return sortDir === 'asc' ? a.est_principale - b.est_principale : b.est_principale - a.est_principale;
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function majEnteteTri() {
    $('#tbl-salles thead th').each(function () {
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
        const msg = searchTerm ? 'Aucun résultat.' : 'Aucune salle.';
        $body.append(`<tr><td colspan="8" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} salle(s).` : 'Aucune salle enregistrée.');
        return;
    }

    affichees.forEach((l) => {
        const idx = lignes.indexOf(l);
        const isNew = !!l._nouveau;
        const $tr = $('<tr>').attr('data-idx', idx).toggleClass('new-row', isNew);

        $tr.append(makeTd(isNew ? '(nouveau)' : l.id_salle, idx, 'id_salle', true));
        $tr.append(makeTd(l.id_club  ?? '', idx, 'id_club',  true));
        $tr.append(makeTd(l.nom_club ?? '', idx, 'nom_club', true));
        $tr.append(makeTd(l.nom,        idx, 'nom',        false));
        $tr.append(makeTd(l.adresse,    idx, 'adresse',    false));
        $tr.append(makeCpTd(l, idx));
        $tr.append(makeVilleTd(l, idx));
        $tr.append(makePrincipaleTd(l, idx));

        $tr.on('click', function () { selectionnerLigne(idx); });
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}` : `${lignes.length} salle(s)`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    $('#lbl-count').text(`${lignes.length} salle(s)`);
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? `col-${field.replace('_','-')}` : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        $td.on('click', function (e) { e.stopPropagation(); selectionnerCellule($(this)); });
    }
    return $td;
}


// ── Modal CP / Ville ──────────────────────────────────────────────────────────
let mcvIdx = null;
let mcvIdLaPoste = null;
let _modalCpVille = null;
function getModalCpVille() {
    if (!_modalCpVille) _modalCpVille = new bootstrap.Modal(document.getElementById('modal-cp-ville'));
    return _modalCpVille;
}

function ouvrirModalCpVille(idx) {
    mcvIdx = idx;
    mcvIdLaPoste = lignes[idx].id_laposte ?? null;
    $('#mcv-cp').val(lignes[idx].cp ?? '');
    $('#mcv-ville').val(lignes[idx].ville ?? '');
    $('#mcv-msg').text('').css('color', '');
    $('#mcv-suggestions').hide();
    $('#mcv-suggestions-list').empty();
    getModalCpVille().show();
    setTimeout(() => $('#mcv-cp').trigger('focus'), 300);
}

function mcvRechercher() {
    const cp    = $('#mcv-cp').val().trim();
    const ville = $('#mcv-ville').val().trim();
    if (!cp && !ville) return;
    $('#mcv-suggestions').hide();
    $('#mcv-suggestions-list').empty();
    $.post('salle.php', { action: 'recherche_laposte', cp, ville }, function (res) {
        if (!res.ok) {
            $('#mcv-msg').text(res.msg ?? 'Commune non trouvée.').css('color', '#c00');
            mcvIdLaPoste = null;
            return;
        }
        if (res.multi) {
            $('#mcv-msg').text('').css('color', '');
            const $list = $('#mcv-suggestions-list').empty();
            res.suggestions.forEach(s => {
                $('<button class="btn btn-sm btn-outline-primary">')
                    .text(`${s.cp} ${s.ville}`)
                    .on('click', function () {
                        $('#mcv-cp').val(s.cp);
                        $('#mcv-ville').val(s.ville);
                        mcvIdLaPoste = s.id_laposte;
                        $('#mcv-msg').text(`✓ ${s.cp} ${s.ville}`).css('color', '#065f46');
                        $('#mcv-suggestions').hide();
                    }).appendTo($list);
            });
            $('#mcv-suggestions').show();
            mcvIdLaPoste = null;
        } else {
            $('#mcv-cp').val(res.cp);
            $('#mcv-ville').val(res.ville);
            mcvIdLaPoste = res.id_laposte;
            $('#mcv-msg').text(`✓ ${res.cp} ${res.ville}`).css('color', '#065f46');
        }
    }, 'json').fail(() => $('#mcv-msg').text('Erreur réseau.').css('color', '#c00'));
}

$('#mcv-cp, #mcv-ville').on('blur', function () { mcvRechercher(); });
$('#mcv-cp').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('#mcv-ville').trigger('focus'); } });
$('#mcv-ville').on('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); mcvRechercher(); } });

$('#btn-mcv-ok').on('click', function () {
    if (mcvIdx === null) return;
    const cp    = $('#mcv-cp').val().trim() || null;
    const ville = $('#mcv-ville').val().trim().toUpperCase() || null;
    lignes[mcvIdx].cp         = cp;
    lignes[mcvIdx].ville      = ville;
    lignes[mcvIdx].id_laposte = mcvIdLaPoste;
    getModalCpVille().hide();
    renderGrille();
    setStatus(cp ? `CP/Ville mis à jour : ${cp} ${ville ?? ''}.` : 'CP/Ville effacés.');
});

function makeVilleTd(l, idx) {
    const $td  = $('<td>').attr('data-idx', idx).attr('data-field', 'ville');
    const $div = $('<div class="cell-inner">').text(l.ville ?? '');
    $td.append($div);
    if (IS_ADMIN) {
        $td.css('cursor', 'pointer').on('click', function (e) { e.stopPropagation(); ouvrirModalCpVille(idx); });
    }
    return $td;
}

function makeCpTd(l, idx) {
    const $td  = $('<td>').attr('data-idx', idx).attr('data-field', 'cp');
    const $div = $('<div class="cell-inner">').text(l.cp ?? '');
    $td.append($div);
    if (IS_ADMIN) {
        $td.css('cursor', 'pointer').on('click', function (e) { e.stopPropagation(); ouvrirModalCpVille(idx); });
    }
    return $td;
}

function makePrincipaleTd(l, idx) {
    const $td  = $('<td class="col-principale">').attr('data-idx', idx).attr('data-field', 'est_principale');
    const $cb  = $('<input type="checkbox">').prop('checked', !!l.est_principale);
    $cb.on('change', function () {
        lignes[idx].est_principale = this.checked ? 1 : 0;
        setStatus('Modification locale. Cliquez sur « Enregistrer » pour sauvegarder.');
    });
    $td.append($cb);
    return $td;
}

// ── Sélection ─────────────────────────────────────────────────────────────────
function selectionnerLigne(idx) {
    rowActive = idx;
    $('#tbody-grille tr').removeClass('selected');
    $(`#tbody-grille tr[data-idx="${idx}"]`).addClass('selected');
}

function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    const idx = +$td.attr('data-idx');
    selectionnerLigne(idx);
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

// ── Clavier ───────────────────────────────────────────────────────────────────
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
        if (cellActive.attr('data-field') === 'cp') {
            $inner.trigger('blur');
        } else {
            validerCellule($inner, cellActive);
        }
    }
});

$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    const val   = $inner.text().trim();
    if (lignes[idx]) {
        lignes[idx][field] = val !== '' ? val : null;
    }
    setStatus('Modification locale. Cliquez sur « Enregistrer » pour sauvegarder.');
}

// ── Charger ───────────────────────────────────────────────────────────────────
function chargerClubs() {
    return $.post('salle.php', { action: 'liste_clubs' }, function (res) {
        if (res.ok) clubs = res.data.map(r => ({ id_club: r.Id_Club, nom: r.Nom }));
    }, 'json');
}

function chargerListe() {
    spinner(true);
    chargerClubs().then(() => {
        $.post('salle.php', { action: 'liste', dept: deptFiltre }, function (res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            lignes = res.data.map(r => ({
                id_salle:       r.Id_Salle,
                nom:            r.Nom,
                adresse:        r.Adresse,
                id_laposte:     r.Id_Laposte,
                cp:             r.Cp    ?? '',
                ville:          r.Ville ?? '',
                cp_ville:       r.CpVille ?? '',
                id_club:        r.Id_Club,
                nom_club:       r.NomClub ?? '',
                est_principale: r.EstPrincipale,
            }));
            renderGrille();
        }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
    });
}


// ── Ajouter ───────────────────────────────────────────────────────────────────
$('#btn-ajouter').on('click', function () {
    lignes.push({
        id_salle: 0, nom: '', adresse: null,
        id_laposte: null, id_club: null, nom_club: '', est_principale: 0,
    });
    renderGrille();
    // Sélectionner la nouvelle ligne et activer le champ Nom
    const newIdx = lignes.length - 1;
    selectionnerLigne(newIdx);
    const $tr = $(`#tbody-grille tr[data-idx="${newIdx}"]`);
    $tr[0]?.scrollIntoView({ block: 'nearest' });
    const $nomTd = $tr.find('td[data-field="nom"]');
    selectionnerCellule($nomTd);
    $nomTd.find('.cell-inner').attr('contenteditable', 'true').trigger('focus');
});

// ── Supprimer ─────────────────────────────────────────────────────────────────
$('#btn-supprimer').on('click', function () {
    if (rowActive === null) { toast('Sélectionnez une ligne à supprimer.', false); return; }
    const l = lignes[rowActive];
    if (!l) return;

    const label = l.nom || '(nouvelle ligne)';
    if (!confirm(`Supprimer la salle « ${label} » ?`)) return;

    if (l.id_salle === 0) {
        lignes.splice(rowActive, 1);
        rowActive = null;
        renderGrille();
        return;
    }

    spinner(true);
    $.post('salle.php', { action: 'supprimer', id: l.id_salle }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) { rowActive = null; chargerListe(); }
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-sauvegarder').on('click', function () {
    // Les lignes importées ont un id provisoire > 0 mais ne sont pas encore en BDD
    // On les distingue par le flag _nouveau posé à l'import
    const modifiees = lignes
        .filter(l => l.nom !== '' && l.nom !== null)
        .map(l => l._nouveau ? { ...l, id_salle: 0 } : l);
    if (!modifiees.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Enregistrer ${modifiees.length} salle(s) ?`)) return;

    spinner(true);
    $.post('salle.php', {
        action:  'sauvegarder',
        lignes:  JSON.stringify(modifiees),
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-salles thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    if (sortField === f) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortField = f;
        sortDir   = 'asc';
    }
    renderGrille();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Filtre département (admin) ────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

// ── Synchronisation FFTT ─────────────────────────────────────────────────────
let syncEnCours = false;

function resetSyncFftt() {
    $('#sync-fftt-step1').show();
    $('#sync-fftt-step2, #sync-fftt-step3').hide();
    $('#sync-fftt-dept').val('');
    $('#sync-fftt-bar').css('width', '0%');
    $('#sync-fftt-label').text('Récupération des clubs…');
    $('#sync-fftt-pct').text('0 %');
    ['sync-cnt-clubs','sync-cnt-new','sync-cnt-maj','sync-cnt-erreurs'].forEach(id => $(`#${id}`).text('0'));
    $('#sync-fftt-log, #sync-fftt-log-final').empty();
    syncEnCours = false;
}

$('#modal-sync-fftt').on('hidden.bs.modal', function () {
    if (!syncEnCours) resetSyncFftt();
});

$('#btn-lancer-sync-fftt').on('click', function () {
    const dep = $('#sync-fftt-dept').val();
    if (!dep) { alert('Sélectionnez un département.'); return; }

    syncEnCours = true;
    $('#sync-fftt-step1').hide();
    $('#sync-fftt-step2').show();
    $('#btn-fermer-sync-fftt').prop('disabled', true);

    let cntClubs = 0, cntNew = 0, cntMaj = 0, cntErreurs = 0;
    const logLines = [];

    $.post('salle.php', { action: 'get_clubs_dept_fftt', dep }, function (res) {
        if (!res.ok) {
            alert('Erreur : ' + res.msg);
            resetSyncFftt();
            $('#sync-fftt-step1').show();
            $('#sync-fftt-step2').hide();
            $('#btn-fermer-sync-fftt').prop('disabled', false);
            return;
        }

        const clubs = res.clubs;
        const total = clubs.length;
        let done = 0;

        if (total === 0) {
            syncEnCours = false;
            $('#btn-fermer-sync-fftt').prop('disabled', false);
            $('#sync-fftt-step2').hide();
            $('#sync-fftt-step3').show();
            $('#sync-fftt-resume').html('<i class="bi bi-info-circle-fill me-2"></i>Aucun club de ce département trouvé dans votre base.');
            return;
        }

        $('#sync-fftt-label').text(`0 / ${total} clubs…`);

        function traiterClub() {
            if (done >= total) {
                syncEnCours = false;
                $('#btn-fermer-sync-fftt').prop('disabled', false);
                $('#sync-fftt-step2').hide();
                $('#sync-fftt-step3').show();
                $('#sync-fftt-resume').html(
                    `<i class="bi bi-check-circle-fill me-2"></i>` +
                    `Synchronisation terminée — <strong>${cntClubs}</strong> club(s) traité(s), ` +
                    `<strong>${cntNew}</strong> salle(s) créée(s), ` +
                    `<strong>${cntMaj}</strong> mise(s) à jour` +
                    (cntErreurs ? `, <strong>${cntErreurs}</strong> erreur(s)` : '') + '.'
                );
                $('#sync-fftt-log-final').html(logLines.join(''));
                chargerListe();
                return;
            }

            const club = clubs[done];
            const pct  = Math.round(done / total * 100);
            $('#sync-fftt-bar').css('width', pct + '%');
            $('#sync-fftt-pct').text(pct + ' %');
            $('#sync-fftt-label').text(`${done + 1} / ${total} — ${club.nom}`);

            $.post('salle.php', { action: 'sync_fftt_salle', num_club: club.numero }, function (r) {
                cntClubs++;
                $(`#sync-cnt-clubs`).text(cntClubs);
                if (r.ok && r.op) {
                    cntNew += r.cnt_new ?? 0;
                    cntMaj += r.cnt_maj ?? 0;
                    $(`#sync-cnt-new`).text(cntNew);
                    $(`#sync-cnt-maj`).text(cntMaj);
                    (r.ops ?? []).forEach(op => {
                        const cls  = op.includes('Créée') ? 'text-success' : 'text-info';
                        const line = `<div class="${cls}">[${club.numero}] ${op}</div>`;
                        logLines.push(line);
                        $('#sync-fftt-log').append(line).scrollTop(9999);
                    });
                } else if (r.ok && r.op === 'vide') {
                    const line = `<div class="text-warning">[${club.numero}] ${r.msg}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                } else if (r.ok && !r.op) {
                    const line = `<div class="text-secondary">[${club.numero}] ${r.msg ?? 'Ignoré'}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                } else {
                    cntErreurs++;
                    $(`#sync-cnt-erreurs`).text(cntErreurs);
                    const line = `<div class="text-danger">[${club.numero}] Erreur : ${r.msg ?? 'inconnue'}</div>`;
                    logLines.push(line);
                    $('#sync-fftt-log').append(line).scrollTop(9999);
                }
            }, 'json').fail(() => {
                cntErreurs++;
                $(`#sync-cnt-erreurs`).text(cntErreurs);
            }).always(() => {
                done++;
                setTimeout(traiterClub, 0);
            });
        }

        traiterClub();

    }, 'json').fail(() => {
        alert('Erreur réseau lors de la récupération des clubs.');
        resetSyncFftt();
        $('#sync-fftt-step1').show();
        $('#sync-fftt-step2').hide();
        $('#btn-fermer-sync-fftt').prop('disabled', false);
    });
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
