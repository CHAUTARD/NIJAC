<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>NIJAC – Statistiques des nominations (EN26)</title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
<style>
:root { --nom-green: #2e7d32; }
body { background:#f0f4fa; font-family:'Segoe UI',system-ui,sans-serif; }
#page-header { background:var(--nom-green); color:#fff; padding:.5rem 1.25rem; font-size:.9rem; font-weight:600; display:flex; align-items:center; gap:.75rem; }
#toolbar { background:#f8fafc; border-bottom:1px solid #dde5f0; padding:.3rem 1rem; font-size:.85rem; }
.ts-user { color:#1a3a6b; font-weight:600; }

.wrap { max-width:1100px; margin:1rem auto 3rem; padding:0 1rem; }

.journee-bloc { background:#fff; border:1px solid #dee2e6; border-radius:8px; margin-bottom:1.25rem; overflow:hidden; }
.journee-titre { background:#e8f5e9; color:#2e7d32; font-weight:700; font-size:.9rem; padding:.5rem .85rem; cursor:pointer; user-select:none; list-style-position:inside; }
details.journee-bloc[open] .journee-titre { border-bottom:1px solid #c8e6c9; }
.journee-titre::marker { color:#2e7d32; }
.recap-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
table.recap { width:100%; border-collapse:collapse; font-size:.83rem; }
table.recap th { background:#f8f9fa; text-align:left; padding:.4rem .6rem; border-bottom:2px solid #dee2e6; white-space:nowrap; }
table.recap td { padding:.35rem .6rem; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
table.recap tr:hover td { background:#f4f9f4; }
.div-badge { font-size:.68rem; font-weight:700; color:#fff; background:#1a3a6b; padding:.1rem .4rem; border-radius:3px; }
.cell-ja { white-space:nowrap; }
.statut-badge { font-size:.66rem; font-weight:700; padding:.1rem .45rem; border-radius:10px; }
.statut-badge.valide { background:#e8f5e9; color:#2e7d32; }
.statut-badge.envoye { background:#e3f2fd; color:#1565c0; }
.statut-badge.attente { background:#fff3e0; color:#e65100; }

#tbl-compteurs { width:100%; border-collapse:collapse; font-size:.85rem; background:#fff; }
#tbl-compteurs th, #tbl-compteurs td { padding:.4rem .7rem; border-bottom:1px solid #eee; text-align:left; }
#tbl-compteurs th { background:#1a3a6b; color:#fff; }
#tbl-compteurs td.nb { text-align:right; font-weight:700; font-variant-numeric:tabular-nums; }

#tbl-clubs { width:100%; min-width:560px; border-collapse:collapse; font-size:.85rem; background:#fff; }
#tbl-clubs th, #tbl-clubs td { padding:.4rem .7rem; border-bottom:1px solid #eee; text-align:left; }
#tbl-clubs th { background:#1a3a6b; color:#fff; }
#tbl-clubs th.num, #tbl-clubs td.num { text-align:right; font-variant-numeric:tabular-nums; }
#tbl-clubs td.ratio { text-align:right; font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
#tbl-clubs tr.complet td.ratio { color:#2e7d32; }
#tbl-clubs tr.court   td.ratio { color:#e65100; }
.jauge { position:relative; height:6px; border-radius:3px; background:#e9edf3; margin-top:.2rem; overflow:hidden; }
.jauge > span { position:absolute; inset:0 auto 0 0; background:var(--nom-green); border-radius:3px; }
#tbl-clubs tr.court .jauge > span { background:#f0a020; }
h2.section { font-size:1rem; color:#1a3a6b; margin:1.5rem 0 .6rem; }

/* ── Sections repliables façon boutons-cartes (forme donnée en exemple) ── */
details.sect-btn { background:#fff; border:1px solid #e6e9f0; border-left:5px solid #ccc; border-radius:14px; box-shadow:0 1px 6px rgba(0,0,0,.06); margin:1rem 0; overflow:hidden; }
details.sect-btn > summary.sect-head { display:flex; align-items:center; gap:1rem; padding:1rem 1.1rem; cursor:pointer; user-select:none; list-style:none; }
details.sect-btn > summary.sect-head::-webkit-details-marker { display:none; }
.sect-head .sect-ico { flex-shrink:0; width:46px; height:46px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.25rem; }
.sect-head .sect-txt { flex:1; min-width:0; }
.sect-head .sect-titre { display:block; font-weight:700; font-size:1.05rem; color:#1f2937; }
.sect-head .sect-desc { display:block; font-size:.8rem; color:#6b7280; font-weight:400; margin-top:.15rem; }
.sect-head .sect-chev { flex-shrink:0; font-size:1.1rem; transition:transform .15s; }
details.sect-btn[open] > summary.sect-head .sect-chev { transform:rotate(90deg); }
details.sect-btn > .sect-body { padding:0 1.1rem 1.1rem; }
details.cal-accent  { border-left-color:#2e7d32; } .cal-accent  .sect-ico { background:#e8f5e9; color:#2e7d32; } .cal-accent  .sect-chev { color:#2e7d32; }
details.ja-accent   { border-left-color:#1565c0; } .ja-accent   .sect-ico { background:#e3f2fd; color:#1565c0; } .ja-accent   .sect-chev { color:#1565c0; }
details.club-accent { border-left-color:#e65100; } .club-accent .sect-ico { background:#fff3e0; color:#e65100; } .club-accent .sect-chev { color:#e65100; }
#chargement { text-align:center; color:#888; padding:2rem; }

/* ── Calendrier (repris d'EN22) : affichage permanent, mois repliés par défaut ── */
.cal-mois-grille { display:grid; grid-template-columns:repeat(auto-fill,minmax(230px,1fr)); gap:1rem; margin-bottom:1rem; align-items:start; }
.cal-mois { border:1px solid #d0d8e8; border-radius:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07); background:#fff; }
.cal-mois-titre { background:#1a3a6b; color:#fff; font-size:.95rem; font-weight:700; padding:.5rem .7rem; cursor:pointer; user-select:none; list-style-position:inside; }
.cal-mois-titre::marker { color:#fff; }
.cal-mois-nb { float:right; font-weight:400; font-size:.8rem; opacity:.85; }
.cal-semaine-header { display:grid; grid-template-columns:repeat(7,1fr); text-align:center; font-size:.78rem; font-weight:700; color:#888; padding:.35rem 0 .1rem; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); padding:.3rem .35rem .5rem; }
.cal-jour { aspect-ratio:1; display:flex; align-items:center; justify-content:center; border-radius:50%; font-size:.9rem; margin:2px; user-select:none; }
.cal-jour.vide { color:transparent; }
.cal-jour.jour-j { font-weight:700; cursor:pointer; background:var(--nom-green); color:#fff; transition:filter .12s, transform .1s; }
.cal-jour.jour-j:hover { filter:brightness(1.12); transform:scale(1.1); }
.cal-jour.today { outline:2px solid #1a3a6b; outline-offset:1px; }

/* ── Adaptation smartphone ──────────────────────────────────────────────── */
@media (max-width: 640px) {
    #page-header { padding:.5rem .8rem; }
    #toolbar { padding:.3rem .8rem; flex-wrap:wrap; }
    .wrap { padding:0 .6rem; margin:.6rem auto 2rem; }
    h2.section { font-size:.95rem; margin:1.1rem 0 .5rem; }
    details.sect-btn { margin:.8rem 0; }
    .sect-head .sect-titre { font-size:1rem; }

    /* Tableau récap : masquer Poule + Heure, resserrer, combo plus étroite */
    table.recap th:nth-child(3), table.recap td:nth-child(3),
    table.recap th:nth-child(4), table.recap td:nth-child(4) { display:none; }
    table.recap { font-size:.8rem; }
    table.recap th, table.recap td { padding:.3rem .35rem; }
    .cell-ja { white-space:normal; }

    .cal-mois-grille { grid-template-columns:1fr; gap:.75rem; }
    .cal-mois-titre { font-size:.9rem; }
    .cal-jour { font-size:.95rem; }
}
</style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'bar-chart-line-fill', 'phTitle' => 'Statistiques des nominations', 'phCode' => 'EN26',
    'phCrumbLabel' => 'Nomination JA', 'phCrumbUrl' => site_url('nomination'), 'phBackUrl' => site_url('nomination'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div class="wrap">

    <div id="chargement"><span class="spinner-border spinner-border-sm me-2"></span>Chargement…</div>

    <div id="contenu" style="display:none">
        <details class="sect-btn cal-accent" open>
        <summary class="sect-head">
            <span class="sect-ico"><i class="bi bi-calendar3"></i></span>
            <span class="sect-txt">
                <span class="sect-titre">Calendrier</span>
                <span class="sect-desc">Cliquer un mois pour voir ses dates, puis une date pour ouvrir la journée</span>
            </span>
            <i class="bi bi-chevron-right sect-chev"></i>
        </summary>
        <div class="sect-body"><div id="cal-grille" class="cal-mois-grille"></div></div>
        </details>

        <h2 class="section"><i class="bi bi-list-check me-1"></i>Journées de rencontre</h2>
        <div id="journees"></div>

        <details class="sect-btn ja-accent">
        <summary class="sect-head">
            <span class="sect-ico"><i class="bi bi-person-badge"></i></span>
            <span class="sect-txt">
                <span class="sect-titre">JA nominés</span>
                <span class="sect-desc">Nombre de nominations par juge-arbitre</span>
            </span>
            <i class="bi bi-chevron-right sect-chev"></i>
        </summary>
        <div class="sect-body">
        <table id="tbl-compteurs">
            <thead><tr><th>Juge-arbitre</th><th style="text-align:right">Nominations</th></tr></thead>
            <tbody id="compteurs-body"></tbody>
        </table>
        </div>
        </details>

        <details class="sect-btn club-accent">
        <summary class="sect-head">
            <span class="sect-ico"><i class="bi bi-building"></i></span>
            <span class="sect-txt">
                <span class="sect-titre">Clubs avec équipes en régionale</span>
                <span class="sect-desc">Nominations faites par les JA du club sur le nombre à effectuer
                    (<span id="coef-nat"></span> par équipe nationale, <span id="coef-reg"></span> par équipe régionale)</span>
            </span>
            <i class="bi bi-chevron-right sect-chev"></i>
        </summary>
        <div class="sect-body">
        <div class="recap-scroll">
        <table id="tbl-clubs">
            <thead><tr>
                <th>Club</th>
                <th class="num">Éq. rég.</th>
                <th class="num">Éq. nat.</th>
                <th class="num">Réalisées</th>
                <th class="num">À effectuer</th>
                <th class="ratio">Avancement</th>
            </tr></thead>
            <tbody id="clubs-body"></tbody>
        </table>
        </div>
        </div>
        </details>
    </div>

</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
'use strict';

const DATA_URL = '<?= site_url('stats-nomination/data') ?>';

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function fmtDate(d) {
    if (!d) return '';
    const [y, m, j] = d.split('-');
    return `${j}/${m}/${y}`;
}

function statutBadge(r) {
    if (!r.IdJaAffecte) return '';
    if (parseInt(r.EmailEnvoye) === 1) return '<span class="statut-badge envoye">Convoqué</span>';
    if (parseInt(r.Valide) === 1)      return '<span class="statut-badge valide">Validé</span>';
    return '<span class="statut-badge attente">En attente</span>';
}

// ── Calendrier de sélection (repris d'EN22) ──────────────────────────────────
const MOIS_NOMS    = ['Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
const JOURS_COURTS = ['L','M','M','J','V','S','D'];

function buildCalendrier(groupes) {
    // Mois actuellement ouverts, à rouvrir après un rechargement (charger() re-render tout).
    const moisOuverts = new Set(
        [...document.querySelectorAll('#cal-grille details.cal-mois[open]')].map(d => d.dataset.mois)
    );

    // Regroupe les journées par mois (clé "annee-mois"), dans l'ordre chronologique.
    const parMois = new Map();
    groupes.forEach(g => {
        const [y, m] = g.date.split('-');
        const cle = `${y}-${parseInt(m, 10) - 1}`;
        if (!parMois.has(cle)) parMois.set(cle, { annee: +y, mois: parseInt(m, 10) - 1, dates: {} });
        parMois.get(cle).dates[g.date] = { cle: g.journee + '|' + g.date, journee: g.journee, nb: g.lignes.length };
    });

    if (!parMois.size) { $('#cal-grille').html('<p class="text-muted">Aucune journée.</p>'); return; }

    const today = new Date(); today.setHours(0, 0, 0, 0);
    let html = '';
    parMois.forEach((info, cle) => {
        html += buildMois(info.annee, info.mois, info.dates, today, moisOuverts.has(cle));
    });
    $('#cal-grille').html(html);
}

function buildMois(annee, mois, parDate, today, ouvert) {
    const nbJournees = Object.keys(parDate).length;
    let h = `<details class="cal-mois" data-mois="${annee}-${mois}"${ouvert ? ' open' : ''}>
        <summary class="cal-mois-titre">${MOIS_NOMS[mois]} ${annee}<span class="cal-mois-nb">${nbJournees} journée${nbJournees > 1 ? 's' : ''}</span></summary>
        <div class="cal-semaine-header">` + JOURS_COURTS.map(j => `<span>${j}</span>`).join('') + '</div><div class="cal-grid">';
    const premier = (new Date(annee, mois, 1).getDay() + 6) % 7;
    for (let i = 0; i < premier; i++) h += '<div class="cal-jour vide"></div>';
    const nbJours = new Date(annee, mois + 1, 0).getDate();
    for (let j = 1; j <= nbJours; j++) {
        const ds   = `${annee}-${String(mois + 1).padStart(2, '0')}-${String(j).padStart(2, '0')}`;
        const day  = parDate[ds];
        let cls = 'cal-jour';
        if (day) cls += ' jour-j';
        if (new Date(annee, mois, j).getTime() === today.getTime()) cls += ' today';
        h += day
            ? `<div class="${cls}" data-cle="${escHtml(day.cle)}" title="Journée ${escHtml(day.journee)} — ${day.nb} rencontre(s)">${j}</div>`
            : `<div class="${cls}">${j}</div>`;
    }
    return h + '</div></details>';
}

$(document).on('click', '.cal-jour.jour-j', function () {
    const $bloc = $('details.journee-bloc[data-cle="' + $(this).data('cle') + '"]');
    if (!$bloc.length) return;
    $bloc.css('display', '').prop('open', true);   // révèle + ouvre la journée choisie
    $bloc[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
});

function rendu(res) {
    // Regroupement par journée (les rencontres arrivent déjà triées)
    const groupes = [];
    let cle = null;
    res.rencontres.forEach(r => {
        const k = r.Journee + '|' + r.Date;
        if (k !== cle) { groupes.push({ journee: r.Journee, date: r.Date, lignes: [] }); cle = k; }
        groupes[groupes.length - 1].lignes.push(r);
    });

    // Mémorise les journées actuellement ouvertes pour les rouvrir après un rechargement
    // (charger() re-render tout). Par défaut aucune n'est ouverte.
    const ouverts = new Set(
        [...document.querySelectorAll('details.journee-bloc[open]')].map(d => d.dataset.cle)
    );

    let html = '';
    if (!groupes.length) html = '<p class="text-muted">Aucune rencontre dans votre périmètre.</p>';
    groupes.forEach(g => {
        const cle    = g.journee + '|' + g.date;
        const visible = ouverts.has(cle);   // masquée par défaut ; révélée via le calendrier
        html += `<details class="journee-bloc" data-cle="${escHtml(cle)}"${visible ? ' open' : ' style="display:none"'}>
            <summary class="journee-titre">Journée ${escHtml(g.journee)} — ${fmtDate(g.date)}</summary>
            <div class="recap-scroll">
            <table class="recap">
                <thead><tr><th>Div.</th><th>Rencontre</th><th>Poule</th><th>Heure</th><th>Juge-arbitre</th><th>Statut</th></tr></thead>
                <tbody>`;
        g.lignes.forEach(r => {
            const color = r.DivisionColor || '#1a3a6b';
            html += `<tr data-renc="${r.Id_Rencontre}">
                <td><span class="div-badge" style="background:${escHtml(color)}">${escHtml(r.DivisionCode)}</span></td>
                <td>${escHtml(r.NomDom)} <span class="text-muted">vs</span> ${escHtml(r.NomExt || '')}</td>
                <td>${escHtml(r.Poule || '')}</td>
                <td>${escHtml((r.Heure || '').slice(0,5))}</td>
                <td class="cell-ja">${r.IdJaAffecte ? escHtml(r.NomJaAffecte) : '<span class="text-muted">—</span>'}</td>
                <td class="cell-statut">${statutBadge(r)}</td>
            </tr>`;
        });
        html += '</tbody></table></div></details>';
    });
    $('#journees').html(html);

    // Tableau des compteurs
    let cbody = '';
    if (!res.compteurs.length) cbody = '<tr><td colspan="2" class="text-muted">Aucune nomination.</td></tr>';
    res.compteurs.forEach(c => {
        cbody += `<tr><td>${escHtml(c.Nom + ' ' + c.Prenom)}</td><td class="nb">${escHtml(c.Nb)}</td></tr>`;
    });
    $('#compteurs-body').html(cbody);

    // Cartouche clubs — nominations faites par les JA du club / nombre à effectuer
    $('#coef-nat').text('× ' + (res.coefNat ?? ''));
    $('#coef-reg').text('× ' + (res.coefReg ?? ''));
    let kbody = '';
    const clubs = res.clubs || [];
    if (!clubs.length) kbody = '<tr><td colspan="6" class="text-muted">Aucun club avec équipe en régionale dans votre périmètre.</td></tr>';
    clubs.forEach(k => {
        const quota = parseInt(k.Quota) || 0;
        const nom   = parseInt(k.NbNom) || 0;
        const pct   = quota ? Math.min(100, Math.round(nom / quota * 100)) : 0;
        const cls   = quota && nom >= quota ? 'complet' : 'court';
        kbody += `<tr class="${cls}">
            <td>${escHtml(k.Nom)}</td>
            <td class="num">${escHtml(k.NbReg)}</td>
            <td class="num">${escHtml(k.NbNat)}</td>
            <td class="num">${nom}</td>
            <td class="num">${quota}</td>
            <td class="ratio">${nom} / ${quota}
                <div class="jauge"><span style="width:${pct}%"></span></div>
            </td>
        </tr>`;
    });
    $('#clubs-body').html(kbody);

    buildCalendrier(groupes);   // affichage permanent ; mois repliés par défaut, état conservé au rechargement

    $('#chargement').hide();
    $('#contenu').show();
}

function charger() {
    $.get(DATA_URL, function (res) {
        if (!res.ok) { nijacToast(res.err || 'Erreur de chargement', 'danger'); return; }
        rendu(res);
    }, 'json').fail(() => nijacToast('Erreur réseau', 'danger'));
}

$(charger);
</script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
</body>
</html>
