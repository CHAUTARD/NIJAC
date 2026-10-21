<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * NIJAC – Gestion des salles (EA81), portage CI4 de salle.php.
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

        // Clubs liés : club_salle (liensClubsSalles(), repli salle.Id_Club avant EA98), assemblés ci-dessous.
        $sql = 'SELECT s.Id_Salle, s.Nom, s.Adresse, s.Telephone, s.Id_Laposte, s.Cp, s.Ville,
                       COALESCE(
                           NULLIF(CONCAT(lp.CodePostal, \' \', lp.Nom), \' \'),
                           NULLIF(CONCAT(COALESCE(s.Cp,\'\'), \' \', COALESCE(s.Ville,\'\')), \' \')
                       ) AS CpVille
                FROM salle s
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
        $sql .= ' ORDER BY s.Nom';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows  = $stmt->fetchAll();
        $liens = liensClubsSalles($pdo);
        foreach ($rows as &$r) {
            $l = $liens[(int) $r['Id_Salle']] ?? [];
            $r['Clubs']         = $l;
            $r['Id_Club']       = implode(', ', array_column($l, 'Id_Club'));
            $r['NomClub']       = implode(', ', array_map(fn ($c) => $c['Nom'] . ($c['EstPrincipale'] ? ' ★' : ''), $l));
            $r['EstPrincipale'] = (int) in_array(1, array_column($l, 'EstPrincipale'), true);
        }
        unset($r);

        return $this->response->setJSON(['ok' => true, 'data' => $rows, 'multiClubs' => clubSalleExiste($pdo)]);
    }

    /**
     * Liaisons club ↔ salle envoyées par la modale (champ `liens`, JSON
     * [{id_club, principale}]) : clubs inconnus refusés, une seule entrée par club.
     * Renvoie [liens normalisés, message d'erreur|null].
     */
    private function lireLiens(\PDO $pdo, $brut): array
    {
        $liens = json_decode((string) $brut, true);
        if (!is_array($liens)) {
            return [[], 'Liste des clubs liés invalide.'];
        }
        $norm = [];
        foreach ($liens as $l) {
            $idClub = trim((string) ($l['id_club'] ?? ''));
            if ($idClub === '') {
                continue;
            }
            $norm[$idClub] = ['id_club' => $idClub, 'principale' => !empty($l['principale'])];
        }
        if ($norm) {
            $ph = implode(',', array_fill(0, count($norm), '?'));
            $st = $pdo->prepare("SELECT Id_Club FROM club WHERE Id_Club IN ($ph)");
            $st->execute(array_keys($norm));
            $connus = $st->fetchAll(\PDO::FETCH_COLUMN);
            $inconnus = array_diff(array_keys($norm), $connus);
            if ($inconnus) {
                return [[], 'Id_Club inconnu : ' . implode(', ', $inconnus) . '.'];
            }
        }
        if (count($norm) > 1 && !clubSalleExiste($pdo)) {
            return [[], 'Une salle ne peut être liée qu\'à un seul club tant que la migration EA98 (table club_salle) n\'a pas été chargée.'];
        }

        return [array_values($norm), null];
    }

    /**
     * Aligne les clubs liés à la salle $idSalle sur $liens : liens absents retirés (la salle
     * reste), nouveaux liens ajoutés, salle principale posée/retirée par club (au plus une
     * par club : definirSallePrincipale() retire l'ancienne principale du club).
     */
    private function enregistrerLiens(\PDO $pdo, int $idSalle, array $liens): void
    {
        $voulus = array_column($liens, null, 'id_club');
        foreach (liensClubsSalles($pdo)[$idSalle] ?? [] as $actuel) {
            if (!isset($voulus[$actuel['Id_Club']])) {
                delierClubSalle($pdo, (string) $actuel['Id_Club'], $idSalle);
            }
        }
        foreach ($liens as $l) {
            lierClubSalle($pdo, $l['id_club'], $idSalle, $l['principale']);
        }
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
        $lecteur = IOFactory::createReader('Xlsx');   // format imposé (seul .xlsx est accepté), sans détection
        $lecteur->setReadDataOnly(true);
        $spreadsheet = $lecteur->load($_FILES['fichier']['tmp_name']);
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

        // Une salle peut être créée sans club ; ses clubs (club_salle) viennent du champ `liens`.
        $pdo = getPDO();
        [$liens, $err] = $this->lireLiens($pdo, $this->request->getPost('liens') ?? '[]');
        if ($err) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO salle (Nom, Adresse, Telephone, Cp, Ville, Id_Laposte) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$nom, $adresse, $telephone, $cp, $ville, $idLaposte]);
            $idSalle = (int) $pdo->lastInsertId();
            $this->enregistrerLiens($pdo, $idSalle, $liens);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->response->setJSON(['ok' => true, 'msg' => 'Salle créée.', 'id_salle' => $idSalle]);
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

        // Clubs liés (club_salle) : modifiés seulement si la requête envoie `liens`.
        $pdo   = getPDO();
        $liens = null;
        if (array_key_exists('liens', $input)) {
            [$liens, $err] = $this->lireLiens($pdo, $input['liens']);
            if ($err) {
                return $this->response->setJSON(['ok' => false, 'msg' => $err]);
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE salle SET Nom=?, Adresse=?, Telephone=?, Cp=?, Ville=?, Id_Laposte=? WHERE Id_Salle=?')
                ->execute([$nom, $adresse, $telephone, $cp, $ville, $idLaposte, $id]);
            if ($liens !== null) {
                $this->enregistrerLiens($pdo, $id, $liens);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

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

        // Suppression seulement si plus aucune ligne ne la référence (rencontre.id_Salle…) : la
        // FK rencontre ON DELETE SET NULL ferait sinon perdre silencieusement le lieu. Les liens
        // club_salle sont supprimés avec elle (ON DELETE CASCADE, ou explicitement sans FK).
        $pdo = getPDO();
        $nb  = nbReferencesSalle($pdo, $id);
        if ($nb > 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Salle utilisée par $nb rencontre(s) : suppression impossible (déliez-la des clubs si besoin)."]);
        }
        $pdo->beginTransaction();
        try {
            if (clubSalleExiste($pdo)) {
                $pdo->prepare('DELETE FROM club_salle WHERE Id_Salle = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM salle WHERE Id_Salle = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

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

        // Convertit chaque champ FFTT en tableau de chaînes scalaires. FFTT peut renvoyer un
        // élément imbriqué (array) au lieu d'une chaîne pour une entrée isolée du XML — sans
        // le array_map ci-dessous, cet élément atteint trim()/PDO::execute() tel quel : trim()
        // plante, et PDO convertit silencieusement un tableau bindé en la chaîne littérale
        // "Array" au lieu de lever une erreur (bug constaté en prod sur Salle.Nom).
        $toArr = fn ($v) => is_array($v)
            ? (isset($v[0]) ? array_map(fn ($x) => is_array($x) ? '' : (string) $x, $v) : [])
            : ($v !== '' && $v !== null ? [(string) $v] : []);

        $nomsalles = $toArr($detail['nomsalle'] ?? '');
        $adrs1     = $toArr($detail['adressesalle1'] ?? '');
        $adrs2     = $toArr($detail['adressesalle2'] ?? '');
        $adrs3     = $toArr($detail['adressesalle3'] ?? '');
        $cps       = $toArr($detail['codepsalle'] ?? '');
        $villes    = $toArr($detail['villesalle'] ?? '');

        $nbSalles = count($nomsalles);
        // Salles du club (club_salle, principale d'abord) et nombre de clubs de chaque salle :
        // une salle partagée avec d'autres clubs n'est jamais vidée ni réaffectée par position.
        $sallesClub = sallesDuClub($pdo, $numClub);
        $liens      = liensClubsSalles($pdo);
        $partagee   = fn ($idS) => count($liens[(int) $idS] ?? []) > 1;

        if ($nbSalles === 0) {
            $idSalleExist = ($sallesClub && (int) $sallesClub[0]['EstPrincipale'] === 1) ? (int) $sallesClub[0]['Id_Salle'] : null;
            if ($idSalleExist && $partagee($idSalleExist)) {
                return $this->response->setJSON(['ok' => true, 'op' => null, 'msg' => "Club $numClub : aucune salle FFTT — salle principale partagée avec d'autres clubs, non modifiée"]);
            }
            if ($idSalleExist) {
                $nomClubRow = $pdo->prepare('SELECT Nom FROM Club WHERE Id_Club=?');
                $nomClubRow->execute([$numClub]);
                $nomClub = $nomClubRow->fetchColumn() ?: null;
                $pdo->prepare('UPDATE salle SET Nom=?, Adresse=NULL, Cp=NULL, Ville=NULL, Id_Laposte=NULL WHERE Id_Salle=?')
                    ->execute([$nomClub, $idSalleExist]);

                return $this->response->setJSON(['ok' => true, 'op' => 'vide', 'msg' => "Club $numClub : aucune salle FFTT — champs vidés"]);
            }

            return $this->response->setJSON(['ok' => true, 'op' => null, 'msg' => "Club $numClub : aucune salle dans FFTT"]);
        }

        $stmtUpd = $pdo->prepare('UPDATE salle SET Nom=?, Adresse=COALESCE(?,Adresse), Cp=COALESCE(?,Cp), Ville=COALESCE(?,Ville), Id_Laposte=COALESCE(?,Id_Laposte) WHERE Id_Salle=?');

        // Pool des salles existantes du club non encore appariées à une entrée FFTT de
        // cette synchronisation — appariées par nom en priorité (voir boucle
        // ci-dessous), avec repli sur l'ordre d'origine seulement si aucun nom
        // ne correspond (et jamais sur une salle partagée avec d'autres clubs),
        // pour ne pas écraser la mauvaise salle quand FFTT retourne ses salles
        // dans un ordre différent de celui de la base.
        $sallesRestantes = $sallesClub;
        $normaliserNomSalle = static fn (string $s): string => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $s)), 'UTF-8');

        $ops    = [];
        $cntNew = 0;
        $cntMaj = 0;

        for ($i = 0; $i < $nbSalles; $i++) {
            $nom = trim($nomsalles[$i] ?? '');
            if ($nom === '') continue;
            $adr1    = trim($adrs1[$i] ?? '');
            $adr2    = trim($adrs2[$i] ?? '');
            $adr3    = trim($adrs3[$i] ?? '');
            $adresse = trim(implode(' ', array_filter([$adr1, $adr2, $adr3]))) ?: null;
            $cp      = trim($cps[$i] ?? '');
            $ville   = mb_strtoupper(trim($villes[$i] ?? ''), 'UTF-8');
            $estPrinc = ($i === 0) ? 1 : 0;

            $idLaPoste = trouverIdLaPoste($pdo, $cp, $ville);

            $idSalle    = null;
            $nomNormale = $normaliserNomSalle($nom);
            foreach ($sallesRestantes as $k => $se) {
                if ($normaliserNomSalle($se['Nom'] ?? '') === $nomNormale) {
                    $idSalle = $se['Id_Salle'];
                    unset($sallesRestantes[$k]);
                    break;
                }
            }
            if ($idSalle === null) {
                foreach ($sallesRestantes as $k => $se) {
                    if (!$partagee($se['Id_Salle'])) {
                        $idSalle = $se['Id_Salle'];
                        unset($sallesRestantes[$k]);
                        break;
                    }
                }
            }

            if ($idSalle) {
                $stmtUpd->execute([$nom, $adresse, $cp ?: null, $ville ?: null, $idLaPoste, $idSalle]);
                lierClubSalle($pdo, $numClub, (int) $idSalle, $estPrinc ? true : null);
                $ops[] = "Mise à jour : $nom ($cp $ville)" . ($estPrinc ? ' [principale]' : '');
                $cntMaj++;
            } else {
                // Salle existante au même lieu (autre club, fiche sans club…) réutilisée plutôt que dupliquée.
                $salle = trouverOuCreerSalle($pdo, ['Nom' => $nom, 'Adresse' => $adresse, 'Cp' => $cp ?: null, 'Ville' => $ville ?: null, 'Id_Laposte' => $idLaPoste], $numClub);
                lierClubSalle($pdo, $numClub, $salle['id'], $estPrinc ? true : null);
                if ($salle['cree']) {
                    $ops[] = "Créée : $nom ($cp $ville)" . ($estPrinc ? ' [principale]' : '');
                    $cntNew++;
                } else {
                    $ops[] = "Liée (salle n°{$salle['id']} existante au même lieu) : $nom ($cp $ville)" . ($estPrinc ? ' [principale]' : '');
                    $cntMaj++;
                }
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
