<?php
$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);

// Includi PHPMailer
require_once __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/vendor/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Includi TCPDF se/quando ti serve
// require_once __DIR__ . '/vendor/tcpdf/tcpdf.php';

// Includi PhpSpreadsheet per Excel
require_once __DIR__ . '/vendor/autoload.php';

/**
 * Funzione centralizzata per inviare email
 */
function inviaEmailSMTP($to, $subject, $htmlBody) {
    $mail = new PHPMailer(true);

    try {
        // Usa configurazione centralizzata da config.php
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        $result = $mail->send();
        
        // Log solo se configurazione debug attiva
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("EMAIL SUCCESS: {$to} - " . substr($subject, 0, 50));
        }
        return $result;
        
    } catch (Exception $e) {
        // Log dettagliato errore
        error_log("EMAIL ERROR: Errore invio a {$to} - " . $e->getMessage());
        error_log("EMAIL ERROR: SMTP ErrorInfo - " . $mail->ErrorInfo);
        error_log("EMAIL ERROR: SMTP Config - Host:" . SMTP_HOST . ", Port:" . SMTP_PORT . ", User:" . SMTP_USER);
        return false;
    }
}

/**
 * Funzione per inviare email con allegato
 */
function inviaEmailSMTPWithAttachment($to, $subject, $htmlBody, $attachmentPath = null) {
    $mail = new PHPMailer(true);

    try {
        // Usa configurazione centralizzata da config.php
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;

        $mail->CharSet = 'UTF-8';
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        // Aggiungi allegato se specificato
        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        $result = $mail->send();
        
        // Log solo se configurazione debug attiva
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            error_log("EMAIL SUCCESS (attachment): {$to} - " . substr($subject, 0, 50));
        }
        return $result;
        
    } catch (Exception $e) {
        // Log dettagliato errore
        error_log("EMAIL ERROR: Errore invio con allegato a {$to} - " . $e->getMessage());
        error_log("EMAIL ERROR: SMTP ErrorInfo - " . $mail->ErrorInfo);
        return false;
    }
}
?>