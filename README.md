# PTA — Module de gestion et de suivi des tâches

Application web (PHP / MySQL) permettant :

- à un **administrateur** de gérer les départements et les comptes ;
- à un **directeur** de voir les activités de **toutes les directions**, de déléguer des activités aux responsables service / sous-directeurs comme aux agents, et de désigner une **secrétaire** qui agit en son nom pendant ses absences ;
- à un **responsable service / sous-directeur** d'attribuer des tâches à ses agents et de suivre leur avancement (comptes rendus, livrables, besoins), et d'exécuter les activités que la direction lui confie ;
- à un **agent** de consulter les tâches qui lui sont confiées, de rendre compte de son avancement, de déposer des livrables et d'exprimer des besoins.

Chaque utilisateur dispose de son propre compte pour suivre l'évolution des tâches qui le concernent.

## 1. Prérequis

- PHP 8.0 ou supérieur, avec les extensions `pdo_mysql`, `mbstring`, `session`.
- MySQL 5.7+ ou MariaDB 10.4+.
- Un serveur web (Apache avec `mod_rewrite`/`mod_php`, ou Nginx + PHP-FPM). Les fichiers `.htaccess` fournis supposent Apache.

## 2. Installation

1. **Copier les fichiers** de ce dossier (`pta_suivi_taches/`) à la racine de votre hébergement, ou dans un sous-dossier.
2. **Créer la base de données** et importer le fichier `database/pta_bd_suivi.sql` (par exemple via phpMyAdmin, ou en ligne de commande) :
   ```
   mysql -u root -p -e "CREATE DATABASE pta_bd_suivi CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
   mysql -u root -p pta_bd_suivi < database/pta_bd_suivi.sql
   ```
   Ce script crée toutes les tables **et** un compte administrateur par défaut.
3. **Créer sa configuration locale** : copier le modèle puis l'adapter.
   ```
   cp config/config.example.php config/config.php
   ```
   `config/config.php` n'est **pas** versionné (chaque poste / hébergement a le sien). Renseignez-y la connexion à la base :
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'pta_bd_suivi');
   define('DB_USER', 'votre_utilisateur');
   define('DB_PASS', 'votre_mot_de_passe');
   ```
   Si l'application n'est pas servie à la racine du domaine, ajustez également `BASE_URL` (ex : `/pta`).
4. **Droits d'écriture** : le dossier `uploads/` (et ses sous-dossiers `livrables/`, `comptes_rendus/`, `avatars/`) doit être accessible en écriture par le serveur web (`chmod -R 775 uploads` sous Linux, propriétaire = utilisateur du serveur web).
5. Ouvrir l'application dans un navigateur et se connecter avec le compte administrateur par défaut (matricule ou email + mot de passe ; l'espace affiché dépend automatiquement du rôle du compte) :
   - **Matricule** : `ADMIN001`
   - **Mot de passe** : `Admin@2026`

   ⚠️ **Changez ce mot de passe immédiatement** depuis "Mon profil" après la première connexion.

## 2 bis. Mise à jour d'une base déjà installée

Si votre base `pta_bd_suivi` a été créée avant l'ajout du champ **Profil / domaine de compétence**, de la **première connexion** (l'utilisateur définit lui-même son mot de passe) et du renommage `Description` → **`Observation`** sur les activités, appliquez les migrations une seule fois, dans l'ordre :

```
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_profil.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_activation.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_observation_activite.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_code_departement.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_activation_admin.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_directeur.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_resp_service.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_fonction_agent.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_type_responsable.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_hierarchie_activites.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_profils_texte_libre.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_reaffectation.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_priorite_libre.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_connexion_traces.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_historique_modification.sql
mysql -u root -p --default-character-set=utf8mb4 pta_bd_suivi < database/migration_2026-09_reaffectation_libelle.sql
```

Les nouvelles installations (via `database/pta_bd_suivi.sql`) les incluent déjà.

## 3. Prise en main

1. Connectez-vous en tant qu'administrateur.
2. Vérifiez/complétez les **départements** (menu *Départements*) et, si besoin, les **causes de réaffectation** (menu *Causes de réaffectation* : agent empêché, malade, en congés, permissionnaire, affecté ailleurs…). Le **profil / domaine de compétence** d'une personne est un texte libre : l'administrateur peut le saisir sur la fiche utilisateur, et l'intéressé(e) peut le compléter depuis *Mon profil*.
3. Créez les comptes **Responsable Service**, **Sous-directeur** et **Agent** (menu *Utilisateurs → Nouvel utilisateur*, liste déroulante *Rôle principal*). « Responsable Service » et « Sous-directeur » ont les mêmes droits (attribution et suivi d'activités) ; seul le libellé change. Une personne peut aussi garder un rôle principal (ex. *Administrateur*) et cocher une **fonction supplémentaire** :
   - **Responsable Service / Sous-directeur** → sélectionnable comme responsable d'un agent et attributaire d'activités ; son nom apparaît dans la colonne *Responsable*.
   - **Agent (peut recevoir des activités)** → un Responsable Service / Sous-directeur doit lui être rattaché ; elle peut alors recevoir des activités et en rendre compte via le menu *Activités qui me sont confiées*.
4. Créez les comptes **Agent**, en rattachant chacun à son responsable hiérarchique.
5. Cycle de vie d'un compte (agent ou responsable) :
   - **À activer** — état à la création. L'administrateur clique le bouton **✔ Activer** dans la liste des utilisateurs.
   - **Première connexion à faire** — le compte est activé mais sans mot de passe. L'intéressé se rend sur *Première connexion — définir mon mot de passe* (page de connexion), saisit son **matricule** et **choisit lui-même son mot de passe**.
   - **Actif** — le compte peut se connecter normalement.
   - Le bouton **clé** réinitialise le mot de passe (le compte repasse « Première connexion à faire ») ; le bouton **power** désactive / réactive un compte actif.
6. Chaque **responsable**, une fois connecté, saisit ses **activités, sous-activités et tâches** et les attribue à ses agents (menu *Nouvelle activité* / *Activités & tâches*), puis suit leur avancement. Au choix du type, le formulaire demande le **rattachement** : une *sous-activité* est liée à une *activité*, une *tâche* est liée à une *sous-activité* ou directement à une *activité principale*. Supprimer une activité supprime aussi les éléments qui lui sont rattachés. Il retrouve dans *Activités qui me sont confiées* celles que la direction lui a déléguées. L'**administrateur** dispose du même module (menu *Activités & tâches*).
   - **Réaffectation** : si l'agent en charge est empêché, malade, permissionnaire, en congés ou affecté sur une autre activité, la personne habilitée (responsable, administrateur, direction) change simplement l'agent dans le formulaire de l'activité. Le formulaire impose alors de choisir une **cause de réaffectation** (+ une précision facultative). Chaque réaffectation est notifiée à l'ancien et au nouvel agent et conservée dans l'onglet *Réaffectations* de la fiche.
7. Le **directeur** (menu *Direction*) voit toutes les activités de toutes les directions, délègue via *Déléguer une activité* (exécutant = un responsable service / sous-directeur **ou** un agent) et suit les comptes rendus. Depuis *Mon profil*, il désigne une **secrétaire** : une fois connectée, celle-ci voit un bouton *Agir pour le Directeur* qui lui ouvre l'espace Direction en son nom (ses actions restent tracées à son nom).
8. Chaque **agent**, une fois connecté, consulte ses tâches, envoie des comptes rendus d'avancement, dépose des livrables et exprime ses besoins directement depuis la fiche de la tâche.
9. L'**administrateur** dispose de deux journaux (menu *Administration*) : le **Journal des connexions** (date/heure de connexion et de déconnexion, adresse IP et origine de chaque compte) et l'**Historique des modifications** (`historique_modification`) qui trace *toutes* les actions des utilisateurs sur *toutes* les fonctionnalités — création / modification / suppression de comptes, départements, référentiels, activités, comptes rendus, connexions… — avec l'auteur, l'horodatage et un détail lisible. Filtres par auteur, fonctionnalité, action et dates. L'origine affiche « Réseau local » pour une adresse privée ; la résolution du pays nécessite une source de géolocalisation IP à brancher dans `paysDepuisIp()`. Chaque entrée d'historique d'une activité porte un **numéro de dossier** (`id_enregistrement` = activité racine) qui permet de retracer toute l'histoire d'une activité et de ses éléments rattachés, quelles que soient les réaffectations (onglet *Historique* → « Historique complet du dossier »).

## 4. Notifications par email (optionnel)

Par défaut, les notifications par email sont **désactivées** (`MAIL_ENABLED = false` dans `config/config.php`) : seules les notifications internes (cloche en haut de page) sont actives, ce qui permet d'utiliser l'application sans configuration serveur mail particulière.

Pour activer l'envoi d'emails :

```php
define('MAIL_ENABLED', true);
define('MAIL_METHOD', 'mail'); // ou 'smtp'
```

- `MAIL_METHOD = 'mail'` utilise la fonction native `mail()` de PHP (nécessite un sendmail/postfix configuré sur le serveur — c'est généralement le cas sur un hébergement mutualisé classique).
- `MAIL_METHOD = 'smtp'` utilise un petit client SMTP intégré (aucune dépendance externe requise) pour vous connecter à un relais authentifié (Gmail, Office 365, un relais interne...). Renseignez alors les constantes `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS`.

Un email est envoyé automatiquement :
- à l'agent, lorsqu'une nouvelle tâche lui est attribuée ;
- au responsable, lorsqu'un agent soumet un compte rendu ;
- à l'agent, lorsqu'une échéance approche (voir tâche planifiée ci-dessous).

## 5. Tâche planifiée (rappels d'échéance)

Le script `cron/check_echeances.php` met à jour automatiquement le statut des tâches en retard et envoie un rappel (notification + email) aux agents dont une échéance approche (3 jours et 1 jour avant la date de fin). Il doit être exécuté quotidiennement via une tâche planifiée du serveur (CLI, jamais accessible depuis le navigateur) :

```
0 7 * * * /usr/bin/php /chemin/vers/pta_suivi_taches/cron/check_echeances.php >> /chemin/vers/logs/pta_cron.log 2>&1
```

Sans tâche planifiée, les statuts « en retard » sont tout de même recalculés automatiquement à chaque ouverture des tableaux de bord — seuls les emails de rappel J-3/J-1 nécessitent la tâche planifiée.

## 6. Structure du projet

```
config/       Connexion base de données et paramètres généraux
includes/     Fonctions communes, authentification, gabarits (header/sidebar/footer), envoi d'emails
auth/         Connexion / déconnexion
admin/        Espace administrateur
responsable/  Espace responsable
agent/        Espace agent
tache_detail.php   Fiche détaillée d'une tâche (comptes rendus, livrables, besoins, observations, historique)
profil.php, notifications.php   Pages communes à tous les rôles
database/     Script SQL d'installation
cron/         Script de tâche planifiée
uploads/      Fichiers déposés (livrables, pièces jointes, photos de profil)
assets/       CSS, JS et bibliothèques (Bootstrap, Bootstrap Icons, Chart.js — livrées en local, aucune dépendance internet)
```

## 7. Sécurité

- Mots de passe stockés sous forme de hachage (`password_hash`, bcrypt).
- Protection CSRF sur tous les formulaires de modification.
- Contrôle d'accès strict par rôle : un agent ne voit que ses propres tâches, un responsable ne voit que celles qu'il a attribuées.
- Le dossier `uploads/` interdit l'exécution de scripts (voir `uploads/.htaccess`).
- Les dossiers `config/`, `includes/`, `database/` et `cron/` sont protégés contre l'accès direct par le navigateur.
- Pensez à désactiver l'affichage des erreurs PHP en production (`config/config.php` → `display_errors`).
