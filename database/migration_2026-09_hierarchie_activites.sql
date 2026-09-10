-- ============================================================================
-- Migration : hiérarchie des activités via `activites.pere_ID`.
--   activite       -> pere_ID = NULL
--   sous-activite  -> pere_ID = une activite
--   tache          -> pere_ID = une sous-activite OU une activite
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_hierarchie_activites.sql
-- ============================================================================

-- 1. Rendre la colonne nullable (une activité de tête n'a pas de parent).
ALTER TABLE `activites` MODIFY `pere_ID` int(11) DEFAULT NULL COMMENT 'activite/sous-activite parente (NULL pour une activite de tete)';

-- 2. Nettoyer les valeurs 0 héritées (aucun parent).
UPDATE `activites` SET `pere_ID` = NULL WHERE `pere_ID` = 0;

-- 3. Index + contrainte auto-référente (supprimer un parent supprime ses enfants).
ALTER TABLE `activites` ADD KEY `pere_ID` (`pere_ID`);
ALTER TABLE `activites`
  ADD CONSTRAINT `activites_pere_fk` FOREIGN KEY (`pere_ID`)
  REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE;
