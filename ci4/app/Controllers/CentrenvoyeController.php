<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Centre d'envoi de messages (E024), portage CI4 de Nominateur/centrenvoye.php.
 *
 * Accessible à tout utilisateur authentifié (filtre "auth", pas "adminauth") :
 * envoie les 4 types de messages (Disponibilités, Rappel dispo, Convocation,
 * Liste nomination) + Demande adresse aux JA actifs du département connecté.
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

    private function obfuscator(): \Obfuscator
    {
        return new \Obfuscator(OBFUSCATOR_SEED);
    }

    public function index()
    {
        $moi = $this->moi();
        $pdo = getPDO();

        $modeles = [];
        $rows    = $pdo->query('SELECT Type, Sujet, Message FROM messagerie ORDER BY Id_Messagerie')->fetchAll();
        foreach ($rows as $r) {
            if (!isset($modeles[$r['Type']])) {
                $modeles[$r['Type']] = ['sujet' => $r['Sujet'], 'message' => $r['Message']];
            }
        }

        $data = [
            'nomComplet'  => trim(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? '')),
            'departement' => $moi['id_departement'] ?? '',
            'changeLogin' => !empty($moi['change_login']),
            'dept'        => $this->dept(),
            'modeles'     => $modeles,
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

        $rows = $pdo->query('
            SELECT r.Journee, r.Date,
                   COUNT(DISTINCT d.Id_JA) AS NbJA
            FROM rencontre r
            LEFT JOIN nomination n ON n.Id_Rencontre = r.Id_Rencontre AND n.Valide = 1
            LEFT JOIN disponible d ON d.Id_Disponible = n.Id_Disponible
            GROUP BY r.Journee, r.Date
            ORDER BY r.Date, r.Journee
        ')->fetchAll();

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
            // ── Disponibilités : tous JA actifs du dept ─────────────────
            case 'Disponibilites':
                $stmt = $pdo->prepare('
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           lp.CodePostal AS CP
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    WHERE j.Actif = 1
                      AND LEFT(lp.CodePostal, 2) = ?
                    ORDER BY j.Nom, j.Prenom
                ');
                $stmt->execute([$dept]);
                $rows = $stmt->fetchAll();
                break;

            // ── Rappel dispo : JA sans dispo dans la saison courante ────
            case 'Rappel dispo':
                $stmt = $pdo->prepare('
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           lp.CodePostal AS CP
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    WHERE j.Actif = 1
                      AND LEFT(lp.CodePostal, 2) = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM disponible d
                          WHERE d.Id_JA = j.Id_JA
                      )
                    ORDER BY j.Nom, j.Prenom
                ');
                $stmt->execute([$dept]);
                $rows = $stmt->fetchAll();
                break;

            // ── Convocation : JA nominés pour une journée ───────────────
            case 'Convocation':
                if (!$journee || !$date) {
                    $rows = [];
                    break;
                }
                $stmt = $pdo->prepare('
                    SELECT n.Id_Nomination, j.Id_JA, j.Nom, j.Prenom, j.Email,
                           r.Date, r.Heure, r.Journee, r.Poule,
                           dv.Division, RIGHT(dv.Division, 1) AS SexeCode,
                           ed.Nom AS NomDom, ee.Nom AS NomExt,
                           n.Id_Rencontre,
                           n.Kilometre,
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
                    JOIN division dv        ON dv.Id_Division = r.Id_Division
                    LEFT JOIN salle s       ON s.Id_Salle     = r.id_Salle
                    LEFT JOIN laposte lps   ON lps.Id_LaPoste = s.Id_Laposte
                    LEFT JOIN Club co ON co.Id_Club = ed.Id_Club
                    WHERE r.Journee = ? AND r.Date = ? AND j.Actif = 1
                    ORDER BY j.Nom, j.Prenom
                ');
                $stmt->execute([$journee, $date]);
                $rows = $stmt->fetchAll();
                break;

            // ── Liste nomination : JA ayant des nominations dans la phase ─
            case 'Liste nomination':
                $stmt = $pdo->prepare('
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           COUNT(n.Id_Nomination) AS NbNominations
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    JOIN disponible dn ON dn.Id_JA = j.Id_JA
                    JOIN nomination n ON n.Id_Disponible = dn.Id_Disponible
                    WHERE j.Actif = 1
                      AND LEFT(lp.CodePostal, 2) = ?
                    GROUP BY j.Id_JA, j.Nom, j.Prenom, j.Email
                    ORDER BY j.Nom, j.Prenom
                ');
                $stmt->execute([$dept]);
                $rows = $stmt->fetchAll();
                break;

            // ── Demande adresse : JA actifs ou sans id_laposte ──────────
            case 'Demande adresse':
                $stmt = $pdo->prepare('
                    SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                           COALESCE(lp.CodePostal, j.Cp)  AS Cp,
                           COALESCE(lp.Nom,        j.Ville) AS Ville,
                           (j.Id_LaPoste IS NOT NULL AND j.Id_LaPoste > 0) AS HasAdresse
                    FROM ja j
                    LEFT JOIN laposte lp ON lp.Id_LaPoste = j.Id_LaPoste
                    WHERE j.Actif = 1 AND (j.Id_LaPoste IS NULL OR j.Id_LaPoste = 0)
                    ORDER BY j.Nom, j.Prenom
                ');
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

        $token = $this->obfuscator()->obfuscate((int) $ja['Id_JA']);
        $vars  = $this->marqueurs($ja, $this->moi(), $token);

        return $this->response->setJSON([
            'ok'    => true,
            'sujet' => strtr($sujet, $vars),
            'corps' => strtr($message, $vars),
        ]);
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
            $stmt       = $pdo->prepare("
                SELECT j.Id_JA, j.Nom, j.Prenom, j.Email,
                       NULL AS Id_Nomination, NULL AS Id_Rencontre,
                       NULL AS Date, NULL AS Heure, NULL AS Journee, NULL AS Poule,
                       NULL AS Division, NULL AS SexeCode, NULL AS NomDom, NULL AS NomExt,
                       NULL AS SalleNom, NULL AS SalleAdresse, NULL AS SalleCP, NULL AS SalleVille,
                       NULL AS CorrNom, NULL AS CorrEmail, NULL AS CorrTel
                FROM ja j
                WHERE j.Id_JA = ? $activeOnly
            ");
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
                SELECT r.Date, r.Heure, dv.Division, ed.Nom AS Dom, ee.Nom AS Ext
                FROM nomination n
                JOIN disponible dn  ON dn.Id_Disponible = n.Id_Disponible
                JOIN rencontre r    ON r.Id_Rencontre = n.Id_Rencontre
                JOIN equipe ed      ON ed.Id_Equipe   = r.Id_EquipeDom
                LEFT JOIN equipe ee ON ee.Id_Equipe   = r.Id_EquipeExt
                JOIN division dv    ON dv.Id_Division = r.Id_Division
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

        $token  = $this->obfuscator()->obfuscate((int) $ja['Id_JA']);
        $scheme = $this->request->getServer('HTTPS') ? 'https' : 'http';
        $host   = $this->request->getServer('HTTP_HOST') ?? 'nijac';
        $urlAdresseJa = site_url('adresse-ja') . '?ja=' . $token;

        $vars = $this->marqueurs($ja, $moi, $token, $listeNoms, $urlAdresseJa);

        $corps      = strtr($message, $vars);
        $sujetRendu = strtr($sujet, $vars);

        // Réduire les suites d'espaces (>2) en un seul espace — les plages d'espaces
        // déclenchent les filtres antispam (Free.fr, OVH…)
        $corps = preg_replace('/ {3,}/', ' ', $corps);

        // Les filtres antispam (Free.fr, etc.) rejettent les emails contenant des images
        // encodées en base64 inline (data:image/...). On les remplace par l'URL hébergée.
        if (str_contains($corps, 'data:image/')) {
            $corps = preg_replace(
                '/src=["\']data:image\/[^;]+;base64,[^"\']*["\']/s',
                'src="' . $scheme . '://' . $host . '/img/FFTT_LIGUE.png"',
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
            if (!empty($moi['email_lntt'])) {
                $mail->addReplyTo($moi['email_lntt'], $moi['nom'] . ' ' . $moi['prenom']);
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
                   dv.Division, RIGHT(dv.Division, 1) AS SexeCode,
                   ed.Nom AS NomDom, ee.Nom AS NomExt,
                   s.Nom AS SalleNom, s.Adresse AS SalleAdresse,
                   lps.CodePostal AS SalleCP, lps.Nom AS SalleVille,
                   co.CorNom AS CorrNom, co.CorEmail AS CorrEmail, co.CorTelephone AS CorrTel
            FROM nomination n
            JOIN disponible dn     ON dn.Id_Disponible = n.Id_Disponible
            JOIN ja j              ON j.Id_JA        = dn.Id_JA
            JOIN rencontre r        ON r.Id_Rencontre = n.Id_Rencontre
            JOIN equipe ed          ON ed.Id_Equipe   = r.Id_EquipeDom
            LEFT JOIN equipe ee     ON ee.Id_Equipe   = r.Id_EquipeExt
            JOIN division dv        ON dv.Id_Division = r.Id_Division
            LEFT JOIN salle s       ON s.Id_Salle     = r.id_Salle
            LEFT JOIN laposte lps   ON lps.Id_LaPoste = s.Id_Laposte
            LEFT JOIN Club co       ON co.Id_Club     = ed.Id_Club
            WHERE n.Id_Nomination = ?
        ');
        $stmt->execute([$idNomination]);

        return $stmt->fetch();
    }

    /**
     * Table de substitution des marqueurs {XXX} pour sujet/corps — partagée
     * par apercu() et envoyerUn(). $listeNominations/$urlAdresseJa restent
     * vides hors de leur contexte (Liste nomination / Demande adresse) ; un
     * marqueur absent du texte n'est simplement jamais substitué (strtr), donc
     * ce périmètre plus large que chaque appel legacy individuel est sans
     * effet observable.
     *
     * {URL_DISPONIBILITE_JA} et {URL_CONVOCATION_JA} remplacent les anciens
     * liens codés en dur vers Nominateur/disponibilite_ja.php et
     * Nominateur/convocation_ja.php (fichiers legacy supprimés, migrés en
     * routes CI4) — à utiliser dans les modèles de message à la place de
     * {URL_LIGUE}/nijac/Nominateur/....
     */
    private function marqueurs(array $ja, array $moi, string $token, string $listeNominations = '', string $urlAdresseJa = ''): array
    {
        $sexe = ($ja['SexeCode'] ?? '') === 'F' ? 'Féminin' : (($ja['SexeCode'] ?? '') === 'M' ? 'Mixte' : '');

        return [
            '{NOM}'                  => $ja['Nom'],
            '{PRENOM}'               => $ja['Prenom'],
            '{NOM_COMPLET}'          => $ja['Prenom'] . ' ' . $ja['Nom'],
            '{ID_JA}'                => $token,
            '{ID_CONVOCATION}'       => (string) ($ja['Id_Nomination'] ?? ''),
            '{SEXE}'                 => $sexe,
            '{UTI_NOM}'              => $moi['nom'] ?? '',
            '{UTI_PRENOM}'           => $moi['prenom'] ?? '',
            '{URL_LIGUE}'            => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
            '{URL_ADRESSE_JA}'       => $urlAdresseJa,
            '{URL_DISPONIBILITE_JA}' => site_url('disponibilite-ja') . '?ja=' . $token,
            '{URL_CONVOCATION_JA}'   => !empty($ja['Id_Nomination']) ? (site_url('convocation-ja') . '?nomination=' . $ja['Id_Nomination']) : '',
            '{YEAR_PHASE}'           => getAnneePhase(),
            '{DATE}'                 => $ja['Date'] ? date('d/m/Y', strtotime($ja['Date'])) : '',
            '{HEURE}'                => $ja['Heure'] ?? '',
            '{JOURNEE}'              => $ja['Journee'] ?? '',
            '{POULE}'                => $ja['Poule'] ?? '',
            '{DIVISION}'             => $ja['Division'] ?? '',
            '{DOM}'                  => $ja['NomDom'] ?? '',
            '{EXT}'                  => $ja['NomExt'] ?? '',
            '{SALLE_NOM}'            => $ja['SalleNom'] ?? '',
            '{SALLE_ADRESSE}'        => $ja['SalleAdresse'] ?? '',
            '{SALLE_CP}'             => $ja['SalleCP'] ?? '',
            '{SALLE_VILLE}'          => $ja['SalleVille'] ?? '',
            '{CORR_NOM}'             => $ja['CorrNom'] ?? '',
            '{CORR_EMAIL}'           => $ja['CorrEmail'] ?? '',
            '{CORR_TEL}'             => $ja['CorrTel'] ?? '',
            '{LISTE_NOMINATIONS}'    => $listeNominations,
        ];
    }
}
