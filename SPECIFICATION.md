# NIJAC – Spécifications fonctionnelles

> Document de référence pour l'ensemble des écrans de l'application.
> Voir aussi [Ecrans.md](Ecrans.md) pour le tableau synthétique et [README.md](README.md) pour l'architecture.

---

## Table des matières

- [E001 – Connexion](#e001--connexion)
- [E002 – Menu administrateur](#e002--menu-administrateur)
- [E003 – Menu nominateur](#e003--menu-nominateur)
- [E006 – Changement du mot de passe](#e006--changement-du-mot-de-passe)
- [E007 – Mot de passe oublié (demande)](#e007--mot-de-passe-oublié-demande)
- [E008 – Réinitialisation du mot de passe](#e008--réinitialisation-du-mot-de-passe)
- [E009 – Menu CRA Convoc](#e009--menu-cra-convoc)
- [EC71 – Compétitions CRA](#ec71--compétitions-cra)
- [EC72 – Degrés Juge-Arbitre](#ec72--degrés-juge-arbitre)
- [EC73 – Désignation CRA](#ec73--désignation-cra)
- [EC74 – Disponibilités CRA](#ec74--disponibilités-cra)
- [EC75 – Statistiques CRA](#ec75--statistiques-cra)
- [EN11 – Juges-Arbitres](#en11--juges-arbitres)
- [EN12 – Désidératas clubs](#en12--désidératas-clubs)
- [EN13 – Disponibilités JA](#en13--disponibilités-ja)
- [EN14 – Nomination JA](#en14--nomination-ja)
- [EN15 – Centre d'envoi](#en15--centre-denvoi)
- [EN17 – Statistiques JA](#en17--statistiques-ja)
- [EN18 – Désidératas club](#en18--désidératas-club)
- [EN19 – Adresse domicile JA](#en19--adresse-domicile-ja)
- [EN21 – Convocation et frais JA](#en21--convocation-et-frais-ja)
- [EN22 – Disponibilité JA](#en22--disponibilité-ja)
- [EN23 – Date des rencontres](#en23--date-des-rencontres)
- [EN24 – Remplacement équipe](#en24--remplacement-équipe)
- [EN27 – Clubs / Associations](#en27--clubs--associations)
- [EN28 – Suivi des nominations](#en28--suivi-des-nominations)
- [ED51 – Défiscalisation JA](#ed51--défiscalisation-ja)
- [ED52 – Barème kilométrique](#ed52--barème-kilométrique)
- [ED53 – Attestation sur l'honneur](#ed53--attestation-sur-lhonneur)
- [ED54 – Attestations reçues](#ed54--attestations-reçues)
- [ED55 – Comptes EBP des JA](#ed55--comptes-ebp-des-ja)
- [EA81 – Salles](#ea81--salles)
- [EA82 – Import Rencontres](#ea82--import-rencontres)
- [EA83 – Import Rencontres Nationales](#ea83--import-rencontres-nationales)
- [EA85 – Saison / Nettoyage](#ea85--saison--nettoyage)
- [EA86 – Utilisateurs](#ea86--utilisateurs)
- [EA87 – Communes](#ea87--communes)
- [EA88 – Régions](#ea88--régions)
- [EA89 – Divisions](#ea89--divisions)
- [EA90 – Départements](#ea90--départements)
- [EA91 – Configuration générale](#ea91--configuration-générale)
- [EA93 – Gestion des messages](#ea93--gestion-des-messages)
- [EA96 – Test API FFTT](#ea96--test-api-fftt)
- [EA98 – Administration base de données](#ea98--administration-base-de-données)

---

## E001 – Connexion

**Fichier :** `index.php`  
**Accès :** Public (non authentifié)

### Objectif
Point d'entrée unique de l'application. Authentifie l'utilisateur et initialise la session.

> **CI4 (à jour) :** il n'y a plus de rôle `JA` ni de connexion JA du tout — voir `AuthController.php`. Un JA
> n'utilise jamais E001 ; il accède directement à ses écrans (EN19–EN22) via un lien tokenisé (Obfuscator,
> `?ja=TOKEN`) reçu par email. La description ci-dessous (login Nom + mot de passe licence) est l'ancien
> comportement, conservée pour l'historique.

### Interface
- Champ **Login** (texte) — identifiant Administrateur/Nominateur/CSR
- Champ **Mot de passe** (password, bouton afficher/masquer)
- Bouton **Se connecter**
- Zone de statut (message d'erreur ou de succès)

### Comportement
| Situation | Résultat |
|-----------|----------|
| Déjà connecté | Redirige immédiatement vers E002 (Admin), E003 (Nominateur) ou E004 (CSR) |
| Login ou MDP vide | Message d'avertissement, pas d'appel base |
| Identifiants invalides ou compte inactif | Message `Échec : Identifiants invalides.` |
| Mot de passe valide, double authentification active (défaut) | **1re étape seulement** : `session_regenerate_id(true)`, état `$_SESSION['mfa_attente']` (id utilisateur, `debut`, hash du code, `essais`, `envois`) — **aucun** `$_SESSION['utilisateur']` —, code de 6 chiffres envoyé à `utilisateur.Email`, redirection vers E010 (`login/code`) |
| Mot de passe valide, `utilisateur.Email` vide/invalide | Refus : « Aucune adresse email valide n'est enregistrée sur votre compte, contactez l'administrateur. » (journal `[NIJAC][SEC]`) |
| Mot de passe valide, envoi de l'email impossible | Refus : « Impossible d'envoyer le code, réessayez ou contactez l'administrateur. » (erreur technique journalisée, sans le code ; aucun état d'attente conservé) |
| Mot de passe valide, `configuration.double_authentification = '0'` (coupe-circuit) | Connexion par mot de passe seul, comme avant (journal `[NIJAC][SEC] double authentification désactivée par configuration` à chaque connexion) |
| Retour sur E001 | Toute connexion inachevée (`mfa_attente`) est abandonnée ; affiche le message transmis par E010 (essais épuisés, délai dépassé) |
| Connexion réussie (après E010) + rôle Admin | Redirection vers `admin_menu.php` (E002) |
| Connexion réussie + rôle Nominateur ou CSR | Redirection vers `Nominateur/menu.php` (E003) ou menu CSR (E004) |
| Erreur base de données | Message système, log PHP |

### Session créée
```php
$_SESSION['utilisateur'] = [
    'id'             => int,
    'login'          => string,
    'nom'            => string,
    'prenom'         => string,
    'role'           => 'Administrateur' | 'Nominateur' | 'CSR' | 'Defiscalisateur' | 'CRA Convoc',
    'id_departement' => string,
    'change_login'   => bool,
    'is_admin'       => bool,
]
```

### Sécurité
- Protection CSRF sur le formulaire POST (`csrfVerify(false)`)
- Mot de passe vérifié via `SecurePasswordHasher::verify()` (bcrypt)
- `session_regenerate_id(true)` après le mot de passe valide, puis à nouveau après validation du code (E010)
- Double authentification par email (E010) ; la session `$_SESSION['utilisateur']` n'est créée qu'après validation du code (le changement de mot de passe forcé `change_login` intervient donc après la double authentification)

---

## E010 – Code de sécurité (double authentification)

**Fichier :** `AuthController::code()` / `AuthController::renvoyerCode()` (CI4), vue `login_code_index.php`, routes `GET/POST login/code`, `POST login/code/renvoi`
**Accès :** Public, mais utilisable seulement avec un état `$_SESSION['mfa_attente']` posé par E001 (sinon redirection vers E001) ; une session déjà authentifiée est redirigée vers son menu.

### Interface
Charte de E001 (bandeau FFTT, carte bleue, sans menu) : champ **Code de sécurité** (`inputmode="numeric"`, `maxlength="6"`, `pattern="[0-9]{6}"`, `autocomplete="one-time-code"`, chiffres seuls, espaces retirés), bouton **Valider**, lien **Recevoir un nouveau code** (désactivé avec compte à rebours tant que le délai court), lien **Annuler** (→ `logout`). Message d'introduction : « Un code de sécurité à 6 chiffres vient d'être envoyé à l'adresse email **<adresse masquée>** enregistrée sur votre compte. Il est valable 10 minutes. » (réaffiché à l'identique après un renvoi). Masquage `masquerEmailAffichage()` (fonction pure, UTF-8, `config/app_config.php`) : 2 premiers caractères de la partie locale, puis un astérisque par caractère restant plafonné à 6 (`***` si la partie locale fait 2 caractères ou moins), puis `@` et le domaine complet (dernier `@`) — `patrick.chautard@free.fr` → `pa******@free.fr`, `ab@x.fr` → `ab***@x.fr`, `a@x.fr` → `a***@x.fr` ; vide ou sans `@` → « (adresse non renseignée) ». Calculé à l'envoi depuis `utilisateur.Email` et conservé seul dans `mfa_attente['email_masque']` (jamais l'adresse complète en session ni dans le HTML), échappé par la vue (`esc()`, en gras) ; en mode Développement c'est l'adresse masquée du compte qui s'affiche, jamais `email_developpement`. Le code n'est jamais affiché (y compris en mode Développement, où il part vers `email_developpement` via `NijacMailer`).

### Règles du code (fonctions pures de `config/app_config.php`)
| Règle | Détail |
|-------|--------|
| Génération | `genererCodeSecurite()` : `random_int(0, 999999)` complété à 6 chiffres (`str_pad`, zéros de tête conservés) |
| Stockage | `hacherCodeSecurite()` = `password_hash()` ; seul le hash est conservé, en session serveur (aucune table, aucune colonne). Jamais en clair (session, base, journaux) |
| Vérification | `verifierCodeSecurite($etat, $saisie, $maintenant)` : `password_verify()` (temps constant) ; usage unique (état supprimé après succès) |
| Durée de vie | 10 minutes (`CODE_SECURITE_DUREE` = 600 s), expiration absolue contrôlée côté serveur depuis l'émission du code |
| Essais | 5 maximum (`CODE_SECURITE_ESSAIS`) ; toute saisie non conforme compte comme un échec ; au 5e échec l'état est supprimé → retour à E001 « Nombre maximal d'essais atteint : veuillez vous reconnecter. » |
| Message d'erreur | Générique : « Code incorrect ou expiré. » |
| Renvoi | `peutRenvoyerCode()` : 60 s minimum entre deux envois, 3 envois maximum par fenêtre glissante de 10 minutes (compteurs en session) ; le nouveau code remplace l'ancien (ancien refusé) et remet les essais à 0 ; un envoi en échec est compté |
| Durée d'attente | L'état `mfa_attente` expire 30 min après le mot de passe (`debut`) : retour à E001 « Délai de connexion dépassé » |
| Anti force brute | Chaque code faux est compté comme un échec de connexion pour l'IP (`enregistrerEchecLogin()`, même verrou que E001 : `login_rate_limit_max` / `login_rate_limit_fenetre`) ; plafond d'envois par compte, indépendant de la session : 6 / 15 min (`checkTentativesRateLimit('mfa_envoi:<id>')`) |
| CSRF | Formulaires POST avec `csrf_field()` (filtre `csrf` global) |

### Après validation
`session_regenerate_id(true)`, suppression de `mfa_attente`, relecture du compte en base (`Actif = 1`), création de `$_SESSION['utilisateur']` (format inchangé), rappel d'expiration FFTT pour un administrateur, redirection selon le rôle (`redirectForRole()`).

### Email envoyé
Message système `messagerie` de Type `Code de sécurité` (constante `TYPE_MESSAGE_CODE_SECURITE`), Sujet « Votre code de sécurité NIJAC », corps texte brut avec le marqueur `{CODE}`, Cc = 0, ReplyTo = 0, envoyé au seul titulaire du compte (`utilisateur.Email`). Amorcé par `assurerModeleCodeSecurite()` dans `initTableConfiguration()` (EA98, jamais d'écrasement) ; lu via `resoudreModeleMessagerieParType()` (message système uniquement) ; repli sur `corpsParDefautCodeSecurite()` si la ligne n'existe pas encore (avant EA98). Éditable en EA93 par un administrateur uniquement (pas de copie personnelle, invisible des autres rôles, absent des modèles d'EN15).

---

## E002 – Menu administrateur

**Fichier :** `admin_menu.php`  
**Accès :** Administrateur uniquement

### Objectif
Page d'accueil de l'espace administrateur. Donne accès à tous les écrans de paramétrage.

### Interface
- Barre utilisateur : nom, département, alerte changement de mot de passe
- Bouton **Menu nominateur** (bascule vers E003)
- Bouton **Menu CRA Convoc** (bascule vers E009, route `cra-convoc-menu`), à côté de Menu CSR / Menu Défiscalisateur
- Grille de boutons (5 colonnes) avec code écran en haut à droite de chaque bouton :

| Bouton | Code | Destination |
|--------|------|-------------|
| Club / Association | EN27 | `club.php` (déplacé vers le menu nominateur E003) |
| Salle | EA81 | `salle.php` |
| Utilisateur | EA86 | `utilisateur.php` |
| Communes | EA87 | `communes.php` |
| Division | EA89 | `division.php` |
| Import Rencontres | EA82 | `import_rencontres.php` |
| Import Rencontres Nationales | EA83 | `import_rencontres_nat.php` |
| Régions | EA88 | `region.php` |
| Départements | EA90 | `departement.php` |
| Saison | EA85 | `clean.php` |
| Configuration | EA91 | `configuration.php` |
| Test API FFTT *(CHAUTARD seulement)* | EA96 | `fftt_test.php` |
| Base de données *(CHAUTARD seulement)* | EA98 | `db-admin.php` |
| Se déconnecter | — | `logout.php` |

### Règles
- Les boutons **Test API FFTT** (EA96) et **Base de données** (EA98) ne sont visibles que si `$_SESSION['utilisateur']['login'] === 'CHAUTARD'`
- Le bouton **Se déconnecter** demande une confirmation JavaScript

> **Note :** l'ancien écran Correspondants de clubs (`correspondant.php`) a été supprimé. La gestion des correspondants est désormais intégrée à l'écran EN27 (Clubs / Associations), sous forme de colonnes directement sur la fiche club.

---

## E003 – Menu nominateur

**Fichier :** `Nominateur/menu.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Page d'accueil de l'espace nominateur avec tableau de bord et accès aux fonctions de nomination.

### Tableau de bord (calculs au chargement)
| Indicateur | Description |
|------------|-------------|
| Prochaine journée | Date + numéro de journée des rencontres à venir du département |
| JA actifs | Nombre de JA avec `JA1 = 1` dans le département |
| Nominations à valider | Nominations avec `Valide = 0` sur des rencontres futures — toujours 0 depuis la règle « nomination = valide d'office » (voir EN14) |
| Convocations à envoyer | Nominations `Valide = 1` et `EmailEnvoye = 0` sur rencontres futures |
| Rencontres sans JA | Rencontres futures sans nomination validée |

Les indicateurs affichent un **badge rouge** sur le bouton de menu correspondant si la valeur est > 0.

### Boutons de menu
| Bouton | Code | Destination |
|--------|------|-------------|
| Juge-Arbitre | EN11 | `jugearbitre.php` |
| Disponibilités JA | EN13 | `disponibilites.php` |
| Nomination JA | EN14 | `nomination.php` |
| Gestion des messages | EA93 | `messagerie.php` |
| Centre d'envoi | EN15 | `centrenvoye.php` |
| Comptes EBP des JA | ED55 | `compta.php` |
| Désidératas clubs | EN12 | `JA_R3R4.php` |
| Statistiques JA | EN17 | `stats_ja.php` |
| Se déconnecter | — | `../logout.php` |

### Règle département Seine-Maritime (76)
Le département 76 inclut automatiquement l'Eure (27) dans tous les calculs, configuré via `regles_departements` dans la table `configuration`.

---

## E006 – Changement du mot de passe

**Fichier :** `changer_mot_de_passe.php`  
**Accès :** Tout utilisateur authentifié (Administrateur, Nominateur ou CSR) — sans objet pour un JA, qui n'a plus de login ni de mot de passe (voir E001)

### Objectif
Permet à l'utilisateur connecté de changer son propre mot de passe : saisie du mot de passe actuel (vérifié contre le hash en base), du nouveau mot de passe et de sa confirmation. Réinitialise le flag `ChangeLogin` (forçage de changement à la première connexion) une fois le changement effectué.

### Interface
- Ouverte depuis le lien **"Mot de passe à modifier"** du bandeau utilisateur (toolbar), présent sur toutes les pages authentifiées : chargement en AJAX dans une modale Bootstrap (`_modal_mdp.php`)
- Accessible aussi en page complète autonome (fallback si JS désactivé, ou navigation directe)
- Message d'état coloré (info/warning/danger/success) selon le résultat de la validation
- En cas de succès : bouton **Continuer** vers le menu (Admin ou Nominateur selon le rôle)

### Actions
| Action | Méthode | Description |
|--------|---------|-------------|
| `index` | GET | Retourne la page complète, ou le fragment de formulaire seul si en-tête `X-Requested-With: XMLHttpRequest` (chargement modale) |
| `index` | POST | Valide (champs non vides, robustesse `validerRobustesseMotDePasse()`, confirmation identique, mot de passe actuel correct), met à jour le hash et `ChangeLogin = 0` · Réponse JSON `{ok, msg, retour}` si AJAX, sinon page complète avec le résultat |

### Règles métier
- Nouveau mot de passe : règle commune `validerRobustesseMotDePasse()` (10 caractères min, minuscule + majuscule + chiffre + caractère spécial — voir E008), doit être confirmé à l'identique
- Le mot de passe actuel est vérifié via `SecurePasswordHasher::verify()` avant tout changement
- Le lien de retour (`retour`) pointe vers E002 (menu admin) si `is_admin`, E009 (menu CRA Convoc) pour le rôle « CRA Convoc », sinon E003 (menu nominateur)

---

## E007 – Mot de passe oublié (demande)

**Fichier :** `MotDePasseOublieController` (CI4), vue `mdp_oublie_index.php`
**Accès :** Public (aucun filtre) — lien **"Mot de passe oublié ?"** sur E001

### Objectif
L'utilisateur non connecté demande un lien de réinitialisation en saisissant son identifiant de connexion **ou** l'adresse email de son compte.

### Actions
| Action | Méthode | Description |
|--------|---------|-------------|
| `demande` | GET | Affiche le formulaire (champ unique « identifiant ou email ») |
| `demande` | POST | Cherche un compte `Actif = 1` avec un `Email` non vide où `Login = saisie` OU `Email = saisie` ; si trouvé, envoie l'email de réinitialisation · **Réponse neutre systématique** (« si un compte correspond… »), aucune information sur l'existence du compte |

### Email envoyé
- Modèle : type système **« Mot de passe oublié »** de la table `messagerie` (éditable en EA93), créé par `assurerTemplateMotDePasseOublie()`
- Marqueurs : `{URL_RESET_MDP}` (lien vers E008), `{UTI_PRENOM}`, `{UTI_NOM}`, `{URL_LIGUE}`
- Passe par `getNijacMailer()` + `getEmailDestinataire()` (redirection en mode Développement)

### Sécurité
- Jeton = `"<Id_Utilisateur>-<expiration>-<HMAC-SHA256>"` produit par `genererJetonResetMdp()`
- Clé HMAC = hash du mot de passe **actuel** `+ OBFUSCATOR_SEED` → le jeton devient invalide dès que le mot de passe change (usage unique), **sans aucune colonne en base**
- Durée de validité : 1 heure

---

## E008 – Réinitialisation du mot de passe

**Fichier :** `MotDePasseOublieController` (CI4), vue `mdp_reset_index.php`
**Accès :** Public — URL `?t=<jeton>` reçue par email (E007)

### Actions
| Action | Méthode | Description |
|--------|---------|-------------|
| `reinitialiser` | GET | Valide le jeton (`verifierJetonResetMdp`) ; si KO, page d'erreur + lien vers E007 ; sinon formulaire de double saisie |
| `reinitialiser` | POST | Re-valide le jeton, puis les deux saisies (non vides, identiques, robustesse) ; `UPDATE Utilisateur SET Password = <hash>, ChangeLogin = 0` ; page de succès + lien vers E001 |

### Règles métier
- `verifierJetonResetMdp()` : format `^\d+-\d+-[0-9a-f]{64}$`, non expiré, HMAC recalculé avec le hash de mot de passe **courant** (`hash_equals`) — un jeton déjà utilisé (mot de passe changé) ne repasse pas
- Robustesse imposée par `validerRobustesseMotDePasse()` : **10 caractères minimum**, au moins une minuscule, une majuscule, un chiffre et un caractère spécial (règle commune, réutilisable par E006)
- Hachage via `SecurePasswordHasher::hash()` (PBKDF2-HMAC-SHA256, 600 000 itérations)

---

## E009 – Menu CRA Convoc

**Fichier :** `CraConvocMenuController` (CI4), vue `cra_convoc_menu_index.php`, route `cra-convoc-menu`
**Accès :** rôle « CRA Convoc » ou Administrateur (filtre `craconvocauth`, voir `CraConvocAuth.php`)

### Objectif
Page d'accueil du rôle « CRA Convoc », atteinte automatiquement après connexion (`AuthController::redirectForRole()`). Aucun accès BDD (lit seulement `$_SESSION['utilisateur']`).

### Boutons de menu
| Bouton | Code | Destination |
|--------|------|-------------|
| Compétitions CRA | EC71 | `cra-competition` |
| Disponibilités CRA | EC74 | `cra-dispo` |
| Désignation CRA | EC73 | `cra-designation` |
| Statistiques CRA | EC75 | `cra-stats` |
| Degrés Juge-Arbitre | EC72 | `cra-juge-arbitre` |
| Se déconnecter | — | `logout` |

### Règles
- Bandeau utilisateur standard (alerte « Mot de passe à modifier », bouton **Menu administrateur** visible seulement pour un Administrateur en prévisualisation)
- Le lien de retour d'E006 ramène ce rôle vers E009

---

## EC71 – Compétitions CRA

**Fichier :** `CraCompetitionController` (CI4), vue `cra_competition_index.php`, route `cra-competition`
**Accès :** rôle « CRA Convoc » ou Administrateur (filtre `craconvocauth`) sur toutes les routes · lien depuis E009, retour vers `cra-convoc-menu`

### Objectif
Gérer le calendrier des compétitions régionales (table `CRA_Competition`, créée et seedée par `initTableConfiguration()`).

### Champs
| Champ | Colonne | Règle |
|-------|---------|-------|
| N° | `Numero` INT | Obligatoire, entier ≥ 1, unique |
| Date de début | `DateDebut` DATE | Obligatoire, date valide |
| Date de fin | `DateFin` DATE NULL | Facultative, ≥ date de début |
| Libellé | `Libelle` VARCHAR(150) | Obligatoire |
| Tables min / max | `NbTablesMin` / `NbTablesMax` INT NULL | Facultatifs, entiers ≥ 1, Min ≤ Max si les deux sont saisis |
| Lieu | `Lieu` VARCHAR(100) | Obligatoire, conservé tel que saisi |
| Id club | `Id_Club` CHAR(8) NULL | Facultatif, lecture seule dans la modale (rempli par la recherche club) ; doit exister dans `Club` ; FK `fk_cracompet_club` → `Club.Id_Club` `ON DELETE SET NULL ON UPDATE CASCADE` (best-effort) |
| Nom du club | `NomClub` VARCHAR(100) NULL | Lecture seule ; recopié côté serveur depuis `Club.Nom` quand `Id_Club` est fourni, NULL sinon |
| Niveau JA | `NiveauJA` VARCHAR(20) | Obligatoire, `JA2` / `JA3` / `JAN JA3` |
| JA (total) | `NbrJA` TINYINT UNSIGNED NOT NULL DEFAULT 1 | Nombre **total** de JA, adjoints compris. Entier 1..20 ; pré-rempli à 1 à la création, 1 si absent |
| dont adjoints | `NbrAdjoint` TINYINT UNSIGNED NOT NULL DEFAULT 0 | Nombre d'adjoints **parmi** `NbrJA`. Entier 0..`NbrJA − 1` (au moins un JA principal ; contrôle serveur + modale) ; pré-rempli à 0 à la création, 0 si absent |

Ex. : `NbrJA`=2, `NbrAdjoint`=1 → 1 JA principal + 1 adjoint ; `NbrJA`=2, `NbrAdjoint`=0 → 2 JA principaux ; `NbrJA`=1, `NbrAdjoint`=0 → 1 JA.

`Id_Club` / `NomClub` sont ajoutés (après `Lieu`) par `initTableConfiguration()` si absents ; pas de backfill des lignes existantes. `NbrJA` / `NbrAdjoint` sont ajoutés (après `NiveauJA`) de la même façon ; les lignes existantes prennent les défauts 1 / 0.

### Interface
- Liste triable côté client (tri par défaut : date de début) : N°, Dates (`10/10/2026`, ou `14-15/11/2026` si même mois), Libellé, Tables (`16` ou `16 à 24`), Lieu, Dépt (centré ; calculé dans `data()` par `SUBSTRING(Id_Club,3,2) AS CodeDept`, vide sans club, pas de colonne en base ni dans la modale), Id club, Nom du club, Niveau JA, JA (total), dont adjoints, corbeille
- Filtres côté client : recherche texte (libellé, lieu, dépt, id et nom du club), niveau JA ; compteur de lignes affichées
- Bouton « Nouvelle compétition » et clic sur une ligne → modale Bootstrap de saisie ; erreurs serveur affichées dans la modale
- Recherche du club à partir du Lieu : bouton « Rechercher le club » (loupe) à côté du Lieu, sur clic uniquement → `cra-competition/clubs?q=<lieu>` ; un seul résultat exact (nom ou ville identique) → sélection automatique, plusieurs → liste cliquable (id, nom, ville) sous le champ, aucun → toast « Aucun club trouvé pour ce lieu ». Bouton « Effacer le club » vide Id club / Nom du club
- Suppression : corbeille par ligne, `nijacConfirm(..., {type:'danger'})`

### Actions AJAX
| Méthode | Route | Action |
|---------|-------|--------|
| GET | `cra-competition/data` | Liste complète `{ok, data}` |
| GET | `cra-competition/clubs?q=<lieu>` | Jusqu'à 15 clubs `{ok, data:[{Id_Club, Nom, Ville, exact}]}` dont `Club.Nom` ou la ville d'une `Salle` du club contient le Lieu (LIKE, casse/accents ignorés par la collation ; tirets/apostrophes → espaces, « Saint(e) » → « St(e) ») ; `Ville` = salle principale |
| POST | `cra-competition` | Création (`numero`, `date_debut`, `date_fin`, `libelle`, `nb_tables_min`, `nb_tables_max`, `lieu`, `id_club`, `niveau_ja`, `nbr_ja`, `nbr_adjoint`) ; refus si `nbr_adjoint` > `nbr_ja` − 1 |
| PUT | `cra-competition/{id}` | Modification (mêmes champs) |
| DELETE | `cra-competition/{id}` | Suppression |

Toutes renvoient `{ok: bool, msg}` ; en cas d'erreur de validation `ok=false` et `msg` explicite.

---

## EC73 – Désignation CRA

**Fichier :** `CraDesignationController` (CI4), vue `cra_designation_index.php`, route `cra-designation`
**Accès :** rôle « CRA Convoc » ou Administrateur (filtre `craconvocauth`) sur toutes les routes · lien depuis E009 (après EC71 et EC74), retour vers `cra-convoc-menu`

### Objectif
Désigner les juges-arbitres et adjoints d'une compétition `CRA_Competition` (table `CRA_Designation`).

### Interface
- Mise en page en 2 colonnes (~45 % / ~55 %, hauteur = écran moins en-tête/barre d'outils, chaque panneau défile seul) ; sous 992 px les panneaux s'empilent (tableau au-dessus, hauteur limitée, formulaire dessous)
- **À gauche** : tableau des compétitions (style EC71/EN11, en-tête collant) — colonnes (dans l'ordre) case à cocher d'envoi des convocations (1re colonne, remplace l'ancienne colonne N°), indicateur (✔ = désignation complète, ◐ = partielle ; suivi de « ✉ » si des convocations ont été envoyées, infobulle « Convocations envoyées le JJ/MM/AAAA à HH:MM (n JA) »), Dates, Libellé préfixé du n° (« n°17 — Libellé »), Lieu, Dépt (`SUBSTRING(Id_Club,3,2)`, centré, vide sans club), Niveau JA, désignés/attendus « n/N » (adjoints compris) ; tri par clic sur les en-têtes (sauf la colonne des cases), par défaut par date de début
- En-tête du panneau, sur une ligne (passe à la ligne si trop étroit) : compteur (`#lbl-count`, nombre centré dans sa pastille, même hauteur que le champ et aligné sur lui — conteneur `align-items:flex-end`, pas sur le libellé) à gauche, et **champ de recherche aligné à droite** au style du champ de recherche d'EN11 (libellé « Recherche » au-dessus, motif `.combo-field` d'EN11 avec `<label for="txt-recherche">`, pilule blanche 290 px, bordure fine `--en-line`, ombre légère, placeholder « Rechercher (n°, libellé, lieu)… », bordure bleue au focus ; recherche sur n°, libellé, lieu, dépt ; pleine largeur en dessous du compteur sur mobile) ; le bouton « Envoyer les convocations (n) » est **sous le tableau**, dans une barre collée au bas du panneau (fond clair, filet supérieur, toujours visible pendant que le tableau défile au-dessus ; pleine largeur sur mobile ; reste sous le tableau quand les panneaux sont empilés sous 992 px)
- Fonds des lignes, par priorité : sélection (#c2e6cf + liseré) > survol gris #E9ECEF (toutes les lignes, roses et vertes comprises) > rose « sans JA » / vert « complète » > zébrage neutre (#f7f8fa / blanc, plus de zébrage vert)
- Ligne sur fond vert clair #E3F4E9 (classe `.complete`, infobulle « Désignation complète », pastille « Complète » dans la légende) quand `Complete` est vrai (tous les JA principaux et adjoints attendus désignés)
- Ligne sur fond rose pâle #FFE4E8 (survol gris comme les autres lignes, classe `.sans-ja`, infobulle « Aucun JA principal désigné », pastille « Sans JA principal » dans la légende) quand `NbDesJA = 0` et `NbPrincipaux > 0` (adjoints ignorés) ; recalculé à chaque rechargement de `data()` ; la ligne sélectionnée garde son style de sélection, avec un liseré rose en plus
- **Sélection par clic** (ou Entrée) sur une ligne : la ligne est surlignée et le formulaire s'affiche à droite (sur écran étroit, la page défile jusqu'au formulaire) ; re-cliquer la ligne courante ne recharge pas la saisie ; après enregistrement/effacement, la liste et la compétition courante sont rechargées
- **À droite** : invite « Sélectionnez une compétition dans la liste » tant qu'aucune n'est choisie, puis le formulaire ci-dessous
- Bandeau des critères de la compétition choisie : n°, libellé, dates (DateDebut–DateFin), lieu, club (`NomClub` + `Id_Club`), dépt (`SUBSTRING(Id_Club,3,2)`), tables (Min–Max), niveau JA, « JA attendus : N en tout (dont X adjoint(s)) »
- Quantités : `NbrJA` = nombre **total** de JA, adjoints compris ; `NbrAdjoint` = adjoints parmi ce total. JA principaux = `NbrJA − NbrAdjoint`, adjoints = `NbrAdjoint`, total = `NbrJA`. Une ligne antérieure hors règle EC71 (`NbrAdjoint` ≥ `NbrJA`) est ramenée à adjoints = `NbrJA − 1` (au moins un JA principal, total toujours `NbrJA`)
- Formulaire généré : `NbrJA − NbrAdjoint` listes « JA n°1…n » puis `NbrAdjoint` listes « Adjoint n°1…n », chacune précédée d'un champ « Filtrer… » (masque les options non correspondantes) ; libellé « NOM Prénom (dépt) — club — grades » (dépt = `ja.CodeDept`, repli positions 3-4 de `ja.Id_Club`, omis si inconnu ; le filtre texte ne porte que sur nom + prénom), tri par nom
- Les listes sont regroupées dans deux cartouches (côte à côte sur grand écran, sinon empilés) titrés « Juges-Arbitres (désignés/`NbrJA − NbrAdjoint`) » et « Adjoints (désignés/`NbrAdjoint`) », compteurs mis à jour à chaque changement ; le cartouche Adjoints est masqué s'il n'y a aucun adjoint attendu ni désigné
- Un JA ne peut être choisi qu'une fois (JA ou adjoint) : ses options sont désactivées dans les autres listes
- Disponibilité déclarée en EC74 (`CRA_Dispo.Disponible`) préfixée au libellé de l'option : ✔ Disponible · ◐ Disponible sous condition / À confirmer (précisé en suffixe « (sous condition) » / « (à confirmer) ») · ✖ Indisponible · ? Non renseigné ou aucune ligne `CRA_Dispo` ; infobulle de l'option = statut — simple information, aucun choix n'est interdit ; une fois les statuts de la compétition chargés, les options sont triées par statut (Disponible, À confirmer, Disponible sous condition, Non renseigné, Indisponible) puis NOM prénom (« — non désigné — » en tête), avec un fond clair par statut (vert #e3f4e9, orange #FCE4D6, bleu #DDEBF7, gris #EDEDED, rose #FFC7CE) repris sur la liste fermée pour l'option choisie, et une légende des couleurs au-dessus des cartouches
- **Indisponibles masqués** : les JA dont la disponibilité est « Indisponible » pour la compétition courante ne sont pas proposés dans les listes (sauf s'ils sont déjà désignés dans cette liste, pour ne pas perdre la valeur) ; les autres statuts sont triés Disponible, À confirmer, Disponible sous condition, Non renseigné, avec un fond clair par statut.
- **Liste déroulante personnalisée colorée** (le natif ne garantit pas les couleurs d'option selon navigateur/OS) : chaque `<select>` natif reste dans la page, caché, comme magasin de données (mêmes options, valeurs, événements `change`) ; un bouton (fond = statut du JA choisi, rouge si conflit) ouvre un panneau (≤ 18rem, défilant, vers le haut si pas de place en bas) listant « — non désigné — » puis les options visibles sur fond coloré par statut, avec entêtes de groupe (Disponibles, À confirmer, Sous condition, Non renseignés), option choisie en gras ✓, options désactivées grisées ; le champ « Filtrer… » filtre aussi le panneau et l'ouvre à la frappe ; clavier ↑/↓, Entrée, Échap, clic extérieur ; panneau reconstruit à l'ouverture et bouton resynchronisé après chaque recalcul (`syncCombo()` en fin de `majOptions()`)
- **Chevauchement de dates bloquant** : un JA ne peut être désigné (JA ou adjoint) qu'une fois sur une date donnée, donc jamais sur deux compétitions dont les plages `[DateDebut, COALESCE(DateFin, DateDebut)]` se chevauchent (ex. 14-15/11 et 15/11 = conflit). Les JA déjà désignés sur une autre compétition en conflit sont désactivés (grisés) dans toutes les listes, avec la mention « déjà désigné : EC n°X (dates) » dans le libellé de l'option
- Conflit antérieur (JA déjà enregistré ici et devenu en conflit) : la consultation reste possible, la liste est encadrée en rouge avec « ⛔ Déjà désigné aux mêmes dates sur EC n°X Libellé (dates) : à remplacer avant d'enregistrer » + toast d'alerte au chargement ; l'enregistrement est refusé tant qu'il n'est pas corrigé
- Chargement de la désignation enregistrée (`GET cra-designation/{id}`) en échec (session expirée → redirection vers la connexion, erreur serveur) : formulaire retiré, message « Impossible de charger la désignation enregistrée… rechargez la page » à la place + toast (évite d'afficher « — non désigné — » et d'écraser la désignation réelle à l'enregistrement)
- Refus serveur : message d'erreur détaillé (une ligne par conflit) affiché dans le formulaire au-dessus des boutons + toast « Enregistrement refusé »
- Barre d'actions en bas du formulaire, séparée par un filet, alignée à droite : « Effacer la désignation » (`nijacConfirm` type danger) puis « Enregistrer la désignation » ; boutons pleine largeur sur mobile ; retours par `nijacToast`

### Envoi des convocations
- Colonne de cases à cocher (cochés conservés entre deux affichages / recherches) : case **désactivée** quand aucun JA principal n'est désigné (`NbDesJA = 0`, rien à convoquer) ; un clic sur la case ne sélectionne pas la ligne (`stopPropagation`), le reste de la ligne reste cliquable. En-tête : case « tout cocher » (infobulle « Envoyer les convocations ») qui ne coche/décoche que les lignes affichées par la recherche et cochables (état indéterminé si partiel)
- Bouton « Envoyer les convocations (n) » (n = compétitions cochées, désactivé à 0) → `nijacConfirm` : nombre de compétitions et de destinataires (désignés = JA principaux + adjoints, détaillé), avertissements (désignations incomplètes, désignés sans email, désignés déjà convoqués, mode Développement) ; options ajoutées à la modale : « Renvoyer aussi à ceux déjà convoqués » (décochée, affichée seulement s'il y en a) et « M'envoyer une copie (Cc) »
- Envoi séquentiel, **un appel par compétition** (progression « compétition i / n »), puis compte-rendu dans une zone juste au-dessus du bouton, dans la barre sous le tableau (hauteur limitée, défilante si long) + `nijacToast` : envoyés (détaillés JA principaux / adjoints) / échecs / sans email / ignorés (déjà convoqués) — les adjoints sont suffixés « (adjoint) » dans les listes ; arrêt si `checkRateLimit()` est atteint ; la liste est rechargée (✉ à jour) et les coches vidées
- **Modèles = messages système de la table `messagerie`** (éditables en EA93, `CraDesignationController::modeleConvocation()`), identifiés par leur `Type` (pas d’`Id_Messagerie` fixe : les ids sont attribués par AUTO_INCREMENT à l’amorçage, ex. 11/12/13 en dev) : `CRA Convocation JA` (amorcé depuis `Convocation/Convocation_1JA.html`), `CRA Convocation JA + adjoint` (`Convocation_1JA_1Adjoint.html`), `CRA Convocation adjoint` (`Convocation_Adjoint.html`). Résolution `resoudreModeleMessagerieParType()` : copie personnelle de l’utilisateur courant (« Copier pour personnaliser » en EA93, même `Type`, `Id_Utilisateur` = lui) en priorité, sinon message système (`Id_Utilisateur IS NULL`, le plus ancien) ; rapprochement par `Type` et non par `Sujet` (JA et JA + adjoint partagent le même sujet). **Repli** : ligne absente (EA98 pas encore passé) → fichier `Convocation/` correspondant, sujet par défaut, Cc/ReplyTo = 1. Amorçage : `assurerModelesConvocationCra()` (appelée par `initTableConfiguration()`, EA98) étend l’ENUM `messagerie.Type` et crée chaque message seulement si aucun message système de ce Type n’existe (jamais d’écrasement), corps = fichier tel quel, Cc = ReplyTo = 1 (comme le n°3) ; fichier introuvable → message non créé + `error_log`. `messagerie.Message` passé en `MEDIUMTEXT` (les modèles font ~53 Ko avec leurs images base64). Les fichiers de `Convocation/` ne sont jamais modifiés par l’application
- **Choix du modèle** selon la composition de la compétition : au moins un adjoint attendu (`NbAdjoints ≥ 1`) **ou** désigné (≥ 1 ligne `Role = 'Adjoint'`), quel que soit leur nombre (1 ou plusieurs) → `CRA Convocation JA + adjoint` (JA principal chargé de solliciter son adjoint « parmi les personnes disponibles ci-dessous » : `{LISTE_JA_DISPONIBLES}` = JA **disponibles** EC74, pas les adjoints désignés — sauf adjoints déjà validés, voir « Adjoints validés » ci-dessous) ; sinon → `CRA Convocation JA` (JA principal seul). Le modèle avec adjoint s'accorde au nombre d'adjoints via les marqueurs de pluralisation (voir ci-dessous). Hypothèse : **plusieurs JA principaux** : chacun reçoit le même modèle (texte au singulier). **Adjoints désignés** : chacun reçoit `CRA Convocation adjoint` (titre « CONVOCATION JA ADJOINT », convoqué en qualité de Juge-Arbitre adjoint, mêmes informations pratiques — épreuve, date(s), salle et adresse, nombre de tables —, bloc « Coordonnées du Juge-Arbitre principal » (nom, téléphone, email), coordination (horaire de présence, répartition des missions) assurée par le JA principal, confirmation de réception/disponibilité à la CRA, note de frais LUCCA, indisponibilité à signaler à la CRA ; ni sollicitation d'adjoint, ni liste des JA disponibles, ni rapport de fin d'épreuve)
- Destinataires : chaque JA principal **et chaque adjoint** désigné ayant un email (sans email : listé, pas d'envoi) ; déjà convoqué (`DateConvocation` renseignée) : ignoré sauf « Renvoyer ». Aucun JA principal désigné → aucun envoi pour la compétition (adjoints compris : rien à leur présenter), `{ok:false}` avec ce motif ; modèle `CRA Convocation adjoint` introuvable (ni ligne ni fichier) → adjoints en échec, les JA principaux sont convoqués quand même
- **Marqueurs du modèle adjoint** (clé de contexte `ja_principaux` = JA principaux désignés de la compétition, `ctxConvocation($c, 0, $principaux)` ; absente → valeurs vides, rétro-compatible) : `{NOM_JA_PRINCIPAL}` « Prénom NOM », `{TEL_JA_PRINCIPAL}` (10 chiffres → 06.12.34.56.78, même format que `JugearbitreController::formaterTelephone()`), `{EMAIL_JA_PRINCIPAL}` ; plusieurs JA principaux → « A, B et C » (valeurs vides omises), texte échappé HTML par `rendreConvocation()`
- Marqueurs remplacés via `construireMarqueursMessage($ja, $moi, $ctx)` : `{SAISON}`, `{DATE_EDITION}`, `{NOM_COMPLET}`, `{EPREUVE}` = `Libelle`, `{DATE_LONGUE}` = `DateDebut`, en plage si `DateFin` diffère (clé de contexte `date_fin`, rétro-compatible : « samedi 14 – dimanche 15 novembre 2026 », mois/année du début répétés seulement s'ils diffèrent), `{NB_TABLES}` = « 16 » ou « 16 à 24 » (`NbTablesMin`/`NbTablesMax`), `{SALLE_NOM}`/`{SALLE_ADRESSE}`/`{SALLE_CP}`/`{SALLE_VILLE}` = salle du club organisateur (`CRA_Competition.Id_Club` → `salle`, principale en priorité ; vides sans club, `{SALLE_VILLE}` = `Lieu` alors), `{LISTE_JA_DISPONIBLES}` (modèle avec adjoint seulement) = JA ayant répondu Disponible / À confirmer / Disponible sous condition (`CRA_Dispo`, statut précisé après le nom sauf « Disponible », triés Disponible d'abord puis nom), au moins JA2, hors JA désignés sur cette compétition ou sur une compétition aux dates qui se chevauchent. Valeurs échappées (`htmlspecialchars`) dans le corps, sauf `{LISTE_JA_DISPONIBLES}` (HTML déjà échappé)
- **Marqueurs de pluralisation** (modèle avec adjoint, propres aux convocations CRA, absents des listes EN15/EA) — clé de contexte `nb_adjoints` = `NbrAdjoint` attendu, ou nombre d'adjoints désignés s'il est supérieur, minimum 1 (`ctxConvocation()`) ; clé absente ou 1 → forme singulière, rendu identique à l'ancien texte du modèle. Forme 1 / forme N (nombre en lettres de deux à dix, en chiffres au-delà) : `{NB_ADJOINTS}` « 1 » / « 2 » ; `{TITRE_ADJOINTS}` « 1 ADJOINT » / « 2 ADJOINTS » ; `{ADJOINTS_TEXTE}` « un seul Juge-Arbitre adjoint » / « deux Juges-Arbitres adjoints » ; `{ADJOINTS_A_SOLLICITER}` « un JA2 ou un JA3 » / « deux JA2 ou JA3 » ; `{ADJOINTS_RETENUS}` « la personne retenue afin de confirmer sa disponibilité et son accord » / « les personnes retenues … leur disponibilité et leur accord » ; `{ADJOINTS_SOLLICITES}` « le nom et le prénom du Juge-Arbitre adjoint sollicité … sa convocation lui sera adressée » / « les noms et prénoms des Juges-Arbitres adjoints sollicités … leur convocation leur sera adressée » ; `{VOTRE_ADJOINT}` « votre adjoint » / « vos adjoints » ; `{LUI_LEUR}` « lui » / « leur »
- **Adjoints validés** (modèle avec adjoint) : adjoint validé = désigné dans `CRA_Designation` (`Role = 'Adjoint'`) pour la compétition ; clés de contexte `adjoints_valides` (nombre) et `liste_adjoints_valides` (lignes Nom, Prenom, Telephone, Email, Rang, triées par Rang), comparées à `nb_adjoints` (attendus). (a) **aucun validé** (ou clés absentes) : rendu strictement identique à l'ancien (tableau des JA disponibles, texte « solliciter… ») ; (b) **tous validés** (validés ≥ attendus) : le tableau ne liste **que** les adjoints validés (même format de lignes : Prénom NOM, « téléphone — email »), aucun JA disponible (`ja_disponibles` n'est même pas calculé), texte « Le Juge-Arbitre adjoint désigné par la CRA est indiqué ci-dessous » / « Les Juges-Arbitres adjoints désignés par la CRA sont indiqués ci-dessous », « Vous voudrez bien le/les contacter pour convenir de l'horaire de présence et de la répartition des missions » ; (c) **en partie** : le tableau liste d'abord les validés, puis une ligne d'en-tête « Adjoint(s) restant(s) à solliciter parmi les personnes disponibles » et les JA disponibles (hors désignés), le texte distingue « déjà désigné(s) » et « encore à solliciter » (nombre restant en lettres). Marqueurs du modèle : `{ADJOINTS_INTRO}` (phrase solliciter / désignés / désignés + encore à solliciter), `{TITRE_LISTE_ADJOINTS}` (« Juges-Arbitres disponibles » / « Juge(s)-Arbitre(s) adjoint(s) désigné(s) » / « … et Juges-Arbitres disponibles »), `{ADJOINTS_CONTACT}` (prise de contact) ; `{ADJOINTS_A_SOLLICITER}`, `{ADJOINTS_RETENUS}` et `{ADJOINTS_SOLLICITES}` s'accordent au nombre **restant** à solliciter, `{ADJOINTS_SOLLICITES}` devenant « la confirmation de votre prise de contact avec le(s) … désigné(s) ; sa/leur convocation lui/leur est adressée directement par la CRA » en (b). Un ancien modèle sans ces marqueurs reste compatible
- Email : sujet = `Sujet` du message (par défaut « CRA – Convocation – {EPREUVE} – {DATE_LONGUE} », adjoint : « CRA – Convocation adjoint – {EPREUVE} – {DATE_LONGUE} », marqueurs remplacés ; un marqueur inconnu reste visible tel quel) (préfixe « [DEV] » en mode Développement), corps = HTML du modèle (`msgHTML()` : les images base64 du modèle deviennent des pièces jointes inline CID, texte brut généré), `getNijacMailer()` + `getEmailDestinataire()`, Reply-To utilisateur si `ReplyTo = 1` sur le message, Cc si `Cc = 1` sur le message **et** « M’envoyer une copie » coché, `checkRateLimit()` / `enregistrerEnvois()` ; succès → `CRA_Designation.DateConvocation = NOW()` (désignation du JA ou de l'adjoint convoqué)

### Règles d'éligibilité
Hiérarchie JA1 < JA2 < JA3 < JAN < JAI (colonnes `ja.JA1…JAI` TINYINT(1) à 1 = grade actif).
- **JA** : au moins un des grades de `NiveauJA` ou un grade supérieur — codes séparés par espace = l'un ou l'autre, donc seuil = le plus bas des codes (`JA2` → JA2/JA3/JAN/JAI ; `JA3` et `JAN JA3` → JA3/JAN/JAI)
- **Adjoint** : au moins JA2 (JA2, JA3, JAN ou JAI à 1)
- JA proposés : ceux des départements actifs de la région (`ja.CodeDept` ∈ `getDeptActifs()` ; aucun filtre si la configuration est vide)
- Validation serveur à l'enregistrement : compétition existante, JA existants, éligibles (grade uniquement, pas la région), aucun doublon dans la compétition (ni deux fois JA/adjoint, ni JA et adjoint), aucun JA désigné au-delà de `NbrJA − NbrAdjoint` listes JA / `NbrAdjoint` listes adjoint (positions excédentaires vides acceptées), **aucun JA désigné sur une autre compétition aux dates qui se chevauchent** (contrôle dans la transaction, avant toute écriture ; refus global, rollback) ; listes vides autorisées (désignation partielle)

### Actions AJAX
| Méthode | Route | Action |
|---------|-------|--------|
| GET | `cra-designation/data` | `{ok, competitions:[… + CodeDept, NbDesJA, NbDesAdj, RangMaxJA, RangMaxAdj, NbConvoques, NbConvAdj, DerniereConvocation, NbSansEmail, NbPrincipaux, NbAdjoints, Complete], jas:[Id_JA, Nom, Prenom, JA2, JA3, JAN, JAI, NomClub], modeDev}` (JA de la région ayant au moins JA2 ; `NbConvoques` = désignations (JA et adjoints) avec `DateConvocation`, dont `NbConvAdj` adjoints — infobulle ✉ « x JA principal(aux), y adjoint(s) », « tous les désignés convoqués » ou « sur N désigné(s) » ; `NbSansEmail` = désignés sans email, tous rôles ; `DerniereConvocation` = MAX) |
| GET | `cra-designation/{id}` | `{ok, data:[{Role, Rang, Id_JA}], indispos:{Id_JA:[{Nom, Numero, Libelle, DateDebut, DateFin}]}, dispos:{Id_JA: statut}}` — `indispos` = JA désignés sur une AUTRE compétition aux dates qui se chevauchent (une requête) ; `dispos` = lignes `CRA_Dispo` de la compétition, statut = une des 5 valeurs de `Disponible` (vide si la table n'existe pas encore) |
| POST | `cra-designation/{id}` | `ja[]`, `adjoint[]` positionnels (rang = position, vide = non désigné) ; remplace la désignation (transaction DELETE + INSERT) ; `{ok, msg}` ou, en cas de chevauchement, `{ok:false, err, msg}` (`err` = « NOM Prénom : déjà désigné sur EC n°X Libellé (dates) » par conflit, aucune écriture) |
| POST | `cra-designation/convocations` | `id` (compétition), `renvoyer` (0/1), `cc` (0/1) ; envoie les convocations de la compétition (voir ci-dessus) ; `{ok, titre, modele, envoyes[] (JA principaux), envoyesAdj[] (adjoints), echecs[], sansEmail[], ignores[], stop?}` ou `{ok:false, titre, msg}` (aucun JA principal, modèle introuvable) |
| DELETE | `cra-designation/{id}` | Efface toute la désignation de la compétition |

### Table `CRA_Designation`
Créée par `initTableConfiguration()` (InnoDB utf8mb4_unicode_ci, best-effort) : `Id_CRA_Designation` INT AI PK · `Id_CRA_Competition` INT NOT NULL (FK `fk_cradesig_compet` → `CRA_Competition`, CASCADE/CASCADE) · `Role` ENUM('JA','Adjoint') · `Rang` TINYINT UNSIGNED (1..n) · `Id_JA` INT NOT NULL (FK `fk_cradesig_ja` → `ja.Id_JA`, CASCADE/CASCADE, posée dans un try/catch séparé) · `DateSaisie` DATETIME DEFAULT CURRENT_TIMESTAMP · `Id_Utilisateur` INT NULL (saisie, depuis la session) · `DateConvocation` DATETIME NULL (dernier envoi réussi de la convocation ; ajoutée par un `ADD COLUMN` conditionnel ; conservée à l'enregistrement pour un JA maintenu dans le même rôle) · UNIQUE (`Id_CRA_Competition`, `Role`, `Rang`) et (`Id_CRA_Competition`, `Id_JA`).

---

## EC74 – Disponibilités CRA

**Fichier :** `CraDispoController` (CI4), vues `cra_dispo_index.php` (écran interne) et `cra_dispo_public.php` (page publique), routes `cra-dispo` et `dispo-cra`
**Accès :** écran interne : rôle « CRA Convoc » ou Administrateur (filtre `craconvocauth`) · lien depuis E009 (juste après EC71), retour vers `cra-convoc-menu` · page `dispo-cra` : **publique** (aucun filtre d'auth, comme EN22), identifiée par `?ja=TOKEN`

### Objectif
Demander par email aux JA éligibles leurs disponibilités pour une ou plusieurs compétitions `CRA_Competition`, recueillir leurs réponses (page publique tokenisée) ou les saisir à la main (réponse par téléphone), et suivre l'état des réponses (table `CRA_Dispo`).

### Écran interne (EC74)
- Mise en page d'EC73 (2 colonnes, empilées sous 992 px)
- **À gauche** : tableau des compétitions — case à cocher (désactivée pour une compétition passée ; case d'en-tête = toutes les compétitions à venir), N°, Dates, Libellé, Lieu, Niveau JA, Dem. (demandes envoyées), Réponses (pastilles de couleur, affichées seulement si non nulles : Disponible, À confirmer, Sous condition, Indisponible, Sans réponse = demandés encore « Non renseigné » ; tri de la colonne = nombre de disponibles) ; légende des couleurs au-dessus du tableau ; recherche (libellé, lieu), tri par en-tête (défaut : date de début) ; clic sur une ligne = compétition courante
- **À droite** : bandeau de la compétition courante (dates, lieu, niveau, compteurs) ; « Envoi pour : … » = compétitions **cochées** (à venir), ou à défaut la compétition courante si elle est à venir ; champ optionnel « Répondre avant le » (date) ; case « M'envoyer une copie (Cc) »
- Liste des JA éligibles à la compétition courante (même règle et même périmètre qu'EC73 : grade ≥ seuil de `NiveauJA`, JA2+ ; `ja.CodeDept` ∈ départements actifs) : case de sélection, NOM Prénom + club (« ✖ pas d'email » le cas échéant), grades, statut (Pas demandé / Demandé le JJ/MM/AAAA tant que « Non renseigné » / sinon pastille du statut + date de réponse, source « réponse du JA » ou « saisie par Prénom Nom », commentaire), **saisie manuelle** : sélecteur des 5 statuts (pré-rempli) + commentaire optionnel (255 car., pré-rempli) + « Enregistrer » ; « Non renseigné » = effacer la réponse
- Couleurs des statuts (celles de la matrice de suivi) : Disponible vert · Indisponible rose · Non renseigné gris · À confirmer orange · Disponible sous condition bleu
- Boutons « Tout sélectionner les non-demandés » / « Aucun »
- **Filtre « Disponibilité »** (côté client, au-dessus de la liste des JA) : Tous · Disponible · Disponible sous condition · À confirmer · Indisponible · Non renseigné (toujours les 5 valeurs, quel que soit `cra_dispo_choix_etendus`) · Pas demandé (aucune ligne `CRA_Dispo`) ; compteur « n affichés / N » + « k sélectionné(s) dont m masqué(s), non envoyé(s) » ; conservé au changement de compétition, remis à « Tous » au rechargement ; « Tout sélectionner les non-demandés » et « Relancer » ne portent que sur les lignes affichées, les coches des lignes masquées sont conservées (« Demander » n'envoie qu'aux lignes cochées ET affichées ; « Aucun » décoche tout)
- **Réglage « Proposer « À confirmer » et « Sous condition » »** (interrupteur au-dessus du tableau des compétitions, à côté de « Importer la matrice ») : clé `cra_dispo_choix_etendus` de la table `configuration` (`'1'` par défaut / clé absente = proposés, `'0'` = masqués), état rechargé à l'ouverture (`cra-dispo/data` → `choixEtendus`), enregistré immédiatement (POST `cra-dispo/reglage`, toast ; en cas d'échec la case revient à l'état précédent). À « non » : la page publique et la saisie manuelle ne proposent plus ces 2 choix (la valeur **déjà enregistrée** sur une ligne reste proposée, marquée « (valeur actuelle) », pour pouvoir la conserver), validation serveur identique ; l'import de la matrice les importe quand même et l'aperçu le signale. Les valeurs déjà enregistrées restent **toujours** affichées (liste, compteurs, pastilles, EC73 ◐) : seuls les choix proposés à la saisie changent
- Saisie manuelle, réglage à « non » : sélecteur limité à Non renseigné / Indisponible / Disponible (+ valeur actuelle de la ligne si c'est « À confirmer » ou « Disponible sous condition »)
- **Demander les disponibilités** : JA cochés · **Relancer les sans réponse** : JA de la liste demandés et encore « Non renseigné » pour la compétition courante (cases ignorées) ; `nijacConfirm` avec le nombre de destinataires, le nombre de JA sans email (ignorés) et la mention du mode Développement ; envoi séquentiel, un appel AJAX par JA (comme EN15) ; compte-rendu dans l'écran (envoyés, JA sans email, JA sans compétition concernée, échecs avec motif ; arrêt si la limite d'envoi est atteinte) + toast ; rechargement des compteurs/statuts
- Un **seul email par JA**, listant toutes les compétitions visées pour lesquelles il est éligible (relance : seulement celles demandées et encore « Non renseigné ») ; compétitions passées toujours exclues

### Email
- Sujet « CRA – Demande de disponibilités » (préfixe `[DEV]` si redirigé en mode Développement) ; corps HTML construit dans le contrôleur (**pas** de modèle `messagerie` ni de nouveau type de message) : « Bonjour Prénom Nom », mention « Rappel » en relance, tableau N° / Dates / Compétition / Lieu, bouton + lien en clair vers `dispo-cra?ja=TOKEN` (`site_url`), « Merci de répondre avant le JJ/MM/AAAA » si la date est saisie, signature (Prénom Nom de l'utilisateur, « Commission Régionale d'Arbitrage », son email)
- Envoi via `getNijacMailer()`, destinataire passé par `getEmailDestinataire()` (mode Développement → adresse de développement), Reply-To = email de l'utilisateur connecté, Cc = lui-même si la case est cochée ; limitation de débit `checkRateLimit()` / `enregistrerEnvois()` (session rouverte le temps de l'envoi pour que le compteur persiste) ; erreur SMTP journalisée (`error_log`)
- Après envoi réussi : ligne `CRA_Dispo` créée ou mise à jour (`DateDemande` = maintenant) pour chaque compétition de l'email ; un statut ≠ « Non renseigné » n'est jamais écrasé (`Disponible`, `Source`, `Commentaire`, `Id_Utilisateur` de la saisie conservés)

### Page publique `dispo-cra?ja=TOKEN`
- Vue autonome responsive (en-tête sarcelle avec le nom du JA, sans menu ni toolbar), `noindex`
- Token Obfuscator **avec pepper** (`new Obfuscator(OBFUSCATOR_SEED, getObfuscatorPepper())`) ; token absent, invalide (`deobfuscate` = -1) ou JA inexistant → message « Lien invalide ou expiré » ; aucune autre donnée personnelle dans l'URL
- Liste uniquement les compétitions **demandées à ce JA** (`CRA_Dispo.DateDemande` non NULL) : n°, dates, libellé, lieu, niveau ; pour chacune choix obligatoire parmi 4 réponses **Disponible / Disponible sous condition / À confirmer / Indisponible** (« Non renseigné » n'est pas un choix : aucun bouton coché tant que le JA n'a pas répondu) + commentaire (255 car.), **obligatoire pour « Disponible sous condition »** (la condition à préciser), facultatif sinon, pré-remplis avec les réponses enregistrées ; **réglage `cra_dispo_choix_etendus` = '0'** : seuls Disponible / Indisponible sont proposés (+ la valeur actuelle du JA si c'est « À confirmer » ou « Disponible sous condition », marquée « (valeur actuelle) »), commentaire toujours facultatif (plus de condition obligatoire) ; compétition passée (`COALESCE(DateFin, DateDebut)` < aujourd'hui) affichée en lecture seule ; aucune donnée d'un autre JA
- Validation client : tous les choix renseignés et ∈ les 4 réponses, condition saisie pour « Disponible sous condition » (blocs fautifs encadrés en rouge) → récapitulatif (pastilles de couleur + libellé du statut + commentaire) → bouton « Valider mes disponibilités » (« Modifier » pour revenir ; toute modification masque le récapitulatif)
- Validation serveur (POST `dispo-cra`) : token/JA valides, chaque compétition reçue réellement demandée à ce JA et non passée, valeur ∈ {Disponible, Disponible sous condition, À confirmer, Indisponible} pour **chaque** compétition modifiable, commentaire ≤ 255 caractères et non vide pour « Disponible sous condition » (seulement si le réglage est à « oui ») ; réglage à « non » : « À confirmer » / « Disponible sous condition » refusés sauf si c'est la valeur déjà enregistrée sur la ligne ; en cas d'erreur, formulaire réaffiché avec le message et les valeurs saisies ; écriture en transaction (`Disponible`, `Commentaire`, `DateReponse` = maintenant, `Source` = 'JA')
- Protection CSRF : filtre `csrf` global (mode cookie), `csrf_field()` dans le formulaire ; pas de limitation de débit (EN22 n'en a pas)
- Après validation : message de remerciement + récapitulatif (libellés des statuts), lien « Modifier mes réponses »

### Import de la matrice Excel (« Importer la matrice »)
- Bouton « Importer la matrice » (au-dessus du tableau des compétitions) → modale : fichier `.xlsx` (5 Mo max. ; contrôles serveur : upload valide, taille, extension `xlsx`, type MIME xlsx/zip, ouverture réelle par PhpSpreadsheet, lecteur `Xlsx` imposé, données seules) + case « Écraser les réponses existantes » (décochée par défaut)
- **Analyser** (POST `cra-dispo/import/apercu`) : aperçu **sans écriture** — JA lus / rapprochés / non rapprochés (avec motif, noms affichés dans la modale uniquement, jamais journalisés), épreuves lues / compétitions rapprochées, lignes à créer / à mettre à jour, ignorées (« Non renseigné », réponse existante conservée, valeur inconnue), divergences. **Importer** (POST `cra-dispo/import/valider`) : le navigateur renvoie le même fichier, le serveur refait toute l'analyse puis écrit ; le bouton n'est actif qu'après une analyse avec au moins une écriture, et redevient inactif si le fichier ou la case change
- Aucun classeur conservé : lecture directe du fichier temporaire d'upload PHP (ni déplacé ni copié, supprimé par PHP en fin de requête)
- Structure attendue : feuille **« Matrice »** — ligne 1 = JA « Prénom NOM » à partir de la colonne B ; colonne A à partir de la ligne 2 = « JJ/MM/AAAA | libellé » (ou « JJ-JJ/MM/AAAA | … » : date de début) ; cellules = texte (la couleur est ignorée). Feuille **« Référents »** facultative — colonne A = nom, colonne B = licence (noms de feuilles comparés sans casse ni accents)
- **Rapprochement des JA** : clé de nom = mots normalisés (casse, accents, tirets/espaces) triés, donc indépendante de l'ordre Nom/Prénom. Licence lue dans « Référents » (même clé de nom) = `ja.Id_JA`, acceptée seulement si ce JA existe et porte le même nom ; licence absente ou inconnue en base → repli sur nom + prénom dans `ja`, accepté seulement si **un seul** JA correspond. Homonymes, absence, licence attribuée à un autre nom, JA en double dans la matrice → « non rapproché », colonne ignorée (aucune déduction)
- **Rapprochement des compétitions** : date de début + libellé normalisé de `CRA_Competition` (correspondance unique), à défaut position (ligne r = `Numero` r−1) ; toute divergence est signalée (rapprochement par position, numéro différent de la position, ligne sans compétition — ignorée —, compétition déjà rapprochée par une autre ligne, date illisible)
- **Mapping** direct (texte comparé sans casse ni accents) : « Disponible », « Indisponible », « À confirmer », « Disponible sous condition » → même valeur dans `Disponible` · « Non renseigné » ou cellule vide → **aucune écriture** (ni création, ni modification) · autre texte → ignoré et signalé. `Commentaire` NULL à la création, remis à NULL lors d'une mise à jour (il se rapportait à l'ancienne réponse)
- **Écrasement** : pas de ligne → création (`DateDemande` NULL) ; ligne « Non renseigné » → complétée ; ligne avec réponse (statut ≠ « Non renseigné ») → conservée, sauf si « Écraser les réponses existantes » est coché
- Aperçu / compte-rendu : en plus des compteurs, effectifs par statut à écrire / écrits (`parStatut`)
- Réglage `cra_dispo_choix_etendus` = '0' : les cellules « À confirmer » / « Disponible sous condition » sont importées **telles quelles** (données source conservées, import non bloqué) ; l'aperçu et le compte-rendu affichent un avertissement avec leur nombre (`masquees`, résumé en orange)
- Écriture : `Source`='Saisie', `DateReponse`=NOW(), `DateDemande` inchangée, `Id_Utilisateur` = utilisateur courant ; **une seule transaction** (rollback complet en cas d'erreur) ; requêtes préparées ; compétitions passées incluses ; périmètre/éligibilité EC73 non contrôlés (la dispo est une information)
- Résultat : compte-rendu dans la modale + toast (créées / mises à jour / ignorées / JA non rapprochés), puis rechargement des compteurs et de la compétition courante

### Actions
| Méthode | Route | Action |
|---------|-------|--------|
| GET | `cra-dispo` | Écran interne |
| POST | `cra-dispo/import/apercu` | multipart `xlsx` (fichier), `ecraser` (0/1) → `{ok, ecrit:false, nbJa, jaRapproches, nonRapproches:[{nom, raison}], nbEpreuves, competRapprochees, creer, maj, parStatut:{statut: nb}, ignNonRenseigne, ignConservees, ignInconnues, divergences[], choixEtendus, masquees}` ou `{ok:false, msg}` ; aucune écriture |
| POST | `cra-dispo/import/valider` | mêmes paramètres → même réponse avec `ecrit:true` après écriture en transaction |
| GET | `cra-dispo/data` | `{ok, competitions:[Id_CRA_Competition, Numero, DateDebut, DateFin, Libelle, Lieu, NiveauJA, Passee, NbDemandes, NbDispo, NbConfirmer, NbCondition, NbIndispo, NbSans], jas:[Id_JA, Nom, Prenom, JA2, JA3, JAN, JAI, NomClub, AEmail], modeDev, choixEtendus}` |
| POST | `cra-dispo/reglage` | `valeur` ∈ {`1`, `0`} → `configuration.cra_dispo_choix_etendus` (INSERT … ON DUPLICATE KEY UPDATE) → `{ok, choixEtendus, msg}` ou `{ok:false, msg}` |
| GET | `cra-dispo/{id}` | `{ok, data:[{Id_JA, Disponible, Commentaire, DateDemande, DateReponse, Source, NomUtilisateur}]}` |
| POST | `cra-dispo/envoyer` | `id_ja`, `competitions[]`, `relance` (0/1), `date_limite` (AAAA-MM-JJ, optionnel), `cc` (0/1) → `{ok, nom, nb}` ou `{ok:false, skip, sansEmail?, stop?, nom, msg}` ; JA contrôlé côté serveur (périmètre + éligibilité par compétition) |
| POST | `cra-dispo/{id}/saisie` | `id_ja`, `valeur` ∈ les 5 statuts (validation stricte ; réglage à « non » : « À confirmer » / « Disponible sous condition » refusés sauf valeur actuelle de la ligne), `commentaire` optionnel (≤ 255) — saisie : `Disponible`, `Commentaire`, `Source`='Saisie', `Id_Utilisateur`, `DateReponse` ; `valeur` = « Non renseigné » = Effacer (ligne supprimée si jamais demandée, sinon retour à « Non renseigné », commentaire/date/source vidés) ; JA éligible requis |
| GET | `dispo-cra?ja=TOKEN` | Page publique |
| POST | `dispo-cra` | `ja` (token), `dispo[Id_CRA_Competition]` ∈ {Disponible, Disponible sous condition, À confirmer, Indisponible}, `commentaire[Id_CRA_Competition]` (obligatoire pour « Disponible sous condition » si le réglage est à « oui ») ; réglage à « non » : seules Disponible / Indisponible ou la valeur déjà enregistrée sont acceptées |

### Table `CRA_Dispo`
Créée par `initTableConfiguration()` (InnoDB utf8mb4_unicode_ci, best-effort) : `Id_CRA_Dispo` INT AI PK · `Id_CRA_Competition` INT NOT NULL (FK `fk_cradispo_compet` → `CRA_Competition`, CASCADE/CASCADE) · `Id_JA` INT NOT NULL (FK `fk_cradispo_ja` → `ja.Id_JA`, CASCADE/CASCADE, posée dans un try/catch séparé) · `Disponible` ENUM('Non renseigné','Indisponible','Disponible','À confirmer','Disponible sous condition') NOT NULL DEFAULT 'Non renseigné' (les 5 valeurs de la matrice de suivi ; « Non renseigné » = demandé sans réponse ou ligne créée sans réponse) · `Commentaire` VARCHAR(255) NULL · `DateDemande` DATETIME NULL · `DateReponse` DATETIME NULL · `Source` ENUM('JA','Saisie') NULL · `Id_Utilisateur` INT NULL (dernier demandeur tant qu'il n'y a pas de réponse, puis auteur de la saisie) · UNIQUE (`Id_CRA_Competition`, `Id_JA`).

**Migration** (dans `initTableConfiguration()`, EA98) d'une table créée avec l'ancien `Disponible` TINYINT(1) NULL (NULL / 1 / 0) : si `information_schema.COLUMNS.DATA_TYPE` de `Disponible` n'est pas `enum` → (a) `MODIFY` en VARCHAR(30) NULL ; (b) conversion — lignes dont `Commentaire` vaut « À confirmer » / « Disponible sous condition » (anciennes substitutions de l'import) → ce statut, commentaire vidé ; puis NULL → « Non renseigné », 1 → « Disponible », 0 → « Indisponible » ; contrôle qu'aucune valeur hors des 5 ne reste ; (c) `MODIFY` en ENUM définitif. La première étape en échec arrête la migration de la table (`error_log`) ; elle est rejouée sans risque au passage EA98 suivant (colonne toujours non-ENUM, l'UPDATE ne touche que les anciennes valeurs).

---

## EC75 – Statistiques CRA

**Fichier :** `CraStatsController` (CI4), vue `cra_stats_index.php`, route `cra-stats`
**Accès :** rôle « CRA Convoc » ou Administrateur (filtre `craconvocauth`) sur toutes les routes · lien depuis E009 (après EC73, avant EC72), retour vers `cra-convoc-menu`

### Objectif
Lister les JA disponibles avec leurs nombres de nominations comme JA principal et comme adjoint, pour répartir les désignations (par défaut, les JA les moins nommés en haut). Lecture seule.

### Définitions
- **JA disponible** : au moins une ligne `CRA_Dispo` avec `Disponible = 'Disponible'` (toutes compétitions) ; « À confirmer » / « Disponible sous condition » seuls ne suffisent pas.
- **Périmètre** : celui d'EC73 (`CraDesignationController::data()`) — au moins JA2 (`JA2`, `JA3`, `JAN` ou `JAI` à 1) et `ja.CodeDept` ∈ `getDeptActifs()` s'il y a des départements actifs.

### Colonnes
| Colonne | Calcul |
|---------|--------|
| NOM Prénom | `UPPER(ja.Nom)` + `ja.Prenom` |
| Dépt | `COALESCE(NULLIF(ja.CodeDept,''), SUBSTRING(NULLIF(ja.Id_Club,''),3,2))` |
| Club | `Club.Nom` (LEFT JOIN sur `ja.Id_Club`) |
| Grades | codes JA1/JA2/JA3/JAN/JAI à 1, séparés par un espace (« JA2 JA3 ») |
| Disponible | nombre de lignes `CRA_Dispo` « Disponible » ; détail « n à confirmer, n sous condition » en petit dessous et en infobulle |
| Nominations principal | nombre de lignes `CRA_Designation` `Role = 'JA'` (toutes compétitions) |
| Nominations adjoint | nombre de lignes `CRA_Designation` `Role = 'Adjoint'` (toutes compétitions) |
| Total | principal + adjoint |
| Dispos non utilisées | `max(0, Disponible − Total)` |
| Désigné ici | (seulement avec une compétition sélectionnée) rôle du JA sur cette compétition : JA / Adjoint / — |

Ligne de totaux en pied (somme principal, adjoint, total des lignes affichées).

### Filtres et tri (côté client, sauf la compétition)
- **Compétition** (select, défaut « Toutes les compétitions », options « n°X — dates — libellé ») : rechargement `cra-stats/data?competition=ID` ; ne garde que les JA « Disponible » pour cette compétition ; les compteurs restent les totaux sur toutes les compétitions ; colonne « Désigné ici » affichée.
- **Recherche** (nom / prénom, libellé au-dessus, aligné à droite, style EN11/EC71).
- Tri par clic sur les en-têtes, par défaut Total croissant puis NOM Prénom (départage par nom sur toutes les colonnes).
- Compteur `#lbl-count` centré = nombre de JA affichés.

### Routes
| Méthode | Route | Action |
|---------|-------|--------|
| GET | `cra-stats` | page (liste des compétitions pour le filtre) |
| GET | `cra-stats/data[?competition=ID]` | JSON `{ok, data}` : une ligne par JA disponible (requête unique préparée, agrégats `CRA_Dispo` et `CRA_Designation` en sous-requêtes `GROUP BY Id_JA`, pas de N+1) |

Échec AJAX ou session expirée : toast + message « rechargez la page » dans le tableau.

---

## EC72 – Degrés Juge-Arbitre

**Fichier :** `CraJugeArbitreController` (CI4), vue `cra_juge_arbitre_index.php`, route `cra-juge-arbitre`
**Accès :** rôle « CRA Convoc » ou Administrateur (filtre `craconvocauth`) sur toutes les routes · lien depuis E009, retour vers `cra-convoc-menu`

### Objectif
Gérer la table de référence des degrés de la filière juge-arbitre (table `JugeArbitre`, créée et seedée par `initTableConfiguration()` : JA1, JA2, JA3, JAN, JAI).

### Champs
| Champ | Colonne | Règle |
|-------|---------|-------|
| Code | `Code` CHAR(3) | Obligatoire, exactement 3 caractères, unique (insensible à la casse) · saisissable en création uniquement (lecture seule en modification, ignoré par le PUT) car il correspond à une colonne TINYINT de `ja` |
| Libellé | `Libelle` VARCHAR(26) | Obligatoire, 26 car. max |
| Description | `Description` TEXT | Obligatoire (textarea, pas de limite courte) |

### Interface
- Liste triable côté client (tri par défaut : code) : Code, Libellé, Description, corbeille
- Filtre côté client : recherche texte (code + libellé + description) ; compteur de lignes affichées
- Bouton « Nouveau degré » et clic sur une ligne → modale Bootstrap de saisie ; erreurs serveur affichées dans la modale
- Suppression : corbeille par ligne, `nijacConfirm(..., {type:'danger'})`

### Règles de suppression
- Si `ja` possède une colonne du même nom que le Code (recherche dans `information_schema.COLUMNS`) et qu'au moins un JA a cette colonne à 1 → refus, `{ok:false, msg}` indiquant le nombre de JA concernés
- Sinon (aucune colonne homonyme, ou aucun JA à 1) → suppression

### Actions AJAX
| Méthode | Route | Action |
|---------|-------|--------|
| GET | `cra-juge-arbitre/data` | Liste complète `{ok, data}` |
| POST | `cra-juge-arbitre` | Création (`code`, `libelle`, `description`) |
| PUT | `cra-juge-arbitre/{id}` | Modification (`libelle`, `description` ; `code` ignoré) |
| DELETE | `cra-juge-arbitre/{id}` | Suppression (voir règles ci-dessus) |

Toutes renvoient `{ok: bool, msg}` ; en cas d'erreur de validation `ok=false` et `msg` explicite.

---

## EN11 – Juges-Arbitres

**Fichier :** `Nominateur/jugearbitre.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Gérer la liste complète des Juges-Arbitres : import depuis fichier FFTT, consultation, modification, activation/désactivation.

### Champs d'une fiche JA
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Nom | Texte | Oui |
| Prénom | Texte | Oui |
| Grade | Texte (ex : Arbitre National) | Non |
| Club (Id_Club) | Sélecteur | Non |
| Code postal / Ville | Via `Id_LaPoste` | Non |
| Email | Email | Non |
| Téléphone | Texte | Non |
| JA1 (ex-`Actif` : JA actif 1er degré) | Booléen | Oui |
| JA2 / JA3 / JAN / JAI | Booléens `TINYINT(1) NOT NULL DEFAULT 0` (codes de la table de référence `JugeArbitre`, sans FK) | Non |

Modale Créer/Modifier : 5 cases à cocher **JA1, JA2, JA3, JAN, JAI** (infobulle = `JugeArbitre.Libelle`, passé par `index()` en `$gradesLibelles` Code → Libelle ; JA1 coché par défaut à la création). Clés POST/JS : `ja1`, `ja2`, `ja3`, `jan`, `jai` (0/1, toujours envoyées par la modale → les 5 colonnes réécrites en UPDATE comme en INSERT).
| Défiscalisation | Booléen | Non |
| Nationale | Booléen (Oui/Non), `DEFAULT 1` — **Oui par défaut** (case cochée à la création) | Non |
| Accepte d'arbitrer dans des départements voisins | Booléen (`ja.ArbitreAutresDepts`) | Non |
| Départements voisins souhaités | SET 14/27/50/61/76 (`ja.DeptsArbitrage`) | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne les JA filtrés par département (clés JSON des grades : `JA1`, `JA2`, `JA3`, `JAN`, `JAI`) |
| `recherche_laposte` | GET | Recherche de commune pour le sélecteur |
| `importer_excel` | POST | Import depuis fichier Excel FFTT (upsert par licence) |
| `clubs_par_dept` | GET | Liste des clubs du département |
| `maj_bdd` | POST | Créer ou modifier un JA. En **UPDATE**, le `SET` est construit ligne par ligne : `DateValidationFFTT`, `Defiscalisation`, `Nationale`, `NumCompteEBP` ne sont réécrits **que si la ligne postée porte la clé correspondante**. Les colonnes `JA1`…`JAI` suivent la même règle (clés `ja1`…`jai` ; l'import CSV n'envoie que celle du grade de la ligne). Seul l'import CSV FFTT (`importer_excel`) fournit `date_validation_fftt` ; seule la modale Créer/Modifier fournit `defiscalisation` / `nationale` / `num_compte_ebp`. Un import FFTT (CSV ou API) ne transmet pas ces trois-là et **préserve donc la valeur en base**. |

### Affichage de la grille
- Menu **Colonnes** (dropdown `<details>`, centré dans le bandeau entre le compteur « x/y JA » et le sélecteur Département) : une case par colonne pour l'afficher/masquer. Le sous-ensemble masqué est mémorisé dans `localStorage` (`nijac_en11_colonnes_cachees`), réappliqué à chaque rendu de la grille.
- La grille expose toutes les colonnes de la table `ja` **sauf `Note` et `Id_LaPoste`** (plus la colonne calculée `nom_club`).
- Grades : une colonne compacte par grade (**JA1, JA2, JA3, JAN, JAI**, en-têtes courts centrés avec infobulle `Libelle`, teinte commune pour les regrouper) — pastille verte ✓ si qualifié, « — » sinon ; triables (numérique).
- Filtre **Grade** (combobox du bandeau) : **Tous les JA** [défaut] = JA ayant au moins un grade (JA1/JA2/JA3/JAN/JAI) / **Tous** = aucun filtre (avec ou sans grade) / JA1 / JA2 / JA3 / JAN / JAI = JA ayant cette qualification / **Aucun grade** = JA sans aucun des 5 grades. Remplace l'ancien bouton « Actifs seulement » ; par défaut « Tous les JA » (JA sans aucun grade masqués). Le bouton **Tous** remet Grade à « Tous » et désactive le filtre Erreurs CP/Ville.
- Masquées par défaut (jeu initial `COLONNES_CACHEES_DEFAUT` quand la clé `localStorage` est absente) : `grade`, `date_validation_fftt`, `defiscalisation`, `nationale`, `num_compte_ebp`, `arbitre_autres_depts`, `depts_arbitrage`. Toutes réactivables depuis le menu.

### Import Excel
- Colonnes attendues : N° licence, Nom, Prénom, Grade, Club, Code postal, Ville
- Comportement : upsert sur le N° licence
- Normalisation automatique du nom de ville via la table `laposte`
- Qualifications (CSV `102_*.csv`, un fichier par grade : `102_*_JA1.csv`, `_JA2`, `_JA3`, `_JAN`…) : **chaque ligne n'affecte que la colonne de son grade**, indépendamment des autres grades. La colonne « Grade Arb/Ja » est convertie par `niveauxJaFftt()` (même mappage que l'import API) en `JA1`/`JA2`/`JA3`/`JAN`/`JAI` ; la ligne positionne uniquement cette colonne : 1 si « Inactivité » = `Actif` (insensible à la casse), 0 sinon. Un JA peut ainsi être inactif en JA1 et actif en JA2/JA3, et importer le fichier JAN après le fichier JA1 n'écrase pas JA1. Lignes sans grade JA reconnu (adjoints `JAAA`/`JAAE`, vide…) ignorées. Un même JA présent sur plusieurs lignes d'un fichier est fusionné par N° licence (à défaut Nom+Prénom) : les clés de grade se cumulent (dernière valeur par colonne), le reste de la fiche suit le grade le plus haut. `maj_bdd` n'écrit en UPDATE que les colonnes grade dont la clé (`ja1`/`ja2`/`ja3`/`jan`/`jai`) est présente dans la ligne (la modale Créer/Modifier envoie toujours les 5) ; en INSERT, colonnes absentes = 0. Les JA absents du fichier restent inchangés.
- L'import (CSV `102_*.csv` comme API FFTT) ne renseigne pas `Defiscalisation` / `Nationale` / `NumCompteEBP` : ces colonnes, gérées à la main dans la modale, ne sont jamais écrasées par un import (voir action `maj_bdd`). Un JA créé par un import prend `Nationale = 1` (défaut de la table). Migration unique (`initTableConfiguration()`, EA98) : défaut passé à 1 et tous les JA existants mis à Oui — gardée par le défaut, elle ne se rejoue pas (les Non saisis ensuite sont conservés).

### Importer les JA depuis l'API FFTT (par département)
- `fftt/reset-actif-dept` (`reinitialiserActifDept()`) remet d'abord `JA1`, `JA2`, `JA3`, `JAN`, `JAI` à 0 pour tous les JA du département : un JA non retrouvé n'a plus aucune qualification.
- Chaque licencié est lu via `xml_licence_b` ; son grade (champ `ja`, à défaut `arb`) est converti en qualifications par un mappage tolérant (`JA1`/`JA 1`/« 1er degré », `JA2`, `JA3`, `JAN`/« National », `JAI`/« International », plusieurs codes possibles). Seuls les licenciés ayant au moins une qualification sont retenus (AR exclus).
- Pour chaque JA importé (UPDATE ou INSERT) : chaque colonne vaut 1 si l'API indique la qualification, 0 sinon — pas de `JA1 = 1` par défaut, pas de cumul implicite (JA3 seul ne coche pas JA1/JA2). Le rapport (journal, sélection limitrophes) affiche ces qualifications.

### Règles
- Seuls les JA avec `JA1 = 1` sont proposés à la nomination (EN14)
- Le département d'un JA est déterminé par le code postal de sa salle principale de club
- Le sélecteur de département (filtre liste + import FFTT) propose, en plus des départements actifs (`getDeptActifs()`), un groupe **« Départements limitrophes »** alimenté par `getDepartementsLimitrophes()` — liste paramétrable via la clé `departements_limitrophes` en EA91 (par défaut `28,35,53,60,72,78,80,95`). Ce mécanisme est distinct de la règle 76→27 (`regles_departements`, voir EA91) : il permet de gérer des JA rattachés à des départements hors Normandie qui interviennent occasionnellement en Normandie, plutôt qu'une inclusion automatique entre deux départements normands.
- La modale Créer/Modifier reprend la case **« Accepte d'arbitrer dans un ou plusieurs départements voisins »** d'EN22 (case maîtresse + sélection des départements). La sélection est filtrée : seuls les départements **voisins de région du champ « Exerce dans »** sont proposés (`voisinsParDept` = `getLimitrophesRegion()` par département actif, injecté en JS ; `njaMajArbVoisins()` masque et décoche les cases non voisines, se recalcule au `change` du département et au chargement d'une fiche). Le corps de la fiche part par `maj_bdd`, puis, une fois l'enregistrement confirmé, la préférence est envoyée par un second POST vers l'action `sauvegarder_arbitrage_voisins` d'EN22 (endpoint partagé, `maj_bdd` n'écrit pas ces colonnes) — un échec de ce second appel n'annule pas l'enregistrement du reste de la fiche (toast d'avertissement).
- Colonnes `ja.ArbitreAutresDepts` / `ja.DeptsArbitrage` supposées présentes en base (créées en production ; plus d'auto-migration côté contrôleur).

---

## EN12 – Désidératas clubs

**Fichier :** `Nominateur/JA_R3R4.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Sélectionner les clubs ayant des équipes de la **Pré-Nationale à la R4M** et leur envoyer en masse le questionnaire de désidératas de saison (formulaire public EN18), en remplacement de l'envoi manuel du fichier Excel.

### Comportement
- Liste, uniquement pour les départements actifs de la région (`getDeptActifs()` — exclut les clubs Hors région), un club par ligne (regroupement de ses équipes dont `division.Ord` est entre 70 et 150), avec département, correspondant/email, nombre d'équipes concernées, badges des divisions concernées (ex. `R2M`, `R3M`), statut **Soumis** (si `club.DesiderataSaison` correspond à la saison configurée) ou **En attente**, et date du dernier envoi (`club.DesiderataEmailDate`)
- Lignes en couleurs alternées (une sur deux) pour la lisibilité
- Case à cocher par club, boutons **Tout sélectionner** / **Tout désélectionner**, filtres département / statut / recherche par nom de club
- Bouton **Visualiser le message** : ouvre une modale d'aperçu du modèle n°6 avec ses marqueurs résolus (données du premier club sélectionné, ou valeurs génériques si aucune sélection)
- Bouton **Envoyer le questionnaire** : envoie le modèle système `Id_Messagerie = 6` (créé/édité dans EA93) aux correspondants des clubs cochés ayant un email, avec un lien `desiderata-club?club=<Id_Club>-<MAC>` (jeton signé, `tokenDesiderataClub()`) généré pour chacun

### Marqueurs disponibles dans le message n°6
`{NOM_CLUB}`, `{CORR_NOM}`, `{URL_DESIDERATA}`, `{URL_LIGUE}`, `{YEAR_PHASE}`, `{UTI_NOM}`, `{UTI_PRENOM}`

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne les clubs de la région ayant des équipes PN à R4M, avec divisions concernées, statut de soumission et date de dernier envoi |
| `departements` | GET | Retourne les départements disponibles |
| `apercu` | GET | Retourne le sujet/corps du message n°6 avec marqueurs résolus (club optionnel en paramètre) |
| `envoyer` | POST | Envoie le message système n°6 aux clubs sélectionnés (soumis au rate-limiting), met à jour `club.DesiderataEmailDate` |

---

## EN13 – Disponibilités JA

**Fichier :** `Nominateur/disponibilites.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Consulter et modifier les disponibilités des JA par département et par journée.

### Interface
- Sélecteur de département : groupe **« Normandie »** (14, 27, 50, 61, 76) et groupe **« Départements limitrophes »** (liste de `getDepartementsLimitrophes()`, paramétrable via `departements_limitrophes` en EA91)
- Après choix d'un département de Normandie, cases à cocher **« Limitrophes »** listant ses départements voisins de la région (`getLimitrophesRegion()`, hors ceux déjà inclus d'office par `regles_departements`) ; boutons **« Tout cocher »** et **« Inverser »**. Cocher/décocher recharge la grille en ajoutant les JA de ces départements.
- Grille des JA du/des département(s) sélectionné(s) avec leur disponibilité par journée
- Clic sur un JA → ouvre `disponibilite_ja.php?id_ja=...` dans une nouvelle fenêtre pour la saisie détaillée par journée

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `ja_dept` | GET | Retourne les JA actifs du département `dept` (+ inclusions `regles_departements`) avec leurs disponibilités. Paramètre optionnel `extra` (codes séparés par virgules) : départements limitrophes cochés, filtrés côté serveur sur les voisins réels de `dept` dans la région |

### Règle département 76
La sélection du département 76 inclut automatiquement les JA du 27.

### Lien vers la fiche de disponibilité
Le lien généré depuis cet écran vers `disponibilite-ja` (EN22) porte l'`Id_JA` réel en clair (`?id_ja=...`) — **pas** de token obfusqué : ni le fichier legacy `disponibilites.php` ni son portage CI4 n'importent `Classes/Obfuscator.php`. Seul le lien généré depuis EN11 (Juge-Arbitre, action « token ») utilise un token obfusqué (`?ja=TOKEN`) vers ce même écran.

---

## EN14 – Nomination JA

**Fichier :** `Nominateur/nomination.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Affecter les JA disponibles aux rencontres de la saison en appliquant les règles métier de nomination.

### Interface
- Sélecteur de journée
- Liste des rencontres de la journée avec statut de nomination
- Bouton **Tri** : ordre de la liste des rencontres, basculable entre **club recevant** (défaut : ordre alphabétique du nom du club recevant, toutes ses rencontres à la suite sous un en-tête de groupe « nom du club — n rencontres », puis division au sein du club ; pas d'en-têtes avec « Non attribuées d'abord ») et **division** (ordre historique : `division.Ord`, poule). Tri fait côté client sur `NomClubDom` / `IdClubDom` / `DivisionOrd` renvoyés par `rencontres_journee` ; choix mémorisé dans le navigateur (`localStorage`) ; le bouton « Non attribuées d'abord » se superpose à cet ordre (tri stable)
- Pour chaque rencontre : liste des JA candidats triés par priorité
- **Repère de convocation** (unique, 3 états — « convocation validée » = le JA a accusé réception dans EN21, `Valide` n'intervient pas), placé à droite de la carte, **devant l'icône d'attribution** `.renc-ico` : ordre `[.renc-corps][.renc-etat : repère + renc-ico]` (`.renc-etat` flex, gap .35rem, centré verticalement) ; le nom du JA reste seul sur sa ligne `.renc-ja`. Emplacement de largeur fixe (`.picto-convoc`, 1.6rem) laissé vide sur les rencontres sans JA, pour garder les icônes alignées d'une carte à l'autre. `pictoConvocation()` : V vert `bi-check-lg` (coche épaisse, #198754, ≈ 1.2rem, épaissie par `-webkit-text-stroke`, sans halo, classe `.picto-valide`) — volontairement distinct de `renc-ico` (personne + coche indigo, présente pour tout JA nommé), infobulle et `aria-label` (`role="img"`) « Convocation validée — accusé de réception le jj/mm/aaaa à hh:mm » si `AccuseReception` est renseigné ; `bi-hourglass-split` orange, infobulle « Convocation non validée — en attente d'accusé de réception » si la convocation est envoyée (`EmailEnvoye = 1`) sans accusé ; « — » gris, infobulle « Convocation pas encore envoyée » sinon. `rencontres_journee` renvoie `AccuseReception` (`NULL AS AccuseReception` tant que la colonne n'existe pas, `nominationAAccuseReception()` — EN14 fonctionne avant EA98, repère alors « — » ou sablier, filtre « Validées » vide). Mini-légende statique (`.legende-convoc`) après le groupe Toutes / Validées / Non validées : « ✔ validée (accusé reçu) · ⏳ en attente d'accusé · — non envoyée », mêmes icônes colorées
- **Filtre « Accusé de réception »** (barre de sélection, à côté de la journée) : Tous / Reçu / Envoyé sans accusé / Pas encore envoyé (`''`, `recu`, `attente`, `nonenvoye`) — filtre côté client (`etatAccuse()` / `passeFiltreAccuse()`), toute valeur autre que « Tous » masque les rencontres sans JA ; conservé au changement de journée ; le compteur de la colonne ajoute « · N affichées » quand le filtre est actif. **Envoyer convocations** n'envoie que les rencontres cochées **et affichées** (`idsEnvoiAffiches()`, bouton désactivé si aucune) ; la feuille de pointage PDF reste sur toutes les rencontres de la journée, indépendamment du filtre
- **Filtre validation** (groupe de boutons dans la barre de sélection, infobulle « Validée = le JA a accusé réception de sa convocation ») : Toutes / Validées (n) / Non validées (n) (`''`, `valide`, `nonvalide`) — Validées = `AccuseReception` renseigné, Non validées = `AccuseReception` NULL (envoyée ou non) ; compteurs calculés sur la journée à chaque affichage — filtre côté client (`etatValidation()` / `passeFiltreValidation()`, dérivés de `etatAccuse()` : même base que le select, donc toujours cohérents ; aucune dépendance à `Valide`), combiné en ET avec le filtre « Accusé de réception » ; toute valeur autre que « Toutes » masque les rencontres sans JA ; conservé au changement de journée ; pris en compte par « · N affichées » et par **Envoyer convocations** (cochées **et affichées**) ; la feuille de pointage PDF reste sur toute la journée
- Boutons : Affecter, Retirer, Envoyer convocations, Feuille de pointage (PDF)

### Feuille de pointage (PDF)
- Bouton « Feuille de pointage (PDF) » de la barre d'actions (désactivé, avec infobulle, quand la journée n'a aucune rencontre) : PDF A4 paysage généré **côté navigateur** avec `asset/js/jspdf.umd.min.js` (même lib que l'attestation ED, tableau dessiné à la main — pas de plugin autotable), téléchargé sous `feuille-pointage-J{journée}-{date}.pdf`. Lecture seule : aucun appel serveur, aucune écriture.
- Contenu : **toutes** les rencontres de la journée affichée (pas seulement les cochées pour l'envoi), JA nommé = état courant de l'écran (y compris les affectations faites dans la session).
- En-tête (page 1) : « Feuille de pointage — Journée n° N du JJ/MM/AAAA », départements du nominateur (`getDepartementsAutorises()`, code + nom), date d'édition. Pied de chaque page : date d'édition + « Page x / y ».
- Colonnes : Division (code court), Club (club recevant), Équipe domicile, Équipe extérieure, JA (NOM Prénom), Tél JA (10 chiffres → `06.12.34.56.78`, sinon tel quel), Email JA, Pointé (carré vide dessiné). Police Helvetica 8,5 pt, retour à la ligne dans les cellules (`splitTextToSize`), en-tête gras blanc sur fond bleu `#1a3a6b` répété sur chaque page, une ligne sur deux grisée (`#E9ECEF`).
- Tri : NOM puis Prénom du JA (`localeCompare('fr', {sensitivity:'base'})`, insensible casse/accents), puis division / club ; rencontres sans JA à la fin (colonnes JA vides), triées par division (`division.Ord`) puis club.
- Données : `rencontres_journee` renvoie `NomJa`, `PrenomJa`, `TelJa`, `EmailJa` (`ja.Telephone` / `ja.Email` du JA nommé) ; `candidats_journee` renvoie `Telephone` / `Email` pour un JA affecté pendant la session.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `journees` | GET | Retourne les journées disponibles pour le département |
| `rencontres_journee` | GET | Retourne les rencontres d'une journée avec nominations |
| `candidats_journee` | GET | Retourne les JA candidats de la journée : JA actifs disponibles pour au moins une rencontre du jour du périmètre (champ `RencontresOk` = liste des rencontres où le JA est disponible selon `sqlDispoRencontre()`, la règle revérifiée par `affecter_ja` ; le client ne propose le JA que pour ces rencontres) rattachés à un département du nominateur — soit par le domicile (`LEFT(Cp,2)`), soit par `ja.CodeDept` — **ou** JA d'un autre département ayant coché « accepte d'arbitrer dans un département voisin » (`ja.ArbitreAutresDepts = 1` et un département du nominateur présent dans `ja.DeptsArbitrage`, testé par `FIND_IN_SET`) — voir EN22/EN11. Chaque ligne porte `HorsDept` (0/1) et `CodeDept` ; côté client, un filtre **« Autres dépts »** génère une case par département distinct des candidats `HorsDept = 1` (dépt = `LEFT(Cp,2)` sinon `CodeDept`) — un tel JA n'est affiché que si la case de son département est cochée (toutes décochées par défaut), boutons Tout cocher / Inverser visibles à partir de 2 départements, badge « Autre dépt ». Tri final côté client. |
| `affecter_ja` | POST | Nomme un JA sur une rencontre et valide directement la nomination (`Valide = 1`) — plus d'étape de validation séparée. **Revérification serveur à l'instant de la nomination** (`nommer()` / `verifierEtNommer()`, la liste des candidats a pu vieillir), tout relu en base dans une transaction, après verrous `SELECT … FOR UPDATE` sur la rencontre, le JA, ses lignes `disponible` (rencontre + journée) et la nomination existante : rencontre du périmètre ; pas de nomination concurrente (POST `id_ja_actuel` = JA affiché nommé, `''` = aucun : si un **autre** JA est nommé entre-temps → refus « Un autre JA vient d'être nommé sur cette rencontre. », la réaffectation voulue — clic « Affecter » alors que la page montrait ce JA — passe ; paramètre absent = pas de contrôle) ; JA actif (`JA1 = 1`, sinon « Ce JA n'est plus actif. ») ; JA dans le périmètre (mêmes conditions que `candidats_journee`, `sqlPerimetreJa()` partagé : domicile/CodeDept ou `ArbitreAutresDepts`/`DeptsArbitrage`) ; **disponibilité actuelle** selon la règle unique `sqlDispoRencontre()` partagée avec la liste : une réponse `'O'` sur la rencontre ou la journée **et** aucune réponse `'N'` ni sur la rencontre ni sur la journée (sans réponse / `'P'` seul → refus ; `'N'` → « Ce JA n'est plus disponible pour cette rencontre/journée (il a répondu « non »). ») ; limite de 2 nominations par JA et par jour, même club recevant (« Ce JA a déjà 2 nominations ce jour. »). Refus → `{ok:false, err, rafraichir:true}` : la vue affiche le toast (message échappé) et recharge rencontres + candidats en gardant la rencontre sélectionnée (et la sélection d'envoi). Aucun email. |
| `retirer_ja` | POST | Retire la nomination d'un JA, et sa validation avec elle (`DELETE FROM nomination WHERE Id_Rencontre = ?`) |
| `envoyer_convocations` | POST | Envoie les emails de convocation aux JA validés (`ids` = rencontres cochées) ; `copie_clubs=1` → copie sans lien aux clubs (voir ci-dessous). Réponse : `envoyes`, `erreurs`, `liens` (chaque ligne porte `copie` = compte-rendu texte de la copie), `copies` = `{envoyees, echecs, sans_destinataire}` ou `null` si case décochée |
| `demander-ja-club` | POST | Rencontre en arbitrage club : bouton « Envoyer la demande au club » du panneau d'édition du message n°7 (sujet/corps modifiables) — **envoi immédiat au correspondant du club, sans fenêtre de confirmation** (référent `Club.RefMail` en Cc) ; bouton désactivé pendant l'appel (et après succès), résultat dans le panneau + toast « Demande envoyée à … (N destinataires) » (messages serveur en texte brut, échappés par `escHtml()` à l'affichage car `nijacToast` rend en HTML). Cœur d'envoi `envoyerDemandeJaClub()` partagé avec EN28 ; refus serveur inchangés (périmètre, JA déjà désigné, pas en arbitrage club, club sans email) |

### Modèle de convocation (message système n°3 « Convocation »)
- Résolu par `resoudreModeleMessagerie($pdo, 3, …)` (copie personnelle du nominateur retrouvée par le **Sujet**, sinon le système) ; Sujet inchangé : « Convocation JA du {DATE} à {HEURE} à {NOM_CLUB} ».
- Corps par défaut **en HTML** (`modeleHtmlConvocationJa()`, config/app_config.php, nowdoc) : e-mail à tableaux et styles en ligne, dans cet ordre : en-tête Ligue ; « Bonjour {PRENOM}, » + « Nous avons l'avantage de vous informer que vous êtes désigné(e) pour diriger la rencontre suivante… » ; tableau bleu de synthèse ({DOM} à {EXT}, Date, Compétition, **Juge-arbitre** `{PRENOM} {NOM}`, Club recevant, Adresse, Correspondant) ; encadré orange **« Actions attendues : »** à 2 puces (« Confirmer votre désignation en cliquant sur le bouton ci-dessous ou Signaler au plus vite toute indisponibilité ou difficulté particulière. » / « Conserver les justificatifs nécessaires à l'établissement de vos frais et compléter votre convocation dans un délai de 5 jours après la rencontre. ») ; bouton « Consulter et confirmer ma convocation » vers `{URL_CONVOCATION_JA}` = EN21 (accusé de réception) + lien de secours ; texte de fin « Nous vous remercions pour votre investissement au service du tennis de table normand et vous souhaitons une excellente rencontre. » ; signature `{UTI_PRENOM} {UTI_NOM}` ; pied de page. Envoyé en HTML car `strip_tags($corps) !== $corps`.
- Marqueurs : `{PRENOM}` `{NOM}` `{SEXE}` `{DOM}` `{EXT}` `{DATE_LONGUE}` `{HEURE}` `{JOURNEE}` `{DIVISION}` `{POULE}` `{NOM_CLUB}` `{SALLE_NOM}` `{SALLE_ADRESSE}` `{SALLE_CP}` `{SALLE_VILLE}` `{CORR_NOM}` `{CORR_TEL}` `{CORR_EMAIL}` `{URL_CONVOCATION_JA}` `{UTI_PRENOM}` `{UTI_NOM}` `{URL_LIGUE}` (tous fournis par `construireMarqueursMessage()`).
- Copie aux clubs : n'est plus dérivée du n°3 (message dédié « Convocation clubs », voir ci-dessous) ; le n°3 n'a pas à rester compatible avec un retrait de liens.
- Mise à niveau des bases existantes par `initTableConfiguration()` (EA98) : le corps du n°3 est remplacé par le HTML **seulement** s'il est encore l'un des anciens textes brut par défaut (`corpsConvocationJaMigrable()` : comparaison après trim et `\r\n`→`\n` avec les 3 variantes historiques de `ancienCorpsConvocationJa()` et les versions HTML précédentes, figées en dur dans `ancienneVersionHtmlConvocationJa()` : v1 « copiez ce lien : », v2 « valider ce lien : » + « Ci-joint… » + bloc IMPORTANT sous le bouton). Corps personnalisé en EA93 ou déjà à jour → inchangé (ligne `error_log` « message n°3 personnalisé : modèle HTML non appliqué »). Sujet, Cc/ReplyTo et copies personnelles des nominateurs ne sont jamais modifiés.

### Copie de la convocation aux clubs (sans lien)
- Case « Envoyer une copie (sans lien) aux clubs » ajoutée à la fenêtre de confirmation de l'envoi, **cochée par défaut**, transmise en POST (`copie_clubs`).
- Destinataires, pour chaque rencontre convoquée : correspondant (`Club.CorNom` / `CorEmail`) et référent (`Club.RefNom` / `RefMail`) du club **recevant et** du club **visiteur** (`equipe.Id_Club` de `Id_EquipeDom` / `Id_EquipeExt`) — adresses valides seulement, dédoublonnées sans casse (une adresse ne reçoit qu'une copie par rencontre), adresse du JA exclue (`destinatairesCopieClubs()`, `config/app_config.php`). Un seul email par rencontre, tous les destinataires en « À ».
- Envoyée **après** l'envoi réussi au JA uniquement : pas de copie si le JA n'a pas d'email ou si sa convocation échoue (signalé dans le compte-rendu). Jamais bloquante : un échec de copie ne remet pas en cause `EmailEnvoye = 1`.
- Modèle dédié : message système **« Convocation clubs »** (`Type = 'Convocation clubs'`, constante `TYPE_MESSAGE_COPIE_CONVOCATION_CLUBS`, `Id_Utilisateur = NULL`, `Cc = 0`, `ReplyTo = 1`), résolu par `resoudreModeleMessagerieParType()` (copie perso du nominateur prioritaire, sinon système). Sujet utilisé tel quel (`Copie – Convocation JA du {DATE} à {HEURE} à {NOM_CLUB}` : préfixe inclus, pas de doublon) ; corps HTML `modeleHtmlCopieConvocationClubs()` (même charte que le n°3 : titre « Copie de convocation — Juge-Arbitre », phrase « Ceci est une copie, pour information, de la convocation adressée à {PRENOM} {NOM} (juge-arbitre) », encadré de la rencontre, JA désigné suivi de ses coordonnées `{COORDONNEES_JA}`, signature `{UTI_PRENOM} {UTI_NOM}`, pied de page) — aucun lien ni consigne destinés au JA. Coordonnées du JA (`ja.Telephone`, `ja.Email`, sélectionnés par la requête d'EN14) : `{COORDONNEES_JA}` = bloc HTML déjà échappé (`htmlspecialchars`) sous le nom, « Téléphone : 06.12.34.56.78 · Email : <lien mailto> » ; une partie vide est omise (pas de libellé orphelin), aucune des deux → chaîne vide (aucune ligne). `{TEL_JA}` (10 chiffres → 06.12.34.56.78, sinon tel quel) et `{EMAIL_JA}` donnent les valeurs brutes (non échappées, comme les autres marqueurs texte). Mise à niveau : si le corps système en base est encore identique (trim, `\r\n`→`\n`) à une ancienne version par défaut (`ancienCorpsCopieConvocationClubs()`), `assurerModeleCopieConvocationClubs()` le remplace par la version courante ; corps personnalisé en EA93 laissé tel quel (`error_log`), copies perso jamais touchées. Marqueurs : `{PRENOM}` `{NOM}` `{COORDONNEES_JA}` `{TEL_JA}` `{EMAIL_JA}` `{SEXE}` `{DOM}` `{EXT}` `{DATE}` `{DATE_LONGUE}` `{HEURE}` `{JOURNEE}` `{DIVISION}` `{POULE}` `{NOM_CLUB}` `{SALLE_NOM}` `{SALLE_ADRESSE}` `{SALLE_CP}` `{SALLE_VILLE}` `{UTI_PRENOM}` `{UTI_NOM}` `{URL_LIGUE}`. Sujet et corps envoyés **tels quels** (aucune ligne ni préfixe ajouté par le code, aucun filtrage) : un lien personnel du JA (`{URL_CONVOCATION_JA}`, `{LIEN_CONVOCATION}`…) ajouté en EA93 serait envoyé aux clubs — responsabilité de l'administrateur, prévenu par l'avertissement non bloquant d'EA93 ; Cc du nominateur seulement si le flag `Cc` du message est actif. Amorcé par `assurerModeleCopieConvocationClubs()` depuis `initTableConfiguration()` (EA98), idempotent (créé seulement si aucun message système de ce Type, jamais d'écrasement) ; éditable en EA93.
- **Repli** (message dédié absent ou vide, EA98 pas encore chargé) : modèle codé en dur — sujet `sujetParDefautCopieConvocationClubs()` (« Copie – Convocation JA du {DATE} à {HEURE} à {NOM_CLUB} », le même que l'amorçage), corps `modeleHtmlCopieConvocationClubs()`, `Cc = 0`, `ReplyTo = 1`. Le message n°3 n'est plus jamais utilisé pour la copie aux clubs.
- Reply-To du nominateur selon le flag `ReplyTo` du modèle ; Cc selon le flag `Cc` (0 par défaut : le nominateur est déjà en Cc de la convocation du JA si le flag `Cc` du n°3 est actif).
- Limite d'envoi : `checkRateLimit(nb destinataires)` avant chaque copie (dépassement → copie comptée en échec), `enregistrerEnvois()` après envoi.
- Mode Développement : `getEmailDestinataire()` ramène tous les destinataires sur `email_developpement` (dédoublonnés par PHPMailer) → **un** mail de test par rencontre, sujet préfixé `[DEV → adresses réelles]`.
- Compte-rendu (fenêtre « Convocations envoyées ») : total copies envoyées / en échec / sans destinataire, et sous chaque rencontre le statut de sa copie.

### Modèle de données (`nomination` → `disponible`)
Depuis la migration décrite dans le commit *« modification dans la table nomination de id_ja par id_disponible »*, la table `nomination` ne référence plus directement `ja.Id_JA` mais **`disponible.Id_Disponible`** (`nomination.Id_Disponible`). Le JA nominé s'obtient par jointure `nomination → disponible → ja`. Une contrainte d'unicité `uq_nomination_rencontre` sur `nomination.Id_Rencontre` garantit qu'**une rencontre ne peut avoir qu'une seule nomination**.

**Règle « nomination = valide d'office »** : si un JA est nommé sur une rencontre, elle devient automatiquement valide — plus de validation manuelle (bouton « Valider les nominations » supprimé). Chaque écriture qui crée une nomination ou change son JA pose `Valide = 1` explicitement : EN14 (`affecterNomination()`), EN25 (`ArbitreClubController::enregistrer`, arbitrage club), import des rencontres (`ImportRencontresController`), EN28 (`suivi-nomination/modifier` et `suivi-nomination/saisir`). EN23/EN24 ne créent ni ne modifient de nomination. `nomination.Valide` a `DEFAULT 1` ; migration unique dans `initTableConfiguration()` (EA98) : si le défaut n'est pas déjà `1`, `ALTER TABLE nomination MODIFY Valide … DEFAULT 1` (type et nullabilité conservés) puis `UPDATE nomination SET Valide = 1 WHERE Valide = 0` — le défaut sert de garde, l'UPDATE ne se rejoue pas. `EmailEnvoye` n'est pas touché, aucun email envoyé. Retirer un JA (`retirer_ja`) supprime la nomination : il n'existe plus d'état « nomination invalide ».

Deux fonctions internes portent cette logique dans `nomination.php` :
- `resoudreDisponible($pdo, $idJa, $idRenc, $dateRenc)` : trouve/crée la ligne `disponible` à utiliser — priorité à une réponse précise sur la rencontre (`Reponse='O'`), sinon une disponibilité « toute la journée » (`Id_Rencontre IS NULL`) qu'elle matérialise en ligne précise, sinon retourne `null` (JA non disponible → nomination refusée)
- `affecterNomination($pdo, $idRenc, $idDispo)` : crée la nomination si absente ; si un autre JA était déjà nominé, réinitialise `Peage`, `Kilometre`, `RapportAccueil`, `RapportEquipements`, `DateSaisie` et `AccuseReception = NULL` (l'accusé de l'ancien JA n'est pas hérité ; seulement si la colonne existe) ; même JA réaffecté : accusé conservé

### Règles métier de nomination (état CI4 — `NominationController` + `nomination_index.php`)
1. **Exclusion club** : un JA est exclu si son club est le **club recevant** de la rencontre — **sauf en R3M/R4M**, où il peut arbitrer une rencontre de son propre club (badge « Son club »). Aucun contrôle sur le club visiteur.
2. **Aucune limite par club / par phase** : le nombre d'arbitrages d'un JA pour un même club n'est pas plafonné.
3. **Max 2 nominations par JA et par date, sur le même club recevant** : refus au-delà de 2 (`affecterJa`, revérifié serveur) et refus d'une nomination si le JA est déjà nommé ce jour-là sur une rencontre d'un **autre** club recevant (`equipe.Id_Club` de l'équipe domicile) ; le JA n'est alors plus proposé dans la liste des candidats. La 2ᵉ nomination (même club) est décidée manuellement par le nominateur. Même règle appliquée au changement de JA depuis EN28.
4. **Unicité par rencontre** : une rencontre ne peut avoir qu'un seul JA nominé (contrainte `uq_nomination_rencontre`)
5. **Priorité disponibilité déclarée** : les rencontres choisies par le JA dans ses disponibilités sont prioritaires
6. **Proximité géographique** : en cas d'égalité, la rencontre la plus proche du domicile du JA est privilégiée
7. **Équité** : priorité au JA ayant le moins d'arbitrages validés sur la phase en cours

### Bug corrigé lors du portage CI4 (contrairement à la politique habituelle de préservation)
Le fichier legacy `Nominateur/nomination.php` testait `!$journee` (avec `$journee` casté en `int`) pour valider le paramètre `journee` dans `rencontres_journee`, `valider_nominations` et `envoyer_convocations` — un test `!0` étant vrai en PHP, `Journee = 0` était traité comme "paramètre manquant". Comme **toutes** les lignes de `rencontre` ont actuellement `Journee = 0` (numérotation jamais renseignée à l'import FFTT), ce bug rendait l'intégralité de l'écran Nomination inutilisable en pratique — contrairement à EN22 où seules deux actions annexes étaient touchées. Corrigé dans `NominationController` (CI4) en distinguant "paramètre absent" (`null`/chaîne vide) de "paramètre valant 0" avant le cast en entier.
8. **Double rencontre en salle** : *(supprimé au portage CI4)* — plus d'affectation automatique « même salle » ; la 2ᵉ nomination d'un JA sur une journée est entièrement manuelle

---

## EN15 – Centre d'envoi

**Fichier :** `Nominateur/centrenvoye.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Envoyer les messages aux JA actifs du département (convocations, rappels, annulations, informations).
JA actifs = `ja.JA1 = 1`, seul critère dans tous les onglets (aucune condition sur la colonne héritée `ja.Grade`).

### Interface
- Sélecteur de journée
- Liste des JA avec statut d'envoi (envoyé / en attente)
- Aperçu du message avant envoi
- Envoi global ou individuel

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste_journees` | GET | Retourne les journées ayant des nominations validées |
| `liste_ja` | GET | Retourne les JA à convoquer pour une journée |
| `envoyer` | POST | Envoie les convocations à tous les JA de la journée |
| `apercu_email` | POST | Retourne le rendu HTML d'un email avant envoi |
| `envoyer_un` | POST | Envoie la convocation à un seul JA |

Erreurs de chargement explicites (`erreurChargement()`) : si `journees` ou `ja` (tous onglets) échoue, toast rouge « Chargement impossible (journées|JA) — détail » (serveur injoignable, session expirée, HTTP 4xx/5xx, `msg` d'un JSON `ok:false`), même détail en rouge sous le combo des journées / dans la liste des JA, et trace `console.error('[EN15]', …)` (500 premiers caractères de la réponse) pour le diagnostic. Exception PHP/SQL côté serveur (`repondreErreur()`) : journalisée en entier (`log_message('error')`, avec une référence `AAAAMMJJ-HHMMSS`) et renvoyée en HTTP 200 `{ok:false, err}` — l'administrateur voit le type, le message (300 car.) et fichier:ligne relatif, les autres rôles un message générique avec la référence à transmettre.

### Règle email
- `EmailEnvoye` passe à `1` après envoi réussi
- En mode `Developpement`, tous les emails sont redirigés vers `email_developpement`
- Le lien dans l'email vers la convocation officielle est tokenisé (Obfuscator)

---

## ED55 – Comptes EBP des JA

**Fichier :** `Nominateur/compta.php`  
**Accès :** Défiscalisateur et Administrateur (menu E005, filtre `defiscauth`)

### Objectif
Renseigner le champ `ja.NumCompteEBP` (n° de compte fournisseur dans le logiciel comptable EBP) pour les JA du périmètre de l'utilisateur (`getDepartementsAutorises()` sur `ja.CodeDept`).

### Interface
Motif partagé **liste + panneau d'édition** (`asset/css/nijac-liste-edit.css`).

- **Volet liste** (gauche) : table triable des JA du périmètre. Bandeau de filtres au style comboboxes « label en encoche » de EN11 (`#menu-strip` + `.combo-field`) : recherche nom/prénom, **Compte EBP** (tous / sans compte [défaut] / avec compte), **Défisc.** (tous / oui / non), **JA1** (tous [défaut] / oui / non), bouton de réinitialisation ; badge `affichés / total`.
  Colonnes : Nom, Prénom, **JA1** (Oui/Non), **Défisc.** (Oui/Non), puis — **uniquement pour les JA ayant demandé la défiscalisation** (`ja.Defiscalisation = 1`) — **Km total** (somme de `nomination.Kilometre` sur toutes les nominations du JA en base), **CV** (`ja.PuissanceFiscale`), **Énergie** (`ja.VehiculeElectrique` → `Therm.` / `Élec.`) — puis N° compte EBP. Lignes des JA inactifs grisées.
- **Volet édition** (droite) : sur sélection d'une ligne, Nom / Prénom / JA1 en lecture seule, rappel défiscalisation (`n CV · thermique|électrique · n km cumulés`) le cas échéant, champ **N° de compte EBP** (vide = efface) + bouton **Enregistrer**.
- **Importer CSV** (bouton du bandeau liste) : fichier `.csv`, deux colonnes — « nom + prénom » et « n° de compte EBP » — dans un **ordre indifférent** (la colonne 100 % chiffres est prise pour le compte, l'autre pour le nom), séparateur `;` ou `,` ; lignes d'en-tête / sous-totaux (0 ou 2 colonnes numériques) ignorées. Compte-rendu dans un encart, **lignes sans correspondance en tête** : chaque nom sans correspondance (et chaque cas ambigu) est cliquable → filtre la liste sur le nom de famille pour retrouver et compléter le JA manuellement.
- **Exporter CSV** (bouton du bandeau liste) : télécharge `comptes_ebp_ja.csv` — uniquement les JA défiscalisés avec un kilométrage arbitré > 0.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `ja-sans-compte` | GET | Liste des JA du périmètre sans `NumCompteEBP` (ou tous avec `?tous=1`) ; inclut `Defiscalisation`, `PuissanceFiscale`, `VehiculeElectrique` et `KmTotal` (SUM des `nomination.Kilometre` du JA) |
| `maj-compte` | POST | Mise à jour manuelle du `NumCompteEBP` d'un JA (`id_ja`, `num_compte` ; vide = efface) |
| `import-ebp` | POST | Import CSV : rapproche chaque ligne avec un JA du périmètre sur le nom normalisé (sans accents, casse et espaces multiples ignorés, `NOM Prénom` et `Prénom NOM` testés) et renseigne le compte ; renvoie le détail ligne à ligne (`maj` / `inchange` / `introuvable` / `ambigu`) |
| `export-csv` | GET | Renvoie le CSV `compte;nom` (en-tête `compte;nom`, `NOM` en majuscules + `Prénom`) des JA du périmètre **défiscalisés** (`Defiscalisation = 1`) ayant un **kilométrage arbitré > 0** (SUM `nomination.Kilometre`) — `NumCompteEBP` éventuellement vide ; réimportable tel quel ; téléchargement déclenché côté client, fichier `comptes_ebp_ja.csv` |

### Rapprochement des noms (`import-ebp`)
- Normalisation : accents retirés, majuscules, tout caractère non alphanumérique → espace simple.
- Index construit sur `NOM Prénom` **et** `Prénom NOM` de chaque JA du périmètre.
- 1 seul JA correspondant → `UPDATE` (ou `inchange` si déjà la même valeur) ; 0 → `introuvable` ; ≥ 2 → `ambigu` (non modifié).
- Les espaces ne sont pas supprimés (« LE ROY » ≠ « LEROY ») : un écart de graphie (ex. « JEANCLAUDE » collé côté EBP vs « Jean-Claude » en base) reste `introuvable`, à corriger via la saisie manuelle.

---

## EN17 – Statistiques JA

**Fichier :** `Nominateur/stats_ja.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Rapport agrégé, en lecture seule, des arbitrages et frais par JA pour une phase d'une saison.

### Interface
- Filtres **Phase** (`1` / `2`) et **Saison** (liste des 7 dernières années, libellé `AAAA‑AAAA+1`), bouton **Afficher** ; défaut = phase en cours (ou phase 2 de la saison écoulée pendant la coupure estivale)
- Graphes par département (rencontres, JA actifs, couverture, charge, barres par journée)
- Tableau « Juge-Arbitre » (par JA : grade, club, arbitrages, arbitrages Club, km, montant km, péages, indemnité, total frais), en-têtes centrés, triable par colonne, avec ligne de totaux ; mêmes données que l'**Export CSV**
- Bouton **Export CSV** placé juste avant le tableau (visible avec lui) ; bouton **Imprimer** dans la barre de filtres (vue imprimable via CSS `@media print`)

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `donnees` | GET (`phase`, `annee`) | Retourne, par JA, le nombre d'arbitrages et les totaux km / péages / indemnité / frais sur la phase choisie — alimente le tableau par JA |
| `export_csv` | GET (`phase`, `annee`) | Télécharge un CSV (BOM UTF-8, séparateur `;`) `stats_ja_saison{annee}_phase{phase}.csv` |

### Résolution (phase, saison) → bornes de dates
- Bornes MM/JJ lues dans la configuration EA91 (`phase1_debut`/`phase1_fin`/`phase2_debut`/`phase2_fin`)
- Année civile d'un mois : `≥ juillet` → 1re année de la saison, sinon année suivante (ex. phase 1 de la saison 2026 = `2026-09-01` → `2027-01-31` ; phase 2 = `2027-02-01` → `2027-06-30`)
- La date de fin est bornée à aujourd'hui ; si la date de début est future, la phase « n'a pas encore commencé » (message d'erreur, aucune ligne)

### Calcul (par JA, sur les nominations `Valide = 1` de la période ayant une `DateSaisie` renseignée — non NULL et ≠ `0000-00-00`, tableau, totaux et export CSV)
- **Arbitrages Club exclus des frais** : les nominations sur une rencontre `ArbitrageCRA = 0` comptent dans `nb_arbitrages` mais ne donnent ni indemnité, ni péage, ni remboursement kilométrique (seules les rencontres `ArbitrageCRA = 1` sont valorisées ci-dessous)
- `nb_arbitrages_club` = nombre de nominations sur des rencontres `ArbitrageCRA = 0` (colonne « Arbitrages Club », incluse dans `nb_arbitrages`)
- `total_km` = `SUM(Kilometre)`, `total_peages` = `SUM(Peage)` (rencontres `ArbitrageCRA = 1` uniquement)
- `montant_km` = `total_km × frais_kilometrique` (colonne « Montant km (€) »)
- `total_indemnite` = `COUNT(nominations ArbitrageCRA = 1) × indemnite_forfaitaire`
- `total_frais` = `total_km × frais_kilometrique + total_peages + total_indemnite`
- Utilise les clés de configuration `indemnite_forfaitaire` et `frais_kilometrique` (EA91) pour valoriser les frais, en rapport de synthèse par JA

---

## EN18 – Désidératas club

**Fichier :** `Nominateur/desiderata_club.php`  
**Accès :** Public, sans authentification — page protégée par un jeton signé `?club=<Id_Club>-<MAC>` (`tokenDesiderataClub()`, HMAC-SHA256 — le numéro de club seul est refusé)

### Objectif
Remplace le questionnaire Excel envoyé par mail aux clubs en début de saison. Permet à un club (via le lien envoyé depuis EN12) de renseigner en ligne, pour la saison en cours, les coordonnées de son correspondant, sa salle et les désidératas de ses équipes de la **Pré-Nationale à la R4M**.

### Contenu du formulaire
- **Correspondant** : nom/prénom, téléphone, email (`club.CorNom`, `club.CorTelephone`, `club.CorEmail`)
- **Salle** : nom, adresse, téléphone (`salle.Telephone`), nombre maximum d'aires de jeu (`club.NbAiresJeu`) — la salle principale (`EstPrincipale = 1`) est créée si elle n'existe pas encore
- **Équipes** : une ligne par équipe du club dans une division dont `division.Ord` est compris entre 70 (PNM) et 150 (R4M), soit PNM, PNF, R1M, R1F, R2M, R3M, R4M. Pour chaque équipe :
  - Réengagement (Oui/Non) → `equipe.ReEngagement`
  - Jour de rencontre souhaité (Samedi/Dimanche) → `equipe.JourSouhaite`
  - Souhait de désignation JA (CRA ou Club) → `equipe.ArbitrageCRA`, uniquement affiché pour les équipes **R3M/R4M** (`Id_Division` 1 ou 10)
- **Note libre** (`club.DesiderataNote`) pour signaler toute modification (nouvelle équipe, correction…) sans avoir à gérer un formulaire d'ajout d'équipe

### Règles
- À l'enregistrement, `club.DesiderataSaison` et `club.DesiderataDate` sont mis à jour (saison courante, horodatage) — utilisés par EN12 pour afficher le statut « Soumis / En attente »
- Pour les équipes R3M/R4M, le souhait JA écrit `equipe.ArbitrageCRA` (1 = CRA, 0 = Club), `equipe.JAdemande` (miroir 0/1 du même choix, lu par EA92/l'écran équipes régionales), **et resynchronise `rencontre.ArbitrageCRA` sur les rencontres à venir de cette équipe** (`Date >= CURDATE()`) — voir la règle unifiée **« Arbitrage requis (ArbitrageCRA) »** ci-dessous, commune à EN14, EN17, EN18, EN25, EA82/EA83, EA92, EN29, EN23 et ES33

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `charger` | GET | Retourne le club, sa salle principale et ses équipes (PN à R4M) avec leurs désidératas actuels |
| `enregistrer` | POST | Enregistre correspondant, salle, note et désidératas par équipe ; synchronise `JAdemande` et les rencontres à venir pour R3M/R4M |

### Règle unifiée « Arbitrage requis (ArbitrageCRA) »
Un seul nom, `ArbitrageCRA`, porté par 3 tables — toutes booléennes (`TINYINT(1) NOT NULL`, 1 = CRA fournit le JA, 0 = à la charge du club) :
- **`division.ArbitrageCRA`** — défaut structurel : `1` pour toutes les divisions, `0` uniquement pour `R3M`/`R4M` (voir EA89).
- **`equipe.ArbitrageCRA`** — valeur par équipe, initialisée depuis `division.ArbitrageCRA` à la création (import EA82/EA83, saisie EA92/EN29), modifiable ensuite via EN18 (le club) ou ES33 (la CSR) — en pratique uniquement pour R3M/R4M (seules divisions où le formulaire propose le choix).
- **`rencontre.ArbitrageCRA`** — valeur **par rencontre**, qui fait foi directement (pas un recalcul en direct depuis `equipe`) :
  - à la création (import EA82), photo de `equipe.ArbitrageCRA` de l'équipe domicile à cet instant ;
  - **le souhait se fait normalement avant le début de la phase** ; s'il change en cours de phase (EN18, ES33, EA92, EN29), seules les rencontres **à venir** de cette équipe (`Date >= CURDATE()`) sont mises à jour — les rencontres déjà passées gardent la valeur qu'elles avaient à l'époque, jamais réécrites rétroactivement ;
  - EN23 (`RencontreAdminController`) permet aussi de modifier `ArbitrageCRA` à la main sur une rencontre précise (édition directe Oui/Non, comme les autres champs de cet écran) — cette valeur sera cependant écrasée par la prochaine resynchronisation si le souhait de l'équipe change avant la date de la rencontre.

Valeur effective utilisée par EN14 (candidats/nomination), EN17 (statistiques) et l'écran de liste d'EA82 — lecture directe de `rencontre.ArbitrageCRA`, sans recalcul depuis `equipe` :
1. Si `rencontre.ArbitrageCRA = 1` → obligatoire.
2. Sinon si une nomination Valide existe déjà sur cette rencontre (désignée par le nominateur ou via EN25, même sans demande CRA de l'équipe) → obligatoire.
3. Sinon → non obligatoire (arbitrage club, R3M/R4M uniquement — c'est le club recevant qui désigne son propre JA via EN25, sans passer par une nomination NIJAC tant qu'il ne l'a pas fait).

La recopie `equipe.ArbitrageCRA` → `rencontre.ArbitrageCRA` (à la création, puis resynchronisation « rencontres à venir » sur chaque changement de souhait) corrige un bug historique de l'ancien fonctionnement (avant 09/2026) où cette recopie n'était pas fiable — `equipe.JAdemande` restait parfois à 0 malgré un souhait CRA enregistré, désynchronisant plusieurs centaines de rencontres R3M/R4M.

---

## EN19 – Adresse domicile JA

**Fichier :** `Nominateur/adresse_ja.php`  
**Accès :** Page publique (sans session), accessible via un lien tokenisé ; certaines actions internes exigent une session nominateur

### Objectif
Permettre à un Juge-Arbitre de renseigner ou corriger son code postal et sa ville, sans avoir besoin de se connecter, via un lien envoyé par email.

### Actions AJAX
| Action | Méthode | Session requise | Description |
|--------|---------|------------------|-------------|
| `token` | GET/POST | Nominateur/admin (`auth_required`) | Génère l'URL tokenisée `adresse_ja.php?ja=TOKEN` (Obfuscator) pour un `Id_JA` donné |
| `envoyer_demande_adresse` | POST | Nominateur/admin | Envoie au JA l'email du modèle système `Demande adresse` avec son lien personnalisé |
| `recherche_laposte` | POST | Aucune (public) | Recherche une commune par code postal / nom dans `laposte` |
| `sauvegarder` | POST | Aucune (public) | Enregistre l'adresse choisie pour le JA |

### Interface publique
- Champs **Code postal** et **Ville**, recherche/normalisation (accents, tirets, `SAINT` → `ST`) dans la table `laposte`
- Code postal unique → sélection automatique de la commune ; plusieurs communes pour un même CP → bloc de suggestions à choisir manuellement
- Bouton **Enregistrer** désactivé tant qu'une commune valide (`Id_LaPoste`) n'est pas résolue

### Écritures en base
- `UPDATE ja SET Id_LaPoste = ?, Cp = ?, Ville = ? WHERE Id_JA = ?`
- Auto-migration : ajout des colonnes `ja.Cp` et `ja.Ville` si absentes

### Génération et envoi du lien
- Token = `Obfuscator::obfuscate($idJa)` (seed `OBFUSCATOR_SEED`), lien généré depuis EN11 (fiche JA) ou par `envoyer_demande_adresse`
- Le modèle « Demande adresse » (système, `messagerie.Type = 'Demande adresse'`) supporte les marqueurs `{NOM}`, `{PRENOM}`, `{NOM_COMPLET}`, `{URL_ADRESSE_JA}`, `{UTI_NOM}`, `{UTI_PRENOM}`, `{URL_LIGUE}`, `{YEAR_PHASE}`
- Envoi via `getNijacMailer()`, destinataire résolu par `getEmailDestinataire()`, soumis au rate-limiting (`checkRateLimit()` / `enregistrerEnvois()`)

---

## EN21 – Convocation et frais JA

**Fichier :** `Nominateur/convocation_ja.php`  
**Accès :** Page publique — aucune authentification. URL jetonnée par un token `cnv` (Obfuscator de l'`Id_Nomination`), servie en **segments de chemin** : `convocation-ja/<Id_Nomination>/<tokenCnv>`. Cette forme sans `?`/`=`/`&` évite la troncature du lien dans les emails en texte brut (encodage quoted-printable + auto-lien des webmails) qui, en prod, faisait arriver le lien sans le paramètre `nomination` (message « Paramètre nomination manquant »). L'ancienne forme `?nomination=<id>&cnv=<token>` reste acceptée pour les convocations déjà envoyées.

### Objectif
Affiche la convocation officielle imprimable (format A4) d'un Juge-Arbitre pour une rencontre donnée, et permet la saisie de ses frais de déplacement. Générée depuis EN14 (`NominationController::envoyerConvocations()`), envoyée par email au JA nominé.

### Interface
- En-tête FFTT, identité du JA, détails de la rencontre (journée, division, poule, opposants, date, heure, salle)
- Correspondant du club recevant : nom, **téléphone** et **courriel** (`Club.CorNom` / `CorTelephone` / `CorEmail`, tiret `–` si la donnée manque)
- Tableau indemnités : indemnité forfaitaire (config `indemnite_forfaitaire`) + péages (saisis) + km (saisis) × tarif (config `frais_kilometrique`) = total, recalculé en JS à la saisie
- Distance domicile JA ↔ salle pré-calculée par Haversine si aucun kilométrage n'a encore été saisi
- Rapport JA (accueil/ambiance, équipements/salle) — zones de texte libres
- Bouton **Imprimer / PDF** (CSS `@media print`), bouton **Enregistrer les frais**
- **Saisie des frais conditionnée à l'accusé de réception** : tant que la convocation est accusable (nomination valide, rencontre du jour ou à venir) mais pas encore accusée, le bouton « Enregistrer les frais » est masqué, les champs péages/km/défiscalisation/rapports sont désactivés (grisés) et le message « Pour saisir vos frais, accusez d'abord réception de cette convocation » s'affiche à la place du bouton ; le clic sur « J'accuse réception… » les débloque sans rechargement. Exceptions (saisie autorisée sans accusé) : rencontre passée (le JA doit pouvoir saisir ses frais réels) et colonne `AccuseReception` absente (avant EA98)
- **Accusé de réception** (bandeau au-dessus de la feuille A4, masqué à l'impression) : pastille verte « Accusé de réception enregistré le jj/mm/aaaa à hh:mm » si `nomination.AccuseReception` est renseignée, sinon bouton « J'accuse réception de cette convocation » (`bi-check2-circle`) pour une nomination valide dont la rencontre est du jour ou à venir ; rencontre passée : mention « Rencontre passée », pas de bouton. Mise à jour sans rechargement + toast (`nijac-toast.js`) ; erreurs en toast rouge persistant (403 = session/CSRF expirée → recharger). Bandeau entièrement masqué tant que la colonne n'existe pas (avant EA98) ou en cas d'erreur SQL
- **Aperçu nominateur** (s'applique à tout lien EN21 ouvert dans une session NIJAC connectée — Nominateur/Administrateur/CSR…, ex. depuis la fenêtre des liens de l'envoi EN14 ; un JA ne se connecte jamais) : bandeau « Aperçu nominateur — ce que voit le JA », bouton « J'accuse réception… » remplacé par « Pas encore d'accusé de réception du JA », champs de frais/rapports désactivés, bouton « Enregistrer les frais » absent. `accuser()` et `sauvegarderFrais()` refusent toute écriture en session (`{ok:false, err:'Aperçu nominateur : action réservée au JA.'}`), avant tout contrôle de jeton. Correction des frais par le nominateur : EN28 (`modifier`), non concerné. Aucune dépendance à la colonne `AccuseReception`. Le JA sans session voit la page inchangée

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `sauvegarder_frais` (`sauvegarderFrais`) | POST | Valide et enregistre péages/km (plafonds `frais_max_peages`/`frais_max_km`), les deux rapports texte et la case défiscalisation (`nomination.Defiscalisation`, 0/1) dans `nomination`. Refus `{ok:false, err:'Accusez réception de la convocation avant de saisir vos frais.'}` (HTTP 200, comme les autres refus) si `ConvocationJaController::fraisBloquesSansAccuse()` : colonne `AccuseReception` présente, NULL, nomination valide et rencontre du jour ou à venir (rencontre passée ou colonne absente → enregistrement autorisé) |
| `convocation-ja/accuse` (`accuser`) | POST | Route publique sans filtre (CSRF global, header `X-CSRF-Token`). `id_nomination` + `cnv` (jeton vérifié comme `sauvegarderFrais`, avec/sans pepper) ; seule la nomination du lien est accusable (`cible` facultatif : vide ou égal à son id, toute autre valeur → `{ok:false, err:'Action non disponible.'}`). Logique dans `accuserReceptionConvocation()` (`config/app_config.php`) : la nomination doit être rattachée à un JA (`nomination → disponible.Id_JA`), `Valide = 1` et `rencontre.Date >= CURDATE()` (`convocationAccusable()`) — inconnue, non valide ou de rencontre passée est refusée. `UPDATE nomination SET AccuseReception = NOW() WHERE Id_Nomination = ? AND AccuseReception IS NULL` : idempotent, la première date est conservée. Réponse `{ok, nb (1 si nouvellement accusée, sinon 0), date (jj/mm/aaaa hh:mm)}` ou `{ok:false, err}` ; refus explicite si la colonne n'existe pas encore (EA98 non passé). Aucun email, pas de journal d'IP (aucune colonne prévue), pas de rate-limit (`checkRateLimit` est un compteur d'emails en session, inadapté) |

### Règles
- Aucune vérification de session ni de rôle : accès par le jeton `cnv` (Obfuscator de l'`Id_Nomination`)
- Colonne `nomination.AccuseReception DATETIME NULL` (après `EmailEnvoye`, NULL = pas encore accusée), créée par `initTableConfiguration()` (EA98) ; lue par EN21/EN28 seulement si présente (`nominationAAccuseReception()`, vérification `information_schema` mise en cache par requête). Un changement de JA (EN14 `affecterNomination()`, EN28 `modifier`) remet `AccuseReception` à NULL (si la colonne existe) ; JA inchangé : accusé conservé

---

## EN22 – Disponibilité JA

**Fichier :** `Nominateur/disponibilite_ja.php`  
**Accès :** Page publique — aucune authentification (le fichier legacy se labellisait par erreur "EA88", déjà attribué à Régions)

### Objectif
Permet à un Juge-Arbitre de déclarer ses disponibilités par journée de championnat (Disponible / Partiel / Non disponible), avec sélection fine des rencontres qu'il souhaite arbitrer en mode Partiel. Accessible via un lien tokenisé (`?ja=TOKEN`, Obfuscator) généré depuis EN11 (action `token`), ou directement en `?id_ja=N` depuis EN13.

### Interface
- Vue calendrier mensuel (saison courante, navigable) avec code couleur par statut, et vue liste des journées
- Modale par journée : bascule Disponible/Partiel/Non disponible, panneau de sélection des rencontres si Partiel
- Distance domicile JA ↔ salle (Haversine), affichée en plage min/max si ≥ 20 rencontres sur la journée
- Case à cocher **Défiscalisation**, bouton **Note** (zone de texte libre à destination des nominateurs)
- Filtrage des rencontres proposées par département du JA (règle Seine-Maritime 76 → inclut aussi l'Eure 27, config `dept_76_includes`)
- **Cartouche en bas d'écran** « J'accepte d'arbitrer dans un ou plusieurs départements voisins » : case maîtresse qui révèle une sélection des départements de la ligue (`14 Calvados`, `27 Eure`, `50 Manche`, `61 Orne`, `76 Seine-Maritime`), celui du JA étant masqué. Enregistrement automatique au changement dans `ja.ArbitreAutresDepts` (booléen) et `ja.DeptsArbitrage` (SET). Décocher la case maîtresse remet les deux colonnes à 0 / NULL.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `ja` | GET | Fiche résumée d'un JA (nom, grade, CP/ville, défiscalisation) — token JA ou session (`resolveIdJaAutorise()`) |
| `journees` | GET | Cartouches Journée × Date avec statut actuel et distances Haversine min/max |
| `rencontres_journee` (`rencontresJournee`) | GET | Rencontres d'une journée filtrées par département du JA, avec distance et réponse existante |
| `sauvegarder_dispo_journee` (`sauvegarderDispoJournee`) | POST | Enregistre le statut d'une journée (O/P/N) et, en mode P, la liste des rencontres sélectionnées |
| `token` | GET/POST | Génère le lien tokenisé (`?ja=TOKEN`) pour un `Id_JA` — exige une session Nominateur/Admin (filtre `auth`), comme EN19 ; publique, elle permettait de fabriquer le token de n'importe quel JA |
| `lire_note` (`lireNote`) | GET | Lit `ja.Note` |
| `sauvegarder_note` (`sauvegarderNote`) | POST | Met à jour `ja.Note` |
| `sauvegarder_defiscalisation` (`sauvegarderDefiscalisation`) | POST | Met à jour `ja.Defiscalisation` |
| `sauvegarder_arbitrage_voisins` (`sauvegarderArbitrageVoisins`) | POST | Met à jour `ja.ArbitreAutresDepts` + `ja.DeptsArbitrage` (`actif` 0/1, `departements[]` ⊂ 14/27/50/61/76). Route publique gardée par token JA **ou** session authentifiée (`resolveIdJaAutorise()`) — réutilisée telle quelle par la modale d'EN11 |

### Bug corrigé lors du portage CI4 (contrairement à la politique habituelle de préservation)
Les actions `rencontres_journee` et `sauvegarder_dispo_journee` du fichier legacy rejetaient la requête (`Paramètres manquants`/`Paramètres invalides`) dès que `journee = 0`, à cause d'un test PHP `!$journee` qui traite `0` comme une valeur absente — même bug que celui identifié et corrigé dans EN14 (Nomination). Sur la base de données actuelle, **toutes** les lignes de `rencontre` ont `Journee = 0` (numérotation de journée jamais renseignée), ce qui rendait ces deux actions non fonctionnelles en pratique. Corrigé dans `DisponibiliteJaController` (CI4) en distinguant "paramètre absent" (`null`/chaîne vide) de "paramètre valant 0" avant le cast en entier.

---

## EN23 – Gestion des rencontres

`RencontreAdminController` (CI4), routes `gestion-rencontres`, `gestion-rencontres/data`, `gestion-rencontres/doublons`, `gestion-rencontres/(:num)` (PUT/DELETE). Filtre `auth` (Nominateur ou Administrateur). Transféré du menu admin (ex-EA95) vers le menu nominateur (E003), même principe qu'EN27 (ex-EA80) et EN29 (ex-EA94) — toutes les fonctions sont restées identiques, seul l'accès a changé.

### Objectif
Édition directe de la table `rencontre` (Date, Heure, Poule, Journee, Frais), sans repasser par les écrans d'import (EA82/EA83) ni un ré-import.

### Interface
- Filtres : Département (clubs actifs + option combinée « 76 + 27 » ; combo large de 260 px, **présélectionné sur le département de l'utilisateur connecté** — 76 et 27 → « 76 + 27 », sinon « Tous » si absent de la liste), Division, Poule, Journée, Date, recherche Équipe (domicile ou extérieure).
- Liste triée par **Phase, Journée, Poule** ; 1ʳᵉ colonne `Id_Rencontre` (triable) ; colonne **Arbitrage** (`CRA`/`Club` selon `rencontre.ArbitrageCRA`, triable).
- Panneau d'édition : Date/Heure/Poule/Journée/Phase, équipes domicile/extérieure, salle, arbitrage obligatoire, **Frais** (`Dom`/`Ext`), commentaire ; libellé de l'affiche « Dom **vs** Ext ».
- Bouton « Doublons » : n'affiche que les rencontres en doublon d'affiche — `GROUP BY Id_EquipeDom, Id_EquipeExt, Phase HAVING COUNT(*)>1` (une affiche ne se joue qu'une fois par phase, quelles que soient la date/l'heure/la journée/la poule).
- Suppression d'une rencontre (refusée si un JA y est déjà nommé — message renvoyant à EN14 — ou si des JA ont répondu à ses disponibilités).

### Champ Frais
`rencontre.Frais` : `ENUM('Dom','Ext') NOT NULL DEFAULT 'Dom'`, placé après `ArbitrageCRA` (avant `Commentaire`) ; indique quelle équipe supporte les frais du JA. Par défaut (et pour toute rencontre créée par les imports EA82/EA83, qui n'écrivent pas la colonne) : `Dom`, à la charge de l'équipe à domicile. Seul EN23 permet de passer une rencontre en `Ext` (`update()` : toute valeur autre que `Ext` est ramenée à `Dom`). Colonne créée de façon idempotente par `initTableConfiguration()` (ouvrir EA98 après déploiement).

### Saisie de l'heure (panneau d'édition)
`<input type="time" step="60">` : n'importe quelle heure de **00:00 à 23:59** (au clavier ou via le sélecteur natif du navigateur). Les boutons **–** / **+** encadrant le champ décalent de **15 minutes**, bornés à `[00:00, 23:59]` (pas de bascule à minuit). Le contrôleur accepte tout `HH:MM` (regex `^\d{2}:\d{2}(:\d{2})?$`, complété en `:00`).

### Avertissement JA déjà nommé
Si `update()` change `Date` ou `Heure` sur une rencontre qui porte déjà une nomination, la réponse inclut un `avertissement` (affiché en toast warning côté client) : le JA n'est pas prévenu automatiquement, il faut vérifier sa disponibilité à la nouvelle date/heure et le prévenir (ou refaire la nomination dans EN14).

---

## EN26 – Statistiques des nominations

`StatsNominationController` — GET `stats-nomination` (vue) et GET `stats-nomination/data` (JSON unique : `rencontres`, `compteurs`, `clubsRegionale`, `clubs`, `deptsClubs`, `coefNat`, `coefReg`). Filtre `auth`, lecture seule. Voir `ECRANS.md` pour le calendrier, les journées et le tableau des JA nominés.

### Cartouche « Clubs avec équipes en régionale » (restauré, avant « Prestations par club »)
- **Périmètre** : clubs dont `SUBSTRING(Id_Club, 3, 2)` ∈ `getDepartementsAutorises()` ayant **au moins une** équipe `equipe` hors `Division LIKE 'N%'` (jointure interne). JSON `clubsRegionale`, trié par `c.Nom`.
- **Éq. rég.** / **Éq. nat.** : comme « Prestations par club » (`equipe` hors N*, club porteur principal ; `equipe_nationale`).
- **Réalisées** : toutes les nominations des JA du club (`nomination → disponible → ja.Id_Club`), sans filtre `Valide`, date ni type d'arbitrage, non restreintes au périmètre (indicateur de complétude du club).
- **À effectuer** (`Quota`) = Éq. nat. × `nombre_arbitrage_national` + Éq. rég. × `nombre_arbitrage_regional` (mêmes clés/défauts, rappelés dans le sous-titre).
- **Avancement** : « Réalisées / À effectuer » + jauge (largeur = min(100 %, Réalisées / À effectuer)) ; ligne verte si Réalisées ≥ À effectuer (> 0), orange sinon. Pas de tri, filtre ni total.

### Cartouche « Prestations par club »
- **Périmètre** : tous les clubs (`club`) dont `SUBSTRING(Id_Club, 3, 2)` ∈ `getDepartementsAutorises()` du nominateur, y compris ceux sans équipe ni JA (valeurs 0).
- **Équipes nationale** : nombre de lignes `equipe_nationale` du club (`Id_Club`, divisions N1…N3).
- **Équipes régionale** : nombre de lignes `equipe` du club porteur principal (`Id_Club`) avec `Division NOT LIKE 'N%'` (PN, R1…R4).
- **Prestations dues** = Nationale × `nombre_arbitrage_national` (défaut 7) + Régionale × `nombre_arbitrage_regional` (défaut 5), calculées côté serveur ; coefficients lus par `getConfig()` (défauts si EA98 n'a pas encore créé les clés) et rappelés dans le sous-titre.
- **Prestations faites** : nominations `Valide = 1` (`nomination → disponible → ja`, `ja.Id_Club` = club) sur une rencontre déjà jouée (`rencontre.Date <= CURDATE()`), toutes divisions et départements confondus, **arbitrage CRA et arbitrage club confondus** (toutes nominations valides jouées, `COUNT(*)`). La table `rencontre` ne contient que la saison en cours.
- **dont arbitrages club** (colonne informative après « Prestations faites », texte gris italique sur fond léger, infobulle « Part des prestations faites réalisée en arbitrage club — incluse dans Prestations faites ») : part des prestations faites sur des rencontres en arbitrage club (`rencontre.ArbitrageCRA = 0`, R3M/R4M sans demande CRA). Les deux compteurs viennent d'une seule sous-requête (`COUNT(*)` / `SUM(r.ArbitrageCRA = 0)`) ; `ArbitrageCRA` est `NOT NULL DEFAULT 1`, pas de cas NULL. Déjà inclus dans « Prestations faites » et l'écart ; total propre en pied de tableau, non réadditionné ; rappel en légende sous le tableau (« Prestations faites inclut les arbitrages club »).
- **Écart** = Faites (arbitrages club inclus) − Dues (rouge si négatif, vert si positif).
- Tableau trié par défaut par nom de club alphabétique (serveur `ORDER BY c.Nom` ; client `Intl.Collator('fr', {sensitivity:'base'})`, insensible casse/accents), en-têtes triables, compteur de clubs, ligne de totaux sur les clubs affichés.
- **Filtre Département** (combo `.combo-field`, défaut « Tous les départements ») : départements présents parmi les clubs (`clubs[].Dept` = positions 3-4 de `Id_Club`, libellés « 76 — Seine-Maritime » via `getDeptActifs()`, JSON `deptsClubs`) ; filtre côté client, compteur et totaux recalculés, tri conservé.
- **Exporter CSV** (côté navigateur, Blob) : lignes affichées (filtre et tri en cours), fichier `prestations-par-club-AAAA-MM-JJ.csv`, colonnes Département ; Id club ; Club ; Équipes Nationale ; Équipes Régionale ; Prestations dues ; Prestations faites (arbitrages club inclus) ; Dont arbitrages club ; Écart, puis ligne `TOTAL`. UTF-8 avec BOM, séparateur `;`, fins de ligne CRLF, champs contenant `;` `"` ou retour ligne entre guillemets (`"` doublés), entiers bruts ; cellules texte commençant par `=` `+` `-` `@` préfixées de `'` (anti-injection de formule). Bouton désactivé quand aucun club n'est affiché.

---

## EN29 – Gestion des équipes

`EquipeAdminController` (CI4), routes `gestion-equipes`, `gestion-equipes/data`, `gestion-equipes/(:num)` (POST/PUT/DELETE), `gestion-equipes/(:num)/appliquer-arbitrage` (POST). Filtre `auth` (Nominateur ou Administrateur). Transféré du menu admin (ex-EA94) vers le menu nominateur (E003), même principe qu'EN27 (ex-EA80) et EN23 (ex-EA95).

### Objectif
Édition directe de la table `equipe` (Nom, Division, Club), sans passer par les écrans d'import. Distinct d'EA92 (Chargement équipe régionale), qui édite les champs de désidératas (ReEngagement, JourSouhaite, ArbitrageCRA...) d'équipes déjà importées mais laisse Nom/Division/Club en lecture seule.

### Interface
- Filtres Département (même combo qu'EN23 : option « 76 + 27 », 260 px, présélectionné sur le département de l'utilisateur connecté au premier chargement), Club, Division, Nom.
- Liste + panneau d'édition : Nom, Division, Club (jusqu'à 3 clubs pour une équipe « entente » — Id_Club/Id_Club2/Id_Club3), Réengagement, Jour souhaité, Souhait JA, Saison désidérata.
- **Souhait JA** affiché uniquement pour les divisions **R3M et R4M** (champ masqué et valeur forcée à « CRA » sinon).
- Bouton **Supprimer** (confirmation `nijacConfirm` danger ; refusé avec message clair si des rencontres référencent l'équipe — FK `rencontre` en `ON DELETE RESTRICT`, message renvoyant à EN23).

### Resynchronisation de l'arbitrage
Si le Souhait JA change à l'enregistrement, `rencontre.ArbitrageCRA` est resynchronisé automatiquement sur **toutes** les rencontres de l'équipe, **y compris celles déjà jouées** — dérogation volontaire à la règle « jamais l'historique » suivie par EN18/ES33/EA92. Bouton ↻ à côté du champ Souhait JA pour resynchroniser manuellement à tout moment, sans changement de valeur (`appliquerArbitrageRencontres`).

---

## EN24 – Remplacement équipe

`RemplacementEquipeController` (CI4), routes `remplacement-equipe`, `remplacement-equipe/equipes`, `remplacement-equipe/rencontres/(:num)`, `remplacement-equipe/remplacer` (POST). Filtre `auth` (Nominateur ou Administrateur). Bouton du menu E003, juste après EN29.

### Objectif
Une équipe forfait ou désistée pour le reste de la saison est remplacée par une autre équipe sur toutes ses rencontres restantes, sans repasser par un ré-import (EA82/EA83) et sans éditer manuellement chaque rencontre en EN23.

### Écran
- **Gauche** : mêmes informations et filtres que EN23 — tableau de **toutes** les rencontres (Date, Heure, Poule, Journée, Division, Domicile, Extérieur), filtres Département (même combo qu'EN23 : « 76 + 27 », présélectionné sur le département de l'utilisateur connecté)/Division/Poule/Journée/Date + recherche libre Équipe, plus une colonne **JA** (« Oui »/« Non ») indiquant si une nomination existe déjà sur la rencontre. Cliquer sur le nom d'une équipe (Domicile ou Extérieur) la désigne comme **équipe à remplacer** (et filtre au passage le tableau sur son nom, comme le clic sur une équipe en EN23).
- **Droite** : une fois une équipe désignée à gauche, un champ de recherche libre (nom d'équipe ou de club) permet de choisir l'**équipe de remplacement** parmi toutes les équipes de la base (résultats limités à 15, pas de restriction de division — le nominateur reste libre du choix). Un bouton **Confirmer le remplacement** déclenche l'opération sur les rencontres **affichées** dans le tableau au moment du clic (celles de l'équipe désignée, réduites par les filtres Département/Division/Poule/Journée/Date/Équipe éventuellement actifs) — pas forcément toute la saison de l'équipe : le nominateur peut ainsi ne remplacer qu'une partie des rencontres (ex. une seule journée) en filtrant avant de confirmer.

### Séquence de confirmation (aucune écriture en base avant la dernière étape)
1. Vérification, dès le clic sur **Confirmer le remplacement**, du nombre de nominations déjà faites sur les rencontres actuellement affichées. S'il y en a : une première confirmation (`nijacConfirm`, type danger) annonce leur suppression prochaine.
2. Une seconde confirmation récapitule le remplacement (nom des deux équipes, nombre de rencontres affichées concernées).
3. Ce n'est qu'après validation de cette dernière étape qu'une unique requête `POST remplacement-equipe/remplacer` est envoyée, avec la liste des `Id_Rencontre` affichés (`ids`) : le contrôleur restreint le traitement à ceux qui impliquent réellement l'équipe à remplacer (Domicile ou Extérieur), supprime leurs nominations puis bascule `Id_EquipeDom`/`Id_EquipeExt`, dans une seule transaction PDO (`beginTransaction`/`commit`/`rollBack`) — tout ou rien.
4. Le message de succès retourné par le serveur rappelle, si des nominations ont été supprimées, qu'elles doivent être refaites (EN14).

### Contrôleur
Pas de Model, `getPDO()` direct comme le reste de cette famille d'écrans. Aucune restriction de département (comme `RencontreAdminController::data()`, dont EN23 hérite déjà sans filtrage dept).

---

## EN28 – Suivi des nominations

**Fichier :** `SuiviNominationController` (CI4) — vue `suivi_nomination_index.php`
**Accès :** Nominateur ou Administrateur (filtre "auth") — bouton du menu nominateur (E003), après EN14

### Objectif
Afficher **toutes les rencontres prévues** du périmètre du nominateur (toutes dates, nommées ou non) et, pour les nominations validées (et les arbitrages club désignés via EN25), suivre les frais saisis par le JA dans EN21 et relancer les JA.

### Rencontres sans JA
Une ligne par rencontre (au plus une nomination par rencontre, `uq_nomination_rencontre`). Une rencontre sans nomination (ou dont la nomination n'est pas retenue, voir `data`) apparaît sur fond rose clair (#FFE4E8, survol #E9ECEF), JA « — Aucun JA » en gris italique, N° licence / Compte EBP / Date saisie vides, sans bouton Modifier ni Rappel (pas de double-clic) — sauf le bouton **Relancer le club** en arbitrage club (`ArbitrageCRA = 0`, colonne Rappel, voir `relance-club`) et, en arbitrage club comme en arbitrage CRA, le bouton **Saisir le JA** (club) / **Nommer un JA** (CRA) (icône `bi-person-plus`, colonne Modifier, ou double-clic sur la ligne ; voir `saisir`) : en arbitrage club, le nominateur enregistre lui-même le JA qui a officié quand le club n'a pas répondu via EN25 ; en arbitrage CRA, il nomme un JA sans passer par EN14 (mêmes règles, voir `saisir`). Popup « Saisir le JA — <rencontre> » / « Nommer un JA — <rencontre> » (même popup que Modifier, sans le champ Arbitrage) : club → liste des JA du périmètre avec ceux du club recevant (`ja.Id_Club`) en tête, péage / km / défiscalisation (0 par défaut) ; CRA → seuls les JA nommables sur cette rencontre (voir `ja-disponibles`), par ordre alphabétique, sans les frais (saisis ensuite par le JA en EN21 ou via Modifier). Rien n'est proposé si l'arbitrage n'est pas renseigné (`ArbitrageCRA` NULL). Une fois saisie, la rencontre (club ou CRA) la rencontre se corrige par **Modifier** comme toute nomination. La colonne Arbitrage (CRA/Club) reste affichée. `rencontre` n'a pas de colonne de statut (annulée/reportée/forfait) : aucune rencontre n'est exclue à ce titre.

### Colonnes
Date de la rencontre (jour abrégé) · Division (macaron coloré comme EN23, `division.Color`) · Arbitrage (CRA ou Club, `rencontre.ArbitrageCRA`) · Domicile · Extérieur · N° licence (`Id_JA`) · JA · Compte EBP (`NumCompteEBP`) · Péage · Km · Défisc. (Oui/Non) · Date saisie · **AR** (accusé de réception EN21 : ✔ vert, info-bulle « Accusé de réception le jj/mm/aaaa à hh:mm », sinon « — » ; vide sans JA ; `nomination.AccuseReception`, `NULL AS AccuseReception` dans `data` tant que la colonne n'existe pas) · Modifier · Rappel (bouton de rappel au JA, ou « Relancer le club » sur une rencontre en arbitrage club sans JA). Les trois colonnes de frais affichent « — » tant que `nomination.DateSaisie` est NULL (le JA n'a pas encore enregistré ses frais).

### Filtres (client)
Date (combo des dates de rencontre existantes, ordre croissant), Division (badge + popup Messieurs/Dames `nijac-division-filter.js`, comme EN29 — divisions présentes dans les rencontres chargées), équipe (domicile ou extérieur, sous-chaîne), nom du JA (sous-chaîne), Date saisie (Toutes / Renseignée / Non renseignée — « Non renseignée » inclut les rencontres sans JA), Nomination (Tous / Avec JA / Sans JA), Accusé (Tous / Reçu / Non reçu — « Non reçu » = nominations sans accusé, rencontres sans JA exclues). Compteur `#lbl-count` = lignes affichées / total des rencontres chargées. Tri par clic sur les en-têtes (sur les données, la date est triée chronologiquement). Tri initial : date décroissante.

### Frais non comptés
Les valeurs de péage et de kilomètres saisies mais **non comptées** sont grisées et barrées dans le tableau (info-bulle), avec les mêmes règles qu'EN17 : seuls les arbitrages CRA valent des frais (Club : 0), et un JA qui arbitre plusieurs rencontres CRA le même jour ne fait qu'un déplacement — km et péage ne sont conservés que sur la 1re rencontre du jour qui en porte (heure la plus précoce, puis n° de nomination).

### Actions AJAX
| Route | Méthode | Description |
|-------|---------|-------------|
| `suivi-nomination/data` | GET | Toutes les rencontres dont le club domicile est dans les départements autorisés (même critère qu'EN14 : `SUBSTRING(equipe.Id_Club, 3, 2)`), une seule requête `FROM rencontre` + `LEFT JOIN nomination / disponible / ja` (`Id_Rencontre` toujours présent, `Id_Nomination` et champs JA/frais NULL si aucune nomination). Nomination retenue si `Valide = 1` (toute nomination l'est d'office, voir EN14) — le `OR rencontre.ArbitrageCRA = 0` conservé ne couvre plus que les arbitrages club EN25 restés à `Valide = 0` tant que la migration EA98 n'a pas tourné ; sinon la rencontre s'affiche sans JA. Tri `Date DESC, Heure, Id_Rencontre`. Pas de bouton Rappel sur une nomination non validée (refusé par `rappel`) |
| `suivi-nomination/ja-liste` | GET | JA actifs (`JA1 = 1`) des départements autorisés (`Id_JA`, `Nom`, `Prenom`, `Id_Club`), pour la liste déroulante des popups en **arbitrage club** (Saisir le JA, Modifier d'une rencontre club — liste complète, souple ; `data` fournit `IdClubDom` pour placer en tête les JA du club recevant) |
| `suivi-nomination/ja-disponibles` | GET | `rencontre` → liste des popups en **arbitrage CRA** (Nommer un JA, Modifier d'une rencontre CRA, et Modifier dont le champ Arbitrage est basculé de Club vers CRA ; retour vers Club = `ja-liste` complète). Refus si rencontre hors périmètre (club recevant, comme `saisir`/`modifier`). Une seule requête (`listerJaDisponibles()`) : JA `JA1 = 1` du périmètre (`Id_JA`, `Nom`, `Prenom`, `Id_Club`) ayant un 'O' (rencontre ou journée) et aucun 'N' (rencontre ou journée) — même condition `EXISTS`/`NOT EXISTS` que `jaDisponiblePourNomination()` — et passant `controlerJourJa()` (moins de 2 autres nominations ce jour, aucune chez un autre club recevant). Le JA actuellement nommé est toujours inclus (`actuel: true`, libellé « — actuel »), même s'il ne passe plus la règle. Réponse `{ok, ja}`. Vue : « Chargement… » pendant l'appel, toast d'erreur échappé, « Aucun JA disponible pour cette rencontre » et bouton Enregistrer désactivé si la liste est vide. Le contrôle serveur de `saisir`/`modifier` reste la protection finale |
| `suivi-nomination/saisir` | POST | `id_rencontre`, `id_ja`, `peage`, `km`, `defisc` → « Saisir le JA » d'un arbitrage club ou « Nommer un JA » d'un arbitrage CRA (`creerNomination()`). Refus communs : rencontre hors périmètre (club recevant, même critère que `data`), arbitrage non renseigné (`ArbitrageCRA` NULL), nomination déjà existante (« utilisez Modifier »), JA inexistant / `JA1 ≠ 1` / `CodeDept` hors départements autorisés, règle 2 nominations max par JA et par date sur le même club recevant (`controlerJourJa()`, partagée avec `modifier`). **Arbitrage club** — en transaction, même création qu'EN25 (`ArbitreClubController::enregistrer`, logique dupliquée dans `creerNomination()`) : `disponible` (JA, rencontre) réutilisée ou créée avec `Reponse = 'P'`, `DateReponse = CURDATE()`, note « Juge-arbitre saisi depuis EN28 (arbitrage club) », puis `nomination` (`Peage`/`Kilometre`/`Defiscalisation` saisis, `DateSaisie = CURDATE()`, `Valide = 1`, `EmailEnvoye = 0`). **Arbitrage CRA** — mêmes règles qu'EN14 (`verifierEtNommer` / `resoudreDisponible` / `affecterNomination`), **disponibilité stricte comprise** : dans la transaction, `controlerDispoCra()` pose les verrous `FOR UPDATE` (rencontre, JA, lignes `disponible` du JA pour la rencontre/journée), revérifie JA actif + `CodeDept` autorisé, puis applique `jaDisponiblePourNomination()` (`config/app_config.php`, même règle que `sqlDispoRencontre()` d'EN14) : une réponse 'O' sur la rencontre ou la journée exigée ET aucune réponse 'N' ni sur la rencontre ni sur la journée — sans réponse ou 'P' seul : refus « Ce JA n'est pas disponible pour cette rencontre (aucune réponse « oui »). », un 'N' (même avec un 'O') : refus « Ce JA n'est plus disponible pour cette rencontre/journée (il a répondu « non »). » (messages d'EN14) ; ensuite `controlerJourJa()`. La ligne 'O' est réutilisée (`disponibleOuiCra()`) : 'O' rencontre telle quelle, sinon la ligne rencontre est matérialisée en 'O' (DateReponse de la réponse 'O' journée, note « Juge-arbitre nommé depuis EN28 (arbitrage CRA) ») — aucune ligne 'P' n'est créée en CRA ; puis `nomination` comme EN14 (`DateNomination = CURDATE()`, `Valide = 1`, `EmailEnvoye = 0`, frais et `DateSaisie` non renseignés — le JA les saisit en EN21 ; « Rappel » reste disponible). La convocation s'envoie ensuite depuis EN14 (`EmailEnvoye = 0`). Saisie concurrente bloquée par `uq_nomination_rencontre`. **Aucun email envoyé** ; la liste est rechargée. Une nomination CRA existante se corrige par `modifier` (JA, frais) |
| `suivi-nomination/modifier` | POST | `id_nomination`, `id_ja`, `arbitrage` (1 = CRA, 0 = Club), `peage`, `km`, `defisc` → en transaction : met à jour `rencontre.ArbitrageCRA` et `nomination` (`Peage`, `Kilometre`, `Defiscalisation`, `DateSaisie = CURDATE()`). Si le JA change : 2 nominations max par JA et par date ; **vers un arbitrage CRA** (`arbitrage` = 1 : CRA→CRA ou club→CRA), même règle stricte qu'à la nomination CRA de `saisir` et qu'EN14 (`controlerDispoCra()` : verrous, JA actif et `CodeDept` autorisé, `jaDisponiblePourNomination()`, mêmes messages), puis la ligne 'O' est réutilisée / matérialisée (`disponibleOuiCra()`, note « Juge-arbitre modifié depuis EN28 ») ; **vers un arbitrage club** (CRA→club, club→club) : souple, inchangé — JA actif exigé, `disponible` (JA, rencontre) réutilisée (rouverte en `P` si `N`) ou créée en `P` avec la note « Juge-arbitre modifié depuis EN28 » ; aucun contrôle de disponibilité si le JA ne change pas (simple modification des frais / de l'arbitrage) ; `Valide = 1` (nomination valide d'office), `EmailEnvoye`, `DateNomination` inchangés ; `AccuseReception = NULL` (l'accusé de l'ancien JA n'est pas hérité, seulement si la colonne existe — accusé conservé si le JA ne change pas). Refus hors périmètre du nominateur |
| `suivi-nomination/rappel` | POST | `id_nomination` → envoie au JA le modèle messagerie n°3 (Convocation, `resoudreModeleMessagerie()` : modèle personnalisé du nominateur si présent), marqueurs de `construireMarqueursMessage()` ; Cc/Reply-To selon le modèle ; passe par `getEmailDestinataire()` (mode Développement). Refus si nomination hors périmètre, non validée, frais déjà saisis (`DateSaisie` renseignée), ou JA sans email |
| `suivi-nomination/relance-club` | POST | `id_rencontre` → « Relancer le club » (bouton enveloppe orange de la colonne Rappel, affiché seulement si `ArbitrageCRA = 0` et aucune nomination, désactivé sans `Club.CorEmail` — fourni par `data`, `LEFT JOIN club`) : **envoi immédiat au correspondant du club, sans confirmation** ; bouton désactivé pendant l'appel puis toast du résultat (« Demande envoyée à … (N destinataires) » ou message d'erreur du serveur, échappé par `escHtml()` comme tous les `msg` serveur affichés en toast sur EN28). Envoie le message n°7 « JA Club » (lien public EN25) via `envoyerDemandeJaClub()` (`config/app_config.php`), le même cœur d'envoi que `nomination/demander-ja-club` (EN14) : modèle perso du nominateur sinon système (`resoudreModeleMessagerie()`), marqueurs `construireMarqueursMessage()`, référent en Cc, Cc/Reply-To du modèle, `getEmailDestinataire()` (mode Développement). En plus d'EN14 : `checkRateLimit(1)` / `enregistrerEnvois(1)`. Refus si hors périmètre (club recevant), JA déjà désigné (« le club a déjà répondu »), rencontre pas en arbitrage club, ou club sans email de correspondant. Aucun suivi d'envoi en base (pas de colonne « demande envoyée le ») |

---

## EN27 – Clubs / Associations

**Fichier :** `club.php`  
**Accès :** Nominateur ou Administrateur (filtre "auth") — ex-EA80, déplacé du menu admin vers le menu nominateur (E003, après EN11)

### Objectif
Importer et gérer la liste des clubs affiliés à la ligue Normandie.

Le filtre Département (JS) propose l'option « 76 + 27 » (`deptFiltre` accepte plusieurs codes séparés par `+`) et est présélectionné sur le département de l'utilisateur connecté ; même comportement sur ES31 (`club_csr_index.php`).

### Champs d'un club
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Id_Club | Texte (N° FFTT, ex : `07614001`) | Oui |
| Nom | Texte | Oui |
| CorNom | Texte (nom du correspondant) | Non |
| CorEmail | Email | Non |
| CorTelephone | Texte | Non |

> Les colonnes correspondant (`CorNom`, `CorEmail`, `CorTelephone`) remplacent l'ancien écran Correspondants de clubs (table `Correspondant` séparée, supprimée). À la première utilisation, une migration copie automatiquement le correspondant existant de chaque club (le plus ancien s'il y en a plusieurs) vers ces colonnes.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne tous les clubs avec correspondant, code postal / ville (salle principale) et nombre de salles |
| `maj_bdd` | POST | Import / upsert d'une liste de clubs (JSON), y compris renommage du N° FFTT avec propagation aux tables liées (salles, correspondants, équipes, JA) |
| `get_clubs_dept_fftt` | POST | Liste des clubs FFTT d'un département via l'API FFTT (`getClubsDepartement`) |
| `sync_fftt_club` | POST | Synchronise un club depuis l'API FFTT : Club, Salle principale et Correspondant en une seule opération |

### Import Excel
- Format FFTT : colonne `N° FFTT` (Id_Club) + `Nom club` (Nom)
- Données lues à partir de la ligne 3 du fichier, parsées côté client puis envoyées à `maj_bdd` sous forme de tableau JSON (`lignes`)
- Comportement : upsert (mise à jour si le club existe, création sinon)

### Synchronisation FFTT
- `get_clubs_dept_fftt` liste les clubs d'un département via l'API FFTT pour sélection
- `sync_fftt_club` récupère le détail d'un club FFTT et met à jour en une fois le nom du club, sa salle principale (nom, adresse, commune) et son correspondant (nom, email, téléphone)

---

## ED51 – Défiscalisation JA

**Fichier :** `DefiscalisationController` (CI4)  
**Accès :** rôle Defiscalisateur ou Administrateur (filtre `defiscauth`) — menu E005

### Objectif
Récapituler, par JA ayant opté pour la défiscalisation, les frais de déplacement de l'**année fiscale** (base des reçus fiscaux « abandon de frais »), et calculer le montant défiscalisable selon le **barème kilométrique fiscal** (table `ComptaDefiscalisation`, éditable en ED52).

### Année de référence
- **Clé de configuration `annee_fiscale`** (éditable en EA91, champ « Année fiscale défiscalisation (ED51) », 4 chiffres 2000-2100, défaut = année système ; `getConfig('annee_fiscale', date('Y'))` — repli sur l'année système si vide/0).
- La fenêtre est le **1ᵉʳ janvier → 31 décembre de cette année** (`anneeCivile()`), utilisée à la fois pour le cumul péages/km et pour le test d'inclusion `nomination.Defiscalisation = 1`.
- Pas de sélecteur dans l'écran : un badge « Année fiscale AAAA » **centré** dans le bandeau. Chargement automatique au démarrage.

### Interface
- 3 cartes résumé : *JA défiscalisés*, *Total péages + km*, *Total défiscalisable*.
- Tableau : N° JA, JA, **CP**, **Ville** (colonnes distinctes), Missions, Péages, Kilomètres, **Frais km + péages** (taux plat `frais_kilometrique`, inchangé), **CV**, **Élec.**, **Frais défiscalisables** ; ligne de totaux (la colonne Élec. y affiche `n/x` = nb de véhicules électriques / nb de JA affichés).
- **CV et Élec. sont en consultation seule** (`–` / `3` … `7 +` ; `Oui` / `Non`) : ces valeurs sont renseignées par le JA sur son attestation signée (ED53, `AttestationDefiscController`), ou après la relance email ci-dessous — plus de saisie inline dans ED51 (route `vehicule` supprimée). Ligne sans CV → mention *« CV manquant »* dans la colonne barème.
- Bouton **Gérer le barème** → ED52.
- Colonne **case à cocher** en tête de ligne (+ case « tout cocher » dans l'en-tête, avec état indéterminé). À chaque (re)chargement, les lignes **sans CV renseigné** sont pré-cochées ; une ligne dont le JA n'a pas d'email (`HasEmail = 0` dans le payload `donnees`) a sa case désactivée.
- Bouton **Relancer les JA cochés (N)** (placé **à gauche** du bandeau) : email groupé aux JA cochés (confirmation `nijacConfirm`, `POST relancer-vehicule` avec `ids[]`) ; libellé et état actif/inactif suivent le nombre de cases cochées.
- Bouton **Export CSV**.

### Population de la liste
`LEFT JOIN` depuis `ja`, `WHERE ( ja.Defiscalisation = 1 OR EXISTS (nomination.Defiscalisation = 1 sur une rencontre de l'année fiscale pour ce JA) )` — un JA est donc retenu par son **choix global** (`ja.Defiscalisation`, fiche EN11 / écran EN22) **ou** par un **choix par mission** fait sur sa convocation EN21 (`nomination.Defiscalisation`), même sans avoir coché le drapeau global. **Pas de filtre `JA1`** : le reçu fiscal de l'année reste dû aux JA désactivés en fin de saison (EA85). `GROUP BY j.Id_JA` → aucun doublon. Les JA sans mission cette année-là apparaissent aussi, totaux à 0. Cumul via `nomination → disponible → ja`, rencontres dont `rencontre.Date` tombe dans l'année fiscale, nominations retenues si `Valide = 1 OR Peage IS NOT NULL OR Kilometre IS NOT NULL OR Defiscalisation = 1`.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `donnees` | POST | Agrégat par JA : `NbMissions`, `Peage`, `Kilometre`, `PuissanceFiscale`, `VehiculeElectrique`, `FraisKmPeages` (taux plat), `MontantBareme` (ou `null`) |
| `relancer-vehicule` | POST (`ids[]`) | Envoie le modèle `messagerie` n°10 aux JA dont l'`Id_JA` est coché — nettoyage des ids (entiers > 0, dédup), filtre serveur `JA1 = 1` + email présent + ( `Defiscalisation = 1` **ou** `nomination.Defiscalisation = 1` sur l'année fiscale ). Un seul mailer (SMTP keep-alive), `Reply-To` selon le modèle, garde-fou `checkRateLimit()` / `enregistrerEnvois()`. Retour `{ok, envoyes, total, erreurs[], msg}` |
| `export-csv` | POST | Renvoie le CSV en JSON (téléchargement déclenché côté client) |

### Calcul du montant défiscalisable (colonne « Frais défiscalisables »)
- `d` = `SUM(Kilometre)` du JA sur l'année fiscale.
- Ligne de barème = celle de `ComptaDefiscalisation` où `PuissanceFiscale BETWEEN Cv_Min AND Cv_Max`.
- Tranche selon `d` : `d ≤ 5 000` → `d × Coef_T1` ; `5 001 ≤ d ≤ 20 000` → `d × Coef_T2 + Fixe_T2` ; `d > 20 000` → `d × Coef_T3`.
- Si `ja.VehiculeElectrique = 1` : `× (1 + comptadefisc_majoration_electrique / 100)` (config, défaut **+20 %**).
- `ja.PuissanceFiscale IS NULL` → montant non calculé (`null`).
- **Les péages n'entrent pas** dans ce montant (remboursés au réel, séparément). Arrondi à 2 décimales.

### Export CSV
En-tête (ajouté côté client) : `Nom;Prenom;CP;Ville;Missions;Peages;Kilometres;FraisKmPeages;CV;Electrique;FraisDefiscalisables`. Séparateur `;`, décimales à la virgule, `CV` = `7+` pour la tranche haute. Fichier `defiscalisation_{annee}.csv`.

### Email de relance « véhicule non renseigné »
Modèle système `messagerie` **n°10** (`Type = 'Administratif'`, `Id_Utilisateur = NULL`, `ReplyTo = 1`, `Cc = 0`), marqueurs `{PRENOM}` / `{UTI_PRENOM}` / `{UTI_NOM}` / `{URL_ATTESTATION_JA}`, éditable via EA93. Plus d'auto-création : la ligne n°10 (et la valeur `'Administratif'` de l'ENUM `messagerie.Type`) doit exister en base, sinon `relancer-vehicule` renvoie « Modèle introuvable ». Mode Développement : redirection par `getEmailDestinataire()`, sujet préfixé `[DEV]`.

### Colonnes `ja` ajoutées
`PuissanceFiscale` (`TINYINT UNSIGNED NULL`, `NULL` = non renseignée) et `VehiculeElectrique` (`TINYINT(1) NOT NULL DEFAULT 0`), après `Defiscalisation`. Déployées par `ALTER TABLE` explicite (déjà appliqué en dev et en prod) — pas de migration automatique.

### Règles
- Période = année civile entière ; l'année est portée par la config `annee_fiscale` (EA91), pas de sélecteur dans l'écran.
- Un seul barème actif : pas d'historique par millésime (le millésime en vigueur est indiqué dans le `TABLE_COMMENT` de `ComptaDefiscalisation`).

---

## ED52 – Barème kilométrique

**Fichier :** `DefiscalisationBaremeController` (CI4)  
**Accès :** rôle Defiscalisateur ou Administrateur (filtre `defiscauth`) — accès depuis ED51

### Objectif
Gérer la table `ComptaDefiscalisation` (barème kilométrique fiscal voiture, valeurs de l'année — cf. `TABLE_COMMENT` « valeurs 2026 ») et le taux de majoration pour véhicule électrique.

### Interface
- Tableau des **5 tranches de puissance** figées : `Libellé`, `Cv_Min` / `Cv_Max` en lecture seule ; 4 champs éditables par ligne — `Coef_T1` (≤ 5 000 km), `Coef_T2` + `Fixe_T2` (5 001–20 000 km), `Coef_T3` (> 20 000 km).
- Champ **Véhicules électriques — majoration (%)** (clé config `comptadefisc_majoration_electrique`).
- Un seul bouton **Enregistrer** (tout le formulaire).
- Pas d'ajout ni de suppression de ligne (structure figée).

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `index` | GET | Rendu de la vue : 5 lignes + valeur de la majoration |
| `enregistrer` | POST (`lignes[]` = `{id, coef_t1, coef_t2, fixe_t2, coef_t3}`, `majoration`) | Met à jour les 5 lignes **et** la clé `comptadefisc_majoration_electrique` dans une **transaction** (tout ou rien) |

### Validation
Chaque coefficient / part fixe / majoration doit être un nombre `≥ 0` (virgule ou point acceptés) ; sinon `rollBack()` + message d'erreur. Coefficients arrondis à 3 décimales, `Fixe_T2` à 2, majoration à 2.

### Table `ComptaDefiscalisation`
`Id_ComptaDefiscalisation` (PK), `Cv_Min` / `Cv_Max` (intervalle de puissance fiscale ; `7`–`99` = « 7 CV et plus »), `Libelle`, `Coef_T1`, `Coef_T2`, `Fixe_T2`, `Coef_T3`, `UNIQUE (Cv_Min, Cv_Max)`. Millésime des valeurs indiqué dans le `TABLE_COMMENT`. Déployée par SQL explicite (`CREATE TABLE` + seed, déjà appliqué en dev et en prod) — pas de migration automatique.

Seed **valeurs 2026** (`Coef_T1` / `Coef_T2` `+` `Fixe_T2` / `Coef_T3`) :

| Puissance | ≤ 5 000 km | 5 001–20 000 km | > 20 000 km |
|-----------|-----------|-----------------|-------------|
| 3 CV et moins | 0,529 | 0,316 + 1 065 | 0,370 |
| 4 CV | 0,606 | 0,340 + 1 330 | 0,407 |
| 5 CV | 0,636 | 0,357 + 1 395 | 0,427 |
| 6 CV | 0,665 | 0,374 + 1 457 | 0,447 |
| 7 CV et plus | 0,697 | 0,394 + 1 515 | 0,470 |

### Règles
- Toute modification est immédiatement prise en compte par ED51 (barème relu à chaque appel `donnees`).
- La majoration électrique est une valeur globale unique (clé de configuration), pas une colonne du barème.

---

## ED53 – Attestation sur l'honneur

**Fichier :** `AttestationDefiscController` (CI4)  
**Accès :** route **publique sans filtre**, contrôle en contrôleur — soit `?ja=TOKEN` valide (Obfuscator), soit une session Défiscalisateur / Administrateur ; sinon redirection vers `login`.

### Objectif
Produire l'**attestation sur l'honneur** du JA défiscalisé — propriété du véhicule, usage exclusivement personnel et bénévole sans contrepartie, engagement à fournir la carte grise. Deux usages :
- **JA (lien tokenisé)** : le JA reçoit `attestation-defisc?ja=TOKEN` dans l'email de relance (marqueur `{URL_ATTESTATION_JA}`, message `messagerie` n°10). Il complète, signe, **valide** → écriture BDD + PDF archivé.
- **Défiscalisateur / Admin** (carte du menu E005, bouton dans le bandeau d'ED51, sans token) : formulaire vierge, **impression seule**, aucune écriture ni PDF serveur.

### Interface
- Bandeau `#toolbar` (non imprimé) : bouton **Imprimer / PDF** et — mode JA uniquement — champ **Carte grise** (`<input type="file">`) et bouton **Valider et transmettre**, alignés sur une même ligne.
- **Mode staff uniquement** : bandeau d'information `.att-dev-note` (non imprimé) rappelant que le texte de l'attestation est codé dans la vue et que toute modification passe par le développeur.
- Feuille d'attestation : texte fixe + champs à compléter :
  - `contenteditable` (soulignés pointillés, hint = libellé) : nom/prénom, adresse, date et lieu de naissance, marque/modèle, immatriculation (forcée en **majuscules** — `text-transform` à l'affichage/impression, `.toUpperCase()` dans le PDF), ville et date de signature ;
  - `<select>` **Puissance administrative** (`—` / `3 CV` … `7 CV ou plus`) et `<select>` **Énergie** (7 `<optgroup>` : fossiles liquides, gaz fossiles, biocarburants, électricité, hybride, hydrogène, carburants de synthèse). Chaque `<option>` porte `data-elec="0|1"` ; seul « Batteries lithium-ion (100 % électrique) » a `data-elec="1"`. C'est ce flag (et non le libellé) qui écrit `ja.VehiculeElectrique`, le libellé choisi allant tel quel dans la ligne « Énergie : » du PDF.
- **Pré-remplissage** :
  - toujours (PHP) : nom de l'association (`Ligue {configuration.region} de Tennis de Table`), date du jour.
  - en mode JA (serveur, depuis `ja`) : nom/prénom, adresse (`{Cp} {Ville}`), ville, puissance (`ja.PuissanceFiscale`) ; l'option « Batteries lithium-ion (100 % électrique) » est présélectionnée si `ja.VehiculeElectrique = 1` **et** la puissance est déjà connue (sinon `—`, le carburant thermique exact n'étant pas stocké).
- **Case « Lu et approuvé »** (`#chk-approuve`) obligatoire ; fait partie du document imprimé/PDF.
- **Zone de signature** : `<canvas>` sur fond ligné, tracé souris / doigt / stylet (API **Pointer Events**, `touch-action: none`, HiDPI via `devicePixelRatio`), bouton **Effacer** ; sous ce bouton, le rappel de la marche à suivre (`.att-hint`, non imprimé).
- **Carte grise** (mode JA, facultatif — champ dans `#toolbar`) : `<input type="file" accept=".pdf,image/*">` lu en base64 (`FileReader`, ≤ 10 Mo), envoyé dans `carte_grise` ; type validé côté serveur par la signature du contenu (`%PDF-` / JPEG / PNG), écrit dans `_Defiscalisation/{Id_JA}_cg.{pdf|jpg|png}` (remplace l'existant).

### Actions
| Action | Méthode | Description |
|--------|---------|-------------|
| `index` | GET | Rend la page. `?ja=TOKEN` → mode JA (pré-remplissage) ; sinon session Défiscalisateur/Admin ; sinon `redirect(login)`. |
| `valider` | POST | **Token obligatoire.** Valide `puissance` ∈ {3,4,5,6,7} ou vide, `electrique` 0/1 ; décode `pdf` (dataURI, entête `%PDF-`, ≤ 10 Mo — `decoderJustificatif()`, tolère le préfixe jsPDF `;filename=…`) → `_Defiscalisation/{Id_JA}.pdf` ; si `carte_grise` fourni → `_Defiscalisation/{Id_JA}_cg.{ext}` ; puis `UPDATE ja SET PuissanceFiscale, VehiculeElectrique`. Retour `{ok, msg}`. CSRF : filtre global (cookie double-submit, sans session). |

### Génération du PDF (côté navigateur)
`asset/js/jspdf.umd.min.js` (jsPDF 2.5.2, vendoré — pas de Composer, se déploie comme un asset statique). Au clic sur **Valider** : contrôle des champs obligatoires + case « Lu et approuvé » + signature non vide ; mise en page manuelle du PDF avec l'API texte de jsPDF (paragraphes `splitTextToSize`, puces, `addImage` du PNG de la signature via `canvas.toDataURL`) → `doc.output('datauristring')`. Si une carte grise est jointe, elle est lue en base64 (`FileReader`) puis l'envoi part avec `pdf` **et** `carte_grise` ; sinon envoi direct. Côté serveur, l'attestation doit se décoder en **PDF** (`decoderJustificatif()` → `ext === 'pdf'`), la carte grise en PDF, JPEG ou PNG. Le bouton **Imprimer / PDF** (`window.print()` + CSS `@media print`) reste disponible pour une impression locale (les deux modes).

### Stockage
`_Defiscalisation/{Id_JA}.pdf` (attestation signée) + `_Defiscalisation/{Id_JA}_cg.{pdf|jpg|png}` (carte grise, si jointe), à la racine du dépôt. `_Defiscalisation/.htaccess` (`Require all denied`) bloque l'accès direct par URL ; la consultation passe par ED54 (`telecharger` / `carte-grise`).

### Règles
- Écriture BDD limitée à `ja.PuissanceFiscale` / `ja.VehiculeElectrique` (voir ED51). Les autres champs de l'attestation n'ont pas de colonne `ja` → PDF uniquement.
- Le nom de l'association suit la clé de configuration `region` (EA88/EA91).
- Le texte fixe de l'attestation est porté par la vue `attestation_defisc_index.php` : sa modification passe par une édition de code + déploiement (document à portée juridique, révisé rarement et à valider). En **mode staff uniquement**, un bandeau d'information (`.att-dev-note`, non imprimé) rappelle ce point au défiscalisateur.

---

## ED54 – Attestations reçues

**Fichier :** `AttestationsListeController` (CI4)  
**Accès :** rôle Defiscalisateur ou Administrateur (filtre `defiscauth`) — carte du menu E005

### Objectif
Consulter les documents déposés par les JA via ED53 dans le répertoire `_Defiscalisation/` : l'attestation signée (`{Id_JA}.pdf`) et, si elle a été jointe, la carte grise (`{Id_JA}_cg.{pdf|jpg|png}`).

### Interface
- Feuille blanche : titre + compteur, puis un tableau **N° JA · Nom · Prénom · Déposée le · actions**, trié par nom.
- Colonne actions : bouton **Attestation** (toujours) et bouton **Carte grise** (seulement si le fichier `{Id_JA}_cg.*` existe).
- Un JA dont l'`Id` de fichier n'a pas de ligne en base est affiché « JA inconnu ».
- État vide si le répertoire ne contient aucun `{n}.pdf`.

### Actions
| Action | Méthode | Description |
|--------|---------|-------------|
| `index` | GET | `scandir('_Defiscalisation/')` filtré sur `^\d+\.pdf$`, jointure `ja` (`Id_JA IN (…)`) sur l'Id extrait du nom de fichier ; par ligne : date de dépôt (`filemtime`), taille, et présence d'une carte grise (`glob('{id}_cg.*')`). |
| `telecharger/{Id_JA}` | GET | Sert `_Defiscalisation/{Id_JA}.pdf` en `inline` (`application/pdf`, `nosniff`). 404 sinon. Seul moyen d'ouvrir le fichier, `_Defiscalisation/.htaccess` refusant l'accès direct. |
| `carte-grise/{Id_JA}` | GET | Sert `_Defiscalisation/{Id_JA}_cg.*` en `inline` (Content-Type déduit de l'extension : pdf/jpeg/png). 404 si absent. Lien affiché dans la liste seulement quand le fichier existe. |

### Règles
- Lecture seule : aucune suppression / renommage depuis l'écran (les fichiers sont gérés par ED53 à la validation, ou par FTP).
- La liste reflète le contenu du répertoire à l'instant T, pas une table — un PDF supprimé par FTP disparaît de la liste.

---

## EA81 – Salles

**Fichier :** `salle.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Référencer les salles de compétition avec leur adresse et les rattacher à un club.

### Champs d'une fiche salle
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Nom | Texte | Oui |
| Adresse | Texte | Non |
| Code postal / Ville | Via `Id_Laposte` | Non |
| Club (Id_Club) | Sélecteur | Oui |
| Salle principale | Booléen | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne toutes les salles avec club et commune |
| `max_id` | GET | Retourne le prochain Id_Salle disponible |
| `liste_clubs` | GET | Retourne la liste des clubs pour le sélecteur |
| `importer_excel` | POST | Import depuis fichier Excel |
| `sauvegarder` | POST | Créer ou modifier une salle |
| `supprimer` | POST | Supprimer une salle |

### Règles
- Les coordonnées GPS sont héritées de la table `laposte` via `Id_Laposte`
- Un seul `EstPrincipale = 1` autorisé par club

---

## EA82 – Import Rencontres

**Fichier :** `ImportRencontresController` (CI4), portage de `import_rencontres.php`  
**Accès :** Administrateur uniquement (`adminauth`) — pas "Administrateur et Nominateur"

> Section réécrite pour refléter le portage CI4 : le mécanisme est passé d'un
> upload de fichier Excel dans `/Importation/` à un appel direct de l'API
> FFTT (flux Ligue → Épreuve → Division → Poules → Rencontres → BDD).

### Objectif
Importer les rencontres de la saison régionale directement depuis l'API FFTT (Smartping), sans passer par un fichier intermédiaire.

### Processus d'import
1. Résolution automatique de la ligue régionale (`chercher-ligue`, recherche par nom dans la liste des organismes FFTT)
2. Choix de l'épreuve (`charger-epreuves`, filtrée sur les épreuves récentes via le seuil `fftt_epreuve_min`, configurable en EA91)
3. Choix de la division FFTT à importer, avec correspondance automatique proposée vers une division NIJAC (`charger-divisions`)
4. Import de la division sélectionnée : poules, tours, rencontres — upsert par club/équipe, création des équipes/rencontres manquantes (`importer-division`)
5. Rapport détaillé (créations, doublons ignorés) renvoyé par l'appel d'import

### Actions AJAX
| Route | Méthode | Description |
|--------|---------|-------------|
| `import-rencontres/chercher-ligue` | POST | Retrouve la ligue régionale (clé `region` en configuration) parmi les organismes FFTT |
| `import-rencontres/charger-epreuves` | POST | Liste les épreuves d'un organisme, filtrées et dédoublonnées |
| `import-rencontres/charger-divisions` | POST | Liste les divisions FFTT d'une épreuve, avec suggestion de correspondance vers une division NIJAC |
| `import-rencontres/importer-division` | POST | Importe poules/rencontres d'une division FFTT vers la BDD (upsert) |
| `import-rencontres/liste-rencontres` | GET | Liste les rencontres déjà importées |
| `import-rencontres/candidats-arbitre` | GET | JA actifs du club recevant, pour désignation directe |
| `import-rencontres/designer-arbitre` | POST | Désigne un JA du club recevant sur une rencontre passée sans nomination et envoie sa convocation |

### Règles
- Les doublons (même Date + équipe domicile + équipe visiteur) sont ignorés silencieusement ; un 2ᵉ test bloque aussi la recréation d'une affiche déjà présente pour la même poule/journée à une autre date (rencontre reprogrammée)
- **Anti-doublon en base (anti-course)** : la table `rencontre` porte une clé `UNIQUE uq_rencontre_affiche (Id_EquipeDom, Id_EquipeExt, Phase)` posée par `initTableConfiguration()`, et l'`INSERT` est un `INSERT … ON DUPLICATE KEY UPDATE Date=VALUES(Date), Heure=VALUES(Heure), Poule=VALUES(Poule), Journee=VALUES(Journee)`. Sans elle, deux exécutions concurrentes de `importer-division` (double-clic, rejeu réseau, ou import EA83 sur la même division N*) passaient chacune le `SELECT` de dédup avant l'`INSERT` de l'autre et inséraient deux lignes identiques à l'`Id_Rencontre` près. Purge prod des doublons pré-existants + pose de la clé : `SQL/2026-09_uq_rencontre_affiche.sql`.
- La désignation directe (`designer-arbitre`) n'est pas restreinte aux divisions R3M/R4M : elle apparaît sur **toute** rencontre passée (`dateEstDepassee`) sans nomination (`NbNominations = 0`), quelle que soit la division — l'admin choisit alors un JA actif du club recevant (`candidats-arbitre`), qui reçoit sa convocation immédiatement. Elle ne dépend pas de `ArbitrageCRA`/`ArbitrageObligatoire` : c'est un rattrapage manuel de rencontre oubliée, distinct du circuit normal EN14/EN25

---

## EA83 – Import Rencontres Nationales

**Fichier :** `ImportRencontresNatController.php`  
**Accès :** Administrateur uniquement

### Objectif
Alimenter la table `equipe_nationale` (équipes des divisions nationales N1M/N2M/N3M/N1F/N2F et leur
club/département d'origine) et associer chaque équipe à un club NIJAC de la région. Deux onglets,
alimentant la même table :

- **Import via API** : interroge l'API FFTT en direct (scan des clubs de la région pour détecter leurs
  équipes nationales, puis chargement des divisions via `xml_epreuve`/`xml_division`).
- **Importation Excel/texte** : analyse un fichier déposé dans `Importation/Rencontres/Nationale/`
  (calendrier des journées + poules par division et par rang), au choix au format `.xlsx` (fichier FFTT
  à plusieurs feuilles — feuille 1 = calendrier, feuilles suivantes = poules) ou `.txt` (voir "Structure
  du fichier texte" ci-dessous). Méthode originelle de cet écran, utile en début de saison quand l'API
  FFTT n'a pas encore les poules alimentées. `parseNatFichier()` dispatche vers `parseNatExcel()` ou
  `parseNatTxt()` selon l'extension ; les deux retournent la même structure interne
  (`saison`/`journees`/`pools`/`avertissements`).

L'import des **rencontres** elles-mêmes (une fois les équipes associées à un club) se fait uniquement à
partir du calendrier persisté (table `nationale_calendrier`, action `importer-excel`) : aucun appel FFTT
pour cette étape, la date de chaque rencontre est déduite de la journée et de l'ordre de rang, appliqués
à chaque poule associée à l'étape 2. L'onglet API ne sert donc qu'à alimenter `equipe_nationale` (étapes
0/1) ; il n'a pas d'équivalent « importer les rencontres depuis l'API » — cette action a été retirée au
profit de la seule source Excel/texte.

### Persistance du calendrier (table `nationale_calendrier`)
`analyserExcel()` écrit le calendrier (`data.journees`) dans cette table à chaque analyse réussie
(`TRUNCATE` puis réinsertion complète — un seul calendrier actif à la fois, commun à toutes les
divisions/poules) : `Journee` (clé primaire), `Date`, `Ordre` (colonne `JSON`, ex: `[[1,8],[2,7],[3,6],[4,5]]`).
La saison (ex: "2026/2027") est dérivée de la clé `configuration.saison` (format "AAAA-AAAA", converti en
"AAAA/AAAA" — l'ancienne clé dédiée `nat_saison`, doublon de `saison`, a été supprimée). Cette persistance
remplace l'ancienne mémorisation côté navigateur (perdue au rechargement de la page) et permet à
`importerRencontresExcel()` de fonctionner sans avoir à reproposer le fichier d'origine — `getCalendrierPersiste()`
relit cette table. L'action GET `calendrier` restitue `{saison, journees}` au chargement de la page pour
réafficher le cartouche « Ordre des rencontres », réactiver l'étape 3 et permettre l'export .txt de
l'étape 2 sans nouvelle analyse.

### Structure du fichier Excel (`.xlsx`)
- Feuille 1 : calendrier (colonne A = n° journée, B = date, C = "Ordre des Rencontres" au format `rang-rang / rang-rang…`)
- Feuilles suivantes : une par division nationale, poules identifiées par un en-tête `POULE n`, équipes listées par rang

### Structure du fichier texte (`.txt`)
Alternative au `.xlsx`, utile quand seul un PDF de poules est disponible (recopié/nettoyé à la main —
chaque équipe sur sa propre ligne, donc aucun risque de colonnes fusionnées comme lors d'une conversion
PDF→texte brute) :

```
SAISON 2026/2027

CALENDRIER
1;19-sept;1-8/2-7/3-6/4-5
2;3-oct;7-1/6-2/5-3/8-4
...

NATIONALE 1 MESSIEURS
POULE 1
1;OUISTREHAM AP 1;07760123
2;PONTAULT COMBAULT UMS TT 1
...

POULE 2
1;NICE CAVIGAL 1
...

NATIONALE 2 MESSIEURS
POULE 1
...
```

- `CALENDRIER` bascule le parseur en lecture des journées : une ligne par journée, `n° journée;date;ordre
  des rencontres` (séparateur `;` ou tabulation).
- Une ligne reconnue comme intitulé de division (`NATIONALE x MESSIEURS/DAMES`, même détection que pour le
  `.xlsx`) bascule vers la lecture des poules de cette division ; `POULE n` démarre une nouvelle poule,
  chaque ligne suivante `rang;nom équipe[;Id_Club]` lui est rattachée. Le 3ᵉ champ (`Id_Club`, numéro FFTT
  à 8 caractères) est optionnel : à ne renseigner que pour les équipes dont le club est déjà connu
  (typiquement les clubs de la région, receveurs), pour éviter l'association manuelle de l'étape 2 —
  `analyser-excel` l'écrit dans `equipe_nationale.Id_Club`/`CodeDept` (dérivé des caractères 3-4 de
  l'Id_Club) sans écraser une association déjà faite si le fichier ne le précise pas.
- Une poule avec moins de 2 équipes n'est pas retenue et remonte en avertissement plutôt que d'être importée.
- Les lignes commençant par `#` sont ignorées (commentaires), ainsi que toute ligne ne correspondant à
  aucun des motifs ci-dessus.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `clubs-region` | GET | Liste les clubs de la région (onglet API) |
| `scanner-club` | POST | Détecte les équipes nationales d'un club (onglet API) |
| `charger-depuis-api` | POST | Charge les équipes nationales depuis l'API FFTT (onglet API) |
| `fichiers-excel` | GET | Liste les fichiers `.xlsx`/`.txt` disponibles dans `Importation/Rencontres/Nationale/` (alimente le sélecteur de l'étape 1 et la liste de liens de téléchargement de l'étape 4) |
| `analyser-excel` | POST | Analyse un fichier `.xlsx` ou `.txt`, insère les équipes dans `equipe_nationale` et persiste le calendrier dans `nationale_calendrier` (onglet Excel/texte) |
| `importer-excel` | POST | Importe les rencontres (receveur = club de la région) à partir du calendrier persisté (`nationale_calendrier`) — aucun fichier à reproposer (onglet Excel/texte) |
| `calendrier` | GET | Restitue le calendrier persisté (`{saison, journees}`), pour réafficher le cartouche/étape 3 après un rechargement de page |
| `exporter-txt` | POST | Enregistre l'état courant (calendrier + équipes/N° Club) dans `Importation/Rencontres/Nationale/YYYYMMJJ_Poules_Nationales.txt` (étape 2) |
| `equipes` | GET | Liste les équipes nationales connues (association, partagée par les deux onglets) |
| `recherche-club` | GET | Recherche un club pour l'association |
| `sauvegarder-assoc` | POST | Sauvegarde l'association équipe nationale → club/département |

### Règle spécifique
Seules les rencontres où l'équipe à domicile est associée à un club de la région sont importées ; l'équipe
adverse peut être normande ou hors région. Les rencontres déjà présentes (même date + mêmes équipes) sont ignorées.
Même filet anti-course qu'EA82 : clé `UNIQUE uq_rencontre_affiche (Id_EquipeDom, Id_EquipeExt, Phase)` + `INSERT … ON DUPLICATE KEY UPDATE`.
L'ordre des rencontres (ex. `1-8 / 2-7 / 3-6 / 4-5`) est un ordre générique de rang appliqué à chaque poule
(division/poule/rang enregistrés dans `equipe_nationale`) — pas de date par rencontre individuelle dans le
fichier, seulement une date par journée.

### Association automatique équipe → club
À chaque chargement (API ou Excel), pour chaque équipe sans club, une recherche exacte est faite dans la
table `club` sur le nom de l'équipe une fois le numéro final retiré (ex : "IGNY AP 1" → "IGNY AP"). Si un
seul club correspond, il est associé automatiquement et le département est dérivé du numéro FFTT du club
(caractères 3-4, ex : `07760123` → `76`). En cas d'ambiguïté (0 ou plusieurs clubs correspondants),
l'équipe reste à associer manuellement dans le tableau (colonnes N° Club / Club / Dépt).

### Export .txt de l'étape 2
Le bouton « Exporter en .txt » de l'étape 2 (action `exporter-txt`) génère côté serveur, à partir du
calendrier persisté (`nationale_calendrier`/`configuration.saison`) et de `equipe_nationale`
(équipes groupées par division/poule/rang avec leur `Id_Club` actuel, colonne N° Club), un fichier au
format documenté ci-dessus, et l'enregistre directement dans `Importation/Rencontres/Nationale/` sous le
nom `YYYYMMJJ_Poules_Nationales.txt` (date du jour). Permet de sauvegarder les associations club faites
manuellement, pour réimport ultérieur (saison suivante, ou après une purge accidentelle) sans avoir à
les refaire — le fichier réapparaît alors directement dans la liste de l'étape 1.

### Liste des fichiers de l'étape 4
L'étape 4 liste tous les fichiers `.xlsx`/`.txt` présents dans `Importation/Rencontres/Nationale/` (même
source que le sélecteur de l'étape 1, action `fichiers-excel`), chacun en lien de téléchargement direct.
Ce dossier est servi tel quel par le `.htaccess` racine (dossiers/fichiers réels non proxifiés vers CI4,
voir CLAUDE.md) : le téléchargement du fichier lui-même ne passe donc pas par le filtre `adminauth` —
seul l'appel qui liste les noms de fichiers y est soumis.

---

## EA85 – Saison / Nettoyage

**Fichier :** `clean.php`  
**Accès :** Administrateur uniquement

### Objectif
Préparer l'application pour une nouvelle saison : sauvegarde SQL puis vidage des tables de jeu, ou restauration depuis une sauvegarde.

### Fonctions disponibles

#### 1. Sauvegarde + nettoyage de phase
- Génère un fichier SQL dans `/SQL/` (horodaté)
- Désactive tous les JA (`JA1 = 0`)
- Vide les tables : `disponible`, `equipe`, `rencontre`, `nomination`
- Nécessite une confirmation admin

#### 2. Sauvegarde totale
- Dump complet de toutes les tables en SQL

#### 3. Restauration
- Sélection d'un fichier de sauvegarde dans `/SQL/`
- Confirmation par saisie du mot de passe administrateur
- Exécution ligne par ligne du SQL

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste_sauvegardes` | GET | Liste les fichiers de sauvegarde phase dans `/SQL/` |
| `liste_sauvegardes_total` | GET | Liste les sauvegardes totales |
| `supprimer_anciennes` | POST | Supprime les sauvegardes antérieures à une date |
| `restaurer_params_dev` | POST (`password`) | **Dev uniquement** : réapplique `etat_logiciel` et `email_developpement` mémorisés (`SQL/dev_params.json`) au début de la dernière restauration totale — instantané pris seulement s'il n'existe pas déjà, supprimé après application. Bouton sous « Restaurer toute la base de données » |
| `supprimer` | POST (`fichier`, `password`) | Supprime **un seul** fichier `Sauve_*`, `Full_*` ou `Table_*` (bouton « Supprimer ce fichier » sous chaque liste de l'onglet Restauration) ; mot de passe admin revérifié, nom validé par motif strict |
| `verifier_mdp` | POST | Vérifie le mot de passe avant restauration |
| `executer` | POST | Lance le nettoyage + sauvegarde phase |
| `sauvegarde_totale` | POST | Lance la sauvegarde complète |
| `restaurer` | POST | Restaure depuis un fichier phase |
| `restaurer_total` | POST | Restaure depuis un fichier total |
| `liste_tables_db` | GET | Liste les tables disponibles pour restauration partielle |
| `restaurer_table_full` | POST | Restaure une table spécifique depuis un backup |

---

## EA86 – Utilisateurs

**Fichier :** `utilisateur.php`  
**Accès :** Administrateur uniquement

### Objectif
Gérer les comptes utilisateurs de l'application (création, modification, suppression, droits).

### Formulaire — 3 cadres
- **Identifiant** : Id (lecture seule) + Login sur la même ligne
- **Information personnelle** : Prénom + Nom sur la même ligne, Adresse email, Rôle + Département sur la même ligne, case Actif
- **Écraser mot de passe** : une seule case **Écraser**. Cochée (ou création — case forcée), le serveur **génère un mot de passe aléatoire** conforme à `validerRobustesseMotDePasse()` (`genererMotDePasseAleatoire()`, 12 caractères) et passe le compte en `ChangeLogin = 1`. Le mot de passe en clair est renvoyé dans la réponse (`mdp`) et affiché à l'admin dans un encadré « Mot de passe provisoire à communiquer ». Case décochée en modification : ni `Password` ni `ChangeLogin` ne sont réécrits.

### Champs d'un utilisateur
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Login | Texte unique | Oui |
| Mot de passe | Généré (haché PBKDF2, jamais saisi) | Auto (création / case Écraser) |
| Nom | Texte | Oui |
| Prénom | Texte | Oui |
| Email | `utilisateur.Email` varchar(150) `NOT NULL` (migration EA98) — destinataire du code de sécurité de connexion (E010) | Oui |
| Rôle | ENUM `utilisateur.Role` (lu en base) | Oui |
| Département | Entier (ex : 76) | Oui |
| Actif | Booléen | Oui |
| `ChangeLogin` | Booléen | Forcé à 1 quand un mot de passe est généré |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `data` | GET | Retourne tous les utilisateurs (`data/{id}` pour un seul) |
| `store` | POST | Créer un utilisateur (mot de passe aléatoire généré, renvoyé dans `mdp`) |
| `update` | PUT | Modifier ; régénère le mot de passe si `ecraser=1` (renvoyé dans `mdp`) |
| `delete` | DELETE | Supprimer un utilisateur |

### Validations
- Login, Nom, Prénom, **Email**, Rôle, Département obligatoires — email requis côté client (`required`, `checkValidity()`) et serveur (non vide, 150 caractères max, `FILTER_VALIDATE_EMAIL`), en création comme en modification ; pas de contrôle d'unicité (des comptes partagent déjà une adresse)
- Un compte sans email valide ne peut pas se connecter tant que la double authentification est active (E001) : le corriger ici
- Rôles valides : lus dynamiquement dans l'ENUM `utilisateur.Role`
- Un utilisateur ne peut pas supprimer son propre compte

---

## EA87 – Communes

**Fichier :** `communes.php`  
**Accès :** Administrateur uniquement

### Objectif
Gérer le référentiel INSEE des codes postaux et communes utilisé pour la géolocalisation des salles et le calcul des distances domicile-salle.

### Champs d'une commune
| Champ | Type |
|-------|------|
| Id_LaPoste | Clé primaire auto |
| CodePostal | Texte (5 car.) |
| Nom | Texte |
| GPS (Latitude) | Décimal |
| GPS (Longitude) | Décimal |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne les communes (paginées ou filtrées) |
| `importer_csv` | POST | Import depuis fichier CSV La Poste |
| `exporter_csv` | GET | Export CSV de toute la table |
| `ajouter` | POST | Ajouter une commune manuellement |
| `modifier_coords` | POST | Modifier les coordonnées GPS d'une commune |
| `compter` | GET | Retourne le nombre total de communes |

### Règles
- Recherche (`q`) sur `CodePostal` / `Nom` / `Id_LaPoste` avec caractères génériques : `*` = plusieurs caractères, `?` = un seul (traduits en `%` / `_` SQL) ; les `%` `_` `\` littéraux saisis sont échappés. Sans joker, recherche « contient » (`%q%`).
- Pas de pagination : tout le résultat filtré est rendu sur une seule page (garde-fou serveur `LIMIT 20000`, message « résultat tronqué » au-delà). Barre du bas : nombre de communes + 5 boutons de saut rapide qui **défilent** la grille au 1/5, 2/5… des lignes affichées (masqués si < 100 lignes).

---

## EA88 – Régions

**Fichier :** `region.php`  
**Accès :** Administrateur uniquement

### Objectif
Référentiel des régions administratives, utilisé pour rattacher les départements (EA90).

### Champs d'une région
| Champ | Type | Obligatoire |
|-------|------|-------------|
| code | Texte (clé primaire, ex : `28`) | Oui, non modifiable après création |
| nom | Texte | Oui |
| Gentile | Texte (ex : `Normand(e)`) | Non |
| chef_lieu | Texte | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | POST | Retourne toutes les régions triées par nom |
| `charger` | GET | Charge une région par son code |
| `enregistrer` | POST | Créer (`is_new=1`) ou modifier une région |
| `supprimer` | POST | Supprime une région |

### Règles
- `code` et `nom` sont obligatoires à l'enregistrement
- Aucune vérification de dépendance à la suppression : un département référençant un code de région supprimé n'est pas bloqué (orphelin possible sur `departement.code_region`)

---

## EA89 – Divisions

**Fichier :** `division.php`  
**Accès :** Administrateur uniquement

### Objectif
Définir les divisions sportives et leur niveau hiérarchique, utilisés pour classer les rencontres et orienter les règles de nomination.

### Champs d'une division
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Division | Texte (ex : `N1M`, `R1M`) | Oui |
| Libellé | Texte | Oui |
| Niveau | Entier (ordre hiérarchique) | Non |
| Arbitrage CRA | Booléen (`ArbitrageCRA`, `TINYINT(1) NOT NULL DEFAULT 1`) | Oui — défaut structurel de la division : 1 = la CRA fournit le JA, 0 = à la charge du club. Seules `R3M` et `R4M` valent 0 ; toutes les autres divisions valent 1. Sert de valeur initiale à `equipe.ArbitrageCRA` (voir la règle unifiée dans EN18) |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne toutes les divisions triées par niveau |
| `charger` | GET | Charge une division par son Id |
| `enregistrer` | POST | Créer ou modifier une division |
| `supprimer` | POST | Supprimer une division |

### Relations en base
- PK `Division` `varchar(3)` (codes tous sur 3 caractères : `N1M`, `PNF`, `R4M`…) ; `Nom` et `Ord` sont `UNIQUE`. Les colonnes `equipe.Division` / `equipe_nationale.Division` sont aussi `varchar(3)`.
- **Contraintes FK déclarées** (`ON UPDATE CASCADE` / `ON DELETE RESTRICT`) :
  - `equipe.Division → division.Division` (`fk_equipe_division`)
  - `equipe_nationale.Division → division.Division` (`fk_equipenat_division`)
- Conséquences : renommer un code division propage aux équipes ; supprimer une division encore référencée par une équipe est bloqué en base (le contrôle applicatif `$divsValides` des écrans d'import reste en place).
- Les contraintes sont (re)créées de façon idempotente par `initTableConfiguration()` (config/app_config.php), appelée à l'ouverture d'EA98.
- `rencontre` n'a pas de colonne `Division` : le lien passe par `rencontre.Id_EquipeDom → equipe.Division → division.Division`.
- `rencontre.Frais` (`ENUM('Dom','Ext')`, défaut `Dom`, après `ArbitrageCRA`) : équipe supportant les frais du JA, édité dans EN23.
- `rencontre` porte aussi une clé `UNIQUE uq_rencontre_affiche (Id_EquipeDom, Id_EquipeExt, Phase)` (posée par `initTableConfiguration()`) : anti-doublon d'affiche pour les imports EA82/EA83, c'est l'invariant qu'utilise déjà le bouton « Doublons » d'EN23. `Id_EquipeExt` NULL (exempt / bye) : MySQL autorise plusieurs NULL dans un index UNIQUE, aucune collision.

---

## EA90 – Départements

**Fichier :** `departement.php`  
**Accès :** Administrateur uniquement

### Objectif
Référentiel des départements, rattachés à une région (EA88). Sert de base à la résolution des noms de département utilisée par d'autres écrans (import rencontres nationales, demandes JA R3M/R4M).

### Champs d'un département
| Champ | Type | Obligatoire |
|-------|------|-------------|
| **CodeDept** | `varchar(3)` (clé primaire, ex : `76`) | Oui, non modifiable après création — renommée depuis `code`, référencée par `utilisateur.Id_Departement` et `equipe_nationale.CodeDept` (FK) |
| nom | Texte (ex : `Seine-Maritime`) | Oui |
| code_region | Texte (référence logique vers `region.code` — **inchangé**, à ne pas confondre avec `CodeDept`) | Non |
| Limitrophe | Texte, codes séparés par `;` (ex : `14;27;60;80`) | Non — colonne auto-migrée/normalisée par `ensureLimitropheColumn()` |
| LimitropheRegion | Texte, codes séparés par `;` (ex : `14;27`) — **lecture seule** | Non stocké — champ calculé à la volée (`ajouterLimitropheRegion()`) : sous-ensemble de `Limitrophe` restreint aux départements de même `code_region` |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | POST | Retourne tous les départements avec le nom de région (jointure), triés par code numérique |
| `charger` | GET | Charge un département par son code |
| `liste_regions` | POST | Retourne les régions pour peupler le sélecteur |
| `enregistrer` | POST | Créer (`is_new=1`) ou modifier un département |
| `supprimer` | POST | Supprime un département |

### Règles
- `CodeDept` et `nom` sont obligatoires à l'enregistrement (formulaire : champ `CodeDept`)
- `code_region` n'est pas une contrainte FK déclarée en base : la cohérence est gérée applicativement, pas de blocage de suppression
- `CodeDept` est en revanche référencée par 2 FK (`fk_utilisateur_departement_code`, `fk_equipenat_dept`, `ON DELETE SET NULL` / `ON UPDATE CASCADE`) : renommer un code se propage, supprimer un département met à NULL les rattachements
- Distinct des listes de départements actifs (`departements_actifs`, EA91) et limitrophes (`departements_limitrophes`, EA91) : cette table est un référentiel de noms, pas un mécanisme de filtrage des écrans nominateur
- `Limitrophe` est éditable (champ « Départements limitrophes », codes séparés par `;`) ; `LimitropheRegion` n'est pas une colonne : il est recalculé à chaque réponse `data`/`charger` à partir de `Limitrophe` ∩ même région, affiché en lecture seule et non transmis à l'enregistrement

---

## EA91 – Configuration générale

**Fichier :** `configuration.php`  
**Accès :** Administrateur uniquement

### Objectif
Gérer les paramètres applicatifs stockés dans la table `configuration` (clé / valeur).

### Paramètres gérés
| Clé | Valeurs possibles | Description |
|-----|-------------------|-------------|
| `etat_logiciel` | `Opérationnel` / `Developpement` | Mode développement = emails redirigés |
| `email_developpement` | Email | Adresse cible en mode développement |
| `departements_actifs` | Ex : `14,27,50,61,76` | Départements affichés dans les sélecteurs |
| `regles_departements` | JSON ex : `{"76":["27"]}` | Inclusion automatique d'un département dans un autre |
| `departements_limitrophes` | CSV ex : `28,35,53,60,72,78,80,95` | Départements hors Normandie proposés en complément dans les sélecteurs de département (EN11, EN13) |
| `smtp_host` | Texte | Serveur SMTP |
| `smtp_port` | Entier | Port SMTP |
| `smtp_from` | Email | Adresse expéditeur |
| `smtp_from_name` | Texte | Nom expéditeur |
| `indemnite_forfaitaire` | Décimal | Indemnité forfaitaire JA (€) |
| `frais_kilometrique` | Décimal | Tarif au km (€) |
| `frais_max_peages` | Décimal | Plafond péages (€) |
| `frais_max_km` | Décimal | Plafond kilomètres indemnisables |
| `saison` | Ex : `2025-2026` | Saison en cours |
| `annee_fiscale` | Année 4 chiffres (2000-2100), ex : `2026` | Année civile de référence de la défiscalisation JA (ED51) — fenêtre 1ᵉʳ janv → 31 déc. Défaut = année système. Auto-heal `INSERT IGNORE` au chargement de l'écran. |
| `nomination_nb_candidats` | Entier ≥ 1, défaut `15` | Nombre de candidats JA listés par rencontre (EN14) |
| `nombre_arbitrage_national` | Entier ≥ 0, défaut `7` | EN26 « Prestations par club » : prestations JA dues par équipe en nationale. Créée par EA98 (`initTableConfiguration()`, `INSERT IGNORE`), éditable dans la table brute d'EA91 |
| `nombre_arbitrage_regional` | Entier ≥ 0, défaut `5` | EN26 « Prestations par club » : prestations JA dues par équipe en régionale. Idem |
| `double_authentification` | `1` / `0`, défaut `1` (clé absente = `1`) | E001/E010 : double authentification par email (`1`) ou **coupe-circuit** mot de passe seul (`0`, journalisé `[NIJAC][SEC]` à chaque connexion — panne SMTP, adresse erronée). Créée par EA98 (`INSERT IGNORE`, jamais écrasée), éditable dans la table brute d'EA91 (valeur contrôlée : `0` ou `1`). Requête d'urgence si plus personne ne peut se connecter : `UPDATE configuration SET valeur = '0' WHERE cle = 'double_authentification';` |
| `cra_dispo_choix_etendus` | `1` / `0`, défaut `1` (clé absente) | EC74 : « À confirmer » et « Disponible sous condition » proposés (`1`) ou non (`0`) à la saisie (page publique `dispo-cra`, saisie manuelle) — modifié par l'interrupteur d'EC74 |

L'utilisateur et le mot de passe SMTP (`SMTP_USER` / `SMTP_PASSWORD`) ne sont pas stockés dans
`configuration` : ils sont lus depuis `.env` (encodés ROT47, comme `DB_USER`/`DB_PASS`/`FFTT_APP_ID`/`FFTT_APP_KEY`),
pour éviter qu'ils apparaissent en clair dans un dump ou dans `db-admin.php` (EA98).

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `lire` | GET | Retourne tous les paramètres |
| `enregistrer` | POST | Met à jour un ou plusieurs paramètres |
| `smtp_test_prod` | POST | Envoie un email de test SMTP |
| `table_creer` | POST | Ajoute un paramètre personnalisé — **endpoint conservé mais plus déclenché par l'UI** (bouton « Ajouter une ligne » retiré de l'onglet « Gestion complète ») |
| `table_modifier` | POST | Modifie un paramètre existant (onglet « Gestion complète », double-clic sur une ligne) |
| `table_supprimer` | POST | Supprime un paramètre |

### Règle email
Toute sortie d'email dans l'application appelle `getEmailDestinataire($email)` :
- Si `etat_logiciel = Developpement` → retourne `email_developpement`
- Sinon → retourne l'adresse réelle

**Garantie centrale** : `getNijacMailer()` retourne un `NijacMailer` (`Classes/NijacMailer.php`, sous-classe de PHPMailer). En mode `Developpement`, `addAddress`/`addCC`/`addBCC`/`addReplyTo` remplacent toute adresse par `email_developpement` (une seule fois, dédoublonnage PHPMailer ; nom d'affichage conservé sauf s'il contient une adresse), même si le contrôleur a oublié `getEmailDestinataire()` ; les adresses réelles remplacées sont tracées dans l'en-tête `X-NIJAC-Dev-Original-To` (500 caractères max) et, à l'envoi, le sujet reçoit le préfixe `[DEV → n adresse(s) réelle(s)] ` s'il ne contient pas déjà `[DEV`. L'expéditeur est inchangé. Si `email_developpement` est vide (clé présente mais blanche), l'envoi est bloqué (exception « Mode Développement actif mais email_developpement non configuré : envoi bloqué »). En production, comportement PHPMailer strictement identique. S'applique aussi au test SMTP (`smtp_test_prod`).

---

## EA93 – Gestion des messages

**Fichier :** `Nominateur/messagerie.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Créer et gérer les modèles de messages utilisés pour les convocations, rappels et communications aux JA.

### Champs d'un message
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Type | Valeur de l'ENUM `messagerie.Type` (lu dynamiquement en base, ex. `Convocation`, `Demande adresse`, `Rappel`, `Annulation`, `Information`) | Oui |
| Sujet | Texte | Oui |
| Message | HTML / Texte, avec marqueurs (`{NOM}`, `{DATE}`, `{URL_ADRESSE_JA}`, `{YEAR_PHASE}`, etc.) | Oui |
| Id_Utilisateur | `NULL` = message système, sinon propriétaire nominateur | — |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne tous les modèles (système en tête, `Id_Messagerie` 1 à 6, puis les autres) |
| `charger` | GET | Charge un modèle par son Id |
| `enregistrer` | POST | Créer ou modifier un modèle (le `Type` doit correspondre à une valeur de l'ENUM) |
| `dupliquer` | POST | Duplique un modèle existant (système ou personnel) pour personnalisation |
| `supprimer` | POST | Supprime un modèle |

### Règles
- Les messages système (`Id_Utilisateur IS NULL` ou `Id_Messagerie` entre 1 et 6) ne sont modifiables/supprimables que par un administrateur ; un nominateur peut les dupliquer pour créer sa propre variante
- Un nominateur ne peut modifier/supprimer que ses propres messages personnels
- Les modèles système sont référencés par type depuis d'autres écrans : `Convocation` (EN15), `Demande adresse` (EN19)
- **Convocations CRA (EC73)** : 3 messages système `CRA Convocation JA`, `CRA Convocation JA + adjoint`, `CRA Convocation adjoint` (HTML complet ~53 Ko avec images base64, amorcés par EA98 depuis `Convocation/*.html`, voir EC73). Édités comme les autres dans le textarea (source HTML, pas de nettoyage côté serveur hormis `trim`) + bouton « Aperçu HTML » (iframe, marqueurs d'exemple de `MARQUEURS_EXEMPLE`, y compris les marqueurs CRA). Bloc de marqueurs « Convocation CRA (EC73) » affiché seulement quand le Type sélectionné commence par `CRA Convocation`. Non proposés par EN15 (onglets à Types fixes). Personnalisation = « Copier pour personnaliser » (copie prioritaire pour son propriétaire à l'envoi) ; revenir au modèle système = supprimer sa copie
- **Message n°3 « Convocation » (EN14)** : corps par défaut en HTML (`modeleHtmlConvocationJa()`), édité dans le même textarea + « Aperçu HTML » ; mis à niveau par EA98 seulement s'il n'a pas été personnalisé (voir EN14 « Modèle de convocation »)
- **Message système « Convocation clubs » (EN14)** : copie de la convocation aux correspondants/référents des deux clubs (voir EN14 « Copie de la convocation aux clubs »), HTML (`modeleHtmlCopieConvocationClubs()`, coordonnées du JA via `{COORDONNEES_JA}` / `{TEL_JA}` / `{EMAIL_JA}`), amorcé par EA98 (`assurerModeleCopieConvocationClubs()`, qui met aussi à niveau un corps système resté à l'ancienne version par défaut). Édité/copié comme les autres messages système (admin ; nominateur : copie perso prioritaire à l'envoi) ; non proposé par EN15 (onglets à Types fixes) ni au rôle « CRA Convoc ». Absent ou vide → EN14 utilise le modèle codé en dur (`modeleHtmlCopieConvocationClubs()` + `sujetParDefautCopieConvocationClubs()`). EA93 : à l'enregistrement d'un message de ce Type contenant `{URL_CONVOCATION_JA}` ou `{LIEN_CONVOCATION}` (sujet ou corps), avertissement non bloquant (toast orange « Ce message est envoyé aux clubs : n'y mettez pas le lien personnel du JA »), aucun nettoyage
- **Message système « Code de sécurité » (E010)** : email du code de double authentification (Sujet « Votre code de sécurité NIJAC », texte brut, seul marqueur `{CODE}`, Cc = 0, ReplyTo = 0), amorcé par EA98 (`assurerModeleCodeSecurite()`, jamais d'écrasement). Réservé aux administrateurs : invisible des autres rôles (liste et chargement), ni copie personnelle (« dupliquer » refusé pour tous), ni création/modification de ce Type par un non-administrateur ; absent des modèles d'EN15. Absent → repli sur le texte par défaut codé en dur (`corpsParDefautCodeSecurite()`)
- **Rôle « CRA Convoc »** (bouton EA93 « Modèles de convocation » du menu E009, route sous filtre `auth`) : ne voit que les messages `CRA Convocation …` (système en lecture seule + ses copies), peut les copier, modifier/supprimer ses copies (Type restant `CRA Convocation …`), ne peut pas créer de message ; retour vers E009

---

## EA96 – Test API FFTT

**Fichier :** `fftt_test.php`  
**Accès :** Administrateur (uniquement utilisateur `CHAUTARD`, même restriction que EA98)

### Objectif
Interface de diagnostic/débogage de l'intégration API FFTT (Smartping v2) : vérifier les identifiants, appeler manuellement chaque endpoint FFTT et inspecter la réponse brute (XML/JSON) sans écrire en base. Réutilise la même classe partagée `Classes/FfttApi.php` (factory `getFfttApi()`) que les écrans d'import réels (EN11, EN27, EA81, EA82, EA83) — il n'existe qu'un seul client FFTT dans l'application.

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `ping` | POST | Test minimal sans appel API (vérifie la chaîne JS → PHP) |
| `test_clubs_dep` | POST | Liste des clubs d'un département (`xml_club_dep2`) |
| `test_licence` | POST | Détail d'un licencié (`xml_licence`) |
| `test_equipes` | POST | Équipes d'un club (`xml_equipe`) |
| `test_club_detail` | POST | Détail complet d'un club (`xml_club_detail`) |
| `debug_club_salle` | POST | Analyse des champs salle d'un club (nom, adresse, code postal, ville) |
| `test_licence_b` | POST | Détail étendu + grades d'un licencié (`xml_licence_b`) |
| `test_arbitres_dep` | POST | Parcourt les clubs d'un département et collecte les licenciés avec grade d'arbitrage |
| `test_spid_club` | POST | Licenciés SPID d'un club (`xml_liste_joueur_o`) |
| `test_organisme` | POST | Liste des organismes (`xml_organisme`) |
| `test_epreuve` | POST | Épreuves d'un organisme (`xml_epreuve`) |
| `test_division` | POST | Divisions d'une épreuve (`xml_division`) |
| `test_poule` | POST | Poules d'une division (`xml_poule`) |
| `test_rencontre` | POST | Flux poules → rencontres (`xml_result_equ` puis `xml_rencontre_equ`) |
| `test_rencontre_poule` | POST | Rencontres d'une poule (`xml_result_equ`) |
| `test_chp_renc` | POST | Détail d'une rencontre (`xml_chp_renc`) |
| `test_result_equ` | POST | Résultats d'une équipe (`xml_result_equ`) |
| `test_equipe_nat` | POST | Analyse de la détection d'équipe nationale pour un club (logique réutilisée de EA83) |
| `scan_dept_nat` | POST | Scan complet d'un département pour repérer les clubs ayant une équipe nationale (opération longue, 2 à 5 min) |

### Règles
- Page strictement en lecture : aucune action n'effectue d'INSERT/UPDATE en base
- Identifiants lus via `getFfttAppId()` / `getFfttAppKey()` (`.env`), `serial` FFTT persisté dans la config (`fftt_serial`)

---

## EA98 – Administration base de données

**Fichier :** `DbAdminController` (CI4)  
**Accès :** Administrateur (uniquement utilisateur `CHAUTARD`)

> **Portage CI4** — la section ci-dessous décrit l'ancien `db-admin.php` (3 onglets
> Données/Structure/Requêteur, ~16 actions AJAX) et n'est plus à jour. La version CI4
> est un **écran unique « bris de glace »**, sans onglets, avec 3 actions : `index`,
> `tables`, `sql`.
>
> - **Barre latérale** : liste des tables + nombre de lignes (COUNT exact). Raccourcis
>   par table : clic sur le nom → `DESCRIBE`, sur le badge **« idx »** → `SHOW INDEX FROM`,
>   sur le compteur → `SELECT * … LIMIT 100`. Ces raccourcis passent par le requêteur
>   libre (`SHOW`/`DESCRIBE` sont dans la liste blanche de `DbAdminController::sql()`).
> - **Requêteur SQL libre** : textarea, exécution Ctrl+Entrée, plusieurs ordres séparés
>   par « ; », aucune restriction. Grille de résultats triable du dernier SELECT.
>   Double-clic sur une cellule → génère un `UPDATE` ciblé (SELECT mono-table, PK
>   détectée via `SHOW KEYS`).
> - `initTableConfiguration()` est rejoué à chaque ouverture de l'écran (best-effort).
> - Bandeau avec lien vers EA97 (BugSpid).

### Objectif
Interface d'administration directe de la base de données MySQL : consultation, édition, structure et requêtage libre.

### Interface
- **Sidebar gauche** : liste de toutes les tables avec nombre de lignes
- **Zone de travail** : 3 onglets

---

### Onglet Données (Browse)

| Fonctionnalité | Description |
|----------------|-------------|
| Parcourir | Affiche les lignes de la table sélectionnée |
| Recherche | Filtre plein-texte sur toutes les colonnes |
| Tri | Clic sur en-tête de colonne (ASC / DESC) |
| Pagination | 25 / 50 / 100 / 250 lignes par page |
| Créer | Formulaire de nouvelle ligne (types auto-détectés) |
| Modifier | Formulaire pré-rempli avec les valeurs existantes |
| Supprimer | Suppression avec confirmation |
| Export CSV | Télécharge **toute** la table en CSV (BOM UTF-8, séparateur `;`) |
| Vider (TRUNCATE) | Vide toutes les lignes avec double confirmation |

### Onglet Structure

| Fonctionnalité | Description |
|----------------|-------------|
| Liste des colonnes | Nom, type SQL, nullable, clé, valeur par défaut, extra |
| Badges de contraintes | PK, NN (not null), IDX, UNI |
| Renommer (R) | `ALTER TABLE … RENAME COLUMN` |
| Modifier le type | `ALTER TABLE … MODIFY COLUMN` (type, nullable, défaut, commentaire) |
| Supprimer | `ALTER TABLE … DROP COLUMN` (désactivé sur la clé primaire) |
| Ajouter une colonne | Nom, type, nullable, valeur par défaut, position (AFTER), commentaire |
| Index | Affichage de tous les index avec type (PRIMARY / UNIQUE / INDEX) et colonnes |
| Ajouter un index | Nom, colonnes (virgule-séparées), option UNIQUE |
| Supprimer un index | Bouton ✕ sur chaque index (PRIMARY protégé) |

### Onglet Requêteur SQL

| Fonctionnalité | Description |
|----------------|-------------|
| Éditeur SQL | Zone texte avec support Tab (indentation) et Shift+Tab (désindentation) |
| Exécuter | Ctrl+Entrée ou bouton Exécuter |
| Résultats SELECT | Tableau avec colonnes, lignes, temps d'exécution |
| Résultats écriture | Nombre de lignes affectées |
| Export CSV | Télécharge le résultat du dernier SELECT en CSV |
| Effacer | Réinitialise l'éditeur et le résultat |
| Erreurs SQL | Affichage du message d'erreur MySQL |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `tables` | GET | Liste toutes les tables avec statistiques |
| `describe` | GET | Structure d'une table (colonnes + index) |
| `browse` | GET | Données paginées d'une table |
| `get_row` | GET | Une ligne par sa clé primaire |
| `insert` | POST | Insérer une ligne |
| `update` | POST | Modifier une ligne |
| `delete` | POST | Supprimer une ligne |
| `sql` | POST | Exécuter une requête SQL libre |
| `export_csv` | GET | Télécharger toute une table en CSV |
| `truncate` | POST | Vider une table (TRUNCATE) |
| `add_column` | POST | Ajouter une colonne |
| `modify_column` | POST | Modifier une colonne |
| `drop_column` | POST | Supprimer une colonne |
| `rename_column` | POST | Renommer une colonne |
| `add_index` | POST | Créer un index |
| `drop_index` | POST | Supprimer un index |

### Sécurité
- Accès conditionnel : `$_SESSION['utilisateur']['login'] === 'CHAUTARD'`
- CSRF vérifié sur toutes les actions POST
- Tous les noms de tables et colonnes sont validés par regex `^\w+$` avant injection dans les requêtes
- Les noms de colonnes dans `insert` / `update` sont comparés à la liste réelle de `DESCRIBE` avant utilisation

