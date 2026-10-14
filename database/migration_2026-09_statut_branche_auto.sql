-- ============================================================================
-- Migration (données) : le STATUT d'une activité / sous-activité COMPOSÉE
-- (qui a des éléments rattachés) devient dynamique, déduit de sa progression :
--     100 %           -> terminé
--     0 %             -> à faire
--     échéance passée -> en retard
--     sinon           -> en cours
-- Une branche « annulé » manuellement le reste.
-- Le statut des tâches / activités SANS élément rattaché n'est PAS touché
-- (c'est l'agent qui le fixe via le compte rendu).
--
-- À lancer APRÈS migration_2026-09_progression_auto.sql (utilise Progression).
-- Idempotent.
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_statut_branche_auto.sql
-- ============================================================================

SET NAMES utf8mb4;

UPDATE `activites` p
  JOIN (SELECT DISTINCT `pere_ID` FROM `activites` WHERE `pere_ID` IS NOT NULL) b
    ON b.`pere_ID` = p.`ID__activites`
  SET p.`Statut` = CASE
        WHEN p.`Progression` >= 100      THEN 'termine'
        WHEN p.`Progression` = 0         THEN 'a_faire'
        WHEN p.`Date_fin` < CURDATE()    THEN 'en_retard'
        ELSE 'en_cours'
      END
  WHERE p.`Statut` <> 'annule';
