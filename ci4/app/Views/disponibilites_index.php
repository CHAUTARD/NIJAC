<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>NIJAC – Disponibilités JA (EN13)</title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
<style>
:root { --col-dispo:#2e7d32; }

body { background:#f0f4fa; font-family:'Segoe UI',system-ui,sans-serif; height:100vh; display:flex; flex-direction:column; overflow:hidden; }

/* ── Toolbar ── */
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
.ts-user { color:#1a3a6b; font-weight:600; }
.ts-pwd-warning { display:<?= $changeLogin ? 'inline-flex' : 'none' ?>; align-items:center; gap:.35rem; color:#c00; font-weight:700; cursor:pointer; text-decoration:underline dotted; }
/* ── En-tête ── */
#page-header { background:#2e7d32; color:#fff; padding:.5rem 1.25rem; display:flex; align-items:center; gap:.75rem; font-size:.9rem; font-weight:600; flex-shrink:0; }

/* ── Bandeau département ── */
#barre-dept { --strip-bg:#fff; background:#fff; border-bottom:2px solid #dee2e6; padding:.6rem 1.25rem; display:flex; align-items:center; gap:.9rem; flex-wrap:wrap; }
#barre-dept > .combo-field > select#sel-dept { min-width:260px; }

/* ── Corps ── */
#corps { flex:1; overflow-y:auto; padding:1.25rem clamp(1rem, 4vw, 4rem); width:100%; box-sizing:border-box; }

/* ── Titre section département ── */
.dept-titre {
    display:flex; align-items:center; gap:.6rem;
    font-size:1rem; font-weight:800; color:var(--nijac-blue);
    margin:.5rem 0 .6rem;
    padding-bottom:.35rem;
    border-bottom:2px solid #c8d4e8;
}
.dept-titre .dept-badge {
    background:var(--nijac-blue); color:#fff;
    border-radius:6px; padding:.1rem .55rem;
    font-size:.8rem; font-weight:700;
}
.dept-titre .dept-nb { font-size:.8rem; color:#888; font-weight:400; }

/* ── Grille JA ── */
.ja-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:.65rem; margin-bottom:1.5rem; }

/* ── Couleurs par grade ── */
.ja-card.grade-ja1 { background:#e3f2fd; border-color:#90caf9; }
.ja-card.grade-ja1 .ja-avatar { background:#1565c0; color:#fff; }
.ja-card.grade-ja1 .ja-grade  { background:#1565c0; }

.ja-card.grade-ja2 { background:#e8f5e9; border-color:#a5d6a7; }
.ja-card.grade-ja2 .ja-avatar { background:#2e7d32; color:#fff; }
.ja-card.grade-ja2 .ja-grade  { background:#2e7d32; }

.ja-card.grade-ja3 { background:#fff8e1; border-color:#ffe082; }
.ja-card.grade-ja3 .ja-avatar { background:#f57f17; color:#fff; }
.ja-card.grade-ja3 .ja-grade  { background:#f57f17; }

/* ── Carte JA ── */
.ja-card {
    background:#fff; border:1px solid #d0d8e8; border-radius:9px;
    padding:.65rem .85rem; cursor:pointer;
    display:flex; align-items:center; gap:.65rem;
    transition:box-shadow .12s, transform .1s, border-color .12s;
    text-decoration:none; color:inherit;
}
.ja-card:hover { box-shadow:0 3px 12px rgba(0,0,0,.15); transform:translateY(-1px); border-color:#1f7a3d; background:#eefaf2; color:inherit; }
.ja-card:active { transform:translateY(0); box-shadow:0 1px 4px rgba(0,0,0,.1); }

.ja-avatar { width:38px; height:38px; border-radius:50%; background:#e8eaf6; display:flex; align-items:center; justify-content:center; font-size:.9rem; font-weight:800; color:var(--nijac-blue); flex-shrink:0; }
.ja-corps { flex:1; min-width:0; }
.ja-nom  { font-size:.88rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ja-meta { font-size:.73rem; color:#888; margin-top:.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ja-grade { font-size:.68rem; background:var(--nijac-blue); color:#fff; padding:.06rem .32rem; border-radius:3px; margin-right:.25rem; }
.ja-arrow { color:#bbb; font-size:.9rem; flex-shrink:0; }
.ja-card:hover .ja-arrow { color:#1f7a3d; }

/* ── JA sans disponibilité saisie ── */
.ja-card.no-dispo .ja-avatar { background:#c62828 !important; color:#fff !important; }
.ja-card.no-dispo { border-color:#ef9a9a; background:#fff5f5 !important; }
.ja-card.no-dispo .ja-nom { color:#c62828; }
.ja-card.no-dispo:hover { background:#ffdede !important; border-color:#c62828; box-shadow:0 3px 12px rgba(198,40,40,.28); }
.ja-card.no-dispo:hover .ja-arrow { color:#c62828; }

/* ── JA avec disponibilité saisie ── */
.ja-dispo-badge {
    width:18px; height:18px; border-radius:50%;
    background:#2e7d32; color:#fff;
    display:flex; align-items:center; justify-content:center;
    font-size:.65rem; flex-shrink:0;
}

/* ── Légende ── */
#legende-dispo {
    display:none; align-items:center; gap:1.1rem;
    font-size:.78rem; color:#555; margin-left:auto;
}
.leg-item { display:flex; align-items:center; gap:.35rem; }
.leg-dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
.leg-dot-ok  { background:#2e7d32; }
.leg-dot-ko  { background:#c62828; }

/* ── Placeholder ── */
#placeholder { text-align:center; color:#bbb; padding:3rem 1rem; }
#placeholder i { font-size:3rem; display:block; margin-bottom:.75rem; }

/* ── Spinner ── */
#spinner-dept { display:none; }

/* ── Pied de page ── */
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
<link rel="stylesheet" href="<?= base_url('asset/css/nijac-skin.css') ?>">
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'calendar2-check', 'phTitle' => 'Saisie des disponibilités JA', 'phCode' => 'EN13',
    'phCrumbLabel' => 'Nominateur', 'phCrumbUrl' => site_url('nominateur-menu'), 'phBackUrl' => site_url('nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

<!-- Bandeau département -->
<div id="barre-dept">
    <span class="combo-field">
        <label for="sel-dept">Département</label>
        <select id="sel-dept">
            <option value="">— Choisir un département —</option>
            <optgroup label="Normandie">
            <?php foreach ($deptActifs as $d): ?>
            <option value="<?= (int) $d['CodeDept'] ?>" <?= (string) $d['CodeDept'] === $departement ? 'selected' : '' ?>><?= (int) $d['CodeDept'] ?> — <?= esc($d['nom']) ?></option>
            <?php endforeach; ?>
            </optgroup>
            <?php if ($deptLimitrophes): ?>
            <optgroup label="Départements limitrophes">
            <?php foreach ($deptLimitrophes as $d): ?>
            <option value="<?= (int) $d['CodeDept'] ?>"><?= (int) $d['CodeDept'] ?> — <?= esc($d['nom']) ?> (<?= esc($d['region']) ?>)</option>
            <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
        </select>
    </span>
    <div id="spinner-dept" class="spinner-border spinner-border-sm text-secondary" role="status">
        <span class="visually-hidden">Chargement…</span>
    </div>
    <div id="wrap-limitrophes" style="display:none; align-items:center; gap:.5rem; flex-wrap:wrap;">
        <span style="font-weight:700;color:#444;font-size:.82rem;white-space:nowrap;">Limitrophes</span>
        <span id="limitrophes-checks" style="display:flex; gap:.55rem; flex-wrap:wrap;"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm py-0" id="btn-lim-tous">Tout cocher</button>
        <button type="button" class="btn btn-outline-secondary btn-sm py-0" id="btn-lim-inverse">Inverser</button>
    </div>
    <div id="legende-dispo">
        <span class="leg-item"><span class="leg-dot leg-dot-ok"></span>Disponibilités saisies</span>
        <span class="leg-item"><span class="leg-dot leg-dot-ko"></span>Non renseigné</span>
    </div>
    <span class="combo-field" style="display:none" id="wrap-filtre-dispo">
        <label for="sel-filtre-dispo">Filtre</label>
        <select id="sel-filtre-dispo">
            <option value="">Tous les JA</option>
            <option value="ok">Avec disponibilités</option>
            <option value="ko">Non renseigné</option>
        </select>
    </span>
    <span class="combo-field" style="display:none" id="wrap-filtre-ja">
        <label for="filtre-ja">Rechercher</label>
        <input type="search" id="filtre-ja" placeholder="Nom / prénom…" autocomplete="off">
    </span>
</div>

<!-- Corps -->
<div id="corps">
    <div id="placeholder">
        <i class="bi bi-person-lines-fill"></i>
        Sélectionnez un département pour afficher les JA disponibles
    </div>
    <div id="liste-ja" style="display:none"></div>
</div>

<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const DISPO_BASE = '<?= site_url('disponibilites') ?>';
const DISPONIBILITE_JA_BASE = '<?= site_url('disponibilite-ja') ?>';

// Libellés des départements
const DEPT_NOMS = <?= json_encode(
    // '+' (pas array_merge) : préserve les clés code-département, que PHP caste en
    // entiers pour les codes numériques (ex. "76") — array_merge les aurait renumérotées.
    array_column($deptActifs, 'nom', 'CodeDept') + array_column($deptLimitrophes, 'nom', 'CodeDept'),
    JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE
) ?>;

// Voisins de la région proposés en cases à cocher, par département sélectionné.
// Pas de JSON_FORCE_OBJECT : les clés (14, 27…) donnent déjà un objet, et on
// veut garder les listes internes en tableaux JS (.length / .forEach).
const LIMITROPHES = <?= json_encode($limitrophesParDept, JSON_UNESCAPED_UNICODE) ?>;

$(function () {
    const deptInitial = $('#sel-dept').val();
    if (deptInitial) { majLimitrophes(deptInitial); chargerJA(deptInitial); }

    $('#sel-dept').on('change', function () {
        const dept = this.value;
        $('#filtre-ja').val('');
        majLimitrophes(dept);
        if (!dept) {
            $('#liste-ja').hide().empty();
            $('#placeholder').show();
            $('#wrap-filtre-dispo').hide();
            $('#wrap-filtre-ja').hide();
            $('#legende-dispo').hide();
            $('#sel-filtre-dispo').val('');
            return;
        }
        chargerJA(dept);
    });

    // Cocher / décocher un voisin, ou les boutons « Tout cocher » / « Inverser »
    // → recharge la grille avec la liste étendue.
    $('#limitrophes-checks').on('change', '.lim-chk', rechargerAvecLimitrophes);
    $('#btn-lim-tous').on('click', function () {
        $('.lim-chk').prop('checked', true);
        rechargerAvecLimitrophes();
    });
    $('#btn-lim-inverse').on('click', function () {
        $('.lim-chk').each(function () { this.checked = !this.checked; });
        rechargerAvecLimitrophes();
    });

    $('#filtre-ja').on('input', filtrerJA);
    $('#sel-filtre-dispo').on('change', filtrerJA);
});

function majLimitrophes(dept) {
    const $box  = $('#limitrophes-checks').empty();
    const liste = LIMITROPHES[dept] || [];
    if (!dept || !liste.length) { $('#wrap-limitrophes').hide(); return; }
    liste.forEach(d => {
        $box.append(
            `<label style="font-size:.82rem;display:inline-flex;align-items:center;gap:.25rem;cursor:pointer;">
                <input type="checkbox" class="form-check-input lim-chk mt-0" value="${escHtml(d.CodeDept)}">
                ${escHtml(d.CodeDept)} ${escHtml(d.nom)}
            </label>`
        );
    });
    // « Tout cocher » / « Inverser » n'ont de sens qu'avec au moins 2 voisins.
    $('#btn-lim-tous, #btn-lim-inverse').toggle(liste.length > 1);
    $('#wrap-limitrophes').css('display', 'flex');
}

function extrasCoches() {
    return $('.lim-chk:checked').map(function () { return this.value; }).get();
}

function rechargerAvecLimitrophes() {
    const dept = $('#sel-dept').val();
    if (dept) chargerJA(dept);
}

function chargerJA(dept) {
    $('#placeholder').hide();
    $('#liste-ja').hide().empty();
    $('#spinner-dept').show();

    $.ajax({
        url: `${DISPO_BASE}/ja-dept`,
        data: { dept: dept, extra: extrasCoches().join(',') },
        dataType: 'json'
    }).done(function (r) {
        $('#spinner-dept').hide();
        if (!r.ok) {
            $('#liste-ja').html(`<div class="alert alert-danger">${escHtml(r.err)}</div>`).show();
            return;
        }
        if (!r.data.length) {
            $('#liste-ja').html('<div class="text-center text-muted py-4"><i class="bi bi-person-x fs-2 d-block mb-2"></i>Aucun JA actif dans ce département</div>').show();
            return;
        }

        // Grouper par département
        const groupes = {};
        r.data.forEach(ja => {
            const d = ja.Dept || '??';
            if (!groupes[d]) groupes[d] = [];
            groupes[d].push(ja);
        });

        const $liste = $('#liste-ja').empty();

        // Afficher dans l'ordre : dept principal en premier, puis les éventuels
        // départements inclus renvoyés par le serveur (ex. 27 pour un 76).
        const ordre = [dept].concat(Object.keys(groupes).filter(d => d !== dept));
        ordre.forEach(d => {
            const jas = groupes[d];
            if (!jas || !jas.length) return;

            const nbDispo   = jas.filter(j => parseInt(j.HasDispo, 10) > 0).length;
            const nbSansDispo = jas.length - nbDispo;
            $liste.append(`
                <div class="dept-titre">
                    <span class="dept-badge">${escHtml(d)}</span>
                    <span>${escHtml(DEPT_NOMS[d] || d)}</span>
                    <span class="dept-nb" data-total="${jas.length}">${jas.length} JA</span>
                    <span style="font-size:.75rem;color:#2e7d32;font-weight:600;"><i class="bi bi-check-circle-fill me-1"></i>${nbDispo} avec dispo</span>
                    ${nbSansDispo > 0 ? `<span style="font-size:.75rem;color:#c62828;font-weight:600;"><i class="bi bi-exclamation-circle-fill me-1"></i>${nbSansDispo} non renseigné</span>` : ''}
                </div>
                <div class="ja-grid" id="grid-${escHtml(d)}"></div>
            `);

            const $grid = $liste.find(`#grid-${d}`);
            jas.forEach(ja => {
                const initiales = ((ja.Prenom || '').charAt(0) + (ja.Nom || '').charAt(0)).toUpperCase();
                const meta = [
                    ja.Grade ? `<span class="ja-grade">${escHtml(ja.Grade)}</span>` : '',
                    ja.Club  ? escHtml(ja.Club) : ''
                ].filter(Boolean).join('');
                const lieu = [ja.Cp, ja.Ville].filter(Boolean).join(' ');

                // Classe CSS selon le grade (JA1, JA2, JA3)
                const gradeClass  = gradeToClass(ja.Grade);
                const hasDispo    = parseInt(ja.HasDispo, 10) > 0;
                const noDispoClass = hasDispo ? '' : 'no-dispo';
                const dispoBadge  = hasDispo
                    ? `<div class="ja-dispo-badge" title="Disponibilités saisies"><i class="bi bi-check-lg"></i></div>`
                    : `<div class="ja-dispo-badge" style="background:#c62828;" title="Non renseigné"><i class="bi bi-x-lg"></i></div>`;

                // Lien vers la fiche de disponibilité (EN22) dans une nouvelle fenêtre
                $grid.append(`
                    <a class="ja-card ${gradeClass} ${noDispoClass}"
                       href="${DISPONIBILITE_JA_BASE}?id_ja=${ja.Id_JA}"
                       target="_blank"
                       title="${hasDispo ? 'Disponibilités saisies — ' : 'Non renseigné — '}${escHtml(ja.Prenom)} ${escHtml(ja.Nom)}">
                        <div class="ja-avatar">${escHtml(initiales)}</div>
                        <div class="ja-corps">
                            <div class="ja-nom">${escHtml(ja.Prenom)} ${escHtml(ja.Nom)}</div>
                            <div class="ja-meta">
                                ${meta}
                                ${meta && lieu ? ' · ' : ''}
                                ${lieu ? escHtml(lieu) : ''}
                            </div>
                        </div>
                        ${dispoBadge}
                    </a>
                `);
            });
        });

        $liste.show();
        $('#wrap-filtre-dispo').show();
        $('#wrap-filtre-ja').show();
        $('#legende-dispo').css('display', 'flex');
    }).fail(function () {
        $('#spinner-dept').hide();
        $('#liste-ja').html('<div class="alert alert-danger">Erreur de chargement.</div>').show();
    });
}

function filtrerJA() {
    const terme  = $('#filtre-ja').val().trim().toLowerCase();
    const dispo  = $('#sel-filtre-dispo').val(); // '' | 'ok' | 'ko'

    $('#liste-ja .ja-grid').each(function () {
        let visibles = 0;
        $(this).find('.ja-card').each(function () {
            const $card   = $(this);
            const texte   = $card.text().toLowerCase();
            const hasDispo = !$card.hasClass('no-dispo');

            const okTexte = !terme || texte.includes(terme);
            const okDispo = !dispo
                || (dispo === 'ok' &&  hasDispo)
                || (dispo === 'ko' && !hasDispo);

            const ok = okTexte && okDispo;
            $card.toggle(ok);
            if (ok) visibles++;
        });
        // Mettre à jour le compteur dans le titre de section
        const $titre = $(this).prev('.dept-titre');
        $titre.find('.dept-nb').text(visibles + ' JA');
        // Masquer la section entière si aucun résultat
        $titre.toggle(visibles > 0);
        $(this).toggle(visibles > 0);
    });
}

function gradeToClass(grade) {
    if (!grade) return '';
    const g = grade.toUpperCase().replace(/\s/g, '');
    if (g.includes('JA1') || g === '1') return 'grade-ja1';
    if (g.includes('JA2') || g === '2') return 'grade-ja2';
    if (g.includes('JA3') || g === '3') return 'grade-ja3';
    return '';
}

function escHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
