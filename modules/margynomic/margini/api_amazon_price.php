<?php
/**
 * API Amazon Price Update - Aggiornamento Prezzi Amazon
 * File: modules/margynomic/margini/api_amazon_price.php
 */

// if (function_exists('ob_get_level') && ob_get_level() > 0) { ob_clean(); }
// Forza output JSON anche in caso di errore
ob_start();
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        ob_clean();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => false,
            'error' => 'PHP Fatal Error: ' . $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'debug' => 'Server crashed before completing request'
        ]);
    }
});

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0); // Evita contaminazione HTML
error_reporting(E_ALL);
ini_set('log_errors', 1);



require_once 'config_shared.php';
require_once dirname(__DIR__) . '/../logs/ApiDebugLogger.php';

// Debug mode per API Logger (false in produzione)
if (!defined('API_DEBUG_MODE')) {
    define('API_DEBUG_MODE', false);
}

// Avvia sessione se non già attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Abilita tutti gli errori per debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Debug log
$debugFile = dirname(__DIR__) . '/logs/api_debug.log';
if (!file_exists(dirname($debugFile))) {
    mkdir(dirname($debugFile), 0755, true);
}
file_put_contents($debugFile, "[" . date('Y-m-d H:i:s') . "] REQUEST START\n", FILE_APPEND);
file_put_contents($debugFile, "SESSION: " . json_encode($_SESSION ?? []) . "\n", FILE_APPEND);

try {
    // Inizializza database e logger
    $pdo = getDbConnection();
    $opId = 'OP_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    $logger = ApiDebugLogger::getInstance();
    
    // Verifica metodo richiesta
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed', 405);
    }

    // Verifica autenticazione
    $currentUser = null;
    if (function_exists('requireUserAuth')) {
        try {
            $currentUser = requireUserAuth();
        } catch (Exception $e) {
            $currentUser = $_SESSION['user'] ?? null;
        }
    }

    if (!$currentUser || !isset($currentUser['id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Autenticazione richiesta', 'code' => 401]);
        exit;
    }
    
    // Parsing input JSON
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        throw new Exception('Dati JSON non validi', 400);
    }

    $currentUserId = (int)($input['user_id'] ?? $currentUser['id'] ?? 0);

    // Log input ricevuto
    $logger->info('INPUT_VALIDATION', [
        'user_id' => $currentUserId,
        'product_id' => $input['product_id'] ?? null,
        'new_price' => $input['new_price'] ?? null,
        'action' => $input['action'] ?? null
    ]);

    // Validazione input critica
    $requiredFields = ['product_id', 'new_price', 'action'];
    $missingFields = [];

    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '' || $input[$field] === null) {
            $missingFields[] = $field;
        }
    }

    if (!empty($missingFields)) {
        echo json_encode([
            'success' => false,
            'error' => 'Campi obbligatori mancanti: ' . implode(', ', $missingFields),
            'debug' => 'Input validation failed'
        ]);
        exit;
    }
    
    // Validazione action specifica
    if ($input['action'] !== 'update_amazon_price') {
        throw new Exception('Azione non supportata', 400);
    }

    $productId = (int)$input['product_id'];
    $newPrice = (float)$input['new_price'];
    $currentPrice = (float)($input['current_price'] ?? 0);
    $targetMargin = (float)($input['target_margin'] ?? 0);

    // Validazioni business
    if ($productId <= 0) {
        throw new Exception('Product ID non valido', 400);
    }

    if ($newPrice < 0.01 || $newPrice > 999.99) {
        throw new Exception('Prezzo non valido. Range consentito: €0.01 - €999.99', 400);
    }

    // Recupera marketplace ID per l'utente
    $stmt = $pdo->prepare("SELECT marketplace_id FROM amazon_client_tokens WHERE user_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$currentUserId]);
    $userMarketplace = $stmt->fetchColumn();
    $marketplaceIds = $userMarketplace ? [$userMarketplace] : ['APJ6JRA9NG5V4'];

    // Log richiesta aggiornamento prezzo
    $logger->info('PRICE_UPDATE_REQUEST', [
        'user_id' => $currentUserId,
        'product_id' => $productId,
        'new_price' => $newPrice,
        'current_price' => $currentPrice,
        'target_margin' => $targetMargin,
        'marketplace_ids' => $marketplaceIds
    ]);
    
   // Get product owner first
$stmt = $pdo->prepare("SELECT user_id FROM products WHERE id = ?");
$stmt->execute([(int)$productId]);
$ownerUserId = (int)$stmt->fetchColumn();
    
    // Verifica che il prodotto appartenga all'utente
    $stmt = $pdo->prepare("
        SELECT id, nome, prezzo_attuale, sku, asin, user_id 
FROM products 
WHERE id = ?
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if (!$product) {
        echo json_encode([
            'success' => false,
            'error' => 'Prodotto non trovato',
            'product_id' => $productId,
            'user_id' => $currentUserId,
            'debug' => 'Product lookup failed - check if product exists and belongs to user'
        ]);
        exit;
    }

    // Debug prodotto trovato
    $logger->info('PRODUCT_VALIDATION', [
        'product_found' => !empty($product),
        'has_sku' => !empty($product['sku']),
        'has_asin' => !empty($product['asin']),
        'sku_value' => $product['sku'] ?? 'missing',
        'asin_value' => $product['asin'] ?? 'missing'
    ]);

    // Verifica SKU/ASIN necessari per Amazon
    if (empty($product['sku']) && empty($product['asin'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Prodotto senza SKU né ASIN - impossibile aggiornare su Amazon',
            'product_data' => $product,
            'debug' => 'Product missing both SKU and ASIN'
        ]);
        exit;
    }
    
    // Check permissions - usa operator ternario sicuro
    $isAdmin = isset($_SESSION['user']['is_admin']) ? $_SESSION['user']['is_admin'] : false;
    if ($product['user_id'] !== $currentUserId && !$isAdmin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Nessun permesso', 'code' => 403]);
        exit;
    }
    
    // Log dati prodotto trovato
    $logger->info('PRODUCT_FOUND', [
        'product_id' => $productId,
        'sku' => $product['sku'] ?? null,
        'asin' => $product['asin'] ?? null,
        'current_price' => $product['prezzo_attuale'] ?? null
    ]);

    // Inizia transazione
    $pdo->beginTransaction();

    try {
        // [rimosso] L'aggiornamento del prodotto avverrà in feed_status.php quando il feed sarà DONE.

        // 2. Log dell'operazione
        $stmt = $pdo->prepare("
            INSERT INTO amazon_price_updates_log 
            (user_id, product_id, sku_amazon, asin, old_price, new_price, target_margin, status, amazon_response, created_at, completed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NULL, NOW(), NULL)
        ");
        $logResult = $stmt->execute([
            $currentUser['id'], 
            $productId, 
            $product['sku'] ?: null,
            $product['asin'] ?: null,
            $currentPrice, 
            $newPrice, 
            $targetMargin
        ]);

        if (!$logResult) {
            throw new Exception('Errore logging operazione', 500);
        }

        $logId = $pdo->lastInsertId();

        // 3. DEBUG: Log dati prodotto prima dell'invio
        CentralLogger::log('margini', 'DEBUG', 'Price update debug', [
            'product_id' => $productId,
            'sku' => $product['sku'] ?? 'unknown',
            'asin' => $product['asin'] ?? 'unknown',
            'current_price' => $currentPrice,
            'new_price' => $newPrice,
            'has_sku' => !empty($product['sku']),
            'has_asin' => !empty($product['asin'])
        ]);

// 3. Chiamata API Amazon SP-API
$amazonResult = updateAmazonPriceViaSPAPI($product, $newPrice, $logId, $pdo);

        // Debug feed creation
        $logger->info('FEED_CREATION_DEBUG', [
            'amazon_success' => $amazonResult['success'],
            'submission_id' => $amazonResult['response']['submission_id'] ?? null,
            'is_fake_id' => isset($amazonResult['response']['is_fake_id']) ? $amazonResult['response']['is_fake_id'] : false,
            'status' => $amazonResult['response']['status'] ?? null
        ]);

        // Verifica se il feed è realmente stato creato
$isFakeFeed = isset($amazonResult['response']['is_fake']) && $amazonResult['response']['is_fake'] === true;

if ($amazonResult['success'] && !$isFakeFeed) {
    // Aggiorna log con successo reale
            $stmt = $pdo->prepare("
                UPDATE amazon_price_updates_log 
                SET status = 'pending', amazon_response = ?, completed_at = NULL 
                WHERE id = ?
            ");
            $stmt->execute([json_encode($amazonResult['response']), $logId]);
            
            $pdo->commit();
            
            // Log operazione per debug
            CentralLogger::log('margini', 'INFO', "Amazon price updated - User: {$currentUser['id']}, Product: {$productId}, Price: €{$currentPrice} → €{$newPrice}");
            
            http_response_code(202);
            echo json_encode([
                'success' => true,
                'message' => 'Feed accettato da Amazon. Stato: pending',
                'data' => [
                    'product_id' => $productId,
                    'old_price'  => $currentPrice,
                    'new_price'  => $newPrice,
                    'amazon_response' => $amazonResult['response'] ?? null
                ]
            ]);
            exit;

        } else {
            // Aggiorna log con errore
            $stmt = $pdo->prepare("
                UPDATE amazon_price_updates_log 
                SET status = 'failed', error_message = ?, completed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$amazonResult['error'], $logId]);
            
            $pdo->commit(); // Commit comunque per salvare il log
            
            throw new Exception('Errore Amazon: ' . $amazonResult['error'], 500);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollback();
        }
        throw $e;
    }

} catch (Exception $e) {
    // Rollback sicuro solo se c'è una transazione attiva
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    // Log errore
    $debugFile = dirname(__DIR__) . '/logs/api_debug.log';
    file_put_contents($debugFile, "[ERROR] " . $e->getMessage() . " at line " . $e->getLine() . "\n", FILE_APPEND);
    
    // Pulisci qualsiasi output precedente
    if (ob_get_level()) {
        ob_clean();
    }
    
    // Forza header JSON
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code($e->getCode() ?: 500);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => $e->getCode() ?: 500
    ]);
    exit;
}

/**
 * Funzione per aggiornare prezzo su Amazon tramite SP-API
 */
function updateAmazonPriceViaSPAPI($product, $newPrice, $logId, $pdo) {
    $logger = ApiDebugLogger::getInstance();
    
    // Log ingresso funzione SP-API
    $logger->info('SPAPI_PRICE_UPDATE_START', [
        'product_id' => $product['id'] ?? null,
        'sku' => $product['sku'] ?? $product['sku_amazon'] ?? null,
        'asin' => $product['asin'] ?? null,
        'new_price' => $newPrice,
        'log_id' => $logId
    ]);
    
    if (!isset($product['id'], $newPrice)) {
        $logger->error('SPAPI_MISSING_FIELDS', ['product' => $product, 'new_price' => $newPrice]);
        return ['success' => false, 'error' => 'Missing fields'];
    }
    
if (defined('TEST_MODE') && TEST_MODE) {
    $logger->warning('SPAPI_TEST_MODE_ENABLED', ['test_mode' => TEST_MODE]);
    return ['success' => false, 'error' => 'TEST_MODE enabled'];
}

// Se arriviamo qui, dovremmo fare chiamata reale
$logger->info('PROCEEDING_WITH_REAL_AMAZON_CALL', [
    'sku' => $product['sku'] ?? 'missing',
    'asin' => $product['asin'] ?? 'missing',
    'new_price' => $newPrice
]);
    
    $newPrice = round((float)$newPrice, 2);
    if (!is_finite($newPrice)) {
        throw new Exception('Prezzo non valido', 400);
    }
    
    // hard floor/ceil
    if (!defined('MIN_LISTING_PRICE')) define('MIN_LISTING_PRICE', 1.00);
    if (!defined('MAX_LISTING_PRICE')) define('MAX_LISTING_PRICE', 999.99);
    
    // Log controlli di range prezzo
    $logger->debug('PRICE_RANGE_CHECK', [
        'new_price' => $newPrice,
        'min_price' => MIN_LISTING_PRICE,
        'max_price' => MAX_LISTING_PRICE,
        'in_range' => ($newPrice >= MIN_LISTING_PRICE && $newPrice <= MAX_LISTING_PRICE)
    ]);
    
    if ($newPrice < MIN_LISTING_PRICE || $newPrice > MAX_LISTING_PRICE) {
        $logger->error('PRICE_OUT_OF_RANGE', [
            'new_price' => $newPrice,
            'min' => MIN_LISTING_PRICE,
            'max' => MAX_LISTING_PRICE
        ]);
        return ['success' => false, 'error' => 'Price out of allowed range', 'min' => MIN_LISTING_PRICE, 'max' => MAX_LISTING_PRICE];
    }
    
    // fetch product (sku/asin/user_id) - usa seller SKU assegnato
$asin = trim($product['asin'] ?? '');
$sku = trim($product['sku'] ?? '');

// Verifica che il seller SKU sia assegnato
if (empty($sku)) {
    $logger->error('NO_SELLER_SKU_ASSIGNED', ['product_id' => $product['id'], 'sku_value' => $sku, 'asin_value' => $asin]);
    return ['success' => false, 'error' => 'Seller SKU non assegnato per questo prodotto'];
}

// Verifica ASIN se SKU presente
if (empty($asin)) {
    $logger->warning('NO_ASIN_ASSIGNED', ['product_id' => $product['id'], 'sku' => $sku]);
    // Continua comunque - Amazon può usare solo SKU
}
    
    // Log validazione SKU/ASIN
    $logger->debug('SKU_ASIN_VALIDATION', [
        'sku' => $sku,
        'asin' => $asin,
        'has_sku' => !empty($sku),
        'has_asin' => !empty($asin)
    ]);
    
    if ($asin === '' && $sku === '') {
        $logger->error('MISSING_SKU_ASIN', ['product_id' => $product['id']]);
        return ['success' => false, 'error' => 'Missing ASIN/SKU'];
    }
    
    // build and submit feed (uses SKU if ASIN missing)
    $submission = submitPriceFeed([
        'marketplace_id' => 'APJ6JRA9NG5V4',  // Forza marketplace Italia
        'seller_sku'     => $sku,
        'asin'           => $asin !== '' ? $asin : null,
        'price'          => $newPrice
    ]);
    
    return [
        'success' => true,
        'response' => $submission
    ];
}

/**
 * Submit price feed to Amazon SP-API
 */
function submitPriceFeed($data) {
    $logger = ApiDebugLogger::getInstance();
    
    // Log inizio submit feed
    $logger->info('SUBMIT_PRICE_FEED_START', [
        'marketplace_id' => $data['marketplace_id'] ?? null,
        'seller_sku' => $data['seller_sku'] ?? null,
        'asin' => $data['asin'] ?? null,
        'price' => $data['price'] ?? null
    ]);
    
    // Prima di getAccessToken
    $logger->debug('PRE_GET_ACCESS_TOKEN', [
        'client_id_present' => defined('AMAZON_CLIENT_ID'),
        'scopes' => ['sp-api'],
        'cache_check' => 'checking token expiry'
    ]);
    
    $tokenStart = microtime(true);
    $token = getAmazonAccessToken();
    $tokenElapsed = (microtime(true) - $tokenStart) * 1000;
    
    // Dopo getAccessToken
    $logger->info('POST_GET_ACCESS_TOKEN', [
        'success' => !empty($token),
        'elapsed_ms' => round($tokenElapsed, 2),
        'token_preview' => $token ? substr($token, 0, 20) . '***MASKED***' : null
    ]);
    
    $endpoint = 'https://sellingpartnerapi-eu.amazon.com/feeds/2021-06-30/feeds';
    $xml = buildPriceFeedXML($data['seller_sku'], $data['asin'], $data['price'], $data['marketplace_id']);

    // Log createFeedDocument
    $documentId = uploadFeedDocument($xml);
    $logger->info('CREATE_FEED_DOCUMENT', [
        'feed_type' => 'POST_PRODUCT_PRICING_DATA',
        'document_id' => $documentId,
        'content_type' => 'text/xml',
        'payload_size' => strlen($xml),
        'compression' => 'none'
    ]);
    
    $requestBody = json_encode([
        'marketplaceIds' => [$data['marketplace_id']],
        'inputFeedDocumentId' => $documentId,
        'feedType' => 'POST_PRODUCT_PRICING_DATA'
    ]);
    
    // Log createFeed request
    $logger->debug('CREATE_FEED_REQUEST', [
        'endpoint' => $endpoint,
        'method' => 'POST',
        'feed_type' => 'POST_PRODUCT_PRICING_DATA',
        'marketplace_ids' => [$data['marketplace_id']],
        'input_feed_document_id' => $documentId,
        'body_size' => strlen($requestBody),
        'marketplaceIds' => [$data['marketplace_id']]
    ]);
    
    $requestStart = microtime(true);
    $res = makeHttpPostRequest($endpoint, [
        'headers' => [
            'Authorization' => 'Bearer '.$token,
            'x-amz-access-token' => $token,
            'Content-Type' => 'application/json'
        ],
        'body' => $requestBody
        ]);
    $requestElapsed = (microtime(true) - $requestStart) * 1000;

    // Log createFeed response
    $logger->info('CREATE_FEED_RESPONSE', [
        'status_code' => $res['code'] ?? 500,
        'feed_id' => $res['body']['feedId'] ?? null,
        'x_amzn_request_id' => $res['headers']['x-amzn-requestid'] ?? null,
        'elapsed_ms' => round($requestElapsed, 2),
        'response_size' => isset($res['body']) ? strlen(json_encode($res['body'])) : 0
    ]);

    $actualFeedId = $res['body']['feedId'] ?? null;
    $ok = ($res['code'] ?? 500) < 300;

    // Log dettagliato se manca feed ID
    if (!$actualFeedId) {
        $logger->error('MISSING_FEED_ID', [
            'status_code' => $res['code'] ?? 500,
            'response_body' => $res['body'] ?? null,
            'response_headers' => $res['headers'] ?? null,
            'http_success' => $ok,
            'endpoint' => 'createFeed'
        ]);
    }

    // Log caso particolare: risposta HTTP OK ma senza feed ID
    if ($ok && !$actualFeedId) {
        $logger->error('SUCCESS_WITHOUT_FEED_ID', [
            'status_code' => $res['code'],
            'full_response' => $res,
            'possible_cause' => 'API returned success but no feedId'
        ]);
    }

    return [
        'status' => $actualFeedId ? 'ACCEPTED' : 'ERROR',
        'submission_id' => $actualFeedId ?: 'FAKE_' . time(),
        'is_fake' => !$actualFeedId,
        'http_code' => $res['code'] ?? 500,
        'timestamp' => gmdate('c')
    ];
}

/**
 * Build XML feed for price update
 */
function buildPriceFeedXML($sku, $asin, $price, $marketplaceId) {
    $logger = ApiDebugLogger::getInstance();
    
    // Log validazione schema
    $logger->debug('XML_FEED_VALIDATION', [
        'schema_version' => '1.01',
        'feed_type' => 'POST_PRODUCT_PRICING_DATA',
        'required_fields' => [
            'sku' => !empty($sku),
            'price' => is_numeric($price),
            'currency' => 'EUR',
            'marketplace_id' => !empty($marketplaceId)
        ]
    ]);
    
    // Normalizzazione numerica
    $formattedPrice = number_format($price, 2, '.', '');
    $logger->debug('PRICE_NORMALIZATION', [
        'original_price' => $price,
        'formatted_price' => $formattedPrice,
        'decimal_separator' => '.',
        'currency' => 'EUR',
        'locale' => setlocale(LC_NUMERIC, 0)
    ]);
    
    $xml = '<?xml version="1.0" encoding="UTF-8"?>';
    $xml .= '<AmazonEnvelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="amzn-envelope.xsd">';
    $xml .= '<Header><DocumentVersion>1.01</DocumentVersion><MerchantIdentifier>MERCHANT_ID</MerchantIdentifier></Header>';
    $xml .= '<MessageType>Price</MessageType>';
    $xml .= '<Message><MessageID>1</MessageID>';
    $xml .= '<Price><SKU>' . htmlspecialchars($sku) . '</SKU>';
    $xml .= '<StandardPrice currency="EUR">' . $formattedPrice . '</StandardPrice>';
    $xml .= '</Price></Message></AmazonEnvelope>';
    
    $logger->debug('XML_FEED_BUILT', [
        'xml_size' => strlen($xml),
        'sku' => $sku,
        'price' => $formattedPrice,
        'currency' => 'EUR'
    ]);
    
    return $xml;
}

/**
 * Get Amazon access token (real implementation)
 */
function getAmazonAccessToken($userId = null) {
    $logger = ApiDebugLogger::getInstance();
    
    try {
        $db = getDbConnection();
        
        // Get Amazon credentials
        $stmt = $db->prepare("SELECT * FROM amazon_credentials WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $credentials = $stmt->fetch();
        
        if (!$credentials) {
            throw new Exception('No active Amazon credentials found');
        }
        
        // Get user refresh token (use specific user if provided, otherwise first available)
        if ($userId) {
            $stmt = $db->prepare("SELECT * FROM amazon_client_tokens WHERE user_id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$userId]);
        } else {
            $stmt = $db->prepare("SELECT * FROM amazon_client_tokens WHERE is_active = 1 LIMIT 1");
            $stmt->execute();
        }
        $userToken = $stmt->fetch();
        
        if (!$userToken) {
            throw new Exception('No active user token found');
        }
        
        // Prepare LWA token request
        $tokenUrl = defined('AMAZON_TOKEN_URL') ? AMAZON_TOKEN_URL : 'https://api.amazon.com/auth/o2/token';
        
        $postData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $userToken['refresh_token'], // In production this should be decrypted
            'client_id' => $credentials['spapi_client_id'], // In production this should be decrypted
            'client_secret' => $credentials['spapi_client_secret'] // In production this should be decrypted
        ];
        
        $logger->debug('LWA_TOKEN_REQUEST', [
            'url' => $tokenUrl,
            'client_id_present' => !empty($credentials['spapi_client_id']),
            'refresh_token_present' => !empty($userToken['refresh_token'])
        ]);
        
        // Make real HTTP request to Amazon LWA
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL error: " . $error);
        }
        
        if ($httpCode !== 200) {
    $logger->error('LWA_TOKEN_FAILED', [
        'http_code' => $httpCode,
        'response' => $response,
        'user_id' => $userId,
        'credentials_present' => !empty($credentials)
    ]);
    throw new Exception("HTTP error: " . $httpCode . " - " . $response);
}
        
        $tokenData = json_decode($response, true);
        
        if (!$tokenData || !isset($tokenData['access_token'])) {
            throw new Exception("Invalid token response: " . $response);
        }
        
        $logger->info('LWA_TOKEN_SUCCESS', [
            'http_code' => $httpCode,
            'token_type' => $tokenData['token_type'] ?? 'bearer',
            'expires_in' => $tokenData['expires_in'] ?? 3600
        ]);
        
        return $tokenData['access_token'];
        
    } catch (Exception $e) {
        $logger->error('LWA_TOKEN_ERROR', [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Upload feed document (real implementation)
 */
function uploadFeedDocument($xml) {
    $logger = ApiDebugLogger::getInstance();
    
    try {
        $accessToken = getAmazonAccessToken();
        
        // Step 1: Create feed document
        $baseUrl = defined('AMAZON_SP_API_BASE_URL') ? AMAZON_SP_API_BASE_URL : 'https://sellingpartnerapi-eu.amazon.com';
        $createDocumentUrl = $baseUrl . '/feeds/2021-06-30/documents';
        
        $createDocumentPayload = [
            'contentType' => 'text/xml; charset=UTF-8'
        ];
        
        $logger->debug('CREATE_FEED_DOCUMENT_REQUEST', [
            'url' => $createDocumentUrl,
            'content_type' => 'text/xml; charset=UTF-8'
        ]);
        
        // Make request to create feed document
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $createDocumentUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($createDocumentPayload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'x-amz-access-token: ' . $accessToken,
            'User-Agent: ' . (defined('AMAZON_USER_AGENT') ? AMAZON_USER_AGENT : 'Skualizer/1.0')
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("cURL error creating document: " . $error);
        }
        
        if ($httpCode !== 201) {
            throw new Exception("HTTP error creating document: " . $httpCode . " - " . $response);
        }
        
        $documentResponse = json_decode($response, true);
        
        if (!$documentResponse || !isset($documentResponse['feedDocumentId'])) {
    throw new Exception("Invalid document response: " . $response);
}

$documentId = $documentResponse['feedDocumentId'];
        $uploadUrl = $documentResponse['url'];
        
        $logger->info('CREATE_FEED_DOCUMENT_SUCCESS', [
            'document_id' => $documentId,
            'upload_url_present' => !empty($uploadUrl)
        ]);
        
        // Step 2: Upload XML to the provided URL
        $contentMd5 = base64_encode(md5($xml, true));
        $contentLength = strlen($xml);
        
        $logger->debug('UPLOAD_XML_TO_S3', [
            'payload_size' => $contentLength,
            'content_md5' => $contentMd5,
            'content_length' => $contentLength
        ]);
        
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $uploadUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: text/xml; charset=UTF-8',
    'Content-Length: ' . strlen($xml)
]);
        
        $uploadResponse = curl_exec($ch);
        $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadError = curl_error($ch);
        curl_close($ch);
        
        if ($uploadError) {
            throw new Exception("cURL error uploading XML: " . $uploadError);
        }
        
        if ($uploadHttpCode !== 200) {
            throw new Exception("HTTP error uploading XML: " . $uploadHttpCode . " - " . $uploadResponse);
        }
        
        $logger->info('UPLOAD_XML_SUCCESS', [
            'document_id' => $documentId,
            'upload_status' => $uploadHttpCode,
            'payload_size' => $contentLength
        ]);
        
        return $documentId;
        
    } catch (Exception $e) {
        $logger->error('UPLOAD_FEED_DOCUMENT_ERROR', [
            'error' => $e->getMessage()
        ]);
        throw $e;
    }
}

/**
 * Make HTTP POST request (real implementation)
 */
function makeHttpPostRequest($url, $options) {
    $logger = ApiDebugLogger::getInstance();
    
    // Log pre-request
    $logger->logHttpRequest(
        'POST',
        $url,
        $options['headers'] ?? [],
        $options['body'] ?? null,
        isset($options['body']) ? strlen($options['body']) : 0
    );
    
    $requestStart = microtime(true);
    
    try {
        // Make real HTTP request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body'] ?? '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        // Set headers if provided
        if (!empty($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $requestElapsed = (microtime(true) - $requestStart) * 1000;
        
        if ($error) {
            throw new Exception("cURL error: " . $error);
        }
        
        // Parse headers and body
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        
        // Parse headers into array
        $headers = [];
        $headerLines = explode("\r\n", $headerString);
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        
        // Try to decode JSON body
        $jsonBody = json_decode($body, true);
        $responseBody = $jsonBody ?: $body;
        
        $result = [
            'code' => $httpCode,
            'body' => $responseBody,
            'headers' => $headers
        ];
        
        // Log post-response
        $logger->logHttpResponse(
            $httpCode,
            $headers,
            is_array($responseBody) ? json_encode($responseBody) : $body,
            round($requestElapsed, 2)
        );
        
        return $result;
        
    } catch (Exception $e) {
        $requestElapsed = (microtime(true) - $requestStart) * 1000;
        
        $logger->error('HTTP_REQUEST_ERROR', [
            'url' => $url,
            'error' => $e->getMessage(),
            'elapsed_ms' => round($requestElapsed, 2)
        ]);
    
    return [
            'code' => 500,
            'body' => ['error' => $e->getMessage()],
            'headers' => []
    ];
    }
}
?>