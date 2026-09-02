<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Barème kilométrique (ED52)</title>

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

        /* Orange, cohérent avec E005 / ED51 */
        #page-header { background: #e65100; color: #fff; }

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

        #main-content { flex: 1; padding: 1.25rem; overflow-y: auto; }

        #wrap {
            max-width: 880px;
            margin: 0 auto;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 1px 6px rgba(0,0,0,.09);
            padding: 1.25rem 1.5rem 1.5rem;
        }
        #wrap h1 { font-size: 1.05rem; font-weight: 700; color: #1a3a6b; margin: 0 0 .25rem; }
        #wrap .sub { font-size: .82rem; color: #6b7a90; margin-bottom: 1rem; }
        #wrap .sub code { background: #f0f4fa; padding: .05rem .3rem; border-radius: 4px; }

        table.bar { width: 100%; border-collapse: collapse; font-size: .84rem; }
        table.bar thead th {
            background: #1a3a6b;
            color: #fff;
            font-weight: 600;
            padding: .5rem .6rem;
            text-align: center;
            line-height: 1.2;
        }
        table.bar thead th small { display: block; font-weight: 400; opacity: .8; font-size: .72rem; }
        table.bar td { padding: .35rem .5rem; border-bottom: 1px solid #e8edf5; text-align: center; }
        table.bar tbody tr:nth-child(even) { background: #f8faff; }
        table.bar td.lib { text-align: left; font-weight: 600; color: #1a3a6b; white-space: nowrap; }
        table.bar input {
            width: 6.5rem;
            padding: .25rem .4rem;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            font-size: .84rem;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        table.bar input:focus { outline: none; border-color: #e65100; }

        .formule {
            font-size: .78rem;
            color: #6b7a90;
            margin: .9rem 0 1.2rem;
            padding: .6rem .8rem;
            background: #fff7f0;
            border-left: 3px solid #e65100;
            border-radius: 4px;
        }
        .formule strong { color: #b45309; }

        .maj-row { display: flex; align-items: center; gap: .6rem; margin: 1rem 0 1.4rem; }
        .maj-row label { font-size: .84rem; font-weight: 600; color: #1a3a6b; }
        .maj-row input { width: 5rem; text-align: right; padding: .25rem .4rem; border: 1px solid #cbd5e1; border-radius: 5px; }

        .actions { display: flex; gap: .75rem; align-items: center; }
        .btn-save {
            background: #1a7f4b; color: #fff; border: none; border-radius: 6px;
            font-size: .85rem; font-weight: 600; padding: .4rem 1.1rem;
            display: inline-flex; align-items: center; gap: .4rem; cursor: pointer;
        }
        .btn-save:hover { opacity: .9; }
        .btn-save:disabled { opacity: .5; cursor: not-allowed; }
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
    </style>

    <!-- Charte "fiche moderne" EN11, base orange — surcharge, à charger APRÈS le <style> ci-dessus -->
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin-orange.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'table', 'phTitle' => 'Barème kilométrique', 'phCode' => 'ED52',
    'phCrumbLabel' => 'Défiscalisation JA', 'phCrumbUrl' => site_url('defiscalisation'), 'phBackUrl' => site_url('defiscalisation'),
    'phCrumbColor' => '#ffe0c2', 'phBadgeColor' => '#ffe0c2',
]) ?>

<!-- Toolbar utilisateur -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbId' => 'toolbar-user']) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="main-content">
    <div id="wrap">
        <h1><i class="bi bi-fuel-pump me-1"></i>Barème kilométrique fiscal — voiture</h1>
        <p class="sub">
            Valeurs <strong>2026</strong> issues du site
            <a href="https://www.impots.gouv.fr/simulateur-bareme-kilometrique" target="_blank" rel="noopener">https://www.impots.gouv.fr/simulateur-bareme-kilometrique</a>.
            Les 5 tranches de puissance sont figées ; seuls les coefficients et la part fixe se modifient.
        </p>

        <table class="bar">
            <thead>
                <tr>
                    <th>Puissance</th>
                    <th>≤ 5 000 km<small>d &times; k</small></th>
                    <th>5 001 – 20 000 km<small>(d &times; k) + fixe</small></th>
                    <th>&nbsp;<small>part fixe</small></th>
                    <th>&gt; 20 000 km<small>d &times; k</small></th>
                </tr>
            </thead>
            <tbody id="tbody-bareme">
                <?php foreach ($lignes as $l): ?>
                <tr data-id="<?= (int) $l['Id_ComptaDefiscalisation'] ?>">
                    <td class="lib"><?= esc($l['Libelle']) ?></td>
                    <td><input type="number" step="0.001" min="0" class="in-t1" value="<?= esc(rtrim(rtrim($l['Coef_T1'], '0'), '.')) ?>"></td>
                    <td><input type="number" step="0.001" min="0" class="in-t2" value="<?= esc(rtrim(rtrim($l['Coef_T2'], '0'), '.')) ?>"></td>
                    <td><input type="number" step="0.01"  min="0" class="in-fx" value="<?= esc(rtrim(rtrim($l['Fixe_T2'], '0'), '.')) ?>"></td>
                    <td><input type="number" step="0.001" min="0" class="in-t3" value="<?= esc(rtrim(rtrim($l['Coef_T3'], '0'), '.')) ?>"></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="formule">
            <strong>d</strong> = distance totale parcourue dans l'année (km) ·
            <strong>k</strong> = coefficient par kilomètre de la colonne
            (<code>Coef_T1</code> / <code>Coef_T2</code> / <code>Coef_T3</code> selon la tranche) ·
            <strong>fixe</strong> = montant forfaitaire <code>Fixe_T2</code> ajouté sur la tranche 5&nbsp;001–20&nbsp;000&nbsp;km.
        </div>

        <div class="maj-row">
            <label for="in-maj">Véhicules électriques — majoration</label>
            <input type="number" step="0.1" min="0" id="in-maj" value="<?= esc(rtrim(rtrim(number_format($majoration, 2, '.', ''), '0'), '.')) ?>">
            <span>%</span>
        </div>

        <div class="actions">
            <button class="btn-save" id="btn-save"><i class="bi bi-check-lg"></i>Enregistrer</button>
            <a class="btn-save" style="background:#6b7280"
               href="<?= base_url('Documentation/cerfa_2041-alk_5100.pdf') ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf"></i>Notice CERFA 2041-ALK
            </a>
            <span id="spinner" class="spinner-border spinner-border-sm text-secondary" role="status"></span>
        </div>
    </div>
</div>

<?= view('partials/page_footer') ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
const BASE = '<?= site_url('defiscalisation-bareme') ?>';

$('#btn-save').on('click', function () {
    const lignes = [];
    let invalide = false;

    $('#tbody-bareme tr').each(function () {
        const $tr = $(this);
        const row = {
            id:      $tr.data('id'),
            coef_t1: $tr.find('.in-t1').val(),
            coef_t2: $tr.find('.in-t2').val(),
            fixe_t2: $tr.find('.in-fx').val(),
            coef_t3: $tr.find('.in-t3').val()
        };
        for (const k of ['coef_t1', 'coef_t2', 'fixe_t2', 'coef_t3']) {
            if (row[k] === '' || isNaN(parseFloat(row[k])) || parseFloat(row[k]) < 0) invalide = true;
        }
        lignes.push(row);
    });

    const maj = $('#in-maj').val();
    if (maj === '' || isNaN(parseFloat(maj)) || parseFloat(maj) < 0) invalide = true;

    if (invalide) { toast('Chaque valeur doit être un nombre positif.', false); return; }

    $('#spinner').addClass('active');
    $(this).prop('disabled', true);

    $.post(`${BASE}/enregistrer`, { lignes: lignes, majoration: maj })
        .done(function (res) {
            $('#spinner').removeClass('active');
            $('#btn-save').prop('disabled', false);
            toast(res.msg || (res.ok ? 'Enregistré.' : 'Échec.'), !!res.ok);
        })
        .fail(function () {
            $('#spinner').removeClass('active');
            $('#btn-save').prop('disabled', false);
            toast('Erreur réseau.', false);
        });
});
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
