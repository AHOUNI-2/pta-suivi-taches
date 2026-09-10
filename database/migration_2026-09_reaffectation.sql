-- ============================================================================
-- Migration : réaffectation d'un exécutant.
--   cause_reafectation : référentiel des causes (agent empêché, malade, en
--                        congés, permissionnaire, affecté ailleurs, autre).
--   reafectation       : historique de chaque changement d'agent exécutant
--                        d'une activité / sous-activité / tâche (ancien agent,
--                        nouvel agent, cause, auteur habilité, date, commentaire).
-- À exécuter une seule fois :
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_reaffectation.sql
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Référentiel des causes (la table a pu être créée à la main avec un type
--    incorrect : on la (re)met au bon format sans perdre les lignes éventuelles).
ALTER TABLE `cause_reafectation`
  MODIFY `libelle` varchar(150) NOT NULL;
ALTER TABLE `cause_reafectation`
  ADD UNIQUE KEY `libelle` (`libelle`);

INSERT IGNORE INTO `cause_reafectation` (`libelle`) VALUES
('Agent empêché(e)'),
('Agent malade'),
('Agent permissionnaire'),
('Agent en congés'),
('Agent affecté(e) sur une autre activité'),
('Autre');

-- 2. Historique des réaffectations.
CREATE TABLE IF NOT EXISTS `reafectation` (
  `id_reafectation` int(11) NOT NULL AUTO_INCREMENT,
  `ID_activites` int(11) NOT NULL,
  `ID_ancien_agent` int(11) DEFAULT NULL COMMENT 'agent retire de l activite',
  `ID_nouvel_agent` int(11) DEFAULT NULL COMMENT 'nouvel agent en charge',
  `id_cause` int(11) DEFAULT NULL,
  `ID_responsable` int(11) DEFAULT NULL COMMENT 'personne habilitee qui a fait la reaffectation',
  `Commentaire` varchar(255) DEFAULT NULL,
  `Date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_reafectation`),
  KEY `ID_activites` (`ID_activites`),
  KEY `ID_ancien_agent` (`ID_ancien_agent`),
  KEY `ID_nouvel_agent` (`ID_nouvel_agent`),
  KEY `id_cause` (`id_cause`),
  KEY `ID_responsable` (`ID_responsable`),
  CONSTRAINT `reafectation_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `reafectation_ancien_fk` FOREIGN KEY (`ID_ancien_agent`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `reafectation_nouvel_fk` FOREIGN KEY (`ID_nouvel_agent`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `reafectation_cause_fk` FOREIGN KEY (`id_cause`) REFERENCES `cause_reafectation` (`id_cause`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `reafectation_responsable_fk` FOREIGN KEY (`ID_responsable`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
