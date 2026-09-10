-- ============================================================================
-- Migration : ajout d'un code d'identification stocké sur chaque département
-- (DPT_001, DPT_002, ...) dans la colonne `code_departement`.
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_code_departement.sql
-- ============================================================================

ALTER TABLE `departement`
  ADD COLUMN `code_departement` varchar(20) DEFAULT NULL
  COMMENT 'code d identification, ex DPT_001' AFTER `id_departement`;

-- Renseigne le code des départements déjà existants.
UPDATE `departement`
  SET `code_departement` = CONCAT('DPT_', LPAD(`id_departement`, 3, '0'))
  WHERE `code_departement` IS NULL OR `code_departement` = '';

ALTER TABLE `departement`
  MODIFY `code_departement` varchar(20) NOT NULL,
  ADD UNIQUE KEY `code_departement` (`code_departement`);
