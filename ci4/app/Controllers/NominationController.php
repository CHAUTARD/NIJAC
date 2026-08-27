<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Nomination des Juges-Arbitres (E022), portage CI4 de
 * Nominateur/nomination.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth"). Le classement/tri
 * des candidats JA (score, distance, préférence) reste calculé côté client en
 * JS, comme le fait le fichier legacy — le serveur ne fait que retourner les
 * données brutes (coordonnées, disponibilités, nominations existantes).
 *
 * Pas de Model : jointures multiples nomination→disponible→ja, résolution de
 * disponibilité avec matérialisation conditionnelle — trop éloigné du Query
 * Builder simple. Réutilise getPDO() directement, comme le fichier legacy.
 *
 * Règle de nomination : au plus 2 nominations par JA et par journée ; la 2ᵉ
 * est décidée manuellement par le nominateur (plus d'affectation automatique
 * « même salle »).
 */
class NominationController extends BaseController
{
    private const ID_MESSAGE_CONVOCATION = 3;

    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);
    }

    /**
     * Exécute une action et convertit toute exception en réponse JSON
     * ['ok' => false, 'err' => ...] — nomination.php enveloppe de la même
     * façon la totalité de son dispatcher d'actions.
     */
    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'err' => $e->getMessage()]);
        }
    }

    private function deptsAutorises(): array
    {
        return getDepartementsAutorises($_SESSION['utilisateur']['id_departement'] ?? null);
    }

    /**
     * Vérifie que la rencontre $idRenc appartient à un club dont le
     * département fait partie de $deptsAutorises — mêmes règles que
     * journees()/rencontresJournee(), appliquées ici aux actions d'écriture
     * (affecterJa/retirerJa/validerNominations/envoyerConvocations), qui ne
     * filtraient auparavant que sur des paramètres non vides, sans jamais
     * vérifier le périmètre du nominateur appelant.
     */
    private function rencontreAutorisee(\PDO $pdo, int $idRenc, array $deptsAutorises): bool
    {
        if (!$deptsAutorises) {
            return false;
        }
        $ph   = implode(',', array_fill(0, count($deptsAutorises), '?'));
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            WHERE r.Id_Rencontre = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
        ");
        $stmt->execute(array_merge([$idRenc], $deptsAutorises));

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Résout l'Id_Disponible à utiliser pour nominer $idJa sur $idRenc (date $dateRenc).
     * Règle : un JA doit être disponible pour être nominé.
     *   1. Ligne disponible précise (Id_Rencontre = $idRenc, Reponse='O') → utilisée telle quelle.
     *   2. Sinon, disponibilité "toute la journée" (Id_Rencontre NULL, Reponse='O', même date)
     *      → une ligne précise est matérialisée pour cette rencontre.
     *   3. Sinon → null (le JA n'est pas disponible, la nomination doit être refusée).
     */
    private function resoudreDisponible(\PDO $pdo, int $idJa, int $idRenc, string $dateRenc): ?int
    {
        $stmt = $pdo->prepare("SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ? AND Reponse = 'O'");
        $stmt->execute([$idJa, $idRenc]);
        $idDispo = $stmt->fetchColumn();
        if ($idDispo) {
            return (int) $idDispo;
        }

        $stmtJournee = $pdo->prepare('
            SELECT DateReponse FROM disponible
            WHERE Id_JA = ? AND Id_Rencontre IS NULL AND DateCompetition = ? AND Reponse = \'O\'
            LIMIT 1
        ');
        $stmtJournee->execute([$idJa, $dateRenc]);
        $dateReponse = $stmtJournee->fetchColumn();
        if ($dateReponse === false) {
            return null;
        }

        $pdo->prepare("
            INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse)
            VALUES (?, ?, ?, 'O', ?)
        ")->execute([$idJa, $idRenc, $dateRenc, $dateReponse]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Affecte (crée ou remplace) la nomination d'une rencontre. Une rencontre n'a
     * qu'un seul JA nominé (uq_nomination_rencontre) :
     *   - Si aucune nomination n'existe encore → création.
     *   - Si une nomination existe pour le même JA (même Id_Disponible) → simple
     *     rafraîchissement (les frais déjà saisis sont conservés).
     *   - Si une nomination existe pour un AUTRE JA → remplacement, et les frais/
     *     rapports de l'ancien JA sont réinitialisés (ils ne concernent pas le nouveau).
     */
    private function affecterNomination(\PDO $pdo, int $idRenc, int $idDispo): void
    {
        $stmt = $pdo->prepare('SELECT Id_Nomination, Id_Disponible FROM nomination WHERE Id_Rencontre = ?');
        $stmt->execute([$idRenc]);
        $existant = $stmt->fetch();

        if (!$existant) {
            $pdo->prepare('
                INSERT INTO nomination (Id_Rencontre, Id_Disponible, DateNomination, Valide, EmailEnvoye)
                VALUES (?, ?, CURDATE(), 0, 0)
            ')->execute([$idRenc, $idDispo]);

            return;
        }

        if ((int) $existant['Id_Disponible'] === $idDispo) {
            $pdo->prepare('
                UPDATE nomination SET DateNomination = CURDATE(), Valide = 0, EmailEnvoye = 0
                WHERE Id_Nomination = ?
            ')->execute([$existant['Id_Nomination']]);

            return;
        }

        $pdo->prepare('
            UPDATE nomination SET
                Id_Disponible = ?, DateNomination = CURDATE(), Valide = 0, EmailEnvoye = 0,
                Peage = 0, Kilometre = 0, RapportAccueil = NULL, RapportEquipements = NULL, DateSaisie = NULL
            WHERE Id_Nomination = ?
        ')->execute([$idDispo, $existant['Id_Nomination']]);
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
        ];

        return view('nomination_index', $data);
    }

    public function journees(): ResponseInterface
    {
        return $this->tryJson(function () {
            $deptsAutorises = $this->deptsAutorises();
            if (!$deptsAutorises) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }

            $pdo    = getPDO();
            $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

            $stmt = $pdo->prepare("
                SELECT DISTINCT r.Journee, r.Date
                FROM rencontre r
                JOIN equipe eq ON eq.Id_Equipe = r.Id_EquipeDom
                JOIN division dv ON dv.Division = eq.Division
                WHERE SUBSTRING(eq.Id_Club, 3, 2) IN ($deptPh)
                  AND r.Date >= CURDATE()
                ORDER BY r.Journee, r.Date
            ");
            $stmt->execute($deptsAutorises);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    public function rencontresJournee(): ResponseInterface
    {
        return $this->tryJson(function () {
            $journeeRaw = $this->request->getGet('journee');
            $date       = trim($this->request->getGet('date') ?? '');
            if ($journeeRaw === null || $journeeRaw === '' || $date === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres manquants']);
            }
            $journee = (int) $journeeRaw;

            $deptsAutorises = $this->deptsAutorises();
            if (!$deptsAutorises) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }

            $pdo    = getPDO();
            $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

            $stmt = $pdo->prepare("
                SELECT
                    r.Id_Rencontre,
                    r.Journee,
                    r.Date,
                    r.Heure,
                    r.Poule,
                    dv.Division AS DivisionCode,
                    dv.Nom      AS DivisionNom,
                    dv.Color    AS DivisionColor,
                    ed.Nom       AS NomDom,
                    ed.Id_Club   AS IdClubDom,
                    ed.SouhaitJA AS SouhaitJADom,
                    cl.CorEmail  AS CorEmailDom,
                    ee.Nom       AS NomExt,
                    s_c.Cp    AS CpSalle,
                    s_c.Ville AS VilleSalle,
                    s_c.Nom    AS NomSalle,
                    -- Coordonnées du lieu : salle propre à la rencontre si renseignée,
                    -- sinon salle principale du club recevant (r.id_Salle est NULL
                    -- pour la majorité des rencontres, comme l'adresse affichée qui
                    -- vient déjà de s_c) — sans ce repli, aucune distance / km JA.
                    COALESCE(lp_r.Latitude,  lp_c.Latitude)  AS VenueLat,
                    COALESCE(lp_r.Longitude, lp_c.Longitude) AS VenueLon,
                    d_n.Id_JA    AS IdJaAffecte,
                    CONCAT(ja_n.Prenom, ' ', ja_n.Nom) AS NomJaAffecte,
                    n.Valide,
                    n.EmailEnvoye
                FROM rencontre r
                JOIN  equipe   ed   ON ed.Id_Equipe    = r.Id_EquipeDom
                JOIN  division dv   ON dv.Division  = ed.Division
                LEFT JOIN equipe ee ON ee.Id_Equipe    = r.Id_EquipeExt
                LEFT JOIN salle   s_r  ON s_r.Id_Salle   = r.id_Salle
                LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
                LEFT JOIN salle   s_c  ON s_c.Id_Club     = ed.Id_Club AND s_c.EstPrincipale = 1
                LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
                LEFT JOIN club    cl   ON cl.Id_Club      = ed.Id_Club
                LEFT JOIN nomination n  ON n.Id_Rencontre  = r.Id_Rencontre
                LEFT JOIN disponible d_n ON d_n.Id_Disponible = n.Id_Disponible
                LEFT JOIN ja ja_n       ON ja_n.Id_JA       = d_n.Id_JA
                WHERE r.Date = ?
                  AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
                ORDER BY dv.Ord, r.Poule, r.Id_Rencontre
            ");
            $stmt->execute(array_merge([$date], $deptsAutorises));

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    public function candidatsJournee(): ResponseInterface
    {
        return $this->tryJson(function () {
            $date = trim($this->request->getGet('date') ?? '');
            if (!$date) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Date manquante']);
            }

            $deptsAutorises = $this->deptsAutorises();
            if (!$deptsAutorises) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }

            $pdo     = getPDO();
            $jaCols  = array_column($pdo->query('DESCRIBE ja')->fetchAll(), 'Field');
            $hasNote = in_array('Note', $jaCols);
            $noteExpr = $hasNote ? 'ja.Note' : 'NULL';
            $deptPh   = implode(',', array_fill(0, count($deptsAutorises), '?'));

            $stmt = $pdo->prepare("
                SELECT
                    ja.Id_JA,
                    ja.Nom,
                    ja.Prenom,
                    ja.Grade,
                    COALESCE(ja.Nationale, 0)        AS Nationale,
                    ja.Id_Club,
                    lp_ja.CodePostal                 AS Cp,
                    lp_ja.Nom                        AS Ville,
                    $noteExpr                        AS Note,
                    lp_ja.Latitude                   AS JaLat,
                    lp_ja.Longitude                  AS JaLon,
                    CASE WHEN dj.Id_JA IS NOT NULL THEN 1 ELSE 0 END AS DispoJournee,
                    (SELECT GROUP_CONCAT(dr2.Id_Rencontre ORDER BY dr2.Id_Rencontre)
                     FROM disponible dr2
                     WHERE dr2.Id_JA = ja.Id_JA
                       AND dr2.DateCompetition = ?
                       AND dr2.Id_Rencontre IS NOT NULL
                       AND dr2.Reponse = 'O') AS DispoRencontres,
                    COALESCE(nbnom.NbNominations, 0) AS NbNominations
                FROM ja
                LEFT JOIN laposte lp_ja ON lp_ja.Id_LaPoste = ja.Id_LaPoste
                LEFT JOIN disponible dj
                    ON  dj.Id_JA           = ja.Id_JA
                    AND dj.DateCompetition = ?
                    AND dj.Id_Rencontre    IS NULL
                    AND dj.Reponse         = 'O'
                LEFT JOIN disponible dr
                    ON  dr.Id_JA           = ja.Id_JA
                    AND dr.DateCompetition = ?
                    AND dr.Id_Rencontre    IS NOT NULL
                    AND dr.Reponse         = 'O'
                LEFT JOIN (
                    SELECT d2.Id_JA, COUNT(*) AS NbNominations
                    FROM nomination n2
                    JOIN disponible d2 ON d2.Id_Disponible = n2.Id_Disponible
                    GROUP BY d2.Id_JA
                ) nbnom ON nbnom.Id_JA = ja.Id_JA
                WHERE ja.Actif = 1
                  AND (dj.Id_JA IS NOT NULL OR dr.Id_JA IS NOT NULL)
                  AND LEFT(lp_ja.CodePostal, 2) IN ($deptPh)
                GROUP BY ja.Id_JA
                ORDER BY ja.Nom, ja.Prenom
            ");
            $stmt->execute(array_merge([$date, $date, $date], $deptsAutorises));

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    public function affecterJa(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo    = getPDO();
            $idRenc = (int) ($this->request->getPost('id_rencontre') ?? 0);
            $idJa   = (int) ($this->request->getPost('id_ja') ?? 0);
            if (!$idRenc || !$idJa) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres manquants']);
            }

            if (!$this->rencontreAutorisee($pdo, $idRenc, $this->deptsAutorises())) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre hors de votre périmètre']);
            }

            $dateRenc = $pdo->prepare('
                SELECT r.Date, ed.Id_Club
                FROM rencontre r
                JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                WHERE r.Id_Rencontre = ?
            ');
            $dateRenc->execute([$idRenc]);
            $ri = $dateRenc->fetch();
            if (!$ri) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre introuvable']);
            }

            // Règle : au maximum 2 nominations par JA sur une même journée. Le
            // nominateur décide lui-même de la 2ᵉ (aucune affectation automatique).
            $checkDate = $pdo->prepare('
                SELECT COUNT(*) FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre  r2 ON r2.Id_Rencontre = n.Id_Rencontre
                WHERE d.Id_JA = ? AND n.Id_Rencontre != ? AND r2.Date = ?
            ');
            $checkDate->execute([$idJa, $idRenc, $ri['Date']]);
            if ((int) $checkDate->fetchColumn() >= 2) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Ce JA a déjà 2 nominations ce jour-là (maximum).']);
            }

            // Règle : pour être nominé, un JA doit être disponible
            $idDispo = $this->resoudreDisponible($pdo, $idJa, $idRenc, $ri['Date']);
            if (!$idDispo) {
                return $this->response->setJSON(['ok' => false, 'err' => "Ce JA n'est pas disponible pour cette rencontre"]);
            }

            $this->affecterNomination($pdo, $idRenc, $idDispo);

            $jaInfo = $pdo->prepare('SELECT Nom, Prenom, Grade, Id_Club FROM ja WHERE Id_JA = ?');
            $jaInfo->execute([$idJa]);
            $ja = $jaInfo->fetch();

            return $this->response->setJSON(['ok' => true, 'ja' => $ja]);
        });
    }

    public function retirerJa(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo    = getPDO();
            $idRenc = (int) ($this->request->getPost('id_rencontre') ?? 0);
            if (!$idRenc) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre manquante']);
            }
            if (!$this->rencontreAutorisee($pdo, $idRenc, $this->deptsAutorises())) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre hors de votre périmètre']);
            }
            $pdo->prepare('DELETE FROM nomination WHERE Id_Rencontre = ?')->execute([$idRenc]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function validerNominations(): ResponseInterface
    {

        return $this->tryJson(function () {
            $journeeRaw = $this->request->getPost('journee');
            $date       = trim($this->request->getPost('date') ?? '');
            if ($journeeRaw === null || $journeeRaw === '' || $date === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres manquants']);
            }
            $journee = (int) $journeeRaw;

            $deptsAutorises = $this->deptsAutorises();
            if (!$deptsAutorises) {
                return $this->response->setJSON(['ok' => true, 'affected' => 0]);
            }
            $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

            $pdo  = getPDO();
            $stmt = $pdo->prepare("
                UPDATE nomination n
                JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                JOIN equipe ed   ON ed.Id_Equipe    = r.Id_EquipeDom
                SET n.Valide = 1
                WHERE r.Journee = ? AND r.Date = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
            ");
            $stmt->execute(array_merge([$journee, $date], $deptsAutorises));

            return $this->response->setJSON(['ok' => true, 'affected' => $stmt->rowCount()]);
        });
    }

    public function envoyerConvocations(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo        = getPDO();
            $journeeRaw = $this->request->getPost('journee');
            $date       = trim($this->request->getPost('date') ?? '');
            if ($journeeRaw === null || $journeeRaw === '' || $date === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres manquants']);
            }
            $journee = (int) $journeeRaw;

            // Sélection des rencontres dont la convocation doit être envoyée
            // (cases cochées côté E022) — obligatoire, pour ne jamais renvoyer
            // toute la journée par erreur.
            $idsRencontre = array_values(array_unique(array_filter(
                array_map('intval', json_decode($this->request->getPost('ids') ?? '[]', true) ?: [])
            )));
            if (!$idsRencontre) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Aucune convocation sélectionnée']);
            }

            $deptsAutorises = $this->deptsAutorises();
            if (!$deptsAutorises) {
                return $this->response->setJSON(['ok' => true, 'envoyes' => 0, 'erreurs' => [], 'liens' => []]);
            }
            $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

            // Récupérer les nominations + email JA
            $placeholders = implode(',', array_fill(0, count($idsRencontre), '?'));
            $stmt = $pdo->prepare("
                SELECT n.Id_Nomination, n.Id_Rencontre, ja.Id_JA, ja.Nom, ja.Prenom, ja.Email,
                       ed.Nom AS NomDom, ee.Nom AS NomExt,
                       r.Date, r.Heure, r.Journee, r.Poule, ed.Division, RIGHT(ed.Division, 1) AS SexeCode
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre r  ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN ja           ON ja.Id_JA         = d.Id_JA
                JOIN equipe  ed   ON ed.Id_Equipe     = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                WHERE r.Journee = ? AND r.Date = ?
                  AND n.Valide = 1
                  AND r.Id_Rencontre IN ($placeholders)
                  AND SUBSTRING(ed.Id_Club, 3, 2) IN ($deptPh)
            ");
            $stmt->execute([$journee, $date, ...$idsRencontre, ...$deptsAutorises]);
            $nominations = $stmt->fetchAll();

            $moi     = $_SESSION['utilisateur'] ?? [];
            $envoyes = 0;
            $erreurs = [];
            $liens   = [];

            foreach ($nominations as $nom) {
                $lien    = site_url('convocation-ja') . '?nomination=' . $nom['Id_Nomination']
                    . '&cnv=' . $this->obf->obfuscate((int) $nom['Id_Nomination']);
                $liens[] = [
                    'nom'       => "{$nom['Prenom']} {$nom['Nom']}",
                    'email'     => $nom['Email'] ?? '',
                    'rencontre' => "{$nom['NomDom']} vs {$nom['NomExt']}",
                    'lien'      => $lien,
                ];

                if (!empty($nom['Email'])) {
                    // Charger le template 'Convocation' depuis messagerie (personnalisé du
                    // nominateur courant si présent, sinon modèle système)
                    static $tplConv = null;
                    if ($tplConv === null) {
                        $idUtilisateurCourant = (int) ($moi['id'] ?? 0);
                        $r       = resoudreModeleMessagerie($pdo, self::ID_MESSAGE_CONVOCATION, $idUtilisateurCourant);
                        $tplConv = $r ?: ['Sujet' => 'Convocation — {DIVISION} — {DATE}', 'Message' => '', 'Cc' => 0, 'ReplyTo' => 0];
                    }

                    $marqueurs = construireMarqueursMessage($nom, $moi, [
                        'id_nomination' => $nom['Id_Nomination'],
                        'sexe_code'     => $nom['SexeCode'] ?? null,
                        'date'          => $nom['Date']     ?? null,
                        'heure'         => $nom['Heure']    ?? null,
                        'journee'       => $nom['Journee']  ?? null,
                        'poule'         => $nom['Poule']    ?? null,
                        'division'      => $nom['Division'] ?? null,
                        'dom'           => $nom['NomDom']   ?? null,
                        'ext'           => $nom['NomExt']   ?? null,
                    ]);
                    $rendu = remplacerMarqueursMessage($tplConv['Sujet'], $tplConv['Message'], $marqueurs);
                    // Alias historique : les modèles écrits avant l'ajout de {URL_CONVOCATION_JA} utilisent {LIEN_CONVOCATION}/{LIEN_LIGUE}.
                    $sujet = str_replace(['{LIEN_CONVOCATION}', '{LIEN_LIGUE}'], [$lien, getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr')], $rendu['sujet']);
                    $corps = str_replace(['{LIEN_CONVOCATION}', '{LIEN_LIGUE}'], [$lien, getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr')], $rendu['corps']);

                    if ($corps === '') {
                        $corps = "Bonjour {$nom['Prenom']},\r\n\r\nVous êtes nominé(e) pour la rencontre {$nom['NomDom']} vs {$nom['NomExt']} le {$nom['Date']}.\r\n\r\nConsultez votre convocation : $lien";
                    }

                    // Retirer les images base64 (antispam)
                    if (str_contains($corps, 'data:image/')) {
                        $corps = preg_replace('/src="data:image\/[^;]+;base64,[^"]*"/', 'src=""', $corps);
                    }

                    $modeDev = isModeDeveloppement();
                    $dest    = getEmailDestinataire($nom['Email']);
                    try {
                        $isHtml = strip_tags($corps) !== $corps;
                        $mail   = getNijacMailer();
                        $mail->isHTML($isHtml);
                        $mail->addAddress($dest, $nom['Prenom'] . ' ' . $nom['Nom']);
                        if (!empty($tplConv['Cc']) && !empty($moi['email'])) {
                            $mail->addCC(getEmailDestinataire($moi['email']), trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
                        }
                        if (!empty($tplConv['ReplyTo']) && !empty($moi['email'])) {
                            $mail->addReplyTo($moi['email'], trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
                        }
                        $mail->Subject = ($modeDev && $dest !== $nom['Email'])
                            ? "[DEV → {$nom['Email']}] $sujet" : $sujet;
                        $mail->Body = $corps;
                        if ($isHtml) {
                            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
                        }
                        $mail->send();
                        $envoyes++;
                        $pdo->prepare('UPDATE nomination SET EmailEnvoye = 1 WHERE Id_Rencontre = ?')
                            ->execute([$nom['Id_Rencontre']]);
                    } catch (\Exception $e) {
                        $erreurs[] = $nom['Email'] . ' (' . $e->getMessage() . ')';
                    }
                }
            }

            return $this->response->setJSON(['ok' => true, 'envoyes' => $envoyes, 'erreurs' => $erreurs, 'liens' => $liens]);
        });
    }

    /**
     * Charge le modèle du message n°7 (JA Club) pour édition dans le panneau
     * droit d'E022 avant l'envoi (marqueurs non substitués : ils le sont à
     * l'envoi par demanderJaClub()).
     */
    public function messageArbitreClub(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $moi    = $_SESSION['utilisateur'] ?? [];
            $idRenc = (int) $this->request->getGet('id_rencontre');
            if (!$idRenc || !$this->rencontreAutorisee($pdo, $idRenc, $this->deptsAutorises())) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre non autorisée.']);
            }

            if (function_exists('assurerTemplateArbitreClub')) { assurerTemplateArbitreClub($pdo); } // tolère un app_config.php encore en cache opcache
            $tpl = resoudreModeleMessagerie($pdo, 7, (int) ($moi['id'] ?? 0)) ?: ['Sujet' => '', 'Message' => ''];

            $stmt = $pdo->prepare(
                'SELECT cl.CorNom, cl.CorEmail
                 FROM rencontre r JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                 LEFT JOIN club cl ON cl.Id_Club = ed.Id_Club WHERE r.Id_Rencontre = ?'
            );
            $stmt->execute([$idRenc]);
            $c = $stmt->fetch() ?: [];

            return $this->response->setJSON([
                'ok'         => true,
                'sujet'      => $tpl['Sujet'],
                'message'    => $tpl['Message'],
                'corr_nom'   => $c['CorNom'] ?? '',
                'corr_email' => $c['CorEmail'] ?? '',
            ]);
        });
    }

    /**
     * « Envoyer la demande au club » (panneau E022) : envoie au correspondant du
     * club recevant le lien vers la page publique E045 (message système n°7,
     * éventuellement édité dans le panneau), pour qu'il désigne lui-même le
     * juge-arbitre. Réservé aux rencontres R3M/R4M sans JA dont le club est en
     * arbitrage club.
     */
    public function demanderJaClub(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo   = getPDO();
            $moi   = $_SESSION['utilisateur'] ?? [];
            $idRenc = (int) $this->request->getPost('id_rencontre');
            if (!$idRenc || !$this->rencontreAutorisee($pdo, $idRenc, $this->deptsAutorises())) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre non autorisée.']);
            }

            $stmt = $pdo->prepare(
                "SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                        ed.Division, ed.SouhaitJA, ed.Nom AS NomDom, ev.Nom AS NomExt,
                        cl.CorNom, cl.CorEmail,
                        COALESCE(sr.Nom, sc.Nom) AS SalleNom, COALESCE(sr.Adresse, sc.Adresse) AS SalleAdresse,
                        COALESCE(sr.Cp, sc.Cp) AS SalleCp, COALESCE(sr.Ville, sc.Ville) AS SalleVille
                 FROM rencontre r
                 JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 LEFT JOIN club cl ON cl.Id_Club = ed.Id_Club
                 LEFT JOIN salle sr ON sr.Id_Salle = r.id_Salle
                 LEFT JOIN salle sc ON sc.Id_Club = ed.Id_Club AND sc.EstPrincipale = 1
                 WHERE r.Id_Rencontre = ?"
            );
            $stmt->execute([$idRenc]);
            $rc = $stmt->fetch();
            if (!$rc) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Rencontre introuvable.']);
            }
            if ($rc['SouhaitJA'] !== 'Club') {
                return $this->response->setJSON(['ok' => false, 'err' => "Cette rencontre n'est pas en arbitrage club."]);
            }
            $dejaNom = $pdo->prepare('SELECT COUNT(*) FROM nomination WHERE Id_Rencontre = ?');
            $dejaNom->execute([$idRenc]);
            if ((int) $dejaNom->fetchColumn() > 0) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Un JA est déjà désigné pour cette rencontre.']);
            }
            if (empty($rc['CorEmail'])) {
                return $this->response->setJSON(['ok' => false, 'err' => "Le club recevant n'a pas d'email de correspondant (à compléter en E008)."]);
            }

            if (function_exists('assurerTemplateArbitreClub')) { assurerTemplateArbitreClub($pdo); } // tolère un app_config.php encore en cache opcache
            $tpl = resoudreModeleMessagerie($pdo, 7, (int) ($moi['id'] ?? 0))
                ?: ['Sujet' => 'Juge-arbitre pour {DOM} / {EXT}', 'Message' => '', 'Cc' => 0, 'ReplyTo' => 0];

            // Sujet / corps éventuellement retouchés dans le panneau E022.
            $sujetEdit = trim((string) $this->request->getPost('sujet'));
            $msgEdit   = trim((string) $this->request->getPost('message'));
            if ($sujetEdit !== '') {
                $tpl['Sujet'] = $sujetEdit;
            }
            if ($msgEdit !== '') {
                $tpl['Message'] = $msgEdit;
            }

            $marqueurs = construireMarqueursMessage([], $moi, [
                'id_rencontre'  => $idRenc,
                'date'          => $rc['Date'],
                'heure'         => $rc['Heure'],
                'journee'       => $rc['Journee'],
                'poule'         => $rc['Poule'],
                'division'      => $rc['Division'],
                'dom'           => $rc['NomDom'],
                'ext'           => $rc['NomExt'],
                'salle_nom'     => $rc['SalleNom'],
                'salle_adresse' => $rc['SalleAdresse'],
                'salle_cp'      => $rc['SalleCp'],
                'salle_ville'   => $rc['SalleVille'],
                'corr_nom'      => $rc['CorNom'],
                'corr_email'    => $rc['CorEmail'],
            ]);
            $rendu = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);
            $corps = $rendu['corps'] !== '' ? $rendu['corps']
                : "Bonjour,\r\n\r\nMerci d'indiquer le juge-arbitre de la rencontre {$rc['NomDom']} / {$rc['NomExt']} du "
                  . date('d/m/Y', strtotime($rc['Date'])) . " :\r\n" . $marqueurs['{URL_ARBITRE_CLUB}'];

            $modeDev = isModeDeveloppement();
            $dest    = getEmailDestinataire($rc['CorEmail']);
            $isHtml  = strip_tags($corps) !== $corps;

            $mail = getNijacMailer();
            $mail->isHTML($isHtml);
            $mail->addAddress($dest, (string) $rc['CorNom']);
            if (!empty($tpl['ReplyTo']) && !empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
            }
            if (!empty($tpl['Cc']) && !empty($moi['email'])) {
                $mail->addCC(getEmailDestinataire($moi['email']), trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
            }
            $mail->Subject = ($modeDev && $dest !== $rc['CorEmail']) ? "[DEV → {$rc['CorEmail']}] {$rendu['sujet']}" : $rendu['sujet'];
            $mail->Body    = $corps;
            if ($isHtml) {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
            }
            $mail->send();

            return $this->response->setJSON([
                'ok'  => true,
                'msg' => 'Demande envoyée à ' . ($rc['CorNom'] ?: $rc['CorEmail']) . '.',
            ]);
        });
    }
}
