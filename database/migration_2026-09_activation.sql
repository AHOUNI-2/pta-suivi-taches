-- ============================================================================
-- Migration : première connexion — l'agent / le responsable définit lui-même
-- son mot de passe (matricule + nouveau mot de passe, sans code).
-- À exécuter une seule fois sur une base déjà installée :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_activation.sql
-- ============================================================================

-- Statut supplémentaire : compte créé mais mot de passe pas encore défini.
ALTER TABLE `agent_dpip`
  MODIFY `statut_compte` enum('actif','inactif','en_attente') NOT NULL DEFAULT 'actif';

-- Nettoyage si une version antérieure de cette migration avait ajouté un code d'activation.
ALTER TABLE `agent_dpip` DROP COLUMN IF EXISTS `code_activation`;
ALTER TABLE `agent_dpip` DROP COLUMN IF EXISTS `code_activation_expire`;
