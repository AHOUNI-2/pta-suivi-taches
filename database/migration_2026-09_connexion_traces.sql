-- ============================================================================
-- Migration : traçabilité / sécurité.
--   connexion                        : journal des connexions & déconnexions
--                                      des comptes (horodatage, IP, origine).
--   journal_activites.id_enregistrement : « numéro de dossier » = id de
--                                      l'activité racine ; permet de retracer
--                                      toutes les actions d'une activité et de
--                                      ses éléments rattachés, quelles que
--                                      soient les réaffectations.
--   notifications.id_activites       : rattache chaque notification à l'activité
--                                      concernée.
-- À exécuter une seule fois :
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_connexion_traces.sql
-- ============================================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------------
-- 1. Journal des connexions
-- ------------------------------------------------------------------
ALTER TABLE `connexion`
  MODIFY `date_heure_connexion`   datetime NOT NULL DEFAULT current_timestamp(),
  MODIFY `date_heure_deconnexion` datetime DEFAULT NULL,
  MODIFY `adresse_ip`             varchar(45) NOT NULL DEFAULT '';

ALTER TABLE `connexion`
  ADD COLUMN IF NOT EXISTS `pays`       varchar(100) DEFAULT NULL COMMENT 'origine : Reseau local, ou pays si geo-IP branche' AFTER `adresse_ip`,
  ADD COLUMN IF NOT EXISTS `user_agent` varchar(255) DEFAULT NULL AFTER `pays`;

ALTER TABLE `connexion`
  ADD CONSTRAINT `connexion_agent_fk` FOREIGN KEY (`id_agent_dpip`)
  REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE;

-- ------------------------------------------------------------------
-- 2. journal_activites : id_enregistrement = id de l'activité racine
-- ------------------------------------------------------------------
ALTER TABLE `journal_activites`
  MODIFY `id_enregistrement` int(11) NOT NULL DEFAULT 0
  COMMENT 'numero de dossier = id de l activite racine (pere_ID remonte)';

ALTER TABLE `journal_activites`
  ADD KEY IF NOT EXISTS `id_enregistrement` (`id_enregistrement`);

-- Rétro-remplissage : remonte jusqu'à 3 niveaux (tache -> sous-activite -> activite).
UPDATE `journal_activites` j
  JOIN `activites` a       ON a.`ID__activites`  = j.`ID_activites`
  LEFT JOIN `activites` p1  ON p1.`ID__activites` = a.`pere_ID`
  LEFT JOIN `activites` p2  ON p2.`ID__activites` = p1.`pere_ID`
  SET j.`id_enregistrement` = COALESCE(p2.`ID__activites`, p1.`ID__activites`, a.`ID__activites`);

-- ------------------------------------------------------------------
-- 3. notifications : id_activites (nullable + FK)
-- ------------------------------------------------------------------
ALTER TABLE `notifications`
  MODIFY `id_activites` int(11) DEFAULT NULL COMMENT 'activite concernee par la notification';

-- Rattache les notifications déjà émises à partir de l'id présent dans le lien.
UPDATE `notifications`
  SET `id_activites` = CASE
        WHEN `Lien` REGEXP '[?&]id=[0-9]+'
        THEN CAST(REGEXP_REPLACE(`Lien`, '^.*[?&]id=([0-9]+).*$', '\\1') AS UNSIGNED)
        ELSE NULL
      END;

-- Sécurité : on annule les id qui ne pointent plus vers une activité existante.
UPDATE `notifications` n
  LEFT JOIN `activites` a ON a.`ID__activites` = n.`id_activites`
  SET n.`id_activites` = NULL
  WHERE n.`id_activites` IS NOT NULL AND a.`ID__activites` IS NULL;

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_activites_fk` FOREIGN KEY (`id_activites`)
  REFERENCES `activites` (`ID__activites`) ON DELETE SET NULL ON UPDATE CASCADE;
