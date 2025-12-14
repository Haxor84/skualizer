<?php
require_once 'config_shared.php';
require_once 'margins_engine.php';
require_once __DIR__ . '/../../listing/helpers.php';

header('Content-Type: application/json');

try {
    $currentUser = requireUserAuth();
    $userId = $currentUser['id'];
    
    $productId = intval($_GET['product_id'] ?? 0);
    
    if ($productId <= 0) {
        throw new Exception('Product ID richiesto');
    }
    
    $engine = new MarginsEngine($userId);
    $breakdown = $engine->getFeeBreakdown($productId);
    
    echo json_encode([
        'success' => true,
        'breakdown' => $breakdown
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>