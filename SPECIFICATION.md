# NIJAC – Spécifications fonctionnelles

> Document de référence pour l'ensemble des écrans de l'application.
> Voir aussi [Ecrans.md](Ecrans.md) pour le tableau synthétique et [README.md](README.md) pour l'architecture.

---

## Table des matières

- [E001 – Connexion](#e001--connexion)
- [E002 – Menu administrateur](#e002--menu-administrateur)
- [E004 – Correspondants de clubs](#e004--correspondants-de-clubs)
- [E005 – Salles](#e005--salles)
- [E006 – Communes](#e006--communes)
- [E007 – Juges-Arbitres](#e007--juges-arbitres)
- [E008 – Clubs / Associations](#e008--clubs--associations)
- [E009 – Utilisateurs](#e009--utilisateurs)
- [E010 – Divisions](#e010--divisions)
- [E011 – Import Rencontres](#e011--import-rencontres)
- [E015 – Configuration générale](#e015--configuration-générale)
- [E016 – Saison / Nettoyage](#e016--saison--nettoyage)
- [E017 – Import Rencontres Nationales](#e017--import-rencontres-nationales)
- [E020 – Menu nominateur](#e020--menu-nominateur)
- [E021 – Disponibilités JA](#e021--disponibilités-ja)
- [E022 – Nomination JA](#e022--nomination-ja)
- [E024 – Centre d'envoi](#e024--centre-denvoi)
- [E025 – Comptabilité frais JA](#e025--comptabilité-frais-ja)
- [E026 – Messagerie](#e026--messagerie)
- [E027 – Demandes JA R3 / R4](#e027--demandes-ja-r3--r4)
- [E099 – Administration base de données](#e099--administration-base-de-données)

---

## E001 – Connexion

**Fichier :** `index.php`  
**Accès :** Public (non authentifié)

### Objectif
Point d'entrée unique de l'application. Authentifie l'utilisateur et initialise la session.

### Interface
- Champ **Login** (texte)
- Champ **Mot de passe** (password, bouton afficher/masquer)
- Bouton **Se connecter**
- Zone de statut (message d'erreur ou de succès)

### Comportement
| Situation | Résultat |
|-----------|----------|
| Déjà connecté | Redirige immédiatement vers E002 (Admin) ou E020 (Nominateur) |
| Login ou MDP vide | Message d'avertissement, pas d'appel base |
| Identifiants invalides ou compte inactif | Message `Échec : Identifiants invalides.` |
| Connexion réussie + rôle Admin | Redirection vers `admin_menu.php` (E002) |
| Connexion réussie + rôle Nominateur | Redirection vers `Nominateur/menu.php` (E020) |
| Erreur base de données | Message système, log PHP |

### Session créée
```php
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
| Correspondant Club | E004 | `correspondant.php` |
| Communes | E006 | `communes.php` |
| Division | E010 | `division.php` |
| Import Rencontres | E011 | `import_rencontres.php` |
| Import Rencontres Nationales | E017 | `import_rencontres_nat.php` |
| Saison | E016 | `clean.php` |
| Configuration | E015 | `configuration.php` |
| Base de données *(CHAUTARD seulement)* | E099 | `db-admin.php` |
| Se déconnecter | — | `logout.php` |

### Règles
- Le bouton **Base de données** (E099) n'est visible que si `$_SESSION['utilisateur']['nom'] === 'CHAUTARD'`
- Le bouton **Se déconnecter** demande une confirmation JavaScript

---

## E004 – Correspondants de clubs

**Fichier :** `correspondant.php`  
**Accès :** Administrateur uniquement

### Objectif
Gérer les contacts référents (correspondants) associés à chaque club.

### Champs d'une fiche correspondant
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Nom | Texte | Oui |
| Prénom | Texte | Non |
| Email | Email | Non |
| Téléphone | Texte (formaté `06.12.34.56.78`) | Non |
| Fonction | Texte | Non |
| Club (Id_Club) | Sélecteur | Oui |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne tous les correspondants avec le nom du club |
| `importer_excel` | POST | Import depuis fichier Excel FFTT (upsert par email) |
| `maj_bdd` | POST | Créer ou modifier un correspondant |

### Import Excel
- Colonnes attendues : N° FFTT du club, Nom, Prénom, Email, Téléphone, Fonction
- Comportement : upsert sur l'email (mise à jour si existant, création sinon)

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

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne tous les clubs |
| `maj_bdd` | POST | Import / upsert depuis fichier Excel FFTT |

### Import Excel
- Format FFTT : colonne `N° FFTT` (Id_Club) + `Nom club` (Nom)
- Données lues à partir de la ligne 3 du fichier
- Comportement : upsert (mise à jour si le club existe, création sinon)

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
| `smtp_host` | Texte | Serveur SMTP |
| `smtp_port` | Entier | Port SMTP |
| `smtp_user` | Texte | Utilisateur SMTP |
| `smtp_password` | Texte | Mot de passe SMTP |
| `smtp_from` | Email | Adresse expéditeur |
| `smtp_from_name` | Texte | Nom expéditeur |
| `indemnite_forfaitaire` | Décimal | Indemnité forfaitaire JA (€) |
| `frais_kilometrique` | Décimal | Tarif au km (€) |
| `frais_max_peages` | Décimal | Plafond péages (€) |
| `frais_max_km` | Décimal | Plafond kilomètres indemnisables |
| `saison` | Ex : `2025-2026` | Saison en cours |

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
| R3 R4 ayant demandé un JA | E027 | `JA_R3R4.php` |
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
- Sélecteur de département (14, 27, 50, 61, 76)
- Grille des JA du département avec leur disponibilité par journée
- Clic sur un JA → ouvre `disponibilite_ja.php` (lien tokenisé via `Obfuscator`) dans une nouvelle fenêtre

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `ja_dept` | GET | Retourne les JA actifs du département avec leurs disponibilités |

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
| `retirer_ja` | POST | Retire la nomination d'un JA |
| `valider_nominations` | POST | Valide les nominations de la journée (`Valide = 1`) |
| `envoyer_convocations` | POST | Envoie les emails de convocation aux JA validés |

### Règles métier de nomination
1. **Exclusion club** : un JA ne peut pas arbitrer une rencontre où son club joue (domicile ou visiteur)
2. **Max rencontres par club / phase** : un JA ne peut pas arbitrer plus de 2 rencontres du même club sur une phase
3. **Unicité par date** : un JA ne peut arbitrer qu'une seule rencontre par date
4. **Priorité disponibilité déclarée** : les rencontres choisies par le JA dans ses disponibilités sont prioritaires
5. **Proximité géographique** : en cas d'égalité, la rencontre la plus proche du domicile du JA est privilégiée
6. **Équité** : priorité au JA ayant le moins d'arbitrages validés sur la phase en cours
7. **Double rencontre en salle** : si un JA est affecté à une rencontre, une 2ᵉ rencontre dans la même salle le même jour lui est automatiquement proposée

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
| `donnees` | GET | Retourne les frais par JA pour la période |
| `export_csv` | GET | Télécharge le fichier CSV au format EBP (journal AC) |

### Calcul des frais
- **Indemnité forfaitaire** : valeur de `configuration.indemnite_forfaitaire` par arbitrage
- **Frais kilométriques** : `Kilometres × frais_kilometrique`, plafonnés à `frais_max_km`
- **Péages** : montant saisi, plafonné à `frais_max_peages`

---

## E026 – Messagerie

**Fichier :** `Nominateur/messagerie.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Créer et gérer les modèles de messages utilisés pour les convocations, rappels et communications aux JA.

### Champs d'un message
| Champ | Type | Obligatoire |
|-------|------|-------------|
| Type | `convocation` \| `rappel` \| `annulation` \| `information` | Oui |
| Sujet | Texte | Oui |
| Corps | HTML / Texte | Oui |
| Utilisateur | Lié à `Id_Utilisateur` | Oui |

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne tous les modèles de messages |
| `charger` | GET | Charge un modèle par son Id |
| `enregistrer` | POST | Créer ou modifier un modèle |
| `dupliquer` | POST | Duplique un modèle existant |
| `supprimer` | POST | Supprime un modèle |

---

## E027 – Demandes JA R3 / R4

**Fichier :** `Nominateur/JA_R3R4.php`  
**Accès :** Administrateur et Nominateur

### Objectif
Signaler qu'une équipe R3 ou R4 demande la présence d'un Juge-Arbitre pour ses rencontres à domicile.

### Comportement
- Affiche la liste des équipes des divisions R3 et R4
- Un toggle **JA demandé** (Oui / Non) par équipe
- Quand `JAdemande = 1` : toutes les rencontres à domicile de cette équipe passent automatiquement en `ArbitrageObligatoire = 1`, ce qui les inclut dans les nominatons de E022

### Actions AJAX
| Action | Méthode | Description |
|--------|---------|-------------|
| `liste` | GET | Retourne les équipes R3/R4 avec leur flag `JAdemande` |
| `departements` | GET | Retourne les départements disponibles |
| `toggle` | POST | Bascule `JAdemande` entre 0 et 1 pour une équipe |

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
- Accès conditionnel : `$_SESSION['utilisateur']['nom'] === 'CHAUTARD'`
- CSRF vérifié sur toutes les actions POST
- Tous les noms de tables et colonnes sont validés par regex `^\w+$` avant injection dans les requêtes
- Les noms de colonnes dans `insert` / `update` sont comparés à la liste réelle de `DESCRIBE` avant utilisation
