<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= csrf_hash() ?>">
<title>Adresse domicile JA (EN19)<?= $ja ? ' – ' . esc($ja['Nom'] . ' ' . $ja['Prenom']) : '' ?></title>
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('asset/css/bootstrap-icons.min.css') ?>">
<style>
    body { background: #f0f4f8; font-family: Arial, Helvetica, sans-serif; }

    /* Grille à 3 colonnes symétriques (logo | carte | espaceur de même largeur) :
       la carte reste centrée sur la page tout en laissant le logo à sa gauche. */
    .contenu-principal {
        display: grid;
        grid-template-columns: 220px minmax(0, 720px) 220px;
        justify-content: center;
        align-items: start;
        gap: 1.5rem;
        margin-top: 2rem;
        padding: 0 1rem;
    }
    #bandeau-normandie {
        grid-column: 1;
        grid-row: 1;
    }
    #bandeau-normandie img {
        width: 100%;
        display: block;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(26,58,107,.2);
    }
    .contenu-principal > .card-adresse,
    .contenu-principal > .alert-danger {
        grid-column: 2;
        grid-row: 1;
    }
    @media (max-width: 900px) {
        .contenu-principal {
            grid-template-columns: 220px;
            justify-content: center;
        }
        #bandeau-normandie { grid-row: 1; margin: 0 auto; }
        .contenu-principal > .card-adresse,
        .contenu-principal > .alert-danger {
            grid-column: 1;
            grid-row: 2;
        }
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

    .card-adresse {
        width: 100%;
        max-width: 720px;
        margin: 0;
        border-radius: .75rem;
        box-shadow: 0 4px 20px rgba(0,0,0,.12);
    }
    .card-adresse .card-header {
        background: #003087;
        color: #fff;
        border-radius: .75rem .75rem 0 0;
        font-weight: 700;
        font-size: 1rem;
        padding: 1rem 1.25rem;
    }

    .ja-identity {
        background: #e8f0fb;
        border-radius: .5rem;
        padding: .75rem 1rem;
        margin-bottom: 1.25rem;
    }
    .ja-identity .ja-nom { font-size: 1.15rem; font-weight: 700; color: #003087; }

    .adresse-actuelle {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: .5rem;
        padding: .6rem 1rem;
        margin-bottom: 1.25rem;
        font-size: .9rem;
    }
    .adresse-actuelle .lbl { font-weight: 700; color: #444; }
    .adresse-actuelle .val { color: #003087; font-weight: 600; }
    .adresse-actuelle .val.none { color: #999; font-style: italic; }

    #suggestions-bloc {
        display: none;
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: .4rem;
        padding: .5rem .75rem;
        margin-top: .5rem;
    }
    #suggestions-bloc .titre { font-size: .8rem; font-weight: 700; color: #856404; margin-bottom: .4rem; }
    #suggestions-bloc .btn-suggestion {
        margin: .2rem .2rem 0 0;
        font-size: .8rem;
    }

    #laposte-status { min-height: 1.4em; font-size: .85rem; margin-top: .3rem; }
    #laposte-status.ok   { color: #065f46; }
    #laposte-status.err  { color: #c00; }

    #aide-recherche {
        background: #eef6ff;
        border: 1px solid #b6d4f5;
        border-radius: .5rem;
        padding: .6rem .9rem;
        margin-bottom: 1rem;
        font-size: .82rem;
        color: #1a3a6b;
    }
    #aide-recherche .titre { font-weight: 700; margin-bottom: .25rem; }
    #aide-recherche ul { margin: 0; padding-left: 1.1rem; }
    #aide-recherche li { margin-bottom: .15rem; }

    #btn-valider {
        width: 100%;
        padding: .65rem;
        font-size: 1rem;
        font-weight: 700;
    }

    .succes-bloc {
        display: none;
        text-align: center;
        padding: 2rem 1rem;
    }
    .succes-bloc .bi { font-size: 3rem; color: #198754; }
    .succes-bloc p { font-size: 1.05rem; margin-top: .75rem; color: #155724; font-weight: 600; }
</style>
</head>
<body>

<div class="page-header">
    <h1><i class="bi bi-geo-alt-fill me-2"></i>Adresse domicile – Juge-Arbitre</h1>
    <span class="badge-ecran">EN19</span>
</div>

<div class="container-fluid">

<div class="contenu-principal">

<div id="bandeau-normandie">
    <a href="https://www.ligue-normandie-tt.fr/" target="_blank" rel="noopener noreferrer">
        <img src="<?= base_url('img/FFTT_LIGUE.png') ?>" alt="FFTT – Ligue de Normandie">
    </a>
</div>

<?php if ($erreur): ?>
    <div class="alert alert-danger" style="max-width:520px">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($erreur) ?>
    </div>
<?php else: ?>

<div class="card card-adresse">
    <div class="card-header">
        <i class="bi bi-house-fill me-2"></i>Renseigner mon adresse domicile
    </div>
    <div class="card-body p-4">

        <!-- Identité JA -->
        <div class="ja-identity">
            <div class="ja-nom"><?= esc(mb_strtoupper($ja['Nom']) . ' ' . $ja['Prenom']) ?></div>
        </div>

        <!-- Adresse actuelle -->
        <div class="adresse-actuelle">
            <span class="lbl">Adresse actuelle&nbsp;: </span>
            <?php if ($ja['Cp'] || $ja['Ville']): ?>
                <span class="val"><?= esc(trim(($ja['Cp'] ?? '') . ' ' . ($ja['Ville'] ?? ''))) ?></span>
            <?php else: ?>
                <span class="val none">Non renseignée</span>
            <?php endif; ?>
        </div>

        <!-- Aide à la recherche -->
        <div id="aide-recherche">
            <div class="titre"><i class="bi bi-info-circle me-1"></i>Comment trouver le bon code postal / la bonne ville ?</div>
            <ul>
                <li>Saisissez <strong>votre code postal seul</strong>, <strong>votre ville seule</strong>, ou les deux, puis cliquez sur <i class="bi bi-search"></i> (ou Entrée).</li>
                <li>Si plusieurs communes correspondent, une liste de suggestions s'affiche — cliquez sur la bonne.</li>
                <li>Pour une grande ville avec plusieurs codes postaux (ex : Rouen), indiquez le code postal exact figurant sur votre justificatif de domicile plutôt que le nom de la ville seul.</li>
                <li>Pour une commune associée à une autre (ex : « Saint-Étienne-du-Rouvray »), essayez aussi une orthographe abrégée (« St Etienne du Rouvray »).</li>
                <li>En cas de doute sur votre code postal, vous pouvez le vérifier sur
                    <a href="https://www.dcode.fr/code-postal" target="_blank" rel="noopener noreferrer">dcode.fr <i class="bi bi-box-arrow-up-right"></i></a>.</li>
            </ul>
        </div>

        <!-- Formulaire -->
        <div id="form-adresse">
            <div class="row g-2 mb-2">
                <div class="col-4">
                    <label class="form-label fw-semibold" for="inp-cp">Code postal</label>
                    <input type="text" id="inp-cp" class="form-control"
                           maxlength="5" placeholder="Exp :76000"
                           value="<?= esc($ja['Cp'] ?? '') ?>">
                </div>
                <div class="col-8">
                    <label class="form-label fw-semibold" for="inp-ville">Ville</label>
                    <div class="input-group">
                        <input type="text" id="inp-ville" class="form-control"
                               maxlength="80" placeholder="Exp :ROUEN"
                               value="<?= esc($ja['Ville'] ?? '') ?>">
                        <button class="btn btn-outline-secondary" id="btn-chercher" type="button" title="Rechercher">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div id="laposte-status"></div>

            <!-- Suggestions (CP ambigu) -->
            <div id="suggestions-bloc">
                <div class="titre"><i class="bi bi-list-ul me-1"></i>Plusieurs communes trouvées — choisissez :</div>
                <div id="suggestions-list"></div>
            </div>

            <div class="mt-3">
                <button id="btn-valider" class="btn btn-success" disabled>
                    <i class="bi bi-floppy me-1"></i>Enregistrer mon adresse
                </button>
            </div>
        </div>

        <!-- Message succès -->
        <div class="succes-bloc" id="succes-bloc">
            <i class="bi bi-check-circle-fill"></i>
            <p>Votre adresse a bien été enregistrée.</p>
            <div id="succes-adresse" class="text-muted mb-3" style="font-size:.9rem"></div>
            <p style="font-size:1rem;color:#1a3a6b;font-weight:700;">
                Merci pour votre contribution !<br>
                <span style="font-size:.9rem;font-weight:400;color:#555;">
                    La Ligue Normandie de Tennis de Table vous remercie d'avoir renseigné votre adresse domicile.
                </span>
            </p>
        </div>

    </div>
</div>

<?php endif; ?>
</div><!-- /contenu-principal -->
</div>

<script src="<?= base_url('asset/js/jquery-3.7.1.min.js') ?>"></script>
<script src="<?= base_url('asset/js/nijac-csrf.js') ?>"></script>
<script>
'use strict';
const ID_JA = <?= (int) $idJa ?>;
const BASE  = '<?= site_url('adresse-ja') ?>';
let idLaPoste = <?= $ja && $ja['Id_LaPoste'] ? (int) $ja['Id_LaPoste'] : 'null' ?>;

function setStatus(msg, type) {
    $('#laposte-status').text(msg).removeClass('ok err').addClass(type);
}

function activerBouton() {
    $('#btn-valider').prop('disabled', !idLaPoste);
}

function choisirCommune(cp, ville, id) {
    idLaPoste = id;
    $('#inp-cp').val(cp);
    $('#inp-ville').val(ville);
    $('#suggestions-bloc').hide();
    setStatus('✓ ' + cp + ' ' + ville, 'ok');
    activerBouton();
}

function rechercherLaPoste() {
    const cp    = $('#inp-cp').val().trim();
    const ville = $('#inp-ville').val().trim();
    if (!cp && !ville) {
        idLaPoste = null;
        setStatus('', '');
        activerBouton();
        return;
    }

    setStatus('Recherche…', '');
    idLaPoste = null;
    activerBouton();
    $('#suggestions-bloc').hide();
    $('#suggestions-list').empty();

    $.post(`${BASE}/recherche-laposte`, { cp, ville }, function (res) {
        if (res.multi) {
            setStatus('', '');
            const $list = $('#suggestions-list').empty();
            res.suggestions.forEach(function (s) {
                $('<button>').addClass('btn btn-sm btn-outline-primary btn-suggestion')
                    .text(s.cp + ' ' + s.ville)
                    .on('click', function () { choisirCommune(s.cp, s.ville, s.id_laposte); })
                    .appendTo($list);
            });
            $('#suggestions-bloc').show();
            return;
        }
        if (res.ok) {
            choisirCommune(res.cp, res.ville, res.id_laposte);
        } else {
            setStatus('Commune non trouvée — vérifiez le code postal ou la ville.', 'err');
        }
    }, 'json').fail(function () {
        setStatus('Erreur réseau.', 'err');
    });
}

$('#btn-chercher').on('click', rechercherLaPoste);
$('#inp-cp, #inp-ville').on('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); rechercherLaPoste(); }
});
$('#inp-cp, #inp-ville').on('input', function () {
    idLaPoste = null;
    $('#laposte-status').text('').removeClass('ok err');
    $('#suggestions-bloc').hide();
    activerBouton();
});

$('#btn-valider').on('click', function () {
    if (!idLaPoste) return;
    const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…');
    $.post(`${BASE}/sauvegarder`, {
        id_ja:      ID_JA,
        id_laposte: idLaPoste,
        cp:         $('#inp-cp').val().trim(),
        ville:      $('#inp-ville').val().trim(),
    }, function (r) {
        if (r.ok) {
            $('#form-adresse').hide();
            $('#succes-adresse').text($('#inp-cp').val().trim() + ' ' + $('#inp-ville').val().trim());
            $('#succes-bloc').show();
        } else {
            $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mon adresse');
            setStatus(r.err || 'Erreur inconnue.', 'err');
        }
    }, 'json').fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-floppy me-1"></i>Enregistrer mon adresse');
        setStatus('Erreur réseau.', 'err');
    });
});

// Activer le bouton si une adresse est déjà connue
activerBouton();
<?php if ($ja && $ja['Cp']): ?>
if (idLaPoste) setStatus('✓ <?= addslashes(($ja['Cp'] ?? '') . ' ' . ($ja['Ville'] ?? '')) ?>', 'ok');
<?php endif; ?>
</script>
</body>
</html>
