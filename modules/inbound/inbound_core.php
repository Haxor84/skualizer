<?php
/**
 * Inbound Core - API Client + Sync Engine Unificato
 * File: modules/inbound/inbound_core.php
 * 
 * Responsabilità:
 * - Client Amazon SP-API v0 (autenticazione, firma AWS SigV4, chiamate API)
 * - Sync incrementale con cursori persistenti
 * - Gestione loop Amazon (token duplicati, pagine duplicate)
 * - Backoff esponenziale con jitter per 429/5xx
 * - Circuit breaker per utente
 * - Lock concorrenza con heartbeat
 * - Idempotenza garantita (UNIQUE keys + upsert)
 * 
 * @version 2.0
 * @date 2025-10-17
 */

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/config/CentralLogger.php';

class InboundCore {
    
    // ===================================================================
    // SEZIONE 1: CONFIGURAZIONE & PROPRIETÀ
    // ===================================================================
    
    private $userId;
    private $db;
    private $logger;
    private $credentials;
    private $accessToken;
    private $clockOffset = 0; // Clock skew offset (secondi)
    
    // Configurazione Amazon SP-API
    private $baseUrl = 'https://sellingpartnerapi-eu.amazon.com';
    private $region = 'eu-west-1';
    private $service = 'execute-api';
    
    // Budget e limiti
    private $apiCallsLimit = 100;      // Max chiamate API per run
    private $apiCallsCount = 0;        // Contatore chiamate corrente
    private $runTimeout = 1800;        // 30 minuti max per run
    private $shipmentsLimit = 1000;    // Max shipments per run
    private $runStartTime;             // Timestamp inizio run
    
    // Backoff e retry
    private $maxRetries = 5;           // Max tentativi per chiamata
    private $baseBackoff = 1;          // Secondi base backoff
    private $jitterPercent = 0.3;      // ±30% jitter
    
    // Anti-loop e safety
    private $maxPagesPerShipment = 100;     // Max pagine items per shipment
    private $maxDurationPerShipment = 300;  // 5 minuti max per shipment
    
    // Heartbeat
    private $heartbeatInterval = 30;   // Aggiorna heartbeat ogni 30s
    private $lastHeartbeat = 0;        // Timestamp ultimo heartbeat
    
    // Progress callback (opzionale, per output real-time)
    private $progressCallback = null;
    
    // Opzioni run
    private $dryRun = false;           // Se true, non scrive nel DB
    private $cronMode = false;         // Se true, log minimal
    
    // Historical sweep tracking
    private $seenIdsRun = [];          // Dedup cross-window (in-memory)
    private $seenIdsFlushThreshold = 500; // Flush a DB ogni N ID
    private $currentHistoricalStatus = null; // Stato corrente in sweep
    
    // Budget tracking (per log accorpati)
    private $budgetSkippedShipments = []; // Spedizioni saltate per budget esaurito
    
    /**
     * Costruttore
     * 
     * @param int $userId ID utente
     * @param array $options Opzioni: dry_run, cron_mode, api_calls_limit, etc.
     */
    public function __construct($userId, $options = []) {
        $this->userId = (int)$userId;
        $this->db = getDbConnection();
        $this->logger = new CentralLogger();
        $this->runStartTime = microtime(true);
        
        // Applica opzioni
        $this->dryRun = $options['dry_run'] ?? false;
        $this->cronMode = $options['cron_mode'] ?? false;
        $this->apiCallsLimit = $options['api_calls_limit'] ?? 100;
        $this->runTimeout = $options['run_timeout'] ?? 1800;
        $this->shipmentsLimit = $options['shipments_limit'] ?? 1000;
        
        // Carica credenziali Amazon
        $this->loadCredentials();
    }
    
    /**
     * Imposta callback per progress (output real-time)
     */
    public function setProgressCallback($callback) {
        $this->progressCallback = $callback;
    }
    
    /**
     * Log progress (se callback impostato)
     */
    private function logProgress($message) {
        if ($this->progressCallback && is_callable($this->progressCallback)) {
            call_user_func($this->progressCallback, $message);
        }
    }
    
    // ===================================================================
    // SEZIONE 2: CREDENZIALI & AUTENTICAZIONE
    // ===================================================================
    
    /**
     * Carica credenziali Amazon (globali + user-specific)
     */
    private function loadCredentials() {
        try {
            // 1. Carica credenziali GLOBALI da amazon_credentials
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
                throw new Exception("Credenziali Amazon globali non trovate in amazon_credentials");
            }
            
            // 2. Carica refresh_token USER-SPECIFIC da amazon_client_tokens
            $stmt = $this->db->prepare("
                SELECT refresh_token, marketplace_id, seller_id 
                FROM amazon_client_tokens 
                WHERE user_id = ? AND is_active = 1 
                LIMIT 1
            ");
            $stmt->execute([$this->userId]);
            $userToken = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$userToken) {
                throw new Exception("Token Amazon non trovato per user {$this->userId}");
            }
            
            // 3. Merge credenziali
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
            $this->logger->error('inventory', "INBOUND_CREDS_ERROR", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Ottieni access token via LWA (Login with Amazon)
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
                throw new Exception("Access token mancante nella risposta LWA");
            }
            
            return $data['access_token'];
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_TOKEN_ERROR", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    // ===================================================================
    // SEZIONE 3: FIRMA AWS SIGV4 & CHIAMATE API
    // ===================================================================
    
    /**
     * Crea firma AWS SigV4 per richiesta SP-API
     */
    private function createAwsSignature($method, $path, $queryString, $body) {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        
        // Applica clock offset se presente
        $timestamp = gmdate('Ymd\THis\Z', time() + $this->clockOffset);
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
        
        // Signing key (4-step HMAC chain)
        $kDate = hash_hmac('sha256', $date, 'AWS4' . $this->credentials['aws_secret_access_key'], true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        // Authorization header
        $authorization = "AWS4-HMAC-SHA256 Credential={$this->credentials['aws_access_key_id']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
        
        return [
            'host' => $host,
            'x-amz-access-token' => $this->accessToken,
            'x-amz-date' => $timestamp,
            'Authorization' => $authorization
        ];
    }
    
    /**
     * Calcola backoff esponenziale con jitter
     * 
     * @param int $attempt Numero tentativo (1-based)
     * @param bool $withJitter Se aggiungere jitter
     * @return float Secondi di attesa
     */
    private function calculateBackoff($attempt, $withJitter = true) {
        // Base: min(32, 2^attempt) → 1, 2, 4, 8, 16, 32
        $base = min(32, pow(2, $attempt - 1));
        
        if (!$withJitter) {
            return $base;
        }
        
        // Jitter: ±30%
        $jitter = (mt_rand() / mt_getrandmax()) * 2 - 1; // -1.0 a +1.0
        $jitter *= $this->jitterPercent; // -0.3 a +0.3
        
        return $base * (1 + $jitter);
    }
    
    /**
     * Rileva e gestisce clock skew da header Date Amazon
     */
    private function handleClockSkew($responseHeaders) {
        if (!isset($responseHeaders['Date'])) {
            return;
        }
        
        try {
            $amazonTime = strtotime($responseHeaders['Date']);
            $localTime = time();
            $offset = $amazonTime - $localTime;
            
            // Se offset > 5 minuti, applica correzione
            if (abs($offset) > 300) {
                $this->clockOffset = $offset;
                $this->logger->warning('inventory', "CLOCK_SKEW_DETECTED", [
                    'user_id' => $this->userId,
                    'offset_seconds' => $offset,
                    'amazon_time' => $responseHeaders['Date'],
                    'local_time' => gmdate('r', $localTime)
                ]);
            }
        } catch (Exception $e) {
            // Ignora errori parsing Date header
        }
    }
    
    /**
     * Esegui chiamata API con retry automatico e backoff
     * 
     * @param string $method HTTP method (GET, POST)
     * @param string $path URI path
     * @param array $params Query parameters
     * @param string $body Request body
     * @param string $phase Nome fase (per log)
     * @return array Response decodificato
     */
    public function call($method, $path, $params = [], $body = '', $phase = 'api_call') {
        // Rate limiting: 0.5s tra richieste
        usleep(500000);
        
        // Check budget API
        if ($this->apiCallsCount >= $this->apiCallsLimit) {
            throw new Exception("Budget API esaurito ({$this->apiCallsLimit} chiamate)");
        }
        
        $this->apiCallsCount++;
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $this->maxRetries) {
            try {
                $attempt++;
                $callStart = microtime(true);
                
                // Refresh access token ad ogni chiamata (best practice)
                $this->accessToken = $this->getAccessToken();
                
                // Build URL
                $queryString = !empty($params) ? http_build_query($params) : '';
                $url = $this->baseUrl . $path . ($queryString ? '?' . $queryString : '');
                
                // Sign request
                $headers = $this->createAwsSignature($method, $path, $queryString, $body);
                
                // Prepare cURL headers
                $curlHeaders = [];
                foreach ($headers as $key => $value) {
                    $curlHeaders[] = "{$key}: {$value}";
                }
                $curlHeaders[] = 'Content-Type: application/json';
                
                // Execute cURL
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => $method,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true, // Include headers per clock skew
                    CURLOPT_HTTPHEADER => $curlHeaders,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_CONNECTTIMEOUT => 10
                ]);
                
                if (!empty($body)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                $duration = round((microtime(true) - $callStart) * 1000, 2);
                
                // Separa headers e body
                $responseHeaders = substr($response, 0, $headerSize);
                $responseBody = substr($response, $headerSize);
                
                // Parse headers
                $parsedHeaders = [];
                foreach (explode("\r\n", $responseHeaders) as $line) {
                    if (strpos($line, ':') !== false) {
                        list($key, $value) = explode(':', $line, 2);
                        $parsedHeaders[trim($key)] = trim($value);
                    }
                }
                
                // Clock skew detection
                $this->handleClockSkew($parsedHeaders);
                
                // Handle cURL errors
                if ($curlError) {
                    throw new Exception("cURL error: {$curlError}");
                }
                
                // Handle rate limiting (429) → RETRY con backoff
                if ($httpCode === 429) {
                    $waitTime = $this->calculateBackoff($attempt);
                    
                    $this->logger->warning('inventory', "INBOUND_API_RATE_LIMIT", [
                        'user_id' => $this->userId,
                        'phase' => $phase,
                        'attempt' => $attempt,
                        'backoff_seconds' => $waitTime
                    ]);
                    
                    sleep((int)$waitTime);
                    continue; // Retry
                }
                
                // Handle 5xx errors → RETRY con backoff
                if ($httpCode >= 500) {
                    $waitTime = $this->calculateBackoff($attempt);
                    
                    $this->logger->warning('inventory', "INBOUND_API_5XX", [
                        'user_id' => $this->userId,
                        'phase' => $phase,
                        'http_code' => $httpCode,
                        'attempt' => $attempt,
                        'backoff_seconds' => $waitTime
                    ]);
                    
                    sleep((int)$waitTime);
                    continue; // Retry
                }
                
                // Handle HTTP errors (non-retryable)
                if ($httpCode < 200 || $httpCode >= 300) {
                    // 404 su boxes è normale
                    if ($httpCode === 404 && strpos($path, '/boxes') !== false) {
                        return ['boxes' => []];
                    }
                    
                    // Clock skew error → retry con offset
                    if ($httpCode === 403 && strpos($responseBody, 'timestamp') !== false) {
                        $this->logger->warning('inventory', "CLOCK_SKEW_ERROR", [
                            'user_id' => $this->userId,
                            'attempt' => $attempt,
                            'response' => substr($responseBody, 0, 200)
                        ]);
                        
                        // Retry con offset recalcolato
                        sleep(1);
                        continue;
                    }
                    
                    throw new Exception("HTTP {$httpCode}: " . substr($responseBody, 0, 500));
                }
                
                // Decode JSON
                $data = json_decode($responseBody, true);
                if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception("JSON decode error: " . json_last_error_msg());
                }
                
                // Log successo (solo se non in cron mode)
                if (!$this->cronMode && $attempt > 1) {
                    $this->logger->info('inventory', "INBOUND_API_SUCCESS_AFTER_RETRY", [
                        'user_id' => $this->userId,
                        'phase' => $phase,
                        'attempts' => $attempt,
                        'duration_ms' => $duration
                    ]);
                }
                
                // SUCCESS!
                return $data;
                
            } catch (Exception $e) {
                $lastException = $e;
                
                $this->logger->error('inventory', "INBOUND_API_ERROR", [
                    'user_id' => $this->userId,
                    'phase' => $phase,
                    'path' => $path,
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_retries' => $this->maxRetries
                ]);
                
                // Se abbiamo esaurito i retry, throw
                if ($attempt >= $this->maxRetries) {
                    break;
                }
                
                // Backoff prima del retry
                $backoffTime = $this->calculateBackoff($attempt);
                sleep((int)$backoffTime);
            }
        }
        
        // Max retries exceeded
        throw new Exception("Max retries ({$this->maxRetries}) exceeded for {$phase}: " . $lastException->getMessage());
    }
    
    // ===================================================================
    // SEZIONE 4: API ENDPOINTS AMAZON SP-API V0
    // ===================================================================
    
    /**
     * Helper: valida e formatta timestamp ISO 8601 UTC
     */
    private function isoUtc($ts) {
        if ($ts === null || $ts === '' || $ts === false) {
            return null;
        }
        
        try {
            $dt = new DateTimeImmutable($ts, new DateTimeZone('UTC'));
            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Lista shipments via API v0 (SUPPORTA PARAMETRI OPZIONALI)
     * 
     * Modalità 1 (paginazione): ['NextToken' => '...']
     * Modalità 2 (finestra strict): ['LastUpdatedAfter' => '...', 'LastUpdatedBefore' => '...']
     * Modalità 3 (broad before): ['LastUpdatedBefore' => '...']
     * Modalità 4 (legacy): $fromUtc, $toUtc come stringhe
     * 
     * @param mixed $fromUtcOrOptions String legacy o array options
     * @param string|null $toUtc Data fine (solo se $fromUtcOrOptions è string)
     * @param string|null $nextToken Token paginazione (solo se legacy)
     * @return array Response con ShipmentData e NextToken
     */
    public function getShipmentsV0($fromUtcOrOptions = null, $toUtc = null, $nextToken = null) {
        $params = [];
        
        // REQUIRED: lista status esplicita (default tutti)
        $defaultStatuses = 'WORKING,SHIPPED,IN_TRANSIT,DELIVERED,CHECKED_IN,RECEIVING,CLOSED,CANCELLED,DELETED,ERROR';
        
        // Detect chiamata: array options vs legacy
        if (is_array($fromUtcOrOptions)) {
            $opts = $fromUtcOrOptions;
            
            // NextToken ha priorità (ignora altri filtri)
            if (!empty($opts['NextToken'])) {
                $params['NextToken'] = $opts['NextToken'];
                
                // FIX: ShipmentStatusList deve essere stringa CSV, non array
                $statusList = $opts['ShipmentStatusList'] ?? $defaultStatuses;
                if (is_array($statusList)) {
                    $params['ShipmentStatusList'] = implode(',', $statusList);
                } else {
                    $params['ShipmentStatusList'] = $statusList;
                }
            } else {
                // Filtri temporali opzionali
                $after = $this->isoUtc($opts['LastUpdatedAfter'] ?? null);
                $before = $this->isoUtc($opts['LastUpdatedBefore'] ?? null);
                
                // FIX 400: NON inviare parametri vuoti/null
                if ($after !== null) {
                    $params['LastUpdatedAfter'] = $after;
                }
                if ($before !== null) {
                    $params['LastUpdatedBefore'] = $before;
                }
                
                // Status list
                if (!empty($opts['ShipmentStatusList'])) {
                    if (is_array($opts['ShipmentStatusList'])) {
                        $params['ShipmentStatusList'] = implode(',', $opts['ShipmentStatusList']);
                    } else {
                        $params['ShipmentStatusList'] = $opts['ShipmentStatusList'];
                    }
                } else {
                    $params['ShipmentStatusList'] = $defaultStatuses;
                }
            }
        } else {
            // LEGACY: chiamata con stringhe separate
            $params['ShipmentStatusList'] = $defaultStatuses;
            
            if ($nextToken) {
                // Pagina successiva: NextToken + Status
                $params['NextToken'] = $nextToken;
            } else {
                // Prima pagina: filtri completi
                $params['QueryType'] = 'DATE_RANGE';
                $params['MarketplaceId'] = $this->credentials['marketplace_id'];
                
                $after = $this->isoUtc($fromUtcOrOptions);
                $before = $this->isoUtc($toUtc);
                
                if ($after !== null) {
                    $params['LastUpdatedAfter'] = $after;
                }
                if ($before !== null) {
                    $params['LastUpdatedBefore'] = $before;
                }
            }
        }
        
        return $this->call('GET', '/fba/inbound/v0/shipments', $params, '', 'list_shipments');
    }
    
    /**
     * Ottieni items di uno shipment via API v0 (con paginazione)
     * 
     * @param string $shipmentId Shipment ID
     * @param string|null $nextToken Token paginazione
     * @return array Response con ItemData e NextToken
     */
    public function getShipmentItemsV0($shipmentId, $nextToken = null) {
        $params = [];
        
        if ($nextToken) {
            $params['NextToken'] = $nextToken;
        } else {
            $params['MarketplaceId'] = $this->credentials['marketplace_id'];
        }
        
        return $this->call('GET', "/fba/inbound/v0/shipments/{$shipmentId}/items", $params, '', 'get_items');
    }
    
    /**
     * Recupera dettagli di una singola spedizione per ID
     * 
     * @param string $shipmentId Shipment ID Amazon
     * @return array Response con ShipmentData
     */
    public function getShipmentDetailsV0($shipmentId) {
        $params = [
            'MarketplaceId' => $this->credentials['marketplace_id']
        ];
        
        return $this->call('GET', "/fba/inbound/v0/shipments/{$shipmentId}", $params, '', 'get_shipment_details');
    }
    
    // ===================================================================
    // SEZIONE 5: LOCK MANAGEMENT & HEARTBEAT
    // ===================================================================
    
    /**
     * Acquisisce lock per l'utente (previene sync concorrenti)
     * 
     * @return bool True se lock acquisito, False se già locked
     */
    public function acquireLock() {
        try {
            // Genera process_id univoco
            $processId = uniqid('inbound_', true) . '_' . getmypid();
            
            // Check se esiste lock e se stuck (heartbeat > 5 minuti)
            $stmt = $this->db->prepare("
                SELECT heartbeat_at 
                FROM sync_locks 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            $existingLock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingLock) {
                $heartbeatAge = time() - strtotime($existingLock['heartbeat_at']);
                
                // Se heartbeat > 5 minuti, lock è stuck → rimuovi e riprova
                if ($heartbeatAge > 300) {
                    $this->logger->warning('inventory', "INBOUND_STUCK_LOCK_REMOVED", [
                        'user_id' => $this->userId,
                        'heartbeat_age_seconds' => $heartbeatAge
                    ]);
                    
                    $stmt = $this->db->prepare("DELETE FROM sync_locks WHERE user_id = ?");
                    $stmt->execute([$this->userId]);
                } else {
                    // Lock valido, non possiamo acquisire
                    return false;
                }
            }
            
            // Inserisci nuovo lock
            $stmt = $this->db->prepare("
                INSERT INTO sync_locks (user_id, locked_at, heartbeat_at, process_id)
                VALUES (?, NOW(), NOW(), ?)
                ON DUPLICATE KEY UPDATE 
                    locked_at = NOW(),
                    heartbeat_at = NOW(),
                    process_id = VALUES(process_id)
            ");
            $stmt->execute([$this->userId, $processId]);
            
            $this->lastHeartbeat = time();
            
            if (!$this->cronMode) {
                $this->logger->info('inventory', "INBOUND_LOCK_ACQUIRED", [
                    'user_id' => $this->userId,
                    'process_id' => $processId
                ]);
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_LOCK_ERROR", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Aggiorna heartbeat (chiamare ogni ~30s durante sync)
     */
    public function updateHeartbeat() {
        // Check se è passato abbastanza tempo dall'ultimo heartbeat
        if (time() - $this->lastHeartbeat < $this->heartbeatInterval) {
            return;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE sync_locks 
                SET heartbeat_at = NOW() 
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            
            $this->lastHeartbeat = time();
            
        } catch (Exception $e) {
            // Non bloccare su errore heartbeat
            $this->logger->warning('inventory', "INBOUND_HEARTBEAT_ERROR", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Rilascia lock
     */
    public function releaseLock() {
        try {
            $stmt = $this->db->prepare("DELETE FROM sync_locks WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            
            if (!$this->cronMode) {
                $this->logger->info('inventory', "INBOUND_LOCK_RELEASED", [
                    'user_id' => $this->userId
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_LOCK_RELEASE_ERROR", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // ===================================================================
    // SEZIONE 6: CIRCUIT BREAKER
    // ===================================================================
    
    /**
     * Check se circuit breaker è open per questo utente
     * 
     * @return bool True se circuit è open (skip sync)
     */
    public function isCircuitOpen() {
        try {
            $stmt = $this->db->prepare("
                SELECT circuit_state, circuit_until, consecutive_errors
                FROM sync_state
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            $state = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$state) {
                return false; // Nessuno stato = circuit closed
            }
            
            // Se circuit open e non scaduto
            if ($state['circuit_state'] === 'open' && 
                $state['circuit_until'] && 
                strtotime($state['circuit_until']) > time()) {
                return true;
            }
            
            // Se circuit_until scaduto, passa a half_open
            if ($state['circuit_state'] === 'open' && 
                $state['circuit_until'] && 
                strtotime($state['circuit_until']) <= time()) {
                    
                $this->db->prepare("
                    UPDATE sync_state 
                    SET circuit_state = 'half_open' 
                    WHERE user_id = ?
                ")->execute([$this->userId]);
                
                $this->logger->info('inventory', "CIRCUIT_BREAKER_HALF_OPEN", [
                    'user_id' => $this->userId
                ]);
                
                return false; // Permetti un tentativo in half_open
            }
            
            return false;
            
        } catch (Exception $e) {
            // Su errore, permetti sync (fail-open)
            return false;
        }
    }
    
    /**
     * Incrementa errori consecutivi e trip circuit se soglia superata
     */
    private function incrementConsecutiveErrors() {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_state (user_id, consecutive_errors, updated_at)
                VALUES (?, 1, NOW())
                ON DUPLICATE KEY UPDATE 
                    consecutive_errors = consecutive_errors + 1,
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId]);
            
            // Leggi errori consecutivi
            $stmt = $this->db->prepare("
                SELECT consecutive_errors FROM sync_state WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            $errors = $stmt->fetchColumn();
            
            // Se >= 5 errori → TRIP circuit breaker
            if ($errors >= 5) {
                $stmt = $this->db->prepare("
                    UPDATE sync_state 
                    SET circuit_state = 'open',
                        circuit_until = DATE_ADD(NOW(), INTERVAL 6 HOUR)
                    WHERE user_id = ?
                ");
                $stmt->execute([$this->userId]);
                
                $this->logger->error('inventory', "CIRCUIT_BREAKER_TRIPPED", [
                    'user_id' => $this->userId,
                    'consecutive_errors' => $errors,
                    'open_until' => date('Y-m-d H:i:s', time() + 21600)
                ]);
            }
            
        } catch (Exception $e) {
            // Non bloccare su errore
        }
    }
    
    /**
     * Reset circuit breaker su successo
     */
    private function resetCircuitBreaker() {
        try {
            $stmt = $this->db->prepare("
                UPDATE sync_state 
                SET consecutive_errors = 0,
                    circuit_state = 'closed',
                    circuit_until = NULL,
                    retry_backoff = 0
                WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            
        } catch (Exception $e) {
            // Non bloccare
        }
    }
    
    // ===================================================================
    // SEZIONE 7: HISTORICAL SWEEP - CURSORI & BUDGET
    // ===================================================================
    
    /**
     * Leggi cursore storico per uno stato specifico
     * 
     * @param string $status Status spedizione (CLOSED, RECEIVING, ecc.)
     * @return string|null ISO UTC datetime o null se non esiste
     */
    private function getHistoricCursor($status) {
        try {
            $stmt = $this->db->prepare("
                SELECT historic_cursors FROM sync_state WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            $json = $stmt->fetchColumn();
            
            if (!$json) {
                return null;
            }
            
            $map = json_decode($json, true) ?: [];
            return $map[$status] ?? null;
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Salva cursore storico per uno stato specifico
     * 
     * @param string $status Status spedizione
     * @param string $toIso Timestamp ISO UTC (fine finestra processata)
     */
    private function setHistoricCursor($status, $toIso) {
        try {
            // Leggi cursori esistenti
            $stmt = $this->db->prepare("
                SELECT historic_cursors FROM sync_state WHERE user_id = ?
            ");
            $stmt->execute([$this->userId]);
            $json = $stmt->fetchColumn();
            
            $map = $json ? (json_decode($json, true) ?: []) : [];
            $map[$status] = $toIso;
            
            // Aggiorna
            $stmt = $this->db->prepare("
                INSERT INTO sync_state (user_id, historic_cursors, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    historic_cursors = VALUES(historic_cursors),
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, json_encode($map)]);
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "HISTORIC_CURSOR_SAVE_FAILED", [
                'user_id' => $this->userId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Check se budget API/timeout ancora disponibile
     * 
     * @return bool True se possiamo continuare
     */
    private function budgetOk() {
        // Check API calls limit
        if ($this->apiCallsCount >= $this->apiCallsLimit) {
            return false;
        }
        
        // Check timeout
        $elapsed = microtime(true) - $this->runStartTime;
        if ($elapsed > $this->runTimeout) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Incrementa contatore API calls (per budget tracking)
     */
    private function bumpBudget() {
        $this->apiCallsCount++;
    }
    
    // ===================================================================
    // SEZIONE 8: SYNC ENGINE INCREMENTALE
    // ===================================================================
    
    /**
     * Sync incrementale: scarica solo shipments modificati dopo last_cursor_utc
     * 
     * @param array $options Opzioni: from_date (override cursor), max_shipments
     * @return array Summary con statistiche
     */
    public function syncIncremental($options = []) {
        $summary = [
            'synced' => 0,
            'skipped' => 0,
            'partial' => 0,
            'errors' => 0,
            'api_calls' => 0,
            'pages_fetched' => 0,
            'pages_wasted' => 0,
            'early_exit' => false,
            'start_time' => microtime(true)
        ];
        
        try {
            $this->logProgress("Starting incremental sync for user {$this->userId}");
            
            // ✅ ROLLING WINDOW: Ultimi N giorni (default 7)
            // Non usa più cursori fissi, sempre finestra mobile
            $windowDays = $options['window_days'] ?? 7;
            
            // Override manuale se from_date specificato (per test/backfill)
            if (isset($options['from_date'])) {
                $fromDate = $options['from_date'];
            } else {
                $fromDate = gmdate('Y-m-d\TH:i:s\Z', strtotime("-{$windowDays} days"));
            }
            
            $toDate = gmdate('Y-m-d\TH:i:s\Z');
            
            $this->logProgress("Date range: {$fromDate} → {$toDate} (rolling window: {$windowDays} days)");
            
            // Fetch shipments incrementali
            $shipmentsProcessed = 0;
            $nextToken = null;
            $pageCount = 0;
            
            // Dedup shipments cross-page
            $seenShipmentIds = [];
            
            // Anti-loop tracking
            $consecutiveZeroNew = 0;
            $maxConsecutiveZero = $options['max_consecutive_zero'] ?? 10; // Ottimizzato: 10 pagine duplicate → exit
            $prevPageHash = null;
            $pageHashRepeat = 0;
            
            do {
                $pageCount++;
                $summary['pages_fetched']++;
                
                // Check budget
                if ($this->apiCallsCount >= $this->apiCallsLimit) {
                    $this->logProgress("Budget API reached, stopping");
                    break;
                }
                
                // Check timeout
                if (microtime(true) - $this->runStartTime > $this->runTimeout) {
                    $this->logProgress("Timeout reached, stopping");
                    break;
                }
                
                // Update heartbeat
                $this->updateHeartbeat();
                
                // Fetch page
                $response = $this->getShipmentsV0($fromDate, $toDate, $nextToken);
                $payload = $response['payload'] ?? $response;
                $shipments = $payload['ShipmentData'] ?? [];
                $nextToken = $payload['NextToken'] ?? null;
                
                $this->logProgress("Page {$pageCount}: " . count($shipments) . " shipments");
                
                if (empty($shipments)) {
                    break;
                }
                
                // Dedup shipments
                $newShipments = [];
                $newCount = 0;
                foreach ($shipments as $ship) {
                    $shipId = $ship['ShipmentId'] ?? null;
                    if ($shipId && !isset($seenShipmentIds[$shipId])) {
                        $newShipments[] = $ship;
                        $seenShipmentIds[$shipId] = true;
                        $newCount++;
                    }
                }
                
                $this->logProgress("After dedup: {$newCount} unique shipments");
                
                // EARLY EXIT: N pagine consecutive senza nuovi ID → STOP
                if ($newCount === 0) {
                    $consecutiveZeroNew++;
                    
                    if ($consecutiveZeroNew >= $maxConsecutiveZero) {
                        $summary['pages_wasted'] = $consecutiveZeroNew;
                        $summary['early_exit'] = true;
                        
                        $this->logProgress("⚠️  Early-exit: {$consecutiveZeroNew} consecutive pages with 0 new IDs");
                        $this->logger->info('inventory', "INBOUND_V0_LIST_EARLY_EXIT", [
                            'user_id' => $this->userId,
                            'pages_wasted' => $consecutiveZeroNew,
                            'page_count' => $pageCount
                        ]);
                        break;
                    }
                } else {
                    $consecutiveZeroNew = 0;
                }
                
                // LOOP DETECTION: Hash pagina IDs
                if ($newCount > 0) {
                    $pageIds = array_map(fn($s) => $s['ShipmentId'] ?? '', $newShipments);
                    sort($pageIds);
                    $currentPageHash = hash('sha256', implode('|', $pageIds));
                    
                    if ($prevPageHash && $currentPageHash === $prevPageHash) {
                        $pageHashRepeat++;
                        
                        if ($pageHashRepeat >= 2) {
                            $this->logProgress("⚠️  Loop detected: identical page hash");
                            $this->logger->warning('inventory', "INBOUND_V0_LIST_PAGE_LOOP", [
                                'user_id' => $this->userId,
                                'page' => $pageCount,
                                'page_hash' => substr($currentPageHash, 0, 12)
                            ]);
                            break;
                        }
                    } else {
                        $pageHashRepeat = 0;
                    }
                    
                    $prevPageHash = $currentPageHash;
                }
                
                // Filtra shipments (arricchisce con metadata DB)
                $toProcess = $this->filterNewShipments($newShipments);
                
                $this->logProgress("To process: " . count($toProcess) . " shipments");
                
                // Process shipments
                foreach ($toProcess as $shipment) {
                    try {
                        $result = $this->processShipment($shipment);
                        
                        // Conta risultati (include skip!)
                        if ($result['status'] === 'complete') {
                            $summary['synced']++;
                        } elseif ($result['status'] === 'skipped') {
                            $summary['skipped']++;
                        } elseif (in_array($result['status'], ['partial_loop', 'partial_no_progress', 'partial'])) {
                            $summary['partial']++;
                        }
                        
                        $shipmentsProcessed++;
                        
                        // Check limite shipments
                        if ($shipmentsProcessed >= $this->shipmentsLimit) {
                            $this->logProgress("Shipments limit reached");
                            break 2; // Exit do-while
                        }
                        
                    } catch (Exception $e) {
                        $summary['errors']++;
                        $this->logger->error('inventory', "INBOUND_PROCESS_SHIPMENT_ERROR", [
                            'user_id' => $this->userId,
                            'shipment_id' => $shipment['ShipmentId'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Safety: max 50 pagine shipments list
                if ($pageCount >= 50) {
                    $this->logProgress("⚠️  Max pages (50) reached");
                    break;
                }
                
            } while ($nextToken);
            
            // ✅ Aggiorna SEMPRE last_run (anche se tutto skippato)
            // Non usiamo più last_cursor_utc per rolling window
            if (!$this->dryRun) {
                $this->updateSyncState(null, $summary);
            }
            
            // Reset circuit breaker su successo
            if ($summary['errors'] == 0) {
                $this->resetCircuitBreaker();
            } else {
                $this->incrementConsecutiveErrors();
            }
            
            $summary['duration'] = round(microtime(true) - $summary['start_time'], 2);
            $summary['api_calls'] = $this->apiCallsCount;
            
            // Log accorpato per spedizioni saltate per budget
            if (!empty($this->budgetSkippedShipments)) {
                $skippedCount = count($this->budgetSkippedShipments);
                $this->logger->warning('inventory', "INBOUND_BUDGET_EXHAUSTED_SUMMARY", [
                    'user_id' => $this->userId,
                    'shipments_skipped' => $skippedCount,
                    'api_calls_used' => $this->apiCallsCount,
                    'api_calls_limit' => $this->apiCallsLimit,
                    'shipment_ids' => array_slice(array_unique($this->budgetSkippedShipments), 0, 20) // Max 20 per log
                ]);
                $this->logProgress("⚠️  Budget API esaurito: {$skippedCount} spedizioni saltate");
            }
            
            $this->logProgress("Sync completed: {$summary['synced']} synced, {$summary['skipped']} skipped");
            
            return $summary;
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_SYNC_FAILED", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            
            $this->incrementConsecutiveErrors();
            throw $e;
        }
    }
    
    /**
     * Sweep Storico a Finestre con Jump & Probe
     * 
     * Risolve problema: LastUpdatedAfter=2020 non include vecchie spedizioni senza LastUpdatedDate
     * 
     * Strategia:
     * - Scansiona a finestre retrograde (90d default)
     * - Se 2 finestre vuote consecutive → JUMP: salta 12/24/36 mesi indietro
     * - Se jump trova dati → REFINE: raffina con finestre più piccole
     * - Stop solo se: min_date raggiunto OR tutti jump vuoti E distanza < 36m OR budget esaurito
     * 
     * @param array $options window_days, min_date, status_list
     * @return array Summary con statistiche
     */
    /**
     * Sync Storico COMPLETO: Sweep per stato con finestre fisse (30gg)
     * Niente early-exit, niente jump: scan esaustivo fino a min_date
     * 
     * @param array $options Opzioni: min_date, window_days, statuses, resume
     * @return array Summary con statistiche
     */
    public function syncHistoricalFull($options = []) {
        $summary = [
            'synced' => 0,
            'skipped' => 0,
            'partial' => 0,
            'errors' => 0,
            'windows_processed' => 0,
            'windows_empty' => 0,
            'api_calls' => 0,
            'start_time' => microtime(true)
        ];
        
        try {
            $minDateIso = $options['min_date'] ?? '2010-01-01T00:00:00Z';
            $windowDays = (int)($options['window_days'] ?? 30);
            $statuses = $options['statuses'] ?? ['CLOSED', 'RECEIVING', 'SHIPPED', 'WORKING', 'CANCELLED'];
            $resume = $options['resume'] ?? true;
            
            $this->logger->info('inventory', "INBOUND_HISTORICAL_SWEEP_START", [
                'user_id' => $this->userId,
                'min_date' => $minDateIso,
                'window_days' => $windowDays,
                'statuses' => implode(',', $statuses)
            ]);
            
            $this->logProgress("🔄 Starting HISTORICAL SWEEP (per stato, finestre {$windowDays}gg)");
            $this->logProgress("Min date: {$minDateIso}");
            $this->logProgress("Stati: " . implode(', ', $statuses));
            $this->logProgress("");
            
            // Leggi current_status se resume
            $startStatus = null;
            if ($resume) {
                $stmt = $this->db->prepare("
                    SELECT current_status FROM sync_state WHERE user_id = ?
                ");
                $stmt->execute([$this->userId]);
                $startStatus = $stmt->fetchColumn();
            }
            
            // Scorri stati in ordine
            $startProcessing = ($startStatus === null);
            
            foreach ($statuses as $status) {
                // Skip stati precedenti se resume
                if (!$startProcessing) {
                    if ($status === $startStatus) {
                        $startProcessing = true;
                    } else {
                        continue;
                    }
                }
                
                $this->currentHistoricalStatus = $status;
                $this->updateCurrentStatus($status);
                
                $this->logProgress("═══════════════════════════════════════");
                $this->logProgress("📦 STATUS: {$status}");
                $this->logProgress("═══════════════════════════════════════");
                
                // Cursore per questo stato
                $cursorTo = $resume 
                    ? ($this->getHistoricCursor($status) ?? gmdate('Y-m-d\TH:i:s\Z'))
                    : gmdate('Y-m-d\TH:i:s\Z');
                
                $statusWindows = 0;
                $statusNewIds = 0;
                
                while ($this->budgetOk() && strtotime($cursorTo) > strtotime($minDateIso)) {
                    $fromIso = gmdate('Y-m-d\TH:i:s\Z', strtotime($cursorTo . " -{$windowDays} days"));
                    $statusWindows++;
                    $summary['windows_processed']++;
                    
                    $this->logProgress("[" . date('H:i:s') . "] STATUS={$status} | Window {$statusWindows} | {$fromIso} → {$cursorTo}");
                    
                    // Process finestra (con loop-guard)
                    $result = $this->processWindowStrict($status, $fromIso, $cursorTo);
                    
                    $statusNewIds += $result['new_ids'];
                    $summary['synced'] += $result['new_ids'];
                    
                    if ($result['new_ids'] === 0) {
                        $summary['windows_empty']++;
                    }
                    
                    // Avanza sempre la finestra (no early-exit)
                    $cursorTo = $fromIso;
                    $this->setHistoricCursor($status, $cursorTo);
                    
                    // Progress report arricchito
                    $elapsed = round(microtime(true) - $this->runStartTime);
                    $eta = $this->apiCallsCount > 0 
                        ? round(($this->apiCallsLimit - $this->apiCallsCount) * ($elapsed / $this->apiCallsCount))
                        : 0;
                    
                    $this->logProgress("  New IDs={$result['new_ids']} | Pages={$result['pages']} | Budget {$this->apiCallsCount}/{$this->apiCallsLimit} | ETA ~{$eta}s");
                    
                    if (!$this->budgetOk()) {
                        $reason = $this->apiCallsCount >= $this->apiCallsLimit ? 'budget' : 'timeout';
                        $this->logger->info('inventory', "INBOUND_HISTORICAL_BUDGET_STOP", [
                            'user_id' => $this->userId,
                            'reason' => $reason,
                            'status' => $status,
                            'cursor' => $cursorTo
                        ]);
                        $this->logProgress("  ⚠️  {$reason} reached, stopping");
                        break 2; // Exit tutti i loop
                    }
                }
                
                // Stato completato
                $this->logger->info('inventory', "INBOUND_HISTORICAL_STATUS_DONE", [
                    'user_id' => $this->userId,
                    'status' => $status,
                    'windows' => $statusWindows,
                    'new_ids' => $statusNewIds
                ]);
                
                $this->logProgress("✅ {$status} done: {$statusWindows} windows, {$statusNewIds} new IDs");
                $this->logProgress("");
            }
            
            // Reset current_status quando finito tutto
            $this->updateCurrentStatus(null);
            
            $summary['duration'] = round(microtime(true) - $summary['start_time'], 2);
            $summary['api_calls'] = $this->apiCallsCount;
            
            $this->logProgress("═══════════════════════════════════════");
            $this->logProgress("📊 SWEEP COMPLETED");
            $this->logProgress("═══════════════════════════════════════");
            $this->logProgress("Windows: {$summary['windows_processed']} ({$summary['windows_empty']} empty)");
            $this->logProgress("New shipments: {$summary['synced']}");
            $this->logProgress("API calls: {$summary['api_calls']}");
            $this->logProgress("Duration: {$summary['duration']}s");
            
            // Log accorpato per spedizioni saltate per budget
            if (!empty($this->budgetSkippedShipments)) {
                $skippedCount = count($this->budgetSkippedShipments);
                $this->logger->warning('inventory', "INBOUND_BUDGET_EXHAUSTED_SUMMARY", [
                    'user_id' => $this->userId,
                    'shipments_skipped' => $skippedCount,
                    'api_calls_used' => $this->apiCallsCount,
                    'api_calls_limit' => $this->apiCallsLimit,
                    'shipment_ids' => array_slice(array_unique($this->budgetSkippedShipments), 0, 20) // Max 20 per log
                ]);
                $this->logProgress("⚠️  Budget API esaurito: {$skippedCount} spedizioni saltate");
                $this->logProgress("");
            }
            
            return $summary;
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_HISTORICAL_SWEEP_FAILED", [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Aggiorna current_status in sync_state (per resume)
     * 
     * @param string|null $status Stato corrente o null quando completato
     */
    private function updateCurrentStatus($status) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sync_state (user_id, current_status, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    current_status = VALUES(current_status),
                    updated_at = NOW()
            ");
            $stmt->execute([$this->userId, $status]);
        } catch (Exception $e) {
            // Non bloccare
        }
    }
    
    /**
     * Process finestra singola con loop-guard per paginazione
     * 
     * @param string $status Status spedizione
     * @param string $fromIso Data inizio finestra (ISO UTC)
     * @param string $toIso Data fine finestra (ISO UTC)
     * @return array ['new_ids' => int, 'pages' => int]
     */
    private function processWindowStrict($status, $fromIso, $toIso) {
        $seenIdsWindow = [];
        $seenTokens = [];
        $prevHash = null;
        $newIds = 0;
        $page = 0;
        $nextToken = null;
        $loopDetected = false; // Track loop without logging each time
        
        do {
            $page++;
            
            // Chiamata API
            $opts = [
                'LastUpdatedAfter' => $fromIso,
                'LastUpdatedBefore' => $toIso,
                'ShipmentStatusList' => [$status],
                'NextToken' => $nextToken
            ];
            
            $resp = $this->getShipmentsV0($opts);
            $this->bumpBudget();
            
            $payload = $resp['payload'] ?? $resp;
            $shipments = $payload['ShipmentData'] ?? [];
            $nextToken = $payload['NextToken'] ?? null;
            
            // Extract IDs
            $ids = array_column($shipments, 'ShipmentId');
            $idsHash = hash('sha256', implode('|', $ids));
            
            // Loop-guard: token ripetuto
            if ($nextToken && in_array($nextToken, $seenTokens, true)) {
                $loopDetected = true;
                break;
            }
            
            // Loop-guard: hash identico
            if ($prevHash && $prevHash === $idsHash) {
                $loopDetected = true;
                break;
            }
            
            $seenTokens[] = $nextToken ?? 'PAGE_' . $page;
            $prevHash = $idsHash;
            
            // Dedup & process IDs
            foreach ($shipments as $ship) {
                $amazonId = $ship['ShipmentId'] ?? null;
                if (!$amazonId || in_array($amazonId, $seenIdsWindow, true)) {
                    continue;
                }
                
                $seenIdsWindow[] = $amazonId;
                
                // Check se visto in questa run (cross-window dedup)
                if (in_array($amazonId, $this->seenIdsRun, true)) {
                    continue;
                }
                
                $this->seenIdsRun[] = $amazonId;
                
                // Handle shipment (new o existing)
                $result = $this->handleShipmentRecord($amazonId, $ship);
                
                if ($result === 'new') {
                    $newIds++;
                }
                
                // Flush dedup se soglia superata
                if (count($this->seenIdsRun) >= $this->seenIdsFlushThreshold) {
                    // TODO: Flush a DB (future enhancement)
                    // Per ora manteniamo solo in-memory
                }
            }
            
            // Check budget
            if (!$this->budgetOk()) {
                break;
            }
            
        } while ($nextToken);
        
        // Condensed summary log (ONLY if there's something interesting to report)
        if ($newIds > 0 || $loopDetected) {
            $this->logger->info('inventory', "INBOUND_WINDOW_SUMMARY", [
                'user_id' => $this->userId,
                'status' => $status,
                'window' => $fromIso . ' → ' . $toIso,
                'new_shipments' => $newIds,
                'pages' => $page,
                'total_ids_seen' => count($seenIdsWindow),
                'loop_detected' => $loopDetected
            ]);
        }
        
        return ['new_ids' => $newIds, 'pages' => $page];
    }
    
    /**
     * Handle singolo shipment record (wrapper processShipment)
     * Decide se NEW (fetch full) o EXISTING (fingerprint probe)
     * 
     * @param string $amazonShipmentId ID Amazon
     * @param array $header Header spedizione da API
     * @return string 'new'|'exist_skip'|'exist_header_only'|'exist_full'
     */
    private function handleShipmentRecord($amazonShipmentId, $header) {
        try {
            // Check se esiste in DB
            $stmt = $this->db->prepare("
                SELECT s.id, ss.shipment_fingerprint, ss.items_fingerprint, ss.immutable
                FROM inbound_shipments s
                LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
                WHERE s.user_id = ? AND s.amazon_shipment_id = ?
            ");
            $stmt->execute([$this->userId, $amazonShipmentId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                // NEW: Arricchisci header e processa (fingerprint + full items)
                $header['_is_new'] = true;
                $result = $this->processShipment($header);
                
                return 'new';
            }
            
            // EXISTING: Usa logica fingerprint esistente
            // Arricchisci header con metadata DB
            $header['_is_new'] = false;
            $header['_db_id'] = $existing['id'];
            $header['_fingerprint_header'] = $existing['shipment_fingerprint'];
            $header['_fingerprint_items'] = $existing['items_fingerprint'];
            $header['_immutable'] = (bool)$existing['immutable'];
            
            // processShipment decide automaticamente (probe/full/skip)
            $result = $this->processShipment($header);
            
            // Traduci result
            if ($result === 'skip') {
                return 'exist_skip';
            } elseif ($result === 'header_only') {
                return 'exist_header_only';
            } else {
                return 'exist_full';
            }
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "HANDLE_SHIPMENT_FAILED", [
                'user_id' => $this->userId,
                'amazon_shipment_id' => $amazonShipmentId,
                'error' => $e->getMessage()
            ]);
            
            return 'error';
        }
    }
    
    // ================================================================
    // METODI DEPRECATI (mantenuti per compatibility, non usati da sweep)
    // ================================================================
    
    /**
     * Jump & Probe: salta 12/24/36 mesi indietro cercando dati
     * @deprecated Usato solo da vecchio syncRebuildHistorical
     */
    private function jumpAndProbe($cursor, $minDate, $statusList, &$summary) {
        $jumpMonths = [12, 24, 36];
        
        foreach ($jumpMonths as $months) {
            $summary['jumps_executed']++;
            
            // Check budget
            if ($this->apiCallsCount >= $this->apiCallsLimit) {
                return ['found' => false, 'cursor' => $cursor, 'coverage_months' => 0];
            }
            
            // Calcola finestra jump (90d centered)
            $jumpTo = $cursor->sub(new DateInterval('P' . ($months * 30) . 'D'));
            
            // Stop se oltre min_date
            if ($jumpTo < $minDate) {
                break;
            }
            
            $jumpFrom = $jumpTo->sub(new DateInterval('P90D'));
            
            $this->logProgress("  🔍 Jump {$months}m: {$jumpFrom->format('Y-m-d')} → {$jumpTo->format('Y-m-d')}");
            
            // BROAD window (no After)
            $opts = [
                'LastUpdatedBefore' => $jumpTo->format('Y-m-d\TH:i:s\Z'),
                'ShipmentStatusList' => $statusList
            ];
            
            $found = $this->processWindow($opts, $summary);
            
            if ($found > 0) {
                $this->logger->info('inventory', 'HIST_JUMP_SUCCESS', [
                    'user_id' => $this->userId,
                    'jump_months' => $months,
                    'found' => $found,
                    'jump_from' => $jumpFrom->format('Y-m-d'),
                    'jump_to' => $jumpTo->format('Y-m-d')
                ]);
                
                return [
                    'found' => true,
                    'cursor' => $jumpTo,
                    'coverage_months' => (int)($months * 0.3) // Stima coverage
                ];
            }
        }
        
        $this->logger->info('inventory', 'HIST_JUMP_EXHAUSTED', [
            'user_id' => $this->userId,
            'jumps_tried' => count($jumpMonths)
        ]);
        
        return ['found' => false, 'cursor' => $cursor, 'coverage_months' => 0];
    }
    
    /**
     * Load historical rebuild state
     */
    private function loadHistoricalState() {
        $stmt = $this->db->prepare("
            SELECT historical_cursor_to, last_nonempty_to, empty_windows_streak, 
                   coverage_months, jump_factor
            FROM sync_state
            WHERE user_id = ?
        ");
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ?: [
            'historical_cursor_to' => null,
            'last_nonempty_to' => null,
            'empty_windows_streak' => 0,
            'coverage_months' => 0,
            'jump_factor' => 0
        ];
    }
    
    /**
     * Save historical rebuild state
     */
    private function saveHistoricalState($cursor, $emptyStreak, $coverageMonths) {
        if ($this->dryRun) {
            return;
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO sync_state 
            (user_id, historical_cursor_to, empty_windows_streak, coverage_months, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                historical_cursor_to = VALUES(historical_cursor_to),
                empty_windows_streak = VALUES(empty_windows_streak),
                coverage_months = VALUES(coverage_months),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $this->userId,
            $cursor->format('Y-m-d H:i:s'),
            $emptyStreak,
            $coverageMonths
        ]);
    }
    
    /**
     * Calcola mesi tra due date
     */
    private function monthsDiff($from, $to) {
        $diff = $from->diff($to);
        return ($diff->y * 12) + $diff->m;
    }
    
    /**
     * Processa una finestra temporale: fetch + dedup + process
     * 
     * @return int Numero shipments nuovi trovati
     */
    private function processWindow($opts, &$summary) {
        $uniqueNew = 0;
        $seenIds = [];
        $nextToken = null;
        $noNewPages = 0;
        $pageCount = 0;
        
        do {
            $pageCount++;
            
            // Fetch page
            $response = $this->getShipmentsV0($nextToken ? ['NextToken' => $nextToken] : $opts);
            $list = $response['payload']['Shipments'] ?? $response['payload']['ShipmentData'] ?? [];
            $nextToken = $response['payload']['NextToken'] ?? null;
            
            // Dedup in-memory
            $ids = [];
            foreach ($list as $s) {
                $sid = $s['ShipmentId'] ?? null;
                if ($sid && !isset($seenIds[$sid])) {
                    $seenIds[$sid] = true;
                    $ids[] = $sid;
                }
            }
            
            if (empty($ids)) {
                $noNewPages++;
                if ($noNewPages >= 3) {
                    $this->logProgress("    ⏭ Early-exit: 3 pages without new IDs");
                    break;
                }
                continue;
            } else {
                $noNewPages = 0;
            }
            
            // Check contro DB
            $newForDb = $this->filterNewShipmentsById($ids);
            
            if (empty($newForDb)) {
                continue;
            }
            
            // FIX BUG ITEMS=0: Arricchisci shipments con metadata DB
            // prima di processarli (serve per decision tree in processShipment)
            $shipmentsToProcess = [];
            foreach ($list as $ship) {
                $sid = $ship['ShipmentId'] ?? null;
                if ($sid && in_array($sid, $newForDb, true)) {
                    $shipmentsToProcess[] = $ship;
                }
            }
            
            // Arricchisci con filterNewShipments (aggiunge _is_new, _db_id, fingerprints)
            $enrichedShipments = $this->filterNewShipments($shipmentsToProcess);
            
            // Process shipments enriched
            foreach ($enrichedShipments as $ship) {
                try {
                    $result = $this->processShipment($ship);
                        
                        if ($result['status'] === 'complete') {
                            $summary['synced']++;
                            $uniqueNew++;
                        } elseif ($result['status'] === 'skipped') {
                            $summary['skipped']++;
                        } elseif (in_array($result['status'], ['partial_loop', 'partial_no_progress', 'partial'])) {
                            $summary['partial']++;
                        }
                        
                } catch (Exception $e) {
                    $summary['errors']++;
                    $this->logger->error('inventory', "INBOUND_REBUILD_PROCESS_ERROR", [
                        'user_id' => $this->userId,
                        'shipment_id' => $ship['ShipmentId'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Budget check
            if ($this->apiCallsCount >= $this->apiCallsLimit) {
                break;
            }
            
        } while ($nextToken);
        
        return $uniqueNew;
    }
    
    /**
     * Helper: ritorna solo shipments che NON esistono in DB
     */
    private function filterNewShipmentsById($amazonIds) {
        if (empty($amazonIds)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($amazonIds), '?'));
        $stmt = $this->db->prepare("
            SELECT amazon_shipment_id 
            FROM inbound_shipments 
            WHERE user_id = ? AND amazon_shipment_id IN ($placeholders)
        ");
        
        $params = array_merge([$this->userId], $amazonIds);
        $stmt->execute($params);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return array_values(array_diff($amazonIds, $existing));
    }
    
    /**
     * Arricchisce shipments con flag is_new (NON filtra)
     * Decision tree in processShipment() decide cosa fare
     */
    private function filterNewShipments($shipments) {
        if (empty($shipments)) {
            return [];
        }
        
        // Estrai IDs
        $shipmentIds = array_map(function($s) {
            return $s['ShipmentId'];
        }, $shipments);
        
        // Query DB: shipments esistenti con fingerprint
        // ✅ ESCLUDI spedizioni MANUAL (importate manualmente, non da API)
        $placeholders = implode(',', array_fill(0, count($shipmentIds), '?'));
        $stmt = $this->db->prepare("
            SELECT 
                s.amazon_shipment_id,
                s.id AS shipment_db_id,
                s.shipment_status,
                ss.shipment_fingerprint,
                ss.items_fingerprint,
                ss.immutable
            FROM inbound_shipments s
            LEFT JOIN shipment_sync_state ss ON ss.shipment_id = s.id
            WHERE s.user_id = ? 
              AND s.amazon_shipment_id IN ($placeholders)
              AND s.shipment_status != 'MANUAL'
        ");
        
        $params = array_merge([$this->userId], $shipmentIds);
        $stmt->execute($params);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Mappa: shipment_id => DB data
        $existingMap = [];
        foreach ($existing as $row) {
            $existingMap[$row['amazon_shipment_id']] = $row;
        }
        
        // Arricchisci con metadata (NON filtrare!)
        $enriched = [];
        foreach ($shipments as $shipment) {
            $shipmentId = $shipment['ShipmentId'];
            
            if (!isset($existingMap[$shipmentId])) {
                // Nuovo
                $shipment['_is_new'] = true;
                $shipment['_db_id'] = null;
                $shipment['_immutable'] = false;
            } else {
                // Esistente
                $dbData = $existingMap[$shipmentId];
                $shipment['_is_new'] = false;
                $shipment['_db_id'] = $dbData['shipment_db_id'];
                $shipment['_fingerprint_header'] = $dbData['shipment_fingerprint'];
                $shipment['_fingerprint_items'] = $dbData['items_fingerprint'];
                $shipment['_immutable'] = (bool)$dbData['immutable'];
            }
            
            $enriched[] = $shipment;
        }
        
        return $enriched;
    }
    
    // ===================================================================
    // SEZIONE 8: PROCESS SHIPMENT & ANTI-LOOP
    // (File continua oltre il limite di lunghezza - parte 2 da aggiungere separatamente)
    // ===================================================================
    
    /**
     * Process Shipment Manuale (public wrapper per import CSV/TXT)
     * 
     * @param array $shipmentData Shipment data from manual import
     * @return array Status: complete|partial + dettagli
     */
    public function processManualShipment($shipmentData) {
        // Wrapper pubblico per import manuale
        return $this->processShipment($shipmentData);
    }
    
    /**
     * Processa singolo shipment: header + items con anti-loop
     * 
     * @param array $shipmentData Shipment data da API
     * @return array Status: complete|partial + dettagli
     */
    private function processShipment($shipmentData) {
        $shipmentId = $shipmentData['ShipmentId'];
        $shipmentStartTime = microtime(true);
        $reason = 'unknown';
        
        try {
            // Estrai metadata da filterNewShipments()
            $isNew = $shipmentData['_is_new'] ?? false;
            $dbId = $shipmentData['_db_id'] ?? null;
            $immutable = $shipmentData['_immutable'] ?? false;
            $oldFpHeader = $shipmentData['_fingerprint_header'] ?? null;
            $oldFpItems = $shipmentData['_fingerprint_items'] ?? null;
            
            // ✅ PROTEZIONE: Skip spedizioni MANUAL (importate manualmente, non da API)
            if (!$isNew && $dbId) {
                $stmt = $this->db->prepare("SELECT shipment_status FROM inbound_shipments WHERE id = ?");
                $stmt->execute([$dbId]);
                $currentStatus = $stmt->fetchColumn();
                
                if ($currentStatus === 'MANUAL') {
                    $this->logProgress("⏭ SKIP (manual import): {$shipmentId}");
                    return [
                        'status' => 'skipped',
                        'reason' => 'manual_import',
                        'items_count' => 0,
                        'duration_ms' => 0
                    ];
                }
            }
            
            // Calcola fingerprint header corrente
            $newFpHeader = $this->headerFingerprint($shipmentData);
            
            // ========================================
            // DECISION TREE (5 livelli)
            // ========================================
            
            // LIVELLO 1: NUOVO → Full fetch
            if ($isNew) {
                $reason = 'new';
                $shipmentDbId = $this->dryRun ? 0 : $this->upsertShipment($shipmentData);
                $itemsResult = $this->processShipmentItems($shipmentId, $shipmentDbId, true); // full_fetch=true
                
                if (!$this->dryRun && $shipmentDbId) {
                    $fpItems = $this->itemsFingerprint($itemsResult['items'] ?? []);
                    $this->updateShipmentFingerprints($shipmentDbId, $newFpHeader, $fpItems);
                    
                    // Aggiungi nota boxes v0 se complete
                    $note = $itemsResult['note'] ?? null;
                    if ($itemsResult['status'] === 'complete' && empty($note)) {
                        $note = 'feature_unavailable: boxes_v0';
                    }
                    
                    $this->updateShipmentSyncState($shipmentDbId, $itemsResult['status'], $note);
                    $this->maybeToggleImmutable($shipmentDbId, $shipmentData['ShipmentStatus'] ?? '');
                }
                
                return [
                    'status' => $itemsResult['status'],
                    'items_count' => $itemsResult['items_count'] ?? 0,
                    'reason' => $reason,
                    'duration_ms' => round((microtime(true) - $shipmentStartTime) * 1000, 2)
                ];
            }
            
            // LIVELLO 2: IMMUTABLE → Skip totale
            if ($immutable) {
                $reason = 'immutable';
                $this->logProgress("  ⏭ SKIP (immutable): {$shipmentId}");
                return [
                    'status' => 'skipped',
                    'reason' => $reason,
                    'items_count' => 0,
                    'duration_ms' => 0
                ];
            }
            
            // LIVELLO 3: Header invariato → Skip (0 API calls)
            if ($oldFpHeader && $oldFpHeader === $newFpHeader) {
                $reason = 'header_unchanged';
                $this->logProgress("  ⏭ SKIP (header unchanged): {$shipmentId}");
                return [
                    'status' => 'skipped',
                    'reason' => $reason,
                    'items_count' => 0,
                    'duration_ms' => 0
                ];
            }
            
            // LIVELLO 4: Header cambiato → Probe page 1
            $reason = 'header_changed';
            $shipmentDbId = $dbId;
            
            $probeResult = $this->probeItemsPage1($shipmentId);
            $probeFp = $probeResult['fingerprint'];
            $hasMore = $probeResult['has_more'];
            
            // LIVELLO 5a: Items probe = items_fp_full → Update solo header
            if ($oldFpItems && $probeFp === $oldFpItems && !$hasMore) {
                $reason = 'header_only';
                
                if (!$this->dryRun) {
                    $shipmentDbId = $this->upsertShipment($shipmentData);  // ✅ FIX: Assegna il DB ID!
                    $this->updateShipmentFingerprints($shipmentDbId, $newFpHeader, $oldFpItems);
                    // Mantieni sync_status esistente (già complete)
                    $this->maybeToggleImmutable($shipmentDbId, $shipmentData['ShipmentStatus'] ?? '');
                }
                
                $this->logProgress("  ⚡ UPDATE header only: {$shipmentId}");
                return [
                    'status' => 'complete',
                    'reason' => $reason,
                    'items_count' => 0, // Non toccati
                    'duration_ms' => round((microtime(true) - $shipmentStartTime) * 1000, 2)
                ];
            }
            
            // LIVELLO 5b: Items cambiati → Full fetch
            $reason = 'full_update';
            
            if (!$this->dryRun) {
                $shipmentDbId = $this->upsertShipment($shipmentData);  // ✅ FIX: Assegna il DB ID!
            }
            
            // Check if manual import (with pre-provided items)
            if (isset($shipmentData['_manual_items']) && is_array($shipmentData['_manual_items'])) {
                $itemsResult = $this->processManualItems($shipmentId, $shipmentDbId, $shipmentData['_manual_items']);
            } else {
                $itemsResult = $this->processShipmentItems($shipmentId, $shipmentDbId, true); // full_fetch=true
            }
            
            if (!$this->dryRun && $shipmentDbId) {
                $fpItems = $this->itemsFingerprint($itemsResult['items'] ?? []);
                $this->updateShipmentFingerprints($shipmentDbId, $newFpHeader, $fpItems);
                
                // Aggiungi nota boxes v0 se complete
                $note = $itemsResult['note'] ?? null;
                if ($itemsResult['status'] === 'complete' && empty($note)) {
                    $note = 'feature_unavailable: boxes_v0';
                }
                
                $this->updateShipmentSyncState($shipmentDbId, $itemsResult['status'], $note);
                $this->maybeToggleImmutable($shipmentDbId, $shipmentData['ShipmentStatus'] ?? '');
            }
            
            $this->logProgress("  ♻️ FULL update: {$shipmentId}");
            return [
                'status' => $itemsResult['status'],
                'items_count' => $itemsResult['items_count'] ?? 0,
                'reason' => $reason,
                'duration_ms' => round((microtime(true) - $shipmentStartTime) * 1000, 2)
            ];
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_PROCESS_SHIPMENT_FAILED", [
                'user_id' => $this->userId,
                'shipment_id' => $shipmentId,
                'reason' => $reason,
                'error' => $e->getMessage()
            ]);
            
            return ['status' => 'partial', 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Upsert shipment header (idempotenza garantita da UNIQUE key)
     */
    private function upsertShipment($shipmentData) {
        $data = [
            'user_id' => $this->userId,
            'amazon_shipment_id' => $shipmentData['ShipmentId'],
            'inbound_plan_id' => null, // v0 non ha plan ID
            'shipment_name' => $shipmentData['ShipmentName'] ?? null,
            'destination_fc' => $shipmentData['DestinationFulfillmentCenterId'] ?? null,
            'shipment_status' => $shipmentData['ShipmentStatus'] ?? null,
            'workflow_version' => 'v0',
            'is_partnered' => isset($shipmentData['IsPartnered']) ? (int)$shipmentData['IsPartnered'] : 0,
            'label_prep_type' => $shipmentData['LabelPrepType'] ?? null,
            'are_cases_required' => isset($shipmentData['AreCasesRequired']) ? (int)$shipmentData['AreCasesRequired'] : 0,
            'confirmed_need_by_date' => $shipmentData['ConfirmedNeedByDate'] ?? null,
            'box_contents_source' => $shipmentData['BoxContentsSource'] ?? null,
            'shipment_created_date' => $shipmentData['CreatedDate'] ?? null,
            'last_updated_date' => $shipmentData['LastUpdatedDate'] ?? null,
            'last_sync_at' => date('Y-m-d H:i:s')
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO inbound_shipments 
            (user_id, amazon_shipment_id, inbound_plan_id, shipment_name, destination_fc, 
             shipment_status, workflow_version, is_partnered, label_prep_type, are_cases_required, 
             confirmed_need_by_date, box_contents_source, shipment_created_date, last_updated_date, last_sync_at) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                shipment_name = VALUES(shipment_name),
                destination_fc = VALUES(destination_fc),
                shipment_status = VALUES(shipment_status),
                last_updated_date = VALUES(last_updated_date),
                last_sync_at = VALUES(last_sync_at)
        ");
        
        $stmt->execute(array_values($data));
        
        // Get inserted/updated ID
        $shipmentDbId = $this->db->lastInsertId();
        if (!$shipmentDbId) {
            $stmt = $this->db->prepare("SELECT id FROM inbound_shipments WHERE amazon_shipment_id = ? AND user_id = ?");
            $stmt->execute([$data['amazon_shipment_id'], $this->userId]);
            $shipmentDbId = $stmt->fetchColumn();
        }
        
        return $shipmentDbId;
    }
    
    /**
     * Process items da import manuale (CSV/TXT)
     * 
     * @param string $shipmentId Amazon shipment ID
     * @param int|null $shipmentDbId DB shipment ID
     * @param array $manualItems Array di items già parseati
     * @return array ['status', 'items_count', 'items' => [...]]
     */
    private function processManualItems($shipmentId, $shipmentDbId, $manualItems) {
        $itemsCount = 0;
        
        $this->logger->info('inventory', "INBOUND_MANUAL_IMPORT_ITEMS", [
            'user_id' => $this->userId,
            'shipment_id' => $shipmentId,
            'items_count' => count($manualItems),
            'shipment_db_id' => $shipmentDbId,
            'dry_run' => $this->dryRun
        ]);
        
        try {
            if (!$shipmentDbId) {
                throw new Exception("shipmentDbId is NULL - cannot save items!");
            }
            
            if (!$this->dryRun && $shipmentDbId) {
                // Upsert items (idempotente grazie a UNIQUE key shipment_id + seller_sku)
                foreach ($manualItems as $item) {
                    $this->upsertItem($shipmentDbId, $item);  // ✅ FIX: Nome metodo corretto
                    $itemsCount++;
                }
            }
            
            return [
                'status' => 'complete',
                'items_count' => $itemsCount,
                'items' => $manualItems,
                'note' => 'manual_import'
            ];
            
        } catch (Exception $e) {
            $this->logger->error('inventory', "INBOUND_MANUAL_IMPORT_FAILED", [
                'user_id' => $this->userId,
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'status' => 'partial_no_progress',
                'items_count' => $itemsCount,
                'items' => [],
                'note' => 'manual_import_error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Process items con anti-loop detection completa (v2 - fingerprint strategy)
     * 
     * @param string $shipmentId Amazon shipment ID
     * @param int|null $shipmentDbId DB shipment ID
     * @param bool $fullFetch Se true, scarica tutte le pagine. Se false, solo probe page 1.
     * @return array ['status', 'items_count', 'items' => [...], 'note' => ...]
     */
    private function processShipmentItems($shipmentId, $shipmentDbId, $fullFetch = true) {
        $itemsCount = 0;
        $nextToken = null;
        $pageCount = 0;
        
        // Anti-loop trackers (basato su policy stati & loop)
        $previousTokens = [];
        $previousHashes = [];
        $seenSkus = [];
        $allItems = []; // Per calcolare fingerprint full
        $consecutiveEmptyPages = 0;
        
        $shipmentStartTime = microtime(true);
        
        // DEBUG LOG: Items call start (solo se budget disponibile)
        if ($this->apiCallsCount < $this->apiCallsLimit) {
            $this->logger->debug('inventory', 'INBOUND_ITEMS_CALL_START', [
                'user_id' => $this->userId,
                'shipment_id' => $shipmentId,
                'shipment_db_id' => $shipmentDbId,
                'full_fetch' => $fullFetch
            ]);
        }
        
        try {
            do {
                $pageCount++;
                
                // SAFETY 1: Max pages
                // Se troppi page → BREAK, poi decidiamo status in base a items salvati
                if ($pageCount > $this->maxPagesPerShipment) {
                    $this->logger->warning('inventory', "INBOUND_V0_MAX_PAGES_EXCEEDED", [
                        'user_id' => $this->userId,
                        'shipment_id' => $shipmentId,
                        'page' => $pageCount,
                        'pages_limit' => $this->maxPagesPerShipment,
                        'items_saved_so_far' => $itemsCount
                    ]);
                    
                    // BREAK loop, non return → decidiamo status dopo in base a items
                    break;
                }
                
                // SAFETY 2: Max duration per shipment
                // Se timeout → BREAK, poi decidiamo status in base a items salvati
                $elapsed = microtime(true) - $shipmentStartTime;
                if ($elapsed > $this->maxDurationPerShipment) {
                    $this->logger->warning('inventory', "INBOUND_V0_TIMEOUT_EXCEEDED", [
                        'user_id' => $this->userId,
                        'shipment_id' => $shipmentId,
                        'page' => $pageCount,
                        'duration_ms' => round($elapsed * 1000, 2),
                        'items_saved_so_far' => $itemsCount
                    ]);
                    
                    // BREAK loop, non return → decidiamo status dopo in base a items
                    break;
                }
                
                // Update heartbeat
                $this->updateHeartbeat();
                
                // Fetch items page
                $oldToken = $nextToken;
                $response = $this->getShipmentItemsV0($shipmentId, $nextToken);
                
                $payload = $response['payload'] ?? $response;
                $items = $payload['ItemData'] ?? [];
                $newToken = $payload['NextToken'] ?? null;
                $hasNext = !empty($newToken);
                
                // Calcola page_hash PRIMA del dedup (basato su dati raw)
                $pageNormalized = $this->normalizePageForHash($items);
                $pageHash = hash('sha256', $pageNormalized);
                
                // Dedup SKU in-memory
                $itemsBeforeDedup = count($items);
                $newItems = [];
                $duplicateItems = 0;
                
                foreach ($items as $item) {
                    $sku = $item['SellerSKU'] ?? null;
                    
                    if (!$sku || !isset($seenSkus[$sku])) {
                        $newItems[] = $item;
                        if ($sku) {
                            $seenSkus[$sku] = true;
                        }
                    } else {
                        $duplicateItems++;
                    }
                }
                
                $items = $newItems;
                $itemsNew = count($items);
                
                // Log strutturato per pagina (telemetria)
                $this->logger->debug('inventory', "INBOUND_V0_ITEMS_PAGE", [
                    'user_id' => $this->userId,
                    'shipment_id' => $shipmentId,
                    'page' => $pageCount,
                    'next_token_preview' => $newToken ? substr($newToken, 0, 20) . '...' : null,
                    'page_hash' => substr($pageHash, 0, 12),
                    'items_raw' => $itemsBeforeDedup,
                    'items_new' => $itemsNew,
                    'items_duplicate' => $duplicateItems,
                    'has_next_token' => $hasNext
                ]);
                
                // SAFETY 3: Token duplicato (loop deterministico)
                // Se token ripetuto → BREAK, poi decidiamo status in base a items salvati
                if ($newToken && in_array($newToken, $previousTokens, true)) {
                    $this->logger->warning('inventory', "INBOUND_V0_TOKEN_LOOP_DETECTED", [
                        'user_id' => $this->userId,
                        'shipment_id' => $shipmentId,
                        'page' => $pageCount,
                        'items_saved_so_far' => $itemsCount
                    ]);
                    
                    // BREAK loop, non return → decidiamo status dopo in base a items
                    break;
                }
                
                // SAFETY 4: Token stuck (non cambia)
                // Se token identico al precedente → BREAK, poi decidiamo status in base a items salvati
                if ($newToken && $newToken === $oldToken) {
                    $this->logger->warning('inventory', "INBOUND_V0_TOKEN_STUCK_DETECTED", [
                        'user_id' => $this->userId,
                        'shipment_id' => $shipmentId,
                        'page' => $pageCount,
                        'items_saved_so_far' => $itemsCount
                    ]);
                    
                    // BREAK loop, non return → decidiamo status dopo in base a items
                    break;
                }
                
                // SAFETY 5: Hash pagina identico (loop su contenuto)
                // Se hash ripetuto → BREAK, poi decidiamo status in base a items salvati
                $hashOccurrences = count(array_filter($previousHashes, fn($h) => $h === $pageHash));
                
                if ($hashOccurrences >= 2) {
                    $this->logger->warning('inventory', "INBOUND_V0_HASH_LOOP_DETECTED", [
                        'user_id' => $this->userId,
                        'shipment_id' => $shipmentId,
                        'page' => $pageCount,
                        'hash_occurrences' => $hashOccurrences + 1,
                        'items_saved_so_far' => $itemsCount
                    ]);
                    
                    // BREAK loop, non return → decidiamo status dopo in base a items
                    break;
                }
                
                // SAFETY 6: Pagine 100% duplicate consecutive
                // Se troppe pagine duplicate → BREAK, poi decidiamo status in base a items salvati
                if ($itemsNew === 0 && $itemsBeforeDedup > 0) {
                    $consecutiveEmptyPages++;
                    
                    if ($consecutiveEmptyPages >= 3) {
                        $this->logger->warning('inventory', "INBOUND_V0_CONSEC_DUP_DETECTED", [
                            'user_id' => $this->userId,
                            'shipment_id' => $shipmentId,
                            'consec_dup' => $consecutiveEmptyPages,
                            'items_saved_so_far' => $itemsCount
                        ]);
                        
                        // BREAK loop, non return → decidiamo status dopo in base a items
                        break;
                    }
                } else {
                    $consecutiveEmptyPages = 0;
                }
                
                // Track token e hash visti
                if ($newToken) {
                    $previousTokens[] = $newToken;
                }
                $previousHashes[] = $pageHash;
                
                // Upsert items (solo se non dry-run)
                if (!$this->dryRun && $shipmentDbId) {
                    foreach ($items as $item) {
                        $this->upsertItem($shipmentDbId, $item);
                        $itemsCount++;
                    }
                }
                
                // Aggiungi items a buffer (per fingerprint full)
                foreach ($items as $item) {
                    $allItems[] = $item;
                }
                
                // Probe mode: stop dopo page 1
                if (!$fullFetch && $pageCount >= 1) {
                    return [
                        'status' => 'complete',
                        'items_count' => $itemsCount,
                        'items' => $allItems,
                        'has_more' => $hasNext
                    ];
                }
                
                $nextToken = $newToken;
                
            } while ($nextToken);
            
            // ================================================================
            // LOGICA FINALE: Items salvati > 0 → SEMPRE COMPLETE
            // ================================================================
            // Se abbiamo salvato almeno 1 item, la spedizione è completa.
            // Loop/duplicati Amazon sono irrilevanti se i dati ci sono.
            
            if ($itemsCount > 0 || count($allItems) > 0) {
                $this->logger->info('inventory', "INBOUND_V0_COMPLETE", [
                    'user_id' => $this->userId,
                    'shipment_id' => $shipmentId,
                    'items_saved' => $itemsCount,
                    'items_total' => count($allItems),
                    'pages' => $pageCount,
                    'duration_ms' => round((microtime(true) - $shipmentStartTime) * 1000, 2),
                    'boxes_available' => 0 // v0: boxes non disponibili
                ]);
                
                return [
                    'status' => 'complete',
                    'items_count' => $itemsCount,
                    'items' => $allItems
                ];
            }
            
            // Se arriviamo qui con 0 items → vera anomalia
            $this->logger->warning('inventory', "INBOUND_V0_NO_ITEMS", [
                'user_id' => $this->userId,
                'shipment_id' => $shipmentId,
                'pages' => $pageCount
            ]);
            
            return [
                'status' => 'partial_no_progress',
                'note' => 'No items found after ' . $pageCount . ' pages',
                'items_count' => 0,
                'items' => []
            ];
            
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $isBudgetExhausted = strpos($errorMsg, 'Budget API esaurito') !== false;
            
            // Se budget esaurito, traccia e non loggare come ERROR
            if ($isBudgetExhausted) {
                $this->budgetSkippedShipments[] = $shipmentId;
                // Log condensato gestito a fine sync
            } else {
                // Solo errori NON-budget vengono loggati come ERROR
                $this->logger->error('inventory', "INBOUND_PROCESS_ITEMS_FAILED", [
                    'user_id' => $this->userId,
                    'shipment_id' => $shipmentId,
                    'page' => $pageCount,
                    'error' => $errorMsg
                ]);
            }
            
            return [
                'status' => 'partial_no_progress',
                'note' => substr($e->getMessage(), 0, 255),
                'items_count' => $itemsCount,
                'items' => $allItems
            ];
        }
    }
    
    /**
     * Upsert singolo item (idempotenza garantita da UNIQUE key)
     */
    private function upsertItem($shipmentDbId, $item) {
        $data = [
            'user_id' => $this->userId,
            'shipment_id' => $shipmentDbId,
            'seller_sku' => $item['SellerSKU'] ?? null,
            'fnsku' => $item['FulfillmentNetworkSKU'] ?? null,
            'asin' => $item['ASIN'] ?? null, // ✅ FIX: Salva ASIN se presente (manual import)
            'product_name' => $item['ProductName'] ?? ($item['SellerSKU'] ?? null), // ✅ FIX: Usa ProductName se presente
            'quantity_shipped' => $item['QuantityShipped'] ?? 0,
            'quantity_received' => $item['QuantityReceived'] ?? null,
            'quantity_in_case' => $item['QuantityInCase'] ?? null,
            'prep_owner' => $item['PrepOwner'] ?? null,
            'prep_type' => isset($item['PrepDetailsList']) ? implode(',', array_column($item['PrepDetailsList'], 'PrepInstruction')) : null
        ];
        
        $stmt = $this->db->prepare("
            INSERT INTO inbound_shipment_items 
            (user_id, shipment_id, seller_sku, fnsku, asin, product_name, 
             quantity_shipped, quantity_received, quantity_in_case, prep_owner, prep_type) 
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                quantity_received = VALUES(quantity_received),
                quantity_in_case = VALUES(quantity_in_case),
                updated_at = CURRENT_TIMESTAMP
        ");
        
        $stmt->execute(array_values($data));
    }
    
    /**
     * Aggiorna o crea stato sync per shipment
     */
    private function updateShipmentSyncState($shipmentDbId, $status, $note = null) {
        try {
            // Calcola next_retry_at in base allo status
            $nextRetry = null;
            if ($status !== 'complete') {
                $nextRetry = date('Y-m-d H:i:s', time() + 3600); // Retry tra 1h
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO shipment_sync_state 
                (shipment_id, user_id, sync_status, status_note, retry_count, last_attempt_at, next_retry_at)
                VALUES (?, ?, ?, ?, 1, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    sync_status = VALUES(sync_status),
                    status_note = VALUES(status_note),
                    retry_count = retry_count + 1,
                    last_attempt_at = NOW(),
                    next_retry_at = VALUES(next_retry_at),
                    updated_at = NOW()
            ");
            
            $stmt->execute([$shipmentDbId, $this->userId, $status, $note, $nextRetry]);
            
        } catch (Exception $e) {
            // Non bloccare su errore stato
            $this->logger->warning('inventory', "SHIPMENT_SYNC_STATE_UPDATE_ERROR", [
                'shipment_id' => $shipmentDbId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Aggiorna sync_state con nuovo cursore
     */
    private function updateSyncState($cursorUtc, $stats) {
        try {
            // ✅ Se cursorUtc è null → aggiorna solo last_run_at (rolling window)
            // Se cursorUtc ha valore → aggiorna anche last_cursor_utc (legacy/manual)
            
            if ($cursorUtc === null) {
                // Rolling window: non toccare cursore, solo timestamp run
                $stmt = $this->db->prepare("
                    INSERT INTO sync_state (user_id, last_run_at, updated_at)
                    VALUES (?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        last_run_at = NOW(),
                        updated_at = NOW()
                ");
                $stmt->execute([$this->userId]);
            } else {
                // Legacy/manual: aggiorna anche cursore
                $stmt = $this->db->prepare("
                    INSERT INTO sync_state (user_id, last_cursor_utc, last_run_at, updated_at)
                    VALUES (?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        last_cursor_utc = VALUES(last_cursor_utc),
                        last_run_at = NOW(),
                        updated_at = NOW()
                ");
                $stmt->execute([$this->userId, $cursorUtc]);
            }
        } catch (Exception $e) {
            // Non bloccare su errore stato
        }
    }
    
    // ===================================================================
    // SEZIONE 9: FINGERPRINT STRATEGY (HASH-BASED)
    // ===================================================================
    
    /**
     * Calcola fingerprint header shipment (SHA256)
     * Campi: status|fc|label_prep|cases_required|box_contents|name
     */
    private function headerFingerprint($shipmentData) {
        $fields = [
            trim((string)($shipmentData['ShipmentStatus'] ?? '')),
            trim((string)($shipmentData['DestinationFulfillmentCenterId'] ?? '')),
            trim((string)($shipmentData['LabelPrepType'] ?? '')),
            (int)($shipmentData['AreCasesRequired'] ?? 0),
            trim((string)($shipmentData['BoxContentsSource'] ?? '')),
            trim((string)($shipmentData['ShipmentName'] ?? ''))
        ];
        return hash('sha256', implode('|', $fields));
    }
    
    /**
     * Normalizza items per hash deterministico
     * Formato: sku1|qtyShip1|qtyRec1;sku2|qtyShip2|qtyRec2;...
     * Ordinato per SKU case-insensitive
     */
    private function normalizePageForHash($items) {
        if (empty($items)) {
            return '';
        }
        
        // Aggiungi chiave sort temporanea
        foreach ($items as &$it) {
            $it['_k'] = mb_strtolower(trim($it['SellerSKU'] ?? ''), 'UTF-8');
        }
        
        // Ordina per SKU
        usort($items, function($a, $b) {
            return strcmp($a['_k'], $b['_k']);
        });
        
        // Costruisci stringa normalizzata
        $buffer = '';
        foreach ($items as $it) {
            $sku = trim((string)($it['SellerSKU'] ?? ''));
            // Rimuovi control chars
            $sku = preg_replace('/[[:cntrl:]]+/', '', $sku);
            $qtyShipped = (int)($it['QuantityShipped'] ?? 0);
            $qtyReceived = (int)($it['QuantityReceived'] ?? 0);
            $buffer .= "{$sku}|{$qtyShipped}|{$qtyReceived};";
        }
        
        return $buffer;
    }
    
    /**
     * Calcola fingerprint items (SHA256)
     * Hash della stringa normalizzata
     */
    private function itemsFingerprint($items) {
        $normalized = $this->normalizePageForHash($items);
        return hash('sha256', $normalized);
    }
    
    /**
     * Probe items: fetch solo pagina 1 per calcolare fingerprint veloce
     * Ritorna: ['items' => [...], 'fingerprint' => 'xxx', 'has_more' => bool]
     */
    private function probeItemsPage1($shipmentId) {
        try {
            $response = $this->getShipmentItemsV0($shipmentId, null);
            $payload = $response['payload'] ?? $response;
            $items = $payload['ItemData'] ?? [];
            
            return [
                'items' => $items,
                'fingerprint' => $this->itemsFingerprint($items),
                'has_more' => !empty($payload['NextToken'] ?? null)
            ];
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            $isBudgetExhausted = strpos($errorMsg, 'Budget API esaurito') !== false;
            
            // Se budget esaurito, traccia e non loggare come WARNING
            if ($isBudgetExhausted) {
                $this->budgetSkippedShipments[] = $shipmentId;
                // Log condensato gestito a fine sync
            } else {
                // Solo errori NON-budget vengono loggati come WARNING
                $this->logger->warning('inventory', "INBOUND_PROBE_ITEMS_FAILED", [
                    'user_id' => $this->userId,
                    'shipment_id' => $shipmentId,
                    'error' => $errorMsg
                ]);
            }
            
            return ['items' => [], 'fingerprint' => '', 'has_more' => false];
        }
    }
    
    /**
     * Aggiorna fingerprints in shipment_sync_state
     */
    private function updateShipmentFingerprints($shipmentDbId, $headerFp, $itemsFp) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO shipment_sync_state 
                (shipment_id, user_id, shipment_fingerprint, items_fingerprint, internal_updated_at, sync_status)
                VALUES (?, ?, ?, ?, NOW(), 'complete')
                ON DUPLICATE KEY UPDATE
                    shipment_fingerprint = VALUES(shipment_fingerprint),
                    items_fingerprint = VALUES(items_fingerprint),
                    internal_updated_at = NOW()
            ");
            
            $stmt->execute([$shipmentDbId, $this->userId, $headerFp, $itemsFp]);
        } catch (Exception $e) {
            // Non bloccare su errore fingerprint
            $this->logger->warning('inventory', "FINGERPRINT_UPDATE_ERROR", [
                'shipment_id' => $shipmentDbId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Toggle immutable flag per CLOSED/CANCELLED >60gg
     */
    private function maybeToggleImmutable($shipmentDbId, $shipmentStatus) {
        try {
            // SET immutable=1 se CLOSED/CANCELLED + complete + >60gg
            if (in_array($shipmentStatus, ['CLOSED', 'CANCELLED'], true)) {
                $this->db->prepare("
                    UPDATE shipment_sync_state ss
                    SET ss.immutable = 1
                    WHERE ss.shipment_id = ?
                      AND ss.sync_status = 'complete'
                      AND ss.internal_updated_at IS NOT NULL
                      AND ss.internal_updated_at <= (NOW() - INTERVAL 60 DAY)
                ")->execute([$shipmentDbId]);
            }
            
            // CLEAR immutable se status riapre
            if (!in_array($shipmentStatus, ['CLOSED', 'CANCELLED'], true)) {
                $this->db->prepare("
                    UPDATE shipment_sync_state 
                    SET immutable = 0 
                    WHERE shipment_id = ?
                ")->execute([$shipmentDbId]);
            }
        } catch (Exception $e) {
            // Non bloccare
        }
    }
    
} // End class InboundCore

