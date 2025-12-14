<?php
/**
 * Admin Log API - Backend JSON per Log System Dashboard
 * File: admin/admin_log_api.php
 * 
 * Gestisce tutte le richieste AJAX per dashboard, filtri, ricerca, export
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'admin_helpers.php';
requireAdmin();
require_once '../config/config.php';

header('Content-Type: application/json; charset=utf-8');

// === PARAMETRI REQUEST ===
$action = $_GET['action'] ?? 'logs';

try {
    $pdo = getDbConnection();
    
    switch ($action) {
        case 'dashboard_stats':
            echo json_encode(getDashboardStats($pdo));
            break;
            
        case 'logs':
            echo json_encode(getFilteredLogs($pdo));
            break;
            
        case 'search':
            echo json_encode(searchLogs($pdo));
            break;
            
        case 'top_errors':
            echo json_encode(getTopErrors($pdo));
            break;
            
        case 'chart_data':
            echo json_encode(getChartData($pdo));
            break;
            
        case 'export':
            exportLogs($pdo);
            break;
            
        case 'check_new_logs':
            echo json_encode(checkNewLogs($pdo));
            break;
            
        case 'cleanup':
            echo json_encode(cleanupOldLogs($pdo));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

// === FUNZIONI API ===

/**
 * Dashboard Stats - Statistiche overview
 */
function getDashboardStats($pdo) {
    $stats = [];
    
    // Ultimi 7 giorni
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_logs,
            SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END) as errors,
            SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END) as warnings,
            COUNT(DISTINCT user_id) as unique_users,
            COUNT(DISTINCT operation_type) as active_modules
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stats['last_7_days'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Oggi
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_logs,
            SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END) as errors,
            SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END) as warnings
        FROM sync_debug_logs 
        WHERE DATE(created_at) = CURDATE()
    ");
    $stats['today'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Per operation_type (ultimi 7 giorni) - Raggruppiamo per prefisso
    $stmt = $pdo->query("
        SELECT 
            SUBSTRING_INDEX(operation_type, '_', 1) as module,
            COUNT(*) as count
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY module
        ORDER BY count DESC
    ");
    $stats['by_module'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Health Score (semplice: 100 - percentuale errori critici)
    $errorRate = $stats['last_7_days']['total_logs'] > 0 
        ? ($stats['last_7_days']['errors'] / $stats['last_7_days']['total_logs']) * 100 
        : 0;
    $stats['health_score'] = max(0, min(100, 100 - ($errorRate * 10)));
    
    // Tempo medio esecuzione (da execution_time_ms)
    $stmt = $pdo->query("
        SELECT AVG(execution_time_ms) / 1000 as avg_duration
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND execution_time_ms IS NOT NULL
    ");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['avg_duration_seconds'] = $result['avg_duration'] ? round($result['avg_duration'], 2) : 0;
    
    return $stats;
}

/**
 * Filtered Logs - Query con filtri avanzati
 */
function getFilteredLogs($pdo) {
    // Parametri
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
    
    $offset = ($page - 1) * $limit;
    
    // Build query
    $where = ['1=1'];
    $params = [];
    
    // Filtro per modulo (basato su operation_type prefix)
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
    
    // Query logs
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
    
    // Parse context_data e normalizza per compatibilità frontend
    foreach ($logs as &$log) {
        $log['context'] = json_decode($log['context_data'] ?? '{}', true);
        $log['module'] = substr($log['operation_type'], 0, strpos($log['operation_type'], '_') ?: strlen($log['operation_type']));
        $log['level'] = $log['log_level'];
        unset($log['context_data']);
        unset($log['log_level']);
    }
    
    return [
        'logs' => $logs,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ],
        'filters' => [
            'module' => $module,
            'level' => $level,
            'operation_type' => $operationType,
            'user_id' => $userId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'search' => $search
        ]
    ];
}

/**
 * Search Logs - Full-text search
 */
function searchLogs($pdo) {
    $query = $_GET['q'] ?? '';
    $limit = intval($_GET['limit'] ?? 20);
    
    if (empty($query)) {
        return ['results' => [], 'count' => 0];
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            created_at,
            operation_type,
            log_level,
            message,
            context_data,
            user_id
        FROM sync_debug_logs 
        WHERE message LIKE :query 
        OR context_data LIKE :query
        ORDER BY created_at DESC
        LIMIT :limit
    ");
    
    $stmt->execute([
        ':query' => '%' . $query . '%',
        ':limit' => $limit
    ]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse context e normalizza
    foreach ($results as &$result) {
        $result['context'] = json_decode($result['context_data'] ?? '{}', true);
        $result['module'] = substr($result['operation_type'], 0, strpos($result['operation_type'], '_') ?: strlen($result['operation_type']));
        $result['level'] = $result['log_level'];
        unset($result['context_data']);
        unset($result['log_level']);
    }
    
    return [
        'results' => $results,
        'count' => count($results),
        'query' => $query
    ];
}

/**
 * Top Errors - Errori più frequenti
 */
function getTopErrors($pdo) {
    $days = intval($_GET['days'] ?? 7);
    
    $stmt = $pdo->prepare("
        SELECT 
            message,
            COUNT(*) as occurrences,
            MAX(created_at) as last_occurrence,
            operation_type,
            log_level as level
        FROM sync_debug_logs 
        WHERE log_level = 'ERROR'
        AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
        GROUP BY message, operation_type, log_level
        ORDER BY occurrences DESC
        LIMIT 10
    ");
    
    $stmt->execute([':days' => $days]);
    $errors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Normalizza module
    foreach ($errors as &$error) {
        $error['module'] = substr($error['operation_type'], 0, strpos($error['operation_type'], '_') ?: strlen($error['operation_type']));
    }
    
    return $errors;
}

/**
 * Chart Data - Dati per grafici trend
 */
function getChartData($pdo) {
    $days = intval($_GET['days'] ?? 30);
    
    $stmt = $pdo->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as total,
            SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END) as errors,
            SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END) as warnings,
            SUM(CASE WHEN log_level = 'INFO' THEN 1 ELSE 0 END) as info
        FROM sync_debug_logs 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    
    $stmt->execute([':days' => $days]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatta per Chart.js
    $labels = [];
    $totals = [];
    $errors = [];
    $warnings = [];
    $info = [];
    
    foreach ($data as $row) {
        $labels[] = date('d/m', strtotime($row['date']));
        $totals[] = (int)$row['total'];
        $errors[] = (int)$row['errors'];
        $warnings[] = (int)$row['warnings'];
        $info[] = (int)$row['info'];
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            ['label' => 'Totale', 'data' => $totals, 'color' => '#059669'],
            ['label' => 'Errori', 'data' => $errors, 'color' => '#dc2626'],
            ['label' => 'Warning', 'data' => $warnings, 'color' => '#f59e0b'],
            ['label' => 'Info', 'data' => $info, 'color' => '#2563eb']
        ]
    ];
}

/**
 * Export Logs - CSV o JSON
 */
function exportLogs($pdo) {
    $format = $_GET['format'] ?? 'csv';
    
    // Usa stessi filtri di getFilteredLogs
    $module = $_GET['module'] ?? 'all';
    $level = $_GET['level'] ?? 'all';
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+1 day'));
    
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
    
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom;
    
    $where[] = 'created_at <= :date_to';
    $params[':date_to'] = $dateTo;
    
    $whereClause = implode(' AND ', $where);
    
    $stmt = $pdo->prepare("
        SELECT 
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
        LIMIT 5000
    ");
    
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="margynomic_logs_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Header CSV
        fputcsv($output, ['Timestamp', 'Operation Type', 'Level', 'Message', 'User ID', 'IP', 'Execution MS', 'Context']);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['created_at'],
                $log['operation_type'],
                $log['log_level'],
                $log['message'],
                $log['user_id'],
                $log['ip_address'],
                $log['execution_time_ms'],
                $log['context_data']
            ]);
        }
        
        fclose($output);
        
    } else if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="margynomic_logs_' . date('Y-m-d') . '.json"');
        
        // Parse context e normalizza
        foreach ($logs as &$log) {
            $log['context'] = json_decode($log['context_data'] ?? '{}', true);
            $log['module'] = substr($log['operation_type'], 0, strpos($log['operation_type'], '_') ?: strlen($log['operation_type']));
            $log['level'] = $log['log_level'];
            unset($log['context_data']);
            unset($log['log_level']);
        }
        
        echo json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    exit;
}

/**
 * Check New Logs - Polling per nuovi log
 */
function checkNewLogs($pdo) {
    $lastId = intval($_GET['last_id'] ?? 0);
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as new_logs
        FROM sync_debug_logs 
        WHERE id > :last_id
    ");
    
    $stmt->execute([':last_id' => $lastId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return [
        'new_logs' => $result['new_logs'],
        'last_check' => date('c')
    ];
}

/**
 * Cleanup log più vecchi di N giorni
 */
function cleanupOldLogs($pdo) {
    try {
        // Parametri di cleanup
        $daysToKeep = isset($_GET['days']) ? (int)$_GET['days'] : 30;
        $dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === 'true';
        
        // Validazione
        if ($daysToKeep < 7) {
            return [
                'success' => false,
                'error' => 'Impossibile eliminare log più recenti di 7 giorni'
            ];
        }
        
        if ($daysToKeep > 365) {
            return [
                'success' => false,
                'error' => 'Periodo massimo: 365 giorni'
            ];
        }
        
        // Calcola data limite
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
        
        // Conta log da eliminare (per anteprima)
        $countStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_logs,
                MIN(created_at) as oldest_log,
                MAX(created_at) as newest_old_log,
                SUM(CASE WHEN log_level = 'ERROR' THEN 1 ELSE 0 END) as errors,
                SUM(CASE WHEN log_level = 'WARNING' THEN 1 ELSE 0 END) as warnings
            FROM sync_debug_logs 
            WHERE created_at < :cutoff_date
        ");
        
        $countStmt->execute([':cutoff_date' => $cutoffDate]);
        $stats = $countStmt->fetch(PDO::FETCH_ASSOC);
        
        // Se dry run, ritorna solo statistiche
        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'cutoff_date' => $cutoffDate,
                'days_to_keep' => $daysToKeep,
                'logs_to_delete' => (int)$stats['total_logs'],
                'oldest_log' => $stats['oldest_log'],
                'newest_old_log' => $stats['newest_old_log'],
                'errors_to_delete' => (int)$stats['errors'],
                'warnings_to_delete' => (int)$stats['warnings']
            ];
        }
        
        // Esegui cleanup REALE
        $deleteStmt = $pdo->prepare("
            DELETE FROM sync_debug_logs 
            WHERE created_at < :cutoff_date
        ");
        
        $deleteStmt->execute([':cutoff_date' => $cutoffDate]);
        $deletedRows = $deleteStmt->rowCount();
        
        // Log dell'operazione di cleanup
        CentralLogger::log('admin', 'INFO', 
            sprintf('Cleanup logs completato: %d log eliminati (più vecchi di %d giorni)', 
                $deletedRows, $daysToKeep),
            [
                'deleted_logs' => $deletedRows,
                'cutoff_date' => $cutoffDate,
                'days_to_keep' => $daysToKeep,
                'admin_user' => $_SESSION['user_id'] ?? 'unknown'
            ]
        );
        
        // Ottimizza tabella dopo cleanup massivo
        if ($deletedRows > 1000) {
            $pdo->exec("OPTIMIZE TABLE sync_debug_logs");
        }
        
        return [
            'success' => true,
            'deleted_logs' => $deletedRows,
            'cutoff_date' => $cutoffDate,
            'days_to_keep' => $daysToKeep,
            'table_optimized' => $deletedRows > 1000
        ];
        
    } catch (PDOException $e) {
        CentralLogger::log('admin', 'ERROR', 
            sprintf('Errore cleanup logs: %s', $e->getMessage()));
        
        return [
            'success' => false,
            'error' => 'Errore durante il cleanup: ' . $e->getMessage()
        ];
    }
}

