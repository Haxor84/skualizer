<?php
/**
 * Enhanced Log Cleanup Script
 * File: modules/margynomic/cleanup_logs_enhanced.php
 * 
 * Pulizia automatica tabelle log con retention policies
 * Da eseguire via cron: 0 3 * * 0 (ogni domenica alle 3 AM)
 */

require_once __DIR__ . '/config/config.php';

try {
    $pdo = getDbConnection();
    
    echo "=== LOG CLEANUP ENHANCED ===\n";
    echo "Start: " . date('Y-m-d H:i:s') . "\n\n";
    
    // === 1. CLEANUP sync_debug_logs (60 giorni) ===
    $stmt = $pdo->query("
        DELETE FROM sync_debug_logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
    ");
    $deleted1 = $stmt->rowCount();
    echo "✓ sync_debug_logs: {$deleted1} righe eliminate (>60 giorni)\n";
    
    // === 2. CLEANUP api_debug_log (30 giorni) ===
    $stmt = $pdo->query("
        DELETE FROM api_debug_log 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $deleted2 = $stmt->rowCount();
    echo "✓ api_debug_log: {$deleted2} righe eliminate (>30 giorni)\n";
    
    // === 3. CLEANUP module_health_history (180 giorni) ===
    $stmt = $pdo->query("
        DELETE FROM module_health_history 
        WHERE measured_at < DATE_SUB(NOW(), INTERVAL 180 DAY)
    ");
    $deleted3 = $stmt->rowCount();
    echo "✓ module_health_history: {$deleted3} righe eliminate (>180 giorni)\n";
    
    // === 4. CLEANUP admin_notifications_log (365 giorni) ===
    $stmt = $pdo->query("
        DELETE FROM admin_notifications_log 
        WHERE sent_at < DATE_SUB(NOW(), INTERVAL 365 DAY)
    ");
    $deleted4 = $stmt->rowCount();
    echo "✓ admin_notifications_log: {$deleted4} righe eliminate (>365 giorni)\n";
    
    // === 5. CLEANUP daily_metrics_snapshot (365 giorni) ===
    $stmt = $pdo->query("
        DELETE FROM daily_metrics_snapshot 
        WHERE metric_date < DATE_SUB(CURDATE(), INTERVAL 365 DAY)
    ");
    $deleted5 = $stmt->rowCount();
    echo "✓ daily_metrics_snapshot: {$deleted5} righe eliminate (>365 giorni)\n";
    
    // === 6. OPTIMIZE TABLES ===
    echo "\n=== OTTIMIZZAZIONE TABELLE ===\n";
    $tables = [
        'sync_debug_logs',
        'api_debug_log',
        'module_health_history',
        'admin_notifications_log',
        'daily_metrics_snapshot'
    ];
    
    foreach ($tables as $table) {
        $pdo->exec("OPTIMIZE TABLE {$table}");
        echo "✓ {$table} ottimizzata\n";
    }
    
    // === RIEPILOGO ===
    $totalDeleted = $deleted1 + $deleted2 + $deleted3 + $deleted4 + $deleted5;
    echo "\n=== RIEPILOGO ===\n";
    echo "Totale righe eliminate: {$totalDeleted}\n";
    echo "Fine: " . date('Y-m-d H:i:s') . "\n";
    
    // Log su CentralLogger
    CentralLogger::log('system', 'INFO', 
        "Log cleanup completato: {$totalDeleted} righe eliminate",
        [
            'sync_debug_logs' => $deleted1,
            'api_debug_log' => $deleted2,
            'module_health_history' => $deleted3,
            'admin_notifications_log' => $deleted4,
            'daily_metrics_snapshot' => $deleted5
        ]
    );
    
} catch (Exception $e) {
    echo "ERRORE: " . $e->getMessage() . "\n";
    CentralLogger::log('system', 'ERROR', 
        "Errore log cleanup: " . $e->getMessage());
}
?>

