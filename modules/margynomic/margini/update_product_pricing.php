<?php
/**
 * Update Product Pricing - Endpoint per aggiornare prezzi e costi prodotto
 * File: modules/margynomic/margini/update_product_pricing.php
 */

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

// Headers per JSON e CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include configurazione
require_once 'config_shared.php';

// Include cache helper per invalidamento
require_once dirname(dirname(__DIR__)) . '/mobile/helpers/mobile_cache_helper.php';

// Usa il sistema di logging centralizzato
function logPricingUpdate($message, $level = 'INFO') {
    CentralLogger::log('margini', $level, $message);
}

try {
    // Verifica metodo POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Metodo non consentito. Utilizzare POST.');
    }

    // Verifica autenticazione utente
    $currentUser = requireUserAuth();
    $userId = $currentUser['id'];
    
    // Log rimosso - troppo verboso

    // Leggi e valida input JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON non valido: ' . json_last_error_msg());
    }

    // Validazione parametri obbligatori
    $requiredFields = ['product_id', 'prezzo_attuale', 'costo_prodotto'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Campo obbligatorio mancante: {$field}");
        }
    }

    $productId = (int) $input['product_id'];
    $prezzoAttuale = (float) $input['prezzo_attuale'];
    $costoProdotto = (float) $input['costo_prodotto'];

    // Validazioni business
    if ($productId <= 0) {
        throw new Exception('ID prodotto non valido');
    }

    if ($prezzoAttuale < 0) {
        throw new Exception('Il prezzo deve essere positivo o zero');
    }

    if ($costoProdotto < 0) {
        throw new Exception('Il costo deve essere positivo o zero');
    }

    if ($prezzoAttuale > 999999.99) {
        throw new Exception('Prezzo troppo alto (max €999,999.99)');
    }

    if ($costoProdotto > 999999.99) {
        throw new Exception('Costo troppo alto (max €999,999.99)');
    }

    // Connessione database
    $pdo = getDbConnection();
    
    // Verifica esistenza prodotto e proprietà utente
    $checkStmt = $pdo->prepare("
        SELECT p.id, p.nome, p.prezzo_attuale, p.costo_prodotto, p.user_id
        FROM products p 
        WHERE p.id = ? AND p.user_id = ?
    ");
    $checkStmt->execute([$productId, $userId]);
$existingProduct = $checkStmt->fetch();

if (!$existingProduct) {
    throw new Exception("Prodotto non trovato o non autorizzato - product_id: {$productId}, user_id: {$userId}");
}

    // Log dei valori precedenti
    logPricingUpdate("Prodotto {$productId} ({$existingProduct['nome']}): " .
        "prezzo {$existingProduct['prezzo_attuale']} -> {$prezzoAttuale}, " .
        "costo {$existingProduct['costo_prodotto']} -> {$costoProdotto}");

    // Aggiornamento database in transazione
    $pdo->beginTransaction();

    try {
$updateStmt = $pdo->prepare("
    UPDATE products 
    SET 
        prezzo_attuale = ?,
        costo_prodotto = ?,
        aggiornato_il = NOW()
    WHERE id = ? AND user_id = ?
");

$updateResult = $updateStmt->execute([$prezzoAttuale, $costoProdotto, $productId, $userId]);
        
        if (!$updateResult) {
            throw new Exception('Errore durante aggiornamento database');
        }

        if ($updateStmt->rowCount() === 0) {
            throw new Exception('Nessuna riga aggiornata - prodotto non trovato');
        }

        // Commit transazione
        $pdo->commit();
        
        // === INVALIDA CACHE MOBILE (event-driven) ===
        // Quando l'utente modifica prezzi/costi, invalida cache margins + inventory
        require_once dirname(dirname(__DIR__)) . '/mobile/helpers/cache_events.php';
        invalidateCacheOnEvent($userId, 'price_updated');

        // Log rimosso - troppo verboso

        // Calcola margine stimato per response (semplificato)
        $margineStimato = 0;
        if ($prezzoAttuale > 0) {
            $margineStimato = (($prezzoAttuale - $costoProdotto) / $prezzoAttuale) * 100;
        }

        // Invia notifica email admin se prezzo è cambiato (senza output)
        if ($prezzoAttuale != $existingProduct['prezzo_attuale']) {
            // Log cambio prezzo per debug
            CentralLogger::log('margini', 'INFO', "Prezzo cambiato per prodotto {$productId} (user {$userId}) da {$existingProduct['prezzo_attuale']} a {$prezzoAttuale}");
            
            // Salva in amazon_price_updates_log per tracking modifiche
            try {
                $targetMargin = 0;
                if ($prezzoAttuale > 0) {
                    $targetMargin = (($prezzoAttuale - $costoProdotto) / $prezzoAttuale) * 100;
                }
                
                $logStmt = $pdo->prepare("
                    INSERT INTO amazon_price_updates_log 
                    (user_id, product_id, sku_amazon, old_price, new_price, target_margin, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
                ");
                $logStmt->execute([
                    $userId,
                    $productId,
                    $existingProduct['nome'],
                    $existingProduct['prezzo_attuale'],
                    $prezzoAttuale,
                    round($targetMargin, 2)
                ]);
                
                CentralLogger::log('margini', 'INFO', "Cambio prezzo salvato in log - Product {$productId}, User {$userId}");
                
            } catch (Exception $logError) {
                CentralLogger::log('margini', 'ERROR', "Errore salvataggio price log: " . $logError->getMessage());
            }
            
            // EMAIL RIMOSSA: inviata solo tramite cron giornaliero alle 15:00
        }

        // Response di successo
        echo json_encode([
            'success' => true,
            'message' => 'Prezzi aggiornati con successo',
            'data' => [
                'product_id' => $productId,
                'product_name' => $existingProduct['nome'],
                'prezzo_attuale' => $prezzoAttuale,
                'costo_prodotto' => $costoProdotto,
                'margine_stimato' => round($margineStimato, 2),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]);

    } catch (Exception $e) {
        // Rollback in caso di errore
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    // Log errore
    logPricingUpdate("ERRORE: " . $e->getMessage(), 'ERROR');
    
    // Response di errore
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?> 