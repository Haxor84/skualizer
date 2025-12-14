<?php
/**
 * Visualizza Settlement - Explorer Dati Raw
 * File: modules/margynomic/margini/visualizza_settlement.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/admin/admin_helpers.php';

// Verifica autenticazione ADMIN
if (!isAdminLogged()) {
    header('Location: ../admin/admin_login.php');
    exit();
}

// Parametri filtri
$selectedUserId = intval($_GET['user_id'] ?? 0);
$selectedTransactionTypes = $_GET['transaction_types'] ?? [];
$selectedItemFeeType = trim($_GET['item_fee_type'] ?? '');
$selectedSku = trim($_GET['sku'] ?? '');
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = $_GET['limit'] ?? '200';
$sortBy = $_GET['sort'] ?? 'posted_date';
$sortOrder = $_GET['order'] ?? 'desc';

// Inizializza variabili
$data = [];
$totalRecords = 0;
$transactionTypes = [];
$itemFeeTypes = [];
$tableName = '';
$users = [];
$error = '';

try {
    $pdo = getDbConnection();
    
    // Ottieni lista utenti
    $stmt = $pdo->query("SHOW TABLES LIKE 'report_settlement_%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($tables as $table) {
        $userId = str_replace('report_settlement_', '', $table);
        
        $userStmt = $pdo->prepare("SELECT email, nome FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userInfo = $userStmt->fetch();
        
        if ($userInfo) {
            $users[] = [
                'id' => $userId,
                'email' => $userInfo['email'],
                'nome' => $userInfo['nome']
            ];
        }
    }
    
    // Se utente selezionato, carica i dati
    if ($selectedUserId) {
        $tableName = "report_settlement_" . $selectedUserId;
        
        // Verifica esistenza tabella
        $checkStmt = $pdo->query("SHOW TABLES LIKE '" . $tableName . "'");
        if (!$checkStmt->fetch()) {
            throw new Exception("Tabella settlement non trovata per User ID: " . $selectedUserId);
        }
        
        // Carica opzioni dropdown transaction types
        $ttStmt = $pdo->query("SELECT DISTINCT transaction_type FROM " . $tableName . " WHERE transaction_type IS NOT NULL ORDER BY transaction_type LIMIT 100");
        $transactionTypes = $ttStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Carica opzioni dropdown item fee types
        $iftStmt = $pdo->query("SELECT DISTINCT item_related_fee_type FROM " . $tableName . " WHERE item_related_fee_type IS NOT NULL ORDER BY item_related_fee_type LIMIT 100");
        $itemFeeTypes = $iftStmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Costruisci WHERE clause
        $whereConditions = [];
        $params = [];
        
        if (!empty($selectedTransactionTypes)) {
            // Rimuovi eventuali valori vuoti dall'array
            $selectedTransactionTypes = array_filter($selectedTransactionTypes, function($value) {
                return $value !== '';
            });
            
            // Applica filtro solo se ci sono transaction types specifici selezionati
            if (!empty($selectedTransactionTypes)) {
                $placeholders = str_repeat('?,', count($selectedTransactionTypes) - 1) . '?';
                $whereConditions[] = "transaction_type IN ($placeholders)";
                $params = array_merge($params, $selectedTransactionTypes);
            }
        }
        
        if ($selectedItemFeeType) {
            $whereConditions[] = "item_related_fee_type = ?";
            $params[] = $selectedItemFeeType;
        }
        
        if ($selectedSku) {
            $whereConditions[] = "sku LIKE ?";
            $params[] = "%" . $selectedSku . "%";
        }
        
        if (!empty($_GET['order_id'])) {
            $orderId = trim($_GET['order_id']);
            $whereConditions[] = "order_id LIKE ?";
            $params[] = "%" . $orderId . "%";
        }
        
        if (!empty($_GET['search'])) {
            $search = trim($_GET['search']);
            $whereConditions[] = "(sku LIKE ? OR order_id LIKE ? OR settlement_id LIKE ? OR hash LIKE ? OR marketplace_name LIKE ? OR other_fee_reason_description LIKE ?)";
            $params[] = "%" . $search . "%";
            $params[] = "%" . $search . "%";
            $params[] = "%" . $search . "%";
            $params[] = "%" . $search . "%";
            $params[] = "%" . $search . "%";
            $params[] = "%" . $search . "%";
        }
        
        if ($startDate) {
            $whereConditions[] = "posted_date >= ?";
            $params[] = $startDate . ' 00:00:00';
        }
        
        if ($endDate) {
            $whereConditions[] = "posted_date <= ?";
            $params[] = $endDate . ' 23:59:59';
        }
        
        $whereClause = '';
        if (!empty($whereConditions)) {
            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
        }
        
        // Conta totale record
        $countSql = "SELECT COUNT(*) FROM " . $tableName . " " . $whereClause;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn();
        
        // Query principale con limite massimo sicurezza
        $maxLimit = 5000; // Limite massimo per sicurezza memoria
        $actualLimit = ($limit === 'all') ? $maxLimit : intval($limit);
        $offset = ($page - 1) * $actualLimit;
        $limitClause = "LIMIT " . $actualLimit . " OFFSET " . $offset;
        
        $sql = "SELECT * FROM " . $tableName . " " . $whereClause . " ORDER BY " . $sortBy . " " . $sortOrder . " " . $limitClause;
        $dataStmt = $pdo->prepare($sql);
        $dataStmt->execute($params);
        $data = $dataStmt->fetchAll();
    }
    
} catch (Exception $e) {
    $error = "Errore: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizza Settlement - Margynomic</title>
    <link rel="stylesheet" href="../css/margynomic.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .filters-panel {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }
        .filter-input {
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 0.875rem;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .data-table th {
            background: #f9fafb;
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        .data-table td {
            padding: 0.5rem;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        .data-table tbody tr:hover {
            background: #f9fafb;
        }
        .table-container {
            max-height: 70vh;
            overflow: auto;
            border-radius: 8px;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        .page-btn {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            background: white;
            text-decoration: none;
            border-radius: 4px;
            color: #374151;
        }
        .page-btn:hover, .page-btn.active {
            background: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }
        .stats-info {
            background: #e0f2fe;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .sort-link {
            color: inherit;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        .sort-link:hover {
            color: #3b82f6;
        }
        .amount-positive { color: #28a745; font-weight: bold; }
        .amount-negative { color: #dc3545; font-weight: bold; }
        .amount-neutral { color: #6c757d; }
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container" style="max-width: 95%; margin: 1rem auto; padding: 0 1rem;">
        
        <!-- Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div>
                <h1><i class="fas fa-table"></i> Visualizza Settlement</h1>
                <p style="color: #666;">Explorer completo dati raw settlement Amazon</p>
            </div>
            <div>
                <a href="admin_fee_mappings.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Torna a Mappings
                </a>
            </div>
        </div>

        <!-- Alert errori -->
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Filtri -->
        <div class="filters-panel">
            <form method="GET" id="filtersForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">👤 User ID</label>
                        <select name="user_id" class="filter-input" onchange="this.form.submit()">
                            <option value="">Seleziona utente</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" 
                                        <?php echo $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                                    ID <?php echo $user['id']; ?> - <?php echo htmlspecialchars($user['email']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">🔄 Transaction Type (Multi-Select)</label>
                        <div style="max-height: 150px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 4px; padding: 0.5rem; background: white;">
                            <label style="display: block; margin-bottom: 0.25rem; font-weight: normal;">
                                <input type="checkbox" name="transaction_types[]" value="" 
                                       <?php echo empty($_GET['transaction_types']) ? 'checked' : ''; ?>> 
                                <strong>Tutti i Transaction Type</strong>
                            </label>
                            <?php foreach ($transactionTypes as $type): ?>
                                <label style="display: block; margin-bottom: 0.25rem; font-weight: normal; font-size: 0.875rem;">
                                    <input type="checkbox" name="transaction_types[]" value="<?php echo htmlspecialchars($type); ?>" 
                                           <?php echo (isset($_GET['transaction_types']) && in_array($type, $_GET['transaction_types'])) ? 'checked' : ''; ?>> 
                                    <?php echo htmlspecialchars($type); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">💰 Item Fee Type</label>
                        <select name="item_fee_type" class="filter-input">
                            <option value="">Tutti</option>
                            <?php foreach ($itemFeeTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" 
                                        <?php echo $selectedItemFeeType === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">📦 SKU</label>
                        <input type="text" name="sku" class="filter-input" 
                               placeholder="Cerca SKU..." 
                               value="<?php echo htmlspecialchars($selectedSku); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">📋 Order ID</label>
                        <input type="text" name="order_id" class="filter-input" 
                               placeholder="Cerca Order ID..." 
                               value="<?php echo htmlspecialchars($_GET['order_id'] ?? ''); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">🔍 Ricerca Globale</label>
                        <input type="text" name="search" class="filter-input" 
                               placeholder="Cerca in Settlement ID, Hash..." 
                               value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">📅 Data Inizio</label>
                        <input type="date" name="start_date" class="filter-input" 
                               value="<?php echo htmlspecialchars($startDate); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">📅 Data Fine</label>
                        <input type="date" name="end_date" class="filter-input" 
                               value="<?php echo htmlspecialchars($endDate); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">📄 Righe per Pagina</label>
                        <select name="limit" class="filter-input">
                            <option value="200" <?php echo $limit === '200' ? 'selected' : ''; ?>>200</option>
                            <option value="500" <?php echo $limit === '500' ? 'selected' : ''; ?>>500</option>
                            <option value="1000" <?php echo $limit === '1000' ? 'selected' : ''; ?>>1000</option>
                            <option value="all" <?php echo $limit === 'all' ? 'selected' : ''; ?>>Max 10000 (Sicurezza)</option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtra Dati
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-refresh"></i> Reset Filtri
                    </a>
                </div>
            </form>
        </div>

        <?php if ($selectedUserId && !empty($data)): ?>
            <!-- Statistiche -->
            <div class="stats-info">
                <div>
                    <strong><?php echo number_format($totalRecords); ?></strong> record trovati
                    <?php if ($limit !== 'all'): ?>
                        • Pagina <?php echo $page; ?> di <?php echo ceil($totalRecords / intval($limit)); ?>
                    <?php else: ?>
                        • Limitato a 5000 record per performance
                    <?php endif; ?>
                </div>
                <div>
                    <strong>Tabella:</strong> <?php echo $tableName; ?>
                </div>
            </div>

            <!-- Tabella Dati -->
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'id', 'order' => $sortBy === 'id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">ID <?php if($sortBy === 'id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'hash', 'order' => $sortBy === 'hash' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Hash <?php if($sortBy === 'hash'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'settlement_id', 'order' => $sortBy === 'settlement_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Settlement ID <?php if($sortBy === 'settlement_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'settlement_start_date', 'order' => $sortBy === 'settlement_start_date' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Settlement Start <?php if($sortBy === 'settlement_start_date'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'settlement_end_date', 'order' => $sortBy === 'settlement_end_date' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Settlement End <?php if($sortBy === 'settlement_end_date'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'deposit_date', 'order' => $sortBy === 'deposit_date' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Deposit Date <?php if($sortBy === 'deposit_date'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'total_amount', 'order' => $sortBy === 'total_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Total Amount <?php if($sortBy === 'total_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'currency', 'order' => $sortBy === 'currency' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Currency <?php if($sortBy === 'currency'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'transaction_type', 'order' => $sortBy === 'transaction_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Transaction Type <?php if($sortBy === 'transaction_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'order_id', 'order' => $sortBy === 'order_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Order ID <?php if($sortBy === 'order_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'merchant_order_id', 'order' => $sortBy === 'merchant_order_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Merchant Order ID <?php if($sortBy === 'merchant_order_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'adjustment_id', 'order' => $sortBy === 'adjustment_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Adjustment ID <?php if($sortBy === 'adjustment_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'shipment_id', 'order' => $sortBy === 'shipment_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Shipment ID <?php if($sortBy === 'shipment_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'marketplace_name', 'order' => $sortBy === 'marketplace_name' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Marketplace <?php if($sortBy === 'marketplace_name'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'shipment_fee_type', 'order' => $sortBy === 'shipment_fee_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Shipment Fee Type <?php if($sortBy === 'shipment_fee_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'shipment_fee_amount', 'order' => $sortBy === 'shipment_fee_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Shipment Fee Amount <?php if($sortBy === 'shipment_fee_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'order_fee_type', 'order' => $sortBy === 'order_fee_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Order Fee Type <?php if($sortBy === 'order_fee_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'order_fee_amount', 'order' => $sortBy === 'order_fee_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Order Fee Amount <?php if($sortBy === 'order_fee_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'fulfillment_id', 'order' => $sortBy === 'fulfillment_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Fulfillment ID <?php if($sortBy === 'fulfillment_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'posted_date', 'order' => $sortBy === 'posted_date' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Posted Date <?php if($sortBy === 'posted_date'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'order_item_code', 'order' => $sortBy === 'order_item_code' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Order Item Code <?php if($sortBy === 'order_item_code'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'merchant_order_item_id', 'order' => $sortBy === 'merchant_order_item_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Merchant Order Item ID <?php if($sortBy === 'merchant_order_item_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'merchant_adjustment_item_id', 'order' => $sortBy === 'merchant_adjustment_item_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Merchant Adj Item ID <?php if($sortBy === 'merchant_adjustment_item_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'sku', 'order' => $sortBy === 'sku' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">SKU <?php if($sortBy === 'sku'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'quantity_purchased', 'order' => $sortBy === 'quantity_purchased' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Quantity <?php if($sortBy === 'quantity_purchased'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'price_type', 'order' => $sortBy === 'price_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Price Type <?php if($sortBy === 'price_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'price_amount', 'order' => $sortBy === 'price_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Price Amount <?php if($sortBy === 'price_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'item_related_fee_type', 'order' => $sortBy === 'item_related_fee_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Item Fee Type <?php if($sortBy === 'item_related_fee_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'item_related_fee_amount', 'order' => $sortBy === 'item_related_fee_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Item Fee Amount <?php if($sortBy === 'item_related_fee_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'misc_fee_amount', 'order' => $sortBy === 'misc_fee_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Misc Fee Amount <?php if($sortBy === 'misc_fee_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'other_fee_amount', 'order' => $sortBy === 'other_fee_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Other Fee Amount <?php if($sortBy === 'other_fee_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'other_fee_reason_description', 'order' => $sortBy === 'other_fee_reason_description' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Other Fee Reason <?php if($sortBy === 'other_fee_reason_description'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'promotion_id', 'order' => $sortBy === 'promotion_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Promotion ID <?php if($sortBy === 'promotion_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'promotion_type', 'order' => $sortBy === 'promotion_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Promotion Type <?php if($sortBy === 'promotion_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'promotion_amount', 'order' => $sortBy === 'promotion_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Promotion Amount <?php if($sortBy === 'promotion_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'direct_payment_type', 'order' => $sortBy === 'direct_payment_type' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Direct Payment Type <?php if($sortBy === 'direct_payment_type'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'direct_payment_amount', 'order' => $sortBy === 'direct_payment_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Direct Payment Amount <?php if($sortBy === 'direct_payment_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'other_amount', 'order' => $sortBy === 'other_amount' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Other Amount <?php if($sortBy === 'other_amount'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'product_id', 'order' => $sortBy === 'product_id' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Product ID <?php if($sortBy === 'product_id'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                            <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'date_uploaded', 'order' => $sortBy === 'date_uploaded' && $sortOrder === 'asc' ? 'desc' : 'asc', 'page' => 1])); ?>" class="sort-link">Date Uploaded <?php if($sortBy === 'date_uploaded'): ?><i class="fas fa-sort-<?php echo $sortOrder === 'asc' ? 'up' : 'down'; ?>"></i><?php endif; ?></a></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data as $row): ?>
                            <tr>
                                <td><?php echo $row['id'] ?? ''; ?></td>
                                <td><?php echo htmlspecialchars(substr($row['hash'] ?? '', 0, 8)); ?></td>
                                <td><?php echo htmlspecialchars($row['settlement_id'] ?? ''); ?></td>
                                <td><?php echo $row['settlement_start_date'] ?? ''; ?></td>
                                <td><?php echo $row['settlement_end_date'] ?? ''; ?></td>
                                <td><?php echo $row['deposit_date'] ?? ''; ?></td>
                                <td><span class="amount-positive">€<?php echo number_format($row['total_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo htmlspecialchars($row['currency'] ?? ''); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['transaction_type'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['order_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['merchant_order_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['adjustment_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['shipment_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['marketplace_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['shipment_fee_type'] ?? ''); ?></td>
                                <td><span class="amount-negative">€<?php echo number_format($row['shipment_fee_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo htmlspecialchars($row['order_fee_type'] ?? ''); ?></td>
                                <td><span class="amount-negative">€<?php echo number_format($row['order_fee_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo htmlspecialchars($row['fulfillment_id'] ?? ''); ?></td>
                                <td><?php echo $row['posted_date'] ?? ''; ?></td>
                                <td><?php echo htmlspecialchars($row['order_item_code'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['merchant_order_item_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['merchant_adjustment_item_id'] ?? ''); ?></td>
                                <td><code><?php echo htmlspecialchars($row['sku'] ?? ''); ?></code></td>
                                <td><?php echo $row['quantity_purchased'] ?? 0; ?></td>
                                <td><?php echo htmlspecialchars($row['price_type'] ?? ''); ?></td>
                                <td><span class="amount-positive">€<?php echo number_format($row['price_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo htmlspecialchars($row['item_related_fee_type'] ?? ''); ?></td>
                                <td><span class="amount-negative">€<?php echo number_format($row['item_related_fee_amount'] ?? 0, 2); ?></span></td>
                                <td><span class="amount-negative">€<?php echo number_format($row['misc_fee_amount'] ?? 0, 2); ?></span></td>
                                <td><span class="amount-negative">€<?php echo number_format($row['other_fee_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo htmlspecialchars(substr($row['other_fee_reason_description'] ?? '', 0, 30)); ?></td>
                                <td><?php echo htmlspecialchars($row['promotion_id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['promotion_type'] ?? ''); ?></td>
                                <td><span class="amount-positive">€<?php echo number_format($row['promotion_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo htmlspecialchars($row['direct_payment_type'] ?? ''); ?></td>
                                <td><span class="amount-positive">€<?php echo number_format($row['direct_payment_amount'] ?? 0, 2); ?></span></td>
                                <td><span class="amount-neutral">€<?php echo number_format($row['other_amount'] ?? 0, 2); ?></span></td>
                                <td><?php echo $row['product_id'] ?? '<span style="color: #dc3545;">NULL</span>'; ?></td>
                                <td><?php echo $row['date_uploaded'] ?? ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginazione -->
            <?php if ($limit !== 'all' && $totalRecords > intval($limit)): ?>
                <?php
                $totalPages = ceil($totalRecords / intval($limit));
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="page-btn">Prima</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-btn">Precedente</a>
                    <?php endif; ?>
                    
                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                           class="page-btn <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-btn">Successiva</a>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="page-btn">Ultima</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($selectedUserId): ?>
            <div style="text-align: center; padding: 3rem; color: #6b7280;">
                <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <p><strong>Nessun dato trovato</strong></p>
                <p>Prova a modificare i filtri o verifica che i dati siano presenti.</p>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 3rem; color: #6b7280;">
                <i class="fas fa-user-circle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <p><strong>Seleziona un utente per iniziare</strong></p>
                <p>Usa il filtro "User ID" per esplorare i dati settlement.</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.querySelectorAll('select[name="user_id"], select[name="limit"]').forEach(select => {
            select.addEventListener('change', () => {
                document.getElementById('filtersForm').submit();
            });
        });
    </script>
</body>
</html>