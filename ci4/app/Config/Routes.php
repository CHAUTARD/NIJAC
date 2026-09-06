<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
// Racine du vhost "nijac" : point d'entrée de l'appli (E001), pas la page
// de bienvenue CI4 par défaut.
$routes->get('/', 'AuthController::index');

// ── E001 Connexion ──────────────────────────────────────────────────────────
$routes->get('login', 'AuthController::index');
$routes->post('login', 'AuthController::index');

// ── E002 Menu administrateur ─────────────────────────────────────────────────
$routes->get('admin-menu', 'AdminMenuController::index', ['filter' => 'adminauth']);

// ── E003 Menu nominateur ─────────────────────────────────────────────────────
$routes->get('nominateur-menu', 'NominateurMenuController::index', ['filter' => 'auth']);
$routes->get('nominateur-menu/convocations-a-envoyer', 'NominateurMenuController::convocationsAEnvoyer', ['filter' => 'auth']);
$routes->get('nominateur-menu/rencontres-sans-ja', 'NominateurMenuController::rencontresSansJa', ['filter' => 'auth']);

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
$routes->get('messagerie', 'MessagerieController::index', ['filter' => 'auth']);
$routes->get('messagerie/data', 'MessagerieController::data', ['filter' => 'auth']);
$routes->get('messagerie/data/(:segment)', 'MessagerieController::show/$1', ['filter' => 'auth']);
$routes->post('messagerie', 'MessagerieController::store', ['filter' => 'auth']);
$routes->put('messagerie/(:segment)', 'MessagerieController::update/$1', ['filter' => 'auth']);
$routes->post('messagerie/(:segment)/dupliquer', 'MessagerieController::duplicate/$1', ['filter' => 'auth']);
$routes->delete('messagerie/(:segment)', 'MessagerieController::delete/$1', ['filter' => 'auth']);

// ── EN15 Centre d'envoi ──────────────────────────────────────────────────────
$routes->get('centrenvoye', 'CentrenvoyeController::index', ['filter' => 'auth']);
$routes->get('centrenvoye/journees', 'CentrenvoyeController::journees', ['filter' => 'auth']);
$routes->get('centrenvoye/ja', 'CentrenvoyeController::ja', ['filter' => 'auth']);
$routes->post('centrenvoye/apercu', 'CentrenvoyeController::apercu', ['filter' => 'auth']);
$routes->post('centrenvoye/envoyer', 'CentrenvoyeController::envoyer', ['filter' => 'auth']);
$routes->post('centrenvoye/envoyer-un', 'CentrenvoyeController::envoyerUn', ['filter' => 'auth']);

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
$routes->post('clean/executer', 'CleanController::executer', ['filter' => 'adminauth']);
$routes->post('clean/sauvegarde-totale', 'CleanController::sauvegardeTotale', ['filter' => 'adminauth']);
$routes->post('clean/sauvegarde-table', 'CleanController::sauvegardeTable', ['filter' => 'adminauth']);
$routes->post('clean/restaurer', 'CleanController::restaurer', ['filter' => 'adminauth']);
$routes->post('clean/restaurer-total', 'CleanController::restaurerTotal', ['filter' => 'adminauth']);
$routes->post('clean/restaurer-table', 'CleanController::restaurerTableFull', ['filter' => 'adminauth']);

// ── EN11 Juges-Arbitres ──────────────────────────────────────────────────────
// Filtre "auth" (pas "adminauth") : accessible aux Nominateurs (grille +
// import EBP), import Excel/API FFTT et maj Id_LaPoste ponctuelle restreints
// à l'admin dans le contrôleur, comme le fait jugearbitre.php.
$routes->get('jugearbitre', 'JugearbitreController::index', ['filter' => 'auth']);
$routes->get('jugearbitre/liste', 'JugearbitreController::liste', ['filter' => 'auth']);
$routes->get('jugearbitre/clubs', 'JugearbitreController::clubsParDept', ['filter' => 'auth']);
$routes->post('jugearbitre/laposte', 'JugearbitreController::majLaposte', ['filter' => 'auth']);
$routes->post('jugearbitre/maj-bdd', 'JugearbitreController::majBdd', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/clubs-dept', 'JugearbitreController::getClubsDept', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/reset-actif-dept', 'JugearbitreController::reinitialiserActifDept', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/import-club', 'JugearbitreController::importFfttClub', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/scan-club', 'JugearbitreController::scanFfttClub', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/import-selected', 'JugearbitreController::importFfttSelected', ['filter' => 'auth']);
$routes->post('jugearbitre/import-csv-ebp', 'JugearbitreController::importCsvEbp', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/enrichir', 'JugearbitreController::enrichirFftt', ['filter' => 'auth']);
$routes->post('jugearbitre/import-excel', 'JugearbitreController::importerExcel', ['filter' => 'auth']);
$routes->post('jugearbitre/fftt/reset-actif-tous', 'JugearbitreController::reinitialiserActifTous', ['filter' => 'auth']);

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
$routes->get('nomination', 'NominationController::index', ['filter' => 'auth']);
$routes->get('nomination/journees', 'NominationController::journees', ['filter' => 'auth']);
$routes->get('nomination/rencontres-journee', 'NominationController::rencontresJournee', ['filter' => 'auth']);
$routes->get('nomination/candidats-journee', 'NominationController::candidatsJournee', ['filter' => 'auth']);
$routes->post('nomination/affecter-ja', 'NominationController::affecterJa', ['filter' => 'auth']);
$routes->post('nomination/retirer-ja', 'NominationController::retirerJa', ['filter' => 'auth']);
$routes->post('nomination/valider', 'NominationController::validerNominations', ['filter' => 'auth']);
$routes->post('nomination/envoyer-convocations', 'NominationController::envoyerConvocations', ['filter' => 'auth']);
$routes->get('nomination/message-arbitre-club', 'NominationController::messageArbitreClub', ['filter' => 'auth']);
$routes->post('nomination/demander-ja-club', 'NominationController::demanderJaClub', ['filter' => 'auth']);

// ── EN26 Statistiques des nominations ────────────────────────────────────────
// Ouvert dans une nouvelle fenêtre depuis EN14. Récapitulatif journées/rencontres
// + JA nominés ; la modification du JA réutilise nomination/affecter-ja|retirer-ja.
$routes->get('stats-nomination', 'StatsNominationController::index', ['filter' => 'auth']);
$routes->get('stats-nomination/data', 'StatsNominationController::data', ['filter' => 'auth']);

// ── EN18 Désidératas club ────────────────────────────────────────────────────
// Page PUBLIQUE (sans authentification), tokenisée par ?club=<Id_Club> — lien
// envoyé par email depuis EN12. Pas de filtre "auth"/"adminauth" ici.
$routes->get('desiderata-club', 'DesiderataClubController::index');
$routes->get('desiderata-club/charger', 'DesiderataClubController::charger');
$routes->post('desiderata-club/enregistrer', 'DesiderataClubController::enregistrer');

// ── EN25 Juge-arbitre du club ────────────────────────────────────────────────
// Page PUBLIQUE, tokenisée par ?renc=TOKEN (Obfuscator Id_Rencontre) — lien
// envoyé au correspondant du club recevant depuis EN14 (message n°7).
$routes->get('arbitre-club', 'ArbitreClubController::index');
$routes->post('arbitre-club/enregistrer', 'ArbitreClubController::enregistrer');

// ── EN12 Désidératas clubs ───────────────────────────────────────────────────
// Nominateur ou Administrateur — retiré du menu CSR (E004), remis dans le menu
// nominateur (E003). Rôle CSR toujours techniquement autorisé par le filtre
// "auth" (comme partout ailleurs), mais sans lien de menu vers cet écran.
$routes->get('desiderata-clubs', 'DesiderataClubsController::index', ['filter' => 'auth']);
$routes->get('desiderata-clubs/liste', 'DesiderataClubsController::liste', ['filter' => 'auth']);
$routes->get('desiderata-clubs/departements', 'DesiderataClubsController::departements', ['filter' => 'auth']);
$routes->get('desiderata-clubs/detail', 'DesiderataClubsController::detail', ['filter' => 'auth']);
$routes->get('desiderata-clubs/ja-club', 'DesiderataClubsController::jaClub', ['filter' => 'auth']);
$routes->get('desiderata-clubs/apercu', 'DesiderataClubsController::apercu', ['filter' => 'auth']);
$routes->post('desiderata-clubs/envoyer', 'DesiderataClubsController::envoyer', ['filter' => 'auth']);

// ── EN16 Comptabilité frais JA ───────────────────────────────────────────────
$routes->get('compta', 'ComptaController::index', ['filter' => 'auth']);
$routes->post('compta/donnees', 'ComptaController::donnees', ['filter' => 'auth']);
$routes->post('compta/export-csv', 'ComptaController::exportCsv', ['filter' => 'auth']);

// ── EN17 Statistiques JA ─────────────────────────────────────────────────────
$routes->get('stats-ja', 'StatsJaController::index', ['filter' => 'auth']);
$routes->get('stats-ja/donnees', 'StatsJaController::donnees', ['filter' => 'auth']);
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

// ── EN20 Fiche personnelle JA ────────────────────────────────────────────────
// Page PUBLIQUE (sans authentification), tokenisée par ?ja=TOKEN — comme
// EN19/EN21/EN22. Pas de rôle JA ni de login dédié (voir AuthController).
// Pas de filtre de route : la résolution (token, ou session Nominateur/Admin)
// est faite manuellement dans le contrôleur.
$routes->get('info-rencontre', 'InfoRencontreController::index');
$routes->post('info-rencontre/se-designer', 'InfoRencontreController::seDesigner');
$routes->post('info-rencontre/recherche-laposte', 'InfoRencontreController::rechercheLaposte');
$routes->post('info-rencontre/sauvegarder-adresse', 'InfoRencontreController::sauvegarderAdresse');

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

// ── EA94 Gestion des équipes ─────────────────────────────────────────────────
$routes->get('gestion-equipes', 'EquipeAdminController::index', ['filter' => 'adminauth']);
$routes->get('gestion-equipes/data', 'EquipeAdminController::data', ['filter' => 'adminauth']);
$routes->put('gestion-equipes/(:num)', 'EquipeAdminController::update/$1', ['filter' => 'adminauth']);

// ── EA95 Gestion des rencontres ──────────────────────────────────────────────
$routes->get('gestion-rencontres', 'RencontreAdminController::index', ['filter' => 'adminauth']);
$routes->get('gestion-rencontres/data', 'RencontreAdminController::data', ['filter' => 'adminauth']);
$routes->get('gestion-rencontres/doublons', 'RencontreAdminController::doublons', ['filter' => 'adminauth']);
$routes->put('gestion-rencontres/(:num)', 'RencontreAdminController::update/$1', ['filter' => 'adminauth']);
$routes->delete('gestion-rencontres/(:num)', 'RencontreAdminController::delete/$1', ['filter' => 'adminauth']);

// ── EA98 Administration base de données ──────────────────────────────────────
// Admin uniquement (filtre "adminauth"), + restriction supplémentaire
// login === 'CHAUTARD' vérifiée manuellement dans le contrôleur (même règle
// que EA96). Outil "bris de glace" : requêteur SQL libre, sans restriction.
$routes->get('db-admin', 'DbAdminController::index', ['filter' => 'adminauth']);
$routes->get('db-admin/tables', 'DbAdminController::tables', ['filter' => 'adminauth']);
$routes->post('db-admin/sql', 'DbAdminController::sql', ['filter' => 'adminauth']);

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
$routes->get('logout', 'LogoutController::index');

// ── E006 Changement du mot de passe ─────────────────────────────────────────
// Ouverte depuis la modale toolbar (_modal_mdp.php) sur toute page CI4
// authentifiée. Filtre "auth" : redirige déjà le rôle JA ailleurs, comme le
// fait includes/auth_required.php côté legacy pour ce fichier.
$routes->get('changer-mot-de-passe', 'ChangerMotDePasseController::index', ['filter' => 'auth']);
$routes->post('changer-mot-de-passe', 'ChangerMotDePasseController::index', ['filter' => 'auth']);

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
$routes->post('convocation-ja/sauvegarder-frais', 'ConvocationJaController::sauvegarderFrais');

// ── EN22 Disponibilité JA ────────────────────────────────────────────────────
// Page PUBLIQUE (sans authentification) — accessible via ?ja=TOKEN ou ?id_ja=N.
$routes->get('disponibilite-ja', 'DisponibiliteJaController::index');
$routes->get('disponibilite-ja/liste-ja', 'DisponibiliteJaController::listeJa');
$routes->get('disponibilite-ja/ja', 'DisponibiliteJaController::ja');
$routes->get('disponibilite-ja/journees', 'DisponibiliteJaController::journees');
$routes->post('disponibilite-ja/sauvegarder-dispo-journee', 'DisponibiliteJaController::sauvegarderDispoJournee');
// "token" est public dans ce fichier (contrairement à EN19/adresse_ja.php) :
// le legacy n'y appelle jamais auth_required.php pour cette action précise.
$routes->get('disponibilite-ja/token', 'DisponibiliteJaController::token');
$routes->post('disponibilite-ja/token', 'DisponibiliteJaController::token');
$routes->get('disponibilite-ja/lire-note', 'DisponibiliteJaController::lireNote');
$routes->post('disponibilite-ja/sauvegarder-note', 'DisponibiliteJaController::sauvegarderNote');
$routes->post('disponibilite-ja/sauvegarder-defiscalisation', 'DisponibiliteJaController::sauvegarderDefiscalisation');
$routes->post('disponibilite-ja/sauvegarder-arbitrage-voisins', 'DisponibiliteJaController::sauvegarderArbitrageVoisins');

// ── E004 Menu CSR ────────────────────────────────────────────────────────────
// Rôle CSR (Commission Sportive Régionale) ou Administrateur — voir CsrAuth.php.
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

// ── EN23 Disponibilités JA Championnat Régional ─────────────────────────────
// Page PUBLIQUE (sans authentification), tokenisée par ?ja=TOKEN — comme
// EN19/EN20/EN21/EN22. Remplace le formulaire Excel envoyé par email (message
// système n°8, voir assurerTemplateDispoRegionale()).
$routes->get('dispo-regionale-ja', 'DispoRegionaleJaController::index');
$routes->post('dispo-regionale-ja/sauvegarder', 'DispoRegionaleJaController::sauvegarder');

// ── E005 Menu Défiscalisateur ────────────────────────────────────────────────
// Rôle Defiscalisateur ou Administrateur — voir DefiscalisateurAuth.php.
$routes->get('defiscalisateur-menu', 'DefiscalisateurMenuController::index', ['filter' => 'defiscauth']);

// ── ED51 Défiscalisation JA ──────────────────────────────────────────────────
$routes->get('defiscalisation', 'DefiscalisationController::index', ['filter' => 'defiscauth']);
$routes->post('defiscalisation/donnees', 'DefiscalisationController::donnees', ['filter' => 'defiscauth']);
$routes->post('defiscalisation/vehicule', 'DefiscalisationController::vehicule', ['filter' => 'defiscauth']);
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
