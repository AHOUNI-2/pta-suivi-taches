-- ============================================================================
-- Migration (données) : la progression d'une activité / sous-activité / tâche
-- est désormais CALCULÉE AUTOMATIQUEMENT.
--   - feuille (aucun élément rattaché) : progression = fonction du statut
--       termine=100 · en_cours/en_retard=50 · en_attente=25 · a_faire/annule=0
--   - branche : moyenne de la progression de ses enfants directs
--       (les enfants « annulé » sont exclus)
-- L'agent ne saisit plus de pourcentage ; il ne change que le statut.
-- Le code recalcule à chaque changement (voir majProgression() dans functions.php).
--
-- Ce script met à niveau les données existantes. Idempotent.
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_progression_auto.sql
-- ============================================================================

SET NAMES utf8mb4;

-- 1) Tout le monde d'abord comme une feuille (d'après le statut).
UPDATE `activites` SET `Progression` = CASE `Statut`
    WHEN 'termine'    THEN 100
    WHEN 'en_cours'   THEN 50
    WHEN 'en_retard'  THEN 50
    WHEN 'en_attente' THEN 25
    ELSE 0
END;

-- 2) Puis on remonte la moyenne des enfants dans les parents.
--    3 passes = suffisant pour la hiérarchie activité > sous-activité > tâche.
UPDATE `activites` p
  JOIN (SELECT `pere_ID`, ROUND(AVG(`Progression`)) AS moy
        FROM `activites` WHERE `pere_ID` IS NOT NULL AND `Statut` <> 'annule'
        GROUP BY `pere_ID`) x ON x.`pere_ID` = p.`ID__activites`
  SET p.`Progression` = x.moy;

UPDATE `activites` p
  JOIN (SELECT `pere_ID`, ROUND(AVG(`Progression`)) AS moy
        FROM `activites` WHERE `pere_ID` IS NOT NULL AND `Statut` <> 'annule'
        GROUP BY `pere_ID`) x ON x.`pere_ID` = p.`ID__activites`
  SET p.`Progression` = x.moy;

UPDATE `activites` p
  JOIN (SELECT `pere_ID`, ROUND(AVG(`Progression`)) AS moy
        FROM `activites` WHERE `pere_ID` IS NOT NULL AND `Statut` <> 'annule'
        GROUP BY `pere_ID`) x ON x.`pere_ID` = p.`ID__activites`
  SET p.`Progression` = x.moy;
