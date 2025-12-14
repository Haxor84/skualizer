<?php
/**
 * Dashboard Admin Margynomic - VERSIONE PROFESSIONALE
 * File: admin/admin_dashboard.php
 * 
 * Dashboard completamente riscritta con layout moderno e funzionalità avanzate
 */

require_once 'admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

// Carica statistiche avanzate
try {
    $pdo = getAdminDbConnection();
    
    // Statistiche base
    $activeUsers = countActiveUsers();
    $activeCredentials = countActiveCredentials();
    $totalLogs = countDebugLogs();
    
    // Statistiche prodotti e AI
    $stmt = $pdo->query("SELECT COUNT(*) FROM products");
    $totalProducts = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM products WHERE ai_generated = 1");
    $aiProducts = $stmt->fetchColumn();
    
    // Statistiche settlement
    $stmt = $pdo->query("
        SELECT 
            (SELECT COUNT(*) FROM report_settlement_2) +
            (SELECT COUNT(*) FROM report_settlement_7) +
            (SELECT COUNT(*) FROM report_settlement_8) +
            (SELECT COUNT(*) FROM report_settlement_9) +
            (SELECT COUNT(*) FROM report_settlement_10) as total_settlements
    ");
    $totalSettlements = $stmt->fetchColumn();
    
    // Statistiche mapping
    $stmt = $pdo->query("SELECT COUNT(*) FROM mapping_states");
    $totalMappings = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM mapping_states WHERE confidence_score > 0.8");
    $highConfidenceMappings = $stmt->fetchColumn();
    
    // Utenti recenti con dati reali
    $stmt = $pdo->query("
        SELECT u.id, u.nome, u.email, u.is_active, u.creato_il, u.last_login,
               COALESCE(settlement_counts.total, 0) as settlement_count
        FROM users u
        LEFT JOIN (
            SELECT 2 as user_id, COUNT(*) as total FROM report_settlement_2
            UNION ALL
            SELECT 7 as user_id, COUNT(*) as total FROM report_settlement_7
            UNION ALL
            SELECT 8 as user_id, COUNT(*) as total FROM report_settlement_8
            UNION ALL
            SELECT 9 as user_id, COUNT(*) as total FROM report_settlement_9
            UNION ALL
            SELECT 10 as user_id, COUNT(*) as total FROM report_settlement_10
        ) settlement_counts ON u.id = settlement_counts.user_id
        WHERE u.is_active = 1
        ORDER BY u.creato_il DESC
        LIMIT 10
    ");
    $recentUsers = $stmt->fetchAll();
    
    // Sistema status checks
    $systemChecks = [
        'database' => true,
        'ai_processor' => $totalProducts > 0,
        'mapping_system' => $totalMappings > 0,
        'settlement_data' => $totalSettlements > 0
    ];
    
} catch (Exception $e) {
    $activeUsers = 0;
    $activeCredentials = 0;
    $totalLogs = 0;
    $totalProducts = 0;
    $aiProducts = 0;
    $totalSettlements = 0;
    $totalMappings = 0;
    $highConfidenceMappings = 0;
    $recentUsers = [];
    $systemChecks = ['database' => false, 'ai_processor' => false, 'mapping_system' => false, 'settlement_data' => false];
}

// Calcola percentuali
$aiAutomationPercent = $totalProducts > 0 ? round(($aiProducts / $totalProducts) * 100, 1) : 0;
$mappingAccuracyPercent = $totalMappings > 0 ? round(($highConfidenceMappings / $totalMappings) * 100, 1) : 0;

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Margynomic</title>
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
        
        .stat-change {
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .positive { background: #d4edda; color: #155724; }
        .neutral { background: #e2e6ea; color: #495057; }
        
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
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .action-card {
            background: linear-gradient(135deg, #f8f9ff 0%, #f1f3ff 100%);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e3e6ef;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.15);
            border-color: #667eea;
            text-decoration: none;
            color: inherit;
        }
        
        .action-icon {
            width: 40px;
            height: 40px;
            background: #667eea;
            color: white;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            font-size: 18px;
        }
        
        .action-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #1a1a1a;
        }
        
        .action-desc {
            font-size: 13px;
            color: #666;
            line-height: 1.4;
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
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        
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
        
        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5a6fd8; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #545b62; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .system-status {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .status-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-online { background: #28a745; color: white; }
        .status-offline { background: #dc3545; color: white; }
        
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #666;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .main-container { padding: 15px; }
            .stats-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: 1fr; }
            .dashboard-header { padding: 20px; }
            .dashboard-title { font-size: 24px; }
        }
    </style>
</head>
<body>

<?php echo getAdminNavigation('dashboard'); ?>

<div class="main-container">
    <!-- Header Dashboard -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">
            <i class="fas fa-tachometer-alt"></i>
            Dashboard Amministratore
        </h1>
        <p class="dashboard-subtitle">
            Pannello di controllo centrale del sistema Margynomic
        </p>
    </div>

    <!-- Statistiche Principali -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #28a745;">
                    <i class="fas fa-users"></i>
                </div>
                <span class="stat-change positive">+<?php echo count($recentUsers); ?> recenti</span>
            </div>
            <div class="stat-number"><?php echo $activeUsers; ?></div>
            <div class="stat-label">Utenti Attivi</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #17a2b8;">
                    <i class="fas fa-key"></i>
                </div>
                <span class="stat-change neutral">API attive</span>
            </div>
            <div class="stat-number"><?php echo $activeCredentials; ?></div>
            <div class="stat-label">Credenziali Amazon</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #ffc107;">
                    <i class="fas fa-database"></i>
                </div>
                <span class="stat-change positive"><?php echo number_format($totalSettlements); ?> righe</span>
            </div>
            <div class="stat-number"><?php echo number_format($totalLogs); ?></div>
            <div class="stat-label">Log Debug Totali</div>
        </div>
        
        <div class="stat-card">
            <div class="stat-header">
                <div class="stat-icon" style="background: #6f42c1;">
                    <i class="fas fa-robot"></i>
                </div>
                <span class="stat-change positive"><?php echo $aiAutomationPercent; ?>% AI</span>
            </div>
            <div class="stat-number"><?php echo $totalProducts; ?></div>
            <div class="stat-label">Prodotti Mappati</div>
        </div>
    </div>

    <!-- Azioni Rapide -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-bolt"></i>
                Azioni Rapide
            </h2>
        </div>
        
        <div class="quick-actions">
            <a href="../../mapping/sku_aggregation_interface.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-project-diagram"></i>
                </div>
                <div class="action-title">Aggregazione SKU</div>
                <div class="action-desc">Gestisci mapping e aggregazione prodotti</div>
            </a>
            
            <a href="admin_utenti.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-users-cog"></i>
                </div>
                <div class="action-title">Gestione Utenti</div>
                <div class="action-desc">Amministra utenti e permessi</div>
            </a>
            
            <a href="../margini/admin_fee_mappings.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-tags"></i>
                </div>
                <div class="action-title">Fee Mappings</div>
                <div class="action-desc">Configura categorie e mappature fee</div>
            </a>
            
            <a href="admin_credenziali.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <div class="action-title">Credenziali Amazon</div>
                <div class="action-desc">Gestisci connessioni SP-API</div>
            </a>
            
            <a href="admin_historical.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-history"></i>
                </div>
                <div class="action-title">Historical Parser</div>
                <div class="action-desc">Importa dati settlement storici</div>
            </a>
            
            <a href="../../listing/admin_list.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-list-ol"></i>
                </div>
                <div class="action-title">Product Listing</div>
                <div class="action-desc">Gestisci ordine prodotti utenti</div>
            </a>
            
            <a href="../../inbound/index.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-truck-loading"></i>
                </div>
                <div class="action-title">Inbound Sync</div>
                <div class="action-desc">Sincronizza spedizioni FBA da Amazon</div>
            </a>
            
            <a href="admin_cleanup.php" class="action-card">
                <div class="action-icon">
                    <i class="fas fa-broom"></i>
                </div>
                <div class="action-title">Cleanup Log & Cache</div>
                <div class="action-desc">Pulizia log database e cache sistema</div>
            </a>
        </div>
    </div>

    <!-- Status Sistema -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-heartbeat"></i>
                Status Sistema
            </h2>
        </div>
        
        <div class="system-status">
            <div class="status-item">
                <div class="status-icon <?php echo $systemChecks['database'] ? 'status-online' : 'status-offline'; ?>">
                    <?php echo $systemChecks['database'] ? '✓' : '✗'; ?>
                </div>
                <div>
                    <div style="font-weight: 600;">Database</div>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo $systemChecks['database'] ? 'Connesso e operativo' : 'Errore connessione'; ?>
                    </div>
                </div>
            </div>
            
            <div class="status-item">
                <div class="status-icon <?php echo $systemChecks['ai_processor'] ? 'status-online' : 'status-offline'; ?>">
                    <?php echo $systemChecks['ai_processor'] ? '✓' : '✗'; ?>
                </div>
                <div>
                    <div style="font-weight: 600;">AI Processor</div>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo $systemChecks['ai_processor'] ? 'Attivo e funzionante' : 'Non inizializzato'; ?>
                    </div>
                </div>
            </div>
            
            <div class="status-item">
                <div class="status-icon <?php echo $systemChecks['mapping_system'] ? 'status-online' : 'status-offline'; ?>">
                    <?php echo $systemChecks['mapping_system'] ? '✓' : '✗'; ?>
                </div>
                <div>
                    <div style="font-weight: 600;">Mapping System</div>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo $systemChecks['mapping_system'] ? $mappingAccuracyPercent . '% accuratezza' : 'Non configurato'; ?>
                    </div>
                </div>
            </div>
            
            <div class="status-item">
                <div class="status-icon <?php echo $systemChecks['settlement_data'] ? 'status-online' : 'status-offline'; ?>">
                    <?php echo $systemChecks['settlement_data'] ? '✓' : '✗'; ?>
                </div>
                <div>
                    <div style="font-weight: 600;">Settlement Data</div>
                    <div style="font-size: 12px; color: #666;">
                        <?php echo $systemChecks['settlement_data'] ? number_format($totalSettlements) . ' record' : 'Nessun dato'; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Utenti Recenti -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-user-clock"></i>
                Utenti Recenti
            </h2>
            <a href="admin_utenti.php" class="btn btn-primary btn-sm">
                <i class="fas fa-users"></i>
                Gestisci Tutti
            </a>
        </div>
        
        <?php if (empty($recentUsers)): ?>
            <div class="no-data">
                <i class="fas fa-users" style="font-size: 48px; color: #dee2e6; margin-bottom: 16px;"></i>
                <p>Nessun utente registrato</p>
                <a href="admin_utenti.php" class="btn btn-primary">Aggiungi Primo Utente</a>
            </div>
        <?php else: ?>
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Utente</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Settlement</th>
                        <th>Ultimo Login</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentUsers as $user): ?>
                        <tr>
                            <td>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 32px; height: 32px; background: #667eea; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px;">
                                        <?php echo strtoupper(substr($user['nome'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 600;"><?php echo htmlspecialchars($user['nome']); ?></div>
                                        <div style="font-size: 12px; color: #666;">ID: <?php echo $user['id']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $user['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $user['is_active'] ? '✓ Attivo' : '✗ Sospeso'; ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo number_format($user['settlement_count']); ?></strong>
                                <div style="font-size: 11px; color: #666;">transazioni</div>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?>
                                <?php else: ?>
                                    <span style="color: #999; font-style: italic;">Mai</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <a href="admin_utenti.php?user_id=<?php echo $user['id']; ?>" class="btn btn-primary btn-sm" title="Dettagli">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../../mapping/sku_aggregation_interface.php?user_id=<?php echo $user['id']; ?>" class="btn btn-secondary btn-sm" title="Mapping">
                                        <i class="fas fa-project-diagram"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Informazioni Sistema -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-info-circle"></i>
                Informazioni Sistema
            </h2>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
            <div>
                <h4 style="margin-bottom: 12px; color: #495057;">Ambiente</h4>
                <table style="width: 100%; font-size: 14px;">
                    <tr><td style="padding: 4px 0; color: #666;">PHP Version:</td><td style="font-weight: 600;"><?php echo PHP_VERSION; ?></td></tr>
                    <tr><td style="padding: 4px 0; color: #666;">Database:</td><td style="font-weight: 600;"><?php echo defined('DB_NAME') ? DB_NAME : 'N/A'; ?></td></tr>
                    <tr><td style="padding: 4px 0; color: #666;">Admin:</td><td style="font-weight: 600;"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['admin_email'] ?? 'N/A'); ?></td></tr>
                </table>
            </div>
            
            <div>
                <h4 style="margin-bottom: 12px; color: #495057;">Performance</h4>
                <table style="width: 100%; font-size: 14px;">
                    <tr><td style="padding: 4px 0; color: #666;">Prodotti AI:</td><td style="font-weight: 600;"><?php echo $aiProducts; ?> / <?php echo $totalProducts; ?></td></tr>
                    <tr><td style="padding: 4px 0; color: #666;">Automazione:</td><td style="font-weight: 600;"><?php echo $aiAutomationPercent; ?>%</td></tr>
                    <tr><td style="padding: 4px 0; color: #666;">Mapping Accuracy:</td><td style="font-weight: 600;"><?php echo $mappingAccuracyPercent; ?>%</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-refresh ogni 5 minuti
setTimeout(function() {
    location.reload();
}, 300000);

// Click tracking per statistiche
document.querySelectorAll('.action-card').forEach(function(card) {
    card.addEventListener('click', function(e) {
        console.log('Action clicked:', this.querySelector('.action-title').textContent);
    });
});
</script>

</body>
</html>