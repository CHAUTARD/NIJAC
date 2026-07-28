<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * NIJAC – Import Rencontres Nationales (E017), portage CI4 de
 * import_rencontres_nat.php.
 *
 * Deux méthodes d'alimentation de la table equipe_nationale, partagées par
 * l'écran (onglets) :
 * - Import API : charge les équipes nationales depuis l'API FFTT (N1M, N2M,
 *   N3M, N1F, N2F).
 * - Importation Excel/texte : réimport d'un fichier FFTT (.xlsx à plusieurs
 *   feuilles ou .txt recopié à la main, voir parseNatFichier()), poules par
 *   rang, utile en début de saison quand l'API FFTT n'a pas encore les poules
 *   alimentées — c'est la méthode originelle de cet écran avant le passage à
 *   l'API (voir git log).
 * Les deux onglets alimentent la même table equipe_nationale et partagent
 * la section d'association équipe → club/département. L'import des
 * rencontres elles-mêmes (importerRencontresExcel()) se fait uniquement à
 * partir du calendrier lu dans le fichier Excel/texte — pas d'appel FFTT
 * pour cette étape, l'ancien flux via xml_result_equ (importerRencontres())
 * ayant été retiré au profit de cette seule source.
 *
 * Admin uniquement (filtre "adminauth", comme includes/admin_required.php
 * côté legacy).
 *
 * Pas de Model : appels API FFTT, parsing Excel, résolution dynamique
 * club/équipe, association manuelle équipe→club en session — trop éloigné
 * du Query Builder simple. Réutilise getPDO() directement, comme le fichier
 * legacy.
 */
class ImportRencontresNatController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../vendor/autoload.php';

        // ── Migration table ───────────────────────────────────────────────
        try {
            $pdo0 = getPDO();
            $pdo0->exec('
                CREATE TABLE IF NOT EXISTS equipe_nationale (
                    Id_EquipeNat INT              AUTO_INCREMENT PRIMARY KEY,
                    Nom          VARCHAR(200)     NOT NULL,
                    Division     VARCHAR(5)       NOT NULL,
                    Poule        TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    Rang         TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    CodeDept     VARCHAR(3)       NULL,
                    Id_Club      CHAR(8)          NULL,
                    Id_Equipe    INT              NULL,
                    UNIQUE KEY uq_nom_div (Nom(150), Division)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ');

            // Colonne club.EquipeNom (nom de base utilisé pour les équipes de ce club, ex.
            // "ROUEN SPO" pour "ROUEN SPO 2") — persistée dès qu'une équipe est associée à ce
            // club (voir autoMatchClubs()/sauvegarderAssoc()), pour que les imports des saisons
            // suivantes retrouvent le club directement au lieu de re-demander une association manuelle.
            $colsClub = array_column($pdo0->query('SHOW COLUMNS FROM club')->fetchAll(), 'Field');
            if (!in_array('EquipeNom', $colsClub, true)) {
                $pdo0->exec('ALTER TABLE club ADD COLUMN EquipeNom VARCHAR(100) NULL');
            }
            // Un nom d'équipe ne doit désigner qu'un seul club. Try/catch isolé : si des doublons
            // existent déjà en base, on laisse la contrainte non posée plutôt que de faire échouer
            // toute la migration (calendrier, dédoublonnage equipe_nationale...) qui suit.
            $hasUqEquipeNom = (bool) $pdo0->query("SHOW INDEX FROM club WHERE Key_name = 'uq_club_equipenom'")->fetch();
            if (!$hasUqEquipeNom) {
                try {
                    $pdo0->exec('ALTER TABLE club ADD UNIQUE KEY uq_club_equipenom (EquipeNom)');
                } catch (\PDOException $e) {
                }
            }

            // Table déjà existante avant l'ajout de la contrainte unique (versions antérieures
            // du code) : chaque rechargement (API ou Excel) accumulait alors un doublon par
            // équipe au lieu de faire l'upsert. On dédoublonne une fois puis on pose l'index.
            $hasUniqueKey = (bool) $pdo0->query("SHOW INDEX FROM equipe_nationale WHERE Key_name = 'uq_nom_div'")->fetch();
            if (!$hasUniqueKey) {
                $this->dedupliquerEquipeNationale($pdo0);
                $pdo0->exec('ALTER TABLE equipe_nationale ADD UNIQUE KEY uq_nom_div (Nom(150), Division)');
            }
        } catch (\PDOException $e) {
        }
    }

    /**
     * Fusionne les doublons exacts (même Nom + Division) d'equipe_nationale en une seule ligne
     * par équipe : conserve la ligne au plus petit Id_EquipeNat, lui récupère un éventuel
     * Id_Club/CodeDept déjà renseigné sur l'un des doublons, puis supprime les autres.
     */
    private function dedupliquerEquipeNationale(\PDO $pdo): void
    {
        $pdo->exec("
            UPDATE equipe_nationale en
            JOIN (
                SELECT Nom, Division,
                       MIN(Id_EquipeNat) AS keep_id,
                       MAX(Id_Club)      AS any_club,
                       MAX(CodeDept)     AS any_dept
                FROM equipe_nationale
                GROUP BY Nom, Division
                HAVING COUNT(*) > 1
            ) g ON en.Id_EquipeNat = g.keep_id
            SET en.Id_Club  = COALESCE(en.Id_Club, g.any_club),
                en.CodeDept = COALESCE(en.CodeDept, g.any_dept)
        ");

        $pdo->exec("
            DELETE en FROM equipe_nationale en
            JOIN (
                SELECT Nom, Division, MIN(Id_EquipeNat) AS keep_id
                FROM equipe_nationale
                GROUP BY Nom, Division
                HAVING COUNT(*) > 1
            ) g ON en.Nom = g.Nom AND en.Division = g.Division AND en.Id_EquipeNat <> g.keep_id
        ");
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

    /** Retourne l'ensemble des codes de division valides, sous forme [code => code] (ex: ['N1M' => 'N1M', 'N2M' => 'N2M']). */
    private function getDivIdMap(\PDO $pdo): array
    {
        $rows = $pdo->query('SELECT Division FROM division')->fetchAll();
        $map  = [];
        foreach ($rows as $r) {
            $map[$r['Division']] = $r['Division'];
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

    /**
     * Appelle xml_result_equ directement via getFfttRawClient()->request(),
     * comme ImportRencontresController::chargerDivisions() : cet endpoint
     * (poules/rencontres à partir d'un ID de division FFTT choisi librement,
     * indépendamment de tout club) n'a pas de méthode dédiée sur
     * FfttRawClient, seulement request().
     */
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

    /**
     * Retourne l'ID organisme de la ligue depuis la config ou l'API
     * (getFfttRawClient()->listOrganismes()). Autonome (ne prend plus de
     * client en paramètre).
     */
    private function getOrgId(): string
    {
        $stored = getConfig('fftt_organisme_id', '');
        if ($stored !== '') {
            return $stored;
        }
        $region = mb_strtolower(getConfig('region', 'Normandie'), 'UTF-8');
        foreach (getFfttRawClient()->listOrganismes('L') as $org) {
            if (str_contains(mb_strtolower($org['libelle'] ?? '', 'UTF-8'), $region)) {
                return (string) $org['id'];
            }
        }

        return '';
    }

    /** Dossier de dépôt des fichiers Excel/texte FFTT (déposés par FTP en production, comme Importation/Rencontres/*.xlsx pour E011). */
    private function excelNatDir(): string
    {
        return __DIR__ . '/../../../Importation/Rencontres/Nationale/';
    }

    private const MOIS_NAT = [
        'janv' => '01', 'fevr' => '02', 'mars' => '03', 'avr' => '04', 'mai' => '05',
        'juin' => '06', 'juil' => '07', 'aout' => '08', 'sept' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12',
    ];

    /** Parse une date au format Excel FFTT texte ("12-sept") en date SQL, à cheval sur deux années civiles. */
    private function parseDateNat(string $s, int $anneeDepart): ?string
    {
        $n = mb_strtolower(strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'û' => 'u']));
        if (!preg_match('/(\d{1,2})-([a-z]+)/', $n, $m)) {
            return null;
        }
        $mo = self::MOIS_NAT[$m[2]] ?? null;
        if (!$mo) {
            return null;
        }
        $annee = ((int) $mo >= 9) ? $anneeDepart : $anneeDepart + 1;

        return $annee . '-' . $mo . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }

    /**
     * Détecte un intitulé de division nationale dans une cellule ("NATIONALE 1 MESSIEURS" → "N1M").
     * Suffixe F (pas D) pour féminin/dames, pour matcher les codes de la table `division`
     * (mêmes codes que getDivisionsNationales(), ex: N1F, N2F).
     */
    private function detectDivNat(string $cell): ?string
    {
        $v = trim($cell);
        if (!preg_match('/nationale[^\d]*(\d+)[^\w]*(messieurs?|hommes?|dames?|f[eé]minin)/i', $v, $m)) {
            return null;
        }

        return 'N' . $m[1] . (preg_match('/dame|f[eé]minin/i', $m[2]) ? 'F' : 'M');
    }

    /**
     * Parse le fichier xlsx FFTT (feuille 1 = calendrier des journées, feuilles 2+ = poules par
     * division) et retourne ['saison', 'journees' => [...], 'pools' => [...], 'avertissements' => [...]].
     * Portage direct de parseNatExcel() du fichier legacy import_rencontres_nat.php.
     */
    private function parseNatExcel(\PhpOffice\PhpSpreadsheet\Spreadsheet $sp): array
    {
        $result = ['saison' => '', 'journees' => [], 'pools' => [], 'avertissements' => []];

        // ── Feuille 1 : calendrier ───────────────────────────────────────────
        $rows1       = $sp->getSheet(0)->toArray(null, false, false, false);
        $anneeDepart = 2026;
        foreach ($rows1 as $row) {
            foreach ($row as $cell) {
                if (preg_match('/(\d{4})\s*\/\s*(\d{4})/', (string) $cell, $m)) {
                    $result['saison'] = $m[1] . '/' . $m[2];
                    $anneeDepart       = (int) $m[1];
                    break 2;
                }
            }
        }
        foreach ($rows1 as $row) {
            $c0      = trim((string) ($row[0] ?? ''));
            $rawDate = $row[1] ?? null;
            $c2      = trim((string) ($row[2] ?? '')); // col C : "Ordre des Rencontres"
            if (!is_numeric($c0) || (int) (float) $c0 < 1 || $rawDate === null || $rawDate === '' || $c2 === '') {
                continue;
            }
            if (is_numeric($rawDate) && (float) $rawDate > 1000) {
                $dt   = ExcelDate::excelToDateTimeObject((float) $rawDate);
                $date = $dt->format('Y-m-d');
            } else {
                $date = $this->parseDateNat(trim((string) $rawDate), $anneeDepart);
            }
            $matchs = [];
            foreach (explode('/', $c2) as $part) {
                if (preg_match('/(\d+)-(\d+)/', trim($part), $pm)) {
                    $matchs[] = [(int) $pm[1], (int) $pm[2]];
                }
            }
            if ($date && !empty($matchs)) {
                $result['journees'][] = ['journee' => (int) (float) $c0, 'date' => $date, 'matchs' => $matchs];
            }
        }

        // ── Feuilles 2+ : poules ──────────────────────────────────────────────
        $defaultDiv = [1 => 'N1M', 2 => 'N2M', 3 => 'N3M', 4 => 'N3M'];
        for ($s = 1; $s < $sp->getSheetCount(); $s++) {
            $sheet         = $sp->getSheet($s);
            $rows          = $sheet->toArray(null, false, false, false);
            $currentDiv    = $defaultDiv[$s] ?? null;
            $poulesFeuille = 0;

            foreach ($rows as $ri => $row) {
                foreach ($row as $cell) {
                    $div = $this->detectDivNat((string) $cell);
                    if ($div) {
                        $currentDiv = $div;
                        break;
                    }
                }

                foreach ($row as $ci => $cell) {
                    $v = str_replace("\n", ' ', trim((string) $cell));
                    if (!preg_match('/^POULE\s+(\d+)$/i', $v, $pm)) {
                        continue;
                    }

                    $pouleNum = (int) $pm[1];
                    $teams    = [];
                    $autoRang = 0;
                    for ($r = $ri + 1; $r <= $ri + 12 && count($teams) < 8; $r++) {
                        if (!isset($rows[$r])) {
                            break;
                        }
                        $valA = trim((string) ($rows[$r][$ci] ?? ''));
                        $valB = trim((string) ($rows[$r][$ci + 1] ?? ''));
                        if ($valA === '' && $valB === '') {
                            continue; // ligne vide tolérée entre l'en-tête et les données
                        }
                        if (preg_match('/^POULE\b/i', $valA) || preg_match('/^POULE\b/i', $valB)) {
                            break; // en-tête de la poule suivante : fin de cette poule
                        }

                        $numA = (bool) preg_match('/^\d+$/', $valA);
                        $numB = (bool) preg_match('/^\d+$/', $valB);

                        if ($numA && $valB !== '' && !$numB) {
                            // format habituel : rang à $ci, nom à $ci+1
                            $autoRang = (int) $valA;
                            $teams[]  = ['rang' => $autoRang, 'nom' => $valB];
                            continue;
                        }

                        // Rang absent ou mal aligné (feuille reformatée à la main ou convertie
                        // depuis un PDF) : dériver le rang de la position, la numérotation FFTT
                        // au sein d'une poule étant toujours séquentielle (1..8).
                        $nom = (!$numA && $valA !== '') ? $valA : ((!$numB && $valB !== '') ? $valB : null);
                        if ($nom === null) {
                            break;
                        }
                        $autoRang++;
                        $teams[] = ['rang' => $autoRang, 'nom' => $nom];
                    }

                    if (count($teams) >= 2 && $currentDiv) {
                        $result['pools'][] = ['division' => $currentDiv, 'poule' => $pouleNum, 'equipes' => $teams];
                        $poulesFeuille++;
                    }
                }
            }

            if ($poulesFeuille === 0) {
                $nomFeuille = $sheet->getTitle();
                $result['avertissements'][] = $currentDiv
                    ? "Feuille « {$nomFeuille} » ({$currentDiv}) : aucune poule exploitable détectée — vérifiez que les colonnes rang/nom d'équipe sont alignées sur la même ligne."
                    : "Feuille « {$nomFeuille} » : aucune division ni poule détectée — vérifiez la mise en forme du fichier.";
            }
        }

        return $result;
    }

    /**
     * Parse un fichier « texte » FFTT (alternative au .xlsx, ex: recopié/nettoyé à la main depuis un
     * PDF de poules) et retourne la même structure que parseNatExcel(). Format attendu, un bloc par
     * section, séparés par une ligne vide :
     *
     *   SAISON 2026/2027
     *
     *   CALENDRIER
     *   1;19-sept;1-8/2-7/3-6/4-5
     *   2;03-oct;7-1/6-2/5-3/8-4
     *   ...
     *
     *   NATIONALE 1 MESSIEURS
     *   POULE 1
     *   1;OUISTREHAM AP 1;07760123
     *   2;PONTAULT COMBAULT UMS TT 1
     *   ...
     *
     *   POULE 2
     *   1;NICE CAVIGAL 1
     *   ...
     *
     *   NATIONALE 2 MESSIEURS
     *   POULE 1
     *   ...
     *
     * Une ligne "CALENDRIER" bascule le parseur en mode lecture du calendrier (une ligne par journée :
     * n° journée ; date "12-sept" ; ordre des rencontres "rang-rang/rang-rang…", séparateur `;` ou
     * tabulation). Une ligne reconnue par detectDivNat() ("NATIONALE 1 MESSIEURS", "NATIONALE 2 DAMES"…)
     * bascule en mode lecture des poules de cette division. Une ligne "POULE n" démarre une nouvelle
     * poule ; les lignes suivantes "rang;nom équipe[;Id_Club]" lui sont rattachées jusqu'à la
     * poule/division suivante — le 3ᵉ champ (Id_Club FFTT du club, ex: "07760123") est optionnel, à
     * ne renseigner que pour les équipes dont le club est déjà connu (typiquement les clubs de la
     * région, receveurs), pour éviter l'association manuelle à l'étape 2. Contrairement au .xlsx,
     * chaque équipe est sur sa propre ligne : aucun risque de colonnes fusionnées comme lors d'une
     * conversion PDF→texte brute.
     */
    private function parseNatTxt(string $contenu): array
    {
        $result = ['saison' => '', 'journees' => [], 'pools' => [], 'avertissements' => []];

        $anneeDepart = 2026;
        if (preg_match('/(\d{4})\s*\/\s*(\d{4})/', $contenu, $m)) {
            $result['saison'] = $m[1] . '/' . $m[2];
            $anneeDepart       = (int) $m[1];
        }

        $mode         = null; // null | 'calendrier' | 'poules'
        $currentDiv   = null;
        $currentPoule = null;
        $poolIndex    = []; // "div|poule" => index dans $result['pools']

        foreach (preg_split('/\r\n|\r|\n/', $contenu) as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '') {
                continue;
            }

            if (strcasecmp($ligne, 'CALENDRIER') === 0) {
                $mode = 'calendrier';
                continue;
            }

            $div = $this->detectDivNat($ligne);
            if ($div) {
                $currentDiv   = $div;
                $currentPoule = null;
                $mode         = 'poules';
                continue;
            }

            if ($mode === 'calendrier') {
                $parts = preg_split('/[;\t]+/', $ligne, 3);
                if (count($parts) === 3 && is_numeric(trim($parts[0]))) {
                    $matchs = [];
                    foreach (explode('/', $parts[2]) as $part) {
                        if (preg_match('/(\d+)-(\d+)/', trim($part), $pm)) {
                            $matchs[] = [(int) $pm[1], (int) $pm[2]];
                        }
                    }
                    $date = $this->parseDateNat(trim($parts[1]), $anneeDepart);
                    if ($date && $matchs) {
                        $result['journees'][] = ['journee' => (int) trim($parts[0]), 'date' => $date, 'matchs' => $matchs];
                    }
                }
                continue;
            }

            if (preg_match('/^POULE\s+(\d+)$/i', $ligne, $pm)) {
                $currentPoule = (int) $pm[1];
                if ($currentDiv) {
                    $cle = $currentDiv . '|' . $currentPoule;
                    if (!isset($poolIndex[$cle])) {
                        $poolIndex[$cle]   = count($result['pools']);
                        $result['pools'][] = ['division' => $currentDiv, 'poule' => $currentPoule, 'equipes' => []];
                    }
                }
                continue;
            }

            if ($currentDiv !== null && $currentPoule !== null && preg_match('/^(\d+)\s*[;\t]/', $ligne)) {
                $cle = $currentDiv . '|' . $currentPoule;
                if (isset($poolIndex[$cle])) {
                    $parts  = preg_split('/[;\t]+/', $ligne, 3);
                    $idClub = (isset($parts[2]) && trim($parts[2]) !== '') ? strtoupper(trim($parts[2])) : null;
                    $result['pools'][$poolIndex[$cle]]['equipes'][] = [
                        'rang'    => (int) trim($parts[0]),
                        'nom'     => trim($parts[1] ?? ''),
                        'id_club' => $idClub,
                    ];
                }
            }
        }

        // Ne garde que les poules exploitables (≥ 2 équipes), les autres deviennent des avertissements.
        $poolsValides = [];
        foreach ($result['pools'] as $p) {
            if (count($p['equipes']) >= 2) {
                $poolsValides[] = $p;
            } else {
                $result['avertissements'][] = "Poule {$p['poule']} ({$p['division']}) : équipes insuffisantes (" . count($p['equipes']) . ') — vérifiez les lignes "rang;nom".';
            }
        }
        $result['pools'] = $poolsValides;

        return $result;
    }

    /** Dispatche vers parseNatExcel() (.xlsx/.xls) ou parseNatTxt() (.txt) selon l'extension du fichier. */
    private function parseNatFichier(string $fichier): array
    {
        if (strtolower(pathinfo($fichier, PATHINFO_EXTENSION)) === 'txt') {
            return $this->parseNatTxt(file_get_contents($fichier));
        }

        return $this->parseNatExcel(IOFactory::load($fichier));
    }

    /**
     * Recherche automatiquement, pour chaque équipe sans club, un club de la table `club` dont le nom
     * d'équipe (EquipeNom, renseigné par une association précédente) ou, à défaut, le nom officiel
     * correspond exactement au nom de l'équipe une fois le numéro final retiré (ex: "IGNY AP 1" →
     * "IGNY AP"). N'associe que si la recherche renvoie un seul club (pas d'ambiguïté). Le département
     * est dérivé du numéro FFTT du club (SUBSTRING(Id_Club, 3, 2), même convention que
     * DesiderataClubsController/JugearbitreController/AuthController). Retourne le nb d'équipes associées.
     */
    private function autoMatchClubs(\PDO $pdo): int
    {
        $rows = $pdo->query('SELECT Id_EquipeNat, Nom FROM equipe_nationale WHERE Id_Club IS NULL')->fetchAll();
        if (!$rows) {
            return 0;
        }

        $stmtClub    = $pdo->prepare('SELECT Id_Club FROM club WHERE EquipeNom = ? OR Nom = ?');
        $stmtUpd     = $pdo->prepare('UPDATE equipe_nationale SET Id_Club = ?, CodeDept = ? WHERE Id_EquipeNat = ?');
        $stmtEquipe  = $pdo->prepare('UPDATE club SET EquipeNom = ? WHERE Id_Club = ?');

        $assoc = 0;
        foreach ($rows as $r) {
            $base = trim((string) preg_replace('/\s+\d+$/', '', $r['Nom']));
            if ($base === '') {
                continue;
            }
            $stmtClub->execute([$base, $base]);
            $matches = $stmtClub->fetchAll(\PDO::FETCH_COLUMN);
            if (count($matches) !== 1) {
                continue; // aucun club, ou plusieurs candidats ambigus : laissé à l'association manuelle
            }
            $idClub = $matches[0];
            $stmtUpd->execute([$idClub, substr($idClub, 2, 2), $r['Id_EquipeNat']]);
            try {
                // EquipeNom est UNIQUE : si un autre club porte déjà ce nom de base (collision
                // rare après le retrait du numéro final), on garde l'association Id_Club faite
                // ci-dessus et on ignore juste cette écriture secondaire.
                $stmtEquipe->execute([$base, $idClub]);
            } catch (\PDOException $e) {
            }
            $assoc++;
        }

        return $assoc;
    }

    /** Propage Id_Club/CodeDept entre équipes de même nom de base (ex: "CLUB 2" ← "CLUB 1"). Retourne le nb de lignes Id_Club propagées. */
    private function propagerAssociationsParNom(\PDO $pdo): int
    {
        $pdo->exec("
            UPDATE equipe_nationale en_cible
            JOIN equipe_nationale en_source
                ON en_source.Id_Club IS NOT NULL
                AND TRIM(REGEXP_REPLACE(en_source.Nom, '\\\\s+[0-9]+\$', ''))
                  = TRIM(REGEXP_REPLACE(en_cible.Nom,  '\\\\s+[0-9]+\$', ''))
            SET en_cible.Id_Club = en_source.Id_Club, en_cible.CodeDept = en_source.CodeDept
            WHERE en_cible.Id_Club IS NULL
        ");
        $autoAssoc = (int) $pdo->query('SELECT ROW_COUNT()')->fetchColumn();

        $pdo->exec("
            UPDATE equipe_nationale en_cible
            JOIN equipe_nationale en_source
                ON en_source.CodeDept IS NOT NULL
                AND TRIM(REGEXP_REPLACE(en_source.Nom, '\\\\s+[0-9]+\$', ''))
                  = TRIM(REGEXP_REPLACE(en_cible.Nom,  '\\\\s+[0-9]+\$', ''))
            SET en_cible.CodeDept = en_source.CodeDept
            WHERE en_cible.CodeDept IS NULL
        ");

        return $autoAssoc;
    }

    public function listerFichiersExcel(): ResponseInterface
    {
        return $this->tryJson(function () {
            $fichiers = [];
            foreach (['*.xlsx', '*.txt'] as $motif) {
                foreach (glob($this->excelNatDir() . $motif) ?: [] as $f) {
                    $fichiers[] = basename($f);
                }
            }
            sort($fichiers);

            return $this->response->setJSON(['ok' => true, 'fichiers' => $fichiers]);
        });
    }

    public function analyserExcel(): ResponseInterface
    {
        return $this->tryJson(function () {
            $nom     = basename($this->request->getPost('fichier') ?? '');
            $fichier = $this->excelNatDir() . $nom;
            if ($nom === '' || !is_file($fichier)) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Fichier introuvable.']);
            }

            $pdo      = getPDO();
            $data     = $this->parseNatFichier($fichier);
            $divIdMap = $this->getDivIdMap($pdo);

            // Id_Club/CodeDept ne sont écrasés que si le fichier en fournit un (COALESCE conserve toute
            // association déjà faite manuellement à l'étape 2 quand le fichier ne précise rien).
            $stmt = $pdo->prepare('
                INSERT INTO equipe_nationale (Nom, Division, Poule, Rang, Id_Club, CodeDept)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE Poule = VALUES(Poule), Rang = VALUES(Rang),
                    Id_Club = COALESCE(VALUES(Id_Club), Id_Club), CodeDept = COALESCE(VALUES(CodeDept), CodeDept)
            ');

            $erreurs = $data['avertissements'];
            foreach ($data['pools'] as $pool) {
                $idDiv = $divIdMap[$pool['division']] ?? null;
                if (!$idDiv) {
                    $erreurs["div_{$pool['division']}"] = "Division {$pool['division']} absente en BDD.";
                    continue;
                }
                foreach ($pool['equipes'] as $eq) {
                    $idClub   = $eq['id_club'] ?? null;
                    $codeDept = $idClub ? substr($idClub, 2, 2) : null;
                    $stmt->execute([mb_substr($eq['nom'], 0, 200), $idDiv, $pool['poule'], $eq['rang'], $idClub, $codeDept]);
                }
            }

            $autoAssoc = $this->autoMatchClubs($pdo) + $this->propagerAssociationsParNom($pdo);

            // Persiste le calendrier (remplace l'ancien : un seul calendrier actif à la fois) pour que
            // le cartouche "Ordre des rencontres", l'étape 3 et l'export .txt restent disponibles après
            // un rechargement de page, sans dépendre de la mémoire JS de la session en cours.
            if (!empty($data['journees'])) {
                $pdo->exec('TRUNCATE TABLE nationale_calendrier');
                $stmtCal = $pdo->prepare('INSERT INTO nationale_calendrier (Journee, Date, Ordre) VALUES (?, ?, ?)');
                foreach ($data['journees'] as $j) {
                    $stmtCal->execute([$j['journee'], $j['date'], json_encode($j['matchs'])]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'data' => $data, 'auto_assoc' => $autoAssoc, 'erreurs' => array_values($erreurs)]);
        });
    }

    /**
     * Retourne le calendrier persisté (table nationale_calendrier), au même format que
     * parseNatExcel()/parseNatTxt() : ['saison' => string, 'journees' => [...]].
     */
    private function getCalendrierPersiste(\PDO $pdo): array
    {
        $journees = [];
        foreach ($pdo->query('SELECT Journee, Date, Ordre FROM nationale_calendrier ORDER BY Journee')->fetchAll() as $r) {
            $journees[] = [
                'journee' => (int) $r['Journee'],
                'date'    => $r['Date'],
                'matchs'  => json_decode($r['Ordre'], true) ?: [],
            ];
        }

        // `nat_saison` a été retiré de `configuration` (doublon de `saison`, mêmes
        // valeurs mais séparateur différent) — même format "AAAA/AAAA" que celui
        // écrit dans les fichiers FFTT (voir SAISON ci-dessous, parseNatExcel/parseNatTxt).
        return ['saison' => str_replace('-', '/', getConfig('saison', '')), 'journees' => $journees];
    }

    /**
     * Étape 3 (partagée par les deux onglets) : reconstitue les rencontres à partir du calendrier
     * persisté (table nationale_calendrier, écrite par analyserExcel() — dates + ordre des rencontres,
     * ex: "1-8 / 2-7 / 3-6 / 4-5" par journée) appliqué à chaque poule (division/poule/rang)
     * enregistrée dans equipe_nationale — associations club à jour depuis l'étape 2. Aucun appel FFTT
     * ici, ni besoin de resélectionner le fichier Excel/texte d'origine : le calendrier ne contient pas
     * de date par rencontre individuelle, seulement un ordre générique de rang à appliquer à chaque
     * poule. Ne crée une rencontre que si l'équipe receveuse (rang "dom" du couple) appartient à un
     * club de la région.
     */
    public function importerRencontresExcel(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo  = getPDO();
            $data = $this->getCalendrierPersiste($pdo);
            if (empty($data['journees'])) {
                return $this->response->setJSON(['ok' => false, 'err' => "Aucun calendrier enregistré : analysez d'abord un fichier à l'étape 1."]);
            }

            $deptsNorm = array_map('strval', array_column(getDeptActifs(), 'code'));

            // Équipes par division/poule/rang, depuis equipe_nationale (associations club à jour de l'étape 2)
            $rows  = $pdo->query('SELECT Nom, Division, Poule, Rang, Id_Club, CodeDept FROM equipe_nationale')->fetchAll();
            $byPos = [];
            foreach ($rows as $r) {
                $byPos[$r['Division']][(int) $r['Poule']][(int) $r['Rang']] = $r;
            }

            $arbitrageMap = [];
            foreach ($pdo->query('SELECT Division, ArbitrageCRA FROM division')->fetchAll() as $r) {
                $arbitrageMap[$r['Division']] = (int) $r['ArbitrageCRA'];
            }

            $stmtEqChk = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom=? AND Division=? LIMIT 1');
            $stmtEqIns = $pdo->prepare('INSERT INTO equipe (Nom, Division, Id_Club, JAdemande) VALUES (?,?,?,0)');
            $stmtRcChk = $pdo->prepare('SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1');
            $stmtRcIns = $pdo->prepare('INSERT INTO rencontre (Date,Heure,Poule,Id_EquipeDom,Id_EquipeExt,Phase,Journee,ArbitrageObligatoire) VALUES (?,?,?,?,?,?,?,?)');

            $stats = ['equipes_creees' => 0, 'rencontres_creees' => 0, 'doublons' => 0, 'ignores' => 0, 'erreurs' => [], 'log' => []];

            // Résout (ou crée) l'équipe NIJAC correspondant à une ligne equipe_nationale, retourne son Id_Equipe.
            // equipe.Nom est VARCHAR(100) (contrairement à equipe_nationale.Nom, VARCHAR(200)) : tronqué ici.
            $resoudreEquipe = function (array $en, string $divCode) use ($stmtEqChk, $stmtEqIns, $pdo, &$stats) {
                $nom = mb_substr($en['Nom'], 0, 100);
                $stmtEqChk->execute([$nom, $divCode]);
                $id = $stmtEqChk->fetchColumn();
                if ($id) {
                    return (int) $id;
                }
                $stmtEqIns->execute([$nom, $divCode, $en['Id_Club']]);
                $id = (int) $pdo->lastInsertId();
                if ($id) {
                    $stats['equipes_creees']++;
                    $stats['log'][] = ['type' => 'equipe', 'val' => $nom];
                }

                return $id;
            };

            foreach ($byPos as $divCode => $poules) {
                $arbitrage = $arbitrageMap[$divCode] ?? 0;
                foreach ($poules as $poule => $equipesParRang) {
                    foreach ($data['journees'] as $j) {
                        foreach ($j['matchs'] as [$rangDom, $rangExt]) {
                            $dom = $equipesParRang[$rangDom] ?? null;
                            $ext = $equipesParRang[$rangExt] ?? null;
                            if (!$dom || !$ext) {
                                $stats['ignores']++;
                                continue;
                            }
                            // Seules les rencontres où le RECEVEUR est un club de la région
                            if (!$dom['Id_Club'] || !in_array((string) $dom['CodeDept'], $deptsNorm, true)) {
                                $stats['ignores']++;
                                continue;
                            }
                            if (!$ext['Id_Club']) {
                                $stats['ignores']++;
                                continue;
                            }

                            $idDom = $resoudreEquipe($dom, $divCode);
                            $idExt = $resoudreEquipe($ext, $divCode);
                            if (!$idDom || !$idExt) {
                                $stats['erreurs'][] = "Équipe non créée : {$dom['Nom']} / {$ext['Nom']}";
                                continue;
                            }

                            $stmtRcChk->execute([$j['date'], $idDom, $idExt]);
                            if ($stmtRcChk->fetchColumn()) {
                                $stats['doublons']++;
                                continue;
                            }

                            $stmtRcIns->execute([$j['date'], '00:00:00', $poule, $idDom, $idExt, 1, $j['journee'], $arbitrage]);
                            $stats['rencontres_creees']++;
                            $stats['log'][] = ['type' => 'rencontre', 'val' => "P{$poule} J{$j['journee']} — {$dom['Nom']} vs {$ext['Nom']} ({$j['date']})"];
                        }
                    }
                }
            }

            return $this->response->setJSON(['ok' => true, 'stats' => $stats]);
        });
    }

    /**
     * Retourne le calendrier persisté (table nationale_calendrier) au chargement de la page, pour que
     * le cartouche "Ordre des rencontres", l'étape 3 et l'export .txt de l'étape 2 restent disponibles
     * après un rechargement — sans ça, ils ne redevenaient visibles/actifs qu'après une nouvelle
     * analyse dans la session en cours.
     */
    public function calendrier(): ResponseInterface
    {
        return $this->tryJson(function () {
            return $this->response->setJSON(['ok' => true] + $this->getCalendrierPersiste(getPDO()));
        });
    }

    /** Inverse de MOIS_NAT : date SQL ("2026-09-19") → format texte FFTT ("19-sept"). */
    private function dateVersTxt(string $iso): string
    {
        [, $mois, $jour] = explode('-', $iso);
        $inv = array_flip(self::MOIS_NAT);

        return ((int) $jour) . '-' . ($inv[$mois] ?? $mois);
    }

    /**
     * Étape 2 : exporte l'état courant (calendrier persisté + équipes/poules/N° Club de
     * equipe_nationale) au format texte documenté dans parseNatTxt(), et l'enregistre dans
     * Importation/Rencontres/Nationale/ — pour conserver les associations club faites manuellement et
     * pouvoir les réimporter telles quelles (ex: saison suivante, ou après une purge accidentelle).
     */
    public function exporterTxt(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            $cal = $this->getCalendrierPersiste($pdo);

            $lignes = [];
            if ($cal['saison'] !== '') {
                $lignes[] = 'SAISON ' . $cal['saison'];
                $lignes[] = '';
            }
            if (!empty($cal['journees'])) {
                $lignes[] = 'CALENDRIER';
                foreach ($cal['journees'] as $j) {
                    $ordre    = implode('/', array_map(fn ($m) => $m[0] . '-' . $m[1], $j['matchs']));
                    $lignes[] = $j['journee'] . ';' . $this->dateVersTxt($j['date']) . ';' . $ordre;
                }
                $lignes[] = '';
            }

            $divLibelles = [
                'N1M' => 'NATIONALE 1 MESSIEURS', 'N2M' => 'NATIONALE 2 MESSIEURS', 'N3M' => 'NATIONALE 3 MESSIEURS',
                'N1F' => 'NATIONALE 1 DAMES', 'N2F' => 'NATIONALE 2 DAMES',
            ];
            $rows = $pdo->query('
                SELECT en.Nom, en.Division AS DivCode, en.Poule, en.Rang, en.Id_Club
                FROM equipe_nationale en
                ORDER BY en.Division, en.Poule, en.Rang
            ')->fetchAll();

            $currentDiv = null;
            $currentPoule = null;
            foreach ($rows as $r) {
                $div = $r['DivCode'] ?? '?';
                if ($div !== $currentDiv) {
                    $currentDiv   = $div;
                    $currentPoule = null;
                    $lignes[]     = $divLibelles[$div] ?? $div;
                }
                if ((int) $r['Poule'] !== $currentPoule) {
                    $currentPoule = (int) $r['Poule'];
                    $lignes[]     = 'POULE ' . $currentPoule;
                }
                $ligne = $r['Rang'] . ';' . $r['Nom'];
                if ($r['Id_Club']) {
                    $ligne .= ';' . $r['Id_Club'];
                }
                $lignes[] = $ligne;
            }

            $nomFichier = date('Ymd') . '_Poules_Nationales.txt';
            file_put_contents($this->excelNatDir() . $nomFichier, implode("\n", $lignes) . "\n");

            return $this->response->setJSON(['ok' => true, 'fichier' => $nomFichier]);
        });
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
            $api   = getFfttRawClient();
            $depts = getDeptActifs();
            $clubs = [];
            foreach ($depts as $dept) {
                $list = $api->listClubsByDepartement((int) $dept['code']);
                foreach ($list as $c) {
                    $num = trim($c['numero'] ?? '');
                    if ($num === '') {
                        continue;
                    }
                    $clubs[] = ['numclu' => $num, 'nom' => trim($c['nom'] ?? ''), 'dept' => (string) $dept['code']];
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
            $api     = getFfttRawClient();
            $reponse = $api->request('xml_equipe', ['numclu' => $numclu]);
            $items   = $reponse['equipe'] ?? [];
            $equipes = isset($items[0]) ? $items : ($items ? [$items] : []);
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
            $nomClub = mb_substr(trim($this->request->getPost('nom') ?? ''), 0, 50); // club.Nom est VARCHAR(50)
            if ($numclu === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'numclu manquant']);
            }

            $phaseFiltre = trim($this->request->getPost('phase') ?? ''); // "1", "2" ou "" = toutes

            $equipes  = getFfttRawClient()->listEquipesByClub($numclu);
            $divIdMap = $this->getDivIdMap($pdo);

            $stmtClubIns = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtNatIns  = $pdo->prepare('
                INSERT INTO equipe_nationale (Nom, Division, Poule, Rang, CodeDept, Id_Club)
                VALUES (?,?,?,0,?,?)
                ON DUPLICATE KEY UPDATE CodeDept=VALUES(CodeDept), Id_Club=VALUES(Id_Club)
            ');

            $nationales = [];
            $nonMatch   = [];

            foreach ($equipes as $eq) {
                $lib = trim($eq['libequipe'] ?? '');
                if ($lib === '') {
                    continue;
                }
                $ndiv = trim($eq['libdivision'] ?? '');

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
            $api   = getFfttRawClient();
            $orgId = $this->getOrgId();
            if ($orgId === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Organisme ligue introuvable.']);
            }

            $divMap   = $this->getDivisionsNationales($api, $orgId);
            $divIdMap = $this->getDivIdMap($pdo);

            $stmt = $pdo->prepare('
                INSERT INTO equipe_nationale (Nom, Division, Poule, Rang)
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

            $stats['associations'] = $this->autoMatchClubs($pdo) + $this->propagerAssociationsParNom($pdo);

            return $this->response->setJSON(['ok' => true, 'stats' => $stats, 'org_id' => $orgId]);
        });
    }

    public function listeEquipes(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query('
                SELECT en.Id_EquipeNat, en.Nom, en.Division AS id_division, en.Poule, en.Rang,
                       en.CodeDept, en.Id_Club,
                       (SELECT c.Nom FROM club c WHERE c.Id_Club = en.Id_Club LIMIT 1) AS NomClub
                FROM equipe_nationale en
                ORDER BY en.Division, en.Poule, en.Nom
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

    public function clubParNumero(): ResponseInterface
    {
        return $this->tryJson(function () {
            $numero = trim($this->request->getGet('numero') ?? '');
            if ($numero === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Numéro manquant.']);
            }
            $stmt = getPDO()->prepare('SELECT Id_Club, Nom FROM club WHERE Id_Club = ?');
            $stmt->execute([$numero]);
            $club = $stmt->fetch();
            if (!$club) {
                return $this->response->setJSON(['ok' => false, 'err' => "Club $numero introuvable."]);
            }

            return $this->response->setJSON(['ok' => true, 'club' => $club]);
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
            $pdo = getPDO();
            $pdo->prepare('UPDATE equipe_nationale SET CodeDept=?, Id_Club=? WHERE Id_EquipeNat=?')
                ->execute([$codeDept, $idClub, $idEn]);

            // Persiste le nom de base de l'équipe sur le club associé, pour que l'import de la
            // saison suivante retrouve ce club automatiquement (voir autoMatchClubs()). EquipeNom
            // est UNIQUE : si un autre club porte déjà ce nom de base, l'association Id_Club
            // ci-dessus reste valide, on prévient juste que le nom n'a pas pu être mémorisé.
            $warn = null;
            if ($idClub !== null) {
                $stmtNom = $pdo->prepare('SELECT Nom FROM equipe_nationale WHERE Id_EquipeNat = ?');
                $stmtNom->execute([$idEn]);
                $nom = $stmtNom->fetchColumn();
                if ($nom !== false) {
                    $base = trim((string) preg_replace('/\s+\d+$/', '', $nom));
                    if ($base !== '') {
                        try {
                            $pdo->prepare('UPDATE club SET EquipeNom = ? WHERE Id_Club = ?')->execute([$base, $idClub]);
                        } catch (\PDOException $e) {
                            $warn = "Nom d'équipe « $base » déjà utilisé par un autre club — non mémorisé pour les imports futurs.";
                        }
                    }
                }
            }

            return $this->response->setJSON(['ok' => true, 'warn' => $warn]);
        });
    }

}
