<?php
/**
 * NIJAC – Comptabilité frais JA (E025)
 *
 * Récapitulatif des frais de déplacement des JA pour une période donnée.
 * Export CSV au format EBP (journal AC).
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-20
 */
session_start();

if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../index.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../config/csrf.php';

$u           = $_SESSION['utilisateur'];
$isAdmin     = !empty($u['is_admin']);
$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        $dateDebut = trim($_POST['date_debut'] ?? $_GET['date_debut'] ?? '');
        $dateFin   = trim($_POST['date_fin']   ?? $_GET['date_fin']   ?? '');
        if (!$dateDebut || !$dateFin) {
            echo json_encode(['ok' => false, 'msg' => 'Dates obligatoires.']);
            exit;
        }

        $tauxKm   = (float)getConfig('frais_kilometrique', '0.30');
        $indemForf = (float)getConfig('indemnite_forfaitaire', '25.00');

        // ── Données par nomination (une ligne par rencontre) ────────────────
        if ($action === 'donnees') {
            $stmt = $pdo->prepare("
                SELECT
                    j.Id_JA,
                    j.Nom,
                    j.Prenom,
                    j.Defiscalisation,
                    r.Date                                                        AS DateRencontre,
                    COALESCE(n.Peage, 0)                                          AS Peage,
                    COALESCE(n.Kilometre, 0)                                      AS Kilometre,
                    ROUND((COALESCE(n.Kilometre, 0)) * :taux
                          + COALESCE(n.Peage, 0), 2)                             AS FraisKmPeages,
                    :indem                                                        AS Prestations
                FROM nomination n
                JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                JOIN ja j        ON j.Id_JA        = n.Id_JA
                WHERE (n.Valide = 1 OR n.Peage IS NOT NULL OR n.Kilometre IS NOT NULL)
                  AND r.Date BETWEEN :debut AND :fin
                ORDER BY j.Nom, j.Prenom, r.Date
            ");
            $stmt->execute([
                ':taux'  => $tauxKm,
                ':indem' => $indemForf,
                ':debut' => $dateDebut,
                ':fin'   => $dateFin,
            ]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            foreach ($rows as &$r) {
                $r['Total'] = round((float)$r['FraisKmPeages'] + (float)$r['Prestations'], 2);
            }
            unset($r);

            echo json_encode(['ok' => true, 'data' => $rows, 'taux_km' => $tauxKm, 'indem' => $indemForf]);
            exit;
        }

        // ── Export CSV EBP ───────────────────────────────────────────────────
        if ($action === 'export_csv') {
            $codeAnalytique = getConfig('code_analytique_compta', '04EPR232');
            $cpte62511      = getConfig('compte_frais_km',         '62511');
            $cpte62261      = getConfig('compte_prestations',      '62261');

            $stmt = $pdo->prepare("
                SELECT
                    j.Id_JA,
                    j.Nom,
                    j.Prenom,
                    j.NumCompteEBP,
                    ROUND(SUM(COALESCE(n.Kilometre, 0)) * :taux
                          + SUM(COALESCE(n.Peage, 0)), 2)  AS FraisKmPeages,
                    ROUND(COUNT(n.Id_Nomination) * :indem, 2) AS Prestations
                FROM nomination n
                JOIN rencontre r ON r.Id_Rencontre = n.Id_Rencontre
                JOIN ja j        ON j.Id_JA        = n.Id_JA
                WHERE (n.Valide = 1 OR n.Peage IS NOT NULL OR n.Kilometre IS NOT NULL)
                  AND r.Date BETWEEN :debut AND :fin
                GROUP BY j.Id_JA, j.Nom, j.Prenom, j.NumCompteEBP
                HAVING (FraisKmPeages > 0 OR Prestations > 0)
                ORDER BY j.Nom, j.Prenom
            ");
            $stmt->execute([
                ':taux'  => $tauxKm,
                ':indem' => $indemForf,
                ':debut' => $dateDebut,
                ':fin'   => $dateFin,
            ]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $lignes = [];
            foreach ($rows as $r) {
                $libelle = strtoupper($r['Nom']) . ' ' . $r['Prenom'];
                $frais   = (float)$r['FraisKmPeages'];
                $prest   = (float)$r['Prestations'];
                $total   = round($frais + $prest, 2);
                $cpteJA  = $r['NumCompteEBP'] ?? '';

                if ($frais > 0) {
                    $lignes[] = implode(',', [
                        'AC', $dateFin, $cpte62511, 'D',
                        number_format($frais, 2, '.', ''),
                        'virement', $libelle, $codeAnalytique,
                    ]);
                }
                if ($prest > 0) {
                    $lignes[] = implode(',', [
                        'AC', $dateFin, $cpte62261, 'D',
                        number_format($prest, 2, '.', ''),
                        'virement', $libelle, $codeAnalytique,
                    ]);
                }
                if ($total > 0 && $cpteJA !== '') {
                    $lignes[] = implode(',', [
                        'AC', $dateFin, $cpteJA, 'C',
                        number_format($total, 2, '.', ''),
                        'virement', $libelle,
                    ]);
                }
                if ($total > 0) {
                    $lignes[] = ''; // ligne vide entre chaque JA
                }
            }

            echo json_encode(['ok' => true, 'csv' => implode("\n", $lignes)]);
            exit;
        }

    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
        exit;
    }
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Valeurs par défaut du filtre (saison courante) ───────────────────────────
try {
    $pdo = getPDO();
    try { initTableConfiguration($pdo); } catch (\Throwable $ignored) {}

    $saison      = getConfig('saison', '');
    $phase1Debut = getConfig('phase1_debut', '09-01');
    $phase1Fin   = getConfig('phase1_fin',   '01-31');
    $phase2Debut = getConfig('phase2_debut', '02-01');
    $phase2Fin   = getConfig('phase2_fin',   '06-30');

    // Calcule les dates absolues des phases à partir de la saison "2024-2025"
    if (preg_match('/^(\d{4})-(\d{4})$/', $saison, $m)) {
        $an1 = $m[1]; $an2 = $m[2];
        // Phase 1 : an1-MM-JJ → an2-MM-JJ (si mois fin < mois début, fin est an2)
        $p1DebMois = (int)explode('-', $phase1Debut)[0];
        $p1FinMois = (int)explode('-', $phase1Fin)[0];
        $p1DebAn   = $an1;
        $p1FinAn   = $p1FinMois < $p1DebMois ? $an2 : $an1;
        // Phase 2 : toujours dans an2
        $p2DebAn   = $an2;
        $p2FinAn   = $an2;

        $dateP1Debut = "$p1DebAn-$phase1Debut";
        $dateP1Fin   = "$p1FinAn-$phase1Fin";
        $dateP2Debut = "$p2DebAn-$phase2Debut";
        $dateP2Fin   = "$p2FinAn-$phase2Fin";
        $defaultDebut = $dateP1Debut;
        $defaultFin   = $dateP1Fin;
    } else {
        // Septembre ou plus → saison qui commence cette année
        // Janvier-Août → saison qui a commencé l'année précédente
        $an1 = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
        $an2 = $an1 + 1;
        $dateP1Debut = "$an1-$phase1Debut";
        $dateP1Fin   = "$an2-$phase1Fin";
        $dateP2Debut = "$an2-$phase2Debut";
        $dateP2Fin   = "$an2-$phase2Fin";
        $defaultDebut = $dateP1Debut;
        $defaultFin   = $dateP1Fin;
    }
} catch (\Throwable $e) {
    $an1 = (int)date('n') >= 9 ? (int)date('Y') : (int)date('Y') - 1;
    $an2 = $an1 + 1;
    $saison      = '';
    $dateP1Debut = "$an1-09-01";
    $dateP1Fin   = "$an2-01-31";
    $dateP2Debut = "$an2-02-01";
    $dateP2Fin   = "$an2-06-30";
    $defaultDebut = $dateP1Debut;
    $defaultFin   = $dateP1Fin;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Comptabilité frais JA (E025)</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        #page-header {
            background: #2e7d32;
            color: #fff;
            padding: .65rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
        }

        /* ── Toolbar ── */
        #toolbar {
            background: #fff;
            border-bottom: 1px solid #dde3ed;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        #toolbar label { font-size: .82rem; font-weight: 600; color: #444; margin-bottom: 0; }
        #toolbar input[type=date] {
            font-size: .82rem;
            padding: .28rem .5rem;
            border: 1px solid #cdd3de;
            border-radius: 6px;
            background: #f8faff;
        }

        .btn-nijac {
            background: var(--nijac-blue);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: .82rem;
            font-weight: 600;
            padding: .32rem .9rem;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            cursor: pointer;
            transition: opacity .15s;
        }
        .btn-nijac:hover { opacity: .85; }
        .btn-nijac:disabled { opacity: .5; cursor: not-allowed; }

        .btn-phase {
            background: #e8f0fe;
            color: #1a3a6b;
            border: 1px solid #b3c6f0;
            border-radius: 6px;
            font-size: .82rem;
            font-weight: 600;
            padding: .32rem .9rem;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-phase:hover { background: #d0e0fb; }
        .btn-phase.active { background: #1a3a6b; color: #fff; border-color: #1a3a6b; }

        .btn-export {
            background: #1a7f4b;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: .82rem;
            font-weight: 600;
            padding: .32rem .9rem;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            cursor: pointer;
            transition: opacity .15s;
            margin-left: auto;
        }
        .btn-export:hover { opacity: .85; }
        .btn-export:disabled { opacity: .5; cursor: not-allowed; }

        /* ── Contenu principal ── */
        #main-content {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
        }

        /* ── Résumé totaux ── */
        #resume-totaux {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .resume-card {
            background: #fff;
            border-radius: 8px;
            padding: .7rem 1.2rem;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            border-left: 4px solid #ccc;
            min-width: 160px;
        }
        .resume-card .rc-label { font-size: .7rem; color: #6b7a90; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .resume-card .rc-value { font-size: 1.4rem; font-weight: 800; line-height: 1.1; margin-top: 2px; }
        .rc-blue   { border-color: #1a3a6b; }  .rc-blue   .rc-value { color: #1a3a6b; }
        .rc-orange { border-color: #e65100; }  .rc-orange .rc-value { color: #e65100; }
        .rc-green  { border-color: #1a7f4b; }  .rc-green  .rc-value { color: #1a7f4b; }
        .rc-purple { border-color: #6f42c1; }  .rc-purple .rc-value { color: #6f42c1; }

        /* ── Tableau ── */
        #wrap-table {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,.09);
            overflow: hidden;
        }

        #tbl-frais {
            width: 100%;
            border-collapse: collapse;
            font-size: .83rem;
        }

        #tbl-frais thead th {
            background: #1a3a6b;
            color: #fff;
            font-weight: 600;
            padding: .5rem .75rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        #tbl-frais tbody tr:hover { background: #f0f4fa; }
        #tbl-frais tbody tr:nth-child(even) { background: #f8faff; }
        #tbl-frais tbody tr:nth-child(even):hover { background: #e8edf8; }

        #tbl-frais td {
            padding: .38rem .75rem;
            border-bottom: 1px solid #e8edf5;
            vertical-align: middle;
        }

        .col-money { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
        .col-center { text-align: center; }
        .col-warn { color: #b45309; font-style: italic; font-size: .78rem; }

        /* Ligne pied de tableau */
        #tbl-frais tfoot td {
            background: #1a3a6b;
            color: #fff;
            font-weight: 700;
            padding: .45rem .75rem;
        }

        /* Placeholder vide */
        #empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #888;
            font-size: .9rem;
        }

        /* Toast */
        #toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 9999; }
        .toast-msg {
            background: #1a3a6b;
            color: #fff;
            border-radius: 8px;
            padding: .6rem 1rem;
            font-size: .84rem;
            margin-top: .4rem;
            box-shadow: 0 2px 8px rgba(0,0,0,.18);
            animation: fadeIn .2s ease;
        }
        .toast-msg.toast-err { background: #c0392b; }
        @keyframes fadeIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: none; } }

        /* Spinner */
        #spinner { display: none; }
        #spinner.active { display: inline-block; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-calculator-fill'; $pageTitle = 'Comptabilité frais JA'; $pageCode = 'E025'; $backUrl = 'menu.php'; require __DIR__ . '/../includes/page_header.php'; ?>

<?php require __DIR__ . '/../includes/toolbar.php'; ?>

<!-- ── Toolbar ── -->
<div id="toolbar">
    <label for="inp-debut">Période du</label>
    <input type="date" id="inp-debut" value="<?= htmlspecialchars($defaultDebut) ?>">

    <label for="inp-fin">au</label>
    <input type="date" id="inp-fin" value="<?= htmlspecialchars($defaultFin) ?>">

    <button class="btn-phase" id="btn-phase1" data-debut="<?= htmlspecialchars($dateP1Debut) ?>" data-fin="<?= htmlspecialchars($dateP1Fin) ?>">
        <i class="bi bi-1-circle"></i>Phase 1
    </button>
    <button class="btn-phase" id="btn-phase2" data-debut="<?= htmlspecialchars($dateP2Debut) ?>" data-fin="<?= htmlspecialchars($dateP2Fin) ?>">
        <i class="bi bi-2-circle"></i>Phase 2
    </button>

    <button class="btn-nijac" id="btn-charger">
        <i class="bi bi-search"></i>Charger
    </button>

    <span id="spinner" class="spinner-border spinner-border-sm text-secondary ms-1" role="status"></span>

    <button class="btn-export" id="btn-export" disabled>
        <i class="bi bi-download"></i>Export EBP (CSV)
    </button>
</div>

<!-- ── Contenu ── -->
<div id="main-content">

    <!-- Résumé -->
    <div id="resume-totaux" style="display:none;">
        <div class="resume-card rc-blue">
            <div class="rc-label">JA concernés</div>
            <div class="rc-value" id="rc-nb-ja">–</div>
            <div style="font-size:.7rem;color:#888;" id="rc-nb-missions"></div>
        </div>
        <div class="resume-card rc-orange">
            <div class="rc-label">Frais km + péages</div>
            <div class="rc-value" id="rc-frais">–</div>
        </div>
        <div class="resume-card rc-purple">
            <div class="rc-label">Prestations</div>
            <div class="rc-value" id="rc-prest">–</div>
        </div>
        <div class="resume-card rc-green">
            <div class="rc-label">Total général</div>
            <div class="rc-value" id="rc-total">–</div>
        </div>
    </div>

    <!-- Tableau -->
    <div id="wrap-table">
        <div id="empty-state">
            <i class="bi bi-search" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
            Sélectionnez une période et cliquez sur <strong>Charger</strong>
        </div>
        <table id="tbl-frais" style="display:none;">
            <thead>
                <tr>
                    <th>JA</th>
                    <th class="col-center">Date rencontre</th>
                    <th class="col-money">Frais km + péages</th>
                    <th class="col-center">Défiscalisation</th>
                    <th class="col-money">Prestations</th>
                    <th class="col-money">Total</th>
                </tr>
            </thead>
            <tbody id="tbody-frais"></tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Totaux</td>
                    <td class="col-money" id="foot-frais">–</td>
                    <td></td>
                    <td class="col-money" id="foot-prest">–</td>
                    <td class="col-money" id="foot-total">–</td>
                </tr>
            </tfoot>
        </table>
    </div>

</div>

<div id="toast-container"></div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/nijac-csrf.js"></script>
<script>
const URL_PAGE = 'compta.php';

// ── Boutons Phase 1 / Phase 2 ─────────────────────────────────────────────
$('.btn-phase').on('click', function () {
    const debut = $(this).data('debut');
    const fin   = $(this).data('fin');
    $('#inp-debut').val(debut);
    $('#inp-fin').val(fin);
    $('.btn-phase').removeClass('active');
    $(this).addClass('active');
});

function toast(msg, ok = true) {
    const $t = $('<div>').addClass('toast-msg' + (ok ? '' : ' toast-err')).text(msg);
    $('#toast-container').append($t);
    setTimeout(() => $t.fadeOut(300, () => $t.remove()), 3500);
}

function money(v) {
    return parseFloat(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

function spinner(on) {
    on ? $('#spinner').addClass('active') : $('#spinner').removeClass('active');
}

let donneesChargees = [];

// ── Charger ──────────────────────────────────────────────────────────────────
$('#btn-charger').on('click', function () {
    const debut = $('#inp-debut').val();
    const fin   = $('#inp-fin').val();
    if (!debut || !fin) { toast('Veuillez saisir les deux dates.', false); return; }
    if (debut > fin)    { toast('La date de début doit être antérieure à la date de fin.', false); return; }

    spinner(true);
    $(this).prop('disabled', true);
    $('#btn-export').prop('disabled', true);

    $.post(URL_PAGE, { action: 'donnees', date_debut: debut, date_fin: fin })
        .done(function (res) {
            spinner(false);
            $('#btn-charger').prop('disabled', false);
            if (!res.ok) { toast(res.msg, false); return; }

            donneesChargees = res.data;
            afficherTableau(res.data);
            $('#btn-export').prop('disabled', donneesChargees.length === 0);
        })
        .fail(function () {
            spinner(false);
            $('#btn-charger').prop('disabled', false);
            toast('Erreur réseau.', false);
        });
});

function afficherTableau(data) {
    const $tbody = $('#tbody-frais').empty();

    if (data.length === 0) {
        $('#tbl-frais').hide();
        $('#resume-totaux').hide();
        $('#empty-state').show().html(
            '<i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>' +
            'Aucune nomination validée avec frais sur cette période.'
        );
        return;
    }

    let totFrais = 0, totPrest = 0, totTotal = 0;
    const jaVus = new Set();

    data.forEach(function (r) {
        const frais = parseFloat(r.FraisKmPeages);
        const prest = parseFloat(r.Prestations);
        const total = parseFloat(r.Total);
        totFrais += frais;
        totPrest += prest;
        totTotal += total;
        jaVus.add(r.Id_JA);

        // Formater la date en français
        const d = new Date(r.DateRencontre);
        const mois = ['jan.','fév.','mar.','avr.','mai','juin','juil.','août','sep.','oct.','nov.','déc.'];
        const dateFr = d.getUTCDate() + ' ' + mois[d.getUTCMonth()] + ' ' + d.getUTCFullYear();

        const defisc = +r.Defiscalisation === 1
            ? '<span class="badge bg-success">Oui</span>'
            : '<span class="badge bg-secondary">Non</span>';

        $tbody.append(`<tr>
            <td><strong>${r.Nom}</strong> ${r.Prenom}</td>
            <td class="col-center">${dateFr}</td>
            <td class="col-money">${frais > 0 ? money(frais) : '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-center">${defisc}</td>
            <td class="col-money">${money(prest)}</td>
            <td class="col-money">${money(total)}</td>
        </tr>`);
    });

    $('#foot-frais').text(money(totFrais));
    $('#foot-prest').text(money(totPrest));
    $('#foot-total').text(money(totTotal));

    $('#rc-nb-ja').text(jaVus.size);
    $('#rc-nb-missions').text(data.length + ' mission' + (data.length > 1 ? 's' : ''));
    $('#rc-frais').text(money(totFrais));
    $('#rc-prest').text(money(totPrest));
    $('#rc-total').text(money(totTotal));

    $('#empty-state').hide();
    $('#tbl-frais').show();
    $('#resume-totaux').show();
}

// ── Export CSV EBP ────────────────────────────────────────────────────────────
$('#btn-export').on('click', function () {
    const debut = $('#inp-debut').val();
    const fin   = $('#inp-fin').val();

    spinner(true);
    $(this).prop('disabled', true);

    $.post(URL_PAGE, { action: 'export_csv', date_debut: debut, date_fin: fin })
        .done(function (res) {
            spinner(false);
            $('#btn-export').prop('disabled', false);
            if (!res.ok) { toast(res.msg, false); return; }

            // Téléchargement côté client
            const header = 'journal,date,cpte,sens,montant,mode_reglement,libelle,poste analytique\n';
            const blob = new Blob([header + res.csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = `import_JA_${fin.replaceAll('-', '')}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            toast('Export téléchargé.');
        })
        .fail(function () {
            spinner(false);
            $('#btn-export').prop('disabled', false);
            toast('Erreur réseau.', false);
        });
});
</script>

</body>
</html>
