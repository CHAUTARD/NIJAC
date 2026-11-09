<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Mes nominations et frais (EJA4)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-chrome.css') ?>">
    <style>
        :root { --ph-bg: #8d6e00; }
        body { background: #fff8e1; font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; display: flex; flex-direction: column; }
        #toolbar { gap: .75rem; }
        #contenu { flex: 1; padding: 1rem; }
        #tbl-frais { font-size: .85rem; background: #fff; }
        #tbl-frais th { background: #fff3cd; white-space: nowrap; }
        #tbl-frais .etat { font-size: .75rem; }
        #tbl-frais tr.a-venir td { background: #f1f8e9; }
        .badge-forfait { background: #fd7e14; }
        #tbl-frais thead th[data-col] { cursor: pointer; user-select: none; white-space: nowrap; }
        #tbl-frais thead th[data-col]::after { content: ' \2195'; opacity: .35; font-size: .8em; }
        #tbl-frais thead th.sort-asc::after  { content: ' \25B2'; opacity: 1; }
        #tbl-frais thead th.sort-desc::after { content: ' \25BC'; opacity: 1; }
    .pill-non { display:inline-block; background:#c62828; color:#fff; border:1px solid #8e0000; border-radius:999px; padding:.1rem .8rem; font-weight:600; }
</style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'cash-coin', 'phTitle' => 'Mes nominations et frais', 'phCode' => 'EJA4',
    'phCrumbLabel' => 'Menu JA', 'phCrumbUrl' => site_url('ja-menu'), 'phBackUrl' => site_url('ja-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<div id="contenu">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <div class="small text-muted">
            Toutes mes nominations, passées et à venir. Mêmes règles que le lien de convocation (EN21) :
            saisie des frais à partir du jour de la rencontre (le jour même, après l'accusé de réception).
            Indemnité forfaitaire <?= number_format($indemniteForfait, 2, ',', ' ') ?> € et
            <?= number_format($tauxKm, 2, ',', ' ') ?> €/km (arbitrage CRA uniquement).
        </div>
        <select id="cbo-periode" class="form-select form-select-sm ms-auto" style="width:auto;">
            <option value="">Toutes</option>
            <option value="avenir">À venir</option>
            <option value="passees">Passées</option>
        </select>
    </div>
    <div class="table-responsive">
        <table id="tbl-frais" class="table table-sm table-bordered table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th data-col="0">Date</th>
                    <th data-col="1">Division</th>
                    <th data-col="2">Rencontre</th>
                    <th data-col="3">Arbitrage</th>
                    <th data-col="4">Accusé de réception</th>
                    <th data-col="5" class="text-end">Péage</th>
                    <th data-col="6" class="text-end">Km</th>
                    <th data-col="7" class="text-end">Frais kilométriques</th>
                    <th data-col="8" class="text-end">Prestation</th>
                    <th data-col="9" class="text-end">Total estimé</th>
                    <th data-col="10" class="text-center">Défisc.</th>
                    <th data-col="11" class="text-center">Justificatif</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tbody-liste">
                <tr><td colspan="13" class="text-center text-muted py-3">Chargement…</td></tr>
            </tbody>
            <tfoot>
                <tr id="ligne-totaux" class="fw-bold table-warning">
                    <td colspan="5" class="text-end">Totaux (rencontres passées)</td>
                    <td class="text-end" id="tot-peage"></td>
                    <td class="text-end" id="tot-km"></td>
                    <td class="text-end" id="tot-fraiskm"></td>
                    <td class="text-end" id="tot-prestation"></td>
                    <td class="text-end" id="tot-total"></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
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
const BASE      = <?= json_encode(site_url('ja/frais')) ?>;
const INDEMNITE = <?= json_encode($indemniteForfait) ?>;
const TAUX_KM   = <?= json_encode($tauxKm) ?>;
const AUJOURDHUI = <?= json_encode(date('Y-m-d')) ?>;
let lignes = [], justifDispo = false;
// Tri par défaut : date croissante (plus ancienne → plus future), comme l'ordre renvoyé par le serveur.
const sortState = { col: '0', asc: true };

function dateFr(d) { return d ? d.substring(8, 10) + '/' + d.substring(5, 7) + '/' + d.substring(0, 4) : ''; }
function dateHeureFr(dt) { return dt ? dateFr(dt.substring(0, 10)) + ' ' + dt.substring(11, 16) : ''; }
function euros(v) { return Number(v || 0).toFixed(2).replace('.', ',') + ' €'; }
function libRencontre(n) { return (n.Journee ? 'J' + n.Journee + ' ' : '') + (n.NomDom || '') + ' / ' + (n.NomExt || '?'); }
function estCra(n) { return parseInt(n.ArbitrageCRA, 10) === 1; }
function urlVoir(n) { return `${BASE}/justificatif/voir?id_nomination=${encodeURIComponent(n.Id_Nomination)}`; }

function afficher() {
    const periode = $('#cbo-periode').val();
    const $body = $('#tbody-liste').empty();
    const filtrees = lignes.filter(n => !periode
        || (periode === 'avenir' && n.Date >= AUJOURDHUI)
        || (periode === 'passees' && n.Date < AUJOURDHUI));
    majTotaux(filtrees);
    if (!filtrees.length) {
        $body.append('<tr><td colspan="13" class="text-center text-muted py-3">Aucune nomination.</td></tr>');
        return;
    }
    filtrees.forEach(n => {
        const cra = estCra(n), saisi = !!n.DateSaisie;
        const etat = n.EtatFrais || {};
        const $accuse = $('<td>').attr('data-sort', n.AccuseReception || '');
        if (n.AccuseReception) {
            $accuse.text('Le ' + dateHeureFr(n.AccuseReception));
        } else {
            $accuse.text('—');
        }
        const $justif = $('<td class="text-center">');
        if (n.Justificatif) {
            $justif.append($('<a target="_blank" rel="noopener noreferrer">').attr('href', urlVoir(n)).text(n.Justificatif));
        } else if (cra && saisi && Number(n.Peage || 0) > 0) {
            $justif.append($('<span class="pill-non">').text('Non'));
        }
        const $actions = $('<td class="text-nowrap">');
        $actions.append(
            $('<a class="btn btn-sm btn-outline-secondary py-0" target="_blank" rel="noopener noreferrer" data-bs-toggle="tooltip" data-bs-title="Ouvrir la convocation (EN21) pour saisir ou modifier les frais et joindre le justificatif de péage">')
                .attr('href', n.UrlConvocation).html('<i class="bi bi-box-arrow-up-right"></i>'));
        if (!etat.ok && etat.err) $actions.append($('<span class="etat text-muted ms-2">').text(etat.err.replace(/^La saisie des frais sera possible à partir du /, 'Saisir les frais à partir du ')));
        if (etat.ok) $actions.append($('<span class="etat text-muted ms-2">').text(saisi ? 'Modifier les frais' : 'Saisir les frais'));
        if (justifDispo && cra && saisi && Number(n.Peage || 0) > 0 && !n.Justificatif) {
            $actions.append($('<span class="etat text-danger fw-bold ms-2">')
                .html('<i class="bi bi-exclamation-circle me-1"></i>Justificatif de péage manquant.'));
        }
        const passee = n.Date <= AUJOURDHUI; // rencontre pas encore jouée (date > jour) : pas de total estimé
        const kmAff = n.KilometreAffiche, fraisKm = Number(kmAff || 0) * TAUX_KM; // km ajustés côté serveur (1re prestation du jour)
        const total = cra && passee ? INDEMNITE + Number(n.Peage || 0) + fraisKm : 0;
        const defisc = cra && saisi && parseInt(n.Defiscalisation, 10) ? 1 : 0;
        const $renc = $('<td>').attr('data-sort', (n.NomDom || '') + ' / ' + (n.NomExt || '')).text(libRencontre(n));
        if (parseInt(n.Forfait, 10) === 1 || parseInt(n.Forfait, 10) === 2) $renc.append(' ', $('<span class="badge badge-forfait">').text('FORFAIT'));
        $body.append($('<tr>').attr('data-id', n.Id_Nomination).toggleClass('a-venir', n.Date >= AUJOURDHUI).append(
            $('<td>').attr('data-sort', (n.Date || '') + ' ' + (n.Heure || '')).text(dateFr(n.Date) + (n.Heure ? ' ' + n.Heure : '')),
            $('<td>').text(n.DivisionCode || ''),
            $renc,
            $('<td>').text(cra ? 'CRA' : 'Club'),
            $accuse,
            $('<td class="text-end">').attr('data-sort', cra && saisi ? String(Number(n.Peage || 0)) : '').text(cra && saisi ? euros(n.Peage) : ''),
            $('<td class="text-end">').attr('data-sort', cra && saisi && kmAff !== null ? String(Number(kmAff)) : '').text(cra && saisi ? (kmAff ?? '') : ''),
            $('<td class="text-end">').attr('data-sort', cra && passee ? fraisKm.toFixed(2) : '').text(cra && passee ? euros(fraisKm) : '—'),
            $('<td class="text-end">').attr('data-sort', cra && passee ? String(INDEMNITE) : '').text(cra && passee ? euros(INDEMNITE) : '—'),
            $('<td class="text-end">').attr('data-sort', cra && passee ? total.toFixed(2) : '').text(cra && passee ? euros(total) : '—'),
            $('<td class="text-center">').attr('data-sort', String(defisc)).text(defisc ? '✔' : ''),
            $justif,
            $actions
        ));
    });
    trierLignes();
    document.querySelectorAll('#tbody-liste [data-bs-toggle="tooltip"]').forEach(el => bootstrap.Tooltip.getOrCreateInstance(el));
}

// Totaux (tfoot, hors tri) : rencontres passées seulement (date <= jour), sur les lignes affichées.
// Péage / km : arbitrage CRA avec frais saisis ; total estimé : arbitrage CRA passé (même calcul que la colonne).
function majTotaux(affichees) {
    let peage = 0, km = 0, fraisKm = 0, prestation = 0, total = 0;
    affichees.forEach(n => {
        if (n.Date > AUJOURDHUI || !estCra(n)) return;
        const fk = Number(n.KilometreAffiche || 0) * TAUX_KM;
        fraisKm += fk;
        prestation += INDEMNITE;
        total += INDEMNITE + Number(n.Peage || 0) + fk;
        if (n.DateSaisie) { peage += Number(n.Peage || 0); km += Number(n.KilometreAffiche || 0); }
    });
    $('#tot-peage').text(euros(peage));
    $('#tot-km').text(km);
    $('#tot-fraiskm').text(euros(fraisKm));
    $('#tot-prestation').text(euros(prestation));
    $('#tot-total').text(euros(total));
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


function charger(rouvrirId = null) {
    $.getJSON(`${BASE}/data`, function (r) {
        if (!r.ok) { nijacToast(r.msg || 'Erreur de chargement.', 'danger'); return; }
        lignes = r.data || [];
        justifDispo = !!r.justificatifDispo;
        afficher();
    }).fail(() => nijacToast('Erreur réseau.', 'danger'));
}


$('#cbo-periode').on('change', afficher);

$(() => {
    nijacSortableTable('#tbl-frais thead th[data-col]', 'col', sortState, trierLignes);
    charger();
});
</script>
</body>
</html>
