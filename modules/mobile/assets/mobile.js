/**
 * Mobile JavaScript Helper
 * Utility functions for Skualizer Mobile
 */

// === HIDE ADDRESS BAR (iOS Safari) ===
function hideAddressBar() {
    // Forza scroll leggero per nascondere barra indirizzi
    if (!window.pageYOffset) {
        window.scrollTo(0, 1);
    }
}

// Esegui all'avvio e al resize/orientamento
window.addEventListener('load', hideAddressBar);
window.addEventListener('orientationchange', hideAddressBar);

// Previeni bounce scroll su iOS
document.addEventListener('touchmove', function(e) {
    if (e.target.closest('.mobile-content, .hamburger-menu')) {
        return; // Permetti scroll nel contenuto
    }
    e.preventDefault();
}, { passive: false });

// === NAVIGATION ===
function attachNavActiveState() {
    const currentPage = window.location.pathname.split('/').pop().replace('.php', '');
    const tabbarItems = document.querySelectorAll('.tabbar-item');
    
    tabbarItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href && href.includes(currentPage)) {
            item.classList.add('active');
            item.setAttribute('aria-current', 'page');
        }
    });
}

// === HAMBURGER MENU ===
function initHamburger() {
    const hamburgerBtn = document.querySelector('.hamburger-btn');
    const overlay = document.querySelector('.hamburger-overlay');
    
    if (hamburgerBtn && overlay) {
        hamburgerBtn.addEventListener('click', () => {
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        });
        
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                closeHamburger();
            }
        });
    }
}

function closeHamburger() {
    const overlay = document.querySelector('.hamburger-overlay');
    if (overlay) {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// === PAGINATION ===
function paginateLinks() {
    const prevBtn = document.querySelector('.pagination-btn[data-dir="prev"]');
    const nextBtn = document.querySelector('.pagination-btn[data-dir="next"]');
    
    if (prevBtn) {
        prevBtn.addEventListener('click', () => {
            const currentPage = parseInt(new URLSearchParams(window.location.search).get('page') || '1');
            if (currentPage > 1) {
                navigateToPage(currentPage - 1);
            }
        });
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', () => {
            const currentPage = parseInt(new URLSearchParams(window.location.search).get('page') || '1');
            navigateToPage(currentPage + 1);
        });
    }
}

function navigateToPage(page) {
    const url = new URL(window.location);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
}

// === MODAL ===
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

function initModals() {
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
        
        const closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            });
        }
    });
}

// === TOAST ===
let toastTimeout;

function toast(message, type = 'info', duration = 3000) {
    let toastEl = document.querySelector('.toast');
    
    if (!toastEl) {
        toastEl = document.createElement('div');
        toastEl.className = 'toast';
        document.body.appendChild(toastEl);
    }
    
    toastEl.textContent = message;
    toastEl.className = `toast ${type}`;
    
    clearTimeout(toastTimeout);
    
    requestAnimationFrame(() => {
        toastEl.classList.add('active');
    });
    
    toastTimeout = setTimeout(() => {
        toastEl.classList.remove('active');
    }, duration);
}

// === FETCH JSON ===
async function fetchJSON(endpoint, params = {}) {
    try {
        const url = new URL(endpoint, window.location.origin);
        Object.keys(params).forEach(key => {
            if (params[key] !== null && params[key] !== undefined) {
                url.searchParams.append(key, params[key]);
            }
        });
        
        const response = await fetch(url.toString(), {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
            },
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        return data;
        
    } catch (error) {
        console.error('Fetch error:', error);
        toast('Errore di connessione', 'error');
        throw error;
    }
}

async function postJSON(endpoint, payload) {
    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        return data;
        
    } catch (error) {
        console.error('Post error:', error);
        toast('Errore durante il salvataggio', 'error');
        throw error;
    }
}

// === FORMATTING ===
function formatCurrency(value, currency = 'EUR') {
    return new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: currency
    }).format(value);
}

function formatNumber(value) {
    return new Intl.NumberFormat('it-IT').format(value);
}

function formatDate(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('it-IT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
}

function formatDateShort(dateString) {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    return date.toLocaleDateString('it-IT', {
        day: '2-digit',
        month: 'short'
    });
}

function timeAgo(dateString) {
    if (!dateString) return 'Mai';
    
    const date = new Date(dateString);
    const now = new Date();
    const seconds = Math.floor((now - date) / 1000);
    
    if (seconds < 60) return 'Ora';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' min fa';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' ore fa';
    return Math.floor(seconds / 86400) + ' giorni fa';
}

// === FILTERS ===
function initFilters() {
    document.querySelectorAll('.filter-chip').forEach(chip => {
        chip.addEventListener('click', function() {
            const filterType = this.dataset.filter;
            const filterValue = this.dataset.value;
            
            const url = new URL(window.location);
            url.searchParams.set(filterType, filterValue);
            url.searchParams.delete('page'); // Reset pagination
            
            window.location.href = url.toString();
        });
    });
}

// === ESCAPE HTML ===
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// === INIT ON DOM READY ===
document.addEventListener('DOMContentLoaded', () => {
    attachNavActiveState();
    initHamburger();
    paginateLinks();
    initModals();
    initFilters();
});

// === LOGOUT ===
function doLogout() {
    if (confirm('Sei sicuro di voler uscire?')) {
        window.location.href = '/modules/margynomic/login/logout.php';
    }
}

