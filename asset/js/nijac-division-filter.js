'use strict';

/**
 * Popup « Division » générique : un badge (division sélectionnée, ou une
 * valeur par défaut) qui ouvre une popup Bootstrap listant toutes les
 * divisions en deux colonnes Messieurs/Dames (suffixe M/F du code,
 * convention utilisée dans tout le projet — voir
 * ImportRencontresNatController::detecterDivisionNationale()). Utilisée à la
 * fois pour filtrer une liste (comportement par défaut, avec une entrée
 * « Toutes les divisions ») et pour saisir la division d'un formulaire
 * (opts.showToutes: false). La popup est injectée une seule fois dans <body>
 * et partagée par tous les écrans/panneaux qui appellent
 * nijacDivisionFilter() sur une même page ; son contenu est reconstruit à
 * l'ouverture (show.bs.modal) pour le panneau qui l'a déclenchée, ce qui
 * permet à plusieurs panneaux (ex: filtre + saisie) de coexister sur le
 * même écran sans se marcher dessus.
 *
 * @param {string}   panelSelector  sélecteur du conteneur du badge
 * @param {string[]} codes          codes de division à proposer (ex: ['N1M','N1F','R1M',...])
 * @param {object}   opts
 * @param {function(string): string}         opts.libDivision  code -> libellé affiché ("N1M — Nationale 1 Messieurs")
 * @param {function(string): (string|null)}   opts.colorFor     code -> couleur hex du badge, ou falsy pour la couleur par défaut
 * @param {function(): string}               opts.getFiltre    code actuellement sélectionné, '' si aucun
 * @param {function(string): void}           opts.onSelect     appelé avec le nouveau code ('' = toutes, filtre uniquement) au clic
 * @param {boolean}  [opts.showToutes=true]  false pour une saisie obligatoire (pas d'entrée « Toutes »)
 * @param {string}   [opts.placeholder]      libellé du badge quand rien n'est sélectionné (défaut : « Toutes les divisions » / « Choisir une division » selon showToutes)
 */
function nijacDivisionFilter(panelSelector, codes, opts) {
    const { libDivision, colorFor, getFiltre, onSelect, showToutes = true } = opts;
    const placeholder = opts.placeholder || (showToutes ? 'Toutes les divisions' : 'Choisir une division');

    if (!$('#modal-divisions-global').length) {
        $('body').append(`
<div class="modal fade" id="modal-divisions-global" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header py-2" style="background:#1a3a6b;color:#fff;">
        <h6 class="modal-title mb-0">Division</h6>
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
        $('#modal-divisions-global').on('show.bs.modal', function (e) {
            const populate = $(e.relatedTarget).data('nijacPopulateDivisionModal');
            if (populate) populate();
        });
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

    const fermerEtFiltrer = code => {
        onSelect(code);
        bootstrap.Modal.getOrCreateInstance($('#modal-divisions-global')[0]).hide();
    };

    function populate() {
        const filtre = getFiltre();
        $('#modal-divisions-global .modal-title').text(showToutes ? 'Filtrer par division' : 'Choisir une division');

        const $toutes = $('#modal-division-toutes').empty();
        if (showToutes) {
            $toutes.append(
                $('<span class="badge badge-toutes">').text('Toutes les divisions')
                    .toggleClass('active', !filtre)
                    .on('click', () => fermerEtFiltrer(''))
            );
        }
        const $colM = $('#modal-division-m').empty();
        const $colF = $('#modal-division-f').empty();
        codes.forEach(code => {
            badge(code)
                .toggleClass('active', filtre === code)
                .on('click', () => fermerEtFiltrer(code))
                .appendTo(code.endsWith('F') ? $colF : $colM);
        });
    }

    const filtre = getFiltre();
    const $panel = $(panelSelector).empty().addClass('division-badges');
    (filtre ? badge(filtre) : $('<span class="badge badge-toutes">').text(placeholder))
        .attr('data-bs-toggle', 'modal').attr('data-bs-target', '#modal-divisions-global')
        .data('nijacPopulateDivisionModal', populate)
        .appendTo($panel);
}
