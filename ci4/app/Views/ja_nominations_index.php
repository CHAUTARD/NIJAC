<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Mes nominations (EJA3)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
        body { background: #f0f4fa; font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; display: flex; flex-direction: column; }
        #page-header { background: #8d6e00; color: #fff; padding: .5rem 1.25rem; font-size: .9rem; font-weight: 600; }
        #toolbar { background: #f8fafc; border-bottom: 1px solid #dde5f0; padding: .3rem 1rem; display: flex; align-items: center; justify-content: space-between; font-size: .85rem; gap: .75rem; }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; align-items: center; gap: .35rem; color: #c00; font-weight: 700; cursor: pointer; text-decoration: underline dotted; }
        #contenu { flex: 1; padding: 1rem; }
        #tbl-nominations { font-size: .85rem; background: #fff; }
        #tbl-nominations th { background: #fff3cd; white-space: nowrap; }
        #tbl-nominations tr.a-venir td { background: #f1f8e9; }
        #tbl-nominations thead th[data-col] { cursor: pointer; user-select: none; white-space: nowrap; }
        #tbl-nominations thead th[data-col]::after { content: ' \2195'; opacity: .35; font-size: .8em; }
        #tbl-nominations thead th.sort-asc::after  { content: ' \25B2'; opacity: 1; }
        #tbl-nominations thead th.sort-desc::after { content: ' \25BC'; opacity: 1; }
        .badge-forfait { background: #fd7e14; }
        #page-footer { background: #e8eef7; border-top: 1px solid #c8d4e8; padding: .25rem 1rem; font-size: .8rem; display: flex; justify-content: center; align-items: center; flex-shrink: 0; }
        #page-footer.pf-status-left { display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; }
        #page-footer.pf-status-left #status-bar { grid-column: 1; justify-self: start; }
        #page-footer.pf-status-left .footer-copyright { grid-column: 2; justify-self: center; }
        .footer-copyright { color: #6b7280; white-space: nowrap; }
        .footer-logo { height: 20px; width: auto; opacity: .75; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'list-check', 'phTitle' => 'Mes nominations', 'phCode' => 'EJA3',
    'phCrumbLabel' => 'Menu JA', 'phCrumbUrl' => site_url('ja-menu'), 'phBackUrl' => site_url('ja-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="contenu">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="fw-bold" style="color:#1a3a6b;">Toutes mes nominations</span>
        <span class="small text-muted">(lecture seule — la saisie des frais se fait dans « Mes frais » EJA4)</span>
        <select id="cbo-periode" class="form-select form-select-sm ms-auto" style="width:auto;">
            <option value="">Toutes</option>
            <option value="avenir">À venir</option>
            <option value="passees">Passées</option>
        </select>
    </div>
    <div class="table-responsive">
        <table id="tbl-nominations" class="table table-sm table-bordered table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th data-col="0">Date</th>
                    <th data-col="1">Compétition</th>
                    <th data-col="2">Division</th>
                    <th data-col="3">Rencontre</th>
                    <th data-col="4">Club recevant</th>
                    <th data-col="5">Arbitrage</th>
                    <th data-col="6">Accusé de réception</th>
                    <th data-col="7" class="text-end">Péage</th>
                    <th data-col="8" class="text-end">Km</th>
                    <th data-col="9" class="text-center">Défisc.</th>
                    <th data-col="10">Justificatif</th>
                </tr>
            </thead>
            <tbody id="tbody-liste">
                <tr><td colspan="11" class="text-center text-muted py-3">Chargement…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
<script>
'use strict';
const BASE = <?= json_encode(site_url('ja/nominations')) ?>;
const AUJOURDHUI = <?= json_encode(date('Y-m-d')) ?>;
let lignes = [];
// Tri par défaut : date croissante (plus ancienne → plus future), comme l'ordre renvoyé par le serveur.
const sortState = { col: '0', asc: true };

function dateFr(d) { return d ? d.substring(8, 10) + '/' + d.substring(5, 7) + '/' + d.substring(0, 4) : ''; }
function dateHeureFr(dt) { return dt ? dateFr(dt.substring(0, 10)) + ' ' + dt.substring(11, 16) : ''; }
function montant(v) { return v === null || v === '' ? '' : Number(v).toFixed(2).replace('.', ',') + ' €'; }

function afficher() {
    const periode = $('#cbo-periode').val();
    const $body = $('#tbody-liste').empty();
    const filtrees = lignes.filter(n => !periode
        || (periode === 'avenir' && n.Date >= AUJOURDHUI)
        || (periode === 'passees' && n.Date < AUJOURDHUI));
    if (!filtrees.length) {
        $body.append('<tr><td colspan="11" class="text-center text-muted py-3">Aucune nomination.</td></tr>');
        return;
    }
    filtrees.forEach(n => {
        const forfait = parseInt(n.Forfait, 10) || 0;
        const saisi = n.DateSaisie, num = v => (v === null || v === undefined || v === '' ? '' : String(Number(v)));
        const $renc = $('<td>').text((n.NomDom || '') + ' / ' + (n.NomExt || '?'));
        if (forfait) $renc.append(' ', $('<span class="badge badge-forfait">').text('FORFAIT'));
        const cra = parseInt(n.ArbitrageCRA, 10) === 1;
        const defisc = cra && saisi && parseInt(n.Defiscalisation, 10) ? 1 : 0;
        $body.append($('<tr>').attr('data-id', n.Id_Nomination).toggleClass('a-venir', n.Date >= AUJOURDHUI).append(
            $('<td>').attr('data-sort', (n.Date || '') + ' ' + (n.Heure || '')).text(dateFr(n.Date) + (n.Heure ? ' ' + n.Heure : '')),
            $('<td>').text(n.DivisionNom || ''),
            $('<td>').text(n.DivisionCode || ''),
            $renc.attr('data-sort', (n.NomDom || '') + ' / ' + (n.NomExt || '')).prepend(n.Journee ? $('<span class="text-muted me-1">').text('J' + n.Journee) : ''),
            $('<td>').text(n.ClubRecevant || ''),
            $('<td>').text(cra ? 'CRA' : 'Club'),
            $('<td>').attr('data-sort', n.AccuseReception || '').text(n.AccuseReception ? 'Le ' + dateHeureFr(n.AccuseReception) : '—'),
            $('<td class="text-end">').attr('data-sort', cra && saisi ? num(n.Peage) : '').text(cra && saisi ? montant(n.Peage) : ''),
            $('<td class="text-end">').attr('data-sort', cra && saisi ? num(n.Kilometre) : '').text(cra && saisi ? (n.Kilometre ?? '') : ''),
            $('<td class="text-center">').attr('data-sort', String(defisc)).text(defisc ? '✔' : ''),
            $('<td>').text(n.Justificatif || '')
        ));
    });
    trierLignes();
}

// ── Tri (clic sur les en-têtes, nijac-sortable-table.js) : valeur data-sort si présente (dates ISO,
// nombres), sinon texte ; comparaison numérique si les deux valeurs sont des nombres. ──
function cleTri(td) {
    if (!td) return '';
    return td.hasAttribute('data-sort') ? td.getAttribute('data-sort') : td.textContent.trim();
}
function trierLignes() {
    const col = parseInt(sortState.col, 10);
    if (isNaN(col)) return;
    const tbody = document.getElementById('tbody-liste');
    const rows = Array.from(tbody.children).filter(tr => tr.hasAttribute('data-id'));
    rows.sort((a, b) => {
        const va = cleTri(a.children[col]), vb = cleTri(b.children[col]);
        const na = Number(va), nb = Number(vb);
        const cmp = (va !== '' && vb !== '' && !isNaN(na) && !isNaN(nb)) ? na - nb : va.localeCompare(vb, 'fr');
        return sortState.asc ? cmp : -cmp;
    });
    rows.forEach(tr => tbody.appendChild(tr));
}


$('#cbo-periode').on('change', afficher);

$(function () {
    nijacSortableTable('#tbl-nominations thead th[data-col]', 'col', sortState, trierLignes);
    $.getJSON(`${BASE}/data`, function (r) {
        if (!r.ok) { nijacToast(r.msg || 'Erreur de chargement.', 'danger'); return; }
        lignes = r.data || [];
        afficher();
    }).fail(() => nijacToast('Erreur réseau.', 'danger'));
});
</script>
</body>
</html>
