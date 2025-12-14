<?php
/**
 * Margynomic - Sistema Inventory Sync con Queue Management
 * File: modules/previsync/inventory_sync.php
 * 
 * SISTEMA OTTIMIZZATO CON:
 * 1. Endpoint dinamici per regione Amazon
 * 2. Signature AWS V4 completa
 * 3. Gestione FATAL con logging dettagliato
 * 4. Selezione automatica report type
 * 5. Integrazione con inventory_report_queue
 */

// Disabilita output errori per evitare contaminazione JSON
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once '../margynomic/config/config.php';
require_once '../margynomic/login/auth_helpers.php';
require_once '../margynomic/sincro/api_config.php';
require_once '../margynomic/sincro/sync_helpers.php';
// Aumenta timeout PHP per dual-sync
set_time_limit(600); // 10 minuti
ini_set('max_execution_time', 600);

// Headers JSON per AJAX
header('Content-Type: application/json');

// Gestione errori FATAL (UNICA)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("INVENTORY_SYNC FATAL: " . $error['message'] . " in " . $error['file'] . ":" . $error['line']);
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
        }
    }
});

// Verifica autenticazione (con bypass per cron)
if (isset($_POST['cron_user_id']) && isset($_POST['cron_key']) && $_POST['cron_key'] === 'margynomic_secure_2025') {
    // Chiamata da cron autenticata
    $userId = intval($_POST['cron_user_id']);
} else {
    // Chiamata normale - richiede login
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
        exit;
    }
    
    try {
        $currentUser = getCurrentUser();
        $userId = $currentUser['id'];
} catch (Exception $e) {
    error_log("INVENTORY_SYNC ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Errore utente: ' . $e->getMessage()]);
    exit;
}
}

class InventorySync {
    private $db;
    private $userId;
    private $downloadDir;
    private $reportType;
    private $strategyManager;
    
    // Mapping endpoint per regione Amazon
    private $endpoints = [
        // Nord America
        'ATVPDKIKX0DER' => ['url' => 'https://sellingpartnerapi-na.amazon.com', 'region' => 'us-east-1'], // US
        'A2EUQ1WTGCTBG2' => ['url' => 'https://sellingpartnerapi-na.amazon.com', 'region' => 'us-east-1'], // CA
        'A1AM78C64UM0Y8' => ['url' => 'https://sellingpartnerapi-na.amazon.com', 'region' => 'us-east-1'], // MX
        
        // Europa
        'A1PA6795UKMFR9' => ['url' => 'https://sellingpartnerapi-eu.amazon.com', 'region' => 'eu-west-1'], // DE
        'APJ6JRA9NG5V4' => ['url' => 'https://sellingpartnerapi-eu.amazon.com', 'region' => 'eu-west-1'], // IT
        'A13V1IB3VIYZZH' => ['url' => 'https://sellingpartnerapi-eu.amazon.com', 'region' => 'eu-west-1'], // FR
        'A1RKKUPIHCS9HS' => ['url' => 'https://sellingpartnerapi-eu.amazon.com', 'region' => 'eu-west-1'], // ES
        'A1F83G8C2ARO7P' => ['url' => 'https://sellingpartnerapi-eu.amazon.com', 'region' => 'eu-west-1'], // UK
        'A1805IZSGTT6HS' => ['url' => 'https://sellingpartnerapi-eu.amazon.com', 'region' => 'eu-west-1'], // NL
        
        // Far East
        'A19VAU5U5O7RUS' => ['url' => 'https://sellingpartnerapi-fe.amazon.com', 'region' => 'us-west-2'], // JP
        'AAHKV2X7AFYLW' => ['url' => 'https://sellingpartnerapi-fe.amazon.com', 'region' => 'us-west-2'], // SG
        'A39IBJ37TRP1C6' => ['url' => 'https://sellingpartnerapi-fe.amazon.com', 'region' => 'us-west-2'], // AU
    ];
    
    public function __construct($userId) {
        $this->db = getDbConnection();
        $this->userId = $userId;
        $this->downloadDir = dirname(__DIR__) . "/downloads/user_{$userId}";
        $this->ensureDirectoryExists();
        $this->ensureQueueTableExists();
        
        // Carica Strategy Manager per gestione last_charge
        require_once __DIR__ . '/inventory_strategy_manager.php';
        $this->strategyManager = new InventoryStrategyManager($this->db, $userId);
    }
    
    /**
     * Assicura che le directory esistano
     */
    private function ensureDirectoryExists() {
        if (!is_dir($this->downloadDir)) {
            mkdir($this->downloadDir, 0755, true);
        }
    }
    
    /**
     * Crea tabella queue se non esiste
     */
    private function ensureQueueTableExists() {
        $sql = "CREATE TABLE IF NOT EXISTS inventory_report_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            marketplace_id VARCHAR(20) NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            attempt_count INT DEFAULT 0,
            last_attempt_at DATETIME NULL,
            next_retry_at DATETIME NULL,
            error_message TEXT NULL,
            report_id VARCHAR(50) NULL,
            file_path TEXT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_user (user_id),
            INDEX idx_status (status),
            INDEX idx_next_retry (next_retry_at),
            INDEX idx_marketplace (marketplace_id)
        )";
        
        $this->db->exec($sql);
    }
    
    /**
     * Aggiorna stato utente nella coda
     */
    private function updateQueueStatus($status, $errorMessage = null, $reportId = null, $filePath = null) {
        $sql = "INSERT INTO inventory_report_queue 
                (user_id, marketplace_id, status, attempt_count, last_attempt_at, next_retry_at, error_message, report_id, file_path, completed_at) 
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status),
                attempt_count = attempt_count + 1,
                last_attempt_at = NOW(),
                next_retry_at = VALUES(next_retry_at),
                error_message = VALUES(error_message),
                report_id = VALUES(report_id),
                file_path = VALUES(file_path),
                completed_at = VALUES(completed_at),
                updated_at = NOW()";
        
        $credentials = $this->getAmazonCredentials();
        $marketplaceId = $credentials['marketplace_id'];
        
        $nextRetry = null;
        $completedAt = null;
        $attemptCount = 1;
        
        if ($status === 'failed') {
            $nextRetry = date('Y-m-d H:i:s', strtotime('+35 minutes'));
        } elseif ($status === 'completed') {
            $completedAt = date('Y-m-d H:i:s');
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $this->userId,
            $marketplaceId,
            $status,
            $attemptCount,
            $nextRetry,
            $errorMessage,
            $reportId,
            $filePath,
            $completedAt
        ]);
    }
    
    /**
     * Ottieni endpoint e regione per marketplace
     */
    private function getEndpointForMarketplace($marketplaceId) {
        if (!isset($this->endpoints[$marketplaceId])) {
            throw new Exception("Marketplace non supportato: $marketplaceId");
        }
        return $this->endpoints[$marketplaceId];
    }
    
/**
     * Sincronizza entrambi i tipi di inventario (FBA + FBM)
     */
    public function syncBothInventoryTypes() {
        $results = [
            'fba' => ['success' => false, 'message' => ''],
            'fbm' => ['success' => false, 'message' => ''],
            'overall_success' => false
        ];
        
        // 1. Sincronizza FBA
        try {
            $this->reportType = 'GET_FBA_MYI_ALL_INVENTORY_DATA';
            $fbaResult = $this->executeSingleReportSync();
            $results['fba'] = $fbaResult;
            
            // Sync FBA - già tracciato da inventory_file_processed
        } catch (Exception $e) {
            // Gestione speciale per "nessun inventario"
            if ($e->getCode() === 1001) {
                $results['fba'] = ['success' => true, 'error' => $e->getMessage(), 'processed_rows' => 0, 'is_empty' => true];
                logSyncOperation($this->userId, 'inventory_fba_empty', 'info', 
                    'FBA vuoto: ' . $e->getMessage());
            } else {
                $results['fba'] = ['success' => false, 'error' => $e->getMessage()];
                logSyncOperation($this->userId, 'inventory_fba_error', 'warning', 
                    'Errore sync FBA: ' . $e->getMessage());
            }
        }
        
        // 2. Sincronizza FBM
        try {
            $this->reportType = 'GET_MERCHANT_LISTINGS_ALL_DATA';
            $fbmResult = $this->executeSingleReportSync();
            $results['fbm'] = $fbmResult;
            
        } catch (Exception $e) {
            // Gestione speciale per "nessun inventario"
            if ($e->getCode() === 1001) {
                $results['fbm'] = ['success' => true, 'error' => $e->getMessage(), 'processed_rows' => 0, 'is_empty' => true];
                logSyncOperation($this->userId, 'inventory_fbm_empty', 'info', 
                    'FBM vuoto: ' . $e->getMessage());
            } else {
                $results['fbm'] = ['success' => false, 'error' => $e->getMessage()];
                logSyncOperation($this->userId, 'inventory_fbm_error', 'warning', 
                    'Errore sync FBM: ' . $e->getMessage());
            }
        }
        
        // 3. Valuta successo complessivo
        $results['overall_success'] = $results['fba']['success'] || $results['fbm']['success'];
        
        if ($results['overall_success']) {
            $successMsg = [];
            if ($results['fba']['success']) $successMsg[] = 'FBA';
            if ($results['fbm']['success']) $successMsg[] = 'FBM';
            $results['message'] = 'Sincronizzazione completata: ' . implode(' + ', $successMsg);
        } else {
            $results['message'] = 'Entrambe le sincronizzazioni sono fallite';
        }
        
        return $results;
    }


    /**
     * Ottieni credenziali Amazon dal database
     */
    private function getAmazonCredentials() {
        // Credenziali globali del sistema
        $stmt = $this->db->prepare("SELECT aws_access_key_id, aws_secret_access_key, aws_region, spapi_client_id, spapi_client_secret FROM amazon_credentials WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $awsCreds = $stmt->fetch();
        
        if (!$awsCreds) {
            throw new Exception("Credenziali Amazon non trovate nel database");
        }
        
        // Token specifico utente
        $stmt = $this->db->prepare("SELECT refresh_token, marketplace_id FROM amazon_client_tokens WHERE user_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$this->userId]);
        $userToken = $stmt->fetch();
        
        if (!$userToken) {
            throw new Exception("Token utente non trovato. Completa prima l'autorizzazione Amazon.");
        }
        
        return array_merge($awsCreds, $userToken);
    }
    
    /**
     * Ottieni access token usando refresh token
     */
    private function getAccessToken($credentials) {
        $ch = curl_init('https://api.amazon.com/auth/o2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'refresh_token',
                'client_id'     => $credentials['spapi_client_id'],
                'client_secret' => $credentials['spapi_client_secret'],
                'refresh_token' => $credentials['refresh_token']
            ])
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("Errore ottenimento token (HTTP $httpCode): $response");
        }
        
        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new Exception("Access token non trovato nella risposta");
        }
        
        return $data['access_token'];
    }
    
    /**
     * Signature AWS V4 completa con tutti gli header
     */
    private function createAwsSignature($method, $url, $accessToken, $credentials, $body = '') {
        $endpoint = $this->getEndpointForMarketplace($credentials['marketplace_id']);
        $region = $endpoint['region'];
        
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'];
        $path = $parsedUrl['path'];
        $query = $parsedUrl['query'] ?? '';
        
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        
        // Canonical headers - INCLUDERE x-amz-access-token nella firma
        $canonicalHeaders = "host:$host\n";
        $canonicalHeaders .= "x-amz-access-token:$accessToken\n";
        $canonicalHeaders .= "x-amz-date:$timestamp\n";
        
        $signedHeaders = 'host;x-amz-access-token;x-amz-date';
        
        // Hash del payload
        $payloadHash = hash('sha256', $body);
        
        // Canonical request
        $canonicalRequest = "$method\n$path\n$query\n$canonicalHeaders\n$signedHeaders\n$payloadHash";
        
        // String to sign
        $credentialScope = "$date/$region/execute-api/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n$timestamp\n$credentialScope\n" . hash('sha256', $canonicalRequest);
        
        // Signature
        $signingKey = hash_hmac('sha256', 'aws4_request', 
            hash_hmac('sha256', 'execute-api', 
                hash_hmac('sha256', $region, 
                    hash_hmac('sha256', $date, 'AWS4' . $credentials['aws_secret_access_key'], true), true), true), true);
        
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        
        // Authorization header
        $authorization = "AWS4-HMAC-SHA256 Credential={$credentials['aws_access_key_id']}/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";
        
        return [
            'Authorization' => $authorization,
            'X-Amz-Date' => $timestamp,
            'X-Amz-Access-Token' => $accessToken
        ];
    }
    
    /**
     * Chiamata SP-API con signature corretta
     */
    private function spApiCall($method, $url, $accessToken, $credentials, $body = null) {
        $headers = $this->createAwsSignature($method, $url, $accessToken, $credentials, $body ?? '');
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $headers['Authorization'],
                'X-Amz-Date: ' . $headers['X-Amz-Date'],
                'X-Amz-Access-Token: ' . $headers['X-Amz-Access-Token'],
                'Content-Type: application/json'
            ]
        ]);
        
        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Accetta sia 200 (GET) che 202 (POST per creazione report)
        if ($httpCode !== 200 && $httpCode !== 202) {
            throw new Exception("SP-API Error (HTTP $httpCode): $response");
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Crea sempre un nuovo report (non riutilizza per evitare throttling)
     */
    private function createNewReport($accessToken, $credentials) {
        $endpoint = $this->getEndpointForMarketplace($credentials['marketplace_id']);
        $baseUrl = $endpoint['url'];
        
        // Il tipo di report è già impostato da syncBothInventoryTypes()
        $body = json_encode([
            'reportType' => $this->reportType,
            'marketplaceIds' => [$credentials['marketplace_id']]
        ]);
        
        $response = $this->spApiCall('POST', "$baseUrl/reports/2021-06-30/reports", $accessToken, $credentials, $body);
        
        return [
            'reportId' => $response['reportId'] ?? null,
            'processingStatus' => 'IN_PROGRESS',
            'reportDocumentId' => null
        ];
    }
    
/**
* Attendi completamento report con gestione FATAL dettagliata
*/
private function waitForReport($reportId, $accessToken, $credentials) {
   $endpoint = $this->getEndpointForMarketplace($credentials['marketplace_id']);
   $baseUrl = $endpoint['url'];
   
   $maxAttempts = 8; // 8 minuti max - report FBM spesso lenti
   $attempt = 0;
   
   // Polling report - log solo su cambio status
   
   do {
       sleep(60); // Polling ogni minuto
       $response = $this->spApiCall('GET', "$baseUrl/reports/2021-06-30/reports/$reportId", $accessToken, $credentials);
       $attempt++;
       
       // Log report status disabilitato - info già in sync_completed
       
       if ($response['processingStatus'] === 'DONE') {
           return [
               'reportId' => $reportId,
               'processingStatus' => 'DONE',
               'reportDocumentId' => $response['reportDocumentId'] ?? null
           ];
       }
       
       if ($response['processingStatus'] === 'CANCELLED' || $response['processingStatus'] === 'FATAL') {
           // Per report CANCELLED, spesso significa "nessun dato disponibile"
           if ($response['processingStatus'] === 'CANCELLED') {
               logSyncOperation($this->userId, 'inventory_report_no_data', 'info', 
                   "Report CANCELLED - probabilmente nessun inventario {$this->reportType} per questo utente");
               
               // Ritorna un errore "soft" che non blocca il dual-sync
               throw new Exception("Nessun inventario {$this->reportType} disponibile", 1001);
           }
           
           // Per FATAL, scarica dettagli errore
           $errorDetails = 'Nessun dettaglio disponibile';
           $reportDocumentId = $response['reportDocumentId'] ?? null;
           
           if ($reportDocumentId) {
               try {
                   $errorDocument = $this->downloadErrorReport($reportDocumentId, $accessToken, $credentials);
                   $errorDetails = $errorDocument;
                   
                   logSyncOperation($this->userId, 'inventory_report_error_details', 'error', 
                       "Dettagli errore report $reportId: $errorDetails");
                       
               } catch (Exception $e) {
                   logSyncOperation($this->userId, 'inventory_report_error_download_failed', 'warning', 
                       "Impossibile scaricare dettagli errore: " . $e->getMessage());
               }
           }
           
           throw new Exception("Report fallito: {$response['processingStatus']} - $errorDetails");
       }
       
   } while ($attempt < $maxAttempts);
   
   throw new Exception("Timeout: report non completato in " . ($maxAttempts * 60) . " secondi");
}

/**
* Scarica report document di errore per vedere la causa FATAL
*/
private function downloadErrorReport($documentId, $accessToken, $credentials) {
   $endpoint = $this->getEndpointForMarketplace($credentials['marketplace_id']);
   $baseUrl = $endpoint['url'];
   
   $docResponse = $this->spApiCall('GET', "$baseUrl/reports/2021-06-30/documents/$documentId", $accessToken, $credentials);
   
   if (!isset($docResponse['url'])) {
       return 'URL documento errore non trovato';
   }
   
   // Scarica il contenuto del documento di errore
   $errorContent = file_get_contents($docResponse['url']);
   
   if ($docResponse['compressionAlgorithm'] === 'GZIP') {
       $errorContent = gzdecode($errorContent);
   }
   
   return trim($errorContent);
}
    /**
     * Download file report
     */
    private function downloadReportFile($documentId, $accessToken, $credentials) {
        $endpoint = $this->getEndpointForMarketplace($credentials['marketplace_id']);
        $baseUrl = $endpoint['url'];
        
        $docResponse = $this->spApiCall('GET', "$baseUrl/reports/2021-06-30/documents/$documentId", $accessToken, $credentials);
        
        if (!isset($docResponse['url'])) {
            throw new Exception("URL download non trovato");
        }
        
        $downloadUrl = $docResponse['url'];
        $compression = $docResponse['compressionAlgorithm'] ?? 'NONE';
        
        // Directory specifica per il tipo di report
        $reportDir = $this->downloadDir . '/' . $this->reportType;
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
        }
        
        $fileName = 'inventory_' . date('Y-m-d_His') . '.tsv';
        $filePath = $reportDir . '/' . $fileName;
        
        // Download
        $fileContent = file_get_contents($downloadUrl);
        if ($fileContent === false) {
            throw new Exception("Errore download file da Amazon");
        }
        
        // Decomprimi se necessario
        if ($compression === 'GZIP') {
            $fileContent = gzdecode($fileContent);
            if ($fileContent === false) {
                throw new Exception("Errore decompressione file GZIP");
            }
        }
        
        if (file_put_contents($filePath, $fileContent) === false) {
            throw new Exception("Errore salvataggio file");
        }
        
        return [
            'file_path' => $filePath,
            'file_size' => filesize($filePath),
            'file_name' => $fileName
        ];
    }
    
    /**
     * Processa file inventory TSV
     */
   private function processInventoryFile($filePath) {
    // Routing basato su tipo di report
    if ($this->reportType === 'GET_MERCHANT_LISTINGS_ALL_DATA') {
        return $this->processFbmInventoryFile($filePath);
    }
    
    // Continua con logica FBA esistente
    $file = fopen($filePath, 'r');
    if (!$file) {
        throw new Exception("Impossibile aprire file: $filePath");
    }
    
    // Cancella dati esistenti per questo utente
    $stmt = $this->db->prepare("DELETE FROM inventory WHERE user_id = ?");
    $stmt->execute([$this->userId]);
        
        // Prepara statement di inserimento
        $insertSql = "INSERT INTO inventory (
            user_id, sku, fnsku, asin, product_name, condition_type, your_price,
            mfn_listing_exists, mfn_fulfillable_quantity, afn_listing_exists,
            afn_warehouse_quantity, afn_fulfillable_quantity, afn_total_quantity,
            afn_unsellable_quantity, afn_reserved_quantity, per_unit_volume,
            afn_inbound_working_quantity, afn_inbound_shipped_quantity,
            afn_inbound_receiving_quantity, afn_researching_quantity,
            afn_reserved_future_supply, afn_future_supply_buyable
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($insertSql);
        
        $processedRows = 0;
        $errorRows = 0;
        $isFirstRow = true;
        
        while (($row = fgetcsv($file, 0, "\t")) !== false) {
            // Salta header
            if ($isFirstRow) {
                $isFirstRow = false;
                continue;
            }
            
            // Verifica che abbiamo abbastanza colonne
            if (count($row) < 21) {
                $errorRows++;
                continue;
            }
            
            try {
                $sku = $row[0] ?? '';
                $afn_warehouse_quantity = intval($row[9] ?? 0);
                
                $stmt->execute([
                    $this->userId,
                    $sku,  // sku
                    $row[1] ?? '',  // fnsku
                    $row[2] ?? '',  // asin
                    $row[3] ?? '',  // product_name
                    $row[4] ?? '',  // condition_type
                    $this->parseDecimal($row[5] ?? '0'), // your_price
                    $this->parseBoolean($row[6] ?? 'false'), // mfn_listing_exists
                    intval($row[7] ?? 0),   // mfn_fulfillable_quantity
                    $this->parseBoolean($row[8] ?? 'false'), // afn_listing_exists
                    $afn_warehouse_quantity,   // afn_warehouse_quantity
                    intval($row[10] ?? 0),  // afn_fulfillable_quantity
                    intval($row[11] ?? 0),  // afn_total_quantity
                    intval($row[12] ?? 0),  // afn_unsellable_quantity
                    intval($row[13] ?? 0),  // afn_reserved_quantity
                    $this->parseDecimal($row[14] ?? '0'), // per_unit_volume
                    intval($row[15] ?? 0),  // afn_inbound_working_quantity
                    intval($row[16] ?? 0),  // afn_inbound_shipped_quantity
                    intval($row[17] ?? 0),  // afn_inbound_receiving_quantity
                    intval($row[18] ?? 0),  // afn_researching_quantity
                    intval($row[19] ?? 0),  // afn_reserved_future_supply
                    intval($row[20] ?? 0)   // afn_future_supply_buyable
                ]);
                
                $processedRows++;
                
                // Aggiorna last_charge se il prodotto ha stock > 0
                if ($afn_warehouse_quantity > 0 && !empty($sku)) {
                    $this->strategyManager->updateLastCharge($sku);
                }
                
            } catch (Exception $e) {
                $errorRows++;
            }
        }
        
        fclose($file);


        
// Auto-mapping con products esistenti
        $this->syncProductMapping();
        
        // RIPRISTINA mapping da mapping_states
        $this->restoreMappingsFromStates();
        
        return [
            'processed_rows' => $processedRows,
            'error_rows' => $errorRows,
            'message' => "Importati $processedRows record inventario"
        ];
    }

    /**
     * Ripristina mapping da mapping_states dopo import inventory
     */
    private function restoreMappingsFromStates() {
        try {
            $stmt = $this->db->prepare("
                UPDATE inventory i
                INNER JOIN mapping_states ms ON i.sku = ms.sku AND i.user_id = ms.user_id
                SET i.product_id = ms.product_id
                WHERE ms.user_id = ? 
                AND ms.source_table = 'inventory'
                AND ms.product_id IS NOT NULL
            ");
            $stmt->execute([$this->userId]);
            $restoredCount = $stmt->rowCount();
            
        } catch (Exception $e) {
            logSyncOperation($this->userId, 'inventory_mapping_restore_error', 'error', 
                'Errore ripristino mapping: ' . $e->getMessage());
        }
    }
    /**
 * Processa file FBM tramite inventory_fbm.php
 */
private function processFbmInventoryFile($filePath) {
    $fbmProcessor = __DIR__ . '/inventory_fbm.php';
    
    if (!file_exists($fbmProcessor)) {
        throw new Exception("File inventory_fbm.php non trovato in " . __DIR__);
    }
    
    // Include il processor FBM
    $userId = $this->userId;
    $db = $this->db;
    
    require_once $fbmProcessor;
    
    // $result è definito dall'esecuzione di inventory_fbm.php
    $fbmResult = $result ?? ['processed_rows' => 0, 'error_rows' => 0, 'message' => 'Nessun risultato FBM'];
    
    // Valida il risultato: se tanti errori e poche righe = fallimento
    if ($fbmResult['processed_rows'] === 0 && $fbmResult['error_rows'] > 10) {
        logSyncOperation($this->userId, 'inventory_fbm_failed', 'error', 
            "Processing FBM fallito: {$fbmResult['error_rows']} errori, 0 righe processate");
        
        throw new Exception("Parsing FBM fallito: {$fbmResult['error_rows']} errori di formato", 2001);
    }
    
    // Processing FBM - già tracciato da inventory_fbm_file_processed
    
    return $fbmResult;
}
    
    /**
     * Helper per parsing decimali
     */
    private function parseDecimal($value) {
        $cleaned = preg_replace('/[^\d.-]/', '', $value);
        return $cleaned === '' ? 0.00 : floatval($cleaned);
    }
    
    /**
     * Helper per parsing boolean
     */
    private function parseBoolean($value) {
        return in_array(strtolower($value), ['true', '1', 'yes', 'y']) ? 1 : 0;
    }
    
/**
 * Sincronizza mapping con tabella products esistente
 * PROTEZIONE: Non sovrascrive mapping manuali/locked dell'admin
 */
private function syncProductMapping() {
    // Mappa per SKU - SOLO se non esiste mapping manuale protetto
    $sql = "UPDATE inventory i 
            JOIN products p ON i.sku = p.sku AND p.user_id = i.user_id 
            LEFT JOIN mapping_states ms ON i.sku = ms.sku AND i.user_id = ms.user_id AND ms.source_table = 'inventory'
            SET i.product_id = p.id 
            WHERE i.product_id IS NULL 
            AND i.user_id = ?
            AND (ms.is_locked IS NULL OR ms.is_locked = 0)
            AND (ms.mapping_type IS NULL OR ms.mapping_type != 'manual')";
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$this->userId]);
    $mappedBySku = $stmt->rowCount();
    
    // Mappa per FNSKU - SOLO se non esiste mapping manuale protetto
    $sql = "UPDATE inventory i 
            JOIN products p ON i.fnsku = p.fnsku AND p.user_id = i.user_id 
            LEFT JOIN mapping_states ms ON i.sku = ms.sku AND i.user_id = ms.user_id AND ms.source_table = 'inventory'
            SET i.product_id = p.id 
            WHERE i.product_id IS NULL 
            AND i.fnsku IS NOT NULL 
            AND i.user_id = ?
            AND (ms.is_locked IS NULL OR ms.is_locked = 0)
            AND (ms.mapping_type IS NULL OR ms.mapping_type != 'manual')";
    
    $stmt = $this->db->prepare($sql);
    $stmt->execute([$this->userId]);
    $mappedByFnsku = $stmt->rowCount();
    
}
    
    /**
     * Ottieni statistiche inventario
     */
    public function getInventoryStats() {
        $sql = "SELECT 
                    COUNT(*) as total_skus,
                    COUNT(CASE WHEN product_id IS NOT NULL THEN 1 END) as mapped_skus,
                    COUNT(CASE WHEN afn_warehouse_quantity > 0 THEN 1 END) as skus_in_stock,
                    SUM(afn_total_quantity) as total_units,
                    MAX(last_updated) as last_sync
                FROM inventory
                WHERE user_id = ?";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->userId]);
        $result = $stmt->fetch();
        
        return [
            'total_skus' => (int)$result['total_skus'],
            'mapped_skus' => (int)$result['mapped_skus'],
            'skus_in_stock' => (int)$result['skus_in_stock'],
            'total_units' => (int)$result['total_units'],
            'last_sync' => $result['last_sync']
        ];
    }

    /**
     * Esegue sincronizzazione per un singolo tipo di report
     */
    private function executeSingleReportSync() {
        try {
            // 1. Ottieni credenziali
            $credentials = $this->getAmazonCredentials();
            $accessToken = $this->getAccessToken($credentials);
            
            // 2. Crea nuovo report
            $report = $this->createNewReport($accessToken, $credentials);
            
            // 3. Attendi completamento
            $report = $this->waitForReport($report['reportId'], $accessToken, $credentials);
            
            if (!isset($report['reportDocumentId'])) {
                throw new Exception("ReportDocumentId non trovato per {$this->reportType}");
            }
            
            // 4. Download file
            $downloadResult = $this->downloadReportFile($report['reportDocumentId'], $accessToken, $credentials);
            
            // 5. Processa file
            $processResult = $this->processInventoryFile($downloadResult['file_path']);
            
            return [
                'success' => true,
                'report_id' => $report['reportId'],
                'report_type' => $this->reportType,
                'processed_rows' => $processResult['processed_rows'] ?? 0,
                'file_info' => $downloadResult
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'report_type' => $this->reportType
            ];
        }
    }
    
    /**
     * Processo completo di sincronizzazione con gestione queue
     */
    public function fullSync() {
        try {
            // Marca utente come in elaborazione
            $this->updateQueueStatus('processing');
            
            $startTime = time();
            
            // Sincronizza entrambi i tipi
            $dualResults = $this->syncBothInventoryTypes();
            
            $duration = time() - $startTime;
            
            // Calcola statistiche aggregate
            $totalProcessedRows = 0;
            $reportIds = [];
            
            if ($dualResults['fba']['success']) {
                $totalProcessedRows += $dualResults['fba']['processed_rows'] ?? 0;
                $reportIds[] = $dualResults['fba']['report_id'] ?? '';
            }
            
            if ($dualResults['fbm']['success']) {
                $totalProcessedRows += $dualResults['fbm']['processed_rows'] ?? 0;
                $reportIds[] = $dualResults['fbm']['report_id'] ?? '';
            }
            
            // Get final product counts in DB (for daily report)
            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM inventory WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $fbaProductCount = $stmt->fetchColumn();
            
            $stmt = $this->db->prepare("SELECT COUNT(*) as cnt FROM inventory_fbm WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $fbmProductCount = $stmt->fetchColumn();
            
            // Get marketplace_id from token
            $stmt = $this->db->prepare("SELECT marketplace_id FROM amazon_client_tokens WHERE user_id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$this->userId]);
            $marketplaceId = $stmt->fetchColumn();
            
            // LOG UNICO CONSOLIDATO con tutti i dettagli (enhanced for daily report)
            logSyncOperation($this->userId, 'inventory_sync_completed', 'info', 
                sprintf(
                    'Sync completato in %ds: FBA %d righe, FBM %d righe | %d prodotti totali in DB',
                    $duration,
                    $dualResults['fba']['processed_rows'] ?? 0,
                    $dualResults['fbm']['processed_rows'] ?? 0,
                    $fbaProductCount + $fbmProductCount
                ), 
                [
                    'user_id' => $this->userId,
                    'marketplace_id' => $marketplaceId,
                    'execution_time_seconds' => $duration,
                    'total_processed_rows' => $totalProcessedRows,
                    'fba_success' => $dualResults['fba']['success'],
                    'fbm_success' => $dualResults['fbm']['success'],
                    'fba' => [
                        'success' => $dualResults['fba']['success'],
                        'rows' => $dualResults['fba']['processed_rows'] ?? 0,
                        'report_id' => $dualResults['fba']['report_id'] ?? null,
                        'products_in_db' => $fbaProductCount,
                        'file_path' => $dualResults['fba']['file_path'] ?? null,
                        'error' => $dualResults['fba']['error'] ?? null
                    ],
                    'fbm' => [
                        'success' => $dualResults['fbm']['success'],
                        'rows' => $dualResults['fbm']['processed_rows'] ?? 0,
                        'report_id' => $dualResults['fbm']['report_id'] ?? null,
                        'products_in_db' => $fbmProductCount,
                        'file_path' => $dualResults['fbm']['file_path'] ?? null,
                        'error' => $dualResults['fbm']['error'] ?? null
                    ]
                ]
            );
            
            if ($dualResults['overall_success']) {
                // Marca come completato nella queue
                $this->updateQueueStatus('completed', null, implode(',', $reportIds), null);
                
                return [
                    'success' => true,
                    'dual_results' => $dualResults,
                    'stats' => $this->getInventoryStats(),
                    'duration' => $duration,
                    'message' => $dualResults['message']
                ];
            } else {
                // Marca come fallito nella queue
                $this->updateQueueStatus('failed', $dualResults['message']);
                
                return [
                    'success' => false,
                    'error' => $dualResults['message'],
                    'dual_results' => $dualResults
                ];
            }
            
        } catch (Exception $e) {
            // Marca come fallito nella queue
            $this->updateQueueStatus('failed', $e->getMessage());
            
            logSyncOperation($this->userId, 'inventory_sync_error', 'error', 
                'Errore sincronizzazione: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

// AJAX Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    if (empty($action)) {
        echo json_encode(['success' => false, 'error' => 'Parametro action mancante']);
        exit;
    }
    
    try {
        $sync = new InventorySync($userId);
        
        switch ($action) {
            case 'sync':
            case 'full_sync':
                // Mantiene compatibilità con chiamate esistenti
                echo json_encode($sync->fullSync());
                break;
                
            case 'sync_dual':
                // Nuovo endpoint per chiamate cron esplicite
                echo json_encode($sync->fullSync());
                break;
                
            case 'get_stats':
                echo json_encode(['success' => true, 'stats' => $sync->getInventoryStats()]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Azione non valida: ' . $action]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Errore inizializzazione: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
}
?>