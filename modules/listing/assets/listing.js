/**
 * Listing Module - JavaScript
 * File: modules/listing/assets/listing.js
 * 
 * Gestione drag & drop e chiamate API per admin_list.php
 */

class ProductListing {
    constructor() {
        this.currentUserId = null;
        this.currentPage = 1;
        this.currentSearch = '';
        this.sortable = null;
        this.isLoading = false;
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadInitialData();
    }
    
    bindEvents() {
        // Cambio utente
        const userSelect = document.getElementById('user-select');
        if (userSelect) {
            userSelect.addEventListener('change', (e) => {
                this.currentUserId = parseInt(e.target.value);
                this.currentPage = 1;
                this.loadProducts();
            });
        }
        
        // Ricerca
        const searchInput = document.getElementById('search-input');
        if (searchInput) {
            let searchTimeout;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.currentSearch = e.target.value.trim();
                    this.currentPage = 1;
                    this.loadProducts();
                }, 300);
            });
        }
        
        // Pulsanti azione
        document.getElementById('save-order-btn')?.addEventListener('click', () => {
            this.saveOrder();
        });
        
        document.getElementById('reset-order-btn')?.addEventListener('click', () => {
            this.resetOrder();
        });
        
        // Paginazione
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('page-btn')) {
                e.preventDefault();
                const page = parseInt(e.target.dataset.page);
                if (page && page !== this.currentPage) {
                    this.currentPage = page;
                    this.loadProducts();
                }
            }
        });
    }
    
    loadInitialData() {
        const userSelect = document.getElementById('user-select');
        if (userSelect && userSelect.value) {
            this.currentUserId = parseInt(userSelect.value);
            this.loadProducts();
        }
    }
    
    async loadProducts() {
        if (!this.currentUserId || this.isLoading) return;
        
        this.setLoading(true);
        
        try {
            const params = new URLSearchParams({
                user_id: this.currentUserId,
                page: this.currentPage,
                limit: 100
            });
            
            if (this.currentSearch) {
                params.set('search', this.currentSearch);
            }
            
            const response = await fetch(`api/get_products.php?${params}`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Errore caricamento prodotti');
            }
            
            this.renderProducts(data.data.products);
            this.renderPagination(data.data.pagination);
            this.initDragDrop();
            
        } catch (error) {
            this.showError('Errore caricamento: ' + error.message);
        } finally {
            this.setLoading(false);
        }
    }
    
    renderProducts(products) {
        const container = document.getElementById('products-container');
        if (!container) return;
        
        if (products.length === 0) {
            container.innerHTML = '<div class="no-products">Nessun prodotto trovato</div>';
            return;
        }
        
        const html = products.map(product => `
            <div class="product-item" data-product-id="${product.id}">
                <div class="drag-handle">⋮⋮</div>
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
                        `<span class="status-mapped">Pos: ${product.position}</span>` : 
                        '<span class="status-unmapped">Non mappato</span>'
                    }
                </div>
            </div>
        `).join('');
        
        container.innerHTML = html;
    }
    
    renderPagination(pagination) {
        const container = document.getElementById('pagination-container');
        if (!container) return;
        
        if (pagination.total_pages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '<div class="pagination">';
        
        // Previous
        if (pagination.has_prev) {
            html += `<button class="page-btn btn" data-page="${pagination.current_page - 1}">« Prec</button>`;
        }
        
        // Page numbers
        const startPage = Math.max(1, pagination.current_page - 2);
        const endPage = Math.min(pagination.total_pages, pagination.current_page + 2);
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === pagination.current_page ? ' active' : '';
            html += `<button class="page-btn btn${activeClass}" data-page="${i}">${i}</button>`;
        }
        
        // Next
        if (pagination.has_next) {
            html += `<button class="page-btn btn" data-page="${pagination.current_page + 1}">Succ »</button>`;
        }
        
        html += '</div>';
        html += `<div class="pagination-info">Pagina ${pagination.current_page} di ${pagination.total_pages} (${pagination.total_count} prodotti)</div>`;
        
        container.innerHTML = html;
    }
    
    initDragDrop() {
        const container = document.getElementById('products-container');
        if (!container) return;
        
        // Destroy existing sortable
        if (this.sortable) {
            this.sortable.destroy();
        }
        
        // Initialize SortableJS
        this.sortable = Sortable.create(container, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onEnd: (evt) => {
                this.onDragEnd(evt);
            }
        });
    }
    
    onDragEnd(evt) {
        // Abilita pulsante salva se l'ordine è cambiato
        const saveBtn = document.getElementById('save-order-btn');
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Salva Ordine *';
        }
    }
    
    async saveOrder() {
        if (!this.currentUserId || this.isLoading) return;
        
        const productItems = document.querySelectorAll('#products-container .product-item');
        const productIds = Array.from(productItems).map(item => 
            parseInt(item.dataset.productId)
        );
        
        if (productIds.length === 0) {
            this.showError('Nessun prodotto da salvare');
            return;
        }
        
        this.setLoading(true);
        
        try {
            const response = await fetch('api/save_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: this.currentUserId,
                    product_ids: productIds
                })
            });
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Errore salvataggio');
            }
            
            this.showSuccess(data.message);
            
            // Disabilita pulsante salva
            const saveBtn = document.getElementById('save-order-btn');
            if (saveBtn) {
                saveBtn.disabled = true;
                saveBtn.textContent = 'Salva Ordine';
            }
            
            // Ricarica per aggiornare posizioni
            this.loadProducts();
            
        } catch (error) {
            this.showError('Errore salvataggio: ' + error.message);
        } finally {
            this.setLoading(false);
        }
    }
    
    async resetOrder() {
        if (!this.currentUserId || this.isLoading) return;
        
        if (!confirm('Ripristinare l\'ordine alfabetico? Questa azione sovrascriverà l\'ordinamento attuale.')) {
            return;
        }
        
        this.setLoading(true);
        
        try {
            // Carica tutti i prodotti in ordine alfabetico
            const response = await fetch(`api/get_products.php?user_id=${this.currentUserId}&limit=1000`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'Errore caricamento prodotti');
            }
            
            // Ordina alfabeticamente
            const sortedProducts = data.data.products.sort((a, b) => {
                const nameA = a.nome.toLowerCase();
                const nameB = b.nome.toLowerCase();
                if (nameA < nameB) return -1;
                if (nameA > nameB) return 1;
                return a.id - b.id; // tie-breaker
            });
            
            const productIds = sortedProducts.map(p => p.id);
            
            // Salva ordine alfabetico
            const saveResponse = await fetch('api/save_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_id: this.currentUserId,
                    product_ids: productIds
                })
            });
            
            const saveData = await saveResponse.json();
            
            if (!saveData.success) {
                throw new Error(saveData.error || 'Errore salvataggio ordine alfabetico');
            }
            
            this.showSuccess('Ordine alfabetico ripristinato');
            this.loadProducts();
            
        } catch (error) {
            this.showError('Errore ripristino: ' + error.message);
        } finally {
            this.setLoading(false);
        }
    }
    
    setLoading(loading) {
        this.isLoading = loading;
        const loader = document.getElementById('loading-indicator');
        const saveBtn = document.getElementById('save-order-btn');
        const resetBtn = document.getElementById('reset-order-btn');
        
        if (loader) {
            loader.style.display = loading ? 'block' : 'none';
        }
        
        if (saveBtn) saveBtn.disabled = loading;
        if (resetBtn) resetBtn.disabled = loading;
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
        container.innerHTML = `<div class="alert ${alertClass}">${this.escapeHtml(message)}</div>`;
        
        // Auto-hide dopo 5 secondi
        setTimeout(() => {
            container.innerHTML = '';
        }, 5000);
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new ProductListing();
}); 