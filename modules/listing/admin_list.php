<?php
/**
 * Admin Product Listing Interface - VERSIONE MIGLIORATA
 * File: modules/listing/admin_list.php
 * 
 * Interfaccia admin per gestione ordinamento prodotti per utente
 */
// Includi admin helpers per il menu
require_once __DIR__ . '/../margynomic/admin/admin_helpers.php';
requireAdmin();

// Includi helpers
require_once __DIR__ . '/helpers.php';

// Verifica autenticazione admin
requireListingAdmin();

// Ottieni lista utenti per dropdown
$users = getListingUsers();

// Utente selezionato (default primo utente se disponibile)
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($users[0]['id'] ?? 0);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Ordine Prodotti - Admin Listing</title>
    
    <!-- Stili -->
    <link rel="stylesheet" href="../margynomic/css/margynomic.css">
    <link rel="stylesheet" href="assets/listing.css">
    
    <style>
        /* === LAYOUT BASE === */
        .listing-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            font-family: Arial, sans-serif;
        }

        .listing-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .listing-title {
            margin: 0 0 10px 0;
            color: #333;
            font-size: 24px;
            font-weight: bold;
        }

        .listing-subtitle {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        /* === CONTROLLI MIGLIORATI === */
        .controls-panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .controls-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }

        .controls-row:last-child {
            margin-bottom: 0;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }

        .form-select, .form-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            min-width: 200px;
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        /* === FILTRI AVANZATI === */
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-toggle {
            display: flex;
            background: #f8f9fa;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #ddd;
        }

        .filter-btn {
            padding: 6px 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .filter-btn.active {
            background: #007bff;
            color: white;
        }

        .filter-btn:not(.active):hover {
            background: #e9ecef;
        }

        /* === ORDINAMENTO RAPIDO === */
        .sort-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .sort-btn {
            padding: 6px 10px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.2s;
        }

        .sort-btn:hover {
            background: #f8f9fa;
            border-color: #007bff;
        }

        .sort-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }

        /* === PULSANTI === */
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
            transition: background-color 0.2s;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover:not(:disabled) {
            background: #1e7e34;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover:not(:disabled) {
            background: #e0a800;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover:not(:disabled) {
            background: #138496;
        }

        /* === LISTA PRODOTTI === */
        .products-panel {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .products-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .products-title {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .products-stats {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        #products-container {
            padding: 0;
            min-height: 200px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .product-item {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
            cursor: grab;
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-item:hover {
            background-color: #f8f9fa;
        }

        .product-item.sortable-chosen {
            background-color: #e3f2fd;
        }

        .product-item.sortable-ghost {
            opacity: 0.4;
        }

        .drag-handle {
            color: #666;
            cursor: grab;
            font-size: 16px;
            margin-right: 15px;
            user-select: none;
            font-weight: bold;
        }

        .drag-handle:active {
            cursor: grabbing;
        }

        .product-info {
            flex: 1;
            min-width: 0;
        }

        .product-name {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            word-break: break-word;
        }

        .product-meta {
            font-size: 12px;
            color: #666;
        }

        .product-status {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 200px;
        }

        .status-mapped {
            background: #d4edda;
            color: #155724;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-unmapped {
            background: #fff3cd;
            color: #856404;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .position-input {
            width: 70px;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            font-size: 12px;
            font-weight: 600;
        }

        .position-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        .position-input.modified {
            background: #fff3cd;
            border-color: #ffc107;
        }

        .no-products {
            padding: 60px 20px;
            text-align: center;
            color: #666;
            font-style: italic;
        }

        .loading-indicator {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }

        /* === MESSAGGI === */
        .alert {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* === SELEZIONE MULTIPLA === */
        .select-checkbox {
            margin-right: 10px;
        }

        .bulk-actions {
            display: none;
            padding: 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
        }

        .bulk-actions.show {
            display: block;
        }

        .selected-count {
            font-weight: 600;
            color: #007bff;
        }

        /* === RESPONSIVE === */
        @media (max-width: 768px) {
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }

            .form-select, .form-input {
                min-width: auto;
            }

            .products-header {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                justify-content: center;
            }

            .product-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .product-status {
                align-self: flex-end;
                min-width: auto;
            }

            .filter-group, .sort-buttons {
                justify-content: center;
            }
        }
    </style>
    
    <!-- SortableJS per drag & drop -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
</head>
<body style="background: #f5f5f5; margin: 0;">

<?php echo getAdminNavigation('product_listing'); ?>

    <div class="listing-container">
        
        <!-- Header -->
        <div class="listing-header">
            <h1 class="listing-title">📋 Gestione Ordine Prodotti Avanzata</h1>
            <p class="listing-subtitle">Controllo completo dell'ordinamento di visualizzazione dei prodotti per ogni utente</p>
        </div>

        <!-- Messaggi -->
        <div id="message-container"></div>

        <!-- Controlli -->
        <div class="controls-panel">
            <!-- Prima riga: Utente e Ricerca -->
            <div class="controls-row">
                <!-- Selezione Utente -->
                <div class="form-group">
                    <label class="form-label" for="user-select">Utente:</label>
                    <select id="user-select" class="form-select">
                        <option value="">-- Seleziona Utente --</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $user['id'] == $selectedUserId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['nome']) ?> (<?= htmlspecialchars($user['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Ricerca -->
                <div class="form-group">
                    <label class="form-label" for="search-input">Cerca:</label>
                    <input type="text" id="search-input" class="form-input" placeholder="Nome prodotto, SKU o ASIN...">
                </div>
            </div>

            <!-- Seconda riga: Filtri -->
            <div class="controls-row">
                <div class="form-group">
                    <label class="form-label">Filtro Mappatura:</label>
                    <div class="filter-toggle">
                        <button class="filter-btn active" data-filter="all">Tutti</button>
                        <button class="filter-btn" data-filter="mapped">Solo Mappati</button>
                        <button class="filter-btn" data-filter="unmapped">Non Mappati</button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Ordinamento Rapido:</label>
                    <div class="sort-buttons">
                        <button class="sort-btn" data-sort="position">📍 Per Posizione</button>
                        <button class="sort-btn" data-sort="name">🔤 Per Nome</button>
                        <button class="sort-btn" data-sort="sku">🏷️ Per SKU</button>
                        <button class="sort-btn" data-sort="recent">🕐 Più Recenti</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading Indicator -->
        <div id="loading-indicator" class="loading-indicator" style="display: none;">
            Caricamento...
        </div>

        <!-- Panel Prodotti -->
        <div class="products-panel">
            
            <!-- Azioni Bulk (nascosta inizialmente) -->
            <div id="bulk-actions" class="bulk-actions">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span>Selezionati: <span class="selected-count">0</span> prodotti</span>
                    <div style="display: flex; gap: 10px;">
                        <button id="bulk-map-btn" class="btn btn-info">📍 Auto-Mappa Selezionati</button>
                        <button id="bulk-unmap-btn" class="btn btn-warning">❌ Rimuovi Mappatura</button>
                        <button id="select-all-btn" class="btn btn-secondary">✅ Seleziona Tutti</button>
                        <button id="clear-selection-btn" class="btn btn-secondary">❌ Deseleziona</button>
                    </div>
                </div>
            </div>
            
            <!-- Header Prodotti -->
            <div class="products-header">
                <div>
                    <h2 class="products-title">Lista Prodotti</h2>
                    <div class="products-stats" id="products-stats">
                        Nessun prodotto caricato
                    </div>
                </div>
                <div class="action-buttons">
                    <button id="compact-positions-btn" class="btn btn-info">
                        🗜️ Compatta Posizioni
                    </button>
                    <button id="save-order-btn" class="btn btn-success" disabled>
                        💾 Salva Ordine
                    </button>
                    <button id="reset-order-btn" class="btn btn-warning">
                        🔄 Ripristina Alfabetico
                    </button>
                </div>
            </div>

            <!-- Container Prodotti (popolato via JavaScript) -->
            <div id="products-container">
                <div class="no-products">
                    Seleziona un utente per visualizzare i prodotti
                </div>
            </div>

        </div>

    </div>

    <!-- Help/Istruzioni -->
    <div class="listing-container" style="margin-top: 30px;">
        <div class="listing-header">
            <h3 style="margin: 0 0 15px 0; font-size: 18px;">🚀 Funzionalità Avanzate</h3>
            <div style="color: #666; font-size: 14px; line-height: 1.6;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                    <div>
                        <p><strong>🎯 Ordinamento Manuale:</strong></p>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Trascina prodotti con l'icona ⋮⋮</li>
                            <li>Inserisci numero nella casella posizione</li>
                            <li>Usa ordinamento rapido per sorting automatico</li>
                        </ul>
                    </div>
                    <div>
                        <p><strong>🔍 Filtri e Ricerca:</strong></p>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Filtra per stato mappatura</li>
                            <li>Ricerca avanzata su nome, SKU, ASIN</li>
                            <li>Visualizzazione di tutti i prodotti (no paginazione)</li>
                        </ul>
                    </div>
                    <div>
                        <p><strong>⚡ Azioni Batch:</strong></p>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Selezione multipla con checkbox</li>
                            <li>Auto-mapping per gruppi di prodotti</li>
                            <li>Compattazione posizioni (1,2,3...)</li>
                        </ul>
                    </div>
                    <div>
                        <p><strong>💡 Tips:</strong></p>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <li>Mappati appaiono sempre per primi</li>
                            <li>Modifiche evidenziate in giallo</li>
                            <li>Salvataggio con feedback in tempo reale</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Avanzato -->
    <script>
        class AdvancedProductListing {
            constructor() {
                this.currentUserId = null;
                this.currentSearch = '';
                this.currentFilter = 'all';
                this.sortable = null;
                this.isLoading = false;
                this.allProducts = [];
                this.filteredProducts = [];
                this.selectedProducts = new Set();
                this.hasChanges = false;
                
                this.init();
            }
            
            init() {
                this.bindEvents();
                
                // Carica utente preselezionato se presente
                const userSelect = document.getElementById('user-select');
                if (userSelect && userSelect.value) {
                    this.currentUserId = parseInt(userSelect.value);
                    this.loadProducts();
                }
            }
            
            bindEvents() {
                // Cambio utente
                document.getElementById('user-select')?.addEventListener('change', (e) => {
                    this.currentUserId = parseInt(e.target.value) || null;
                    this.clearSelection();
                    if (this.currentUserId) {
                        this.loadProducts();
                    } else {
                        this.clearProducts();
                    }
                });
                
                // Ricerca
                let searchTimeout;
                document.getElementById('search-input')?.addEventListener('input', (e) => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(() => {
                        this.currentSearch = e.target.value.trim().toLowerCase();
                        this.filterAndRenderProducts();
                    }, 300);
                });
                
                // Filtri mappatura
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        document.querySelector('.filter-btn.active')?.classList.remove('active');
                        e.target.classList.add('active');
                        this.currentFilter = e.target.dataset.filter;
                        this.filterAndRenderProducts();
                    });
                });
                
                // Ordinamento rapido
                document.querySelectorAll('.sort-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        document.querySelector('.sort-btn.active')?.classList.remove('active');
                        e.target.classList.add('active');
                        this.applySorting(e.target.dataset.sort);
                    });
                });
                
                // Pulsanti azione
                document.getElementById('save-order-btn')?.addEventListener('click', () => {
                    this.saveOrder();
                });
                
                document.getElementById('reset-order-btn')?.addEventListener('click', () => {
                    this.resetOrder();
                });
                
                document.getElementById('compact-positions-btn')?.addEventListener('click', () => {
                    this.compactPositions();
                });
                
                // Azioni bulk
                document.getElementById('bulk-map-btn')?.addEventListener('click', () => {
                    this.bulkAutoMap();
                });
                
                document.getElementById('bulk-unmap-btn')?.addEventListener('click', () => {
                    this.bulkUnmap();
                });
                
                document.getElementById('select-all-btn')?.addEventListener('click', () => {
                    this.selectAll();
                });
                
                document.getElementById('clear-selection-btn')?.addEventListener('click', () => {
                    this.clearSelection();
                });
                
                // Event delegation per input e checkbox
                document.addEventListener('input', (e) => {
                    if (e.target.classList.contains('position-input')) {
                        this.handlePositionChange(e);
                    }
                });
                
                document.addEventListener('change', (e) => {
                    if (e.target.classList.contains('select-checkbox')) {
                        this.handleSelectionChange();
                    }
                });
            }
            
            async loadProducts() {
                if (!this.currentUserId || this.isLoading) return;
                
                this.setLoading(true);
                
                try {
                    const response = await fetch(`api/get_products.php?user_id=${this.currentUserId}`);
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Errore caricamento prodotti');
                    }
                    
                    this.allProducts = data.data.products || [];
                    this.clearSelection();
                    this.filterAndRenderProducts();
                    this.updateStats();
                    this.initDragDrop();
                    
                } catch (error) {
                    this.showError('Errore caricamento: ' + error.message);
                    this.clearProducts();
                } finally {
                    this.setLoading(false);
                }
            }
            
            filterAndRenderProducts() {
                let filtered = [...this.allProducts];
                
                // Applica filtro ricerca
                if (this.currentSearch) {
                    filtered = filtered.filter(product => 
                        product.nome.toLowerCase().includes(this.currentSearch) ||
                        (product.sku && product.sku.toLowerCase().includes(this.currentSearch)) ||
                        (product.asin && product.asin.toLowerCase().includes(this.currentSearch))
                    );
                }
                
                // Applica filtro mappatura
                if (this.currentFilter === 'mapped') {
                    filtered = filtered.filter(p => p.is_mapped);
                } else if (this.currentFilter === 'unmapped') {
                    filtered = filtered.filter(p => !p.is_mapped);
                }
                
                this.filteredProducts = filtered;
                this.renderProducts(filtered);
                this.updateStats();
            }
            
            applySorting(sortType) {
                let sorted = [...this.filteredProducts];
                
                switch (sortType) {
                    case 'position':
                        sorted.sort((a, b) => {
                            if (a.is_mapped && b.is_mapped) return a.position - b.position;
                            if (a.is_mapped && !b.is_mapped) return -1;
                            if (!a.is_mapped && b.is_mapped) return 1;
                            return a.nome.localeCompare(b.nome);
                        });
                        break;
                    case 'name':
                        sorted.sort((a, b) => a.nome.localeCompare(b.nome));
                        break;
                    case 'sku':
                        sorted.sort((a, b) => {
                            const skuA = a.sku || '';
                            const skuB = b.sku || '';
                            return skuA.localeCompare(skuB);
                        });
                        break;
                    case 'recent':
                        sorted.sort((a, b) => b.id - a.id);
                        break;
                }
                
                this.renderProducts(sorted);
                this.initDragDrop();
                this.enableSaveButton();
            }
            
            renderProducts(products) {
                const container = document.getElementById('products-container');
                if (!container) return;
                
                if (products.length === 0) {
                    container.innerHTML = '<div class="no-products">Nessun prodotto trovato con i filtri attuali</div>';
                    return;
                }
                
                const html = products.map(product => `
                    <div class="product-item" data-product-id="${product.id}">
                        <input type="checkbox" class="select-checkbox" data-product-id="${product.id}" 
                               ${this.selectedProducts.has(product.id) ? 'checked' : ''}>
                        <div class="product-info">
                            <div class="product-name">${this.escapeHtml(product.nome)}</div>
                            <div class="product-meta">
                                ID: ${product.id}
                                ${product.sku ? `| SKU: ${this.escapeHtml(product.sku)}` : ''}
                                ${product.asin ? `| ASIN: ${this.escapeHtml(product.asin)}` : ''}
                            </div>
                        </div>
                        <div class="product-status">
                            ${product.is_mapped ? 
    `<span class="status-mapped">Pos: ${product.position} (ID:${product.id})</span>` : 
    '<span class="status-unmapped">Non mappato (ID:${product.id})</span>'
}
                            <input type="number" 
       class="position-input" 
       value="${product.position || ''}" 
       placeholder="Pos" 
       min="1" 
       max="999"
       step="1"
                                   data-product-id="${product.id}">
                        </div>
                    </div>
                `).join('');
                
                container.innerHTML = html;
                this.updateBulkActions();
            }
            
            handlePositionChange(e) {
                const input = e.target;
                const productId = parseInt(input.dataset.productId);
                const newPosition = parseInt(input.value);
                
                // Evidenzia modifiche
                input.classList.add('modified');
                
                if (!productId) return;
                
                // Aggiorna prodotto nell'array
                const productIndex = this.allProducts.findIndex(p => p.id === productId);
                if (productIndex !== -1) {
                    if (isNaN(newPosition) || newPosition < 1 || input.value === '') {
                        // Rimuovi mappatura se posizione vuota o invalida
                        this.allProducts[productIndex].position = null;
                        this.allProducts[productIndex].is_mapped = false;
                    } else {
                        // Assegna nuova posizione
                        this.allProducts[productIndex].position = newPosition;
                        this.allProducts[productIndex].is_mapped = true;
                    }
                }
                
                this.enableSaveButton();
            }
            
            handleSelectionChange() {
                const checkboxes = document.querySelectorAll('.select-checkbox:checked');
                this.selectedProducts.clear();
                
                checkboxes.forEach(cb => {
                    this.selectedProducts.add(parseInt(cb.dataset.productId));
                });
                
                this.updateBulkActions();
            }
            
            selectAll() {
                const checkboxes = document.querySelectorAll('.select-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = true;
                    this.selectedProducts.add(parseInt(cb.dataset.productId));
                });
                this.updateBulkActions();
            }
            
            clearSelection() {
                this.selectedProducts.clear();
                document.querySelectorAll('.select-checkbox').forEach(cb => {
                    cb.checked = false;
                });
                this.updateBulkActions();
            }
            
            updateBulkActions() {
                const bulkContainer = document.getElementById('bulk-actions');
                const countSpan = document.querySelector('.selected-count');
                
                if (countSpan) {
                    countSpan.textContent = this.selectedProducts.size;
                }
                
                if (bulkContainer) {
                    if (this.selectedProducts.size > 0) {
                        bulkContainer.classList.add('show');
                    } else {
                        bulkContainer.classList.remove('show');
                    }
                }
            }
            
            bulkAutoMap() {
                if (this.selectedProducts.size === 0) return;
                
                let nextPosition = this.getNextAvailablePosition();
                
                this.selectedProducts.forEach(productId => {
    const productIndex = this.allProducts.findIndex(p => p.id === productId);
    if (productIndex !== -1) {
        this.allProducts[productIndex].position = nextPosition;
        this.allProducts[productIndex].is_mapped = true;
        nextPosition += 1;
    }
});
                
                this.filterAndRenderProducts();
                this.enableSaveButton();
                this.showSuccess(`Auto-mappati ${this.selectedProducts.size} prodotti`);
            }
            
            bulkUnmap() {
                if (this.selectedProducts.size === 0) return;
                
                this.selectedProducts.forEach(productId => {
                    const productIndex = this.allProducts.findIndex(p => p.id === productId);
                    if (productIndex !== -1) {
                        this.allProducts[productIndex].position = null;
                        this.allProducts[productIndex].is_mapped = false;
                    }
                });
                
                this.filterAndRenderProducts();
                this.enableSaveButton();
                this.showSuccess(`Rimossa mappatura da ${this.selectedProducts.size} prodotti`);
            }
            
            compactPositions() {
                if (!confirm('Compattare le posizioni?\nI prodotti mappati saranno rinumerati 1, 2, 3...')) {
                    return;
                }
                
                const mappedProducts = this.allProducts.filter(p => p.is_mapped);
                mappedProducts.sort((a, b) => a.position - b.position);
                
                mappedProducts.forEach((product, index) => {
    product.position = index + 1;
});
                
                this.filterAndRenderProducts();
                this.enableSaveButton();
                this.showSuccess(`Posizioni compattate per ${mappedProducts.length} prodotti`);
            }
            
            getNextAvailablePosition() {
    const mappedProducts = this.allProducts.filter(p => p.is_mapped && p.position);
    if (mappedProducts.length === 0) return 1;
    
    const maxPosition = Math.max(...mappedProducts.map(p => p.position));
    return maxPosition + 1;
}
            
            updateStats() {
                const statsElement = document.getElementById('products-stats');
                if (!statsElement) return;
                
                const total = this.allProducts.length;
                const filtered = this.filteredProducts.length;
                const mapped = this.allProducts.filter(p => p.is_mapped).length;
                const unmapped = total - mapped;
                
                statsElement.innerHTML = `
                    Totali: ${total} | Visualizzati: ${filtered} | 
                    Mappati: ${mapped} | Non mappati: ${unmapped}
                `;
            }
            
            initDragDrop() {
                // Drag & drop rimosso - solo ordinamento tramite input posizione
                return;
            }
            
            onDragEnd(evt) {
                // Drag & drop rimosso - nessuna azione necessaria
                return;
            }
            
                        async saveOrder() {
                if (!this.currentUserId || this.isLoading) return;
                
                // Raccogli prodotti con posizione e ordinali per valore input numerico
                const productsWithPositions = [];
                const productItems = document.querySelectorAll('#products-container .product-item');
                
                Array.from(productItems).forEach((item, index) => {
                    const productId = parseInt(item.dataset.productId);
                    const productName = item.querySelector('.product-name')?.textContent?.trim();
                    const positionInput = item.querySelector('.position-input');
                    const inputValue = parseInt(positionInput?.value);
                    
                    if (positionInput && positionInput.value && inputValue > 0) {
                        productsWithPositions.push({
                            id: productId,
                            position: inputValue,
                            name: productName
                        });
                    }
                });
                
                // Ordina per posizione numerica input
                productsWithPositions.sort((a, b) => a.position - b.position);
                
                if (productsWithPositions.length === 0) {
                    this.showError('Nessun prodotto con posizione da salvare');
                    return;
                }
                
                // Prepara oggetto con posizioni CUSTOM
                const productPositions = {};
                productsWithPositions.forEach(p => {
                    productPositions[p.id] = p.position;
                });
                
                this.setLoading(true);
                
                try {
                    const response = await fetch('api/save_order.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            user_id: this.currentUserId,
                            product_positions: productPositions
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Errore salvataggio ordine');
                    }
                    
                    this.showSuccess('Ordine salvato con successo!');
                    this.hasChanges = false;
                    
                    // Reset pulsante salva
                    const saveBtn = document.getElementById('save-order-btn');
                    if (saveBtn) {
                        saveBtn.disabled = true;
                        saveBtn.textContent = '💾 Salva Ordine';
                    }
                    
                    // Rimuovi evidenziazioni modifiche
                    document.querySelectorAll('.position-input.modified').forEach(input => {
                        input.classList.remove('modified');
                    });
                    
                    // Ricarica prodotti per aggiornare le posizioni
                    this.loadProducts();
                    
                } catch (error) {
                    this.showError('Errore salvataggio: ' + error.message);
                } finally {
                    this.setLoading(false);
                }
            }
            
            async resetOrder() {
                if (!this.currentUserId || this.isLoading) return;
                
                if (!confirm('Ripristinare l\'ordine alfabetico?\nQuesta azione sovrascriverà l\'ordinamento attuale.')) {
                    return;
                }
                
                this.setLoading(true);
                
                try {
                    // Ordina alfabeticamente i prodotti correnti
                    const sortedProducts = [...this.allProducts].sort((a, b) => {
                        const nameA = a.nome.toLowerCase();
                        const nameB = b.nome.toLowerCase();
                        if (nameA < nameB) return -1;
                        if (nameA > nameB) return 1;
                        return a.id - b.id; // tie-breaker
                    });
                    
                    // Prepara posizioni sequenziali 1, 2, 3...
                    const productPositions = {};
                    sortedProducts.forEach((product, index) => {
                        productPositions[product.id] = index + 1;
                    });
                    
                    // Salva ordine alfabetico
                    const response = await fetch('api/save_order.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            user_id: this.currentUserId,
                            product_positions: productPositions
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (!data.success) {
                        throw new Error(data.error || 'Errore ripristino ordine alfabetico');
                    }
                    
                    this.showSuccess('Ordine alfabetico ripristinato');
                    this.loadProducts();
                    
                } catch (error) {
                    this.showError('Errore ripristino: ' + error.message);
                } finally {
                    this.setLoading(false);
                }
            }
            
            enableSaveButton() {
                const saveBtn = document.getElementById('save-order-btn');
                if (saveBtn) {
                    saveBtn.disabled = false;
                    saveBtn.textContent = '💾 Salva Ordine *';
                }
                this.hasChanges = true;
            }
            
            clearProducts() {
                const container = document.getElementById('products-container');
                if (container) {
                    container.innerHTML = '<div class="no-products">Seleziona un utente per visualizzare i prodotti</div>';
                }
                this.allProducts = [];
                this.filteredProducts = [];
                this.clearSelection();
                this.updateStats();
            }
            
            setLoading(loading) {
                this.isLoading = loading;
                const loader = document.getElementById('loading-indicator');
                const saveBtn = document.getElementById('save-order-btn');
                const resetBtn = document.getElementById('reset-order-btn');
                const compactBtn = document.getElementById('compact-positions-btn');
                
                if (loader) {
                    loader.style.display = loading ? 'block' : 'none';
                }
                
                // Disabilita tutti i pulsanti durante loading
                [saveBtn, resetBtn, compactBtn].forEach(btn => {
                    if (btn) btn.disabled = loading;
                });
                
                document.querySelectorAll('.bulk-actions button').forEach(btn => {
                    btn.disabled = loading;
                });
            }
            
            showSuccess(message) {
                this.showMessage(message, 'success');
            }
            
            showError(message) {
                this.showMessage(message, 'error');
            }
            
            showMessage(message, type) {
                const container = document.getElementById('message-container');
                if (!container) return;
                
                const alertClass = type === 'error' ? 'alert-error' : 'alert-success';
                
                container.innerHTML = `
                    <div class="alert ${alertClass}">
                        ${this.escapeHtml(message)}
                    </div>
                `;
                
                // Auto-hide dopo 5 secondi
                setTimeout(() => {
                    container.innerHTML = '';
                }, 5000);
                
                // Scroll to top per vedere il messaggio
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            
            escapeHtml(text) {
                if (!text) return '';
                const map = {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                };
                return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
            }
        }

        // Inizializza quando il DOM è carico
        document.addEventListener('DOMContentLoaded', function() {
            window.productListing = new AdvancedProductListing();
            
            // Avviso per modifiche non salvate
            window.addEventListener('beforeunload', function(e) {
                if (window.productListing && window.productListing.hasChanges) {
                    e.preventDefault();
                    e.returnValue = 'Hai modifiche non salvate. Sicuro di voler uscire?';
                    return e.returnValue;
                }
            });
        });
    </script>
    
</body>
</html>