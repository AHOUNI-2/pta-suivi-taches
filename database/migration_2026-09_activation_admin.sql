-- ============================================================================
-- Migration : le compte doit d'abord être ACTIVÉ par l'administrateur, puis
-- l'utilisateur définit son mot de passe (première connexion), puis il se
-- connecte normalement.
--   non_active  -> compte créé, pas encore activé par l'admin
--   en_attente  -> activé par l'admin, mot de passe à définir
--   actif       -> mot de passe défini, connexion possible
--   inactif     -> compte désactivé
-- À exécuter une seule fois :
--   mysql -u root -p pta_bd_suivi < database/migration_2026-09_activation_admin.sql
-- ============================================================================

ALTER TABLE `agent_dpip`
  MODIFY `statut_compte` enum('actif','inactif','en_attente','non_active') NOT NULL DEFAULT 'actif';
