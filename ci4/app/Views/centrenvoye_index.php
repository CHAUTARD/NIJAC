<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Centre d'envoi (E024)</title>

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

        #page-header {
            background: #2e7d32;
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
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

        #split-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* ── Panneau message (gauche) ── */
        #panel-message {
            width: 55%;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
            background: #fff;
            overflow: hidden;
        }

        /* ── Onglets ── */
        #type-tabs {
            display: flex;
            border-bottom: 2px solid #c8d4e8;
            flex-shrink: 0;
            background: #f0f4fa;
        }

        .type-tab {
            padding: .45rem .9rem;
            font-size: .8rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            border-bottom: 3px solid transparent;
            background: none;
            color: #6b7280;
            white-space: nowrap;
            transition: color .15s, border-color .15s;
        }

        .type-tab:hover   { color: var(--nijac-blue); }
        .type-tab.active  { color: var(--nijac-blue); border-bottom-color: var(--nijac-blue); background: #fff; }

        /* ── Corps du panneau message ── */
        #panel-message-body {
            flex: 1;
            overflow-y: auto;
            padding: .75rem 1rem;
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        #txt-message {
            resize: vertical;
            min-height: 220px;
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: .88rem;
            flex: 1;
        }

        /* ── Cartouche marqueurs ── */
        #cartouche-marqueurs {
            font-size: .76rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: .4rem .6rem;
            flex-shrink: 0;
        }

        #cartouche-marqueurs .cart-titre {
            font-size: .7rem;
            font-weight: 700;
            color: #6b7280;
            letter-spacing: .04em;
            text-transform: uppercase;
            margin-bottom: .25rem;
        }

        #cartouche-marqueurs code {
            display: inline-block;
            background: #e8eef7;
            border: 1px solid #b8cce4;
            border-radius: 3px;
            padding: .02rem .28rem;
            font-size: .73rem;
            cursor: pointer;
            user-select: none;
            transition: background .12s, transform .1s;
            margin: .1rem .15rem .1rem 0;
        }

        #cartouche-marqueurs code:hover  { background: #cfe0f8; border-color: #7aaddf; transform: scale(1.06); }
        #cartouche-marqueurs code:active { transform: scale(.95); }

        /* ── Barre d'envoi ── */
        #envoi-bar {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .4rem .75rem;
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        #result-envoi { font-size: .82rem; flex: 1; }

        /* ── Panneau JA (droite) ── */
        #panel-ja {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #ja-header {
            background: steelblue;
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            padding: .4rem .75rem;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: .6rem;
        }

        .badge-dept {
            background: #fff;
            color: steelblue;
            font-size: .75rem;
            padding: .1rem .4rem;
            border-radius: 3px;
            font-weight: 700;
        }

        /* ── Sélecteur journée (Nomination) ── */
        #journee-bar {
            background: #fff8e1;
            border-bottom: 1px solid #ffe082;
            padding: .35rem .6rem;
            display: none;
            align-items: center;
            gap: .5rem;
            flex-shrink: 0;
            font-size: .83rem;
        }

        #journee-bar.visible { display: flex; }

        /* ── Barre de recherche ── */
        #ja-search-bar {
            background: #f0f4fa;
            border-bottom: 1px solid #c8d4e8;
            padding: .3rem .6rem;
            display: flex;
            align-items: center;
            gap: .4rem;
            flex-shrink: 0;
        }

        #txt-recherche-ja {
            flex: 1;
            font-size: .82rem;
            padding: .2rem .4rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
        }

        #ja-list-wrapper { flex: 1; overflow-y: auto; }

        #tbl-ja {
            width: 100%;
            font-size: .82rem;
            border-collapse: collapse;
        }

        #tbl-ja thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .3rem .4rem;
            position: sticky;
            top: 0;
            z-index: 1;
            white-space: nowrap;
        }

        #tbl-ja tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-ja tbody tr:hover { background: #f4f7fc; }
        #tbl-ja tbody td { padding: .22rem .4rem; }

        .col-sort { cursor: pointer; user-select: none; }
        .no-email { color: #bbb; font-style: italic; font-size: .75rem; }
        tr.masque { display: none; }

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
    'phIcon' => 'send-fill', 'phTitle' => "Centre d'envoi", 'phCode' => 'E024',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<div id="split-container">

    <!-- ── Panneau message ── -->
    <div id="panel-message">

        <!-- Onglets -->
        <div id="type-tabs">
            <button class="type-tab active" data-type="Disponibilites">
                <i class="bi bi-calendar-check me-1"></i>Disponibilités
            </button>
            <button class="type-tab" data-type="Rappel dispo">
                <i class="bi bi-bell me-1"></i>Rappel dispo
            </button>
            <button class="type-tab" data-type="Convocation">
                <i class="bi bi-person-check me-1"></i>Convocation
            </button>
            <button class="type-tab" data-type="Liste nomination">
                <i class="bi bi-list-check me-1"></i>Liste nomination
            </button>
            <button class="type-tab" data-type="Demande adresse">
                <i class="bi bi-geo-alt me-1"></i>Demande adresse
            </button>
        </div>

        <!-- Corps -->
        <div id="panel-message-body">

            <div class="mb-2" id="grp-cc" style="display:none;">
                <label class="form-label" for="txt-cc">Cc :</label>
                <input type="email" id="txt-cc" class="form-control form-control-sm" maxlength="150">
            </div>

            <div class="mb-2">
                <label class="form-label" for="txt-sujet">Sujet :</label>
                <input type="text" id="txt-sujet" class="form-control form-control-sm" maxlength="150">
            </div>

            <div class="mb-1 d-flex flex-column flex-grow-1">
                <label class="form-label" for="txt-message">Message :</label>
                <textarea id="txt-message" class="form-control form-control-sm flex-grow-1"></textarea>
            </div>

            <!-- Cartouche marqueurs (mis à jour selon l'onglet actif) -->
            <div id="cartouche-marqueurs" class="mb-1">
                <div class="cart-titre"><i class="bi bi-braces me-1"></i>Marqueurs disponibles — clic pour insérer</div>
                <div id="cart-communs">
                    <span class="badge bg-secondary me-1 fw-normal" style="font-size:.68rem;">Tous</span>
                    <code data-cible="message" data-marqueur="{PRENOM}">{PRENOM}</code>
                    <code data-cible="message" data-marqueur="{NOM}">{NOM}</code>
                    <code data-cible="message" data-marqueur="{NOM_COMPLET}">{NOM_COMPLET}</code>
                    <code data-cible="message" data-marqueur="{ID_JA}">{ID_JA}</code>
                    <code data-cible="message" data-marqueur="{UTI_NOM}">{UTI_NOM}</code>
                    <code data-cible="message" data-marqueur="{UTI_PRENOM}">{UTI_PRENOM}</code>
                    <code data-cible="message" data-marqueur="{URL_LIGUE}">{URL_LIGUE}</code>
                    <code data-cible="message" data-marqueur="{URL_DISPONIBILITE_JA}">{URL_DISPONIBILITE_JA}</code>
                </div>
                <div id="cart-convocation" style="display:none;margin-top:.2rem;">
                    <span class="badge me-1 fw-normal" style="font-size:.68rem;background:#1a7f4b;">Convocation</span>
                    <code data-cible="message" data-marqueur="{DATE}">{DATE}</code>
                    <code data-cible="message" data-marqueur="{HEURE}">{HEURE}</code>
                    <code data-cible="message" data-marqueur="{JOURNEE}">{JOURNEE}</code>
                    <code data-cible="message" data-marqueur="{POULE}">{POULE}</code>
                    <code data-cible="message" data-marqueur="{DIVISION}">{DIVISION}</code>
                    <code data-cible="message" data-marqueur="{DOM}">{DOM}</code>
                    <code data-cible="message" data-marqueur="{EXT}">{EXT}</code>
                    <code data-cible="message" data-marqueur="{SALLE_NOM}">{SALLE_NOM}</code>
                    <code data-cible="message" data-marqueur="{SALLE_ADRESSE}">{SALLE_ADRESSE}</code>
                    <code data-cible="message" data-marqueur="{SALLE_CP}">{SALLE_CP}</code>
                    <code data-cible="message" data-marqueur="{SALLE_VILLE}">{SALLE_VILLE}</code>
                    <code data-cible="message" data-marqueur="{CORR_NOM}">{CORR_NOM}</code>
                    <code data-cible="message" data-marqueur="{CORR_EMAIL}">{CORR_EMAIL}</code>
                    <code data-cible="message" data-marqueur="{CORR_TEL}">{CORR_TEL}</code>
                    <code data-cible="message" data-marqueur="{ID_CONVOCATION}">{ID_CONVOCATION}</code>
                    <code data-cible="message" data-marqueur="{SEXE}">{SEXE}</code>
                    <code data-cible="message" data-marqueur="{URL_CONVOCATION_JA}">{URL_CONVOCATION_JA}</code>
                </div>
                <div id="cart-liste-nom" style="display:none;margin-top:.2rem;">
                    <span class="badge me-1 fw-normal" style="font-size:.68rem;background:#6f42c1;">Liste nomination</span>
                    <code data-cible="message" data-marqueur="{LISTE_NOMINATIONS}">{LISTE_NOMINATIONS}</code>
                </div>
                <div id="cart-demande-adresse" style="display:none;margin-top:.2rem;">
                    <span class="badge me-1 fw-normal" style="font-size:.68rem;background:#e06c00;">Demande adresse</span>
                    <code data-cible="message" data-marqueur="{URL_ADRESSE_JA}">{URL_ADRESSE_JA}</code>
                </div>
            </div>

        </div>

        <!-- Barre d'envoi -->
        <div id="envoi-bar">
            <button class="btn btn-sm btn-success px-3" id="btn-envoyer">
                <i class="bi bi-send me-1"></i>Envoyer
            </button>
            <div id="result-envoi"></div>
        </div>

        <!-- Barre de progression (masquée par défaut) -->
        <div id="envoi-progress" style="display:none;margin-top:.6rem;">
            <div class="d-flex justify-content-between align-items-center mb-1" style="font-size:.78rem;">
                <span id="progress-label" class="text-secondary fw-semibold"></span>
                <span id="progress-counts" class="text-muted"></span>
            </div>
            <div class="progress" style="height:14px;border-radius:8px;">
                <div id="progress-bar"
                     class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                     role="progressbar" style="width:0%;transition:width .3s ease;"></div>
            </div>
            <div id="progress-erreurs" class="mt-1 text-danger" style="font-size:.72rem;"></div>
        </div>

    </div>

    <!-- ── Panneau JA ── -->
    <div id="panel-ja">

        <div id="ja-header">
            <span id="ja-header-titre">JA actifs</span>
            <span class="badge-dept">Dép. <?= esc($dept) ?></span>
            <span id="nb-ja" class="ms-auto opacity-75" style="font-weight:400;font-size:.78rem;"></span>
        </div>

        <!-- Sélecteur journée (visible seulement pour Nomination) -->
        <div id="journee-bar">
            <i class="bi bi-calendar3 text-warning"></i>
            <label class="fw-bold mb-0" style="font-size:.82rem;">Journée :</label>
            <select id="cbo-journee" class="form-select form-select-sm" style="max-width:300px;">
                <option value="">— Sélectionner —</option>
            </select>
            <span id="nb-ja-journee" class="text-muted" style="font-size:.78rem;"></span>
        </div>

        <!-- Recherche -->
        <div id="ja-search-bar">
            <i class="bi bi-search text-muted" style="font-size:.82rem;"></i>
            <input type="text" id="txt-recherche-ja" placeholder="Rechercher…">
            <button class="btn btn-sm btn-outline-secondary py-0 px-2" id="btn-effacer-recherche" title="Effacer">
                <i class="bi bi-x"></i>
            </button>
            <span id="nb-visibles" class="text-muted" style="font-size:.76rem;white-space:nowrap;"></span>
        </div>

        <div id="ja-list-wrapper">
            <table id="tbl-ja">
                <thead id="tbl-ja-thead">
                    <tr>
                        <th style="width:28px">
                            <input type="checkbox" id="chk-header" checked title="Tout cocher/décocher">
                        </th>
                        <th class="col-sort" data-col="1">Nom <span class="sort-icon">↕</span></th>
                        <th class="col-sort" data-col="2">Prénom <span class="sort-icon">↕</span></th>
                        <th>Email</th>
                    </tr>
                </thead>
                <tbody id="tbody-ja">
                    <tr><td colspan="4" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>

    </div>
</div>

<!-- Modal aperçu email convocation -->
<div class="modal fade" id="modal-apercu" tabindex="-1" aria-labelledby="modal-apercu-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="modal-apercu-label"><i class="bi bi-envelope me-2"></i>Aperçu de l'email</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="px-3 py-2 border-bottom bg-light" style="font-size:.82rem;">
                    <span class="text-muted me-1">Sujet :</span>
                    <strong id="apercu-sujet"></strong>
                </div>
                <div id="apercu-corps" class="p-3" style="white-space:pre-wrap;font-size:.88rem;line-height:1.6;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const CENTRENVOYE_BASE = '<?= site_url('centrenvoye') ?>';
const MODELES  = <?= json_encode($modeles, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const MON_EMAIL = <?= json_encode($monEmail, JSON_HEX_TAG | JSON_HEX_APOS) ?>;

const TITRES_JA = {
    'Disponibilites':  'JA actifs du département',
    'Rappel dispo':    'JA sans disponibilités saisies',
    'Convocation':     'JA nominés — journée sélectionnée',
    'Liste nomination':'JA avec nominations dans la phase',
    'Demande adresse': 'JA actifs ou sans adresse domicile',
};

let typeActif  = 'Disponibilites';
let saisonCourante = null;
const sortState = { col: 1, asc: true };

// ── Utilitaires ───────────────────────────────────────────────────────────────
// ── Onglets ───────────────────────────────────────────────────────────────────
$('.type-tab').on('click', function () {
    typeActif = $(this).data('type');
    $('.type-tab').removeClass('active');
    $(this).addClass('active');
    chargerModele(typeActif);
    majColonnesJA(typeActif);
    const isConv = typeActif === 'Convocation';
    $('#journee-bar').toggleClass('visible', isConv);
    if (isConv) {
        chargerJournees();
    } else {
        chargerJA();
    }
});

function chargerModele(type) {
    const m = MODELES[type] || { sujet: '', message: '', cc: false };
    $('#txt-sujet').val(m.sujet || '');
    $('#txt-message').val(m.message || '');
    $('#grp-cc').toggle(!!m.cc);
    $('#txt-cc').val(m.cc ? MON_EMAIL : '');
    // Afficher/masquer les sections du cartouche selon le type actif
    $('#cart-convocation').toggle(type === 'Convocation');
    $('#cart-liste-nom').toggle(type === 'Liste nomination');
    $('#cart-demande-adresse').toggle(type === 'Demande adresse');
    $('#ja-header-titre').text(TITRES_JA[type] || 'JA');
    $('#result-envoi').html('');
}

function majColonnesJA(type) {
    const $thead = $('#tbl-ja-thead tr');
    $thead.find('th:gt(3)').remove();
    if (type === 'Convocation') {
        $thead.append(
            '<th>Date</th><th>Heure</th><th>Division</th><th>Dom vs Ext</th><th class="text-center">Km</th>'
        );
    } else if (type === 'Liste nomination') {
        $thead.append('<th class="text-center">Nominations</th>');
    } else if (type === 'Demande adresse') {
        $thead.append('<th>CP / Ville actuelle</th>');
    }
    $('#alerte-sans-km').remove();
}

// ── Journées (Convocation) ───────────────────────────────────────────────────
function chargerJournees() {
    $.get(`${CENTRENVOYE_BASE}/journees`, function (res) {
        const $cbo = $('#cbo-journee').empty().append('<option value="">— Sélectionner —</option>');
        if (res.ok) {
            saisonCourante = res.saison;
            res.data.forEach(j => {
                const dateFr = j.Date ? j.Date.split('-').reverse().join('/') : '';
                $cbo.append($('<option>').val(`${j.Journee}|${j.Date}`).text(
                    `J${j.Journee} — ${dateFr} — ${j.NbJA} JA nominé(s)`
                ));
            });
        }
        $('#tbody-ja').html('<tr><td colspan="8" class="text-center text-muted py-2">Sélectionner une journée</td></tr>');
    }, 'json');
}

$('#cbo-journee').on('change', function () {
    if ($(this).val()) chargerJA();
    else $('#tbody-ja').html('<tr><td colspan="8" class="text-center text-muted py-2">Sélectionner une journée</td></tr>');
});

// ── Chargement JA ─────────────────────────────────────────────────────────────
function chargerJA() {
    const data = { type: typeActif };
    if (typeActif === 'Convocation') {
        const val = $('#cbo-journee').val();
        if (!val) return;
        const [journee, date] = val.split('|');
        data.journee = journee;
        data.date    = date;
    }

    const colSpan = typeActif === 'Convocation' ? 8 : (typeActif === 'Liste nomination' ? 5 : (typeActif === 'Demande adresse' ? 5 : 4));
    $('#tbody-ja').html(`<tr><td colspan="${colSpan}" class="text-center text-muted py-2"><i class="bi bi-hourglass-split me-1"></i>Chargement…</td></tr>`);

    $.get(`${CENTRENVOYE_BASE}/ja`, data, function (res) {
        saisonCourante = res.saison;
        const $body = $('#tbody-ja').empty();
        if (!res.ok || !res.data.length) {
            $body.append(`<tr><td colspan="${colSpan}" class="text-center text-muted py-3">Aucun JA.</td></tr>`);
            $('#nb-ja').text('');
            return;
        }
        let nbEmail = 0;
        const sansKm = [];
        res.data.forEach(ja => {
            const aEmail = ja.Email && ja.Email.trim() !== '';
            if (aEmail) nbEmail++;
            const emailCell = aEmail ? `<span>${ja.Email}</span>`
                : `<span class="no-email"><i class="bi bi-exclamation-triangle me-1"></i>Pas d'email</span>`;

            const rowId = (typeActif === 'Convocation') ? ja.Id_Nomination : ja.Id_JA;
            const $tr = $('<tr>').attr('data-id', rowId).append(
                $('<td>').append($('<input type="checkbox">').prop('checked', aEmail).prop('disabled', !aEmail).addClass('chk-ja')),
                $('<td>').text(ja.Nom),
                $('<td>').text(ja.Prenom),
                $('<td>').html(emailCell)
            );

            if (typeActif === 'Convocation') {
                const km = parseInt(ja.Kilometre ?? 0);
                const kmCell = km > 0
                    ? $('<td class="text-center fw-semibold text-success">').text(km + ' km')
                    : $('<td class="text-center">').html('<span class="badge bg-warning text-dark" style="font-size:.7rem;"><i class="bi bi-exclamation-triangle me-1"></i>—</span>');
                if (km === 0) sansKm.push(`${ja.Prenom} ${ja.Nom}`);
                $tr.append(
                    $('<td>').text(ja.Date ?? ''),
                    $('<td>').text(ja.Heure ?? ''),
                    $('<td>').text(ja.Division ?? ''),
                    $('<td>').text((ja.NomDom ?? '') + (ja.NomExt ? ' vs ' + ja.NomExt : '')),
                    kmCell
                );
            } else if (typeActif === 'Liste nomination') {
                $tr.append($('<td class="text-center">').text(ja.NbNominations ?? ''));
            } else if (typeActif === 'Demande adresse') {
                const adresse = [ja.Cp, ja.Ville].filter(Boolean).join(' ') || '—';
                $tr.append($('<td class="text-muted" style="font-size:.85rem;">').text(adresse));
            }
            $body.append($tr);
        });

        // Bandeau d'alerte JA sans kilométrage (Convocation uniquement)
        $('#alerte-sans-km').remove();
        if (typeActif === 'Convocation' && sansKm.length > 0) {
            const $alerte = $(`
                <div id="alerte-sans-km" class="mx-0 px-3 py-2 border-bottom"
                     style="background:#fff8e1;border-left:4px solid #f59e0b!important;font-size:.8rem;flex-shrink:0;">
                    <span style="font-weight:700;color:#92400e;">
                        <i class="bi bi-exclamation-triangle-fill me-1 text-warning"></i>${sansKm.length} JA sans kilométrage :
                    </span>
                    <span class="text-muted">${sansKm.join(', ')}</span>
                </div>
            `);
            $('#ja-list-wrapper').before($alerte);
        }

        $('#nb-ja').text(`${res.data.length} JA — ${nbEmail} avec email`);
    }, 'json');
}

// ── Aperçu email convocation au clic sur une ligne ────────────────────────────
$(document).on('click', '#tbody-ja tr', function (e) {
    if (typeActif !== 'Convocation') return;
    if ($(e.target).is('input, label')) return;
    const idNomination = $(this).data('id');
    const sujet   = $('#txt-sujet').val().trim();
    const message = $('#txt-message').val().trim();
    if (!sujet && !message) { toast('Saisissez un sujet et un message avant de prévisualiser.', false); return; }
    $.post(`${CENTRENVOYE_BASE}/apercu`, { id_nomination: idNomination, sujet, message }, function (r) {
        if (!r.ok) { toast(r.msg, false); return; }
        $('#apercu-sujet').text(r.sujet);
        $('#apercu-corps').text(r.corps);
        new bootstrap.Modal(document.getElementById('modal-apercu')).show();
    }, 'json');
});

// ── Clic sur un marqueur : insérer à la position du curseur ──────────────────
let dernierChamp = 'message'; // 'sujet' ou 'message'
$('#txt-sujet').on('focus', () => { dernierChamp = 'sujet'; });
$('#txt-message').on('focus', () => { dernierChamp = 'message'; });

$(document).on('click', '[data-marqueur]', function () {
    const ta    = document.getElementById(dernierChamp === 'sujet' ? 'txt-sujet' : 'txt-message');
    const texte = $(this).data('marqueur');
    const debut = ta.selectionStart ?? ta.value.length;
    const fin   = ta.selectionEnd   ?? debut;
    ta.setRangeText(texte, debut, fin, 'end');
    ta.focus();
});

// ── Tri ───────────────────────────────────────────────────────────────────────
function trierJA() {
    const col = parseInt(sortState.col, 10);
    $('.sort-icon').text('↕');
    $(`.col-sort[data-col="${col}"] .sort-icon`).text(sortState.asc ? '↑' : '↓');
    const rows = $('#tbody-ja tr').toArray();
    rows.sort((a, b) => {
        const va = $(a).find('td').eq(col).text().trim().toLowerCase();
        const vb = $(b).find('td').eq(col).text().trim().toLowerCase();
        return sortState.asc ? va.localeCompare(vb, 'fr') : vb.localeCompare(va, 'fr');
    });
    rows.forEach(r => $('#tbody-ja').append(r));
    filtrerJA();
}

// Différé : nijac-sortable-table.js est chargé en fin de page, donc pas encore
// défini si on l'appelait ici de façon synchrone.
$(function () { nijacSortableTable('.col-sort', 'col', sortState, trierJA); });

// ── Recherche ─────────────────────────────────────────────────────────────────
function filtrerJA() {
    const terme = $('#txt-recherche-ja').val().toLowerCase().trim();
    let visibles = 0;
    $('#tbody-ja tr').each(function () {
        const ok = terme === '' || $(this).text().toLowerCase().includes(terme);
        $(this).toggleClass('masque', !ok);
        if (ok) visibles++;
    });
    $('#nb-visibles').text(terme ? `${visibles} / ${$('#tbody-ja tr').length}` : '');
}

$('#txt-recherche-ja').on('input', filtrerJA);
$('#btn-effacer-recherche').on('click', () => $('#txt-recherche-ja').val('').trigger('input').trigger('focus'));

// ── Cocher/décocher ───────────────────────────────────────────────────────────
$('#chk-header').on('change', function () { $('.chk-ja:not(:disabled)').prop('checked', this.checked); });
$('#tbody-ja').on('change', '.chk-ja', function () {
    const total  = $('.chk-ja:not(:disabled)').length;
    const coches = $('.chk-ja:not(:disabled):checked').length;
    $('#chk-header').prop('checked', coches === total);
});

// ── Envoi ─────────────────────────────────────────────────────────────────────
$('#btn-envoyer').on('click', function () {
    const sujet   = $('#txt-sujet').val().trim();
    const message = $('#txt-message').val().trim();
    const ids = [], noms = [];

    $('#tbody-ja tr:not(.masque)').each(function () {
        if ($(this).find('.chk-ja').is(':checked')) {
            ids.push($(this).data('id'));
            const tds = $(this).find('td');
            noms.push(`${tds.eq(1).text().trim()} ${tds.eq(2).text().trim()}`);
        }
    });

    if (!sujet || !message) { toast('Le sujet et le message sont obligatoires.', false); return; }
    if (!ids.length)         { toast('Aucun destinataire sélectionné.', false); return; }

    nijacConfirm(`Envoyer le message à ${ids.length} JA ?\n\n${noms.join('\n')}`, function () {
        demarrerEnvoi(sujet, message, ids);
    });
});

function demarrerEnvoi(sujet, message, ids) {
    const total   = ids.length;
    let envoyes   = 0, echecs = 0, sansEmail = 0;
    const erreursDetail = [];

    function demarrerProgress() {
        $('#btn-envoyer').prop('disabled', true).html('<i class="bi bi-hourglass-split me-1"></i>Envoi en cours…');
        $('#result-envoi').html('');
        $('#envoi-progress').show();
        $('#progress-bar').css('width', '0%').removeClass('bg-danger').addClass('bg-success progress-bar-animated');
        $('#progress-erreurs').html('');
        majProgress(0);
    }

    function majProgress(fait) {
        const pct   = total > 0 ? Math.round(fait / total * 100) : 0;
        $('#progress-bar').css('width', pct + '%').attr('aria-valuenow', pct);
        $('#progress-label').text(`Envoi ${fait} / ${total}…`);
        $('#progress-counts').html(
            `<span class="text-success">${envoyes} ✓</span>` +
            (echecs   > 0 ? ` &nbsp;<span class="text-danger">${echecs} ✗</span>` : '') +
            (sansEmail > 0 ? ` &nbsp;<span class="text-muted">${sansEmail} sans email</span>` : '')
        );
    }

    function terminerProgress() {
        const ok = echecs === 0;
        $('#progress-bar')
            .css('width', '100%')
            .removeClass('progress-bar-animated' + (ok ? '' : ' bg-success'))
            .addClass(ok ? '' : 'bg-danger');
        $('#progress-label').html(ok
            ? `<span class="text-success fw-bold"><i class="bi bi-check-circle me-1"></i>Envoi terminé</span>`
            : `<span class="text-danger fw-bold"><i class="bi bi-exclamation-triangle me-1"></i>Envoi terminé avec ${echecs} erreur(s)</span>`
        );
        $('#progress-counts').html(
            `<span class="text-success fw-semibold">${envoyes} envoyé(s)</span>` +
            (echecs   > 0 ? ` &nbsp;<span class="text-danger">${echecs} échec(s)</span>` : '') +
            (sansEmail > 0 ? ` &nbsp;<span class="text-muted">${sansEmail} sans email</span>` : '')
        );
        if (erreursDetail.length) {
            $('#progress-erreurs').html(erreursDetail.map(e => `<i class="bi bi-x-circle me-1"></i>${e.nom} — ${e.msg}`).join('<br>'));
        }
        $('#btn-envoyer').prop('disabled', false).html('<i class="bi bi-send me-1"></i>Envoyer');
        toast(ok ? `${envoyes} email(s) envoyé(s).` : `${envoyes} envoyé(s), ${echecs} échec(s).`, ok);
    }

    // Vérification initiale (rate limit global + comptage sans-email)
    $.post(`${CENTRENVOYE_BASE}/envoyer`, {
        sujet, message,
        ids: JSON.stringify(ids),
    }, function (res) {
        if (!res.ok) { toast(res.msg, false); return; }
        sansEmail = res.sans_email || 0;

        demarrerProgress();

        // Envoi séquentiel un par un
        let idx = 0;
        function envoyerSuivant() {
            if (idx >= ids.length) { terminerProgress(); return; }
            const id = ids[idx++];
            const postData = {
                type:    typeActif,
                sujet, message,
                saison:  saisonCourante ?? '',
                cc:      $('#grp-cc').is(':visible') ? $('#txt-cc').val().trim() : '',
            };
            if (typeActif === 'Convocation') {
                postData.id_nomination = id;
            } else {
                postData.id_ja = id;
            }
            $.post(`${CENTRENVOYE_BASE}/envoyer-un`, postData, function (r) {
                if (r.ok) {
                    envoyes++;
                } else if (r.skip) {
                    // pas d'email, déjà compté dans sansEmail
                } else {
                    echecs++;
                    erreursDetail.push({ nom: r.nom || `JA #${id}`, msg: r.msg || 'Erreur inconnue' });
                }
                majProgress(idx);
                envoyerSuivant();
            }, 'json').fail(function () {
                echecs++;
                erreursDetail.push({ nom: `JA #${id}`, msg: 'Erreur réseau' });
                majProgress(idx);
                envoyerSuivant();
            });
        }
        envoyerSuivant();

    }, 'json').fail(function () {
        toast('Erreur réseau lors de la vérification.', false);
    });
}

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    chargerModele(typeActif);
    chargerJA();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
