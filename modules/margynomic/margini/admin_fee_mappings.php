<?php
/**
 * Admin Fee Mappings - Gestione Categorie e Mappings
 * File: modules/margynomic/margini/admin_fee_mappings.php
 */

require_once 'config_shared.php';
require_once dirname(__DIR__) . '/admin/admin_helpers.php';

// Verifica autenticazione admin
if (!isAdminLogged()) {
    header('Location: ../admin/admin_login.php');
    exit();
}

$success = '';
$error = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo = getDbConnection();
        
        if ($action === 'create_category') {
            $categoryCode = strtoupper(trim($_POST['category_code'] ?? ''));
            $categoryName = trim($_POST['category_name'] ?? '');
            $groupType = $_POST['group_type'] ?? 'TAB2';
            $description = trim($_POST['description'] ?? '');
            
            if (empty($categoryCode) || empty($categoryName)) {
                throw new Exception('Codice e nome categoria sono obbligatori');
            }
            
            // Verifica unicità
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fee_categories WHERE category_code = ?");
            $stmt->execute([$categoryCode]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception('Codice categoria già esistente');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO fee_categories (category_code, category_name, group_type, description, sort_order) 
                VALUES (?, ?, ?, ?, (SELECT COALESCE(MAX(sort_order), 0) + 1 FROM fee_categories fc2))
            ");
            $stmt->execute([$categoryCode, $categoryName, $groupType, $description]);
            
            $success = "Categoria '{$categoryName}' creata con successo";
            logMarginsOperation("Nuova categoria creata: {$categoryCode}");
            
        } elseif ($action === 'update_category') {
            $categoryId = intval($_POST['category_id']);
            $categoryName = trim($_POST['category_name'] ?? '');
            $groupType = $_POST['group_type'] ?? 'TAB2';
            $description = trim($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            $stmt = $pdo->prepare("
                UPDATE fee_categories 
                SET category_name = ?, group_type = ?, description = ?, is_active = ?
                WHERE id = ?
            ");
            $stmt->execute([$categoryName, $groupType, $description, $isActive, $categoryId]);
            
            $success = "Categoria aggiornata con successo";
            
        } elseif ($action === 'save_mapping') {
    $transactionType = trim($_POST['transaction_type'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    if (empty($transactionType) || empty($category)) {
        throw new Exception('Transaction type e categoria sono obbligatori');
    }
    
    // Prima elimina eventuali mapping esistenti per questo transaction_type (globali)
    $stmtDelete = $pdo->prepare("
        DELETE FROM transaction_fee_mappings 
        WHERE transaction_type = ? AND user_id IS NULL
    ");
    $stmtDelete->execute([$transactionType]);
    
    // Poi inserisci il nuovo mapping
    $stmt = $pdo->prepare("
        INSERT INTO transaction_fee_mappings (transaction_type, category, user_id, notes) 
        VALUES (?, ?, NULL, ?)
    ");
    $stmt->execute([$transactionType, $category, $notes]);
    
    $success = "Mapping salvato: {$transactionType} → {$category}";
            
        } elseif ($action === 'bulk_mapping') {
    $mappings = $_POST['mappings'] ?? [];
    $count = 0;
    
    $pdo->beginTransaction();
    
    $stmtDelete = $pdo->prepare("
        DELETE FROM transaction_fee_mappings 
        WHERE transaction_type = ? AND user_id IS NULL
    ");
    
    $stmt = $pdo->prepare("
        INSERT INTO transaction_fee_mappings (transaction_type, category, user_id, notes) 
        VALUES (?, ?, NULL, 'Bulk mapping')
    ");
            
            foreach ($mappings as $transactionType => $category) {
                if (!empty($category) && $category !== 'UNCHANGED') {
                    $stmt->execute([$transactionType, $category]);
                    $count++;
                }
            }
            
            $pdo->commit();
            $success = "Salvati {$count} mappings in bulk";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        logMarginsOperation("Errore admin mappings: " . $e->getMessage());
    }
}

// Carica dati per la pagina
try {
    $pdo = getDbConnection();
    
    // Carica categorie
    // Carica categorie con transaction types associati
$stmt = $pdo->query("
    SELECT fc.*, 
           GROUP_CONCAT(tfm.transaction_type ORDER BY tfm.transaction_type SEPARATOR ' - ') as mapped_types
    FROM fee_categories fc
    LEFT JOIN transaction_fee_mappings tfm ON fc.category_code = tfm.category 
        AND tfm.user_id IS NULL AND tfm.is_active = 1
    WHERE fc.is_active = 1
    GROUP BY fc.id
    ORDER BY fc.group_type, fc.sort_order, fc.category_name
");
$categories = $stmt->fetchAll();
    
    // Carica transaction types con mapping status
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    
    // Ottieni tutti i transaction types da settlement tables
    $allTransactionTypes = [];
    $settlementTables = ['report_settlement_1', 'report_settlement_2', 'report_settlement_7', 'report_settlement_8', 'report_settlement_9', 'report_settlement_10'];
    
    foreach ($settlementTables as $table) {
        try {
            $stmt = $pdo->query("
                SELECT DISTINCT transaction_type, COUNT(*) as occurrences
                FROM `{$table}` 
                WHERE transaction_type IS NOT NULL AND transaction_type != ''
                GROUP BY transaction_type
            ");
            $results = $stmt->fetchAll();
            
            foreach ($results as $row) {
                if (!isset($allTransactionTypes[$row['transaction_type']])) {
                    $allTransactionTypes[$row['transaction_type']] = 0;
                }
                $allTransactionTypes[$row['transaction_type']] += $row['occurrences'];
            }
        } catch (Exception $e) {
            // Salta tabelle che non esistono
            continue;
        }
    }
    
    // Ottieni mappings esistenti
    $stmt = $pdo->query("
        SELECT transaction_type, category, notes, updated_at
        FROM transaction_fee_mappings 
        WHERE user_id IS NULL AND is_active = 1
    ");
    $existingMappings = [];
    while ($row = $stmt->fetch()) {
        $existingMappings[$row['transaction_type']] = $row;
    }
    
    // Filtra transaction types
    $filteredTypes = [];
    foreach ($allTransactionTypes as $transactionType => $occurrences) {
        $isMapped = isset($existingMappings[$transactionType]);
        $matchesSearch = empty($search) || stripos($transactionType, $search) !== false;
        
        $include = false;
        switch ($filter) {
            case 'mapped':
                $include = $isMapped && $matchesSearch;
                break;
            case 'unmapped':
                $include = !$isMapped && $matchesSearch;
                break;
            default:
                $include = $matchesSearch;
        }
        
        if ($include) {
            $filteredTypes[$transactionType] = [
                'transaction_type' => $transactionType,
                'occurrences' => $occurrences,
                'is_mapped' => $isMapped,
                'current_mapping' => $existingMappings[$transactionType] ?? null
            ];
        }
    }
    
    // Ordina per occorrenze
    uasort($filteredTypes, function($a, $b) {
        return $b['occurrences'] <=> $a['occurrences'];
    });
    
} catch (Exception $e) {
    $error = "Errore caricamento dati: " . $e->getMessage();
    $categories = [];
    $filteredTypes = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Fee Mappings - Margynomic</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 1400px; margin: 2rem auto; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .btn-admin { background: #667eea; border: none; border-radius: 8px; }
        .category-badge { padding: 0.25rem 0.5rem; border-radius: 6px; font-size: 0.75rem; }
        .badge-tab1 { background: #e3f2fd; color: #1976d2; }
        .badge-tab2 { background: #f3e5f5; color: #7b1fa2; }
        .badge-tab3 { background: #fff3e0; color: #f57c00; }
        .badge-ignore { background: #f5f5f5; color: #757575; }
        .mapping-row { border-bottom: 1px solid #eee; padding: 0.75rem 0; }
        .mapped { background: #f0f9ff; }
        .unmapped { background: #fefce8; }
    </style>
</head>
<body>
<?php echo getAdminNavigation('fee_mappings'); ?>
    <div class="container">
        <!-- Header -->
        <div class="text-center text-white mb-4">
            <h1><i class="fas fa-tags"></i> Gestione Fee Mappings</h1>
            <p>Categorie e assegnazione transaction types</p>
        </div>
        
        <!-- Navigation -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex gap-3 justify-content-center">
                    <a href="admin_fee_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="admin_fee_user_overrides.php" class="btn btn-warning">
                        <i class="fas fa-user-cog"></i> Override Utenti
                    </a>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newCategoryModal">
                        <i class="fas fa-plus"></i> Nuova Categoria
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Categories Management -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-layer-group"></i> Categorie Esistenti</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($categories as $category): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="card-title"><?php echo htmlspecialchars($category['category_name']); ?></h6>
                                            <code class="text-muted"><?php echo htmlspecialchars($category['category_code']); ?></code>
                                            <br>
                                            <span class="category-badge badge-<?php echo strtolower($category['group_type']); ?>">
                                                <?php echo $category['group_type']; ?>
                                            </span>
                                        </div>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="#" onclick="editCategory(<?php echo $category['id']; ?>)">
                                                    <i class="fas fa-edit"></i> Modifica
                                                </a></li>
                                                <li><a class="dropdown-item" href="#" onclick="toggleCategory(<?php echo $category['id']; ?>, <?php echo $category['is_active']; ?>)">
                                                    <i class="fas fa-<?php echo $category['is_active'] ? 'eye-slash' : 'eye'; ?>"></i> 
                                                    <?php echo $category['is_active'] ? 'Disattiva' : 'Attiva'; ?>
                                                </a></li>
                                            </ul>
                                        </div>
                                    </div>
                                    <?php if ($category['mapped_types']): ?>
    <div class="mt-2">
        <small class="text-muted d-block mb-1">Transaction Types:</small>
        <div style="font-size: 0.7rem; color: #666; max-height: 60px; overflow-y: auto;">
            <?php 
            $types = explode(' - ', $category['mapped_types']);
            foreach ($types as $type): 
                if (trim($type)):
            ?>
                <span class="badge bg-light text-dark me-1 mb-1" style="font-size: 0.6rem;">
                    <?php echo htmlspecialchars(trim($type)); ?>
                </span>
            <?php 
                endif;
            endforeach; 
            ?>
        </div>
    </div>
<?php else: ?>
    <p class="card-text small text-muted mt-2">Nessun transaction type assegnato</p>
<?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Transaction Types Mapping -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-exchange-alt"></i> Mappatura Transaction Types</h5>
                    <div class="d-flex gap-2">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="filter" id="filter-all" value="all" <?php echo $filter === 'all' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="filter-all">Tutti</label>
                            
                            <input type="radio" class="btn-check" name="filter" id="filter-mapped" value="mapped" <?php echo $filter === 'mapped' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-success" for="filter-mapped">Mappati</label>
                            
                            <input type="radio" class="btn-check" name="filter" id="filter-unmapped" value="unmapped" <?php echo $filter === 'unmapped' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-warning" for="filter-unmapped">Non Mappati</label>
                        </div>
                    </div>
                </div>
                
                <!-- Search -->
                <div class="mt-3">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="text" name="search" class="form-control" placeholder="Cerca transaction type..." 
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="card-body">
                <?php if (empty($filteredTypes)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">Nessun transaction type trovato</p>
                    </div>
                <?php else: ?>
                    <form method="POST" id="bulkMappingForm">
                        <input type="hidden" name="action" value="bulk_mapping">
                        
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <strong><?php echo count($filteredTypes); ?></strong> transaction types trovati
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salva Mappings
                            </button>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Transaction Type</th>
                                        <th>Occorrenze</th>
                                        <th>Categoria Attuale</th>
                                        <th>Nuova Categoria</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filteredTypes as $type): ?>
                                        <tr class="<?php echo $type['is_mapped'] ? 'mapped' : 'unmapped'; ?>">
                                            <td>
                                                <code><?php echo htmlspecialchars($type['transaction_type']); ?></code>
                                                <?php if ($type['is_mapped']): ?>
                                                    <i class="fas fa-check-circle text-success ms-2" title="Mappato"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-exclamation-circle text-warning ms-2" title="Non mappato"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo number_format($type['occurrences']); ?></span>
                                            </td>
                                            <td>
                                                <?php if ($type['current_mapping']): ?>
                                                    <span class="badge bg-success">
                                                        <?php echo htmlspecialchars($type['current_mapping']['category']); ?>
                                                    </span>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($type['current_mapping']['notes']); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Non mappato</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <select name="mappings[<?php echo htmlspecialchars($type['transaction_type']); ?>]" class="form-select form-select-sm">
                                                    <option value="">-- Seleziona categoria --</option>
                                                    <option value="UNCHANGED">Mantieni attuale</option>
                                                    <?php foreach ($categories as $category): ?>
                                                        <?php if ($category['is_active']): ?>
                                                            <option value="<?php echo htmlspecialchars($category['category_code']); ?>">
                                                                <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['group_type']; ?>)
                                                            </option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" 
                                                       placeholder="Note opzionali..." maxlength="255">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nuova Categoria -->
    <div class="modal fade" id="newCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nuova Categoria</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_category">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Codice Categoria *</label>
                            <input type="text" name="category_code" class="form-control" required 
                                   placeholder="es. CUSTOM_FEE" pattern="[A-Z_]+" maxlength="50">
                            <div class="form-text">Solo lettere maiuscole e underscore</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nome Categoria *</label>
                            <input type="text" name="category_name" class="form-control" required 
                                   placeholder="es. Fee Personalizzate" maxlength="100">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Gruppo *</label>
                            <select name="group_type" class="form-select" required>
                                <option value="TAB1">TAB1 - Commissioni Dirette</option>
                                <option value="TAB2" selected>TAB2 - Costi Operativi</option>
                                <option value="TAB3">TAB3 - Compensi/Danni</option>
                                <option value="IGNORE">IGNORE - Ignorati</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrizione</label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Descrizione opzionale della categoria..." maxlength="500"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Crea Categoria
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filter handling
        document.querySelectorAll('input[name="filter"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const url = new URL(window.location);
                url.searchParams.set('filter', this.value);
                window.location.href = url.toString();
            });
        });
        
        // Bulk selection helpers
        function selectAllForCategory(categoryCode) {
            document.querySelectorAll('select[name^="mappings["]').forEach(select => {
                if (select.closest('tr').classList.contains('unmapped')) {
                    select.value = categoryCode;
                }
            });
        }
        
        // Form validation
        document.getElementById('bulkMappingForm').addEventListener('submit', function(e) {
            const selects = document.querySelectorAll('select[name^="mappings["]');
            let hasChanges = false;
            
            selects.forEach(select => {
                if (select.value && select.value !== 'UNCHANGED') {
                    hasChanges = true;
                }
            });
            
            if (!hasChanges) {
                e.preventDefault();
                alert('Seleziona almeno una categoria da assegnare');
            }
        });
    </script>
</body>
</html>