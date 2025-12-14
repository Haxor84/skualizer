<?php
/**
 * Mapping Dashboard - Gestione Prodotti e Associazioni SKU
 * File: /modules/mapping/mapping_dashboard.php
 */

// Attiva gli errori per debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/config/mapping_config.php';
require_once __DIR__ . '/MappingRepository.php';
require_once __DIR__ . '/MappingService.php';

$dbConnection = getMappingDbConnection();
$mappingConfig = getMappingConfig();
$mappingRepository = new MappingRepository($dbConnection, $mappingConfig);
$mappingService = new MappingService($mappingRepository, $mappingConfig);

// Lista utenti
$pdo = getMappingDbConnection();
$stmt = $pdo->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY id");
$availableUsers = $stmt->fetchAll();

$userId = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 2));

// Gestione AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_product_name':
            $productId = (int)($_POST['product_id'] ?? 0);
            $newName = trim($_POST['new_name'] ?? '');
            
            if ($productId <= 0 || empty($newName)) {
                echo json_encode(['success' => false, 'error' => 'Dati non validi']);
                exit;
            }
            
            $result = $mappingService->updateProduct($productId, ['nome' => $newName]);
            echo json_encode(['success' => $result]);
            exit;
            
        case 'dissociate_sku':
            try {
                $productId = (int)($_POST['product_id'] ?? 0);
                $sku = $_POST['sku'] ?? '';
                $sourceTable = $_POST['source_table'] ?? '';
                
                if ($productId <= 0 || empty($sku) || empty($sourceTable)) {
                    echo json_encode(['success' => false, 'error' => 'Dati non validi']);
                    exit;
                }
                
                // Dissociazione diretta nel database - TUTTE LE 5 TABELLE
                if ($sourceTable === 'inventory') {
                    $stmt = $dbConnection->prepare("UPDATE inventory SET product_id = NULL WHERE sku = ? AND user_id = ?");
                    $result = $stmt->execute([$sku, $userId]);
                } elseif ($sourceTable === 'inventory_fbm') {
                    $stmt = $dbConnection->prepare("UPDATE inventory_fbm SET product_id = NULL WHERE seller_sku = ? AND user_id = ?");
                    $result = $stmt->execute([$sku, $userId]);
                } elseif ($sourceTable === 'inbound_shipment_items' || $sourceTable === 'inbound_shipments') {
                    $stmt = $dbConnection->prepare("UPDATE inbound_shipment_items SET product_id = NULL WHERE seller_sku = ? AND user_id = ?");
                    $result = $stmt->execute([$sku, $userId]);
                } elseif ($sourceTable === 'removal_orders') {
                    $stmt = $dbConnection->prepare("UPDATE removal_orders SET product_id = NULL WHERE sku = ? AND user_id = ?");
                    $result = $stmt->execute([$sku, $userId]);
                } elseif ($sourceTable === 'settlement') {
                    $settlementTable = "report_settlement_{$userId}";
                    $stmt = $dbConnection->prepare("UPDATE `{$settlementTable}` SET product_id = NULL WHERE sku = ?");
                    $result = $stmt->execute([$sku]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Tabella sorgente non valida: ' . $sourceTable]);
                    exit;
                }
                
                echo json_encode(['success' => $result]);
                
            } catch (Exception $e) {
                error_log("DISSOCIATE ERROR: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'delete_product':
            $productId = (int)($_POST['product_id'] ?? 0);
            
            if ($productId <= 0) {
                echo json_encode(['success' => false, 'error' => 'Product ID non valido']);
                exit;
            }
            
            try {
                $dbConnection->beginTransaction();
                
                // Dissocia tutti gli SKU prima di eliminare
                $stmt = $dbConnection->prepare("UPDATE inventory SET product_id = NULL WHERE product_id = ?");
                $stmt->execute([$productId]);
                
                $stmt = $dbConnection->prepare("UPDATE inventory_fbm SET product_id = NULL WHERE product_id = ?");
                $stmt->execute([$productId]);
                
                $stmt = $dbConnection->prepare("UPDATE report_settlement_{$userId} SET product_id = NULL WHERE product_id = ?");
                $stmt->execute([$productId]);
                
                $stmt = $dbConnection->prepare("DELETE FROM mapping_states WHERE product_id = ?");
                $stmt->execute([$productId]);
                
                $stmt = $dbConnection->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
                $stmt->execute([$productId, $userId]);
                
                $dbConnection->commit();
                echo json_encode(['success' => true]);
                
            } catch (Exception $e) {
                $dbConnection->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
            
        case 'bulk_delete_products':
            $productIds = json_decode($_POST['product_ids'] ?? '[]', true);
            
            if (empty($productIds) || !is_array($productIds)) {
                echo json_encode(['success' => false, 'error' => 'Nessun prodotto selezionato']);
                exit;
            }
            
            try {
                $dbConnection->beginTransaction();
                $deletedCount = 0;
                
                foreach ($productIds as $productId) {
                    $productId = (int)$productId;
                    if ($productId <= 0) continue;
                    
                    // Dissocia SKU
                    $stmt = $dbConnection->prepare("UPDATE inventory SET product_id = NULL WHERE product_id = ?");
                    $stmt->execute([$productId]);
                    
                    $stmt = $dbConnection->prepare("UPDATE inventory_fbm SET product_id = NULL WHERE product_id = ?");
                    $stmt->execute([$productId]);
                    
                    $stmt = $dbConnection->prepare("UPDATE report_settlement_{$userId} SET product_id = NULL WHERE product_id = ?");
                    $stmt->execute([$productId]);
                    
                    $stmt = $dbConnection->prepare("DELETE FROM mapping_states WHERE product_id = ?");
                    $stmt->execute([$productId]);
                    
                    // Elimina prodotto
                    $stmt = $dbConnection->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
                    $stmt->execute([$productId, $userId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $deletedCount++;
                    }
                }
                
                $dbConnection->commit();
                echo json_encode(['success' => true, 'deleted_count' => $deletedCount]);
                
            } catch (Exception $e) {
                $dbConnection->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;
    }
}

// Carica prodotti con SKU associati
$stmt = $dbConnection->prepare("
    SELECT p.id, p.nome, p.sku as product_sku, p.asin, p.user_id,
           u.nome as user_name, u.email as user_email
    FROM products p
    LEFT JOIN users u ON p.user_id = u.id
    WHERE p.user_id = ?
    ORDER BY p.nome ASC
");
$stmt->execute([$userId]);
$products = $stmt->fetchAll();

// Per ogni prodotto, trova gli SKU associati da TUTTE le 5 tabelle
foreach ($products as $index => $product) {
   $skus = [];
   
   // 1. SKU da inventory
   $stmt = $dbConnection->prepare("SELECT DISTINCT sku FROM inventory WHERE product_id = ? AND user_id = ?");
   $stmt->execute([$product['id'], $userId]);
   while ($row = $stmt->fetch()) {
       $skus[] = ['sku' => $row['sku'], 'source' => 'inventory'];
   }
   
   // 2. SKU da inventory_fbm
   $stmt = $dbConnection->prepare("SELECT DISTINCT seller_sku as sku FROM inventory_fbm WHERE product_id = ? AND user_id = ?");
   $stmt->execute([$product['id'], $userId]);
   while ($row = $stmt->fetch()) {
       $skus[] = ['sku' => $row['sku'], 'source' => 'inventory_fbm'];
   }
   
   // 3. SKU da inbound_shipment_items
   try {
       $stmt = $dbConnection->prepare("SELECT DISTINCT seller_sku as sku FROM inbound_shipment_items WHERE product_id = ? AND user_id = ?");
       $stmt->execute([$product['id'], $userId]);
       while ($row = $stmt->fetch()) {
           $skus[] = ['sku' => $row['sku'], 'source' => 'inbound_shipment_items'];
       }
   } catch (Exception $e) {
       // Tabella inbound_shipment_items potrebbe non esistere
   }
   
   // 4. SKU da removal_orders
   try {
       $stmt = $dbConnection->prepare("SELECT DISTINCT sku FROM removal_orders WHERE product_id = ? AND user_id = ?");
       $stmt->execute([$product['id'], $userId]);
       while ($row = $stmt->fetch()) {
           $skus[] = ['sku' => $row['sku'], 'source' => 'removal_orders'];
       }
   } catch (Exception $e) {
       // Tabella removal_orders potrebbe non esistere
   }
   
   // 5. SKU da settlement
   $settlementTable = "report_settlement_{$userId}";
   try {
       $stmt = $dbConnection->prepare("SELECT DISTINCT sku FROM `{$settlementTable}` WHERE product_id = ?");
       $stmt->execute([$product['id']]);
       while ($row = $stmt->fetch()) {
           $skus[] = ['sku' => $row['sku'], 'source' => 'settlement'];
       }
   } catch (Exception $e) {
       // Tabella settlement non esiste
   }
   
   $products[$index]['skus'] = $skus;
}
?>

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../margynomic/admin/admin_helpers.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapping Dashboard - Margynomic Admin</title>
    <link rel="stylesheet" href="../margynomic/css/margynomic.css">
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
        
        .dashboard-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .dashboard-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        
        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1a1a1a;
            margin: 8px 0 4px 0;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        
        .section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f3f4;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .product-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }

        .product-card:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .product-card.selected {
            border-color: #667eea;
            background: #e3f2fd;
        }

        .product-header {
            margin-bottom: 8px;
        }

        .product-title {
            font-weight: 600;
            font-size: 18px;
            color: #1a1a1a;
            margin-bottom: 8px;
        }

        .product-meta {
            font-size: 14px;
            color: #666;
            margin-bottom: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sku-list {
            margin-top: 16px;
        }

        .sku-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .sku-info {
            flex: 1;
        }

        .sku-code {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 14px;
            font-family: monospace;
        }

        .sku-source {
            font-size: 12px;
            color: #666;
            margin-left: 8px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-primary { 
            background: #667eea; 
            color: white; 
        }
        
        .btn-primary:hover { 
            background: #5a6fd8; 
        }
        
        .btn-secondary { 
            background: #6c757d; 
            color: white; 
        }
        
        .btn-secondary:hover { 
            background: #545b62; 
        }
        
        .btn-success { 
            background: #28a745; 
            color: white; 
        }
        
        .btn-success:hover { 
            background: #218838; 
        }
        
        .btn-danger { 
            background: #dc3545; 
            color: white; 
        }
        
        .btn-danger:hover { 
            background: #c82333; 
        }

        .btn-sm { 
            padding: 6px 12px; 
            font-size: 12px; 
        }
        
        .edit-input {
            border: 2px solid #e9ecef;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 14px;
            width: 100%;
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        
        .edit-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    text-align: center;
}

.stat-number {
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .product-checkbox {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .form-select {
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            background: #fafbfc;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .form-input {
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        .product-card.hidden {
            display: none;
        }
        
        .search-highlight {
            background: #fff3cd;
            padding: 1px 3px;
            border-radius: 2px;
        }
        
        .filter-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }
        
        .filter-card.active {
            background: linear-gradient(135deg, #667eea, #5a6fd8);
            color: white;
        }
        
        .filter-card.active .stat-number {
            color: white;
        }
        
        .filter-card.active .stat-label {
            color: rgba(255, 255, 255, 0.9);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .main-container {
                padding: 15px;
            }
            
            .dashboard-header {
                padding: 20px;
            }
            
            .dashboard-title {
                font-size: 24px;
            }
        }
</style>
</head>
<body>
    <?php echo getAdminNavigation('mapping_dashboard'); ?>
    
    <div class="main-container">
        <!-- Header Dashboard -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">
                <i class="fas fa-sitemap"></i>
                Mapping Dashboard
            </h1>
            <p class="dashboard-subtitle">
                Gestisci nomi prodotti e associazioni SKU per il sistema Margynomic
            </p>
        </div>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-user-cog"></i>
                    Configurazione Utente
                </h2>
                <div>
                    <a href="sku_aggregation_interface.php?user_id=<?= $userId ?>" class="btn btn-secondary">
                        <i class="fas fa-link"></i>
                        Aggregazione SKU
                    </a>
                </div>
            </div>
            
            <!-- Selezione Utente -->
            <div class="form-group">
                <label class="form-label">Utente:</label>
                <select onchange="window.location.href='?user_id='+this.value" class="form-select" style="width: 300px;">
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user['id'] == $userId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?> (<?= $user['email'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Statistiche Principali -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #28a745;">
                        <i class="fas fa-boxes"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count($products) ?></div>
                <div class="stat-label">Prodotti Totali</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #17a2b8;">
                        <i class="fas fa-tags"></i>
                    </div>
                </div>
                <div class="stat-number"><?= array_sum(array_map(fn($p) => count($p['skus']), $products)) ?></div>
                <div class="stat-label">SKU Associati</div>
            </div>
            <div class="stat-card filter-card" id="filter-no-skus" onclick="toggleFilter('no-skus')" style="cursor: pointer;">
                <div class="stat-header">
                    <div class="stat-icon" style="background: #ffc107;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-number"><?= count(array_filter($products, fn($p) => empty($p['skus']))) ?></div>
                <div class="stat-label">Prodotti Senza SKU</div>
            </div>
        </div>

        <!-- Azioni Bulk -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-bolt"></i>
                    Operazioni Multiple
                </h2>
            </div>
            <div style="display: flex; gap: 10px; align-items: center;">
                <button id="select-all-btn" class="btn btn-secondary">
                    <i class="fas fa-check-square"></i>
                    Seleziona Tutti
                </button>
                <button id="deselect-all-btn" class="btn btn-secondary">
                    <i class="fas fa-square"></i>
                    Deseleziona Tutti
                </button>
                <button id="bulk-delete-btn" class="btn btn-danger" disabled>
                    <i class="fas fa-trash"></i>
                    Elimina Selezionati (<span id="selected-count">0</span>)
                </button>
            </div>
        </div>

        <!-- Alert Container -->
        <div id="alert-container"></div>

        <!-- Lista Prodotti -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-list"></i>
                    Lista Prodotti
                </h2>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <input type="text" 
                           id="product-search" 
                           class="form-input" 
                           placeholder="Cerca prodotti... (es: pest pist 10)"
                           style="width: 300px; margin: 0;">
                    <button id="clear-search" class="btn btn-secondary btn-sm" style="display: none;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <div id="products-container">
                <?php foreach ($products as $product): ?>
                    <div class="product-card" data-product-id="<?= $product['id'] ?>">
                        <div class="product-header">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                <input type="checkbox" class="product-checkbox" data-product-id="<?= $product['id'] ?>">
                                <div class="product-title" style="flex: 1;">
                                    <span class="product-name" onclick="editProductName(<?= $product['id'] ?>, '<?= htmlspecialchars($product['nome']) ?>')" style="cursor: pointer; color: #1C1C1C; font-weight: 600; font-size: 1.1rem;">
                                        <?= htmlspecialchars($product['nome']) ?>
                                    </span>
                                    <input type="text" class="edit-input" style="display: none;" />
                                </div>
                                <button class="btn btn-danger btn-sm" onclick="deleteProduct(<?= $product['id'] ?>, '<?= htmlspecialchars($product['nome']) ?>')">
                                    <i class="fas fa-trash"></i>
                                    Elimina
                                </button>
                            </div>
                            
                            <div class="product-meta">
                                ID: <?= $product['id'] ?>
                                <?php if ($product['product_sku']): ?>
                                    | SKU Prodotto: <?= htmlspecialchars($product['product_sku']) ?>
                                <?php endif; ?>
                                <?php if ($product['asin']): ?>
                                    | ASIN: <?= htmlspecialchars($product['asin']) ?>
                                <?php endif; ?>
                                | Utente: <?= htmlspecialchars($product['user_name']) ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($product['skus'])): ?>
                            <?php
                            // Mappa nomi tabelle leggibili
                            $tableLabels = [
                                'inventory' => 'Inventory FBA',
                                'inventory_fbm' => 'Inventory FBM',
                                'inbound_shipment_items' => 'Inbound Shipments',
                                'removal_orders' => 'Removal Orders',
                                'settlement' => 'Settlement Reports'
                            ];
                            
                            // Conta SKU per tabella
                            $skusByTable = [];
                            foreach ($product['skus'] as $skuData) {
                                $source = $skuData['source'];
                                if (!isset($skusByTable[$source])) {
                                    $skusByTable[$source] = [];
                                }
                                $skusByTable[$source][] = $skuData;
                            }
                            
                            $totalSkuCount = count($product['skus']);
                            $totalTableCount = count($skusByTable);
                            ?>
                            <div class="sku-list">
                                <div style="margin-bottom: 10px; font-weight: 600;">
                                    SKU Associati (<?= $totalSkuCount ?> SKU in <?= $totalTableCount ?> tabelle):
                                </div>
                                <?php foreach ($product['skus'] as $skuData): ?>
                                    <?php
                                    $tableLabel = $tableLabels[$skuData['source']] ?? ucfirst(str_replace('_', ' ', $skuData['source']));
                                    ?>
                                    <div class="sku-item">
                                        <div class="sku-info">
                                            <span class="sku-code"><?= htmlspecialchars($skuData['sku']) ?></span>
                                            <span class="sku-source">[<?= $tableLabel ?>]</span>
                                        </div>
                                        <button class="btn btn-danger btn-sm" onclick="dissociateSku(<?= $product['id'] ?>, '<?= htmlspecialchars($skuData['sku']) ?>', '<?= $skuData['source'] ?>')">
                                            <i class="fas fa-unlink"></i>
                                            Dissocia
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="sku-list">
                                <em style="color: #6B7280;">Nessun SKU associato a questo prodotto</em>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($products)): ?>
                    <div class="product-card">
                        <div style="text-align: center; color: #6B7280; padding: 2rem;">
                            <em>Nessun prodotto trovato per questo utente</em>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Variabile globale per tracking filtro e ricerca
        let activeFilter = null;
        
        function showAlert(message, type = 'success') {
            const container = document.getElementById('alert-container');
            if (!container) {
                console.error('Alert container not found');
                return;
            }
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.textContent = message;
            
            container.innerHTML = '';
            container.appendChild(alertDiv);
            
            // Rimuovi alert dopo 5 secondi (con check sicuro)
            setTimeout(() => {
                if (alertDiv && alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        function editProductName(productId, currentName) {
            const productCard = document.querySelector(`[data-product-id="${productId}"]`);
            const nameSpan = productCard.querySelector('.product-name');
            const editInput = productCard.querySelector('.edit-input');
            
            nameSpan.style.display = 'none';
            editInput.style.display = 'inline-block';
            editInput.value = currentName;
            editInput.focus();
            
            editInput.onblur = editInput.onkeydown = function(e) {
                if (e.type === 'blur' || e.key === 'Enter') {
                    const newName = editInput.value.trim();
                    if (newName && newName !== currentName) {
                        updateProductName(productId, newName);
                    } else {
                        editInput.style.display = 'none';
                        nameSpan.style.display = 'inline';
                    }
                }
                if (e.key === 'Escape') {
                    editInput.style.display = 'none';
                    nameSpan.style.display = 'inline';
                }
            };
        }

        function updateProductName(productId, newName) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=update_product_name&product_id=${productId}&new_name=${encodeURIComponent(newName)}`
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    showAlert('Nome aggiornato con successo');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showAlert('Errore aggiornamento: ' + (data?.error || 'Errore sconosciuto'), 'error');
                }
            })
            .catch(e => {
                console.error('Update name error:', e);
                showAlert('Errore di connessione: ' + e.message, 'error');
            });
        }

        function dissociateSku(productId, sku, sourceTable) {
            if (!confirm(`Dissociare SKU "${sku}" dal prodotto?`)) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=dissociate_sku&product_id=${productId}&sku=${encodeURIComponent(sku)}&source_table=${sourceTable}`
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    showAlert('SKU dissociato con successo');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showAlert('Errore dissociazione: ' + (data?.error || 'Errore sconosciuto'), 'error');
                }
            })
            .catch(e => {
                console.error('Dissociate SKU error:', e);
                showAlert('Errore di connessione: ' + e.message, 'error');
            });
        }

        function deleteProduct(productId, productName) {
            if (!confirm(`Eliminare definitivamente il prodotto "${productName}"?\n\nQuesta azione dissocerà tutti gli SKU e non può essere annullata.`)) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=delete_product&product_id=${productId}`
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    showAlert('Prodotto eliminato con successo');
                    // Reload dopo un breve delay per mostrare l'alert
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('Errore eliminazione: ' + (data?.error || 'Errore sconosciuto'), 'error');
                }
            })
            .catch(e => {
                console.error('Delete error:', e);
                showAlert('Errore di connessione: ' + e.message, 'error');
            });
        }

        // Gestione selezioni multiple
        let selectedProducts = new Set();

        function updateBulkActions() {
            const count = selectedProducts.size;
            document.getElementById('selected-count').textContent = count;
            document.getElementById('bulk-delete-btn').disabled = count === 0;
        }

        function selectAllProducts() {
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.checked = true;
                selectedProducts.add(checkbox.dataset.productId);
                checkbox.closest('.product-card').classList.add('selected');
            });
            updateBulkActions();
        }

        function deselectAllProducts() {
            document.querySelectorAll('.product-checkbox').forEach(checkbox => {
                checkbox.checked = false;
                checkbox.closest('.product-card').classList.remove('selected');
            });
            selectedProducts.clear();
            updateBulkActions();
        }

        function bulkDeleteProducts() {
            if (selectedProducts.size === 0) return;
            
            if (!confirm(`Eliminare definitivamente ${selectedProducts.size} prodotti selezionati?\n\nQuesta azione dissocerà tutti gli SKU e non può essere annullata.`)) return;
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=bulk_delete_products&product_ids=${JSON.stringify([...selectedProducts])}`
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.success) {
                    showAlert(`${data.deleted_count} prodotti eliminati con successo`);
                    // Reload dopo un breve delay per mostrare l'alert
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showAlert('Errore eliminazione: ' + (data?.error || 'Errore sconosciuto'), 'error');
                }
            })
            .catch(e => {
                console.error('Bulk delete error:', e);
                showAlert('Errore di connessione: ' + e.message, 'error');
            });
        }

        // Event listeners per selezioni
        document.getElementById('select-all-btn').addEventListener('click', selectAllProducts);
        document.getElementById('deselect-all-btn').addEventListener('click', deselectAllProducts);
        document.getElementById('bulk-delete-btn').addEventListener('click', bulkDeleteProducts);

        document.querySelectorAll('.product-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const productId = this.dataset.productId;
                const card = this.closest('.product-card');
                
                if (this.checked) {
                    selectedProducts.add(productId);
                    card.classList.add('selected');
                } else {
                    selectedProducts.delete(productId);
                    card.classList.remove('selected');
                }
                updateBulkActions();
            });
        });
// === RICERCA SEMPLICE ED EFFICACE ===
        
function searchProducts(searchTerm) {
    // Se c'è un filtro attivo, mantienilo durante la ricerca
    const hasActiveFilter = activeFilter !== null;
    const productCards = document.querySelectorAll('.product-card');
    let visibleCount = 0;
    
    // Se ricerca vuota, mostra tutto
    if (!searchTerm.trim()) {
        productCards.forEach(card => {
            card.style.display = ''; // Rimuovi inline style
            card.classList.remove('hidden');
            removeHighlights(card);
        });
        document.getElementById('clear-search').style.display = 'none';
        updateSearchResults(0, '');
        return;
    }
    
    document.getElementById('clear-search').style.display = 'inline-block';
    
    // Normalizza il termine di ricerca
    const normalizedSearch = searchTerm.toLowerCase()
                                      .normalize('NFD')
                                      .replace(/[\u0300-\u036f]/g, '')
                                      .trim();
    
    productCards.forEach(card => {
        // Testo da cercare: nome + metadati + SKU
        const productName = card.querySelector('.product-name')?.textContent || '';
        const productMeta = card.querySelector('.product-meta')?.textContent || '';
        const skuElements = card.querySelectorAll('.sku-code');
        let skuText = '';
        skuElements.forEach(sku => skuText += ' ' + sku.textContent);
        
        const fullText = (productName + ' ' + productMeta + ' ' + skuText)
                        .toLowerCase()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '');
        
        // Ricerca: se il testo normalizzato contiene la ricerca
        const isMatch = fullText.includes(normalizedSearch);
        
        if (isMatch) {
            card.style.display = ''; // Rimuovi inline style
            card.classList.remove('hidden');
            visibleCount++;
            
            const nameElement = card.querySelector('.product-name');
            if (nameElement) {
                highlightWords(nameElement, [normalizedSearch]);
            }
        } else {
            card.style.display = 'none'; // Forza nascondimento
            card.classList.add('hidden');
            
            const nameElement = card.querySelector('.product-name');
            if (nameElement) {
                removeHighlights(card);
            }
        }
    });
    
    updateSearchResults(visibleCount, searchTerm);
}

function highlightWords(element, words) {
    let html = element.textContent;
    
    words.forEach(word => {
        if (!/^\d+$/.test(word)) { // evidenzia solo testo, non numeri
            const regex = new RegExp(`(${escapeRegex(word)})`, 'gi');
            html = html.replace(regex, '<span class="search-highlight">$1</span>');
        }
    });
    
    element.innerHTML = html;
}

function removeHighlights(card) {
    card.querySelectorAll('.search-highlight').forEach(span => {
        span.outerHTML = span.textContent;
    });
}

function escapeRegex(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function updateSearchResults(count, searchTerm) {
    const existingCounter = document.getElementById('search-results-counter');
    if (existingCounter) existingCounter.remove();
    
    if (searchTerm) {
        const counter = document.createElement('div');
        counter.id = 'search-results-counter';
        counter.style.cssText = 'margin: 10px 0; color: #6B7280; font-size: 0.875rem;';
        counter.innerHTML = `🔍 <strong>${count}</strong> prodotto${count !== 1 ? 'i' : ''} trovato${count !== 1 ? 'i' : ''} per "<em>${searchTerm}</em>"`;
        
        document.getElementById('products-container').parentNode.insertBefore(
            counter, 
            document.getElementById('products-container')
        );
    }
}
        
        // Event listeners
        const searchInput = document.getElementById('product-search');
        const clearButton = document.getElementById('clear-search');
        
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchProducts(this.value);
            }, 300);
        });
        
        clearButton.addEventListener('click', function() {
            searchInput.value = '';
            searchProducts('');
            searchInput.focus();
        });
        
        // Ctrl+F per focus
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        });

        // Funzione per toggleare il filtro (activeFilter è dichiarato all'inizio dello script)
function toggleFilter(filterType) {
    const filterCard = document.getElementById('filter-' + filterType);
    const allProducts = document.querySelectorAll('.product-card');
    
    if (activeFilter === filterType) {
        // Disattiva filtro
        activeFilter = null;
        filterCard.classList.remove('active');
        
        // Mostra tutti i prodotti
        allProducts.forEach(card => {
            card.style.display = 'block';
        });
        
        showAlert('Filtro rimosso - Tutti i prodotti visibili', 'success');
    } else {
        // Attiva filtro
        activeFilter = filterType;
        
        // Rimuovi classe active da tutte le card
        document.querySelectorAll('.filter-card').forEach(card => {
            card.classList.remove('active');
        });
        
        // Aggiungi classe active alla card cliccata
        filterCard.classList.add('active');
        
        let visibleCount = 0;
        
        // Filtra prodotti
allProducts.forEach(card => {
    const productId = card.dataset.productId;
    const skuElements = card.querySelectorAll('.sku-code');
    const hasSkus = skuElements.length > 0;
    
    if (filterType === 'no-skus' && !hasSkus) {
        card.style.display = 'block';
        visibleCount++;
    } else if (filterType === 'no-skus' && hasSkus) {
        card.style.display = 'none';
    }
});
        
        showAlert(`Filtro attivo: ${visibleCount} prodotti senza SKU`, 'success');
    }
}
    </script>

    </div> <!-- /main-container -->
</body>
</html>