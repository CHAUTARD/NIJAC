---
name: security-audit
description: Audit de sécurité propre à NIJAC (PHP/CodeIgniter 4) — à utiliser pour auditer tout le dépôt, un diff git, un écran EXXX ou un fichier, pour la relecture avant un déploiement FTP en production, ou dès qu'une nouvelle route, un upload, un endpoint public tokenisé ou un envoi d'e-mail est ajouté. Lecture seule : produit un rapport priorisé, ne modifie rien sans l'accord explicite de Patrick.
---

# Audit de sécurité NIJAC

Skill **en lecture seule**. Il produit un rapport ; il ne modifie, ne crée ni ne supprime aucun fichier,
ne lance aucune requête SQL d'écriture et ne fait aucun commit sans accord explicite de Patrick.
Il complète le skill générique `security-review` (diff de branche) par les conventions réelles du dépôt :
ne pas re-dérouler ici une check-list OWASP générique.

## 1. Principe directeur : la frontière de confiance

On ne confirme que des **violations établies d'une frontière de confiance**. Chaque candidat nomme :
1. **Principal de moindre confiance** : visiteur anonyme, porteur d'un lien tokenisé (EN18, EN19, EN21,
   EN22, EC74), JA, Nominateur d'un autre département, CSR, Défiscalisateur, CRA Convoc, admin non CHAUTARD ;
2. **Entrée ou action acceptée** : route + méthode + paramètre (`POST convocation-ja/justificatif`, `nom=`…) ;
3. **Contrôle censé s'appliquer** : filtre, jeton + pepper, `getDepartementsAutorises()`, regex + liste
   serveur, `is_admin`, règle de date J/D… ;
4. **Frontière franchie** ; 5. **Ressource ou principal touché** (autre JA, nomination, département,
   compte admin, fichier) ; 6. **Résultat observable** (donnée lue, ligne modifiée, e-mail envoyé, fichier servi).

Une bonne pratique absente, une hypothèse sur le déploiement ou un défaut de défense en profondeur
**sans frontière atteignable** n'est pas une vulnérabilité : au mieux `Info` ou durcissement suggéré.

## 2. Périmètre et mode d'emploi

- **Audit complet** : tout `ci4/app/` + `config/` + `Classes/` + `.htaccess` racine et des dossiers sensibles.
- **Audit ciblé** : `git diff` (ou `git diff --cached`), un écran (`EXXX` → bloc commenté de
  `ci4/app/Config/Routes.php` → contrôleur → vue `ci4/app/Views/*_index.php`), ou un fichier précis.
  Toujours remonter de la route au contrôleur, puis aux fonctions de `config/app_config.php` appelées.
- **Reconnaissance (audit complet)** : d'abord la carte des surfaces — routes publiques/filtrées (quel
  filtre), uploads, écritures de fichiers (`Importation/`, `_Suivi/`, `_Peage/`, `_Defiscalisation/`,
  `SQL/`), entrées tokenisées (Obfuscator, `cnv`), envois d'e-mails, sorties HTML/JSON/fichiers. Elle
  devient le **registre de couverture** (`surface → vérifiée / non vérifiée`) qui guide la chasse ; une
  surface n'est « vérifiée » que si sa trace source → puits a été lue.
- **Délégation** (règle du projet) : sous-agents **Opus** (une surface ou catégorie chacun), contexte
  minimal, résultat résumé en 3 lignes ; pas de Fable. Validation : § 4.
- **Tests dynamiques** : uniquement en local sur `http://nijac/`, en **lecture seule** (GET sans effet de
  bord). Le skill ne sonde **jamais** la production, l'API FFTT, le SMTP ni aucun service externe ou partagé.
- **Avant de conclure** : relire le diff audité (ou les extraits cités) pour confirmer chaque `fichier:ligne`.
- **Langue** : rapport en français, commençant par « Patrick ». Production déployée **par FTP
  uniquement** : tout correctif = fichier PHP/.htaccess déployable tel quel ; `vendor/` copié, jamais régénéré.

## 3. Check-list par catégorie

Pour chaque point : **Comment** vérifier, puis **OK si** (critère de réussite). Ces points orientent la
chasse ; un point non conforme n'est un constat que s'il satisfait le § 1.

### 3.1 Contrôle d'accès
- **Filtres de routes.** Comment : `grep -n "\$routes->" ci4/app/Config/Routes.php` puis repérer les lignes
  sans `'filter' =>` ; comparer aux alias de `ci4/app/Config/Filters.php` (`auth`, `adminauth`, `csrauth`,
  `defiscauth`, `craconvocauth`). OK si chaque route sans filtre est une route publique documentée
  (login/E007/E008/E010, EN18 `desiderata-club`, EN19 `adresse-ja`, EN21 `convocation-ja*`,
  EN22 `disponibilite-ja*`, EC74 `dispo-cra`) et si le rôle exigé correspond au menu de l'écran.
- **Routes publiques tokenisées.** Comment : lire le contrôleur ; vérifier que chaque action (GET **et**
  POST) re-vérifie le jeton et que la ressource modifiée est bien celle du jeton (ex. EN21 : nomination du
  lien uniquement, `cnv` + pepper ; `voirJustificatif()` lit le nom de fichier depuis la base, jamais du
  client). OK si aucun Id fourni par le client n'est utilisé sans être recoupé avec le jeton.
- **Contrôle par ressource, pas seulement par rôle.** Chaque action par Id (update, delete, envoi,
  fichier servi) recoupe l'Id avec `getDepartementsAutorises()` ou le jeton dans le `WHERE` (un
  Nominateur du 14 ne doit pas agir sur une nomination/un JA/un club du 50 en changeant l'Id : IDOR).
- **Restriction CHAUTARD.** Comment : `grep -n "CHAUTARD" ci4/app/Controllers/*.php`. OK si
  `DbAdminController` (EA98), `FfttTestController` (EA96) et `BugSpidController` testent
  `$_SESSION['utilisateur']['login'] === 'CHAUTARD'` dans le constructeur ou chaque action, en plus de `adminauth`.
- **Contrôles admin internes.** Dans les contrôleurs partagés en `auth` (`SalleController`,
  `JugearbitreController`, `MessagerieController`…), chaque action d'écriture réservée à l'admin teste
  `is_admin` côté serveur. OK si aucun contrôle n'existe seulement côté JS.
- **Périmètre départemental.** Comment : `grep -n "getDepartementsAutorises" -r ci4/app/Controllers`. OK si
  toute lecture/écriture filtrée par département passe par `getDepartementsAutorises()` (jamais de règle
  codée en dur, jamais `id_departement` reçu du client).

### 3.2 Authentification et session
- **2FA (E001 → E010).** Lire `ci4/app/Controllers/AuthController.php` et les fonctions pures
  `emettreCodeSecurite()`, `verifierCodeSecurite()`, `peutRenvoyerCode()`, `enregistrerEchecLogin()`
  (`config/app_config.php`). OK si : `$_SESSION['utilisateur']` n'est créé qu'après le code ; seul un
  `password_hash` du code est stocké dans `mfa_attente` ; expiration, essais et renvois sont bornés
  (constantes `CODE_SECURITE_*`) ; chaque mauvais code compte comme échec de login IP.
- **Kill switch.** `configuration.double_authentification = '0'` : vérifier qu'il est journalisé
  (`[NIJAC][SEC]`) ; son état en production est `à valider` (base de prod hors dépôt).
- **Session.** `session_regenerate_id(true)` après mot de passe **et** après code ; session native
  (`session_start()` dans `config/db.php` avec `httponly`, `samesite=Lax`, `secure` conditionné au HTTPS).
  OK si aucun nouveau code n'utilise le service Session de CI4.
- **Mots de passe.** Uniquement `SecurePasswordHasher::hash()/verify()` ; pas de comparaison `==`.

### 3.3 CSRF
- Comment : `ci4/app/Config/Filters.php` (`globals.before` contient `'csrf'`), chercher toute clé
  `except` ; `ci4/app/Config/Security.php` (`csrfProtection = 'cookie'`, `headerName = 'X-CSRF-Token'`,
  `regenerate = false`). OK si aucune exclusion, aucune action d'écriture en GET
  (`grep -n "\$routes->get(" Routes.php` puis vérifier qu'aucune n'écrit en base ni n'envoie de mail),
  et chaque vue a `<meta name="csrf-token">` + `asset/js/nijac-csrf.js` ou `csrf_field()` (formulaires classiques).

### 3.4 Injection SQL
- Comment : `grep -nE "(query|exec)\(\s*[\"'].*\\\$" -r ci4/app config` et
  `grep -nE "\"(SELECT|UPDATE|DELETE|INSERT)[^\"]*\{?\\\$" -r ci4/app config`.
- OK si toute valeur passe par `prepare()` + paramètres ; les interpolations restantes ne portent que sur
  des constantes, des listes blanches (`ORDER BY`/sens de tri choisis dans un tableau fixe) ou des Ids
  castés `(int)` ; les `IN (...)` sont construits avec des `?` générés ; les `LIKE` échappent `%`/`_` si
  la saisie vient du client. Les helpers `sql*()` de `config/app_config.php` (`sqlJoinSallePrincipale()`…)
  ne reçoivent que des noms de colonnes en dur.

### 3.5 XSS
- Vues : `grep -nE "<\?= *\\\$" ci4/app/Views/*.php | grep -v "esc("` ; OK si chaque sortie non
  échappée est un entier, un `site_url()/base_url()` ou un HTML volontairement construit côté serveur et
  échappé en amont. Données injectées en JS : `json_encode` avec `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT`.
- jQuery : `grep -nE "\.html\(|innerHTML|\.append\(" ci4/app/Views/*.php asset/js/*.js` ; OK si les données
  serveur sont échappées avant concaténation (fonction d'échappement locale) ou passées par `.text()`.
- `nijacToast()` / `nijacConfirm()` : vérifier si le message est inséré en HTML ; si oui, tout message
  contenant des données (noms de JA, clubs) doit être échappé.
- E-mails HTML : marqueurs remplacés (`construireMarqueursMessage()`) échappés, sauf blocs déjà échappés
  documentés (`{COORDONNEES_JA}`).
- Préciser **qui écrit** la donnée : un XSS stocké qui n'atteint que son propre auteur est un auto-impact (§ 6).

### 3.6 Tokens Obfuscator
- Comment : `grep -rn "new \\\\\?Obfuscator(" ci4/app config`. OK si chaque appel passe
  `OBFUSCATOR_SEED, getObfuscatorPepper()` (sans le pepper, les jetons sont recalculables par quiconque
  a le source) et si `deobfuscate()` = `-1` est traité comme refus.
- URLs publiques : aucun Id en clair **seul**. La forme `convocation-ja/(:num)/(:alphanum)` porte
  `Id_Nomination` + jeton `cnv` : OK seulement si le jeton est vérifié contre cet Id.
- **Chaînage** : suivre le jeton jusqu'au bout — le jeton EN21 valide la nomination A ; vérifier
  qu'aucune action suivante (`sauvegarderFrais()`, `joindreJustificatif()`, `voirJustificatif()`) ne
  bascule sur une nomination B via un second paramètre client.
- Pepper `.env` (`OBFUSCATOR_PEPPER`) renseigné en production : `à valider` (`.env` serveur hors dépôt).

### 3.7 Uploads et fichiers
- Référence : `ci4/app/Libraries/JustificatifPeage.php` (`TAILLE_MAX`, `MIMES`, `MOTIF_NOM`, `controler()`,
  `enregistrer()`, `fichiers()`, `reponse()`) et `SuiviNominationController::f131Televerser()`
  (extension `.xlsx`, `getMimeType()` xlsx/zip, `F131_TAILLE_MAX`, `canRead()`, nom `131_<dmYHis>.xlsx`).
- OK si tout nouvel upload : liste blanche MIME via finfo (pas le type client), taille bornée, contrôle du
  contenu (`getimagesize`, `%PDF-`, lecteur Xlsx), nom **généré serveur**, jamais d'extension client
  recopiée, dossier hors exécution PHP.
- **TOCTOU** : le fichier contrôlé est-il celui qui est déplacé/lu ensuite (nom réservé par `fopen('x')`,
  supprimé au rollback, `unlink()` seulement sur une liste recalculée) ?
- **Xlsx = zip + XML (PhpSpreadsheet)** : taille bornée avant lecture, pas de lecture de liens externes ;
  une « bombe zip » réservée à un compte authentifié = disponibilité, rayon limité.
- Lecture/suppression : nom validé par regex stricte (`/D`) **et** présent dans une liste calculée serveur
  (`fichiers()`, `fichiers131()`, `fichiersSuivi()` : glob fixe, pas de symlink, `realpath` directement
  dans le dossier) ; jamais de chemin client. Service via route filtrée (`peageFichier()`, `f131Suivi()`,
  `voirJustificatif()`) avec `nosniff` et `no-store`. **Chaînage** : la route d'affichage doit recouper le
  nom avec la liste serveur **et** avec une ressource du périmètre de l'appelant (ex. `peageFichier()` :
  nomination du périmètre).
- `.htaccess` racine : `RewriteRule ^(SQL|logs|_Defiscalisation|Importation|_Suivi|_Peage)/ - [F,L]` et
  `<FilesMatch "^\.env">` refusé. Comment : lire `.htaccess`, puis `ls -a` de chaque dossier. Double
  protection par `.htaccess` local : présente pour `_Peage/`, `_Suivi/`, `_Defiscalisation/` ; absente
  pour `SQL/`, `Importation/`, `logs/` → défense en profondeur ; que la règle racine soit déployée en
  production est `à valider`. Vérifier qu'aucun nouveau dossier de données n'a été ajouté sans règle.

### 3.8 Secrets
- `.env` et `ci4/.env` ignorés (`git check-ignore -v .env ci4/.env`) et refusés en HTTP. Vérifier
  `git ls-files | grep -i env` : les fichiers suivis (`.env.production!`, `ci4/.env.prod`) ne doivent
  contenir aucune valeur secrète (sinon Élevée).
- ROT47 (`rot47()` dans `config/db.php`) est un **encodage**, pas un chiffrement : un `.env` lu = secrets lus.
- Comment : `grep -rniE "(password|passwd|apikey|app_key|secret|smtp).{0,6}=\s*['\"][^'\"]{4,}" ci4/app config Classes`.
  OK si aucune clé en dur.
- Logs (`error_log`, `logs/`) : pas de mot de passe, code 2FA, jeton complet ni corps de mail sensible.

### 3.9 E-mails
- Comment : `grep -rn "new PHPMailer\|new \\\\PHPMailer" ci4/app config`. OK si tout envoi passe par
  `getNijacMailer()` (renvoie `Classes/NijacMailer.php`, redirection en mode `Developpement`,
  blocage si `email_developpement` vide) et appelle `getEmailDestinataire()`.
- Injection d'en-têtes : sujets/noms issus de la base ou du client sans `\r\n` ; Reply-To/Cc limités aux
  adresses prévues ; destinataires jamais choisis librement par une route publique.
- Anti-spam (règles du dépôt) : `AltBody` via `altBodyDepuisHtml()`, pas de texte caché ni de commentaire HTML.

### 3.10 Données personnelles / RGPD et fuites par fonctionnalités
- Justificatifs de péage, attestations et cartes grises (`_Defiscalisation/`), dumps `SQL/`, fichiers
  d'`Importation/` : uniquement accessibles via routes filtrées, et minimisés (purge, pas de copie ailleurs).
- Exports CSV : chaque champ passe par `csvSafe()` (`config/app_config.php`) contre l'injection de
  formules (`=`, `+`, `-`, `@`). Comment : `grep -rn "fputcsv" ci4/app config` puis vérifier l'appel.
- **Fuites par fonctionnalités** : un export CSV, un journal `_Suivi/suivi_131_*.csv`, une liste de JA,
  une réponse JSON `data()` ou un message d'erreur renvoie-t-il plus que le périmètre de l'appelant
  (autres départements, e-mails/téléphones, chemins serveur, traces SQL) ?
- Pages publiques : n'afficher que les données du JA du jeton (pas d'autres JA, pas de liste).

### 3.11 Configuration production
- `CI_ENVIRONMENT = production` dans le `ci4/.env` du serveur (celui du dépôt est en `development`) :
  `à valider`. `ci4/app/Config/Boot/production.php` force `display_errors = 0` ; pages d'erreur génériques
  (`ci4/app/Views/errors/html/production.php`) ; Debug Toolbar désactivée.
- En-têtes de sécurité : `secureheaders` est commenté dans `Filters.php` et aucun `Header set` dans les
  `.htaccess` ; l'hébergeur peut en ajouter → `à valider`, sinon durcissement (pas de sévérité sans
  scénario de clickjacking ou de sniffing atteignable).
- Cookies : `ci4/app/Config/Cookie.php` (`httponly = true`, `samesite = 'Lax'`, `secure = false`) et
  session native (`secure` selon HTTPS). HTTPS en production : `à valider`.
- `Security::$redirect` ne redirige qu'en production : comportement attendu.

### 3.12 Dépendances
- Lire `composer.lock` (racine : `phpmailer/phpmailer`, `phpoffice/phpspreadsheet`) et `ci4/composer.lock`
  (`codeigniter4/framework`). Relever les versions exactes et les comparer aux avis publiés (GitHub
  Security Advisories, `composer audit` en local si disponible). **Ne jamais citer de CVE sans l'avoir
  vérifiée** ; sinon écrire « version à comparer aux avis de sécurité ». Une CVE n'est `confirmé` que si
  le code de NIJAC atteint la fonction vulnérable avec une entrée contrôlée par un principal moins fiable.
- Vérifier que `vendor/` déployé par FTP correspond au lock (`à valider`).

### 3.13 Logique métier, redirections et requêtes sortantes
- **États de nomination** : accusé (`accuserReceptionConvocation()`, `dateConvocationAccusable()`), frais
  (`fraisSaisissables()`, `ConvocationJaController::etatSaisieFrais()`), règles J/D appliquées côté
  serveur. Rejouer : accuser après D, frais avant D, double POST concurrent (`AccuseReception`,
  `uq_nomination_rencontre`). `f131Appliquer()` ne doit jamais utiliser de lignes reçues du client.
- **Valeurs** : `Peage`/`Kilometre` négatifs, nuls ou non numériques acceptés ? Conséquence (ED51) à démontrer.
- **Redirections / SSRF** : `grep -rn "redirect()->to(\|header('Location\|curl_init" ci4/app config` ;
  OK si la cible est interne (`site_url()`) et l'hôte sortant fixe (`FfttRawClient`), jamais choisi par le client.

## 4. Validation indépendante (chasseur → vérificateur)

1. **Chasse** : sous-agents Opus « chasseurs », un par surface ou catégorie, qui produisent des
   **candidats** (pas des constats) au format du § 1.
2. **Réfutation** : chaque candidat va à un sous-agent Opus **vérificateur distinct**, contexte minimal
   (le candidat + chemins/lignes cités), chargé de le **réfuter** en relisant chaque ligne sans faire
   confiance au chasseur. Seuls les survivants sont reportés.
3. **Contre-vérification** : chaque `confirmé` est revérifié par un **second** vérificateur avant le
   rapport, même en audit rapide.

Modèle de prompt — chasseur :
```
Projet E:\Wamp64\www\NIJAC (lecture seule, aucune modification, aucune requête vers la prod).
Surface : <route/écran/fichiers>. Catégorie : <3.x>.
Cherche des violations de frontière de confiance. Pour chaque candidat, donne :
principal, entrée acceptée, contrôle attendu, frontière franchie, ressource touchée,
résultat observable, et la trace source → puits en fichier:ligne.
N'invente rien : sans ligne exacte, pas de candidat. Signale aussi ce que tu as vérifié sans défaut.
Réponse en 3 lignes de synthèse + la liste des candidats.
```
Modèle de prompt — vérificateur :
```
Projet E:\Wamp64\www\NIJAC (lecture seule). Candidat à RÉFUTER : <candidat complet>.
Ne fais pas confiance à son auteur : relis toi-même chaque fichier:ligne cité et leurs appelants
(filtre de route, contrôle du contrôleur, échappement, regex, liste serveur).
Cherche le contrôle qui bloque le scénario. Verdict : confirmé / à valider / rejeté,
avec la ligne qui le justifie. Si un contrôle requis est hors dépôt (prod), réponds « à valider ».
Si confirmé, borne l'impact réellement démontré. Réponse en 3 lignes.
```

## 5. Verdicts et sévérité

- **`confirmé`** : trace complète source → puits lue ligne à ligne, résultat borné. Seul verdict qui
  reçoit une sévérité, **plafonnée à l'impact démontré**.
- **`à valider`** : fait exact non résolu dans le dépôt — `.htaccess` ou `ci4/.env` du serveur, droits FTP,
  `php.ini`, en-têtes ajoutés par l'hébergeur, contenu de la base de prod. Pas de sévérité. Si un
  contrôle requis n'est pas dans le dépôt, ne présumer ni sa présence ni son absence.
- **`rejeté`** : réfuté (contrôle trouvé, frontière inexistante) ; conservé avec la ligne qui le réfute
  pour ne pas le re-signaler.

Ancrages de sévérité (NIJAC) :
- **Critique** : acteur non authentifié (ou simple porteur d'un lien) qui obtient les données personnelles
  de JA, les justificatifs/cartes grises, un dump `SQL/`, ou un compte administrateur.
- **Élevée** : contournement complet d'un contrôle avec conséquence réelle (Nominateur sur un autre
  département, JA modifiant la nomination d'un autre, écriture admin par un non-admin).
- **Moyenne** : violation à rayon limité (une ressource, condition préalable crédible, déni de service
  par un compte authentifié). **Faible** : divulgation d'éléments internes non secrets (chemins,
  versions, structure de table). **Info** : hygiène, durcissement sans frontière atteignable.

Chaque constat `confirmé` comporte : `fichier:ligne`, catégorie, les 6 éléments du § 1, la trace,
le correctif minimal proposé (déployable par FTP).

## 6. Anti-patterns (à ne jamais reporter comme vulnérabilité)

- Liste de vérifications non conformes présentée comme liste de failles.
- Défense en profondeur sans frontière franchissable (`.htaccess` local absent, règle racine présente).
- Effet d'un parseur exagéré (« PhpSpreadsheet pourrait… ») sans entrée atteignable ni impact démontré.
- Auto-impact (son propre XSS, ses propres frais) traité comme franchissement.
- Test contre la production ou un service partagé (FFTT, SMTP) : interdit, quel que soit le motif.
- Résultat non vérifiable (pas de ligne exacte, scénario supposé) : `à valider` ou `rejeté`.

## 7. Format du rapport

1. « Patrick, » + périmètre audité (complet / diff / écran / fichier) et date.
2. Tableau récapitulatif des `confirmé` : `# | Sévérité | Catégorie | fichier:ligne | Résumé`.
3. Détail de chaque constat confirmé (6 éléments du § 1, trace, correctif minimal).
4. **À valider** (sans sévérité) : le fait à établir et où le vérifier (serveur, `.env` de prod, HTTPS,
   en-têtes hébergeur, `vendor/` déployé).
5. **Rejetés (à ne pas re-signaler)** : candidat, ligne qui le réfute.
6. **Points déjà bien traités** (à ne pas re-signaler sauf régression) : CSRF global mode cookie ;
   2FA E010 avec code haché, quotas et `session_regenerate_id(true)` ; cookies de session httponly/Lax ;
   pepper Obfuscator ; `JustificatifPeage` et `f131Televerser()` (finfo, taille, nom serveur, listes
   serveur + `realpath`) ; `.htaccess` racine bloquant `.env`, `SQL/`, `logs/`, `Importation/`, `_Suivi/`,
   `_Peage/`, `_Defiscalisation/` ; `csvSafe()` sur les journaux 131 ; `NijacMailer` en mode Développement ;
   restriction CHAUTARD d'EA96/EA98. Compléter avec ce qui a été vérifié pendant l'audit.
7. **Couverture** : registre `surface → vérifiée / non vérifiée` (§ 2), surfaces non couvertes en clair.

## 8. Après le rapport

Ne rien corriger sans validation explicite de Patrick. Proposer ensuite un **lot de correctifs priorisé**
(Critique → Info), chaque correctif minimal, indépendant et déployable par FTP, avec les fichiers touchés,
puis attendre son choix avant toute modification.

---
Méthode inspirée de github.com/cloudflare/security-audit-skill, licence MIT, réécrite pour NIJAC.
