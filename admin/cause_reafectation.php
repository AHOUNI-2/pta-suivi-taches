<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Causes de réaffectation';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = post('action');
    try {
        if ($action === 'create' || $action === 'update') {
            $libelle = post('libelle');
            if ($libelle === '') {
                throw new RuntimeException('Le libellé est obligatoire.');
            }
            if ($action === 'create') {
                $pdo->prepare('INSERT INTO cause_reafectation (libelle) VALUES (?)')->execute([$libelle]);
                logModif($pdo, 'cause_reafectation', (int)$pdo->lastInsertId(), 'creation', 'Cause de réaffectation « ' . $libelle .' » ajoutée');
                flash('success', 'Cause ajoutée.');
            } else {
                $cid = (int)post('id_cause');
                $pdo->prepare('UPDATE cause_reafectation SET libelle = ? WHERE id_cause = ?')->execute([$libelle, $cid]);
                logModif($pdo, 'cause_reafectation', $cid, 'modification', 'Cause de réaffectation renommée en « ' . $libelle . ' »');
                flash('success', 'Cause mise à jour.');
            }
        } elseif ($action === 'delete') {
            $cid = (int)post('id_cause');
            $nb = $pdo->prepare('SELECT COUNT(*) FROM reafectation WHERE id_cause = ?');
            $nb->execute([$cid]);
            if ($nb->fetchColumn() > 0) {
                throw new RuntimeException("Impossible de supprimer : cette cause est utilisée dans l'historique des réaffectations.");
            }
            $pdo->prepare('DELETE FROM cause_reafectation WHERE id_cause = ?')->execute([$cid]);
            logModif($pdo, 'cause_reafectation', $cid, 'suppression', 'Cause de réaffectation supprimée');
            flash('success', 'Cause supprimée.');
        }
    } catch (Throwable $e) {
        flash('danger', str_contains($e->getMessage(), 'Duplicate') ? 'Cette cause existe déjà.' : $e->getMessage());
    }
    redirect('/admin/cause_reafectation.php');
}

$causes = $pdo->query(
    "SELECT c.*, (SELECT COUNT(*) FROM reafectation r WHERE r.id_cause = c.id_cause) AS nb_utilisations
     FROM cause_reafectation c ORDER BY c.libelle"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <p class="text-muted mb-0">Motifs proposés lorsqu'une activité, sous-activité ou tâche est réaffectée à un autre agent (agent empêché, malade, en congés…).</p>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCause" onclick="ouvrirCreation()">
        <i class="bi bi-plus-lg"></i> Nouvelle cause
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Cause</th><th>Utilisations</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($causes as $c): ?>
                <tr>
                    <td><?= h($c['libelle']) ?></td>
                    <td><?= (int)$c['nb_utilisations'] ?></td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                                onclick='ouvrirEdition(<?= json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                data-bs-toggle="modal" data-bs-target="#modalCause"><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline" data-confirm="Supprimer cette cause ?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id_cause" value="<?= $c['id_cause'] ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($causes)): ?>
                <tr><td colspan="3" class="text-center text-muted py-4">Aucune cause enregistrée.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalCause" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id_cause" id="formId" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Nouvelle cause</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label small fw-semibold">Libellé de la cause</label>
                    <input type="text" name="libelle" id="formLibelle" class="form-control" required maxlength="150"
                           placeholder="Ex. : Agent en congés, Agent affecté(e) sur une autre activité...">
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
    document.getElementById('modalTitle').textContent = 'Nouvelle cause';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formLibelle').value = '';
}
function ouvrirEdition(c) {
    document.getElementById('modalTitle').textContent = 'Modifier la cause';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = c.id_cause;
    document.getElementById('formLibelle').value = c.libelle;
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
