<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Suivi des nominations (EN28).
 *
 * Liste de toutes les rencontres du périmètre du nominateur (nommées ou non) avec les frais saisis
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

    /** Journalise l'exception et renvoie un message générique (pas de SQL / chemin côté navigateur). */
    private function erreurTechnique(\Throwable $e, string $action, string $msg): ResponseInterface
    {
        error_log("[NIJAC] EN28 $action : " . $e->getMessage());

        return $this->response->setJSON(['ok' => false, 'msg' => messageErreur($e, $msg)]);
    }

    public function index()
    {
        $u = $_SESSION['utilisateur'] ?? [];

        return view('suivi_nomination_index', [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'divisionNoms' => getDivisionNoms(),
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
            // Toutes les rencontres du périmètre (même critère qu'EN14 : club recevant), nommées ou non.
            // Une nomination au plus par rencontre (uq_nomination_rencontre) → une ligne par rencontre ;
            // sans nomination, les colonnes n.* / ja.* sont NULL (ligne « Aucun JA »).
            $stmt = $pdo->prepare('
                SELECT r.Id_Rencontre, n.Id_Nomination, ja.Id_JA, r.Date, r.Heure, r.ArbitrageCRA,
                       ed.Division, dv.Color AS DivisionColor, ed.Id_Club AS IdClubDom, ed.Nom AS NomDom, ee.Nom AS NomExt,
                       CONCAT(ja.Prenom, \' \', ja.Nom) AS NomJa, ja.Email AS EmailJa, ja.NumCompteEBP, ja.Telephone,
                       n.Peage, n.Kilometre, n.Defiscalisation, n.DateSaisie, n.Valide,
                       cl.Nom AS NomClub, cl.CorNom, cl.CorEmail, cl.RefNom, cl.RefMail
                FROM rencontre r
                JOIN equipe ed     ON ed.Id_Equipe    = r.Id_EquipeDom
                JOIN division dv   ON dv.Division     = ed.Division
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                LEFT JOIN club cl   ON cl.Id_Club     = ed.Id_Club   -- destinataires de « Relancer le club »
                -- Toute nomination est valide par défaut (Valide = 1) ; le OR couvre les
                -- arbitrages club restés à 0 avant la migration EA98.
                LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre AND (n.Valide = 1 OR r.ArbitrageCRA = 0)
                LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                LEFT JOIN ja           ON ja.Id_JA        = d.Id_JA
                WHERE SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                ORDER BY r.Date DESC, r.Heure, r.Id_Rencontre
            ');
            $stmt->execute($depts);

            return $this->response->setJSON(['ok' => true, 'nominations' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'data', 'Liste indisponible.');
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
                SELECT Id_JA, Nom, Prenom, Id_Club FROM ja
                WHERE JA1 = 1 AND CodeDept IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
                ORDER BY Nom, Prenom
            ');
            $stmt->execute($depts);

            return $this->response->setJSON(['ok' => true, 'ja' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'jaListe', 'Liste des JA indisponible.');
        }
    }

    /**
     * Correction d'une nomination depuis EN28 : Arbitrage (rencontre.ArbitrageCRA), JA,
     * péage, kilomètres, défiscalisation. DateSaisie prend la date du jour (le compte EBP
     * se modifie dans la fiche JA, EN11). Le changement de JA garde la nomination (EmailEnvoye,
     * DateNomination inchangés ; Valide forcé à 1 — un JA nommé = nomination valide d'office) et applique la règle de 2 nominations max par JA et par jour.
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
                    $ja = $pdo->prepare('SELECT JA1 FROM ja WHERE Id_JA = ?');
                    $ja->execute([$idJa]);
                    if ((int) $ja->fetchColumn() !== 1) {
                        throw new \RuntimeException('Juge-arbitre introuvable ou inactif.');
                    }
                    $this->controlerJourJa($pdo, $idJa, (int) $nom['Id_Rencontre'], $nom['Date'], $nom['IdClubDom']);

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
                    UPDATE nomination SET Id_Disponible = ?, Peage = ?, Kilometre = ?, Defiscalisation = ?, DateSaisie = CURDATE(), Valide = 1
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
            return $this->erreurTechnique($e, 'modifier', 'Modification impossible.');
        }
    }

    /**
     * Règle EN14/EN25 : 2 nominations max par JA et par date, et uniquement sur le même club
     * recevant. Lève une RuntimeException (message affichable) si $idJa ne peut pas être nommé.
     */
    private function controlerJourJa(\PDO $pdo, int $idJa, int $idRencontre, string $date, string $idClubDom): void
    {
        $nb = $pdo->prepare('
            SELECT COUNT(*) AS nb, COALESCE(SUM(ed2.Id_Club <> ?), 0) AS autres_clubs
            FROM nomination n
            JOIN disponible d  ON d.Id_Disponible = n.Id_Disponible
            JOIN rencontre r2  ON r2.Id_Rencontre = n.Id_Rencontre
            JOIN equipe   ed2  ON ed2.Id_Equipe   = r2.Id_EquipeDom
            WHERE d.Id_JA = ? AND n.Id_Rencontre != ? AND r2.Date = ?
        ');
        $nb->execute([$idClubDom, $idJa, $idRencontre, $date]);
        $deja = $nb->fetch();
        if ((int) $deja['nb'] >= 2) {
            throw new \RuntimeException('Ce JA a déjà 2 nominations ce jour-là (maximum).');
        }
        if ((int) $deja['autres_clubs'] > 0) {
            throw new \RuntimeException('Ce JA est déjà nommé ce jour-là sur une rencontre d\'un autre club.');
        }
    }

    /**
     * « Saisir le JA » : le nominateur enregistre le JA qui a officié sur une rencontre en
     * arbitrage club (ArbitrageCRA = 0) restée sans réponse du club. Même création qu'EN25
     * (ArbitreClubController::enregistrer) : disponible 'P' + nomination Valide = 1,
     * EmailEnvoye = 0 — aucun email envoyé.
     */
    public function saisir(): ResponseInterface
    {
        try {
            $peage = str_replace(',', '.', trim((string) $this->request->getPost('peage')) ?: '0');
            $kmRaw = trim((string) $this->request->getPost('km')) ?: '0';
            if (!is_numeric($peage) || (float) $peage < 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Péage invalide.']);
            }
            if (!ctype_digit($kmRaw)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Kilomètres invalides (entier positif).']);
            }

            $this->creerNominationClub(
                getPDO(),
                $this->deptsAutorises(),
                (int) $this->request->getPost('id_rencontre'),
                (int) $this->request->getPost('id_ja'),
                $peage,
                (int) $kmRaw,
                $this->request->getPost('defisc') ? 1 : 0
            );

            return $this->response->setJSON(['ok' => true, 'msg' => 'Juge-arbitre enregistré.']);
        } catch (\RuntimeException $e) {
            return $this->response->setJSON(['ok' => false, 'msg' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'saisir', 'Enregistrement impossible.');
        }
    }

    /** Cœur de saisir() (sans requête HTTP). Refus métier : RuntimeException. */
    private function creerNominationClub(\PDO $pdo, array $depts, int $idRenc, int $idJa, string $peage, int $km, int $defisc): void
    {
        if ($idRenc <= 0 || $idJa <= 0 || !$depts) {
            throw new \RuntimeException('Paramètres invalides.');
        }
        $in = implode(',', array_fill(0, count($depts), '?'));

        // Périmètre : même critère que data() (club recevant).
        $stmt = $pdo->prepare("
            SELECT r.Id_Rencontre, r.Date, r.ArbitrageCRA, ed.Id_Club AS IdClubDom,
                   (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS NbNom
            FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            WHERE r.Id_Rencontre = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN ($in)
        ");
        $stmt->execute([$idRenc, ...$depts]);
        $rc = $stmt->fetch();
        if (!$rc) {
            throw new \RuntimeException('Rencontre introuvable ou hors de votre périmètre.');
        }
        if ($rc['ArbitrageCRA'] === null || (int) $rc['ArbitrageCRA'] !== 0) {
            throw new \RuntimeException('Rencontre en arbitrage CRA : la nomination se fait dans EN14.');
        }
        if ((int) $rc['NbNom'] > 0) {
            throw new \RuntimeException('Un JA est déjà désigné pour cette rencontre : utilisez « Modifier ».');
        }

        $ja = $pdo->prepare("SELECT 1 FROM ja WHERE Id_JA = ? AND JA1 = 1 AND CodeDept IN ($in)");
        $ja->execute([$idJa, ...$depts]);
        if (!$ja->fetchColumn()) {
            throw new \RuntimeException('Juge-arbitre introuvable, inactif ou hors de votre périmètre.');
        }

        $pdo->beginTransaction();
        try {
            $this->controlerJourJa($pdo, $idJa, $idRenc, $rc['Date'], $rc['IdClubDom']);

            // disponible (JA, rencontre) réutilisée si elle existe (comme EN25), sinon créée en 'P'.
            $d = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
            $d->execute([$idJa, $idRenc]);
            $idDispo = $d->fetchColumn();
            $note    = 'Juge-arbitre saisi depuis EN28 (arbitrage club) le ' . date('d/m/Y');
            if ($idDispo) {
                $pdo->prepare("UPDATE disponible SET Reponse = 'P', DateReponse = CURDATE(), DateCompetition = ?, Note = ? WHERE Id_Disponible = ?")
                    ->execute([$rc['Date'], $note, $idDispo]);
            } else {
                $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse, Note) VALUES (?, ?, ?, 'P', CURDATE(), ?)")
                    ->execute([$idJa, $idRenc, $rc['Date'], $note]);
                $idDispo = (int) $pdo->lastInsertId();
            }

            // uq_nomination_rencontre bloque une saisie concurrente (club via EN25, autre nominateur).
            $pdo->prepare(
                'INSERT INTO nomination (Id_Rencontre, Id_Disponible, Peage, Kilometre, Defiscalisation, DateSaisie, Valide, EmailEnvoye)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), 1, 0)'
            )->execute([$idRenc, $idDispo, $peage, $km, $defisc]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                throw new \RuntimeException('Un JA vient d\'être désigné pour cette rencontre : rechargez la liste.');
            }
            throw $e;
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

            $errRl = checkRateLimit(1);   // même garde-fou d'envoi que le Centre d'envoi (EN15)
            if ($errRl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
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
            enregistrerEnvois(1);

            // Affiché dans un toast HTML côté client : nom du JA échappé.
            return $this->response->setJSON(['ok' => true, 'msg' => 'Rappel envoyé à ' . htmlspecialchars($nom['Prenom'] . ' ' . $nom['Nom']) . '.']);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'rappel', 'Envoi impossible (voir le journal des erreurs).');
        }
    }

    /**
     * « Relancer le club » : renvoie au club recevant d'une rencontre en arbitrage club
     * encore sans JA le message n°7 (lien EN25), via envoyerDemandeJaClub() partagée avec EN14.
     */
    public function relanceClub(): ResponseInterface
    {
        try {
            $pdo    = getPDO();
            $idRenc = (int) $this->request->getPost('id_rencontre');
            $depts  = $this->deptsAutorises();
            if ($idRenc <= 0 || !$depts) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre invalide.']);
            }

            // Périmètre du nominateur : même critère que data() (club recevant). Arbitrage club
            // et email du correspondant sont vérifiés par envoyerDemandeJaClub().
            $stmt = $pdo->prepare('
                SELECT (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS NbNom
                FROM rencontre r
                JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                WHERE r.Id_Rencontre = ? AND SUBSTRING(ed.Id_Club, 3, 2) IN (' . implode(',', array_fill(0, count($depts), '?')) . ')
            ');
            $stmt->execute([$idRenc, ...$depts]);
            $rc = $stmt->fetch();
            if (!$rc) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Rencontre introuvable ou hors de votre périmètre.']);
            }
            if ((int) $rc['NbNom'] > 0) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Le club a déjà répondu : un JA est désigné pour cette rencontre.']);
            }

            $errRl = checkRateLimit(1);   // même garde-fou d'envoi que le rappel au JA
            if ($errRl !== null) {
                return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
            }

            $res = envoyerDemandeJaClub($pdo, $idRenc, $_SESSION['utilisateur'] ?? []);
            if ($res['ok']) {
                enregistrerEnvois(1);
            }

            // Affiché dans un toast HTML côté client : nom du correspondant échappé.
            return $this->response->setJSON(['ok' => $res['ok'], 'msg' => htmlspecialchars($res['msg'])]);
        } catch (\Throwable $e) {
            return $this->erreurTechnique($e, 'relanceClub', 'Envoi impossible (voir le journal des erreurs).');
        }
    }
}
