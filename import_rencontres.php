<?php
/**
 * NIJAC – Import des rencontres depuis l'API FFTT (E011)
 *
 * Flux : Ligue → Épreuves → Divisions → Poules → Rencontres → BDD
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-28
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';

require __DIR__ . '/includes/admin_required.php';

// Garantit que la clé "region" existe en configuration
try {
    getPDO()->exec("INSERT IGNORE INTO configuration (cle, valeur) VALUES ('region', 'Normandie')");
} catch (PDOException $e) {}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Normalise une réponse FFTT (objet unique ou tableau) en tableau indexé. */
function ffttItems(array $data, string $key): array
{
    $items = $data[$key] ?? [];
    if (empty($items)) return [];
    return isset($items[0]) ? $items : [$items];
}

/**
 * Tente de deviner l'Id_Division NIJAC depuis le libellé FFTT d'une division.
 * IDs hardcodés d'après la table division actuelle.
 */
function devinerIdDivision(string $libelle): ?int
{
    // Supprimer le préfixe de ligue FFTT de la forme "L09_", "L17_", etc. (4 premiers caractères)
    $libelle = preg_replace('/^[A-Z]\d{2}_/u', '', $libelle);

    $l   = mb_strtolower($libelle, 'UTF-8');
    $fem = str_contains($l, 'dame') || str_contains($l, 'féminin') || str_contains($l, 'feminin');

    // Correspondance directe avec les codes NIJAC (ex. "R1M", "R2M", "PNF"…)
    $map = [
        'r1m' => 3, 'r2m' => 2, 'r3m' => 1, 'r4m' => 10,
        'r1f' => 8, 'pnm' => 4, 'pnf' => 9,
        'n1m' => 7, 'n2m' => 6, 'n3m' => 5,
        'n1f' => 12, 'n2f' => 11,
    ];
    if (isset($map[$l])) return $map[$l];

    // Correspondance par mots-clés
    if (str_contains($l, 'pré-nationale') || str_contains($l, 'pre-nationale')) {
        return $fem ? 9 : 4;   // PNF=9, PNM=4
    }
    if (str_contains($l, 'régionale') || str_contains($l, 'regionale')) {
        preg_match('/(\d+)/', $l, $m);
        $n = (int)($m[1] ?? 0);
        if ($fem) return $n === 1 ? 8 : null;       // R1F=8
        return match($n) { 1=>3, 2=>2, 3=>1, 4=>10, default=>null };
    }
    return null;
}

/** Parse une date FFTT ("DD/MM/YYYY", "DD/MM/YYYY HHhMM", "YYYY-MM-DD") → "YYYY-MM-DD" ou null. */
function parseDateFftt(string $s): ?string
{
    if (preg_match('/(\d{1,2})\/(\d{2})\/(\d{4})/', $s, $m)) {
        return $m[3] . '-' . $m[2] . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return $m[0];
    }
    return null;
}

/** Parse une heure FFTT ("09H00", "09:00", "09:00:00") → "HH:MM:SS". */
function parseHeureFftt(string $s): string
{
    if (preg_match('/(\d{1,2})[Hh](\d{2})/', $s, $m)) return str_pad($m[1],2,'0',STR_PAD_LEFT).':'.$m[2].':00';
    if (preg_match('/(\d{2}):(\d{2})/', $s, $m))       return $m[1].':'.$m[2].':00';
    return '09:00:00';
}

// ── AJAX ──────────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── 1. Recherche de la ligue ──────────────────────────────────────────
        if ($action === 'chercher_ligue') {
            $region  = trim(getConfig('region', 'Normandie'));
            $api     = getFfttApi();
            $reponse = $api->request('xml_organisme', ['type' => 'L']);
            $items   = ffttItems($reponse, 'organisme');

            $regionN = mb_strtolower($region, 'UTF-8');
            $trouve  = null;
            // Champs possibles pour l'ID selon la version de l'API FFTT
            $champId = function(array $org): string {
                foreach (['id', 'ident', 'idorga', 'numero', 'numorg'] as $f) {
                    if (isset($org[$f]) && (string)$org[$f] !== '') return (string)$org[$f];
                }
                return '';
            };

            foreach ($items as $org) {
                $libelle = (string)($org['libelle'] ?? '');
                if (mb_strpos(mb_strtolower($libelle, 'UTF-8'), $regionN) !== false) {
                    $trouve = ['id' => $champId($org), 'libelle' => $libelle];
                    break;
                }
            }

            // Debug : clés du premier organisme pour diagnostiquer les noms de champs
            $clesPremier = !empty($items) ? array_keys($items[0]) : [];

            ob_end_clean();
            if ($trouve && $trouve['id'] !== '') {
                echo json_encode(['ok' => true, 'ligue' => $trouve, 'total' => count($items)]);
            } elseif ($trouve) {
                // Ligue trouvée mais ID vide → retourner les clés pour diagnostic
                echo json_encode([
                    'ok'    => false,
                    'msg'   => "Ligue « {$trouve['libelle']} » trouvée mais champ ID introuvable. Clés disponibles : " . implode(', ', $clesPremier),
                    'ligues'=> array_column($items, 'libelle'),
                    'debug' => $items[0] ?? [],
                ]);
            } else {
                echo json_encode([
                    'ok'     => false,
                    'msg'    => "Aucune ligue « $region » parmi " . count($items) . " ligues.",
                    'ligues' => array_column($items, 'libelle'),
                ]);
            }
            exit;
        }

        // ── 2. Épreuves d'un organisme (type E = équipes) ─────────────────────
        if ($action === 'charger_epreuves') {
            $organisme = trim($_POST['organisme'] ?? '');
            if ($organisme === '') { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Organisme manquant.']); exit; }

            $api     = getFfttApi();
            $reponse = $api->request('xml_epreuve', ['organisme' => $organisme, 'type' => 'E']);
            $items   = ffttItems($reponse, 'epreuve');

            // Pour les épreuves FED_ : dédoublonner par intitulé, garder le idepreuve le plus grand
            $fed    = [];   // [intitule => item] avec le plus grand idepreuve
            $autres = [];
            foreach ($items as $ep) {
                $intitule = (string)($ep['intitule'] ?? $ep['libelle'] ?? '');
                $idEp     = (int)($ep['idepreuve'] ?? $ep['ident'] ?? 0);
                if (stripos($intitule, 'FED_') === 0) {
                    if (!isset($fed[$intitule]) || $idEp > (int)($fed[$intitule]['idepreuve'] ?? $fed[$intitule]['ident'] ?? 0)) {
                        $fed[$intitule] = $ep;
                    }
                } else {
                    $autres[] = $ep;
                }
            }
            $items = array_merge(array_values($fed), $autres);

            ob_end_clean();
            echo json_encode(['ok' => true, 'epreuves' => $items]);
            exit;
        }

        // ── 3. Divisions d'une épreuve ────────────────────────────────────────
        // ── DEBUG : structure brute xml_result_equ ───────────────────────────
        if ($action === 'debug_result_equ') {
            $divFftt = trim($_POST['division_fftt'] ?? '');
            if ($divFftt === '') { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'division_fftt manquant']); exit; }
            $api = getFfttApi();
            $resultats = [];

            // Test 1 : action=poule → liste des poules avec cx_poule si 'lien' disponible
            $cxPoules   = [];
            $pouleItems = [];
            try {
                $r          = $api->request('xml_result_equ', ['action'=>'poule','D1'=>$divFftt,'auto'=>'1','type'=>'E']);
                $pouleItems = isset($r['poule']) ? (isset($r['poule'][0]) ? $r['poule'] : [$r['poule']]) : [];
                foreach ($pouleItems as $i => $p) {
                    $lienRaw = $p['lien'] ?? ''; $lienStr = is_array($lienRaw) ? '' : (string)$lienRaw;
                    parse_str(html_entity_decode($lienStr), $lp);
                    if (!empty($lp['cx_poule'])) $cxPoules[$i+1] = $lp['cx_poule'];
                }
                $resultats[] = ['test'=>'action=poule','url'=>$api->lastUrl(),'nb_poules'=>count($pouleItems),'cx_poules'=>$cxPoules,'apercu'=>$pouleItems];
            } catch (\Throwable $e) { $resultats[] = ['test'=>'action=poule','erreur'=>$e->getMessage(),'url'=>$api->lastUrl()]; }

            // Test 2 : si cx_poules disponibles → appel par poule individuelle
            if (count($cxPoules) === count($pouleItems) && count($cxPoules) > 0) {
                foreach ($cxPoules as $num => $cx) {
                    try {
                        $r    = $api->request('xml_result_equ', ['D1'=>$divFftt,'cx_poule'=>$cx,'auto'=>'1','type'=>'E']);
                        $tours = isset($r['tour']) ? (isset($r['tour'][0]) ? $r['tour'] : [$r['tour']]) : [];
                        $resultats[] = ['test'=>"poule $num (cx=$cx)",'url'=>$api->lastUrl(),'nb_tours'=>count($tours),'apercu'=>array_slice($tours,0,2)];
                    } catch (\Throwable $e) { $resultats[] = ['test'=>"poule $num",'erreur'=>$e->getMessage(),'url'=>$api->lastUrl()]; }
                }
            } else {
                // Test 3 : fallback global (lien vide → cx_poule non disponible)
                try {
                    $r    = $api->request('xml_result_equ', ['D1'=>$divFftt,'auto'=>'1','type'=>'E']);
                    $tours = isset($r['tour']) ? (isset($r['tour'][0]) ? $r['tour'] : [$r['tour']]) : [];
                    $resultats[] = ['test'=>'fallback global (lien vide)','url'=>$api->lastUrl(),'nb_tours'=>count($tours),'apercu'=>array_slice($tours,0,2)];
                } catch (\Throwable $e) { $resultats[] = ['test'=>'fallback global','erreur'=>$e->getMessage(),'url'=>$api->lastUrl()]; }
            }

            ob_end_clean();
            echo json_encode(['ok'=>true, 'resultats'=>$resultats]);
            exit;
        }

        if ($action === 'charger_divisions') {
            $organisme = trim($_POST['organisme'] ?? '');
            $epreuve   = trim($_POST['epreuve']   ?? '');
            if ($organisme === '' || $epreuve === '') {
                ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Paramètres manquants.']); exit;
            }

            $api     = getFfttApi();
            $reponse = $api->request('xml_division', ['organisme'=>$organisme, 'epreuve'=>$epreuve, 'type'=>'E']);
            $items   = ffttItems($reponse, 'division');

            // Charger les divisions NIJAC disponibles pour le select
            $divsNijac = $pdo->query('SELECT Id_Division, Division FROM division ORDER BY Division')
                             ->fetchAll(PDO::FETCH_ASSOC);

            // Enrichir chaque division avec la suggestion NIJAC auto-détectée
            foreach ($items as &$div) {
                $libelle = (string)($div['libelle'] ?? '');
                $div['id_division_nijac_auto'] = devinerIdDivision($libelle);
            }
            unset($div);

            ob_end_clean();
            echo json_encode(['ok' => true, 'divisions' => $items, 'divisions_nijac' => $divsNijac]);
            exit;
        }

        // ── 4. Importer toutes les rencontres d'une division ─────────────────
        if ($action === 'importer_division') {
            set_time_limit(180);

            $organisme  = trim($_POST['organisme']     ?? '');
            $epreuve    = trim($_POST['epreuve']       ?? '');
            $divFftt    = trim($_POST['division_fftt'] ?? '');
            $idDivNijac = (int)($_POST['id_division']  ?? 0);
            $phase      = (int)($_POST['phase']        ?? 1);

            if ($organisme === '' || $epreuve === '' || $divFftt === '' || $idDivNijac <= 0) {
                ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>'Paramètres manquants.']); exit;
            }

            $stmtDiv = $pdo->prepare('SELECT ArbitrageCRA, Division FROM division WHERE Id_Division=?');
            $stmtDiv->execute([$idDivNijac]);
            $divInfo = $stmtDiv->fetch();
            if (!$divInfo) { ob_end_clean(); echo json_encode(['ok'=>false,'msg'=>"Division NIJAC #$idDivNijac introuvable."]); exit; }
            $arbitrage   = (int)$divInfo['ArbitrageCRA'];
            $divCode     = (string)$divInfo['Division'];          // ex: "N1M", "R2M"
            $isNationale = str_starts_with($divCode, 'N');        // N1M, N2M, N3M, N1F, N2F

            $api = getFfttApi();

            // Étape 1 : liste des poules (libellés + cx_poule via champ 'lien')
            $rPoules    = $api->request('xml_result_equ', ['action' => 'poule', 'D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
            $pouleItems = ffttItems($rPoules, 'poule');

            // Construire la map : numéro de poule (1,2,3…) → cx_poule réel si disponible dans 'lien'
            $pouleLibelles = []; // [1 => 'Poule A', 2 => 'Poule B', ...]
            $cxPoules      = []; // [1 => '12345', 2 => '12346', ...]  — vide si lien absent
            foreach ($pouleItems as $i => $p) {
                $num     = $i + 1;
                $pouleLibelles[$num] = trim((string)($p['libelle'] ?? "Poule $num"));
                $lienRaw = $p['lien'] ?? '';
                $lienStr = is_array($lienRaw) ? '' : (string)$lienRaw;
                if ($lienStr !== '') {
                    parse_str(html_entity_decode($lienStr), $lp);
                    if (!empty($lp['cx_poule'])) $cxPoules[$num] = $lp['cx_poule'];
                }
            }

            // Étape 2 : récupérer les rencontres poule par poule (si cx_poule disponibles)
            //           ou en un seul appel global (si 'lien' vide — cas constaté avec apiv2)
            $rencontres = [];
            if (count($cxPoules) === count($pouleItems) && count($cxPoules) > 0) {
                // cx_poule connus : appel individuel par poule pour associer le numéro de poule
                foreach ($cxPoules as $num => $cx) {
                    $rP = $api->request('xml_result_equ', ['D1' => $divFftt, 'cx_poule' => $cx, 'auto' => '1', 'type' => 'E']);
                    foreach (ffttItems($rP, 'tour') as $rc) {
                        $rc['_poule_num'] = $num; // injecté pour éviter le parsing du libellé
                        $rencontres[] = $rc;
                    }
                }
            } else {
                // Fallback : un seul appel global, numéro de poule extrait du libellé
                $rAll       = $api->request('xml_result_equ', ['D1' => $divFftt, 'auto' => '1', 'type' => 'E']);
                $rencontres = ffttItems($rAll, 'tour');
            }

            $stats = ['poules'=>[],'equipes_creees'=>0,'rencontres_creees'=>0,'doublons'=>0,'erreurs'=>[],'log'=>[]];

            $stmtClubIns    = $pdo->prepare('INSERT IGNORE INTO club (Id_Club, Nom) VALUES (?,?)');
            $stmtClubByNom  = $pdo->prepare('SELECT Id_Club FROM club WHERE Nom=? LIMIT 1');
            $stmtClubById   = $pdo->prepare('SELECT Id_Club FROM club WHERE Id_Club=? LIMIT 1');
            $stmtNatChk     = $pdo->prepare('SELECT 1 FROM equipe_nationale WHERE Nom=? AND id_division=? LIMIT 1');
            $stmtNatIns     = $pdo->prepare(
                'INSERT INTO equipe_nationale (Nom, id_division, Poule, Rang, Id_Club, Id_Equipe) VALUES (?,?,?,0,?,?)'
            );
            $stmtEqChk   = $pdo->prepare('SELECT Id_Equipe FROM equipe WHERE Nom=? AND Id_Division=? LIMIT 1');
            $stmtEqIns   = $pdo->prepare('INSERT INTO equipe (Nom, Id_Division, Id_Club, JAdemande) VALUES (?,?,?,0)');
            $stmtRcChk   = $pdo->prepare('SELECT 1 FROM rencontre WHERE Date=? AND Id_EquipeDom=? AND Id_EquipeExt=? LIMIT 1');
            $stmtRcIns   = $pdo->prepare(
                'INSERT INTO rencontre (Date,Heure,Id_Division,Poule,Id_EquipeDom,Id_EquipeExt,Phase,Journee,ArbitrageObligatoire)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );

            foreach ($rencontres as $rc) {
                // Champs confirmés : equa, equb, dateprevue, libelle ("Poule X - tour n°Y du …")
                $libDom  = mb_substr(trim((string)($rc['equa'] ?? '')), 0, 100);
                $libExt  = mb_substr(trim((string)($rc['equb'] ?? '')), 0, 100);
                $dateStr = trim((string)($rc['dateprevue'] ?? ''));
                $libelle = trim((string)($rc['libelle'] ?? ''));

                // Numéro de poule : injecté directement si appel par poule, sinon extrait du libellé
                $pouleNum = isset($rc['_poule_num']) ? (int)$rc['_poule_num'] : 0;
                $journee  = 0;
                if ($pouleNum === 0 && preg_match('/poule\s+(\d+)/i', $libelle, $m)) $pouleNum = (int)$m[1];
                if (preg_match('/tour\s+n[°o]?\s*(\d+)/i', $libelle, $m)) $journee = (int)$m[1];

                $date = parseDateFftt($dateStr);
                if (!$date || $libDom === '' || $libExt === '') {
                    $stats['erreurs'][] = "Rencontre ignorée : dom=\"$libDom\" ext=\"$libExt\" date=\"$dateStr\"";
                    continue;
                }
                $heure = '00:00:00'; // xml_result_equ ne fournit pas l'heure

                // Compter les poules distinctes rencontrées
                if ($pouleNum > 0) $stats['poules'][$pouleNum] = true;

                // xml_result_equ ne fournit pas de code équipe → club fictif basé sur nom
                $nomClubDom = preg_replace('/\s+\d+$/', '', $libDom);
                $nomClubExt = preg_replace('/\s+\d+$/', '', $libExt);
                $clubDom = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nomClubDom), 0, 8));
                $clubExt = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', $nomClubExt), 0, 8));
                if ($clubDom === '') $clubDom = 'UNKNOWN1';
                if ($clubExt === '') $clubExt = 'UNKNOWN2';

                // Résoudre l'Id_Club réel pour dom et ext
                // (INSERT IGNORE peut être ignoré si Nom UNIQUE déjà pris par un autre Id_Club)
                foreach ([['dom', &$clubDom, $nomClubDom], ['ext', &$clubExt, $nomClubExt]] as [, &$idVar, $nom]) {
                    $nomTronc = mb_substr($nom, 0, 100);
                    $stmtClubIns->execute([$idVar, $nomTronc]);
                    if ($pdo->lastInsertId()) {
                        $stats['log'][] = ['type'=>'club','op'=>'créé','val'=>"$idVar — $nom"];
                    } else {
                        // INSERT ignoré : vérifier si notre Id_Club existe, sinon chercher par Nom
                        $stmtClubById->execute([$idVar]);
                        if (!$stmtClubById->fetchColumn()) {
                            $stmtClubByNom->execute([$nomTronc]);
                            $found = $stmtClubByNom->fetchColumn();
                            if ($found) $idVar = $found;
                        }
                    }
                }
                unset($idVar);

                foreach ([[$libDom,$clubDom,'idDom'], [$libExt,$clubExt,'idExt']] as [$lib,$club,$var]) {
                    $stmtEqChk->execute([$lib, $idDivNijac]);
                    $$var = $stmtEqChk->fetchColumn();
                    if (!$$var) {
                        $stmtEqIns->execute([$lib, $idDivNijac, $club]);
                        $$var = (int)$pdo->lastInsertId();
                        if ($$var) {
                            $stats['equipes_creees']++;
                            $stats['log'][] = ['type'=>'equipe','op'=>'créée','val'=>$lib];
                        } else {
                            $stmtEqChk->execute([$lib, $idDivNijac]);
                            $$var = (int)$stmtEqChk->fetchColumn();
                        }
                    }
                }

                if (!$idDom || !$idExt) {
                    $stats['erreurs'][] = "Équipe non créée : \"$libDom\" ou \"$libExt\"";
                    $stats['log'][] = ['type'=>'erreur','op'=>'échec équipe','val'=>"$libDom / $libExt"];
                    continue;
                }

                // Alimenter equipe_nationale si division Nationale (N1M, N2M…)
                if ($isNationale) {
                    foreach ([[$libDom,$clubDom,$idDom], [$libExt,$clubExt,$idExt]] as [$lib,$club,$idEq]) {
                        $stmtNatChk->execute([$lib, $idDivNijac]);
                        if (!$stmtNatChk->fetchColumn()) {
                            $stmtNatIns->execute([$lib, $idDivNijac, $pouleNum, $club, $idEq]);
                            $stats['log'][] = ['type'=>'nationale','op'=>'nat. ajoutée','val'=>"$divCode P$pouleNum — $lib"];
                        }
                    }
                }

                $stmtRcChk->execute([$date, $idDom, $idExt]);
                if ($stmtRcChk->fetchColumn()) {
                    $stats['doublons']++;
                    $stats['log'][] = ['type'=>'doublon','op'=>'ignorée','val'=>"P$pouleNum J$journee — $libDom vs $libExt ($date)"];
                    continue;
                }

                $stmtRcIns->execute([$date, $heure, $idDivNijac, $pouleNum, $idDom, $idExt, $phase, $journee, $arbitrage]);
                $stats['rencontres_creees']++;
                $stats['log'][] = ['type'=>'rencontre','op'=>'créée','val'=>"P$pouleNum J$journee — $libDom vs $libExt ($date)"];
            }

            $stats['poules'] = count($stats['poules']);
            ob_end_clean();
            echo json_encode(['ok' => true, 'stats' => $stats, 'nb_rencontres' => count($rencontres)]);
            exit;
        }

        // ── 5. Liste des rencontres déjà en base ─────────────────────────────
        if ($action === 'liste_rencontres') {
            $rows = $pdo->query(
                'SELECT r.Id_Rencontre, r.Date, r.Heure, r.Journee, r.Poule, r.Phase,
                        dv.Division AS DivisionCode, dv.Nom AS DivisionNom, dv.Color AS DivisionColor,
                        ed.Nom AS NomDom, ev.Nom AS NomExt,
                        r.ArbitrageObligatoire,
                        (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS NbNominations
                 FROM rencontre r
                 JOIN division dv ON dv.Id_Division = r.Id_Division
                 JOIN equipe   ed ON ed.Id_Equipe   = r.Id_EquipeDom
                 LEFT JOIN equipe ev ON ev.Id_Equipe = r.Id_EquipeExt
                 ORDER BY r.Date ASC, r.Heure ASC, dv.Ord ASC'
            )->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'rencontres' => $rows, 'total' => count($rows)]);
            exit;
        }

        // ── 6. Vider les tables de rencontres ────────────────────────────────
        if ($action === 'compter_tables') {
            $tables = ['equipe', 'equipe_nationale', 'rencontre', 'nomination', 'disponible'];
            $counts = [];
            foreach ($tables as $t) {
                $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            }
            ob_end_clean();
            echo json_encode(['ok' => true, 'counts' => $counts]);
            exit;
        }

        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
        exit;

    } catch (PDOException $e) {
        error_log('[NIJAC] import_rencontres.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] import_rencontres.php : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$moi         = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars(($moi['nom'] ?? '') . ' ' . ($moi['prenom'] ?? ''));
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
$region      = htmlspecialchars(getConfig('region', 'Normandie'));
$ffttOk      = (getFfttAppId() !== '' && getFfttAppKey() !== '');

// Calcul de la phase actuelle
(function () use (&$phaseLabel, &$phaseKey, &$phaseOptions) {
    $today   = new DateTime();
    $md      = (int)$today->format('m') * 100 + (int)$today->format('d');
    $toMd    = fn(string $s) => (int)substr($s, 0, 2) * 100 + (int)substr($s, 3, 2);

    $p1FinMd = $toMd(getConfig('phase1_fin',   '01-31')); // 131
    $p2DbtMd = $toMd(getConfig('phase2_debut', '02-01')); // 201
    $p2FinMd = $toMd(getConfig('phase2_fin',   '06-30')); // 630
    $p1DbtMd = $toMd(getConfig('phase1_debut', '09-01')); // 901

    $saison  = getConfig('saison', date('Y') . '-' . (date('Y') + 1));
    $parts   = explode('-', $saison);
    $a1 = (int)($parts[0] ?? date('Y'));
    $a2 = (int)($parts[1] ?? $a1 + 1);

    $saisonSuivante = ($a1 + 1) . '-' . ($a2 + 1);
    $phaseOptions = [
        ['key' => 'p1',   'label' => "Phase 1 · saison $saison"],
        ['key' => 'p2',   'label' => "Phase 2 · saison $saison"],
        ['key' => 'prep', 'label' => "Phase 1 · saison $saison (à venir)"],
    ];

    if ($md >= $p1DbtMd || $md <= $p1FinMd) {
        $phaseLabel = "Phase 1 · saison $saison";
        $phaseKey   = 'p1';
    } elseif ($md >= $p2DbtMd && $md <= $p2FinMd) {
        $phaseLabel = "Phase 2 · saison $saison";
        $phaseKey   = 'p2';
    } else {
        $phaseLabel = "Phase 1 · saison $saison (à venir)";
        $phaseKey   = 'prep';
    }
})();

// Divisions NIJAC pour le JS
$divsNijac = getPDO()->query('SELECT Id_Division, Division FROM division ORDER BY Division')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <title>NIJAC – Import Rencontres FFTT (E011)</title>
    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="asset/css/nijac.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; }
        #content { padding: 1.25rem 1.5rem; }

        /* ── Bouton FFTT ── */
        #btn-fftt {
            display: flex; align-items: center; gap: 1rem;
            padding: .9rem 1.5rem; background: #fff;
            border: 2px solid #1a3a6b; border-radius: 12px;
            cursor: pointer; font-size: 1rem; font-weight: 600; color: #1a3a6b;
            transition: background .15s, box-shadow .15s;
            box-shadow: 0 2px 8px rgba(26,58,107,.12);
        }
        #btn-fftt:hover:not(:disabled) { background: #eef3fb; box-shadow: 0 4px 16px rgba(26,58,107,.2); }
        #btn-fftt:disabled { opacity: .5; cursor: default; }
        #btn-fftt img { height: 40px; }

        /* ── Sections ── */
        .section-box {
            background: #fff; border: 1px solid #d0d8e8;
            border-radius: 10px; padding: 1rem 1.25rem; margin-top: 1.25rem;
        }
        .section-title {
            font-size: .92rem; font-weight: 700; color: #1a3a6b;
            display: flex; align-items: center; gap: .5rem; margin-bottom: .85rem;
        }

        /* ── Carte ligue ── */
        .ligue-card {
            display: flex; align-items: center; gap: 1rem;
            background: #f0fdf4; border: 2px solid #86efac;
            border-radius: 8px; padding: .75rem 1rem;
        }
        .ligue-id {
            font-size: 1.3rem; font-weight: 800; color: #166534;
            background: #dcfce7; border-radius: 6px; padding: .2rem .65rem;
        }
        .ligue-nom { font-size: 1rem; font-weight: 700; color: #14532d; }
        .ligue-sub { font-size: .8rem; color: #4b5563; margin-top: .1rem; }

        /* ── Liste épreuves ── */
        #liste-epreuves {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(450px, 1fr));
            gap: .25rem .75rem;
        }
        .epreuve-item {
            display: flex; align-items: flex-start; gap: .5rem;
            padding: .35rem .4rem; border-radius: 5px;
        }
        .epreuve-item:hover { background: #f7faff; }
        .epreuve-item label { cursor: pointer; font-size: .84rem; margin: 0; line-height: 1.3; }
        .epreuve-item .intitule { font-weight: 600; }
        .epreuve-item .ep-id    { font-size: .74rem; color: #6b7280; margin-left: .25rem; }

        /* ── Table divisions ── */
        #tbl-divisions { width: 100%; border-collapse: collapse; font-size: .85rem; }
        #tbl-divisions thead th {
            background: #e8eef7; border: 1px solid #c8d4e8;
            padding: .3rem .6rem; white-space: nowrap;
        }
        #tbl-divisions tbody td { border: 1px solid #e0e8f0; padding: .35rem .6rem; vertical-align: middle; }
        #tbl-divisions tbody tr:hover td { background: #f7faff; }
        #tbl-divisions tbody tr.div-selected td { background: #eef6ff; border-color: #bfdbfe; }
        #tbl-divisions tbody tr.div-selected:hover td { background: #dbeafe; }

        /* ── Barre de progression import ── */
        .div-progress {
            display: flex; align-items: center; gap: .75rem;
            padding: .45rem .6rem; border-radius: 6px; margin-bottom: .4rem;
            background: #f8faff; border: 1px solid #e0e8f0;
            font-size: .85rem;
        }
        .div-progress .dp-nom { flex: 1; font-weight: 600; }
        .dp-stats { font-size: .78rem; color: #4b5563; }
        .dp-ok  { color: #15803d; }
        .dp-err { color: #dc2626; }

        /* ── Stat cards ── */
        .stat-card {
            display: inline-block; text-align: center; min-width: 100px;
            background: #fff; border: 2px solid #d0d8e8; border-radius: 8px;
            padding: .4rem .65rem; margin: 0 .4rem .4rem 0;
        }
        .stat-card .sv { font-size: 1.5rem; font-weight: 700; color: #1a3a6b; }
        .stat-card .sl { font-size: .72rem; color: #6b7280; }

        #spinner-fftt { display:none; }
        #spinner-fftt.show { display:inline-block; }
    </style>
</head>
<body>

<?php
$pageIcon  = 'bi-trophy-fill';
$pageTitle = 'Import des rencontres FFTT';
$pageCode  = 'E011';
$backUrl   = 'admin_menu.php';
require __DIR__ . '/includes/page_header.php';
?>
<?php require __DIR__ . '/includes/toolbar.php'; ?>

<div id="content">

    <?php if (!$ffttOk): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-x-circle-fill"></i>
        Credentials FFTT non configurés — renseignez <code>FFTT_APP_ID</code> et <code>FFTT_APP_KEY</code> dans <code>.env</code>.
    </div>
    <?php endif; ?>

    <!-- ── Bouton + carte ligue (côte à côte) ── -->
    <div class="d-flex align-items-center gap-3 flex-wrap mb-1">
        <button id="btn-fftt" <?= $ffttOk ? '' : 'disabled' ?>>
            <img src="img/FFTT_LIGUE.png" alt="FFTT">
            <span>
                Importer depuis la FFTT
                <br>
                <span style="font-size:.8rem;font-weight:400;color:#4b5563;">
                    Ligue <strong><?= $region ?></strong> → épreuves → divisions → rencontres
                </span>
            </span>
            <span id="spinner-fftt" class="spinner-border spinner-border-sm text-primary ms-2"></span>
        </button>

        <div id="sec-ligue" style="display:none;flex:1;min-width:0;">
            <div id="ligue-card"></div>
        </div>

        <div class="ms-auto flex-shrink-0 text-end">
            <button id="btn-vider" class="btn btn-secondary text-white">
                <i class="bi bi-table me-1"></i>État des tables
            </button>
            <div id="msg-vidage" class="mt-1" style="font-size:.82rem;display:none;"></div>
        </div>
    </div>

    <!-- ── Section 2 : Épreuves ── -->
    <div id="sec-epreuves" class="section-box" style="display:none;">
        <div class="section-title">
            <i class="bi bi-list-ul"></i>
            Épreuves (type Équipes)
            <span id="lbl-nb-epreuves" class="text-muted fw-normal ms-1" style="font-size:.82rem;"></span>
        </div>

        <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
            <input type="search" id="filtre-epreuves" class="form-control form-control-sm"
                   placeholder="Filtrer par nom…" style="max-width:280px;">
            <button class="btn btn-sm btn-success" id="btn-tout-cocher">
                <i class="bi bi-check2-all me-1"></i>Tout sélectionner
            </button>
            <button class="btn btn-sm btn-outline-secondary" id="btn-tout-decocher">
                <i class="bi bi-x-lg me-1"></i>Tout désélectionner
            </button>
        </div>

        <div style="max-height:380px;overflow-y:auto;border:1px solid #e0e8f0;border-radius:6px;padding:.5rem .6rem;">
            <div id="liste-epreuves"></div>
        </div>

        <div class="mt-3 d-flex align-items-center gap-3 flex-wrap">
            <button id="btn-importer" class="btn btn-success" disabled>
                <i class="bi bi-cloud-upload me-1"></i>Importer les rencontres des épreuves cochées
                <span id="spinner-div" class="spinner-border spinner-border-sm ms-2 d-none"></span>
            </button>
            <span class="text-muted" style="font-size:.82rem;">(Les doublons seront ignorés)</span>
        </div>
    </div>

    <!-- ── Section 4 : Progression import ── -->
    <div id="sec-progression" class="section-box" style="display:none;">
        <div class="section-title"><i class="bi bi-arrow-repeat"></i>Progression de l'import</div>
        <div id="liste-progression"></div>
        <div id="bilan-import" class="mt-3" style="display:none;">
            <hr>
            <div class="section-title"><i class="bi bi-bar-chart-fill"></i>Bilan</div>
            <div id="bilan-stats"></div>
        </div>
    </div>

    <!-- ── Section : Rencontres déjà en base ── -->
    <div class="section-box mt-3">
        <div class="section-title d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar3"></i>&nbsp;Rencontres déjà en base</span>
            <span id="lbl-nb-renc" class="text-muted fw-normal" style="font-size:.82rem;"></span>
        </div>

        <div class="d-flex gap-2 mb-2 flex-wrap align-items-center">
            <input type="search" id="filtre-renc" class="form-control form-control-sm"
                   placeholder="Filtrer…" style="max-width:240px;">
            <select id="filtre-div" class="form-select form-select-sm" style="max-width:160px;">
                <option value="">Toutes les divisions</option>
            </select>
            <button id="btn-refresh-renc" class="btn btn-sm btn-outline-secondary ms-auto">
                <i class="bi bi-arrow-clockwise me-1"></i>Actualiser
            </button>
        </div>

        <div style="max-height:65vh;overflow-y:auto;border:1px solid #e0e8f0;border-radius:6px;">
            <table id="tbl-renc" class="table table-sm table-hover table-striped mb-0 align-middle" style="font-size:.83rem;">
                <thead class="table-dark sticky-top" style="top:0;z-index:1;">
                    <tr>
                        <th class="sort-col" data-col="Date" style="cursor:pointer;white-space:nowrap;">Date <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="Heure" style="cursor:pointer;">H <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="Journee" style="cursor:pointer;">J <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="Poule" style="cursor:pointer;">P <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="Phase" style="cursor:pointer;">Ph <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="DivisionCode" style="cursor:pointer;">Division <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="NomDom" style="cursor:pointer;">Domicile <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="sort-col" data-col="NomExt" style="cursor:pointer;">Extérieur <i class="bi bi-arrow-down-up sort-icon"></i></th>
                        <th class="text-center">Arb.</th>
                        <th class="text-center">Nom.</th>
                    </tr>
                </thead>
                <tbody id="tbody-renc">
                    <tr><td colspan="10" class="text-center text-muted py-3">
                        <span class="spinner-border spinner-border-sm me-2"></span>Chargement…
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- #content -->

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script src="asset/js/nijac-toast.js"></script>
<script>
'use strict';

/* ── Données PHP → JS ─────────────────────────────────────────────────────── */
const DIVISIONS_NIJAC = <?= json_encode($divsNijac, JSON_UNESCAPED_UNICODE) ?>;
const PHASE_OPTIONS   = <?= json_encode($phaseOptions, JSON_UNESCAPED_UNICODE) ?>;
let   phaseKey        = <?= json_encode($phaseKey,    JSON_UNESCAPED_UNICODE) ?>;
let   phaseLabel      = <?= json_encode($phaseLabel,  JSON_UNESCAPED_UNICODE) ?>;

/* ── Utilitaires ──────────────────────────────────────────────────────────── */
function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function spin(id, show) { $(`#${id}`).toggleClass('d-none', !show); }

/* ── État global ──────────────────────────────────────────────────────────── */
let ligueId   = null;
let epreuves  = [];     // [{idepreuve, intitule, typepreuve, …}]

/* ── Carte ligue (avec sélecteur de phase) ────────────────────────────────── */
let ligueInfo = null;

function renderLigueCard(ligue) {
    ligueInfo = ligue;
    const btns = PHASE_OPTIONS.map(o => `
        <button class="btn btn-sm btn-phase ${o.key === phaseKey ? 'btn-primary' : 'btn-outline-secondary'}"
                data-key="${o.key}" data-label="${o.label}">
            ${o.label}
        </button>`).join('');

    $('#ligue-card').html(`
        <div class="ligue-card flex-column align-items-start gap-2">
            <div class="d-flex align-items-center gap-3">
                <div class="ligue-id">${esc(ligue.id)}</div>
                <div>
                    <div class="ligue-nom">
                        <i class="bi bi-check-circle-fill text-success me-1"></i>${esc(ligue.libelle)}
                    </div>
                    <div class="ligue-sub">Identifiant FFTT : <code>${esc(ligue.id)}</code></div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted" style="font-size:.8rem;white-space:nowrap;">Phase :</span>
                <div class="btn-group btn-group-sm" role="group">${btns}</div>
            </div>
        </div>`);
}

/**
 * Retourne true si une division FFTT correspond à la phase sélectionnée.
 * Phase 2 : libellé contient "ph2", "phase 2", "_p2" ou "finale".
 * Phase 1 / Préparation : tout ce qui n'est pas Phase 2.
 */
function divisionMatchPhase(libelle, key) {
    const isP2 = /ph\s*2|phase\s*2|_p2\b|finale/i.test(libelle);
    return key === 'p2' ? isP2 : !isP2;
}

$(document).on('click', '.btn-phase', function () {
    phaseKey   = $(this).data('key');
    phaseLabel = $(this).data('label');
    $('.btn-phase').removeClass('btn-primary').addClass('btn-outline-secondary');
    $(this).removeClass('btn-outline-secondary').addClass('btn-primary');

    // Mettre à jour les coches et couleurs des divisions selon la nouvelle phase
    $('.chk-division').each(function () {
        const idx     = +$(this).attr('data-idx');
        const libelle = divisions[idx]?.libelle ?? '';
        const cochee  = divisionMatchPhase(libelle, phaseKey);
        $(this).prop('checked', cochee);
        $(this).closest('tr').toggleClass('div-selected', cochee);
    });
    majBtnImporter();
});

/* ═══════════════════════════════════════════════════════════════════════════
   ÉTAPE 1 — Recherche ligue
   ═══════════════════════════════════════════════════════════════════════════ */
$('#btn-fftt').on('click', function () {
    $(this).prop('disabled', true);
    $('#spinner-fftt').addClass('show');
    ['sec-ligue','sec-epreuves','sec-divisions','sec-progression'].forEach(id => $(`#${id}`).hide());

    $.post('import_rencontres.php', { action: 'chercher_ligue' }, function (r) {
        $('#spinner-fftt').removeClass('show');
        $('#btn-fftt').prop('disabled', false);

        if (!r.ok) {
            let html = `<div class="alert alert-danger mt-3">
                <i class="bi bi-x-circle-fill me-2"></i><strong>Ligue non trouvée.</strong> ${esc(r.msg)}`;
            if (r.ligues?.length) {
                html += `<details class="mt-2"><summary style="cursor:pointer;font-size:.83rem;">
                    ${r.ligues.length} ligues disponibles</summary>
                    <ul class="mb-0 mt-1" style="font-size:.82rem;">`;
                r.ligues.forEach(l => html += `<li>${esc(l)}</li>`);
                html += '</ul></details>';
            }
            html += '</div>';
            $('#sec-ligue').html(html).show();
            return;
        }

        ligueId = r.ligue.id;

        renderLigueCard(r.ligue);
        $('#sec-ligue').show();

        chargerEpreuves();
    }, 'json').fail(function () {
        $('#spinner-fftt').removeClass('show');
        $('#btn-fftt').prop('disabled', false);
        nijacToast('Erreur réseau lors de la recherche de la ligue.', 'danger');
    });
});

/* ═══════════════════════════════════════════════════════════════════════════
   ÉTAPE 2 — Épreuves
   ═══════════════════════════════════════════════════════════════════════════ */
function chargerEpreuves() {
    $('#sec-epreuves').show();
    $('#liste-epreuves').html('<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Chargement des épreuves…</span>');
    $('#btn-charger-divisions').prop('disabled', true);

    $.post('import_rencontres.php', { action: 'charger_epreuves', organisme: ligueId }, function (r) {
        if (!r.ok) {
            $('#liste-epreuves').html(`<div class="text-danger">${esc(r.msg)}</div>`);
            return;
        }
        epreuves = r.epreuves ?? [];
        $('#lbl-nb-epreuves').text(`(${epreuves.length} épreuve(s))`);
        renderEpreuves();
        majBtnDivisions();
    }, 'json').fail(function () {
        $('#liste-epreuves').html('<div class="text-danger">Erreur réseau.</div>');
    });
}

/** Retourne true si l'épreuve doit être cochée par défaut. */
function epreuveParDefaut(intitule) {
    const s = intitule.toUpperCase();
    return s.startsWith('FED_') && !s.includes('ANTILLES') && !s.includes('GUYANE');
}

function renderEpreuves() {
    const filtre = $('#filtre-epreuves').val().trim().toLowerCase();
    const $list  = $('#liste-epreuves').empty();
    let nb = 0;

    epreuves.forEach((ep, i) => {
        const intitule = (ep.intitule ?? ep.libelle ?? '');
        if (filtre && !intitule.toLowerCase().includes(filtre)) return;
        nb++;
        const cochee = epreuveParDefaut(intitule);
        const id = `chk-ep-${i}`;
        $list.append(`
            <div class="epreuve-item">
                <input type="checkbox" class="chk-epreuve form-check-input flex-shrink-0"
                       id="${id}" data-idx="${i}" ${cochee ? 'checked' : ''} style="margin-top:.15rem;">
                <label for="${id}">
                    <span class="intitule">${esc(intitule)}</span>
                    <span class="ep-id">(${esc(ep.idepreuve ?? '')})</span>
                </label>
            </div>`);
    });

    if (!nb) $list.html('<div class="text-muted py-2 px-1">Aucune épreuve correspondante.</div>');
}

$('#filtre-epreuves').on('input', function () { renderEpreuves(); majBtnImportEpreuves(); });

$('#btn-tout-cocher').on('click',   function () { $('.chk-epreuve').prop('checked', true);  majBtnImportEpreuves(); });
$('#btn-tout-decocher').on('click', function () { $('.chk-epreuve').prop('checked', false); majBtnImportEpreuves(); });

$(document).on('change', '.chk-epreuve', majBtnImportEpreuves);

function majBtnImportEpreuves() {
    const nb = $('.chk-epreuve:checked').length;
    $('#btn-importer').prop('disabled', nb === 0).text(
        nb > 0
            ? `Importer les rencontres des ${nb} épreuve(s) cochée(s)`
            : 'Importer les rencontres des épreuves cochées'
    );
    $('#btn-importer').append(
        '<span id="spinner-div" class="spinner-border spinner-border-sm ms-2 d-none"></span>'
    );
}

/* ═══════════════════════════════════════════════════════════════════════════
   ÉTAPE 3 — Import direct (épreuves → divisions auto → poules → rencontres)
   ═══════════════════════════════════════════════════════════════════════════ */
function nijacConfirmPromise(msg) {
    return new Promise(resolve => nijacConfirm(msg, () => resolve(true), () => resolve(false)));
}

$('#btn-importer').on('click', async function () {
    const checkedIdx = $('.chk-epreuve:checked').map(function () {
        return +$(this).attr('data-idx');
    }).get();
    if (!checkedIdx.length) return;

    const conf = await nijacConfirmPromise(
        `Importer les rencontres de ${checkedIdx.length} épreuve(s) ?\n(Les doublons seront ignorés.)`
    );
    if (!conf) return;

    $(this).prop('disabled', true);
    spin('spinner-div', true);
    $('#sec-progression').show();
    $('#liste-progression').empty();
    $('#bilan-import').hide();

    const bilanTotal = { equipes: 0, rencontres: 0, doublons: 0, erreurs: 0 };

    const phaseNum = phaseKey === 'p2' ? 2 : 1;

    for (const idx of checkedIdx) {
        const ep         = epreuves[idx];
        const idEp       = (ep.idepreuve ?? ep.ident ?? '');
        const intituleEp = (ep.intitule  ?? ep.libelle ?? '');

        // ── 1. Divisions ──────────────────────────────────────────────────────
        let divs;
        try {
            const r = await $.post('import_rencontres.php', {
                action: 'charger_divisions', organisme: ligueId, epreuve: idEp,
            });
            if (!r.ok || !r.divisions?.length) {
                ajouterLigneProgression(null, intituleEp, false, `Divisions introuvables : ${r.msg ?? ''}`);
                bilanTotal.erreurs++;
                continue;
            }
            divs = r.divisions;
        } catch (e) {
            ajouterLigneProgression(null, intituleEp, false, 'Erreur réseau (divisions)');
            bilanTotal.erreurs++;
            continue;
        }

        for (const div of divs) {
            const libelle    = (div.libelle    ?? '');
            const idDivFftt  = (div.iddivision ?? div.ident ?? '');
            const idDivNijac = div.id_division_nijac_auto ?? null;

            if (!divisionMatchPhase(libelle, phaseKey)) continue;
            if (!idDivNijac) {
                ajouterLigneProgression(libelle, intituleEp, false, 'Division non mappée (ignorée)');
                continue;
            }

            const rowDiv = ajouterLigneProgression(libelle, intituleEp, null, 'Import en cours…');

            try {
                const rr = await $.post('import_rencontres.php', {
                    action:        'importer_division',
                    organisme:     ligueId,
                    epreuve:       idEp,
                    division_fftt: idDivFftt,
                    id_division:   idDivNijac,
                    phase:         phaseNum,
                });

                if (rr.ok) {
                    const s = rr.stats;
                    bilanTotal.equipes    += s.equipes_creees    ?? 0;
                    bilanTotal.rencontres += s.rencontres_creees ?? 0;
                    bilanTotal.doublons   += s.doublons          ?? 0;
                    bilanTotal.erreurs    += s.erreurs?.length   ?? 0;

                    let resume = `<span class="dp-ok">${s.rencontres_creees} renc. créées</span>`;
                    if (s.poules)          resume += ` · ${s.poules} poule(s)`;
                    if (s.doublons)        resume += ` · <span class="text-secondary">${s.doublons} doublon(s)</span>`;
                    if (s.equipes_creees)  resume += ` · ${s.equipes_creees} éq. créées`;
                    if (s.erreurs?.length) resume += ` · <span class="dp-err">${s.erreurs.length} err.</span>`;
                    mettreAJourLigne(rowDiv, s.erreurs?.length === 0, resume);

                    // Détail des opérations
                    if (s.log?.length) {
                        const icons = {rencontre:'bi-calendar-check text-success', equipe:'bi-people-fill text-primary',
                                       club:'bi-building text-info', doublon:'bi-skip-forward text-secondary',
                                       nationale:'bi-trophy-fill text-warning', erreur:'bi-x-circle text-danger'};
                        const rows = s.log.map(l => {
                            const ic = icons[l.type] ?? 'bi-dot';
                            return `<div style="display:flex;gap:.4rem;align-items:baseline;">
                                <i class="bi ${ic}" style="font-size:.7rem;flex-shrink:0;margin-top:.15rem;"></i>
                                <span><strong>${esc(l.op)}</strong> ${esc(l.val)}</span>
                            </div>`;
                        }).join('');
                        $(`#${rowDiv}`).after(`
                            <div class="log-detail" style="font-size:.75rem;padding:.3rem .5rem .3rem 2rem;color:#374151;background:#f9fafb;border-left:3px solid #e5e7eb;margin-bottom:.2rem;">
                                ${rows}
                            </div>`);
                    }
                } else {
                    mettreAJourLigne(rowDiv, false, esc(rr.msg));
                    bilanTotal.erreurs++;
                }
            } catch (e) {
                mettreAJourLigne(rowDiv, false, 'Erreur réseau');
                bilanTotal.erreurs++;
            }
        }
    }

    spin('spinner-div', false);

    // Bilan final
    $('#bilan-stats').html(`
        <div class="stat-card"><div class="sv text-success">${bilanTotal.rencontres}</div><div class="sl">Rencontres créées</div></div>
        <div class="stat-card"><div class="sv text-primary">${bilanTotal.equipes}</div><div class="sl">Équipes créées</div></div>
        <div class="stat-card"><div class="sv text-secondary">${bilanTotal.doublons}</div><div class="sl">Doublons ignorés</div></div>
        <div class="stat-card"><div class="sv ${bilanTotal.erreurs ? 'text-danger' : 'text-success'}">${bilanTotal.erreurs}</div><div class="sl">Erreurs</div></div>
        <div class="alert alert-${bilanTotal.erreurs ? 'warning' : 'success'} mt-2 py-2">
            <i class="bi bi-${bilanTotal.erreurs ? 'exclamation-triangle' : 'check-circle'}-fill me-1"></i>
            Import terminé${bilanTotal.erreurs ? ' avec des erreurs.' : ' sans erreur.'}
        </div>
    `);
    $('#bilan-import').show();
    $('#btn-importer').prop('disabled', false);
    majBtnImportEpreuves();
});

/** Ajoute une ligne dans la section progression et retourne son ID. */
function ajouterLigneProgression(libelle, epIntitule, ok, msg) {
    const rowId = 'dp-' + Math.random().toString(36).slice(2);

    const nom = libelle
        ? `${esc(libelle)} <span class="text-muted fw-normal" style="font-size:.78rem;">(${esc(epIntitule)})</span>`
        : `<span class="text-muted">${esc(epIntitule)}</span>`;

    $('#liste-progression').append(`
        <div class="div-progress" id="${rowId}">
            ${iconeOk(ok)}
            <span class="dp-nom">${nom}</span>
            <span class="dp-stats">${esc(msg)}</span>
        </div>`);
    $('html,body').animate({ scrollTop: $(`#${rowId}`).offset().top - 20 }, 150);
    return rowId;
}

function iconeOk(ok) {
    if (ok === null) return '<span class="spinner-border spinner-border-sm text-primary dp-icone flex-shrink-0"></span>';
    return ok
        ? '<i class="bi bi-check-circle-fill text-success dp-icone flex-shrink-0"></i>'
        : '<i class="bi bi-x-circle-fill text-danger dp-icone flex-shrink-0"></i>';
}

function mettreAJourLigne(rowId, ok, statsHtml) {
    const $row = $(`#${rowId}`);
    $row.find('.dp-icone, .spinner-border').replaceWith(iconeOk(ok));
    $row.find('.dp-stats').html(statsHtml);
}

function ajouterSousLigne(parentId, label, ok, msg) {
    const rowId = 'dp-' + Math.random().toString(36).slice(2);
    $(`#${parentId}`).after(`
        <div class="div-progress div-progress-sub" id="${rowId}" style="padding-left:2rem;font-size:.82rem;opacity:.9;">
            ${iconeOk(ok)}
            <span class="dp-nom">${label}</span>
            <span class="dp-stats">${esc(msg)}</span>
        </div>`);
    return rowId;
}

/* ── Vider les tables ─────────────────────────────────────────────────────── */
function rafraichirEtatTables(afficherMsg) {
    const $btn = $('#btn-vider');
    if (afficherMsg) $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Chargement…');

    $.post('import_rencontres.php', { action: 'compter_tables' }, function (r) {
        $btn.prop('disabled', false).html('<i class="bi bi-table me-1"></i>État des tables');
        $btn.removeClass('btn-secondary btn-success btn-warning text-white text-dark');

        if (!r.ok) {
            $btn.addClass('btn-secondary text-white');
            if (afficherMsg) $('#msg-vidage').stop(true).show().html(`<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Erreur réseau</span>`);
            return;
        }

        const counts = r.counts;
        const totalNonVide = Object.values(counts).reduce((a, b) => a + b, 0);

        if (totalNonVide > 0) {
            $btn.addClass('btn-warning text-dark');
        } else {
            $btn.addClass('btn-success text-white');
        }

        if (!afficherMsg) return;

        const $msg = $('#msg-vidage').stop(true).show();

        let rows = Object.entries(counts).map(([t, n]) => {
            const cls = n > 0 ? 'text-warning fw-semibold' : 'text-success';
            return `<tr><td class="pe-3">${esc(t)}</td><td class="${cls} text-end">${n.toLocaleString('fr-FR')} enr.</td></tr>`;
        }).join('');

        let html = `<table class="mb-2" style="font-size:.82rem;">${rows}</table>`;

        if (totalNonVide > 0) {
            html += `<div class="alert alert-warning py-1 px-2 mb-0" style="font-size:.82rem;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong>Tables non vides.</strong> Avant le premier import de la saison, utilisez
                <a href="clean.php" target="_blank" class="alert-link">Administrateur → Nouvelle saison (E016)</a>
                pour vider et sauvegarder ces tables.
            </div>`;
        } else {
            html += `<div class="text-success" style="font-size:.82rem;">
                <i class="bi bi-check-circle-fill me-1"></i>Toutes les tables sont vides — vous pouvez importer.
            </div>`;
        }

        $msg.html(html);
    }, 'json').fail(function () {
        $('#btn-vider').prop('disabled', false).html('<i class="bi bi-table me-1"></i>État des tables')
            .removeClass('btn-secondary btn-success btn-warning text-white text-dark').addClass('btn-secondary text-white');
        if (afficherMsg) $('#msg-vidage').show().html(`<span class="text-danger fw-semibold">
            <i class="bi bi-x-circle-fill me-1"></i>Erreur réseau — vérifiez la console
        </span>`);
    });
}

$('#btn-vider').on('click', function () { rafraichirEtatTables(true); });

// Couleur au chargement de la page
rafraichirEtatTables(false);

/* ── Liste des rencontres en base ─────────────────────────────────────────── */
let toutesRencontres = [];

function chargerListeRencontres() {
    $('#tbody-renc').html('<tr><td colspan="10" class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span>Chargement…</td></tr>');

    $.post('import_rencontres.php', { action: 'liste_rencontres' }, function (r) {
        if (!r.ok) {
            $('#tbody-renc').html(`<tr><td colspan="10" class="text-danger p-2">${esc(r.msg)}</td></tr>`);
            return;
        }
        toutesRencontres = r.rencontres ?? [];
        $('#lbl-nb-renc').text(`(${r.total} rencontre(s))`);

        // Alimenter le filtre division
        const divs = [...new Set(toutesRencontres.map(rc => rc.DivisionCode))].sort();
        const $sel = $('#filtre-div').empty().append('<option value="">Toutes les divisions</option>');
        divs.forEach(d => $sel.append(`<option value="${esc(d)}">${esc(d)}</option>`));

        renderListeRencontres();
    }, 'json').fail(function () {
        $('#tbody-renc').html('<tr><td colspan="10" class="text-danger p-2">Erreur réseau.</td></tr>');
    });
}

/* Calcule la luminosité d'une couleur hex et retourne '#fff' ou '#111' selon le contraste */
function textColorFor(hex) {
    const c = hex.replace('#', '');
    const r = parseInt(c.substring(0,2), 16);
    const g = parseInt(c.substring(2,4), 16);
    const b = parseInt(c.substring(4,6), 16);
    const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return lum > 0.55 ? '#111' : '#fff';
}

let sortCol = 'Date', sortAsc = true;

function renderListeRencontres() {
    const filtre  = $('#filtre-renc').val().trim().toLowerCase();
    const filtDiv = $('#filtre-div').val();

    let lignes = toutesRencontres.filter(rc => {
        if (filtDiv && rc.DivisionCode !== filtDiv) return false;
        if (filtre && !`${rc.NomDom} ${rc.NomExt} ${rc.DivisionCode}`.toLowerCase().includes(filtre)) return false;
        return true;
    });

    // Tri
    lignes.sort((a, b) => {
        const va = String(a[sortCol] ?? '');
        const vb = String(b[sortCol] ?? '');
        return sortAsc ? va.localeCompare(vb) : vb.localeCompare(va);
    });

    // Icônes tri dans l'en-tête
    $('.sort-col .sort-icon').removeClass('bi-arrow-up bi-arrow-down').addClass('bi-arrow-down-up');
    $(`.sort-col[data-col="${sortCol}"] .sort-icon`)
        .removeClass('bi-arrow-down-up')
        .addClass(sortAsc ? 'bi-arrow-down' : 'bi-arrow-up');

    if (!lignes.length) {
        $('#tbody-renc').html('<tr><td colspan="10" class="text-center text-muted py-3">Aucune rencontre.</td></tr>');
        return;
    }

    const rows = lignes.map(rc => {
        const date  = rc.Date ? rc.Date.substring(0, 10).split('-').reverse().join('/') : '—';
        const heure = (rc.Heure ?? '').substring(0, 5) || '—';
        const bg    = rc.DivisionColor && /^#[0-9a-fA-F]{6}$/.test(rc.DivisionColor)
                      ? rc.DivisionColor : '#1a3a6b';
        const fg    = textColorFor(bg);
        const arb   = rc.ArbitrageObligatoire == 1
            ? '<i class="bi bi-check-circle-fill text-success"></i>'
            : '<span class="text-muted">—</span>';
        const nom   = rc.NbNominations > 0
            ? `<span class="badge bg-success">${rc.NbNominations}</span>`
            : '<span class="text-muted">—</span>';
        return `<tr>
            <td class="fw-semibold">${esc(date)}</td>
            <td>${esc(heure)}</td>
            <td>${esc(rc.Journee ?? '—')}</td>
            <td>${esc(rc.Poule ?? '—')}</td>
            <td>${esc(rc.Phase ?? '—')}</td>
            <td><span class="badge" style="background:${bg};color:${fg}">${esc(rc.DivisionCode)}</span></td>
            <td>${esc(rc.NomDom ?? '—')}</td>
            <td>${esc(rc.NomExt ?? '—')}</td>
            <td class="text-center">${arb}</td>
            <td class="text-center">${nom}</td>
        </tr>`;
    }).join('');

    $('#tbody-renc').html(rows);
}

$(document).on('click', '.sort-col', function () {
    const col = $(this).data('col');
    if (col === sortCol) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = true; }
    renderListeRencontres();
});

$('#filtre-renc, #filtre-div').on('input change', renderListeRencontres);
$('#btn-refresh-renc').on('click', chargerListeRencontres);

// Chargement initial
chargerListeRencontres();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
