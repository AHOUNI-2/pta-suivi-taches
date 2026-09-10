-- ============================================================================
-- Migration : la priorité d'une activité n'est plus figée.
--   activites.Priorite passe de enum('basse','moyenne','haute','urgente')
--   à varchar(50) : les valeurs proposées restent basse / moyenne / haute /
--   urgente, mais le responsable peut saisir une autre valeur si besoin.
-- À exécuter une seule fois :
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_priorite_libre.sql
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE `activites`
  MODIFY `Priorite` varchar(50) NOT NULL DEFAULT 'moyenne';
