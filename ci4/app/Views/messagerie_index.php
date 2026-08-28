<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Gestion des messages (EA93)</title>

    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac.css') ?>">
    <link rel="stylesheet" href="<?= base_url('asset/css/nijac-liste-edit.css') ?>">

    <style>
        /* Violet quand ouvert depuis le menu CSR (E004), vert Nominateur sinon — voir isCsr(). */
        #page-header { background: <?= $isCsr ? '#6a1b9a' : '#2e7d32' ?>; }
        #toolbar .ts-pwd-warning { display: <?= $changeLogin ? 'inline-flex' : 'none' ?>; }
        #panel-liste { width: 44%; }
        #table-wrapper { min-height: 9rem; }
        #panel-form { display: flex; flex-direction: column; }
        #txt-id { background: #f0f4fa; width: 80px; }

        #txt-message {
            resize: vertical;
            min-height: 220px;
        }

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

    </style>
</head>
<body>

<?= view('partials/page_header', [
    'phIcon' => 'envelope-fill', 'phTitle' => 'Gestion des messages', 'phCode' => 'EA93',
    'phCrumbLabel' => $isCsr ? 'CSR' : 'Nominateur',
    'phCrumbUrl'   => site_url($isCsr ? 'csr-menu' : 'nominateur-menu'),
    'phBackUrl'    => site_url($isCsr ? 'csr-menu' : 'nominateur-menu'),
    'phCrumbColor' => '#d0f0d0', 'phBadgeColor' => '#d0f0d0',
]) ?>

<!-- Toolbar : recopié de includes/toolbar.php -->
<?= view('partials/toolbar', ['tbNomComplet' => $nomComplet, 'tbDepartement' => $departement]) ?>

<?php require __DIR__ . '/_modal_mdp.php'; ?>

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
                        <th>Source</th>
                        <th title="Nominateur en copie">Cc</th>
                        <th title="Email du nominateur en Reply-To">RT</th>
                    </tr>
                </thead>
                <tbody id="tbody-liste">
                    <tr><td colspan="5" class="text-center text-muted py-3">Chargement…</td></tr>
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

        <div class="mb-2<?= $isCsr ? ' d-none' : '' ?>">
            <label class="form-label" for="cbo-type">Type :</label>
            <select id="cbo-type" class="form-select form-select-sm" style="max-width:280px">
                <?php foreach ($enumTypes as $v): ?>
                <option value="<?= esc($v) ?>"><?= esc($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="mb-2<?= $isCsr ? ' d-none' : '' ?>">
            <label class="form-label" for="txt-sujet">Sujet :</label>
            <input type="text" id="txt-sujet" class="form-control form-control-sm" maxlength="150" autocomplete="off">
        </div>

        <div class="mb-2 form-check<?= $isCsr ? ' d-none' : '' ?>">
            <input class="form-check-input" type="checkbox" id="chk-cc">
            <label class="form-check-label" for="chk-cc">Mettre le nominateur en copie (Cc) lors de l'envoi</label>
        </div>

        <div class="mb-2 form-check<?= $isCsr ? ' d-none' : '' ?>">
            <input class="form-check-input" type="checkbox" id="chk-replyto">
            <label class="form-check-label" for="chk-replyto">Utiliser l'email du nominateur (Reply-To) pour les réponses à l'email</label>
        </div>

        <div class="mb-2 flex-grow-1 d-flex flex-column">
            <div class="d-flex align-items-center justify-content-between mb-0">
                <label class="form-label mb-0" for="txt-message">Message :</label>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btn-apercu-html">
                    <i class="bi bi-eye me-1"></i>Aperçu HTML
                </button>
            </div>
            <textarea id="txt-message" class="form-control form-control-sm flex-grow-1"></textarea>
        </div>

        <!-- Boutons -->
        <div id="panel-boutons">
            <button class="btn btn-sm btn-nouveau px-3<?= $isCsr ? ' d-none' : '' ?>" id="btn-nouveau">
                <i class="bi bi-plus-circle me-1"></i>Nouveau
            </button>
            <button class="btn btn-sm btn-enregistrer px-3" id="btn-enregistrer">
                <i class="bi bi-floppy me-1"></i>Enregistrer
            </button>
            <button class="btn btn-sm btn-supprimer px-3<?= $isCsr ? ' d-none' : '' ?>" id="btn-supprimer" disabled>
                <i class="bi bi-trash3 me-1"></i>Supprimer
            </button>
            <button class="btn btn-sm px-3<?= $isCsr ? ' d-none' : '' ?>" id="btn-dupliquer" disabled
                    style="background:#fff3cd;border:1px solid #ffc107;font-weight:600;"
                    title="Copier ce message pour le personnaliser">
                <i class="bi bi-copy me-1"></i>Copier pour personnaliser
            </button>
        </div>
        <div id="msg-systeme-info" class="mt-2 small text-warning d-none">
            <i class="bi bi-lock-fill me-1"></i>Message système — lecture seule. Utilisez <strong>Copier pour personnaliser</strong> pour créer votre propre version.
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
                <code data-marqueur="{URL_LIGUE}" class="me-2">{URL_LIGUE}</code>
                <code data-marqueur="{YEAR_PHASE}" class="me-2">{YEAR_PHASE}</code>
                <code data-marqueur="{PHASE}" class="me-2">{PHASE}</code>
                <code data-marqueur="{URL_INFO_RENCONTRE}" class="me-2">{URL_INFO_RENCONTRE}</code>
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
                <code data-marqueur="{ID_CONVOCATION}" class="me-2">{ID_CONVOCATION}</code>
                <code data-marqueur="{SEXE}" class="me-2">{SEXE}</code>
                <code data-marqueur="{URL_CONVOCATION_JA}" class="me-2">{URL_CONVOCATION_JA}</code>
            </div>
            <div class="mb-1">
                <span class="badge me-1 fw-normal" style="background:#6f42c1;">Liste nomination</span>
                <code data-marqueur="{LISTE_NOMINATIONS}" class="me-2">{LISTE_NOMINATIONS}</code>
            </div>
            <div class="mb-1">
                <span class="badge me-1 fw-normal" style="background:#0d6efd;">Disponibilités / Rappel dispo</span>
                <code data-marqueur="{URL_DISPONIBILITE_JA}" class="me-2">{URL_DISPONIBILITE_JA}</code>
            </div>
            <div class="mb-1">
                <span class="badge me-1 fw-normal" style="background:#e06c00;">Demande adresse</span>
                <code data-marqueur="{URL_ADRESSE_JA}" class="me-2">{URL_ADRESSE_JA}</code>
            </div>
            <div>
                <span class="badge me-1 fw-normal" style="background:#c2185b;">Désidératas club (EN12 / ES31 CSR)</span>
                <code data-marqueur="{NOM_CLUB}" class="me-2">{NOM_CLUB}</code>
                <code data-marqueur="{CORR_NOM}" class="me-2">{CORR_NOM}</code>
                <code data-marqueur="{URL_DESIDERATA}" class="me-2">{URL_DESIDERATA}</code>
            </div>
        </div>

        <!-- Statut -->
        <div id="form-status" class="mt-2 small fw-bold"></div>

    </div><!-- /panel-form -->
</div><!-- /split-container -->

<!-- Modale aperçu HTML -->
<div class="modal fade" id="modal-apercu" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:var(--nijac-blue);color:#fff;">
                <h6 class="modal-title mb-0"><i class="bi bi-eye me-1"></i>Aperçu HTML du message</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="iframe-apercu" style="width:100%;height:60vh;border:0;"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Pied de page : recopié de includes/footer.php -->
<?= view('partials/page_footer', ['pfStatusAlign' => 'left']) ?>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/bootstrap.bundle.min.js') ?>"></script>
<script>
'use strict';

const MESSAGERIE_BASE = '<?= site_url('messagerie') ?>';
const IS_ADMIN         = <?= $isAdmin ? 'true' : 'false' ?>;
const IS_CSR            = <?= $isCsr ? 'true' : 'false' ?>;
const ID_CURRENT_USER  = <?= (int) $idCurrentUser ?>;

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Valeurs d'exemple pour l'aperçu des marqueurs ──────────────────────────────
const MARQUEURS_EXEMPLE = {
    '{PRENOM}':            'Jean',
    '{NOM}':                'Dupont',
    '{NOM_COMPLET}':        'Jean Dupont',
    '{ID_JA}':              '12345',
    '{UTI_NOM}':            'Martin',
    '{UTI_PRENOM}':         'Sophie',
    '{URL_LIGUE}':          'https://www.ligue-normandie-tt.fr',
    '{URL_ADRESSE_JA}':     <?= json_encode(site_url('adresse-ja') . '?ja=AbCd1234') ?>,
    '{URL_DISPONIBILITE_JA}': <?= json_encode(site_url('disponibilite-ja') . '?ja=AbCd1234') ?>,
    '{URL_INFO_RENCONTRE}': <?= json_encode(site_url('info-rencontre') . '?ja=AbCd1234') ?>,
    '{URL_CONVOCATION_JA}': <?= json_encode(site_url('convocation-ja') . '?nomination=12345') ?>,
    '{YEAR_PHASE}':         <?= json_encode(getAnneePhase()) ?>,
    '{PHASE}':              <?= json_encode(getConfig('phase', '1')) ?>,
    '{DATE}':               '15/03/2026',
    '{HEURE}':              '14:30',
    '{JOURNEE}':            '12',
    '{POULE}':              'A',
    '{DIVISION}':           'R2M',
    '{DOM}':                'Club A',
    '{EXT}':                'Club B',
    '{SALLE_NOM}':          'Salle Omnisports',
    '{SALLE_ADRESSE}':      '12 rue des Sports',
    '{SALLE_CP}':           '76000',
    '{SALLE_VILLE}':        'Rouen',
    '{CORR_NOM}':           'Durand',
    '{CORR_EMAIL}':         'correspondant@club.fr',
    '{CORR_TEL}':           '06 12 34 56 78',
    '{ID_CONVOCATION}':     'AB12CD34',
    '{SEXE}':               'M',
    '{LISTE_NOMINATIONS}':  '<ul><li>15/03/2026 — R2M — Club A vs Club B</li></ul>',
    '{NOM_CLUB}':           'ASSUN TT',
    '{URL_DESIDERATA}':     <?= json_encode(site_url('desiderata-club') . '?club=09760136') ?>
};

function resoudreMarqueurs(txt) {
    let out = String(txt ?? '');
    for (const [m, v] of Object.entries(MARQUEURS_EXEMPLE)) {
        out = out.split(m).join(v);
    }
    return out;
}

// ── Aperçu HTML : masqué pour les messages en texte brut ──────────────────────
function estMessageHtml(texte) {
    return /<\/?[a-z][\s\S]*?>/i.test(String(texte ?? ''));
}

function majBoutonApercu() {
    $('#btn-apercu-html').toggleClass('d-none', !estMessageHtml($('#txt-message').val()));
}

let currentId       = null;
let currentEstSys   = false; // true si message système (Id_Utilisateur === null)

// ── Utilitaires ───────────────────────────────────────────────────────────────
function setStatus(msg, ok = true) {
    $('#form-status').text(msg).removeClass('text-danger text-success').addClass(ok ? 'text-success' : 'text-danger');
}

// ── Liste ─────────────────────────────────────────────────────────────────────
function chargerListe(selectId = null) {
    $.get(`${MESSAGERIE_BASE}/data`, function (res) {
        const $body = $('#tbody-liste').empty();
        if (!res.ok || !res.data.length) {
            $body.append('<tr><td colspan="5" class="text-center text-muted py-3">Aucun message.</td></tr>');
            return;
        }
        res.data.forEach(m => {
            const estSys = !!parseInt(m.EstSysteme) || m.Id_Utilisateur === null || m.Id_Utilisateur === '';
            let sourceLabel;
            if (estSys) {
                sourceLabel = '<span class="badge bg-secondary">Défaut</span>';
            } else if (IS_ADMIN && m.NomUtilisateur) {
                sourceLabel = `<span class="badge" style="background:#1a7f4b">${escHtml(m.NomUtilisateur)}</span>`;
            } else {
                sourceLabel = '<span class="badge" style="background:#1a7f4b">Personnalisé</span>';
            }
            const ccLabel      = parseInt(m.Cc) === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '';
            const replyToLabel = parseInt(m.ReplyTo) === 1 ? '<i class="bi bi-check-lg text-success"></i>' : '';
            const $tr = $('<tr>')
                .attr('data-id', m.Id_Messagerie)
                .attr('data-sys', estSys ? '1' : '0')
                .append(
                    $('<td>').text(m.Type),
                    $('<td>').text(m.Sujet),
                    $('<td>').html(sourceLabel),
                    $('<td class="text-center">').html(ccLabel),
                    $('<td class="text-center">').html(replyToLabel)
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
    const id     = $tr.data('id');
    const estSys = $tr.data('sys') === 1 || $tr.data('sys') === '1';
    $.get(`${MESSAGERIE_BASE}/data/${id}`, function (res) {
        if (!res.ok) return;
        const m = res.data;
        currentId     = parseInt(m.Id_Messagerie);
        currentEstSys = estSys;
        $('#txt-id').val(currentId);
        $('#cbo-type').val(m.Type);
        $('#txt-sujet').val(m.Sujet);
        $('#txt-message').val(m.Message || '');
        $('#chk-cc').prop('checked', parseInt(m.Cc) === 1);
        $('#chk-replyto').prop('checked', parseInt(m.ReplyTo) === 1);

        const locked = estSys && !IS_ADMIN && !IS_CSR;
        $('#cbo-type, #txt-sujet, #txt-message').prop('readonly', locked);
        $('#cbo-type').prop('disabled', locked);
        $('#chk-cc').prop('disabled', locked);
        $('#chk-replyto').prop('disabled', locked);
        $('#btn-enregistrer').prop('disabled', locked);
        $('#btn-supprimer').prop('disabled', locked);
        // Actif si message système (pour tous) ou message d'un autre utilisateur (admin)
        const peutDupliquer = estSys || (IS_ADMIN && currentId !== null);
        $('#btn-dupliquer').prop('disabled', !peutDupliquer);
        $('#msg-systeme-info').toggleClass('d-none', !locked);
        majBoutonApercu();
        setStatus('');
    }, 'json');
}

// ── Nouveau ───────────────────────────────────────────────────────────────────
$('#btn-nouveau').on('click', function () {
    currentId     = null;
    currentEstSys = false;
    $('#tbody-liste tr').removeClass('selected');
    $('#txt-id').val('');
    $('#cbo-type').prop('disabled', false).val($('#cbo-type option:first').val());
    $('#txt-sujet').prop('readonly', false).val('').trigger('focus');
    $('#txt-message').prop('readonly', false).val('');
    $('#chk-cc').prop('disabled', false).prop('checked', false);
    $('#chk-replyto').prop('disabled', false).prop('checked', false);
    $('#btn-enregistrer').prop('disabled', false);
    $('#btn-supprimer').prop('disabled', true);
    $('#btn-dupliquer').prop('disabled', true);
    $('#msg-systeme-info').addClass('d-none');
    majBoutonApercu();
    setStatus('');
});

// ── Dupliquer ─────────────────────────────────────────────────────────────────
$('#btn-dupliquer').on('click', function () {
    if (!currentId) return;
    $.ajax({ url: `${MESSAGERIE_BASE}/${currentId}/dupliquer`, method: 'POST', dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(res.id); }
        else toast(res.msg, false);
    });
});

// ── Aperçu HTML ──────────────────────────────────────────────────────────────
$('#btn-apercu-html').on('click', function () {
    document.getElementById('iframe-apercu').srcdoc = resoudreMarqueurs($('#txt-message').val());
    new bootstrap.Modal('#modal-apercu').show();
});

// ── Enregistrer ───────────────────────────────────────────────────────────────
$('#btn-enregistrer').on('click', function () {
    const isNew = currentId === null;
    const payload = {
        type:    $('#cbo-type').val(),
        sujet:   $('#txt-sujet').val().trim(),
        message: $('#txt-message').val().trim(),
        cc:      $('#chk-cc').is(':checked') ? '1' : '0',
        replyto: $('#chk-replyto').is(':checked') ? '1' : '0',
    };
    const url    = isNew ? MESSAGERIE_BASE : `${MESSAGERIE_BASE}/${currentId}`;
    const method = isNew ? 'POST' : 'PUT';

    $.ajax({ url, method, data: payload, dataType: 'json' }).done(function (res) {
        if (res.ok) { toast(res.msg); chargerListe(res.id); }
        else { toast(res.msg, false); setStatus(res.msg, false); }
    });
});

// ── Supprimer ─────────────────────────────────────────────────────────────────
$('#btn-supprimer').on('click', function () {
    if (!currentId) return;
    const sujet = $('#txt-sujet').val();
    nijacConfirm(`Supprimer le message « ${sujet} » ?`, function () {
        $.ajax({ url: `${MESSAGERIE_BASE}/${currentId}`, method: 'DELETE', dataType: 'json' }).done(function (res) {
            if (res.ok) { toast(res.msg); chargerListe(); $('#btn-nouveau').trigger('click'); }
            else toast(res.msg, false);
        });
    }, null, {type: 'danger'});
});

// ── Clic sur un marqueur : insérer à la position du curseur ──────────────────
$(document).on('click', '[data-marqueur]', function () {
    const ta    = document.getElementById('txt-message');
    const texte = $(this).data('marqueur');
    const debut = ta.selectionStart ?? ta.value.length;
    const fin   = ta.selectionEnd   ?? debut;
    ta.setRangeText(texte, debut, fin, 'end');
    ta.focus();
    majBoutonApercu();
});

$('#txt-message').on('input', majBoutonApercu);

// ── Init ──────────────────────────────────────────────────────────────────────
$(function () {
    chargerListe();
    majBoutonApercu();
});
</script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-toast.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-sortable-table.js') ?>"></script>
</body>
</html>
