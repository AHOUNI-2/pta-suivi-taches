-- ============================================================================
-- PTA - MODULE DE GESTION DU SUIVI DES TACHES
-- Base de données : pta_bd_suivi
-- ============================================================================
-- Ce fichier reprend la structure d'origine (pta_bd_suivi.sql fournie) et
-- l'étend pour supporter l'application de suivi des tâches avec comptes
-- Administrateur / Responsable / Agent.
--
-- Modifications apportées par rapport au fichier d'origine :
--   1. Table `agent_dpip` : ajout de l'authentification (mot_de_passe, role),
--      du lien hiérarchique agent -> responsable (ID_responsable), du statut
--      de compte et de l'élargissement des colonnes Mail/Mail_Pro/Contact
--      (trop courtes dans le fichier d'origine pour des emails réels).
--   2. Table `activites` : ajout du responsable qui attribue la tâche
--      (ID_responsable), du statut de suivi, de la priorité, de la
--      progression (%), d'une observation libre et du département.
--      Correction : ON DELETE CASCADE -> RESTRICT sur les FK vers
--      agent_dpip (supprimer un agent ne doit pas effacer ses tâches).
--   3. Table `livrables` : Piece_jointe élargie (VARCHAR(12) -> VARCHAR(255),
--      12 caractères ne permettent pas de stocker un nom de fichier),
--      ajout de l'auteur du dépôt et de la date.
--   4. Nouvelle table `comptes_rendus` : les comptes rendus que les agents
--      remettent à leur responsable pour faire état de l'avancement.
--   5. Nouvelle table `notifications` : notifications internes (nouvelle
--      tâche, nouveau compte rendu, échéance proche, etc.).
--   6. Nouvelle table `journal_activites` : historique léger des actions
--      sur chaque tâche (création, changement de statut, dépôt de
--      livrable, compte rendu), utilisé pour le fil d'activité des
--      tableaux de bord.
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------
-- Structure de la table `departement`
-- --------------------------------------------------------

CREATE TABLE `departement` (
  `id_departement` int(11) NOT NULL AUTO_INCREMENT,
  `code_departement` varchar(20) NOT NULL COMMENT 'code d identification, ex DPT_001',
  `Nom_court` varchar(50) NOT NULL COMMENT 'le departement sera un menu deroulant (DPIP;SDPS;SDAAR;SERVICE IT;SECRETARIAT)',
  `Libelle` varchar(150) NOT NULL COMMENT 'libelle est de definie les abregés',
  `Types` varchar(100) NOT NULL COMMENT 'type va prendre en valeur (Direction;sous-Direction; service, secretariat )',
  PRIMARY KEY (`id_departement`),
  UNIQUE KEY `code_departement` (`code_departement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `agent_dpip` (comptes utilisateurs : admin / responsable / agent)
-- --------------------------------------------------------

CREATE TABLE `agent_dpip` (
  `ID_agent_dpip` int(11) NOT NULL AUTO_INCREMENT,
  `Nom` varchar(100) NOT NULL,
  `Prenom` varchar(150) NOT NULL,
  `Matricule` varchar(50) NOT NULL,
  `Mail` varchar(150) NOT NULL,
  `Mail_Pro` varchar(150) DEFAULT NULL,
  `Contact` varchar(20) DEFAULT NULL,
  `Profil` text DEFAULT NULL COMMENT 'domaine de competence, texte libre renseigne par l agent ou l admin',
  `ID_departement` int(11) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL COMMENT 'hash password_hash()',
  `role` enum('admin','directeur','responsable','agent') NOT NULL DEFAULT 'agent',
  `type_responsable` enum('service','sous_directeur') DEFAULT NULL COMMENT 'precise le type quand role = responsable',
  `est_responsable_service` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'fonction secondaire : peut etre designe comme Responsable Service / Sous-directeur',
  `est_agent` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'fonction secondaire : peut recevoir des activites comme un agent',
  `ID_responsable` int(11) DEFAULT NULL COMMENT 'responsable hierarchique direct de l''agent (NULL pour admin/directeur/responsable de tete)',
  `ID_secretaire` int(11) DEFAULT NULL COMMENT 'compte designe par un directeur pour agir en son nom',
  `statut_compte` enum('actif','inactif','en_attente','non_active') NOT NULL DEFAULT 'actif' COMMENT 'non_active=cree non active par admin ; en_attente=active, mot de passe a definir ; actif=ok ; inactif=desactive',
  `photo` varchar(255) DEFAULT NULL,
  `date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `derniere_connexion` datetime DEFAULT NULL,
  PRIMARY KEY (`ID_agent_dpip`),
  UNIQUE KEY `Matricule` (`Matricule`),
  UNIQUE KEY `Mail` (`Mail`),
  KEY `ID_departement` (`ID_departement`),
  KEY `ID_responsable` (`ID_responsable`),
  KEY `ID_secretaire` (`ID_secretaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `activites` (les tâches attribuées)
-- --------------------------------------------------------

CREATE TABLE `activites` (
  `ID__activites` int(11) NOT NULL AUTO_INCREMENT,
  `Libelle` varchar(150) NOT NULL,
  `Observation` text DEFAULT NULL COMMENT 'observation / note libre sur l activite',
  `Types` enum('activite','sous-activite','tache') NOT NULL DEFAULT 'tache',
  `Produit` varchar(150) DEFAULT NULL,
  `Date_debut` date NOT NULL,
  `Date_fin` date NOT NULL,
  `ID_agent_dpip` int(11) NOT NULL COMMENT 'agent executant, a qui la tache est attribuee',
  `ID_responsable` int(11) NOT NULL COMMENT 'responsable qui attribue la tache',
  `ID_departement` int(11) DEFAULT NULL,
  `pere_ID` int(11) DEFAULT NULL COMMENT 'activite/sous-activite parente (NULL pour une activite de tete)',
  `Statut` enum('a_faire','en_cours','en_attente','termine','en_retard','annule') NOT NULL DEFAULT 'a_faire',
  `Priorite` varchar(50) NOT NULL DEFAULT 'moyenne' COMMENT 'liste ouverte : basse/moyenne/haute/urgente proposees, valeur libre possible',
  `Progression` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'pourcentage 0-100',
  `Date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  `Date_modification` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`ID__activites`),
  KEY `ID_agent_dpip` (`ID_agent_dpip`),
  KEY `ID_responsable` (`ID_responsable`),
  KEY `ID_departement` (`ID_departement`),
  KEY `pere_ID` (`pere_ID`),
  KEY `Statut` (`Statut`),
  KEY `Date_fin` (`Date_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `besions` (besoins liés à une activité)
-- --------------------------------------------------------

CREATE TABLE `besions` (
  `id_besions` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(150) NOT NULL,
  `Type` enum('materiel','humain','financier') NOT NULL COMMENT 'dans type de besions on peut avoir (materiel,humain, financier)',
  `profil_humain` varchar(255) DEFAULT NULL COMMENT 'profil recherche quand Type = humain (texte libre)',
  `Estimation_financiere` int(12) NOT NULL DEFAULT 0,
  `ID_activites` int(11) NOT NULL,
  `Date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_besions`),
  KEY `ID_activites` (`ID_activites`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Réaffectation d'un exécutant : référentiel des causes + historique
-- --------------------------------------------------------

CREATE TABLE `cause_reafectation` (
  `id_cause` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(150) NOT NULL,
  PRIMARY KEY (`id_cause`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `reafectation` (
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
  KEY `ID_responsable` (`ID_responsable`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `retour_responsable` (retours du responsable à l'agent)
-- --------------------------------------------------------

CREATE TABLE `retour_responsable` (
  `id_retour`        int(11) NOT NULL AUTO_INCREMENT,
  `ID_activites`     int(11) NOT NULL,
  `ID_responsable`   int(11) DEFAULT NULL COMMENT 'auteur du retour',
  `ID_agent`         int(11) DEFAULT NULL COMMENT 'executant destinataire au moment du retour',
  `contexte`         enum('sans_reaction','en_cours','termine','autre') NOT NULL DEFAULT 'autre' COMMENT 'etat de la tache au moment du retour',
  `statut_activite`  varchar(20) DEFAULT NULL COMMENT 'instantane du statut de l activite',
  `message`          text NOT NULL,
  `lu`               tinyint(1) NOT NULL DEFAULT 0,
  `date_lecture`     datetime DEFAULT NULL,
  `Date_creation`    timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_retour`),
  KEY `ID_activites` (`ID_activites`),
  KEY `ID_responsable` (`ID_responsable`),
  KEY `ID_agent` (`ID_agent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `livrables` (documents rendus pour une activité)
-- --------------------------------------------------------

CREATE TABLE `livrables` (
  `id_livrable` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` varchar(150) NOT NULL,
  `Piece_jointe` varchar(255) DEFAULT NULL COMMENT 'nom du fichier stocke sur le serveur',
  `ID_activites` int(11) NOT NULL,
  `ID_agent_dpip` int(11) DEFAULT NULL COMMENT 'agent ayant depose le livrable',
  `Commentaire` varchar(255) DEFAULT NULL,
  `Date_depot` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_livrable`),
  KEY `ID_activites` (`ID_activites`),
  KEY `ID_agent_dpip` (`ID_agent_dpip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `observation` (remarques du responsable sur une tâche)
-- --------------------------------------------------------

CREATE TABLE `observation` (
  `id_observation` int(11) NOT NULL AUTO_INCREMENT,
  `libelle` text NOT NULL,
  `ID_agent_dpip` int(11) NOT NULL COMMENT 'auteur de l''observation',
  `id_activites` int(11) NOT NULL,
  `Date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_observation`),
  KEY `ID_agent_dpip` (`ID_agent_dpip`),
  KEY `id_activites` (`id_activites`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `comptes_rendus` (NOUVELLE : rapports d'avancement des agents)
-- --------------------------------------------------------

CREATE TABLE `comptes_rendus` (
  `id_compte_rendu` int(11) NOT NULL AUTO_INCREMENT,
  `ID_activites` int(11) NOT NULL,
  `ID_agent_dpip` int(11) NOT NULL COMMENT 'agent auteur du compte rendu',
  `Contenu` text NOT NULL,
  `Progression` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'progression declaree au moment du CR (0-100)',
  `Statut_activite` enum('a_faire','en_cours','en_attente','termine','en_retard','annule') NOT NULL,
  `Piece_jointe` varchar(255) DEFAULT NULL,
  `Lu_par_responsable` tinyint(1) NOT NULL DEFAULT 0,
  `Date_lecture` datetime DEFAULT NULL,
  `Date_rendu` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_compte_rendu`),
  KEY `ID_activites` (`ID_activites`),
  KEY `ID_agent_dpip` (`ID_agent_dpip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `notifications` (NOUVELLE)
-- --------------------------------------------------------

CREATE TABLE `notifications` (
  `id_notification` int(11) NOT NULL AUTO_INCREMENT,
  `ID_agent_dpip` int(11) NOT NULL COMMENT 'destinataire',
  `id_activites` int(11) DEFAULT NULL COMMENT 'activite concernee par la notification',
  `Titre` varchar(150) NOT NULL,
  `Message` text NOT NULL,
  `Lien` varchar(255) DEFAULT NULL,
  `Lu` tinyint(1) NOT NULL DEFAULT 0,
  `Date_creation` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_notification`),
  KEY `ID_agent_dpip` (`ID_agent_dpip`),
  KEY `id_activites` (`id_activites`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `journal_activites` (NOUVELLE : historique des actions)
-- --------------------------------------------------------

CREATE TABLE `journal_activites` (
  `id_journal` int(11) NOT NULL AUTO_INCREMENT,
  `ID_activites` int(11) NOT NULL,
  `ID_agent_dpip` int(11) DEFAULT NULL COMMENT 'auteur de l''action (NULL si compte supprime)',
  `id_enregistrement` int(11) NOT NULL DEFAULT 0 COMMENT 'numero de dossier = id de l activite racine (pere_ID remonte)',
  `Action` varchar(50) NOT NULL,
  `Detail` varchar(255) DEFAULT NULL,
  `Date_action` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_journal`),
  KEY `ID_activites` (`ID_activites`),
  KEY `ID_agent_dpip` (`ID_agent_dpip`),
  KEY `id_enregistrement` (`id_enregistrement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `connexion` (journal des connexions / securite)
-- --------------------------------------------------------

CREATE TABLE `connexion` (
  `id_connexion` int(11) NOT NULL AUTO_INCREMENT,
  `id_agent_dpip` int(11) NOT NULL,
  `date_heure_connexion` datetime NOT NULL DEFAULT current_timestamp(),
  `date_heure_deconnexion` datetime DEFAULT NULL,
  `adresse_ip` varchar(45) NOT NULL DEFAULT '',
  `pays` varchar(100) DEFAULT NULL COMMENT 'origine : Reseau local, ou pays si geo-IP branche',
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_connexion`),
  KEY `id_agent_dpip` (`id_agent_dpip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Structure de la table `historique_modification` (journal d'audit global)
-- --------------------------------------------------------

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
  KEY `date_heure` (`date_heure`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Contraintes de clés étrangères
-- --------------------------------------------------------

ALTER TABLE `agent_dpip`
  ADD CONSTRAINT `agent_dpip_departement_fk` FOREIGN KEY (`ID_departement`) REFERENCES `departement` (`id_departement`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `agent_dpip_responsable_fk` FOREIGN KEY (`ID_responsable`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `agent_dpip_secretaire_fk` FOREIGN KEY (`ID_secretaire`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `activites`
  ADD CONSTRAINT `activites_agent_dpip_fk` FOREIGN KEY (`ID_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `activites_responsable_fk` FOREIGN KEY (`ID_responsable`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `activites_departement_fk` FOREIGN KEY (`ID_departement`) REFERENCES `departement` (`id_departement`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `activites_pere_fk` FOREIGN KEY (`pere_ID`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `besions`
  ADD CONSTRAINT `besions_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `reafectation`
  ADD CONSTRAINT `reafectation_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `reafectation_ancien_fk` FOREIGN KEY (`ID_ancien_agent`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `reafectation_nouvel_fk` FOREIGN KEY (`ID_nouvel_agent`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `reafectation_cause_fk` FOREIGN KEY (`id_cause`) REFERENCES `cause_reafectation` (`id_cause`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `reafectation_responsable_fk` FOREIGN KEY (`ID_responsable`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `retour_responsable`
  ADD CONSTRAINT `retour_responsable_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `retour_responsable_resp_fk` FOREIGN KEY (`ID_responsable`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `retour_responsable_agent_fk` FOREIGN KEY (`ID_agent`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `livrables`
  ADD CONSTRAINT `livrables_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `livrables_agent_dpip_fk` FOREIGN KEY (`ID_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `observation`
  ADD CONSTRAINT `observation_agent_dpip_fk` FOREIGN KEY (`ID_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `observation_activites_fk` FOREIGN KEY (`id_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `comptes_rendus`
  ADD CONSTRAINT `comptes_rendus_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `comptes_rendus_agent_dpip_fk` FOREIGN KEY (`ID_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_agent_dpip_fk` FOREIGN KEY (`ID_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `notifications_activites_fk` FOREIGN KEY (`id_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `journal_activites`
  ADD CONSTRAINT `journal_activites_activites_fk` FOREIGN KEY (`ID_activites`) REFERENCES `activites` (`ID__activites`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `journal_activites_agent_dpip_fk` FOREIGN KEY (`ID_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `connexion`
  ADD CONSTRAINT `connexion_agent_fk` FOREIGN KEY (`id_agent_dpip`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `historique_modification`
  ADD CONSTRAINT `historique_modification_agent_fk` FOREIGN KEY (`id_agent`) REFERENCES `agent_dpip` (`ID_agent_dpip`) ON DELETE SET NULL ON UPDATE CASCADE;

-- --------------------------------------------------------
-- Données initiales : départements (repris du commentaire d'origine)
-- --------------------------------------------------------

INSERT INTO `departement` (`id_departement`, `code_departement`, `Nom_court`, `Libelle`, `Types`) VALUES
(1, 'DPT_001', 'DPIP', 'Direction de la Programmation des Investissements Publics', 'Direction'),
(2, 'DPT_002', 'SDPS', 'Sous-Direction de la Programmation Sectorielle', 'Sous-Direction'),
(3, 'DPT_003', 'SDAAR', 'Sous-Direction de l''Analyse et de la Recherche', 'Sous-Direction'),
(4, 'DPT_004', 'SERVICE IT', 'Service Informatique', 'Service'),
(5, 'DPT_005', 'SECRETARIAT', 'Secretariat', 'Secretariat');

-- Données initiales : causes de réaffectation d'un exécutant
INSERT INTO `cause_reafectation` (`libelle`) VALUES
('Agent empêché(e)'),
('Agent malade'),
('Agent permissionnaire'),
('Agent en congés'),
('Agent affecté(e) sur une autre activité'),
('Autre');

-- --------------------------------------------------------
-- Compte administrateur par défaut
-- Matricule : ADMIN001  /  Mot de passe : Admin@2026
-- /!\ A changer immédiatement après la première connexion.
-- --------------------------------------------------------

INSERT INTO `agent_dpip` (`Nom`, `Prenom`, `Matricule`, `Mail`, `Mail_Pro`, `Contact`, `ID_departement`, `mot_de_passe`, `role`, `type_responsable`, `ID_responsable`, `statut_compte`) VALUES
('Administrateur', 'Systeme', 'ADMIN001', 'admin@pta.local', 'admin@pta.local', NULL, 4, '$2y$12$LTioAp/8vb7KPLEujqyPY.ltQr753KR7krekATaexRLgrZr6Y0ewC', 'admin', NULL, NULL, 'actif');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
