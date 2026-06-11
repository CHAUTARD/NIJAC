<?php
/**
 * NIJAC – Gestion des correspondants de clubs (E004)
 *
 * Permet de créer, modifier et supprimer les correspondants (contacts référents)
 * associés à chaque club. Supporte l'import depuis un fichier Excel FFTT.
 * Chaque correspondant est lié à un club et possède un nom, un email,
 * un téléphone et une fonction.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Sécurité ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Formater un numéro de téléphone (06 12345678 → 06.12.34.56.78) ───────────
function formaterTelephone(?string $tel): ?string
{
    if ($tel === null || $tel === '') return null;
    $t = preg_replace('/\s+/', '', $tel);
    if (strlen($t) === 10) {
        return implode('.', str_split($t, 2));
    }
    return $tel;
}

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = getPDO();

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            $rows = $pdo->query(
                'SELECT c.Id_Correspondant, c.Nom, c.Email, c.Telephone, c.Fonction,
                        c.Id_Club, cl.Nom AS NomClub
                 FROM Correspondant c
                 LEFT JOIN Club cl ON cl.Id_Club = c.Id_Club
                 ORDER BY c.Id_Club, c.Nom'
            )->fetchAll();
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

            $ext = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Seul le format .xlsx est accepté.']);
                exit;
            }

            $spreadsheet = IOFactory::load($_FILES['fichier']['tmp_name']);
            $sheet       = $spreadsheet->getActiveSheet();
            $maxRow      = $sheet->getHighestRow();

            $lignes = [];
            for ($row = 3; $row <= $maxRow; $row++) {
                // Colonnes : A=Id_Club, H=N°Licence, I=Nom, N=Téléphone, O=Email
                $idClub    = trim((string)$sheet->getCell('A' . $row)->getValue());
                $licence   = trim((string)$sheet->getCell('H' . $row)->getValue());
                $nom       = trim((string)$sheet->getCell('I' . $row)->getValue());
                $telephone = trim((string)$sheet->getCell('N' . $row)->getValue());
                $email     = trim((string)$sheet->getCell('O' . $row)->getValue());

                if ($idClub === '' && $nom === '') continue;

                $lignes[] = [
                    'id'        => $licence !== '' ? (int)$licence : 0,
                    'nom'       => $nom !== '' ? $nom : '????',
                    'email'     => $email !== '' ? $email : null,
                    'telephone' => formaterTelephone($telephone !== '' ? $telephone : null),
                    'fonction'  => 'Correspondant',
                    'id_club'   => $idClub !== '' ? (int)$idClub : null,
                    'nom_club'  => '',
                ];
            }

            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $lignes, 'count' => count($lignes)]);
            exit;
        }

        // ── Mettre à jour la BDD ───────────────────────────────────────────
        if ($action === 'maj_bdd') {
            $lignes = json_decode($_POST['lignes'] ?? '[]', true);
            if (!is_array($lignes)) {
                echo json_encode(['ok' => false, 'msg' => 'Données invalides.']);
                exit;
            }

            $inserts = 0;
            $updates = 0;
            $erreurs = [];

            foreach ($lignes as $l) {
                $id        = (int)($l['id']        ?? 0);
                $nom       = trim($l['nom']       ?? '????');
                $email     = $l['email']     !== '' ? $l['email']     : null;
                $telephone = formaterTelephone($l['telephone'] !== '' ? $l['telephone'] : null);
                $fonction  = trim($l['fonction']  ?? 'Correspondant');
                $idClub    = (int)($l['id_club']   ?? 0) ?: null;

                // Vérifier/créer le club référencé
                if ($idClub !== null) {
                    $nb = $pdo->prepare('SELECT COUNT(*) FROM Club WHERE Id_Club = ?');
                    $nb->execute([$idClub]);
                    if ((int)$nb->fetchColumn() === 0) {
                        try {
                            $ins = $pdo->prepare('INSERT INTO Club (Id_Club, Nom) VALUES (?, ?)');
                            $ins->execute([$idClub, "Information du club à saisir ($idClub)"]);
                        } catch (PDOException $ex) {
                            $erreurs[] = "Club $idClub : " . $ex->getMessage();
                            continue;
                        }
                    }
                } else {
                    $idClub = 1;
                }

                try {
                    $nb = $pdo->prepare('SELECT COUNT(*) FROM Correspondant WHERE Id_Correspondant = ?');
                    $nb->execute([$id]);
                    if ((int)$nb->fetchColumn() > 0) {
                        $pdo->prepare(
                            'UPDATE Correspondant SET Nom=?, Email=?, Telephone=?, Fonction=?, Id_Club=?
                             WHERE Id_Correspondant=?'
                        )->execute([$nom, $email, $telephone, $fonction, $idClub, $id]);
                        $updates++;
                    } else {
                        $pdo->prepare(
                            'INSERT INTO Correspondant (Id_Correspondant, Nom, Email, Telephone, Fonction, Id_Club)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        )->execute([$id, $nom, $email, $telephone, $fonction, $idClub]);
                        $inserts++;
                    }
                } catch (PDOException $ex) {
                    $erreurs[] = "Ligne id=$id : " . $ex->getMessage();
                }
            }

            $msg = "Mise à jour terminée : $inserts insérés, $updates mis à jour.";
            if ($erreurs) $msg .= ' Erreurs : ' . implode(' | ', $erreurs);
            echo json_encode(['ok' => empty($erreurs), 'msg' => $msg]);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] correspondant.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur Excel : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        ob_end_clean();
        error_log('[NIJAC] correspondant.php : ' . $e->getMessage());
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Correspondants clubs (E004)</title>

    <link rel="stylesheet" href="asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="asset/css/bootstrap-icons.min.css">

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

        /* ── Toolbar ── */
        #toolbar {
            background: #c0ffff;
            border-bottom: 1px solid #90cccc;
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
            align-items: center; gap: .35rem;
            color: #c00; font-weight: 700;
            cursor: pointer; text-decoration: underline dotted;
        }
/* ── MenuStrip ── */
        #menu-strip {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: .25rem .75rem;
            display: flex;
            align-items: center;
            gap: .25rem;
            flex-shrink: 0;
        }

        .menu-item {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .25rem .75rem;
            font-size: .85rem;
            border: 1px solid transparent;
            border-radius: 4px;
            background: none;
            cursor: pointer;
            white-space: nowrap;
            color: #212529;
        }
        .menu-item:hover { background: #e8eef7; border-color: #c8d4e8; }
        .menu-item img { width: 18px; height: 18px; object-fit: contain; }

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Barre d'état ── */
        #status-bar {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            color: #374151;
            flex-shrink: 0;
            min-height: 26px;
        }

        /* ── Grille ── */
        #grid-wrapper {
            flex: 1;
            overflow: auto;
        }

        #tbl-correspondants {
            width: 100%;
            font-size: .83rem;
            border-collapse: collapse;
            min-width: 700px;
        }

        #tbl-correspondants thead th {
            background: #e8eef7;
            border: 1px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
            font-weight: 700;
            text-align: left;
        }

        #tbl-correspondants tbody tr { border-bottom: 1px solid #e0e8f0; }
        #tbl-correspondants tbody tr:nth-child(even) { background: #f7faff; }
        #tbl-correspondants tbody tr:hover { background: #dce8f8; }
        #tbl-correspondants tbody tr.selected { background: #b8d0f0 !important; }

        #tbl-correspondants tbody td {
            border: 1px solid #e0e8f0;
            padding: 0;
        }

        /* Cellule éditable — F2 pour activer */
        .cell-inner {
            display: block;
            padding: .28rem .45rem;
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

        td.col-id, td.col-club { background: #f0f4fa; }
        td.col-id .cell-inner, td.col-club .cell-inner { color: #6b7280; font-style: italic; }

        /* ── Recherche ── */
        #search-input {
            font-size: .85rem;
            padding: .2rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            width: 250px;
        }

        /* ── En-têtes triables ── */
        #tbl-correspondants thead th { cursor: pointer; user-select: none; }
        #tbl-correspondants thead th:hover { background: #d4dff0; }
        #tbl-correspondants thead th .sort-icon { margin-left: .3rem; opacity: .4; font-size: .75rem; }
        #tbl-correspondants thead th.sort-asc  .sort-icon::after { content: '▲'; opacity: 1; }
        #tbl-correspondants thead th.sort-desc .sort-icon::after { content: '▼'; opacity: 1; }
        #tbl-correspondants thead th:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }

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

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<!-- Toolbar -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
    </span>
    <a class="ts-pwd-warning" href="changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<!-- MenuStrip -->
<div id="menu-strip">
    <button class="menu-item" id="btn-importer">
        <img src="img/Importer_32.png" alt="">Importer Excel
    </button>
    <button class="menu-item" id="btn-maj-bdd">
        <img src="img/MAJ_Database_32.png" alt="">Mettre à jour la Base de données
    </button>
    <input type="file" id="file-input" accept=".xlsx" style="display:none">
    <span style="flex:1"></span>
    <input type="search" id="search-input" placeholder="🔍 Rechercher…">
</div>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-people-fill me-2"></i>Gestion des correspondants de clubs
    <small class="opacity-75 ms-2">(E004)</small>
    <a href="admin_menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Grille (DataGridView) -->
<div id="grid-wrapper">
    <table id="tbl-correspondants">
        <thead>
            <tr>
                <th style="width:90px"  data-field="id">Licence N°<span class="sort-icon"></span></th>
                <th style="width:200px" data-field="nom">Nom<span class="sort-icon"></span></th>
                <th style="width:220px" data-field="email">Email<span class="sort-icon"></span></th>
                <th style="width:130px" data-field="telephone">Téléphone<span class="sort-icon"></span></th>
                <th style="width:90px"  data-field="id_club">N° Club<span class="sort-icon"></span></th>
                <th style="width:220px" data-field="nom_club">Nom du club<span class="sort-icon"></span></th>
            </tr>
        </thead>
        <tbody id="tbody-grille">
            <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
        </tbody>
    </table>
</div>

<!-- Barre d'état -->
<div id="status-bar">Prêt. &mdash; Cliquez sur une cellule puis appuyez sur <kbd>F2</kbd> pour modifier.</div>

<!-- Toast -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

// ── Données en mémoire (équivalent DataGridView) ──────────────────────────────
let lignes     = [];
let cellActive = null;
let sortField  = 'id_club';
let sortDir    = 'asc';
let searchTerm = '';

// ── Utilitaires ───────────────────────────────────────────────────────────────
function spinner(show) { $('#spinner').toggleClass('show', show); }

function setStatus(msg, ok = true) {
    $('#status-bar').html(msg)
        .css('color', ok ? '#374151' : '#c00');
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
    let result = term
        ? lignes.filter(l =>
            String(l.id        ?? '').toLowerCase().includes(term) ||
            String(l.nom       ?? '').toLowerCase().includes(term) ||
            String(l.email     ?? '').toLowerCase().includes(term) ||
            String(l.telephone ?? '').toLowerCase().includes(term) ||
            String(l.fonction  ?? '').toLowerCase().includes(term) ||
            String(l.id_club   ?? '').toLowerCase().includes(term) ||
            String(l.nom_club  ?? '').toLowerCase().includes(term))
        : [...lignes];

    const numFields = ['id', 'id_club'];
    result.sort((a, b) => {
        if (numFields.includes(sortField)) {
            return sortDir === 'asc' ? (+a[sortField]) - (+b[sortField]) : (+b[sortField]) - (+a[sortField]);
        }
        const va = String(a[sortField] ?? '').toLowerCase();
        const vb = String(b[sortField] ?? '').toLowerCase();
        return sortDir === 'asc' ? va.localeCompare(vb) : vb.localeCompare(va);
    });
    return result;
}

function majEnteteTri() {
    $('#tbl-correspondants thead th').each(function () {
        const f = $(this).data('field');
        $(this).removeClass('sort-asc sort-desc');
        if (f === sortField) $(this).addClass(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
    });
}

// ── Rendu de la grille ────────────────────────────────────────────────────────
function renderGrille() {
    const $body = $('#tbody-grille').empty();
    majEnteteTri();

    const affichees = lignesFiltreesTriees();

    if (!affichees.length) {
        const msg = searchTerm ? 'Aucun résultat pour cette recherche.' : 'Aucune donnée.';
        $body.append(`<tr><td colspan="5" class="text-center text-muted py-3">${msg}</td></tr>`);
        setStatus(searchTerm ? `0 résultat sur ${lignes.length} correspondant(s).` : 'Aucun correspondant.');
        return;
    }

    affichees.forEach((l) => {
        const idx = lignes.indexOf(l);
        const $tr = $('<tr>').attr('data-idx', idx);
        $tr.append(makeTd(l.id,        idx, 'id',        true));
        $tr.append(makeTd(l.nom,       idx, 'nom',       false));
        $tr.append(makeTd(l.email,     idx, 'email',     false));
        $tr.append(makeTd(l.telephone, idx, 'telephone', false));
        $tr.append(makeTd(l.id_club,   idx, 'id_club',   true));
        $tr.append(makeTd(l.nom_club,  idx, 'nom_club',  true));
        $body.append($tr);
    });

    const info = searchTerm ? `${affichees.length} résultat(s) sur ${lignes.length}` : `${lignes.length} correspondant(s)`;
    setStatus(`${info}. Cliquez sur une cellule puis <kbd>F2</kbd> pour modifier.`);
}

function makeTd(val, idx, field, readonly) {
    const $td  = $('<td>').addClass(readonly ? `col-${field}` : '').attr('data-idx', idx).attr('data-field', field);
    const $div = $('<div class="cell-inner">').text(val ?? '').attr('contenteditable', 'false');
    $td.append($div);

    if (!readonly) {
        $td.on('click', function () { selectionnerCellule($(this)); });
    }
    return $td;
}

function selectionnerCellule($td) {
    // Désélectionner l'ancienne
    if (cellActive) {
        cellActive.find('.cell-inner').attr('contenteditable', 'false').trigger('blur');
        cellActive.closest('tr').removeClass('selected');
    }
    cellActive = $td;
    $td.closest('tr').addClass('selected');
    setStatus(`Cellule sélectionnée — appuyez sur <kbd>F2</kbd> pour modifier, <kbd>Échap</kbd> pour annuler.`);
}

// ── Gestion clavier (F2 = éditer, Échap = quitter, Entrée = valider) ─────────
$(document).on('keydown', function (e) {
    if (!cellActive) return;

    const $inner = cellActive.find('.cell-inner');

    if (e.key === 'F2' && $inner.attr('contenteditable') === 'false') {
        e.preventDefault();
        $inner.attr('contenteditable', 'true').trigger('focus');
        // Place le curseur en fin de texte
        const range = document.createRange();
        range.selectNodeContents($inner[0]);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
    } else if (e.key === 'Escape') {
        // Restaurer la valeur originale
        const idx   = +cellActive.attr('data-idx');
        const field = cellActive.attr('data-field');
        $inner.text(lignes[idx]?.[field] ?? '').attr('contenteditable', 'false');
        setStatus('Modification annulée.');
    } else if (e.key === 'Enter' && $inner.attr('contenteditable') === 'true') {
        e.preventDefault();
        validerCellule($inner, cellActive);
    }
});

// Valider quand on quitte la cellule (blur)
$(document).on('blur', '.cell-inner[contenteditable="true"]', function () {
    validerCellule($(this), $(this).closest('td'));
});

function validerCellule($inner, $td) {
    $inner.attr('contenteditable', 'false');
    const idx   = +$td.attr('data-idx');
    const field = $td.attr('data-field');
    const val   = $inner.text().trim();
    if (lignes[idx]) {
        lignes[idx][field] = val !== '' ? val : null;
    }
    setStatus('Modification enregistrée localement. Cliquez sur « Mettre à jour la BDD » pour sauvegarder.');
}

// ── Charger depuis la BDD ─────────────────────────────────────────────────────
function chargerListe() {
    spinner(true);
    $.post('correspondant.php', { action: 'liste' }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        lignes = res.data.map(r => ({
            id:        r.Id_Correspondant,
            nom:       r.Nom,
            email:     r.Email,
            telephone: r.Telephone,
            fonction:  r.Fonction,
            id_club:   r.Id_Club,
            nom_club:  r.NomClub ?? '',
        }));
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
        url: 'correspondant.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success(res) {
            spinner(false);
            if (!res.ok) { toast(res.msg, false); return; }
            lignes = res.data;
            renderGrille();
            toast(`${res.count} ligne(s) importée(s) depuis Excel.`);
            setStatus(`${res.count} ligne(s) importée(s). Vérifiez les données puis cliquez sur « Mettre à jour la BDD ».`);
        },
        error() { spinner(false); toast('Erreur lors de l\'import.', false); }
    });
    this.value = ''; // Réinitialiser pour permettre re-sélection du même fichier
});

// ── Mettre à jour la BDD ──────────────────────────────────────────────────────
$('#btn-maj-bdd').on('click', function () {
    if (!lignes.length) { toast('Aucune donnée à enregistrer.', false); return; }
    if (!confirm(`Mettre à jour la base de données avec ${lignes.length} ligne(s) ?`)) return;

    spinner(true);
    $.post('correspondant.php', {
        action: 'maj_bdd',
        lignes: JSON.stringify(lignes),
    }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) chargerListe();
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Tri sur clic en-tête ──────────────────────────────────────────────────────
$('#tbl-correspondants thead th[data-field]').on('click', function () {
    const f = $(this).data('field');
    if (sortField === f) {
        sortDir = sortDir === 'asc' ? 'desc' : 'asc';
    } else {
        sortField = f;
        sortDir   = 'asc';
    }
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
</body>
</html>
