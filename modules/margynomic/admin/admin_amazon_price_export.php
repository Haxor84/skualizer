<?php
/**
 * Admin Export Prezzi Amazon - Export modifiche prezzi in formato Amazon flat file
 * File: modules/margynomic/admin/admin_amazon_price_export.php
 * 
 * Genera file TSV compatibile con Amazon Seller Central per upload prezzi
 * Supporta tutti gli utenti del sistema (nessun hardcoding user_id)
 */

// Avvia output buffering per prevenire problemi con headers
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/admin_helpers.php';
requireAdmin();

$pdo = getDbConnection();

// Tab attiva
$activeTab = $_GET['tab'] ?? 'export';

// Default: ultimi 30 giorni, solo pending
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$userIdFilter = $_GET['user_id'] ?? null;
$statusFilter = $_GET['status'] ?? 'pending';

// === GENERAZIONE FILE TSV (POST action=generate) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    // Pulisci il buffer di output per evitare interferenze
    ob_end_clean();
    
    $selectedIds = $_POST['product_ids'] ?? [];
    
    if (empty($selectedIds)) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Nessun prodotto selezionato']));
    }
    
    try {
        // Recupera prodotti selezionati con SKU e prezzo
        // IMPORTANTE: raggruppa per SKU per evitare duplicati (se utente ha modificato prezzo più volte)
        // Prende solo l'ultimo prezzo modificato per ogni SKU
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT 
                p.sku, 
                p.prezzo_attuale,
                MAX(p.id) as latest_id
            FROM products p
            WHERE p.id IN ($placeholders)
              AND p.sku IS NOT NULL 
              AND p.sku != ''
              AND p.prezzo_attuale > 0
              AND p.prezzo_attuale < 1000000
            GROUP BY p.sku
            ORDER BY p.sku
        ");
        $stmt->execute($selectedIds);
        $exportProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($exportProducts)) {
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Nessun prodotto valido per export']));
        }
        
        // Header Amazon Flat File formato Price and Quantity
        $output = "sku\tprice\tminimum-seller-allowed-price\tmaximum-seller-allowed-price\tquantity\tfulfillment-channel\thandling-time\tminimum_order_quantity_minimum\n";
        
        // Righe prodotti
        foreach ($exportProducts as $p) {
            // Usa virgola come separatore decimale (standard italiano/europeo per Amazon.it)
            $price = number_format($p['prezzo_attuale'], 2, ',', '');
            $minPrice = number_format($p['prezzo_attuale'] * 0.95, 2, ',', '');
            $maxPrice = number_format($p['prezzo_attuale'] * 1.10, 2, ',', '');
            
            // Formato: TAB separated
            $output .= "{$p['sku']}\t{$price}\t{$minPrice}\t{$maxPrice}\t\tamazon\t\t\n";
        }
        
        // Nome file con timestamp
        $filename = "Flat.File.PriceInventory.it_" . date('Ymd_His') . ".txt";
        
        // Log export PRIMA di inviare headers
        CentralLogger::log('admin', 'INFO', "Export prezzi Amazon generato", [
            'file' => $filename,
            'products_count' => count($exportProducts),
            'user_filter' => $userIdFilter,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
        
        // Headers download
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($output));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Flush e output del file
        flush();
        echo $output;
        exit;
        
    } catch (Exception $e) {
        CentralLogger::log('admin', 'ERROR', "Errore generazione export prezzi", [
            'error' => $e->getMessage()
        ]);
        
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Errore generazione file: ' . $e->getMessage()]));
    }
}

// === INVIA DISCREPANZE COME PENDING (POST action=send_to_pending) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_pending') {
    header('Content-Type: application/json');
    
    $userId = (int)($_POST['user_id'] ?? 0);
    $selectedIds = $_POST['product_ids'] ?? [];
    
    if (!$userId) {
        die(json_encode(['error' => 'User ID non valido']));
    }
    
    if (empty($selectedIds)) {
        die(json_encode(['error' => 'Nessun prodotto selezionato']));
    }
    
    try {
        $pdo->beginTransaction();
        
        $insertedCount = 0;
        $skippedCount = 0;
        
        foreach ($selectedIds as $productId) {
            $productId = (int)$productId;
            
            // Recupera dati prodotto con prezzo Amazon
            $stmt = $pdo->prepare("
                SELECT 
                    p.id,
                    p.sku,
                    p.asin,
                    p.prezzo_attuale,
                    i.your_price as amazon_price
                FROM products p
                LEFT JOIN inventory i ON i.product_id = p.id AND i.user_id = p.user_id
                WHERE p.id = :product_id 
                  AND p.user_id = :user_id
                  AND i.your_price IS NOT NULL
                  AND ABS(i.your_price - p.prezzo_attuale) > 0.01
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':user_id' => $userId
            ]);
            
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                $skippedCount++;
                continue;
            }
            
            // Controlla se esiste già un pending per questo prodotto
            $stmt = $pdo->prepare("
                SELECT id FROM amazon_price_updates_log 
                WHERE product_id = :product_id 
                  AND user_id = :user_id
                  AND status = 'pending'
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':user_id' => $userId
            ]);
            
            $existingPending = $stmt->fetch();
            
            if ($existingPending) {
                // Aggiorna il pending esistente
                $stmt = $pdo->prepare("
                    UPDATE amazon_price_updates_log 
                    SET old_price = :old_price,
                        new_price = :new_price,
                        target_margin = 0,
                        created_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':old_price' => $product['amazon_price'],
                    ':new_price' => $product['prezzo_attuale'],
                    ':id' => $existingPending['id']
                ]);
            } else {
                // Inserisci nuovo pending
                $stmt = $pdo->prepare("
                    INSERT INTO amazon_price_updates_log 
                    (user_id, product_id, sku_amazon, asin, old_price, new_price, target_margin, status, created_at, updated_at)
                    VALUES
                    (:user_id, :product_id, :sku, :asin, :old_price, :new_price, 0, 'pending', NOW(), NOW())
                ");
                $stmt->execute([
                    ':user_id' => $userId,
                    ':product_id' => $productId,
                    ':sku' => $product['sku'],
                    ':asin' => $product['asin'],
                    ':old_price' => $product['amazon_price'],
                    ':new_price' => $product['prezzo_attuale']
                ]);
            }
            
            $insertedCount++;
        }
        
        $pdo->commit();
        
        CentralLogger::log('admin', 'INFO', "Discrepanze inviate a pending per export Amazon", [
            'user_id' => $userId,
            'inserted' => $insertedCount,
            'skipped' => $skippedCount
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => "{$insertedCount} prodotti inviati alla coda Export Amazon",
            'inserted' => $insertedCount,
            'skipped' => $skippedCount
        ]);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        
        CentralLogger::log('admin', 'ERROR', "Errore invio discrepanze a pending", [
            'error' => $e->getMessage(),
            'user_id' => $userId
        ]);
        
        die(json_encode(['error' => 'Errore invio: ' . $e->getMessage()]));
    }
}

// === SALVA MODIFICHE PREZZI (POST action=save_prices) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prices') {
    header('Content-Type: application/json');
    
    $productId = (int)($_POST['product_id'] ?? 0);
    $newPrezzoAttuale = $_POST['prezzo_attuale'] ?? null;
    $newCostoProdotto = $_POST['costo_prodotto'] ?? null;
    
    if (!$productId) {
        die(json_encode(['error' => 'ID prodotto non valido']));
    }
    
    try {
        $updates = [];
        $params = ['id' => $productId];
        
        if ($newPrezzoAttuale !== null && $newPrezzoAttuale !== '') {
            $updates[] = "prezzo_attuale = :prezzo_attuale";
            $params['prezzo_attuale'] = (float)str_replace(',', '.', $newPrezzoAttuale);
        }
        
        if ($newCostoProdotto !== null && $newCostoProdotto !== '') {
            $updates[] = "costo_prodotto = :costo_prodotto";
            $params['costo_prodotto'] = (float)str_replace(',', '.', $newCostoProdotto);
        }
        
        if (empty($updates)) {
            die(json_encode(['error' => 'Nessuna modifica da salvare']));
        }
        
        $sql = "UPDATE products SET " . implode(', ', $updates) . ", aggiornato_il = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        CentralLogger::log('admin', 'INFO', "Prezzi aggiornati da Verifica Prezzi", [
            'product_id' => $productId,
            'updates' => $params
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Prezzi aggiornati con successo'
        ]);
        exit;
        
    } catch (Exception $e) {
        CentralLogger::log('admin', 'ERROR', "Errore salvataggio prezzi", [
            'error' => $e->getMessage(),
            'product_id' => $productId
        ]);
        
        die(json_encode(['error' => 'Errore salvataggio: ' . $e->getMessage()]));
    }
}

// === MARCA COME COMPLETATI (POST action=mark_completed) ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_completed') {
    $selectedIds = $_POST['product_ids'] ?? [];
    
    if (empty($selectedIds)) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Nessun prodotto selezionato']));
    }
    
    try {
        $pdo->beginTransaction();
        
        $placeholders = str_repeat('?,', count($selectedIds) - 1) . '?';
        
        // Recupera i dati dei prodotti da aggiornare (con nuovo prezzo)
        $stmt = $pdo->prepare("
            SELECT 
                apu.product_id,
                apu.user_id,
                apu.new_price,
                p.sku
            FROM amazon_price_updates_log apu
            INNER JOIN products p ON p.id = apu.product_id
            WHERE apu.product_id IN ($placeholders)
              AND apu.status = 'pending'
        ");
        $stmt->execute($selectedIds);
        $productsToUpdate = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($productsToUpdate)) {
            $pdo->rollBack();
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Nessun prodotto pending trovato']));
        }
        
        $inventoryUpdated = 0;
        $logUpdated = 0;
        
        // Per ogni prodotto, aggiorna inventory.your_price con il nuovo prezzo
        foreach ($productsToUpdate as $product) {
            // Aggiorna inventory.your_price con il nuovo prezzo da Margynomic
            $stmt = $pdo->prepare("
                UPDATE inventory 
                SET your_price = :new_price,
                    last_updated = NOW()
                WHERE product_id = :product_id 
                  AND user_id = :user_id
            ");
            $stmt->execute([
                ':new_price' => $product['new_price'],
                ':product_id' => $product['product_id'],
                ':user_id' => $product['user_id']
            ]);
            
            if ($stmt->rowCount() > 0) {
                $inventoryUpdated++;
            }
        }
        
        // Marca come completati nel log
        $stmt = $pdo->prepare("
            UPDATE amazon_price_updates_log 
            SET status = 'success', 
                completed_at = NOW(),
                amazon_response = CONCAT('Completato manualmente da admin - Inventory aggiornato a €', CAST(new_price AS CHAR))
            WHERE product_id IN ($placeholders)
              AND status = 'pending'
        ");
        $stmt->execute($selectedIds);
        
        $logUpdated = $stmt->rowCount();
        
        $pdo->commit();
        
        CentralLogger::log('admin', 'INFO', "Prezzi marcati come completati e inventory aggiornato", [
            'count_log' => $logUpdated,
            'count_inventory' => $inventoryUpdated,
            'product_ids' => $selectedIds
        ]);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => "{$logUpdated} modifiche marcate come completate\n{$inventoryUpdated} prezzi inventory aggiornati",
            'count' => $logUpdated,
            'inventory_updated' => $inventoryUpdated
        ]);
        exit;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        CentralLogger::log('admin', 'ERROR', "Errore marca come completati", [
            'error' => $e->getMessage()
        ]);
        
        header('Content-Type: application/json');
        die(json_encode(['error' => $e->getMessage()]));
    }
}

// === CARICAMENTO DATI PER PREVIEW ===
$products = [];
$statsData = [
    'total' => 0,
    'pending' => 0,
    'completed' => 0,
    'error' => 0
];

try {
    // Query principale con JOIN alla tabella products
    // Prima prende tutti i record nel periodo, poi filtra in PHP per deduplicate SKU
    $sql = "
        SELECT 
            p.id,
            p.sku,
            p.fnsku,
            p.nome,
            p.prezzo_attuale as new_price,
            apu.old_price,
            apu.created_at as modified_at,
            apu.status,
            apu.user_id,
            apu.id as log_id,
            ROUND((p.prezzo_attuale - apu.old_price) / apu.old_price * 100, 1) as change_percent
        FROM amazon_price_updates_log apu
        INNER JOIN products p ON p.id = apu.product_id
        WHERE apu.created_at BETWEEN :date_from AND :date_to
    ";
    
    $params = [
        ':date_from' => $dateFrom . ' 00:00:00',
        ':date_to' => $dateTo . ' 23:59:59'
    ];
    
    // Filtro opzionale per user_id
    if ($userIdFilter) {
        $sql .= " AND apu.user_id = :user_id";
        $params[':user_id'] = (int)$userIdFilter;
    }
    
    if ($statusFilter) {
        $sql .= " AND apu.status = :status";
        $params[':status'] = $statusFilter;
    }
    
    $sql .= "
          AND p.sku IS NOT NULL
          AND p.sku != ''
          AND p.prezzo_attuale > 0
        ORDER BY apu.created_at DESC
        LIMIT 1000
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Deduplica: mantieni solo l'ultima modifica per ogni SKU
    $products = [];
    $seenSkus = [];
    foreach ($allProducts as $product) {
        $sku = $product['sku'];
        if (!isset($seenSkus[$sku])) {
            $products[] = $product;
            $seenSkus[$sku] = true;
        }
    }
    unset($allProducts); // Libera memoria
    
    // Statistiche status
    foreach ($products as $p) {
        $statsData['total']++;
        $status = $p['status'] ?? 'pending';
        if (isset($statsData[$status])) {
            $statsData[$status]++;
        }
    }
    
} catch (Exception $e) {
    CentralLogger::log('admin', 'ERROR', "Errore caricamento dati export prezzi", [
        'error' => $e->getMessage()
    ]);
    $products = [];
}

// Lista utenti per filtro (solo se admin)
$usersList = [];
try {
    $stmt = $pdo->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY nome");
    $usersList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usersList = [];
}

// === CARICAMENTO DATI TAB "VERIFICA PREZZI" ===
$priceCheckProducts = [];
$priceCheckStats = [
    'total' => 0,
    'discrepancies' => 0,
    'missing_cost' => 0,
    'both_issues' => 0
];

if ($activeTab === 'verify' && $userIdFilter) {
    try {
        // Query per trovare discrepanze e costi mancanti
        // NOTA: Esclude prodotti con prezzo Amazon NULL quando non hanno costo mancante
        // GROUP BY p.id per evitare duplicati se ci sono record multipli in inventory
        $sql = "
            SELECT 
                p.id,
                p.sku,
                p.fnsku,
                p.nome,
                p.prezzo_attuale as margynomic_price,
                p.costo_prodotto,
                MAX(i.your_price) as amazon_price,
                COALESCE(MAX(i.your_price), 0) - p.prezzo_attuale as diff_price,
                ABS(COALESCE(MAX(i.your_price), 0) - p.prezzo_attuale) as abs_diff_price,
                (p.costo_prodotto IS NULL OR p.costo_prodotto <= 0) as has_missing_cost,
                (ABS(COALESCE(MAX(i.your_price), 0) - p.prezzo_attuale) > 0.01 AND MAX(i.your_price) IS NOT NULL) as has_price_discrepancy
            FROM products p
            LEFT JOIN inventory i ON i.product_id = p.id AND i.user_id = p.user_id
            WHERE p.user_id = :user_id
              AND p.sku IS NOT NULL
              AND p.sku != ''
            GROUP BY p.id, p.sku, p.fnsku, p.nome, p.prezzo_attuale, p.costo_prodotto
            HAVING (
                (ABS(COALESCE(MAX(i.your_price), 0) - p.prezzo_attuale) > 0.01 AND MAX(i.your_price) IS NOT NULL)
                OR (p.costo_prodotto IS NULL OR p.costo_prodotto <= 0)
            )
            ORDER BY 
                CASE WHEN MAX(i.your_price) IS NULL THEN 1 ELSE 0 END,
                ABS(COALESCE(MAX(i.your_price), 0) - p.prezzo_attuale) DESC
            LIMIT 1000
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':user_id' => (int)$userIdFilter]);
        $priceCheckProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcola statistiche
        foreach ($priceCheckProducts as $p) {
            $priceCheckStats['total']++;
            
            $hasMissingCost = ($p['costo_prodotto'] === null || $p['costo_prodotto'] <= 0);
            $hasPriceDiscrepancy = ($p['abs_diff_price'] > 0.01);
            
            if ($hasMissingCost && $hasPriceDiscrepancy) {
                $priceCheckStats['both_issues']++;
            } elseif ($hasMissingCost) {
                $priceCheckStats['missing_cost']++;
            } elseif ($hasPriceDiscrepancy) {
                $priceCheckStats['discrepancies']++;
            }
        }
        
    } catch (Exception $e) {
        CentralLogger::log('admin', 'ERROR', "Errore caricamento dati verifica prezzi", [
            'error' => $e->getMessage(),
            'user_id' => $userIdFilter
        ]);
        $priceCheckProducts = [];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Prezzi Amazon - Margynomic Admin</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8f9fa;
            color: #333;
        }
        
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 24px 30px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 24px;
            font-weight: 700;
            margin: 0 0 6px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-subtitle {
            font-size: 14px;
            opacity: 0.9;
            margin: 0;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: #f8f9fa;
            padding: 16px 20px;
            border-bottom: 1px solid #e9ecef;
            font-weight: 600;
            color: #495057;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            background: linear-gradient(135deg, #f8f9ff 0%, #f1f3ff 100%);
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #667eea;
            text-align: center;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #666;
            font-weight: 500;
        }
        
        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-size: 13px;
            font-weight: 600;
            color: #495057;
            margin-bottom: 6px;
        }
        
        .form-control {
            padding: 10px 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .btn-outline-secondary {
            background: white;
            color: #6c757d;
            border: 1px solid #6c757d;
        }
        
        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .table-responsive {
            overflow-x: auto;
            overflow-y: auto;
            max-height: 500px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            position: relative;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .table thead {
            position: sticky;
            top: 0;
            background: white;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .actions-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            margin-top: 20px;
        }
        
        .selection-info {
            font-size: 14px;
            color: #495057;
        }
        
        .selection-count {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .no-data i {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 16px;
        }
        
        /* Tabs */
        .tabs-container {
            background: white;
            border-radius: 12px 12px 0 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 0;
            overflow: hidden;
        }
        
        .tabs-nav {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .tab-button {
            flex: 1;
            padding: 16px 24px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 600;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 3px solid transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .tab-button:hover {
            background: #e9ecef;
            color: #495057;
        }
        
        .tab-button.active {
            background: white;
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Price edit inline */
        .editable-price {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .price-input {
            width: 90px;
            padding: 6px 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 13px;
            text-align: right;
            font-weight: 600;
        }
        
        .price-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.1);
        }
        
        .price-input.border-danger {
            border-color: #dc3545 !important;
        }
        
        .price-actions {
            display: none;
            gap: 4px;
        }
        
        .editable-price:hover .price-actions,
        .editable-price:focus-within .price-actions {
            display: flex;
        }
        
        .btn-icon {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }
        
        .btn-icon.btn-save {
            background: #28a745;
            color: white;
        }
        
        .btn-icon.btn-save:hover {
            background: #218838;
        }
        
        .btn-icon.btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-icon.btn-cancel:hover {
            background: #5a6268;
        }
        
        .issue-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .issue-price-diff {
            background: #fff3cd;
            color: #856404;
        }
        
        .issue-missing-cost {
            background: #f8d7da;
            color: #721c24;
        }
        
        .issue-both {
            background: #f8d7da;
            color: #721c24;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            
            .stats-row {
                grid-template-columns: 1fr;
            }
            
            .filters-form {
                grid-template-columns: 1fr;
            }
            
            .actions-footer {
                flex-direction: column;
                gap: 16px;
                align-items: stretch;
            }
            
            .table-responsive {
                max-height: 400px;
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th,
            .table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<?php echo getAdminNavigation('amazon_price_export'); ?>

<div class="main-container">
    <!-- Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-file-export"></i>
            Gestione Prezzi Amazon
        </h1>
        <p class="page-subtitle">
            Export prezzi per Seller Central e verifica discrepanze tra Margynomic e Amazon
        </p>
    </div>
    
    <!-- Tabs Navigation -->
    <div class="tabs-container">
        <div class="tabs-nav">
            <a href="?tab=export<?php echo $userIdFilter ? '&user_id='.$userIdFilter : ''; ?>" 
               class="tab-button <?php echo ($activeTab === 'export') ? 'active' : ''; ?>">
                <i class="fas fa-file-export"></i>
                Export Prezzi Amazon
            </a>
            <a href="?tab=verify<?php echo $userIdFilter ? '&user_id='.$userIdFilter : ''; ?>" 
               class="tab-button <?php echo ($activeTab === 'verify') ? 'active' : ''; ?>">
                <i class="fas fa-search-dollar"></i>
                Verifica Prezzi
            </a>
        </div>
    </div>

    <!-- TAB 1: Export Prezzi Amazon -->
    <div class="tab-content <?php echo ($activeTab === 'export') ? 'active' : ''; ?>">
    
    <!-- Statistiche -->
    <?php if (!empty($products)): ?>
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-number"><?php echo $statsData['total']; ?></div>
            <div class="stat-label">Modifiche Totali</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $statsData['pending']; ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $statsData['completed']; ?></div>
            <div class="stat-label">Completate</div>
        </div>
        <div class="stat-box">
            <div class="stat-number"><?php echo $statsData['error']; ?></div>
            <div class="stat-label">Errori</div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Card principale -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-filter"></i> Filtri di Ricerca
        </div>
        <div class="card-body">
            <!-- Filtri -->
            <form method="GET" class="filters-form">
                <div class="form-group">
                    <label for="date_from">
                        <i class="fas fa-calendar"></i> Data inizio
                    </label>
                    <input type="date" 
                           id="date_from"
                           name="date_from" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                
                <div class="form-group">
                    <label for="date_to">
                        <i class="fas fa-calendar"></i> Data fine
                    </label>
                    <input type="date" 
                           id="date_to"
                           name="date_to" 
                           class="form-control" 
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                
                <?php if (!empty($usersList)): ?>
                <div class="form-group">
                    <label for="user_id">
                        <i class="fas fa-user"></i> Utente (opzionale)
                    </label>
                    <select id="user_id" name="user_id" class="form-control">
                        <option value="">Tutti gli utenti</option>
                        <?php foreach ($usersList as $user): ?>
                            <option value="<?php echo $user['id']; ?>" 
                                    <?php echo ($userIdFilter == $user['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['nome']); ?> (<?php echo htmlspecialchars($user['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="status">
                        <i class="fas fa-check-circle"></i> Status
                    </label>
                    <select id="status" name="status" class="form-control">
                        <option value="">Tutti</option>
                        <option value="pending" <?php echo ($statusFilter === 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="success" <?php echo ($statusFilter === 'success') ? 'selected' : ''; ?>>Completati</option>
                        <option value="failed" <?php echo ($statusFilter === 'failed') ? 'selected' : ''; ?>>Errori</option>
                    </select>
                </div>
                
                <div class="form-group" style="justify-content: flex-end;">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Cerca
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Risultati -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-table"></i> Modifiche Prezzi (Ultimi 1000 record)
        </div>
        <div class="card-body">
            <?php if (empty($products)): ?>
                <div class="no-data">
                    <i class="fas fa-inbox"></i>
                    <p style="margin: 0 0 8px 0; font-weight: 600; font-size: 18px;">
                        Nessuna modifica prezzo trovata
                    </p>
                    <p style="margin: 0; color: #6c757d;">
                        Prova a cambiare i filtri di ricerca o seleziona un periodo diverso.
                    </p>
                </div>
            <?php else: ?>
                <!-- Tabella prodotti -->
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" 
                                           id="selectAll" 
                                           onclick="toggleSelectAll(this)"
                                           checked>
                                </th>
                                <th>SKU</th>
                                <th>Prodotto</th>
                                <th style="text-align: right;">Vecchio</th>
                                <th style="text-align: right;">Nuovo</th>
                                <th style="text-align: center;">Variazione</th>
                                <th>Data Modifica</th>
                                <th>Utente</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $p): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" 
                                           class="product-check" 
                                           value="<?php echo $p['id']; ?>" 
                                           checked>
                                </td>
                                <td>
                                    <code style="font-size: 11px; background: #f8f9fa; padding: 2px 6px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($p['sku']); ?>
                                    </code>
                                </td>
                                <td>
                                    <div style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                         title="<?php echo htmlspecialchars($p['nome']); ?>">
                                        <?php echo htmlspecialchars($p['nome']); ?>
                                    </div>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    €<?php echo number_format($p['old_price'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: right; font-weight: 600; color: #28a745;">
                                    €<?php echo number_format($p['new_price'], 2, ',', '.'); ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php
                                    $changePercent = $p['change_percent'];
                                    $badgeClass = $changePercent > 0 ? 'badge-success' : 'badge-danger';
                                    $icon = $changePercent > 0 ? 'fa-arrow-up' : 'fa-arrow-down';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <i class="fas <?php echo $icon; ?>"></i>
                                        <?php echo $changePercent > 0 ? '+' : ''; ?><?php echo $changePercent; ?>%
                                    </span>
                                </td>
                                <td>
                                    <small style="color: #6c757d;">
                                        <?php echo date('d/m/Y H:i', strtotime($p['modified_at'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <small style="color: #6c757d;">
                                        ID: <?php echo $p['user_id']; ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Footer azioni -->
                <div class="actions-footer">
                    <div class="selection-info">
                        <span class="selection-count" id="selectedCount"><?php echo count($products); ?></span>
                        prodotti selezionati
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button type="button" 
                                class="btn btn-outline-secondary btn-sm" 
                                onclick="toggleSelectAll(document.getElementById('selectAll'))">
                            <i class="fas fa-times"></i> Deseleziona tutti
                        </button>
                        <button type="button" 
                                class="btn btn-success" 
                                onclick="generateExport()">
                            <i class="fas fa-download"></i> Genera File Amazon
                        </button>
                        <button type="button" 
                                class="btn btn-primary" 
                                onclick="markAsCompleted()"
                                style="background: #17a2b8;">
                            <i class="fas fa-check-double"></i> Marca come Completati
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info formato e funzionalità -->
    <?php if (!empty($products)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Formato file:</strong> Il file generato è compatibile con Amazon Seller Central 
            (formato Price and Quantity, separatori TAB, decimali con virgola, encoding UTF-8).
            <br>
            <strong>Come usarlo:</strong> Inventory → Upload & Manage Inventory → Upload File → Scegli "Price and Quantity File".
            <br><br>
            <strong>📌 Importante - Marca come Completati:</strong> Quando marchi i prodotti come completati, 
            il sistema aggiorna automaticamente anche la tabella <code>inventory</code> con i nuovi prezzi. 
            Questo risolve il problema del ritardo di aggiornamento dei report Amazon, allineando immediatamente 
            i prezzi locali con quelli realmente presenti su Amazon.
        </div>
    </div>
    <?php endif; ?>
    
    </div><!-- Fine TAB 1: Export Prezzi Amazon -->
    
    <!-- TAB 2: Verifica Prezzi -->
    <div class="tab-content <?php echo ($activeTab === 'verify') ? 'active' : ''; ?>">
        
        <!-- Selezione Utente -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-user"></i> Seleziona Utente
            </div>
            <div class="card-body">
                <?php if (empty($userIdFilter)): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Seleziona un utente per visualizzare le discrepanze di prezzo
                    </div>
                    
                    <form method="GET">
                        <input type="hidden" name="tab" value="verify">
                        <div class="form-group">
                            <label for="user_id_verify">
                                <i class="fas fa-user"></i> Seleziona Utente
                            </label>
                            <select id="user_id_verify" name="user_id" class="form-control" required>
                                <option value="">-- Seleziona utente --</option>
                                <?php foreach ($usersList as $user): ?>
                                    <option value="<?php echo $user['id']; ?>">
                                        <?php echo htmlspecialchars($user['nome']); ?> (<?php echo htmlspecialchars($user['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Carica Dati
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Statistiche Verifica Prezzi -->
                    <div class="stats-row">
                        <div class="stat-box">
                            <div class="stat-number"><?php echo $priceCheckStats['total']; ?></div>
                            <div class="stat-label">Prodotti da Verificare</div>
                        </div>
                        <div class="stat-box" style="border-left-color: #ffc107;">
                            <div class="stat-number" style="color: #ffc107;"><?php echo $priceCheckStats['discrepancies']; ?></div>
                            <div class="stat-label">Discrepanze Prezzo</div>
                        </div>
                        <div class="stat-box" style="border-left-color: #dc3545;">
                            <div class="stat-number" style="color: #dc3545;"><?php echo $priceCheckStats['missing_cost']; ?></div>
                            <div class="stat-label">Costi Mancanti</div>
                        </div>
                        <div class="stat-box" style="border-left-color: #dc3545;">
                            <div class="stat-number" style="color: #dc3545;"><?php echo $priceCheckStats['both_issues']; ?></div>
                            <div class="stat-label">Entrambi i Problemi</div>
                        </div>
                    </div>
                    
                    <div style="margin-top: 16px; text-align: center;">
                        <a href="?tab=verify" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-user"></i> Cambia Utente
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tabella Verifica Prezzi -->
        <?php if ($userIdFilter && !empty($priceCheckProducts)): ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-search-dollar"></i> Verifica e Correzione Prezzi
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 40px;">
                                    <input type="checkbox" 
                                           id="selectAllVerify" 
                                           onclick="toggleSelectAllVerify(this)"
                                           title="Seleziona tutti con discrepanza prezzo">
                                </th>
                                <th>SKU</th>
                                <th>Prodotto</th>
                                <th style="text-align: right;">Prezzo Amazon</th>
                                <th style="text-align: right;">Prezzo Margynomic</th>
                                <th style="text-align: center;">Differenza</th>
                                <th style="text-align: right;">Costo Materia Prima</th>
                                <th style="text-align: center;">Problemi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($priceCheckProducts as $p): ?>
                            <?php
                                $hasMissingCost = ($p['costo_prodotto'] === null || $p['costo_prodotto'] <= 0);
                                $hasPriceDiscrepancy = ($p['abs_diff_price'] > 0.01 && $p['amazon_price'] !== null);
                            ?>
                            <tr data-product-id="<?php echo $p['id']; ?>">
                                <td>
                                    <?php if ($hasPriceDiscrepancy): ?>
                                        <input type="checkbox" 
                                               class="verify-check" 
                                               value="<?php echo $p['id']; ?>"
                                               data-has-discrepancy="1">
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="font-size: 11px; background: #f8f9fa; padding: 2px 6px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($p['sku']); ?>
                                    </code>
                                </td>
                                <td>
                                    <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                         title="<?php echo htmlspecialchars($p['nome']); ?>">
                                        <?php echo htmlspecialchars($p['nome']); ?>
                                    </div>
                                </td>
                                <td style="text-align: right; font-weight: 600;">
                                    <?php if ($p['amazon_price']): ?>
                                        €<?php echo number_format($p['amazon_price'], 2, ',', '.'); ?>
                                    <?php else: ?>
                                        <span style="color: #6c757d;">N/D</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div class="editable-price">
                                        <input type="number" 
                                               class="price-input price-margynomic" 
                                               step="0.01"
                                               data-field="prezzo_attuale"
                                               data-original="<?php echo $p['margynomic_price']; ?>"
                                               value="<?php echo number_format($p['margynomic_price'], 2, '.', ''); ?>">
                                        <div class="price-actions">
                                            <button class="btn-icon btn-save" onclick="savePrice(<?php echo $p['id']; ?>, 'prezzo_attuale', this)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn-icon btn-cancel" onclick="resetPrice(this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($hasPriceDiscrepancy && $p['amazon_price']): ?>
                                        <?php
                                        $diffPercent = ($p['amazon_price'] > 0) ? (($p['diff_price'] / $p['amazon_price']) * 100) : 0;
                                        $badgeClass = ($p['diff_price'] > 0) ? 'badge-success' : 'badge-danger';
                                        $icon = ($p['diff_price'] > 0) ? 'fa-arrow-up' : 'fa-arrow-down';
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <i class="fas <?php echo $icon; ?>"></i>
                                            €<?php echo number_format(abs($p['diff_price']), 2, ',', '.'); ?>
                                            (<?php echo $p['diff_price'] > 0 ? '+' : ''; ?><?php echo number_format($diffPercent, 1, ',', '.'); ?>%)
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #28a745; font-weight: 600;">✓ OK</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div class="editable-price">
                                        <input type="number" 
                                               class="price-input price-cost <?php echo $hasMissingCost ? 'border-danger' : ''; ?>" 
                                               step="0.01"
                                               data-field="costo_prodotto"
                                               data-original="<?php echo $p['costo_prodotto'] ?? '0'; ?>"
                                               value="<?php echo $p['costo_prodotto'] ? number_format($p['costo_prodotto'], 2, '.', '') : ''; ?>"
                                               placeholder="0.00">
                                        <div class="price-actions">
                                            <button class="btn-icon btn-save" onclick="savePrice(<?php echo $p['id']; ?>, 'costo_prodotto', this)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button class="btn-icon btn-cancel" onclick="resetPrice(this)">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($hasMissingCost && $hasPriceDiscrepancy): ?>
                                        <span class="issue-indicator issue-both">
                                            <i class="fas fa-exclamation-triangle"></i>
                                            Entrambi
                                        </span>
                                    <?php elseif ($hasMissingCost): ?>
                                        <span class="issue-indicator issue-missing-cost">
                                            <i class="fas fa-exclamation-circle"></i>
                                            Costo Mancante
                                        </span>
                                    <?php elseif ($hasPriceDiscrepancy): ?>
                                        <span class="issue-indicator issue-price-diff">
                                            <i class="fas fa-info-circle"></i>
                                            Prezzo Diverso
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Footer azioni verifica prezzi -->
                <?php 
                $discrepancyCount = count(array_filter($priceCheckProducts, fn($p) => 
                    ($p['abs_diff_price'] > 0.01 && $p['amazon_price'] !== null)
                ));
                ?>
                <?php if ($discrepancyCount > 0): ?>
                <div class="actions-footer" style="margin-top: 20px;">
                    <div class="selection-info">
                        <span class="selection-count" id="selectedVerifyCount">0</span>
                        prodotti con discrepanza selezionati
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button type="button" 
                                class="btn btn-outline-secondary btn-sm" 
                                onclick="toggleSelectAllVerify(document.getElementById('selectAllVerify'))">
                            <i class="fas fa-times"></i> Deseleziona tutti
                        </button>
                        <button type="button" 
                                class="btn btn-primary" 
                                onclick="sendToPending()"
                                style="background: #17a2b8;">
                            <i class="fas fa-paper-plane"></i> Invia come Pending per Export
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="alert alert-info" style="margin-top: 20px;">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Modifica Prezzi:</strong> Modifica i prezzi direttamente nella tabella. 
                        I pulsanti di salvataggio appariranno al passaggio del mouse. 
                        Le modifiche vengono salvate immediatamente nel database.
                        <br>
                        <strong>Invia come Pending:</strong> Seleziona i prodotti con discrepanza prezzo e inviali alla tab "Export Prezzi Amazon" 
                        per generare il file di aggiornamento prezzi da caricare su Amazon Seller Central.
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($userIdFilter && empty($priceCheckProducts)): ?>
        <div class="card">
            <div class="card-body">
                <div class="no-data">
                    <i class="fas fa-check-circle"></i>
                    <p style="margin: 0 0 8px 0; font-weight: 600; font-size: 18px; color: #28a745;">
                        Tutti i prezzi sono corretti! ✓
                    </p>
                    <p style="margin: 0; color: #6c757d;">
                        Non sono state trovate discrepanze di prezzo né costi mancanti per questo utente.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
    </div><!-- Fine TAB 2: Verifica Prezzi -->
    
</div><!-- Fine main-container -->

<script>
// Toggle selezione singola checkbox
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.product-check');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateSelectedCount();
}

// Aggiorna contatore prodotti selezionati
function updateSelectedCount() {
    const count = document.querySelectorAll('.product-check:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

// Listener per singole checkbox
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.product-check').forEach(cb => {
        cb.addEventListener('change', function() {
            updateSelectedCount();
            
            // Aggiorna checkbox "Seleziona tutti"
            const total = document.querySelectorAll('.product-check').length;
            const checked = document.querySelectorAll('.product-check:checked').length;
            document.getElementById('selectAll').checked = (total === checked);
        });
    });
});

// Genera export TSV
function generateExport() {
    const selected = Array.from(document.querySelectorAll('.product-check:checked'))
                          .map(cb => cb.value);
    
    if (selected.length === 0) {
        alert('⚠️ Seleziona almeno un prodotto da esportare');
        return;
    }
    
    // Conferma
    if (!confirm(`🚀 Generare file Amazon con ${selected.length} prodotti?`)) {
        return;
    }
    
    // Crea form e submit
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '';
    
    // Action input
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'generate';
    form.appendChild(actionInput);
    
    // Product IDs
    selected.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'product_ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    // Submit
    document.body.appendChild(form);
    form.submit();
}

// Marca prodotti selezionati come completati
async function markAsCompleted() {
    const selected = Array.from(document.querySelectorAll('.product-check:checked'))
                          .map(cb => cb.value);
    
    if (selected.length === 0) {
        alert('⚠️ Seleziona almeno un prodotto');
        return;
    }
    
    if (!confirm(`✅ Marcare ${selected.length} prodotti come completati su Amazon?`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'mark_completed');
        selected.forEach(id => formData.append('product_ids[]', id));
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`✅ Operazione completata con successo!\n\n` +
                  `📊 ${result.count} modifiche marcate come completate\n` +
                  `🔄 ${result.inventory_updated} prezzi inventory aggiornati\n\n` +
                  `I prezzi nella tabella inventory sono ora allineati con i prezzi su Amazon.`);
            location.reload();
        } else {
            alert('❌ Errore: ' + (result.error || 'Operazione fallita'));
        }
    } catch (error) {
        alert('❌ Errore di connessione: ' + error.message);
    }
}

// === FUNZIONI TAB VERIFICA PREZZI ===

// Toggle selezione checkbox verifica prezzi
function toggleSelectAllVerify(checkbox) {
    const checkboxes = document.querySelectorAll('.verify-check');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateSelectedVerifyCount();
}

// Aggiorna contatore prodotti selezionati per verifica
function updateSelectedVerifyCount() {
    const count = document.querySelectorAll('.verify-check:checked').length;
    const counterEl = document.getElementById('selectedVerifyCount');
    if (counterEl) {
        counterEl.textContent = count;
    }
}

// Listener per singole checkbox verifica
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.verify-check').forEach(cb => {
        cb.addEventListener('change', function() {
            updateSelectedVerifyCount();
            
            // Aggiorna checkbox "Seleziona tutti"
            const total = document.querySelectorAll('.verify-check').length;
            const checked = document.querySelectorAll('.verify-check:checked').length;
            const selectAll = document.getElementById('selectAllVerify');
            if (selectAll) {
                selectAll.checked = (total === checked);
            }
        });
    });
});

// Invia prodotti selezionati come pending per export Amazon
async function sendToPending() {
    const selected = Array.from(document.querySelectorAll('.verify-check:checked'))
                          .map(cb => cb.value);
    
    if (selected.length === 0) {
        alert('⚠️ Seleziona almeno un prodotto con discrepanza prezzo');
        return;
    }
    
    // Ottieni user_id dalla URL
    const urlParams = new URLSearchParams(window.location.search);
    const userId = urlParams.get('user_id');
    
    if (!userId) {
        alert('❌ Errore: User ID non trovato');
        return;
    }
    
    if (!confirm(`📤 Inviare ${selected.length} prodotti alla coda Export Amazon?\n\nQuesti prodotti appariranno nella tab "Export Prezzi Amazon" con status "pending".`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'send_to_pending');
        formData.append('user_id', userId);
        selected.forEach(id => formData.append('product_ids[]', id));
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(`✅ ${result.inserted} prodotti inviati con successo!`, 'success');
            
            // Redirect alla tab export dopo 2 secondi
            setTimeout(() => {
                window.location.href = `?tab=export&user_id=${userId}`;
            }, 2000);
        } else {
            alert('❌ Errore: ' + (result.error || 'Operazione fallita'));
        }
    } catch (error) {
        alert('❌ Errore di connessione: ' + error.message);
    }
}

// Salva prezzo modificato
async function savePrice(productId, field, button) {
    const row = button.closest('tr');
    const input = row.querySelector(`input[data-field="${field}"]`);
    const newValue = input.value.trim();
    
    if (!newValue || newValue === '') {
        alert('⚠️ Inserisci un valore valido');
        return;
    }
    
    // Disabilita pulsanti durante il salvataggio
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    
    try {
        const formData = new FormData();
        formData.append('action', 'save_prices');
        formData.append('product_id', productId);
        formData.append(field, newValue);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Aggiorna valore originale
            input.setAttribute('data-original', newValue);
            
            // Feedback visivo
            input.style.borderColor = '#28a745';
            setTimeout(() => {
                input.style.borderColor = '';
            }, 1500);
            
            // Nascondi pulsanti
            button.closest('.price-actions').style.display = 'none';
            
            // Mostra messaggio successo (opzionale)
            showToast('✅ Prezzo aggiornato con successo', 'success');
        } else {
            alert('❌ Errore: ' + (result.error || 'Salvataggio fallito'));
        }
    } catch (error) {
        alert('❌ Errore di connessione: ' + error.message);
    } finally {
        button.disabled = false;
        button.innerHTML = '<i class="fas fa-check"></i>';
    }
}

// Reset prezzo al valore originale
function resetPrice(button) {
    const editablePrice = button.closest('.editable-price');
    const input = editablePrice.querySelector('.price-input');
    const originalValue = input.getAttribute('data-original');
    
    input.value = parseFloat(originalValue).toFixed(2);
    input.style.borderColor = '';
    
    // Nascondi pulsanti
    editablePrice.querySelector('.price-actions').style.display = 'none';
}

// Toast notification (opzionale)
function showToast(message, type = 'success') {
    // Crea toast element
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 16px 24px;
        background: ${type === 'success' ? '#28a745' : '#dc3545'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 9999;
        font-size: 14px;
        font-weight: 500;
        animation: slideIn 0.3s ease;
    `;
    toast.textContent = message;
    
    // Aggiungi al body
    document.body.appendChild(toast);
    
    // Rimuovi dopo 3 secondi
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// CSS per animazioni toast
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
</script>

</body>
</html>

