/**
 * Admin Logs - Frontend Interactive System
 * File: admin/assets/admin_logs.js
 * 
 * Gestisce tutte le interazioni AJAX e UI dinamiche
 */

class LogViewer {
    constructor() {
        this.filters = {
            module: 'all',
            level: 'all',
            operation_type: 'all',
            user_id: '',
            date_from: '',
            date_to: '',
            search: '',
            page: 1,
            limit: 50
        };
        this.currentPage = 1;
        this.autoRefresh = false;
        this.autoRefreshTimer = null;
        this.lastLogId = 0;
        
        this.init();
    }
    
    init() {
        console.log('🚀 LogViewer inizializzato');
        
        // Bind eventi filtri
        this.bindFilterEvents();
        
        // Bind toggle context
        this.bindContextToggles();
        
        // Keyboard shortcuts
        this.bindKeyboardShortcuts();
        
        // Mobile sidebar
        this.bindMobileSidebar();
    }
    
    bindFilterEvents() {
        // Module filter
        const moduleSelect = document.getElementById('filter-module');
        if (moduleSelect) {
            moduleSelect.addEventListener('change', (e) => {
                this.filters.module = e.target.value;
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        // Level filter
        const levelSelect = document.getElementById('filter-level');
        if (levelSelect) {
            levelSelect.addEventListener('change', (e) => {
                this.filters.level = e.target.value;
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        // Operation type filter
        const operationSelect = document.getElementById('filter-operation-type');
        if (operationSelect) {
            operationSelect.addEventListener('change', (e) => {
                this.filters.operation_type = e.target.value;
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        // User ID filter
        const userIdInput = document.getElementById('filter-user-id');
        if (userIdInput) {
            userIdInput.addEventListener('change', (e) => {
                this.filters.user_id = e.target.value;
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        // Date filters
        const dateFromInput = document.getElementById('filter-date-from');
        const dateToInput = document.getElementById('filter-date-to');
        
        if (dateFromInput) {
            dateFromInput.addEventListener('change', (e) => {
                this.filters.date_from = e.target.value;
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        if (dateToInput) {
            dateToInput.addEventListener('change', (e) => {
                this.filters.date_to = e.target.value;
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        // Search
        const searchInput = document.getElementById('filter-search');
        const searchBtn = document.getElementById('btn-search');
        
        if (searchInput && searchBtn) {
            searchBtn.addEventListener('click', () => {
                this.filters.search = searchInput.value;
                this.filters.page = 1;
                this.loadLogs();
            });
            
            // Enter key
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.filters.search = searchInput.value;
                    this.filters.page = 1;
                    this.loadLogs();
                }
            });
        }
        
        // Limit
        const limitSelect = document.getElementById('filter-limit');
        if (limitSelect) {
            limitSelect.addEventListener('change', (e) => {
                this.filters.limit = parseInt(e.target.value);
                this.filters.page = 1;
                this.loadLogs();
            });
        }
        
        // Reset filters
        const resetBtn = document.getElementById('btn-reset-filters');
        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                this.resetFilters();
            });
        }
        
        // Export buttons
        const exportCsvBtn = document.getElementById('btn-export-csv');
        const exportJsonBtn = document.getElementById('btn-export-json');
        
        if (exportCsvBtn) {
            exportCsvBtn.addEventListener('click', () => this.exportLogs('csv'));
        }
        
        if (exportJsonBtn) {
            exportJsonBtn.addEventListener('click', () => this.exportLogs('json'));
        }
        
        // Refresh button
        const refreshBtn = document.getElementById('btn-refresh');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => this.loadLogs());
        }
    }
    
    bindContextToggles() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('.log-entry-context-toggle')) {
                const toggle = e.target.closest('.log-entry-context-toggle');
                const logId = toggle.dataset.logId;
                this.toggleContext(logId);
            }
        });
    }
    
    bindKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Focus search: / or Ctrl+K
            if ((e.key === '/' || (e.ctrlKey && e.key === 'k')) && !this.isTyping()) {
                e.preventDefault();
                const searchInput = document.getElementById('filter-search');
                if (searchInput) searchInput.focus();
            }
            
            // Toggle errors: E
            if (e.key === 'e' && !this.isTyping()) {
                const levelSelect = document.getElementById('filter-level');
                if (levelSelect) {
                    levelSelect.value = levelSelect.value === 'ERROR' ? 'all' : 'ERROR';
                    this.filters.level = levelSelect.value;
                    this.filters.page = 1;
                    this.loadLogs();
                }
            }
            
            // Refresh: R
            if (e.key === 'r' && !this.isTyping()) {
                e.preventDefault();
                this.loadLogs();
            }
        });
    }
    
    bindMobileSidebar() {
        const mobileToggle = document.getElementById('mobile-sidebar-toggle');
        const sidebar = document.querySelector('.log-sidebar');
        
        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', () => {
                sidebar.classList.toggle('mobile-open');
            });
        }
    }
    
    isTyping() {
        const activeElement = document.activeElement;
        return activeElement && (
            activeElement.tagName === 'INPUT' ||
            activeElement.tagName === 'TEXTAREA' ||
            activeElement.tagName === 'SELECT'
        );
    }
    
    async loadLogs() {
        const container = document.getElementById('logs-container');
        if (!container) return;
        
        // Show loading
        container.innerHTML = '<div class="log-loading"><div class="log-loading-spinner"></div></div>';
        
        try {
            const params = new URLSearchParams(this.filters);
            const response = await fetch(`admin_log_api.php?action=logs&${params}`);
            
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            
            const data = await response.json();
            
            this.renderLogs(data.logs);
            this.renderPagination(data.pagination);
            
            // Update last log ID for polling
            if (data.logs.length > 0) {
                this.lastLogId = Math.max(...data.logs.map(log => log.id));
            }
            
        } catch (error) {
            console.error('Errore caricamento log:', error);
            container.innerHTML = `
                <div class="log-empty-state">
                    <div class="log-empty-icon">⚠️</div>
                    <h3 class="log-empty-title">Errore Caricamento</h3>
                    <p class="log-empty-message">Si è verificato un errore durante il caricamento dei log.</p>
                    <button onclick="logViewer.loadLogs()" class="log-btn log-btn-primary">Riprova</button>
                </div>
            `;
        }
    }
    
    renderLogs(logs) {
        const container = document.getElementById('logs-container');
        if (!container) return;
        
        if (logs.length === 0) {
            container.innerHTML = `
                <div class="log-empty-state">
                    <div class="log-empty-icon">📄</div>
                    <h3 class="log-empty-title">Nessun Log Trovato</h3>
                    <p class="log-empty-message">Non ci sono log che corrispondono ai filtri selezionati.</p>
                    <button onclick="logViewer.resetFilters()" class="log-btn log-btn-secondary">Reset Filtri</button>
                </div>
            `;
            return;
        }
        
        container.innerHTML = logs.map(log => this.logTemplate(log)).join('');
        
        // Re-bind context toggles
        this.bindContextToggles();
    }
    
    logTemplate(log) {
        const timestamp = new Date(log.created_at).toLocaleString('it-IT', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
        
        const levelClass = log.level.toLowerCase();
        const hasContext = log.context && Object.keys(log.context).length > 0;
        const contextCount = hasContext ? Object.keys(log.context).length : 0;
        
        return `
            <div class="log-entry">
                <div class="log-entry-header">
                    <div class="log-entry-timestamp">${timestamp}</div>
                    <div class="log-entry-level ${levelClass}">${log.level}</div>
                    <div class="log-entry-module">${log.module}</div>
                    <div class="log-entry-message">${this.escapeHtml(log.message)}</div>
                </div>
                
                <div class="log-entry-meta">
                    ${log.user_id ? `
                        <div class="log-entry-meta-item">
                            <span class="log-entry-meta-label">👤 User:</span>
                            <span>${log.user_id}</span>
                        </div>
                    ` : ''}
                    
                    ${log.memory_mb > 0 ? `
                        <div class="log-entry-meta-item">
                            <span class="log-entry-meta-label">💾 Memory:</span>
                            <span>${log.memory_mb}MB</span>
                        </div>
                    ` : ''}
                    
                    ${log.ip_address ? `
                        <div class="log-entry-meta-item">
                            <span class="log-entry-meta-label">🌐 IP:</span>
                            <span>${this.escapeHtml(log.ip_address)}</span>
                        </div>
                    ` : ''}
                    
                    ${hasContext ? `
                        <div class="log-entry-meta-item">
                            <span class="log-entry-meta-label">📋 Context:</span>
                            <span>${contextCount} items</span>
                        </div>
                    ` : ''}
                </div>
                
                ${hasContext ? `
                    <div class="log-entry-context">
                        <button class="log-entry-context-toggle" data-log-id="${log.id}">
                            <span>▼ Mostra Context</span>
                        </button>
                        <div class="log-entry-context-content" id="context-${log.id}">
${JSON.stringify(log.context, null, 2)}
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
    }
    
    renderPagination(pagination) {
        const container = document.getElementById('pagination-container');
        if (!container || pagination.pages <= 1) {
            if (container) container.innerHTML = '';
            return;
        }
        
        const { page, pages, total } = pagination;
        let html = '<div class="log-pagination">';
        
        // Previous
        if (page > 1) {
            html += `<a href="#" class="log-pagination-btn" onclick="logViewer.goToPage(${page - 1}); return false;">← Precedente</a>`;
        }
        
        // Page numbers
        const startPage = Math.max(1, page - 2);
        const endPage = Math.min(pages, page + 2);
        
        if (startPage > 1) {
            html += `<a href="#" class="log-pagination-btn" onclick="logViewer.goToPage(1); return false;">1</a>`;
            if (startPage > 2) {
                html += `<span class="log-pagination-info">...</span>`;
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === page ? 'active' : '';
            html += `<a href="#" class="log-pagination-btn ${activeClass}" onclick="logViewer.goToPage(${i}); return false;">${i}</a>`;
        }
        
        if (endPage < pages) {
            if (endPage < pages - 1) {
                html += `<span class="log-pagination-info">...</span>`;
            }
            html += `<a href="#" class="log-pagination-btn" onclick="logViewer.goToPage(${pages}); return false;">${pages}</a>`;
        }
        
        // Next
        if (page < pages) {
            html += `<a href="#" class="log-pagination-btn" onclick="logViewer.goToPage(${page + 1}); return false;">Successiva →</a>`;
        }
        
        html += `<span class="log-pagination-info">${total} log totali</span>`;
        html += '</div>';
        
        container.innerHTML = html;
    }
    
    goToPage(page) {
        this.filters.page = page;
        this.currentPage = page;
        this.loadLogs();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    
    toggleContext(logId) {
        const content = document.getElementById(`context-${logId}`);
        const toggle = document.querySelector(`[data-log-id="${logId}"]`);
        
        if (content && toggle) {
            content.classList.toggle('expanded');
            const isExpanded = content.classList.contains('expanded');
            toggle.querySelector('span').textContent = isExpanded ? '▲ Nascondi Context' : '▼ Mostra Context';
        }
    }
    
    resetFilters() {
        this.filters = {
            module: 'all',
            level: 'all',
            operation_type: 'all',
            user_id: '',
            date_from: '',
            date_to: '',
            search: '',
            page: 1,
            limit: 50
        };
        
        // Reset UI
        const moduleSelect = document.getElementById('filter-module');
        const levelSelect = document.getElementById('filter-level');
        const operationSelect = document.getElementById('filter-operation-type');
        const userIdInput = document.getElementById('filter-user-id');
        const dateFromInput = document.getElementById('filter-date-from');
        const dateToInput = document.getElementById('filter-date-to');
        const searchInput = document.getElementById('filter-search');
        const limitSelect = document.getElementById('filter-limit');
        
        if (moduleSelect) moduleSelect.value = 'all';
        if (levelSelect) levelSelect.value = 'all';
        if (operationSelect) operationSelect.value = 'all';
        if (userIdInput) userIdInput.value = '';
        if (dateFromInput) dateFromInput.value = '';
        if (dateToInput) dateToInput.value = '';
        if (searchInput) searchInput.value = '';
        if (limitSelect) limitSelect.value = '50';
        
        this.loadLogs();
    }
    
    exportLogs(format) {
        const params = new URLSearchParams(this.filters);
        params.delete('page'); // Non limitare l'export a una pagina
        params.set('format', format);
        
        window.location.href = `admin_log_api.php?action=export&${params}`;
    }
    
    toggleAutoRefresh() {
        this.autoRefresh = !this.autoRefresh;
        
        if (this.autoRefresh) {
            this.startAutoRefresh();
            console.log('✅ Auto-refresh attivato (30s)');
        } else {
            this.stopAutoRefresh();
            console.log('⏸️ Auto-refresh disattivato');
        }
    }
    
    startAutoRefresh() {
        this.stopAutoRefresh(); // Clear existing
        
        this.autoRefreshTimer = setInterval(() => {
            this.loadLogs();
        }, 30000); // 30 secondi
    }
    
    stopAutoRefresh() {
        if (this.autoRefreshTimer) {
            clearInterval(this.autoRefreshTimer);
            this.autoRefreshTimer = null;
        }
    }
    
    async checkNewLogs() {
        try {
            const response = await fetch(`admin_log_api.php?action=check_new_logs&last_id=${this.lastLogId}`);
            const data = await response.json();
            
            if (data.new_logs > 0) {
                this.showNotification(`${data.new_logs} nuovi log disponibili`, 'info');
            }
        } catch (error) {
            console.error('Errore check new logs:', error);
        }
    }
    
    showNotification(message, type = 'info') {
        // Simple notification (può essere sostituito con toast library)
        console.log(`[${type.toUpperCase()}] ${message}`);
        
        // TODO: Implementare toast notifications se richiesto
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize on DOM ready
let logViewer;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        logViewer = new LogViewer();
    });
} else {
    logViewer = new LogViewer();
}

// Export per uso globale
window.logViewer = logViewer;

console.log('📋 Admin Logs System caricato con successo!');

