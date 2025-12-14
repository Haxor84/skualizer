/**
 * Variant Adapter Frontend
 * Gestisce UI per selezione master/variants e generazione
 */

class VariantAdapterUI {
    constructor() {
        this.masterRowId = null;
        this.variantRowIds = [];
        
        this.init();
    }
    
    init() {
        // Aspetta che la tabella sia caricata
        this.waitForTableAndInit();
    }
    
    waitForTableAndInit() {
        const checkTable = setInterval(() => {
            const tbody = document.getElementById('tableBody');
            if (tbody && tbody.children.length > 0) {
                clearInterval(checkTable);
                this.attachEventListeners();
            }
        }, 500);
    }
    
    attachEventListeners() {
        // Master radio selection (event delegation)
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('variant-master-radio')) {
                this.masterRowId = parseInt(e.target.value);
                this.updateUI();
            }
        });
        
        // Variant checkboxes (event delegation)
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('variant-checkbox')) {
                this.updateVariantSelection();
                this.updateUI();
            }
        });
        
        // Generate variants button
        const btn = document.getElementById('btnGenerateVariants');
        if (btn) {
            btn.addEventListener('click', () => {
                this.generateVariants();
            });
        }
    }
    
    updateVariantSelection() {
        this.variantRowIds = Array.from(
            document.querySelectorAll('.variant-checkbox:checked')
        ).map(cb => parseInt(cb.value));
    }
    
    updateUI() {
        const hasMaster = this.masterRowId !== null;
        const hasVariants = this.variantRowIds.length > 0;
        
        // Show/hide generate variants button
        const btn = document.getElementById('btnGenerateVariants');
        if (btn) {
            btn.style.display = (hasMaster && hasVariants) ? 'inline-block' : 'none';
            btn.textContent = `🔄 Genera ${this.variantRowIds.length} Varianti`;
        }
        
        // Disable master checkbox se è master
        document.querySelectorAll('.variant-checkbox').forEach(cb => {
            if (parseInt(cb.value) === this.masterRowId) {
                cb.disabled = true;
                cb.checked = false;
            } else {
                cb.disabled = false;
            }
        });
    }
    
    async generateVariants() {
        console.log('=== generateVariants chiamato ===');
        console.log('masterRowId:', this.masterRowId);
        console.log('variantRowIds:', this.variantRowIds);
        
        if (!this.masterRowId || this.variantRowIds.length === 0) {
            showToast('Seleziona 1 master (radio) e almeno 1 variante (checkbox)', 'warning');
            return;
        }
        
        // Debug: verifica stato AppState
        console.log('window.AppState:', window.AppState);
        console.log('window.AppState.currentFilepath:', window.AppState?.currentFilepath);
        console.log('window.AI_CONFIG:', window.AI_CONFIG);
        console.log('window.AI_CONFIG.currentFilepath:', window.AI_CONFIG?.currentFilepath);
        
        // Prova a usare AI_CONFIG come fallback
        const filepath = window.AppState?.currentFilepath || window.AI_CONFIG?.currentFilepath;
        
        console.log('filepath finale:', filepath);
        
        // Verifica che ci sia un file caricato
        if (!filepath) {
            console.error('ERRORE: Nessun filepath trovato!');
            showToast('❌ Nessun file Excel caricato', 'error');
            return;
        }
        
        const btn = document.getElementById('btnGenerateVariants');
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Generazione in corso...';
        
        // Mostra loading overlay
        if (window.showLoading) {
            showLoading('Generazione varianti in corso...');
        }
        
        try {
            const payload = {
                filepath: filepath,
                master_row_number: this.masterRowId,
                variant_row_numbers: this.variantRowIds
            };
            
            console.log('=== Inviando richiesta variant adapter ===');
            console.log('Payload completo:', JSON.stringify(payload, null, 2));
            
            const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/variant_adapter_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            
            console.log('=== Risposta HTTP ricevuta ===');
            console.log('Response status:', response.status);
            console.log('Response ok:', response.ok);
            console.log('Response headers:', response.headers);
            
            // Leggi la risposta come testo per vedere cosa arriva
            const responseText = await response.text();
            console.log('Response text (raw):', responseText);
            console.log('Response text length:', responseText.length);
            console.log('Response first 500 chars:', responseText.substring(0, 500));
            
            // Prova a parsare come JSON
            let data;
            try {
                data = JSON.parse(responseText);
                console.log('✅ JSON parsing riuscito');
            } catch (e) {
                console.error('❌ JSON parsing fallito:', e.message);
                console.error('Risposta completa HTML:', responseText);
                throw new Error('Server ha restituito HTML invece di JSON. Vedi console per dettagli.');
            }
            
            console.log('=== Risposta JSON parsed ===');
            console.log('Response data:', data);
            
            if (!data.success) {
                console.error('ERRORE dalla API:', data.error);
                throw new Error(data.error || 'Errore generazione varianti');
            }
            
            console.log('✅ Varianti generate con successo!');
            console.log('Risultati:', data.results);
            console.log('Conteggio varianti:', data.variants_count);
            
            showToast(`✅ ${data.variants_count} varianti generate e salvate con successo!`, 'success');
            
            // Ricarica tabella dopo 2 secondi
            setTimeout(() => {
                location.reload();
            }, 2000);
            
        } catch (error) {
            console.error('=== ERRORE CATCH ===');
            console.error('Error object:', error);
            console.error('Error message:', error.message);
            console.error('Error stack:', error.stack);
            showToast('❌ Errore: ' + error.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = originalText;
            
            if (window.hideLoading) {
                hideLoading();
            }
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', () => {
    console.log('=== Inizializzazione VariantAdapter ===');
    
    // Aspetta che AppState sia disponibile
    const checkAppState = setInterval(() => {
        if (window.AppState) {
            console.log('✅ AppState trovato, inizializzo VariantAdapterUI');
            console.log('AppState iniziale:', {
                currentFile: window.AppState.currentFile,
                currentFilepath: window.AppState.currentFilepath,
                rowsCount: window.AppState.rows?.length
            });
            clearInterval(checkAppState);
            window.variantAdapter = new VariantAdapterUI();
        }
    }, 100);
});

// Helper per toast notification (usa quella esistente se disponibile)
function showToast(message, type = 'info') {
    if (window.toastNotification) {
        window.toastNotification(message, type);
    } else {
        // Fallback
        const toast = document.getElementById('toast');
        if (toast) {
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            setTimeout(() => {
                toast.className = 'toast';
            }, 3000);
        } else {
            alert(message);
        }
    }
}
