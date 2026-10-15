-- ============================================================================
-- Migration : recommandations du Directeur à ses Responsables Service / Sous-directeurs
-- ============================================================================
-- Le Directeur (supérieur hiérarchique) sélectionne un Sous-directeur ou un
-- Responsable Service qui lui est rattaché (agent_dpip.ID_responsable) et lui adresse
-- une recommandation. Le destinataire la reçoit dans son espace en gardant son titre
-- (son rôle n'est pas modifié).
-- Réexécutable sans effet de bord (CREATE TABLE IF NOT EXISTS).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `recommandation` (
  `id_recommandation` int(11) NOT NULL AUTO_INCREMENT,
  `ID_emetteur` int(11) NOT NULL COMMENT 'superieur hierarchique (directeur) qui adresse la recommandation',
  `ID_destinataire` int(11) NOT NULL COMMENT 'responsable service ou sous-directeur destinataire',
  `objet` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `importance` enum('normale','importante','urgente') NOT NULL DEFAULT 'normale',
  `lu` tinyint(1) NOT NULL DEFAULT 0,
  `date_lecture` datetime DEFAULT NULL,
  `Date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_recommandation`),
  KEY `destinataire_lu` (`ID_destinataire`, `lu`),
  KEY `ID_emetteur` (`ID_emetteur`),
  CONSTRAINT `recommandation_emetteur_fk` FOREIGN KEY (`ID_emetteur`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `recommandation_destinataire_fk` FOREIGN KEY (`ID_destinataire`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
