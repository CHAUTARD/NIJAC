<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Import des rencontres depuis l'API FFTT (E011), portage CI4 de
 * import_rencontres.php.
 *
 * Flux : Ligue → Épreuves → Divisions → Poules → Rencontres → BDD.
 * Admin uniquement (filtre "adminauth", comme includes/admin_required.php
 * côté legacy — voir SPECIFICATION.md, section E011, mise à jour pour
 * refléter ce flux API FFTT direct au lieu de l'ancien import de fichier
 * Excel).
 *
 * Pas de Model : appels API FFTT, résolution dynamique club/équipe/division,
 * upsert avec détection de doublons — trop éloigné du Query Builder simple.
 * Réutilise getPDO() directement, comme le fichier legacy.
 */
class ImportRencontresController extends BaseController
{
    private const ID_MESSAGE_CONVOCATION = 3;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        // Garantit que la clé "region" existe en configuration
        try {
            $pdo0 = getPDO();
            $pdo0->exec("INSERT IGNORE INTO configuration (cle, valeur) VALUES ('region', 'Normandie')");

            // Colonne club.EquipeNom (nom de base utilisé pour les équipes de ce club, ex.
            // "ROUEN SPO" pour "ROUEN SPO 2") — persistée dès qu'une équipe est résolue vers ce
            // club dans importerDivision(), pour que les imports des saisons suivantes retrouvent
            // le club directement au lieu de créer un club "fictif" faute de code FFTT.
            $colsClub = array_column($pdo0->query('SHOW COLUMNS FROM club')->fetchAll(), 'Field');
            if (!in_array('EquipeNom', $colsClub, true)) {
                $pdo0->exec('ALTER TABLE club ADD COLUMN EquipeNom VARCHAR(100) NULL');
            }
            // Un nom d'équipe ne doit désigner qu'un seul club. Try/catch isolé : si des doublons
            // existent déjà en base, on laisse la contrainte non posée plutôt que de faire échouer
            // le reste de la migration.
            $hasUqEquipeNom = (bool) $pdo0->query("SHOW INDEX FROM club WHERE Key_name = 'uq_club_equipenom'")->fetch();
            if (!$hasUqEquipeNom) {
                try {
                    $pdo0->exec('ALTER TABLE club ADD UNIQUE KEY uq_club_equipenom (EquipeNom)');
                } catch (\PDOException $e) {
                }
            }
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
     * Tente de deviner le code de division NIJAC depuis le libellé FFTT d'une division.
     */
    private function devinerDivisionCode(string $libelle): ?string
    {
        // Supprimer le préfixe de ligue FFTT de la forme "L09_", "L17_", etc. (4 premiers caractères)
        $libelle = preg_replace('/^[A-Z]\d{2}_/u', '', $libelle);

        $l   = mb_strtolower($libelle, 'UTF-8');
        $fem = str_contains($l, 'dame') || str_contains($l, 'féminin') || str_contains($l, 'feminin');

        // Correspondance directe avec les codes NIJAC (ex. "R1M", "R2M", "PNF"…)
        $codes = ['r1m', 'r2m', 'r3m', 'r4m', 'r1f', 'pnm', 'pnf', 'n1m', 'n2m', 'n3m', 'n1f', 'n2f'];
        if (in_array($l, $codes, true)) {
            return strtoupper($l);
        }

        // Correspondance par mots-clés
        if (str_contains($l, 'pré-nationale') || str_contains($l, 'pre-nationale')) {
            return $fem ? 'PNF' : 'PNM';
        }
        if (str_contains($l, 'régionale') || str_contains($l, 'regionale')) {
            preg_match('/(\d+)/', $l, $m);
            $n = (int) ($m[1] ?? 0);
            if ($fem) {
                return $n === 1 ? 'R1F' : null;
            }

            return match ($n) { 1 => 'R1M', 2 => 'R2M', 3 => 'R3M', 4 => 'R4M', default => null };
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

        $divsNijac = getPDO()->query('SELECT Division FROM division ORDER BY Division')->fetchAll();

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
            $region = trim(getConfig('region', 'Normandie'));
            $items  = getFfttRawClient()->listOrganismes('L');

            $regionN = mb_strtolower($region, 'UTF-8');
            $trouve  = null;
            foreach ($items as $org) {
                if (mb_strpos(mb_strtolower($org['libelle'] ?? '', 'UTF-8'), $regionN) !== false) {
                    $trouve = ['id' => (string) $org['id'], 'libelle' => $org['libelle']];
                    break;
                }
            }

            if ($trouve) {
                return $this->response->setJSON(['ok' => true, 'ligue' => $trouve, 'total' => count($items)]);
            }

            return $this->response->setJSON([
                'ok'     => false,
                'msg'    => "Aucune ligue « $region » parmi " . count($items) . ' ligues.',
                'ligues' => array_map(static fn ($o) => $o['libelle'] ?? '', $items),
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

            $items = getFfttRawClient()->listEpreuves((int) $organisme, 'E');

            // Ne conserver que les épreuves des saisons récentes : la FFTT numérote idepreuve de
            // façon croissante et continue toutes saisons confondues, donc les anciennes saisons
            // encombrent la liste sans intérêt. Seuil configurable (clé 'fftt_epreuve_min',
            // éditable dans Configuration générale E015 — à relever à chaque nouvelle saison).
            $seuilEpreuve = (int) getConfig('fftt_epreuve_min', '18369');
            $items        = array_values(array_filter(
                $items,
                static fn ($e) => (int) ($e['idepreuve'] ?? 0) > $seuilEpreuve
            ));

            // Pour les épreuves FED_ : dédoublonner par intitulé, garder le idepreuve le plus grand
            $fed    = [];
            $autres = [];
            foreach ($items as $ep) {
                $intitule = $ep['libelle'] ?? '';
                if (stripos($intitule, 'FED_') === 0) {
                    if (!isset($fed[$intitule]) || $ep['idepreuve'] > $fed[$intitule]['idepreuve']) {
                        $fed[$intitule] = $ep;
                    }
                } else {
                    $autres[] = $ep;
                }
            }
            $items = array_merge(array_values($fed), $autres);

            return $this->response->setJSON(['ok' => true, 'epreuves' => array_map(static fn ($e) => [
                'idepreuve' => $e['idepreuve'],
                'intitule'  => $e['libelle'],
            ], $items)]);
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
            $api        = getFfttRawClient();
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

    /**
     * Utilise le client bas niveau (getFfttRawClient()), comme les méthodes
     * suivantes (importerDivision, debugResultEqu) : la façade FFTTApi de
     * alamirault/fftt-api n'expose pas xml_division (aucune méthode
     * listDivisions()/équivalent), et ses méthodes de poules/rencontres
     * (listEquipePouleByLienDivision, listRencontrePouleByLienDivision)
     * partent d'un objet Equipe déjà connu (Club → equipe → lienDivision),
     * pas d'un ID de division FFTT choisi librement comme le fait ce flux
     * d'import (Ligue → Épreuve → Division → Poules, indépendamment de tout
     * club).
     */
    public function chargerDivisions(): ResponseInterface
    {

        return $this->tryJson(function () {
            $organisme = trim($this->request->getPost('organisme') ?? '');
            $epreuve   = trim($this->request->getPost('epreuve') ?? '');
            if ($organisme === '' || $epreuve === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants.']);
            }

            $api     = getFfttRawClient();
            $reponse = $api->request('xml_division', ['organisme' => $organisme, 'epreuve' => $epreuve, 'type' => 'E']);
            $items   = $this->ffttItems($reponse, 'division');

            $pdo       = getPDO();
            $divsNijac = $pdo->query('SELECT Division FROM division ORDER BY Division')->fetchAll();

            foreach ($items as &$div) {
                $libelle                       = (string) ($div['libelle'] ?? '');
                $div['id_division_nijac_auto'] = $this->devinerDivisionCode($libelle);
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

            $organisme = trim($this->request->getPost('organisme') ?? '');
            $epreuve   = trim($this->request->getPost('epreuve') ?? '');
            $divFftt   = trim($this->request->getPost('division_fftt') ?? '');
            $divCode   = trim($this->request->getPost('id_division') ?? '');
            $phase     = (int) ($this->request->getPost('phase') ?? 1);

            if ($organisme === '' || $epreuve === '' || $divFftt === '' || $divCode === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants.']);
            }

            $stmtDiv = $pdo->prepare('SELECT ArbitrageCRA FROM division WHERE Division=?');
            $stmtDiv->execute([$divCode]);
            $divInfo = $stmtDiv->fetch();
            if (!$divInfo) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Division NIJAC $divCode introuvable."]);
            }
            $arbitrage   = (int) $divInfo['ArbitrageCRA'];
            $isNationale = str_starts_with($divCode, 'N');

            $api = getFfttRawClient();

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

            $stmtClubIns          = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtClubByNom        = $pdo->prepare('SELECT Id_Club FROM club WHERE Nom=? LIMIT 1');
            $stmtClubById         = $pdo->prepare('SELECT Id_Club FROM club WHERE Id_Club=? LIMIT 1');
            $stmtClubByEquipeNom  = $pdo->prepare('SELECT Id_Club FROM club WHERE EquipeNom=? OR Nom=? LIMIT 1');
            $stmtClubEquipeNom    = $pdo->prepare('UPDATE club SET EquipeNom=? WHERE Id_Club=?');
            $stmtNatChk    = $pdo->prepare('SELECT 1 FROM equipe_nationale WHERE Nom=? AND Division=? LIMIT 1');
            $stmtNatIns    = $pdo->prepare(
                'INSERT INTO equipe_nationale (Nom, Division, Poule, Rang, Id_Club, Id_Equipe) VALUES (?,?,?,0,?,?)'
            );
            $stmtEqChk = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom=? AND Division=? LIMIT 1');
            $stmtEqIns = $pdo->prepare('INSERT INTO equipe (Nom, Division, Id_Club, JAdemande) VALUES (?,?,?,0)');
            $stmtRcChk = $pdo->prepare('SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1');
            $stmtRcIns = $pdo->prepare(
                'INSERT INTO rencontre (Date,Heure,Poule,Id_EquipeDom,Id_EquipeExt,Phase,Journee,ArbitrageObligatoire)
                 VALUES (?,?,?,?,?,?,?,?)'
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
                $nomClubDom = trim((string) preg_replace('/\s+\d+$/', '', $libDom));
                $nomClubExt = trim((string) preg_replace('/\s+\d+$/', '', $libExt));
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

                    // Affectation automatique : un club déjà associé à ce nom d'équipe (import
                    // précédent, voir EquipeNom) ou portant ce nom officiel est réutilisé directement,
                    // sans créer de club fictif.
                    $stmtClubByEquipeNom->execute([$nomTronc, $nomTronc]);
                    $existant = $stmtClubByEquipeNom->fetchColumn();
                    if ($existant) {
                        $idVar = $existant;
                    } else {
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
                    // Persiste le nom de base ("ROUEN SPO" pour "ROUEN SPO 2") sur le club résolu,
                    // pour que les imports des saisons suivantes le retrouvent directement au lieu
                    // de recréer un club fictif faute de code FFTT. EquipeNom est UNIQUE : si un
                    // autre club porte déjà ce nom (collision rare), on garde l'Id_Club résolu
                    // ci-dessus et on ignore juste cette écriture secondaire.
                    try {
                        $stmtClubEquipeNom->execute([$nomTronc, $idVar]);
                    } catch (\PDOException $e) {
                    }
                }
                unset($idVar);

                foreach ([[$libDom, $clubDom, 'idDom'], [$libExt, $clubExt, 'idExt']] as [$lib, $club, $var]) {
                    $stmtEqChk->execute([$lib, $divCode]);
                    $$var = $stmtEqChk->fetchColumn();
                    if (!$$var) {
                        $stmtEqIns->execute([$lib, $divCode, $club]);
                        $$var = (int) $pdo->lastInsertId();
                        if ($$var) {
                            $stats['equipes_creees']++;
                            $stats['log'][] = ['type' => 'equipe', 'op' => 'créée', 'val' => $lib];
                        } else {
                            $stmtEqChk->execute([$lib, $divCode]);
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
                        $stmtNatChk->execute([$lib, $divCode]);
                        if (!$stmtNatChk->fetchColumn()) {
                            $stmtNatIns->execute([$lib, $divCode, $pouleNum, $club, $idEq]);
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

                $stmtRcIns->execute([$date, $heure, $pouleNum, $idDom, $idExt, $phase, $journee, $arbitrage]);
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
                 JOIN equipe   ed ON ed.Id_Equipe   = r.Id_EquipeDom
                 JOIN division dv ON dv.Division = ed.Division
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 ORDER BY r.Date ASC, r.Heure ASC, dv.Ord ASC'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'rencontres' => $rows, 'total' => count($rows)]);
        });
    }

    /**
     * Liste les JA actifs du club recevant d'une rencontre, pour la popup de
     * désignation directe (R3M/R4M — arbitrage non fourni par la CRA, c'est
     * au club recevant de désigner l'un de ses arbitres).
     */
    public function candidatsArbitre(): ResponseInterface
    {
        return $this->tryJson(function () {
            $idRenc = (int) ($this->request->getGet('id_rencontre') ?? 0);
            if (!$idRenc) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre manquante']);
            }

            $pdo  = getPDO();
            $stmt = $pdo->prepare(
                'SELECT ed.Id_Club, ed.Division
                 FROM rencontre r
                 JOIN equipe   ed ON ed.Id_Equipe   = r.Id_EquipeDom
                 WHERE r.Id_Rencontre = ?'
            );
            $stmt->execute([$idRenc]);
            $renc = $stmt->fetch();
            if (!$renc) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre introuvable']);
            }
            if (!in_array($renc['Division'], ['R3M', 'R4M'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Désignation directe réservée aux R3M/R4M']);
            }

            $stmtJa = $pdo->prepare('SELECT Id_JA, Nom, Prenom, Grade FROM ja WHERE Id_Club = ? AND Actif = 1 ORDER BY Nom, Prenom');
            $stmtJa->execute([$renc['Id_Club']]);

            return $this->response->setJSON(['ok' => true, 'arbitres' => $stmtJa->fetchAll()]);
        });
    }

    /**
     * Désigne un JA du club recevant sur une rencontre R3M/R4M sans arbitre
     * nominé, et lui envoie immédiatement la convocation — même logique que
     * InfoRencontreController::designerJaPourRencontre() (E030, auto-désignation
     * du JA connecté), adaptée ici au choix par l'admin d'un JA quelconque du
     * club recevant. La disponibilité est générée automatiquement (Reponse='P',
     * comme en E030) : ces arbitres officient leur propre match, sans saisie
     * préalable de disponibilité.
     */
    public function designerArbitre(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $idRenc = (int) ($this->request->getPost('id_rencontre') ?? 0);
            $idJa   = (int) ($this->request->getPost('id_ja') ?? 0);
            if (!$idRenc || !$idJa) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants']);
            }

            $stmtJa = $pdo->prepare('SELECT Id_JA, Nom, Prenom, Email, Id_Club FROM ja WHERE Id_JA = ? AND Actif = 1');
            $stmtJa->execute([$idJa]);
            $ja = $stmtJa->fetch();
            if (!$ja) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Arbitre introuvable']);
            }

            $stmtR = $pdo->prepare(
                'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                        ed.Division, RIGHT(ed.Division, 1) AS SexeCode,
                        ed.Nom AS NomDom, ev.Nom AS NomExt,
                        s.Nom AS SalleNom, s.Adresse AS SalleAdresse,
                        lps.CodePostal AS SalleCP, lps.Nom AS SalleVille,
                        cl.CorNom AS CorrNom, cl.CorEmail AS CorrEmail, cl.CorTelephone AS CorrTel
                 FROM rencontre r
                 JOIN equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
                 LEFT JOIN equipe ev  ON ev.Id_Equipe   = r.Id_EquipeExt
                 LEFT JOIN salle s    ON s.Id_Salle     = r.Id_Salle
                 LEFT JOIN laposte lps ON lps.Id_LaPoste = s.Id_Laposte
                 LEFT JOIN club cl    ON cl.Id_Club     = ed.Id_Club
                 WHERE r.Id_Rencontre = ? AND ed.Id_Club = ?'
            );
            $stmtR->execute([$idRenc, $ja['Id_Club']]);
            $renc = $stmtR->fetch();
            if (!$renc) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Cet arbitre n'appartient pas au club recevant de cette rencontre"]);
            }
            if (!in_array($renc['Division'], ['R3M', 'R4M'], true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Désignation directe réservée aux R3M/R4M']);
            }

            $already = $pdo->prepare('SELECT Id_Nomination FROM nomination WHERE Id_Rencontre = ?');
            $already->execute([$idRenc]);
            if ($already->fetch()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Un arbitre est déjà désigné pour cette rencontre']);
            }

            $dispo = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
            $dispo->execute([$idJa, $idRenc]);
            $idDispo = $dispo->fetchColumn();
            if (!$idDispo) {
                $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse) VALUES (?, ?, ?, 'P', CURDATE())")
                    ->execute([$idJa, $idRenc, $renc['Date']]);
                $idDispo = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('INSERT INTO nomination (Id_Rencontre, Id_Disponible, DateNomination, Valide, EmailEnvoye) VALUES (?, ?, CURDATE(), 1, 0)')
                ->execute([$idRenc, $idDispo]);
            $idNomination = (int) $pdo->lastInsertId();

            $idUtilisateurCourant = (int) ($_SESSION['utilisateur']['id'] ?? 0);
            $msgRow    = resoudreModeleMessagerie($pdo, self::ID_MESSAGE_CONVOCATION, $idUtilisateurCourant);
            $emailSent = false;
            if ($msgRow && !empty($ja['Email'])) {
                $marqueurs = construireMarqueursMessage($ja, $_SESSION['utilisateur'] ?? [], [
                    'id_nomination' => $idNomination,
                    'sexe_code'     => $renc['SexeCode']     ?? null,
                    'date'          => $renc['Date']         ?? null,
                    'heure'         => $renc['Heure']        ?? null,
                    'journee'       => $renc['Journee']      ?? null,
                    'poule'         => $renc['Poule']        ?? null,
                    'division'      => $renc['Division']     ?? null,
                    'dom'           => $renc['NomDom']       ?? null,
                    'ext'           => $renc['NomExt']       ?? null,
                    'salle_nom'     => $renc['SalleNom']     ?? null,
                    'salle_adresse' => $renc['SalleAdresse'] ?? null,
                    'salle_cp'      => $renc['SalleCP']      ?? null,
                    'salle_ville'   => $renc['SalleVille']   ?? null,
                    'corr_nom'      => $renc['CorrNom']      ?? null,
                    'corr_email'    => $renc['CorrEmail']    ?? null,
                    'corr_tel'      => $renc['CorrTel']      ?? null,
                ]);
                $rendu = remplacerMarqueursMessage($msgRow['Sujet'], $msgRow['Message'], $marqueurs);
                $sujet = $rendu['sujet'];
                $corps = $rendu['corps'];
                try {
                    $dest    = getEmailDestinataire($ja['Email']);
                    $isHtml  = strip_tags($corps) !== $corps;
                    $mail    = getNijacMailer();
                    $mail->isHTML($isHtml);
                    $mail->addAddress($dest, $ja['Prenom'] . ' ' . $ja['Nom']);
                    $modeDev       = isModeDeveloppement();
                    $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? "[DEV] $sujet" : $sujet;
                    $mail->Body    = $corps;
                    if ($isHtml) {
                        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
                    }
                    $mail->send();
                    enregistrerEnvois(1);
                    $pdo->prepare('UPDATE nomination SET EmailEnvoye = 1 WHERE Id_Nomination = ?')->execute([$idNomination]);
                    $emailSent = true;
                } catch (\Exception $e) {
                    error_log('[NIJAC] import_rencontres.php designerArbitre mail : ' . $e->getMessage());
                }
            }

            $msg = $emailSent
                ? 'Arbitre désigné. La convocation lui a été envoyée par email.'
                : "Arbitre désigné, mais l'envoi de la convocation a échoué (voir logs).";

            return $this->response->setJSON(['ok' => true, 'msg' => $msg, 'ja' => ['nom' => $ja['Nom'], 'prenom' => $ja['Prenom']]]);
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
