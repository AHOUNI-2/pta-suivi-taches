<?php
/**
 * Envoi d'emails de notification.
 *
 * Aucune dépendance externe : utilise soit la fonction mail() native de PHP
 * (MAIL_METHOD = 'mail', nécessite un sendmail/postfix configuré sur le
 * serveur), soit un petit client SMTP maison (MAIL_METHOD = 'smtp') pour se
 * connecter à un relais externe (Gmail, Office365, etc.) sans dépendance
 * Composer. Voir les constantes SMTP_* dans config/config.php.
 *
 * Toute erreur d'envoi est interceptée pour ne jamais bloquer l'application :
 * la notification interne (table `notifications`) reste la source de vérité,
 * l'email n'est qu'un canal complémentaire.
 */

function sendAppMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    if (!MAIL_ENABLED || empty($toEmail)) {
        return false;
    }
    try {
        if (MAIL_METHOD === 'smtp') {
            return SimpleSmtpMailer::send($toEmail, $toName, $subject, $htmlBody);
        }
        return sendViaPhpMail($toEmail, $toName, $subject, $htmlBody);
    } catch (Throwable $e) {
        error_log('[sendAppMail] Échec envoi email : ' . $e->getMessage());
        return false;
    }
}

function sendViaPhpMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
    $headers[] = 'Reply-To: ' . MAIL_FROM;
    $headers[] = 'X-Mailer: PHP/' . phpversion();

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    return @mail($toEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers));
}

/**
 * Client SMTP minimal (AUTH LOGIN, STARTTLS/SSL) sans dépendance externe.
 * Suffisant pour l'envoi de notifications simples via un relais authentifié.
 */
class SimpleSmtpMailer
{
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        $host = (SMTP_SECURE === 'ssl' ? 'ssl://' : '') . SMTP_HOST;
        $sock = @stream_socket_client($host . ':' . SMTP_PORT, $errno, $errstr, 15);
        if (!$sock) {
            throw new RuntimeException("Connexion SMTP impossible : $errstr ($errno)");
        }

        self::expect($sock, '220');
        self::command($sock, 'EHLO ' . gethostname(), '250');

        if (SMTP_SECURE === 'tls') {
            self::command($sock, 'STARTTLS', '220');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Impossible de négocier TLS avec le serveur SMTP.');
            }
            self::command($sock, 'EHLO ' . gethostname(), '250');
        }

        self::command($sock, 'AUTH LOGIN', '334');
        self::command($sock, base64_encode(SMTP_USER), '334');
        self::command($sock, base64_encode(SMTP_PASS), '235');

        self::command($sock, 'MAIL FROM:<' . MAIL_FROM . '>', '250');
        self::command($sock, 'RCPT TO:<' . $toEmail . '>', '250');
        self::command($sock, 'DATA', '354');

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . '>';
        $headers[] = 'To: ' . ($toName ? $toName . ' <' . $toEmail . '>' : $toEmail);
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n.", "\n..", $htmlBody) . "\r\n.";
        fwrite($sock, $message . "\r\n");
        self::expect($sock, '250');

        self::command($sock, 'QUIT', '221');
        fclose($sock);
        return true;
    }

    private static function command($sock, string $cmd, string $expectedCode): string
    {
        fwrite($sock, $cmd . "\r\n");
        return self::expect($sock, $expectedCode);
    }

    private static function expect($sock, string $expectedCode): string
    {
        $response = '';
        while ($line = fgets($sock, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if (strpos($response, $expectedCode) !== 0) {
            throw new RuntimeException("Réponse SMTP inattendue (attendu $expectedCode) : $response");
        }
        return $response;
    }
}

/** Raccourcis pour les notifications métier courantes */

function mailNouvelleTache(array $agent, array $activite): void
{
    $type = isset($activite['Types']) ? mb_strtolower(typeActiviteLabel($activite['Types'])) : 'tâche';
    $subject = 'Nouvelle ' . $type . ' attribuée : ' . $activite['Libelle'];
    $body = "<p>Bonjour {$agent['Prenom']},</p>"
        . "<p>Une nouvelle " . h($type) . " vous a été attribuée :</p>"
        . "<p><strong>" . h($activite['Libelle']) . "</strong><br>"
        . "Échéance : " . formatDate($activite['Date_fin']) . "</p>"
        . "<p>Connectez-vous à " . APP_NAME . " pour la consulter et rendre compte de votre avancement.</p>";
    sendAppMail($agent['Mail_Pro'] ?: $agent['Mail'], $agent['Prenom'] . ' ' . $agent['Nom'], $subject, $body);
}

function mailActiviteModifiee(array $agent, array $activite): void
{
    $type = isset($activite['Types']) ? mb_strtolower(typeActiviteLabel($activite['Types'])) : 'tâche';
    $subject = 'Mise à jour de votre ' . $type . ' : ' . $activite['Libelle'];
    $body = "<p>Bonjour {$agent['Prenom']},</p>"
        . "<p>Votre responsable a modifié la " . h($type) . " suivante :</p>"
        . "<p><strong>" . h($activite['Libelle']) . "</strong><br>"
        . "Échéance : " . formatDate($activite['Date_fin']) . "</p>"
        . "<p>Connectez-vous à " . APP_NAME . " pour prendre connaissance des changements.</p>";
    sendAppMail($agent['Mail_Pro'] ?: $agent['Mail'], $agent['Prenom'] . ' ' . $agent['Nom'], $subject, $body);
}

function mailNouveauCompteRendu(array $responsable, array $activite, array $agent): void
{
    $subject = 'Nouveau compte rendu : ' . $activite['Libelle'];
    $body = "<p>Bonjour {$responsable['Prenom']},</p>"
        . "<p>" . h($agent['Prenom'] . ' ' . $agent['Nom']) . " vient de soumettre un compte rendu pour la tâche :</p>"
        . "<p><strong>" . h($activite['Libelle']) . "</strong></p>"
        . "<p>Connectez-vous à " . APP_NAME . " pour le consulter.</p>";
    sendAppMail($responsable['Mail_Pro'] ?: $responsable['Mail'], $responsable['Prenom'] . ' ' . $responsable['Nom'], $subject, $body);
}

function mailEcheanceProche(array $agent, array $activite, int $joursRestants): void
{
    $subject = 'Échéance proche : ' . $activite['Libelle'];
    $body = "<p>Bonjour {$agent['Prenom']},</p>"
        . "<p>La tâche suivante arrive à échéance dans {$joursRestants} jour(s) :</p>"
        . "<p><strong>" . h($activite['Libelle']) . "</strong><br>"
        . "Échéance : " . formatDate($activite['Date_fin']) . "</p>";
    sendAppMail($agent['Mail_Pro'] ?: $agent['Mail'], $agent['Prenom'] . ' ' . $agent['Nom'], $subject, $body);
}
