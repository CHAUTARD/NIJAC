<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Import Rencontres Nationales (E017), portage CI4 de
 * import_rencontres_nat.php.
 *
 * Charge les équipes nationales depuis l'API FFTT (N1M, N2M, N3M, N1F, N2F),
 * permet de les associer à un département et un club normand, puis importe
 * les rencontres où le receveur est un club de la région. Admin uniquement
 * (filtre "adminauth", comme includes/admin_required.php côté legacy — la
 * SPECIFICATION.md décrit à tort un import de fichier Excel multi-feuilles,
 * document déjà obsolète : le code réel appelle l'API FFTT en direct, comme
 * pour E011).
 *
 * Pas de Model : appels API FFTT, résolution dynamique club/équipe,
 * association manuelle équipe→club en session — trop éloigné du Query
 * Builder simple. Réutilise getPDO() directement, comme le fichier legacy.
 */
class ImportRencontresNatController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // ── Migration table ───────────────────────────────────────────────
        try {
            $pdo0 = getPDO();
            $pdo0->exec('
                CREATE TABLE IF NOT EXISTS equipe_nationale (
                    Id_EquipeNat INT              AUTO_INCREMENT PRIMARY KEY,
                    Nom          VARCHAR(200)     NOT NULL,
                    id_division  INT      NOT NULL,
                    Poule        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    Rang         TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    CodeDept     VARCHAR(3)       NULL,
                    Id_Club      CHAR(8)          NULL,
                    Id_Equipe    INT              NULL,
                    UNIQUE KEY uq_nom_div (Nom(150), id_division)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');
            $cols = array_column($pdo0->query('SHOW COLUMNS FROM equipe_nationale')->fetchAll(), 'Field');
            if (!in_array('CodeDept', $cols)) {
                $pdo0->exec('ALTER TABLE equipe_nationale ADD COLUMN CodeDept VARCHAR(3) NULL AFTER Rang');
            }
        } catch (\PDOException $e) {
        }
    }

    /**
     * Exécute une action et convertit toute exception en réponse JSON
     * ['ok' => false, 'err' => ...] — import_rencontres_nat.php enveloppe de
     * la même façon la totalité de son dispatcher d'actions.
     */
    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            error_log('[NIJAC] E017 PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] E017 : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => $e->getMessage()]);
        }
    }

    /** Retourne [code => Id_Division] depuis la table division (ex: ['N1M' => 3, 'N2M' => 5]). */
    private function getDivIdMap(\PDO $pdo): array
    {
        $rows = $pdo->query('SELECT Id_Division, Division FROM division')->fetchAll();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['Division']] = (int) $r['Id_Division'];
        }

        return $map;
    }

    /** Normalise une réponse FFTT en tableau indexé. */
    private function ffttItemsNat(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (empty($items)) {
            return [];
        }

        return isset($items[0]) ? $items : [$items];
    }

    /** Retourne les cx_poule réels d'une division depuis action=poule. */
    private function getCxPoules(object $api, string $divFftt): array
    {
        $r     = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
        $items = $this->ffttItemsNat($r, 'poule');
        $cx    = [];
        foreach ($items as $i => $p) {
            $lienRaw = $p['lien'] ?? '';
            $lienStr = is_array($lienRaw) ? '' : (string) $lienRaw;
            if ($lienStr !== '') {
                parse_str(html_entity_decode($lienStr), $lp);
                if (!empty($lp['cx_poule'])) {
                    $cx[$i + 1] = $lp['cx_poule'];
                    continue;
                }
            }
            $cx[$i + 1] = null;
        }

        return $cx;
    }

    /** Retourne les rencontres d'une division (par poule ou global). */
    private function getRencontresDivision(object $api, string $divFftt): array
    {
        $cx         = $this->getCxPoules($api, $divFftt);
        $rencontres = [];
        $hasAllCx   = count(array_filter($cx)) === count($cx) && count($cx) > 0;

        if ($hasAllCx) {
            foreach ($cx as $num => $cxPoule) {
                $r = $api->request('xml_result_equ', ['D1' => $divFftt, 'cx_poule' => $cxPoule, 'auto' => '1', 'type' => 'E']);
                foreach ($this->ffttItemsNat($r, 'tour') as $rc) {
                    $rc['_poule_num'] = $num;
                    $rencontres[]     = $rc;
                }
            }
        } else {
            $r          = $api->request('xml_result_equ', ['D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
            $rencontres = $this->ffttItemsNat($r, 'tour');
        }

        return $rencontres;
    }

    /** Retourne [idEpreuve => [divisions FFTT Nationales]] depuis l'API. */
    private function getDivisionsNationales(object $api, string $orgId): array
    {
        $epRep  = $api->request('xml_epreuve', ['organisme' => $orgId, 'type' => 'E']);
        $result = [];
        foreach ($this->ffttItemsNat($epRep, 'epreuve') as $ep) {
            $intitule = (string) ($ep['intitule'] ?? $ep['libelle'] ?? '');
            if (!preg_match('/nationale/i', $intitule)) {
                continue;
            }
            $idEp = (string) ($ep['idepreuve'] ?? $ep['ident'] ?? '');
            if ($idEp === '') {
                continue;
            }
            $divRep = $api->request('xml_division', ['organisme' => $orgId, 'epreuve' => $idEp, 'type' => 'E']);
            foreach ($this->ffttItemsNat($divRep, 'division') as $div) {
                $lib   = (string) ($div['libelle'] ?? '');
                $idDiv = (string) ($div['iddivision'] ?? $div['ident'] ?? '');
                if ($idDiv === '') {
                    continue;
                }
                // Ne garder que les divisions N1M, N2M, N3M, N1F, N2F, N1D, N2D
                if (!preg_match('/nationale\s*(\d+)\s*(messieurs?|dames?|hommes?|f[eé]minin)/i', $lib, $m)) {
                    continue;
                }
                $num  = $m[1];
                $sexe = preg_match('/dame|féminin|feminin/i', $m[2]) ? 'F' : 'M';
                $code = "N{$num}{$sexe}";
                $result[$idEp][] = ['iddivision' => $idDiv, 'libelle' => $lib, 'code' => $code];
            }
        }

        return $result;
    }

    /** Retourne l'ID organisme de la ligue depuis la config ou l'API. */
    private function getOrgId(object $api): string
    {
        $stored = getConfig('fftt_organisme_id', '');
        if ($stored !== '') {
            return $stored;
        }
        $region = mb_strtolower(getConfig('region', 'Normandie'), 'UTF-8');
        $rep    = $api->request('xml_organisme', ['type' => 'L']);
        foreach ($this->ffttItemsNat($rep, 'organisme') as $org) {
            $lib = mb_strtolower((string) ($org['libelle'] ?? ''), 'UTF-8');
            if (str_contains($lib, $region)) {
                $id = (string) ($org['id'] ?? $org['ident'] ?? $org['idorga'] ?? '');
                if ($id !== '') {
                    return $id;
                }
            }
        }

        return '';
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $deptsNorm     = array_column(getDeptActifs(), 'code');
        $regionNom     = getConfig('region', 'Région');
        $regionGentile = getRegionGentile();

        try {
            $allDepts = getPDO()->query('SELECT code, nom FROM departement ORDER BY CAST(code AS UNSIGNED)')->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\PDOException $e) {
            $allDepts = [];
        }

        // Récapitulatif equipe_nationale par département normand
        $recapDepts = [];
        try {
            $pdo0            = getPDO();
            $deptsNormActifs = array_map('strval', $deptsNorm);
            if ($deptsNormActifs) {
                $ph   = implode(',', array_fill(0, count($deptsNormActifs), '?'));
                $rows = $pdo0->prepare("
                    SELECT CodeDept, COUNT(*) AS nb
                    FROM equipe_nationale
                    WHERE CodeDept IN ($ph)
                    GROUP BY CodeDept
                ");
                $rows->execute($deptsNormActifs);
                foreach ($rows->fetchAll() as $r) {
                    $recapDepts[$r['CodeDept']] = (int) $r['nb'];
                }
            }
        } catch (\PDOException $e) {
            $recapDepts = [];
        }

        $data = [
            'nomComplet'     => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'    => $u['id_departement'] ?? '',
            'changeLogin'    => !empty($u['change_login']),
            'isChautard'     => ($u['login'] ?? '') === 'CHAUTARD',
            'deptsNorm'      => $deptsNorm,
            'regionNom'      => $regionNom,
            'regionGentile'  => $regionGentile,
            'allDepts'       => $allDepts,
            'recapDepts'     => $recapDepts,
        ];

        return view('import_rencontres_nat_index', $data);
    }

    public function resetNatCache(): ResponseInterface
    {

        return $this->tryJson(function () {
            unset($_SESSION['nat_div_ids']);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function listerClubsRegion(): ResponseInterface
    {
        return $this->tryJson(function () {
            set_time_limit(120);
            $api   = getFfttApi();
            $depts = getDeptActifs();
            $clubs = [];
            foreach ($depts as $dept) {
                $list = $api->getClubsDepartement($dept['code']);
                foreach ($list as $c) {
                    $num = trim((string) ($c['numero'] ?? $c['numclu'] ?? ''));
                    $nom = trim((string) ($c['nom'] ?? ''));
                    if ($num === '') {
                        continue;
                    }
                    $clubs[] = ['numclu' => $num, 'nom' => $nom, 'dept' => (string) $dept['code']];
                }
            }

            return $this->response->setJSON(['ok' => true, 'clubs' => $clubs]);
        });
    }

    public function debugClub(): ResponseInterface
    {
        return $this->tryJson(function () {
            $numclu = trim($this->request->getGet('numclu') ?? '');
            if ($numclu === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'numclu manquant']);
            }
            $api     = getFfttApi();
            $equipes = $api->getEquipesClub($numclu);
            $raw     = mb_convert_encoding((string) $api->lastRaw(), 'UTF-8', 'UTF-8');
            $payload = ['ok' => true, 'equipes' => $equipes, 'raw' => $raw];
            $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json === false) {
                $json = json_encode(['ok' => false, 'err' => 'json_encode échoué : ' . json_last_error_msg()]);
            }

            return $this->response->setContentType('application/json')->setBody($json);
        });
    }

    public function scannerClub(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo     = getPDO();
            $numclu  = trim($this->request->getPost('numclu') ?? '');
            $dept    = trim($this->request->getPost('dept') ?? '');
            $nomClub = mb_substr(trim($this->request->getPost('nom') ?? ''), 0, 100);
            if ($numclu === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'numclu manquant']);
            }

            // Divisions nationales actives (phase à venir) — cachées en session pour éviter
            // un appel API par club (plusieurs centaines de clubs à scanner)
            if (empty($_SESSION['nat_div_ids'])) {
                $apiOrg                 = getFfttApi();
                $orgId                  = $this->getOrgId($apiOrg);
                $_SESSION['nat_div_ids'] = [];
                if ($orgId !== '') {
                    $divMap = $this->getDivisionsNationales($apiOrg, $orgId);
                    foreach ($divMap as $divs) {
                        foreach ($divs as $div) {
                            $_SESSION['nat_div_ids'][$div['iddivision']] = $div['code'];
                        }
                    }
                }
            }
            $phaseFiltre = trim($this->request->getPost('phase') ?? ''); // "1", "2" ou "" = toutes

            $api      = getFfttApi();
            $equipes  = $api->getEquipesClub($numclu);
            $divIdMap = $this->getDivIdMap($pdo);

            $stmtClubIns = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtNatIns  = $pdo->prepare('
                INSERT INTO equipe_nationale (Nom, id_division, Poule, Rang, CodeDept, Id_Club)
                VALUES (?,?,?,0,?,?)
                ON DUPLICATE KEY UPDATE CodeDept=VALUES(CodeDept), Id_Club=VALUES(Id_Club)
            ');

            $nationales = [];
            $nonMatch   = [];

            foreach ($equipes as $eq) {
                $lib = trim((string) ($eq['libequipe'] ?? $eq['libelle'] ?? ''));
                if ($lib === '') {
                    continue;
                }
                $ndiv = trim((string) ($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? ''));

                // Détection par nom de division (seule méthode fiable — D1 est un ID local incompatible)
                $divCode = null;
                if (preg_match('/\b(N[1-9][MFD])\b/i', $ndiv, $m)) {
                    $divCode = strtoupper($m[1]);
                } elseif (preg_match('/nationale[^\d]*(\d+)[^\w]*(messieurs?|hommes?|dames?|f[eé]minin)/i', $ndiv, $m)) {
                    $divCode = 'N' . $m[1] . (preg_match('/dame|f[eé]minin/i', $m[2]) ? 'F' : 'M');
                } elseif (preg_match('/\bpro\s*([ab])\b/i', $ndiv, $m)) {
                    $divCode = 'PRO' . strtoupper($m[1]);
                }

                if (!$divCode) {
                    if ($ndiv !== '') {
                        $nonMatch[] = ['lib' => $lib, 'raison' => 'non reconnu', 'ndiv' => $ndiv];
                    }
                    continue;
                }

                // Filtre de phase : "Phase X" dans ndivision
                if ($phaseFiltre !== '' && !preg_match('/\bPhase\s+' . preg_quote($phaseFiltre, '/') . '\b/i', $ndiv)) {
                    $nonMatch[] = ['lib' => $lib, 'raison' => 'autre phase', 'ndiv' => $ndiv];
                    continue;
                }

                // Extraire la poule depuis ndivision
                $poule = 0;
                if (preg_match('/Poule\s+(\d+)/i', $ndiv, $mp)) {
                    $poule = (int) $mp[1];
                }

                $idDiv = $divIdMap[$divCode] ?? null;
                if (!$idDiv) {
                    $nonMatch[] = ['lib' => $lib, 'raison' => "division $divCode absente en BDD", 'ndiv' => $ndiv];
                    continue;
                }
                $stmtClubIns->execute([$numclu, $nomClub]);
                $stmtNatIns->execute([mb_substr($lib, 0, 200), $idDiv, $poule, $dept, $numclu]);
                $isNew         = (bool) $pdo->lastInsertId();
                $nationales[]  = ['lib' => $lib, 'div' => $divCode, 'new' => $isNew];
            }

            return $this->response->setJSON(['ok' => true, 'nationales' => $nationales, 'non_match' => $nonMatch]);
        });
    }

    public function chargerDepuisApi(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo = getPDO();
            set_time_limit(300);
            $api   = getFfttApi();
            $orgId = $this->getOrgId($api);
            if ($orgId === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Organisme ligue introuvable.']);
            }

            $divMap   = $this->getDivisionsNationales($api, $orgId);
            $divIdMap = $this->getDivIdMap($pdo);

            $stmt = $pdo->prepare('
                INSERT INTO equipe_nationale (Nom, id_division, Poule, Rang)
                VALUES (?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE Poule = VALUES(Poule)
            ');

            $stats = ['divisions' => 0, 'equipes' => 0, 'erreurs' => []];

            foreach ($divMap as $idEp => $divisions) {
                foreach ($divisions as $div) {
                    $divCode = $div['code'];
                    $divFftt = $div['iddivision'];
                    $idDiv   = $divIdMap[$divCode] ?? null;
                    if (!$idDiv) {
                        $stats['erreurs'][] = "Division $divCode absente en BDD.";
                        continue;
                    }
                    $stats['divisions']++;

                    try {
                        $rencontres = $this->getRencontresDivision($api, $divFftt);
                        $equipesDep = [];
                        foreach ($rencontres as $rc) {
                            $libelle  = trim((string) ($rc['libelle'] ?? ''));
                            $pouleNum = isset($rc['_poule_num']) ? (int) $rc['_poule_num'] : 0;
                            if ($pouleNum === 0 && preg_match('/poule\s+(\d+)/i', $libelle, $m)) {
                                $pouleNum = (int) $m[1];
                            }

                            foreach (['equa' => $rc['equa'] ?? '', 'equb' => $rc['equb'] ?? ''] as $nom) {
                                $nom = mb_substr(trim((string) $nom), 0, 200);
                                if ($nom === '') {
                                    continue;
                                }
                                $key = $divCode . '|' . $pouleNum . '|' . $nom;
                                if (isset($equipesDep[$key])) {
                                    continue;
                                }
                                $equipesDep[$key] = true;
                                $stmt->execute([$nom, $idDiv, $pouleNum]);
                                if ($pdo->lastInsertId()) {
                                    $stats['equipes']++;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $stats['erreurs'][] = "{$divCode} ({$divFftt}) : " . $e->getMessage();
                    }
                }
            }

            // Auto-propagation CodeDept/Id_Club par nom de base
            $pdo->exec("
                UPDATE equipe_nationale en_cible
                JOIN equipe_nationale en_source
                    ON en_source.Id_Club IS NOT NULL
                    AND TRIM(REGEXP_REPLACE(en_source.Nom, '\\\\s+[0-9]+\$', ''))
                      = TRIM(REGEXP_REPLACE(en_cible.Nom,  '\\\\s+[0-9]+\$', ''))
                SET en_cible.Id_Club  = en_source.Id_Club,
                    en_cible.CodeDept = en_source.CodeDept
                WHERE en_cible.Id_Club IS NULL
            ");

            return $this->response->setJSON(['ok' => true, 'stats' => $stats, 'org_id' => $orgId]);
        });
    }

    public function listeEquipes(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query('
                SELECT en.Id_EquipeNat, en.Nom, d.Division AS id_division, en.Poule, en.Rang,
                       en.CodeDept, en.Id_Club,
                       (SELECT c.Nom FROM club c WHERE c.Id_Club = en.Id_Club LIMIT 1) AS NomClub
                FROM equipe_nationale en
                LEFT JOIN division d ON d.Id_Division = en.id_division
                ORDER BY d.Division, en.Poule, en.Nom
            ')->fetchAll();

            return $this->response->setJSON(['ok' => true, 'equipes' => $rows]);
        });
    }

    public function rechercheClub(): ResponseInterface
    {
        return $this->tryJson(function () {
            $q = trim($this->request->getGet('q') ?? '');
            if (strlen($q) < 2) {
                return $this->response->setJSON(['ok' => true, 'clubs' => []]);
            }
            $stmt = getPDO()->prepare('SELECT Id_Club, Nom FROM club WHERE Nom LIKE ? ORDER BY Nom LIMIT 25');
            $stmt->execute(['%' . $q . '%']);

            return $this->response->setJSON(['ok' => true, 'clubs' => $stmt->fetchAll()]);
        });
    }

    public function sauvegarderAssoc(): ResponseInterface
    {

        return $this->tryJson(function () {
            $idEn     = (int) ($this->request->getPost('id_equipe_nat') ?? 0);
            $codeDept = trim($this->request->getPost('code_dept') ?? '') ?: null;
            $idClub   = ($this->request->getPost('id_club') ?? '') !== '' ? trim($this->request->getPost('id_club')) : null;
            if ($idEn <= 0) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Id invalide.']);
            }
            getPDO()->prepare('UPDATE equipe_nationale SET CodeDept=?, Id_Club=? WHERE Id_EquipeNat=?')
                ->execute([$codeDept, $idClub, $idEn]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function assignerHorsRegion(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo          = getPDO();
            $deptsNorm    = array_map('strval', array_column(getDeptActifs(), 'code'));
            $placeholders = implode(',', array_fill(0, count($deptsNorm), '?'));

            $stmt = $pdo->prepare("
                SELECT en.CodeDept,
                       TRIM(REGEXP_REPLACE(MIN(en.Nom), '\\\\s+[0-9]+\$', '')) AS NomBase
                FROM equipe_nationale en
                WHERE en.CodeDept IS NOT NULL
                  AND en.Id_Club IS NULL
                  AND en.CodeDept NOT IN ($placeholders)
                GROUP BY en.CodeDept
            ");
            $stmt->execute($deptsNorm);
            $depts = $stmt->fetchAll();

            $crees   = 0;
            $assoc   = 0;
            $stmtIns = $pdo->prepare('INSERT INTO club (Id_Club, Nom) VALUES (?,?) ON DUPLICATE KEY UPDATE Nom=VALUES(Nom)');
            $stmtUpd = $pdo->prepare('UPDATE equipe_nationale SET Id_Club=? WHERE CodeDept=? AND Id_Club IS NULL');
            foreach ($depts as $d) {
                $idClub = 'HR' . str_pad($d['CodeDept'], 3, '0', STR_PAD_LEFT);
                $stmtIns->execute([$idClub, mb_substr('Hors Région - ' . $d['NomBase'], 0, 100)]);
                if ($stmtIns->rowCount() === 1) {
                    $crees++;
                }
                $stmtUpd->execute([$idClub, $d['CodeDept']]);
                $assoc += $stmtUpd->rowCount();
            }

            return $this->response->setJSON(['ok' => true, 'clubs_crees' => $crees, 'equipes_assignees' => $assoc]);
        });
    }

    public function importerRencontres(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo = getPDO();
            set_time_limit(300);
            $api   = getFfttApi();
            $orgId = $this->getOrgId($api);
            if ($orgId === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Organisme introuvable.']);
            }

            $deptsNorm = array_map('strval', array_column(getDeptActifs(), 'code'));

            // Map [Division][Nom] → {Id_Club, CodeDept}
            $enRows = $pdo->query('
                SELECT d.Division AS DivCode, en.Nom, en.Id_Club, en.CodeDept
                FROM equipe_nationale en
                LEFT JOIN division d ON d.Id_Division = en.id_division
                WHERE en.Id_Club IS NOT NULL
            ')->fetchAll();
            $enMap = [];
            foreach ($enRows as $r) {
                $enMap[$r['DivCode']][$r['Nom']] = $r;
            }

            // Map divCode → Id_Division NIJAC + ArbitrageCRA
            $divRows  = $pdo->query('SELECT Id_Division, Division, ArbitrageCRA FROM division')->fetchAll();
            $divNijac = [];
            foreach ($divRows as $r) {
                $divNijac[$r['Division']] = $r;
            }

            $stmtClubIns   = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtClubByNom = $pdo->prepare('SELECT Id_Club FROM club WHERE Nom=? LIMIT 1');
            $stmtClubById  = $pdo->prepare('SELECT Id_Club FROM club WHERE Id_Club=? LIMIT 1');
            $stmtEqChk     = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom=? AND Id_Division=? LIMIT 1');
            $stmtEqIns     = $pdo->prepare('INSERT INTO equipe (Nom, Id_Division, Id_Club, JAdemande) VALUES (?,?,?,0)');
            $stmtRcChk     = $pdo->prepare('SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1');
            $stmtRcIns     = $pdo->prepare('INSERT INTO rencontre (Date,Heure,Id_Division,Poule,Id_EquipeDom,Id_EquipeExt,Phase,Journee,ArbitrageObligatoire) VALUES (?,?,?,?,?,?,?,?,?)');

            $stats = ['equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'ignores' => 0, 'erreurs' => [], 'log' => []];

            $divMap = $this->getDivisionsNationales($api, $orgId);

            foreach ($divMap as $idEp => $divisions) {
                foreach ($divisions as $div) {
                    $divCode = $div['code'];
                    $divFftt = $div['iddivision'];
                    $nijac   = $divNijac[$divCode] ?? null;
                    if (!$nijac) {
                        $stats['erreurs'][] = "Division $divCode non mappée en NIJAC.";
                        continue;
                    }
                    $idDivNijac = (int) $nijac['Id_Division'];
                    $arbitrage  = (int) $nijac['ArbitrageCRA'];

                    try {
                        $rencontres = $this->getRencontresDivision($api, $divFftt);
                    } catch (\Throwable $e) {
                        $stats['erreurs'][] = "$divCode : " . $e->getMessage();
                        continue;
                    }

                    foreach ($rencontres as $rc) {
                        $libDom  = mb_substr(trim((string) ($rc['equa'] ?? '')), 0, 200);
                        $libExt  = mb_substr(trim((string) ($rc['equb'] ?? '')), 0, 200);
                        $dateStr = trim((string) ($rc['dateprevue'] ?? ''));
                        $libelle = trim((string) ($rc['libelle'] ?? ''));

                        $pouleNum = isset($rc['_poule_num']) ? (int) $rc['_poule_num'] : 0;
                        $journee  = 0;
                        if ($pouleNum === 0 && preg_match('/poule\s+(\d+)/i', $libelle, $m)) {
                            $pouleNum = (int) $m[1];
                        }
                        if (preg_match('/tour\s+n[°o]?\s*(\d+)/i', $libelle, $m)) {
                            $journee = (int) $m[1];
                        }

                        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateStr, $dm)) {
                            $stats['ignores']++;
                            continue;
                        }
                        $date = "{$dm[3]}-{$dm[2]}-{$dm[1]}";

                        // Seules les rencontres où le RECEVEUR est de la région
                        $domEntry = $enMap[$divCode][$libDom] ?? null;
                        if (!$domEntry || !in_array((string) $domEntry['CodeDept'], $deptsNorm)) {
                            $stats['ignores']++;
                            continue;
                        }
                        $extEntry = $enMap[$divCode][$libExt] ?? null;
                        if (!$extEntry) {
                            $stats['ignores']++;
                            continue;
                        }

                        // Résoudre clubs
                        foreach ([
                            ['dom', &$domEntry['Id_Club'], mb_substr(preg_replace('/\s+\d+$/', '', $libDom), 0, 100)],
                            ['ext', &$extEntry['Id_Club'], mb_substr(preg_replace('/\s+\d+$/', '', $libExt), 0, 100)],
                        ] as [, &$idVar, $nomClub]) {
                            $idClub8 = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nomClub), 0, 8)) ?: 'UNKNOWN1';
                            $stmtClubIns->execute([$idClub8, $nomClub]);
                            if (!$pdo->lastInsertId()) {
                                $stmtClubById->execute([$idVar]);
                                if (!$stmtClubById->fetchColumn()) {
                                    $stmtClubByNom->execute([$nomClub]);
                                    $found = $stmtClubByNom->fetchColumn();
                                    if ($found) {
                                        $idVar = $found;
                                    }
                                }
                            }
                        }
                        unset($idVar);

                        // Équipes
                        $idDom = $idExt = null;
                        foreach ([[$libDom, $domEntry['Id_Club'], 'idDom'], [$libExt, $extEntry['Id_Club'], 'idExt']] as [$lib, $club, $var]) {
                            $stmtEqChk->execute([$lib, $idDivNijac]);
                            $$var = $stmtEqChk->fetchColumn() ?: null;
                            if (!$$var) {
                                $stmtEqIns->execute([$lib, $idDivNijac, $club]);
                                $$var = (int) $pdo->lastInsertId() ?: null;
                                if ($$var) {
                                    $stats['equipes_creees']++;
                                    $stats['log'][] = ['type' => 'equipe', 'val' => $lib];
                                }
                            }
                        }
                        if (!$idDom || !$idExt) {
                            $stats['erreurs'][] = "Équipe non créée : $libDom / $libExt";
                            continue;
                        }

                        $stmtRcChk->execute([$date, $idDom, $idExt]);
                        if ($stmtRcChk->fetchColumn()) {
                            $stats['doublons']++;
                            continue;
                        }

                        $stmtRcIns->execute([$date, '00:00:00', $idDivNijac, $pouleNum, $idDom, $idExt, 1, $journee, $arbitrage]);
                        $stats['rencontres_creees']++;
                        $stats['log'][] = ['type' => 'rencontre', 'val' => "$divCode P$pouleNum J$journee — $libDom vs $libExt ($date)"];
                    }
                }
            }

            return $this->response->setJSON(['ok' => true, 'stats' => $stats]);
        });
    }
}
