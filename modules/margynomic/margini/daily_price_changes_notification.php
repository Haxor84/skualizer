<?php
/**
 * Cron Daily Price Changes Notification
 * File: modules/margynomic/margini/daily_price_changes_notification.php
 * 
 * Script cron giornaliero per notifica modifiche prezzi pending
 * Esegui: Ogni giorno alle 15:00
 * Crontab: 0 15 * * * /usr/bin/php /path/to/daily_price_changes_notification.php
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_time_limit(120);
ini_set('memory_limit', '128M');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../gestione_vendor.php';

/**
 * Log cron price notification
 */
function logPriceNotification($message, $level = 'INFO', $context = []) {
    CentralLogger::log('cron', $level, $message, $context);
    
    if (php_sapi_name() === 'cli') {
        $timestamp = date('H:i:s');
        echo "[{$timestamp}] [PRICE_NOTIFICATION] [{$level}] {$message}" . PHP_EOL;
    }
}

// === ESECUZIONE PRINCIPALE ===
$startTime = microtime(true);

try {
    logPriceNotification('Avvio cron notifica modifiche prezzi giornaliera');
    
    $pdo = getDbConnection();
    
    // Query: conta modifiche prezzi ultime 24h per ogni utente
    $stmt = $pdo->query("
        SELECT 
            apu.user_id,
            u.nome as user_name,
            u.email as user_email,
            COUNT(*) as changes_count,
            MIN(apu.created_at) as first_change,
            MAX(apu.created_at) as last_change
        FROM amazon_price_updates_log apu
        INNER JOIN users u ON u.id = apu.user_id
        WHERE apu.created_at >= NOW() - INTERVAL 24 HOUR
          AND apu.status = 'pending'
        GROUP BY apu.user_id
        HAVING changes_count > 0
        ORDER BY changes_count DESC
    ");
    
    $usersWithChanges = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($usersWithChanges)) {
        logPriceNotification('Nessuna modifica prezzo pending nelle ultime 24h - Email non inviata');
        exit(0);
    }
    
    logPriceNotification(count($usersWithChanges) . " utenti con modifiche prezzi pending");
    
    // URL pagina export
    $exportUrl = 'https://www.skualizer.com/modules/margynomic/admin/admin_amazon_price_export.php';
    
    // Prepara dati email
    $totalChanges = array_sum(array_column($usersWithChanges, 'changes_count'));
    
    // Costruisci corpo email HTML
    $emailBody = "
    <div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 12px 12px 0 0;'>
            <h1 style='margin: 0; font-size: 28px;'>💰 Modifiche Prezzi Amazon</h1>
            <p style='margin: 10px 0 0 0; font-size: 16px; opacity: 0.9;'>Report giornaliero modifiche pending</p>
        </div>
        
        <div style='background: #f8f9fa; padding: 30px;'>
            <div style='background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px;'>
                <h2 style='color: #333; margin: 0 0 16px 0; font-size: 20px;'>📊 Riepilogo</h2>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 12px 0; border-bottom: 1px solid #e9ecef;'>
                            <strong style='color: #667eea; font-size: 32px;'>{$totalChanges}</strong>
                            <br>
                            <span style='color: #6c757d; font-size: 14px;'>Modifiche Totali</span>
                        </td>
                        <td style='padding: 12px 0; border-bottom: 1px solid #e9ecef;'>
                            <strong style='color: #28a745; font-size: 32px;'>" . count($usersWithChanges) . "</strong>
                            <br>
                            <span style='color: #6c757d; font-size: 14px;'>Utenti Coinvolti</span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div style='background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px;'>
                <h3 style='color: #333; margin: 0 0 16px 0; font-size: 18px;'>👥 Dettaglio per Utente</h3>
                <table style='width: 100%; border-collapse: collapse;'>
                    <thead>
                        <tr style='background: #f8f9fa;'>
                            <th style='padding: 10px; text-align: left; border-bottom: 2px solid #dee2e6;'>Utente</th>
                            <th style='padding: 10px; text-align: center; border-bottom: 2px solid #dee2e6;'>Modifiche</th>
                            <th style='padding: 10px; text-align: left; border-bottom: 2px solid #dee2e6;'>Periodo</th>
                        </tr>
                    </thead>
                    <tbody>";
    
    foreach ($usersWithChanges as $user) {
        $emailBody .= "
                        <tr>
                            <td style='padding: 12px; border-bottom: 1px solid #e9ecef;'>
                                <strong style='color: #495057;'>{$user['user_name']}</strong>
                                <br>
                                <small style='color: #6c757d;'>{$user['user_email']}</small>
                            </td>
                            <td style='padding: 12px; border-bottom: 1px solid #e9ecef; text-align: center;'>
                                <span style='background: #d4edda; color: #155724; padding: 6px 12px; border-radius: 12px; font-weight: 600;'>
                                    {$user['changes_count']}
                                </span>
                            </td>
                            <td style='padding: 12px; border-bottom: 1px solid #e9ecef;'>
                                <small style='color: #6c757d;'>
                                    " . date('d/m/Y H:i', strtotime($user['first_change'])) . "
                                    <br>
                                    " . date('d/m/Y H:i', strtotime($user['last_change'])) . "
                                </small>
                            </td>
                        </tr>";
    }
    
    $emailBody .= "
                    </tbody>
                </table>
            </div>
            
            <div style='background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); padding: 20px; border-radius: 12px; border-left: 4px solid #f59e0b; margin-bottom: 20px;'>
                <h3 style='color: #856404; margin: 0 0 12px 0; font-size: 16px;'>⚡ Azioni Necessarie</h3>
                <ol style='color: #856404; margin: 0; padding-left: 20px; line-height: 1.6;'>
                    <li>Accedi alla pagina export prezzi</li>
                    <li>Filtra per data/utente se necessario</li>
                    <li>Seleziona i prodotti da esportare</li>
                    <li>Genera file TSV Amazon</li>
                    <li>Carica su Amazon Seller Central</li>
                    <li>Marca come completati dopo upload</li>
                </ol>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <a href='{$exportUrl}' 
                   style='display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 16px 40px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);'>
                    🚀 Vai alla Pagina Export Prezzi
                </a>
            </div>
            
            <div style='background: white; padding: 20px; border-radius: 12px; text-align: center;'>
                <p style='color: #6c757d; margin: 0; font-size: 13px;'>
                    <strong>💡 Tip:</strong> Dopo aver caricato i prezzi su Amazon Seller Central, 
                    usa il pulsante \"Marca come Completati\" per aggiornare lo status.
                </p>
            </div>
        </div>
        
        <div style='background: #343a40; color: white; padding: 20px; text-align: center; border-radius: 0 0 12px 12px;'>
            <p style='margin: 0; font-size: 12px; opacity: 0.8;'>
                Margynomic Admin System - Notifica automatica giornaliera<br>
                Generato il " . date('d/m/Y H:i:s') . "
            </p>
        </div>
    </div>";
    
    // Invia email admin
    $subject = "💰 {$totalChanges} Modifiche Prezzi Amazon da Processare";
    $adminEmail = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'info@skualizer.com';
    
    $emailResult = inviaEmailSMTP($adminEmail, $subject, $emailBody);
    
    if ($emailResult) {
        logPriceNotification("Email inviata con successo a {$adminEmail}", 'SUCCESS', [
            'total_changes' => $totalChanges,
            'users_count' => count($usersWithChanges)
        ]);
    } else {
        logPriceNotification("Errore invio email", 'ERROR', [
            'recipient' => $adminEmail
        ]);
        exit(1);
    }
    
    $executionTime = round(microtime(true) - $startTime, 2);
    
    logPriceNotification("Cron completato in {$executionTime}s", 'SUCCESS', [
        'execution_time' => $executionTime,
        'total_changes' => $totalChanges,
        'users_count' => count($usersWithChanges)
    ]);
    
    exit(0);
    
} catch (Exception $e) {
    logPriceNotification("ERRORE CRITICO: " . $e->getMessage(), 'CRITICAL', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
    
    exit(2);
}

// === INFO PAGE ===
if (isset($_GET['info'])) {
    echo "<h3>💰 Cron Daily Price Changes Notification</h3>";
    echo "<div style='background: #f0f8ff; padding: 20px; border-left: 5px solid #667eea; margin: 15px 0;'>";
    echo "<h4>📋 Informazioni</h4>";
    echo "<p><strong>Scopo:</strong> Notifica giornaliera delle modifiche prezzi pending da caricare su Amazon.</p>";
    
    echo "<h5>⚙️ Funzionalità:</h5>";
    echo "<ul>";
    echo "<li>Analizza modifiche prezzi ultime 24h</li>";
    echo "<li>Invia email solo se ci sono modifiche pending</li>";
    echo "<li>Raggruppa per utente con dettagli</li>";
    echo "<li>Link diretto alla pagina export</li>";
    echo "</ul>";
    
    echo "<h5>🔄 Esecuzione Programmata:</h5>";
    echo "<code style='background: #f5f5f5; padding: 10px; display: block;'>";
    echo "0 15 * * * /usr/bin/php " . __FILE__;
    echo "</code>";
    
    echo "<p><strong>Orario:</strong> Ogni giorno alle 15:00</p>";
    echo "<p><strong>Email destinatario:</strong> " . (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'info@skualizer.com') . "</p>";
    echo "</div>";
    exit(0);
}
?>

