<?php
/**
 * Calendrier du tableau de bord — 3 vues : Mois / Semaine / Jour.
 * Matérialise la CHARGE de chaque journée par la couleur :
 *   bleu foncé = 5 activités et +, bleu clair = 2 à 4, gris = 1 ou moins.
 *
 * Contexte attendu (défini par le dashboard qui l'inclut) :
 *   $pdo              connexion PDO
 *   $calNavUrl        URL de la page courante (BASE_URL inclus)
 *   $calFiltreSql     condition SQL supplémentaire (préfixe "a.") ou '' — facultatif
 *   $calFiltreParams  valeurs de la condition — facultatif
 */
$calNavUrl       = $calNavUrl       ?? '';
$calFiltreSql    = $calFiltreSql    ?? '';
$calFiltreParams = $calFiltreParams ?? [];

$vue = get('vue');
if (!in_array($vue, ['mois', 'semaine', 'jour'], true)) {
    $vue = 'mois';
}

// Date de référence : ?ref=Y-m-d, ou rétro-compat ?mois=Y-m, sinon aujourd'hui.
$ref = get('ref');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref)) {
    $m = get('mois');
    $ref = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m . '-01' : date('Y-m-d');
}
$refTs = strtotime($ref);
$auj   = date('Y-m-d');

// Bornes de la plage selon la vue.
if ($vue === 'mois') {
    $rangeDebut = date('Y-m-01', $refTs);
    $rangeFin   = date('Y-m-t', $refTs);
    $prevRef    = date('Y-m-d', strtotime($rangeDebut . ' -1 month'));
    $nextRef    = date('Y-m-d', strtotime($rangeDebut . ' +1 month'));
    $titre      = nomMoisFr(date('Y-m', $refTs));
} elseif ($vue === 'semaine') {
    $dow        = (int)date('N', $refTs);                 // 1 = lundi
    $rangeDebut = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $refTs));
    $rangeFin   = date('Y-m-d', strtotime($rangeDebut . ' +6 days'));
    $prevRef    = date('Y-m-d', strtotime($rangeDebut . ' -7 days'));
    $nextRef    = date('Y-m-d', strtotime($rangeDebut . ' +7 days'));
    $titre      = 'Semaine du ' . (int)date('j', strtotime($rangeDebut))
                . ' au ' . nomJourFr($rangeFin);
} else { // jour
    $rangeDebut = $rangeFin = date('Y-m-d', $refTs);
    $prevRef    = date('Y-m-d', strtotime($rangeDebut . ' -1 day'));
    $nextRef    = date('Y-m-d', strtotime($rangeDebut . ' +1 day'));
    $titre      = nomJourFr($rangeDebut);
}

$calActivites = activitesPeriode($pdo, $rangeDebut, $rangeFin, $calFiltreSql, $calFiltreParams);

// Jours de la plage.
$jours = [];
for ($t = strtotime($rangeDebut); $t <= strtotime($rangeFin); $t = strtotime('+1 day', $t)) {
    $jours[] = date('Y-m-d', $t);
}

// Regroupement par jour + données pour la modale.
$parJour = [];
$calData = [];
foreach ($jours as $jr) {
    foreach ($calActivites as $a) {
        if ($a['Date_debut'] <= $jr && $a['Date_fin'] >= $jr) {
            $parJour[$jr][] = $a;
        }
    }
    if (!empty($parJour[$jr])) {
        $calData[$jr] = array_map(function ($a) {
            $cat = dureeCategorieActivite($a['Date_debut'], $a['Date_fin']);
            return [
                'id'           => (int)$a['ID__activites'],
                'libelle'      => $a['Libelle'],
                'type'         => typeActiviteLabel($a['Types']),
                'debut'        => formatDate($a['Date_debut']),
                'fin'          => formatDate($a['Date_fin']),
                'statut'       => statutLabel($a['Statut']),
                'couleur'      => statutColor($a['Statut']),
                'duree'        => dureeCategorieLabel($cat),
                'dureeCouleur' => dureeCategorieColor($cat),
                'priorite'     => prioriteLabel($a['Priorite']),
                'agent'        => trim(($a['ag_prenom'] ?? '') . ' ' . ($a['ag_nom'] ?? '')),
                'resp'         => trim(($a['resp_prenom'] ?? '') . ' ' . ($a['resp_nom'] ?? '')),
                'produit'      => $a['Produit'] ?: '—',
                'url'          => BASE_URL . '/tache_detail.php?id=' . (int)$a['ID__activites'],
            ];
        }, $parJour[$jr]);
    }
}

// Résumé de charge sur la plage.
$compteNiv = ['charge' => 0, 'modere' => 0, 'relache' => 0];
foreach ($jours as $jr) {
    $n = count($parJour[$jr] ?? []);
    if ($n > 0) {
        $compteNiv[chargeNiveau($n)]++;
    }
}
$totalPeriode = count($calActivites);

// Petit générateur d'URL pour la navigation.
$lien = fn(string $v, string $r): string => h($calNavUrl) . '?vue=' . $v . '&ref=' . $r;

// Style de fond d'une case selon le nombre d'activités.
$fondCase = function (int $n): string {
    if ($n <= 0) return '';
    $niv   = chargeNiveau($n);
    $alpha = ['relache' => 0.12, 'modere' => 0.20, 'charge' => 0.30][$niv];
    return 'border-color:' . chargeColor($niv) . ';background:' . hexRgba(chargeColor($niv), $alpha) . ';';
};
$pilule = function (int $n): string {
    $niv = chargeNiveau($n);
    $txt = $niv === 'charge' ? '#fff' : '#12395c';
    return 'background:' . chargeColor($niv) . ';color:' . $txt . ';';
};
?>
<style>
.cal-wrap { --cal-border:#e2e6ec; }
.cal-head { display:flex; align-items:center; justify-content:space-between; gap:.5rem .75rem; margin-bottom:.75rem; flex-wrap:wrap; }
.cal-vues .btn { border-radius:999px; }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.cal-dow { text-align:center; font-size:.72rem; font-weight:600; text-transform:uppercase; color:var(--pta-text-muted); padding:2px 0; }
.cal-day { min-height:84px; border:1px solid var(--cal-border); border-radius:8px; padding:4px 6px; background:#fff; position:relative; }
.cal-day.is-out { background:#f7f8fa; }
.cal-day.is-today .cal-num { color:var(--pta-navy); text-decoration:underline; }
.cal-num { font-size:.75rem; color:var(--pta-text-muted); font-weight:600; }
.cal-day.has-acts { border-width:2px; cursor:pointer; }
.cal-day.has-acts .cal-num { color:#12395c; }
.cal-day.has-acts:hover { box-shadow:0 0 0 2px rgba(15,41,66,.18); }
.cal-count { display:inline-flex; align-items:center; gap:4px; margin-top:8px; border:none; border-radius:999px;
             padding:3px 10px; font-size:.72rem; font-weight:700; line-height:1.3; cursor:pointer; }
.cal-count:hover { filter:brightness(1.06); }

/* Vue Semaine */
.cal-week .cal-day { min-height:168px; }
.cal-wk-head { font-size:.72rem; font-weight:700; color:#12395c; }
.cal-chip { display:block; margin-top:4px; padding:2px 5px; border-radius:4px; background:#f4f6f9;
            border-left:3px solid var(--cc,#0f2942); font-size:.7rem; line-height:1.3;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-more { display:block; margin-top:4px; font-size:.68rem; color:var(--pta-navy); }

/* Vue Jour */
.cal-day-banner { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;
                  border:1px solid var(--cal-border); border-left:5px solid var(--cc,#adb5bd);
                  border-radius:10px; padding:12px 16px; margin-bottom:14px; }
.cal-day-banner b { font-size:1.05rem; color:#12395c; }
.cal-jour-item { border:1px solid var(--cal-border); border-radius:8px; padding:10px 12px; margin-bottom:8px; }

@media (max-width:576px){ .cal-day{ min-height:58px; } .cal-count{ font-size:.66rem; padding:2px 7px; } .cal-week .cal-day{ min-height:120px; } }
</style>

<div class="cal-wrap">
    <div class="cal-head">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= $lien($vue, $prevRef) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
            <strong class="h6 mb-0"><?= h($titre) ?></strong>
            <a href="<?= $lien($vue, $nextRef) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            <?php if ($rangeDebut > $auj || $rangeFin < $auj): ?>
                <a href="<?= $lien($vue, $auj) ?>" class="btn btn-sm btn-link">Aujourd'hui</a>
            <?php endif; ?>
        </div>
        <div class="cal-vues btn-group btn-group-sm" role="group">
            <a href="<?= $lien('mois', $ref) ?>"    class="btn <?= $vue === 'mois'    ? 'btn-primary' : 'btn-outline-secondary' ?>">Mois</a>
            <a href="<?= $lien('semaine', $ref) ?>" class="btn <?= $vue === 'semaine' ? 'btn-primary' : 'btn-outline-secondary' ?>">Semaine</a>
            <a href="<?= $lien('jour', $ref) ?>"    class="btn <?= $vue === 'jour'    ? 'btn-primary' : 'btn-outline-secondary' ?>">Jour</a>
        </div>
    </div>

    <?php if ($totalPeriode > 0): ?>
    <p class="small text-muted mb-2">
        <?= (int)$totalPeriode ?> activité(s) sur la période. Cliquez sur une case pour dérouler la liste.
    </p>
    <?php endif; ?>

    <?php /* ================= VUE MOIS ================= */ ?>
    <?php if ($vue === 'mois'):
        $premier  = $rangeDebut;
        $nbJours  = (int)date('t', strtotime($premier));
        $decalage = ((int)date('N', strtotime($premier))) - 1;
    ?>
    <div class="cal-grid mb-1">
        <?php foreach (['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'] as $d): ?><div class="cal-dow"><?= $d ?></div><?php endforeach; ?>
    </div>
    <div class="cal-grid">
        <?php for ($i = 0; $i < $decalage; $i++): ?><div class="cal-day is-out"></div><?php endfor; ?>
        <?php for ($j = 1; $j <= $nbJours; $j++):
            $jr   = sprintf('%s-%02d', date('Y-m', strtotime($premier)), $j);
            $acts = $parJour[$jr] ?? [];
            $n    = count($acts);
        ?>
            <div class="cal-day <?= $jr === $auj ? 'is-today' : '' ?> <?= $n ? 'has-acts' : '' ?>"
                 style="<?= $fondCase($n) ?>"
                 <?= $n ? 'data-bs-toggle="modal" data-bs-target="#calModal" data-jour="' . $jr . '" role="button" tabindex="0" aria-label="' . $n . ' activité(s)"' : '' ?>>
                <div class="cal-num"><?= $j ?></div>
                <?php if ($n): ?>
                    <span class="cal-count" style="<?= $pilule($n) ?>"><?= $n ?> activité<?= $n > 1 ? 's' : '' ?></span>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>

    <?php /* ================= VUE SEMAINE ================= */ ?>
    <?php elseif ($vue === 'semaine'): ?>
    <div class="cal-grid mb-1">
        <?php foreach ($jours as $jr): ?>
            <div class="cal-dow"><?= h(mb_substr(nomJourFr($jr), 0, 3)) ?> <?= (int)date('j', strtotime($jr)) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="cal-grid cal-week">
        <?php foreach ($jours as $jr):
            $acts = $parJour[$jr] ?? [];
            $n    = count($acts);
        ?>
            <div class="cal-day <?= $jr === $auj ? 'is-today' : '' ?> <?= $n ? 'has-acts' : '' ?>"
                 style="<?= $fondCase($n) ?>"
                 <?= $n ? 'data-bs-toggle="modal" data-bs-target="#calModal" data-jour="' . $jr . '" role="button" tabindex="0"' : '' ?>>
                <?php if ($n): ?>
                    <span class="cal-count" style="<?= $pilule($n) ?>"><?= $n ?> activité<?= $n > 1 ? 's' : '' ?></span>
                    <?php foreach (array_slice($acts, 0, 4) as $a): ?>
                        <span class="cal-chip" style="--cc:<?= h(statutColor($a['Statut'])) ?>" title="<?= h($a['Libelle']) ?>">
                            <?= h($a['Libelle']) ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if ($n > 4): ?><span class="cal-more">+ <?= $n - 4 ?> autre(s)</span><?php endif; ?>
                <?php else: ?>
                    <span class="text-muted small">—</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php /* ================= VUE JOUR ================= */ ?>
    <?php else:
        $acts = $parJour[$rangeDebut] ?? [];
        $n    = count($acts);
        $niv  = chargeNiveau($n);
        usort($acts, function ($x, $y) {
            $ordre = ['urgente' => 0, 'haute' => 1, 'moyenne' => 2, 'basse' => 3];
            $px = $ordre[$x['Priorite']] ?? 2;
            $py = $ordre[$y['Priorite']] ?? 2;
            return $px <=> $py ?: strcmp($x['Date_fin'], $y['Date_fin']);
        });
    ?>
    <div class="cal-day-banner" style="--cc:<?= chargeColor($niv) ?>">
        <div><b><?= h(nomJourFr($rangeDebut)) ?></b></div>
        <div>
            <span class="badge" style="<?= $pilule($n) ?>"><?= $n ?> activité<?= $n > 1 ? 's' : '' ?></span>
        </div>
    </div>
    <?php if ($n === 0): ?>
        <p class="text-muted">Aucune activité active ce jour.</p>
    <?php else: ?>
        <?php foreach ($acts as $a): ?>
            <div class="cal-jour-item">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <strong><?= h($a['Libelle']) ?></strong>
                    <span>
                        <span class="badge" style="background:<?= h(statutColor($a['Statut'])) ?>"><?= h(statutLabel($a['Statut'])) ?></span>
                        <span class="badge bg-light text-dark border"><?= h(typeActiviteLabel($a['Types'])) ?></span>
                    </span>
                </div>
                <div class="small text-muted mt-1">
                    Période : <span class="text-primary fw-semibold"><?= formatDate($a['Date_debut']) ?></span> →
                    <span class="text-primary fw-semibold"><?= formatDate($a['Date_fin']) ?></span>
                    &nbsp;·&nbsp; Priorité : <?= h(prioriteLabel($a['Priorite'])) ?>
                </div>
                <div class="small text-muted">
                    Attribué à : <span class="text-primary fw-semibold"><?= h(trim(($a['ag_prenom'] ?? '') . ' ' . ($a['ag_nom'] ?? ''))) ?></span>
                    &nbsp;·&nbsp; par : <span class="text-primary fw-semibold"><?= h(trim(($a['resp_prenom'] ?? '') . ' ' . ($a['resp_nom'] ?? ''))) ?></span>
                </div>
                <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= (int)$a['ID__activites'] ?>" class="btn btn-sm btn-outline-primary mt-2">Ouvrir la fiche</a>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>

    <div class="d-flex flex-wrap gap-3 mt-3 small align-items-center">
        <span class="text-muted fw-semibold">Légende&nbsp;:</span>
        <?php foreach ([
            'charge'  => 'Mensuelle',
            'modere'  => 'Hebdomadaire',
            'relache' => 'Journalière',
        ] as $niv => $texte): ?>
            <span class="d-inline-flex align-items-center gap-1">
                <span style="width:15px;height:15px;border-radius:4px;border:1px solid rgba(0,0,0,.12);background:<?= chargeColor($niv) ?>;display:inline-block;"></span>
                <?= h($texte) ?>
            </span>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="calModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="calModalTitle">Activité</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body" id="calModalBody"></div>
        </div>
    </div>
</div>

<script>
window.__CAL = <?= json_encode($calData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('calModal');
    if (!modal) return;
    document.querySelectorAll('.cal-day.has-acts').forEach(function (cell) {
        cell.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cell.click(); }
        });
    });
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };
    var carte = function (a) {
        return '<div class="border rounded p-2 mb-2">'
            + '<div class="d-flex justify-content-between align-items-start gap-2">'
            + '<span class="badge" style="background:' + esc(a.couleur) + '">' + esc(a.statut) + '</span>'
            + '<span class="badge bg-light text-dark border">' + esc(a.type) + '</span></div>'
            + '<div class="fw-bold mt-1">' + esc(a.libelle) + '</div>'
            + '<div class="small text-muted">Période : '
            +   '<span class="text-primary fw-semibold">' + esc(a.debut) + '</span> → '
            +   '<span class="text-primary fw-semibold">' + esc(a.fin) + '</span>'
            +   ' <span class="badge bg-light text-dark border ms-1"><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:' + esc(a.dureeCouleur) + ';margin-right:4px"></span>' + esc(a.duree) + '</span>'
            + '</div>'
            + '<div class="small text-muted">Attribué à : <span class="text-primary fw-semibold">' + esc(a.agent) + '</span></div>'
            + '<div class="small text-muted">Attribué par : <span class="text-primary fw-semibold">' + esc(a.resp) + '</span></div>'
            + '<div class="small text-muted">Priorité : ' + esc(a.priorite) + ' · Produit : ' + esc(a.produit) + '</div>'
            + '<a href="' + esc(a.url) + '" class="btn btn-sm btn-outline-primary mt-2">Ouvrir la fiche</a>'
            + '</div>';
    };
    modal.addEventListener('show.bs.modal', function (ev) {
        var t = ev.relatedTarget; if (!t) return;
        var jour = t.getAttribute('data-jour');
        var liste = (window.__CAL && window.__CAL[jour]) ? window.__CAL[jour] : [];
        document.getElementById('calModalTitle').textContent = liste.length + ' activité(s) — ' + jour.split('-').reverse().join('/');
        document.getElementById('calModalBody').innerHTML = liste.map(carte).join('') || '<p class="text-muted mb-0">Aucune activité.</p>';
    });
});
</script>
