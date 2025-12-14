<?php
/**
 * Bulk Auto-Mapping Inbound Shipments
 * Mappa automaticamente SKU inbound da tutte le tabelle (inventory, fbm, products, settlement)
 */

require_once __DIR__ . '/../margynomic/config/config.php';

$db = getDbConnection();

// Log inizio
$startTime = microtime(true);
$stats = [
    'total_users' => 0,
    'mapped_from_inventory' => 0,
    'mapped_from_fbm' => 0,
    'mapped_from_fnsku' => 0,
    'mapped_from_settlement' => 0,
    'errors' => []
];

try {
    $db->beginTransaction();
    
    // Ottieni tutti gli utenti attivi
    $stmt = $db->query("SELECT id FROM users WHERE is_active = 1");
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $stats['total_users'] = count($users);
    
    echo "🚀 Inizio auto-mapping per " . $stats['total_users'] . " utenti\n\n";
    
    // STEP 1: Mappa da inventory
    echo "📦 STEP 1: Mapping da inventory...\n";
    $stmt = $db->query("
        UPDATE inbound_shipment_items isi
        INNER JOIN inventory i ON i.sku = isi.seller_sku AND i.user_id = isi.user_id
        SET isi.product_id = i.product_id
        WHERE isi.product_id IS NULL 
          AND i.product_id IS NOT NULL
    ");
    $stats['mapped_from_inventory'] = $stmt->rowCount();
    echo "   ✅ Mappati {$stats['mapped_from_inventory']} record da inventory\n\n";
    
    // STEP 2: Mappa da inventory_fbm
    echo "🚚 STEP 2: Mapping da inventory_fbm...\n";
    $stmt = $db->query("
        UPDATE inbound_shipment_items isi
        INNER JOIN inventory_fbm ifbm ON ifbm.seller_sku = isi.seller_sku AND ifbm.user_id = isi.user_id
        SET isi.product_id = ifbm.product_id
        WHERE isi.product_id IS NULL 
          AND ifbm.product_id IS NOT NULL
    ");
    $stats['mapped_from_fbm'] = $stmt->rowCount();
    echo "   ✅ Mappati {$stats['mapped_from_fbm']} record da inventory_fbm\n\n";
    
    // STEP 3: Mappa da products via FNSKU
    echo "🏷️  STEP 3: Mapping da products (FNSKU)...\n";
    $stmt = $db->query("
        UPDATE inbound_shipment_items isi
        INNER JOIN products p ON p.fnsku = isi.fnsku AND p.user_id = isi.user_id
        SET isi.product_id = p.id
        WHERE isi.product_id IS NULL 
          AND isi.fnsku IS NOT NULL
    ");
    $stats['mapped_from_fnsku'] = $stmt->rowCount();
    echo "   ✅ Mappati {$stats['mapped_from_fnsku']} record da products (FNSKU)\n\n";
    
    // STEP 3B: Mappa removal_orders da inventory/products
    echo "📦 STEP 3B: Mapping removal_orders da inventory...\n";
    $stmt = $db->query("
        UPDATE removal_orders ro
        INNER JOIN inventory i ON i.sku = ro.sku AND i.user_id = ro.user_id
        SET ro.product_id = i.product_id
        WHERE ro.product_id IS NULL 
          AND i.product_id IS NOT NULL
    ");
    $stats['mapped_removal_from_inventory'] = $stmt->rowCount();
    echo "   ✅ Mappati {$stats['mapped_removal_from_inventory']} removal orders da inventory\n\n";
    
    // STEP 4: Mappa da settlement (per ogni utente)
    echo "💰 STEP 4: Mapping da settlement (tabelle dinamiche)...\n";
    foreach ($users as $userId) {
        $settlementTable = "report_settlement_{$userId}";
        
        // Verifica esistenza tabella
        $checkStmt = $db->query("SHOW TABLES LIKE '{$settlementTable}'");
        if ($checkStmt->rowCount() === 0) {
            continue;
        }
        
        try {
            $stmt = $db->prepare("
                UPDATE inbound_shipment_items isi
                INNER JOIN `{$settlementTable}` s ON s.sku = isi.seller_sku
                SET isi.product_id = s.product_id
                WHERE isi.user_id = ?
                  AND isi.product_id IS NULL 
                  AND s.product_id IS NOT NULL
            ");
            $stmt->execute([$userId]);
            $rowsMapped = $stmt->rowCount();
            
            if ($rowsMapped > 0) {
                $stats['mapped_from_settlement'] += $rowsMapped;
                echo "   ✅ User {$userId}: mappati {$rowsMapped} record da settlement\n";
            }
            
        } catch (PDOException $e) {
            $stats['errors'][] = "User {$userId} settlement error: " . $e->getMessage();
            echo "   ⚠️  User {$userId}: errore - " . $e->getMessage() . "\n";
        }
    }
    
    $db->commit();
    
    // STEP 5: Statistiche finali
    echo "\n📊 RISULTATO FINALE:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Utenti processati: {$stats['total_users']}\n";
    echo "Da inventory: {$stats['mapped_from_inventory']}\n";
    echo "Da inventory_fbm: {$stats['mapped_from_fbm']}\n";
    echo "Da products (FNSKU): {$stats['mapped_from_fnsku']}\n";
    echo "Da settlement: {$stats['mapped_from_settlement']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $totalMapped = $stats['mapped_from_inventory'] + $stats['mapped_from_fbm'] + 
                   $stats['mapped_from_fnsku'] + $stats['mapped_from_settlement'];
    echo "TOTALE MAPPATI: {$totalMapped}\n";
    
    $executionTime = round(microtime(true) - $startTime, 2);
    echo "Tempo esecuzione: {$executionTime}s\n";
    
    // Verifica SKU ancora non mappati
    $stmt = $db->query("
        SELECT 
            COUNT(*) as totale,
            COUNT(product_id) as mappati,
            COUNT(*) - COUNT(product_id) as non_mappati
        FROM inbound_shipment_items
    ");
    $finalStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\n📈 STATO INBOUND_SHIPMENT_ITEMS:\n";
    echo "Totale record: {$finalStats['totale']}\n";
    echo "Mappati: {$finalStats['mappati']} (" . round($finalStats['mappati']/$finalStats['totale']*100, 1) . "%)\n";
    echo "Non mappati: {$finalStats['non_mappati']} (" . round($finalStats['non_mappati']/$finalStats['totale']*100, 1) . "%)\n";
    
    if (!empty($stats['errors'])) {
        echo "\n⚠️  ERRORI:\n";
        foreach ($stats['errors'] as $error) {
            echo "  - {$error}\n";
        }
    }
    
    echo "\n✅ Processo completato!\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "\n❌ ERRORE CRITICO: " . $e->getMessage() . "\n";
    exit(1);
}
?>