<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Racine du vhost "nijac" : point d'entrée de l'appli (E001), pas la page
// de bienvenue CI4 par défaut.
$routes->get('/', 'AuthController::index');

// ── E001 Connexion ──────────────────────────────────────────────────────────
$routes->get('login', 'AuthController::index');
$routes->post('login', 'AuthController::index');

// ── E010 Code de sécurité (double authentification par email) ───────────────
// Public (pas de filtre) : n'est utilisable qu'avec l'état $_SESSION['mfa_attente']
// posé par E001 après un mot de passe valide, sinon retour à E001. Le filtre csrf
// global couvre les POST.
$routes->get('login/code', 'AuthController::code');
$routes->post('login/code', 'AuthController::code');
$routes->post('login/code/renvoi', 'AuthController::renvoyerCode');

// ── E002 Menu administrateur ─────────────────────────────────────────────────
$routes->get('admin-menu', 'AdminMenuController::index', ['filter' => 'adminauth']);
// ── E003 Menu nominateur ─────────────────────────────────────────────────────
// Filtre "auth" sans argument = Administrateur ou Nominateur (ou is_admin) seulement ;
// les rôles CSR, Defiscalisateur, CRA Convoc et JA ne passent que sur les routes dont
// l'argument les nomme (ex. "auth:CRA Convoc") — voir Filters/Auth.php.
$routes->get('nominateur-menu', 'NominateurMenuController::index', ['filter' => 'auth']);
$routes->get('nominateur-menu/convocations-a-envoyer', 'NominateurMenuController::convocationsAEnvoyer', ['filter' => 'auth']);
$routes->get('nominateur-menu/rencontres-sans-ja', 'NominateurMenuController::rencontresSansJa', ['filter' => 'auth']);
$routes->get('nominateur-menu/club-sans-saisie', 'NominateurMenuController::clubSansSaisie', ['filter' => 'auth']);

// ── EA88 Régions (pilote migration CI4) ────────────────────────────────────
$routes->get('region', 'RegionController::index', ['filter' => 'adminauth']);
$routes->get('region/data', 'RegionController::data', ['filter' => 'adminauth']);
$routes->get('region/data/(:segment)', 'RegionController::show/$1', ['filter' => 'adminauth']);
$routes->post('region', 'RegionController::store', ['filter' => 'adminauth']);
$routes->put('region/(:segment)', 'RegionController::update/$1', ['filter' => 'adminauth']);
$routes->delete('region/(:segment)', 'RegionController::delete/$1', ['filter' => 'adminauth']);

// ── EA90 Départements ────────────────────────────────────────────────────────
$routes->get('departement', 'DepartementController::index', ['filter' => 'adminauth']);
$routes->get('departement/data', 'DepartementController::data', ['filter' => 'adminauth']);
$routes->get('departement/data/(:segment)', 'DepartementController::show/$1', ['filter' => 'adminauth']);
$routes->get('departement/regions', 'DepartementController::regions', ['filter' => 'adminauth']);
$routes->post('departement', 'DepartementController::store', ['filter' => 'adminauth']);
$routes->put('departement/(:segment)', 'DepartementController::update/$1', ['filter' => 'adminauth']);
$routes->delete('departement/(:segment)', 'DepartementController::delete/$1', ['filter' => 'adminauth']);

// ── EA84 Calendrier championnat régional ────────────────────────────────────
$routes->get('competition-regionale', 'CompetitionRegionaleController::index', ['filter' => 'adminauth']);
$routes->get('competition-regionale/data', 'CompetitionRegionaleController::data', ['filter' => 'adminauth']);
$routes->get('competition-regionale/data/(:segment)', 'CompetitionRegionaleController::show/$1', ['filter' => 'adminauth']);
$routes->post('competition-regionale', 'CompetitionRegionaleController::store', ['filter' => 'adminauth']);
$routes->put('competition-regionale/(:segment)', 'CompetitionRegionaleController::update/$1', ['filter' => 'adminauth']);
$routes->delete('competition-regionale/(:segment)', 'CompetitionRegionaleController::delete/$1', ['filter' => 'adminauth']);

// ── EA86 Utilisateurs ────────────────────────────────────────────────────────
$routes->get('utilisateur', 'UtilisateurController::index', ['filter' => 'adminauth']);
$routes->get('utilisateur/data', 'UtilisateurController::data', ['filter' => 'adminauth']);
$routes->get('utilisateur/data/(:segment)', 'UtilisateurController::show/$1', ['filter' => 'adminauth']);
// Recherche de fiche ja (nom / prénom / licence) pour le rattachement d'un compte de rôle JA (utilisateur.Id_JA).
$routes->get('utilisateur/fiches-ja', 'UtilisateurController::fichesJa', ['filter' => 'adminauth']);
// Fiches ja (n° de licence) proposées pour le champ « Fiche JA liée » des comptes non-JA (utilisateur.Id_Ja = ja.Id_JA).
$routes->get('utilisateur/fiches-ja-liables', 'UtilisateurController::fichesJaLiables', ['filter' => 'adminauth']);
$routes->post('utilisateur', 'UtilisateurController::store', ['filter' => 'adminauth']);
$routes->put('utilisateur/(:segment)', 'UtilisateurController::update/$1', ['filter' => 'adminauth']);
$routes->delete('utilisateur/(:segment)', 'UtilisateurController::delete/$1', ['filter' => 'adminauth']);

// ── EA89 Divisions ───────────────────────────────────────────────────────────
$routes->get('division', 'DivisionController::index', ['filter' => 'adminauth']);
$routes->get('division/data', 'DivisionController::data', ['filter' => 'adminauth']);
$routes->get('division/data/(:segment)', 'DivisionController::show/$1', ['filter' => 'adminauth']);
$routes->post('division', 'DivisionController::store', ['filter' => 'adminauth']);
$routes->put('division/(:segment)', 'DivisionController::update/$1', ['filter' => 'adminauth']);
$routes->delete('division/(:segment)', 'DivisionController::delete/$1', ['filter' => 'adminauth']);

// ── EA81 Salles ───────────────────────────────────────────────────────────────
// Filtre "auth" (pas "adminauth") : accessible aux Nominateurs en lecture,
// import/enregistrement/suppression/FFTT restreints à l'admin dans le contrôleur.
$routes->get('salle', 'SalleController::index', ['filter' => 'auth']);
$routes->get('salle/data', 'SalleController::data', ['filter' => 'auth']);
$routes->get('salle/clubs', 'SalleController::clubs', ['filter' => 'auth']);
$routes->post('salle/import-excel', 'SalleController::importExcel', ['filter' => 'auth']);
$routes->post('salle', 'SalleController::store', ['filter' => 'auth']);
$routes->put('salle/(:segment)', 'SalleController::update/$1', ['filter' => 'auth']);
$routes->delete('salle/(:segment)', 'SalleController::delete/$1', ['filter' => 'auth']);
$routes->post('salle/fftt/clubs', 'SalleController::ffttClubs', ['filter' => 'auth']);
$routes->post('salle/fftt/sync', 'SalleController::ffttSync', ['filter' => 'auth']);

// ── EA87 Communes / La Poste ─────────────────────────────────────────────────
$routes->get('commune', 'CommuneController::index', ['filter' => 'adminauth']);
$routes->get('commune/data', 'CommuneController::data', ['filter' => 'adminauth']);
$routes->get('commune/export', 'CommuneController::exportCsv', ['filter' => 'adminauth']);
$routes->post('commune', 'CommuneController::store', ['filter' => 'adminauth']);
$routes->post('commune/import', 'CommuneController::importCsv', ['filter' => 'adminauth']);
$routes->put('commune/(:segment)', 'CommuneController::updateCoords/$1', ['filter' => 'adminauth']);

// ── EA93 Messagerie ──────────────────────────────────────────────────────────
// Filtre "auth" (pas "adminauth") : accessible aux Nominateurs (leurs messages
// + les systèmes), modification/suppression des messages système restreinte à
// l'admin dans le contrôleur, comme le fait messagerie.php.
// Rôles supplémentaires déclarés en argument d'"auth" (filtrage fin dans le contrôleur) :
// - CRA Convoc (lien EA93 du menu E009) : écran + lecture + modification/duplication/
//   suppression, limitées aux messages « CRA Convocation … » (création refusée par le contrôleur) ;
// - CSR (ES32) : lecture + mise à jour du message n°6 seulement (restreint au n°6 dans le contrôleur).
$routes->get('messagerie', 'MessagerieController::index', ['filter' => 'auth:CRA Convoc']);
$routes->get('messagerie/data', 'MessagerieController::data', ['filter' => 'auth:CRA Convoc']);
$routes->get('messagerie/data/(:segment)', 'MessagerieController::show/$1', ['filter' => 'auth:CSR,CRA Convoc']);
$routes->post('messagerie', 'MessagerieController::store', ['filter' => 'auth']);
$routes->put('messagerie/(:segment)', 'MessagerieController::update/$1', ['filter' => 'auth:CSR,CRA Convoc']);
$routes->post('messagerie/(:segment)/dupliquer', 'MessagerieController::duplicate/$1', ['filter' => 'auth:CRA Convoc']);
$routes->delete('messagerie/(:segment)', 'MessagerieController::delete/$1', ['filter' => 'auth:CRA Convoc']);

// ── EN15 Centre d'envoi ──────────────────────────────────────────────────────
$routes->get('centrenvoye', 'CentrenvoyeController::index', ['filter' => 'auth']);
$routes->get('centrenvoye/journees', 'CentrenvoyeController::journees', ['filter' => 'auth']);
$routes->get('centrenvoye/ja', 'CentrenvoyeController::ja', ['filter' => 'auth']);
$routes->post('centrenvoye/apercu', 'CentrenvoyeController::apercu', ['filter' => 'auth']);
$routes->post('centrenvoye/envoyer', 'CentrenvoyeController::envoyer', ['filter' => 'auth']);
$routes->post('centrenvoye/envoyer-un', 'CentrenvoyeController::envoyerUn', ['filter' => 'auth']);
// Onglet « Invitation JA » : JA1 actifs du périmètre + état de leur compte, puis création (Id automatique,
// fiche = utilisateur.Id_Ja, Login = email) / régénération du mot de passe et envoi du message système « Invitation JA » (EA93,
// admin). Même filtre "auth" (Administrateur / Nominateur) et CSRF global que le reste d'EN15.
$routes->get('centrenvoye/invitation-ja', 'CentrenvoyeController::invitationJa', ['filter' => 'auth']);
// Aperçu du message « Invitation JA » (valeurs d'exemple, rendu serveur) pour tout rôle d'EN15 ; brouillon
// et données d'édition réservés à l'administrateur (contrôlé dans le contrôleur) — l'enregistrement passe
// par la route existante PUT messagerie/(:segment) (droits EA93 inchangés).
$routes->get('centrenvoye/invitation-ja/modele', 'CentrenvoyeController::invitationJaModele', ['filter' => 'auth']);
$routes->post('centrenvoye/invitation-ja/apercu', 'CentrenvoyeController::invitationJaApercu', ['filter' => 'auth']);
$routes->post('centrenvoye/invitation-ja/verifier','CentrenvoyeController::invitationJaVerifier', ['filter' => 'auth']);
$routes->post('centrenvoye/invitation-ja/envoyer-un', 'CentrenvoyeController::invitationJaEnvoyerUn', ['filter' => 'auth']);

// ── EA85 Nettoyage / Restauration de phase ──────────────────────────────────
// Outil admin destructeur — toute action hors listage de fichiers exige en
// plus une revérification du mot de passe admin (voir CleanController).
$routes->get('clean', 'CleanController::index', ['filter' => 'adminauth']);
$routes->get('clean/sauvegardes', 'CleanController::sauvegardes', ['filter' => 'adminauth']);
$routes->get('clean/sauvegardes-total', 'CleanController::sauvegardesTotal', ['filter' => 'adminauth']);
$routes->get('clean/sauvegardes-table', 'CleanController::sauvegardesTable', ['filter' => 'adminauth']);
$routes->post('clean/verifier-mdp', 'CleanController::verifierMdp', ['filter' => 'adminauth']);
$routes->post('clean/tables', 'CleanController::tables', ['filter' => 'adminauth']);
$routes->post('clean/supprimer-anciennes', 'CleanController::supprimerAnciennes', ['filter' => 'adminauth']);
$routes->post('clean/supprimer', 'CleanController::supprimer', ['filter' => 'adminauth']);
$routes->post('clean/executer', 'CleanController::executer', ['filter' => 'adminauth']);
$routes->post('clean/sauvegarde-totale', 'CleanController::sauvegardeTotale', ['filter' => 'adminauth']);
$routes->post('clean/sauvegarde-table', 'CleanController::sauvegardeTable', ['filter' => 'adminauth']);
$routes->post('clean/restaurer', 'CleanController::restaurer', ['filter' => 'adminauth']);
$routes->post('clean/restaurer-total', 'CleanController::restaurerTotal', ['filter' => 'adminauth']);
$routes->post('clean/restaurer-params-dev', 'CleanController::restaurerParamsDev', ['filter' => 'adminauth']);
$routes->post('clean/restaurer-table','CleanController::restaurerTableFull', ['filter' => 'adminauth']);

// ── EN11 Juges-Arbitres ──────────────────────────────────────────────────────
// Filtre "auth" (pas "adminauth") : accessible aux Nominateurs (grille +
// import EBP), import Excel/API FFTT restreints à l'admin dans le
// contrôleur, comme le fait jugearbitre.php.
$routes->get('jugearbitre', 'JugearbitreController::index', ['filter' => 'auth']);
$routes->get('jugearbitre/liste', 'JugearbitreController::liste', ['filter' => 'auth']);
$routes->get('jugearbitre/clubs', 'JugearbitreController::clubsParDept', ['filter' => 'auth']);
$routes->post('jugearbitre/maj-bdd', 'JugearbitreController::majBdd', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/clubs-dept', 'JugearbitreController::getClubsDept', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/reset-actif-dept', 'JugearbitreController::reinitialiserActifDept', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/import-club', 'JugearbitreController::importFfttClub', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/scan-club', 'JugearbitreController::scanFfttClub', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/import-selected', 'JugearbitreController::importFfttSelected', ['filter' => 'auth']);
$routes->post('jugearbitre/import-csv-ebp', 'JugearbitreController::importCsvEbp', ['filter' => 'auth']);
$routes->post('jugearbitre/import-excel', 'JugearbitreController::importerExcel', ['filter' => 'auth']);

// ── EA82 Import Rencontres FFTT ──────────────────────────────────────────────
// Admin uniquement (comme includes/admin_required.php côté legacy — voir
// SPECIFICATION.md, section EA82).
$routes->get('import-rencontres', 'ImportRencontresController::index', ['filter' => 'adminauth']);
$routes->post('import-rencontres/chercher-ligue', 'ImportRencontresController::chercherLigue', ['filter' => 'adminauth']);
$routes->post('import-rencontres/charger-epreuves', 'ImportRencontresController::chargerEpreuves', ['filter' => 'adminauth']);
$routes->post('import-rencontres/charger-divisions', 'ImportRencontresController::chargerDivisions', ['filter' => 'adminauth']);
$routes->post('import-rencontres/importer-division', 'ImportRencontresController::importerDivision', ['filter' => 'adminauth']);
$routes->get('import-rencontres/liste-rencontres', 'ImportRencontresController::listeRencontres', ['filter' => 'adminauth']);
$routes->get('import-rencontres/compter-tables', 'ImportRencontresController::compterTables', ['filter' => 'adminauth']);
$routes->get('import-rencontres/candidats-arbitre', 'ImportRencontresController::candidatsArbitre', ['filter' => 'adminauth']);
$routes->post('import-rencontres/designer-arbitre', 'ImportRencontresController::designerArbitre', ['filter' => 'adminauth']);

// ── EN27 Clubs / Associations ───────────────────────────────────────────────
// Déplacé du menu admin (ex-EA80) vers le menu nominateur (E003, après EN11).
// Filtre "auth" : Nominateur ou Administrateur.
$routes->get('club', 'ClubController::index', ['filter' => 'auth']);
$routes->get('club/liste', 'ClubController::liste', ['filter' => 'auth']);
$routes->put('club/(:segment)', 'ClubController::modifier/$1', ['filter' => 'auth']);
$routes->delete('club/(:segment)', 'ClubController::supprimer/$1', ['filter' => 'auth']);
$routes->post('club/fftt/clubs-dept', 'ClubController::getClubsDeptFftt', ['filter' => 'auth']);
$routes->post('club/fftt/sync', 'ClubController::syncFfttClub', ['filter' => 'auth']);

// ── EA83 Import Rencontres Nationales ────────────────────────────────────────
// Admin uniquement (comme includes/admin_required.php côté legacy). Deux onglets pour
// alimenter la table equipe_nationale (import via API FFTT, ou importation d'un fichier
// Excel FFTT multi-feuilles/texte — méthode originelle de cet écran, utile en début de
// saison quand l'API n'a pas encore les poules) : ils partagent la section d'association
// équipe → club. L'import des rencontres elles-mêmes (importer-excel) ne se fait qu'à
// partir du calendrier lu dans le fichier Excel/texte — pas d'appel FFTT pour cette étape.
$routes->get('import-rencontres-nat', 'ImportRencontresNatController::index', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/reset-cache', 'ImportRencontresNatController::resetNatCache', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/clubs-region', 'ImportRencontresNatController::listerClubsRegion', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/debug-club', 'ImportRencontresNatController::debugClub', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/scanner-club', 'ImportRencontresNatController::scannerClub', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/charger-depuis-api', 'ImportRencontresNatController::chargerDepuisApi', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/equipes', 'ImportRencontresNatController::listeEquipes', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/recherche-club', 'ImportRencontresNatController::rechercheClub', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/club-par-numero', 'ImportRencontresNatController::clubParNumero', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/sauvegarder-assoc', 'ImportRencontresNatController::sauvegarderAssoc', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/fichiers-excel', 'ImportRencontresNatController::listerFichiersExcel', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/analyser-excel', 'ImportRencontresNatController::analyserExcel', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/importer-excel', 'ImportRencontresNatController::importerRencontresExcel', ['filter' => 'adminauth']);
$routes->get('import-rencontres-nat/calendrier', 'ImportRencontresNatController::calendrier', ['filter' => 'adminauth']);
$routes->post('import-rencontres-nat/exporter-txt', 'ImportRencontresNatController::exporterTxt', ['filter' => 'adminauth']);

// ── EN13 Disponibilités JA ───────────────────────────────────────────────────
$routes->get('disponibilites', 'DisponibilitesController::index', ['filter' => 'auth']);
$routes->get('disponibilites/ja-dept', 'DisponibilitesController::jaDept', ['filter' => 'auth']);

// ── EN14 Nomination des Juges-Arbitres ───────────────────────────────────────
$routes->get('nomination', 'NominationController::index', ['filter' => 'nominauth']);
$routes->get('nomination/journees', 'NominationController::journees', ['filter' => 'nominauth']);
$routes->get('nomination/rencontres-journee', 'NominationController::rencontresJournee', ['filter' => 'nominauth']);
$routes->get('nomination/candidats-journee', 'NominationController::candidatsJournee', ['filter' => 'nominauth']);
$routes->post('nomination/affecter-ja', 'NominationController::affecterJa', ['filter' => 'nominauth']);
$routes->post('nomination/retirer-ja', 'NominationController::retirerJa', ['filter' => 'nominauth']);
$routes->post('nomination/envoyer-convocations', 'NominationController::envoyerConvocations', ['filter' => 'nominauth']);
$routes->get('nomination/message-arbitre-club', 'NominationController::messageArbitreClub', ['filter' => 'nominauth']);
$routes->post('nomination/demander-ja-club', 'NominationController::demanderJaClub', ['filter' => 'nominauth']);

// ── EN26 Statistiques des nominations ────────────────────────────────────────
// Ouvert dans une nouvelle fenêtre depuis EN14 (seul lien). Lecture seule : la vue
// n'appelle que stats-nomination/data (export CSV généré côté navigateur).
// Mêmes rôles qu'EN14/EN28 : Nominateur, Administrateur (filtre nominauth, CSR exclu).
$routes->get('stats-nomination', 'StatsNominationController::index', ['filter' => 'nominauth']);
$routes->get('stats-nomination/data', 'StatsNominationController::data', ['filter' => 'nominauth']);

// ── EN28 Suivi des nominations ───────────────────────────────────────────────
// Menu nominateur (E003), après EN14. Nominations du périmètre + frais saisis
// par le JA (EN21) ; « rappel » renvoie le modèle messagerie n°3 (Convocation) au JA.
$routes->get('suivi-nomination', 'SuiviNominationController::index', ['filter' => 'nominauth']);
$routes->get('suivi-nomination/data', 'SuiviNominationController::data', ['filter' => 'nominauth']);
$routes->get('suivi-nomination/ja-liste', 'SuiviNominationController::jaListe', ['filter' => 'nominauth']);
$routes->get('suivi-nomination/ja-disponibles', 'SuiviNominationController::jaDisponibles', ['filter' => 'nominauth']);   // ?rencontre=ID : JA nommables en arbitrage CRA (règle stricte EN14 + 2/jour), JA actuel toujours inclus
$routes->post('suivi-nomination/modifier', 'SuiviNominationController::modifier', ['filter' => 'nominauth']);
$routes->post('suivi-nomination/saisir', 'SuiviNominationController::saisir', ['filter' => 'nominauth']);   // JA d'une rencontre sans nomination : arbitrage club (saisie) ou CRA (nomination, règles EN14)
$routes->post('suivi-nomination/rappel', 'SuiviNominationController::rappel', ['filter' => 'nominauth']);
$routes->post('suivi-nomination/relance-club', 'SuiviNominationController::relanceClub', ['filter' => 'nominauth']);
// « Mise à jour FFTT 131 » (popup EN28) : le fichier analysé est TOUJOURS le 131_*.xlsx le plus récent d'Importation/
// (jamais servi en HTTP). fichier = liste de l'étape 2 (tous les 131_*.xlsx, plus récent d'abord) ; televerser = dépôt
// xlsx multipart sous un nom généré par le serveur (131_<jjmmaaaaHHMMSS>.xlsx) ; supprimer = UN fichier par appel (`nom`
// validé par regex puis retrouvé dans la liste serveur, jamais de chemin client) ; apercu (sans paramètre) = analyse
// SANS ÉCRITURE : rencontres R3M / R4M sans JA nommé auxquelles le fichier donne un JA connu ; appliquer (`fichier` +
// `nb` attendus, contrôle de cohérence seulement) = le serveur relit et recalcule tout, crée ces nominations (F131 = 1)
// dans UNE transaction et écrit le journal CSV _Suivi/suivi_131_<aaaammjj_HHMMSS>.csv. (L'ancien écran EN30 « Contrôle
// FFTT 131 », routes controle-f131*, a été supprimé.)
$routes->get('suivi-nomination/f131/fichier', 'SuiviNominationController::f131Fichier', ['filter' => 'nominauth']);
$routes->post('suivi-nomination/f131/televerser', 'SuiviNominationController::f131Televerser', ['filter' => 'nominauth']);
$routes->post('suivi-nomination/f131/supprimer', 'SuiviNominationController::f131Supprimer', ['filter' => 'nominauth']);
$routes->post('suivi-nomination/f131/apercu', 'SuiviNominationController::f131Apercu', ['filter' => 'nominauth']);
$routes->post('suivi-nomination/f131/appliquer', 'SuiviNominationController::f131Appliquer', ['filter' => 'nominauth']);
// ?nom=suivi_131_<aaaammjj_HHMMSS>.csv : ouvre un journal de _Suivi/ (interdit en HTTP direct) — regex stricte + nom retrouvé
// dans la liste serveur, jamais de chemin client ; 404 sinon. La liste des 10 derniers est fournie par f131/fichier (suivis[]).
$routes->get('suivi-nomination/f131/suivi', 'SuiviNominationController::f131Suivi', ['filter' => 'nominauth']);
// Justificatif de péage (nomination.FichierPeage, téléversé par la popup « Modifier » via suivi-nomination/modifier) :
// ?nom=<17 chiffres>.<jpg|jpeg|png|gif|webp|pdf> affiche (inline) un fichier de _Peage/ (interdit en HTTP direct) — regex
// stricte + nom porté par une nomination du périmètre + retrouvé dans la liste serveur, jamais de chemin client ; 404 sinon.
$routes->get('suivi-nomination/peage/fichier', 'SuiviNominationController::peageFichier', ['filter' => 'nominauth']);

// ── EN18 Désidératas club ────────────────────────────────────────────────────
// Page PUBLIQUE (sans authentification), jeton signé ?club=<Id_Club>-<MAC> — lien
// envoyé par email depuis EN12 / ES32. Pas de filtre "auth"/"adminauth" ici.
$routes->get('desiderata-club', 'DesiderataClubController::index');
$routes->get('desiderata-club/charger', 'DesiderataClubController::charger');
$routes->post('desiderata-club/enregistrer', 'DesiderataClubController::enregistrer');

// ── EN25 Juge-arbitre du club ────────────────────────────────────────────────
// Page PUBLIQUE, tokenisée par ?renc=TOKEN (Obfuscator Id_Rencontre) — lien
// envoyé au correspondant du club recevant depuis EN14 (message n°7).
$routes->get('arbitre-club', 'ArbitreClubController::index');
$routes->get('arbitre-club/rechercher-ja', 'ArbitreClubController::rechercherJa');
$routes->post('arbitre-club/enregistrer', 'ArbitreClubController::enregistrer');

// ── EN12 Désidératas clubs ───────────────────────────────────────────────────
// Nominateur ou Administrateur — retiré du menu CSR (E004), remis dans le menu
// nominateur (E003). Rôle CSR refusé par le filtre "auth" (sans argument "CSR").
$routes->get('desiderata-clubs', 'DesiderataClubsController::index', ['filter' => 'auth']);
$routes->get('desiderata-clubs/liste', 'DesiderataClubsController::liste', ['filter' => 'auth']);
$routes->get('desiderata-clubs/departements', 'DesiderataClubsController::departements', ['filter' => 'auth']);
$routes->get('desiderata-clubs/detail', 'DesiderataClubsController::detail', ['filter' => 'auth']);
$routes->get('desiderata-clubs/ja-club', 'DesiderataClubsController::jaClub', ['filter' => 'auth']);
$routes->get('desiderata-clubs/apercu', 'DesiderataClubsController::apercu', ['filter' => 'auth']);
$routes->post('desiderata-clubs/envoyer', 'DesiderataClubsController::envoyer', ['filter' => 'auth']);

// ── ED55 Comptes EBP des JA (menu E005 Défiscalisateur) ─────────────────────
$routes->get('compta', 'ComptaController::index', ['filter' => 'defiscauth']);
$routes->get('compta/export-csv', 'ComptaController::exportCsv', ['filter' => 'defiscauth']);
$routes->get('compta/ja-sans-compte', 'ComptaController::jaSansCompte', ['filter' => 'defiscauth']);
$routes->post('compta/maj-compte', 'ComptaController::majCompte', ['filter' => 'defiscauth']);
$routes->post('compta/import-ebp', 'ComptaController::importEbp', ['filter' => 'defiscauth']);

// ── EN17 Statistiques JA ─────────────────────────────────────────────────────
$routes->get('stats-ja', 'StatsJaController::index', ['filter' => 'auth']);
$routes->get('stats-ja/donnees', 'StatsJaController::donnees', ['filter' => 'auth']);
$routes->get('stats-ja/par-departement', 'StatsJaController::parDepartement', ['filter' => 'auth']);
$routes->get('stats-ja/export-csv', 'StatsJaController::exportCsv', ['filter' => 'auth']);

// ── EN19 Adresse domicile JA ─────────────────────────────────────────────────
// Page PUBLIQUE (sans authentification), tokenisée par ?ja=TOKEN — lien envoyé
// par email depuis EN11/EN15. Les actions "token" et "envoyer-demande-adresse"
// exigent en revanche une session Nominateur/Admin (filtre "auth"), comme le
// fait le legacy en ré-incluant auth_required.php uniquement pour elles.
$routes->get('adresse-ja', 'AdresseJaController::index');
$routes->get('adresse-ja/token', 'AdresseJaController::token', ['filter' => 'auth']);
$routes->post('adresse-ja/token', 'AdresseJaController::token', ['filter' => 'auth']);
$routes->post('adresse-ja/envoyer-demande-adresse', 'AdresseJaController::envoyerDemandeAdresse', ['filter' => 'auth']);
$routes->post('adresse-ja/recherche-laposte', 'AdresseJaController::rechercheLaposte');
$routes->post('adresse-ja/sauvegarder', 'AdresseJaController::sauvegarder');

// ── EA91 Configuration générale ──────────────────────────────────────────────
$routes->get('configuration', 'ConfigurationController::index', ['filter' => 'adminauth']);
$routes->post('configuration/lire', 'ConfigurationController::lire', ['filter' => 'adminauth']);
$routes->post('configuration/enregistrer', 'ConfigurationController::enregistrer', ['filter' => 'adminauth']);
$routes->post('configuration/smtp-test-prod', 'ConfigurationController::smtpTestProd', ['filter' => 'adminauth']);
$routes->post('configuration/table-creer', 'ConfigurationController::tableCreer', ['filter' => 'adminauth']);
$routes->post('configuration/table-modifier', 'ConfigurationController::tableModifier', ['filter' => 'adminauth']);
$routes->post('configuration/table-supprimer', 'ConfigurationController::tableSupprimer', ['filter' => 'adminauth']);

// ── EA96 Test API FFTT ───────────────────────────────────────────────────────
// Admin uniquement (filtre "adminauth"), + restriction supplémentaire
// login === 'CHAUTARD' vérifiée manuellement dans le contrôleur (même règle
// que EA98).
$routes->get('fftt-test', 'FfttTestController::index', ['filter' => 'adminauth']);
$routes->post('fftt-test/ping', 'FfttTestController::ping', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-clubs-dep', 'FfttTestController::testClubsDep', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-licence', 'FfttTestController::testLicence', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-equipes', 'FfttTestController::testEquipes', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-club-detail', 'FfttTestController::testClubDetail', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-club-b', 'FfttTestController::testClubB', ['filter' => 'adminauth']);
$routes->post('fftt-test/debug-club-salle', 'FfttTestController::debugClubSalle', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-licence-b', 'FfttTestController::testLicenceB', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-arbitres-dep', 'FfttTestController::testArbitresDep', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-spid-club', 'FfttTestController::testSpidClub', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-organisme', 'FfttTestController::testOrganisme', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-epreuve', 'FfttTestController::testEpreuve', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-division', 'FfttTestController::testDivision', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-poule', 'FfttTestController::testPoule', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-rencontre', 'FfttTestController::testRencontre', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-rencontre-poule', 'FfttTestController::testRencontrePoule', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-chp-renc', 'FfttTestController::testChpRenc', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-result-equ', 'FfttTestController::testResultEqu', ['filter' => 'adminauth']);
$routes->post('fftt-test/test-equipe-nat', 'FfttTestController::testEquipeNat', ['filter' => 'adminauth']);
$routes->post('fftt-test/scan-dept-nat', 'FfttTestController::scanDeptNat', ['filter' => 'adminauth']);
// Test générique de la librairie alamirault/fftt-api (composer) — voir
// avertissement sécurité dans FfttTestController::testViaLibrary().
$routes->post('fftt-test/test-lib', 'FfttTestController::testViaLibrary', ['filter' => 'adminauth']);

// ── EA92 Équipes régionales ──────────────────────────────────────────────────
$routes->get('equipe-regionale', 'EquipeRegionaleController::index', ['filter' => 'adminauth']);
$routes->get('equipe-regionale/liste', 'EquipeRegionaleController::liste', ['filter' => 'adminauth']);
$routes->post('equipe-regionale/import-txt', 'EquipeRegionaleController::importerTxt', ['filter' => 'adminauth']);
$routes->put('equipe-regionale/(:num)', 'EquipeRegionaleController::modifier/$1', ['filter' => 'adminauth']);
$routes->delete('equipe-regionale/(:num)', 'EquipeRegionaleController::supprimer/$1', ['filter' => 'adminauth']);

// ── EN29 Gestion des équipes (nominateur) ────────────────────────────────────
// Transféré du menu admin (ex-EA94) vers le menu nominateur (E003), même
// principe qu'EN27 (ex-EA80) et EN23 (ex-EA95). Nominateur ou Administrateur
// (filtre "auth").
$routes->get('gestion-equipes', 'EquipeAdminController::index', ['filter' => 'auth']);
$routes->get('gestion-equipes/data', 'EquipeAdminController::data', ['filter' => 'auth']);
$routes->post('gestion-equipes', 'EquipeAdminController::store', ['filter' => 'auth']);
$routes->put('gestion-equipes/(:num)', 'EquipeAdminController::update/$1', ['filter' => 'auth']);
$routes->delete('gestion-equipes/(:num)', 'EquipeAdminController::delete/$1', ['filter' => 'auth']);
$routes->post('gestion-equipes/(:num)/appliquer-arbitrage', 'EquipeAdminController::appliquerArbitrageRencontres/$1', ['filter' => 'auth']);

// ── EN23 Gestion des rencontres (nominateur) ─────────────────────────────────
// Transféré du menu admin (ex-EA95) vers le menu nominateur (E003), même
// principe qu'EN27 (ex-EA80). Nominateur ou Administrateur (filtre "auth").
$routes->get('gestion-rencontres', 'RencontreAdminController::index', ['filter' => 'auth']);
$routes->get('gestion-rencontres/data', 'RencontreAdminController::data', ['filter' => 'auth']);
$routes->get('gestion-rencontres/doublons', 'RencontreAdminController::doublons', ['filter' => 'auth']);
$routes->put('gestion-rencontres/(:num)', 'RencontreAdminController::update/$1', ['filter' => 'auth']);
$routes->delete('gestion-rencontres/(:num)', 'RencontreAdminController::delete/$1', ['filter' => 'auth']);

// ── EN24 Remplacement équipe (nominateur) ────────────────────────────────────
// Une équipe forfait/désistée est remplacée par une autre sur toutes ses
// rencontres restantes (Id_EquipeDom/Id_EquipeExt) ; les nominations déjà
// faites sur ces rencontres sont supprimées (à refaire par le nominateur).
// Nominateur ou Administrateur (filtre "auth").
$routes->get('remplacement-equipe', 'RemplacementEquipeController::index', ['filter' => 'auth']);
$routes->get('remplacement-equipe/data', 'RemplacementEquipeController::data', ['filter' => 'auth']);
$routes->get('remplacement-equipe/equipes', 'RemplacementEquipeController::equipes', ['filter' => 'auth']);
$routes->post('remplacement-equipe/remplacer', 'RemplacementEquipeController::remplacer', ['filter' => 'auth']);

// ── EA98 Administration base de données ──────────────────────────────────────
// Admin uniquement (filtre "adminauth"), + restriction supplémentaire
// login === 'CHAUTARD' vérifiée manuellement dans le contrôleur (même règle
// que EA96). Outil "bris de glace" : requêteur SQL libre, sans restriction.
$routes->get('db-admin', 'DbAdminController::index', ['filter' => 'adminauth']);
$routes->get('db-admin/tables', 'DbAdminController::tables', ['filter' => 'adminauth']);
$routes->get('db-admin/fichiers-sql', 'DbAdminController::fichiersSql', ['filter' => 'adminauth']);
$routes->post('db-admin/verifier-mdp', 'DbAdminController::verifierMdp', ['filter' => 'adminauth']);
$routes->post('db-admin/charger-fichier-sql', 'DbAdminController::chargerFichierSql', ['filter' => 'adminauth']);
$routes->post('db-admin/supprimer-fichier-sql', 'DbAdminController::supprimerFichierSql', ['filter' => 'adminauth']);
$routes->post('db-admin/sql', 'DbAdminController::sql', ['filter' => 'adminauth']);

// ── EA99 Sécurité TOTP (double authentification des écrans EA96 / EA97 / EA98) ─
// Filtre "adminauth" + login === 'CHAUTARD' vérifié dans TotpController (même règle que
// EA96/97/98), CSRF global. Page d'activation (QR + secret + mot de passe + 1er code, codes de
// secours affichés une fois) ou de saisie du code ; les guardChautard() d'EA96/97/98 y renvoient
// (App\Libraries\TotpChautard) tant que la session n'est pas déverrouillée (30 min glissantes).
$routes->get('securite-totp', 'TotpController::index', ['filter' => 'adminauth']);
$routes->post('securite-totp/activer', 'TotpController::activer', ['filter' => 'adminauth']);
$routes->post('securite-totp/verifier', 'TotpController::verifier', ['filter' => 'adminauth']);
$routes->post('securite-totp/secours', 'TotpController::secours', ['filter' => 'adminauth']);

// ── EA97 BugSpid (file de corrections Id_Club dupliqué) ─────────────────────
// Même restriction que EA98 : filtre "adminauth" + login === 'CHAUTARD'
// vérifié manuellement dans le contrôleur — outil de correction de données,
// accessible depuis un bouton sur EA98.
$routes->get('bug-spid', 'BugSpidController::index', ['filter' => 'adminauth']);
$routes->get('bug-spid/data', 'BugSpidController::data', ['filter' => 'adminauth']);
$routes->post('bug-spid', 'BugSpidController::store', ['filter' => 'adminauth']);
$routes->put('bug-spid/(:num)', 'BugSpidController::update/$1', ['filter' => 'adminauth']);
$routes->delete('bug-spid/(:num)', 'BugSpidController::delete/$1', ['filter' => 'adminauth']);
$routes->post('bug-spid/executer', 'BugSpidController::executer', ['filter' => 'adminauth']);
$routes->post('bug-spid/pdf-csv', 'BugSpidController::pdfCsv', ['filter' => 'adminauth']);
$routes->post('bug-spid/maj-csv', 'BugSpidController::majCsv', ['filter' => 'adminauth']);
$routes->get('bug-spid/nom-club/(:num)', 'BugSpidController::nomClub/$1', ['filter' => 'adminauth']);
$routes->post('bug-spid/(:num)/xml-club-b', 'BugSpidController::testXmlClubB/$1', ['filter' => 'adminauth']);

// ── Déconnexion ──────────────────────────────────────────────────────────────
// POST (filtre csrf global) : détruit la session + son cookie. GET (anciens favoris) :
// simple redirection vers E001, la session n'est PAS détruite (pas de déconnexion par
// lien/image tiers). Seule route accessible, avec changer-mot-de-passe, tant que
// change_login est vrai (voir revaliderSessionUtilisateur()).
$routes->post('logout', 'LogoutController::index');
$routes->get('logout', 'LogoutController::rediriger');

// ── E006 Changement du mot de passe ─────────────────────────────────────────
// Ouverte depuis la modale toolbar (_modal_mdp.php) sur toute page CI4
// authentifiée. Filtre "auth:CSR,Defiscalisateur,CRA Convoc,JA" : tout rôle connecté
// (la modale / le lien est présent sur les menus E004, E005, E009, E011 et leurs écrans).
// Changement forcé (change_login) : tous les filtres d'authentification redirigent
// ici toute autre route que changer-mot-de-passe / logout. Essais du mot de passe
// actuel limités par compte (mdp_actuel:<id>).
$routes->get('changer-mot-de-passe', 'ChangerMotDePasseController::index', ['filter' => 'auth:CSR,Defiscalisateur,CRA Convoc,JA']);
$routes->post('changer-mot-de-passe', 'ChangerMotDePasseController::index', ['filter' => 'auth:CSR,Defiscalisateur,CRA Convoc,JA']);

// ── Mode JA d'un compte non-JA / retour (pas un écran EXXX) ──────────────────
// POST seul (filtre csrf global), tout rôle connecté. action=ja : session en mode JA sur la fiche
// liée (utilisateur.Id_Ja = ja.Id_JA, réglé par un administrateur dans EA86 ; bouton
// E002 / E003), SANS changer de compte ; action=retour : rôles d'origine relus en base (bouton E011 /
// E006). Fiche et rôles toujours relus en base, jamais fournis par le client — voir
// ChangerCompteController. Accessible pendant un changement de mot de passe forcé (ROUTES_CHANGE_LOGIN)
// pour le seul retour.
$routes->post('changer-compte', 'ChangerCompteController::index', ['filter' => 'auth:CSR,Defiscalisateur,CRA Convoc,JA']);

// ── E007 Mot de passe oublié (demande) + E008 Réinitialisation (lien email) ──
// Pages PUBLIQUES (aucun filtre auth) : l'utilisateur n'est pas connecté. Le
// lien email porte un jeton HMAC à usage unique (genererJetonResetMdp), aucune
// colonne BDD. Le filtre csrf global couvre les POST.
$routes->get('mot-de-passe-oublie',  'MotDePasseOublieController::demande');
$routes->post('mot-de-passe-oublie', 'MotDePasseOublieController::demande');
$routes->get('reinitialiser-mot-de-passe',  'MotDePasseOublieController::reinitialiser');
$routes->post('reinitialiser-mot-de-passe', 'MotDePasseOublieController::reinitialiser');

// ── Utilitaire partagé : résolution code postal / commune (laposte) ─────────
// Pas un écran EXXX — réutilisé par EA81 (Salle) et EN11 (Juge-Arbitre).
$routes->post('laposte/recherche-laposte', 'LaposteController::rechercheLaposte', ['filter' => 'auth']);
$routes->post('laposte/lookup-laposte', 'LaposteController::lookupLaposte', ['filter' => 'auth']);

// ── EN21 Convocation et frais JA ─────────────────────────────────────────────
// Page PUBLIQUE (sans authentification, ni même de token obfusqué — l'URL
// porte l'Id_Nomination en clair, comme le legacy). Générée depuis EN14.
$routes->get('convocation-ja', 'ConvocationJaController::index');
// Forme "chemin" : .../convocation-ja/<Id_Nomination>/<tokenCnv> — sans '?', '='
// ni '&', qui se font mutiler dans les emails en texte brut (encodage
// quoted-printable + auto-lien des webmails) et cassaient le lien en prod
// ("Paramètre nomination manquant"). L'ancienne forme ?nomination=&cnv= reste
// acceptée pour les convocations déjà envoyées.
$routes->get('convocation-ja/(:num)/(:alphanum)', 'ConvocationJaController::index/$1/$2');
$routes->post('convocation-ja/sauvegarder-frais', 'ConvocationJaController::sauvegarderFrais');
// Accusé de réception par le JA (jeton cnv, nomination.AccuseReception — colonne ajoutée par EA98).
$routes->post('convocation-ja/accuse', 'ConvocationJaController::accuser');
// Justificatif de péage joint par le JA (jeton cnv, multipart `justificatif`, nomination.FichierPeage — colonne EA98) :
// dépôt / remplacement (frais saisissables, Peage > 0) ; aucune suppression par le JA.
$routes->post('convocation-ja/justificatif', 'ConvocationJaController::joindreJustificatif');
// Consultation (inline) par le JA de SON justificatif : ?id_nomination=&cnv= (jeton de la nomination du lien,
// colonne présente) ; nom lu UNIQUEMENT en base (nomination.FichierPeage), jamais du client ; 404 texte neutre sinon.
$routes->get('convocation-ja/justificatif/voir', 'ConvocationJaController::voirJustificatif');

// ── EN22 Disponibilité JA ────────────────────────────────────────────────────
// Page PUBLIQUE (sans authentification) — accessible via ?ja=TOKEN ou ?id_ja=N.
$routes->get('disponibilite-ja', 'DisponibiliteJaController::index');
$routes->get('disponibilite-ja/ja', 'DisponibiliteJaController::ja');
$routes->get('disponibilite-ja/journees', 'DisponibiliteJaController::journees');
$routes->post('disponibilite-ja/sauvegarder-dispo-journee', 'DisponibiliteJaController::sauvegarderDispoJournee');
// "token" exige une session (appelée depuis EN11) : publique, elle permettait à n'importe qui
// de fabriquer le token de n'importe quel JA (?id=N) et donc de contourner tout le jetonnage.
$routes->get('disponibilite-ja/token', 'DisponibiliteJaController::token', ['filter' => 'auth']);
$routes->post('disponibilite-ja/token', 'DisponibiliteJaController::token', ['filter' => 'auth']);
$routes->get('disponibilite-ja/lire-note', 'DisponibiliteJaController::lireNote');
$routes->post('disponibilite-ja/sauvegarder-note', 'DisponibiliteJaController::sauvegarderNote');
$routes->post('disponibilite-ja/sauvegarder-defiscalisation', 'DisponibiliteJaController::sauvegarderDefiscalisation');
$routes->post('disponibilite-ja/sauvegarder-arbitrage-voisins', 'DisponibiliteJaController::sauvegarderArbitrageVoisins');

// ── E004 Menu CSR ────────────────────────────────────────────────────────────
// Rôle CSR (Commission Sportive Régionale) ou Administrateur — voir CsrAuth.php.
// Le rôle CSR n'a accès qu'aux écrans de ce menu (E004, ES31, ES32, ES33 :
// "csrauth") + routes partagées dont l'argument "auth:…" contient CSR
// (changer-mot-de-passe, messagerie/data/(:segment) et PUT messagerie/(:segment)
// pour ES32). Le filtre "auth" sans argument et "nominauth" le refusent.
$routes->get('csr-menu', 'CsrMenuController::index', ['filter' => 'csrauth']);

// ── ES32 Message Réengagement CSR ────────────────────────────────────────────
// Remplace le lien vers EA93 dans le menu CSR (E004) — voir ReengagementCsrController.
$routes->get('reengagement-csr', 'ReengagementCsrController::index', ['filter' => 'csrauth']);

// ── ES33 Souhaits des équipes (jour + arbitrage R3M/R4M) ─────────────────────
// Rôle CSR ou Administrateur (filtre "csrauth"), accessible depuis E004.
$routes->get('souhait-equipe', 'SouhaitEquipeController::index', ['filter' => 'csrauth']);
$routes->get('souhait-equipe/liste', 'SouhaitEquipeController::liste', ['filter' => 'csrauth']);
$routes->post('souhait-equipe/xlsx-csv', 'SouhaitEquipeController::xlsxCsv', ['filter' => 'csrauth']);
$routes->post('souhait-equipe/maj-csv', 'SouhaitEquipeController::majCsv', ['filter' => 'csrauth']);
$routes->get('souhait-equipe/rapports', 'SouhaitEquipeController::rapports', ['filter' => 'csrauth']);
$routes->put('souhait-equipe/(:num)', 'SouhaitEquipeController::modifier/$1', ['filter' => 'csrauth']);

// ── ES31 Club CSR (envoi email) ──────────────────────────────────────────────
$routes->get('club-csr', 'ClubCsrController::index', ['filter' => 'csrauth']);
$routes->get('club-csr/liste', 'ClubCsrController::liste', ['filter' => 'csrauth']);
$routes->put('club-csr/(:segment)', 'ClubCsrController::modifier/$1', ['filter' => 'csrauth']);
$routes->post('club-csr/envoyer', 'ClubCsrController::envoyer', ['filter' => 'csrauth']);

// ── E005 Menu Défiscalisateur ────────────────────────────────────────────────
// Rôle Defiscalisateur ou Administrateur — voir DefiscalisateurAuth.php.
// Le rôle Defiscalisateur n'a accès qu'aux écrans de ce menu (E005, ED51-ED55 :
// "defiscauth", ED53 sans filtre) + changer-mot-de-passe ("auth:…,Defiscalisateur,…").
$routes->get('defiscalisateur-menu', 'DefiscalisateurMenuController::index', ['filter' => 'defiscauth']);

// ── E009 Menu CRA Convoc ─────────────────────────────────────────────────────
// Rôle « CRA Convoc » ou Administrateur — voir CraConvocAuth.php. Compétitions CRA (EC71), Disponibilités CRA (EC74), Désignation CRA (EC73), Statistiques CRA (EC75), Degrés Juge-Arbitre (EC72) + déconnexion.
// Le rôle CRA Convoc n'a accès qu'aux écrans de ce menu ("craconvocauth") + EA93 messagerie (routes
// "auth:…CRA Convoc", messages CRA filtrés par MessagerieController) + changer-mot-de-passe.
$routes->get('cra-convoc-menu', 'CraConvocMenuController::index', ['filter' => 'craconvocauth']);

// ── E011 Menu JA ─────────────────────────────────────────────────────────────
// Rôle « JA » UNIQUEMENT (compte juge-arbitre dont la fiche est utilisateur.Id_Ja, créé par
// EN15 « Invitation JA » ou EA86) — voir JaAuth.php ("jaauth"). L'Administrateur n'y a PAS accès : ces
// écrans portent sur la fiche du compte $_SESSION['utilisateur']['id'] (idJaDuCompteJa()), ou en mode JA (compte non-JA, fiche
// liée, POST changer-compte) sur $_SESSION['utilisateur']['id_ja'] (rôle JA + fiche existante / lien
// revérifiés par le filtre ET chaque contrôleur, idJaCompteSession()), jamais un id client.
// Le rôle JA n'a accès qu'à ces écrans + changer-mot-de-passe ("auth:…,JA") ; "auth" sans argument
// le refuse. Les pages publiques tokenisées EN19/EN21/EN22 restent valables pour les JA sans compte.
$routes->get('ja-menu', 'JaMenuController::index', ['filter' => 'jaauth']);

// ── EJA2 Mes disponibilités — logique et vue d'EN22 (JaDisponibiliteController extends DisponibiliteJaController) ──
// Mêmes actions AJAX qu'EN22, fiche = Id du compte de session (paramètres ja / id_ja ignorés).
$routes->get('ja/disponibilites', 'JaDisponibiliteController::index', ['filter' => 'jaauth']);
$routes->get('ja/disponibilites/ja', 'JaDisponibiliteController::ja', ['filter' => 'jaauth']);
$routes->get('ja/disponibilites/journees', 'JaDisponibiliteController::journees', ['filter' => 'jaauth']);
$routes->post('ja/disponibilites/sauvegarder-dispo-journee', 'JaDisponibiliteController::sauvegarderDispoJournee', ['filter' => 'jaauth']);
$routes->get('ja/disponibilites/lire-note', 'JaDisponibiliteController::lireNote', ['filter' => 'jaauth']);
$routes->post('ja/disponibilites/sauvegarder-note', 'JaDisponibiliteController::sauvegarderNote', ['filter' => 'jaauth']);
$routes->post('ja/disponibilites/sauvegarder-defiscalisation', 'JaDisponibiliteController::sauvegarderDefiscalisation', ['filter' => 'jaauth']);
$routes->post('ja/disponibilites/sauvegarder-arbitrage-voisins', 'JaDisponibiliteController::sauvegarderArbitrageVoisins', ['filter' => 'jaauth']);

// ── EJA4 Mes nominations et frais / péages / défiscalisation (ex-EJA3 « Mes nominations » fusionné, code EJA3 libre) — règles et actions d'EN21 (JaFraisController extends ConvocationJaController) ──
// Autorisation : nomination appartenant au JA de la session (au lieu du jeton cnv) ; dates J <= D (accusé),
// J >= D (frais, accusé requis le jour même), justificatif via JustificatifPeage (multipart `justificatif`).
$routes->get('ja/frais', 'JaFraisController::index', ['filter' => 'jaauth']);
$routes->get('ja/frais/data', 'JaFraisController::data', ['filter' => 'jaauth']);
$routes->post('ja/frais/accuse', 'JaFraisController::accuser', ['filter' => 'jaauth']);
$routes->post('ja/frais/sauvegarder', 'JaFraisController::sauvegarderFrais', ['filter' => 'jaauth']);
$routes->post('ja/frais/justificatif', 'JaFraisController::joindreJustificatif', ['filter' => 'jaauth']);
$routes->get('ja/frais/justificatif/voir', 'JaFraisController::voirJustificatif', ['filter' => 'jaauth']);

// ── EC71 Compétitions CRA — CRUD de la table CRA_Competition ─────────────────
// Accès depuis E009. Rôle « CRA Convoc » ou Administrateur (filtre "craconvocauth").
$routes->get('cra-competition', 'CraCompetitionController::index', ['filter' => 'craconvocauth']);
$routes->get('cra-competition/data', 'CraCompetitionController::data', ['filter' => 'craconvocauth']);
$routes->get('cra-competition/clubs', 'CraCompetitionController::clubs', ['filter' => 'craconvocauth']);
$routes->post('cra-competition', 'CraCompetitionController::store', ['filter' => 'craconvocauth']);
$routes->put('cra-competition/(:num)', 'CraCompetitionController::update/$1', ['filter' => 'craconvocauth']);
$routes->delete('cra-competition/(:num)', 'CraCompetitionController::delete/$1', ['filter' => 'craconvocauth']);

// ── EC73 Désignation CRA — JA et adjoints d'une compétition (table CRA_Designation) ──
// Accès depuis E009. Rôle « CRA Convoc » ou Administrateur (filtre "craconvocauth").
$routes->get('cra-designation', 'CraDesignationController::index', ['filter' => 'craconvocauth']);
$routes->get('cra-designation/data', 'CraDesignationController::data', ['filter' => 'craconvocauth']);
$routes->get('cra-designation/(:num)', 'CraDesignationController::show/$1', ['filter' => 'craconvocauth']);
$routes->post('cra-designation/(:num)', 'CraDesignationController::save/$1', ['filter' => 'craconvocauth']);
// Envoi des convocations (messages « CRA Convocation … » de la table messagerie, éditables en EA93), un appel par compétition cochée ; journalisé dans CRA_Designation.DateConvocation.
$routes->post('cra-designation/convocations', 'CraDesignationController::convocations', ['filter' => 'craconvocauth']);
$routes->delete('cra-designation/(:num)', 'CraDesignationController::delete/$1', ['filter' => 'craconvocauth']);

// ── EC74 Disponibilités CRA — demande par email / saisie des dispos des JA (table CRA_Dispo) ──
// Accès depuis E009. Rôle « CRA Convoc » ou Administrateur (filtre "craconvocauth").
$routes->get('cra-dispo', 'CraDispoController::index', ['filter' => 'craconvocauth']);
$routes->get('cra-dispo/data', 'CraDispoController::data', ['filter' => 'craconvocauth']);
$routes->get('cra-dispo/(:num)', 'CraDispoController::show/$1', ['filter' => 'craconvocauth']);
$routes->post('cra-dispo/envoyer', 'CraDispoController::envoyer', ['filter' => 'craconvocauth']);
$routes->post('cra-dispo/(:num)/saisie', 'CraDispoController::saisie/$1', ['filter' => 'craconvocauth']);
// Réglage « choix étendus » (À confirmer / Disponible sous condition proposés ou non) — clé configuration.cra_dispo_choix_etendus.
$routes->post('cra-dispo/reglage', 'CraDispoController::reglage', ['filter' => 'craconvocauth']);
// Import de la matrice Excel : aperçu sans écriture, puis validation (le navigateur renvoie le fichier, rien n'est conservé).
$routes->post('cra-dispo/import/apercu', 'CraDispoController::importApercu', ['filter' => 'craconvocauth']);
$routes->post('cra-dispo/import/valider', 'CraDispoController::importValider', ['filter' => 'craconvocauth']);
// Page PUBLIQUE EC74 (sans authentification, comme EN22) : réponse du JA via ?ja=TOKEN (Obfuscator + pepper).
// POST protégé par le filtre csrf global (csrf_field() dans le formulaire).
$routes->get('dispo-cra', 'CraDispoController::formulaire');
$routes->post('dispo-cra', 'CraDispoController::valider');

// ── EC75 Statistiques CRA — JA disponibles (CRA_Dispo) et nombre de désignations (CRA_Designation), lecture seule ──
// Accès depuis E009. Rôle « CRA Convoc » ou Administrateur (filtre "craconvocauth"). data : ?competition=ID facultatif.
$routes->get('cra-stats', 'CraStatsController::index', ['filter' => 'craconvocauth']);
$routes->get('cra-stats/data', 'CraStatsController::data', ['filter' => 'craconvocauth']);

// ── EC72 Degrés Juge-Arbitre — CRUD de la table de référence JugeArbitre ─────
// Accès depuis E009. Rôle « CRA Convoc » ou Administrateur (filtre "craconvocauth").
// Code non modifiable (= colonne de `ja`) ; suppression refusée si un JA a cette colonne à 1.
$routes->get('cra-juge-arbitre', 'CraJugeArbitreController::index', ['filter' => 'craconvocauth']);
$routes->get('cra-juge-arbitre/data', 'CraJugeArbitreController::data', ['filter' => 'craconvocauth']);
$routes->post('cra-juge-arbitre', 'CraJugeArbitreController::store', ['filter' => 'craconvocauth']);
$routes->put('cra-juge-arbitre/(:num)', 'CraJugeArbitreController::update/$1', ['filter' => 'craconvocauth']);
$routes->delete('cra-juge-arbitre/(:num)', 'CraJugeArbitreController::delete/$1', ['filter' => 'craconvocauth']);

// ── ED51 Défiscalisation JA ──────────────────────────────────────────────────
$routes->get('defiscalisation', 'DefiscalisationController::index', ['filter' => 'defiscauth']);
$routes->post('defiscalisation/donnees', 'DefiscalisationController::donnees', ['filter' => 'defiscauth']);
$routes->post('defiscalisation/relancer-vehicule', 'DefiscalisationController::relancerVehicule', ['filter' => 'defiscauth']);
$routes->post('defiscalisation/export-csv', 'DefiscalisationController::exportCsv', ['filter' => 'defiscauth']);

// ── ED52 Barème kilométrique — gestion de la table ComptaDefiscalisation ──────
// Accès depuis ED51. Rôle Defiscalisateur ou Administrateur (filtre "defiscauth").
$routes->get('defiscalisation-bareme', 'DefiscalisationBaremeController::index', ['filter' => 'defiscauth']);
$routes->post('defiscalisation-bareme/enregistrer', 'DefiscalisationBaremeController::enregistrer', ['filter' => 'defiscauth']);

// ── ED53 Attestation sur l'honneur défiscalisation ───────────────────────────
// Public tokenisé (?ja=TOKEN, lien de l'email n°10) OU session Defiscalisateur/
// Administrateur (menu E005, bouton ED51). Pas de filtre : accès vérifié dans le
// contrôleur (token valide OU session). `valider` exige un token valide.
$routes->get('attestation-defisc', 'AttestationDefiscController::index');
$routes->post('attestation-defisc/valider', 'AttestationDefiscController::valider');

// ── ED54 Attestations reçues — liste du répertoire _Defiscalisation/ ───────────
// Menu E005. Rôle Defiscalisateur ou Administrateur (filtre "defiscauth").
$routes->get('attestations-defisc', 'AttestationsListeController::index', ['filter' => 'defiscauth']);
$routes->get('attestations-defisc/telecharger/(:num)', 'AttestationsListeController::telecharger/$1', ['filter' => 'defiscauth']);
$routes->get('attestations-defisc/carte-grise/(:num)', 'AttestationsListeController::carteGrise/$1', ['filter' => 'defiscauth']);
