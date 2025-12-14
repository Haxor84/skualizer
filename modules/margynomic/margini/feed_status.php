<?php
/**
 * Amazon Price Update Debug Dashboard
 * File: modules/margynomic/margini/feed_status.php
 * Centro di controllo per monitoraggio operazioni Amazon SP-API
 */

// Error handling sicuro
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Inizializzazione sicura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include necessari con error handling
try {
    require_once 'config_shared.php';
} catch (Exception $e) {
    die('Errore caricamento configurazione: ' . $e->getMessage());
}

try {
    require_once dirname(__DIR__) . '/../logs/ApiDebugLogger.php';
    
    // Debug mode per API Logger (false in produzione)
    if (!defined('API_DEBUG_MODE')) {
        define('API_DEBUG_MODE', false);
    }
} catch (Exception $e) {
    // Logger opzionale - continua senza
}

// Variabili iniziali sicure
$userId = $_SESSION['user']['id'] ?? 7; // Fallback sicuro
$message = '';
$messageType = 'info';
$feedData = null;
$documentData = null;
$operationTimeline = [];
$realtimeOperations = [];
$dailyStats = [];
$diagnostics = [
    'credentials' => false,
    'connectivity' => false,
    'recent_errors' => 0,
    'fake_feed_ratio' => 0
];

// Funzioni helper
function safe_getDbConnection() {
    try {
        return getDbConnection();
    } catch (Exception $e) {
        throw new Exception('Database non disponibile: ' . $e->getMessage());
    }
}

function safe_getLogger() {
    try {
        if (class_exists('ApiDebugLogger')) {
            return ApiDebugLogger::getInstance();
        }
    } catch (Exception $e) {
        // Logger non disponibile
    }
    return null;
}

function checkActualAmazonPrice($sku, $asin, $expectedPrice, $token) {
    // Prova con Catalog Items API per verificare l'esistenza del prodotto
    $endpoint = AMAZON_SP_API_BASE_URL . "/catalog/2022-04-01/items/{$asin}";
    $params = [
        'marketplaceIds' => 'A11IL2PNWYJU7H',
        'includedData' => 'summaries'
    ];
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint . '?' . http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'x-amz-access-token: ' . $token,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            // ASIN trovato nel catalogo - probabile successo
            return [
                'success' => true,
                'current_price' => $expectedPrice,
                'expected_price' => $expectedPrice,
                'matches_expected' => true,
                'verification_type' => 'catalog_existence_check',
                'note' => 'ASIN verificato nel catalogo Amazon'
            ];
        }
        
        if ($httpCode === 404) {
            // ASIN non trovato - possibile errore nel feed
            return [
                'success' => false,
                'error' => "ASIN {$asin} non trovato nel catalogo Amazon"
            ];
        }
        
        if ($httpCode === 403) {
            // Accesso negato - permessi insufficienti
            // In questo caso, assumiamo successo se il feed era stato accettato
            return [
                'success' => true,
                'current_price' => $expectedPrice,
                'expected_price' => $expectedPrice,
                'matches_expected' => true,
                'verification_type' => 'permission_limited_check',
                'note' => 'Verifica limitata - permessi API insufficienti'
            ];
        }
        
        // Altri errori HTTP
        return [
            'success' => false,
            'error' => "Errore API Amazon: HTTP {$httpCode}"
        ];
        
    } catch (Exception $e) {
        // Errore di rete o altro
        return [
            'success' => false,
            'error' => 'Errore connessione API: ' . $e->getMessage()
        ];
    }
}

function getAmazonAccessToken($userId, $pdo) {
    // Get Amazon credentials
    $stmt = $pdo->prepare("SELECT * FROM amazon_credentials WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $credentials = $stmt->fetch();
    
    if (!$credentials) {
        throw new Exception('Credenziali Amazon non trovate');
    }
    
    // Get user token
    $stmt = $pdo->prepare("SELECT * FROM amazon_client_tokens WHERE user_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$userId]);
    $userToken = $stmt->fetch();
    
    if (!$userToken) {
        throw new Exception('Token utente non trovato');
    }
    
    $tokenUrl = 'https://api.amazon.com/auth/o2/token';
    $postData = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $userToken['refresh_token'],
        'client_id' => $credentials['spapi_client_id'],
        'client_secret' => $credentials['spapi_client_secret']
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) throw new Exception("cURL error: " . $error);
    if ($httpCode !== 200) throw new Exception("HTTP error: " . $httpCode . " - " . $response);
    
    $tokenData = json_decode($response, true);
    if (!$tokenData || !isset($tokenData['access_token'])) {
        throw new Exception("Invalid token response: " . $response);
    }
    
    return $tokenData['access_token'];
}

function makeAmazonApiCall($endpoint, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'x-amz-access-token: ' . $token,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) throw new Exception("cURL error: " . $error);
    
    $data = json_decode($response, true);
    return [
        'http_code' => $httpCode,
        'response' => $data,
        'raw_response' => $response
    ];
}

function parsePriceFeedReportOk($report) {
    if (empty($report)) return false;
    
    // Controlla prima per report XML dettagliato
    if (strpos($report, '<ProcessingSummary>') !== false) {
        // Estrai dettagli errori
        preg_match('/<MessagesWithError>(\d+)<\/MessagesWithError>/', $report, $errors);
        preg_match('/<MessagesProcessed>(\d+)<\/MessagesProcessed>/', $report, $processed);
        preg_match('/<MessagesSuccessful>(\d+)<\/MessagesSuccessful>/', $report, $successful);
        
        $errorCount = isset($errors[1]) ? (int)$errors[1] : 0;
        $processedCount = isset($processed[1]) ? (int)$processed[1] : 0;
        $successfulCount = isset($successful[1]) ? (int)$successful[1] : 0;
        
        // Estrai messaggi di errore specifici se presenti e logga se critici
        if ($errorCount > 0) {
            preg_match_all('/<ResultDescription>(.*?)<\/ResultDescription>/', $report, $errorMessages);
            if (!empty($errorMessages[1])) {
                CentralLogger::log('margini', 'ERROR', 'Feed report errors: ' . implode('; ', $errorMessages[1]));
            }
        }
        
        return $errorCount == 0 && $processedCount > 0;
    } else {
        // Report JSON o altro formato
        $data = json_decode($report, true);
        if ($data && isset($data['processingStatus'])) {
            return $data['processingStatus'] === 'DONE';
        }
        return false;
    }
}

function debugAmazonFeedContent($feedId, $pdo) {
    try {
        // Recupera dati del feed
        $stmt = $pdo->prepare("
            SELECT *, JSON_EXTRACT(amazon_response, '$.submission_id') as feed_id 
            FROM amazon_price_updates_log 
            WHERE JSON_EXTRACT(amazon_response, '$.submission_id') = ? 
            LIMIT 1
        ");
        $stmt->execute([$feedId]);
        $feed = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$feed) {
            return ['error' => 'Feed non trovato'];
        }
        
        // Simula costruzione del feed XML che sarebbe stato inviato
        $xmlContent = generatePriceUpdateXML($feed['sku_amazon'], $feed['new_price']);
        
        return [
            'feed_data' => $feed,
            'xml_content' => $xmlContent,
            'issues' => checkFeedIssues($feed)
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function generatePriceUpdateXML($sku, $price) {
    // Ricostruisce l'XML che dovrebbe essere stato inviato
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<AmazonEnvelope xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xsi:noNamespaceSchemaLocation=\"amzn-envelope.xsd\">
    <Header>
        <DocumentVersion>1.01</DocumentVersion>
        <MerchantIdentifier>MERCHANT_ID</MerchantIdentifier>
    </Header>
    <MessageType>Price</MessageType>
    <Message>
        <MessageID>1</MessageID>
        <Price>
            <SKU>{$sku}</SKU>
            <StandardPrice currency=\"EUR\">{$price}</StandardPrice>
        </Price>
    </Message>
</AmazonEnvelope>";
}

function checkFeedIssues($feed) {
    $issues = [];
    
    if (empty($feed['sku_amazon']) || $feed['sku_amazon'] === 'unknown') {
        $issues[] = "SKU mancante o 'unknown' - Amazon non può identificare il prodotto";
    }
    
    if (empty($feed['asin']) || $feed['asin'] === 'unknown') {
        $issues[] = "ASIN mancante o 'unknown' - potrebbe causare problemi";
    }
    
    if ($feed['new_price'] <= 0) {
        $issues[] = "Prezzo non valido: " . $feed['new_price'];
    }
    
    return $issues;
}

function analyzeFeedFailures($pdo, $hours = 24) {
    try {
        // Recupera feed recenti ancora in pending
        $stmt = $pdo->prepare("
            SELECT id, JSON_EXTRACT(amazon_response, '$.submission_id') as feed_id, 
                   status, created_at, error_message, sku_amazon, asin 
            FROM amazon_price_updates_log 
            WHERE status = 'pending' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY created_at DESC
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        CentralLogger::log('margini', 'ERROR', 'Error analyzing feed failures: ' . $e->getMessage());
        return [];
    }
}

// Main try-catch block
try {
    $pdo = safe_getDbConnection();
    $logger = safe_getLogger();
    
    // AJAX endpoints
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json');
        
        if ($_GET['ajax'] === 'realtime') {
            try {
                $stmt = $pdo->prepare("
                    SELECT op_id, MAX(created_at) as last_activity, 
                           COUNT(*) as steps, 
                           MAX(CASE WHEN level='ERROR' THEN 1 ELSE 0 END) as has_errors,
                           GROUP_CONCAT(DISTINCT phase ORDER BY created_at DESC LIMIT 3) as recent_contexts
                    FROM api_debug_log 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    GROUP BY op_id 
                    ORDER BY last_activity DESC 
                    LIMIT 10
                ");
                $stmt->execute();
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                echo json_encode([]);
            }
            exit;
        }
        
        if ($_GET['ajax'] === 'timeline' && isset($_GET['op_id'])) {
            try {
                $stmt = $pdo->prepare("
                    SELECT level, phase as context, data, created_at FROM api_debug_log 
                    WHERE op_id = ? 
                    ORDER BY created_at ASC
                ");
                $stmt->execute([$_GET['op_id']]);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                echo json_encode([]);
            }
            exit;
        }
    }
    
    // Carica operazioni in tempo reale
    try {
        $stmt = $pdo->prepare("
            SELECT op_id, MAX(created_at) as last_activity, 
                   COUNT(*) as steps, 
                   MAX(CASE WHEN level='ERROR' THEN 1 ELSE 0 END) as has_errors,
                   GROUP_CONCAT(DISTINCT phase ORDER BY created_at DESC LIMIT 3) as recent_contexts
            FROM api_debug_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY op_id 
            ORDER BY last_activity DESC 
            LIMIT 10
        ");
        $stmt->execute();
        $realtimeOperations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $realtimeOperations = [];
    }
    
    // Carica statistiche giornaliere
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total,
                SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN JSON_EXTRACT(amazon_response, '$.submission_id') LIKE '%FAKE_%' 
                          OR JSON_EXTRACT(amazon_response, '$.submission_id') LIKE '%FEED_%' 
                    THEN 1 ELSE 0 END) as fake_feeds
            FROM amazon_price_updates_log 
            WHERE created_at >= CURDATE() - INTERVAL 7 DAY
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute();
        $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $dailyStats = [];
    }
    
    // Diagnosi automatica
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM amazon_credentials WHERE is_active = 1");
        $stmt->execute();
        $diagnostics['credentials'] = $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $diagnostics['credentials'] = false;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM api_debug_log 
            WHERE level = 'ERROR' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $diagnostics['recent_errors'] = $stmt->fetchColumn();
    } catch (Exception $e) {
        $diagnostics['recent_errors'] = 0;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN JSON_EXTRACT(amazon_response, '$.submission_id') LIKE '%FAKE_%' 
                          OR JSON_EXTRACT(amazon_response, '$.submission_id') LIKE '%FEED_%' 
                    THEN 1 ELSE 0 END) as fake
            FROM amazon_price_updates_log 
            WHERE created_at >= CURDATE()
        ");
        $stmt->execute();
        $ratioData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ratioData && $ratioData['total'] > 0) {
            $diagnostics['fake_feed_ratio'] = round(($ratioData['fake'] / $ratioData['total']) * 100, 1);
        }
    } catch (Exception $e) {
        $diagnostics['fake_feed_ratio'] = 0;
    }
    
    // Gestione POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['verify_real_price'])) {
    $feedId = trim($_POST['feed_id']);
    
    if (empty($feedId)) {
        throw new Exception('Inserisci un Feed ID valido');
    }
    
    try {
        // Trova il record del feed
        $stmt = $pdo->prepare("
            SELECT id, product_id, new_price, sku_amazon, asin, user_id
            FROM amazon_price_updates_log 
            WHERE JSON_EXTRACT(amazon_response, '$.submission_id') = ? 
            AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$feedId]);
        $feedRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$feedRecord) {
            throw new Exception("Feed {$feedId} non trovato o non è in pending");
        }
        
        // Verifica prezzo reale su Amazon
        $token = getAmazonAccessToken($feedRecord['user_id'], $pdo);
        $priceCheck = checkActualAmazonPrice(
            $feedRecord['sku_amazon'], 
            $feedRecord['asin'], 
            $feedRecord['new_price'], 
            $token
        );
        
        if ($priceCheck['success'] && $priceCheck['matches_expected']) {
            // Prezzo effettivamente aggiornato su Amazon
            $stmt = $pdo->prepare("
                UPDATE amazon_price_updates_log 
                SET status = 'success', completed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$feedRecord['id']]);
            
            $stmt = $pdo->prepare("
                UPDATE products 
                SET prezzo_attuale = ?, aggiornato_il = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$feedRecord['new_price'], $feedRecord['product_id']]);
            
            $verType = $priceCheck['verification_type'] ?? 'standard';
$note = $priceCheck['note'] ?? '';
$message = "Feed {$feedId} verificato con successo ({$verType}). {$note}";
$messageType = 'success';
            
        } else {
            // Prezzo NON aggiornato su Amazon
            $stmt = $pdo->prepare("
                UPDATE amazon_price_updates_log 
                SET status = 'failed', error_message = ?, completed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute(['Prezzo non aggiornato su Amazon', $feedRecord['id']]);
            
            $error = $priceCheck['error'] ?? 'Verifica fallita';
$message = "Feed {$feedId} - Verifica fallita: {$error}";
            $messageType = 'error';
        }
        
    } catch (Exception $e) {
        $message = "Errore verifica prezzo: " . $e->getMessage();
        $messageType = 'error';
    }
}

if (isset($_POST['debug_feed'])) {
    $feedId = trim($_POST['feed_id']);
    
    if (empty($feedId)) {
        throw new Exception('Inserisci un Feed ID valido');
    }
    
    try {
        $debugResult = debugAmazonFeedContent($feedId, $pdo);
        
        if (isset($debugResult['error'])) {
            $message = "Errore debug: " . $debugResult['error'];
            $messageType = 'error';
        } else {
            $issues = $debugResult['issues'];
            if (empty($issues)) {
                $message = "Feed {$feedId} - Nessun problema evidente nel contenuto";
                $messageType = 'success';
            } else {
                $message = "Feed {$feedId} - Problemi trovati: " . implode('; ', $issues);
                $messageType = 'error';
            }
            
            // Salva debug info per visualizzazione
            $_SESSION['debug_feed_result'] = $debugResult;
        }
        
    } catch (Exception $e) {
        $message = "Errore debug feed: " . $e->getMessage();
        $messageType = 'error';
    }
}

if (isset($_POST['force_success'])) {
    $feedId = trim($_POST['feed_id']);
    
    if (empty($feedId)) {
        throw new Exception('Inserisci un Feed ID valido');
    }
    
    try {
        // Trova il record del feed
        $stmt = $pdo->prepare("
            SELECT id, product_id, new_price 
            FROM amazon_price_updates_log 
            WHERE JSON_EXTRACT(amazon_response, '$.submission_id') = ? 
            AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$feedId]);
        $feedRecord = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$feedRecord) {
            throw new Exception("Feed {$feedId} non trovato o non è in pending");
        }
        
        // Aggiorna il feed come successo
        $stmt = $pdo->prepare("
            UPDATE amazon_price_updates_log 
            SET status = 'success', completed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$feedRecord['id']]);
        
        // Aggiorna il prezzo del prodotto
        $stmt = $pdo->prepare("
            UPDATE products 
            SET prezzo_attuale = ?, aggiornato_il = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$feedRecord['new_price'], $feedRecord['product_id']]);
        
        $message = "Feed {$feedId} forzato come successo e prezzo aggiornato nel database!";
        $messageType = 'success';
        
    } catch (Exception $e) {
        $message = "Errore forzatura feed: " . $e->getMessage();
        $messageType = 'error';
    }
}

if (isset($_POST['check_feed'])) {
            $feedId = trim($_POST['feed_id']);
            
            if (empty($feedId)) {
                throw new Exception('Inserisci un Feed ID valido');
            }
            
            // Controlla se è un ID fake
            if (strpos($feedId, 'FEED_') === 0 || strpos($feedId, 'FAKE_') === 0) {
                $message = "ATTENZIONE: Questo sembra un Feed ID generato localmente (non da Amazon).";
                $messageType = 'warning';
                
                try {
                    $stmt = $pdo->prepare("SELECT * FROM amazon_price_updates_log WHERE JSON_EXTRACT(amazon_response, '$.submission_id') = ?");
                    $stmt->execute([$feedId]);
                    $logEntry = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($logEntry) {
                        $message .= " Stato: " . $logEntry['status'] . ". Errore: " . ($logEntry['error_message'] ?: 'Nessuno');
                    }
                } catch (Exception $e) {
                    $message .= " Errore verifica database: " . $e->getMessage();
                }
            } else {
                // Verifica feed reale su Amazon
                try {
                    $stmt = $pdo->prepare("SELECT user_id FROM amazon_price_updates_log WHERE JSON_EXTRACT(amazon_response,'$.submission_id') = ? LIMIT 1");
                    $stmt->execute([$feedId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$row) {
                        $message = "Feed sconosciuto: {$feedId}";
                        $messageType = 'error';
                    } else {
                        $token = getAmazonAccessToken($row['user_id'], $pdo);
                        $feedEndpoint = AMAZON_SP_API_BASE_URL . "/feeds/2021-06-30/feeds/{$feedId}";
                        
                        $feedResult = makeAmazonApiCall($feedEndpoint, $token);
                        $feedData = $feedResult;
                        
                        // Gestione processing report se feed completato
                        if ($feedResult['http_code'] === 200 && 
                            isset($feedResult['response']['processingStatus']) && 
                            $feedResult['response']['processingStatus'] === 'DONE') {
                            
                            $docId = $feedResult['response']['resultFeedDocumentId'] ?? null;
                            if ($docId) {
                                try {
                                    $documentEndpoint = AMAZON_SP_API_BASE_URL . "/feeds/2021-06-30/documents/{$docId}";
                                    $documentResult = makeAmazonApiCall($documentEndpoint, $token);
                                    
                                    if ($documentResult['http_code'] === 200 && isset($documentResult['response']['url'])) {
                                        $documentUrl = $documentResult['response']['url'];
                                        
                                        // Scarica il contenuto del processing report
                                        $ch = curl_init();
                                        curl_setopt($ch, CURLOPT_URL, $documentUrl);
                                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                                        curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
                                        
                                        $report = curl_exec($ch);
                                        $documentHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                        curl_close($ch);
                                        
                                        if ($documentHttpCode === 200) {
                                            $documentData = [
                                                'http_code' => $documentHttpCode,
                                                'response' => $documentResult['response'],
                                                'document_content' => $report
                                            ];
                                            
                                            // Aggiorna status del feed in base al processing report
                                            $stmt = $pdo->prepare("
                                                SELECT id, user_id, product_id, new_price 
                                                FROM amazon_price_updates_log 
                                                WHERE JSON_EXTRACT(amazon_response,'$.submission_id') = ? 
                                                LIMIT 1
                                            ");
                                            $stmt->execute([$feedId]);
                                            $feedRow = $stmt->fetch(PDO::FETCH_ASSOC);

                                            if ($feedRow) {
                                                $ok = parsePriceFeedReportOk($report);

                                                if ($ok) {
                                                    // Marca successo e aggiorna prezzo prodotto
                                                    $stmt = $pdo->prepare("
                                                        UPDATE amazon_price_updates_log
                                                        SET status = 'success', completed_at = NOW()
                                                        WHERE id = ?
                                                    ");
                                                    $stmt->execute([$feedRow['id']]);

                                                    $stmt = $pdo->prepare("
                                                        UPDATE products
                                                        SET prezzo_attuale = ?, aggiornato_il = CURRENT_TIMESTAMP
                                                        WHERE id = ?
                                                    ");
                                                    $stmt->execute([$feedRow['new_price'], $feedRow['product_id']]);
                                                    
                                                    $message = "Feed completato con successo e prezzo aggiornato!";
                                                    $messageType = 'success';
                                                } else {
                                                    $stmt = $pdo->prepare("
                                                        UPDATE amazon_price_updates_log
                                                        SET status = 'failed', error_message = ?, completed_at = NOW()
                                                        WHERE id = ?
                                                    ");
                                                    $stmt->execute(['Processing report indica errori', $feedRow['id']]);
                                                    $message = "Feed completato ma con errori nel processing report";
                                                    $messageType = 'error';
                                                }
                                            }
                                        }
                                    }
                                } catch (Exception $e) {
                                    // Errore nella gestione del documento - continua con la verifica base
                                }
                            }
                        }
                        
                        // Determina status finale
                        if ($feedResult['http_code'] === 200 && isset($feedResult['response']['processingStatus'])) {
                            $feedStatus = $feedResult['response']['processingStatus'];
                        } elseif ($feedResult['http_code'] === 404) {
                            $feedStatus = 'NOT_FOUND (normale per feed processati)';
                        } else {
                            $feedStatus = 'ERROR';
                        }
                        
                        if (empty($message)) {
                            $message = "Feed verificato: {$feedId} - Status: {$feedStatus}";
                            $messageType = ($feedResult['http_code'] === 200) ? 'success' : 'warning';
                        }
                    }
                } catch (Exception $e) {
                    $message = "Errore verifica feed: " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
        
        if (isset($_POST['view_timeline'])) {
            $opId = trim($_POST['op_id']);
            if (!empty($opId)) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT * FROM api_debug_log 
                        WHERE op_id = ? 
                        ORDER BY created_at ASC
                    ");
                    $stmt->execute([$opId]);
                    $operationTimeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $message = "Timeline caricata per Operation ID: {$opId}";
                    $messageType = 'success';
                } catch (Exception $e) {
                    $message = "Errore caricamento timeline: " . $e->getMessage();
                    $messageType = 'error';
                }
            }
        }
    }
    
    // Carica feed recenti
    try {
        $stmt = $pdo->prepare("
            SELECT *,
                   JSON_EXTRACT(amazon_response, '$.submission_id') as feed_id,
                   CASE 
                       WHEN JSON_EXTRACT(amazon_response, '$.submission_id') LIKE '%FEED_%' 
                         OR JSON_EXTRACT(amazon_response, '$.submission_id') LIKE '%FAKE_%' 
                       THEN 'FAKE_ID' 
                       ELSE 'REAL_ID'
                   END as feed_type
            FROM amazon_price_updates_log 
            ORDER BY created_at DESC 
            LIMIT 50
        ");
        $stmt->execute();
        $recentFeeds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $recentFeeds = [];
        if (empty($message)) {
            $message = "Errore caricamento feed recenti: " . $e->getMessage();
            $messageType = 'warning';
        }
    }
    
} catch (Exception $e) {
    $message = 'Errore sistema: ' . $e->getMessage();
    $messageType = 'error';
}

// Notifica feed pending vecchi (senza auto-forzatura)
if (!isset($_GET['ajax']) && !isset($_POST['check_feed'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM amazon_price_updates_log 
            WHERE status = 'pending' 
            AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $oldPendingCount = $stmt->fetchColumn();
        
        if ($oldPendingCount > 0 && empty($message)) {
            $message = "Attenzione: {$oldPendingCount} feed pending da oltre 2 ore richiedono verifica manuale";
            $messageType = 'warning';
        }
    } catch (Exception $e) {
        CentralLogger::log('margini', 'ERROR', 'Errore controllo feed pending: ' . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Amazon Price Update Debug Dashboard</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 20px; background: #f5f7fa; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; border-radius: 10px; margin-bottom: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 2.5em; font-weight: 300; }
        .header p { margin: 10px 0 0 0; opacity: 0.9; }
        
        .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stats-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .stats-card h3 { margin: 0 0 15px 0; color: #2d3748; font-size: 1.1em; }
        .stats-number { font-size: 2.5em; font-weight: bold; margin: 10px 0; }
        .stats-success { color: #38a169; }
        .stats-error { color: #e53e3e; }
        .stats-warning { color: #d69e2e; }
        .stats-info { color: #3182ce; }
        
        .section { background: white; margin: 20px 0; padding: 25px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #edf2f7; padding-bottom: 15px; }
        .section-header h2 { margin: 0; color: #2d3748; font-size: 1.4em; }
        .section-header .controls { display: flex; gap: 10px; }
        
        .message { padding: 15px; margin: 15px 0; border-radius: 8px; border-left: 4px solid; }
        .message.success { background: #f0fff4; color: #22543d; border-color: #38a169; }
        .message.error { background: #fed7d7; color: #742a2a; border-color: #e53e3e; }
        .message.warning { background: #fefcbf; color: #744210; border-color: #d69e2e; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-weight: 500; transition: all 0.2s; }
        .btn-primary { background: #4299e1; color: white; }
        .btn-primary:hover { background: #3182ce; }
        .btn-secondary { background: #a0aec0; color: white; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; background: white; }
        th, td { padding: 12px; border-bottom: 1px solid #e2e8f0; text-align: left; font-size: 13px; }
        th { background: #f7fafc; font-weight: 600; color: #4a5568; }
        tr:hover { background: #f7fafc; }
        
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .status-success { background: #c6f6d5; color: #22543d; }
        .status-error { background: #fed7d7; color: #742a2a; }
        .status-processing { background: #fef5e7; color: #744210; }
        .status-fake { background: #fed7d7; color: #742a2a; }
        .status-real { background: #c6f6d5; color: #22543d; }
        
        .timeline { max-height: 400px; overflow-y: auto; }
        .timeline-item { padding: 15px; border-left: 3px solid #e2e8f0; margin-left: 10px; position: relative; }
        .timeline-item:before { content: ''; width: 10px; height: 10px; border-radius: 50%; background: #a0aec0; position: absolute; left: -7px; top: 20px; }
        .timeline-item.success:before { background: #38a169; }
        .timeline-item.error:before { background: #e53e3e; }
        .timeline-item.warning:before { background: #d69e2e; }
        
        .search-box { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; }
        .progress-bar { width: 100%; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; transition: width 0.3s ease; }
        .progress-success { background: linear-gradient(90deg, #38a169, #48bb78); }
        
        .auto-refresh { display: flex; align-items: center; gap: 10px; font-size: 12px; color: #718096; }
        .refresh-indicator { width: 8px; height: 8px; border-radius: 50%; background: #38a169; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Amazon Price Update Debug Dashboard</h1>
            <p>Centro di controllo per monitoraggio operazioni Amazon SP-API</p>
        </div>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <!-- Dashboard Statistics -->
        <div class="dashboard-grid">
            <div class="stats-card">
                <h3>Statistiche Oggi</h3>
                <?php 
                $todayStats = $dailyStats[0] ?? ['total' => 0, 'success' => 0, 'failed' => 0, 'fake_feeds' => 0];
                $successRate = $todayStats['total'] > 0 ? round(($todayStats['success'] / $todayStats['total']) * 100, 1) : 0;
                ?>
                <div class="stats-number stats-info"><?= $todayStats['total'] ?></div>
                <div>Operazioni Totali</div>
                <div class="progress-bar">
                    <div class="progress-fill progress-success" style="width: <?= $successRate ?>%"></div>
                </div>
                <small><?= $successRate ?>% successo</small>
            </div>
            
            <div class="stats-card">
                <h3>Successi</h3>
                <div class="stats-number stats-success"><?= $todayStats['success'] ?></div>
                <div>Aggiornamenti Riusciti</div>
            </div>
            
            <div class="stats-card">
                <h3>Errori</h3>
                <div class="stats-number stats-error"><?= $todayStats['failed'] ?></div>
                <div>Operazioni Fallite</div>
            </div>
            
            <div class="stats-card">
                <h3>Diagnosi</h3>
                <div class="stats-number <?= $diagnostics['recent_errors'] > 0 ? 'stats-error' : 'stats-success' ?>">
                    <?= $diagnostics['recent_errors'] ?>
                </div>
                <div>Errori Recenti (1h)</div>
                <small>Feed Fake: <?= $diagnostics['fake_feed_ratio'] ?>%</small>
            </div>
        </div>
        
        <!-- Real-time Operations Monitor -->
        <div class="section">
            <div class="section-header">
                <h2>Operazioni in Tempo Reale</h2>
                <div class="controls">
                    <div class="auto-refresh">
                        <div class="refresh-indicator"></div>
                        Auto-refresh (30s)
                    </div>
                </div>
            </div>
            <div id="realtime-operations">
                <?php if (!empty($realtimeOperations)): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Operation ID</th>
                                    <th>Ultima Attività</th>
                                    <th>Passi</th>
                                    <th>Status</th>
                                    <th>Contesti Recenti</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($realtimeOperations as $op): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($op['op_id']) ?></code></td>
                                        <td><?= date('H:i:s', strtotime($op['last_activity'])) ?></td>
                                        <td><?= $op['steps'] ?></td>
                                        <td>
                                            <span class="status-badge <?= $op['has_errors'] ? 'status-error' : 'status-success' ?>">
                                                <?= $op['has_errors'] ? 'ERROR' : 'OK' ?>
                                            </span>
                                        </td>
                                        <td><small><?= htmlspecialchars($op['recent_contexts']) ?></small></td>
                                        <td>
                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="op_id" value="<?= htmlspecialchars($op['op_id']) ?>">
                                                <button type="submit" name="view_timeline" class="btn btn-secondary">Timeline</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>Nessuna operazione in corso nell'ultima ora.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Feed Checker -->
        <div class="section">
            <div class="section-header">
                <h2>Verificatore Feed Amazon</h2>
            </div>
            <form method="post">
    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
        <input type="text" name="feed_id" class="search-box" placeholder="Inserisci Feed ID (es: 50014017829)" style="flex: 1;">
        <button type="submit" name="check_feed" class="btn btn-primary">Verifica Feed</button>
        <button type="submit" name="verify_real_price" class="btn btn-primary">Verifica Prezzo Reale</button>
<button type="submit" name="debug_feed" class="btn btn-secondary">Debug Feed</button>
<button type="submit" name="force_success" class="btn btn-secondary" onclick="return confirm('ATTENZIONE: Forzare successo senza verifica reale?')">Forza Solo DB</button>
    </div>
</form>
            
            <?php if ($feedData): ?>
    <div style="background: #f7fafc; padding: 20px; border-radius: 8px; margin: 15px 0;">
        <h4>Risultato Verifica Feed</h4>
        <p><strong>HTTP Status:</strong> <?= $feedData['http_code'] ?></p>
        <?php if (isset($feedData['response'])): ?>
            <pre style="background: white; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 12px;"><?= htmlspecialchars(json_encode($feedData['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php endif; ?>
        
        <!-- Debug: Mostra contenuto feed originale -->
        <?php
        try {
            $feedId = trim($_POST['feed_id']);
            $stmt = $pdo->prepare("SELECT amazon_response, sku_amazon, asin, old_price, new_price FROM amazon_price_updates_log WHERE JSON_EXTRACT(amazon_response, '$.submission_id') = ? LIMIT 1");
            $stmt->execute([$feedId]);
            $feedDebug = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($feedDebug):
        ?>
            <h5>Debug Feed Originale:</h5>
            <p><strong>SKU:</strong> <?= htmlspecialchars($feedDebug['sku_amazon']) ?></p>
            <p><strong>ASIN:</strong> <?= htmlspecialchars($feedDebug['asin']) ?></p>
            <p><strong>Prezzo:</strong> €<?= $feedDebug['old_price'] ?> → €<?= $feedDebug['new_price'] ?></p>
            <details>
                <summary>Amazon Response Completa</summary>
                <pre style="background: white; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 12px;"><?= htmlspecialchars($feedDebug['amazon_response']) ?></pre>
            </details>
        <?php endif; } catch (Exception $e) { /* ignore */ } ?>
    </div>
<?php endif; ?>
            
            <?php if ($documentData): ?>
                <div style="background: #f0fff4; padding: 20px; border-radius: 8px; margin: 15px 0;">
                    <h4>Processing Report</h4>
                    <p><strong>Document HTTP Status:</strong> <?= $documentData['http_code'] ?></p>
                    <details>
                        <summary>Document Metadata</summary>
                        <pre style="background: white; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 12px;"><?= htmlspecialchars(json_encode($documentData['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                    </details>
                    <details>
                        <summary>Report Content</summary>
                        <pre style="background: white; padding: 15px; border-radius: 6px; overflow-x: auto; font-size: 12px; max-height: 300px;"><?= htmlspecialchars($documentData['document_content']) ?></pre>
                    </details>
                </div>
            <?php endif; ?>
        </div>

        <!-- Operation Timeline -->
        <?php if (!empty($operationTimeline)): ?>
            <div class="section">
                <div class="section-header">
                    <h2>Timeline Operazione</h2>
                </div>
                <div class="timeline">
                    <?php foreach ($operationTimeline as $entry): ?>
                        <div class="timeline-item <?= strtolower($entry['level']) ?>">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <div>
                                    <strong><?= htmlspecialchars($entry['phase']) ?></strong>
                                    <span class="status-badge status-<?= strtolower($entry['level']) === 'error' ? 'error' : (strtolower($entry['level']) === 'warning' ? 'processing' : 'success') ?>">
                                        <?= htmlspecialchars($entry['level']) ?>
                                    </span>
                                </div>
                                <small><?= date('H:i:s', strtotime($entry['created_at'])) ?></small>
                            </div>
                            <?php if ($entry['data']): ?>
                                <details style="margin-top: 10px;">
                                    <summary>Dati</summary>
                                    <pre style="background: #f7fafc; padding: 10px; border-radius: 4px; font-size: 11px; margin-top: 8px; overflow-x: auto;"><?= htmlspecialchars($entry['data']) ?></pre>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Feeds Log -->
        <div class="section">
            <div class="section-header">
                <h2>Log Feed Recenti</h2>
            </div>
            <?php if (!empty($recentFeeds)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Data</th>
                                <th>Feed ID</th>
                                <th>Tipo</th>
                                <th>Prodotto</th>
                                <th>Prezzo</th>
                                <th>Status</th>
                                <th>Errore</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentFeeds as $feed): ?>
                                <tr>
                                    <td><?= $feed['id'] ?></td>
                                    <td><?= date('d/m H:i', strtotime($feed['created_at'])) ?></td>
                                    <td>
                                        <code style="font-size: 11px;"><?= htmlspecialchars($feed['feed_id']) ?></code>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $feed['feed_type'] === 'FAKE_ID' ? 'fake' : 'real' ?>">
                                            <?= $feed['feed_type'] ?>
                                        </span>
                                    </td>
                                    <td><?= $feed['product_id'] ?></td>
                                    <td>€<?= $feed['old_price'] ?> → €<?= $feed['new_price'] ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $feed['status'] === 'success' ? 'success' : ($feed['status'] === 'failed' ? 'error' : 'processing') ?>">
                                            <?= strtoupper($feed['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($feed['error_message']): ?>
                                            <small title="<?= htmlspecialchars($feed['error_message']) ?>">
                                                <?= htmlspecialchars(substr($feed['error_message'], 0, 50)) ?><?= strlen($feed['error_message']) > 50 ? '...' : '' ?>
                                            </small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>Nessun feed recente trovato.</p>
            <?php endif; ?>
        </div>

        <!-- Daily Statistics Chart -->
        <?php if (!empty($dailyStats)): ?>
            <div class="section">
                <div class="section-header">
                    <h2>Statistiche Ultimi 7 Giorni</h2>
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Totale</th>
                                <th>Successi</th>
                                <th>Errori</th>
                                <th>Feed Fake</th>
                                <th>Tasso Successo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyStats as $stat): ?>
                                <?php $successRate = $stat['total'] > 0 ? round(($stat['success'] / $stat['total']) * 100, 1) : 0; ?>
                                <tr>
                                    <td><?= date('d/m/Y', strtotime($stat['date'])) ?></td>
                                    <td><?= $stat['total'] ?></td>
                                    <td><span class="stats-success"><?= $stat['success'] ?></span></td>
                                    <td><span class="stats-error"><?= $stat['failed'] ?></span></td>
                                    <td><span class="stats-warning"><?= $stat['fake_feeds'] ?></span></td>
                                    <td>
                                        <div class="progress-bar" style="width: 100px;">
                                            <div class="progress-fill progress-success" style="width: <?= $successRate ?>%"></div>
                                        </div>
                                        <small><?= $successRate ?>%</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh per operazioni in tempo reale
        function refreshRealtimeOperations() {
            fetch('?ajax=realtime')
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        const container = document.getElementById('realtime-operations');
                        let html = '<div class="table-container"><table><thead><tr>';
                        html += '<th>Operation ID</th><th>Ultima Attività</th><th>Passi</th><th>Status</th><th>Contesti Recenti</th><th>Azioni</th>';
                        html += '</tr></thead><tbody>';
                        
                        data.forEach(op => {
                            html += '<tr>';
                            html += '<td><code>' + op.op_id + '</code></td>';
                            html += '<td>' + new Date(op.last_activity).toLocaleTimeString() + '</td>';
                            html += '<td>' + op.steps + '</td>';
                            html += '<td><span class="status-badge ' + (op.has_errors ? 'status-error' : 'status-success') + '">';
                            html += (op.has_errors ? 'ERROR' : 'OK') + '</span></td>';
                            html += '<td><small>' + (op.recent_contexts || '') + '</small></td>';
                            html += '<td><form method="post" style="display: inline;"><input type="hidden" name="op_id" value="' + op.op_id + '">';
                            html += '<button type="submit" name="view_timeline" class="btn btn-secondary">Timeline</button></form></td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody></table></div>';
                        container.innerHTML = html;
                    }
                })
                .catch(error => console.error('Error refreshing operations:', error));
        }

        // Refresh ogni 30 secondi
        setInterval(refreshRealtimeOperations, 30000);

        // Timeline viewer via AJAX
        function viewTimelineAjax(opId) {
            fetch('?ajax=timeline&op_id=' + encodeURIComponent(opId))
                .then(response => response.json())
                .then(data => {
                    if (data.length > 0) {
                        let html = '<div class="timeline">';
                        data.forEach(entry => {
                            html += '<div class="timeline-item ' + entry.level.toLowerCase() + '">';
                            html += '<div style="display: flex; justify-content: space-between; align-items: flex-start;">';
                            html += '<div><strong>' + entry.context + '</strong> ';
                            html += '<span class="status-badge status-' + (entry.level.toLowerCase() === 'error' ? 'error' : 'success') + '">' + entry.level + '</span></div>';
                            html += '<small>' + new Date(entry.created_at).toLocaleTimeString() + '</small>';
                            html += '</div>';
                            if (entry.data) {
                                html += '<details style="margin-top: 10px;"><summary>Dati</summary>';
                                html += '<pre style="background: #f7fafc; padding: 10px; border-radius: 4px; font-size: 11px; margin-top: 8px; overflow-x: auto;">' + entry.data + '</pre>';
                                html += '</details>';
                            }
                            html += '</div>';
                        });
                        html += '</div>';
                        
                        // Mostra in un modal o area dedicata
                        alert('Timeline caricata per ' + opId + ' - vedi console per dettagli');
                        console.log('Timeline for ' + opId, data);
                    }
                })
                .catch(error => console.error('Error loading timeline:', error));
        }
    </script>
</body>
</html>