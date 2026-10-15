-- ============================================================================
-- Migration : fonction supplémentaire « Administrateur » pour un Responsable Service
-- ============================================================================
-- Un Responsable Service (rôle « responsable », type « service ») peut avoir en plus
-- les droits d'administration : il garde son espace de responsable (attribuer des
-- activités à ses agents, comptes rendus...) et obtient l'espace Administration.
-- Son responsable hiérarchique reste le Directeur (agent_dpip.ID_responsable).
-- Réexécutable sans effet de bord (la colonne n'est ajoutée que si elle manque).
-- ============================================================================

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_dpip' AND COLUMN_NAME = 'est_admin');
SET @requete := IF(@existe = 0,
    'ALTER TABLE `agent_dpip` ADD COLUMN `est_admin` tinyint(1) NOT NULL DEFAULT 0 COMMENT ''fonction supplementaire : administrateur (pour un responsable service)'' AFTER `est_agent`',
    'SELECT ''colonne est_admin deja presente'' AS information');
PREPARE etape FROM @requete;
EXECUTE etape;
DEALLOCATE PREPARE etape;
