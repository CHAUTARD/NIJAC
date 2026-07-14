<?php
/**
 * Helpers de configuration applicative NIJAC.
 *
 * Nécessite que getPDO() soit disponible (config/db.php déjà chargé).
 * Cache statique : la BDD n'est interrogée qu'une seule fois par requête.
 */

/** Termine une action AJAX avec succès. */
function jsonOk(array $data = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $data);
    exit;
}

/** Termine une action AJAX avec une erreur. */
function jsonError(string $msg, int $httpCode = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    if ($httpCode !== 200) http_response_code($httpCode);
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

/**
 * Crée la table configuration si absente et insère les paramètres par défaut.
 */
function initTableConfiguration(\PDO $pdo): void
{
}

/**
 * Retourne la valeur du marqueur {YEAR_PHASE} : les 4 premiers caractères de
 * la saison en Phase 1 (ex "2026"), la saison complète en Phase 2 (ex "2026-2027").
 * Phase courante déterminée à partir des bornes de config phase2_debut/phase2_fin (MM-JJ).
 */
function getAnneePhase(): string
{
    $saison = getConfig('saison', date('Y') . '-' . (date('Y') + 1));

    $today = new \DateTime();
    $md    = (int)$today->format('m') * 100 + (int)$today->format('d');
    $toMd  = fn(string $s) => (int)substr($s, 0, 2) * 100 + (int)substr($s, 3, 2);

    $p2Debut = $toMd(getConfig('phase2_debut', '02-01'));
    $p2Fin   = $toMd(getConfig('phase2_fin',   '06-30'));

    $enPhase2 = $md >= $p2Debut && $md <= $p2Fin;

    return $enPhase2 ? $saison : substr($saison, 0, 4);
}

/**
 * Retourne le gentilé de la région configurée (clé 'region' dans configuration).
 * Ex: "Normand(e)" pour "Normandie". Fallback sur le nom de la région.
 */
function getRegionGentile(): string
{
    $nom = getConfig('region', '');
    if ($nom === '') return '';
    try {
        $stmt = getPDO()->prepare("SELECT COALESCE(Gentile, nom) FROM region WHERE nom = ? LIMIT 1");
        $stmt->execute([$nom]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string)$val : $nom;
    } catch (\Throwable $e) {
        return $nom;
    }
}

/**
 * Retourne toute la configuration sous forme de tableau associatif cle => valeur.
 */
function getAllConfig(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = [];
            $rows  = getPDO()->query('SELECT cle, valeur FROM configuration')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['cle']] = $r['valeur'];
            }
        } catch (\Throwable $e) {
            $cache = [];
        }
    }
    return $cache;
}

/**
 * Retourne la valeur d'une clé de configuration.
 */
function getConfig(string $cle, string $defaut = ''): string
{
    return getAllConfig()[$cle] ?? $defaut;
}

/**
 * Retourne l'email effectif selon l'état du logiciel.
 * En mode Développement, redirige vers l'adresse configurée dans email_developpement.
 */
function getEmailDestinataire(string $email): string
{
    if (getConfig('etat_logiciel', 'Developpement') === 'Developpement') {
        return getConfig('email_developpement', 'patrick.chautard@free.fr');
    }
    return $email;
}

/**
 * Retourne le nombre de jours restants avant l'expiration des identifiants API FFTT
 * (clé de config 'fftt_api_expiration', format YYYY-MM-DD — voir E015/E018), ou null si
 * la clé n'est pas configurée. Négatif si la date est déjà dépassée.
 */
function getFfttApiJoursAvantExpiration(): ?int
{
    $exp = getConfig('fftt_api_expiration', '');
    if ($exp === '') {
        return null;
    }
    $date = \DateTime::createFromFormat('Y-m-d', $exp);
    if (!$date) {
        return null;
    }

    return (int) (new \DateTime('today'))->diff($date)->format('%r%a');
}

/**
 * Ajoute une valeur à l'ENUM d'une colonne si elle n'y est pas déjà — relit l'ENUM existant via
 * SHOW COLUMNS pour ne perdre aucune valeur déjà en place. $defaut doit correspondre au DEFAULT
 * actuel de la colonne (MySQL exige de le repréciser sur un MODIFY COLUMN). $table/$colonne ne
 * sont jamais fournis par l'utilisateur (toujours des littéraux au point d'appel).
 */
function ajouterValeurEnum(\PDO $pdo, string $table, string $colonne, string $valeur, string $defaut): void
{
    $col = $pdo->query("SHOW COLUMNS FROM $table WHERE Field = '$colonne'")->fetch();
    if (!$col || !preg_match("/^enum\((.+)\)$/i", $col['Type'], $m)) {
        return;
    }
    $valeurs = array_map('trim', str_getcsv($m[1], ',', "'"));
    if (in_array($valeur, $valeurs, true)) {
        return;
    }
    $valeurs[] = $valeur;
    $enumList  = implode(',', array_map(fn ($v) => $pdo->quote($v), $valeurs));
    $pdo->exec("ALTER TABLE $table MODIFY COLUMN $colonne ENUM($enumList) NOT NULL DEFAULT " . $pdo->quote($defaut));
}

/**
 * Ajoute une valeur à l'ENUM messagerie.Type si elle n'y est pas déjà (ex. pour un nouveau type
 * de message système), même lecture dynamique que MessagerieController::typesValides().
 */
function ajouterTypeMessagerie(\PDO $pdo, string $type): void
{
    ajouterValeurEnum($pdo, 'messagerie', 'Type', $type, 'Disponibilites');
}

/**
 * Ajoute le rôle 'CSR' (Commission Sportive Régionale) à l'ENUM utilisateur.Role s'il n'y est pas
 * déjà — permet de créer des comptes CSR depuis E009 (Gestion des utilisateurs) sans migration
 * manuelle. Idempotente, appelée par UtilisateurController.
 */
function assurerRoleCsr(\PDO $pdo): void
{
    ajouterValeurEnum($pdo, 'utilisateur', 'Role', 'CSR', 'JA');
}

/**
 * Garantit l'existence du type de message système "Expiration FFTT API" (ENUM messagerie.Type +
 * une ligne de gabarit par défaut, marqueurs {DATE_EXPIRATION}/{DELAI}) — éditable ensuite comme
 * les autres modèles système via E026 (Id_Utilisateur NULL = protégé en écriture pour les non-admin,
 * voir MessagerieController). Idempotente, appelée à la fois par MessagerieController (pour que le
 * gabarit apparaisse dès l'ouverture de l'écran) et par verifierRappelExpirationFfttApi() (filet de
 * sécurité si E026 n'a jamais été ouvert avant la fenêtre des 60 jours).
 */
function assurerTemplateExpirationFfttApi(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'Expiration FFTT API');

    $existe = $pdo->query("SELECT 1 FROM messagerie WHERE Type = 'Expiration FFTT API' LIMIT 1")->fetch();
    if ($existe) {
        return;
    }

    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc) VALUES (?, ?, ?, NULL, 0)')
        ->execute([
            'Expiration FFTT API',
            'Expiration des identifiants API FFTT le {DATE_EXPIRATION}',
            "Les identifiants de l'API FFTT (Code Appli / Mot de passe) utilisés par NIJAC expirent le {DATE_EXPIRATION} ({DELAI}).\n\n"
            . "Merci de faire la demande de prolongation auprès de la FFTT, puis de mettre à jour :\n"
            . "- le fichier .env (FFTT_APP_ID / FFTT_APP_KEY)\n"
            . "- la clé de configuration « fftt_api_expiration » (écran Configuration générale, E015)",
        ]);
}

/**
 * Envoie un rappel par email aux administrateurs actifs quand l'expiration des identifiants
 * API FFTT approche (2 mois, soit 60 jours) ou est dépassée — à demander à prolonger auprès de
 * la FFTT, puis à reporter dans .env (FFTT_APP_ID/FFTT_APP_KEY) et dans la clé de config
 * 'fftt_api_expiration'. Sujet/corps viennent du gabarit système "Expiration FFTT API" de la
 * table `messagerie` (éditable via E026), avec les marqueurs {DATE_EXPIRATION}/{DELAI} — voir
 * assurerTemplateExpirationFfttApi(). Envoyé au plus une fois par date d'expiration (mémorisé
 * dans la clé 'fftt_api_expiration_email_envoye') pour ne pas spammer à chaque connexion admin
 * (voir AuthController::index(), seul appelant). Best-effort : erreurs SMTP/BDD avalées, ce
 * rappel ne doit jamais faire échouer la connexion.
 */
function verifierRappelExpirationFfttApi(): void
{
    try {
        $exp = getConfig('fftt_api_expiration', '');
        if ($exp === '') {
            return;
        }
        $joursRestants = getFfttApiJoursAvantExpiration();
        if ($joursRestants === null || $joursRestants > 60) {
            return;
        }
        if (getConfig('fftt_api_expiration_email_envoye', '') === $exp) {
            return; // déjà envoyé pour cette date d'expiration
        }

        $pdo = getPDO();
        assurerTemplateExpirationFfttApi($pdo);

        $tpl = $pdo->query("SELECT Sujet, Message FROM messagerie WHERE Type = 'Expiration FFTT API' LIMIT 1")->fetch();
        if (!$tpl) {
            return;
        }

        $dateFmt   = (\DateTime::createFromFormat('Y-m-d', $exp))->format('d/m/Y');
        $delai     = $joursRestants >= 0 ? "dans $joursRestants jour(s)" : ('depuis ' . abs($joursRestants) . ' jour(s)');
        $marqueurs = ['{DATE_EXPIRATION}' => $dateFmt, '{DELAI}' => $delai];
        $rendu     = remplacerMarqueursMessage($tpl['Sujet'], $tpl['Message'], $marqueurs);

        $emails = $pdo->query(
            "SELECT Email FROM utilisateur WHERE Role = 'Administrateur' AND Actif = 1 AND Email IS NOT NULL AND Email <> ''"
        )->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($emails as $email) {
            try {
                $mail = getNijacMailer();
                $mail->isHTML(strip_tags($rendu['corps']) !== $rendu['corps']);
                $mail->addAddress(getEmailDestinataire($email));
                $mail->Subject = $rendu['sujet'];
                $mail->Body    = $rendu['corps'];
                $mail->send();
            } catch (\Throwable $e) {
            }
        }

        $pdo->prepare('INSERT INTO configuration (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')
            ->execute(['fftt_api_expiration_email_envoye', $exp]);
    } catch (\Throwable $e) {
    }
}

/**
 * Construit la table de correspondance des marqueurs {XXX} des modèles de
 * message (table `messagerie`) — source unique remplaçant les listes de
 * marqueurs dupliquées et divergentes qui existaient dans
 * CentrenvoyeController::marqueurs() (E024), NominationController::
 * envoyerConvocations() (E022), InfoRencontreController::
 * designerJaPourRencontre() (E030) et AdresseJaController::
 * envoyerDemandeAdresse() (E029). C'est cette divergence qui causait le bug
 * "{URL_CONVOCATION_JA} non remplacé" (E022 ne connaissait que
 * {LIEN_CONVOCATION}).
 *
 * @param array $ja   Ligne JA : au moins Id_JA, Nom, Prenom.
 * @param array $moi  Utilisateur connecté ($_SESSION['utilisateur']) ; tableau vide pour un envoi sans session.
 * @param array $ctx  Contexte optionnel de la rencontre/convocation — toute clé absente laisse
 *                    le(s) marqueur(s) correspondant(s) vide(s) :
 *                    id_nomination, sexe_code ('F'|'M'), date, heure, journee, poule, division, dom, ext,
 *                    salle_nom, salle_adresse, salle_cp, salle_ville,
 *                    corr_nom, corr_email, corr_tel, liste_nominations (HTML de {LISTE_NOMINATIONS}).
 * @return array<string,string> Table marqueur => valeur, prête pour remplacerMarqueursMessage().
 */
function construireMarqueursMessage(array $ja, array $moi = [], array $ctx = []): array
{
    require_once __DIR__ . '/../Classes/Obfuscator.php';

    $idJa         = (int)($ja['Id_JA'] ?? 0);
    $token        = $idJa > 0 ? (new \Obfuscator(OBFUSCATOR_SEED))->obfuscate($idJa) : '';
    $idNomination = $ctx['id_nomination'] ?? null;

    $sexe = match ($ctx['sexe_code'] ?? '') {
        'F'     => 'Féminin',
        'M'     => 'Mixte',
        default => '',
    };

    return [
        '{NOM}'                  => $ja['Nom'] ?? '',
        '{PRENOM}'               => $ja['Prenom'] ?? '',
        '{NOM_COMPLET}'          => trim(($ja['Prenom'] ?? '') . ' ' . ($ja['Nom'] ?? '')),
        '{ID_JA}'                => $token,
        '{ID_CONVOCATION}'       => $idNomination !== null ? (string)$idNomination : '',
        '{SEXE}'                 => $sexe,
        '{UTI_NOM}'              => $moi['nom'] ?? '',
        '{UTI_PRENOM}'           => $moi['prenom'] ?? '',
        '{URL_LIGUE}'            => getConfig('url_ligue', 'https://www.ligue-normandie-tt.fr'),
        '{URL_ADRESSE_JA}'       => $token !== '' ? (site_url('adresse-ja') . '?ja=' . $token) : '',
        '{URL_DISPONIBILITE_JA}' => $token !== '' ? (site_url('disponibilite-ja') . '?ja=' . $token) : '',
        '{URL_CONVOCATION_JA}'   => !empty($idNomination) ? (site_url('convocation-ja') . '?nomination=' . $idNomination) : '',
        '{YEAR_PHASE}'           => getAnneePhase(),
        '{DATE}'                 => !empty($ctx['date']) ? date('d/m/Y', strtotime($ctx['date'])) : '',
        '{HEURE}'                => substr((string)($ctx['heure'] ?? ''), 0, 5),
        '{JOURNEE}'              => $ctx['journee']  ?? '',
        '{POULE}'                => $ctx['poule']    ?? '',
        '{DIVISION}'             => $ctx['division'] ?? '',
        '{DOM}'                  => $ctx['dom']       ?? '',
        '{EXT}'                  => $ctx['ext']       ?? '',
        '{SALLE_NOM}'            => $ctx['salle_nom']     ?? '',
        '{SALLE_ADRESSE}'        => $ctx['salle_adresse'] ?? '',
        '{SALLE_CP}'             => $ctx['salle_cp']      ?? '',
        '{SALLE_VILLE}'          => $ctx['salle_ville']   ?? '',
        '{CORR_NOM}'             => $ctx['corr_nom']   ?? '',
        '{CORR_EMAIL}'           => $ctx['corr_email'] ?? '',
        '{CORR_TEL}'             => $ctx['corr_tel']   ?? '',
        '{LISTE_NOMINATIONS}'    => $ctx['liste_nominations'] ?? '',
    ];
}

/**
 * Substitue les marqueurs {XXX} dans un couple sujet/corps de message, à
 * partir de la table retournée par construireMarqueursMessage().
 *
 * @return array{sujet: string, corps: string}
 */
function remplacerMarqueursMessage(string $sujet, string $corps, array $marqueurs): array
{
    return [
        'sujet' => strtr($sujet, $marqueurs),
        'corps' => strtr($corps, $marqueurs),
    ];
}

/**
 * Vérifie si l'utilisateur peut envoyer $nb emails supplémentaires.
 * Utilise une fenêtre glissante stockée en session.
 *
 * Retourne null si l'envoi est autorisé, ou un message d'erreur sinon.
 * Appeler enregistrerEnvois() après chaque email effectivement envoyé.
 */
function checkRateLimit(int $nb): ?string
{
    $max     = (int)getConfig('rate_limit_max',     '100');
    $fenetre = (int)getConfig('rate_limit_fenetre',  '10');
    $now     = time();
    $debut   = $now - $fenetre * 60;

    // Purger les horodatages expirés
    $_SESSION['nijac_rate_limit'] = array_values(array_filter(
        $_SESSION['nijac_rate_limit'] ?? [],
        fn(int $ts) => $ts > $debut
    ));

    $deja = count($_SESSION['nijac_rate_limit']);
    if ($deja + $nb > $max) {
        $plus_ancien  = $_SESSION['nijac_rate_limit'][0] ?? $now;
        $attente      = (int)ceil(($plus_ancien + $fenetre * 60 - $now) / 60);
        return "Limite d'envoi atteinte ({$max} emails / {$fenetre} min). "
             . "Il reste {$deja} email(s) comptabilisé(s). "
             . "Réessayez dans environ {$attente} minute(s).";
    }
    return null;
}

/**
 * Enregistre $nb envois réussis dans la fenêtre glissante.
 */
function enregistrerEnvois(int $nb): void
{
    $now = time();
    for ($i = 0; $i < $nb; $i++) {
        $_SESSION['nijac_rate_limit'][] = $now;
    }
}

/**
 * Retourne la liste des départements qu'un utilisateur est autorisé à voir,
 * en appliquant les règles d'association définies dans configuration.php
 * (paramètre 'regles_departements', ex: {"76":["27"]} → un utilisateur du 76
 * voit aussi les rencontres du 27).
 */
function getDepartementsAutorises(?string $deptUtilisateur): array
{
    if (!$deptUtilisateur) return [];
    $regles = json_decode(getConfig('regles_departements', '{}'), true) ?: [];
    $autorises = [$deptUtilisateur];
    if (!empty($regles[$deptUtilisateur]) && is_array($regles[$deptUtilisateur])) {
        $autorises = array_merge($autorises, $regles[$deptUtilisateur]);
    }
    return array_values(array_unique($autorises));
}

/**
 * Retourne les départements limitrophes de la région, calculés (plus de
 * paramètre 'departements_limitrophes' en configuration) à partir de la
 * colonne departement.Limitrophe des départements actifs (getDeptActifs) :
 * union des codes limitrophes de chaque département actif, en excluant ceux
 * qui sont eux-mêmes actifs. Chaque entrée : ['code' => '80', 'nom' =>
 * 'Somme', 'region' => 'Hauts-de-France'].
 */
function getDepartementsLimitrophes(): array
{
    $actifs = getDeptActifs();
    if (!$actifs) return [];
    $codesActifs = array_column($actifs, 'code');

    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare("SELECT Limitrophe FROM departement WHERE code IN ($ph)");
        $stmt->execute($codesActifs);

        $codesVoisins = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $val) {
            if ($val) {
                $codesVoisins = array_merge($codesVoisins, array_filter(array_map('trim', explode(';', $val))));
            }
        }
        $codesVoisins = array_values(array_diff(array_unique($codesVoisins), $codesActifs));
        if (!$codesVoisins) return [];

        $ph2 = implode(',', array_fill(0, count($codesVoisins), '?'));
        $stmt2 = $pdo->prepare(
            "SELECT d.code, d.nom, r.nom AS region
             FROM departement d
             LEFT JOIN region r ON r.code = d.code_region
             WHERE d.code IN ($ph2)
             ORDER BY CAST(d.code AS UNSIGNED), d.code"
        );
        $stmt2->execute($codesVoisins);

        return $stmt2->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Retourne les départements actifs (depuis departements_actifs en configuration)
 * avec leur nom (depuis la table departement), triés par code numérique.
 * Chaque entrée : ['code' => '14', 'nom' => 'Calvados']
 */
function getDeptActifs(): array
{
    $codesActifs = array_filter(array_map('trim', explode(',', getConfig('departements_actifs', ''))));
    if (!$codesActifs) return [];
    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare(
            "SELECT code, nom FROM departement
             WHERE CAST(code AS UNSIGNED) IN ($ph)
             ORDER BY CAST(code AS UNSIGNED)"
        );
        $stmt->execute(array_map('intval', $codesActifs));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Retourne un client FFTT bas niveau (App\Libraries\FfttRawClient) pour les
 * endpoints non couverts par la façade FFTTApi de alamirault/fftt-api
 * (xml_division, xml_result_equ avec cx_poule, xml_licence,
 * xml_liste_joueur_o avec le champ echelon…). Remplace getFfttApi()/
 * Classes/FfttApi.php (supprimés) — plus de serial applicatif séparé, le
 * même schéma d'authentification (id/appKey) que la façade est réutilisé
 * partout. Lance une exception si les credentials ne sont pas renseignés.
 *
 * N'est utilisable que depuis un contrôleur CI4 (App\Libraries\FfttRawClient
 * n'est autoloadable qu'une fois l'autoloader Composer de ci4/ chargé).
 */
function getFfttRawClient(): \App\Libraries\FfttRawClient
{
    $appId  = getFfttAppId();
    $appKey = getFfttAppKey();
    if ($appId === '' || $appKey === '') {
        throw new \RuntimeException('API FFTT non configurée. Renseignez FFTT_APP_ID et FFTT_APP_KEY dans .env.');
    }

    return new \App\Libraries\FfttRawClient($appId, $appKey);
}

/**
 * Indique si le logiciel est en mode développement.
 */
function isModeDeveloppement(): bool
{
    return getConfig('etat_logiciel', 'Developpement') === 'Developpement';
}

/**
 * Retourne une instance PHPMailer préconfigurée avec les paramètres SMTP.
 * Host/port/sécurité/expéditeur viennent de la table `configuration` ; l'utilisateur
 * et le mot de passe viennent de .env (SMTP_USER / SMTP_PASSWORD, encodés ROT47).
 * Lance une exception en cas d'erreur de configuration.
 */
function getNijacMailer(string $forcedPrefix = null): \PHPMailer\PHPMailer\PHPMailer
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->CharSet   = 'UTF-8';
    $mail->Encoding  = 'quoted-printable';

    $p = $forcedPrefix ?? 'smtp_';

    $debugLevel = (int)getConfig($p . 'debug', '0');
    $mail->SMTPDebug = $debugLevel;
    if ($debugLevel > 0) {
        $logFile = __DIR__ . '/../logs/smtp_debug.log';
        @mkdir(dirname($logFile), 0755, true);
        $mail->Debugoutput = function (string $str, int $level) use ($logFile) {
            file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $str . PHP_EOL, FILE_APPEND);
        };
    }
    $mail->Hostname  = gethostname() ?: 'nijac.ligue-normandie-tt.fr';

    $host   = getConfig($p . 'host', '');
    $secure = getConfig($p . 'secure', 'tls');
    $port   = (int)getConfig($p . 'port', '587');
    $auth   = getConfig($p . 'auth', '1') === '1';

    if ($host !== '') {
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->Port       = $port;
        $mail->SMTPAuth   = $auth;
        $mail->SMTPSecure = $secure === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : ($secure === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        if ($auth) {
            $mail->Username = getSmtpUser();
            $mail->Password = getSmtpPassword();
        }
    } else {
        throw new \RuntimeException('SMTP non configuré. Veuillez renseigner les paramètres SMTP dans la configuration.');
    }

    $mail->setFrom(
        getConfig($p . 'from', 'patrick.chautard@free.fr'),
        getConfig($p . 'from_name', 'NIJAC')
    );

    return $mail;
}
