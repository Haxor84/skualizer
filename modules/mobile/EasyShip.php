<?php
/**
 * EasyShip Mobile - Gestione Spedizioni Multi-Box
 * File: modules/mobile/EasyShip.php
 * 
 * Desktop Parity: Replica esatta di modules/easyship/easyship.php
 * Ottimizzato per mobile con UI/UX coerente con altre pagine mobile
 */

session_start();

// Gestione AJAX - Intercetta autocomplete per debug, resto delegato al backend desktop
$action = $_REQUEST['action'] ?? null;
if ($action) {
    // CRITICAL: Config e autenticazione PRIMA di delegare
    require_once dirname(__DIR__) . '/easyship/config_easyship.php';
    
    // Forza autenticazione e ottieni userId
    $currentUser = requireEasyShipAuth();
    $userId = $currentUser['id'];
    
    // INTERCETTA AUTOCOMPLETE per debug dettagliato
    if ($action === 'autocomplete') {
        header('Content-Type: application/json');
        
        $query = trim($_GET['q'] ?? '');
        
        error_log("=== AUTOCOMPLETE MOBILE DEBUG ===");
        error_log("User ID: {$userId}");
        error_log("Query: '{$query}'");
        
        try {
            $db = getDbConnection();
            
            if (empty($query)) {
                $stmt = $db->prepare("
                    SELECT nome 
                    FROM products 
                    WHERE user_id = ? 
                    ORDER BY nome ASC 
                    LIMIT 2000
                ");
                $stmt->execute([$userId]);
                error_log("SQL: SELECT nome FROM products WHERE user_id = ? (empty query)");
            } else {
                // Dividi query in parole singole
                $words = array_filter(explode(' ', strtolower(trim($query))));
                $conditions = [];
                $params = [$userId];
                
                foreach ($words as $word) {
                    $conditions[] = "LOWER(nome) LIKE ?";
                    $params[] = "%{$word}%";
                }
                
                $whereClause = implode(' AND ', $conditions);
                $sqlQuery = "SELECT nome FROM products WHERE user_id = ? AND {$whereClause} ORDER BY nome ASC LIMIT 2000";
                
                error_log("SQL: {$sqlQuery}");
                error_log("PARAMS: " . json_encode($params));
                
                $stmt = $db->prepare($sqlQuery);
                $stmt->execute($params);
            }
            
            $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            error_log("RESULTS COUNT: " . count($results));
            if (count($results) > 0) {
                error_log("FIRST 3: " . implode(', ', array_slice($results, 0, 3)));
            }
            
            // Verifica user_id dei primi 3 prodotti trovati (per debug)
            if (!empty($query) && count($results) > 0) {
                $checkStmt = $db->prepare("SELECT nome, user_id FROM products WHERE nome IN (?, ?, ?) LIMIT 3");
                $checkStmt->execute(array_slice($results, 0, 3));
                $checkResults = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("USER_ID CHECK: " . json_encode($checkResults));
            }
            
            echo json_success($results);
            
        } catch (Exception $e) {
            error_log("AUTOCOMPLETE ERROR: " . $e->getMessage());
            echo json_error('Errore autocomplete: ' . $e->getMessage());
        }
        exit;
    }
    
    // Log per altre azioni
    error_log("=== EASYSHIP MOBILE REQUEST ===");
    error_log("Action: {$action}");
    error_log("User ID: {$userId}");
    
    // Delega al backend desktop per tutte le altre azioni
    require_once dirname(__DIR__) . '/easyship/easyship_api.php';
    exit;
}

// === PARTE HTML ===
require_once dirname(__DIR__) . '/easyship/config_easyship.php';

// Autenticazione
$currentUser = requireEasyShipAuth();
$userId = $currentUser['id'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#dc2626">
    <meta name="apple-mobile-web-app-title" content="SkuAlizer Suite">
    <title>EasyShip - SkuAlizer</title>
    
    <!-- PWA Icons -->
    <link rel="icon" type="image/png" sizes="192x192" href="/modules/mobile/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/modules/mobile/assets/icon-180.png">
    <link rel="manifest" href="/modules/mobile/manifest.json">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/mobile.css">
    <link rel="stylesheet" href="assets/EasyShip.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        .hamburger-overlay.active { opacity: 1 !important; visibility: visible !important; }
        .hamburger-overlay.active .hamburger-menu { transform: translateX(0) !important; }
        .hamburger-menu-link:hover { background: #f8fafc !important; border-left-color: #dc2626 !important; }
    </style>
</head>
<body>
    <?php readfile(__DIR__ . '/assets/icons.svg'); ?>
    
    <!-- Hamburger Menu Overlay -->
    <div class="hamburger-overlay" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); z-index: 9999; opacity: 0; visibility: hidden; transition: all 0.3s;">
        <nav class="hamburger-menu" style="position: absolute; top: 0; right: 0; width: 80%; max-width: 320px; height: 100%; background: white; transform: translateX(100%); transition: transform 0.3s; box-shadow: -4px 0 24px rgba(0,0,0,0.15);">
            <div class="hamburger-menu-header" style="background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%); padding: 24px 20px; color: white;">
                <div class="hamburger-menu-title" style="font-size: 24px; font-weight: 700; margin-bottom: 4px;">Menu</div>
                <div style="font-size: 12px; opacity: 0.9;">Navigazione rapida</div>
            </div>
            <div class="hamburger-menu-nav" style="padding: 12px 0;">
                <a href="/modules/mobile/Margynomic.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-chart-line" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>Margynomic</span>
                </a>
                <a href="/modules/mobile/Previsync.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-boxes" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>PreviSync</span>
                </a>
                <a href="/modules/mobile/OrderInsights.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-microscope" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>OrderInsight</span>
                </a>
                <a href="/modules/mobile/TridScanner.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-search" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>TridScanner</span>
                </a>
                <a href="/modules/mobile/Rendiconto.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-file-invoice-dollar" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>Economics</span>
                </a>
                <a href="/modules/mobile/EasyShip.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid #dc2626; background: #fef2f2;">
                    <i class="fas fa-truck" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>EasyShip</span>
                </a>
                <a href="/modules/mobile/Profilo.php" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #1e293b; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-user" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>Profilo</span>
                </a>
                <div style="height: 1px; background: #e2e8f0; margin: 12px 20px;"></div>
                <a href="#" onclick="doLogout(); return false;" class="hamburger-menu-link" style="display: flex; align-items: center; gap: 16px; padding: 16px 20px; color: #dc2626; text-decoration: none; font-weight: 600; font-size: 15px; transition: background 0.2s; border-left: 3px solid transparent;">
                    <i class="fas fa-sign-out-alt" style="font-size: 20px; color: #dc2626; width: 24px; text-align: center;"></i>
                    <span>Logout</span>
                </a>
            </div>
        </nav>
    </div>

    <!-- Main Content -->
    <main class="mobile-content">
        <!-- Hero Header (Pattern Rendiconto.php) -->
        <div class="hero-welcome">
            <div class="hero-header">
                <div class="hero-logo">
                    <div class="hero-title"><i class="fas fa-truck"></i> EasyShip</div>
                    <div class="hero-subtitle">GESTIONE MULTI-BOX CON TRACKING!</div>
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
                    <div class="info-box-title">🎯 Target FBA</div>
                    <div class="info-box-text">Sistema ottimizzato per requisiti Amazon FBA.</div>
                </div>
                <div class="info-box">
                    <div class="info-box-title">🧮 Smart Packing</div>
                    <div class="info-box-text">Algoritmi suggeriscono distribuzione ottimale prodotti.</div>
                </div>
                <div class="info-box">
                    <div class="info-box-title">🔄 Template Riutilizzabili</div>
                    <div class="info-box-text">Duplica spedizioni ricorrenti con un click.</div>
                </div>
                <div class="info-box">
                    <div class="info-box-title">📊 Storico Completo</div>
                    <div class="info-box-text">Archivio digitale con ricerca avanzata.</div>
                </div>
            </div>
        </div>
    
    <!-- Strategic Flow Grid - Cards 1-3 (Stats) -->
    <div class="style2-grid">
        <!-- Card 1: Spedizioni Completate -->
        <div class="style2-card card-success">
            <div class="style2-header">
                <div class="style2-icon">✅</div>
                <div class="style2-header-text">
                    <div class="style2-number"><span id="flow-completed">0</span></div>
                    <div class="style2-label">Completate</div>
                </div>
            </div>
            <div class="style2-body">
                <div class="style2-stat">
                    <span class="style2-stat-label">Stato</span>
                    <span class="style2-stat-value">Confermate</span>
                </div>
            </div>
        </div>
        
        <!-- Card 2: Colli + Volume -->
        <div class="style2-card card-info">
            <div class="style2-header">
                <div class="style2-icon">📦</div>
                <div class="style2-header-text">
                    <div class="style2-number"><span id="flow-boxes">0</span></div>
                    <div class="style2-label">Box</div>
                </div>
            </div>
            <div class="style2-body">
                <div class="style2-stat">
                    <span class="style2-stat-label">Volume</span>
                    <span class="style2-stat-value"><span id="flow-volume">0</span> m³</span>
                </div>
            </div>
        </div>
        
        <!-- Card 3: Unità + Peso -->
        <div class="style2-card card-warning">
            <div class="style2-header">
                <div class="style2-icon">📊</div>
                <div class="style2-header-text">
                    <div class="style2-number"><span id="flow-units">0</span></div>
                    <div class="style2-label">Unità</div>
                </div>
            </div>
            <div class="style2-body">
                <div class="style2-stat">
                    <span class="style2-stat-label">Peso</span>
                    <span class="style2-stat-value"><span id="flow-weight">0</span> kg</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="nav-tabs">
        <button class="nav-tab active" data-tab="create">
            <i class="fas fa-plus"></i> Nuova Spedizione
        </button>
        <button class="nav-tab" data-tab="list">
            <i class="fas fa-list"></i> Le Mie Spedizioni
        </button>
    </div>
    
    <!-- Tab: Create Shipment -->
    <div id="tab-create" class="tab-content">
        <div class="form-section">
            <div class="section-header">
                <i class="fas fa-boxes"></i>
                <h3>Gestione Colli</h3>
            </div>
            
            <button class="add-btn" onclick="addBox()">
                <i class="fas fa-plus"></i> Aggiungi Collo
            </button>
            
            <div id="boxes-container" class="boxes-container">
                <!-- Box dinamici inseriti qui -->
            </div>
        </div>
        
        <div class="main-actions">
            <button class="save-draft-btn" onclick="saveShipment('draft')">
                <i class="fas fa-save"></i> Salva Bozza
            </button>
            <button class="confirm-shipment-btn" onclick="saveShipment('confirm')">
                <i class="fas fa-check-circle"></i> Conferma Spedizione
            </button>
        </div>
    </div>
    
    <!-- Tab: List Shipments -->
    <div id="tab-list" class="tab-content" style="display: none;">
        <div class="shipments-list">            
            <table class="shipments-table" id="shipments-table">
                <thead>
                    <tr>
                        <th><h3><i class="fas fa-list"></i> Le Mie Spedizioni</h3>
                <p>Gestisci le spedizioni create</p></th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Righe dinamiche -->
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="loading-overlay">
        <div class="loading-content">
            <div class="spinner"></div>
            <div>Caricamento...</div>
        </div>
    </div>
    
    <!-- Shipment View Modal -->
    <div id="view-modal" class="modal-overlay"></div>
    </main>
    
    <?php include '_partials/mobile_tabbar.php'; ?>
    
    <script>
        // === VARIABILI GLOBALI (Desktop Parity) ===
        let boxCounter = 0;
        let currentEditingId = null;
        
        // === INIZIALIZZAZIONE (Desktop Parity) ===
        $(document).ready(function() {
            // Carica statistiche flow grid immediatamente
            loadShipmentsList();
            
            // Aggiungi primo box di default
            addBox();
            
            // Validazione iniziale dello stato dei pulsanti
            updateSaveButtonsState();
            
            // Tab switching
            $('.nav-tab').click(function() {
                if ($(this).attr('href')) return; // Skip links
                
                const tab = $(this).data('tab');
                $('.nav-tab').removeClass('active');
                $(this).addClass('active');
                $('.tab-content').hide();
                $(`#tab-${tab}`).show();
                
                if (tab === 'list') {
                    loadShipmentsList();
                } else if (tab === 'create') {
                    // Reset completo per nuova spedizione
                    resetForm();
                    
                    // Ripristina bottoni originali
                    $('.save-draft-btn').html('<i class="fas fa-save"></i> Salva Bozza').show();
                    $('.confirm-shipment-btn').html('<i class="fas fa-check-circle"></i> Conferma Spedizione').show();
                }
            });
        });
        
        // === GESTIONE BOX (Desktop Parity) ===
        function addBox() {
            boxCounter++;
            const boxHtml = `
                <div class="box-item" data-box="${boxCounter}">
                    <div class="box-header">
                        <h4 class="box-title">📦 Box ${boxCounter}</h4>
                        <button class="remove-box-btn" onclick="removeBox(${boxCounter})">
                            <i class="fas fa-trash"></i> Rimuovi
                        </button>
                    </div>
                    
                    <div class="dimensions-grid">
                        <div class="dimension-field">
                            <label>Altezza (cm)</label>
                            <input type="number" class="dimension-input" data-dimension="altezza" step="0.1" min="0">
                        </div>
                        <div class="dimension-field">
                            <label>Larghezza (cm)</label>
                            <input type="number" class="dimension-input" data-dimension="larghezza" step="0.1" min="0">
                        </div>
                        <div class="dimension-field">
                            <label>Lunghezza (cm)</label>
                            <input type="number" class="dimension-input" data-dimension="lunghezza" step="0.1" min="0">
                        </div>
                        <div class="dimension-field">
                            <label>Peso (kg)</label>
                            <input type="number" class="dimension-input" data-dimension="peso" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="section-header">
                        <i class="fas fa-box-open"></i>
                        <h4>Prodotti in questo Box</h4>
                    </div>
                    
                    <button class="add-btn" onclick="addProduct(${boxCounter})">
                        <i class="fas fa-plus"></i> Aggiungi Prodotto
                    </button>
                    
                    <div class="products-container" data-box="${boxCounter}">
                        <!-- Prodotti inseriti qui -->
                    </div>
                </div>
            `;
            
            $('#boxes-container').append(boxHtml);
        }
        
        function removeBox(boxId) {
            if ($('.box-item').length <= 1) {
                showFeedback('Almeno un collo è richiesto', 'error');
                return;
            }
            $(`.box-item[data-box="${boxId}"]`).remove();
        }
        
        function addProduct(boxId) {
            const productId = Date.now();
            const productHtml = `
                <div class="product-item" data-product="${productId}">
                    <div class="autocomplete-container">
                        <input type="text" class="product-input product-name" placeholder="Nome prodotto..." 
                               data-product="${productId}" onkeyup="handleAutocomplete(this)" oninput="handleProductInput(this)">
                        <div class="autocomplete-results" id="autocomplete-${productId}"></div>
                    </div>
                    <input type="number" class="product-input quantity" placeholder="Quantità" min="1" value="1" required>
                    <input type="date" class="product-input expiry" placeholder="Scadenza (opzionale)">
                    <button class="remove-product-btn" onclick="removeProduct(${productId})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            $(`.products-container[data-box="${boxId}"]`).append(productHtml);
            
            // Aggiorna stato pulsanti dopo aggiunta prodotto
            updateSaveButtonsState();
        }
        
        function removeProduct(productId) {
            $(`.product-item[data-product="${productId}"]`).remove();
            
            // Aggiorna stato pulsanti dopo rimozione prodotto
            updateSaveButtonsState();
        }
        
        // === AUTOCOMPLETE PRODOTTI (Desktop Parity) ===
        let autocompleteTimeout;
        
        function handleProductInput(input) {
            // Compatibilità con HTML onchange
        }
        
        function handleAutocomplete(input) {
            clearTimeout(autocompleteTimeout);
            const query = input.value.trim();
            const productId = $(input).data('product');
            const resultsContainer = $(`#autocomplete-${productId}`);
            
            if (query.length < 2) {
                resultsContainer.hide().empty();
                return;
            }
            
            autocompleteTimeout = setTimeout(() => {
                $.ajax({
                    url: '?action=autocomplete',
                    data: { q: query },
                    success: function(response) {
                        if (response.success && response.data.length > 0) {
                            let html = '';
                            response.data.forEach(product => {
                                const escapedProduct = product.replace(/'/g, "\\'").replace(/"/g, '\\"');
                                html += `<div class="autocomplete-item" onclick="selectProduct('${productId}', '${escapedProduct}')">${product}</div>`;
                            });
                            resultsContainer.html(html).show();
                        } else if (response.success && response.data.length === 0) {
                            resultsContainer.html('<div class="autocomplete-no-results">Nessun prodotto trovato</div>').show();
                        } else {
                            resultsContainer.hide().empty();
                        }
                    },
                    error: function(xhr, status, error) {
                        resultsContainer.hide().empty();
                    }
                });
            }, 300);
        }
        
        function selectProduct(productId, productName) {
            const input = $(`.product-input[data-product="${productId}"]`);
            input.val(productName);
            input.addClass('validated-product');
            input.data('validated', true);
            $(`#autocomplete-${productId}`).hide().empty();
            
            clearProductError(input);
            input.attr('title', '✓ Prodotto selezionato dall\'elenco - Validato');
            
            updateSaveButtonsState();
        }
        
        // Nascondi autocomplete quando si clicca fuori
        $(document).click(function(e) {
            if (!$(e.target).closest('.autocomplete-container').length) {
                $('.autocomplete-results').hide();
            }
        });
        
        // === VALIDAZIONE IN TEMPO REALE (Desktop Parity) ===
        $(document).on('input', '.product-name', function() {
            const input = $(this);
            const currentValue = input.val().trim();
            
            if (input.data('validated') === true) {
                input.removeData('validated');
                input.removeClass('validated-product');
                input.removeAttr('title');
            }
            
            if (currentValue.length > 0) {
                input.addClass('unvalidated-product');
                markProductAsInvalid(input, 'Prodotto non selezionato dall\'elenco');
            } else {
                input.removeClass('unvalidated-product invalid-product');
                clearProductError(input);
            }
            
            updateSaveButtonsState();
        });
        
        $(document).on('blur', '.product-name', function() {
            const input = $(this);
            const currentValue = input.val().trim();
            
            if (currentValue.length > 0 && !input.data('validated')) {
                markProductAsInvalid(input, 'Prodotto inesistente - Seleziona dall\'elenco');
            }
        });
        
        // === FUNZIONI DI VALIDAZIONE VISIVA (Desktop Parity) ===
        function markProductAsInvalid(input, message) {
            input.addClass('invalid-product');
            input.attr('title', '❌ ' + message);
            
            const productItem = input.closest('.product-item');
            let errorMsg = productItem.find('.product-error');
            if (errorMsg.length === 0) {
                errorMsg = $('<div class="product-error"></div>');
                productItem.append(errorMsg);
            }
            errorMsg.text(message).show();
        }
        
        function clearProductError(input) {
            input.removeClass('invalid-product unvalidated-product');
            input.removeAttr('title');
            
            const productItem = input.closest('.product-item');
            productItem.find('.product-error').hide();
        }
        
        function updateSaveButtonsState() {
            const hasInvalidProducts = $('.invalid-product').length > 0;
            const hasUnvalidatedProducts = $('.unvalidated-product').length > 0;
            const shouldDisable = hasInvalidProducts || hasUnvalidatedProducts;
            
            $('.save-draft-btn, .confirm-shipment-btn').prop('disabled', shouldDisable);
            
            if (shouldDisable) {
                $('.save-draft-btn, .confirm-shipment-btn').addClass('disabled-by-validation');
            } else {
                $('.save-draft-btn, .confirm-shipment-btn').removeClass('disabled-by-validation');
            }
        }
        
        // === SALVATAGGIO SPEDIZIONE (Desktop Parity) ===
        function saveShipment(type) {
            const invalidProducts = $('.invalid-product, .unvalidated-product');
            if (invalidProducts.length > 0) {
                const invalidNames = [];
                invalidProducts.each(function() {
                    const name = $(this).val().trim();
                    if (name) invalidNames.push(name);
                });
                
                showFeedback(`Impossibile salvare: ${invalidNames.length} prodotto/i non validato/i.`, 'error');
                
                invalidProducts.each(function() {
                    $(this).focus().blur();
                });
                
                return;
            }
            
            validateProducts(function(isValid) {
                if (!isValid) return;
                
                if (type === 'confirm' || currentEditingId) {
                    if (!validateDimensions()) return;
                }
                
                const payload = collectShipmentData(type === 'confirm');
                if (!payload) return;
                
                proceedWithSave(type, payload);
            });
        }
        
        function proceedWithSave(type, payload) {
            let action;
            if (currentEditingId) {
                action = 'updateShipmentComplete';
            } else {
                action = (type === 'draft') ? 'saveDraft' : 'confirmShipment';
            }
            
            const button = (type === 'draft') ? $('.save-draft-btn') : $('.confirm-shipment-btn');
            
            button.addClass('loading').prop('disabled', true);
            const originalText = button.html();
            button.html('<span class="spinner"></span> Salvando...');
            
            $('#loading-overlay').addClass('show');
            
            $.ajax({
                url: '?action=' + action,
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                success: function(response) {
                    if (response.success) {
                        showFeedback(response.message, 'success');
                        
                        if (currentEditingId) {
                            currentEditingId = null;
                            $('.save-draft-btn').html('<i class="fas fa-save"></i> Salva Bozza').show();
                            $('.confirm-shipment-btn').html('<i class="fas fa-check-circle"></i> Conferma Spedizione').show();
                            
                            $('.nav-tab').removeClass('active');
                            $('.nav-tab[data-tab="list"]').addClass('active');
                            $('.tab-content').hide();
                            $('#tab-list').show();
                            loadShipmentsList();
                        } else {
                            resetForm();
                            
                            if ($('#tab-list').is(':visible')) {
                                loadShipmentsList();
                            }
                        }
                    } else {
                        showFeedback(response.error || 'Errore sconosciuto', 'error');
                    }
                },
                error: function() {
                    showFeedback('Errore di connessione', 'error');
                },
                complete: function() {
                    button.removeClass('loading').prop('disabled', false).html(originalText);
                    $('#loading-overlay').removeClass('show');
                }
            });
        }
        
        function collectShipmentData(isConfirmation) {
            const boxes = [];
            
            $('.box-item').each(function() {
                const boxElement = $(this);
                const boxNumber = parseInt(boxElement.data('box'));
                
                const dimensioni = {};
                boxElement.find('.dimension-input').each(function() {
                    const dimension = $(this).data('dimension');
                    const value = $(this).val().trim();
                    dimensioni[dimension] = value;
                });
                
                const prodotti = [];
                boxElement.find('.product-item').each(function() {
                    const productElement = $(this);
                    const nome = productElement.find('.product-name').val().trim();
                    const quantita = parseInt(productElement.find('.quantity').val()) || 0;
                    const scadenza = productElement.find('.expiry').val().trim();
                    
                    prodotti.push({
                        nome: nome,
                        quantita: quantita,
                        scadenza: scadenza || null
                    });
                });
                
                boxes.push({
                    numero: boxNumber,
                    dimensioni: dimensioni,
                    prodotti: prodotti
                });
            });
            
            if (boxes.length === 0) {
                showFeedback('Almeno un collo è richiesto', 'error');
                return null;
            }
            
            const payload = { boxes: boxes };
            
            if (currentEditingId) {
                payload.id = currentEditingId;
            }
            
            return payload;
        }
        
        // === VALIDAZIONE PRODOTTI (Desktop Parity) ===
        function validateProducts(callback) {
            const productsToValidate = [];
            let hasEmptyProducts = false;
            let hasInvalidQuantities = false;
            let totalValidProducts = 0;
            
            $('.box-item').each(function() {
                const boxElement = $(this);
                const boxNumber = parseInt(boxElement.data('box'));
                
                boxElement.find('.product-item').each(function() {
                    const productElement = $(this);
                    const nome = productElement.find('.product-name').val().trim();
                    const quantita = parseInt(productElement.find('.quantity').val()) || 0;
                    
                    if (!nome) {
                        hasEmptyProducts = true;
                        showFeedback(`Box ${boxNumber}: inserire il nome del prodotto`, 'error');
                        return false;
                    }
                    
                    if (quantita <= 0) {
                        hasInvalidQuantities = true;
                        showFeedback(`Box ${boxNumber}: la quantità deve essere maggiore di zero per "${nome}"`, 'error');
                        return false;
                    }
                    
                    const input = productElement.find('.product-name');
                    const isValidatedFromAutocomplete = input.data('validated') === true;
                    
                    if (!productsToValidate.some(p => p.nome === nome)) {
                        productsToValidate.push({ nome, boxNumber });
                    }
                    
                    if (nome && quantita > 0) {
                        totalValidProducts++;
                    }
                });
                
                if (hasEmptyProducts || hasInvalidQuantities) return false;
            });
            
            if (hasEmptyProducts || hasInvalidQuantities) {
                callback(false);
                return;
            }
            
            if (totalValidProducts === 0) {
                showFeedback('Almeno un prodotto è richiesto', 'error');
                callback(false);
                return;
            }
            
            if (productsToValidate.length === 0) {
                showFeedback('Errore interno di validazione', 'error');
                callback(false);
                return;
            }
            
            let validatedCount = 0;
            let hasInvalidProducts = false;
            
            showValidationLoader(productsToValidate.length);
            
            let currentIndex = 0;
            
            function validateNextProduct() {
                if (currentIndex >= productsToValidate.length) {
                    return;
                }
                
                const product = productsToValidate[currentIndex];
                currentIndex++;
                
                const normalizedName = product.nome.replace(/\s+/g, ' ').trim();
                
                $.ajax({
                    url: '?action=validateProduct',
                    data: { name: normalizedName },
                    success: function(response) {
                        validatedCount++;
                        
                        if (!response.success || !response.data.valid) {
                            hasInvalidProducts = true;
                            showFeedback(`"${product.nome}" non trovato. Digita almeno 2 caratteri per vedere l'elenco e seleziona un prodotto esistente.`, 'error');
                        }
                        
                        updateValidationProgress(validatedCount, productsToValidate.length);
                        
                        if (validatedCount === productsToValidate.length) {
                            hideValidationLoader();
                            callback(!hasInvalidProducts);
                        } else {
                            setTimeout(validateNextProduct, 100);
                        }
                    },
                    error: function(xhr, status, error) {
                        validatedCount++;
                        hasInvalidProducts = true;
                        
                        if (xhr.status === 401 || (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.includes('Sessione scaduta'))) {
                            hideValidationLoader();
                            showFeedback('Sessione scaduta. Ricarica la pagina per effettuare il login.', 'error');
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                            return;
                        }
                        
                        showFeedback(`Errore validazione prodotto "${product.nome}"`, 'error');
                        
                        updateValidationProgress(validatedCount, productsToValidate.length);
                        
                        if (validatedCount === productsToValidate.length) {
                            hideValidationLoader();
                            callback(false);
                        } else {
                            setTimeout(validateNextProduct, 100);
                        }
                    }
                });
            }
            
            validateNextProduct();
        }
        
        // === GESTIONE LOADER VALIDAZIONE (Desktop Parity) ===
        function showValidationLoader(totalProducts) {
            if (!$('#validation-loader').length) {
                const loaderHtml = `
                    <div id="validation-loader" class="validation-overlay">
                        <div class="validation-modal">
                            <div class="validation-content">
                                <div class="validation-spinner"></div>
                                <h3>Validazione Prodotti in Corso</h3>
                                <p class="validation-status">Controllo prodotti nel database...</p>
                                <div class="validation-progress">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: 0%"></div>
    </div>
                                    <div class="progress-text">0 / ${totalProducts} prodotti validati</div>
    </div>
        </div>
        </div>
        </div>
                `;
                $('body').append(loaderHtml);
            }
            $('#validation-loader').show();
        }
        
        function updateValidationProgress(current, total) {
            const percentage = Math.round((current / total) * 100);
            $('#validation-loader .progress-fill').css('width', percentage + '%');
            $('#validation-loader .progress-text').text(`${current} / ${total} prodotti validati`);
            
            if (current === total) {
                $('#validation-loader .validation-status').text('Validazione completata!');
            }
        }
        
        function hideValidationLoader() {
            setTimeout(() => {
                $('#validation-loader').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 500);
        }
        
        function validateDimensions() {
            let isValid = true;
            
            $('.box-item').each(function() {
                if (!isValid) return false;
                
                const boxElement = $(this);
                const boxNumber = parseInt(boxElement.data('box'));
                
                const altezza = boxElement.find('[data-dimension="altezza"]').val().trim();
                const larghezza = boxElement.find('[data-dimension="larghezza"]').val().trim();
                const lunghezza = boxElement.find('[data-dimension="lunghezza"]').val().trim();
                const peso = boxElement.find('[data-dimension="peso"]').val().trim();
                
                if (!altezza || !larghezza || !lunghezza || !peso) {
                    showFeedback(`Box ${boxNumber}: inserire altezza, larghezza, lunghezza e peso per confermare la spedizione`, 'error');
                    isValid = false;
                    return false;
                }
                
                if (parseFloat(altezza) <= 0 || parseFloat(larghezza) <= 0 || 
                    parseFloat(lunghezza) <= 0 || parseFloat(peso) <= 0) {
                    showFeedback(`Box ${boxNumber}: le dimensioni e il peso devono essere maggiori di zero`, 'error');
                    isValid = false;
                    return false;
                }
            });
            
            return isValid;
        }
        
        function resetForm() {
            $('#boxes-container').empty();
            boxCounter = 0;
            currentEditingId = null;
            addBox();
        }
        
        // === GESTIONE LISTA SPEDIZIONI (Desktop Parity) ===
        function loadShipmentsList() {
            $.ajax({
                url: '?action=getFlowStats',
                success: function(response) {
                    if (response.success) {
                        updateFlowCards(response.data);
                    }
                }
            });
            
            $.ajax({
                url: '?action=getShipments',
                success: function(response) {
                    if (response.success) {
                        populateShipmentsTable(response.data);
                    } else {
                        showFeedback('Errore caricamento spedizioni', 'error');
                    }
                }
            });
        }
        
        function updateFlowCards(stats) {
            document.getElementById('flow-completed').textContent = stats.completed || 0;
            document.getElementById('flow-boxes').textContent = stats.total_boxes || 0;
            document.getElementById('flow-volume').textContent = stats.total_volume || 0;
            document.getElementById('flow-units').textContent = stats.total_units || 0;
            document.getElementById('flow-weight').textContent = stats.total_weight || 0;
            document.getElementById('flow-draft').textContent = stats.draft || 0;
            document.getElementById('flow-cancelled').textContent = stats.cancelled || 0;
            
            document.getElementById('flow-top-count').textContent = stats.top_products_total || (stats.top_products || []).length;
            document.getElementById('flow-regular-count').textContent = stats.regular_products_total || (stats.regular_products || []).length;
            document.getElementById('flow-low-count').textContent = stats.low_products_total || (stats.low_products || []).length;
            
            document.getElementById('flow-top').innerHTML = formatProductListStyle2(stats.top_products);
            document.getElementById('flow-regular').innerHTML = formatProductListStyle2(stats.regular_products);
            document.getElementById('flow-low').innerHTML = formatProductListStyle2(stats.low_products);
        }
        
        function formatProductListStyle2(products) {
            if (!products || products.length === 0) {
                return '<div class="style2-product-item">Nessun dato</div>';
            }
            
            return products.map((p) => {
                return `<div class="style2-product-item">${p.total} pz ${p.product_name}</div>`;
            }).join('');
        }
        
        function populateShipmentsTable(shipments) {
            const tbody = $('#shipments-table tbody');
            tbody.empty();
            
            if (shipments.length === 0) {
                tbody.append('<div style="text-align: center; padding: 2rem; color: #94a3b8;">Nessuna spedizione trovata</div>');
            return;
        }
        
        const statusClass = {
            'Completed': 'status-completed',
            'Draft': 'status-draft',
            'Cancelled': 'status-cancelled'
        };
        
        const statusLabel = {
            'Completed': 'Completata',
            'Draft': 'Bozza',
            'Cancelled': 'Annullata'
        };
        
            const statusIcon = {
                'Completed': '✅',
                'Draft': '📋',
                'Cancelled': '❌'
            };
            
            const html = shipments.map(s => `
                <div class="shipment-card-mobile">
                    <div class="shipment-card-header" onclick="toggleShipmentDetails(${s.id})">
                        <div style="flex: 1;">
                            <div class="shipment-name">${statusIcon[s.status] || '📦'} ${s.name}</div>
                        <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.25rem;">${s.created_at}</div>
                    </div>
                        <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span class="shipment-status ${statusClass[s.status] || 'status-draft'}">
                        ${statusLabel[s.status] || s.status}
                    </span>
                            <i class="fas fa-chevron-down shipment-toggle" id="toggle-${s.id}" style="color: #94a3b8; transition: transform 0.3s;"></i>
                </div>
                    </div>
                    
                    <div class="shipment-stats-mobile">
                    <div class="shipment-stat">
                        <div class="shipment-stat-value">${s.total_boxes || 0}</div>
                        <div class="shipment-stat-label">Colli</div>
                    </div>
                    <div class="shipment-stat">
                        <div class="shipment-stat-value">${s.total_units || 0}</div>
                        <div class="shipment-stat-label">Unità</div>
                    </div>
                    <div class="shipment-stat">
                        <div class="shipment-stat-value">#${s.id}</div>
                        <div class="shipment-stat-label">ID</div>
                    </div>
                </div>
                    
                    <div class="shipment-details-mobile" id="details-${s.id}" style="display: none;">
                        <div class="shipment-actions-mobile">
                            ${renderShipmentActions(s)}
                        </div>
                        <div class="shipment-content" id="content-${s.id}">
                            <div style="text-align: center; padding: 1rem; color: #94a3b8;">
                                <div class="spinner-small"></div>
                                <div style="margin-top: 0.5rem;">Caricamento...</div>
                            </div>
                    </div>
                </div>
            </div>
        `).join('');
            
            tbody.html(html);
        }
        
        // Render Actions based on status
        function renderShipmentActions(shipment) {
            if (shipment.status === 'Cancelled') {
                return `
                    <div style="text-align: center; padding: 1rem; color: #94a3b8; font-size: 0.875rem;">
                        ❌ Spedizione annullata - Nessuna azione disponibile
                    </div>
                `;
            }
            
            if (shipment.status === 'Draft') {
                return `
                    <button class="action-btn-mobile edit" onclick="event.stopPropagation(); editShipment(${shipment.id})">
                        <i class="fas fa-edit"></i> Modifica
                    </button>
                    <button class="action-btn-mobile confirm" onclick="event.stopPropagation(); confirmShipmentMobile(${shipment.id})">
                        <i class="fas fa-check"></i> Conferma
                    </button>
                    <button class="action-btn-mobile delete" onclick="event.stopPropagation(); deleteShipment(${shipment.id})">
                        <i class="fas fa-trash"></i> Elimina
                    </button>
                `;
            }
            
            if (shipment.status === 'Completed') {
                return `
                    <button class="action-btn-mobile cancel" onclick="event.stopPropagation(); cancelAndDuplicate(${shipment.id})">
                        <i class="fas fa-copy"></i> Annulla e Duplica
                    </button>
                `;
            }
            
            return '';
        }
        
        // Toggle Shipment Details
        function toggleShipmentDetails(id) {
            const detailsEl = $(`#details-${id}`);
            const toggleIcon = $(`#toggle-${id}`);
            const contentEl = $(`#content-${id}`);
            
            if (detailsEl.css('display') === 'none') {
                // Expand
                detailsEl.show();
                toggleIcon.css('transform', 'rotate(180deg)');
                
                // Load details if not already loaded
                if (!contentEl.data('loaded')) {
                    $.ajax({
                        url: `?action=getShipmentDetails&id=${id}`,
                        success: function(response) {
                            if (response.success) {
                                contentEl.html(renderShipmentContent(response.data));
                                contentEl.data('loaded', 'true');
                            } else {
                                contentEl.html(`<div style="text-align: center; padding: 1rem; color: #ef4444;">Errore: ${response.error || 'Impossibile caricare dettagli'}</div>`);
                            }
                        },
                        error: function() {
                            contentEl.html('<div style="text-align: center; padding: 1rem; color: #ef4444;">Errore di connessione</div>');
                        }
                    });
                }
            } else {
                // Collapse
                detailsEl.hide();
                toggleIcon.css('transform', 'rotate(0deg)');
            }
        }
        
        // Render Shipment Content
        function renderShipmentContent(data) {
            const { boxes } = data;
            
            return boxes.map((box, index) => `
                <div class="box-details-mobile">
                    <div class="box-details-header">
                        📦 Collo ${box.numero}
                </div>
                
                    ${box.prodotti && box.prodotti.length > 0 ? `
                        <div class="box-products">
                            <div class="box-section-title">Prodotti</div>
                            ${box.prodotti.map(p => `
                                <div class="product-details-mobile">
                                    <div style="font-weight: 600; font-size: 0.875rem; color: #1e293b;">${p.nome}</div>
                                    <div style="display: flex; gap: 1rem; margin-top: 0.5rem; font-size: 0.75rem; color: #64748b;">
                                        <span>📦 Qtà: ${p.quantita}</span>
                                        ${p.scadenza ? `<span>📅 Scad: ${p.scadenza}</span>` : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
                    ` : ''}
                    
                    ${(box.dimensioni.altezza || box.dimensioni.peso) ? `
                        <div class="box-dimensions">
                            <div class="box-section-title">Dimensioni</div>
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; font-size: 0.75rem; color: #64748b;">
                                ${box.dimensioni.altezza ? `<div>📏 H: ${box.dimensioni.altezza} cm</div>` : ''}
                                ${box.dimensioni.larghezza ? `<div>📏 L: ${box.dimensioni.larghezza} cm</div>` : ''}
                                ${box.dimensioni.lunghezza ? `<div>📏 P: ${box.dimensioni.lunghezza} cm</div>` : ''}
                                ${box.dimensioni.peso ? `<div>⚖️ Peso: ${box.dimensioni.peso} kg</div>` : ''}
                    </div>
                </div>
                    ` : ''}
            </div>
        `).join('');
    }
    
        function editShipment(id) {
            $.ajax({
                url: '?action=getShipmentDetails&id=' + id,
                success: function(response) {
                    if (response.success) {
                        loadShipmentForEdit(id, response.data);
                        
                        $('.nav-tab').removeClass('active');
                        $('.nav-tab[data-tab="create"]').addClass('active');
                        $('.tab-content').hide();
                        $('#tab-create').show();
                    } else {
                        showFeedback('Errore caricamento spedizione', 'error');
                    }
                }
            });
        }
        
        function viewShipment(id) {
            $.ajax({
                url: '?action=getShipmentDetails&id=' + id,
                success: function(response) {
                    if (response.success) {
                        showShipmentModal(response.data);
                    } else {
                        showFeedback('Errore caricamento spedizione', 'error');
                    }
                }
            });
        }
        
        function showShipmentModal(data) {
            const shipment = data.shipment;
            const boxes = data.boxes;
            
            let modalContent = `
                <div class="modal-content" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <h3><i class="fas fa-eye"></i> ${shipment.name}</h3>
                        <button class="modal-close" onclick="closeShipmentModal()">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="shipment-summary">
                            <div class="summary-item">
                                <strong>Status:</strong> 
                                <span class="status-badge status-completed">COMPLETATA</span>
                            </div>
                            <div class="summary-item">
                                <strong>Totale Box:</strong> ${boxes.length}
                            </div>
                            <div class="summary-item">
                                <strong>Totale Unità:</strong> ${boxes.reduce((total, box) => 
                                    total + box.prodotti.reduce((boxTotal, prod) => boxTotal + parseInt(prod.quantita), 0), 0
                                )}
                            </div>
                        </div>
                        
                        <div class="boxes-details">
            `;
            
            boxes.forEach(box => {
                modalContent += `
                    <div class="box-detail">
                        <div class="box-header">
                            <h4><i class="fas fa-box"></i> Box ${box.numero}</h4>
                            <div class="box-dimensions">
                `;
                
                if (box.dimensioni.peso || box.dimensioni.altezza || box.dimensioni.larghezza || box.dimensioni.lunghezza) {
                    modalContent += `<small>`;
                    if (box.dimensioni.peso) modalContent += `Peso: ${box.dimensioni.peso}kg `;
                    if (box.dimensioni.larghezza) modalContent += `L: ${box.dimensioni.larghezza}cm `;
                    if (box.dimensioni.lunghezza) modalContent += `P: ${box.dimensioni.lunghezza}cm `;
                    if (box.dimensioni.altezza) modalContent += `H: ${box.dimensioni.altezza}cm`;
                    modalContent += `</small>`;
                }
                
                modalContent += `
                            </div>
                        </div>
                        <div class="products-list">
                `;
                
                box.prodotti.forEach(prod => {
                    modalContent += `
                        <div class="product-item">
                            <div class="product-info">
                                <span class="product-name">${prod.nome}</span>
                                <span class="product-quantity">Qtà: ${prod.quantita}</span>
                            </div>
                            ${prod.scadenza ? `<div class="product-expiry">Scadenza: ${prod.scadenza}</div>` : ''}
                        </div>
                    `;
                });
                
                modalContent += `
                        </div>
                    </div>
                `;
            });
            
            modalContent += `
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-secondary" onclick="closeShipmentModal()" style="width: 100%;">
                            <i class="fas fa-times"></i> Chiudi
                        </button>
                    </div>
                </div>
            `;
            
            $('#view-modal').html(modalContent).addClass('show');
        }
        
        function closeShipmentModal() {
            $('#view-modal').removeClass('show').empty();
        }
        
        function loadShipmentForEdit(id, data) {
            currentEditingId = id;
            
            $('#boxes-container').empty();
            boxCounter = 0;
            
            data.boxes.forEach(box => {
                boxCounter = box.numero;
                addBoxForEdit(box);
            });
            
            $('.save-draft-btn').hide();
            $('.confirm-shipment-btn').html('<i class="fas fa-check-circle"></i> Conferma Spedizione').show();
        }
        
        function addBoxForEdit(boxData) {
            const boxHtml = `
                <div class="box-item" data-box="${boxData.numero}">
                    <div class="box-header">
                        <h4 class="box-title">📦 Box ${boxData.numero}</h4>
                        <button class="remove-box-btn" onclick="removeBox(${boxData.numero})">
                            <i class="fas fa-trash"></i> Rimuovi
                        </button>
            </div>
            
                    <div class="dimensions-grid">
                        <div class="dimension-field">
                            <label>Altezza (cm)</label>
                            <input type="number" class="dimension-input" data-dimension="altezza" step="0.1" min="0" value="${boxData.dimensioni.altezza || ''}">
                </div>
                        <div class="dimension-field">
                            <label>Larghezza (cm)</label>
                            <input type="number" class="dimension-input" data-dimension="larghezza" step="0.1" min="0" value="${boxData.dimensioni.larghezza || ''}">
            </div>
                        <div class="dimension-field">
                            <label>Lunghezza (cm)</label>
                            <input type="number" class="dimension-input" data-dimension="lunghezza" step="0.1" min="0" value="${boxData.dimensioni.lunghezza || ''}">
                                    </div>
                        <div class="dimension-field">
                            <label>Peso (kg)</label>
                            <input type="number" class="dimension-input" data-dimension="peso" step="0.01" min="0" value="${boxData.dimensioni.peso || ''}">
                                </div>
                        </div>
                    
                    <div class="section-header">
                        <i class="fas fa-box-open"></i>
                        <h4>Prodotti in questo Box</h4>
                            </div>
                    
                    <button class="add-btn" onclick="addProduct(${boxData.numero})">
                        <i class="fas fa-plus"></i> Aggiungi Prodotto
                    </button>
                    
                    <div class="products-container" data-box="${boxData.numero}">
                        <!-- Prodotti inseriti qui -->
                        </div>
                </div>
            `;
            
            $('#boxes-container').append(boxHtml);
            
            boxData.prodotti.forEach(product => {
                addProductForEdit(boxData.numero, product);
            });
            
            setTimeout(() => {
                $(`.box-item[data-box="${boxData.numero}"] .product-name`).each(function() {
                    $(this).addClass('validated-product').data('validated', true);
                    $(this).attr('title', '✓ Prodotto caricato dal database - Validato');
                });
                
                updateSaveButtonsState();
            }, 100);
        }
        
        function addProductForEdit(boxId, productData) {
            const productId = Date.now() + Math.random();
            const productHtml = `
                <div class="product-item" data-product="${productId}">
                    <div class="autocomplete-container">
                        <input type="text" class="product-input product-name" placeholder="Nome prodotto..." 
                               data-product="${productId}" onkeyup="handleAutocomplete(this)" oninput="handleProductInput(this)" value="${productData.nome}">
                        <div class="autocomplete-results" id="autocomplete-${productId}"></div>
                    </div>
                    <input type="number" class="product-input quantity" placeholder="Quantità" min="1" value="${productData.quantita}" required>
                    <input type="date" class="product-input expiry" placeholder="Scadenza (opzionale)" value="${productData.scadenza || ''}">
                    <button class="remove-product-btn" onclick="removeProduct(${productId})">
                        <i class="fas fa-times"></i>
                    </button>
            </div>
        `;
        
            $(`.products-container[data-box="${boxId}"]`).append(productHtml);
        }
        
        function deleteShipment(id) {
            if (!confirm('Sei sicuro di voler eliminare questa spedizione?')) return;
            
            $.ajax({
                url: '?action=deleteShipment',
                method: 'POST',
                data: { id: id },
                success: function(response) {
                    if (response.success) {
                        showFeedback(response.message, 'success');
                        loadShipmentsList();
                    } else {
                        showFeedback(response.error, 'error');
                    }
                }
            });
        }
        
        function changeStatus(id, newStatus) {
            const statusText = newStatus === 'Completed' ? 'completare' : 'riaprire';
            if (!confirm(`Sei sicuro di voler ${statusText} questa spedizione?`)) return;
            
            if (newStatus === 'Completed') {
                $.ajax({
                    url: '?action=getShipmentDetails&id=' + id,
                    success: function(response) {
                        if (response.success) {
                            const payload = {
                                id: id,
                                boxes: response.data.boxes
                            };
                            
                            $.ajax({
                                url: '?action=updateShipmentComplete',
                method: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify(payload),
                                success: function(response) {
                                    if (response.success) {
                                        showFeedback('Spedizione completata con validazione ed email!', 'success');
                                        loadShipmentsList();
                                    } else {
                                        showFeedback(response.error, 'error');
                                    }
                                }
                            });
                        } else {
                            showFeedback('Errore caricamento spedizione', 'error');
                        }
                    }
                });
            } else {
                $.ajax({
                    url: '?action=changeStatus',
                    method: 'POST',
                    data: { id: id, status: newStatus },
                    success: function(response) {
                        if (response.success) {
                            showFeedback(response.message, 'success');
                            loadShipmentsList();
                        } else {
                            showFeedback(response.error, 'error');
                        }
                    }
                });
            }
        }
        
        function cancelAndDuplicate(id) {
            if (!confirm('Annullare questa spedizione e crearne una copia modificabile?')) return;
            
            $.ajax({
                url: '?action=cancelAndDuplicate',
                method: 'POST',
                data: { id: id },
                success: function(response) {
                    if (response.success) {
                        showFeedback('Spedizione annullata e duplicata. Modifica la nuova copia.', 'success');
                        
                        loadShipmentForEdit(response.data.new_shipment_id, response.data.shipment_data);
                        
                        $('.nav-tab').removeClass('active');
                        $('.nav-tab[data-tab="create"]').addClass('active');
                        $('.tab-content').hide();
                        $('#tab-create').show();
            } else {
                        showFeedback(response.error, 'error');
                    }
                },
                error: function() {
                    showFeedback('Errore di connessione', 'error');
                }
            });
        }
        
        // === SHARED FUNCTIONS (Desktop Parity) ===
        function showFeedback(message, type = 'success') {
            // Rimuovi eventuali alert precedenti
            $('.feedback-alert').remove();
            
            const bgColor = type === 'success' ? '#10b981' : '#ef4444';
            
            const alert = $('<div class="feedback-alert"></div>');
            alert.text(message);
            alert.css({
                'position': 'fixed',
                'top': '50%',
                'left': '50%',
                'transform': 'translate(-50%, -50%)',
                'background': bgColor,
                'color': 'white',
                'padding': '1rem 1.5rem',
                'border-radius': '12px',
                'font-weight': '600',
                'font-size': '0.875rem',
                'z-index': '99999',
                'box-shadow': '0 4px 20px rgba(0,0,0,0.3)',
                'max-width': '90%',
                'text-align': 'center',
                'opacity': '0',
                'transition': 'opacity 0.3s ease'
            });
            
            $('body').append(alert);
            
            // Fade in
            setTimeout(() => alert.css('opacity', '1'), 10);
            
            // Fade out e rimuovi dopo 3 secondi
            setTimeout(() => {
                alert.css('opacity', '0');
                setTimeout(() => alert.remove(), 300);
            }, 3000);
        }
        
        // === HAMBURGER MENU (Mobile Pattern) ===
        $(document).ready(function() {
            $('.hamburger-btn-hero').on('click', function() {
                $('.hamburger-overlay').addClass('active');
                $('body').css('overflow', 'hidden');
            });
            
            $('.hamburger-overlay').on('click', function(e) {
                if ($(e.target).hasClass('hamburger-overlay')) {
                    $(this).removeClass('active');
                    $('body').css('overflow', '');
                }
            });
        });
        
        // === MOBILE-SPECIFIC SHIPMENT FUNCTIONS ===
        
        // Confirm Shipment Mobile
        function confirmShipmentMobile(id) {
            if (!confirm('Confermare la spedizione? Verranno validate dimensioni e inviata email.')) return;
            
            $('#loading-overlay').addClass('show');
            
            // Carica dettagli per validazione completa
            $.ajax({
                url: `?action=getShipmentDetails&id=${id}`,
                success: function(detailsResponse) {
                    if (!detailsResponse.success) {
                        showFeedback('Errore caricamento dettagli', 'error');
                        $('#loading-overlay').removeClass('show');
                        return;
                    }
                    
                    // Usa updateShipmentComplete per validazione e email
                    $.ajax({
                        url: '?action=updateShipmentComplete',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            id: id,
                            boxes: detailsResponse.data.boxes
                        }),
                        success: function(response) {
                            if (response.success) {
                                showFeedback(response.message || 'Spedizione confermata!', 'success');
                                loadShipmentsList();
            } else {
                                showFeedback(response.error || 'Errore nella conferma', 'error');
                            }
                        },
                        error: function() {
                            showFeedback('Errore di connessione', 'error');
                        },
                        complete: function() {
                            $('#loading-overlay').removeClass('show');
                        }
                    });
                },
                error: function() {
                    showFeedback('Errore di connessione', 'error');
                    $('#loading-overlay').removeClass('show');
                }
            });
        }
        
        // Logout function
        function doLogout() {
            if (confirm('Sei sicuro di voler uscire?')) {
                window.location.href = '/modules/margynomic/login/logout.php';
        }
    }
    </script>
</body>
</html>
