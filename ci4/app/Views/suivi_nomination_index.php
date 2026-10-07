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
        #panel-division { margin-top: .3rem; }

        #tbl-suivi td.num, #tbl-suivi th.num { text-align: right; }
        #tbl-suivi td.centre, #tbl-suivi th.centre { text-align: center; }
        #tbl-suivi .non-saisi { color: #9aa5b8; }
        /* valeur saisie mais non comptée (2e rencontre du jour, ou arbitrage Club) — mêmes règles qu'EN17 */
        #tbl-suivi .deja-compte { color: #9aa5b8; text-decoration: line-through; cursor: help; }
        /* rencontre sans JA nommé (#table-wrapper : passe devant le zébrage nth-child de nijac-liste-edit.css) */
        #table-wrapper #tbl-suivi tbody tr.sans-ja { background: #FFE4E8; }
        #table-wrapper #tbl-suivi tbody tr.sans-ja:hover { background: #E9ECEF; }
        #tbl-suivi .aucun-ja { color: #9aa5b8; font-style: italic; }
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
            <span style="flex:1"></span>
            <span class="count-badge" id="lbl-count">0 / 0</span>
            <span style="flex:1"></span>
            <span class="combo-field">
                <label for="sel-date">Date</label>
                <select id="sel-date" style="width:auto;">
                    <option value="">Toutes</option>
                </select>
            </span>
            <span class="combo-field">
                <label>Division</label>
                <div id="panel-division"></div>
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
                <label for="sel-saisie">Date saisie</label>
                <select id="sel-saisie" style="width:150px;">
                    <option value="">Toutes</option>
                    <option value="oui">Renseignée</option>
                    <option value="non">Non renseignée</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-avecja">Nomination</label>
                <select id="sel-avecja" style="width:120px;">
                    <option value="">Tous</option>
                    <option value="oui">Avec JA</option>
                    <option value="non">Sans JA</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-accuse">Accusé</label>
                <select id="sel-accuse" style="width:110px;">
                    <option value="">Tous</option>
                    <option value="oui">Reçu</option>
                    <option value="non">Non reçu</option>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-f131">FFTT 131</label>
                <select id="sel-f131" style="width:120px;">
                    <option value="">Tous</option>
                    <option value="oui">Mis à jour par 131</option>
                </select>
            </span>
            <button type="button" class="btn btn-sm btn-light" id="btn-reset-filtres" title="Réinitialiser les filtres">
                <i class="bi bi-x-circle"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" id="btn-f131" title="Mettre à jour les nominations depuis l'édition FFTT 131 « Activités détaillées des arbitres »">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Mise à jour FFTT 131
            </button>
        </div>
        <div id="table-wrapper">
            <table id="tbl-suivi">
                <thead>
                    <tr>
                        <th style="width:130px" data-field="date">Date<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px" data-field="division">Division<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px" data-field="arbitrage">Arbitrage<span class="sort-icon"></span></th>
                        <th data-field="domicile">Domicile<span class="sort-icon"></span></th>
                        <th data-field="exterieur">Extérieur<span class="sort-icon"></span></th>
                        <th class="centre" style="width:90px" data-field="licence">N° licence<span class="sort-icon"></span></th>
                        <th data-field="ja">JA<span class="sort-icon"></span></th>
                        <th style="width:110px" data-field="ebp">Compte EBP<span class="sort-icon"></span></th>
                        <th class="num" style="width:90px" data-field="peage">Péage<span class="sort-icon"></span></th>
                        <th class="num" style="width:90px" data-field="km">Km<span class="sort-icon"></span></th>
                        <th class="centre" style="width:110px" data-field="defisc">Défisc.<span class="sort-icon"></span></th>
                        <th class="centre" style="width:110px" data-field="saisie">Date saisie<span class="sort-icon"></span></th>
                        <th class="centre" style="width:50px" data-field="ar" title="Accusé de réception de la convocation par le JA (EN21)">AR<span class="sort-icon"></span></th>
                        <th class="centre" style="width:80px">Modifier</th>
                        <th class="centre" style="width:80px">Rappel</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="15" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Popup modification d'une nomination -->
<div class="modal fade" id="modal-modif" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="modif-titre"><i class="bi bi-pencil me-2"></i>Modifier la nomination</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div id="modif-rencontre" class="small text-muted mb-3"></div>
                <div class="row g-2">
                    <div class="col-5" id="modif-col-arbitrage">
                        <label class="form-label small mb-1" for="modif-arbitrage">Arbitrage</label>
                        <select id="modif-arbitrage" class="form-select form-select-sm">
                            <option value="1">CRA</option>
                            <option value="0">Club</option>
                        </select>
                    </div>
                    <div class="col-7">
                        <label class="form-label small mb-1" for="modif-ja">Juge-arbitre</label>
                        <select id="modif-ja" class="form-select form-select-sm"></select>
                    </div>
                    <div class="col-4 modif-frais">
                        <label class="form-label small mb-1" for="modif-peage">Péage (€)</label>
                        <input type="number" id="modif-peage" class="form-control form-control-sm" min="0" step="0.01">
                    </div>
                    <div class="col-4 modif-frais">
                        <label class="form-label small mb-1" for="modif-km">Kilomètres</label>
                        <input type="number" id="modif-km" class="form-control form-control-sm" min="0" step="1">
                    </div>
                    <div class="col-4 d-flex align-items-end modif-frais">
                        <div class="form-check mb-1">
                            <input type="checkbox" id="modif-defisc" class="form-check-input">
                            <label class="form-check-label small" for="modif-defisc">Défiscalisation</label>
                        </div>
                    </div>
                </div>
                <div class="small text-muted mt-3 modif-frais"><i class="bi bi-info-circle me-1"></i>La date de saisie sera mise à la date du jour.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-sm btn-success" id="btn-modif-enregistrer"><i class="bi bi-floppy me-1"></i>Enregistrer</button>
            </div>
        </div>
    </div>
</div>

<!-- Popup « Mise à jour FFTT 131 » : aide → fichier + Analyser (aperçu sans écriture) → Mettre à jour -->
<div class="modal fade" id="modal-f131" tabindex="-1" aria-labelledby="f131-titre" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" id="f131-titre"><i class="bi bi-file-earmark-spreadsheet me-2"></i>Mise à jour depuis l'édition FFTT 131</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <p class="small mb-2">
                    <strong>1.</strong> Générez l'édition <strong>131 - Activités détaillées des arbitres</strong> (format Excel) dans le logiciel fédéral.
                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="btn-f131-aide"><i class="bi bi-question-circle me-1"></i>Comment obtenir le fichier ?</button>
                </p>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <strong class="small">2.</strong>
                    <input type="file" id="f131-fichier" class="form-control form-control-sm" style="max-width:420px" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn-f131-analyser"><i class="bi bi-search me-1"></i>Analyser</button>
                </div>
                <p class="small text-muted mb-2">Seules les lignes dont la colonne G vaut « JA » sont utilisées ; aucune donnée n'est modifiée par l'analyse.</p>
                <div id="f131-resultat"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary me-auto d-none" id="btn-f131-csv"><i class="bi bi-filetype-csv me-1"></i>Rapport complet (CSV)</button>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Fermer</button>
                <button type="button" class="btn btn-sm btn-success" id="btn-f131-valider" disabled><i class="bi bi-check2 me-1"></i>Mettre à jour</button>
            </div>
        </div>
    </div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';
const SUIVI_BASE = '<?= site_url('suivi-nomination') ?>';
const DIVISION_NOMS = <?= json_encode($divisionNoms ?? [], JSON_UNESCAPED_UNICODE) ?>;
function libDivision(code) {
    const n = DIVISION_NOMS[code];
    return n ? code + ' — ' + n : code;
}
let nominations = [];
const filtres   = { date: '', division: '', equipe: '', ja: '', saisie: '', avecJa: '', accuse: '', f131: '' };
const sortState = { col: null, asc: true };

const JOURS_SEMAINE = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

/** "YYYY-MM-DD" → "Samedi 19/09/2026" (abrégé : "Sam 19/09/2026"). Construction via composants locaux (pas de décalage UTC). */
function formatDateAvecJour(dateStr, abrege = false) {
    if (!dateStr) return '—';
    const [y, m, d] = dateStr.substring(0, 10).split('-').map(Number);
    const jour = JOURS_SEMAINE[new Date(y, m - 1, d).getDay()].substring(0, abrege ? 3 : undefined);
    return `${jour} ${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

/** "YYYY-MM-DD HH:MM:SS" → "19/09/2026 à 14:05" */
function formatDateHeure(dt) {
    return dt.substring(0, 10).split('-').reverse().join('/') + ' à ' + dt.substring(11, 16);
}

/** Échappement HTML (même fonction qu'EN14) : toast() rend son message en innerHTML. */
function escHtml(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* Couleur de texte (blanc/noir) selon la luminosité du fond — identique à EN23 */
function textColorFor(hex) {
    const c = hex.replace('#', '');
    const r = parseInt(c.substring(0,2), 16);
    const g = parseInt(c.substring(2,4), 16);
    const b = parseInt(c.substring(4,6), 16);
    const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return lum > 0.55 ? '#111' : '#fff';
}

function macaronDivision(division, color) {
    const bg = color && /^#[0-9a-fA-F]{6}$/.test(color) ? color : '#1a3a6b';
    return $('<span class="badge">').text(division ?? '').css({ background: bg, color: textColorFor(bg) });
}

function nominationsFiltrees() {
    const equipe = filtres.equipe.toLowerCase();
    const ja     = filtres.ja.toLowerCase();
    return nominations.filter(n => {
        if (filtres.date && (n.Date ?? '').substring(0, 10) !== filtres.date) return false;
        if (filtres.division && n.Division !== filtres.division) return false;
        if (equipe
            && !String(n.NomDom ?? '').toLowerCase().includes(equipe)
            && !String(n.NomExt ?? '').toLowerCase().includes(equipe)) return false;
        if (ja && !String(n.NomJa ?? '').toLowerCase().includes(ja)) return false;
        if (filtres.saisie === 'oui' && !n.DateSaisie) return false;
        if (filtres.saisie === 'non' && n.DateSaisie) return false;   // inclut les rencontres sans JA
        if (filtres.avecJa === 'oui' && !n.Id_Nomination) return false;
        if (filtres.avecJa === 'non' && n.Id_Nomination) return false;
        if (filtres.accuse === 'oui' && !n.AccuseReception) return false;
        if (filtres.accuse === 'non' && (!n.Id_Nomination || n.AccuseReception)) return false;   // nominations sans accusé seulement
        if (filtres.f131 === 'oui' && !+n.F131) return false;
        return true;
    });
}

/** rencontre.ArbitrageCRA : 1 = arbitrage fourni par la CRA, 0 = à la charge du club */
const libArbitrage = n => n.ArbitrageCRA === null ? '' : (+n.ArbitrageCRA ? 'CRA' : 'Club');

// Clé de tri par colonne — sur les données (pas sur le texte des cellules : la date
// affichée « Samedi 19/09/2026 » ne se trierait pas chronologiquement).
const CLES_TRI = {
    date:      n => (n.Date ?? '') + (n.Heure ?? ''),
    division:  n => n.Division ?? '',
    arbitrage: n => libArbitrage(n),
    domicile:  n => n.NomDom ?? '',
    exterieur: n => n.NomExt ?? '',
    licence:   n => n.Id_JA === null ? -1 : +n.Id_JA,
    ja:        n => n.NomJa ?? '',
    ebp:       n => n.NumCompteEBP ?? '',
    peage:     n => n.DateSaisie ? +n.Peage : -1,
    km:        n => n.DateSaisie ? +n.Kilometre : -1,
    defisc:    n => n.DateSaisie ? +n.Defiscalisation : -1,
    saisie:    n => n.DateSaisie ?? '',
    ar:        n => n.AccuseReception ?? '',
};

/** Lignes du tableau : filtrées puis triées (une ligne par rencontre). */
function lignesAffichees() {
    const affichees = nominationsFiltrees();
    const cle = CLES_TRI[sortState.col];
    if (cle) {
        affichees.sort((a, b) => {
            const va = cle(a), vb = cle(b);
            const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb), 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    }
    return affichees;
}

function renderListe() {
    const $body = $('#tbody-liste').empty();
    const affichees = lignesAffichees();
    $('#lbl-count').text(`${affichees.length} / ${nominations.length}`);

    if (!affichees.length) {
        $body.append('<tr><td colspan="15" class="text-center text-muted py-3">Aucune rencontre.</td></tr>');
        return;
    }

    const kmNon = nonComptees('Kilometre'), peageNon = nonComptees('Peage');
    affichees.forEach(n => {
        // Saisi mais non compté (déjà compté sur une autre rencontre du jour, ou arbitrage Club) : barré + info-bulle
        const nonCompte = (champ, sec) => !!n.DateSaisie && +n[champ] > 0 && (!estCra(n) || sec.has(n.Id_Nomination));
        const titreNonCompte = estCra(n) ? 'Déjà compté sur une autre rencontre du même jour' : 'Arbitrage Club : non remboursé';
        // DateSaisie NULL = le JA n'a encore rien saisi dans EN21 : pas de valeurs à afficher
        const saisi = !!n.DateSaisie;
        const nomme = !!n.Id_Nomination;   // rencontre sans JA nommé : ni modification ni rappel
        const $modifier = $('<button type="button" class="btn btn-sm btn-outline-secondary" title="Modifier cette nomination">')
            .html('<i class="bi bi-pencil"></i>')
            .on('click', function () { ouvrirModification(n); });
        const $rappel = $('<button type="button" class="btn btn-sm btn-outline-primary btn-rappel">')
            .attr('title', n.EmailJa ? 'Envoyer un message de rappel au JA' : 'JA sans adresse email')
            .prop('disabled', !n.EmailJa)
            .html('<i class="bi bi-envelope"></i>')
            .on('click', function () { envoyerRappel(n, $(this)); });
        // Arbitrage club sans réponse du club (aucun JA désigné) : relance du club (message n°7)
        const $relanceClub = $('<button type="button" class="btn btn-sm btn-outline-warning btn-relance-club">')
            .attr('title', n.CorEmail ? 'Envoyer immédiatement la demande de JA au correspondant du club (message n°7)' : 'Club sans email de correspondant (à compléter en EN27)')
            .prop('disabled', !n.CorEmail)
            .html('<i class="bi bi-envelope"></i>')
            .on('click', function () { relancerClub(n, $(this)); });
        const relancable = !nomme && n.ArbitrageCRA !== null && +n.ArbitrageCRA === 0;
        // Sans JA : arbitrage club → le nominateur saisit lui-même le JA qui a officié ; arbitrage CRA → il nomme un JA (règles EN14)
        const nommable = !nomme && n.ArbitrageCRA !== null;
        const $saisir = $('<button type="button" class="btn btn-sm btn-outline-success">')
            .attr('title', estCra(n) ? 'Nommer un JA (arbitrage CRA)' : 'Saisir le JA qui a officié (arbitrage club)')
            .attr('aria-label', estCra(n) ? 'Nommer un JA' : 'Saisir le JA')
            .html('<i class="bi bi-person-plus"></i>')
            .on('click', function () { ouvrirModification(n); });
        $('<tr>').toggleClass('sans-ja', !nomme).append(
            $('<td>').attr('data-field', 'date').text(formatDateAvecJour(n.Date, true)),
            $('<td class="centre">').attr('data-field', 'division').append(macaronDivision(n.Division, n.DivisionColor)),
            $('<td class="centre">').attr('data-field', 'arbitrage').text(libArbitrage(n)),
            $('<td>').attr('data-field', 'domicile').text(n.NomDom ?? ''),
            $('<td>').attr('data-field', 'exterieur').text(n.NomExt ?? '—'),
            $('<td class="centre">').attr('data-field', 'licence').attr('title', n.Telephone || null).text(n.Id_JA ?? ''),
            $('<td>').attr('data-field', 'ja').attr('title', n.Telephone || null).toggleClass('aucun-ja', !nomme)
                .text(nomme ? (n.NomJa ?? '') : '— Aucun JA')
                .append(nomme && +n.F131 ? $('<span class="badge rounded-pill text-bg-light border ms-1 badge-f131">').text('131').attr('title', 'Mise à jour depuis le fichier FFTT 131') : ''),
            $('<td>').attr('data-field', 'ebp').text(n.NumCompteEBP ?? ''),
            $('<td class="num">').attr('data-field', 'peage').toggleClass('non-saisi', !saisi)
                .toggleClass('deja-compte', nonCompte('Peage', peageNon)).attr('title', nonCompte('Peage', peageNon) ? titreNonCompte : null)
                .text(saisi ? Number(n.Peage ?? 0).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €' : '—'),
            $('<td class="num">').attr('data-field', 'km').toggleClass('non-saisi', !saisi)
                .toggleClass('deja-compte', nonCompte('Kilometre', kmNon)).attr('title', nonCompte('Kilometre', kmNon) ? titreNonCompte : null)
                .text(saisi ? (n.Kilometre ?? 0) : '—'),
            $('<td class="centre">').attr('data-field', 'defisc').toggleClass('non-saisi', !saisi)
                .text(saisi ? (+n.Defiscalisation ? 'Oui' : 'Non') : '—'),
            $('<td class="centre">').attr('data-field', 'saisie').toggleClass('non-saisi', !saisi)
                .text(saisi ? n.DateSaisie.substring(0, 10).split('-').reverse().join('/') : (nomme ? '—' : '')),
            $('<td class="centre">').attr('data-field', 'ar').append(n.AccuseReception
                ? $('<i class="bi bi-check-lg text-success fw-bold">').attr('title', 'Accusé de réception le ' + formatDateHeure(n.AccuseReception))
                : $('<span class="non-saisi">').text(nomme ? '—' : '')),
            $('<td class="centre">').append(nomme ? $modifier : (nommable ? $saisir : '')),
            $('<td class="centre">').append(relancable ? $relanceClub
                : (!nomme || saisi || !+n.Valide ? '' : $rappel)) // sans JA, frais déjà saisis, ou arbitrage club non validé (refusé serveur) : pas de rappel
        ).on('dblclick', function () { if (nomme || nommable) ouvrirModification(n); }).appendTo($body);
    });
}

function envoyerRappel(n, $btn) {
    nijacConfirm(`Envoyer un message de rappel à ${n.NomJa} ?`, function () {
        $btn.prop('disabled', true);
        $.post(`${SUIVI_BASE}/rappel`, { id_nomination: n.Id_Nomination }, function (r) {
            toast(escHtml(r.msg), !!r.ok);
            $btn.prop('disabled', false);
        }, 'json').fail(function () {
            toast('Erreur réseau.', false);
            $btn.prop('disabled', false);
        });
    });
}

// Envoi immédiat au correspondant du club, sans confirmation (bouton désactivé pendant l'appel)
function relancerClub(n, $btn) {
    $btn.prop('disabled', true);
    $.post(`${SUIVI_BASE}/relance-club`, { id_rencontre: n.Id_Rencontre }, function (r) {
        toast(escHtml(r.msg), !!r.ok);
        $btn.prop('disabled', false);
    }, 'json').fail(function () {
        toast('Erreur réseau ou session expirée.', false);
        $btn.prop('disabled', false);
    });
}

// ── Modification d'une nomination (popup) ────────────────────────────────────
let jaListe = null;      // JA actifs du périmètre, chargés à la première ouverture
let modifNom = null;     // nomination en cours de modification
let reqDispo = null;     // requête ja-disponibles en cours (abandonnée si la liste est redemandée)
const option = j => $('<option>').val(j.Id_JA).text(`${j.Nom} ${j.Prenom} (${j.Id_JA})`);

/**
 * Arbitrage CRA (« Nommer un JA », ou « Modifier » en CRA) : seuls les JA nommables sur cette rencontre
 * (règle stricte d'EN14 + 2 nominations/jour, calculées serveur), le JA actuel toujours inclus.
 * Le contrôle de saisir()/modifier() au clic reste la protection finale.
 */
function chargerJaDisponibles(n) {
    const $sel = $('#modif-ja').prop('disabled', true).empty().append($('<option>').val('').text('Chargement…'));
    const $btn = $('#btn-modif-enregistrer').prop('disabled', true);
    const vide = txt => $sel.empty().append($('<option>').val('').text(txt));
    if (reqDispo) reqDispo.abort();
    reqDispo = $.get(`${SUIVI_BASE}/ja-disponibles`, { rencontre: n.Id_Rencontre }, function (res) {
        if (!res.ok) { vide('—'); toast(escHtml(res.msg), false); return; }
        if (!res.ja.length) { vide('Aucun JA disponible pour cette rencontre'); return; }
        $sel.empty().prop('disabled', false);
        if (!n.Id_Nomination) $sel.append($('<option>').val('').text('— Choisir le JA —'));
        $sel.append(res.ja.map(j => option(j).text(`${j.Nom} ${j.Prenom} (${j.Id_JA})${j.actuel ? ' — actuel' : ''}`)));
        if (n.Id_Nomination) $sel.val(n.Id_JA);
        $btn.prop('disabled', false);
    }, 'json').fail(function (xhr, statut) {
        if (statut === 'abort') return;
        vide('—');
        toast(escHtml(`Liste des JA disponibles indisponible (${xhr.status || 'erreur réseau'}).`), false);
    });
}

/** Arbitrage club (souple) : liste complète des JA actifs du périmètre (jaListe). */
function remplirListeJa(n) {
    if (reqDispo) reqDispo.abort();
    $('#btn-modif-enregistrer').prop('disabled', false);
    const $sel = $('#modif-ja').prop('disabled', false).empty();
    let liste = jaListe;
    if (!n.Id_Nomination) {
        // « Saisir le JA » : JA du club recevant en tête, puis les autres (même tri Nom/Prénom)
        const duClub = liste.filter(j => j.Id_Club === n.IdClubDom), autres = liste.filter(j => j.Id_Club !== n.IdClubDom);
        $sel.append($('<option>').val('').text('— Choisir le JA —'));
        if (duClub.length) $sel.append($('<optgroup label="JA du club recevant">').append(duClub.map(option)));
        $sel.append($('<optgroup label="Autres JA du périmètre">').append(autres.map(option)));
        return;
    }
    // le JA actuel reste sélectionnable même s'il n'est pas dans la liste (inactif, autre département)
    if (!liste.some(j => +j.Id_JA === +n.Id_JA)) {
        const [prenom, ...nom] = String(n.NomJa ?? '').split(' ');
        liste = [{ Id_JA: n.Id_JA, Nom: nom.join(' '), Prenom: prenom }, ...liste];
    }
    liste.forEach(j => $sel.append(option(j)));
    $sel.val(n.Id_JA);
}

/** Popup « Modifier la nomination », ou, sans nomination, « Saisir le JA » (arbitrage club) / « Nommer un JA » (arbitrage CRA). */
function ouvrirModification(n) {
    modifNom = n;
    const ouvrir = () => {
        const saisie = !n.Id_Nomination, nommer = saisie && estCra(n);
        const libRencontre = `${formatDateAvecJour(n.Date, true)} — ${n.NomDom} vs ${n.NomExt ?? '?'}`;
        estCra(n) ? chargerJaDisponibles(n) : remplirListeJa(n);
        $('#modif-titre').empty().append(
            $('<i class="bi me-2">').addClass(saisie ? 'bi-person-plus' : 'bi-pencil'),
            document.createTextNode(saisie ? `${nommer ? 'Nommer un JA' : 'Saisir le JA'} — ${n.NomDom} vs ${n.NomExt ?? '?'}` : 'Modifier la nomination'));
        $('#modif-col-arbitrage').toggle(!saisie);   // arbitrage de la rencontre conservé à la saisie
        $('#modal-modif .modif-frais').toggle(!nommer);   // CRA : frais saisis ensuite par le JA (EN21) ou via « Modifier »
        $('#modif-rencontre').text(libRencontre);
        $('#modif-arbitrage').val(+n.ArbitrageCRA ? '1' : '0');
        $('#modif-peage').val(n.Peage ?? 0);
        $('#modif-km').val(n.Kilometre ?? 0);
        $('#modif-defisc').prop('checked', !!+n.Defiscalisation);
        bootstrap.Modal.getOrCreateInstance('#modal-modif').show();
    };
    if (jaListe) { ouvrir(); return; }
    $.get(`${SUIVI_BASE}/ja-liste`, function (res) {
        if (!res.ok) { toast(escHtml(res.msg), false); return; }
        jaListe = res.ja;
        ouvrir();
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

// « Modifier » : bascule club → CRA = liste filtrée par disponibilité ; CRA → club = liste complète.
$('#modif-arbitrage').on('change', function () {
    if (!modifNom || !modifNom.Id_Nomination) return;
    $(this).val() === '1' ? chargerJaDisponibles(modifNom) : remplirListeJa(modifNom);
});

$('#btn-modif-enregistrer').on('click', function () {
    if (!modifNom) return;
    const saisie = !modifNom.Id_Nomination;
    if (saisie && !$('#modif-ja').val()) { toast('Choisissez le juge-arbitre.', false); return; }
    const $btn = $(this).prop('disabled', true);
    const frais = {
        id_ja:  $('#modif-ja').val(),
        peage:  $('#modif-peage').val(),
        km:     $('#modif-km').val(),
        defisc: $('#modif-defisc').is(':checked') ? 1 : 0,
    };
    $.post(saisie ? `${SUIVI_BASE}/saisir` : `${SUIVI_BASE}/modifier`, saisie
        ? { id_rencontre: modifNom.Id_Rencontre, ...frais }
        : { id_nomination: modifNom.Id_Nomination, arbitrage: $('#modif-arbitrage').val(), ...frais }, function (r) {
        $btn.prop('disabled', false);
        toast(escHtml(r.msg), !!r.ok);
        if (!r.ok) return;
        bootstrap.Modal.getInstance('#modal-modif').hide();
        chargerListe();
    }, 'json').fail(function () {
        $btn.prop('disabled', false);
        toast('Erreur réseau.', false);
    });
});

// ── Frais non comptés (mêmes règles qu'EN17) ─────────────────────────────────
// Une ligne par nomination, sans regroupement. Mêmes règles qu'EN17 (tableau « Arbitrages et frais ») : seules les
// rencontres à arbitrage CRA valent des frais (Club : 0), et un JA qui arbitre plusieurs rencontres CRA le même jour ne
// fait qu'un déplacement — les km, et de même les péages, ne sont conservés que sur la 1re rencontre du jour qui en porte
// (heure la plus précoce, puis n° de nomination), les suivantes sont barrées dans le tableau. Une rencontre à 0 ne « consomme »
// pas le déplacement : le JA a pu ne saisir ses frais que sur l'une des deux.
const estCra = n => +n.ArbitrageCRA === 1;

/** Ids des nominations dont la valeur de `champ` ('Kilometre' | 'Peage') n'est PAS comptée : 2e rencontre et suivantes du jour. */
function nonComptees(champ) {
    const vus = new Set(), sec = new Set();
    nominations.filter(n => estCra(n) && +n[champ] > 0)
        .sort((a, b) => ((a.Date ?? '') + (a.Heure ?? '')).localeCompare((b.Date ?? '') + (b.Heure ?? '')) || a.Id_Nomination - b.Id_Nomination)
        .forEach(n => {
            const cle = `${n.Id_JA}|${(n.Date ?? '').substring(0, 10)}`;
            if (vus.has(cle)) sec.add(n.Id_Nomination); else vus.add(cle);
        });
    return sec;
}


function chargerListe() {
    $.get(`${SUIVI_BASE}/data`, function (res) {
        if (!res.ok) { toast(escHtml(res.msg), false); return; }
        nominations = res.nominations;
        const dates = [...new Set(nominations.map(n => (n.Date ?? '').substring(0, 10)).filter(Boolean))].sort();
        const $sel = $('#sel-date');
        $sel.find('option:not(:first)').remove();
        dates.forEach(d => $sel.append(new Option(formatDateAvecJour(d), d)));
        $sel.val(filtres.date);
        majPanelDivision();
        renderListe();
    }, 'json').fail(() => toast('Erreur réseau.', false));
}

/** Filtre Division (popup partagée nijac-division-filter.js, comme EN29) : divisions présentes dans les nominations. */
function majPanelDivision() {
    const couleurs = {};
    nominations.forEach(n => { if (n.Division) couleurs[n.Division] = n.DivisionColor; });
    nijacDivisionFilter('#panel-division', Object.keys(couleurs).sort(), {
        libDivision,
        colorFor: code => couleurs[code],
        getFiltre: () => filtres.division,
        onSelect: code => { filtres.division = code; majPanelDivision(); renderListe(); },
    });
}

$('#sel-date').on('change', function () { filtres.date = $(this).val(); renderListe(); });
$('#sel-saisie').on('change', function () { filtres.saisie = $(this).val(); renderListe(); });
$('#sel-avecja').on('change', function () { filtres.avecJa = $(this).val(); renderListe(); });
$('#sel-accuse').on('change', function () { filtres.accuse = $(this).val(); renderListe(); });
$('#sel-f131').on('change', function () { filtres.f131 = $(this).val(); renderListe(); });
// Debounce : renderListe() reconstruit tout le tableau
let searchTimer;
function filtreTexte(cle) {
    return function () {
        const val = $(this).val().trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { filtres[cle] = val; renderListe(); }, 200);
    };
}
$('#search-equipe').on('input', filtreTexte('equipe'));
$('#search-ja').on('input', filtreTexte('ja'));
$('#btn-reset-filtres').on('click', function () {
    filtres.date = filtres.division = filtres.equipe = filtres.ja = filtres.saisie = filtres.avecJa = filtres.accuse = filtres.f131 = '';
    $('#sel-date, #sel-saisie, #sel-avecja, #sel-accuse, #sel-f131, #search-equipe, #search-ja').val('');
    majPanelDivision();
    renderListe();
});

// ── Mise à jour FFTT 131 (aperçu sans écriture, puis validation : le fichier est renvoyé, rien n'est conservé serveur) ──
let fichier131 = null, rapport131 = null;
const F131_MAX_LIGNES = 200;

$('#btn-f131').on('click', function () {
    fichier131 = rapport131 = null;
    $('#f131-fichier').val('');
    $('#f131-resultat').empty();
    $('#btn-f131-valider').prop('disabled', true);
    $('#btn-f131-csv').addClass('d-none');
    bootstrap.Modal.getOrCreateInstance('#modal-f131').show();
});
// Aide « comme la 102 » : fenêtre asset/aide/import-131.html, qui peut aussi choisir le fichier (importerFichier131).
$('#btn-f131-aide').on('click', function () {
    window.open('<?= base_url('asset/aide/import-131.html') ?>', 'aideImport131', 'width=640,height=680,resizable=yes,scrollbars=yes');
});
$('#f131-fichier').on('change', function () {
    fichier131 = this.files[0] || null;
    $('#btn-f131-valider').prop('disabled', true);
});
window.importerFichier131 = function (file) {
    fichier131 = file;
    $('#f131-fichier').val('');
    envoyer131('apercu');
};
$('#btn-f131-analyser').on('click', () => envoyer131('apercu'));
$('#btn-f131-valider').on('click', function () {
    if (!rapport131) return;
    nijacConfirm(`Appliquer ${rapport131.creees} création(s) et ${rapport131.modifiees} remplacement(s) de JA ?`, () => envoyer131('valider'));
});
$('#btn-f131-csv').on('click', function () {
    if (!rapport131) return;
    const blob = new Blob([csv131(rapport131)], { type: 'text/csv;charset=utf-8' });
    const a = $('<a>').attr({ href: URL.createObjectURL(blob), download: 'rapport_131.csv' }).appendTo('body');
    a[0].click();
    URL.revokeObjectURL(a.attr('href'));
    a.remove();
});

function envoyer131(etape) {
    const f = fichier131;
    if (!f) { nijacToast('Choisissez le fichier 131 (.xlsx).', 'warning'); return; }
    if (!/\.xlsx$/i.test(f.name) || f.size > 5 * 1024 * 1024) { nijacToast('Fichier .xlsx de 5 Mo maximum.', 'warning'); return; }
    const fd = new FormData();
    fd.append('xlsx', f);
    const $btns = $('#btn-f131-analyser, #btn-f131-valider').prop('disabled', true);
    $('#f131-resultat').html('<div class="text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>' + (etape === 'apercu' ? 'Analyse…' : 'Mise à jour…') + '</div>');
    $.ajax({ url: `${SUIVI_BASE}/f131/${etape}`, method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            $('#btn-f131-analyser').prop('disabled', false);
            if (!r.ok) {
                rapport131 = null;
                $('#btn-f131-csv').addClass('d-none');
                $('#f131-resultat').empty().append($('<div class="alert alert-danger small mb-0">').text(r.msg || 'Erreur.'));
                return;
            }
            rapport131 = r;
            afficher131(r);
            $('#btn-f131-csv').removeClass('d-none');
            if (!r.ecrit) {
                $('#btn-f131-valider').prop('disabled', r.creees + r.modifiees === 0);
                return;
            }
            nijacToast(`FFTT 131 : ${r.creees} nomination(s) créée(s), ${r.modifiees} modifiée(s), ${r.incoherences} incohérence(s).`, r.incoherences ? 'warning' : 'success');
            chargerListe();
        })
        .fail(() => { $btns.prop('disabled', false); $('#btn-f131-valider').prop('disabled', true); $('#f131-resultat').empty(); nijacToast('Erreur réseau ou session expirée.', 'danger'); });
}

const fr131 = d => d ? d.split('-').reverse().join('/') : '?';

/** Compte rendu : chiffres puis détail (200 lignes max), tout le texte en .text() (aucun HTML venant du fichier). */
function afficher131(r) {
    const ign = Object.entries(r.ignores).map(([g, n]) => `${g} : ${n}`).join(', ') || 'aucune';
    const cats = Object.entries(r.parCategorie).map(([c, n]) => `${c} : ${n}`).join(', ');
    const fait = r.ecrit;
    const lignes = [
        `Fichier : ${r.fichier} — ${r.nbLignes} ligne(s), du ${fr131(r.dateMin)} au ${fr131(r.dateMax)}` + (r.vides ? ` (${r.vides} ligne(s) vide(s))` : ''),
        `Lignes ignorées (colonne G ≠ « JA ») : ${ign}`,
        `Lignes JA retenues : ${r.retenues} = ${r.conformes} conforme(s) + ${r.creees} ${fait ? 'créée(s)' : 'à créer'} + ${r.modifiees} ${fait ? 'modifiée(s)' : 'à modifier'} + ${r.incoherences} incohérence(s) + ${r.horsPerimetre} hors périmètre`,
    ];
    if (cats) lignes.push(`Incohérences : ${cats}`);
    const $res = $('#f131-resultat').empty();
    $('<div class="alert small mb-2" style="white-space:pre-line">')
        .addClass(fait ? 'alert-success' : (r.incoherences ? 'alert-warning' : 'alert-info'))
        .text((fait ? 'Mise à jour effectuée.\n' : 'Aperçu — rien n\'a encore été modifié.\n') + lignes.join('\n')).appendTo($res);
    if (!r.details.length) return;
    const $tb = $('<tbody>');
    r.details.slice(0, F131_MAX_LIGNES).forEach(d => $('<tr>').append(
        $('<td>').text(d.action), $('<td class="text-end">').text(d.ligne), $('<td>').text(d.date),
        $('<td>').text(d.rencontre), $('<td>').text(d.ja), $('<td>').text(d.motif)
    ).appendTo($tb));
    $('<table class="table table-sm table-striped small mb-1">')
        .append($('<thead><tr><th>Action</th><th>Ligne</th><th>Date</th><th>Rencontre</th><th>JA</th><th>Motif</th></tr></thead>'), $tb).appendTo($res);
    if (r.details.length > F131_MAX_LIGNES) {
        $('<div class="small text-muted">').text(`… et ${r.details.length - F131_MAX_LIGNES} autre(s) : voir le rapport complet (CSV).`).appendTo($res);
    }
}

/** Cellule CSV : anti-injection de formule (cf. EN26 csvCellule()), guillemets si nécessaire. */
function csvCellule131(v) {
    let s = String(v ?? '');
    if (typeof v === 'string' && /^[=+\-@\t\r]/.test(s)) s = "'" + s;
    return /[;"\r\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

/** Rapport complet : UTF-8 BOM, « ; », CRLF. */
function csv131(r) {
    const rows = [['Action', 'Ligne', 'Date', 'Rencontre', 'JA', 'Motif']]
        .concat(r.details.map(d => [d.action, d.ligne, d.date, d.rencontre, d.ja, d.motif]));
    return '﻿' + rows.map(l => l.map(csvCellule131).join(';')).join('\r\n') + '\r\n';
}

$(function () {
    nijacSortableTable('#tbl-suivi thead th[data-field]', 'field', sortState, renderListe);
    chargerListe();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-division-filter.js') ?>"></script>
</body>
</html>
