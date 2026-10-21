# Déploiement prod — dernier lot : EN21 « le lien fait foi » (fin du mode aperçu nominateur)

> **Note interne : ne PAS envoyer ce fichier (`DEPLOIEMENT_PROD_DERNIERES.md`) en production.**

Établi le 14/10/2026 vers 19:05. Hypothèse (selon l'utilisateur) : **la prod sert déjà** le lot anti-spam et les nouvelles règles de dates EN21 (vérifié par un test de la page de convocation).

> ⚠ **Liste susceptible d'évoluer** : une modification EN21 (b) était peut-être encore en cours au moment du relevé (derniers fichiers modifiés à 19:02–19:03). **Relancer `php -l` et revérifier les dates juste avant l'envoi.**

**Méthode (git en lecture seule)** : aucun commit depuis `eb0fdce` « Affichage des forfaits » (08/10/2026). Tout est donc non commité, en partie indexé (`git add`) et en partie non indexé. Git ne permet pas de distinguer ce qui est déjà en prod. J'ai donc retenu les **fichiers de code dont la date de modification est postérieure à la génération du lot précédent** (`DEPLOIEMENT_PROD_DERNIERES.md` anti-spam, 14/10 à 16:30:41) :

| Fichier | Modifié le | État git |
|---|---|---|
| `ci4/app/Views/convocation_ja_index.php` | 14/10 19:03:22 | `MM` (indexé + modif. non indexée) |
| `ci4/app/Controllers/ConvocationJaController.php` | 14/10 19:03:08 | `MM` |
| `config/db.php` | 14/10 19:02:47 | `MM` (1.3.29 indexé → 1.3.30 sur le disque) |
| `config/app_config.php` | 14/10 17:53:19 | `M` (indexé, identique au disque) |

**Limite** : `config/app_config.php` (17:53) porte peut-être les règles de dates EN21 **déjà déployées**. Faute de pouvoir le vérifier, il est inclus par sécurité : le renvoyer est sans risque s'il est identique, et nécessaire sinon.

**Contrôles** : `php -l` (PHP 8.2.29) **OK sur les 4 fichiers PHP** ; **aucun marqueur de conflit** (`<<<<<<<`, `=======`, `>>>>>>>`) dans ces fichiers.

---

## A) À ENVOYER (4 fichiers) — ensemble, dans la même session FTP

1. `config/app_config.php` : fonctions EN21 partagées (`convocationAccusable()`, `accuserReceptionConvocation()`, règles de dates de l'accusé et de la saisie des frais) appelées par `ConvocationJaController`. À envoyer **en premier**, pour que le contrôleur ne référence jamais une fonction absente.
2. `ci4/app/Controllers/ConvocationJaController.php` : EN21. Le mode aperçu nominateur est supprimé, **le lien jetonné fait foi pour tout porteur**. Une session NIJAC ne bloque plus l'accusé ni la saisie des frais (`ERR_APERCU` retiré). `loginSession()` ne sert plus qu'au bouton Retour et à la trace `error_log` « EN21 : accusé|saisie via lien par utilisateur connecté <login> (nomination N) ».
3. `ci4/app/Views/convocation_ja_index.php` : EN21. Bandeau « Aperçu nominateur » supprimé, bouton d'accusé de réception affiché selon la seule règle `convocationAccusable()` (identique avec ou sans session), champs de frais et bouton Enregistrer non désactivés pour un connecté (seul `$fraisBloques` compte).
4. `config/db.php` : `APP_VERSION` 1.3.30. À envoyer **en dernier**.

**Dépendances** : la vue lit `$estConnecte`, `$accuse` et `$fraisBloques`, fournis par le nouveau contrôleur : **vue et contrôleur ensemble**, sinon variables indéfinies ou comportement incohérent. Le contrôleur dépend de `config/app_config.php`.

---

## B) À SUPPRIMER en prod (si présents)

Aucun.

---

## C) NON ENVOYÉS

- Docs modifiées : `CLAUDE.md`, `ECRANS.md`, `SPECIFICATION.md` (et `README.md`, `DEPLOIEMENT_PROD.md`, `DEPLOIEMENT_PROD_DERNIERES.md`). Envoi facultatif, sans effet.
- `.gitignore` (modifié à 19:00) et `increment_version.bat` (script local, 18:58) : outils du poste de développement, **jamais en prod**.
- ⛔ `deploy.lftp` : maintenant **indexé en suppression** (`D`, retiré du suivi git), mais il **reste dans l'historique GitHub** (commit `65febcc`). S'il contenait des identifiants FTP, les changer chez l'hébergeur. Ne jamais l'envoyer en prod.
- `.claude/settings.local.json` (indexé, configuration locale) : à ne pas envoyer, et à éviter de commiter.
- `.dbeaver/`, `SQL/` (sauvegardes, `dev_params.json`), `Importation/`, `logs/`, `_Suivi/*.csv`, `.env*` (**`ci4/.env` de prod à ne jamais écraser**), `vendor/`, `NIJAC_Login.txt`, `cj2.txt`, `composer.phar`, `Serveur/`.

---

## D) ACTION EA98

**Aucune.** `initTableConfiguration()` n'a pas de nouvelle opération de schéma : les seules opérations ajoutées depuis `eb0fdce` sont celles du lot `club_salle`, déjà déployé. Ce lot ne touche ni colonne ni table (`nomination.AccuseReception` existe déjà).

---

## E) VÉRIFICATIONS

- Ouvrir le **même lien de convocation** (`convocation-ja?ja=…`) **en navigation privée** (sans session) **et connecté** (Nominateur/Admin) : **affichage identique**, sans bandeau « Aperçu nominateur ». Le bouton d'accusé de réception est **actif** dans les deux cas tant que la convocation est accusable. Le bouton Retour n'apparaît qu'en session.
- Cliquer sur l'accusé : la date s'enregistre (visible en EN28) ; un 2ᵉ clic garde la première date.
- Saisie des frais : possible **à partir du jour de la rencontre** selon les règles de dates, bloquée avant. Enregistrement OK sans session et en session.
- Après une action faite connecté, le `error_log` PHP de la prod contient la ligne « `[NIJAC] EN21 : accusé via lien par utilisateur connecté <login> (nomination N)` » (ou `saisie`). Aucune ligne pour un JA non connecté.
- Version affichée = 1.3.30.
