<?php
/**
 * Overview Dashboard - Vista principale OrderInsights
 * File: modules/orderinsights/overview.php
 */

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';
require_once 'OverviewModel.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: ../margynomic/login/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];

// Redirect mobile
if (isMobileDevice()) {
    header('Location: /modules/mobile/OrderInsights.php');
    exit;
}

$currentMonth = ''; // Lascia vuoto per caricare tutti i dati all'avvio
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OrderInsights - Overview Mensile</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="orderinsight.css?v=<?= time() ?>">
</head>
<body>
<?php require_once '../margynomic/shared_header.php'; ?>

<div class="story">
        <!-- Hero Welcome -->
        <div class="welcome-hero">
            <div class="welcome-content">
                <h1 class="welcome-title">
                    <i class="fas fa-microscope"></i> OrderInsights AI System
                </h1>
                <p class="welcome-subtitle">
                    ANALIZZA COME VIENE DISTRIBUITO IL TUO FATTURATO!
                </p>
            </div>
            
            <!-- 4 Box Informativi -->
            <div class="info-boxes">
                <div class="info-box-card">
                    <h4>🔄 Lifecycle Ordine</h4>
                    <p>Segui ogni transazione dal click cliente al settlement nel tuo conto.</p>
                </div>
                
                <div class="info-box-card">
                    <h4>💎 Quality Score</h4>
                    <p>Valuta la "qualità" delle vendite: margine, retention, no-return rate.</p>
                </div>
                
                <div class="info-box-card">
                    <h4>🌍 Multi-Marketplace</h4>
                    <p>Confronta performance tra Amazon.it, .de, .fr, .es, .co.uk.</p>
                </div>
                
                <div class="info-box-card">
                    <h4>🎯 Customer Insights</h4>
                    <p>Analisi comportamento acquisto: prime vs non-prime, quantità media.</p>
                </div>
            </div>
        </div>

        <!-- Strategic Flow Grid con KPI -->
        <div class="strategic-flow-section">
            <div class="stats-flow-grid">
                <!-- Card 1: Incassato -->
                <div class="flow-card flow-stage-1">
                    <div class="flow-icon">💰</div>
                    <div class="flow-number"><span id="kpi-incassato">€ 0,00</span></div>
                    <div class="flow-label">Fatturato<br>Totale</div>
                    <div class="flow-description">Ricavi Totali<br>Fatturato Lordo</div>
                    <div class="flow-timeline">Revenue Total</div>
                </div>

                <!-- Card 2: Commissioni -->
                <div class="flow-card flow-stage-2">
                    <div class="flow-icon">💸</div>
                    <div class="flow-number"><span id="kpi-commissioni">€ 0,00</span></div>
                    <div class="flow-label">Commissioni<br>Amazon</div>
                    <div class="flow-description">Fee Amazon<br>Trattenute</div>
                    <div class="flow-timeline">Amazon Fees</div>
                </div>

                <!-- Card 3: Costi Operativi -->
                <div class="flow-card flow-stage-3">
                    <div class="flow-icon">🏢</div>
                    <div class="flow-number"><span id="kpi-operativi">€ 0,00</span></div>
                    <div class="flow-label">Costi<br>Operativi</div>
                    <div class="flow-description">Storage & Altro<br>Subscription</div>
                    <div class="flow-timeline">Operational</div>
                </div>

                <!-- Card 4: Perdite/Danni -->
                <div class="flow-card flow-stage-4">
                    <div class="flow-icon">⚠️</div>
                    <div class="flow-number"><span id="kpi-perdite">€ 0,00</span></div>
                    <div class="flow-label">Perdite<br>Danni</div>
                    <div class="flow-description">Smarrimenti<br>Warehouse Lost</div>
                    <div class="flow-timeline">Losses</div>
                </div>

                <!-- Card 5: Ordini -->
                <div class="flow-card flow-stage-5">
                    <div class="flow-icon">🛒</div>
                    <div class="flow-number"><span id="kpi-ordini">Pz 0</span></div>
                    <div class="flow-label">Ordini<br>Evasi</div>
                    <div class="flow-description">Totale Ordini<br>Processati</div>
                    <div class="flow-timeline">Orders Total</div>
                </div>

                <!-- Card 6: Unità Vendute -->
                <div class="flow-card flow-stage-6">
                    <div class="flow-icon">📦</div>
                    <div class="flow-number"><span id="kpi-vendute">Pz 0</span></div>
                    <div class="flow-label">Unità<br>Vendute</div>
                    <div class="flow-description">Prodotti Spediti<br>Delivered</div>
                    <div class="flow-timeline">Units Sold</div>
                </div>

                <!-- Card 7: Unità Rimborsate -->
                <div class="flow-card flow-stage-7">
                    <div class="flow-icon">🔴</div>
                    <div class="flow-number"><span id="kpi-rimborsate">Pz 0</span></div>
                    <div class="flow-label">Unità<br>Rimborsate</div>
                    <div class="flow-description">Resi & Refund<br>Returns</div>
                    <div class="flow-timeline">Refunded</div>
                </div>
            </div>
        </div>
        
        <!-- Filtri -->
        <div class="filters">
            <h3 style="color: #1a202c; margin-bottom: 0.5rem;">🔍 Filtra Periodo</h3>
            <div id="auto-load-info" style="background: rgba(251, 191, 36, 0.1); border: 1px solid rgba(251, 191, 36, 0.3); color: #d97706; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; display: none;">
                <i class="fas fa-info-circle"></i> <strong>Caricamento automatico:</strong> Visualizzando tutti i dati disponibili. Usa i filtri sotto per restringere il periodo.
            </div>
            <div class="filter-row">
                <div class="filter-group">
                    <label for="month-filter">
                        <i class="fas fa-calendar-alt"></i> Mese
                    </label>
                    <input type="month" id="month-filter" value="<?= $currentMonth ?>">
                </div>
                <div class="filter-group">
                    <label for="start-date">
                        <i class="fas fa-calendar-day"></i> Data Inizio (opzionale)
                    </label>
                    <input type="date" id="start-date">
                </div>
                <div class="filter-group">
                    <label for="end-date">
                        <i class="fas fa-calendar-day"></i> Data Fine (opzionale)
                    </label>
                    <input type="date" id="end-date">
                </div>
                <div class="filter-group" style="display: flex; align-items: flex-end;">
                    <button class="btn btn-primary" onclick="loadOverview()" style="width: 100%;">
                        <i class="fas fa-search"></i> Carica Dati
                    </button>
                </div>
            </div>
            <div style="margin-top: 1rem; display: flex; gap: 1rem; align-items: center;">
                <button class="btn btn-secondary" onclick="resetFilters()" style="padding: 0.5rem 1rem;">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </div>

        <!-- Alert conversione EUR -->
        <div id="eur-warning" class="warning-box" style="display: none;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Attenzione:</strong> alcuni importi non sono in EUR. Conversione non garantita.
        </div>

        <!-- Loading -->
        <div id="loading" style="display: none; text-align: center; padding: 3rem;">
            <i class="fas fa-spinner fa-spin" style="font-size: 3rem; color: #fbbf24;"></i>
            <h3 style="color: #1a202c; margin-top: 1rem;">Caricamento dati...</h3>
            <p style="color: #718096;">Elaborazione in corso</p>
        </div>

        <!-- SEZIONE 1: Ricavi -->
        <div class="section" id="section-1" style="display: none;">
            <div class="section-header" onclick="toggleSection(1)">
                <div class="section-number">1</div>
                <div class="section-title">
                    <h2>💰 Quanto hai guadagnato?</h2>
                    <p>I tuoi ricavi lordi dalle vendite Amazon</p>
                </div>
                <div class="toggle-icon open">▼</div>
            </div>
            
            <div class="section-content" id="section-1-content">
                <div class="highlight" style="background: linear-gradient(135deg, #f97316, #fb923c);">
                    <div class="highlight-value" id="section1-ricavi">€ 0,00</div>
                    <div class="highlight-label">Incassato Vendite</div>
                    <div class="highlight-context">
                        Da <strong><span id="section1-ordini">0</span> ordini</strong> con <strong><span id="section1-unita">0</span> unità</strong> spedite in totale
                    </div>
                </div>
                
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="kpi-value" id="section1-ordini2">0</div>
                        <div class="kpi-label">Ordini</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" id="kpi-transazioni">0</div>
                        <div class="kpi-label">Transazioni</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" id="section1-vendute">0</div>
                        <div class="kpi-label">Unità Vendute</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-value" id="section1-rimborsate">0</div>
                        <div class="kpi-label">Unità Rimborsate</div>
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;">
                    <div id="fee-components-month"></div>
                    <div id="fba-vs-principal-month"></div>
                </div>
            </div>
        </div>
        
        <!-- SEZIONE 2: Costi Amazon -->
        <div class="section" id="section-2" style="display: none;">
            <div class="section-header" onclick="toggleSection(2)">
                <div class="section-number">2</div>
                <div class="section-title">
                    <h2>💸 Quanto è costato Amazon?</h2>
                    <p>Fee trattenute per vendita e logistica</p>
                </div>
                <div class="toggle-icon open">▼</div>
            </div>
            
            <div class="section-content" id="section-2-content">
                <div class="highlight" style="background: linear-gradient(135deg, #dc2626, #ef4444);">
                    <div class="highlight-value" id="section2-commissioni">€ 0,00</div>
                    <div class="highlight-label">Commissioni Totali</div>
                    <div class="highlight-context">
                        Amazon ha trattenuto <strong><span id="section2-percent">0</span>%</strong> del tuo fatturato lordo
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;" id="fee-breakdown-section2"></div>
            </div>
        </div>
        
        <!-- SEZIONE 3: Altri Costi -->
        <div class="section" id="section-3" style="display: none;">
            <div class="section-header" onclick="toggleSection(3)">
                <div class="section-number">3</div>
                <div class="section-title">
                    <h2>🏢 Altri costi operativi</h2>
                    <p>Storage, subscription e costi accessori</p>
                </div>
                <div class="toggle-icon open">▼</div>
            </div>
            
            <div class="section-content" id="section-3-content">
                <div class="highlight" style="background: linear-gradient(135deg, #f59e0b, #fbbf24);">
                    <div class="highlight-value" id="section3-operativi">€ 0,00</div>
                    <div class="highlight-label">Costi Operativi</div>
                    <div class="highlight-context">
                        Abbonamenti, storage e altri costi accessori
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;" id="breakdown-operativi"></div>
            </div>
        </div>
        
        <!-- SEZIONE 4: Perdite e Rimborsi/Danni -->
        <div class="section" id="section-4">
            <div class="section-header" onclick="toggleSection(4)">
                <div class="section-number">4</div>
                <div class="section-title">
                    <h2>⚠️ Perdite e Rimborsi/Danni</h2>
                    <p>Rimborsi da Amazon per danni e perdite in magazzino</p>
                </div>
                <div class="toggle-icon">▼</div>
            </div>
            
            <div class="section-content" id="section-4-content">
                <div class="highlight" style="background: linear-gradient(135deg, #3b82f6, #60a5fa);">
                    <div class="highlight-value" id="section4-perdite">€ 0</div>
                    <div class="highlight-label">Totale Perdite/Danni</div>
                    <div class="highlight-context">
                        Rimborsi da Amazon per danni e perdite in magazzino
                    </div>
                </div>
                
                <div style="margin-top: 1.5rem;" id="breakdown-perdite"></div>
            </div>
        </div>
        
        <!-- SEZIONE 5: Risultato -->
        <div class="section" id="section-5" style="display: none;">
            <div class="section-header">
                <div class="section-number">✓</div>
                <div class="section-title">
                    <h2>🎯 Il tuo risultato finale</h2>
                    <p>Quanto hai guadagnato realmente</p>
                </div>
            </div>
            
            <div class="section-content" id="section-5-content">
                <div class="highlight" style="background: linear-gradient(135deg, #43e97b, #38f9d7);">
                    <div class="highlight-value" id="kpi-netto">€ 0,00</div>
                    <div class="highlight-label">Netto Operativo</div>
                    <div class="highlight-context">
                        <p>Questo è il guadagno effettivo dopo tutti i costi</p>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 1rem;">
                            <div>
                                <div style="font-size: 1.5rem; font-weight: bold;" id="result-ricavi">€ 0</div>
                                <div style="font-size: 0.9rem;">Incassato</div>
                            </div>
                            <div>
                                <div style="font-size: 1.5rem; font-weight: bold;" id="result-costi">€ 0</div>
                                <div style="font-size: 0.9rem;">Costi Totali</div>
                            </div>
                            <div>
                                <div style="font-size: 1.5rem; font-weight: bold;" id="result-margine">0%</div>
                                <div style="font-size: 0.9rem;">Margine</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Warning Costo Materia Prima -->
                <div id="materia-prima-warning" style="display: none;"></div>
            </div>
        </div>
        
        <!-- SEZIONE 6: Giorni -->
        <div class="section" id="section-6">
            <div class="section-header">
                <div class="section-number">📅</div>
                <div class="section-title">
                    <h2>Andamento giornaliero</h2>
                    <p>Performance day-by-day del periodo</p>
                </div>
            </div>
            
            <div class="section-content" id="section-6-content">
                <div class="info-box">
                    <strong>💡 Suggerimento:</strong> Clicca su un giorno per vedere il dettaglio completo delle transazioni
                </div>
                <div class="day-grid" id="days-grid"></div>
                <div style="text-align: center; margin-top: 1rem;">
                    <button id="btn-load-more" class="btn btn-primary" onclick="loadMoreDays()">Carica altri giorni</button>
                </div>
            </div>
        </div>

        <!-- Container nascosti per retrocompatibilità backend -->
        <div id="monthly-kpi" style="display: none;"></div>
        <div id="monthly-categories" style="display: none;"></div>
        <div id="daily-breakdown" style="display: none;"></div>
        <div id="reserve-funds" style="display: none;"></div>

        <!-- Modal Breakdown Transaction Types -->
        <div id="breakdown-modal" class="modal">
            <div class="modal-content">
                <button class="modal-close" onclick="closeBreakdownModal()">×</button>
                <h2 style="margin-bottom: 1.5rem;" id="breakdown-title">Breakdown</h2>
                <div id="breakdown-content"></div>
            </div>
        </div>

        <!-- Modal Ordini del Giorno -->
        <div id="orders-modal" class="modal">
            <div class="modal-content">
                <button class="modal-close" onclick="closeOrdersModal()">×</button>
                <h2 style="margin-bottom: 1.5rem;" id="orders-title">Ordini</h2>
                <div id="orders-content"></div>
            </div>
        </div>

        <!-- Modal Dettaglio Ordine -->
        <div id="order-detail-modal" class="modal">
            <div class="modal-content">
                <button class="modal-close" onclick="closeOrderDetailModal()">×</button>
                <h2 style="margin-bottom: 1.5rem;" id="order-detail-title">Dettaglio Ordine</h2>
                <div id="order-detail-content"></div>
            </div>
        </div>
    </div>

<script src="orderinsight.js?v=<?= time() ?>"></script>
</body>
</html>