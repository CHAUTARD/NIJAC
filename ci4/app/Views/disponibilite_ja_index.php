<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Disponibilités JA (EN22)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <style>
        :root {
            --col-dispo:   #2e7d32;
            --col-partiel: #e65100;
            --col-nodispo: #c62828;
        }

        body {
            background: #f0f4fa;
            font-family: 'Segoe UI', system-ui, sans-serif;
            min-height: 100vh;
        }

        /* ── En-tête ─────────────────────────────────────────────────────── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: .55rem;
            position: sticky;
            top: 0;
            z-index: 200;
        }
        #page-header h1 { font-size: 1rem; font-weight: 700; margin: 0; }

        /* ── Identité JA inline dans l'en-tête ──────────────────────────── */
        .ja-header-sep { opacity: .4; font-weight: 300; font-size: 1.1rem; }
        #ja-info-bar .ja-ib-nom {
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
        }
        #ja-info-bar .ja-ib-loc {
            font-size: .82rem;
            color: rgba(255,255,255,.8);
            display: flex;
            align-items: center;
            gap: .3rem;
        }
        #ja-info-bar .ja-ib-grade {
            background: rgba(255,255,255,.22);
            color: #fff;
            border-radius: 10px;
            padding: .1rem .5rem;
            font-size: .75rem;
            font-weight: 600;
        }

        /* ── Toolbar utilisateur (visible seulement si session) ────────── */
        #toolbar {
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .3rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .85rem;
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
        #btn-switch-admin {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            background: var(--nijac-blue);
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        #btn-switch-nominateur {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .25rem .75rem;
            background: #2e7d32;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        #btn-switch-nominateur:hover { background: #1b5e20; color: #fff; }

        /* ── Barre saison ────────────────────────────────────────────────── */
        #barre-saison {
            background: #fff;
            border-bottom: 1px solid #d0d8e8;
            padding: .5rem 1.25rem;
            display: none;
            align-items: center;
            gap: 1rem;
        }

        .spin-sm {
            display: inline-block;
            width: 1rem; height: 1rem;
            border: 2px solid #dee2e6;
            border-top-color: var(--nijac-blue);
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Vue calendrier ──────────────────────────────────────────────── */
        #section-cal-grille {
            max-width: 1700px; margin: 0 auto;
            padding: .75rem 1rem 1rem;
            display: none;
        }
        .cal-legende {
            display: flex; gap: 1.2rem; flex-wrap: wrap;
            align-items: center; margin-bottom: .75rem;
            font-size: .78rem; color: #555;
        }
        .cal-legende-item { display: flex; align-items: center; gap: .35rem; }
        .cal-dot {
            width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0;
        }
        .dot-O { background: var(--col-dispo); }
        .dot-N { background: var(--col-nodispo); }
        .dot-vide { background: #cbd5e1; border: 1px solid #94a3b8; }

        .cal-mois-grille {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1rem;
        }
        .cal-mois {
            background: #fff; border: 1px solid #d0d8e8;
            border-radius: 10px; overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,.07);
        }
        .cal-mois-titre {
            background: var(--nijac-blue); color: #fff;
            text-align: center; font-size: 1.05rem; font-weight: 700;
            padding: .6rem;
        }
        .cal-semaine-header {
            display: grid; grid-template-columns: repeat(7, 1fr);
            text-align: center; font-size: .85rem; font-weight: 700;
            color: #888; padding: .4rem 0 .15rem;
        }
        .cal-semaine-header span:last-child { color: #c62828; }
        .cal-grid {
            display: grid; grid-template-columns: repeat(7, 1fr);
            padding: .3rem .35rem .5rem;
        }
        .cal-jour {
            aspect-ratio: 1; display: flex; align-items: center;
            justify-content: center; border-radius: 50%;
            font-size: 1rem; margin: 2px;
            cursor: default; user-select: none;
        }
        .cal-jour.vide { color: transparent; }
        .cal-jour.autre-mois { color: #ccc; }
        .cal-jour.jour-journee {
            font-weight: 700; cursor: pointer;
            transition: filter .12s, transform .1s;
        }
        .cal-jour.jour-journee:hover { filter: brightness(1.12); transform: scale(1.1); }
        .cal-jour.statut-O { background: var(--col-dispo);   color: #fff; }
        .cal-jour.statut-N { background: var(--col-nodispo); color: #fff; }
        .cal-jour.statut-vide { background: #e2e8f0; color: #475569; }
        .cal-jour.today { outline: 2px solid var(--nijac-blue); outline-offset: 1px; }
    </style>
</head>
<body>

<!-- ── En-tête unifié ─────────────────────────────────────────────────────── -->
<div id="page-header">
    <i class="bi bi-calendar2-check fs-5 flex-shrink-0"></i>
    <h1>Disponibilités JA <small class="opacity-75" style="font-size:.75rem;">(EN22)</small></h1>
    <span id="ja-info-bar" style="display:none;align-items:center;gap:.65rem;flex-wrap:wrap">
        <span class="ja-header-sep">|</span>
        <i class="bi bi-person-badge flex-shrink-0" style="opacity:.7"></i>
        <span class="ja-ib-nom" id="ja-ib-nom"></span>
        <span class="ja-ib-grade" id="ja-ib-grade" style="display:none"></span>
        <span class="ja-ib-loc" id="ja-ib-loc" style="display:none">
            <i class="bi bi-geo-alt-fill" style="color:rgba(255,255,255,.6)"></i>
            <span id="ja-ib-cp"></span> <span id="ja-ib-ville"></span>
        </span>
    </span>
    <label id="lbl-defisc" class="d-none ms-auto align-items-center gap-2"
           style="font-size:.84rem;font-weight:700;color:#fff;cursor:pointer;user-select:none;">
        <input type="checkbox" id="chk-defisc" style="width:1.1rem;height:1.1rem;cursor:pointer;accent-color:#2e7d32;">
        Défiscalisation
    </label>
    <a href="<?= base_url('Documentation/Plaquette_Defiscalisation.pdf') ?>" target="_blank"
       class="btn btn-sm ms-1"
       style="background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7;font-size:.84rem;font-weight:700;"
       title="Ouvrir la plaquette de défiscalisation (PDF)">
        <i class="bi bi-file-earmark-pdf-fill me-1"></i>Défiscalisation
    </a>
    <button id="btn-note" class="btn btn-sm ms-1 d-none"
            style="background:#f0c040;color:#1a3a6b;border:none;font-size:.84rem;font-weight:700;box-shadow:0 2px 6px rgba(0,0,0,.3)"
            data-bs-toggle="modal" data-bs-target="#modal-note">
        <i class="bi bi-sticky-fill me-1"></i>Note
    </button>
</div>

<!-- Toolbar utilisateur (visible seulement si une session existe déjà) -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowLabel' => false, 'tbSwitchTo' => $tbSwitchTo]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- ── Modale Note ─────────────────────────────────────────────────────────── -->
<div class="modal fade" id="modal-note" tabindex="-1" aria-labelledby="modal-note-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--nijac-blue);color:#fff">
                <h5 class="modal-title" id="modal-note-label">
                    <i class="bi bi-sticky me-2"></i>Note à destination des nominateurs
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="font-size:.83rem">
                    Cette note est visible par les nominateurs lors de la désignation des juges-arbitres.
                </p>
                <textarea id="note-texte" class="form-control" rows="6"
                          placeholder="Saisissez ici vos informations (contraintes ponctuelles, préférences, indisponibilités particulières…)"></textarea>
            </div>
            <div class="modal-footer">
                <span id="note-spin" class="spin-sm d-none me-auto"></span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
                <button type="button" id="btn-save-note" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-floppy me-1"></i>Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modale Note / Département (ouverte au passage en Disponible) ─────────── -->
<div class="modal fade" id="modal-journee" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--nijac-blue);color:#fff;padding:.6rem 1rem">
                <h5 class="modal-title" id="mj-titre" style="font-size:.95rem;font-weight:700">
                    <i class="bi bi-calendar-week me-2"></i>Disponible
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="mj-commentaire" class="alert alert-info py-2 px-3" style="font-size:.85rem;display:none"></div>
                <div id="mj-departements" style="display:none" class="mb-3">
                    <label class="form-label fw-bold" style="font-size:.85rem">Département(s) concerné(s)</label>
                    <div class="d-flex flex-wrap gap-3">
                        <?php foreach (['14', '27', '50', '61', '76'] as $d): ?>
                        <label style="font-size:.85rem">
                            <input type="checkbox" class="form-check-input me-1 mj-dept" value="<?= $d ?>"><?= $d ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <label class="form-label fw-bold" style="font-size:.85rem">Note (facultatif)</label>
                <textarea id="mj-note" class="form-control" rows="3" placeholder="Précision éventuelle…"></textarea>
            </div>
            <div class="modal-footer py-2">
                <span id="mj-spin" class="spin-sm d-none me-auto"></span>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
                <button type="button" id="mj-btn-save" class="btn btn-primary btn-sm px-4">
                    <i class="bi bi-floppy me-1"></i>Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Message d'erreur si aucun paramètre JA dans l'URL -->
<div id="section-erreur" style="display:none" class="alert alert-warning m-3">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    Aucun identifiant JA fourni. Veuillez utiliser le lien personnalisé qui vous a été communiqué.
</div>

<!-- ── Barre spin ─────────────────────────────────────────────────────────── -->
<div id="barre-saison" style="display:none">
    <span id="barre-spin" style="display:none"><span class="spin-sm"></span></span>
</div>

<!-- ── Vue calendrier mensuelle ───────────────────────────────────────── -->
<div id="section-cal-grille">
    <div id="nav-saison" style="display:flex;align-items:center;gap:.75rem;padding:.5rem 0 .6rem;flex-wrap:wrap;">
        <span id="lbl-saison-cal" style="font-size:.92rem;font-weight:700;color:var(--nijac-blue);flex:1;text-align:center;"></span>
    </div>
    <div class="cal-legende">
        <span class="cal-legende-item"><span class="cal-dot dot-O"></span>Disponible</span>
        <span class="cal-legende-item"><span class="cal-dot dot-N"></span>Non disponible</span>
        <span class="cal-legende-item"><span class="cal-dot dot-vide"></span>Pas de réponse</span>
    </div>
    <div class="cal-mois-grille" id="cal-mois-grille"></div>
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const BASE = '<?= site_url('disponibilite-ja') ?>';
// Rejoué sur chaque appel d'écriture : resolveIdJaAutorise() (contrôleur) exige
// ce token à chaque requête, un JA public n'ayant pas de session pour en tenir lieu.
const TOKEN_JA = <?= json_encode($tokenJa) ?>;

// Saison courante lue depuis la configuration (ex : "2026/2027")
const CONFIG_SAISON = <?= json_encode(getConfig('saison') ?: '') ?>;
// Phase courante (1 ou 2), déterminée par getAnneePhase() (bornes phase2_debut/phase2_fin)
const CONFIG_PHASE = <?= strpos(getAnneePhase(), '-') !== false ? 2 : 1 ?>;
// Bornes de la phase 2 (mois calendaire 1-12) — le reste de la saison est la phase 1.
const PHASE2_MOIS_DEBUT = <?= (int) substr(getConfig('phase2_debut', '02-01'), 0, 2) ?>;
const PHASE2_MOIS_FIN   = <?= (int) substr(getConfig('phase2_fin',   '06-30'), 0, 2) ?>;

(function () {
    const m = CONFIG_SAISON.match(/(\d{4})/);
    window._yrDebutConfig = m ? +m[1] : (function () {
        const n = new Date(); return n.getMonth() >= 8 ? n.getFullYear() : n.getFullYear() - 1;
    })();
})();

let idJaCourant  = null;
let nomJaCourant = '';
let jaDept       = '';

let etatDates = {};

const JOURS_LONG = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
const MOIS_LONG  = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

function formatDateLong(d) {
    if (!d) return '';
    const [y, m, j] = d.split('-').map(Number);
    const wd = new Date(y, m-1, j).getDay();
    return `${JOURS_LONG[wd]} ${j} ${MOIS_LONG[m-1]}`;
}

function chargerInfosJA() {
    $.getJSON(`${BASE}/ja`, { id: idJaCourant }, function (r) {
        if (!r.ok) return;
        nomJaCourant = r.data.Nom + ' ' + r.data.Prenom;

        $('#ja-ib-nom').text(nomJaCourant);
        if (r.data.Grade) {
            $('#ja-ib-grade').text(r.data.Grade).show();
        } else {
            $('#ja-ib-grade').hide();
        }
        jaDept = (r.data.Cp || '').substring(0, 2);
        const hasloc = r.data.Cp || r.data.Ville;
        if (hasloc) {
            $('#ja-ib-cp').text(r.data.Cp || '');
            $('#ja-ib-ville').text(r.data.Ville || '');
            $('#ja-ib-loc').show();
        } else {
            $('#ja-ib-loc').hide();
        }
        $('#ja-info-bar').css('display', 'flex');
        $('#chk-defisc').prop('checked', +r.data.Defiscalisation === 1);
        $('#lbl-defisc').addClass('d-flex').removeClass('d-none');
        $('#btn-note').removeClass('d-none');
    });
}

$('#chk-defisc').on('change', function () {
    $.post(`${BASE}/sauvegarder-defiscalisation`, {
        id_ja:           idJaCourant,
        ja:              TOKEN_JA,
        defiscalisation: this.checked ? 1 : 0,
    }, function (r) {
        if (!r.ok) toast('Erreur défiscalisation : ' + r.err, false);
    }, 'json');
});

$('#modal-note').on('show.bs.modal', function () {
    $('#note-texte').val('').prop('disabled', true);
    $('#note-spin').removeClass('d-none');
    $.getJSON(`${BASE}/lire-note`, { id_ja: idJaCourant, ja: TOKEN_JA }, function (r) {
        $('#note-spin').addClass('d-none');
        $('#note-texte').val(r.ok ? (r.note || '') : '').prop('disabled', false).trigger('focus');
    });
});

$('#btn-save-note').on('click', function () {
    const note = $('#note-texte').val();
    $('#note-spin').removeClass('d-none');
    $('#btn-save-note').prop('disabled', true);
    $.post(`${BASE}/sauvegarder-note`, {
        id_ja: idJaCourant,
        ja:    TOKEN_JA,
        note:  note,
    }, function (r) {
        $('#note-spin').addClass('d-none');
        $('#btn-save-note').prop('disabled', false);
        if (r.ok) {
            bootstrap.Modal.getInstance($('#modal-note')[0]).hide();
            toast('Note enregistrée.');
        } else {
            toast('Erreur : ' + r.err, false);
        }
    }, 'json').fail(function () {
        $('#note-spin').addClass('d-none');
        $('#btn-save-note').prop('disabled', false);
        toast('Erreur réseau.', false);
    });
});

function chargerJournees() {
    if (!idJaCourant) return;
    $('#section-cal-grille').show();
    $('#cal-mois-grille').html(
        '<div class="text-muted text-center py-5"><span class="spin-sm me-2"></span>Chargement du calendrier…</div>'
    );

    $.getJSON(`${BASE}/journees`, {
        id_ja: idJaCourant,
    }, function (r) {
        if (!r.ok) {
            $('#cal-mois-grille').html(`<div class="alert alert-danger m-3">${r.err}</div>`);
            return;
        }
        if (!r.data.length) {
            $('#cal-mois-grille').html(
                '<div class="alert alert-info m-3"><i class="bi bi-info-circle me-2"></i>Aucune date de championnat régional programmée.</div>'
            );
            return;
        }
        etatDates = {};
        r.data.forEach(function (d) {
            etatDates[d.Id_CompetitionRegionale] = {
                id: d.Id_CompetitionRegionale,
                date: d.Date,
                heure: d.Heure,
                commentaire: d.Commentaire || '',
                statut: d.Statut || null,
                departement: d.Departement || '',
                note: d.Note || '',
            };
        });
        renderCalendrierMensuel();
    });
}

const MOIS_NOMS = ['Janvier','Février','Mars','Avril','Mai','Juin',
                   'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
const JOURS_COURTS = ['L','M','M','J','V','S','D'];

let jourDetailMap = {};

function renderCalendrierMensuel() {
    jourDetailMap = {};
    Object.values(etatDates).forEach(e => {
        jourDetailMap[e.date] = { id: e.id, statut: e.statut || 'vide', heure: e.heure };
    });

    const today = new Date();
    today.setHours(0,0,0,0);

    const yrDebut = window._yrDebutConfig;

    $('#lbl-saison-cal').text(`Phase ${CONFIG_PHASE} / ${yrDebut}-${yrDebut + 1}`);

    const moisSaison = [
        [yrDebut,     8],
        [yrDebut,     9],
        [yrDebut,    10],
        [yrDebut,    11],
        [yrDebut + 1, 0],
        [yrDebut + 1, 1],
        [yrDebut + 1, 2],
        [yrDebut + 1, 3],
        [yrDebut + 1, 4],
        [yrDebut + 1, 5],
    ].filter(([, mois]) => {
        const enPhase2 = (mois + 1) >= PHASE2_MOIS_DEBUT && (mois + 1) <= PHASE2_MOIS_FIN;
        return CONFIG_PHASE === 2 ? enPhase2 : !enPhase2;
    });

    const $grille = $('#cal-mois-grille').empty()
        .css('grid-template-columns', `repeat(${moisSaison.length}, 1fr)`);
    moisSaison.forEach(([annee, mois]) => {
        $grille.append(buildMonthGrid(annee, mois, today));
    });
}

function buildMonthGrid(annee, mois, today) {
    const $wrap = $('<div class="cal-mois">');
    $wrap.append(`<div class="cal-mois-titre">${MOIS_NOMS[mois]} ${annee}</div>`);

    const $hdr = $('<div class="cal-semaine-header">');
    JOURS_COURTS.forEach(j => $hdr.append(`<span>${j}</span>`));
    $wrap.append($hdr);

    const $grid = $('<div class="cal-grid">');

    const premier = (new Date(annee, mois, 1).getDay() + 6) % 7;
    for (let i = 0; i < premier; i++) {
        $grid.append('<div class="cal-jour vide"></div>');
    }

    const nbJours = new Date(annee, mois + 1, 0).getDate();
    for (let j = 1; j <= nbJours; j++) {
        const dateStr = `${annee}-${String(mois+1).padStart(2,'0')}-${String(j).padStart(2,'0')}`;
        const detail  = jourDetailMap[dateStr];
        const isToday = (new Date(annee, mois, j).getTime() === today.getTime());

        let cls = 'cal-jour';
        if (detail) cls += ' jour-journee statut-' + detail.statut;
        if (isToday) cls += ' today';

        const $jour = $(`<div class="${cls}">${j}</div>`);

        if (detail) {
            $jour.attr('data-id', detail.id);
            $jour.attr('title', `${formatDateLong(dateStr)}${detail.heure ? ' — ' + detail.heure.substring(0,5) : ''}\nCliquez pour ${libelleClicSuivant(detail.statut === 'vide' ? null : detail.statut)}`);
            $jour.on('click', () => toggleDate(detail.id));
        }

        $grid.append($jour);
    }

    $wrap.append($grid);
    return $wrap;
}

function libelleClicSuivant(statut) {
    if (statut === 'O') return 'indiquer non disponible';
    if (statut === 'N') return 'effacer la réponse';
    return 'indiquer disponible';
}

// Cycle à 3 états : pas de réponse → Disponible → Non disponible → pas de réponse…
function toggleDate(idComp) {
    const e = etatDates[idComp];
    if (!e) return;
    const nouveau = e.statut === 'O' ? 'N' : (e.statut === 'N' ? null : 'O');
    e.statut = nouveau;
    if (nouveau !== 'O') { e.note = ''; e.departement = ''; }

    const cls = nouveau || 'vide';
    jourDetailMap[e.date].statut = cls;
    $(`.cal-jour[data-id="${idComp}"]`)
        .removeClass('statut-O statut-N statut-vide')
        .addClass('statut-' + cls)
        .attr('title', `${formatDateLong(e.date)}${e.heure ? ' — ' + e.heure.substring(0,5) : ''}\nCliquez pour ${libelleClicSuivant(nouveau)}`);

    sauvegarderDate(idComp, nouveau === 'O');
}

function sauvegarderDate(idComp, ouvrirPopupSiDispo) {
    const e = etatDates[idComp];

    $.post(`${BASE}/sauvegarder-dispo-journee`, {
        id_ja: idJaCourant,
        ja: TOKEN_JA,
        date: e.date,
        statut: e.statut || 'VIDE',
        note: e.note || '',
        departements: e.departement ? e.departement.split(',') : [],
    }, function (r) {
        if (!r.ok) { toast('Erreur : ' + r.err, false); return; }
        if (ouvrirPopupSiDispo) ouvrirPopupNote(idComp);
    }, 'json').fail(function () {
        toast('Erreur réseau.', false);
    });
}

let modalJournee = null;

function ouvrirPopupNote(idComp) {
    const e = etatDates[idComp];

    $('#mj-titre').html(`<i class="bi bi-calendar-week me-2"></i>Disponible — ${formatDateLong(e.date)}`);

    if (e.commentaire) {
        $('#mj-commentaire').text(e.commentaire).show();
        $('#mj-departements').show();
        const sel = new Set((e.departement || '').split(',').filter(Boolean));
        if (!sel.size && jaDept) sel.add(jaDept);
        $('.mj-dept').each(function () { $(this).prop('checked', sel.has($(this).val())); });
    } else {
        $('#mj-commentaire').hide();
        $('#mj-departements').hide();
    }
    $('#mj-note').val(e.note || '');
    $('#modal-journee').data('idComp', idComp);

    if (!modalJournee) modalJournee = new bootstrap.Modal($('#modal-journee')[0]);
    modalJournee.show();
}

$('#mj-btn-save').on('click', function () {
    const idComp = $('#modal-journee').data('idComp');
    const e = etatDates[idComp];
    e.note = $('#mj-note').val().trim();
    e.departement = $('.mj-dept:checked').map(function () { return this.value; }).get().join(',');

    $('#mj-spin').removeClass('d-none');
    $('#mj-btn-save').prop('disabled', true);
    $.post(`${BASE}/sauvegarder-dispo-journee`, {
        id_ja: idJaCourant,
        ja: TOKEN_JA,
        date: e.date,
        statut: 'O',
        note: e.note,
        departements: e.departement ? e.departement.split(',') : [],
    }, function (r) {
        $('#mj-spin').addClass('d-none');
        $('#mj-btn-save').prop('disabled', false);
        if (r.ok) {
            modalJournee.hide();
            toast('Disponibilité enregistrée.');
        } else {
            toast('Erreur : ' + r.err, false);
        }
    }, 'json').fail(function () {
        $('#mj-spin').addClass('d-none');
        $('#mj-btn-save').prop('disabled', false);
        toast('Erreur réseau.', false);
    });
});

$(function () {
    const idJaUrl = <?= (int) $idJa ?>;

    if (idJaUrl > 0) {
        idJaCourant = idJaUrl;
        chargerInfosJA();
        chargerJournees();
    } else {
        $('#section-erreur').show();
    }
});
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
