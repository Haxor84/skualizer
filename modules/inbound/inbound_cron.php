<?php
/**
 * Inbound Cron Job - Multi-User Sequential Sync
 * File: modules/inbound/inbound_cron.php
 * 
 * Features:
 * - Processa TUTTI gli utenti attivi con token Amazon
 * - Loop sequenziale (non parallelo)
 * - Lock per utente (previene concorrenza)
 * - Circuit breaker check per utente
 * - Errori per utente non bloccano gli altri
 * - Summary finale aggregato
 * - Log su file per monitoring esterno
 * - ✅ PROTEZIONE: Skip spedizioni MANUAL (importate manualmente)
 * 
 * Configurazione Cron (esempio):
 * Ogni 15 minuti: 0,15,30,45 * * * * php /path/to/inbound_cron.php >> /var/log/inbound_cron.log 2>&1
 * 
 * @version 2.1
 * @date 2025-10-18
 */

// ============================================
// SETUP
// ============================================
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/inbound_core.php';
require_once __DIR__ . '/../margynomic/admin_notifier.php';

// Include Mobile Cache Event System
require_once dirname(__DIR__) . '/mobile/helpers/cache_events.php';

// Set execution limits
set_time_limit(3600); // 1 ora max
ini_set('memory_limit', '1024M');
error_reporting(E_ALL);

// Disable output buffering per output real-time
if (ob_get_level() > 0) {
    ob_end_flush();
}

// ============================================
// LOGGING HELPER
// ============================================
function cronLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}\n";
    echo $logLine;
    
    // Write to file log
    $logFile = __DIR__ . '/cron_inbound.log';
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

// ============================================
// MAIN EXECUTION
// ============================================
cronLog("═══════════════════════════════════════════════════════", 'INFO');
cronLog("INBOUND CRON - START", 'INFO');
cronLog("═══════════════════════════════════════════════════════", 'INFO');

$cronStart = microtime(true);
$db = getDbConnection();

// Summary
$summary = [
    'users_processed' => 0,
    'users_success' => 0,
    'users_failed' => 0,
    'users_skipped' => 0,
    'total_shipments_synced' => 0,
    'total_shipments_skipped' => 0,
    'total_shipments_partial' => 0,
    'total_errors' => 0,
    'total_api_calls' => 0
];

// Dettagli per email
$emailDetails = [
    'users' => [],
    'errors' => []
];

try {
    // ============================================
    // FETCH ACTIVE USERS
    // ============================================
    cronLog("Fetching active users with Amazon tokens...");
    
    $stmt = $db->query("
        SELECT DISTINCT u.id, u.nome, u.email
        FROM users u
        INNER JOIN amazon_client_tokens t ON t.user_id = u.id
        WHERE u.is_active = 1 
          AND t.is_active = 1
          AND u.role != 'admin'
        ORDER BY u.id
    ");
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        cronLog("No active users found with Amazon tokens", 'WARNING');
        cronLog("CRON COMPLETED - No work to do", 'INFO');
        exit(0);
    }
    
    cronLog("Found " . count($users) . " users to process");
    cronLog("");
    
    // ============================================
    // PROCESS EACH USER SEQUENTIALLY
    // ============================================
    foreach ($users as $user) {
        $userId = $user['id'];
        $userName = $user['nome'];
        
        cronLog("───────────────────────────────────────────────────────");
        cronLog("Processing User ID: {$userId} - {$userName}", 'INFO');
        cronLog("───────────────────────────────────────────────────────");
        
        $summary['users_processed']++;
        
        try {
            // Create InboundCore instance
            $core = new InboundCore($userId, [
                'cron_mode' => true,           // Minimal logging
                'dry_run' => false,
                'api_calls_limit' => 100,      // Safety limit
                'run_timeout' => 1800,         // 30 minuti per utente
                'shipments_limit' => 1000      // Max 1000 shipments per run
            ]);
            
            // Check circuit breaker
            if ($core->isCircuitOpen()) {
                cronLog("Circuit breaker OPEN for user {$userId} - Skipping", 'WARNING');
                $summary['users_skipped']++;
                $emailDetails['users'][] = [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'status' => 'skipped',
                    'reason' => 'Circuit breaker OPEN',
                    'synced' => 0,
                    'skipped' => 0,
                    'partial' => 0,
                    'api_calls' => 0
                ];
                continue;
            }
            
            // Acquire lock
            if (!$core->acquireLock()) {
                cronLog("Failed to acquire lock for user {$userId} - Sync already running? Skipping", 'WARNING');
                $summary['users_skipped']++;
                $emailDetails['users'][] = [
                    'user_id' => $userId,
                    'user_name' => $userName,
                    'status' => 'skipped',
                    'reason' => 'Lock already acquired (sync in progress)',
                    'synced' => 0,
                    'skipped' => 0,
                    'partial' => 0,
                    'api_calls' => 0
                ];
                continue;
            }
            
            cronLog("Lock acquired, starting sync...");
            
            // ✅ Run incremental sync con rolling window 7 giorni
            $userSummary = $core->syncIncremental([
                'window_days' => 7  // Ultimi 7 giorni
            ]);
            
            // Release lock
            $core->releaseLock();
            
            // Log results
            cronLog("User {$userId} sync completed:", 'SUCCESS');
            cronLog("  - Synced: {$userSummary['synced']}", 'INFO');
            cronLog("  - Skipped: {$userSummary['skipped']}", 'INFO');
            cronLog("  - Partial: {$userSummary['partial']}", 'INFO');
            cronLog("  - Errors: {$userSummary['errors']}", 'INFO');
            cronLog("  - API Calls: {$userSummary['api_calls']}", 'INFO');
            cronLog("  - Duration: {$userSummary['duration']}s", 'INFO');
            
            // === INVALIDA CACHE MOBILE (event-driven) ===
            if ($userSummary['synced'] > 0) {
                invalidateCacheOnEvent($userId, 'inbound_sync');
            }
            
            // Aggregate summary
            $summary['users_success']++;
            $summary['total_shipments_synced'] += $userSummary['synced'];
            $summary['total_shipments_skipped'] += $userSummary['skipped'];
            $summary['total_shipments_partial'] += $userSummary['partial'];
            $summary['total_errors'] += $userSummary['errors'];
            $summary['total_api_calls'] += $userSummary['api_calls'];
            
            // Salva dettagli per email
            $emailDetails['users'][] = [
                'user_id' => $userId,
                'user_name' => $userName,
                'status' => 'success',
                'synced' => $userSummary['synced'],
                'skipped' => $userSummary['skipped'],
                'partial' => $userSummary['partial'],
                'api_calls' => $userSummary['api_calls'],
                'duration' => $userSummary['duration']
            ];
            
        } catch (Exception $e) {
            cronLog("ERROR processing user {$userId}: " . $e->getMessage(), 'ERROR');
            $summary['users_failed']++;
            
            // Salva errore per email
            $emailDetails['errors'][] = [
                'user_id' => $userId,
                'user_name' => $userName,
                'message' => $e->getMessage()
            ];
            
            $emailDetails['users'][] = [
                'user_id' => $userId,
                'user_name' => $userName,
                'status' => 'failed',
                'reason' => $e->getMessage(),
                'synced' => 0,
                'skipped' => 0,
                'partial' => 0,
                'api_calls' => 0
            ];
            
            // Try to release lock on error
            if (isset($core)) {
                try {
                    $core->releaseLock();
                    cronLog("Lock released (cleanup)", 'INFO');
                } catch (Exception $lockErr) {
                    // Ignore
                }
            }
            
            // Continue with next user (don't stop cron)
            continue;
        }
        
        cronLog("");
    }
    
    // ============================================
    // FINAL SUMMARY
    // ============================================
    $cronDuration = round(microtime(true) - $cronStart, 2);
    
    cronLog("═══════════════════════════════════════════════════════", 'INFO');
    cronLog("INBOUND CRON - COMPLETED", 'INFO');
    cronLog("═══════════════════════════════════════════════════════", 'INFO');
    cronLog("Duration: {$cronDuration}s", 'INFO');
    cronLog("");
    cronLog("USERS SUMMARY:", 'INFO');
    cronLog("  - Total Processed: {$summary['users_processed']}", 'INFO');
    cronLog("  - Success: {$summary['users_success']}", 'SUCCESS');
    cronLog("  - Failed: {$summary['users_failed']}", $summary['users_failed'] > 0 ? 'ERROR' : 'INFO');
    cronLog("  - Skipped: {$summary['users_skipped']}", 'INFO');
    cronLog("");
    cronLog("SHIPMENTS SUMMARY:", 'INFO');
    cronLog("  - Synced: {$summary['total_shipments_synced']}", 'SUCCESS');
    cronLog("  - Skipped: {$summary['total_shipments_skipped']}", 'INFO');
    cronLog("  - Partial: {$summary['total_shipments_partial']}", $summary['total_shipments_partial'] > 0 ? 'WARNING' : 'INFO');
    cronLog("  - Errors: {$summary['total_errors']}", $summary['total_errors'] > 0 ? 'ERROR' : 'INFO');
    cronLog("  - API Calls: {$summary['total_api_calls']}", 'INFO');
    cronLog("═══════════════════════════════════════════════════════", 'INFO');
    
    // ============================================
    // SEND EMAIL NOTIFICATION
    // ============================================
    try {
        $emailDetails['duration'] = $cronDuration;
        $emailResult = AdminNotifier::notifyInboundCronCompletion($summary, $emailDetails);
        
        if ($emailResult) {
            cronLog("✅ Email notification sent successfully", 'INFO');
        } else {
            cronLog("⚠️  Email notification skipped (cooldown or failed)", 'WARNING');
        }
    } catch (Exception $emailError) {
        cronLog("⚠️  Failed to send email notification: " . $emailError->getMessage(), 'WARNING');
    }
    
    // Save summary to DB (kpi_snapshot)
    try {
        $stmt = $db->prepare("
            INSERT INTO kpi_snapshot (user_id, snapshot_date, total_shipments, total_items, total_boxes, partial_count, metadata)
            SELECT 
                s.user_id,
                NOW(),
                COUNT(DISTINCT s.id),
                COUNT(DISTINCT i.id),
                COUNT(DISTINCT b.id),
                COUNT(DISTINCT CASE WHEN ss.sync_status IN ('partial_loop','partial_no_progress','missing') THEN s.id END),
                JSON_OBJECT(
                    'cron_duration', ?,
                    'users_processed', ?,
                    'api_calls', ?
                )
            FROM inbound_shipments s
            LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
            LEFT JOIN inbound_shipment_items i ON i.shipment_id = s.id
            LEFT JOIN inbound_shipment_boxes b ON b.shipment_id = s.id
            GROUP BY s.user_id
        ");
        
        $stmt->execute([
            $cronDuration,
            $summary['users_processed'],
            $summary['total_api_calls']
        ]);
        
        cronLog("KPI snapshots saved to database", 'INFO');
        
    } catch (Exception $e) {
        cronLog("Warning: Failed to save KPI snapshot: " . $e->getMessage(), 'WARNING');
    }
    
    // Exit code based on results
    if ($summary['users_failed'] > 0) {
        exit(1); // Some failures
    } elseif ($summary['total_errors'] > 0) {
        exit(2); // Shipment errors but no user failures
    } else {
        exit(0); // Success
    }
    
} catch (Exception $e) {
    cronLog("═══════════════════════════════════════════════════════", 'ERROR');
    cronLog("FATAL ERROR: " . $e->getMessage(), 'ERROR');
    cronLog("Stack Trace:", 'ERROR');
    cronLog($e->getTraceAsString(), 'ERROR');
    cronLog("═══════════════════════════════════════════════════════", 'ERROR');
    exit(99);
}

