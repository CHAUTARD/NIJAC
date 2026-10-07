<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Nomination des Juges-Arbitres (EN14), portage CI4 de
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

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
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
            error_log('[NIJAC] EN14 : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'err' => $e->getMessage()]);
        }
    }

    /**
     * URL publique EN21 d'une nomination (forme "chemin", jeton cnv avec pepper) —
     * même lien que celui des convocations envoyées et de {URL_CONVOCATION_JA}.
     */
    private function urlConvocation(int $idNomination): string
    {
        return site_url('convocation-ja/' . $idNomination . '/' . $this->obf->obfuscate($idNomination));
    }

    private function deptsAutorises(): array
    {
        return getDepartementsAutorises($_SESSION['utilisateur']['id_departement'] ?? null);
    }

    /**
     * Vérifie que la rencontre $idRenc appartient à un club dont le
     * département fait partie de $deptsAutorises — mêmes règles que
     * journees()/rencontresJournee(), appliquées ici aux actions d'écriture
     * (affecterJa/retirerJa/envoyerConvocations), qui ne
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
     * Périmètre d'un JA (alias ja / lp_ja) pour le nominateur : domicile (code postal) ou
     * CodeDept dans $depts (« in »), ou JA d'un autre département acceptant d'arbitrer dans
     * l'un de ces départements (« arb », EN22/EN11). Partagé par candidatsJournee() et la
     * revérification de nomination (verifierEtNommer()) pour que les deux ne divergent pas.
     */
    private function sqlPerimetreJa(array $depts): array
    {
        $ph = implode(',', array_fill(0, count($depts), '?'));

        return [
            'in'        => "LEFT(lp_ja.CodePostal, 2) IN ($ph) OR ja.CodeDept IN ($ph)",
            'inParams'  => array_merge($depts, $depts),
            'arb'       => 'ja.ArbitreAutresDepts = 1 AND ('
                . implode(' OR ', array_fill(0, count($depts), 'FIND_IN_SET(?, ja.DeptsArbitrage)')) . ')',
            'arbParams' => $depts,
        ];
    }

    /** EXISTS : le JA $ja a une réponse $rep sur la rencontre $renc ou pour la journée $date (expressions SQL). */
    private function sqlReponseDispo(string $rep, string $ja, string $renc, string $date): string
    {
        return "EXISTS (SELECT 1 FROM disponible dx WHERE dx.Id_JA = $ja AND dx.Reponse = '$rep'
                AND (dx.Id_Rencontre = $renc OR (dx.Id_Rencontre IS NULL AND dx.DateCompetition = $date)))";
    }

    /**
     * Règle unique de disponibilité EN14 (liste des candidats ET nomination) : une réponse 'O'
     * sur la rencontre ou la journée, et aucune réponse 'N' ni sur la rencontre ni sur la journée.
     * Sans réponse / 'P' seul → non disponible.
     */
    private function sqlDispoRencontre(string $ja, string $renc, string $date): string
    {
        return '(' . $this->sqlReponseDispo('O', $ja, $renc, $date)
            . ' AND NOT ' . $this->sqlReponseDispo('N', $ja, $renc, $date) . ')';
    }

    /**
     * Nomination de $idJa sur $idRenc avec revérification complète en base à l'instant de
     * l'écriture (la liste des candidats a pu vieillir) : transaction + verrous.
     * $idJaAttendu = JA que le navigateur affichait nommé (0 = aucun, null = pas de contrôle).
     */
    private function nommer(\PDO $pdo, int $idRenc, int $idJa, ?int $idJaAttendu, array $depts): array
    {
        if (!$this->rencontreAutorisee($pdo, $idRenc, $depts)) {
            return ['ok' => false, 'err' => 'Rencontre hors de votre périmètre'];
        }

        $pdo->beginTransaction();
        try {
            $err = $this->verifierEtNommer($pdo, $idRenc, $idJa, $idJaAttendu, $depts);
            $err === null ? $pdo->commit() : $pdo->rollBack();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $err === null ? ['ok' => true] : ['ok' => false, 'err' => $err, 'rafraichir' => true];
    }

    /** Contrôles + écriture (transaction ouverte par nommer()). Renvoie null si nommé, sinon le motif du refus. */
    private function verifierEtNommer(\PDO $pdo, int $idRenc, int $idJa, ?int $idJaAttendu, array $depts): ?string
    {
        // Verrous AVANT toute lecture non verrouillante (qui fixerait l'instantané InnoDB) :
        // la rencontre sérialise deux nominateurs sur la même rencontre, le JA deux nominations
        // du même JA (limite journalière), ses lignes disponible une réponse EN22 concurrente.
        $st = $pdo->prepare('SELECT Date FROM rencontre WHERE Id_Rencontre = ? FOR UPDATE');
        $st->execute([$idRenc]);
        $date = $st->fetchColumn();
        if ($date === false) {
            return 'Rencontre introuvable';
        }
        $st = $pdo->prepare('SELECT JA1 FROM ja WHERE Id_JA = ? FOR UPDATE');
        $st->execute([$idJa]);
        $ja1 = $st->fetchColumn();
        $pdo->prepare('
            SELECT Id_Disponible FROM disponible
            WHERE Id_JA = ? AND (Id_Rencontre = ? OR (Id_Rencontre IS NULL AND DateCompetition = ?))
            FOR UPDATE
        ')->execute([$idJa, $idRenc, $date]);

        // Nomination concurrente : un autre JA nommé depuis le chargement de la page. La
        // réaffectation voulue (clic « Affecter » alors que la page montrait déjà ce JA) passe.
        $st = $pdo->prepare('
            SELECT d.Id_JA FROM nomination n JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
            WHERE n.Id_Rencontre = ? FOR UPDATE
        ');
        $st->execute([$idRenc]);
        $jaNomme = (int) $st->fetchColumn();
        if ($idJaAttendu !== null && $jaNomme && $jaNomme !== $idJa && $jaNomme !== $idJaAttendu) {
            return 'Un autre JA vient d\'être nommé sur cette rencontre.';
        }

        if ((int) $ja1 !== 1) {
            return 'Ce JA n\'est plus actif.';
        }

        $p  = $this->sqlPerimetreJa($depts);
        $st = $pdo->prepare("
            SELECT CASE WHEN ({$p['in']}) OR ({$p['arb']}) THEN 1 ELSE 0 END
            FROM ja LEFT JOIN laposte lp_ja ON lp_ja.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Id_JA = ?
        ");
        $st->execute(array_merge($p['inParams'], $p['arbParams'], [$idJa]));
        if (!(int) $st->fetchColumn()) {
            return 'Ce JA n\'est plus dans votre périmètre (département non autorisé).';
        }

        $st = $pdo->prepare('SELECT ' . $this->sqlReponseDispo('N', '?', '?', '?') . ', ' . $this->sqlDispoRencontre('?', '?', '?'));
        $st->execute([$idJa, $idRenc, $date, $idJa, $idRenc, $date, $idJa, $idRenc, $date]);
        [$aNon, $dispo] = array_map('intval', $st->fetch(\PDO::FETCH_NUM));
        if (!$dispo) {
            return $aNon
                ? 'Ce JA n\'est plus disponible pour cette rencontre/journée (il a répondu « non »).'
                : 'Ce JA n\'est pas disponible pour cette rencontre (aucune réponse « oui »).';
        }

        // Règle : au maximum 2 nominations par JA sur une même journée, et uniquement sur
        // des rencontres du même club recevant (même lieu). Le nominateur décide lui-même
        // de la 2ᵉ (aucune affectation automatique).
        $st = $pdo->prepare('
            SELECT COUNT(*) AS nb, COALESCE(SUM(ed2.Id_Club <> ed.Id_Club), 0) AS autres_clubs
            FROM nomination n
            JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
            JOIN rencontre  r2 ON r2.Id_Rencontre = n.Id_Rencontre
            JOIN equipe    ed2 ON ed2.Id_Equipe   = r2.Id_EquipeDom
            JOIN rencontre  r  ON r.Id_Rencontre  = ?
            JOIN equipe     ed ON ed.Id_Equipe    = r.Id_EquipeDom
            WHERE d.Id_JA = ? AND n.Id_Rencontre != r.Id_Rencontre AND r2.Date = r.Date
        ');
        $st->execute([$idRenc, $idJa]);
        $deja = $st->fetch();
        if ((int) $deja['nb'] >= 2) {
            return 'Ce JA a déjà 2 nominations ce jour.';
        }
        if ((int) $deja['autres_clubs'] > 0) {
            return 'Ce JA est déjà nommé ce jour-là sur une rencontre d\'un autre club.';
        }

        $idDispo = $this->resoudreDisponible($pdo, $idJa, $idRenc, $date);
        if (!$idDispo) {   // garde-fou : sqlDispoRencontre() garantit une réponse 'O'
            return "Ce JA n'est pas disponible pour cette rencontre";
        }
        $this->affecterNomination($pdo, $idRenc, $idDispo);

        return null;
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
                VALUES (?, ?, CURDATE(), 1, 0)
            ')->execute([$idRenc, $idDispo]);

            return;
        }

        if ((int) $existant['Id_Disponible'] === $idDispo) {
            $pdo->prepare('
                UPDATE nomination SET DateNomination = CURDATE(), Valide = 1, EmailEnvoye = 0
                WHERE Id_Nomination = ?
            ')->execute([$existant['Id_Nomination']]);

            return;
        }

        // Changement de JA : l'accusé de réception de l'ancien JA n'est pas hérité (colonne EA98).
        $razAccuse = nominationAAccuseReception($pdo) ? ', AccuseReception = NULL' : '';
        // Modification manuelle : elle ne vient plus du fichier FFTT 131 (colonne EA98).
        $razAccuse .= nominationAF131($pdo) ? ', F131 = 0' : '';
        $pdo->prepare("
            UPDATE nomination SET
                Id_Disponible = ?, DateNomination = CURDATE(), Valide = 1, EmailEnvoye = 0,
                Peage = 0, Kilometre = 0, RapportAccueil = NULL, RapportEquipements = NULL, DateSaisie = NULL$razAccuse
            WHERE Id_Nomination = ?
        ")->execute([$idDispo, $existant['Id_Nomination']]);
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        // code => nom des départements de la région : libellé des cases « autre dépt »
        // du filtre des candidats JA (voir majFiltreHorsDept() dans la vue).
        $deptNoms = array_column(getDeptActifs(), 'nom', 'CodeDept')
                  + array_column(getDepartementsLimitrophes(), 'nom', 'CodeDept');

        $data = [
            'nomComplet'   => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement'  => $u['id_departement'] ?? '',
            'changeLogin'  => !empty($u['change_login']),
            'isAdmin'      => !empty($u['is_admin']),
            'deptNoms'     => $deptNoms,
            'deptsAutorises' => $this->deptsAutorises(),   // en-tête de la feuille de pointage PDF
            'nbCandidats'  => max(1, (int) getConfig('nomination_nb_candidats', '15')),
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

            $deptsAutorises = $this->deptsAutorises();
            if (!$deptsAutorises) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }

            $pdo    = getPDO();
            $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));
            // Colonne ajoutée par EA98 : EN14 reste fonctionnel avant la migration.
            $colAccuse = nominationAAccuseReception($pdo) ? 'n.AccuseReception' : 'NULL AS AccuseReception';

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
                    dv.Ord      AS DivisionOrd,
                    ed.Nom       AS NomDom,
                    ed.Id_Club   AS IdClubDom,
                    cl.Nom       AS NomClubDom,
                    CASE WHEN r.ArbitrageCRA = 1 THEN 'CRA' ELSE 'Club' END AS SouhaitJADom,
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
                    -- Feuille de pointage PDF (tri NOM/Prénom + coordonnées du JA)
                    ja_n.Nom       AS NomJa,
                    ja_n.Prenom    AS PrenomJa,
                    ja_n.Telephone AS TelJa,
                    ja_n.Email     AS EmailJa,
                    n.Valide,
                    n.EmailEnvoye,
                    $colAccuse
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

            $pdo    = getPDO();
            $deptPh = implode(',', array_fill(0, count($deptsAutorises), '?'));

            // Périmètre JA partagé avec la revérification de nomination (sqlPerimetreJa()) :
            // « in » sert aussi au flag HorsDept (case à cocher « autres départements »).
            $p         = $this->sqlPerimetreJa($deptsAutorises);
            $inDeptSql = $p['in'];
            // Rencontres du jour pour lesquelles le JA est disponible selon la règle unique
            // sqlDispoRencontre() — la même que celle revérifiée à la nomination.
            $dispoSql  = $this->sqlDispoRencontre('ja.Id_JA', 'rok.Id_Rencontre', 'rok.Date');

            $stmt = $pdo->prepare("
                SELECT
                    ja.Id_JA,
                    ja.Nom,
                    ja.Prenom,
                    ja.Telephone,                    -- feuille de pointage PDF (JA nommé en cours de session)
                    ja.Email,
                    ja.Grade,
                    COALESCE(ja.Nationale, 0)        AS Nationale,
                    ja.Id_Club,
                    ja.CodeDept                      AS CodeDept,
                    lp_ja.CodePostal                 AS Cp,
                    lp_ja.Nom                        AS Ville,
                    ja.Note                          AS Note,
                    lp_ja.Latitude                   AS JaLat,
                    lp_ja.Longitude                  AS JaLon,
                    CASE WHEN dj.Id_JA IS NOT NULL THEN 1 ELSE 0 END AS DispoJournee,
                    CASE WHEN $inDeptSql THEN 0 ELSE 1 END AS HorsDept,
                    (SELECT GROUP_CONCAT(dr2.Id_Rencontre ORDER BY dr2.Id_Rencontre)
                     FROM disponible dr2
                     WHERE dr2.Id_JA = ja.Id_JA
                       AND dr2.DateCompetition = ?
                       AND dr2.Id_Rencontre IS NOT NULL
                       AND dr2.Reponse = 'O') AS DispoRencontres,
                    (SELECT GROUP_CONCAT(rok.Id_Rencontre ORDER BY rok.Id_Rencontre)
                     FROM rencontre rok
                     JOIN equipe eok ON eok.Id_Equipe = rok.Id_EquipeDom
                     WHERE rok.Date = ? AND SUBSTRING(eok.Id_Club, 3, 2) IN ($deptPh)   -- rencontres de rencontresJournee()
                       AND $dispoSql) AS RencontresOk,
                    COALESCE(nbnom.NbNominations, 0) AS NbNominations
                FROM ja
                LEFT JOIN laposte lp_ja ON lp_ja.Id_LaPoste = ja.Id_LaPoste
                LEFT JOIN disponible dj
                    ON  dj.Id_JA           = ja.Id_JA
                    AND dj.DateCompetition = ?
                    AND dj.Id_Rencontre    IS NULL
                    AND dj.Reponse         = 'O'
                LEFT JOIN (
                    SELECT d2.Id_JA, COUNT(*) AS NbNominations
                    FROM nomination n2
                    JOIN disponible d2 ON d2.Id_Disponible = n2.Id_Disponible
                    GROUP BY d2.Id_JA
                ) nbnom ON nbnom.Id_JA = ja.Id_JA
                WHERE ja.JA1 = 1
                  AND (($inDeptSql) OR ({$p['arb']}))
                GROUP BY ja.Id_JA
                HAVING RencontresOk IS NOT NULL
                ORDER BY ja.Nom, ja.Prenom
            ");
            $stmt->execute(array_merge(
                $p['inParams'],       // CASE ... HorsDept
                [$date, $date],       // DispoRencontres, RencontresOk
                $deptsAutorises,      // RencontresOk : périmètre
                [$date],              // dj
                $p['inParams'],       // WHERE in
                $p['arbParams']       // WHERE arb (FIND_IN_SET)
            ));

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

            // JA que la page affichait nommé sur la rencontre ('' = aucun) ; absent = ancien client, pas de contrôle.
            $attendu = $this->request->getPost('id_ja_actuel');
            $res     = $this->nommer($pdo, $idRenc, $idJa, $attendu === null ? null : (int) $attendu, $this->deptsAutorises());
            if (!$res['ok']) {
                return $this->response->setJSON($res);
            }

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
            // (cases cochées côté EN14) — obligatoire, pour ne jamais renvoyer
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
                SELECT n.Id_Nomination, n.Id_Rencontre, ja.Id_JA, ja.Nom, ja.Prenom, ja.Email, ja.Telephone,
                       ed.Nom AS NomDom, ee.Nom AS NomExt, cl.Nom AS NomClub,
                       r.Date, r.Heure, r.Journee, r.Poule, ed.Division, RIGHT(ed.Division, 1) AS SexeCode,
                       -- Salle propre à la rencontre si renseignée, sinon salle principale du club recevant
                       -- (r.id_Salle est NULL pour la majorité des rencontres).
                       COALESCE(s_r.Nom, s_c.Nom)                 AS SalleNom,
                       COALESCE(s_r.Adresse, s_c.Adresse)         AS SalleAdresse,
                       COALESCE(lp_r.CodePostal, lp_c.CodePostal) AS SalleCP,
                       COALESCE(lp_r.Nom, lp_c.Nom)               AS SalleVille,
                       cl.CorNom AS CorrNom, cl.CorEmail AS CorrEmail, cl.CorTelephone AS CorrTel,
                       -- Destinataires de la copie sans lien : correspondant + référent des 2 clubs.
                       cl.RefNom AS DomRefNom, cl.RefMail AS DomRefMail,
                       cx.CorNom AS ExtCorNom, cx.CorEmail AS ExtCorEmail, cx.RefNom AS ExtRefNom, cx.RefMail AS ExtRefMail
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre r  ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN ja           ON ja.Id_JA         = d.Id_JA
                JOIN equipe  ed   ON ed.Id_Equipe     = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                LEFT JOIN Club cl ON cl.Id_Club       = ed.Id_Club
                LEFT JOIN Club cx ON cx.Id_Club       = ee.Id_Club
                LEFT JOIN salle   s_r  ON s_r.Id_Salle    = r.id_Salle
                LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
                LEFT JOIN salle   s_c  ON s_c.Id_Club     = ed.Id_Club AND s_c.EstPrincipale = 1
                LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
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
            // Copie sans lien aux correspondants/référents des 2 clubs (case cochée dans la confirmation EN14).
            $copieClubs = $this->request->getPost('copie_clubs') === '1';
            $copies     = ['envoyees' => 0, 'echecs' => 0, 'sans_destinataire' => 0];

            foreach ($nominations as $nom) {
                $lien    = $this->urlConvocation((int) $nom['Id_Nomination']);
                $liens[] = [
                    'nom'       => "{$nom['Prenom']} {$nom['Nom']}",
                    'email'     => $nom['Email'] ?? '',
                    'rencontre' => "{$nom['NomDom']} vs {$nom['NomExt']}",
                    'lien'      => $lien,
                    'copie'     => $copieClubs ? 'Copie clubs : non envoyée (JA sans email)' : '',
                ];
                $iLien = array_key_last($liens);

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
                        'nom_club'      => $nom['NomClub']  ?? null,
                        'salle_nom'     => $nom['SalleNom']     ?? null,
                        'salle_adresse' => $nom['SalleAdresse'] ?? null,
                        'salle_cp'      => $nom['SalleCP']      ?? null,
                        'salle_ville'   => $nom['SalleVille']   ?? null,
                        'corr_nom'      => $nom['CorrNom']      ?? null,
                        'corr_email'    => $nom['CorrEmail']    ?? null,
                        'corr_tel'      => $nom['CorrTel']      ?? null,
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
                        if ($copieClubs) {
                            $liens[$iLien]['copie'] = 'Copie clubs : non envoyée (échec de la convocation)';
                        }
                        continue;
                    }

                    // Après l'envoi réussi au JA uniquement ; jamais bloquant (EmailEnvoye déjà à 1).
                    if ($copieClubs) {
                        [$statut, $texte] = $this->envoyerCopieClubs($nom, $marqueurs, $moi);
                        $copies[$statut]++;
                        $liens[$iLien]['copie'] = 'Copie clubs : ' . $texte;
                    }
                }
            }

            return $this->response->setJSON(['ok' => true, 'envoyes' => $envoyes, 'erreurs' => $erreurs, 'liens' => $liens, 'copies' => $copieClubs ? $copies : null]);
        });
    }

    /**
     * Copie pour information de la convocation d'une rencontre aux correspondants
     * et référents des clubs recevant et visiteur (Club.CorEmail / Club.RefMail,
     * dédoublonnés, adresse du JA exclue) — un seul email par rencontre, tous les
     * destinataires en « À ». Modèle : message système « Convocation clubs »
     * (TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS, copie perso prioritaire, sujet et corps
     * tels quels, sans lien personnel du JA) ; absent ou vide (EA98 pas chargé) :
     * modèle codé en dur modeleHtmlCopieConvocationClubs() + sujetParDefautCopieConvocationClubs(),
     * Cc = 0 / ReplyTo = 1. Reply-To du nominateur et Cc selon les flags du modèle.
     * En mode Développement, getEmailDestinataire() ramène tous les
     * destinataires sur l'adresse de test (PHPMailer dédoublonne) : un mail de
     * test par rencontre, sujet préfixé [DEV → adresses réelles].
     *
     * @return array{0: 'envoyees'|'echecs'|'sans_destinataire', 1: string} statut + texte du compte-rendu
     */
    private function envoyerCopieClubs(array $nom, array $marqueurs, array $moi): array
    {
        $dest = destinatairesCopieClubs([
            ['CorNom' => $nom['CorrNom'],   'CorEmail' => $nom['CorrEmail'],   'RefNom' => $nom['DomRefNom'], 'RefMail' => $nom['DomRefMail']],
            ['CorNom' => $nom['ExtCorNom'], 'CorEmail' => $nom['ExtCorEmail'], 'RefNom' => $nom['ExtRefNom'], 'RefMail' => $nom['ExtRefMail']],
        ], $nom['Email']);
        if (!$dest) {
            return ['sans_destinataire', 'aucun destinataire (correspondant/référent sans email valide)'];
        }

        $errRl = checkRateLimit(count($dest));
        if ($errRl !== null) {
            return ['echecs', 'non envoyée — ' . $errRl];
        }

        // Message système dédié « Convocation clubs » (version perso du nominateur prioritaire).
        $tpl = resoudreModeleMessagerieParType(getPDO(), TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS, (int) ($moi['id'] ?? 0));
        if ($tpl === null || trim((string) $tpl['Message']) === '') {
            // Avant EA98 : modèle codé en dur, mêmes Sujet/Cc/ReplyTo que l'amorçage.
            $tpl = ['Sujet' => sujetParDefautCopieConvocationClubs(), 'Message' => modeleHtmlCopieConvocationClubs(), 'Cc' => 0, 'ReplyTo' => 1];
        }
        // Tel quel : un lien personnel du JA ajouté en EA93 n'est pas filtré (avertissement à l'enregistrement).
        $marqueurs += ['{LIEN_LIGUE}' => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr')];
        $corps = strtr((string) $tpl['Message'], $marqueurs);
        $sujet = strtr((string) $tpl['Sujet'], $marqueurs); // préfixe « Copie – » inclus dans le modèle
        if (str_contains($corps, 'data:image/')) {
            $corps = preg_replace('/src="data:image\/[^;]+;base64,[^"]*"/', 'src=""', $corps);
        }

        $isHtml = strip_tags($corps) !== $corps;

        try {
            $mail = getNijacMailer();
            $mail->isHTML($isHtml);
            foreach ($dest as $adresse => $nomDest) {
                $mail->addAddress(getEmailDestinataire($adresse), $nomDest);
            }
            if (!empty($tpl['ReplyTo']) && !empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
            }
            // Cc du nominateur : seulement si le message le demande (défaut 0, déjà en Cc de la convocation du JA).
            if (!empty($tpl['Cc']) && !empty($moi['email'])) {
                $mail->addCC(getEmailDestinataire($moi['email']), trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? '')));
            }
            $mail->Subject = isModeDeveloppement() ? '[DEV → ' . implode(', ', array_keys($dest)) . "] $sujet" : $sujet;
            $mail->Body    = $corps;
            if ($isHtml) {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
            }
            $mail->send();
            enregistrerEnvois(count($dest));

            return ['envoyees', 'envoyée à ' . count($dest) . ' destinataire' . (count($dest) > 1 ? 's' : '') . ' (' . implode(', ', array_keys($dest)) . ')'];
        } catch (\Exception $e) {
            error_log('[NIJAC] EN14 copie clubs : ' . $e->getMessage());

            return ['echecs', 'échec (' . $e->getMessage() . ')'];
        }
    }

    /**
     * Charge le modèle du message n°7 (JA Club) pour édition dans le panneau
     * droit d'EN14 avant l'envoi (marqueurs non substitués : ils le sont à
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
     * « Envoyer la demande au club » (panneau EN14) : envoie au correspondant du
     * club recevant le lien vers la page publique EN25 (message système n°7,
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

            // Cœur d'envoi partagé avec EN28 (« Relancer le club ») : config/app_config.php.
            $res = envoyerDemandeJaClub(
                $pdo, $idRenc, $moi,
                trim((string) $this->request->getPost('sujet')),
                trim((string) $this->request->getPost('message'))
            );

            return $this->response->setJSON($res['ok']
                ? ['ok' => true, 'msg' => $res['msg']]
                : ['ok' => false, 'err' => $res['msg']]);
        });
    }
}
