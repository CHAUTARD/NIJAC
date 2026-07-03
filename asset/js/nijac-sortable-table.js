'use strict';

/**
 * Attache le tri par clic sur les en-têtes <th data-*="..."> d'un tableau.
 * Bascule .sort-asc / .sort-desc sur l'en-tête actif et rappelle onSort() pour
 * que la page réordonne / réaffiche ses données (le tri des données reste
 * spécifique à chaque page, seule la mécanique de clic/état est mutualisée ici).
 *
 * @param {string}   theadSelector  sélecteur CSS des <th> triables, ex: '#tbl-clubs thead th[data-col]'
 * @param {string}   attrName       nom de l'attribut data-* portant l'id de colonne, ex: 'col' ou 'field'
 * @param {{col: (string|number|null), asc: boolean}} state  état de tri, muté en place par ce helper
 * @param {function(): void} onSort callback appelé après chaque bascule d'état (re-tri/rendu côté page)
 * @param {boolean}  [newColAsc=true]  sens par défaut au premier clic sur une nouvelle colonne
 *          (mettre false pour des colonnes numériques où "plus grand d'abord" est plus utile, ex: statistiques)
 * @returns {function(): void} refresh — réapplique .sort-asc/.sort-desc selon l'état courant
 *          (utile après un rendu qui recrée les <th>, ou pour resynchroniser sans clic)
 */
function nijacSortableTable(theadSelector, attrName, state, onSort, newColAsc) {
    if (newColAsc === undefined) newColAsc = true;
    const headers = document.querySelectorAll(theadSelector);

    function applyClasses() {
        headers.forEach(th => {
            const col = th.getAttribute('data-' + attrName);
            th.classList.toggle('sort-asc',  col == state.col &&  state.asc);
            th.classList.toggle('sort-desc', col == state.col && !state.asc);
        });
    }

    headers.forEach(th => {
        th.addEventListener('click', () => {
            const col = th.getAttribute('data-' + attrName);
            if (state.col == col) state.asc = !state.asc;
            else { state.col = col; state.asc = newColAsc; }
            applyClasses();
            onSort();
        });
    });

    applyClasses();
    return applyClasses;
}

/**
 * Réordonne directement les <tr> d'un tbody selon le texte de leur cellule à
 * l'index colIndex (détection auto numérique/texte). Pratique pour les pages
 * liste + formulaire qui n'ont pas de tableau de données JS côté client à
 * retrier (contrairement aux grilles éditables qui ont leur propre rendu).
 *
 * @param {string}  tbodySelector  sélecteur CSS du <tbody>, ex: '#tbody-liste'
 * @param {number}  colIndex       index (0-based) de la cellule <td> à comparer
 * @param {boolean} asc            sens du tri
 */
function nijacSortRows(tbodySelector, colIndex, asc) {
    const tbody = document.querySelector(tbodySelector);
    if (!tbody) return;
    // Une ligne de données a toujours un attribut data-* (data-id, data-code…) pour
    // sa sélection ; les lignes "aucun résultat" n'en ont pas et sont donc ignorées.
    const rows = Array.from(tbody.children).filter(tr =>
        tr.tagName === 'TR' && Array.from(tr.attributes).some(attr => attr.name.indexOf('data-') === 0)
    );
    rows.sort((a, b) => {
        const va = (a.children[colIndex] ? a.children[colIndex].textContent : '').trim();
        const vb = (b.children[colIndex] ? b.children[colIndex].textContent : '').trim();
        const na = parseFloat(va.replace(',', '.'));
        const nb = parseFloat(vb.replace(',', '.'));
        const cmp = (va !== '' && vb !== '' && !isNaN(na) && !isNaN(nb))
            ? na - nb
            : va.localeCompare(vb, 'fr');
        return asc ? cmp : -cmp;
    });
    rows.forEach(tr => tbody.appendChild(tr));
}
