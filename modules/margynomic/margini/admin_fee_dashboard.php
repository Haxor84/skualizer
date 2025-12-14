<?php
/**
 * Admin Fee Dashboard - Overview Sistema Fee Integrato
 * File: modules/margynomic/margini/admin_fee_dashboard.php
 */

require_once 'config_shared.php';
require_once dirname(__DIR__) . '/admin/admin_helpers.php';

// Verifica autenticazione admin
if (!isAdminLogged()) {
    header('Location: ../admin/admin_login.php');
    exit();
}

// Carica statistiche sistema
try {
    $pdo = getDbConnection();
    
    // Statistiche categorie
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_categories,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_categories,
            COUNT(DISTINCT group_type) as group_types
        FROM fee_categories
    ");
    $categoryStats = $stmt->fetch();
    
    // Statistiche mappings
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT transaction_type) as total_mappings,
            COUNT(DISTINCT CASE WHEN user_id IS NULL THEN transaction_type END) as global_mappings,
            COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN transaction_type END) as user_overrides
        FROM transaction_fee_mappings 
        WHERE is_active = 1
    ");
    $mappingStats = $stmt->fetch();
    
    // Transaction types non mappati
    $stmt = $pdo->query("
        SELECT COUNT(DISTINCT transaction_type) as unmapped_count
        FROM (
            SELECT DISTINCT transaction_type FROM report_settlement_1 WHERE transaction_type IS NOT NULL
            UNION SELECT DISTINCT transaction_type FROM report_settlement_2 WHERE transaction_type IS NOT NULL
            UNION SELECT DISTINCT transaction_type FROM report_settlement_7 WHERE transaction_type IS NOT NULL
            UNION SELECT DISTINCT transaction_type FROM report_settlement_8 WHERE transaction_type IS NOT NULL
            UNION SELECT DISTINCT transaction_type FROM report_settlement_9 WHERE transaction_type IS NOT NULL
            UNION SELECT DISTINCT transaction_type FROM report_settlement_10 WHERE transaction_type IS NOT NULL
        ) all_types
        WHERE transaction_type NOT IN (
            SELECT DISTINCT transaction_type 
            FROM transaction_fee_mappings 
            WHERE user_id IS NULL AND is_active = 1
        )
    ");
    $unmappedStats = $stmt->fetch();
    
    // Distribuzione per gruppo
    $stmt = $pdo->query("
        SELECT 
            fc.group_type,
            COUNT(fc.id) as categories_count,
            COUNT(tfm.transaction_type) as mapped_transactions
        FROM fee_categories fc
        LEFT JOIN transaction_fee_mappings tfm ON fc.category_code = tfm.category 
            AND tfm.user_id IS NULL AND tfm.is_active = 1
        WHERE fc.is_active = 1
        GROUP BY fc.group_type
        ORDER BY FIELD(fc.group_type, 'TAB1', 'TAB2', 'TAB3', 'IGNORE')
    ");
    $groupStats = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = "Errore caricamento statistiche: " . $e->getMessage();
    logMarginsOperation("Errore dashboard admin: " . $e->getMessage());
}

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : ($error ?? '');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fee Dashboard Admin - Margynomic</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .container { max-width: 1200px; margin: 2rem auto; }
        .card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .stat-card { background: white; padding: 2rem; border-radius: 15px; text-align: center; }
        .stat-value { font-size: 2.5rem; font-weight: bold; color: #667eea; }
        .group-card { background: white; padding: 1.5rem; border-radius: 10px; margin-bottom: 1rem; }
        .btn-admin { background: #667eea; border: none; border-radius: 8px; }
        .btn-admin:hover { background: #5a6fd8; }
    </style>
</head>
<body>
<?php echo getAdminNavigation('fee_dashboard'); ?>
    <div class="container">
        <!-- Header -->
        <div class="text-center text-white mb-4">
            <h1><i class="fas fa-tachometer-alt"></i> Fee Management Dashboard</h1>
            <p>Sistema di gestione categorizzazione fee Amazon</p>
        </div>
        
        <!-- Navigation -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="d-flex gap-3 justify-content-center">
                    <a href="admin_fee_mappings.php" class="btn btn-admin btn-lg">
                        <i class="fas fa-tags"></i> Gestione Mappings
                    </a>
                    <a href="admin_fee_user_overrides.php" class="btn btn-warning btn-lg">
                        <i class="fas fa-user-cog"></i> Override Utenti
                    </a>
                    <a href="margins_overview.php" class="btn btn-success btn-lg">
                        <i class="fas fa-chart-line"></i> Test Dashboard Margini
                    </a>
                    <a href="../admin/admin_dashboard.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-arrow-left"></i> Admin Panel
                    </a>
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
        
        <!-- Statistics Overview -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $categoryStats['active_categories'] ?? 0; ?></div>
                    <div>Categorie Attive</div>
                    <small class="text-muted"><?php echo $categoryStats['group_types'] ?? 0; ?> gruppi</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?php echo $mappingStats['global_mappings'] ?? 0; ?></div>
                    <div>Mappings Globali</div>
                    <small class="text-muted"><?php echo $mappingStats['user_overrides'] ?? 0; ?> override utente</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $unmappedStats['unmapped_count'] ?? 0; ?></div>
                    <div>Non Mappati</div>
                    <small class="text-muted">Transaction types</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success">
                        <?php 
                        $totalMapped = $mappingStats['global_mappings'] ?? 0;
                        $totalUnmapped = $unmappedStats['unmapped_count'] ?? 0;
                        $total = $totalMapped + $totalUnmapped;
                        $percentage = $total > 0 ? round(($totalMapped / $total) * 100, 1) : 0;
                        echo $percentage;
                        ?>%
                    </div>
                    <div>Coverage</div>
                    <small class="text-muted">Mappings completati</small>
                </div>
            </div>
        </div>
        
        <!-- Group Distribution -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-layer-group"></i> Distribuzione per Gruppo</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($groupStats as $group): ?>
                        <div class="col-md-6 col-lg-3">
                            <div class="group-card">
                                <h6 class="text-primary">
                                    <?php 
                                    $icons = [
                                        'TAB1' => 'fas fa-money-bill-wave',
                                        'TAB2' => 'fas fa-cogs', 
                                        'TAB3' => 'fas fa-shield-alt',
                                        'IGNORE' => 'fas fa-eye-slash'
                                    ];
                                    $names = [
                                        'TAB1' => 'Commissioni Dirette',
                                        'TAB2' => 'Costi Operativi',
                                        'TAB3' => 'Compensi/Danni', 
                                        'IGNORE' => 'Ignorati'
                                    ];
                                    ?>
                                    <i class="<?php echo $icons[$group['group_type']] ?? 'fas fa-question'; ?>"></i>
                                    <?php echo $names[$group['group_type']] ?? $group['group_type']; ?>
                                </h6>
                                <div class="d-flex justify-content-between">
                                    <span><?php echo $group['categories_count']; ?> categorie</span>
                                    <span class="text-success"><?php echo $group['mapped_transactions']; ?> mappings</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="card mt-4">
            <div class="card-header">
                <h5><i class="fas fa-bolt"></i> Azioni Rapide</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="d-grid">
                            <a href="admin_fee_mappings.php?action=create_category" class="btn btn-success btn-lg">
                                <i class="fas fa-plus"></i><br>
                                Nuova Categoria
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-grid">
                            <a href="admin_fee_mappings.php?filter=unmapped" class="btn btn-warning btn-lg">
                                <i class="fas fa-exclamation-circle"></i><br>
                                Mappa Non Assegnati
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-grid">
                            <a href="admin_fee_user_overrides.php" class="btn btn-info btn-lg">
                                <i class="fas fa-users-cog"></i><br>
                                Gestisci Override
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>