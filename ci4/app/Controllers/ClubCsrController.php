<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Club CSR (ES31), variante de la Gestion des clubs (EN27) pour le rôle CSR
 * (Commission Sportive Régionale) : même liste de clubs, sans la synchronisation FFTT ni le
 * filtre "Plusieurs salles", avec une case à cocher par ligne et un envoi groupé d'email aux
 * correspondants des clubs sélectionnés — message n°6 (Réengagements), le même que celui déjà
 * utilisé par EN12 (Désidératas clubs), éditable via EA93 où le rôle CSR n'a accès qu'à celui-ci.
 *
 * Accès rôle CSR ou Administrateur (filtre "csrauth").
 *
 * Pas de Model : mêmes raisons que ClubController — réutilise getPDO() directement.
 */
class ClubCsrController extends BaseController
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
            log_message('error', '[NIJAC] club_csr PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur base de données.')]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] club_csr : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, 'Erreur technique, voir le journal.')]);
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
                "SELECT CodeDept, nom FROM departement
                 ORDER BY CASE WHEN CodeDept IN ('2A','2B') THEN 20 ELSE CAST(CodeDept AS UNSIGNED) END, CodeDept"
            )->fetchAll(),
        ];

        return view('club_csr_index', $data);
    }

    public function liste(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            // L'unicité de Club.EquipeNom (uq_club_equipenom) est posée par initTableConfiguration() (EA98).

            // Tous les clubs par défaut (filtre "Club Régional" appliqué côté vue) — EstRegional
            // signale un club ayant au moins une équipe en Régionale (code Division commençant
            // par R, ex. R1M) ou Pré-Nationale (PN, ex. PNM) — codes définis dans la table
            // division, voir EA82/EA83.
            $rows = $pdo->query(
                "SELECT c.Id_Club, c.Nom, c.EquipeNom,
                        c.CorNom, c.CorEmail, c.CorTelephone,
                        EXISTS (
                            SELECT 1 FROM equipe e
                            JOIN division d ON d.Division = e.Division
                            WHERE e.Id_Club = c.Id_Club
                              AND (d.Division LIKE 'R%' OR d.Division LIKE 'PN%')
                        ) AS EstRegional
                 FROM Club c
                 ORDER BY c.Nom"
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

    /**
     * Envoi groupé du message système "CSR" aux correspondants des clubs sélectionnés — même
     * schéma que DesiderataClubsController::envoyer() (EN12), gabarit et destinataires différents.
     */
    public function envoyer(): ResponseInterface
    {
        return $this->tryJson(function () {
            $pdo = getPDO();
            $moi = $_SESSION['utilisateur'] ?? [];

            $ids = json_decode($this->request->getPost('ids') ?? '[]', true);
            $ids = is_array($ids) ? array_values(array_unique(array_filter($ids, 'is_string'))) : [];
            if (!$ids) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun club sélectionné.']);
            }

            $errRl = checkRateLimit(count($ids));
            if ($errRl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
            }

            // Message n°6 (Réengagements) : celui destiné aux correspondants de club, déjà utilisé
            // par EN12 (Désidératas clubs) — édité via EA93, où le rôle CSR n'a accès qu'à celui-ci.
            $msgRow = $pdo->query('SELECT Sujet, Message FROM messagerie WHERE Id_Messagerie = 6')->fetch();
            if (!$msgRow) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Modèle de message n°6 introuvable (à créer dans EA93 — Gestion des messages).']);
            }

            $base    = site_url('desiderata-club');
            $modeDev = isModeDeveloppement();

            $ph        = implode(',', array_fill(0, count($ids), '?'));
            $stmtClubs = $pdo->prepare("SELECT Id_Club, Nom, CorNom, CorEmail FROM Club WHERE Id_Club IN ($ph)");
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

                $vars = [
                    '{NOM_CLUB}'       => $c['Nom'],
                    '{CORR_NOM}'       => $c['CorNom'] ?? '',
                    '{URL_DESIDERATA}' => $base . '?club=' . urlencode(tokenDesiderataClub($c['Id_Club'])),
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

                    // Même message n°6 et même lien EN18 qu'EN12 : sans cette date, EN12 afficherait le club « jamais relancé ».
                    $pdo->prepare('UPDATE club SET DesiderataEmailDate = NOW() WHERE Id_Club = ?')->execute([$c['Id_Club']]);
                } catch (\Exception $e) {
                    error_log('[NIJAC] ES31 envoi ' . $c['Id_Club'] . ' : ' . $e->getMessage());
                    $erreurs[] = $c['Nom'] . ' : ' . messageErreur($e, "échec d'envoi");
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
