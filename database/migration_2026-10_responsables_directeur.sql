-- ============================================================================
-- Migration : chaque Responsable Service et chaque Sous-directeur a pour
--             responsable hiérarchique le Directeur
-- ============================================================================
-- Règle : agent_dpip.ID_responsable d'un compte de rôle « responsable »
-- (Responsable Service : type_responsable = 'service', ou Sous-directeur :
-- type_responsable = 'sous_directeur') pointe vers un Directeur actif.
--
-- Cette migration rattache au Directeur les responsables / sous-directeurs encore
-- SANS responsable, à condition qu'il n'y ait qu'UN SEUL Directeur actif (sinon il
-- n'y a pas de choix évident : l'administrateur les rattache depuis
-- « Utilisateurs > Modifier »). Un compte déjà rattaché à quelqu'un n'est pas modifié.
-- Réexécutable sans effet de bord.
-- ============================================================================

UPDATE agent_dpip r
JOIN (
    SELECT MIN(ID_agent_dpip) AS id_directeur, COUNT(*) AS nb
    FROM agent_dpip
    WHERE role = 'directeur' AND statut_compte = 'actif'
) d ON d.nb = 1
SET r.ID_responsable = d.id_directeur
WHERE r.role = 'responsable'
  AND r.ID_responsable IS NULL;

-- Contrôle : responsables / sous-directeurs et leur responsable
-- SELECT a.ID_agent_dpip, a.Nom, a.Prenom, a.type_responsable, r.Nom AS responsable, r.role AS role_responsable
-- FROM agent_dpip a LEFT JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
-- WHERE a.role = 'responsable';
