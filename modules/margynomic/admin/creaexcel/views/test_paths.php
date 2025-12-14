<?php
/**
 * Test Path Resolution
 * Verifica che tutti i require_once funzionino
 */

echo "<h2>🔍 Path Test - Excel Upload</h2>";
echo "<pre>";

// Test 1: admin_helpers.php
$path1 = __DIR__ . '/../../admin_helpers.php';
echo "\n1. admin_helpers.php\n";
echo "   Path: $path1\n";
echo "   Exists: " . (file_exists($path1) ? "✅ YES" : "❌ NO") . "\n";
echo "   Resolved: " . realpath($path1) . "\n";

// Test 2: config.php
$path2 = __DIR__ . '/../../../config/config.php';
echo "\n2. config.php\n";
echo "   Path: $path2\n";
echo "   Exists: " . (file_exists($path2) ? "✅ YES" : "❌ NO") . "\n";
echo "   Resolved: " . realpath($path2) . "\n";

// Test 3: ExcelListingManager.php
$path3 = __DIR__ . '/../ExcelListingManager.php';
echo "\n3. ExcelListingManager.php\n";
echo "   Path: $path3\n";
echo "   Exists: " . (file_exists($path3) ? "✅ YES" : "❌ NO") . "\n";
echo "   Resolved: " . realpath($path3) . "\n";

// Test 4: CentralLogger.php
$path4 = __DIR__ . '/../../../config/CentralLogger.php';
echo "\n4. CentralLogger.php\n";
echo "   Path: $path4\n";
echo "   Exists: " . (file_exists($path4) ? "✅ YES" : "❌ NO") . "\n";
echo "   Resolved: " . realpath($path4) . "\n";

// Test 5: vendor/autoload.php (CORRETTO per views/)
$path5 = __DIR__ . '/../../../vendor/autoload.php';
echo "\n5. vendor/autoload.php (from views/)\n";
echo "   Path: $path5\n";
echo "   Exists: " . (file_exists($path5) ? "✅ YES" : "❌ NO") . "\n";
echo "   Resolved: " . realpath($path5) . "\n";

// Test 5b: Path per file in creaexcel/ (parent)
$path5b = __DIR__ . '/../../vendor/autoload.php';
echo "\n5b. vendor/autoload.php (from creaexcel/ - per ExcelParser, etc.)\n";
echo "   Path: $path5b\n";
echo "   Exists: " . (file_exists($path5b) ? "✅ YES" : "❌ NO") . "\n";
echo "   Resolved: " . realpath($path5b) . "\n";

// Test 6: Ora proviamo a caricare i file
echo "\n\n=== LOADING TEST ===\n";

try {
    echo "\n→ Loading config.php... ";
    require_once __DIR__ . '/../../../config/config.php';
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "→ Loading CentralLogger.php... ";
    require_once __DIR__ . '/../../../config/CentralLogger.php';
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "→ Loading admin_helpers.php... ";
    require_once __DIR__ . '/../../admin_helpers.php';
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

try {
    echo "→ Loading ExcelListingManager.php... ";
    require_once __DIR__ . '/../ExcelListingManager.php';
    echo "✅ OK\n";
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

echo "\n\n✅ All tests completed!\n";
echo "</pre>";
?>

