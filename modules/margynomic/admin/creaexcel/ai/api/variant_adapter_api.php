<?php
/**
 * Variant Adapter API
 * Endpoint per generare varianti da master
 */

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/variant_adapter_api_errors.log');

// Cattura SOLO errori critici (non Notice/Warning)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Ignora Notice e Warning (solo Fatal/Parse/Error)
    if ($errno === E_NOTICE || $errno === E_WARNING || $errno === E_DEPRECATED) {
        error_log("PHP Notice/Warning [$errno]: $errstr in $errfile:$errline");
        return true; // Suppress error
    }
    
    error_log("PHP Error [$errno]: $errstr in $errfile:$errline");
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => "Server error: $errstr",
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
});

// Cattura exception non gestite
set_exception_handler(function($exception) {
    error_log("PHP Exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => "Exception: " . $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine()
    ]);
    exit;
});

// Buffer output per evitare output accidentale
ob_start();

session_start();

require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../config/CentralLogger.php';
require_once __DIR__ . '/../core/VariantAdapter.php';

// Pulisci buffer e imposta header
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// Check auth
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$userId = $_SESSION['selected_user_id'] ?? $_SESSION['admin_id'];

// Log richiesta
error_log("=== Variant Adapter API Request ===");
error_log("User ID: $userId");
error_log("Session admin_id: " . ($_SESSION['admin_id'] ?? 'not set'));
error_log("Session selected_user_id: " . ($_SESSION['selected_user_id'] ?? 'not set'));

try {
    // Parse request
    $rawInput = file_get_contents('php://input');
    error_log("Raw input: " . substr($rawInput, 0, 500));
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    error_log("Parsed input: " . print_r($input, true));
    
    $filepath = $input['filepath'] ?? null;
    $masterRowNumber = (int)($input['master_row_number'] ?? 0);
    $variantRowNumbers = array_map('intval', $input['variant_row_numbers'] ?? []);
    
    if (!$filepath || !$masterRowNumber || empty($variantRowNumbers)) {
        throw new Exception('filepath, master_row_number e variant_row_numbers richiesti');
    }
    
    if (count($variantRowNumbers) > 10) {
        throw new Exception('Massimo 10 varianti per batch');
    }
    
    error_log("Filepath: $filepath");
    error_log("Master row: $masterRowNumber");
    error_log("Variant rows: " . implode(', ', $variantRowNumbers));
    
    // Security: verifica che filepath appartenga all'user
    if (strpos($filepath, $userId . '/') === false) {
        error_log("Security check failed: filepath doesn't contain user ID $userId");
        throw new Exception('Accesso negato al file');
    }
    
    error_log("Security check passed, creating VariantAdapter");
    
    // Execute adaptation
    $adapter = new VariantAdapter($userId);
    
    // DEBUG: Leggi master row per verificare headers
    error_log("=== DEBUG: Verifico headers Excel ===");
    $engine = new AiEngine($userId);
    $masterCheck = $engine->getRow($filepath, $masterRowNumber);
    if ($masterCheck['success']) {
        error_log("Master row fields disponibili: " . implode(', ', array_keys($masterCheck['data'])));
        error_log("item_sku presente: " . (isset($masterCheck['data']['item_sku']) ? 'SI' : 'NO'));
        error_log("item_sku valore: " . ($masterCheck['data']['item_sku'] ?? '(vuoto)'));
        error_log("Headers Excel: " . print_r($masterCheck['headers'], true));
    } else {
        error_log("Errore lettura master row: " . ($masterCheck['error'] ?? 'unknown'));
    }
    
    error_log("VariantAdapter created, calling adaptVariants");
    
    $results = $adapter->adaptVariants($filepath, $masterRowNumber, $variantRowNumbers);
    
    error_log("adaptVariants completed, results count: " . count($results));
    
    // Response
    echo json_encode([
        'success' => true,
        'results' => $results,
        'variants_count' => count($results)
    ]);
    
} catch (Exception $e) {
    CentralLogger::error('variant_adapter_api', 'Request failed', [
        'user_id' => $userId,
        'error' => $e->getMessage()
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
