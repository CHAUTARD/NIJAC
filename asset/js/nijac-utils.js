/**
 * NIJAC – utilitaires JS partagés (chargé par les vues avant leur script).
 * escHtml(s) : échappe &, <, > et " pour insertion dans du HTML (null/undefined → '').
 */
function escHtml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
