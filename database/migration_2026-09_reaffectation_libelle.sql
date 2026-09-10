-- ============================================================================
-- Migration (données) : recalcule le libellé des réaffectations déjà tracées
-- pour faire apparaître l'ANCIEN et le NOUVEL agent —
--   « Réaffectée de <ancien> à <nouveau> — cause : <cause> »
-- Les nouvelles réaffectations écrivent déjà ce format ; ici on met à niveau
-- l'historique (`journal_activites`) et le journal global (`historique_modification`)
-- à partir de la table structurée `reafectation`.
-- Idempotent : peut être relancé sans effet de bord.
--
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_reaffectation_libelle.sql
-- ============================================================================

SET NAMES utf8mb4;

UPDATE `journal_activites` j
  JOIN `reafectation` r
    ON r.`ID_activites` = j.`ID_activites`
   AND ABS(TIMESTAMPDIFF(SECOND, r.`Date_creation`, j.`Date_action`)) <= 5
  LEFT JOIN `agent_dpip` aa ON aa.`ID_agent_dpip` = r.`ID_ancien_agent`
  LEFT JOIN `agent_dpip` na ON na.`ID_agent_dpip` = r.`ID_nouvel_agent`
  LEFT JOIN `cause_reafectation` c ON c.`id_cause` = r.`id_cause`
  SET j.`Detail` = CONCAT(
        'Réaffectée de ',
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(aa.`Prenom`,''),' ',COALESCE(aa.`Nom`,''))), ''), '—'),
        ' à ',
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(na.`Prenom`,''),' ',COALESCE(na.`Nom`,''))), ''), '—'),
        IF(c.`libelle` IS NOT NULL, CONCAT(' — cause : ', c.`libelle`), ''))
  WHERE j.`Action` = 'reafectation';

UPDATE `historique_modification` h
  JOIN `reafectation` r
    ON r.`ID_activites` = h.`id_enregistrement`
   AND ABS(TIMESTAMPDIFF(SECOND, r.`Date_creation`, h.`date_heure`)) <= 5
  LEFT JOIN `agent_dpip` aa ON aa.`ID_agent_dpip` = r.`ID_ancien_agent`
  LEFT JOIN `agent_dpip` na ON na.`ID_agent_dpip` = r.`ID_nouvel_agent`
  LEFT JOIN `cause_reafectation` c ON c.`id_cause` = r.`id_cause`
  SET h.`details` = CONCAT(
        'Réaffectée de ',
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(aa.`Prenom`,''),' ',COALESCE(aa.`Nom`,''))), ''), '—'),
        ' à ',
        COALESCE(NULLIF(TRIM(CONCAT(COALESCE(na.`Prenom`,''),' ',COALESCE(na.`Nom`,''))), ''), '—'),
        IF(c.`libelle` IS NOT NULL, CONCAT(' — cause : ', c.`libelle`), ''))
  WHERE h.`table_concernee` = 'activites' AND h.`action` = 'reafectation';
