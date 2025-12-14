<?php
/**
 * Cron Daily Report - Sistema Notifiche Admin Margynomic
 * File: modules/margynomic/cron_daily_report.php
 * 
 * Script cron giornaliero per:
 * - Invio report giornaliero completo
 * - Pulizia cache notifiche vecchie
 * - Logging operazioni
 * - Gestione errori con fallback email
 * 
 * Esecuzione consigliata: 00:00 ogni giorno
 * Crontab: 0 0 * * * /usr/bin/php /path/to/cron_daily_report.php
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Configurazione sicurezza
set_time_limit(300); // 5 minuti max
ini_set('memory_limit', '256M');

// Protezione esecuzione (temporaneamente disabilitata per test)
// if (php_sapi_name() !== 'cli' && !isset($_GET['manual_key'])) {
//     http_response_code(403);
//     die('Script eseguibile solo via CLI o con chiave manuale');
// }

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/admin_notifier.php';

/**
 * Log Daily Report - Utilizza CentralLogger
 */
function logDailyReport($message, $level = 'INFO', $context = []) {
    // Aggiungi informazioni memoria al context
    $context['memory_mb'] = round(memory_get_usage() / 1024 / 1024, 2);
    $context['peak_memory_mb'] = round(memory_get_peak_usage() / 1024 / 1024, 2);
    
    CentralLogger::log('cron_daily_report', $level, $message, $context);
    
    // Output CLI per debug
    if (php_sapi_name() === 'cli') {
        $timestamp = date('H:i:s');
        echo "[{$timestamp}] [DAILY_REPORT] [{$level}] {$message}" . PHP_EOL;
    }
}

/**
 * Invia email di fallback in caso di errori critici
 */
function sendFallbackEmail($error, $context = []) {
    try {
        $subject = "🚨 CRITICAL: Fallimento Cron Daily Report";
        
        $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); color: white; padding: 20px; text-align: center;'>
                <h1>🚨 CRITICAL ERROR</h1>
                <p>Cron Daily Report Failure</p>
            </div>
            
            <div style='padding: 20px; background: #f9fafb;'>
                <h3>📋 Dettagli Errore:</h3>
                <ul>
                    <li><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</li>
                    <li><strong>Script:</strong> cron_daily_report.php</li>
                    <li><strong>Errore:</strong> {$error}</li>
                </ul>
                
                " . (!empty($context) ? "<p><strong>📊 Context:</strong><br><pre>" . json_encode($context, JSON_PRETTY_PRINT) . "</pre></p>" : "") . "
            </div>
            
            <div style='padding: 20px; background: #fef3c7; border-left: 4px solid #f59e0b;'>
                <h4>⚡ Azioni Necessarie:</h4>
                <ul>
                    <li>Verificare AdminNotifier class</li>
                    <li>Controllare configurazione SMTP</li>
                    <li>Verificare log sistema per dettagli</li>
                    <li>Eseguire test manuale daily report</li>
                </ul>
            </div>
            
            <div style='text-align: center; padding: 20px; color: #6b7280;'>
                <small>Margynomic Admin Notifications System - Fallback Email</small>
            </div>
        </div>";
        
        return inviaEmailSMTP(AdminNotifier::ADMIN_EMAIL, $subject, $body);
        
    } catch (Exception $e) {
        error_log("CRITICAL: Fallback email failed: " . $e->getMessage());
        return false;
    }
}

// === ESECUZIONE PRINCIPALE ===
$startTime = microtime(true);
$stats = [
    'daily_report_sent' => false,
    'cache_cleaned' => 0,
    'errors' => 0,
    'execution_time' => 0
];

try {
    // Verifica prerequisiti
    
    // Verifica classe AdminNotifier
    if (!class_exists('AdminNotifier')) {
        throw new Exception("Classe AdminNotifier non trovata");
    }
    
    // Verifica configurazione SMTP
    if (!defined('SMTP_HOST') || !defined('SMTP_USER')) {
        throw new Exception("Configurazione SMTP mancante");
    }
    
    // Invio report giornaliero
    
    $reportResult = AdminNotifier::sendDailyReport();
    
    if ($reportResult) {
        $stats['daily_report_sent'] = true;
    } else {
        $stats['errors']++;
    }
    
    // Pulizia cache e log
    $cleanedFiles = AdminNotifier::cleanupNotificationCache();
    $stats['cache_cleaned'] = $cleanedFiles;
    
    try {
        $cleanedLogs = CentralLogger::cleanup();
        $stats['logs_cleaned'] = $cleanedLogs;
    } catch (Exception $e) {
        $stats['logs_cleaned'] = 0;
    }
    
    // LOG UNICO CONSOLIDATO
    $endTime = microtime(true);
    $stats['execution_time'] = round($endTime - $startTime, 2);
    
    logDailyReport(
        sprintf('Daily report completato: %s | Cache: %d | Logs: %d | %.2fs',
            $stats['daily_report_sent'] ? 'Sent' : 'Skipped',
            $stats['cache_cleaned'],
            $stats['logs_cleaned'] ?? 0,
            $stats['execution_time']
        ),
        $stats['errors'] > 0 ? 'WARNING' : 'SUCCESS',
        $stats
    );
    
    exit($stats['errors'] > 0 ? 1 : 0);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $stats['errors']++;
    
    logDailyReport("ERRORE CRITICO: {$errorMessage}", 'CRITICAL', [
        'error' => $errorMessage,
        'stack_trace' => $e->getTraceAsString(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    // Tentativo invio email fallback
    logDailyReport("Tentativo invio email fallback...");
    
    $fallbackResult = sendFallbackEmail($errorMessage, [
        'stats' => $stats,
        'stack_trace' => $e->getTraceAsString()
    ]);
    
    if ($fallbackResult) {
        logDailyReport("✅ Email fallback inviata", 'INFO');
    } else {
        logDailyReport("❌ Fallimento email fallback", 'ERROR');
    }
    
    exit(2);
}

/**
 * Funzione per test manuale
 */
function testDailyReportCron() {
    if (php_sapi_name() === 'cli' && isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1] === '--test') {
        logDailyReport("=== MODALITÀ TEST DAILY REPORT ===");
        
        try {
            // Test AdminNotifier
            $testResults = AdminNotifier::testNotificationSystem();
            
            logDailyReport("Test completato:");
            logDailyReport("- Email failure test: " . ($testResults['email_failure'] ? 'OK' : 'FAIL'));
            logDailyReport("- Daily report test: " . ($testResults['daily_report'] ? 'OK' : 'FAIL'));
            logDailyReport("- Log analysis: " . (is_array($testResults['log_analysis']) ? 'OK' : 'FAIL'));
            
            if (is_array($testResults['log_analysis'])) {
                $metrics = $testResults['log_analysis'];
                logDailyReport("Metriche trovate:");
                logDailyReport("- Operazioni totali: {$metrics['total_operations']}");
                logDailyReport("- Utenti processati: {$metrics['unique_users_count']}");
                logDailyReport("- Tasso successo: {$metrics['success_rate']}%");
            }
            
        } catch (Exception $e) {
            logDailyReport("Test fallito: " . $e->getMessage(), 'ERROR');
        }
        
        exit(0);
    }
}

// Esegui test se richiesto
testDailyReportCron();

// === INFO E HELP ===
if (isset($_GET['info'])) {
    echo "<h3>📋 Informazioni Cron Daily Report</h3>";
    echo "<div style='background: #f0f8ff; padding: 20px; border-left: 5px solid #2196f3; margin: 15px 0;'>";
    echo "<h4>🔧 Cron Daily Report - Guida</h4>";
    echo "<p><strong>Scopo:</strong> Invia report giornalieri automatici e gestisce pulizia sistema notifiche.</p>";
    
    echo "<h5>📊 Funzionalità:</h5>";
    echo "<ul>";
    echo "<li><strong>Report Giornaliero:</strong> Analizza log 24h e invia statistiche complete</li>";
    echo "<li><strong>Pulizia Cache:</strong> Rimuove file cache notifiche vecchi (>7 giorni)</li>";
    echo "<li><strong>Pulizia Log:</strong> Gestisce rotazione log centralizzati</li>";
    echo "<li><strong>Fallback Email:</strong> Notifica errori critici del cron stesso</li>";
    echo "<li><strong>Sistema Cooldown:</strong> Evita spam di notifiche duplicate</li>";
    echo "</ul>";
    
    echo "<h5>⚙️ Parametri URL:</h5>";
    echo "<ul>";
    echo "<li><code>?info</code> - Mostra questa pagina informativa</li>";
    echo "<li><code>--test</code> - Esegue test diagnostici (solo CLI)</li>";
    echo "<li><code>?manual_key=XXX</code> - Esecuzione manuale con chiave</li>";
    echo "</ul>";
    
    echo "<h5>🔄 Esecuzione Programmata:</h5>";
    echo "<p><strong>Crontab Consigliato:</strong></p>";
    echo "<code style='background: #f5f5f5; padding: 10px; display: block; margin: 10px 0;'>";
    echo "# Ogni giorno alle 00:00<br>";
    echo "0 0 * * * /usr/bin/php " . __FILE__ . " > /dev/null 2>&1";
    echo "</code>";
    
    echo "<h5>📧 Email Destinatario:</h5>";
    echo "<p><strong>Admin Email:</strong> " . AdminNotifier::ADMIN_EMAIL . "</p>";
    echo "<p><strong>Cooldown:</strong> " . AdminNotifier::COOLDOWN_MINUTES . " minuti tra notifiche simili</p>";
    
    echo "</div>";
    exit(0);
}

?>