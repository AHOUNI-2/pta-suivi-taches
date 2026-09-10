-- ============================================================================
-- Migration : nouveau rôle « directeur » + délégation à une secrétaire.
--   * role : ajout de 'directeur' (au-dessus de 'responsable' = sous-directeur)
--   * ID_secretaire : compte désigné par un directeur pour agir en son nom
--     pendant une absence.
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_directeur.sql
-- ============================================================================

ALTER TABLE `agent_dpip`
  MODIFY `role` enum('admin','directeur','responsable','agent') NOT NULL DEFAULT 'agent';

ALTER TABLE `agent_dpip`
  ADD COLUMN `ID_secretaire` int(11) DEFAULT NULL
    COMMENT 'compte designe par un directeur pour agir en son nom' AFTER `ID_responsable`,
  ADD KEY `ID_secretaire` (`ID_secretaire`),
  ADD CONSTRAINT `agent_dpip_secretaire_fk` FOREIGN KEY (`ID_secretaire`)
    REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;
