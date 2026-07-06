<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Désidératas club (E023), portage CI4 de Nominateur/desiderata_club.php.
 *
 * Page PUBLIQUE, sans authentification — tokenisée par le paramètre `?club=<Id_Club>`
 * envoyé par email depuis E027 (JA_R3R4.php, pas encore porté). Pas de filtre de
 * route ("auth"/"adminauth") : seul le filtre global "canonicalhost" s'applique.
 *
 * Session native démarrée manuellement dans chaque action (comme AuthController),
 * bien qu'aucun filtre "auth" n'ouvre de session ici puisque la page n'a pas
 * d'utilisateur connecté. La protection CSRF (filtre global "csrf") est en
 * mode cookie et ne dépend donc pas de cette session.
 */
class DesiderataClubController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';

        $pdo = getPDO();

        $colsSalle = array_column($pdo->query('SHOW COLUMNS FROM salle')->fetchAll(\PDO::FETCH_ASSOC), 'Field');
        if (!in_array('Cp', $colsSalle)) {
            $pdo->exec('ALTER TABLE salle ADD COLUMN Cp VARCHAR(10) NULL AFTER Adresse');
        }
        if (!in_array('Ville', $colsSalle)) {
            $pdo->exec('ALTER TABLE salle ADD COLUMN Ville VARCHAR(100) NULL AFTER Cp');
        }
    }

    private function startSession(): void
    {
        demarrerSessionNijac();
    }

    /**
     * Les erreurs de base de données ne doivent jamais fuiter vers ce endpoint
     * public (anonyme) : on journalise le détail côté serveur et on ne retourne
     * qu'un message générique, comme le fait le catch (PDOException) legacy.
     */
    private function tryJson(\Closure $fn): ResponseInterface
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] desiderata_club PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur base de données.']);
        }
    }

    public function index()
    {
        $this->startSession();

        $idClubGet = trim($this->request->getGet('club') ?? '');

        $club   = null;
        $erreur = '';
        if ($idClubGet !== '') {
            $stmt = getPDO()->prepare('SELECT Id_Club, Nom FROM club WHERE Id_Club = ?');
            $stmt->execute([$idClubGet]);
            $club = $stmt->fetch();
            if (!$club) {
                $erreur = "Club #$idClubGet introuvable.";
            }
        } else {
            $erreur = 'Lien invalide ou paramètre manquant.';
        }

        session_write_close();

        return view('desiderata_club_index', [
            'club'   => $club,
            'erreur' => $erreur,
        ]);
    }

    public function charger(): ResponseInterface
    {
        $this->startSession();
        session_write_close();

        return $this->tryJson(function () {
            $club = trim($this->request->getGet('club') ?? '');
            if ($club === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club manquant.']);
            }

            $pdo = getPDO();

            $stmt = $pdo->prepare(
                'SELECT Id_Club, Nom, CorNom, CorEmail, CorTelephone, NbAiresJeu, DesiderataNote, DesiderataDate
                 FROM club WHERE Id_Club = ?'
            );
            $stmt->execute([$club]);
            $c = $stmt->fetch();
            if (!$c) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club introuvable.']);
            }

            $stmtS = $pdo->prepare('SELECT Nom, Adresse, Cp, Ville, Telephone FROM salle WHERE Id_Club = ? AND EstPrincipale = 1 LIMIT 1');
            $stmtS->execute([$club]);
            $salle = $stmtS->fetch() ?: ['Nom' => '', 'Adresse' => '', 'Cp' => '', 'Ville' => '', 'Telephone' => ''];

            $stmtE = $pdo->prepare(
                "SELECT e.Id_Equipe, e.Nom AS NomEquipe, e.ReEngagement, e.JourSouhaite, e.SouhaitJA,
                        d.Id_Division, d.Division, d.Nom AS NomDivision
                 FROM equipe e
                 JOIN division d ON d.Id_Division = e.Id_Division
                 WHERE e.Id_Club = ? AND d.Ord BETWEEN 70 AND 150
                 ORDER BY d.Ord, e.Nom"
            );
            $stmtE->execute([$club]);
            $equipes = $stmtE->fetchAll();

            return $this->response->setJSON([
                'ok'      => true,
                'club'    => $c,
                'salle'   => $salle,
                'equipes' => $equipes,
                'saison'  => getConfig('saison', ''),
            ]);
        });
    }

    public function enregistrer(): ResponseInterface
    {
        $this->startSession();
        session_write_close();

        return $this->tryJson(function () {
            $club = trim($this->request->getPost('club') ?? '');
            if ($club === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club manquant.']);
            }

            $pdo = getPDO();

            $chk = $pdo->prepare('SELECT COUNT(*) FROM club WHERE Id_Club = ?');
            $chk->execute([$club]);
            if (!$chk->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club introuvable.']);
            }

            $corNom   = trim($this->request->getPost('cor_nom') ?? '') ?: null;
            $corEmail = trim($this->request->getPost('cor_email') ?? '') ?: null;
            $corTel   = trim($this->request->getPost('cor_tel') ?? '') ?: null;
            $nbAiresP = $this->request->getPost('nb_aires');
            $nbAires  = ($nbAiresP ?? '') !== '' ? max(0, (int) $nbAiresP) : null;
            $note     = trim($this->request->getPost('note') ?? '') ?: null;
            $saison   = getConfig('saison', '');

            $pdo->prepare(
                'UPDATE club SET CorNom=?, CorEmail=?, CorTelephone=?, NbAiresJeu=?, DesiderataNote=?, DesiderataSaison=?, DesiderataDate=NOW()
                 WHERE Id_Club=?'
            )->execute([$corNom, $corEmail, $corTel, $nbAires, $note, $saison, $club]);

            $salleNom   = trim($this->request->getPost('salle_nom') ?? '') ?: null;
            $salleAdr   = trim($this->request->getPost('salle_adresse') ?? '') ?: null;
            $salleCp    = trim($this->request->getPost('salle_cp') ?? '') ?: null;
            $salleVille = trim($this->request->getPost('salle_ville') ?? '') ?: null;
            $salleTel   = trim($this->request->getPost('salle_tel') ?? '') ?: null;

            $stmtSalleChk = $pdo->prepare('SELECT Id_Salle FROM salle WHERE Id_Club=? AND EstPrincipale=1 LIMIT 1');
            $stmtSalleChk->execute([$club]);
            $idSalle = $stmtSalleChk->fetchColumn();
            if ($idSalle) {
                // Nom laissé vide = conserve le nom existant (colonne NOT NULL)
                $pdo->prepare('UPDATE salle SET Nom=COALESCE(?, Nom), Adresse=?, Cp=?, Ville=?, Telephone=? WHERE Id_Salle=?')
                    ->execute([$salleNom, $salleAdr, $salleCp, $salleVille, $salleTel, $idSalle]);
            } elseif ($salleNom !== null) {
                $pdo->prepare('INSERT INTO salle (Nom, Adresse, Cp, Ville, Telephone, Id_Club, EstPrincipale) VALUES (?,?,?,?,?,?,1)')
                    ->execute([$salleNom, $salleAdr, $salleCp, $salleVille, $salleTel, $club]);
            }

            $equipes = json_decode($this->request->getPost('equipes') ?? '[]', true);
            if (is_array($equipes)) {
                $stmtEq = $pdo->prepare(
                    'UPDATE equipe SET ReEngagement=?, JourSouhaite=?, SouhaitJA=?, DesiderataSaison=?
                     WHERE Id_Equipe=? AND Id_Club=?'
                );
                $stmtDivOf = $pdo->prepare('SELECT Id_Division FROM equipe WHERE Id_Equipe=? AND Id_Club=?');

                foreach ($equipes as $eq) {
                    $idEquipe = (int) ($eq['id_equipe'] ?? 0);
                    if ($idEquipe <= 0) {
                        continue;
                    }

                    $re  = in_array($eq['reengagement'] ?? '', ['O', 'N'], true) ? $eq['reengagement'] : null;
                    $jr  = in_array($eq['jour'] ?? '', ['Samedi', 'Dimanche'], true) ? $eq['jour'] : null;
                    $sja = in_array($eq['souhait_ja'] ?? '', ['CRA', 'Club'], true) ? $eq['souhait_ja'] : null;

                    $stmtEq->execute([$re, $jr, $sja, $saison, $idEquipe, $club]);

                    // Synchronise JAdemande + ArbitrageObligatoire pour les équipes R3M/R4M
                    if ($sja !== null) {
                        $stmtDivOf->execute([$idEquipe, $club]);
                        $idDiv = (int) $stmtDivOf->fetchColumn();
                        if (in_array($idDiv, [1, 10], true)) { // R3M, R4M
                            $jademande = $sja === 'CRA' ? 1 : 0;
                            $pdo->prepare('UPDATE equipe SET JAdemande=? WHERE Id_Equipe=?')->execute([$jademande, $idEquipe]);
                            if ($jademande === 1) {
                                $pdo->prepare('UPDATE rencontre SET ArbitrageObligatoire=1 WHERE Id_EquipeDom=?')
                                    ->execute([$idEquipe]);
                            } else {
                                $pdo->prepare(
                                    'UPDATE rencontre r
                                     JOIN equipe e   ON e.Id_Equipe   = r.Id_EquipeDom
                                     JOIN division d ON d.Id_Division = e.Id_Division
                                     SET r.ArbitrageObligatoire = d.ArbitrageCRA
                                     WHERE r.Id_EquipeDom = ?'
                                )->execute([$idEquipe]);
                            }
                        }
                    }
                }
            }

            return $this->response->setJSON(['ok' => true]);
        });
    }
}
