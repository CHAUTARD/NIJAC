<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Contrôle FFTT 131 (EN30)</title>
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">
    <style>
        /* En-tête vert, comme les autres écrans nominateur (E003) */
        #page-header { background: #2e7d32; }

        #panel-liste { width: 100%; }

        /* Bandeau de sélection : comboboxes « label en encoche » comme EN28 */
        #menu-strip {
            --strip-bg: #f8fafc;
            background: #f8fafc;
            border-bottom: 1px solid #dde5f0;
            padding: .4rem .75rem;
            flex-wrap: wrap;
        }
        /* inline-flex : supprime l'espace de ligne (strut) sous le contrôle → hauteur exacte .6rem + 2.15rem, comme le badge */
        #menu-strip .combo-field { display: inline-flex; }
        #menu-strip #lbl-count {
            margin-left: 0; margin-top: .6rem;
            box-sizing: border-box; height: 2.15rem; padding: 0 .9rem; line-height: 1;
            display: inline-flex; align-items: center; justify-content: center; text-align: center;
            min-width: 5.5rem;
            background: #eef2f9; border: 1.5px solid #d3dae6; border-radius: 999px;
            font-size: .82rem; font-weight: 700; color: var(--nijac-blue);
        }
        /* Blocs gauche / droite de même base (flex 1 1 0) → badge #lbl-count centré dans la barre ;
           min-width:max-content : si la place manque, ils s'élargissent (badge décalé, sans chevauchement) puis passent à la ligne */
        #menu-strip .strip-cote { display: flex; align-items: center; gap: .5rem; flex: 1 1 0; min-width: max-content; }
        #menu-strip .strip-droite { justify-content: flex-end; }
        /* Menu « Colonnes » placé à gauche : le panneau s'ouvre vers la droite */
        #menu-colonnes-list { left: 0; right: auto; }
        #msg-zone { padding: .6rem .75rem 0; }
        #msg-zone:empty { display: none; }
        /* Erreurs d'écriture (valider / valider-lot / annuler) : alertes persistantes fermables, non effacées par message() */
        #erreurs-zone { padding: .6rem .75rem 0; }
        #erreurs-zone:empty { display: none; }
        #erreurs-zone .alert + .alert { margin-top: .4rem; }

        #tbl-controle td.centre, #tbl-controle th.centre { text-align: center; }
        #tbl-controle .rapport-motif { font-size: .8rem; }
        #tbl-controle .rapport-detail { font-size: .75rem; color: #6c757d; }
        #tbl-controle .rapport-avert { font-size: .75rem; color: #b26a00; }
        #tbl-controle .dept-ja { font-size: .72rem; color: #9aa5b8; font-style: italic; }

        /* Colonnes masquables : classe « masque-<champ> » sur le tableau → en-tête et cellules « col-<champ> » cachés (survit aux re-rendus) */
        #tbl-controle.masque-traiter .col-traiter, #tbl-controle.masque-licence .col-licence,
        #tbl-controle.masque-nom .col-nom, #tbl-controle.masque-club .col-club,
        #tbl-controle.masque-equipe1 .col-equipe1, #tbl-controle.masque-equipe2 .col-equipe2,
        #tbl-controle.masque-division .col-division, #tbl-controle.masque-journee .col-journee,
        #tbl-controle.masque-rapport .col-rapport { display: none; }
    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'clipboard2-data', 'phTitle' => 'Contrôle FFTT 131', 'phCode' => 'EN30',
    'phCrumbLabel' => 'Suivi des nominations', 'phCrumbUrl' => site_url('suivi-nomination'), 'phBackUrl' => site_url('suivi-nomination'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement, 'tbShowPwdWarning' => false]) ?>

<div id="split-container">
    <div id="panel-liste">
        <div id="menu-strip">
            <div class="strip-cote">
            <span class="combo-field">
                <label for="sel-dept">Département</label>
                <select id="sel-dept" style="width:auto;">
                    <?php foreach ($groupes as $cle => $g): ?>
                    <option value="<?= esc((string) $cle) ?>"<?= $g['defaut'] ? ' selected' : '' ?>><?= esc($g['libelle']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="combo-field">
                <label for="sel-date">Journée</label>
                <select id="sel-date" style="width:auto;min-width:280px;" disabled>
                    <option value="">—</option>
                </select>
            </span>
            <span class="combo-field">
                <details id="menu-colonnes">
                    <summary id="menu-colonnes-resume" title="Afficher / masquer des colonnes">Colonnes</summary>
                    <div id="menu-colonnes-list"></div>
                </details>
            </span>
            </div>
            <span class="count-badge" id="lbl-count" title="Lignes affichées">0</span>
            <div class="strip-cote strip-droite">
            <button type="button" id="btn-annuler" class="btn btn-sm btn-outline-secondary align-self-center" style="margin-top:.6rem"
                    <?= empty($annulation['annulable']) ? 'disabled' : '' ?>
                    title="<?= esc(empty($annulation['annulable']) ? 'Aucune validation à annuler' : 'Annuler la dernière validation : ' . $annulation['libelleAnnulation']) ?>">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Annuler la dernière validation
            </button>
            <button type="button" id="btn-valider-lot" class="btn btn-sm btn-success align-self-center" style="margin-top:.6rem" disabled
                    title="Crée en une fois les nominations des lignes « Aucune nomination » dont la rencontre est en R3M ou R4M">
                <i class="bi bi-check2-all me-1"></i>Valider tous les R3M / R4M (<span id="nb-lot">0</span>)
            </button>
            <div class="form-check ms-3 mb-0 align-self-center" style="margin-top:.6rem"
                 title="Toutes les erreurs du fichier 131 (toutes journées et départements) : lignes non traitables (date illisible, département hors de vos groupes…), incohérences et hors périmètre — lecture seule">
                <input type="checkbox" id="chk-autres-erreurs" class="form-check-input" disabled>
                <label class="form-check-label small" for="chk-autres-erreurs">Afficher toutes les erreurs (<span id="nb-autres">0</span>)</label>
            </div>
            <div class="form-check ms-3 mb-0 align-self-center" style="margin-top:.6rem">
                <input type="checkbox" id="chk-masquer-conformes" class="form-check-input" checked>
                <label class="form-check-label small" for="chk-masquer-conformes">Masquer les conformes</label>
            </div>
            <div class="form-check ms-3 mb-0 align-self-center" style="margin-top:.6rem"
                 title="Affiche aussi les lignes cochées « Traité » (validées ou marquées traitées) : leur case reste décochable">
                <input type="checkbox" id="chk-traites" class="form-check-input">
                <label class="form-check-label small" for="chk-traites">Afficher les lignes traitées</label>
            </div>
            </div>
        </div>
        <div id="msg-zone"></div>
        <div id="erreurs-zone"></div>
        <div id="table-wrapper">
            <table id="tbl-controle">
                <thead>
                    <tr>
                        <th class="centre col-traiter" style="width:60px" data-field="traiter" title="Cochée = ligne traitée (Traiter = 1), masquée par défaut">Traité<span class="sort-icon"></span></th>
                        <th class="centre col-licence" style="width:90px" data-field="licence">Licence<span class="sort-icon"></span></th>
                        <th class="col-nom" data-field="nom">Nom Prénom<span class="sort-icon"></span></th>
                        <th class="col-club" data-field="club">Club<span class="sort-icon"></span></th>
                        <th class="col-equipe1" data-field="equipe1">Équipe 1 (recevante)<span class="sort-icon"></span></th>
                        <th class="col-equipe2" data-field="equipe2">Équipe 2<span class="sort-icon"></span></th>
                        <th class="col-division" data-field="division">Division<span class="sort-icon"></span></th>
                        <th class="centre col-journee" style="width:70px" data-field="journee">Journée<span class="sort-icon"></span></th>
                        <th class="col-rapport" style="min-width:280px" data-field="rapport">Rapport<span class="sort-icon"></span></th>
                        <th class="centre" style="width:90px">Action</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="10" class="text-center text-muted py-3">Choisissez un département et une journée.</td></tr>
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
const CTRL_BASE = '<?= site_url('controle-f131') ?>';
const DIVISION_NOMS = <?= json_encode($divisionNoms ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const JOURS_SEMAINE = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
let lignes = [];
/** Erreurs du fichier entier (data().erreurs : non traitables + Incohérence / Hors périmètre) : affichées seulement si « Afficher toutes les erreurs », jamais validables ni dans le lot. */
let autres = [];
/* Tri par défaut : aucun → ordre du serveur (Date, puis Id_F131) ; le n° de ligne Excel n'est ni affiché ni utilisé. */
const sortState = { col: null, asc: true };

/** Échappement HTML (même fonction qu'EN28) : tout texte venant du fichier ou de la base passe par ici. */
function escHtml(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** "YYYY-MM-DD" → "Sam 03/10/2026" (composants locaux, pas de décalage UTC). */
function formatDate(d) {
    const [y, m, j] = d.substring(0, 10).split('-').map(Number);
    return `${JOURS_SEMAINE[new Date(y, m - 1, j).getDay()].substring(0, 3)} ${String(j).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
}

/** Rapport de l'analyse 131 (mêmes libellés que l'import EN28) → libellé lisible + couleur. « sera » = ce que fait le bouton Valider de la ligne. */
const RAPPORTS = {
    'Conforme':       { cls: 'text-bg-success',   lib: 'Conforme' },
    'Création':       { cls: 'text-bg-primary',   lib: 'Aucune nomination' },
    'Remplacement':   { cls: 'text-bg-warning',   lib: 'Autre JA nommé — sera remplacé' },
    'Incohérence':    { cls: 'text-bg-danger',    lib: 'Incohérence' },
    'Hors périmètre': { cls: 'text-bg-secondary', lib: 'Hors périmètre' },
    'Non traitable':  { cls: 'bg-secondary-subtle text-danger border border-danger', lib: 'Non traitable' },
};
const ORDRE_RAPPORT = ['Non traitable', 'Incohérence', 'Remplacement', 'Création', 'Hors périmètre', 'Conforme'];
/** Verdicts applicables : bouton « Valider » (le serveur ré-analyse et décide). */
const APPLICABLES = ['Création', 'Remplacement'];
/** Validation en lot : lignes « Création » dont la rencontre (équipe recevante) est dans ces divisions — le serveur recalcule la liste. */
const DIVISIONS_LOT = <?= json_encode(\App\Controllers\ControleF131Controller::DIVISIONS_LOT) ?>;
const lignesLot = () => lignes.filter(l => +l.Traiter === 0 && l.rapport?.action === 'Création' && DIVISIONS_LOT.includes(l.divisionRencontre));
/** « Afficher les lignes traitées » coché : lignes Traiter = 1 demandées au serveur (traites=1) et conservées à l'affichage. */
const avecTraites = () => $('#chk-traites').prop('checked');
/** Filtre local (une case cochée / une validation fait passer Traiter à 1 sans recharger) : sans le filtre, lignes à traiter seules. */
const visible = l => avecTraites() || +l.Traiter === 0;

function majBoutonLot() {
    const n = lignesLot().length;
    $('#nb-lot').text(n);
    $('#btn-valider-lot').prop('disabled', n === 0);
}

/** Bouton « Annuler la dernière validation » : état {annulable, libelleAnnulation} renvoyé par le serveur (page, valider, valider-lot, annuler). */
let annulation = <?= json_encode($annulation ?? ['annulable' => false, 'libelleAnnulation' => ''], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
function majBoutonAnnuler(r) {
    if (r && r.annulable !== undefined) annulation = { annulable: !!r.annulable, libelleAnnulation: r.libelleAnnulation || '' };
    $('#btn-annuler').prop('disabled', !annulation.annulable)
        .attr('title', annulation.annulable ? `Annuler la dernière validation : ${annulation.libelleAnnulation}` : 'Aucune validation à annuler');
}

const CLES_TRI = {
    traiter:  l => +l.Traiter,
    licence:  l => l.Licence === null ? -1 : +l.Licence,
    nom:      l => `${l.Nom ?? ''} ${l.Prenom ?? ''}`,
    club:     l => l.Club ?? '',
    equipe1:  l => l.Equipe1 ?? '',
    equipe2:  l => l.Equipe2 ?? '',
    division: l => l.Division ?? '',
    journee:  l => l.Journee ?? '',
    rapport:  l => { const i = ORDRE_RAPPORT.indexOf(l.rapport?.action ?? ''); return i < 0 ? 99 : i; },
};

function message(texte, type) {
    $('#msg-zone').html(texte ? `<div class="alert alert-${type} small mb-0">${escHtml(texte)}</div>` : '');
}

/**
 * Échec d'écriture (valider / valider-lot / annuler) : alerte rouge PERSISTANTE et fermable dans #erreurs-zone (non effacée
 * par message() ni par les rechargements), avec heure, message complet du serveur et détails (lignes, motif…). Plus récente en tête.
 */
function alerteErreur(titre, texte, details) {
    const h = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const det = (details || []).filter(Boolean).map(d => `<li>${escHtml(d)}</li>`).join('');
    $('#erreurs-zone').prepend(
        `<div class="alert alert-danger alert-dismissible small mb-0" role="alert">`
        + `<strong>${escHtml(titre)}</strong> <span class="text-muted">(${escHtml(h)})</span><div>${escHtml(texte)}</div>`
        + (det ? `<ul class="mb-0 mt-1">${det}</ul>` : '')
        + `<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>`
    );
}

/** Libellé d'une ligne pour les messages : « Équipe 1 – Équipe 2 (JA) » (jamais le n° de ligne Excel). */
const libLigne = l => `${[l.Equipe1, l.Equipe2].filter(Boolean).join(' – ') || 'sans équipes'} (${`${l.Nom ?? ''} ${l.Prenom ?? ''}`.trim()})`;

/** Lignes rappelées par le serveur (valider-lot : ids = Id_F131) → libellés « Équipe 1 – Équipe 2 (JA) ». */
function detailLignes(ids) {
    return (ids || []).map(id => {
        const l = lignes.find(x => +x.Id_F131 === +id);
        return l ? libLigne(l) : 'Ligne introuvable dans la liste affichée';
    });
}

/** Une ligne est une « erreur » si son verdict n'est ni Conforme ni applicable (Création / Remplacement). */
const estErreur = l => !['Conforme', ...APPLICABLES].includes(l.rapport?.action);
/** Erreurs de la table absentes de la journée affichée (dédoublonnage par Id_F131 : la version de la journée prime). */
function autresHorsJournee() {
    const vues = new Set(lignes.map(l => +l.Id_F131));
    return autres.filter(a => !vues.has(+a.Id_F131) && visible(a));
}

function celluleRapport(r) {
    if (!r) return '<span class="text-muted">—</span>';
    const d = RAPPORTS[r.action] || { cls: 'text-bg-light border', lib: r.action };
    let h = `<span class="badge ${d.cls}">${escHtml(d.lib)}</span>`;
    if (r.motif && r.motif !== r.action) h += ` <span class="rapport-motif">${escHtml(r.motif)}</span>`;
    const det = [r.dateFichier ? 'Date du fichier : ' + r.dateFichier : '', r.rencontre ? 'Rencontre : ' + r.rencontre : '', r.ja ? 'JA : ' + r.ja : ''].filter(Boolean).join(' · ');
    if (det) h += `<div class="rapport-detail">${escHtml(det)}</div>`;
    (r.avertissements || []).forEach(a => { h += `<div class="rapport-avert"><i class="bi bi-exclamation-triangle me-1"></i>${escHtml(a)}</div>`; });
    return h;
}

function renderListe() {
    majBoutonLot();
    const cle = CLES_TRI[sortState.col];
    /* Filtre d'affichage seul : « Masquer les conformes » retire les lignes au verdict « Conforme » (jamais Création / Remplacement). */
    const masquer = $('#chk-masquer-conformes').prop('checked');
    /* « Afficher toutes les erreurs » ajoute les erreurs des autres journées / départements et les non traitables
       (jamais conformes : le filtre ci-dessus ne les retire pas). N = erreurs de la journée + erreurs hors journée, sans doublon. */
    /* Lignes traitées (Traiter = 1) exclues partout (tableau, compteurs, lot) sauf si « Afficher les lignes traitées » est coché. */
    const duJour = lignes.filter(visible);
    const horsJournee = autresHorsJournee();
    const nbErreurs = duJour.filter(estErreur).length + horsJournee.length;
    $('#nb-autres').text(nbErreurs);
    $('#chk-autres-erreurs').prop('disabled', nbErreurs === 0);
    const source = $('#chk-autres-erreurs').prop('checked') ? duJour.concat(horsJournee) : duJour;
    const aff = masquer ? source.filter(l => l.rapport?.action !== 'Conforme') : source.slice();
    const nbMasquees = source.length - aff.length;
    if (cle) {
        aff.sort((a, b) => {
            const va = cle(a), vb = cle(b);
            const cmp = typeof va === 'number' ? va - vb : String(va).localeCompare(String(vb), 'fr');
            return sortState.asc ? cmp : -cmp;
        });
    }
    $('#lbl-count').text(nbMasquees ? `${aff.length} / ${source.length}` : aff.length)
        .attr('title', nbMasquees ? `${aff.length} ligne(s) affichée(s) sur ${source.length} — ${nbMasquees} conforme(s) masquée(s)` : 'Lignes affichées');
    if (!aff.length) {
        const vide = nbMasquees ? `Aucune ligne à traiter (${nbMasquees} ligne${nbMasquees > 1 ? 's' : ''} conforme${nbMasquees > 1 ? 's' : ''} masquée${nbMasquees > 1 ? 's' : ''}).` : 'Aucune ligne.';
        $('#tbody-liste').html(`<tr><td colspan="10" class="text-center text-muted py-3">${escHtml(vide)}</td></tr>`);
        return;
    }
    $('#tbody-liste').html(aff.map(l => `
        <tr data-id="${escHtml(l.Id_F131)}">
            <td class="centre col-traiter"><input type="checkbox" class="form-check-input chk-traiter" data-id="${escHtml(l.Id_F131)}"
                ${+l.Traiter ? 'checked' : ''} ${l.traiterModifiable ? '' : 'disabled'}
                title="${l.traiterModifiable ? (+l.Traiter ? 'Traitée — décocher pour la remettre à traiter' : 'Cocher pour marquer la ligne traitée') : 'Ligne hors de vos départements'}"></td>
            <td class="centre col-licence">${escHtml(l.Licence ?? l.LicenceBrute ?? '')}</td>
            <td class="col-nom">${escHtml(`${l.Nom ?? ''} ${l.Prenom ?? ''}`.trim())}</td>
            <td class="col-club">${escHtml(l.Club ?? '')}</td>
            <td class="col-equipe1">${escHtml(l.Equipe1 ?? '')}${l.deptJa ? '<div class="dept-ja" title="Rencontre non rapprochée : département déduit du club du JA">dépt du club du JA</div>' : ''}</td>
            <td class="col-equipe2">${escHtml(l.Equipe2 ?? '')}</td>
            <td class="col-division" title="${escHtml(DIVISION_NOMS[l.Division] ?? '')}">${escHtml(l.Division ?? '')}</td>
            <td class="centre col-journee">${escHtml(l.Journee ?? '')}</td>
            <td class="col-rapport">${celluleRapport(l.rapport)}</td>
            <td class="centre">${+l.Traiter === 0 && APPLICABLES.includes(l.rapport?.action)
                ? `<button type="button" class="btn btn-sm btn-outline-success btn-valider" data-id="${escHtml(l.Id_F131)}"><i class="bi bi-check2 me-1"></i>Valider</button>`
                : ''}</td>
        </tr>`).join(''));
}

/** Validation d'une ligne : POST id_f131 seul ; le serveur ré-analyse et n'écrit que si la ligne est applicable. */
function validerLigne(id) {
    const l = lignes.find(x => String(x.Id_F131) === String(id));
    if (!l || +l.Traiter !== 0 || !APPLICABLES.includes(l.rapport?.action)) return;
    const det = [l.rapport.rencontre ? 'Rencontre : ' + l.rapport.rencontre : '', l.rapport.ja ? 'JA : ' + l.rapport.ja : ''].filter(Boolean).join('\n');
    const quoi = l.rapport.action === 'Création' ? 'Créer la nomination' : 'Remplacer le JA nommé';
    nijacConfirm(`${quoi} d'après la ligne du fichier 131 « ${libLigne(l)} » ?\n${det}`, function () {
        $(`#tbody-liste .btn-valider[data-id="${CSS.escape(String(id))}"]`).prop('disabled', true);
        $.post(`${CTRL_BASE}/valider`, { id_f131: id }, null, 'json')
            .done(function (r) {
                if (r.rapport !== undefined) l.rapport = r.rapport;
                if (r.ok) majTraiterLocal(l.Id_F131, r.traiter ?? 1);   // validée = traitée : disparaît sans « Afficher les lignes traitées »
                renderListe();
                majBoutonAnnuler(r);
                if (r.ok) {
                    nijacToast(escHtml(r.msg || 'Ligne validée.'), 'success');
                    majJournees();
                } else {
                    const rp = r.rapport;
                    alerteErreur(`Validation de la ligne « ${libLigne(l)} » refusée`, r.msg || 'Erreur.', [
                        rp ? `Verdict actuel : ${RAPPORTS[rp.action]?.lib ?? rp.action}${rp.motif && rp.motif !== rp.action ? ' — ' + rp.motif : ''}` : '',
                    ]);
                }
            })
            .fail(() => { renderListe(); alerteErreur(`Validation de la ligne « ${libLigne(l)} » non aboutie`, 'Erreur réseau ou session expirée : aucune confirmation reçue du serveur, actualisez la page pour vérifier.', []); });
    }, null, { title: 'Valider la ligne', confirmLabel: 'Valider' });
}

/** Validation en lot des « Aucune nomination » R3M / R4M de la journée : POST dept + date seuls ; le serveur ré-analyse et recalcule la liste. */
function validerLot() {
    const n = lignesLot().length;
    if (!n) return;
    const dept = $('#sel-dept').val(), date = $('#sel-date').val();
    nijacConfirm(`Créer les ${n} nomination${n > 1 ? 's' : ''} R3M / R4M « Aucune nomination » de cette journée d'après le fichier 131 ?`, function () {
        $('#btn-valider-lot').prop('disabled', true);
        $.post(`${CTRL_BASE}/valider-lot`, { dept, date }, null, 'json')
            .done(function (r) {
                if (r.ok) nijacToast(escHtml(r.msg || 'Lignes validées.'), 'success');
                else alerteErreur(`Validation en lot R3M / R4M refusée (journée ${formatDate(date)})`, r.msg || 'Erreur.',
                    (r.ids || []).length ? ['Ligne en cours d\'écriture lors de l\'échec (tout le lot est annulé) :', ...detailLignes(r.ids)] : []);
                majBoutonAnnuler(r);
                chargerLignes();
                if (r.ok) majJournees();
            })
            .fail(() => { majBoutonLot(); alerteErreur('Validation en lot R3M / R4M non aboutie', 'Erreur réseau ou session expirée : aucune confirmation reçue du serveur, actualisez la page pour vérifier.', []); });
    }, null, { title: 'Valider les R3M / R4M', confirmLabel: 'Valider' });
}

/** Statut Traiter d'une ligne mis à jour localement (tableau de la journée ET liste des erreurs, même Id_F131). */
function majTraiterLocal(id, val) {
    lignes.concat(autres).forEach(x => { if (String(x.Id_F131) === String(id)) x.Traiter = +val; });
}

/** Case « Traité » : POST id_f131 + traiter 0/1 (f131_travail.Traiter seul, aucune nomination) ; échec → case remise et alerte. */
function basculerTraiter(chk) {
    const id = $(chk).data('id'), val = chk.checked ? 1 : 0;
    const l = lignes.concat(autres).find(x => String(x.Id_F131) === String(id));
    chk.disabled = true;
    $.post(`${CTRL_BASE}/traiter`, { id_f131: id, traiter: val }, null, 'json')
        .done(function (r) {
            if (r.ok) {
                majTraiterLocal(id, r.traiter);
                nijacToast(escHtml(r.msg || 'Statut enregistré.'), 'success', 2000);
                majJournees();
            } else {
                if (r.traiter !== undefined) majTraiterLocal(id, r.traiter);
                alerteErreur(`Statut « Traité » de la ligne${l ? ' « ' + libLigne(l) + ' »' : ''} non enregistré`, r.msg || 'Erreur.', []);
            }
            renderListe();
        })
        .fail(() => { renderListe(); alerteErreur(`Statut « Traité » de la ligne${l ? ' « ' + libLigne(l) + ' »' : ''} non abouti`, 'Erreur réseau ou session expirée : actualisez la page pour vérifier.', []); });
}

/** Annule la dernière validation (ligne ou lot) : le serveur défait l'instantané de session avec garde-fous, puis la liste est rechargée. */
function annulerDerniere() {
    if (!annulation.annulable) return;
    nijacConfirm(`Annuler la dernière validation (${annulation.libelleAnnulation}) ?\nLes nominations créées sont supprimées, les JA remplacés sont rétablis.`, function () {
        $('#btn-annuler').prop('disabled', true);
        $.post(`${CTRL_BASE}/annuler`, {}, null, 'json')
            .done(function (r) {
                if (r.ok) nijacToast(escHtml(r.msg || 'Validation annulée.'), 'success');
                else alerteErreur('Annulation de la dernière validation refusée', r.msg || 'Erreur.',
                    annulation.libelleAnnulation ? [`Validation concernée : ${annulation.libelleAnnulation} (instantané conservé)`] : []);
                majBoutonAnnuler(r);
                chargerLignes();
                if (r.ok) majJournees();
            })
            .fail(() => { majBoutonAnnuler(); alerteErreur('Annulation de la dernière validation non aboutie', 'Erreur réseau ou session expirée : aucune confirmation reçue du serveur, actualisez la page pour vérifier.', []); });
    }, null, { type: 'warning', title: 'Annuler la dernière validation', confirmLabel: 'Annuler la validation', cancelLabel: 'Fermer' });
}

/** Recalcule le nombre de rencontres sans JA des journées (libellés du choix de date), sans recharger le tableau. */
function majJournees() {
    const dept = $('#sel-dept').val(), date = $('#sel-date').val();
    $.getJSON(`${CTRL_BASE}/journees`, { dept, traites: avecTraites() ? 1 : 0 })
        .done(function (r) {
            if (!r.ok || $('#sel-dept').val() !== dept) return;
            $('#sel-date').html(optionsJournees(r.journees, date)).val(date);
        });
}

/** « Afficher les lignes traitées » changé : journées recalculées (avec / sans lignes traitées) puis tableau rechargé si la journée existe encore. */
function changerFiltreTraites() {
    const dept = $('#sel-dept').val(), date = $('#sel-date').val();
    if (!dept) return;
    $.getJSON(`${CTRL_BASE}/journees`, { dept, traites: avecTraites() ? 1 : 0 })
        .done(function (r) {
            if ($('#sel-dept').val() !== dept) return;
            if (!r.ok) { message(r.msg || 'Erreur.', r.vide ? 'info' : 'danger'); return; }
            message('', '');
            const ok = r.journees.some(j => j.date === date);
            $('#sel-date').html(r.journees.length ? optionsJournees(r.journees) : '<option value="">—</option>')
                .prop('disabled', !r.journees.length).val(ok ? date : '');
            if (!r.journees.length) message(messageAucuneJournee(), 'info');
            chargerLignes();
        })
        .fail(() => nijacToast('Erreur réseau ou session expirée.', 'danger'));
}

function messageAucuneJournee() {
    return avecTraites() ? 'Aucune ligne du fichier 131 pour ce département.'
        : 'Aucune ligne à traiter du fichier 131 pour ce département (cochez « Afficher les lignes traitées » pour revoir les lignes traitées).';
}

/** Options du choix de journée ; garder = journée affichée à conserver même si elle n'a plus de ligne (toutes cochées « Traité »). */
function optionsJournees(journees, garder) {
    const liste = journees.slice();
    if (garder && !liste.some(j => j.date === garder)) {
        liste.push({ date: garder, lignes: 0, sansJa: null });
        liste.sort((a, b) => a.date.localeCompare(b.date));
    }
    return '<option value="">Choisir une journée…</option>' + liste.map(j =>
        `<option value="${escHtml(j.date)}">${escHtml(formatDate(j.date))} — ${j.sansJa === null ? 'plus aucune ligne à traiter'
            : `${j.sansJa} rencontre${j.sansJa > 1 ? 's' : ''} sans JA (${j.lignes} ligne${j.lignes > 1 ? 's' : ''} 131${avecTraites() ? '' : ' à traiter'})`}</option>`
    ).join('');
}

/**
 * Erreurs du fichier reçues de data() → même forme que les lignes du tableau (sans bouton Valider) : type « nonTraitable »
 * → rapport « Non traitable » + motif ; type « verdict » → rapport de l'analyse (Incohérence / Hors périmètre) inchangé.
 * Date du fichier ajoutée au détail du rapport (ces lignes peuvent venir d'une autre journée).
 */
function majAutres(liste) {
    autres = (liste || []).map(a => {
        const dateFichier = a.Date ? formatDate(a.Date) : (a.DateBrute ? `« ${a.DateBrute} » (illisible)` : 'absente');
        const rapport = a.type === 'verdict'
            ? { ...a.rapport, dateFichier }
            : { action: 'Non traitable', motif: a.motif, rencontre: '', ja: '', avertissements: [], dateFichier };
        return { ...a, rapport };
    });
}

function viderTableau(texte) {
    lignes = [];
    majAutres([]);
    $('#nb-autres').text(0);
    $('#chk-autres-erreurs').prop('disabled', true);
    majBoutonLot();
    $('#lbl-count').text(0).attr('title', 'Lignes affichées');
    $('#tbody-liste').html(`<tr><td colspan="10" class="text-center text-muted py-3">${escHtml(texte)}</td></tr>`);
}

function chargerJournees() {
    const dept = $('#sel-dept').val();
    const $sel = $('#sel-date').prop('disabled', true).html('<option value="">Chargement…</option>');
    message('', '');
    viderTableau('Choisissez une journée.');
    if (!dept) { $sel.html('<option value="">—</option>'); message('Aucun département autorisé.', 'warning'); return; }
    $.getJSON(`${CTRL_BASE}/journees`, { dept, traites: avecTraites() ? 1 : 0 })
        .done(function (r) {
            if (!r.ok) {
                $sel.html('<option value="">—</option>');
                message(r.msg || 'Erreur.', r.vide ? 'info' : 'danger');
                return;
            }
            if (!r.journees.length) {
                $sel.html('<option value="">—</option>');
                message(messageAucuneJournee(), 'info');
                return;
            }
            $sel.html(optionsJournees(r.journees)).prop('disabled', false);
            if (r.sansDate) message(`${r.sansDate} ligne(s) du fichier sans date lisible ne sont rattachées à aucune journée.`, 'warning');
        })
        .fail(() => { $sel.html('<option value="">—</option>'); nijacToast('Erreur réseau ou session expirée.', 'danger'); });
}

function chargerLignes() {
    const dept = $('#sel-dept').val(), date = $('#sel-date').val();
    if (!date) { viderTableau('Choisissez une journée.'); return; }
    viderTableau('Chargement…');
    $.getJSON(`${CTRL_BASE}/data`, { dept, date, traites: avecTraites() ? 1 : 0 })
        .done(function (r) {
            if (!r.ok) { viderTableau(''); message(r.msg || 'Erreur.', r.vide ? 'info' : 'danger'); return; }
            lignes = r.lignes;
            majAutres(r.erreurs);
            renderListe();
        })
        .fail(() => { viderTableau(''); nijacToast('Erreur réseau ou session expirée.', 'danger'); });
}

// ── Affichage / masquage des colonnes (mémorisé dans le navigateur) ──────────
/* Classe « masque-<champ> » posée sur le tableau (règles CSS en tête) : s'applique aux en-têtes et aux cellules,
   y compris après chaque renderListe() ; les lignes de message (colspan="10") restent sur toute la largeur. */
const LS_COLONNES = 'nijac_en30_colonnes_cachees';
/* 'ligne' (ancienne colonne n° de ligne Excel, supprimée) éventuellement mémorisée : ignorée par le filtre CHAMPS_MASQUABLES ci-dessous. */
const COLONNES_CACHEES_DEFAUT = ['licence', 'nom', 'club'];
const CHAMPS_MASQUABLES = $('#tbl-controle thead th[data-field]').map((i, th) => th.getAttribute('data-field')).get();
let colonnesCachees;
try {
    const brut = localStorage.getItem(LS_COLONNES);
    const lu = brut !== null ? JSON.parse(brut) : COLONNES_CACHEES_DEFAUT;
    colonnesCachees = new Set((Array.isArray(lu) ? lu : COLONNES_CACHEES_DEFAUT).filter(f => CHAMPS_MASQUABLES.includes(f)));
} catch (e) {
    colonnesCachees = new Set(COLONNES_CACHEES_DEFAUT);
}

function appliquerColonnesCachees() {
    const $tbl = $('#tbl-controle');
    CHAMPS_MASQUABLES.forEach(f => $tbl.toggleClass(`masque-${f}`, colonnesCachees.has(f)));
    $('#menu-colonnes-resume').text(`Colonnes ${CHAMPS_MASQUABLES.length - colonnesCachees.size}/${CHAMPS_MASQUABLES.length}`);
}

function construireMenuColonnes() {
    const $box = $('#menu-colonnes-list').empty();
    $('#tbl-controle thead th[data-field]').each(function () {
        const field = this.getAttribute('data-field');
        const label = (this.textContent || '').replace(/[⇅▲▼]/g, '').trim();
        const $chk  = $('<input type="checkbox" class="form-check-input mt-0">').prop('checked', !colonnesCachees.has(field));
        $chk.on('change', function () {
            if (this.checked) colonnesCachees.delete(field);
            else              colonnesCachees.add(field);
            try { localStorage.setItem(LS_COLONNES, JSON.stringify([...colonnesCachees])); } catch (e) {}
            appliquerColonnesCachees();
        });
        $('<label>').append($chk).append(document.createTextNode(' ' + label)).appendTo($box);
    });
}
construireMenuColonnes();
appliquerColonnesCachees();

// Ferme le menu « Colonnes » au clic hors de celui-ci
$(document).on('click', function (e) {
    if (!$(e.target).closest('#menu-colonnes').length) $('#menu-colonnes').removeAttr('open');
});

$('#sel-dept').on('change', chargerJournees);
$('#sel-date').on('change', chargerLignes);
$('#chk-masquer-conformes, #chk-autres-erreurs').on('change', renderListe);
$('#chk-traites').on('change', changerFiltreTraites);
$('#tbody-liste').on('change', '.chk-traiter', function () { basculerTraiter(this); });
$('#tbody-liste').on('click', '.btn-valider', function () { validerLigne($(this).data('id')); });
$('#btn-valider-lot').on('click', validerLot);
$('#btn-annuler').on('click', annulerDerniere);

$(function () {
    nijacSortableTable('#tbl-controle thead th[data-field]', 'field', sortState, renderListe);
    chargerJournees();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
