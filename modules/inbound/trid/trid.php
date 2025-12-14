<?php
/**
 * TRID - Tracking Inventario Amazon
 * UI per visualizzazione spedizioni e timeline prodotti
 */

session_start();
require_once __DIR__ . '/../../margynomic/config/config.php';
require_once __DIR__ . '/../../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../../margynomic/admin/admin_helpers.php';

// Check authentication - SOLO ADMIN
if (!isAdminLogged()) {
    header('Location: ../../margynomic/admin/admin_login.php');
    exit;
}

// Admin è sempre loggato, usa user_id selezionato o da GET
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['trid_user'] ?? 2);
$_SESSION['trid_user'] = $selectedUserId;

$userId = $selectedUserId;
$isAdmin = true;
$db = getDbConnection();

// Get user info
$stmt = $db->prepare("SELECT id, nome, email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$selectedUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$selectedUser) {
    $selectedUser = ['id' => $userId, 'nome' => 'Unknown', 'email' => 'N/A'];
}

// Get available users
$availableUsers = $db->query("
    SELECT DISTINCT u.id, u.nome, u.email 
    FROM users u
    INNER JOIN amazon_client_tokens t ON t.user_id = u.id
    WHERE u.is_active = 1 AND t.is_active = 1
    ORDER BY u.nome
")->fetchAll(PDO::FETCH_ASSOC);

// SOLO VISTA DASHBOARD - Dettaglio spedizioni via popup
$viewMode = 'list';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TRID - Tracking Inventario</title>
    <link rel="stylesheet" href="../inbound.css">
    <link rel="stylesheet" href="trid.css">
</head>
<body>

<?php echo getAdminNavigation('trid'); ?>

<div class="trid-container">
    
    <!-- USER SELECTOR -->
    <div class="user-selector-card">
        <form method="GET" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 250px;">
                <label for="user_id" style="font-weight: 600; margin-bottom: 0.5rem; display: block;">
                    👤 Seleziona Utente:
                </label>
                <select name="user_id" id="user_id" onchange="this.form.submit()" style="width: 100%; padding: 0.5rem; border-radius: 6px; border: 2px solid #e5e7eb;">
                    <?php foreach ($availableUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['nome']) ?> (ID: <?= $u['id'] ?>) - <?= htmlspecialchars($u['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="color: #6b7280;">
                <strong>Utente corrente:</strong> <?= htmlspecialchars($selectedUser['nome']) ?> (ID: <?= $userId ?>)
            </div>
        </form>
    </div>
    
    <?php if ($viewMode === 'list'): ?>
        <!-- DASHBOARD GLOBALE TRID -->
        <div class="trid-header">
            <h1><i class="fas fa-search"></i> TRID - Tracking Inventario Amazon</h1>
            
            <?php if ($isAdmin): ?>
            <button onclick="openDownloadModal()" class="btn-download">
                📥 Scarica Report Ledger
            </button>
            <?php endif; ?>
        </div>
        
        <!-- KPI CARDS ROW -->
        <div class="kpi-row" id="kpi-row">
            <div class="kpi-card kpi-primary">
                <div class="kpi-icon">📤</div>
                <div class="kpi-content">
                    <div class="kpi-value" id="kpi-unita-spedite">0</div>
                    <div class="kpi-label">Unità Spedite</div>
                    <div class="kpi-subtitle">quantity_shipped</div>
                </div>
            </div>
            
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">✅</div>
                <div class="kpi-content">
                    <div class="kpi-value" id="kpi-unita-ricevute">0</div>
                    <div class="kpi-label">Unità Ricevute</div>
                    <div class="kpi-subtitle">quantity_received</div>
                </div>
            </div>
            
            <div class="kpi-card kpi-warning">
                <div class="kpi-icon">🔄</div>
                <div class="kpi-content">
                    <div class="kpi-value" id="kpi-unita-ritirate">0</div>
                    <div class="kpi-label">Unità Ritirate</div>
                    <div class="kpi-subtitle">removal_orders</div>
                </div>
            </div>
            
            <div class="kpi-card kpi-info">
                <div class="kpi-icon">🛒</div>
                <div class="kpi-content">
                    <div class="kpi-value" id="kpi-unita-vendute">0</div>
                    <div class="kpi-label">Unità Vendute</div>
                    <div class="kpi-subtitle">nette</div>
                </div>
            </div>
            
            <div class="kpi-card kpi-transfer">
                <div class="kpi-icon">↩️</div>
                <div class="kpi-content">
                    <div class="kpi-value" id="kpi-resi-clienti">0</div>
                    <div class="kpi-label">Resi Clienti</div>
                    <div class="kpi-subtitle">refund</div>
                </div>
            </div>
            
            <div class="kpi-card kpi-success">
                <div class="kpi-icon">📦</div>
                <div class="kpi-content">
                    <div class="kpi-value" id="kpi-giacenza">0</div>
                    <div class="kpi-label">Giacenza Attuale</div>
                    <div class="kpi-subtitle">inventory</div>
                </div>
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
        
        <!-- TWO COLUMN GRID -->
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
        
    <?php endif; ?>
    
</div>

<!-- MODAL DOWNLOAD (solo admin) -->
<?php if ($isAdmin): ?>
<div id="download-modal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeDownloadModal()">&times;</span>
        <h2>📥 Scarica Report Ledger Amazon</h2>
        
        <form id="download-form">
            <div class="form-group">
                <label for="start_date">Data Inizio:</label>
                <input type="date" id="start_date" name="start_date" 
                       value="<?= date('Y-m-d', strtotime('-18 months')) ?>" required>
            </div>
            
            <div class="form-group">
                <label for="end_date">Data Fine:</label>
                <input type="date" id="end_date" name="end_date" 
                       value="<?= date('Y-m-d') ?>" required>
            </div>
            
            <button type="submit" id="download-btn" class="btn-primary">
                🚀 Scarica e Importa
            </button>
        </form>
        
        <div id="download-progress" style="display: none;">
            <div class="progress-bar">
                <div class="progress-bar-fill" id="progress-fill"></div>
            </div>
            <div id="progress-text">In corso...</div>
        </div>
        
        <div id="download-result"></div>
    </div>
</div>
<?php endif; ?>

<script>
const userId = <?= $userId ?>;

// Load data on page load
document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
});

// Load dashboard data
async function loadDashboard() {
    try {
        const response = await fetch(`trid_api.php?action=load_dashboard&user_id=${userId}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error);
        }
        
        const data = result.data;
        renderKpiCards(data.kpi);
        renderShipmentsList(data.shipments, data.kpi);
        renderReimbursementsList(data.reimbursements_by_month, data.total_reimbursements);
    } catch (error) {
        console.error('Dashboard load error:', error);
        document.getElementById('shipments-list').innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #ef4444;">
                ❌ Errore: ${error.message}
            </div>
        `;
    }
}

// Render KPI Cards
function renderKpiCards(kpi) {
    // 1. Unità Spedite
    const unitaSpedite = kpi.unita_spedite || 0;
    document.getElementById('kpi-unita-spedite').textContent = formatNumber(unitaSpedite);
    
    // 2. Unità Ricevute
    const unitaRicevute = kpi.unita_ricevute || 0;
    document.getElementById('kpi-unita-ricevute').textContent = formatNumber(unitaRicevute);
    
    // 3. Unità Ritirate
    const unitaRitirate = kpi.unita_ritirate || 0;
    document.getElementById('kpi-unita-ritirate').textContent = formatNumber(unitaRitirate);
    
    // 4. Unità Vendute
    const unitaVendute = kpi.unita_vendute || 0;
    document.getElementById('kpi-unita-vendute').textContent = formatNumber(unitaVendute);
    
    // 5. Resi Clienti
    const resiClienti = kpi.resi_clienti || 0;
    document.getElementById('kpi-resi-clienti').textContent = formatNumber(resiClienti);
    
    // 6. Giacenza Attuale
    const giacenza = kpi.giacenza || 0;
    document.getElementById('kpi-giacenza').textContent = formatNumber(giacenza);
    
    // 7. Rimborsi Amazon (usato solo nel banner, non come KPI)
    const unitaRimborsate = kpi.unita_rimborsate || 0;
    
    // 8. Calcola "Unità Non Contabilizzate" (SENZA rimborsi)
    // Formula: Spedite - Vendute - Ritirate - Resi - Giacenza
    const unitaNonContabilizzate = unitaSpedite - unitaVendute - unitaRitirate - resiClienti - giacenza;
    
    // Calcola "Unità da Recuperare" (totale - rimborsi già ottenuti)
    const unitaDaRecuperare = unitaNonContabilizzate - unitaRimborsate;
    
    // Popola il banner
    document.getElementById('formula-spedite').textContent = formatNumber(unitaSpedite);
    document.getElementById('formula-vendute').textContent = formatNumber(unitaVendute);
    document.getElementById('formula-ritirate').textContent = formatNumber(unitaRitirate);
    document.getElementById('formula-resi').textContent = formatNumber(resiClienti);
    document.getElementById('formula-giacenza').textContent = formatNumber(giacenza);
    document.getElementById('formula-totale').textContent = formatNumber(unitaNonContabilizzate);
    
    document.getElementById('unita-totali-non-cont').textContent = formatNumber(unitaNonContabilizzate);
    document.getElementById('unita-rimborsate-banner').textContent = formatNumber(unitaRimborsate);
    document.getElementById('unita-da-recuperare').textContent = formatNumber(unitaDaRecuperare);
    
    // Mostra il banner solo se ci sono unità da recuperare (valore assoluto > 10)
    const banner = document.getElementById('unita-sparite-banner');
    if (Math.abs(unitaDaRecuperare) > 10) {
        banner.style.display = 'flex';
        
        // Colora il risultato in base al valore
        const totale = document.getElementById('formula-totale');
        const daRecuperare = document.querySelector('.unita-da-recuperare');
        
        if (unitaDaRecuperare < 0) {
            totale.style.color = '#dc2626'; // Rosso (unità mancanti)
            daRecuperare.style.color = '#dc2626';
            banner.querySelector('.banner-title').textContent = 'Unità Non Contabilizzate (Mancanti)';
        } else if (unitaDaRecuperare > 0) {
            totale.style.color = '#f59e0b'; // Arancione (eccedenza)
            daRecuperare.style.color = '#f59e0b';
            banner.querySelector('.banner-title').textContent = 'Unità Non Contabilizzate (Eccedenza)';
        }
    } else {
        banner.style.display = 'none';
    }
}

// Render shipments list
function renderShipmentsList(shipments, kpi) {
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
        <div class="shipment-card" onclick="openShipmentModal(${s.id})">
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
async function openShipmentModal(shipmentId) {
    try {
        const response = await fetch(`trid_api.php?action=shipment_items&shipment_id=${shipmentId}&user_id=${userId}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error);
        }
        
        const data = result.data;
        const info = data.shipment_info;
        const items = data.items;
        
        const modalHtml = `
            <div class="modal-overlay" id="shipment-modal" onclick="closeShipmentModal(event)">
                <div class="modal-content" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <div>
                            <h2>${escapeHtml(info.shipment_name)}</h2>
                            <div class="modal-subtitle">
                                <code>${escapeHtml(info.amazon_shipment_id)}</code>
                                <span>•</span>
                                <span>📍 ${escapeHtml(info.destination_fc || 'N/A')}</span>
                            </div>
                        </div>
                        <button class="modal-close" onclick="closeShipmentModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <table class="items-table">
                            <thead>
                                <tr>
                                    <th>Prodotto</th>
                                    <th>SKU</th>
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
        alert('Errore caricamento dettaglio: ' + error.message);
    }
}

// Close shipment modal
function closeShipmentModal(event) {
    if (event && event.target.className !== 'modal-overlay' && event.target.className !== 'modal-close') {
        return;
    }
    const modal = document.getElementById('shipment-modal');
    if (modal) {
        modal.remove();
        document.body.style.overflow = '';
    }
}

// Render reimbursements list
function renderReimbursementsList(reimbursementsByMonth, totalCount) {
    document.getElementById('reimbursements-summary').textContent = 
        `${totalCount} TRID disponibili`;
    
    const container = document.getElementById('reimbursements-list');
    
    if (totalCount === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #6b7280;">
                ✅ Nessun rimborso da richiedere
            </div>
        `;
        return;
    }
    
    const months = Object.keys(reimbursementsByMonth).sort((a, b) => b.localeCompare(a));
    
    container.innerHTML = months.map(month => {
        const data = reimbursementsByMonth[month];
        return `
            <div class="month-section">
                <div class="month-header" onclick="toggleMonth('${month}')">
                    <div>
                        <strong>📅 ${formatMonth(month)}</strong>
                        <div style="font-size: 0.85rem; color: #6b7280;">
                            ${data.count} TRID • ${data.total_qty} unità
                        </div>
                    </div>
                    <span class="expand-icon" id="expand-${month}">▼</span>
                </div>
                <div class="month-content" id="month-${month}" style="display: block;">
                    ${data.events.map(event => `
                        <div class="reimbursement-item">
                            <div class="reimbursement-header">
                                <span class="reimbursement-date">${formatDate(event.event_date)}</span>
                                <span class="reimbursement-qty ${event.quantity > 0 ? 'qty-positive' : 'qty-negative'}">
                                    ${event.quantity > 0 ? '+' : ''}${event.quantity}
                                </span>
                            </div>
                            <div class="reimbursement-product">
                                ${escapeHtml(event.product_name || event.msku)}
                            </div>
                            <div class="reimbursement-details">
                                <span>[${escapeHtml(event.fulfillment_center)}]</span>
                                <span>${escapeHtml(event.disposition || 'N/A')}</span>
                                <span>(${getReasonLabel(event.reason)})</span>
                            </div>
                            <div class="reimbursement-trid">
                                <code>${event.reference_id}</code>
                                <button class="btn-copy" onclick="event.stopPropagation(); copyTrid('${event.reference_id}')">
                                    📋 Copia
                                </button>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }).join('');
}

// Toggle month expansion
function toggleMonth(monthId) {
    const content = document.getElementById(`month-${monthId}`);
    const icon = document.getElementById(`expand-${monthId}`);
    
    if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.textContent = '▼';
    } else {
        content.style.display = 'none';
        icon.textContent = '▶';
    }
}

// Format month (YYYY-MM to "Gen 2025")
function formatMonth(monthStr) {
    const [year, month] = monthStr.split('-');
    const monthNames = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
    return `${monthNames[parseInt(month) - 1]} ${year}`;
}

// Get reason label
function getReasonLabel(reason) {
    const labels = {
        'Q': 'SELLABLE (Q)',
        'D': 'DISTRIBUTOR_DAMAGED',
        'E': 'SELLABLE (E)',
        'M': 'WAREHOUSE_DAMAGED',
        'O': 'CARRIER_DAMAGED',
        'P': 'DEFECTIVE (P)'
    };
    return labels[reason] || reason;
}

// Load shipment detail
async function loadShipmentDetail(shipmentId) {
    try {
        const response = await fetch(`trid_api.php?action=detail&shipment_id=${shipmentId}&user_id=${userId}`);
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error);
        }
        
        renderProductsTimeline(result.data);
    } catch (error) {
        document.getElementById('products-container').innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #ef4444;">
                ❌ Errore: ${error.message}
            </div>
        `;
    }
}

// Render products timeline
function renderProductsTimeline(products) {
    const container = document.getElementById('products-container');
    
    if (products.length === 0) {
        container.innerHTML = `
            <div class="no-data">
                📭 Nessun dato TRID disponibile per questa spedizione.
                <br><br>
                <small>Assicurati di aver scaricato il report Ledger.</small>
            </div>
        `;
        return;
    }
    
    container.innerHTML = products.map((product, idx) => `
        <div class="product-card">
            <div class="product-header" onclick="toggleProduct(${idx})">
                <h3>📦 ${escapeHtml(product.name)}</h3>
                <div class="product-stats">
                    <span class="stat-item">✅ Ricevute: <strong>${product.receipts}</strong></span>
                    <span class="stat-item">📦 Vendute: <strong>${product.shipments}</strong></span>
                    ${product.defective > 0 ? `<span class="stat-item stat-defect">⚠️ Difettose: <strong>${product.defective}</strong></span>` : ''}
                    ${product.transfers > 0 ? `<span class="stat-item">🔄 Transfer: <strong>${product.transfers}</strong></span>` : ''}
                    ${product.adjustments > 0 ? `<span class="stat-item">📊 Aggiustamenti: <strong>${product.adjustments}</strong></span>` : ''}
                </div>
                <span class="expand-icon" id="expand-${idx}">▼</span>
            </div>
            
            <div class="product-timeline" id="timeline-${idx}" style="display: none;">
                ${renderTimeline(product.events)}
            </div>
        </div>
    `).join('');
}

// Render timeline eventi
function renderTimeline(events) {
    return events.map(event => {
        const isTridTicket = event.reference_id && /^\d{14,}$/.test(event.reference_id);
        const isDefective = event.disposition === 'DEFECTIVE';
        
        return `
            <div class="timeline-event ${isDefective ? 'event-defect' : ''}">
                <div class="event-date">${formatDateTime(event.datetime)}</div>
                <div class="event-type">${getEventIcon(event.event_type)} ${escapeHtml(event.event_type)}</div>
                <div class="event-qty">${event.quantity > 0 ? '+' : ''}${event.quantity}</div>
                <div class="event-location">${escapeHtml(event.fulfillment_center || 'N/A')}</div>
                ${event.disposition ? `<div class="event-disposition">${escapeHtml(event.disposition)}</div>` : ''}
                ${event.reason ? `<div class="event-reason">${escapeHtml(event.reason)}</div>` : ''}
                
                ${isTridTicket ? `
                    <div class="trid-ticket">
                        <strong>🎫 TRID:</strong> 
                        <code id="trid-${event.id}">${escapeHtml(event.reference_id)}</code>
                        <button onclick="copyTrid('${event.reference_id}', ${event.id})" class="btn-copy">📋 Copia</button>
                        <a href="https://sellercentral.amazon.it/home?ref=warehouse_damaged_issues_wf&spaui-direct-answer=WarehouseDamaged&spaui-help-topic=G202160380" 
                           target="_blank" class="btn-seller-central">
                            Apri Seller Central →
                        </a>
                        <p class="hint">💡 Incolla questo TRID su Seller Central per richiedere il rimborso!</p>
                    </div>
                ` : ''}
            </div>
        `;
    }).join('');
}

// Toggle product accordion
function toggleProduct(idx) {
    const timeline = document.getElementById(`timeline-${idx}`);
    const icon = document.getElementById(`expand-${idx}`);
    
    if (timeline.style.display === 'none') {
        timeline.style.display = 'block';
        icon.textContent = '▲';
    } else {
        timeline.style.display = 'none';
        icon.textContent = '▼';
    }
}

// Copy TRID to clipboard
function copyTrid(trid, id) {
    navigator.clipboard.writeText(trid).then(() => {
        alert('✅ TRID copiato negli appunti!');
    }).catch(() => {
        // Fallback
        const input = document.createElement('input');
        input.value = trid;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        alert('✅ TRID copiato!');
    });
}

// ============================================
// AGGREGATED VIEW LOGIC
// ============================================

let currentShipmentId = <?= $shipmentId ?? 0 ?>;

// Toggle view
document.getElementById('view-cards')?.addEventListener('click', function() {
    document.getElementById('aggregated-view').style.display = 'block';
    document.getElementById('timeline-view').style.display = 'none';
    this.classList.add('active');
    document.getElementById('view-timeline').classList.remove('active');
});

document.getElementById('view-timeline')?.addEventListener('click', function() {
    document.getElementById('aggregated-view').style.display = 'none';
    document.getElementById('timeline-view').style.display = 'block';
    this.classList.add('active');
    document.getElementById('view-cards').classList.remove('active');
});

// Load aggregated data
if (currentShipmentId > 0) {
    loadAggregatedData(currentShipmentId);
}

function loadAggregatedData(shipmentId) {
    console.log('🔍 Loading aggregated data for shipment:', shipmentId);
    fetch(`trid_api.php?action=load_aggregated&shipment_id=${shipmentId}`)
        .then(res => {
            console.log('📡 Response status:', res.status);
            return res.json();
        })
        .then(data => {
            console.log('📦 Aggregated data received:', data);
            if (data.success) {
                console.log('✅ Data valid, rendering view');
                renderAggregatedView(data.data);
                document.getElementById('loading-aggregated').style.display = 'none';
                document.getElementById('aggregated-content').style.display = 'block';
            } else {
                console.error('❌ Error loading aggregated data:', data.error);
            }
        })
        .catch(err => console.error('❌ Fetch error:', err));
}

function renderAggregatedView(data) {
    console.log('🎨 Rendering aggregated view with data:', data);
    const kpi = data.kpi;
    console.log('📊 KPI data:', kpi);
    console.log('📊 KPI.total_received:', kpi.total_received, 'Type:', typeof kpi.total_received);
    console.log('📊 KPI.total_shipped:', kpi.total_shipped, 'Type:', typeof kpi.total_shipped);
    console.log('📊 KPI.total_adjustments:', kpi.total_adjustments, 'Type:', typeof kpi.total_adjustments);
    console.log('📊 KPI.total_transfers:', kpi.total_transfers, 'Type:', typeof kpi.total_transfers);
    console.log('📊 KPI.reimbursable_count:', kpi.reimbursable_count, 'Type:', typeof kpi.reimbursable_count);
    console.log('📊 KPI.shipment_events:', kpi.shipment_events, 'Type:', typeof kpi.shipment_events);
    
    // KPI Cards
    const receivedVal = `+${kpi.total_received || 0}`;
    const shippedVal = `-${kpi.total_shipped || 0}`;
    const totalLosses = (parseInt(kpi.total_adjustments) || 0) + (parseInt(kpi.total_defective) || 0);
    const lossesVal = `-${totalLosses}`;
    const transfersVal = kpi.total_transfers || 0;
    const reimburseVal = kpi.reimbursable_count || 0;
    
    console.log('💾 Setting KPI values:', {
        received: receivedVal,
        shipped: shippedVal,
        losses: lossesVal,
        transfers: transfersVal,
        reimburse: reimburseVal
    });
    
    document.getElementById('kpi-received').textContent = receivedVal;
    document.getElementById('kpi-received-sub').textContent = `${kpi.receipt_batches || 0} ingressi`;
    
    document.getElementById('kpi-shipped').textContent = shippedVal;
    document.getElementById('kpi-shipped-sub').textContent = `${kpi.shipment_events || 0} ordini`;
    
    document.getElementById('kpi-losses').textContent = lossesVal;
    document.getElementById('kpi-losses-sub').textContent = `${data.critical_events.length} eventi`;
    
    document.getElementById('kpi-transfers').textContent = transfersVal;
    
    document.getElementById('kpi-reimburse').textContent = reimburseVal;
    
    console.log('📍 Warehouses:', data.warehouses);
    // Warehouse Distribution
    renderWarehouseDistribution(data.warehouses);
    
    console.log('⚠️ Critical events:', data.critical_events);
    // Critical Events
    if (data.critical_events.length > 0) {
        document.getElementById('critical-section').style.display = 'block';
        renderCriticalEvents(data.critical_events);
    }
    
    console.log('📅 Monthly timeline:', data.monthly_timeline);
    // Monthly Timeline
    renderMonthlyTimeline(data.monthly_timeline);
}

function renderWarehouseDistribution(warehouses) {
    console.log('🏢 Rendering warehouse distribution:', warehouses);
    const container = document.getElementById('warehouse-distribution');
    
    if (!warehouses || warehouses.length === 0) {
        console.log('⚠️ No warehouse data');
        container.innerHTML = '<p style="color: #9ca3af; text-align: center;">Nessun dato disponibile</p>';
        return;
    }
    
    container.innerHTML = warehouses.map(wh => `
        <div class="warehouse-item">
            <div class="warehouse-name">🏢 ${escapeHtml(wh.fulfillment_center)}</div>
            <div class="warehouse-qty ${wh.net_quantity > 0 ? 'qty-positive' : 'qty-negative'}">
                ${wh.net_quantity > 0 ? '+' : ''}${wh.net_quantity} unità
            </div>
        </div>
    `).join('');
}

function renderCriticalEvents(events) {
    console.log('⚠️ Rendering critical events:', events);
    const container = document.getElementById('critical-events');
    
    container.innerHTML = events.map(evt => {
        const reasonMap = {
            'Q': 'SELLABLE (Q)',
            'D': 'DISTRIBUTOR_DAMAGED',
            'E': 'SELLABLE (E)',
            'M': 'WAREHOUSE_DAMAGED',
            'O': 'CARRIER_DAMAGED',
            'P': 'DEFECTIVE (P)'
        };
        
        return `
            <div class="critical-event-item">
                <div class="event-date">${formatDate(evt.event_date)}</div>
                <div class="event-details">
                    <span class="event-fc">${escapeHtml(evt.fulfillment_center || 'N/A')}</span>
                    <span class="event-reason">${reasonMap[evt.reason] || evt.disposition}</span>
                    <span class="event-qty">${evt.quantity < 0 ? '' : '+'}${evt.quantity}</span>
                </div>
                <div class="event-trid">
                    <code class="trid-code">${escapeHtml(evt.reference_id || 'N/A')}</code>
                    <button class="btn-copy-trid" onclick="copyTrid('${evt.reference_id}')">📋</button>
                </div>
            </div>
        `;
    }).join('');
}

function renderMonthlyTimeline(monthlyData) {
    console.log('📅 Rendering monthly timeline:', monthlyData);
    const container = document.getElementById('monthly-timeline');
    const months = Object.keys(monthlyData).sort().reverse();
    console.log('📅 Months found:', months);
    
    if (months.length === 0) {
        console.log('⚠️ No monthly data');
        container.innerHTML = '<p style="color: #9ca3af;">Nessun dato disponibile</p>';
        return;
    }
    
    container.innerHTML = months.map(month => {
        const data = monthlyData[month];
        const netFlow = (data.receipts || 0) - (data.shipments || 0) - (data.adjustments || 0);
        
        return `
            <div class="month-card">
                <div class="month-header" onclick="toggleMonth('${month}')">
                    <div>
                        <h4>📅 ${formatMonth(month)}</h4>
                        <div class="month-summary">
                            Net: ${netFlow > 0 ? '+' : ''}${netFlow} unità
                            ${data.adjustments > 0 ? ' • ⚠️ ' + data.adjustments + ' perdite' : ''}
                        </div>
                    </div>
                    <span class="expand-icon" id="icon-${month}">▼</span>
                </div>
                <div class="month-details" id="details-${month}" style="display: none;">
                    <div class="month-stats">
                        ${data.receipts > 0 ? `<div class="stat-row">✅ Ricevute: <strong>+${data.receipts}</strong></div>` : ''}
                        ${data.shipments > 0 ? `<div class="stat-row">📦 Vendite: <strong>-${data.shipments}</strong></div>` : ''}
                        ${data.adjustments > 0 ? `<div class="stat-row">⚠️ Perdite: <strong>-${data.adjustments}</strong></div>` : ''}
                        ${data.transfers > 0 ? `<div class="stat-row">🔄 Transfer: <strong>${data.transfers}</strong></div>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function toggleMonth(month) {
    const details = document.getElementById(`details-${month}`);
    const icon = document.getElementById(`icon-${month}`);
    
    if (details.style.display === 'none') {
        details.style.display = 'block';
        icon.textContent = '▲';
    } else {
        details.style.display = 'none';
        icon.textContent = '▼';
    }
}

function formatMonth(monthStr) {
    const [year, month] = monthStr.split('-');
    const monthNames = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
    return `${monthNames[parseInt(month) - 1]} ${year}`;
}

document.getElementById('copy-all-trids')?.addEventListener('click', function() {
    const trids = Array.from(document.querySelectorAll('.trid-code'))
        .map(el => el.textContent)
        .join('\n');
    
    navigator.clipboard.writeText(trids).then(() => {
        alert(`✅ Copiati ${trids.split('\n').length} TRID negli appunti!`);
    });
});

document.getElementById('expand-all-months')?.addEventListener('click', function() {
    const allDetails = document.querySelectorAll('.month-details');
    const allIcons = document.querySelectorAll('.expand-icon');
    const isExpanding = allDetails[0]?.style.display === 'none';
    
    allDetails.forEach(detail => detail.style.display = isExpanding ? 'block' : 'none');
    allIcons.forEach(icon => icon.textContent = isExpanding ? '▲' : '▼');
    
    this.textContent = isExpanding ? 'Chiudi tutto' : 'Espandi tutto';
});

// Modal functions
function openDownloadModal() {
    document.getElementById('download-modal').style.display = 'flex';
}

function closeDownloadModal() {
    document.getElementById('download-modal').style.display = 'none';
}

// Download form submit
<?php if ($isAdmin): ?>
document.getElementById('download-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const downloadBtn = document.getElementById('download-btn');
    const progressDiv = document.getElementById('download-progress');
    const resultDiv = document.getElementById('download-result');
    
    downloadBtn.disabled = true;
    progressDiv.style.display = 'block';
    resultDiv.innerHTML = '';
    
    try {
        const response = await fetch('trid_api.php?action=download_and_import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `user_id=${userId}&start_date=${startDate}&end_date=${endDate}`
        });
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error);
        }
        
        let errorDetailsHtml = '';
        if (result.errors > 0 && result.error_details && result.error_details.length > 0) {
            errorDetailsHtml = `
                <details style="margin-top: 1rem; padding: 1rem; background: #fef3c7; border-radius: 8px;">
                    <summary style="cursor: pointer; font-weight: 600; color: #92400e;">
                        ⚠️ ${result.errors} errori di parsing (clicca per dettagli)
                    </summary>
                    <ul style="margin-top: 0.5rem; padding-left: 1.5rem; max-height: 200px; overflow-y: auto;">
                        ${result.error_details.map(err => `<li style="font-size: 0.9rem; color: #78350f;">${escapeHtml(err)}</li>`).join('')}
                    </ul>
                </details>
            `;
        }
        
        resultDiv.innerHTML = `
            <div class="success-message">
                ✅ <strong>Importazione completata!</strong><br>
                📥 ${result.imported} transazioni importate<br>
                ${result.errors > 0 ? `⚠️ ${result.errors} righe saltate<br>` : ''}
                🔗 ${result.assigned_shipments} spedizioni collegate<br>
                🏷️ ${result.assigned_products} prodotti mappati
            </div>
            ${errorDetailsHtml}
        `;
        
        // Reload dashboard
        setTimeout(() => {
            closeDownloadModal();
            if (viewMode === 'list') loadDashboard();
        }, 2000);
        
    } catch (error) {
        resultDiv.innerHTML = `
            <div class="error-message">
                ❌ Errore: ${error.message}
            </div>
        `;
    } finally {
        downloadBtn.disabled = false;
        progressDiv.style.display = 'none';
    }
});
<?php endif; ?>

// Utility functions
function escapeHtml(text) {
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

function formatDateTime(dateStr) {
    if (!dateStr) return 'N/A';
    const d = new Date(dateStr);
    return d.toLocaleDateString('it-IT') + ' ' + d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
}

function getEventIcon(eventType) {
    const icons = {
        'Receipts': '✅',
        'Shipments': '📦',
        'Adjustments': '📊',
        'WhseTransfers': '🔄',
        'Disposals': '🗑️',
        'Returns': '↩️'
    };
    return icons[eventType] || '📋';
}
</script>

</body>
</html>

