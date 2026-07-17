<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Fiche personnelle du Juge-Arbitre connecté (E030), portage CI4 de
 * JA/info_rencontre.php.
 *
 * Accès réservé aux JA connectés (rôle "JA"), OU au Nominateur/Admin consultant
 * la fiche d'un JA donné via un lien tokenisé (?ja=TOKEN, Obfuscator) — c'est
 * le seul écran où le rôle JA est autorisé. Pas de filtre de route "auth"
 * (celui-ci redirige justement tout rôle JA ailleurs) : la vérification de
 * session est refaite manuellement ici, comme le fait le legacy en incluant
 * auth_required.php avec $allowJa = true.
 */
class InfoRencontreController extends BaseController
{
    private \Obfuscator $obf;

    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../config/helpers.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';

        $this->obf = new \Obfuscator(OBFUSCATOR_SEED);
    }

    /**
     * Réplique la logique de sécurité + résolution d'Id_JA du fichier legacy :
     *   - rôle JA connecté → sa propre fiche (id_ja de session)
     *   - Administrateur/Nominateur + ?ja=TOKEN valide → fiche du JA ciblé
     *   - sinon → redirection
     *
     * @return array{redirect: string}|array{moi: array, idJa: int|string}
     */
    private function resolveContext(?string $jaTokenRaw): array
    {
        demarrerSessionNijac();

        if (!isset($_SESSION['utilisateur'])) {
            return ['redirect' => site_url('login')];
        }

        $moi       = $_SESSION['utilisateur'];
        $idJaToken = null;
        if ($jaTokenRaw !== null && $jaTokenRaw !== '') {
            $decoded = $this->obf->deobfuscate(trim($jaTokenRaw));
            if ($decoded > 0) {
                $idJaToken = $decoded;
            }
        }

        if (($moi['role'] ?? '') === 'JA') {
            $idJa = $moi['id_ja'] ?? null;
            if (!$idJa) {
                // Id_JA absent de la session (compte créé avant l'ajout de ce champ,
                // ou la correspondance par nom avait échoué à la connexion — voir
                // AuthController::index()) : on retente une résolution par nom ici
                // et on la met en cache en session, pour éviter de comparer plus bas
                // le login (une chaîne) à Id_JA (un entier) — ce qui ne matchait
                // jamais et déconnectait l'utilisateur à chaque navigation sur cet
                // écran, seul écran autorisé pour le rôle JA.
                $stmtIdJa = getPDO()->prepare('SELECT Id_JA FROM ja WHERE UPPER(TRIM(Nom)) = UPPER(TRIM(?)) LIMIT 1');
                $stmtIdJa->execute([$moi['login'] ?? '']);
                $idJa = $stmtIdJa->fetchColumn() ?: null;
                if ($idJa) {
                    $_SESSION['utilisateur']['id_ja'] = $idJa;
                }
            }
            if (!$idJa) {
                // Toujours introuvable : compte JA non lié à une fiche `ja` valide.
                // On termine la session pour éviter une boucle de redirection avec
                // AuthController (qui renverrait un utilisateur déjà connecté ici).
                session_destroy();

                return ['redirect' => site_url('login')];
            }
        } elseif (in_array($moi['role'] ?? '', ['Administrateur', 'Nominateur'], true) && $idJaToken) {
            $idJa = $idJaToken;
        } else {
            return ['redirect' => site_url('nominateur-menu')];
        }

        return ['moi' => $moi, 'idJa' => $idJa];
    }

    /**
     * Auto-désigne le JA connecté sur une rencontre de son club (arbitrage
     * club) et envoie la convocation par email.
     */
    private function designerJaPourRencontre(\PDO $pdo, $idJa, array $ja, int $idRencontre): array
    {
        if (!$idRencontre) {
            return ['ok' => false, 'msg' => 'Rencontre invalide.', 'id_rencontre' => $idRencontre];
        }

        $stmtR = $pdo->prepare(
            'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule,
                    dv.Division, RIGHT(dv.Division, 1) AS SexeCode,
                    ed.Nom AS NomDom, ev.Nom AS NomExt,
                    s.Nom AS SalleNom, s.Adresse AS SalleAdresse,
                    lps.CodePostal AS SalleCP, lps.Nom AS SalleVille,
                    cl.CorNom AS CorrNom, cl.CorEmail AS CorrEmail, cl.CorTelephone AS CorrTel
             FROM rencontre r
             JOIN division dv   ON dv.Id_Division = r.Id_Division
             JOIN equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
             LEFT JOIN equipe ev ON ev.Id_Equipe  = r.Id_EquipeExt
             LEFT JOIN salle s   ON s.Id_Salle    = r.Id_Salle
             LEFT JOIN laposte lps ON lps.Id_LaPoste = s.Id_Laposte
             LEFT JOIN Club cl   ON cl.Id_Club    = ed.Id_Club
             WHERE r.Id_Rencontre = ? AND ed.Id_Club = ?'
        );
        $stmtR->execute([$idRencontre, $ja['Id_Club']]);
        $renc = $stmtR->fetch();
        if (!$renc) {
            return ['ok' => false, 'msg' => 'Rencontre introuvable ou non autorisée.', 'id_rencontre' => $idRencontre];
        }

        $already = $pdo->prepare('SELECT Id_Nomination FROM nomination WHERE Id_Rencontre = ?');
        $already->execute([$idRencontre]);
        if ($already->fetch()) {
            return ['ok' => false, 'msg' => 'Un JA est déjà désigné pour cette rencontre.', 'id_rencontre' => $idRencontre];
        }

        $dispo = $pdo->prepare('SELECT Id_Disponible FROM disponible WHERE Id_JA = ? AND Id_Rencontre = ?');
        $dispo->execute([$idJa, $idRencontre]);
        $idDispo = $dispo->fetchColumn();
        if (!$idDispo) {
            $pdo->prepare("INSERT INTO disponible (Id_JA, Id_Rencontre, DateCompetition, Reponse, DateReponse) VALUES (?, ?, ?, 'P', CURDATE())")
                ->execute([$idJa, $idRencontre, $renc['Date']]);
            $idDispo = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('INSERT INTO nomination (Id_Rencontre, Id_Disponible, DateNomination, Valide, EmailEnvoye) VALUES (?, ?, CURDATE(), 1, 0)')
            ->execute([$idRencontre, $idDispo]);
        $idNomination = (int) $pdo->lastInsertId();

        $msgRow    = $pdo->query("SELECT Sujet, Message FROM messagerie WHERE Type = 'Convocation' LIMIT 1")->fetch();
        $emailSent = false;
        if ($msgRow && !empty($ja['Email'])) {
            $marqueurs = construireMarqueursMessage($ja, [], [
                'id_nomination' => $idNomination,
                'sexe_code'     => $renc['SexeCode']    ?? null,
                'date'          => $renc['Date']        ?? null,
                'heure'         => $renc['Heure']        ?? null,
                'journee'       => $renc['Journee']     ?? null,
                'poule'         => $renc['Poule']       ?? null,
                'division'      => $renc['Division']    ?? null,
                'dom'           => $renc['NomDom']      ?? null,
                'ext'           => $renc['NomExt']      ?? null,
                'salle_nom'     => $renc['SalleNom']     ?? null,
                'salle_adresse' => $renc['SalleAdresse'] ?? null,
                'salle_cp'      => $renc['SalleCP']      ?? null,
                'salle_ville'   => $renc['SalleVille']   ?? null,
                'corr_nom'      => $renc['CorrNom']      ?? null,
                'corr_email'    => $renc['CorrEmail']    ?? null,
                'corr_tel'      => $renc['CorrTel']      ?? null,
            ]);
            $rendu = remplacerMarqueursMessage($msgRow['Sujet'], $msgRow['Message'], $marqueurs);
            $sujet = $rendu['sujet'];
            $corps = $rendu['corps'];
            try {
                $dest    = getEmailDestinataire($ja['Email']);
                $isHtml  = strip_tags($corps) !== $corps;
                $mail    = getNijacMailer();
                $mail->isHTML($isHtml);
                $mail->addAddress($dest, $ja['Prenom'] . ' ' . $ja['Nom']);
                $modeDev = isModeDeveloppement();
                $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? "[DEV] $sujet" : $sujet;
                $mail->Body    = $corps;
                if ($isHtml) {
                    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
                }
                $mail->send();
                enregistrerEnvois(1);
                $pdo->prepare('UPDATE nomination SET EmailEnvoye = 1 WHERE Id_Nomination = ?')->execute([$idNomination]);
                $emailSent = true;
            } catch (\Exception $e) {
                log_message('error', '[NIJAC] info_rencontre se_designer mail : ' . $e->getMessage());
            }
        }

        $msg = $emailSent
            ? 'Désignation enregistrée. Un email de convocation vous a été envoyé.'
            : 'Désignation enregistrée (email non envoyé).';

        return ['ok' => true, 'msg' => $msg, 'id_rencontre' => $idRencontre];
    }

    public function index()
    {
        $jaTokenRaw = $this->request->getGet('ja') ?? '';
        $ctx        = $this->resolveContext($jaTokenRaw);
        if (isset($ctx['redirect'])) {
            session_write_close();

            return redirect()->to($ctx['redirect']);
        }
        $idJa = $ctx['idJa'];

        $pdo    = getPDO();
        $stmtJa = $pdo->prepare(
            'SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.Telephone, j.Grade,
                    j.Actif, j.Id_Club, j.Id_LaPoste, j.Defiscalisation, j.Nationale,
                    j.Classement, j.DateValidationFFTT,
                    COALESCE(j.Cp,    lp.CodePostal) AS CodePostal,
                    COALESCE(j.Ville, lp.Nom)        AS Ville,
                    cl.Nom AS NomClub
             FROM ja j
             LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
             LEFT JOIN Club    cl ON cl.Id_Club     = j.Id_Club
             WHERE j.Id_JA = :id_ja'
        );
        $stmtJa->execute([':id_ja' => $idJa]);
        $ja = $stmtJa->fetch();

        if (!$ja) {
            session_destroy();

            return redirect()->to(site_url('login'));
        }

        $nominations = $pdo->prepare(
            'SELECT n.Id_Nomination, r.Date AS DateRencontre, r.Heure AS HeureRencontre,
                    s.Nom AS NomSalle, s.Adresse AS AdresseSalle,
                    lps.CodePostal AS CpSalle, lps.Nom AS VilleSalle,
                    d.Division AS DivisionCode, d.Color AS DivisionColor,
                    ec.Nom AS NomClubDomicile,
                    ev.Nom AS NomClubVisiteur,
                    (r.Date >= CURDATE()) AS AVenir
             FROM Nomination n
             JOIN disponible dp ON dp.Id_Disponible = n.Id_Disponible
             JOIN Rencontre r ON r.Id_Rencontre = n.Id_Rencontre
             LEFT JOIN Salle s   ON s.Id_Salle   = r.Id_Salle
             LEFT JOIN laposte lps ON lps.Id_LaPoste = s.Id_LaPoste
             LEFT JOIN Division d  ON d.Id_Division = r.Id_Division
             LEFT JOIN equipe ede ON ede.Id_Equipe = r.Id_EquipeDom
             LEFT JOIN Club  ec  ON ec.Id_Club = ede.Id_Club
             LEFT JOIN equipe eve ON eve.Id_Equipe = r.Id_EquipeExt
             LEFT JOIN Club  ev  ON ev.Id_Club = eve.Id_Club
             WHERE dp.Id_JA = :id_ja
             ORDER BY (r.Date >= CURDATE()) DESC,
                      CASE WHEN r.Date >= CURDATE() THEN r.Date END ASC,
                      CASE WHEN r.Date <  CURDATE() THEN r.Date END DESC
             LIMIT 15'
        );
        $nominations->execute([':id_ja' => $idJa]);
        $prochaines = $nominations->fetchAll();

        $stmtR3R4 = $pdo->prepare(
            "SELECT r.Id_Rencontre, r.Date AS DateRencontre, r.Heure AS HeureRencontre,
                    r.Journee, r.Poule,
                    dv.Division AS DivisionCode, dv.Nom AS DivisionNom, dv.Color AS DivisionColor,
                    ed.Nom AS NomDom, ev.Nom AS NomExt,
                    COALESCE(s_r.Nom,  s_c.Nom)         AS NomSalle,
                    COALESCE(lp_r.CodePostal, lp_c.CodePostal) AS CpSalle,
                    COALESCE(lp_r.Nom,        lp_c.Nom)        AS VilleSalle,
                    d_n.Id_JA AS IdJaAffecte,
                    CONCAT(ja_n.Prenom, ' ', ja_n.Nom) AS NomJaAffecte
             FROM rencontre r
             JOIN division dv   ON dv.Id_Division = r.Id_Division
             JOIN equipe   ed   ON ed.Id_Equipe   = r.Id_EquipeDom
             LEFT JOIN equipe ev ON ev.Id_Equipe  = r.Id_EquipeExt
             LEFT JOIN salle   s_r  ON s_r.Id_Salle   = r.Id_Salle
             LEFT JOIN laposte lp_r ON lp_r.Id_LaPoste = s_r.Id_Laposte
             LEFT JOIN salle   s_c  ON s_c.Id_Club = ed.Id_Club AND s_c.EstPrincipale = 1
             LEFT JOIN laposte lp_c ON lp_c.Id_LaPoste = s_c.Id_Laposte
             LEFT JOIN nomination n   ON n.Id_Rencontre = r.Id_Rencontre
             LEFT JOIN disponible d_n ON d_n.Id_Disponible = n.Id_Disponible
             LEFT JOIN ja ja_n        ON ja_n.Id_JA     = d_n.Id_JA
             WHERE dv.Division IN ('R3M', 'R4M')
               AND ed.SouhaitJA = 'Club'
               AND ed.Id_Club = :id_club
               AND r.Date BETWEEN DATE_SUB(CURDATE(), INTERVAL 5 DAY) AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
             ORDER BY r.Date ASC, r.Heure ASC, dv.Ord ASC"
        );
        $stmtR3R4->execute([':id_club' => $ja['Id_Club']]);
        $rencontresR3R4 = $stmtR3R4->fetchAll();

        session_write_close();

        return view('info_rencontre_index', [
            'ja'             => $ja,
            'idJa'           => $idJa,
            'jaToken'        => $jaTokenRaw,
            'prochaines'     => $prochaines,
            'rencontresR3R4' => $rencontresR3R4,
        ]);
    }

    public function seDesigner(): ResponseInterface
    {
        $jaTokenRaw = $this->request->getPost('ja') ?? $this->request->getGet('ja') ?? '';
        $ctx        = $this->resolveContext($jaTokenRaw);
        if (isset($ctx['redirect'])) {
            session_write_close();

            return redirect()->to($ctx['redirect']);
        }
        $idJa = $ctx['idJa'];

        session_write_close();

        try {
            $pdo = getPDO();

            $stmtJa = $pdo->prepare('SELECT Id_JA, Nom, Prenom, Email, Id_Club FROM ja WHERE Id_JA = ?');
            $stmtJa->execute([$idJa]);
            $ja = $stmtJa->fetch();
            if (!$ja) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'JA introuvable.']);
            }

            $ids = json_decode($this->request->getPost('ids') ?? '[]', true);
            if (!is_array($ids) || !$ids) {
                $idUnique = (int) ($this->request->getPost('id_rencontre') ?? 0);
                $ids      = $idUnique ? [$idUnique] : [];
            }
            if (!$ids) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Aucune rencontre sélectionnée.']);
            }

            $resultats = [];
            foreach (array_map('intval', $ids) as $idRencontre) {
                $resultats[] = $this->designerJaPourRencontre($pdo, $idJa, $ja, $idRencontre);
            }

            $nbOk = count(array_filter($resultats, fn ($r) => $r['ok']));

            return $this->response->setJSON(['ok' => $nbOk > 0, 'resultats' => $resultats, 'id_ja' => $idJa]);
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] info_rencontre.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            log_message('error', '[NIJAC] info_rencontre.php : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    public function rechercheLaposte(): ResponseInterface
    {
        $jaTokenRaw = $this->request->getPost('ja') ?? $this->request->getGet('ja') ?? '';
        $ctx        = $this->resolveContext($jaTokenRaw);
        if (isset($ctx['redirect'])) {
            session_write_close();

            return redirect()->to($ctx['redirect']);
        }

        session_write_close();

        try {
            $pdo   = getPDO();
            $cp    = trim($this->request->getPost('cp') ?? '');
            $ville = normaliserVille($this->request->getPost('ville') ?? '');

            if ($cp === '' && $ville === '') {
                return $this->response->setJSON(['ok' => false, 'msg' => 'CP et ville vides.']);
            }

            if ($cp !== '' && $ville !== '') {
                $stmt = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? AND REPLACE(REPLACE(Nom,'-',' '),'SAINT ','ST ') LIKE ? LIMIT 1");
                $stmt->execute([$cp, $ville . '%']);
                $row = $stmt->fetch();
                if ($row) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $row['Id_LaPoste'], 'cp' => $row['CodePostal'], 'ville' => $row['Nom']]);
                }
            }
            if ($cp !== '') {
                $rows = $pdo->prepare('SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE CodePostal = ? ORDER BY Nom');
                $rows->execute([$cp]);
                $rows = $rows->fetchAll();
                if (count($rows) === 1) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                }
                if (count($rows) > 1) {
                    return $this->response->setJSON(['ok' => true, 'multi' => true, 'suggestions' => array_map(fn ($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows)]);
                }
            }
            if ($ville !== '') {
                $rows = $pdo->prepare("SELECT Id_LaPoste, CodePostal, Nom FROM laposte WHERE REPLACE(REPLACE(Nom,'-',' '),'SAINT ','ST ') LIKE ? ORDER BY CodePostal, Nom LIMIT 20");
                $rows->execute([$ville . '%']);
                $rows = $rows->fetchAll();
                if (count($rows) === 1) {
                    return $this->response->setJSON(['ok' => true, 'id_laposte' => $rows[0]['Id_LaPoste'], 'cp' => $rows[0]['CodePostal'], 'ville' => $rows[0]['Nom']]);
                }
                if (count($rows) > 1) {
                    return $this->response->setJSON(['ok' => true, 'multi' => true, 'suggestions' => array_map(fn ($r) => ['id_laposte' => $r['Id_LaPoste'], 'cp' => $r['CodePostal'], 'ville' => $r['Nom']], $rows)]);
                }
            }

            return $this->response->setJSON(['ok' => false, 'msg' => 'Commune non trouvée.']);
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] info_rencontre.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        }
    }

    public function sauvegarderAdresse(): ResponseInterface
    {
        $jaTokenRaw = $this->request->getPost('ja') ?? $this->request->getGet('ja') ?? '';
        $ctx        = $this->resolveContext($jaTokenRaw);
        if (isset($ctx['redirect'])) {
            session_write_close();

            return redirect()->to($ctx['redirect']);
        }
        $idJa = $ctx['idJa'];

        session_write_close();

        try {
            $pdo       = getPDO();
            $idLaPoste = ($this->request->getPost('id_laposte') ?? '') !== '' ? (int) $this->request->getPost('id_laposte') : null;
            $cp        = trim($this->request->getPost('cp') ?? '');
            $ville     = trim($this->request->getPost('ville') ?? '');

            if (!$idLaPoste) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Sélectionnez une commune valide.']);
            }

            $pdo->prepare('UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?')
                ->execute([$idLaPoste, $cp ?: null, $ville ?: null, $idJa]);

            return $this->response->setJSON(['ok' => true, 'cp' => $cp, 'ville' => $ville]);
        } catch (\PDOException $e) {
            log_message('error', '[NIJAC] info_rencontre.php PDO : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        }
    }
}
