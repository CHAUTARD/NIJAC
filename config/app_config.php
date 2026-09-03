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
 * Migrations de schéma appliquées à l'ouverture de l'écran EA98 (seul appelant).
 * Chaque bloc est idempotent : on ne (re)crée que ce qui manque.
 */
function initTableConfiguration(\PDO $pdo): void
{
    // Renommage departement.code -> departement.CodeDept : PK référencée par 2 FK,
    // il faut les retirer, renommer, puis les recréer à l'identique.
    try {
        $ancienne = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departement' AND COLUMN_NAME = 'code'"
        )->fetchColumn();
        if ($ancienne) {
            $pdo->exec('ALTER TABLE equipe_nationale DROP FOREIGN KEY fk_equipenat_dept');
            $pdo->exec('ALTER TABLE utilisateur DROP FOREIGN KEY fk_utilisateur_departement_code');
            $pdo->exec("ALTER TABLE departement CHANGE code CodeDept varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL");
            $pdo->exec("ALTER TABLE equipe_nationale ADD CONSTRAINT fk_equipenat_dept FOREIGN KEY (CodeDept) REFERENCES departement (CodeDept) ON DELETE SET NULL ON UPDATE CASCADE");
            $pdo->exec("ALTER TABLE utilisateur ADD CONSTRAINT fk_utilisateur_departement_code FOREIGN KEY (Id_Departement) REFERENCES departement (CodeDept) ON DELETE SET NULL ON UPDATE CASCADE");
        }
    } catch (\PDOException $e) {
        // best-effort — voir le SQL manuel dans la doc si l'ALTER échoue ici.
    }

    // FK Division -> division.Division sur equipe et equipe_nationale (aucune
    // valeur orpheline en base). ON UPDATE CASCADE : renommage d'un code division
    // propagé ; ON DELETE RESTRICT : impossible de supprimer une division encore
    // référencée.
    foreach ([
        'equipe'           => 'fk_equipe_division',
        'equipe_nationale' => 'fk_equipenat_division',
    ] as $table => $contrainte) {
        try {
            $existe = $pdo->query(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = " . $pdo->quote($table) . "
                   AND CONSTRAINT_NAME = " . $pdo->quote($contrainte)
            )->fetchColumn();
            if (!$existe) {
                $pdo->exec(
                    "ALTER TABLE $table
                     ADD CONSTRAINT $contrainte FOREIGN KEY (Division) REFERENCES division (Division)
                     ON DELETE RESTRICT ON UPDATE CASCADE"
                );
            }
        } catch (\PDOException $e) {
            // best-effort : ne bloque pas EA98 si l'ALTER échoue (droits, données incohérentes…)
        }
    }

    // FK implicites ajoutées (09/2026) : ententes de clubs (equipe.Id_Club2 /
    // Id_Club3), département de rattachement du JA (ja.CodeDept) et
    // disponibilités régionales (disponible_regionale). Idempotent (garde
    // information_schema). Id_Club2/3 et CodeDept : ON DELETE SET NULL (un club
    // ou un département qui disparaît ne doit pas effacer l'équipe / le JA).
    // disponible_regionale : ON DELETE CASCADE (mêmes règles que fk_disponible_ja
    // et que la purge en cascade d'EA84). Aucune valeur orpheline en base au
    // moment de l'ajout — voir SQL/2026-09_fk_manquantes.sql pour le détail /
    // les contrôles de pré-vol côté prod.
    foreach ([
        ['equipe',              'fk_equipe_club2',       'Id_Club2',                'club',                  'Id_Club',                 'SET NULL'],
        ['equipe',              'fk_equipe_club3',       'Id_Club3',                'club',                  'Id_Club',                 'SET NULL'],
        ['ja',                  'fk_ja_departement',     'CodeDept',                'departement',           'CodeDept',                'SET NULL'],
        ['disponible_regionale','fk_dispreg_ja',         'Id_JA',                   'ja',                    'Id_JA',                   'CASCADE'],
        ['disponible_regionale','fk_dispreg_competition', 'Id_CompetitionRegionale', 'competition_regionale', 'Id_CompetitionRegionale', 'CASCADE'],
    ] as [$table, $contrainte, $col, $refTable, $refCol, $onDelete]) {
        try {
            $existe = $pdo->query(
                "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = " . $pdo->quote($table) . "
                   AND CONSTRAINT_NAME = " . $pdo->quote($contrainte)
            )->fetchColumn();
            if (!$existe) {
                if ($onDelete === 'SET NULL') {
                    // chaîne vide -> NULL, sinon la FK échoue sur ces lignes
                    $pdo->exec("UPDATE $table SET $col = NULL WHERE $col = ''");
                }
                $pdo->exec(
                    "ALTER TABLE $table
                     ADD CONSTRAINT $contrainte FOREIGN KEY ($col) REFERENCES $refTable ($refCol)
                     ON DELETE $onDelete ON UPDATE CASCADE"
                );
            }
        } catch (\PDOException $e) {
            // best-effort : ne bloque pas EA98 si l'ALTER échoue (droits, données incohérentes…)
        }
    }

    // Colonnes "référent" du club : 2e contact, mis en copie (Cc) des emails
    // envoyés au correspondant. Mêmes types que CorNom / CorEmail / CorTelephone.
    try {
        $existe = $pdo->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'club' AND COLUMN_NAME = 'RefNom'"
        )->fetchColumn();
        if (!$existe) {
            $pdo->exec(
                "ALTER TABLE Club
                   ADD COLUMN RefNom       varchar(100) DEFAULT NULL AFTER CorTelephone,
                   ADD COLUMN RefMail      varchar(150) DEFAULT NULL AFTER RefNom,
                   ADD COLUMN RefTelephone varchar(20)  DEFAULT NULL AFTER RefMail"
            );
        }
    } catch (\PDOException $e) {
        // best-effort — SQL manuel possible si l'ALTER échoue ici (droits…).
    }

    // Note : le barème kilométrique (table ComptaDefiscalisation + config
    // comptadefisc_majoration_electrique) et les colonnes ja.PuissanceFiscale /
    // ja.VehiculeElectrique (ED51/ED52) sont déjà déployés en dev et en prod par
    // ALTER manuel — pas de bloc de migration ici (voir SPECIFICATION.md ED51/ED52).
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
 * (clé de config 'fftt_api_expiration', format YYYY-MM-DD — voir EA91/EA96), ou null si
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
 * déjà — permet de créer des comptes CSR depuis EA86 (Gestion des utilisateurs) sans migration
 * manuelle. Idempotente, appelée par UtilisateurController.
 */
function assurerRoleCsr(\PDO $pdo): void
{
    ajouterValeurEnum($pdo, 'utilisateur', 'Role', 'CSR', 'JA');
}

/**
 * Ajoute le rôle 'Defiscalisateur' à l'ENUM utilisateur.Role s'il n'y est pas déjà — permet de
 * créer des comptes Défiscalisateur depuis EA86 sans migration manuelle. Idempotente, appelée
 * par UtilisateurController, même principe que assurerRoleCsr().
 */
function assurerRoleDefiscalisateur(\PDO $pdo): void
{
    ajouterValeurEnum($pdo, 'utilisateur', 'Role', 'Defiscalisateur', 'Nominateur');
}

/**
 * Garantit l'existence du type de message système "Expiration FFTT API" (ENUM messagerie.Type +
 * une ligne de gabarit par défaut, marqueurs {DATE_EXPIRATION}/{DELAI}) — éditable ensuite comme
 * les autres modèles système via EA93 (Id_Utilisateur NULL = protégé en écriture pour les non-admin,
 * voir MessagerieController). Idempotente, appelée à la fois par MessagerieController (pour que le
 * gabarit apparaisse dès l'ouverture de l'écran) et par verifierRappelExpirationFfttApi() (filet de
 * sécurité si EA93 n'a jamais été ouvert avant la fenêtre des 60 jours).
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
            . "- la clé de configuration « fftt_api_expiration » (écran Configuration générale, EA91)",
        ]);
}

/**
 * Garantit l'existence du message système n°8 "Dispo régionale" (ENUM messagerie.Type +
 * une ligne de gabarit, marqueur {URL_DISPO_REGIONALE_JA}) — invite un JA à saisir ses
 * disponibilités pour le championnat régional (EN23, dispo-regionale-ja), éditable ensuite
 * comme les autres modèles système via EA93. Id_Messagerie fixé à 8 explicitement (demandé),
 * l'AUTO_INCREMENT de la table ayant déjà dépassé cette valeur. Idempotente.
 */
/**
 * Modèle « JA Club » (Id_Messagerie = 7) : email envoyé au correspondant d'un
 * club recevant en arbitrage club (EN14), demandant le nom du JA qui arbitrera
 * la rencontre via la page publique {URL_ARBITRE_CLUB} (EN25). Remplit la ligne
 * si elle est absente ou vide, sans écraser un contenu déjà saisi via EA93.
 */
function assurerTemplateArbitreClub(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'JA Club');

    $sujet   = 'Championnat Régional - Juge-arbitre pour {DOM} / {EXT} du {DATE}';
    $message = "Bonjour {CORR_NOM},\n\n"
        . "Votre équipe {DOM} reçoit {EXT} le {DATE} à {HEURE}"
        . " (Division {DIVISION}, journée {JOURNEE}, poule {POULE})"
        . " à la salle : {SALLE_NOM} {SALLE_CP} {SALLE_VILLE}.\n\n"
        . "Cette rencontre est en arbitrage club. Merci de nous indiquer le nom du"
        . " juge-arbitre qui la dirigera, en suivant ce lien :\n{URL_ARBITRE_CLUB}\n\n"
        . "À renseigner dans les 5 jours qui suivent la rencontre au maximum.\n\n"
        . "Sportivement,\n{UTI_PRENOM} {UTI_NOM}";

    $row = $pdo->query('SELECT Message FROM messagerie WHERE Id_Messagerie = 7')->fetch();
    if (!$row) {
        $pdo->prepare('INSERT INTO messagerie (Id_Messagerie, Type, Sujet, Message, Id_Utilisateur, Cc, ReplyTo) VALUES (7, ?, ?, ?, NULL, 1, 1)')
            ->execute(['JA Club', $sujet, $message]);
    } elseif (trim((string) $row['Message']) === '') {
        $pdo->prepare('UPDATE messagerie SET Type = ?, Sujet = ?, Message = ?, Cc = 1, ReplyTo = 1 WHERE Id_Messagerie = 7')
            ->execute(['JA Club', $sujet, $message]);
    }
}

/**
 * Garantit l'existence du type de message système « Mot de passe oublié » (ENUM messagerie.Type +
 * une ligne de gabarit par défaut, marqueur {URL_RESET_MDP}) — éditable ensuite comme les autres
 * modèles système via EA93 (Id_Utilisateur NULL = protégé en écriture pour les non-admin).
 * Idempotente, appelée par MessagerieController à l'ouverture de l'écran.
 */
function assurerTemplateMotDePasseOublie(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'Mot de passe oublié');

    $existe = $pdo->query("SELECT 1 FROM messagerie WHERE Type = 'Mot de passe oublié' LIMIT 1")->fetch();
    if ($existe) {
        return;
    }

    $pdo->prepare('INSERT INTO messagerie (Type, Sujet, Message, Id_Utilisateur, Cc) VALUES (?, ?, ?, NULL, 0)')
        ->execute([
            'Mot de passe oublié',
            'NIJAC - Réinitialisation de votre mot de passe',
            "Bonjour {UTI_PRENOM} {UTI_NOM},\n\n"
            . "Une réinitialisation de mot de passe a été demandée pour votre compte NIJAC.\n\n"
            . "Cliquez sur le lien suivant pour choisir un nouveau mot de passe :\n{URL_RESET_MDP}\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.",
        ]);
}

/**
 * Règle de robustesse du mot de passe (E008 « mot de passe oublié ») : 10 caractères
 * minimum, avec au moins une minuscule, une majuscule, un chiffre et un caractère
 * spécial. Retourne null si conforme, sinon le message d'erreur à afficher.
 */
function validerRobustesseMotDePasse(string $mdp): ?string
{
    if (strlen($mdp) < 10)                     return 'Le mot de passe doit contenir au moins 10 caractères.';
    if (!preg_match('/[a-z]/', $mdp))          return 'Le mot de passe doit contenir au moins une lettre minuscule.';
    if (!preg_match('/[A-Z]/', $mdp))          return 'Le mot de passe doit contenir au moins une lettre majuscule.';
    if (!preg_match('/\d/', $mdp))             return 'Le mot de passe doit contenir au moins un chiffre.';
    if (!preg_match('/[^a-zA-Z0-9]/', $mdp))   return 'Le mot de passe doit contenir au moins un caractère spécial.';
    return null;
}

/**
 * Génère un mot de passe aléatoire conforme à validerRobustesseMotDePasse()
 * (au moins un minuscule, un majuscule, un chiffre, un caractère spécial).
 * Caractères ambigus (l/1/I, O/0…) exclus pour la lisibilité. Utilisé par EA86
 * quand l'admin coche « Écraser » (mot de passe provisoire à communiquer).
 */
function genererMotDePasseAleatoire(int $longueur = 12): string
{
    $minu = 'abcdefghijkmnpqrstuvwxyz';
    $maju = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $chif = '23456789';
    $spec = '!@#$%*-_=+';
    $tous = $minu . $maju . $chif . $spec;

    $car = [
        $minu[random_int(0, strlen($minu) - 1)],
        $maju[random_int(0, strlen($maju) - 1)],
        $chif[random_int(0, strlen($chif) - 1)],
        $spec[random_int(0, strlen($spec) - 1)],
    ];
    for ($i = count($car); $i < max($longueur, 10); $i++) {
        $car[] = $tous[random_int(0, strlen($tous) - 1)];
    }
    for ($i = count($car) - 1; $i > 0; $i--) {           // mélange Fisher-Yates
        $j = random_int(0, $i);
        [$car[$i], $car[$j]] = [$car[$j], $car[$i]];
    }

    return implode('', $car);
}

/**
 * Jeton de réinitialisation de mot de passe SANS stockage BDD : la signature HMAC
 * est calculée avec le hash du mot de passe actuel comme clé, donc le jeton devient
 * automatiquement invalide dès que le mot de passe a été changé (usage unique).
 * Format : "<idUtilisateur>-<timestampExpiration>-<hmacSha256>" (tout URL-safe).
 */
function genererJetonResetMdp(int $idUtilisateur, string $hashMdpActuel, int $dureeSecondes = 3600): string
{
    $exp = time() + $dureeSecondes;
    $sig = hash_hmac('sha256', $idUtilisateur . '.' . $exp, $hashMdpActuel . OBFUSCATOR_SEED);
    return $idUtilisateur . '-' . $exp . '-' . $sig;
}

/**
 * Vérifie un jeton produit par genererJetonResetMdp() : retourne l'Id_Utilisateur si
 * le jeton est bien formé, non expiré et signé avec le hash de mot de passe COURANT
 * de l'utilisateur, sinon null. $lookupHash(int $id): ?string doit rendre le hash
 * Password actuel (null si utilisateur inconnu/inactif).
 */
function verifierJetonResetMdp(string $jeton, callable $lookupHash): ?int
{
    if (!preg_match('/^(\d+)-(\d+)-([0-9a-f]{64})$/', $jeton, $m)) {
        return null;
    }
    [, $id, $exp, $sig] = $m;
    if ((int) $exp < time()) {
        return null;
    }
    $hash = $lookupHash((int) $id);
    if (!$hash) {
        return null;
    }
    $attendu = hash_hmac('sha256', $id . '.' . $exp, $hash . OBFUSCATOR_SEED);
    return hash_equals($attendu, $sig) ? (int) $id : null;
}

function assurerTemplateDispoRegionale(\PDO $pdo): void
{
    ajouterTypeMessagerie($pdo, 'Dispo régionale');

    $existe = $pdo->query('SELECT 1 FROM messagerie WHERE Id_Messagerie = 8')->fetch();
    if ($existe) {
        return;
    }

    $pdo->prepare('INSERT INTO messagerie (Id_Messagerie, Type, Sujet, Message, Id_Utilisateur, Cc) VALUES (8, ?, ?, ?, NULL, 0)')
        ->execute([
            'Dispo régionale',
            'Championnat Régional {YEAR_PHASE} - Vos disponibilités',
            "Bonjour {PRENOM} {NOM},\n\n"
            . "Merci de bien vouloir renseigner vos disponibilités pour le Championnat Régional par équipes {YEAR_PHASE} "
            . "en suivant ce lien :\n{URL_DISPO_REGIONALE_JA}\n\n"
            . "Sportivement,\n{UTI_PRENOM} {UTI_NOM}",
        ]);
}

/**
 * Envoie un rappel par email aux administrateurs actifs quand l'expiration des identifiants
 * API FFTT approche (2 mois, soit 60 jours) ou est dépassée — à demander à prolonger auprès de
 * la FFTT, puis à reporter dans .env (FFTT_APP_ID/FFTT_APP_KEY) et dans la clé de config
 * 'fftt_api_expiration'. Sujet/corps viennent du gabarit système "Expiration FFTT API" de la
 * table `messagerie` (éditable via EA93), avec les marqueurs {DATE_EXPIRATION}/{DELAI} — voir
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
 * CentrenvoyeController::marqueurs() (EN15), NominationController::
 * envoyerConvocations() (EN14), InfoRencontreController::
 * designerJaPourRencontre() (EN20) et AdresseJaController::
 * envoyerDemandeAdresse() (EN19). C'est cette divergence qui causait le bug
 * "{URL_CONVOCATION_JA} non remplacé" (EN14 ne connaissait que
 * {LIEN_CONVOCATION}).
 *
 * @param array $ja   Ligne JA : au moins Id_JA, Nom, Prenom.
 * @param array $moi  Utilisateur connecté ($_SESSION['utilisateur']) ; tableau vide pour un envoi sans session.
 * @param array $ctx  Contexte optionnel de la rencontre/convocation — toute clé absente laisse
 *                    le(s) marqueur(s) correspondant(s) vide(s) :
 *                    {PHASE} : numéro de phase (config `phase`, saisi manuellement — distinct de {YEAR_PHASE}
 *                    qui est calculé à partir de la date du jour, voir getAnneePhase()).
 *                    id_nomination, id_rencontre (pour {URL_ARBITRE_CLUB}), sexe_code ('F'|'M'), date, heure, journee, poule, division, dom, ext,
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
    $tokenNomination = !empty($idNomination) ? (new \Obfuscator(OBFUSCATOR_SEED))->obfuscate((int) $idNomination) : '';
    $idRencontre  = $ctx['id_rencontre'] ?? null;
    $tokenRenc    = !empty($idRencontre) ? (new \Obfuscator(OBFUSCATOR_SEED))->obfuscate((int) $idRencontre) : '';

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
        '{URL_ATTESTATION_JA}'   => $token !== '' ? (site_url('attestation-defisc') . '?ja=' . $token) : '',
        '{URL_DISPO_REGIONALE_JA}' => $token !== '' ? (site_url('dispo-regionale-ja') . '?ja=' . $token) : '',
        '{URL_INFO_RENCONTRE}'   => $token !== '' ? (site_url('info-rencontre') . '?ja=' . $token) : '',
        '{URL_CONVOCATION_JA}'   => !empty($idNomination) ? (site_url('convocation-ja') . '?nomination=' . $idNomination . '&cnv=' . $tokenNomination) : '',
        '{URL_ARBITRE_CLUB}'     => $tokenRenc !== '' ? (site_url('arbitre-club') . '?renc=' . $tokenRenc) : '',
        '{YEAR_PHASE}'           => getAnneePhase(),
        '{PHASE}'                => getConfig('phase', '1'),
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
 * Résout le modèle de messagerie à utiliser pour un envoi automatique : d'abord
 * la version personnalisée du Nominateur courant (même Sujet que le modèle
 * système $idMessagerieSysteme, mais Id_Utilisateur = lui), sinon le modèle
 * système par défaut (Id_Utilisateur = 1). Les versions personnalisées sont
 * créées via le bouton "Dupliquer" de EN15 (MessagerieController::duplicate).
 */
function resoudreModeleMessagerie(\PDO $pdo, int $idMessagerieSysteme, int $idUtilisateurCourant): ?array
{
    $stmt = $pdo->prepare('SELECT Sujet, Message, Cc, ReplyTo FROM messagerie WHERE Id_Messagerie = ?');
    $stmt->execute([$idMessagerieSysteme]);
    $systeme = $stmt->fetch();
    if (!$systeme) {
        return null;
    }

    if ($idUtilisateurCourant > 0) {
        $stmt = $pdo->prepare('SELECT Sujet, Message, Cc, ReplyTo FROM messagerie WHERE Sujet = ? AND Id_Utilisateur = ?');
        $stmt->execute([$systeme['Sujet'], $idUtilisateurCourant]);
        $perso = $stmt->fetch();
        if ($perso) {
            return $perso;
        }
    }

    return $systeme;
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
 * qui sont eux-mêmes actifs. Chaque entrée : ['CodeDept' => '80', 'nom' =>
 * 'Somme', 'region' => 'Hauts-de-France'].
 */
function getDepartementsLimitrophes(): array
{
    $actifs = getDeptActifs();
    if (!$actifs) return [];
    $codesActifs = array_column($actifs, 'CodeDept');

    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare("SELECT Limitrophe FROM departement WHERE CodeDept IN ($ph)");
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
            "SELECT d.CodeDept, d.nom, r.nom AS region
             FROM departement d
             LEFT JOIN region r ON r.code = d.code_region
             WHERE d.CodeDept IN ($ph2)
             ORDER BY CAST(d.CodeDept AS UNSIGNED), d.CodeDept"
        );
        $stmt2->execute($codesVoisins);

        return $stmt2->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Départements de la même région que $dept qui lui sont limitrophes :
 * colonne departement.Limitrophe ∩ départements de même code_region.
 * Chaque entrée : ['CodeDept' => '27', 'nom' => 'Eure']. Utilisé par EN13 pour
 * proposer d'étendre l'affichage aux départements voisins.
 */
function getLimitrophesRegion(string $dept): array
{
    $dept = trim($dept);
    if ($dept === '') return [];
    try {
        $pdo  = getPDO();
        $stmt = $pdo->prepare('SELECT code_region, Limitrophe FROM departement WHERE CodeDept = ?');
        $stmt->execute([$dept]);
        $r = $stmt->fetch();
        if (!$r || $r['code_region'] === null || $r['code_region'] === '' || empty($r['Limitrophe'])) {
            return [];
        }
        $voisins = array_values(array_filter(array_map('trim', explode(';', $r['Limitrophe']))));
        if (!$voisins) return [];

        $ph   = implode(',', array_fill(0, count($voisins), '?'));
        $stmt = $pdo->prepare(
            "SELECT CodeDept, nom FROM departement
             WHERE CodeDept IN ($ph) AND code_region = ?
             ORDER BY CAST(CodeDept AS UNSIGNED), CodeDept"
        );
        $stmt->execute([...$voisins, $r['code_region']]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Retourne les départements actifs (depuis departements_actifs en configuration)
 * avec leur nom (depuis la table departement), triés par code numérique.
 * Chaque entrée : ['CodeDept' => '14', 'nom' => 'Calvados']
 */
function getDeptActifs(): array
{
    $codesActifs = array_filter(array_map('trim', explode(',', getConfig('departements_actifs', ''))));
    if (!$codesActifs) return [];
    try {
        $pdo = getPDO();
        $ph  = implode(',', array_fill(0, count($codesActifs), '?'));
        $stmt = $pdo->prepare(
            "SELECT CodeDept, nom FROM departement
             WHERE CAST(CodeDept AS UNSIGNED) IN ($ph)
             ORDER BY CAST(CodeDept AS UNSIGNED)"
        );
        $stmt->execute(array_map('intval', $codesActifs));
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Table de correspondance code division => libellé (colonne division.Nom), triée
 * par division.Ord. Alimente les listes déroulantes « Division » (EA82, EA83,
 * EA92, EA94, EA95), affichées « code — Nom ». Cache statique par requête.
 *
 * @return array<string, string>
 */
function getDivisionNoms(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = getPDO()->query('SELECT Division, Nom FROM division ORDER BY Ord')->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\PDOException $e) {
            $cache = [];
        }
    }

    return $cache;
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
