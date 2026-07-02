# NIJAC – Nomination des Juges-Arbitres en Championnats

Application web PHP/MySQL de gestion et de nomination des Juges-Arbitres (JA) pour la **Ligue de Normandie de Tennis de Table**.

Elle couvre l'ensemble du cycle de vie d'une nomination : import des JA depuis un fichier FFTT, saisie des disponibilités, affectation aux rencontres, envoi des convocations et saisie des frais de déplacement.

---

## Fonctionnalités principales

Le détail complet de chaque écran (fonctionnalités, fichier source) est disponible dans [Ecrans.md](Ecrans.md).

| Code | Titre | Description |
|------|-------|-------------|
| E001 | Connexion | Authentification, redirection selon le rôle, forçage du changement de mot de passe |
| E002 | Menu administrateur | Accès aux écrans de paramétrage, bascule vers le menu nominateur |
| E005 | Salles | CRUD des salles de compétition, rattachement club, coordonnées GPS |
| E006 | Communes | Référentiel codes postaux / communes avec coordonnées GPS (calcul distances) |
| E007 | Juges-Arbitres | Import Excel FFTT, fiche JA (grade, club, commune, défiscalisation, nationale) |
| E008 | Clubs / Associations | Import et gestion des clubs affiliés (upsert depuis fichier FFTT) |
| E009 | Utilisateurs | Gestion des comptes, rôles (Admin / Nominateur), département, activation |
| E010 | Divisions | Définition des divisions et niveaux hiérarchiques |
| E011 | Import Rencontres | Import fichiers Excel FFTT dans la table rencontre (upsert) |
| E015 | Configuration | Paramètres applicatifs (état logiciel, SMTP, frais kilométriques…) |
| E016 | Saison / Nettoyage | Sauvegarde SQL + vidage des tables de saison, restauration depuis backup |
| E017 | Import Rencontres Nationales | Import fichier FFTT à 6 feuilles (N1M/N2M/N3M/N1D/N2D) |
| E020 | Menu nominateur | Tableau de bord (JA actifs, nominations, convocations, rencontres sans JA) |
| E021 | Disponibilités JA | Saisie par département (règle 76 → 27 automatique), fiche par journée |
| E022 | Nomination | Affectation JA ↔ rencontres avec règles métier, validation des nominations |
| E024 | Centre d'envoi | Envoi des 4 types de messages aux JA actifs du département |
| E025 | Comptabilité | Récapitulatif des frais JA, export CSV format EBP (journal AC) |
| E026 | Messagerie | Création et gestion des modèles de messages (convocation, rappel, annulation…) |
| E027 | JA R3 / R4 | Signalement des équipes R3/R4 demandant un JA → `ArbitrageObligatoire = 1` |
| E099 | Administration BDD | Browse/CRUD, structure, requêteur SQL, export CSV, gestion des index |

---

## Prérequis

- **PHP ≥ 8.1** avec extensions : `pdo_mysql`, `bcmath`, `mbstring`
- **MySQL / MariaDB** (port 3307 en local WAMP, 3306 en production)
- **Composer** pour les dépendances PHP

## Installation

```bash
# Depuis la racine du projet
composer install
```

Les deux dépendances sont :
- `phpoffice/phpspreadsheet` — import des fichiers Excel FFTT
- `phpmailer/phpmailer` — envoi des emails de convocation

## Configuration

### Base de données

| Environnement | Détection | Base | Port |
|---|---|---|---|
| Développement (WAMP) | par défaut | `nijac` | 3307 |
| Production | `NIJAC_ENV=production` ou fichier `.env.production` à la racine | `******_nijac` | 3306 |

La structure de la base est auto-créée / migrée au premier accès via `config/app_config.php` (`initTableConfiguration()`).

### Paramètres applicatifs

Tous les paramètres métier sont stockés dans la table `configuration` (clé/valeur) et modifiables via l'écran E015 :

- `etat_logiciel` : `Opérationnel` ou `Developpement` (en mode développement, tous les emails sont redirigés vers `email_developpement`)
- `departements_actifs` : liste des départements gérés (ex : `14,27,50,61,76`)
- `regles_departements` : JSON d'associations automatiques (ex : `{"76":["27"]}`)
- `smtp_*` : configuration du serveur SMTP sortant
- `indemnite_forfaitaire`, `frais_kilometrique`, `frais_max_peages`, `frais_max_km`

---

## Architecture

```
NIJAC/
├── index.php                   # Point d'entrée / connexion (E001)
├── admin_menu.php              # Menu administrateur (E002)
├── club.php                    # Gestion clubs (E008)
├── salle.php                   # Gestion salles (E005)
├── utilisateur.php             # Gestion utilisateurs (E009)
├── communes.php                # Gestion communes (E006)
├── division.php                # Gestion divisions (E010)
├── import_rencontres.php       # Import rencontres régionales (E011)
├── import_rencontres_nat.php   # Import rencontres nationales (E017)
├── clean.php                   # Nettoyage / saison (E016)
├── configuration.php           # Configuration générale (E015)
├── db-admin.php                # Administration BDD (E099)
├── logout.php                  # Déconnexion
├── config/
│   ├── db.php                  # PDO singleton + détection env
│   ├── csrf.php                # Protection CSRF (token session)
│   └── app_config.php          # Helpers config, migrations auto, PHPMailer factory
├── Classes/
│   ├── Obfuscator.php          # Obfuscation entier ↔ token 8 chars (bcmath + Knuth hash)
│   ├── Distance.php            # Calcul de distance GPS
│   └── SecurePasswordHasher.php
├── Nominateur/                 # Espace nominateur
│   ├── menu.php                # Tableau de bord (E020)
│   ├── jugearbitre.php         # Gestion JA — import Excel + grille éditable (E007)
│   ├── disponibilites.php      # Disponibilités par département (E021)
│   ├── disponibilite_ja.php    # Saisie détaillée par JA (lien tokenisé)
│   ├── nomination.php          # Affectation JA ↔ rencontres (E022)
│   ├── centrenvoye.php         # Centre d'envoi des messages (E024)
│   ├── compta.php              # Comptabilité frais JA (E025)
│   ├── messagerie.php          # Modèles de messages (E026)
│   ├── JA_R3R4.php             # Demandes JA équipes R3/R4 (E027)
│   └── includes/toolbar.php
├── includes/                   # Composants partagés (page_header.php, footer, modal mdp…)
├── asset/                      # Bootstrap, Bootstrap Icons, jQuery, CSS/JS propres
├── Ecrans.md                   # Répertoire détaillé de tous les écrans
└── vendor/                     # Dépendances Composer
```

### Modèle de données principal

```
ja ─── disponible ─── rencontre ─── nomination
 │                        │
 └── Club                 └── Salle ─── laposte
```

- **`ja`** : fiche JA (Grade, Actif, Defiscalisation, Nationale [Oui/Non], Id_LaPoste, Cp, Ville)
- **`disponible`** : réponse d'un JA pour une rencontre (O/P/N)
- **`rencontre`** : matchs à arbitrer (Saison, Journee, Division, Id_Salle)
- **`nomination`** : affectation JA ↔ rencontre + frais (Peages, Kilometres)
- **`laposte`** : référentiel INSEE des communes (CodePostal, Nom, coordonnées GPS)

---

## Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **Administrateur** | Tous les écrans + configuration + import + administration BDD |
| **Nominateur** | Espace `Nominateur/` uniquement (E020 à E027) |

La session stocke `$_SESSION['utilisateur']` avec les clés `is_admin`, `id_departement`, `role`, `change_login`.

---

## Sécurité

- **CSRF** : chaque formulaire inclut `csrfField()` ; chaque endpoint POST appelle `csrfVerify(true)` (JSON) ou `csrfVerify()` (HTML).
- **Obfuscation des IDs JA** dans les URL publiques (lien de disponibilité tokenisé) via `Obfuscator` avec `OBFUSCATOR_SEED = 167`.
- **Mots de passe** hashés via `SecurePasswordHasher` (wrapper bcrypt).
- **Rate limiting** sur l'envoi d'emails (fenêtre glissante en session, paramétrable).

---

## Environnements

| Étape | Action |
|---|---|
| Développement | Lancer WAMP, accéder à `http://localhost/NIJAC/` |
| Mise en production | Créer `.env.production` à la racine (ou `NIJAC_ENV=production` dans Apache), adapter les constantes de `config/db.php` |
| Emails | Basculer `etat_logiciel` → `Opérationnel` dans la table `configuration` (E015) |

---

## Auteur

Patrick CHAUTARD — Ligue de Normandie de Tennis de Table
