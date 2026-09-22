# NIJAC – Nomination des Juges-Arbitres en Championnats

Application web PHP/MySQL de gestion et de nomination des Juges-Arbitres (JA) pour la **Ligue de Normandie de Tennis de Table**.

Elle couvre l'ensemble du cycle de vie d'une nomination : import des JA et des clubs depuis l'API/les fichiers FFTT, désidératas des clubs et des équipes, saisie des disponibilités, affectation aux rencontres, envoi des convocations, saisie des frais de déplacement et défiscalisation.

L'application est entièrement implémentée en **CodeIgniter 4** (dossier `ci4/`). Il n'existe plus d'application PHP autonome à la racine : celle-ci a été intégralement remplacée par le portage CI4. La racine du dépôt ne conserve que les éléments partagés, indépendants du framework, dont `ci4/` dépend (voir [Architecture](#architecture)).

---

## Fonctionnalités principales

Le détail complet de chaque écran (fonctionnalités, fichier source, règles métier) est disponible dans [Ecrans.md](Ecrans.md), avec les spécifications détaillées dans [SPECIFICATION.md](SPECIFICATION.md). Une cinquantaine d'écrans est actuellement en service, répartis par menu :

| Plage de code | Menu | Contenu |
|---|---|---|
| E001–E008 | Connexion / mots de passe | Connexion, menus (Admin, Nominateur, CSR, Défiscalisateur), changement et réinitialisation de mot de passe |
| EN11–EN28 | Nominateur + pages publiques JA | Gestion des JA et des clubs, désidératas, disponibilités, nomination aux rencontres, centre d'envoi, convocation/frais, statistiques, suivi des nominations… |
| ES31–ES33 | CSR (Commission Sportive Régionale) | Réengagement des clubs, souhaits des équipes |
| ED51–ED55 | Défiscalisateur | Défiscalisation des JA, barème kilométrique, attestations, comptes EBP |
| EA81–EA98 | Administrateur | Salles, imports FFTT, utilisateurs, communes, régions/départements, divisions, équipes, rencontres, configuration, messagerie, test API FFTT, administration base de données |

Quelques écrans marquants :

| Code | Titre | Description |
|------|-------|-------------|
| E001 | Connexion | Authentification, redirection selon le rôle, forçage du changement de mot de passe |
| EN11 | Juges-Arbitres | Import FFTT (API ou fichier), fiche JA (grade, club, commune, défiscalisation, nationale) |
| EN14 | Nomination | Affectation JA ↔ rencontres selon les règles métier, validation, envoi des convocations |
| EN21 | Convocation et frais JA | Page publique tokenisée : consultation de la convocation, saisie des frais (péage, km, défiscalisation) par le JA |
| EN27 | Clubs / Associations | Import et gestion des clubs affiliés (upsert depuis l'API FFTT) |
| EN28 | Suivi des nominations | Suivi des frais saisis par les JA, correction, relance et export CSV |
| EA91 | Configuration | Paramètres applicatifs (état logiciel, SMTP, phases, frais kilométriques…) |
| EA98 | Administration BDD | Requêteur SQL libre, structure des tables, accès restreint (compte CHAUTARD) |

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
- `smtp_*` (utilisé par `getNijacMailer()`, wrapper PHPMailer)
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
├── ci4/
│   ├── app/
│   │   ├── Config/Routes.php     # Toutes les routes, commentées par code EXXXX
│   │   ├── Config/Filters.php    # Filtres d'accès (auth, adminauth, csrauth, defiscauth, csrf…)
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
              │
           division
```

- **`ja`** : fiche JA (Grade, Actif, Defiscalisation, Nationale, Id_Club, Id_LaPoste, Cp/Ville de repli)
- **`disponible`** : réponse d'un JA pour une rencontre ou une journée entière (O/P/N)
- **`nomination`** : affectation JA ↔ rencontre (via `disponible`) + frais (Peage, Kilometre, Defiscalisation, dates de nomination/saisie)
- **`rencontre`** : matchs à arbitrer (Date, Heure, Poule, Journee, équipes domicile/extérieure, ArbitrageCRA)
- **`equipe`** / **`equipe_nationale`** : équipes engagées, `Division` sous FK vers `division`
- **`division`** : référentiel des divisions (PK `Division`, `Nom`, `Color`, `Ord`)
- **`club`** / **`salle`** / **`laposte`** : référentiels clubs, salles et communes INSEE (coordonnées GPS)

---

## Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **Administrateur** | Tous les écrans (menus Admin et Nominateur), configuration, imports, administration BDD |
| **Nominateur** | Menu nominateur (EN11 à EN28) |
| **CSR** | Menu CSR (ES31–ES33) ; passe aussi le filtre `auth` du menu Nominateur, sans y avoir de lien de menu |
| **Défiscalisateur** | Menu Défiscalisateur (ED51–ED55) |

La session (native PHP, pas le service Session de CI4) stocke `$_SESSION['utilisateur']` avec les clés `id`, `login`, `nom`, `prenom`, `role`, `is_admin`, `id_departement`, `change_login`, `email`. Un JA ne se connecte jamais : ses écrans (EN19, EN21, EN22, EN25) sont publics, identifiés par un lien tokenisé (Obfuscator) envoyé par email.

Deux écrans (EA96, EA98) restent réservés en plus au compte `CHAUTARD`, vérifié explicitement dans le contrôleur.

---

## Sécurité

- **CSRF** : géré globalement par le filtre `csrf` de CodeIgniter (`Config\Filters`) sur tout POST/PUT/PATCH/DELETE, sans appel explicite dans les contrôleurs. Chaque vue expose le jeton via `<meta name="csrf-token">`, injecté dans l'en-tête `X-CSRF-Token` par `asset/js/nijac-csrf.js` (préfiltre jQuery AJAX).
- **Obfuscation des IDs JA** dans les URL publiques via `Classes/Obfuscator.php` (bcmath + hash de Knuth), avec un pepper secret optionnel (`.env`) qui rend les tokens non forgeables sans lui.
- **Mots de passe** hashés via `Classes/SecurePasswordHasher.php` (bcrypt).
- **Rate limiting** sur l'envoi d'emails (fenêtre glissante, `config/app_config.php`).
- **Secrets** : jamais dans le dépôt, ROT47-encodés dans `.env` (non versionné), explicitement refusés par `.htaccess` en dehors de tout appel applicatif — de même pour `SQL/` et `Importation/`.

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
