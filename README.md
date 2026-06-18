# NIJAC – Nomination des Juges-Arbitres en Championnats

Application web PHP/MySQL de gestion et de nomination des Juges-Arbitres (JA) pour la **Ligue de Normandie de Tennis de Table**.

Elle couvre l'ensemble du cycle de vie d'une nomination : import des JA depuis un fichier FFTT, saisie des disponibilités, affectation aux rencontres, envoi des convocations et saisie des frais de déplacement.

---

## Fonctionnalités principales

| Écran | Code | Description |
|---|---|---|
| Connexion | E001 | Authentification avec gestion du premier changement de mot de passe |
| Juges-Arbitres | E007 | Import Excel FFTT, fiche JA (grade, club, commune, défiscalisation, nationale) |
| Disponibilités | E021 | Saisie par département avec règle 76 → 27 automatique |
| Nomination | E011 | Affectation des JA aux rencontres par journée / division |
| Convocations | E013 | Génération et envoi email des convocations |
| Messagerie | E016 | Modèles de messages (disponibilités, convocations, rappels) |
| Frais | — | Saisie des péages et kilomètres par nomination |
| JA R3/R4 | — | Vue des JA en rencontres régionales |

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

Tous les paramètres métier sont stockés dans la table `configuration` (clé/valeur) et modifiables via l'écran Administration :

- `etat_logiciel` : `Opérationnel` ou `Developpement` (en mode développement, tous les emails sont redirigés vers `email_developpement`)
- `departements_actifs` : liste des départements gérés (ex : `14,27,50,61,76`)
- `regles_departements` : JSON d'associations automatiques (ex : `{"76":["27"]}`)
- `smtp_*` : configuration du serveur SMTP sortant
- `indemnite_forfaitaire`, `frais_kilometrique`, `frais_max_peages`, `frais_max_km`

---

## Architecture

```
NIJAC/
├── index.php               # Point d'entrée / connexion (E001)
├── admin_menu.php          # Menu administrateur
├── config/
│   ├── db.php              # PDO singleton + détection env
│   ├── csrf.php            # Protection CSRF (token session)
│   └── app_config.php      # Helpers config, migrations auto, PHPMailer factory
├── Classes/
│   ├── Obfuscator.php      # Obfuscation entier ↔ token 8 chars (bcmath + Knuth hash)
│   ├── Distance.php        # Calcul de distance GPS
│   └── SecurePasswordHasher.php
├── Nominateur/             # Espace nominateur
│   ├── menu.php            # Tableau de bord (E020)
│   ├── jugearbitre.php     # Gestion JA — import Excel + grille éditable (E007)
│   ├── disponibilites.php  # Vue par département (E021)
│   ├── disponibilite_ja.php# Saisie détaillée par JA (lien tokenisé)
│   ├── nomination.php      # Affectation JA ↔ rencontres (E011)
│   ├── convocation_ja.php  # Génération / envoi convocations (E013)
│   ├── messagerie.php      # Modèles de messages (E016)
│   └── includes/toolbar.php
├── includes/               # Composants partagés (footer, modal mdp…)
├── asset/                  # Bootstrap, Bootstrap Icons, jQuery, CSS/JS propres
└── vendor/                 # Dépendances Composer
```

### Modèle de données principal

```
ja ─── disponible ─── rencontre ─── nomination
 │                        │
 └── Club                 └── Salle ─── laposte
```

- **`ja`** : fiche JA (Grade, Actif, Defiscalisation, Nationale, Id_LaPoste, Cp, Ville, DistanceMaxKm)
- **`disponible`** : réponse d'un JA pour une rencontre (O/P/N)
- **`rencontre`** : matchs à arbitrer (Saison, Journee, Division, Id_Salle)
- **`nomination`** : affectation JA ↔ rencontre + frais (Peages, Kilometres)
- **`laposte`** : référentiel INSEE des communes (CodePostal, Nom, coordonnées GPS)

---

## Rôles utilisateurs

| Rôle | Accès |
|---|---|
| **Administrateur** | Tous les écrans + configuration + import |
| **Nominateur** | Espace `Nominateur/` uniquement (menu, JA, disponibilités, nomination, convocations, messagerie) |

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
| Emails | Basculer `etat_logiciel` → `Opérationnel` dans la table `configuration` |

---

## Auteur

Patrick CHAUTARD — Ligue de Normandie de Tennis de Table
