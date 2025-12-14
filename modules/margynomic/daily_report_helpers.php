<?php
/**
 * Daily Report Helpers - Enhanced Metrics & Analytics
 * File: daily_report_helpers.php
 * 
 * Funzioni helper per daily report 2.0:
 * - Database statistics
 * - API performance metrics
 * - Trend analysis
 * - Snapshot management
 */

require_once __DIR__ . '/config/config.php';

class DailyReportHelpers {
    
    /**
     * Get detailed database statistics
     */
    public static function getDatabaseStats() {
        try {
            $pdo = getDbConnection();
            
            // Table sizes and row counts
            $stmt = $pdo->query("
                SELECT 
                    table_name,
                    table_rows,
                    ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                    ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                    ROUND(index_length / 1024 / 1024, 2) AS index_mb
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                AND table_name IN (
                    'report_settlement_1', 'report_settlement_2', 'report_settlement_7', 
                    'report_settlement_8', 'report_settlement_9', 'report_settlement_10',
                    'inventory', 'inventory_fbm', 'sync_debug_logs', 'api_debug_log'
                )
                ORDER BY (data_length + index_length) DESC
            ");
            
            $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate totals
            $totalSize = 0;
            $totalRows = 0;
            foreach ($tables as $table) {
                $totalSize += $table['size_mb'];
                $totalRows += $table['table_rows'];
            }
            
            // Get growth rate (compare with yesterday's snapshot if exists)
            $growthRate = self::calculateDatabaseGrowth($pdo);
            
            return [
                'tables' => $tables,
                'total_size_mb' => round($totalSize, 2),
                'total_rows' => $totalRows,
                'growth_rate_mb_per_day' => $growthRate
            ];
            
        } catch (Exception $e) {
            CentralLogger::log('daily_report', 'ERROR', 
                "Error getting database stats: " . $e->getMessage());
            return ['tables' => [], 'total_size_mb' => 0, 'total_rows' => 0];
        }
    }
    
    /**
     * Calculate database growth rate
     */
    private static function calculateDatabaseGrowth($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT metric_value 
                FROM daily_metrics_snapshot 
                WHERE metric_category = 'database' 
                AND metric_name = 'total_size_mb'
                AND metric_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
            ");
            
            $yesterday = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($yesterday) {
                // Get current size
                $stmt = $pdo->query("
                    SELECT SUM(ROUND((data_length + index_length) / 1024 / 1024, 2)) as current_size
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE()
                ");
                $current = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return round($current['current_size'] - $yesterday['metric_value'], 2);
            }
            
            return 0;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get API performance metrics from api_debug_log
     */
    public static function getApiMetrics() {
        try {
            $pdo = getDbConnection();
            
            // Get all API calls from last 24h
            $stmt = $pdo->query("
                SELECT 
                    phase,
                    level,
                    data,
                    created_at
                FROM api_debug_log
                WHERE created_at >= NOW() - INTERVAL 24 HOUR
                ORDER BY created_at DESC
            ");
            
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $metrics = [
                'total_calls' => 0,
                'by_endpoint' => [],
                'success_count' => 0,
                'error_count' => 0,
                'latencies' => [],
                'errors' => []
            ];
            
            foreach ($logs as $log) {
                $data = json_decode($log['data'], true);
                $phase = $log['phase'];
                
                // Count by endpoint
                if (!isset($metrics['by_endpoint'][$phase])) {
                    $metrics['by_endpoint'][$phase] = [
                        'count' => 0,
                        'success' => 0,
                        'failed' => 0,
                        'latencies' => []
                    ];
                }
                
                $metrics['by_endpoint'][$phase]['count']++;
                $metrics['total_calls']++;
                
                // Track success/errors
                if ($log['level'] === 'ERROR') {
                    $metrics['by_endpoint'][$phase]['failed']++;
                    $metrics['error_count']++;
                    
                    $metrics['errors'][] = [
                        'endpoint' => $phase,
                        'timestamp' => $log['created_at'],
                        'error' => $data['error'] ?? 'Unknown error'
                    ];
                } else {
                    $metrics['by_endpoint'][$phase]['success']++;
                    $metrics['success_count']++;
                }
                
                // Track latency if available
                if (isset($data['duration_ms'])) {
                    $latency = (float)$data['duration_ms'];
                    $metrics['by_endpoint'][$phase]['latencies'][] = $latency;
                    $metrics['latencies'][] = $latency;
                }
            }
            
            // Calculate average latencies
            foreach ($metrics['by_endpoint'] as $endpoint => &$stats) {
                if (!empty($stats['latencies'])) {
                    $stats['avg_latency'] = round(array_sum($stats['latencies']) / count($stats['latencies']), 0);
                    sort($stats['latencies']);
                    $stats['p95_latency'] = self::percentile($stats['latencies'], 95);
                } else {
                    $stats['avg_latency'] = 0;
                    $stats['p95_latency'] = 0;
                }
                unset($stats['latencies']); // Remove raw data
            }
            
            // Calculate global percentiles
            if (!empty($metrics['latencies'])) {
                sort($metrics['latencies']);
                $metrics['avg_latency'] = round(array_sum($metrics['latencies']) / count($metrics['latencies']), 0);
                $metrics['p95_latency'] = self::percentile($metrics['latencies'], 95);
                $metrics['p99_latency'] = self::percentile($metrics['latencies'], 99);
            } else {
                $metrics['avg_latency'] = 0;
                $metrics['p95_latency'] = 0;
                $metrics['p99_latency'] = 0;
            }
            
            unset($metrics['latencies']); // Remove raw data
            
            return $metrics;
            
        } catch (Exception $e) {
            CentralLogger::log('daily_report', 'ERROR', 
                "Error getting API metrics: " . $e->getMessage());
            return ['total_calls' => 0, 'by_endpoint' => [], 'errors' => []];
        }
    }
    
    /**
     * Calculate percentile
     */
    private static function percentile($array, $percentile) {
        if (empty($array)) return 0;
        
        $index = ($percentile / 100) * (count($array) - 1);
        $lower = floor($index);
        $upper = ceil($index);
        
        if ($lower === $upper) {
            return round($array[$lower], 0);
        }
        
        $fraction = $index - $lower;
        return round($array[$lower] + ($array[$upper] - $array[$lower]) * $fraction, 0);
    }
    
    /**
     * Get detailed user breakdown for inventory
     */
    public static function getInventoryUserBreakdown() {
        try {
            $pdo = getDbConnection();
            
            // Get active users
            $stmt = $pdo->query("
                SELECT DISTINCT user_id, marketplace_id
                FROM amazon_client_tokens
                WHERE is_active = 1
                ORDER BY user_id
            ");
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $breakdown = [];
            
            foreach ($users as $user) {
                $userId = $user['user_id'];
                
                // Get latest inventory sync details from sync_debug_logs
                $stmt = $pdo->prepare("
                    SELECT 
                        created_at,
                        operation_type,
                        message,
                        context_data,
                        execution_time_ms
                    FROM sync_debug_logs
                    WHERE user_id = ?
                    AND operation_type IN ('inventory_sync_completed', 'inventory_fbm_no_data', 'inventory_report_no_data')
                    AND created_at >= NOW() - INTERVAL 24 HOUR
                    ORDER BY created_at DESC
                    LIMIT 5
                ");
                $stmt->execute([$userId]);
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($logs)) continue;
                
                $latestSync = $logs[0];
                $context = json_decode($latestSync['context_data'] ?? '{}', true);
                
                // Count products in inventory
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as fba_count FROM inventory WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
                $fbaCount = $stmt->fetch(PDO::FETCH_ASSOC)['fba_count'];
                
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as fbm_count FROM inventory_fbm WHERE user_id = ?
                ");
                $stmt->execute([$userId]);
                $fbmCount = $stmt->fetch(PDO::FETCH_ASSOC)['fbm_count'];
                
                $breakdown[$userId] = [
                    'user_id' => $userId,
                    'marketplace_id' => $user['marketplace_id'],
                    'last_sync' => $latestSync['created_at'],
                    'fba_success' => $context['fba_success'] ?? false,
                    'fbm_success' => $context['fbm_success'] ?? false,
                    'fba_products' => $fbaCount,
                    'fbm_products' => $fbmCount,
                    'total_rows' => $context['total_processed_rows'] ?? 0,
                    'execution_time' => $latestSync['execution_time_ms'] ? round($latestSync['execution_time_ms'] / 1000, 1) : 0,
                    'message' => $latestSync['message'],
                    'status' => $latestSync['operation_type'] === 'inventory_sync_completed' ? 'success' : 'warning'
                ];
            }
            
            return $breakdown;
            
        } catch (Exception $e) {
            CentralLogger::log('daily_report', 'ERROR', 
                "Error getting inventory breakdown: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get detailed user breakdown for settlement
     */
    public static function getSettlementUserBreakdown() {
        try {
            $pdo = getDbConnection();
            
            // Get active users
            $stmt = $pdo->query("SELECT id FROM users WHERE is_active = 1");
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $breakdown = [];
            
            foreach ($users as $userId) {
                $tableName = "report_settlement_{$userId}";
                
                // Check if table exists
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ?
                ");
                $stmt->execute([$tableName]);
                if ($stmt->fetchColumn() == 0) continue;
                
                // Get today's imports
                $stmt = $pdo->query("
                    SELECT 
                        COUNT(*) as rows_today,
                        MIN(posted_date) as period_start,
                        MAX(posted_date) as period_end,
                        SUM(CASE 
                            WHEN transaction_type LIKE '%Fee%' 
                            THEN ABS(total_amount) 
                            ELSE 0 
                        END) as total_fees,
                        COUNT(DISTINCT settlement_id) as reports_count
                    FROM {$tableName}
                    WHERE date_uploaded >= NOW() - INTERVAL 24 HOUR
                ");
                
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['rows_today'] > 0) {
                    $breakdown[$userId] = [
                        'user_id' => $userId,
                        'rows_imported' => $result['rows_today'],
                        'reports_count' => $result['reports_count'],
                        'period_start' => $result['period_start'],
                        'period_end' => $result['period_end'],
                        'total_fees' => round($result['total_fees'], 2),
                        'status' => 'success'
                    ];
                }
            }
            
            return $breakdown;
            
        } catch (Exception $e) {
            CentralLogger::log('daily_report', 'ERROR', 
                "Error getting settlement breakdown: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get trend comparison (vs yesterday and last 7 days avg)
     */
    public static function getTrendAnalysis() {
        try {
            $pdo = getDbConnection();
            
            $trends = [
                'inventory' => [],
                'settlement' => [],
                'system' => []
            ];
            
            // Define metrics to track
            $metricsToTrack = [
                'inventory' => ['products_count', 'success_rate', 'avg_execution_time'],
                'settlement' => ['reports_count', 'total_fees', 'users_synced'],
                'system' => ['health_score', 'errors_count', 'api_calls']
            ];
            
            foreach ($metricsToTrack as $category => $metrics) {
                foreach ($metrics as $metricName) {
                    // Get yesterday's value
                    $stmt = $pdo->prepare("
                        SELECT metric_value 
                        FROM daily_metrics_snapshot 
                        WHERE metric_category = ? 
                        AND metric_name = ?
                        AND metric_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)
                    ");
                    $stmt->execute([$category, $metricName]);
                    $yesterday = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Get 7-day average
                    $stmt = $pdo->prepare("
                        SELECT AVG(metric_value) as avg_value
                        FROM daily_metrics_snapshot 
                        WHERE metric_category = ? 
                        AND metric_name = ?
                        AND metric_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                        AND metric_date < CURDATE()
                    ");
                    $stmt->execute([$category, $metricName]);
                    $weekAvg = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $trends[$category][$metricName] = [
                        'yesterday' => $yesterday ? (float)$yesterday['metric_value'] : null,
                        'week_avg' => $weekAvg ? round((float)$weekAvg['avg_value'], 2) : null
                    ];
                }
            }
            
            return $trends;
            
        } catch (Exception $e) {
            CentralLogger::log('daily_report', 'ERROR', 
                "Error getting trend analysis: " . $e->getMessage());
            return ['inventory' => [], 'settlement' => [], 'system' => []];
        }
    }
    
    /**
     * Save today's metrics snapshot
     */
    public static function saveDailySnapshot($category, $metricName, $value, $metadata = []) {
        try {
            $pdo = getDbConnection();
            
            $stmt = $pdo->prepare("
                INSERT INTO daily_metrics_snapshot 
                (metric_date, metric_category, metric_name, metric_value, metadata)
                VALUES (CURDATE(), ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                metric_value = VALUES(metric_value),
                metadata = VALUES(metadata),
                created_at = NOW()
            ");
            
            $stmt->execute([
                $category,
                $metricName,
                $value,
                json_encode($metadata)
            ]);
            
            return true;
            
        } catch (Exception $e) {
            CentralLogger::log('daily_report', 'ERROR', 
                "Error saving daily snapshot: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate actionable recommendations based on metrics
     */
    public static function generateRecommendations($metrics) {
        $recommendations = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => []
        ];
        
        // Check inventory issues
        if (isset($metrics['inventory_breakdown'])) {
            foreach ($metrics['inventory_breakdown'] as $user) {
                if ($user['status'] === 'warning' && strpos($user['message'], 'CANCELLED') !== false) {
                    $recommendations['critical'][] = [
                        'title' => "User {$user['user_id']}: FBA Report CANCELLED",
                        'description' => "Verificare credenziali Amazon e marketplace_id",
                        'estimated_time' => '15 minuti',
                        'user_id' => $user['user_id']
                    ];
                }
            }
        }
        
        // Check settlement coverage
        if (isset($metrics['settlement_details'])) {
            $coverage = $metrics['settlement_details']['coverage_percentage'] ?? 100;
            if ($coverage < 80) {
                $recommendations['high'][] = [
                    'title' => "Settlement coverage basso: {$coverage}%",
                    'description' => "Non tutti gli utenti attivi hanno report settlement",
                    'estimated_time' => '30 minuti'
                ];
            }
        }
        
        // Check API errors
        if (isset($metrics['api_metrics']['error_count'])) {
            if ($metrics['api_metrics']['error_count'] > 5) {
                $recommendations['high'][] = [
                    'title' => "Errori API elevati: {$metrics['api_metrics']['error_count']}",
                    'description' => "Verificare rate limiting e credenziali",
                    'estimated_time' => '20 minuti'
                ];
            }
        }
        
        // Check database growth
        if (isset($metrics['database_stats']['growth_rate_mb_per_day'])) {
            $growth = $metrics['database_stats']['growth_rate_mb_per_day'];
            if ($growth > 100) {
                $recommendations['medium'][] = [
                    'title' => "Database growth elevato: +{$growth}MB/giorno",
                    'description' => "Verificare cleanup automatico",
                    'estimated_time' => '10 minuti'
                ];
            }
        }
        
        return $recommendations;
    }
}

