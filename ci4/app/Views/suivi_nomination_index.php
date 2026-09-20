<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Suivi des nominations (EN28)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        /* En-tête vert, comme les autres écrans nominateur (E003) */
        #page-header { background: #2e7d32; }

        #panel-liste { width: 100%; }

        /* Bandeau de filtres : comboboxes « label en encoche » comme EN23 */
        #menu-strip {
            --strip-bg: #f8fafc;
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .4rem .75rem;
            flex-wrap: wrap;
        }
        #menu-strip .btn { margin-top: .6rem; border-radius: 999px; }
        #menu-strip #lbl-count {
            margin-left: 0; margin-top: .6rem;
            height: 2.15rem; padding: 0 .9rem;
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 5.5rem;
            background: #eef2f9; border: 1.5px solid #d3dae6; border-radius: 999px;
            font-size: .82rem; font-weight: 700; color: var(--nijac-blue);
        }

        #tbl-suivi td.num, #tbl-suivi th.num { text-align: right; }
        #tbl-suivi td.centre, #tbl-suivi th.centre { text-align: center; }
        #tbl-suivi .non-saisi { color: #9aa5b8; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'clipboard2-check', 'phTitle' => 'Suivi des nominations', 'phCode' => 'EN28',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">
    <div id="panel-liste">
        <div id="menu-strip">
            <span class="count-badge" id="lbl-count">0 / 0</span>
            <span style="flex:1"></span>
            <span class="combo-field">
                <label for="sel-date">Date</label>
                <select id="sel-date" style="width:auto;">
                    <option value="">Toutes</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="search-equipe">Équipe</label>
                <input type="search" id="search-equipe" placeholder="Équipe…" style="width:220px;">
            </span>
            <span class="combo-field">
                <label for="search-ja">JA</label>
                <input type="search" id="search-ja" placeholder="Nom du JA…" style="width:200px;">
            </span>
            <span class="combo-field">
                <label for="sel-km">Kilométrage</label>
                <select id="sel-km" style="width:150px;">
                    <option value="">Tous</option>
                    <option value="sans">Sans kilométrage</option>
                </select>
            </span>
            <button type="button" class="btn btn-sm btn-light" id="btn-reset-filtres" title="Réinitialiser les filtres">
                <i class="bi bi-x-circle"></i>
            </button>
        </div>
        <div id="table-wrapper">
            <table id="tbl-suivi">
                <thead>
                    <tr>
                        <th style="width:130px" data-field="date">Date<span class="sort-icon"></span></th>
                        <th data-field="domicile">Domicile<span class="sort-icon"></span></th>
                        <th data-field="exterieur">Extérieur<span class="sort-icon"></span></th>
                        <th data-field="ja">JA<span class="sort-icon"></span></th>
                        <th class="num" style="width:90px" data-field="peage">Péage<span class="sort-icon"></span></th>
                        <th class="num" style="width:90px" data-field="km">Km<span class="sort-icon"></span></th>
                        <th class="centre" style="width:110px" data-field="defisc">Défisc.<span class="sort-icon"></span></th>
                        <th class="centre" style="width:110px" data-field="saisie">Date saisie<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px">Rappel</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="9" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const SUIVI_BASE = '<?= site_url('suivi-nomination') ?>';
let nominations = [];
const filtres   = { date: '', equipe: '', ja: '', km: '' };
const sortState = { col: null, asc: true };

const JOURS_SEMAINE = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

/** "YYYY-MM-DD" → "Samedi 19/09/2026" (abrégé : "Sam 19/09/2026"). Construction via composants locaux (pas de décalage UTC). */
function formatDateAvecJour(dateStr, abrege = false) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.substring(0, 10).split('-').map(Number);
    const jour = JOURS_SEMAINE[new Date(y, m - 1, d).getDay()].substring(0, abrege ? 3 : undefined);
    return `${jour} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

function nominationsFiltrees() {
    const equipe = filtres.equipe.toLowerCase();
    const ja     = filtres.ja.toLowerCase();
    return nominations.filter(n => {
        if (filtres.date && (n.Date ?? '').substring(0, 10) !== filtres.date) return false;
        if (equipe
            && !String(n.NomDom ?? '').toLowerCase().includes(equipe)
            && !String(n.NomExt ?? '').toLowerCase().includes(equipe)) return false;
        if (ja && !String(n.NomJa ?? '').toLowerCase().includes(ja)) return false;
        // « Sans kilométrage » : frais non saisis (DateSaisie NULL). 0 km = départ du domicile, valeur valide.
        if (filtres.km === 'sans' && n.DateSaisie) return false;
        return true;
    });
}

// Clé de tri par colonne — sur les données (pas sur le texte des cellules : la date
// affichée « Samedi 19/09/2026 » ne se trierait pas chronologiquement).
const CLES_TRI = {
    date:      n => (n.Date ?? '') + (n.Heure ?? ''),
    domicile:  n => n.NomDom ?? '',
    exterieur: n => n.NomExt ?? '',
    ja:        n => n.NomJa ?? '',
    peage:     n => n.DateSaisie ? +n.Peage : -1,
    km:        n => n.DateSaisie ? +n.Kilometre : -1,
    defisc:    n => n.DateSaisie ? +n.Defiscalisation : -1,
    saisie:    n => n.DateSaisie ?? '',
};

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = nominationsFiltrees();
    const cle = CLES_TRI[sortState.col];
    if (cle) {
        affichees.sort((a, b) => {
            const va = cle(a), vb = cle(b);
            const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb), 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    }
    $('#lbl-count').text(`${affichees.length} / ${nominations.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="9" class="text-center text-muted py-3">Aucune nomination.</td></tr>');
        return;
    }

    affichees.forEach(n => {
        // DateSaisie NULL = le JA n'a encore rien saisi dans EN21 : pas de valeurs à afficher
        const saisi = !!n.DateSaisie;
        const $rappel = $('<button type="button" class="btn btn-sm btn-outline-primary btn-rappel">')
            .attr('title', n.EmailJa ? 'Envoyer un message de rappel au JA' : 'JA sans adresse email')
            .prop('disabled', !n.EmailJa)
            .html('<i class="bi bi-envelope"></i>')
            .on('click', function () { envoyerRappel(n, $(this)); });
        $('<tr>').append(
            $('<td>').attr('data-field', 'date').text(formatDateAvecJour(n.Date, true)),
            $('<td>').attr('data-field', 'domicile').text(n.NomDom ?? ''),
            $('<td>').attr('data-field', 'exterieur').text(n.NomExt ?? '—'),
            $('<td>').attr('data-field', 'ja').text(n.NomJa ?? ''),
            $('<td class="num">').attr('data-field', 'peage').toggleClass('non-saisi', !saisi)
                .text(saisi ? Number(n.Peage ?? 0).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €' : '—'),
            $('<td class="num">').attr('data-field', 'km').toggleClass('non-saisi', !saisi)
                .text(saisi ? (n.Kilometre ?? 0) : '—'),
            $('<td class="centre">').attr('data-field', 'defisc').toggleClass('non-saisi', !saisi)
                .text(saisi ? (+n.Defiscalisation ? 'Oui' : 'Non') : '—'),
            $('<td class="centre">').attr('data-field', 'saisie').toggleClass('non-saisi', !saisi)
                .text(saisi ? n.DateSaisie.substring(0, 10).split('-').reverse().join('/') : '—'),
            $('<td class="centre">').append($rappel)
        ).appendTo($body);
    });
}

function envoyerRappel(n, $btn) {
    nijacConfirm(`Envoyer un message de rappel à ${n.NomJa} ?`, function () {
        $btn.prop('disabled', true);
        $.post(`${SUIVI_BASE}/rappel`, { id_nomination: n.Id_Nomination }, function (r) {
            toast(r.msg, !!r.ok);
            $btn.prop('disabled', false);
        }, 'json').fail(function () {
            toast('Erreur réseau.', false);
            $btn.prop('disabled', false);
        });
    });
}

function chargerListe() {
    $.get(`${SUIVI_BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        nominations = res.nominations;
        const dates = [...new Set(nominations.map(n => (n.Date ?? '').substring(0, 10)).filter(Boolean))].sort();
        const $sel = $('#sel-date');
        $sel.find('option:not(:first)').remove();
        dates.forEach(d => $sel.append(new Option(formatDateAvecJour(d), d)));
        $sel.val(filtres.date);
        renderListe();
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

$('#sel-date').on('change', function () { filtres.date = $(this).val(); renderListe(); });
$('#sel-km').on('change', function () { filtres.km = $(this).val(); renderListe(); });
$('#search-equipe').on('input', function () { filtres.equipe = $(this).val().trim(); renderListe(); });
$('#search-ja').on('input', function () { filtres.ja = $(this).val().trim(); renderListe(); });
$('#btn-reset-filtres').on('click', function () {
    filtres.date = filtres.equipe = filtres.ja = filtres.km = '';
    $('#sel-date, #sel-km, #search-equipe, #search-ja').val('');
    renderListe();
});

$(function () {
    nijacSortableTable('#tbl-suivi thead th[data-field]', 'field', sortState, renderListe);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
