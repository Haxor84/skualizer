<?php
/**
 * Admin Fee User Overrides - Gestione Override Utente-Specifici
 * File: modules/margynomic/margini/admin_fee_user_overrides.php
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
$selectedUserId = intval($_GET['user_id'] ?? 0);

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo = getDbConnection();
        
        if ($action === 'save_user_override') {
            $userId = intval($_POST['user_id'] ?? 0);
            $transactionType = trim($_POST['transaction_type'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            if (!$userId || !$transactionType || !$category) {
                throw new Exception('Tutti i campi sono obbligatori');
            }
            
            if ($category === 'REMOVE_OVERRIDE') {
                // Rimuovi override
                $stmt = $pdo->prepare("
                    DELETE FROM transaction_fee_mappings 
                    WHERE transaction_type = ? AND user_id = ?
                ");
                $stmt->execute([$transactionType, $userId]);
                $success = "Override rimosso per utente {$userId}";
            } else {
                // Salva/aggiorna override
                $stmt = $pdo->prepare("
                    INSERT INTO transaction_fee_mappings 
                    (transaction_type, category, user_id, notes) 
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    category = VALUES(category),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$transactionType, $category, $userId, $notes]);
                $success = "Override salvato per utente {$userId}: {$transactionType} → {$category}";
            }
            
            logMarginsOperation("User override: User {$userId}, {$transactionType} → {$category}");
            
        } elseif ($action === 'bulk_copy') {
            $sourceUserId = intval($_POST['source_user_id'] ?? 0);
            $targetUserIds = $_POST['target_user_ids'] ?? [];
            
            if (!$sourceUserId || empty($targetUserIds)) {
                throw new Exception('Seleziona utente origine e almeno un utente destinazione');
            }
            
            $pdo->beginTransaction();
            
            // Ottieni override dell'utente origine
            $stmt = $pdo->prepare("
                SELECT transaction_type, category, notes 
                FROM transaction_fee_mappings 
                WHERE user_id = ? AND is_active = 1
            ");
            $stmt->execute([$sourceUserId]);
            $sourceOverrides = $stmt->fetchAll();
            
            if (empty($sourceOverrides)) {
                throw new Exception('Utente origine non ha override da copiare');
            }
            
            // Copia agli utenti target
            $insertStmt = $pdo->prepare("
                INSERT INTO transaction_fee_mappings 
                (transaction_type, category, user_id, notes) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                category = VALUES(category),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $copiedCount = 0;
            foreach ($targetUserIds as $targetUserId) {
                $targetUserId = intval($targetUserId);
                if ($targetUserId > 0 && $targetUserId !== $sourceUserId) {
                    foreach ($sourceOverrides as $override) {
                        $insertStmt->execute([
                            $override['transaction_type'],
                            $override['category'],
                            $targetUserId,
                            $override['notes'] . ' (Copiato da user ' . $sourceUserId . ')'
                        ]);
                        $copiedCount++;
                    }
                }
            }
            
            $pdo->commit();
            $success = "Copiati {$copiedCount} override a " . count($targetUserIds) . " utenti";
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        logMarginsOperation("Errore user override: " . $e->getMessage());
    }
}

// Carica dati per la pagina
try {
    $pdo = getDbConnection();
    
    // Lista utenti con settlement data
    $stmt = $pdo->query("SHOW TABLES LIKE 'report_settlement_%'");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $users = [];
    foreach ($tables as $table) {
        $userId = str_replace('report_settlement_', '', $table);
        
        $userStmt = $pdo->prepare("SELECT email, nome FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userInfo = $userStmt->fetch();
        
        if ($userInfo) {
            // Conta override esistenti
            $overrideStmt = $pdo->prepare("
                SELECT COUNT(*) FROM transaction_fee_mappings 
                WHERE user_id = ? AND is_active = 1
            ");
            $overrideStmt->execute([$userId]);
            $overrideCount = $overrideStmt->fetchColumn();
            
            $users[] = [
                'id' => $userId,
                'email' => $userInfo['email'],
                'nome' => $userInfo['nome'],
                'override_count' => $overrideCount
            ];
        }
    }
    
    // Carica categorie disponibili
    $stmt = $pdo->query("
        SELECT category_code, category_name, group_type 
        FROM fee_categories 
        WHERE is_active = 1 
        ORDER BY group_type, category_name
    ");
    $categories = $stmt->fetchAll();
    
    // Se utente selezionato, carica i suoi dati
    $userOverrides = [];
    $userTransactionTypes = [];
    $userDetails = null;
    
    if ($selectedUserId) {
        // Info utente
        $stmt = $pdo->prepare("SELECT email, nome FROM users WHERE id = ?");
        $stmt->execute([$selectedUserId]);
        $userDetails = $stmt->fetch();
        
        if ($userDetails) {
            // Override esistenti
            $stmt = $pdo->prepare("
                SELECT tfm.transaction_type, tfm.category, tfm.notes, tfm.updated_at,
                       fc.category_name, fc.group_type
                FROM transaction_fee_mappings tfm
                LEFT JOIN fee_categories fc ON tfm.category = fc.category_code
                WHERE tfm.user_id = ? AND tfm.is_active = 1
                ORDER BY tfm.updated_at DESC
            ");
            $stmt->execute([$selectedUserId]);
            $userOverrides = $stmt->fetchAll();
            
            // Transaction types dell'utente con confronto globale
            $tableName = "report_settlement_{$selectedUserId}";
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        s.transaction_type,
                        COUNT(*) as occurrences,
                        SUM(ABS(COALESCE(s.total_amount, 0))) as impact,
                        tfm_global.category as global_category,
                        tfm_user.category as user_category
                    FROM `{$tableName}` s
                    LEFT JOIN transaction_fee_mappings tfm_global ON s.transaction_type = tfm_global.transaction_type 
                        AND tfm_global.user_id IS NULL AND tfm_global.is_active = 1
                    LEFT JOIN transaction_fee_mappings tfm_user ON s.transaction_type = tfm_user.transaction_type 
                        AND tfm_user.user_id = ? AND tfm_user.is_active = 1
                    WHERE s.transaction_type IS NOT NULL
                    GROUP BY s.transaction_type
                    ORDER BY impact DESC
                    LIMIT 100
                ");
                $stmt->execute([$selectedUserId]);
                $userTransactionTypes = $stmt->fetchAll();
            } catch (Exception $e) {
                // Tabella settlement non esiste
                $userTransactionTypes = [];
            }
        }
    }
    
} catch (Exception $e) {
    $error = "Errore caricamento dati: " . $e->getMessage();
    $users = [];
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Override Utenti - Margynomic</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 1400px; margin: 2rem auto; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .btn-admin { background: #667eea; border: none; border-radius: 8px; }
        .override-row { border-bottom: 1px solid #eee; padding: 0.75rem 0; }
        .different { background: #fff3cd; }
        .same { background: #d1ecf1; }
        .badge-override { background: #dc3545; color: white; }
        .badge-global { background: #28a745; color: white; }
    </style>
</head>
<body>
<?php echo getAdminNavigation('fee_overrides'); ?>
    <div class="container">
        <!-- Header -->
        <div class="text-center text-white mb-4">
            <h1><i class="fas fa-user-cog"></i> Override Utente-Specifici</h1>
            <p>Gestione eccezioni personalizzate per singoli utenti</p>
        </div>
        
        <!-- Navigation -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex gap-3 justify-content-center">
                    <a href="admin_fee_dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="admin_fee_mappings.php" class="btn btn-primary">
                        <i class="fas fa-tags"></i> Mapping Globali
                    </a>
                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#bulkCopyModal">
                        <i class="fas fa-copy"></i> Copia Bulk
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
        
        <!-- User Selection -->
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-users"></i> Selezione Utente</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row align-items-end">
                    <div class="col-md-8">
                        <label class="form-label">Seleziona Utente</label>
                        <select name="user_id" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Seleziona un utente --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['email']); ?> 
                                    (<?php echo htmlspecialchars($user['nome']); ?>) 
                                    - <?php echo $user['override_count']; ?> override
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Carica Dati
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if ($selectedUserId && $userDetails): ?>
            <!-- User Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-user"></i> Dettagli Utente: <?php echo htmlspecialchars($userDetails['email']); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Nome:</strong> <?php echo htmlspecialchars($userDetails['nome']); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Override Attivi:</strong> <?php echo count($userOverrides); ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Transaction Types:</strong> <?php echo count($userTransactionTypes); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Current Overrides -->
            <?php if (!empty($userOverrides)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-list"></i> Override Esistenti</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Transaction Type</th>
                                        <th>Categoria Override</th>
                                        <th>Gruppo</th>
                                        <th>Note</th>
                                        <th>Aggiornato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userOverrides as $override): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($override['transaction_type']); ?></code></td>
                                            <td>
                                                <span class="badge badge-override">
                                                    <?php echo htmlspecialchars($override['category_name'] ?: $override['category']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $override['group_type']; ?></td>
                                            <td><?php echo htmlspecialchars($override['notes']); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($override['updated_at'])); ?></td>
                                            <td>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Rimuovere questo override?')">
                                                    <input type="hidden" name="action" value="save_user_override">
                                                    <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                                                    <input type="hidden" name="transaction_type" value="<?php echo htmlspecialchars($override['transaction_type']); ?>">
                                                    <input type="hidden" name="category" value="REMOVE_OVERRIDE">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Add New Override -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-plus"></i> Nuovo Override</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_user_override">
                        <input type="hidden" name="user_id" value="<?php echo $selectedUserId; ?>">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">Transaction Type</label>
                                <select name="transaction_type" class="form-select" required>
                                    <option value="">-- Seleziona transaction type --</option>
                                    <?php foreach ($userTransactionTypes as $type): ?>
                                        <option value="<?php echo htmlspecialchars($type['transaction_type']); ?>">
                                            <?php echo htmlspecialchars($type['transaction_type']); ?> 
                                            (€<?php echo number_format($type['impact'], 0); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nuova Categoria</label>
                                <select name="category" class="form-select" required>
                                    <option value="">-- Seleziona categoria --</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category['category_code']); ?>">
                                            <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['group_type']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Note</label>
                                <input type="text" name="notes" class="form-control" placeholder="Motivo del override...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-success d-block w-100">
                                    <i class="fas fa-save"></i> Salva
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Comparison Table -->
            <?php if (!empty($userTransactionTypes)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-balance-scale"></i> Confronto Mapping Globale vs Utente</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Transaction Type</th>
                                        <th>Occorrenze</th>
                                        <th>Impatto</th>
                                        <th>Mapping Globale</th>
                                        <th>Override Utente</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userTransactionTypes as $type): ?>
                                        <?php 
                                        $hasOverride = !empty($type['user_category']);
                                        $isDifferent = $type['global_category'] !== $type['user_category'];
                                        ?>
                                        <tr class="<?php echo $hasOverride ? ($isDifferent ? 'different' : 'same') : ''; ?>">
                                            <td><code><?php echo htmlspecialchars($type['transaction_type']); ?></code></td>
                                            <td><?php echo number_format($type['occurrences']); ?></td>
                                            <td>€<?php echo number_format($type['impact'], 2); ?></td>
                                            <td>
                                                <?php if ($type['global_category']): ?>
                                                    <span class="badge badge-global"><?php echo htmlspecialchars($type['global_category']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Non mappato</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($type['user_category']): ?>
                                                    <span class="badge badge-override"><?php echo htmlspecialchars($type['user_category']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Usa globale</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($hasOverride): ?>
                                                    <?php if ($isDifferent): ?>
                                                        <i class="fas fa-exclamation-triangle text-warning" title="Override diverso"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-equals text-info" title="Override uguale al globale"></i>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <i class="fas fa-globe text-success" title="Usa mapping globale"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Modal Bulk Copy -->
    <div class="modal fade" id="bulkCopyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-copy"></i> Copia Override Bulk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="bulk_copy">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Copia Override Da:</label>
                            <select name="source_user_id" class="form-select" required>
                                <option value="">-- Seleziona utente origine --</option>
                                <?php foreach ($users as $user): ?>
                                    <?php if ($user['override_count'] > 0): ?>
                                        <option value="<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['email']); ?> 
                                            (<?php echo $user['override_count']; ?> override)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Copia Override A:</label>
                            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #dee2e6; padding: 0.5rem;">
                                <?php foreach ($users as $user): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="target_user_ids[]" 
                                               value="<?php echo $user['id']; ?>" id="target_<?php echo $user['id']; ?>">
                                        <label class="form-check-label" for="target_<?php echo $user['id']; ?>">
                                            <?php echo htmlspecialchars($user['email']); ?> 
                                            (<?php echo htmlspecialchars($user['nome']); ?>)
                                            <?php if ($user['override_count'] > 0): ?>
                                                <small class="text-warning">- Ha già <?php echo $user['override_count']; ?> override</small>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">
                                Gli override esistenti negli utenti target verranno sovrascritti
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-copy"></i> Copia Override
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Select/Deselect all checkboxes
        function toggleAllTargets(selectAll) {
            document.querySelectorAll('input[name="target_user_ids[]"]').forEach(checkbox => {
                checkbox.checked = selectAll;
            });
        }
        
        // Add select all/none buttons
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxContainer = document.querySelector('div[style*="max-height: 200px"]');
            if (checkboxContainer) {
                const controls = document.createElement('div');
                controls.className = 'mb-2';
                controls.innerHTML = `
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleAllTargets(true)">
                        Seleziona Tutti
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="toggleAllTargets(false)">
                        Deseleziona Tutti
                    </button>
                `;
                checkboxContainer.parentNode.insertBefore(controls, checkboxContainer);
            }
        });
        
        // Form validation
        document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
            const action = this.querySelector('input[name="action"]').value;
            
            if (action === 'bulk_copy') {
                const sourceUser = this.querySelector('select[name="source_user_id"]').value;
                const targetUsers = this.querySelectorAll('input[name="target_user_ids[]"]:checked');
                
                if (!sourceUser) {
                    e.preventDefault();
                    alert('Seleziona un utente origine');
                    return;
                }
                
                if (targetUsers.length === 0) {
                    e.preventDefault();
                    alert('Seleziona almeno un utente destinazione');
                    return;
                }
                
                if (!confirm(`Copiare gli override a ${targetUsers.length} utenti? Gli override esistenti verranno sovrascritti.`)) {
                    e.preventDefault();
                }
            }
        });
    </script>
</body>
</html>