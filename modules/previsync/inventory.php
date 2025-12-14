<?php
/**
 * Dashboard Inventario Spettacolare - Previsync  
 * File: modules/previsync/inventory.php
 * 
 * Analisi inventario con calcoli avanzati e design ispirato a profilo_utente.php
 * Tema arancione Margynomic con funzionalità complete
 */

// Debug errori
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once dirname(__DIR__) . '/margynomic/config/config.php';
require_once dirname(__DIR__) . '/margynomic/login/auth_helpers.php';
require_once dirname(__DIR__) . '/listing/helpers.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: ../margynomic/login/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Redirect mobile
if (isMobileDevice()) {
    header('Location: /modules/mobile/Previsync.php');
    exit;
}

/**
 * Classe per analisi inventario con calcoli avanzati
 */
class InventoryAnalyzer {
    
    private $db;
    private $userId;
    private $strategyManager;
    
    public function __construct($userId) {
        $this->db = getDbConnection();
        $this->userId = $userId;
        
        // Carica Strategy Manager per logica avanzata
        require_once __DIR__ . '/inventory_strategy_manager.php';
        $this->strategyManager = new InventoryStrategyManager($this->db, $userId);
    }
    
    /**
     * Analisi completa inventario con tutti i calcoli richiesti
     */
    public function getCompleteInventoryAnalysis() {
        $tableName = "report_settlement_{$this->userId}";
        
        // Verifica esistenza tabella settlement
        try {
            $checkTable = $this->db->query("SHOW TABLES LIKE 'report_settlement_{$this->userId}'");
            $hasSettlementTable = $checkTable->rowCount() > 0;
        } catch (Exception $e) {
            $hasSettlementTable = false;
        }
        
        // Se non c'è tabella settlement, ritorna solo i dati inventory aggregati
        if (!$hasSettlementTable) {
            $sql = "
                SELECT 
                    COALESCE(p.nome, MIN(i.product_name), 'Nome non disponibile') as product_name,
                    COALESCE(
                        MAX(CASE WHEN i.sku = p.sku THEN i.your_price END),
                        AVG(i.your_price)
                    ) as your_price,
                    SUM(i.afn_warehouse_quantity) as disponibili,
                    SUM(i.afn_inbound_shipped_quantity) as in_arrivo,
                    i.product_id,
                    0 as vendite_totali,
                    0 as giorni_attivi,
                    0 as media_vendite_1d,
                    NULL as prima_vendita,
                    0 as vendite_90gg
                FROM inventory i
                LEFT JOIN products p ON i.product_id = p.id
                LEFT JOIN product_display_order pdo ON (pdo.user_id = ? AND pdo.product_id = i.product_id)
                WHERE i.user_id = ? AND i.product_id IS NOT NULL
                  AND COALESCE(p.nome, '') NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
                  AND p.fnsku IS NOT NULL AND p.fnsku != ''
                GROUP BY i.product_id, pdo.position
                ORDER BY pdo.position IS NULL ASC, pdo.position ASC, COALESCE(p.nome, MIN(i.product_name)) ASC, i.product_id ASC
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userId, $this->userId]);
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Query ottimizzata con prezzo dello SKU principale
            $sql = "
                SELECT 
                    COALESCE(p.nome, MIN(inv.product_name), 'Nome non disponibile') as product_name,
                    COALESCE(
                        MAX(CASE WHEN inv.sku = p.sku THEN inv.your_price END),
                        AVG(inv.your_price)
                    ) as your_price,
                    SUM(inv.afn_warehouse_quantity) as disponibili,
                    SUM(inv.afn_inbound_shipped_quantity) as in_arrivo,
                    inv.product_id,
                    
                    -- Statistiche vendite
                    COALESCE(s.vendite_totali, 0) as vendite_totali,
                    COALESCE(s.giorni_attivi, 0) as giorni_attivi,
                    COALESCE(s.media_vendite_1d, 0) as media_vendite_1d,
                    COALESCE(s.prima_vendita, NULL) as prima_vendita,
                    COALESCE(s.vendite_90gg, 0) as vendite_90gg
                    
                FROM products p
                LEFT JOIN inventory inv ON inv.product_id = p.id AND inv.user_id = ?
                LEFT JOIN product_display_order pdo ON (pdo.user_id = ? AND pdo.product_id = p.id)
                LEFT JOIN (
                    SELECT 
                        product_id,
                        SUM(CASE WHEN transaction_type = 'Order' AND quantity_purchased > 0 THEN quantity_purchased ELSE 0 END) as vendite_totali,
                        MIN(posted_date) as prima_vendita,
                        GREATEST(1, DATEDIFF(CURDATE(), MIN(posted_date))) as giorni_attivi,
                        SUM(CASE WHEN transaction_type = 'Order' AND quantity_purchased > 0 THEN quantity_purchased ELSE 0 END) / GREATEST(1, DATEDIFF(CURDATE(), MIN(posted_date))) as media_vendite_1d,
                        SUM(CASE WHEN posted_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND transaction_type = 'Order' AND quantity_purchased > 0 THEN quantity_purchased ELSE 0 END) as vendite_90gg
                    FROM `{$tableName}` 
                    WHERE product_id IS NOT NULL
                    GROUP BY product_id
                ) s ON p.id = s.product_id
                WHERE p.user_id = ?
                  AND p.nome NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
                  AND p.fnsku IS NOT NULL AND p.fnsku != ''
                GROUP BY p.id, pdo.position
                ORDER BY pdo.position IS NULL ASC, pdo.position ASC, p.nome ASC, p.id ASC
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userId, $this->userId, $this->userId]);
            $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $analysis = [];
        $stats = ['alto' => 0, 'medio' => 0, 'basso' => 0, 'neutro' => 0, 'elimina' => 0, 'avvia' => 0];
        
        foreach ($inventory as $item) {
            $disponibili = (int)$item['disponibili'];
            $in_arrivo = (int)$item['in_arrivo'];
            $media_vendite_1d = (float)$item['media_vendite_1d'];
            $vendite_90gg = (int)$item['vendite_90gg'];
            
            // Calcolo Giorni di Stock
            if ($media_vendite_1d > 0) {
                $giorni_stock = round(($disponibili + $in_arrivo) / $media_vendite_1d);
            } else {
                $giorni_stock = ($disponibili + $in_arrivo > 0) ? 999 : 0;
            }
            
            // Usa Strategy Manager per calcolo avanzato
            $strategiaResult = $this->strategyManager->calculateAdvancedStrategy($item);
            $invio_suggerito = $strategiaResult['invio_suggerito'];
            $criticita = $strategiaResult['criticita'];
            $criticita_priority = $strategiaResult['criticita_priority'];
            
            // Mappa criticità per display e stats
            $criticitaMap = [
                'alta' => ['alto', '🔴 Alta'],
                'media' => ['medio', '🟡 Media'], 
                'bassa' => ['basso', '🔵 Bassa'],
                'neutro' => ['neutro', '🟢 Neutro'],
                'elimina' => ['elimina', '🗑️ Elimina'],
                'avvia' => ['avvia', '🚀 Avvia']
            ];
            
            $mappedCriticita = $criticitaMap[$criticita] ?? ['neutro', '🟢 Neutro'];
            $criticita = $mappedCriticita[0];
            $criticita_display = $mappedCriticita[1];
            $stats[$criticita]++;
            
            // Aggiunge priorità per ordinamento (usa quella del Strategy Manager)
            $priorita_criticita = [
                'alto' => 1,
                'medio' => 2, 
                'basso' => 3,
                'avvia' => 4,
                'elimina' => 5,
                'neutro' => 6
            ];
            
            // Usa priorità del Strategy Manager se disponibile
            $final_priority = $criticita_priority ?? ($priorita_criticita[$criticita] ?? 6);
            
            $analysis[] = [
                'product_name' => $item['product_name'],
                'your_price' => (float)$item['your_price'],
                'disponibili' => $disponibili,
                'in_arrivo' => $in_arrivo,
                'media_vendite_1d' => round($media_vendite_1d, 2),
                'giorni_stock' => $giorni_stock,
                'criticita' => $criticita,
                'criticita_display' => $criticita_display,
                'invio_suggerito' => $invio_suggerito,
                'criticity_level' => $criticita,
                'criticita_priority' => $final_priority,
                'strategia_applicata' => $strategiaResult['strategia_applicata'] ?? 'fallback',
                'product_id' => $item['product_id'] ?? null
            ];
        }
        
        // Ordinamento default per criticità (alta → media → bassa → avvia → elimina → neutro)
        usort($analysis, function($a, $b) {
            return $a['criticita_priority'] - $b['criticita_priority'];
        });
        
        return [
            'analysis' => $analysis,
            'stats' => $stats
        ];
    }
    
    /**
     * Statistiche generali inventario
     */
    public function getInventoryOverview() {
        $sql = "
            SELECT 
                (SELECT COUNT(*) FROM products p 
                 WHERE p.user_id = ? 
                   AND p.nome NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
                   AND p.fnsku IS NOT NULL AND p.fnsku != '') as total_items,
                COUNT(CASE WHEN i.afn_warehouse_quantity > 0 THEN 1 END) as items_in_stock,
                SUM(i.afn_warehouse_quantity) as total_units,
                SUM(i.afn_inbound_shipped_quantity) as inbound_units,
                AVG(i.your_price) as avg_price,
                MAX(i.last_updated) as last_sync
            FROM inventory i
            LEFT JOIN products p ON i.product_id = p.id
            WHERE i.user_id = ?
              AND COALESCE(p.nome, '') NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
              AND p.fnsku IS NOT NULL AND p.fnsku != ''
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->userId, $this->userId]);
        
        return $stmt->fetch();
    }
    
    /**
     * Ottieni statistiche strategia avanzata per debug
     */
    public function getStrategiaStats() {
        if ($this->strategyManager) {
            return $this->strategyManager->getStrategiaStats();
        }
        return [];
    }
}

// Inizializza analyzer
$analyzer = new InventoryAnalyzer($userId);
$inventoryData = $analyzer->getCompleteInventoryAnalysis();
$overview = $analyzer->getInventoryOverview();

$analysis = $inventoryData['analysis'];
$stats = $inventoryData['stats'];

// Gestione filtri
$selectedCriticity = $_GET['criticity'] ?? 'all';
if ($selectedCriticity !== 'all') {
    $analysis = array_filter($analysis, function($item) use ($selectedCriticity) {
        return $item['criticity_level'] === $selectedCriticity;
    });
}

// Funzione per formato tempo
function timeAgo($datetime) {
    if (!$datetime) return 'Mai';
    $time = time() - strtotime($datetime);
    if ($time < 60) return 'Ora';
    if ($time < 3600) return floor($time/60) . ' min fa';
    if ($time < 86400) return floor($time/3600) . ' ore fa';
    return floor($time/86400) . ' giorni fa';
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📦 Dashboard Inventario - Previsync</title>
    
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        /* === TEMA ARANCIONE MARGYNOMIC === */
        :root {
            --primary: #FF6B35;
            --secondary: #F7931E;
            --danger: #FF3547;
            --warning: #FFB400;
            --success: #00C851;
            --info: #17A2B8;
            --dark: #1a1a1a;
            --light: #f8f9fa;
            --gradient-primary: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%);
            --gradient-danger: linear-gradient(135deg, #FF3547 0%, #CC2A39 100%);
            --gradient-warning: linear-gradient(135deg, #FFB400 0%, #CC9100 100%);
            --gradient-success: linear-gradient(135deg, #00C851 0%, #009639 100%);
            --gradient-info: linear-gradient(135deg, #17A2B8 0%, #138496 100%);
        }
        
        /* === RESET & BASE === */
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-primary);
            min-height: 100vh;
            color: #2d3748;
        }

        /* Header styles are now handled by shared_header.php */

        /* === DASHBOARD CONTAINER === */
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* === WELCOME SECTION EPICA === */
        .welcome-hero {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 3rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        .welcome-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .welcome-content {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .welcome-title {
            font-size: 3rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 1rem;
            animation: fadeInUp 1s ease-out;
        }

        .welcome-subtitle {
            font-size: 1.2rem;
            color: #64748b;
            margin-bottom: 2rem;
            animation: fadeInUp 1s ease-out 0.2s both;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* === STATS GRID SPETTACOLARE === */
        .stats-supergrid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-supercard {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .stat-supercard::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent 0%, rgba(255, 107, 53, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-supercard:hover::before {
            opacity: 1;
        }

        .stat-supercard:hover {
            transform: translateY(-10px) scale(1.02);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        }

        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
        }

        .stat-label {
            color: #64748b;
            font-weight: 600;
            font-size: 0.9rem;
            position: relative;
            z-index: 2;
        }

        .stat-alto .stat-icon { background: var(--gradient-danger); }
        .stat-alto .stat-number { color: #FF3547; }

        .stat-medio .stat-icon { background: var(--gradient-warning); }
        .stat-medio .stat-number { color: #FFB400; }

        .stat-basso .stat-icon { background: var(--gradient-info); }
        .stat-basso .stat-number { color: #17A2B8; }

        .stat-neutro .stat-icon { background: var(--gradient-success); }
        .stat-neutro .stat-number { color: #00C851; }

        .stat-elimina .stat-icon { background: var(--gradient-danger); }
        .stat-elimina .stat-number { color: #FF3547; }

        .stat-avvia .stat-icon { background: var(--gradient-info); }
        .stat-avvia .stat-number { color: #17A2B8; }

        /* === FILTRI SPETTACOLARI === */
        .filters-panel {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0.7) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
        }

        .filter-select {
            padding: 1rem 1.5rem;
            border: 2px solid rgba(255, 107, 53, 0.2);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            color: #4a5568;
            font-weight: 600;
            transition: all 0.3s ease;
            min-width: 250px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.2);
        }

        /* === TABELLA SORTABLE === */
        .inventory-table th {
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .inventory-table th:hover {
            background: rgba(255, 107, 53, 0.15);
            transform: translateY(-1px);
        }
        
        .inventory-table th.sortable::after {
            content: '⇅';
            position: absolute;
            right: 0.5rem;
            opacity: 0.5;
            font-weight: normal;
        }
        
        .inventory-table th.sort-asc::after {
            content: '↑';
            opacity: 1;
            color: var(--primary);
        }
        
        .inventory-table th.sort-desc::after {
            content: '↓';
            opacity: 1;
            color: var(--primary);
        }

        /* === TABELLA SPETTACOLARE === */
        .inventory-table-container {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .table-header {
            background: var(--gradient-primary);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .table-title {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .table-subtitle {
            font-size: 1rem;
            opacity: 0.9;
        }

        .table-responsive {
            overflow-x: auto;
            max-height: 800px;
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
        }

        .inventory-table th,
        .inventory-table td {
            padding: 1.5rem 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 107, 53, 0.1);
        }

        .inventory-table th {
            background: rgba(255, 107, 53, 0.05);
            font-weight: 700;
            color: #2d3748;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .inventory-table tbody tr {
            transition: all 0.3s ease;
        }

        .inventory-table tbody tr:hover {
            background: rgba(255, 107, 53, 0.05);
            transform: scale(1.01);
        }

        /* === BADGE CRITICITÀ === */
        .criticity-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-block;
            text-align: center;
            min-width: 120px;
        }

        .criticity-alto {
            background: linear-gradient(135deg, #FF3547, #CC2A39);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 53, 71, 0.3);
        }

        .criticity-medio {
            background: linear-gradient(135deg, #FFB400, #CC9100);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 180, 0, 0.3);
        }

        .criticity-basso {
            background: linear-gradient(135deg, #17A2B8, #138496);
            color: white;
            box-shadow: 0 4px 15px rgba(23, 162, 184, 0.3);
        }

        .criticity-neutro {
            background: linear-gradient(135deg, #00C851, #009639);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 200, 81, 0.3);
        }

        .criticity-elimina {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .criticity-avvia {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .dashboard-container {
                padding: 1rem;
            }
            
            .welcome-hero {
                padding: 2rem 1.5rem;
            }
            
            .welcome-title {
                font-size: 2rem;
            }
            
            .stats-supergrid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .header-nav {
                display: none;
            }
        }

        /* === LOADING ANIMATION === */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #FF6B35;
            animation: spin 1s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* === STRATEGIC FLOW GRID === */
        .strategic-flow-section {
            margin-bottom: 3rem;
        }

        .stats-flow-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
            position: relative;
        }

        .flow-card {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 1.5rem;
            border: 3px solid transparent;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .flow-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, transparent 0%, rgba(255, 107, 53, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .flow-card:hover::before {
            opacity: 1;
        }

        .flow-card:hover {
            transform: translateY(-10px) scale(1.05);
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
        }

        /* === FLOW STAGES === */
        .flow-stage-1 { 
            border-color: #007bff; 
            background: linear-gradient(135deg, rgba(0, 123, 255, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-stage-2 { 
            border-color: #00C851; 
            background: linear-gradient(135deg, rgba(0, 200, 81, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-stage-3 { 
            border-color: #FF3547; 
            background: linear-gradient(135deg, rgba(255, 53, 71, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-stage-4 { 
            border-color: #FFB400; 
            background: linear-gradient(135deg, rgba(255, 180, 0, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-stage-5 { 
            border-color: #17A2B8; 
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-stage-6 { 
            border-color: #6c757d; 
            background: linear-gradient(135deg, rgba(108, 117, 125, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }
        .flow-stage-7 { 
            border-color: #FF6B35; 
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.1) 0%, rgba(255,255,255,0.9) 100%);
        }

        .flow-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            filter: drop-shadow(2px 2px 4px rgba(0,0,0,0.2));
        }

        .flow-number {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            color: #2d3748;
        }

        .flow-label {
            font-size: 0.9rem;
            font-weight: 700;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .flow-description {
            font-size: 0.8rem;
            color: #64748b;
            line-height: 1.4;
            margin-top: 0.5rem;
        }

        .flow-timeline {
            font-size: 0.7rem;
            color: #9ca3af;
            font-weight: 600;
            margin-top: 0.5rem;
            background: rgba(0,0,0,0.05);
            padding: 0.25rem 0.5rem;
            border-radius: 8px;
        }

        /* === FLOW ARROWS === */
        .flow-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 2rem;
            color: rgba(255,255,255,0.8);
            z-index: 10;
            animation: pulse 2s infinite;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .arrow-1 { right: -0.5rem; }
        .arrow-2 { right: -0.5rem; }
        .arrow-3 { right: -0.5rem; top: 40%; }
        .arrow-4 { right: -0.5rem; top: 60%; }
        .arrow-5 { right: -0.5rem; }

        .flow-info-panel {
            background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.1);
        }

        @media (max-width: 1200px) {
            .stats-flow-grid {
                grid-template-columns: repeat(4, 1fr);
                gap: 1rem;
            }
            
            .flow-arrow {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .stats-flow-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .flow-card {
                min-height: 150px;
                padding: 1rem;
            }
            
            .flow-icon {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php 
    // Non include header se chiamato da API/cron
    if (!defined('NO_HTML_OUTPUT')) {
        require_once '../margynomic/shared_header.php'; 
    }
    ?>

    <!-- Main Dashboard -->
    <div class="dashboard-container">
        
        <!-- Hero Welcome -->
        <div class="welcome-hero">
            <div class="welcome-content">
                <h1 class="welcome-title">
                    <i class="fas fa-boxes"></i> PreviSync AI Sistem
                </h1>
                <p class="welcome-subtitle">
                    SCOPRI COME IL SISTEMA GUIDA OGNI PRODOTTO ATTRAVERSO IL SUO CICLO DI VITA OTTIMALE!
                </p>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-top: 1.5rem;">
    <style>
        @media (max-width: 1200px) {
            .welcome-hero > div:last-child {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        @media (max-width: 768px) {
            .welcome-hero > div:last-child {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
                    <div style="background: rgba(255, 107, 53, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid var(--primary);">
                        <h4 style="color: var(--primary); font-weight: 700; margin-bottom: 0.5rem;">🎯 Test di Mercato</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Quando un prodotto si esaurisce, il sistema suggerisce di rifornire 5 unità per verificare se c'è ancora domanda di mercato.</p>
                    </div>
                    
                    <div style="background: rgba(255, 107, 53, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid var(--primary);">
                        <h4 style="color: var(--primary); font-weight: 700; margin-bottom: 0.5rem;">⏱️ Periodo di Grazia</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Dopo il rifornimento, il prodotto entra in "fase neutra" per 90 giorni, durante i quali il sistema raccoglie dati di vendita.</p>
                    </div>
                    
                    <div style="background: rgba(255, 107, 53, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid var(--primary);">
                        <h4 style="color: var(--primary); font-weight: 700; margin-bottom: 0.5rem;">📊 Decisioni Data-Driven</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Dopo 90 giorni, se il prodotto registra vendite, il sitema lo inserisce nel ciclo di criticità. Se non vende, viene candidato per l'eliminazione.</p>
                    </div>
                    
                    <div style="background: rgba(255, 107, 53, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid var(--primary);">
                        <h4 style="color: var(--primary); font-weight: 700; margin-bottom: 0.5rem;">🔄 Ciclo di Criticità</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">I prodotti che vendono vengono inseriti nel ciclo di criticità e classificati per urgenza di rifornimento basata su proiezioni e trend di vendita.</p>
                    </div>
                </div>
        </div>

        <!-- Strategic Flow Grid -->
        <div class="strategic-flow-section">
            <div class="stats-flow-grid">
                <!-- Stage 1: Avvia Rotazione -->
                <div class="flow-card flow-stage-1">
                    <div class="flow-icon">🚀</div>
                    <div class="flow-number"><?php echo $stats['avvia']; ?></div>
                    <div class="flow-label">Avvia<br>Rotazione</div>
                    <div class="flow-description">Prodotto Esaurito<br>Testiamo il Mercato</div>
                    <div class="flow-timeline">Spedisci 5 unità</div>
                    <div class="flow-arrow arrow-1">→</div>
                </div>

                <!-- Stage 2: Neutro -->
                <div class="flow-card flow-stage-2">
                    <div class="flow-icon">🟢</div>
                    <div class="flow-number"><?php echo $stats['neutro']; ?></div>
                    <div class="flow-label">Neutro</div>
                    <div class="flow-description">Periodo di Analisi<br>Stock Monitorato<br>Nessuna Azione</div>
                    <div class="flow-timeline">Analisi 90d Attiva</div>
                    <div class="flow-arrow arrow-2">→</div>
                </div>

              <!-- Stage 5: Bassa Criticità -->
                <div class="flow-card flow-stage-5">
                    <div class="flow-icon">🔵</div>
                    <div class="flow-number"><?php echo $stats['basso']; ?></div>
                    <div class="flow-label">Criticità<br>Bassa</div>
                    <div class="flow-description">30-60 Giorni<br>Stock Sufficiente</div>
                    <div class="flow-timeline">Sistema Stabile</div>
                    <div class="flow-arrow arrow-2">→</div>
                </div>

                <!-- Stage 4: Media Criticità -->
                <div class="flow-card flow-stage-4">
                    <div class="flow-icon">🟡</div>
                    <div class="flow-number"><?php echo $stats['medio']; ?></div>
                    <div class="flow-label">Criticità<br>Media</div>
                    <div class="flow-description">15-30 Giorni<br>Stock da Rifornire</div>
                    <div class="flow-timeline">Sistema in Allerta</div>
                    <div class="flow-arrow arrow-2">→</div>
                </div>

                <!-- Stage 3: Alta Criticità -->
                <div class="flow-card flow-stage-3">
                    <div class="flow-icon">🔴</div>
                    <div class="flow-number"><?php echo $stats['alto']; ?></div>
                    <div class="flow-label">Criticità<br>Alta</div>
                    <div class="flow-description">0-15 Giorni<br>Stock Insufficiente</div>
                    <div class="flow-timeline">Azione Immediata</div>
                    <div class="flow-arrow arrow-2">→</div>
                </div>

                <!-- Stage 6: Elimina -->
                <div class="flow-card flow-stage-6">
                    <div class="flow-icon">🗑️</div>
                    <div class="flow-number"><?php echo $stats['elimina']; ?></div>
                    <div class="flow-label">Non<br>Profittevole</div>
                    <div class="flow-description">Nessuna Vendita<br>Per + di 90 giorni</div>
                    <div class="flow-timeline">Elimina Prodotto</div>
                </div>

                <!-- Stage 7: Totale -->
                <div class="flow-card flow-stage-7">
                    <div class="flow-icon"><i class="fas fa-robot"></i></div>
                    <div class="flow-number"><?php echo number_format($overview['total_items'] ?? 0); ?></div>
                    <div class="flow-label">Prodotti<br>Monitorati</div>
                    <div class="flow-description">Inventario Completo<br>Sotto Controllo</div>
                    <div class="flow-timeline">AI Sistem Attivo</div>
                </div>
            </div>
        </div>

        <!-- Filtri Spettacolari -->
        <div class="filters-panel">
<form method="GET" style="display: inline;">
    <label for="criticity" style="font-weight: 700; margin-right: 1rem; color: #2d3748;">
        <i class="fas fa-filter"></i> Filtra per Criticità:
    </label>
    <select name="criticity" id="criticity" class="filter-select" onchange="this.form.submit()">
        <option value="all" <?php echo $selectedCriticity === 'all' ? 'selected' : ''; ?>>🎯 Tutti i Livelli</option>
        <option value="alto" <?php echo $selectedCriticity === 'alto' ? 'selected' : ''; ?>>🔴 Alta Criticità</option>
        <option value="medio" <?php echo $selectedCriticity === 'medio' ? 'selected' : ''; ?>>🟡 Media Criticità</option>
        <option value="basso" <?php echo $selectedCriticity === 'basso' ? 'selected' : ''; ?>>🔵 Bassa Criticità</option>
        <option value="neutro" <?php echo $selectedCriticity === 'neutro' ? 'selected' : ''; ?>>🟢 Neutri</option>
        <option value="elimina" <?php echo $selectedCriticity === 'elimina' ? 'selected' : ''; ?>>🗑️ Da Eliminare</option>
        <option value="avvia" <?php echo $selectedCriticity === 'avvia' ? 'selected' : ''; ?>>🚀 Avvia Rotazione</option>
    </select>
</form>

<a href="inventory_export_pdf.php?user_id=<?= $userId ?>" class="btn btn-primary" style="margin-left: 1rem; padding: 8px 12px; border-radius: 6px; background-color: #3182ce; color: white; text-decoration: none;">
    🧾 Scarica PDF
</a>
        </div>

        <!-- Tabella Inventario Spettacolare -->
        <div class="inventory-table-container">
            <div class="table-header">
                <div class="table-title">🎯 Analisi Inventario e Rifornimenti</div>
                <div class="table-subtitle">Basato su vendite storiche e proiezioni a 60 giorni - Aggiornato <?php echo timeAgo($overview['last_sync']); ?></div>
            </div>
            <div class="table-responsive">
                <table class="inventory-table">
                    <thead>
                        <tr>
                            <th class="sortable" data-column="product_name" data-type="string"><i class="fas fa-tag"></i> Nome Prodotto</th>
<th class="sortable" data-column="fnsku" data-type="string"><i class="fas fa-barcode"></i> FNSKU</th>
<th class="sortable" data-column="your_price" data-type="number"><i class="fas fa-euro-sign"></i> Prezzo</th>
                            <th class="sortable" data-column="disponibili" data-type="number"><i class="fas fa-boxes"></i> Disponibili</th>
                            <th class="sortable" data-column="in_arrivo" data-type="number"><i class="fas fa-truck"></i> In Arrivo</th>
                            <th class="sortable" data-column="media_vendite_1d" data-type="number"><i class="fas fa-chart-line"></i> Media Vendite 1D</th>
                            <th class="sortable" data-column="giorni_stock" data-type="number"><i class="fas fa-calendar-alt"></i> Giorni di Stock</th>
                            <th class="sortable" data-column="criticita_priority" data-type="number"><i class="fas fa-traffic-light"></i> Criticità</th>
                            <th class="sortable" data-column="invio_suggerito" data-type="number"><i class="fas fa-paper-plane"></i> Invio Suggerito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($analysis)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 3rem; color: #64748b; font-size: 1.1rem;">
                                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; display: block; opacity: 0.5;"></i>
                                    <strong>Nessun dato inventario disponibile</strong><br>
                                    <small>Sincronizza i dati Amazon per visualizzare l'analisi</small>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($analysis as $item): ?>
                                <tr data-criticita="<?php echo $item['criticita']; ?>" 
    data-product-name="<?php echo htmlspecialchars($item['product_name']); ?>"
    data-fnsku="<?php echo htmlspecialchars($item['sku'] ?? ''); ?>"
    data-your-price="<?php echo $item['your_price']; ?>"
    data-disponibili="<?php echo $item['disponibili']; ?>"
    data-in-arrivo="<?php echo $item['in_arrivo']; ?>"
    data-media-vendite-1d="<?php echo $item['media_vendite_1d']; ?>"
    data-giorni-stock="<?php echo $item['giorni_stock']; ?>"
    data-criticita-priority="<?php echo $item['criticita_priority']; ?>"
    data-invio-suggerito="<?php echo $item['invio_suggerito']; ?>">
                                    <td>
                                        <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; font-weight: 600; color: #2d3748;">
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                        // Ottieni FNSKU dalla tabella products
                                        $fnsku = '';
                                        
                                        try {
                                            $db = getDbConnection();
                                            
                                            // Prova prima con product_id se disponibile
                                            if (!empty($item['product_id'])) {
                                                $stmt = $db->prepare("SELECT fnsku FROM products WHERE user_id = ? AND id = ? LIMIT 1");
                                                $stmt->execute([$userId, $item['product_id']]);
                                                $result = $stmt->fetch();
                                                $fnsku = $result['fnsku'] ?? '';
                                            }
                                            // Se non funziona, prova con il nome prodotto
                                            elseif (!empty($item['product_name'])) {
                                                $stmt = $db->prepare("SELECT fnsku FROM products WHERE user_id = ? AND nome = ? LIMIT 1");
                                                $stmt->execute([$userId, $item['product_name']]);
                                                $result = $stmt->fetch();
                                                $fnsku = $result['fnsku'] ?? '';
                                            }
                                        } catch (Exception $e) {
                                            // In caso di errore, lascia fnsku vuoto
                                            $fnsku = '';
                                        }
                                        ?>
                                        <?php if ($fnsku): ?>
                                            <span style="font-weight: 600; color: #059669; font-family: 'Courier New', monospace; font-size: 0.9rem;">
                                                <?php echo htmlspecialchars($fnsku); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic; font-size: 0.85rem;">
                                                Non assegnato
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong style="color: var(--primary); font-size: 1.1rem;">
                                            €<?php echo number_format($item['your_price'], 2); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span style="font-weight: 700; font-size: 1.1rem; color: #2d3748;">
                                            <?php echo number_format($item['disponibili']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($item['in_arrivo'] > 0): ?>
                                            <span style="color: var(--success); font-weight: 700; font-size: 1.1rem;">
                                                <i class="fas fa-arrow-up"></i> <?php echo number_format($item['in_arrivo']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8;">
                                                <i class="fas fa-minus"></i> 0
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600; color: #2d3748;">
                                            <?php echo $item['media_vendite_1d']; ?>
                                            <small style="color: #64748b; font-weight: 400;">/giorno</small>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="font-weight: 700; color: <?php echo $item['giorni_stock'] < 30 ? 'var(--danger)' : '#2d3748'; ?>; font-size: 1.1rem;">
                                            <?php if ($item['giorni_stock'] == 999): ?>
                                                <i class="fas fa-infinity"></i> ∞
                                            <?php else: ?>
                                                <i class="fas fa-calendar-day"></i> <?php echo $item['giorni_stock']; ?>
                                                <small style="font-weight: 400; color: #64748b;"> giorni</small>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="criticity-badge criticity-<?php echo $item['criticita']; ?>">
                                            <?php echo $item['criticita_display']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <span style="font-weight: 700; color: var(--primary); font-size: 1.1rem;">
                                                <?php echo number_format($item['invio_suggerito']); ?>
                                            </span>
                                            <small style="color: #64748b; font-weight: 500;">unità</small>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer Info -->
        <div style="margin-top: 2rem; text-align: center; color: rgba(255,255,255,0.8);">
            <p style="font-weight: 600;">
                <i class="fas fa-info-circle"></i> 
                Dashboard aggiornata automaticamente ogni 6 ore
            </p>
            <p style="font-size: 0.9rem; margin-top: 0.5rem;">
                Ultima sincronizzazione inventario: <?php echo timeAgo($overview['last_sync']); ?>
            </p>
        </div>
    </div>

    <script>
        // === SISTEMA DI SORTING AVANZATO ===
        document.addEventListener('DOMContentLoaded', function() {
            const table = document.querySelector('.inventory-table');
            const headers = table.querySelectorAll('th.sortable');
            let currentSort = { column: 'criticita_priority', direction: 'asc' };
            
            // Imposta ordinamento di default per criticità
            const defaultHeader = table.querySelector('th[data-column="criticita_priority"]');
            if (defaultHeader) {
                defaultHeader.classList.add('sort-asc');
            }
            
            headers.forEach(header => {
                header.addEventListener('click', function() {
                    const column = this.dataset.column;
                    const type = this.dataset.type;
                    
                    // Determina direzione ordinamento
                    let direction = 'asc';
                    if (currentSort.column === column && currentSort.direction === 'asc') {
                        direction = 'desc';
                    }
                    
                    // Aggiorna stato UI
                    headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
                    this.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');
                    
                    // Esegui ordinamento
                    sortTable(column, type, direction);
                    currentSort = { column, direction };
                });
            });
            
            function sortTable(column, type, direction) {
    console.log('Sorting:', column, type, direction);
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
                
                rows.sort((a, b) => {
                    let aVal, bVal;
                    
                    if (type === 'number') {
    const camelColumn = camelize(column);
    aVal = parseFloat(a.dataset[camelColumn]) || 0;
    bVal = parseFloat(b.dataset[camelColumn]) || 0;
    console.log('Sorting numbers:', camelColumn, aVal, bVal);
                        
                        // Gestione speciale per giorni_stock infiniti
                        if (column === 'giorni_stock') {
                            if (aVal === 999) aVal = direction === 'asc' ? Infinity : -Infinity;
                            if (bVal === 999) bVal = direction === 'asc' ? Infinity : -Infinity;
                        }
                    } else {
                        aVal = a.dataset[camelize(column)] || '';
                        bVal = b.dataset[camelize(column)] || '';
                        aVal = aVal.toLowerCase();
                        bVal = bVal.toLowerCase();
                    }
                    
                    if (aVal < bVal) return direction === 'asc' ? -1 : 1;
                    if (aVal > bVal) return direction === 'asc' ? 1 : -1;
                    return 0;
                });
                
                // Ricostruisci tabella
                tbody.innerHTML = '';
                rows.forEach(row => tbody.appendChild(row));
                
                // Animazione di refresh
                tbody.style.opacity = '0.7';
                setTimeout(() => tbody.style.opacity = '1', 150);
            }
            
function camelize(str) {
    return str.replace(/_([a-z])/g, (g) => g[1].toUpperCase())
              .replace(/-([a-z])/g, (g) => g[1].toUpperCase());
}
        });

        // Funzione logout
        function doLogout() {
            if (confirm('Sei sicuro di voler uscire?')) {
                window.location.href = '../margynomic/login/logout.php';
            }
        }

        // Auto-refresh ogni 30 minuti
        setTimeout(function() {
            window.location.reload();
        }, 30 * 60 * 1000);

        // Animazioni al caricamento
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stat-supercard');
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';
                    card.style.transition = 'all 0.6s ease';
                    
                    setTimeout(() => {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, 100);
                }, index * 100);
            });
        });

        console.log('🎯 Dashboard Inventario Previsync caricata con successo!');
        console.log('📊 Statistiche:', <?php echo json_encode($stats); ?>);
        console.log('📦 Overview:', <?php echo json_encode($overview); ?>);
    </script>


</body>
</html>