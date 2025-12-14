<?php
/**
 * Cron Settlement Margynomic - Automazione Settlement Reports
 * File: modules/margynomic/sincro/cron_settlement_margynomic.php
 * 
 * Sincronizza automaticamente SOLO Settlement Reports per tutti gli utenti attivi
 * Eseguito ogni 2 giorni alle 02:00 via cron
 */

require_once '../config/config.php';
require_once 'sync_helpers.php';
require_once '../admin_notifier.php';

// Include Mobile Cache Event System
require_once dirname(__DIR__, 2) . '/mobile/helpers/cache_events.php';

// PROTEZIONE TEMPORANEAMENTE DISABILITATA PER TEST
// if (php_sapi_name() !== 'cli' && !isset($_GET['manual_key'])) {
//     http_response_code(403);
//     die('Script eseguibile solo via CLI o con chiave manuale');
// }

// Log inizio
$startTime = microtime(true);

try {
    $pdo = getDbConnection();
    
    // Trova utenti con token Amazon attivo
    $stmt = $pdo->prepare("
        SELECT DISTINCT user_id, marketplace_id
        FROM amazon_client_tokens 
        WHERE is_active = 1 
        AND refresh_token IS NOT NULL
    ");
    $stmt->execute();
    $activeUsers = $stmt->fetchAll();
    
    if (empty($activeUsers)) {
        logSettlement("Nessun utente attivo da sincronizzare");
        exit(0);
    }
    
    $totalSettlementReports = 0;
    $successCount = 0;
    $errorCount = 0;
    
    $userDetails = []; // Array per tracciare dettagli utenti
    
    foreach ($activeUsers as $user) {
        $userId = $user['user_id'];
        $marketplace = $user['marketplace_id'];
        
        try {
            // Usa la funzione esistente di populate_queue.php
            $settlementReports = popolaCodaReport($userId);
            
            if ($settlementReports === false) {
                $errorCount++;
                $userDetails[] = ['user_id' => $userId, 'reports' => 0, 'status' => 'error'];
            } else {
                $totalSettlementReports += $settlementReports;
                $userDetails[] = ['user_id' => $userId, 'reports' => $settlementReports, 'status' => 'success'];
                
                // === INVALIDA CACHE MOBILE (event-driven) ===
                // Quando vengono importati nuovi settlement, invalida cache Orders e Margins
                if ($settlementReports > 0) {
                    invalidateCacheOnEvent($userId, 'settlement_sync');
                }
            }
            
            $successCount++;
            
            // Pausa tra utenti per evitare rate limiting
            sleep(3);
            
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            logSettlement("  ✗ Errore critico per utente {$userId}: {$errorMessage}", 'ERROR');
            
            logSyncOperation($userId, 'cron_settlement_error', 'error', 
                'Errore cron settlement: ' . $errorMessage);
            
            // Notifica admin fallimento settlement
            AdminNotifier::notifySettlementCronFailure($errorMessage, [
                'user_id' => $userId ?? 'unknown',
                'marketplace' => $marketplace ?? 'unknown',
                'operation' => 'settlement_sync'
            ]);
            
            $errorCount++;
        }
    }
    
    // LOG UNICO CONSOLIDATO
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    logSettlement(
        sprintf('Settlement sync completato: %d report da %d utenti in %.2fs', 
            $totalSettlementReports, count($activeUsers), $executionTime),
        $errorCount > 0 ? 'WARNING' : 'SUCCESS',
        [
            'users_processed' => count($activeUsers),
            'users_success' => $successCount,
            'users_failed' => $errorCount,
            'total_reports' => $totalSettlementReports,
            'duration_seconds' => $executionTime,
            'details' => $userDetails
        ]
    );
    
    // Log per sync_debug_logs
    logSyncOperation(0, 'cron_settlement_completed', 'info', 
        'Cron settlement completato', [
            'users_processed' => count($activeUsers),
            'successes' => $successCount,
            'errors' => $errorCount,
            'settlement_reports' => $totalSettlementReports,
            'execution_time' => $executionTime,
            'details' => $userDetails
        ]);
    
    // Pulizia gestita automaticamente da CentralLogger
    
    exit($errorCount > 0 ? 1 : 0);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    logSettlement("ERRORE CRITICO CRON SETTLEMENT: {$errorMessage}", 'CRITICAL');
    
    logSyncOperation(0, 'cron_settlement_critical_error', 'error', 
        'Errore critico cron settlement: ' . $errorMessage, [
            'error' => $errorMessage,
            'stack_trace' => $e->getTraceAsString()
        ]);
    
    // Notifica admin fallimento critico settlement
    AdminNotifier::notifySettlementCronFailure($errorMessage, [
        'error_type' => 'critical',
        'stack_trace' => $e->getTraceAsString(),
        'operation' => 'settlement_sync_critical'
    ]);
    
    exit(2);
}

/**
 * Funzione obsoleta - pulizia gestita da CentralLogger
 */
function cleanupSettlementLogs() {
    // Non più necessaria - CentralLogger gestisce automaticamente la rotazione
    logSettlement("Pulizia log gestita automaticamente da CentralLogger");
}

/**
 * Funzione per test manuale
 */
function testSettlementCron() {
    if (php_sapi_name() === 'cli' && isset($GLOBALS['argv'][1]) && $GLOBALS['argv'][1] === '--test') {
        logSettlement("=== MODALITÀ TEST SETTLEMENT ===");
        
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total_users
            FROM amazon_client_tokens 
            WHERE is_active = 1 AND refresh_token IS NOT NULL
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        logSettlement("Test completato. Trovati " . $result['total_users'] . " utenti per settlement sync");
        exit(0);
    }
}

// Esegui test se richiesto
testSettlementCron();

?>