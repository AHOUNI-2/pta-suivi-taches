-- ============================================================================
-- Migration : fonction secondaire « Agent (peut recevoir des activités) ».
-- Une personne peut garder son rôle principal (ex. Administrateur) ET recevoir
-- des activités de son Responsable Service, comme un agent.
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_fonction_agent.sql
-- ============================================================================

ALTER TABLE `agent_dpip`
  ADD COLUMN `est_agent` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'fonction secondaire : peut recevoir des activites comme un agent'
  AFTER `est_responsable_service`;

-- Les comptes déjà « agent » ont cette fonction par nature.
UPDATE `agent_dpip` SET `est_agent` = 1 WHERE `role` = 'agent';
