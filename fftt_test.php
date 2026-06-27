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
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
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
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
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
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
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
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_epreuve':
                $org  = trim($_POST['organisme'] ?? '');
                $type = trim($_POST['type'] ?? 'E');
                if ($org === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'ID organisme requis']); break; }
                $data = $api->request('xml_epreuve', ['organisme' => $org, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_division':
                $org     = trim($_POST['organisme'] ?? '');
                $epreuve = trim($_POST['epreuve']   ?? '');
                $type    = trim($_POST['type']       ?? 'E');
                if ($org === '' || $epreuve === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Organisme et épreuve requis']); break; }
                $data = $api->request('xml_division', ['organisme' => $org, 'epreuve' => $epreuve, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_poule':
                $org     = trim($_POST['organisme'] ?? '');
                $epreuve = trim($_POST['epreuve']   ?? '');
                $division = trim($_POST['division'] ?? '');
                $type    = trim($_POST['type']       ?? 'E');
                if ($org === '' || $epreuve === '' || $division === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Organisme, épreuve et division requis']); break; }
                $data = $api->request('xml_poule', ['organisme' => $org, 'epreuve' => $epreuve, 'division' => $division, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_rencontre':
                $org     = trim($_POST['organisme'] ?? '');
                $epreuve = trim($_POST['epreuve']   ?? '');
                $division = trim($_POST['division'] ?? '');
                $poule   = trim($_POST['poule']     ?? '');
                $type    = trim($_POST['type']       ?? 'E');
                if ($org === '' || $epreuve === '' || $division === '' || $poule === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Tous les champs sont requis']); break; }
                $data = $api->request('xml_rencontre', ['organisme' => $org, 'epreuve' => $epreuve, 'division' => $division, 'poule' => $poule, 'type' => $type]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
                break;

            case 'test_result_equ':
                $club = trim($_POST['club'] ?? '');
                $equ  = trim($_POST['equ']  ?? '');
                if ($club === '' || $equ === '') { ob_end_clean(); echo jsonSafe(['ok' => false, 'msg' => 'Club et équipe requis']); break; }
                $data = $api->request('xml_result_equ', ['club' => $club, 'equ' => $equ]);
                ob_end_clean();
                echo jsonSafe(['ok' => true, 'data' => $data, 'trace' => $trace, 'warnings' => $phpWarnings]);
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
        echo json_encode(['ok' => false, 'msg' => $e->getMessage(), 'trace' => $trace, 'warnings' => $phpWarnings, 'parasite' => $parasite ?: null]);
    }

    restore_error_handler();
    exit;
}

// json_encode avec substitution des octets UTF-8 invalides — ne retourne jamais false
function jsonSafe(mixed $data): string
{
    $r = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $r !== false ? $r : json_encode(['ok' => false, 'msg' => 'json_encode a échoué (json_last_error=' . json_last_error_msg() . ')']);
}

// ── HTML ──────────────────────────────────────────────────────────────────────
$appId  = getFfttAppId();
$appKey = getFfttAppKey();
$serial = getConfig('fftt_serial', '');
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

<main>
<div class="container-fluid py-4" style="max-width:960px">

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

    <!-- Ping PHP -->
    <div class="card mb-3 border-secondary">
        <div class="card-header fw-semibold text-secondary"><i class="bi bi-activity me-2"></i>Étape 1 — Ping PHP (sans appel API)</div>
        <div class="card-body">
            <button class="btn btn-outline-secondary btn-sm" onclick="tester('ping', {}, 'ping')">Tester</button>
            <div id="res-ping" class="mt-2"></div>
        </div>
    </div>

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

    <!-- Test : détail club + salle -->
    <div class="card mb-3 border-success">
        <div class="card-header fw-semibold text-success"><i class="bi bi-building me-2"></i>Détail club et salle (xml_club_detail)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Retourne les informations complètes d'un club : adresse, coordonnées, salle(s).</p>
            <div class="input-group mb-2" style="max-width:340px">
                <span class="input-group-text">N° Club</span>
                <input type="text" id="club-detail" class="form-control" value="09760442">
                <button class="btn btn-success" onclick="tester('test_club_detail', {club:$('#club-detail').val()}, 'club-detail')">Tester</button>
                <button class="btn btn-warning" onclick="tester('debug_club_salle', {club:$('#club-detail').val()}, 'club-detail')" title="Analyser la structure des champs salle">Analyser salles</button>
            </div>
            <div id="res-club-detail"></div>
        </div>
    </div>

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

    <hr class="my-4">
    <h5 class="text-danger fw-bold mb-3"><i class="bi bi-calendar3 me-2"></i>Exploration Rencontres (organisme → épreuve → division → poule → rencontre)</h5>

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

    <!-- Divisions -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-list-ol me-2"></i>3. Divisions (xml_division)</div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-2 flex-wrap">
                <input type="text" id="div-org"     class="form-control" placeholder="ID organisme" style="max-width:140px">
                <input type="text" id="div-epreuve" class="form-control" placeholder="ID épreuve"   style="max-width:140px">
                <select id="div-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_division', {organisme:$('#div-org').val(), epreuve:$('#div-epreuve').val(), type:$('#div-type').val()}, 'division')">Tester</button>
            </div>
            <div id="res-division"></div>
        </div>
    </div>

    <!-- Poules -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-grid-3x3 me-2"></i>4. Poules (xml_poule)</div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-2 flex-wrap">
                <input type="text" id="poule-org"      class="form-control" placeholder="ID organisme" style="max-width:130px">
                <input type="text" id="poule-epreuve"  class="form-control" placeholder="ID épreuve"   style="max-width:130px">
                <input type="text" id="poule-division" class="form-control" placeholder="ID division"  style="max-width:130px">
                <select id="poule-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_poule', {organisme:$('#poule-org').val(), epreuve:$('#poule-epreuve').val(), division:$('#poule-division').val(), type:$('#poule-type').val()}, 'poule')">Tester</button>
            </div>
            <div id="res-poule"></div>
        </div>
    </div>

    <!-- Rencontres -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-calendar-event me-2"></i>5. Rencontres (xml_rencontre)</div>
        <div class="card-body">
            <div class="d-flex gap-2 mb-2 flex-wrap">
                <input type="text" id="renc-org"      class="form-control" placeholder="ID organisme" style="max-width:120px">
                <input type="text" id="renc-epreuve"  class="form-control" placeholder="ID épreuve"   style="max-width:120px">
                <input type="text" id="renc-division" class="form-control" placeholder="ID division"  style="max-width:120px">
                <input type="text" id="renc-poule"    class="form-control" placeholder="ID poule"     style="max-width:100px">
                <select id="renc-type" class="form-select w-auto">
                    <option value="E">E — Équipe</option>
                    <option value="I">I — Individuel</option>
                </select>
                <button class="btn btn-danger" onclick="tester('test_rencontre', {organisme:$('#renc-org').val(), epreuve:$('#renc-epreuve').val(), division:$('#renc-division').val(), poule:$('#renc-poule').val(), type:$('#renc-type').val()}, 'rencontre')">Tester</button>
            </div>
            <div id="res-rencontre"></div>
        </div>
    </div>

    <!-- Résultats par équipe -->
    <div class="card mb-3 border-danger">
        <div class="card-header fw-semibold text-danger"><i class="bi bi-bar-chart-line me-2"></i>6. Résultats équipe (xml_result_equ)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Ex: club <code>09760442</code>, équipe <code>A</code></p>
            <div class="d-flex gap-2 mb-2" style="max-width:400px">
                <input type="text" id="reseq-club" class="form-control" placeholder="N° club" style="max-width:140px">
                <input type="text" id="reseq-equ"  class="form-control" placeholder="Équipe (A, B…)" style="max-width:120px">
                <button class="btn btn-danger" onclick="tester('test_result_equ', {club:$('#reseq-club').val(), equ:$('#reseq-equ').val()}, 'result-equ')">Tester</button>
            </div>
            <div id="res-result-equ"></div>
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

    // Données retournées
    if (data !== null && data !== undefined) {
        html += `<div>
            <button class="btn btn-sm btn-outline-primary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#data-${uid}">
                <i class="bi bi-code-slash me-1"></i>Données JSON
            </button>
            <div class="collapse mt-1" id="data-${uid}">
                <pre class="bg-light border rounded p-2 small mb-0" style="max-height:300px;overflow:auto">${escHtml(JSON.stringify(data, null, 2))}</pre>
            </div>
        </div>`;
    }

    container.innerHTML = html;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
