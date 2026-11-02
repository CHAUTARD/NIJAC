// Protection CSRF : injecte automatiquement le token dans tous les appels jQuery AJAX
// No-op si jQuery n'est pas chargé sur la page (certaines pages utilisent fetch() nativement).
(function ($) {
    'use strict';
    if (!$) return;
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token && token.content) {
        $.ajaxSetup({
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-CSRF-Token', token.content);
            }
        });
    }

    // L'hébergeur de production refuse PUT / PATCH / DELETE (403 vide, avant CodeIgniter).
    // Ces appels partent donc en POST avec le champ `_method`, que CodeIgniter 4 retransforme
    // en PUT / PATCH / DELETE (CodeIgniter::spoofRequestMethod()) : routes et contrôleurs
    // inchangés. Prefilter = après sérialisation des données, avant l'envoi.
    $.ajaxPrefilter(function (options) {
        var methode = String(options.type || '').toUpperCase();
        if (methode !== 'PUT' && methode !== 'PATCH' && methode !== 'DELETE') return;
        if (options.data != null && typeof options.data !== 'string') return; // FormData… : laissé tel quel
        options.type = 'POST';
        options.data = (options.data ? options.data + '&' : '') + '_method=' + methode;
    });
}(window.jQuery));
