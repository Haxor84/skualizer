<?php
/**
 * API Endpoint: Save Product Order
 * File: modules/listing/api/save_order.php
 * 
 * Salva ordinamento prodotti dall'admin_list.php
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Disabilita output errori per evitare contaminazione JSON
error_reporting(0);
ini_set('display_errors', 0);

// Gestione errori FATAL
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Fatal error: ' . $error['message']]);
        }
    }
});

try {
    // Includi helpers
    require_once __DIR__ . '/../helpers.php';
    
    // Verifica autenticazione admin
    requireListingAdmin();
    
    // Verifica metodo HTTP
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito');
    }
    
    // Leggi JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON non valido');
    }
    
    // Parametri richiesti
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $productPositions = isset($data['product_positions']) ? $data['product_positions'] : [];
    
    if ($userId <= 0) {
        throw new Exception('user_id richiesto e deve essere > 0');
    }
    
    if (!is_array($productPositions) || empty($productPositions)) {
        throw new Exception('product_positions deve essere un array non vuoto');
    }
    
    // Validazione: array associativo [product_id => position]
    $validatedPositions = [];
    foreach ($productPositions as $productId => $position) {
        $id = (int)$productId;
        $pos = (int)$position;
        
        if ($id <= 0 || $pos < 1) {
            throw new Exception('product_id e position devono essere positivi');
        }
        
        $validatedPositions[$id] = $pos;
    }
    
    $validatedProductIds = array_keys($validatedPositions);
    
    // Verifica che i prodotti appartengano all'utente
    $pdo = getListingDbConnection();
    $placeholders = str_repeat('?,', count($validatedProductIds) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM products 
        WHERE user_id = ? AND id IN ({$placeholders})
    ");
    $params = array_merge([$userId], $validatedProductIds);
    $stmt->execute($params);
    $validCount = $stmt->fetchColumn();
    
    if ($validCount !== count($validatedProductIds)) {
        throw new Exception('Alcuni prodotti non appartengono all\'utente specificato');
    }
    
    // Log debug tecnico opzionale
    if (class_exists('ApiDebugLogger')) {
        $logger = new ApiDebugLogger();
        $logger->info('SAVE_ORDER_REQUEST', [
            'user_id' => $userId,
            'products_count' => count($validatedProductIds),
            'admin_session' => $_SESSION['admin_logged'] ?? false
        ]);
    }
    
    // Salva con posizioni custom
    $result = saveProductOrderWithPositions($userId, $validatedPositions);
    
    if (!$result['success']) {
        throw new Exception($result['message']);
    }
    
    // Risposta successo
    $response = [
        'success' => true,
        'message' => $result['message'],
        'data' => [
            'user_id' => $userId,
            'updated_count' => $result['updated_count'],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(400);
    
    // Log errore opzionale
    if (class_exists('ApiDebugLogger')) {
        $logger = new ApiDebugLogger();
        $logger->error('SAVE_ORDER_ERROR', [
            'error' => $e->getMessage(),
            'input' => $input ?? null,
            'user_id' => $userId ?? null
        ]);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 