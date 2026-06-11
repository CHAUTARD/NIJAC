<?php
/**
 * NIJAC – Gestion des utilisateurs (E009)
 *
 * Création et gestion des comptes utilisateurs de l'application.
 * Chaque compte possède un login, un mot de passe (haché), un rôle
 * (Administrateur ou Nominateur), un département et un état actif/inactif.
 * Permet également de forcer le changement de mot de passe à la prochaine connexion.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/Classes/SecurePasswordHasher.php';

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
                'SELECT Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin
                 FROM Utilisateur ORDER BY Nom, Prenom'
            )->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Charger un utilisateur ─────────────────────────────────────────
        if ($action === 'charger') {
            $id   = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT Id_Utilisateur, Login, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin
                 FROM Utilisateur WHERE Id_Utilisateur = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']);
            exit;
        }

        // ── Enregistrer (INSERT ou UPDATE) ─────────────────────────────────
        if ($action === 'enregistrer') {
            $id      = (int)($_POST['id']      ?? 0);
            $login   = trim($_POST['login']    ?? '');
            $nom     = trim($_POST['nom']      ?? '');
            $prenom  = trim($_POST['prenom']   ?? '');
            $role    = trim($_POST['role']     ?? '');
            $dept    = (int)($_POST['dept']    ?? 0);
            $mdp     = $_POST['mdp']           ?? '';
            $actif   = ($_POST['actif']        ?? '0') === '1' ? 1 : 0;
            $chgLogin= ($_POST['change_login'] ?? '0') === '1' ? 1 : 0;

            if ($login === '') {
                echo json_encode(['ok' => false, 'msg' => 'Le login ne peut pas être vide.']);
                exit;
            }
            if ($id === 0 && $mdp === '') {
                echo json_encode(['ok' => false, 'msg' => 'Un mot de passe est obligatoire pour un nouvel utilisateur.']);
                exit;
            }
            if (!in_array($role, ['Administrateur', 'Nominateur', 'Consultation'], true)) {
                echo json_encode(['ok' => false, 'msg' => 'Rôle invalide.']);
                exit;
            }

            $hashMdp = $mdp !== '' ? SecurePasswordHasher::hash($mdp) : null;

            if ($id > 0) {
                // UPDATE
                if ($hashMdp !== null) {
                    $stmt = $pdo->prepare(
                        'UPDATE Utilisateur SET Login=?, Password=?, Nom=?, Prenom=?, Role=?,
                         Id_Departement=?, Actif=?, ChangeLogin=? WHERE Id_Utilisateur=?'
                    );
                    $stmt->execute([$login, $hashMdp, $nom, $prenom, $role, $dept, $actif, $chgLogin, $id]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE Utilisateur SET Login=?, Nom=?, Prenom=?, Role=?,
                         Id_Departement=?, Actif=?, ChangeLogin=? WHERE Id_Utilisateur=?'
                    );
                    $stmt->execute([$login, $nom, $prenom, $role, $dept, $actif, $chgLogin, $id]);
                }
                echo json_encode(['ok' => true, 'msg' => 'Utilisateur mis à jour.', 'id' => $id]);
            } else {
                // INSERT
                $stmt = $pdo->prepare(
                    'INSERT INTO Utilisateur (Login, Password, Nom, Prenom, Role, Id_Departement, Actif, ChangeLogin)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$login, $hashMdp, $nom, $prenom, $role, $dept, $actif, $chgLogin]);
                echo json_encode(['ok' => true, 'msg' => 'Utilisateur créé.', 'id' => (int)$pdo->lastInsertId()]);
            }
            exit;
        }

        // ── Supprimer ──────────────────────────────────────────────────────
        if ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === (int)$moi['id']) {
                echo json_encode(['ok' => false, 'msg' => 'Vous ne pouvez pas supprimer votre propre compte.']);
                exit;
            }
            $stmt = $pdo->prepare('DELETE FROM Utilisateur WHERE Id_Utilisateur = ?');
            $stmt->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'Utilisateur supprimé.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] utilisateur.php PDO : ' . $e->getMessage());
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
    <title>NIJAC – Gestion des utilisateurs (E009)</title>

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
        #toolbar .ts-screen-id {
            font-size: .78rem; font-weight: 700;
            color: #1a3a6b; background: #ddeeff;
            padding: .1rem .45rem; border-radius: 4px;
            border: 1px solid #99bbdd; letter-spacing: .03em;
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
            width: 54%;
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

        #table-wrapper {
            flex: 1;
            overflow-y: auto;
        }

        #tbl-utilisateurs {
            width: 100%;
            font-size: .85rem;
            border-collapse: collapse;
        }

        #tbl-utilisateurs thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        #tbl-utilisateurs tbody tr {
            cursor: pointer;
            border-bottom: 1px solid #e0e8f0;
        }

        #tbl-utilisateurs tbody tr:hover { background: #dce8f8; }
        #tbl-utilisateurs tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-utilisateurs tbody td { padding: .3rem .5rem; }
        #tbl-utilisateurs tbody tr.inactif td { color: #bbb; }

        /* ── Panneau formulaire (droite) ── */
        #panel-form {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            background: #fff;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        .form-control, .form-select {
            font-size: .9rem;
        }

        #txt-id {
            background: #f0f4fa;
            width: 80px;
        }

        /* ── Boutons CRUD ── */
        #panel-boutons {
            display: flex;
            gap: .6rem;
            margin-top: 1.25rem;
        }

        .btn-nouveau      { background:#fff; border:1px solid #aaa; }
        .btn-enregistrer  { background:#c6efce; border:1px solid #82c88e; font-weight:600; }
        .btn-supprimer    { background:#ffc7ce; border:1px solid #e09090; font-weight:600; }

        .btn-nouveau:hover     { background:#e8e8e8; }
        .btn-enregistrer:hover { background:#a8dfb0; }
        .btn-supprimer:hover   { background:#f0a0a8; }

        .btn-supprimer:disabled { opacity:.5; cursor:not-allowed; }

        /* ── Toast ── */
        #toast-container {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            z-index: 9999;
        }
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
    <i class="bi bi-people-fill me-2"></i>Gestion des utilisateurs
    <small class="opacity-75 ms-2">(E009)</small>
    <a href="admin_menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Split -->
<div id="split-container">

    <!-- ── Liste ── -->
    <div id="panel-liste">
        <div id="liste-header">Utilisateurs</div>
        <div id="table-wrapper">
            <table id="tbl-utilisateurs">
                <thead>
                    <tr>
                        <th>Login</th>
                        <th>Nom</th>
                        <th>Prénom</th>
                        <th>Rôle</th>
                        <th>Dept</th>
                        <th>Actif</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="6" class="text-center text-muted py-3">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Formulaire ── -->
    <div id="panel-form">

        <div class="row g-2 mb-2">
            <div class="col-auto">
                <label class="form-label">Id :</label>
                <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
            </div>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-login">Login :</label>
            <input type="text" id="txt-login" class="form-control form-control-sm" maxlength="50" autocomplete="off">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-nom">Nom :</label>
            <input type="text" id="txt-nom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-prenom">Prénom :</label>
            <input type="text" id="txt-prenom" class="form-control form-control-sm" maxlength="100">
        </div>

        <div class="mb-2">
            <label class="form-label" for="cbo-role">Rôle :</label>
            <select id="cbo-role" class="form-select form-select-sm">
                <option value="Administrateur">Administrateur</option>
                <option value="Nominateur" selected>Nominateur</option>
                <option value="Consultation">Consultation</option>
            </select>
        </div>

        <div class="mb-2">
            <label class="form-label" for="num-dept">Département :</label>
            <input type="number" id="num-dept" class="form-control form-control-sm" min="0" max="999" value="0" style="width:100px">
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-mdp">
                Mot de passe :
                <small class="fw-normal text-muted" id="mdp-hint">(laisser vide = inchangé)</small>
            </label>
            <input type="password" id="txt-mdp" class="form-control form-control-sm" maxlength="100" autocomplete="new-password" style="max-width:320px">
        </div>

        <div class="mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-actif" checked>
                <label class="form-check-label fw-bold" for="chk-actif">Actif</label>
            </div>
        </div>

        <div class="mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="chk-change-login" checked>
                <label class="form-check-label" for="chk-change-login">Forcer changement de mot de passe</label>
            </div>
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

    </div><!-- /panel-form -->
</div><!-- /split-container -->

<!-- Toast notifications -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

const MOI_ID = <?= (int)$moi['id'] ?>;
let   currentId = null; // null = nouvel utilisateur

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
    setTimeout(() => { $(`#${id}`).remove(); }, 3500);
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function chargerListe(selectId = null) {
    $.post('utilisateur.php', { action: 'liste' }, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="6" class="text-center text-muted py-3">Aucun utilisateur.</td></tr>');
            return;
        }
        res.data.forEach(u => {
            const actif = parseInt(u.Actif) === 1;
            const $tr = $('<tr>')
                .toggleClass('inactif', !actif)
                .attr('data-id', u.Id_Utilisateur)
                .append(
                    $('<td>').text(u.Login),
                    $('<td>').text(u.Nom),
                    $('<td>').text(u.Prenom),
                    $('<td>').text(u.Role),
                    $('<td class="text-center">').text(u.Id_Departement),
                    $('<td class="text-center">').text(actif ? '✔' : '')
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
    $.get('utilisateur.php', { action: 'charger', id: id }, function (res) {
        if (!res.ok) return;
        const u = res.data;
        currentId = parseInt(u.Id_Utilisateur);
        $('#txt-id').val(currentId);
        $('#txt-login').val(u.Login);
        $('#txt-nom').val(u.Nom);
        $('#txt-prenom').val(u.Prenom);
        $('#cbo-role').val(u.Role);
        $('#num-dept').val(u.Id_Departement);
        $('#txt-mdp').val('');
        $('#chk-actif').prop('checked', parseInt(u.Actif) === 1);
        $('#chk-change-login').prop('checked', parseInt(u.ChangeLogin) === 1);
        $('#mdp-hint').show();
        $('#btn-supprimer').prop('disabled', currentId === MOI_ID);
        setStatus('');
    }, 'json');
}

// ── Nouveau ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#txt-login').val('').trigger('focus');
    $('#txt-nom').val('');
    $('#txt-prenom').val('');
    $('#cbo-role').val('Utilisateur');
    $('#num-dept').val(0);
    $('#txt-mdp').val('');
    $('#chk-actif').prop('checked', true);
    $('#chk-change-login').prop('checked', true);
    $('#mdp-hint').hide();
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    const data = {
        action:       'enregistrer',
        id:           currentId ?? 0,
        login:        $('#txt-login').val().trim(),
        nom:          $('#txt-nom').val().trim(),
        prenom:       $('#txt-prenom').val().trim(),
        role:         $('#cbo-role').val(),
        dept:         $('#num-dept').val(),
        mdp:          $('#txt-mdp').val(),
        actif:        $('#chk-actif').is(':checked') ? '1' : '0',
        change_login: $('#chk-change-login').is(':checked') ? '1' : '0',
    };
    $.post('utilisateur.php', data, function (res) {
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
    const login = $('#txt-login').val();
    const nom   = $('#txt-nom').val() + ' ' + $('#txt-prenom').val();
    if (!confirm(`Supprimer l'utilisateur « ${login} » (${nom.trim()}) ?`)) return;

    $.post('utilisateur.php', { action: 'supprimer', id: currentId }, function (res) {
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
$(function () {
    chargerListe();
});
</script>
</body>
</html>
