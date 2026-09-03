/**
 * NIJAC – bouton emoji 👁️ / 🙈 pour afficher / masquer un champ mot de passe.
 *
 * Chargé par _modal_mdp.php (modale « Changer le mot de passe » E006, présente
 * sur une trentaine d'écrans) et par les pages mot de passe autonomes
 * (E006 pleine page, E008 réinitialisation).
 *
 * S'applique tout seul au chargement à chaque <input type="password"> pas
 * encore équipé. Après une injection AJAX d'un formulaire, rappeler
 * nijacPwdToggle(zoneInjectee).
 */
(function () {
    'use strict';

    function equiper(input) {
        if (input.dataset.pwdToggle) return;
        input.dataset.pwdToggle = '1';

        // Regroupe l'input et le bouton dans un .input-group Bootstrap (sauf si
        // la vue en fournit déjà un).
        var group = input.parentNode;
        if (!group.classList || !group.classList.contains('input-group')) {
            group = document.createElement('div');
            group.className = 'input-group';
            input.parentNode.insertBefore(group, input);
            group.appendChild(input);
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-secondary';
        btn.tabIndex = -1;
        btn.title = 'Afficher / masquer le mot de passe';
        btn.setAttribute('aria-label', btn.title);
        btn.textContent = '👁️';
        btn.addEventListener('click', function () {
            var cache = input.type === 'password';
            input.type = cache ? 'text' : 'password';
            btn.textContent = cache ? '🙈' : '👁️';
        });
        group.appendChild(btn);
    }

    window.nijacPwdToggle = function (root) {
        (root || document).querySelectorAll('input[type="password"]').forEach(equiper);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.nijacPwdToggle(); });
    } else {
        window.nijacPwdToggle();
    }
})();
