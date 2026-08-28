<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – BugSpid (EA97)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        #panel-liste { width: 62%; }
        #tbl-bugspid td.col-statut .badge-traite  { background: #c6efce; color: #1a5c1a; }
        #tbl-bugspid td.col-statut .badge-atraiter { background: #fff3cd; color: #7a5b00; }
        #tbody-liste tr.ligne-ok { background: #e8f5e9; }
        #tbody-liste tr.ligne-ok:nth-child(even) { background: #ddeedd; }
        #zone-import { padding: .5rem .75rem; background: #f4f7fb; border-bottom: 1px solid #c8d4e8; }
        #zone-import .zone-import-btns { display: flex; gap: .5rem; flex-wrap: wrap; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'wrench-adjustable-circle', 'phTitle' => 'BugSpid — corrections de clubs dupliqués', 'phCode' => 'EA97',
    'phCrumbLabel' => 'Admin BDD', 'phCrumbUrl' => site_url('db-admin'), 'phBackUrl' => site_url('db-admin'),
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => '', 'tbShowPwdWarning' => false]) ?>

<div id="split-container">

    <div id="panel-liste">
        <div id="liste-header">
            <span>Corrections</span>
            <button type="button" class="btn btn-sm btn-light" id="btn-nouveau" title="Ajouter une ligne">
                <i class="bi bi-plus-lg"></i> Ajouter
            </button>
            <button type="button" class="btn btn-sm btn-primary" id="btn-executer-selection" title="Exécuter les lignes cochées">
                <i class="bi bi-play-fill"></i> Exécuter la sélection
            </button>
            <span id="lbl-count">0 / 0</span>
        </div>

        <div id="zone-import">
            <div class="zone-import-btns">
                <button type="button" class="btn btn-sm btn-light" id="btn-pdf-csv">
                    <i class="bi bi-filetype-pdf"></i> Créer le CSV depuis un PDF
                </button>
                <button type="button" class="btn btn-sm btn-light" id="btn-maj-csv">
                    <i class="bi bi-filetype-csv"></i> Mise à jour CSV
                </button>
                <input type="file" id="file-csv" accept=".csv,text/csv" class="d-none">
            </div>
        </div>

        <div id="table-wrapper">
            <table id="tbl-bugspid">
                <thead>
                    <tr>
                        <th style="width:30px"><input type="checkbox" id="chk-tout"></th>
                        <th data-col="1">Description<span class="sort-icon"></span></th>
                        <th style="width:110px" data-col="2">Ancien Id_Club<span class="sort-icon"></span></th>
                        <th style="width:110px" data-col="3">Nouveau Id_Club<span class="sort-icon"></span></th>
                        <th style="width:90px" data-col="4">Statut<span class="sort-icon"></span></th>
                        <th style="width:140px" data-col="5">Date exécution<span class="sort-icon"></span></th>
                        <th style="width:60px"></th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="7" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="panel-form">

        <div id="no-selection">Sélectionnez une ligne dans la liste pour la modifier, ou cliquez sur « Ajouter ».</div>

        <div id="form-bugspid" style="display:none;">
            <div class="mb-2">
                <label class="form-label" for="txt-description">Description</label>
                <input type="text" id="txt-description" class="form-control form-control-sm" maxlength="255">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-ancien">Ancien Id_Club (fantôme)</label>
                <input type="text" id="txt-ancien" class="form-control form-control-sm" maxlength="20">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-nouveau">Nouveau Id_Club (FFTT réel)</label>
                <input type="text" id="txt-nouveau" class="form-control form-control-sm" maxlength="20">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-equipe-nom">EquipeNom à reporter (optionnel)</label>
                <input type="text" id="txt-equipe-nom" class="form-control form-control-sm" maxlength="100"
                       placeholder="Laisser vide pour ne pas toucher au EquipeNom du club cible">
            </div>

            <div class="form-readonly small text-muted mb-2" id="txt-resultat"></div>

            <div id="panel-boutons">
                <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
                <button class="btn btn-sm btn-nouveau px-3" id="btn-annuler">Annuler</button>
                <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer"><i class="bi bi-trash me-1"></i>Supprimer</button>
            </div>

            <div id="form-status" class="mt-3 small fw-bold"></div>
        </div>
    </div>
</div>

<!-- Modale « Créer le CSV depuis un PDF » -->
<div class="modal fade" id="modalPdfCsv" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6"><i class="bi bi-filetype-pdf me-2"></i>Créer le CSV depuis un PDF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2">
                    Sélectionnez un PDF « Calendriers Chpt R1 à R4 » de la FFTT. L'écran en extrait la liste
                    <em>N° Club officiel&nbsp;↔&nbsp;nom du club</em> (n° d'équipe retiré, doublons n°+nom supprimés)
                    et télécharge un fichier CSV « N° Club ; Club ».
                </p>
                <p class="small text-muted mb-3">
                    Ce CSV se recharge ensuite via <strong>Mise à jour CSV</strong> pour renseigner la colonne
                    <em>Nouveau Id_Club</em>. Aucune fusion n'est exécutée.
                </p>
                <input type="file" id="file-pdf" accept="application/pdf,.pdf" class="form-control form-control-sm">
                <div id="pdf-csv-status" class="small mt-2"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<!-- Modale résultat xml_club_b -->
<div class="modal fade" id="modalXmlClubB" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title fs-6"><i class="bi bi-cloud-download me-2"></i>Retour xml_club_b</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="xml-club-b-body"></div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const BUGSPID_BASE = '<?= site_url('bug-spid') ?>';
let lignes = [];
let currentId = null;
const sortState = { col: null, asc: true };

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

function chargerListe(selectId = null) {
    $.get(`${BUGSPID_BASE}/data`, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data;
        renderListe();
        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    $('#lbl-count').text(`${lignes.length} ligne(s)`);
    $('#chk-tout').prop('checked', false);

    if (!lignes.length) {
        $body.append('<tr><td colspan="7" class="text-center text-muted py-3">Aucune ligne.</td></tr>');
        return;
    }

    lignes.forEach(l => {
        const badge = l.Statut === 'Traite'
            ? '<span class="badge badge-traite">Traité</span>'
            : '<span class="badge badge-atraiter">À traiter</span>';
        const $tdFftt = $('<td>').append(
            $('<button type="button" class="btn btn-sm btn-outline-secondary btn-xml-club-b" title="Tester xml_club_b avec cet Id_Club">')
                .html('<i class="bi bi-cloud-download"></i>')
                .on('click', function (e) { e.stopPropagation(); appelerXmlClubB(l.Id_BugSpid); })
        );
        // Même contrôle que le garde-fou d'exécution côté serveur : un NouveauIdClub
        // identifié (format FFTT à 8 chiffres, différent de AncienIdClub).
        const nouveauOk = /^\d{8}$/.test(l.NouveauIdClub ?? '') && l.NouveauIdClub !== l.AncienIdClub;
        const $tdNouveau = $('<td>').text(l.NouveauIdClub ?? '');
        if (/^\d{8}$/.test(l.NouveauIdClub ?? '')) {
            $tdNouveau.css('cursor', 'pointer').attr('title', 'Cliquer pour afficher le nom du club')
                .on('click', function (e) { e.stopPropagation(); afficherNomClub(l.NouveauIdClub); });
        }
        $('<tr>').attr('data-id', l.Id_BugSpid).toggleClass('ligne-ok', nouveauOk).append(
            $('<td>').append($('<input type="checkbox" class="chk-ligne">').prop('disabled', l.Statut === 'Traite')),
            $('<td>').text(l.Description ?? ''),
            $('<td>').text(l.AncienIdClub ?? ''),
            $tdNouveau,
            $('<td>').addClass('col-statut').html(badge),
            $('<td>').text(l.DateExecution ?? ''),
            $tdFftt
        ).on('click', function (e) {
            if ($(e.target).is('input[type="checkbox"]')) return;
            selectionnerLigne($(this));
        }).appendTo($body);
    });

    if (currentId) {
        const $tr = $(`#tbody-liste tr[data-id="${currentId}"]`);
        if ($tr.length) $tr.addClass('selected');
    }
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = +$tr.attr('data-id');
    const l  = lignes.find(x => x.Id_BugSpid == id);
    if (!l) return;

    currentId = id;
    $('#no-selection').hide();
    $('#form-bugspid').show();
    $('#txt-description').val(l.Description ?? '');
    $('#txt-ancien').val(l.AncienIdClub ?? '');
    $('#txt-nouveau').val(l.NouveauIdClub ?? '');
    $('#txt-equipe-nom').val(l.EquipeNom ?? '');
    $('#txt-resultat').text(l.Resultat ? `Résultat : ${l.Resultat}` : '');
    setStatus('');
}

$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#no-selection').hide();
    $('#form-bugspid').show();
    $('#txt-description, #txt-ancien, #txt-nouveau, #txt-equipe-nom').val('');
    $('#txt-resultat').text('');
    setStatus('');
});

$('#btn-annuler').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#form-bugspid').hide();
    $('#no-selection').show();
});

$('#btn-enregistrer').on('click', function () {
    const payload = {
        description:      $('#txt-description').val().trim(),
        ancien_id_club:    $('#txt-ancien').val().trim(),
        nouveau_id_club:   $('#txt-nouveau').val().trim(),
        equipe_nom:        $('#txt-equipe-nom').val().trim(),
    };

    const url    = currentId ? `${BUGSPID_BASE}/${currentId}` : BUGSPID_BASE;
    const method = currentId ? 'PUT' : 'POST';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(currentId || res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    }).fail(() => toast('Erreur réseau.', false));
});

$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    nijacConfirm('Supprimer cette ligne ?', function () {
        $.ajax({ url: `${BUGSPID_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) {
                toast(res.msg);
                currentId = null;
                $('#form-bugspid').hide();
                $('#no-selection').show();
                chargerListe();
            } else {
                toast(res.msg, false);
            }
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'danger' });
});

$('#chk-tout').on('change', function () {
    $('.chk-ligne:not(:disabled)').prop('checked', this.checked);
});

$('#btn-executer-selection').on('click', function () {
    const ids = $('.chk-ligne:checked').map(function () { return +$(this).closest('tr').attr('data-id'); }).get();
    if (!ids.length) { toast('Cochez au moins une ligne à exécuter.', false); return; }

    nijacConfirm(`Exécuter ${ids.length} ligne(s) de correction ?`, function () {
        $.ajax({ url: `${BUGSPID_BASE}/executer`, method: 'POST', data: { ids: JSON.stringify(ids) }, dataType: 'json' }).done(function (res) {
            if (!res.ok) { toast(res.msg, false); return; }
            const nbOk = res.resultats.filter(r => r.ok).length;
            const nbKo = res.resultats.length - nbOk;
            toast(`${nbOk} ligne(s) exécutée(s)` + (nbKo ? `, ${nbKo} en erreur.` : '.'), nbKo === 0);
            chargerListe();
        }).fail(() => toast('Erreur réseau.', false));
    }, null, { type: 'question', title: 'Exécuter la sélection', confirmLabel: 'Exécuter' });
});

$('#btn-pdf-csv').on('click', function () {
    $('#file-pdf').val('');
    $('#pdf-csv-status').text('').removeClass('text-danger text-success');
    new bootstrap.Modal('#modalPdfCsv').show();
});

$('#file-pdf').on('change', function () {
    const f = this.files[0];
    if (!f) return;

    const $st = $('#pdf-csv-status').removeClass('text-danger text-success').text('Extraction du PDF en cours…');
    const fd = new FormData();
    fd.append('pdf', f);
    $.ajax({
        url: `${BUGSPID_BASE}/pdf-csv`, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
    }).done(function (res) {
        if (!res.ok) { $st.addClass('text-danger').text(res.msg); return; }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([res.csv], { type: 'text/csv;charset=utf-8' }));
        a.download = res.nom || 'clubs.csv';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(a.href);
        bootstrap.Modal.getInstance('#modalPdfCsv')?.hide();
        toast(`${res.nb} club(s) extrait(s) — fichier « ${a.download} » téléchargé.`);
    }).fail(() => $st.addClass('text-danger').text('Erreur réseau.'));
});

$('#btn-maj-csv').on('click', () => $('#file-csv').click());

$('#file-csv').on('change', function () {
    const f = this.files[0];
    this.value = ''; // permet de re-sélectionner le même fichier ensuite
    if (!f) return;

    const fd = new FormData();
    fd.append('csv', f);
    $.ajax({
        url: `${BUGSPID_BASE}/maj-csv`, method: 'POST', data: fd,
        processData: false, contentType: false, dataType: 'json',
    }).done(function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        toast(res.msg, (res.restantes ?? 0) === 0);
        chargerListe();
    }).fail(() => toast('Erreur réseau.', false));
});

function afficherNomClub(num) {
    $.get(`${BUGSPID_BASE}/nom-club/${num}`, function (res) {
        toast(res.ok ? `${num} — ${res.nom}` : (res.msg || 'Club introuvable.'), res.ok);
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

function appelerXmlClubB(id) {
    $('#xml-club-b-body').html('<div class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> Appel en cours…</div>');
    const modal = new bootstrap.Modal('#modalXmlClubB');
    modal.show();

    $.post(`${BUGSPID_BASE}/${id}/xml-club-b`, {}, function (res) {
        if (!res.ok) {
            $('#xml-club-b-body').html(`<div class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>${res.msg}</div>`);
            return;
        }
        const url    = res.url ? `<div class="text-muted small mb-2 text-break">${res.url}</div>` : '';
        const source = res.source === 'local'
            ? '<span class="badge bg-success ms-2">Trouvé en base locale</span>'
            : '<span class="badge bg-secondary ms-2">Recherche FFTT</span>';
        const entete = `<div class="mb-2"><strong>Recherche :</strong> ${escHtml(res.recherche)}${source}</div>${url}`;
        if (!res.clubs.length) {
            $('#xml-club-b-body').html(`${entete}<div class="text-muted">Aucun club trouvé.</div>`);
            return;
        }
        const lignes = res.clubs.map(c => `
            <tr>
                <td>${escHtml(c.numero ?? '')}</td>
                <td>${escHtml(c.nom ?? '')}</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-success btn-utiliser-numero" data-id="${id}" data-numero="${escHtml(c.numero ?? '')}">
                        <i class="bi bi-check-lg me-1"></i>Utiliser
                    </button>
                </td>
            </tr>`).join('');
        $('#xml-club-b-body').html(
            `${entete}<table class="table table-sm"><thead><tr><th>Numéro</th><th>Nom</th><th></th></tr></thead><tbody>${lignes}</tbody></table>`
        );
    }, 'json').fail(function (xhr) {
        $('#xml-club-b-body').html(`<div class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Erreur réseau.</div>`);
    });
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

$(document).on('click', '.btn-utiliser-numero', function () {
    const id     = +$(this).data('id');
    const numero = String($(this).data('numero'));
    const l      = lignes.find(x => x.Id_BugSpid == id);
    if (!l) return;

    $.ajax({
        url: `${BUGSPID_BASE}/${id}`, method: 'PUT', dataType: 'json',
        data: {
            description:      l.Description ?? '',
            ancien_id_club:   l.AncienIdClub ?? '',
            nouveau_id_club:  numero,
            equipe_nom:       l.EquipeNom ?? '',
        },
    }).done(function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        toast(`Nouveau Id_Club renseigné : ${numero}`);
        bootstrap.Modal.getInstance('#modalXmlClubB')?.hide();
        chargerListe(id);
    }).fail(() => toast('Erreur réseau.', false));
});

$(function () {
    nijacSortableTable('#tbl-bugspid thead th[data-col]', 'col', sortState,
        () => nijacSortRows('#tbody-liste', parseInt(sortState.col, 10), sortState.asc));
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
