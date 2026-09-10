<?php
/**
 * Champ « Rattachement » du formulaire d'activité (colonne activites.pere_ID).
 * Attend : $peres (candidatsPereActivite()), $data['pere_ID'], $data['Types'].
 * Le JavaScript associé (fonction majRattachement) est inclus une fois ici.
 */
$peres = $peres ?? [];
?>
<div class="col-12" id="pereWrapper" style="display:none;">
    <label class="form-label small fw-semibold" id="pereLabel">Rattachement</label>
    <select name="pere_ID" id="pereSelect" class="form-select">
        <option value="">— Choisir —</option>
        <?php foreach ($peres as $pp): ?>
            <option value="<?= (int)$pp['ID__activites'] ?>" data-ptype="<?= h($pp['Types']) ?>"
                    <?= (string)($data['pere_ID'] ?? '') === (string)$pp['ID__activites'] ? 'selected' : '' ?>>
                [<?= h(typeActiviteLabel($pp['Types'])) ?>] <?= h($pp['Libelle']) ?> — <?= h(trim(($pp['ag_prenom'] ?? '') . ' ' . ($pp['ag_nom'] ?? ''))) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <div class="form-text" id="pereHelp"></div>
</div>
<script>
function majRattachement() {
    var typeEl = document.querySelector('[name="Types"]');
    var wrap = document.getElementById('pereWrapper');
    if (!typeEl || !wrap) return;
    var sel  = document.getElementById('pereSelect');
    var lab  = document.getElementById('pereLabel');
    var help = document.getElementById('pereHelp');
    var t = typeEl.value;

    if (t === 'activite') {
        wrap.style.display = 'none';
        sel.required = false;
        sel.value = '';
        return;
    }
    wrap.style.display = '';
    sel.required = true;

    var autorises = (t === 'sous-activite') ? ['activite'] : ['activite', 'sous-activite'];
    var courant = sel.value, valide = false;
    Array.prototype.forEach.call(sel.options, function (o) {
        if (o.value === '') { return; }
        var ok = autorises.indexOf(o.getAttribute('data-ptype')) !== -1;
        o.hidden = !ok;
        o.disabled = !ok;
        if (ok && o.value === courant) { valide = true; }
    });
    if (!valide) { sel.value = ''; }

    if (t === 'sous-activite') {
        lab.textContent = 'Activité parente *';
        help.textContent = 'Une sous-activité est obligatoirement rattachée à une activité.';
    } else {
        lab.textContent = 'Rattachée à (activité ou sous-activité) *';
        help.textContent = 'Une tâche peut être rattachée à une sous-activité, ou directement à une activité principale.';
    }
}
document.addEventListener('DOMContentLoaded', function () {
    var typeEl = document.querySelector('[name="Types"]');
    if (typeEl) { typeEl.addEventListener('change', majRattachement); majRattachement(); }
});
</script>
