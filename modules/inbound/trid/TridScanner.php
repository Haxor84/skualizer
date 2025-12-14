<?php
/**
 * TridScanner - Tracking Inventario Amazon (Customer Version)
 * File: modules/margynomic/TridScanner.php
 * 
 * Dashboard TRID per utenti finali con tema rosa Margynomic
 * Funzionalità: visualizzazione spedizioni, KPI, rimborsi (NO download admin)
 */

// DEBUG: Attiva visualizzazione errori
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once __DIR__ . '/../../margynomic/config/config.php';
require_once __DIR__ . '/../../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../../listing/helpers.php';

// Verifica autenticazione
if (!isLoggedIn()) {
    header('Location: ../../margynomic/login/login.php');
    exit();
}

$currentUser = getCurrentUser();
$userId = $currentUser['id'];
$isAdmin = false; // Sempre false per TridScanner

// Redirect mobile
if (isMobileDevice()) {
    header('Location: /modules/mobile/TridScanner.php');
    exit;
}

require_once __DIR__ . '/../../margynomic/shared_header.php';
?>

<!-- FontAwesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="TridScanner.css">

<div class="tridscanner-container">
    
    <!-- HEADER -->
    <div class="welcome-hero">
        <div class="welcome-content">
            <h1 class="welcome-title">🔍 TridScanner</h1>
            <p class="welcome-subtitle">Tracking completo del tuo inventario Amazon</p>
        </div>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-top: 2rem;">
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
            <div style="background: rgba(236, 72, 153, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #ec4899;">
                <h4 style="color: #ec4899; font-weight: 700; margin-bottom: 0.5rem;">📦 Tracking Spedizioni</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Monitora in tempo reale tutte le tue spedizioni FBA con dettaglio unità ricevute e warehouse di destinazione.</p>
            </div>
            
            <div style="background: rgba(236, 72, 153, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #ec4899;">
                <h4 style="color: #ec4899; font-weight: 700; margin-bottom: 0.5rem;">💰 Rimborsi TRID</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Identifica unità perse o danneggiate da Amazon con TRID pronti per richiedere il rimborso.</p>
            </div>
            
            <div style="background: rgba(236, 72, 153, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #ec4899;">
                <h4 style="color: #ec4899; font-weight: 700; margin-bottom: 0.5rem;">📊 KPI Inventario</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Analizza unità spedite, vendute, ritirate e giacenza per avere il controllo completo del tuo stock.</p>
            </div>
            
            <div style="background: rgba(236, 72, 153, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #ec4899;">
                <h4 style="color: #ec4899; font-weight: 700; margin-bottom: 0.5rem;">🎯 Report Ledger</h4>
                <p style="color: #64748b; line-height: 1.6; margin: 0;">Integrazione automatica con Amazon Ledger per dati sempre aggiornati e affidabili.</p>
            </div>
        </div>
    </div>
    
    <!-- KPI CARDS GRID -->
    <div class="kpi-grid" id="kpi-grid">
        <div class="kpi-card kpi-primary">
            <div class="kpi-icon">📤</div>
            <div class="kpi-value" id="kpi-unita-spedite">0</div>
            <div class="kpi-label">Unità Spedite</div>
            <div class="kpi-sublabel">quantity_shipped</div>
        </div>
        
        <div class="kpi-card kpi-success">
            <div class="kpi-icon">✅</div>
            <div class="kpi-value" id="kpi-unita-ricevute">0</div>
            <div class="kpi-label">Unità Ricevute</div>
            <div class="kpi-sublabel">quantity_received</div>
        </div>
        
        <div class="kpi-card kpi-warning">
            <div class="kpi-icon">🔄</div>
            <div class="kpi-value" id="kpi-unita-ritirate">0</div>
            <div class="kpi-label">Unità Ritirate</div>
            <div class="kpi-sublabel">removal_orders</div>
        </div>
        
        <div class="kpi-card kpi-info">
            <div class="kpi-icon">🛒</div>
            <div class="kpi-value" id="kpi-unita-vendute">0</div>
            <div class="kpi-label">Unità Vendute</div>
            <div class="kpi-sublabel">nette</div>
        </div>
        
        <div class="kpi-card kpi-transfer">
            <div class="kpi-icon">↩️</div>
            <div class="kpi-value" id="kpi-resi-clienti">0</div>
            <div class="kpi-label">Resi Clienti</div>
            <div class="kpi-sublabel">refund</div>
        </div>
        
        <div class="kpi-card kpi-giacenza">
            <div class="kpi-icon">📦</div>
            <div class="kpi-value" id="kpi-giacenza">0</div>
            <div class="kpi-label">Giacenza Attuale</div>
            <div class="kpi-sublabel">inventory</div>
        </div>
    </div>
    
    <!-- BANNER UNITÀ SPARITE -->
    <div class="unita-sparite-banner" id="unita-sparite-banner" style="display: none;">
        <div class="banner-icon">⚠️</div>
        <div class="banner-content">
            <div class="banner-title">Unità Non Contabilizzate</div>
            <div class="banner-formula">
                <span id="formula-spedite">0</span> <small>(spedite)</small>
                - <span id="formula-vendute">0</span> <small>(vendute)</small>
                - <span id="formula-ritirate">0</span> <small>(ritirate)</small>
                - <span id="formula-resi">0</span> <small>(resi clienti)</small>
                - <span id="formula-giacenza">0</span> <small>(giacenza)</small>
                = <strong id="formula-totale" class="risultato-sparite">0</strong>
            </div>
            <div class="banner-rimborsi" id="banner-rimborsi">
                Su <strong id="unita-totali-non-cont">0</strong> unità non contabilizzate, 
                sono state <strong>rimborsate <span id="unita-rimborsate-banner">0</span></strong>, 
                quindi <strong class="unita-da-recuperare">devi recuperare ancora <span id="unita-da-recuperare">0</span> unità</strong>.
            </div>
            <div class="banner-explanation">
                <strong>Cosa significa?</strong> Questo calcolo mostra la differenza tra le unità che hai spedito ad Amazon 
                e quelle effettivamente contabilizzate (vendute, ritirate, rimborsate ai clienti, o in giacenza). 
                Le unità "da recuperare" rappresentano prodotti che Amazon dovrebbe rimborsarti perché persi, danneggiati o non ricevuti. 
                Verifica la sezione "Rimborsi da Richiedere" per creare eventuali ticket TRID e recuperare questi importi.
            </div>
        </div>
    </div>
    
    <!-- DASHBOARD GRID -->
    <div class="dashboard-grid">
        <!-- LEFT: Spedizioni -->
        <div class="dashboard-column">
            <div class="column-header">
                <h2>📦 Spedizioni Ricevute</h2>
                <div class="summary" id="shipments-summary">Caricamento...</div>
            </div>
            <div class="column-content" id="shipments-list">
                <div style="text-align: center; padding: 2rem;">
                    <div class="spinner"></div>
                    <p>Caricamento spedizioni...</p>
                </div>
            </div>
        </div>
        
        <!-- RIGHT: Rimborsi -->
        <div class="dashboard-column">
            <div class="column-header">
                <h2>💰 Rimborsi da Richiedere</h2>
                <div class="summary" id="reimbursements-summary">Caricamento...</div>
            </div>
            <div class="column-content" id="reimbursements-list">
                <div style="text-align: center; padding: 2rem;">
                    <div class="spinner"></div>
                    <p>Caricamento rimborsi...</p>
                </div>
            </div>
        </div>
    </div>
    
</div>

<script>
// ============================================
// TRIDSCANNER DASHBOARD
// ============================================

const userId = <?= $userId ?>;

// Auto-load dashboard
if (document.getElementById('shipments-list')) {
    loadDashboard();
}

function loadDashboard() {
    fetch(`trid_scanner_api.php?action=load_dashboard`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderDashboard(data.data);
            } else {
                console.error('Dashboard error:', data.error);
                alert('❌ Errore caricamento dashboard: ' + (data.error || 'Errore sconosciuto'));
            }
        })
        .catch(err => {
            console.error('Dashboard load error:', err);
            alert('❌ Errore di connessione. Verifica che l\'API sia configurata correttamente.');
        });
}

function renderDashboard(data) {
    console.log('📊 Dashboard data:', data);
    renderKpiCards(data.kpi || {});
    renderShipmentsList(data.shipments || [], data.shipments_stats || {});
    renderReimbursementsList(data.reimbursements_by_month || {}, data.total_reimbursements || 0);
}

function renderKpiCards(kpi) {
    console.log('📈 KPI data:', kpi);
    
    // Popola KPI cards
    document.getElementById('kpi-unita-spedite').textContent = formatNumber(kpi.unita_spedite || 0);
    document.getElementById('kpi-unita-ricevute').textContent = formatNumber(kpi.unita_ricevute || 0);
    document.getElementById('kpi-unita-ritirate').textContent = formatNumber(kpi.unita_ritirate || 0);
    document.getElementById('kpi-unita-vendute').textContent = formatNumber(kpi.unita_vendute || 0);
    document.getElementById('kpi-resi-clienti').textContent = formatNumber(kpi.resi_clienti || 0);
    document.getElementById('kpi-giacenza').textContent = formatNumber(kpi.giacenza || 0);
    
    // Calcolo unità sparite
    const spedite = kpi.unita_spedite || 0;
    const ricevute = kpi.unita_ricevute || 0;
    const vendute = kpi.unita_vendute || 0;
    const ritirate = kpi.unita_ritirate || 0;
    const resi = kpi.resi_clienti || 0;
    const giacenza = kpi.giacenza || 0;
    const rimborsate = kpi.unita_rimborsate || 0;
    
    const unitaNonContabilizzate = spedite - vendute - ritirate - resi - giacenza;
    const unitaDaRecuperare = unitaNonContabilizzate - rimborsate;
    
    console.log(`🧮 Calcolo: ${spedite} - ${vendute} - ${ritirate} - ${resi} - ${giacenza} = ${unitaNonContabilizzate}`);
    console.log(`💰 Da recuperare: ${unitaNonContabilizzate} - ${rimborsate} = ${unitaDaRecuperare}`);
    
    if (unitaNonContabilizzate > 0) {
        document.getElementById('unita-sparite-banner').style.display = 'flex';
        document.getElementById('formula-spedite').textContent = formatNumber(spedite);
        document.getElementById('formula-vendute').textContent = formatNumber(vendute);
        document.getElementById('formula-ritirate').textContent = formatNumber(ritirate);
        document.getElementById('formula-resi').textContent = formatNumber(resi);
        document.getElementById('formula-giacenza').textContent = formatNumber(giacenza);
        document.getElementById('formula-totale').textContent = formatNumber(unitaNonContabilizzate);
        document.getElementById('unita-totali-non-cont').textContent = formatNumber(unitaNonContabilizzate);
        document.getElementById('unita-rimborsate-banner').textContent = formatNumber(rimborsate);
        document.getElementById('unita-da-recuperare').textContent = formatNumber(Math.max(0, unitaDaRecuperare));
    }
}

function renderShipmentsList(shipments, stats) {
    const totalQty = shipments.reduce((sum, s) => sum + parseInt(s.qty_received || 0), 0);
    const totalShipped = shipments.reduce((sum, s) => sum + parseInt(s.qty_shipped || 0), 0);
    document.getElementById('shipments-summary').textContent = 
        `${shipments.length} spedizioni • ${totalShipped} inviate • ${totalQty} ricevute`;
    
    const container = document.getElementById('shipments-list');
    
    if (shipments.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #6b7280;">
                📭 Nessuna spedizione trovata
            </div>
        `;
        return;
    }
    
    container.innerHTML = shipments.map(s => `
        <div class="shipment-card" onclick="openShipmentDetails(${s.id})">
            <div class="shipment-header">
                <code class="shipment-id">${escapeHtml(s.amazon_shipment_id)}</code>
                <div class="shipment-qty-group">
                    <span class="shipment-qty-shipped">${s.qty_shipped || 0}</span>
                    <span class="shipment-qty-arrow">→</span>
                    <span class="shipment-qty-received">${s.qty_received || 0}</span>
                </div>
            </div>
            <div class="shipment-name">${escapeHtml(s.shipment_name)}</div>
            <div class="shipment-meta">
                <span>📅 ${formatDate(s.receipt_date)}</span>
                <span>📍 ${escapeHtml(s.warehouses || s.destination_fc || 'N/A')}</span>
                <span>${s.trid_events || 0} eventi</span>
            </div>
        </div>
    `).join('');
}

// Open shipment modal
async function openShipmentDetails(shipmentId) {
    try {
        const response = await fetch(`trid_scanner_api.php?action=shipment_items&shipment_id=${shipmentId}`);
        const result = await response.json();
        
        if (!result.success) {
            alert('❌ Errore: ' + (result.error || 'Errore sconosciuto'));
            return;
        }
        
        const data = result.data;
        const shipment = data.shipment_info;
        const items = data.items;
        
        // Crea modal HTML
        const modalHtml = `
            <div class="modal-overlay" onclick="closeShipmentModal(event)">
                <div class="modal-content" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <div>
                            <h2>📦 ${escapeHtml(shipment.amazon_shipment_id)}</h2>
                            <p style="color: #6b7280; margin-top: 0.5rem;">${escapeHtml(shipment.shipment_name)}</p>
                        </div>
                        <button class="modal-close" onclick="closeShipmentModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <h3 style="margin-bottom: 1rem; color: #374151;">Prodotti Spediti</h3>
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Prodotto</th>
                                    <th>Seller SKU</th>
                                    <th style="text-align: center;">Inviate</th>
                                    <th style="text-align: center;">Ricevute</th>
                                    <th style="text-align: center;">Differenza</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${items.map(item => {
                                    const diff = (item.quantity_received || 0) - (item.quantity_shipped || 0);
                                    const diffClass = diff < 0 ? 'diff-negative' : diff > 0 ? 'diff-positive' : 'diff-zero';
                                    return `
                                        <tr>
                                            <td><strong>${escapeHtml(item.product_name)}</strong></td>
                                            <td><code>${escapeHtml(item.seller_sku || 'N/A')}</code></td>
                                            <td style="text-align: center;">${item.quantity_shipped || 0}</td>
                                            <td style="text-align: center;">${item.quantity_received || 0}</td>
                                            <td style="text-align: center;">
                                                <span class="${diffClass}">${diff > 0 ? '+' : ''}${diff}</span>
                                            </td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        document.body.style.overflow = 'hidden';
        
    } catch (error) {
        console.error('Error loading shipment details:', error);
        alert('❌ Errore caricamento dettagli spedizione');
    }
}

function closeShipmentModal(event) {
    if (event && event.target.className !== 'modal-overlay' && event.target.className !== 'modal-close') {
        return;
    }
    
    const modal = document.querySelector('.modal-overlay');
    if (modal) {
        modal.remove();
        document.body.style.overflow = '';
    }
}

function renderReimbursementsList(reimbursementsByMonth, totalCount) {
    const container = document.getElementById('reimbursements-list');
    
    // Update summary
    document.getElementById('reimbursements-summary').textContent = `${formatNumber(totalCount)} TRID disponibili`;
    
    if (!reimbursementsByMonth || Object.keys(reimbursementsByMonth).length === 0) {
        container.innerHTML = '<p style="color: #10b981; text-align: center; padding: 2rem;">✅ Nessun rimborso da richiedere!</p>';
        return;
    }
    
    const months = Object.keys(reimbursementsByMonth).sort().reverse();
    
    container.innerHTML = months.map(month => {
        const data = reimbursementsByMonth[month];
        
        return `
            <div class="month-section">
                <div class="month-header" onclick="toggleReimbursements('${month}')">
                    <div>
                        <h3>📅 ${formatMonth(month)}</h3>
                        <div style="color: #6b7280; font-size: 0.875rem;">
                            ${data.count} TRID • ${data.total_qty} unità
                        </div>
                    </div>
                    <span class="expand-icon" id="expand-${month}">▼</span>
                </div>
                <div class="reimbursements-content" id="reimb-${month}" style="display: block;">
                    ${data.events.map(evt => renderReimbursementItem(evt)).join('')}
                </div>
            </div>
        `;
    }).join('');
}

function renderReimbursementItem(evt) {
    const reasonMap = {
        'Q': 'SELLABLE (Q)',
        'D': 'DISTRIBUTOR_DAMAGED',
        'E': 'SELLABLE (E)',
        'M': 'WAREHOUSE_DAMAGED',
        'O': 'CARRIER_DAMAGED',
        'P': 'DEFECTIVE (P)'
    };
    
    const productName = evt.product_name || evt.msku;
    
    return `
        <div class="reimbursement-item">
            <div class="reimb-header">
                <div class="reimb-date">${formatDate(evt.event_date)}</div>
                <div class="reimb-qty">${evt.quantity < 0 ? '' : '+'}${evt.quantity}</div>
            </div>
            <div class="reimb-product">${escapeHtml(productName)}</div>
            <div class="reimb-meta">
                <span class="badge-fc">${escapeHtml(evt.fulfillment_center)}</span>
                <span class="badge-reason">${reasonMap[evt.reason] || evt.disposition}</span>
            </div>
            <div class="reimb-trid">
                <code>${escapeHtml(evt.reference_id)}</code>
                <button class="btn-copy-mini" onclick="event.stopPropagation(); copyTrid('${evt.reference_id}')">
                    📋
                </button>
            </div>
        </div>
    `;
}

function toggleReimbursements(month) {
    const content = document.getElementById(`reimb-${month}`);
    const icon = document.getElementById(`expand-${month}`);
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.textContent = '▼';
    } else {
        content.style.display = 'none';
        icon.textContent = '▶';
    }
}

function copyTrid(trid) {
    navigator.clipboard.writeText(trid).then(() => {
        alert(`✅ TRID copiato: ${trid}`);
    }).catch(err => {
        console.error('Errore copia:', err);
        alert('❌ Errore nella copia del TRID');
    });
}

// Utility functions
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatNumber(num) {
    return new Intl.NumberFormat('it-IT').format(num);
}

function formatDate(dateStr) {
    if (!dateStr) return 'N/A';
    const d = new Date(dateStr);
    return d.toLocaleDateString('it-IT');
}

function formatMonth(monthStr) {
    const [year, month] = monthStr.split('-');
    const monthNames = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
    return `${monthNames[parseInt(month) - 1]} ${year}`;
}
</script>

</body>
</html>