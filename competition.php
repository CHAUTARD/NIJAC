<?php
/**
 * NIJAC – Gestion des types de compétition (E003)
 *
 * Permet de créer, modifier et supprimer les types de compétitions
 * (ex. Championnat par équipes, Tournoi individuel…). Chaque type définit
 * l'indemnité forfaitaire et le barème kilométrique applicable aux JA.
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-06-11
 */
session_start();
require_once __DIR__ . '/config/db.php';

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

    try {
        $pdo = getPDO();

        // ── Charger la liste ───────────────────────────────────────────────
        if ($action === 'liste') {
            $rows = $pdo->query(
                'SELECT Id_Competition, Nom, IndemniteForfaitaire, FraisKilometrique
                 FROM Competition ORDER BY Nom'
            )->fetchAll();
            ob_end_clean();
            echo json_encode(['ok' => true, 'data' => $rows]);
            exit;
        }

        // ── Charger un enregistrement ──────────────────────────────────────
        if ($action === 'lire') {
            $id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
            $stmt = $pdo->prepare(
                'SELECT Id_Competition, Nom, IndemniteForfaitaire, FraisKilometrique
                 FROM Competition WHERE Id_Competition = ?'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            ob_end_clean();
            if ($row) {
                echo json_encode(['ok' => true, 'data' => $row]);
            } else {
                echo json_encode(['ok' => false, 'msg' => 'Enregistrement introuvable.']);
            }
            exit;
        }

        // ── Sauvegarder (INSERT ou UPDATE) ─────────────────────────────────
        if ($action === 'sauvegarder') {
            $id     = (int)($_POST['id']     ?? 0);
            $nom    = trim($_POST['nom']     ?? '');
            $indem  = $_POST['indemnite']    !== '' ? (float)str_replace(',', '.', $_POST['indemnite'])    : null;
            $frais  = $_POST['frais']        !== '' ? (float)str_replace(',', '.', $_POST['frais'])        : null;

            if ($nom === '') {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Le nom est obligatoire.']);
                exit;
            }

            if ($id === 0) {
                $stmt = $pdo->prepare(
                    'INSERT INTO Competition (Nom, IndemniteForfaitaire, FraisKilometrique)
                     VALUES (?, ?, ?)'
                );
                $stmt->execute([$nom, $indem, $frais]);
                $newId = (int)$pdo->lastInsertId();
                ob_end_clean();
                echo json_encode(['ok' => true, 'msg' => 'Compétition créée.', 'id' => $newId]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE Competition SET Nom=?, IndemniteForfaitaire=?, FraisKilometrique=?
                     WHERE Id_Competition=?'
                );
                $stmt->execute([$nom, $indem, $frais, $id]);
                ob_end_clean();
                echo json_encode(['ok' => true, 'msg' => 'Compétition mise à jour.', 'id' => $id]);
            }
            exit;
        }

        // ── Supprimer ──────────────────────────────────────────────────────
        if ($action === 'supprimer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id === 0) {
                ob_end_clean();
                echo json_encode(['ok' => false, 'msg' => 'Id invalide.']);
                exit;
            }
            $pdo->prepare('DELETE FROM Competition WHERE Id_Competition = ?')->execute([$id]);
            ob_end_clean();
            echo json_encode(['ok' => true, 'msg' => 'Compétition supprimée.']);
            exit;
        }

    } catch (PDOException $e) {
        error_log('[NIJAC] competition.php PDO : ' . $e->getMessage());
        ob_end_clean();
        echo json_encode(['ok' => false, 'msg' => 'Erreur BDD : ' . $e->getMessage()]);
        exit;
    } catch (\Throwable $e) {
        error_log('[NIJAC] competition.php : ' . $e->getMessage());
        ob_end_clean();
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
    <title>NIJAC – Compétitions (E003)</title>

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

/* ── En-tête ── */
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ── Corps principal : liste + formulaire ── */
        #main-body {
            display: flex;
            flex: 1;
            overflow: hidden;
            gap: 0;
        }

        /* ── Panneau gauche : liste ── */
        #panel-liste {
            width: 320px;
            min-width: 220px;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #c8d4e8;
            background: #fff;
            flex-shrink: 0;
        }

        #panel-liste-header {
            background: #e8eef7;
            border-bottom: 1px solid #c8d4e8;
            padding: .35rem .6rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .3rem;
            flex-shrink: 0;
        }

        #panel-liste-header span {
            font-size: .8rem;
            font-weight: 700;
            color: #1a3a6b;
        }

        #btn-nouveau {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .2rem .55rem;
            font-size: .8rem;
            background: #1a3a6b;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            white-space: nowrap;
        }
        #btn-nouveau:hover { background: #254d8f; }

        #search-liste {
            width: 100%;
            font-size: .82rem;
            padding: .25rem .5rem;
            border: 1px solid #c8d4e8;
            border-radius: 4px;
            margin: .4rem .5rem;
            width: calc(100% - 1rem);
            box-sizing: border-box;
        }

        #liste-competitions {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        #liste-competitions li {
            padding: .45rem .75rem;
            cursor: pointer;
            border-bottom: 1px solid #f0f4fa;
            font-size: .85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .3rem;
            user-select: none;
        }
        #liste-competitions li:hover { background: #e8eef7; }
        #liste-competitions li.active {
            background: #1a3a6b;
            color: #fff;
        }
        #liste-competitions li.active .item-id { color: #a8c4e8; }

        #liste-competitions li .item-nom { flex: 1; }
        #liste-competitions li .item-id {
            font-size: .75rem;
            color: #9ca3af;
            font-style: italic;
        }

        #lbl-count-liste {
            font-size: .75rem;
            color: #6b7280;
            padding: .25rem .6rem;
            border-top: 1px solid #e8eef7;
            flex-shrink: 0;
        }

        /* ── Panneau droit : formulaire ── */
        #panel-form {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        #panel-form-header {
            background: #e8eef7;
            border-bottom: 1px solid #c8d4e8;
            padding: .35rem .75rem;
            font-size: .85rem;
            font-weight: 700;
            color: #1a3a6b;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        #panel-form-header .badge-id {
            background: #1a3a6b;
            color: #fff;
            border-radius: 10px;
            padding: .1rem .5rem;
            font-size: .75rem;
        }

        #form-wrapper {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }

        .form-section {
            background: #fff;
            border: 1px solid #c8d4e8;
            border-radius: 6px;
            padding: 1.25rem 1.5rem;
            max-width: 520px;
        }

        .form-label {
            font-weight: 600;
            font-size: .85rem;
            color: #374151;
            margin-bottom: .25rem;
        }

        .form-control, .form-control:focus {
            font-size: .88rem;
        }

        .input-suffix {
            background: #e8eef7;
            border-color: #c8d4e8;
            font-size: .82rem;
            color: #6b7280;
        }

        #form-actions {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: .5rem 1.5rem;
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-shrink: 0;
        }

        #status-bar {
            background: #e8eef7;
            border-top: 1px solid #c8d4e8;
            padding: .25rem 1rem;
            font-size: .8rem;
            color: #374151;
            flex-shrink: 0;
        }

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

        /* ── État vide du formulaire ── */
        #form-empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #9ca3af;
            gap: .5rem;
        }
        #form-empty i { font-size: 3rem; }
        #form-empty p  { font-size: .9rem; }
    </style>
</head>
<body>

<!-- Spinner -->
<div id="spinner">
    <div class="spinner-border text-light" style="width:3rem;height:3rem;"></div>
</div>

<?php require __DIR__ . '/includes/toolbar.php'; ?>

<!-- En-tête -->
<div id="page-header">
    <i class="bi bi-trophy-fill me-2"></i>Gestion des types de compétition
    <small class="opacity-75 ms-2">(E003)</small>
    <a href="admin_menu.php" class="btn btn-sm btn-light float-end py-0">
        <i class="bi bi-arrow-left me-1"></i>Retour menu
    </a>
</div>

<!-- Corps principal -->
<div id="main-body">

    <!-- ── Panneau gauche : liste ── -->
    <div id="panel-liste">
        <div id="panel-liste-header">
            <span><i class="bi bi-list-ul me-1"></i>Compétitions</span>
            <button id="btn-nouveau"><i class="bi bi-plus-lg"></i> Nouveau</button>
        </div>
        <input type="search" id="search-liste" placeholder="🔍 Rechercher…">
        <ul id="liste-competitions">
            <li class="text-muted" style="cursor:default;font-size:.82rem;padding:.5rem .75rem;">Chargement…</li>
        </ul>
        <div id="lbl-count-liste">—</div>
    </div>

    <!-- ── Panneau droit : formulaire ── -->
    <div id="panel-form">
        <div id="panel-form-header">
            <i class="bi bi-pencil-square"></i>
            <span id="form-titre">Sélectionnez une compétition</span>
            <span id="badge-id" class="badge-id" style="display:none"></span>
        </div>

        <div id="form-wrapper">
            <!-- État vide -->
            <div id="form-empty">
                <i class="bi bi-trophy"></i>
                <p>Sélectionnez une compétition dans la liste<br>ou cliquez sur <strong>Nouveau</strong> pour en créer une.</p>
            </div>

            <!-- Formulaire -->
            <div id="form-content" style="display:none">
                <div class="form-section">
                    <div class="mb-3">
                        <label class="form-label" for="fld-nom">Nom <span class="text-danger">*</span></label>
                        <input type="text" id="fld-nom" class="form-control" maxlength="100" placeholder="Nom de la compétition">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="fld-indemnite">Indemnité forfaitaire</label>
                        <div class="input-group">
                            <input type="number" id="fld-indemnite" class="form-control" step="0.01" min="0" placeholder="0.00">
                            <span class="input-group-text input-suffix">€</span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="fld-frais">Frais kilométriques</label>
                        <div class="input-group">
                            <input type="number" id="fld-frais" class="form-control" step="0.01" min="0" placeholder="0.00">
                            <span class="input-group-text input-suffix">€ / km</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Barre d'actions -->
        <div id="form-actions">
            <button class="btn btn-primary btn-sm" id="btn-sauvegarder" disabled>
                <i class="bi bi-floppy me-1"></i>Enregistrer
            </button>
            <button class="btn btn-outline-secondary btn-sm" id="btn-annuler" disabled>
                <i class="bi bi-x-circle me-1"></i>Annuler
            </button>
            <div class="flex-grow-1"></div>
            <button class="btn btn-outline-danger btn-sm" id="btn-supprimer" disabled>
                <i class="bi bi-trash me-1"></i>Supprimer
            </button>
        </div>
    </div>
</div>

<!-- Barre d'état -->
<div id="status-bar">Prêt.</div>

<!-- Toast -->
<div id="toast-container"></div>

<script src="asset/js/jquery-3.7.1.min.js"></script>
<script src="asset/js/bootstrap.bundle.min.js"></script>
<script>
'use strict';

let competitions  = [];   // liste complète
let currentId     = null; // Id sélectionné (null = nouveau)
let isDirty       = false; // modifications non sauvegardées

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

function confirmerAbandon() {
    if (!isDirty) return true;
    return confirm('Des modifications non enregistrées seront perdues. Continuer ?');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function chargerListe(selectId = null) {
    spinner(true);
    $.post('competition.php', { action: 'liste' }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        competitions = res.data;
        renderListe();
        if (selectId !== null) {
            const item = competitions.find(c => +c.Id_Competition === +selectId);
            if (item) selectionnerItem(item);
        }
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
}

function renderListe() {
    const term  = $('#search-liste').val().trim().toLowerCase();
    const $ul   = $('#liste-competitions').empty();
    const liste = term
        ? competitions.filter(c => (c.Nom ?? '').toLowerCase().includes(term))
        : competitions;

    if (!liste.length) {
        $ul.append('<li style="cursor:default;font-size:.82rem;color:#9ca3af;">Aucun résultat.</li>');
    } else {
        liste.forEach(c => {
            const $li = $('<li>')
                .attr('data-id', c.Id_Competition)
                .toggleClass('active', +c.Id_Competition === +currentId);
            $li.append(`<span class="item-nom">${c.Nom ?? '—'}</span>`);
            $li.append(`<span class="item-id">#${c.Id_Competition}</span>`);
            $li.on('click', function () {
                if (!confirmerAbandon()) return;
                selectionnerItem(c);
            });
            $ul.append($li);
        });
    }

    const n = competitions.length;
    $('#lbl-count-liste').text(`${n} compétition${n > 1 ? 's' : ''}`);
}

// ── Formulaire ────────────────────────────────────────────────────────────────
function afficherFormulaire(show) {
    $('#form-empty').toggle(!show);
    $('#form-content').toggle(show);
    $('#btn-sauvegarder, #btn-annuler').prop('disabled', !show);
}

function selectionnerItem(c) {
    currentId = c.Id_Competition;
    isDirty   = false;

    $('#fld-nom').val(c.Nom ?? '');
    $('#fld-indemnite').val(c.IndemniteForfaitaire ?? '');
    $('#fld-frais').val(c.FraisKilometrique ?? '');

    $('#form-titre').text(c.Nom ?? 'Compétition');
    $('#badge-id').text(`#${c.Id_Competition}`).show();
    $('#btn-supprimer').prop('disabled', false);

    afficherFormulaire(true);
    renderListe();
    setStatus(`Compétition #${c.Id_Competition} — ${c.Nom}`);
    $('#fld-nom').trigger('focus');
}

function nouveauFormulaire() {
    currentId = null;
    isDirty   = false;

    $('#fld-nom').val('');
    $('#fld-indemnite').val('');
    $('#fld-frais').val('');

    $('#form-titre').text('Nouvelle compétition');
    $('#badge-id').hide();
    $('#btn-supprimer').prop('disabled', true);

    afficherFormulaire(true);
    renderListe();
    setStatus('Nouveau — remplissez le formulaire puis cliquez sur Enregistrer.');
    $('#fld-nom').trigger('focus');
}

// ── Détection modifications ───────────────────────────────────────────────────
$('#form-content').on('input change', 'input', function () {
    isDirty = true;
    setStatus('Modifications en cours…');
});

// ── Boutons ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    if (!confirmerAbandon()) return;
    nouveauFormulaire();
});

$('#btn-annuler').on('click', function () {
    if (!confirmerAbandon()) return;
    if (currentId !== null) {
        const c = competitions.find(c => +c.Id_Competition === +currentId);
        if (c) { selectionnerItem(c); return; }
    }
    // Retour à l'état vide
    currentId = null;
    isDirty   = false;
    afficherFormulaire(false);
    $('#form-titre').text('Sélectionnez une compétition');
    $('#badge-id').hide();
    $('#btn-supprimer').prop('disabled', true);
    renderListe();
    setStatus('Annulé.');
});

$('#btn-sauvegarder').on('click', function () {
    const nom   = $('#fld-nom').val().trim();
    if (nom === '') {
        toast('Le nom est obligatoire.', false);
        $('#fld-nom').trigger('focus');
        return;
    }

    spinner(true);
    $.post('competition.php', {
        action:    'sauvegarder',
        id:        currentId ?? 0,
        nom:       nom,
        indemnite: $('#fld-indemnite').val().trim(),
        frais:     $('#fld-frais').val().trim(),
    }, function (res) {
        spinner(false);
        if (!res.ok) { toast(res.msg, false); return; }
        isDirty = false;
        toast(res.msg);
        chargerListe(res.id);
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

$('#btn-supprimer').on('click', function () {
    if (currentId === null) return;
    const c = competitions.find(c => +c.Id_Competition === +currentId);
    if (!confirm(`Supprimer la compétition « ${c?.Nom ?? ''} » ?\n\nCette opération est irréversible.`)) return;

    spinner(true);
    $.post('competition.php', { action: 'supprimer', id: currentId }, function (res) {
        spinner(false);
        toast(res.msg, res.ok);
        if (res.ok) {
            currentId = null;
            isDirty   = false;
            afficherFormulaire(false);
            $('#form-titre').text('Sélectionnez une compétition');
            $('#badge-id').hide();
            $('#btn-supprimer').prop('disabled', true);
            chargerListe();
        }
    }, 'json').fail(() => { spinner(false); toast('Erreur réseau.', false); });
});

// ── Recherche dans la liste ───────────────────────────────────────────────────
$('#search-liste').on('input', function () { renderListe(); });

// ── Raccourci Ctrl+S ──────────────────────────────────────────────────────────
$(document).on('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        if (!$('#btn-sauvegarder').prop('disabled')) $('#btn-sauvegarder').trigger('click');
    }
    if (e.key === 'Escape') {
        if (!$('#btn-annuler').prop('disabled')) $('#btn-annuler').trigger('click');
    }
});

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () { chargerListe(); });
</script>
</body>
</html>
