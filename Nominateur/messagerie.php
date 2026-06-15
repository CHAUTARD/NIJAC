<?php
/**
 * NIJAC – Gestion des messages (E010)
 *
 * Création et gestion des modèles de messages de l'application.
 * Chaque message possède un type, un sujet, un corps de message
 * et est associé à un utilisateur.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-14
 */
session_start();
require_once __DIR__ . '/../config/db.php';

// ── Sécurité : accès admin uniquement ────────────────────────────────────────
if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur']['is_admin'])) {
    header('Location: ../index.php');
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
                'SELECT m.Id_Messagerie, m.Id_Utilisateur, m.Type, m.Sujet, m.Message,
                        CONCAT(u.Nom, \' \', u.Prenom) AS NomUtilisateur
                 FROM messagerie m
                 LEFT JOIN Utilisateur u ON u.Id_Utilisateur = m.Id_Utilisateur
                 ORDER BY m.Type, m.Sujet'
            )->fetchAll();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Charger un message ─────────────────────────────────────────────
        if ($action === 'charger') {
            $id   = (int)($_GET['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT Id_Messagerie, Id_Utilisateur, Type, Sujet, Message
                 FROM messagerie WHERE Id_Messagerie = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            echo json_encode($row ? ['ok' => true, 'data' => $row] : ['ok' => false, 'msg' => 'Introuvable']);
            exit;
        }

        // ── Enregistrer (INSERT ou UPDATE) ─────────────────────────────────
        if ($action === 'enregistrer') {
            $id      = (int)($_POST['id']  ?? 0);
            $idUser  = (int)$moi['id'];
            $type    = trim($_POST['type'] ?? '');
            $sujet   = trim($_POST['sujet']          ?? '');
            $message = trim($_POST['message']        ?? '');

            // Lire les valeurs ENUM autorisées directement depuis la BDD
            $colEnum = $pdo->query("SHOW COLUMNS FROM messagerie WHERE Field = 'Type'")->fetch();
            $typesValides = [];
            if ($colEnum && preg_match("/^enum\((.+)\)$/i", $colEnum['Type'], $em)) {
                foreach (str_getcsv($em[1], ',', "'") as $ev) {
                    $typesValides[] = trim($ev);
                }
            }

            if ($sujet === '') {
                echo json_encode(['ok' => false, 'msg' => 'Le sujet ne peut pas être vide.']);
                exit;
            }
            if ($message === '') {
                echo json_encode(['ok' => false, 'msg' => 'Le message ne peut pas être vide.']);
                exit;
            }
            if (!in_array($type, $typesValides, true)) {
                echo json_encode(['ok' => false, 'msg' => 'Type invalide.']);
                exit;
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE messagerie SET Id_Utilisateur=?, Type=?, Sujet=?, Message=?
                     WHERE Id_Messagerie=?'
                );
                $stmt->execute([$idUser, $type, $sujet, $message, $id]);
                echo json_encode(['ok' => true, 'msg' => 'Message mis à jour.', 'id' => $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO messagerie (Id_Utilisateur, Type, Sujet, Message)
                     VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$idUser, $type, $sujet, $message]);
                echo json_encode(['ok' => true, 'msg' => 'Message créé.', 'id' => (int)$pdo->lastInsertId()]);
            }
            exit;
        }

        // ── Supprimer ──────────────────────────────────────────────────────
        if ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('DELETE FROM messagerie WHERE Id_Messagerie = ?');
            $stmt->execute([$id]);
            echo json_encode(['ok' => true, 'msg' => 'Message supprimé.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] messagerie.php PDO : ' . $e->getMessage());
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

// ── Lire les valeurs ENUM du champ Type ──────────────────────────────────────
$enumTypes = [];
$col = getPDO()->query("SHOW COLUMNS FROM messagerie WHERE Field = 'Type'")->fetch();
if ($col && preg_match("/^enum\((.+)\)$/i", $col['Type'], $m)) {
    foreach (str_getcsv($m[1], ',', "'") as $v) {
        $enumTypes[] = trim($v);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NIJAC – Gestion des messages (E010)</title>

    <link rel="stylesheet" href="../asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../asset/css/nijac.css">

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

        /* ── Layout split ── */
        #split-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* ── Panneau liste (gauche) ── */
        #panel-liste {
            width: 44%;
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

        #tbl-messagerie {
            width: 100%;
            font-size: .85rem;
            border-collapse: collapse;
        }

        #tbl-messagerie thead th {
            background: #e8eef7;
            border-bottom: 2px solid #c8d4e8;
            padding: .35rem .5rem;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        #tbl-messagerie tbody tr {
            cursor: pointer;
            border-bottom: 1px solid #e0e8f0;
        }

        #tbl-messagerie tbody tr:hover    { background: #dce8f8; }
        #tbl-messagerie tbody tr.selected { background: #b8d0f0 !important; }
        #tbl-messagerie tbody td          { padding: .3rem .5rem; }

        /* ── Panneau formulaire (droite) ── */
        #panel-form {
            flex: 1;
            padding: 1rem 1.25rem;
            overflow-y: auto;
            background: #fff;
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-size: .82rem;
            font-weight: 700;
            color: #374151;
            margin-bottom: .2rem;
        }

        .form-control, .form-select { font-size: .9rem; }

        #txt-id { background: #f0f4fa; width: 80px; }

        #txt-message {
            resize: vertical;
            min-height: 220px;
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

        /* ── Marqueurs cliquables ── */
        #cartouche-marqueurs code {
            display: inline-block;
            background: #e8eef7;
            border: 1px solid #b8cce4;
            border-radius: 3px;
            padding: .05rem .3rem;
            font-size: .76rem;
            cursor: pointer;
            user-select: none;
            transition: background .12s, transform .1s;
        }

        #cartouche-marqueurs code:hover {
            background: #cfe0f8;
            border-color: #7aaddf;
            transform: scale(1.06);
        }

        #cartouche-marqueurs code:active {
            transform: scale(.95);
        }

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

<?php require __DIR__ . '/../includes/toolbar.php'; ?>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-envelope-fill me-2"></i>Gestion des messages
    <small class="opacity-75 ms-2">(E010)</small>
    <a href="menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Split -->
<div id="split-container">

    <!-- ── Liste ── -->
    <div id="panel-liste">
        <div id="liste-header">Messages</div>
        <div id="table-wrapper">
            <table id="tbl-messagerie">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Sujet</th>
                        <th>Utilisateur</th>
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

        <div class="d-flex align-items-center gap-2 mb-2">
            <label class="form-label mb-0">Id :</label>
            <input type="text" id="txt-id" class="form-control form-control-sm" readonly tabindex="-1">
        </div>

        <div class="mb-2">
            <label class="form-label" for="cbo-type">Type :</label>
            <select id="cbo-type" class="form-select form-select-sm" style="max-width:280px">
                <?php foreach ($enumTypes as $v): ?>
                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-2">
            <label class="form-label" for="txt-sujet">Sujet :</label>
            <input type="text" id="txt-sujet" class="form-control form-control-sm" maxlength="150" autocomplete="off">
        </div>

        <div class="mb-2 flex-grow-1 d-flex flex-column">
            <label class="form-label" for="txt-message">Message :</label>
            <textarea id="txt-message" class="form-control form-control-sm flex-grow-1"></textarea>
        </div>

        <!-- Boutons -->
        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3" id="btn-nouveau">
                <i class="bi bi-plus-circle me-1"></i>Nouveau
            </button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer">
                <i class="bi bi-floppy me-1"></i>Enregistrer
            </button>
            <button class="btn btn-sm btn-supprimer px-3" id="btn-supprimer" disabled>
                <i class="bi bi-trash3 me-1"></i>Supprimer
            </button>
        </div>

        <!-- Cartouche des marqueurs disponibles -->
        <div id="cartouche-marqueurs" class="mt-3 border rounded p-2" style="background:#f8f9fa;font-size:.78rem;">
            <div class="fw-bold text-secondary mb-1" style="font-size:.75rem;letter-spacing:.04em;text-transform:uppercase;">
                <i class="bi bi-braces me-1"></i>Marqueurs disponibles
            </div>
            <div class="mb-1">
                <span class="badge bg-secondary me-1 fw-normal">Tous types</span>
                <code data-marqueur="{PRENOM}" class="me-2">{PRENOM}</code>
                <code data-marqueur="{NOM}" class="me-2">{NOM}</code>
                <code data-marqueur="{NOM_COMPLET}" class="me-2">{NOM_COMPLET}</code>
                <code data-marqueur="{ID_JA}" class="me-2">{ID_JA}</code>
                <code data-marqueur="{UTI_NOM}" class="me-2">{UTI_NOM}</code>
                <code data-marqueur="{UTI_PRENOM}" class="me-2">{UTI_PRENOM}</code>
            </div>
            <div class="mb-1">
                <span class="badge me-1 fw-normal" style="background:#1a7f4b;">Convocation</span>
                <code data-marqueur="{DATE}" class="me-2">{DATE}</code>
                <code data-marqueur="{HEURE}" class="me-2">{HEURE}</code>
                <code data-marqueur="{JOURNEE}" class="me-2">{JOURNEE}</code>
                <code data-marqueur="{POULE}" class="me-2">{POULE}</code>
                <code data-marqueur="{DIVISION}" class="me-2">{DIVISION}</code>
                <code data-marqueur="{DOM}" class="me-2">{DOM}</code>
                <code data-marqueur="{EXT}" class="me-2">{EXT}</code>
                <code data-marqueur="{SALLE_NOM}" class="me-2">{SALLE_NOM}</code>
                <code data-marqueur="{SALLE_ADRESSE}" class="me-2">{SALLE_ADRESSE}</code>
                <code data-marqueur="{SALLE_CP}" class="me-2">{SALLE_CP}</code>
                <code data-marqueur="{SALLE_VILLE}" class="me-2">{SALLE_VILLE}</code>
                <code data-marqueur="{CORR_NOM}" class="me-2">{CORR_NOM}</code>
                <code data-marqueur="{CORR_EMAIL}" class="me-2">{CORR_EMAIL}</code>
                <code data-marqueur="{CORR_TEL}" class="me-2">{CORR_TEL}</code>
            </div>
            <div>
                <span class="badge me-1 fw-normal" style="background:#6f42c1;">Liste nomination</span>
                <code data-marqueur="{LISTE_NOMINATIONS}" class="me-2">{LISTE_NOMINATIONS}</code>
            </div>
        </div>

        <!-- Statut -->
        <div id="form-status" class="mt-2 small fw-bold"></div>

    </div><!-- /panel-form -->
</div><!-- /split-container -->

<!-- Toast notifications -->
<div id="toast-container"></div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/bootstrap.bundle.min.js"></script>
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
    setTimeout(() => { $(`#${id}`).remove(); }, 3500);
}

function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function chargerListe(selectId = null) {
    $.post('messagerie.php', { action: 'liste' }, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="3" class="text-center text-muted py-3">Aucun message.</td></tr>');
            return;
        }
        res.data.forEach(m => {
            const $tr = $('<tr>')
                .attr('data-id', m.Id_Messagerie)
                .append(
                    $('<td>').text(m.Type),
                    $('<td>').text(m.Sujet),
                    $('<td>').text(m.NomUtilisateur ?? '')
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
    $.get('messagerie.php', { action: 'charger', id: id }, function (res) {
        if (!res.ok) return;
        const m = res.data;
        currentId = parseInt(m.Id_Messagerie);
        $('#txt-id').val(currentId);
        $('#cbo-type').val(m.Type);
        $('#txt-sujet').val(m.Sujet);
        $('#txt-message').val(m.Message);
        $('#btn-supprimer').prop('disabled', false);
        setStatus('');
    }, 'json');
}

// ── Nouveau ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    currentId = null;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#cbo-type').val('Disponibilites');
    $('#txt-sujet').val('').trigger('focus');
    $('#txt-message').val('');
    $('#btn-supprimer').prop('disabled', true);
    setStatus('');
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    const data = {
        action: 'enregistrer',
        id:     currentId ?? 0,
        type:   $('#cbo-type').val(),
        sujet:          $('#txt-sujet').val().trim(),
        message:        $('#txt-message').val().trim(),
    };
    $.post('messagerie.php', data, function (res) {
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
    const sujet = $('#txt-sujet').val();
    if (!confirm(`Supprimer le message « ${sujet} » ?`)) return;

    $.post('messagerie.php', { action: 'supprimer', id: currentId }, function (res) {
        if (res.ok) {
            toast(res.msg);
            chargerListe();
            $('#btn-nouveau').trigger('click');
        } else {
            toast(res.msg, false);
        }
    }, 'json');
});

// ── Clic sur un marqueur : insérer à la position du curseur ──────────────────
$(document).on('click', '[data-marqueur]', function () {
    const ta     = document.getElementById('txt-message');
    const texte  = $(this).data('marqueur');
    const debut  = ta.selectionStart ?? ta.value.length;
    const fin    = ta.selectionEnd   ?? debut;
    ta.setRangeText(texte, debut, fin, 'end');
    ta.focus();
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    chargerListe();
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
