-- ============================================================================
-- Migration : ajout du domaine de compétence (« Profil ») sur les comptes.
-- À exécuter une seule fois sur une base déjà installée :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_profil.sql
-- (Les nouvelles installations via pta_bd_suivi.sql l'incluent déjà.)
-- ============================================================================

ALTER TABLE `agent_dpip`
  ADD COLUMN `Profil` varchar(255) DEFAULT NULL
  COMMENT 'domaine de competence / profil metier de l agent'
  AFTER `Contact`;
