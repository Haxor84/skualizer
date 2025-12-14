<?php
/**
 * Admin Application Logs - Sistema Log Applicativi DB
 * File: admin/admin_log.php
 * 
 * Visualizza log da sync_debug_logs con filtri avanzati e ricerca
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'admin_helpers.php';
requireAdmin();
require_once '../config/config.php';

// === PARAMETRI REQUEST ===
$module = $_GET['module'] ?? 'all';
$level = $_GET['level'] ?? 'all';
$operationType = $_GET['operation_type'] ?? 'all';
$userId = !empty($_GET['user_id']) ? intval($_GET['user_id']) : null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+1 day'));
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = intval($_GET['limit'] ?? 50);

if ($page < 1) $page = 1;
if ($limit < 10) $limit = 10;
if ($limit > 500) $limit = 500;

// === CARICAMENTO DATI ===
try {
    $pdo = getDbConnection();
    
    // Get available modules (prefissi da operation_type)
    $modulesStmt = $pdo->query("
        SELECT DISTINCT SUBSTRING_INDEX(operation_type, '_', 1) as module
        FROM sync_debug_logs 
        ORDER BY module
    ");
    $availableModules = $modulesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get available operation types
    $operationStmt = $pdo->query("
        SELECT DISTINCT operation_type
        FROM sync_debug_logs 
        ORDER BY operation_type
    ");
    $availableOperations = $operationStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    if ($module !== 'all') {
        $where[] = 'operation_type LIKE :module';
        $params[':module'] = $module . '%';
    }
    
    if ($level !== 'all') {
        $where[] = 'log_level = :level';
        $params[':level'] = $level;
    }
    
    if ($operationType !== 'all') {
        $where[] = "operation_type = :operation_type";
        $params[':operation_type'] = $operationType;
    }
    
    if ($userId) {
        $where[] = 'user_id = :user_id';
        $params[':user_id'] = $userId;
    }
    
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom;
    
    $where[] = 'created_at <= :date_to';
    $params[':date_to'] = $dateTo;
    
    if (!empty($search)) {
        $where[] = '(message LIKE :search OR context_data LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }
    
    $whereClause = implode(' AND ', $where);
    
    // Count totale
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM sync_debug_logs WHERE $whereClause");
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Query logs con paginazione
    $offset = ($page - 1) * $limit;
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            created_at,
            operation_type,
            log_level,
            message,
            context_data,
            user_id,
            ip_address,
            execution_time_ms
        FROM sync_debug_logs 
        WHERE $whereClause
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalizza i log per il frontend
    foreach ($logs as &$log) {
        $log['module'] = substr($log['operation_type'], 0, strpos($log['operation_type'], '_') ?: strlen($log['operation_type']));
        $log['level'] = $log['log_level'];
        $log['context_json'] = $log['context_data'];
    }
    
    $pages = ceil($total / $limit);
        
    } catch (Exception $e) {
    die("Errore caricamento log: " . $e->getMessage());
}

// === HELPER FUNCTIONS ===
function getLevelClass($level) {
    switch (strtoupper($level)) {
        case 'CRITICAL':
        case 'ERROR':
            return 'error';
        case 'WARNING':
            return 'warning';
        case 'DEBUG':
            return 'debug';
        default:
            return 'info';
    }
}

$moduleIcons = [
    'inventory' => '📦',
    'settlement' => '💰',
    'oauth' => '🔐',
    'email_notifications' => '📧',
    'mapping' => '🔗',
    'orderinsights' => '📊',
    'admin' => '⚙️',
    'margini' => '📈',
    'historical' => '📜',
    'ai' => '🤖'
];

// === OUTPUT HTML ===
try {
    echo getAdminHeader('📋 Application Logs');
    echo getAdminNavigation('log');
} catch (Exception $e) {
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Application Logs</title>';
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
                <a href="admin_log_dashboard.php" class="log-sidebar-link">
                    <span class="log-sidebar-icon">🏠</span>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="log-sidebar-item">
                <a href="admin_log.php" class="log-sidebar-link active">
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
            <h1>📋 Application Logs</h1>
            <p>Log applicativi da database sync_debug_logs • <?php echo number_format($total); ?> log trovati</p>
    </div>

        <!-- Filters Bar -->
        <div class="log-filters-bar">
            <form method="GET" action="admin_log.php">
                <div class="log-filters-row">
                    <!-- Module Filter -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">📦 Modulo</label>
                        <select name="module" id="filter-module" class="log-filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $module === 'all' ? 'selected' : ''; ?>>Tutti i moduli</option>
                            <?php foreach ($availableModules as $mod): ?>
                                <option value="<?php echo htmlspecialchars($mod); ?>" <?php echo $module === $mod ? 'selected' : ''; ?>>
                                    <?php echo ($moduleIcons[$mod] ?? '📋') . ' ' . ucfirst($mod); ?>
                                </option>
        <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Level Filter -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">🎯 Livello</label>
                        <select name="level" id="filter-level" class="log-filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $level === 'all' ? 'selected' : ''; ?>>Tutti i livelli</option>
                            <option value="DEBUG" <?php echo $level === 'DEBUG' ? 'selected' : ''; ?>>DEBUG</option>
                            <option value="INFO" <?php echo $level === 'INFO' ? 'selected' : ''; ?>>INFO</option>
                            <option value="WARNING" <?php echo $level === 'WARNING' ? 'selected' : ''; ?>>WARNING</option>
                            <option value="ERROR" <?php echo $level === 'ERROR' ? 'selected' : ''; ?>>ERROR</option>
                            <option value="CRITICAL" <?php echo $level === 'CRITICAL' ? 'selected' : ''; ?>>CRITICAL</option>
                        </select>
    </div>

                    <!-- Operation Type Filter -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">⚙️ Operation Type</label>
                        <select name="operation_type" id="filter-operation-type" class="log-filter-select" onchange="this.form.submit()">
                            <option value="all" <?php echo $operationType === 'all' ? 'selected' : ''; ?>>Tutti i tipi</option>
                            <?php foreach ($availableOperations as $op): 
                                if (!$op) continue;
                                $op = trim($op, '"');
                            ?>
                                <option value="<?php echo htmlspecialchars($op); ?>" <?php echo $operationType === $op ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($op); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
                    <!-- User ID Filter -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">👤 User ID</label>
                        <input type="number" name="user_id" id="filter-user-id" 
                               class="log-filter-input" 
                               placeholder="ID utente"
                               value="<?php echo $userId ?: ''; ?>">
                    </div>
                </div>

                <div class="log-filters-row" style="margin-top: 15px;">
                    <!-- Date From -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">📅 Data Da</label>
                        <input type="date" name="date_from" id="filter-date-from" 
                               class="log-filter-input" 
                               value="<?php echo $dateFrom; ?>">
                    </div>

                    <!-- Date To -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">📅 Data A</label>
                        <input type="date" name="date_to" id="filter-date-to" 
                               class="log-filter-input" 
                               value="<?php echo $dateTo; ?>">
                    </div>

                    <!-- Search -->
                    <div class="log-filter-group log-filter-search">
                        <label class="log-filter-label">🔍 Ricerca Testo</label>
                        <input type="text" name="search" id="filter-search" 
                               class="log-filter-input" 
                               placeholder="Cerca in message e context..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <!-- Limit -->
                    <div class="log-filter-group">
                        <label class="log-filter-label">📄 Per Pagina</label>
                        <select name="limit" id="filter-limit" class="log-filter-select" onchange="this.form.submit()">
                            <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200</option>
            </select>
                    </div>
        </div>
        
                <div class="log-filters-row" style="margin-top: 15px; justify-content: space-between;">
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="log-btn log-btn-primary">
                            <span>🔍</span>
                            <span>Applica Filtri</span>
        </button>
                        
                        <a href="admin_log.php" class="log-btn log-btn-secondary">
                            <span>🔄</span>
                            <span>Reset</span>
                        </a>
        </div>
        
                    <div style="display: flex; gap: 10px;">
                        <a href="admin_log_api.php?action=export&format=csv&<?php echo http_build_query(array_filter(['module' => $module !== 'all' ? $module : null, 'level' => $level !== 'all' ? $level : null, 'date_from' => $dateFrom, 'date_to' => $dateTo])); ?>" 
                           class="log-btn log-btn-secondary log-btn-sm">
                            <span>📥</span>
                            <span>Export CSV</span>
                        </a>
                        
                        <a href="admin_log_api.php?action=export&format=json&<?php echo http_build_query(array_filter(['module' => $module !== 'all' ? $module : null, 'level' => $level !== 'all' ? $level : null, 'date_from' => $dateFrom, 'date_to' => $dateTo])); ?>" 
                           class="log-btn log-btn-secondary log-btn-sm">
                            <span>📥</span>
                            <span>Export JSON</span>
                        </a>
                    </div>
        </div>
            </form>
        </div>

    <!-- Logs Container -->
        <div class="log-entries-container" id="logs-container">
            <?php if (empty($logs)): ?>
                <div class="log-empty-state">
                    <div class="log-empty-icon">📄</div>
                    <h3 class="log-empty-title">Nessun Log Trovato</h3>
                    <p class="log-empty-message">Non ci sono log che corrispondono ai filtri selezionati.</p>
                    <?php if ($module !== 'all' || $level !== 'all' || !empty($search)): ?>
                        <a href="admin_log.php" class="log-btn log-btn-secondary">Reset Filtri</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
                <?php foreach ($logs as $log): 
                    $timestamp = date('d/m H:i:s', strtotime($log['created_at']));
                    $levelClass = getLevelClass($log['level']);
                    $context = json_decode($log['context_json'] ?? '{}', true);
                    $hasContext = !empty($context);
                    $contextCount = $hasContext ? count($context) : 0;
                ?>
                <div class="log-entry">
                        <div class="log-entry-header">
                            <div class="log-entry-timestamp"><?php echo $timestamp; ?></div>
                            <div class="log-entry-level <?php echo $levelClass; ?>"><?php echo $log['level']; ?></div>
                            <div class="log-entry-module">
                                <?php echo ($moduleIcons[$log['module']] ?? '📋') . ' ' . $log['module']; ?>
                        </div>
                            <div class="log-entry-message"><?php echo htmlspecialchars($log['message']); ?></div>
                    </div>
                    
                        <div class="log-entry-meta">
                        <?php if ($log['user_id']): ?>
                                <div class="log-entry-meta-item">
                                    <span class="log-entry-meta-label">👤 User:</span>
                                    <span><?php echo $log['user_id']; ?></span>
                                </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($log['execution_time_ms']) && $log['execution_time_ms'] > 0): ?>
                                <div class="log-entry-meta-item">
                                    <span class="log-entry-meta-label">⏱️ Time:</span>
                                    <span><?php echo $log['execution_time_ms']; ?>ms</span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($log['ip_address']): ?>
                                <div class="log-entry-meta-item">
                                    <span class="log-entry-meta-label">🌐 IP:</span>
                                    <span><?php echo htmlspecialchars($log['ip_address']); ?></span>
                                </div>
                        <?php endif; ?>
                        
                            <?php if ($hasContext): ?>
                                <div class="log-entry-meta-item">
                                    <span class="log-entry-meta-label">📋 Context:</span>
                                    <span><?php echo $contextCount; ?> items</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($hasContext): ?>
                            <div class="log-entry-context">
                                <button class="log-entry-context-toggle" data-log-id="<?php echo $log['id']; ?>" onclick="toggleContext(<?php echo $log['id']; ?>)">
                                    <span id="toggle-text-<?php echo $log['id']; ?>">▼ Mostra Context</span>
                                </button>
                                <div class="log-entry-context-content" id="context-<?php echo $log['id']; ?>">
<?php echo htmlspecialchars(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                    <?php endif; ?>
                </div>
        
        <!-- Pagination -->
        <?php if ($pages > 1): ?>
            <div class="log-pagination" id="pagination-container">
                <?php if ($page > 1): 
                    $prevQuery = http_build_query(array_merge($_GET, ['page' => $page - 1]));
                ?>
                    <a href="?<?php echo $prevQuery; ?>" class="log-pagination-btn">← Precedente</a>
            <?php endif; ?>
            
                <?php 
                $startPage = max(1, $page - 2);
                $endPage = min($pages, $page + 2);
                
                if ($startPage > 1): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" class="log-pagination-btn">1</a>
                    <?php if ($startPage > 2): ?>
                        <span class="log-pagination-info">...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): 
                    $activeClass = $i === $page ? 'active' : '';
                    $pageQuery = http_build_query(array_merge($_GET, ['page' => $i]));
                ?>
                    <a href="?<?php echo $pageQuery; ?>" class="log-pagination-btn <?php echo $activeClass; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            
                <?php if ($endPage < $pages): ?>
                    <?php if ($endPage < $pages - 1): ?>
                        <span class="log-pagination-info">...</span>
                    <?php endif; ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pages])); ?>" class="log-pagination-btn"><?php echo $pages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $pages): 
                    $nextQuery = http_build_query(array_merge($_GET, ['page' => $page + 1]));
                ?>
                    <a href="?<?php echo $nextQuery; ?>" class="log-pagination-btn">Successiva →</a>
            <?php endif; ?>
                
                <span class="log-pagination-info"><?php echo number_format($total); ?> log totali</span>
        </div>
        <?php endif; ?>
    </main>
</div>

<script>
// Toggle context inline
function toggleContext(logId) {
    const content = document.getElementById(`context-${logId}`);
    const toggleText = document.getElementById(`toggle-text-${logId}`);
    
    if (content && toggleText) {
        content.classList.toggle('expanded');
        const isExpanded = content.classList.contains('expanded');
        toggleText.textContent = isExpanded ? '▲ Nascondi Context' : '▼ Mostra Context';
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Focus search: /
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT') {
        e.preventDefault();
        document.getElementById('filter-search').focus();
    }
    
    // Toggle errors: E
    if (e.key === 'e' && document.activeElement.tagName !== 'INPUT') {
        const levelSelect = document.getElementById('filter-level');
        levelSelect.value = levelSelect.value === 'ERROR' ? 'all' : 'ERROR';
        levelSelect.form.submit();
    }
});

console.log('📋 Application Logs System caricato con successo!');
console.log('Shortcuts: "/" per ricerca, "E" per toggle errori');
</script>

<?php
try {
    echo getAdminFooter();
} catch (Exception $e) {
    echo '</body></html>';
}
?>
