<?php
require_once 'config_shared.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$currentUser = requireUserAuth();
$userId = $currentUser['id'];

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['product_id'])) {
    echo json_encode(['success' => false, 'error' => 'Product ID required']);
    exit;
}

$productId = (int)$input['product_id'];
$feeType = $input['fee_type'] ?? 'none';
$feeValue = $input['fee_value'] ?? 0;
$feeDescription = $input['fee_description'] ?? null;

// Validazione
$validTypes = ['none', 'percent', 'fixed'];
if (!in_array($feeType, $validTypes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid fee type']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    // Verifica che il prodotto appartenga all'utente
    $stmt = $pdo->prepare("SELECT id FROM products WHERE id = ? AND user_id = ?");
    $stmt->execute([$productId, $userId]);
    
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }
    
    // Aggiorna custom fee
    $stmt = $pdo->prepare("
        UPDATE products 
        SET custom_fee_type = ?, 
            custom_fee_value = ?, 
            custom_fee_description = ?
        WHERE id = ? AND user_id = ?
    ");
    
    $stmt->execute([$feeType, $feeValue, $feeDescription, $productId, $userId]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>