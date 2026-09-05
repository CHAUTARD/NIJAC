<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des Juges-Arbitres (EN11), portage CI4 de Nominateur/jugearbitre.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * un Nominateur consulte/modifie la grille et importe des comptes EBP ; import
 * Excel FFTT, import/scan API FFTT par département et mise à jour ponctuelle
 * de Id_LaPoste sont restreints à l'admin — vérifié individuellement dans
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
     * Non appelée par le JS de jugearbitre.php (aucun autre fichier n'y fait
     * appel non plus) — portée à l'identique pour parité, comme
     * liste_tables_db dans CleanController.
     */
    public function majLaposte(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();

        $idJA      = (int) ($this->request->getPost('id_ja') ?? 0);
        $idLaPoste = ($this->request->getPost('id_laposte') ?? '') !== '' ? (int) $this->request->getPost('id_laposte') : null;
        $cp        = ($this->request->getPost('cp') ?? '') !== '' ? trim($this->request->getPost('cp')) : null;
        $ville     = ($this->request->getPost('ville') ?? '') !== '' ? trim($this->request->getPost('ville')) : null;

        if ($idJA <= 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Id_JA invalide.']);
        }

        $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
            ->execute([$idLaPoste, $cp, $ville, $idJA]);

        return $this->response->setJSON(['ok' => true]);
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
        // scan FFTT retrouve. DateValidationFFTT n'est mis à jour ici que si la
        // ligne fournit explicitement date_validation_fftt (import CSV FFTT
        // uniquement — la modale ne l'envoie pas, pour ne pas écraser une valeur
        // déjà synchronisée par ailleurs).
        $stmtCheck  = $pdo->prepare('SELECT COUNT(*) FROM ja WHERE Id_JA = ?');
        $stmtInsert = $pdo->prepare(
            'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Telephone, Grade, Actif,
                             Id_Club, Id_LaPoste, Defiscalisation, Nationale, NumCompteEBP,
                             Cp, Ville, DateValidationFFTT, CodeDept)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmtUpdateAvecDate = $pdo->prepare(
            'UPDATE ja SET Nom=?, Prenom=?, Email=?, Telephone=?, Grade=?,
                           Actif=?, Id_Club=?, Id_LaPoste=?,
                           Defiscalisation=?, Nationale=?, NumCompteEBP=?,
                           Cp=?, Ville=?, DateValidationFFTT=?, CodeDept=?
             WHERE Id_JA=?'
        );
        $stmtUpdateSansDate = $pdo->prepare(
            'UPDATE ja SET Nom=?, Prenom=?, Email=?, Telephone=?, Grade=?,
                           Actif=?, Id_Club=?, Id_LaPoste=?,
                           Defiscalisation=?, Nationale=?, NumCompteEBP=?,
                           Cp=?, Ville=?, CodeDept=?
             WHERE Id_JA=?'
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
                        if ($dateFournie) {
                            $stmtUpdateAvecDate->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville, $dateValid, $codeDept, $id]);
                        } else {
                            $stmtUpdateSansDate->execute([$nom, $prenom, $email, $tel, $grade, $actif, $idClub, $idLap, $defisc, $nationale, $cpteEbp, $cp, $ville, $codeDept, $id]);
                        }
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
     * Réinitialise Actif=0 pour TOUS les JA (tous départements), appelée par le
     * JS juste avant l'enregistrement en base de l'import CSV FFTT (102_*.csv,
     * fichier national) — même principe que reinitialiserActifDept() pour
     * l'import/scan API par département : les JA absents du nouveau fichier
     * (donc jamais réactivés ensuite par maj-bdd()) restent Inactif.
     */
    public function reinitialiserActifTous(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $stmt = getPDO()->query('UPDATE ja SET Actif = 0');

        return $this->response->setJSON(['ok' => true, 'maj' => $stmt->rowCount()]);
    }

    /**
     * listJoueursByClub pour la liste des licences, puis xml_licence_b en
     * appel direct (request(), pas retrieveJoueurDetails()) pour le détail :
     * accède au tableau brut, qui expose email/Cp/Ville — ces champs
     * pré-remplissent l'adresse d'un nouveau JA quand l'API FFTT les fournit
     * (en pratique souvent absents de la réponse elle-même, quels que soient
     * les identifiants applicatifs utilisés).
     */
    public function importFfttClub(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();

        $numClub = trim($this->request->getPost('num_club') ?? '');
        if ($numClub === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
        }

        set_time_limit(180);
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
                    continue;
                }

                $ja  = ffttStr($lb['ja'] ?? '');
                $arb = ffttStr($lb['arb'] ?? '');
                if ($ja === '' && $arb === '') {
                    continue;
                }

                $grade  = $ja ?: $arb;
                $nom    = mb_strtoupper(ffttStr($lb['nom'] ?? ''), 'UTF-8');
                $prenom = ffttStr($lb['prenom'] ?? '');
                $email  = ffttStr($lb['email'] ?? '');
                $idClub = ffttStr($lb['numclub'] ?? '') ?: $numClub;

                // Seuls JA1, JA2, JA3 — les AR sont exclus
                if (!preg_match('/^JA[123]$/i', $grade)) {
                    continue;
                }

                $gradeNorm = strtoupper($grade);

                $dateValidStr = ffttStr($lb['validation'] ?? '');
                $dateValid    = $dateValidStr ?: null;

                // Résolution CP / Ville / Id_LaPoste depuis les données FFTT
                $cpFFTT    = ffttStr($lb['cp'] ?? '');
                $villeFFTT = normaliserVille(ffttStr($lb['ville'] ?? ''));
                $idLaPoste = null;
                $cpFinal   = $cpFFTT;
                $villeFinal = $villeFFTT;
                if ($cpFFTT !== '') {
                    $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
                    $stmtLap->execute([$cpFFTT]);
                    $lap = $stmtLap->fetch();
                    if ($lap) {
                        $idLaPoste  = $lap['Id_LaPoste'];
                        $villeFinal = $villeFFTT !== '' ? $villeFFTT : normaliserVille((string) ($lap['Nom'] ?? ''));
                    }
                }

                $exists = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                $exists->execute([$licence]);
                if ($exists->fetchColumn()) {
                    // Actif n'est jamais remis à 1 ici : reinitialiserActifDept()
                    // (appelée par le JS avant la boucle clubs) a déjà tout mis à 0
                    // pour le département, et cette action ne réactive personne —
                    // même un JA retrouvé dans le rapport FFTT reste à Actif=0.
                    $pdo->prepare(
                        'UPDATE ja SET DateValidationFFTT=?,
                         Cp = COALESCE(Cp, ?), Ville = COALESCE(Ville, ?), Id_LaPoste = COALESCE(Id_LaPoste, ?)
                         WHERE Id_JA=?'
                    )->execute([$dateValid, $cpFinal, $villeFinal, $idLaPoste, $licence]);
                    $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'mis_a_jour'];
                } else {
                    // Actif=0 : un nouveau JA créé via cette action n'est pas non plus
                    // réactivé, pour rester cohérent avec la remise à zéro du département.
                    $pdo->prepare(
                        'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, Actif, Id_Club,
                                         Defiscalisation, Nationale, DateValidationFFTT,
                                         Id_LaPoste, Cp, Ville)
                         VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, ?, ?, ?, ?)'
                    )->execute([$licence, $nom, $prenom, $email ?: null, $gradeNorm, $idClub,
                        $dateValid, $idLaPoste, $cpFinal, $villeFinal]);
                    $trouves[] = ['licence' => $licence, 'nom' => $nom, 'prenom' => $prenom, 'grade' => $gradeNorm, 'statut' => 'nouveau'];
                }
            } catch (\Throwable $e) {
                $erreurs++;
                $msg = $e->getMessage();
                error_log("[NIJAC] import_fftt_club $numClub licence=$licence : $msg");
                if (count($erreursMsgs) < 10) {
                    $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
    }

    public function scanFfttClub(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();

        $numClub = trim($this->request->getPost('num_club') ?? '');
        if ($numClub === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
        }

        set_time_limit(180);
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
                    continue;
                }

                $ja  = ffttStr($lb['ja'] ?? '');
                $arb = ffttStr($lb['arb'] ?? '');
                if ($ja === '' && $arb === '') {
                    continue;
                }
                $grade = $ja ?: $arb;
                if (!preg_match('/^JA[123]$/i', $grade)) {
                    continue;
                }

                $gradeNorm = strtoupper($grade);
                $nom       = mb_strtoupper(ffttStr($lb['nom'] ?? ''), 'UTF-8');
                $prenom    = ffttStr($lb['prenom'] ?? '');
                $email     = ffttStr($lb['email'] ?? '');
                $idClub    = ffttStr($lb['numclub'] ?? '') ?: $numClub;

                $dateValidStr = ffttStr($lb['validation'] ?? '');
                $dateValid    = $dateValidStr ?: null;

                $cpFFTT    = ffttStr($lb['cp'] ?? '');
                $villeFFTT = normaliserVille(ffttStr($lb['ville'] ?? ''));
                $idLaPoste = null;
                $cpFinal   = $cpFFTT;
                $villeFinal = $villeFFTT;
                if ($cpFFTT !== '') {
                    $stmtLap = $pdo->prepare('SELECT Id_LaPoste, Nom FROM laposte WHERE CodePostal = ? LIMIT 1');
                    $stmtLap->execute([$cpFFTT]);
                    $lap = $stmtLap->fetch();
                    if ($lap) {
                        $idLaPoste  = $lap['Id_LaPoste'];
                        $villeFinal = $villeFFTT !== '' ? $villeFFTT : normaliserVille((string) ($lap['Nom'] ?? ''));
                    }
                }

                $stmtEx = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
                $stmtEx->execute([$licence]);
                $enBase = (bool) $stmtEx->fetchColumn();

                $trouves[] = [
                    'licence'         => $licence,
                    'nom'             => $nom,
                    'prenom'          => $prenom,
                    'email'           => $email,
                    'grade'           => $gradeNorm,
                    'id_club'         => $idClub,
                    'id_laposte'      => $idLaPoste,
                    'cp'              => $cpFinal,
                    'ville'           => $villeFinal,
                    'date_validation' => $dateValid,
                    'en_base'         => $enBase,
                ];
            } catch (\Throwable $e) {
                $erreurs++;
                $msg = $e->getMessage();
                error_log("[NIJAC] scan_fftt_club $numClub licence=$licence : $msg");
                if (count($erreursMsgs) < 10) {
                    $erreursMsgs[] = "[$licence] " . mb_substr($msg, 0, 120);
                }
            }
        }

        return $this->response->setJSON(['ok' => true, 'trouves' => $trouves, 'total_membres' => count($membres), 'erreurs' => $erreurs, 'erreurs_msgs' => $erreursMsgs]);
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
            $licence      = trim((string) ($ja['licence'] ?? ''));
            $nom          = trim((string) ($ja['nom'] ?? ''));
            $prenom       = trim((string) ($ja['prenom'] ?? ''));
            $email        = trim((string) ($ja['email'] ?? '')) ?: null;
            $grade        = trim((string) ($ja['grade'] ?? ''));
            $idClub       = trim((string) ($ja['id_club'] ?? ''));
            $idLaPoste    = $ja['id_laposte'] ?? null;
            $cpFinal      = trim((string) ($ja['cp'] ?? ''));
            $villeFinal   = trim((string) ($ja['ville'] ?? ''));
            $dateValidStr = trim((string) ($ja['date_validation'] ?? ''));
            $dateValid    = $dateValidStr ?: null;

            if ($licence === '' || $grade === '') {
                continue;
            }

            $stmtEx = $pdo->prepare('SELECT Id_JA FROM ja WHERE Id_JA = ?');
            $stmtEx->execute([$licence]);
            if ($stmtEx->fetchColumn()) {
                // Actif n'est jamais remis à 1 ici : reinitialiserActifDept()
                // (appelée par le JS avant la boucle clubs) a déjà tout mis à 0
                // pour le département, et cette action ne réactive personne —
                // même un JA retrouvé dans le rapport FFTT reste à Actif=0.
                $pdo->prepare(
                    'UPDATE ja SET DateValidationFFTT=?,
                     Cp = COALESCE(Cp, ?), Ville = COALESCE(Ville, ?), Id_LaPoste = COALESCE(Id_LaPoste, ?)
                     WHERE Id_JA=?'
                )->execute([$dateValid, $cpFinal, $villeFinal, $idLaPoste, $licence]);
                $maj++;
            } else {
                // Actif=0 : un nouveau JA créé via cette action n'est pas non plus
                // réactivé, pour rester cohérent avec la remise à zéro du département.
                $pdo->prepare(
                    'INSERT INTO ja (Id_JA, Nom, Prenom, Email, Grade, Actif, Id_Club,
                                     Defiscalisation, Nationale, DateValidationFFTT,
                                     Id_LaPoste, Cp, Ville)
                     VALUES (?, ?, ?, ?, ?, 0, ?, 0, 0, ?, ?, ?, ?)'
                )->execute([$licence, $nom, $prenom, $email, $grade, $idClub, $dateValid, $idLaPoste, $cpFinal, $villeFinal]);
                $nouveaux++;
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

    /**
     * Non appelée par le JS de jugearbitre.php — portée à l'identique pour
     * parité (voir majLaposte()).
     */
    public function enrichirFftt(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'err' => 'Accès refusé']);
        }

        $pdo = getPDO();

        $idJa = trim($this->request->getPost('id_ja') ?? '');
        if ($idJa === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'id_ja manquant.']);
        }

        $lic = getFfttRawClient()->retrieveJoueurDetails($idJa);
        if (empty($lic)) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Licence $idJa introuvable dans l'API FFTT."]);
        }
        if (array_is_list($lic)) {
            return $this->response->setJSON(['ok' => false, 'msg' => "Plusieurs fiches trouvées pour la licence $idJa dans l'API FFTT."]);
        }

        $dateValidStr = ffttStr($lic['validation'] ?? '');
        $dateValid    = $dateValidStr ?: null;

        // CP, Ville et Id_LaPoste volontairement exclus : les données FFTT sont moins fiables que la BDD locale
        $pdo->prepare(
            'UPDATE ja SET DateValidationFFTT=? WHERE Id_JA=?'
        )->execute([$dateValid, $idJa]);

        return $this->response->setJSON([
            'ok'         => true,
            'date_valid' => $dateValid,
            'nom_fftt'   => ffttStr($lic['nom'] ?? '') . ' ' . ffttStr($lic['prenom'] ?? ''),
            'club_fftt'  => ffttStr($lic['nomclub'] ?? ''),
        ]);
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

    /**
     * Rejoue un fichier 102_*.csv sans le réimporter : pour chaque licence
     * marquée « actif » dans le fichier, si le JA existe en base avec Actif=0,
     * on le repasse à Actif=1 et on met à jour DateValidationFFTT (si le fichier
     * fournit une date d/m/Y valide). Ne crée rien, ne touche jamais un JA déjà
     * actif ni un JA absent de la base. Renvoie le nombre de lignes modifiées.
     */
    public function verifActifs102(): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Accès refusé']);
        }

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

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $this->response->setJSON(['ok' => false, 'msg' => 'Fichier CSV vide ou illisible.']);
        }
        $header = array_map(static fn ($h) => trim((string) $h), $header);
        $col    = array_flip($header);

        $colonnesRequises = ['N° Licence', 'Inactivité', 'Date de validation'];
        $manquantes       = array_values(array_diff($colonnesRequises, $header));
        if ($manquantes) {
            fclose($handle);

            return $this->response->setJSON(['ok' => false, 'msg' => 'Colonne(s) attendue(s) absente(s) du CSV : ' . implode(', ', $manquantes)]);
        }

        $stmt = getPDO()->prepare(
            'UPDATE ja SET Actif = 1, DateValidationFFTT = COALESCE(?, DateValidationFFTT)
             WHERE Id_JA = ? AND Actif = 0'
        );

        $modifies = 0;
        while (($ligne = fgetcsv($handle)) !== false) {
            if (strtolower(trim((string) ($ligne[$col['Inactivité']] ?? ''))) !== 'actif') {
                continue;
            }
            $idJA = trim((string) ($ligne[$col['N° Licence']] ?? ''));
            if ($idJA === '') {
                continue;
            }
            $dateValidRaw = trim((string) ($ligne[$col['Date de validation']] ?? ''));
            $dateValidStr = preg_match('#^\d{1,2}/\d{2}/\d{4}$#', $dateValidRaw) ? $dateValidRaw : null;

            $stmt->execute([$dateValidStr, $idJA]);
            $modifies += $stmt->rowCount();
        }
        fclose($handle);

        return $this->response->setJSON(['ok' => true, 'modifies' => $modifies]);
    }
}
