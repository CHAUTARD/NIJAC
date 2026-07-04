<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des communes / La Poste (E006), portage CI4 de communes.php.
 *
 * Requêtes SQL dynamiques (recherche, pagination, jointure département, import
 * CSV en masse) trop éloignées des méthodes simples du Query Builder CI4 pour
 * un Model classique : réutilise getPDO() directement, comme le fait le
 * fichier legacy — une seule source de vérité pour cette logique.
 */
class CommuneController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/csrf.php';
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'csrfToken'   => csrfToken(),
        ];

        return view('commune_index', $data);
    }

    public function data(): ResponseInterface
    {
        $pdo = getPDO();

        $q          = trim($_GET['q'] ?? '');
        $offset     = max(0, (int) ($_GET['offset'] ?? 0));
        $sansCoords = !empty($_GET['sans_coords'] ?? '');
        $limit      = 500;

        $where  = $sansCoords ? '(lp.Latitude IS NULL OR lp.Longitude IS NULL OR lp.Latitude = 0 OR lp.Longitude = 0)' : '1';
        $params = [];

        if ($q !== '') {
            $like    = '%' . $q . '%';
            $where  .= ' AND (lp.CodePostal LIKE ? OR lp.Nom LIKE ?)';
            $params  = [$like, $like];
        }

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM laposte lp WHERE $where");
        $cntStmt->execute($params);
        $total = (int) $cntStmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT lp.Id_LaPoste, lp.Nom, lp.CodePostal, lp.Latitude, lp.Longitude,
                    LEFT(lp.CodePostal, 2) AS CodeDept, d.nom AS NomDept
             FROM laposte lp
             LEFT JOIN departement d ON d.code = LEFT(lp.CodePostal, 2)
             WHERE $where
             ORDER BY lp.CodePostal, lp.Nom LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        $rows = $stmt->fetchAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows, 'total' => $total, 'offset' => $offset, 'limit' => $limit]);
    }

    public function store(): ResponseInterface
    {
        csrfVerify(true);

        $insee = (int) ($_POST['insee'] ?? 0);
        $nom   = mb_strtoupper(trim($_POST['nom'] ?? ''), 'UTF-8');
        $cp    = trim($_POST['cp'] ?? '');
        $lat   = str_replace(',', '.', trim($_POST['lat'] ?? ''));
        $lon   = str_replace(',', '.', trim($_POST['lon'] ?? ''));

        if ($insee <= 0 || $nom === '' || $cp === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'N° INSEE, nom et code postal sont obligatoires.']);
        }
        if ($lat !== '' && !is_numeric($lat)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Latitude invalide.']);
        }
        if ($lon !== '' && !is_numeric($lon)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Longitude invalide.']);
        }

        $pdo = getPDO();

        $chk = $pdo->prepare('SELECT COUNT(*) FROM laposte WHERE Id_LaPoste = ?');
        $chk->execute([$insee]);
        if ((int) $chk->fetchColumn() > 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Le code INSEE $insee existe déjà."]);
        }

        $pdo->prepare(
            'INSERT INTO laposte (Id_LaPoste, Nom, CodePostal, Latitude, Longitude) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $insee, $nom, $cp,
            $lat !== '' ? (float) $lat : null,
            $lon !== '' ? (float) $lon : null,
        ]);

        $stmt = $pdo->prepare(
            'SELECT lp.Id_LaPoste, lp.Nom, lp.CodePostal, lp.Latitude, lp.Longitude,
                    LEFT(lp.CodePostal, 2) AS CodeDept, d.nom AS NomDept
             FROM laposte lp
             LEFT JOIN departement d ON d.code = LEFT(lp.CodePostal, 2)
             WHERE lp.Id_LaPoste = ?'
        );
        $stmt->execute([$insee]);
        $row = $stmt->fetch();

        return $this->response->setJSON(['ok' => true, 'msg' => "Commune « $nom » ajoutée.", 'row' => $row]);
    }

    public function updateCoords($insee = null): ResponseInterface
    {
        csrfVerify(true);

        $insee = (int) $insee;
        $input = $this->request->getRawInput();
        $lat   = str_replace(',', '.', trim($input['lat'] ?? ''));
        $lon   = str_replace(',', '.', trim($input['lon'] ?? ''));

        if ($insee <= 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Identifiant INSEE manquant.']);
        }
        if ($lat === '' || !is_numeric($lat)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Latitude invalide ou manquante.']);
        }
        if ($lon === '' || !is_numeric($lon)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Longitude invalide ou manquante.']);
        }

        $pdo  = getPDO();
        $stmt = $pdo->prepare('UPDATE laposte SET Latitude=?, Longitude=? WHERE Id_LaPoste=?');
        $stmt->execute([(float) $lat, (float) $lon, $insee]);

        if ($stmt->rowCount() === 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Commune $insee introuvable."]);
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Coordonnées mises à jour.', 'lat' => (float) $lat, 'lon' => (float) $lon]);
    }

    public function importCsv(): ResponseInterface
    {
        csrfVerify(true);

        if (empty($_FILES['fichier'])) {
            $postMax = ini_get('post_max_size');
            return $this->response->setJSON(['ok' => false, 'msg' => "Aucun fichier reçu. Vérifiez que post_max_size ($postMax) n'est pas dépassé."]);
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
            $msg  = $uploadErrors[$code] ?? "Erreur upload inconnue (code $code).";
            return $this->response->setJSON(['ok' => false, 'msg' => $msg]);
        }

        $ext            = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
        $extsAutorisees = ['csv', '001', '002', '003', '004', '005'];
        if (!in_array($ext, $extsAutorisees, true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Extension « .$ext » non acceptée."]);
        }

        $vider     = !empty($_POST['vider']);
        $hasHeader = !isset($_POST['has_header']) || !empty($_POST['has_header']);

        $fh = fopen($_FILES['fichier']['tmp_name'], 'r');
        if ($fh === false) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de lire le fichier.']);
        }

        $firstLine = fgets($fh);
        rewind($fh);
        $sep = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        if ($hasHeader) {
            $header = fgetcsv($fh, 0, $sep);
            if ($header === false) {
                fclose($fh);
                return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier vide ou illisible.']);
            }
            $header = array_map('trim', $header);

            $required = ['zip_code', 'label', 'latitude', 'longitude'];
            $colIdx   = array_flip($header);
            foreach ($required as $col) {
                if (!isset($colIdx[$col])) {
                    fclose($fh);
                    return $this->response->setJSON(['ok' => false, 'msg' => "Colonne manquante : « $col »."]);
                }
            }
            $iInsee = $colIdx['insee_code'] ?? 0;
            $iZip   = $colIdx['zip_code'];
            $iLabel = $colIdx['label'];
            $iLat   = $colIdx['latitude'];
            $iLon   = $colIdx['longitude'];
            $iDept  = $colIdx['department_number'] ?? null;
        } else {
            $iInsee = 0;
            $iZip   = 2;
            $iLabel = 3;
            $iLat   = 4;
            $iLon   = 5;
            $iDept  = 7;
        }

        set_time_limit(300);

        $pdo = getPDO();
        $pdo->beginTransaction();
        try {
            if ($vider) {
                $pdo->exec('DELETE FROM laposte');
            }

            $stmtIns = $pdo->prepare(
                'INSERT INTO laposte (Id_LaPoste, Nom, CodePostal, Latitude, Longitude) VALUES (?, ?, ?, ?, ?)'
            );
            $stmtUpd = $pdo->prepare(
                'UPDATE laposte SET Nom=?, CodePostal=?, Latitude=?, Longitude=? WHERE Id_LaPoste=?'
            );
            $stmtChk = $pdo->prepare('SELECT Id_LaPoste FROM laposte WHERE Id_LaPoste=? LIMIT 1');

            $inserts = 0;
            $updates = 0;
            $ignores = 0;
            $erreurs = [];

            while (($row = fgetcsv($fh, 0, $sep)) !== false) {
                if (count($row) <= $iLabel) { $ignores++; continue; }

                $insee   = (int) trim($row[$iInsee] ?? '0');
                $cp      = trim($row[$iZip] ?? '');
                $nom     = mb_strtoupper(trim($row[$iLabel] ?? ''), 'UTF-8');
                $lat     = (float) str_replace(',', '.', trim($row[$iLat] ?? '0'));
                $lon     = (float) str_replace(',', '.', trim($row[$iLon] ?? '0'));

                if ($insee === 0 || $cp === '' || $nom === '') { $ignores++; continue; }

                if (!$vider) {
                    $stmtChk->execute([$insee]);
                    $existing = $stmtChk->fetchColumn();
                    if ($existing !== false) {
                        $stmtUpd->execute([$nom, $cp, $lat, $lon, $insee]);
                        $updates++;
                        continue;
                    }
                }

                try {
                    $stmtIns->execute([$insee, $nom, $cp, $lat, $lon]);
                    $inserts++;
                } catch (\PDOException $ex) {
                    $erreurs[] = "$cp $nom (insee=$insee) : " . $ex->getMessage();
                    if (count($erreurs) >= 10) { $ignores++; continue; }
                }
            }

            $pdo->commit();
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            fclose($fh);
            throw $ex;
        }

        fclose($fh);

        $msg = "$inserts insérée(s), $updates mise(s) à jour, $ignores ignorée(s).";
        if ($erreurs) $msg .= ' Premières erreurs : ' . implode(' | ', array_slice($erreurs, 0, 3));

        return $this->response->setJSON(['ok' => empty($erreurs), 'msg' => $msg, 'inserts' => $inserts, 'updates' => $updates, 'ignores' => $ignores]);
    }

    public function exportCsv()
    {
        $pdo  = getPDO();
        $rows = $pdo->query(
            'SELECT lp.Id_LaPoste, lp.Nom, lp.CodePostal, lp.Latitude, lp.Longitude,
                    LEFT(lp.CodePostal, 2) AS CodeDept, d.nom AS NomDept
             FROM laposte lp
             LEFT JOIN departement d ON d.code = LEFT(lp.CodePostal, 2)
             ORDER BY lp.CodePostal, lp.Nom'
        )->fetchAll();

        $filename = 'communes_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['insee_code', 'city_code', 'zip_code', 'label', 'latitude', 'longitude',
                       'department_name', 'department_number', 'region_name', 'region_geojson_name']);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['Id_LaPoste'],
                '',
                $r['CodePostal'],
                $r['Nom'],
                $r['Latitude'],
                $r['Longitude'],
                $r['NomDept'] ?? '',
                $r['CodeDept'] ?? '',
                '',
                '',
            ]);
        }
        fclose($out);
        exit;
    }
}
