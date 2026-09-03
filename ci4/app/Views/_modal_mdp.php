<?php
/**
 * NIJAC – Fenêtre modale "Changer le mot de passe"
 *
 * Partagée par toutes les vues CI4 (portage de includes/modal_mdp.php).
 * Le lien déclencheur doit avoir : id="lnk-chg-pwd" et data-base="<?= site_url('changer-mot-de-passe') ?>".
 * Cible ChangerMotDePasseController (E006), portage CI4 de
 * changer_mot_de_passe.php.
 *
 * Usage : <?php require __DIR__ . '/_modal_mdp.php'; ?>
 */
?>
<!-- Modale changement de mot de passe -->
<div class="modal fade" id="modal-mdp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--nijac-blue, #1a3a6b);color:#fff;">
                <h5 class="modal-title"><i class="bi bi-key-fill me-2"></i>Changer le mot de passe</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="modal-mdp-body">
                <div class="text-center text-muted py-4">
                    <i class="bi bi-hourglass-split me-1"></i>Chargement…
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= base_url('asset/js/nijac-pwd-toggle.js') ?>"></script>
<script>
(function () {
    var lien    = document.getElementById('lnk-chg-pwd');
    var modalEl = document.getElementById('modal-mdp');
    var body    = document.getElementById('modal-mdp-body');
    if (!lien || !modalEl || !body) return;

    var base = lien.dataset.base;

    function chargerForm() {
        body.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-hourglass-split me-1"></i>Chargement…</div>';
        fetch(base, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.text(); })
            .then(function (html) { body.innerHTML = html; window.nijacPwdToggle(body); })
            .catch(function () { body.innerHTML = '<div class="text-danger">Erreur de chargement.</div>'; });
    }

    lien.addEventListener('click', function (e) {
        e.preventDefault();
        chargerForm();
        new bootstrap.Modal(modalEl).show();
    });

    body.addEventListener('submit', function (e) {
        if (e.target.id !== 'form-mdp') return;
        e.preventDefault();
        var form = e.target;
        var btn  = form.querySelector('button[type=submit]');
        btn.disabled = true;

        fetch(base, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            var zone = body.querySelector('#lbl-status');
            if (zone) {
                zone.className  = 'mb-3 ' + (res.ok ? 'text-success' : 'text-danger');
                zone.innerHTML  = '<i class="bi bi-' + (res.ok ? 'check-circle-fill' : 'exclamation-triangle-fill') + ' me-1"></i>' + res.msg;
            }
            if (res.ok) {
                form.querySelectorAll('input[type=password]').forEach(function (i) { i.value = ''; });
                document.querySelectorAll('.ts-pwd-warning').forEach(function (a) { a.style.display = 'none'; });
                setTimeout(function () {
                    var inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }, 1200);
            } else {
                btn.disabled = false;
            }
        })
        .catch(function () {
            btn.disabled = false;
        });
    });
})();
</script>
