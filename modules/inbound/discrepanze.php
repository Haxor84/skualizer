<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure session is started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
require_once '../margynomic/config/config.php';
require_once '../margynomic/login/auth_helpers.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: ../margynomic/login/login.php');
    exit;
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Create PDO connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    die("Errore connessione database: " . $e->getMessage());
}

// Verifica che i transaction_type TAB3 siano mappati
try {
    $verifyTab3 = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM fee_categories 
        WHERE group_type = 'TAB3' 
        AND is_active = 1
    ");
    $verifyTab3->execute();
    $tab3Count = $verifyTab3->fetch(PDO::FETCH_ASSOC);
    
    if ($tab3Count['count'] == 0) {
        die("⚠️ ERRORE: Nessuna categoria TAB3 configurata. Verificare admin_fee_mappings.php");
    }
} catch (PDOException $e) {
    // Se la tabella non esiste, continua comunque (compatibilità)
}

// Get sorting parameters
$sortColumn = $_GET['sort'] ?? 'position';
$sortOrder = $_GET['order'] ?? 'ASC';

// Validate sort parameters
$allowedColumns = ['product_name', 'costo_prodotto', 'unita_inviate', 'unita_ricevute', 'unita_vendute', 'giacenza', 'unita_rimborsate', 'discrepanza', 'costo_totale_inviate', 'importo_rimborsato', 'costo_non_recuperato', 'position', 'stato_attuale', 'costo_mp_persa', 'saldo_netto'];
if (!in_array($sortColumn, $allowedColumns)) {
    $sortColumn = 'position';
}
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Get discrepancy data
$discrepanzeData = [];

try {
    // Query principale: aggrega dati per product_id
    // ESCLUDE spedizioni CANCELLED e DELETED dal calcolo
    // USA SUBQUERY per removal_orders per evitare prodotto cartesiano
    $sql = "
        SELECT 
            p.id as product_id,
            p.nome as product_name,
            p.sku,
            p.costo_prodotto,
            COALESCE(pdo.position, 999999) as position,
            
            -- Unità inviate/ricevute da inbound (SOLO spedizioni attive)
            COALESCE(SUM(isi.quantity_shipped), 0) as unita_inviate,
            COALESCE(SUM(isi.quantity_received), 0) as unita_ricevute,
            COALESCE(SUM(isi.quantity_shipped * p.costo_prodotto), 0) as costo_totale_inviate,
            
            -- Unità ritirate da removal_orders (SUBQUERY per evitare prodotto cartesiano)
            COALESCE(ro_agg.unita_ritirate, 0) as unita_ritirate,
            
            -- Giacenza da inventory (SUBQUERY per evitare prodotto cartesiano)
            COALESCE(inv.giacenza_totale, 0) as giacenza,
            
            -- Placeholder per unità vendute (verrà calcolato dopo)
            0 as unita_vendute,
            
            -- Placeholder per rimborsi
            0 as unita_rimborsate,
            0 as importo_rimborsato
            
        FROM products p
        LEFT JOIN product_display_order pdo ON pdo.product_id = p.id AND pdo.user_id = p.user_id
        LEFT JOIN inbound_shipment_items isi ON isi.product_id = p.id AND isi.user_id = ?
        LEFT JOIN inbound_shipments iship ON iship.id = isi.shipment_id
        LEFT JOIN (
            SELECT 
                product_id,
                SUM(shipped_quantity) as unita_ritirate
            FROM removal_orders
            WHERE user_id = ?
            AND (order_status IS NULL OR order_status != 'Cancelled')
            GROUP BY product_id
        ) ro_agg ON ro_agg.product_id = p.id
        LEFT JOIN (
            SELECT 
                product_id,
                SUM(afn_warehouse_quantity) as giacenza_totale
            FROM inventory
            WHERE user_id = ?
            GROUP BY product_id
        ) inv ON inv.product_id = p.id
        WHERE p.user_id = ?
        AND (iship.shipment_status IS NULL OR iship.shipment_status NOT IN ('CANCELLED', 'DELETED'))
        GROUP BY p.id, p.nome, p.sku, p.costo_prodotto, pdo.position, inv.giacenza_totale, ro_agg.unita_ritirate
        HAVING unita_inviate > 0 OR unita_ricevute > 0 OR unita_ritirate > 0
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $discrepanzeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Inizializza campi per tutti i prodotti
    foreach ($discrepanzeData as &$row) {
        $row['unita_vendute'] = 0;
        $row['unita_rimborsate'] = 0;
        $row['importo_rimborsato'] = 0;
        $row['num_rimborsi'] = 0;
        $row['discrepanza'] = 0;
        $row['costo_non_recuperato'] = 0;
        $row['rimborsi_by_type'] = []; // Rimborsi separati per transaction_type
    }
    unset($row);
    
    // Per ogni prodotto, ottieni unità vendute e rimborsi da settlement
    $tableName = "report_settlement_{$userId}";
    
    // Verifica se la tabella settlement esiste
    $checkTable = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
    $tableExists = $checkTable->fetchColumn();
    
    // Ottieni tutti i transaction_type TAB3 presenti per creare colonne dinamiche
    $allReimbursementTypes = [];
    if ($tableExists) {
        try {
            $stmtAllTypes = $pdo->prepare("
                SELECT DISTINCT s.transaction_type
                FROM `{$tableName}` s
                INNER JOIN transaction_fee_mappings tfm 
                    ON tfm.transaction_type = s.transaction_type
                    AND (tfm.user_id = ? OR tfm.user_id IS NULL)
                INNER JOIN fee_categories fc 
                    ON fc.category_code = tfm.category
                    AND fc.group_type = 'TAB3'
                WHERE s.product_id IN (
                    SELECT DISTINCT p.id 
                    FROM products p 
                    WHERE p.user_id = ?
                )
                ORDER BY s.transaction_type
            ");
            $stmtAllTypes->execute([$userId, $userId]);
            $allReimbursementTypes = $stmtAllTypes->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // Se le tabelle di mapping non esistono, continua senza colonne dinamiche
        }
    }
    
    if ($tableExists) {
        foreach ($discrepanzeData as &$row) {
            $productId = $row['product_id'];
            
            if ($productId) {
                // Unità vendute NETTE (Order - Refund + REVERSAL_REIMBURSEMENT)
                $stmtVendute = $pdo->prepare("
                    SELECT 
                        -- Unità Order (quantity_purchased)
                        COALESCE(SUM(CASE WHEN transaction_type = 'Order' THEN quantity_purchased ELSE 0 END), 0)
                        - (
                            -- Refund (1 per order_id con price_type='Principal')
                            COUNT(DISTINCT CASE WHEN transaction_type = 'Refund' AND price_type = 'Principal' THEN order_id END)
                            -- REVERSAL (reso senza restituzione fisica, unità resta venduta)
                            - COALESCE(SUM(CASE WHEN transaction_type = 'REVERSAL_REIMBURSEMENT' THEN quantity_purchased ELSE 0 END), 0)
                        ) as total
                    FROM `{$tableName}`
                    WHERE product_id = ?
                ");
                $stmtVendute->execute([$productId]);
                $vendute = $stmtVendute->fetch(PDO::FETCH_ASSOC);
                $row['unita_vendute'] = (int)($vendute['total'] ?? 0);
                
                // Rimborsi separati per transaction_type (tramite product_id) - SOLO TAB3
                $stmtRimborsiByType = $pdo->prepare("
                    SELECT 
                        s.transaction_type,
                        COUNT(DISTINCT s.id) as num_rimborsi,
                        COALESCE(SUM(SIGN(s.other_amount) * ABS(s.quantity_purchased)), 0) as unita,
                        COALESCE(SUM(s.other_amount), 0) as importo
                    FROM `{$tableName}` s
                    INNER JOIN transaction_fee_mappings tfm 
                        ON tfm.transaction_type = s.transaction_type
                        AND (tfm.user_id = ? OR tfm.user_id IS NULL)
                    INNER JOIN fee_categories fc 
                        ON fc.category_code = tfm.category
                        AND fc.group_type = 'TAB3'
                    WHERE s.product_id = ?
                    GROUP BY s.transaction_type
                    ORDER BY ABS(COALESCE(SUM(s.other_amount), 0)) DESC
                ");
                $stmtRimborsiByType->execute([$userId, $productId]);
                $rimborsiByType = $stmtRimborsiByType->fetchAll(PDO::FETCH_ASSOC);
                
                // Aggrega totali e breakdown
                foreach ($rimborsiByType as $rimborso) {
                    $transType = $rimborso['transaction_type'];
                    $row['unita_rimborsate'] += (int)$rimborso['unita'];
                    $row['importo_rimborsato'] += (float)$rimborso['importo']; // Mantiene segno (+ o -)
                    $row['num_rimborsi'] += (int)$rimborso['num_rimborsi'];
                    
                    // Salva breakdown per transaction_type
                    $row['rimborsi_by_type'][$transType] = [
                        'unita' => (int)$rimborso['unita'],
                        'importo' => round((float)$rimborso['importo'], 2),
                        'num' => (int)$rimborso['num_rimborsi']
                    ];
                    
                    // Aggiungi al set globale di transaction_types
                    if (!in_array($transType, $allReimbursementTypes)) {
                        $allReimbursementTypes[] = $transType;
                    }
                }
            }
            
            // Calcola discrepanza: Ricevute - Inviate
            // Valore negativo = unità mancanti in ricezione, Valore positivo = eccedenza ricevuta
            $row['discrepanza'] = $row['unita_ricevute'] - $row['unita_inviate'];
            
            // Calcola costo non recuperato (unità mancanti * costo)
            $unitaMancanti = max(0, -$row['discrepanza']); // Solo se negativo
            $row['costo_non_recuperato'] = round($unitaMancanti * $row['costo_prodotto'], 2);
            
            // Calcola Stato Attuale: Giacenza Reale - Giacenza Teorica
            $giacenzaTeorica = $row['unita_inviate'] - $row['unita_vendute'] - $row['unita_ritirate'] - $row['unita_rimborsate'];
            $row['stato_attuale'] = $row['giacenza'] - $giacenzaTeorica;
            
            // Calcola Costo MP Persa e Saldo Netto
            $unitaPerse = abs($row['stato_attuale']) + $row['unita_rimborsate'];
            $row['costo_mp_persa'] = $unitaPerse * $row['costo_prodotto'];
            $row['saldo_netto'] = $row['importo_rimborsato'] - $row['costo_mp_persa'];
        }
        unset($row);
    } else {
        // Se la tabella non esiste, calcola comunque la discrepanza base
        foreach ($discrepanzeData as &$row) {
            $row['discrepanza'] = $row['unita_ricevute'] - $row['unita_inviate'];
            $unitaMancanti = max(0, -$row['discrepanza']);
            $row['costo_non_recuperato'] = round($unitaMancanti * $row['costo_prodotto'], 2);
            
            // Calcola Stato Attuale anche senza settlement: Giacenza Reale - Giacenza Teorica
            $giacenzaTeorica = $row['unita_inviate'] - $row['unita_vendute'] - $row['unita_ritirate'] - $row['unita_rimborsate'];
            $row['stato_attuale'] = $row['giacenza'] - $giacenzaTeorica;
        }
        unset($row);
    }
    
    // Ordinamento dei dati
    usort($discrepanzeData, function($a, $b) use ($sortColumn, $sortOrder) {
        $aVal = $a[$sortColumn] ?? 0;
        $bVal = $b[$sortColumn] ?? 0;
        
        if ($sortOrder === 'ASC') {
            return $aVal <=> $bVal;
        } else {
            return $bVal <=> $aVal;
        }
    });
    
} catch (Exception $e) {
    $error = "Errore caricamento dati: " . $e->getMessage();
    $discrepanzeData = []; // Assicura array vuoto in caso di errore
    $allReimbursementTypes = [];
}

// Calcola totali (gestisce array vuoto)
$totali = [
    'unita_inviate' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'unita_inviate')) : 0,
    'unita_ricevute' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'unita_ricevute')) : 0,
    'unita_ritirate' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'unita_ritirate')) : 0,
    'unita_vendute' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'unita_vendute')) : 0,
    'giacenza' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'giacenza')) : 0,
    'unita_rimborsate' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'unita_rimborsate')) : 0,
    'costo_totale_inviate' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'costo_totale_inviate')) : 0,
    'importo_rimborsato' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'importo_rimborsato')) : 0,
    'costo_non_recuperato' => !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'costo_non_recuperato')) : 0
];

// Calcola Stato Attuale Totale
$giacenzaTeoricaTotale = $totali['unita_inviate'] - $totali['unita_vendute'] - $totali['unita_ritirate'] - $totali['unita_rimborsate'];
$totali['stato_attuale'] = $totali['giacenza'] - $giacenzaTeoricaTotale;

// Calcola Costo MP Persa Totale e Saldo Netto (usa valori già calcolati)
$totali['costo_mp_persa'] = !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'costo_mp_persa')) : 0;
$totali['saldo_netto'] = !empty($discrepanzeData) ? array_sum(array_column($discrepanzeData, 'saldo_netto')) : 0;

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discrepanze Inventario - Margynomic</title>
    <link rel="stylesheet" href="../margynomic/css/margynomic.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #3182ce 0%, #2b77cb 100%);
            min-height: 100vh;
            color: #2d3748;
            margin: 0;
            padding: 0;
        }
        
        .header-logo img {
            height: 50px !important;
            max-height: 50px !important;
        }
        
        .page-container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: #64748b;
            font-size: 1.1rem;
        }
        
        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .kpi-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(20px);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
        }
        
        .kpi-label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: #2d3748;
            font-family: 'Courier New', monospace;
        }
        
        .kpi-card.positive .kpi-value { color: #10b981; }
        .kpi-card.negative .kpi-value { color: #ef4444; }
        .kpi-card.warning .kpi-value { color: #f59e0b; }
        
        /* Table */
        .table-container {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        
        thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        thead th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        thead th:hover {
            background: linear-gradient(135deg, #5a67d8, #6b48a2);
        }
        
        thead th[data-sort] {
            user-select: none;
        }
        
        tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.2s;
        }
        
        tbody tr:hover {
            background: #f8fafc;
        }
        
        tbody td {
            padding: 0.75rem 1rem;
            white-space: nowrap;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .negative-value {
            color: #ef4444;
            font-weight: 600;
        }
        
        .positive-value {
            color: #10b981;
            font-weight: 600;
        }
        
        tfoot {
            background: #f8fafc;
            font-weight: 700;
        }
        
        tfoot td {
            padding: 1rem;
        }
        
        .unmapped-position {
            color: #94a3b8;
            font-style: italic;
        }
        
        /* Stato Attuale Badge */
        .stato-attuale-badge {
            display: inline-block;
            padding: 0.5rem 1.25rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            text-align: center;
            min-width: 80px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .stato-attuale-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .stato-negativo {
            animation: pulse-red 2s infinite;
        }
        
        .stato-positivo {
            animation: pulse-orange 2s infinite;
        }
        
        .stato-zero {
            animation: pulse-green 2s infinite;
        }
        
        @keyframes pulse-red {
            0%, 100% { box-shadow: 0 2px 8px rgba(220, 38, 38, 0.2); }
            50% { box-shadow: 0 4px 16px rgba(220, 38, 38, 0.4); }
        }
        
        @keyframes pulse-orange {
            0%, 100% { box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2); }
            50% { box-shadow: 0 4px 16px rgba(245, 158, 11, 0.4); }
        }
        
        @keyframes pulse-green {
            0%, 100% { box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2); }
            50% { box-shadow: 0 4px 16px rgba(16, 185, 129, 0.4); }
        }
        
        /* ========================================
           TOOLTIP CLASSICI - DARK BACKGROUND
        ======================================== */
        .tooltip-classic {
            position: relative;
            display: inline-block;
            cursor: help;
        }
        
        .tooltip-classic .tooltiptext {
            visibility: hidden;
            width: 280px;
            background-color: #2d3748;
            color: #fff;
            text-align: left;
            border-radius: 8px;
            padding: 12px 16px;
            position: absolute;
            z-index: 2000;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            font-size: 0.85rem;
            line-height: 1.5;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .tooltip-classic .tooltiptext::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -6px;
            border-width: 6px;
            border-style: solid;
            border-color: #2d3748 transparent transparent transparent;
        }
        
        .tooltip-classic:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
        
        .tooltip-label {
            font-weight: 700;
            color: #90cdf4;
            margin-bottom: 8px;
            padding-bottom: 6px;
            border-bottom: 1px solid rgba(255,255,255,0.2);
            font-size: 0.9rem;
        }
        
        .tooltip-info {
            color: #a0aec0;
            margin-bottom: 8px;
            font-size: 0.8rem;
        }
        
        .tooltip-info strong {
            color: #90cdf4;
            font-weight: 600;
        }
        
        .tooltip-calc {
            background: rgba(0,0,0,0.2);
            padding: 8px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            color: #e2e8f0;
            border-left: 3px solid #90cdf4;
            margin-top: 6px;
        }
        
        .tooltip-calc code {
            display: block;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <?php require_once '../margynomic/shared_header.php'; ?>
    
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📦 Discrepanze Inventario</h1>
            <p class="page-subtitle">Analisi differenze tra unità inviate, ricevute, vendute, ritirate, giacenza e rimborsate</p>
        </div>
        
        <?php if (isset($error)): ?>
            <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 12px; margin-bottom: 2rem;">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-label">📤 Unità Inviate</div>
                <div class="kpi-value"><?php echo number_format($totali['unita_inviate'], 0, ',', '.'); ?></div>
            </div>
            
            <div class="kpi-card positive">
                <div class="kpi-label">✅ Unità Ricevute</div>
                <div class="kpi-value"><?php echo number_format($totali['unita_ricevute'], 0, ',', '.'); ?></div>
            </div>
            
            <div class="kpi-card warning">
                <div class="kpi-label">🔄 Unità Ritirate</div>
                <div class="kpi-value"><?php echo number_format($totali['unita_ritirate'], 0, ',', '.'); ?></div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-label">🛒 Unità Vendute</div>
                <div class="kpi-value"><?php echo number_format($totali['unita_vendute'], 0, ',', '.'); ?></div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-label">📦 Giacenza FBA</div>
                <div class="kpi-value"><?php echo number_format($totali['giacenza'], 0, ',', '.'); ?></div>
            </div>
            
            <div class="kpi-card warning">
                <div class="kpi-label">💰 Unità Rimborsate</div>
                <div class="kpi-value"><?php echo number_format($totali['unita_rimborsate'], 0, ',', '.'); ?></div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-label">💵 Costo Inviate</div>
                <div class="kpi-value"><?php echo number_format($totali['costo_totale_inviate'], 2, ',', '.'); ?> €</div>
            </div>
            
            <div class="kpi-card positive">
                <div class="kpi-label">✨ Rimborso Ottenuto</div>
                <div class="kpi-value"><?php echo number_format($totali['importo_rimborsato'], 2, ',', '.'); ?> €</div>
            </div>
            
            <div class="kpi-card negative">
                <div class="kpi-label">⚠️ Costo Non Recuperato</div>
                <div class="kpi-value"><?php echo number_format($totali['costo_non_recuperato'], 2, ',', '.'); ?> €</div>
            </div>
        </div>
        
        <!-- Table -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th class="text-center" data-sort="position" onclick="sortTable('position')">📍 Pos</th>
                        <th data-sort="product_name" onclick="sortTable('product_name')">Prodotto</th>
                        <th class="text-right" data-sort="costo_prodotto" onclick="sortTable('costo_prodotto')">€ Costo</th>
                        <th class="text-center" data-sort="unita_inviate" onclick="sortTable('unita_inviate')">📦 Inviate/Ricevute</th>
                        <th class="text-center" data-sort="unita_vendute" onclick="sortTable('unita_vendute')">🛒 Vendute/Ritirate/Giacenza</th>
                        <th class="text-center" data-sort="stato_attuale" onclick="sortTable('stato_attuale')">📊 Stato Attuale</th>
                        <th class="text-right" data-sort="costo_mp_persa" onclick="sortTable('costo_mp_persa')">💸 Costo MP Persa</th>
                        <th class="text-right" data-sort="importo_rimborsato" onclick="sortTable('importo_rimborsato')">✅ Rimborsi Ottenuti</th>
                        <th class="text-right" data-sort="saldo_netto" onclick="sortTable('saldo_netto')">📊 Saldo Netto</th>
                        <?php foreach ($allReimbursementTypes as $transType): ?>
                            <?php
                                // Mappa transaction_type a emoji descrittive
                                $iconMap = [
                                    'WAREHOUSE_DAMAGE' => '🔨',
                                    'WAREHOUSE_LOST' => '❓',
                                    'MISSING_FROM_INBOUND' => '📦',
                                    'INBOUND_CARRIER_DAMAGE' => '🚚',
                                    'WAREHOUSE_DAMAGE_EXCEPTION' => '⚠️',
                                    'WAREHOUSE_LOST_MANUAL' => '🔍',
                                    'COMPENSATED_CLAWBACK' => '↩️',
                                    'MISSING_FROM_INBOUND_CLAWBACK' => '🔙',
                                    'INCORRECT_FEES_ITEMS' => '💱',
                                    'RE_EVALUATION' => '🔄'
                                ];
                                $icon = $iconMap[$transType] ?? '💰';
                                $shortName = str_replace(['WAREHOUSE_', 'MISSING_FROM_', 'INBOUND_'], '', $transType);
                            ?>
                            <th class="text-center" title="<?php echo htmlspecialchars($transType); ?> - Unità rimborsate">
                                <?php echo $icon; ?> <?php echo htmlspecialchars(substr($shortName, 0, 12)); ?>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-right" data-sort="costo_totale_inviate" onclick="sortTable('costo_totale_inviate')">💵 Costo Inviate</th>
                        <th class="text-right" data-sort="importo_rimborsato" onclick="sortTable('importo_rimborsato')">✨ Rimborso</th>
                        <th class="text-right" data-sort="costo_non_recuperato" onclick="sortTable('costo_non_recuperato')">❌ Non Recuperato</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discrepanzeData)): ?>
                        <tr>
                            <td colspan="<?php echo 9 + count($allReimbursementTypes); ?>" style="text-align: center; padding: 3rem; color: #64748b;">
                                Nessun dato disponibile
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($discrepanzeData as $row): ?>
                            <?php
                                $discrepanza = $row['discrepanza'];
                                $badgeClass = 'badge-success';
                                $statusText = 'OK';
                                
                                if ($discrepanza < 0) {
                                    $badgeClass = 'badge-danger';
                                    $statusText = 'MANCANTI';
                                } elseif ($discrepanza > 0) {
                                    $badgeClass = 'badge-warning';
                                    $statusText = 'ECCEDENZA';
                                }
                                
                                $discrepanzaClass = $discrepanza < 0 ? 'negative-value' : ($discrepanza > 0 ? 'positive-value' : '');
                                $position = $row['position'] == 999999 ? '-' : $row['position'];
                                $positionClass = $row['position'] == 999999 ? 'unmapped-position' : '';
                                
                                // Usa valori già calcolati (no ricalcolo)
                                $statoAttuale = $row['stato_attuale'];
                                $costoMpPersa = $row['costo_mp_persa'];
                                $saldoNetto = $row['saldo_netto'];
                                $unitaPerse = abs($statoAttuale) + $row['unita_rimborsate']; // Necessario per tooltip
                                
                                // Determina colore e classe per stato attuale
                                if ($statoAttuale < 0) {
                                    $statoClass = 'stato-negativo';
                                    $statoColor = '#dc2626'; // Rosso
                                } elseif ($statoAttuale > 0) {
                                    $statoClass = 'stato-positivo';
                                    $statoColor = '#f59e0b'; // Arancione
                                } else {
                                    $statoClass = 'stato-zero';
                                    $statoColor = '#10b981'; // Verde
                                }
                            ?>
                            <tr>
                                <td class="text-center <?php echo $positionClass; ?>"><?php echo $position; ?></td>
                                <td><strong><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                                <td class="text-right"><?php echo number_format($row['costo_prodotto'], 2, ',', '.'); ?> €</td>
                                <td class="text-center">
                                    <div>
                                        <div class="tooltip-classic" style="display: inline;">
                                            <strong><?php echo number_format($row['unita_inviate'], 0, ',', '.'); ?></strong>
                                            <span class="tooltiptext">
                                                <div class="tooltip-label">📦 UNITÀ INVIATE</div>
                                                <div class="tooltip-info">
                                                    <strong>Tabella:</strong> inbound_shipment_items<br>
                                                    <strong>Colonna:</strong> quantity_shipped
                                                </div>
                                                <div class="tooltip-calc">
                                                    <code>SELECT SUM(quantity_shipped)<br>FROM inbound_shipment_items isi<br>JOIN inbound_shipments is<br>&nbsp;&nbsp;ON isi.shipment_id = is.id<br>WHERE isi.product_id = <?php echo $row['product_id']; ?><br>&nbsp;&nbsp;AND is.user_id = <?php echo $userId; ?><br>&nbsp;&nbsp;AND is.shipment_status<br>&nbsp;&nbsp;NOT IN ('CANCELLED','DELETED')</code>
                                                </div>
                                            </span>
                                        </div> / 
                                        <div class="tooltip-classic" style="display: inline;">
                                        <?php echo number_format($row['unita_ricevute'], 0, ',', '.'); ?>
                                            <span class="tooltiptext">
                                                <div class="tooltip-label">✅ UNITÀ RICEVUTE</div>
                                                <div class="tooltip-info">
                                                    <strong>Tabella:</strong> inbound_shipment_items<br>
                                                    <strong>Colonna:</strong> quantity_received
                                                </div>
                                                <div class="tooltip-calc">
                                                    <code>SELECT SUM(quantity_received)<br>FROM inbound_shipment_items isi<br>JOIN inbound_shipments is<br>&nbsp;&nbsp;ON isi.shipment_id = is.id<br>WHERE isi.product_id = <?php echo $row['product_id']; ?><br>&nbsp;&nbsp;AND is.user_id = <?php echo $userId; ?><br>&nbsp;&nbsp;AND is.shipment_status<br>&nbsp;&nbsp;NOT IN ('CANCELLED','DELETED')</code>
                                                </div>
                                            </span>
                                        </div>
                                    </div>
                                    <div style="font-size: 0.75rem; margin-top: 2px; <?php echo $discrepanza < 0 ? 'color: #dc2626;' : ($discrepanza > 0 ? 'color: #16a34a;' : 'color: #64748b;'); ?>">
                                        Discrepanza: <?php echo $discrepanza > 0 ? '+' : ''; ?><?php echo number_format($discrepanza, 0, ',', '.'); ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <div>
                                        <div class="tooltip-classic" style="display: inline;">
                                            <strong><?php echo number_format($row['unita_vendute'], 0, ',', '.'); ?></strong>
                                            <span class="tooltiptext">
                                                <div class="tooltip-label">🛒 UNITÀ VENDUTE NETTE</div>
                                                <div class="tooltip-info">
                                                    <strong>Tabella:</strong> report_settlement_<?php echo $userId; ?><br>
                                                    <strong>Formula:</strong> Order - (Refund - REVERSAL)
                                                </div>
                                                <div class="tooltip-calc">
                                                    <code>SELECT<br>&nbsp;&nbsp;SUM(CASE WHEN transaction_type<br>&nbsp;&nbsp;&nbsp;&nbsp;= 'Order' THEN quantity_purchased<br>&nbsp;&nbsp;&nbsp;&nbsp;ELSE 0 END)<br>&nbsp;&nbsp;- (<br>&nbsp;&nbsp;&nbsp;&nbsp;COUNT(DISTINCT CASE WHEN<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;transaction_type = 'Refund'<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;AND price_type = 'Principal'<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;THEN order_id END)<br>&nbsp;&nbsp;&nbsp;&nbsp;- SUM(CASE WHEN transaction_type<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;= 'REVERSAL_REIMBURSEMENT'<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;THEN quantity_purchased<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;ELSE 0 END)<br>&nbsp;&nbsp;)<br>FROM report_settlement_<?php echo $userId; ?><br>WHERE product_id = <?php echo $row['product_id']; ?></code>
                                                </div>
                                            </span>
                                        </div> / 
                                        <div class="tooltip-classic" style="display: inline;">
                                            <span style="color: #f59e0b; font-weight: 600;"><?php echo number_format($row['unita_ritirate'], 0, ',', '.'); ?></span>
                                            <span class="tooltiptext">
                                                <div class="tooltip-label">📤 UNITÀ RITIRATE</div>
                                                <div class="tooltip-info">
                                                    <strong>Tabella:</strong> removal_orders<br>
                                                    <strong>Colonna:</strong> shipped_quantity
                                                </div>
                                                <div class="tooltip-calc">
                                                    <code>SELECT SUM(shipped_quantity)<br>FROM removal_orders<br>WHERE product_id = <?php echo $row['product_id']; ?><br>&nbsp;&nbsp;AND user_id = <?php echo $userId; ?><br>&nbsp;&nbsp;AND (order_status IS NULL<br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;OR order_status != 'Cancelled')</code>
                                                </div>
                                            </span>
                                        </div> / 
                                        <div class="tooltip-classic" style="display: inline;">
                                        <?php echo number_format($row['giacenza'], 0, ',', '.'); ?>
                                            <span class="tooltiptext">
                                                <div class="tooltip-label">📦 GIACENZA ATTUALE</div>
                                                <div class="tooltip-info">
                                                    <strong>Tabella:</strong> inventory<br>
                                                    <strong>Colonna:</strong> afn_warehouse_quantity
                                                </div>
                                                <div class="tooltip-calc">
                                                    <code>SELECT afn_warehouse_quantity<br>FROM inventory<br>WHERE product_id = <?php echo $row['product_id']; ?><br>&nbsp;&nbsp;AND user_id = <?php echo $userId; ?></code>
                                                </div>
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($row['unita_rimborsate'] > 0): ?>
                                        <div style="font-size: 0.75rem; margin-top: 2px; color: #16a34a;">
                                            Rimborsate: +<?php echo number_format($row['unita_rimborsate'], 0, ',', '.'); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="tooltip-classic" style="display: inline-block;">
                                        <div class="stato-attuale-badge <?php echo $statoClass; ?>" style="background-color: <?php echo $statoColor; ?>15; border: 2px solid <?php echo $statoColor; ?>; color: <?php echo $statoColor; ?>;">
                                            <strong style="font-size: 1.1rem;"><?php echo $statoAttuale > 0 ? '+' : ''; ?><?php echo number_format($statoAttuale, 0, ',', '.'); ?></strong>
                                        </div>
                                        <span class="tooltiptext">
                                            <div class="tooltip-label">📊 STATO ATTUALE</div>
                                            <div class="tooltip-info">
                                                <strong>Formula:</strong> Giacenza Reale - Giacenza Teorica
                                            </div>
                                            <div class="tooltip-calc">
                                                <code>Giacenza: <?php echo number_format($row['giacenza'], 0, ',', '.'); ?><br>Giacenza Teorica:<br>&nbsp;&nbsp;Inviate: <?php echo number_format($row['unita_inviate'], 0, ',', '.'); ?><br>&nbsp;&nbsp;- Vendute: <?php echo number_format($row['unita_vendute'], 0, ',', '.'); ?><br>&nbsp;&nbsp;- Ritirate: <?php echo number_format($row['unita_ritirate'], 0, ',', '.'); ?><br>&nbsp;&nbsp;- Rimborsate: <?php echo number_format($row['unita_rimborsate'], 0, ',', '.'); ?><br>= <?php echo $statoAttuale > 0 ? '+' : ''; ?><?php echo number_format($statoAttuale, 0, ',', '.'); ?></code>
                                            </div>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right" style="color: #dc2626; font-weight: 600;">
                                    <div class="tooltip-classic" style="display: inline;">
                                        -<?php echo number_format($costoMpPersa, 2, ',', '.'); ?> €
                                        <span class="tooltiptext">
                                            <div class="tooltip-label">💸 COSTO MP PERSA</div>
                                            <div class="tooltip-info">
                                                <strong>Formula:</strong> (|Stato Attuale| + Rimborsate) × Costo
                                            </div>
                                            <div class="tooltip-calc">
                                                <code>|Stato Attuale|: <?php echo number_format(abs($statoAttuale), 0, ',', '.'); ?><br>+ Rimborsate: <?php echo number_format($row['unita_rimborsate'], 0, ',', '.'); ?><br>= Unità Perse: <?php echo number_format($unitaPerse, 0, ',', '.'); ?><br><br>× Costo: <?php echo number_format($row['costo_prodotto'], 2, ',', '.'); ?> €<br>= <?php echo number_format($costoMpPersa, 2, ',', '.'); ?> €</code>
                                            </div>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right" style="color: #10b981; font-weight: 600;">
                                    <div class="tooltip-classic" style="display: inline;">
                                        +<?php echo number_format($row['importo_rimborsato'], 2, ',', '.'); ?> €
                                        <span class="tooltiptext">
                                            <div class="tooltip-label">✅ RIMBORSI OTTENUTI</div>
                                            <div class="tooltip-info">
                                                <strong>Tabella:</strong> report_settlement_<?php echo $userId; ?><br>
                                                <strong>Colonna:</strong> other_amount
                                            </div>
                                            <div class="tooltip-calc">
                                                <code>SELECT SUM(s.other_amount)<br>FROM report_settlement_<?php echo $userId; ?> s<br>JOIN transaction_fee_mappings tfm<br>&nbsp;&nbsp;ON s.transaction_type = tfm.transaction_type<br>JOIN fee_categories fc<br>&nbsp;&nbsp;ON fc.category_code = tfm.category<br>&nbsp;&nbsp;AND fc.group_type = 'TAB3'<br>WHERE s.product_id = <?php echo $row['product_id']; ?></code>
                                            </div>
                                        </span>
                                    </div>
                                </td>
                                <td class="text-right" style="font-weight: 600; color: <?php echo $saldoNetto >= 0 ? '#10b981' : '#dc2626'; ?>;">
                                    <div class="tooltip-classic" style="display: inline;">
                                        <?php echo $saldoNetto >= 0 ? '+' : ''; ?><?php echo number_format($saldoNetto, 2, ',', '.'); ?> €
                                        <span class="tooltiptext">
                                            <div class="tooltip-label">📊 SALDO NETTO</div>
                                            <div class="tooltip-info">
                                                <strong>Formula:</strong> Rimborsi Ottenuti - Costo MP Persa
                                            </div>
                                            <div class="tooltip-calc">
                                                <code>Rimborsi: +<?php echo number_format($row['importo_rimborsato'], 2, ',', '.'); ?> €<br>- Costo MP: -<?php echo number_format($costoMpPersa, 2, ',', '.'); ?> €<br>= <?php echo $saldoNetto >= 0 ? '+' : ''; ?><?php echo number_format($saldoNetto, 2, ',', '.'); ?> €</code>
                                            </div>
                                        </span>
                                    </div>
                                </td>
                                <?php foreach ($allReimbursementTypes as $transType): ?>
                                        <?php 
                                        // Icona per questo tipo di rimborso
                                        $iconMapRow = [
                                            'WAREHOUSE_DAMAGE' => '🔨',
                                            'WAREHOUSE_LOST' => '❓',
                                            'MISSING_FROM_INBOUND' => '📦',
                                            'INBOUND_CARRIER_DAMAGE' => '🚚',
                                            'WAREHOUSE_DAMAGE_EXCEPTION' => '⚠️',
                                            'WAREHOUSE_LOST_MANUAL' => '🔍',
                                            'COMPENSATED_CLAWBACK' => '↩️',
                                            'MISSING_FROM_INBOUND_CLAWBACK' => '🔙',
                                            'INCORRECT_FEES_ITEMS' => '💱',
                                            'RE_EVALUATION' => '🔄'
                                        ];
                                        $iconRow = $iconMapRow[$transType] ?? '💰';
                                    ?>
                                    <td class="text-center">
                                        <?php if (isset($row['rimborsi_by_type'][$transType])): ?>
                                            <div class="tooltip-classic" style="display: inline;">
                                                <?php echo number_format($row['rimborsi_by_type'][$transType]['unita'], 0, ',', '.'); ?>
                                                <span class="tooltiptext">
                                                    <div class="tooltip-label"><?php echo $iconRow; ?> <?php echo htmlspecialchars($transType); ?></div>
                                                    <div class="tooltip-info">
                                                        <strong>Tabella:</strong> report_settlement_<?php echo $userId; ?><br>
                                                        <strong>Colonne:</strong> quantity_purchased, other_amount
                                                    </div>
                                                    <div class="tooltip-calc">
                                                        <code>Unità: <?php echo number_format($row['rimborsi_by_type'][$transType]['unita'], 0, ',', '.'); ?><br>Importo: <?php echo number_format($row['rimborsi_by_type'][$transType]['importo'], 2, ',', '.'); ?> €<br>Transazioni: <?php echo $row['rimborsi_by_type'][$transType]['num']; ?><br><br>SELECT<br>&nbsp;&nbsp;SUM(SIGN(other_amount)<br>&nbsp;&nbsp;&nbsp;&nbsp;* ABS(quantity_purchased)),<br>&nbsp;&nbsp;SUM(other_amount)<br>FROM report_settlement_<?php echo $userId; ?><br>WHERE product_id = <?php echo $row['product_id']; ?><br>&nbsp;&nbsp;AND transaction_type<br>&nbsp;&nbsp;&nbsp;&nbsp;= '<?php echo $transType; ?>'</code>
                                                    </div>
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-right"><?php echo number_format($row['costo_totale_inviate'], 2, ',', '.'); ?> €</td>
                                <td class="text-right"><?php echo number_format($row['importo_rimborsato'], 2, ',', '.'); ?> €</td>
                                <td class="text-right <?php echo $row['costo_non_recuperato'] > 0 ? 'negative-value' : ''; ?>">
                                    <?php echo number_format($row['costo_non_recuperato'], 2, ',', '.'); ?> €
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $statusText; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3"><strong>TOTALI</strong></td>
                        <td class="text-center">
                            <strong><?php echo number_format($totali['unita_inviate'], 0, ',', '.'); ?></strong> / 
                            <?php echo number_format($totali['unita_ricevute'], 0, ',', '.'); ?>
                        </td>
                        <td class="text-center">
                            <div>
                                <strong><?php echo number_format($totali['unita_vendute'], 0, ',', '.'); ?></strong> / 
                                <span style="color: #f59e0b; font-weight: 600;"><?php echo number_format($totali['unita_ritirate'], 0, ',', '.'); ?></span> / 
                                <?php echo number_format($totali['giacenza'], 0, ',', '.'); ?>
                            </div>
                            <?php if ($totali['unita_rimborsate'] > 0): ?>
                                <div style="font-size: 0.75rem; margin-top: 2px; color: #16a34a;">
                                    Rimborsate: +<?php echo number_format($totali['unita_rimborsate'], 0, ',', '.'); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <strong style="font-size: 1.2rem; <?php echo $totali['stato_attuale'] < 0 ? 'color: #dc2626;' : ($totali['stato_attuale'] > 0 ? 'color: #f59e0b;' : 'color: #10b981;'); ?>">
                                <?php echo $totali['stato_attuale'] > 0 ? '+' : ''; ?><?php echo number_format($totali['stato_attuale'], 0, ',', '.'); ?>
                            </strong>
                        </td>
                        <td class="text-right" style="color: #dc2626; font-weight: 600; font-size: 1.1rem;">
                            -<?php echo number_format($totali['costo_mp_persa'], 2, ',', '.'); ?> €
                        </td>
                        <td class="text-right" style="color: #10b981; font-weight: 600; font-size: 1.1rem;">
                            +<?php echo number_format($totali['importo_rimborsato'], 2, ',', '.'); ?> €
                        </td>
                        <td class="text-right" style="font-weight: 600; font-size: 1.1rem; color: <?php echo $totali['saldo_netto'] >= 0 ? '#10b981' : '#dc2626'; ?>;">
                            <?php echo $totali['saldo_netto'] >= 0 ? '+' : ''; ?><?php echo number_format($totali['saldo_netto'], 2, ',', '.'); ?> €
                        </td>
                        <?php foreach ($allReimbursementTypes as $transType): ?>
                            <td class="text-center">-</td>
                        <?php endforeach; ?>
                        <td class="text-right"><?php echo number_format($totali['costo_totale_inviate'], 2, ',', '.'); ?> €</td>
                        <td class="text-right"><?php echo number_format($totali['importo_rimborsato'], 2, ',', '.'); ?> €</td>
                        <td class="text-right"><?php echo number_format($totali['costo_non_recuperato'], 2, ',', '.'); ?> €</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    
    <script>
        // Funzione per ordinamento cliccabile
        function sortTable(column) {
            const url = new URL(window.location);
            const currentSort = url.searchParams.get('sort');
            const currentOrder = url.searchParams.get('order');
            
            let newOrder = 'DESC';
            if (currentSort === column && currentOrder === 'DESC') {
                newOrder = 'ASC';
            }
            
            url.searchParams.set('sort', column);
            url.searchParams.set('order', newOrder);
            window.location = url.toString();
        }
        
        // Evidenzia colonna ordinata
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const sortColumn = urlParams.get('sort') || 'position';
            const sortOrder = urlParams.get('order') || 'ASC';
            
            // Trova e marca la colonna ordinata
            const headers = document.querySelectorAll('thead th[data-sort]');
            headers.forEach(header => {
                const column = header.getAttribute('data-sort');
                if (column === sortColumn) {
                    header.style.backgroundColor = '#5a67d8';
                    header.style.color = 'white';
                    
                    // Aggiungi freccia indicativa
                    const arrow = sortOrder === 'ASC' ? ' ▲' : ' ▼';
                    if (!header.textContent.includes('▲') && !header.textContent.includes('▼')) {
                        header.textContent += arrow;
                    }
                }
            });
        });
    </script>
</body>
</html>

