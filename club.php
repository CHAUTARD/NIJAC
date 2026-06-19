<?php
/**
 * NIJAC – Gestion des clubs et associations (E008)
 *
 * Importe et gère la liste des clubs affiliés à la ligue depuis un fichier Excel FFTT.
 * Le fichier doit comporter les colonnes "N° FFTT" (Id_Club) et "Nom club" (Nom),
 * les données étant lues à partir de la ligne 3.
 * L'import effectue un upsert : mise à jour si le club existe déjà, création sinon.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/csrf.php';
require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify(true);

    try {
        $pdo = getPDO();

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            $dept = isset($_POST['dept']) && $_POST['dept'] !== '' ? $_POST['dept'] : null;

            // CP/Ville proviennent toujours de la salle principale (EstPrincipale=1)
            $selectPrincipale = 'SELECT c.Id_Club, c.Nom,
                                        lp.CodePostal,
                                        lp.Nom AS Ville,
                                        (SELECT COUNT(*) FROM Salle s2 WHERE s2.Id_Club = c.Id_Club) AS NbSalles
                                 FROM Club c
                                 LEFT JOIN Salle   sp ON sp.Id_Club    = c.Id_Club
                                                     AND sp.EstPrincipale = 1
                                 LEFT JOIN laposte lp ON lp.Id_LaPoste = sp.Id_Laposte';

            if ($dept !== null) {
                $deptPad = str_pad((string)$dept, 2, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare(
                    $selectPrincipale . '
                     WHERE c.Id_Club IN (
                         SELECT s.Id_Club
                         FROM Salle   s
                         JOIN laposte lf ON lf.Id_LaPoste = s.Id_Laposte
                         WHERE LEFT(lf.CodePostal, 2) = ?
                     )
                     ORDER BY c.Nom'
                );
                $stmt->execute([$deptPad]);
                $rows = $stmt->fetchAll();
            } else {
                $rows = $pdo->query($selectPrincipale . ' ORDER BY c.Nom')->fetchAll();
            }
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Importer Excel ─────────────────────────────────────────────────
        if ($action === 'importer_excel') {
            if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Aucun fichier reçu.']);
                exit;
            }
            if (strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
                exit;
            }

            $spreadsheet = IOFactory::load($_FILES['fichier']['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = $sheet->getHighestRow();

            $lignes = [];
            for ($row = 3; $row <= $maxRow; $row++) {
                $col1 = trim((string)$sheet->getCell('A' . $row)->getValue());
                $col3 = trim((string)$sheet->getCell('B' . $row)->getValue());
                if ($col1 === '' && $col3 === '') continue;

                $idClub = $col1 !== '' ? (int)$col1 : null;
                $nom    = $col3 !== '' ? mb_strtoupper($col3, 'UTF-8') : null;

                if ($idClub === null) continue;

                $lignes[] = [
                    'id_club' => $idClub,
                    'nom'     => $nom !== '' ? $nom : null,
                ];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
            exit;
        }

        // ── Mettre à jour la BDD (UPSERT) ─────────────────────────────────
        if ($action === 'maj_bdd') {
            $lignes = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) {
                echo json_encode(['ok' => false, 'msg' => 'Données invalides.']);
                exit;
            }

            $inserts = 0;
            $updates = 0;
            $erreurs = [];

            $stmtCheck  = $pdo->prepare('SELECT COUNT(*) FROM Club WHERE Id_Club = ?');
            $stmtUpdate = $pdo->prepare('UPDATE Club SET Nom = ? WHERE Id_Club = ?');
            $stmtInsert = $pdo->prepare('INSERT INTO Club (Id_Club, Nom) VALUES (?, ?)');

            foreach ($lignes as $l) {
                $id  = (int)($l['id_club'] ?? 0);
                $nom = trim($l['nom'] ?? '') ?: null;
                if ($id === 0) continue;

                try {
                    $stmtCheck->execute([$id]);
                    if ((int)$stmtCheck->fetchColumn() > 0) {
                        $stmtUpdate->execute([$nom, $id]);
                        $updates++;
                    } else {
                        $stmtInsert->execute([$id, $nom]);
                        $inserts++;
                    }
                } catch (PDOException $ex) {
                    $erreurs[] = "Id $id : " . $ex->getMessage();
                }
            }

            $msg = "Mise à jour terminée : $inserts insérés, $updates mis à jour.";
            if ($erreurs) $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] club.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur Excel : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        ob_end_clean();
        error_log('[NIJAC] club.php : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur : ' . $e->getMessage()]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
$deptActifs  = getDeptActifs();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Clubs et Associations (E008)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="asset/css/nijac.css">

    <style>
        :root { --nijac-blue: #1a3a6b; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
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
        #tbl-clubs tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-clubs tbody td { border: 1px solid #e0e8f0; padding: 0; }

        /* Cellule éditable */
        .cell-inner {
            display: block;
            padding: .28rem .5rem;
            min-height: 28px;
            outline: none;
            white-space: nowrap;
            overflow: hidden;
        }
        .cell-inner[contenteditable="true"] {
            background: #fffbe6;
            outline: 2px solid #f0a000;
            outline-offset: -2px;
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
        #tbl-clubs thead th { cursor: pointer; user-select: none; }
        #tbl-clubs thead th:hover { background: #d4dff0; }
        #tbl-clubs thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-clubs thead th.sort-asc .sort-icon::after  { content: '▲'; opacity: 1; }
        #tbl-clubs thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-clubs thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

        /* ── Toast ── */
        #toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 9999; }

        /* ── Spinner ── */
        #spinner {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.3);
            z-index: 99999;
            align-items: center;
            justify-content: center;
        }
        #spinner.show { display: flex; }
    </style>
</head>
<body>

<?php $pageIcon = 'bi-building'; $pageTitle = 'Gestion des clubs et Associations'; $pageCode = 'E008'; $backUrl = 'admin_menu.php'; require __DIR__ . '/includes/page_header.php'; ?>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-importer" title="Edition 203 FFTT : Liste des clubs du comité D76 - SEINE MARITIME">
        <i class="bi bi-file-earmark-arrow-up"></i>Importation Excel (xlsx)
    </button>
    <button class="menu-item" id="btn-maj-bdd">
        <i class="bi bi-database-fill-up"></i>Mettre à jour la Base de données
    </button>
    <input type="file" id="file-input" accept=".xlsx" style="display:none">
    <span style="margin-left:.75rem; padding:.2rem .6rem; background:#e8eef7; border:1px solid #c8d4e8; border-radius:4px; font-size:.82rem; color:#1a3a6b; font-weight:600;" id="lbl-count">0 club(s)</span>
    &nbsp;&nbsp;&nbsp;Fichier d'origine : édition 204 FFTT - comité D76.
    <span style="flex:1"></span>
    <label for="sel-dept" style="font-size:.85rem;font-weight:700;color:#444;white-space:nowrap;margin:0;">
        <i class="bi bi-map me-1"></i>Département
    </label>
    <select id="sel-dept" class="form-select form-select-sm w-auto">
        <option value="">— Tous —</option>
        <?php foreach ($deptActifs as $d): ?>
        <option value="<?= (int)$d['code'] ?>"><?= (int)$d['code'] ?> — <?= htmlspecialchars($d['nom']) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="menu-item" id="btn-plusieurs-salles" title="Afficher uniquement les clubs ayant plusieurs salles" style="border-color:transparent;">
        <i class="bi bi-door-open me-1"></i>Plusieurs salles
    </button>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- Grille -->
<div id="grid-wrapper">
    <table id="tbl-clubs">
        <thead>
            <tr>
                <th style="width:120px" data-field="id_club">N° FFTT<span class="sort-icon"></span></th>
                <th data-field="nom">Nom club<span class="sort-icon"></span></th>
                <th style="width:100px" data-field="code_postal">Code postal<span class="sort-icon"></span></th>
                <th style="width:180px" data-field="ville">Ville<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="4" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<?php $statusInitial = 'Prêt. — Cliquez sur une cellule puis appuyez sur F2 pour modifier.'; ?>

<!-- Toast -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
    <script src="asset/js/nijac-csrf.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let lignes           = [];
let cellActive       = null;
let sortField        = 'id_club';
let sortDir          = 'asc';
let searchTerm       = '';
let deptFiltre       = '';
let filtreMultiSalle = false;

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg).css('color', ok ? '#374151' : '#c00');
}

function toast(msg, ok = true) {
    const id  = 't' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-container').append(
        `<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show">
           <div class="d-flex">
             <div class="toast-body">${msg}</div>
             <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
           </div>
         </div>`
    );
    setTimeout(() => $(`#${id}`).remove(), 4000);
}

// ── Tri & Recherche ───────────────────────────────────────────────────────────
function lignesFiltreesTriees() {
    const term = searchTerm.toLowerCase();
    let result = [...lignes];
    if (filtreMultiSalle) result = result.filter(l => (l.nb_salles ?? 0) > 1);
    if (term) result = result.filter(l =>
        String(l.id_club     ?? '').toLowerCase().includes(term) ||
        String(l.nom         ?? '').toLowerCase().includes(term) ||
        String(l.code_postal ?? '').toLowerCase().includes(term) ||
        String(l.ville       ?? '').toLowerCase().includes(term));

    result.sort((a, b) => {
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        if (sortField === 'id_club') {
            return sortDir === 'asc' ? (+a.id_club) - (+b.id_club) : (+b.id_club) - (+a.id_club);
        }
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function majEnteteTri() {
    $('#tbl-clubs thead th').each(function () {
        const f = $(this).data('field');
        $(this).removeClass('sort-asc sort-desc');
        if (f === sortField) $(this).addClass(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
}

// ── Rendu ─────────────────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    majEnteteTri();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucun club.';
        $body.append(`<tr><td colspan="4" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} club(s).` : 'Aucun club enregistré.');
        return;
    }

    affichees.forEach((l) => {
        const idx = lignes.indexOf(l);
        const $tr = $('<tr>').attr('data-idx', idx);
        $tr.append(makeTd(l.id_club,     idx, 'id_club',     true));
        $tr.append(makeTd(l.nom,         idx, 'nom',         false));
        $tr.append(makeTd(l.code_postal, idx, 'code_postal', true));
        $tr.append(makeTd(l.ville,       idx, 'ville',       true));
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}` : `${lignes.length} club(s)`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
    $('#lbl-count').text(`${lignes.length} club(s)`);
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? 'col-id' : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);
    if (!readonly) {
        $td.on('click', function () { selectionnerCellule($(this)); });
    }
    return $td;
}

function selectionnerCellule($td) {
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    $td.closest('tr').addClass('selected');
    setStatus(`Cellule sélectionnée — <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

// ── Clavier : F2 / Échap / Entrée ────────────────────────────────────────────
$(document).on('keydown', function (e) {
    if (!cellActive) return;
    const $inner = cellActive.find('.cell-inner');

    if (e.key === 'F2' && $inner.attr('contenteditable') === 'false') {
        e.preventDefault();
        $inner.attr('contenteditable', 'true').trigger('focus');
        const range = document.createRange();
        range.selectNodeContents($inner[0]);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

    } else if (e.key === 'Escape') {
        const idx   = +cellActive.attr('data-idx');
        const field = cellActive.attr('data-field');
        $inner.text(lignes[idx]?.[field] ?? '').attr('contenteditable', 'false');
        setStatus('Modification annulée.');

    } else if (e.key === 'Enter' && $inner.attr('contenteditable') === 'true') {
        e.preventDefault();
        validerCellule($inner, cellActive);
    }
});

$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    if (lignes[idx]) lignes[idx][field] = $inner.text().trim() || null;
    setStatus('Modification locale. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
}

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.post('club.php', { action: 'liste', dept: deptFiltre }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map(r => ({
            id_club:     r.Id_Club,
            nom:         r.Nom,
            code_postal: r.CodePostal ?? '',
            ville:       r.Ville      ?? '',
            nb_salles:   +(r.NbSalles ?? 0),
        }));
        const aMultiSalles = lignes.some(l => l.nb_salles > 1);
        $('#btn-plusieurs-salles').toggle(aMultiSalles);
        if (!aMultiSalles && filtreMultiSalle) {
            filtreMultiSalle = false;
            $('#btn-plusieurs-salles').css({ background: '', color: '', borderColor: 'transparent' });
        }
        renderGrille();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

// ── Importer Excel ────────────────────────────────────────────────────────────
$('#btn-importer').on('click', () => $('#file-input').trigger('click'));

$('#file-input').on('change', function () {
    const file = this.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('action', 'importer_excel');
    fd.append('fichier', file);

    spinner(true);
    $.ajax({
        url: 'club.php', type: 'POST',
        data: fd, processData: false, contentType: false, dataType: 'json',
        success(res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            lignes = res.data;
            renderGrille();
            toast(`${res.count} club(s) importé(s) depuis Excel.`);
            setStatus(`${res.count} club(s) importé(s). Vérifiez les données puis cliquez sur « Mettre à jour la BDD ».`);
        },
        error() { spinner(false); toast("Erreur lors de l'import.", false); }
    });
    this.value = '';
});

// ── Mettre à jour la BDD ──────────────────────────────────────────────────────
$('#btn-maj-bdd').on('click', function () {
    if (!lignes.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Mettre à jour la base de données avec ${lignes.length} club(s) ?`)) return;

    spinner(true);
    $.post('club.php', {
        action: 'maj_bdd',
        lignes: JSON.stringify(lignes),
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-clubs thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    if (sortField === f) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortField = f;
        sortDir   = 'asc';
    }
    renderGrille();
});

// ── Filtre plusieurs salles ───────────────────────────────────────────────────
$('#btn-plusieurs-salles').on('click', function () {
    filtreMultiSalle = !filtreMultiSalle;
    $(this).toggleClass('active', filtreMultiSalle)
           .css({
               background:   filtreMultiSalle ? '#1a3a6b' : '',
               color:        filtreMultiSalle ? '#fff'    : '',
               borderColor:  filtreMultiSalle ? '#1a3a6b' : 'transparent',
           });
    renderGrille();
});

// ── Filtre département ────────────────────────────────────────────────────────
$('#sel-dept').on('change', function () {
    deptFiltre = $(this).val();
    chargerListe();
});

// ── Recherche ─────────────────────────────────────────────────────────────────
$('#search-input').on('input', function () {
    searchTerm = $(this).val().trim();
    renderGrille();
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
