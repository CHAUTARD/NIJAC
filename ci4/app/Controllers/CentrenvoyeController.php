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
        require_once __DIR__ . '/../../../Classes/SecurePasswordHasher.php';
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
        return new \Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper());
    }

    /**
     * Erreur inattendue d'un endpoint de chargement : journal complet + JSON ok:false
     * en HTTP 200 (pour que erreurChargement() affiche ce message plutôt que « HTTP 500 »).
     * Admin : type, message (300 car.) et fichier:ligne relatif ; autres : message
     * générique + référence horodatée, retrouvable dans le journal.
     * (messageErreur() d'app_config.php n'a ni fichier:ligne ni référence.)
     */
    private function repondreErreur(\Throwable $e): ResponseInterface
    {
        $ref    = date('Ymd-His');
        $racine = realpath(__DIR__ . '/../../..') . DIRECTORY_SEPARATOR;
        $rel    = fn (string $t): string => str_replace([$racine, str_replace('\\', '/', $racine)], '', $t);
        $trace  = strtok($e->getTraceAsString(), "\n");

        log_message('error', "[EN15 réf. {$ref}] " . get_class($e) . ' : ' . $e->getMessage()
            . ' — ' . $e->getFile() . ':' . $e->getLine() . ' — ' . $trace);

        $err = !empty($_SESSION['utilisateur']['is_admin'])
            ? get_class($e) . ' : ' . mb_substr($rel($e->getMessage()), 0, 300) . ' — ' . $rel($e->getFile()) . ':' . $e->getLine()
            : "Erreur technique, contactez l'administrateur (réf. {$ref})";

        return $this->response->setStatusCode(200)->setJSON(['ok' => false, 'err' => $err]);
    }

    public function index()
    {
        $moi = $this->moi();
        $pdo = getPDO();

        $modeles = [];
        // Le code de sécurité (E010) et l'invitation JA ne sont jamais des modèles génériques d'EN15
        // (l'onglet « Invitation JA » a son propre aperçu / édition admin : invitationJaModele()).
        $rows    = $pdo->prepare('SELECT Type, Sujet, Message, Cc, ReplyTo FROM messagerie WHERE Type NOT IN (?, ?) ORDER BY Id_Messagerie');
        $rows->execute(typesMessagesReservesAdmin());
        $rows    = $rows->fetchAll();
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
            'deptsAff'    => implode('/', $this->deptsAutorises()), // ex. "76/27" (règle région) — utilisé pour l'onglet Convocation
            'modeles'     => $modeles,
            'monEmail'    => $moi['email'] ?? '',
            'modeDev'     => isModeDeveloppement(),
        ];

        return view('centrenvoye_index', $data);
    }

    public function journees(): ResponseInterface
    {
        try {
            $pdo    = getPDO();
            $saison = getConfig('saison') ?: null;

            if (!$saison) {
                return $this->response->setJSON(['ok' => true, 'data' => []]);
            }

            // Mêmes filtres JA que la liste de convocation (cas "Convocation" de ja()) :
            // JA1 actifs (toute nomination existante est valide).
            $depts = $this->deptsAutorises();
            $ph    = implode(',', array_fill(0, count($depts), '?'));
            $stmt = $pdo->prepare("
                SELECT r.Journee, r.Date,
                       COUNT(DISTINCT j.Id_JA) AS NbJA
                FROM rencontre r
                JOIN equipe ed ON ed.Id_Equipe = r.Id_EquipeDom
                LEFT JOIN nomination n  ON n.Id_Rencontre   = r.Id_Rencontre
                LEFT JOIN disponible d  ON d.Id_Disponible  = n.Id_Disponible
                LEFT JOIN ja j          ON j.Id_JA = d.Id_JA AND j.JA1 = 1
                WHERE SUBSTRING(ed.Id_Club, 3, 2) IN ($ph)
                GROUP BY r.Journee, r.Date
                ORDER BY r.Date, r.Journee
            ");
            $stmt->execute($depts);
            $rows = $stmt->fetchAll();

            return $this->response->setJSON(['ok' => true, 'data' => $rows, 'saison' => $saison]);
        } catch (\Throwable $e) {
            return $this->repondreErreur($e);
        }
    }

    public function ja(): ResponseInterface
    {
        try {
            $pdo     = getPDO();
            $dept    = $this->dept();
            $saison  = getConfig('saison') ?: null;
            $type    = $this->request->getGet('type') ?? 'Disponibilites';
            $journee = (int) ($this->request->getGet('journee') ?? 0);
            $date    = trim((string) ($this->request->getGet('date') ?? ''));

            // Toutes les listes de JA suivent la règle région (ex. 76 ⇒ 76 + 27).
            $depts = $this->deptsAutorises();
            $ph    = implode(',', array_fill(0, count($depts), '?'));

            switch ($type) {
                // ── Disponibilités : tous JA1 actifs des départements autorisés ─
                case 'Disponibilites':
                    $stmt = $pdo->prepare("
                        SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                               lp.CodePostal AS CP
                        FROM ja j
                        LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                        WHERE j.JA1 = 1
                          AND j.CodeDept IN ($ph)
                        ORDER BY j.Nom, j.Prenom
                    ");
                    $stmt->execute($depts);
                    $rows = $stmt->fetchAll();
                    break;

                // ── Rappel dispo : JA1 sans dispo dans la saison courante ───
                case 'Rappel dispo':
                    $stmt = $pdo->prepare("
                        SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                               lp.CodePostal AS CP
                        FROM ja j
                        LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                        WHERE j.JA1 = 1
                          AND j.CodeDept IN ($ph)
                          AND NOT EXISTS (
                              SELECT 1 FROM disponible d
                              WHERE d.Id_JA = j.Id_JA
                          )
                        ORDER BY j.Nom, j.Prenom
                    ");
                    $stmt->execute($depts);
                    $rows = $stmt->fetchAll();
                    break;

                // ── Convocation : JA nominés pour une journée ───────────────
                case 'Convocation':
                    if (!$journee || !$date) {
                        $rows = [];
                        break;
                    }
                    $stmt = $pdo->prepare("
                        SELECT n.Id_Nomination, j.Id_JA, j.Nom, j.Prenom, j.Email,
                               r.Date, r.Heure, r.Journee, r.Poule,
                               ed.Division, RIGHT(ed.Division, 1) AS SexeCode,
                               ed.Nom AS NomDom, ee.Nom AS NomExt,
                               n.Id_Rencontre,
                               COALESCE(s.Nom, s_c.Nom)                 AS SalleNom,
                               COALESCE(s.Adresse, s_c.Adresse)         AS SalleAdresse,
                               COALESCE(lps.CodePostal, lp_c.CodePostal) AS SalleCP,
                               COALESCE(lps.Nom, lp_c.Nom)              AS SalleVille,
                               co.Nom         AS NomClub,
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
                        -- Repli : salle principale du club recevant (r.id_Salle est NULL pour la majorité des rencontres)
                        " . sqlJoinSallePrincipale('ed.Id_Club', 's_c') . "
                        LEFT JOIN laposte lp_c  ON lp_c.Id_LaPoste = s_c.Id_Laposte
                        LEFT JOIN Club co ON co.Id_Club = ed.Id_Club
                        WHERE r.Journee = ? AND r.Date = ? AND j.JA1 = 1
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
                        WHERE j.JA1 = 1
                          AND j.CodeDept IN ($ph)
                        GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Email
                        ORDER BY j.Nom, j.Prenom
                    ");
                    $stmt->execute($depts);
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
                        WHERE j.JA1 = 1 AND (j.Id_LaPoste IS NULL OR j.Id_LaPoste = 0)
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
        } catch (\Throwable $e) {
            return $this->repondreErreur($e);
        }
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
        if (!in_array($ja['DeptClub'], $this->deptsAutorises(), true)) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Nomination hors de votre périmètre.']);
        }

        $marqueurs = construireMarqueursMessage($ja, $this->moi(), $this->ctxConvocation($ja));
        $rendu     = remplacerMarqueursMessage($sujet, $message, $marqueurs);

        $nonDispo = $this->jaNonDisponible($pdo, (int) $ja['Id_JA'], (int) $ja['Id_Rencontre'], $ja['Date'] ?? null);
        $dateFr   = !empty($ja['Date']) ? date('d/m/Y', strtotime($ja['Date'])) : '';

        return $this->response->setJSON([
            'ok'         => true,
            'sujet'      => $rendu['sujet'],
            'corps'      => $rendu['corps'],
            'non_dispo'  => $nonDispo,
            'avertissement' => $nonDispo
                ? trim(($ja['Prenom'] ?? '') . ' ' . ($ja['Nom'] ?? '')) . " s'est déclaré(e) NON DISPONIBLE"
                    . ($dateFr ? " pour le $dateFr" : '') . ". L'envoi de cette convocation sera bloqué : "
                    . 'supprimez la nomination puis réaffectez la rencontre à un autre juge-arbitre.'
                : '',
        ]);
    }

    public function envoyer(): ResponseInterface
    {

        $ids     = json_decode($this->request->getPost('ids') ?? '[]', true);
        $sujet   = trim($this->request->getPost('sujet') ?? '');
        $message = trim($this->request->getPost('message') ?? '');
        $type    = trim($this->request->getPost('type') ?? 'Disponibilites');

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
        $intIds       = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($intIds), '?'));

        // Convocation : les ids sont des Id_Nomination — comptage des JA sans
        // email et des JA qui se sont déclarés NON DISPONIBLES (EN22).
        if ($type === 'Convocation') {
            $stmt = $pdo->prepare("
                SELECT j.Prenom, j.Nom,
                       (j.Email IS NULL OR j.Email = '') AS SansEmail,
                       EXISTS (
                           SELECT 1 FROM disponible dn
                           WHERE dn.Id_JA = j.Id_JA AND dn.Reponse = 'N'
                             AND (dn.Id_Rencontre = n.Id_Rencontre
                                  OR (dn.Id_Rencontre IS NULL AND dn.DateCompetition = r.Date))
                       ) AS NonDispo
                FROM nomination n
                JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
                JOIN ja j         ON j.Id_JA = d.Id_JA
                JOIN rencontre r  ON r.Id_Rencontre = n.Id_Rencontre
                WHERE n.Id_Nomination IN ($placeholders)
            ");
            $stmt->execute($intIds);
            $rows        = $stmt->fetchAll();
            $sansEmail   = 0;
            $indispoNoms = [];
            foreach ($rows as $r) {
                if ($r['SansEmail']) {
                    $sansEmail++;
                }
                if ($r['NonDispo']) {
                    $indispoNoms[] = trim($r['Prenom'] . ' ' . $r['Nom']);
                }
            }

            return $this->response->setJSON([
                'ok'          => true,
                'sans_email'  => $sansEmail,
                'indispo'     => count($indispoNoms),
                'indispo_noms' => $indispoNoms,
                'mode_dev'    => isModeDeveloppement(),
            ]);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ja WHERE Id_JA IN ($placeholders) AND (Email IS NULL OR Email = '')");
        $stmt->execute($intIds);
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
            $activeOnly = ($type !== 'Demande adresse') ? 'AND j.JA1 = 1' : '';
            $stmt       = $pdo->prepare("SELECT j.Id_JA, j.Nom, j.Prenom, j.Email, j.CodeDept FROM ja j WHERE j.Id_JA = ? $activeOnly");
            $stmt->execute([$idJa]);
            $ja = $stmt->fetch();
        }

        // Périmètre du nominateur : mêmes règles que les listes de ja() (nomination →
        // département du club recevant, autres types → CodeDept du JA). « Demande
        // adresse » n'est pas filtrée par département dans ja(), elle ne l'est pas ici non plus.
        if ($ja && $type !== 'Demande adresse') {
            $deptJa = $type === 'Convocation' ? $ja['DeptClub'] : str_pad((string) ($ja['CodeDept'] ?? ''), 2, '0', STR_PAD_LEFT);
            if (!in_array($deptJa, $this->deptsAutorises(), true)) {
                return $this->response->setJSON(['ok' => false, 'msg' => 'Hors de votre périmètre.']);
            }
        }

        if (!$ja || empty($ja['Email'])) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'msg' => "Pas d'email."]);
        }

        // Convocation : ne rien envoyer si le JA s'est déclaré NON DISPONIBLE
        // pour cette rencontre ou pour la date (déclaration EN22, Reponse='N').
        // Le nominateur doit supprimer la nomination et la réaffecter.
        if ($type === 'Convocation'
            && $this->jaNonDisponible($pdo, (int) $ja['Id_JA'], (int) $ja['Id_Rencontre'], $ja['Date'] ?? null)) {
            $dateFr = !empty($ja['Date']) ? date('d/m/Y', strtotime($ja['Date'])) : '';

            return $this->response->setJSON([
                'ok'  => false,
                'nom' => trim(($ja['Prenom'] ?? '') . ' ' . ($ja['Nom'] ?? '')),
                'msg' => "s'est déclaré(e) NON DISPONIBLE" . ($dateFr ? " pour le $dateFr" : '')
                       . " — aucune convocation envoyée. Supprimez la nomination puis réaffectez la rencontre à un autre juge-arbitre.",
            ]);
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
                $mail->AltBody = altBodyDepuisHtml($corps);
            }
            $mail->send();
            enregistrerEnvois(1);
            if ($type === 'Convocation' && $idNomination) {
                $pdo->prepare('UPDATE nomination SET EmailEnvoye = 1 WHERE Id_Nomination = ?')
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
                $mail->AltBody = altBodyDepuisHtml($corpsCopie);
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
                   COALESCE(s.Nom, s_c.Nom) AS SalleNom, COALESCE(s.Adresse, s_c.Adresse) AS SalleAdresse,
                   COALESCE(lps.CodePostal, lp_c.CodePostal) AS SalleCP, COALESCE(lps.Nom, lp_c.Nom) AS SalleVille,
                   co.Nom AS NomClub,
                   co.CorNom AS CorrNom, co.CorEmail AS CorrEmail, co.CorTelephone AS CorrTel,
                   co.RefNom AS RefNom, co.RefMail AS RefMail,
                   SUBSTRING(ed.Id_Club, 3, 2) AS DeptClub
            FROM nomination n
            JOIN disponible dn     ON dn.Id_Disponible = n.Id_Disponible
            JOIN ja j              ON j.Id_JA        = dn.Id_JA
            JOIN rencontre r        ON r.Id_Rencontre = n.Id_Rencontre
            JOIN equipe ed          ON ed.Id_Equipe   = r.Id_EquipeDom
            LEFT JOIN equipe ee     ON ee.Id_Equipe   = r.Id_EquipeExt
            LEFT JOIN salle s       ON s.Id_Salle     = r.id_Salle
            LEFT JOIN laposte lps   ON lps.Id_LaPoste = s.Id_Laposte
            ' . sqlJoinSallePrincipale('ed.Id_Club', 's_c') . '
            LEFT JOIN laposte lp_c  ON lp_c.Id_LaPoste = s_c.Id_Laposte
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
            'nom_club'      => $ja['NomClub']       ?? null,
            'salle_nom'     => $ja['SalleNom']      ?? null,
            'salle_adresse' => $ja['SalleAdresse']  ?? null,
            'salle_cp'      => $ja['SalleCP']       ?? null,
            'salle_ville'   => $ja['SalleVille']    ?? null,
            'corr_nom'      => $ja['CorrNom']       ?? null,
            'corr_email'    => $ja['CorrEmail']     ?? null,
            'corr_tel'      => $ja['CorrTel']       ?? null,
            'ja_disponibles' => $this->jaDisponibles($ja),
        ];
    }

    /**
     * {LISTE_JA_DISPONIBLES} : autres JA actifs disponibles (EN22, Reponse='O')
     * le jour de la rencontre — toute la journée ou pour cette rencontre précise —,
     * hors JA convoqué, limités aux départements autorisés du nominateur.
     */
    private function jaDisponibles(array $ja): array
    {
        if (empty($ja['Date'])) {
            return [];
        }
        $depts = $this->deptsAutorises();
        $stmt  = getPDO()->prepare("
            SELECT DISTINCT j.Nom, j.Prenom, j.Telephone, j.Email
            FROM disponible d
            JOIN ja j ON j.Id_JA = d.Id_JA
            WHERE d.Reponse = 'O' AND d.DateCompetition = ?
              AND (d.Id_Rencontre IS NULL OR d.Id_Rencontre = ?)
              AND j.JA1 = 1 AND j.Id_JA <> ?
              AND LPAD(j.CodeDept, 2, '0') IN (" . implode(',', array_fill(0, count($depts), '?')) . ")
            ORDER BY j.Nom, j.Prenom
        ");
        $stmt->execute(array_merge([$ja['Date'], (int) $ja['Id_Rencontre'], (int) $ja['Id_JA']], $depts));

        return $stmt->fetchAll();
    }

    /**
     * Le JA s'est-il déclaré NON DISPONIBLE (EN22, `disponible.Reponse = 'N'`)
     * pour cette rencontre précise ou pour sa date de compétition ? Sert au
     * blocage d'envoi (envoyerUn), à l'aperçu (apercu) et au comptage préalable
     * de l'envoi groupé (envoyer), tous pour le type "Convocation".
     */
    private function jaNonDisponible(\PDO $pdo, int $idJa, ?int $idRencontre, ?string $date): bool
    {
        if ($idJa <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM disponible
            WHERE Id_JA = ? AND Reponse = 'N'
              AND (Id_Rencontre = ? OR (Id_Rencontre IS NULL AND DateCompetition = ?))
        ");
        $stmt->execute([$idJa, $idRencontre, $date]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // ── Onglet « Invitation JA » : création des comptes de rôle JA (menu E011) ───────────────────
    //
    // Destinataires : JA1 actifs (ja.JA1 = 1) des départements de getDepartementsAutorises(). À l'envoi,
    // compte utilisateur créé (Id_Utilisateur automatique, fiche = utilisateur.Id_Ja — index UNIQUE : une
    // fiche, un seul compte —, Login = Email = adresse du JA, Prenom vide, Nom = nom de la fiche, rôle JA
    // (utilisateur_role), Actif 1, ChangeLogin 1) avec un mot de passe
    // provisoire aléatoire, envoyé dans le message système « Invitation JA » (EA93, admin). JA déjà doté
    // d'un compte (compte JA dont Id_Ja = la fiche, compteJaDeFiche()) : ignoré (« compte déjà existant »)
    // sauf case « Régénérer » (nouveau mot de passe, ChangeLogin 1, PwdVersion + 1). Conflits (fiche liée à
    // un compte non-JA, login déjà pris, adresse invalide…) : ignorés avec
    // motif, aucun compte écrasé — voir etatCompteJa(). Le mot de passe n'existe en clair que dans le
    // corps de l'email : jamais journalisé, jamais renvoyé au navigateur.

    /** Modèle système « Invitation JA » (Id_Utilisateur 0 = jamais de copie personnelle). */
    private function modeleInvitationJa(\PDO $pdo): ?array
    {
        return resoudreModeleMessagerieParType($pdo, TYPE_MESSAGE_INVITATION_JA, 0);
    }

    /** Administrateur ? (mêmes droits d'édition des messages système qu'EA93, MessagerieController::isAdmin()). */
    private function estAdmin(): bool
    {
        return !empty($this->moi()['is_admin']);
    }

    /**
     * GET centrenvoye/invitation-ja/modele : aperçu du message « Invitation JA » tel qu'il sera envoyé
     * (message système retrouvé par Type — même ligne que resoudreModeleMessagerieParType(…, 0) —, sinon
     * corps par défaut), rendu côté serveur avec des valeurs d'exemple (apercuMessageInvitationJa()).
     * Tout rôle d'EN15 : `apercu` + `modele` (Sujet, Message bruts — ce Type seulement, affichés en lecture
     * seule). Administrateur : en plus Id, Cc, ReplyTo pour l'édition, enregistrée par PUT messagerie/{Id}
     * (contrôles d'EA93 inchangés).
     * `absent` = aucune ligne système (avant EA98) : aperçu du corps par défaut, édition impossible.
     */
    public function invitationJaModele(): ResponseInterface
    {
        try {
            $pdo = getPDO();
            $st  = $pdo->prepare('SELECT Id_Messagerie, Sujet, Message, Cc, ReplyTo FROM messagerie
                WHERE Type = ? AND Id_Utilisateur IS NULL ORDER BY Id_Messagerie LIMIT 1');
            $st->execute([TYPE_MESSAGE_INVITATION_JA]);
            $ligne  = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            $modele = $this->modeleInvitationJa($pdo);

            $rep = [
                'ok'            => true,
                'apercu'        => apercuMessageInvitationJa($modele),
                'absent'        => $ligne === null,
                'erreur_modele' => controlerModeleInvitationJa($modele),
                'peut_editer'   => $this->estAdmin() && $ligne !== null,
            ];
            // Code source (Sujet / Message) fourni à tout rôle d'EN15 pour CE Type seulement (champ Message en
            // lecture seule hors administrateur) : le corps ne contient que des marqueurs, aucune donnée
            // personnelle. Id / Cc / ReplyTo (nécessaires à l'enregistrement) : administrateur seulement.
            $rep['modele'] = [
                'sujet'   => $ligne ? (string) $ligne['Sujet'] : SUJET_INVITATION_JA,
                'message' => $ligne ? (string) $ligne['Message'] : corpsParDefautInvitationJa(),
            ];
            if ($rep['peut_editer']) {
                $rep['modele'] += [
                    'id'      => (int) $ligne['Id_Messagerie'],
                    'cc'      => (int) $ligne['Cc'],
                    'replyto' => (int) $ligne['ReplyTo'],
                ];
            }

            return $this->response->setJSON($rep);
        } catch (\Throwable $e) {
            return $this->repondreErreur($e);
        }
    }

    /**
     * POST centrenvoye/invitation-ja/apercu (sujet, message) : aperçu d'un BROUILLON (administrateur
     * seulement, rien n'est enregistré) — même rendu serveur que invitationJaModele(), plus `erreur`
     * (controlerModeleInvitationJa(), bloquant à l'enregistrement) et `avertissement`
     * (avertissementsHtmlEmail(), non bloquant).
     */
    public function invitationJaApercu(): ResponseInterface
    {
        if (!$this->estAdmin()) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Action réservée aux administrateurs.']);
        }
        $brouillon = [
            'Sujet'   => trim((string) ($this->request->getPost('sujet') ?? '')),
            'Message' => trim((string) ($this->request->getPost('message') ?? '')),
        ];

        return $this->response->setJSON([
            'ok'            => true,
            'apercu'        => apercuMessageInvitationJa($brouillon),
            'erreur'        => controlerModeleInvitationJa($brouillon),
            'avertissement' => avertissementsHtmlEmail($brouillon['Message']),
        ]);
    }

    /**
     * GET centrenvoye/invitation-ja : JA1 actifs du périmètre + état de leur compte (etatCompteJa()).
     * `erreur_modele` = modèle EA93 inutilisable.
     */
    public function invitationJa(): ResponseInterface
    {
        try {
            $pdo   = getPDO();
            $depts = $this->deptsAutorises();
            $ph    = implode(',', array_fill(0, count($depts), '?'));
            $stmt  = $pdo->prepare("
                SELECT j.Id_JA, j.Nom, j.Prenom, j.Email
                FROM ja j
                WHERE j.JA1 = 1 AND j.CodeDept IN ($ph)
                ORDER BY j.Nom, j.Prenom
            ");
            $stmt->execute($depts);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$row) {
                $etat           = etatCompteJa($pdo, (int) $row['Id_JA'], (string) ($row['Email'] ?? ''));
                $row['Statut']  = $etat['statut'];
                $row['Motif']   = $etat['motif'];
                $row['LoginCompte'] = $etat['compte']['Login'] ?? null;
            }
            unset($row);

            return $this->response->setJSON([
                'ok'            => true,
                'data'          => $rows,
                'erreur_modele' => controlerModeleInvitationJa($this->modeleInvitationJa($pdo)),
                'mode_dev'      => isModeDeveloppement(),
            ]);
        } catch (\Throwable $e) {
            return $this->repondreErreur($e);
        }
    }

    /**
     * POST centrenvoye/invitation-ja/verifier (ids JSON) : garde-fous avant l'envoi en masse — même
     * limitation de débit que les autres envois (checkRateLimit()), modèle valide.
     */
    public function invitationJaVerifier(): ResponseInterface
    {
        $ids = json_decode((string) ($this->request->getPost('ids') ?? '[]'), true);
        if (!is_array($ids) || !$ids) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Aucun destinataire sélectionné.']);
        }
        $pdo = getPDO();
        if ($err = controlerModeleInvitationJa($this->modeleInvitationJa($pdo))) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }
        if ($errRl = checkRateLimit(count($ids))) {
            return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
        }

        return $this->response->setJSON(['ok' => true, 'mode_dev' => isModeDeveloppement()]);
    }

    /**
     * POST centrenvoye/invitation-ja/envoyer-un (id_ja, regenerer 0/1) : un JA. Compte créé / mis à jour
     * dans une transaction, email envoyé, COMMIT seulement si l'envoi a réussi (sinon ROLLBACK : aucun
     * compte ni mot de passe ne subsiste). Réponse {ok, nom, statut 'cree'|'regenere'} ou
     * {ok:false, skip:true, nom, msg: motif} (ignoré) ou {ok:false, nom, msg} (échec) — jamais le mot de passe.
     */
    public function invitationJaEnvoyerUn(): ResponseInterface
    {
        $pdo       = getPDO();
        $moi       = $this->moi();
        $idJa      = (int) ($this->request->getPost('id_ja') ?? 0);
        $regenerer = $this->request->getPost('regenerer') === '1';

        if ($idJa <= 0) {
            return $this->response->setJSON(['ok' => false, 'msg' => 'Paramètres manquants.']);
        }
        $modele = $this->modeleInvitationJa($pdo);
        if ($err = controlerModeleInvitationJa($modele)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $err]);
        }
        if ($errRl = checkRateLimit(1)) {
            return $this->response->setJSON(['ok' => false, 'msg' => $errRl]);
        }

        // Fiche : JA1 actif du périmètre (mêmes règles que la liste, revérifiées ici — id client non sûr).
        $st = $pdo->prepare('SELECT Id_JA, Nom, Prenom, Email, CodeDept FROM ja WHERE Id_JA = ? AND JA1 = 1');
        $st->execute([$idJa]);
        $ja = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$ja) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => "JA #$idJa", 'msg' => 'fiche introuvable ou JA inactif']);
        }
        $nomJa = trim($ja['Prenom'] . ' ' . $ja['Nom']);
        if (!in_array(str_pad((string) $ja['CodeDept'], 2, '0', STR_PAD_LEFT), $this->deptsAutorises(), true)) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nomJa, 'msg' => 'hors de votre périmètre']);
        }

        try {
            // Avant la reprise EA98 (marqueur absent) : 'ignore', motif « … chargez EA98 » (MSG_COMPTE_JA_AVANT_EA98).
            $etat = etatCompteJa($pdo, $idJa, (string) ($ja['Email'] ?? ''));
        } catch (\PDOException $e) {
            // Marqueur / base illisible : refus par défaut, aucun compte créé ni email envoyé.
            error_log('[NIJAC] EN15 invitation JA (Id_JA ' . $idJa . ') : état du compte illisible : ' . $e->getMessage());

            return $this->response->setJSON(['ok' => false, 'nom' => $nomJa, 'msg' => 'état du compte illisible (erreur base de données) — aucun email envoyé']);
        }
        if ($etat['statut'] === 'ignore') {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nomJa, 'msg' => $etat['motif']]);
        }
        if ($etat['statut'] === 'existant' && !$regenerer) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nomJa, 'msg' => 'compte déjà existant']);
        }

        // Rôle JA exclusif : un compte 'existant' est un compte JA seul (etatCompteJa() ignore tout cumul hérité).
        $nouveau = $etat['statut'] === 'nouveau';
        $login  = $nouveau ? trim((string) $ja['Email']) : (string) $etat['compte']['Login'];
        $adresse = $nouveau ? $login : (string) $etat['compte']['Email'];
        if (!filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
            return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nomJa, 'msg' => 'adresse email du compte invalide']);
        }

        $mdp   = genererMotDePasseAleatoire(INVITATION_JA_LONGUEUR_MDP);
        $rendu = rendreMessageInvitationJa($modele, [
            'prenom' => $ja['Prenom'], 'nom' => $ja['Nom'], 'login' => $login,
            'mot_de_passe' => $mdp, 'lien' => site_url('login'),
        ]);

        $envoye = false;
        // Compte JA visé : Id_Utilisateur du compte existant (repéré par sa fiche, etatCompteJa()), ou celui
        // créé ci-dessous (Id automatique) — jamais l'Id_JA de la fiche.
        $idCompte = $nouveau ? 0 : (int) $etat['compte']['Id_Utilisateur'];
        try {
            $pdo->beginTransaction();
            if ($nouveau) {
                $idCompte = creerCompteJa(
                    $pdo, $idJa, $login, (string) $ja['Nom'],
                    departementCompteJa($pdo, (string) $ja['CodeDept'], (string) ($moi['id_departement'] ?? '')),
                    \SecurePasswordHasher::hash($mdp)
                );
            } else {
                // Compte toujours JA, actif et toujours rattaché à CETTE fiche (relu sous transaction).
                $up = $pdo->prepare("UPDATE utilisateur SET Password = ?, ChangeLogin = 1" . sqlIncrementPwdVersion($pdo)
                    . " WHERE Id_Utilisateur = ? AND " . sqlARole('utilisateur.Id_Utilisateur', '?') . " AND Actif = 1");
                if (idJaDuCompteJa($pdo, $idCompte) !== $idJa) {
                    $pdo->rollBack();

                    return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nomJa, 'msg' => 'compte modifié entre-temps, rechargez la liste']);
                }
                $up->execute([\SecurePasswordHasher::hash($mdp), $idCompte, 'JA']);
                if ($up->rowCount() !== 1) {
                    $pdo->rollBack();

                    return $this->response->setJSON(['ok' => false, 'skip' => true, 'nom' => $nomJa, 'msg' => 'compte modifié entre-temps, rechargez la liste']);
                }
            }

            // Mode Développement : getEmailDestinataire() + NijacMailer redirigent vers email_developpement ;
            // le Login enregistré reste l'adresse réelle du JA.
            $mail = getNijacMailer();
            $mail->isHTML($rendu['html']);
            $mail->addAddress(getEmailDestinataire($adresse), $nomJa);
            $mail->Subject = $rendu['sujet'];
            $mail->Body    = $rendu['corps'];
            if ($rendu['html']) {
                $mail->AltBody = $rendu['texte'];
            }
            $mail->send();
            $envoye = true;

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            unset($mdp, $rendu);
            // Journal : message d'erreur seul (jamais le corps : il contient le mot de passe).
            error_log('[NIJAC] EN15 invitation JA (Id_JA ' . $idJa . ') : ' . get_class($e) . ' : ' . $e->getMessage());
            $msg = $envoye
                ? "email envoyé mais enregistrement du compte impossible : son mot de passe n'est pas valable, renvoyez l'invitation"
                : ($e instanceof \PDOException
                    ? (estViolationFicheJaLieeUnique($e)
                        ? 'fiche déjà liée à un autre compte — aucun email envoyé'
                        : 'création du compte impossible (login déjà pris ?) — aucun email envoyé')
                    : ($e instanceof \RuntimeException && str_contains($e->getMessage(), 'EA98')
                        ? $e->getMessage() . ' — aucun email envoyé'
                        : "échec de l'envoi de l'email — aucun compte créé ni modifié"));

            return $this->response->setJSON(['ok' => false, 'nom' => $nomJa, 'msg' => $msg]);
        }
        unset($mdp, $rendu);
        enregistrerEnvois(1);
        error_log('[NIJAC][SEC] EN15 invitation JA : compte ' . ($nouveau ? 'créé' : 'mot de passe régénéré')
            . ' (Id_Utilisateur ' . $idCompte . ', Id_JA ' . $idJa . ') par ' . preg_replace('/[^\w.@-]/u', '_', (string) ($moi['login'] ?? '?')));

        return $this->response->setJSON(['ok' => true, 'nom' => $nomJa, 'statut' => $nouveau ? 'cree' : 'regenere']);
    }
}
