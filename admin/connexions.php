<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Journal des connexions';

$agents = $pdo->query("SELECT ID_agent_dpip, Nom, Prenom FROM agent_dpip ORDER BY Nom, Prenom")->fetchAll();
$filtreAgent = (int)get('agent');
$filtreDebut = get('debut');
$filtreFin = get('fin');

$where = [];
$params = [];
if ($filtreAgent > 0) { $where[] = 'c.id_agent_dpip = ?'; $params[] = $filtreAgent; }
if ($filtreDebut !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtreDebut)) { $where[] = 'c.date_heure_connexion >= ?'; $params[] = $filtreDebut . ' 00:00:00'; }
if ($filtreFin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtreFin)) { $where[] = 'c.date_heure_connexion <= ?'; $params[] = $filtreFin . ' 23:59:59'; }
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare(
    "SELECT c.*, a.Nom, a.Prenom, a.Matricule, a.role,
            TIMESTAMPDIFF(SECOND, c.date_heure_connexion, c.date_heure_deconnexion) AS duree_s
     FROM connexion c
     JOIN agent_dpip a ON a.ID_agent_dpip = c.id_agent_dpip
     $sqlWhere
     ORDER BY c.date_heure_connexion DESC
     LIMIT 300"
);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) n, COUNT(DISTINCT id_agent_dpip) agents, SUM(date_heure_deconnexion IS NULL) ouvertes FROM connexion")->fetch();

/** Durée lisible à partir d'un nombre de secondes. */
function dureeLisible(?int $s): string
{
    if ($s === null) return '<span class="text-muted">session en cours</span>';
    if ($s < 60) return $s . ' s';
    if ($s < 3600) return floor($s / 60) . ' min';
    $h = floor($s / 3600); $m = floor(($s % 3600) / 60);
    return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
}

include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Historique des connexions et déconnexions des comptes : horodatage, adresse IP et navigateur utilisé. Utile pour le suivi de sécurité.</p>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon" style="background:#e7edf5;color:var(--pta-navy)"><i class="bi bi-box-arrow-in-right"></i></div><div><div class="stat-value"><?= (int)$stats['n'] ?></div><div class="stat-label">Connexions enregistrées</div></div></div></div>
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon" style="background:#e6f4ea;color:#1f7a45"><i class="bi bi-people"></i></div><div><div class="stat-value"><?= (int)$stats['agents'] ?></div><div class="stat-label">Comptes concernés</div></div></div></div>
    <div class="col-md-4"><div class="stat-card"><div class="stat-icon" style="background:#fff3e6;color:var(--pta-orange)"><i class="bi bi-hourglass-split"></i></div><div><div class="stat-value"><?= (int)$stats['ouvertes'] ?></div><div class="stat-label">Sessions ouvertes</div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Compte</label>
                <select name="agent" class="form-select">
                    <option value="">— Tous —</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['ID_agent_dpip'] ?>" <?= $filtreAgent === (int)$a['ID_agent_dpip'] ? 'selected' : '' ?>>
                            <?= h($a['Prenom'] . ' ' . $a['Nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Du</label>
                <input type="date" name="debut" value="<?= h($filtreDebut) ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Au</label>
                <input type="date" name="fin" value="<?= h($filtreFin) ?>" class="form-control">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> Filtrer</button>
                <?php if ($filtreAgent || $filtreDebut || $filtreFin): ?>
                    <a href="<?= BASE_URL ?>/admin/connexions.php" class="btn btn-light border">×</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr>
                <th>Compte</th><th>Connexion</th><th>Déconnexion</th><th>Durée</th><th>Adresse IP</th><th class="ps-5">Navigateur</th>
            </tr></thead>
            <tbody>
            <?php foreach ($lignes as $l): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= h($l['Prenom'] . ' ' . $l['Nom']) ?></div>
                        <div class="text-muted small"><?= h($l['Matricule']) ?> · <?= h(roleLabel($l['role'])) ?></div>
                    </td>
                    <td class="small"><?= formatDate($l['date_heure_connexion'], true) ?></td>
                    <td class="small"><?= $l['date_heure_deconnexion'] ? formatDate($l['date_heure_deconnexion'], true) : '<span class="text-muted">—</span>' ?></td>
                    <td class="small"><?= dureeLisible($l['duree_s'] !== null ? (int)$l['duree_s'] : null) ?></td>
                    <td class="small font-monospace"><?= h($l['adresse_ip'] ?: '—') ?></td>
                    <td class="small ps-5">
                        <?php $nav = navigateurDepuisUserAgent($l['user_agent'] ?? ''); ?>
                        <div class="text-muted" title="<?= h((string)($l['user_agent'] ?? '') ?: 'User-Agent non enregistré') ?>">
                            <i class="bi <?= h($nav['icone']) ?>"></i> <?= h($nav['navigateur']) ?><?php if ($nav['systeme'] !== ''): ?> · <?= h($nav['systeme']) ?><?php endif; ?><?php if ($nav['appareil'] === 'Mobile'): ?> · mobile<?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($lignes)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Aucune connexion enregistrée pour ces critères.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-2">300 dernières connexions affichées. Le navigateur est déduit de l'en-tête envoyé par l'appareil (survolez-le pour voir l'en-tête complet) : indicatif, car il peut être modifié par l'utilisateur.</p>
<?php include __DIR__ . '/../includes/footer.php'; ?>
