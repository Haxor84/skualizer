<?php
/**
 * 🔍 DEBUG COMPLETO - Analisi Mapping e Categorie
 * Visita questo file via browser: https://tuosito.com/modules/orderinsights/debug_complete.php
 */

// Setup
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../margynomic/margini/fee_mapping_helpers.php';
require_once 'OverviewModel.php';

// Simula login (modifica user_id se necessario)
if (!isset($_SESSION)) session_start();
$_SESSION['user'] = ['id' => 2, 'email' => 'debug@test.com'];
$userId = 2;

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔍 Debug OrderInsights - Mapping Categorie</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f7fa; 
            padding: 20px;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 { color: #2c3e50; margin-bottom: 30px; font-size: 28px; }
        h2 { 
            color: #34495e; 
            margin: 30px 0 15px 0; 
            padding: 10px; 
            background: #ecf0f1; 
            border-left: 4px solid #3498db;
            font-size: 20px;
        }
        h3 { color: #7f8c8d; margin: 20px 0 10px 0; font-size: 16px; }
        .section { 
            background: white; 
            padding: 25px; 
            margin-bottom: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .success { 
            color: #27ae60; 
            font-weight: bold; 
            padding: 8px 12px; 
            background: #d5f4e6;
            border-radius: 4px;
            display: inline-block;
            margin: 5px 0;
        }
        .error { 
            color: #e74c3c; 
            font-weight: bold; 
            padding: 8px 12px; 
            background: #fadbd8;
            border-radius: 4px;
            display: inline-block;
            margin: 5px 0;
        }
        .warning { 
            color: #f39c12; 
            font-weight: bold; 
            padding: 8px 12px; 
            background: #fcf3cf;
            border-radius: 4px;
            display: inline-block;
            margin: 5px 0;
        }
        .info { 
            color: #3498db; 
            padding: 8px 12px; 
            background: #d6eaf8;
            border-radius: 4px;
            margin: 10px 0;
        }
        code { 
            background: #f8f9fa; 
            padding: 2px 6px; 
            border-radius: 3px; 
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        pre { 
            background: #2c3e50; 
            color: #ecf0f1; 
            padding: 15px; 
            border-radius: 5px; 
            overflow-x: auto;
            font-size: 13px;
            margin: 10px 0;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 15px 0;
            font-size: 14px;
        }
        th { 
            background: #34495e; 
            color: white; 
            padding: 12px; 
            text-align: left;
            font-weight: 600;
        }
        td { 
            padding: 10px; 
            border-bottom: 1px solid #ecf0f1;
        }
        tr:nth-child(even) { background: #f8f9fa; }
        tr:hover { background: #e8f4f8; }
        .highlight { 
            background: yellow; 
            font-weight: bold;
            padding: 2px 4px;
        }
        .byte-compare {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #dee2e6;
            border-radius: 4px;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            color: #7f8c8d;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug OrderInsights - Analisi Mapping Categorie</h1>
        
<?php
// ============================================================================
// TEST 1: VERIFICA MAPPING DATABASE
// ============================================================================
echo '<div class="section">';
echo '<h2>1️⃣ Verifica Mapping Database</h2>';

try {
    $pdo = getDbConnection();
    
    // Query mapping per Order
    $stmt = $pdo->prepare("
        SELECT transaction_type, category, user_id, is_active 
        FROM transaction_fee_mappings 
        WHERE transaction_type = 'Order' 
          AND (user_id = ? OR user_id IS NULL)
        ORDER BY user_id DESC
    ");
    $stmt->execute([$userId]);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($mappings)) {
        echo '<div class="error">❌ PROBLEMA: Nessun mapping trovato per "Order"</div>';
    } else {
        echo '<div class="success">✅ Mapping trovato nel database</div>';
        echo '<table>';
        echo '<tr><th>Transaction Type</th><th>Category</th><th>User ID</th><th>Active</th></tr>';
        foreach ($mappings as $m) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($m['transaction_type']) . '</td>';
            echo '<td><strong>' . htmlspecialchars($m['category']) . '</strong></td>';
            echo '<td>' . ($m['user_id'] ?? 'NULL (globale)') . '</td>';
            echo '<td>' . ($m['is_active'] ? '✅' : '❌') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
} catch (Exception $e) {
    echo '<div class="error">❌ Errore DB: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>';

// ============================================================================
// TEST 2: VERIFICA getTransactionCategory()
// ============================================================================
echo '<div class="section">';
echo '<h2>2️⃣ Test getTransactionCategory("Order", ' . $userId . ')</h2>';

try {
    $categoryFromDB = getTransactionCategory('Order', $userId);
    
    echo '<div class="info">';
    echo '<strong>Risultato:</strong> <code>' . var_export($categoryFromDB, true) . '</code><br>';
    echo '<strong>Tipo:</strong> ' . gettype($categoryFromDB) . '<br>';
    echo '<strong>Lunghezza:</strong> ' . strlen($categoryFromDB) . ' bytes<br>';
    echo '<strong>Bytes (hex):</strong> ' . bin2hex($categoryFromDB);
    echo '</div>';
    
    if ($categoryFromDB === 'FEE_TAB1') {
        echo '<div class="success">✅ Ritorna "FEE_TAB1" come atteso</div>';
    } else {
        echo '<div class="warning">⚠️ Ritorna: "' . htmlspecialchars($categoryFromDB) . '" (atteso: "FEE_TAB1")</div>';
    }
} catch (Exception $e) {
    echo '<div class="error">❌ Errore: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>';

// ============================================================================
// TEST 3: VERIFICA mapCategory()
// ============================================================================
echo '<div class="section">';
echo '<h2>3️⃣ Test mapCategory("Order", ' . $userId . ')</h2>';

try {
    $mappedCategory = OverviewModel::mapCategory('Order', $userId);
    
    echo '<div class="info">';
    echo '<strong>Risultato finale:</strong> <code>' . htmlspecialchars($mappedCategory) . '</code><br>';
    echo '<strong>Tipo:</strong> ' . gettype($mappedCategory) . '<br>';
    echo '<strong>Lunghezza:</strong> ' . strlen($mappedCategory) . ' bytes<br>';
    echo '<strong>Bytes (hex):</strong> ' . bin2hex($mappedCategory);
    echo '</div>';
    
    if ($mappedCategory === 'Ricavi Vendite') {
        echo '<div class="success">✅ Ritorna "Ricavi Vendite" (CORRETTO!)</div>';
    } else {
        echo '<div class="error">❌ Ritorna: "' . htmlspecialchars($mappedCategory) . '" (atteso: "Ricavi Vendite")</div>';
    }
    
    // Confronto byte per byte
    echo '<h3>🔬 Confronto Byte-per-Byte</h3>';
    echo '<div class="byte-compare">';
    echo '<strong>Atteso:</strong> "Ricavi Vendite"<br>';
    echo '<strong>Ricevuto:</strong> "' . htmlspecialchars($mappedCategory) . '"<br><br>';
    
    $expected = 'Ricavi Vendite';
    if ($mappedCategory === $expected) {
        echo '<div class="success">✅ Match perfetto (===)</div>';
    } else {
        echo '<div class="error">❌ NON corrispondono</div>';
        echo '<strong>Differenze:</strong><br>';
        echo 'Atteso lunghezza: ' . strlen($expected) . ' bytes<br>';
        echo 'Ricevuto lunghezza: ' . strlen($mappedCategory) . ' bytes<br>';
        echo 'Atteso hex: ' . bin2hex($expected) . '<br>';
        echo 'Ricevuto hex: ' . bin2hex($mappedCategory) . '<br>';
    }
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="error">❌ Errore: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>';

// ============================================================================
// TEST 4: ANALISI CATEGORIE DA monthSummary()
// ============================================================================
echo '<div class="section">';
echo '<h2>4️⃣ Analisi Categorie da monthSummary()</h2>';

try {
    $result = OverviewModel::monthSummary('2025-09-01', '2025-10-01', $userId, false);
    
    echo '<h3>📊 KPI Generali</h3>';
    echo '<table>';
    echo '<tr><th>Metrica</th><th>Valore</th></tr>';
    echo '<tr><td>Incassato Vendite</td><td><strong>' . number_format($result['kpi']['incassato_vendite'], 2) . '€</strong></td></tr>';
    echo '<tr><td>Ordini</td><td>' . $result['kpi']['ordini'] . '</td></tr>';
    echo '<tr><td>Transazioni</td><td>' . $result['kpi']['transazioni'] . '</td></tr>';
    echo '</table>';
    
    echo '<h3>📋 Breakdown per Categoria</h3>';
    
    if (!isset($result['categorie']) || !is_array($result['categorie']) || empty($result['categorie'])) {
        echo '<div class="error">❌ Nessuna categoria trovata o formato non valido!</div>';
        echo '<pre>Tipo: ' . gettype($result['categorie']) . '</pre>';
        if (isset($result['categorie'])) {
            echo '<pre>Valore: ' . htmlspecialchars(print_r($result['categorie'], true)) . '</pre>';
        }
    } else {
        echo '<p><strong>Numero categorie:</strong> ' . count($result['categorie']) . '</p>';
        echo '<table>';
        echo '<tr><th>Categoria</th><th>Importo €</th><th>Transazioni</th><th>Ordini</th><th>Bytes Nome</th></tr>';
        
        $foundRicaviVendite = false;
        $totalTrans = 0;
        
        foreach ($result['categorie'] as $cat) {
            if (!is_array($cat) || !isset($cat['categoria'])) {
                echo '<tr><td colspan="5"><div class="error">Riga categoria non valida: ' . htmlspecialchars(print_r($cat, true)) . '</div></td></tr>';
                continue;
            }
            $catName = $cat['categoria'];
            $isRicaviVendite = ($catName === 'Ricavi Vendite');
            
            if ($isRicaviVendite) $foundRicaviVendite = true;
            
            $totalTrans += $cat['transazioni'];
            
            echo '<tr' . ($isRicaviVendite ? ' style="background:#d5f4e6;"' : '') . '>';
            echo '<td><strong>' . htmlspecialchars($catName) . '</strong>';
            if ($isRicaviVendite) echo ' <span class="success">← TARGET</span>';
            echo '</td>';
            echo '<td>' . number_format($cat['importo_eur'], 2) . '€</td>';
            echo '<td>' . $cat['transazioni'] . '</td>';
            echo '<td>' . $cat['ordini'] . '</td>';
            echo '<td><code>' . bin2hex($catName) . '</code></td>';
            echo '</tr>';
        }
        
        echo '<tr style="background:#34495e;color:white;font-weight:bold;">';
        echo '<td>TOTALE</td><td>-</td><td>' . $totalTrans . '</td><td>-</td><td>-</td>';
        echo '</tr>';
        echo '</table>';
        
        // Verifica presenza Ricavi Vendite
        if ($foundRicaviVendite) {
            echo '<div class="success">✅ Categoria "Ricavi Vendite" TROVATA nel breakdown</div>';
            
            // Verifica se ha transazioni
            foreach ($result['categorie'] as $cat) {
                if ($cat['categoria'] === 'Ricavi Vendite') {
                    if ($cat['transazioni'] == 0) {
                        echo '<div class="error">❌ PROBLEMA: "Ricavi Vendite" ha 0 transazioni!</div>';
                        echo '<p>Importo: ' . number_format($cat['importo_eur'], 2) . '€<br>';
                        echo 'Transazioni: ' . $cat['transazioni'] . '<br>';
                        echo 'Ordini: ' . $cat['ordini'] . '</p>';
                    } else {
                        echo '<div class="success">✅ "Ricavi Vendite" ha ' . $cat['transazioni'] . ' transazioni</div>';
                    }
                    break;
                }
            }
        } else {
            echo '<div class="error">❌ PROBLEMA CRITICO: Categoria "Ricavi Vendite" NON trovata!</div>';
        }
        
        // Analisi transazioni mancanti
        $expectedTrans = $result['kpi']['transazioni'];
        if ($totalTrans < $expectedTrans) {
            $missing = $expectedTrans - $totalTrans;
            echo '<div class="warning">⚠️ Transazioni mancanti nel breakdown: ' . $missing . ' (totale KPI: ' . $expectedTrans . ', totale categorie: ' . $totalTrans . ')</div>';
        }
    }
    
} catch (Exception $e) {
    echo '<div class="error">❌ Errore: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}

echo '</div>';

// ============================================================================
// TEST 5: VERIFICA CACHE CATEGORIE
// ============================================================================
echo '<div class="section">';
echo '<h2>5️⃣ Verifica Cache Categorie nel Loop</h2>';

echo '<p>Questo test simula il loop interno di monthSummary per vedere cosa viene cachato.</p>';

try {
    $catCache = [];
    $testTypes = ['Order', 'Refund', 'Storage Fee', 'WAREHOUSE_DAMAGE'];
    
    echo '<table>';
    echo '<tr><th>Transaction Type</th><th>Categoria Mappata</th><th>Bytes</th><th>Match "Ricavi Vendite"?</th></tr>';
    
    foreach ($testTypes as $type) {
        if (!isset($catCache[$type])) {
            $catCache[$type] = OverviewModel::mapCategory($type, $userId);
        }
        $cat = $catCache[$type];
        $isMatch = ($cat === 'Ricavi Vendite');
        
        echo '<tr' . ($type === 'Order' ? ' style="background:#fef9e7;"' : '') . '>';
        echo '<td><strong>' . htmlspecialchars($type) . '</strong></td>';
        echo '<td>' . htmlspecialchars($cat) . ($isMatch ? ' <span class="success">✅ MATCH</span>' : '') . '</td>';
        echo '<td><code>' . bin2hex($cat) . '</code></td>';
        echo '<td>' . ($isMatch ? '<span class="success">SÌ</span>' : '<span class="error">NO</span>') . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    
    // Test specifico per Order
    $orderCat = $catCache['Order'];
    if ($orderCat === 'Ricavi Vendite') {
        echo '<div class="success">✅ Cache per "Order" restituisce "Ricavi Vendite" correttamente</div>';
    } else {
        echo '<div class="error">❌ Cache per "Order" restituisce "' . htmlspecialchars($orderCat) . '" invece di "Ricavi Vendite"</div>';
    }
    
} catch (Exception $e) {
    echo '<div class="error">❌ Errore: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>';

// ============================================================================
// TEST 6: CONTA RIGHE ORDER NEL DATABASE
// ============================================================================
echo '<div class="section">';
echo '<h2>6️⃣ Verifica Righe Order nel Database</h2>';

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_righe,
            COUNT(DISTINCT order_id) as ordini_unici,
            COUNT(DISTINCT price_type) as price_types,
            SUM(COALESCE(price_amount, 0)) as sum_price_amount
        FROM report_settlement_2
        WHERE transaction_type = 'Order'
          AND posted_date >= '2025-09-01'
          AND posted_date < '2025-10-01'
    ");
    $stmt->execute();
    $dbStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo '<table>';
    echo '<tr><th>Metrica DB</th><th>Valore</th></tr>';
    echo '<tr><td>Righe totali Order</td><td><strong>' . $dbStats['total_righe'] . '</strong></td></tr>';
    echo '<tr><td>Ordini unici</td><td>' . $dbStats['ordini_unici'] . '</td></tr>';
    echo '<tr><td>Price Types diversi</td><td>' . $dbStats['price_types'] . '</td></tr>';
    echo '<tr><td>Somma price_amount</td><td>' . number_format($dbStats['sum_price_amount'], 2) . '€</td></tr>';
    echo '</table>';
    
    // Confronto con KPI
    echo '<h3>📊 Confronto DB vs KPI</h3>';
    if (isset($result['kpi']['ordini'])) {
        $kpiOrdini = $result['kpi']['ordini'];
        if ($kpiOrdini == $dbStats['ordini_unici']) {
            echo '<div class="success">✅ Ordini nel KPI corrispondono al DB (' . $kpiOrdini . ')</div>';
        } else {
            echo '<div class="warning">⚠️ Mismatch ordini: KPI=' . $kpiOrdini . ', DB=' . $dbStats['ordini_unici'] . '</div>';
        }
    }
    
} catch (Exception $e) {
    echo '<div class="error">❌ Errore: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '</div>';

// ============================================================================
// RIEPILOGO FINALE
// ============================================================================
echo '<div class="section" style="background:#ecf0f1;border-left:4px solid #e74c3c;">';
echo '<h2>🎯 RIEPILOGO DIAGNOSI</h2>';

$issues = [];
$successes = [];

// Check 1: Mapping DB
if (!empty($mappings)) {
    $successes[] = 'Mapping database presente per "Order"';
} else {
    $issues[] = 'Mapping database MANCANTE per "Order"';
}

// Check 2: getTransactionCategory
if (isset($categoryFromDB) && $categoryFromDB === 'FEE_TAB1') {
    $successes[] = 'getTransactionCategory() funziona correttamente';
} else {
    $issues[] = 'getTransactionCategory() NON ritorna "FEE_TAB1"';
}

// Check 3: mapCategory
if (isset($mappedCategory) && $mappedCategory === 'Ricavi Vendite') {
    $successes[] = 'mapCategory() converte correttamente a "Ricavi Vendite"';
} else {
    $issues[] = 'mapCategory() NON converte a "Ricavi Vendite"';
}

// Check 4: Categoria nel breakdown
if (isset($foundRicaviVendite) && $foundRicaviVendite) {
    $successes[] = 'Categoria "Ricavi Vendite" presente nel breakdown';
    
    // Check transazioni
    if (isset($result['categorie'])) {
        foreach ($result['categorie'] as $cat) {
            if ($cat['categoria'] === 'Ricavi Vendite') {
                if ($cat['transazioni'] > 0) {
                    $successes[] = 'Categoria "Ricavi Vendite" ha ' . $cat['transazioni'] . ' transazioni';
                } else {
                    $issues[] = 'Categoria "Ricavi Vendite" ha 0 transazioni (MISMATCH!)';
                }
                break;
            }
        }
    }
} else {
    $issues[] = 'Categoria "Ricavi Vendite" ASSENTE dal breakdown';
}

if (empty($issues)) {
    echo '<div class="success" style="font-size:18px;padding:15px;">✅ TUTTI I TEST PASSATI - SISTEMA FUNZIONANTE!</div>';
} else {
    echo '<div class="error" style="font-size:18px;padding:15px;">❌ PROBLEMI RILEVATI</div>';
    echo '<h3>Problemi:</h3><ul>';
    foreach ($issues as $issue) {
        echo '<li style="color:#e74c3c;margin:5px 0;">' . $issue . '</li>';
    }
    echo '</ul>';
}

if (!empty($successes)) {
    echo '<h3>✅ Test Passati:</h3><ul>';
    foreach ($successes as $success) {
        echo '<li style="color:#27ae60;margin:5px 0;">' . $success . '</li>';
    }
    echo '</ul>';
}

echo '</div>';

?>
        
        <div class="footer">
            <p>🔍 Debug OrderInsights - <?php echo date('Y-m-d H:i:s'); ?></p>
            <p>User ID: <?php echo $userId; ?> | Periodo: 2025-09-01 → 2025-10-01</p>
        </div>
    </div>
</body>
</html>

