<?php

/**
 * Helper Functions per Sincronizzazione Amazon SP-API
 * File: sincro/sync_helpers.php
 * 
 * Contiene funzioni di utilità per la sincronizzazione
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/api_config.php';

/**
 * Verifica se l'utente ha una sincronizzazione Amazon attiva
 */
function hasActiveAmazonSync($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM amazon_credentials 
            WHERE user_id = ? 
            AND status = ? 
            AND expires_at > NOW()
        ");
        
        $stmt->execute([$userId, SYNC_STATUS_ACTIVE]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
        
    } catch (PDOException $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore verifica sincronizzazione attiva: %s', $e->getMessage()));
        return false;
    }
}

/**
 * Verifica se l'utente ha completato il primo import
 */
function hasCompletedFirstImport($userId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT first_import_ok 
            FROM users 
            WHERE id = ?
        ");
        
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result && $result['first_import_ok'] == 1;
        
    } catch (PDOException $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore verifica primo import: %s', $e->getMessage()));
        return false;
    }
}

/**
 * Log operazioni di sincronizzazione - Sostituito da CentralLogger
 * Mantenuto per retrocompatibilità
 */
function logSyncOperation($userId, $step, $status, $message, $contextJson = null) {
    // Determina il modulo dal tipo di operazione
    $module = 'settlement'; // Default
    
    if (strpos($step, 'inventory') !== false) {
        $module = 'inventory';
    } elseif (strpos($step, 'oauth') !== false) {
        $module = 'oauth';
    } elseif (strpos($step, 'mapping') !== false) {
        $module = 'mapping';
    } elseif (strpos($step, 'historical') !== false) {
        $module = 'historical';
    } elseif (strpos($step, 'ai') !== false) {
        $module = 'ai';
    }
    
    $context = $contextJson ? (is_array($contextJson) ? $contextJson : ['context' => $contextJson]) : [];
    $context['user_id'] = $userId;
    $context['operation_type'] = $step;
    
    CentralLogger::log($module, $status, $message, $context);
    return true;
}

/**
 * Funzione obsoleta - tabella gestita automaticamente
 */
function createSyncDebugLogsTable($pdo) {
    // Non più necessaria - CentralLogger gestisce tutto automaticamente
    return true;
}

/**
 * Log Settlement - Utilizza CentralLogger
 */
function logSettlement($message, $level = 'INFO', $context = []) {
    CentralLogger::log('settlement', $level, $message, $context);
    
    // Mantieni output CLI per retrocompatibilità
    if (php_sapi_name() === 'cli') {
        echo "[" . date('H:i:s') . "] [SETTLEMENT] [{$level}] {$message}" . PHP_EOL;
    }
}

/**
 * Log su file - Obsoleto, sostituito da CentralLogger
 * Mantenuto per retrocompatibilità, redirige a CentralLogger
 */
function logToFile($userId, $step, $status, $message, $contextJson = null) {
    $context = $contextJson ? (is_array($contextJson) ? $contextJson : ['context' => $contextJson]) : [];
    $context['user_id'] = $userId;
    $context['step'] = $step;
    
    CentralLogger::log('settlement', $status, $message, $context);
    return true;
}

/**
 * Ottieni credenziali Amazon per l'utente
 */
function getAmazonCredentials($userId, $marketplaceId = 'APJ6JRA9NG5V4') {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT refresh_token, marketplace_id 
            FROM amazon_client_tokens 
            WHERE user_id = ? AND is_active = 1
            ORDER BY updated_at DESC 
            LIMIT 1
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore recupero credenziali Amazon: %s', $e->getMessage()));
        return false;
    }
}

/**
 * Verifica se il token è in scadenza
 */
function isTokenExpiring($expiresAt, $thresholdSeconds = AMAZON_TOKEN_REFRESH_THRESHOLD) {
    try {
        $expiryTime = strtotime($expiresAt);
        $currentTime = time();
        $timeUntilExpiry = $expiryTime - $currentTime;
        
        return $timeUntilExpiry <= $thresholdSeconds;
        
    } catch (Exception $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore verifica scadenza token: %s', $e->getMessage()));
        return true; // Considera scaduto in caso di errore
    }
}

/**
 * Genera hash univoco per riga di dati (per evitare duplicati)
 */
function generateUniqueRowHash($data) {
    // Rimuovi campi che possono variare tra import
    $excludeFields = ['imported_at', 'id'];
    $hashData = [];
    
    foreach ($data as $key => $value) {
        if (!in_array($key, $excludeFields)) {
            $hashData[$key] = $value;
        }
    }
    
    // Ordina per consistenza
    ksort($hashData);
    
    return hash('sha256', json_encode($hashData));
}

/**
 * Formatta importo per database (gestisce valute diverse)
 */
function formatAmountForDb($amount, $currency = 'EUR') {
    try {
        // Rimuovi caratteri non numerici eccetto punto e virgola
        $cleanAmount = preg_replace('/[^\d.,-]/', '', $amount);
        
        // Gestisci separatori decimali diversi
        if (strpos($cleanAmount, ',') !== false && strpos($cleanAmount, '.') !== false) {
            // Formato tipo 1.234,56
            $cleanAmount = str_replace('.', '', $cleanAmount);
            $cleanAmount = str_replace(',', '.', $cleanAmount);
        } elseif (strpos($cleanAmount, ',') !== false) {
            // Formato tipo 1234,56
            $cleanAmount = str_replace(',', '.', $cleanAmount);
        }
        
        return floatval($cleanAmount);
        
    } catch (Exception $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore formattazione importo: %s', $e->getMessage()));
        return 0.0;
    }
}

/**
 * Formatta data per database
 */
function formatDateForDb($dateString) {
    try {
        if (empty($dateString)) {
            return null;
        }
        
        // Prova diversi formati di data
        $formats = [
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:s.u\Z',
            'Y-m-d',
            'd/m/Y H:i:s',
            'd/m/Y',
            'm/d/Y H:i:s',
            'm/d/Y'
        ];
        
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $dateString);
            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        
        // Fallback: prova strtotime
        $timestamp = strtotime($dateString);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }
        
        return null;
        
    } catch (Exception $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore formattazione data: %s', $e->getMessage()));
        return null;
    }
}

/**
 * Pulisce e valida SKU
 */
function cleanSku($sku) {
    if (empty($sku)) {
        return null;
    }
    
    // Rimuovi spazi e caratteri speciali
    $cleanSku = trim($sku);
    $cleanSku = preg_replace('/[^\w\-._]/', '', $cleanSku);
    
    return !empty($cleanSku) ? $cleanSku : null;
}

/**
 * Ottieni statistiche di sincronizzazione per l'utente
 */
function getSyncStats($userId) {
    try {
        $pdo = getDbConnection();
        $stats = [];
        
        // Statistiche credenziali
        $stmt = $pdo->prepare("
            SELECT status, expires_at, updated_at, marketplace
            FROM amazon_credentials 
            WHERE user_id = ? 
            ORDER BY updated_at DESC
        ");
        $stmt->execute([$userId]);
        $stats['credentials'] = $stmt->fetchAll();
        
        // Statistiche log
        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count, MAX(created_at) as last_log
            FROM sync_debug_logs 
            WHERE user_id = ?
            GROUP BY status
        ");
        $stmt->execute([$userId]);
        $stats['logs'] = $stmt->fetchAll();
        
        // Statistiche tabella dinamica (se esiste)
        $tableName = SYNC_TABLE_PREFIX . $userId;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total_records,
                       COUNT(DISTINCT settlement_id) as settlements,
                       MIN(posted_date) as first_date,
                       MAX(posted_date) as last_date
                FROM `{$tableName}`
            ");
            $stmt->execute();
            $stats['records'] = $stmt->fetch();
        } catch (PDOException $e) {
            $stats['records'] = ['total_records' => 0, 'settlements' => 0];
        }
        
        return $stats;
        
    } catch (PDOException $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore recupero statistiche sync: %s', $e->getMessage()));
        return [];
    }
}

/**
 * Verifica se una tabella esiste nel database
 */
function tableExists($tableName) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = ?
        ");
        
        $stmt->execute([$tableName]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
        
    } catch (PDOException $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore verifica esistenza tabella: %s', $e->getMessage()));
        return false;
    }
}

/**
 * Sanitizza nome file per sicurezza
 */
function sanitizeFilename($filename) {
    // Rimuovi caratteri pericolosi
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    
    // Limita lunghezza
    if (strlen($filename) > 100) {
        $filename = substr($filename, 0, 100);
    }
    
    return $filename;
}

/**
 * Popola coda con tutti i report disponibili per utente
 */
function popolaCodaReport($userId) {
    echo "[DEBUG] Inizio popolaCodaReport per user: $userId<br>";
    
    try {
        $credentials = getAmazonCredentials($userId);
        echo "[DEBUG] Credenziali ottenute: " . ($credentials ? 'SI' : 'NO') . "<br>";
        
        if (!$credentials) {
            echo "[DEBUG] ERRORE: Credenziali mancanti<br>";
            return false;
        }
        
        echo "[DEBUG] Refresh token presente: " . (isset($credentials['refresh_token']) ? 'SI' : 'NO') . "<br>";
        echo "[DEBUG] Marketplace: " . $credentials['marketplace_id'] . "<br>";
        
        echo "[DEBUG] Tentativo getAccessToken...<br>";
        $accessToken = getAccessToken(
            AMAZON_CLIENT_ID,
            AMAZON_CLIENT_SECRET,
            $credentials['refresh_token']
        );
        echo "[DEBUG] Access token ottenuto: " . (strlen($accessToken) > 10 ? 'SI' : 'NO') . "<br>";
        
        $createdSince = date('c', strtotime('-89 days')); // Amazon max 90 giorni
        echo "[DEBUG] Created since: $createdSince<br>";
        
        $url = 'https://sellingpartnerapi-eu.amazon.com/reports/2021-06-30/reports'
             . '?reportTypes=GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE'
             . '&processingStatuses=DONE'
             . '&createdSince=' . urlencode($createdSince)
             . '&pageSize=99'; // Limitiamo a 10 per test
        
        echo "[DEBUG] URL chiamata: $url<br>";
        
        echo "[DEBUG] Tentativo signedRequest...<br>";
        $response = signedRequest('GET', $url, $accessToken, AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY);
        echo "[DEBUG] Risposta ottenuta, lunghezza: " . strlen($response) . "<br>";
        
        $data = json_decode($response, true);
        echo "[DEBUG] JSON decodificato: " . ($data ? 'SI' : 'NO') . "<br>";
        
        if (isset($data['reports'])) {
    echo "[DEBUG] Reports trovati: " . count($data['reports']) . "<br>";
    
    $totalAdded = 0;
    foreach ($data['reports'] as $report) {
        $added = aggiungiReportAllaCoda($userId, $report);
        if ($added) $totalAdded++;
        echo "[DEBUG] Report {$report['reportId']} aggiunto: " . ($added ? 'SI' : 'NO') . "<br>";
    }
    
    return $totalAdded;
} else {
            echo "[DEBUG] ERRORE: Nessun campo reports nella risposta<br>";
            echo "[DEBUG] Risposta completa: " . substr($response, 0, 500) . "<br>";
            return false;
        }
        
    } catch (Exception $e) {
        echo "[DEBUG] ECCEZIONE: " . $e->getMessage() . "<br>";
        echo "[DEBUG] Stack trace: " . $e->getTraceAsString() . "<br>";
        return false;
    }
}

/**
 * Aggiunge singolo report alla coda se non presente
 */
function aggiungiReportAllaCoda($userId, $reportData) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO settlement_report_queue 
            (user_id, report_id, report_start_date, report_end_date, marketplace_id) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $userId,
            $reportData['reportId'],
            $reportData['dataStartTime'] ?? null,
            $reportData['dataEndTime'] ?? null,
            $reportData['marketplaceIds'][0] ?? 'APJ6JRA9NG5V4'
        ]);
        
    } catch (Exception $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore aggiunta report coda: %s', $e->getMessage()));
        return false;
    }
}

/**
 * Processa un report dalla coda
 */
function processaUnReportDallaCoda() {
    try {
        $pdo = getDbConnection();
        
        // Prendi primo report pending
        $stmt = $pdo->prepare("
            SELECT * FROM settlement_report_queue 
            WHERE status = 'pending' AND tentativi < 5 
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt->execute();
        $report = $stmt->fetch();
        
        if (!$report) return false;
        
        // Marca come in elaborazione
        $updateStmt = $pdo->prepare("
            UPDATE settlement_report_queue 
            SET status = 'processing', updated_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$report['id']]);
        
        // Scarica report
        $result = scaricaReportDallaCoda($report);
        
        // Aggiorna stato
        if ($result['success']) {
            $pdo->prepare("
                UPDATE settlement_report_queue 
                SET status = 'completed', file_path = ?, updated_at = NOW() 
                WHERE id = ?
            ")->execute([$result['file_path'], $report['id']]);
        } else {
            $pdo->prepare("
                UPDATE settlement_report_queue 
                SET status = 'pending', tentativi = tentativi + 1, 
                    last_error = ?, updated_at = NOW() 
                WHERE id = ?
            ")->execute([$result['error'], $report['id']]);
        }
        
        return $result;
        
    } catch (Exception $e) {
        CentralLogger::log('sync', 'ERROR', 
            sprintf('Errore processamento coda: %s', $e->getMessage()));
        return false;
    }
}

/**
 * Converte dimensione file in formato leggibile
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Verifica se l'ambiente è in modalità debug
 */
function isDebugMode() {
    return defined('DEBUG_MODE') && DEBUG_MODE === true;
}

/**
 * Log condizionale (solo in debug mode)
 */
function debugLog($message, $context = null) {
    if (isDebugMode()) {
        CentralLogger::log('sync', 'DEBUG', $message, $context ?: []);
    }
}

/**
 * Ottieni configurazione marketplace
 */
function getMarketplaceConfig($marketplaceId = AMAZON_MARKETPLACE_ID) {
    $marketplaces = [
        AMAZON_MARKETPLACE_ID => ['name' => 'Germany', 'currency' => 'EUR', 'region' => 'eu-west-1'],
        AMAZON_MARKETPLACE_ID_IT => ['name' => 'Italy', 'currency' => 'EUR', 'region' => 'eu-west-1'],
        AMAZON_MARKETPLACE_ID_FR => ['name' => 'France', 'currency' => 'EUR', 'region' => 'eu-west-1'],
        AMAZON_MARKETPLACE_ID_ES => ['name' => 'Spain', 'currency' => 'EUR', 'region' => 'eu-west-1'],
        AMAZON_MARKETPLACE_ID_UK => ['name' => 'United Kingdom', 'currency' => 'GBP', 'region' => 'eu-west-1']
    ];
    
    return $marketplaces[$marketplaceId] ?? $marketplaces[AMAZON_MARKETPLACE_ID];
}

/**
 * Ottieni access token da refresh token
 */
function getAccessToken($clientId, $clientSecret, $refreshToken) {
    $post = http_build_query([
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret
    ]);

    $ch = curl_init('https://api.amazon.com/auth/o2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200) {
        throw new Exception("LWA token error (HTTP $http)\n$res\n");
    }
    return json_decode($res, true)['access_token'];
}

function signedRequest(
   string $method,
   string $url,
   string $accessToken,
   string $awsKey,
   string $awsSecret,
   string $region  = 'eu-west-1',
   string $service = 'execute-api',
   string $payload = ''
): string
{
   $u      = parse_url($url);
   $host   = $u['host'];
   $uri    = $u['path'] ?? '/';
   $qRaw   = $u['query'] ?? '';
   $query  = canonicalQuery($qRaw);

   /* ---------- HEADERS BASE ---------- */
   $headers = [
       'host'               => $host,
       'x-amz-access-token' => $accessToken,
       'content-type'       => 'application/json'
   ];
   if ($payload !== '') {
       $headers['content-length'] = strlen($payload);
   }

   /* ---------- x-amz-date ---------- */
   $amzDate  = gmdate('Ymd\THis\Z');
   $dateOnly = gmdate('Ymd');
   $headers['x-amz-date'] = $amzDate;

   /* ---------- CANONICAL HEADERS ---------- */
   ksort($headers);
   $canonicalHeaders = '';
   $signedHeaders    = '';
   foreach ($headers as $k => $v) {
       $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
       $signedHeaders    .= strtolower($k) . ';';
   }
   $signedHeaders = rtrim($signedHeaders, ';');

   /* ---------- HASHES ---------- */
   $payloadHash = hash('sha256', $payload);
   $canonicalRequest = implode("\n", [
       $method,
       $uri,
       $query,
       $canonicalHeaders,
       $signedHeaders,
       $payloadHash
   ]);

   $scope      = "$dateOnly/$region/$service/aws4_request";
   $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);

   /* ---------- SIGNATURE ---------- */
   $kDate    = hash_hmac('sha256', $dateOnly,  'AWS4' . $awsSecret, true);
   $kRegion  = hash_hmac('sha256', $region,    $kDate,   true);
   $kService = hash_hmac('sha256', $service,   $kRegion, true);
   $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
   $signature = hash_hmac('sha256', $stringToSign, $kSigning);

   $auth = 'AWS4-HMAC-SHA256 '
         . "Credential=$awsKey/$scope, "
         . "SignedHeaders=$signedHeaders, "
         . "Signature=$signature";

   /* ---------- CURL CALL ---------- */
   $curlHeaders = [];
   foreach ($headers as $k => $v) {
       $curlHeaders[] = "$k: $v";
   }
   $curlHeaders[] = "Authorization: $auth";

   $ch = curl_init($url);
   curl_setopt_array($ch, [
       CURLOPT_CUSTOMREQUEST  => $method,
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_HTTPHEADER     => $curlHeaders,
       CURLOPT_TIMEOUT        => 30
   ]);
   if ($payload !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

   $res  = curl_exec($ch);
   $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);

   if ($http < 200 || $http >= 300) {
       throw new Exception("API error HTTP $http\n$res\n");
   }
   return $res;
}

function canonicalQuery(string $raw): string
{
   if ($raw === '') return '';
   parse_str($raw, $params);
   ksort($params);
   $pairs = [];
   foreach ($params as $k => $v) {
       $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
   }
   return implode('&', $pairs);
}

?>