-- ============================================================================
-- Migration : le « Profil / domaine de compétence » redevient un TEXTE LIBRE.
--   - agent_dpip.Profil (text) : renseigné par l'agent lui-même (Mon profil) ou
--     par l'administrateur (fiche utilisateur).
--   - besions.profil_humain (varchar) : profil recherché quand le besoin est
--     de type « humain », saisi librement.
--   - le référentiel (tables profil / profil_agent, besions.id_profil) est
--     supprimé.
-- À exécuter une seule fois :
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_profils_texte_libre.sql
-- ============================================================================

SET NAMES utf8mb4;

-- 1. agent_dpip : champ texte libre.
ALTER TABLE `agent_dpip`
  ADD COLUMN IF NOT EXISTS `Profil` text DEFAULT NULL
  COMMENT 'domaine de competence, texte libre renseigne par l agent ou l admin'
  AFTER `Contact`;

-- 2. besions : profil recherché en texte libre à la place de la clé étrangère.
ALTER TABLE `besions` DROP FOREIGN KEY IF EXISTS `besions_profil_fk`;
ALTER TABLE `besions` DROP FOREIGN KEY IF EXISTS `besions_ibfk_1`;
ALTER TABLE `besions` DROP KEY IF EXISTS `id_profil`;
ALTER TABLE `besions` DROP COLUMN IF EXISTS `id_profil`;
ALTER TABLE `besions`
  ADD COLUMN IF NOT EXISTS `profil_humain` varchar(255) DEFAULT NULL
  COMMENT 'profil recherche quand Type = humain (texte libre)'
  AFTER `Type`;

-- 3. Suppression du référentiel.
DROP TABLE IF EXISTS `profil_agent`;
DROP TABLE IF EXISTS `profil`;
