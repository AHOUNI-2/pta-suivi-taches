-- ============================================================================
-- Migration : distinguer « Responsable Service » et « Sous-directeur » au sein
-- du rôle « responsable » (mêmes droits, libellé différent).
--   type_responsable = 'service'        -> Responsable Service
--   type_responsable = 'sous_directeur' -> Sous-directeur
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_type_responsable.sql
-- ============================================================================

ALTER TABLE `agent_dpip`
  ADD COLUMN `type_responsable` enum('service','sous_directeur') DEFAULT NULL
  COMMENT 'precise le type quand role = responsable' AFTER `role`;

UPDATE `agent_dpip` SET `type_responsable` = 'service' WHERE `role` = 'responsable';
