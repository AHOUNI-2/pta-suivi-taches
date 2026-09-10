<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Départements';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = post('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $nomCourt = post('Nom_court');
            $libelle = post('Libelle');
            $types = post('Types');
            if ($nomCourt === '' || $libelle === '' || $types === '') {
                throw new RuntimeException('Tous les champs sont obligatoires.');
            }
            if ($action === 'create') {
                // Le code (DPT_00X) dépend de l'id auto-incrémenté : on insère avec un
                // code provisoire unique puis on le fige sur l'id réel, en transaction.
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO departement (code_departement, Nom_court, Libelle, Types) VALUES (?, ?, ?, ?)')
                    ->execute(['tmp_' . bin2hex(random_bytes(6)), $nomCourt, $libelle, $types]);
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE departement SET code_departement = ? WHERE id_departement = ?')
                    ->execute([codeDepartement($newId), $newId]);
                $pdo->commit();
                logModif($pdo, 'departement', $newId, 'creation', 'Département « ' . $nomCourt . ' — ' . $libelle . ' » créé');
                flash('success', 'Département créé avec succès (code ' . codeDepartement($newId) . ').');
            } else {
                $id = (int)post('id_departement');
                $pdo->prepare('UPDATE departement SET Nom_court = ?, Libelle = ?, Types = ? WHERE id_departement = ?')
                    ->execute([$nomCourt, $libelle, $types, $id]);
                logModif($pdo, 'departement', $id, 'modification', 'Département « ' . $nomCourt . ' — ' . $libelle . ' » modifié');
                flash('success', 'Département mis à jour.');
            }
        } elseif ($action === 'delete') {
            $id = (int)post('id_departement');
            $count = $pdo->prepare('SELECT COUNT(*) FROM agent_dpip WHERE ID_departement = ?');
            $count->execute([$id]);
            if ($count->fetchColumn() > 0) {
                throw new RuntimeException('Impossible de supprimer : des utilisateurs sont rattachés à ce département.');
            }
            $pdo->prepare('DELETE FROM departement WHERE id_departement = ?')->execute([$id]);
            logModif($pdo, 'departement', $id, 'suppression', 'Département supprimé');
            flash('success', 'Département supprimé.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $e->getMessage());
    }
    redirect('/admin/departements.php');
}

$departements = $pdo->query(
    'SELECT d.*, COUNT(a.ID_agent_dpip) AS nb_agents
     FROM departement d
     LEFT JOIN agent_dpip a ON a.ID_departement = d.id_departement
     GROUP BY d.id_departement ORDER BY d.Libelle'
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">Gérez les directions, sous-directions et services de votre structure.</p>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalDepartement" onclick="ouvrirCreation()">
        <i class="bi bi-plus-lg"></i> Nouveau département
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0" id="tableDepartements">
            <thead><tr>
                <th>Code</th><th>Sigle</th><th>Libellé</th><th>Type</th><th>Utilisateurs</th><th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($departements as $d): ?>
                <tr>
                    <td><span class="badge bg-dark text-white font-monospace"><?= h($d['code_departement'] ?? codeDepartement($d['id_departement'])) ?></span></td>
                    <td><span class="badge bg-light text-dark border"><?= h($d['Nom_court']) ?></span></td>
                    <td><?= h($d['Libelle']) ?></td>
                    <td><?= h($d['Types']) ?></td>
                    <td><?= (int)$d['nb_agents'] ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                                onclick='ouvrirEdition(<?= json_encode($d, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                data-bs-toggle="modal" data-bs-target="#modalDepartement">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" class="d-inline" data-confirm="Supprimer ce département ?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_departement" value="<?= $d['id_departement'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($departements)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Aucun département enregistré.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalDepartement" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id_departement" id="formId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nouveau département</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Sigle (ex : DPIP)</label>
                        <input type="text" name="Nom_court" id="formNomCourt" class="form-control" required maxlength="50">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Libellé complet</label>
                        <input type="text" name="Libelle" id="formLibelle" class="form-control" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Type</label>
                        <select name="Types" id="formTypes" class="form-select" required>
                            <option value="Direction">Direction</option>
                            <option value="Sous-Direction">Sous-Direction</option>
                            <option value="Service">Service</option>
                            <option value="Secretariat">Secrétariat</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function ouvrirCreation() {
    document.getElementById('modalTitle').textContent = 'Nouveau département';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formNomCourt').value = '';
    document.getElementById('formLibelle').value = '';
    document.getElementById('formTypes').value = 'Direction';
}
function ouvrirEdition(d) {
    document.getElementById('modalTitle').textContent = 'Modifier le département';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = d.id_departement;
    document.getElementById('formNomCourt').value = d.Nom_court;
    document.getElementById('formLibelle').value = d.Libelle;
    document.getElementById('formTypes').value = d.Types;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
