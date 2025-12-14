<?php
/**
 * Mobile TridScanner - TRID Tracking Completo
 * Feature parity con desktop: KPI, Unità Sparite, Spedizioni, Rimborsi, Filtri
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
    header('Location: /modules/inbound/trid/trid.php');
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
    <meta name="theme-color" content="#f59e0b">
    <meta name="apple-mobile-web-app-title" content="SkuAlizer Suite">
    <meta name="format-detection" content="telephone=no">
    <title>TridScanner - Skualizer Mobile</title>
    
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
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #ec4899 !important; }
    </style>

<style>
body { overflow-x: hidden; padding-top: 0 !important; }
.mobile-content { padding-top: 0 !important; }
.hero-welcome {
    background: linear-gradient(135deg, #ec4899 0%, #f472b6 100%);
    color: white;
    padding: 0;
    margin: 0 0 16px 0;
    border-radius: 0 0 20px 20px;
    text-align: left;
    box-shadow: 0 4px 12px rgba(236, 72, 153, 0.3);
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
    border-left: 3px solid rgba(236, 72, 153, 0.8);
    padding: 10px;
    text-align: left;
    min-width: 0;
    overflow: hidden;
}
.info-box-title { font-size: 12px; font-weight: 700; margin-bottom: 4px; color: #1a202c; }
.info-box-text { font-size: 10px; opacity: 0.75; line-height: 1.4; color: #1a202c; }

/* TRID Specific Styles */
.unita-sparite-banner {
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-left: 4px solid #f59e0b;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);
}

.banner-title {
    font-size: 16px;
    font-weight: 700;
    color: #92400e;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.formula-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: #78350f;
    margin-bottom: 12px;
}

.formula-item {
    display: flex;
    align-items: center;
    gap: 4px;
}

.formula-operator {
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
}

.formula-value {
    font-weight: 700;
    font-size: 16px;
}

.formula-result {
    background: white;
    padding: 8px 12px;
    border-radius: 6px;
    font-weight: 700;
    font-size: 18px;
    color: #dc2626;
}

.banner-text {
    font-size: 12px;
    color: #78350f;
    margin-top: 8px;
    line-height: 1.4;
}

.section-divider {
    height: 8px;
    background: var(--bg-light);
    margin: 0 -16px;
}

/* Drawer Bottom Sheet */
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
    max-height: 70vh;
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

.item-row {
    display: flex;
    justify-content: space-between;
    padding: 12px;
    background: var(--bg-light);
    border-radius: 8px;
    margin-bottom: 8px;
}

.item-name {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 4px;
}

.item-sku {
    font-size: 12px;
    color: var(--text-muted);
}

.item-quantities {
    text-align: right;
    font-size: 13px;
}

.loading-spinner {
    text-align: center;
    padding: 20px;
    color: var(--text-muted);
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

.kpi-grid-6 {
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
.kpi-compact.info { border-left: 3px solid #3b82f6; }
.kpi-compact.danger { border-left: 3px solid #ef4444; }
.kpi-compact.purple { border-left: 3px solid #8b5cf6; }

.two-column-container {
    display: flex;
    gap: 12px;
    margin-bottom: 80px;
}

.column-left, .column-right {
    flex: 1;
    min-width: 0;
}

#shipments-container, #reimbursements-container {
    max-height: 75vh;
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
}

.shipment-compact {
    background: white;
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 8px;
    box-shadow: var(--shadow-sm);
    border-left: 3px solid var(--theme-primary);
    font-size: 12px;
    cursor: pointer;
}

.shipment-compact-id {
    font-weight: 700;
    font-size: 11px;
    margin-bottom: 4px;
    color: var(--text-dark);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reimb-month-header {
    background: white;
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 4px;
    box-shadow: var(--shadow-sm);
    border-left: 3px solid #ef4444;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    font-size: 12px;
}

.reimb-month-title {
    font-weight: 700;
    font-size: 11px;
    color: var(--text-dark);
}

.reimb-month-badge {
    background: rgba(239, 68, 68, 0.1);
    color: #dc2626;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.reimb-events-container {
    display: none;
    margin-bottom: 8px;
}

.reimb-events-container.expanded {
    display: block;
}

.reimb-event-item {
    background: white;
    border-radius: 6px;
    padding: 8px;
    margin-bottom: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border-left: 2px solid #fca5a5;
    font-size: 10px;
}

.reimb-event-date {
    font-weight: 700;
    color: #374151;
    font-size: 11px;
}

.reimb-event-qty {
    font-size: 11px;
    font-weight: 700;
    color: #dc2626;
}

.reimb-event-product {
    font-weight: 600;
    color: #1f2937;
    margin-bottom: 3px;
    font-size: 11px;
}

.reimb-event-meta {
    color: #6b7280;
    margin-bottom: 3px;
}

.reimb-event-trid {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 4px;
    padding-top: 4px;
    border-top: 1px solid #f3f4f6;
}

.reimb-trid-number {
    font-family: monospace;
    font-size: 10px;
    font-weight: 600;
    color: #4b5563;
}

.copy-btn {
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 10px;
    cursor: pointer;
    font-weight: 600;
}

.copy-btn:active {
    background: #e5e7eb;
}
</style>
</head>
<body>
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #ec4899 0%, #f472b6 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #ec4899; background: #fdf2f8;">
                    <i class="fas fa-search" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-user" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #ec4899; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #ec4899; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>
    <main class="mobile-content" style="padding-top: 0;">

<div class="hero-welcome">
    <div class="hero-header">
        <div class="hero-logo">
            <div class="hero-title"><i class="fas fa-search"></i> TridScanner</div>
            <div class="hero-subtitle">TRACCIA SPEDIZIONI E RIMBORSI FBA!</div>
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
            <div class="info-box-title">📦 Tracking Spedizioni</div>
            <div class="info-box-text">Monitora tutte le spedizioni inbound e verifica unità ricevute.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">⚠️ Unità Sparite</div>
            <div class="info-box-text">Identifica discrepanze tra unità inviate e ricevute da Amazon.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">💰 Rimborsi TRID</div>
            <div class="info-box-text">Gestisci rimborsi per prodotti danneggiati, persi o mancanti.</div>
        </div>
        <div class="info-box">
            <div class="info-box-title">📊 Analytics</div>
            <div class="info-box-text">Statistiche complete su performance e recovery rate.</div>
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

<!-- KPI Grid 6 Cards -->
<div class="kpi-grid-6" id="kpi-container">
    <div class="kpi-compact primary">
        <div class="kpi-compact-label">📤 Spedite</div>
        <div class="kpi-compact-value" id="kpi-spedite">-</div>
    </div>
    
    <div class="kpi-compact success">
        <div class="kpi-compact-label">✅ Ricevute</div>
        <div class="kpi-compact-value" id="kpi-ricevute">-</div>
    </div>
    
    <div class="kpi-compact warning">
        <div class="kpi-compact-label">🔄 Ritirate</div>
        <div class="kpi-compact-value" id="kpi-ritirate">-</div>
    </div>
    
    <div class="kpi-compact info">
        <div class="kpi-compact-label">🛒 Vendute</div>
        <div class="kpi-compact-value" id="kpi-vendute">-</div>
    </div>
    
    <div class="kpi-compact danger">
        <div class="kpi-compact-label">↩️ Resi</div>
        <div class="kpi-compact-value" id="kpi-resi">-</div>
    </div>
    
    <div class="kpi-compact purple">
        <div class="kpi-compact-label">📦 Giacenza</div>
        <div class="kpi-compact-value" id="kpi-giacenza">-</div>
    </div>
</div>

<!-- Banner Unità Sparite -->
<div class="unita-sparite-banner" id="banner-sparite" style="display: none;">
    <div class="banner-title">
        ⚠️ Unità Non Contabilizzate
    </div>
    <div class="formula-row">
        <div class="formula-item">
            <span id="f-spedite">0</span> <small>(spedite)</small>
        </div>
        <span class="formula-operator">-</span>
        <div class="formula-item">
            <span id="f-vendute">0</span> <small>(vendute)</small>
        </div>
        <span class="formula-operator">-</span>
        <div class="formula-item">
            <span id="f-ritirate">0</span> <small>(ritirate)</small>
        </div>
        <span class="formula-operator">-</span>
        <div class="formula-item">
            <span id="f-resi">0</span> <small>(resi)</small>
        </div>
        <span class="formula-operator">-</span>
        <div class="formula-item">
            <span id="f-giacenza">0</span> <small>(giacenza)</small>
        </div>
        <span class="formula-operator">=</span>
        <div class="formula-result" id="f-totale">0</div>
    </div>
    <div class="banner-text" id="banner-text">
        Su <strong id="sparite-totali">0</strong> unità non contabilizzate, 
        sono state <strong>rimborsate <span id="unita-rimborsate">0</span></strong>, 
        quindi devi recuperare ancora <strong id="da-recuperare">0</strong> unità.
    </div>
</div>

<div class="section-divider"></div>

<!-- Spedizioni e Rimborsi Affiancati -->
<div class="two-column-container">
    <!-- Spedizioni -->
    <div class="column-left">
        <div class="section-title">📦 Spedizioni</div>
        <div id="shipments-container">
            <div class="loading-spinner">Caricamento...</div>
        </div>
    </div>
    
    <!-- Rimborsi -->
    <div class="column-right">
        <div class="section-title">💰 Rimborsi</div>
        <div id="reimbursements-container">
            <div class="loading-spinner">Caricamento...</div>
        </div>
    </div>
</div>

<!-- Drawer Dettaglio Spedizione -->
<div class="drawer-overlay" id="drawer-overlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-handle"></div>
    <div class="drawer-header">
        <div class="drawer-title" id="drawer-title">Dettaglio Spedizione</div>
        <div class="drawer-subtitle" id="drawer-subtitle"></div>
    </div>
    <div class="drawer-content" id="drawer-content">
        <div class="loading-spinner">Caricamento...</div>
    </div>
</div>

<script>
let dashboardData = null;

// Load Dashboard
async function loadDashboard() {
    try {
        const res = await fetch('/modules/inbound/trid/trid_scanner_api.php?action=load_dashboard');
        const json = await res.json();
        
        if (!json.success) {
            console.error('API Error:', json.error);
            showError('Errore API: ' + (json.error || 'Errore sconosciuto'));
            return;
        }
        
        dashboardData = json.data;
        renderKPI(dashboardData.kpi || {});
        renderBannerSparite(dashboardData.kpi || {});
        renderShipments(dashboardData.shipments || []);
        renderReimbursements(dashboardData.reimbursements_by_month || {});
        
    } catch (error) {
        console.error('Load error:', error);
        showError('Errore di connessione: ' + error.message);
    }
}

function showError(message) {
    const errorHtml = `
        <div class="empty-state" style="padding: 20px 10px;">
            <div style="font-size: 32px; opacity: 0.5;">⚠️</div>
            <div style="font-size: 11px;">${escapeHtml(message)}</div>
        </div>
    `;
    document.getElementById('shipments-container').innerHTML = errorHtml;
    document.getElementById('reimbursements-container').innerHTML = errorHtml;
}

// Render 6 KPI
function renderKPI(kpi) {
    document.getElementById('kpi-spedite').textContent = formatNumber(kpi.unita_spedite || 0);
    document.getElementById('kpi-ricevute').textContent = formatNumber(kpi.unita_ricevute || 0);
    document.getElementById('kpi-ritirate').textContent = formatNumber(kpi.unita_ritirate || 0);
    document.getElementById('kpi-vendute').textContent = formatNumber(kpi.unita_vendute || 0);
    document.getElementById('kpi-resi').textContent = formatNumber(kpi.resi_clienti || 0);
    document.getElementById('kpi-giacenza').textContent = formatNumber(kpi.giacenza || 0);
}

// Render Banner Unità Sparite
function renderBannerSparite(kpi) {
    const spedite = kpi.unita_spedite || 0;
    const vendute = kpi.unita_vendute || 0;
    const ritirate = kpi.unita_ritirate || 0;
    const resi = kpi.resi_clienti || 0;
    const giacenza = kpi.giacenza || 0;
    const rimborsate = kpi.unita_rimborsate || 0;
    
    const sparite = spedite - vendute - ritirate - resi - giacenza;
    const daRecuperare = Math.max(0, sparite - rimborsate);
    
    document.getElementById('f-spedite').textContent = formatNumber(spedite);
    document.getElementById('f-vendute').textContent = formatNumber(vendute);
    document.getElementById('f-ritirate').textContent = formatNumber(ritirate);
    document.getElementById('f-resi').textContent = formatNumber(resi);
    document.getElementById('f-giacenza').textContent = formatNumber(giacenza);
    document.getElementById('f-totale').textContent = formatNumber(sparite);
    
    document.getElementById('sparite-totali').textContent = formatNumber(sparite);
    document.getElementById('unita-rimborsate').textContent = formatNumber(rimborsate);
    document.getElementById('da-recuperare').textContent = formatNumber(daRecuperare);
    
    // Mostra solo se ci sono unità non contabilizzate
    if (sparite > 0) {
        document.getElementById('banner-sparite').style.display = 'block';
    }
}

// Render Spedizioni
function renderShipments(shipments) {
    const container = document.getElementById('shipments-container');
    
    if (!shipments || shipments.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px 10px;">
                <div style="font-size: 32px; opacity: 0.5;">📦</div>
                <div style="font-size: 11px;">Nessuna spedizione</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    shipments.slice(0, 50).forEach(ship => {
        const shipmentId = ship.amazon_shipment_id || ship.shipment_name || 'N/A';
        const fc = ship.warehouses || ship.destination_fc || 'N/A';
        const qtyShipped = ship.qty_shipped || 0;
        const qtyReceived = ship.qty_received || 0;
        const tridEvents = ship.trid_events || 0;
        
        // Colori: verde se match, giallo se discrepanza
        const shippedColor = qtyShipped === qtyReceived ? '#10b981' : '#10b981';
        const receivedColor = qtyShipped === qtyReceived ? '#10b981' : '#f59e0b';
        
        // Date parsing - priorità a receipt_date (come desktop)
        let shipDate = ship.receipt_date || ship.date_received || ship.date_shipped || ship.created_at || ship.shipment_date;
        const formattedDateShort = shipDate ? formatDate(shipDate) : 'N/A';
        
        html += `
            <div class="shipment-compact" onclick="openDrawer(${ship.id})">
                <div class="shipment-compact-id">${escapeHtml(shipmentId)}</div>
                <div style="font-size: 11px; font-weight: 700; margin: 4px 0; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span style="color: ${shippedColor};">${qtyShipped}</span>
                        <span style="color: #6b7280;"> → </span>
                        <span style="color: ${receivedColor};">${qtyReceived}</span>
                    </div>
                    <div style="font-size: 10px; color: #6b7280; font-weight: 600;">
                        📅 ${formattedDateShort}
                    </div>
                </div>
                <div style="font-size: 10px; color: #6b7280; display: flex; justify-content: space-between; align-items: center;">
                    <span>📍 ${escapeHtml(fc)}</span>
                    <span>${tridEvents} eventi</span>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
}

// Render Rimborsi
function renderReimbursements(reimbsByMonth) {
    const container = document.getElementById('reimbursements-container');
    
    if (!reimbsByMonth || Object.keys(reimbsByMonth).length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="padding: 20px 10px;">
                <div style="font-size: 32px; opacity: 0.5;">💰</div>
                <div style="font-size: 11px;">Nessun rimborso</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    // Ordina mesi in ordine decrescente
    const months = Object.keys(reimbsByMonth).sort().reverse();
    
    months.forEach(month => {
        const data = reimbsByMonth[month];
        const totalQty = data.total_qty || data.events.reduce((sum, ev) => sum + Math.abs(ev.quantity), 0);
        const monthId = `reimb-month-${month.replace(/[^a-zA-Z0-9]/g, '')}`;
        
        html += `
            <div class="reimb-month-header" onclick="toggleReimbMonth('${monthId}')">
                <div class="reimb-month-title">${formatMonthYear(month)}</div>
                <div class="reimb-month-badge">${totalQty} TRID</div>
            </div>
            <div class="reimb-events-container" id="${monthId}">
        `;
        
        // Ordina eventi per data decrescente
        const events = data.events.sort((a, b) => new Date(b.event_date) - new Date(a.event_date));
        
        events.forEach(ev => {
            const trid = ev.reference_id || ev.event_id || ev.trid || 'N/A';
            const qty = ev.quantity;
            const qtyDisplay = qty >= 0 ? `+${qty}` : qty;
            const productName = ev.product_name || ev.msku || 'N/A';
            const fc = ev.fulfillment_center || 'N/A';
            
            // Reason mapping (come desktop)
            const reasonMap = {
                'Q': 'SELLABLE (Q)',
                'D': 'DISTRIBUTOR_DAMAGED',
                'E': 'SELLABLE (E)',
                'M': 'WAREHOUSE_DAMAGED',
                'O': 'CARRIER_DAMAGED',
                'P': 'DEFECTIVE (P)'
            };
            const reason = reasonMap[ev.reason] || ev.disposition || ev.reason || 'N/A';
            const eventDate = formatDate(ev.event_date);
            
            html += `
                <div class="reimb-event-item">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                        <div class="reimb-event-date">${eventDate}</div>
                        <div class="reimb-event-qty">(${qtyDisplay})</div>
                    </div>
                    <div class="reimb-event-product">${escapeHtml(productName)}</div>
                    <div class="reimb-event-meta" style="margin-bottom: 4px;">${escapeHtml(fc)} - ${escapeHtml(reason)}</div>
                    <div class="reimb-event-trid">
                        <div class="reimb-trid-number">${escapeHtml(trid)}</div>
                        <button class="copy-btn" onclick="copyTRID('${escapeHtml(trid)}', event)">📋</button>
                    </div>
                </div>
            `;
        });
        
        html += `</div>`;
    });
    
    container.innerHTML = html;
}

// Open Drawer Dettaglio Spedizione
async function openDrawer(shipmentId) {
    document.getElementById('drawer-overlay').classList.add('active');
    document.getElementById('drawer').classList.add('active');
    document.getElementById('drawer-content').innerHTML = '<div class="loading-spinner">Caricamento...</div>';
    
    try {
        const res = await fetch(`/modules/inbound/trid/trid_scanner_api.php?action=shipment_items&shipment_id=${shipmentId}`);
        const json = await res.json();
        
        if (!json.success) {
            document.getElementById('drawer-content').innerHTML = '<div class="empty-state">Errore caricamento</div>';
            return;
        }
        
        const info = json.data.shipment_info;
        const items = json.data.items;
        
        document.getElementById('drawer-title').textContent = info.amazon_shipment_id || info.shipment_name;
        document.getElementById('drawer-subtitle').textContent = `FC: ${info.destination_fc}`;
        
        let html = '';
        items.forEach(item => {
            const missing = item.quantity_shipped - item.quantity_received;
            html += `
                <div class="item-row">
                    <div style="flex: 1;">
                        <div class="item-name">${escapeHtml(item.product_name)}</div>
                        <div class="item-sku">SKU: ${escapeHtml(item.seller_sku)}</div>
                    </div>
                    <div class="item-quantities">
                        <div>Spedite: <strong>${item.quantity_shipped}</strong></div>
                        <div>Ricevute: <strong style="color: var(--success);">${item.quantity_received}</strong></div>
                        ${missing > 0 ? `<div style="color: var(--danger);">Mancanti: <strong>${missing}</strong></div>` : ''}
                    </div>
                </div>
            `;
        });
        
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

function copyTRID(trid, event) {
    event.stopPropagation();
    
    // Copia negli appunti
    navigator.clipboard.writeText(trid).then(() => {
        // Feedback visivo
        const btn = event.target;
        const originalText = btn.textContent;
        btn.textContent = '✅';
        btn.style.background = '#10b981';
        btn.style.color = 'white';
        
        setTimeout(() => {
            btn.textContent = originalText;
            btn.style.background = '';
            btn.style.color = '';
        }, 1000);
    }).catch(err => {
        console.error('Copy failed:', err);
        alert('Errore copia: ' + trid);
    });
}

function toggleReimbMonth(monthId) {
    const container = document.getElementById(monthId);
    if (container) {
        container.classList.toggle('expanded');
    }
}

// Utils
function formatNumber(num) {
    return new Intl.NumberFormat('it-IT').format(num || 0);
}

function formatDate(date) {
    if (!date || date === 'N/A') return 'N/A';
    const d = new Date(date);
    if (isNaN(d.getTime())) return 'N/A';
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

function formatDateShort(date) {
    if (!date || date === 'N/A') return 'N/A';
    const d = new Date(date);
    if (isNaN(d.getTime())) return 'N/A';
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit' });
}

function formatDateTimeFull(date) {
    if (!date || date === 'N/A') return 'N/A';
    const d = new Date(date);
    if (isNaN(d.getTime())) return 'N/A';
    return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' }) + ' ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}

function formatMonthYear(monthStr) {
    // Formato input: "2024-11" o "November 2024"
    if (!monthStr) return 'N/A';
    
    if (monthStr.includes('-')) {
        const [year, month] = monthStr.split('-');
        const d = new Date(year, month - 1);
        if (isNaN(d.getTime())) return monthStr;
        return d.toLocaleDateString('it-IT', { year: 'numeric', month: 'long' });
    }
    
    return monthStr;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Init
loadDashboard();
</script>

</main>

<?php include __DIR__ . '/_partials/mobile_tabbar.php'; ?>

</body>
</html>