-- =============================================================================
-- NIJAC — Suppression de l'écran EN23 « Disponibilités JA Championnat Régional »
-- À exécuter UNE FOIS par environnement (local + PRODUCTION).
--
-- L'écran (route dispo-regionale-ja, DispoRegionaleJaController, vue
-- dispo_regionale_ja_index) et tout son code PHP ont été retirés : il n'était
-- raccordé à rien (aucun écran n'envoyait l'invitation, aucun ne relisait les
-- réponses). Ce script nettoie l'empreinte base restante.
--
-- Idempotent : les DROP ... IF EXISTS et le DELETE se relancent sans erreur.
-- La valeur 'Dispo régionale' de l'ENUM messagerie.Type est laissée en place
-- (convention projet : on ne retire jamais une valeur d'ENUM).
-- =============================================================================

-- FK de disponible_regionale (présentes si SQL/2026-09_fk_manquantes.sql ou EA98
-- ont été passés avant cette suppression).
ALTER TABLE disponible_regionale DROP FOREIGN KEY fk_dispreg_ja;
ALTER TABLE disponible_regionale DROP FOREIGN KEY fk_dispreg_competition;

-- Table des réponses JA (créée jadis en lazy par le contrôleur supprimé).
DROP TABLE IF EXISTS disponible_regionale;

-- Gabarit email « Dispo régionale » (message système n°8).
DELETE FROM messagerie WHERE Id_Messagerie = 8;
