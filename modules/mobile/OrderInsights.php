<?php
/**
 * Mobile OrderInsights - Dashboard Completa Ordini
 * Feature parity con desktop: 7 KPI, Filtri, Sezioni, Breakdown, Charts
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
    header('Location: /modules/orderinsights/overview.php');
    exit;
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
    <meta name="theme-color" content="#667eea">
    <meta name="apple-mobile-web-app-title" content="SkuAlizer Suite">
    <meta name="format-detection" content="telephone=no">
    <title>OrderInsights - Skualizer Mobile</title>
    
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
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #fbbf24 !important; }
    </style>
</head>
<body>
    <!-- Sprite Icons -->
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    
    <!-- Hamburger Overlay Menu -->
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #fbbf24; background: #fefce8;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-search" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-user" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #fbbf24; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #fbbf24; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Main Content Start -->
    <main class="mobile-content" style="padding-top: 0;">

<style>
/* OrderInsights Specific Styles */
body {
    overflow-x: hidden;
    padding-top: 0 !important;
}

.mobile-content {
    padding-top: 0 !important;
}

.hero-welcome {
    background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
    color: white;
    padding: 0;
    margin: 0 0 16px 0;
    border-radius: 0 0 20px 20px;
    text-align: left;
    box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
    /* Safe area per notch iPhone */
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
    border-left: 3px solid rgba(251, 191, 36, 0.8);
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

/* === KPI CARDS (IDENTICO TRIDSCANNER) === */
.kpi-grid-7 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 16px;
}

.kpi-compact {
    background: white;
    border-radius: 10px;
    padding: 12px;
    box-shadow: var(--shadow-sm);
}

.kpi-compact-label {
    font-size: 11px;
    color: var(--text-muted);
    margin-bottom: 4px;
    font-weight: 600;
}

.kpi-compact-value {
    font-size: 20px;
    font-weight: 700;
}

.kpi-compact.primary { border-left: 3px solid #667eea; }
.kpi-compact.success { border-left: 3px solid #10b981; }
.kpi-compact.warning { border-left: 3px solid #f59e0b; }
.kpi-compact.danger { border-left: 3px solid #ef4444; }
.kpi-compact.info { border-left: 3px solid #3b82f6; }
.kpi-compact.cyan { border-left: 3px solid #06b6d4; }
.kpi-compact.pink { border-left: 3px solid #ec4899; }
.kpi-compact.purple { border-left: 3px solid #8b5cf6; }

/* Sezioni Always Visible (come desktop) */
.section-box {
    background: white;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    background: var(--bg-light);
    border-bottom: 2px solid var(--border-light);
}

.section-number {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: var(--theme-primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}

.section-title {
    flex: 1;
    font-size: 15px;
    font-weight: 700;
    color: var(--text-dark);
}

.section-body {
    padding: 16px;
}

.highlight-card {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 14px;
    text-align: center;
}

.highlight-value {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 6px;
}

.highlight-label {
    font-size: 13px;
    opacity: 0.9;
    margin-bottom: 8px;
}

.highlight-context {
    font-size: 11px;
    opacity: 0.8;
}

.mini-kpi-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}

.mini-kpi {
    background: var(--bg-light);
    border-radius: 8px;
    padding: 10px;
    text-align: center;
}

.mini-kpi-value {
    font-size: 20px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 4px;
}

.mini-kpi-label {
    font-size: 11px;
    color: var(--text-muted);
    font-weight: 600;
}

.breakdown-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: var(--bg-light);
    border-radius: 6px;
    margin-bottom: 6px;
}

.breakdown-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
}

.breakdown-value {
    font-size: 14px;
    font-weight: 700;
}

.days-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}

.day-card {
    background: white;
    border-radius: 10px;
    padding: 12px;
    box-shadow: var(--shadow-sm);
    cursor: pointer;
    border-left: 3px solid var(--theme-primary);
}

.day-date {
    font-weight: 700;
    font-size: 12px;
    color: var(--text-dark);
    margin-bottom: 6px;
}

.day-value {
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
}

.day-value.positive {
    color: var(--success);
}

.day-value.negative {
    color: var(--danger);
}

.drawer-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 9998;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s;
}

.drawer-overlay.active {
    opacity: 1;
    visibility: visible;
}

.drawer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: white;
    border-radius: 20px 20px 0 0;
    max-height: 80vh;
    transform: translateY(100%);
    transition: transform 0.3s;
    z-index: 9999;
    overflow-y: auto;
}

.drawer.active {
    transform: translateY(0);
}

.drawer-handle {
    width: 40px;
    height: 4px;
    background: var(--border-light);
    border-radius: 2px;
    margin: 12px auto;
}

.drawer-header {
    padding: 0 16px 12px;
    border-bottom: 1px solid var(--border-light);
}

.drawer-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 4px;
}

.drawer-subtitle {
    font-size: 13px;
    color: var(--text-muted);
}

.drawer-content {
    padding: 16px;
}

.loading-spinner {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid var(--border-light);
    border-top-color: var(--theme-primary);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.empty-icon {
    font-size: 48px;
    margin-bottom: 12px;
    opacity: 0.5;
}

.load-more-btn {
    width: 100%;
    padding: 12px;
    background: var(--bg-light);
    border: 2px dashed var(--border-light);
    border-radius: 8px;
    font-weight: 600;
    color: var(--text-muted);
    margin-top: 12px;
}

.chart-container {
    margin: 16px 0;
    padding: 12px;
    background: var(--bg-light);
    border-radius: 8px;
}

.chart-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 10px;
}
</style>

<!-- Hero Welcome con Header Integrato -->
<div class="hero-welcome">
    <!-- Header Integrato nella Hero -->
    <div class="hero-header">
            <div class="hero-logo">
                <div class="hero-title"><i class="fas fa-microscope"></i> OrderInsights</div>
                <div class="hero-subtitle">ANALIZZA LA DISTRIBUZIONE DEL FATTURATO!</div>
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
            <div class="info-box-title">🔄 Lifecycle Ordine</div>
            <div class="info-box-text">Segui ogni transazione dal click cliente al bonifico sul tuo conto.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">💎 Quality Score</div>
            <div class="info-box-text">Valuta la "qualità" delle vendite: margine, retention, tasso di reso.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">📈 Grow Performance</div>
            <div class="info-box-text">Confronta performance delle transazioni, individua leve di miglioramento.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">📅 Day Insights</div>
            <div class="info-box-text">Analisi giornaliera approfondita, un colpo d'occhio sul tuo business.</div>
        </div>
    </div>
</div>

<!-- 7 KPI Cards (Identico TridScanner) -->
<div class="kpi-grid-7" id="kpi-container">
    <div class="kpi-compact success">
        <div class="kpi-compact-label">💰 Fatturato</div>
        <div class="kpi-compact-value" id="kpi-fatturato">€ 0</div>
    </div>
    
    <div class="kpi-compact warning">
        <div class="kpi-compact-label">💸 Commissioni</div>
        <div class="kpi-compact-value" id="kpi-commissioni">€ 0</div>
    </div>
    
    <div class="kpi-compact danger">
        <div class="kpi-compact-label">🏢 Operativi</div>
        <div class="kpi-compact-value" id="kpi-operativi">€ 0</div>
    </div>
    
    <div class="kpi-compact purple">
        <div class="kpi-compact-label">⚠️ Perdite</div>
        <div class="kpi-compact-value" id="kpi-perdite">€ 0</div>
    </div>
    
    <div class="kpi-compact info">
        <div class="kpi-compact-label">🛒 Ordini</div>
        <div class="kpi-compact-value" id="kpi-ordini">0</div>
    </div>
    
    <div class="kpi-compact cyan">
        <div class="kpi-compact-label">📦 Vendute</div>
        <div class="kpi-compact-value" id="kpi-vendute">0</div>
    </div>
    
    <div class="kpi-compact pink">
        <div class="kpi-compact-label">🔴 Rimborsate</div>
        <div class="kpi-compact-value" id="kpi-rimborsate">0</div>
    </div>
</div>

<div id="loading" class="loading-spinner" style="display: none;">
    <div class="spinner"></div>
    <p>Caricamento...</p>
</div>

<!-- Sezioni Always Visible (come desktop) -->
<div id="sections-container">
    <!-- Sezione 1: Quanto hai guadagnato -->
    <div class="section-box" id="section-1">
        <div class="section-header">
            <span class="section-number">1</span>
            <span class="section-title">💰 Quanto hai guadagnato?</span>
        </div>
        <div class="section-body" id="section-1-body">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
    </div>
    
    <!-- Sezione 2: Quanto è costato Amazon -->
    <div class="section-box" id="section-2">
        <div class="section-header">
            <span class="section-number">2</span>
            <span class="section-title">💸 Quanto è costato Amazon?</span>
        </div>
        <div class="section-body" id="section-2-body">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
    </div>
    
    <!-- Sezione 3: Altri costi operativi -->
    <div class="section-box" id="section-3">
        <div class="section-header">
            <span class="section-number">3</span>
            <span class="section-title">🏢 Altri costi operativi</span>
        </div>
        <div class="section-body" id="section-3-body">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
    </div>
    
    <!-- Sezione 4: Perdite e Rimborsi/Danni -->
    <div class="section-box" id="section-4">
        <div class="section-header">
            <span class="section-number">4</span>
            <span class="section-title">⚠️ Perdite e Rimborsi/Danni</span>
        </div>
        <div class="section-body" id="section-4-body">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
    </div>
    
    <!-- Sezione 5: Il tuo risultato finale -->
    <div class="section-box" id="section-5">
        <div class="section-header">
            <span class="section-number">✓</span>
            <span class="section-title">💎 Il tuo risultato finale</span>
        </div>
        <div class="section-body" id="section-5-body">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
    </div>
    
    <!-- Sezione 6: Andamento giornaliero -->
    <div class="section-box" id="section-6">
        <div class="section-header">
            <span class="section-number">📅</span>
            <span class="section-title">Andamento giornaliero</span>
        </div>
        <div class="section-desc" style="padding: 0 16px 12px; font-size: 12px; color: var(--text-muted);">
            Performance day-by-day del periodo (ultimi 8 giorni)
        </div>
        <div class="section-body" id="section-6-body">
            <div class="loading-spinner"><div class="spinner"></div></div>
        </div>
    </div>
</div>


<!-- Drawer Dettaglio Giorno -->
<div class="drawer-overlay" id="drawer-overlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-handle"></div>
    <div class="drawer-header">
        <div class="drawer-title" id="drawer-title">Dettaglio Giorno</div>
        <div class="drawer-subtitle" id="drawer-subtitle"></div>
    </div>
    <div class="drawer-content" id="drawer-content">
        <div class="loading-spinner"><div class="spinner"></div></div>
    </div>
</div>

<script>
let dashboardData = null;
let currentData = null; // Per modal categorie
let allDaysData = []; // Tutti i giorni disponibili
let daysOffset = 0;
const daysPerPage = 8; // Giorni da caricare per volta (8 per mobile)

// Init - Carica TUTTI i dati (come desktop)
document.addEventListener('DOMContentLoaded', () => {
    loadData();
    
    // Setup hamburger menu (hero version)
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

// Load Data (senza filtri = tutti i dati)
async function loadData() {
    document.getElementById('loading').style.display = 'block';
    daysOffset = 0;
    
    try {
        // Carica tutto (nessun parametro month/start/end)
        const params = new URLSearchParams({
            action: 'month_summary',
            include_reserve: '0'
        });
        
        const res = await fetch(`/modules/orderinsights/OverviewController.php?${params}`);
        const json = await res.json();
        
        if (!json.success) {
            console.error('API Error:', json.error);
            alert('Errore caricamento dati: ' + (json.error?.message || 'Sconosciuto'));
            return;
        }
        
        dashboardData = json.data;
        currentData = json.data; // Salva per modal categorie
        renderKPI(dashboardData);
        renderSections(dashboardData);
        
    } catch (error) {
        console.error('Load error:', error);
        alert('Errore di rete: ' + error.message);
    } finally {
        document.getElementById('loading').style.display = 'none';
    }
}

// Render 7 KPI (usa data.kpi come desktop)
function renderKPI(data) {
    const kpi = data.kpi || {};
    const fc = data.fee_components || {};
    const categorie = data.categorie || [];
    
    // Ricalcola commissioni totali da fee_components (item_related + order + shipment)
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    // Usa categoria "Perdite e Rimborsi/Danni" e "Costi Operativi/Abbonamenti"
    const catPerdite = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    const perditeImporto = catPerdite ? catPerdite.importo_eur : 0;
    
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const operativiImporto = catOperativi ? catOperativi.importo_eur : 0;
    
    document.getElementById('kpi-fatturato').textContent = formatCurrency(kpi.incassato_vendite || 0);
    document.getElementById('kpi-commissioni').textContent = formatCurrency(commissioniTotali);
    document.getElementById('kpi-operativi').textContent = formatCurrency(Math.abs(operativiImporto));
    document.getElementById('kpi-perdite').textContent = formatCurrency(Math.abs(perditeImporto));
    document.getElementById('kpi-ordini').textContent = formatNumber(kpi.ordini || 0);
    document.getElementById('kpi-vendute').textContent = formatNumber(kpi.unita_vendute || 0);
    document.getElementById('kpi-rimborsate').textContent = formatNumber(kpi.unita_rimborsate || 0);
}

// Render 6 Sections (come desktop)
function renderSections(data) {
    const kpi = data.kpi || {};
    const fc = data.fee_components || {};
    const categorie = data.categorie || [];
    
    // Ricalcola commissioni da fee_components
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    // Sezione 1: Quanto hai guadagnato (Banner ARANCIONE)
    const incassoPercent = 100; // Sempre 100% del totale
    let section1 = `
        <div class="highlight-card" style="background: linear-gradient(135deg, #f97316, #fb923c);">
            <div class="highlight-value">${formatCurrency(kpi.incassato_vendite || 0)}</div>
            <div class="highlight-label">Incassato Vendite</div>
            <div class="highlight-context">
                Da <strong>${formatNumber(kpi.ordini || 0)} ordini</strong> con <strong>${formatNumber(kpi.unita_vendute || 0)} unità</strong> spedite in totale
            </div>
        </div>
        <div class="mini-kpi-grid">
            <div class="mini-kpi">
                <div class="mini-kpi-value">${formatNumber(kpi.ordini || 0)}</div>
                <div class="mini-kpi-label">Ordini</div>
            </div>
            <div class="mini-kpi">
                <div class="mini-kpi-value">${formatNumber(kpi.transazioni || 0)}</div>
                <div class="mini-kpi-label">Transazioni</div>
            </div>
            <div class="mini-kpi">
                <div class="mini-kpi-value">${formatNumber(kpi.unita_vendute || 0)}</div>
                <div class="mini-kpi-label">Unità Vendute</div>
            </div>
            <div class="mini-kpi">
                <div class="mini-kpi-value">${formatNumber(kpi.unita_rimborsate || 0)}</div>
                <div class="mini-kpi-label">Unità Rimborsate</div>
            </div>
        </div>
    `;
    section1 += renderFeeComponentsTable(fc, kpi.incassato_vendite);
    document.getElementById('section-1-body').innerHTML = section1;
    
    // Sezione 2: Quanto è costato Amazon (Banner ROSSO)
    const commissioniPercent = kpi.incassato_vendite ? ((commissioniTotali / kpi.incassato_vendite) * 100).toFixed(1) : 0;
    const section2 = `
        <div class="highlight-card" style="background: linear-gradient(135deg, #dc2626, #ef4444);">
            <div class="highlight-value">${formatCurrency(commissioniTotali)}</div>
            <div class="highlight-label">Commissioni Totali</div>
            <div class="highlight-context">
                Amazon ha trattenuto <strong>${commissioniPercent}%</strong> del tuo fatturato lordo
            </div>
        </div>
        ${renderCommissioniBreakdown(fc, kpi.incassato_vendite)}
    `;
    document.getElementById('section-2-body').innerHTML = section2;
    
    // Sezione 3: Altri costi operativi (Banner GIALLO)
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const operativiImporto = catOperativi ? catOperativi.importo_eur : 0;
    const operativiPercent = kpi.incassato_vendite ? ((Math.abs(operativiImporto) / kpi.incassato_vendite) * 100).toFixed(1) : 0;
    const operativiDisplay = operativiImporto < 0 ? '-' + formatCurrency(Math.abs(operativiImporto)) : formatCurrency(operativiImporto);
    const section3 = `
        <div class="highlight-card" style="background: linear-gradient(135deg, #f59e0b, #fbbf24);">
            <div class="highlight-value">${operativiDisplay}</div>
            <div class="highlight-label">Totale Costi Operativi</div>
            <div class="highlight-context">
                Abbonamenti, storage e altri costi accessori (<strong>${operativiPercent}%</strong>)
            </div>
        </div>
        ${renderOperativiBreakdown(data, kpi.incassato_vendite)}
    `;
    document.getElementById('section-3-body').innerHTML = section3;
    
    // Sezione 4: Perdite e Rimborsi/Danni (Banner BLU)
    const catPerdite = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    const perditeImporto = catPerdite ? catPerdite.importo_eur : 0;
    const perditePercent = kpi.incassato_vendite ? ((Math.abs(perditeImporto) / kpi.incassato_vendite) * 100).toFixed(1) : 0;
    const perditeDisplay = perditeImporto < 0 
        ? '-' + formatCurrency(Math.abs(perditeImporto)) 
        : (perditeImporto > 0 ? '+' + formatCurrency(perditeImporto) : formatCurrency(0));
    const section4 = `
        <div class="highlight-card" style="background: linear-gradient(135deg, #3b82f6, #60a5fa);">
            <div class="highlight-value">${perditeDisplay}</div>
            <div class="highlight-label">Totale Perdite/Danni</div>
            <div class="highlight-context">
                Eventi di magazzino e rimborsi da richiedere (<strong>${perditePercent}%</strong>)
            </div>
        </div>
        ${renderPerditeBreakdown(data, kpi.incassato_vendite)}
    `;
    document.getElementById('section-4-body').innerHTML = section4;
    
    // Sezione 5: Il tuo risultato finale
    // Usa la stessa logica del desktop
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    
    // NETTO OPERATIVO = Incassato - Commissioni + Operativi + Perdite/Danni
    // Operativi è negativo, Perdite può essere positivo (rimborsi) o negativo
    const nettoOperativo = incassatoDaiClienti - commissioniTotali + operativiImporto + perditeImporto;
    const margine = incassatoDaiClienti > 0 ? ((nettoOperativo / incassatoDaiClienti) * 100).toFixed(1) : 0;
    
    let section5 = `
        <div class="highlight-card" style="background: linear-gradient(135deg, ${nettoOperativo >= 0 ? '#10b981, #059669' : '#ef4444, #dc2626'});">
            <div class="highlight-value">${formatCurrency(nettoOperativo)}</div>
            <div class="highlight-label">Netto Operativo</div>
            <div class="highlight-context">
                Margine: <strong>${margine}%</strong> sul fatturato
            </div>
        </div>
    `;
    
    // Messaggio costo materia prima
    const costoMateriaPrima = data.kpi.costo_materia_prima || 0;
    if (costoMateriaPrima > 0) {
        // Usa il netto operativo VISUALIZZATO (calcolato localmente), non quello dal backend
        const utileNettoFinale = nettoOperativo - costoMateriaPrima;
        const warningHtml = `
            <div style="background: #fff3cd; border-left: 4px solid #ff9800; padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                <strong>⚠️ Importante:</strong> Da questo Netto Operativo devi ancora sottrarre il <strong>costo della materia prima</strong> che ammonta a <strong style="color: #dc2626;">${formatCurrency(costoMateriaPrima)}</strong>.<br>
                <small style="display: block; margin-top: 0.5rem;">Il tuo <strong>utile netto finale</strong> sarà quindi: <strong style="color: ${utileNettoFinale >= 0 ? '#16a34a' : '#dc2626'};">${formatCurrency(utileNettoFinale)}</strong></small>
            </div>
        `;
        section5 += warningHtml;
    }
    
    document.getElementById('section-5-body').innerHTML = section5;
    
    // Sezione 6: Andamento giornaliero (ultimi 8 giorni per mobile)
    loadLast16Days();
}

// Render Fee Components Table (sempre visibile, come desktop)
function renderFeeComponentsTable(fc, baseRicavi) {
    if (!fc || !fc.price) return '';
    
    const fmtP = (v) => baseRicavi ? ((v / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #16a34a; font-size: 16px; margin: 16px 0 12px; border-left: 4px solid #16a34a; padding-left: 10px;">
            💰 Ricavi Vendite
        </h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 16px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Componente</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Importo €</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">% su Ricavi</th>
                </tr>
            </thead>
            <tbody>
                <tr style="background: #e8f5e8;">
                    <td style="border: 1px solid #ddd; padding: 6px; font-weight: bold; font-size: 11px;">💰 FATTURATO</td>
                    <td style="border: 1px solid #ddd; padding: 6px;"></td>
                    <td style="border: 1px solid #ddd; padding: 6px;"></td>
                </tr>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 6px; padding-left: 16px;"><strong>Principal</strong></td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(fc.price.principal || 0)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(fc.price.principal || 0)}</td>
                </tr>
                <tr>
                    <td style="border: 1px solid #ddd; padding: 6px; padding-left: 16px;"><strong>Tax</strong></td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(fc.price.tax || 0)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(fc.price.tax || 0)}</td>
                </tr>`;
    
    // Altri componenti price
    if (fc.price.by_type) {
        for (const [k, v] of Object.entries(fc.price.by_type)) {
            html += `<tr>
                <td style="border: 1px solid #ddd; padding: 6px; padding-left: 16px;">${escapeHtml(k)}</td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(v)}</td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(v)}</td>
            </tr>`;
        }
    }
    
    html += `<tr style="background: #d4edda; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px; font-size: 11px;"><strong>TOTALE FATTURATO</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${formatCurrency(fc.price.total || 0)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${fmtP(fc.price.total || 0)}</strong></td>
    </tr>`;
    
    // REFUND
    html += `<tr style="background: #ffe6e6;">
        <td style="border: 1px solid #ddd; padding: 6px; font-weight: bold; font-size: 11px;">🔄 REFUND</td>
        <td style="border: 1px solid #ddd; padding: 6px;"></td>
        <td style="border: 1px solid #ddd; padding: 6px;"></td>
    </tr>`;
    
    // Refund Principal e Tax
    if (fc.refund?.principal && Math.abs(fc.refund.principal) > 0) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 6px; padding-left: 16px;"><strong>Principal</strong></td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(fc.refund.principal)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(fc.refund.principal)}</td>
        </tr>`;
    }
    
    if (fc.refund?.tax && Math.abs(fc.refund.tax) > 0) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 6px; padding-left: 16px;"><strong>Tax</strong></td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(fc.refund.tax)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(fc.refund.tax)}</td>
        </tr>`;
    }
    
    // Altri tipi refund
    for (const [k, v] of Object.entries(fc.refund?.by_type || {})) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 6px; padding-left: 16px;">${escapeHtml(k)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(v)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(v)}</td>
        </tr>`;
    }
    
    // TOTALE REFUND
    html += `<tr style="background: #f8d7da; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px; font-size: 11px;"><strong>TOTALE REFUND</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${formatCurrency(fc.refund?.total || 0)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${fmtP(fc.refund?.total || 0)}</strong></td>
    </tr>`;
    
    // Riga vuota
    html += `<tr><td colspan="3" style="height: 10px;"></td></tr>`;
    
    // INCASSATO DAI CLIENTI
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    html += `<tr style="background: #4caf50; color: white; font-weight: bold;">
        <td style="border: 2px solid #000; padding: 10px; font-size: 12px;"><strong>💵 INCASSATO DAI CLIENTI</strong></td>
        <td style="border: 2px solid #000; padding: 10px; text-align: right; font-size: 12px;"><strong>${formatCurrency(incassatoDaiClienti)}</strong></td>
        <td style="border: 2px solid #000; padding: 10px; text-align: right; font-size: 12px;"><strong>${fmtP(incassatoDaiClienti)}</strong></td>
    </tr>`;
    
    html += `</tbody></table>`;
    
    // Messaggio esplicativo
    html += `<div style="background: #e3f2fd; border-left: 4px solid #2196f3; padding: 10px; border-radius: 6px; font-size: 11px; margin-bottom: 16px;">
        <strong>ℹ️ Come leggere questa tabella:</strong><br>
        Questa sezione mostra il <strong>fatturato lordo</strong> (vendite) a cui vengono sottratti i <strong>rimborsi</strong>. 
        Il risultato (${formatCurrency(incassatoDaiClienti)}) rappresenta quanto hai effettivamente incassato dai clienti prima di pagare Amazon.<br><br>
        Nella prossima sezione vedremo quanto Amazon ha trattenuto in commissioni e fee.
    </div>`;
    
    return html;
}

// Render Commissioni Breakdown (Tab 2 - tabella 3 colonne)
function renderCommissioniBreakdown(fc, baseRicavi) {
    if (!fc) return '';
    
    const fmtP = (v) => baseRicavi ? ((Math.abs(v) / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #dc2626; font-size: 16px; margin: 16px 0 12px; border-left: 4px solid #dc2626; padding-left: 10px;">
            💸 Commissioni di Vendita/Logistica
        </h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 16px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Componente</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Importo €</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">% su Ricavi</th>
                </tr>
            </thead>
            <tbody>`;
    
    // Item Related Fees
    if (fc.item_related_fees && fc.item_related_fees.by_type) {
        for (const [k, v] of Object.entries(fc.item_related_fees.by_type)) {
            if (Math.abs(v) > 0.01) {
                html += `<tr>
                    <td style="border: 1px solid #ddd; padding: 6px;">${escapeHtml(k)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(v)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(v)}</td>
                </tr>`;
            }
        }
    }
    
    // Order Fees
    if (fc.order_fees && fc.order_fees.by_type) {
        for (const [k, v] of Object.entries(fc.order_fees.by_type)) {
            if (Math.abs(v) > 0.01) {
                html += `<tr>
                    <td style="border: 1px solid #ddd; padding: 6px;">${escapeHtml(k)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(v)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(v)}</td>
                </tr>`;
            }
        }
    }
    
    // Shipment Fees
    if (fc.shipment_fees && fc.shipment_fees.by_type) {
        for (const [k, v] of Object.entries(fc.shipment_fees.by_type)) {
            if (Math.abs(v) > 0.01) {
                html += `<tr>
                    <td style="border: 1px solid #ddd; padding: 6px;">${escapeHtml(k)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(v)}</td>
                    <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${fmtP(v)}</td>
                </tr>`;
            }
        }
    }
    
    html += '</tbody></table>';
    
    // Messaggio esplicativo
    const incassatoDaiClienti = baseRicavi + (fc.refund?.total || 0);
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    
    html += `<div style="background: #fff3cd; border-left: 4px solid #ff9800; padding: 10px; border-radius: 6px; font-size: 11px; margin: 16px 0;">
        <strong>💡 Cosa rappresenta questa tabella:</strong><br>
        Queste sono le <strong>commissioni e fee</strong> che Amazon trattiene direttamente dal tuo account settlement. 
        Include costi di vendita (Commission), logistica FBA, servizi digitali e altre fee operative.<br><br>
        Amazon detrae automaticamente questi importi prima di accreditarti il saldo.
    </div>`;
    
    // Calcolo Margine Lordo
    const margineLordo = incassatoDaiClienti - commissioniTotali;
    const margineLordoPercent = baseRicavi ? ((margineLordo / baseRicavi) * 100).toFixed(2) : 0;
    
    html += `<table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 16px;">
        <tbody>
            <tr style="background: #e3f2fd;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>💵 Incassato dai Clienti</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold;">${formatCurrency(incassatoDaiClienti)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #ffebee;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>➖ Commissioni Amazon</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: #e53e3e;">${formatCurrency(-commissioniTotali)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #4caf50; color: white; font-weight: bold;">
                <td style="border: 2px solid #000; padding: 12px;"><strong>💰 MARGINE LORDO</strong></td>
                <td style="border: 2px solid #000; padding: 12px; text-align: right;"><strong>${formatCurrency(margineLordo)}</strong></td>
                <td style="border: 2px solid #000; padding: 12px; text-align: center;"><strong>${margineLordoPercent}%</strong></td>
            </tr>
        </tbody>
    </table>`;
    
    return html;
}

// Render Operativi Breakdown (Tab 3 - tabella 3 colonne)
function renderOperativiBreakdown(data, baseRicavi) {
    const categorie = data.categorie || [];
    const breakdown = data.breakdown_by_type && data.breakdown_by_type['Costi Operativi/Abbonamenti'] ? data.breakdown_by_type['Costi Operativi/Abbonamenti'] : [];
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    
    if (breakdown.length === 0) return '<p style="text-align: center; color: var(--text-muted); padding: 20px;">Nessun costo operativo in questo periodo</p>';
    
    const fmtP = (v) => baseRicavi ? ((Math.abs(v) / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #f59e0b; font-size: 16px; margin: 16px 0 12px; border-left: 4px solid #f59e0b; padding-left: 10px;">
            🏢 Costi Operativi/Abbonamenti
        </h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 16px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Componente</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Importo €</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Transazioni</th>
                </tr>
            </thead>
            <tbody>`;
    
    for (const item of breakdown) {
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 6px;">${escapeHtml(item.transaction_type)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right;">${formatCurrency(item.importo_eur)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">${item.transazioni}</td>
        </tr>`;
    }
    
    // TOTALE ALTRI COSTI OPERATIVI
    const totaleOperativi = catOperativi ? catOperativi.importo_eur : 0;
    html += `<tr style="background: #f8d7da; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px; font-size: 11px;"><strong>TOTALE ALTRI COSTI OPERATIVI</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right;"><strong>${formatCurrency(totaleOperativi)}</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><strong>${catOperativi ? catOperativi.transazioni : 0}</strong></td>
    </tr>`;
    
    html += '</tbody></table>';
    
    // Box informativo
    html += `<div style="background: #fff3cd; border-left: 4px solid #ff9800; padding: 10px; border-radius: 6px; font-size: 11px; margin: 16px 0;">
        <strong>💡 Cosa rappresenta questa tabella:</strong><br>
        Questi sono <strong>costi operativi aggiuntivi</strong> che Amazon addebita sul tuo account settlement oltre alle commissioni di vendita. 
        Include costi come: abbonamenti mensili, fee di trasporto inbound, fee di storage/rimozione, fee di servizio e altri costi operativi.<br><br>
        Amazon detrae dal tuo account questi importi prima di accreditarti il saldo.
    </div>`;
    
    // Calcolo EROGATO SU IBAN
    const fc = data.fee_components;
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    const erogato = incassatoDaiClienti - commissioniTotali + totaleOperativi; // totaleOperativi è negativo
    const erogatoPercent = baseRicavi ? ((erogato / baseRicavi) * 100).toFixed(2) : 0;
    
    // Tabella riepilogo finale
    html += `<table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 16px;">
        <tbody>
            <tr style="background: #e3f2fd;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>💵 Incassato dai Clienti</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold;">${formatCurrency(incassatoDaiClienti)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #ffebee;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>➖ Commissioni Amazon</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: #e53e3e;">${formatCurrency(-commissioniTotali)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #fff3cd;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>➖ Altri Costi Operativi</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: #e53e3e;">${formatCurrency(totaleOperativi)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #4caf50; color: white; font-weight: bold;">
                <td style="border: 2px solid #000; padding: 12px;"><strong>💰 EROGATO SU IBAN</strong></td>
                <td style="border: 2px solid #000; padding: 12px; text-align: right;"><strong>${formatCurrency(erogato)}</strong></td>
                <td style="border: 2px solid #000; padding: 12px; text-align: center;"><strong>${erogatoPercent}%</strong></td>
            </tr>
        </tbody>
    </table>`;
    
    return html;
}

// Render Perdite Breakdown (Tab 4 - tabella 3 colonne)
function renderPerditeBreakdown(data, baseRicavi) {
    const categorie = data.categorie || [];
    const breakdown = data.breakdown_by_type && data.breakdown_by_type['Perdite e Rimborsi/Danni'] ? data.breakdown_by_type['Perdite e Rimborsi/Danni'] : [];
    const catPerdite = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni');
    
    if (breakdown.length === 0) return '<p style="text-align: center; color: var(--text-muted); padding: 20px;">Nessuna perdita/danno in questo periodo</p>';
    
    const fmtP = (v) => baseRicavi ? ((Math.abs(v) / baseRicavi) * 100).toFixed(2) + '%' : '—';
    
    let html = `
        <h3 style="color: #3b82f6; font-size: 16px; margin: 16px 0 12px; border-left: 4px solid #3b82f6; padding-left: 10px;">
            ⚠️ Perdite e Rimborsi/Danni
        </h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 16px;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Componente</th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: right;">Importo €</th>
                </tr>
            </thead>
            <tbody>`;
    
    for (const item of breakdown) {
        const colorClass = item.importo_eur >= 0 ? '#38a169' : '#e53e3e';
        html += `<tr>
            <td style="border: 1px solid #ddd; padding: 6px;">${escapeHtml(item.transaction_type)}</td>
            <td style="border: 1px solid #ddd; padding: 6px; text-align: right; color: ${colorClass};">${formatCurrency(item.importo_eur)}</td>
        </tr>`;
    }
    
    // TOTALE PERDITE E RIMBORSI/DANNI
    const totalePerdite = catPerdite ? catPerdite.importo_eur : 0;
    const bgColor = totalePerdite >= 0 ? '#d4edda' : '#f8d7da';
    const textColor = totalePerdite >= 0 ? '#38a169' : '#e53e3e';
    html += `<tr style="background: ${bgColor}; font-weight: bold;">
        <td style="border: 1px solid #ddd; padding: 8px; font-size: 11px;"><strong>TOTALE PERDITE E RIMBORSI/DANNI</strong></td>
        <td style="border: 1px solid #ddd; padding: 8px; text-align: right; color: ${textColor};"><strong>${formatCurrency(totalePerdite)}</strong></td>
    </tr>`;
    
    html += '</tbody></table>';
    
    // Box informativo
    html += `<div style="background: #fff3cd; border-left: 4px solid #ff9800; padding: 10px; border-radius: 6px; font-size: 11px; margin: 16px 0;">
        <strong>💡 Cosa rappresenta questa tabella:</strong><br>
        Questi sono <strong>rimborsi e compensazioni</strong> che Amazon ti eroga per prodotti danneggiati, persi in magazzino, o eventi come liquidazioni. 
        Include: WAREHOUSE_DAMAGE, WAREHOUSE_LOST, MISSING_FROM_INBOUND, Liquidations e altri eventi di perdita.<br><br>
        ${totalePerdite >= 0 ? 'Questi importi positivi aumentano il tuo netto operativo.' : 'Questi importi negativi riducono il tuo netto operativo (eventi rari).'}
    </div>`;
    
    // Calcolo EROGATO SU IBAN (include anche perdite/danni)
    const fc = data.fee_components;
    const incassatoDaiClienti = (fc.price.total || 0) + (fc.refund?.total || 0);
    const commissioniTotali = Math.abs(
        (fc.item_related_fees?.total || 0) + 
        (fc.order_fees?.total || 0) + 
        (fc.shipment_fees?.total || 0)
    );
    const catOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti');
    const totaleOperativi = catOperativi ? catOperativi.importo_eur : 0;
    const erogato = incassatoDaiClienti - commissioniTotali + totaleOperativi + totalePerdite; // totalePerdite può essere positivo o negativo
    const erogatoPercent = baseRicavi ? ((erogato / baseRicavi) * 100).toFixed(2) : 0;
    
    // Tabella riepilogo finale
    html += `<table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 16px;">
        <tbody>
            <tr style="background: #e3f2fd;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>💵 Incassato dai Clienti</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold;">${formatCurrency(incassatoDaiClienti)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #ffebee;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>➖ Commissioni Amazon</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: #e53e3e;">${formatCurrency(-commissioniTotali)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #fff3cd;">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>➖ Altri Costi Operativi</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: #e53e3e;">${formatCurrency(totaleOperativi)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: ${totalePerdite >= 0 ? '#e8f5e9' : '#ffebee'};">
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>${totalePerdite >= 0 ? '➕' : '➖'} Perdite e Rimborsi/Danni</strong></td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd; font-weight: bold; color: ${totalePerdite >= 0 ? '#38a169' : '#e53e3e'};">${formatCurrency(totalePerdite)}</td>
                <td style="padding: 10px; border: 1px solid #ddd;"></td>
            </tr>
            <tr style="background: #4caf50; color: white; font-weight: bold;">
                <td style="border: 2px solid #000; padding: 12px;"><strong>💰 EROGATO SU IBAN</strong></td>
                <td style="border: 2px solid #000; padding: 12px; text-align: right;"><strong>${formatCurrency(erogato)}</strong></td>
                <td style="border: 2px solid #000; padding: 12px; text-align: center;"><strong>${erogatoPercent}%</strong></td>
            </tr>
        </tbody>
    </table>`;
    
    return html;
}

// Funzioni renderCategoriesBreakdown e renderBreakdown RIMOSSE (non più necessarie)

// Load Last Days (mostra solo i primi 8 giorni per mobile)
async function loadLast16Days() {
    try {
        // Fetch TUTTI i dati disponibili
        const resp = await fetch('/modules/orderinsights/OverviewController.php?action=day_index');
        const json = await resp.json();
        
        if (!json.success) {
            console.error('day_index failed:', json);
            document.getElementById('section-6-body').innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 20px;">Errore caricamento dati</p>';
            return;
        }
        
        let rows = json.data || [];
        
        if (rows.length === 0) {
            document.getElementById('section-6-body').innerHTML = '<p style="text-align: center; color: var(--text-muted); padding: 20px;">Nessun dato disponibile</p>';
            return;
        }
        
        // Rimuovi duplicati basandoti sulla data normalizzata
        const seen = new Set();
        rows = rows.filter(day => {
            const normalizedDate = day.giorno ? day.giorno.split('T')[0] : null;
            if (!normalizedDate || seen.has(normalizedDate)) {
                return false;
            }
            seen.add(normalizedDate);
            return true;
        });
        
        // Ordina per data decrescente (più recente prima)
        rows.sort((a, b) => {
            const dateA = new Date(a.giorno);
            const dateB = new Date(b.giorno);
            return dateB - dateA;
        });
        
        // Salva TUTTI i giorni disponibili
        allDaysData = rows;
        daysOffset = 0;
        
        // Mostra i primi 8 giorni (mobile)
        renderMobileDays(0, daysPerPage);
        
    } catch (error) {
        console.error('Load last days error:', error);
        document.getElementById('section-6-body').innerHTML = '<p style="text-align: center; color: var(--danger); padding: 20px;">Errore caricamento: ' + error.message + '</p>';
    }
}

// Funzione helper per renderizzare i giorni (mobile)
function renderMobileDays(start, count) {
    const container = document.getElementById('section-6-body');
    const daysToShow = allDaysData.slice(start, start + count);
    
    let cardsHtml = '';
    
    for (const day of daysToShow) {
        const dateObj = new Date(day.giorno);
        const formattedDate = formatDateShort(dateObj);
        
        const ricavi = day.incassato_vendite || 0;
        const ordini = day.ordini || 0;
        const refund = day.refund_totale || 0;
        const refundOrdini = day.refund_ordini || 0;
        
        const colorClass = ricavi >= 0 ? 'positive' : 'negative';
        
        cardsHtml += `
            <div class="day-card" onclick="openDayDrawer('${day.giorno}', ${JSON.stringify(day).replace(/"/g, '&quot;')})">
                <div class="day-date">${formattedDate}</div>
                <div class="day-value ${colorClass}">${formatCurrency(ricavi)}</div>
                <div class="day-value" style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">${formatNumber(ordini)} ${ordini === 1 ? 'ordine' : 'ordini'}</div>
        `;
        
        if (refundOrdini > 0) {
            cardsHtml += `<div class="day-value negative" style="font-size: 11px; margin-top: 4px;">${formatCurrency(refund)} (${formatNumber(refundOrdini)} refund)</div>`;
        }
        
        cardsHtml += `</div>`;
    }
    
    // Aggiorna offset
    daysOffset = start + count;
    
    // Costruisci HTML completo
    let html = '';
    
    if (start === 0) {
        // Prima volta: crea il grid wrapper
        html = `<div class="days-grid">${cardsHtml}</div>`;
    }
    
    // Mostra/nascondi pulsante in base ai giorni rimanenti
    if (daysOffset < allDaysData.length) {
        const remaining = allDaysData.length - daysOffset;
        html += `<button class="load-more-btn" onclick="loadAllDays()">
            📅 Carica altri ${Math.min(remaining, daysPerPage)} giorni
        </button>`;
    }
    
    if (start === 0) {
        container.innerHTML = html;
    } else {
        // Rimuovi il vecchio pulsante
        const oldBtn = container.querySelector('.load-more-btn');
        if (oldBtn) oldBtn.remove();
        
        // Trova il grid e aggiungi le nuove card
        const grid = container.querySelector('.days-grid');
        if (grid) {
            grid.insertAdjacentHTML('beforeend', cardsHtml);
        }
        
        // Aggiungi nuovo pulsante se necessario
        if (daysOffset < allDaysData.length) {
            const remaining = allDaysData.length - daysOffset;
            container.insertAdjacentHTML('beforeend', `<button class="load-more-btn" onclick="loadAllDays()">
                📅 Carica altri ${Math.min(remaining, daysPerPage)} giorni
            </button>`);
        }
    }
}

// Load All Days (quando clicca "Carica altri giorni")
function loadAllDays() {
    // Carica i prossimi 8 giorni da allDaysData
    if (daysOffset >= allDaysData.length) {
        return;
    }
    
    renderMobileDays(daysOffset, daysPerPage);
}

function formatDateShort(dateObj) {
    if (isNaN(dateObj.getTime())) return 'N/A';
    const giorni = ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'];
    const mesi = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];
    return `${giorni[dateObj.getDay()]} ${dateObj.getDate()} ${mesi[dateObj.getMonth()]} ${dateObj.getFullYear()}`;
}

// Open Day Drawer (Modal con 4 righe come desktop)
async function openDayDrawer(dayDate, dayData = null) {
    document.getElementById('drawer-overlay').classList.add('active');
    document.getElementById('drawer').classList.add('active');
    document.getElementById('drawer-title').textContent = formatDateLong(dayDate);
    document.getElementById('drawer-subtitle').textContent = 'Calcolo Utile Operativo Giornaliero';
    document.getElementById('drawer-content').innerHTML = '<div class="loading-spinner"><div class="spinner"></div></div>';
    
    try {
        const res = await fetch(`/modules/orderinsights/OverviewController.php?action=day_summary&day=${dayDate}`);
        const json = await res.json();
        
        if (!json.success) {
            document.getElementById('drawer-content').innerHTML = '<div class="empty-state">Errore caricamento</div>';
            return;
        }
        
        const data = json.data;
        const categorie = data.categorie || [];
        
        // Trova le categorie specifiche
        const commissioni = categorie.find(c => c.categoria === 'Commissioni di Vendita/Logistica') || { importo_eur: 0 };
        const costiOperativi = categorie.find(c => c.categoria === 'Costi Operativi/Abbonamenti') || { importo_eur: 0 };
        const perditeRimborsi = categorie.find(c => c.categoria === 'Perdite e Rimborsi/Danni') || { importo_eur: 0 };
        
        // USA I DATI DALLA CARD se disponibili
        let incassoGiornata, ordiniCount, refundGiornata, refundCount;
        
        if (dayData) {
            incassoGiornata = dayData.incassato_vendite || 0;
            ordiniCount = dayData.ordini || 0;
            refundGiornata = dayData.refund_totale || 0;
            refundCount = dayData.refund_ordini || 0;
        } else {
            incassoGiornata = data.kpi.incassato_vendite || 0;
            ordiniCount = data.kpi.ordini || 0;
            refundGiornata = 0;
            refundCount = data.kpi.unita_rimborsate || 0;
        }
        
        // TOTALE COMMISSIONI AMAZON = Tab2 + Tab3 + Tab4
        const totaleCommissioniAmazon = commissioni.importo_eur + costiOperativi.importo_eur + perditeRimborsi.importo_eur;
        
        // Netto Operativo
        const nettoOperativo = data.kpi.netto_operativo || 0;
        
        const html = `
            <div style="background: linear-gradient(135deg, ${nettoOperativo >= 0 ? '#38a169' : '#e53e3e'}, ${nettoOperativo >= 0 ? '#48bb78' : '#fc8181'}); padding: 1.5rem; border-radius: 12px; margin-bottom: 1rem; color: white; text-align: center;">
                <div style="font-size: 2rem; font-weight: bold; margin-bottom: 0.5rem;">${formatCurrency(nettoOperativo)}</div>
                <div style="font-size: 1rem; opacity: 0.95;">Netto Operativo del Giorno</div>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.3);">
                    <div>
                        <div style="font-size: 1.2rem; font-weight: bold;">${formatNumber(data.kpi.ordini)}</div>
                        <div style="font-size: 0.8rem; opacity: 0.9;">Ordini</div>
                    </div>
                    <div>
                        <div style="font-size: 1.2rem; font-weight: bold;">${formatNumber(data.kpi.transazioni)}</div>
                        <div style="font-size: 0.8rem; opacity: 0.9;">Transazioni</div>
                    </div>
                </div>
            </div>
            
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 1rem;">
                <thead>
                    <tr style="background: #f8f9fa;">
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: left; font-size: 11px;">Voce</th>
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: right; font-size: 11px;">Importo €</th>
                        <th style="border: 1px solid #ddd; padding: 10px; text-align: center; font-size: 11px;">Unità</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="background: #e8f5e9;">
                        <td style="border: 1px solid #ddd; padding: 10px; font-size: 12px;"><strong>💰 Incasso di Giornata</strong></td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: right; color: #38a169; font-weight: bold; font-size: 13px;">${formatCurrency(incassoGiornata)}</td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: center; font-size: 12px;">${formatNumber(ordiniCount)}</td>
                    </tr>
                    <tr style="background: #ffebee;">
                        <td style="border: 1px solid #ddd; padding: 10px; font-size: 12px;"><strong>🔄 Refund di Giornata</strong></td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: right; color: #e53e3e; font-weight: bold; font-size: 13px;">${formatCurrency(refundGiornata)}</td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: center; font-size: 12px;">${formatNumber(refundCount)}</td>
                    </tr>
                    <tr style="background: #fff3cd;">
                        <td style="border: 1px solid #ddd; padding: 10px; font-size: 12px;"><strong>➖ Commissioni Amazon Totali</strong></td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: right; color: #e53e3e; font-weight: bold; font-size: 13px;">${formatCurrency(totaleCommissioniAmazon)}</td>
                        <td style="border: 1px solid #ddd; padding: 10px; text-align: center;"></td>
                    </tr>
                    <tr style="background: ${nettoOperativo >= 0 ? '#4caf50' : '#f44336'}; color: white; font-weight: bold;">
                        <td style="border: 2px solid #000; padding: 12px; font-size: 13px;"><strong>💎 NETTO OPERATIVO</strong></td>
                        <td style="border: 2px solid #000; padding: 12px; text-align: right; font-size: 14px;"><strong>${formatCurrency(nettoOperativo)}</strong></td>
                        <td style="border: 2px solid #000; padding: 12px; text-align: center; font-size: 13px;"><strong>${formatNumber(data.kpi.transazioni)}</strong></td>
                    </tr>
                </tbody>
            </table>
            
            <details style="margin-top: 1rem; padding: 1rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #f9fafb;">
                <summary style="cursor: pointer; font-weight: bold; color: #4b5563; font-size: 12px;">
                    🔍 Dettaglio Commissioni (Tab2 + Tab3 + Tab4)
                </summary>
                <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e2e8f0; font-size: 12px;">
                    <div style="margin-bottom: 0.5rem;">
                        <strong>Tab2 - Commissioni Vendita/Logistica:</strong> ${formatCurrency(commissioni.importo_eur)}
                    </div>
                    <div style="margin-bottom: 0.5rem;">
                        <strong>Tab3 - Costi Operativi/Abbonamenti:</strong> ${formatCurrency(costiOperativi.importo_eur)}
                    </div>
                    <div>
                        <strong>Tab4 - Perdite e Rimborsi/Danni:</strong> ${formatCurrency(perditeRimborsi.importo_eur)}
                    </div>
                </div>
            </details>
        `;
        
        document.getElementById('drawer-content').innerHTML = html;
        
    } catch (error) {
        console.error('Drawer error:', error);
        document.getElementById('drawer-content').innerHTML = '<div class="empty-state">Errore caricamento</div>';
    }
}

function closeDrawer() {
    document.getElementById('drawer-overlay').classList.remove('active');
    document.getElementById('drawer').classList.remove('active');
}

// Toggle Accordion REMOVED (sezioni always visible)

// Utils
function formatCurrency(num) {
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(num || 0);
}

function formatNumber(num) {
    return new Intl.NumberFormat('it-IT').format(num || 0);
}

function formatDate(date) {
    return date.toISOString().split('T')[0];
}

function formatDateLong(dateStr) {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    if (isNaN(date.getTime())) return dateStr; // Se data invalida, mostra stringa originale
    return date.toLocaleDateString('it-IT', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Logout function
function doLogout() {
    if (confirm('Sei sicuro di voler uscire?')) {
        window.location.href = '/modules/margynomic/login/logout.php';
    }
}

// Show Category Breakdown Modal
function showCategoryBreakdown(categoria) {
    if (!currentData || !currentData.breakdown_by_type || !currentData.breakdown_by_type[categoria]) {
        alert('Nessun dettaglio disponibile per questa categoria');
        return;
    }
    
    const breakdown = currentData.breakdown_by_type[categoria];
    
    let html = `
        <h3 style="margin-bottom: 16px; font-size: 16px;">${escapeHtml(categoria)}</h3>
        <div style="max-height: 60vh; overflow-y: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead style="position: sticky; top: 0; background: white; z-index: 10;">
                    <tr style="background: var(--bg-light);">
                        <th style="border: 1px solid var(--border-light); padding: 8px; text-align: left;">Transaction Type</th>
                        <th style="border: 1px solid var(--border-light); padding: 8px; text-align: right;">Importo</th>
                        <th style="border: 1px solid var(--border-light); padding: 8px; text-align: center;">N°</th>
                    </tr>
                </thead>
                <tbody>`;
    
    for (const item of breakdown) {
        const colorClass = item.importo_eur >= 0 ? 'var(--success)' : 'var(--danger)';
        html += `<tr>
            <td style="border: 1px solid var(--border-light); padding: 8px; font-size: 11px;">${escapeHtml(item.transaction_type)}</td>
            <td style="border: 1px solid var(--border-light); padding: 8px; text-align: right; color: ${colorClass}; font-weight: 600;">${formatCurrency(item.importo_eur)}</td>
            <td style="border: 1px solid var(--border-light); padding: 8px; text-align: center; font-size: 11px;">${formatNumber(item.transazioni)}</td>
        </tr>`;
    }
    
    html += `</tbody></table></div>`;
    
    document.getElementById('breakdown-modal-content').innerHTML = html;
    document.getElementById('breakdown-modal').classList.add('active');
}

function closeBreakdownModal() {
    document.getElementById('breakdown-modal').classList.remove('active');
}
</script>

<!-- Modal Breakdown Categorie -->
<div id="breakdown-modal" class="modal-overlay" onclick="if(event.target===this) closeBreakdownModal()">
    <div class="modal-card" style="width: 90%; max-width: 500px; max-height: 80vh; overflow: hidden;">
        <button onclick="closeBreakdownModal()" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-muted);">×</button>
        <div id="breakdown-modal-content" style="padding: 20px;"></div>
    </div>
</div>

<style>
.modal-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}

.modal-overlay.active {
    display: flex;
}

.modal-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    position: relative;
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from { transform: translateY(50px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
</style>

</main>

<?php include __DIR__ . '/_partials/mobile_tabbar.php'; ?>

</body>
</html>