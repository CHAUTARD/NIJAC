<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Désidératas club (EN18), portage CI4 de Nominateur/desiderata_club.php.
 *
 * Page PUBLIQUE, sans authentification — jeton signé `?club=<Id_Club>-<MAC>`
 * (tokenDesiderataClub(), app_config.php) envoyé par email depuis EN12 / ES32 ; le numéro de
 * club seul (public) est refusé. Pas de filtre de
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

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur base de données.')]);
        }
    }

    public function index()
    {
        $this->startSession();

        $token  = trim($this->request->getGet('club') ?? '');
        $idClub = idClubDepuisTokenDesiderata($token);

        $club   = null;
        $erreur = '';
        if ($idClub !== null) {
            $stmt = getPDO()->prepare('SELECT Id_Club, Nom FROM club WHERE Id_Club = ?');
            $stmt->execute([$idClub]);
            $club = $stmt->fetch();
            if (!$club) {
                $erreur = 'Club introuvable.';
            }
        } else {
            $erreur = "Lien invalide ou expiré. Merci de demander l'envoi d'un nouveau lien à la Ligue.";
        }

        session_write_close();
        // Empêche CodeIgniter\CodeIgniter::storePreviousURL() (appelé pour toute
        // réponse HTML non-AJAX, donc à chaque F5) de démarrer le service Session
        // de CI4 — voir Auth.php pour le détail du conflit avec la session native.
        unset($_SESSION);

        return view('desiderata_club_index', [
            'club'      => $club,
            'tokenClub' => $token,   // rejoué par le JS sur charger / enregistrer
            'erreur'    => $erreur,
        ]);
    }

    public function charger(): ResponseInterface
    {
        $this->startSession();
        session_write_close();

        return $this->tryJson(function () {
            $club = idClubDepuisTokenDesiderata(trim($this->request->getGet('club') ?? ''));
            if ($club === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Lien invalide ou expiré.']);
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
                "SELECT e.Id_Equipe, e.Nom AS NomEquipe, e.ReEngagement, e.JourSouhaite,
                        CASE WHEN e.ArbitrageCRA = 1 THEN 'CRA' ELSE 'Club' END AS SouhaitJA,
                        d.Division, d.Nom AS NomDivision
                 FROM equipe e
                 JOIN division d ON d.Division = e.Division
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
            $club = idClubDepuisTokenDesiderata(trim($this->request->getPost('club') ?? ''));
            if ($club === null) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Lien invalide ou expiré.']);
            }

            $pdo = getPDO();

            $chk = $pdo->prepare('SELECT COUNT(*) FROM club WHERE Id_Club = ?');
            $chk->execute([$club]);
            if (!$chk->fetchColumn()) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Club introuvable.']);
            }

            // Endpoint public : toute saisie est bornée avant écriture.
            $texte    = fn (string $cle, int $max = 255): ?string => mb_substr(trim((string) $this->request->getPost($cle)), 0, $max) ?: null;
            $corNom   = $texte('cor_nom');
            $corEmail = $texte('cor_email');
            $corTel   = $texte('cor_tel', 30);
            $nbAiresP = $this->request->getPost('nb_aires');
            $nbAires  = ($nbAiresP ?? '') !== '' ? min(100, max(0, (int) $nbAiresP)) : null;
            $note     = $texte('note', 2000);
            $saison   = getConfig('saison', '');

            // Transaction : club, salle, équipes et resynchronisation des rencontres à venir forment un tout.
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'UPDATE club SET CorNom=?, CorEmail=?, CorTelephone=?, NbAiresJeu=?, DesiderataNote=?, DesiderataSaison=?, DesiderataDate=NOW()
                     WHERE Id_Club=?'
                )->execute([$corNom, $corEmail, $corTel, $nbAires, $note, $saison, $club]);

                $salleNom   = $texte('salle_nom');
                $salleAdr   = $texte('salle_adresse');
                $salleCp    = $texte('salle_cp', 10);
                $salleVille = $texte('salle_ville');
                $salleTel   = $texte('salle_tel', 30);

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
                    // ArbitrageCRA est NOT NULL (booléen) : COALESCE(?, ArbitrageCRA) laisse la valeur
                    // en base inchangée quand le formulaire n'envoie rien (équipes hors R3M/R4M, champ
                    // absent du formulaire).
                    $stmtEq = $pdo->prepare(
                        'UPDATE equipe SET ReEngagement=?, JourSouhaite=?, ArbitrageCRA=COALESCE(?, ArbitrageCRA), DesiderataSaison=?
                         WHERE Id_Equipe=? AND Id_Club=?'
                    );
                    $stmtDivOf = $pdo->prepare('SELECT Division FROM equipe WHERE Id_Equipe=? AND Id_Club=?');
                    // Le souhait se fait normalement avant le début de phase ; un changement fait
                    // après ne doit s'appliquer qu'aux rencontres pas encore jouées, pas réécrire
                    // celles déjà passées.
                    $stmtRcResync = $pdo->prepare(
                        'UPDATE rencontre SET ArbitrageCRA=? WHERE Id_EquipeDom=? AND Date >= CURDATE()'
                    );
                    $stmtJaDemande = $pdo->prepare('UPDATE equipe SET JAdemande=? WHERE Id_Equipe=?');

                    foreach ($equipes as $eq) {
                        $idEquipe = (int) ($eq['id_equipe'] ?? 0);
                        if ($idEquipe <= 0) {
                            continue;
                        }

                        $re  = in_array($eq['reengagement'] ?? '', ['O', 'N'], true) ? $eq['reengagement'] : null;
                        $jr  = in_array($eq['jour'] ?? '', ['Samedi', 'Dimanche'], true) ? $eq['jour'] : null;
                        $sja    = in_array($eq['souhait_ja'] ?? '', ['CRA', 'Club'], true) ? $eq['souhait_ja'] : null;
                        $sjaInt = $sja === null ? null : ($sja === 'CRA' ? 1 : 0);

                        $stmtEq->execute([$re, $jr, $sjaInt, $saison, $idEquipe, $club]);

                        // Synchronise JAdemande et les rencontres à venir pour les équipes R3M/R4M.
                        if ($sja !== null) {
                            $stmtDivOf->execute([$idEquipe, $club]);
                            $divCode = (string) $stmtDivOf->fetchColumn();
                            if (in_array($divCode, ['R3M', 'R4M'], true)) {
                                $jademande = $sja === 'CRA' ? 1 : 0;
                                $stmtJaDemande->execute([$jademande, $idEquipe]);
                                $stmtRcResync->execute([$sjaInt, $idEquipe]);
                            }
                        }
                    }
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            return $this->response->setJSON(['ok' => true]);
        });
    }
}
