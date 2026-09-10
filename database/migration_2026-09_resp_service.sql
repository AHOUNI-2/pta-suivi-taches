-- ============================================================================
-- Migration : fonction secondaire « Responsable Service / Sous-directeur ».
-- Une personne peut garder son rôle principal (ex. Administrateur) ET être
-- désignable comme Responsable Service / Sous-directeur pour l'attribution
-- des activités et le rattachement hiérarchique des agents.
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_resp_service.sql
-- ============================================================================

ALTER TABLE `agent_dpip`
  ADD COLUMN `est_responsable_service` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'fonction secondaire : peut etre designe comme Responsable Service / Sous-directeur'
  AFTER `role`;

-- Les comptes déjà « responsable » ont cette fonction par nature.
UPDATE `agent_dpip` SET `est_responsable_service` = 1 WHERE `role` = 'responsable';
