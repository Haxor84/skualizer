<?php
/**
 * Margynomic - Gestione FNSKU Admin
 * File: modules/margynomic/admin/admin_fnsku.php
 * 
 * Sistema funzionante per l'assegnazione FNSKU ai prodotti
 */

// Avvia sessione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Includi dipendenze
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

// Ottieni ID admin dalla sessione
$adminId = $_SESSION['admin_id'] ?? 0;
if (!$adminId) {
    header('Location: admin_login.php');
    exit;
}

// Connessione database
try {
    $db = getDbConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('Errore connessione database');
}

// Carica lista utenti
$availableUsers = [];
try {
    $stmt = $db->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY nome ASC");
    $availableUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $availableUsers = [];
}

// Selezione utente corrente
$selectedUserId = 0;
if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $selectedUserId = (int)$_GET['user_id'];
} elseif (!empty($availableUsers)) {
    $selectedUserId = (int)$availableUsers[0]['id'];
}

// Variabili per messaggi
$message = '';
$messageType = 'info';

// Gestione salvataggio POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignments']) && $selectedUserId) {
    try {
        $assignments = $_POST['assignments'] ?? [];
        $db->beginTransaction();
        
        $savedCount = 0;
        foreach ($assignments as $productId => $selectedSku) {
            $productId = (int)$productId;
            $selectedSku = trim((string)$selectedSku);
            
            if (!$productId) continue;
            
            if ($selectedSku === '') {
                // Rimuovi assegnazione
                $stmt = $db->prepare("UPDATE products SET sku = NULL, fnsku = NULL WHERE id = ? AND user_id = ?");
                $stmt->execute([$productId, $selectedUserId]);
            } else {
                // Ottieni FNSKU da inventory FBA o inventory_fbm
                $stmt = $db->prepare("
                    SELECT fnsku FROM inventory 
                    WHERE user_id = ? AND sku = ? LIMIT 1
                    
                    UNION
                    
                    SELECT NULL as fnsku FROM inventory_fbm 
                    WHERE user_id = ? AND seller_sku = ? LIMIT 1
                ");
                $stmt->execute([$selectedUserId, $selectedSku, $selectedUserId, $selectedSku]);
                $row = $stmt->fetch();
                $fnsku = $row['fnsku'] ?? null;
                
                // Aggiorna prodotto
                $stmt = $db->prepare("UPDATE products SET sku = ?, fnsku = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$selectedSku, $fnsku, $productId, $selectedUserId]);
            }
            $savedCount++;
        }
        
        $db->commit();
        $message = "Salvate {$savedCount} assegnazioni con successo";
        $messageType = 'success';
        
    } catch (Exception $e) {
        $db->rollBack();
        $message = 'Errore durante il salvataggio: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Carica dati per visualizzazione
$searchTerm = trim($_GET['search'] ?? '');
$showUnassigned = isset($_GET['unassigned']);

$products = [];
$unassignedCount = 0;

if ($selectedUserId) {
    try {
        // Carica assegnazioni esistenti
        $assignments = [];
        $stmt = $db->prepare("SELECT id, sku, fnsku FROM products WHERE user_id = ?");
        $stmt->execute([$selectedUserId]);
        while ($row = $stmt->fetch()) {
            $assignments[(int)$row['id']] = [
                'sku' => $row['sku'],
                'fnsku' => $row['fnsku']
            ];
        }
        
        // Carica prodotti dalla tabella products
                        $productNames = [];
                        $stmt = $db->prepare("SELECT id, nome FROM products WHERE user_id = ?");
                        $stmt->execute([$selectedUserId]);
                        while ($row = $stmt->fetch()) {
                            $productNames[(int)$row['id']] = $row['nome'];
                        }
                        
                        // Carica SKU da inventory (FBA), inventory_fbm (FBM) e products
                        $inventoryData = [];
                        $stmt = $db->prepare("
                            SELECT DISTINCT
                                product_id, 
                                sku, 
                                fnsku,
                                'FBA' as source
                            FROM inventory 
                            WHERE user_id = ? AND product_id IS NOT NULL AND product_id > 0
                            
                            UNION
                            
                            SELECT DISTINCT
                                product_id,
                                seller_sku as sku,
                                NULL as fnsku,
                                'FBM' as source
                            FROM inventory_fbm
                            WHERE user_id = ? AND product_id IS NOT NULL AND product_id > 0
                            
                            UNION
                            
                            SELECT DISTINCT
                                p.id as product_id,
                                p.sku,
                                p.fnsku,
                                'PRODUCTS' as source
                            FROM products p
                            WHERE p.user_id = ?
                              AND p.nome NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
                              AND NOT EXISTS (
                                  SELECT 1 FROM inventory i 
                                  WHERE i.product_id = p.id AND i.user_id = ?
                              )
                              AND NOT EXISTS (
                                  SELECT 1 FROM inventory_fbm ifbm 
                                  WHERE ifbm.product_id = p.id AND ifbm.user_id = ?
                              )
                            
                            ORDER BY sku ASC
                        ");
                        $stmt->execute([$selectedUserId, $selectedUserId, $selectedUserId, $selectedUserId, $selectedUserId]);
                        
                        while ($row = $stmt->fetch()) {
                            $pid = (int)$row['product_id'];
                            if (!isset($inventoryData[$pid])) {
                                $inventoryData[$pid] = [
                                    'product_name' => $productNames[$pid] ?? 'Prodotto senza nome',
                                    'skus' => []
                                ];
                            }
                            $inventoryData[$pid]['skus'][] = [
                                'sku' => $row['sku'],
                                'fnsku' => $row['fnsku'],
                                'source' => $row['source']
                            ];
                        }
        
        // Applica filtri
        foreach ($inventoryData as $pid => $data) {
            $productName = $data['product_name'] ?? '';
            
            // Filtro ricerca
            if ($searchTerm && stripos($productName, $searchTerm) === false) {
                continue;
            }
            
            // Verifica assegnazione
            $hasAssignment = !empty($assignments[$pid]['fnsku']);
            
            // Filtro non assegnati
            if ($showUnassigned && $hasAssignment) {
                continue;
            }
            
            if (!$hasAssignment) {
                $unassignedCount++;
            }
            
            $products[$pid] = [
                'name' => $productName,
                'skus' => $data['skus'],
                'current_sku' => $assignments[$pid]['sku'] ?? '',
                'current_fnsku' => $assignments[$pid]['fnsku'] ?? ''
            ];
        }
        
    } catch (Exception $e) {
        $message = 'Errore caricamento dati: ' . $e->getMessage();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione FNSKU - Margynomic Admin</title>
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
        
        .controls {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        select, input[type="text"] {
            padding: 8px 12px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: #fafbfc;
        }
        
        select:focus, input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            background: white;
        }
        
        select {
            min-width: 250px;
        }
        
        input[type="text"] {
            min-width: 200px;
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
        
        .btn-outline {
            background: transparent;
            border: 1px solid #667eea;
            color: #667eea;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
            border-left: 4px solid #667eea;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }
        
        .users-table td {
            font-size: 14px;
        }
            color: #495057;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .product-name {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .assignment-status {
            font-size: 0.85em;
            color: #6c757d;
        }
        
        .assignment-status.assigned {
            color: #28a745;
        }
        
        .assignment-status.unassigned {
            color: #dc3545;
        }
        
        .sku-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .sku-option {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .sku-option:hover {
            background: #e9ecef;
        }
        
        .sku-option.selected {
            background: #e3f2fd;
            border-color: #2196f3;
            color: #1976d2;
        }
        
        .sku-option input {
            margin-right: 6px;
        }
        
        .sku-info {
            display: flex;
            flex-direction: column;
        }
        
        .sku-code {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .fnsku-code {
            font-size: 0.8em;
            color: #6c757d;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #495057;
        }
        
        .form-actions {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            text-align: right;
        }
        
        @media (max-width: 768px) {
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            select, input[type="text"] {
                min-width: auto;
                width: 100%;
            }
            
            .stats {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }
            
            .stats-left {
                justify-content: space-between;
            }
            
            .sku-options {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php echo getAdminNavigation('fnsku'); ?>
    
    <div class="main-container">
        <!-- Header Dashboard -->
        <div class="dashboard-header">
            <h1 class="dashboard-title">
                <i class="fas fa-tags"></i>
                Gestione FNSKU
            </h1>
            <p class="dashboard-subtitle">
                Sistema di assegnazione FNSKU ai prodotti Margynomic
            </p>
        </div>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-search"></i>
                    Ricerca e Filtri
                </h2>
            </div>
            
            <form method="GET" class="controls">
                <select name="user_id" onchange="this.form.submit()" required>
                    <option value="">Seleziona utente...</option>
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['nome'] . ' (' . $user['email'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <input type="text" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Cerca prodotto...">
                
                <input type="hidden" name="unassigned" value="<?php echo $showUnassigned ? '1' : '0'; ?>">
                
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i>
                    Cerca
                </button>
            </form>
        </div>
        
        <!-- Messaggi -->
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($selectedUserId): ?>
            <!-- Statistiche -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($products); ?></div>
                    <div class="stat-label">Prodotti Trovati</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $unassignedCount; ?></div>
                    <div class="stat-label">Senza FNSKU</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($products) - $unassignedCount; ?></div>
                    <div class="stat-label">Con FNSKU</div>
                </div>
                <div class="stat-card" style="display: flex; align-items: center; justify-content: center;">
                    <?php if ($showUnassigned): ?>
                        <a href="?user_id=<?php echo $selectedUserId; ?>&search=<?php echo urlencode($searchTerm); ?>" class="btn btn-outline">
                            <i class="fas fa-list"></i>
                            Mostra tutti
                        </a>
                    <?php else: ?>
                        <a href="?user_id=<?php echo $selectedUserId; ?>&search=<?php echo urlencode($searchTerm); ?>&unassigned=1" class="btn btn-outline">
                            <i class="fas fa-filter"></i>
                            Solo senza FNSKU
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Tabella prodotti -->
            <?php if (empty($products)): ?>
                <div class="table-container">
                    <div class="empty-state">
                        <h3>Nessun prodotto trovato</h3>
                        <p>
                            <?php if ($searchTerm): ?>
                                Non ci sono prodotti che corrispondono alla ricerca "<?php echo htmlspecialchars($searchTerm); ?>".
                            <?php elseif ($showUnassigned): ?>
                                Tutti i prodotti hanno già un FNSKU assegnato.
                            <?php else: ?>
                                L'utente selezionato non ha prodotti nell'inventory.
                            <?php endif; ?>
                        </p>
                        <?php if ($searchTerm || $showUnassigned): ?>
                            <br>
                            <a href="?user_id=<?php echo $selectedUserId; ?>" class="btn btn-primary">Mostra tutti i prodotti</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="section">
                    <div class="section-header">
                        <h2 class="section-title">
                            <i class="fas fa-table"></i>
                            Assegnazione FNSKU
                        </h2>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="save_assignments" value="1">
                        
                        <table class="users-table">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Prodotto</th>
                                    <th>Assegnazione FNSKU</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $productId => $product): ?>
                                    <tr>
                                        <td>
                                            <div class="product-name">
                                                <?php echo htmlspecialchars($product['name']); ?>
                                            </div>
                                            <div class="assignment-status <?php echo $product['current_fnsku'] ? 'assigned' : 'unassigned'; ?>">
                                                <?php if ($product['current_fnsku']): ?>
                                                    ✅ Assegnato: <?php echo htmlspecialchars($product['current_sku'] . ' → ' . $product['current_fnsku']); ?>
                                                <?php else: ?>
                                                    ❌ Nessun FNSKU assegnato
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="sku-options">
                                                <?php foreach ($product['skus'] as $skuData): ?>
                                                    <?php 
                                                        $sku = $skuData['sku'];
                                                        $fnsku = $skuData['fnsku'];
                                                        $isSelected = ($product['current_sku'] === $sku);
                                                    ?>
                                                    <label class="sku-option <?php echo $isSelected ? 'selected' : ''; ?>">
                                                        <input type="radio" 
                                                               name="assignments[<?php echo $productId; ?>]" 
                                                               value="<?php echo htmlspecialchars($sku); ?>"
                                                               <?php echo $isSelected ? 'checked' : ''; ?>>
                                                        <div class="sku-info">
                                                            <div class="sku-code"><?php echo htmlspecialchars($sku); ?></div>
                                                            <div class="fnsku-code">
                                                                <?php if ($fnsku): ?>
                                                                    FNSKU: <?php echo htmlspecialchars($fnsku); ?>
                                                                <?php else: ?>
                                                                    <em>FNSKU mancante</em>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </label>
                                                <?php endforeach; ?>
                                                
                                                <!-- Opzione rimozione -->
                                                <label class="sku-option <?php echo empty($product['current_sku']) ? 'selected' : ''; ?>">
                                                    <input type="radio" 
                                                           name="assignments[<?php echo $productId; ?>]" 
                                                           value=""
                                                           <?php echo empty($product['current_sku']) ? 'checked' : ''; ?>>
                                                    <div class="sku-info">
                                                        <div class="sku-code">Nessuna assegnazione</div>
                                                        <div class="fnsku-code">Rimuovi FNSKU</div>
                                                    </div>
                                                </label>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                Salva Assegnazioni
                            </button>
                            <a href="admin_dashboard.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i>
                                Dashboard
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="section">
                <div class="empty-state">
                    <h3>Seleziona un utente</h3>
                    <p>Scegli un utente dal menu a tendina per gestire le assegnazioni FNSKU.</p>
                </div>
            </div>
        <?php endif; ?>
    </div> <!-- /main-container -->
    
    <script>
        // Evidenzia selezioni radio
        document.addEventListener('DOMContentLoaded', function() {
            const radioInputs = document.querySelectorAll('input[type="radio"]');
            
            radioInputs.forEach(radio => {
                radio.addEventListener('change', function() {
                    // Rimuovi selezione da gruppo
                    const groupRadios = document.querySelectorAll(`input[name="${this.name}"]`);
                    groupRadios.forEach(r => r.closest('.sku-option').classList.remove('selected'));
                    
                    // Aggiungi selezione corrente
                    this.closest('.sku-option').classList.add('selected');
                });
            });
            
            // Conferma salvataggio
            const form = document.querySelector('form[method="POST"]');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!confirm('Confermi di voler salvare le modifiche alle assegnazioni FNSKU?')) {
                        e.preventDefault();
                    }
                });
            }
        });
    </script>
</body>
</html>