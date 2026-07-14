<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Désidératas clubs (E027), portage CI4 de Nominateur/JA_R3R4.php.
 *
 * Sélection des clubs ayant des équipes PN à R4M et envoi groupé du
 * questionnaire de désidératas de saison (message système n°6) contenant un
 * lien vers le formulaire public E023 (site_url('desiderata-club')).
 *
 * Accessible rôle CSR (Commission Sportive Régionale) ou Administrateur (filtre "csrauth") —
 * plus accessible au Nominateur, dont le menu (E020) ne pointe plus vers cet écran. Pas de
 * Model : agrégations GROUP_CONCAT et filtrage dynamique par département — reste en raw PDO
 * comme le reste de cette famille d'écrans.
 */
class DesiderataClubsController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] JA_R3R4 PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
        ];

        return view('desiderata_clubs_index', $data);
    }

    public function liste(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $saison = getConfig('saison', '');
            $dept   = (int) ($this->request->getGet('dept') ?? 0);

            // Ne retenir que les clubs des départements actifs de la région (pas les clubs Hors région)
            $deptsRegion = array_map('intval', array_column(getDeptActifs(), 'code'));
            if (!$deptsRegion) {
                return $this->response->setJSON(['ok' => true, 'data' => [], 'saison' => $saison]);
            }

            $sql = "SELECT c.Id_Club, c.Nom AS NomClub, c.CorNom, c.CorEmail,
                           c.DesiderataSaison, c.DesiderataDate, c.DesiderataEmailDate, c.DesiderataNote,
                           SUBSTRING(c.Id_Club, 3, 2) AS Departement,
                           COUNT(e.Id_Equipe) AS NbEquipes,
                           GROUP_CONCAT(DISTINCT d.Division ORDER BY d.Ord SEPARATOR ',') AS Divisions,
                           GROUP_CONCAT(CONCAT(d.Division, '§', e.Nom) ORDER BY d.Ord SEPARATOR '||') AS DivisionsEquipes
                    FROM club c
                    JOIN equipe e   ON e.Id_Club = c.Id_Club
                    JOIN division d ON d.Id_Division = e.Id_Division
                    WHERE d.Ord BETWEEN 70 AND 150";
            $params = [];
            if ($dept > 0) {
                $sql     .= ' AND SUBSTRING(c.Id_Club, 3, 2) = ?';
                $params[] = $dept;
            } else {
                $ph       = implode(',', array_fill(0, count($deptsRegion), '?'));
                $sql     .= " AND SUBSTRING(c.Id_Club, 3, 2) IN ($ph)";
                $params   = array_merge($params, $deptsRegion);
            }
            $sql .= ' GROUP BY c.Id_Club, c.Nom, c.CorNom, c.CorEmail,
                               c.DesiderataSaison, c.DesiderataDate, c.DesiderataEmailDate, c.DesiderataNote,
                               SUBSTRING(c.Id_Club, 3, 2)
                      ORDER BY c.Nom';

            $rows = $pdo->prepare($sql);
            $rows->execute($params);

            return $this->response->setJSON(['ok' => true, 'data' => $rows->fetchAll(), 'saison' => $saison]);
        });
    }

    public function departements(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo  = getPDO();
            $stmt = $pdo->query("SELECT valeur FROM configuration WHERE cle = 'departements_actifs'");
            $row  = $stmt->fetch();
            $codes = $row
                ? array_map('intval', array_filter(array_map('trim', explode(',', $row['valeur']))))
                : [];
            if (!$codes) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }
            sort($codes);
            $ph   = implode(',', array_fill(0, count($codes), '?'));
            $stmt = $pdo->prepare(
                "SELECT CAST(code AS UNSIGNED) AS code, nom
                 FROM departement
                 WHERE CAST(code AS UNSIGNED) IN ($ph)
                 ORDER BY CAST(code AS UNSIGNED)"
            );
            $stmt->execute($codes);

            return $this->response->setJSON(['ok' => true, 'data' => $stmt->fetchAll()]);
        });
    }

    public function detail(): ResponseInterface
    {
        return $this->tryJson(function () {
            $idClub = trim($this->request->getGet('club') ?? '');
            if ($idClub === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club manquant.']);
            }

            $pdo   = getPDO();
            $stmtC = $pdo->prepare('SELECT Id_Club, Nom, DesiderataNote FROM club WHERE Id_Club = ?');
            $stmtC->execute([$idClub]);
            $club = $stmtC->fetch();
            if (!$club) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club introuvable.']);
            }

            $stmtE = $pdo->prepare(
                "SELECT e.Id_Equipe, e.Nom AS NomEquipe, e.ReEngagement, e.JourSouhaite, e.SouhaitJA,
                        d.Id_Division, d.Division, d.Nom AS NomDivision
                 FROM equipe e
                 JOIN division d ON d.Id_Division = e.Id_Division
                 WHERE e.Id_Club = ? AND d.Ord BETWEEN 70 AND 150
                 ORDER BY d.Ord, e.Nom"
            );
            $stmtE->execute([$idClub]);
            $equipes = $stmtE->fetchAll();

            return $this->response->setJSON(['ok' => true, 'club' => $club, 'equipes' => $equipes]);
        });
    }

    public function jaClub(): ResponseInterface
    {
        return $this->tryJson(function () {
            $idClub = trim($this->request->getGet('club') ?? '');
            if ($idClub === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club manquant.']);
            }

            $pdo   = getPDO();
            $stmtC = $pdo->prepare('SELECT Id_Club, Nom FROM club WHERE Id_Club = ?');
            $stmtC->execute([$idClub]);
            $club = $stmtC->fetch();
            if (!$club) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club introuvable.']);
            }

            $stmtJ = $pdo->prepare(
                'SELECT Id_JA, Nom, Prenom, Grade FROM ja WHERE Id_Club = ? AND Actif = 1 ORDER BY Nom, Prenom'
            );
            $stmtJ->execute([$idClub]);
            $jas = $stmtJ->fetchAll();

            require_once __DIR__ . '/../../../Classes/Obfuscator.php';
            $obf = new \Obfuscator(OBFUSCATOR_SEED);
            foreach ($jas as &$j) {
                $j['Token'] = $obf->obfuscate((int) $j['Id_JA']);
            }
            unset($j);

            $stmtAC = $pdo->prepare(
                "SELECT COUNT(*) FROM equipe e JOIN division d ON d.Id_Division = e.Id_Division
                 WHERE e.Id_Club = ? AND d.Division IN ('R3M','R4M') AND e.SouhaitJA = 'Club'"
            );
            $stmtAC->execute([$idClub]);
            $arbitrageClub = (bool) $stmtAC->fetchColumn();

            return $this->response->setJSON(['ok' => true, 'club' => $club, 'jas' => $jas, 'arbitrageClub' => $arbitrageClub]);
        });
    }

    public function apercu(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo    = getPDO();
            $moi    = $_SESSION['utilisateur'] ?? [];
            $msgRow = $pdo->query('SELECT Sujet, Message FROM messagerie WHERE Id_Messagerie = 6')->fetch();
            if (!$msgRow) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Modèle de message n°6 introuvable (à créer dans E026 — Gestion des messages).']);
            }

            $idClub = trim($this->request->getGet('club') ?? '');
            $club   = null;
            if ($idClub !== '') {
                $stmtC = $pdo->prepare('SELECT Id_Club, Nom, CorNom FROM club WHERE Id_Club = ?');
                $stmtC->execute([$idClub]);
                $club = $stmtC->fetch();
            }

            $vars = [
                '{NOM_CLUB}'       => $club['Nom'] ?? 'Nom du club',
                '{CORR_NOM}'       => $club['CorNom'] ?? 'Nom du correspondant',
                '{URL_DESIDERATA}' => site_url('desiderata-club') . '?club=' . urlencode($club['Id_Club'] ?? '09760136'),
                '{URL_LIGUE}'      => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
                '{YEAR_PHASE}'     => getAnneePhase(),
                '{UTI_NOM}'        => $moi['nom'] ?? '',
                '{UTI_PRENOM}'     => $moi['prenom'] ?? '',
            ];
            $corps = str_replace(array_keys($vars), array_values($vars), $msgRow['Message']);
            $sujet = str_replace(array_keys($vars), array_values($vars), $msgRow['Sujet']);

            return $this->response->setJSON(['ok' => true, 'sujet' => $sujet, 'corps' => $corps]);
        });
    }

    public function envoyer(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo = getPDO();
            $moi = $_SESSION['utilisateur'] ?? [];

            $ids = json_decode($this->request->getPost('ids') ?? '[]', true);
            if (!is_array($ids) || !$ids) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun club sélectionné.']);
            }

            $errRl = checkRateLimit(count($ids));
            if ($errRl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
            }

            $msgRow = $pdo->query('SELECT Sujet, Message FROM messagerie WHERE Id_Messagerie = 6')->fetch();
            if (!$msgRow) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Modèle de message n°6 introuvable (à créer dans E026 — Gestion des messages).']);
            }

            $base    = site_url('desiderata-club');
            $modeDev = isModeDeveloppement();

            $ph        = implode(',', array_fill(0, count($ids), '?'));
            $stmtClubs = $pdo->prepare("SELECT Id_Club, Nom, CorNom, CorEmail FROM club WHERE Id_Club IN ($ph)");
            $stmtClubs->execute($ids);
            $clubs = $stmtClubs->fetchAll();

            $envoyes   = 0;
            $sansEmail = [];
            $erreurs   = [];

            foreach ($clubs as $c) {
                if (empty($c['CorEmail'])) {
                    $sansEmail[] = $c['Nom'];
                    continue;
                }

                $urlDesiderata = $base . '?club=' . urlencode($c['Id_Club']);
                $vars = [
                    '{NOM_CLUB}'       => $c['Nom'],
                    '{CORR_NOM}'       => $c['CorNom'] ?? '',
                    '{URL_DESIDERATA}' => $urlDesiderata,
                    '{URL_LIGUE}'      => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
                    '{YEAR_PHASE}'     => getAnneePhase(),
                    '{UTI_NOM}'        => $moi['nom'] ?? '',
                    '{UTI_PRENOM}'     => $moi['prenom'] ?? '',
                ];
                $corps = str_replace(array_keys($vars), array_values($vars), $msgRow['Message']);
                $sujet = str_replace(array_keys($vars), array_values($vars), $msgRow['Sujet']);

                try {
                    $dest   = getEmailDestinataire($c['CorEmail']);
                    $isHtml = strip_tags($corps) !== $corps;
                    $mail   = getNijacMailer();
                    $mail->isHTML($isHtml);
                    $mail->addAddress($dest, $c['CorNom'] ?: $c['Nom']);
                    $mail->Subject = ($modeDev && $dest !== $c['CorEmail']) ? "[DEV] $sujet" : $sujet;
                    $mail->Body    = $corps;
                    if ($isHtml) {
                        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
                    }
                    $mail->send();
                    enregistrerEnvois(1);
                    $envoyes++;

                    $pdo->prepare('UPDATE club SET DesiderataEmailDate = NOW() WHERE Id_Club = ?')
                        ->execute([$c['Id_Club']]);
                } catch (\Exception $e) {
                    $erreurs[] = $c['Nom'] . ' : ' . $e->getMessage();
                }
            }

            $msg = "$envoyes email(s) envoyé(s).";
            if ($sansEmail) {
                $msg .= ' Sans email : ' . implode(', ', $sansEmail) . '.';
            }
            if ($erreurs) {
                $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            }

            return $this->response->setJSON(['ok' => true, 'msg' => $msg, 'envoyes' => $envoyes]);
        });
    }
}
