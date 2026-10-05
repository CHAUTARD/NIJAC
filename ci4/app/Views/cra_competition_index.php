<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Compétitions CRA (EC71)</title>
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
        /* Assez large pour « Tous niveaux JA » / « JAN JA3 » + la flèche. */
        #liste-header #sel-niveau { min-width: 12rem; }
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
        #lbl-count { display: inline-flex; align-items: center; justify-content: center; min-width: 2.6rem; text-align: center; padding: 0 .9rem; font-size: .82rem; }

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
        #table-wrapper table thead th.th-pk { color: #e65100; text-align: center; }
        #table-wrapper td.td-num { text-align: center; }
        #table-wrapper table thead th.th-num { text-align: center; white-space: nowrap; }
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
        #table-wrapper table thead th.th-niveau { min-width: 7.5rem; white-space: nowrap; }

        /* Résultats de la recherche club (modale). */
        #lst-clubs { max-height: 12rem; overflow-y: auto; }
        #lst-clubs .list-group-item { font-size: .85rem; padding: .3rem .6rem; }

        /* Modale : libellés comme EN11 (fw-semibold, taille Bootstrap). */
        .modal .form-label { font-size: 1rem; font-weight: 600; color: inherit; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar-event', 'phTitle' => 'Compétitions CRA', 'phCode' => 'EC71',
    'phCrumbLabel' => 'CRA Convoc', 'phCrumbUrl' => site_url('cra-convoc-menu'), 'phBackUrl' => site_url('cra-convoc-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">
    <div id="panel-liste">
        <div id="liste-header">
            <button class="btn btn-sm" id="btn-nouveau"><i class="bi bi-plus-circle me-1"></i>Nouvelle compétition</button>
            <span id="lbl-count" class="count-badge">0</span>
            <span class="combo-field ms-auto">
                <label for="sel-niveau">Niveau JA</label>
                <select id="sel-niveau">
                    <option value="">Tous niveaux JA</option>
                    <?php foreach ($niveaux as $n): ?>
                    <option value="<?= esc($n) ?>"><?= esc($n) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="combo-field">
                <label for="txt-recherche">Recherche</label>
                <input type="search" id="txt-recherche" placeholder="Rechercher (libellé, lieu, club)…">
            </span>
        </div>
        <div id="table-wrapper">
            <table id="tbl-competitions">
                <thead>
                    <tr>
                        <th class="th-pk" style="width:55px;" data-col="Numero">N°<span class="sort-icon"></span></th>
                        <th data-col="DateDebut">Dates<span class="sort-icon"></span></th>
                        <th data-col="Libelle">Libellé<span class="sort-icon"></span></th>
                        <th data-col="NbTablesMin">Tables<span class="sort-icon"></span></th>
                        <th data-col="Lieu">Lieu<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="CodeDept">Dépt<span class="sort-icon"></span></th>
                        <th data-col="Id_Club">Id club<span class="sort-icon"></span></th>
                        <th data-col="NomClub">Nom du club<span class="sort-icon"></span></th>
                        <th class="th-niveau" data-col="NiveauJA">Niveau JA<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="NbrJA" title="Nombre total de JA, adjoints compris">JA (total)<span class="sort-icon"></span></th>
                        <th class="th-num" data-col="NbrAdjoint" title="Nombre d'adjoints parmi le total de JA">dont adjoints<span class="sort-icon"></span></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="12" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-competition" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <form class="modal-content" id="form-competition">
            <div class="modal-header" style="background:#1a3a6b;color:#fff;">
                <h5 class="modal-title"><i class="bi bi-calendar-event me-2"></i><span id="modal-titre">Compétition</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-2">
                    <div class="col-3">
                        <label class="form-label" for="txt-numero">N° :</label>
                        <input type="number" id="txt-numero" class="form-control form-control-sm" min="1" required>
                    </div>
                    <div class="col">
                        <label class="form-label" for="txt-date-debut">Date de début :</label>
                        <input type="date" id="txt-date-debut" class="form-control form-control-sm" required>
                    </div>
                    <div class="col">
                        <label class="form-label" for="txt-date-fin">Date de fin :</label>
                        <input type="date" id="txt-date-fin" class="form-control form-control-sm">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="txt-libelle">Libellé :</label>
                    <input type="text" id="txt-libelle" class="form-control form-control-sm" maxlength="150" required>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col">
                        <label class="form-label" for="txt-tables-min">Tables min :</label>
                        <input type="number" id="txt-tables-min" class="form-control form-control-sm" min="1">
                    </div>
                    <div class="col">
                        <label class="form-label" for="txt-tables-max">Tables max :</label>
                        <input type="number" id="txt-tables-max" class="form-control form-control-sm" min="1">
                    </div>
                    <div class="col">
                        <label class="form-label" for="sel-niveau-ja">Niveau JA :</label>
                        <select id="sel-niveau-ja" class="form-select form-select-sm" required>
                            <option value=""></option>
                            <?php foreach ($niveaux as $n): ?>
                            <option value="<?= esc($n) ?>"><?= esc($n) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col">
                        <label class="form-label" for="txt-nbr-ja">JA (total) :</label>
                        <input type="number" id="txt-nbr-ja" class="form-control form-control-sm" min="1" max="20" required
                               title="Nombre total de JA, adjoints compris">
                    </div>
                    <div class="col">
                        <label class="form-label" for="txt-nbr-adjoint">dont adjoints :</label>
                        <input type="number" id="txt-nbr-adjoint" class="form-control form-control-sm" min="0" max="19" required
                               title="Adjoints parmi le total (au moins un JA principal)">
                    </div>
                    <div class="col-12 form-text mt-0">Ex. : 2 en tout dont 1 adjoint = 1 JA principal + 1 adjoint.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="txt-lieu">Lieu :</label>
                    <div class="input-group input-group-sm">
                        <input type="text" id="txt-lieu" class="form-control" maxlength="100" required>
                        <button type="button" class="btn btn-outline-secondary" id="btn-chercher-club" title="Rechercher le club à partir du Lieu">
                            <i class="bi bi-search me-1"></i>Rechercher le club
                        </button>
                    </div>
                    <div id="lst-clubs" class="list-group mt-1 d-none"></div>
                </div>
                <div class="row g-2 mb-2 align-items-end">
                    <div class="col-3">
                        <label class="form-label" for="txt-id-club">Id club :</label>
                        <input type="text" id="txt-id-club" class="form-control form-control-sm" readonly>
                    </div>
                    <div class="col">
                        <label class="form-label" for="txt-nom-club">Nom du club :</label>
                        <input type="text" id="txt-nom-club" class="form-control form-control-sm" readonly>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-danger btn-sm" id="btn-effacer-club" title="Effacer le club">
                            <i class="bi bi-x-circle me-1"></i>Effacer le club
                        </button>
                    </div>
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
const CRA_BASE = '<?= site_url('cra-competition') ?>';
let competitions = [];
let currentId = null;
let modal;
const sortState = { col: 'DateDebut', asc: true };

/** 'AAAA-MM-JJ' → 'JJ/MM/AAAA', et '14-15/11/2026' si même mois/année. */
function formatDates(d, f) {
    const [ya, ma, da] = d.split('-');
    if (!f || f === d) return `${da}/${ma}/${ya}`;
    const [yb, mb, db] = f.split('-');
    if (ya === yb && ma === mb) return `${da}-${db}/${ma}/${ya}`;
    return `${da}/${ma}/${ya} - ${db}/${mb}/${yb}`;
}

function formatTables(min, max) {
    if (min && max && min !== max) return `${min} à ${max}`;
    return min || max || '';
}

function afficher() {
    const q   = $('#txt-recherche').val().trim().toLowerCase();
    const niv = $('#sel-niveau').val();
    const col = sortState.col;
    const num = ['Numero', 'NbTablesMin', 'NbrJA', 'NbrAdjoint'].includes(col);
    const rows = competitions
        .filter(c => (!niv || c.NiveauJA === niv)
            && (!q || [c.Libelle, c.Lieu, c.CodeDept, c.Id_Club, c.NomClub].join(' ').toLowerCase().includes(q)))
        .sort((a, b) => {
            const cmp = num ? (Number(a[col]) || 0) - (Number(b[col]) || 0)
                            : String(a[col] ?? '').localeCompare(String(b[col] ?? ''), 'fr');
            return sortState.asc ? cmp : -cmp;
        });

    $('#lbl-count').text(rows.length);
    const $body = $('#tbody-liste').empty();
    if (!rows.length) {
        $body.append('<tr><td colspan="12" class="text-center text-muted py-3">Aucune compétition.</td></tr>');
        return;
    }
    rows.forEach(c => {
        $('<tr>').attr('data-id', c.Id_CRA_Competition).append(
            $('<td class="td-num">').html(`<code>${Number(c.Numero)}</code>`),
            $('<td class="nowrap">').text(formatDates(c.DateDebut, c.DateFin)),
            $('<td>').text(c.Libelle),
            $('<td class="nowrap">').text(formatTables(c.NbTablesMin, c.NbTablesMax)),
            $('<td>').text(c.Lieu),
            $('<td class="td-num">').text(c.CodeDept ?? ''),
            $('<td class="nowrap">').append(c.Id_Club ? $('<code>').text(c.Id_Club) : ''),
            $('<td>').text(c.NomClub ?? ''),
            $('<td class="nowrap">').text(c.NiveauJA),
            $('<td class="td-num">').text(c.NbrJA),
            $('<td class="td-num">').text(c.NbrAdjoint),
            $('<td class="td-actions">').append(
                $('<button class="btn btn-sm btn-outline-danger btn-suppr" title="Supprimer"><i class="bi bi-trash3"></i></button>')
            )
        ).appendTo($body);
    });
}

function chargerListe() {
    $.get(`${CRA_BASE}/data`, function (res) {
        competitions = res.ok ? res.data : [];
        afficher();
    }, 'json').fail(() => toast('Erreur de chargement.', false));
}

function ouvrirModal(c) {
    currentId = c ? c.Id_CRA_Competition : null;
    $('#modal-titre').text(c ? `Modifier la compétition n°${c.Numero}` : 'Nouvelle compétition');
    $('#txt-numero').val(c ? c.Numero : '');
    $('#txt-date-debut').val(c ? c.DateDebut : '');
    $('#txt-date-fin').val(c ? (c.DateFin ?? '') : '');
    $('#txt-libelle').val(c ? c.Libelle : '');
    $('#txt-tables-min').val(c ? (c.NbTablesMin ?? '') : '');
    $('#txt-tables-max').val(c ? (c.NbTablesMax ?? '') : '');
    $('#txt-lieu').val(c ? c.Lieu : '');
    $('#sel-niveau-ja').val(c ? c.NiveauJA : '');
    $('#txt-nbr-ja').val(c ? c.NbrJA : 1);
    $('#txt-nbr-adjoint').val(c ? c.NbrAdjoint : 0);
    choisirClub(c && c.Id_Club ? c.Id_Club : '', c && c.Id_Club ? (c.NomClub ?? '') : '');
    $('#form-err').text('');
    modal.show();
}

function choisirClub(id, nom) {
    $('#txt-id-club').val(id);
    $('#txt-nom-club').val(nom);
    $('#lst-clubs').addClass('d-none').empty();
}

$('#btn-chercher-club').on('click', function () {
    const q = $('#txt-lieu').val().trim();
    if (!q) { nijacToast('Saisissez d\'abord le Lieu.', 'warning'); return; }
    $.get(`${CRA_BASE}/clubs`, { q }, function (res) {
        if (!res.ok) { nijacToast(res.msg, 'warning'); return; }
        const exacts = res.data.filter(c => Number(c.exact));
        if (exacts.length === 1) { choisirClub(exacts[0].Id_Club, exacts[0].Nom); return; }
        if (!res.data.length) { $('#lst-clubs').addClass('d-none').empty(); nijacToast('Aucun club trouvé pour ce lieu', 'warning'); return; }
        const $lst = $('#lst-clubs').empty().removeClass('d-none');
        res.data.forEach(c => $('<button type="button" class="list-group-item list-group-item-action">')
            .data('club', c)
            .append($('<code class="me-2">').text(c.Id_Club), $('<span class="fw-semibold">').text(c.Nom),
                    c.Ville ? $('<span class="text-muted ms-2">').text(c.Ville) : '')
            .appendTo($lst));
    }, 'json').fail(() => nijacToast('Erreur lors de la recherche du club.', 'danger'));
});

$('#lst-clubs').on('click', '.list-group-item', function () {
    const c = $(this).data('club');
    choisirClub(c.Id_Club, c.Nom);
});

$('#btn-effacer-club').on('click', () => choisirClub('', ''));

$('#btn-nouveau').on('click', () => ouvrirModal(null));

$('#tbody-liste').on('click', 'tr[data-id]', function (e) {
    const id = String($(this).data('id'));
    const c  = competitions.find(x => String(x.Id_CRA_Competition) === id);
    if (!c) return;
    if ($(e.target).closest('.btn-suppr').length) {
        nijacConfirm(`Supprimer la compétition n°${c.Numero} « ${c.Libelle} » ?`, function () {
            $.ajax({ url: `${CRA_BASE}/${id}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
                toast(res.msg, res.ok);
                if (res.ok) chargerListe();
            });
        }, null, { type: 'danger' });
        return;
    }
    ouvrirModal(c);
});

$('#form-competition').on('submit', function (e) {
    e.preventDefault();
    const payload = {
        numero:        $('#txt-numero').val().trim(),
        date_debut:    $('#txt-date-debut').val(),
        date_fin:      $('#txt-date-fin').val(),
        libelle:       $('#txt-libelle').val().trim(),
        nb_tables_min: $('#txt-tables-min').val().trim(),
        nb_tables_max: $('#txt-tables-max').val().trim(),
        lieu:          $('#txt-lieu').val().trim(),
        id_club:       $('#txt-id-club').val(),
        niveau_ja:     $('#sel-niveau-ja').val(),
        nbr_ja:        $('#txt-nbr-ja').val().trim(),
        nbr_adjoint:   $('#txt-nbr-adjoint').val().trim(),
    };
    // NbrJA = total, adjoints compris : au moins un JA principal (même règle que le serveur).
    const nbJa = Number(payload.nbr_ja), nbAdj = Number(payload.nbr_adjoint);
    if (nbJa >= 1 && nbAdj > nbJa - 1) {
        $('#form-err').text(`Trop d'adjoints : « JA (total) » inclut les adjoints et il faut au moins un JA principal, donc au plus ${nbJa - 1} adjoint(s) pour ${nbJa} JA en tout.`);
        return;
    }
    const isNew = currentId === null;
    $.ajax({
        url: isNew ? CRA_BASE : `${CRA_BASE}/${currentId}`,
        method: isNew ? 'POST' : 'PUT',
        data: payload,
        dataType: 'json',
    }).done(function (res) {
        if (res.ok) { toast(res.msg); modal.hide(); chargerListe(); }
        else $('#form-err').text(res.msg);
    }).fail(() => $('#form-err').text('Erreur réseau.'));
});

$('#txt-recherche').on('input', afficher);
$('#sel-niveau').on('change', afficher);

// Différé : nijac-sortable-table.js / nijac-toast.js sont chargés après ce script.
$(function () {
    modal = new bootstrap.Modal(document.getElementById('modal-competition'));
    nijacSortableTable('#tbl-competitions thead th[data-col]', 'col', sortState, afficher);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
