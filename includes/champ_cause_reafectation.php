<?php
/**
 * Champ « Cause de la réaffectation » — à inclure dans les formulaires d'activité
 * (admin / responsable / directeur), juste après le menu de l'exécutant.
 *
 * Contexte attendu :
 *   $edition             (bool)        édition d'une activité existante
 *   $causesReafectation  (array)       causesReafectation($pdo)
 *   $execActuelId        (int|string)  id de l'exécutant actuel
 *   $data['id_cause'], $data['cause_commentaire']  (ré-affichage après erreur)
 *
 * Le <select> de l'exécutant du formulaire doit porter l'attribut
 * data-exec-select pour que le champ s'affiche quand on change d'agent.
 */
if (!empty($edition) && !empty($causesReafectation)):
?>
<div class="col-12" id="causeReafWrap" data-exec-actuel="<?= (int)($execActuelId ?? 0) ?>" style="display:none;">
    <div class="border rounded p-3" style="background:#fff6ef;">
        <label class="form-label small fw-semibold text-danger mb-1">
            <i class="bi bi-arrow-left-right"></i> Cause de la réaffectation *
        </label>
        <p class="form-text mt-0">Vous changez l'agent en charge : indiquez pourquoi (agent empêché, malade, en congés…). Conservé dans l'historique des réaffectations et notifié à l'ancien et au nouvel agent.</p>
        <div class="row g-2">
            <div class="col-md-5">
                <select name="id_cause" id="causeReafSelect" class="form-select">
                    <option value="">— Choisir la cause —</option>
                    <?php foreach ($causesReafectation as $c): ?>
                        <option value="<?= (int)$c['id_cause'] ?>" <?= (string)($data['id_cause'] ?? '') === (string)$c['id_cause'] ? 'selected' : '' ?>>
                            <?= h($c['libelle']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-7">
                <input type="text" name="cause_commentaire" class="form-control" maxlength="255"
                       placeholder="Précision (facultatif)" value="<?= h($data['cause_commentaire'] ?? '') ?>">
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var wrap = document.getElementById('causeReafWrap');
    if (!wrap) return;
    var exec = document.querySelector('[data-exec-select]');
    var sel = document.getElementById('causeReafSelect');
    var actuel = wrap.getAttribute('data-exec-actuel');
    function maj() {
        var change = !!exec && exec.value !== '' && String(exec.value) !== String(actuel);
        wrap.style.display = change ? '' : 'none';
        if (sel) {
            sel.required = change;
            if (!change) sel.value = '';
        }
    }
    if (exec) exec.addEventListener('change', maj);
    document.addEventListener('DOMContentLoaded', maj);
    maj();
})();
</script>
<?php endif; ?>
