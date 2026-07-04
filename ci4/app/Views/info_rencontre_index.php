<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title>NIJAC – Ma fiche JA (E030)</title>
    <link rel="stylesheet" href="/asset/css/bootstrap.min.css">
    <link rel="stylesheet" href="/asset/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/asset/css/nijac.css">
    <style>
        :root { --nijac-blue: #1a3a6b; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f0f4fa;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        #page-header {
            background: var(--nijac-blue);
            color: #fff;
            padding: .5rem 1.25rem;
            font-size: .9rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        .container-fluid { flex: 1; }
    </style>
</head>
<body>

<!-- En-tête : recopié de includes/page_header.php (pas de bouton Retour, page racine du JA) -->
<div id="page-header">
    <i class="bi bi-person-badge me-2"></i>Ma fiche Juge-Arbitre
    <small class="ms-2" style="opacity:.75;">(E030)</small>
</div>

<div class="container-fluid px-3 pb-4 pt-3">

    <!-- ── Identité ─────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold bg-primary text-white">
            <i class="bi bi-person-circle me-2"></i>Identité
        </div>
        <div class="card-body">
            <h4 class="mb-1"><?= esc($ja['Prenom'] . ' ' . $ja['Nom']) ?></h4>
            <p class="text-muted mb-2 small">Licence&nbsp;: <strong><?= esc($ja['Id_JA']) ?></strong></p>
            <table class="table table-sm table-borderless mb-0" style="max-width:400px">
                <?php if ($ja['NomClub']): ?>
                <tr>
                    <th class="text-muted fw-normal ps-0" style="width:40%">Club</th>
                    <td><?= esc($ja['NomClub']) ?></td>
                </tr>
                <?php endif; ?>
                <tr style="background:#eef4ff;border-radius:6px;">
                    <th class="text-muted fw-normal ps-0" style="border-left:3px solid #1a3a6b;padding-left:.5rem !important;">Domicile</th>
                    <td>
                        <span id="lbl-domicile" class="fw-semibold">
                            <?= esc(trim(($ja['CodePostal'] ?? '') . ' ' . ($ja['Ville'] ?? ''))) ?: '<em class="text-muted fw-normal">Non renseigné</em>' ?>
                        </span>
                        <button class="btn btn-primary btn-sm ms-2 py-0 px-2" data-bs-toggle="modal" data-bs-target="#modal-adresse" title="Modifier mon adresse">
                            <i class="bi bi-pencil-fill me-1"></i>Modifier
                        </button>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- ── Nominations (à venir + déjà arbitrées) ──────────────────────────── -->
    <div class="card border-0 shadow-sm">
        <div class="card-header fw-semibold bg-primary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-check me-2"></i>Mes nominations</span>
            <span class="badge bg-light text-dark"><?= count($prochaines) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($prochaines)): ?>
            <p class="text-muted p-3 mb-0"><i class="bi bi-info-circle me-2"></i>Aucune nomination pour le moment.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Heure</th>
                            <th>Division</th>
                            <th>Rencontre</th>
                            <th>Salle</th>
                            <th>Lieu</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prochaines as $n):
                            $dateFr = $n['DateRencontre']
                                ? (new DateTime($n['DateRencontre']))->format('d/m/Y')
                                : '—';
                            $heure  = $n['HeureRencontre']
                                ? substr($n['HeureRencontre'], 0, 5)
                                : '—';
                            $rencontre = esc(($n['NomClubDomicile'] ?? '?') . ' – ' . ($n['NomClubVisiteur'] ?? '?'));
                            $lieu = esc(trim(($n['CpSalle'] ?? '') . ' ' . ($n['VilleSalle'] ?? '')));
                            $bgN  = $n['DivisionColor'] ?: '#6c757d';
                            $hexN = ltrim($bgN, '#');
                            $lumN = strlen($hexN) === 6
                                ? (0.299*hexdec(substr($hexN,0,2)) + 0.587*hexdec(substr($hexN,2,2)) + 0.114*hexdec(substr($hexN,4,2))) / 255
                                : 0;
                            $fgN     = $lumN > 0.55 ? '#111' : '#fff';
                            $aVenir  = (bool)$n['AVenir'];
                        ?>
                        <tr<?= $aVenir ? '' : ' style="opacity:.6"' ?>>
                            <td class="fw-semibold"><?= $dateFr ?></td>
                            <td><?= $heure ?></td>
                            <td><span class="badge" style="background:<?= esc($bgN) ?>;color:<?= $fgN ?>"><?= esc($n['DivisionCode'] ?? '—') ?></span></td>
                            <td><?= $rencontre ?></td>
                            <td><?= esc($n['NomSalle'] ?? '—') ?></td>
                            <td class="text-muted small"><?= $lieu ?></td>
                            <td>
                                <?php if ($aVenir): ?>
                                <span class="badge bg-primary"><i class="bi bi-hourglass-split me-1"></i>À venir</span>
                                <?php else: ?>
                                <span class="badge bg-secondary"><i class="bi bi-check-circle me-1"></i>Arbitrée</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Arbitrage club : rencontres R3M / R4M à sélectionner ─────────── -->
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header fw-semibold bg-warning text-dark d-flex justify-content-between align-items-center">
            <span><i class="bi bi-trophy me-2"></i>Arbitrage club — Rencontres R3M / R4M à venir</span>
            <span class="badge bg-dark"><?= count($rencontresR3R4) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($rencontresR3R4)): ?>
            <p class="text-muted p-3 mb-0"><i class="bi bi-info-circle me-2"></i>Aucune rencontre R3M/R4M à venir avec arbitrage assuré par le club.</p>
            <?php else: ?>
            <p class="text-muted px-3 pt-3 mb-2 small"><i class="bi bi-info-circle me-2"></i>Votre club a choisi d'assurer lui-même l'arbitrage de ses rencontres R3M/R4M à domicile. Sélectionnez les rencontres que vous allez arbitrer, puis validez.</p>
            <form id="form-selection">
            <div class="table-responsive">
                <table class="table table-hover table-striped table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:2.2rem" class="text-center"><input type="checkbox" id="chk-all-r3r4"></th>
                            <th>Date</th>
                            <th>Heure</th>
                            <th>Journée</th>
                            <th>Division</th>
                            <th>Domicile</th>
                            <th>Extérieur</th>
                            <th>Salle</th>
                            <th>Lieu</th>
                            <th>JA désigné</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rencontresR3R4 as $rc):
                            $dateFr = $rc['DateRencontre']
                                ? (new DateTime($rc['DateRencontre']))->format('d/m/Y')
                                : '—';
                            $heure  = $rc['HeureRencontre']
                                ? substr($rc['HeureRencontre'], 0, 5)
                                : '—';
                            $color  = $rc['DivisionColor'] ?: '#6c757d';
                            $hex = ltrim($color, '#');
                            if (strlen($hex) === 6) {
                                $lum = (0.299 * hexdec(substr($hex,0,2)) + 0.587 * hexdec(substr($hex,2,2)) + 0.114 * hexdec(substr($hex,4,2))) / 255;
                                $textColor = $lum > 0.55 ? '#111' : '#fff';
                            } else {
                                $textColor = '#fff';
                            }
                            $isMe        = ($rc['IdJaAffecte'] == $idJa);
                            $selectable  = !$rc['IdJaAffecte'];
                        ?>
                        <tr<?= $isMe ? ' class="table-success fw-semibold"' : '' ?> data-id-rencontre="<?= (int)$rc['Id_Rencontre'] ?>">
                            <td class="text-center">
                                <?php if ($selectable): ?>
                                <input type="checkbox" class="chk-r3r4">
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= $dateFr ?></td>
                            <td><?= $heure ?></td>
                            <td class="text-muted small">J<?= esc($rc['Journee'] ?? '—') ?></td>
                            <td>
                                <span class="badge" style="background:<?= esc($color) ?>;color:<?= $textColor ?>">
                                    <?= esc($rc['DivisionCode'] ?? '—') ?>
                                </span>
                            </td>
                            <td><?= esc($rc['NomDom'] ?? '—') ?></td>
                            <td><?= esc($rc['NomExt'] ?? '—') ?></td>
                            <td class="text-muted small"><?= esc($rc['NomSalle'] ?? '—') ?></td>
                            <td class="text-muted small"><?= esc(trim(($rc['CpSalle'] ?? '') . ' ' . ($rc['VilleSalle'] ?? ''))) ?></td>
                            <td class="td-statut">
                                <?php if ($rc['IdJaAffecte']): ?>
                                    <?php if ($isMe): ?>
                                    <span class="badge bg-success"><i class="bi bi-person-check me-1"></i><?= esc($rc['NomJaAffecte']) ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?= esc($rc['NomJaAffecte']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-circle me-1"></i>Non désigné</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="p-3 border-top d-flex justify-content-between align-items-center">
                <span id="lbl-r3r4-selection" class="text-muted small">0 rencontre(s) sélectionnée(s)</span>
                <button type="button" id="btn-valider-selection" class="btn btn-danger btn-sm" disabled>
                    <i class="bi bi-check2-circle me-1"></i>Valider ma sélection
                </button>
            </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-end mt-3">
        <a href="<?= site_url('logout') ?>" class="btn btn-outline-danger btn-sm">
            <i class="bi bi-box-arrow-left me-2"></i>Se déconnecter
        </a>
    </div>

</div>

<!-- ── Modale modification adresse ───────────────────────────────────────── -->
<div class="modal fade" id="modal-adresse" tabindex="-1" aria-labelledby="modal-adresse-titre" aria-hidden="true">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white py-2">
        <h6 class="modal-title" id="modal-adresse-titre"><i class="bi bi-geo-alt-fill me-2"></i>Mon adresse domicile</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2 mb-2">
          <div class="col-5">
            <label class="form-label form-label-sm fw-semibold mb-1">Code postal</label>
            <input type="text" id="adr-cp" class="form-control form-control-sm" maxlength="5" placeholder="76000"
                   value="<?= esc($ja['CodePostal'] ?? '') ?>">
          </div>
          <div class="col-7">
            <label class="form-label form-label-sm fw-semibold mb-1">Ville</label>
            <div class="input-group input-group-sm">
              <input type="text" id="adr-ville" class="form-control" maxlength="80" placeholder="ROUEN"
                     value="<?= esc($ja['Ville'] ?? '') ?>">
              <button class="btn btn-outline-secondary" id="adr-btn-chercher" type="button"><i class="bi bi-search"></i></button>
            </div>
          </div>
        </div>
        <div id="adr-status" class="small mb-1"></div>
        <div id="adr-suggestions" class="d-none p-2 rounded mb-2" style="background:#fff3cd;border:1px solid #ffc107">
          <div class="small fw-bold text-warning-emphasis mb-1">Plusieurs communes — choisissez :</div>
          <div id="adr-suggestions-list"></div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
        <button type="button" id="adr-btn-valider" class="btn btn-success btn-sm" disabled>
          <i class="bi bi-floppy me-1"></i>Enregistrer
        </button>
      </div>
    </div>
  </div>
</div>

<script src="/asset/js/jquery-3.7.1.min.js"></script>
<script src="/asset/js/nijac-csrf.js"></script>
<script src="/asset/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF     = <?= json_encode(csrf_hash()) ?>;
const JA_TOKEN = <?= json_encode($jaToken ?? '') ?>;
const BASE     = '<?= site_url('info-rencontre') ?>';

// ── Modale adresse domicile ───────────────────────────────────────────────────
let adrIdLaPoste = <?= ($ja['Id_LaPoste'] ?? 0) ? (int)$ja['Id_LaPoste'] : 'null' ?>;

function adrSetStatus(msg, ok) {
    const el = document.getElementById('adr-status');
    el.textContent = msg;
    el.className = 'small mb-1 ' + (ok === true ? 'text-success' : ok === false ? 'text-danger' : 'text-muted');
}

function adrChoisir(cp, ville, id) {
    adrIdLaPoste = id;
    document.getElementById('adr-cp').value    = cp;
    document.getElementById('adr-ville').value = ville;
    document.getElementById('adr-suggestions').classList.add('d-none');
    adrSetStatus('✓ ' + cp + ' ' + ville, true);
    document.getElementById('adr-btn-valider').disabled = false;
}

async function adrChercher() {
    const cp    = document.getElementById('adr-cp').value.trim();
    const ville = document.getElementById('adr-ville').value.trim();
    if (!cp && !ville) return;
    adrSetStatus('Recherche…', null);
    adrIdLaPoste = null;
    document.getElementById('adr-btn-valider').disabled = true;
    document.getElementById('adr-suggestions').classList.add('d-none');

    const body = new URLSearchParams({ cp, ville, _csrf: CSRF, ja: JA_TOKEN });
    try {
        const r = await fetch(`${BASE}/recherche-laposte`, { method: 'POST', body });
        const d = await r.json();
        if (d.multi) {
            adrSetStatus('', null);
            const list = document.getElementById('adr-suggestions-list');
            list.innerHTML = '';
            d.suggestions.forEach(s => {
                const b = document.createElement('button');
                b.className = 'btn btn-sm btn-outline-primary me-1 mb-1';
                b.textContent = s.cp + ' ' + s.ville;
                b.addEventListener('click', () => adrChoisir(s.cp, s.ville, s.id_laposte));
                list.appendChild(b);
            });
            document.getElementById('adr-suggestions').classList.remove('d-none');
        } else if (d.ok) {
            adrChoisir(d.cp, d.ville, d.id_laposte);
        } else {
            adrSetStatus(d.msg || 'Commune non trouvée.', false);
        }
    } catch { adrSetStatus('Erreur réseau.', false); }
}

document.getElementById('adr-btn-chercher').addEventListener('click', adrChercher);
['adr-cp', 'adr-ville'].forEach(id => {
    document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); adrChercher(); } });
    document.getElementById(id).addEventListener('input', () => {
        adrIdLaPoste = null;
        document.getElementById('adr-status').textContent = '';
        document.getElementById('adr-btn-valider').disabled = true;
        document.getElementById('adr-suggestions').classList.add('d-none');
    });
});

document.getElementById('adr-btn-valider').addEventListener('click', async () => {
    if (!adrIdLaPoste) return;
    const btn = document.getElementById('adr-btn-valider');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Enregistrement…';
    const cp    = document.getElementById('adr-cp').value.trim();
    const ville = document.getElementById('adr-ville').value.trim();
    const body  = new URLSearchParams({ id_laposte: adrIdLaPoste, cp, ville, _csrf: CSRF, ja: JA_TOKEN });
    try {
        const r = await fetch(`${BASE}/sauvegarder-adresse`, { method: 'POST', body });
        const d = await r.json();
        if (d.ok) {
            document.getElementById('lbl-domicile').textContent = (d.cp + ' ' + d.ville).trim();
            bootstrap.Modal.getInstance(document.getElementById('modal-adresse')).hide();
            nijacToast('Adresse mise à jour.', 'success');
        } else {
            adrSetStatus(d.msg || 'Erreur.', false);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Enregistrer';
        }
    } catch {
        adrSetStatus('Erreur réseau.', false);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Enregistrer';
    }
});

// Réinitialiser le statut à chaque ouverture de la modale
document.getElementById('modal-adresse').addEventListener('show.bs.modal', () => {
    adrSetStatus(adrIdLaPoste ? '✓ Adresse déjà renseignée' : '', adrIdLaPoste ? true : null);
    document.getElementById('adr-btn-valider').disabled = !adrIdLaPoste;
    document.getElementById('adr-suggestions').classList.add('d-none');
    document.getElementById('adr-btn-valider').innerHTML = '<i class="bi bi-floppy me-1"></i>Enregistrer';
});

// ── Sélection des rencontres à arbitrer (arbitrage club) ──────────────────────
const JA_NOM_COMPLET = <?= json_encode($ja['Prenom'] . ' ' . $ja['Nom']) ?>;

function majSelectionR3R4() {
    const nb = document.querySelectorAll('.chk-r3r4:checked').length;
    document.getElementById('lbl-r3r4-selection').textContent = `${nb} rencontre(s) sélectionnée(s)`;
    document.getElementById('btn-valider-selection').disabled = nb === 0;
    const total = document.querySelectorAll('.chk-r3r4').length;
    const chkAll = document.getElementById('chk-all-r3r4');
    if (chkAll) chkAll.checked = total > 0 && nb === total;
}

document.querySelectorAll('.chk-r3r4').forEach(chk => chk.addEventListener('change', majSelectionR3R4));

// ── Clic sur la ligne = bascule la case à cocher ──────────────────────────────
document.querySelectorAll('#form-selection tr[data-id-rencontre]').forEach(tr => {
    const chk = tr.querySelector('.chk-r3r4');
    if (!chk) return;
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', ev => {
        if (ev.target.closest('.chk-r3r4')) return;
        chk.checked = !chk.checked;
        chk.dispatchEvent(new Event('change', { bubbles: true }));
    });
});

const chkAllR3R4 = document.getElementById('chk-all-r3r4');
if (chkAllR3R4) {
    chkAllR3R4.addEventListener('change', ev => {
        document.querySelectorAll('.chk-r3r4').forEach(chk => { chk.checked = ev.target.checked; });
        majSelectionR3R4();
    });
}

const btnValiderSelection = document.getElementById('btn-valider-selection');
if (btnValiderSelection) {
    btnValiderSelection.addEventListener('click', async () => {
        const ids = Array.from(document.querySelectorAll('.chk-r3r4:checked'))
            .map(chk => +chk.closest('tr').dataset.idRencontre);
        if (!ids.length) return;
        if (!confirm(`Vous désigner comme JA pour ${ids.length} rencontre(s) et envoyer les convocations ?`)) return;

        btnValiderSelection.disabled = true;
        btnValiderSelection.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Validation…';

        try {
            const body = new URLSearchParams({ ids: JSON.stringify(ids), _csrf: CSRF, ja: JA_TOKEN });
            const r = await fetch(`${BASE}/se-designer`, { method: 'POST', body });
            const d = await r.json();

            (d.resultats || []).forEach(res => {
                const tr = document.querySelector(`tr[data-id-rencontre="${res.id_rencontre}"]`);
                if (!tr) return;
                if (res.ok) {
                    tr.querySelector('.td-statut').innerHTML = '<span class="badge bg-success"><i class="bi bi-person-check me-1"></i>' + JA_NOM_COMPLET + '</span>';
                    tr.querySelector('td:first-child').innerHTML = '';
                    tr.className = 'table-success fw-semibold';
                }
            });

            const nbOk = (d.resultats || []).filter(r => r.ok).length;
            const nbKo = (d.resultats || []).filter(r => !r.ok);
            let msg = `${nbOk} rencontre(s) validée(s).`;
            if (nbKo.length) msg += ' Échecs : ' + nbKo.map(r => r.msg).join(' | ');
            alert(msg);
        } catch (e) {
            alert('Erreur : ' + e.message);
        }

        majSelectionR3R4();
        btnValiderSelection.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Valider ma sélection';
    });
}
</script>
</body>
</html>
