<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Club CSR (ES31)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">

    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
            flex-shrink: 0;
        }
        #toolbar .ts-user { color: #1a3a6b; font-weight: 600; }
        #toolbar .ts-pwd-warning {
            display: <?= $changeLogin ? 'inline-flex' : 'none' ?>;
            align-items: center;
            gap: .35rem;
            color: #c00;
            font-weight: 700;
            cursor: pointer;
            text-decoration: underline dotted;
        }
        #toolbar .ts-pwd-warning:hover { color: #900; }

        /* ── En-tête (violet, propre au rôle CSR — voir E004) ── */
        #page-header {
            background: #6a1b9a;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Grille ── */
        #grid-wrapper { flex: 1; overflow: auto; }

        #tbl-clubs {
            width: 100%;
            font-size: .85rem;
            border-collapse: collapse;
            min-width: 400px;
        }

        #tbl-clubs thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .35rem .6rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
        }

        #tbl-clubs tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-clubs tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-clubs tbody tr:hover   { background: #dce8f8; }
        #tbl-clubs tbody tr.club-selected { background: #b2dfdb !important; }
        #tbl-clubs tbody td { border: 1px solid #e0e8f0; padding: 0; }
        #tbl-clubs tbody td.col-chk { text-align: center; padding: .3rem 0; }

        .cell-inner {
            display: block;
            padding: .28rem .5rem;
            min-height: 28px;
            white-space: nowrap;
            overflow: hidden;
            cursor: pointer;
        }

        td.col-id .cell-inner {
            color: #6b7280;
            font-style: italic;
            background: #f0f4fa;
        }

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 220px;
        }

        /* ── En-têtes triables ── */
        #tbl-clubs thead th[data-field] { cursor: pointer; user-select: none; }
        #tbl-clubs thead th[data-field]:hover { background: #d4dff0; }
        #tbl-clubs thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-clubs thead th.sort-asc .sort-icon::after  { content: '▲'; opacity: 1; }
        #tbl-clubs thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-clubs thead th[data-field]:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        #page-footer {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
            gap: 1rem;
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
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'building', 'phTitle' => 'Club CSR', 'phCode' => 'ES31',
    'phCrumbLabel' => 'CSR', 'phCrumbUrl' => site_url('csr-menu'), 'phBackUrl' => site_url('csr-menu'),
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Spinner -->
<?= view('partials/spinner_overlay') ?>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-envoyer" disabled style="background:#00695c;color:#fff;border-color:#00695c;">
        <i class="bi bi-envelope-fill me-1"></i>Envoyer un Email à tous <span id="lbl-selection">(0)</span>
    </button>
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 club(s)</span>
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <?php
        $codesRegion = array_column($deptActifs, 'code');
        $deptsAutres = array_filter($tousDepts, fn ($d) => !in_array($d['code'], $codesRegion, true));
    ?>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <optgroup label="Région">
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= esc($d['code']) ?>"><?= esc($d['code']) ?> — <?= esc($d['nom']) ?></option>
        <?php endforeach; ?>
        </optgroup>
        <optgroup label="Autres départements">
        <?php foreach ($deptsAutres as $d): ?>
        <option value="<?= esc($d['code']) ?>"><?= esc($d['code']) ?> — <?= esc($d['nom']) ?></option>
        <?php endforeach; ?>
        </optgroup>
    </select>
    <button class="menu-item" id="btn-filtre-region" title="Cliquer pour n'afficher que les clubs de la région, ou tous les clubs" style="border-color:transparent;">
        <i class="bi bi-geo-alt me-1"></i><span id="lbl-filtre-region">Région</span>
    </button>
    <button class="menu-item" id="btn-filtre-regional" title="Cliquer pour n'afficher que les clubs ayant une équipe Régionale/Pré-Nationale, ou tous les clubs" style="border-color:transparent;">
        <i class="bi bi-trophy me-1"></i><span id="lbl-filtre-regional">R4-PN</span>
    </button>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-clubs">
        <thead>
            <tr>
                <th style="width:2.2rem;text-align:center;"><input type="checkbox" id="chk-all"></th>
                <th style="width:120px" data-field="id_club">N° FFTT<span class="sort-icon"></span></th>
                <th data-field="nom">Nom club<span class="sort-icon"></span></th>
                <th style="width:180px" data-field="equipe_nom" title="Nom de base utilisé pour les équipes de ce club dans les imports FFTT (ex. « ROUEN SPO » pour « ROUEN SPO 2 »)">Nom équipe<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="cor_nom">Correspondant<span class="sort-icon"></span></th>
                <th style="width:220px" data-field="cor_email">Email correspondant<span class="sort-icon"></span></th>
                <th style="width:150px" data-field="cor_tel">Téléphone correspondant<span class="sort-icon"></span></th>
                <th style="width:70px"></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="8" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Pied de page : recopié de includes/footer.php (setStatus() écrit dans #status-bar) -->
<?= view('partials/page_footer', [
    'pfStatusText' => 'Prêt.',
    'pfStatusAlign' => 'left',
]) ?>

<!-- Modale : modifier un club -->
<div class="modal fade" id="modal-modifier-club" tabindex="-1" aria-labelledby="modal-modifier-club-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
        <div class="modal-content">
            <div class="modal-header" style="background:#00695c;color:#fff;">
                <h5 class="modal-title" id="modal-modifier-club-titre">
                    <i class="bi bi-building me-2"></i>Modifier le club
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:.88rem;">
                <input type="hidden" id="mod-club-idx">
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">N° FFTT</label>
                    <p id="mod-club-id" class="fw-bold mb-0" style="color:#6b7280;"></p>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Nom du club <span class="text-danger">*</span></label>
                    <input type="text" id="mod-club-nom" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Nom équipe (imports FFTT)</label>
                    <input type="text" id="mod-club-equipe-nom" class="form-control form-control-sm" placeholder="ex. ROUEN SPO">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Correspondant</label>
                    <input type="text" id="mod-club-cor-nom" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Email correspondant</label>
                    <input type="email" id="mod-club-cor-email" class="form-control form-control-sm">
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold" style="font-size:.82rem;">Téléphone correspondant</label>
                    <input type="text" id="mod-club-cor-tel" class="form-control form-control-sm">
                </div>
                <div id="mod-club-msg" style="font-size:.8rem;min-height:18px;margin-top:.4rem;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-primary btn-sm" id="mod-club-btn-ok" style="background:#00695c;border-color:#00695c;">
                    <i class="bi bi-floppy-fill me-1"></i>Valider
                </button>
            </div>
        </div>
    </div>
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const CLUB_BASE = '<?= site_url('club-csr') ?>';
const DEPTS_REGION = new Set(<?= json_encode(array_column($deptActifs, 'code')) ?>);

function deptDeClub(idClub) {
    // Format : 0[9][dept 2 chiffres][4 chiffres] — ex. 09760442 → '76'
    return String(idClub ?? '').substring(2, 4);
}

let lignes         = [];
const sortState    = { col: 'id_club', asc: true };
let searchTerm     = '';
let deptFiltre     = '';   // filtré côté JS
let filtreEnRegion = true;  // true = En région uniquement (par défaut), false = Tous
let filtreRegional = false; // true = clubs avec équipe Régionale/Pré-Nationale uniquement, false = tous (par défaut)
const selection    = new Set(); // Id_Club sélectionnés (persiste entre filtrages)

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let result = [...lignes];
    if (deptFiltre)     result = result.filter(l => deptDeClub(l.id_club) === deptFiltre);
    if (filtreEnRegion) result = result.filter(l => DEPTS_REGION.has(deptDeClub(l.id_club)));
    if (filtreRegional) result = result.filter(l => l.est_regional);
    if (term) result = result.filter(l =>
        String(l.id_club     ?? '').toLowerCase().includes(term) ||
        String(l.nom         ?? '').toLowerCase().includes(term) ||
        String(l.equipe_nom  ?? '').toLowerCase().includes(term) ||
        String(l.cor_nom     ?? '').toLowerCase().includes(term) ||
        String(l.cor_email   ?? '').toLowerCase().includes(term));

    result.sort((a, b) => {
        const va = String(a[sortState.col] ?? '').toLowerCase();
        const vb = String(b[sortState.col] ?? '').toLowerCase();
        return sortState.asc ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    refreshTriEntetes();
    const $body = $('#tbody-grille').empty();

    const rows = lignesFiltreesTriees().map(construireLigne);

    if (!rows.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun club.';
        $body.append(`<tr><td colspan="8" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} club(s).` : 'Aucun club enregistré.');
        $('#lbl-count').text(`0 / ${lignes.length} club(s)`);
        majBarreSelection();
        return;
    }

    rows.forEach((tr) => $body.append(tr));

    const info = searchTerm ? `${rows.length} résultat(s) sur ${lignes.length}. ` : '';
    setStatus(`${info}Prêt.`);
    $('#lbl-count').text(`${rows.length} / ${lignes.length} club(s)`);
    majBarreSelection();
}

function construireLigne(l) {
    const idx  = lignes.indexOf(l);
    const dept = deptDeClub(l.id_club);
    const $tr  = $('<tr>').attr('data-idx', idx);
    if (selection.has(l.id_club)) $tr.addClass('club-selected');
    const $tdChk = $('<td class="col-chk">').append(
        $('<input type="checkbox" class="chk-club">').attr('data-id', l.id_club).prop('checked', selection.has(l.id_club))
    );
    $tr.append($tdChk);
    $tr.append(makeTd(l.id_club,     'id_club'));
    $tr.append(makeTd(l.nom,         'nom'));
    $tr.append(makeTd(l.equipe_nom,  'equipe_nom'));
    $tr.append(makeTd(l.cor_nom,     'cor_nom'));
    $tr.append(makeTd(l.cor_email,   'cor_email'));
    $tr.append(makeTd(l.cor_tel,     'cor_tel'));
    const $tdActions = $('<td class="text-center">');
    $tdActions.append(
        $('<button type="button" class="btn btn-sm btn-outline-primary btn-modifier-club" title="Modifier">')
            .attr('data-idx', idx)
            .html('<i class="bi bi-pencil-fill"></i>')
    );
    $tr.append($tdActions);
    return $tr;
}

function makeTd(val, field) {
    const $td  = $('<td>').attr('data-field', field);
    if (field === 'id_club') $td.addClass('col-id');
    const $div = $('<div class="cell-inner">').text(val ?? '');
    $td.append($div);
    return $td;
}

// ── Sélection ─────────────────────────────────────────────────────────────────
function majBarreSelection() {
    $('#lbl-selection').text(`(${selection.size})`);
    $('#btn-envoyer').prop('disabled', selection.size === 0);
    const $chks = $('#tbody-grille .chk-club');
    $('#chk-all').prop('checked', $chks.length > 0 && $chks.filter(':not(:checked)').length === 0);
}

$('#tbody-grille').on('change', '.chk-club', function () {
    const id = $(this).attr('data-id');
    if (this.checked) selection.add(id); else selection.delete(id);
    $(this).closest('tr').toggleClass('club-selected', this.checked);
    majBarreSelection();
});

$('#chk-all').on('change', function () {
    const check = this.checked;
    $('#tbody-grille .chk-club').each(function () {
        const id = $(this).attr('data-id');
        $(this).prop('checked', check);
        if (check) selection.add(id); else selection.delete(id);
        $(this).closest('tr').toggleClass('club-selected', check);
    });
    majBarreSelection();
});

$('#btn-envoyer').on('click', function () {
    if (!selection.size) return;
    nijacConfirm(`Envoyer l'email aux correspondants des ${selection.size} club(s) sélectionné(s) ?`, function () {
        spinner(true);
        $.post(`${CLUB_BASE}/envoyer`, { ids: JSON.stringify(Array.from(selection)) }, function (res) {
            spinner(false);
            if (res.ok) toast(res.msg, true); else toast(res.msg, false);
        }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
    });
});

$('#tbody-grille').on('click', '.btn-modifier-club', function () {
    ouvrirModaleModifClub(+$(this).attr('data-idx'));
});

$('#tbody-grille').on('dblclick', 'tr[data-idx]', function (e) {
    if ($(e.target).is('.chk-club')) return;
    ouvrirModaleModifClub(+$(this).attr('data-idx'));
});

function ouvrirModaleModifClub(idx) {
    const l = lignes[idx];
    if (!l) return;
    $('#mod-club-idx').val(idx);
    $('#mod-club-id').text(l.id_club ?? '');
    $('#mod-club-nom').val(l.nom ?? '');
    $('#mod-club-equipe-nom').val(l.equipe_nom ?? '');
    $('#mod-club-cor-nom').val(l.cor_nom ?? '');
    $('#mod-club-cor-email').val(l.cor_email ?? '');
    $('#mod-club-cor-tel').val(l.cor_tel ?? '');
    $('#mod-club-msg').text('');
    const el = document.getElementById('modal-modifier-club');
    let modal = bootstrap.Modal.getInstance(el);
    if (!modal) modal = new bootstrap.Modal(el);
    modal.show();
    el.addEventListener('shown.bs.modal', () => {
        $('#mod-club-nom').trigger('focus').trigger('select');
    }, { once: true });
}

$('#modal-modifier-club').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); $('#mod-club-btn-ok').trigger('click'); }
});

$('#mod-club-btn-ok').on('click', function () {
    $('#mod-club-msg').text('');
    const idx      = +$('#mod-club-idx').val();
    const l        = lignes[idx];
    if (!l) return;
    const nom       = $('#mod-club-nom').val().trim();
    const equipeNom = $('#mod-club-equipe-nom').val().trim();
    const corNom    = $('#mod-club-cor-nom').val().trim();
    const corEmail  = $('#mod-club-cor-email').val().trim();
    const corTel    = $('#mod-club-cor-tel').val().trim();

    if (!nom) {
        $('#mod-club-msg').html('<span class="text-danger">Le nom du club est obligatoire.</span>');
        return;
    }

    spinner(true);
    $.ajax({
        url: `${CLUB_BASE}/${encodeURIComponent(l.id_club)}`,
        method: 'PUT',
        data: { nom, equipe_nom: equipeNom, cor_nom: corNom, cor_email: corEmail, cor_tel: corTel },
        dataType: 'json',
    }).done(function (res) {
        spinner(false);
        if (res.ok) {
            toast(res.msg, true);
            bootstrap.Modal.getInstance(document.getElementById('modal-modifier-club')).hide();
            l.nom        = nom;
            l.equipe_nom = equipeNom;
            l.cor_nom    = corNom;
            l.cor_email  = corEmail;
            l.cor_tel    = corTel;
            renderGrille();
        } else {
            $('#mod-club-msg').html('<span class="text-danger">✖ ' + res.msg + '</span>');
        }
    }).fail(() => { spinner(false); $('#mod-club-msg').html('<span class="text-danger">Erreur réseau.</span>'); });
});

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.get(`${CLUB_BASE}/liste`, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map(r => ({
            id_club:      r.Id_Club,
            nom:          r.Nom,
            equipe_nom:   r.EquipeNom ?? '',
            cor_nom:      r.CorNom      ?? '',
            cor_email:    r.CorEmail    ?? '',
            cor_tel:      r.CorTelephone ?? '',
            est_regional: !!(+r.EstRegional),
        }));
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
// Différé : nijac-sortable-table.js est chargé en fin de page, donc pas encore
// défini si on l'appelait ici de façon synchrone.
let refreshTriEntetes = () => {};
$(function () {
    refreshTriEntetes = nijacSortableTable('#tbl-clubs thead th[data-field]', 'field', sortState, renderGrille);
});

// ── Filtre région / tous (bascule) ───────────────────────────────────────────
function appliquerStyleFiltreRegion() {
    $('#lbl-filtre-region').text(filtreEnRegion ? 'Région' : 'Tous');
    $('#btn-filtre-region').css({
        background:  filtreEnRegion ? '#166534' : '',
        color:       filtreEnRegion ? '#fff'    : '',
        borderColor: filtreEnRegion ? '#166534' : 'transparent',
    });
}
appliquerStyleFiltreRegion();

$('#btn-filtre-region').on('click', function () {
    filtreEnRegion = !filtreEnRegion;
    appliquerStyleFiltreRegion();
    renderGrille();
});

// ── Filtre club régional (bascule) ───────────────────────────────────────────
function appliquerStyleFiltreRegional() {
    $('#lbl-filtre-regional').text('R4-PN');
    $('#btn-filtre-regional').css({
        background:  filtreRegional ? '#166534' : '',
        color:       filtreRegional ? '#fff'    : '',
        borderColor: filtreRegional ? '#166534' : 'transparent',
    });
}
appliquerStyleFiltreRegional();

$('#btn-filtre-regional').on('click', function () {
    filtreRegional = !filtreRegional;
    appliquerStyleFiltreRegional();
    renderGrille();
});

// ── Filtre département ────────────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    renderGrille();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
