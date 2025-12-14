<?php
/**
 * Cache Event-Driven Invalidation System
 * Sistema centralizzato di invalidamento cache mobile basato su eventi
 * 
 * Questo sistema invalida la cache SOLO quando i dati cambiano realmente:
 * - Sync Amazon (settlement, inventory, inbound, trid)
 * - Azioni utente (modifica prezzi/costi)
 * 
 * TTL cache: 48h come safety net
 * Invalidazione: event-driven per dati sempre freschi
 */

require_once __DIR__ . '/mobile_cache_helper.php';

/**
 * Invalida cache mobile basato su evento di sistema
 * 
 * @param int $userId ID utente
 * @param string $event Tipo evento (es: 'settlement_sync', 'inventory_sync', 'price_updated')
 * @return bool Successo operazione
 */
function invalidateCacheOnEvent($userId, $event) {
    if (!$userId || $userId <= 0) {
        error_log("ERROR invalidateCacheOnEvent: userId non valido ($userId)");
        return false;
    }
    
    try {
        switch ($event) {
            // === SETTLEMENT SYNC ===
            // Quando viene importato un nuovo settlement report da Amazon
            case 'settlement_sync':
                invalidateMobileCache($userId, 'orders_summary');
                invalidateMobileCache($userId, 'day_index');
                invalidateMobileCache($userId, 'margins');
                
                // Invalida cache Rendiconto (fatturato/erogato/unità vendute cambiano)
                // Non possiamo invalidare solo un anno specifico, quindi invalidiamo tutte le cache rendiconto
                try {
                    $pdo = getDbConnection();
                    // Get all years with rendiconto data for this user
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT anno 
                        FROM rendiconto_input_utente 
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$userId]);
                    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Invalida cache per ogni anno
                    foreach ($years as $year) {
                        invalidateMobileCache($userId, "rendiconto_settlement_{$year}");
                        invalidateMobileCache($userId, "rendiconto_transactions_{$year}");
                    }
                    
                    // Invalida anche lista anni (potrebbero esserci nuovi anni)
                    invalidateMobileCache($userId, "rendiconto_years");
                    
                } catch (Exception $e) {
                    error_log("ERROR invalidating rendiconto cache on settlement_sync: " . $e->getMessage());
                }
                break;
            
            // === INVENTORY SYNC ===
            // Quando vengono sincronizzate le giacenze da Amazon
            case 'inventory_sync':
                invalidateMobileCache($userId, 'inventory');
                break;
            
            // === INBOUND SHIPMENTS SYNC ===
            // Quando vengono sincronizzate le spedizioni FBA in entrata
            case 'inbound_sync':
                invalidateMobileCache($userId, 'trid_shipments');
                break;
            
            // === TRID EVENTS SYNC ===
            // Quando vengono sincronizzati gli eventi TRID (damaged, lost, etc)
            case 'trid_sync':
                invalidateMobileCache($userId, 'trid_refunds');
                break;
            
            // === REMOVAL ORDERS SYNC ===
            // Quando vengono sincronizzati gli ordini di removal/disposal
            case 'removal_sync':
                invalidateMobileCache($userId, 'trid_refunds');
                break;
            
            // === USER ACTION: PRICE/COST UPDATE ===
            // Quando l'utente modifica prezzi o costi prodotto
            case 'price_updated':
                invalidateMobileCache($userId, 'margins');
                invalidateMobileCache($userId, 'inventory'); // mostra costi
                break;
            
            // === USER ACTION: RENDICONTO UPDATE ===
            // Quando l'utente modifica dati rendiconto manualmente
            case 'rendiconto_updated':
                invalidateMobileCache($userId, 'rendiconto');
                break;
            
            // === USER ACTION: PROFILE UPDATE ===
            // Quando l'utente modifica dati profilo
            case 'profile_updated':
                invalidateMobileCache($userId, 'profile_stats');
                break;
            
            default:
                error_log("WARNING invalidateCacheOnEvent: evento sconosciuto '$event' per user $userId");
                return false;
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("ERROR invalidateCacheOnEvent ($event, user $userId): " . $e->getMessage());
        return false;
    }
}

/**
 * Invalida TUTTA la cache per un utente (uso admin/debug)
 * 
 * @param int $userId ID utente
 * @return bool Successo operazione
 */
function invalidateAllUserCache($userId) {
    if (!$userId || $userId <= 0) {
        error_log("ERROR invalidateAllUserCache: userId non valido ($userId)");
        return false;
    }
    
    try {
        // Invalida tutte le cache types
        invalidateMobileCache($userId); // senza secondo parametro = invalida tutto
        return true;
        
    } catch (Exception $e) {
        error_log("ERROR invalidateAllUserCache (user $userId): " . $e->getMessage());
        return false;
    }
}

