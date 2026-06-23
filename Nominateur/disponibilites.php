<?php
/**
 * NIJAC – Saisie des disponibilités JA par le nominateur (E021)
 *
 * Interface nominateur pour consulter et modifier les disponibilités des JA
 * par département (Normandie : 14, 27, 50, 61, 76). La sélection du département 76
 * inclut automatiquement l'Eure (27). Un clic sur un JA ouvre disponibilite_ja.php
 * dans une nouvelle fenêtre pour la saisie détaillée par journée.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-19
 */
session_start();
if (!isset($_SESSION['utilisateur'])) {
    header('Location: ../index.php');
    exit;
}

require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';

$pdo = getPDO();

// ── Action AJAX : JA par département(s) ─────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action === 'ja_dept') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $depts = array_filter(array_map('trim', explode(',', $_GET['depts'] ?? '')));
        if (!$depts) { echo json_encode(['ok' => false, 'err' => 'Aucun département']); exit; }

        // Filtrage sur les 2 premiers chiffres du CP (ja.Cp ou laposte.CodePostal)
        $jaCols = array_column($pdo->query('DESCRIBE ja')->fetchAll(), 'Field');
        $hasCp    = in_array('Cp',    $jaCols);
        $hasVille = in_array('Ville', $jaCols);
        $cpExpr    = $hasCp    ? 'COALESCE(lp.CodePostal, ja.Cp)'    : 'lp.CodePostal';
        $villeExpr = $hasVille ? 'COALESCE(lp.Nom, ja.Ville)'        : 'lp.Nom';

        // Construire la clause IN pour les préfixes de CP
        $placeholders = implode(',', array_fill(0, count($depts), '?'));
        $stmt = $pdo->prepare("
            SELECT ja.Id_JA,
                   ja.Nom,
                   ja.Prenom,
                   ja.Grade,
                   cl.Nom      AS Club,
                   $cpExpr    AS Cp,
                   $villeExpr AS Ville,
                   LEFT(COALESCE(lp.CodePostal, ja.Cp), 2) AS Dept,
                   (SELECT COUNT(*) FROM disponible d WHERE d.Id_JA = ja.Id_JA) AS HasDispo
            FROM ja
            LEFT JOIN Club    cl ON cl.Id_Club    = ja.Id_Club
            LEFT JOIN laposte lp ON lp.Id_LaPoste = ja.Id_LaPoste
            WHERE ja.Actif = 1
              AND LEFT(COALESCE(lp.CodePostal, ja.Cp), 2) IN ($placeholders)
            ORDER BY LEFT(COALESCE(lp.CodePostal, ja.Cp), 2), ja.Nom, ja.Prenom
        ");
        $stmt->execute(array_values($depts));
        $rows = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'data' => $rows]);
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
    }
    exit;
}

// ── Données session ──────────────────────────────────────────────────────────
$u = $_SESSION['utilisateur'];
$nomComplet  = htmlspecialchars(($u['nom'] ?? '') . ' ' . ($u['prenom'] ?? ''));
$departement = htmlspecialchars($u['id_departement'] ?? '');
$changeLogin = !empty($u['change_login']);
$isAdmin     = !empty($u['is_admin']);
$deptActifs  = getDeptActifs();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NIJAC – Disponibilités JA (E021)</title>
<link rel="stylesheet" href="../asset/css/bootstrap.min.css">
<link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
<style>
:root { --nijac-blue:#1a3a6b; --col-dispo:#2e7d32; }

body { background:#f0f4fa; font-family:'Segoe UI',system-ui,sans-serif; height:100vh; display:flex; flex-direction:column; overflow:hidden; }

/* ── Toolbar ── */
.ts-user { color:#1a3a6b; font-weight:600; }
.ts-pwd-warning { display:<?= $changeLogin ? 'inline-flex' : 'none' ?>; align-items:center; gap:.35rem; color:#c00; font-weight:700; cursor:pointer; text-decoration:underline dotted; }
/* ── En-tête ── */
#page-header { background:var(--nijac-blue); color:#fff; padding:.65rem 1.25rem; display:flex; align-items:center; gap:.75rem; font-size:.9rem; font-weight:600; }
#page-header .btn-retour { margin-left:auto; }

/* ── Bandeau département ── */
#barre-dept { background:#fff; border-bottom:2px solid #dee2e6; padding:.65rem 1.25rem; display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap; }
#barre-dept label { font-weight:700; color:#444; font-size:.88rem; margin:0; white-space:nowrap; }
#sel-dept { min-width:260px; font-size:.88rem; }
#info-dept { font-size:.8rem; color:#888; font-style:italic; }

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
.ja-card:hover { box-shadow:0 3px 12px rgba(0,0,0,.15); transform:translateY(-1px); border-color:#90a8d0; color:inherit; }
.ja-card:active { transform:translateY(0); box-shadow:0 1px 4px rgba(0,0,0,.1); }

.ja-avatar { width:38px; height:38px; border-radius:50%; background:#e8eaf6; display:flex; align-items:center; justify-content:center; font-size:.9rem; font-weight:800; color:var(--nijac-blue); flex-shrink:0; }
.ja-corps { flex:1; min-width:0; }
.ja-nom  { font-size:.88rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ja-meta { font-size:.73rem; color:#888; margin-top:.1rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ja-grade { font-size:.68rem; background:var(--nijac-blue); color:#fff; padding:.06rem .32rem; border-radius:3px; margin-right:.25rem; }
.ja-arrow { color:#bbb; font-size:.9rem; flex-shrink:0; }
.ja-card:hover .ja-arrow { color:var(--nijac-blue); }

/* ── JA sans disponibilité saisie ── */
.ja-card.no-dispo .ja-avatar { background:#c62828 !important; color:#fff !important; }
.ja-card.no-dispo { border-color:#ef9a9a; background:#fff5f5 !important; }
.ja-card.no-dispo .ja-nom { color:#c62828; }

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

</style>
</head>
<body>

<?php $pageIcon = 'bi-calendar2-check'; $pageIconClass = 'fs-5'; $pageTitle = 'Saisie des disponibilités JA'; $pageCode = 'E021'; $backUrl = 'menu.php'; $backBtnClass = 'btn btn-sm btn-light py-0 btn-retour'; require __DIR__ . '/../includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Bandeau département -->
<div id="barre-dept">
    <label for="sel-dept"><i class="bi bi-map me-1"></i>Département</label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Choisir un département —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int)$d['code'] ?>" <?= (string)$d['code'] === $departement ? 'selected' : '' ?>><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <span id="info-dept"></span>
    <div id="spinner-dept" class="spinner-border spinner-border-sm text-secondary" role="status">
        <span class="visually-hidden">Chargement…</span>
    </div>
    <div id="legende-dispo">
        <span class="leg-item"><span class="leg-dot leg-dot-ok"></span>Disponibilités saisies</span>
        <span class="leg-item"><span class="leg-dot leg-dot-ko"></span>Aucune disponibilité</span>
    </div>
    <div class="input-group input-group-sm" style="max-width:200px; display:none" id="wrap-filtre-dispo">
        <label class="input-group-text" for="sel-filtre-dispo"><i class="bi bi-funnel-fill"></i></label>
        <select id="sel-filtre-dispo" class="form-select form-select-sm">
            <option value="">Tous les JA</option>
            <option value="ok">Avec disponibilités</option>
            <option value="ko">Sans disponibilités</option>
        </select>
    </div>
    <div class="input-group input-group-sm ms-2" style="max-width:240px; display:none" id="wrap-filtre-ja">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" id="filtre-ja" class="form-control" placeholder="Filtrer par nom / prénom…" autocomplete="off">
    </div>
</div>

<!-- Corps -->
<div id="corps">
    <div id="placeholder">
        <i class="bi bi-person-lines-fill"></i>
        Sélectionnez un département pour afficher les JA disponibles
    </div>
    <div id="liste-ja" style="display:none"></div>
</div>

<!-- Pied de page -->
<?php require __DIR__ . '/../includes/footer.php'; ?>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/nijac-csrf.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

// Libellés des départements
const DEPT_NOMS = <?= json_encode(array_column($deptActifs, 'nom', 'code'), JSON_UNESCAPED_UNICODE) ?>;

$(function () {
    const deptInitial = $('#sel-dept').val();
    if (deptInitial) chargerJA(deptInitial);

    $('#sel-dept').on('change', function () {
        const dept = this.value;
        $('#filtre-ja').val('');
        if (!dept) {
            $('#liste-ja').hide().empty();
            $('#placeholder').show();
            $('#info-dept').text('');
            $('#wrap-filtre-dispo').hide();
            $('#wrap-filtre-ja').hide();
            $('#legende-dispo').hide();
            $('#sel-filtre-dispo').val('');
            return;
        }
        chargerJA(dept);
    });

    $('#filtre-ja').on('input', filtrerJA);
    $('#sel-filtre-dispo').on('change', filtrerJA);
});

function chargerJA(dept) {
    // Si 76 → inclure aussi l'Eure (27)
    const depts = (dept === '76') ? ['76', '27'] : [dept];

    $('#placeholder').hide();
    $('#liste-ja').hide().empty();
    $('#spinner-dept').show();

    const infoTxt = (dept === '76')
        ? 'Seine-Maritime (76) — Eure (27) incluse'
        : DEPT_NOMS[dept] || dept;
    $('#info-dept').text(infoTxt);

    $.ajax({
        url: 'disponibilites.php',
        data: { action: 'ja_dept', depts: depts.join(',') },
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

        // Afficher dans l'ordre : dept principal en premier
        const ordre = depts.concat(Object.keys(groupes).filter(d => !depts.includes(d)));
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
                    ${nbSansDispo > 0 ? `<span style="font-size:.75rem;color:#c62828;font-weight:600;"><i class="bi bi-exclamation-circle-fill me-1"></i>${nbSansDispo} sans dispo</span>` : ''}
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
                    : `<div class="ja-dispo-badge" style="background:#c62828;" title="Aucune disponibilité saisie"><i class="bi bi-x-lg"></i></div>`;

                // Lien vers disponibilite_ja.php dans une nouvelle fenêtre
                $grid.append(`
                    <a class="ja-card ${gradeClass} ${noDispoClass}"
                       href="disponibilite_ja.php?id_ja=${ja.Id_JA}"
                       target="_blank"
                       title="${hasDispo ? 'Disponibilités saisies — ' : 'Aucune disponibilité — '}${escHtml(ja.Prenom)} ${escHtml(ja.Nom)}">
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
