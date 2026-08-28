<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Gestion des clubs et associations (EA80), portage CI4 de club.php.
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
        require_once __DIR__ . '/../../../config/helpers.php';
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

           // Un nom d'équipe ne doit désigner qu'un seul club (utilisé pour l'affectation
            // automatique en EA82/EA83). Posée séparément avec son propre try/catch : si des
            // doublons existent déjà en base, on ne veut pas faire planter le chargement de
            // l'écran, juste laisser la contrainte non posée jusqu'à correction manuelle.
            $hasUqEquipeNom = (bool) $pdo->query("SHOW INDEX FROM Club WHERE Key_name = 'uq_club_equipenom'")->fetch();
            if (!$hasUqEquipeNom) {
                try {
                    $pdo->exec('ALTER TABLE Club ADD UNIQUE KEY uq_club_equipenom (EquipeNom)');
                } catch (\PDOException $e) {
                }
            }
            $rows = $pdo->query(
                'SELECT c.Id_Club, c.Nom, c.EquipeNom,
                        c.CorNom, c.CorEmail, c.CorTelephone,
                        (SELECT COUNT(*) FROM Salle s2 WHERE s2.Id_Club = c.Id_Club) AS NbSalles,
                        sp.Nom AS SallePrincipaleNom, sp.Cp AS SallePrincipaleCp, sp.Ville AS SallePrincipaleVille
                 FROM Club c
                 LEFT JOIN Salle sp ON sp.Id_Club = c.Id_Club AND sp.EstPrincipale = 1
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

            $nom       = trim($input['nom'] ?? '');
            $equipeNom = trim($input['equipe_nom'] ?? '') ?: null;
            $corNom    = trim($input['cor_nom'] ?? '') ?: null;
            $corEmail  = trim($input['cor_email'] ?? '') ?: null;
            $corTel    = trim($input['cor_tel'] ?? '') ?: null;

            if ($nom === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le nom du club est obligatoire.']);
            }

            if ($equipeNom !== null) {
                $chkEquipe = $pdo->prepare('SELECT Nom FROM Club WHERE EquipeNom = ? AND Id_Club <> ?');
                $chkEquipe->execute([$equipeNom, $idClub]);
                $autreClub = $chkEquipe->fetchColumn();
                if ($autreClub !== false) {
                    return $this->response->setJSON(['ok' => false, 'msg' => "Ce nom d'équipe est déjà utilisé par le club « $autreClub »."]);
                }
            }

            $stmt = $pdo->prepare('UPDATE Club SET Nom=?, EquipeNom=?, CorNom=?, CorEmail=?, CorTelephone=? WHERE Id_Club=?');
            $stmt->execute([$nom, $equipeNom, $corNom, $corEmail, $corTel, $idClub]);

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

    public function supprimer(string $idClub): ResponseInterface
    {
        return $this->tryJson(function () use ($idClub) {
            $pdo = getPDO();

            try {
                $stmt = $pdo->prepare('DELETE FROM Club WHERE Id_Club = ?');
                $stmt->execute([$idClub]);
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    return $this->response->setJSON(['ok' => false, 'msg' => 'Impossible de supprimer : ce club est encore utilisé (équipe, salle ou JA rattaché).']);
                }
                throw $e;
            }

            if ($stmt->rowCount() === 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Club $idClub introuvable."]);
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Club supprimé.']);
        });
    }

    /** Codes département FFTT (xml_club_dep2) pour la Corse, distincts des codes INSEE 2A/2B utilisés partout ailleurs dans l'appli. */
    private const DEPT_FFTT_CORSE = ['2A' => '98', '2B' => '99'];

    public function getClubsDeptFftt(): ResponseInterface
    {

        return $this->tryJson(function () {
            $dep = trim($this->request->getPost('dep') ?? '');
            if ($dep === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Département manquant.']);
            }

            $depFftt = self::DEPT_FFTT_CORSE[strtoupper($dep)] ?? $dep;
            $clubs   = getFfttRawClient()->listClubsByDepartement($depFftt);

            $clubs = array_filter($clubs, static fn ($c) => ($c['numero'] ?? '') !== '');

            return $this->response->setJSON(['ok' => true, 'clubs' => array_map(static fn ($c) => [
                'numero' => $c['numero'],
                'nom'    => $c['nom'],
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
            $detail = getFfttRawClient()->retrieveClubDetails($numClub);
            if (empty($detail['numero'])) {
                return $this->response->setJSON(['ok' => false, 'msg' => "Club $numClub introuvable dans l'API FFTT."]);
            }

            $nomsalle   = ffttStr($detail['nomsalle']  ?? '');
            $adr1       = ffttStr($detail['adressesalle1'] ?? '');
            $adr2       = ffttStr($detail['adressesalle2'] ?? '');
            $adr3       = ffttStr($detail['adressesalle3'] ?? '');
            $adresse    = trim(implode(' ', array_filter([$adr1, $adr2, $adr3]))) ?: null;
            $cpSalle    = ffttStr($detail['codepsalle'] ?? '');
            $villeSalle = mb_strtoupper(ffttStr($detail['villesalle'] ?? ''), 'UTF-8');
            $nomCor     = mb_strtoupper(ffttStr($detail['nomcor']     ?? ''), 'UTF-8');
            $prenomCor  = ffttStr($detail['prenomcor']  ?? '');
            $mailCor    = ffttStr($detail['mailcor']    ?? '');
            $telCor     = ffttStr($detail['telcor']     ?? '');
            // Formatage XX.XX.XX.XX.XX
            $telDigits = preg_replace('/[^0-9]/', '', $telCor);
            if (strlen($telDigits) === 10) {
                $telCor = implode('.', str_split($telDigits, 2));
            }

            $ops = [];

            // ── 1. Club ────────────────────────────────────────────────────
            $syncClub = synchroniserClubFftt($pdo, $numClub, $detail);
            if ($syncClub !== null) {
                $ops[] = $syncClub['cree'] ? "Club créé : {$syncClub['nom']}" : "Club mis à jour : {$syncClub['nom']}";
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

            return $this->response->setJSON(['ok' => true, 'ops' => $ops, 'club' => $numClub, 'nom' => $syncClub['nom'] ?? '']);
        });
    }
}
