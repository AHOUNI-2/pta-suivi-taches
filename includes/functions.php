<?php
/**
 * Fonctions utilitaires générales
 */

/** Nettoie une chaîne pour affichage sécurisé en HTML */
function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirige vers une URL relative à la racine de l'application et arrête le script */
function redirect(string $path): void
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

/** Dépose un message flash en session (affiché puis supprimé à la prochaine page) */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Récupère et vide les messages flash */
function getFlashMessages(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Formate une date SQL (Y-m-d ou Y-m-d H:i:s) en format lisible fr */
function formatDate(?string $date, bool $withTime = false): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '—';
    }
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return '—';
    }
    return $withTime ? date('d/m/Y à H:i', $timestamp) : date('d/m/Y', $timestamp);
}

/** Temps écoulé de manière relative (ex: "il y a 2 heures") */
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'à l\'instant';
    if ($diff < 3600) return 'il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . floor($diff / 3600) . ' h';
    if ($diff < 2592000) return 'il y a ' . floor($diff / 86400) . ' j';
    return formatDate($datetime);
}

/** Libellés des statuts de tâche */
/**
 * Libellé d'un statut. Pour « en_retard », passer $progression (colonne
 * `Progression` de la ligne) affine le libellé pour distinguer une tâche en
 * retard jamais commencée (0 %) d'une tâche en retard déjà entamée (> 0 %) —
 * sans quoi les deux se ressemblent alors qu'elles n'ont rien à voir. Omettre
 * $progression (ex. dans un menu déroulant de filtre, sans ligne précise)
 * conserve le libellé générique « En retard ».
 */
function statutLabel(string $statut, ?int $progression = null): string
{
    if ($statut === 'en_retard' && $progression !== null) {
        return $progression > 0 ? 'En retard (déjà commencée)' : 'En retard (non commencée)';
    }
    return [
        'a_faire' => 'À faire',
        'en_cours' => 'En cours',
        'en_attente' => 'En attente',
        'termine' => 'Terminé',
        'en_retard' => 'En retard',
        'annule' => 'Annulé',
    ][$statut] ?? $statut;
}

/** Couleur (hex) associée à un statut de tâche — utilisée pour les diagrammes et leur légende */
function statutColor(string $statut): string
{
    return [
        'a_faire'    => '#9fd49f', // vert clair : à faire
        'en_cours'   => '#0a4d0a',
        'en_attente' => '#f5a623',
        'termine'    => '#2e9e2e', // vert : terminé
        'en_retard'  => '#dc3545',
        'annule'     => '#343a40',
    ][$statut] ?? '#9fd49f';
}

/**
 * Légende HTML d'un diagramme de répartition par statut : une pastille de couleur,
 * le libellé et le nombre pour chaque statut. $counts : [statut => nombre].
 */
function legendeStatuts(array $counts): string
{
    $ordre = ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'];
    $total = array_sum($counts);
    $html = '<div class="d-flex flex-wrap gap-3 mt-3 small">';
    foreach ($ordre as $s) {
        $n = (int)($counts[$s] ?? 0);
        $pct = $total > 0 ? round($n * 100 / $total) : 0;
        $html .= '<span class="d-inline-flex align-items-center gap-1">'
            . '<span style="width:11px;height:11px;border-radius:3px;background:' . statutColor($s) . ';display:inline-block;flex-shrink:0;"></span>'
            . h(statutLabel($s)) . ' <strong>' . $n . '</strong>'
            . '<span class="text-muted">(' . $pct . '%)</span>'
            . '</span>';
    }
    $html .= '</div>';
    return $html;
}

/** Classe Bootstrap du badge pour un statut de tâche */
function statutBadgeClass(string $statut): string
{
    return [
        'a_faire' => 'bg-secondary',
        'en_cours' => 'bg-primary',
        'en_attente' => 'bg-warning text-dark',
        'termine' => 'bg-success',
        'en_retard' => 'bg-danger',
        'annule' => 'bg-dark',
    ][$statut] ?? 'bg-secondary';
}

/** Priorités proposées par défaut (la valeur reste libre : liste ouverte) */
function prioritesActivite(): array
{
    return ['basse', 'moyenne', 'haute', 'urgente'];
}

/** Libellés des priorités — une valeur hors liste est affichée telle quelle */
function prioriteLabel(string $priorite): string
{
    $priorite = trim($priorite);
    return [
        'basse' => 'Basse',
        'moyenne' => 'Moyenne',
        'haute' => 'Haute',
        'urgente' => 'Urgente',
    ][$priorite] ?? ($priorite === '' ? '—' : ucfirst($priorite));
}

/**
 * Catégorie de durée d'une activité d'après sa période [Date_debut, Date_fin] :
 *   journaliere  = 1 jour
 *   hebdomadaire = 2 à 7 jours
 *   mensuelle    = 8 jours et plus
 * Sert à colorer les boutons du calendrier du tableau de bord.
 */
function dureeCategorieActivite(?string $debut, ?string $fin): string
{
    $d = $debut ? strtotime($debut) : false;
    $f = $fin ? strtotime($fin) : false;
    if ($d === false || $f === false || $f < $d) {
        return 'journaliere';
    }
    $jours = (int)floor(($f - $d) / 86400) + 1;
    if ($jours <= 1) return 'journaliere';
    if ($jours <= 7) return 'hebdomadaire';
    return 'mensuelle';
}

/** Libellé d'une catégorie de durée */
function dureeCategorieLabel(string $cat): string
{
    return [
        'journaliere'  => 'Journalière',
        'hebdomadaire' => 'Hebdomadaire',
        'mensuelle'    => 'Mensuelle',
    ][$cat] ?? $cat;
}

/** Couleur d'une catégorie de durée : vert foncé / vert clair / gris */
function dureeCategorieColor(string $cat): string
{
    return [
        'mensuelle'    => '#0a4d0a', // vert foncé
        'hebdomadaire' => '#9fd49f', // vert clair
        'journaliere'  => '#adb5bd', // gris
    ][$cat] ?? '#adb5bd';
}

/**
 * Catégorie « dominante » d'un ensemble d'activités (pour teinter une case de
 * calendrier) : mensuelle l'emporte sur hebdomadaire, qui l'emporte sur journalière.
 * $activites : lignes ayant Date_debut / Date_fin.
 */
function dureeCategorieDominante(array $activites): string
{
    $cats = [];
    foreach ($activites as $a) {
        $cats[dureeCategorieActivite($a['Date_debut'] ?? null, $a['Date_fin'] ?? null)] = true;
    }
    if (isset($cats['mensuelle'])) return 'mensuelle';
    if (isset($cats['hebdomadaire'])) return 'hebdomadaire';
    return 'journaliere';
}

/** Convertit "#rrggbb" en "rgba(r, g, b, a)" */
function hexRgba(string $hex, float $alpha): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "rgba($r, $g, $b, " . round($alpha, 3) . ")";
}

/** Pourcentage entier de $n sur $total (0 si total nul) */
function pourcentage(int $n, int $total): int
{
    return $total > 0 ? (int)round($n * 100 / $total) : 0;
}

/** Classe Bootstrap du badge pour une priorité */
function prioriteBadgeClass(string $priorite): string
{
    return [
        'basse' => 'bg-light text-dark border',
        'moyenne' => 'bg-info text-dark',
        'haute' => 'bg-warning text-dark',
        'urgente' => 'bg-danger',
    ][$priorite] ?? 'bg-secondary';
}

/**
 * Code d'identification d'un département, dérivé dynamiquement de son id.
 * Ex. : id 1 -> "DPT_001", id 12 -> "DPT_012". L'id reste stocké tel quel en base.
 */
function codeDepartement(int|string|null $idDepartement): string
{
    return 'DPT_' . str_pad((string)(int)$idDepartement, 3, '0', STR_PAD_LEFT);
}

/** Valide un mois au format "YYYY-MM", renvoie le mois courant sinon */
function moisValide(?string $mois): string
{
    return ($mois && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) ? $mois : date('Y-m');
}

/** Nom du mois en français, ex : "Septembre 2026" */
function nomMoisFr(string $mois): string
{
    $noms = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    [$y, $m] = explode('-', $mois);
    return ($noms[(int)$m] ?? $m) . ' ' . $y;
}

/**
 * Activités (activités / sous-activités / tâches) dont la période chevauche le mois donné,
 * pour l'affichage du calendrier du tableau de bord.
 * $filtreSql : condition SQL supplémentaire (avec préfixe "a.") ou '' ; $params : ses valeurs.
 */
function activitesDuMois(PDO $pdo, string $mois, string $filtreSql = '', array $params = []): array
{
    $debut = $mois . '-01';
    return activitesPeriode($pdo, $debut, date('Y-m-t', strtotime($debut)), $filtreSql, $params);
}

/**
 * Activités dont la période [Date_debut, Date_fin] chevauche l'intervalle
 * [$dateDebut, $dateFin] (format Y-m-d). $filtreSql : condition SQL (préfixe "a.")
 * ou '' ; $params : ses valeurs.
 */
function activitesPeriode(PDO $pdo, string $dateDebut, string $dateFin, string $filtreSql = '', array $params = []): array
{
    $sql = "SELECT a.ID__activites, a.Libelle, a.Types, a.Date_debut, a.Date_fin, a.Statut, a.Progression, a.Priorite,
                   a.Produit, a.Observation,
                   ag.Prenom AS ag_prenom, ag.Nom AS ag_nom,
                   r.Prenom AS resp_prenom, r.Nom AS resp_nom
            FROM activites a
            JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
            JOIN agent_dpip r  ON r.ID_agent_dpip = a.ID_responsable
            WHERE a.Date_debut <= ? AND a.Date_fin >= ?";
    $p = [$dateFin, $dateDebut];
    if ($filtreSql !== '') {
        $sql .= " AND (" . $filtreSql . ")";
        foreach ($params as $x) { $p[] = $x; }
    }
    $sql .= " ORDER BY a.Date_debut, a.Date_fin";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/** Niveau de charge d'une journée d'après son nombre d'activités actives */
function chargeNiveau(int $n): string
{
    if ($n >= 5) return 'charge';
    if ($n >= 2) return 'modere';
    return 'relache';           // 0 ou 1
}

/** Libellé d'un niveau de charge */
function chargeLabel(string $niveau): string
{
    return ['charge' => 'Chargé', 'modere' => 'Modéré', 'relache' => 'Relâche'][$niveau] ?? $niveau;
}

/** Couleur d'un niveau de charge : vert foncé / vert clair / gris */
function chargeColor(string $niveau): string
{
    return ['charge' => '#0a4d0a', 'modere' => '#9fd49f', 'relache' => '#adb5bd'][$niveau] ?? '#adb5bd';
}

/** Libellé d'un contexte de retour du responsable */
function contexteRetourLabel(string $contexte): string
{
    return [
        'sans_reaction' => "L'agent n'a pas encore réagi",
        'en_cours'      => "L'agent a commencé",
        'termine'       => "L'agent a terminé",
        'autre'         => 'Autre',
    ][$contexte] ?? $contexte;
}

/** Badge Bootstrap d'un contexte de retour */
function contexteRetourBadge(string $contexte): string
{
    return [
        'sans_reaction' => 'bg-danger',
        'en_cours'      => 'bg-primary',
        'termine'       => 'bg-success',
        'autre'         => 'bg-secondary',
    ][$contexte] ?? 'bg-secondary';
}

/** Contexte pré-suggéré d'après le statut de l'activité et le nombre de comptes rendus */
function contexteRetourAuto(string $statut, int $nbComptesRendus): string
{
    if ($statut === 'termine') return 'termine';
    if ($statut === 'annule')  return 'autre';
    if ($statut === 'a_faire' && $nbComptesRendus === 0) return 'sans_reaction';
    return 'en_cours';
}

/** Date longue en français, ex. "Vendredi 12 septembre 2026" */
function nomJourFr(string $ymd): string
{
    $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
    $mois  = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($ymd);
    return $jours[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' ' . ($mois[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}

/** Libellés du type d'entrée du plan (activité / sous-activité / tâche) */
function typeActiviteLabel(string $type): string
{
    return [
        'activite' => 'Activité',
        'sous-activite' => 'Sous-activité',
        'tache' => 'Tâche',
    ][$type] ?? $type;
}

/** Classe Bootstrap du badge pour un type d'entrée du plan */
function typeActiviteBadgeClass(string $type): string
{
    return [
        'activite' => 'bg-type-activite text-white',
        'sous-activite' => 'bg-type-sous-activite text-dark',
        'tache' => 'bg-light text-dark border',
    ][$type] ?? 'bg-light text-dark border';
}

/** Liste des types d'entrée du plan */
function typesActivite(): array
{
    return ['activite', 'sous-activite', 'tache'];
}

/**
 * Candidats « parent » pour le rattachement d'une activité :
 * les activités (Types='activite') et sous-activités (Types='sous-activite').
 * $excludeId : id à exclure (l'entrée en cours d'édition).
 */
function candidatsPereActivite(PDO $pdo, ?int $excludeId = null): array
{
    $sql = "SELECT a.ID__activites, a.Libelle, a.Types, a.Date_debut, a.Date_fin,
                   ag.Prenom AS ag_prenom, ag.Nom AS ag_nom
            FROM activites a
            JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
            WHERE a.Types IN ('activite','sous-activite')";
    $p = [];
    if ($excludeId) { $sql .= " AND a.ID__activites <> ?"; $p[] = $excludeId; }
    $sql .= " ORDER BY FIELD(a.Types,'activite','sous-activite'), a.Libelle";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}

/**
 * Valide le rattachement (pere_ID) d'une activité selon son type.
 *  - activite      : aucun parent
 *  - sous-activite : parent obligatoire, doit être une activite
 *  - tache         : parent obligatoire, une activite OU une sous-activite
 * Renvoie [pere_ID (int|null), message d'erreur (string|null)].
 */
function validerPereActivite(PDO $pdo, string $type, $pereBrut, ?int $selfId = null): array
{
    if ($type === 'activite') {
        return [null, null];
    }
    $pere = ($pereBrut !== '' && $pereBrut !== null) ? (int)$pereBrut : 0;
    if (!$pere) {
        return [null, $type === 'sous-activite'
            ? 'Une sous-activité doit être rattachée à une activité.'
            : 'Une tâche doit être rattachée à une activité ou une sous-activité.'];
    }
    if ($selfId && $pere === $selfId) {
        return [null, 'Une activité ne peut pas être rattachée à elle-même.'];
    }
    $st = $pdo->prepare('SELECT Types FROM activites WHERE ID__activites = ?');
    $st->execute([$pere]);
    $tParent = $st->fetchColumn();
    if ($tParent === false) {
        return [null, 'Rattachement invalide.'];
    }
    if ($type === 'sous-activite' && $tParent !== 'activite') {
        return [null, 'Une sous-activité doit être rattachée à une activité.'];
    }
    if ($type === 'tache' && !in_array($tParent, ['activite', 'sous-activite'], true)) {
        return [null, 'Une tâche doit être rattachée à une activité ou une sous-activité.'];
    }
    return [$pere, null];
}

/** Référentiel des causes de réaffectation (id_cause => libelle), trié */
function causesReafectation(PDO $pdo): array
{
    return $pdo->query('SELECT id_cause, libelle FROM cause_reafectation ORDER BY libelle')->fetchAll();
}

/**
 * Enregistre dans l'historique un changement d'agent exécutant d'une activité.
 * $auteurId : personne habilitée qui a fait la réaffectation (responsable, admin,
 * directeur ou secrétaire). $commentaire : précision libre facultative.
 */
function enregistrerReafectation(PDO $pdo, int $activiteId, ?int $ancienAgent, ?int $nouvelAgent, ?int $idCause, ?int $auteurId, ?string $commentaire = null): void
{
    $pdo->prepare(
        'INSERT INTO reafectation (ID_activites, ID_ancien_agent, ID_nouvel_agent, id_cause, ID_responsable, Commentaire)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $activiteId,
        $ancienAgent ?: null,
        $nouvelAgent ?: null,
        $idCause ?: null,
        $auteurId ?: null,
        ($commentaire !== null && trim($commentaire) !== '') ? trim($commentaire) : null,
    ]);
}

/** Historique des réaffectations d'une activité (plus récente en premier) */
function reafectationsActivite(PDO $pdo, int $activiteId): array
{
    $st = $pdo->prepare(
        "SELECT rf.*, c.libelle AS cause_libelle,
                aa.Prenom AS ancien_prenom, aa.Nom AS ancien_nom,
                na.Prenom AS nouvel_prenom, na.Nom AS nouvel_nom,
                au.Prenom AS auteur_prenom, au.Nom AS auteur_nom
         FROM reafectation rf
         LEFT JOIN cause_reafectation c ON c.id_cause = rf.id_cause
         LEFT JOIN agent_dpip aa ON aa.ID_agent_dpip = rf.ID_ancien_agent
         LEFT JOIN agent_dpip na ON na.ID_agent_dpip = rf.ID_nouvel_agent
         LEFT JOIN agent_dpip au ON au.ID_agent_dpip = rf.ID_responsable
         WHERE rf.ID_activites = ?
         ORDER BY rf.Date_creation DESC, rf.id_reafectation DESC"
    );
    $st->execute([$activiteId]);
    return $st->fetchAll();
}

/** Libellé lisible d'un statut de compte */
function statutCompteLabel(string $statut): string
{
    return [
        'non_active' => 'À activer',
        'en_attente' => 'Première connexion à faire',
        'actif'      => 'Actif',
        'inactif'    => 'Désactivé',
    ][$statut] ?? $statut;
}

/** Classe Bootstrap du badge d'un statut de compte */
function statutCompteBadgeClass(string $statut): string
{
    return [
        'non_active' => 'bg-danger',
        'en_attente' => 'bg-warning text-dark',
        'actif'      => 'bg-success',
        'inactif'    => 'bg-secondary',
    ][$statut] ?? 'bg-secondary';
}

/**
 * Fragment SQL (sans paramètre) sélectionnant les comptes pouvant être désignés
 * « Responsable Service / Sous-directeur » : rôle 'responsable' OU fonction secondaire.
 * $prefix : préfixe d'alias de table, ex. "a." (avec le point).
 */
function sqlEstRespService(string $prefix = ''): string
{
    return "({$prefix}role = 'responsable' OR {$prefix}est_responsable_service = 1)";
}

/** L'utilisateur (ligne agent_dpip) peut-il être Responsable Service / Sous-directeur ? */
function estResponsableService(array $u): bool
{
    return (($u['role'] ?? '') === 'responsable') || !empty($u['est_responsable_service']);
}

/**
 * Fragment SQL sélectionnant les comptes pouvant recevoir des activités comme un
 * agent : rôle 'agent' OU fonction secondaire. $prefix : alias de table (ex. "a.").
 */
function sqlEstAgent(string $prefix = ''): string
{
    return "({$prefix}role = 'agent' OR {$prefix}est_agent = 1)";
}

/** L'utilisateur (ligne agent_dpip) peut-il recevoir des activités comme un agent ? */
function estAgentExecutant(array $u): bool
{
    return (($u['role'] ?? '') === 'agent') || !empty($u['est_agent']);
}

/**
 * Le compte a-t-il les droits d'administration ?
 *  - rôle « admin » ;
 *  - ou Responsable Service ayant la fonction supplémentaire « Administrateur » (est_admin).
 * Pour ce second cas, l'état est revérifié en base (compte actif, fonction toujours accordée) :
 * retirer la fonction prend effet immédiatement, sans attendre la prochaine connexion.
 */
function estAdministrateurActif(array $u): bool
{
    if (($u['role'] ?? '') === 'admin') {
        return true;
    }
    if (($u['role'] ?? '') !== 'responsable' || empty($u['est_admin'])) {
        return false;
    }
    static $cache = [];
    $id = (int)($u['ID_agent_dpip'] ?? 0);
    if (!isset($cache[$id])) {
        try {
            $s = Database::getConnection()->prepare(
                "SELECT 1 FROM agent_dpip WHERE ID_agent_dpip = ? AND role = 'responsable' AND est_admin = 1 AND statut_compte = 'actif'"
            );
            $s->execute([$id]);
            $cache[$id] = (bool)$s->fetchColumn();
        } catch (Throwable $e) {
            $cache[$id] = false;   // colonne absente (migration non appliquée) : pas de droits supplémentaires
        }
    }
    return $cache[$id];
}

/** $userId est-il le responsable hiérarchique direct de $executantId ? */
function estSuperviseurDe(PDO $pdo, int $userId, int $executantId): bool
{
    if ($userId <= 0 || $executantId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM agent_dpip WHERE ID_agent_dpip = ? AND ID_responsable = ?');
    $stmt->execute([$executantId, $userId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Qui peut modifier ou supprimer une activité / sous-activité / tâche ?
 *  - l'administrateur et le directeur : toutes les activités ;
 *  - un responsable (Responsable Service ou Sous-directeur) : celles qu'il a attribuées
 *    et celles qui sont confiées à l'un de ses agents (même attribuées par un autre).
 * $activite : ligne de la table activites (ID_responsable, ID_agent_dpip).
 */
function peutGererActivite(PDO $pdo, array $user, array $activite): bool
{
    $role = $user['role'] ?? '';
    if ($role === 'admin' || $role === 'directeur' || estAdministrateurActif($user)) {
        return true;
    }
    if ($role === 'responsable') {
        $moi = (int)($user['ID_agent_dpip'] ?? 0);
        return (int)($activite['ID_responsable'] ?? 0) === $moi
            || estSuperviseurDe($pdo, $moi, (int)($activite['ID_agent_dpip'] ?? 0));
    }
    return false;
}

/**
 * Destinataires possibles d'une recommandation du Directeur $dirId : ses Responsables Service
 * et Sous-directeurs actifs (comptes de rôle « responsable » dont il est le supérieur hiérarchique).
 */
function destinatairesRecommandation(PDO $pdo, int $dirId): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM agent_dpip
         WHERE role = 'responsable' AND ID_responsable = ? AND statut_compte = 'actif'
         ORDER BY FIELD(type_responsable, 'sous_directeur', 'service'), Nom, Prenom"
    );
    $stmt->execute([$dirId]);
    return $stmt->fetchAll();
}

/** Nombre de recommandations non lues reçues par $agentId. */
function nbRecommandationsNonLues(PDO $pdo, int $agentId): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM recommandation WHERE ID_destinataire = ? AND lu = 0');
        $stmt->execute([$agentId]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;   // table absente (migration non appliquée) : on ne bloque pas la page
    }
}

function importanceRecommandationLabel(string $importance): string
{
    return ['normale' => 'Normale', 'importante' => 'Importante', 'urgente' => 'Urgente'][$importance] ?? $importance;
}

function importanceRecommandationBadge(string $importance): string
{
    return ['normale' => 'bg-secondary', 'importante' => 'bg-warning text-dark', 'urgente' => 'bg-danger'][$importance] ?? 'bg-secondary';
}

/** Libellés des rôles */
function roleLabel(string $role): string
{
    return [
        'admin' => 'Administrateur',
        'directeur' => 'Directeur',
        'responsable' => 'Responsable Service',
        'agent' => 'Agent',
    ][$role] ?? $role;
}

/**
 * Libellé du rôle d'un compte (ligne agent_dpip) : distingue « Responsable Service »
 * et « Sous-directeur » selon type_responsable.
 */
function roleLabelCompte(array $u): string
{
    if (($u['role'] ?? '') === 'responsable') {
        return ($u['type_responsable'] ?? 'service') === 'sous_directeur' ? 'Sous-directeur' : 'Responsable Service';
    }
    return roleLabel($u['role'] ?? '');
}

/**
 * Progression (%) d'une FEUILLE (activité/tâche sans élément rattaché) d'après
 * son statut. L'agent ne saisit plus de pourcentage : il ne change que le statut.
 */
function progressionStatut(string $statut): int
{
    // 'en_retard' n'a pas de pourcentage fixe : voir calculerProgression(), qui
    // fige la progression d'une feuille passée en retard sur sa valeur d'avant
    // (0 % si elle n'avait pas commencé, 25/50 % si elle était déjà en cours),
    // pour distinguer une tâche en retard jamais commencée d'une tâche en retard
    // déjà entamée.
    return [
        'termine'    => 100,
        'en_cours'   => 50,
        'en_attente' => 25,
        'a_faire'    => 0,
        'annule'     => 0,
    ][$statut] ?? 0;
}

/**
 * Calcule (récursivement, depuis les éléments rattachés) la progression d'une
 * activité / sous-activité / tâche et la stocke dans `activites.Progression`.
 *  - feuille  : progression = progressionStatut(son statut) ; STATUT INCHANGÉ
 *               (c'est l'agent qui le fixe via le compte rendu). Exception :
 *               si le statut est « en_retard », la progression n'est PAS
 *               recalculée ici — elle a été figée par actualiserTachesEnRetard()
 *               au moment du passage en retard, pour distinguer une tâche en
 *               retard jamais commencée (0 %) d'une tâche en retard déjà
 *               entamée (25/50 %).
 *  - branche  : moyenne de la progression de ses enfants directs (enfants
 *               « annulé » exclus). Son STATUT devient DYNAMIQUE :
 *                 100 %          -> termine
 *                 0 %            -> a_faire
 *                 échéance passée-> en_retard
 *                 sinon          -> en_cours
 *               (une branche « annulée » manuellement le reste.)
 * Renvoie la progression calculée.
 */
function calculerProgression(PDO $pdo, int $activiteId): int
{
    $st = $pdo->prepare('SELECT Statut, Date_fin FROM activites WHERE ID__activites = ?');
    $st->execute([$activiteId]);
    $self = $st->fetch() ?: ['Statut' => '', 'Date_fin' => null];

    $en = $pdo->prepare('SELECT ID__activites, Statut FROM activites WHERE pere_ID = ?');
    $en->execute([$activiteId]);
    $enfants = $en->fetchAll();

    if (!$enfants) {
        // 'en_retard' est figé au moment où actualiserTachesEnRetard() bascule le
        // statut (voir cette fonction) : on ne le recalcule pas ici, sous peine
        // d'écraser la distinction entre une tâche en retard jamais commencée et
        // une tâche en retard déjà entamée.
        if ((string)$self['Statut'] === 'en_retard') {
            $stP = $pdo->prepare('SELECT Progression FROM activites WHERE ID__activites = ?');
            $stP->execute([$activiteId]);
            return (int)$stP->fetchColumn();
        }
        $prog = progressionStatut((string)$self['Statut']);
        $pdo->prepare('UPDATE activites SET Progression = ? WHERE ID__activites = ?')->execute([$prog, $activiteId]);
        return $prog;
    }

    $actifs = array_filter($enfants, fn($e) => $e['Statut'] !== 'annule');
    if (!$actifs) {
        $pdo->prepare('UPDATE activites SET Progression = 0 WHERE ID__activites = ?')->execute([$activiteId]);
        return 0;
    }
    $vals = [];
    foreach ($actifs as $e) {
        $vals[] = calculerProgression($pdo, (int)$e['ID__activites']);
    }
    $prog = (int)round(array_sum($vals) / count($vals));

    // Statut dynamique de la branche.
    if ((string)$self['Statut'] === 'annule') {
        $nouveauStatut = 'annule';
    } elseif ($prog >= 100) {
        $nouveauStatut = 'termine';
    } elseif ($prog === 0) {
        $nouveauStatut = 'a_faire';
    } elseif (!empty($self['Date_fin']) && strtotime((string)$self['Date_fin']) < strtotime(date('Y-m-d'))) {
        $nouveauStatut = 'en_retard';
    } else {
        $nouveauStatut = 'en_cours';
    }

    $pdo->prepare('UPDATE activites SET Progression = ?, Statut = ? WHERE ID__activites = ?')
        ->execute([$prog, $nouveauStatut, $activiteId]);
    return $prog;
}

/**
 * Recalcule la progression de l'activité indiquée PUIS de tous ses ancêtres
 * (chaîne des pere_ID). À appeler après tout changement de statut, de
 * rattachement, d'ajout ou de suppression d'élément.
 */
function majProgression(PDO $pdo, ?int $activiteId): void
{
    if (!$activiteId) {
        return;
    }
    calculerProgression($pdo, $activiteId);
    $p = $pdo->prepare('SELECT pere_ID FROM activites WHERE ID__activites = ?');
    $p->execute([$activiteId]);
    $pid = $p->fetchColumn();
    if ($pid) {
        majProgression($pdo, (int)$pid);
    }
}

/** Détermine si une tâche est en retard (date de fin dépassée et pas terminée/annulée) */
function estEnRetard(string $dateFin, string $statut): bool
{
    if (in_array($statut, ['termine', 'annule'], true)) {
        return false;
    }
    return strtotime($dateFin) < strtotime(date('Y-m-d'));
}

/** Nombre de jours restants avant l'échéance (négatif si dépassée) */
function joursRestants(string $dateFin): int
{
    $today = new DateTime(date('Y-m-d'));
    $fin = new DateTime($dateFin);
    return (int)$today->diff($fin)->format('%r%a');
}

/**
 * Recalcule automatiquement le statut "en_retard" pour les tâches dont l'échéance
 * est dépassée et qui ne sont ni terminées ni annulées. À appeler à chaque
 * chargement des listes de tâches pour garder les statuts à jour.
 */
function actualiserTachesEnRetard(PDO $pdo): void
{
    // On fige la progression sur sa valeur AVANT le passage en retard (0 % si
    // « à faire », jamais commencée ; 25 % si « en attente » ; 50 % si « en
    // cours », déjà entamée) — pour ne pas afficher 50 % à une tâche en retard
    // qui n'a jamais été commencée. Sans objet pour les branches (activité /
    // sous-activité avec enfants) : leur progression est de toute façon
    // recalculée juste après, à partir de leurs enfants.
    $n = $pdo->exec("UPDATE activites
                SET Progression = CASE Statut WHEN 'en_cours' THEN 50 WHEN 'en_attente' THEN 25 ELSE 0 END,
                    Statut = 'en_retard'
                WHERE Date_fin < CURDATE()
                AND Statut NOT IN ('termine', 'annule', 'en_retard')");
    // Un passage « en retard » peut changer la progression d'une feuille : on
    // recalcule chaque arbre (rare — au plus une fois par jour et par tâche).
    if ($n) {
        foreach ($pdo->query('SELECT ID__activites FROM activites WHERE pere_ID IS NULL')->fetchAll(PDO::FETCH_COLUMN) as $rootId) {
            calculerProgression($pdo, (int)$rootId);
        }
    }
}

/** Upload sécurisé d'un fichier vers un dossier donné. Retourne le nom de fichier stocké ou null. */
function handleFileUpload(array $file, string $destinationDir, string $prefix = ''): ?array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Erreur lors du téléversement du fichier (code {$file['error']}).");
    }
    if ($file['size'] > MAX_UPLOAD_SIZE) {
        throw new RuntimeException('Le fichier dépasse la taille maximale autorisée (10 Mo).');
    }
    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
        throw new RuntimeException('Type de fichier non autorisé (.' . $ext . ').');
    }
    if (!is_dir($destinationDir)) {
        mkdir($destinationDir, 0755, true);
    }
    $safeBaseName = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $storedName = ($prefix ? $prefix . '_' : '') . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '_' . $safeBaseName . '.' . $ext;
    $destination = rtrim($destinationDir, '/') . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Impossible d\'enregistrer le fichier sur le serveur.');
    }
    return ['stored_name' => $storedName, 'original_name' => $originalName];
}

/**
 * Enregistre une notification interne pour un utilisateur.
 * $activiteId : activité concernée (déduite du lien « ?id=… » si non fournie).
 */
function createNotification(PDO $pdo, int $agentId, string $titre, string $message, ?string $lien = null, ?int $activiteId = null): void
{
    if ($activiteId === null && $lien !== null && preg_match('~[?&]id=(\d+)~', $lien, $m)) {
        $activiteId = (int)$m[1];
    }
    $stmt = $pdo->prepare('INSERT INTO notifications (ID_agent_dpip, id_activites, Titre, Message, Lien) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$agentId, $activiteId ?: null, $titre, $message, $lien]);
}

/**
 * Reconnaît le navigateur et le système d'après l'en-tête User-Agent enregistré à la connexion.
 * Retourne : ['navigateur' => 'Chrome 152', 'nom' => 'Chrome', 'version' => '152', 'systeme' => 'Windows 10/11',
 *             'appareil' => 'Ordinateur'|'Mobile'|'Outil', 'icone' => classe Bootstrap Icons].
 * L'ordre des règles compte : Edge, Opera et Samsung Internet contiennent aussi « Chrome », et Chrome contient « Safari ».
 * Un User-Agent peut être falsifié : cette information est indicative (suivi de sécurité), pas une preuve.
 */
function navigateurDepuisUserAgent(?string $ua): array
{
    $ua = trim((string)$ua);
    $inconnu = ['navigateur' => 'Navigateur inconnu', 'nom' => 'Inconnu', 'version' => '', 'systeme' => '', 'appareil' => '', 'icone' => 'bi-question-circle'];
    if ($ua === '') {
        return $inconnu;
    }

    // Système d'exploitation
    $systeme = '';
    $appareil = 'Ordinateur';
    if (preg_match('/Windows NT (\d+\.\d+)/', $ua, $m)) {
        $systeme = ['10.0' => 'Windows 10/11', '6.3' => 'Windows 8.1', '6.2' => 'Windows 8', '6.1' => 'Windows 7'][$m[1]] ?? 'Windows';
    } elseif (preg_match('/Android ([\d.]+)/', $ua, $m)) {
        $systeme = 'Android ' . strtok($m[1], '.');
        $appareil = 'Mobile';
    } elseif (preg_match('/(iPhone|iPad|iPod)/', $ua)) {
        $systeme = str_contains($ua, 'iPad') ? 'iPadOS' : 'iOS';
        $appareil = 'Mobile';
    } elseif (str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')) {
        $systeme = 'macOS';
    } elseif (str_contains($ua, 'CrOS')) {
        $systeme = 'ChromeOS';
    } elseif (str_contains($ua, 'Linux') || str_contains($ua, 'X11')) {
        $systeme = 'Linux';
    }
    if ($appareil === 'Ordinateur' && preg_match('/Mobile|Tablet/', $ua)) {
        $appareil = 'Mobile';
    }

    // Navigateur (du plus spécifique au plus général)
    $regles = [
        ['/\bEdg(?:e|A|iOS)?\/(\d+)/',              'Edge',              'bi-browser-edge'],
        ['/\b(?:OPR|Opera)\/(\d+)/',                'Opera',             'bi-globe2'],
        ['/\bSamsungBrowser\/(\d+)/',               'Samsung Internet',  'bi-globe2'],
        ['/\bVivaldi\/(\d+)/',                      'Vivaldi',           'bi-globe2'],
        ['/\b(?:Firefox|FxiOS)\/(\d+)/',            'Firefox',           'bi-browser-firefox'],
        ['/\b(?:Chrome|CriOS|Chromium)\/(\d+)/',    'Chrome',            'bi-browser-chrome'],
        ['/\bVersion\/(\d+)[\d.]*.*\bSafari\//',    'Safari',            'bi-browser-safari'],
        ['/\bMSIE (\d+)/',                          'Internet Explorer', 'bi-globe2'],
        ['/\bTrident\/.*rv:(\d+)/',                 'Internet Explorer', 'bi-globe2'],
    ];
    foreach ($regles as [$motif, $nom, $icone]) {
        if (preg_match($motif, $ua, $m)) {
            return ['navigateur' => $nom . ' ' . $m[1], 'nom' => $nom, 'version' => $m[1], 'systeme' => $systeme, 'appareil' => $appareil, 'icone' => $icone];
        }
    }
    // Outils en ligne de commande, robots, scripts
    if (preg_match('/^(curl|Wget|python-requests|Go-http-client|PostmanRuntime|okhttp|Java)\/?([\d.]*)/i', $ua, $m)) {
        $v = $m[2] !== '' ? ' ' . strtok($m[2], '.') : '';
        return ['navigateur' => 'Outil (' . $m[1] . $v . ')', 'nom' => $m[1], 'version' => $m[2], 'systeme' => $systeme, 'appareil' => 'Outil', 'icone' => 'bi-terminal'];
    }
    if (preg_match('/bot|crawl|spider|slurp/i', $ua)) {
        return ['navigateur' => 'Robot', 'nom' => 'Robot', 'version' => '', 'systeme' => $systeme, 'appareil' => 'Outil', 'icone' => 'bi-robot'];
    }
    return $inconnu + ['systeme' => $systeme];
}
/**
 * Adresse IP du client, et son origine lisible.
 * Renvoie « Réseau local » pour une IP privée / loopback ; sinon null tant
 * qu'aucune source de géolocalisation (base MaxMind, API…) n'est branchée
 * au point d'extension ci-dessous.
 */
function paysDepuisIp(string $ip): ?string
{
    $ip = trim($ip);
    if ($ip === '') {
        return null;
    }
    $low = strtolower($ip);
    if ($ip === '127.0.0.1' || $ip === '::1'
        || str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.') || str_starts_with($ip, '169.254.')
        || preg_match('~^172\.(1[6-9]|2\d|3[01])\.~', $ip)
        || str_starts_with($low, 'fe80:') || str_starts_with($low, 'fc') || str_starts_with($low, 'fd')) {
        return 'Réseau local';
    }
    // Point d'extension : résolution géo-IP du pays à partir de $ip, si disponible.
    return null;
}

/** « Prénom Nom » d'un compte, ou « — » si introuvable / id nul */
function nomAgent(PDO $pdo, ?int $agentId): string
{
    if (!$agentId) {
        return '—';
    }
    $st = $pdo->prepare('SELECT Prenom, Nom FROM agent_dpip WHERE ID_agent_dpip = ?');
    $st->execute([$agentId]);
    $r = $st->fetch();
    return $r ? (trim(($r['Prenom'] ?? '') . ' ' . ($r['Nom'] ?? '')) ?: '—') : '—';
}

/** Id de l'activité racine (remonte pere_ID) — sert de « numéro de dossier ». */
function activiteRacine(PDO $pdo, int $activiteId): int
{
    $st = $pdo->prepare('SELECT pere_ID FROM activites WHERE ID__activites = ?');
    $vus = [];
    $cur = $activiteId;
    while ($cur > 0 && !isset($vus[$cur])) {
        $vus[$cur] = true;
        $st->execute([$cur]);
        $pere = $st->fetchColumn();
        if ($pere === false || $pere === null || (int)$pere <= 0) {
            return $cur;
        }
        $cur = (int)$pere;
    }
    return $activiteId;
}

/**
 * Prévient l'agent exécutant qu'une observation a été portée sur son activité
 * (champ activites.Observation, saisi à la création ou via « Modifier ») et trace
 * l'action dans le journal. À appeler uniquement quand le texte de l'observation
 * a réellement changé.
 */
function notifierObservationActivite(PDO $pdo, int $activiteId, int $agentId, ?int $auteurId, string $auteur, string $libelleActivite): void
{
    logJournal($pdo, $activiteId, $auteurId, 'observation', 'Observation sur l\'activité par ' . $auteur);
    createNotification(
        $pdo,
        $agentId,
        'Nouvelle observation',
        $auteur . ' a laissé une observation sur « ' . $libelleActivite . ' ».',
        '/tache_detail.php?id=' . $activiteId . '#observations'
    );
}

/** Nombre de notifications non lues pour un utilisateur */
function countUnreadNotifications(PDO $pdo, int $agentId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE ID_agent_dpip = ? AND Lu = 0');
    $stmt->execute([$agentId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Ajoute une entrée dans le journal d'activité.
 * id_enregistrement = id de l'activité racine : toute l'histoire d'un dossier
 * (activité + sous-activités + tâches) se retrouve avec ce seul numéro,
 * indépendamment des réaffectations.
 */
function logJournal(PDO $pdo, int $activiteId, ?int $agentId, string $action, ?string $detail = null): void
{
    $racine = activiteRacine($pdo, $activiteId);
    $stmt = $pdo->prepare('INSERT INTO journal_activites (ID_activites, ID_agent_dpip, id_enregistrement, Action, Detail) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$activiteId, $agentId, $racine, $action, $detail]);
    // Report aussi dans le journal d'audit global de l'application.
    logModif($pdo, 'activites', $activiteId, $action, $detail, $agentId);
}

/**
 * Journal d'audit GLOBAL : trace toute action d'un utilisateur sur n'importe
 * quelle fonctionnalité (comptes, départements, référentiels, activités, comptes
 * rendus, connexions…).
 *  $table   : nom de la table / fonctionnalité touchée (ex. 'agent_dpip').
 *  $idEnr   : identifiant de la ligne concernée (ou null).
 *  $action  : 'creation' | 'modification' | 'suppression' | 'activation' | ...
 *  $details : phrase lisible décrivant ce qui a changé.
 *  $agentId : acteur ; par défaut l'utilisateur réel connecté (jamais le
 *             directeur quand une secrétaire agit pour lui).
 * N'échoue jamais : une erreur d'audit ne doit pas casser l'action métier.
 */
function logModif(PDO $pdo, string $table, ?int $idEnr, string $action, ?string $details = null, ?int $agentId = null): void
{
    // Les connexions / déconnexions ont leur propre journal (table `connexion`).
    if (in_array($action, ['connexion', 'deconnexion'], true) || $table === 'connexion') {
        return;
    }
    $libelle = null;
    if ($agentId === null) {
        $u = function_exists('realUser') ? realUser() : (function_exists('currentUser') ? currentUser() : null);
        if ($u) {
            $agentId = (int)($u['ID_agent_dpip'] ?? 0) ?: null;
            $libelle = trim(($u['Prenom'] ?? '') . ' ' . ($u['Nom'] ?? '')) ?: null;
        }
    }
    if ($agentId !== null && $libelle === null) {
        try {
            $st = $pdo->prepare('SELECT Prenom, Nom FROM agent_dpip WHERE ID_agent_dpip = ?');
            $st->execute([$agentId]);
            if ($r = $st->fetch()) {
                $libelle = trim(($r['Prenom'] ?? '') . ' ' . ($r['Nom'] ?? '')) ?: null;
            }
        } catch (Throwable $e) {
        }
    }
    try {
        $pdo->prepare(
            'INSERT INTO historique_modification (id_agent, agent_libelle, table_concernee, id_enregistrement, action, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$agentId, $libelle, $table, $idEnr ?: null, $action, $details !== null ? mb_substr($details, 0, 255) : null]);
    } catch (Throwable $e) {
        // l'audit ne bloque jamais l'action métier
    }
}

/** Libellé lisible d'une action d'audit */
function actionModifLabel(string $action): string
{
    return [
        'creation'          => 'Création',
        'modification'      => 'Modification',
        'suppression'       => 'Suppression',
        'activation'        => 'Activation',
        'desactivation'     => 'Désactivation',
        'reinitialisation'  => 'Réinitialisation du mot de passe',
        'changement_mdp'    => 'Changement de mot de passe',
        'connexion'         => 'Connexion',
        'deconnexion'       => 'Déconnexion',
        'delegation'        => 'Délégation',
        'reafectation'      => 'Réaffectation',
        'reattribution'     => 'Réattribution',
        'compte_rendu'      => 'Compte rendu',
        'livrable'          => 'Livrable',
        'besoin'            => 'Besoin exprimé',
        'observation'       => 'Observation',
        'retour'            => 'Retour du responsable',
        'export'            => 'Export Excel',
    ][$action] ?? ucfirst(str_replace('_', ' ', $action));
}

/** Libellé lisible d'une fonctionnalité (table) pour le journal d'audit */
function tableConcerneeLabel(string $table): string
{
    return [
        'agent_dpip'         => 'Compte utilisateur',
        'departement'        => 'Département',
        'cause_reafectation' => 'Cause de réaffectation',
        'activites'          => 'Activité / tâche',
        'comptes_rendus'     => 'Compte rendu',
        'livrables'          => 'Livrable',
        'besions'            => 'Besoin',
        'observation'        => 'Observation',
        'recommandation'     => 'Recommandation',
        'connexion'          => 'Connexion',
    ][$table] ?? $table;
}

/** Génère un mot de passe temporaire lisible */
function genererMotDePasseTemporaire(int $longueur = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $mdp = '';
    for ($i = 0; $i < $longueur; $i++) {
        $mdp .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $mdp;
}

/** Valeur d'un champ POST nettoyée (trim), avec valeur par défaut */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/** Valeur d'un champ GET nettoyée (trim), avec valeur par défaut */
function get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/** Génère / vérifie un jeton CSRF simple */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): bool
{
    return isset($_POST['csrf_token']) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
