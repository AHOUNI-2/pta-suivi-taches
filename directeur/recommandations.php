<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$directeur = requireDirecteurAccess($pdo);
$pageTitle = 'Recommandations';
$dirId = (int)$directeur['ID_agent_dpip'];
$acteur = realUser();   // une secrétaire peut agir pour le directeur

$destinataires = destinatairesRecommandation($pdo, $dirId);
$idsDest = array_column($destinataires, null, 'ID_agent_dpip');
$data = ['ID_destinataire' => get('destinataire'), 'objet' => '', 'message' => '', 'importance' => 'normale'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    foreach (['ID_destinataire', 'objet', 'message', 'importance'] as $f) {
        $data[$f] = trim(post($f, $data[$f]));
    }
    if ($data['ID_destinataire'] === '' || !isset($idsDest[$data['ID_destinataire']])) {
        $errors[] = 'Sélectionnez un Sous-directeur ou un Responsable Service qui vous est rattaché.';
    }
    if ($data['objet'] === '') $errors[] = 'L\'objet est obligatoire.';
    if ($data['message'] === '') $errors[] = 'Le message est obligatoire.';
    if (!in_array($data['importance'], ['normale', 'importante', 'urgente'], true)) $data['importance'] = 'normale';

    if (!$errors) {
        $dest = $idsDest[$data['ID_destinataire']];
        $objet = mb_substr($data['objet'], 0, 150);
        $pdo->prepare('INSERT INTO recommandation (ID_emetteur, ID_destinataire, objet, message, importance) VALUES (?,?,?,?,?)')
            ->execute([$dirId, (int)$dest['ID_agent_dpip'], $objet, $data['message'], $data['importance']]);
        $newId = (int)$pdo->lastInsertId();
        createNotification(
            $pdo, (int)$dest['ID_agent_dpip'],
            'Recommandation du Directeur' . ($data['importance'] !== 'normale' ? ' (' . mb_strtolower(importanceRecommandationLabel($data['importance'])) . ')' : ''),
            $directeur['Prenom'] . ' ' . $directeur['Nom'] . ' vous adresse une recommandation : « ' . $objet . ' ».',
            '/responsable/recommandations.php'
        );
        logModif($pdo, 'recommandation', $newId, 'creation', 'Recommandation « ' . $objet . ' » adressée à ' . $dest['Prenom'] . ' ' . $dest['Nom'] . ' (' . roleLabelCompte($dest) . ')');
        flash('success', 'Recommandation envoyée à ' . $dest['Prenom'] . ' ' . $dest['Nom'] . ' (' . roleLabelCompte($dest) . ').');
        redirect('/directeur/recommandations.php');
    }
}

$envoyees = $pdo->prepare(
    "SELECT r.*, d.Nom, d.Prenom, d.role, d.type_responsable
     FROM recommandation r JOIN agent_dpip d ON d.ID_agent_dpip = r.ID_destinataire
     WHERE r.ID_emetteur = ? ORDER BY r.Date_creation DESC, r.id_recommandation DESC LIMIT 100"
);
$envoyees->execute([$dirId]);
$envoyees = $envoyees->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Adressez une recommandation à un <strong>Sous-directeur</strong> ou à un <strong>Responsable Service</strong> placé sous votre autorité. Il la reçoit dans son espace, avec une notification, et conserve son titre.</p>

<?php foreach ($errors as $err): ?><div class="alert alert-danger py-2"><?= h($err) ?></div><?php endforeach; ?>

<div class="card mb-4">
    <div class="card-header">Nouvelle recommandation</div>
    <div class="card-body">
        <?php if (empty($destinataires)): ?>
            <div class="alert alert-warning mb-0">Aucun Sous-directeur ni Responsable Service ne vous est rattaché pour le moment. L'administrateur doit vous désigner comme responsable hiérarchique (Utilisateurs &gt; Modifier).</div>
        <?php else: ?>
        <form method="post">
            <?= csrfField() ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Destinataire *</label>
                    <select name="ID_destinataire" class="form-select" required>
                        <option value="">— Choisir —</option>
                        <?php foreach ($destinataires as $d): ?>
                            <option value="<?= $d['ID_agent_dpip'] ?>" <?= (string)$data['ID_destinataire'] === (string)$d['ID_agent_dpip'] ? 'selected' : '' ?>>
                                <?= h($d['Prenom'] . ' ' . $d['Nom'] . ' — ' . roleLabelCompte($d)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Importance</label>
                    <select name="importance" class="form-select">
                        <?php foreach (['normale', 'importante', 'urgente'] as $imp): ?>
                            <option value="<?= $imp ?>" <?= $data['importance'] === $imp ? 'selected' : '' ?>><?= h(importanceRecommandationLabel($imp)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Objet *</label>
                    <input type="text" name="objet" class="form-control" maxlength="150" value="<?= h($data['objet']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Recommandation *</label>
                    <textarea name="message" class="form-control" rows="4" required><?= h($data['message']) ?></textarea>
                </div>
            </div>
            <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Envoyer la recommandation</button></div>
        </form>
        <?php endif; ?>
    </div>
</div>

<h2 class="h6 fw-bold mb-2">Recommandations envoyées</h2>
<?php foreach ($envoyees as $r): ?>
    <div class="card mb-2">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <strong><?= h($r['objet']) ?></strong>
                    <span class="badge <?= importanceRecommandationBadge($r['importance']) ?> ms-1"><?= h(importanceRecommandationLabel($r['importance'])) ?></span>
                </div>
                <span class="text-muted small"><?= formatDate($r['Date_creation'], true) ?></span>
            </div>
            <div class="small text-muted mb-1">À <?= h($r['Prenom'] . ' ' . $r['Nom']) ?> (<?= h(roleLabelCompte($r)) ?>)
                — <?php if ($r['lu']): ?><span class="text-success"><i class="bi bi-check2-all"></i> lue le <?= formatDate($r['date_lecture'], true) ?></span>
                <?php else: ?><span class="text-warning">non lue</span><?php endif; ?></div>
            <p class="mb-0"><?= nl2br(h($r['message'])) ?></p>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($envoyees)): ?><p class="text-muted">Aucune recommandation envoyée pour le moment.</p><?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
