-- =============================================================================
-- NIJAC — Gabarit « Liste nomination » (messagerie.Id_Messagerie = 4) en HTML
-- À exécuter UNE FOIS par environnement (local + PRODUCTION).
--
-- EN15 injecte le tableau {LISTE_NOMINATIONS} dans le corps : l'email part donc
-- en HTML et les retours ligne d'un gabarit en texte brut étaient avalés par le
-- client mail. On passe le gabarit stocké en HTML (comme le message n°6
-- Réengagements) : le texte encadre proprement le tableau, police alignée sur
-- celle du tableau généré (Arial 13px).
--
-- Idempotent : réexécutable, écrase toujours par la même valeur.
-- =============================================================================

UPDATE messagerie
   SET Message = '<div style="font-family:Arial,sans-serif;font-size:13px;">
Bonjour {PRENOM} {NOM},<br><br>
Ci-joint le récapitulatif des arbitrages pour cette phase :<br><br>
{LISTE_NOMINATIONS}<br><br>
Cordialement<br>
{UTI_PRENOM} {UTI_NOM}
</div>'
 WHERE Id_Messagerie = 4;
