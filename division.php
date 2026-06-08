<?php
session_start();
require_once __DIR__ . '/config/db.php';

// ── Sécurité : accès admin uniquement ────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: index.php');
    exit;
}
$moi = $_SESSION['utilisateur'];

// ── Points d'API AJAX ────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $pdo = getPDO();

        // ── Liste ──────────────────────────────────────────────────────────
        if ($action === 'liste') {
            $rows = $pdo->query(
                'SELECT Id_Division, Division, Ord, Nom FROM Division ORDER BY Ord'
            )->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Charger une division ───────────────────────────────────────────
        if ($action === 'charger') {
            $id   = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT Id_Division, Division, Ord, Nom FROM Division WHERE Id_Division = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']);
            exit;
        }

        // ── Enregistrer (INSERT ou UPDATE) ─────────────────────────────────
        if ($action === 'enregistrer') {
            $id      = (int)($_POST['id']       ?? 0);
            $nom     = trim($_POST['nom']        ?? '');
            $ord     = max(1, (int)($_POST['ord'] ?? 1));
            $nomLong = trim($_POST['nom_long']   ?? '');

            if ($nom === '') {
                echo json_encode(['ok' => false, 'msg' => 'Le nom de la division ne peut pas être vide.']);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE Division SET Division=?, Ord=?, Nom=? WHERE Id_Division=?'
                );
                $stmt->execute([$nom, $ord, $nomLong, $id]);
                echo json_encode(['ok' => true, 'msg' => 'Division mise à jour.', 'id' => $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO Division (Division, Ord, Nom) VALUES (?, ?, ?)'
                );
                $stmt->execute([$nom, $ord, $nomLong]);
                echo json_encode(['ok' => true, 'msg' => 'Division créée.', 'id' => (int)$pdo->lastInsertId()]);
            }
            exit;
        }

        // ── Supprimer ──────────────────────────────────────────────────────
        if ($action === 'supprimer') {
            $id   = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM Division WHERE Id_Division = ?');
            $stmt->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'Division supprimée.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] division.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données : ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Rendu HTML ───────────────────────────────────────────────────────────────
$nomComplet  = htmlspecialchars($moi['nom'] . ' ' . $moi['prenom']);
$departement = htmlspecialchars($moi['id_departement'] ?? '');
$changeLogin = !empty($moi['change_login']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Gestion des divisions (E904)</title>

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

        /* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Layout split ── */
        #split-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* ── Panneau liste (gauche) ── */
        #panel-liste {
            width: 55%;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
        }

        #liste-header {
            background: steelblue;
            color: #fff;
            font-weight: 700;
            font-size: .85rem;
            padding: .4rem .75rem;
            flex-shrink: 0;
        }

        #table-wrapper { flex: 1; overflow-y: auto; }

        #tbl-divisions {
            width: 100%;
            font-size: .85rem;
            border-collapse: collapse;
        }

        #tbl-divisions thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        #tbl-divisions tbody tr {
            cursor: pointer;
            border-bottom: 1px solid #e0e8f0;
        }
        #tbl-divisions tbody tr:hover   { background: #dce8f8; }
        #tbl-divisions tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-divisions tbody td { padding: .3rem .5rem; }

        /* ── Panneau formulaire (droite) ── */
        #panel-form {
            flex: 1;
            padding: 1rem 1.5rem;
            overflow-y: auto;
            background: #fff;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        .form-control, .form-select { font-size: .9rem; }

        #txt-id { background: #f0f4fa; width: 100px; }

        /* ── Boutons CRUD ── */
        #panel-boutons { display: flex; gap: .6rem; margin-top: 1.5rem; }

        .btn-nouveau     { background: #fff; border: 1px solid #aaa; }
        .btn-enregistrer { background: #c6efce; border: 1px solid #82c88e; font-weight: 600; }
        .btn-supprimer   { background: #ffc7ce; border: 1px solid #e09090; font-weight: 600; }

        .btn-nouveau:hover     { background: #e8e8e8; }
        .btn-enregistrer:hover { background: #a8dfb0; }
        .btn-supprimer:hover   { background: #f0a0a8; }
        .btn-supprimer:disabled { opacity: .5; cursor: not-allowed; }

        /* ── Toast ── */
        #toast-container { position: fixed; bottom: 1rem; right: 1rem; z-index: 9999; }
    </style>
</head>
<body>

<!-- Toolbar -->
<div id="toolbar">
    <span class="ts-user">
        <i class="bi bi-person-fill me-1"></i>Utilisateur : <?= $nomComplet ?><?= $departement ? " ($departement)" : '' ?>
    </span>
    <a class="ts-pwd-warning" href="changer_mot_de_passe.php">
        <i class="bi bi-key-fill"></i>Mot de passe à modifier
    </a>
</div>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-diagram-3-fill me-2"></i>Gestion des divisions
    <small class="opacity-75 ms-2">(E904)</small>
    <a href="admin_menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Split -->
<div id="split-container">

    <!-- ── Liste ── -->
    <div id="panel-liste">
        <div id="liste-header">Divisions</div>
        <div id="table-wrapper">
            <table id="tbl-divisions">
                <thead>
                    <tr>
                        <th style="width:60px">Ord</th>
                        <th style="width:120px">Division</th>
                        <th>Nom</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="3" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Formulaire ── -->
    <div id="panel-form">

        <div class="mb-2">
            <label class="form-label">Id Division :</label>
            <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Division :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="num-ord">Ordre :</label>
            <input type="number" id="num-ord" class="form-control form-control-sm" min="1" max="300" value="1" style="width:120px">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom-long">Nom :</label>
            <input type="text" id="txt-nom-long" class="form-control form-control-sm" maxlength="255">
        </div>

        <!-- Boutons -->
        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3" id="btn-nouveau">
                <img src="img/Ajouter.png" alt="" width="18" height="18" class="me-1">Nouveau
            </button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer">
                <img src="img/MiseaJour.png" alt="" width="18" height="18" class="me-1">Enregistrer
            </button>
            <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer" disabled>
                <img src="img/Supprimer.png" alt="" width="18" height="18" class="me-1">Supprimer
            </button>
        </div>

        <!-- Statut -->
        <div id="form-status" class="mt-3 small fw-bold"></div>

    </div>
</div>

<!-- Toast notifications -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let currentId = null;

// ── Utilitaires ───────────────────────────────────────────────────────────────
function toast(msg, ok = true) {
    const id  = 'toast-' + Date.now();
    const cls = ok ? 'text-bg-success' : 'text-bg-danger';
    $('#toast-container').append(
        `<div id="${id}" class="toast align-items-center ${cls} border-0 mb-2 show" role="alert">
           <div class="d-flex">
             <div class="toast-body">${msg}</div>
             <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
           </div>
         </div>`
    );
    setTimeout(() => $(`#${id}`).remove(), 3500);
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg)
        .removeClass('text-danger text-success')
        .addClass(ok ? 'text-success' : 'text-danger');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function chargerListe(selectId = null) {
    $.post('division.php', { action: 'liste' }, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="3" class="text-center text-muted py-3">Aucune division.</td></tr>');
            return;
        }
        res.data.forEach(d => {
            const $tr = $('<tr>')
                .attr('data-id', d.Id_Division)
                .append(
                    $('<td class="text-center">').text(d.Ord),
                    $('<td>').text(d.Division),
                    $('<td>').text(d.Nom)
                )
                .on('click', function () { selectionnerLigne($(this)); });
            $body.append($tr);
        });

        if (selectId) {
            const $tr = $(`#tbody-liste tr[data-id="${selectId}"]`);
            if ($tr.length) selectionnerLigne($tr);
        }
    }, 'json');
}

function selectionnerLigne($tr) {
    $('#tbody-liste tr').removeClass('selected');
    $tr.addClass('selected');
    const id = $tr.data('id');
    $.get('division.php', { action: 'charger', id: id }, function (res) {
        if (!res.ok) return;
        const d = res.data;
        currentId = parseInt(d.Id_Division);
        $('#txt-id').val(currentId);
        $('#txt-nom').val(d.Division);
        $('#num-ord').val(d.Ord);
        $('#txt-nom-long').val(d.Nom);
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

// ── Nouveau ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-nom').val('').trigger('focus');
    $('#num-ord').val(1);
    $('#txt-nom-long').val('');
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    $.post('division.php', {
        action:   'enregistrer',
        id:       currentId ?? 0,
        nom:      $('#txt-nom').val().trim(),
        ord:      $('#num-ord').val(),
        nom_long: $('#txt-nom-long').val().trim(),
    }, function (res) {
        if (res.ok) {
            toast(res.msg);
            chargerListe(res.id);
        } else {
            toast(res.msg, false);
            setStatus(res.msg, false);
        }
    }, 'json');
});

// ── Supprimer ─────────────────────────────────────────────────────────────────
$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const nom = $('#txt-nom').val();
    if (!confirm(`Supprimer la division « ${nom} » ?`)) return;

    $.post('division.php', { action: 'supprimer', id: currentId }, function (res) {
        if (res.ok) {
            toast(res.msg);
            chargerListe();
            $('#btn-nouveau').trigger('click');
        } else {
            toast(res.msg, false);
        }
    }, 'json');
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
</body>
</html>
