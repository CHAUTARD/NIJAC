'use strict';

/**
 * Filtre « Division » générique : un badge dans la barre de filtres (division
 * sélectionnée, ou « Toutes les divisions ») qui ouvre une popup Bootstrap
 * listant toutes les divisions en deux colonnes Messieurs/Dames (suffixe M/F
 * du code, convention utilisée dans tout le projet — voir
 * ImportRencontresNatController::detecterDivisionNationale()). La popup est
 * injectée une seule fois dans <body> et partagée par tous les écrans qui
 * appellent nijacDivisionFilter() sur une même page.
 *
 * @param {string}   panelSelector  sélecteur du conteneur du badge dans la barre de filtres
 * @param {string[]} codes          codes de division à proposer (ex: ['N1M','N1F','R1M',...])
 * @param {object}   opts
 * @param {function(string): string}         opts.libDivision  code -> libellé affiché ("N1M — Nationale 1 Messieurs")
 * @param {function(string): (string|null)}   opts.colorFor     code -> couleur hex du badge, ou falsy pour la couleur par défaut
 * @param {function(): string}               opts.getFiltre    code actuellement sélectionné, '' si aucun filtre
 * @param {function(string): void}           opts.onSelect     appelé avec le nouveau code ('' = toutes) au clic
 */
function nijacDivisionFilter(panelSelector, codes, opts) {
    const { libDivision, colorFor, getFiltre, onSelect } = opts;

    if (!$('#modal-divisions-global').length) {
        $('body').append(`
<div class="modal fade" id="modal-divisions-global" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a3a6b;color:#fff;">
        <h6 class="modal-title mb-0">Filtrer par division</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="modal-division-toutes" class="division-badges mb-3"></div>
        <div class="row">
          <div class="col-6">
            <div class="text-muted small fw-bold mb-1">Messieurs</div>
            <div id="modal-division-m" class="division-badges" style="flex-direction:column; align-items:stretch;"></div>
          </div>
          <div class="col-6">
            <div class="text-muted small fw-bold mb-1">Dames</div>
            <div id="modal-division-f" class="division-badges" style="flex-direction:column; align-items:stretch;"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>`);
    }

    function textColorFor(hex) {
        const c = hex.replace('#', '');
        const r = parseInt(c.substring(0, 2), 16), g = parseInt(c.substring(2, 4), 16), b = parseInt(c.substring(4, 6), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.55 ? '#111' : '#fff';
    }

    function badge(code) {
        const color = colorFor(code);
        const bg = color && /^#[0-9a-fA-F]{6}$/.test(color) ? color : '#1a3a6b';
        return $('<span class="badge">').text(libDivision(code)).css({ background: bg, color: textColorFor(bg) });
    }

    const filtre = getFiltre();

    const $panel = $(panelSelector).empty().addClass('division-badges');
    (filtre ? badge(filtre) : $('<span class="badge badge-toutes">').text('Toutes les divisions'))
        .attr('data-bs-toggle', 'modal').attr('data-bs-target', '#modal-divisions-global')
        .appendTo($panel);

    const fermerEtFiltrer = code => {
        onSelect(code);
        bootstrap.Modal.getOrCreateInstance($('#modal-divisions-global')[0]).hide();
    };

    $('#modal-division-toutes').empty().append(
        $('<span class="badge badge-toutes">').text('Toutes les divisions')
            .toggleClass('active', !filtre)
            .on('click', () => fermerEtFiltrer(''))
    );
    const $colM = $('#modal-division-m').empty();
    const $colF = $('#modal-division-f').empty();
    codes.forEach(code => {
        badge(code)
            .toggleClass('active', filtre === code)
            .on('click', () => fermerEtFiltrer(code))
            .appendTo(code.endsWith('F') ? $colF : $colM);
    });
}
