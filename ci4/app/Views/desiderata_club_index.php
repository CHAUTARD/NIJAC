<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>Désidératas club (EN18)<?= $club ? ' – ' . esc($club['Nom']) : '' ?></title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
<style>
    body { background: #f0f4f8; font-family: Arial, Helvetica, sans-serif; }

    #bandeau-normandie {
        max-width: 420px;
        margin: 1.5rem auto .5rem;
        padding: 0 .5rem;
    }
    #bandeau-normandie img {
        width: 100%;
        height: auto;
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
        max-width: 860px;
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

    @media (max-width: 640px) {
        .page-header { padding: .6rem .9rem; flex-wrap: wrap; gap: .5rem; }
        .page-header h1 { font-size: 1rem; }
        .card-desiderata { margin: 1rem .5rem; }
        .card-desiderata .card-body { padding: 1rem !important; }
        #tbl-equipes { font-size: .8rem; }
    }
</style>
</head>
<body>

<div class="page-header">
    <h1><i class="bi bi-clipboard2-check-fill me-2"></i>Désidératas du club — Phase</h1>
    <span class="badge-ecran">EN18</span>
</div>

<div class="container-fluid">

<div id="bandeau-normandie">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<?php if ($erreur): ?>
    <div class="alert alert-danger mt-4 mx-auto" style="max-width:720px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($erreur) ?>
    </div>
<?php else: ?>

<div class="card card-desiderata">
    <div class="card-header">
        <i class="bi bi-people-fill me-2"></i>Formulaire de désidératas — équipes Pré-Nationale à R4
    </div>
    <div class="card-body p-4">

        <div class="club-identity">
            <div class="club-nom"><?= esc($club['Nom']) ?></div>
            <div class="club-num">N° <?= esc($club['Id_Club']) ?> — <span id="lbl-saison"></span></div>
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
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="inp-salle-nom">Nom de la salle</label>
                    <input type="text" id="inp-salle-nom" class="form-control" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold" for="inp-salle-adresse">Adresse</label>
                    <input type="text" id="inp-salle-adresse" class="form-control" maxlength="255">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="inp-salle-cp">Code postal</label>
                    <input type="text" id="inp-salle-cp" class="form-control" maxlength="10">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="inp-salle-ville">Ville</label>
                    <input type="text" id="inp-salle-ville" class="form-control" maxlength="100">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold" for="inp-salle-tel">Téléphone</label>
                    <input type="text" id="inp-salle-tel" class="form-control" maxlength="20">
                </div>
            </div>
            <div class="row g-2 mb-2">
                <div class="col-md-3">
                    <label class="form-label fw-semibold text-nowrap" for="inp-nb-aires">Aires de jeu (max)</label>
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

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
'use strict';
const ID_CLUB       = <?= json_encode($club['Id_Club'] ?? '') ?>;
const DESID_BASE = '<?= site_url('desiderata-club') ?>';

function escHtml(s) {
    return String(s ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function radioGroup(name, id, options, current) {
    return options.map(([val, label], i) => {
        const inputId = `${name}-${id}-${i}`;
        return `
        <input type="radio" class="btn-check" id="${inputId}" name="${name}-${id}" value="${val}" autocomplete="off" ${current === val ? 'checked' : ''}>
        <label class="btn btn-sm btn-outline-primary" for="${inputId}">${label}</label>`;
    }).join('');
}

function renderEquipes(equipes) {
    const $body = $('#tbody-equipes').empty();
    if (!equipes.length) {
        $body.html('<tr><td colspan="5" class="text-center text-muted py-3">Aucune équipe (Pré-Nationale à R4) trouvée pour ce club.</td></tr>');
        return;
    }
    equipes.forEach(e => {
        const isR34 = e.Division === 'R3M' || e.Division === 'R4M';
        const reengagement = e.ReEngagement || 'O';
        const jour         = e.JourSouhaite || 'Samedi';
        const souhaitJa    = e.SouhaitJA    || 'CRA';
        const souhaitCell = isR34
            ? `<div class="btn-group btn-check-group" role="group">${radioGroup('sja', e.Id_Equipe, [['CRA','CRA'],['Club','Club']], souhaitJa)}</div>`
            : `<span class="text-muted" style="font-size:.75rem">—</span>`;
        const $tr = $(`
            <tr data-id="${e.Id_Equipe}">
                <td>${escHtml(e.NomEquipe)}</td>
                <td><span class="badge div-badge" style="background:#5c6bc0">${escHtml(e.Division)}</span></td>
                <td><div class="btn-group btn-check-group" role="group">${radioGroup('re', e.Id_Equipe, [['O','Oui'],['N','Non']], reengagement)}</div></td>
                <td><div class="btn-group btn-check-group" role="group">${radioGroup('jr', e.Id_Equipe, [['Samedi','Samedi'],['Dimanche','Dimanche']], jour)}</div></td>
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
    $.get(`${DESID_BASE}/charger`, { club: ID_CLUB }, function (res) {
        if (!res.ok) {
            $('#form-desiderata').html(`<div class="alert alert-danger">${escHtml(res.msg)}</div>`);
            return;
        }
        $('#lbl-saison').text('Saison ' + (res.saison || ''));
        $('#inp-cor-nom').val(res.club.CorNom || '');
        $('#inp-cor-tel').val(res.club.CorTelephone || '');
        $('#inp-cor-email').val(res.club.CorEmail || '');
        $('#inp-nb-aires').val(res.club.NbAiresJeu ?? 1);
        $('#inp-note').val(res.club.DesiderataNote || '');
        $('#inp-salle-nom').val(res.salle.Nom || '');
        $('#inp-salle-adresse').val(res.salle.Adresse || '');
        $('#inp-salle-cp').val(res.salle.Cp || '');
        $('#inp-salle-ville').val(res.salle.Ville || '');
        $('#inp-salle-tel').val(res.salle.Telephone || '');
        renderEquipes(res.equipes);
    }, 'json').fail(function () {
        $('#form-desiderata').html('<div class="alert alert-danger">Erreur réseau lors du chargement.</div>');
    });
}

$('#btn-valider').on('click', function () {
    const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…');
    $('#save-status').removeClass('err').text('');

    $.post(`${DESID_BASE}/enregistrer`, {
        club:          ID_CLUB,
        cor_nom:       $('#inp-cor-nom').val().trim(),
        cor_tel:       $('#inp-cor-tel').val().trim(),
        cor_email:     $('#inp-cor-email').val().trim(),
        nb_aires:      $('#inp-nb-aires').val(),
        note:          $('#inp-note').val().trim(),
        salle_nom:     $('#inp-salle-nom').val().trim(),
        salle_adresse: $('#inp-salle-adresse').val().trim(),
        salle_cp:      $('#inp-salle-cp').val().trim(),
        salle_ville:   $('#inp-salle-ville').val().trim(),
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
