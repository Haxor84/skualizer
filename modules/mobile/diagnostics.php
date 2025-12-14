<?php
/**
 * Diagnostics - Performance Check
 * Accesso: Utenti loggati
 */
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';

// Solo utenti loggati
if (!isLoggedIn()) {
    die('Accesso negato - Login richiesto');
}

echo "<style>body{font-family:monospace;padding:20px;background:#1a1a1a;color:#0f0;}pre{background:#000;padding:15px;border:1px solid #0f0;}</style>";
echo "<h1 style='color:#0f0'>🔍 DIAGNOSTICS MOBILE</h1>";
echo "<pre>";

// === PHP VERSION ===
echo "=== PHP ENVIRONMENT ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n";
echo "Max Execution: " . ini_get('max_execution_time') . "s\n";
echo "Upload Max: " . ini_get('upload_max_filesize') . "\n";
echo "Post Max: " . ini_get('post_max_size') . "\n";

// === EXTENSIONS ===
echo "\n=== EXTENSIONS ===\n";
echo "OPcache: " . (extension_loaded('Zend OPcache') ? '✓ ENABLED' : '✗ DISABLED') . "\n";
echo "APCu: " . (extension_loaded('apcu') ? '✓ ENABLED' : '✗ DISABLED') . "\n";
echo "GZip: " . (extension_loaded('zlib') ? '✓ ENABLED' : '✗ DISABLED') . "\n";
echo "PDO: " . (extension_loaded('pdo') ? '✓ ENABLED' : '✗ DISABLED') . "\n";
echo "JSON: " . (extension_loaded('json') ? '✓ ENABLED' : '✗ DISABLED') . "\n";

// === APCu STATS ===
if (extension_loaded('apcu') && function_exists('apcu_cache_info')) {
    try {
        $info = apcu_cache_info(true);
        echo "\n=== APCu CACHE ===\n";
        echo "Entries: " . ($info['num_entries'] ?? 0) . "\n";
        echo "Memory Used: " . round(($info['mem_size'] ?? 0)/1024/1024, 2) . " MB\n";
        echo "Hit Rate: " . round(
            ($info['num_hits'] ?? 0) / max(($info['num_hits'] ?? 0) + ($info['num_misses'] ?? 1), 1) * 100, 
            2
        ) . "%\n";
    } catch (Exception $e) {
        echo "\n=== APCu CACHE ===\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// === OPCACHE STATS ===
if (function_exists('opcache_get_status')) {
    try {
        $op = opcache_get_status(false);
        echo "\n=== OPCACHE ===\n";
        echo "Enabled: " . ($op['opcache_enabled'] ? 'YES' : 'NO') . "\n";
        echo "Hit Rate: " . round($op['opcache_statistics']['opcache_hit_rate'] ?? 0, 2) . "%\n";
        echo "Cached Scripts: " . ($op['opcache_statistics']['num_cached_scripts'] ?? 0) . "\n";
        echo "Memory Used: " . round(($op['memory_usage']['used_memory'] ?? 0)/1024/1024, 2) . " MB\n";
    } catch (Exception $e) {
        echo "\n=== OPCACHE ===\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// === DB CONNECTION TEST ===
echo "\n=== DATABASE ===\n";
try {
    $start = microtime(true);
    $pdo = getDbConnection();
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "Connection: ✓ OK ({$elapsed}ms)\n";
    
    // Test query
    $start = microtime(true);
    $stmt = $pdo->query("SELECT COUNT(*) FROM mobile_cache");
    $count = $stmt->fetchColumn();
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "Cache Entries: {$count} ({$elapsed}ms)\n";
    
} catch (Exception $e) {
    echo "Connection: ✗ ERROR\n";
    echo "Error: " . $e->getMessage() . "\n";
}

// === SERVER INFO ===
echo "\n=== SERVER ===\n";
echo "OS: " . php_uname('s') . " " . php_uname('r') . "\n";
echo "Server: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Load: " . (function_exists('sys_getloadavg') ? implode(' ', sys_getloadavg()) : 'N/A') . "\n";

echo "\n=== RECOMMENDATIONS ===\n";
if (!extension_loaded('apcu')) {
    echo "⚠ APCu non disponibile - cache solo DB\n";
} else {
    echo "✓ APCu disponibile - implementabile cache RAM\n";
}

if (!extension_loaded('zlib')) {
    echo "⚠ GZip non disponibile - no compressione output\n";
} else {
    echo "✓ GZip disponibile - implementabile compressione\n";
}

if (function_exists('opcache_get_status')) {
    $op = opcache_get_status(false);
    $hitRate = $op['opcache_statistics']['opcache_hit_rate'] ?? 0;
    if ($hitRate < 95) {
        echo "⚠ OPcache hit rate basso ({$hitRate}%)\n";
    } else {
        echo "✓ OPcache performante ({$hitRate}%)\n";
    }
}

echo "</pre>";
echo "<p style='color:#0f0'>Diagnostics completato: " . date('Y-m-d H:i:s') . "</p>";