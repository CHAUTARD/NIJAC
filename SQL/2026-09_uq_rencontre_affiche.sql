-- =============================================================================
-- NIJAC — Anti-doublons rencontre (prod)
--
-- Contexte : les imports EA82 (FFTT direct) et EA83 (national) ne dédupliquaient
-- que via un SELECT applicatif, sans verrou ni contrainte. Deux exécutions
-- concurrentes (double-clic, rejeu réseau, import FFTT + national sur la même
-- division N*) créaient deux lignes rencontre identiques à l'Id_Rencontre près.
--
-- Correctif code : clé UNIQUE uq_rencontre_affiche (Id_EquipeDom, Id_EquipeExt,
-- Phase) + INSERT ... ON DUPLICATE KEY UPDATE dans les deux imports. La même clé
-- est déjà l'invariant utilisé par l'écran EA95 (RencontreAdminController::doublons()).
--
-- Id_EquipeExt NULL (exempt / bye) : exclu ici, MySQL autorise plusieurs NULL
-- dans un index UNIQUE — pas de collision, rien à purger sur ces lignes.
--
-- Ordre d'exécution en prod (phpMyAdmin) : étapes 1 et 2 d'abord (lecture seule),
-- puis 3, puis 4. Ne PAS enchaîner en aveugle.
-- =============================================================================


-- 1. DIAGNOSTIC — liste les affiches en double (ne modifie rien).
SELECT Id_EquipeDom, Id_EquipeExt, Phase,
       COUNT(*)                                        AS n,
       GROUP_CONCAT(Id_Rencontre ORDER BY Id_Rencontre) AS ids
FROM rencontre
WHERE Id_EquipeExt IS NOT NULL
GROUP BY Id_EquipeDom, Id_EquipeExt, Phase
HAVING n > 1;


-- 2. CONTRÔLE — les lignes en trop (tout sauf le plus petit Id de chaque groupe)
--    portent-elles une nomination ou une disponibilité ?
--    Si cette requête renvoie des lignes : NE PAS lancer l'étape 3 telle quelle.
--    Rebrancher d'abord nomination/disponible sur l'Id conservé (g.garder),
--    puis supprimer la ligne en trop à la main.
SELECT r.Id_Rencontre,
       (SELECT COUNT(*) FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre) AS nb_nominations,
       (SELECT COUNT(*) FROM disponible d WHERE d.Id_Rencontre = r.Id_Rencontre) AS nb_disponibilites
FROM rencontre r
JOIN (
    SELECT Id_EquipeDom, Id_EquipeExt, Phase, MIN(Id_Rencontre) AS garder
    FROM rencontre
    WHERE Id_EquipeExt IS NOT NULL
    GROUP BY Id_EquipeDom, Id_EquipeExt, Phase
    HAVING COUNT(*) > 1
) g ON g.Id_EquipeDom = r.Id_EquipeDom
   AND g.Id_EquipeExt = r.Id_EquipeExt
   AND g.Phase        = r.Phase
   AND r.Id_Rencontre <> g.garder;


-- 3. PURGE — supprime les doublons sans nomination ni disponibilité,
--    en conservant le plus petit Id_Rencontre de chaque groupe.
--    (Sous-requête matérialisée : autorisé par MySQL malgré la même table.)
DELETE r FROM rencontre r
JOIN (
    SELECT Id_EquipeDom, Id_EquipeExt, Phase, MIN(Id_Rencontre) AS garder
    FROM rencontre
    WHERE Id_EquipeExt IS NOT NULL
    GROUP BY Id_EquipeDom, Id_EquipeExt, Phase
    HAVING COUNT(*) > 1
) g ON g.Id_EquipeDom = r.Id_EquipeDom
   AND g.Id_EquipeExt = r.Id_EquipeExt
   AND g.Phase        = r.Phase
   AND r.Id_Rencontre <> g.garder
WHERE NOT EXISTS (SELECT 1 FROM nomination n WHERE n.Id_Rencontre = r.Id_Rencontre)
  AND NOT EXISTS (SELECT 1 FROM disponible d WHERE d.Id_Rencontre = r.Id_Rencontre);


-- 4. CONTRAINTE — échoue s'il reste des doublons (revenir à l'étape 1).
--    Équivalent à ce que initTableConfiguration() tentera au prochain chargement
--    d'EA98 ; le faire ici évite d'attendre ce déclencheur.
ALTER TABLE rencontre
  ADD UNIQUE KEY uq_rencontre_affiche (Id_EquipeDom, Id_EquipeExt, Phase);
