<?php
/**
 * Admin Log Dashboard - Overview Sistema Log
 * File: admin/admin_log_dashboard.php
 * 
 * Dashboard centrale con statistiche, trend e health score
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'admin_helpers.php';
requireAdmin();
require_once '../config/config.php';

// === CARICAMENTO DATI ===
try {
    $pdo = getDbConnection();
    
    // Dashboard stats
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_logs,
            COALESCE(SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END), 0) as errors,
            COALESCE(SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END), 0) as warnings,
            COUNT(DISTINCT user_id) as unique_users
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Today stats
    $todayStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_logs,
            COALESCE(SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END), 0) as errors,
            COALESCE(SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END), 0) as warnings
        FROM sync_debug_logs 
        WHERE DATE(created_at) = CURDATE()
    ");
    $today = $todayStmt->fetch(PDO::FETCH_ASSOC);
    
    // By module (dai prefissi operation_type)
    $modulesStmt = $pdo->query("
        SELECT 
            SUBSTRING_INDEX(operation_type, '_', 1) as module,
            COUNT(*) as count,
            COALESCE(SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END), 0) as errors
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY module
        ORDER BY count DESC
        LIMIT 8
    ");
    $modules = $modulesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top errors
    $errorsStmt = $pdo->query("
        SELECT 
            message,
            COUNT(*) as occurrences,
            MAX(created_at) as last_occurrence,
            SUBSTRING_INDEX(operation_type, '_', 1) as module
        FROM sync_debug_logs 
        WHERE log_level = 'ERROR'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY message, module
        ORDER BY occurrences DESC
        LIMIT 5
    ");
    $topErrors = $errorsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Health score
    $totalLogs = $stats['total_logs'] ?? 0;
    $errors = $stats['errors'] ?? 0;
    $errorRate = $totalLogs > 0 ? ($errors / $totalLogs) * 100 : 0;
    $healthScore = max(0, min(100, 100 - ($errorRate * 10)));
    
    // Avg duration (da execution_time_ms)
    $durationStmt = $pdo->query("
        SELECT AVG(execution_time_ms) / 1000 as avg_duration
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND execution_time_ms IS NOT NULL
    ");
    $durationResult = $durationStmt->fetch(PDO::FETCH_ASSOC);
    $avgDuration = $durationResult['avg_duration'] ? round($durationResult['avg_duration'], 2) : 0;
    
} catch (Exception $e) {
    die("Errore caricamento dati: " . $e->getMessage());
}

// === HEALTH SCORE CLASS ===
function getHealthClass($score) {
    if ($score >= 90) return 'excellent';
    if ($score >= 70) return 'good';
    if ($score >= 50) return 'warning';
    return 'critical';
}

function getHealthIcon($score) {
    if ($score >= 90) return '✅';
    if ($score >= 70) return '🟢';
    if ($score >= 50) return '⚠️';
    return '❌';
}

// === MODULE ICONS ===
$moduleIcons = [
    'inventory' => '📦',
    'settlement' => '💰',
    'oauth' => '🔐',
    'email_notifications' => '📧',
    'mapping' => '🔗',
    'orderinsights' => '📊',
    'admin' => '⚙️',
    'historical' => '📜',
    'ai' => '🤖'
];

// === OUTPUT HTML ===
try {
    echo getAdminHeader('📊 Log Dashboard');
    echo getAdminNavigation('log_dashboard');
} catch (Exception $e) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Log Dashboard</title>';
    echo '<link rel="stylesheet" href="assets/admin_logs.css"></head><body>';
}
?>

<link rel="stylesheet" href="assets/admin_logs.css">

<div class="log-system-container">
    <!-- Sidebar Navigation -->
    <nav class="log-sidebar">
        <div class="log-sidebar-header">
            <h2 class="log-sidebar-title">📊 LOG SYSTEM</h2>
        </div>
        
        <ul class="log-sidebar-nav">
            <li class="log-sidebar-item">
                <a href="admin_log_dashboard.php" class="log-sidebar-link active">
                    <span class="log-sidebar-icon">🏠</span>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="log-sidebar-item">
                <a href="admin_log.php" class="log-sidebar-link">
                    <span class="log-sidebar-icon">📋</span>
                    <span>Application Logs</span>
                </a>
            </li>
            <li class="log-sidebar-item">
                <a href="admin_server_logs.php" class="log-sidebar-link">
                    <span class="log-sidebar-icon">🔥</span>
                    <span>Server Logs</span>
                </a>
            </li>
            
            <li class="log-sidebar-divider"></li>
            
            <li class="log-sidebar-item">
                <a href="../dashboard.php" class="log-sidebar-link">
                    <span class="log-sidebar-icon">⬅️</span>
                    <span>Admin Dashboard</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <main class="log-main-content">
        <!-- Page Header -->
        <div class="log-page-header">
            <h1>📊 Log System Dashboard</h1>
            <p>Overview completa del sistema di logging Margynomic</p>
            
            <div class="log-page-header-stats">
                <div class="log-page-header-stat">
                    <div class="log-page-header-stat-label">Health Score</div>
                    <div class="log-page-header-stat-value log-health-score <?php echo getHealthClass($healthScore); ?>">
                        <span class="log-health-icon"><?php echo getHealthIcon($healthScore); ?></span>
                        <?php echo round($healthScore); ?>%
                    </div>
                </div>
                
                <div class="log-page-header-stat">
                    <div class="log-page-header-stat-label">Log Oggi</div>
                    <div class="log-page-header-stat-value"><?php echo $today['total_logs'] ?? 0; ?></div>
                </div>
                
                <div class="log-page-header-stat">
                    <div class="log-page-header-stat-label">Errori Oggi</div>
                    <div class="log-page-header-stat-value"><?php echo $today['errors'] ?? 0; ?></div>
                </div>
                
                <div class="log-page-header-stat">
                    <div class="log-page-header-stat-label">Tempo Medio</div>
                    <div class="log-page-header-stat-value"><?php echo $avgDuration; ?>s</div>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="log-stats-grid">
            <div class="log-stat-card">
                <div class="log-stat-icon">📝</div>
                <div class="log-stat-value"><?php echo number_format($stats['total_logs'] ?? 0); ?></div>
                <div class="log-stat-label">Log Totali</div>
                <div class="log-stat-sublabel">Ultimi 7 giorni</div>
            </div>
            
            <div class="log-stat-card danger">
                <div class="log-stat-icon">❌</div>
                <div class="log-stat-value"><?php echo number_format($stats['errors'] ?? 0); ?></div>
                <div class="log-stat-label">Errori</div>
                <div class="log-stat-sublabel">
                    <?php echo ($stats['total_logs'] ?? 0) > 0 ? round((($stats['errors'] ?? 0) / $stats['total_logs']) * 100, 1) : 0; ?>% del totale
                </div>
            </div>
            
            <div class="log-stat-card warning">
                <div class="log-stat-icon">⚠️</div>
                <div class="log-stat-value"><?php echo number_format($stats['warnings'] ?? 0); ?></div>
                <div class="log-stat-label">Warning</div>
                <div class="log-stat-sublabel">
                    <?php echo ($stats['total_logs'] ?? 0) > 0 ? round((($stats['warnings'] ?? 0) / $stats['total_logs']) * 100, 1) : 0; ?>% del totale
                </div>
            </div>
            
            <div class="log-stat-card info">
                <div class="log-stat-icon">👥</div>
                <div class="log-stat-value"><?php echo $stats['unique_users'] ?? 0; ?></div>
                <div class="log-stat-label">Utenti Attivi</div>
                <div class="log-stat-sublabel">Con attività recente</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
            <!-- Moduli Attivi -->
            <div class="log-card">
                <div class="log-card-header">
                    <h3 class="log-card-title">📦 Moduli Più Attivi</h3>
                </div>
                <div class="log-card-body">
                    <?php if (empty($modules)): ?>
                        <p class="log-text-center" style="color: var(--text-secondary);">Nessun dato disponibile</p>
                    <?php else: ?>
                        <div style="display: flex; flex-direction: column; gap: 12px;">
                            <?php foreach ($modules as $module): ?>
                                <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius);">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="font-size: 1.5rem;"><?php echo $moduleIcons[$module['module']] ?? '📋'; ?></span>
                                        <div>
                                            <div style="font-weight: 600; color: var(--text-primary);"><?php echo ucfirst($module['module']); ?></div>
                                            <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                                                <?php echo $module['errors'] ?? 0; ?> errori
                                            </div>
                                        </div>
                                    </div>
                                    <div style="font-size: 1.25rem; font-weight: 700; color: var(--primary);">
                                        <?php echo number_format($module['count'] ?? 0); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top Errori -->
            <div class="log-card">
                <div class="log-card-header">
                    <h3 class="log-card-title">🔥 Errori Più Frequenti</h3>
                </div>
                <div class="log-card-body">
                    <?php if (empty($topErrors)): ?>
                        <p class="log-text-center" style="color: var(--text-secondary);">✅ Nessun errore critico!</p>
                    <?php else: ?>
                        <ul class="log-top-errors-list">
                            <?php foreach ($topErrors as $error): ?>
                                <li class="log-top-error-item">
                                    <div class="log-top-error-header">
                                        <span class="log-top-error-count"><?php echo $error['occurrences']; ?>x</span>
                                    </div>
                                    <div class="log-top-error-message">
                                        <?php echo htmlspecialchars(substr($error['message'], 0, 80)); ?>
                                        <?php if (strlen($error['message']) > 80): ?>...<?php endif; ?>
                                    </div>
                                    <div class="log-top-error-meta">
                                        <span><strong>Modulo:</strong> <?php echo $error['module']; ?></span>
                                        <span><strong>Ultimo:</strong> <?php echo date('d/m H:i', strtotime($error['last_occurrence'])); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="log-card">
            <div class="log-card-header">
                <h3 class="log-card-title">⚡ Azioni Rapide</h3>
            </div>
            <div class="log-card-body">
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <a href="admin_log.php" class="log-btn log-btn-primary">
                        <span>🔍</span>
                        <span>Cerca Log</span>
                    </a>
                    
                    <a href="admin_log.php?level=ERROR" class="log-btn log-btn-danger">
                        <span>❌</span>
                        <span>Vedi Solo Errori</span>
                    </a>
                    
                    <a href="admin_log.php?date_from=<?php echo date('Y-m-d'); ?>" class="log-btn log-btn-secondary">
                        <span>📅</span>
                        <span>Log di Oggi</span>
                    </a>
                    
                    <a href="admin_server_logs.php" class="log-btn log-btn-secondary">
                        <span>🔥</span>
                        <span>Server Logs</span>
                    </a>
                    
                    <button onclick="cleanupLogs()" class="log-btn log-btn-secondary" id="cleanupBtn">
                        <span>🗑️</span>
                        <span>Cleanup Logs</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="log-card" style="margin-top: 30px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-left: 4px solid var(--info);">
            <div class="log-card-body">
                <h4 style="margin: 0 0 10px 0; color: var(--info);">💡 Sistema di Logging Ottimizzato</h4>
                <p style="margin: 0 0 10px 0; color: var(--text-primary);">
                    Dopo il consolidamento logging, il sistema genera circa <strong>~25-30 log/giorno</strong> 
                    invece di ~475, una riduzione del <strong>95%</strong> mantenendo tutte le informazioni critiche.
                </p>
                <ul style="margin: 0; padding-left: 20px; color: var(--text-secondary);">
                    <li><strong>1 log = 1 operazione completa</strong> con context JSON dettagliato</li>
                    <li>Database ottimizzato: da 14.250 a ~750 log/mese</li>
                    <li>Query admin velocissime, UI reattiva</li>
                </ul>
            </div>
        </div>
    </main>
</div>

<script src="assets/admin_logs.js"></script>

<script>
/**
 * Cleanup Logs - Elimina log più vecchi di N giorni
 */
async function cleanupLogs() {
    const daysToKeep = 30; // Default 30 giorni
    const btn = document.getElementById('cleanupBtn');
    
    try {
        // FASE 1: Dry run - mostra anteprima
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span><span>Analisi...</span>';
        
        const dryRunResponse = await fetch(`admin_log_api.php?action=cleanup&days=${daysToKeep}&dry_run=true`);
        const dryRunData = await dryRunResponse.json();
        
        if (!dryRunData.success) {
            alert('❌ Errore: ' + (dryRunData.error || 'Errore sconosciuto'));
            btn.disabled = false;
            btn.innerHTML = '<span>🗑️</span><span>Cleanup Logs</span>';
            return;
        }
        
        // Mostra dettagli e chiedi conferma
        const confirmMessage = `🗑️ CLEANUP LOGS - Conferma Eliminazione

📊 Statistiche:
• Log da eliminare: ${dryRunData.logs_to_delete.toLocaleString()}
• Periodo: più vecchi di ${daysToKeep} giorni
• Data limite: ${new Date(dryRunData.cutoff_date).toLocaleDateString('it-IT')}

⚠️ Dettagli:
• Errori: ${dryRunData.errors_to_delete.toLocaleString()}
• Warning: ${dryRunData.warnings_to_delete.toLocaleString()}
• Log più vecchio: ${dryRunData.oldest_log ? new Date(dryRunData.oldest_log).toLocaleDateString('it-IT') : 'N/A'}

⚠️ ATTENZIONE: Questa operazione è IRREVERSIBILE!

Procedere con l'eliminazione?`;
        
        if (!confirm(confirmMessage)) {
            btn.disabled = false;
            btn.innerHTML = '<span>🗑️</span><span>Cleanup Logs</span>';
            return;
        }
        
        // FASE 2: Cleanup REALE
        btn.innerHTML = '<span>🗑️</span><span>Eliminazione...</span>';
        
        const cleanupResponse = await fetch(`admin_log_api.php?action=cleanup&days=${daysToKeep}`);
        const cleanupData = await cleanupResponse.json();
        
        if (cleanupData.success) {
            const successMessage = `✅ CLEANUP COMPLETATO!

📊 Risultati:
• Log eliminati: ${cleanupData.deleted_logs.toLocaleString()}
• Data limite: ${new Date(cleanupData.cutoff_date).toLocaleDateString('it-IT')}
${cleanupData.table_optimized ? '\n✨ Tabella database ottimizzata' : ''}

Il sistema è ora più veloce e ottimizzato.`;
            
            alert(successMessage);
            
            // Ricarica la pagina per aggiornare le statistiche
            setTimeout(() => window.location.reload(), 1500);
        } else {
            alert('❌ Errore durante il cleanup:\n' + (cleanupData.error || 'Errore sconosciuto'));
            btn.disabled = false;
            btn.innerHTML = '<span>🗑️</span><span>Cleanup Logs</span>';
        }
        
    } catch (error) {
        console.error('Errore cleanup:', error);
        alert('❌ Errore di comunicazione con il server:\n' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<span>🗑️</span><span>Cleanup Logs</span>';
    }
}
</script>

<?php
try {
    echo getAdminFooter();
} catch (Exception $e) {
    echo '</body></html>';
}
?>

