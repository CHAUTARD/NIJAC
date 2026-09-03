-- =============================================================================
-- NIJAC — Ajout des clés étrangères implicites manquantes
-- À exécuter UNE FOIS sur la base de PRODUCTION.
--
-- Contexte : le schéma déclare déjà 20 FK ; 5 relations restaient implicites
-- (colonnes sans contrainte). Ce script les matérialise pour l'intégrité
-- référentielle et pour que le MCD (phpMyAdmin Concepteur / Workbench) soit
-- complet.
--
-- Équivalent code : config/app_config.php -> initTableConfiguration() ajoute
-- ces 5 FK de façon idempotente au chargement d'EA98. Ce fichier est la
-- version manuelle (FTP / phpMyAdmin) pour la prod.
--
-- Prérequis : toutes les tables sont en InnoDB (déjà le cas en prod).
-- =============================================================================


-- -----------------------------------------------------------------------------
-- ÉTAPE 1 — CONTRÔLES DE PRÉ-VOL
-- Les 5 requêtes doivent renvoyer nb = 0. Si l'une renvoie > 0, corriger les
-- lignes concernées (rattacher au bon parent ou passer la colonne à NULL)
-- AVANT de lancer l'étape 3, sinon l'ALTER correspondant échoue.
-- -----------------------------------------------------------------------------
SELECT 'equipe.Id_Club2 -> club'                       AS controle,
       COUNT(*) AS nb
  FROM equipe e
  LEFT JOIN club c ON c.Id_Club = e.Id_Club2
 WHERE e.Id_Club2 IS NOT NULL AND e.Id_Club2 <> '' AND c.Id_Club IS NULL;

SELECT 'equipe.Id_Club3 -> club'                       AS controle,
       COUNT(*) AS nb
  FROM equipe e
  LEFT JOIN club c ON c.Id_Club = e.Id_Club3
 WHERE e.Id_Club3 IS NOT NULL AND e.Id_Club3 <> '' AND c.Id_Club IS NULL;

SELECT 'ja.CodeDept -> departement'                    AS controle,
       COUNT(*) AS nb
  FROM ja j
  LEFT JOIN departement d ON d.CodeDept = j.CodeDept
 WHERE j.CodeDept IS NOT NULL AND j.CodeDept <> '' AND d.CodeDept IS NULL;

SELECT 'disponible_regionale.Id_JA -> ja'              AS controle,
       COUNT(*) AS nb
  FROM disponible_regionale r
  LEFT JOIN ja j ON j.Id_JA = r.Id_JA
 WHERE j.Id_JA IS NULL;

SELECT 'disponible_regionale.Id_CompetitionRegionale -> competition_regionale' AS controle,
       COUNT(*) AS nb
  FROM disponible_regionale r
  LEFT JOIN competition_regionale k ON k.Id_CompetitionRegionale = r.Id_CompetitionRegionale
 WHERE k.Id_CompetitionRegionale IS NULL;


-- -----------------------------------------------------------------------------
-- ÉTAPE 2 — NORMALISATION (chaîne vide -> NULL sur les colonnes SET NULL)
-- Sans ça, une valeur '' ferait échouer l'ALTER (pas de club/dept de code '').
-- -----------------------------------------------------------------------------
UPDATE equipe SET Id_Club2 = NULL WHERE Id_Club2 = '';
UPDATE equipe SET Id_Club3 = NULL WHERE Id_Club3 = '';
UPDATE ja     SET CodeDept = NULL WHERE CodeDept = '';


-- -----------------------------------------------------------------------------
-- ÉTAPE 3 — AJOUT DES CONTRAINTES
-- -----------------------------------------------------------------------------

-- Ententes de clubs : 2e et 3e club d'une équipe. SET NULL — le départ d'un
-- club partenaire ne doit pas supprimer l'équipe.
ALTER TABLE equipe
  ADD CONSTRAINT fk_equipe_club2 FOREIGN KEY (Id_Club2) REFERENCES club (Id_Club)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE equipe
  ADD CONSTRAINT fk_equipe_club3 FOREIGN KEY (Id_Club3) REFERENCES club (Id_Club)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Département de rattachement du JA (fallback à côté de Id_LaPoste / LEFT(Cp,2)).
-- Même politique que utilisateur.Id_Departement et equipe_nationale.CodeDept.
ALTER TABLE ja
  ADD CONSTRAINT fk_ja_departement FOREIGN KEY (CodeDept) REFERENCES departement (CodeDept)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Disponibilités championnat régional (EN23). CASCADE — même règle que
-- fk_disponible_ja, et la purge par date d'EA84 est déjà une cascade applicative.
ALTER TABLE disponible_regionale
  ADD CONSTRAINT fk_dispreg_ja FOREIGN KEY (Id_JA) REFERENCES ja (Id_JA)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE disponible_regionale
  ADD CONSTRAINT fk_dispreg_competition FOREIGN KEY (Id_CompetitionRegionale) REFERENCES competition_regionale (Id_CompetitionRegionale)
  ON DELETE CASCADE ON UPDATE CASCADE;


-- -----------------------------------------------------------------------------
-- ÉTAPE 4 — VÉRIFICATION (doit lister les 5 lignes)
-- -----------------------------------------------------------------------------
SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
  FROM information_schema.KEY_COLUMN_USAGE
 WHERE TABLE_SCHEMA = DATABASE()
   AND CONSTRAINT_NAME IN ('fk_equipe_club2','fk_equipe_club3','fk_ja_departement',
                           'fk_dispreg_ja','fk_dispreg_competition')
 ORDER BY TABLE_NAME, CONSTRAINT_NAME;


-- =============================================================================
-- ROLLBACK (à décommenter uniquement en cas de besoin)
-- =============================================================================
-- ALTER TABLE equipe               DROP FOREIGN KEY fk_equipe_club2;
-- ALTER TABLE equipe               DROP FOREIGN KEY fk_equipe_club3;
-- ALTER TABLE ja                   DROP FOREIGN KEY fk_ja_departement;
-- ALTER TABLE disponible_regionale DROP FOREIGN KEY fk_dispreg_ja;
-- ALTER TABLE disponible_regionale DROP FOREIGN KEY fk_dispreg_competition;
