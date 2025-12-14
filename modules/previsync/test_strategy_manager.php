<?php
/**
 * Test Strategy Manager - Debug completo
 * File: modules/previsync/test_strategy_manager.php
 * 
 * Testa la logica del Strategy Manager con prodotti reali
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/margynomic/config/config.php';
require_once dirname(__DIR__) . '/margynomic/login/auth_helpers.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: ../margynomic/login/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

echo "<h1>🧪 Test Strategy Manager</h1>";
echo "<p>User ID: $userId</p>";

// Carica Strategy Manager
try {
    require_once __DIR__ . '/inventory_strategy_manager.php';
    $db = getDbConnection();
    $strategyManager = new InventoryStrategyManager($db, $userId);
    echo "<p>✅ Strategy Manager caricato correttamente</p>";
} catch (Exception $e) {
    echo "<p>❌ Errore caricamento Strategy Manager: " . $e->getMessage() . "</p>";
    exit;
}

// Test con prodotti reali dal database
try {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(p.nome, MIN(inv.product_name), 'Nome non disponibile') as product_name,
            COALESCE(
                MAX(CASE WHEN inv.sku = p.sku THEN inv.your_price END),
                AVG(inv.your_price)
            ) as your_price,
            SUM(inv.afn_warehouse_quantity) as disponibili,
            SUM(inv.afn_inbound_shipped_quantity) as in_arrivo,
            inv.product_id,
            0 as vendite_totali,
            0 as giorni_attivi,
            0 as media_vendite_1d,
            NULL as prima_vendita,
            0 as vendite_90gg
        FROM inventory inv
        LEFT JOIN products p ON inv.product_id = p.id
        WHERE inv.user_id = ? AND inv.product_id IS NOT NULL
        GROUP BY inv.product_id
        ORDER BY COALESCE(p.nome, MIN(inv.product_name)) ASC
        LIMIT 1000
    ");
    
    $stmt->execute([$userId]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>📦 Prodotti di Test (primi 10)</h2>";
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr>";
    echo "<th>Nome Prodotto</th>";
    echo "<th>Disponibili</th>";
    echo "<th>In Arrivo</th>";
    echo "<th>Media Vendite 1D</th>";
    echo "<th>Strategia Applicata</th>";
    echo "<th>Criticità</th>";
    echo "<th>Invio Suggerito</th>";
    echo "<th>Last Charge</th>";
    echo "</tr>";
    
    foreach ($products as $item) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($item['product_name']) . "</td>";
        echo "<td>" . $item['disponibili'] . "</td>";
        echo "<td>" . $item['in_arrivo'] . "</td>";
        echo "<td>" . $item['media_vendite_1d'] . "</td>";
        
        // Testa Strategy Manager
        try {
            $strategiaResult = $strategyManager->calculateAdvancedStrategy($item);
            
            echo "<td>" . ($strategiaResult['strategia_applicata'] ?? 'N/A') . "</td>";
            echo "<td>" . ($strategiaResult['criticita'] ?? 'N/A') . "</td>";
            echo "<td>" . ($strategiaResult['invio_suggerito'] ?? 'N/A') . "</td>";
            
            // Verifica last_charge
            $lastCharge = 'N/A';
            if (!empty($item['product_id'])) {
                $stmt2 = $db->prepare("SELECT MIN(last_charge) as ultimo_rifornimento FROM inventory WHERE product_id = ? AND user_id = ? AND last_charge IS NOT NULL");
                $stmt2->execute([$item['product_id'], $userId]);
                $result = $stmt2->fetch();
                $lastCharge = $result['ultimo_rifornimento'] ?? 'NULL';
            }
            echo "<td>" . $lastCharge . "</td>";
            
        } catch (Exception $e) {
            echo "<td colspan='4'>❌ Errore: " . $e->getMessage() . "</td>";
        }
        
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p>❌ Errore query prodotti: " . $e->getMessage() . "</p>";
}

// Test manuale con dati simulati
echo "<h2>🧪 Test Scenari Specifici</h2>";

$testCases = [
    [
        'nome' => 'Test 1: Stock = 0, Vendite = 0',
        'disponibili' => 0,
        'in_arrivo' => 0,
        'media_vendite_1d' => 0,
        'vendite_90gg' => 0,
        'product_id' => 999
    ],
    [
        'nome' => 'Test 2: Stock > 0, Vendite = 0',
        'disponibili' => 10,
        'in_arrivo' => 0,
        'media_vendite_1d' => 0,
        'vendite_90gg' => 0,
        'product_id' => 998
    ],
    [
        'nome' => 'Test 3: Stock = 0, Vendite > 0',
        'disponibili' => 0,
        'in_arrivo' => 0,
        'media_vendite_1d' => 0.5,
        'vendite_90gg' => 15,
        'product_id' => 997
    ]
];

echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr>";
echo "<th>Test Case</th>";
echo "<th>Stock</th>";
echo "<th>Vendite 1D</th>";
echo "<th>Strategia</th>";
echo "<th>Criticità</th>";
echo "<th>Invio</th>";
echo "<th>Giorni Ultimo Rifornimento</th>";
echo "</tr>";

foreach ($testCases as $test) {
    echo "<tr>";
    echo "<td>" . $test['nome'] . "</td>";
    echo "<td>" . $test['disponibili'] . "</td>";
    echo "<td>" . $test['media_vendite_1d'] . "</td>";
    
    try {
        $strategiaResult = $strategyManager->calculateAdvancedStrategy($test);
        
        echo "<td>" . ($strategiaResult['strategia_applicata'] ?? 'N/A') . "</td>";
        echo "<td>" . ($strategiaResult['criticita'] ?? 'N/A') . "</td>";
        echo "<td>" . ($strategiaResult['invio_suggerito'] ?? 'N/A') . "</td>";
        
        // Test calcolo giorni ultimo rifornimento
        $giorni = $strategyManager->getGiorniUltimoRifornimento($test);
        echo "<td>" . ($giorni ?? 'NULL') . "</td>";
        
    } catch (Exception $e) {
        echo "<td colspan='4'>❌ Errore: " . $e->getMessage() . "</td>";
    }
    
    echo "</tr>";
}

echo "</table>";

// Statistiche Strategy Manager
echo "<h2>📊 Statistiche Strategy Manager</h2>";
try {
    $stats = $strategyManager->getStrategiaStats();
    echo "<pre>" . print_r($stats, true) . "</pre>";
} catch (Exception $e) {
    echo "<p>❌ Errore statistiche: " . $e->getMessage() . "</p>";
}

// Test colonna last_charge
echo "<h2>💾 Test Colonna last_charge</h2>";
try {
    $stmt = $db->prepare("SELECT sku, last_charge FROM inventory WHERE user_id = ? AND last_charge IS NOT NULL LIMIT 5");
    $stmt->execute([$userId]);
    $lastChargeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($lastChargeData)) {
        echo "<p>⚠️ Nessun record con last_charge popolato trovato</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse;'>";
        echo "<tr><th>SKU</th><th>Last Charge</th></tr>";
        foreach ($lastChargeData as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['sku']) . "</td>";
            echo "<td>" . $row['last_charge'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p>❌ Errore query last_charge: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><a href='inventory.php'>← Torna alla Dashboard</a></p>";
?>