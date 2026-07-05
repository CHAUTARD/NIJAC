<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Comptabilité frais JA (E025)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">

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
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        /* ── Toolbar utilisateur ── */
        #toolbar-user {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
        }
        #toolbar-user .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar-user .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            color: #c00;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline dotted;
        }
        #toolbar-user .ts-pwd-warning:hover { color: #900; }

        /* ── Toolbar filtres ── */
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

        /* Spinner */
        #spinner { display: none; }
        #spinner.active { display: inline-block; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calculator-fill', 'phTitle' => 'Comptabilité frais JA', 'phCode' => 'E025',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar utilisateur : recopié de Nominateur/includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- ── Toolbar filtres ── -->
<div id="toolbar">
    <label for="inp-debut">Période du</label>
    <input type="date" id="inp-debut" value="<?= esc($defaultDebut) ?>">

    <label for="inp-fin">au</label>
    <input type="date" id="inp-fin" value="<?= esc($defaultFin) ?>">

    <button class="btn-phase" id="btn-phase1" data-debut="<?= esc($dateP1Debut) ?>" data-fin="<?= esc($dateP1Fin) ?>">
        <i class="bi bi-1-circle"></i>Phase 1
    </button>
    <button class="btn-phase" id="btn-phase2" data-debut="<?= esc($dateP2Debut) ?>" data-fin="<?= esc($dateP2Fin) ?>">
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

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
const BASE = '<?= site_url('compta') ?>';

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
    nijacToast(msg, ok ? 'success' : 'danger');
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

    $.post(`${BASE}/donnees`, { date_debut: debut, date_fin: fin })
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

    $.post(`${BASE}/export-csv`, { date_debut: debut, date_fin: fin })
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
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
