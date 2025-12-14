<?php
/**
 * EasyShip - Gestione Spedizioni Multi-Box
 * File: modules/easyship/easyship.php
 * 
 * Interfaccia utente per creare e gestire spedizioni multi-box
 * con prodotti, dimensioni e gestione bolle PDF
 */

session_start();

// Gestione AJAX - Delega a easyship_api.php
$action = $_REQUEST['action'] ?? null;
if ($action) {
    require_once 'easyship_api.php';
    exit;
}

// === PARTE HTML ===
require_once 'config_easyship.php';

// Autenticazione
$currentUser = requireEasyShipAuth();
$userId = $currentUser['id'];

// Redirect mobile
if (!function_exists('isMobileDevice')) {
    require_once dirname(__DIR__) . '/margynomic/login/auth_helpers.php';
}
if (isMobileDevice()) {
    header('Location: /modules/mobile/EasyShip.php');
    exit;
}

// Include header condiviso
require_once dirname(__DIR__) . '/margynomic/shared_header.php';
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EasyShip - Gestione Spedizioni</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <div class="easyship-container">
        <div class="main-container">
            <!-- Hero Welcome -->
            <div class="welcome-hero">
                <div class="welcome-content">
                    <h1 class="welcome-title">
                        <i class="fas fa-truck"></i> EasyShip AI System
                    </h1>
                    <p class="welcome-subtitle">
                        SCOPRI COME IL SISTEMA TIENE TRACCIA DI OGNI SPEDIZIONE ATTRAVERSO UNA GESTIONE MULTI-BOX PROFESSIONALE!
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
                        <div style="background: rgba(220, 38, 38, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #dc2626;">
                            <h4 style="color: #dc2626; font-weight: 700; margin-bottom: 0.5rem;">🎯 Target FBA</h4>
                            <p style="color: #64748b; line-height: 1.6; margin: 0;">Sistema ottimizzato specificamente per requisiti Amazon FBA.</p>
                        </div>
                        
                        <div style="background: rgba(220, 38, 38, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #dc2626;">
                            <h4 style="color: #dc2626; font-weight: 700; margin-bottom: 0.5rem;">🧮 Smart Packing</h4>
                            <p style="color: #64748b; line-height: 1.6; margin: 0;">Algoritmi suggeriscono distribuzione ottimale prodotti per minimizzare costi.</p>
                        </div>
                        
                        <div style="background: rgba(220, 38, 38, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #dc2626;">
                            <h4 style="color: #dc2626; font-weight: 700; margin-bottom: 0.5rem;">🔄 Template Riutilizzabili</h4>
                            <p style="color: #64748b; line-height: 1.6; margin: 0;">Duplica spedizioni ricorrenti con un click.</p>
                        </div>
                        
                        <div style="background: rgba(220, 38, 38, 0.05); border-radius: 12px; padding: 1.5rem; border-left: 4px solid #dc2626;">
                            <h4 style="color: #dc2626; font-weight: 700; margin-bottom: 0.5rem;">📊 Storico Completo</h4>
                            <p style="color: #64748b; line-height: 1.6; margin: 0;">Archivio digitale di tutte le spedizioni con ricerca avanzata.</p>
                        </div>
                    </div>
            </div>

            <!-- Strategic Flow Grid -->
<div class="strategic-flow-section">
    <div class="style2-grid">
    <!-- Card 1: Spedizioni Completate -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">✅</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-completed">0</span></div>
                <div class="style2-label">Spedizioni Completate</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-stat">
                <span class="style2-stat-label">Stato</span>
                <span class="style2-stat-value">Confermate</span>
            </div>
            <div class="style2-stat">
                <span class="style2-stat-label">Database</span>
                <span class="style2-stat-value">Tracking OK</span>
            </div>
        </div>
    </div>
    
    <!-- Card 2: Colli + Volume -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">📦</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-boxes">0</span></div>
                <div class="style2-label">Colli Totali</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-stat">
                <span class="style2-stat-label">Volume Spedito</span>
                <span class="style2-stat-value"><span id="flow-volume">0</span> m³</span>
            </div>
            <div class="style2-stat">
                <span class="style2-stat-label">Tracking</span>
                <span class="style2-stat-value">Attivo</span>
            </div>
        </div>
    </div>
    
    <!-- Card 3: Unità + Peso -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">📊</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-units">0</span></div>
                <div class="style2-label">Unità Spedite</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-stat">
                <span class="style2-stat-label">Peso Totale</span>
                <span class="style2-stat-value"><span id="flow-weight">0</span> kg</span>
            </div>
            <div class="style2-stat">
                <span class="style2-stat-label">Stato</span>
                <span class="style2-stat-value">In Viaggio</span>
            </div>
        </div>
    </div>
    
    <!-- Card 4: Bozze + Annullate -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">📝</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-draft">0</span></div>
                <div class="style2-label">Bozze</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-stat">
                <span class="style2-stat-label">Annullate</span>
                <span class="style2-stat-value"><span id="flow-cancelled">0</span></span>
            </div>
            <div class="style2-stat">
                <span class="style2-stat-label">Stato</span>
                <span class="style2-stat-value">Da Completare</span>
            </div>
        </div>
    </div>
</div>

<!-- Products Grid - Same width as 4 cards above -->
<div class="style2-grid style2-grid-products">
    <!-- Card 5: Prodotti Top -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">🔥</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-top-count">0</span></div>
                <div class="style2-label">Prodotti Top (90 D)</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-product-list" id="flow-top">
                <div class="style2-product-item">Nessun dato</div>
            </div>
        </div>
    </div>
    
    <!-- Card 6: Prodotti Regolari -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">✔️</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-regular-count">0</span></div>
                <div class="style2-label">Prodotti Regolari (90 D)</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-product-list" id="flow-regular">
                <div class="style2-product-item">Nessun dato</div>
            </div>
        </div>
    </div>
    
    <!-- Card 7: Prodotti Scarsi -->
    <div class="style2-card">
        <div class="style2-header">
            <div class="style2-icon">⚠️</div>
            <div class="style2-header-text">
                <div class="style2-number"><span id="flow-low-count">0</span></div>
                <div class="style2-label">Prodotti Scarsi (90 D)</div>
            </div>
        </div>
        <div class="style2-body">
            <div class="style2-product-list" id="flow-low">
                <div class="style2-product-item">Nessun dato</div>
            </div>
        </div>
</div>
</div>
</div>
</div>              
            <!-- Navigation Tabs -->
        <div class="content-container">
            <div class="nav-tabs">
                <button class="nav-tab active" data-tab="create">
                    <i class="fas fa-plus"></i> Nuova Spedizione
                </button>
                <button class="nav-tab" data-tab="list">
                    <i class="fas fa-list"></i> Le Mie Spedizioni
                </button>
                <?php if (isEasyShipAdmin($currentUser)): ?>
                <a href="../margynomic/admin/admin_easyship.php" class="nav-tab" style="text-decoration: none;">
                    <i class="fas fa-user-shield"></i> Admin Panel
                </a>
                <?php endif; ?>
            </div>

            <!-- Create Shipment Tab -->
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

            <!-- List Shipments Tab -->
            <div id="tab-list" class="tab-content" style="display: none;">
                <div class="shipments-list">
                    <div class="list-header">
                        <h3><i class="fas fa-list"></i> Le Mie Spedizioni</h3>
                        <p>Gestisci le tue spedizioni create</p>
                    </div>
                    
                    <table class="shipments-table" id="shipments-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Nome Spedizione</th>
                                <th>Stato</th>
                                <th>Data Creazione</th>
                                <th>Box</th>
                                <th>Unità Tot.</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Righe dinamiche -->
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (isEasyShipAdmin($currentUser)): ?>
            <!-- Admin Tab -->
            <div id="tab-admin" class="tab-content" style="display: none;">
                <div class="admin-redirect">
                    <div class="section-header">
                        <i class="fas fa-external-link-alt"></i>
                        <h3>Pannello Amministrativo</h3>
                    </div>
                    <div style="text-align: center; padding: 40px;">
                        <p style="font-size: 16px; margin-bottom: 20px;">Il pannello admin è stato spostato nel sistema centralizzato Margynomic.</p>
                        <a href="../margynomic/admin/admin_easyship.php" class="confirm-shipment-btn" style="display: inline-block; text-decoration: none;">
                            🚚 Vai al Pannello Admin EasyShip
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Feedback Toast -->
    <div id="feedback" class="feedback"></div>

    <script>
        // === VARIABILI GLOBALI ===
        let boxCounter = 0;
        let currentEditingId = null;

        // === INIZIALIZZAZIONE ===
        $(document).ready(function() {
            // Carica statistiche flow grid immediatamente
            loadShipmentsList();
            
            // Aggiungi primo box di default
            addBox();
            
            // Validazione iniziale dello stato dei pulsanti
            updateSaveButtonsState();
            
            // Tab switching
            $('.nav-tab').click(function() {
                if ($(this).attr('href')) return; // Skip admin link
                
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

        // === GESTIONE BOX ===
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
            
            // Aggiungi primo prodotto di default
            addProduct(boxCounter);
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

        // === AUTOCOMPLETE PRODOTTI ===
        let autocompleteTimeout;
        
        function handleProductInput(input) {
            // Questa funzione è chiamata dall'HTML ma ora usiamo l'event listener 'input'
            // Manteniamo per compatibilità ma la logica è gestita dall'event listener
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
                                // Escape caratteri speciali per evitare errori JavaScript
                                const escapedProduct = product.replace(/'/g, "\\'").replace(/"/g, '\\"');
                                html += `<div class="autocomplete-item" onclick="selectProduct('${productId}', '${escapedProduct}')">${product}</div>`;
                            });
                            resultsContainer.html(html).show();
                        } else if (response.success && response.data.length === 0) {
                            // Mostra messaggio quando non ci sono risultati
                            resultsContainer.html('<div class="autocomplete-no-results">Nessun prodotto trovato</div>').show();
                        } else {
                            resultsContainer.hide().empty();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Errore autocomplete:', error);
                        resultsContainer.hide().empty();
                    }
                });
            }, 300);
        }

        function selectProduct(productId, productName) {
            const input = $(`.product-input[data-product="${productId}"]`);
            input.val(productName);
            input.addClass('validated-product'); // Marca come validato dall'elenco
            input.data('validated', true);
            $(`#autocomplete-${productId}`).hide().empty();
            
            // Pulisci errori e stati non validati
            clearProductError(input);
            
            // Feedback visivo immediato
            input.attr('title', '✓ Prodotto selezionato dall\'elenco - Validato');
            
            // Aggiorna stato pulsanti
            updateSaveButtonsState();
            
            console.log(`Product "${productName}" selected from autocomplete and validated`);
        }

        // Nascondi autocomplete quando si clicca fuori
        $(document).click(function(e) {
            if (!$(e.target).closest('.autocomplete-container').length) {
                $('.autocomplete-results').hide();
            }
        });

        // === VALIDAZIONE IN TEMPO REALE ===
        $(document).on('input', '.product-name', function() {
            const input = $(this);
            const currentValue = input.val().trim();
            
            // Rimuovi validazione quando l'utente modifica manualmente
            if (input.data('validated') === true) {
                input.removeData('validated');
                input.removeClass('validated-product');
                input.removeAttr('title');
                console.log(`Removed validation for manually edited product: "${currentValue}"`);
            }
            
            // Aggiungi classe per prodotto non validato
            if (currentValue.length > 0) {
                input.addClass('unvalidated-product');
                markProductAsInvalid(input, 'Prodotto non selezionato dall\'elenco');
            } else {
                input.removeClass('unvalidated-product invalid-product');
                clearProductError(input);
            }
            
            // Controlla se ci sono prodotti non validati e aggiorna i pulsanti
            updateSaveButtonsState();
        });

        // Gestione focus out per validazione finale
        $(document).on('blur', '.product-name', function() {
            const input = $(this);
            const currentValue = input.val().trim();
            
            if (currentValue.length > 0 && !input.data('validated')) {
                markProductAsInvalid(input, 'Prodotto inesistente - Seleziona dall\'elenco');
            }
        });

        // === FUNZIONI DI VALIDAZIONE VISIVA ===
        function markProductAsInvalid(input, message) {
            input.addClass('invalid-product');
            input.attr('title', '❌ ' + message);
            
            // Aggiungi messaggio di errore se non esiste
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
                console.log('Save buttons disabled due to invalid/unvalidated products');
            } else {
                $('.save-draft-btn, .confirm-shipment-btn').removeClass('disabled-by-validation');
                console.log('Save buttons enabled - all products valid');
            }
        }

        // === SALVATAGGIO SPEDIZIONE ===
        function saveShipment(type) {
            // Controllo preventivo per prodotti non validati
            const invalidProducts = $('.invalid-product, .unvalidated-product');
            if (invalidProducts.length > 0) {
                const invalidNames = [];
                invalidProducts.each(function() {
                    const name = $(this).val().trim();
                    if (name) invalidNames.push(name);
                });
                
                showFeedback(`Impossibile salvare: ${invalidNames.length} prodotto/i non validato/i. Tutti i prodotti devono essere selezionati dall'elenco autocomplete.`, 'error');
                
                // Evidenzia i prodotti problematici
                invalidProducts.each(function() {
                    $(this).focus().blur();
                });
                
                return;
            }
            
            // Valida prodotti SEMPRE (sia bozza che conferma)
            validateProducts(function(isValid) {
                if (!isValid) return;
                
                // Valida dimensioni obbligatorie per conferma
                if (type === 'confirm' || currentEditingId) {
                    if (!validateDimensions()) return;
                }
                
                const payload = collectShipmentData(type === 'confirm');
                if (!payload) return;
                
                // Procedi con il salvataggio
                proceedWithSave(type, payload);
            });
        }

        function proceedWithSave(type, payload) {
            
            // Determina l'azione basandosi su modalità editing
            let action;
            if (currentEditingId) {
                // Modalità modifica - sempre completa (solo un bottone)
                action = 'updateShipmentComplete';
            } else {
                // Modalità creazione - usa saveDraft o confirmShipment
                action = (type === 'draft') ? 'saveDraft' : 'confirmShipment';
            }
                
                const button = (type === 'draft') ? $('.save-draft-btn') : $('.confirm-shipment-btn');
                
                // Loading state
                button.addClass('loading').prop('disabled', true);
                const originalText = button.html();
                button.html('<span class="spinner"></span> Salvando...');
                
                $.ajax({
                    url: '?action=' + action,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(payload),
                    success: function(response) {
                        if (response.success) {
                            showFeedback(response.message, 'success');
                            
                            // Gestione post-salvataggio
                            if (currentEditingId) {
                                // Modalità aggiornamento - torna alla lista
                                currentEditingId = null;
                                $('.save-draft-btn').html('<i class="fas fa-save"></i> Salva Bozza').show();
                                $('.confirm-shipment-btn').html('<i class="fas fa-check-circle"></i> Conferma Spedizione').show();
                                
                                // Switch alla tab lista
                                $('.nav-tab').removeClass('active');
                                $('.nav-tab[data-tab="list"]').addClass('active');
                                $('.tab-content').hide();
                                $('#tab-list').show();
                                loadShipmentsList();
                            } else {
                                // Modalità creazione - reset form
                                resetForm();
                                
                                // Aggiorna lista se visibile
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
                    }
                });
            }

            function collectShipmentData(isConfirmation) {
        console.log('=== DEBUG collectShipmentData START ===');
        console.log('isConfirmation:', isConfirmation);
        
        const boxes = [];
        let totalBoxesProcessed = 0;
        
        $('.box-item').each(function() {
            totalBoxesProcessed++;
            console.log(`Processing box ${totalBoxesProcessed}`);
            const boxElement = $(this);
            const boxNumber = parseInt(boxElement.data('box'));
            
            // Dimensioni
            const dimensioni = {};
            boxElement.find('.dimension-input').each(function() {
                const dimension = $(this).data('dimension');
                const value = $(this).val().trim();
                dimensioni[dimension] = value;
            });
            
            // Prodotti - INCLUDE TUTTI i prodotti per validazione (non filtrare qui)
            const prodotti = [];
            let productsInThisBox = 0;
            
            boxElement.find('.product-item').each(function() {
                productsInThisBox++;
                const productElement = $(this);
                const nome = productElement.find('.product-name').val().trim();
                const quantita = parseInt(productElement.find('.quantity').val()) || 0;
                const scadenza = productElement.find('.expiry').val().trim();
                
                console.log(`  Product ${productsInThisBox} in box ${boxNumber}: "${nome}" (qty: ${quantita})`);
                
                // Includi TUTTI i prodotti, anche quelli vuoti (la validazione avviene prima)
                prodotti.push({
                    nome: nome,
                    quantita: quantita,
                    scadenza: scadenza || null
                });
            });
            
            console.log(`Box ${boxNumber} contains ${productsInThisBox} products`);
            
            boxes.push({
                numero: boxNumber,
                dimensioni: dimensioni,
                prodotti: prodotti
            });
        });
        
        console.log(`Total boxes processed: ${totalBoxesProcessed}`);
        console.log('Final boxes array:', boxes);
        
        // Count total products across all boxes
        const totalProducts = boxes.reduce((sum, box) => sum + box.prodotti.length, 0);
        console.log(`Total products across all boxes: ${totalProducts}`);
        
        // Validazione base solo per numero di box
        if (boxes.length === 0) {
            console.log('ERROR: No boxes found');
            showFeedback('Almeno un collo è richiesto', 'error');
            return null;
        }
        
        // La validazione prodotti avviene ora PRIMA di chiamare questa funzione
        
        const payload = { boxes: boxes };
        
        // Se editing, aggiungi ID
        if (currentEditingId) {
            payload.id = currentEditingId;
            console.log('Adding currentEditingId to payload:', currentEditingId);
        }
        
        console.log('Final payload before return:', payload);
        return payload;
    }

            // === VALIDAZIONE PRODOTTI ===
            function validateProducts(callback) {
                console.log('=== DEBUG validateProducts START ===');
                
                // Count total products in DOM
                const totalProductsInDOM = $('.product-item').length;
                console.log('Total products found in DOM:', totalProductsInDOM);
                
                const productsToValidate = [];
                let hasEmptyProducts = false;
                let hasInvalidQuantities = false;
                let totalValidProducts = 0; // Conta prodotti validi (autocomplete + da validare)
                
                // Raccogli tutti i prodotti da validare
                $('.box-item').each(function() {
                    const boxElement = $(this);
                    const boxNumber = parseInt(boxElement.data('box'));
                    
                    boxElement.find('.product-item').each(function() {
                        const productElement = $(this);
                        const nome = productElement.find('.product-name').val().trim();
                        const quantita = parseInt(productElement.find('.quantity').val()) || 0;
                        
                        // Verifica campi vuoti
                        if (!nome) {
                            hasEmptyProducts = true;
                            showFeedback(`Box ${boxNumber}: inserire il nome del prodotto`, 'error');
                            return false;
                        }
                        
                        // Verifica quantità 
                        if (quantita <= 0) {
                            hasInvalidQuantities = true;
                            showFeedback(`Box ${boxNumber}: la quantità deve essere maggiore di zero per "${nome}"`, 'error');
                            return false;
                        }
                        
                        // SEMPRE aggiungi alla lista per validazione database (sicurezza)
                        const input = productElement.find('.product-name');
                        const isValidatedFromAutocomplete = input.data('validated') === true;
                        
                        console.log(`Product "${nome}" - Box ${boxNumber} - Validated from autocomplete:`, isValidatedFromAutocomplete);
                        console.log(`Product "${nome}" - data('validated') value:`, input.data('validated'));
                        
                        // Aggiungi SEMPRE per validazione database (evita duplicati)
                        if (!productsToValidate.some(p => p.nome === nome)) {
                            productsToValidate.push({ nome, boxNumber });
                            console.log(`Added "${nome}" to productsToValidate array for database validation`);
                        } else {
                            console.log(`Product "${nome}" already in validation queue`);
                        }
                        
                        // Conta tutti i prodotti validi (con nome e quantità > 0)
                        if (nome && quantita > 0) {
                            totalValidProducts++;
                        }
                    });
                    
                    if (hasEmptyProducts || hasInvalidQuantities) return false;
                });
                
                // Se ci sono errori di base, ferma qui
                if (hasEmptyProducts || hasInvalidQuantities) {
                    console.log('Validation failed due to empty products or invalid quantities');
                    callback(false);
                    return;
                }
                
                console.log('Products to validate array:', productsToValidate);
                console.log('Products to validate count:', productsToValidate.length);
                console.log('Total valid products (including autocomplete):', totalValidProducts);
                
                // Se non ci sono prodotti validi in totale
                if (totalValidProducts === 0) {
                    console.log('ERROR: No valid products found - triggering "Almeno un prodotto è richiesto"');
                    showFeedback('Almeno un prodotto è richiesto', 'error');
                    callback(false);
                    return;
                }
                
                // Se non ci sono prodotti da validare (non dovrebbe mai succedere ora)
                if (productsToValidate.length === 0) {
                    console.log('ERROR: productsToValidate array is empty - this should not happen');
                    showFeedback('Errore interno di validazione', 'error');
                    callback(false);
                    return;
                }
                
                // Valida ogni prodotto nel database
                let validatedCount = 0;
                let hasInvalidProducts = false;
                
                console.log('Starting AJAX validation for products:', productsToValidate.map(p => p.nome));
                
                // Mostra indicatore di caricamento
                showValidationLoader(productsToValidate.length);
                
                // Valida prodotti in sequenza invece che in parallelo per evitare loop 508
                let currentIndex = 0;
                
                function validateNextProduct() {
                    if (currentIndex >= productsToValidate.length) {
                        return; // Tutti i prodotti processati
                    }
                    
                    const product = productsToValidate[currentIndex];
                    currentIndex++;
                    
                    console.log(`Validating product ${currentIndex}/${productsToValidate.length}: "${product.nome}"`);
                    
                    // Normalizza spazi multipli nel nome prodotto
                    const normalizedName = product.nome.replace(/\s+/g, ' ').trim();
                    console.log(`Normalized name: "${normalizedName}"`);
                    
                    $.ajax({
                        url: '?action=validateProduct',
                        data: { name: normalizedName },
                        success: function(response) {
                            validatedCount++;
                            console.log(`AJAX validation result for "${product.nome}":`, response.success && response.data.valid ? 'VALID' : 'INVALID');
                            console.log(`Validated count: ${validatedCount}/${productsToValidate.length}`);
                            
                            if (!response.success || !response.data.valid) {
                                hasInvalidProducts = true;
                                console.log(`Product "${product.nome}" marked as invalid`);
                                showFeedback(`"${product.nome}" non trovato. Digita almeno 2 caratteri per vedere l'elenco e seleziona un prodotto esistente.`, 'error');
                            }
                            
                            // Aggiorna progress bar
                            updateValidationProgress(validatedCount, productsToValidate.length);
                            
                            // Quando tutti i prodotti sono stati validati
                            if (validatedCount === productsToValidate.length) {
                                console.log('All products validated. hasInvalidProducts:', hasInvalidProducts);
                                console.log('Final callback result:', !hasInvalidProducts);
                                hideValidationLoader();
                                callback(!hasInvalidProducts);
                            } else {
                                // Valida il prossimo prodotto dopo un piccolo delay
                                setTimeout(validateNextProduct, 100);
                            }
                        },
                        error: function(xhr, status, error) {
                            validatedCount++;
                            hasInvalidProducts = true;
                            console.log(`AJAX error for product "${product.nome}": ${status} - ${error}`);
                            console.log(`HTTP Status: ${xhr.status}`);
                            console.log(`Validated count: ${validatedCount}/${productsToValidate.length}`);
                            
                            // Gestione specifica per errori di autenticazione
                            if (xhr.status === 401 || (xhr.responseJSON && xhr.responseJSON.error && xhr.responseJSON.error.includes('Sessione scaduta'))) {
                                hideValidationLoader();
                                showFeedback('Sessione scaduta. Ricarica la pagina per effettuare il login.', 'error');
                                setTimeout(() => {
                                    window.location.reload();
                                }, 2000);
                                return;
                            }
                            
                            showFeedback(`Errore validazione prodotto "${product.nome}"`, 'error');
                            
                            // Aggiorna progress bar anche per errori
                            updateValidationProgress(validatedCount, productsToValidate.length);
                            
                            if (validatedCount === productsToValidate.length) {
                                console.log('All products processed (with errors). Final result: false');
                                hideValidationLoader();
                                callback(false);
                            } else {
                                // Continua con il prossimo prodotto anche in caso di errore
                                setTimeout(validateNextProduct, 100);
                            }
                        }
                    });
                }
                
                // Inizia la validazione sequenziale
                validateNextProduct();
            }

            // === GESTIONE LOADER VALIDAZIONE ===
            function showValidationLoader(totalProducts) {
                // Crea overlay di caricamento se non esiste
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
                }, 500); // Piccolo delay per mostrare il completamento
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

            // === GESTIONE LISTA SPEDIZIONI ===
            function loadShipmentsList() {
    // Carica statistiche flow
    $.ajax({
        url: '?action=getFlowStats',
        success: function(response) {
            if (response.success) {
                updateFlowCards(response.data);
            }
        }
    });
    
    // Carica lista spedizioni
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
    // Card 1: Completate
    document.getElementById('flow-completed').textContent = stats.completed || 0;
    
    // Card 2: Colli + Volume
    document.getElementById('flow-boxes').textContent = stats.total_boxes || 0;
    document.getElementById('flow-volume').textContent = stats.total_volume || 0;
    
    // Card 3: Unità + Peso
    document.getElementById('flow-units').textContent = stats.total_units || 0;
    document.getElementById('flow-weight').textContent = stats.total_weight || 0;
    
    // Card 4: Bozze + Annullate
    document.getElementById('flow-draft').textContent = stats.draft || 0;
    document.getElementById('flow-cancelled').textContent = stats.cancelled || 0;
    
    // Card 5-7: Prodotti con conteggi TOTALI
document.getElementById('flow-top-count').textContent = stats.top_products_total || (stats.top_products || []).length;
document.getElementById('flow-regular-count').textContent = stats.regular_products_total || (stats.regular_products || []).length;
document.getElementById('flow-low-count').textContent = stats.low_products_total || (stats.low_products || []).length;

// Liste prodotti
document.getElementById('flow-top').innerHTML = formatProductListStyle2(stats.top_products);
document.getElementById('flow-regular').innerHTML = formatProductListStyle2(stats.regular_products);
document.getElementById('flow-low').innerHTML = formatProductListStyle2(stats.low_products);
}

function formatProductListStyle2(products) {
    if (!products || products.length === 0) {
        return '<div class="style2-product-item">Nessun dato</div>';
    }
    
    return products.map((p) => {
        return `<div class="style2-product-item">
            ${p.total} pz ${p.product_name}
        </div>`;
    }).join('');
}

            function populateShipmentsTable(shipments) {
    const tbody = $('#shipments-table tbody');
    tbody.empty();
    
    if (shipments.length === 0) {
        tbody.append('<tr><td colspan="7" style="text-align: center; padding: 2rem; color: #666;">Nessuna spedizione trovata</td></tr>');
        return;
    }
                
                shipments.forEach(shipment => {
                    let statusClass, statusText;
                    
                    // Determina classe e testo status
                    if (shipment.status === 'Draft') {
                        statusClass = 'status-draft';
                        statusText = 'BOZZA';
                    } else if (shipment.status === 'Cancelled') {
                        statusClass = 'status-cancelled';
                        statusText = 'ANNULLATA';
                    } else if (shipment.status === 'Completed') {
                        statusClass = 'status-completed';
                        statusText = 'COMPLETATA';
                    } else {
                        // Fallback per stati non riconosciuti
                        statusClass = 'status-completed';
                        statusText = shipment.status || 'N/A';
                    }
                    
                    // Determina azioni disponibili
                    let actionsHtml = '';
                    
                    if (shipment.status === 'Cancelled') {
                        // Spedizione annullata - solo indicatore visivo
                        actionsHtml = `
                            <span class="status-indicator cancelled">
                                <i class="fas fa-ban"></i> Spedizione Annullata
                            </span>
                        `;
                    } else if (shipment.status === 'Draft') {
                        actionsHtml = `
                            <button class="action-btn" onclick="editShipment(${shipment.id})">
                                <i class="fas fa-edit"></i> Modifica
                            </button>
                            <button class="action-btn secondary" onclick="changeStatus(${shipment.id}, 'Completed')">
                                <i class="fas fa-check"></i> Conferma
                            </button>
                            <button class="action-btn secondary" onclick="deleteShipment(${shipment.id})">
                                <i class="fas fa-trash"></i> Elimina
                            </button>
                        `;
                    } else if (shipment.status === 'Completed') {
                        actionsHtml = `
                            <button class="action-btn primary" onclick="viewShipment(${shipment.id})">
                                <i class="fas fa-eye"></i> Visualizza
                            </button>
                            <button class="action-btn secondary" onclick="cancelAndDuplicate(${shipment.id})">
                                <i class="fas fa-copy"></i> Annulla e Duplica
                            </button>
                        `;
                    }
                    
                    const row = `
                        <tr>
                            <td>${shipment.id}</td>
                            <td>${shipment.name}</td>
                            <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                            <td>${shipment.created_at}</td>
                            <td>${shipment.total_boxes || 0}</td>
                            <td>${shipment.total_units || 0}</td>
                            <td>${actionsHtml}</td>
                        </tr>
                    `;
                    
                    tbody.append(row);
                });
            }

            function editShipment(id) {
                $.ajax({
                    url: '?action=getShipmentDetails&id=' + id,
                    success: function(response) {
                        if (response.success) {
                            loadShipmentForEdit(id, response.data);
                            
                            // Switch to create tab
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
                    <div class="modal-overlay" onclick="closeShipmentModal()">
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
                                <button class="btn btn-secondary" onclick="closeShipmentModal()">
                                    <i class="fas fa-times"></i> Chiudi
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                $('body').append(modalContent);
            }

            function closeShipmentModal() {
                $('.modal-overlay').remove();
            }

            function loadShipmentForEdit(id, data) {
                currentEditingId = id;
                
                // Reset form
                $('#boxes-container').empty();
                boxCounter = 0;
                
                // Carica dati
                data.boxes.forEach(box => {
                    boxCounter = box.numero;
                    addBoxForEdit(box);
                });
                
                // Aggiorna bottoni per modalità modifica - solo "Completa Spedizione"
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
                
                // Aggiungi prodotti esistenti
                boxData.prodotti.forEach(product => {
                    addProductForEdit(boxData.numero, product);
                });
                
                // Marca tutti i prodotti caricati come validati (vengono dal database)
                setTimeout(() => {
                    $(`.box-item[data-box="${boxData.numero}"] .product-name`).each(function() {
                        $(this).addClass('validated-product').data('validated', true);
                        $(this).attr('title', '✓ Prodotto caricato dal database - Validato');
                    });
                    
                    // Aggiorna stato pulsanti dopo caricamento
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
    
    // Se si sta completando una bozza, usa il flusso completo con validazione
    if (newStatus === 'Completed') {
        // Carica la spedizione per validazione e completamento
        $.ajax({
            url: '?action=getShipmentDetails&id=' + id,
            success: function(response) {
                if (response.success) {
                    // Simula il flusso di completamento con validazione
                    const payload = {
                        id: id,
                        boxes: response.data.boxes
                    };
                    
                    // Usa updateShipmentComplete che fa validazione e invia email
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
        // Per altri cambi stato, usa il metodo semplice
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
                    
                    // Carica la spedizione duplicata per modifica
                    loadShipmentForEdit(response.data.new_shipment_id, response.data.shipment_data);
                    
                    // Switch to create tab
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

        // === SHARED FUNCTIONS ===
        
        function showFeedback(message, type = 'success') {
            const feedback = $('#feedback');
            feedback.removeClass('success error').addClass(type);
            feedback.text(message).addClass('show');
            
            setTimeout(() => {
                feedback.removeClass('show');
            }, 4000);
        }
    </script>
</body>
</html>