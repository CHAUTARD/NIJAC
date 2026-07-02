# NIJAC – Spécifications fonctionnelles

> Document de référence pour l'ensemble des écrans de l'application.
> Voir aussi [Ecrans.md](Ecrans.md) pour le tableau synthétique et [README.md](README.md) pour l'architecture.

---

## Table des matières

- [E001 – Connexion](#e001--connexion)
- [E002 – Menu administrateur](#e002--menu-administrateur)
- [E005 – Salles](#e005--salles)
- [E006 – Communes](#e006--communes)
- [E007 – Juges-Arbitres](#e007--juges-arbitres)
- [E008 – Clubs / Associations](#e008--clubs--associations)
- [E009 – Utilisateurs](#e009--utilisateurs)
- [E010 – Divisions](#e010--divisions)
- [E011 – Import Rencontres](#e011--import-rencontres)
- [E012 – Régions](#e012--régions)
- [E013 – Départements](#e013--départements)
- [E015 – Configuration générale](#e015--configuration-générale)
- [E016 – Saison / Nettoyage](#e016--saison--nettoyage)
- [E017 – Import Rencontres Nationales](#e017--import-rencontres-nationales)
- [E018 – Test API FFTT](#e018--test-api-fftt)
- [E020 – Menu nominateur](#e020--menu-nominateur)
- [E021 – Disponibilités JA](#e021--disponibilités-ja)
- [E022 – Nomination JA](#e022--nomination-ja)
- [E023 – Désidératas club](#e023--désidératas-club)
- [E024 – Centre d'envoi](#e024--centre-denvoi)
- [E025 – Comptabilité frais JA](#e025--comptabilité-frais-ja)
- [E026 – Messagerie](#e026--messagerie)
- [E027 – Désidératas clubs](#e027--désidératas-clubs)
- [E028 – Statistiques JA](#e028--statistiques-ja)
- [E029 – Adresse domicile JA](#e029--adresse-domicile-ja)
- [E030 – Fiche personnelle JA](#e030--fiche-personnelle-ja)
- [E099 – Administration base de données](#e099--administration-base-de-données)

---

## E001 – Connexion

**Fichier :** `index.php`  
**Accès :** Public (non authentifié)

### Objectif
Point d'entrée unique de l'application. Authentifie l'utilisateur et initialise la session.

### Interface
- Champ **Login** (texte) — pour un JA, il s'agit de son **Nom** de famille (voir plus bas)
- Champ **Mot de passe** (password, bouton afficher/masquer) — pour un JA, il s'agit de son **numéro de licence**
- Bouton **Se connecter**
- Zone de statut (message d'erreur ou de succès)

### Comportement
| Situation | Résultat |
|-----------|----------|
| Déjà connecté | Redirige immédiatement vers E002 (Admin), E020 (Nominateur) ou E030 (JA) |
| Login ou MDP vide | Message d'avertissement, pas d'appel base |
| Identifiants invalides ou compte inactif | Message `Échec : Identifiants invalides.` |
| Connexion réussie + rôle Admin | Redirection vers `admin_menu.php` (E002) |
| Connexion réussie + rôle Nominateur | Redirection vers `Nominateur/menu.php` (E020) |
| Connexion réussie + rôle JA | Redirection vers `JA/info_rencontre.php` (E030) |
| Erreur base de données | Message système, log PHP |

### Connexion Juge-Arbitre (rôle `JA`)
Si le login ne correspond à aucun `Utilisateur` existant, l'application tente une authentification JA :
1. Recherche un `ja` actif dont `Nom` correspond au login saisi (comparaison insensible à la casse/espaces).
2. Vérifie que le mot de passe saisi correspond exactement à `Id_JA` (le numéro de licence sert de mot de passe).
3. **Contrôle d'accès métier** : le club du JA doit avoir au moins une rencontre en division `R3M`/`R4M` (ou `division.ArbitrageCRA = 1`, ou `equipe.JAdemande = 1`) dans une fenêtre de `CURDATE() ± 5 jours`. Sinon, l'accès est refusé et le compte `Utilisateur` associé (s'il existe) est supprimé.
4. Si l'accès est autorisé et qu'aucun compte `Utilisateur` n'existe pour ce login, un compte est créé automatiquement : `Login = ja.Nom`, `Password = hash(licence)`, `Role = 'JA'`, `Id_Departement` déduit des 2 premiers chiffres du `Id_Club`, `Actif = 1`.

### Session créée
```php
// Administrateur / Nominateur
$_SESSION['utilisateur'] = [
    'id'             => int,
    'login'          => string,
    'nom'            => string,
    'prenom'         => string,
    'role'           => 'Administrateur' | 'Nominateur',
    'id_departement' => string,
    'change_login'   => bool,
    'is_admin'       => bool,
]

// Juge-Arbitre (E030)
$_SESSION['utilisateur'] = [
    // ... mêmes clés que ci-dessus, avec :
    'role'  => 'JA',
    'id_ja' => string,   // numéro de licence (= Id_JA), utilisé comme identifiant métier
]
```

### Sécurité
- Protection CSRF sur le formulaire POST (`csrfVerify(false)`)
- Mot de passe vérifié via `SecurePasswordHasher::verify()` (bcrypt)
- `session_regenerate_id(true)` après authentification réussie

---

## E002 – Menu administrateur

**Fichier :** `admin_menu.php`  
**Accès :** Administrateur uniquement

### Objectif
Page d'accueil de l'espace administrateur. Donne accès à tous les écrans de paramétrage.

### Interface
- Barre utilisateur : nom, département, alerte changement de mot de passe
- Bouton **Menu nominateur** (bascule vers E020)
- Grille de boutons (5 colonnes) avec code écran en haut à droite de chaque bouton :

| Bouton | Code | Destination |
|--------|------|-------------|
| Club / Association | E008 | `club.php` |
| Salle | E005 | `salle.php` |
| Utilisateur | E009 | `utilisateur.php` |
| Communes | E006 | `communes.php` |
| Division | E010 | `division.php` |
| Import Rencontres | E011 | `import_rencontres.php` |
| Import Rencontres Nationales | E017 | `import_rencontres_nat.php` |
| Régions | E012 | `region.php` |
| Départements | E013 | `departement.php` |
| Saison | E016 | `clean.php` |
| Configuration | E015 | `configuration.php` |
| Test API FFTT *(CHAUTARD seulement)* | E018 | `fftt_test.php` |
| Base de données *(CHAUTARD seulement)* | E099 | `db-admin.php` |
| Se déconnecter | — | `logout.php` |

### Règles
- Les boutons **Test API FFTT** (E018) et **Base de données** (E099) ne sont visibles que si `$_SESSION['utilisateur']['login'] === 'CHAUTARD'`
- Le bouton **Se déconnecter** demande une confirmation JavaScript

> **Note :** l'ancien écran E004 (Correspondants de clubs, `correspondant.php`) a été supprimé. La gestion des correspondants est désormais intégrée à l'écran E008 (Clubs / Associations), sous forme de colonnes directement sur la fiche club.

---

## E005 – Salles

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

## E006 – Communes

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

---

## E007 – Juges-Arbitres

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
| Actif | Booléen | Oui |
| Défiscalisation | Booléen | Non |
| Nationale | Booléen (Oui/Non) | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne les JA filtrés par département |
| `recherche_laposte` | GET | Recherche de commune pour le sélecteur |
| `importer_excel` | POST | Import depuis fichier Excel FFTT (upsert par licence) |
| `clubs_par_dept` | GET | Liste des clubs du département |
| `maj_laposte` | POST | Met à jour `Id_LaPoste` d'un JA |
| `maj_bdd` | POST | Créer ou modifier un JA |

### Import Excel
- Colonnes attendues : N° licence, Nom, Prénom, Grade, Club, Code postal, Ville
- Comportement : upsert sur le N° licence
- Normalisation automatique du nom de ville via la table `laposte`

### Règles
- Seuls les JA avec `Actif = 1` sont proposés à la nomination (E022)
- Le département d'un JA est déterminé par le code postal de sa salle principale de club
- Le sélecteur de département (filtre liste + import FFTT) propose, en plus des départements actifs (`getDeptActifs()`), un groupe **« Départements limitrophes »** alimenté par `getDepartementsLimitrophes()` — liste paramétrable via la clé `departements_limitrophes` en E015 (par défaut `28,35,53,60,72,78,80,95`). Ce mécanisme est distinct de la règle 76→27 (`regles_departements`, voir E015) : il permet de gérer des JA rattachés à des départements hors Normandie qui interviennent occasionnellement en Normandie, plutôt qu'une inclusion automatique entre deux départements normands.

---

## E008 – Clubs / Associations

**Fichier :** `club.php`  
**Accès :** Administrateur uniquement

### Objectif
Importer et gérer la liste des clubs affiliés à la ligue Normandie.

### Champs d'un club
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Id_Club | Texte (N° FFTT, ex : `07614001`) | Oui |
| Nom | Texte | Oui |
| CorNom | Texte (nom du correspondant) | Non |
| CorEmail | Email | Non |
| CorTelephone | Texte | Non |

> Les colonnes correspondant (`CorNom`, `CorEmail`, `CorTelephone`) remplacent l'ancien écran E004 (table `Correspondant` séparée, supprimée). À la première utilisation, une migration copie automatiquement le correspondant existant de chaque club (le plus ancien s'il y en a plusieurs) vers ces colonnes.

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

## E009 – Utilisateurs

**Fichier :** `utilisateur.php`  
**Accès :** Administrateur uniquement

### Objectif
Gérer les comptes utilisateurs de l'application (création, modification, suppression, droits).

### Champs d'un utilisateur
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Login | Texte unique | Oui |
| Mot de passe | Texte (haché bcrypt) | Oui (création) |
| Nom | Texte | Non |
| Prénom | Texte | Non |
| Rôle | `Administrateur` \| `Nominateur` | Oui |
| Département | Entier (ex : 76) | Non |
| Actif | Booléen | Oui |
| Forcer changement MDP | Booléen (`ChangeLogin`) | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne tous les utilisateurs |
| `charger` | GET | Charge un utilisateur par son Id |
| `enregistrer` | POST | Créer ou modifier un utilisateur |
| `supprimer` | POST | Supprimer un utilisateur |

### Validations
- Login obligatoire et unique
- Mot de passe obligatoire en création ; facultatif en modification (si vide, non modifié)
- Rôles valides : `Administrateur`, `Nominateur`
- Un utilisateur ne peut pas supprimer son propre compte

---

## E010 – Divisions

**Fichier :** `division.php`  
**Accès :** Administrateur uniquement

### Objectif
Définir les divisions sportives et leur niveau hiérarchique, utilisés pour classer les rencontres et orienter les règles de nomination.

### Champs d'une division
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Id_Division | Texte (ex : `N1M`, `R1M`) | Oui |
| Libellé | Texte | Oui |
| Niveau | Entier (ordre hiérarchique) | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne toutes les divisions triées par niveau |
| `charger` | GET | Charge une division par son Id |
| `enregistrer` | POST | Créer ou modifier une division |
| `supprimer` | POST | Supprimer une division |

---

## E011 – Import Rencontres

**Fichier :** `import_rencontres.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Importer les rencontres de la saison régionale depuis des fichiers Excel FFTT déposés dans le dossier `/Importation/`.

### Processus d'import
1. Upload du fichier Excel dans `/Importation/`
2. Aperçu des données avant import (colonnes détectées, nombre de lignes)
3. Import avec upsert : mise à jour si la rencontre existe, création sinon
4. Rapport : lignes importées, doublons ignorés, anomalies (club inconnu, salle manquante)

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `upload` | POST | Dépose le fichier Excel dans `/Importation/` |
| `supprimer` | POST | Supprime un fichier de la liste |
| `liste` | GET | Liste les fichiers disponibles dans `/Importation/` |
| `apercu` | POST | Retourne un aperçu des données du fichier sélectionné |
| `importer` | POST | Exécute l'import en base (upsert) |

### Colonnes attendues dans le fichier FFTT
Saison, Journée, Date, Division, Équipe domicile (Id_Club), Équipe visiteur, Id_Salle

### Règles
- Les rencontres dont la salle ou le club est inconnu sont signalées mais pas bloquées
- Les doublons (même Saison + Journée + Division + Équipes) sont ignorés silencieusement

---

## E012 – Régions

**Fichier :** `region.php`  
**Accès :** Administrateur uniquement

### Objectif
Référentiel des régions administratives, utilisé pour rattacher les départements (E013).

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

## E013 – Départements

**Fichier :** `departement.php`  
**Accès :** Administrateur uniquement

### Objectif
Référentiel des départements, rattachés à une région (E012). Sert de base à la résolution des noms de département utilisée par d'autres écrans (import rencontres nationales, demandes JA R3M/R4M).

### Champs d'un département
| Champ | Type | Obligatoire |
|-------|------|-------------|
| code | Texte (clé primaire, ex : `76`) | Oui, non modifiable après création |
| nom | Texte (ex : `Seine-Maritime`) | Oui |
| code_region | Texte (référence logique vers `region.code`) | Non |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | POST | Retourne tous les départements avec le nom de région (jointure), triés par code numérique |
| `charger` | GET | Charge un département par son code |
| `liste_regions` | POST | Retourne les régions pour peupler le sélecteur |
| `enregistrer` | POST | Créer (`is_new=1`) ou modifier un département |
| `supprimer` | POST | Supprime un département |

### Règles
- `code` et `nom` sont obligatoires à l'enregistrement
- `code_region` n'est pas une contrainte FK déclarée en base : la cohérence est gérée applicativement, pas de blocage de suppression
- Distinct des listes de départements actifs (`departements_actifs`, E015) et limitrophes (`departements_limitrophes`, E015) : cette table est un référentiel de noms, pas un mécanisme de filtrage des écrans nominateur

---

## E015 – Configuration générale

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
| `departements_limitrophes` | CSV ex : `28,35,53,60,72,78,80,95` | Départements hors Normandie proposés en complément dans les sélecteurs de département (E007, E021) |
| `smtp_host` | Texte | Serveur SMTP |
| `smtp_port` | Entier | Port SMTP |
| `smtp_from` | Email | Adresse expéditeur |
| `smtp_from_name` | Texte | Nom expéditeur |
| `indemnite_forfaitaire` | Décimal | Indemnité forfaitaire JA (€) |
| `frais_kilometrique` | Décimal | Tarif au km (€) |
| `frais_max_peages` | Décimal | Plafond péages (€) |
| `frais_max_km` | Décimal | Plafond kilomètres indemnisables |
| `saison` | Ex : `2025-2026` | Saison en cours |

L'utilisateur et le mot de passe SMTP (`SMTP_USER` / `SMTP_PASSWORD`) ne sont pas stockés dans
`configuration` : ils sont lus depuis `.env` (encodés ROT47, comme `DB_USER`/`DB_PASS`/`FFTT_APP_ID`/`FFTT_APP_KEY`),
pour éviter qu'ils apparaissent en clair dans un dump ou dans `db-admin.php` (E099).

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `lire` | GET | Retourne tous les paramètres |
| `enregistrer` | POST | Met à jour un ou plusieurs paramètres |
| `smtp_test_prod` | POST | Envoie un email de test SMTP |
| `table_creer` | POST | Ajoute un paramètre personnalisé |
| `table_modifier` | POST | Modifie un paramètre existant |
| `table_supprimer` | POST | Supprime un paramètre |

### Règle email
Toute sortie d'email dans l'application appelle `getEmailDestinataire($email)` :
- Si `etat_logiciel = Developpement` → retourne `email_developpement`
- Sinon → retourne l'adresse réelle

---

## E016 – Saison / Nettoyage

**Fichier :** `clean.php`  
**Accès :** Administrateur uniquement

### Objectif
Préparer l'application pour une nouvelle saison : sauvegarde SQL puis vidage des tables de jeu, ou restauration depuis une sauvegarde.

### Fonctions disponibles

#### 1. Sauvegarde + nettoyage de phase
- Génère un fichier SQL dans `/SQL/` (horodaté)
- Désactive tous les JA (`Actif = 0`)
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
| `verifier_mdp` | POST | Vérifie le mot de passe avant restauration |
| `executer` | POST | Lance le nettoyage + sauvegarde phase |
| `sauvegarde_totale` | POST | Lance la sauvegarde complète |
| `restaurer` | POST | Restaure depuis un fichier phase |
| `restaurer_total` | POST | Restaure depuis un fichier total |
| `liste_tables_db` | GET | Liste les tables disponibles pour restauration partielle |
| `restaurer_table_full` | POST | Restaure une table spécifique depuis un backup |

---

## E017 – Import Rencontres Nationales

**Fichier :** `import_rencontres_nat.php`  
**Accès :** Administrateur uniquement

### Objectif
Importer les rencontres des divisions nationales depuis un fichier Excel FFTT multi-feuilles.

### Structure du fichier
- 6 feuilles : `N1M`, `N2M`, `N3M`, `N1D`, `N2D` (+ éventuellement une feuille supplémentaire)
- Colonnes : Saison, Journée, Date, Équipe domicile, Équipe visiteur

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste_fichiers` | GET | Liste les fichiers disponibles dans `/Importation/` |
| `analyser` | POST | Analyse un fichier et retourne les équipes non associées |
| `liste_equipes` | GET | Liste les équipes nationales connues |
| `recherche_club` | GET | Recherche un club pour l'association |
| `sauvegarder_assoc` | POST | Sauvegarde l'association équipe nationale → club |
| `assigner_hors_region` | POST | Marque une équipe comme hors région (pas de JA) |
| `importer` | POST | Exécute l'import en base |
| `remplir_rencontres` | POST | Complète les rencontres existantes avec les données nationales |

### Règle spécifique
Avant l'import, toutes les équipes non reconnues doivent être associées manuellement à un club NIJAC ou marquées « hors région ». L'import est bloqué tant que des équipes restent sans association.

---

## E018 – Test API FFTT

**Fichier :** `fftt_test.php`  
**Accès :** Administrateur (uniquement utilisateur `CHAUTARD`, même restriction que E099)

### Objectif
Interface de diagnostic/débogage de l'intégration API FFTT (Smartping v2) : vérifier les identifiants, appeler manuellement chaque endpoint FFTT et inspecter la réponse brute (XML/JSON) sans écrire en base. Réutilise la même classe partagée `Classes/FfttApi.php` (factory `getFfttApi()`) que les écrans d'import réels (E007, E008, E005, E011, E017) — il n'existe qu'un seul client FFTT dans l'application.

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
| `test_equipe_nat` | POST | Analyse de la détection d'équipe nationale pour un club (logique réutilisée de E017) |
| `scan_dept_nat` | POST | Scan complet d'un département pour repérer les clubs ayant une équipe nationale (opération longue, 2 à 5 min) |

### Règles
- Page strictement en lecture : aucune action n'effectue d'INSERT/UPDATE en base
- Identifiants lus via `getFfttAppId()` / `getFfttAppKey()` (`.env`), `serial` FFTT persisté dans la config (`fftt_serial`)

---

## E020 – Menu nominateur

**Fichier :** `Nominateur/menu.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Page d'accueil de l'espace nominateur avec tableau de bord et accès aux fonctions de nomination.

### Tableau de bord (calculs au chargement)
| Indicateur | Description |
|------------|-------------|
| Prochaine journée | Date + numéro de journée des rencontres à venir du département |
| JA actifs | Nombre de JA avec `Actif = 1` dans le département |
| Nominations à valider | Nominations avec `Valide = 0` sur des rencontres futures |
| Convocations à envoyer | Nominations `Valide = 1` et `EmailEnvoye = 0` sur rencontres futures |
| Rencontres sans JA | Rencontres futures sans nomination validée |

Les indicateurs affichent un **badge rouge** sur le bouton de menu correspondant si la valeur est > 0.

### Boutons de menu
| Bouton | Code | Destination |
|--------|------|-------------|
| Juge-Arbitre | E007 | `jugearbitre.php` |
| Disponibilités JA | E021 | `disponibilites.php` |
| Nomination JA | E022 | `nomination.php` |
| Messagerie | E026 | `messagerie.php` |
| Centre d'envoi | E024 | `centrenvoye.php` |
| Comptabilité | E025 | `compta.php` |
| Désidératas clubs | E027 | `JA_R3R4.php` |
| Statistiques JA | E028 | `stats_ja.php` |
| Se déconnecter | — | `../logout.php` |

### Règle département Seine-Maritime (76)
Le département 76 inclut automatiquement l'Eure (27) dans tous les calculs, configuré via `regles_departements` dans la table `configuration`.

---

## E021 – Disponibilités JA

**Fichier :** `Nominateur/disponibilites.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Consulter et modifier les disponibilités des JA par département et par journée.

### Interface
- Sélecteur de département : groupe **« Normandie »** (14, 27, 50, 61, 76) et groupe **« Départements limitrophes »** (liste de `getDepartementsLimitrophes()`, paramétrable via `departements_limitrophes` en E015)
- Grille des JA du/des département(s) sélectionné(s) avec leur disponibilité par journée
- Clic sur un JA → ouvre `disponibilite_ja.php?id_ja=...` dans une nouvelle fenêtre pour la saisie détaillée par journée

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `ja_dept` | GET | Retourne les JA actifs des départements sélectionnés (`depts`, liste séparée par virgules) avec leurs disponibilités |

### Règle département 76
La sélection du département 76 inclut automatiquement les JA du 27.

### Tokenisation des liens
Les liens vers `disponibilite_ja.php` utilisent un token obfusqué (8 caractères) au lieu de l'Id_JA réel, généré par `Obfuscator(OBFUSCATOR_SEED=167)`.

---

## E022 – Nomination JA

**Fichier :** `Nominateur/nomination.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Affecter les JA disponibles aux rencontres de la saison en appliquant les règles métier de nomination.

### Interface
- Sélecteur de journée
- Liste des rencontres de la journée avec statut de nomination
- Pour chaque rencontre : liste des JA candidats triés par priorité
- Boutons : Affecter, Retirer, Valider, Envoyer convocations

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `journees` | GET | Retourne les journées disponibles pour le département |
| `rencontres_journee` | GET | Retourne les rencontres d'une journée avec nominations |
| `candidats_journee` | GET | Retourne les JA candidats pour une rencontre (triés par règles) |
| `affecter_ja` | POST | Nomme un JA sur une rencontre |
| `retirer_ja` | POST | Retire la nomination d'un JA (`DELETE FROM nomination WHERE Id_Rencontre = ?`) |
| `valider_nominations` | POST | Valide les nominations de la journée (`Valide = 1`) |
| `envoyer_convocations` | POST | Envoie les emails de convocation aux JA validés |

### Modèle de données (`nomination` → `disponible`)
Depuis la migration décrite dans le commit *« modification dans la table nomination de id_ja par id_disponible »*, la table `nomination` ne référence plus directement `ja.Id_JA` mais **`disponible.Id_Disponible`** (`nomination.Id_Disponible`). Le JA nominé s'obtient par jointure `nomination → disponible → ja`. Une contrainte d'unicité `uq_nomination_rencontre` sur `nomination.Id_Rencontre` garantit qu'**une rencontre ne peut avoir qu'une seule nomination**.

Deux fonctions internes portent cette logique dans `nomination.php` :
- `resoudreDisponible($pdo, $idJa, $idRenc, $dateRenc)` : trouve/crée la ligne `disponible` à utiliser — priorité à une réponse précise sur la rencontre (`Reponse='O'`), sinon une disponibilité « toute la journée » (`Id_Rencontre IS NULL`) qu'elle matérialise en ligne précise, sinon retourne `null` (JA non disponible → nomination refusée)
- `affecterNomination($pdo, $idRenc, $idDispo)` : crée la nomination si absente ; si un autre JA était déjà nominé, réinitialise `Peage`, `Kilometre`, `RapportAccueil`, `RapportEquipements`, `DateSaisie`

### Règles métier de nomination
1. **Exclusion club** : un JA ne peut pas arbitrer une rencontre où son club joue (domicile ou visiteur)
2. **Max rencontres par club / phase** : un JA ne peut pas arbitrer plus de 2 rencontres du même club sur une phase
3. **Unicité par date** : un JA ne peut arbitrer qu'une seule rencontre par date (vérifié par jointure `nomination → disponible` sur la même date)
4. **Unicité par rencontre** : une rencontre ne peut avoir qu'un seul JA nominé (contrainte `uq_nomination_rencontre`)
5. **Priorité disponibilité déclarée** : les rencontres choisies par le JA dans ses disponibilités sont prioritaires
6. **Proximité géographique** : en cas d'égalité, la rencontre la plus proche du domicile du JA est privilégiée
7. **Équité** : priorité au JA ayant le moins d'arbitrages validés sur la phase en cours
8. **Double rencontre en salle** : si un JA est affecté à une rencontre, une 2ᵉ rencontre dans la même salle le même jour (hors rencontres où son club joue) lui est automatiquement proposée via `resoudreDisponible` / `affecterNomination`, une seule à la fois

---

## E024 – Centre d'envoi

**Fichier :** `Nominateur/centrenvoye.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Envoyer les messages aux JA actifs du département (convocations, rappels, annulations, informations).

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

### Règle email
- `EmailEnvoye` passe à `1` après envoi réussi
- En mode `Developpement`, tous les emails sont redirigés vers `email_developpement`
- Le lien dans l'email vers la convocation officielle est tokenisé (Obfuscator)

---

## E025 – Comptabilité frais JA

**Fichier :** `Nominateur/compta.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Générer le récapitulatif des frais de déplacement des JA pour une période et l'exporter pour le logiciel comptable EBP.

### Interface
- Sélecteur de période (date début / date fin)
- Tableau récapitulatif : JA, rencontres arbitrées, kilométrage, péages, indemnité forfaitaire, total
- Bouton export CSV

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `donnees` | POST | Retourne les frais par JA pour la période |
| `export_csv` | POST | Retourne le CSV au format EBP (le fichier est ensuite déclenché en téléchargement côté client) |

### Calcul des frais
- **Indemnité forfaitaire** : `COUNT(nominations validées) × configuration.indemnite_forfaitaire`
- **Frais kilométriques + péages** : `SUM(Kilometre) × frais_kilometrique + SUM(Peage)`
- Nominations prises en compte : `Valide = 1` OU `Peage`/`Kilometre` renseignés, sur la période sélectionnée

### Export CSV (format EBP)
- Une ligne d'en-tête ajoutée côté client : `journal,date,cpte,sens,montant,mode_reglement,libelle,poste analytique`
- Par JA ayant des frais > 0, jusqu'à 3 lignes, code journal **`AC`**, date = date de fin de période, mode de règlement `virement` :
  1. Débit (`D`) frais kilométriques + péages sur le compte `compte_frais_km` (config, défaut `62511`)
  2. Débit (`D`) indemnité/prestations sur le compte `compte_prestations` (config, défaut `62261`)
  3. Crédit (`C`) du total sur le compte du JA (`ja.NumCompteEBP`, ou `?????` si absent)
- Poste analytique (config `code_analytique_compta`, défaut `04EPR232`) renseigné uniquement sur les lignes de débit
- Une ligne vide sépare chaque JA ; nom de fichier `import_JA_{datefin sans tirets}.csv`

---

## E026 – Messagerie

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
- Les modèles système sont référencés par type depuis d'autres écrans : `Convocation` (E024, E030 « se désigner »), `Demande adresse` (E029)

---

## E023 – Désidératas club

**Fichier :** `Nominateur/desiderata_club.php`  
**Accès :** Public, sans authentification — page tokenisée par le paramètre `?club=<Id_Club>`

### Objectif
Remplace le questionnaire Excel envoyé par mail aux clubs en début de saison. Permet à un club (via le lien envoyé depuis E027) de renseigner en ligne, pour la saison en cours, les coordonnées de son correspondant, sa salle et les désidératas de ses équipes de la **Pré-Nationale à la R4M**.

### Contenu du formulaire
- **Correspondant** : nom/prénom, téléphone, email (`club.CorNom`, `club.CorTelephone`, `club.CorEmail`)
- **Salle** : nom, adresse, téléphone (`salle.Telephone`), nombre maximum d'aires de jeu (`club.NbAiresJeu`) — la salle principale (`EstPrincipale = 1`) est créée si elle n'existe pas encore
- **Équipes** : une ligne par équipe du club dans une division dont `division.Ord` est compris entre 70 (PNM) et 150 (R4M), soit PNM, PNF, R1M, R1F, R2M, R3M, R4M. Pour chaque équipe :
  - Réengagement (Oui/Non) → `equipe.ReEngagement`
  - Jour de rencontre souhaité (Samedi/Dimanche) → `equipe.JourSouhaite`
  - Souhait de désignation JA (CRA ou Club) → `equipe.SouhaitJA`, uniquement affiché pour les équipes **R3M/R4M** (`Id_Division` 1 ou 10)
- **Note libre** (`club.DesiderataNote`) pour signaler toute modification (nouvelle équipe, correction…) sans avoir à gérer un formulaire d'ajout d'équipe

### Règles
- À l'enregistrement, `club.DesiderataSaison` et `club.DesiderataDate` sont mis à jour (saison courante, horodatage) — utilisés par E027 pour afficher le statut « Soumis / En attente »
- Pour les équipes R3M/R4M, le souhait JA pilote automatiquement `equipe.JAdemande` (`CRA` → 1, `Club` → 0) et `rencontre.ArbitrageObligatoire` sur les rencontres à domicile de l'équipe, avec la même logique que l'ancien bouton de bascule de E027 : `CRA` force `ArbitrageObligatoire = 1`, `Club` restaure la valeur par défaut de la division (`division.ArbitrageCRA`)

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `charger` | GET | Retourne le club, sa salle principale et ses équipes (PN à R4M) avec leurs désidératas actuels |
| `enregistrer` | POST | Enregistre correspondant, salle, note et désidératas par équipe ; synchronise `JAdemande`/`ArbitrageObligatoire` pour R3M/R4M |

---

## E027 – Désidératas clubs

**Fichier :** `Nominateur/JA_R3R4.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Sélectionner les clubs ayant des équipes de la **Pré-Nationale à la R4M** et leur envoyer en masse le questionnaire de désidératas de saison (formulaire public E023), en remplacement de l'envoi manuel du fichier Excel.

### Comportement
- Liste, uniquement pour les départements actifs de la région (`getDeptActifs()` — exclut les clubs Hors région), un club par ligne (regroupement de ses équipes dont `division.Ord` est entre 70 et 150), avec département, correspondant/email, nombre d'équipes concernées, badges des divisions concernées (ex. `R2M`, `R3M`), statut **Soumis** (si `club.DesiderataSaison` correspond à la saison configurée) ou **En attente**, et date du dernier envoi (`club.DesiderataEmailDate`)
- Lignes en couleurs alternées (une sur deux) pour la lisibilité
- Case à cocher par club, boutons **Tout sélectionner** / **Tout désélectionner**, filtres département / statut / recherche par nom de club
- Bouton **Visualiser le message** : ouvre une modale d'aperçu du modèle n°6 avec ses marqueurs résolus (données du premier club sélectionné, ou valeurs génériques si aucune sélection)
- Bouton **Envoyer le questionnaire** : envoie le modèle système `Id_Messagerie = 6` (créé/édité dans E026) aux correspondants des clubs cochés ayant un email, avec un lien `desiderata_club.php?club=<Id_Club>` généré pour chacun

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

## E028 – Statistiques JA

**Fichier :** `Nominateur/stats_ja.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Rapport agrégé, en lecture seule, des arbitrages et frais par JA sur une période donnée (complémentaire de l'export comptable détaillé E025).

### Interface
- Filtres de période (défaut : 1ᵉʳ septembre de l'année en cours → aujourd'hui), bouton **Afficher**
- Tableau triable (clic sur en-tête) : JA (avec mini barre proportionnelle au nombre d'arbitrages), Grade, Club, Arbitrages, Km, Péages, Indemnité, Total frais — avec ligne de totaux
- Boutons **Export CSV** et **Imprimer** (vue imprimable via CSS `@media print`)

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `donnees` | GET | Retourne, par JA, le nombre d'arbitrages et les totaux km / péages / indemnité / frais sur la période |
| `export_csv` | GET | Télécharge un CSV (BOM UTF-8, séparateur `;`) `stats_ja_{debut}_{fin}.csv` |

### Calcul (par JA, sur les nominations `Valide = 1` de la période)
- `total_km` = `SUM(Kilometre)`, `total_peages` = `SUM(Peage)`
- `total_indemnite` = `COUNT(nominations) × indemnite_forfaitaire`
- `total_frais` = `total_km × frais_kilometrique + total_peages + total_indemnite`
- Utilise les mêmes clés de configuration (`indemnite_forfaitaire`, `frais_kilometrique`) que E025, mais comme rapport de synthèse par JA plutôt que comme export comptable ligne à ligne

---

## E029 – Adresse domicile JA

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
- Token = `Obfuscator::obfuscate($idJa)` (seed `OBFUSCATOR_SEED`), lien généré depuis E007 (fiche JA) ou par `envoyer_demande_adresse`
- Le modèle « Demande adresse » (système, `messagerie.Type = 'Demande adresse'`) supporte les marqueurs `{NOM}`, `{PRENOM}`, `{NOM_COMPLET}`, `{URL_ADRESSE_JA}`, `{UTI_NOM}`, `{UTI_PRENOM}`, `{URL_LIGUE}`, `{YEAR_PHASE}`
- Envoi via `getNijacMailer()`, destinataire résolu par `getEmailDestinataire()`, soumis au rate-limiting (`checkRateLimit()` / `enregistrerEnvois()`)

---

## E030 – Fiche personnelle JA

**Fichier :** `JA/info_rencontre.php`  
**Accès :** Rôle `JA` (voir connexion E001) — dossier `JA/` ne contient que ce fichier ; pas de menu ni de formulaire de connexion dédiés, le login se fait via `index.php` (E001) et la déconnexion via `logout.php`

### Objectif
Page d'accueil du Juge-Arbitre connecté par Nom + numéro de licence : consultation de sa fiche, de ses prochaines nominations, et auto-désignation en masse sur les rencontres R3M/R4M à domicile de son club lorsque celui-ci a choisi l'**arbitrage club** (E023 : `equipe.SouhaitJA = 'Club'`).

### Interface
- Fiche identité : Prénom / Nom, licence (`Id_JA`), club, domicile (`Cp`/`Ville` ou commune liée), bouton pour modifier l'adresse (même mécanisme que E029)
- **Mes nominations à venir** (10 max, `Date >= CURDATE()`) : jointure `Nomination → disponible → Rencontre → Salle → laposte`, division, équipes domicile/visiteur
- **Arbitrage club — Rencontres R3M/R4M à venir** : uniquement les rencontres à domicile des équipes R3M/R4M du club du JA ayant `SouhaitJA = 'Club'` (renseigné via le formulaire E023). Une case à cocher par rencontre non pourvue, boutons **Tout sélectionner** (case d'en-tête) et **Valider ma sélection** pour s'auto-désigner sur plusieurs rencontres en une seule action ; ligne en vert si c'est le JA connecté

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `se_designer` | POST | Le JA se désigne lui-même sur une ou plusieurs rencontres (`ids` : tableau JSON d'`Id_Rencontre`) sans JA déjà nominé : crée la ligne `disponible` (Réponse = `P`) si absente, crée la `nomination` (`Valide = 1`) pour chacune, envoie l'email du modèle système `Convocation` ; retourne un résultat par rencontre |
| `recherche_laposte` | POST | Identique à E029 |
| `sauvegarder_adresse` | POST | Met à jour `Cp`/`Ville`/`Id_LaPoste` du JA connecté (identifié via la session, pas de paramètre `id_ja`) |

### Règles
- Toutes les actions POST exigent `csrfVerify(true)`
- `se_designer` traite chaque rencontre indépendamment (fonction `designerJaPourRencontre()`) et refuse celles ayant déjà une nomination ou n'appartenant pas au club du JA connecté ; les autres rencontres de la sélection restent traitées

---

## E099 – Administration base de données

**Fichier :** `db-admin.php`  
**Accès :** Administrateur (uniquement utilisateur `CHAUTARD`)

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
