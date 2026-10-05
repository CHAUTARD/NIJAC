<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Degrés Juge-Arbitre (EC72)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
    <style>
        /* Habillage EN11 (nijac-skin.css) transposé au motif liste-edit : chargé
           APRÈS nijac-skin.css pour garder l'en-tête sarcelle (E009 CRA Convoc)
           et neutraliser ce que nijac-liste-edit.css impose (bandeau steelblue,
           zébrage bleu, filet du volet). */
        #page-header { background: #00695c; }
        #panel-liste { width: 100%; border-right: 0; }

        /* Barre d'outils = #menu-strip d'EN11 (fond clair, comboboxes à encoche). */
        #liste-header {
            --strip-bg: var(--en-bg);
            background: var(--en-bg); color: inherit; font-weight: 400;
            padding: .4rem 1.4rem 1rem; gap: .55rem;
        }
        #liste-header .combo-field > select {
            font-size: .85rem; padding: .42rem 2rem .42rem .8rem;
            border: 1.5px solid #d3dae6; border-radius: 12px;
        }
        /* Champ Recherche : pilule blanche comme #search-input d'EN11. */
        #liste-header #txt-recherche {
            width: 290px; font-size: .85rem; padding: .32rem .9rem;
            border-radius: 999px; background: var(--en-card);
            border: 1px solid var(--en-line); box-shadow: 0 1px 2px rgba(16,24,40,.04);
        }
        /* Bouton principal : pilule verte comme #win-menu-trigger d'EN11. */
        #btn-nouveau {
            margin-top: .6rem; border-radius: 999px; padding: .25rem .9rem; font-size: .83rem;
            background: var(--en-green); color: #fff; border: 1px solid var(--en-green); font-weight: 600;
        }
        #btn-nouveau:hover { background: var(--en-green-d); color: #fff; }
        /* nijac-liste-edit.css force #lbl-count{display:inline-block;padding:...}
           (id > .count-badge) : on rétablit le centrage flex de la pastille EN11. */
        #lbl-count { display: inline-flex; align-items: center; padding: 0 .9rem; font-size: .82rem; }

        /* Grille : feuille blanche + en-têtes/zébrage/survol d'EN11. */
        #table-wrapper {
            overflow: auto; background: var(--en-card); margin: 0 1.4rem 1rem;
            border: 1px solid var(--en-line); border-radius: var(--en-radius);
            box-shadow: 0 1px 3px rgba(16,24,40,.06);
        }
        #table-wrapper table { font-size: .82rem; }
        #table-wrapper table thead th {
            background: var(--en-card); border: 0; border-bottom: 2px solid var(--en-line);
            color: var(--en-muted); font-size: .68rem; font-weight: 700;
            letter-spacing: .5px; text-transform: uppercase; padding: .7rem .6rem; text-align: left;
        }
        #table-wrapper table thead th[data-col]:hover { background: #f6f8fb; }
        #table-wrapper table thead th.th-pk { color: #e65100; }
        #table-wrapper table tbody tr { border-bottom: 1px solid #f2f3f6; }
        #table-wrapper table tbody tr:nth-child(odd)  { background: #e3f4e9; }
        #table-wrapper table tbody tr:nth-child(even) { background: transparent; }
        #table-wrapper table tbody tr:hover { background: #d3ecdd; }
        #table-wrapper table tbody td { border: 0; padding: .55rem .6rem; }
        #table-wrapper table td code { color: var(--en-muted); font-size: inherit; }
        #table-wrapper td.nowrap { white-space: nowrap; }
        .td-actions { white-space: nowrap; text-align: center; vertical-align: middle; }
        #table-wrapper table tbody td.td-actions { padding: .2rem; }
        .btn-suppr { border-radius: 8px; }

        /* Modale : libellés comme EN11 (fw-semibold, taille Bootstrap). */
        .modal .form-label { font-size: 1rem; font-weight: 600; color: inherit; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'award', 'phTitle' => 'Degrés Juge-Arbitre', 'phCode' => 'EC72',
    'phCrumbLabel' => 'CRA Convoc', 'phCrumbUrl' => site_url('cra-convoc-menu'), 'phBackUrl' => site_url('cra-convoc-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">
    <div id="panel-liste">
        <div id="liste-header">
            <button class="btn btn-sm" id="btn-nouveau"><i class="bi bi-plus-circle me-1"></i>Nouveau degré</button>
            <span id="lbl-count" class="count-badge">0</span>
            <span class="combo-field ms-auto">
                <label for="txt-recherche">Recherche</label>
                <input type="search" id="txt-recherche" placeholder="Rechercher (code, libellé, description)…">
            </span>
        </div>
        <div id="table-wrapper">
            <table id="tbl-degres">
                <thead>
                    <tr>
                        <th class="th-pk" style="width:80px;" data-col="Code">Code<span class="sort-icon"></span></th>
                        <th data-col="Libelle">Libellé<span class="sort-icon"></span></th>
                        <th data-col="Description">Description<span class="sort-icon"></span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="4" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-degre" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <form class="modal-content" id="form-degre">
            <div class="modal-header" style="background:#1a3a6b;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-award me-2"></i><span id="modal-titre">Degré</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-2">
                    <div class="col-3">
                        <label class="form-label" for="txt-code">Code :</label>
                        <input type="text" id="txt-code" class="form-control form-control-sm" minlength="3" maxlength="3" required>
                    </div>
                    <div class="col">
                        <label class="form-label" for="txt-libelle">Libellé :</label>
                        <input type="text" id="txt-libelle" class="form-control form-control-sm" maxlength="26" required>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="txt-description">Description :</label>
                    <textarea id="txt-description" class="form-control form-control-sm" rows="3" required></textarea>
                </div>
                <div id="form-err" class="text-danger small fw-bold"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const JUG_BASE = '<?= site_url('cra-juge-arbitre') ?>';
let degres = [];
let currentId = null;
let modal;
const sortState = { col: 'Code', asc: true };

function afficher() {
    const q   = $('#txt-recherche').val().trim().toLowerCase();
    const col = sortState.col;
    const rows = degres
        .filter(d => !q || (d.Code + ' ' + d.Libelle + ' ' + d.Description).toLowerCase().includes(q))
        .sort((a, b) => {
            const cmp = String(a[col] ?? '').localeCompare(String(b[col] ?? ''), 'fr');
            return sortState.asc ? cmp : -cmp;
        });

    $('#lbl-count').text(rows.length);
    const $body = $('#tbody-liste').empty();
    if (!rows.length) {
        $body.append('<tr><td colspan="4" class="text-center text-muted py-3">Aucun degré.</td></tr>');
        return;
    }
    rows.forEach(d => {
        $('<tr>').attr('data-id', d.Id_JugeArbitre).append(
            $('<td class="nowrap">').append($('<code>').text(d.Code)),
            $('<td>').text(d.Libelle),
            $('<td>').text(d.Description),
            $('<td class="td-actions">').append(
                $('<button class="btn btn-sm btn-outline-danger btn-suppr" title="Supprimer"><i class="bi bi-trash3"></i></button>')
            )
        ).appendTo($body);
    });
}

function chargerListe() {
    $.get(`${JUG_BASE}/data`, function (res) {
        degres = res.ok ? res.data : [];
        afficher();
    }, 'json').fail(() => toast('Erreur de chargement.', false));
}

function ouvrirModal(d) {
    currentId = d ? d.Id_JugeArbitre : null;
    $('#modal-titre').text(d ? `Modifier le degré ${d.Code}` : 'Nouveau degré');
    // Code = nom d'une colonne de `ja` : saisissable seulement en création.
    $('#txt-code').val(d ? d.Code : '').prop('readonly', !!d);
    $('#txt-libelle').val(d ? d.Libelle : '');
    $('#txt-description').val(d ? d.Description : '');
    $('#form-err').text('');
    modal.show();
}

$('#btn-nouveau').on('click', () => ouvrirModal(null));

$('#tbody-liste').on('click', 'tr[data-id]', function (e) {
    const id = String($(this).data('id'));
    const d  = degres.find(x => String(x.Id_JugeArbitre) === id);
    if (!d) return;
    if ($(e.target).closest('.btn-suppr').length) {
        nijacConfirm(`Supprimer le degré ${d.Code} « ${d.Libelle} » ?`, function () {
            $.ajax({ url: `${JUG_BASE}/${id}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
                toast(res.msg, res.ok);
                if (res.ok) chargerListe();
            });
        }, null, { type: 'danger' });
        return;
    }
    ouvrirModal(d);
});

$('#form-degre').on('submit', function (e) {
    e.preventDefault();
    const payload = {
        code:        $('#txt-code').val().trim(),
        libelle:     $('#txt-libelle').val().trim(),
        description: $('#txt-description').val().trim(),
    };
    const isNew = currentId === null;
    $.ajax({
        url: isNew ? JUG_BASE : `${JUG_BASE}/${currentId}`,
        method: isNew ? 'POST' : 'PUT',
        data: payload,
        dataType: 'json',
    }).done(function (res) {
        if (res.ok) { toast(res.msg); modal.hide(); chargerListe(); }
        else $('#form-err').text(res.msg);
    }).fail(() => $('#form-err').text('Erreur réseau.'));
});

$('#txt-recherche').on('input', afficher);

// Différé : nijac-sortable-table.js / nijac-toast.js sont chargés après ce script.
$(function () {
    modal = new bootstrap.Modal(document.getElementById('modal-degre'));
    nijacSortableTable('#tbl-degres thead th[data-col]', 'col', sortState, afficher);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
