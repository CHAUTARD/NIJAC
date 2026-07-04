<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * NIJAC – Test API FFTT (E018), portage CI4 de fftt_test.php.
 *
 * Interface de diagnostic de l'API FFTT (Smartping v2) — appel manuel de
 * chaque endpoint, lecture seule, aucune écriture en base. Réutilise la même
 * classe partagée Classes/FfttApi.php (factory getFfttApi()) que les écrans
 * d'import réels (E007, E008, E005, E011, E017).
 *
 * Accès Administrateur + restriction supplémentaire login === 'CHAUTARD'
 * (même règle que E099), vérifiée manuellement en plus du filtre "adminauth".
 */
class FfttTestController extends BaseController
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../config/db.php';
        require_once __DIR__ . '/../../../config/app_config.php';
    }

    /**
     * @return ResponseInterface|null null si l'accès est autorisé
     */
    private function guardChautard(): ?ResponseInterface
    {
        if (($_SESSION['utilisateur']['login'] ?? '') !== 'CHAUTARD') {
            return redirect()->to(site_url('admin-menu'));
        }

        return null;
    }

    // json_encode avec substitution des octets UTF-8 invalides — ne retourne jamais false
    private function jsonSafe(array $data): string
    {
        $r = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $r !== false ? $r : json_encode(['ok' => false, 'msg' => 'json_encode a échoué (json_last_error=' . json_last_error_msg() . ')']);
    }

    private function rawJson(array $data): ResponseInterface
    {
        return $this->response->setContentType('application/json')->setBody($this->jsonSafe($data));
    }

    /**
     * Exécute une action de test FFTT en capturant warnings PHP et exceptions,
     * comme le fait le dispatcher legacy (register_shutdown_function,
     * set_error_handler, ob_start), pour afficher le détail de tout incident
     * dans l'interface de diagnostic plutôt que de le masquer.
     */
    private function runAction(\Closure $fn): ResponseInterface
    {
        $phpWarnings = [];
        set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$phpWarnings) {
            $phpWarnings[] = "[$errno] $errstr — $errfile:$errline";

            return true;
        });

        ob_start();
        $trace = [];
        $api   = null;

        try {
            $trace[] = ['étape' => '1. Credentials', 'app_id' => getFfttAppId(), 'app_key_len' => strlen(getFfttAppKey())];
            $api     = getFfttApi();
            $trace[] = ['étape' => '2. FfttApi instanciée', 'serial' => getConfig('fftt_serial', '(non généré)')];

            $result = $fn($api, $trace, $phpWarnings);
            ob_end_clean();
            restore_error_handler();

            return $this->rawJson($result);
        } catch (\Throwable $e) {
            $parasite = ob_get_level() ? ob_get_clean() : '';
            $trace[]  = [
                'étape'     => 'Exception',
                'class'     => get_class($e),
                'message'   => $e->getMessage(),
                'fichier'   => $e->getFile() . ':' . $e->getLine(),
                'backtrace' => array_map(
                    fn ($f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ' → ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                    array_slice($e->getTrace(), 0, 8)
                ),
            ];
            restore_error_handler();

            return $this->response->setJSON([
                'ok' => false, 'msg' => $e->getMessage(),
                'url' => $api ? $api->lastUrl() : '', 'http' => $api ? $api->lastHttp() : 0,
                'trace' => $trace, 'warnings' => $phpWarnings, 'parasite' => $parasite ?: null,
            ]);
        }
    }

    /**
     * Retourne [iddivision => code] des divisions nationales actives.
     */
    private function fetchNatDivIds(\FfttApi $api, string $phase, array &$debug): array
    {
        $debug = [];

        $orgId = getConfig('fftt_organisme_id', '');
        $debug['orgId_config'] = $orgId;

        if ($orgId === '') {
            $region = mb_strtolower(getConfig('region', 'Normandie'), 'UTF-8');
            $rep    = $api->request('xml_organisme', ['type' => 'L']);
            $debug['xml_organisme_url'] = $api->lastUrl();
            $orgs   = $rep['organisme'] ?? [];
            if (!empty($orgs) && !isset($orgs[0])) {
                $orgs = [$orgs];
            }
            $debug['organismes'] = array_map(fn ($o) => [
                'id'      => $o['id'] ?? $o['ident'] ?? '?',
                'libelle' => $o['libelle'] ?? $o['nom'] ?? '?',
            ], $orgs);
            foreach ($orgs as $org) {
                $lib = mb_strtolower((string) ($org['libelle'] ?? $org['nom'] ?? ''), 'UTF-8');
                if (str_contains($lib, $region)) {
                    $orgId = (string) ($org['id'] ?? $org['ident'] ?? '');
                    $debug['orgId_trouvé'] = $orgId;
                    break;
                }
            }
            if ($orgId === '') {
                $debug['erreur'] = "Organisme \"$region\" introuvable dans la liste.";

                return [];
            }
        }

        $epParams = ['organisme' => $orgId, 'type' => 'E'];
        if ($phase !== '') {
            $epParams['phase'] = $phase;
        }
        $epRep = $api->request('xml_epreuve', $epParams);
        $debug['xml_epreuve_url'] = $api->lastUrl();
        $eps   = $epRep['epreuve'] ?? [];
        if (!empty($eps) && !isset($eps[0])) {
            $eps = [$eps];
        }
        $debug['nb_epreuves_total'] = count($eps);

        $epsNat = [];
        foreach ($eps as $ep) {
            $intitule = (string) ($ep['intitule'] ?? $ep['libelle'] ?? '');
            if (preg_match('/nationale/i', $intitule)) {
                $epsNat[] = $ep;
            }
        }
        $debug['epreuves_nationales'] = array_map(fn ($e) => [
            'id'       => $e['idepreuve'] ?? $e['ident'] ?? '?',
            'intitule' => $e['intitule'] ?? $e['libelle'] ?? '?',
        ], $epsNat);

        $ids = [];
        foreach ($epsNat as $ep) {
            $idEp = (string) ($ep['idepreuve'] ?? $ep['ident'] ?? '');
            if ($idEp === '') {
                continue;
            }
            $divParams = ['organisme' => $orgId, 'epreuve' => $idEp, 'type' => 'E'];
            if ($phase !== '') {
                $divParams['phase'] = $phase;
            }
            $divRep = $api->request('xml_division', $divParams);
            $divs   = $divRep['division'] ?? [];
            if (!empty($divs) && !isset($divs[0])) {
                $divs = [$divs];
            }
            $debug['divisions'][$idEp] = array_map(fn ($d) => [
                'iddivision' => $d['iddivision'] ?? $d['ident'] ?? '?',
                'libelle'    => $d['libelle'] ?? '?',
            ], $divs);
            foreach ($divs as $div) {
                $lib   = (string) ($div['libelle'] ?? '');
                $idDiv = (string) ($div['iddivision'] ?? $div['ident'] ?? '');
                if ($idDiv === '') {
                    continue;
                }
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
    private function extractD1(array $eq): string
    {
        $raw  = $eq['liendivision'] ?? $eq['lien'] ?? $eq['liendiv'] ?? '';
        $lien = is_array($raw) ? (string) ($raw[0] ?? $raw['0'] ?? implode('', $raw)) : (string) $raw;
        if ($lien === '') {
            return '';
        }
        parse_str(html_entity_decode($lien), $lp);

        return (string) ($lp['D1'] ?? $lp['d1'] ?? '');
    }

    /** Détecte le code division nationale depuis les champs disponibles. */
    private function detectNatCode(array $eq): ?string
    {
        $ndiv    = trim((string) ($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? $eq['libelledivision'] ?? ''));
        $libelle = trim((string) ($eq['libequipe'] ?? $eq['libelle'] ?? ''));

        foreach ([$ndiv, $libelle] as $src) {
            if ($src === '') {
                continue;
            }
            if (preg_match('/\b(N[1-9][MFD])\b/i', $src, $m)) {
                return strtoupper($m[1]);
            }
            if (preg_match('/nationale[^\d]*(\d+)[^\w]*(messieurs?|hommes?|dames?|f[eé]minin)/i', $src, $m)) {
                return 'N' . $m[1] . (preg_match('/dame|f[eé]minin/i', $m[2]) ? 'F' : 'M');
            }
            if (preg_match('/\bpro\s*([ab])\b/i', $src, $m)) {
                return 'PRO' . strtoupper($m[1]);
            }
            if (preg_match('/\s+(\d)[MF]$/i', $src, $m2)) {
                $genre = strtoupper(substr($src, -1));

                return 'N' . $m2[1] . $genre;
            }
        }

        return null;
    }

    public function index()
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        $u = $_SESSION['utilisateur'] ?? [];

        $data = [
            'nomComplet'  => trim(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? '')),
            'departement' => $u['id_departement'] ?? '',
            'changeLogin' => !empty($u['change_login']),
            'isAdmin'     => !empty($u['is_admin']),
            'appId'       => getFfttAppId(),
            'appKey'      => getFfttAppKey(),
            'serial'      => getConfig('fftt_serial', ''),
            'deptsNorm'   => getDeptActifs(),
        ];

        return view('fftt_test_index', $data);
    }

    public function ping(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->rawJson(['ok' => true, 'msg' => 'PHP répond correctement', 'php' => PHP_VERSION, 'warnings' => []]);
    }

    public function testClubsDep(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $dep     = trim($this->request->getPost('dep') ?? '76');
            $trace[] = ['étape' => '3. Appel xml_club_dep2', 'dep' => $dep];
            $clubs   = $api->getClubsDepartement($dep);
            $trace[] = ['étape' => '4. Réponse reçue', 'count' => count($clubs)];

            return ['ok' => true, 'count' => count($clubs), 'data' => array_slice($clubs, 0, 5), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testLicence(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $licence = trim($this->request->getPost('licence') ?? '');
            if ($licence === '') {
                return ['ok' => false, 'msg' => 'Numéro de licence requis', 'trace' => $trace];
            }
            $trace[] = ['étape' => '3. Appel xml_licence', 'licence' => $licence];
            $data    = $api->getLicence($licence);
            $trace[] = ['étape' => '4. Réponse reçue', 'vide' => ($data === null)];

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testEquipes(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $club = trim($this->request->getPost('club') ?? '');
            if ($club === '') {
                return ['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace];
            }
            $trace[] = ['étape' => '3. Appel xml_equipe', 'club' => $club];
            $data    = $api->getEquipesClub($club);
            $trace[] = ['étape' => '4. Réponse reçue', 'count' => count($data)];

            return ['ok' => true, 'count' => count($data), 'data' => $data, 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testClubDetail(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $club = trim($this->request->getPost('club') ?? '');
            if ($club === '') {
                return ['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace];
            }
            $trace[] = ['étape' => '3. Appel xml_club_detail', 'club' => $club];
            $data    = $api->getClubDetail($club);
            $trace[] = ['étape' => '4. Réponse reçue', 'clés' => array_keys($data)];

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function debugClubSalle(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $club = trim($this->request->getPost('club') ?? '');
            if ($club === '') {
                return ['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace];
            }
            $data        = $api->getClubDetail($club);
            $champsSalle = ['nomsalle', 'adressesalle1', 'adressesalle2', 'adressesalle3', 'codepsalle', 'villesalle'];
            $analyse     = [];
            foreach ($champsSalle as $champ) {
                $val = $data[$champ] ?? null;
                $analyse[$champ] = [
                    'type'   => gettype($val),
                    'count'  => is_array($val) ? count($val) : null,
                    'valeur' => $val,
                ];
            }

            return ['ok' => true, 'club' => $club, 'analyse' => $analyse, 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testLicenceB(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $licence = trim($this->request->getPost('licence') ?? '');
            if ($licence === '') {
                return ['ok' => false, 'msg' => 'Numéro de licence requis', 'trace' => $trace];
            }
            $trace[] = ['étape' => '3. Appel xml_licence_b', 'licence' => $licence];
            $data    = $api->request('xml_licence_b', ['licence' => $licence]);
            $trace[] = ['étape' => '4. Réponse reçue', 'clés' => array_keys($data)];

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testArbitresDep(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $dep     = trim($this->request->getPost('dep') ?? '76');
            $trace[] = ['étape' => '3. Récupération clubs + membres', 'dep' => $dep];
            set_time_limit(120);
            $arbitres = $api->getArbitresDepartement($dep);
            $trace[]  = ['étape' => '4. Terminé', 'arbitres_trouvés' => count($arbitres)];

            return ['ok' => true, 'count' => count($arbitres), 'data' => $arbitres, 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testSpidClub(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $club = trim($this->request->getPost('club') ?? '');
            if ($club === '') {
                return ['ok' => false, 'msg' => 'Numéro de club requis', 'trace' => $trace];
            }
            $trace[]  = ['étape' => '3. Appel xml_liste_joueur_o', 'club' => $club];
            $membres  = $api->getLicenciesClub($club);
            $trace[]  = ['étape' => '4. Réponse reçue', 'count' => count($membres)];
            $exemple  = $membres[0] ?? null;
            $champs   = $exemple ? array_keys($exemple) : [];

            return ['ok' => true, 'count' => count($membres), 'champs' => $champs, 'data' => $exemple, 'trace' => $trace];
        });
    }

    public function testOrganisme(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $type = trim($this->request->getPost('type') ?? 'L');
            $data = $api->request('xml_organisme', ['type' => $type]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testEpreuve(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $org  = trim($this->request->getPost('organisme') ?? '');
            $type = trim($this->request->getPost('type') ?? 'E');
            if ($org === '') {
                return ['ok' => false, 'msg' => 'ID organisme requis'];
            }
            $data = $api->request('xml_epreuve', ['organisme' => $org, 'type' => $type]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testDivision(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $org     = trim($this->request->getPost('organisme') ?? '');
            $epreuve = trim($this->request->getPost('epreuve') ?? '');
            $type    = trim($this->request->getPost('type') ?? 'E');
            if ($org === '' || $epreuve === '') {
                return ['ok' => false, 'msg' => 'Organisme et épreuve requis'];
            }
            $data = $api->request('xml_division', ['organisme' => $org, 'epreuve' => $epreuve, 'type' => $type]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testPoule(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $org      = trim($this->request->getPost('organisme') ?? '');
            $epreuve  = trim($this->request->getPost('epreuve') ?? '');
            $division = trim($this->request->getPost('division') ?? '');
            $type     = trim($this->request->getPost('type') ?? 'E');
            if ($org === '' || $epreuve === '' || $division === '') {
                return ['ok' => false, 'msg' => 'Organisme, épreuve et division requis'];
            }
            $data = $api->request('xml_poule', ['organisme' => $org, 'epreuve' => $epreuve, 'division' => $division, 'type' => $type]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testRencontre(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $division = trim($this->request->getPost('division') ?? '');
            $type     = trim($this->request->getPost('type') ?? 'E');
            if ($division === '') {
                return ['ok' => false, 'msg' => 'Division requise'];
            }

            $rPoules    = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $division, 'auto' => '1', 'type' => $type]);
            $urlPoules  = $api->lastUrl();
            $pouleItems = isset($rPoules['poule']) ? (isset($rPoules['poule'][0]) ? $rPoules['poule'] : [$rPoules['poule']]) : [];

            $cxPoules = [];
            foreach ($pouleItems as $p) {
                $lienRaw = $p['lien'] ?? '';
                $lienStr = is_array($lienRaw) ? '' : (string) $lienRaw;
                parse_str(html_entity_decode($lienStr), $lp);
                if (!empty($lp['cx_poule'])) {
                    $cxPoules[] = $lp['cx_poule'];
                }
            }

            $rencData = null;
            $urlRenc  = null;
            if (!empty($cxPoules)) {
                $rencData = $api->request('xml_rencontre_equ', ['poule' => implode('|', $cxPoules)]);
                $urlRenc  = $api->lastUrl();
            }

            return [
                'ok' => true, 'poules' => $pouleItems, 'cx_poules' => $cxPoules,
                'url_poules' => $urlPoules, 'xml_poules_brut' => $api->lastRaw(),
                'rencontres' => $rencData, 'url_rencontres' => $urlRenc,
                'trace' => $trace, 'warnings' => $warnings,
            ];
        });
    }

    public function testRencontrePoule(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $cxPoule = trim($this->request->getPost('cx_poule') ?? '');
            $d1      = trim($this->request->getPost('D1') ?? '');
            $type    = trim($this->request->getPost('type') ?? 'E');
            if ($cxPoule === '' || $d1 === '') {
                return ['ok' => false, 'msg' => 'cx_poule et D1 requis'];
            }
            $data = $api->request('xml_result_equ', ['cx_poule' => $cxPoule, 'D1' => $d1, 'auto' => '1', 'type' => $type]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'xml_brut' => $api->lastRaw(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testChpRenc(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $idRenc = trim($this->request->getPost('id_rencontre') ?? '');
            if ($idRenc === '') {
                return ['ok' => false, 'msg' => 'id_rencontre requis'];
            }
            $data = $api->request('xml_chp_renc', ['id_rencontre' => $idRenc]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testResultEqu(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $club = trim($this->request->getPost('club') ?? '');
            $equ  = trim($this->request->getPost('equ') ?? '');
            if ($club === '' || $equ === '') {
                return ['ok' => false, 'msg' => 'Club et équipe requis'];
            }
            $data = $api->request('xml_result_equ', ['club' => $club, 'equ' => $equ]);

            return ['ok' => true, 'data' => $data, 'url' => $api->lastUrl(), 'http' => $api->lastHttp(), 'trace' => $trace, 'warnings' => $warnings];
        });
    }

    public function testEquipeNat(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $numclu = trim($this->request->getPost('club') ?? '');
            if ($numclu === '') {
                return ['ok' => false, 'msg' => 'N° club requis'];
            }

            $trace[]     = ['étape' => '3. Chargement divisions nationales actives'];
            $natDivDebug = [];
            $natDivIds   = $this->fetchNatDivIds($api, '', $natDivDebug);
            $trace[]     = ['étape' => '4. Divisions actives', 'count' => count($natDivIds), 'ids' => $natDivIds, 'debug' => $natDivDebug];

            $trace[]  = ['étape' => '5. xml_equipe', 'numclu' => $numclu];
            $equipes  = $api->getEquipesClub($numclu);
            $trace[]  = ['étape' => '6. Réponse', 'nb_equipes' => count($equipes)];

            $analyse = [];
            foreach ($equipes as $eq) {
                $lib     = trim((string) ($eq['libequipe'] ?? $eq['libelle'] ?? ''));
                $ndiv    = trim((string) ($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? $eq['libelledivision'] ?? ''));
                $d1      = $this->extractD1($eq);
                $divCode = $this->detectNatCode($eq);

                $phaseDiv = '';
                if (preg_match('/\bPhase\s+(\d+)\b/i', $ndiv, $pm)) {
                    $phaseDiv = $pm[1];
                }

                $analyse[] = [
                    'libelle'     => $lib,
                    'champ_div'   => $ndiv !== '' ? $ndiv : '(vide)',
                    'd1'          => $d1 ?: '—',
                    'phase_div'   => $phaseDiv ?: '—',
                    'tous_champs' => $eq,
                    'détecté'     => $divCode !== null,
                    'code_div'    => $divCode,
                ];
            }

            $trace[] = ['étape' => '7. Nationales retenues', 'count' => count(array_filter($analyse, fn ($a) => $a['détecté']))];

            return [
                'ok' => true, 'analyse' => $analyse, 'nat_div_ids' => $natDivIds,
                'url' => $api->lastUrl(), 'http' => $api->lastHttp(),
                'trace' => $trace, 'warnings' => $warnings, 'xml_brut' => $api->lastRaw(),
            ];
        });
    }

    public function scanDeptNat(): ResponseInterface
    {
        if ($guard = $this->guardChautard()) {
            return $guard;
        }

        return $this->runAction(function ($api, &$trace, &$warnings) {
            $dep   = trim($this->request->getPost('dep') ?? '76');
            $phase = trim($this->request->getPost('phase') ?? '');
            set_time_limit(300);

            $trace[]     = ['étape' => '3. Chargement divisions nationales actives', 'phase' => $phase ?: 'toutes'];
            $natDivDebug = [];
            $natDivIds   = $this->fetchNatDivIds($api, $phase, $natDivDebug);
            $trace[]     = ['étape' => '4. Divisions actives', 'count' => count($natDivIds), 'ids' => $natDivIds, 'debug' => $natDivDebug];

            $trace[] = ['étape' => '5. Clubs du département', 'dep' => $dep];
            $clubs   = $api->getClubsDepartement($dep);
            $trace[] = ['étape' => '6. ' . count($clubs) . ' club(s) à scanner'];

            $filtreD1 = !empty($natDivIds);
            $trace[]  = ['étape' => '6b. Filtre D1 actif', 'actif' => $filtreD1];

            $resultats = [];
            $detail    = [];

            foreach ($clubs as $c) {
                $numclu  = trim((string) ($c['numero'] ?? $c['numclu'] ?? ''));
                $nomClub = trim((string) ($c['nom'] ?? ''));
                if ($numclu === '') {
                    continue;
                }

                $clubTrace = ['numclu' => $numclu, 'nom' => $nomClub, 'equipes' => [], 'erreur' => null];

                try {
                    $equipes = $api->getEquipesClub($numclu);
                    $clubTrace['url_equipe']  = $api->lastUrl();
                    $clubTrace['http_equipe'] = $api->lastHttp();
                    $clubTrace['nb_equipes']  = count($equipes);
                    $clubTrace['raw_equipe']  = substr($api->lastRaw(), 0, 800);
                    $nationales = [];

                    foreach ($equipes as $eq) {
                        $lib = trim((string) ($eq['libequipe'] ?? $eq['libelle'] ?? ''));
                        if ($lib === '') {
                            continue;
                        }
                        $ndiv = trim((string) ($eq['libdivision'] ?? $eq['ndivision'] ?? $eq['division'] ?? ''));

                        $eqTrace = ['lib' => $lib, 'ndiv' => $ndiv, 'd1' => $this->extractD1($eq) ?: '—', 'statut' => '', 'code' => null, 'brut' => $eq];

                        $divCode = $this->detectNatCode($eq);
                        if ($divCode === null) {
                            $eqTrace['statut'] = $ndiv !== '' ? 'non_nationale' : 'sans_division';
                            $clubTrace['equipes'][] = $eqTrace;
                            continue;
                        }

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
                usleep(150000);
            }
            $trace[] = ['étape' => '7. Clubs avec nationale phase à venir', 'count' => count($resultats)];

            return [
                'ok' => true, 'resultats' => $resultats, 'clubs_scannes' => count($clubs),
                'nat_div_ids' => $natDivIds, 'detail' => $detail,
                'trace' => $trace, 'warnings' => $warnings,
            ];
        });
    }
}
