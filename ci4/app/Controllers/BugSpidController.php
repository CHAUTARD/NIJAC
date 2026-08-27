<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – BugSpid (E043) : file de corrections « club dupliqué avec un
 * Id_Club fantôme » (code alphabétique généré par l'import quand le nom du
 * club de la rencontre ne matchait aucun Club.EquipeNom existant — voir
 * ImportRencontresController::synchroniserClubFftt() et l'outil de
 * rapprochement tools/rapprocher_clubs_alpha.php).
 *
 * Chaque ligne décrit une fusion à faire (ancien Id_Club → nouveau) ; la
 * requête n'est jamais stockée telle quelle, elle est régénérée à
 * l'exécution à partir des champs de la ligne — trois étapes systématiques :
 *   1. UPDATE equipe SET Id_Club = nouveau WHERE Id_Club = ancien
 *   2. DELETE FROM Club WHERE Id_Club = ancien
 *   3. UPDATE Club SET EquipeNom = ... WHERE Id_Club = nouveau (si renseigné)
 *
 * Même restriction que E099 (outil de correction de données) : filtre
 * "adminauth" + login === 'CHAUTARD' vérifié manuellement, même règle que
 * E018/E099.
 */
class BugSpidController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function guardChautard(): ?ResponseInterface
    {
        if (($_SESSION['utilisateur']['login'] ?? '') !== 'CHAUTARD') {
            return redirect()->to(site_url('admin-menu'));
        }

        return null;
    }

    private function assurerTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS BugSpid (
                Id_BugSpid INT AUTO_INCREMENT PRIMARY KEY,
                Description VARCHAR(255) NOT NULL,
                AncienIdClub VARCHAR(20) NOT NULL,
                NouveauIdClub VARCHAR(20) NOT NULL,
                EquipeNom VARCHAR(100) NULL,
                Statut ENUM(\'A traiter\',\'Traite\') NOT NULL DEFAULT \'A traiter\',
                DateAjout DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                DateExecution DATETIME NULL,
                Resultat TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
              COMMENT=\'File de corrections Id_Club dupliqué (alpha -> code FFTT réel), exécutable en lot depuis E043\''
        );
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] bug_spid PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] bug_spid : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $this->assurerTable(getPDO());

        $u = $_SESSION['utilisateur'] ?? [];

        return view('bug_spid_index', [
            'nomComplet'  => trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? '')),
            'changeLogin' => !empty($u['change_login']),
        ]);
    }

    public function data(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo = getPDO();
            $this->assurerTable($pdo);

            $rows = $pdo->query('SELECT * FROM BugSpid ORDER BY Id_BugSpid DESC')->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    public function store(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $pdo   = getPDO();
            $this->assurerTable($pdo);
            $input = $this->request->getPost();

            [$description, $ancien, $nouveau, $equipeNom, $err] = $this->lireEtValider($input);
            if ($err) {
                return $this->response->setJSON(['ok' => false, 'msg' => $err]);
            }

            $pdo->prepare(
                'INSERT INTO BugSpid (Description, AncienIdClub, NouveauIdClub, EquipeNom) VALUES (?, ?, ?, ?)'
            )->execute([$description, $ancien, $nouveau, $equipeNom]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne ajoutée.', 'id' => (int) $pdo->lastInsertId()]);
        });
    }

    public function update(int $id): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () use ($id) {
            $pdo   = getPDO();
            $this->assurerTable($pdo);
            $input = $this->request->getRawInput();

            [$description, $ancien, $nouveau, $equipeNom, $err] = $this->lireEtValider($input);
            if ($err) {
                return $this->response->setJSON(['ok' => false, 'msg' => $err]);
            }

            $stmt = $pdo->prepare(
                'UPDATE BugSpid SET Description=?, AncienIdClub=?, NouveauIdClub=?, EquipeNom=? WHERE Id_BugSpid=?'
            );
            $stmt->execute([$description, $ancien, $nouveau, $equipeNom, $id]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM BugSpid WHERE Id_BugSpid = ?');
                $chk->execute([$id]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Ligne $id introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne mise à jour.']);
        });
    }

    /**
     * Appelle l'endpoint FFTT xml_club_b avec l'AncienIdClub de la ligne, pour
     * voir ce que l'API renvoie sur ce code (souvent rien d'exploitable
     * puisque ce n'est pas un vrai numéro FFTT, mais utile pour vérifier au
     * cas où) — diagnostic seul, n'écrit rien.
     */
    public function testXmlClubB(int $id): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () use ($id) {
            $pdo   = getPDO();
            $this->assurerTable($pdo);
            $stmt  = $pdo->prepare('SELECT Description FROM BugSpid WHERE Id_BugSpid = ?');
            $stmt->execute([$id]);
            $desc  = $stmt->fetchColumn();
            if ($desc === false) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Ligne $id introuvable."]);
            }
            // Description est de la forme "Club à identifier : NOM DU CLUB" pour les
            // lignes générées automatiquement — on cherche sur le nom seul, sans ce préfixe.
            $nom = preg_replace('/^Club à identifier\s*:\s*/u', '', $desc);
            // Un nom d'entente ("PACYMENILLES / EVREUX ENT") ne matchera jamais un
            // seul vrai club — ne garder que la première partie avant le "/".
            if (str_contains($nom, '/')) {
                $nom = trim(explode('/', $nom)[0]);
            }

            // 1. Recherche locale d'abord (Nom ou EquipeNom déjà en base) — évite
            // un appel FFTT si le vrai club est déjà connu de la table Club.
            $like      = '%' . $nom . '%';
            $stmtLocal = $pdo->prepare("SELECT Id_Club, Nom, EquipeNom FROM Club WHERE (Nom LIKE ? OR EquipeNom LIKE ?) AND Id_Club LIKE '0%'");
            $stmtLocal->execute([$like, $like]);
            $locaux = $stmtLocal->fetchAll();

            if ($locaux) {
                $clubs = array_map(
                    static fn ($c) => ['numero' => $c['Id_Club'], 'nom' => $c['Nom'] . ($c['EquipeNom'] ? " (EquipeNom : {$c['EquipeNom']})" : '')],
                    $locaux
                );

                return $this->response->setJSON(['ok' => true, 'recherche' => $nom, 'source' => 'local', 'clubs' => $clubs]);
            }

            // 2. Sinon, recherche FFTT. xml_club_b accepte un seul paramètre de
            // recherche : dep, ville (sert aussi de recherche par nom de club),
            // numero ou code — jamais "club" (voir
            // Documentation/Specifications_techniques_de_API_Smartping_2.0.pdf).
            $api  = getFfttRawClient();
            $data = $api->request('xml_club_b', ['ville' => $nom]);

            // xmlToArray() ne met club[] en liste que s'il y a plusieurs résultats —
            // un seul résultat reste un tableau associatif simple, à réenvelopper.
            $clubs = $data['club'] ?? [];
            if ($clubs !== [] && !isset($clubs[0])) {
                $clubs = [$clubs];
            }

            return $this->response->setJSON(['ok' => true, 'recherche' => $nom, 'source' => 'fftt', 'clubs' => $clubs, 'url' => $api->lastUrl()]);
        });
    }

    /**
     * « Créer le CSV depuis un PDF » : reçoit un PDF « Calendriers Chpt R1 à
     * R4 » de la FFTT, en extrait la liste « N° Club officiel ; nom du club »
     * (bloc coordonnées de chaque poule) et renvoie le CSV prêt à recharger
     * via « Mise à jour CSV ». Extraction 100 % PHP (pas de dépendance
     * Composer, cf. choix FfttRawClient) — voir extrairePdf().
     */
    public function pdfCsv(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $file = $this->request->getFile('pdf');
            if (!$file || !$file->isValid()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier PDF manquant ou invalide.']);
            }

            $texte = $this->extrairePdf((string) file_get_contents($file->getRealPath()));
            if (trim($texte) === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => "Impossible d'extraire le texte de ce PDF (format non reconnu)."]);
            }

            // Après extraction, chaque club d'un bloc « coordonnées de poule »
            // occupe 3 lignes consécutives : "<8 chiffres>", "<nom> <n° équipe>",
            // puis "S" ou "D" (type de salle). On déduplique sur n° + nom.
            $L     = array_values(array_filter(array_map('trim', explode("\n", $texte)), static fn ($x) => $x !== ''));
            $clubs = [];
            for ($i = 0, $n = count($L); $i + 2 < $n; $i++) {
                if (preg_match('/^\d{8}$/', $L[$i])
                    && preg_match('/^[SD]$/', $L[$i + 2])
                    && preg_match('/^(.*?)\s+\d+$/u', $L[$i + 1], $m)) {
                    $nom = preg_replace('/\s+/', ' ', trim($m[1]));
                    $clubs[$L[$i] . '|' . $nom] = [$L[$i], $nom];
                }
            }
            if (!$clubs) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune ligne club reconnue dans ce PDF.']);
            }
            ksort($clubs);

            $csv = "\xEF\xBB\xBFN° Club;Club\r\n";
            foreach ($clubs as $c) {
                $csv .= $c[0] . ';' . str_replace(['"', ';', "\r", "\n"], ['', ',', ' ', ' '], $c[1]) . "\r\n";
            }

            $nom = preg_replace('/[^\w .()\-]+/u', '_', preg_replace('/\.pdf$/i', '', $file->getClientName()));

            return $this->response->setJSON([
                'ok'  => true,
                'csv' => $csv,
                'nom' => ($nom !== '' ? $nom : 'clubs') . '.csv',
                'nb'  => count($clubs),
            ]);
        });
    }

    /**
     * Extraction texte d'un PDF, en PHP pur : décompresse chaque flux
     * (FlateDecode) et concatène les chaînes littérales des opérateurs Tj/TJ,
     * en insérant un saut de ligne sur les opérateurs de positionnement
     * (Td, TD, T-étoile, Tm). Suffisant pour les exports FFTT « Calendriers ».
     * ponytail: gère seulement les flux Flate (ou déjà en clair) et un
     * découpage en lignes calé sur ces PDF — pas un extracteur PDF générique.
     */
    private function extrairePdf(string $raw): string
    {
        if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
            return '';
        }

        $out = '';
        foreach ($m[1] as $s) {
            $d = @gzuncompress($s);
            if ($d === false) {
                $d = @gzinflate($s);
            }
            if ($d === false) {
                $d = (strpos($s, 'BT') !== false && strpos($s, 'Tj') !== false) ? $s : false;
            }
            if ($d === false || (strpos($d, 'Tj') === false && strpos($d, 'TJ') === false)) {
                continue;
            }

            $n   = strlen($d);
            $buf = '';
            for ($i = 0; $i < $n; $i++) {
                $ch = $d[$i];
                if ($ch === '(') {
                    $depth = 1;
                    for ($i++; $i < $n && $depth > 0; $i++) {
                        $c = $d[$i];
                        if ($c === '\\') {
                            $nx = $d[$i + 1] ?? '';
                            if (ctype_digit($nx)) {
                                $oct = $nx;
                                for ($k = 0; $k < 2 && ctype_digit($d[$i + 2 + $k] ?? ''); $k++) {
                                    $oct .= $d[$i + 2 + $k];
                                }
                                $buf .= chr(octdec($oct) & 0xFF);
                                $i   += strlen($oct);
                            } else {
                                $buf .= ['n' => "\n", 'r' => '', 't' => ' ', 'b' => '', 'f' => ''][$nx] ?? $nx;
                                $i++;
                            }
                        } elseif ($c === '(') {
                            $depth++;
                            $buf .= $c;
                        } elseif ($c === ')') {
                            if (--$depth > 0) {
                                $buf .= $c;
                            }
                        } else {
                            $buf .= $c;
                        }
                    }
                    $i--;
                } elseif ($ch === 'T' && in_array($d[$i + 1] ?? '', ['d', 'D', '*', 'm'], true)) {
                    $buf .= "\n";
                    $i++;
                }
            }
            $out .= $buf . "\n";
        }

        return $out;
    }

    /**
     * « Mise à jour CSV » : importe un fichier CSV « N° Club ; Nom du club »
     * (typiquement Documentation/clubs.csv, construit depuis les calendriers
     * FFTT) et renseigne NouveauIdClub sur chaque ligne « À traiter » encore
     * non identifiée, en rapprochant cle8(nom du CSV) de l'AncienIdClub — qui
     * EST déjà cle8() du nom d'équipe importé (même transformation que
     * tools/rapprocher_clubs_alpha.php). N'exécute aucune fusion : se contente
     * de préremplir la colonne, l'admin relit puis lance « Exécuter ».
     */
    public function majCsv(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $file = $this->request->getFile('csv');
            if (!$file || !$file->isValid()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier CSV manquant ou invalide.']);
            }

            $lignes = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            if ($lignes === []) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'CSV vide.']);
            }
            $lignes[0] = ltrim($lignes[0], "\xEF\xBB\xBF"); // BOM éventuel (Excel FR)

            // N° FFTT indexé par clé de nom (cf. cleNom()) ; en-tête et lignes
            // malformées ignorées (1re colonne = code FFTT à 8 chiffres attendu).
            $numParCle = [];
            $nbCsv     = 0;
            foreach ($lignes as $l) {
                $cols = str_getcsv($l, str_contains($l, ';') ? ';' : ',');
                $num  = trim($cols[0] ?? '');
                $nom  = trim($cols[1] ?? '');
                if (!preg_match('/^\d{8}$/', $num)) {
                    continue;
                }
                $nbCsv++;
                $numParCle[$this->cleNom($nom)] = $num; // dernière occurrence gagne
            }

            $pdo = getPDO();
            $this->assurerTable($pdo);

            // Toutes les lignes, quel que soit le statut ou un NouveauIdClub déjà
            // saisi : le CSV fait foi. Aucune fusion n'est lancée ici, l'admin
            // relit la colonne avant « Exécuter la sélection ».
            $rows = $pdo->query('SELECT Id_BugSpid, AncienIdClub FROM BugSpid')->fetchAll();
            $maj  = $pdo->prepare('UPDATE BugSpid SET NouveauIdClub = ? WHERE Id_BugSpid = ?');

            $nbLignes = $restantes = 0;
            foreach ($rows as $r) {
                $num = $numParCle[$this->cleNom($r['AncienIdClub'])] ?? null;
                if ($num === null) {
                    $restantes++;
                    continue;
                }
                $maj->execute([$num, $r['Id_BugSpid']]);
                $nbLignes++;
            }

            return $this->response->setJSON([
                'ok'        => true,
                'msg'       => "$nbCsv club(s) lus dans le CSV — $nbLignes ligne(s) BugSpid renseignée(s), $restantes sans correspondance dans le CSV.",
                'restantes' => $restantes,
            ]);
        });
    }

    /**
     * Clé de rapprochement : la même cle8() que le code de repli de l'import
     * (strtoupper + alphanum + 8 premiers car., cf. tools/rapprocher_clubs_alpha.php),
     * débarrassée d'un éventuel n° d'équipe en fin — l'AncienIdClub est dérivé
     * du nom d'équipe FFTT ("ACATT 1" -> "ACATT1"), le CSV du nom de club nu.
     * ponytail: rapprochement sur préfixe 8 car. — collision possible entre 2
     * clubs de même début de nom ; sans effet de bord (aucune fusion ici, l'admin
     * relit la colonne avant « Exécuter »).
     */
    private function cleNom(string $nom): string
    {
        return rtrim(strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nom), 0, 8)), '0123456789');
    }

    /**
     * Nom du club pour un code FFTT à 8 chiffres (clic sur une cellule
     * « Nouveau Id_Club » dans la vue) : table Club locale d'abord, repli sur
     * xml_club_b sinon. Diagnostic seul, n'écrit rien.
     */
    public function nomClub(string $num): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () use ($num) {
            if (!preg_match('/^\d{8}$/', $num)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Code FFTT à 8 chiffres attendu.']);
            }

            $stmt = getPDO()->prepare('SELECT Nom, EquipeNom FROM Club WHERE Id_Club = ?');
            $stmt->execute([$num]);
            if ($c = $stmt->fetch()) {
                return $this->response->setJSON(['ok' => true, 'nom' => $c['Nom'] ?: $c['EquipeNom'], 'source' => 'local']);
            }

            $api  = getFfttRawClient();
            $data = $api->request('xml_club_b', ['numero' => $num]);
            $club = $data['club'] ?? [];
            if ($club !== [] && !isset($club[0])) {
                $club = [$club];
            }
            $nom = $club[0]['nom'] ?? null;

            return $this->response->setJSON(['ok' => (bool) $nom, 'nom' => $nom, 'source' => 'fftt', 'msg' => $nom ? null : 'Club introuvable.']);
        });
    }

    public function delete(int $id): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () use ($id) {
            getPDO()->prepare('DELETE FROM BugSpid WHERE Id_BugSpid = ?')->execute([$id]);

            return $this->response->setJSON(['ok' => true, 'msg' => 'Ligne supprimée.']);
        });
    }

    /**
     * Exécute la fusion (UPDATE equipe / DELETE Club / UPDATE Club EquipeNom)
     * pour chaque ligne cochée. Chaque ligne est traitée dans sa propre
     * transaction : une erreur sur l'une n'empêche pas le traitement des
     * suivantes, contrairement au requêteur libre de E099.
     */
    public function executer(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->tryJson(function () {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', json_decode($this->request->getPost('ids') ?? '[]', true) ?: [])
            )));
            if (!$ids) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune ligne sélectionnée.']);
            }

            $pdo    = getPDO();
            $this->assurerTable($pdo);
            $ph     = implode(',', array_fill(0, count($ids), '?'));
            $lignes = $pdo->prepare("SELECT * FROM BugSpid WHERE Id_BugSpid IN ($ph)");
            $lignes->execute($ids);

            $resultats = [];
            foreach ($lignes->fetchAll() as $ligne) {
                $resultats[] = $this->executerLigne($pdo, $ligne);
            }

            return $this->response->setJSON(['ok' => true, 'resultats' => $resultats]);
        });
    }

    private function executerLigne(\PDO $pdo, array $ligne): array
    {
        $id      = (int) $ligne['Id_BugSpid'];
        $ancien  = $ligne['AncienIdClub'];
        $nouveau = $ligne['NouveauIdClub'];

        // Garde-fou : une ligne pas encore identifiée (NouveauIdClub vide ou
        // laissé égal à AncienIdClub comme simple aide-mémoire) ne doit jamais
        // être exécutée — sinon le DELETE FROM Club qui suit supprimerait le
        // club réellement référencé par les équipes (via fk_equipe_club
        // ON DELETE CASCADE, ça supprimerait même les équipes).
        if ($nouveau === '' || $nouveau === $ancien || !preg_match('/^\d{8}$/', $nouveau)) {
            $msg = 'NouveauIdClub manquant ou invalide (code FFTT à 8 chiffres attendu, différent de AncienIdClub).';
            $pdo->prepare('UPDATE BugSpid SET Resultat=? WHERE Id_BugSpid=?')->execute([$msg, $id]);

            return ['id' => $id, 'ok' => false, 'msg' => $msg];
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE equipe SET Id_Club = ? WHERE Id_Club = ?');
            $stmt->execute([$nouveau, $ancien]);
            $nbEquipes = $stmt->rowCount();

            $pdo->prepare('DELETE FROM Club WHERE Id_Club = ?')->execute([$ancien]);

            if (!empty($ligne['EquipeNom'])) {
                $pdo->prepare('UPDATE Club SET EquipeNom = ? WHERE Id_Club = ?')->execute([$ligne['EquipeNom'], $nouveau]);
            }

            $pdo->commit();

            $resultat = "$nbEquipes équipe(s) repointée(s) de $ancien vers $nouveau.";
            $pdo->prepare('UPDATE BugSpid SET Statut=\'Traite\', DateExecution=NOW(), Resultat=? WHERE Id_BugSpid=?')
                ->execute([$resultat, $id]);

            return ['id' => $id, 'ok' => true, 'msg' => $resultat];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = 'Erreur : ' . $e->getMessage();
            $pdo->prepare('UPDATE BugSpid SET Resultat=? WHERE Id_BugSpid=?')->execute([$msg, $id]);

            return ['id' => $id, 'ok' => false, 'msg' => $msg];
        }
    }

    /** @return array{0:string,1:string,2:string,3:?string,4:?string} */
    private function lireEtValider(array $input): array
    {
        $description = trim($input['description'] ?? '');
        $ancien      = trim($input['ancien_id_club'] ?? '');
        $nouveau     = trim($input['nouveau_id_club'] ?? '');
        $equipeNom   = trim($input['equipe_nom'] ?? '') ?: null;

        if ($description === '' || $ancien === '' || $nouveau === '') {
            return [$description, $ancien, $nouveau, $equipeNom, 'Description, ancien et nouveau Id_Club sont obligatoires.'];
        }

        return [$description, $ancien, $nouveau, $equipeNom, null];
    }
}
