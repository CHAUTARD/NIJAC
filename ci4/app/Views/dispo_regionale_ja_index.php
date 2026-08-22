<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>Disponibilités JA Régionale (E036)<?= $ja ? ' – ' . esc($ja['Nom'] . ' ' . $ja['Prenom']) : '' ?></title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
<style>
    body { background: #f0f4f8; font-family: Arial, Helvetica, sans-serif; }

    .contenu-principal {
        display: grid;
        grid-template-columns: 220px minmax(0, 760px) 220px;
        justify-content: center;
        align-items: start;
        gap: 1.5rem;
        margin-top: 2rem;
        padding: 0 1rem 2rem;
    }
    #bandeau-normandie { grid-column: 1; grid-row: 1; }
    #bandeau-normandie img { width: 100%; display: block; border-radius: 8px; box-shadow: 0 2px 8px rgba(26,58,107,.2); }
    .contenu-principal > .card-dispo,
    .contenu-principal > .alert-danger { grid-column: 2; grid-row: 1; }
    @media (max-width: 900px) {
        .contenu-principal { grid-template-columns: 220px; justify-content: center; }
        #bandeau-normandie { grid-row: 1; margin: 0 auto; }
        .contenu-principal > .card-dispo,
        .contenu-principal > .alert-danger { grid-column: 1; grid-row: 2; }
    }

    .page-header {
        background: #003087; color: #fff; padding: .75rem 1.5rem;
        display: flex; align-items: center; gap: 1rem;
    }
    .page-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; }
    .page-header .badge-ecran {
        background: rgba(255,255,255,.2); font-size: .7rem;
        padding: .25em .6em; border-radius: .25rem; letter-spacing: .5px;
    }

    .card-dispo { width: 100%; max-width: 760px; margin: 0; border-radius: .75rem; box-shadow: 0 4px 20px rgba(0,0,0,.12); }
    .card-dispo .card-header {
        background: #003087; color: #fff; border-radius: .75rem .75rem 0 0;
        font-weight: 700; font-size: 1rem; padding: 1rem 1.25rem;
    }

    .ja-identity { background: #e8f0fb; border-radius: .5rem; padding: .75rem 1rem; margin-bottom: 1.25rem; }
    .ja-identity .ja-nom { font-size: 1.15rem; font-weight: 700; color: #003087; }

    table.tbl-dates { width: 100%; }
    table.tbl-dates th { font-size: .82rem; color: #444; border-bottom: 2px solid #dee2e6; }
    table.tbl-dates td { vertical-align: middle; padding: .5rem .4rem; }
    table.tbl-dates tr:not(:last-child) td { border-bottom: 1px solid #eee; }
    .chk-dept { margin-right: .35rem; }
    .depts-cell label { font-size: .82rem; margin-right: .6rem; white-space: nowrap; }
    .depts-cell { opacity: .35; pointer-events: none; transition: opacity .15s; }
    .depts-cell.actif { opacity: 1; pointer-events: auto; }
    .date-txt { font-weight: 600; color: #1a3a6b; }

    #btn-valider { width: 100%; padding: .65rem; font-size: 1rem; font-weight: 700; }

    .succes-bloc { display: none; text-align: center; padding: 2rem 1rem; }
    .succes-bloc .bi { font-size: 3rem; color: #198754; }
    .succes-bloc p { font-size: 1.05rem; margin-top: .75rem; color: #155724; font-weight: 600; }
</style>
</head>
<body>

<div class="page-header">
    <i class="bi bi-calendar2-check-fill me-2"></i><h1>Disponibilités JA – Championnat Régional</h1>
    <span class="badge-ecran">E036</span>
</div>

<div class="container-fluid">
<div class="contenu-principal">

<div id="bandeau-normandie">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<?php if ($erreur): ?>
    <div class="alert alert-danger" style="max-width:520px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($erreur) ?>
    </div>
<?php else: ?>

<div class="card card-dispo">
    <div class="card-header">
        <i class="bi bi-calendar2-week-fill me-2"></i>Mes disponibilités – Championnat Régional
    </div>
    <div class="card-body p-4">

        <div class="ja-identity">
            <div class="ja-nom"><?= esc(mb_strtoupper($ja['Nom']) . ' ' . $ja['Prenom']) ?></div>
        </div>

        <p class="text-muted" style="font-size:.88rem">
            Cochez les dates où vous êtes disponible, puis le ou les départements souhaités
            (<?= implode(', ', ['14', '27', '50', '61', '76']) ?>).
        </p>

        <?php if (!$dates): ?>
            <p class="text-muted fst-italic">Aucune date de championnat régional n'est actuellement programmée.</p>
        <?php else: ?>
        <div id="form-dispo">
            <div class="table-responsive">
                <table class="tbl-dates">
                    <thead>
                        <tr>
                            <th style="width:2.2rem"></th>
                            <th>Date</th>
                            <th>Horaire</th>
                            <th>Département(s)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dates as $d):
                        $deptsActuels = $d['Departement'] ? explode(',', $d['Departement']) : [];
                        $disponible   = $d['Id_DisponibleRegionale'] !== null; ?>
                        <tr data-id="<?= (int) $d['Id_CompetitionRegionale'] ?>">
                            <td>
                                <input type="checkbox" class="form-check-input chk-dispo" <?= $disponible ? 'checked' : '' ?>>
                            </td>
                            <td class="date-txt"><?= esc($d['DateLabel']) ?></td>
                            <td><?= esc(substr($d['Heure'], 0, 5)) ?></td>
                            <td>
                                <div class="depts-cell <?= $disponible ? 'actif' : '' ?>">
                                    <?php foreach (['14', '27', '50', '61', '76'] as $dept): ?>
                                        <label>
                                            <input type="checkbox" class="chk-dept" value="<?= $dept ?>"
                                                   <?= in_array($dept, $deptsActuels, true) ? 'checked' : '' ?>><?= $dept ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                <button id="btn-valider" class="btn btn-success">
                    <i class="bi bi-floppy me-1"></i>Enregistrer mes disponibilités
                </button>
            </div>
            <div id="dispo-status" class="text-danger mt-2" style="font-size:.85rem"></div>
        </div>
        <?php endif; ?>

        <div class="succes-bloc" id="succes-bloc">
            <i class="bi bi-check-circle-fill"></i>
            <p>Vos disponibilités ont bien été enregistrées.</p>
            <p style="font-size:1rem;color:#1a3a6b;font-weight:700;">Merci pour votre contribution !</p>
        </div>

    </div>
</div>

<?php endif; ?>
</div><!-- /contenu-principal -->
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
'use strict';
const ID_JA = <?= (int) $idJa ?>;
const BASE  = '<?= site_url('dispo-regionale-ja') ?>';

$('.chk-dispo').on('change', function () {
    $(this).closest('tr').find('.depts-cell').toggleClass('actif', this.checked);
});

$('#btn-valider').on('click', function () {
    const reponses = [];
    $('table.tbl-dates tbody tr').each(function () {
        const $tr = $(this);
        if (!$tr.find('.chk-dispo').is(':checked')) return;
        const departements = $tr.find('.chk-dept:checked').map(function () { return this.value; }).get();
        reponses.push({ id_competition: parseInt($tr.data('id'), 10), departements });
    });

    const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…');
    $('#dispo-status').text('');
    $.post(BASE + '/sauvegarder', { id_ja: ID_JA, reponses: JSON.stringify(reponses) }, function (r) {
        if (r.ok) {
            $('#form-dispo').hide();
            $('#succes-bloc').show();
        } else {
            $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mes disponibilités');
            $('#dispo-status').text(r.err || 'Erreur inconnue.');
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mes disponibilités');
        $('#dispo-status').text('Erreur réseau.');
    });
});
</script>
</body>
</html>
