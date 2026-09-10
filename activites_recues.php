<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = requireLogin();
$pageTitle = 'Activités qui me sont confiées';
$moiId = (int)$user['ID_agent_dpip'];
actualiserTachesEnRetard($pdo);

$filtreStatut = get('statut');
$recherche = get('q');

$sql = "SELECT a.*, r.Nom AS resp_nom, r.Prenom AS resp_prenom, r.role AS resp_role
        FROM activites a
        JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
        WHERE a.ID_agent_dpip = ?";
$params = [$moiId];
if ($filtreStatut !== '') { $sql .= ' AND a.Statut = ?'; $params[] = $filtreStatut; }
if ($recherche !== '') { $sql .= ' AND a.Libelle LIKE ?'; $params[] = "%$recherche%"; }
$sql .= " ORDER BY FIELD(a.Types,'activite','sous-activite','tache'), a.Date_fin ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activites = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<p class="text-muted">Activités et tâches que la direction, l'administration ou votre Responsable Service vous a confiées à exécuter. Rendez compte de votre avancement depuis chaque fiche.</p>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label small fw-semibold">Recherche</label>
                <input type="text" name="q" class="form-control" value="<?= h($recherche) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Statut</label>
                <select name="statut" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (['a_faire','en_cours','en_attente','termine','en_retard','annule'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= statutLabel($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
        </form>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($activites as $a): ?>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="badge <?= statutBadgeClass($a['Statut']) ?>"><?= statutLabel($a['Statut']) ?></span>
                        <span class="badge <?= prioriteBadgeClass($a['Priorite']) ?>"><?= prioriteLabel($a['Priorite']) ?></span>
                    </div>
                    <span class="badge <?= typeActiviteBadgeClass($a['Types']) ?> mb-1"><?= h(typeActiviteLabel($a['Types'])) ?></span>
                    <h3 class="h6 fw-bold"><?= h($a['Libelle']) ?></h3>
                    <p class="text-muted small mb-2">Confiée par <?= h($a['resp_prenom'] . ' ' . $a['resp_nom']) ?><?= $a['resp_role'] === 'directeur' ? ' (Directeur)' : '' ?></p>
                    <div class="progress mb-1"><div class="progress-bar bg-success" style="width:<?= (int)$a['Progression'] ?>%"></div></div>
                    <div class="d-flex justify-content-between small mb-3">
                        <span class="text-muted"><?= (int)$a['Progression'] ?>% réalisé</span>
                        <span class="<?= estEnRetard($a['Date_fin'], $a['Statut']) ? 'text-danger fw-semibold' : 'text-muted' ?>">Échéance : <?= formatDate($a['Date_fin']) ?></span>
                    </div>
                    <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $a['ID__activites'] ?>" class="btn btn-sm btn-primary w-100">Ouvrir <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($activites)): ?>
        <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">Aucune activité ne vous est confiée directement pour le moment.</div></div></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
