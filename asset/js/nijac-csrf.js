// Protection CSRF : injecte automatiquement le token dans tous les appels jQuery AJAX
(function ($) {
    'use strict';
    var token = document.querySelector('meta[name="csrf-token"]');
    if (token && token.content) {
        $.ajaxSetup({
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-CSRF-Token', token.content);
            }
        });
    }
}(jQuery));
