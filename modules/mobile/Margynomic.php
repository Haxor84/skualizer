<?php
/**
 * Mobile Margynomic - Margini
 * Versione mobile identica nella logica a modules/margynomic/margini/margins_overview.php
 */

// Config e Auth
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';

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
    header('Location: /modules/margynomic/margini/margins_overview.php');
    exit;
}

// Include Cache Helper
require_once __DIR__ . '/helpers/mobile_cache_helper.php';

// Flag per rendering HTML
$renderHTML = false;

// Inizializza variabili
$success = false;
$data = [];
$summary = [];
$dbError = null;
$fromCache = false;

try {
    // === SISTEMA CACHE (TTL: 48 ore - invalidazione event-driven) ===
    $cacheData = getMobileCache($userId, 'margins', 172800); // 172800s = 48h
    
    if ($cacheData !== null) {
        // Cache valida, usa dati cachati
        $success = $cacheData['success'] ?? false;
        $data = $cacheData['data'] ?? [];
        $summary = $cacheData['summary'] ?? [];
        $profittevole = $cacheData['profittevole'] ?? [];
        $buono = $cacheData['buono'] ?? [];
        $attenzione = $cacheData['attenzione'] ?? [];
        $critico = $cacheData['critico'] ?? [];
        $perdita = $cacheData['perdita'] ?? [];
        $allProducts = $cacheData['allProducts'] ?? [];
        $periodKPIs = $cacheData['periodKPIs'] ?? [];
        $fromCache = true;
        
    } else {
        // Cache non valida/assente, calcola dati freschi
        
        // Include MarginsEngine (logica identica al desktop)
        require_once dirname(__DIR__) . '/margynomic/margini/config_shared.php';
        require_once dirname(__DIR__) . '/margynomic/margini/margins_engine.php';
        require_once dirname(__DIR__) . '/listing/helpers.php';

        // Filtri (identici versione desktop: no date, no SKU - ricerca lato client)
        $filters = [
            'start_date' => null,
            'end_date' => null,
            'marketplace' => 'tutti',
            'sku' => ''
        ];

        // Calcolo margini con MarginsEngine (come desktop)
        $engine = new MarginsEngine($userId);
        $marginsData = $engine->calculateMargins($filters);

        $success = $marginsData['success'] ?? false;
        $data = $success ? $marginsData['data'] : [];
        $summary = $success ? $marginsData['summary'] : [];
        
        // Calcolo KPI periodi temporali (7d, 30d, 90d, storico)
        $periodKPIs = $success ? $engine->calculatePeriodKPIs() : [];
        
        // Calcola categorizzazione prodotti per margine
        $profittevole = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] > 25);
        $buono = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] >= 18 && $item['margin_percentage'] <= 25);
        $attenzione = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] >= 10 && $item['margin_percentage'] < 18);
        $critico = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] >= 0 && $item['margin_percentage'] < 10);
        $perdita = array_filter($data, fn($item) => isset($item['margin_percentage']) && $item['margin_percentage'] < 0);
        
        // Usa lo stesso ordinamento del desktop (da MarginsEngine query ORDER BY)
        $allProducts = $data;
        
        // === SALVA IN CACHE ===
        if ($success) {
            setMobileCache($userId, 'margins', [
                'success' => $success,
                'data' => $data,
                'summary' => $summary,
                'profittevole' => $profittevole,
                'buono' => $buono,
                'attenzione' => $attenzione,
                'critico' => $critico,
                'perdita' => $perdita,
                'allProducts' => $allProducts,
                'periodKPIs' => $periodKPIs
            ]);
        }
    }
    
} catch (Exception $e) {
    $dbError = $e->getMessage();
    $success = false;
    error_log("Mobile Margynomic Error: " . $e->getMessage());
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
    <meta name="theme-color" content="#38a169">
    <meta name="apple-mobile-web-app-title" content="SkuAlizer Suite">
    <meta name="format-detection" content="telephone=no">
    <title>Margynomic - Skualizer Mobile</title>
    
    <!-- PWA Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="/modules/mobile/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/modules/mobile/assets/icon-180.png">
    <link rel="manifest" href="/modules/mobile/manifest.json">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/modules/mobile/assets/mobile.css">
    
    <!-- Service Worker Registration -->
    <script>
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/modules/mobile/sw.js').catch(() => {});
    }
    </script>
    <style>
        .hamburger-overlay.active { opacity: 1 !important; visibility: visible !important; }
        .hamburger-overlay.active .hamburger-menu { transform: translateX(0) !important; }
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #38a169 !important; }
    </style>
    
    <style>
    /* Margynomic Hero Styles */
    body {
        overflow-x: hidden;
        padding-top: 0 !important;
    }

    .mobile-content {
        padding-top: 0 !important;
    }

    .hero-welcome {
        background: linear-gradient(135deg, #38a169 0%, #48bb78 100%);
        color: white;
        padding: 0;
        margin: 0 0 16px 0;
        border-radius: 0 0 20px 20px;
        text-align: left;
        box-shadow: 0 4px 12px rgba(56, 161, 105, 0.3);
        padding-top: env(safe-area-inset-top);
    }

    .hero-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        padding: 8px 16px 18px;
        gap: 12px;
    }

    .hero-logo {
        flex: 1;
        padding-top: 0;
    }

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

    .hamburger-btn-hero:active {
        transform: scale(0.95);
        background: rgba(255, 255, 255, 0.25);
    }

    .hero-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 6px;
        padding: 0;
        line-height: 1.3;
        text-align: left;
    }

    .hero-subtitle {
        font-size: 11px;
        opacity: 0.95;
        line-height: 1.4;
        padding: 0;
        text-align: left;
        letter-spacing: 0.3px;
        font-weight: 600;
    }

    .info-boxes {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        margin-top: 16px;
        padding: 0 16px 20px;
    }

    .info-box {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 8px;
        border-left: 3px solid rgba(56, 161, 105, 0.8);
        padding: 10px;
        text-align: left;
        min-width: 0;
        overflow: hidden;
    }
    
    .info-box-title {
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 4px;
        color: #1a202c;
    }
    
    .info-box-text {
        font-size: 10px;
        opacity: 0.75;
        line-height: 1.4;
        color: #1a202c;
    }
    
    /* KPI Cards Periodi Temporali - Mobile */
    .kpi-grid-periods {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        padding: 0 16px;
        margin-bottom: 16px;
    }
    
    .kpi-card-period {
        background: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
        backdrop-filter: blur(10px);
        border-radius: 16px;
        padding: 12px;
        border: 3px solid #10b981;
        box-shadow: 0 8px 24px rgba(16, 185, 129, 0.15);
        transition: all 0.3s ease;
    }
    
    .kpi-card-period:active {
        transform: scale(0.98);
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    }
    
    .kpi-period-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid rgba(0,0,0,0.05);
        font-weight: 700;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        color: #1a202c;
    }
    
    .kpi-period-header i {
        font-size: 14px;
    }
    
    .kpi-metrics {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 12px;
    }
    
    .kpi-metric {
        position: relative;
    }
    
    .kpi-metric-label {
        font-size: 9px;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-bottom: 4px;
        font-weight: 600;
    }
    
    .kpi-metric-value-row {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }
    
    .kpi-metric-value {
        font-size: 16px;
        font-weight: 800;
        color: #1a202c;
        line-height: 1;
        font-family: 'SF Mono', 'Monaco', monospace;
    }
    
    .kpi-metric-value.fee-negative {
        color: #ef4444;
    }
    
    .kpi-trend {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        padding: 2px 6px;
        border-radius: 8px;
        font-size: 9px;
        font-weight: 700;
    }
    
    .kpi-trend.trend-up {
        background: linear-gradient(135deg, #dcfce7, #bbf7d0);
        color: #15803d;
        border: 1px solid #86efac;
    }
    
    .kpi-trend.trend-down {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        color: #dc2626;
        border: 1px solid #fca5a5;
    }
    
    .kpi-trend i {
        font-size: 8px;
    }
    
    .kpi-period-footer {
        padding-top: 8px;
        border-top: 2px solid rgba(0,0,0,0.05);
        font-size: 9px;
        color: #64748b;
        font-weight: 600;
        text-align: center;
        line-height: 1.4;
    }
    
    /* Mobile responsive - single column su schermi piccoli */
    @media (max-width: 360px) {
        .kpi-grid-periods {
            grid-template-columns: 1fr;
        }
    }
    
    /* Filtro Categorie Margine - Active State */
    .filter-card.filter-active {
        border: 3px solid #10b981 !important;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2), 0 4px 12px rgba(16, 185, 129, 0.3) !important;
        transform: scale(1.02);
        position: relative;
    }
    
    .filter-card.filter-active::after {
        content: '✓';
        position: absolute;
        top: 4px;
        right: 4px;
        background: #10b981;
        color: white;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(0,0,0,0.2);
    }
    
    .filter-card:active {
        transform: scale(0.95);
    }
    </style>
</head>
<body>
    <!-- Sprite Icons -->
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    
    <!-- Hamburger Overlay Menu -->
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #38a169 0%, #48bb78 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #38a169; background: #f0fdf4;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-search" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-user" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #38a169; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #38a169; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content Start -->
    <main class="mobile-content" style="padding-top: 0;">

<!-- Hero Welcome con Header Integrato -->
<div class="hero-welcome">
    <div class="hero-header">
        <div class="hero-logo">
            <div class="hero-title"><i class="fas fa-chart-line"></i> Margynomic</div>
            <div class="hero-subtitle">ANALIZZA I MARGINI DEI TUOI PRODOTTI!</div>
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
            <div class="info-box-title">📊 Margini Real-Time</div>
            <div class="info-box-text">Calcola margini su ogni prodotto considerando tutti i costi Amazon.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">🎯 Categorizzazione</div>
            <div class="info-box-text">Prodotti profittevoli, buoni, attenzione, critici o in perdita.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">💡 Ottimizzazione</div>
            <div class="info-box-text">Identifica quali prodotti migliorare per massimizzare i profitti.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">📈 Performance</div>
            <div class="info-box-text">Monitora andamento vendite e costi per marketplace.</div>
        </div>
    </div>
</div>

<script>
// Setup hamburger menu
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

<?php if ($success && !empty($data)): ?>
<!-- KPI Grid Periodi Temporali (identica versione desktop) -->
<div class="kpi-grid-periods">
    <?php
    $periods = [
        'storico_completo' => ['label' => 'Storico', 'icon' => 'fa-database', 'color' => '#6366f1'],
        '90_days' => ['label' => '90 Giorni', 'icon' => 'fa-calendar-alt', 'color' => '#8b5cf6'],
        '30_days' => ['label' => '30 Giorni', 'icon' => 'fa-calendar-week', 'color' => '#ec4899'],
        '7_days' => ['label' => '7 Giorni', 'icon' => 'fa-calendar-day', 'color' => '#f59e0b']
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
                $feeChange = (($kpi['fee_per_unit'] - $prevKPI['fee_per_unit']) / abs($prevKPI['fee_per_unit'])) * 100;
            }
        }
        $previousPeriod = $periodKey;
    ?>
    <div class="kpi-card-period">
        <div class="kpi-period-header">
            <i class="fas <?= $periodInfo['icon'] ?>" style="color: <?= $periodInfo['color'] ?>;"></i>
            <span><?= $periodInfo['label'] ?></span>
        </div>
        
        <div class="kpi-metrics">
            <div class="kpi-metric">
                <div class="kpi-metric-label">Prezzo Medio</div>
                <div class="kpi-metric-value-row">
                    <div class="kpi-metric-value">€<?= number_format($kpi['prezzo_medio'], 2) ?></div>
                    <?php if ($priceChange != 0): ?>
                    <div class="kpi-trend <?= $priceChange > 0 ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-<?= $priceChange > 0 ? 'arrow-up' : 'arrow-down' ?>"></i>
                        <?= number_format(abs($priceChange), 1) ?>%
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="kpi-metric">
                <div class="kpi-metric-label">Fee per Unità</div>
                <div class="kpi-metric-value-row">
                    <div class="kpi-metric-value fee-negative">€<?= number_format($kpi['fee_per_unit'], 2) ?></div>
                    <?php if ($feeChange != 0): 
                        $isBetter = $feeChange > 0;
                    ?>
                    <div class="kpi-trend <?= $isBetter ? 'trend-up' : 'trend-down' ?>">
                        <i class="fas fa-<?= $isBetter ? 'arrow-up' : 'arrow-down' ?>"></i>
                        <?= number_format(abs($feeChange), 1) ?>%
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="kpi-period-footer">
            <?= number_format($kpi['units']) ?> unità • €<?= number_format($kpi['revenue'], 0) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Stats Categorie Margine (Filtrabili) -->
<div class="kpi-grid" style="grid-template-columns: repeat(3, 1fr);">
    <!-- Primo rigo -->
    <div class="kpi-card info filter-card" data-filter="all" style="background: rgba(59, 130, 246, 0.05); cursor: pointer; transition: all 0.3s ease;">
        <div class="kpi-label">📦 Prodotti</div>
        <div class="kpi-value" style="color: #3b82f6;"><?= count($data) ?></div>
        <div style="font-size: 0.7rem; color: var(--text-muted);">Monitorati</div>
    </div>
    
    <div class="kpi-card success filter-card" data-filter="profittevole" style="background: rgba(16, 185, 129, 0.05); cursor: pointer; transition: all 0.3s ease;">
        <div class="kpi-label">🟢 Ottimo</div>
        <div class="kpi-value" style="color: #10b981;"><?= count($profittevole) ?></div>
        <div style="font-size: 0.7rem; color: var(--text-muted);">Più di 25%</div>
    </div>
    
    <div class="kpi-card success filter-card" data-filter="buono" style="background: rgba(34, 197, 94, 0.05); cursor: pointer; transition: all 0.3s ease;">
        <div class="kpi-label">🟢 Buono</div>
        <div class="kpi-value" style="color: #22c55e;"><?= count($buono) ?></div>
        <div style="font-size: 0.7rem; color: var(--text-muted);">Tra il 25-18%</div>
    </div>
    
    <!-- Secondo rigo -->
    <div class="kpi-card filter-card" data-filter="attenzione" style="background: rgba(251, 191, 36, 0.05); cursor: pointer; transition: all 0.3s ease;">
        <div class="kpi-label">🟡 Scarso</div>
        <div class="kpi-value" style="color: #fbbf24;"><?= count($attenzione) ?></div>
        <div style="font-size: 0.7rem; color: var(--text-muted);">Tra il 18-10%</div>
    </div>
    
    <div class="kpi-card filter-card" data-filter="critico" style="background: rgba(249, 115, 22, 0.05); cursor: pointer; transition: all 0.3s ease;">
        <div class="kpi-label">🟠 Critico</div>
        <div class="kpi-value" style="color: #f97316;"><?= count($critico) ?></div>
        <div style="font-size: 0.7rem; color: var(--text-muted);">Tra il 10-1%</div>
    </div>
    
    <div class="kpi-card danger filter-card" data-filter="perdita" style="background: rgba(239, 68, 68, 0.05); cursor: pointer; transition: all 0.3s ease;">
        <div class="kpi-label">🔴 Perdita</div>
        <div class="kpi-value" style="color: #ef4444;"><?= count($perdita) ?></div>
        <div style="font-size: 0.7rem; color: var(--text-muted);">Meno di 1%</div>
    </div>
</div>
<?php else: ?>
<div class="empty-state">
    <div class="empty-icon">📊</div>
    <div class="empty-title">Nessun dato disponibile</div>
    <div class="empty-text">
        <?php if ($dbError): ?>
            Errore: <?= htmlspecialchars($dbError) ?>
        <?php else: ?>
            Seleziona un periodo o verifica i dati disponibili.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Barra Ricerca Prodotti -->
<div class="section">
    <div style="position: relative;">
        <input type="text" 
               id="product-search" 
               class="search-box" 
               placeholder="🔍 Cerca prodotti per nome o SKU..." 
               autocomplete="off"
               style="width: 100%; padding: 12px 40px 12px 16px; border: 2px solid rgba(56,161,105,0.2); border-radius: 12px; font-size: 14px; transition: all 0.3s ease;">
        <button type="button" 
                id="clear-search" 
                class="clear-btn" 
                style="display: none; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: var(--danger); color: white; border: none; padding: 6px 12px; border-radius: 8px; cursor: pointer; font-size: 12px;">
            ✖
        </button>
    </div>
</div>

<!-- Tutti i Prodotti per Profitto -->
<?php if ($success && count($allProducts) > 0): ?>
<div class="section">
    <div class="section-title"><i class="fas fa-boxes"></i> Analisi Prodotti (<?= count($allProducts) ?>)</div>
    
    <table class="mobile-table">
        <thead>
            <tr>
                <th>Prodotto</th>
                <th style="text-align: right; white-space: nowrap;">Prezzo</th>
                <th style="text-align: right; white-space: nowrap;">Fee</th>
                <th style="text-align: right; white-space: nowrap;">Utile</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($allProducts as $row): ?>
            <?php
            // Calcola valori (come versione desktop)
            $productId = $row['product_id'] ?? 0;
            $units = $row['units_sold'] ?? 1;
            $unitPrice = $row['prezzo_attuale'] ?? 0;
            $unitCost = $row['costo_prodotto'] ?? 0;
            $totalRevenue = $row['revenue'] ?? 0;
            $totalFees = $row['total_fees'] ?? 0;
            $unitFees = $units > 0 ? $totalFees / $units : 0;
            $totalProfit = $row['net_profit'] ?? 0;
            $marginPercent = $row['margin_percentage'] ?? 0;
            
            $marginClass = 'success';
            if ($marginPercent < 10) $marginClass = 'danger';
            elseif ($marginPercent < 18) $marginClass = 'warning';
            
            // Determina categoria margine per filtro
            $marginCategory = 'profittevole';
            if ($marginPercent < 0) $marginCategory = 'perdita';
            elseif ($marginPercent < 10) $marginCategory = 'critico';
            elseif ($marginPercent < 18) $marginCategory = 'attenzione';
            elseif ($marginPercent < 25) $marginCategory = 'buono';
            ?>
            <tr data-product-id="<?= $productId ?>" 
                data-units="<?= $units ?>" 
                data-total-fees="<?= $totalFees ?>" 
                data-total-revenue="<?= $totalRevenue ?>"
                data-margin-category="<?= $marginCategory ?>"
                data-margin-percent="<?= $marginPercent ?>">
                <td style="vertical-align: top; padding: 8px 4px;">
                    <div style="font-weight: 700; margin-bottom: 6px; font-size: 13px;">
                        <?= htmlspecialchars($row['product_name'] ?? 'N/A') ?>
                    </div>
                    <div style="font-size: 11px; color: var(--text-muted);">
                        📦 <span class="display-units"><?= number_format($units, 0) ?></span> vendite<br> 
                        💰 <span class="display-revenue"><?= number_format($totalRevenue, 2) ?></span> €
                    </div>
                </td>
                <td style="text-align: right; vertical-align: top; padding: 8px 4px;">
                    <div style="margin-bottom: 4px;">
                        <input type="number" 
                               class="price-input" 
                               data-product-id="<?= $productId ?>"
                               data-field="prezzo_attuale"
                               data-original="<?= $unitPrice ?>"
                               value="<?= number_format($unitPrice, 2, '.', '') ?>" 
                               step="0.01" 
                               min="0"
                               style="width: 60px; padding: 3px 5px; border: 1px solid rgba(56,161,105,0.3); border-radius: 6px; font-size: 13px; font-weight: 700; text-align: right;">
                    </div>
                    <div style="font-size: 10px;">
                        <input type="number" 
                               class="price-input" 
                               data-product-id="<?= $productId ?>"
                               data-field="costo_prodotto"
                               data-original="<?= $unitCost ?>"
                               value="<?= number_format($unitCost, 2, '.', '') ?>" 
                               step="0.01" 
                               min="0"
                               style="width: 60px; padding: 3px 5px; border: 1px solid rgba(56,161,105,0.3); border-radius: 6px; font-size: 11px; text-align: right;">
                    </div>
                </td>
                <td style="text-align: right; vertical-align: top; padding: 8px 4px;">
                    <div style="font-weight: 700; font-size: 14px; color: #ef4444;" class="display-unit-fees">
                        <?= number_format($unitFees, 2) ?>€
                    </div>
                    <div style="font-size: 10px; color: var(--text-muted);" class="display-total-fees">
                        <?= number_format($totalFees, 2) ?>€
                    </div>
                </td>
                <td style="text-align: right; vertical-align: top; position: relative; padding: 8px 4px 35px 4px;">
                    <div style="font-weight: 700; font-size: 14px;" class="display-total-profit">
                        <span style="color: <?= $totalProfit >= 0 ? 'var(--success)' : 'var(--danger)' ?>;">
                            <?= number_format($totalProfit, 2) ?>€
                        </span>
                    </div>
                    <div style="font-size: 10px; color: var(--text-muted); margin-bottom: 6px;" class="display-margin">
                        <?= number_format($marginPercent, 1) ?>%
                    </div>
                    <button class="save-btn-mobile" 
                            data-product-id="<?= $productId ?>" 
                            title="Salva modifiche"
                            style="position: absolute; bottom: 6px; right: 4px;">
                        💾
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// === RICERCA PRODOTTI + GESTIONE PREZZI + FILTRO CATEGORIE ===
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('product-search');
    const clearBtn = document.getElementById('clear-search');
    const productsTable = document.querySelector('.mobile-table tbody');
    
    let searchTimeout;
    let allProducts = [];
    let originalValues = new Map();
    let unsavedChanges = new Set();
    let activeFilter = 'all'; // Filtro attivo per categoria margine
    
    // === RICERCA PRODOTTI ===
    if (productsTable) {
        allProducts = Array.from(productsTable.querySelectorAll('tr')).map(row => {
            const nameEl = row.querySelector('td:first-child > div:first-child');
            return {
                element: row,
                name: nameEl?.textContent?.toLowerCase() || '',
                category: row.dataset.marginCategory || 'all'
            };
        });
    }
    
    // === FILTRO CATEGORIE MARGINE ===
    const filterCards = document.querySelectorAll('.filter-card');
    filterCards.forEach(card => {
        card.addEventListener('click', function() {
            const filterValue = this.dataset.filter;
            
            // Toggle filtro: se clicco sulla stessa categoria, reset a "all"
            if (activeFilter === filterValue && filterValue !== 'all') {
                activeFilter = 'all';
                removeActiveFilters();
                applyFilters();
            } else {
                activeFilter = filterValue;
                removeActiveFilters();
                this.classList.add('filter-active');
                applyFilters();
            }
        });
        
        // Feedback visivo touch
        card.addEventListener('touchstart', function() {
            this.style.transform = 'scale(0.95)';
        });
        
        card.addEventListener('touchend', function() {
            this.style.transform = 'scale(1)';
        });
    });
    
    function removeActiveFilters() {
        filterCards.forEach(card => card.classList.remove('filter-active'));
    }
    
    function applyFilters() {
        const searchQuery = searchInput?.value?.trim().toLowerCase() || '';
        
        allProducts.forEach(product => {
            let showProduct = true;
            
            // Filtro categoria
            if (activeFilter !== 'all' && product.category !== activeFilter) {
                showProduct = false;
            }
            
            // Filtro ricerca
            if (searchQuery.length >= 2 && !product.name.includes(searchQuery)) {
                showProduct = false;
            }
            
            product.element.style.display = showProduct ? '' : 'none';
        });
        
        // Aggiorna contatore visibile (opzionale)
        updateVisibleCount();
    }
    
    function updateVisibleCount() {
        const visibleCount = allProducts.filter(p => p.element.style.display !== 'none').length;
        const totalCount = allProducts.length;
        
        // Aggiorna titolo sezione (se esiste)
        const sectionTitle = document.querySelector('.section-title');
        if (sectionTitle) {
            const baseText = sectionTitle.textContent.split('(')[0].trim();
            sectionTitle.textContent = `${baseText} (${visibleCount}/${totalCount})`;
        }
    }
    
    searchInput?.addEventListener('focus', function() {
        this.style.borderColor = '#38a169';
        this.style.boxShadow = '0 0 0 3px rgba(56,161,105,0.2)';
    });
    
    searchInput?.addEventListener('blur', function() {
        this.style.borderColor = 'rgba(56,161,105,0.2)';
        this.style.boxShadow = 'none';
    });
    
    searchInput?.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            applyFilters();
            hideClearButton();
            return;
        }
        
        searchTimeout = setTimeout(() => applyFilters(), 300);
        showClearButton();
    });
    
    clearBtn?.addEventListener('click', function() {
        searchInput.value = '';
        applyFilters();
        hideClearButton();
        searchInput.focus();
    });
    
    function showClearButton() {
        if (clearBtn) clearBtn.style.display = 'block';
    }
    
    function hideClearButton() {
        if (clearBtn) clearBtn.style.display = 'none';
    }
    
    // Inizializza contatore all'avvio
    updateVisibleCount();
    
    // === GESTIONE MODIFICA PREZZI ===
    // Salva valori originali
    document.querySelectorAll('.price-input').forEach(input => {
        const key = `${input.dataset.productId}_${input.dataset.field}`;
        originalValues.set(key, parseFloat(input.dataset.original) || 0);
    });
    
    // Event listener per input prezzi
    document.querySelectorAll('.price-input').forEach(input => {
        input.addEventListener('input', function() {
            handlePriceChange(this);
        });
        
        input.addEventListener('focus', function() {
            this.style.borderColor = '#38a169';
            this.style.boxShadow = '0 0 0 2px rgba(56,161,105,0.2)';
        });
        
        input.addEventListener('blur', function() {
            this.style.borderColor = 'rgba(56,161,105,0.3)';
            this.style.boxShadow = 'none';
        });
    });
    
    function handlePriceChange(input) {
        const productId = input.dataset.productId;
        const field = input.dataset.field;
        const key = `${productId}_${field}`;
        const currentValue = parseFloat(input.value) || 0;
        const originalValue = originalValues.get(key) || 0;
        
        const saveBtn = document.querySelector(`.save-btn-mobile[data-product-id="${productId}"]`);
        
        if (!saveBtn) {
            console.error('Bottone salva non trovato per prodotto:', productId);
            return;
        }
        
        if (Math.abs(currentValue - originalValue) > 0.001) {
            unsavedChanges.add(key);
            saveBtn.classList.add('active');
        } else {
            unsavedChanges.delete(key);
            // Disattiva bottone solo se non ci sono altre modifiche per questo prodotto
            const otherField = field === 'prezzo_attuale' ? 'costo_prodotto' : 'prezzo_attuale';
            const otherKey = `${productId}_${otherField}`;
            if (!unsavedChanges.has(otherKey)) {
                saveBtn.classList.remove('active');
            }
        }
        
        // Ricalcola margini
        recalculateRow(productId);
    }
    
    function recalculateRow(productId) {
        const row = document.querySelector(`tr[data-product-id="${productId}"]`);
        if (!row) return;
        
        // Dati storici dal DB (immutabili)
        const units = parseFloat(row.dataset.units) || 1;
        const totalFeesFromDB = parseFloat(row.dataset.totalFees) || 0; // Fee STORICHE (già negative)
        const originalRevenue = parseFloat(row.dataset.totalRevenue) || 0;
        
        // Valori ATTUALI dagli input
        const prezzoInput = row.querySelector('[data-field="prezzo_attuale"]');
        const costoInput = row.querySelector('[data-field="costo_prodotto"]');
        
        const currentPrice = parseFloat(prezzoInput?.value) || 0;
        const currentCost = parseFloat(costoInput?.value) || 0;
        
        // CALCOLO ESATTO COME DESKTOP (linee 2580-2661 margins_overview.php)
        const totalRevenue = currentPrice * units;
        const totalCostMaterial = currentCost * units;
        
        // Fee unitarie storiche (invarianti rispetto al prezzo, tranne custom fee)
        const unitFeesFromDB = units > 0 ? totalFeesFromDB / units : 0;
        const totalFees = unitFeesFromDB * units;
        
        // FORMULA CHIAVE: revenue - cost + fees (fee sono negative, quindi + fees le sottrae)
        const totalProfit = totalRevenue - totalCostMaterial + totalFees;
        const unitProfit = units > 0 ? totalProfit / units : 0;
        
        // Margine % = (profit / revenue) * 100
        const marginPercent = totalRevenue > 0 ? (totalProfit / totalRevenue) * 100 : 0;
        
        // Aggiorna UI
        const profitEl = row.querySelector('.display-total-profit span');
        const marginEl = row.querySelector('.display-margin');
        
        if (profitEl) {
            profitEl.textContent = totalProfit.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '€';
            profitEl.style.color = totalProfit >= 0 ? 'var(--success)' : 'var(--danger)';
        }
        
        if (marginEl) {
            marginEl.textContent = marginPercent.toFixed(1) + '%';
        }
        
        // Aggiorna anche il revenue display (opzionale, per coerenza con desktop)
        const revenueEl = row.querySelector('.display-revenue');
        if (revenueEl) {
            revenueEl.textContent = totalRevenue.toLocaleString('it-IT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }
    
    // Event listener per bottoni salva
    document.querySelectorAll('.save-btn-mobile').forEach(btn => {
        btn.addEventListener('click', function() {
            saveProduct(this.dataset.productId);
        });
    });
    
    async function saveProduct(productId) {
        const row = document.querySelector(`tr[data-product-id="${productId}"]`);
        if (!row) {
            showToast('Errore: prodotto non trovato', 'error');
            return;
        }
        
        const saveBtn = row.querySelector('.save-btn-mobile');
        
        // Non fare nulla se il bottone non è attivo
        if (!saveBtn.classList.contains('active')) {
            return;
        }
        
        const prezzoInput = row.querySelector('[data-field="prezzo_attuale"]');
        const costoInput = row.querySelector('[data-field="costo_prodotto"]');
        
        const data = {
            product_id: parseInt(productId),
            prezzo_attuale: parseFloat(prezzoInput.value) || 0,
            costo_prodotto: parseFloat(costoInput.value) || 0
        };
        
        try {
            saveBtn.disabled = true;
            saveBtn.textContent = '⏳';
            
            const response = await fetch('../margynomic/margini/update_product_pricing.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Aggiorna valori originali
                originalValues.set(`${productId}_prezzo_attuale`, data.prezzo_attuale);
                originalValues.set(`${productId}_costo_prodotto`, data.costo_prodotto);
                prezzoInput.dataset.original = data.prezzo_attuale;
                costoInput.dataset.original = data.costo_prodotto;
                
                // Rimuovi da unsaved changes
                unsavedChanges.delete(`${productId}_prezzo_attuale`);
                unsavedChanges.delete(`${productId}_costo_prodotto`);
                
                // Disattiva bottone
                saveBtn.classList.remove('active');
                saveBtn.disabled = false;
                saveBtn.textContent = '💾';
                
                showToast('✅ Salvato con successo!', 'success');
            } else {
                saveBtn.disabled = false;
                saveBtn.textContent = '💾';
                showToast('❌ ' + (result.error || 'Errore sconosciuto'), 'error');
            }
        } catch (error) {
            saveBtn.disabled = false;
            saveBtn.textContent = '💾';
            showToast('❌ Errore di rete', 'error');
        }
    }
    
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.textContent = message;
        toast.style = `position:fixed;top:70px;left:50%;transform:translateX(-50%);background:${type === 'success' ? 'var(--success)' : 'var(--danger)'};color:white;padding:12px 24px;border-radius:8px;z-index:9999;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.3);`;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            if (document.body.contains(toast)) {
                document.body.removeChild(toast);
            }
        }, 3000);
    }
    
    // Warning per modifiche non salvate
    window.addEventListener('beforeunload', function(e) {
        if (unsavedChanges.size > 0) {
            e.preventDefault();
            e.returnValue = 'Ci sono modifiche non salvate. Sei sicuro?';
        }
    });
});
</script>

</main>

<?php include __DIR__ . '/_partials/mobile_tabbar.php'; ?>

</body>
</html>

