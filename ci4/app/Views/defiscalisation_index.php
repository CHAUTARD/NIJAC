<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Défiscalisation JA (ED51)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Orange, cohérent avec E005 (Menu Défiscalisateur) */
        #page-header {
            background: #e65100;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: .75rem;
        }

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

        #toolbar {
            background: #fff;
            border-bottom: 1px solid #dde3ed;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        #toolbar .annee-badge {
            font-size: .9rem;
            font-weight: 700;
            color: #1a3a6b;
        }

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

        #main-content {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
        }

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
        .rc-blue  { border-color: #1a3a6b; }  .rc-blue  .rc-value { color: #1a3a6b; }
        .rc-green { border-color: #00695c; }  .rc-green .rc-value { color: #00695c; }

        #wrap-table {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,.09);
            overflow: hidden;
        }

        #tbl-defisc {
            width: 100%;
            border-collapse: collapse;
            font-size: .83rem;
        }

        #tbl-defisc thead th {
            background: #1a3a6b;
            color: #fff;
            font-weight: 600;
            padding: .5rem .75rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 2;
        }

        #tbl-defisc tbody tr:hover { background: #f0f4fa; }
        #tbl-defisc tbody tr:nth-child(even) { background: #f8faff; }
        #tbl-defisc tbody tr:nth-child(even):hover { background: #e8edf8; }

        #tbl-defisc td {
            padding: .38rem .75rem;
            border-bottom: 1px solid #e8edf5;
            vertical-align: middle;
        }

        .col-money  { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
        .col-center { text-align: center; }

        #tbl-defisc tfoot td {
            background: #1a3a6b;
            color: #fff;
            font-weight: 700;
            padding: .45rem .75rem;
        }

        #empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #888;
            font-size: .9rem;
        }

        #spinner { display: none; }
        #spinner.active { display: inline-block; }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
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
    'phIcon' => 'cash-coin', 'phTitle' => 'Défiscalisation JA', 'phCode' => 'ED51',
    'phCrumbLabel' => 'Défiscalisateur', 'phCrumbUrl' => site_url('defiscalisateur-menu'), 'phBackUrl' => site_url('defiscalisateur-menu'),
]) ?>

<!-- Toolbar utilisateur -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- ── Toolbar : année civile en cours, pas de sélection de période ── -->
<div id="toolbar">
    <span class="annee-badge"><i class="bi bi-calendar-event me-1"></i>Année civile <?= esc($annee) ?></span>

    <span id="spinner" class="spinner-border spinner-border-sm text-secondary ms-1" role="status"></span>

    <button class="btn-export" id="btn-export" disabled>
        <i class="bi bi-download"></i>Export CSV
    </button>
</div>

<!-- ── Contenu ── -->
<div id="main-content">

    <div id="resume-totaux" style="display:none;">
        <div class="resume-card rc-blue">
            <div class="rc-label">JA actifs défiscalisés</div>
            <div class="rc-value" id="rc-nb-ja">–</div>
        </div>
        <div class="resume-card rc-green">
            <div class="rc-label">Total péages + km</div>
            <div class="rc-value" id="rc-total">–</div>
        </div>
    </div>

    <div id="wrap-table">
        <div id="empty-state">
            <i class="bi bi-hourglass-split" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
            Chargement…
        </div>
        <table id="tbl-defisc" style="display:none;">
            <thead>
                <tr>
                    <th>JA</th>
                    <th>Adresse</th>
                    <th class="col-center">Missions</th>
                    <th class="col-money">Péages</th>
                    <th class="col-money">Kilomètres</th>
                    <th class="col-money">Frais km + péages</th>
                </tr>
            </thead>
            <tbody id="tbody-defisc"></tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Totaux</td>
                    <td class="col-center" id="foot-missions">–</td>
                    <td class="col-money" id="foot-peages">–</td>
                    <td class="col-money" id="foot-km">–</td>
                    <td class="col-money" id="foot-total">–</td>
                </tr>
            </tfoot>
        </table>
    </div>

</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
const BASE = '<?= site_url('defiscalisation') ?>';

function money(v) {
    return parseFloat(v).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
}

function km(v) {
    return parseFloat(v).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' km';
}

function spinner(on) {
    on ? $('#spinner').addClass('active') : $('#spinner').removeClass('active');
}

let donneesChargees = [];

function chargerListe() {
    spinner(true);
    $('#btn-export').prop('disabled', true);

    $.post(`${BASE}/donnees`)
        .done(function (res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }

            donneesChargees = res.data;
            afficherTableau(res.data);
            $('#btn-export').prop('disabled', donneesChargees.length === 0);
        })
        .fail(function () {
            spinner(false);
            toast('Erreur réseau.', false);
        });
}

function afficherTableau(data) {
    const $tbody = $('#tbody-defisc').empty();

    if (data.length === 0) {
        $('#tbl-defisc').hide();
        $('#resume-totaux').hide();
        $('#empty-state').show().html(
            '<i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>' +
            'Aucun JA actif défiscalisé.'
        );
        return;
    }

    let totPeages = 0, totKm = 0, totFrais = 0, totMissions = 0;

    data.forEach(function (r) {
        const peage    = parseFloat(r.Peage);
        const kilo     = parseFloat(r.Kilometre);
        const frais    = parseFloat(r.FraisKmPeages);
        const missions = parseInt(r.NbMissions, 10);
        totPeages   += peage;
        totKm       += kilo;
        totFrais    += frais;
        totMissions += missions;

        const adresse = [r.Cp, r.Ville].filter(Boolean).join(' ');

        $tbody.append(`<tr>
            <td><strong>${r.Nom}</strong> ${r.Prenom}</td>
            <td>${adresse || '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-center">${missions}</td>
            <td class="col-money">${peage > 0 ? money(peage) : '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-money">${kilo > 0 ? km(kilo) : '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-money">${money(frais)}</td>
        </tr>`);
    });

    $('#foot-missions').text(totMissions);
    $('#foot-peages').text(money(totPeages));
    $('#foot-km').text(km(totKm));
    $('#foot-total').text(money(totFrais));

    $('#rc-nb-ja').text(data.length);
    $('#rc-total').text(money(totFrais));

    $('#empty-state').hide();
    $('#tbl-defisc').show();
    $('#resume-totaux').show();
}

// ── Export CSV ────────────────────────────────────────────────────────────────
$('#btn-export').on('click', function () {
    spinner(true);
    $(this).prop('disabled', true);

    $.post(`${BASE}/export-csv`)
        .done(function (res) {
            spinner(false);
            $('#btn-export').prop('disabled', false);
            if (!res.ok) { toast(res.msg, false); return; }

            const header = 'Nom;Prenom;CP;Ville;Missions;Peages;Kilometres;FraisKmPeages\n';
            const blob = new Blob([header + res.csv], { type: 'text/csv;charset=utf-8;' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = `defiscalisation_<?= esc($annee) ?>.csv`;
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

// ── Init : chargement automatique, pas de bouton "Charger" ──────────────────
$(function () { chargerListe(); });
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
