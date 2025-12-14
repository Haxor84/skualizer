<?php
/**
 * TRID Ledger Cron Job - Multi-User Sequential Download
 * File: modules/inbound/trid/trid_cron.php
 * 
 * Features:
 * - Processa TUTTI gli utenti attivi con token Amazon
 * - Download report GET_LEDGER_DETAIL_VIEW_DATA (ultimi 30 giorni)
 * - Parse TSV e salvataggio in shipments_trid table
 * - Auto-assignment a spedizioni e prodotti
 * - Lock per utente (previene concorrenza)
 * - Errori per utente non bloccano gli altri
 * - Summary finale aggregato
 * - Log su file per monitoring esterno
 * 
 * Configurazione Cron (esempio):
 * Daily at 03:00: 0 3 * * * php /path/to/trid_cron.php >> /var/log/trid_cron.log 2>&1
 * 
 * @version 1.0
 * @date 2025-11-07
 */

// ============================================
// SETUP
// ============================================
require_once __DIR__ . '/../../margynomic/config/config.php';
require_once __DIR__ . '/../../margynomic/config/CentralLogger.php';

// Include Mobile Cache Event System
require_once dirname(__DIR__, 2) . '/mobile/helpers/cache_events.php';

// Set execution limits
set_time_limit(3600); // 1 ora max
ini_set('memory_limit', '1024M');
error_reporting(E_ALL);

// Disable output buffering per output real-time
if (ob_get_level() > 0) {
    ob_end_flush();
}

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
    
    // Write to file log
    $logFile = __DIR__ . '/cron_trid.log';
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

// ============================================
// MAIN EXECUTION
// ============================================
$startTime = microtime(true);
cronLog("=== TRID LEDGER CRON START ===", "INFO");

try {
    $db = getDbConnection();
    
    // Get all active users with Amazon tokens
    $stmtUsers = $db->query("
        SELECT DISTINCT u.id, u.nome, u.email
        FROM users u
        INNER JOIN amazon_client_tokens act 
            ON act.user_id = u.id 
            AND act.is_active = 1
        WHERE act.refresh_token IS NOT NULL
        ORDER BY u.id
    ");
    $users = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        cronLog("Nessun utente attivo con token Amazon trovato", "WARNING");
        exit(0);
    }
    
    cronLog("Trovati " . count($users) . " utenti da processare", "INFO");
    
    // Statistics
    $stats = [
        'total_users' => count($users),
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'total_events' => 0,
        'total_assigned_shipments' => 0,
        'total_assigned_products' => 0,
        'errors' => []
    ];
    
    // Process each user sequentially
    foreach ($users as $user) {
        $userId = $user['id'];
        $userName = $user['nome'] ?: $user['email'];
        
        cronLog("", "INFO");
        cronLog("--- Processing User: {$userName} (ID: {$userId}) ---", "INFO");
        
        // Check lock
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
        $processId = uniqid('trid_cron_', true) . '_' . getmypid();
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
            // Download and parse report (last 30 days)
            $startDate = date('Y-m-d', strtotime('-30 days'));
            $endDate = date('Y-m-d');
            
            $result = downloadTridLedgerForUser($db, $userId, $startDate, $endDate);
            
            if ($result['success']) {
                cronLog("✅ User {$userId}: Imported {$result['imported']} events, {$result['assigned_shipments']} shipments assigned, {$result['assigned_products']} products assigned", "SUCCESS");
                $stats['success']++;
                $stats['total_events'] += $result['imported'];
                $stats['total_assigned_shipments'] += $result['assigned_shipments'];
                $stats['total_assigned_products'] += $result['assigned_products'];
                
                // === INVALIDA CACHE MOBILE (event-driven) ===
                if ($result['imported'] > 0) {
                    invalidateCacheOnEvent($userId, 'trid_sync');
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
        sleep(3);
    }
    
    // Final summary
    $duration = round(microtime(true) - $startTime, 2);
    cronLog("", "INFO");
    cronLog("=== TRID LEDGER CRON SUMMARY ===", "INFO");
    cronLog("Duration: {$duration}s", "INFO");
    cronLog("Users Processed: {$stats['total_users']}", "INFO");
    cronLog("Success: {$stats['success']}", "INFO");
    cronLog("Failed: {$stats['failed']}", "INFO");
    cronLog("Skipped: {$stats['skipped']}", "INFO");
    cronLog("Total TRID Events: {$stats['total_events']}", "INFO");
    cronLog("Shipments Auto-Assigned: {$stats['total_assigned_shipments']}", "INFO");
    cronLog("Products Auto-Assigned: {$stats['total_assigned_products']}", "INFO");
    
    if (!empty($stats['errors'])) {
        cronLog("Errors:", "ERROR");
        foreach ($stats['errors'] as $error) {
            cronLog("  - {$error}", "ERROR");
        }
    }
    
    cronLog("=== TRID LEDGER CRON END ===", "INFO");
    
} catch (Exception $e) {
    cronLog("FATAL ERROR: " . $e->getMessage(), "ERROR");
    cronLog($e->getTraceAsString(), "ERROR");
    exit(1);
}

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Download and parse TRID Ledger for a single user
 */
function downloadTridLedgerForUser($db, $userId, $startDate, $endDate) {
    try {
        // Load credentials
        $creds = loadCredentialsForUser($db, $userId);
        $accessToken = getAccessToken($creds);
        
        // Request report
        $reportId = requestLedgerReport($accessToken, $creds, $startDate, $endDate);
        cronLog("Report requested: {$reportId}", "INFO");
        
        // Poll for completion (max 15 minutes)
        $maxAttempts = 90;
        $attempts = 0;
        $documentId = null;
        
        while ($attempts < $maxAttempts) {
            sleep(10);
            $attempts++;
            
            $status = checkReportStatus($reportId, $accessToken, $creds);
            
            if ($attempts % 6 == 0) { // Log every minute
                cronLog("Attempt {$attempts}/{$maxAttempts}: Status = {$status['status']}", "INFO");
            }
            
            if ($status['status'] === 'DONE') {
                $documentId = $status['documentId'];
                break;
            } elseif ($status['status'] === 'FATAL' || $status['status'] === 'CANCELLED') {
                throw new Exception("Report failed: {$status['status']}");
            }
        }
        
        if (!$documentId) {
            throw new Exception("Report timeout after {$attempts} attempts");
        }
        
        // Download TSV
        $tsvContent = downloadReportDocument($documentId, $accessToken, $creds);
        cronLog("Report downloaded: " . strlen($tsvContent) . " bytes", "INFO");
        
        // Parse and save
        $parseResult = parseLedgerTSV($db, $userId, $tsvContent);
        cronLog("Parsed: {$parseResult['imported']} imported, {$parseResult['errors']} errors", "INFO");
        
        // Auto-assign shipments and products
        $assignedShipments = autoAssignReceipts($db, $userId);
        $assignedProducts = autoAssignProducts($db, $userId);
        
        cronLog("Auto-assignment: {$assignedShipments} shipments, {$assignedProducts} products", "INFO");
        
        return [
            'success' => true,
            'imported' => $parseResult['imported'],
            'assigned_shipments' => $assignedShipments,
            'assigned_products' => $assignedProducts
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Parse TSV and save to database
 */
function parseLedgerTSV($db, $userId, $tsvContent) {
    $lines = explode("\n", trim($tsvContent));
    $headerLine = array_shift($lines);
    $header = str_getcsv($headerLine, "\t");
    
    // Normalize header
    $header = array_map(function($h) {
        return strtolower(trim($h, '"'));
    }, $header);
    
    $headerMap = array_flip($header);
    
    $stmt = $db->prepare("
        INSERT INTO shipments_trid 
        (user_id, date, fnsku, asin, msku, title, event_type, reference_id, 
         quantity, fulfillment_center, disposition, reason, country, 
         reconciled_qty, unreconciled_qty, datetime)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity),
            reconciled_qty = VALUES(reconciled_qty),
            unreconciled_qty = VALUES(unreconciled_qty),
            last_updated = CURRENT_TIMESTAMP
    ");
    
    $imported = 0;
    $errors = 0;
    
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        
        $row = str_getcsv($line, "\t");
        $row = array_map(function($val) { return trim($val, '"'); }, $row);
        
        // Parse dates
        $dateStr = $row[$headerMap['date'] ?? -1] ?? '';
        $datetimeStr = $row[$headerMap['date and time'] ?? -1] ?? '';
        
        $date = null;
        if (!empty($dateStr)) {
            $timestamp = strtotime($dateStr);
            $date = $timestamp ? date('Y-m-d', $timestamp) : null;
        }
        
        $datetime = null;
        if (!empty($datetimeStr)) {
            $timestamp = strtotime($datetimeStr);
            $datetime = $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
        }
        
        if (!$datetime && $date) {
            $datetime = $date . ' 00:00:00';
        }
        
        // Extract fields
        $fnsku = !empty($row[$headerMap['fnsku'] ?? -1] ?? '') ? $row[$headerMap['fnsku']] : null;
        $asin = !empty($row[$headerMap['asin'] ?? -1] ?? '') ? $row[$headerMap['asin']] : null;
        $msku = $row[$headerMap['msku'] ?? -1] ?? '';
        $title = $row[$headerMap['title'] ?? -1] ?? '';
        $eventType = $row[$headerMap['event type'] ?? -1] ?? '';
        $referenceId = !empty($row[$headerMap['reference id'] ?? -1] ?? '') ? $row[$headerMap['reference id']] : null;
        $quantity = (int)($row[$headerMap['quantity'] ?? -1] ?? 0);
        $fc = !empty($row[$headerMap['fulfillment center'] ?? -1] ?? '') ? $row[$headerMap['fulfillment center']] : null;
        $disposition = !empty($row[$headerMap['disposition'] ?? -1] ?? '') ? $row[$headerMap['disposition']] : null;
        $reason = !empty($row[$headerMap['reason'] ?? -1] ?? '') ? $row[$headerMap['reason']] : null;
        $country = !empty($row[$headerMap['country'] ?? -1] ?? '') ? $row[$headerMap['country']] : null;
        $reconciledQty = !empty($row[$headerMap['reconciled quantity'] ?? -1] ?? '') ? $row[$headerMap['reconciled quantity']] : null;
        $unreconciledQty = !empty($row[$headerMap['unreconciled quantity'] ?? -1] ?? '') ? $row[$headerMap['unreconciled quantity']] : null;
        
        if (empty($msku) || empty($eventType) || empty($datetime)) {
            $errors++;
            continue;
        }
        
        try {
            $stmt->execute([
                $userId, $date, $fnsku, $asin, $msku, $title, $eventType, $referenceId,
                $quantity, $fc, $disposition, $reason, $country,
                $reconciledQty, $unreconciledQty, $datetime
            ]);
            $imported++;
        } catch (PDOException $e) {
            $errors++;
        }
    }
    
    return ['imported' => $imported, 'errors' => $errors];
}

/**
 * Auto-assign receipts to shipments
 */
function autoAssignReceipts($db, $userId) {
    $stmt = $db->prepare("
        UPDATE shipments_trid st
        INNER JOIN inbound_shipments ibs 
            ON st.reference_id = ibs.amazon_shipment_id
            AND st.user_id = ibs.user_id
        SET st.inbound_shipment_id = ibs.id
        WHERE st.user_id = ?
          AND st.event_type = 'Receipts' 
          AND st.inbound_shipment_id IS NULL
          AND st.reference_id IS NOT NULL
    ");
    
    $stmt->execute([$userId]);
    return $stmt->rowCount();
}

/**
 * Auto-assign products (multi-step)
 */
function autoAssignProducts($db, $userId) {
    $assigned = 0;
    
    // Step 1: inventory.sku
    $stmt1 = $db->prepare("
        UPDATE shipments_trid st
        INNER JOIN inventory inv 
            ON st.msku = inv.sku
            AND st.user_id = inv.user_id
        SET st.product_id = inv.product_id
        WHERE st.user_id = ?
          AND st.product_id IS NULL
          AND inv.product_id IS NOT NULL
    ");
    $stmt1->execute([$userId]);
    $assigned += $stmt1->rowCount();
    
    // Step 2: inventory_fbm.seller_sku
    try {
        $stmt2 = $db->prepare("
            UPDATE shipments_trid st
            INNER JOIN inventory_fbm ifbm
                ON st.msku = ifbm.seller_sku
                AND st.user_id = ifbm.user_id
            SET st.product_id = ifbm.product_id
            WHERE st.user_id = ?
              AND st.product_id IS NULL
              AND ifbm.product_id IS NOT NULL
        ");
        $stmt2->execute([$userId]);
        $assigned += $stmt2->rowCount();
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Step 3: inbound_shipment_items.seller_sku
    try {
        $stmt3 = $db->prepare("
            UPDATE shipments_trid st
            INNER JOIN inbound_shipment_items isi
                ON st.msku = isi.seller_sku
                AND st.user_id = isi.user_id
            SET st.product_id = isi.product_id
            WHERE st.user_id = ?
              AND st.product_id IS NULL
              AND isi.product_id IS NOT NULL
        ");
        $stmt3->execute([$userId]);
        $assigned += $stmt3->rowCount();
    } catch (PDOException $e) {
        // Error
    }
    
    // Step 4: mapping_states (fallback)
    try {
        $stmt4 = $db->prepare("
            UPDATE shipments_trid st
            INNER JOIN mapping_states ms
                ON st.msku = ms.sku
            INNER JOIN products p
                ON ms.product_id = p.id
                AND p.user_id = st.user_id
            SET st.product_id = ms.product_id
            WHERE st.user_id = ?
              AND st.product_id IS NULL
        ");
        $stmt4->execute([$userId]);
        $assigned += $stmt4->rowCount();
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    return $assigned;
}

/**
 * Load credentials for user
 */
function loadCredentialsForUser($db, $userId) {
    $stmt = $db->query("
        SELECT aws_access_key_id, aws_secret_access_key, aws_region, 
               spapi_client_id, spapi_client_secret
        FROM amazon_credentials 
        WHERE is_active = 1 
        LIMIT 1
    ");
    $global = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$global) throw new Exception('Credenziali globali non trovate');
    
    $stmt = $db->prepare("
        SELECT refresh_token, marketplace_id, seller_id
        FROM amazon_client_tokens 
        WHERE user_id = ? AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) throw new Exception('Token utente non trovato');
    
    return [
        'aws_access_key_id' => $global['aws_access_key_id'],
        'aws_secret_access_key' => $global['aws_secret_access_key'],
        'region' => $global['aws_region'] ?? 'eu-west-1',
        'client_id' => $global['spapi_client_id'],
        'client_secret' => $global['spapi_client_secret'],
        'refresh_token' => $user['refresh_token'],
        'marketplace_id' => $user['marketplace_id'],
        'seller_id' => $user['seller_id']
    ];
}

/**
 * Get OAuth access token
 */
function getAccessToken($creds) {
    $ch = curl_init('https://api.amazon.com/auth/o2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'refresh_token' => $creds['refresh_token']
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) throw new Exception("Token error: HTTP {$httpCode}");
    
    $data = json_decode($response, true);
    return $data['access_token'] ?? throw new Exception('Access token mancante');
}

/**
 * Request ledger report
 */
function requestLedgerReport($accessToken, $creds, $startDate, $endDate) {
    $startDateTime = $startDate . 'T00:00:00Z';
    $endDateTime = $endDate . 'T23:59:59Z';
    
    $body = json_encode([
        'reportType' => 'GET_LEDGER_DETAIL_VIEW_DATA',
        'marketplaceIds' => [$creds['marketplace_id']],
        'dataStartTime' => $startDateTime,
        'dataEndTime' => $endDateTime
    ]);
    
    $result = callSpApi('POST', '/reports/2021-06-30/reports', [], $body, $accessToken, $creds);
    return $result['reportId'] ?? throw new Exception('Report ID non ricevuto');
}

/**
 * Check report status
 */
function checkReportStatus($reportId, $accessToken, $creds) {
    $result = callSpApi('GET', "/reports/2021-06-30/reports/{$reportId}", [], '', $accessToken, $creds);
    return [
        'status' => $result['processingStatus'] ?? 'UNKNOWN',
        'documentId' => $result['reportDocumentId'] ?? null
    ];
}

/**
 * Download report document
 */
function downloadReportDocument($documentId, $accessToken, $creds) {
    $docInfo = callSpApi('GET', "/reports/2021-06-30/documents/{$documentId}", [], '', $accessToken, $creds);
    $downloadUrl = $docInfo['url'] ?? throw new Exception('URL mancante');
    
    $ch = curl_init($downloadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FOLLOWLOCATION => true
    ]);
    
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || empty($content)) {
        throw new Exception("Download fallito: HTTP {$httpCode}");
    }
    
    // Decompress GZIP if needed
    if (isset($docInfo['compressionAlgorithm']) && $docInfo['compressionAlgorithm'] === 'GZIP') {
        $content = gzdecode($content);
    }
    
    return $content;
}

/**
 * Call Amazon SP-API with AWS Signature V4
 */
function callSpApi($method, $path, $params, $body, $accessToken, $creds) {
    $baseUrl = 'https://sellingpartnerapi-eu.amazon.com';
    $region = 'eu-west-1';
    $service = 'execute-api';
    
    $queryString = '';
    if (!empty($params)) {
        ksort($params);
        $queryString = http_build_query($params);
    }
    
    $url = $baseUrl . $path;
    if ($queryString) $url .= '?' . $queryString;
    
    // AWS Signature V4
    $timestamp = gmdate('Ymd\THis\Z');
    $date = substr($timestamp, 0, 8);
    
    $canonicalHeaders = "host:sellingpartnerapi-eu.amazon.com\n";
    $canonicalHeaders .= "x-amz-access-token:{$accessToken}\n";
    $canonicalHeaders .= "x-amz-date:{$timestamp}\n";
    
    $signedHeaders = 'host;x-amz-access-token;x-amz-date';
    $payloadHash = hash('sha256', $body);
    
    $canonicalRequest = "{$method}\n{$path}\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    
    $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
    
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $creds['aws_secret_access_key'], true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    
    $authorization = "AWS4-HMAC-SHA256 Credential={$creds['aws_access_key_id']}/{$credentialScope}, ";
    $authorization .= "SignedHeaders={$signedHeaders}, Signature={$signature}";
    
    $headers = [
        "Authorization: {$authorization}",
        "x-amz-date: {$timestamp}",
        "x-amz-access-token: {$accessToken}",
        "host: sellingpartnerapi-eu.amazon.com",
        "Content-Type: application/json"
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);
    
    if (!empty($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode >= 400) {
        throw new Exception("SP-API Error: HTTP {$httpCode} - {$response}");
    }
    
    return json_decode($response, true);
}

