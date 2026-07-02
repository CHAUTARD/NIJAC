<?php
/**
 * NIJAC – Désidératas club (E023)
 *
 * Page publique (sans authentification) permettant à un club de renseigner,
 * pour la saison en cours, les coordonnées de son correspondant, sa salle
 * et les désidératas de ses équipes (de la Pré-Nationale à la R4) :
 * réengagement, jour de rencontre souhaité, souhait de désignation JA
 * (CRA ou Club) pour les équipes R3M/R4M.
 * Remplace le questionnaire Excel envoyé par mail aux clubs.
 * Accessible via un lien envoyé depuis E027 (?club=<Id_Club>).
 *
 * Créé par : Patrick CHAUTARD
 * Date de création : 2026-07-02
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/app_config.php';

$pdo = getPDO();
try { initTableConfiguration($pdo); } catch (\Throwable $ignored) {}

$idClubGet = trim($_GET['club'] ?? '');

// ── ACTIONS AJAX ──────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        // ── Charger les données du club ─────────────────────────────────────
        if ($action === 'charger') {
            $club = trim($_GET['club'] ?? '');
            if ($club === '') { echo json_encode(['ok' => false, 'msg' => 'Club manquant.']); exit; }

            $stmt = $pdo->prepare(
                'SELECT Id_Club, Nom, CorNom, CorEmail, CorTelephone, NbAiresJeu, DesiderataNote, DesiderataDate
                 FROM club WHERE Id_Club = ?'
            );
            $stmt->execute([$club]);
            $c = $stmt->fetch();
            if (!$c) { echo json_encode(['ok' => false, 'msg' => 'Club introuvable.']); exit; }

            $stmtS = $pdo->prepare('SELECT Nom, Adresse, Telephone FROM salle WHERE Id_Club = ? AND EstPrincipale = 1 LIMIT 1');
            $stmtS->execute([$club]);
            $salle = $stmtS->fetch() ?: ['Nom' => '', 'Adresse' => '', 'Telephone' => ''];

            $stmtE = $pdo->prepare(
                "SELECT e.Id_Equipe, e.Nom AS NomEquipe, e.ReEngagement, e.JourSouhaite, e.SouhaitJA,
                        d.Id_Division, d.Division, d.Nom AS NomDivision
                 FROM equipe e
                 JOIN division d ON d.Id_Division = e.Id_Division
                 WHERE e.Id_Club = ? AND d.Ord BETWEEN 70 AND 150
                 ORDER BY d.Ord, e.Nom"
            );
            $stmtE->execute([$club]);
            $equipes = $stmtE->fetchAll();

            echo json_encode(['ok' => true, 'club' => $c, 'salle' => $salle, 'equipes' => $equipes, 'saison' => getConfig('saison', '')]);
            exit;
        }

        // ── Enregistrer le formulaire ────────────────────────────────────────
        if ($action === 'enregistrer') {
            csrfVerify(true);

            $club = trim($_POST['club'] ?? '');
            if ($club === '') { echo json_encode(['ok' => false, 'msg' => 'Club manquant.']); exit; }

            $chk = $pdo->prepare('SELECT COUNT(*) FROM club WHERE Id_Club = ?');
            $chk->execute([$club]);
            if (!$chk->fetchColumn()) { echo json_encode(['ok' => false, 'msg' => 'Club introuvable.']); exit; }

            $corNom   = trim($_POST['cor_nom']   ?? '') ?: null;
            $corEmail = trim($_POST['cor_email'] ?? '') ?: null;
            $corTel   = trim($_POST['cor_tel']   ?? '') ?: null;
            $nbAires  = ($_POST['nb_aires'] ?? '') !== '' ? max(0, (int)$_POST['nb_aires']) : null;
            $note     = trim($_POST['note'] ?? '') ?: null;
            $saison   = getConfig('saison', '');

            $pdo->prepare(
                'UPDATE club SET CorNom=?, CorEmail=?, CorTelephone=?, NbAiresJeu=?, DesiderataNote=?, DesiderataSaison=?, DesiderataDate=NOW()
                 WHERE Id_Club=?'
            )->execute([$corNom, $corEmail, $corTel, $nbAires, $note, $saison, $club]);

            $salleNom = trim($_POST['salle_nom']     ?? '') ?: null;
            $salleAdr = trim($_POST['salle_adresse'] ?? '') ?: null;
            $salleTel = trim($_POST['salle_tel']     ?? '') ?: null;

            $stmtSalleChk = $pdo->prepare('SELECT Id_Salle FROM salle WHERE Id_Club=? AND EstPrincipale=1 LIMIT 1');
            $stmtSalleChk->execute([$club]);
            $idSalle = $stmtSalleChk->fetchColumn();
            if ($idSalle) {
                // Nom laissé vide = conserve le nom existant (colonne NOT NULL)
                $pdo->prepare('UPDATE salle SET Nom=COALESCE(?, Nom), Adresse=?, Telephone=? WHERE Id_Salle=?')
                    ->execute([$salleNom, $salleAdr, $salleTel, $idSalle]);
            } elseif ($salleNom !== null) {
                $pdo->prepare('INSERT INTO salle (Nom, Adresse, Telephone, Id_Club, EstPrincipale) VALUES (?,?,?,?,1)')
                    ->execute([$salleNom, $salleAdr, $salleTel, $club]);
            }

            $equipes = json_decode($_POST['equipes'] ?? '[]', true);
            if (is_array($equipes)) {
                $stmtEq = $pdo->prepare(
                    'UPDATE equipe SET ReEngagement=?, JourSouhaite=?, SouhaitJA=?, DesiderataSaison=?
                     WHERE Id_Equipe=? AND Id_Club=?'
                );
                $stmtDivOf = $pdo->prepare('SELECT Id_Division FROM equipe WHERE Id_Equipe=? AND Id_Club=?');

                foreach ($equipes as $eq) {
                    $idEquipe = (int)($eq['id_equipe'] ?? 0);
                    if ($idEquipe <= 0) continue;

                    $re  = in_array($eq['reengagement'] ?? '', ['O', 'N'], true) ? $eq['reengagement'] : null;
                    $jr  = in_array($eq['jour']         ?? '', ['Samedi', 'Dimanche'], true) ? $eq['jour'] : null;
                    $sja = in_array($eq['souhait_ja']   ?? '', ['CRA', 'Club'], true) ? $eq['souhait_ja'] : null;

                    $stmtEq->execute([$re, $jr, $sja, $saison, $idEquipe, $club]);

                    // Synchronise JAdemande + ArbitrageObligatoire pour les équipes R3M/R4M
                    if ($sja !== null) {
                        $stmtDivOf->execute([$idEquipe, $club]);
                        $idDiv = (int)$stmtDivOf->fetchColumn();
                        if (in_array($idDiv, [1, 10], true)) { // R3M, R4M
                            $jademande = $sja === 'CRA' ? 1 : 0;
                            $pdo->prepare('UPDATE equipe SET JAdemande=? WHERE Id_Equipe=?')->execute([$jademande, $idEquipe]);
                            if ($jademande === 1) {
                                $pdo->prepare('UPDATE rencontre SET ArbitrageObligatoire=1 WHERE Id_EquipeDom=?')
                                    ->execute([$idEquipe]);
                            } else {
                                $pdo->prepare(
                                    'UPDATE rencontre r
                                     JOIN equipe e   ON e.Id_Equipe   = r.Id_EquipeDom
                                     JOIN division d ON d.Id_Division = e.Id_Division
                                     SET r.ArbitrageObligatoire = d.ArbitrageCRA
                                     WHERE r.Id_EquipeDom = ?'
                                )->execute([$idEquipe]);
                            }
                        }
                    }
                }
            }

            echo json_encode(['ok' => true]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('[NIJAC] desiderata_club.php PDO : ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erreur base de données.']);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Action inconnue.']);
    exit;
}

// ── Chargement initial (rendu HTML) ────────────────────────────────────────────
$club   = null;
$erreur = '';
if ($idClubGet !== '') {
    $stmt = $pdo->prepare('SELECT Id_Club, Nom FROM club WHERE Id_Club = ?');
    $stmt->execute([$idClubGet]);
    $club = $stmt->fetch();
    if (!$club) $erreur = "Club #$idClubGet introuvable.";
} else {
    $erreur = 'Lien invalide ou paramètre manquant.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
<title>Désidératas club (E023)<?= $club ? ' – ' . htmlspecialchars($club['Nom']) : '' ?></title>
<link rel="stylesheet" href="../asset/css/bootstrap.min.css">
<link rel="stylesheet" href="../asset/css/bootstrap-icons.min.css">
<style>
    body { background: #f0f4f8; font-family: Arial, Helvetica, sans-serif; }

    #bandeau-normandie {
        max-width: 720px;
        margin: 1.5rem auto .5rem;
        padding: 0 .5rem;
    }
    #bandeau-normandie img {
        width: 100%;
        display: block;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(26,58,107,.2);
    }

    .page-header {
        background: #003087;
        color: #fff;
        padding: .75rem 1.5rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    .page-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; }
    .page-header .badge-ecran {
        background: rgba(255,255,255,.2);
        font-size: .7rem;
        padding: .25em .6em;
        border-radius: .25rem;
        letter-spacing: .5px;
    }

    .card-desiderata {
        max-width: 720px;
        margin: 1.5rem auto;
        border-radius: .75rem;
        box-shadow: 0 4px 20px rgba(0,0,0,.12);
    }
    .card-desiderata .card-header {
        background: #003087;
        color: #fff;
        border-radius: .75rem .75rem 0 0;
        font-weight: 700;
        font-size: 1rem;
        padding: 1rem 1.25rem;
    }

    .club-identity {
        background: #e8f0fb;
        border-radius: .5rem;
        padding: .75rem 1rem;
        margin-bottom: 1.25rem;
    }
    .club-identity .club-nom { font-size: 1.15rem; font-weight: 700; color: #003087; }
    .club-identity .club-num { font-size: .85rem; color: #555; }

    .section-titre {
        font-size: .82rem;
        font-weight: 700;
        color: #003087;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin: 1.25rem 0 .5rem;
        border-bottom: 2px solid #e8f0fb;
        padding-bottom: .3rem;
    }
    .section-titre:first-child { margin-top: 0; }

    #tbl-equipes { width: 100%; font-size: .85rem; border-collapse: collapse; }
    #tbl-equipes th {
        background: #e8eef7;
        padding: .4rem .5rem;
        text-align: center;
        font-size: .78rem;
        white-space: nowrap;
    }
    #tbl-equipes th:first-child, #tbl-equipes td:first-child { text-align: left; }
    #tbl-equipes td { padding: .45rem .5rem; border-top: 1px solid #e8eef7; text-align: center; vertical-align: middle; }
    #tbl-equipes .div-badge { font-size: .72rem; padding: .25em .5em; }
    #tbl-equipes .souhait-ja-cell.disabled { opacity: .35; }

    .btn-check-group label {
        font-size: .78rem;
        width: 68px;
        height: 30px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    #btn-valider {
        width: 100%;
        padding: .65rem;
        font-size: 1rem;
        font-weight: 700;
    }

    .succes-bloc { display: none; text-align: center; padding: 2rem 1rem; }
    .succes-bloc .bi { font-size: 3rem; color: #198754; }
    .succes-bloc p { font-size: 1.05rem; margin-top: .75rem; color: #155724; font-weight: 600; }

    #save-status { min-height: 1.4em; font-size: .85rem; margin-top: .5rem; text-align: center; }
    #save-status.err { color: #c00; }
</style>
</head>
<body>

<div class="page-header">
    <h1><i class="bi bi-clipboard2-check-fill me-2"></i>Désidératas du club — saison</h1>
    <span class="badge-ecran">E023</span>
</div>

<div class="container-fluid">

<div id="bandeau-normandie">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="../img/FFTT_LIGUE.png" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<?php if ($erreur): ?>
    <div class="alert alert-danger mt-4 mx-auto" style="max-width:720px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($erreur) ?>
    </div>
<?php else: ?>

<div class="card card-desiderata">
    <div class="card-header">
        <i class="bi bi-people-fill me-2"></i>Formulaire de désidératas — équipes Pré-Nationale à R4
    </div>
    <div class="card-body p-4">

        <div class="club-identity">
            <div class="club-nom"><?= htmlspecialchars($club['Nom']) ?></div>
            <div class="club-num">N° <?= htmlspecialchars($club['Id_Club']) ?> — <span id="lbl-saison"></span></div>
        </div>

        <div id="form-desiderata">

            <div class="section-titre"><i class="bi bi-person-lines-fill me-1"></i>Correspondant</div>
            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="inp-cor-nom">Nom et prénom</label>
                    <input type="text" id="inp-cor-nom" class="form-control" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="inp-cor-tel">Téléphone</label>
                    <input type="text" id="inp-cor-tel" class="form-control" maxlength="20">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="inp-cor-email">Email</label>
                    <input type="email" id="inp-cor-email" class="form-control" maxlength="150">
                </div>
            </div>

            <div class="section-titre"><i class="bi bi-building me-1"></i>Salle</div>
            <div class="row g-2 mb-2">
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="inp-salle-nom">Nom de la salle</label>
                    <input type="text" id="inp-salle-nom" class="form-control" maxlength="100">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold" for="inp-salle-adresse">Adresse</label>
                    <input type="text" id="inp-salle-adresse" class="form-control" maxlength="255">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="inp-salle-tel">Téléphone</label>
                    <input type="text" id="inp-salle-tel" class="form-control" maxlength="20">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="inp-nb-aires">Aires de jeu (max)</label>
                    <input type="number" id="inp-nb-aires" class="form-control" min="0" max="20">
                </div>
            </div>

            <div class="section-titre"><i class="bi bi-trophy me-1"></i>Équipes</div>
            <div class="table-responsive">
                <table id="tbl-equipes">
                    <thead>
                        <tr>
                            <th>Équipe</th>
                            <th style="width:5.5rem">Division</th>
                            <th style="width:9rem">Réengagement</th>
                            <th style="width:11rem">Jour souhaité</th>
                            <th style="width:11rem">Souhait JA (R3/R4)</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-equipes">
                        <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section-titre"><i class="bi bi-chat-left-text me-1"></i>Note</div>
            <div class="mb-2">
                <label class="form-label fw-semibold" for="inp-note">Pour signaler une modification éventuelle (nouvelle équipe, correction…)</label>
                <textarea id="inp-note" class="form-control" rows="3" maxlength="2000"></textarea>
            </div>

            <div id="save-status"></div>

            <div class="mt-3">
                <button id="btn-valider" class="btn btn-success">
                    <i class="bi bi-floppy me-1"></i>Enregistrer mes désidératas
                </button>
            </div>
        </div>

        <!-- Message succès -->
        <div class="succes-bloc" id="succes-bloc">
            <i class="bi bi-check-circle-fill"></i>
            <p>Vos désidératas ont bien été enregistrés.</p>
            <p style="font-size:1rem;color:#1a3a6b;font-weight:700;">
                Merci pour votre contribution !<br>
                <span style="font-size:.9rem;font-weight:400;color:#555;">
                    La Ligue Normandie de Tennis de Table vous remercie d'avoir renseigné ce questionnaire.
                </span>
            </p>
            <button class="btn btn-outline-primary btn-sm" id="btn-modifier">
                <i class="bi bi-pencil me-1"></i>Modifier à nouveau
            </button>
        </div>

    </div>
</div>

<?php endif; ?>
</div>

<script src="../asset/js/jquery-3.7.1.min.js"></script>
<script src="../asset/js/nijac-csrf.js"></script>
<script>
'use strict';
const ID_CLUB = <?= json_encode($club['Id_Club'] ?? '') ?>;

function escHtml(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function radioGroup(name, id, options, current) {
    return options.map(([val, label]) => `
        <label class="btn btn-sm btn-outline-primary">
            <input type="radio" class="btn-check" name="${name}-${id}" value="${val}" autocomplete="off" ${current === val ? 'checked' : ''}> ${label}
        </label>`).join('');
}

function renderEquipes(equipes) {
    const $body = $('#tbody-equipes').empty();
    if (!equipes.length) {
        $body.html('<tr><td colspan="5" class="text-center text-muted py-3">Aucune équipe (Pré-Nationale à R4) trouvée pour ce club.</td></tr>');
        return;
    }
    equipes.forEach(e => {
        const isR34 = e.Division === 'R3M' || e.Division === 'R4M';
        const souhaitCell = isR34
            ? `<div class="btn-group btn-check-group" role="group">${radioGroup('sja', e.Id_Equipe, [['CRA','CRA'],['Club','Club']], e.SouhaitJA)}</div>`
            : `<span class="text-muted" style="font-size:.75rem">—</span>`;
        const $tr = $(`
            <tr data-id="${e.Id_Equipe}">
                <td>${escHtml(e.NomEquipe)}</td>
                <td><span class="badge div-badge" style="background:#5c6bc0">${escHtml(e.Division)}</span></td>
                <td><div class="btn-group btn-check-group" role="group">${radioGroup('re', e.Id_Equipe, [['O','Oui'],['N','Non']], e.ReEngagement)}</div></td>
                <td><div class="btn-group btn-check-group" role="group">${radioGroup('jr', e.Id_Equipe, [['Samedi','Samedi'],['Dimanche','Dimanche']], e.JourSouhaite)}</div></td>
                <td class="souhait-ja-cell${isR34 ? '' : ' disabled'}">${souhaitCell}</td>
            </tr>`);
        $body.append($tr);
    });
}

function collecteEquipes() {
    const out = [];
    $('#tbody-equipes tr[data-id]').each(function () {
        const id = $(this).data('id');
        out.push({
            id_equipe:    id,
            reengagement: $(this).find(`input[name="re-${id}"]:checked`).val() || '',
            jour:         $(this).find(`input[name="jr-${id}"]:checked`).val() || '',
            souhait_ja:   $(this).find(`input[name="sja-${id}"]:checked`).val() || '',
        });
    });
    return out;
}

function charger() {
    $.get('desiderata_club.php', { action: 'charger', club: ID_CLUB }, function (res) {
        if (!res.ok) {
            $('#form-desiderata').html(`<div class="alert alert-danger">${escHtml(res.msg)}</div>`);
            return;
        }
        $('#lbl-saison').text('Saison ' + (res.saison || ''));
        $('#inp-cor-nom').val(res.club.CorNom || '');
        $('#inp-cor-tel').val(res.club.CorTelephone || '');
        $('#inp-cor-email').val(res.club.CorEmail || '');
        $('#inp-nb-aires').val(res.club.NbAiresJeu ?? '');
        $('#inp-note').val(res.club.DesiderataNote || '');
        $('#inp-salle-nom').val(res.salle.Nom || '');
        $('#inp-salle-adresse').val(res.salle.Adresse || '');
        $('#inp-salle-tel').val(res.salle.Telephone || '');
        renderEquipes(res.equipes);
    }, 'json').fail(function () {
        $('#form-desiderata').html('<div class="alert alert-danger">Erreur réseau lors du chargement.</div>');
    });
}

$('#btn-valider').on('click', function () {
    const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…');
    $('#save-status').removeClass('err').text('');

    $.post('desiderata_club.php', {
        action:        'enregistrer',
        club:          ID_CLUB,
        cor_nom:       $('#inp-cor-nom').val().trim(),
        cor_tel:       $('#inp-cor-tel').val().trim(),
        cor_email:     $('#inp-cor-email').val().trim(),
        nb_aires:      $('#inp-nb-aires').val(),
        note:          $('#inp-note').val().trim(),
        salle_nom:     $('#inp-salle-nom').val().trim(),
        salle_adresse: $('#inp-salle-adresse').val().trim(),
        salle_tel:     $('#inp-salle-tel').val().trim(),
        equipes:       JSON.stringify(collecteEquipes()),
    }, function (r) {
        if (r.ok) {
            $('#form-desiderata').hide();
            $('#succes-bloc').show();
        } else {
            $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mes désidératas');
            $('#save-status').addClass('err').text(r.msg || 'Erreur inconnue.');
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mes désidératas');
        $('#save-status').addClass('err').text('Erreur réseau.');
    });
});

$('#btn-modifier').on('click', function () {
    $('#succes-bloc').hide();
    $('#form-desiderata').show();
    $('#btn-valider').prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mes désidératas');
});

<?php if ($club): ?>
charger();
<?php endif; ?>
</script>
</body>
</html>
