<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Mes frais (EJA4)</title>
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
        #tbl-frais { font-size: .85rem; background: #fff; }
        #tbl-frais th { background: #fff3cd; white-space: nowrap; }
        #tbl-frais .etat { font-size: .75rem; }
        #tbl-frais thead th[data-col] { cursor: pointer; user-select: none; white-space: nowrap; }
        #tbl-frais thead th[data-col]::after { content: ' \2195'; opacity: .35; font-size: .8em; }
        #tbl-frais thead th.sort-asc::after  { content: ' \25B2'; opacity: 1; }
        #tbl-frais thead th.sort-desc::after { content: ' \25BC'; opacity: 1; }
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
    'phIcon' => 'cash-coin', 'phTitle' => 'Mes frais', 'phCode' => 'EJA4',
    'phCrumbLabel' => 'Menu JA', 'phCrumbUrl' => site_url('ja-menu'), 'phBackUrl' => site_url('ja-menu'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="contenu">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <div class="small text-muted">
            Mêmes règles que le lien de convocation (EN21) : accusé de réception possible jusqu'au jour de la rencontre ;
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
                    <th data-col="1">Rencontre</th>
                    <th data-col="2">Arbitrage</th>
                    <th data-col="3">Accusé de réception</th>
                    <th data-col="4" class="text-end">Péage</th>
                    <th data-col="5" class="text-end">Km</th>
                    <th data-col="6" class="text-center">Défisc.</th>
                    <th data-col="7" class="text-end">Total estimé</th>
                    <th data-col="8">Justificatif</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tbody-liste">
                <tr><td colspan="10" class="text-center text-muted py-3">Chargement…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Modale de saisie des frais ── -->
<div class="modal fade" id="modal-frais" tabindex="-1" aria-labelledby="modal-frais-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:#8d6e00;color:#fff;">
                <h5 class="modal-title fs-6" id="modal-frais-titre">Frais</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div id="mf-rencontre" class="fw-bold mb-2" style="color:#1a3a6b;"></div>
                <div id="mf-bloc-cra">
                    <div class="row g-2 mb-2">
                        <div class="col">
                            <label class="form-label small" for="mf-peages">Péages (€)</label>
                            <input type="text" id="mf-peages" class="form-control form-control-sm" inputmode="decimal" placeholder="0,00">
                            <div class="form-text small">Maximum <?= number_format($maxPeage, 2, ',', ' ') ?> €</div>
                        </div>
                        <div class="col">
                            <label class="form-label small" for="mf-km">Kilomètres</label>
                            <input type="number" id="mf-km" class="form-control form-control-sm" min="0" max="<?= (int) $maxKm ?>" step="1">
                            <div class="form-text small">Maximum <?= (int) $maxKm ?> km</div>
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="mf-defisc">
                        <label class="form-check-label" for="mf-defisc">Je demande la défiscalisation de ces frais</label>
                    </div>
                </div>
                <div id="mf-info-club" class="alert alert-info py-1 px-2 small d-none">
                    Arbitrage club : ni indemnité, ni péage, ni kilomètres — seul le rapport est à saisir.
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="mf-rap-acc">Rapport — accueil</label>
                    <textarea id="mf-rap-acc" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label small" for="mf-rap-eq">Rapport — équipements</label>
                    <textarea id="mf-rap-eq" class="form-control form-control-sm" rows="2" maxlength="1000"></textarea>
                </div>

                <!-- Justificatif de péage (CRA, Peage > 0 enregistré, colonne présente) -->
                <div id="mf-bloc-justif" class="border rounded p-2 bg-light d-none">
                    <div class="small fw-bold mb-1"><i class="bi bi-paperclip me-1"></i>Justificatif de péage</div>
                    <div id="mf-justif-etat" class="small text-muted mb-1"></div>
                    <div class="input-group input-group-sm">
                        <input type="file" id="mf-justif-fichier" class="form-control" accept="image/jpeg,image/png,image/gif,image/webp,application/pdf">
                        <button type="button" class="btn btn-outline-secondary" id="mf-justif-envoyer">Joindre</button>
                    </div>
                    <div class="form-text small">Image ou PDF, 5 Mo maximum. Enregistrez d'abord le montant du péage.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-sm btn-primary" id="mf-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
            </div>
        </div>
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
let lignes = [], justifDispo = false, accuseDispo = false, courante = null;
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
    if (!filtrees.length) {
        $body.append('<tr><td colspan="10" class="text-center text-muted py-3">Aucune nomination.</td></tr>');
        return;
    }
    filtrees.forEach(n => {
        const cra = estCra(n), saisi = !!n.DateSaisie;
        const etat = n.EtatFrais || {};
        const $accuse = $('<td>').attr('data-sort', n.AccuseReception || '');
        if (n.AccuseReception) {
            $accuse.text('Le ' + dateHeureFr(n.AccuseReception));
        } else if (accuseDispo && n.Accusable) {
            $accuse.append($('<button type="button" class="btn btn-sm btn-outline-success py-0 btn-accuser">')
                .attr('data-id', n.Id_Nomination).html('<i class="bi bi-check2-circle me-1"></i>Accuser réception'));
        } else {
            $accuse.text('—');
        }
        const $justif = $('<td>');
        if (n.Justificatif) {
            $justif.append($('<a target="_blank" rel="noopener noreferrer">').attr('href', urlVoir(n)).text(n.Justificatif));
        }
        const $actions = $('<td class="text-nowrap">');
        const $btn = $('<button type="button" class="btn btn-sm btn-primary py-0 me-1 btn-saisir">')
            .attr('data-id', n.Id_Nomination).html('<i class="bi bi-pencil-square me-1"></i>' + (saisi ? 'Modifier' : 'Saisir'))
            .prop('disabled', !etat.ok);
        $actions.append($btn,
            $('<a class="btn btn-sm btn-outline-secondary py-0" target="_blank" rel="noopener noreferrer" title="Ouvrir la convocation (EN21)">')
                .attr('href', n.UrlConvocation).html('<i class="bi bi-box-arrow-up-right"></i>'));
        if (!etat.ok && etat.err) $actions.append($('<div class="etat text-muted">').text(etat.err));
        const total = cra ? INDEMNITE + Number(n.Peage || 0) + Number(n.Kilometre || 0) * TAUX_KM : 0;
        const defisc = cra && saisi && parseInt(n.Defiscalisation, 10) ? 1 : 0;
        $body.append($('<tr>').attr('data-id', n.Id_Nomination).append(
            $('<td>').attr('data-sort', (n.Date || '') + ' ' + (n.Heure || '')).text(dateFr(n.Date) + (n.Heure ? ' ' + n.Heure : '')),
            $('<td>').attr('data-sort', (n.NomDom || '') + ' / ' + (n.NomExt || '')).text(libRencontre(n)).append($('<div class="small text-muted">').text(n.DivisionNom || '')),
            $('<td>').text(cra ? 'CRA' : 'Club'),
            $accuse,
            $('<td class="text-end">').attr('data-sort', cra && saisi ? String(Number(n.Peage || 0)) : '').text(cra && saisi ? euros(n.Peage) : ''),
            $('<td class="text-end">').attr('data-sort', cra && saisi && n.Kilometre !== null ? String(Number(n.Kilometre)) : '').text(cra && saisi ? (n.Kilometre ?? '') : ''),
            $('<td class="text-center">').attr('data-sort', String(defisc)).text(defisc ? '✔' : ''),
            $('<td class="text-end">').attr('data-sort', cra ? total.toFixed(2) : '').text(cra ? euros(total) : ''),
            $justif,
            $actions
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


function charger(rouvrirId = null) {
    $.getJSON(`${BASE}/data`, function (r) {
        if (!r.ok) { nijacToast(r.msg || 'Erreur de chargement.', 'danger'); return; }
        lignes = r.data || [];
        justifDispo = !!r.justificatifDispo;
        accuseDispo = !!r.accuseDispo;
        afficher();
        if (rouvrirId) {
            const n = lignes.find(x => String(x.Id_Nomination) === String(rouvrirId));
            if (n) majBlocJustif(n);
        }
    }).fail(() => nijacToast('Erreur réseau.', 'danger'));
}

function majBlocJustif(n) {
    courante = n;
    const visible = justifDispo && estCra(n) && Number(n.Peage || 0) > 0 && n.DateSaisie;
    $('#mf-bloc-justif').toggleClass('d-none', !visible);
    $('#mf-justif-etat').empty();
    if (n.Justificatif) {
        $('#mf-justif-etat').append(document.createTextNode(n.Justificatif + ' — '),
            $('<a target="_blank" rel="noopener noreferrer">').attr('href', urlVoir(n)).text('Consulter'));
    } else {
        $('#mf-justif-etat').text('Aucun justificatif joint.');
    }
    $('#mf-justif-fichier').val('');
}

// ── Accusé de réception (ConvocationJaController::accuser(), réutilisé) ──
$(document).on('click', '.btn-accuser', function () {
    const id = $(this).data('id');
    $.post(`${BASE}/accuse`, { id_nomination: id }, function (r) {
        if (r.ok) { nijacToast('Accusé de réception enregistré.', 'success'); charger(); }
        else nijacToast(r.err || 'Action impossible.', 'danger');
    }, 'json').fail(() => nijacToast('Erreur réseau.', 'danger'));
});

// ── Ouverture de la saisie ──
$(document).on('click', '.btn-saisir', function () {
    const n = lignes.find(x => String(x.Id_Nomination) === String($(this).data('id')));
    if (!n) return;
    const cra = estCra(n);
    $('#mf-rencontre').text(dateFr(n.Date) + ' — ' + libRencontre(n));
    $('#mf-bloc-cra').toggleClass('d-none', !cra);
    $('#mf-info-club').toggleClass('d-none', cra);
    $('#mf-peages').val(n.DateSaisie && n.Peage !== null ? String(n.Peage).replace('.', ',') : '');
    $('#mf-km').val(n.DateSaisie && n.Kilometre !== null ? n.Kilometre : '');
    $('#mf-defisc').prop('checked', parseInt(n.Defiscalisation, 10) === 1);
    $('#mf-rap-acc').val(n.RapportAccueil || '');
    $('#mf-rap-eq').val(n.RapportEquipements || '');
    majBlocJustif(n);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-frais')).show();
});

// ── Enregistrement (ConvocationJaController::sauvegarderFrais(), réutilisé) ──
$('#mf-enregistrer').on('click', function () {
    if (!courante) return;
    const id = courante.Id_Nomination;
    const $b = $(this).prop('disabled', true);
    $.post(`${BASE}/sauvegarder`, {
        id_nomination:       id,
        peages:              $('#mf-peages').val().trim(),
        km:                  $('#mf-km').val().trim(),
        defiscalisation:     $('#mf-defisc').is(':checked') ? '1' : '',
        rapport_accueil:     $('#mf-rap-acc').val().trim(),
        rapport_equipements: $('#mf-rap-eq').val().trim(),
    }, function (r) {
        if (!r.ok) { nijacToast(r.err || 'Enregistrement impossible.', 'danger'); return; }
        nijacToast(r.justificatif ? 'Frais enregistrés. Vous pouvez joindre le justificatif de péage.' : 'Frais enregistrés.', 'success');
        charger(id);
    }, 'json').fail(() => nijacToast('Erreur réseau.', 'danger')).always(() => $b.prop('disabled', false));
});

// ── Justificatif (ConvocationJaController::joindreJustificatif(), réutilisé) ──
$('#mf-justif-envoyer').on('click', function () {
    if (!courante) return;
    const f = document.getElementById('mf-justif-fichier').files[0];
    if (!f) { nijacToast('Choisissez le fichier du justificatif (image ou PDF).', 'warning'); return; }
    const fd = new FormData();
    fd.append('id_nomination', courante.Id_Nomination);
    fd.append('justificatif', f);
    const id = courante.Id_Nomination;
    const $b = $(this).prop('disabled', true);
    $.ajax({ url: `${BASE}/justificatif`, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            if (!r.ok) { nijacToast(r.msg || r.err || 'Envoi impossible.', 'danger'); return; }
            nijacToast(r.msg || 'Justificatif enregistré.', 'success');
            charger(id);
        })
        .fail(() => nijacToast('Erreur réseau.', 'danger'))
        .always(() => $b.prop('disabled', false));
});

$('#cbo-periode').on('change', afficher);

$(() => {
    nijacSortableTable('#tbl-frais thead th[data-col]', 'col', sortState, trierLignes);
    charger();
});
</script>
</body>
</html>
