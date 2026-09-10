<?php
/**
 * Script à exécuter quotidiennement via une tâche planifiée (cron) pour :
 *  - mettre à jour automatiquement le statut des tâches en retard,
 *  - notifier (in-app + email) les agents dont une échéance approche (J-3 et J-1).
 *
 * Exemple de configuration cron (tous les jours à 7h) :
 *   0 7 * * * /usr/bin/php /chemin/vers/pta_suivi_taches/cron/check_echeances.php
 *
 * Ce script doit être exécuté en ligne de commande (CLI), jamais exposé publiquement sur le web.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut être exécuté qu\'en ligne de commande.');
}

require_once __DIR__ . '/../includes/bootstrap.php';

echo "[" . date('Y-m-d H:i:s') . "] Démarrage de la vérification des échéances...\n";

// 1. Mettre à jour les statuts en retard
actualiserTachesEnRetard($pdo);
echo "Statuts 'en_retard' mis à jour.\n";

// 2. Notifier pour les échéances à J-3 et J-1
foreach ([3, 1] as $joursAvant) {
    $dateCible = date('Y-m-d', strtotime("+{$joursAvant} day"));
    $stmt = $pdo->prepare(
        "SELECT a.*, ag.Nom, ag.Prenom, ag.Mail, ag.Mail_Pro
         FROM activites a
         JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
         WHERE a.Date_fin = ? AND a.Statut NOT IN ('termine', 'annule')"
    );
    $stmt->execute([$dateCible]);
    $taches = $stmt->fetchAll();

    foreach ($taches as $t) {
        createNotification(
            $pdo,
            (int)$t['ID_agent_dpip'],
            'Échéance proche',
            'La tâche "' . $t['Libelle'] . '" arrive à échéance dans ' . $joursAvant . ' jour(s).',
            '/tache_detail.php?id=' . $t['ID__activites']
        );
        mailEcheanceProche(['Prenom' => $t['Prenom'], 'Nom' => $t['Nom'], 'Mail' => $t['Mail'], 'Mail_Pro' => $t['Mail_Pro']], $t, $joursAvant);
        echo "Notification envoyée pour la tâche #{$t['ID__activites']} (échéance J-{$joursAvant}).\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Terminé.\n";
