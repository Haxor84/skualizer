<?php
/**
 * Overview Controller - API Endpoints per Dashboard OrderInsights
 * File: modules/orderinsights/OverviewController.php
 */

header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', 0); // No HTML output in JSON responses
error_reporting(E_ERROR | E_PARSE); // Disabilita warning e notice per output JSON pulito

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';
require_once 'OverviewModel.php';

// Include Mobile Cache System (TTL: 48h, invalidazione event-driven)
require_once __DIR__ . '/../mobile/helpers/mobile_cache_helper.php';

try {
    // Verifica autenticazione
    if (!isLoggedIn()) {
        throw new Exception('Autenticazione richiesta', 401);
    }

    $currentUser = getCurrentUser();
    $userId = $currentUser['id'];
    if (!$userId) {
        throw new Exception('User ID non valido', 401);
    }
    
    // Verifica che la tabella esista
    $tableName = "report_settlement_{$userId}";
    $pdo = getDbConnection();
$stmt = $pdo->query("SHOW TABLES LIKE '" . addslashes($tableName) . "'");
if (!$stmt->fetchColumn()) {
    throw new Exception('Nessun dato disponibile per questo utente', 404);
}
    
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'month_summary':
            handleMonthSummary($userId);
            break;
            
        case 'day_summary':
            handleDaySummary($userId);
            break;
            
        case 'orders':
            handleOrders($userId);
            break;
            
        case 'order_detail':
            handleOrderDetail($userId);
            break;
            
        case 'day_index':
            handleDayIndex($userId);
            break;
            
        case 'get_full_range':
            handleGetFullRange($userId);
            break;
            
        default:
            throw new Exception('Azione non valida', 400);
    }
    
} catch (Exception $e) {
    http_response_code((int)($e->getCode() ?: 500));
    
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => (int)($e->getCode() ?: 500),
            'message' => $e->getMessage()
        ]
    ]);
}

/**
 * GET ?action=month_summary&month=YYYY-MM (o start/end)
 */
function handleMonthSummary($userId) {
    $month = $_GET['month'] ?? null;
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;
    $includeReserve = isset($_GET['include_reserve']) && $_GET['include_reserve'] === '1';
    
    // === CACHE SYSTEM (TTL: 48h, invalidazione event-driven) ===
    // Cache key univoca per parametri richiesta
    $cacheKey = 'orders_summary_' . md5(json_encode(compact('month', 'start', 'end', 'includeReserve')));
    $cachedData = getMobileCache($userId, $cacheKey, 172800); // 48h
    
    if ($cachedData !== null) {
        // Cache HIT - ritorna dati cachati
        echo json_encode($cachedData);
        return;
    }
    
    // === CACHE MISS - Calcola dati freschi ===
    
    // Ottieni range date
    $dateRange = OverviewModel::getDateRange($month, $start, $end);
    
    // Ottieni summary ORIGINALE (con streaming fix)
    $summary = OverviewModel::monthSummary($dateRange['from'], $dateRange['to'], $userId, $includeReserve);
    
    // Verifica warning conversione EUR
    $eurWarning = OverviewModel::hasEurConversionWarning($userId);
    
    $response = [
        'success' => true,
        'data' => $summary,
        'date_range' => $dateRange,
        'eur_conversion_warning' => $eurWarning
    ];
    
    // Salva in cache
    setMobileCache($userId, $cacheKey, $response);
    
    echo json_encode($response);
}

/**
 * GET ?action=day_summary&day=YYYY-MM-DD
 */
function handleDaySummary($userId) {
    $day = $_GET['day'] ?? '';
    
    if (!$day || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        throw new Exception('Parametro day richiesto (formato YYYY-MM-DD)', 400);
    }
    
    $summary = OverviewModel::daySummary($day, $userId);
    
    echo json_encode([
        'success' => true,
        'data' => $summary,
        'day' => $day
    ]);
}

/**
 * GET ?action=orders&day=YYYY-MM-DD
 */
function handleOrders($userId) {
    $day = $_GET['day'] ?? '';
    
    if (!$day || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        throw new Exception('Parametro day richiesto (formato YYYY-MM-DD)', 400);
    }
    
    $orders = OverviewModel::ordersByDay($day, $userId);
    
    echo json_encode([
        'success' => true,
        'data' => $orders,
        'day' => $day,
        'count' => count($orders)
    ]);
}

/**
 * GET ?action=order_detail&order_id=...
 */
function handleOrderDetail($userId) {
    $orderId = $_GET['order_id'] ?? '';
    
    if (!$orderId) {
        throw new Exception('Parametro order_id richiesto', 400);
    }
    
    $details = OverviewModel::orderDetail($orderId, $userId);
    
    if (empty($details)) {
        throw new Exception('Ordine non trovato', 404);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $details,
        'order_id' => $orderId,
        'count' => count($details)
    ]);
}

/**
 * GET ?action=day_index&offset=0&limit=30&month=YYYY-MM|start/end
 */
function handleDayIndex($userId) {
    $month = $_GET['month'] ?? null;
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
    
    // === CACHE SYSTEM (TTL: 48h, invalidazione event-driven) ===
    // Cache key univoca per parametri richiesta
    $cacheKey = 'day_index_' . md5(json_encode(compact('month', 'start', 'end', 'offset', 'limit')));
    $cachedData = getMobileCache($userId, $cacheKey, 172800); // 48h
    
    if ($cachedData !== null) {
        // Cache HIT - ritorna dati cachati
        echo json_encode($cachedData);
        return;
    }
    
    // === CACHE MISS - Calcola dati freschi ===

    $dateRange = OverviewModel::getDateRange($month, $start, $end);
    $result = OverviewModel::dayIndex($dateRange['from'], $dateRange['to'], $userId, $offset, $limit);

    $response = [
        'success' => true,
        'data' => $result['rows'],
        'offset' => $offset,
        'limit' => $limit,
        'total' => $result['total']
    ];
    
    // Salva in cache
    setMobileCache($userId, $cacheKey, $response);

    echo json_encode($response);
}

/**
 * GET ?action=get_full_range - Ottiene il range completo di date disponibili
 */
function handleGetFullRange($userId) {
    try {
        $tableName = "report_settlement_{$userId}";
        $pdo = getDbConnection();
        
        // Ottieni la prima e l'ultima data disponibile
        $stmt = $pdo->prepare("
            SELECT 
                MIN(DATE(posted_date)) as min_date,
                MAX(DATE(posted_date)) as max_date,
                COUNT(DISTINCT DATE(posted_date)) as total_days,
                COUNT(*) as total_transactions
            FROM `{$tableName}` 
            WHERE posted_date IS NOT NULL
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if (!$result || !$result['min_date']) {
            throw new Exception('Nessun dato disponibile', 404);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'min_date' => $result['min_date'],
                'max_date' => $result['max_date'],
                'total_days' => (int)$result['total_days'],
                'total_transactions' => (int)$result['total_transactions'],
                'suggested_start' => $result['min_date'],
                'suggested_end' => $result['max_date']
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Errore nel recupero del range: ' . $e->getMessage(), 500);
    }
}
?> 