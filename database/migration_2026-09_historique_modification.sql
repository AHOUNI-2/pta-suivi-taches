-- ============================================================================
-- Migration : journal d'audit global de l'application.
--   historique_modification : trace TOUTES les actions des utilisateurs sur
--   TOUTES les fonctionnalités (comptes, départements, référentiels, activités,
--   comptes rendus, livrables, besoins, connexions…).
--
--   Normalise la table créée à la main (0 ligne) :
--     - colonne « table_concernéé » (double accent) -> `table_concernee`
--     - `id_enregistrements` -> `id_enregistrement` (nullable)
--     - `actions`  int  -> `action`  varchar(50)
--     - `details`  int  -> `details` varchar(255) nullable
--     - `dates_heure`    -> `date_heure`
--     - `id_agent` nullable + FK ; + `agent_libelle` (instantané du nom)
--
-- À exécuter une seule fois :
--   mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_historique_modification.sql
-- ============================================================================

SET NAMES utf8mb4;

-- On repart d'une structure propre (la table est vide).
DROP TABLE IF EXISTS `historique_modification`;

CREATE TABLE `historique_modification` (
  `id_historique_modification` int(11) NOT NULL AUTO_INCREMENT,
  `id_agent`          int(11) DEFAULT NULL,
  `agent_libelle`     varchar(150) DEFAULT NULL COMMENT 'nom de l acteur au moment de l action',
  `table_concernee`   varchar(64) NOT NULL COMMENT 'fonctionnalite / table touchee',
  `id_enregistrement` int(11) DEFAULT NULL COMMENT 'identifiant de la ligne concernee',
  `action`            varchar(50) NOT NULL COMMENT 'creation / modification / suppression / activation / connexion...',
  `details`           varchar(255) DEFAULT NULL COMMENT 'description lisible de ce qui a change',
  `date_heure`        timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_historique_modification`),
  KEY `id_agent` (`id_agent`),
  KEY `table_concernee` (`table_concernee`),
  KEY `date_heure` (`date_heure`),
  CONSTRAINT `historique_modification_agent_fk` FOREIGN KEY (`id_agent`)
    REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
