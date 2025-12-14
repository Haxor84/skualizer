<?php
/**
 * Get Chart Data API - Fornisce dati per i grafici della dashboard margini
 * File: modules/margynomic/margini/get_chart_data.php
 */

// Avvia sessione PRIMA di qualsiasi header
session_start();

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

try {
    require_once 'config_shared.php';
    require_once 'margins_engine.php';
    require_once __DIR__ . '/../../listing/helpers.php';

    // Autenticazione con gestione errori migliorata
    try {
        $currentUser = requireUserAuth();
        $userId = $currentUser['id'];
    } catch (Exception $authError) {
        throw new Exception('Autenticazione fallita: ' . $authError->getMessage());
    }

    // Tipo di grafico richiesto
    $chartType = $_GET['type'] ?? '';
    
    if (empty($chartType)) {
        throw new Exception('Tipo di grafico non specificato');
    }

    switch ($chartType) {
        case 'revenue_vs_fee':
            $data = getRevenueVsFeeData($userId);
            break;
            
        case 'top_products':
            $data = getTopProductsData($userId);
            break;
            
        default:
            throw new Exception('Tipo di grafico non valido');
    }

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Ottiene dati per il grafico Revenue vs Fee giornaliere (ultimi 30 giorni)
 */
function getRevenueVsFeeData($userId) {
    $db = getDbConnection();
    $tableName = "report_settlement_{$userId}";
    
    // Ultimi 30 giorni con metriche estese
    $sql = "
        SELECT 
            DATE(posted_date) as date,
            SUM(CASE WHEN transaction_type = 'Order' THEN COALESCE(price_amount, 0) ELSE 0 END) as daily_revenue,
            SUM(CASE WHEN transaction_type = 'Order' THEN 
                COALESCE(item_related_fee_amount, 0) + COALESCE(other_amount, 0)
                ELSE 0 END) as daily_fees,
            SUM(CASE WHEN transaction_type = 'Order' THEN 
                COALESCE(quantity_purchased, 0) ELSE 0 END) as daily_units,
            COUNT(DISTINCT CASE WHEN transaction_type = 'Order' THEN product_id END) as products_sold
        FROM `{$tableName}` s
        WHERE posted_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        AND posted_date < CURDATE()
        GROUP BY DATE(posted_date)
        ORDER BY date ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepara dati per Chart.js con metriche estese
    $labels = [];
    $revenueData = [];
    $feeData = [];
    $unitsData = [];
    $marginData = [];
    $feePerUnitData = [];
    
    foreach ($results as $row) {
        $revenue = floatval($row['daily_revenue']);
        $fees = floatval($row['daily_fees']);
        $units = floatval($row['daily_units']);
        
        // Calcoli derivati corretti
        $profit = $revenue + $fees; // fees sono già negativi nel DB
        $marginPercent = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
        $feePerUnit = $units > 0 ? abs($fees) / $units : 0;
        
        $labels[] = date('d/m', strtotime($row['date']));
        $revenueData[] = $revenue;
        $feeData[] = $fees * -1; // Negativo per mostrare come costo
        $unitsData[] = $units;
        $marginData[] = round($marginPercent, 2);
        $feePerUnitData[] = round($feePerUnit, 2);
    }
    
    return [
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Revenue (€)',
                'data' => $revenueData,
                'borderColor' => '#16a34a',
                'backgroundColor' => 'rgba(22, 163, 74, 0.1)',
                'fill' => true,
                'tension' => 0.4,
                'borderWidth' => 3,
                'yAxisID' => 'y-left'
            ],
            [
                'label' => 'Fee (€)',
                'data' => $feeData,
                'borderColor' => '#dc2626',
                'backgroundColor' => 'rgba(220, 38, 38, 0.1)',
                'fill' => true,
                'tension' => 0.4,
                'borderWidth' => 3,
                'yAxisID' => 'y-left'
            ],
            [
                'label' => 'Margine %',
                'data' => $marginData,
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.1)',
                'fill' => false,
                'tension' => 0.4,
                'borderWidth' => 2,
                'yAxisID' => 'y-right',
                'type' => 'line'
            ],
            [
                'label' => 'Unità',
                'data' => $unitsData,
                'borderColor' => '#ea580c',
                'backgroundColor' => 'rgba(234, 88, 12, 0.1)',
                'fill' => false,
                'tension' => 0.4,
                'borderWidth' => 2,
                'yAxisID' => 'y-right',
                'type' => 'line'
            ]
        ],
        'metadata' => [
            'fee_per_unit' => $feePerUnitData,
            'total_days' => count($labels),
            'avg_margin' => count($marginData) > 0 ? round(array_sum($marginData) / count($marginData), 2) : 0
        ]
    ];
}

/**
 * Ottiene dati per il grafico Top 5 Prodotti Profittevoli
 */
function getTopProductsData($userId) {
    // Calcola margini per tutti i prodotti
    $engine = new MarginsEngine($userId);
    $marginsData = $engine->calculateMargins([]);
    
    if (!$marginsData['success'] || empty($marginsData['data'])) {
        return [
            'labels' => [],
            'datasets' => []
        ];
    }
    
    // Filtra e ordina prodotti
    $products = array_filter($marginsData['data'], function($product) {
        return ($product['units_sold'] ?? 0) >= 5; // Minimo 5 unità vendute
    });
    
    // Ordina per utile totale decrescente e prendi i top 7
    usort($products, function($a, $b) {
        return ($b['net_profit'] ?? 0) <=> ($a['net_profit'] ?? 0);
    });
    
    $topProducts = array_slice($products, 0, 7);
    
    $barData = [];
    $backgroundColors = [];
    $borderColors = [];
    $productNames = [];
    $marginLabels = [];
    
    foreach ($topProducts as $index => $product) {
        $netProfit = floatval($product['net_profit'] ?? 0);
        $units = intval($product['units_sold'] ?? 0);
        $marginPercent = floatval($product['margin_percentage'] ?? 0);
        $profitPerUnit = $units > 0 ? $netProfit / $units : 0;
        
        // Tronca nome prodotto per visualizzazione
        $name = $product['product_name'];
        if (strlen($name) > 30) {
            $name = substr($name, 0, 27) . '...';
        }
        $productNames[] = $name;
        
        // Bar data: utile per unità
        $barData[] = round($profitPerUnit, 2);
        $marginLabels[] = round($marginPercent, 1) . '%';
        
        // Colori basati su fasce di margine (corretti)
        if ($marginPercent < 0) {
            $backgroundColors[] = 'rgba(239, 68, 68, 0.8)'; // Rosso per margine negativo
            $borderColors[] = '#ef4444';
        } elseif ($marginPercent <= 10) {
            $backgroundColors[] = 'rgba(245, 158, 11, 0.8)'; // Giallo per margine 0-10%
            $borderColors[] = '#f59e0b';
        } else {
            $backgroundColors[] = 'rgba(16, 185, 129, 0.8)'; // Verde per margine >10%
            $borderColors[] = '#10b981';
        }
    }
    
    return [
        'labels' => $productNames,
        'datasets' => [
            [
                'label' => 'Utile per Unità (€)',
                'data' => $barData,
                'backgroundColor' => $backgroundColors,
                'borderColor' => $borderColors,
                'borderWidth' => 2,
                'borderRadius' => 8,
                'borderSkipped' => false,
                'barThickness' => 40
            ]
        ],
        'metadata' => [
            'total_products' => count($topProducts),
            'chart_type' => 'horizontalBar',
            'margin_labels' => $marginLabels,
            'product_details' => array_map(function($product, $index) use ($marginLabels, $barData) {
                return [
                    'name' => $product['product_name'],
                    'units' => $product['units_sold'],
                    'total_profit' => $product['net_profit'],
                    'margin_percent' => $marginLabels[$index],
                    'profit_per_unit' => $barData[$index]
                ];
            }, $topProducts, array_keys($topProducts)),
            'color_legend' => [
                'red' => 'Margine < 0%',
                'yellow' => 'Margine 0-10%', 
                'green' => 'Margine > 10%'
            ]
        ]
    ];
}
?> 