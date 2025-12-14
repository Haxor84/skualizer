<?php
/**
 * Removal Orders API Handler
 * File: modules/inbound/removal_api.php
 * 
 * Gestisce download e storage di Removal Orders da Amazon SP-API
 * 
 * Azioni supportate:
 * - request_report: Richiede report a Amazon
 * - check_status: Controlla stato report
 * - download_report: Scarica e processa TSV
 * - get_removal_orders: Fetch da DB per tabella
 * 
 * @version 1.0
 * @date 2025-10-25
 */

session_start();
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../margynomic/admin/admin_helpers.php';
require_once __DIR__ . '/../margynomic/config/CentralLogger.php';

// ============================================
// AUTHENTICATION: SOLO ADMIN
// ============================================
if (!isAdminLogged()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Accesso negato. Solo admin.']);
    exit;
}

// USER SELECTION
$userId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : ($_SESSION['removal_selected_user'] ?? 2);
$action = $_REQUEST['action'] ?? '';

// ============================================
// REMOVAL ORDERS API CLASS
// ============================================

class RemovalOrdersAPI {
    
    private $userId;
    private $db;
    private $credentials;
    private $accessToken;
    
    // SP-API config
    private $baseUrl = 'https://sellingpartnerapi-eu.amazon.com';
    private $region = 'eu-west-1';
    private $service = 'execute-api';
    
    public function __construct($userId) {
        $this->userId = (int)$userId;
        $this->db = getDbConnection();
        $this->loadCredentials();
    }
    
    // ===================================================================
    // CREDENTIALS & AUTHENTICATION
    // ===================================================================
    
    /**
     * Carica credenziali Amazon dal database
     */
    private function loadCredentials() {
        try {
            // 1. Carica credenziali GLOBALI
            $stmt = $this->db->prepare("
                SELECT aws_access_key_id, aws_secret_access_key, aws_region, 
                       spapi_client_id, spapi_client_secret 
                FROM amazon_credentials 
                WHERE is_active = 1 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $globalCreds = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$globalCreds) {
                throw new Exception("Credenziali Amazon globali non trovate");
            }
            
            // 2. Carica token USER-SPECIFIC
            $stmt = $this->db->prepare("
                SELECT refresh_token, marketplace_id, seller_id 
                FROM amazon_client_tokens 
                WHERE user_id = ? AND is_active = 1 
                LIMIT 1
            ");
            $stmt->execute([$this->userId]);
            $userToken = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userToken) {
                throw new Exception("Token Amazon non trovato per utente {$this->userId}");
            }
            
            // 3. Merge
            $this->credentials = [
                'aws_access_key_id' => $globalCreds['aws_access_key_id'],
                'aws_secret_access_key' => $globalCreds['aws_secret_access_key'],
                'region' => $globalCreds['aws_region'] ?? 'eu-west-1',
                'client_id' => $globalCreds['spapi_client_id'],
                'client_secret' => $globalCreds['spapi_client_secret'],
                'refresh_token' => $userToken['refresh_token'],
                'marketplace_id' => $userToken['marketplace_id'],
                'seller_id' => $userToken['seller_id']
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('removal_orders', 'ERROR', 'CREDS_ERROR: ' . $e->getMessage(), [
                'user_id' => $this->userId
            ]);
            throw $e;
        }
    }
    
    /**
     * Ottieni access token via LWA
     */
    private function getAccessToken() {
        try {
            $ch = curl_init('https://api.amazon.com/auth/o2/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->credentials['client_id'],
                    'client_secret' => $this->credentials['client_secret'],
                    'refresh_token' => $this->credentials['refresh_token']
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                throw new Exception("LWA token error (HTTP {$httpCode}): {$response}");
            }
            
            $data = json_decode($response, true);
            if (!isset($data['access_token'])) {
                throw new Exception("Access token mancante");
            }
            
            $this->accessToken = $data['access_token'];
            return $this->accessToken;
            
        } catch (Exception $e) {
            CentralLogger::log('removal_orders', 'ERROR', 'TOKEN_ERROR: ' . $e->getMessage(), [
                'user_id' => $this->userId
            ]);
            throw $e;
        }
    }
    
    // ===================================================================
    // AWS SIGNATURE V4
    // ===================================================================
    
    /**
     * Crea firma AWS SigV4
     */
    private function createAwsSignature($method, $path, $queryString, $body) {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        $timestamp = gmdate('Ymd\THis\Z');
        $date = substr($timestamp, 0, 8);
        
        // Canonical headers
        $canonicalHeaders = "host:{$host}\n";
        $canonicalHeaders .= "x-amz-access-token:{$this->accessToken}\n";
        $canonicalHeaders .= "x-amz-date:{$timestamp}\n";
        
        $signedHeaders = 'host;x-amz-access-token;x-amz-date';
        $payloadHash = hash('sha256', $body);
        
        // Canonical request
        $canonicalRequest = "{$method}\n{$path}\n{$queryString}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        
        // String to sign
        $credentialScope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        
        // Signing key
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->credentials['aws_secret_access_key'], true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        // Signature
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        // Authorization header
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->credentials['aws_access_key_id']}/{$credentialScope}, ";
        $authorization .= "SignedHeaders={$signedHeaders}, Signature={$signature}";
        
        return [
            'Authorization' => $authorization,
            'x-amz-date' => $timestamp,
            'x-amz-access-token' => $this->accessToken,
            'host' => $host
        ];
    }
    
    // ===================================================================
    // SP-API CALLS
    // ===================================================================
    
    /**
     * Chiamata generica SP-API
     */
    private function callSpApi($method, $path, $params = [], $body = '') {
        // Assicura access token
        if (!$this->accessToken) {
            $this->getAccessToken();
        }
        
        // Costruisci query string
        $queryString = '';
        if (!empty($params)) {
            ksort($params);
            $queryString = http_build_query($params);
        }
        
        // URL completo
        $url = $this->baseUrl . $path;
        if ($queryString) {
            $url .= '?' . $queryString;
        }
        
        // Firma AWS
        $signature = $this->createAwsSignature($method, $path, $queryString, $body);
        
        // Headers
        $headers = [
            "Authorization: {$signature['Authorization']}",
            "x-amz-date: {$signature['x-amz-date']}",
            "x-amz-access-token: {$signature['x-amz-access-token']}",
            "host: {$signature['host']}",
            "Content-Type: application/json"
        ];
        
        // cURL request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("cURL error: {$curlError}");
        }
        
        if ($httpCode >= 400) {
            CentralLogger::log('removal_orders', 'ERROR', 'API_ERROR', [
                'user_id' => $this->userId,
                'method' => $method,
                'path' => $path,
                'http_code' => $httpCode,
                'response' => substr($response, 0, 500)
            ]);
            throw new Exception("SP-API Error: HTTP {$httpCode}");
        }
        
        return json_decode($response, true);
    }
    
    // ===================================================================
    // ACTION 1: REQUEST REPORT
    // ===================================================================
    
    /**
     * Richiede report Removal Orders a Amazon
     */
    public function requestReport($startDate, $endDate) {
        try {
            CentralLogger::log('removal_orders', 'INFO', 'REQUEST_REPORT', [
                'user_id' => $this->userId,
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            
            $body = json_encode([
                'reportType' => 'GET_FBA_FULFILLMENT_REMOVAL_ORDER_DETAIL_DATA',
                'marketplaceIds' => [$this->credentials['marketplace_id']],
                'dataStartTime' => $startDate,
                'dataEndTime' => $endDate
            ]);
            
            $result = $this->callSpApi('POST', '/reports/2021-06-30/reports', [], $body);
            
            if (!isset($result['reportId'])) {
                throw new Exception("reportId mancante nella risposta");
            }
            
            CentralLogger::log('removal_orders', 'INFO', 'REPORT_REQUESTED', [
                'user_id' => $this->userId,
                'report_id' => $result['reportId']
            ]);
            
            return [
                'success' => true,
                'report_id' => $result['reportId']
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('removal_orders', 'ERROR', 'REQUEST_ERROR: ' . $e->getMessage(), [
                'user_id' => $this->userId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // ===================================================================
    // ACTION 2: CHECK STATUS
    // ===================================================================
    
    /**
     * Controlla stato report
     */
    public function checkReportStatus($reportId) {
        try {
            $result = $this->callSpApi('GET', "/reports/2021-06-30/reports/{$reportId}");
            
            if (!isset($result['processingStatus'])) {
                throw new Exception("processingStatus mancante");
            }
            
            $status = $result['processingStatus'];
            $documentId = $result['reportDocumentId'] ?? null;
            
            CentralLogger::log('removal_orders', 'INFO', 'STATUS_CHECK', [
                'user_id' => $this->userId,
                'report_id' => $reportId,
                'status' => $status,
                'document_id' => $documentId
            ]);
            
            return [
                'success' => true,
                'status' => $status,
                'document_id' => $documentId
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('removal_orders', 'ERROR', 'STATUS_ERROR: ' . $e->getMessage(), [
                'user_id' => $this->userId,
                'report_id' => $reportId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // ===================================================================
    // ACTION 3: DOWNLOAD REPORT
    // ===================================================================
    
    /**
     * Scarica e processa report TSV
     */
    public function downloadReport($documentId) {
        try {
            // 1. Get document info (URL download)
            $docInfo = $this->callSpApi('GET', "/reports/2021-06-30/documents/{$documentId}");
            
            if (!isset($docInfo['url'])) {
                throw new Exception("URL download mancante");
            }
            
            CentralLogger::log('removal_orders', 'INFO', 'DOWNLOAD_START', [
                'user_id' => $this->userId,
                'document_id' => $documentId
            ]);
            
            // 2. Download TSV
            $ch = curl_init($docInfo['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 120
            ]);
            
            $tsvContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || empty($tsvContent)) {
                throw new Exception("Download fallito: HTTP {$httpCode}");
            }
            
            // 3. Parse e salva
            $parseResult = $this->parseRemovalOrdersTsv($tsvContent);
            
            if (!$parseResult['success']) {
                throw new Exception($parseResult['error']);
            }
            
            CentralLogger::log('removal_orders', 'INFO', 'DOWNLOAD_SUCCESS', [
                'user_id' => $this->userId,
                'document_id' => $documentId,
                'inserted' => $parseResult['inserted'],
                'skipped' => $parseResult['skipped']
            ]);
            
            return [
                'success' => true,
                'inserted' => $parseResult['inserted'],
                'skipped' => $parseResult['skipped']
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('removal_orders', 'ERROR', 'DOWNLOAD_ERROR: ' . $e->getMessage(), [
                'user_id' => $this->userId,
                'document_id' => $documentId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Parse TSV e insert in DB
     */
    private function parseRemovalOrdersTsv($tsvContent) {
        $lines = explode("\n", trim($tsvContent));
        
        if (count($lines) < 2) {
            return ['success' => false, 'error' => 'Report vuoto'];
        }
        
        // Parse header
        $header = str_getcsv(array_shift($lines), "\t");
        $colMap = array_flip($header);
        
        $inserted = 0;
        $skipped = 0;
        
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            
            $row = str_getcsv($line, "\t");
            
            // Campi obbligatori
            $orderId = $row[$colMap['order-id'] ?? -1] ?? null;
            $sku = $row[$colMap['sku'] ?? -1] ?? null;
            
            if (!$orderId || !$sku) {
                $skipped++;
                continue;
            }
            
            // Trova product_id
            $productId = $this->findProductIdBySku($sku);
            
            // Parse date
            $requestDate = null;
            if (isset($colMap['request-date']) && !empty($row[$colMap['request-date']])) {
                try {
                    $requestDate = date('Y-m-d H:i:s', strtotime($row[$colMap['request-date']]));
                } catch (Exception $e) {
                    // Ignora errori di parsing data
                }
            }
            
            // Insert/Update
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO removal_orders (
                        user_id, order_id, request_date, sku, fnsku, product_id,
                        disposition, requested_quantity, cancelled_quantity,
                        disposed_quantity, shipped_quantity, order_type, order_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        cancelled_quantity = VALUES(cancelled_quantity),
                        disposed_quantity = VALUES(disposed_quantity),
                        shipped_quantity = VALUES(shipped_quantity),
                        order_status = VALUES(order_status),
                        last_updated = CURRENT_TIMESTAMP
                ");
                
                $stmt->execute([
                    $this->userId,
                    $orderId,
                    $requestDate,
                    $sku,
                    $row[$colMap['fnsku'] ?? -1] ?? null,
                    $productId,
                    $row[$colMap['disposition'] ?? -1] ?? null,
                    (int)($row[$colMap['requested-quantity'] ?? -1] ?? 0),
                    (int)($row[$colMap['cancelled-quantity'] ?? -1] ?? 0),
                    (int)($row[$colMap['disposed-quantity'] ?? -1] ?? 0),
                    (int)($row[$colMap['shipped-quantity'] ?? -1] ?? 0),
                    $row[$colMap['order-type'] ?? -1] ?? 'Removal',
                    $row[$colMap['order-status'] ?? -1] ?? null
                ]);
                
                $inserted++;
                
            } catch (PDOException $e) {
                // Skip duplicate o errori constraint
                $skipped++;
                continue;
            }
        }
        
        return [
            'success' => true,
            'inserted' => $inserted,
            'skipped' => $skipped
        ];
    }
    
    /**
     * Trova product_id da SKU
     */
    private function findProductIdBySku($sku) {
        $stmt = $this->db->prepare("
            SELECT id FROM products 
            WHERE user_id = ? AND sku = ? 
            LIMIT 1
        ");
        $stmt->execute([$this->userId, $sku]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $product['id'] ?? null;
    }
    
    // ===================================================================
    // ACTION 4: GET REMOVAL ORDERS
    // ===================================================================
    
    /**
     * Fetch removal orders da DB
     */
    public function getRemovalOrders($filters = []) {
        try {
            $where = ["ro.user_id = ?"];
            $params = [$this->userId];
            
            // Filtro data
            if (!empty($filters['start_date'])) {
                $where[] = "ro.request_date >= ?";
                $params[] = $filters['start_date'] . ' 00:00:00';
            }
            
            if (!empty($filters['end_date'])) {
                $where[] = "ro.request_date <= ?";
                $params[] = $filters['end_date'] . ' 23:59:59';
            }
            
            // Filtro SKU
            if (!empty($filters['sku'])) {
                $where[] = "ro.sku LIKE ?";
                $params[] = '%' . $filters['sku'] . '%';
            }
            
            // Filtro status
            if (!empty($filters['status'])) {
                $where[] = "ro.order_status = ?";
                $params[] = $filters['status'];
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Query
            $sql = "
                SELECT 
                    ro.*,
                    p.nome as product_name,
                    p.asin
                FROM removal_orders ro
                LEFT JOIN products p ON p.id = ro.product_id
                WHERE {$whereClause}
                ORDER BY ro.request_date DESC
                LIMIT 1000
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'orders' => $orders,
                'count' => count($orders)
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('removal_orders', 'ERROR', 'FETCH_ERROR: ' . $e->getMessage(), [
                'user_id' => $this->userId
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'orders' => []
            ];
        }
    }
}

// ============================================
// ACTION ROUTER
// ============================================

// Skip action router if included by cron (no HTTP request)
if (empty($action) && php_sapi_name() === 'cli') {
    // Script included by cron, don't execute action router
    return;
}

$api = new RemovalOrdersAPI($userId);

switch ($action) {
    
    case 'request_report':
        $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('-3 years'));
        $endDate = $_POST['end_date'] ?? date('Y-m-d');
        
        // Converti in ISO 8601
        $startDate = date('c', strtotime($startDate . ' 00:00:00'));
        $endDate = date('c', strtotime($endDate . ' 23:59:59'));
        
        $result = $api->requestReport($startDate, $endDate);
        echo json_encode($result);
        break;
        
    case 'check_status':
        $reportId = $_GET['report_id'] ?? '';
        
        if (empty($reportId)) {
            echo json_encode(['success' => false, 'error' => 'report_id mancante']);
            exit;
        }
        
        $result = $api->checkReportStatus($reportId);
        echo json_encode($result);
        break;
        
    case 'download_report':
        $documentId = $_POST['document_id'] ?? '';
        
        if (empty($documentId)) {
            echo json_encode(['success' => false, 'error' => 'document_id mancante']);
            exit;
        }
        
        $result = $api->downloadReport($documentId);
        echo json_encode($result);
        break;
        
    case 'get_removal_orders':
        $filters = [
            'start_date' => $_GET['start_date'] ?? '',
            'end_date' => $_GET['end_date'] ?? '',
            'sku' => $_GET['sku'] ?? '',
            'status' => $_GET['status'] ?? ''
        ];
        
        $result = $api->getRemovalOrders($filters);
        echo json_encode($result);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Azione non valida']);
        break;
}

