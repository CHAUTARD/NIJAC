'use strict';

/* ── Conteneur global (créé une seule fois) ─────────────────────────────────── */
(function () {
    if (document.getElementById('nijac-toast-wrap')) return;
    const wrap = document.createElement('div');
    wrap.id = 'nijac-toast-wrap';
    wrap.style.cssText = [
        'position:fixed', 'top:56px', 'right:14px', 'z-index:9990',
        'display:flex', 'flex-direction:column', 'gap:7px',
        'pointer-events:none',
    ].join(';');
    document.body.appendChild(wrap);
})();

/* ── nijacToast(message, type, duration) ────────────────────────────────────────
 *  type    : 'success' | 'danger' | 'warning' | 'info'  (défaut : 'success')
 *  duration: millisecondes avant disparition             (défaut : 3 500)
 * ─────────────────────────────────────────────────────────────────────────── */
function nijacToast(message, type, duration) {
    type     = type     || 'success';
    duration = duration != null ? duration : 3500;

    const palette = {
        success : { bg: '#198754', icon: 'bi-check-circle-fill'          },
        danger  : { bg: '#dc3545', icon: 'bi-x-circle-fill'              },
        warning : { bg: '#e65100', icon: 'bi-exclamation-triangle-fill'  },
        info    : { bg: '#0d6efd', icon: 'bi-info-circle-fill'           },
    };
    const p   = palette[type] || palette.info;
    const uid = 'njt-' + Date.now() + Math.floor(Math.random() * 1000);

    const el = document.createElement('div');
    el.id = uid;
    el.style.cssText = [
        'background:' + p.bg,
        'color:#fff',
        'padding:.45rem .85rem',
        'border-radius:6px',
        'font-size:.82rem',
        'font-weight:600',
        'display:flex',
        'align-items:center',
        'gap:.45rem',
        'box-shadow:0 3px 10px rgba(0,0,0,.28)',
        'max-width:320px',
        'opacity:0',
        'transition:opacity .18s',
        'pointer-events:auto',
        'word-break:break-word',
    ].join(';');

    el.innerHTML =
        '<i class="bi ' + p.icon + '" style="font-size:.95rem;flex-shrink:0;"></i>' +
        '<span style="flex:1;">' + message + '</span>' +
        '<button onclick="document.getElementById(\'' + uid + '\').remove()" ' +
            'style="background:none;border:none;color:#fff;opacity:.65;font-size:.9rem;' +
            'margin-left:.3rem;cursor:pointer;padding:0;line-height:1;" ' +
            'title="Fermer">✕</button>';

    document.getElementById('nijac-toast-wrap').appendChild(el);
    requestAnimationFrame(function () { el.style.opacity = '1'; });

    setTimeout(function () {
        el.style.opacity = '0';
        setTimeout(function () { el.remove(); }, 200);
    }, duration);
}

/* ── nijacConfirm(message, onConfirm, onCancel, opts) ───────────────────────────
 *  Remplace window.confirm() par une modale Bootstrap centrée.
 *
 *  message   : texte affiché dans le corps (supporte \n)
 *  onConfirm : callback si l'utilisateur confirme
 *  onCancel  : callback si l'utilisateur annule (optionnel)
 *  opts      : objet optionnel {
 *                type         : 'question' (défaut) | 'danger' | 'warning'
 *                title        : titre de la modale (défaut selon type)
 *                confirmLabel : texte du bouton confirmer (défaut selon type)
 *                cancelLabel  : texte du bouton annuler  (défaut : 'Annuler')
 *              }
 *
 *  Raccourci suppression : nijacConfirm(msg, cb, null, {type:'danger'})
 * ─────────────────────────────────────────────────────────────────────────── */
function nijacConfirm(message, onConfirm, onCancel, opts) {
    opts = opts || {};

    const themes = {
        question : { bg: '#1a3a6b', icon: 'bi-question-circle-fill', btnCls: 'btn-primary',   title: 'Confirmation',  label: 'Confirmer'  },
        danger   : { bg: '#c62828', icon: 'bi-trash3-fill',          btnCls: 'btn-danger',    title: 'Suppression',   label: 'Supprimer'  },
        warning  : { bg: '#e65100', icon: 'bi-exclamation-triangle-fill', btnCls: 'btn-warning text-dark', title: 'Attention', label: 'Continuer' },
    };
    const t = themes[opts.type] || themes.question;

    const title        = opts.title        || t.title;
    const confirmLabel = opts.confirmLabel || t.label;
    const cancelLabel  = opts.cancelLabel  || 'Annuler';

    const mid = 'nijac-confirm-modal';
    let modal = document.getElementById(mid);

    if (!modal) {
        modal = document.createElement('div');
        modal.id        = mid;
        modal.className = 'modal fade';
        modal.tabIndex  = -1;
        modal.innerHTML =
            '<div class="modal-dialog modal-dialog-centered modal-sm">' +
            '  <div class="modal-content shadow-lg">' +
            '    <div class="modal-header py-2" id="' + mid + '-header">' +
            '      <h6 class="modal-title mb-0" id="' + mid + '-title"></h6>' +
            '      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>' +
            '    </div>' +
            '    <div class="modal-body py-3 px-3" id="' + mid + '-body" style="font-size:.88rem;white-space:pre-wrap;"></div>' +
            '    <div class="modal-footer py-2 gap-2 justify-content-end">' +
            '      <button class="btn btn-sm btn-secondary" id="' + mid + '-cancel" data-bs-dismiss="modal"></button>' +
            '      <button class="btn btn-sm"               id="' + mid + '-ok"></button>' +
            '    </div>' +
            '  </div>' +
            '</div>';
        document.body.appendChild(modal);
    }

    // Mise à jour dynamique du thème et des textes
    const header = document.getElementById(mid + '-header');
    header.style.background = t.bg;
    header.style.color      = '#fff';

    document.getElementById(mid + '-title').innerHTML =
        '<i class="bi ' + t.icon + ' me-2"></i>' + title;

    document.getElementById(mid + '-body').textContent = message;

    const okBtn = document.getElementById(mid + '-ok');
    okBtn.className  = 'btn btn-sm ' + t.btnCls;
    okBtn.textContent = confirmLabel;

    document.getElementById(mid + '-cancel').textContent = cancelLabel;

    // Remplacer les boutons pour éviter l'accumulation de listeners
    const newOk     = okBtn.cloneNode(true);
    const oldCancel = document.getElementById(mid + '-cancel');
    const newCancel = oldCancel.cloneNode(true);
    okBtn.replaceWith(newOk);
    oldCancel.replaceWith(newCancel);

    const bsModal = new bootstrap.Modal(modal);

    let confirmed = false;

    newOk.addEventListener('click', function () {
        confirmed = true;
        bsModal.hide();
        if (typeof onConfirm === 'function') onConfirm();
    });

    modal.addEventListener('hidden.bs.modal', function () {
        if (!confirmed && typeof onCancel === 'function') onCancel();
    }, { once: true });

    bsModal.show();
    setTimeout(function () { newOk.focus(); }, 300);
}
