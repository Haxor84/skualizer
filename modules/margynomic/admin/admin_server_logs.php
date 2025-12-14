<?php
/**
 * Admin Server Logs Viewer - Sistema Monitoraggio Log Server
 * File: admin/admin_server_logs.php
 * 
 * Visualizza log critici del server hosting per debug Margynomic
 * REFACTORED: Con sidebar unificata e design system
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'admin_helpers.php';
requireAdmin();

// === CONFIGURAZIONE LOG SERVER ===
$serverLogs = [
    'error' => '../../../../logs/error_log',
    'access' => '../../../../logs/access_log', 
    'proxy' => '../../../../logs/proxy_error_log'
];

// === PARAMETRI REQUEST ===
$activeTab = $_GET['tab'] ?? 'error';
$limit = intval($_GET['limit'] ?? 50);
$refresh = intval($_GET['refresh'] ?? 30);

if ($limit < 10) $limit = 10;
if ($limit > 200) $limit = 200;

// === FUNZIONI PARSING LOG ===

/**
 * Parsa error_log del server - OTTIMIZZATO per file grandi
 */
function parseErrorLog($filePath, $limit = 50) {
    $logs = [];
    
    if (!file_exists($filePath)) {
        return $logs;
    }
    
    try {
        // LIMITE RIGHE per evitare memory exhausted (max 1000 righe)
        $maxLines = min($limit * 2, 1000);
        
        // Usa tail per leggere solo le ultime righe (efficiente)
        $lines = [];
        $handle = fopen($filePath, 'r');
        if ($handle) {
            fseek($handle, -1, SEEK_END);
            $fileSize = ftell($handle);
            
            // Leggi max 5MB dall'ultima parte del file
            $readSize = min(5 * 1024 * 1024, $fileSize);
            fseek($handle, -$readSize, SEEK_END);
            $content = fread($handle, $readSize);
            fclose($handle);
            
            $lines = explode("\n", $content);
            $lines = array_filter($lines); // Rimuovi righe vuote
        }
        
        $recentLines = array_slice($lines, -$maxLines);
        
        foreach ($recentLines as $line) {
            // Pattern: [Sat Aug 02 20:15:59.686288 2025] [fcgid:warn] [pid 12474:tid 140019951920896] [client 78.209.11.211:48912] mod_fcgid: stderr: PHP Warning: ...
            if (preg_match('/\[(.*?)\] \[(.*?)\] \[(.*?)\] \[client (.*?)\] (.*)/', $line, $matches)) {
                $timestamp = $matches[1];
                $level = $matches[2];
                $process = $matches[3];
                $client = $matches[4];
                $message = $matches[5];
                
                // Determina severità
                $severity = 'info';
                if (stripos($level, 'error') !== false || stripos($message, 'fatal') !== false) {
                    $severity = 'error';
                } elseif (stripos($level, 'warn') !== false || stripos($message, 'warning') !== false) {
                    $severity = 'warning';
                }
                
                $logs[] = [
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'severity' => $severity,
                    'process' => $process,
                    'client_ip' => $client,
                    'message' => $message,
                    'raw_line' => $line
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Errore parsing error log: " . $e->getMessage());
    }
    
    return array_reverse($logs); // Più recenti prima
}

/**
 * Parsa access_log del server
 */
function parseAccessLog($filePath, $limit = 50) {
    $logs = [];
    
    if (!file_exists($filePath)) {
        return $logs;
    }
    
    try {
        // LIMITE RIGHE per evitare memory exhausted (max 1000 righe)
        $maxLines = min($limit * 2, 1000);
        
        // Leggi solo ultimi 5MB del file
        $lines = [];
        $handle = fopen($filePath, 'r');
        if ($handle) {
            fseek($handle, -1, SEEK_END);
            $fileSize = ftell($handle);
            $readSize = min(5 * 1024 * 1024, $fileSize);
            fseek($handle, -$readSize, SEEK_END);
            $content = fread($handle, $readSize);
            fclose($handle);
            $lines = explode("\n", $content);
            $lines = array_filter($lines);
        }
        
        $recentLines = array_slice($lines, -$maxLines);
        
        foreach ($recentLines as $line) {
            // Pattern: 91.99.23.109 - - [02/Aug/2025:05:00:21 +0200] "POST /modules/margynomic/sincro/batch_processor.php HTTP/1.0" 200 3833 "-" "Mozilla/4.0"
            if (preg_match('/^(\S+) \S+ \S+ \[(.*?)\] "(\S+) (.*?) (.*?)" (\d+) (\d+) "(.*?)" "(.*?)"/', $line, $matches)) {
                $ip = $matches[1];
                $timestamp = $matches[2];
                $method = $matches[3];
                $url = $matches[4];
                $protocol = $matches[5];
                $status = intval($matches[6]);
                $size = intval($matches[7]);
                $referer = $matches[8];
                $userAgent = $matches[9];
                
                // Determina severità in base allo status
                $severity = 'info';
                if ($status >= 500) {
                    $severity = 'error';
                } elseif ($status >= 400) {
                    $severity = 'warning';
                }
                
                // Filtra solo richieste relative a Margynomic
                if (stripos($url, 'margynomic') !== false || stripos($url, 'previsync') !== false) {
                    $logs[] = [
                        'timestamp' => $timestamp,
                        'ip' => $ip,
                        'method' => $method,
                        'url' => $url,
                        'status' => $status,
                        'severity' => $severity,
                        'size' => $size,
                        'user_agent' => $userAgent,
                        'raw_line' => $line
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Errore parsing access log: " . $e->getMessage());
    }
    
    return array_reverse($logs);
}

/**
 * Parsa proxy_error_log del server
 */
function parseProxyLog($filePath, $limit = 50) {
    $logs = [];
    
    if (!file_exists($filePath)) {
        return $logs;
    }
    
    try {
        // LIMITE RIGHE per evitare memory exhausted (max 1000 righe)
        $maxLines = min($limit * 2, 1000);
        
        // Leggi solo ultimi 5MB del file
        $lines = [];
        $handle = fopen($filePath, 'r');
        if ($handle) {
            fseek($handle, -1, SEEK_END);
            $fileSize = ftell($handle);
            $readSize = min(5 * 1024 * 1024, $fileSize);
            fseek($handle, -$readSize, SEEK_END);
            $content = fread($handle, $readSize);
            fclose($handle);
            $lines = explode("\n", $content);
            $lines = array_filter($lines);
        }
        
        $recentLines = array_slice($lines, -$maxLines);
        
        foreach ($recentLines as $line) {
            // Pattern generico per proxy errors
            if (preg_match('/\[(.*?)\] \[(.*?)\] (.*)/', $line, $matches)) {
                $timestamp = $matches[1];
                $level = $matches[2];
                $message = $matches[3];
                
                $severity = 'info';
                if (stripos($level, 'error') !== false) {
                    $severity = 'error';
                } elseif (stripos($level, 'warn') !== false) {
                    $severity = 'warning';
                }
                
                $logs[] = [
                    'timestamp' => $timestamp,
                    'level' => $level,
                    'severity' => $severity,
                    'message' => $message,
                    'raw_line' => $line
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Errore parsing proxy log: " . $e->getMessage());
    }
    
    return array_reverse($logs);
}

// === RACCOLTA DATI ===
$logs = [];
$stats = ['total' => 0, 'error' => 0, 'warning' => 0, 'info' => 0];

switch ($activeTab) {
    case 'error':
        $logs = parseErrorLog($serverLogs['error'], $limit);
        break;
    case 'access':
        $logs = parseAccessLog($serverLogs['access'], $limit);
        break;
    case 'proxy':
        $logs = parseProxyLog($serverLogs['proxy'], $limit);
        break;
}

// Calcola statistiche
foreach ($logs as $log) {
    $stats['total']++;
    $stats[$log['severity']]++;
}

// === OUTPUT HTML ===
try {
    echo getAdminHeader('🔥 Log Server Sistema');
    echo getAdminNavigation('server_logs');
} catch (Exception $e) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Server Logs</title>';
    echo '<link rel="stylesheet" href="assets/admin_logs.css"></head><body>';
}
?>

<link rel="stylesheet" href="assets/admin_logs.css">

<style>
/* === STILI SPECIFICI PER SERVER LOGS === */
.server-logs-container { 
    max-width: 1600px; 
    margin: 0 auto; 
    padding: 20px; 
}

.page-header { 
    background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); 
    color: white; 
    padding: 30px; 
    border-radius: 12px; 
    margin-bottom: 30px; 
    text-align: center;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.page-header h1 { 
    margin: 0 0 10px 0; 
    font-size: 2.5rem; 
    font-weight: 700; 
}

.page-header p { 
    margin: 0; 
    font-size: 1.1rem; 
    opacity: 0.9; 
}

/* Tab System */
.tabs-container { 
    background: white; 
    border-radius: 12px; 
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); 
    overflow: hidden;
}

.tabs-nav { 
    display: flex; 
    background: #f8fafc; 
    border-bottom: 2px solid #e2e8f0; 
}

.tab-btn { 
    flex: 1; 
    padding: 15px 20px; 
    border: none; 
    background: transparent; 
    font-weight: 600; 
    font-size: 1rem; 
    cursor: pointer; 
    transition: all 0.3s ease;
    color: #64748b;
}

.tab-btn:hover { 
    background: #e2e8f0; 
    color: #334155;
}

.tab-btn.active { 
    background: white; 
    color: #dc2626; 
    border-bottom: 3px solid #dc2626;
    transform: translateY(2px);
}

/* Stats Cards */
.stats-grid { 
    display: grid; 
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); 
    gap: 20px; 
    margin: 20px 0; 
}

.stat-card { 
    background: white; 
    padding: 20px; 
    border-radius: 10px; 
    text-align: center; 
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    border-left: 4px solid #64748b;
}

.stat-card.error { border-left-color: #dc2626; }
.stat-card.warning { border-left-color: #f59e0b; }
.stat-card.info { border-left-color: #059669; }

.stat-number { 
    font-size: 2rem; 
    font-weight: 700; 
    margin-bottom: 5px; 
}

.stat-label { 
    color: #64748b; 
    font-weight: 600; 
    text-transform: uppercase; 
    font-size: 0.8rem; 
}

/* Controls */
.controls-bar { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    padding: 20px; 
    background: #f8fafc; 
    border-bottom: 1px solid #e2e8f0;
}

.controls-left { 
    display: flex; 
    gap: 15px; 
    align-items: center; 
}

.controls-right { 
    display: flex; 
    gap: 10px; 
    align-items: center; 
}

.form-select, .form-input { 
    padding: 8px 12px; 
    border: 2px solid #e2e8f0; 
    border-radius: 6px; 
    font-size: 0.9rem;
    background: white;
}

.form-select:focus, .form-input:focus { 
    outline: none; 
    border-color: #dc2626; 
}

/* Log Entries */
.logs-container { 
    padding: 20px; 
    max-height: 70vh; 
    overflow-y: auto; 
}

.log-entry { 
    border: 1px solid #e2e8f0; 
    border-radius: 8px; 
    margin-bottom: 15px; 
    overflow: hidden;
    background: white;
    transition: all 0.2s ease;
}

.log-entry:hover { 
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); 
    transform: translateY(-1px);
}

.log-header { 
    display: flex; 
    align-items: center; 
    gap: 15px; 
    padding: 15px 20px; 
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}

.log-timestamp { 
    font-family: 'Courier New', monospace; 
    font-size: 0.85rem; 
    color: #64748b; 
    background: white; 
    padding: 4px 8px; 
    border-radius: 4px;
    min-width: 200px;
}

.log-severity { 
    padding: 4px 12px; 
    border-radius: 20px; 
    font-size: 0.75rem; 
    font-weight: 700; 
    text-transform: uppercase; 
    letter-spacing: 0.5px;
}

.log-severity.error { background: #fee2e2; color: #dc2626; }
.log-severity.warning { background: #fef3c7; color: #d97706; }
.log-severity.info { background: #d1fae5; color: #059669; }

.log-meta { 
    flex: 1; 
    font-size: 0.85rem; 
    color: #64748b;
}

.log-body { 
    padding: 15px 20px; 
}

.log-message { 
    font-family: 'Courier New', monospace; 
    font-size: 0.9rem; 
    line-height: 1.6; 
    color: #1f2937;
    background: #f9fafb;
    padding: 15px;
    border-radius: 6px;
    word-break: break-all;
}

/* Status badges per access log */
.status-badge { 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 0.75rem; 
    font-weight: 700; 
    font-family: monospace;
}

.status-2xx { background: #d1fae5; color: #059669; }
.status-3xx { background: #dbeafe; color: #2563eb; }
.status-4xx { background: #fef3c7; color: #d97706; }
.status-5xx { background: #fee2e2; color: #dc2626; }

/* Buttons */
.btn { 
    padding: 8px 16px; 
    border: none; 
    border-radius: 6px; 
    font-weight: 600; 
    font-size: 0.9rem; 
    cursor: pointer; 
    text-decoration: none; 
    display: inline-flex; 
    align-items: center; 
    gap: 6px; 
    transition: all 0.2s;
}

.btn-primary { 
    background: #dc2626; 
    color: white; 
}

.btn-primary:hover { 
    background: #b91c1c; 
}

.btn-secondary { 
    background: #e2e8f0; 
    color: #475569; 
}

.btn-secondary:hover { 
    background: #cbd5e0; 
}

/* Auto-refresh indicator */
.refresh-indicator { 
    display: flex; 
    align-items: center; 
    gap: 8px; 
    font-size: 0.85rem; 
    color: #64748b;
}

.refresh-dot { 
    width: 8px; 
    height: 8px; 
    background: #22c55e; 
    border-radius: 50%; 
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Empty state */
.empty-state { 
    text-align: center; 
    padding: 60px 20px; 
    color: #64748b;
}

.empty-icon { 
    font-size: 4rem; 
    margin-bottom: 20px; 
}

/* Responsive */
@media (max-width: 768px) {
    .server-logs-container { padding: 10px; }
    .tabs-nav { flex-direction: column; }
    .tab-btn { text-align: left; }
    .controls-bar { flex-direction: column; gap: 15px; }
    .controls-left, .controls-right { width: 100%; justify-content: center; }
    .log-header { flex-direction: column; align-items: flex-start; gap: 10px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="log-system-container">
    <!-- Sidebar Navigation -->
    <nav class="log-sidebar">
        <div class="log-sidebar-header">
            <h2 class="log-sidebar-title">📊 LOG SYSTEM</h2>
        </div>
        
        <ul class="log-sidebar-nav">
            <li class="log-sidebar-item">
                <a href="admin_log_dashboard.php" class="log-sidebar-link">
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
                <a href="admin_server_logs.php" class="log-sidebar-link active">
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
        <!-- Header -->
        <div class="log-page-header danger">
            <h1>🔥 Log Server Sistema</h1>
            <p>Monitoraggio file log server: error_log | access_log | proxy_error_log</p>
        </div>

    <!-- Stats Overview -->
    <div class="log-stats-grid">
        <div class="log-stat-card">
            <div class="log-stat-icon">📝</div>
            <div class="log-stat-value"><?php echo $stats['total']; ?></div>
            <div class="log-stat-label">Log Totali</div>
        </div>
        <div class="log-stat-card danger">
            <div class="log-stat-icon">❌</div>
            <div class="log-stat-value"><?php echo $stats['error']; ?></div>
            <div class="log-stat-label">Errori</div>
        </div>
        <div class="log-stat-card warning">
            <div class="log-stat-icon">⚠️</div>
            <div class="log-stat-value"><?php echo $stats['warning']; ?></div>
            <div class="log-stat-label">Warning</div>
        </div>
        <div class="log-stat-card info">
            <div class="log-stat-icon">ℹ️</div>
            <div class="log-stat-value"><?php echo $stats['info']; ?></div>
            <div class="log-stat-label">Info</div>
        </div>
    </div>

    <!-- Tab System -->
    <div class="tabs-container">
        <!-- Tab Navigation -->
        <div class="tabs-nav">
            <a href="?tab=error&limit=<?php echo $limit; ?>&refresh=<?php echo $refresh; ?>" 
               class="tab-btn <?php echo $activeTab === 'error' ? 'active' : ''; ?>">
                🔥 error_log
            </a>
            <a href="?tab=access&limit=<?php echo $limit; ?>&refresh=<?php echo $refresh; ?>" 
               class="tab-btn <?php echo $activeTab === 'access' ? 'active' : ''; ?>">
                📊 access_log
            </a>
            <a href="?tab=proxy&limit=<?php echo $limit; ?>&refresh=<?php echo $refresh; ?>" 
               class="tab-btn <?php echo $activeTab === 'proxy' ? 'active' : ''; ?>">
                ⚠️ proxy_error_log
            </a>
        </div>

        <!-- Controls Bar -->
        <div class="controls-bar">
            <div class="controls-left">
                <label for="limit">Mostra:</label>
                <select id="limit" class="form-select" onchange="updateLimit(this.value)">
                    <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25 log</option>
                    <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50 log</option>
                    <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100 log</option>
                    <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200 log</option>
                </select>

                <label for="refresh">Auto-refresh:</label>
                <select id="refresh" class="form-select" onchange="updateRefresh(this.value)">
                    <option value="0" <?php echo $refresh === 0 ? 'selected' : ''; ?>>Disabilitato</option>
                    <option value="15" <?php echo $refresh === 15 ? 'selected' : ''; ?>>15 secondi</option>
                    <option value="30" <?php echo $refresh === 30 ? 'selected' : ''; ?>>30 secondi</option>
                    <option value="60" <?php echo $refresh === 60 ? 'selected' : ''; ?>>60 secondi</option>
                </select>
            </div>

            <div class="controls-right">
                <?php if ($refresh > 0): ?>
                <div class="refresh-indicator">
                    <div class="refresh-dot"></div>
                    Auto-refresh attivo (<?php echo $refresh; ?>s)
                </div>
                <?php endif; ?>

                <button onclick="window.location.reload()" class="btn btn-secondary">
                    🔄 Refresh
                </button>
            </div>
        </div>

        <!-- Logs Container -->
        <div class="logs-container">
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📄</div>
                    <h3>Nessun log trovato</h3>
                    <p>Non ci sono log disponibili per questa categoria o il file non esiste.</p>
                </div>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <div class="log-entry">
                        <div class="log-header">
                            <div class="log-timestamp">
                                <?php echo htmlspecialchars($log['timestamp']); ?>
                            </div>
                            
                            <div class="log-severity <?php echo $log['severity']; ?>">
                                <?php echo strtoupper($log['severity']); ?>
                            </div>

                            <div class="log-meta">
                                <?php if ($activeTab === 'error'): ?>
                                    <strong>Process:</strong> <?php echo htmlspecialchars($log['process'] ?? 'N/A'); ?> |
                                    <strong>Client:</strong> <?php echo htmlspecialchars($log['client_ip'] ?? 'N/A'); ?>
                                <?php elseif ($activeTab === 'access'): ?>
                                    <strong>IP:</strong> <?php echo htmlspecialchars($log['ip']); ?> |
                                    <strong>Method:</strong> <?php echo htmlspecialchars($log['method']); ?> |
                                    <span class="status-badge status-<?php echo substr($log['status'], 0, 1); ?>xx">
                                        <?php echo $log['status']; ?>
                                    </span> |
                                    <strong>Size:</strong> <?php echo number_format($log['size']); ?> bytes
                                <?php elseif ($activeTab === 'proxy'): ?>
                                    <strong>Level:</strong> <?php echo htmlspecialchars($log['level'] ?? 'N/A'); ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="log-body">
                            <?php if ($activeTab === 'access'): ?>
                                <div class="log-message">
                                    <strong>URL:</strong> <?php echo htmlspecialchars($log['url']); ?><br>
                                    <strong>User Agent:</strong> <?php echo htmlspecialchars(substr($log['user_agent'], 0, 100)); ?>
                                    <?php if (strlen($log['user_agent']) > 100): ?>...<?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="log-message">
                                    <?php echo htmlspecialchars($log['message']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    </main>
</div>

<script>
// === JAVASCRIPT FUNCTIONS ===

function updateLimit(newLimit) {
    const url = new URL(window.location);
    url.searchParams.set('limit', newLimit);
    window.location = url.toString();
}

function updateRefresh(newRefresh) {
    const url = new URL(window.location);
    url.searchParams.set('refresh', newRefresh);
    window.location = url.toString();
}

// Auto-refresh functionality
<?php if ($refresh > 0): ?>
let refreshTimer;
let countdown = <?php echo $refresh; ?>;

function startRefreshTimer() {
    refreshTimer = setInterval(function() {
        countdown--;
        if (countdown <= 0) {
            window.location.reload();
        }
    }, 1000);
}

// Start timer
startRefreshTimer();

// Pause timer when user is interacting
document.addEventListener('click', function() {
    clearInterval(refreshTimer);
    setTimeout(startRefreshTimer, 5000); // Resume after 5 seconds
});
<?php endif; ?>

console.log('🔥 Server Logs System caricato con successo!');
console.log('📊 File log attivo: <?php echo $activeTab; ?>_log');
console.log('📈 Log visualizzati: <?php echo count($logs); ?>');
</script>

<?php 
try {
    echo getAdminFooter();
} catch (Exception $e) {
    echo '</body></html>';
}
?>