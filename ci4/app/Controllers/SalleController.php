<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * NIJAC – Gestion des salles (E005), portage CI4 de salle.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * un Nominateur consulte en lecture, restreint à ses départements autorisés ;
 * seul un Administrateur peut importer/enregistrer/supprimer/synchroniser
 * FFTT — vérifié individuellement dans chaque méthode, comme le fait
 * salle.php (`in_array($action, $actionsAdmin) && !$isAdmin`).
 *
 * Pas de Model : requêtes dynamiques (jointures Club/laposte, filtre
 * département conditionnel, sauvegarde en masse) trop éloignées du Query
 * Builder simple — réutilise getPDO() directement, comme le fichier legacy.
 */
class SalleController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../vendor/autoload.php';
    }

    private function isAdmin(): bool
    {
        return !empty($_SESSION['utilisateur']['is_admin']);
    }

    // Normalise sous la forme 00.00.00.00.00 (mêmes règles que
    // ClubController::syncFfttClub pour le téléphone du correspondant).
    private function normaliserTelephone(?string $telephone): ?string
    {
        $chiffres = preg_replace('/\D/', '', $telephone ?? '');

        return strlen($chiffres) === 10 ? implode('.', str_split($chiffres, 2)) : null;
    }

    /** Codes département FFTT (xml_club_dep2) pour la Corse, distincts des codes INSEE 2A/2B utilisés partout ailleurs dans l'appli. */
    private const DEPT_FFTT_CORSE = ['2A' => '98', '2B' => '99'];

    public function index()
    {
        $moi     = $_SESSION['utilisateur'] ?? [];
        $isAdmin = $this->isAdmin();

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'isAdmin'     => $isAdmin,
            'deptUser'    => $moi['id_departement'] ?? null,
            'deptActifs'  => getDeptActifs(),
        ];

        return view('salle_index', $data);
    }

    public function data(): ResponseInterface
    {
        $moi     = $_SESSION['utilisateur'] ?? [];
        $isAdmin = $this->isAdmin();
        $pdo     = getPDO();

        $colsSalle = array_column($pdo->query('SHOW COLUMNS FROM Salle')->fetchAll(), 'Field');
        if (!in_array('Cp', $colsSalle))        $pdo->exec("ALTER TABLE Salle ADD COLUMN Cp VARCHAR(10) NULL AFTER Adresse");
        if (!in_array('Ville', $colsSalle))     $pdo->exec("ALTER TABLE Salle ADD COLUMN Ville VARCHAR(100) NULL AFTER Cp");
        if (!in_array('Telephone', $colsSalle)) $pdo->exec("ALTER TABLE Salle ADD COLUMN Telephone VARCHAR(20) NULL AFTER Ville");

        $sql = 'SELECT s.Id_Salle, COALESCE(s.Nom, cl.Nom) AS Nom, s.Adresse, s.Telephone, s.Id_Laposte, s.Id_Club,
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
            $depts = getDepartementsAutorises($moi['id_departement'] ?? null);
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }
            $deptPh = implode(',', array_fill(0, count($depts), '?'));
            $sql   .= " WHERE (LEFT(lp.CodePostal, 2) IN ($deptPh) OR LEFT(s.Cp, 2) IN ($deptPh))";
            foreach ($depts as $d) $params[] = str_pad((string) $d, 2, '0', STR_PAD_LEFT);
            foreach ($depts as $d) $params[] = str_pad((string) $d, 2, '0', STR_PAD_LEFT);
        } elseif (($_GET['dept'] ?? '') !== '') {
            $cp2    = str_pad((string) $_GET['dept'], 2, '0', STR_PAD_LEFT);
            $sql   .= ' WHERE (LEFT(lp.CodePostal, 2) = ? OR LEFT(s.Cp, 2) = ?)';
            $params = [$cp2, $cp2];
        }
        $sql .= ' ORDER BY cl.Nom, s.Nom';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
    }

    public function clubs(): ResponseInterface
    {
        $rows = getPDO()->query('SELECT Id_Club, Nom FROM Club ORDER BY Nom')->fetchAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function importExcel(): ResponseInterface
    {

        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
        }

        if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
        }
        if (strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
        }

        $pdo         = getPDO();
        $spreadsheet = IOFactory::load($_FILES['fichier']['tmp_name']);
        $sheet       = $spreadsheet->getActiveSheet();
        $maxRow      = $sheet->getHighestRow();

        $lignes   = [];
        $clubsVus = [];
        for ($row = 3; $row <= $maxRow; $row++) {
            $idClub = trim((string) $sheet->getCell('A' . $row)->getValue());
            $nom    = trim((string) $sheet->getCell('AN' . $row)->getValue());
            $adr1   = trim((string) $sheet->getCell('AP' . $row)->getValue());
            $adr2   = trim((string) $sheet->getCell('AQ' . $row)->getValue());
            $cp     = trim((string) $sheet->getCell('AR' . $row)->getValue());
            $ville  = trim((string) $sheet->getCell('AS' . $row)->getValue());

            if ($nom === '') continue;

            $adresse   = trim($adr1 . ($adr1 !== '' && $adr2 !== '' ? ' ' : '') . $adr2) ?: null;
            $idLaposte = trouverIdLaPoste($pdo, $cp, $ville);
            $idClubInt = $idClub !== '' ? (int) $idClub : null;

            $estPrincipale = 0;
            if ($idClubInt !== null) {
                if (!isset($clubsVus[$idClubInt])) {
                    $clubsVus[$idClubInt] = true;
                    $estPrincipale        = 1;
                }
            }

            $lignes[] = [
                'id_salle'       => 0,
                'nom'            => mb_strtoupper($nom, 'UTF-8'),
                'adresse'        => $adresse,
                'id_laposte'     => $idLaposte,
                'cp_ville'       => trim("$cp $ville"),
                'id_club'        => $idClubInt,
                'nom_club'       => '',
                'est_principale' => $estPrincipale,
            ];
        }

        return $this->response->setJSON(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
    }

    public function store(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
        }

        $nom = trim($this->request->getPost('nom') ?? '');
        if ($nom === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom est obligatoire.']);
        }
        $adresse       = trim($this->request->getPost('adresse') ?? '') ?: null;
        $telephone     = $this->normaliserTelephone($this->request->getPost('telephone'));
        $cp            = trim($this->request->getPost('cp') ?? '') ?: null;
        $ville         = trim($this->request->getPost('ville') ?? '') ?: null;
        $idLaposte     = ($this->request->getPost('id_laposte') ?? '') !== '' ? (int) $this->request->getPost('id_laposte') : null;
        $estPrincipale = $this->request->getPost('est_principale') ? 1 : 0;

        $pdo  = getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO Salle (Nom, Adresse, Telephone, Cp, Ville, Id_Laposte, Id_Club, EstPrincipale) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$nom, $adresse, $telephone, $cp, $ville, $idLaposte, $estPrincipale]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Salle créée.', 'id_salle' => (int) $pdo->lastInsertId()]);
    }

    public function update($id = null): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
        }

        $id = (int) $id;
        if ($id === 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Id invalide.']);
        }

        $input = $this->request->getRawInput();
        $nom   = trim($input['nom'] ?? '');
        if ($nom === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom est obligatoire.']);
        }
        $adresse       = trim($input['adresse'] ?? '') ?: null;
        $telephone     = $this->normaliserTelephone($input['telephone'] ?? null);
        $cp            = trim($input['cp'] ?? '') ?: null;
        $ville         = trim($input['ville'] ?? '') ?: null;
        $idLaposte     = ($input['id_laposte'] ?? '') !== '' ? (int) $input['id_laposte'] : null;
        $idClub        = ($input['id_club'] ?? '') !== '' ? trim($input['id_club']) : null;
        $estPrincipale = !empty($input['est_principale']) ? 1 : 0;

        $pdo  = getPDO();
        $stmt = $pdo->prepare(
            'UPDATE Salle SET Nom=?, Adresse=?, Telephone=?, Cp=?, Ville=?, Id_Laposte=?, Id_Club=?, EstPrincipale=? WHERE Id_Salle=?'
        );
        $stmt->execute([$nom, $adresse, $telephone, $cp, $ville, $idLaposte, $idClub, $estPrincipale, $id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Salle mise à jour.']);
    }

    public function delete($id = null): ResponseInterface
    {

        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
        }

        $id = (int) $id;
        if ($id === 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Id invalide.']);
        }

        getPDO()->prepare('DELETE FROM Salle WHERE Id_Salle = ?')->execute([$id]);

        return $this->response->setJSON(['ok' => true, 'msg' => 'Salle supprimée.']);
    }

    public function ffttClubs(): ResponseInterface
    {

        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
        }

        $dep = trim($this->request->getPost('dep') ?? '');
        if ($dep === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Département manquant.']);
        }

        $pdo     = getPDO();
        $depFftt = self::DEPT_FFTT_CORSE[strtoupper($dep)] ?? $dep;
        $clubs   = getFfttRawClient()->listClubsByDepartement($depFftt);

        $numeros = array_values(array_filter(array_map(fn ($c) => $c['numero'] ?? '', $clubs)));
        if ($numeros) {
            $ph     = implode(',', array_fill(0, count($numeros), '?'));
            $stmtEx = $pdo->prepare("SELECT Id_Club FROM Club WHERE Id_Club IN ($ph)");
            $stmtEx->execute($numeros);
            $existants = array_column($stmtEx->fetchAll(), 'Id_Club');
        } else {
            $existants = [];
        }

        $result = array_values(array_filter(
            array_map(fn ($c) => [
                'numero' => $c['numero'] ?? '',
                'nom'    => $c['nom'] ?? '',
            ], $clubs),
            fn ($c) => $c['numero'] !== '' && in_array($c['numero'], $existants, true)
        ));

        return $this->response->setJSON(['ok' => true, 'clubs' => $result]);
    }

    /**
     * Appelle xml_club_detail directement via getFfttRawClient()->request(),
     * pas la méthode retrieveClubDetails() (qui suppose une seule salle) :
     * cet endpoint peut renvoyer plusieurs salles pour un même club
     * (tableaux nomsalle[]/adressesalle1[]/…, boucle $nbSalles ci-dessous).
     */
    public function ffttSync(): ResponseInterface
    {

        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé.']);
        }

        $numClub = trim($this->request->getPost('num_club') ?? '');
        if ($numClub === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
        }

        set_time_limit(30);
        $pdo    = getPDO();
        $api    = getFfttRawClient();
        $detail = $api->request('xml_club_detail', ['club' => $numClub])['club'] ?? [];
        if (empty($detail)) {
            return $this->response->setJSON(['ok' => true, 'op' => null, 'msg' => "Club $numClub : aucune donnée FFTT"]);
        }

        $toArr = fn ($v) => is_array($v) ? (isset($v[0]) ? $v : []) : ($v !== '' && $v !== null ? [(string) $v] : []);

        $nomsalles = $toArr($detail['nomsalle'] ?? '');
        $adrs1     = $toArr($detail['adressesalle1'] ?? '');
        $adrs2     = $toArr($detail['adressesalle2'] ?? '');
        $adrs3     = $toArr($detail['adressesalle3'] ?? '');
        $cps       = $toArr($detail['codepsalle'] ?? '');
        $villes    = $toArr($detail['villesalle'] ?? '');

        $nbSalles = count($nomsalles);
        if ($nbSalles === 0) {
            $stmtChkP = $pdo->prepare('SELECT Id_Salle FROM Salle WHERE Id_Club=? AND EstPrincipale=1 LIMIT 1');
            $stmtChkP->execute([$numClub]);
            $idSalleExist = $stmtChkP->fetchColumn();
            if ($idSalleExist) {
                $nomClubRow = $pdo->prepare('SELECT Nom FROM Club WHERE Id_Club=?');
                $nomClubRow->execute([$numClub]);
                $nomClub = $nomClubRow->fetchColumn() ?: null;
                $pdo->prepare('UPDATE Salle SET Nom=?, Adresse=NULL, Cp=NULL, Ville=NULL, Id_Laposte=NULL WHERE Id_Salle=?')
                    ->execute([$nomClub, $idSalleExist]);

                return $this->response->setJSON(['ok' => true, 'op' => 'vide', 'msg' => "Club $numClub : aucune salle FFTT — champs vidés"]);
            }

            return $this->response->setJSON(['ok' => true, 'op' => null, 'msg' => "Club $numClub : aucune salle dans FFTT"]);
        }

        $stmtUpd = $pdo->prepare('UPDATE Salle SET Nom=?, Adresse=COALESCE(?,Adresse), Cp=COALESCE(?,Cp), Ville=COALESCE(?,Ville), Id_Laposte=COALESCE(?,Id_Laposte), EstPrincipale=? WHERE Id_Salle=?');
        $stmtIns = $pdo->prepare('INSERT INTO Salle (Nom, Adresse, Cp, Ville, Id_Laposte, Id_Club, EstPrincipale) VALUES (?,?,?,?,?,?,?)');

        $stmtExist = $pdo->prepare('SELECT Id_Salle FROM Salle WHERE Id_Club=? ORDER BY EstPrincipale DESC, Id_Salle ASC');
        $stmtExist->execute([$numClub]);
        $idsSallesExist = array_column($stmtExist->fetchAll(), 'Id_Salle');

        $ops    = [];
        $cntNew = 0;
        $cntMaj = 0;

        for ($i = 0; $i < $nbSalles; $i++) {
            $nom = trim($nomsalles[$i] ?? '');
            if ($nom === '') continue;
            $adr1    = trim($adrs1[$i] ?? '');
            $adr2Raw = $adrs2[$i] ?? '';
            $adr2    = is_array($adr2Raw) ? '' : trim((string) $adr2Raw);
            $adr3Raw = $adrs3[$i] ?? '';
            $adr3    = is_array($adr3Raw) ? '' : trim((string) $adr3Raw);
            $adresse = trim(implode(' ', array_filter([$adr1, $adr2, $adr3]))) ?: null;
            $cp      = trim($cps[$i] ?? '');
            $ville   = mb_strtoupper(trim($villes[$i] ?? ''), 'UTF-8');
            $estPrinc = ($i === 0) ? 1 : 0;

            $idLaPoste = trouverIdLaPoste($pdo, $cp, $ville);
            $idSalle   = $idsSallesExist[$i] ?? null;

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

        return $this->response->setJSON([
            'ok'        => true,
            'op'        => $cntNew > 0 ? 'new' : ($cntMaj > 0 ? 'maj' : null),
            'cnt_new'   => $cntNew,
            'cnt_maj'   => $cntMaj,
            'nb'        => $nbSalles,
            'nom_salle' => $nomsalles[0] ?? '',
            'cp'        => $cps[0] ?? '',
            'ville'     => $villes[0] ?? '',
            'ops'       => $ops,
        ]);
    }
}
