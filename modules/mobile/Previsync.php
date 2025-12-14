<?php
/**
 * Mobile Previsync - PreviSync
 * Versione mobile identica nella logica a modules/previsync/inventory.php
 */

// Config e Auth
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';

// Include Mobile Cache System
require_once __DIR__ . '/helpers/mobile_cache_helper.php';

if (!isLoggedIn()) {
    redirect('/modules/margynomic/login/login.php');
}

$currentUser = getCurrentUser();
$userId = $currentUser['user_id'] ?? $currentUser['id'] ?? null;

if (!$userId) {
    die('Errore: User ID non trovato nella sessione.');
}

// Redirect desktop
if (!isMobileDevice()) {
    header('Location: /modules/previsync/inventory.php');
    exit;
}

/**
 * Classe per analisi inventario (uguale a desktop)
 */
class InventoryAnalyzer {
    
    private $db;
    private $userId;
    private $strategyManager;
    
    public function __construct($userId) {
        $this->db = getDbConnection();
        $this->userId = $userId;
        
        // Carica Strategy Manager per logica avanzata
        require_once dirname(__DIR__) . '/previsync/inventory_strategy_manager.php';
        $this->strategyManager = new InventoryStrategyManager($this->db, $userId);
    }
    
    /**
     * Analisi completa inventario con tutti i calcoli
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
                    
                FROM inventory inv
                LEFT JOIN products p ON inv.product_id = p.id
                LEFT JOIN product_display_order pdo ON (pdo.user_id = ? AND pdo.product_id = inv.product_id)
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
                ) s ON inv.product_id = s.product_id
                WHERE inv.user_id = ? AND inv.product_id IS NOT NULL
                  AND p.nome NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
                  AND p.fnsku IS NOT NULL AND p.fnsku != ''
                GROUP BY inv.product_id, pdo.position
                ORDER BY pdo.position IS NULL ASC, pdo.position ASC, COALESCE(p.nome, MIN(inv.product_name)) ASC, inv.product_id ASC
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->userId, $this->userId]);
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
            
            // Priorità per ordinamento
            $priorita_criticita = [
                'alto' => 1,
                'medio' => 2, 
                'basso' => 3,
                'avvia' => 4,
                'elimina' => 5,
                'neutro' => 6
            ];
            
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
                'criticita_priority' => $final_priority,
                'product_id' => $item['product_id'] ?? null
            ];
        }
        
        // Ordinamento per criticità
        usort($analysis, function($a, $b) {
            return $a['criticita_priority'] - $b['criticita_priority'];
        });
        
        return [
            'analysis' => $analysis,
            'stats' => $stats
        ];
    }
}

// === SISTEMA CACHE (TTL: 48 ore - invalidazione event-driven) ===
$cacheData = getMobileCache($userId, 'inventory', 172800); // 172800s = 48h

if ($cacheData !== null) {
    // Cache HIT - usa dati cachati
    $analysis = $cacheData['analysis'] ?? [];
    $stats = $cacheData['stats'] ?? [];
} else {
    // Cache MISS - calcola dati freschi
    $analyzer = new InventoryAnalyzer($userId);
    $inventoryData = $analyzer->getCompleteInventoryAnalysis();
    
    $analysis = $inventoryData['analysis'];
    $stats = $inventoryData['stats'];
    
    // Salva in cache
    setMobileCache($userId, 'inventory', [
        'analysis' => $analysis,
        'stats' => $stats
    ]);
}

// Filtro criticità (default: "all")
$selectedCriticity = $_GET['criticity'] ?? 'all';
if ($selectedCriticity !== 'all') {
    $analysis = array_filter($analysis, function($item) use ($selectedCriticity) {
        return $item['criticita'] === $selectedCriticity;
    });
}

// Tutti i prodotti (no paginazione)
$items = $analysis;
$totalRows = count($items);

// KPI aggregati
try {
    $pdo = getDbConnection();
    $stmtKPI = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM products p 
             WHERE p.user_id = ? 
               AND p.nome NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
               AND p.fnsku IS NOT NULL AND p.fnsku != '') as totale_prodotti,
            SUM(i.afn_warehouse_quantity) as totale_disponibili
        FROM inventory i
        LEFT JOIN products p ON i.product_id = p.id
        WHERE i.user_id = ?
          AND COALESCE(p.nome, '') NOT IN ('Prodotti Archiviati', 'ASIN PADRE')
          AND p.fnsku IS NOT NULL AND p.fnsku != ''
    ");
    $stmtKPI->execute([$userId, $userId]);
    $kpi = $stmtKPI->fetch(PDO::FETCH_ASSOC);
    $kpi['critici'] = $stats['alto'];
} catch (PDOException $e) {
    $kpi = ['totale_prodotti' => 0, 'critici' => 0, 'totale_disponibili' => 0];
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#0ea5e9">
    <meta name="apple-mobile-web-app-title" content="SkuAlizer Suite">
    <meta name="format-detection" content="telephone=no">
    <title>PreviSync - Skualizer Mobile</title>
    
    <!-- PWA Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="/modules/mobile/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/modules/mobile/assets/icon-180.png">
    <link rel="manifest" href="/modules/mobile/manifest.json">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/modules/mobile/assets/mobile.css">
    
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/modules/mobile/sw.js').catch(() => {});
    }
    </script>
    <style>
        .hamburger-overlay.active { opacity: 1 !important; visibility: visible !important; }
        .hamburger-overlay.active .hamburger-menu { transform: translateX(0) !important; }
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #ff6b35 !important; }
    </style>
    
    <style>
    body { overflow-x: hidden; padding-top: 0 !important; }
    .mobile-content { padding-top: 0 !important; }
    .hero-welcome {
        background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%);
        color: white;
        padding: 0;
        margin: 0 0 16px 0;
        border-radius: 0 0 20px 20px;
        text-align: left;
        box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        padding-top: env(safe-area-inset-top);
    }
    .hero-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 8px 16px 18px; gap: 12px; }
    .hero-logo { flex: 1; padding-top: 0; }
    .hamburger-btn-hero {
        background: rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 8px;
        width: 40px;
        height: 40px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: white;
        transition: all 0.2s;
    }
    .hamburger-btn-hero:active { transform: scale(0.95); background: rgba(255, 255, 255, 0.25); }
    .hero-title { font-size: 20px; font-weight: 700; margin-bottom: 6px; padding: 0; line-height: 1.3; text-align: left; }
    .hero-subtitle { font-size: 11px; opacity: 0.95; line-height: 1.4; padding: 0; text-align: left; letter-spacing: 0.3px; font-weight: 600; }
    .info-boxes { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-top: 16px; padding: 0 16px 20px; }
    .info-box {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 8px;
        border-left: 3px solid rgba(255, 107, 53, 0.8);
        padding: 10px;
        text-align: left;
        min-width: 0;
        overflow: hidden;
    }
    .info-box-title { font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #1a202c; }
    .info-box-text { font-size: 10px; opacity: 0.75; line-height: 1.4; color: #1a202c; }
    </style>
</head>
<body>
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #ff6b35; background: #fff7ed;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-search" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-user" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #ff6b35; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #ff6b35; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>
    <main class="mobile-content" style="padding-top: 0;">
<div class="hero-welcome">
    <div class="hero-header">
        <div class="hero-logo">
            <div class="hero-title"><i class="fas fa-boxes"></i> PreviSync</div>
            <div class="hero-subtitle">GESTISCI IL TUO INVENTARIO E PREVISIONI!</div>
        </div>
        <button class="hamburger-btn-hero" aria-label="Menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="info-boxes">
        <div class="info-box">
            <div class="info-box-title">📊 Analisi Giacenze</div>
            <div class="info-box-text">Monitora unità disponibili, in transito e previsioni di esaurimento.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">🎯 Strategie Smart</div>
            <div class="info-box-text">Algoritmo intelligente per ottimizzare riordini e ridurre stockout.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">⚡ Performance</div>
            <div class="info-box-text">Velocità media di vendita e trend ultimi 90 giorni.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">💡 Alerting</div>
            <div class="info-box-text">Notifiche automatiche per prodotti in esaurimento o sovrastoccati.</div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const hamburgerBtn = document.querySelector('.hamburger-btn-hero');
    const overlay = document.querySelector('.hamburger-overlay');
    if (hamburgerBtn) {
        hamburgerBtn.addEventListener('click', () => {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
    }
    if (overlay) {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    }
});
function doLogout() {
    if (confirm('Sei sicuro di voler uscire?')) {
        window.location.href = '/modules/margynomic/login/logout.php';
    }
}
</script>

<!-- KPI Grid Principale -->
<div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr); gap: 0.75rem;">
    <a href="?criticity=all" class="kpi-card info" style="text-decoration: none; <?= $selectedCriticity === 'all' ? 'box-shadow: 0 0 0 3px var(--info); transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">📊 Tutti i Prodotti</div>
        <div class="kpi-value"><?= number_format($kpi['totale_prodotti'], 0, ',', '.') ?></div>
    </a>
    
    <div class="kpi-card success">
        <div class="kpi-label">Unità Disponibili</div>
        <div class="kpi-value"><?= number_format($kpi['totale_disponibili'], 0, ',', '.') ?></div>
    </div>
    
    <button id="send-email-btn" 
       style="width: 100%; padding: 16px; background: linear-gradient(135deg, #FF6B35 0%, #F7931E 100%); color: white; border: none; border-radius: 12px; font-size: 16px; font-weight: 700; cursor: pointer; box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3); transition: all 0.3s ease;">
        Scarica Report
    </button>
</div>

<!-- Stats Criticità (Cards Cliccabili) -->
<div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr); gap: 0.75rem; margin-top: 1rem;">
    <a href="?criticity=alto" class="kpi-card danger" style="text-decoration: none; <?= $selectedCriticity === 'alto' ? 'box-shadow: 0 0 0 3px var(--danger); transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">🔴 Alto</div>
        <div class="kpi-value"><?= number_format($stats['alto'], 0, ',', '.') ?></div>
    </a>
    
    <a href="?criticity=medio" class="kpi-card warning" style="text-decoration: none; <?= $selectedCriticity === 'medio' ? 'box-shadow: 0 0 0 3px var(--warning); transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">🟡 Medio</div>
        <div class="kpi-value"><?= number_format($stats['medio'], 0, ',', '.') ?></div>
    </a>
    
    <a href="?criticity=basso" class="kpi-card info" style="text-decoration: none; <?= $selectedCriticity === 'basso' ? 'box-shadow: 0 0 0 3px var(--info); transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">🔵 Basso</div>
        <div class="kpi-value"><?= number_format($stats['basso'], 0, ',', '.') ?></div>
    </a>
    
    <a href="?criticity=neutro" class="kpi-card success" style="text-decoration: none; <?= $selectedCriticity === 'neutro' ? 'box-shadow: 0 0 0 3px var(--success); transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">🟢 Neutro</div>
        <div class="kpi-value"><?= number_format($stats['neutro'], 0, ',', '.') ?></div>
    </a>
    
    <a href="?criticity=elimina" class="kpi-card" style="text-decoration: none; background: rgba(108, 117, 125, 0.1); <?= $selectedCriticity === 'elimina' ? 'box-shadow: 0 0 0 3px #6c757d; transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">🗑️ Elimina</div>
        <div class="kpi-value"><?= number_format($stats['elimina'], 0, ',', '.') ?></div>
    </a>
    
    <a href="?criticity=avvia" class="kpi-card" style="text-decoration: none; background: rgba(102, 126, 234, 0.1); <?= $selectedCriticity === 'avvia' ? 'box-shadow: 0 0 0 3px #667eea; transform: scale(1.05);' : '' ?>">
        <div class="kpi-label">🚀 Avvia</div>
        <div class="kpi-value"><?= number_format($stats['avvia'], 0, ',', '.') ?></div>
    </a>
</div>

<!-- Card "Visualizza Tutti" rimossa - funzione integrata in "Tutti i Prodotti" sopra -->

<!-- Filtri rimossi: ora si usano le cards cliccabili sopra -->

<!-- PreviSync Paginato -->
<div class="section">
    <div class="section-title">PreviSync (<?= number_format($totalRows, 0, ',', '.') ?> Prodotti)</div>
    
    <?php if (count($items) > 0): ?>
        <table class="mobile-table">
            <thead>
                <tr>
                    <th class="sortable" data-column="product_name" data-type="string" style="cursor: pointer;">
                        Prodotto <span class="sort-icon">⇅</span>
                    </th>
                    <th class="sortable" data-column="disponibili" data-type="number" style="text-align: center; white-space: nowrap; cursor: pointer;">
                        Stock <span class="sort-icon">⇅</span>
                    </th>
                    <th class="sortable" data-column="giorni_stock" data-type="number" style="text-align: right; white-space: nowrap; cursor: pointer;">
                        Giorni <span class="sort-icon">⇅</span>
                    </th>
                    <th class="sortable" data-column="invio_suggerito" data-type="number" style="text-align: center; white-space: nowrap; cursor: pointer;">
                        Invio <span class="sort-icon">⇅</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <?php
                    $badgeClass = 'badge-success';
                    $crit = $item['criticita'] ?? 'basso';
                    if ($crit === 'alto') $badgeClass = 'badge-danger';
                    elseif ($crit === 'medio') $badgeClass = 'badge-warning';
                ?>
                <tr 
                    data-product-name="<?= htmlspecialchars($item['product_name']) ?>"
                    data-your-price="<?= $item['your_price'] ?>"
                    data-disponibili="<?= $item['disponibili'] ?>"
                    data-in-arrivo="<?= $item['in_arrivo'] ?>"
                    data-media-vendite-1d="<?= $item['media_vendite_1d'] ?>"
                    data-giorni-stock="<?= $item['giorni_stock'] ?>"
                    data-criticita-priority="<?= $item['criticita_priority'] ?>"
                    data-invio-suggerito="<?= $item['invio_suggerito'] ?>">
                    <td>
                        <div style="font-weight: 700; margin-bottom: 4px; font-size: 13px;">
                            <?= htmlspecialchars($item['product_name']) ?>
                        </div>
                        <?php
                        // FNSKU da products table
                        $fnsku = '';
                        try {
                            $dbTemp = getDbConnection();
                            if (!empty($item['product_id'])) {
                                $stmtFnsku = $dbTemp->prepare("SELECT fnsku FROM products WHERE user_id = ? AND id = ? LIMIT 1");
                                $stmtFnsku->execute([$userId, $item['product_id']]);
                                $resultFnsku = $stmtFnsku->fetch();
                                $fnsku = $resultFnsku['fnsku'] ?? '';
                            }
                        } catch (Exception $e) {
                            $fnsku = '';
                        }
                        ?>
                        <?php if ($fnsku): ?>
                            <div style="font-size: 10px; color: #059669; font-family: monospace; margin-bottom: 2px;">
                                <?= htmlspecialchars($fnsku) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 4px;">
                            €<?= number_format($item['your_price'], 2) ?> • 
                            <?= $item['media_vendite_1d'] ?>/gg
                        </div>
                        <?php
                        $badgeClass = 'badge-success';
                        $badgeText = $item['criticita_display'];
                        if ($item['criticita'] === 'alto') $badgeClass = 'badge-danger';
                        elseif ($item['criticita'] === 'medio') $badgeClass = 'badge-warning';
                        elseif ($item['criticita'] === 'basso') $badgeClass = 'badge-info';
                        ?>
                        <span class="badge <?= $badgeClass ?>" style="font-size: 10px;">
                            <?= htmlspecialchars($badgeText) ?>
                        </span>
                    </td>
                    <td style="text-align: center;">
                        <div style="font-weight: 700; font-size: 14px;">
                            <?= number_format($item['disponibili'], 0, ',', '.') ?>
                        </div>
                        <div style="font-size: 10px; color: var(--info);">
                            +<?= number_format($item['in_arrivo'], 0, ',', '.') ?>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <?php if ($item['giorni_stock'] == 999): ?>
                            <span style="font-weight: 700; color: #6c757d;">∞</span>
                        <?php else: ?>
                            <div style="font-weight: 700; color: <?= $item['giorni_stock'] < 30 ? '#FF3547' : '#2d3748' ?>;">
                                <?= $item['giorni_stock'] ?>
                            </div>
                            <div style="font-size: 10px; color: var(--text-muted);">giorni</div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">
                        <div style="font-weight: 700; color: var(--primary); font-size: 14px;">
                            <?= number_format($item['invio_suggerito'], 0) ?>
                        </div>
                        <div style="font-size: 10px; color: var(--text-muted);">unità</div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Paginazione rimossa - tutti i prodotti visibili con sorting -->
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">📦</div>
            <div class="empty-title">Nessun prodotto</div>
            <div class="empty-text">Non ci sono prodotti con questo livello di criticità.</div>
        </div>
    <?php endif; ?>
</div>

</main>

<?php include __DIR__ . '/_partials/mobile_tabbar.php'; ?>

</body>
</html>

<script>
// === SISTEMA DI SORTING MOBILE ===
document.addEventListener('DOMContentLoaded', function() {
    const table = document.querySelector('.mobile-table');
    if (!table) return;
    
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
            headers.forEach(h => {
                h.classList.remove('sort-asc', 'sort-desc');
                h.querySelector('.sort-icon').textContent = '⇅';
            });
            this.classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');
            this.querySelector('.sort-icon').textContent = direction === 'asc' ? '↑' : '↓';
            
            // Esegui ordinamento
            sortTable(column, type, direction);
            currentSort = { column, direction };
        });
    });
    
    function sortTable(column, type, direction) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        rows.sort((a, b) => {
            let aVal, bVal;
            
            if (type === 'number') {
                const camelColumn = camelize(column);
                aVal = parseFloat(a.dataset[camelColumn]) || 0;
                bVal = parseFloat(b.dataset[camelColumn]) || 0;
                
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

console.log('📱 PreviSync Mobile caricata con sorting attivo');

// === INVIO EMAIL REPORT ===
const sendEmailBtn = document.getElementById('send-email-btn');
if (sendEmailBtn) {
    sendEmailBtn.addEventListener('click', async function() {
        // Toast caricamento
        const toast = document.createElement('div');
        toast.style = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:var(--primary);color:white;padding:12px 24px;border-radius:8px;z-index:9999;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.3);';
        toast.textContent = '📧 Invio in corso...';
        document.body.appendChild(toast);
        
        // Disabilita pulsante
        this.disabled = true;
        this.style.opacity = '0.6';
        
        try {
            const response = await fetch('/modules/mobile/api/send_inventory_email.php', {
                method: 'POST',
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            
            const text = await response.text();
            let result;
            
            try {
                result = JSON.parse(text);
            } catch (e) {
                throw new Error('Risposta non valida');
            }
            
            if (result.success) {
                toast.style.background = '#28a745';
                toast.textContent = '✅ Inviato a ' + result.email;
            } else {
                toast.style.background = '#dc3545';
                toast.textContent = '❌ ' + (result.error || 'Errore sconosciuto');
            }
            
        } catch (err) {
            toast.style.background = '#dc3545';
            toast.textContent = '❌ ' + err.message;
        } finally {
            // Riabilita pulsante
            this.disabled = false;
            this.style.opacity = '1';
            
            // Rimuovi toast dopo 4 secondi
            setTimeout(() => {
                if (document.body.contains(toast)) {
                    document.body.removeChild(toast);
                }
            }, 4000);
        }
    });
}
</script>

<style>
/* Stile Send Email Button */
#send-email-btn:active {
    transform: scale(0.97);
    box-shadow: 0 2px 8px rgba(255, 107, 53, 0.4);
}

#send-email-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Stili per sorting */
.sortable {
    position: relative;
    user-select: none;
    -webkit-tap-highlight-color: transparent;
}

.sortable:active {
    background: rgba(255, 255, 255, 0.1);
}

.sort-icon {
    font-size: 0.8em;
    opacity: 0.5;
    margin-left: 4px;
}

.sort-asc .sort-icon,
.sort-desc .sort-icon {
    opacity: 1;
    color: var(--primary);
}
</style>

