# Déploiement en production (FTP) — lot complet depuis `origin/main`

> **Ce fichier (`DEPLOIEMENT_PROD.md`) est une note interne : ne PAS l'envoyer en production.**
> Pour les seules modifications faites depuis le dernier commit, voir `DEPLOIEMENT_PROD_DERNIERES.md`.

Établi le 14/10/2026 à partir de `git diff --name-status origin/main` + `git status --porcelain` (arbre de travail actuel).

> ⚠ **État git**
> - `origin/main` (= `0f29b12` « Modif ») **n'a pas été rafraîchi** (aucun `git fetch`) : si la prod ou GitHub ont évolué depuis un autre poste, revérifier la liste.
> - HEAD = `eb0fdce` « Affichage des forfaits » (08/10/2026) : **3 commits locaux non poussés** (`5760ddf`, `847fbb1`, `eb0fdce`).
> - **Changements NON commités** importants (modèle `club_salle`, règle « même salle », v1.3.27 : `config/`, 13 contrôleurs, 2 vues, plus `.gitignore` et les docs). Déployer depuis les fichiers **du disque**, pas depuis un export de commit.
> - Sauvegardes SQL : plus aucune n'est indexée (seul `SQL/.gitkeep` est suivi). Les anciennes (`Full_202609230848.sql`, `Full_202609251702.sql`) restent dans l'historique GitHub.

**Contrôles faits sur l'arbre de travail :** aucun marqueur de conflit dans les fichiers de la section A ni dans les docs ; `php -l` (PHP 8.2.29) **OK pour les 21 fichiers PHP** de la section A ; `APP_VERSION` = `1.3.27`.

---

## A) À ENVOYER (25 fichiers)

### Racine (1)
- `.htaccess` — ajoute `RewriteRule ^_Suivi/ - [F,L]` (à envoyer **en dernier**)

### `ci4/app/Config/` (1)
- `ci4/app/Config/Routes.php` — routes `suivi-nomination/f131/{fichier,televerser,supprimer,apercu,appliquer,suivi}` ; `f131/valider` retirée

### `ci4/app/Controllers/` (14)
- `ArbitreClubController.php` — EN25 : règle « même salle », salle via `club_salle`
- `AuthController.php` — email du code de sécurité en HTML + AltBody
- `CentrenvoyeController.php` — EN15 : salle via `club_salle`
- `ClubController.php` — EN27 : salles du club via `club_salle`
- `ConvocationJaController.php` — EN21 : salle de la rencontre en priorité, sinon principale via `club_salle`
- `CraCompetitionController.php` — EC71 : salles via `club_salle`
- `CraDesignationController.php` — EC73 : salle via `club_salle`
- `DesiderataClubController.php` — EN18 : salles du club via `club_salle`
- `JugearbitreController.php` — salle de club via `club_salle`
- `NominationController.php` — EN14 : salle effective, `LieuSalle`, règle « même salle »
- `RencontreAdminController.php` — EN23 : `rencontre.Forfait`, salles via `club_salle`
- `SalleController.php` — EA81 : plusieurs clubs par salle, principale par club
- `StatsJaController.php` — salle via `club_salle`
- `SuiviNominationController.php` — import 131 refondu (sans EN30), journal CSV `_Suivi/`, forfait EN28, règle « même salle »

### `ci4/app/Views/` (4)
- `nomination_index.php` — EN14 : filtrage des candidats par `LieuSalle`
- `rencontre_admin_index.php` — EN23 : Forfait, libellés Frais
- `salle_index.php` — EA81 : plusieurs clubs liés, salle principale
- `suivi_nomination_index.php` — EN28 : popup 131 en étapes, pastille FORFAIT

### `config/` (2)
- `config/app_config.php` — migrations EA98 (voir D), helpers `club_salle`, règle « même salle », `rencontreAForfait()`, email du code de sécurité
- `config/db.php` — `APP_VERSION` 1.3.27

### `asset/` (1)
- `asset/aide/import-131.html` — aide de la popup 131

### `_Suivi/` (2, nouveau dossier)
- `_Suivi/.htaccess` (`Require all denied`)
- `_Suivi/.gitignore`

**Dépendances** : `config/app_config.php` doit partir **avec TOUS les contrôleurs** ; un contrôleur envoyé sans lui provoque une **erreur fatale**. `salle_index.php` dépend de `SalleController.php`, `nomination_index.php` de `NominationController.php`. `composer.*` inchangés, donc **pas de `vendor/`**.

**Avant EA98**, l'application fonctionne : sans table `club_salle`, `clubSalleExiste()` replie tous les helpers sur `salle.Id_Club` / `salle.EstPrincipale` (EA81 refuse plusieurs clubs par salle tant qu'EA98 n'est pas passé), et `rencontreAForfait()` désactive le forfait EN23.

---

## B) À SUPPRIMER en prod (si présents)

Aucun fichier applicatif supprimé par rapport à `origin/main`. À supprimer **seulement s'ils ont été envoyés en prod entre-temps** (ajoutés par `8990b43`, supprimés par `5760ddf`) :
- `ci4/app/Controllers/ControleF131Controller.php`
- `ci4/app/Views/controle_f131_index.php`

---

## C) À NE PAS ENVOYER

- Docs (envoi facultatif) : `CLAUDE.md`, `README.md`, `SPECIFICATION.md`, `ECRANS.md`, `Documentation/…xls`.
- `DEPLOIEMENT_PROD.md`, `DEPLOIEMENT_PROD_DERNIERES.md`, `.gitignore` racine (fichier de dépôt git).
- `tools/propositions_messagerie_12.html` (maquette), `.claude/` (configuration locale, worktrees).
- `SQL/` (sauvegardes `Full_*.sql`, `dev_params.json`, `.gitkeep`), `Importation/`, `logs/`, `_Suivi/*.csv`.
- Fichiers locaux ignorés, hors périmètre : `NIJAC_Login.txt`, `cj2.txt`, `composer.phar`, `Serveur/`, `_Defiscalisation/*`.
- `.env*` et surtout **`ci4/.env` de prod : ne jamais l'écraser** (prod sous `/nijac/`).
- `vendor/`, `ci4/vendor/`, la copie `NIJAC/NIJAC/`, fichiers temporaires `~$*`.

---

## D) ÉTAPES APRÈS ENVOI

> ### ⛔ SAUVEGARDE COMPLÈTE DE LA BASE DE PRODUCTION OBLIGATOIRE AVANT EA98
> EA98 supprime **définitivement** une colonne, une table et des **fiches salle** (fusions).

1. Créer `_Suivi/` à la racine de l'appli en prod (à côté de `Importation/`) s'il n'existe pas, y envoyer `_Suivi/.htaccess` et `_Suivi/.gitignore`, et vérifier que PHP peut **y écrire**.
2. Envoyer `config/app_config.php`, puis `Routes.php`, les 14 contrôleurs et les 4 vues, puis `asset/aide/import-131.html`, puis `config/db.php`.
3. Envoyer le `.htaccess` racine **en dernier**.
4. Supprimer les fichiers de la section B s'ils existent.
5. Se connecter en **CHAUTARD** et charger **EA98** → `initTableConfiguration()`. D'après le diff réel contre `origin/main`, dans l'ordre :
   - **ajout** de `rencontre.Forfait` `TINYINT(1) NOT NULL DEFAULT 0` (après `Frais`) ;
   - ⚠ **SUPPRESSION DÉFINITIVE** de `nomination.Forfait` si elle existe (`ALTER TABLE nomination DROP COLUMN Forfait`) ;
   - ⚠ **SUPPRESSION DÉFINITIVE** de la table `f131_travail` si elle existe (`DROP TABLE IF EXISTS f131_travail`) ;
   - **email « Code de sécurité »** : corps système passé en HTML seulement s'il est encore au texte d'origine ;
   - **`migrerClubSalle()`** (en dernier) :
     - `salle.Id_Club` rendue NULL-able ; FK `salle.Id_Club` (ex. `fk_salle_club`) passée de `ON DELETE CASCADE` à **`ON DELETE SET NULL`** ;
     - **création de `club_salle`** (PK `Id_Club` + `Id_Salle`, `EstPrincipale`, FK `fk_club_salle_club` / `fk_club_salle_salle` en CASCADE, ou sans FK si impossible) et **remplissage** depuis `salle.Id_Club` / `EstPrincipale` (à la création seulement ; anciennes colonnes conservées mais obsolètes) ;
     - ⚠ **fusion automatique IRRÉVERSIBLE des doublons évidents** (même commune et même nom ou même adresse normalisés) : références et liens réaffectés, **fiches doublons supprimées**, une transaction par groupe, à chaque passage d'EA98 ;
     - ⚠ **fusions validées IRRÉVERSIBLES 55 → 89** (« routot ») **et 185 → 226** (« pont tinel »), avec garde-fous (deux fiches existantes, même CP, même commune, mot-clé présent ; sinon paire ignorée). **Vérifier avant** qu'en prod ces Id désignent bien ces salles ;
     - au plus une salle principale par club (à la création, première salle promue si aucune).
6. **Contrôler le `error_log` PHP** : `club_salle créée : N liaison(s)…`, `FK salle.fk_salle_club : ON DELETE CASCADE -> SET NULL`, `salle fusionnée : X → Y`, `salle fusionnée (validée) : 55 → 89` / `185 → 226`, `colonne nomination.Forfait supprimée`, `table f131_travail supprimée`. Examiner toute ligne `annulée`, `ignorée` ou `SANS clés étrangères`. Un 2ᵉ chargement d'EA98 ne doit plus rien produire.

---

## E) VÉRIFICATIONS

- `https://www.ligue-normandie-tt.fr/nijac/_Suivi/` et `/nijac/Importation/` → **403**.
- **EA81** : liens multiples club ↔ salle, une principale par club, salles 55 et 185 (et doublons évidents) fusionnées dans 89 et 226 avec leurs rencontres et clubs.
- **EN27** : salles du club et principale affichées.
- **EN14** : salle de la rencontre, sinon **salle principale via `club_salle`** ; 2ᵉ nomination le même jour seulement dans la même salle.
- **EN21** : même salle qu'EN14. **EN25 / EN28** : même règle « même salle ».
- **EN28, popup « Mise à jour FFTT 131 »** : dépôt, « Analyser », bouton R3M / R4M, ouverture du CSV de suivi.
- **EN23 / EN28** : forfait (liste active, pastille FORFAIT, filtres), libellés Frais EN23.
- **EN15 / EN18 / EC71 / EC73 / statistiques JA** : salles correctes.
- Connexion avec double authentification : email du code en HTML. Version = 1.3.27.
