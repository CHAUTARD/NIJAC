-- Dénormalisation de division.Division dans equipe_nationale/equipe/rencontre,
-- puis suppression de Id_Division dans ces 3 tables ET dans division elle-même
-- (Division devient la clé primaire de division).
--
-- Prérequis : les colonnes equipe.Division / equipe_nationale.Division /
-- rencontre.Division (VARCHAR(5) NOT NULL) doivent déjà exister (ajoutées
-- séparément). Ce script se contente de les remplir puis de nettoyer le
-- schéma. À exécuter via l'outil SQL libre d'E099 (DbAdminController), une
-- instruction à la fois.

-- ── Backfill ──────────────────────────────────────────────────────────────
UPDATE equipe e            JOIN division d ON d.Id_Division = e.Id_Division  SET e.Division  = d.Division;
UPDATE equipe_nationale en JOIN division d ON d.Id_Division = en.Id_Division SET en.Division = d.Division;
UPDATE rencontre r         JOIN division d ON d.Id_Division = r.Id_Division  SET r.Division  = d.Division;

-- ── equipe ────────────────────────────────────────────────────────────────
ALTER TABLE equipe DROP FOREIGN KEY fk_equipe_division;
ALTER TABLE equipe DROP INDEX uq_equipe_nom_div;
ALTER TABLE equipe ADD UNIQUE KEY uq_equipe_nom_div (Nom, Division);
ALTER TABLE equipe DROP COLUMN Id_Division;

-- ── equipe_nationale ──────────────────────────────────────────────────────
ALTER TABLE equipe_nationale DROP FOREIGN KEY fk_equipenat_division;
ALTER TABLE equipe_nationale DROP INDEX uq_nom_div;
ALTER TABLE equipe_nationale ADD UNIQUE KEY uq_nom_div (Nom(150), Division);
ALTER TABLE equipe_nationale DROP COLUMN Id_Division;
-- Contrairement à equipe/rencontre, equipe_nationale.Id_Equipe est encore NULL pour la
-- plupart des lignes tant que l'étape 3 (génération des rencontres) n'a pas tourné : on ne
-- peut donc pas remplacer Division par la relation equipe_nationale->equipe->division sans
-- casser l'affichage E017 en cours de saison. On garde la colonne Division mais on formalise
-- la relation par une vraie FK vers division(Division), pour garantir l'intégrité référentielle.
ALTER TABLE equipe_nationale ADD CONSTRAINT fk_equipenat_division FOREIGN KEY (Division) REFERENCES division(Division) ON UPDATE CASCADE;

-- ── rencontre ─────────────────────────────────────────────────────────────
-- Pas de colonne Division sur rencontre : la division se lit via l'équipe
-- domicile (rencontre.Id_EquipeDom -> equipe.Division), qui la porte déjà.
ALTER TABLE rencontre DROP FOREIGN KEY fk_rencontre_division;
ALTER TABLE rencontre DROP COLUMN Id_Division;
ALTER TABLE rencontre DROP COLUMN Division;

-- ── division : Division devient la clé primaire ──────────────────────────
ALTER TABLE division DROP PRIMARY KEY, MODIFY Id_Division INT NOT NULL;
ALTER TABLE division DROP INDEX `Division`, ADD PRIMARY KEY (Division);
ALTER TABLE division DROP COLUMN Id_Division;
