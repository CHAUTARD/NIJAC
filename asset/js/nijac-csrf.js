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
}(window.jQuery));
