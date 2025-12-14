<?php
/**
 * Import Missing Shipments - User 8
 * 
 * Script per importare manualmente le 36 spedizioni mancanti
 * identificate nel confronto con Seller Central.
 */

// ENABLE ERROR REPORTING
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

try {
    // Load dependencies with better error handling
    $basePath = dirname(__DIR__, 2);
    
    // Try different possible paths for database config
    $dbConfigPaths = [
        __DIR__ . '/../../modules/margynomic/config/database.php',
        __DIR__ . '/../margynomic/config/database.php',
        $basePath . '/modules/margynomic/config/database.php',
        $basePath . '/config/database.php'
    ];
    
    $dbLoaded = false;
    foreach ($dbConfigPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $dbLoaded = true;
            break;
        }
    }
    
    if (!$dbLoaded) {
        throw new Exception("Database config not found. Tried: " . implode(', ', $dbConfigPaths));
    }
    
    // Load logger
    $loggerPath = __DIR__ . '/../margynomic/config/CentralLogger.php';
    if (!file_exists($loggerPath)) {
        throw new Exception("CentralLogger not found at: $loggerPath");
    }
    require_once $loggerPath;
    
    // Load InboundCore
    $corePath = __DIR__ . '/inbound_core.php';
    if (!file_exists($corePath)) {
        throw new Exception("InboundCore not found at: $corePath");
    }
    require_once $corePath;
    
} catch (Exception $e) {
    die("<h1>Configuration Error</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>");
}

// ============================================
// CONFIGURAZIONE
// ============================================
$userId = 8; // GECO Green ECOmmerce
$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';

// 36 Spedizioni mancanti (da query SQL)
$missingShipments = [
    'FBA15K3K2S4Y', 'FBA15K0TJZYM', 'FBA15K05ZQHW', 'FBA15JXNVRCD',
    'FBA15JRRJ1NK', 'FBA15JNCBXY2', 'FBA15JMRH8TB', 'FBA15JLRK7HZ',
    'FBA15JKGS2ZF', 'FBA15JGQN2MR', 'FBA15JGQXJ0B', 'FBA15JGMYTX5',
    'FBA15JBQLB28', 'FBA15J9D1918', 'FBA15J9DD9ZX', 'FBA15J50BKV2',
    'FBA15J50BPZG', 'FBA15J50K2LX', 'FBA15J3H86K1', 'FBA15J2TGHXV',
    'FBA15J2TJGR0', 'FBA15J2LW5RH', 'FBA15J0TD12Z', 'FBA15HZ6MQ77',
    'FBA15HX0GWKH', 'FBA15HVGN1DL', 'FBA15HTG19DZ', 'FBA15HT4GDV0',
    'FBA15HSSQWRP', 'FBA15HSB6KP3', 'FBA15HQ6C5JP', 'FBA15HNNZ5Z6',
    'FBA15HNBF25P', 'FBA15HNBCL02', 'FBA15HLDYYSY', 'FBA15HL3S84G'
];

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Spedizioni Mancanti - User 8</title>
    <link rel="stylesheet" href="inbound.css">
    <style>
        .terminal {
            background: #1e1e1e;
            color: #00ff00;
            padding: 1.5rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 2rem 0;
            max-height: 600px;
            overflow-y: auto;
        }
        .terminal .success { color: #00ff00; }
        .terminal .error { color: #ff0000; }
        .terminal .warning { color: #ffaa00; }
        .terminal .info { color: #00aaff; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 1200px; margin: 2rem auto; padding: 0 2rem;">
        <div class="header" style="margin-bottom: 2rem;">
            <h1 style="font-size: 2rem; margin-bottom: 0.5rem;">🔧 Import Spedizioni Mancanti</h1>
            <p style="color: #666;">User 8: GECO Green ECOmmerce - 36 spedizioni da importare</p>
        </div>

        <?php if (!$dryRun && !isset($_POST['confirm'])): ?>
            <!-- Conferma richiesta -->
            <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 1.5rem; margin-bottom: 2rem;">
                <div style="font-size: 1.5rem; margin-bottom: 1rem;">⚠️ <strong>ATTENZIONE!</strong></div>
                <p>Stai per importare 36 spedizioni mancanti chiamando l'API Amazon per ciascuna.</p>
                <p>Questo processo richiederà circa <strong>5-10 minuti</strong> e consumerà <strong>~72-108 API calls</strong>.</p>
                <br>
                <form method="POST" style="margin-top: 1rem;">
                    <input type="hidden" name="confirm" value="1">
                    <button type="submit" style="background: #28a745; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 6px; cursor: pointer; margin-right: 1rem; font-size: 1rem;">
                        ✅ Confermo, Avvia Import
                    </button>
                    <a href="inbound.php?user_id=<?= $userId ?>" style="background: #6c757d; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; display: inline-block;">
                        ❌ Annulla
                    </a>
                </form>
            </div>
        <?php else: ?>
            <!-- Info Box -->
            <div style="background: #d1ecf1; border: 1px solid #17a2b8; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                <strong>ℹ️ In Corso...</strong><br>
                Lo script sta eseguendo 36 chiamate API Amazon. Vedrai l'output in tempo reale qui sotto.<br>
                <strong>Tempo stimato:</strong> <?= $dryRun ? '~15-25 secondi' : '~1-2 minuti' ?><br>
                <strong>Non chiudere questa pagina!</strong>
            </div>
            
            <!-- Esecuzione Import -->
            <div class="terminal">
<?php
// DISABLE ALL OUTPUT BUFFERING
while (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Apache/PHP config override
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');

// Send headers to prevent buffering
header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no'); // Nginx
header('Cache-Control: no-cache');

// Pad output to force display (some browsers need 1KB before showing)
echo str_repeat(' ', 1024);
flush();

$startTime = microtime(true);

echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
echo "[" . date('H:i:s') . "] <span class='info'>IMPORT SPEDIZIONI MANCANTI - START</span>\n";
echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
echo "[" . date('H:i:s') . "] User ID: $userId\n";
echo "[" . date('H:i:s') . "] Dry Run: " . ($dryRun ? 'YES' : 'NO') . "\n";
echo "[" . date('H:i:s') . "] Total shipments: " . count($missingShipments) . "\n";
echo "[" . date('H:i:s') . "] <span class='warning'>⏱️  ETA: ~1-2 minuti (36 API calls + rate limiting)</span>\n";
echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n\n";

flush();

try {
    $db = getDbConnection();
    
    // Test InboundCore initialization
    echo "[" . date('H:i:s') . "] <span class='info'>Initializing InboundCore...</span>\n";
    flush();
    
    $core = new InboundCore($userId, ['dry_run' => $dryRun]);
    
    echo "[" . date('H:i:s') . "] <span class='success'>✓ InboundCore initialized</span>\n\n";
    flush();
    
    $stats = [
        'success' => 0,
        'skipped' => 0,
        'not_found' => 0,
        'errors' => 0,
        'api_calls' => 0
    ];
    
    foreach ($missingShipments as $index => $shipmentId) {
        $num = $index + 1;
        $percent = round(($num / 36) * 100);
        echo "[" . date('H:i:s') . "] <span class='info'>[$num/36 - $percent%]</span> Processing: <strong>$shipmentId</strong>... ";
        flush();
        
        // Force output immediately
        if (ob_get_level() > 0) {
            ob_flush();
        }
        
        try {
            // 1. Test se getShipmentDetailsV0 esiste
            if (!method_exists($core, 'getShipmentDetailsV0')) {
                throw new Exception("Method getShipmentDetailsV0 not found in InboundCore");
            }
            
            // 2. Recupera dati spedizione
            $response = $core->getShipmentDetailsV0($shipmentId);
            $stats['api_calls']++;
            
            if (empty($response['payload']['ShipmentData'])) {
                echo "<span class='warning'>NOT FOUND (404)</span>\n";
                $stats['not_found']++;
                continue;
            }
            
            $shipment = $response['payload']['ShipmentData'];
            
            // 3. Verifica se già esiste
            $stmt = $db->prepare("SELECT id FROM inbound_shipments WHERE user_id = ? AND amazon_shipment_id = ?");
            $stmt->execute([$userId, $shipmentId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo "<span class='warning'>SKIP (already in DB)</span>\n";
                $stats['skipped']++;
                continue;
            }
            
            if (!$dryRun) {
                // 4. Processa spedizione (salva header + items + boxes)
                $result = $core->processShipment($shipment);
                $stats['api_calls'] += 2; // +1 items, +1 boxes (circa)
                
                if ($result['status'] === 'complete') {
                    echo "<span class='success'>✓ SUCCESS (items: {$result['items_count']})</span>\n";
                    $stats['success']++;
                } else {
                    echo "<span class='warning'>⚠ PARTIAL ({$result['status']})</span>\n";
                    $stats['success']++;
                }
            } else {
                echo "<span class='info'>✓ DRY RUN (would import)</span>\n";
                $stats['success']++;
            }
            
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            // Truncate long error messages
            if (strlen($errorMsg) > 100) {
                $errorMsg = substr($errorMsg, 0, 100) . '...';
            }
            echo "<span class='error'>✗ ERROR: " . htmlspecialchars($errorMsg) . "</span>\n";
            $stats['errors']++;
        }
        
        flush();
        
        // Force flush again
        if (ob_get_level() > 0) {
            ob_flush();
        }
        
        // Rate limiting: più veloce in dry run, più sicuro in run reale
        if ($num < count($missingShipments)) {
            usleep($dryRun ? 200000 : 1000000); // 0.2s dry run, 1s real
        }
    }
    
    $duration = round(microtime(true) - $startTime, 2);
    
    echo "\n[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
    echo "[" . date('H:i:s') . "] <span class='success'>IMPORT COMPLETED</span>\n";
    echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
    echo "[" . date('H:i:s') . "] Success: <span class='success'>{$stats['success']}</span>\n";
    echo "[" . date('H:i:s') . "] Skipped: <span class='warning'>{$stats['skipped']}</span>\n";
    echo "[" . date('H:i:s') . "] Not Found: <span class='warning'>{$stats['not_found']}</span>\n";
    echo "[" . date('H:i:s') . "] Errors: <span class='error'>{$stats['errors']}</span>\n";
    echo "[" . date('H:i:s') . "] API Calls: <span class='info'>{$stats['api_calls']}</span>\n";
    echo "[" . date('H:i:s') . "] Duration: {$duration}s\n";
    echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
    
} catch (Exception $e) {
    echo "\n<span class='error'>✗ FATAL ERROR: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    echo "<span class='error'>Stack trace:</span>\n";
    echo "<span class='error'>" . htmlspecialchars($e->getTraceAsString()) . "</span>\n";
}
?>
            </div>

            <?php if (!$dryRun && isset($stats)): ?>
            <div style="background: #d4edda; border: 1px solid #28a745; border-radius: 8px; padding: 1.5rem; margin-top: 2rem;">
                <div style="font-size: 1.2rem; margin-bottom: 1rem;">✅ <strong>Import Completato!</strong></div>
                <p>Le spedizioni sono state importate. Verifica il database:</p>
                <code style="background: #f8f9fa; padding: 0.5rem; border-radius: 4px; display: block; margin: 1rem 0;">
                    SELECT COUNT(*) FROM inbound_shipments WHERE user_id = 8;
                </code>
                <p>Dovrebbe essere: <strong>67 + <?= isset($stats) ? $stats['success'] : 0 ?> = <?= 67 + (isset($stats) ? $stats['success'] : 0) ?></strong></p>
            </div>
            <?php endif; ?>

            <div style="margin-top: 2rem;">
                <a href="inbound.php?user_id=<?= $userId ?>" style="background: #007bff; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; display: inline-block; margin-right: 1rem;">
                    📊 Torna alla Dashboard
                </a>
                <a href="inbound_views.php?view=shipments&user_id=<?= $userId ?>" style="background: #6c757d; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; display: inline-block;">
                    📦 Vedi Spedizioni
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
