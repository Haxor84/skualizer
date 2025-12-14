<?php
/**
 * AJAX Handler per Admin Margynomic - VERSIONE SEMPLIFICATA
 * File: admin/admin_ajax.php
 * 
 * Solo le azioni essenziali, zero quarantena
 */

// Headers per AJAX
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

// Avvia sessione e includi helpers
session_start();
require_once 'admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

// Ottieni azione richiesta
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        
        case 'save_single_mapping':
            handleSaveSingleMapping();
            break;
            
        case 'save_bulk_mappings':
            handleSaveBulkMappings();
            break;
            
        case 'get_ai_suggestion':
            handleGetAiSuggestion();
            break;
            
        case 'check_product_id_uniqueness':
            handleCheckProductIdUniqueness();
            break;
            
        case 'get_sku_mappings':
            handleGetSkuMappings();
            break;
            
        default:
            throw new Exception('Azione non supportata: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}

/**
 * Salva mapping singolo SKU
 */
function handleSaveSingleMapping() {
    $userId = (int)($_POST['user_id'] ?? 0);
    $sku = trim($_POST['sku'] ?? '');
    $productId = trim($_POST['product_id'] ?? '') ?: null;
    $productName = trim($_POST['product_name'] ?? '') ?: null;
    
    if (!$userId || !$sku) {
        throw new Exception('Parametri mancanti (user_id, sku)');
    }
    
    $result = saveSingleMapping($userId, $sku, $productId, $productName);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => $result['message'],
            'product_id' => $result['product_id'],
            'product_name' => $productName,
            'is_new_product' => $result['is_new_product'] ?? false
        ]);
    } else {
        throw new Exception($result['error']);
    }
}

/**
 * Salva mappings multipli (bulk)
 */
function handleSaveBulkMappings() {
    $userId = (int)($_POST['user_id'] ?? 0);
    $mappingsJson = $_POST['mappings'] ?? '';
    
    if (!$userId || !$mappingsJson) {
        throw new Exception('Parametri mancanti (user_id, mappings)');
    }
    
    $mappings = json_decode($mappingsJson, true);
    if (!is_array($mappings)) {
        throw new Exception('Formato mappings non valido');
    }
    
    $result = saveBulkMappings($userId, $mappings);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => "Salvate {$result['success_count']} modifiche" . 
                        ($result['error_count'] > 0 ? ", {$result['error_count']} errori" : ''),
            'processed' => $result['processed'],
            'success_count' => $result['success_count'],
            'error_count' => $result['error_count'],
            'results' => $result['results']
        ]);
    } else {
        throw new Exception($result['error']);
    }
}

/**
 * Ottieni suggerimento AI per SKU
 */
function handleGetAiSuggestion() {
    $userId = (int)($_POST['user_id'] ?? 0);
    $sku = trim($_POST['sku'] ?? '');
    
    if (!$userId || !$sku) {
        throw new Exception('Parametri mancanti (user_id, sku)');
    }
    
    $result = getAiSuggestionForSku($userId, $sku);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'suggestion' => $result['suggestion'],
            'categoria' => $result['categoria'] ?? '',
            'confidenza' => $result['confidenza'] ?? 5
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => $result['error']
        ]);
    }
}

/**
 * Controlla unicità Product ID
 */
function handleCheckProductIdUniqueness() {
    $userId = (int)($_POST['user_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $excludeSku = trim($_POST['exclude_sku'] ?? '');
    
    if (!$userId || !$productId) {
        throw new Exception('Parametri mancanti (user_id, product_id)');
    }
    
    $result = checkProductIdUniqueness($userId, $productId, $excludeSku ?: null);
    
    echo json_encode([
        'success' => true,
        'is_unique' => $result['is_unique'],
        'conflicts' => $result['conflicts'],
        'message' => $result['is_unique'] ? 
            'Product ID disponibile' : 
            'Product ID già usato per: ' . implode(', ', $result['conflicts'])
    ]);
}

/**
 * Ottieni mappings SKU per utente
 */
function handleGetSkuMappings() {
    $userId = (int)($_GET['user_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 50);
    
    if (!$userId) {
        throw new Exception('User ID mancante');
    }
    
    $mappings = getUnmappedSkus($userId, $limit);
    
    // Calcola statistiche
    $totalSkus = count($mappings);
    $mappedCount = count(array_filter($mappings, function($s) { 
        return !empty($s['product_id']); 
    }));
    $unmappedCount = $totalSkus - $mappedCount;
    $mappingPercentage = $totalSkus > 0 ? round(($mappedCount / $totalSkus) * 100, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'mappings' => $mappings,
        'stats' => [
            'total_skus' => $totalSkus,
            'mapped_count' => $mappedCount,
            'unmapped_count' => $unmappedCount,
            'mapping_percentage' => $mappingPercentage
        ]
    ]);
}

/**
 * Funzione helper per validazione parametri
 */
function validateRequiredParams($params, $required) {
    foreach ($required as $param) {
        if (!isset($params[$param]) || empty($params[$param])) {
            throw new Exception("Parametro richiesto mancante: {$param}");
        }
    }
}

/**
 * Funzione helper per logging AJAX
 */
function logAjaxAction($action, $userId = null, $details = []) {
    $logData = [
        'action' => $action,
        'user_id' => $userId,
        'timestamp' => date('Y-m-d H:i:s'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'details' => $details
    ];
    
    // Log semplice (potresti espandere questo per salvare in database)
    error_log("AJAX Action: " . json_encode($logData));
}
?>