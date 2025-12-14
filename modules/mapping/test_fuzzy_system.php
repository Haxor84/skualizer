<?php
/**
 * SCRIPT TEST SISTEMA APPROVAZIONE FUZZY MAPPING
 * File: /modules/mapping/test_fuzzy_system.php
 * 
 * Testa il sistema completo di approvazione fuzzy con 6 SKU strategici
 */

// Includi le dipendenze necessarie
require_once __DIR__ . '/config/mapping_config.php';
require_once __DIR__ . '/MappingRepository.php';
require_once __DIR__ . '/MappingService.php';

echo "<h1>🧪 TEST SISTEMA APPROVAZIONE FUZZY MAPPING</h1>";
echo "<style>
body { font-family: monospace; background: #f5f5f5; padding: 20px; }
.step { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #008CFF; }
.success { border-left-color: #28a745; }
.warning { border-left-color: #ffc107; }
.error { border-left-color: #dc3545; }
.debug { background: #f8f9fa; padding: 10px; margin: 5px 0; font-size: 12px; }
</style>";

// Configurazione test
$userId = 2;
$testMode = true;

// SKU di test strategici basati sui dati reali
$testSkus = [
    [
        'sku' => 'H-1000 Aglio a Fette-01',
        'expected' => 'exact_match',
        'description' => 'SKU esistente - deve trovare match esatto immediato',
        'should_find_product_id' => 393
    ],
    [
        'sku' => 'H-1000 Aglio a Fette-02', 
        'expected' => 'auto_approve',
        'description' => 'Molto simile - solo numero finale diverso (alta confidenza ≥95%)',
        'should_find_product_id' => 393
    ],
    [
        'sku' => 'H-1000 Aglio Fette-01',
        'expected' => 'auto_approve', 
        'description' => 'Simile - manca "a" (alta confidenza ≥95%)',
        'should_find_product_id' => 393
    ],
    [
        'sku' => 'H-1000 Aglio Tritato-01',
        'expected' => 'pending_approval',
        'description' => 'Media similarità - "Tritato" vs "Fette" (confidenza 60-90%)',
        'should_find_product_id' => 393
    ],
    [
        'sku' => 'H-999 Aglio a Fette-01',
        'expected' => 'pending_approval',
        'description' => 'Media similarità - numero diverso (confidenza 60-90%)', 
        'should_find_product_id' => 393
    ],
    [
        'sku' => 'Z-9999 Pomodoro Secco-05',
        'expected' => 'auto_reject',
        'description' => 'Completamente diverso (bassa confidenza <60%)',
        'should_find_product_id' => null
    ]
];

try {
    // === STEP 1: INIZIALIZZAZIONE ===
    echo "<div class='step'>";
    echo "<h2>📋 STEP 1: Inizializzazione Sistema</h2>";
    
    $dbConnection = getMappingDbConnection();
    $mappingConfig = getMappingConfig();
    $mappingRepository = new MappingRepository($dbConnection, $mappingConfig);
    $mappingService = new MappingService($mappingRepository, $mappingConfig);
    
    echo "<div class='debug'>✅ Database connesso</div>";
    echo "<div class='debug'>✅ Repository inizializzato</div>";
    echo "<div class='debug'>✅ Service inizializzato</div>";
    echo "<div class='debug'>📊 User ID: {$userId}</div>";
    echo "<div class='debug'>📊 Test SKU: " . count($testSkus) . "</div>";
    echo "</div>";

    // === STEP 2: VERIFICA CONFIGURAZIONE FUZZY ===
    echo "<div class='step'>";
    echo "<h2>⚙️ STEP 2: Verifica Configurazione Fuzzy</h2>";
    
    $fuzzyConfig = $mappingConfig['fuzzy_approval'] ?? [];
    echo "<div class='debug'>🔧 Fuzzy approval enabled: " . ($fuzzyConfig['enabled'] ? 'YES' : 'NO') . "</div>";
    echo "<div class='debug'>🔧 Auto-approve threshold: " . ($fuzzyConfig['auto_approve_threshold'] ?? 'N/A') . "</div>";
    echo "<div class='debug'>🔧 Require approval below: " . ($fuzzyConfig['require_approval_below'] ?? 'N/A') . "</div>";
    echo "<div class='debug'>🔧 Auto-reject below: " . ($fuzzyConfig['auto_reject_below'] ?? 'N/A') . "</div>";
    echo "</div>";

    // === STEP 3: PULIZIA DATI PRECEDENTI ===
    echo "<div class='step'>";
    echo "<h2>🧹 STEP 3: Pulizia Dati Test Precedenti</h2>";
    
    // Rimuovi SKU test precedenti
    $cleanupSkus = array_column($testSkus, 'sku');
    $placeholders = str_repeat('?,', count($cleanupSkus) - 1) . '?';
    
    $stmt = $dbConnection->prepare("DELETE FROM inventory WHERE user_id = ? AND sku IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $cleanupSkus));
    $deletedInventory = $stmt->rowCount();
    
    $stmt = $dbConnection->prepare("DELETE FROM mapping_states WHERE user_id = ? AND sku IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $cleanupSkus));
    $deletedStates = $stmt->rowCount();
    
    echo "<div class='debug'>🗑️ Inventory cancellati: {$deletedInventory}</div>";
    echo "<div class='debug'>🗑️ Mapping states cancellati: {$deletedStates}</div>";
    echo "</div>";

    // === STEP 4: INSERIMENTO SKU TEST ===
    echo "<div class='step'>";
    echo "<h2>📥 STEP 4: Inserimento SKU di Test</h2>";
    
    foreach ($testSkus as $index => $testSku) {
        $stmt = $dbConnection->prepare("
            INSERT INTO inventory (user_id, sku, product_name, afn_fulfillable_quantity, your_price, last_updated) 
            VALUES (?, ?, ?, 10, 9.99, NOW())
        ");
        
        $stmt->execute([
            $userId, 
            $testSku['sku'], 
            "Test Product - " . $testSku['sku']
        ]);
        
        echo "<div class='debug'>✅ Inserito: {$testSku['sku']} - {$testSku['description']}</div>";
    }
    echo "</div>";

    // === STEP 5: ESECUZIONE MAPPING AUTOMATICO ===
    echo "<div class='step'>";
    echo "<h2>🤖 STEP 5: Esecuzione Mapping Automatico</h2>";
    
    echo "<div class='debug'>🚀 Avvio executeFullMapping per user_id {$userId} source 'inventory'...</div>";
    
    $mappingResults = $mappingService->executeFullMapping($userId, 'inventory');
    
    echo "<div class='debug'>📊 Risultati mapping:</div>";

// === DEBUG FUZZY STRATEGY ===
echo "<div class='debug'>🔍 DEBUG FUZZY STRATEGY:</div>";

foreach ($testSkus as $testSku) {
    if ($testSku['sku'] === 'H-1000 Aglio a Fette-01') continue; // Skip exact match
    
    echo "<div class='debug'>Testing fuzzy for: {$testSku['sku']}</div>";
    
    // Test diretto della strategia fuzzy
    $fuzzyStrategy = new FuzzyMatchStrategy($mappingRepository, $mappingConfig);
    $fuzzyResult = $fuzzyStrategy->executeMapping($userId, $testSku['sku'], [
        'source_table' => 'inventory',
        'product_name' => "Test Product - " . $testSku['sku']
    ]);
    
    echo "<div class='debug'>  Fuzzy result: " . json_encode($fuzzyResult) . "</div>";
    
    // Debug delle parole chiave estratte
    $testKeywords = $fuzzyStrategy->extractKeywordsFromSku($testSku['sku']);
    echo "<div class='debug'>  Keywords extracted: " . json_encode($testKeywords) . "</div>";
    
    // Test manuale della ricerca per parole chiave
    if (!empty($testKeywords)) {
        $searchTerm = implode(' ', $testKeywords);
        echo "<div class='debug'>  Search term: '{$searchTerm}'</div>";
        $manualSearch = $mappingRepository->findProducts($userId, $searchTerm, 'nome', 5);
        echo "<div class='debug'>  Manual search results: " . count($manualSearch) . "</div>";
    }
    
    // Test ricerca prodotti
    $products = $mappingRepository->findProducts($userId, $testSku['sku'], 'sku', 5);
    echo "<div class='debug'>  Found products by SKU: " . count($products) . "</div>";
    
    $products2 = $mappingRepository->findProducts($userId, $testSku['sku'], 'nome', 5);
    echo "<div class='debug'>  Found products by name: " . count($products2) . "</div>";

    // Test ricerca su nome prodotto invece di SKU
    $products3 = $mappingRepository->findProducts($userId, 'Aglio a Fette', 'nome', 5);
    echo "<div class='debug'>  Found products by partial name: " . count($products3) . "</div>";
    
    if (!empty($products3)) {
        foreach ($products3 as $prod) {
            echo "<div class='debug'>    - {$prod['nome']} (ID: {$prod['id']})</div>";
        }
    }
}
    echo "<div class='debug'>  - Processed SKUs: " . ($mappingResults['processed_skus'] ?? 0) . "</div>";
    echo "<div class='debug'>  - Mapped SKUs: " . ($mappingResults['mapped_skus'] ?? 0) . "</div>";
    echo "<div class='debug'>  - Conflicts: " . ($mappingResults['conflicts'] ?? 0) . "</div>";
    echo "<div class='debug'>  - Errors: " . ($mappingResults['errors'] ?? 0) . "</div>";
    echo "<div class='debug'>  - Execution time: " . ($mappingResults['execution_time'] ?? 0) . "ms</div>";
    echo "</div>";

    // === STEP 6: ANALISI RISULTATI PER OGNI SKU ===
    echo "<div class='step'>";
    echo "<h2>🔍 STEP 6: Analisi Risultati per Ogni SKU</h2>";
    
    foreach ($testSkus as $testSku) {
        echo "<div class='debug'>";
        echo "<strong>🧪 TEST: {$testSku['sku']}</strong><br>";
        echo "Expected: {$testSku['expected']}<br>";
        
        // Verifica stato attuale dello SKU
        $stmt = $dbConnection->prepare("
            SELECT i.sku, i.product_id as inventory_product_id, p.nome as product_name,
                   ms.id as mapping_state_id, ms.product_id as state_product_id, 
                   ms.mapping_type, ms.confidence_score, ms.metadata
            FROM inventory i
            LEFT JOIN products p ON i.product_id = p.id
            LEFT JOIN mapping_states ms ON ms.user_id = i.user_id AND ms.sku = i.sku AND ms.source_table = 'inventory'
            WHERE i.user_id = ? AND i.sku = ?
        ");
        $stmt->execute([$userId, $testSku['sku']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $actualResult = 'unknown';
            
            // Determina il risultato effettivo
            if ($result['inventory_product_id']) {
                $actualResult = 'exact_match';
            } elseif ($result['mapping_state_id']) {
                if ($result['state_product_id']) {
                    if ($result['confidence_score'] >= 0.95) {
                        $actualResult = 'auto_approve';
                    } else {
                        $actualResult = 'manual_approved';
                    }
                } else {
                    $actualResult = 'pending_approval';
                }
            } else {
                $actualResult = 'auto_reject';
            }
            
            $statusClass = ($actualResult === $testSku['expected']) ? 'success' : 'error';
            echo "Actual: <span style='color:" . ($statusClass === 'success' ? 'green' : 'red') . "'>{$actualResult}</span><br>";
            echo "Status: " . ($statusClass === 'success' ? '✅ PASS' : '❌ FAIL') . "<br>";
            
            if ($result['inventory_product_id']) {
                echo "Product ID: {$result['inventory_product_id']} - {$result['product_name']}<br>";
            }
            
            if ($result['mapping_state_id']) {
                echo "Mapping State ID: {$result['mapping_state_id']}<br>";
                echo "Mapping Type: {$result['mapping_type']}<br>";
                echo "Confidence: " . ($result['confidence_score'] ?? 'N/A') . "<br>";
                
                if ($result['metadata']) {
                    $metadata = json_decode($result['metadata'], true);
                    echo "Metadata: " . json_encode($metadata, JSON_PRETTY_PRINT) . "<br>";
                }
            }
        } else {
            echo "❌ SKU non trovato dopo mapping!<br>";
        }
        
        echo "</div><hr>";
    }
    echo "</div>";

    // === STEP 7: STATISTICHE PENDING MAPPINGS ===
    echo "<div class='step'>";
    echo "<h2>📊 STEP 7: Statistiche Pending Mappings</h2>";
    
    try {
        $pendingStats = $mappingService->getPendingMappingStatistics($userId);
        echo "<div class='debug'>📈 Statistiche Pending:</div>";
        echo "<div class='debug'>  - Total pending: " . $pendingStats['total_pending'] . "</div>";
        echo "<div class='debug'>  - High confidence: " . $pendingStats['high_confidence'] . "</div>";
        echo "<div class='debug'>  - Medium confidence: " . $pendingStats['medium_confidence'] . "</div>";
        echo "<div class='debug'>  - Low confidence: " . $pendingStats['low_confidence'] . "</div>";
        echo "<div class='debug'>  - Avg confidence: " . $pendingStats['avg_confidence'] . "</div>";
    } catch (Exception $e) {
        echo "<div class='debug error'>❌ Errore statistiche: " . $e->getMessage() . "</div>";
    }
    echo "</div>";

    // === STEP 8: TEST UI PENDING MAPPINGS ===
    echo "<div class='step'>";
    echo "<h2>🖥️ STEP 8: Test UI Pending Mappings</h2>";
    
    try {
        $pendingMappings = $mappingService->getPendingMappingsForApproval($userId);
        echo "<div class='debug'>📋 Pending mappings trovati: " . count($pendingMappings) . "</div>";
        
        foreach ($pendingMappings as $mapping) {
            echo "<div class='debug'>";
            echo "  - SKU: {$mapping['sku']}<br>";
            echo "    Confidence: " . round($mapping['confidence_score'] * 100, 1) . "%<br>";
            echo "    Suggested Product: {$mapping['suggested_product_name']}<br>";
            echo "    Created: {$mapping['created_at']}<br>";
            echo "</div>";
        }
    } catch (Exception $e) {
        echo "<div class='debug error'>❌ Errore pending mappings: " . $e->getMessage() . "</div>";
    }
    echo "</div>";

    // === STEP 9: PULIZIA FINALE (OPZIONALE) ===
    echo "<div class='step warning'>";
    echo "<h2>🧹 STEP 9: Pulizia Finale</h2>";
    echo "<div class='debug'>⚠️ I dati test sono stati lasciati per ispezione manuale</div>";
    echo "<div class='debug'>💡 Per pulire: esegui nuovamente questo script o vai su sku_aggregation_interface.php</div>";
    echo "<div class='debug'>💡 Per testare UI: vai su sku_aggregation_interface.php → tab 'Approvazioni Pending'</div>";
    echo "</div>";

    echo "<div class='step success'>";
    echo "<h2>✅ TEST COMPLETATO!</h2>";
    echo "<div class='debug'>🎯 Il sistema di approvazione fuzzy è stato testato con successo</div>";
    echo "<div class='debug'>📊 Controlla i risultati sopra per verificare il comportamento</div>";
    echo "<div class='debug'>🔗 Vai su: <a href='sku_aggregation_interface.php?user_id={$userId}'>sku_aggregation_interface.php</a></div>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div class='step error'>";
    echo "<h2>❌ ERRORE DURANTE IL TEST</h2>";
    echo "<div class='debug'>Errore: " . $e->getMessage() . "</div>";
    echo "<div class='debug'>File: " . $e->getFile() . "</div>";
    echo "<div class='debug'>Linea: " . $e->getLine() . "</div>";
    echo "</div>";
}

echo "<div style='margin-top: 30px; text-align: center; color: #666;'>";
echo "🧪 Test Fuzzy Approval System - Margynomic " . date('Y-m-d H:i:s');
echo "</div>";
?>