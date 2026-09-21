<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Suivi des nominations (EN28).
 *
 * Liste des nominations validées du périmètre du nominateur avec les frais saisis
 * par le JA (péage, kilomètres, défiscalisation — renseignés depuis EN21) et un
 * bouton de rappel par ligne (masqué une fois les frais saisis) : renvoie au JA le modèle « Convocation » de la table
 * messagerie (lien EN21 vers sa convocation / note de frais).
 *
 * Accès Nominateur ou Administrateur (filtre "auth").
 */
class SuiviNominationController extends BaseController
{
    private const ID_MESSAGE_CONVOCATION = 3;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    private function deptsAutorises(): array
    {
        return getDepartementsAutorises($_SESSION['utilisateur']['id_departement'] ?? null);
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('suivi_nomination_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
        ]);
    }

    public function data(): ResponseInterface
    {
        try {
            $depts = $this->deptsAutorises();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'nominations' => []]);
            }

            $pdo  = getPDO();
            $stmt = $pdo->prepare('
                SELECT n.Id_Nomination, ja.Id_JA, r.Date, r.Heure, r.ArbitrageCRA,
                       ed.Division, dv.Color AS DivisionColor, ed.Nom AS NomDom, ee.Nom AS NomExt,
                       CONCAT(ja.Prenom, \' \', ja.Nom) AS NomJa, ja.Email AS EmailJa, ja.NumCompteEBP,
                       n.Peage, n.Kilometre, n.Defiscalisation, n.DateSaisie
                FROM nomination n
                JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
                JOIN ja            ON ja.Id_JA        = d.Id_JA
                JOIN rencontre r   ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN equipe ed     ON ed.Id_Equipe    = r.Id_EquipeDom
                JOIN division dv   ON dv.Division     = ed.Division
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                WHERE n.Valide = 1
                  AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                ORDER BY r.Date DESC, r.Heure, n.Id_Nomination
            ');
            $stmt->execute($depts);

            return $this->response->setJSON(['ok' => true, 'nominations' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /** JA actifs du périmètre du nominateur, pour la liste déroulante de la popup de modification. */
    public function jaListe(): ResponseInterface
    {
        try {
            $depts = $this->deptsAutorises();
            if (!$depts) {
                return $this->response->setJSON(['ok' => true, 'ja' => []]);
            }
            $stmt = getPDO()->prepare('
                SELECT Id_JA, Nom, Prenom FROM ja
                WHERE Actif = 1 AND CodeDept IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                ORDER BY Nom, Prenom
            ');
            $stmt->execute($depts);

            return $this->response->setJSON(['ok' => true, 'ja' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Correction d'une nomination depuis EN28 : Arbitrage (rencontre.ArbitrageCRA), JA,
     * péage, kilomètres, défiscalisation. DateSaisie prend la date du jour (le compte EBP
     * se modifie dans la fiche JA, EN11). Le changement de JA garde la nomination (Valide, EmailEnvoye,
     * DateNomination inchangés) et applique la règle de 2 nominations max par JA et par jour.
     */
    public function modifier(): ResponseInterface
    {
        try {
            $pdo      = getPDO();
            $depts    = $this->deptsAutorises();
            $idNom    = (int) $this->request->getPost('id_nomination');
            $idJa     = (int) $this->request->getPost('id_ja');
            $arbCra   = $this->request->getPost('arbitrage') === '0' ? 0 : 1;
            $peageRaw = trim((string) $this->request->getPost('peage'));
            $kmRaw    = trim((string) $this->request->getPost('km'));
            $defisc   = $this->request->getPost('defisc') ? 1 : 0;

            if ($idNom <= 0 || $idJa <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres invalides.']);
            }
            $peage = str_replace(',', '.', $peageRaw === '' ? '0' : $peageRaw);
            if (!is_numeric($peage) || (float) $peage < 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Péage invalide.']);
            }
            if (!ctype_digit($kmRaw === '' ? '0' : $kmRaw)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Kilomètres invalides (entier positif).']);
            }
            $km = (int) $kmRaw;

            $stmt = $pdo->prepare('
                SELECT n.Id_Nomination, n.Id_Rencontre, n.Id_Disponible, d.Id_JA AS IdJaActuel, r.Date, ed.Id_Club AS IdClubDom
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN rencontre r  ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN equipe ed    ON ed.Id_Equipe    = r.Id_EquipeDom
                WHERE n.Id_Nomination = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
            ');
            $stmt->execute([$idNom, ...$depts]);
            $nom = $stmt->fetch();
            if (!$nom) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination introuvable.']);
            }

            $pdo->beginTransaction();
            try {
                $idDispo = (int) $nom['Id_Disponible'];
                if ($idJa !== (int) $nom['IdJaActuel']) {
                    $ja = $pdo->prepare('SELECT Actif FROM ja WHERE Id_JA = ?');
                    $ja->execute([$idJa]);
                    if ((int) $ja->fetchColumn() !== 1) {
                        throw new \RuntimeException('Juge-arbitre introuvable ou inactif.');
                    }
                    $nb = $pdo->prepare('
                        SELECT COUNT(*) AS nb, COALESCE(SUM(ed2.Id_Club <> ?), 0) AS autres_clubs
                        FROM nomination n
                        JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
                        JOIN rencontre r2  ON r2.Id_Rencontre = n.Id_Rencontre
                        JOIN equipe   ed2  ON ed2.Id_Equipe   = r2.Id_EquipeDom
                        WHERE d.Id_JA = ? AND n.Id_Rencontre != ? AND r2.Date = ?
                    ');
                    $nb->execute([$nom['IdClubDom'], $idJa, $nom['Id_Rencontre'], $nom['Date']]);
                    $deja = $nb->fetch();
                    if ((int) $deja['nb'] >= 2) {
                        throw new \RuntimeException('Ce JA a déjà 2 nominations ce jour-là (maximum).');
                    }
                    if ((int) $deja['autres_clubs'] > 0) {
                        throw new \RuntimeException('Ce JA est déjà nommé ce jour-là sur une rencontre d\'un autre club.');
                    }

                    // disponible (JA, rencontre) : réutilisée si le JA a répondu O/P, rouverte en P s'il avait répondu N.
                    $d = $pdo->prepare('SELECT Id_Disponible, Reponse FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
                    $d->execute([$idJa, $nom['Id_Rencontre']]);
                    $dispo = $d->fetch();
                    $note  = 'Juge-arbitre modifié depuis EN28 le ' . date('d/m/Y');
                    if ($dispo) {
                        $idDispo = (int) $dispo['Id_Disponible'];
                        if ($dispo['Reponse'] === 'N') {
                            $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), Note = ? WHERE Id_Disponible = ?")
                                ->execute([$note, $idDispo]);
                        }
                    } else {
                        $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
                            ->execute([$idJa, $nom['Id_Rencontre'], $nom['Date'], $note]);
                        $idDispo = (int) $pdo->lastInsertId();
                    }
                }

                $pdo->prepare('UPDATE rencontre SET ArbitrageCRA = ? WHERE Id_Rencontre = ?')->execute([$arbCra, $nom['Id_Rencontre']]);
                $pdo->prepare('
                    UPDATE nomination SET Id_Disponible = ?, Peage = ?, Kilometre = ?, Defiscalisation = ?, DateSaisie = CURDATE()
                    WHERE Id_Nomination = ?
                ')->execute([$idDispo, $peage, $km, $defisc, $idNom]);

                $pdo->commit();
            } catch (\RuntimeException $e) {
                $pdo->rollBack();

                return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            return $this->response->setJSON(['ok' => true, 'msg' => 'Nomination modifiée.']);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Modification impossible : ' . $e->getMessage()]);
        }
    }

    /** Renvoie au JA de la nomination le modèle « Convocation » (lien EN21 vers ses frais). */
    public function rappel(): ResponseInterface
    {
        try {
            $pdo   = getPDO();
            $idNom = (int) $this->request->getPost('id_nomination');
            $depts = $this->deptsAutorises();
            if ($idNom <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination invalide.']);
            }

            $stmt = $pdo->prepare('
                SELECT n.Id_Nomination, ja.Id_JA, ja.Nom, ja.Prenom, ja.Email,
                       ed.Nom AS NomDom, ee.Nom AS NomExt, cl.Nom AS NomClub,
                       r.Date, r.Heure, r.Journee, r.Poule, ed.Division, RIGHT(ed.Division, 1) AS SexeCode
                FROM nomination n
                JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
                JOIN ja            ON ja.Id_JA        = d.Id_JA
                JOIN rencontre r   ON r.Id_Rencontre  = n.Id_Rencontre
                JOIN equipe ed     ON ed.Id_Equipe    = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                LEFT JOIN Club cl  ON cl.Id_Club      = ed.Id_Club
                WHERE n.Id_Nomination = ? AND n.Valide = 1 AND n.DateSaisie IS NULL
                  AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
            ');
            $stmt->execute([$idNom, ...$depts]);
            $nom = $stmt->fetch();
            if (!$nom) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination introuvable ou frais déjà saisis.']);
            }
            if (empty($nom['Email'])) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Ce JA n\'a pas d\'adresse email.']);
            }

            $moi = $_SESSION['utilisateur'] ?? [];
            $tpl = resoudreModeleMessagerie($pdo, self::ID_MESSAGE_CONVOCATION, (int) ($moi['id'] ?? 0));
            if (!$tpl) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Modèle de message introuvable (messagerie n°' . self::ID_MESSAGE_CONVOCATION . ').']);
            }

            $marqueurs = construireMarqueursMessage($nom, $moi, [
                'id_nomination' => $nom['Id_Nomination'],
                'sexe_code'     => $nom['SexeCode'],
                'date'          => $nom['Date'],
                'heure'         => $nom['Heure'],
                'journee'       => $nom['Journee'],
                'poule'         => $nom['Poule'],
                'division'      => $nom['Division'],
                'dom'           => $nom['NomDom'],
                'ext'           => $nom['NomExt'],
                'nom_club'      => $nom['NomClub'],
            ]);
            $rendu = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);
            $corps = $rendu['corps'];
            if (str_contains($corps, 'data:image/')) {
                $corps = preg_replace('/src="data:image\/[^;]+;base64,[^"]*"/', 'src=""', $corps); // antispam
            }

            $dest   = getEmailDestinataire($nom['Email']);
            $isHtml = strip_tags($corps) !== $corps;
            $nomMoi = trim(($moi['prenom'] ?? '') . ' ' . ($moi['nom'] ?? ''));
            $mail   = getNijacMailer();
            $mail->isHTML($isHtml);
            $mail->addAddress($dest, $nom['Prenom'] . ' ' . $nom['Nom']);
            if (!empty($tpl['Cc']) && !empty($moi['email'])) {
                $mail->addCC(getEmailDestinataire($moi['email']), $nomMoi);
            }
            if (!empty($tpl['ReplyTo']) && !empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], $nomMoi);
            }
            $mail->Subject = (isModeDeveloppement() && $dest !== $nom['Email'])
                ? "[DEV → {$nom['Email']}] {$rendu['sujet']}" : $rendu['sujet'];
            $mail->Body = $corps;
            if ($isHtml) {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
            }
            $mail->send();

            return $this->response->setJSON(['ok' => true, 'msg' => "Rappel envoyé à {$nom['Prenom']} {$nom['Nom']}."]);
        } catch (\Throwable $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Envoi impossible : ' . $e->getMessage()]);
        }
    }
}
