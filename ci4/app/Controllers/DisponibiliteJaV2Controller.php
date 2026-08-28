<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Copie de EN22 (Déclaration de disponibilité JA) pour utilisation
 * ultérieure — code EN24, nom provisoire. Duplication à l'identique de
 * DisponibiliteJaController, à adapter/renommer quand son usage sera défini.
 *
 * Voir DisponibiliteJaController pour la documentation de fonctionnement.
 */
class DisponibiliteJaV2Controller extends BaseController
{
    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);

        try {
            getPDO()->exec('ALTER TABLE disponible ADD UNIQUE KEY uq_dispo (Id_JA, Id_Rencontre)');
        } catch (\PDOException $ignored) {
            // index déjà présent
        }
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            return $this->response->setJSON(['ok' => false, 'err' => 'BDD : ' . $e->getMessage()]);
        }
    }

    /**
     * Résout l'Id_JA depuis ?ja=TOKEN (obfusqué) ou ?id_ja=N (clair), comme
     * le fait le legacy en réécrivant $_GET['id_ja'] dès le décodage du token.
     */
    private function resolveIdJa(): int
    {
        $tokenGet = trim($this->request->getGet('ja') ?? $this->request->getPost('ja') ?? '');
        if ($tokenGet !== '') {
            $decoded = $this->obf->deobfuscate($tokenGet);
            if ($decoded > 0) {
                return $decoded;
            }
        }

        return (int) ($this->request->getGet('id_ja') ?? $this->request->getPost('id_ja') ?? 0);
    }

    /**
     * Comme resolveIdJa(), mais pour les actions sensibles (Note interne,
     * Défiscalisation, saisie de disponibilité) : un ?id_ja=N en clair sans
     * token n'est accepté que si l'appelant a une session authentifiée
     * (usage documenté : lien utilisé directement depuis EN13 par un
     * nominateur déjà connecté). Sans token valide ni session, retourne 0 au
     * lieu de faire confiance à n'importe quel entier deviné par un tiers.
     */
    private function resolveIdJaAutorise(): int
    {
        $tokenGet = trim($this->request->getGet('ja') ?? $this->request->getPost('ja') ?? '');
        if ($tokenGet !== '') {
            $decoded = $this->obf->deobfuscate($tokenGet);
            if ($decoded > 0) {
                return $decoded;
            }
        }

        $idClair = (int) ($this->request->getGet('id_ja') ?? $this->request->getPost('id_ja') ?? 0);
        if ($idClair > 0 && $this->sessionAuthentifiee()) {
            return $idClair;
        }

        return 0;
    }

    private function sessionAuthentifiee(): bool
    {
        demarrerSessionNijac();
        $ok = !empty($_SESSION['utilisateur']['role'] ?? null);
        session_write_close();

        return $ok;
    }

    public function index()
    {
        // Page publique, mais accessible aussi depuis un onglet ouvert par un
        // nominateur déjà connecté (EN13, target="_blank" — même session
        // navigateur) : on lit la session si elle existe, sans l'exiger.
        demarrerSessionNijac();
        $u = $_SESSION['utilisateur'] ?? [];
        session_write_close();
        // Empêche CodeIgniter\CodeIgniter::storePreviousURL() (appelé pour toute
        // réponse HTML non-AJAX, donc à chaque F5) de démarrer le service Session
        // de CI4 — voir Auth.php pour le détail du conflit avec la session native.
        unset($_SESSION);

        $idJa = $this->resolveIdJa();

        // Bouton de retour de la toolbar : vers le menu correspondant au rôle
        // réel de la session (Nominateur -> menu nominateur, Administrateur ->
        // menu administrateur), pas systématiquement admin. Absent si la page
        // est ouverte sans session (accès public par un JA).
        $tbSwitchTo = match ($u['role'] ?? '') {
            'Administrateur' => 'admin',
            'Nominateur'      => 'nominateur',
            default           => null,
        };

        return view('disponibilite_ja_v2_index', [
            'idJa'        => $idJa,
            'nomComplet'  => isset($u['prenom'], $u['nom']) ? $u['prenom'] . ' ' . $u['nom'] : '',
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'tbSwitchTo'  => $tbSwitchTo,
        ]);
    }

    public function listeJa(): ResponseInterface
    {
        return $this->tryJson(function () {
            $rows = getPDO()->query(
                'SELECT Id_JA, Nom, Prenom, Grade, Ville FROM ja WHERE Actif = 1 ORDER BY Nom, Prenom'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    public function ja(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $id     = (int) ($this->request->getGet('id') ?? 0);

            $stmt = $pdo->prepare('
                SELECT ja.Id_JA, ja.Nom, ja.Prenom, ja.Grade, ja.Defiscalisation,
                       lp.CodePostal AS Cp,
                       lp.Nom        AS Ville
                FROM ja
                LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
                WHERE ja.Id_JA = ?
            ');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $this->response->setJSON($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'err' => 'Introuvable']);
        });
    }

    public function journees(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo  = getPDO();
            $idJa = (int) ($this->request->getGet('id_ja') ?? 0);
            if (!$idJa) {
                return $this->response->setJSON(['ok' => false, 'err' => 'JA manquant']);
            }

            $haversine = '
                ROUND(6371 * ACOS(GREATEST(-1, LEAST(1,
                    COS(RADIANS(lp_ja.Latitude))  * COS(RADIANS(lp_v.Latitude))
                  * COS(RADIANS(lp_v.Longitude)   - RADIANS(lp_ja.Longitude))
                  + SIN(RADIANS(lp_ja.Latitude))  * SIN(RADIANS(lp_v.Latitude))
                ))))
            ';

            $stmt = $pdo->prepare("
                SELECT
                    j.Journee,
                    j.Date,
                    j.NbRencontres,
                    CASE
                        WHEN dj.Reponse IS NOT NULL THEN dj.Reponse
                        WHEN COALESCE(sel.Nb, 0) > 0 THEN 'P'
                        ELSE NULL
                    END                 AS Statut,
                    COALESCE(sel.Nb, 0) AS NbSelectionnes,
                    dist.MinKm,
                    dist.MaxKm
                FROM (
                    SELECT r.Journee, r.Date, COUNT(*) AS NbRencontres
                    FROM rencontre r
                    JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                    WHERE NOT EXISTS (
                          SELECT 1 FROM ja ja0
                          WHERE ja0.Id_JA = ? AND ja0.Id_Club IS NOT NULL AND ja0.Id_Club = ed.Id_Club
                      )
                    GROUP BY r.Journee, r.Date
                ) j
                LEFT JOIN disponible dj
                    ON  dj.Id_JA           = ?
                    AND dj.DateCompetition = j.Date
                    AND dj.Id_Rencontre    IS NULL
                LEFT JOIN (
                    SELECT r2.Journee, r2.Date, COUNT(*) AS Nb
                    FROM rencontre r2
                    JOIN disponible d2
                        ON  d2.Id_Rencontre = r2.Id_Rencontre
                        AND d2.Id_JA        = ?
                    GROUP BY r2.Journee, r2.Date
                ) sel ON sel.Journee = j.Journee AND sel.Date = j.Date
                LEFT JOIN (
                    SELECT r3.Journee, r3.Date,
                           MIN($haversine) AS MinKm,
                           MAX($haversine) AS MaxKm
                    FROM rencontre r3
                    JOIN  equipe  ed3 ON ed3.Id_Equipe  = r3.Id_EquipeDom
                    LEFT JOIN salle   s3  ON  s3.Id_Club    = ed3.Id_Club AND s3.EstPrincipale = 1
                    LEFT JOIN laposte lp_v ON lp_v.Id_LaPoste = s3.Id_Laposte
                    CROSS JOIN (
                        SELECT lp2.Latitude, lp2.Longitude, ja.Id_Club AS JaClub
                        FROM ja LEFT JOIN laposte lp2 ON lp2.Id_LaPoste = ja.Id_LaPoste
                        WHERE ja.Id_JA = ?
                    ) lp_ja
                    WHERE lp_v.Latitude  IS NOT NULL
                      AND lp_ja.Latitude IS NOT NULL
                      AND (lp_ja.JaClub IS NULL OR ed3.Id_Club != lp_ja.JaClub)
                    GROUP BY r3.Journee, r3.Date
                ) dist ON dist.Journee = j.Journee AND dist.Date = j.Date
                ORDER BY j.Journee, j.Date
            ");
            $stmt->execute([$idJa, $idJa, $idJa, $idJa]);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    public function rencontresJournee(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo        = getPDO();
            $idJa       = (int) ($this->request->getGet('id_ja') ?? 0);
            $journeeRaw = $this->request->getGet('journee');
            $date       = trim($this->request->getGet('date') ?? '');
            if (!$idJa || $journeeRaw === null || $journeeRaw === '' || $date === '') {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres manquants']);
            }
            $journee = (int) $journeeRaw;

            $jaRow = $pdo->prepare('SELECT LEFT(lp2.CodePostal, 2) AS Dept FROM ja LEFT JOIN laposte lp2 ON lp2.Id_LaPoste = ja.Id_LaPoste WHERE ja.Id_JA = ?');
            $jaRow->execute([$idJa]);
            $jaDept = $jaRow->fetchColumn() ?: '';

            // Règle de rattachement de département (ex: 76 inclut 27) définie dans
            // configuration.regles_departements, jamais en dur — voir CLAUDE.md.
            $depts = $jaDept ? getDepartementsAutorises($jaDept) : [];

            $deptClause = '';
            if ($depts) {
                $ph         = implode(',', array_fill(0, count($depts), '?'));
                $deptClause = "AND LEFT(COALESCE(lp_r.CodePostal, lp_c.CodePostal), 2) IN ($ph)";
            }

            $params = [$idJa, $idJa, $journee, $date];
            if ($depts) {
                $params = array_merge($params, $depts);
            }

            $stmt = $pdo->prepare("
                SELECT
                    r.Id_Rencontre,
                    r.Date,
                    r.Heure,
                    r.Poule,
                    d.Division   AS DivisionCode,
                    d.Nom        AS DivisionNom,
                    ed.Nom       AS NomDom,
                    ee.Nom       AS NomExt,
                    COALESCE(s_r.Nom,  s_c.Nom)        AS NomSalle,
                    COALESCE(lp_r.Nom, lp_c.Nom)       AS VilleSalle,
                    COALESCE(lp_r.CodePostal, lp_c.CodePostal) AS CpSalle,
                    CASE
                        WHEN lp_ja.Latitude IS NOT NULL
                         AND COALESCE(lp_r.Latitude, lp_c.Latitude) IS NOT NULL
                        THEN ROUND(6371 * ACOS(GREATEST(-1, LEAST(1,
                                COS(RADIANS(lp_ja.Latitude))
                              * COS(RADIANS(COALESCE(lp_r.Latitude,  lp_c.Latitude)))
                              * COS(RADIANS(COALESCE(lp_r.Longitude, lp_c.Longitude))
                                    - RADIANS(lp_ja.Longitude))
                              + SIN(RADIANS(lp_ja.Latitude))
                              * SIN(RADIANS(COALESCE(lp_r.Latitude,  lp_c.Latitude)))
                            ))))
                        ELSE NULL
                    END AS DistanceKm,
                    disp.Reponse AS ReponseDisp
                FROM rencontre r
                JOIN  equipe   ed   ON ed.Id_Equipe    = r.Id_EquipeDom
                JOIN  division d    ON d.Division   = ed.Division
                LEFT JOIN equipe ee ON ee.Id_Equipe    = r.Id_EquipeExt
                LEFT JOIN salle   s_r  ON s_r.Id_Salle  = r.id_Salle
                LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
                LEFT JOIN salle   s_c  ON s_c.Id_Club   = ed.Id_Club AND s_c.EstPrincipale = 1
                LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
                CROSS JOIN (
                    SELECT lp2.Latitude, lp2.Longitude, ja.Id_Club AS JaClub
                    FROM ja
                    LEFT JOIN laposte lp2 ON lp2.Id_LaPoste = ja.Id_LaPoste
                    WHERE ja.Id_JA = ?
                ) lp_ja
                LEFT JOIN disponible disp
                    ON disp.Id_Rencontre = r.Id_Rencontre AND disp.Id_JA = ?
                WHERE r.Journee = ? AND r.Date = ?
                  AND (lp_ja.JaClub IS NULL OR ed.Id_Club != lp_ja.JaClub)
                  $deptClause
                ORDER BY DistanceKm IS NULL, DistanceKm ASC, r.Heure, d.Ord
            ");
            $stmt->execute($params);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    /**
     * POST : id_ja, journee, date (YYYY-MM-DD), statut (O/P/N), rencontres[] (pour Partiel).
     * Stockage dans disponible :
     *   Niveau journée  : DateCompetition = date exacte du cartouche, Id_Rencontre = NULL, Reponse = O/P/N
     *   Niveau rencontre: DateCompetition = Date match,               Id_Rencontre = id,   Reponse = O
     */
    public function sauvegarderDispoJournee(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo         = getPDO();
            $idJa        = $this->resolveIdJaAutorise();
            $journeeRaw  = $this->request->getPost('journee');
            $dateJournee = trim($this->request->getPost('date') ?? '');
            $statut      = strtoupper(trim($this->request->getPost('statut') ?? ''));
            if (!$idJa || $journeeRaw === null || $journeeRaw === '' || $dateJournee === '' || !in_array($statut, ['O', 'P', 'N'])) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Paramètres invalides']);
            }
            $journee = (int) $journeeRaw;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateJournee)) {
                return $this->response->setJSON(['ok' => false, 'err' => 'Date invalide']);
            }

            $stmtIds = $pdo->prepare('SELECT Id_Rencontre, Date FROM rencontre WHERE Journee = ? AND Date = ?');
            $stmtIds->execute([$journee, $dateJournee]);
            $tousRencontres = $stmtIds->fetchAll();
            $tousIds        = array_column($tousRencontres, 'Id_Rencontre');

            if ($statut === 'O' || $statut === 'N') {
                $pdo->prepare('DELETE FROM disponible WHERE Id_JA = ? AND DateCompetition = ? AND Id_Rencontre IS NULL')
                    ->execute([$idJa, $dateJournee]);
                $pdo->prepare('INSERT INTO disponible (Id_JA, DateCompetition, Reponse, DateReponse) VALUES (?, ?, ?, CURDATE())')
                    ->execute([$idJa, $dateJournee, $statut]);
                if ($tousIds) {
                    $in = implode(',', array_fill(0, count($tousIds), '?'));
                    $pdo->prepare("DELETE FROM disponible WHERE Id_JA = ? AND Id_Rencontre IN ($in)")
                        ->execute(array_merge([$idJa], $tousIds));
                }
            } elseif ($statut === 'P') {
                $pdo->prepare('DELETE FROM disponible WHERE Id_JA = ? AND DateCompetition = ? AND Id_Rencontre IS NULL')
                    ->execute([$idJa, $dateJournee]);
                $selIds   = array_map('intval', (array) ($this->request->getPost('rencontres') ?? []));
                $mapDates = array_column($tousRencontres, 'Date', 'Id_Rencontre');
                $stmtUps  = $pdo->prepare("
                    INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse)
                    VALUES (?, ?, ?, 'O', CURDATE())
                    ON DUPLICATE KEY UPDATE Reponse='O', DateCompetition=VALUES(DateCompetition), DateReponse=CURDATE()
                ");
                $stmtDel = $pdo->prepare('DELETE FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
                foreach ($tousIds as $idR) {
                    if (in_array($idR, $selIds)) {
                        $stmtUps->execute([$idJa, $idR, $mapDates[$idR] ?? $dateJournee]);
                    } else {
                        $stmtDel->execute([$idJa, $idR]);
                    }
                }
            }

            return $this->response->setJSON(['ok' => true, 'statut' => $statut]);
        });
    }

    public function token(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id = (int) ($this->request->getGet('id') ?? $this->request->getPost('id') ?? 0);
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant']);
            }
            $token = $this->obf->obfuscate($id);
            $url   = site_url('disponibilite-ja-v2') . '?ja=' . $token;

            return $this->response->setJSON(['ok' => true, 'token' => $token, 'url' => $url]);
        });
    }

    public function lireNote(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id = $this->resolveIdJaAutorise();
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }
            $stmt = getPDO()->prepare('SELECT Note FROM JA WHERE Id_JA = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();

            return $this->response->setJSON(['ok' => true, 'note' => $row ? ($row['Note'] ?? '') : '']);
        });
    }

    public function sauvegarderNote(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id   = $this->resolveIdJaAutorise();
            $note = trim($this->request->getPost('note') ?? '');
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }
            getPDO()->prepare('UPDATE JA SET Note = ? WHERE Id_JA = ?')
                ->execute([$note === '' ? null : $note, $id]);

            return $this->response->setJSON(['ok' => true]);
        });
    }

    public function sauvegarderDefiscalisation(): ResponseInterface
    {
        return $this->tryJson(function () {
            $id     = $this->resolveIdJaAutorise();
            $defisc = !empty($this->request->getPost('defiscalisation')) ? 1 : 0;
            if (!$id) {
                return $this->response->setJSON(['ok' => false, 'err' => 'ID manquant ou non autorisé']);
            }
            getPDO()->prepare('UPDATE JA SET Defiscalisation = ? WHERE Id_JA = ?')
                ->execute([$defisc, $id]);

            return $this->response->setJSON(['ok' => true]);
        });
    }
}
