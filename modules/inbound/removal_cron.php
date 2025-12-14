<?php
/**
 * Removal Orders Cron Job - Multi-User Sequential Download
 * File: modules/inbound/removal_cron.php
 * 
 * Features:
 * - Processa TUTTI gli utenti attivi con token Amazon
 * - Download report GET_FBA_RECOMMENDED_REMOVAL_DATA
 * - Parse TSV e salvataggio in removal_orders table usando RemovalOrdersAPI
 * - Lock per utente (previene concorrenza)
 * - Errori per utente non bloccano gli altri
 * - Summary finale aggregato
 * 
 * Configurazione Cron (esempio):
 * Daily at 02:00: 0 2 * * * php /path/to/removal_cron.php >> /var/log/removal_cron.log 2>&1
 * 
 * @version 2.0
 * @date 2025-11-07
 */

// ============================================
// SETUP
// ============================================
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/config/CentralLogger.php';

// Include Mobile Cache Event System
require_once dirname(__DIR__) . '/mobile/helpers/cache_events.php';

// Setup session for RemovalOrdersAPI
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_logged'] = true; // Bypass admin check for cron

// Include RemovalOrdersAPI class (reuse existing code)
require_once __DIR__ . '/removal_api.php';

// Set execution limits
set_time_limit(3600); // 1 ora max
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

// Prevent nginx timeout by sending output
ob_implicit_flush(true);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);

// ============================================
// LOGGING HELPER
// ============================================
function cronLog($message, $level = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[{$timestamp}] [{$level}] {$message}\n";
    echo $logLine;
    
    // Also save to CentralLogger for database tracking
    if ($level === 'ERROR') {
        CentralLogger::log('removal_cron', 'ERROR', $message);
    }
}

// ============================================
// MAIN EXECUTION
// ============================================
try {
    $startTime = microtime(true);
    cronLog("=== REMOVAL ORDERS CRON START ===", "INFO");
    
    // Get database connection
    $db = getDbConnection();
    
    // Get all active users with Amazon tokens
    $stmt = $db->query("
        SELECT DISTINCT u.id, u.nome
        FROM users u
        INNER JOIN amazon_client_tokens t ON t.user_id = u.id
        WHERE u.is_active = 1 AND t.is_active = 1
        ORDER BY u.id
    ");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    cronLog("Trovati " . count($users) . " utenti da processare", "INFO");
    
    $stats = [
        'total_users' => count($users),
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'total_orders' => 0,
        'errors' => []
    ];
    
    // Process each user
    foreach ($users as $user) {
        $userId = $user['id'];
        $userName = $user['nome'];
        
        cronLog("", "INFO");
        cronLog("--- Processing User: {$userName} (ID: {$userId}) ---", "INFO");
        
        // Check and acquire lock
        $stmtCheckLock = $db->prepare("
            SELECT 
                heartbeat_at,
                TIMESTAMPDIFF(SECOND, heartbeat_at, NOW()) as heartbeat_age_seconds
            FROM sync_locks 
            WHERE user_id = ?
        ");
        $stmtCheckLock->execute([$userId]);
        $existingLock = $stmtCheckLock->fetch(PDO::FETCH_ASSOC);
        
        if ($existingLock) {
            $heartbeatAge = (int)$existingLock['heartbeat_age_seconds'];
            
            // Se heartbeat > 10 minuti, lock è stuck → rimuovi
            if ($heartbeatAge > 600) {
                cronLog("User {$userId} has stuck lock (heartbeat age: {$heartbeatAge}s, last: {$existingLock['heartbeat_at']}). Removing.", "WARNING");
                $stmtRemove = $db->prepare("DELETE FROM sync_locks WHERE user_id = ?");
                $stmtRemove->execute([$userId]);
            } else {
                // Lock valido, skip
                cronLog("User {$userId} has active lock (heartbeat age: {$heartbeatAge}s, last: {$existingLock['heartbeat_at']}). Skipping.", "WARNING");
                $stats['skipped']++;
                continue;
            }
        }
        
        // Acquire lock
        $processId = uniqid('removal_cron_', true) . '_' . getmypid();
        $stmtLock = $db->prepare("
            INSERT INTO sync_locks (user_id, locked_at, heartbeat_at, process_id)
            VALUES (?, NOW(), NOW(), ?)
            ON DUPLICATE KEY UPDATE 
                locked_at = NOW(),
                heartbeat_at = NOW(),
                process_id = VALUES(process_id)
        ");
        $stmtLock->execute([$userId, $processId]);
        cronLog("Lock acquired for user {$userId} (process: {$processId})", "INFO");
        
        try {
            // Download and parse report using RemovalOrdersAPI
            $result = downloadRemovalOrdersForUser($db, $userId);
            
            if ($result['success']) {
                cronLog("✅ User {$userId}: Downloaded {$result['orders_count']} removal orders", "SUCCESS");
                $stats['success']++;
                $stats['total_orders'] += $result['orders_count'];
                
                // === INVALIDA CACHE MOBILE (event-driven) ===
                if ($result['orders_count'] > 0) {
                    invalidateCacheOnEvent($userId, 'removal_sync');
                }
            } else {
                cronLog("❌ User {$userId}: {$result['error']}", "ERROR");
                $stats['failed']++;
                $stats['errors'][] = "User {$userId}: {$result['error']}";
            }
            
        } catch (Exception $e) {
            cronLog("❌ User {$userId} exception: " . $e->getMessage(), "ERROR");
            $stats['failed']++;
            $stats['errors'][] = "User {$userId}: " . $e->getMessage();
        } finally {
            // Release lock
            $stmtUnlock = $db->prepare("DELETE FROM sync_locks WHERE user_id = ?");
            $stmtUnlock->execute([$userId]);
            cronLog("Lock released for user {$userId}", "INFO");
        }
        
        // Small delay between users
        sleep(2);
    }
    
    // Final summary
    $duration = round(microtime(true) - $startTime, 2);
    cronLog("", "INFO");
    cronLog("=== REMOVAL ORDERS CRON SUMMARY ===", "INFO");
    cronLog("Duration: {$duration}s", "INFO");
    cronLog("Users Processed: {$stats['total_users']}", "INFO");
    cronLog("Success: {$stats['success']}", "INFO");
    cronLog("Failed: {$stats['failed']}", "INFO");
    cronLog("Skipped: {$stats['skipped']}", "INFO");
    cronLog("Total Removal Orders: {$stats['total_orders']}", "INFO");
    
    if (!empty($stats['errors'])) {
        cronLog("Errors:", "ERROR");
        foreach ($stats['errors'] as $error) {
            cronLog("  - {$error}", "ERROR");
        }
    }
    
    cronLog("=== REMOVAL ORDERS CRON END ===", "INFO");
    
} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage(), "ERROR");
    cronLog($e->getTraceAsString(), "ERROR");
    exit(1);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Download and parse Removal Orders for a single user using RemovalOrdersAPI
 */
function downloadRemovalOrdersForUser($db, $userId) {
    try {
        // Use existing RemovalOrdersAPI class (DRY principle!)
        $api = new RemovalOrdersAPI($userId);
        
        // Date range: last 3 years (same as UI default)
        $startDate = date('Y-m-d', strtotime('-3 years'));
        $endDate = date('Y-m-d');
        
        // Step 1: Request report
        $reportResult = $api->requestReport($startDate, $endDate);
        if (!$reportResult['success']) {
            throw new Exception($reportResult['error'] ?? 'Report request failed');
        }
        
        $reportId = $reportResult['report_id'];
        cronLog("Report requested: {$reportId}", "INFO");
        
        // Step 2: Poll for completion (max 10 minutes)
        $maxAttempts = 60;
        $attempts = 0;
        $documentId = null;
        
        while ($attempts < $maxAttempts) {
            sleep(10);
            $attempts++;
            
            $statusResult = $api->checkReportStatus($reportId);
            if (!$statusResult['success']) {
                throw new Exception($statusResult['error'] ?? 'Status check failed');
            }
            
            $status = $statusResult['status'];
            
            // Log ogni 30 secondi per evitare spam
            if ($attempts % 3 == 0 || $status !== 'IN_PROGRESS') {
                cronLog("Attempt {$attempts}/{$maxAttempts}: Status = {$status}", "INFO");
            }
            
            if ($status === 'DONE') {
                $documentId = $statusResult['document_id'] ?? null;
                break;
            } elseif ($status === 'CANCELLED') {
                // Report cancelled by Amazon (no data available) - not an error
                cronLog("Report cancelled by Amazon (no removal orders data available)", "INFO");
                return [
                    'success' => true,
                    'orders_count' => 0
                ];
            } elseif ($status === 'FATAL') {
                throw new Exception("Report failed: FATAL");
            }
        }
        
        if (!$documentId) {
            throw new Exception("Report timeout after {$attempts} attempts");
        }
        
        // Step 3: Download and parse TSV using RemovalOrdersAPI
        $downloadResult = $api->downloadReport($documentId);
        if (!$downloadResult['success']) {
            throw new Exception($downloadResult['error'] ?? 'Download failed');
        }
        
        $inserted = $downloadResult['inserted'] ?? 0;
        $skipped = $downloadResult['skipped'] ?? 0;
        $totalProcessed = $inserted + $skipped;
        
        cronLog("Report processed: {$inserted} inserted/updated, {$skipped} skipped, {$totalProcessed} total", "INFO");
        
        return [
            'success' => true,
            'orders_count' => $totalProcessed
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

