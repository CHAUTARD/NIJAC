<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Import des rencontres depuis l'API FFTT (E011), portage CI4 de
 * import_rencontres.php.
 *
 * Flux : Ligue → Épreuves → Divisions → Poules → Rencontres → BDD.
 * Admin uniquement (filtre "adminauth", comme includes/admin_required.php
 * côté legacy — la SPECIFICATION.md mentionne à tort "Administrateur et
 * Nominateur", document déjà obsolète par ailleurs : la page actuelle appelle
 * l'API FFTT en direct, pas un import de fichier Excel comme décrit).
 *
 * Pas de Model : appels API FFTT, résolution dynamique club/équipe/division,
 * upsert avec détection de doublons — trop éloigné du Query Builder simple.
 * Réutilise getPDO() directement, comme le fichier legacy.
 */
class ImportRencontresController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Garantit que la clé "region" existe en configuration
        try {
            getPDO()->exec("INSERT IGNORE INTO configuration (cle, valeur) VALUES ('region', 'Normandie')");
        } catch (\PDOException $e) {
        }
    }

    /**
     * Exécute une action et convertit toute exception (appel API FFTT en
     * échec, contrainte BDD…) en réponse JSON ['ok' => false, 'msg' => ...]
     * plutôt que de laisser remonter une page d'erreur HTML — import_rencontres.php
     * enveloppe de la même façon la totalité de son dispatcher d'actions.
     */
    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            error_log('[NIJAC] import_rencontres.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] import_rencontres.php : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    /** Normalise une réponse FFTT (objet unique ou tableau) en tableau indexé. */
    private function ffttItems(array $data, string $key): array
    {
        $items = $data[$key] ?? [];
        if (empty($items)) {
            return [];
        }

        return isset($items[0]) ? $items : [$items];
    }

    /**
     * Tente de deviner l'Id_Division NIJAC depuis le libellé FFTT d'une division.
     * IDs hardcodés d'après la table division actuelle.
     */
    private function devinerIdDivision(string $libelle): ?int
    {
        // Supprimer le préfixe de ligue FFTT de la forme "L09_", "L17_", etc. (4 premiers caractères)
        $libelle = preg_replace('/^[A-Z]\d{2}_/u', '', $libelle);

        $l   = mb_strtolower($libelle, 'UTF-8');
        $fem = str_contains($l, 'dame') || str_contains($l, 'féminin') || str_contains($l, 'feminin');

        // Correspondance directe avec les codes NIJAC (ex. "R1M", "R2M", "PNF"…)
        $map = [
            'r1m' => 3, 'r2m' => 2, 'r3m' => 1, 'r4m' => 10,
            'r1f' => 8, 'pnm' => 4, 'pnf' => 9,
            'n1m' => 7, 'n2m' => 6, 'n3m' => 5,
            'n1f' => 12, 'n2f' => 11,
        ];
        if (isset($map[$l])) {
            return $map[$l];
        }

        // Correspondance par mots-clés
        if (str_contains($l, 'pré-nationale') || str_contains($l, 'pre-nationale')) {
            return $fem ? 9 : 4;   // PNF=9, PNM=4
        }
        if (str_contains($l, 'régionale') || str_contains($l, 'regionale')) {
            preg_match('/(\d+)/', $l, $m);
            $n = (int) ($m[1] ?? 0);
            if ($fem) {
                return $n === 1 ? 8 : null;
            }

            return match ($n) { 1 => 3, 2 => 2, 3 => 1, 4 => 10, default => null };
        }

        return null;
    }

    /** Parse une date FFTT ("DD/MM/YYYY", "DD/MM/YYYY HHhMM", "YYYY-MM-DD") → "YYYY-MM-DD" ou null. */
    private function parseDateFftt(string $s): ?string
    {
        if (preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return $m[0];
        }

        return null;
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $region = getConfig('region', 'Normandie');
        $ffttOk = (getFfttAppId() !== '' && getFfttAppKey() !== '');

        // Calcul de la phase actuelle
        $today = new \DateTime();
        $md    = (int) $today->format('m') * 100 + (int) $today->format('d');
        $toMd  = static fn (string $s) => (int) substr($s, 0, 2) * 100 + (int) substr($s, 3, 2);

        $p1FinMd = $toMd(getConfig('phase1_fin', '01-31'));
        $p2DbtMd = $toMd(getConfig('phase2_debut', '02-01'));
        $p2FinMd = $toMd(getConfig('phase2_fin', '06-30'));
        $p1DbtMd = $toMd(getConfig('phase1_debut', '09-01'));

        $saison = getConfig('saison', date('Y') . '-' . (date('Y') + 1));
        $parts  = explode('-', $saison);
        $a1     = (int) ($parts[0] ?? date('Y'));
        $a2     = (int) ($parts[1] ?? $a1 + 1);

        $phaseOptions = [
            ['key' => 'p1', 'label' => "Phase 1 · saison $saison"],
            ['key' => 'p2', 'label' => "Phase 2 · saison $saison"],
            ['key' => 'prep', 'label' => "Phase 1 · saison $saison (à venir)"],
        ];

        if ($md >= $p1DbtMd || $md <= $p1FinMd) {
            $phaseLabel = "Phase 1 · saison $saison";
            $phaseKey   = 'p1';
        } elseif ($md >= $p2DbtMd && $md <= $p2FinMd) {
            $phaseLabel = "Phase 2 · saison $saison";
            $phaseKey   = 'p2';
        } else {
            $phaseLabel = "Phase 1 · saison $saison (à venir)";
            $phaseKey   = 'prep';
        }

        $divsNijac = getPDO()->query('SELECT Id_Division, Division FROM division ORDER BY Division')->fetchAll();

        $data = [
            'nomComplet'    => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'   => $moi['id_departement'] ?? '',
            'changeLogin'   => !empty($moi['change_login']),
            'region'        => $region,
            'ffttOk'        => $ffttOk,
            'divsNijac'     => $divsNijac,
            'phaseOptions'  => $phaseOptions,
            'phaseKey'      => $phaseKey,
            'phaseLabel'    => $phaseLabel,
        ];

        return view('import_rencontres_index', $data);
    }

    public function chercherLigue(): ResponseInterface
    {

        return $this->tryJson(function () {
            $region  = trim(getConfig('region', 'Normandie'));
            $api     = getFfttApi();
            $reponse = $api->request('xml_organisme', ['type' => 'L']);
            $items   = $this->ffttItems($reponse, 'organisme');

            $regionN = mb_strtolower($region, 'UTF-8');
            $trouve  = null;
            $champId = static function (array $org): string {
                foreach (['id', 'ident', 'idorga', 'numero', 'numorg'] as $f) {
                    if (isset($org[$f]) && (string) $org[$f] !== '') {
                        return (string) $org[$f];
                    }
                }

                return '';
            };

            foreach ($items as $org) {
                $libelle = (string) ($org['libelle'] ?? '');
                if (mb_strpos(mb_strtolower($libelle, 'UTF-8'), $regionN) !== false) {
                    $trouve = ['id' => $champId($org), 'libelle' => $libelle];
                    break;
                }
            }

            $clesPremier = !empty($items) ? array_keys($items[0]) : [];

            if ($trouve && $trouve['id'] !== '') {
                return $this->response->setJSON(['ok' => true, 'ligue' => $trouve, 'total' => count($items)]);
            }
            if ($trouve) {
                return $this->response->setJSON([
                    'ok'     => false,
                    'msg'    => "Ligue « {$trouve['libelle']} » trouvée mais champ ID introuvable. Clés disponibles : " . implode(', ', $clesPremier),
                    'ligues' => array_column($items, 'libelle'),
                    'debug'  => $items[0] ?? [],
                ]);
            }

            return $this->response->setJSON([
                'ok'     => false,
                'msg'    => "Aucune ligue « $region » parmi " . count($items) . ' ligues.',
                'ligues' => array_column($items, 'libelle'),
            ]);
        });
    }

    public function chargerEpreuves(): ResponseInterface
    {

        return $this->tryJson(function () {
            $organisme = trim($this->request->getPost('organisme') ?? '');
            if ($organisme === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Organisme manquant.']);
            }

            $api     = getFfttApi();
            $reponse = $api->request('xml_epreuve', ['organisme' => $organisme, 'type' => 'E']);
            $items   = $this->ffttItems($reponse, 'epreuve');

            // Pour les épreuves FED_ : dédoublonner par intitulé, garder le idepreuve le plus grand
            $fed    = [];
            $autres = [];
            foreach ($items as $ep) {
                $intitule = (string) ($ep['intitule'] ?? $ep['libelle'] ?? '');
                $idEp     = (int) ($ep['idepreuve'] ?? $ep['ident'] ?? 0);
                if (stripos($intitule, 'FED_') === 0) {
                    if (!isset($fed[$intitule]) || $idEp > (int) ($fed[$intitule]['idepreuve'] ?? $fed[$intitule]['ident'] ?? 0)) {
                        $fed[$intitule] = $ep;
                    }
                } else {
                    $autres[] = $ep;
                }
            }
            $items = array_merge(array_values($fed), $autres);

            return $this->response->setJSON(['ok' => true, 'epreuves' => $items]);
        });
    }

    /**
     * Action de débogage non appelée par le JS de import_rencontres.php —
     * portée à l'identique pour parité, comme les autres actions mortes déjà
     * rencontrées dans ce portage (liste_tables_db, maj_laposte…).
     */
    public function debugResultEqu(): ResponseInterface
    {

        return $this->tryJson(function () {
            $divFftt = trim($this->request->getPost('division_fftt') ?? '');
            if ($divFftt === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'division_fftt manquant']);
            }
            $api        = getFfttApi();
            $resultats  = [];
            $cxPoules   = [];
            $pouleItems = [];

            try {
                $r          = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
                $pouleItems = isset($r['poule']) ? (isset($r['poule'][0]) ? $r['poule'] : [$r['poule']]) : [];
                foreach ($pouleItems as $i => $p) {
                    $lienRaw = $p['lien'] ?? '';
                    $lienStr = is_array($lienRaw) ? '' : (string) $lienRaw;
                    parse_str(html_entity_decode($lienStr), $lp);
                    if (!empty($lp['cx_poule'])) {
                        $cxPoules[$i + 1] = $lp['cx_poule'];
                    }
                }
                $resultats[] = ['test' => 'action=poule', 'url' => $api->lastUrl(), 'nb_poules' => count($pouleItems), 'cx_poules' => $cxPoules, 'apercu' => $pouleItems];
            } catch (\Throwable $e) {
                $resultats[] = ['test' => 'action=poule', 'erreur' => $e->getMessage(), 'url' => $api->lastUrl()];
            }

            if (count($cxPoules) === count($pouleItems) && count($cxPoules) > 0) {
                foreach ($cxPoules as $num => $cx) {
                    try {
                        $r     = $api->request('xml_result_equ', ['D1' => $divFftt, 'cx_poule' => $cx, 'auto' => '1', 'type' => 'E']);
                        $tours = isset($r['tour']) ? (isset($r['tour'][0]) ? $r['tour'] : [$r['tour']]) : [];
                        $resultats[] = ['test' => "poule $num (cx=$cx)", 'url' => $api->lastUrl(), 'nb_tours' => count($tours), 'apercu' => array_slice($tours, 0, 2)];
                    } catch (\Throwable $e) {
                        $resultats[] = ['test' => "poule $num", 'erreur' => $e->getMessage(), 'url' => $api->lastUrl()];
                    }
                }
            } else {
                try {
                    $r     = $api->request('xml_result_equ', ['D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
                    $tours = isset($r['tour']) ? (isset($r['tour'][0]) ? $r['tour'] : [$r['tour']]) : [];
                    $resultats[] = ['test' => 'fallback global (lien vide)', 'url' => $api->lastUrl(), 'nb_tours' => count($tours), 'apercu' => array_slice($tours, 0, 2)];
                } catch (\Throwable $e) {
                    $resultats[] = ['test' => 'fallback global', 'erreur' => $e->getMessage(), 'url' => $api->lastUrl()];
                }
            }

            return $this->response->setJSON(['ok' => true, 'resultats' => $resultats]);
        });
    }

    public function chargerDivisions(): ResponseInterface
    {

        return $this->tryJson(function () {
            $organisme = trim($this->request->getPost('organisme') ?? '');
            $epreuve   = trim($this->request->getPost('epreuve') ?? '');
            if ($organisme === '' || $epreuve === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants.']);
            }

            $api     = getFfttApi();
            $reponse = $api->request('xml_division', ['organisme' => $organisme, 'epreuve' => $epreuve, 'type' => 'E']);
            $items   = $this->ffttItems($reponse, 'division');

            $pdo       = getPDO();
            $divsNijac = $pdo->query('SELECT Id_Division, Division FROM division ORDER BY Division')->fetchAll();

            foreach ($items as &$div) {
                $libelle                       = (string) ($div['libelle'] ?? '');
                $div['id_division_nijac_auto'] = $this->devinerIdDivision($libelle);
            }
            unset($div);

            return $this->response->setJSON(['ok' => true, 'divisions' => $items, 'divisions_nijac' => $divsNijac]);
        });
    }

    public function importerDivision(): ResponseInterface
    {
        set_time_limit(180);

        return $this->tryJson(function () {
            $pdo = getPDO();

            $organisme  = trim($this->request->getPost('organisme') ?? '');
            $epreuve    = trim($this->request->getPost('epreuve') ?? '');
            $divFftt    = trim($this->request->getPost('division_fftt') ?? '');
            $idDivNijac = (int) ($this->request->getPost('id_division') ?? 0);
            $phase      = (int) ($this->request->getPost('phase') ?? 1);

            if ($organisme === '' || $epreuve === '' || $divFftt === '' || $idDivNijac <= 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants.']);
            }

            $stmtDiv = $pdo->prepare('SELECT ArbitrageCRA, Division FROM division WHERE Id_Division=?');
            $stmtDiv->execute([$idDivNijac]);
            $divInfo = $stmtDiv->fetch();
            if (!$divInfo) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Division NIJAC #$idDivNijac introuvable."]);
            }
            $arbitrage   = (int) $divInfo['ArbitrageCRA'];
            $divCode     = (string) $divInfo['Division'];
            $isNationale = str_starts_with($divCode, 'N');

            $api = getFfttApi();

            // Étape 1 : liste des poules (libellés + cx_poule via champ 'lien')
            $rPoules    = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
            $pouleItems = $this->ffttItems($rPoules, 'poule');

            $pouleLibelles = [];
            $cxPoules      = [];
            foreach ($pouleItems as $i => $p) {
                $num                 = $i + 1;
                $pouleLibelles[$num] = trim((string) ($p['libelle'] ?? "Poule $num"));
                $lienRaw             = $p['lien'] ?? '';
                $lienStr             = is_array($lienRaw) ? '' : (string) $lienRaw;
                if ($lienStr !== '') {
                    parse_str(html_entity_decode($lienStr), $lp);
                    if (!empty($lp['cx_poule'])) {
                        $cxPoules[$num] = $lp['cx_poule'];
                    }
                }
            }

            // Étape 2 : récupérer les rencontres poule par poule (si cx_poule disponibles)
            //           ou en un seul appel global (si 'lien' vide — cas constaté avec apiv2)
            $rencontres = [];
            if (count($cxPoules) === count($pouleItems) && count($cxPoules) > 0) {
                foreach ($cxPoules as $num => $cx) {
                    $rP = $api->request('xml_result_equ', ['D1' => $divFftt, 'cx_poule' => $cx, 'auto' => '1', 'type' => 'E']);
                    foreach ($this->ffttItems($rP, 'tour') as $rc) {
                        $rc['_poule_num'] = $num;
                        $rencontres[]     = $rc;
                    }
                }
            } else {
                $rAll       = $api->request('xml_result_equ', ['D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
                $rencontres = $this->ffttItems($rAll, 'tour');
            }

            $stats = ['poules' => [], 'equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'erreurs' => [], 'log' => []];

            $stmtClubIns   = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtClubByNom = $pdo->prepare('SELECT Id_Club FROM club WHERE Nom=? LIMIT 1');
            $stmtClubById  = $pdo->prepare('SELECT Id_Club FROM club WHERE Id_Club=? LIMIT 1');
            $stmtNatChk    = $pdo->prepare('SELECT 1 FROM equipe_nationale WHERE Nom=? AND id_division=? LIMIT 1');
            $stmtNatIns    = $pdo->prepare(
                'INSERT INTO equipe_nationale (Nom, id_division, Poule, Rang, Id_Club, Id_Equipe) VALUES (?,?,?,0,?,?)'
            );
            $stmtEqChk = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom=? AND Id_Division=? LIMIT 1');
            $stmtEqIns = $pdo->prepare('INSERT INTO equipe (Nom, Id_Division, Id_Club, JAdemande) VALUES (?,?,?,0)');
            $stmtRcChk = $pdo->prepare('SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1');
            $stmtRcIns = $pdo->prepare(
                'INSERT INTO rencontre (Date,Heure,Id_Division,Poule,Id_EquipeDom,Id_EquipeExt,Phase,Journee,ArbitrageObligatoire)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );

            foreach ($rencontres as $rc) {
                // Champs confirmés : equa, equb, dateprevue, libelle ("Poule X - tour n°Y du …")
                $libDom  = mb_substr(trim((string) ($rc['equa'] ?? '')), 0, 100);
                $libExt  = mb_substr(trim((string) ($rc['equb'] ?? '')), 0, 100);
                $dateStr = trim((string) ($rc['dateprevue'] ?? ''));
                $libelle = trim((string) ($rc['libelle'] ?? ''));

                // Numéro de poule : injecté directement si appel par poule, sinon extrait du libellé
                $pouleNum = isset($rc['_poule_num']) ? (int) $rc['_poule_num'] : 0;
                $journee  = 0;
                if ($pouleNum === 0 && preg_match('/poule\s+(\d+)/i', $libelle, $m)) {
                    $pouleNum = (int) $m[1];
                }
                if (preg_match('/tour\s+n[°o]?\s*(\d+)/i', $libelle, $m)) {
                    $journee = (int) $m[1];
                }

                $date = $this->parseDateFftt($dateStr);
                if (!$date || $libDom === '' || $libExt === '') {
                    $stats['erreurs'][] = "Rencontre ignorée : dom=\"$libDom\" ext=\"$libExt\" date=\"$dateStr\"";
                    continue;
                }
                $heure = '00:00:00'; // xml_result_equ ne fournit pas l'heure

                if ($pouleNum > 0) {
                    $stats['poules'][$pouleNum] = true;
                }

                // xml_result_equ ne fournit pas de code équipe → club fictif basé sur nom
                $nomClubDom = preg_replace('/\s+\d+$/', '', $libDom);
                $nomClubExt = preg_replace('/\s+\d+$/', '', $libExt);
                $clubDom    = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nomClubDom), 0, 8));
                $clubExt    = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nomClubExt), 0, 8));
                if ($clubDom === '') {
                    $clubDom = 'UNKNOWN1';
                }
                if ($clubExt === '') {
                    $clubExt = 'UNKNOWN2';
                }

                // Résoudre l'Id_Club réel pour dom et ext
                // (INSERT IGNORE peut être ignoré si Nom UNIQUE déjà pris par un autre Id_Club)
                foreach ([['dom', &$clubDom, $nomClubDom], ['ext', &$clubExt, $nomClubExt]] as [, &$idVar, $nom]) {
                    $nomTronc = mb_substr($nom, 0, 100);
                    $stmtClubIns->execute([$idVar, $nomTronc]);
                    if ($pdo->lastInsertId()) {
                        $stats['log'][] = ['type' => 'club', 'op' => 'créé', 'val' => "$idVar — $nom"];
                    } else {
                        // INSERT ignoré : vérifier si notre Id_Club existe, sinon chercher par Nom
                        $stmtClubById->execute([$idVar]);
                        if (!$stmtClubById->fetchColumn()) {
                            $stmtClubByNom->execute([$nomTronc]);
                            $found = $stmtClubByNom->fetchColumn();
                            if ($found) {
                                $idVar = $found;
                            }
                        }
                    }
                }
                unset($idVar);

                foreach ([[$libDom, $clubDom, 'idDom'], [$libExt, $clubExt, 'idExt']] as [$lib, $club, $var]) {
                    $stmtEqChk->execute([$lib, $idDivNijac]);
                    $$var = $stmtEqChk->fetchColumn();
                    if (!$$var) {
                        $stmtEqIns->execute([$lib, $idDivNijac, $club]);
                        $$var = (int) $pdo->lastInsertId();
                        if ($$var) {
                            $stats['equipes_creees']++;
                            $stats['log'][] = ['type' => 'equipe', 'op' => 'créée', 'val' => $lib];
                        } else {
                            $stmtEqChk->execute([$lib, $idDivNijac]);
                            $$var = (int) $stmtEqChk->fetchColumn();
                        }
                    }
                }

                if (!$idDom || !$idExt) {
                    $stats['erreurs'][] = "Équipe non créée : \"$libDom\" ou \"$libExt\"";
                    $stats['log'][]     = ['type' => 'erreur', 'op' => 'échec équipe', 'val' => "$libDom / $libExt"];
                    continue;
                }

                // Alimenter equipe_nationale si division Nationale (N1M, N2M…)
                if ($isNationale) {
                    foreach ([[$libDom, $clubDom, $idDom], [$libExt, $clubExt, $idExt]] as [$lib, $club, $idEq]) {
                        $stmtNatChk->execute([$lib, $idDivNijac]);
                        if (!$stmtNatChk->fetchColumn()) {
                            $stmtNatIns->execute([$lib, $idDivNijac, $pouleNum, $club, $idEq]);
                            $stats['log'][] = ['type' => 'nationale', 'op' => 'nat. ajoutée', 'val' => "$divCode P$pouleNum — $lib"];
                        }
                    }
                }

                $stmtRcChk->execute([$date, $idDom, $idExt]);
                if ($stmtRcChk->fetchColumn()) {
                    $stats['doublons']++;
                    $stats['log'][] = ['type' => 'doublon', 'op' => 'ignorée', 'val' => "P$pouleNum J$journee — $libDom vs $libExt ($date)"];
                    continue;
                }

                $stmtRcIns->execute([$date, $heure, $idDivNijac, $pouleNum, $idDom, $idExt, $phase, $journee, $arbitrage]);
                $stats['rencontres_creees']++;
                $stats['log'][] = ['type' => 'rencontre', 'op' => 'créée', 'val' => "P$pouleNum J$journee — $libDom vs $libExt ($date)"];
            }

            $stats['poules'] = count($stats['poules']);

            return $this->response->setJSON(['ok' => true, 'stats' => $stats, 'nb_rencontres' => count($rencontres)]);
        });
    }

    public function listeRencontres(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule, r.Phase,
                        dv.Division AS DivisionCode, dv.Nom AS DivisionNom, dv.Color AS DivisionColor,
                        ed.Nom AS NomDom, ev.Nom AS NomExt,
                        r.ArbitrageObligatoire,
                        (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS NbNominations
                 FROM rencontre r
                 JOIN division dv ON dv.Id_Division = r.Id_Division
                 JOIN equipe   ed ON ed.Id_Equipe   = r.Id_EquipeDom
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 ORDER BY r.Date ASC, r.Heure ASC, dv.Ord ASC'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'rencontres' => $rows, 'total' => count($rows)]);
        });
    }

    public function compterTables(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $tables = ['equipe', 'equipe_nationale', 'rencontre', 'nomination', 'disponible'];
            $counts = [];
            foreach ($tables as $t) {
                $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            }

            return $this->response->setJSON(['ok' => true, 'counts' => $counts]);
        });
    }
}
