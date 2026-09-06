<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Centre d'envoi de messages (EN15), portage CI4 de Nominateur/centrenvoye.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * envoie les 4 types de messages (Disponibilités, Rappel dispo, Convocation,
 * Liste nomination) + Demande adresse aux JA1 actifs du département connecté.
 *
 * Pas de Model : requêtes dynamiques (jointures multiples selon le type,
 * substitution de marqueurs, envoi PHPMailer, rate limiting) trop éloignées
 * du Query Builder simple — réutilise getPDO() directement, comme le fichier
 * legacy.
 */
class CentrenvoyeController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
        require_once __DIR__ . '/../../../Classes/Obfuscator.php';
    }

    private function moi(): array
    {
        return $_SESSION['utilisateur'] ?? [];
    }

    private function dept(): string
    {
        return str_pad((string) ($this->moi()['id_departement'] ?? '76'), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Départements dont ce nominateur gère les rencontres, règle région incluse
     * (getDepartementsAutorises : ex. 76 ⇒ 76 + 27). Codes sur 2 chiffres pour
     * comparer à SUBSTRING(ed.Id_Club, 3, 2).
     */
    private function deptsAutorises(): array
    {
        $depts = array_map(
            static fn ($d) => str_pad((string) $d, 2, '0', STR_PAD_LEFT),
            getDepartementsAutorises($this->moi()['id_departement'] ?? null)
        );

        return $depts ?: [$this->dept()];
    }

    private function obfuscator(): \Obfuscator
    {
        return new \Obfuscator(OBFUSCATOR_SEED);
    }

    public function index()
    {
        $moi = $this->moi();
        $pdo = getPDO();

        $modeles = [];
        $rows    = $pdo->query('SELECT Type, Sujet, Message, Cc, ReplyTo FROM messagerie ORDER BY Id_Messagerie')->fetchAll();
        foreach ($rows as $r) {
            if (!isset($modeles[$r['Type']])) {
                $modeles[$r['Type']] = ['sujet' => $r['Sujet'], 'message' => $r['Message'], 'cc' => (bool) $r['Cc'], 'replyto' => (bool) $r['ReplyTo']];
            }
        }

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'dept'        => $this->dept(),
            'modeles'     => $modeles,
            'monEmail'    => $moi['email'] ?? '',
        ];

        return view('centrenvoye_index', $data);
    }

    public function journees(): ResponseInterface
    {
        $pdo    = getPDO();
        $saison = getConfig('saison') ?: null;

        if (!$saison) {
            return $this->response->setJSON(['ok' => true, 'data' => []]);
        }

        // Mêmes filtres JA que la liste de convocation (cas "Convocation" de ja()) :
        // JA1 actifs, sans filtre sur nomination.Valide — sinon le combo affiche 0
        // tant que la journée n'a pas été validée dans EN14 alors que la liste, elle,
        // montre déjà le JA nominé.
        $depts = $this->deptsAutorises();
        $ph    = implode(',', array_fill(0, count($depts), '?'));
        $stmt = $pdo->prepare("
            SELECT r.Journee, r.Date,
                   COUNT(DISTINCT j.Id_JA) AS NbJA
            FROM rencontre r
            JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
            LEFT JOIN nomination n  ON n.Id_Rencontre   = r.Id_Rencontre
            LEFT JOIN disponible d  ON d.Id_Disponible  = n.Id_Disponible
            LEFT JOIN ja j          ON j.Id_JA = d.Id_JA AND j.Actif = 1 AND j.Grade = 'JA1'
            WHERE SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
            GROUP BY r.Journee, r.Date
            ORDER BY r.Date, r.Journee
        ");
        $stmt->execute($depts);
        $rows = $stmt->fetchAll();

        return $this->response->setJSON(['ok' => true, 'data' => $rows, 'saison' => $saison]);
    }

    public function ja(): ResponseInterface
    {
        $pdo     = getPDO();
        $dept    = $this->dept();
        $saison  = getConfig('saison') ?: null;
        $type    = $this->request->getGet('type') ?? 'Disponibilites';
        $journee = (int) ($this->request->getGet('journee') ?? 0);
        $date    = trim((string) ($this->request->getGet('date') ?? ''));

        switch ($type) {
            // ── Disponibilités : tous JA1 actifs du dept ────────────────
            case 'Disponibilites':
                $stmt = $pdo->prepare("
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           lp.CodePostal AS CP
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    WHERE j.Actif = 1
                      AND j.Grade = 'JA1'
                      AND j.CodeDept = ?
                    ORDER BY j.Nom, j.Prenom
                ");
                $stmt->execute([$dept]);
                $rows = $stmt->fetchAll();
                break;

            // ── Rappel dispo : JA1 sans dispo dans la saison courante ───
            case 'Rappel dispo':
                $stmt = $pdo->prepare("
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           lp.CodePostal AS CP
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    WHERE j.Actif = 1
                      AND j.Grade = 'JA1'
                      AND j.CodeDept = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM disponible d
                          WHERE d.Id_JA = j.Id_JA
                      )
                    ORDER BY j.Nom, j.Prenom
                ");
                $stmt->execute([$dept]);
                $rows = $stmt->fetchAll();
                break;

            // ── Convocation : JA nominés pour une journée ───────────────
            case 'Convocation':
                if (!$journee || !$date) {
                    $rows = [];
                    break;
                }
                $depts = $this->deptsAutorises();
                $ph    = implode(',', array_fill(0, count($depts), '?'));
                $stmt = $pdo->prepare("
                    SELECT n.Id_Nomination, j.Id_JA, j.Nom, j.Prenom, j.Email,
                           r.Date, r.Heure, r.Journee, r.Poule,
                           ed.Division, RIGHT(ed.Division, 1) AS SexeCode,
                           ed.Nom AS NomDom, ee.Nom AS NomExt,
                           n.Id_Rencontre,
                           s.Nom          AS SalleNom,
                           s.Adresse      AS SalleAdresse,
                           lps.CodePostal AS SalleCP,
                           lps.Nom        AS SalleVille,
                           co.CorNom      AS CorrNom,
                           co.CorEmail    AS CorrEmail,
                           co.CorTelephone AS CorrTel
                    FROM nomination n
                    JOIN disponible dn     ON dn.Id_Disponible = n.Id_Disponible
                    JOIN ja j              ON j.Id_JA        = dn.Id_JA
                    JOIN rencontre r        ON r.Id_Rencontre = n.Id_Rencontre
                    JOIN equipe ed          ON ed.Id_Equipe   = r.Id_EquipeDom
                    LEFT JOIN equipe ee     ON ee.Id_Equipe   = r.Id_EquipeExt
                    LEFT JOIN salle s       ON s.Id_Salle     = r.id_Salle
                    LEFT JOIN laposte lps   ON lps.Id_LaPoste = s.Id_Laposte
                    LEFT JOIN Club co ON co.Id_Club = ed.Id_Club
                    WHERE r.Journee = ? AND r.Date = ? AND j.Actif = 1
                      AND j.Grade = 'JA1'
                      AND SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
                    ORDER BY j.Nom, j.Prenom
                ");
                $stmt->execute(array_merge([$journee, $date], $depts));
                $rows = $stmt->fetchAll();
                break;

            // ── Liste nomination : JA1 ayant des nominations dans la phase ─
            case 'Liste nomination':
                $stmt = $pdo->prepare("
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           COUNT(n.Id_Nomination) AS NbNominations
                    FROM ja j
                    JOIN disponible dn ON dn.Id_JA = j.Id_JA
                    JOIN nomination n ON n.Id_Disponible = dn.Id_Disponible
                    WHERE j.Actif = 1
                      AND j.Grade = 'JA1'
                      AND j.CodeDept = ?
                    GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Email
                    ORDER BY j.Nom, j.Prenom
                ");
                $stmt->execute([$dept]);
                $rows = $stmt->fetchAll();
                break;

            // ── Demande adresse : JA1 actifs ou sans id_laposte ─────────
            case 'Demande adresse':
                $stmt = $pdo->prepare("
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           COALESCE(lp.CodePostal, j.Cp)  AS Cp,
                           COALESCE(lp.Nom,        j.Ville) AS Ville,
                           (j.Id_LaPoste IS NOT NULL AND j.Id_LaPoste > 0) AS HasAdresse
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    WHERE j.Actif = 1 AND j.Grade = 'JA1' AND (j.Id_LaPoste IS NULL OR j.Id_LaPoste = 0)
                    ORDER BY j.Nom, j.Prenom
                ");
                $stmt->execute();
                $rows = $stmt->fetchAll();
                break;

            default:
                $rows = [];
        }

        $obf = $this->obfuscator();
        foreach ($rows as &$row) {
            $row['token'] = $obf->obfuscate((int) $row['Id_JA']);
        }
        unset($row);

        return $this->response->setJSON(['ok' => true, 'data' => $rows, 'dept' => $dept, 'saison' => $saison]);
    }

    public function apercu(): ResponseInterface
    {

        $pdo          = getPDO();
        $idNomination = (int) ($this->request->getPost('id_nomination') ?? 0);
        $message      = trim($this->request->getPost('message') ?? '');
        $sujet        = trim($this->request->getPost('sujet') ?? '');

        if (!$idNomination) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètre manquant.']);
        }

        $ja = $this->chargerNominationConvocation($pdo, $idNomination);
        if (!$ja) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination introuvable.']);
        }

        $marqueurs = construireMarqueursMessage($ja, $this->moi(), $this->ctxConvocation($ja));
        $rendu     = remplacerMarqueursMessage($sujet, $message, $marqueurs);

        return $this->response->setJSON(['ok' => true, 'sujet' => $rendu['sujet'], 'corps' => $rendu['corps']]);
    }

    public function envoyer(): ResponseInterface
    {

        $ids     = json_decode($this->request->getPost('ids') ?? '[]', true);
        $sujet   = trim($this->request->getPost('sujet') ?? '');
        $message = trim($this->request->getPost('message') ?? '');

        if ($sujet === '' || $message === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Sujet et message obligatoires.']);
        }
        if (empty($ids)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun destinataire sélectionné.']);
        }

        $errRl = checkRateLimit(count($ids));
        if ($errRl !== null) {
            return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
        }

        $pdo          = getPDO();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt         = $pdo->prepare("SELECT COUNT(*) FROM ja WHERE Id_JA IN ($placeholders) AND (Email IS NULL OR Email = '')");
        $stmt->execute(array_map('intval', $ids));
        $sansEmail = (int) $stmt->fetchColumn();

        return $this->response->setJSON(['ok' => true, 'sans_email' => $sansEmail, 'mode_dev' => isModeDeveloppement()]);
    }

    public function envoyerUn(): ResponseInterface
    {

        $pdo = getPDO();
        $moi = $this->moi();

        $type         = trim($this->request->getPost('type') ?? 'Disponibilites');
        $sujet        = trim($this->request->getPost('sujet') ?? '');
        $message      = trim($this->request->getPost('message') ?? '');
        $idJa         = (int) ($this->request->getPost('id_ja') ?? 0);
        $idNomination = (int) ($this->request->getPost('id_nomination') ?? 0);
        $saison       = trim($this->request->getPost('saison') ?? '');
        $cc           = trim($this->request->getPost('cc') ?? '');
        $replyTo      = $this->request->getPost('reply_to') === '1';
        $ccCorr       = $this->request->getPost('cc_corr') === '1'; // Convocation : copie au correspondant du club recevant
        $ccRef        = $this->request->getPost('cc_ref') === '1';  // Convocation : copie au référent du club recevant

        $identifiant = ($type === 'Convocation') ? $idNomination : $idJa;
        if (!$identifiant || $sujet === '' || $message === '') {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants.']);
        }

        $errRl = checkRateLimit(1);
        if ($errRl !== null) {
            return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
        }

        if ($type === 'Convocation') {
            $ja = $this->chargerNominationConvocation($pdo, $idNomination);
        } else {
            // Pour "Demande adresse" on autorise aussi les JA inactifs sans adresse
            $activeOnly = ($type !== 'Demande adresse') ? 'AND j.Actif = 1' : '';
            $stmt       = $pdo->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.Email FROM ja j WHERE j.Id_JA = ? $activeOnly");
            $stmt->execute([$idJa]);
            $ja = $stmt->fetch();
        }

        if (!$ja || empty($ja['Email'])) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'msg' => "Pas d'email."]);
        }

        // Générer la liste des nominations pour "Liste nomination"
        $listeNoms = '';
        if ($type === 'Liste nomination' && $saison) {
            $stmtNoms = $pdo->prepare('
                SELECT r.Date, r.Heure, ed.Division, ed.Nom AS Dom, ee.Nom AS Ext
                FROM nomination n
                JOIN disponible dn  ON dn.Id_Disponible = n.Id_Disponible
                JOIN rencontre r    ON r.Id_Rencontre = n.Id_Rencontre
                JOIN equipe ed      ON ed.Id_Equipe   = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                WHERE dn.Id_JA = ? ORDER BY r.Date, r.Heure
            ');
            $stmtNoms->execute([$idJa]);
            $noms = $stmtNoms->fetchAll();
            if ($noms) {
                $listeNoms = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">'
                    . '<tr style="background:#1a3a6b;color:#fff;"><th>Date</th><th>Heure</th><th>Division</th><th>Domicile</th><th>Extérieur</th></tr>';
                foreach ($noms as $idx => $n) {
                    $bg = ($idx % 2 === 0) ? '#f0f4fa' : '#ffffff';
                    $listeNoms .= sprintf(
                        '<tr style="background:%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                        $bg,
                        htmlspecialchars($n['Date'] ? date('d/m/Y', strtotime($n['Date'])) : ''),
                        htmlspecialchars($n['Heure'] ?? ''),
                        htmlspecialchars($n['Division']),
                        htmlspecialchars($n['Dom']),
                        htmlspecialchars($n['Ext'] ?? '')
                    );
                }
                $listeNoms .= '</table>';
            } else {
                $listeNoms = '<em>(aucune nomination)</em>';
            }
        }

        $ctx = $type === 'Convocation' ? $this->ctxConvocation($ja) : [];
        $ctx['liste_nominations'] = $listeNoms;

        $marqueurs  = construireMarqueursMessage($ja, $moi, $ctx);
        $rendu      = remplacerMarqueursMessage($sujet, $message, $marqueurs);
        $corps      = $rendu['corps'];
        $sujetRendu = $rendu['sujet'];

        // Réduire les suites d'espaces (>2) en un seul espace — les plages d'espaces
        // déclenchent les filtres antispam (Free.fr, OVH…)
        $corps = preg_replace('/ {3,}/', ' ', $corps);

        // Les filtres antispam (Free.fr, etc.) rejettent les emails contenant des images
        // encodées en base64 inline (data:image/...). On les remplace par l'URL hébergée.
        if (str_contains($corps, 'data:image/')) {
            $corps = preg_replace(
                '/src=["\']data:image\/[^;]+;base64,[^"\']*["\']/s',
                'src="' . base_url('img/FFTT_LIGUE.png') . '"',
                $corps
            );
        }

        $modeDev = isModeDeveloppement();
        $dest    = getEmailDestinataire($ja['Email']);
        $isHtml  = strip_tags($corps) !== $corps;

        try {
            $mail = getNijacMailer();
            $mail->isHTML($isHtml);
            $mail->addAddress($dest, $ja['Prenom'] . ' ' . $ja['Nom']);
            if ($replyTo && !empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], $moi['nom'] . ' ' . $moi['prenom']);
            }
            if ($cc !== '' && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
                $mail->addCC(getEmailDestinataire($cc), $moi['nom'] . ' ' . $moi['prenom']);
            }
            $mail->Subject = ($modeDev && $dest !== $ja['Email']) ? "[DEV] $sujetRendu" : $sujetRendu;
            $mail->Body    = $corps;
            if ($isHtml) {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corps));
            }
            $mail->send();
            enregistrerEnvois(1);
            if ($type === 'Convocation' && $idNomination) {
                $pdo->prepare('UPDATE nomination SET Valide = 1, EmailEnvoye = 1 WHERE Id_Nomination = ?')
                    ->execute([$idNomination]);
            }

            // Copie informative au correspondant / référent du club recevant (cases
            // cochées dans EN15) : même convocation, mais sans le lien de saisie des
            // frais (bouton "Enregistrer" du formulaire EN21) — copie en lecture seule.
            if ($type === 'Convocation') {
                $this->envoyerCopiesClub($ja, $ccCorr, $ccRef, $sujetRendu, $corps, $isHtml, $modeDev, $moi, $replyTo, $marqueurs['{URL_CONVOCATION_JA}'] ?? '');
            }

            return $this->response->setJSON(['ok' => true, 'nom' => $ja['Prenom'] . ' ' . $ja['Nom']]);
        } catch (\Exception $e) {
            // Log du corps pour diagnostiquer les rejets SMTP
            $logDir = __DIR__ . '/../../../logs';
            @mkdir($logDir, 0755, true);
            $snippet = substr(preg_replace('/\s+/', ' ', $corps), 0, 500);
            file_put_contents(
                $logDir . '/smtp_error.log',
                date('[Y-m-d H:i:s] ') . 'ERREUR: ' . $e->getMessage() . "\n"
                    . 'Taille corps: ' . strlen($corps) . " octets\n"
                    . 'Corps (500 premiers car): ' . $snippet . "\n\n",
                FILE_APPEND
            );
            error_log('[NIJAC] centrenvoye mail error : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'nom' => $ja['Prenom'] . ' ' . $ja['Nom'], 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Envoie une copie de la convocation au correspondant et/ou au référent du
     * club recevant (colonnes Club.CorEmail / Club.RefMail), selon les cases
     * cochées dans EN15. Le corps est celui du JA, mais le lien de convocation
     * ({URL_CONVOCATION_JA} → formulaire EN21 avec bouton "Enregistrer") est
     * retiré : ces destinataires reçoivent une copie informative, pas un accès
     * à la saisie des frais. Un échec ici n'invalide pas l'envoi au JA.
     */
    private function envoyerCopiesClub(array $ja, bool $ccCorr, bool $ccRef, string $sujet, string $corps, bool $isHtml, bool $modeDev, array $moi, bool $replyTo, string $urlConvoc): void
    {
        $destinataires = [];
        if ($ccCorr && !empty($ja['CorrEmail']) && filter_var($ja['CorrEmail'], FILTER_VALIDATE_EMAIL)) {
            $destinataires[$ja['CorrEmail']] = $ja['CorrNom'] ?: 'Correspondant';
        }
        if ($ccRef && !empty($ja['RefMail']) && filter_var($ja['RefMail'], FILTER_VALIDATE_EMAIL)) {
            $destinataires[$ja['RefMail']] = $ja['RefNom'] ?: 'Référent';
        }
        if (!$destinataires) {
            return;
        }

        // Retire le lien de convocation + la phrase qui l'introduit.
        $corpsCopie = $urlConvoc !== '' ? str_replace($urlConvoc, '', $corps) : $corps;
        $corpsCopie = preg_replace('/^.*lien vers la convocation.*\R?/mi', '', $corpsCopie);

        try {
            $mail = getNijacMailer();
            $mail->isHTML($isHtml);
            foreach ($destinataires as $adresse => $nom) {
                $mail->addAddress(getEmailDestinataire($adresse), $nom);
            }
            if ($replyTo && !empty($moi['email'])) {
                $mail->addReplyTo($moi['email'], ($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? ''));
            }
            $sujetCopie    = 'Copie convocation — ' . $sujet;
            $mail->Subject = $modeDev ? "[DEV] $sujetCopie" : $sujetCopie;
            $mail->Body    = $corpsCopie;
            if ($isHtml) {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $corpsCopie));
            }
            $mail->send();
            enregistrerEnvois(count($destinataires)); // ponytail: hors du checkRateLimit initial, dérive mineure acceptée
        } catch (\Exception $e) {
            error_log('[NIJAC] centrenvoye copie club error : ' . $e->getMessage());
        }
    }

    /**
     * Charge une nomination + JA + rencontre + salle + correspondant pour la
     * Convocation d'Id_Nomination donné — requête partagée par apercu() et
     * envoyerUn(). Correspondant lu depuis Club.CorNom/CorEmail/CorTelephone,
     * comme ja() et le reste de l'appli (E004/table `correspondant` retirée
     * au profit de ces colonnes, voir club.php).
     */
    private function chargerNominationConvocation(\PDO $pdo, int $idNomination): array|false
    {
        $stmt = $pdo->prepare('
            SELECT n.Id_Nomination, j.Id_JA, j.Nom, j.Prenom, j.Email,
                   n.Id_Rencontre,
                   r.Date, r.Heure, r.Journee, r.Poule,
                   ed.Division, RIGHT(ed.Division, 1) AS SexeCode,
                   ed.Nom AS NomDom, ee.Nom AS NomExt,
                   s.Nom AS SalleNom, s.Adresse AS SalleAdresse,
                   lps.CodePostal AS SalleCP, lps.Nom AS SalleVille,
                   co.CorNom AS CorrNom, co.CorEmail AS CorrEmail, co.CorTelephone AS CorrTel,
                   co.RefNom AS RefNom, co.RefMail AS RefMail
            FROM nomination n
            JOIN disponible dn     ON dn.Id_Disponible = n.Id_Disponible
            JOIN ja j              ON j.Id_JA        = dn.Id_JA
            JOIN rencontre r        ON r.Id_Rencontre = n.Id_Rencontre
            JOIN equipe ed          ON ed.Id_Equipe   = r.Id_EquipeDom
            LEFT JOIN equipe ee     ON ee.Id_Equipe   = r.Id_EquipeExt
            LEFT JOIN salle s       ON s.Id_Salle     = r.id_Salle
            LEFT JOIN laposte lps   ON lps.Id_LaPoste = s.Id_Laposte
            LEFT JOIN Club co       ON co.Id_Club     = ed.Id_Club
            WHERE n.Id_Nomination = ?
        ');
        $stmt->execute([$idNomination]);

        return $stmt->fetch();
    }

    /**
     * Mappe une ligne retournée par chargerNominationConvocation() (alias SQL
     * en PascalCase) vers le $ctx snake_case attendu par
     * construireMarqueursMessage() (config/app_config.php), utilisée par
     * apercu() et envoyerUn() pour le type "Convocation".
     */
    private function ctxConvocation(array $ja): array
    {
        return [
            'id_nomination' => $ja['Id_Nomination'] ?? null,
            'sexe_code'     => $ja['SexeCode']      ?? null,
            'date'          => $ja['Date']          ?? null,
            'heure'         => $ja['Heure']         ?? null,
            'journee'       => $ja['Journee']       ?? null,
            'poule'         => $ja['Poule']         ?? null,
            'division'      => $ja['Division']      ?? null,
            'dom'           => $ja['NomDom']        ?? null,
            'ext'           => $ja['NomExt']        ?? null,
            'salle_nom'     => $ja['SalleNom']      ?? null,
            'salle_adresse' => $ja['SalleAdresse']  ?? null,
            'salle_cp'      => $ja['SalleCP']       ?? null,
            'salle_ville'   => $ja['SalleVille']    ?? null,
            'corr_nom'      => $ja['CorrNom']       ?? null,
            'corr_email'    => $ja['CorrEmail']     ?? null,
            'corr_tel'      => $ja['CorrTel']       ?? null,
        ];
    }
}
