<?php
/**
 * Champ « Priorité » à liste ouverte : on choisit une valeur proposée
 * (basse / moyenne / haute / urgente) ou on en saisit une autre.
 * Contexte attendu : $prioriteValeur (valeur courante ; 'moyenne' par défaut).
 */
$prioriteValeur = trim((string)($prioriteValeur ?? '')) ?: 'moyenne';
?>
<label class="form-label small fw-semibold">Priorité</label>
<input type="text" name="Priorite" class="form-control" list="prioriteOptions" maxlength="50"
       value="<?= h($prioriteValeur) ?>" placeholder="moyenne" autocomplete="off">
<datalist id="prioriteOptions">
    <?php foreach (prioritesActivite() as $p): ?><option value="<?= h($p) ?>"><?= h(prioriteLabel($p)) ?></option><?php endforeach; ?>
</datalist>
<div class="form-text">Choisissez dans la liste ou saisissez une autre priorité.</div>
