<?php
/**
 * Admin Cleanup Interface - Gestione Log e Cache
 * File: admin/admin_cleanup.php
 * 
 * Interfaccia web per pulizia log database e cache sistema
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'admin_helpers.php';
requireAdmin();
require_once '../config/config.php';

// === ESECUZIONE CLEANUP ===
$result = null;
$executed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $executed = true;
    
    try {
        $pdo = getDbConnection();
        
        switch ($action) {
            case 'sync_debug_logs':
                // ELIMINAZIONE TOTALE (nessuna retention)
                $stmt = $pdo->query("DELETE FROM sync_debug_logs");
                $deleted = $stmt->rowCount();
                $result = ['success' => true, 'deleted' => $deleted, 'table' => 'sync_debug_logs'];
                break;
                
            case 'api_debug_log':
                // ELIMINAZIONE TOTALE (nessuna retention)
                $stmt = $pdo->query("DELETE FROM api_debug_log");
                $deleted = $stmt->rowCount();
                $result = ['success' => true, 'deleted' => $deleted, 'table' => 'api_debug_log'];
                break;
                
            case 'module_health_history':
                // ELIMINAZIONE TOTALE (nessuna retention)
                $stmt = $pdo->query("DELETE FROM module_health_history");
                $deleted = $stmt->rowCount();
                $result = ['success' => true, 'deleted' => $deleted, 'table' => 'module_health_history'];
                break;
                
            case 'admin_notifications_log':
                // ELIMINAZIONE TOTALE (nessuna retention)
                $stmt = $pdo->query("DELETE FROM admin_notifications_log");
                $deleted = $stmt->rowCount();
                $result = ['success' => true, 'deleted' => $deleted, 'table' => 'admin_notifications_log'];
                break;
                
            case 'daily_metrics_snapshot':
                // ELIMINAZIONE TOTALE (nessuna retention)
                $stmt = $pdo->query("DELETE FROM daily_metrics_snapshot");
                $deleted = $stmt->rowCount();
                $result = ['success' => true, 'deleted' => $deleted, 'table' => 'daily_metrics_snapshot'];
                break;
                
            case 'mobile_cache':
                // ELIMINAZIONE TOTALE (nessuna retention)
                $stmt = $pdo->query("DELETE FROM mobile_cache");
                $deleted = $stmt->rowCount();
                $result = ['success' => true, 'deleted' => $deleted, 'table' => 'mobile_cache'];
                break;
                
            case 'all':
                $totalDeleted = 0;
                $tables = [
                    'sync_debug_logs',
                    'api_debug_log',
                    'module_health_history',
                    'admin_notifications_log',
                    'daily_metrics_snapshot',
                    'mobile_cache'
                ];
                
                $details = [];
                foreach ($tables as $table) {
                    // ELIMINAZIONE TOTALE di ogni tabella (nessuna retention)
                    $stmt = $pdo->query("DELETE FROM $table");
                    $deleted = $stmt->rowCount();
                    $totalDeleted += $deleted;
                    $details[] = "$table: $deleted righe";
                }
                
                $result = ['success' => true, 'deleted' => $totalDeleted, 'table' => 'ALL', 'details' => $details];
                break;
        }
    } catch (Exception $e) {
        $result = ['success' => false, 'error' => $e->getMessage()];
    }
}

// === STATISTICHE TABELLE ===
try {
    $pdo = getDbConnection();
    
    $stats = [];
    
    // sync_debug_logs - ELIMINAZIONE TOTALE
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(*) as old_records,  -- Tutto verrà eliminato
            MIN(created_at) as oldest,
            MAX(created_at) as newest
        FROM sync_debug_logs
    ");
    $stats['sync_debug_logs'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // api_debug_log - ELIMINAZIONE TOTALE
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(*) as old_records,  -- Tutto verrà eliminato
            MIN(created_at) as oldest,
            MAX(created_at) as newest
        FROM api_debug_log
    ");
    $stats['api_debug_log'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // module_health_history - ELIMINAZIONE TOTALE
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(*) as old_records,  -- Tutto verrà eliminato
            MIN(measured_at) as oldest,
            MAX(measured_at) as newest
        FROM module_health_history
    ");
    $stats['module_health_history'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // admin_notifications_log - ELIMINAZIONE TOTALE
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(*) as old_records,  -- Tutto verrà eliminato
            MIN(sent_at) as oldest,
            MAX(sent_at) as newest
        FROM admin_notifications_log
    ");
    $stats['admin_notifications_log'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // daily_metrics_snapshot - ELIMINAZIONE TOTALE
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(*) as old_records,  -- Tutto verrà eliminato
            MIN(metric_date) as oldest,
            MAX(metric_date) as newest
        FROM daily_metrics_snapshot
    ");
    $stats['daily_metrics_snapshot'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // mobile_cache - ELIMINAZIONE TOTALE
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(*) as old_records,  -- Tutto verrà eliminato
            MIN(created_at) as oldest,
            MAX(created_at) as newest
        FROM mobile_cache
    ");
    $stats['mobile_cache'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $stats = [];
}

$tables = [
    ['sync_debug_logs', 'Sync Debug Logs', 0, 'Logs di debug sincronizzazioni'],
    ['api_debug_log', 'API Debug Log', 0, 'Logs chiamate API Amazon'],
    ['module_health_history', 'Module Health History', 0, 'Storico health check moduli'],
    ['admin_notifications_log', 'Admin Notifications', 0, 'Log notifiche amministratori'],
    ['daily_metrics_snapshot', 'Daily Metrics', 0, 'Snapshot metriche giornaliere'],
    ['mobile_cache', 'Mobile Cache', 0, 'Cache app mobile']
];

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cleanup Log & Cache - Admin</title>
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
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            margin: 0 0 8px 0;
        }
        
        .page-subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin: 0;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 15px;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .section {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 24px;
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
        
        .cleanup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .cleanup-card {
            background: linear-gradient(135deg, #f8f9ff 0%, #f1f3ff 100%);
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #e3e6ef;
            transition: all 0.3s ease;
        }
        
        .cleanup-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.15);
            border-color: #667eea;
        }
        
        .cleanup-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }
        
        .cleanup-info h3 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 4px 0;
            color: #1a1a1a;
        }
        
        .cleanup-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        
        .cleanup-retention {
            font-size: 12px;
            color: #667eea;
            font-weight: 500;
        }
        
        .cleanup-stats {
            background: white;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 13px;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        
        .stat-row:last-child {
            margin-bottom: 0;
        }
        
        .stat-label {
            color: #666;
        }
        
        .stat-value {
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .old-records {
            color: #ef4444;
            font-weight: 700;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            justify-content: center;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        .btn-danger:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            font-size: 16px;
            padding: 14px 28px;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .cleanup-all-section {
            text-align: center;
            padding: 30px;
        }
        
        .cleanup-all-section h3 {
            font-size: 22px;
            margin-bottom: 12px;
            color: #1a1a1a;
        }
        
        .cleanup-all-section p {
            color: #666;
            margin-bottom: 24px;
        }
        
        .action-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 32px;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0 0 8px 0;
            color: #1a1a1a;
            font-size: 20px;
        }
        
        .modal-body {
            margin-bottom: 24px;
            color: #666;
            line-height: 1.6;
        }
        
        .modal-footer {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        @media (max-width: 768px) {
            .cleanup-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php echo getAdminNavigation('cleanup'); ?>
    
    <div class="main-container">
        <div class="page-header">
            <h1 class="page-title">🧹 Cleanup Log & Cache</h1>
            <p class="page-subtitle">Gestione pulizia log database e cache sistema</p>
        </div>
        
        <?php if ($executed && $result): ?>
            <?php if ($result['success']): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Cleanup completato con successo!</strong><br>
                        <?php if ($result['table'] === 'ALL'): ?>
                            <strong>Totale righe eliminate: <?php echo number_format($result['deleted']); ?></strong>
                            <ul style="margin: 8px 0 0 20px; padding: 0;">
                                <?php foreach ($result['details'] as $detail): ?>
                                    <li><?php echo htmlspecialchars($detail); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            Tabella: <strong><?php echo htmlspecialchars($result['table']); ?></strong> - 
                            Righe eliminate: <strong><?php echo number_format($result['deleted']); ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Errore durante il cleanup:</strong><br>
                        <?php echo htmlspecialchars($result['error']); ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="section">
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-database"></i>
                    Tabelle Log Database
                </h2>
            </div>
            
            <div class="cleanup-grid">
                <?php foreach ($tables as list($table, $name, $retention, $description)): ?>
                    <div class="cleanup-card">
                        <div class="cleanup-header">
                            <div class="cleanup-info">
                                <h3><?php echo htmlspecialchars($name); ?></h3>
                                <p class="cleanup-desc"><?php echo htmlspecialchars($description); ?></p>
                                <p class="cleanup-retention" style="color: #ef4444; font-weight: 600;">⚠️ ELIMINAZIONE TOTALE</p>
                            </div>
                        </div>
                        
                        <?php if (isset($stats[$table])): ?>
                            <div class="cleanup-stats">
                                <div class="stat-row">
                                    <span class="stat-label">Totale righe:</span>
                                    <span class="stat-value"><?php echo number_format($stats[$table]['total']); ?></span>
                                </div>
                                <div class="stat-row">
                                    <span class="stat-label">Da eliminare:</span>
                                    <span class="stat-value old-records"><?php echo number_format($stats[$table]['old_records']); ?></span>
                                </div>
                                <?php if ($stats[$table]['oldest']): ?>
                                    <div class="stat-row">
                                        <span class="stat-label">Record più vecchio:</span>
                                        <span class="stat-value"><?php echo date('d/m/Y', strtotime($stats[$table]['oldest'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" onsubmit="return confirm('⚠️ ATTENZIONE: Stai per ELIMINARE TUTTE le <?php echo number_format($stats[$table]['total'] ?? 0); ?> righe da <?php echo htmlspecialchars($name); ?>!\n\nQuesta operazione NON può essere annullata.\n\nConfermi?');">
                            <input type="hidden" name="action" value="<?php echo htmlspecialchars($table); ?>">
                            <button type="submit" class="btn btn-danger" <?php echo ($stats[$table]['total'] ?? 0) == 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash-alt"></i>
                                Svuota Tabella
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="section cleanup-all-section">
            <h3>⚡ Cleanup Completo</h3>
            <p style="color: #ef4444; font-weight: 500;">⚠️ SVUOTA COMPLETAMENTE tutte le tabelle log e cache (nessuna retention)</p>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='admin_dashboard.php'">
                    <i class="fas fa-arrow-left"></i>
                    Torna alla Dashboard
                </button>
                <button type="button" class="btn btn-primary" onclick="showConfirmModal()">
                    <i class="fas fa-broom"></i>
                    Esegui Cleanup Completo
                </button>
            </div>
        </div>
    </div>
    
    <!-- Modal Conferma -->
    <div id="confirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>⚠️ Conferma Cleanup Completo</h3>
            </div>
            <div class="modal-body">
                <p style="color: #ef4444; font-weight: 600; margin-bottom: 12px;">⚠️ ATTENZIONE: Stai per SVUOTARE COMPLETAMENTE tutte le seguenti tabelle:</p>
                <p><strong>Tutte le righe verranno eliminate (nessuna retention):</strong></p>
                <ul>
                    <?php 
                    $totalToDelete = 0;
                    foreach ($tables as list($table, $name, $retention, $description)): 
                        $toDelete = $stats[$table]['old_records'] ?? 0;
                        $totalToDelete += $toDelete;
                    ?>
                        <li><?php echo htmlspecialchars($name); ?>: <strong><?php echo number_format($toDelete); ?> righe</strong></li>
                    <?php endforeach; ?>
                </ul>
                <p style="margin-top: 16px;"><strong>Totale righe da eliminare: <?php echo number_format($totalToDelete); ?></strong></p>
                <p style="color: #ef4444; margin-top: 12px;">⚠️ Questa operazione non può essere annullata!</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideConfirmModal()">
                    <i class="fas fa-times"></i>
                    Annulla
                </button>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="all">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-check"></i>
                        Conferma ed Esegui
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function showConfirmModal() {
            document.getElementById('confirmModal').classList.add('active');
        }
        
        function hideConfirmModal() {
            document.getElementById('confirmModal').classList.remove('active');
        }
        
        // Close modal on click outside
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideConfirmModal();
            }
        });
    </script>
</body>
</html>

