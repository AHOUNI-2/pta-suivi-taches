-- ============================================================================
-- Migration : dans `activites`, la colonne `Description` devient `Observation`
-- (champ texte libre permettant de porter une observation sur l'activité).
-- À exécuter une seule fois sur une base déjà installée :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_observation_activite.sql
-- ============================================================================

ALTER TABLE `activites`
  CHANGE COLUMN `Description` `Observation` text DEFAULT NULL
  COMMENT 'observation / note libre sur l activite';
