<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Test API FFTT (E018)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
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
        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            font-size: .85rem;
            flex-shrink: 0;
        }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
            gap: 1rem;
        }
        #status-bar { color: #374151; min-height: 18px; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
        #page-footer.pf-status-left {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
        }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; text-align: left; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'plug-fill', 'phTitle' => 'Test API FFTT', 'phCode' => 'E018',
    'phCrumbLabel' => 'Admin', 'phCrumbUrl' => site_url('admin-menu'), 'phBackUrl' => site_url('admin-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<main>
<div class="container-fluid py-4" style="max-width:1800px">

    <!-- Statut de la configuration -->
    <?php $configured = ($appId !== '' && $appKey !== ''); ?>
    <div id="credentials-banner" class="alert alert-<?= $configured ? 'success' : 'danger' ?> d-flex align-items-center gap-2 mb-4"
         data-app-key="<?= esc($appKey) ?>"
         title="Double-cliquer pour afficher temporairement le mot de passe (App Key)">
        <i class="bi bi-<?= $configured ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
        <?php if ($configured): ?>
            <span>
                Credentials FFTT chargés — App ID : <strong><?= esc($appId) ?></strong>
                &nbsp;|&nbsp; Serial : <strong><?= $serial ?: '<em>sera généré au premier appel</em>' ?></strong>
                &nbsp;|&nbsp; App Key : <strong id="credentials-appkey">••••••••</strong>
            </span>
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
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-lib-btn" data-bs-toggle="tab" data-bs-target="#tab-lib" type="button" role="tab">
                <i class="bi bi-box-seam me-1"></i>Appel générique FfttRawClient
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
        <div class="card-header fw-semibold"><i class="bi bi-building me-2"></i>Clubs par département <small class="text-muted fw-normal">(listClubsByDepartement)</small></div>
        <div class="card-body">
            <div class="input-group mb-2" style="max-width:300px">
                <span class="input-group-text">Département</span>
                <input type="text" id="dep" class="form-control" value="76" maxlength="3">
                <button class="btn btn-primary" onclick="tester('test-clubs-dep', {dep:$('#dep').val()}, 'clubs')">Tester</button>
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
                <button class="btn btn-success" onclick="tester('test-club-detail', {club:$('#club-detail').val()}, 'club-detail')">Tester</button>
                <button class="btn btn-warning" onclick="tester('debug-club-salle', {club:$('#club-detail').val()}, 'club-detail')" title="Analyser la structure des champs salle">Analyser salles</button>
            </div>
            <div id="res-club-detail"></div>
        </div>
    </div>

    <!-- Test : recherche club par nom -->
    <div class="card mb-3 border-success">
        <div class="card-header fw-semibold text-success"><i class="bi bi-search me-2"></i>Recherche club par nom (xml_club_b)</div>
        <div class="card-body">
            <p class="text-muted small mb-2">Recherche un club par son nom (contrairement à xml_club_detail qui exige le numéro FFTT exact) — utile pour retrouver le vrai club d'un doublon BugSpid (E043).</p>
            <div class="input-group mb-2" style="max-width:600px">
                <span class="input-group-text">Nom</span>
                <input type="text" id="club-b-nom" class="form-control form-control-lg" value="VILLERS BOCAGE">
                <button class="btn btn-success" onclick="tester('test-club-b', {nom:$('#club-b-nom').val()}, 'club-b')">Tester</button>
            </div>
            <div id="res-club-b"></div>
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
                <button class="btn btn-primary" onclick="tester('test-licence', {licence:$('#licence').val()}, 'licence')">Tester</button>
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
                <button class="btn btn-warning" onclick="tester('test-licence-b', {licence:$('#licence-b').val()}, 'licence-b')">Tester</button>
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
                <button class="btn btn-primary" onclick="tester('test-equipes', {club:$('#club').val()}, 'equipes')">Tester</button>
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
                <button class="btn btn-primary" onclick="tester('test-arbitres-dep', {dep:$('#dep-arb').val()}, 'arbitres')">Lancer</button>
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
                <button class="btn btn-warning" onclick="tester('test-spid-club', {club:$('#club-spid').val()}, 'spid')">Tester</button>
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
                <button class="btn btn-danger" onclick="tester('test-organisme', {type:$('#org-type').val()}, 'organisme')">Tester</button>
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
                <button class="btn btn-danger" onclick="tester('test-epreuve', {organisme:$('#ep-org').val(), type:$('#ep-type').val()}, 'epreuve')">Tester</button>
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
                <button class="btn btn-danger" onclick="tester('test-division', {organisme:$('#div-org').val(), epreuve:$('#div-epreuve').val(), type:$('#div-type').val()}, 'division')">Tester</button>
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
                <button class="btn btn-danger" onclick="tester('test-rencontre',{division:$('#poule-division').val(),type:$('#poule-type').val()},'poule')">
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
                <button class="btn btn-danger" onclick="tester('test-rencontre-poule',{cx_poule:$('#renc-cx').val(),D1:$('#renc-d1').val(),type:$('#renc-type').val()},'renc-poule')">
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
                <button class="btn btn-danger" onclick="tester('test-equipes',{club:$('#equipe-club').val(),type:$('#equipe-type').val()},'equipes2')">
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
                    <option value="<?= esc($d['code']) ?>"><?= esc($d['code'] . ' — ' . $d['nom']) ?></option>
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

    <div class="tab-pane fade" id="tab-lib" role="tabpanel">
    <div class="alert alert-info small mb-3">
        <i class="bi bi-info-circle-fill me-1"></i>
        <code>App\Libraries\FfttRawClient</code> (cURL natif, pas de dépendance Composer) — utilisé dans
        toute l'application (Club, Salle, Jugearbitre, ImportRencontres*), mêmes identifiants
        (<code>getFfttAppId()</code>/<code>getFfttAppKey()</code>). Seules les méthodes réellement utilisées
        en production sont testables ici ; pour un endpoint FFTT brut quelconque (xml_division, cx_poule…),
        voir l'onglet « Exploration Rencontres ».
    </div>
    <div class="row">

    <div class="col-lg-5">
    <div class="card mb-3 border-warning">
        <div class="card-header fw-semibold text-warning-emphasis"><i class="bi bi-box-seam me-2"></i>Appel générique</div>
        <div class="card-body">
            <div class="mb-2">
                <label class="form-label small fw-semibold">Méthode</label>
                <select id="lib-methode" class="form-select">
                    <option value="apercu">Aperçu — organismes + clubs du département</option>
                    <option value="organismes">listOrganismes — organismes (type)</option>
                    <option value="clubs-dep">listClubsByDepartement — clubs d'un département (dep)</option>
                    <option value="club-details">retrieveClubDetails — détail d'un club (club)</option>
                    <option value="joueurs-club">listJoueursByClub — joueurs d'un club (club)</option>
                    <option value="joueur-details">retrieveJoueurDetails — détail joueur (licence, club optionnel)</option>
                    <option value="equipes-club">listEquipesByClub — équipes d'un club (club, type optionnel)</option>
                    <option value="epreuves">listEpreuves — épreuves d'un organisme (organisme, type_epreuve)</option>
                </select>
                <div class="form-text">Seuls les champs pertinents pour la méthode choisie sont utilisés.</div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label small mb-0">Club</label><input type="text" id="lib-club" class="form-control form-control-sm" placeholder="09760221"></div>
                <div class="col-6"><label class="form-label small mb-0">Département</label><input type="text" id="lib-dep" class="form-control form-control-sm" value="76" maxlength="3"></div>
                <div class="col-6"><label class="form-label small mb-0">Licence</label><input type="text" id="lib-licence" class="form-control form-control-sm" placeholder="7646282"></div>
                <div class="col-6"><label class="form-label small mb-0">Organisme (id)</label><input type="text" id="lib-organisme" class="form-control form-control-sm"></div>
                <div class="col-6">
                    <label class="form-label small mb-0">Type épreuve</label>
                    <select id="lib-type-epreuve" class="form-select form-select-sm">
                        <option value="E">Équipe</option>
                        <option value="I">Individuel</option>
                    </select>
                </div>
                <div class="col-6"><label class="form-label small mb-0">Type (organisme/équipe)</label><input type="text" id="lib-type" class="form-control form-control-sm" placeholder="Z, L… / N1M…"></div>
            </div>

            <button class="btn btn-warning" onclick="testerLib()"><i class="bi bi-play-fill me-1"></i>Tester</button>
        </div>
    </div>
    </div>

    <div class="col-lg-7">
        <div id="res-lib"></div>
    </div>

    </div>
    </div>

    </div>

</div>
</main>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
const BASE = '<?= site_url('fftt-test') ?>';

function tester(action, params, id) {
    const container = document.getElementById('res-' + id);
    container.innerHTML = '<div class="text-muted small fst-italic py-2"><i class="bi bi-hourglass-split me-1"></i>Appel en cours…</div>';

    $.ajax({
        url: `${BASE}/${action}`,
        method: 'POST',
        data: Object.assign({}, params),
        dataType: 'text'   // on récupère toujours le texte brut, on parse nous-mêmes
    })
    .done(function(raw) {
        let r;
        try {
            r = JSON.parse(raw);
        } catch(e) {
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

function testerLib() {
    tester('test-lib', {
        methode:       $('#lib-methode').val(),
        club:          $('#lib-club').val(),
        dep:           $('#lib-dep').val(),
        licence:       $('#lib-licence').val(),
        organisme:     $('#lib-organisme').val(),
        type_epreuve:  $('#lib-type-epreuve').val(),
        type:          $('#lib-type').val(),
    }, 'lib');
}

function afficher(container, r) {
    const ok       = r && r.ok;
    const trace    = r?.trace    ?? [];
    const warnings = r?.warnings ?? [];
    const parasite = r?.parasite ?? null;
    const data     = r?.data     ?? null;
    const uid      = container.id;

    let html = '';

    html += `<div class="alert alert-${ok ? 'success' : 'danger'} py-2 mb-2">
        <i class="bi bi-${ok ? 'check-circle' : 'x-circle'} me-1"></i>
        <strong>${ok ? 'Succès' : 'Erreur'}</strong>
        ${r?.count !== undefined ? ` — ${r.count} résultat(s)` : ''}
        ${r?.msg ? ' : ' + escHtml(r.msg) : ''}
    </div>`;

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

    if (parasite) {
        html += `<div class="alert alert-warning py-2 small mb-2">
            <i class="bi bi-exclamation-triangle me-1"></i><strong>Output PHP parasite (avant le JSON) :</strong>
            <pre class="mb-0 mt-1">${escHtml(parasite)}</pre>
        </div>`;
    }

    if (warnings.length) {
        html += `<div class="alert alert-warning py-2 small mb-2">
            <i class="bi bi-exclamation-circle me-1"></i><strong>Warnings PHP (${warnings.length}) :</strong>
            <pre class="mb-0 mt-1">${escHtml(warnings.join('\n'))}</pre>
        </div>`;
    }

    if ('raw' in (r ?? {})) {
        const rawVal = r.raw ?? '';
        html += `<div class="alert alert-danger py-2 small mb-2">
            <i class="bi bi-file-earmark-code me-1"></i><strong>Réponse brute (${rawVal.length} octets) :</strong>
            <pre class="mb-0 mt-1" style="max-height:200px;overflow:auto">${rawVal ? escHtml(rawVal) : '<em>(vide)</em>'}</pre>
        </div>`;
    }

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

    if (data !== null && data !== undefined) {
        const items = Array.isArray(data) ? data
            : (data.division ? (Array.isArray(data.division) ? data.division : [data.division])
            : (data.club     ? (Array.isArray(data.club)     ? data.club     : [data.club])
            : (data.equipe   ? (Array.isArray(data.equipe)   ? data.equipe   : [data.equipe])
            : null)));

        if (uid === 'res-division' && items) {
            html += `<div class="mt-2"><strong>${items.length} division(s)</strong> — cliquez pour tester xml_result_equ :</div>
            <div class="mt-1 d-flex flex-wrap gap-1">`;
            items.forEach(d => {
                const id  = escHtml(d.iddivision ?? d.ident ?? '');
                const lib = escHtml(d.libelle ?? d.lib ?? id);
                html += `<button class="btn btn-sm btn-outline-danger" onclick="
                    $('#poule-division').val('${id}');
                    document.getElementById('poule-division').scrollIntoView({behavior:'smooth',block:'center'});
                    tester('test-rencontre',{division:'${id}',type:$('#poule-type').val()},'poule');
                ">${lib} <small class="text-muted">(${id})</small></button>`;
            });
            html += `</div>`;
        }

    }

    const excluded = ['trace','warnings','parasite','xml_poules_brut','xml_brut'];
    const rData = Object.fromEntries(Object.entries(r ?? {}).filter(([k]) => !excluded.includes(k)));
    html += `<div class="mt-2 d-flex gap-2 flex-wrap">`;

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

    $.post(`${BASE}/test-equipe-nat`, {club: numclu}, null, 'json')
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
    $.post(`${BASE}/scan-dept-nat`, {dep, phase}, null, 'json')
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

// ── Affichage temporaire du mot de passe (App Key) sur double-clic du bandeau credentials ──
let credentialsMaskTimer = null;
$('#credentials-banner').on('dblclick', function() {
    const appKey = $(this).attr('data-app-key');
    if (!appKey) return;
    const $span = $('#credentials-appkey');
    $span.text(appKey);
    clearTimeout(credentialsMaskTimer);
    credentialsMaskTimer = setTimeout(() => $span.text('••••••••'), 15000);
});
</script>
</body>
</html>
