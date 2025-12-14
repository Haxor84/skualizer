<?php
/**
 * Margynomic - FBM Inventory Processor
 * File: modules/previsync/inventory_fbm.php
 * 
 * Processa file GET_MERCHANT_LISTINGS_ALL_DATA per inventario FBM
 * Integrato con sistema mapping esistente
 */

// Variabili disponibili dal chiamante:
// $userId - ID utente
// $db - Connessione database  
// $filePath - Path del file da processare

if (!isset($userId) || !isset($db) || !isset($filePath)) {
    throw new Exception("Variabili richieste non disponibili per inventory_fbm.php");
}

/**
 * Processa file FBM TSV
 */
function processFbmFile($filePath, $userId, $db) {
    $file = fopen($filePath, 'r');
    if (!$file) {
        throw new Exception("Impossibile aprire file FBM: $filePath");
    }
    
    // Verifica se il file è vuoto o ha solo header
    $fileSize = filesize($filePath);
    if ($fileSize < 100) { // File troppo piccolo per avere dati reali
        fclose($file);
        logSyncOperation($userId, 'inventory_fbm_empty', 'info', 
            'File FBM vuoto - utente probabilmente ha solo inventario FBA');
        
        return [
            'processed_rows' => 0,
            'error_rows' => 0,
            'message' => 'File FBM vuoto - nessun inventario FBM trovato'
        ];
    }
    
    // Cancella dati esistenti per questo utente
    $stmt = $db->prepare("DELETE FROM inventory_fbm WHERE user_id = ?");
    $stmt->execute([$userId]);
    
    // Prepara statement di inserimento
    $insertSql = "INSERT INTO inventory_fbm (
        user_id, item_name, item_description, listing_id, seller_sku, price, quantity,
        open_date, image_url, item_is_marketplace, product_id_type, zshop_shipping_fee,
        item_note, item_condition, zshop_category1, zshop_browse_path, zshop_storefront_feature,
        asin1, asin2, asin3, will_ship_internationally, expedited_shipping, zshop_boldface,
        product_id_value, bid_for_featured_placement, add_delete, pending_quantity,
        fulfillment_channel, optional_payment_type_exclusion, merchant_shipping_group,
        status, minimum_order_quantity, sell_remainder
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $db->prepare($insertSql);
    
    $processedRows = 0;
    $errorRows = 0;
    $isFirstRow = true;
    
    while (($row = fgetcsv($file, 0, "\t")) !== false) {
        // Salta header
        if ($isFirstRow) {
            $isFirstRow = false;
            continue;
        }
        
        // Verifica che abbiamo abbastanza colonne (minimo 31, supporta 31-32)
        if (count($row) < 31) {
            $errorRows++;
            
            // Log solo il primo errore strutturale
            if ($errorRows === 1) {
                logSyncOperation($userId, 'inventory_fbm_structure_error', 'warning', 
    "File FBM ha righe con " . count($row) . " colonne invece di 32+ richieste");
            }
            continue;
        }
        
        try {
            // Parsing data apertura
            $openDate = null;
            if (!empty($row[6])) {
                $openDate = date('Y-m-d H:i:s', strtotime($row[6]));
            }
            
            // Rilevamento formato automatico: 31 o 32 colonne
            $isFormat32 = (count($row) >= 32);
            
            // Mapping flessibile basato sul formato
            if ($isFormat32) {
                // Formato 32 colonne (utente 2)
                $optionalPayment = $row[27] ?? '';
                $merchantGroup = $row[28] ?? '';
                $status = $row[29] ?? '';
                $minOrderQty = intval($row[30] ?? 0);
                $sellRemainder = $row[31] ?? '';
            } else {
                // Formato 31 colonne (utente 7) - shift delle ultime colonne
                $optionalPayment = '';  // Colonna mancante
                $merchantGroup = $row[27] ?? '';  // Era posizione 28
                $status = $row[28] ?? '';         // Era posizione 29
                $minOrderQty = intval($row[29] ?? 0); // Era posizione 30
                $sellRemainder = $row[30] ?? '';      // Era posizione 31
            }
            
            $stmt->execute([
                $userId,                                    // user_id
                $row[0] ?? '',                             // item_name
                $row[1] ?? '',                             // item_description  
                $row[2] ?? '',                             // listing_id
                $row[3] ?? '',                             // seller_sku
                parseDecimal($row[4] ?? '0'),              // price
                intval($row[5] ?? 0),                      // quantity
                $openDate,                                 // open_date
                $row[7] ?? '',                             // image_url
                parseBoolean($row[8] ?? 'false'),          // item_is_marketplace
                $row[9] ?? '',                             // product_id_type
                parseDecimal($row[10] ?? '0'),             // zshop_shipping_fee
                $row[11] ?? '',                            // item_note
                $row[12] ?? '',                            // item_condition
                $row[13] ?? '',                            // zshop_category1
                $row[14] ?? '',                            // zshop_browse_path
                $row[15] ?? '',                            // zshop_storefront_feature
                $row[16] ?? '',                            // asin1
                $row[17] ?? '',                            // asin2
                $row[18] ?? '',                            // asin3
                parseBoolean($row[19] ?? 'false'),         // will_ship_internationally
                parseBoolean($row[20] ?? 'false'),         // expedited_shipping
                parseBoolean($row[21] ?? 'false'),         // zshop_boldface
                $row[22] ?? '',                            // product_id_value
                parseDecimal($row[23] ?? '0'),             // bid_for_featured_placement
                $row[24] ?? '',                            // add_delete
                intval($row[25] ?? 0),                     // pending_quantity
                $row[26] ?? '',                            // fulfillment_channel
                $optionalPayment,                          // optional_payment_type_exclusion
                $merchantGroup,                            // merchant_shipping_group
                $status,                                   // status
                $minOrderQty,                             // minimum_order_quantity
                $sellRemainder                            // sell_remainder
            ]);
            
            $processedRows++;
            
        } catch (Exception $e) {
            $errorRows++;
        }
    }
    
fclose($file);
    
    // Se nessuna riga processata, probabilmente file vuoto o solo header
    if ($processedRows === 0) {
        logSyncOperation($userId, 'inventory_fbm_no_data', 'info', 
            'Nessun dato FBM trovato - utente probabilmente ha solo inventario FBA');
        
        return [
            'processed_rows' => 0,
            'error_rows' => $errorRows,
            'message' => 'Nessun dato FBM trovato (normale per utenti solo FBA)'
        ];
    }
    
    // Auto-mapping con products esistenti usando SKU
    syncFbmProductMapping($userId, $db);
    
    // RIPRISTINA mapping da mapping_states per FBM
    restoreFbmMappingsFromStates($userId, $db);
    
    logSyncOperation($userId, 'inventory_fbm_file_processed', 'info', 
        "File FBM processato: $processedRows righe importate, $errorRows errori", [
            'processed_rows' => $processedRows,
            'error_rows' => $errorRows,
            'report_type' => 'FBM'
        ]);
    
    return [
        'processed_rows' => $processedRows,
        'error_rows' => $errorRows,
        'message' => "Importati $processedRows record FBM"
    ];
}

/**
 * Ripristina mapping FBM da mapping_states
 */
function restoreFbmMappingsFromStates($userId, $db) {
    try {
        $stmt = $db->prepare("
            UPDATE inventory_fbm f
            INNER JOIN mapping_states ms ON f.seller_sku = ms.sku AND f.user_id = ms.user_id
            SET f.product_id = ms.product_id
            WHERE ms.user_id = ? 
            AND ms.source_table = 'inventory_fbm'
            AND ms.product_id IS NOT NULL
        ");
        $stmt->execute([$userId]);
        $restoredCount = $stmt->rowCount();
        
    } catch (Exception $e) {
        logSyncOperation($userId, 'inventory_fbm_mapping_restore_error', 'error', 
            'Errore ripristino mapping FBM: ' . $e->getMessage());
    }
}

/**
 * Mapping FBM con tabella products esistente
 * PROTEZIONE: Non sovrascrive mapping manuali/locked dell'admin
 */
function syncFbmProductMapping($userId, $db) {
    // Mappa per SKU (seller_sku) - SOLO se non esiste mapping manuale protetto
    $sql = "UPDATE inventory_fbm i 
            JOIN products p ON i.seller_sku = p.sku AND p.user_id = i.user_id 
            LEFT JOIN mapping_states ms ON i.seller_sku = ms.sku AND i.user_id = ms.user_id AND ms.source_table = 'inventory_fbm'
            SET i.product_id = p.id 
            WHERE i.product_id IS NULL 
            AND i.user_id = ?
            AND (ms.is_locked IS NULL OR ms.is_locked = 0)
            AND (ms.mapping_type IS NULL OR ms.mapping_type != 'manual')";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $mappedBySku = $stmt->rowCount();
    
    // Mappa per ASIN1 se SKU non ha funzionato - SOLO se non esiste mapping manuale protetto
    $sql = "UPDATE inventory_fbm i 
            JOIN products p ON i.asin1 = p.asin AND p.user_id = i.user_id 
            LEFT JOIN mapping_states ms ON i.seller_sku = ms.sku AND i.user_id = ms.user_id AND ms.source_table = 'inventory_fbm'
            SET i.product_id = p.id 
            WHERE i.product_id IS NULL 
            AND i.asin1 IS NOT NULL 
            AND i.user_id = ?
            AND (ms.is_locked IS NULL OR ms.is_locked = 0)
            AND (ms.mapping_type IS NULL OR ms.mapping_type != 'manual')";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $mappedByAsin = $stmt->rowCount();
    
    if ($mappedBySku > 0 || $mappedByAsin > 0) {
        logSyncOperation($userId, 'inventory_fbm_mapping_completed', 'info', 
            "Auto-mapping FBM protetto: SKU=$mappedBySku, ASIN=$mappedByAsin (mapping manuali preservati)");
    }
}
/**
 * Helper per parsing decimali
 */
function parseDecimal($value) {
    $cleaned = preg_replace('/[^\d.-]/', '', $value);
    return $cleaned === '' ? 0.00 : floatval($cleaned);
}

/**
 * Helper per parsing boolean
 */
function parseBoolean($value) {
    return in_array(strtolower($value), ['true', '1', 'yes', 'y']) ? 1 : 0;
}

// === ESECUZIONE ===
try {
    $result = processFbmFile($filePath, $userId, $db);
    
    // Processing completato - log già tracciato da inventory_fbm_file_processed
    
} catch (Exception $e) {
    logSyncOperation($userId, 'inventory_fbm_processing_error', 'error', 
        'Errore processing FBM: ' . $e->getMessage());
    
    throw $e;
}
?>