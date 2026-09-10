<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Utilisateurs';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = post('action');
    $id = (int)post('id');
    try {
        $cible = $id ? $pdo->query('SELECT Prenom, Nom FROM agent_dpip WHERE ID_agent_dpip = ' . $id)->fetch() : null;
        $nomCible = $cible ? trim($cible['Prenom'] . ' ' . $cible['Nom']) : ('#' . $id);
        if ($action === 'activer') {
            $stmt = $pdo->prepare("UPDATE agent_dpip SET statut_compte = 'en_attente' WHERE ID_agent_dpip = ? AND statut_compte = 'non_active'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() > 0) {
                logModif($pdo, 'agent_dpip', $id, 'activation', 'Compte « ' . $nomCible .' » activé (passe en « Première connexion »)');
                flash('success', "Compte activé. L'utilisateur peut maintenant définir son mot de passe via « Première connexion » (matricule + nouveau mot de passe), puis se connecter.");
            } else {
                flash('danger', "Ce compte n'est pas dans l'état « À activer ».");
            }
        } elseif ($action === 'toggle_statut') {
            if ($id === (int)$user['ID_agent_dpip']) {
                throw new RuntimeException('Vous ne pouvez pas désactiver votre propre compte.');
            }
            $pdo->prepare("UPDATE agent_dpip
                           SET statut_compte = CASE
                               WHEN statut_compte = 'actif' THEN 'inactif'
                               WHEN statut_compte = 'inactif' THEN 'actif'
                               ELSE statut_compte END
                           WHERE ID_agent_dpip = ?")
                ->execute([$id]);
            $nv = $pdo->query('SELECT statut_compte FROM agent_dpip WHERE ID_agent_dpip = ' . $id)->fetchColumn();
            logModif($pdo, 'agent_dpip', $id, $nv === 'inactif' ? 'desactivation' : 'activation', 'Compte « ' . $nomCible . ' » → ' . statutCompteLabel((string)$nv));
            flash('success', 'Statut du compte mis à jour.');
        } elseif ($action === 'reset_password') {
            if ($id === (int)$user['ID_agent_dpip']) {
                throw new RuntimeException('Utilisez « Mon profil » pour changer votre propre mot de passe.');
            }
            $pdo->prepare("UPDATE agent_dpip SET mot_de_passe = '', statut_compte = 'en_attente' WHERE ID_agent_dpip = ?")
                ->execute([$id]);
            logModif($pdo, 'agent_dpip', $id, 'reinitialisation', 'Mot de passe du compte « ' . $nomCible . ' » réinitialisé');
            flash('success', "Le compte repasse « en attente d'activation ». L'utilisateur redéfinit son mot de passe via « Première connexion » (matricule + nouveau mot de passe).");
        } elseif ($action === 'delete') {
            if ($id === (int)$user['ID_agent_dpip']) {
                throw new RuntimeException('Vous ne pouvez pas supprimer votre propre compte.');
            }
            $pdo->prepare('DELETE FROM agent_dpip WHERE ID_agent_dpip = ?')->execute([$id]);
            logModif($pdo, 'agent_dpip', $id, 'suppression', 'Compte « ' . $nomCible . ' » supprimé');
            flash('success', 'Utilisateur supprimé.');
        }
    } catch (Throwable $e) {
        $msg = str_contains($e->getMessage(), 'FOREIGN KEY') || str_contains($e->getMessage(), 'foreign key')
            ? "Impossible de supprimer : cet utilisateur est lié à des tâches ou données existantes. Désactivez plutôt son compte."
            : $e->getMessage();
        flash('danger', $msg);
    }
    redirect('/admin/utilisateurs.php');
}

// --- Filtres ---
$filtreRole = get('role');
$filtreDept = get('departement');
$filtreStatut = get('statut');
$recherche = get('q');

$sql = "SELECT a.*, d.Nom_court AS dept_nom, d.code_departement AS dept_code, r.Nom AS resp_nom, r.Prenom AS resp_prenom
        FROM agent_dpip a
        LEFT JOIN departement d ON d.id_departement = a.ID_departement
        LEFT JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
        WHERE 1=1";
$params = [];
if ($filtreRole === 'resp_service') { $sql .= " AND " . sqlEstRespService('a.'); }
elseif ($filtreRole === 'fonction_agent') { $sql .= " AND " . sqlEstAgent('a.'); }
elseif ($filtreRole === 'responsable') { $sql .= " AND a.role = 'responsable' AND (a.type_responsable IS NULL OR a.type_responsable = 'service')"; }
elseif ($filtreRole === 'sous_directeur') { $sql .= " AND a.role = 'responsable' AND a.type_responsable = 'sous_directeur'"; }
elseif ($filtreRole !== '') { $sql .= " AND a.role = ?"; $params[] = $filtreRole; }
if ($filtreDept !== '') { $sql .= " AND a.ID_departement = ?"; $params[] = $filtreDept; }
if ($filtreStatut !== '') { $sql .= " AND a.statut_compte = ?"; $params[] = $filtreStatut; }
if ($recherche !== '') {
    $sql .= " AND (a.Nom LIKE ? OR a.Prenom LIKE ? OR a.Matricule LIKE ? OR a.Mail LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like, $like);
}
$sql .= " ORDER BY a.role, a.Nom, a.Prenom";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$utilisateurs = $stmt->fetchAll();

$departements = $pdo->query('SELECT * FROM departement ORDER BY Libelle')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="text-muted mb-0">Créez et gérez les comptes administrateur, responsable et agent.</p>
    <a href="<?= BASE_URL ?>/admin/utilisateur_form.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Nouvel utilisateur</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Recherche</label>
                <input type="text" name="q" class="form-control" placeholder="Nom, prénom, matricule, email..." value="<?= h($recherche) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Rôle</label>
                <select name="role" class="form-select">
                    <option value="">Tous</option>
                    <option value="admin" <?= $filtreRole === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                    <option value="directeur" <?= $filtreRole === 'directeur' ? 'selected' : '' ?>>Directeur</option>
                    <option value="sous_directeur" <?= $filtreRole === 'sous_directeur' ? 'selected' : '' ?>>Sous-directeur</option>
                    <option value="responsable" <?= $filtreRole === 'responsable' ? 'selected' : '' ?>>Responsable Service</option>
                    <option value="agent" <?= $filtreRole === 'agent' ? 'selected' : '' ?>>Agent</option>
                    <option value="resp_service" <?= $filtreRole === 'resp_service' ? 'selected' : '' ?>>Responsable Service / Sous-directeur (fonction)</option>
                    <option value="fonction_agent" <?= $filtreRole === 'fonction_agent' ? 'selected' : '' ?>>Agent (fonction)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Département</label>
                <select name="departement" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($departements as $d): ?>
                        <option value="<?= $d['id_departement'] ?>" <?= $filtreDept == $d['id_departement'] ? 'selected' : '' ?>><?= h(($d['code_departement'] ?? codeDepartement($d['id_departement'])) . ' · ' . $d['Nom_court']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Statut</label>
                <select name="statut" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (['actif', 'non_active', 'en_attente', 'inactif'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= h(statutCompteLabel($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr>
                <th>Utilisateur</th><th>Matricule</th><th>Rôle</th><th>Département</th><th>Responsable Service / Sous-directeur</th><th>Statut</th><th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($utilisateurs as $u): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($u['Prenom'] . ' ' . $u['Nom']) ?></div>
                        <div class="text-muted small"><?= h($u['Mail']) ?></div>
                        <?php if (!empty($u['Profil'])): ?>
                            <div class="small text-muted"><i class="bi bi-mortarboard"></i> <?= h($u['Profil']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($u['Matricule']) ?></td>
                    <td>
                        <span class="badge bg-light text-dark border"><?= h(roleLabelCompte($u)) ?></span>
                        <?php if (!empty($u['est_responsable_service']) && $u['role'] !== 'responsable'): ?>
                            <span class="badge bg-info text-dark" title="Fonction supplémentaire">+ Resp. Service</span>
                        <?php endif; ?>
                        <?php if (!empty($u['est_agent']) && $u['role'] !== 'agent'): ?>
                            <span class="badge bg-info text-dark" title="Fonction supplémentaire">+ Agent</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($u['ID_departement'])): ?>
                            <span class="badge bg-dark text-white font-monospace"><?= h($u['dept_code'] ?? codeDepartement($u['ID_departement'])) ?></span>
                            <span class="small"><?= h($u['dept_nom'] ?? '') ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= $u['resp_nom'] ? h($u['resp_prenom'] . ' ' . $u['resp_nom']) : '—' ?></td>
                    <td>
                        <span class="badge <?= statutCompteBadgeClass($u['statut_compte']) ?>"><?= h(statutCompteLabel($u['statut_compte'])) ?></span>
                    </td>
                    <td class="text-end">
                        <?php $estSoi = (int)$u['ID_agent_dpip'] === (int)$user['ID_agent_dpip']; ?>
                        <a href="<?= BASE_URL ?>/admin/utilisateur_form.php?id=<?= $u['ID_agent_dpip'] ?>" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>

                        <?php if ($u['statut_compte'] === 'non_active'): ?>
                            <form method="post" class="d-inline" data-confirm="Activer ce compte ? L'utilisateur pourra ensuite définir son mot de passe (Première connexion).">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="activer">
                                <input type="hidden" name="id" value="<?= $u['ID_agent_dpip'] ?>">
                                <button class="btn btn-sm btn-success" title="Activer le compte"><i class="bi bi-check2-circle"></i> Activer</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$estSoi && in_array($u['statut_compte'], ['actif', 'inactif', 'en_attente'], true)): ?>
                            <form method="post" class="d-inline" data-confirm="Réinitialiser le mot de passe ? Le compte repassera « Première connexion à faire » et l'utilisateur devra redéfinir son mot de passe.">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="id" value="<?= $u['ID_agent_dpip'] ?>">
                                <button class="btn btn-sm btn-outline-warning" title="Réinitialiser le mot de passe"><i class="bi bi-key"></i></button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$estSoi && in_array($u['statut_compte'], ['actif', 'inactif'], true)): ?>
                            <form method="post" class="d-inline" data-confirm="Changer le statut de ce compte ?">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="toggle_statut">
                                <input type="hidden" name="id" value="<?= $u['ID_agent_dpip'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="<?= $u['statut_compte'] === 'actif' ? 'Désactiver' : 'Réactiver' ?>"><i class="bi bi-power"></i></button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$estSoi): ?>
                            <form method="post" class="d-inline" data-confirm="Supprimer définitivement cet utilisateur ?">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $u['ID_agent_dpip'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($utilisateurs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Aucun utilisateur trouvé.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
