<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = "Historique des modifications";

$agents = $pdo->query("SELECT ID_agent_dpip, Nom, Prenom FROM agent_dpip ORDER BY Nom, Prenom")->fetchAll();
$tables = $pdo->query("SELECT DISTINCT table_concernee FROM historique_modification ORDER BY table_concernee")->fetchAll(PDO::FETCH_COLUMN);
$actionsDispo = $pdo->query("SELECT DISTINCT action FROM historique_modification ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$fAgent  = (int)get('agent');
$fTable  = get('table');
$fAction = get('action');
$fDebut  = get('debut');
$fFin    = get('fin');

$where = [];
$params = [];
if ($fAgent > 0)  { $where[] = 'h.id_agent = ?';          $params[] = $fAgent; }
if ($fTable !== '')  { $where[] = 'h.table_concernee = ?'; $params[] = $fTable; }
if ($fAction !== '') { $where[] = 'h.action = ?';          $params[] = $fAction; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDebut)) { $where[] = 'h.date_heure >= ?'; $params[] = $fDebut . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFin))   { $where[] = 'h.date_heure <= ?'; $params[] = $fFin . ' 23:59:59'; }
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT h.*, a.Nom, a.Prenom, a.Matricule
     FROM historique_modification h
     LEFT JOIN agent_dpip a ON a.ID_agent_dpip = h.id_agent
     $sqlWhere
     ORDER BY h.date_heure DESC, h.id_historique_modification DESC
     LIMIT 400"
);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT id_agent) agents FROM historique_modification")->fetch();

/** Couleur du badge selon l'action */
function auditBadge(string $action): string
{
    if (in_array($action, ['suppression', 'desactivation'], true)) return 'bg-danger';
    if (in_array($action, ['creation', 'activation'], true))       return 'bg-success';
    if (in_array($action, ['connexion', 'deconnexion'], true))     return 'bg-secondary';
    if (in_array($action, ['reinitialisation', 'changement_mdp'], true)) return 'bg-warning text-dark';
    return 'bg-primary';
}

include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Toutes les actions des utilisateurs sur l'ensemble des fonctionnalités : comptes, départements, référentiels, activités, comptes rendus, connexions… Chaque action est datée et rattachée à son auteur.</p>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#e7edf5;color:var(--pta-navy)"><i class="bi bi-clock-history"></i></div><div><div class="stat-value"><?= (int)$stats['n'] ?></div><div class="stat-label">Actions enregistrées</div></div></div></div>
    <div class="col-md-3"><div class="stat-card"><div class="stat-icon" style="background:#e6f4ea;color:#1f7a45"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= (int)$stats['agents'] ?></div><div class="stat-label">Utilisateurs concernés</div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Auteur</label>
                <select name="agent" class="form-select">
                    <option value="">— Tous —</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['ID_agent_dpip'] ?>" <?= $fAgent === (int)$a['ID_agent_dpip'] ? 'selected' : '' ?>><?= h($a['Prenom'] . ' ' . $a['Nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Fonctionnalité</label>
                <select name="table" class="form-select">
                    <option value="">— Toutes —</option>
                    <?php foreach ($tables as $t): ?>
                        <option value="<?= h($t) ?>" <?= $fTable === $t ? 'selected' : '' ?>><?= h(tableConcerneeLabel($t)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Action</label>
                <select name="action" class="form-select">
                    <option value="">— Toutes —</option>
                    <?php foreach ($actionsDispo as $ac): ?>
                        <option value="<?= h($ac) ?>" <?= $fAction === $ac ? 'selected' : '' ?>><?= h(actionModifLabel($ac)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Du</label>
                <input type="date" name="debut" value="<?= h($fDebut) ?>" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Au</label>
                <input type="date" name="fin" value="<?= h($fFin) ?>" class="form-control">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrer</button>
                <?php if ($fAgent || $fTable || $fAction || $fDebut || $fFin): ?>
                    <a href="<?= BASE_URL ?>/admin/historique.php" class="btn btn-light border">Réinitialiser</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Date &amp; heure</th><th>Auteur</th><th>Fonctionnalité</th><th>Action</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ($lignes as $l): ?>
                <tr>
                    <td class="small text-nowrap"><?= formatDate($l['date_heure'], true) ?></td>
                    <td class="small">
                        <?php $nom = trim(($l['Prenom'] ?? '') . ' ' . ($l['Nom'] ?? '')) ?: $l['agent_libelle']; ?>
                        <?= $nom ? h($nom) : '<span class="text-muted">compte supprimé</span>' ?>
                    </td>
                    <td class="small"><?= h(tableConcerneeLabel($l['table_concernee'])) ?><?php if ($l['id_enregistrement']): ?> <span class="text-muted">#<?= (int)$l['id_enregistrement'] ?></span><?php endif; ?></td>
                    <td><span class="badge <?= auditBadge($l['action']) ?>"><?= h(actionModifLabel($l['action'])) ?></span></td>
                    <td class="small"><?= h($l['details'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($lignes)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Aucune action enregistrée pour ces critères.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-2">400 dernières actions affichées.</p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
