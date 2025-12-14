<?php
/**
 * Margins Overview - Dashboard Margini Utente (Versione Semplificata)
 * File: modules/margynomic/margini/margins_overview.php
 */

// Fix UTF-8 per emoji e caratteri speciali
header('Content-Type: text/html; charset=UTF-8');

// Error reporting per produzione (può essere rimosso se non necessario)
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('display_startup_errors', 1);

// Inizializza variabili di default per evitare errori
$success = false;
$data = [];
$summary = [];
$dbError = null;
$currentUser = null;
$userId = null;

try {
    require_once __DIR__ . '/config_shared.php';
    require_once __DIR__ . '/margins_engine.php';
    require_once __DIR__ . '/../../listing/helpers.php';

    // Autenticazione
    $currentUser = requireUserAuth();
    $userId = $currentUser['id'];
    
    // Redirect mobile
    if (!function_exists('isMobileDevice')) {
        require_once __DIR__ . '/../../login/auth_helpers.php';
    }
    if (isMobileDevice()) {
        header('Location: /modules/mobile/Margynomic.php');
        exit;
    }

    // Filtri semplificati (solo SKU)
    $filters = [
        'start_date' => null,
        'end_date' => null,
        'marketplace' => 'tutti',
        'sku' => trim($_GET['sku'] ?? '')
    ];

    // Calcolo margini
    $engine = new MarginsEngine($userId);
    $marginsData = $engine->calculateMargins($filters);

    $success = $marginsData['success'] ?? false;
    $data = $success ? $marginsData['data'] : [];
    $summary = $success ? $marginsData['summary'] : [];
    
    // Calcolo KPI periodi temporali (7d, 30d, 90d, storico)
    $periodKPIs = $success ? $engine->calculatePeriodKPIs() : [];
    
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $success = false;
    // Inizializza $filters se non definito per evitare errori nella vista
    if (!isset($filters)) {
        $filters = ['sku' => ''];
    }
    CentralLogger::log('margini', 'ERROR', 'Margins overview error: ' . $e->getMessage());
}

require_once dirname(__DIR__) . '/shared_header.php';
?>

<!-- FontAwesome e Chart.js per Margins Overview -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Margins Overview CSS -->
<link rel="stylesheet" href="margins_overview.css">

    <div class="main-container">

        <!-- Hero Welcome -->
        <div class="welcome-hero">
            <div class="welcome-content">
                <h1 class="welcome-title">
                    <i class="fas fa-chart-line"></i> Margynomic AI System
                </h1>
                <p class="welcome-subtitle">
                    SCOPRI COME IL SISTEMA TI GUIDA NEL CALCOLO DEL MARGINE D'UTILE CORRETTO PER OGNI PRODOTTO!
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
                    <div style="background: rgba(56, 161, 105, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #38a169;">
                        <h4 style="color: #38a169; font-weight: 700; margin-bottom: 0.5rem;">💰 Calcolo Margini</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Il sistema analizza prezzi, fee Amazon e costi per determinare la redditività di ogni prodotto.</p>
                    </div>
                    
                    <div style="background: rgba(56, 161, 105, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #38a169;">
                        <h4 style="color: #38a169; font-weight: 700; margin-bottom: 0.5rem;">⚖️ Valutazione Performance</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Ogni SKU viene classificato in base al margine: profittevole, attenzione o perdita.</p>
                    </div>
                    
                    <div style="background: rgba(56, 161, 105, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #38a169;">
                        <h4 style="color: #38a169; font-weight: 700; margin-bottom: 0.5rem;">📈 Ottimizzazione Prezzi</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Modifiche ai prezzi con simulazione immediata dell'impatto sui margini.</p>
                    </div>
                    
                    <div style="background: rgba(56, 161, 105, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #38a169;">
                        <h4 style="color: #38a169; font-weight: 700; margin-bottom: 0.5rem;">🎯 Decision Making</h4>
                        <p style="color: #64748b; line-height: 1.6; margin: 0;">Dashboard KPI per identificare prodotti da ottimizzare o eliminare.</p>
                    </div>
                </div>
        </div>

        <!-- Strategic Flow Grid -->
        <div class="strategic-flow-section">
            <div class="stats-flow-grid">
                <!-- Stage 1: Analisi Iniziale -->
                <div class="flow-card flow-stage-1 active" data-category="all">
                    <div class="flow-icon">🔍</div>
                    <div class="flow-number"><?php echo count($data); ?></div>
                    <div class="flow-label">Avvio Analisi</div>
                    <div class="flow-description">Prodotti Caricati<br>AI Analizza Pricing</div>
                    <div class="flow-timeline">Scansione Attiva</div>
                </div>

                <!-- Stage 2: Profittevole -->
                <div class="flow-card flow-stage-2" data-category="profittevole">
                    <div class="flow-icon">💚</div>
                    <div class="flow-number"><?php 
                        $profittevole = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] > 25);
                        echo count($profittevole);
                    ?></div>
                    <div class="flow-label">Profittevole</div>
                    <div class="flow-description">Margine >25%<br>Ottimo Prodotto</div>
                    <div class="flow-timeline">Mantieni Strategia</div>
                </div>

                <!-- Stage 3: Buono -->
                <div class="flow-card flow-stage-3" data-category="buono">
                    <div class="flow-icon">🟢</div>
                    <div class="flow-number"><?php 
                        $buono = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] >= 18 && $item['margin_percentage'] <= 25);
                        echo count($buono);
                    ?></div>
                    <div class="flow-label">Buono</div>
                    <div class="flow-description">Margine 18-25%<br>Performance Solida</div>
                    <div class="flow-timeline">Monitora Strategia</div>
                </div>

                <!-- Stage 4: Attenzione -->
                <div class="flow-card flow-stage-4" data-category="attenzione">
                    <div class="flow-icon">🟡</div>
                    <div class="flow-number"><?php 
                        $attenzione = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] >= 10 && $item['margin_percentage'] < 18);
                        echo count($attenzione);
                    ?></div>
                    <div class="flow-label">Attenzione</div>
                    <div class="flow-description">Margine 10-18%<br>Ottimizza Pricing</div>
                    <div class="flow-timeline">Rivedi Prezzi</div>
                </div>

                <!-- Stage 5: Critico -->
                <div class="flow-card flow-stage-5" data-category="critico">
                    <div class="flow-icon">🟠</div>
                    <div class="flow-number"><?php 
                        $critico = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] >= 0 && $item['margin_percentage'] < 10);
                        echo count($critico);
                    ?></div>
                    <div class="flow-label">Critico</div>
                    <div class="flow-description">Margine 0-10%<br>Rischio Alto</div>
                    <div class="flow-timeline">Azione Immediata</div>
                </div>

                <!-- Stage 6: Perdita -->
                <div class="flow-card flow-stage-6" data-category="perdita">
                    <div class="flow-icon">🔴</div>
                    <div class="flow-number"><?php 
                        $perdita = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] < 0);
                        echo count($perdita);
                    ?></div>
                    <div class="flow-label">Perdita</div>
                    <div class="flow-description">Margine Negativo<br>Prodotto in Perdita</div>
                    <div class="flow-timeline">Elimina o Riprezza</div>
                </div>

                <!-- Stage 7: Dashboard -->
                <div class="flow-card flow-stage-7" data-category="all">
                    <div class="flow-icon"><i class="fas fa-robot"></i></div>
                    <div class="flow-number"><?php echo number_format(count($data)); ?></div>
                    <div class="flow-label">Dashboard</div>
                    <div class="flow-description">Prodotti Monitorati<br>Analisi Completa</div>
                    <div class="flow-timeline">AI System Attivo</div>
                </div>
            </div>
        </div>

        <div class="content-container"><?php if ($success && !empty($data)): ?>
            <!-- KPI Cards Grid - Periodi Temporali -->
            <div class="kpi-grid">
                <?php
                $periods = [
                    'storico_completo' => ['label' => 'Storico Completo', 'icon' => 'fa-database', 'color' => '#6366f1'],
                    '90_days' => ['label' => 'Ultimi 90 Giorni', 'icon' => 'fa-calendar-alt', 'color' => '#8b5cf6'],
                    '30_days' => ['label' => 'Ultimi 30 Giorni', 'icon' => 'fa-calendar-week', 'color' => '#ec4899'],
                    '7_days' => ['label' => 'Ultimi 7 Giorni', 'icon' => 'fa-calendar-day', 'color' => '#f59e0b']
                ];
                
                $previousPeriod = null;
                foreach ($periods as $periodKey => $periodInfo):
                    $kpi = $periodKPIs[$periodKey] ?? ['prezzo_medio' => 0, 'fee_per_unit' => 0, 'revenue' => 0, 'units' => 0];
                    
                    // Calcola variazioni vs periodo precedente
                    $priceChange = 0;
                    $feeChange = 0;
                    if ($previousPeriod !== null) {
                        $prevKPI = $periodKPIs[$previousPeriod] ?? ['prezzo_medio' => 0, 'fee_per_unit' => 0];
                        if ($prevKPI['prezzo_medio'] != 0) {
                            $priceChange = (($kpi['prezzo_medio'] - $prevKPI['prezzo_medio']) / abs($prevKPI['prezzo_medio'])) * 100;
                        }
                        if ($prevKPI['fee_per_unit'] != 0) {
                            // Per le fee: da -11.64 a -10.89 = +6.4% = MIGLIORAMENTO (meno negative)
                            $feeChange = (($kpi['fee_per_unit'] - $prevKPI['fee_per_unit']) / abs($prevKPI['fee_per_unit'])) * 100;
                        }
                    }
                    $previousPeriod = $periodKey;
                ?>
                <div class="kpi-card-period">
                    <div class="kpi-period-header">
                        <i class="fas <?php echo $periodInfo['icon']; ?>" style="color: <?php echo $periodInfo['color']; ?>"></i>
                        <span><?php echo $periodInfo['label']; ?></span>
                </div>
                
                    <div class="kpi-metrics">
                        <div class="kpi-metric">
                            <div class="kpi-metric-label">Prezzo Medio</div>
                            <div class="kpi-metric-value-row">
                                <div class="kpi-metric-value">€<?php echo number_format($kpi['prezzo_medio'], 2); ?></div>
                                <?php if ($priceChange != 0): ?>
                                <div class="kpi-trend <?php echo $priceChange > 0 ? 'trend-up' : 'trend-down'; ?>">
                                    <i class="fas fa-<?php echo $priceChange > 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                    <?php echo number_format(abs($priceChange), 1); ?>%
                    </div>
                                <?php endif; ?>
                            </div>
                </div>
                
                        <div class="kpi-metric">
                            <div class="kpi-metric-label">Fee per Unità</div>
                            <div class="kpi-metric-value-row">
                                <div class="kpi-metric-value fee-negative">€<?php echo number_format($kpi['fee_per_unit'], 2); ?></div>
                                <?php if ($feeChange != 0): 
                                    // Per le fee: valore positivo = fee meno negative = MIGLIORAMENTO
                                    $isBetter = $feeChange > 0;
                                ?>
                                <div class="kpi-trend <?php echo $isBetter ? 'trend-up' : 'trend-down'; ?>">
                                    <i class="fas fa-<?php echo $isBetter ? 'arrow-up' : 'arrow-down'; ?>"></i>
                                    <?php echo number_format(abs($feeChange), 1); ?>%
                    </div>
                                <?php endif; ?>
                    </div>
                        </div>
                </div>
                
                    <div class="kpi-period-footer">
                        <?php echo number_format($kpi['units']); ?> unità • €<?php echo number_format($kpi['revenue'], 0); ?> revenue
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <div class="chart-card">
                    
                    <div id="revenueVsFeeChart">
                        <div style="display: flex; justify-content: center; align-items: center; height: 200px; color: #666;">
                            <i class="fas fa-spinner fa-spin"></i> Caricamento grafico...
                        </div>
                    </div>
                </div>
                
                <div class="chart-card">
                    
                    <div id="topProductsChart">
                        <div style="display: flex; justify-content: center; align-items: center; height: 200px; color: #666;">
                            <i class="fas fa-spinner fa-spin"></i> Caricamento grafico...
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Search Section -->
<div class="search-section">
    <div class="search-container">
        <div class="search-form">
            <input type="text" 
                   id="product-search" 
                   class="search-box" 
                   placeholder="🔍 Cerca prodotti per nome..." 
                   autocomplete="off">
            <div id="search-results" class="search-results" style="display: none;"></div>
            <button type="button" id="clear-search" class="clear-btn" style="display: none;">
                <i class="fas fa-times"></i> Cancella
            </button>
        </div>
    </div>
</div>

            <?php if ($success && !empty($data)): ?>
            <!-- Table Section -->
            <div class="table-section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-boxes"></i> Analisi Prodotti
                    </h2>
                    <p class="section-subtitle">
                        Click su Fee Totali per espandere il dettaglio - Prezzo e Costo sono modificabili inline
                    </p>
                </div>
                
                <table class="products-table">
                    <!-- Header -->
                    <thead>
                        <tr class="product-header">
                            <th>Prodotto</th>
                            <th>Prezzo</th>
                            <th>Unità</th>
                            <th>Fee Totali</th>
                            <th>Materia Prima</th>
                            <th>Utile Netto</th>
                            <th>Margine %</th>
                        </tr>
                    </thead>
                    <tbody>
                    <!-- Prodotti -->
                    <?php foreach ($data as $row): ?>
                        <?php 
                        $units = $row['units_sold'] ?: 1;
                        $unitPrice = $row['prezzo_attuale'] ?? 0;
                        $unitCost = $row['costo_prodotto'] ?? 0;
                        $totalRevenue = $row['revenue'];
                        $totalFees = $row['total_fees'];
                        $totalCostMaterial = $unitCost * $units;
                        $totalProfit = $row['net_profit'];
                        $unitProfit = $units > 0 ? $totalProfit / $units : 0;
                        $marginPercent = $row['margin_percentage'];
                        $unitFees = $units > 0 ? $totalFees / $units : 0;
                        
                        // Determina classe margine
                        $marginClass = 'margin-danger';
                        if ($marginPercent >= 10) $marginClass = 'margin-excellent';
                        elseif ($marginPercent >= 5) $marginClass = 'margin-good';
                        elseif ($marginPercent >= 0) $marginClass = 'margin-warning';
                        
                        // Determina categoria margine per filtri
                        $marginCategory = 'perdita';
                        if ($marginPercent > 25) $marginCategory = 'profittevole';
                        elseif ($marginPercent >= 18) $marginCategory = 'buono';
                        elseif ($marginPercent >= 10) $marginCategory = 'attenzione';
                        elseif ($marginPercent >= 0) $marginCategory = 'critico';
                        
                        // Fee breakdown per espansione
                        $feeTabCommissioni = $row["fee_FEE_TAB1"] ?? 0;
                        $feeTabOperative = $row["fee_FEE_TAB2"] ?? 0; 
                        $feeTabCompensazioni = $row["fee_FEE_TAB3"] ?? 0;
                        $customFeeAmount = $row['custom_fee_amount'] ?? 0;
                        ?>
                        
                        <tr class="product-item" 
                            data-product-id="<?php echo $row['product_id']; ?>" 
                            data-sku="<?php echo htmlspecialchars($row['all_skus'] ?? ''); ?>"
                            data-margin-category="<?php echo $marginCategory; ?>"
                            data-margin-percent="<?php echo $marginPercent; ?>">
                            <!-- Informazioni Prodotto -->
                            <td class="product-info" style="text-align: left; vertical-align: middle;">
                                <div class="product-name"><?php echo htmlspecialchars($row['product_name'] ?? 'N/A'); ?></div>
                                <div class="product-details">
                                    Revenue: €<?php echo number_format($totalRevenue, 2); ?> • <?php echo number_format($units, 0); ?> vendite totali
                                </div>
                            </td>
                            
                            <!-- Prezzo -->
                            <td class="metric-cell" style="text-align: center; vertical-align: middle;">
                                <div class="price-input-container">
                                    <input type="number" 
                                           class="price-input-main" 
                                           data-product-id="<?php echo $row['product_id']; ?>"
                                           data-field="prezzo_attuale"
                                           data-original="<?php echo $unitPrice; ?>"
                                           value="<?php echo number_format($unitPrice, 2); ?>" 
                                           min="0" 
                                           step="0.01">
                                    <button class="save-btn-main" data-product-id="<?php echo $row['product_id']; ?>">
                                        <i class="fas fa-save"></i> Salva
                                    </button>
                                </div>
                                <div class="metric-sub">€<?php echo number_format($totalRevenue, 2); ?></div>
                            </td>
                            
                            <!-- Unità -->
                            <td class="metric-cell" style="text-align: center; vertical-align: middle;">
                                <div class="metric-main"><?php echo number_format($units, 0); ?></div>
                                <div class="metric-sub">unità</div>
                            </td>
                            
                            <!-- Fee Totali (Expandable) -->
<td class="metric-cell fee-clickable" style="text-align: center; vertical-align: middle;" 
     data-product-id="<?php echo $row['product_id']; ?>" 
     data-product-name="<?php echo htmlspecialchars($row['product_name'] ?? 'N/A'); ?>"
     data-units="<?php echo $units; ?>"
     data-fee-tab1="<?php echo $feeTabCommissioni; ?>"
     data-fee-tab2="<?php echo $feeTabOperative; ?>"
     data-fee-tab3="<?php echo $feeTabCompensazioni; ?>"
     data-custom-fee="<?php echo $customFeeAmount; ?>"
     data-custom-fee-type="<?php echo $row['custom_fee_type'] ?? ''; ?>"
     data-custom-fee-value="<?php echo $row['custom_fee_value'] ?? 0; ?>"
     data-custom-fee-desc="<?php echo htmlspecialchars($row['custom_fee_description'] ?? ''); ?>"
     data-php-total-fees="<?php echo $row['total_fees']; ?>"
data-tab1-shared="<?php echo $row['fee_TAB1_SHARED'] ?? 0; ?>"
data-total-fees-amazon="<?php echo $row['total_fees_amazon'] ?? 0; ?>"
     onclick="openDetachedFeePanel(this)">
                                <div class="metric-main calculated-unit-fees <?php echo $unitFees >= 0 ? 'fee-positive' : 'fee-negative'; ?>">
                                    €<?php echo number_format($unitFees, 2); ?> <i class="fas fa-external-link-alt fee-info-icon"></i>
                                </div>
                                <div class="metric-sub calculated-total-fees <?php echo $totalFees >= 0 ? 'fee-positive' : 'fee-negative'; ?>">€<?php echo number_format($totalFees, 2); ?></div>
                            </td>
                            
                            <!-- Materia Prima -->
                            <td class="metric-cell" style="text-align: center; vertical-align: middle;">
                                <div class="price-input-container">
                                    <input type="number" 
                                           class="price-input-main" 
                                           data-product-id="<?php echo $row['product_id']; ?>"
                                           data-field="costo_prodotto"
                                           data-original="<?php echo $unitCost; ?>"
                                           value="<?php echo number_format($unitCost, 2); ?>" 
                                           min="0" 
                                           step="0.01">
                                    <button class="save-btn-main" data-product-id="<?php echo $row['product_id']; ?>">
                                        <i class="fas fa-save"></i> Salva
                                    </button>
                                </div>
                                <div class="metric-sub calculated-total-material">€<?php echo number_format($totalCostMaterial, 2); ?></div>
                            </td>
                            
                            <!-- Utile Netto -->
                            <td class="metric-cell" style="text-align: center; vertical-align: middle;">
                                <div class="metric-main <?php echo $unitProfit >= 0 ? 'price-positive' : 'price-negative'; ?> calculated-unit-profit">€<?php echo number_format($unitProfit, 3); ?></div>
                                <div class="metric-sub calculated-total-profit">€<?php echo number_format($totalProfit, 2); ?></div>
                            </td>
                            
                            <!-- Margine % -->
                            <td class="metric-cell" style="text-align: center; vertical-align: middle;">
                                <div class="margin-heatmap <?php echo $marginClass; ?> calculated-margin"><?php echo number_format($marginPercent, 1); ?>%</div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: white;">
                    <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; display: block;"></i>
                    <strong>Nessun dato disponibile</strong><br>
                    <?php if (!$success): ?>
                        <small>Errore: <?php echo htmlspecialchars($marginsData['error'] ?? 'Errore sconosciuto'); ?></small>
                    <?php else: ?>
                        <small>Carica i dati di settlement per visualizzare i margini</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- DETACHED FEE PANEL -->
    <div id="detachedFeePanel" class="detached-fee-panel">
        <div class="dfp-header" id="dfpHeader">
            <h3 class="dfp-title" id="dfpTitle">📊 Dettaglio Fee</h3>
            <div class="dfp-controls">
                <button class="dfp-btn" id="dfpCloseBtn" title="Chiudi">✕</button>
            </div>
        </div>
        <div class="dfp-content" id="dfpContent">
            <!-- Content will be populated by JavaScript -->
        </div>
    </div>

    <!-- Feedback Toast -->
    <div id="feedback" class="feedback"></div>

    <script>
        // Variabili globali
        const originalValues = new Map();
        let unsavedChanges = new Set();

        // Funzione per mostrare feedback
        function showFeedback(message, type = 'success') {
            const feedback = document.getElementById('feedback');
            feedback.textContent = message;
            feedback.className = `feedback ${type} show`;
            
            setTimeout(() => {
                feedback.classList.remove('show');
            }, 3000);
        }

        // === DETACHED FEE PANEL (DFP) SYSTEM ===
        
        // Carica breakdown dettagliato fee
        async function loadFeeBreakdown(productId) {
            const container = document.getElementById(`breakdown-${productId}`);
            const btn = container.previousElementSibling;
            
            if (container.style.display === 'block') {
                container.style.display = 'none';
                btn.textContent = '📊 Mostra dettaglio fee';
                return;
            }
            
            try {
                btn.textContent = '⏳ Caricamento...';
                const response = await fetch(`get_fee_breakdown.php?product_id=${productId}`);
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.error);
                }
                
                let html = '<div class="breakdown-content">';
                
                for (const [category, items] of Object.entries(data.breakdown)) {
                    const categoryName = getCategoryDisplayName(category);
                    const totalCategoryAmount = items.reduce((sum, item) => sum + parseFloat(item.amount || 0), 0);
                    
                    html += `<div class="breakdown-category">
                        <div class="breakdown-category-header" onclick="toggleBreakdownCategory('${category}-${productId}')">
                            <span class="breakdown-toggle">▶</span>
                            <span class="breakdown-category-name">${categoryName}</span>
                            <span class="breakdown-category-total">€${totalCategoryAmount.toFixed(2)}</span>
                        </div>
                        <div class="breakdown-items" id="${category}-${productId}" style="display: none;">`;
                    
                    for (const item of items) {
                        const displayName = item.item_related_fee_type || item.transaction_type || 'Altro';
                        html += `<div class="breakdown-sub-item">
                            <span class="breakdown-bullet">•</span>
                            <span class="breakdown-label">${displayName}</span>
                            <span class="breakdown-amount">€${parseFloat(item.amount || 0).toFixed(2)}</span>
                        </div>`;
                    }
                    
                    html += '</div></div>';
                }
                
                html += '</div>';
                container.innerHTML = html;
                container.style.display = 'block';
                btn.textContent = '📊 Nascondi dettaglio';
                
            } catch (error) {
                container.innerHTML = `<div class="error">Errore: ${error.message}</div>`;
                container.style.display = 'block';
                btn.textContent = '📊 Mostra dettaglio fee';
            }
        }
        
        function getCategoryDisplayName(categoryCode) {
            const names = {
                'FEE_TAB1': 'Commissioni (Tab1)',
                'FEE_TAB2': 'Operative (Tab2)', 
                'FEE_TAB3': 'Compensazioni (Tab3)'
            };
            return names[categoryCode] || categoryCode;
        }
        
        async function toggleCategoryBreakdown(categoryKey) {
            const [categoryCode, productId] = categoryKey.split('-');
            const container = document.getElementById(`breakdown-${categoryKey}`);
            const toggle = document.getElementById(`toggle-${categoryKey}`);
            
            if (container.style.display === 'none') {
                // Carica breakdown se non già caricato
                if (container.innerHTML.includes('Caricamento dettaglio...')) {
                    try {
                        const response = await fetch(`get_fee_breakdown.php?product_id=${productId}`);
                        const data = await response.json();
                        
                        if (data.success && data.breakdown[categoryCode]) {
                            let html = '';
                            for (const item of data.breakdown[categoryCode]) {
                                const displayName = item.item_related_fee_type || item.transaction_type || 'Altro';
                                html += `<div class="fee-sub-item">• ${displayName}: €${parseFloat(item.amount || 0).toFixed(2)}</div>`;
                            }
                            container.innerHTML = html;
                        } else {
                            container.innerHTML = '<div class="fee-sub-item">Nessun dettaglio disponibile</div>';
                        }
                    } catch (error) {
                        container.innerHTML = '<div class="fee-sub-item error">Errore caricamento dettaglio</div>';
                    }
                }
                
                container.style.display = 'block';
                toggle.textContent = '▼';
            } else {
                container.style.display = 'none';
                toggle.textContent = '▶';
            }
        }
        
        function toggleBreakdownCategory(categoryId) {
            const container = document.getElementById(categoryId);
            const toggle = container.previousElementSibling.querySelector('.breakdown-toggle');
            
            if (container.style.display === 'none') {
                container.style.display = 'block';
                toggle.textContent = '▼';
            } else {
                container.style.display = 'none';
                toggle.textContent = '▶';
            }
        }
        let dfpCurrentProductId = null;
        let isDragging = false;
        let dragOffset = { x: 0, y: 0 };

        async function openDetachedFeePanel(element) {
    const dfp = document.getElementById('detachedFeePanel');
    const productId = element.dataset.productId;
    const productName = element.dataset.productName;
    
    
    // Se è già aperto per lo stesso prodotto, non fare nulla
    if (dfpCurrentProductId === productId && dfp.classList.contains('show')) {
        return;
    }
    
    dfpCurrentProductId = productId;
    
    // Aggiorna titolo
    document.getElementById('dfpTitle').textContent = `📊 ${productName}`;
            
// Popola contenuto
await populateDFPContent(element);
            
            // Reset posizione al centro
            dfp.style.transform = 'translate(-50%, -50%)';
            dfp.style.top = '50%';
            dfp.style.left = '50%';
            dfp.style.right = 'auto';
            dfp.style.bottom = 'auto';
            dfp.style.width = 'min(90vw, 450px)';
            dfp.style.height = 'auto';
            
            // Mostra il panel
dfp.classList.add('show');
dfpJustOpened = true;
        }

        // Nuova funzione per formattare fee con breakdown sempre visibile
const formatFeeWithColorAndBreakdown = async (value, label, categoryCode, productId) => {
    const isPositive = value > 0;
    const colorClass = isPositive ? 'fee-positive' : 'fee-negative';
    const displayValue = isPositive ? value : Math.abs(value);
    const sign = isPositive ? '' : '-';
    
    let breakdownHtml = '';
    
    // Carica breakdown immediatamente
    try {
        const response = await fetch(`get_fee_breakdown.php?product_id=${productId}`);
        const data = await response.json();
        
        if (data.success && data.breakdown[categoryCode]) {
            for (const item of data.breakdown[categoryCode]) {
                const displayName = item.item_related_fee_type || item.transaction_type || 'Altro';
                const itemAmount = parseFloat(item.amount || 0);
                const itemSign = itemAmount >= 0 ? '' : '-';
                breakdownHtml += `<div class="fee-sub-item">  • ${displayName}: ${itemSign}€${Math.abs(itemAmount).toFixed(2)}</div>`;
            }
        }
    } catch (error) {
        breakdownHtml = '<div class="fee-sub-item">  • Errore caricamento dettaglio</div>';
    }
    
    return `
        <div class="dfp-fee-item">
            <div class="fee-main-line">
                <span>${label}:</span>
                <span class="${colorClass}">${sign}€${displayValue.toFixed(2)}</span>
            </div>
            <div class="fee-breakdown-sub">
                ${breakdownHtml}
            </div>
        </div>`;
};

async function populateDFPContent(element) {
    const units = parseInt(element.dataset.units);
    const feeTab1 = parseFloat(element.dataset.feeTab1) || 0;
    const feeTab2 = parseFloat(element.dataset.feeTab2) || 0;
    const feeTab3 = parseFloat(element.dataset.feeTab3) || 0;
    const customFee = parseFloat(element.dataset.customFee) || 0;
    const customFeeType = element.dataset.customFeeType || 'none';
    const customFeeValue = parseFloat(element.dataset.customFeeValue) || 0;
    const customFeeDesc = element.dataset.customFeeDesc || '';
    const productId = element.dataset.productId;
    
    // Usa il prezzo aggiornato se disponibile, altrimenti quello originale
    const currentPrice = parseFloat(element.dataset.currentPrice) || 
                        parseFloat(document.querySelector(`[data-product-id="${productId}"][data-field="prezzo_attuale"]`)?.value) || 0;
            
            // Calcola fee per unità
            const unitFeeTab1 = units > 0 ? feeTab1 / units : 0;
            const unitFeeTab2 = units > 0 ? feeTab2 / units : 0;
            const unitFeeTab3 = units > 0 ? feeTab3 / units : 0;
            const unitCustomFee = units > 0 ? customFee / units : 0;
            
// Calcola totale con custom fee aggiornata
let currentCustomFeeAmount = 0;
if (customFeeType !== 'none' && customFeeValue > 0) {
    if (customFeeType === 'percent') {
        currentCustomFeeAmount = (currentPrice * customFeeValue / 100) * -1 * units;
    } else if (customFeeType === 'fixed') {
        currentCustomFeeAmount = customFeeValue * -1 * units;
    }
}

// Calcola totale sostituendo la custom fee originale con quella aggiornata
const tab1Shared = parseFloat(element.dataset.tab1Shared) || 0;
const totalFeesWithUpdatedCustom = -Math.abs(feeTab1) + feeTab2 + feeTab3 + currentCustomFeeAmount + tab1Shared;
const totalUnitFees = units > 0 ? totalFeesWithUpdatedCustom / units : 0;
            
            // Helper function per creare cards fee
            const createFeeCard = (iconClass, iconSymbol, label, amount, breakdown = '') => {
                const isPositive = amount >= 0;
                const colorClass = isPositive ? 'fee-positive' : 'fee-negative';
                const displayValue = Math.abs(amount);
                const sign = isPositive ? '' : '-';
                
                return `
                    <div class="fee-card">
                        <div class="fee-card-header">
                            <div class="fee-card-left">
                                <div class="fee-icon ${iconClass}">${iconSymbol}</div>
                                <span class="fee-label">${label}</span>
                            </div>
                            <span class="fee-amount ${colorClass}">${sign}€${displayValue.toFixed(2)}</span>
                        </div>
                        ${breakdown ? `<div class="fee-breakdown">${breakdown}</div>` : ''}
                    </div>`;
            };
            
            // Helper function per formattare fee dettagliate per unità
            const formatDetailedFeeList = async (categoryCode, productId, units) => {
                let detailHtml = '';
                try {
                    const response = await fetch(`get_fee_breakdown.php?product_id=${productId}`);
                    const data = await response.json();
                    
                    if (data.success && data.breakdown[categoryCode]) {
                        for (const item of data.breakdown[categoryCode]) {
                            const displayName = item.item_related_fee_type || item.transaction_type || 'Altro';
                            const totalAmount = parseFloat(item.amount || 0);
                            const unitAmount = units > 0 ? totalAmount / units : 0;
                            const isPositive = unitAmount >= 0;
                            const colorClass = isPositive ? 'fee-positive' : 'fee-negative';
                            const sign = isPositive ? '' : '-';
                            // Per Tab1 (commissioni), mostra sempre il segno negativo per indicare che sono costi
                            const displaySign = categoryCode === 'FEE_TAB1' ? '-' : (isPositive ? '' : '-');
                            // Per Tab1, forza il colore rosso per indicare che sono sempre costi
                            const displayColorClass = categoryCode === 'FEE_TAB1' ? 'fee-negative' : colorClass;
                            detailHtml += `
                                <div class="fee-breakdown-item">
                                    <span>• ${displayName}</span>
                                    <span class="${displayColorClass}" style="font-weight: 600;">${displaySign}€${Math.abs(unitAmount).toFixed(2)}</span>
                                </div>`;
                        }
                    }
                } catch (error) {
                    detailHtml = '<div class="fee-breakdown-item"><span>• Errore caricamento dettaglio</span><span></span></div>';
                }
                return detailHtml;
            };
            
            let content = '';
            
// Tab1 - Commissioni con breakdown dettagliato
const tab1Details = await formatDetailedFeeList('FEE_TAB1', productId, units);
content += createFeeCard('tab1', '💳', 'Commissioni (Tab1)', -Math.abs(unitFeeTab1), tab1Details);

// Tab2 - Operative con breakdown dettagliato
if (unitFeeTab2 !== 0) {
    const tab2Details = await formatDetailedFeeList('FEE_TAB2', productId, units);
    content += createFeeCard('tab2', '⚙️', 'Operative (Tab2)', unitFeeTab2, tab2Details);
}

// Tab3 - Compensazioni con breakdown dettagliato
if (unitFeeTab3 !== 0) {
    const tab3Details = await formatDetailedFeeList('FEE_TAB3', productId, units);
    content += createFeeCard('tab3', '🛡️', 'Compensazioni (Tab3)', unitFeeTab3, tab3Details);
}

// Custom Fee - ricalcolata con il prezzo corrente
if (customFeeType !== 'none' && customFeeValue > 0) {
    let currentCustomFeePerUnit = 0;
    if (customFeeType === 'percent') {
        currentCustomFeePerUnit = (currentPrice * customFeeValue / 100) * -1;
    } else if (customFeeType === 'fixed') {
        currentCustomFeePerUnit = customFeeValue * -1;
    }
    content += createFeeCard('custom', '⭐', 'Custom Fee', currentCustomFeePerUnit);
}

// Rimborsi distribuiti senza breakdown dettagliato
if (tab1Shared !== 0) {
    const unitTab1Shared = units > 0 ? tab1Shared / units : 0;
    content += createFeeCard('rimborsi', '💰', 'Rimborsi distribuiti', unitTab1Shared);
}


            
            // Form Custom Fee
            content += `
                <div class="fee-card">
                    <div class="custom-fee-form" 
                         data-product-id="${productId}"
                         data-original-custom-fee="${unitCustomFee}">
                        <div class="fee-header-small" style="font-weight: 600; color: #1e293b; margin-bottom: 12px; text-align: center;">
                            ${customFeeType !== 'none' ? 'Modifica Fee Extra' : 'Aggiungi Fee Extra'}
                        </div>
                        
                        <div class="custom-fee-inputs">
                            <select class="custom-fee-type">
                                <option value="none" ${customFeeType === 'none' ? 'selected' : ''}>--</option>
                                <option value="percent" ${customFeeType === 'percent' ? 'selected' : ''}>%</option>
                                <option value="fixed" ${customFeeType === 'fixed' ? 'selected' : ''}>€</option>
                            </select>
                            
                            <input type="number" 
                                   step="0.01" 
                                   class="custom-fee-value" 
                                   placeholder="0.00"
                                   value="${customFeeType !== 'none' ? customFeeValue.toFixed(2) : ''}">
                            
                            <input type="text" 
                                   class="custom-fee-description" 
                                   placeholder="Descrizione"
                                   value="${customFeeDesc}">
                        </div>
                        
                        <div class="custom-fee-preview">
                             Anteprima: <span class="preview-value">€0.00</span>
                         </div>
                        
                        <div class="custom-fee-buttons">
                            <button class="save-custom-fee">Salva</button>
                            ${customFeeType !== 'none' ? '<button class="remove-custom-fee">Rimuovi</button>' : ''}
                        </div>
                    </div>
                </div>
                
                <!-- SEZIONE TOTALE -->
                <div class="dfp-total-section">
                    <div class="dfp-total-label">TOTALE per unità</div>
                    <div class="dfp-total-amount ${totalUnitFees >= 0 ? 'fee-positive' : 'fee-negative'}">€${totalUnitFees.toFixed(2)}</div>
                </div>`;
            
            document.getElementById('dfpContent').innerHTML = content;
            
            // Re-inizializza custom fee per questo nuovo contenuto
            const form = document.querySelector('.custom-fee-form[data-product-id="' + productId + '"]');
            if (form) {
                updateCustomFeePreview(form);
            }
        }

        function closeDFP() {
    const dfp = document.getElementById('detachedFeePanel');
    dfp.classList.remove('show');
    dfpCurrentProductId = null;
}

        // Ricalcola valori della riga
        function recalculateRowValues(row) {
            const prezzoInput = row.querySelector('[data-field="prezzo_attuale"]');
            const costoInput = row.querySelector('[data-field="costo_prodotto"]');
            
            if (!prezzoInput || !costoInput) return;
            
            const currentPrice = parseFloat(prezzoInput.value) || 0;
            const currentCost = parseFloat(costoInput.value) || 0;
            const originalPrice = parseFloat(prezzoInput.dataset.original) || 0;
            const originalCost = parseFloat(costoInput.dataset.original) || 0;
            
            // Ottieni unità vendute
            const unitsCell = row.children[2];
            const units = parseInt(unitsCell.querySelector('.metric-main').textContent) || 1;
            
            // Calcola nuovi valori proiettati
            const totalRevenue = currentPrice * units;
            const totalCostMaterial = currentCost * units;

            // Ottieni fee unitarie STORICHE dal database (dati certi)
            const feeCell = row.children[3];
            const unitFeesText = feeCell.querySelector('.calculated-unit-fees').textContent || '0';
            
            // Parser robusto per euro
            const parseEuroToFloat = (txt) => {
                let t = (txt || '').replace(/[€\s]/g, '').trim();
                if (t.includes(',') && t.includes('.')) { t = t.replace(/,/g, ''); } 
                else if (t.includes(',')) { t = t.replace(/\./g, '').replace(',', '.'); }
                return parseFloat(t) || 0;
            };
            
            // USA SEMPRE le fee storiche dal database (dati certi)
// MA ricalcola la custom fee basata sul prezzo attuale
let unitFees = parseEuroToFloat(unitFeesText);
let unitFeesUpdated = unitFees; // Inizializza con il valore originale

// Ricalcola custom fee se presente
const productId = prezzoInput.dataset.productId;
const customFeeForm = row.querySelector(`.custom-fee-form[data-product-id="${productId}"]`);

// Controlla se c'è una custom fee dai data attributes della cella fee
const customFeeType = feeCell.dataset.customFeeType || 'none';
const customFeeValue = parseFloat(feeCell.dataset.customFeeValue) || 0;

if (customFeeType && customFeeType !== 'none' && customFeeValue > 0) {
                    let customFeeAmount = 0;
                    if (customFeeType === 'percent') {
                        customFeeAmount = (currentPrice * customFeeValue / 100) * -1; // Per unità
                    } else if (customFeeType === 'fixed') {
                        customFeeAmount = customFeeValue * -1; // Per unità
                    }
                    
                    // Ricalcola le fee totali includendo la custom fee aggiornata

// Ricalcola le fee totali includendo la custom fee aggiornata
const feeTab1 = parseFloat(feeCell.dataset.feeTab1) || 0;
const feeTab2 = parseFloat(feeCell.dataset.feeTab2) || 0;
const feeTab3 = parseFloat(feeCell.dataset.feeTab3) || 0;
const tab1Shared = parseFloat(feeCell.dataset.tab1Shared) || 0;
const customFeeTotal = customFeeAmount * units;

// Calcola totale fee aggiornato (replica logica PHP)
const totalFeesUpdated = -Math.abs(feeTab1) + feeTab2 + feeTab3 + customFeeTotal + tab1Shared;
unitFeesUpdated = units > 0 ? totalFeesUpdated / units : 0;

// Aggiorna il display delle fee nella colonna principale
const feeMainDisplay = feeCell.querySelector('.calculated-unit-fees');
const feeTotalDisplay = feeCell.querySelector('.calculated-total-fees');
if (feeMainDisplay && feeTotalDisplay) {
    feeMainDisplay.textContent = `€${unitFeesUpdated.toFixed(2)}`;
    feeTotalDisplay.textContent = `€${totalFeesUpdated.toFixed(2)}`;
    
    // Aggiorna classi di colore
    feeMainDisplay.classList.remove('fee-positive', 'fee-negative');
    feeTotalDisplay.classList.remove('fee-positive', 'fee-negative');
    feeMainDisplay.classList.add(unitFeesUpdated >= 0 ? 'fee-positive' : 'fee-negative');
    feeTotalDisplay.classList.add(totalFeesUpdated >= 0 ? 'fee-positive' : 'fee-negative');
    
    // Indica che è una proiezione se il prezzo è cambiato
    if (Math.abs(currentPrice - originalPrice) > 0.001) {
        feeMainDisplay.style.fontStyle = 'italic';
        feeMainDisplay.title = 'Fee ricalcolate con nuovo prezzo (custom fee)';
        feeTotalDisplay.style.fontStyle = 'italic';
        feeTotalDisplay.title = 'Fee ricalcolate con nuovo prezzo (custom fee)';
    } else {
        feeMainDisplay.style.fontStyle = 'normal';
        feeMainDisplay.title = '';
        feeTotalDisplay.style.fontStyle = 'normal';
        feeTotalDisplay.title = '';
    }
}
                }
            
            const totalFees = unitFeesUpdated * units;
// Replica esatta della formula PHP: revenue - cost + fees  
const totalProfit = totalRevenue - totalCostMaterial + totalFees;
            const unitProfit = units > 0 ? totalProfit / units : 0;
            const marginPercent = totalRevenue > 0 ? (totalProfit / totalRevenue) * 100 : 0;
            
            // IMPORTANTE: Aggiorna DFP solo alla fine dopo tutti i calcoli
            updateCustomFeeForPrice(productId, currentPrice);
            
            // Aggiorna revenue se il prezzo è cambiato
            const revenueSubValue = prezzoInput.parentNode.querySelector('.metric-sub');
            if (revenueSubValue) {
                revenueSubValue.textContent = `€${totalRevenue.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                if (Math.abs(currentPrice - originalPrice) > 0.001) {
                    revenueSubValue.style.fontStyle = 'italic';
                    revenueSubValue.title = 'Valore proiettato con il nuovo prezzo';
                } else {
                    revenueSubValue.style.fontStyle = 'normal';
                    revenueSubValue.title = '';
                }
            }

            // Costo totale materia prima
            const materialSubValue = costoInput.parentNode.querySelector('.calculated-total-material');
            if (materialSubValue) {
                materialSubValue.textContent = `€${totalCostMaterial.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                if (Math.abs(currentCost - originalCost) > 0.001) {
                    materialSubValue.style.fontStyle = 'italic';
                    materialSubValue.title = 'Valore proiettato con il nuovo costo';
                } else {
                    materialSubValue.style.fontStyle = 'normal';
                    materialSubValue.title = '';
                }
            }

            // Utile per unità 
            const unitProfitCell = row.querySelector('.calculated-unit-profit');
            if (unitProfitCell) {
                const isProjection = Math.abs(currentPrice - originalPrice) > 0.001 || 
                                   Math.abs(currentCost - originalCost) > 0.001;
                
                unitProfitCell.textContent = `€${unitProfit.toFixed(3)}`;
                unitProfitCell.className = `metric-main calculated-unit-profit ${unitProfit >= 0 ? 'price-positive' : 'price-negative'}`;
                
                if (isProjection) {
                    unitProfitCell.style.fontStyle = 'italic';
                    unitProfitCell.title = 'Utile proiettato con i nuovi valori';
                } else {
                    unitProfitCell.style.fontStyle = 'normal';
                    unitProfitCell.title = '';
                }
            }

            // Utile totale
            const totalProfitCell = row.querySelector('.calculated-total-profit');
            if (totalProfitCell) {
                const isProjection = Math.abs(currentPrice - originalPrice) > 0.001 || 
                                   Math.abs(currentCost - originalCost) > 0.001;
                
                totalProfitCell.textContent = `€${totalProfit.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                
                if (isProjection) {
                    totalProfitCell.style.fontStyle = 'italic';
                    totalProfitCell.title = 'Utile proiettato con i nuovi valori';
                } else {
                    totalProfitCell.style.fontStyle = 'normal';
                    totalProfitCell.title = '';
                }
            }

            // Margine %
            const marginCell = row.querySelector('.calculated-margin');
            if (marginCell) {
                const isProjection = Math.abs(currentPrice - originalPrice) > 0.001 || 
                                   Math.abs(currentCost - originalCost) > 0.001;
                
                marginCell.textContent = `${marginPercent.toFixed(1)}%`;
                
                // Aggiorna classe heatmap
                let marginClass = 'margin-danger';
                if (marginPercent >= 10) marginClass = 'margin-excellent';
                else if (marginPercent >= 5) marginClass = 'margin-good';
                else if (marginPercent >= 0) marginClass = 'margin-warning';
                
                marginCell.className = `margin-heatmap ${marginClass} calculated-margin`;
                
                if (isProjection) {
                    marginCell.style.fontStyle = 'italic';
                    marginCell.title = 'Margine proiettato con i nuovi valori';
                } else {
                    marginCell.style.fontStyle = 'normal';
                    marginCell.title = '';
                }
            }
        }

        // Gestione cambi input
        function handleInputChange(e) {
    const input = e.target;
    const productId = input.dataset.productId;
    const field = input.dataset.field;
    const currentValue = parseFloat(input.value) || 0;
            const originalValue = originalValues.get(`${productId}_${field}`) || 0;
            const key = `${productId}_${field}`;
            
            // Controlla se il valore è cambiato
            if (Math.abs(currentValue - originalValue) > 0.001) {
                unsavedChanges.add(key);
                input.parentNode.querySelector('.save-btn-main').classList.add('show');
                
                // Ricalcola valori
                recalculateRowValues(input.closest('.product-item'));
            } else {
                unsavedChanges.delete(key);
                input.parentNode.querySelector('.save-btn-main').classList.remove('show');
                
                // IMPORTANTE: Ricalcola anche quando torna al valore originale
                recalculateRowValues(input.closest('.product-item'));
            }
            
            // Se cambia il prezzo, aggiorna custom fee preview
if (field === 'prezzo_attuale') {
    updateCustomFeeForPrice(productId, currentValue);
}
        }

        // Salva prodotto da bottone
        async function saveProductFromButton(button) {
            const row = button.closest('.product-item');
            if (!row) {
                showFeedback('Errore: Riga prodotto non trovata', 'error');
                return;
            }
            
            const productId = button.dataset.productId;
            if (!productId) {
                showFeedback('Errore: ID prodotto non trovato', 'error');
                return;
            }
            
            const inputs = row.querySelectorAll('.price-input-main');
            const data = { product_id: productId };
            
            inputs.forEach(input => {
                data[input.dataset.field] = parseFloat(input.value) || 0;
            });
            
            try {
                const response = await fetch('update_product_pricing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Aggiorna i valori originali e rimuovi da modifiche non salvate
                    inputs.forEach(input => {
                        const key = `${productId}_${input.dataset.field}`;
                        unsavedChanges.delete(key);
                        originalValues.set(key, parseFloat(input.value) || 0);
                        input.dataset.original = input.value;
                        input.parentNode.querySelector('.save-btn-main').classList.remove('show');
                    });
                    
                    // FORZA il ricalcolo con i nuovi valori salvati
                    setTimeout(() => {
                        recalculateRowValues(row);
                    }, 100);
                    
                    showFeedback('Prodotto salvato con successo!', 'success');
                } else {
                    showFeedback('Errore: ' + (result.error || 'Salvataggio fallito'), 'error');
                }
            } catch (error) {
                showFeedback('Errore di connessione: ' + error.message, 'error');
            }
        }

        // Salva prodotto
        async function saveProduct(productId) {
            const element = document.querySelector(`[data-product-id="${productId}"]`);
            if (!element) {
                showFeedback('Errore: Elemento prodotto non trovato', 'error');
                return;
            }
            
            const row = element.closest('.product-item');
            if (!row) {
                showFeedback('Errore: Riga prodotto non trovata', 'error');
                return;
            }
            
            const inputs = row.querySelectorAll('.price-input-main');
            const data = { product_id: productId };
            
            inputs.forEach(input => {
                data[input.dataset.field] = parseFloat(input.value) || 0;
            });
            
            try {
                const response = await fetch('update_product_pricing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Aggiorna i valori originali e rimuovi da modifiche non salvate
                    inputs.forEach(input => {
                        const key = `${productId}_${input.dataset.field}`;
                        unsavedChanges.delete(key);
                        originalValues.set(key, parseFloat(input.value) || 0);
                        input.dataset.original = input.value;
                        input.parentNode.querySelector('.save-btn-main').classList.remove('show');
                    });
                    
                    // FORZA il ricalcolo con i nuovi valori salvati
                    setTimeout(() => {
                        recalculateRowValues(row);
                    }, 100);
                    
                    showFeedback('Prodotto salvato con successo!', 'success');
                } else {
                    showFeedback('Errore: ' + (result.error || 'Salvataggio fallito'), 'error');
                }
            } catch (error) {
                showFeedback('Errore di connessione: ' + error.message, 'error');
            }
        }

        // === CUSTOM FEE SYSTEM ===
        function initializeCustomFees() {
            // Event listeners per form custom fee
            document.addEventListener('input', handleCustomFeeInput);
            document.addEventListener('change', handleCustomFeeInput);
            document.addEventListener('click', handleCustomFeeButtons);
        }

        function handleCustomFeeInput(e) {
            if (e.target.matches('.custom-fee-type, .custom-fee-value')) {
                const form = e.target.closest('.custom-fee-form');
                updateCustomFeePreview(form);
            }
        }

        function handleCustomFeeButtons(e) {
            if (e.target.matches('.save-custom-fee')) {
                e.preventDefault();
                e.stopPropagation();
                const form = e.target.closest('.custom-fee-form');
                if (form) {
                    saveCustomFee(form);
                }
            } else if (e.target.matches('.remove-custom-fee')) {
                e.preventDefault();
                e.stopPropagation();
                const form = e.target.closest('.custom-fee-form');
                if (form) {
                    removeCustomFee(form);
                }
            }
        }

        function updateCustomFeePreview(form) {
            const typeEl = form.querySelector('.custom-fee-type');
            const valueEl = form.querySelector('.custom-fee-value');
            const previewEl = form.querySelector('.preview-value');
            
            const type = typeEl.value;
            const value = parseFloat(valueEl.value) || 0;
            
            if (type === 'none' || value === 0) {
                previewEl.textContent = '€0.00';
                return;
            }
            
            // Ottieni prezzo prodotto dalla riga
            const productId = form.dataset.productId;
            const row = document.querySelector(`[data-product-id="${productId}"]`)?.closest('.product-item');
            const priceInput = row?.querySelector('[data-field="prezzo_attuale"]');
            const currentPrice = parseFloat(priceInput?.value) || 0;
            
            let calculatedFee = 0;
            if (type === 'percent') {
                calculatedFee = (currentPrice * value / 100) * -1;
            } else if (type === 'fixed') {
                calculatedFee = value * -1;
            }
            
            previewEl.textContent = `€${calculatedFee.toFixed(2)}`;
        }

        // Aggiorna custom fee quando cambia il prezzo
        function updateCustomFeeForPrice(productId, newPrice) {
    
    // Aggiorna nel form se presente
    const customFeeForm = document.querySelector(`.custom-fee-form[data-product-id="${productId}"]`);
            if (customFeeForm) {
                updateCustomFeePreview(customFeeForm);
            }
            
            // Verifica se il prodotto ha una custom fee e se il DFP è aperto per questo prodotto
const feeCell = document.querySelector(`[data-product-id="${productId}"].fee-clickable`);
const hasCustomFee = feeCell && feeCell.dataset.customFeeType !== 'none';
const dfpIsOpen = document.getElementById('detachedFeePanel').classList.contains('show');

// Aggiorna nel DFP se esiste una custom fee (indipendentemente se è aperto o chiuso)
if (hasCustomFee) {
    // Salva i valori aggiornati nei data attributes della cella per quando il DFP verrà aperto
    feeCell.dataset.currentPrice = newPrice;
    
    // Se il DFP è aperto per questo prodotto, aggiorna in tempo reale
    if (dfpCurrentProductId === productId && dfpIsOpen) {
        
        // Prima aggiorna il form preview nel DFP
        const dfpForm = document.querySelector('#detachedFeePanel .custom-fee-form');
        if (dfpForm) {
            updateCustomFeePreview(dfpForm);
        }
        
        // Poi aggiorna il display della custom fee nella lista fee
        updateDFPCustomFeeDisplay(productId, newPrice);
        
        // Infine aggiorna il totale
        updateDFPTotal(productId, newPrice);
    }
}
}
        
        // Aggiorna il display della custom fee nel DFP
        function updateDFPCustomFeeDisplay(productId, newPrice) {
    
    // Ottieni i dati della custom fee dalla cella originale
    const feeCell = document.querySelector(`[data-product-id="${productId}"].fee-clickable`);
    if (!feeCell) {
        return;
    }
    
    const customFeeType = feeCell.dataset.customFeeType || 'none';
    const customFeeValue = parseFloat(feeCell.dataset.customFeeValue) || 0;
    
    if (customFeeType === 'none' || customFeeValue === 0) {
        return;
    }
            
            // Calcola la nuova custom fee
            let newCustomFeeAmount = 0;
            if (customFeeType === 'percent') {
                newCustomFeeAmount = (newPrice * customFeeValue / 100) * -1;
            } else if (customFeeType === 'fixed') {
                newCustomFeeAmount = customFeeValue * -1;
            }
            
            // Aggiorna il display nel DFP - cerca nelle fee-card, non dfp-fee-item
const dfpCustomFeeElements = document.querySelectorAll('#detachedFeePanel .fee-card');
dfpCustomFeeElements.forEach(card => {
    const feeLabel = card.querySelector('.fee-label');
    if (feeLabel && feeLabel.textContent.includes('Custom Fee')) {
        const feeAmount = card.querySelector('.fee-amount');
        if (feeAmount) {
            const newCustomFeeSign = newCustomFeeAmount >= 0 ? '' : '-';
            const colorClass = newCustomFeeAmount >= 0 ? 'fee-positive' : 'fee-negative';
            feeAmount.textContent = `${newCustomFeeSign}€${Math.abs(newCustomFeeAmount).toFixed(2)}`;
            feeAmount.className = `fee-amount ${colorClass}`;
        }
    }
});
            
            // Ricalcola e aggiorna il totale nel DFP
            updateDFPTotal(productId, newPrice);
        }
        
        // Aggiorna il totale nel DFP quando cambia il prezzo
        function updateDFPTotal(productId, newPrice) {
            const feeCell = document.querySelector(`[data-product-id="${productId}"].fee-clickable`);
            if (!feeCell) return;
            
            const units = parseInt(feeCell.dataset.units) || 1;
            const feeTab1 = parseFloat(feeCell.dataset.feeTab1) || 0;
            const feeTab2 = parseFloat(feeCell.dataset.feeTab2) || 0;
            const feeTab3 = parseFloat(feeCell.dataset.feeTab3) || 0;
            const tab1Shared = parseFloat(feeCell.dataset.tab1Shared) || 0;
            
            // Calcola custom fee aggiornata
            const customFeeType = feeCell.dataset.customFeeType || 'none';
            const customFeeValue = parseFloat(feeCell.dataset.customFeeValue) || 0;
            let customFeeAmount = 0;
            
            if (customFeeType !== 'none' && customFeeValue > 0) {
                if (customFeeType === 'percent') {
                    customFeeAmount = (newPrice * customFeeValue / 100) * -1 * units;
                } else if (customFeeType === 'fixed') {
                    customFeeAmount = customFeeValue * -1 * units;
                }
            }
            
            // Calcola totale fee per unità (replica logica PHP)
            const totalFees = -Math.abs(feeTab1) + feeTab2 + feeTab3 + customFeeAmount + tab1Shared;
            const unitFees = units > 0 ? totalFees / units : 0;
            
            // Aggiorna il totale nel DFP
            const dfpTotalAmount = document.querySelector('#detachedFeePanel .dfp-total-amount');
            if (dfpTotalAmount) {
                dfpTotalAmount.textContent = `€${unitFees.toFixed(2)}`;
                dfpTotalAmount.className = `dfp-total-amount ${unitFees >= 0 ? 'fee-positive' : 'fee-negative'}`;
            }
        }

        async function saveCustomFee(form) {
            const productId = form.dataset.productId;
            const typeEl = form.querySelector('.custom-fee-type');
            const valueEl = form.querySelector('.custom-fee-value');
            const descEl = form.querySelector('.custom-fee-description');
            
            const type = typeEl.value;
            const value = parseFloat(valueEl.value) || 0;
            const description = descEl.value.trim();
            
            const payload = {
                product_id: parseInt(productId),
                fee_type: type,
                fee_value: value,
                fee_description: description || null
            };
            
            try {
                const response = await fetch('update_custom_fee.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showFeedback('Custom fee salvata con successo!', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    showFeedback('Errore: ' + (result.error || 'Salvataggio fallito'), 'error');
                }
            } catch (error) {
                showFeedback('Errore di connessione: ' + error.message, 'error');
            }
        }

        async function removeCustomFee(form) {
            const productId = form.dataset.productId;
            
            if (!confirm('Rimuovere la custom fee per questo prodotto?')) {
                return;
            }
            
            try {
                const response = await fetch('update_custom_fee.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: parseInt(productId),
                        fee_type: 'none',
                        fee_value: 0,
                        fee_description: null
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showFeedback('Custom fee rimossa con successo!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showFeedback('Errore: ' + (result.error || 'Rimozione fallita'), 'error');
                }
            } catch (error) {
                showFeedback('Errore di connessione: ' + error.message, 'error');
            }
        }

        // Inizializzazione
        document.addEventListener('DOMContentLoaded', function() {
            // Salva valori originali
            document.querySelectorAll('.price-input-main').forEach(input => {
                const key = `${input.dataset.productId}_${input.dataset.field}`;
                originalValues.set(key, parseFloat(input.dataset.original) || 0);
            });

            // Event listeners per input changes
document.querySelectorAll('.price-input-main').forEach(input => {
    input.addEventListener('input', handleInputChange);
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            const saveBtn = this.parentNode.querySelector('.save-btn-main');
            if (saveBtn.classList.contains('show')) {
                saveProduct(this.dataset.productId);
            }
        }
    });
});

            // Event listeners per save buttons
            document.querySelectorAll('.save-btn-main').forEach(btn => {
                btn.addEventListener('click', function() {
                    saveProductFromButton(this);
                });
            });

            // Warning before unload se ci sono modifiche non salvate
            window.addEventListener('beforeunload', function(e) {
                if (unsavedChanges.size > 0) {
                    e.preventDefault();
                    e.returnValue = 'Ci sono modifiche non salvate. Sei sicuro di voler uscire?';
                }
            });

            // Inizializza Custom Fee System
            initializeCustomFees();

            // Inizializza preview per custom fee esistenti
            document.querySelectorAll('.custom-fee-form').forEach(form => {
                updateCustomFeePreview(form);
            });

            // Inizializza Detached Fee Panel
            initializeDFP();

        });

        // === DFP EVENT LISTENERS ===
        function initializeDFP() {
            const dfp = document.getElementById('detachedFeePanel');
            const header = document.getElementById('dfpHeader');
            const closeBtn = document.getElementById('dfpCloseBtn');

            // Close button
            closeBtn.addEventListener('click', closeDFP);

            // Drag functionality
            header.addEventListener('mousedown', startDrag);
            document.addEventListener('mousemove', drag);
            document.addEventListener('mouseup', stopDrag);

            // Click outside to close (evita chiusura immediata usando flag)
let dfpJustOpened = false;

document.addEventListener('click', function(e) {
    // Se il DFP è appena stato aperto, ignora questo click
    if (dfpJustOpened) {
        dfpJustOpened = false;
        return;
    }
    
    const dfp = document.getElementById('detachedFeePanel');
    if (dfp.classList.contains('show') && !dfp.contains(e.target) && !e.target.closest('.fee-clickable')) {
        closeDFP();
    }
});
        }

        function startDrag(e) {
            if (e.target.closest('.dfp-controls')) return;
            
            isDragging = true;
            const dfp = document.getElementById('detachedFeePanel');
            const rect = dfp.getBoundingClientRect();
            
            dragOffset.x = e.clientX - rect.left;
            dragOffset.y = e.clientY - rect.top;
            
            dfp.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
        }

        function drag(e) {
            if (!isDragging) return;
            
            e.preventDefault();
            const dfp = document.getElementById('detachedFeePanel');
            
            let newX = e.clientX - dragOffset.x;
            let newY = e.clientY - dragOffset.y;
            
            // Bounds checking
            const maxX = window.innerWidth - dfp.offsetWidth;
            const maxY = window.innerHeight - dfp.offsetHeight;
            
            newX = Math.max(0, Math.min(newX, maxX));
            newY = Math.max(0, Math.min(newY, maxY));
            
            dfp.style.left = newX + 'px';
            dfp.style.top = newY + 'px';
            dfp.style.right = 'auto';
            dfp.style.bottom = 'auto';
        }

        function stopDrag() {
            if (isDragging) {
                isDragging = false;
                const dfp = document.getElementById('detachedFeePanel');
                dfp.style.cursor = '';
                document.body.style.userSelect = '';
            }
        }


    </script>

    <!-- Charts JavaScript -->
    <script src="margins_charts.js"></script>
    
    <!-- Search + Category Filters: Sistema Unificato INLINE -->
    <script>
    (function() {
        let activeFilter = 'all';
        let searchQuery = '';
        
        // Elementi DOM
        const searchInput = document.getElementById('product-search');
        const clearBtn = document.getElementById('clear-search');
        const productsTable = document.querySelector('.products-table tbody');
        const filterCards = document.querySelectorAll('.flow-card');
        const allRows = document.querySelectorAll('tr.product-item');
        
        // Funzione unificata per applicare ENTRAMBI i filtri
        function applyFilters() {
            let visibleCount = 0;
            allRows.forEach(function(row) {
                let showProduct = true;
                
                // 1. Filtro CATEGORIA (dalla card)
                if (activeFilter !== 'all') {
                    const rowCategory = row.getAttribute('data-margin-category');
                    if (rowCategory !== activeFilter) {
                        showProduct = false;
                    }
                }
                
                // 2. Filtro RICERCA (dalla search bar)
                if (searchQuery.length >= 2) {
                    const sku = (row.getAttribute('data-sku') || '').toLowerCase();
                    const nameCell = row.querySelector('.product-name');
                    const name = nameCell ? nameCell.textContent.toLowerCase() : '';
                    
                    if (!name.includes(searchQuery) && !sku.includes(searchQuery)) {
                        showProduct = false;
                    }
                }
                
                // Applica visibilità
                row.style.display = showProduct ? '' : 'none';
                if (showProduct) visibleCount++;
            });
            
            // Aggiorna contatore
            updateVisibleCount(visibleCount);
        }
        
        // Aggiorna contatore visibile
        function updateVisibleCount(count) {
            const counterEl = document.querySelector('.visible-count');
            if (counterEl) {
                counterEl.textContent = count;
            }
        }
        
        // Event listener SEARCH BAR
        if (searchInput) {
            searchInput.addEventListener('input', function(e) {
                searchQuery = e.target.value.trim().toLowerCase();
                
                // Mostra/nascondi pulsante clear
                if (clearBtn) {
                    clearBtn.style.display = searchQuery ? 'flex' : 'none';
                }
                
                applyFilters();
            });
        }
        
        // Event listener CLEAR BUTTON
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchQuery = '';
                clearBtn.style.display = 'none';
                applyFilters();
            });
        }
        
        // Event listener CARD FILTERS
        filterCards.forEach(function(card) {
            card.addEventListener('click', function() {
                const category = this.getAttribute('data-category');
                
                // Rimuovi active da tutte le card
                filterCards.forEach(c => c.classList.remove('active'));
                
                // Aggiungi active alla card cliccata
                this.classList.add('active');
                
                // Aggiorna filtro attivo
                activeFilter = category;
                
                applyFilters();
            });
        });
    })();
    </script>
