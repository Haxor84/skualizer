<?php
/**
 * Gestione Utenti Admin - VERSIONE PROFESSIONALE
 * File: admin/admin_utenti.php
 * 
 * Interfaccia moderna per gestione completa utenti Margynomic
 */

require_once 'admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

$message = '';
$messageType = 'success';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = intval($_POST['user_id'] ?? 0);
    
    try {
        $pdo = getAdminDbConnection();
        
        switch ($action) {
            case 'toggle_status':
                if ($userId > 0) {
                    $stmt = $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$userId]);
                    
                    $stmt = $pdo->prepare("SELECT is_active FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $newStatus = $stmt->fetchColumn();
                    
                    $message = $newStatus ? 'Utente attivato con successo' : 'Utente disattivato con successo';
                }
                break;
                
            case 'delete_user':
                if ($userId > 0) {
                    // Prima elimina dati correlati
                    $pdo->beginTransaction();
                    
                    // Elimina token Amazon
                    $stmt = $pdo->prepare("DELETE FROM amazon_client_tokens WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    
                    // Elimina dati inventory
                    $stmt = $pdo->prepare("DELETE FROM inventory WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    
                    // Elimina prodotti
                    $stmt = $pdo->prepare("DELETE FROM products WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    
                    // Elimina utente
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    
                    $pdo->commit();
                    $message = 'Utente e tutti i dati correlati eliminati con successo';
                }
                break;
                
            case 'create_settlement_table':
                if ($userId > 0) {
                    $tableName = "report_settlement_{$userId}";
                    
                    // Controlla se esiste già
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM information_schema.tables 
                        WHERE table_schema = DATABASE() 
                        AND table_name = ?
                    ");
                    $stmt->execute([$tableName]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        // Crea tabella copiando da template
                        $sql = "CREATE TABLE `{$tableName}` LIKE `report_settlement_1`";
                        $pdo->exec($sql);
                        
                        $message = "Tabella settlement creata per utente {$userId}";
                    } else {
                        $message = "Tabella settlement già esistente per utente {$userId}";
                        $messageType = 'warning';
                    }
                }
                break;
                
            case 'reset_password':
                if ($userId > 0) {
                    $newPassword = 'temp' . rand(1000, 9999);
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $userId]);
                    
                    $message = "Password resettata: {$newPassword} (comunicala all'utente)";
                }
                break;
                
            case 'bulk_create_tables':
                $stmt = $pdo->query("SELECT id FROM users WHERE is_active = 1");
                $users = $stmt->fetchAll();
                $created = 0;
                
                foreach ($users as $user) {
                    $tableName = "report_settlement_{$user['id']}";
                    
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM information_schema.tables 
                        WHERE table_schema = DATABASE() 
                        AND table_name = ?
                    ");
                    $stmt->execute([$tableName]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        $sql = "CREATE TABLE `{$tableName}` LIKE `report_settlement_1`";
                        $pdo->exec($sql);
                        $created++;
                    }
                }
                
                $message = "Create {$created} nuove tabelle settlement";
                break;
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = 'Errore: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Carica dati utenti con statistiche dettagliate
try {
    $pdo = getAdminDbConnection();
    
    // Query completa per utenti con tutte le statistiche
    $stmt = $pdo->query("
        SELECT u.id, u.nome, u.email, u.is_active, u.creato_il, u.last_login, u.role,
               COALESCE(settlement_stats.settlement_count, 0) as settlement_count,
               COALESCE(product_stats.product_count, 0) as product_count,
               COALESCE(inventory_stats.inventory_count, 0) as inventory_count,
               COALESCE(token_stats.has_token, 0) as has_amazon_token,
               settlement_stats.last_settlement_date,
               settlement_stats.total_amount
        FROM users u
        LEFT JOIN (
            SELECT 2 as user_id, COUNT(*) as settlement_count, 
                   MAX(posted_date) as last_settlement_date,
                   SUM(CASE WHEN transaction_type = 'Order' AND price_amount > 0 THEN price_amount ELSE 0 END) as total_amount
            FROM report_settlement_2 
            UNION ALL
            SELECT 7 as user_id, COUNT(*) as settlement_count,
                   MAX(posted_date) as last_settlement_date,
                   SUM(CASE WHEN transaction_type = 'Order' AND price_amount > 0 THEN price_amount ELSE 0 END) as total_amount
            FROM report_settlement_7
            UNION ALL
            SELECT 8 as user_id, COUNT(*) as settlement_count,
                   MAX(posted_date) as last_settlement_date,
                   SUM(CASE WHEN transaction_type = 'Order' AND price_amount > 0 THEN price_amount ELSE 0 END) as total_amount
            FROM report_settlement_8
            UNION ALL
            SELECT 9 as user_id, COUNT(*) as settlement_count,
                   MAX(posted_date) as last_settlement_date,
                   SUM(CASE WHEN transaction_type = 'Order' AND price_amount > 0 THEN price_amount ELSE 0 END) as total_amount
            FROM report_settlement_9
            UNION ALL
            SELECT 10 as user_id, COUNT(*) as settlement_count,
                   MAX(posted_date) as last_settlement_date,
                   SUM(CASE WHEN transaction_type = 'Order' AND price_amount > 0 THEN price_amount ELSE 0 END) as total_amount
            FROM report_settlement_10
        ) settlement_stats ON u.id = settlement_stats.user_id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as product_count
            FROM products 
            GROUP BY user_id
        ) product_stats ON u.id = product_stats.user_id
        LEFT JOIN (
            SELECT user_id, COUNT(*) as inventory_count
            FROM inventory 
            GROUP BY user_id
        ) inventory_stats ON u.id = inventory_stats.user_id
        LEFT JOIN (
            SELECT user_id, 1 as has_token
            FROM amazon_client_tokens 
            WHERE is_active = 1
            GROUP BY user_id
        ) token_stats ON u.id = token_stats.user_id
        ORDER BY u.creato_il DESC
    ");
    $users = $stmt->fetchAll();
    
    // Statistiche generali
    $totalUsers = count($users);
    $activeUsers = count(array_filter($users, fn($u) => $u['is_active']));
    $syncedUsers = count(array_filter($users, fn($u) => $u['settlement_count'] > 0));
    $connectedUsers = count(array_filter($users, fn($u) => $u['has_amazon_token']));
    
} catch (Exception $e) {
    $users = [];
    $totalUsers = $activeUsers = $syncedUsers = $connectedUsers = 0;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Utenti - Admin Margynomic</title>
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
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .dashboard-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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
            margin-bottom: 24px;
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
        
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .user-card {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .user-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
        }
        
        .user-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
        }
        
        .user-info h3 {
            margin: 0 0 4px 0;
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .user-email {
            color: #666;
            font-size: 14px;
        }
        
        .user-status {
            position: absolute;
            top: 16px;
            right: 16px;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .status-admin { background: #fff3cd; color: #856404; }
        
        .user-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 16px 0;
        }
        
        .user-stat {
            background: white;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e9ecef;
        }
        
        .user-stat-number {
            font-size: 18px;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 4px;
        }
        
        .user-stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .user-actions {
            display: flex;
            gap: 8px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a6fd8; }
        .btn-success { background: #28a745; color: white; }
        .btn-success:hover { background: #218838; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-warning:hover { background: #e0a800; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        
        .bulk-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            border-left: 4px solid;
        }
        
        .alert-success { 
            background: #d4edda; 
            color: #155724; 
            border-left-color: #28a745; 
        }
        
        .alert-error { 
            background: #f8d7da; 
            color: #721c24; 
            border-left-color: #dc3545; 
        }
        
        .alert-warning { 
            background: #fff3cd; 
            color: #856404; 
            border-left-color: #ffc107; 
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-icon {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 16px;
        }
        
        .user-meta {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e9ecef;
            font-size: 12px;
            color: #666;
        }
        
        .connection-indicator {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            margin-top: 8px;
        }
        
        .connection-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .connected { background: #28a745; }
        .disconnected { background: #dc3545; }
        
        @media (max-width: 768px) {
            .main-container { padding: 15px; }
            .users-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-header { padding: 20px; }
            .page-title { font-size: 24px; }
            .user-stats { grid-template-columns: 1fr; }
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 24px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
    </style>
</head>
<body>

<?php echo getAdminNavigation('utenti'); ?>

<div class="main-container">
    <!-- Header Dashboard -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">
            <i class="fas fa-users"></i>
            Gestione Utenti
        </h1>
        <p class="dashboard-subtitle">
            Amministrazione completa utenti e sincronizzazioni Amazon
        </p>
    </div>

    <!-- Messaggi -->
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : ($messageType === 'error' ? 'exclamation-triangle' : 'info-circle'); ?>"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Statistiche Utenti -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #667eea;">
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="stat-number"><?php echo $totalUsers; ?></div>
            <div class="stat-label">Utenti Totali</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #28a745;">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
            <div class="stat-number"><?php echo $activeUsers; ?></div>
            <div class="stat-label">Utenti Attivi</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #17a2b8;">
                    <i class="fas fa-sync"></i>
                </div>
            </div>
            <div class="stat-number"><?php echo $syncedUsers; ?></div>
            <div class="stat-label">Sincronizzati</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #ffc107;">
                    <i class="fas fa-link"></i>
                </div>
            </div>
            <div class="stat-number"><?php echo $connectedUsers; ?></div>
            <div class="stat-label">Connessi Amazon</div>
        </div>
    </div>

    <!-- Azioni Bulk -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-tools"></i>
                Azioni Sistema
            </h2>
        </div>
        
        <div class="bulk-actions">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="action" value="bulk_create_tables">
                <button type="submit" class="btn btn-primary" onclick="return confirm('Creare tabelle settlement per tutti gli utenti attivi?')">
                    <i class="fas fa-database"></i>
                    Crea Tabelle Settlement
                </button>
            </form>
            
            <a href="../../mapping/sku_aggregation_interface.php" class="btn btn-secondary">
                <i class="fas fa-project-diagram"></i>
                Gestione Mapping
            </a>
            
            <a href="../margini/admin_fee_mappings.php" class="btn btn-secondary">
                <i class="fas fa-tags"></i>
                Fee Mappings
            </a>
        </div>
    </div>

    <!-- Lista Utenti -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-list"></i>
                Lista Utenti
            </h2>
            <div style="color: #666; font-size: 14px;">
                <?php echo $totalUsers; ?> utenti totali
            </div>
        </div>
        
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3>Nessun utente registrato</h3>
                <p>Il sistema non ha ancora utenti registrati</p>
            </div>
        <?php else: ?>
            <div class="users-grid">
                <?php foreach ($users as $user): ?>
                    <div class="user-card">
                        <!-- Status Badge -->
                        <div class="user-status">
                            <?php if ($user['role'] === 'admin'): ?>
                                <span class="status-badge status-admin">
                                    <i class="fas fa-crown"></i> Admin
                                </span>
                            <?php elseif ($user['is_active']): ?>
                                <span class="status-badge status-active">
                                    <i class="fas fa-check"></i> Attivo
                                </span>
                            <?php else: ?>
                                <span class="status-badge status-inactive">
                                    <i class="fas fa-times"></i> Sospeso
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Header Utente -->
                        <div class="user-header">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['nome'], 0, 1)); ?>
                            </div>
                            <div class="user-info">
                                <h3><?php echo htmlspecialchars($user['nome']); ?></h3>
                                <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                <div class="connection-indicator">
                                    <div class="connection-dot <?php echo $user['has_amazon_token'] ? 'connected' : 'disconnected'; ?>"></div>
                                    <?php echo $user['has_amazon_token'] ? 'Amazon Connesso' : 'Amazon Disconnesso'; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Statistiche Utente -->
                        <div class="user-stats">
                            <div class="user-stat">
                                <div class="user-stat-number"><?php echo number_format($user['settlement_count']); ?></div>
                                <div class="user-stat-label">Settlement</div>
                            </div>
                            <div class="user-stat">
                                <div class="user-stat-number"><?php echo number_format($user['product_count']); ?></div>
                                <div class="user-stat-label">Prodotti</div>
                            </div>
                            <div class="user-stat">
                                <div class="user-stat-number"><?php echo number_format($user['inventory_count']); ?></div>
                                <div class="user-stat-label">Inventory</div>
                            </div>
                            <div class="user-stat">
                                <div class="user-stat-number">
                                    <?php if ($user['total_amount']): ?>
                                        €<?php echo number_format($user['total_amount'], 0); ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </div>
                                <div class="user-stat-label">Revenue</div>
                            </div>
                        </div>
                        
                        <!-- Meta Info -->
                        <div class="user-meta">
                            <div><strong>ID:</strong> <?php echo $user['id']; ?></div>
                            <div><strong>Registrato:</strong> <?php echo date('d/m/Y', strtotime($user['creato_il'])); ?></div>
                            <?php if ($user['last_login']): ?>
                                <div><strong>Ultimo login:</strong> <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?></div>
                            <?php endif; ?>
                            <?php if ($user['last_settlement_date']): ?>
                                <div><strong>Ultimo settlement:</strong> <?php echo date('d/m/Y', strtotime($user['last_settlement_date'])); ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Azioni -->
                        <div class="user-actions">
                            <?php if ($user['role'] !== 'admin'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn <?php echo $user['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <i class="fas fa-<?php echo $user['is_active'] ? 'pause' : 'play'; ?>"></i>
                                        <?php echo $user['is_active'] ? 'Sospendi' : 'Attiva'; ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="create_settlement_table">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-database"></i>
                                    Settlement Table
                                </button>
                            </form>
                            
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn btn-secondary" onclick="return confirm('Resettare la password per questo utente?')">
                                    <i class="fas fa-key"></i>
                                    Reset PWD
                                </button>
                            </form>
                            
                            <a href="../../mapping/sku_aggregation_interface.php?user_id=<?php echo $user['id']; ?>" class="btn btn-secondary">
                                <i class="fas fa-project-diagram"></i>
                                Mapping
                            </a>
                            
                            <?php if ($user['role'] !== 'admin' && $user['settlement_count'] == 0): ?>
                                <button onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['nome']); ?>')" class="btn btn-danger">
                                    <i class="fas fa-trash"></i>
                                    Elimina
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Conferma Eliminazione -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Conferma Eliminazione</h2>
            <span class="close" onclick="closeDeleteModal()">&times;</span>
        </div>
        <p>Sei sicuro di voler eliminare l'utente <strong id="deleteUserName"></strong>?</p>
        <p style="color: #dc3545; font-size: 14px;">
            <i class="fas fa-exclamation-triangle"></i>
            Questa azione eliminerà tutti i dati correlati (prodotti, inventory, token Amazon) e non può essere annullata.
        </p>
        <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px;">
            <button onclick="closeDeleteModal()" class="btn btn-secondary">Annulla</button>
            <form method="POST" style="display: inline;" id="deleteForm">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-trash"></i>
                    Elimina Definitivamente
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmDelete(userId, userName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteModal').style.display = 'block';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

// Chiudi modal cliccando fuori
window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        modal.style.display = 'none';
    }
}

// Auto-hide alert dopo 10 secondi
setTimeout(function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.remove();
        }, 500);
    });
}, 10000);
</script>

</body>
</html>