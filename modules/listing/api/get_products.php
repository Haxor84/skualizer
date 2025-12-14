<?php
/**
 * API Endpoint: Get Products with Order
 * File: modules/listing/api/get_products.php
 * 
 * Restituisce lista prodotti con ordinamento per admin_list.php
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Metodo non consentito');
    }
    
    // Parametri richiesti
    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    if ($userId <= 0) {
        throw new Exception('user_id richiesto e deve essere > 0');
    }
    
    // Parametri opzionali
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Filtri per helpers - NESSUN LIMITE per vedere tutto il catalogo
    $filters = [
        'search' => $search
        // Rimossi limit e offset per mostrare tutto
    ];
    
    // Ottieni prodotti
    $result = getProductsWithOrder($userId, $filters);
    
    if ($result === false) {
        throw new Exception('Errore recupero prodotti');
    }
    
    // Formatta risposta - NESSUNA PAGINAZIONE
    $response = [
        'success' => true,
        'data' => [
            'products' => $result['products'],
            'total_count' => $result['total_count'],
            'filters' => [
                'user_id' => $userId,
                'search' => $search
            ]
        ]
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?> 