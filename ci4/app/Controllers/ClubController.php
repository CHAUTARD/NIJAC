<?php

namespace App\Controllers;

use Alamirault\FFTTApi\Exception\ClubNotFoundException;
use Alamirault\FFTTApi\Service\FFTTApi as FfttApiLib;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des clubs et associations (E008), portage CI4 de club.php.
 *
 * Admin uniquement (filtre "adminauth", comme includes/admin_required.php
 * côté legacy).
 *
 * Pas de Model : auto-migration de colonnes, appels API FFTT — trop éloigné
 * du Query Builder simple. Réutilise getPDO() directement, comme le fichier
 * legacy.
 */
class ClubController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    /**
     * Exécute une action et convertit toute exception en réponse JSON
     * ['ok' => false, 'msg' => ...] — club.php enveloppe de la même façon la
     * totalité de son dispatcher d'actions (appels API FFTT compris).
     */
    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            error_log('[NIJAC] club.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            error_log('[NIJAC] club.php : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function index()
    {
        $moi = $_SESSION['utilisateur'] ?? [];
        $pdo = getPDO();

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'deptActifs'  => getDeptActifs(),
            'tousDepts'   => $pdo->query(
                "SELECT code, nom FROM departement
                 ORDER BY CASE WHEN code IN ('2A','2B') THEN 20 ELSE CAST(code AS UNSIGNED) END, code"
            )->fetchAll(),
        ];

        return view('club_index', $data);
    }

    public function liste(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();

            // Auto-migration colonnes correspondant
            $colsClub = array_column($pdo->query('SHOW COLUMNS FROM Club')->fetchAll(), 'Field');
            $corCols  = [
                'CorNom'       => 'VARCHAR(100) NULL',
                'CorEmail'     => 'VARCHAR(150) NULL',
                'CorTelephone' => 'VARCHAR(20) NULL',
            ];
            foreach ($corCols as $col => $def) {
                if (!in_array($col, $colsClub)) {
                    $pdo->exec("ALTER TABLE Club ADD COLUMN $col $def");
                }
            }
            // Copie unique Correspondant → Club (si la table Correspondant existe encore
            // et qu'il reste des clubs sans correspondant) — no-op désormais que la table
            // a été retirée (voir E024/E026), conservé pour parité avec le fichier legacy.
            $tables = array_column($pdo->query("SHOW TABLES LIKE 'Correspondant'")->fetchAll(), 0);
            if ($tables) {
                $pdo->exec(
                    'UPDATE Club cl
                     JOIN (SELECT Id_Club, MIN(Id_Correspondant) AS Id_Min FROM Correspondant GROUP BY Id_Club) sel
                       ON sel.Id_Club = cl.Id_Club AND cl.CorNom IS NULL
                     JOIN Correspondant co ON co.Id_Correspondant = sel.Id_Min
                     SET cl.CorNom = co.Nom, cl.CorEmail = co.Email, cl.CorTelephone = co.Telephone'
                );
            }

            $rows = $pdo->query(
                'SELECT c.Id_Club, c.Nom,
                        c.CorNom, c.CorEmail, c.CorTelephone,
                        (SELECT COUNT(*) FROM Salle s2 WHERE s2.Id_Club = c.Id_Club) AS NbSalles
                 FROM Club c
                 ORDER BY c.Nom'
            )->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows]);
        });
    }

    public function modifier(string $idClub): ResponseInterface
    {
        return $this->tryJson(function () use ($idClub) {
            $pdo   = getPDO();
            $input = $this->request->getRawInput();

            $nom      = trim($input['nom'] ?? '');
            $corNom   = trim($input['cor_nom'] ?? '') ?: null;
            $corEmail = trim($input['cor_email'] ?? '') ?: null;
            $corTel   = trim($input['cor_tel'] ?? '') ?: null;

            if ($nom === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom du club est obligatoire.']);
            }

            $stmt = $pdo->prepare('UPDATE Club SET Nom=?, CorNom=?, CorEmail=?, CorTelephone=? WHERE Id_Club=?');
            $stmt->execute([$nom, $corNom, $corEmail, $corTel, $idClub]);

            if ($stmt->rowCount() === 0) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM Club WHERE Id_Club = ?');
                $chk->execute([$idClub]);
                if ((int) $chk->fetchColumn() === 0) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Club $idClub introuvable."]);
                }
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Club mis à jour.']);
        });
    }

    public function getClubsDeptFftt(): ResponseInterface
    {

        return $this->tryJson(function () {
            $dep = trim($this->request->getPost('dep') ?? '');
            if ($dep === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Département manquant.']);
            }

            $api   = new FfttApiLib(getFfttAppId(), getFfttAppKey());
            $clubs = $api->listClubsByDepartement((int) $dep);

            $clubs = array_filter($clubs, static fn ($c) => $c->getNumero() !== '');

            return $this->response->setJSON(['ok' => true, 'clubs' => array_map(static fn ($c) => [
                'numero' => $c->getNumero(),
                'nom'    => $c->getNom(),
            ], $clubs)]);
        });
    }

    public function syncFfttClub(): ResponseInterface
    {

        return $this->tryJson(function () {
            $pdo     = getPDO();
            $numClub = trim($this->request->getPost('num_club') ?? '');
            if ($numClub === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Numéro de club manquant.']);
            }

            set_time_limit(30);
            $api = new FfttApiLib(getFfttAppId(), getFfttAppKey());
            try {
                $detail = $api->retrieveClubDetails($numClub);
            } catch (ClubNotFoundException) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Club $numClub introuvable dans l'API FFTT."]);
            }

            // ClubDetails normalise déjà côté lib le cas "tableau vide au lieu de
            // chaîne" renvoyé par l'API FFTT pour un champ d'adresse non renseigné.
            $nomClub    = trim($detail->getNom());
            $nomsalle   = trim($detail->getNomSalle() ?? '');
            $adr1       = trim($detail->getAdresseSalle1() ?? '');
            $adr2       = trim($detail->getAdresseSalle2() ?? '');
            $adr3       = trim($detail->getAdresseSalle3() ?? '');
            $adresse    = trim(implode(' ', array_filter([$adr1, $adr2, $adr3]))) ?: null;
            $cpSalle    = trim($detail->getCodePostaleSalle() ?? '');
            $villeSalle = mb_strtoupper(trim($detail->getVilleSalle() ?? ''), 'UTF-8');
            $nomCor     = mb_strtoupper(trim($detail->getNomCoordo() ?? ''), 'UTF-8');
            $prenomCor  = trim($detail->getPrenomCoordo() ?? '');
            $mailCor    = trim($detail->getMailCoordo() ?? '');
            $telCor     = trim($detail->getTelCoordo() ?? '');
            // Formatage XX.XX.XX.XX.XX
            $telDigits = preg_replace('/[^0-9]/', '', $telCor);
            if (strlen($telDigits) === 10) {
                $telCor = implode('.', str_split($telDigits, 2));
            }

            $ops = [];

            // ── 1. Club ────────────────────────────────────────────────────
            if ($nomClub !== '') {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM Club WHERE Id_Club=?');
                $chk->execute([$numClub]);
                if ((int) $chk->fetchColumn() > 0) {
                    $pdo->prepare('UPDATE Club SET Nom=? WHERE Id_Club=?')->execute([$nomClub, $numClub]);
                    $ops[] = "Club mis à jour : $nomClub";
                } else {
                    $pdo->prepare('INSERT INTO Club (Id_Club, Nom) VALUES (?,?)')->execute([$numClub, $nomClub]);
                    $ops[] = "Club créé : $nomClub";
                }
            }

            // ── 2. Salle principale ────────────────────────────────────────
            if ($nomsalle !== '' && $cpSalle !== '') {
                $idLaPoste = null;
                // Essai 1 : CP + Ville exacte
                $stmtExact = $pdo->prepare('SELECT Id_LaPoste FROM laposte WHERE CodePostal=? AND UPPER(Nom)=? LIMIT 1');
                $stmtExact->execute([$cpSalle, $villeSalle]);
                $idLaPoste = $stmtExact->fetchColumn() ?: null;
                // Essai 2 : CP seul (1 résultat)
                if (!$idLaPoste) {
                    $stmtCp = $pdo->prepare('SELECT Id_LaPoste FROM laposte WHERE CodePostal=? LIMIT 2');
                    $stmtCp->execute([$cpSalle]);
                    $rows = $stmtCp->fetchAll();
                    if (count($rows) === 1) {
                        $idLaPoste = $rows[0]['Id_LaPoste'];
                    }
                }

                $stmtSalleChk = $pdo->prepare('SELECT Id_Salle FROM Salle WHERE Id_Club=? AND EstPrincipale=1 LIMIT 1');
                $stmtSalleChk->execute([$numClub]);
                $idSalle = $stmtSalleChk->fetchColumn();

                if ($idSalle) {
                    $pdo->prepare('UPDATE Salle SET Nom=?, Adresse=?, Id_Laposte=? WHERE Id_Salle=?')
                        ->execute([$nomsalle, $adresse, $idLaPoste, $idSalle]);
                    $ops[] = "Salle mise à jour : $nomsalle";
                } else {
                    $pdo->prepare('INSERT INTO Salle (Nom, Adresse, Id_Laposte, Id_Club, EstPrincipale) VALUES (?,?,?,?,1)')
                        ->execute([$nomsalle, $adresse, $idLaPoste, $numClub]);
                    $ops[] = "Salle créée : $nomsalle";
                }
            }

            // ── 3. Correspondant (colonnes CorNom… dans Club) ──────────────
            if ($nomCor !== '') {
                $nomComplet = trim("$nomCor $prenomCor");
                $pdo->prepare(
                    'UPDATE Club SET CorNom=?, CorEmail=?, CorTelephone=? WHERE Id_Club=?'
                )->execute([$nomComplet, $mailCor ?: null, $telCor ?: null, $numClub]);
                $ops[] = "Correspondant mis à jour : $nomComplet";
            }

            return $this->response->setJSON(['ok' => true, 'ops' => $ops, 'club' => $numClub, 'nom' => $nomClub]);
        });
    }
}
