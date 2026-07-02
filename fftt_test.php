<?php
/**
 * NIJAC – Test API FFTT (E018)
 *
 * Permet de vérifier la connexion à l'API FFTT et de tester les principaux
 * endpoints disponibles depuis l'interface d'administration.
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require __DIR__ . '/includes/admin_required.php';
if (($_SESSION['utilisateur']['login'] ?? '') !== 'CHAUTARD') {
    header('Location: admin_menu.php');
    exit;
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // Capture les erreurs fatales (E_ERROR, memory, etc.) non attrapables par try/catch
    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            while (ob_get_level()) ob_end_clean();
            echo json_encode([
                'ok'    => false,
                'msg'   => 'Erreur fatale PHP : ' . $err['message'],
                'trace' => [['fichier' => $err['file'] . ':' . $err['line'], 'message' => $err['message']]],
            ]);
        }
    });

    // CSRF en premier, avant tout ob_start — sinon exit() ne flush pas le buffer
    // Le ping est exempté : il sert justement à tester la chaîne JS→PHP sans CSRF
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'ping') csrfVerify(true);

    // Capture warnings/notices PHP après la vérification CSRF
    $phpWarnings = [];
    set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$phpWarnings) {
        $phpWarnings[] = "[$errno] $errstr — $errfile:$errline";
        return true;
    });

    ob_start(); // Capture tout output parasite APRÈS le CSRF

    $trace = [];

    try {
        // ── Ping : test minimal sans appel API ───────────────────────────────
        if ($action === 'ping') {
            ob_end_clean();
            echo jsonSafe(['ok' => true, 'msg' => 'PHP répond correctement', 'php' => PHP_VERSION, 'warnings' => $phpWarnings]);
            exit;
        }

        $trace[] = ['étape' => '1. Credentials', 'app_id' => getFfttAppId(), 'app_key_len' => strlen(getFfttAppKey())];

        $api = getFfttApi();

        $trace[] = ['étape' => '2. FfttApi instanciée', 'serial' => getConfig('fftt_serial', '(non généré)')];

        switch ($action) {
            case 'test_clubs_dep':
                $dep = trim($_POST['dep'] ?? '76');
                $trace[] = ['étape' => '3. Appel xml_club_dep2', 'dep' => $dep];
                $clubs = $api->getClubsDepartement($dep);
                $trace[] = ['étape' => '4. Réponse reçue', 'count' => count($clubs)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'count' => count($clubs), 'data' => array_slice($clubs, 0, 5), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_licence':
                $licence = trim($_POST['licence'] ?? '');
                if ($licence === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Numéro de licence requis', 'trace' => $trace]); break; }
                $trace[] = ['étape' => '3. Appel xml_licence', 'licence' => $licence];
                $data = $api->getLicence($licence);
                $trace[] = ['étape' => '4. Réponse reçue', 'vide' => ($data === null)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_equipes':
                $club = trim($_POST['club'] ?? '');
                if ($club === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace]); break; }
                $trace[] = ['étape' => '3. Appel xml_equipe', 'club' => $club];
                $data = $api->getEquipesClub($club);
                $trace[] = ['étape' => '4. Réponse reçue', 'count' => count($data)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'count' => count($data), 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_club_detail':
                $club = trim($_POST['club'] ?? '');
                if ($club === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace]); break; }
                $trace[] = ['étape' => '3. Appel xml_club_detail', 'club' => $club];
                $data = $api->getClubDetail($club);
                $trace[] = ['étape' => '4. Réponse reçue', 'clés' => array_keys($data)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'debug_club_salle':
                $club = trim($_POST['club'] ?? '');
                if ($club === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace]); break; }
                $data = $api->getClubDetail($club);
                $champsSalle = ['nomsalle','adressesalle1','adressesalle2','adressesalle3','codepsalle','villesalle'];
                $analyse = [];
                foreach ($champsSalle as $champ) {
                    $val = $data[$champ] ?? null;
                    $analyse[$champ] = [
                        'type'   => gettype($val),
                        'count'  => is_array($val) ? count($val) : null,
                        'valeur' => $val,
                    ];
                }
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'club' => $club, 'analyse' => $analyse, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_licence_b':
                $licence = trim($_POST['licence'] ?? '');
                if ($licence === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Numéro de licence requis', 'trace' => $trace]); break; }
                $trace[] = ['étape' => '3. Appel xml_licence_b', 'licence' => $licence];
                $data = $api->request('xml_licence_b', ['licence' => $licence]);
                $trace[] = ['étape' => '4. Réponse reçue', 'clés' => array_keys($data)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_arbitres_dep':
                $dep = trim($_POST['dep'] ?? '76');
                $trace[] = ['étape' => '3. Récupération clubs + membres', 'dep' => $dep];
                set_time_limit(120); // l'opération peut prendre du temps
                $arbitres = $api->getArbitresDepartement($dep);
                $trace[] = ['étape' => '4. Terminé', 'arbitres_trouvés' => count($arbitres)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'count' => count($arbitres), 'data' => $arbitres, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_spid_club':
                $club = trim($_POST['club'] ?? '');
                if ($club === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace]); break; }
                $trace[] = ['étape' => '3. Appel xml_liste_joueur_o', 'club' => $club];
                $membres = $api->getLicenciesClub($club);
                $trace[] = ['étape' => '4. Réponse reçue', 'count' => count($membres)];
                // On retourne le premier membre avec TOUS ses champs pour analyse
                $exemple = $membres[0] ?? null;
                $champs  = $exemple ? array_keys($exemple) : [];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'count' => count($membres), 'champs' => $champs, 'data' => $exemple, 'trace' => $trace]);
                break;

            case 'test_organisme':
                $type = trim($_POST['type'] ?? 'L');
                $data = $api->request('xml_organisme', ['type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_epreuve':
                $org  = trim($_POST['organisme'] ?? '');
                $type = trim($_POST['type'] ?? 'E');
                if ($org === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'ID organisme requis']); break; }
                $data = $api->request('xml_epreuve', ['organisme' => $org, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_division':
                $org     = trim($_POST['organisme'] ?? '');
                $epreuve = trim($_POST['epreuve']   ?? '');
                $type    = trim($_POST['type']       ?? 'E');
                if ($org === '' || $epreuve === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Organisme et épreuve requis']); break; }
                $data = $api->request('xml_division', ['organisme' => $org, 'epreuve' => $epreuve, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_poule':
                $org     = trim($_POST['organisme'] ?? '');
                $epreuve = trim($_POST['epreuve']   ?? '');
                $division = trim($_POST['division'] ?? '');
                $type    = trim($_POST['type']       ?? 'E');
                if ($org === '' || $epreuve === '' || $division === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Organisme, épreuve et division requis']); break; }
                $data = $api->request('xml_poule', ['organisme' => $org, 'epreuve' => $epreuve, 'division' => $division, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_rencontre':
                // Flux correct : action=poule → récupérer cx_poules → xml_rencontre_equ
                $division = trim($_POST['division'] ?? '');
                $type     = trim($_POST['type']     ?? 'E');
                if ($division === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Division requise']); break; }

                // Étape 1 : liste des poules
                $rPoules    = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $division, 'auto' => '1', 'type' => $type]);
                $urlPoules  = $api->lastUrl();
                $pouleItems = isset($rPoules['poule']) ? (isset($rPoules['poule'][0]) ? $rPoules['poule'] : [$rPoules['poule']]) : [];

                $cxPoules = [];
                foreach ($pouleItems as $p) {
                    $lienRaw = $p['lien'] ?? '';
                    $lienStr = is_array($lienRaw) ? '' : (string)$lienRaw;
                    parse_str(html_entity_decode($lienStr), $lp);
                    if (!empty($lp['cx_poule'])) $cxPoules[] = $lp['cx_poule'];
                }

                // Étape 2 : rencontres via xml_rencontre_equ
                $rencData = null; $urlRenc = null;
                if (!empty($cxPoules)) {
                    $rencData = $api->request('xml_rencontre_equ', ['poule' => implode('|', $cxPoules)]);
                    $urlRenc  = $api->lastUrl();
                }

                ob_end_clean();
                echo jsonSafe(['ok' => true, 'poules' => $pouleItems, 'cx_poules' => $cxPoules,
                    'url_poules' => $urlPoules, 'xml_poules_brut' => $api->lastRaw(),
                    'rencontres' => $rencData, 'url_rencontres' => $urlRenc,
                    'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_rencontre_poule':
                $cxPoule = trim($_POST['cx_poule'] ?? '');
                $d1      = trim($_POST['D1']       ?? '');
                $type    = trim($_POST['type']     ?? 'E');
                if ($cxPoule === '' || $d1 === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'cx_poule et D1 requis']); break; }
                $data = $api->request('xml_result_equ', ['cx_poule' => $cxPoule, 'D1' => $d1, 'auto' => '1', 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(),
                    'xml_brut' => $api->lastRaw(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_chp_renc':
                $idRenc = trim($_POST['id_rencontre'] ?? '');
                if ($idRenc === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'id_rencontre requis']); break; }
                $data = $api->request('xml_chp_renc', ['id_rencontre' => $idRenc]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_result_equ':
                $club = trim($_POST['club'] ?? '');
                $equ  = trim($_POST['equ']  ?? '');
                if ($club === '' || $equ === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Club et équipe requis']); break; }
                $data = $api->request('xml_result_equ', ['club' => $club, 'equ' => $equ]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            // ── Analyse détection nationale pour un club ─────────────────────
            case 'test_equipe_nat':
                $numclu = trim($_POST['club'] ?? '');
                if ($numclu === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'N° club requis']); break; }

                // Récupérer les divisions nationales actives (phase à venir)
                $trace[] = ['étape' => '3. Chargement divisions nationales actives'];
                $natDivDebug = [];
                $natDivIds = fetchNatDivIds($api, '', $natDivDebug);
                $trace[] = ['étape' => '4. Divisions actives', 'count' => count($natDivIds), 'ids' => $natDivIds, 'debug' => $natDivDebug];

                $trace[] = ['étape' => '5. xml_equipe', 'numclu' => $numclu];
                $equipes = $api->getEquipesClub($numclu);
                $trace[] = ['étape' => '6. Réponse', 'nb_equipes' => count($equipes)];

                $analyse = [];
                foreach ($equipes as $eq) {
                    $lib     = trim((string)($eq['libequipe'] ?? $eq['libelle'] ?? ''));
                    $ndiv    = trim((string)($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? $eq['libelledivision'] ?? ''));
                    $d1      = extractD1($eq);
                    $divCode = detectNatCode($eq);

                    // Extraire la phase depuis ndivision (ex: "FED_Nationale 1 Messieurs Phase 1 Poule 1" → "1")
                    $phaseDiv = '';
                    if (preg_match('/\bPhase\s+(\d+)\b/i', $ndiv, $pm)) $phaseDiv = $pm[1];

                    $analyse[] = [
                        'libelle'    => $lib,
                        'champ_div'  => $ndiv !== '' ? $ndiv : '(vide)',
                        'd1'         => $d1 ?: '—',
                        'phase_div'  => $phaseDiv ?: '—',
                        'tous_champs'=> $eq,
                        'détecté'    => $divCode !== null,
                        'code_div'   => $divCode,
                    ];
                }

                $trace[] = ['étape' => '7. Nationales retenues', 'count' => count(array_filter($analyse, fn($a) => $a['détecté']))];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'analyse' => $analyse, 'nat_div_ids' => $natDivIds,
                    'url' => $api->lastUrl(), 'http' => $api->lastHttp(),
                    'trace' => $trace, 'warnings' => $phpWarnings, 'xml_brut' => $api->lastRaw()]);
                break;

            // ── Scan département → clubs avec équipes nationales ─────────────
            case 'scan_dept_nat':
                $dep   = trim($_POST['dep']   ?? '76');
                $phase = trim($_POST['phase'] ?? '');
                set_time_limit(300);

                // Charger une seule fois les divisions nationales actives
                $trace[] = ['étape' => '3. Chargement divisions nationales actives', 'phase' => $phase ?: 'toutes'];
                $natDivDebug = [];
                $natDivIds = fetchNatDivIds($api, $phase, $natDivDebug);
                $trace[] = ['étape' => '4. Divisions actives', 'count' => count($natDivIds), 'ids' => $natDivIds, 'debug' => $natDivDebug];

                $trace[] = ['étape' => '5. Clubs du département', 'dep' => $dep];
                $clubs = $api->getClubsDepartement($dep);
                $trace[] = ['étape' => '6. ' . count($clubs) . ' club(s) à scanner'];

                // Si les divisions actives n'ont pas pu être chargées, on désactive le filtre D1
                // pour ne pas bloquer toutes les équipes — on tombe en mode détection par nom seul.
                $filtreD1 = !empty($natDivIds);
                $trace[] = ['étape' => '6b. Filtre D1 actif', 'actif' => $filtreD1];

                $resultats = [];
                $detail    = []; // trace club par club

                foreach ($clubs as $c) {
                    $numclu  = trim((string)($c['numero'] ?? $c['numclu'] ?? ''));
                    $nomClub = trim((string)($c['nom'] ?? ''));
                    if ($numclu === '') continue;

                    $clubTrace = ['numclu' => $numclu, 'nom' => $nomClub, 'equipes' => [], 'erreur' => null];

                    try {
                        $equipes    = $api->getEquipesClub($numclu);
                        $clubTrace['url_equipe']  = $api->lastUrl();
                        $clubTrace['http_equipe'] = $api->lastHttp();
                        $clubTrace['nb_equipes']  = count($equipes);
                        // Toujours conserver la réponse brute (utile pour diagnostic)
                        $clubTrace['raw_equipe'] = substr($api->lastRaw(), 0, 800);
                        $nationales = [];

                        foreach ($equipes as $eq) {
                            $lib  = trim((string)($eq['libequipe'] ?? $eq['libelle'] ?? ''));
                            if ($lib === '') continue;
                            $ndiv = trim((string)($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? ''));

                            $eqTrace = ['lib' => $lib, 'ndiv' => $ndiv, 'd1' => extractD1($eq) ?: '—', 'statut' => '', 'code' => null, 'brut' => $eq];

                            $divCode = detectNatCode($eq);
                            if ($divCode === null) {
                                $eqTrace['statut'] = $ndiv !== '' ? 'non_nationale' : 'sans_division';
                                $clubTrace['equipes'][] = $eqTrace;
                                continue;
                            }

                            // Filtre de phase : si une phase est choisie, vérifier "Phase X" dans ndivision
                            if ($phase !== '' && !preg_match('/\bPhase\s+' . preg_quote($phase, '/') . '\b/i', $ndiv)) {
                                $eqTrace['statut'] = 'autre_phase';
                                $clubTrace['equipes'][] = $eqTrace;
                                continue;
                            }

                            $eqTrace['statut'] = 'nationale';
                            $eqTrace['code']   = $divCode;
                            $clubTrace['equipes'][] = $eqTrace;
                            $nationales[] = ['lib' => $lib, 'div' => $divCode, 'ndiv_brut' => $ndiv];
                        }

                        if ($nationales) {
                            $resultats[] = ['numclu' => $numclu, 'nom' => $nomClub, 'nationales' => $nationales];
                        }
                    } catch (\Throwable $e) {
                        $clubTrace['erreur'] = $e->getMessage();
                    }

                    $detail[] = $clubTrace;
                    usleep(150000); // 150ms entre clubs pour éviter le throttling FFTT
                }
                $trace[] = ['étape' => '7. Clubs avec nationale phase à venir', 'count' => count($resultats)];
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'resultats' => $resultats, 'clubs_scannes' => count($clubs),
                    'nat_div_ids' => $natDivIds, 'detail' => $detail,
                    'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            default:
                ob_end_clean();
                echo jsonSafe(['ok' => false, 'msg' => "Action inconnue : $action", 'trace' => $trace]);
        }
    } catch (Throwable $e) {
        $parasite = ob_get_level() ? ob_get_clean() : '';
        $trace[] = [
            'étape'     => 'Exception',
            'class'     => get_class($e),
            'message'   => $e->getMessage(),
            'fichier'   => $e->getFile() . ':' . $e->getLine(),
            'backtrace' => array_map(
                fn($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' → ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                array_slice($e->getTrace(), 0, 8)
            ),
        ];
        echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'url' => isset($api) ? $api->lastUrl() : '', 'http' => isset($api) ? $api->lastHttp() : 0, 'trace' => $trace, 'warnings' => $phpWarnings, 'parasite' => $parasite ?: null]);
    }

    restore_error_handler();
    exit;
}

/**
 * Retourne [iddivision => code] des divisions nationales actives (phase à venir).
 * Utilisé pour valider qu'une équipe est bien inscrite dans une division courante
 * et non dans une division d'une saison passée.
 */
/**
 * Retourne [iddivision => code] des divisions nationales actives.
 * $debug est rempli avec le détail de chaque étape pour afficher dans la trace.
 */
function fetchNatDivIds(FfttApi $api, string $phase = '', array &$debug = []): array
{
    $debug = [];

    // 1. Organisme (ligue)
    $orgId = getConfig('fftt_organisme_id', '');
    $debug['orgId_config'] = $orgId;

    if ($orgId === '') {
        $region = mb_strtolower(getConfig('region', 'Normandie'), 'UTF-8');
        $rep    = $api->request('xml_organisme', ['type' => 'L']);
        $debug['xml_organisme_url'] = $api->lastUrl();
        $orgs   = $rep['organisme'] ?? [];
        if (!empty($orgs) && !isset($orgs[0])) $orgs = [$orgs];
        $debug['organismes'] = array_map(fn($o) => [
            'id'      => $o['id'] ?? $o['ident'] ?? '?',
            'libelle' => $o['libelle'] ?? $o['nom'] ?? '?',
        ], $orgs);
        foreach ($orgs as $org) {
            $lib = mb_strtolower((string)($org['libelle'] ?? $org['nom'] ?? ''), 'UTF-8');
            if (str_contains($lib, $region)) {
                $orgId = (string)($org['id'] ?? $org['ident'] ?? '');
                $debug['orgId_trouvé'] = $orgId;
                break;
            }
        }
        if ($orgId === '') {
            $debug['erreur'] = "Organisme \"$region\" introuvable dans la liste.";
            return [];
        }
    }

    // 2. Épreuves
    $epParams = ['organisme' => $orgId, 'type' => 'E'];
    if ($phase !== '') $epParams['phase'] = $phase;
    $epRep = $api->request('xml_epreuve', $epParams);
    $debug['xml_epreuve_url'] = $api->lastUrl();
    $eps   = $epRep['epreuve'] ?? [];
    if (!empty($eps) && !isset($eps[0])) $eps = [$eps];
    $debug['nb_epreuves_total'] = count($eps);

    $epsNat = [];
    foreach ($eps as $ep) {
        $intitule = (string)($ep['intitule'] ?? $ep['libelle'] ?? '');
        if (preg_match('/nationale/i', $intitule)) $epsNat[] = $ep;
    }
    $debug['epreuves_nationales'] = array_map(fn($e) => [
        'id'       => $e['idepreuve'] ?? $e['ident'] ?? '?',
        'intitule' => $e['intitule']  ?? $e['libelle'] ?? '?',
    ], $epsNat);

    // 3. Divisions pour chaque épreuve nationale
    $ids = [];
    foreach ($epsNat as $ep) {
        $idEp = (string)($ep['idepreuve'] ?? $ep['ident'] ?? '');
        if ($idEp === '') continue;
        $divParams = ['organisme' => $orgId, 'epreuve' => $idEp, 'type' => 'E'];
        if ($phase !== '') $divParams['phase'] = $phase;
        $divRep = $api->request('xml_division', $divParams);
        $divs   = $divRep['division'] ?? [];
        if (!empty($divs) && !isset($divs[0])) $divs = [$divs];
        $debug['divisions'][$idEp] = array_map(fn($d) => [
            'iddivision' => $d['iddivision'] ?? $d['ident'] ?? '?',
            'libelle'    => $d['libelle'] ?? '?',
        ], $divs);
        foreach ($divs as $div) {
            $lib   = (string)($div['libelle'] ?? '');
            $idDiv = (string)($div['iddivision'] ?? $div['ident'] ?? '');
            if ($idDiv === '') continue;
            if (preg_match('/nationale[^\d]*(\d+)[^\w]*(messieurs?|hommes?|dames?|f[eé]minin)/i', $lib, $m)) {
                $code = 'N' . $m[1] . (preg_match('/dame|f[eé]minin/i', $m[2]) ? 'F' : 'M');
            } elseif (preg_match('/\bN(\d+)\s*([MF])\b/i', $lib, $m)) {
                $code = 'N' . $m[1] . strtoupper($m[2]);
            } elseif (preg_match('/\bpro\s*([AB])\b/i', $lib, $m)) {
                $code = 'PRO' . strtoupper($m[1]);
            } else {
                $code = $lib ?: 'NAT';
            }
            $ids[$idDiv] = $code;
        }
    }
    $debug['nb_divisions_nationales'] = count($ids);
    return $ids;
}

/** Extrait le D1 (ID division FFTT) depuis le champ liendivision d'une équipe. */
function extractD1(array $eq): string
{
    // liendivision peut être un tableau après json_decode(json_encode(CDATA))
    $raw  = $eq['liendivision'] ?? $eq['lien'] ?? $eq['liendiv'] ?? '';
    $lien = is_array($raw) ? (string)($raw[0] ?? $raw['0'] ?? implode('', $raw)) : (string)$raw;
    if ($lien === '') return '';
    parse_str(html_entity_decode($lien), $lp);
    return (string)($lp['D1'] ?? $lp['d1'] ?? '');
}

/** Détecte le code division nationale depuis les champs disponibles (ndivision, libelle…). */
function detectNatCode(array $eq): ?string
{
    $ndiv   = trim((string)($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? $eq['libelledivision'] ?? ''));
    $libelle = trim((string)($eq['libequipe']  ?? $eq['libelle']   ?? ''));

    foreach ([$ndiv, $libelle] as $src) {
        if ($src === '') continue;
        if (preg_match('/\b(N[1-9][MFD])\b/i', $src, $m)) return strtoupper($m[1]);
        if (preg_match('/nationale[^\d]*(\d+)[^\w]*(messieurs?|hommes?|dames?|f[eé]minin)/i', $src, $m))
            return 'N' . $m[1] . (preg_match('/dame|f[eé]minin/i', $m[2]) ? 'F' : 'M');
        if (preg_match('/\bpro\s*([ab])\b/i', $src, $m)) return 'PRO' . strtoupper($m[1]);
        // Patterns courts : "1M", "2F" à la fin d'un libellé (ex: "SPO ROUEN TT 1M")
        if (preg_match('/\s+(\d)[MF]$/i', $src, $m2)) {
            $genre = strtoupper(substr($src, -1));
            return 'N' . $m2[1] . $genre;
        }
    }
    return null;
}

// json_encode avec substitution des octets UTF-8 invalides — ne retourne jamais false
function jsonSafe(mixed $data): string
{
    $r = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $r !== false ? $r : json_encode(['ok' => false, 'msg' => 'json_encode a échoué (json_last_error=' . json_last_error_msg() . ')']);
}

// ── HTML ──────────────────────────────────────────────────────────────────────
$appId    = getFfttAppId();
$appKey   = getFfttAppKey();
$serial   = getConfig('fftt_serial', '');
$deptsNorm = getDeptActifs(); // [['code'=>'14','nom'=>'Calvados'], …]
$moi         = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title>NIJAC – Test API FFTT (E018)</title>
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f4fa; display: flex; flex-direction: column; min-height: 100vh; }
        main { flex: 1; }
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<?php
$pageIcon  = 'bi-plug-fill';
$pageTitle = 'Test API FFTT';
$pageCode  = 'E018';
$backUrl   = 'admin_menu.php';
require __DIR__ . '/includes/page_header.php';
?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<main>
<div class="container-fluid py-4" style="max-width:1800px">

    <!-- Statut de la configuration -->
    <?php $configured = ($appId !== '' && $appKey !== ''); ?>
    <div class="alert alert-<?= $configured ? 'success' : 'danger' ?> d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-<?= $configured ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
        <?php if ($configured): ?>
            Credentials FFTT chargés — App ID : <strong><?= htmlspecialchars($appId) ?></strong>
            &nbsp;|&nbsp; Serial : <strong><?= $serial ?: '<em>sera généré au premier appel</em>' ?></strong>
        <?php else: ?>
            Credentials FFTT non configurés. Renseignez <code>FFTT_APP_ID</code> et <code>FFTT_APP_KEY</code> dans <code>.env</code>.
        <?php endif; ?>
    </div>

    <!-- Onglets -->
    <ul class="nav nav-tabs mb-3" id="fftt-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-general-btn" data-bs-toggle="tab" data-bs-target="#tab-general" type="button" role="tab">
                <i class="bi bi-plug me-1"></i>Tests généraux
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-rencontres-btn" data-bs-toggle="tab" data-bs-target="#tab-rencontres" type="button" role="tab">
                <i class="bi bi-calendar3 me-1"></i>Exploration Rencontres
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-national-btn" data-bs-toggle="tab" data-bs-target="#tab-national" type="button" role="tab">
                <i class="bi bi-trophy me-1"></i>Détection Équipes Nationales
            </button>
        </li>
    </ul>

    <div class="tab-content">
    <div class="tab-pane fade show active" id="tab-general" role="tabpanel">
    <div class="row">

    <!-- Ping PHP -->
    <div class="col-lg-6">
    <div class="card mb-3 border-secondary">
        <div class="card-header fw-semibold text-secondary"><i class="bi bi-activity me-2"></i>Étape 1 — Ping PHP (sans appel API)</div>
        <div class="card-body">
            <button class="btn btn-outline-secondary btn-sm" onclick="tester('ping', {}, 'ping')">Tester</button>
            <div id="res-ping" class="mt-2"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test 1 : clubs par département -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-building me-2"></i>Clubs par département</div>
        <div class="card-body">
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">Département</span>
                <input type="text" id="dep" class="form-control" value="76" maxlength="3">
                <button class="btn btn-primary" onclick="tester('test_clubs_dep', {dep:$('#dep').val()}, 'clubs')">Tester</button>
            </div>
            <div id="res-clubs"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test : détail club + salle -->
    <div class="card mb-3 border-success">
        <div class="card-header fw-semibold text-success"><i class="bi bi-building me-2"></i>Détail club et salle (xml_club_detail)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Retourne les informations complètes d'un club : adresse, coordonnées, salle(s).</p>
            <div class="input-group mb-2" style="max-width:600px">
                <span class="input-group-text">N° Club</span>
                <input type="text" id="club-detail" class="form-control form-control-lg" value="09760442">
                <button class="btn btn-success" onclick="tester('test_club_detail', {club:$('#club-detail').val()}, 'club-detail')">Tester</button>
                <button class="btn btn-warning" onclick="tester('debug_club_salle', {club:$('#club-detail').val()}, 'club-detail')" title="Analyser la structure des champs salle">Analyser salles</button>
            </div>
            <div id="res-club-detail"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test 2 : licence -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-person-badge me-2"></i>Détail d'un licencié (xml_licence)</div>
        <div class="card-body">
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">Licence</span>
                <input type="text" id="licence" class="form-control" value="9317315">
                <button class="btn btn-primary" onclick="tester('test_licence', {licence:$('#licence').val()}, 'licence')">Tester</button>
            </div>
            <div id="res-licence"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test 2b : xml_licence_b (détails complets + grades) -->
    <div class="card mb-3 border-warning">
        <div class="card-header fw-semibold text-warning"><i class="bi bi-person-badge-fill me-2"></i>Détail étendu (xml_licence_b) — cherche les grades JA</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Endpoint alternatif — peut contenir echelon/grade d'arbitrage.</p>
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">Licence</span>
                <input type="text" id="licence-b" class="form-control" value="9317315">
                <button class="btn btn-warning" onclick="tester('test_licence_b', {licence:$('#licence-b').val()}, 'licence-b')">Tester</button>
            </div>
            <div id="res-licence-b"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test 3 : équipes d'un club -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="bi bi-people-fill me-2"></i>Équipes d'un club</div>
        <div class="card-body">
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">N° Club</span>
                <input type="text" id="club" class="form-control" value="09760442">
                <button class="btn btn-primary" onclick="tester('test_equipes', {club:$('#club').val()}, 'equipes')">Tester</button>
            </div>
            <div id="res-equipes"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test 4 : arbitres d'un département -->
    <div class="card mb-3 border-primary">
        <div class="card-header fw-semibold text-primary"><i class="bi bi-award me-2"></i>Arbitres par département (JA1/JA2/JA3/AR)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Parcourt tous les clubs du département et collecte les licenciés avec un grade d'arbitrage. Peut prendre 1-2 minutes.</p>
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">Département</span>
                <input type="text" id="dep-arb" class="form-control" value="76" maxlength="3">
                <button class="btn btn-primary" onclick="tester('test_arbitres_dep', {dep:$('#dep-arb').val()}, 'arbitres')">Lancer</button>
            </div>
            <div id="res-arbitres"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Test 5 : licenciés SPID d'un club (structure complète) -->
    <div class="card mb-3 border-warning">
        <div class="card-header fw-semibold text-warning-emphasis"><i class="bi bi-search me-2"></i>Test structure — Licenciés SPID d'un club (xml_liste_joueur_o)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Affiche le premier licencié retourné avec tous ses champs — pour identifier les champs JA.</p>
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">N° Club</span>
                <input type="text" id="club-spid" class="form-control" value="09760442">
                <button class="btn btn-warning" onclick="tester('test_spid_club', {club:$('#club-spid').val()}, 'spid')">Tester</button>
            </div>
            <div id="res-spid"></div>
        </div>
    </div>

    </div>

    </div>
    </div>

    <div class="tab-pane fade" id="tab-rencontres" role="tabpanel">
    <p class="text-muted small mb-3">Organisme → épreuve → division → poule → rencontre.</p>
    <div class="row">

    <div class="col-lg-6">
    <!-- Organismes -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-diagram-3 me-2"></i>1. Organismes (xml_organisme)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Type : <code>L</code> = Ligue, <code>D</code> = Département, <code>N</code> = National</p>
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">Type</span>
                <select id="org-type" class="form-select">
                    <option value="L">L — Ligue</option>
                    <option value="D">D — Département</option>
                    <option value="N">N — National</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_organisme', {type:$('#org-type').val()}, 'organisme')">Tester</button>
            </div>
            <div id="res-organisme"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Épreuves -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-trophy me-2"></i>2. Épreuves (xml_epreuve)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Saisissez l'ID organisme trouvé à l'étape 1. Type : <code>E</code> = Équipe, <code>I</code> = Individuel</p>
            <div class="d-flex gap-2 mb-2" style="max-width:500px">
                <input type="text" id="ep-org" class="form-control" placeholder="ID organisme" style="max-width:140px">
                <select id="ep-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_epreuve', {organisme:$('#ep-org').val(), type:$('#ep-type').val()}, 'epreuve')">Tester</button>
            </div>
            <div id="res-epreuve"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Divisions -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-list-ol me-2"></i>3. Divisions (xml_division)</div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-2 flex-wrap">
                <input type="text" id="div-org"     class="form-control" placeholder="ID organisme" style="max-width:140px" value="17">
                <input type="text" id="div-epreuve" class="form-control" placeholder="ID épreuve"   style="max-width:140px" value="15955">
                <select id="div-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_division', {organisme:$('#div-org').val(), epreuve:$('#div-epreuve').val(), type:$('#div-type').val()}, 'division')">Tester</button>
            </div>
            <div id="res-division"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Poules -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-grid-3x3 me-2"></i>4. Liste des poules (xml_result_equ — action=poule)</div>
        <div class="card-body">
            <small class="text-muted d-block mb-2">
                Appel <code>xml_result_equ?action=poule&amp;D1=…&amp;auto=1</code> → liste des poules avec libellé et cx_poule (via champ <code>lien</code>).
            </small>
            <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
                <input type="text" id="poule-division" class="form-control" placeholder="D1 = ID division FFTT" style="max-width:220px">
                <select id="poule-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_rencontre',{division:$('#poule-division').val(),type:$('#poule-type').val()},'poule')">
                    <i class="bi bi-search me-1"></i>Lister les poules
                </button>
            </div>
            <div id="res-poule"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Rencontres d'une poule -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-table me-2"></i>5. Rencontres d'une poule (xml_result_equ — cx_poule + D1)</div>
        <div class="card-body">
            <small class="text-muted d-block mb-2">
                Appel <code>xml_result_equ?cx_poule=…&amp;D1=…&amp;auto=1</code> → rencontres d'une seule poule.
                Les valeurs cx_poule et D1 sont issues du champ <code>lien</code> du bloc 4.
            </small>
            <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
                <input type="text" id="renc-cx"  class="form-control" placeholder="cx_poule" style="max-width:140px" value="1349842">
                <input type="text" id="renc-d1"  class="form-control" placeholder="D1"       style="max-width:140px" value="229122">
                <select id="renc-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_rencontre_poule',{cx_poule:$('#renc-cx').val(),D1:$('#renc-d1').val(),type:$('#renc-type').val()},'renc-poule')">
                    <i class="bi bi-search me-1"></i>Tester
                </button>
            </div>
            <div id="res-renc-poule"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- xml_equipe -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-people-fill me-2"></i>6. Équipes d'un club (xml_equipe — numclu)</div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
                <input type="text" id="equipe-club" class="form-control" placeholder="N° club (ex: 09760442)" style="max-width:220px">
                <select id="equipe-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                    <option value="">Tous</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_equipes',{club:$('#equipe-club').val(),type:$('#equipe-type').val()},'equipes2')">
                    <i class="bi bi-search me-1"></i>Tester
                </button>
            </div>
            <div id="res-equipes2"></div>
        </div>
    </div>

    </div>

    </div>
    </div>

    <div class="tab-pane fade" id="tab-national" role="tabpanel">
    <p class="text-muted small mb-3">Détection Équipes Nationales (E017).</p>
    <div class="row">

    <div class="col-lg-6">
    <!-- Carte 7 : Analyse détection nationale pour un club -->
    <div class="card mb-3 border-warning">
        <div class="card-header fw-semibold text-warning-emphasis">
            <i class="bi bi-cpu me-2"></i>7. Analyse détection nationale — un club (xml_equipe + logique E017)
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Appelle <code>xml_equipe</code> pour un club, puis applique la logique de détection de E017 sur chaque équipe.<br>
                Affiche : le champ division brut reçu, la règle matchée (ou non), et le code détecté.
                Permet de voir pourquoi une équipe nationale n'est pas reconnue.
            </p>
            <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
                <input type="text" id="nat-club" class="form-control" placeholder="N° club (ex: 09760076)" style="max-width:220px">
                <button class="btn btn-warning" onclick="testerNatClub()">
                    <i class="bi bi-search me-1"></i>Analyser
                </button>
            </div>
            <div id="res-nat-club"></div>
        </div>
    </div>

    </div>

    <div class="col-lg-6">
    <!-- Carte 8 : Scan département → clubs avec nationales -->
    <div class="card mb-3 border-warning">
        <div class="card-header fw-semibold text-warning-emphasis">
            <i class="bi bi-binoculars me-2"></i>8. Scan département — tous clubs avec équipe nationale
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">
                Parcourt tous les clubs d'un département, appelle <code>xml_equipe</code> pour chacun et
                liste ceux qui ont au moins une équipe nationale détectée. <strong>Peut prendre 2–5 min.</strong>
            </p>
            <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
                <select id="scan-dep" class="form-select w-auto">
                    <?php foreach ($deptsNorm as $d): ?>
                    <option value="<?= htmlspecialchars($d['code']) ?>"><?= htmlspecialchars($d['code'] . ' — ' . $d['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="scan-phase" class="form-select w-auto">
                    <option value="">Toutes les phases</option>
                    <option value="1">Phase 1</option>
                    <option value="2">Phase 2</option>
                </select>
                <button class="btn btn-warning" id="btn-scan-dep" onclick="lancerScanDept()">
                    <i class="bi bi-play-fill me-1"></i>Lancer le scan
                </button>
                <span id="scan-dep-status" class="text-muted small"></span>
            </div>
            <div id="res-scan-dep"></div>
        </div>
    </div>
    </div>

    </div>
    </div>
    </div>

</div>
</main>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
function tester(action, params, id) {
    const container = document.getElementById('res-' + id);
    container.innerHTML = '<div class="text-muted small fst-italic py-2"><i class="bi bi-hourglass-split me-1"></i>Appel en cours…</div>';

    $.ajax({
        url: 'fftt_test.php',
        method: 'POST',
        data: Object.assign({action}, params),
        dataType: 'text'   // on récupère toujours le texte brut, on parse nous-mêmes
    })
    .done(function(raw) {
        let r;
        try {
            r = JSON.parse(raw);
        } catch(e) {
            // Réponse non-JSON : PHP a émis du texte avant le JSON (erreur fatale, notice…)
            afficher(container, {ok: false, msg: 'Réponse PHP non-JSON — voir réponse brute ci-dessous', raw: raw});
            return;
        }
        afficher(container, r);
    })
    .fail(function(xhr) {
        afficher(container, {
            ok: false,
            msg: 'Erreur HTTP ' + xhr.status + ' — ' + xhr.statusText,
            raw: xhr.responseText || '(aucune réponse)'
        });
    });
}

function afficher(container, r) {
    const ok       = r && r.ok;
    const trace    = r?.trace    ?? [];
    const warnings = r?.warnings ?? [];
    const parasite = r?.parasite ?? null;
    const data     = r?.data     ?? null;
    const uid      = container.id;

    let html = '';

    // Bandeau statut
    html += `<div class="alert alert-${ok ? 'success' : 'danger'} py-2 mb-2">
        <i class="bi bi-${ok ? 'check-circle' : 'x-circle'} me-1"></i>
        <strong>${ok ? 'Succès' : 'Erreur'}</strong>
        ${r?.count !== undefined ? ` — ${r.count} résultat(s)` : ''}
        ${r?.msg ? ' : ' + escHtml(r.msg) : ''}
    </div>`;

    // URL(s) appelée(s) + code HTTP
    const urls = [];
    if (r?.url)            urls.push({label:'URL',       url: r.url,            http: r.http ?? 0});
    if (r?.url_poules)     urls.push({label:'Poules',    url: r.url_poules,     http: null});
    if (r?.url_rencontres) urls.push({label:'Rencontres',url: r.url_rencontres, http: null});
    urls.forEach(u => {
        const httpCls = u.http === null ? '' : (u.http === 200 ? 'text-success' : 'text-danger fw-semibold');
        const httpBadge = u.http !== null ? `&nbsp;<span class="${httpCls}">HTTP ${u.http}</span>` : '';
        html += `<div class="mb-1 small text-muted">
            <i class="bi bi-link-45deg me-1"></i><strong>${escHtml(u.label)} :</strong>
            <code style="word-break:break-all;">${escHtml(u.url)}</code>${httpBadge}
        </div>`;
    });

    // Output parasite PHP (warnings, notices avant le JSON)
    if (parasite) {
        html += `<div class="alert alert-warning py-2 small mb-2">
            <i class="bi bi-exclamation-triangle me-1"></i><strong>Output PHP parasite (avant le JSON) :</strong>
            <pre class="mb-0 mt-1">${escHtml(parasite)}</pre>
        </div>`;
    }

    // Warnings PHP capturés
    if (warnings.length) {
        html += `<div class="alert alert-warning py-2 small mb-2">
            <i class="bi bi-exclamation-circle me-1"></i><strong>Warnings PHP (${warnings.length}) :</strong>
            <pre class="mb-0 mt-1">${escHtml(warnings.join('\n'))}</pre>
        </div>`;
    }

    // Réponse brute — toujours affichée si présente dans r, même si vide
    if ('raw' in (r ?? {})) {
        const rawVal = r.raw ?? '';
        html += `<div class="alert alert-danger py-2 small mb-2">
            <i class="bi bi-file-earmark-code me-1"></i><strong>Réponse brute (${rawVal.length} octets) :</strong>
            <pre class="mb-0 mt-1" style="max-height:200px;overflow:auto">${rawVal ? escHtml(rawVal) : '<em>(vide)</em>'}</pre>
        </div>`;
    }

    // Trace des étapes PHP
    if (trace.length) {
        html += `<div class="mb-2">
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#trace-${uid}">
                <i class="bi bi-list-ol me-1"></i>Trace PHP (${trace.length} étape(s))
            </button>
            <div class="collapse show mt-1" id="trace-${uid}">
                <pre class="bg-light border rounded p-2 small mb-0" style="max-height:300px;overflow:auto">${escHtml(JSON.stringify(trace, null, 2))}</pre>
            </div>
        </div>`;
    }

    // Rendu rapide cliquable pour les listes connues
    if (data !== null && data !== undefined) {
        const items = Array.isArray(data) ? data
            : (data.division ? (Array.isArray(data.division) ? data.division : [data.division])
            : (data.club     ? (Array.isArray(data.club)     ? data.club     : [data.club])
            : (data.equipe   ? (Array.isArray(data.equipe)   ? data.equipe   : [data.equipe])
            : null)));

        // Carte divisions → clic remplit D1 de la carte xml_result_equ
        if (uid === 'res-division' && items) {
            html += `<div class="mt-2"><strong>${items.length} division(s)</strong> — cliquez pour tester xml_result_equ :</div>
            <div class="mt-1 d-flex flex-wrap gap-1">`;
            items.forEach(d => {
                const id  = escHtml(d.iddivision ?? d.ident ?? '');
                const lib = escHtml(d.libelle ?? d.lib ?? id);
                html += `<button class="btn btn-sm btn-outline-danger" onclick="
                    $('#poule-division').val('${id}');
                    document.getElementById('poule-division').scrollIntoView({behavior:'smooth',block:'center'});
                    tester('test_rencontre',{division:'${id}',type:$('#poule-type').val()},'poule');
                ">${lib} <small class="text-muted">(${id})</small></button>`;
            });
            html += `</div>`;
        }

    }

    const excluded = ['trace','warnings','parasite','xml_poules_brut','xml_brut'];
    const rData = Object.fromEntries(Object.entries(r ?? {}).filter(([k]) => !excluded.includes(k)));
    html += `<div class="mt-2 d-flex gap-2 flex-wrap">`;

    // Bouton XML brut (si disponible — réponse directe de l'API avant parsing)
    const xmlBrut = r?.xml_poules_brut ?? r?.xml_brut ?? null;
    if (xmlBrut) {
        html += `<div>
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#xml-${uid}">
                <i class="bi bi-file-earmark-code me-1"></i>XML brut API
            </button>
            <div class="collapse mt-1" id="xml-${uid}">
                <pre class="bg-light border rounded p-2 small mb-0" style="max-height:300px;overflow:auto">${escHtml(xmlBrut)}</pre>
            </div>
        </div>`;
    }

    html += `<div>
        <button class="btn btn-sm btn-outline-primary" type="button"
                data-bs-toggle="collapse" data-bs-target="#data-${uid}">
            <i class="bi bi-code-slash me-1"></i>Données JSON brutes
        </button>
        <div class="collapse mt-1" id="data-${uid}">
            <pre class="bg-light border rounded p-2 small mb-0" style="max-height:300px;overflow:auto">${escHtml(JSON.stringify(rData, null, 2))}</pre>
        </div>
    </div></div>`;

    container.innerHTML = html;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Carte 7 : analyse détection nationale pour un club ────────────────────────
function testerNatClub() {
    const numclu = $('#nat-club').val().trim();
    if (!numclu) { alert('Saisissez un numéro de club.'); return; }
    const $c = $('#res-nat-club').html('<div class="text-muted small fst-italic py-2"><i class="bi bi-hourglass-split me-1"></i>Appel en cours…</div>');

    $.post('fftt_test.php', {action:'test_equipe_nat', club: numclu}, null, 'json')
    .done(function(r) {
        if (!r.ok) { $c.html(`<div class="alert alert-danger py-2">${escHtml(r.msg??'Erreur')}</div>`); return; }

        const a = r.analyse ?? [];
        const nbNat = a.filter(x => x.détecté).length;

        const nbDivActives = Object.keys(r.nat_div_ids ?? {}).length;
        let html = `<div class="alert alert-${nbNat > 0 ? 'success' : 'warning'} py-2 mb-2">
            <i class="bi bi-${nbNat > 0 ? 'check-circle' : 'exclamation-triangle'} me-1"></i>
            ${a.length} équipe(s) — <strong>${nbNat} nationale(s) retenue(s) pour la phase à venir</strong>
            <small class="ms-2 text-muted">(${nbDivActives} division(s) active(s) chargées depuis l'API)</small>
        </div>`;

        // Divisions actives + diagnostic si vide
        const dbg = r.trace?.find(t => t['étape']?.includes('Divisions actives'))?.debug ?? {};
        if (nbDivActives) {
            const badges = Object.entries(r.nat_div_ids).map(([d1, code]) =>
                `<span class="badge bg-dark me-1" title="D1=${escHtml(d1)}">${escHtml(code)}</span>`
            ).join('');
            html += `<div class="mb-2 small"><strong>Divisions actives :</strong> ${badges}</div>`;
        } else {
            const dbgJson = escHtml(JSON.stringify(dbg, null, 2));
            const uid = 'dbg-div-' + Date.now();
            html += `<div class="alert alert-danger py-2 mb-2 small">
                <strong>⚠ Aucune division nationale chargée — la détection ne fonctionnera pas correctement.</strong><br>
                Cause probable : organisme Normandie introuvable dans l'API, ou épreuve nationale non retournée.
                <button class="btn btn-sm btn-outline-danger ms-2" data-bs-toggle="collapse" data-bs-target="#${uid}">Détail</button>
                <div class="collapse mt-1" id="${uid}"><pre class="bg-light p-2 rounded" style="font-size:.7rem;max-height:250px;overflow:auto;">${dbgJson}</pre></div>
            </div>`;
        }

        // Tableau équipe par équipe
        html += `<div style="overflow-x:auto;"><table class="table table-sm table-bordered mb-2" style="font-size:.8rem;">
            <thead class="table-dark">
                <tr>
                    <th>Libellé équipe</th>
                    <th>Division brute (ndivision)</th>
                    <th>D1</th>
                    <th>Phase</th>
                    <th>Code retenu</th>
                    <th>Champs bruts</th>
                </tr>
            </thead><tbody>`;
        a.forEach(eq => {
            const rowCls = eq.détecté ? 'table-success' : (eq.champ_div !== '(vide)' ? 'table-warning' : 'table-secondary');
            const badge = eq.détecté
                ? `<span class="badge bg-success">${escHtml(eq.code_div)}</span>`
                : `<span class="badge bg-secondary">—</span>`;
            const allFields = JSON.stringify(eq.tous_champs, null, 2);
            const uid2 = 'eq-' + Math.random().toString(36).slice(2,8);
            html += `<tr class="${rowCls}">
                <td class="fw-semibold">${escHtml(eq.libelle||'—')}</td>
                <td><code style="font-size:.73rem;">${escHtml(eq.champ_div)}</code></td>
                <td><code style="font-size:.73rem;">${escHtml(eq.d1||'—')}</code></td>
                <td><span class="badge bg-dark">${escHtml(eq.phase_div||'—')}</span></td>
                <td>${badge}</td>
                <td>
                    <button class="btn btn-xs btn-outline-secondary py-0 px-1" style="font-size:.72rem;"
                            data-bs-toggle="collapse" data-bs-target="#${uid2}">
                        <i class="bi bi-code-slash"></i>
                    </button>
                    <div class="collapse" id="${uid2}">
                        <pre class="mb-0 mt-1 bg-light p-1" style="font-size:.7rem;max-height:150px;overflow:auto;">${escHtml(allFields)}</pre>
                    </div>
                </td>
            </tr>`;
        });
        html += `</tbody></table></div>`;

        // XML brut
        if (r.xml_brut) {
            html += `<div><button class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#xml-nat-club">
                <i class="bi bi-file-earmark-code me-1"></i>XML brut API
            </button>
            <div class="collapse mt-1" id="xml-nat-club">
                <pre class="bg-light border rounded p-2 small" style="max-height:200px;overflow:auto">${escHtml(r.xml_brut)}</pre>
            </div></div>`;
        }

        $c.html(html);
    })
    .fail(function() { $c.html('<div class="alert alert-danger py-2">Erreur réseau.</div>'); });
}

// ── Carte 8 : scan département ────────────────────────────────────────────────
function lancerScanDept() {
    const dep = $('#scan-dep').val();
    $('#btn-scan-dep').prop('disabled', true);
    $('#scan-dep-status').text('Scan en cours… (peut prendre plusieurs minutes)');
    const $c = $('#res-scan-dep').html('<div class="text-muted small fst-italic py-2"><i class="bi bi-hourglass-split me-1"></i>Scan en cours…</div>');

    const phase = $('#scan-phase').val();
    $.post('fftt_test.php', {action:'scan_dept_nat', dep, phase}, null, 'json')
    .done(function(r) {
        $('#btn-scan-dep').prop('disabled', false);
        if (!r.ok) { $c.html(`<div class="alert alert-danger py-2">${escHtml(r.msg??'Erreur')}</div>`); $('#scan-dep-status').text(''); return; }

        const res = r.resultats ?? [];
        $('#scan-dep-status').text(`${r.clubs_scannes} clubs scannés — ${res.length} avec équipe(s) nationale(s).`);

        const detail = r.detail ?? [];
        const nbDivActives = Object.keys(r.nat_div_ids ?? {}).length;

        const phaseLabel = phase ? `Phase ${phase}` : 'toutes phases';
        const dbg8 = r.trace?.find(t => t['étape']?.includes('Divisions actives'))?.debug ?? {};
        let html = `<div class="alert alert-${res.length > 0 ? 'success' : 'warning'} py-2 mb-2">
            <strong>${res.length} club(s)</strong> avec équipe(s) nationale(s) sur ${r.clubs_scannes} scannés
            — <span class="badge bg-dark">${escHtml(phaseLabel)}</span>
            <small class="ms-2 text-muted">(filtre D1 : ${nbDivActives > 0 ? nbDivActives + ' divisions actives' : '<span class="text-danger">désactivé — divisions non chargées</span>'})</small>
        </div>`;
        if (!nbDivActives) {
            const uid = 'dbg8-' + Date.now();
            html += `<div class="alert alert-danger py-2 mb-2 small">
                <strong>⚠ Aucune division nationale chargée — la détection ne fonctionnera pas.</strong>
                <button class="btn btn-sm btn-outline-danger ms-2" data-bs-toggle="collapse" data-bs-target="#${uid}">Détail</button>
                <div class="collapse mt-1" id="${uid}"><pre class="bg-light p-2 rounded" style="font-size:.7rem;max-height:250px;overflow:auto;">${escHtml(JSON.stringify(dbg8,null,2))}</pre></div>
            </div>`;
        }

        if (!res.length && !detail.length) {
            $c.html(html + '<div class="alert alert-warning py-2">Vérifiez la carte 7 sur un club connu pour diagnostiquer la détection.</div>');
            return;
        }

        // ── Tableau synthèse des clubs avec équipes nationales ────────────────
        if (res.length) {
            html += `<div style="overflow-x:auto;" class="mb-3"><table class="table table-sm table-bordered mb-0" style="font-size:.82rem;">
                <thead class="table-dark"><tr><th>N° Club</th><th>Nom du club</th><th>Équipes nationales retenues</th></tr></thead><tbody>`;
            res.forEach(club => {
                const lignes = club.nationales.map(n =>
                    `<span class="badge bg-success me-1">${escHtml(n.div)}</span> ${escHtml(n.lib)} <small class="text-muted">[D1:${escHtml(n.d1||'—')} | ${escHtml(n.ndiv_brut)}]</small>`
                ).join('<br>');
                html += `<tr><td><code>${escHtml(club.numclu)}</code></td><td class="fw-semibold">${escHtml(club.nom)}</td><td>${lignes}</td></tr>`;
            });
            html += `</tbody></table></div>`;
        }

        // ── Trace détaillée club par club ─────────────────────────────────────
        const statIcons = {
            nationale:     ['bg-success',   '✔ Nationale'],
            autre_phase:   ['bg-warning',   '~ Autre phase'],
            non_nationale: ['bg-secondary', '— Non nationale'],
            sans_division: ['bg-light text-muted border', '— Pas de division'],
        };
        const uidLog = 'log-scan-' + Date.now();
        html += `<div>
            <button class="btn btn-sm btn-outline-secondary mb-2" data-bs-toggle="collapse" data-bs-target="#${uidLog}">
                <i class="bi bi-list-ul me-1"></i>Trace détaillée (${detail.length} clubs)
            </button>
            <div class="collapse" id="${uidLog}">
              <div style="max-height:500px;overflow-y:auto;background:#0f172a;border-radius:6px;padding:.6rem .85rem;font-family:monospace;font-size:.75rem;line-height:1.7;">`;

        detail.forEach(club => {
            const hasNat = club.equipes.some(e => e.statut === 'nationale_D1' || e.statut === 'nationale_nom');
            const clubColor = club.erreur ? '#f87171' : (hasNat ? '#4ade80' : '#94a3b8');
            const nbEq = club.equipes.length;
            html += `<div style="color:${clubColor};margin-top:.3rem;">`;
            html += `<strong>${escHtml(club.numclu)}</strong> ${escHtml(club.nom)}`;
            if (club.erreur) {
                html += ` <span style="color:#f87171;">⚠ ${escHtml(club.erreur)}</span>`;
            } else if (nbEq === 0) {
                const rid = 'raw-' + Math.random().toString(36).slice(2,8);
                const rawContent = club.raw_equipe ?? '(non capturé)';
                html += ` <span style="color:#475569;"> — 0 équipe [HTTP ${club.http_equipe||'?'}]</span>`;
                html += ` <button onclick="document.getElementById('${rid}').classList.toggle('d-none')" style="background:none;border:none;color:#94a3b8;font-size:.65rem;cursor:pointer;">[raw]</button>`;
                html += `<pre id="${rid}" class="d-none" style="background:#1e293b;color:#94a3b8;font-size:.65rem;margin:.2rem 0 0 0;padding:.3rem;border-radius:4px;white-space:pre-wrap;">${escHtml(rawContent)}</pre>`;
            } else {
                club.equipes.forEach(eq => {
                    const [bg, lbl] = statIcons[eq.statut] ?? ['bg-secondary','?'];
                    const isNat = eq.statut === 'nationale';
                    const eqColor = isNat ? '#4ade80' : (eq.statut === 'autre_phase' ? '#fbbf24' : '#64748b');
                    const brutJson = JSON.stringify(eq.brut ?? {}, null, 2);
                    const brutId = 'brut-' + Math.random().toString(36).slice(2,8);
                    html += `<br><span style="color:${eqColor}; padding-left:1.2rem;">`;
                    html += `${isNat ? '⊕' : '·'} ${escHtml(eq.lib)}`;
                    if (eq.code) html += ` <span style="color:#93c5fd;">[${escHtml(eq.code)}]</span>`;
                    html += ` <span style="color:#475569;font-size:.7rem;">${escHtml(lbl)} | ndiv="${escHtml(eq.ndiv)}" | D1=${escHtml(eq.d1)}</span>`;
                    html += ` <button onclick="document.getElementById('${brutId}').classList.toggle('d-none')" style="background:none;border:none;color:#64748b;font-size:.65rem;cursor:pointer;padding:0 .3rem;">[+]</button>`;
                    html += `<pre id="${brutId}" class="d-none" style="background:#1e293b;color:#94a3b8;font-size:.65rem;margin:.2rem 0 0 1.2rem;padding:.3rem;border-radius:4px;white-space:pre-wrap;">${escHtml(brutJson)}</pre>`;
                    html += `</span>`;
                });
            }
            html += `</div>`;
        });

        html += `</div></div></div>`;

        // Warnings
        if (r.warnings?.length) {
            html += `<div class="alert alert-warning py-2 small mt-2"><pre class="mb-0">${escHtml(r.warnings.join('\n'))}</pre></div>`;
        }

        $c.html(html);
    })
    .fail(function() {
        $('#btn-scan-dep').prop('disabled', false);
        $('#scan-dep-status').text('');
        $c.html('<div class="alert alert-danger py-2">Erreur réseau.</div>');
    });
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
