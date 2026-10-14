-- ============================================================================
-- Migration : retours du responsable sur une activité.
--   retour_responsable : après avoir attribué une activité / sous-activité /
--   tâche, le responsable (ou l'admin / la direction) peut adresser un RETOUR
--   à l'agent — pour le relancer s'il n'a pas réagi, le suivre pendant
--   l'exécution, ou lui faire un retour une fois terminé. L'agent voit ces
--   retours dans la fiche (onglet « Retours ») et reçoit une notification ;
--   le retour est marqué « lu » quand l'agent l'ouvre.
--
-- À exécuter une seule fois :
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_retour_responsable.sql
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `retour_responsable` (
  `id_retour`        int(11) NOT NULL AUTO_INCREMENT,
  `ID_activites`     int(11) NOT NULL,
  `ID_responsable`   int(11) DEFAULT NULL COMMENT 'auteur du retour',
  `ID_agent`         int(11) DEFAULT NULL COMMENT 'executant destinataire au moment du retour',
  `contexte`         enum('sans_reaction','en_cours','termine','autre') NOT NULL DEFAULT 'autre'
                     COMMENT 'etat de la tache au moment du retour',
  `statut_activite`  varchar(20) DEFAULT NULL COMMENT 'instantane du statut de l activite',
  `message`          text NOT NULL,
  `lu`               tinyint(1) NOT NULL DEFAULT 0,
  `date_lecture`     datetime DEFAULT NULL,
  `Date_creation`    timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_retour`),
  KEY `ID_activites` (`ID_activites`),
  KEY `ID_responsable` (`ID_responsable`),
  KEY `ID_agent` (`ID_agent`),
  CONSTRAINT `retour_responsable_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `retour_responsable_resp_fk`      FOREIGN KEY (`ID_responsable`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `retour_responsable_agent_fk`     FOREIGN KEY (`ID_agent`)       REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
