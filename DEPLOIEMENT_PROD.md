# Déploiement en production (FTP) — liste des fichiers

> **Ce fichier (`DEPLOIEMENT_PROD.md`) est une note interne : ne PAS l'envoyer en production.**

Établi le 08/10/2026 à partir de `git diff --name-status origin/main` (arbre de travail) + `git status --porcelain`
(HEAD = `847fbb1` « Merge branch 'main' », 5 commits en avance sur `origin/main` = `0f29b12`).
Référence `origin/main` locale non rafraîchie (pas de `git fetch`) : si la prod a été mise à jour depuis un autre poste, revérifier.

## État des conflits de fusion : RÉSOLUS (non commités)

Les marqueurs de conflit du commit de fusion `847fbb1` ont été résolus dans l'arbre de travail (côté `HEAD` conservé :
le côté `0f29b12` n'était qu'une version antérieure de la même refonte FFTT 131 — import par envoi du fichier navigateur,
`f131/valider`, remplacements de JA — sans autre apport). Aucun marqueur restant, `php -l` OK sur tous les PHP ci-dessous.
Un doublon du bouton `#btn-f131` réintroduit silencieusement par la fusion dans `suivi_nomination_index.php` a été retiré.

→ **Commiter la résolution avant l'envoi** (les fichiers sur disque sont la bonne version ; le commit `847fbb1` lui-même contient encore les marqueurs).

---

## A) À ENVOYER (12 fichiers)

### Racine
- `.htaccess` — ajoute `RewriteRule ^_Suivi/ - [F,L]` (à envoyer **en dernier**, voir étapes)

### `ci4/app/Config/`
- `ci4/app/Config/Routes.php` — routes `suivi-nomination/f131/{fichier,televerser,supprimer,apercu,appliquer,suivi}` ; plus aucune route `controle-f131*`

### `ci4/app/Controllers/`
- `ci4/app/Controllers/AuthController.php` — `AltBody` texte du code de sécurité HTML
- `ci4/app/Controllers/RencontreAdminController.php` — `rencontre.Forfait`, libellés Frais (EN23)
- `ci4/app/Controllers/SuiviNominationController.php` — mise à jour FFTT 131 (analyse sans écriture, application R3M / R4M, journal CSV)

### `ci4/app/Views/`
- `ci4/app/Views/rencontre_admin_index.php`
- `ci4/app/Views/suivi_nomination_index.php`

### `config/`
- `config/app_config.php`
- `config/db.php` — `APP_VERSION` = `1.3.24`

### `asset/`
- `asset/aide/import-131.html`

### `_Suivi/` (nouveau dossier)
- `_Suivi/.htaccess`
- `_Suivi/.gitignore`

Créer le dossier `_Suivi/` à la racine de l'appli en prod s'il n'existe pas, et vérifier qu'il est **accessible en écriture par PHP** (journaux CSV `suivi_131_*.csv` écrits par EN28).

`composer.json` / `composer.lock` (racine et `ci4/`) inchangés → **aucun envoi de `vendor/`**.

---

## B) À SUPPRIMER en prod

Aucun fichier supprimé par rapport à `origin/main`.

À supprimer **seulement s'ils existent en prod** (ajoutés par le commit `8990b43` puis supprimés par `5760ddf`, jamais présents dans `origin/main` — à vérifier si un envoi intermédiaire a eu lieu) :
- `ci4/app/Controllers/ControleF131Controller.php`
- `ci4/app/Views/controle_f131_index.php`

---

## C) À NE PAS ENVOYER

- **Docs internes** (envoi facultatif, sans effet sur l'appli) : `CLAUDE.md`, `README.md`, `SPECIFICATION.md`, `ECRANS.md`, `DEPLOIEMENT_PROD.md` (ce fichier), `Documentation/Fichiers CRA engagements juges-arbitrages Secteur 14-50-61.xls`.
- `tools/propositions_messagerie_12.html` — maquette de travail, non utilisée par l'appli.
- `.claude/settings.local.json` — configuration locale de l'outil.
- `Importation/` (ex. `Importation/102_05092026212151.csv`, `Importation/131_07102026081402.xlsx`) — fichiers de données/import, déposés en prod via l'appli (EN28 « Parcourir… ») si besoin.
- `SQL/` (sauvegardes), `logs/`, `.env*` (dont `ci4/.env` : celui de prod a `app.baseURL` = `https://www.ligue-normandie-tt.fr/nijac/` — **ne jamais l'écraser**).
- `_Suivi/*.csv` (journaux générés en prod).
- La copie parasite `NIJAC/NIJAC/` éventuelle.
- `vendor/`, `ci4/vendor/` (composer inchangé).
- Fichiers temporaires Excel `~$*`.

---

## ÉTAPES APRÈS ENVOI

0. **Sauvegarde de la base de production recommandée AVANT** (EA98 fait des suppressions définitives, voir ci-dessous).
1. Envoyer tous les fichiers PHP / HTML de la section A (sauf `.htaccess` racine), créer `_Suivi/` + ses 2 fichiers, vérifier les droits d'écriture.
2. Envoyer le `.htaccess` racine en dernier.
3. Supprimer les fichiers de la section B s'ils existent.
4. Se connecter en **CHAUTARD** et charger **EA98** (Administration BDD) → `initTableConfiguration()`. EA98 va :
   - **ajouter** `rencontre.Forfait` `TINYINT(1) NOT NULL DEFAULT 0` (après `Frais` si présente, sinon en fin de table) — EN23 ;
   - **mettre à jour** le corps du message système « Code de sécurité » (`messagerie`, `Id_Utilisateur IS NULL`) vers la version HTML, **uniquement** s'il est encore identique à l'ancien texte brut par défaut (corps personnalisé en EA93 non touché) ;
   - ⚠ **SUPPRIMER DÉFINITIVEMENT** la colonne `nomination.Forfait` si elle existe (`ALTER TABLE nomination DROP COLUMN Forfait`) ;
   - ⚠ **SUPPRIMER DÉFINITIVEMENT** la table `f131_travail` si elle existe (`DROP TABLE IF EXISTS f131_travail`).
5. Vérifications rapides :
   - `https://www.ligue-normandie-tt.fr/nijac/_Suivi/` → **403** ;
   - `https://www.ligue-normandie-tt.fr/nijac/Importation/` → **403** ;
   - EN28 : ouvrir la popup « Mise à jour FFTT 131 » (liste des fichiers, téléversement, Analyser, application, journal CSV dans `_Suivi/`) ;
   - EN23 : liste « Forfait » active (plus grisée « chargez EA98 ») ;
   - connexion avec double authentification : email du code reçu au format HTML.
6. Rappel : la prod est sous le chemin `/nijac/` → ne pas écraser `ci4/.env` de prod.
