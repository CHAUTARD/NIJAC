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
        .cards-row {
            display: flex; align-items: center; gap: 1rem;
            flex-wrap: wrap; margin-bottom: 1rem;
        }
        #search-defisc {
            margin-left: auto; align-self: center;
            font-size: .85rem; padding: .3rem .8rem;
            border: 1px solid #c8d4e8; border-radius: 999px; width: 280px;
        }
        #tbl-defisc thead th[data-col] { cursor: pointer; user-select: none; white-space: nowrap; }
        #tbl-defisc thead th[data-col]::after { content: ' \2195'; opacity: .35; font-size: .8em; }
        #tbl-defisc thead th.sort-asc::after  { content: ' \25B2'; opacity: 1; }
        #tbl-defisc thead th.sort-desc::after { content: ' \25BC'; opacity: 1; }
        #toolbar .annee-badge {
            font-size: .9rem;
            font-weight: 700;
            color: #1a3a6b;
        }

        .btn-relance {
            background: #b45309;
            color: #fff;
            border: 1px solid #b45309;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 600;
            padding: .4rem 1.1rem;
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            cursor: pointer;
            box-shadow: 0 1px 2px rgba(16,24,40,.06);
            transition: background .15s;
            margin-left: auto;
        }
        .btn-relance:hover { background: #92400e; }
        .btn-relance:disabled { opacity: .5; cursor: not-allowed; }

        .btn-bareme {
            background: #fff;
            color: #e65100;
            border: 1px solid #e65100;
            border-radius: 6px;
            font-size: .82rem;
            font-weight: 600;
            padding: .32rem .9rem;
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            text-decoration: none;
            transition: opacity .15s;
            margin-left: .5rem;
        }
        .btn-bareme:hover { background: #fff3e8; }

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
            margin-left: .5rem;
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
            flex-wrap: wrap;
        }

        .resume-card {
            background: #fff;
            border-radius: 8px;
            padding: .7rem 1.2rem;
            box-shadow: 0 1px 4px rgba(0,0,0,.08);
            border-left: 4px solid #ccc;
            min-width: 160px;
            text-align: center;
        }
        .resume-card .rc-label { font-size: .7rem; color: #6b7a90; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
        .resume-card .rc-value { font-size: 1.4rem; font-weight: 800; line-height: 1.1; margin-top: 2px; }
        .rc-blue   { border-color: #1a3a6b; }  .rc-blue   .rc-value { color: #1a3a6b; }
        .rc-green  { border-color: #00695c; }  .rc-green  .rc-value { color: #00695c; }
        .rc-orange { border-color: #e65100; }  .rc-orange .rc-value { color: #e65100; }

        #tbl-defisc .sel-cv {
            font-size: .8rem;
            padding: .1rem .25rem;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
        }
        #tbl-defisc .chk-elec,
        #tbl-defisc .chk-relance,
        #tbl-defisc #chk-relance-all { width: 1rem; height: 1rem; cursor: pointer; }
        #tbl-defisc .chk-relance:disabled { cursor: not-allowed; opacity: .4; }
        .bareme-manquant { color: #b45309; font-style: italic; font-weight: 500; }

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

    <!-- Charte "fiche moderne" EN11, base orange — surcharge, à charger APRÈS le <style> ci-dessus -->
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin-orange.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'cash-coin', 'phTitle' => 'Défiscalisation JA', 'phCode' => 'ED51',
    'phCrumbLabel' => 'Défiscalisateur', 'phCrumbUrl' => site_url('defiscalisateur-menu'), 'phBackUrl' => site_url('defiscalisateur-menu'),
    'phCrumbColor' => '#ffe0c2', 'phBadgeColor' => '#ffe0c2',
]) ?>

<!-- Toolbar utilisateur -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- ── Toolbar : année civile en cours, pas de sélection de période ── -->
<div id="toolbar">
    <span class="annee-badge"><i class="bi bi-calendar-event me-1"></i>Année civile <?= esc($annee) ?></span>

    <span id="spinner" class="spinner-border spinner-border-sm text-secondary ms-1" role="status"></span>

    <button class="btn-relance" id="btn-relance" disabled>
        <i class="bi bi-envelope-paper"></i>Relancer les JA cochés
    </button>

    <a href="<?= site_url('defiscalisation-bareme') ?>" class="btn-bareme">
        <i class="bi bi-table"></i>Gérer le barème
    </a>

    <a href="<?= site_url('attestation-defisc') ?>" class="btn-bareme">
        <i class="bi bi-file-earmark-text"></i>Attestation sur l'honneur
    </a>

    <button class="btn-export" id="btn-export" disabled>
        <i class="bi bi-download"></i>Export CSV
    </button>
</div>

<!-- ── Contenu ── -->
<div id="main-content">

    <div class="cards-row">
    <div id="resume-totaux" style="display:none;">
        <div class="resume-card rc-blue">
            <div class="rc-label">JA actifs défiscalisés</div>
            <div class="rc-value" id="rc-nb-ja">–</div>
        </div>
        <div class="resume-card rc-green">
            <div class="rc-label">Total péages + km</div>
            <div class="rc-value" id="rc-total">–</div>
        </div>
        <div class="resume-card rc-orange">
            <div class="rc-label">Total défiscalisable (barème)</div>
            <div class="rc-value" id="rc-bareme">–</div>
        </div>
    </div>
        <input type="search" id="search-defisc" placeholder="🔍 Rechercher (n°, nom, ville)">
    </div>

    <div id="wrap-table">
        <div id="empty-state">
            <i class="bi bi-hourglass-split" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>
            Chargement…
        </div>
        <table id="tbl-defisc" style="display:none;">
            <thead>
                <tr>
                    <th class="col-center" title="Relance"><input type="checkbox" id="chk-relance-all"></th>
                    <th class="col-center" data-col="Id_JA">N° JA</th>
                    <th data-col="Nom">JA</th>
                    <th data-col="Ville">Adresse</th>
                    <th class="col-center" data-col="NbMissions">Missions</th>
                    <th class="col-money" data-col="Peage">Péages</th>
                    <th class="col-money" data-col="Kilometre">Kilomètres</th>
                    <th class="col-money" data-col="FraisKmPeages">Frais km + péages</th>
                    <th class="col-center" data-col="PuissanceFiscale">CV</th>
                    <th class="col-center" data-col="VehiculeElectrique">Élec.</th>
                    <th class="col-money" data-col="MontantBareme">Frais défiscalisables (barème)</th>
                </tr>
            </thead>
            <tbody id="tbody-defisc"></tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Totaux</td>
                    <td class="col-center" id="foot-missions">–</td>
                    <td class="col-money" id="foot-peages">–</td>
                    <td class="col-money" id="foot-km">–</td>
                    <td class="col-money" id="foot-total">–</td>
                    <td colspan="2"></td>
                    <td class="col-money" id="foot-bareme">–</td>
                </tr>
            </tfoot>
        </table>
    </div>

</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
<script>
const BASE = '<?= site_url('defiscalisation') ?>';
const sortState = { col: null, asc: true };
const CV_OPTIONS = <?= json_encode($cvOptions) ?>;

function cvSelect(id, val) {
    let opts = '<option value="">–</option>';
    CV_OPTIONS.forEach(function (cv) {
        const label = cv >= 7 ? cv + ' +' : cv;
        const sel   = (val !== null && String(val) === String(cv)) ? ' selected' : '';
        opts += `<option value="${cv}"${sel}>${label}</option>`;
    });
    return `<select class="sel-cv" data-id="${id}">${opts}</select>`;
}

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
            filtrerEtAfficher();
            $('#btn-export').prop('disabled', donneesChargees.length === 0);
        })
        .fail(function () {
            spinner(false);
            toast('Erreur réseau.', false);
        });
}

function trier(rows) {
    if (!sortState.col) return rows;
    const c = sortState.col;
    return [...rows].sort((a, b) => {
        let va = a[c], vb = b[c];
        const na = parseFloat(va), nb = parseFloat(vb);
        if (!isNaN(na) && !isNaN(nb)) { va = na; vb = nb; }
        else { va = String(va ?? '').toLowerCase(); vb = String(vb ?? '').toLowerCase(); }
        if (va < vb) return sortState.asc ? -1 : 1;
        if (va > vb) return sortState.asc ? 1 : -1;
        return 0;
    });
}

function filtrerEtAfficher() {
    const q = ($('#search-defisc').val() || '').trim().toLowerCase();
    let rows = donneesChargees;
    if (q) {
        rows = rows.filter(r =>
            [r.Id_JA, r.Nom, r.Prenom, r.Cp, r.Ville].join(' ').toLowerCase().includes(q)
        );
    }
    afficherTableau(trier(rows));
}

function afficherTableau(data) {
    const $tbody = $('#tbody-defisc').empty();

    if (data.length === 0) {
        $('#tbl-defisc').hide();
        $('#resume-totaux').hide();
        $('#empty-state').show().html(
            '<i class="bi bi-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:.5rem;"></i>' +
            (($('#search-defisc').val() || '').trim() ? 'Aucun résultat.' : 'Aucun JA actif défiscalisé.')
        );
        return;
    }

    let totPeages = 0, totKm = 0, totFrais = 0, totMissions = 0, totBareme = 0;

    data.forEach(function (r) {
        const peage    = parseFloat(r.Peage);
        const kilo     = parseFloat(r.Kilometre);
        const frais    = parseFloat(r.FraisKmPeages);
        const missions = parseInt(r.NbMissions, 10);
        const bareme   = r.MontantBareme === null ? null : parseFloat(r.MontantBareme);
        totPeages   += peage;
        totKm       += kilo;
        totFrais    += frais;
        totMissions += missions;
        if (bareme !== null) totBareme += bareme;

        const adresse = [r.Cp, r.Ville].filter(Boolean).join(' ');
        const elecChk = `<input type="checkbox" class="chk-elec" data-id="${r.Id_JA}"${r.VehiculeElectrique ? ' checked' : ''}>`;
        const cellBareme = bareme !== null
            ? money(bareme)
            : '<span class="bareme-manquant">CV manquant</span>';
        const sansEmail  = !r.HasEmail;
        const relanceChk = `<input type="checkbox" class="chk-relance" data-id="${r.Id_JA}"`
            + (r.PuissanceFiscale === null && !sansEmail ? ' checked' : '')
            + (sansEmail ? ' disabled title="Sans adresse email"' : '')
            + '>';

        $tbody.append(`<tr>
            <td class="col-center">${relanceChk}</td>
            <td class="col-center">${r.Id_JA}</td>
            <td><strong>${r.Nom}</strong> ${r.Prenom}</td>
            <td>${adresse || '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-center">${missions}</td>
            <td class="col-money">${peage > 0 ? money(peage) : '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-money">${kilo > 0 ? km(kilo) : '<span style="color:#aaa;">–</span>'}</td>
            <td class="col-money">${money(frais)}</td>
            <td class="col-center">${cvSelect(r.Id_JA, r.PuissanceFiscale)}</td>
            <td class="col-center">${elecChk}</td>
            <td class="col-money">${cellBareme}</td>
        </tr>`);
    });

    $('#foot-missions').text(totMissions);
    $('#foot-peages').text(money(totPeages));
    $('#foot-km').text(km(totKm));
    $('#foot-total').text(money(totFrais));
    $('#foot-bareme').text(money(totBareme));

    $('#rc-nb-ja').text(data.length);
    $('#rc-total').text(money(totFrais));
    $('#rc-bareme').text(money(totBareme));

    majBoutonRelance();

    $('#empty-state').hide();
    $('#tbl-defisc').show();
    $('#resume-totaux').show();
}

// ── Cases de relance : compteur + case "tout cocher" ─────────────────────────
function idsRelanceCoches() {
    return $('#tbody-defisc .chk-relance:checked').map(function () { return $(this).data('id'); }).get();
}
function majBoutonRelance() {
    const n = idsRelanceCoches().length;
    $('#btn-relance')
        .prop('disabled', n === 0)
        .html(`<i class="bi bi-envelope-paper"></i>Relancer les JA cochés${n ? ' (' + n + ')' : ''}`);
    const total = $('#tbody-defisc .chk-relance:not(:disabled)').length;
    $('#chk-relance-all').prop('checked', total > 0 && n === total).prop('indeterminate', n > 0 && n < total);
}
$('#tbody-defisc').on('change', '.chk-relance', majBoutonRelance);
$('#chk-relance-all').on('change', function () {
    $('#tbody-defisc .chk-relance:not(:disabled)').prop('checked', this.checked);
    majBoutonRelance();
});

// ── Saisie puissance fiscale / électrique (inline) ───────────────────────────
$('#tbody-defisc').on('change', '.sel-cv, .chk-elec', function () {
    const id  = $(this).data('id');
    const $tr = $(this).closest('tr');

    spinner(true);
    $.post(`${BASE}/vehicule`, {
        Id_JA:              id,
        PuissanceFiscale:   $tr.find('.sel-cv').val(),
        VehiculeElectrique: $tr.find('.chk-elec').is(':checked') ? 1 : 0
    })
        .done(function (res) {
            if (!res.ok) { spinner(false); toast(res.msg, false); return; }
            chargerListe();   // recalcul du barème côté serveur
        })
        .fail(function () {
            spinner(false);
            toast('Erreur réseau.', false);
        });
});

// ── Relance email : JA cochés dans le tableau ────────────────────────────────
$('#btn-relance').on('click', function () {
    const ids = idsRelanceCoches();
    if (!ids.length) { toast('Cochez au moins un JA à relancer.', false); return; }

    nijacConfirm(
        `Envoyer un email de relance à ${ids.length} juge(s)-arbitre(s) coché(s) pour qu'il(s) renseigne(nt) et signe(nt) l'attestation ?`,
        function () {
            spinner(true);
            $('#btn-relance').prop('disabled', true);
            $.post(`${BASE}/relancer-vehicule`, { ids: ids })
                .done(function (res) {
                    spinner(false);
                    toast(res.msg || (res.ok ? 'Emails envoyés.' : 'Échec de l\'envoi.'), !!res.ok);
                    if (res.erreurs && res.erreurs.length) console.warn('Relance véhicule — échecs :', res.erreurs);
                    chargerListe();
                })
                .fail(function () {
                    spinner(false);
                    $('#btn-relance').prop('disabled', false);
                    toast('Erreur réseau.', false);
                });
        },
        null,
        { type: 'warning', title: 'Relancer les JA', confirmLabel: 'Envoyer les emails' }
    );
});

// ── Export CSV ────────────────────────────────────────────────────────────────
$('#btn-export').on('click', function () {
    spinner(true);
    $(this).prop('disabled', true);

    $.post(`${BASE}/export-csv`)
        .done(function (res) {
            spinner(false);
            $('#btn-export').prop('disabled', false);
            if (!res.ok) { toast(res.msg, false); return; }

            const header = 'Nom;Prenom;CP;Ville;Missions;Peages;Kilometres;FraisKmPeages;CV;Electrique;FraisDefiscalisables\n';
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
$(function () {
    $('#search-defisc').on('input', filtrerEtAfficher);
    if (typeof nijacSortableTable === 'function') {
        nijacSortableTable('#tbl-defisc thead th[data-col]', 'col', sortState, filtrerEtAfficher);
    }
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
