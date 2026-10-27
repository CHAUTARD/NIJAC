# NIJAC – Nomination des Juges-Arbitres en Championnats

Application web PHP/MySQL de gestion et de nomination des Juges-Arbitres (JA) pour la **Ligue de Normandie de Tennis de Table**.

Elle couvre l'ensemble du cycle de vie d'une nomination : import des JA et des clubs depuis l'API/les fichiers FFTT, désidératas des clubs et des équipes, saisie des disponibilités, affectation aux rencontres, envoi des convocations, saisie des frais de déplacement et défiscalisation.

L'application est entièrement implémentée en **CodeIgniter 4** (dossier `ci4/`). Il n'existe plus d'application PHP autonome à la racine : celle-ci a été intégralement remplacée par le portage CI4. La racine du dépôt ne conserve que les éléments partagés, indépendants du framework, dont `ci4/` dépend (voir [Architecture](#architecture)).

---

## Fonctionnalités principales

Le détail complet de chaque écran (fonctionnalités, fichier source, règles métier) est disponible dans [Ecrans.md](Ecrans.md), avec les spécifications détaillées dans [SPECIFICATION.md](SPECIFICATION.md). Une cinquantaine d'écrans est actuellement en service, répartis par menu :

| Plage de code | Menu | Contenu |
|---|---|---|
| E001–E010 | Connexion / mots de passe | Connexion, menus (Admin, Nominateur, CSR, Défiscalisateur, CRA Convoc), changement et réinitialisation de mot de passe, code de sécurité |
| E011 + EJA2–EJA4 | Menu JA (compte juge-arbitre) — plage « EJA » pour ses écrans | Menu JA (E011), Mes disponibilités (EJA2), Mes nominations (EJA3), Mes frais / péages / défiscalisation (EJA4) |
| EN11–EN30 | Nominateur + pages publiques JA | Gestion des JA et des clubs, désidératas, disponibilités, nomination aux rencontres, centre d'envoi, convocation/frais, statistiques, suivi des nominations… |
| ES31–ES33 | CSR (Commission Sportive Régionale) | Réengagement des clubs, souhaits des équipes |
| ED51–ED55 | Défiscalisateur | Défiscalisation des JA, barème kilométrique, attestations, comptes EBP |
| EA81–EA99 | Administrateur | Salles, imports FFTT, utilisateurs, communes, régions/départements, divisions, équipes, rencontres, configuration, messagerie, test API FFTT, administration base de données, sécurité TOTP |

Quelques écrans marquants :

| Code | Titre | Description |
|------|-------|-------------|
| E001 | Connexion | Authentification, redirection vers le menu du rôle principal, forçage du changement de mot de passe |
| EN11 | Juges-Arbitres | Import FFTT (API ou fichier), fiche JA (grade, club, commune, défiscalisation, nationale) |
| EN14 | Nomination | Affectation JA ↔ rencontres selon les règles métier (nomination valide d'office), envoi des convocations (+ copie sans lien aux correspondants/référents des 2 clubs), feuille de pointage PDF de la journée |
| EN21 | Convocation et frais JA | Page publique tokenisée : consultation de la convocation, saisie des frais (péage, km, défiscalisation) et accusé de réception de la convocation par le JA |
| EN27 | Clubs / Associations | Import et gestion des clubs affiliés (upsert depuis l'API FFTT) |
| EN28 | Suivi des nominations | Suivi des frais saisis par les JA et des accusés de réception, correction et relance, mise à jour FFTT 131 (analyse sans écriture, puis JA renseignés sur les rencontres R3M / R4M sans JA nommé, journal CSV dans `_Suivi/`) — l'ancien écran EN30 « Contrôle FFTT 131 » a été supprimé (numéro libre) |
| EA91 | Configuration | Paramètres applicatifs (état logiciel, SMTP, phases, frais kilométriques…) |
| EA98 | Administration BDD | Requêteur SQL libre, structure des tables, accès restreint (compte CHAUTARD + 2FA TOTP déverrouillée sur EA99 ; mot de passe redemandé pour exécuter) |
| EA99 | Sécurité TOTP | Activation (QR code, codes de secours) et saisie du code TOTP exigé par EA96 / EA97 / EA98 (compte CHAUTARD) |

---

## Prérequis

- **PHP ≥ 8.2** avec les extensions `pdo_mysql`, `bcmath`, `mbstring`, `intl`
- **MySQL / MariaDB** (port 3307 en local WAMP)
- **Composer**, pour les deux jeux de dépendances (racine et `ci4/`)

## Installation

```bash
composer install          # racine : PhpSpreadsheet + PHPMailer (import Excel, envoi des emails)
cd ci4 && composer install # ci4/ : le framework CodeIgniter 4 lui-même
```

## Accès local

Le site attend un vhost WAMP dédié `http://nijac/`, dont le `DocumentRoot` pointe directement sur ce dossier (voir `httpd-vhosts.conf` et le fichier `hosts` Windows), et non `http://localhost/NIJAC/` : `Config\App::$baseURL` (`ci4/.env`) n'a pas de segment de chemin, donc tous les liens générés par `site_url()`/`base_url()` seraient faux sous le vhost par défaut. Le fichier `.htaccess` racine renvoie tout vers `ci4/public/index.php`, sauf les fichiers/dossiers réels (`asset/`, `img/`, `logs/`, `ci4/`) et les chemins explicitement bloqués (`.env*`, `SQL/`, `Importation/`).

En production, l'application est déployée sous un chemin (`.../nijac/`) : `app.baseURL` y est alors adapté dans un `ci4/.env` propre au serveur, non versionné (déploiement FTP-only, sans Composer ni shell disponibles côté serveur).

## Configuration

### Base de données

PDO singleton et détection d'environnement centralisés dans `config/db.php` (racine, hors `ci4/`) : WAMP par défaut, ou production via `NIJAC_ENV=production` / présence d'un `.env.production`. `ci4/app/Config/Database.php` réutilise ces identifiants, sans en définir de son côté.

Il n'y a plus de migration automatique à chaque chargement de page : le schéma est créé/mis à jour par `config/app_config.php → initTableConfiguration()`, appelée uniquement à l'ouverture de l'écran **EA98** (Administration BDD) par un administrateur. Ajouter une colonne suppose donc un `ALTER TABLE` explicite par environnement, ou un passage par EA98 après déploiement.

### Secrets

Les identifiants (BDD, SMTP, API FFTT, pepper de l'Obfuscator) sont stockés ROT47-encodés dans `.env` (racine, jamais versionné) et décodés par `config/db.php`. `tools/rot47.php` permet de pré-calculer une valeur à y coller.

### Paramètres applicatifs

Les paramètres métier sont stockés dans la table `configuration` (clé/valeur), modifiables via l'écran **EA91** :

- `etat_logiciel` : `Opérationnel` ou `Developpement` (en développement, tous les emails sont redirigés vers `email_developpement`, toujours via `getEmailDestinataire()`)
- `departements_actifs`, `regles_departements` (associations automatiques entre départements, ex. 76 → 27)
- `smtp_*` (utilisé par `getNijacMailer()`, wrapper PHPMailer ; anti-spam : Return-Path et Message-ID alignés sur le domaine de `smtp_from`, X-Mailer masqué, AltBody via `altBodyDepuisHtml()`, 0,3 s entre deux envois — DKIM/DMARC à activer chez l’hébergeur)
- `phase1_debut`/`phase1_fin`/`phase2_debut`/`phase2_fin`, `indemnite_forfaitaire`, `frais_kilometrique`, `frais_max_peages`, `frais_max_km`

---

## Architecture

CodeIgniter 4 standard : `ci4/app/Config/Routes.php` associe chaque URL à `Controller::méthode`. Chaque contrôleur étend `BaseController` (vide) et importe lui-même les fichiers legacy partagés dans son constructeur — il n'y a pas de bootstrap global. La plupart des écrans restent doubles : une méthode `index()` rend la vue HTML, des méthodes sœurs (`data`, `store`, `update`, `delete`…) servent les appels AJAX.

```
NIJAC/
├── config/
│   ├── db.php            # PDO singleton, détection d'environnement, décodage ROT47 des secrets
│   ├── app_config.php     # Migrations (initTableConfiguration), config, PHPMailer, rate-limit
│   └── helpers.php        # Helpers partagés (adresse/commune/salle)
├── Classes/
│   ├── Obfuscator.php     # Entier ↔ token 8 caractères (bcmath + hash de Knuth + pepper)
│   ├── SecurePasswordHasher.php
│   └── Distance.php       # Distance GPS entre deux points
├── asset/                 # CSS/JS partagés, servis tels quels (pas dans ci4/public/)
├── tools/rot47.php        # CLI pour pré-calculer une valeur ROT47 à coller dans .env
├── SQL/                   # Sauvegardes (EA85) — lues côté serveur uniquement
├── Importation/           # Dépôt de fichiers d'import — lu/écrit côté serveur uniquement
├── _Suivi/                # Journaux CSV de la mise à jour FFTT 131 (EN28) — écrits côté serveur uniquement
├── ci4/
│   ├── app/
│   │   ├── Config/Routes.php     # Toutes les routes, commentées par code EXXXX
│   │   ├── Config/Filters.php    # Filtres d'accès (auth, adminauth, csrauth, defiscauth, craconvocauth, csrf…)
│   │   ├── Config/Security.php   # CSRF en mode "cookie" (double-submit), header X-CSRF-Token
│   │   ├── Controllers/          # Un contrôleur par écran ou famille d'écrans
│   │   ├── Views/                # Une vue autonome par écran (pas de layout partagé)
│   │   └── Libraries/FfttRawClient.php  # Client API FFTT (cURL pur, sans dépendance Composer)
│   └── public/index.php  # Front controller CI4
├── Ecrans.md              # Répertoire de tous les écrans
├── SPECIFICATION.md       # Spécifications détaillées par écran
└── vendor/                # Dépendances Composer (racine)
```

### Front FFTT

Tous les appels à l'API FFTT passent par `App\Libraries\FfttRawClient`, en cURL pur sans dépendance Composer (déploiement FTP-only, sans `vendor/` à synchroniser côté serveur). Sept méthodes typées couvrent les besoins des écrans en service ; `request()` donne accès à n'importe quel autre endpoint brut pour les cas particuliers.

### Modèle de données principal

```
ja ─── disponible ─── nomination
 │          │              │
 └── club   rencontre ─────┘
              │    │
          equipe   salle ─── laposte
              │      │
             club ─ club_salle
              │
           division
```

- **`ja`** : fiche JA (Grade, JA1 [ex-Actif : JA actif 1er degré] + JA2 / JA3 / JAN / JAI [TINYINT(1) NOT NULL DEFAULT 0, écho des codes de la table de référence `JugeArbitre`, sans FK], Defiscalisation, Nationale [DEFAULT 1 = Oui, saisie manuelle uniquement, jamais touchée par les imports], Id_Club, Id_LaPoste, Cp/Ville de repli)
- **`disponible`** : réponse d'un JA pour une rencontre ou une journée entière (O/P/N)
- **`nomination`** : affectation JA ↔ rencontre (via `disponible`) + frais (Peage, Kilometre, Defiscalisation, dates de nomination/saisie) (pas de colonne `Forfait` : le forfait est dans `rencontre.Forfait`, l'ancienne `nomination.Forfait` est supprimée par EA98)
- **`rencontre`** : matchs à arbitrer (Date, Heure, Poule, Journee, équipes domicile/extérieure, ArbitrageCRA)
- **`equipe`** / **`equipe_nationale`** : équipes engagées, `Division` sous FK vers `division`
- **`division`** : référentiel des divisions (PK `Division`, `Nom`, `Color`, `Ord`)
- **`club`** / **`salle`** / **`laposte`** : référentiels clubs, salles et communes INSEE (coordonnées GPS)
- **`club_salle`** : liaison plusieurs-à-plusieurs club ↔ salle (une salle partagée par plusieurs clubs, plusieurs salles par club) avec `EstPrincipale` (au plus une salle principale par club) ; créée par EA98, qui y reprend les anciennes colonnes `salle.Id_Club` / `salle.EstPrincipale` (conservées mais obsolètes) et fusionne les fiches `salle` en double (même lieu)

---

## Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **Administrateur** | Tous les écrans (menus Admin et Nominateur), configuration, imports, administration BDD |
| **Nominateur** | Menu nominateur (EN11 à EN28) |
| **CSR** | Menu CSR (ES31–ES33) ; passe aussi le filtre `auth` du menu Nominateur, sans y avoir de lien de menu |
| **Défiscalisateur** | Menu Défiscalisateur (ED51–ED55) |
| **CRA Convoc** | Menu CRA Convoc (E009) : Compétitions CRA (EC71), Disponibilités CRA (EC74, + page publique `dispo-cra?ja=TOKEN`), Désignation CRA (EC73), Statistiques CRA (EC75), Degrés Juge-Arbitre (EC72), déconnexion |
| **JA** | Menu JA (E011, filtre `jaauth`, rôle JA requis — l'Administrateur seul n'y a pas accès) : Mes disponibilités (EJA2), Mes nominations (EJA3), Mes frais (EJA4), changement de mot de passe. Compte dont l'identifiant (`Id_Utilisateur`) est le n° de sa fiche `ja` et le login son adresse email ; créé par l'onglet « Invitation JA » d'EN15 (mot de passe provisoire envoyé par email, à changer à la 1re connexion) ou par EA86 « Nouveau » ; **JA exclusif** : un compte JA n'a que le rôle JA (aucun autre rôle), un compte d'un autre rôle ne peut jamais recevoir JA ; en EA86 seule l'adresse email (= login) d'un compte JA est modifiable, tout le reste est verrouillé ; compte JA sans fiche de même n° : connexion refusée. Une personne nominateur (ou tout rôle de priorité < 6) **et** JA n'a **pas** de compte JA : un administrateur renseigne dans EA86 sa « Fiche JA liée (n° de licence) » (`utilisateur.Id_Ja` = `ja.Id_JA`, ancienne colonne `Id_Utilisateur_Lie` renommée par EA98) ; elle ouvre alors E011 depuis E002 / E003 (« Accéder à mon espace JA ») et revient (« Revenir à mon compte … ») sans se reconnecter ni changer de compte (`POST changer-compte` : session en « mode JA », `id` inchangé, `id_ja` = fiche liée relue en base) |

**Multi-rôles** : un utilisateur peut cumuler plusieurs rôles (table de référence `role` — `Id_Role` auto-incrémenté, `Nom` unique, `Libelle`, `Priorite`, `RouteMenu` — + liaison `utilisateur_role` (`Id_Utilisateur`, `Id_Role`), champ `estPrincipal` : un seul rôle principal par utilisateur ; le code manipule les noms de rôles, EA98 migre l'ancien schéma `role.Code` / `utilisateur_role.Role`). Ses droits sont l'**union** de ses rôles (`aRole()` / `estAdmin()` dans tous les filtres) ; à la connexion il arrive directement sur le menu de son rôle principal (`menuDuRole()`, colonne `role.RouteMenu`), sans page de choix. EA86 : cases à cocher des rôles + choix du rôle principal. Exception : le rôle **JA est exclusif** (non cumulable) — cocher JA décoche et désactive les autres rôles, le serveur refuse tout mélange (« Le rôle JA est exclusif ») ; un compte hérité cumulant déjà JA et d'autres rôles est conservé tel quel (journalisé) et se régularise dans EA86. L'ancienne colonne `utilisateur.Role` est migrée vers `utilisateur_role` puis supprimée par EA98 (`assurerRolesUtilisateurs()` ; conservée tant qu'un utilisateur reste non migré) ; les anciens `assurerRole*()` (extension d'ENUM) n'existent plus.

La session (native PHP, pas le service Session de CI4) stocke `$_SESSION['utilisateur']` avec les clés `id`, `login`, `nom`, `prenom`, `role` (rôle principal), `roles` (tous les rôles), `is_admin`, `id_departement`, `change_login`, `email`, `pwd_version`, `verifie_le` (compte JA : sa fiche est celle de n° `id`). Un JA peut désormais avoir un compte (rôle JA) ; les JA sans compte continuent d'utiliser les écrans publics (EN19, EN21, EN22, EN25), identifiés par un lien tokenisé (Obfuscator) envoyé par email.

Quatre écrans (EA96, EA97, EA98, EA99) restent réservés en plus au compte `CHAUTARD`, vérifié explicitement dans le contrôleur ; EA96 / EA97 / EA98 exigent en outre la double authentification TOTP (EA99).

---

## Sécurité

- **CSRF** : géré globalement par le filtre `csrf` de CodeIgniter (`Config\Filters`) sur tout POST/PUT/PATCH/DELETE, sans appel explicite dans les contrôleurs. Chaque vue expose le jeton via `<meta name="csrf-token">`, injecté dans l'en-tête `X-CSRF-Token` par `asset/js/nijac-csrf.js` (préfiltre jQuery AJAX).
- **Obfuscation des IDs JA** dans les URL publiques via `Classes/Obfuscator.php` (bcmath + hash de Knuth), avec un pepper secret optionnel (`.env`) qui rend les tokens non forgeables sans lui.
- **Mots de passe** hashés via `Classes/SecurePasswordHasher.php` (bcrypt).
- **Double authentification par email** : après le mot de passe (E001), un code de 6 chiffres (`random_int`) est envoyé à `utilisateur.Email` (obligatoire) et saisi en E010 — valable 10 min, usage unique, 5 essais, renvoi limité (60 s, 3 / 10 min), seul son hash est conservé en session, `$_SESSION['utilisateur']` n'existe qu'après validation. Coupe-circuit d'urgence (panne SMTP, adresse erronée) : `UPDATE configuration SET valeur = '0' WHERE cle = 'double_authentification';` (retour au mot de passe seul, journalisé ; remettre `'1'` ensuite).
- **2FA TOTP des écrans CHAUTARD** (EA96 / EA97 / EA98) : code d'une application d'authentification (RFC 6238, Google Authenticator), activé et saisi sur EA99 (mot de passe + premier code ; 8 codes de secours à usage unique, hachés) ; session déverrouillée 30 min glissantes ; une fois la session déverrouillée, aucun code TOTP n'est redemandé ; les actions d'EA98 (requête SQL, chargement / suppression d'un fichier SQL) redemandent le mot de passe ; secret chiffré AES-256-GCM (clé dérivée du pepper — changer le pepper le rend illisible), anti-rejeu, tentatives limitées. Réinitialisation d'urgence : `UPDATE utilisateur SET TotpSecret=NULL, TotpDernierPas=NULL, TotpSecours=NULL WHERE Login='CHAUTARD';`.
- **Rate limiting** sur l'envoi d'emails (fenêtre glissante, `config/app_config.php`).
- **Secrets** : jamais dans le dépôt, ROT47-encodés dans `.env` (non versionné), explicitement refusés par `.htaccess` en dehors de tout appel applicatif — de même pour `SQL/`, `Importation/` et `_Suivi/`.

---

## Environnements

| Étape | Action |
|---|---|
| Développement | Lancer WAMP, accéder à `http://nijac/` (vhost dédié) |
| Mise en production | Déploiement FTP uniquement (pas de Composer ni de shell côté serveur) ; `ci4/.env` propre au serveur, avec `app.baseURL` incluant le chemin de déploiement |
| Migration de schéma | Ouvrir l'écran EA98 (compte admin habilité) après déploiement d'une modification de structure |
| Emails | Basculer `etat_logiciel` → `Opérationnel` dans la table `configuration` (EA91) |

---

## Auteur

Patrick CHAUTARD — Ligue de Normandie de Tennis de Table
