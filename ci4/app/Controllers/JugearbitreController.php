<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des Juges-Arbitres (EN11), portage CI4 de Nominateur/jugearbitre.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * un Nominateur consulte/modifie la grille et importe des comptes EBP ; import
 * Excel FFTT et import/scan API FFTT par département sont restreints à
 * l'admin — vérifié individuellement dans
 * chaque méthode, comme le fait jugearbitre.php
 * (`in_array($action, $actionsAdmin) && !$isAdmin`).
 *
 * Pas de Model : upsert en masse, appels API FFTT avec retry — trop éloignés
 * du Query Builder simple. Réutilise getPDO() directement, comme le fichier legacy.
 */
class JugearbitreController extends BaseController
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

    /** Codes département FFTT (xml_club_dep2) pour la Corse, distincts des codes INSEE 2A/2B utilisés partout ailleurs dans l'appli. */
    private const DEPT_FFTT_CORSE = ['2A' => '98', '2B' => '99'];

    /** Rang du grade : J3=3, J2=2, JA1=1 — plus c'est haut, plus c'est prioritaire */
    private function gradeRank(string $grade): int
    {
        if (preg_match('/3/', $grade)) {
            return 3;
        }
        if (preg_match('/2/', $grade)) {
            return 2;
        }

        return 1;
    }

    /** Déduplique un tableau de JA (clé Nom+Prénom) en gardant le grade le plus haut */
    private function deduplicateJA(array $rows, string $nomKey, string $prenomKey, string $gradeKey): array
    {
        $byPerson = [];
        foreach ($rows as $r) {
            $key = mb_strtoupper($r[$nomKey] . '|' . $r[$prenomKey]);
            if (!isset($byPerson[$key]) || $this->gradeRank((string) $r[$gradeKey]) > $this->gradeRank((string) $byPerson[$key][$gradeKey])) {
                $byPerson[$key] = $r;
            }
        }

        return array_values($byPerson);
    }

    private function formaterTelephone(?string $tel): ?string
    {
        if ($tel === null || $tel === '') {
            return null;
        }
        $t = preg_replace('/\D/', '', $tel);
        if (strlen($t) === 10) {
            return implode('.', str_split($t, 2));
        }

        return $tel;
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'      => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement'     => $moi['id_departement'] ?? '',
            'changeLogin'     => !empty($moi['change_login']),
            'isAdmin'         => $this->isAdmin(),
            'deptUser'        => $moi['id_departement'] ?? '',
            'deptActifs'      => getDeptActifs(),
            'deptLimitrophes' => getDepartementsLimitrophes(),
        ];

        // Pour chaque département actif : ses voisins de la région (codes) — sert
        // à ne proposer, dans la modale JA, que les départements voisins du
        // « Exerce dans » saisi pour la préférence « arbitre départements voisins ».
        $data['voisinsParDept'] = [];
        foreach ($data['deptActifs'] as $d) {
            $code = (string) $d['CodeDept'];
            $data['voisinsParDept'][$code] = array_column(getLimitrophesRegion($code), 'CodeDept');
        }

        return view('jugearbitre_index', $data);
    }

    public function liste(): ResponseInterface
    {
        $pdo = getPDO();

        $dept = $this->request->getGet('dept');
        $dept = ($dept !== null && $dept !== '') ? $dept : null;

        $deptPad   = $dept !== null ? str_pad((string) $dept, 2, '0', STR_PAD_LEFT) : null;
        $whereDept = $dept !== null ? 'WHERE j.CodeDept = ?' : '';

        $stmt = $pdo->prepare(
            'SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.Telephone,
                    j.Grade, j.Actif, j.Id_Club, j.Id_LaPoste,
                    j.Defiscalisation, j.Nationale, j.NumCompteEBP,
                    j.DateValidationFFTT,
                    j.ArbitreAutresDepts, j.DeptsArbitrage,
                    j.Cp, j.Ville, j.CodeDept,
                    cl.Nom AS NomClub,
                    COALESCE(j.Cp,    lp.CodePostal) AS CodePostalJA,
                    COALESCE(j.Ville, lp.Nom)        AS VilleJA,
                    (SELECT COUNT(*) FROM disponible d WHERE d.Id_JA = j.Id_JA) AS NbDispo
             FROM ja j
             LEFT JOIN Club cl    ON cl.Id_Club    = j.Id_Club
             LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
             ' . $whereDept . '
             ORDER BY j.Nom, j.Prenom'
        );
        $stmt->execute($dept !== null ? [$deptPad] : []);
        $rows = $stmt->fetchAll();
        $rows = $this->deduplicateJA($rows, 'Nom', 'Prenom', 'Grade');

        $rows = array_map(static fn ($r) => [
            'Id_JA'                  => $r['Id_JA'],
            'Nom'                    => $r['Nom'],
            'Prenom'                 => $r['Prenom'],
            'Email'                  => $r['Email'],
            'Telephone'              => $r['Telephone'],
            'Grade'                  => $r['Grade'],
            'Actif'                  => $r['Actif'],
            'Id_Club'                => $r['Id_Club'],
            'Id_LaPoste'             => $r['Id_LaPoste'],
            'CodeDept'               => $r['CodeDept'],
            'NumCompteEBP'           => $r['NumCompteEBP'],
            'Defiscalisation'        => $r['Defiscalisation'],
            'Nationale'              => $r['Nationale'],
            'ArbitreAutresDepts'     => $r['ArbitreAutresDepts'],
            'DeptsArbitrage'         => $r['DeptsArbitrage'],
            'NbDispo'                => $r['NbDispo'],
            'NomClub'                => $r['NomClub'],
            'CP'                     => $r['CodePostalJA'],
            'Ville'                  => $r['VilleJA'],
            'DateValidationFFTT'     => $r['DateValidationFFTT'],
        ], $rows);

        return $this->response->setJSON(['ok' => true, 'data' => $rows]);
    }

    public function clubsParDept(): ResponseInterface
    {
        $pdo  = getPDO();

        $dept = trim((string) ($this->request->getGet('dept') ?? ''));
        if ($dept === '') {
            $stmt = $pdo->query('SELECT Id_Club, Nom FROM Club ORDER BY Nom');
        } else {
            $deptPad = str_pad($dept, 2, '0', STR_PAD_LEFT);
            $stmt    = $pdo->prepare(
                'SELECT cl.Id_Club, cl.Nom
                 FROM Club cl
                 JOIN Salle s  ON s.Id_Club   = cl.Id_Club AND s.EstPrincipale = 1
                 JOIN laposte lp ON lp.Id_LaPoste = s.Id_Laposte
                 WHERE LEFT(lp.CodePostal, 2) = ?
                 ORDER BY cl.Nom'
            );
            $stmt->execute([$deptPad]);
        }

        return $this->response->setJSON(['ok' => true, 'clubs' => $stmt->fetchAll()]);
    }

    /**
     * Résout le département (2 chiffres) d'un JA — $deptManuel (combo de la
     * modale Créer/Modifier JA) est prioritaire s'il est fourni, sinon même
     * priorité que whereDept dans liste() : Id_Club (positions 3-4), sinon Cp,
     * sinon code postal de Id_LaPoste.
     */
    private function resolveCodeDept(\PDO $pdo, ?string $idClub, string $cp, ?int $idLap, ?string $deptManuel = null): ?string
    {
        if ($deptManuel !== null && $deptManuel !== '') {
            return $deptManuel;
        }
        if ($idClub !== null && $idClub !== '') {
            return substr($idClub, 2, 2);
        }
        if ($cp !== '') {
            return substr($cp, 0, 2);
        }
        if ($idLap !== null) {
            $stmt = $pdo->prepare('SELECT CodePostal FROM laposte WHERE Id_LaPoste = ? LIMIT 1');
            $stmt->execute([$idLap]);
            $cpLap = (string) $stmt->fetchColumn();
            if ($cpLap !== '') {
                return substr($cpLap, 0, 2);
            }
        }

        return null;
    }

    public function majBdd(): ResponseInterface
    {

        $pdo = getPDO();

        $lignes = json_decode($this->request->getPost('lignes') ?? '[]', true);
        if (!is_array($lignes)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Données invalides.']);
        }

        $inserts       = 0;
        $updates       = 0;
        $erreurs       = [];
        $avertissements = [];

        // Id_Club a une contrainte FK vers Club : un code présent dans l'Excel FFTT
        // mais pas encore synchronisé dans la table Club (nouveau club, renommage,
        // fusion...) ferait échouer l'INSERT/UPDATE en bloc. On le détecte en amont
        // et on enregistre quand même le JA sans club (à corriger manuellement),
        // plutôt que de perdre toute la ligne pour une seule référence orpheline.
        $clubsValides = array_flip(array_column($pdo->query('SELECT Id_Club FROM Club')->fetchAll(), 'Id_Club'));

        // Actif est accepté directement ici (checkbox de la modale Créer/Modifier
        // JA, ou colonne "Inactivité" du CSV FFTT) — l'import API par département
        // (importFfttClub()/importFfttSelected()) modifie aussi Actif, mais jamais
        // via majBdd() : il se contente de tout remettre à 0 pour le département
        // (reinitialiserActifDept()) sans jamais réactiver personne, quoi que le
        // scan FFTT retrouve. En UPDATE, DateValidationFFTT / Defiscalisation /
        // Nationale / NumCompteEBP ne sont réécrits que si la ligne fournit
        // explicitement la clé (cf. SET construit ligne par ligne plus bas) :
        // seul l'import CSV FFTT porte date_validation_fftt, seule la modale
        // Créer/Modifier JA porte les trois autres — les imports ne doivent pas
        // écraser une valeur saisie ou synchronisée ailleurs.
        $stmtCheck  = $pdo->prepare('SELECT COUNT(*) FROM ja WHERE Id_JA = ?');
        $stmtInsert = $pdo->prepare(
            'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Telephone, Grade, Actif,
                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP,
                             Cp, Ville, DateValidationFFTT, CodeDept)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtInsertAuto = $pdo->prepare(
            'INSERT INTO ja (Nom, Prenom, Email, Telephone, Grade, Actif,
                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP,
                             Cp, Ville, DateValidationFFTT, CodeDept)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($lignes as $l) {
            $id        = (int) ($l['id'] ?? 0);
            $nom       = trim($l['nom'] ?? '');
            $prenom    = trim($l['prenom'] ?? '');
            $email     = ($l['email'] ?? '') !== '' ? $l['email'] : null;
            $tel       = $this->formaterTelephone(($l['telephone'] ?? '') !== '' ? $l['telephone'] : null);
            $grade     = trim($l['grade'] ?? '');
            $actif     = !empty($l['actif']) ? 1 : 0;
            $defisc    = !empty($l['defiscalisation']) ? 1 : 0;
            $nationale = !empty($l['nationale']) ? 1 : 0;
            $idClub    = ($l['id_club'] ?? '') !== '' ? trim($l['id_club']) : null;
            $idLap     = ($l['id_laposte'] ?? '') !== '' ? (int) $l['id_laposte'] : null;
            $deptManuel = ($l['dept'] ?? '') !== '' ? trim((string) $l['dept']) : null;
            $cpteEbp   = ($l['num_compte_ebp'] ?? '') !== '' ? trim($l['num_compte_ebp']) : null;
            // Cp/Ville sont NOT NULL en base : chaîne vide plutôt que null si inconnu
            $cp    = trim((string) ($l['cp'] ?? ''));
            $ville = trim((string) ($l['ville'] ?? ''));

            // Format jj/mm/aaaa attendu (celui du CSV FFTT/API) — toute autre valeur
            // est ignorée plutôt que stockée telle quelle.
            $dateFournie  = array_key_exists('date_validation_fftt', $l);
            $dateValidRaw = trim((string) ($l['date_validation_fftt'] ?? ''));
            $dateValidStr = preg_match('#^\d{1,2}/\d{2}/\d{4}$#', $dateValidRaw) ? $dateValidRaw : '';
            $dateValid    = $dateValidStr ?: null;

            if ($nom === '') {
                continue;
            }

            if ($idClub !== null && !isset($clubsValides[$idClub])) {
                $avertissements[] = "$nom $prenom : club « $idClub » inconnu (pas encore synchronisé) — enregistré sans club.";
                $idClub = null;
            }

            $codeDept = $this->resolveCodeDept($pdo, $idClub, $cp, $idLap, $deptManuel);

            try {
                if ($id > 0) {
                    $stmtCheck->execute([$id]);
                    if ((int) $stmtCheck->fetchColumn() > 0) {
                        // SET construit ligne par ligne : DateValidationFFTT /
                        // Defiscalisation / Nationale / NumCompteEBP ne sont écrits que
                        // si la ligne les fournit explicitement (modale Créer/Modifier
                        // JA). Les imports FFTT — API (importFfttClub/importFfttSelected)
                        // et CSV 102_*.csv (importerExcel) — ne les envoient pas : la
                        // valeur en base ne doit alors pas être écrasée.
                        $setOpt    = '';
                        $paramsOpt = [];
                        if ($dateFournie) {
                            $setOpt .= 'DateValidationFFTT=?, ';
                            $paramsOpt[] = $dateValid;
                        }
                        if (array_key_exists('defiscalisation', $l)) {
                            $setOpt .= 'Defiscalisation=?, ';
                            $paramsOpt[] = $defisc;
                        }
                        if (array_key_exists('nationale', $l)) {
                            $setOpt .= 'Nationale=?, ';
                            $paramsOpt[] = $nationale;
                        }
                        if (array_key_exists('num_compte_ebp', $l)) {
                            $setOpt .= 'NumCompteEBP=?, ';
                            $paramsOpt[] = $cpteEbp;
                        }
                        $sql = "UPDATE ja SET {$setOpt}Nom=?, Prenom=?, Email=?, Telephone=?, Grade=?,
                                       Actif=?, Id_Club=?, Id_LaPoste=?,
                                       Cp=?, Ville=?, CodeDept=?
                                WHERE Id_JA=?";
                        $pdo->prepare($sql)->execute(array_merge(
                            $paramsOpt,
                            [$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $cp, $ville, $codeDept, $id]
                        ));
                        $updates++;
                    } else {
                        $stmtInsert->execute([$id, $nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville, $dateValid, $codeDept]);
                        $inserts++;
                    }
                } else {
                    $stmtInsertAuto->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville, $dateValid, $codeDept]);
                    $inserts++;
                }
            } catch (\PDOException $ex) {
                $erreurs[] = "$nom $prenom : " . $ex->getMessage();
            }
        }

        $msg = "Mise à jour terminée : $inserts insérés, $updates mis à jour.";
        if ($avertissements) {
            $msg .= ' Avertissements : ' . implode(' | ', $avertissements);
        }
        if ($erreurs) {
            $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
        }

        return $this->response->setJSON(['ok' => empty($erreurs), 'msg' => $msg]);
    }

    public function getClubsDept(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $dep = trim($this->request->getPost('dep') ?? '');
        if ($dep === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Département manquant.']);
        }

        $depFftt = self::DEPT_FFTT_CORSE[strtoupper($dep)] ?? $dep;
        $clubs   = getFfttRawClient()->listClubsByDepartement($depFftt);

        return $this->response->setJSON(['ok' => true, 'clubs' => array_map(static fn ($c) => [
            'numero' => $c['numero'],
            'nom'    => $c['nom'],
        ], $clubs)]);
    }

    /**
     * Réinitialise Actif=0 pour tous les JA du département AVANT de lancer
     * l'import/scan FFTT (voir importFfttClub()/importFfttSelected()) — cette
     * action ne réactive ensuite personne, même un JA retrouvé dans le rapport
     * FFTT du passage reste à Actif=0 (seuls le CSV FFTT et la modale
     * Créer/Modifier JA peuvent remettre Actif à 1). Département résolu comme
     * dans liste() : Id_Club (positions 3-4) ou, à défaut, code postal du JA
     * — CodeDept n'est renseigné nulle part.
     */
    public function reinitialiserActifDept(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $dep = trim($this->request->getPost('dep') ?? '');
        if ($dep === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Département manquant.']);
        }

        $pdo    = getPDO();
        $depPad = str_pad($dep, 2, '0', STR_PAD_LEFT);
        $stmt   = $pdo->prepare(
            'UPDATE ja j SET j.Actif = 0
             WHERE (
                 SUBSTRING(j.Id_Club, 3, 2) = ?
                 OR ((j.Id_Club IS NULL OR j.Id_Club = \'\') AND LEFT((SELECT lp2.CodePostal FROM laposte lp2 WHERE lp2.Id_LaPoste = j.Id_LaPoste LIMIT 1), 2) = ?)
             )'
        );
        $stmt->execute([$depPad, $depPad]);

        return $this->response->setJSON(['ok' => true, 'maj' => $stmt->rowCount()]);
    }

    /**
     * Détail d'une licence via xml_licence_b (appel direct request(), pas
     * retrieveJoueurDetails() : accède au tableau brut, qui expose email/Cp/Ville
     * — ces champs pré-remplissent l'adresse d'un nouveau JA quand l'API FFTT les
     * fournit ; en pratique souvent absents de la réponse, quels que soient les
     * identifiants applicatifs utilisés). Un seul nouvel essai après 600 ms.
     *
     * Retourne null si la licence est introuvable ou n'est pas JA1/JA2/JA3
     * (les AR sont exclus) ; lève l'exception de l'API au 2e échec.
     */
    private function lireJaFftt(\PDO $pdo, $apiRaw, string $numClub, string $licence): ?array
    {
        $lb = null;
        for ($tentative = 0; $tentative < 2; $tentative++) {
            try {
                $lb = $apiRaw->request('xml_licence_b', ['licence' => $licence, 'club' => $numClub])['licence'] ?? null;
                break;
            } catch (\Throwable $e) {
                if ($tentative === 0) {
                    usleep(600_000);
                } else {
                    throw $e;
                }
            }
        }
        if (!$lb) {
            return null;
        }

        $grade = ffttStr($lb['ja'] ?? '') ?: ffttStr($lb['arb'] ?? '');
        if (!preg_match('/^JA[123]$/i', $grade)) {
            return null;
        }

        // Résolution CP / Ville / Id_LaPoste depuis les données FFTT
        $cp        = ffttStr($lb['cp'] ?? '');
        $ville     = normaliserVille(ffttStr($lb['ville'] ?? ''));
        $idLaPoste = null;
        if ($cp !== '') {
            $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
            $stmtLap->execute([$cp]);
            $lap = $stmtLap->fetch();
            if ($lap) {
                $idLaPoste = $lap['Id_LaPoste'];
                $ville     = $ville !== '' ? $ville : normaliserVille((string) ($lap['Nom'] ?? ''));
            }
        }

        return [
            'licence'    => $licence,
            'nom'        => mb_strtoupper(ffttStr($lb['nom'] ?? ''), 'UTF-8'),
            'prenom'     => ffttStr($lb['prenom'] ?? ''),
            'email'      => ffttStr($lb['email'] ?? ''),
            'grade'      => strtoupper($grade),
            'id_club'    => ffttStr($lb['numclub'] ?? '') ?: $numClub,
            'date_valid' => ffttStr($lb['validation'] ?? '') ?: null,
            'id_laposte' => $idLaPoste,
            'cp'         => $cp,
            'ville'      => $ville,
        ];
    }

    /**
     * Insère ou met à jour un JA issu de l'API FFTT ($d : voir lireJaFftt()).
     * Retourne true si le JA vient d'être créé.
     *
     * Actif n'est jamais remis à 1 ici : reinitialiserActifDept() (appelée par
     * le JS avant la boucle clubs) a déjà tout mis à 0 pour le département, et
     * ces imports ne réactivent personne — même un JA retrouvé dans le rapport
     * FFTT reste à Actif=0 ; un nouveau JA est créé à Actif=0 pour la même raison.
     */
    private function upsertJaFftt(\PDO $pdo, array $d): bool
    {
        $exists = $pdo->prepare('SELECT 1 FROM ja WHERE Id_JA = ?');
        $exists->execute([$d['licence']]);

        if ($exists->fetchColumn()) {
            $pdo->prepare(
                'UPDATE ja SET DateValidationFFTT=?,
                 Cp = COALESCE(Cp, ?), Ville = COALESCE(Ville, ?), Id_LaPoste = COALESCE(Id_LaPoste, ?)
                 WHERE Id_JA=?'
            )->execute([$d['date_valid'], $d['cp'], $d['ville'], $d['id_laposte'], $d['licence']]);

            return false;
        }

        $pdo->prepare(
            'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, Actif, Id_Club,
                             Defiscalisation, Nationale, DateValidationFFTT,
                             Id_LaPoste, Cp, Ville)
             VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, ?, ?, ?, ?)'
        )->execute([$d['licence'], $d['nom'], $d['prenom'], $d['email'] ?: null, $d['grade'], $d['id_club'],
            $d['date_valid'], $d['id_laposte'], $d['cp'], $d['ville']]);

        return true;
    }

    /**
     * Parcourt les licenciés d'un club et applique $traiter à chaque JA1/JA2/JA3
     * trouvé (import direct ou simple scan). Retourne ce que le JS attend :
     * trouves / total_membres / erreurs / erreurs_msgs (10 messages max).
     */
    private function parcourirClubFftt(string $action, callable $traiter): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $numClub = trim($this->request->getPost('num_club') ?? '');
        if ($numClub === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
        }

        set_time_limit(180);
        $pdo         = getPDO();
        $apiRaw      = getFfttRawClient();
        $membres     = $apiRaw->listJoueursByClub($numClub);
        $trouves     = [];
        $erreurs     = 0;
        $erreursMsgs = [];

        foreach ($membres as $m) {
            $licence = trim($m['licence'] ?? '');
            if ($licence === '') {
                continue;
            }

            try {
                $d = $this->lireJaFftt($pdo, $apiRaw, $numClub, $licence);
                if ($d !== null) {
                    $trouves[] = $traiter($pdo, $d);
                }
            } catch (\Throwable $e) {
                $erreurs++;
                $msg = $e->getMessage();
                error_log("[NIJAC] $action $numClub licence=$licence : $msg");
                if (count($erreursMsgs) < 10) {
                    $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
    }

    public function importFfttClub(): ResponseInterface
    {
        return $this->parcourirClubFftt('import_fftt_club', function (\PDO $pdo, array $d): array {
            $nouveau = $this->upsertJaFftt($pdo, $d);

            return ['licence' => $d['licence'], 'nom' => $d['nom'], 'prenom' => $d['prenom'], 'grade' => $d['grade'], 'statut' => $nouveau ? 'nouveau' : 'mis_a_jour'];
        });
    }

    public function scanFfttClub(): ResponseInterface
    {
        return $this->parcourirClubFftt('scan_fftt_club', function (\PDO $pdo, array $d): array {
            $stmtEx = $pdo->prepare('SELECT 1 FROM ja WHERE Id_JA = ?');
            $stmtEx->execute([$d['licence']]);

            return [
                'licence'         => $d['licence'],
                'nom'             => $d['nom'],
                'prenom'          => $d['prenom'],
                'email'           => $d['email'],
                'grade'           => $d['grade'],
                'id_club'         => $d['id_club'],
                'id_laposte'      => $d['id_laposte'],
                'cp'              => $d['cp'],
                'ville'           => $d['ville'],
                'date_validation' => $d['date_valid'],
                'en_base'         => (bool) $stmtEx->fetchColumn(),
            ];
        });
    }

    public function importFfttSelected(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();

        $licences = json_decode($this->request->getPost('licences') ?? '[]', true);
        if (!is_array($licences)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Données invalides.']);
        }

        $nouveaux = 0;
        $maj      = 0;

        foreach ($licences as $ja) {
            $d = [
                'licence'    => trim((string) ($ja['licence'] ?? '')),
                'nom'        => trim((string) ($ja['nom'] ?? '')),
                'prenom'     => trim((string) ($ja['prenom'] ?? '')),
                'email'      => trim((string) ($ja['email'] ?? '')),
                'grade'      => trim((string) ($ja['grade'] ?? '')),
                'id_club'    => trim((string) ($ja['id_club'] ?? '')),
                'id_laposte' => $ja['id_laposte'] ?? null,
                'cp'         => trim((string) ($ja['cp'] ?? '')),
                'ville'      => trim((string) ($ja['ville'] ?? '')),
                'date_valid' => trim((string) ($ja['date_validation'] ?? '')) ?: null,
            ];

            if ($d['licence'] === '' || $d['grade'] === '') {
                continue;
            }

            if ($this->upsertJaFftt($pdo, $d)) {
                $nouveaux++;
            } else {
                $maj++;
            }
        }

        return $this->response->setJSON(['ok' => true, 'nouveaux' => $nouveaux, 'maj' => $maj]);
    }

    public function importCsvEbp(): ResponseInterface
    {

        $pdo = getPDO();

        $lignes = json_decode($this->request->getPost('lignes') ?? '[]', true);
        if (!is_array($lignes)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Données invalides.']);
        }

        $ok     = 0;
        $echecs = [];

        $stmtSearch = $pdo->prepare(
            "SELECT Id_JA, Nom, Prenom FROM ja
             WHERE UPPER(?) LIKE CONCAT('%', UPPER(TRIM(Nom)), '%')
               AND UPPER(?) LIKE CONCAT('%', UPPER(TRIM(Prenom)), '%')"
        );
        $stmtUpdate = $pdo->prepare('UPDATE ja SET NumCompteEBP = ? WHERE Id_JA = ?');

        foreach ($lignes as $idx => $ligne) {
            $numEbp    = trim((string) ($ligne['num'] ?? ''));
            $nomPrenom = trim((string) ($ligne['nom'] ?? ''));
            $lineNo    = $idx + 1;

            if ($numEbp === '' || $nomPrenom === '') {
                $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => 'Ligne incomplète'];
                continue;
            }

            if (stripos($nomPrenom, 'fournisseur') === 0) {
                continue;
            }

            $stmtSearch->execute([$nomPrenom, $nomPrenom]);
            $found = $stmtSearch->fetchAll();

            if (count($found) === 0) {
                $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => 'JA introuvable en base'];
            } elseif (count($found) > 1) {
                $dups     = implode(', ', array_map(static fn ($r) => $r['Id_JA'], $found));
                $echecs[] = ['ligne' => $lineNo, 'texte' => $nomPrenom, 'raison' => "Plusieurs JA trouvés ($dups) — non mis à jour"];
            } else {
                $stmtUpdate->execute([$numEbp, $found[0]['Id_JA']]);
                $ok++;
            }
        }

        return $this->response->setJSON(['ok' => true, 'maj' => $ok, 'echecs' => $echecs]);
    }

    public function importerExcel(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé']);
        }

        $pdo = getPDO();

        $file = $this->request->getFile('fichier');
        if ($file === null || !$file->isValid()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun fichier reçu (post_max_size = ' . ini_get('post_max_size') . ').']);
        }
        if (!in_array(strtolower($file->getClientExtension()), ['csv', 'txt'], true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Seul le format .csv ou .txt est accepté.']);
        }

        set_time_limit(180);

        $handle = fopen($file->getTempName(), 'r');
        if ($handle === false) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier illisible ou corrompu.']);
        }

        // En-têtes exacts de l'export FFTT (102_*.csv) — colonnes retrouvées par
        // nom plutôt que par position, plus robuste à un réordonnancement.
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier CSV vide ou illisible.']);
        }
        $header = array_map(static fn ($h) => trim((string) $h), $header);
        $col    = array_flip($header);

        $colonnesRequises = ['N° Licence', 'Nom', 'Prénom', 'N° club', 'Grade Arb/Ja', 'Inactivité', 'Date de validation', 'Code Postal', 'Ville', 'Mail', 'Téléphone', 'Portable'];
        $manquantes       = array_values(array_diff($colonnesRequises, $header));
        if ($manquantes) {
            fclose($handle);

            return $this->response->setJSON(['ok' => false, 'msg' => 'Colonne(s) attendue(s) absente(s) du CSV : ' . implode(', ', $manquantes)]);
        }

        // Charger tous les clubs pour enrichir nom_club
        $clubsMap = [];
        foreach ($pdo->query('SELECT Id_Club, Nom FROM Club')->fetchAll() as $c) {
            $clubsMap[$c['Id_Club']] = $c['Nom'];
        }

        $lignes           = [];
        $idsClubManquants = [];
        while (($ligne = fgetcsv($handle)) !== false) {
            $grade = trim((string) ($ligne[$col['Grade Arb/Ja']] ?? ''));
            if (!preg_match('/^JA1$/i', $grade)) {
                continue;
            }

            $idJA         = trim((string) ($ligne[$col['N° Licence']] ?? ''));
            $nom          = trim((string) ($ligne[$col['Nom']] ?? ''));
            $prenom       = trim((string) ($ligne[$col['Prénom']] ?? ''));
            $idClub       = trim((string) ($ligne[$col['N° club']] ?? ''));
            $actifRaw     = trim((string) ($ligne[$col['Inactivité']] ?? ''));
            $dateValidRaw = trim((string) ($ligne[$col['Date de validation']] ?? ''));
            $cp           = trim((string) ($ligne[$col['Code Postal']] ?? ''));
            $ville        = trim((string) ($ligne[$col['Ville']] ?? ''));
            $email        = trim((string) ($ligne[$col['Mail']] ?? ''));
            $telPortable  = trim((string) ($ligne[$col['Portable']] ?? ''));
            $telFixe      = trim((string) ($ligne[$col['Téléphone']] ?? ''));

            if ($nom === '' && $prenom === '') {
                continue;
            }

            $tel          = $telPortable !== '' ? $telPortable : $telFixe;
            $actif        = strtolower($actifRaw) === 'actif' ? 1 : 0;
            $dateValidStr = preg_match('#^\d{1,2}/\d{2}/\d{4}$#', $dateValidRaw) ? $dateValidRaw : '';

            if ($idClub !== '' && !isset($clubsMap[$idClub])) {
                $idsClubManquants[] = $idClub;
            }

            $lignes[] = [
                'id'                   => $idJA !== '' ? (int) $idJA : 0,
                'nom'                  => mb_strtoupper($nom, 'UTF-8'),
                'prenom'               => mb_convert_case($prenom, MB_CASE_TITLE, 'UTF-8'),
                'email'                => $email !== '' ? $email : null,
                'telephone'            => $this->formaterTelephone($tel !== '' ? $tel : null),
                'grade'                => $grade,
                'actif'                => $actif,
                'date_validation_fftt' => $dateValidStr !== '' ? $dateValidStr : null,
                'id_club'              => $idClub !== '' ? $idClub : null,
                'id_laposte'           => null, // résolu côté JS avec progression
                'cp'                   => $cp,
                'ville'                => $ville,
            ];
        }
        fclose($handle);

        // Clubs référencés par le CSV mais absents localement — créés à la
        // volée depuis l'API FFTT (le N° club vient du CSV lui-même), via le
        // même helper que ClubController::syncFfttClub() (écran EA81).
        $clubsCrees = [];
        foreach (array_unique($idsClubManquants) as $numClub) {
            try {
                $detail = getFfttRawClient()->retrieveClubDetails($numClub);
                $sync   = synchroniserClubFftt($pdo, $numClub, $detail);
                if ($sync !== null) {
                    $clubsMap[$numClub] = $sync['nom'];
                    $clubsCrees[]       = ['id_club' => $numClub, 'nom' => $sync['nom']];
                }
            } catch (\Throwable $e) {
                error_log("[NIJAC] import_excel_ja : club $numClub introuvable via API FFTT : " . $e->getMessage());
            }
        }

        foreach ($lignes as &$l) {
            $l['nom_club'] = $l['id_club'] !== null ? ($clubsMap[$l['id_club']] ?? '') : '';
        }
        unset($l);

        $lignes = $this->deduplicateJA($lignes, 'nom', 'prenom', 'grade');

        return $this->response->setJSON(['ok' => true, 'data' => $lignes, 'count' => count($lignes), 'clubs_crees' => $clubsCrees]);
    }
}
