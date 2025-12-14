/**
 * AI Excel Editor - Frontend Logic
 * Path: modules/margynomic/admin/creaexcel/ai/assets/ai_creator.js
 * 
 * Excel Editor con AI Assistant:
 * - Upload file Excel
 * - Visualizza tabella prodotti
 * - Edit/Add/Delete righe
 * - AI suggestions per campi
 * - Validation real-time
 * - Save & Download
 */

// ============================================
// HTML ESCAPE UTILITIES
// ============================================

/**
 * Escape HTML per visualizzazione sicura
 * Converte tag HTML in testo visibile senza eseguirli
 */
function escapeHtml(text) {
    if (!text) return '';
    
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
        '\n': ' ',
        '\r': ''
    };
    
    return String(text).replace(/[&<>"'\n\r]/g, m => map[m]);
}

/**
 * Rimuove tag HTML e converte in testo plain
 * Utile per tooltip e display compatto
 */
function stripHtmlTags(text) {
    if (!text) return '';
    
    return String(text)
        .replace(/<br\s*\/?>/gi, ' ')  // <br> diventa spazio
        .replace(/<\/p>/gi, ' ')       // </p> diventa spazio
        .replace(/<[^>]+>/g, '')       // Rimuovi altri tag
        .replace(/\s+/g, ' ')          // Normalizza spazi multipli
        .trim();
}

/**
 * Decodifica HTML entities
 */
function decodeHtmlEntities(text) {
    if (!text) return '';
    
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

/**
 * Pulisci tutti i marker "non conforme" dal file corrente
 * Utile dopo revisione manuale completa
 */
function clearAllInvalidMarkers() {
    if (!AppState.currentFilepath) {
        showToast('Nessun file caricato', 'error');
        return;
    }
    
    if (!confirm('Rimuovere tutti i marker "contenuto non conforme" (rossi) da questo file?')) {
        return;
    }
    
    let clearedCount = 0;
    
    // Pulisci da ogni row
    AppState.rows.forEach(row => {
        if (row.invalidFields && row.invalidFields.length > 0) {
            const storageKey = `invalidFields_${AppState.currentFilepath}_${row.row_number}`;
            localStorage.removeItem(storageKey);
            row.invalidFields = [];
            clearedCount++;
        }
    });
    
    // Re-render tabella per rimuovere colori rossi
    renderProductsTable();
    
    showToast(`✅ ${clearedCount} marker rimossi. Celle ora normali.`, 'success');
    
    console.log(`🧹 Puliti ${clearedCount} invalid markers per file: ${AppState.currentFilepath}`);
}

// ============================================
// STATE MANAGEMENT
// ============================================

const AppState = {
    selectedUserId: null, // User ID selezionato dall'admin
    currentFile: null,
    currentFilepath: null,
    currentFolder: '', // Cartella cliente corrente (vuoto = root)
    currentRowNumber: null,
    rows: [],
    headers: {},
    columnOrder: [], // Ordine colonne Excel: ['item_sku', 'brand_name', 'item_name', ...]
    dropdowns: {},
    editingRow: null,
    updatedRows: [], // Array di row numbers con prezzi aggiornati da sync
    verifiedSkuRows: [], // Array di row numbers con SKU verificati (esistono in products)
    verifiedEanRows: [], // Array di row numbers con EAN verificati
    verifiedAsinRows: [], // Array di row numbers con ASIN verificati
    
    // Campi obbligatori Amazon (esclusi da Parent)
    requiredFields: [
        'recommended_browse_nodes',
        'unit_count',
        'unit_count_type',
        'country_of_origin',
        'is_heat_sensitive',
        'standard_price',
        'quantity',
        'is_expiration_dated_product',
        'main_image_url'
    ]
};

// Esponi AppState globalmente per VariantAdapter
window.AppState = AppState;
console.log('=== ai_creator.js: AppState esposto globalmente ===');
console.log('window.AppState:', window.AppState);

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', () => {

    // Leggi user_id selezionato dal selettore HTML
    const userSelector = document.getElementById('userSelector');
    if (userSelector) {
        AppState.selectedUserId = parseInt(userSelector.value);
    }

    initializeUI();
    initializeEventListeners();
    loadFolders(); // Carica cartelle disponibili
    loadRecentFiles(); // Carica file recenti all'avvio
});

function initializeUI() {
    // Setup modals
    document.querySelectorAll('.modal-close').forEach(btn => {
        btn.addEventListener('click', () => closeAllModals());
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeAllModals();
        });
    });
}

function initializeEventListeners() {
    // Upload buttons
    const btnUploadExcel = document.getElementById('btnUploadExcel');
    const btnUploadWelcome = document.getElementById('btnUploadWelcome');
    const btnUploadConfirm = document.getElementById('btnUploadConfirm');
    const fileExcelInput = document.getElementById('fileExcelInput');

    if (btnUploadExcel) {
        btnUploadExcel.addEventListener('click', () => showModal('modalUploadExcel'));
    }

    if (btnUploadWelcome) {
        btnUploadWelcome.addEventListener('click', () => showModal('modalUploadExcel'));
    }

    if (btnUploadConfirm) {
        btnUploadConfirm.addEventListener('click', uploadExcel);
    }

    if (fileExcelInput) {
        fileExcelInput.addEventListener('change', handleFileSelect);
    }

    // Folder management
    const btnNewFolder = document.getElementById('btnNewFolder');
    const folderSelect = document.getElementById('folderSelect');
    const btnCreateFolder = document.getElementById('btnCreateFolder');
    
    if (btnNewFolder) {
        btnNewFolder.addEventListener('click', () => showModal('modalNewFolder'));
    }
    
    if (folderSelect) {
        folderSelect.addEventListener('change', (e) => {
            AppState.currentFolder = e.target.value;
            updateFolderSubtitle();
            loadRecentFiles(); // Reload files for selected folder
        });
    }
    
    if (btnCreateFolder) {
        btnCreateFolder.addEventListener('click', createNewFolder);
    }
    
    // Category management
    const btnManageCategories = document.getElementById('btnManageCategories');
    const btnAddCategory = document.getElementById('btnAddCategory');
    
    if (btnManageCategories) {
        btnManageCategories.addEventListener('click', () => {
            showModal('modalManageCategories');
            loadCategories();
        });
    }
    
    if (btnAddCategory) {
        btnAddCategory.addEventListener('click', addCategory);
    }

    // Upload area drag & drop
    const uploadArea = document.getElementById('uploadArea');
    if (uploadArea) {
        uploadArea.addEventListener('click', () => fileExcelInput.click());

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            if (e.dataTransfer.files[0]) {
                fileExcelInput.files = e.dataTransfer.files;
                handleFileSelect({ target: { files: e.dataTransfer.files } });
            }
        });
    }

    // Table actions
    const btnAddRow = document.getElementById('btnAddRow');
    const btnValidateAll = document.getElementById('btnValidateAll');
    const btnSaveExcel = document.getElementById('btnSaveExcel');

    if (btnAddRow) {
        btnAddRow.addEventListener('click', () => {
            // Trova prima riga vuota o crea nuova
            const emptyRow = AppState.rows.find(r => r.is_empty);
            const rowNumber = emptyRow ? emptyRow.row_number : (AppState.rows.length > 0 ? Math.max(...AppState.rows.map(r => r.row_number)) + 1 : 4);
            openRowEditor(rowNumber, true);
        });
    }
    
    // Sync prices button
    const btnSyncPrices = document.getElementById('btnSyncPrices');
    if (btnSyncPrices) {
        btnSyncPrices.addEventListener('click', syncPricesFromDatabase);
    }
    
    // Sync EAN button
    const btnSyncEan = document.getElementById('btnSyncEan');
    if (btnSyncEan) {
        btnSyncEan.addEventListener('click', syncEanCodes);
    }

    if (btnValidateAll) {
        btnValidateAll.addEventListener('click', validateAllRows);
    }

    if (btnSaveExcel) {
        btnSaveExcel.addEventListener('click', downloadExcel);
    }

    // Row editor
    const btnCloseEditor = document.getElementById('btnCloseEditor');
    const btnSaveRow = document.getElementById('btnSaveRow');
    const btnDuplicateRow = document.getElementById('btnDuplicateRow');
    const btnDeleteRow = document.getElementById('btnDeleteRow');
    const btnCancelEdit = document.getElementById('btnCancelEdit');
    const btnGenerateEan = document.getElementById('btnGenerateEan');

    if (btnCloseEditor) btnCloseEditor.addEventListener('click', closeRowEditor);
    if (btnCancelEdit) btnCancelEdit.addEventListener('click', closeRowEditor);
    if (btnSaveRow) btnSaveRow.addEventListener('click', saveCurrentRow);
    if (btnDuplicateRow) btnDuplicateRow.addEventListener('click', duplicateCurrentRow);
    if (btnDeleteRow) btnDeleteRow.addEventListener('click', deleteCurrentRow);
    if (btnGenerateEan) btnGenerateEan.addEventListener('click', generateEanForRow);

    // AI buttons (delegated)
    document.addEventListener('click', (e) => {
        if (e.target.classList.contains('btn-ai')) {
            const field = e.target.dataset.aiField;
            const index = e.target.dataset.index;
            generateFieldWithAI(field, index);
        }

        if (e.target.classList.contains('btn-validate')) {
            const field = e.target.dataset.validateField;
            const input = document.querySelector(`#dynamicFieldsContainer input[data-field="${field}"], #dynamicFieldsContainer textarea[data-field="${field}"]`);
            if (input) validateField(field, input.value);
        }
    });

    // Field input - character count
    document.querySelectorAll('.field-input').forEach(input => {
        input.addEventListener('input', () => updateCharCount(input));
    });

    // Close
    const btnClose = document.getElementById('btnClose');
    if (btnClose) {
        btnClose.addEventListener('click', () => {
            if (confirm('Chiudere l\'editor? Modifiche non salvate andranno perse.')) {
                window.location.href = '/modules/margynomic/admin/';
            }
        });
    }
}

// ============================================
// EXCEL UPLOAD
// ============================================

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;

    const uploadArea = document.getElementById('uploadArea');
    if (uploadArea) {
        uploadArea.innerHTML = `
            <div class="upload-icon">✅</div>
            <p><strong>${file.name}</strong></p>
            <p class="upload-hint">${(file.size / 1024 / 1024).toFixed(2)} MB</p>
        `;
    }
}

function uploadExcel() {
    const fileInput = document.getElementById('fileExcelInput');

    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        showToast('Seleziona un file Excel', 'error');
        return;
    }

    const file = fileInput.files[0];
    const categoria = document.getElementById('excelCategoria').value || 'Generic'; // Default se vuoto

    const formData = new FormData();
    formData.append('action', 'upload_template');
    formData.append('user_id', AppState.selectedUserId); // IMPORTANTE: passa user_id!
    formData.append('file', file);
    formData.append('categoria', categoria);
    formData.append('folder', AppState.currentFolder || ''); // Passa cartella corrente

    showLoading('Caricamento file Excel...');

    fetch(window.AI_CONFIG.apiUrl, {
        method: 'POST',
        body: formData
    })
        .then(response => response.json())
        .then(data => {
            hideLoading();

            if (data.success) {
                AppState.currentFile = file.name;
                AppState.currentFilepath = data.data.filepath;
                console.log('=== File caricato, filepath impostato ===');
                console.log('AppState.currentFilepath:', AppState.currentFilepath);
                console.log('window.AppState.currentFilepath:', window.AppState.currentFilepath);

                showToast('File caricato! Caricamento righe...', 'success');
                closeAllModals();

                // Load rows
                loadExcelRows(data.data.filepath);
            } else {
                showToast('Errore: ' + data.error, 'error');
            }
        })
        .catch(error => {
            hideLoading();
            showToast('Errore upload: ' + error.message, 'error');
        });
}

function loadExcelRows(filepath) {
    showLoading('Caricamento prodotti...');

    apiCall('load_excel_rows', { filepath: filepath })
        .then(response => {
            hideLoading();

            if (response.success) {
                AppState.rows = response.data.rows;
                AppState.headers = response.data.metadata.headers;
                AppState.columnOrder = response.data.metadata.column_order || Object.values(response.data.metadata.headers); // Ordine Excel
                AppState.dropdowns = response.data.metadata.dropdown_values || {};
                
                // Salva righe con SKU/EAN/ASIN verificati
                if (response.data.metadata.sku_verification) {
                    const verification = response.data.metadata.sku_verification;
                    AppState.verifiedSkuRows = verification.matched_sku_rows || [];
                    AppState.verifiedEanRows = verification.matched_ean_rows || [];
                    AppState.verifiedAsinRows = verification.matched_asin_rows || [];
                } else {
                    AppState.verifiedSkuRows = [];
                    AppState.verifiedEanRows = [];
                    AppState.verifiedAsinRows = [];
                }

                // Hide welcome, show table
                const welcomeScreen = document.getElementById('welcomeScreen');
                const productsTable = document.getElementById('productsTable');

                if (welcomeScreen) welcomeScreen.style.display = 'none';
                if (productsTable) productsTable.style.display = 'block';

                // Update file name
                document.getElementById('fileName').textContent = 'File: ' + AppState.currentFile;

                // Render table
                renderProductsTable();

                showToast(`${response.data.metadata.data_rows} righe caricate`, 'success');
                
                // AUTO-SYNC EAN + PREZZI automatico (se ci sono SKU verificati)
                if (AppState.verifiedSkuRows.length > 0) {
                    showToast('🔄 Avvio sincronizzazione automatica...', 'info');
                    
                    // Sync EAN (immediato)
                    setTimeout(() => {
                        syncEanCodesAutomatic();
                    }, 100);
                    
                    // Sync Prezzi (dopo 800ms per dare tempo all'EAN)
                    setTimeout(() => {
                        syncPricesFromDatabaseAutomatic();
                    }, 800);
                }
            } else {
                showToast('Errore caricamento: ' + response.error, 'error');
            }
        });
}

function renderProductsTable() {
    const tbody = document.getElementById('tableBody');
    const thead = document.querySelector('#tableProducts thead tr');
    if (!tbody || !thead) return;

    // Get all field names in Excel order (usa columnOrder se disponibile)
    const visibleColumns = AppState.columnOrder && AppState.columnOrder.length > 0 
        ? AppState.columnOrder 
        : Object.keys(AppState.headers);

    // Render thead - tutte le colonne con batch button
    thead.innerHTML = `
        <th style="position: sticky; left: 0; background: #f9fafb; z-index: 10; min-width: 50px;">#</th>
        <th style="position: sticky; left: 50px; background: #f9fafb; z-index: 10; min-width: 70px; text-align: center;">
            <div style="font-size: 11px;">Variant<br><small style="opacity:0.7;">M/V</small></div>
        </th>
        ${visibleColumns.map(col => {
            const label = AppState.headers[col] || col.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            return `
                <th style="min-width: 150px;">
                    <div style="display: flex; align-items: center; gap: 5px; justify-content: space-between;">
                        <span>${label}</span>
                        <button 
                            class="btn-batch-fill" 
                            data-field="${col}"
                            onclick="openBatchFillModal('${col}')"
                            title="Copia valore in tutta la colonna"
                        >
                            📋
                        </button>
                    </div>
                </th>`;
        }).join('')}
        <th style="min-width: 80px;">Status</th>
        <th style="position: sticky; right: 0; background: #f9fafb; z-index: 10; min-width: 120px;">Azioni</th>
    `;

    // Render tbody
    tbody.innerHTML = '';

    AppState.rows.forEach((row, index) => {
        // ✅ Carica invalidFields da localStorage (campi non conformi da AI)
        if (AppState.currentFilepath) {
            const storageKey = `invalidFields_${AppState.currentFilepath}_${row.row_number}`;
            const storedInvalidFields = localStorage.getItem(storageKey);
            if (storedInvalidFields) {
                try {
                    row.invalidFields = JSON.parse(storedInvalidFields);
                } catch (e) {
                    console.error('Error parsing invalidFields from localStorage:', e);
                    row.invalidFields = [];
                }
            }
        }
        
        const tr = document.createElement('tr');
        tr.className = row.is_empty ? 'row-empty' : 'row-filled';
        tr.dataset.rowNumber = row.row_number;

        const status = row.is_empty ? '🔘 Vuoto' : '✅ OK';

        let cells = `<td style="position: sticky; left: 0; background: white; z-index: 9;">${row.row_number}</td>`;
        
        // Variant column: radio (master) + checkbox (variant) - usa row_number
        cells += `<td style="position: sticky; left: 50px; background: white; z-index: 9; text-align: center;">
            <div style="display: flex; flex-direction: column; gap: 3px; align-items: center;">
                <input type="radio" 
                       name="variant_master" 
                       value="${row.row_number}"
                       class="variant-master-radio"
                       title="Master"
                       style="cursor: pointer; margin: 0;">
                <input type="checkbox" 
                       class="variant-checkbox"
                       value="${row.row_number}"
                       title="Variante"
                       style="cursor: pointer; margin: 0;">
            </div>
        </td>`;

        // Add data cells - tutte le colonne
        visibleColumns.forEach(col => {
            // IMPORTANTE: Distingui tra null/undefined e zero!
            let value = row.data[col];
            if (value === null || value === undefined) {
                value = '';
            }
            
            // Check if this is a price cell that was just updated
            const isPriceUpdated = col === 'standard_price' && AppState.updatedRows.includes(row.row_number);
            
            // Check verified cells
            const isSkuVerified = col === 'item_sku' && AppState.verifiedSkuRows && AppState.verifiedSkuRows.includes(row.row_number);
            const isEanVerified = col === 'external_product_id' && AppState.verifiedEanRows && AppState.verifiedEanRows.includes(row.row_number);
            const isAsinVerified = col === 'external_product_id' && AppState.verifiedAsinRows && AppState.verifiedAsinRows.includes(row.row_number);
            
            // Build CSS classes array
            let cellClasses = [];
            if (isPriceUpdated) cellClasses.push('price-updated');
            if (isSkuVerified) cellClasses.push('sku-verified');
            if (isEanVerified) cellClasses.push('ean-verified');
            if (isAsinVerified) cellClasses.push('asin-verified');
            
            const cellClassAttr = cellClasses.length > 0 ? ` class="${cellClasses.join(' ')}"` : '';
            
            // Check if this column has dropdown values
            const hasDropdown = AppState.dropdowns && AppState.dropdowns[col] && AppState.dropdowns[col].length > 0;
            
            if (hasDropdown) {
                // Render dropdown inline
                const options = AppState.dropdowns[col];
                const selectId = `dropdown_${row.row_number}_${col}`;
                
                let optionsHtml = '<option value="">-</option>';
                options.forEach(opt => {
                    const selected = opt === value ? 'selected' : '';
                    optionsHtml += `<option value="${opt}" ${selected}>${opt}</option>`;
                });
                
                // Badge HTML per celle verificate con dropdown
                let badgeHtml = '';
                if (isSkuVerified) {
                    badgeHtml = '<span class="verification-badge sku-badge">✓</span>';
                } else if (isEanVerified) {
                    badgeHtml = '<span class="verification-badge ean-badge">✓ EAN</span>';
                } else if (isAsinVerified) {
                    badgeHtml = '<span class="verification-badge asin-badge">✓ ASIN</span>';
                }
                
                cells += `<td${cellClassAttr}>
                    <select 
                        id="${selectId}" 
                        class="inline-dropdown" 
                        data-row="${row.row_number}" 
                        data-field="${col}"
                        onchange="handleInlineDropdownChange(this)"
                    >
                        ${optionsHtml}
                    </select>
                    ${badgeHtml}
                </td>`;
            } else {
                // Render text - editable on double click
                // IMPORTANTE: Zero deve rimanere "0", non diventare "-"
                let displayValue = value;
                if (displayValue === null || displayValue === undefined || displayValue === '') {
                    displayValue = '';
                }
                
                // ESCAPE HTML per evitare rendering di tag <br>, <p>, ecc.
                const cleanText = stripHtmlTags(displayValue);
                const escapedText = escapeHtml(cleanText);
                
                const truncated = cleanText.length > 30;
                const cellText = truncated ? escapedText.substring(0, 30) + '...' : (cleanText !== '' ? escapedText : '-');
                
                // Check if this is a required field and it's empty (escludi Parent)
                const isParent = row.data.parent_child && String(row.data.parent_child).toLowerCase().trim() === 'parent';
                const isRequired = AppState.requiredFields.includes(col);
                const isEmpty = displayValue === '' || displayValue === '-';
                const isInvalid = isRequired && isEmpty && !isParent && !row.is_empty;
                
                // ✅ NEW: Check se campo contiene contenuto non conforme (da AI)
                const isNonCompliant = row.invalidFields && row.invalidFields.includes(col);
                
                // Build complete CSS classes array for editable cells
                let editableCellClasses = ['editable-cell', ...cellClasses];
                if (isInvalid) editableCellClasses.push('cell-required-empty');
                if (isNonCompliant) editableCellClasses.push('cell-non-compliant');
                
                const editableCellClassAttr = ` class="${editableCellClasses.join(' ')}"`;
                
                // Tooltip con testo pulito (senza HTML)
                let titleText = 'Doppio click per modificare';
                if (isNonCompliant) {
                    titleText = '⚠️ CONTENUTO NON CONFORME - Revisione richiesta - Doppio click per modificare';
                } else if (isInvalid) {
                    titleText = '⚠️ CAMPO OBBLIGATORIO VUOTO - Doppio click per modificare';
                } else if (isSkuVerified) {
                    titleText = '✅ SKU verificato in database - Doppio click per modificare';
                } else if (isEanVerified) {
                    titleText = '✅ EAN verificato in database - Doppio click per modificare';
                } else if (isAsinVerified) {
                    titleText = '✅ ASIN verificato in database - Doppio click per modificare';
                } else if (truncated) {
                    titleText = cleanText; // Testo pulito senza HTML
                }
                
                cells += `<td${editableCellClassAttr} 
                    data-row="${row.row_number}" 
                    data-field="${col}"
                    data-full-value="${escapeHtml(displayValue)}"
                    title="${escapeHtml(titleText)}"
                    ondblclick="makeInlineEditable(this)"
                >${cellText}</td>`;
            }
        });

        cells += `
            <td>${status}</td>
            <td style="position: sticky; right: 0; background: white; z-index: 9;">
                ${row.is_empty ?
                `<button class="btn-small btn-primary" onclick="openRowEditor(${row.row_number}, true)">➕ Add</button>` :
            `<button class="btn-ai-chat" onclick="openAiChat(${index})" title="AI Assistant">🤖</button>
             <button class="btn-small btn-secondary" onclick="openRowEditor(${row.row_number}, false)">✏️ Edit</button>`
            }
            </td>
        `;

        tr.innerHTML = cells;
        tbody.appendChild(tr);
    });

    // Count invalid required cells (escluse Parent)
    let invalidCellsCount = 0;
    AppState.rows.forEach(row => {
        const isParent = row.data.parent_child && String(row.data.parent_child).toLowerCase().trim() === 'parent';
        if (!isParent && !row.is_empty) {
            AppState.requiredFields.forEach(field => {
                const value = row.data[field];
                if (value === null || value === undefined || value === '' || value === '-') {
                    invalidCellsCount++;
                }
            });
        }
    });
    
    // Count verified SKUs, EANs, ASINs
    const verifiedSkuCount = AppState.verifiedSkuRows ? AppState.verifiedSkuRows.length : 0;
    const verifiedEanCount = AppState.verifiedEanRows ? AppState.verifiedEanRows.length : 0;
    const verifiedAsinCount = AppState.verifiedAsinRows ? AppState.verifiedAsinRows.length : 0;
    
    // Update count
    const filledCount = AppState.rows.filter(r => !r.is_empty).length;
    const rowsCountEl = document.getElementById('rowsCount');
    if (rowsCountEl) {
        const invalidWarning = invalidCellsCount > 0 ? ` | ⚠️ ${invalidCellsCount} celle obbligatorie vuote` : '';
        let verifiedInfo = '';
        if (verifiedSkuCount > 0) verifiedInfo += ` | ✅ ${verifiedSkuCount} SKU`;
        if (verifiedEanCount > 0) verifiedInfo += ` | ✅ ${verifiedEanCount} EAN`;
        if (verifiedAsinCount > 0) verifiedInfo += ` | ✅ ${verifiedAsinCount} ASIN`;
        rowsCountEl.textContent = `${filledCount} prodotti | ${visibleColumns.length} colonne${verifiedInfo}${invalidWarning}`;
    }
}

// ============================================
// INLINE DROPDOWN HANDLER
// ============================================

async function handleInlineDropdownChange(selectElement) {
    const rowNumber = parseInt(selectElement.dataset.row);
    const fieldName = selectElement.dataset.field;
    let newValue = selectElement.value;
    
    // Preserva zero se numerico
    if (newValue === '0') {
        newValue = 0;
    }
    
    // Aggiorna valore in AppState
    const row = AppState.rows.find(r => r.row_number === rowNumber);
    if (row) {
        row.data[fieldName] = newValue;
    }
    
    // Salva immediatamente su file Excel
    try {
        const rowData = row.data;
        
        const response = await apiCall('save_row', {
            filepath: AppState.currentFilepath,
            row_number: rowNumber,
            row_data: rowData
        });
        
        if (response.success) {
            // Visual feedback: aggiungi classe temporanea
            selectElement.classList.add('just-saved');
            setTimeout(() => {
                selectElement.classList.remove('just-saved');
            }, 1000);
        } else {
            showToast('Errore salvataggio: ' + response.error, 'error');
        }
    } catch (error) {
        console.error('Error saving inline dropdown:', error);
        showToast('Errore salvataggio dropdown', 'error');
    }
}

// Make function globally accessible
window.handleInlineDropdownChange = handleInlineDropdownChange;

// ============================================
// BATCH FILL FUNCTIONS
// ============================================

function openBatchFillModal(fieldName) {
    const label = AppState.headers[fieldName] || fieldName;
    
    // Get unique non-empty values from this column (exclude empty rows and parent rows)
    const uniqueValues = new Set();
    const valueRows = {}; // Track which row has which value
    
    AppState.rows.forEach(row => {
        // Skip empty rows and parent rows
        if (row.is_empty) return;
        if (row.data['parent_child'] === 'Parent') return;
        
        const value = row.data[fieldName];
        if (value && value !== '-' && value !== '') {
            const valueStr = String(value).trim();
            uniqueValues.add(valueStr);
            
            if (!valueRows[valueStr]) {
                valueRows[valueStr] = row.row_number;
            }
        }
    });
    
    if (uniqueValues.size === 0) {
        showToast(`Nessun valore disponibile per "${label}"`, 'warning');
        return;
    }
    
    // Build modal HTML
    let optionsHtml = '';
    Array.from(uniqueValues).sort().forEach(value => {
        const rowNum = valueRows[value];
        optionsHtml += `
            <div class="batch-option" onclick="executeBatchFill('${fieldName}', '${value.replace(/'/g, "\\'")}')">
                <strong>${value}</strong>
                <span style="color: #6b7280; font-size: 12px;">(da riga ${rowNum})</span>
            </div>
        `;
    });
    
    // Count eligible rows (non-empty, non-parent)
    const eligibleRowsCount = AppState.rows.filter(row => 
        !row.is_empty && row.data['parent_child'] !== 'Parent'
    ).length;
    
    const modalHtml = `
        <div class="modal" id="modalBatchFill" style="display: flex;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3>📋 Batch Fill: ${label}</h3>
                    <button class="modal-close" onclick="closeBatchFillModal()">×</button>
                </div>
                <div class="modal-body">
                    <p>Seleziona quale valore copiare in tutte le righe della colonna "${label}":</p>
                    <div class="batch-options-container" style="max-height: 400px; overflow-y: auto;">
                        ${optionsHtml}
                    </div>
                    <div style="margin-top: 15px; padding: 10px; background: #fef3c7; border-radius: 6px; font-size: 13px;">
                        ⚠️ Questa operazione sovrascriverà i valori esistenti in <strong>${eligibleRowsCount} righe</strong> (esclude righe vuote e parent).
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-secondary" onclick="closeBatchFillModal()">Annulla</button>
                </div>
            </div>
        </div>
    `;
    
    // Remove existing modal if present
    const existingModal = document.getElementById('modalBatchFill');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Add to DOM
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function closeBatchFillModal() {
    const modal = document.getElementById('modalBatchFill');
    if (modal) {
        modal.remove();
    }
}

async function executeBatchFill(fieldName, value) {
    const label = AppState.headers[fieldName] || fieldName;
    
    // Count eligible rows (non-empty, non-parent)
    const eligibleRows = AppState.rows.filter(row => 
        !row.is_empty && row.data['parent_child'] !== 'Parent'
    );
    
    if (!confirm(`Confermi di voler copiare il valore "${value}" in ${eligibleRows.length} righe della colonna "${label}"?\n\n(Le righe vuote e parent verranno saltate)`)) {
        return;
    }
    
    closeBatchFillModal();
    showLoading(`Applicando "${value}" a ${eligibleRows.length} righe...`);
    
    try {
        // Update only eligible rows
        let updatedCount = 0;
        let skippedCount = 0;
        
        for (const row of AppState.rows) {
            // Skip empty rows and parent rows
            if (row.is_empty) {
                skippedCount++;
                continue;
            }
            if (row.data['parent_child'] === 'Parent') {
                skippedCount++;
                continue;
            }
            
            // Update in memory
            row.data[fieldName] = value;
            
            // Save to Excel
            const response = await apiCall('save_row', {
                filepath: AppState.currentFilepath,
                row_number: row.row_number,
                row_data: row.data
            });
            
            if (response.success) {
                updatedCount++;
            }
        }
        
        hideLoading();
        
        // Reload table to show changes
        loadExcelRows(AppState.currentFilepath);
        
        showToast(`✅ Batch Fill completato!\n\n✔️ Righe aggiornate: ${updatedCount}\n⏭️ Righe saltate (vuote/parent): ${skippedCount}`, 'success');
        
    } catch (error) {
        hideLoading();
        console.error('Batch fill error:', error);
        showToast('Errore durante batch fill: ' + error.message, 'error');
    }
}

// Make functions globally accessible
window.openBatchFillModal = openBatchFillModal;
window.closeBatchFillModal = closeBatchFillModal;
window.executeBatchFill = executeBatchFill;

// ============================================
// INLINE CELL EDITING (Double Click)
// ============================================

function makeInlineEditable(cellElement) {
    // Prevent editing if already editing
    if (cellElement.querySelector('input')) {
        return;
    }
    
    const rowNumber = parseInt(cellElement.dataset.row);
    const fieldName = cellElement.dataset.field;
    const encodedValue = cellElement.dataset.fullValue || '';
    
    // Decodifica HTML entities per editing (preserva HTML originale)
    const currentValue = decodeHtmlEntities(encodedValue);
    
    // Store original content
    const originalContent = cellElement.innerHTML;
    
    // Create input element
    const input = document.createElement('input');
    input.type = 'text';
    input.value = currentValue; // Valore decodificato per editing
    input.className = 'inline-cell-editor';
    input.style.width = '100%';
    input.style.padding = '4px 8px';
    input.style.border = '2px solid #3b82f6';
    input.style.borderRadius = '4px';
    input.style.fontSize = '13px';
    input.style.boxSizing = 'border-box';
    
    // Function to save and exit
    const saveAndExit = async () => {
        let newValue = input.value.trim();
        
        // Se campo numerico e l'utente ha scritto "0", mantieni 0 (non stringa vuota)
        if (newValue === '0' || newValue === '0.0' || newValue === '0.00') {
            newValue = 0;
        }
        
        // Update cell display immediately
        const displayValue = newValue || '-';
        const truncated = displayValue.length > 30;
        cellElement.innerHTML = truncated ? displayValue.substring(0, 30) + '...' : displayValue;
        cellElement.dataset.fullValue = newValue;
        cellElement.title = truncated ? displayValue : 'Doppio click per modificare';
        
        // Update in AppState
        const row = AppState.rows.find(r => r.row_number === rowNumber);
        if (row) {
            row.data[fieldName] = newValue;
        }
        
        // Save to Excel
        try {
            const rowData = row.data;
            
            const response = await apiCall('save_row', {
                filepath: AppState.currentFilepath,
                row_number: rowNumber,
                row_data: rowData
            });
            
            if (response.success) {
                // ✅ Rimuovi marker "non conforme" se presente
                if (row.invalidFields && row.invalidFields.includes(fieldName)) {
                    row.invalidFields = row.invalidFields.filter(f => f !== fieldName);
                    
                    // Rimuovi anche da localStorage
                    const storageKey = `invalidFields_${AppState.currentFilepath}_${rowNumber}`;
                    if (row.invalidFields.length > 0) {
                        localStorage.setItem(storageKey, JSON.stringify(row.invalidFields));
                    } else {
                        localStorage.removeItem(storageKey);
                    }
                    
                    console.log('✅ [EDIT] Rimosso marker non conforme da campo:', fieldName);
                }
                
                // Visual feedback
                cellElement.style.background = '#dcfce7';
                cellElement.classList.remove('cell-non-compliant'); // Rimuovi classe rosso
                setTimeout(() => {
                    cellElement.style.background = '';
                }, 1000);
            } else {
                showToast('Errore salvataggio: ' + response.error, 'error');
                // Restore original content on error
                cellElement.innerHTML = originalContent;
            }
        } catch (error) {
            console.error('Error saving inline cell:', error);
            showToast('Errore salvataggio cella', 'error');
            // Restore original content on error
            cellElement.innerHTML = originalContent;
        }
    };
    
    // Function to cancel and restore
    const cancelAndRestore = () => {
        cellElement.innerHTML = originalContent;
    };
    
    // Event handlers
    input.addEventListener('blur', saveAndExit);
    
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            input.blur(); // Trigger save
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelAndRestore();
        }
    });
    
    // Replace cell content with input
    cellElement.innerHTML = '';
    cellElement.appendChild(input);
    
    // Focus and select all text
    input.focus();
    input.select();
}

// Make function globally accessible
window.makeInlineEditable = makeInlineEditable;

// ============================================
// ROW EDITOR
// ============================================

function openRowEditor(rowNumber, isNew) {
    console.log('🔍 openRowEditor called:', { rowNumber, isNew, totalRows: AppState.rows.length });
    
    AppState.currentRowNumber = rowNumber;
    
    if (isNew) {
        AppState.editingRow = { row_number: rowNumber, data: {} };
    } else {
        AppState.editingRow = AppState.rows.find(r => r.row_number === rowNumber);
        
        if (!AppState.editingRow) {
            console.error('❌ Riga non trovata!', {
                rowNumber,
                availableRows: AppState.rows.map(r => r.row_number)
            });
            showToast(`Errore: Riga #${rowNumber} non trovata in AppState.rows`, 'error');
            return;
        }
    }

    const editor = document.getElementById('rowEditor');
    const editorTitle = document.getElementById('editorTitle');
    const container = document.getElementById('dynamicFieldsContainer');

    if (isNew) {
        editorTitle.textContent = `Nuovo Prodotto - Riga #${rowNumber}`;
    } else {
        const sku = AppState.editingRow.data?.item_sku || '';
        editorTitle.textContent = `Modifica Prodotto - Riga #${rowNumber}${sku ? ' - ' + sku : ''}`;
    }

    // Render dynamic fields
    renderDynamicFields(container, AppState.editingRow.data || {});

    if (isNew) {
        // Generate EAN automatically for new rows if needed
        generateEanForRow();
    }

    editor.style.display = 'block';
    editor.classList.add('slide-in');
}

function closeRowEditor() {
    const editor = document.getElementById('rowEditor');
    if (editor) {
        editor.classList.remove('slide-in');
        editor.style.display = 'none';

        // Reset state
        AppState.editingRow = null;
        AppState.currentRowNumber = null;
    }
}

function renderDynamicFields(container, rowData) {
    container.innerHTML = '';

    // Usa columnOrder (ordine ESATTO Excel A→B→C→D...)
    // columnOrder è un array di field names: ['feed_product_type', 'item_sku', ...]
    const fieldNames = AppState.columnOrder || Object.keys(AppState.headers);

    // NO GROUPING! Renderizza TUTTI i campi nell'ordine esatto dell'Excel
    fieldNames.forEach(field => {
        // Skip feed_product_type (colonna A, già impostato nel template)
        if (field === 'feed_product_type') {
            return;
        }
        
        const fieldElement = createFieldElement(field, rowData[field]);
        container.appendChild(fieldElement);
    });

    // Re-attach listeners for new elements
    attachFieldListeners();
}

function createFieldElement(fieldName, value = '', isBullet = false) {
    const wrapper = document.createElement('div');
    // Clean field name for label
    const labelText = fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

    // Check if field has dropdown values
    const dropdownValues = AppState.dropdowns[fieldName];

    if (isBullet) {
        wrapper.className = 'bullet-field';
        const bulletNum = fieldName.replace('bullet_point', '');
        wrapper.innerHTML = `
            <label>
                Bullet ${bulletNum}
                <span class="char-count" id="bullet${bulletNum}CharCount">0/500</span>
            </label>
            <textarea 
                class="field-input" 
                rows="2"
                data-field="${fieldName}"
                maxlength="500"
                placeholder="Beneficio ${bulletNum}..."
            >${value || ''}</textarea>
            <div class="field-actions">
                <button class="btn-ai" data-ai-field="${fieldName}" data-index="${bulletNum}">🤖 Ask AI</button>
                <span class="validation-icon" data-validation-field="${fieldName}"></span>
            </div>
        `;
        return wrapper;
    }

    wrapper.className = 'field-group';

    // If field has dropdown values, render select
    if (dropdownValues && dropdownValues.length > 0) {
        const options = dropdownValues.map(opt => {
            const selected = opt === value ? 'selected' : '';
            return `<option value="${opt}" ${selected}>${opt}</option>`;
        }).join('');

        wrapper.innerHTML = `
            <label>${labelText}</label>
            <select class="field-input" data-field="${fieldName}">
                <option value="">-- Seleziona --</option>
                ${options}
            </select>
            <div class="field-actions">
                <!-- No AI for dropdowns -->
                <span class="validation-icon" data-validation-field="${fieldName}"></span>
            </div>
        `;
        return wrapper;
    }

    switch (fieldName) {
        case 'item_name':
            wrapper.innerHTML = `
                <label>
                    <strong>Title</strong>
                    <span class="char-count" id="titleCharCount">0/200</span>
                </label>
                <textarea 
                    class="field-input" 
                    rows="3" 
                    data-field="${fieldName}"
                    maxlength="200"
                    placeholder="Titolo prodotto Amazon..."
                >${value || ''}</textarea>
                <div class="field-actions">
                    <button class="btn-ai" data-ai-field="${fieldName}">🤖 Ask AI</button>
                    <button class="btn-validate" data-validate-field="${fieldName}">✅ Valida</button>
                    <span class="validation-icon" data-validation-field="${fieldName}"></span>
                </div>
                <div class="field-validation" data-validation-field="${fieldName}"></div>
            `;
            break;

        case 'product_description':
            wrapper.innerHTML = `
                <label>
                    <strong>Description (HTML)</strong>
                    <span class="char-count" id="descriptionCharCount">0/2000</span>
                </label>
                <textarea 
                    class="field-input" 
                    rows="6"
                    data-field="${fieldName}"
                    maxlength="2000"
                    placeholder="<strong>Descrizione</strong> con HTML..."
                >${value || ''}</textarea>
                <div class="field-actions">
                    <button class="btn-ai" data-ai-field="${fieldName}">🤖 Ask AI</button>
                    <button class="btn-validate" data-validate-field="${fieldName}">✅ Valida</button>
                    <span class="validation-icon" data-validation-field="${fieldName}"></span>
                </div>
                <div class="field-validation" data-validation-field="${fieldName}"></div>
            `;
            break;

        case 'external_product_id':
            wrapper.innerHTML = `
                <label><strong>EAN / Codice Prodotto</strong></label>
                <div style="display: flex; gap: 10px;">
                    <input 
                        type="text" 
                        id="fieldEan"
                        class="field-input" 
                        data-field="${fieldName}"
                        placeholder="EAN-13 (13 cifre)..."
                        maxlength="13"
                        value="${value || ''}"
                    >
                    <button id="btnGenerateEan" class="btn-secondary">🎲 Genera EAN</button>
                </div>
            `;
            break;

        default:
            // Generic Input
            wrapper.innerHTML = `
                <label>${labelText}</label>
                <input 
                    type="text" 
                    class="field-input" 
                    data-field="${fieldName}"
                    value="${value || ''}"
                >
                <div class="field-actions">
                    <button class="btn-ai" data-ai-field="${fieldName}">🤖 Ask AI</button>
                </div>
            `;
            break;
    }

    return wrapper;
}

function attachFieldListeners() {
    // Character counts
    document.querySelectorAll('.field-input').forEach(input => {
        input.addEventListener('input', () => updateCharCount(input));
        // Initialize count
        updateCharCount(input);
    });

    // Re-attach EAN generator listener if present
    const btnGenerateEan = document.getElementById('btnGenerateEan');
    if (btnGenerateEan) {
        btnGenerateEan.addEventListener('click', generateEanForRow);
    }
}

function saveCurrentRow() {
    if (!AppState.currentRowNumber) {
        showToast('Nessuna riga selezionata', 'error');
        return;
    }

    // Collect all field values (SOLO input/textarea/select, non button/span)
    const rowData = {};
    const allInputs = document.querySelectorAll('#dynamicFieldsContainer input[data-field], #dynamicFieldsContainer textarea[data-field], #dynamicFieldsContainer select[data-field]');
    
    allInputs.forEach(input => {
        const fieldName = input.dataset.field;
        let value = input.value;
        
        // Trim per rimuovere spazi vuoti
        if (typeof value === 'string') {
            value = value.trim();
        }
        
        // Preserva zero numerico
        if (value === '0' || value === '0.0' || value === '0.00') {
            value = 0;
        }
        
        // Converti stringa vuota in '' (non null)
        if (value === null || value === undefined) {
            value = '';
        }
        
        rowData[fieldName] = value;
    });

    showLoading('Salvataggio riga...');

    const isNew = !AppState.editingRow || AppState.editingRow.is_empty;

    const action = isNew ? 'add_row' : 'save_row';
    const payload = {
        filepath: AppState.currentFilepath,
        row_number: AppState.currentRowNumber,
        row_data: rowData,
        generate_ean: false // EAN già generato se nuovo
    };

    apiCall(action, payload)
        .then(response => {
            hideLoading();

            if (response.success) {
                const message = isNew
                    ? 'Nuovo prodotto aggiunto! ✅ Le modifiche sono nel file Excel.'
                    : 'Riga aggiornata! ✅ Le modifiche sono nel file Excel.';
                showToast(message, 'success');
                closeRowEditor();

                loadExcelRows(AppState.currentFilepath);
            } else {
                showToast('Errore salvataggio: ' + response.error, 'error');
                console.error('❌ SAVE ROW - Error:', response.error);
            }
        })
        .catch(error => {
            hideLoading();
            showToast('Errore rete: ' + error.message, 'error');
            console.error('❌ SAVE ROW - Network error:', error);
        });
}

/**
 * Duplica la riga corrente nel modal
 * Crea copia della riga subito sotto l'originale
 */
async function duplicateCurrentRow() {
    if (!AppState.editingRow || !AppState.currentFilepath) {
        showToast('Nessuna riga selezionata', 'error');
        return;
    }
    
    const rowNumber = AppState.editingRow.row_number;
    const rowSku = AppState.editingRow.data.item_sku || 'questa riga';
    const isParent = AppState.editingRow.data.parent_child && 
                     String(AppState.editingRow.data.parent_child).toLowerCase().trim() === 'parent';
    
    let confirmMessage = `Duplicare la riga #${rowNumber} (${rowSku})?\n\nLa copia sarà inserita subito sotto.`;
    
    if (isParent) {
        confirmMessage += '\n\n⚠️ ATTENZIONE: Questa è una riga Parent!';
    }
    
    if (!confirm(confirmMessage)) {
        return;
    }
    
    showLoading('Duplicazione riga in corso...');
    
    try {
        const response = await apiCall('duplicate_row', {
            filepath: AppState.currentFilepath,
            row_number: rowNumber
        });
        
        if (response.success) {
            showToast(`✅ Riga duplicata con successo!\n\nNuova riga: #${response.data.new_row_number}`, 'success');
            
            // Chiudi modal
            closeRowEditor();
            
            // Ricarica tabella per mostrare nuova riga
            await loadExcelRows(AppState.currentFilepath);
            
            // Scorri alla nuova riga (opzionale)
            setTimeout(() => {
                const tbody = document.getElementById('tableBody');
                if (tbody) {
                    const rows = tbody.querySelectorAll('tr');
                    // Trova riga con row_number uguale a new_row_number
                    rows.forEach(tr => {
                        const cells = tr.querySelectorAll('td');
                        if (cells.length > 0 && cells[0].textContent == response.data.new_row_number) {
                            tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            tr.style.backgroundColor = '#dbeafe';
                            setTimeout(() => {
                                tr.style.backgroundColor = '';
                            }, 2000);
                        }
                    });
                }
            }, 500);
            
        } else {
            showToast(`Errore: ${response.error || response.message}`, 'error');
        }
    } catch (error) {
        console.error('Duplicate row error:', error);
        showToast('Errore durante la duplicazione della riga', 'error');
    } finally {
        hideLoading();
    }
}

function deleteCurrentRow() {
    if (!AppState.currentRowNumber || AppState.currentRowNumber < 4) {
        showToast('Impossibile eliminare questa riga', 'error');
        return;
    }

    if (!confirm(`Eliminare riga #${AppState.currentRowNumber}?`)) {
        return;
    }

    showLoading('Eliminazione riga...');

    apiCall('delete_row', {
        filepath: AppState.currentFilepath,
        row_number: AppState.currentRowNumber
    })
        .then(response => {
            hideLoading();

            if (response.success) {
                showToast('Riga eliminata ✅', 'success');
                closeRowEditor();
                loadExcelRows(AppState.currentFilepath);
            } else {
                showToast('Errore: ' + response.error, 'error');
            }
        });
}

function generateEanForRow() {
    showLoading('Generazione EAN...');

    apiCall('start', { template_id: 1 }) // Genera solo EAN
        .then(response => {
            hideLoading();

            if (response.success && response.data.ean) {
                const fieldEan = document.getElementById('fieldEan');
                if (fieldEan) {
                    fieldEan.value = response.data.ean;
                    showToast('EAN generato: ' + response.data.ean, 'success');
                }
            }
        });
}

// ============================================
// AI FIELD GENERATION
// ============================================

function generateFieldWithAI(fieldName, index = null) {
    const context = collectContext();
    if (index) context.bullet_index = index;
    
    // ⚠️ VERIFICA: Context non vuoto
    if (!context.sku && !context.current_title && !context.brand) {
        alert('❌ ERRORE: Nessun dato prodotto disponibile!\n\nRicarica la pagina e riprova.');
        showToast('Context vuoto - impossibile generare', 'error');
        return;
    }

    showLoading(`Generazione ${fieldName} con AI...`);

    apiCall('generate_field', {
        session_id: 'temp', // Non serve session per editor
        field_name: fieldName,
        context: context,
        llm: 'gpt4'
    })
        .then(response => {
            hideLoading();

            if (response.success) {
                const input = document.querySelector(`#dynamicFieldsContainer input[data-field="${fieldName}"], #dynamicFieldsContainer textarea[data-field="${fieldName}"]`);
                if (input) {
                    input.value = response.data.content;
                    updateCharCount(input);
                    showToast(`${fieldName} generato! ✅`, 'success');
                }
            } else {
                showToast('Errore AI: ' + response.error, 'error');
            }
        });
}

/**
 * Estrae brand DAL TITOLO ATTUALE (più affidabile del campo brand_name)
 * Assume formato: "Brand - Resto del titolo" o "Brand | Resto"
 */
function extractBrandFromTitle(title) {
    if (!title) return '';
    
    // Pattern comune: "Brand - Prodotto"
    const match = title.match(/^([^-]+)\s*-/);
    if (match) {
        return match[1].trim();
    }
    
    // Pattern alternativo: "Brand | Prodotto"
    const match2 = title.match(/^([^|]+)\s*\|/);
    if (match2) {
        return match2[1].trim();
    }
    
    return '';
}

/**
 * Raccoglie context COMPLETO dal prodotto corrente
 * Versione RICCA per AI che non dimentica mai il prodotto
 */
function collectContext() {
    if (!AppState.editingRow || !AppState.editingRow.data) {
        console.error('❌ collectContext: No editing row!');
        return {};
    }
    
    const row = AppState.editingRow.data;
    
    // ===== CORE PRODUCT INFO =====
    const context = {
        // Identificatori
        sku: row.item_sku || '',
        
        // Brand: priorità al brand NEL TITOLO, fallback su brand_name field
        brand_from_title: extractBrandFromTitle(row.item_name),
        brand_field: row.brand_name || '',
        brand: extractBrandFromTitle(row.item_name) || row.brand_name || '',
        
        manufacturer: row.manufacturer || '',
        
        // Titolo e descrizione COMPLETI
        current_title: row.item_name || '',
        current_description: row.product_description || '',
        
        // Categorizzazione
        category: row.feed_product_type || 'grocery',
        product_type: row.item_type_name || '',
        
        // Formato/Peso
        unit_count: row.unit_count || '',
        unit_count_type: row.unit_count_type || '',
        weight: extractWeight(row),
        
        // Origine
        country: row.country_of_origin || '',
        
        // EAN/ASIN
        external_product_id: row.external_product_id || '',
        external_product_id_type: row.external_product_id_type || '',
        
        // Prezzo (per context)
        price: row.standard_price || '',
        
        // ===== BULLET POINTS ESISTENTI =====
        bullets: extractBulletPoints(row),
        
        // ===== KEYWORDS ESISTENTI =====
        keywords: extractExistingKeywords(row),
        
        // ===== ATTRIBUTI PRODOTTO =====
        attributes: extractProductAttributes(row),
        
        // ===== CONTENUTI ESISTENTI (per reference) =====
        existing_content: {
            generic_keywords: row.generic_keywords || '',
            style_name: row.style_name || '',
            flavor_name: row.flavor_name || '',
            size_name: row.size_name || '',
            color_name: row.color_name || ''
        }
    };
    
    return context;
}

/**
 * Estrae tutti i bullet points esistenti
 */
function extractBulletPoints(rowData) {
    const bullets = [];
    
    for (let i = 1; i <= 10; i++) {
        const bullet = rowData[`bullet_point${i}`];
        if (bullet && bullet.trim() !== '' && bullet !== '-') {
            bullets.push(bullet.trim());
        }
    }
    
    return bullets;
}

/**
 * Estrae keyword esistenti da tutti i campi disponibili
 */
function extractExistingKeywords(rowData) {
    const keywords = [];
    
    // Da generic_keywords
    if (rowData.generic_keywords) {
        const kws = rowData.generic_keywords.split(/[,;]/);
        keywords.push(...kws);
    }
    
    // Da platinum_keywords
    for (let i = 1; i <= 5; i++) {
        const pk = rowData[`platinum_keywords${i}`];
        if (pk && pk !== '-') keywords.push(pk);
    }
    
    // Da thesaurus_attribute_keywords
    for (let i = 1; i <= 5; i++) {
        const tk = rowData[`thesaurus_attribute_keywords${i}`];
        if (tk && tk !== '-') keywords.push(tk);
    }
    
    return keywords
        .map(k => k.trim())
        .filter(k => k.length > 2)
        .filter((k, i, arr) => arr.indexOf(k) === i); // Unici
}

/**
 * Estrae attributi prodotto rilevanti
 */
function extractProductAttributes(rowData) {
    const attrs = [];
    
    // Diet type
    for (let i = 1; i <= 3; i++) {
        const diet = rowData[`diet_type${i}`];
        if (diet && diet !== '-') attrs.push(diet);
    }
    
    // Specialties
    for (let i = 1; i <= 5; i++) {
        const spec = rowData[`specialty${i}`];
        if (spec && spec !== '-') attrs.push(spec);
    }
    
    // Material type free (es. gluten_free)
    for (let i = 1; i <= 5; i++) {
        const mat = rowData[`material_type_free${i}`];
        if (mat && mat !== '-') attrs.push(mat);
    }
    
    // Recommended uses
    if (rowData.recommended_uses_for_product) {
        attrs.push(rowData.recommended_uses_for_product);
    }
    
    return attrs.filter((a, i, arr) => arr.indexOf(a) === i);
}

/**
 * Estrae peso/formato con PRIORITÀ da item_sku
 */
/**
 * Estrae e normalizza formato per titolo mobile-first
 * PRIORITÀ: item_sku → unit_count → size_name
 * OUTPUT: '100g', '250ml', '5pz' (formato breve, NO spazi)
 */
function extractWeight(rowData) {
    let format = '';
    
    // PRIORITÀ 1: item_sku (più affidabile)
    const sku = rowData.item_sku || '';
    const skuMatch = sku.match(/(\d+(?:[.,]\d+)?)\s*(gr|g|kg|ml|l|cl|dl|pz|pezzi|gramm[io]|litri?|millilitri?|metro|metri|cm)/i);
    
    if (skuMatch) {
        let value = skuMatch[1].replace(',', '.');
        format = value + normalizeUnit(skuMatch[2]);
    }
    
    // PRIORITÀ 2: unit_count + unit_count_type
    if (!format && rowData.unit_count && rowData.unit_count_type) {
        format = rowData.unit_count + normalizeUnit(rowData.unit_count_type);
    }
    
    // PRIORITÀ 3: size_name
    if (!format && rowData.size_name && rowData.size_name !== '-') {
        format = rowData.size_name;
    }
    
    // PRIORITÀ 4: item_display_weight
    if (!format && rowData.item_display_weight && rowData.item_display_weight_unit_of_measure) {
        format = rowData.item_display_weight + normalizeUnit(rowData.item_display_weight_unit_of_measure);
    }
    
    return format;
}

/**
 * Normalizza unità di misura per titolo (formato compatto)
 */
function normalizeUnit(unit) {
    const normalized = {
        // Peso
        'gr': 'g',
        'g': 'g',
        'grammo': 'g',
        'grammi': 'g',
        'kg': 'kg',
        'kilogrammo': 'kg',
        
        // Volume
        'ml': 'ml',
        'millilitro': 'ml',
        'millilitri': 'ml',
        'l': 'l',
        'litro': 'l',
        'litri': 'l',
        'cl': 'cl',
        'dl': 'dl',
        
        // Pezzi
        'pz': 'pz',
        'pezzi': 'pz',
        'pezzo': 'pz',
        
        // Dimensioni
        'cm': 'cm',
        'metro': 'm',
        'metri': 'm',
        'm': 'm'
    };
    
    const lower = unit.toLowerCase().trim();
    return normalized[lower] || lower;
}

// ============================================
// VALIDATION
// ============================================

function validateField(fieldName, content) {
    apiCall('validate_field', {
        field_name: fieldName,
        content: content
    })
        .then(response => {
            if (response.success) {
                displayValidation(fieldName, response.data);
            }
        });
}

function validateAllRows() {
    if (!AppState.currentFilepath) {
        showToast('Nessun file caricato', 'error');
        return;
    }

    showLoading('Validazione tutte le righe...');

    apiCall('validate_all_rows', { filepath: AppState.currentFilepath })
        .then(response => {
            hideLoading();

            if (response.success) {
                const total = response.data.total_validated;
                showToast(`${total} righe validate ✅`, 'success');
            } else {
                showToast('Errore validazione: ' + response.error, 'error');
            }
        });
}

function displayValidation(fieldName, validation) {
    const icon = document.querySelector(`.validation-icon[data-validation-field="${fieldName}"]`);
    const container = document.querySelector(`.field-validation[data-validation-field="${fieldName}"]`);

    if (icon) {
        if (validation.valid) {
            icon.textContent = '✅';
            icon.className = 'validation-icon valid';
        } else if (validation.errors && validation.errors.length > 0) {
            icon.textContent = '❌';
            icon.className = 'validation-icon error';
        } else {
            icon.textContent = '⚠️';
            icon.className = 'validation-icon warning';
        }
    }

    if (container) {
        let html = '';

        if (validation.errors && validation.errors.length > 0) {
            validation.errors.forEach(err => {
                html += `<div class="validation-item error">❌ ${err}</div>`;
            });
        }

        if (validation.warnings && validation.warnings.length > 0) {
            validation.warnings.forEach(warn => {
                html += `<div class="validation-item warning">⚠️ ${warn}</div>`;
            });
        }

        container.innerHTML = html;
    }
}

// ============================================
// DOWNLOAD
// ============================================

function downloadExcel() {
    if (!AppState.currentFilepath) {
        showToast('Nessun file caricato', 'error');
        return;
    }

    const downloadUrl = `/modules/margynomic/admin/creaexcel/ai/api/download.php?filepath=${encodeURIComponent(AppState.currentFilepath)}`;

    window.location.href = downloadUrl;
    showToast('Download avviato ✅', 'success');
    
    // Reset updated rows highlighting after download
    setTimeout(() => {
        AppState.updatedRows = [];
        renderProductsTable(); // Re-render to remove green highlighting
    }, 1000);
}

// ============================================
// UI HELPERS
// ============================================

function updateCharCount(input, content = null) {
    const text = content || input.value || '';
    const plainText = typeof text === 'string' ? text.replace(/<[^>]*>/g, '') : '';
    const length = plainText.length;

    const fieldName = input.dataset.field;
    let counterId = null;

    if (fieldName === 'item_name') counterId = 'titleCharCount';
    else if (fieldName === 'product_description') counterId = 'descriptionCharCount';
    else if (fieldName && fieldName.startsWith('bullet_point')) {
        const num = fieldName.replace('bullet_point', '');
        counterId = `bullet${num}CharCount`;
    }

    if (counterId) {
        const counter = document.getElementById(counterId);
        if (counter) {
            let max = parseInt(input.getAttribute('maxlength'));
            if (isNaN(max) || max <= 0) max = 500; // Default fallback

            counter.textContent = `${length}/${max}`;
            counter.classList.toggle('over-limit', length > max);
        }
    }
}

function clearAllFields() {
    document.querySelectorAll('.field-input').forEach(input => {
        input.value = '';
    });
}

function showModal(modalId) {
    document.getElementById(modalId).style.display = 'flex';
}

function closeAllModals() {
    document.querySelectorAll('.modal').forEach(modal => {
        modal.style.display = 'none';
    });
}

function showToast(message, type = 'info') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = `toast toast-${type} show`;

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

function showLoading(text = 'Caricamento...') {
    const overlay = document.getElementById('loadingOverlay');
    const loadingText = document.getElementById('loadingText');
    if (loadingText) loadingText.textContent = text;
    if (overlay) overlay.style.display = 'flex';
}

function hideLoading() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) overlay.style.display = 'none';
}

// ============================================
// API COMMUNICATION
// ============================================

function apiCall(action, data = {}) {
    // Includi sempre user_id selezionato nelle chiamate API
    const payload = {
        action: action,
        user_id: AppState.selectedUserId,
        ...data
    };
    
    // ✅ Timeout più lungo per workflow completo (8 campi Gemini 3 Pro)
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 360000); // 6 minuti
    
    return fetch(window.AI_CONFIG.apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        signal: controller.signal
    })
        .then(response => {
            clearTimeout(timeoutId);
            
            // ✅ Gestione errori HTTP migliore
            if (!response.ok) {
                // Se risposta non è JSON (es: HTML error page)
                return response.text().then(text => {
                    console.error('Server Error Response (not JSON):', text.substring(0, 500));
                    throw new Error(`HTTP ${response.status}: Server error. Check console for details.`);
                });
            }
            
            return response.json();
        })
        .catch(error => {
            clearTimeout(timeoutId);
            
            if (error.name === 'AbortError') {
                console.error('API Timeout: Request took longer than 3 minutes');
                throw new Error('Request timeout (3 min). File Excel troppo grande?');
            }
            
            console.error('API Error:', error);
            throw error;
        });
}

// ============================================
// RECENT FILES
// ============================================

async function loadRecentFiles() {
    const container = document.getElementById('recentFilesContainer');
    if (!container) return;
    
    try {
        const response = await apiCall('list_recent_files', {
            folder: AppState.currentFolder || ''
        });
        
        if (response.success && response.files && response.files.length > 0) {
            renderRecentFiles(response.files);
        } else {
            container.innerHTML = `
                <div class="no-recent-files">
                    <p>📂 Nessun file recente trovato</p>
                    <p class="hint">Carica il tuo primo file Excel per iniziare</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('❌ Error loading recent files:', error);
        console.error('Error details:', error.message, error.stack);
        container.innerHTML = `
            <div class="error-message">
                ❌ Errore caricamento file recenti<br>
                <small style="font-size: 12px; font-weight: normal;">${error.message}</small><br>
                <a href="../api/debug_recent_files.php" target="_blank" style="color: white; text-decoration: underline;">
                    🔍 Debug
                </a>
            </div>
        `;
    }
}

function renderRecentFiles(files) {
    const container = document.getElementById('recentFilesContainer');
    
    const html = files.map(file => `
        <div class="recent-file-card" data-filepath="${file.filepath}">
            <div class="file-icon">📊</div>
            <div class="file-info">
                <div class="file-name" title="${file.name}">${file.name}</div>
                <div class="file-meta">
                    <span class="file-date">🕒 ${file.last_modified_formatted}</span>
                    <span class="file-size">💾 ${file.size_formatted}</span>
                </div>
            </div>
            <div class="file-actions">
                <button class="btn-open-file" data-filepath="${file.filepath}" data-filename="${file.name}">
                    📂 Apri
                </button>
                <button class="btn-duplicate-file" data-filepath="${file.filepath}" data-filename="${file.name}">
                    📋 Duplica
                </button>
                <button class="btn-rename-file" data-filepath="${file.filepath}" data-filename="${file.name}">
                    ✏️ Rinomina
                </button>
                <button class="btn-delete-file" data-filepath="${file.filepath}" data-filename="${file.name}">
                    🗑️
                </button>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html;
    
    // Attach click listeners for open button
    container.querySelectorAll('.btn-open-file').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const filepath = btn.dataset.filepath;
            const filename = btn.dataset.filename;
            openRecentFile(filepath, filename);
        });
    });
    
    // Attach click listeners for duplicate button
    container.querySelectorAll('.btn-duplicate-file').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const filepath = btn.dataset.filepath;
            const filename = btn.dataset.filename;
            duplicateFile(filepath, filename);
        });
    });
    
    // Attach click listeners for rename button
    container.querySelectorAll('.btn-rename-file').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const filepath = btn.dataset.filepath;
            const filename = btn.dataset.filename;
            renameFile(filepath, filename);
        });
    });
    
    // Attach click listeners for delete button
    container.querySelectorAll('.btn-delete-file').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const filepath = btn.dataset.filepath;
            const filename = btn.dataset.filename;
            deleteRecentFile(filepath, filename);
        });
    });
    
    // Also make cards clickable (but not if clicking buttons)
    container.querySelectorAll('.recent-file-card').forEach(card => {
        card.addEventListener('click', (e) => {
            // Don't trigger if clicking buttons
            if (e.target.closest('.btn-open-file') || e.target.closest('.btn-duplicate-file') || 
                e.target.closest('.btn-rename-file') || e.target.closest('.btn-delete-file')) {
                return;
            }
            const filepath = card.dataset.filepath;
            const filename = card.querySelector('.file-name').textContent;
            openRecentFile(filepath, filename);
        });
    });
}

async function openRecentFile(filepath, filename) {
    // Setta AppState PRIMA di chiamare loadExcelRows
    AppState.currentFile = filename;
    AppState.currentFilepath = filepath;
    console.log('=== openRecentFile: filepath impostato ===');
    console.log('filepath:', filepath);
    console.log('window.AppState.currentFilepath:', window.AppState.currentFilepath);
    
    // Chiama loadExcelRows che contiene TUTTA la logica (include auto-sync!)
    loadExcelRows(filepath);
}

async function deleteRecentFile(filepath, filename) {
    if (!confirm(`🗑️ Sei sicuro di voler eliminare "${filename}"?\n\nQuesta azione è irreversibile!`)) {
        return;
    }
    
    showLoading('Eliminazione ' + filename + '...');
    
    try {
        const response = await apiCall('delete_file', { filepath: filepath });
        
        hideLoading();
        
        if (response.success) {
            showToast(`File "${filename}" eliminato con successo`, 'success');
            
            // Reload recent files list
            loadRecentFiles();
        } else {
            showToast('Errore: ' + (response.message || 'Eliminazione fallita'), 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error deleting file:', error);
        showToast('Errore eliminazione file: ' + error.message, 'error');
    }
}

/**
 * Duplica un file Excel
 */
async function duplicateFile(filepath, filename) {
    const newName = prompt(`Duplica file: ${filename}\n\nInserisci il nome per la copia (lascia vuoto per nome automatico):`, '');
    
    // User clicked cancel
    if (newName === null) {
        return;
    }
    
    showLoading('Duplicazione file in corso...');
    
    try {
        const response = await apiCall('duplicate_file', {
            filepath: filepath,
            new_name: newName || null
        });
        
        hideLoading();
        
        if (response.success) {
            showToast(`✅ File duplicato con successo!\n\nNuovo file: ${response.new_filename}`, 'success');
            
            // Ricarica lista file
            await loadRecentFiles();
        } else {
            showToast(`Errore: ${response.error}`, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Duplicate file error:', error);
        showToast('Errore durante la duplicazione del file', 'error');
    }
}

/**
 * Rinomina un file Excel
 */
async function renameFile(filepath, currentFilename) {
    // Estrai nome senza estensione
    const nameWithoutExt = currentFilename.replace(/\.(xlsx?|xlsm)$/i, '');
    
    const newName = prompt(`Rinomina file: ${currentFilename}\n\nInserisci il nuovo nome:`, nameWithoutExt);
    
    // User clicked cancel or empty name
    if (!newName || newName.trim() === '') {
        return;
    }
    
    if (newName.trim() === nameWithoutExt) {
        showToast('Il nome non è cambiato', 'warning');
        return;
    }
    
    showLoading('Rinomina file in corso...');
    
    try {
        const response = await apiCall('rename_file', {
            filepath: filepath,
            new_name: newName.trim()
        });
        
        hideLoading();
        
        if (response.success) {
            showToast(`✅ File rinominato con successo!\n\nNuovo nome: ${response.new_filename}`, 'success');
            
            // Se il file rinominato è quello attualmente aperto, aggiorna il riferimento
            if (AppState.currentFilepath === filepath) {
                AppState.currentFilepath = response.new_filepath;
                AppState.currentFile = response.new_filename;
                
                // Aggiorna UI
                const fileNameEl = document.getElementById('fileName');
                if (fileNameEl) {
                    fileNameEl.textContent = 'File: ' + response.new_filename;
                }
            }
            
            // Ricarica lista file
            await loadRecentFiles();
        } else {
            showToast(`Errore: ${response.error}`, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Rename file error:', error);
        showToast('Errore durante la rinomina del file', 'error');
    }
}

// ============================================
// FOLDER MANAGEMENT
// ============================================

async function loadFolders() {
    try {
        // Force user_id anche se dovrebbe essere aggiunto automaticamente da apiCall
        const response = await apiCall('list_folders', {
            user_id: AppState.selectedUserId
        });
        
        if (response.success && response.folders) {
            renderFolderDropdown(response.folders);
        }
    } catch (error) {
        console.error('❌ Error loading folders:', error);
    }
}

function renderFolderDropdown(folders) {
    const folderSelect = document.getElementById('folderSelect');
    if (!folderSelect) return;
    
    // Keep root option
    folderSelect.innerHTML = '<option value="">🏠 Root (tutti i file)</option>';
    
    // Add folder options
    folders.forEach(folder => {
        const option = document.createElement('option');
        option.value = folder.name;
        option.textContent = `📁 ${folder.name} (${folder.file_count} file)`;
        folderSelect.appendChild(option);
    });
}

async function createNewFolder() {
    const folderNameInput = document.getElementById('folderName');
    const folderName = folderNameInput.value.trim();
    
    if (!folderName) {
        showToast('Inserisci un nome per la cartella', 'error');
        return;
    }
    
    // Validate folder name (only alphanumeric, dash, underscore)
    if (!/^[a-zA-Z0-9_-]+$/.test(folderName)) {
        showToast('Nome cartella non valido. Usa solo lettere, numeri, trattini e underscore', 'error');
        return;
    }
    
    showLoading('Creazione cartella ' + folderName + '...');
    
    try {
        const response = await apiCall('create_folder', { folder_name: folderName });
        
        hideLoading();
        
        if (response.success) {
            showToast(`Cartella "${folderName}" creata con successo`, 'success');
            
            // Close modal
            closeAllModals();
            folderNameInput.value = '';
            
            // Reload folders
            await loadFolders();
            
            // Select the new folder
            const folderSelect = document.getElementById('folderSelect');
            if (folderSelect) {
                folderSelect.value = folderName;
                AppState.currentFolder = folderName;
                updateFolderSubtitle();
                loadRecentFiles();
            }
        } else {
            showToast('Errore: ' + (response.message || 'Creazione cartella fallita'), 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error creating folder:', error);
        showToast('Errore creazione cartella: ' + error.message, 'error');
    }
}

function updateFolderSubtitle() {
    const subtitle = document.getElementById('folderSubtitle');
    if (subtitle) {
        if (AppState.currentFolder) {
            subtitle.textContent = `File in: ${AppState.currentFolder}`;
        } else {
            subtitle.textContent = 'Tutti i file';
        }
    }
}

// ============================================
// CATEGORY MANAGEMENT
// ============================================

// ============================================
// CATEGORIES MANAGEMENT (Database-backed)
// ============================================

// Store categories in memory for quick access
let categoriesCache = [];

async function loadCategories() {
    try {
        const response = await apiCall('list_categories', {});
        
        if (response.success) {
            categoriesCache = response.categories || [];
            renderCategoriesList(categoriesCache);
        } else {
            showToast('Errore caricamento categorie: ' + response.error, 'error');
        }
    } catch (error) {
        console.error('Error loading categories:', error);
        showToast('Errore caricamento categorie', 'error');
    }
}

function renderCategoriesList(categories) {
    const container = document.getElementById('categoriesList');
    if (!container) return;
    
    if (categories.length === 0) {
        container.innerHTML = '<p style="text-align:center;color:#999;padding:20px;">Nessuna categoria. Aggiungine una!</p>';
        return;
    }
    
    const html = categories.map(cat => `
        <div class="category-item">
            <span class="category-name">${cat.name}</span>
            <button class="btn-delete-category" data-id="${cat.id}" data-name="${cat.name}">
                🗑️ Elimina
            </button>
        </div>
    `).join('');
    
    container.innerHTML = html;
    
    // Attach delete listeners
    container.querySelectorAll('.btn-delete-category').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = parseInt(btn.dataset.id);
            const name = btn.dataset.name;
            deleteCategory(id, name);
        });
    });
}

async function addCategory() {
    const input = document.getElementById('newCategoryName');
    const categoryName = input.value.trim();
    
    if (!categoryName) {
        showToast('Inserisci un nome categoria', 'error');
        return;
    }
    
    try {
        showLoading('Creazione categoria...');
        const response = await apiCall('create_category', {
            category_name: categoryName
        });
        
        hideLoading();
        
        if (response.success) {
            // Clear input
            input.value = '';
            
            // Reload categories
            await loadCategories();
            
            // Reload page to update dropdown
            showToast(`✅ Categoria "${categoryName}" creata!`, 'success');
            
            // Refresh page to update dropdown in upload modal
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showToast('Errore: ' + response.error, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error adding category:', error);
        showToast('Errore creazione categoria', 'error');
    }
}

async function deleteCategory(id, name) {
    if (!confirm(`Eliminare la categoria "${name}"?\n\nNota: La cartella filesystem sarà preservata.`)) {
        return;
    }
    
    try {
        showLoading('Eliminazione categoria...');
        const response = await apiCall('delete_category', {
            category_id: id
        });
        
        hideLoading();
        
        if (response.success) {
            showToast(`Categoria "${name}" eliminata`, 'success');
            
            // Reload categories
            await loadCategories();
            
            // Refresh page to update dropdown
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showToast('Errore: ' + response.error, 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error deleting category:', error);
        showToast('Errore eliminazione categoria', 'error');
    }
}

// ============================================
// PRICE SYNC FROM DATABASE
// ============================================

async function syncPricesFromDatabase() {
    if (!AppState.currentFilepath) {
        showToast('Nessun file caricato', 'error');
        return;
    }
    
    if (!confirm('🔄 Sincronizzare i prezzi da database?\n\nI prezzi nella colonna "Standard Price" verranno aggiornati con i valori presenti nel database products.\n\nLe righe Parent (senza SKU) verranno saltate.')) {
        return;
    }
    
    showLoading('Sincronizzazione prezzi da database...');
    
    try {
        const response = await apiCall('sync_prices_from_db', {
            filepath: AppState.currentFilepath
        });
        
        hideLoading();
        
        if (response.success) {
            const data = response.data;
            
            // Salva row numbers aggiornati per evidenziarli nella UI
            AppState.updatedRows = data.updated_rows || [];
            
            // Warning se nessun prezzo aggiornato
            if (data.updated_count === 0) {
                showToast(
                    `⚠️ Nessun prezzo aggiornato!\n\n` +
                    `Righe totali: ${data.total_rows}\n` +
                    `SKU non trovati in DB: ${data.not_found_count}\n` +
                    `Parent saltati: ${data.skipped_parent_count}\n` +
                    `Righe vuote: ${data.skipped_empty_count}\n\n` +
                    `Controlla i log del server per dettagli.`,
                    'warning'
                );
            } else {
                showToast(
                    `✅ Sincronizzazione completata!\n\n` +
                    `Righe elaborate: ${data.total_rows}\n` +
                    `✅ Prezzi aggiornati: ${data.updated_count}\n` +
                    `⚠️ SKU non trovati: ${data.not_found_count}\n` +
                    `ℹ️ Parent saltati: ${data.skipped_parent_count}`,
                    'success'
                );
            }
            
            // Reload table to show updated prices
            loadExcelRows(AppState.currentFilepath);
        } else {
            showToast('Errore: ' + (response.message || 'Sincronizzazione fallita'), 'error');
        }
    } catch (error) {
        hideLoading();
        console.error('Error syncing prices:', error);
        showToast('Errore sincronizzazione prezzi: ' + error.message, 'error');
    }
}

// ============================================
// EAN SYNC FROM EXCEL TO DATABASE
// ============================================

/**
 * Sincronizza codici EAN da Excel a database products.ean
 */
async function syncEanCodes() {
    if (!AppState.currentFilepath) {
        showToast('Nessun file caricato', 'error');
        return;
    }
    
    if (!confirm('🏷️ Sincronizzare i codici EAN dal file Excel al database?\n\nSaranno aggiornati solo i codici EAN (non ASIN).\n\nI codici verranno copiati dalla colonna "External Product Id" al database products.ean')) {
        return;
    }
    
    showLoading('Sincronizzazione codici EAN in corso...');
    
    try {
        const response = await apiCall('sync_ean', {
            filepath: AppState.currentFilepath
        });
        
        hideLoading();
        
        if (response.success) {
            const stats = response.data;
            
            if (stats.updated_count === 0) {
                showToast(
                    `⚠️ Nessun EAN aggiornato!\n\n` +
                    `Righe totali: ${stats.total_rows}\n` +
                    `SKU non trovati: ${stats.not_found}\n` +
                    `ASIN saltati: ${stats.skipped_asin}\n` +
                    `Parent saltati: ${stats.skipped_parent}\n` +
                    `Celle vuote: ${stats.skipped_empty}`,
                    'warning'
                );
            } else {
                showToast(
                    `✅ Sincronizzazione EAN completata!\n\n` +
                    `Righe totali: ${stats.total_rows}\n` +
                    `✅ EAN aggiornati: ${stats.updated_count}\n` +
                    `⚠️ SKU non trovati: ${stats.not_found}\n` +
                    `ℹ️ ASIN saltati: ${stats.skipped_asin}\n` +
                    `ℹ️ Parent saltati: ${stats.skipped_parent}`,
                    'success'
                );
            }
            
            // Ricarica tabella per mostrare verifiche aggiornate
            await loadExcelRows(AppState.currentFilepath);
        } else {
            showToast(`Errore: ${response.data.error}`, 'error');
        }
    } catch (error) {
        showToast('Errore durante la sincronizzazione EAN: ' + error.message, 'error');
    } finally {
        hideLoading();
    }
}

/**
 * Versione automatica di syncEanCodes (senza conferma utente)
 * Chiamata automaticamente 5 secondi dopo caricamento file
 */
async function syncEanCodesAutomatic() {
    if (!AppState.currentFilepath) {
        return;
    }
    
    showLoading('🔄 Sincronizzazione automatica codici EAN...');
    
    try {
        const response = await apiCall('sync_ean', {
            filepath: AppState.currentFilepath
        });
        
        hideLoading();
        
        if (response.success) {
            const stats = response.data;
            
            // Toast compatto per auto-sync
            if (stats.updated_count > 0) {
                showToast(
                    `✅ Auto-sync EAN: ${stats.updated_count} codici aggiornati`,
                    'success'
                );
                
                // Ricarica tabella per mostrare celle verdi
                await loadExcelRowsQuiet(AppState.currentFilepath);
            }
        } else {
            showToast(`⚠️ Auto-sync EAN: ${response.data.error}`, 'warning');
        }
    } catch (error) {
        hideLoading();
        showToast('⚠️ Errore auto-sync EAN', 'warning');
    }
}

/**
 * Versione automatica di syncPricesFromDatabase (senza conferma utente)
 * Chiamata automaticamente dopo caricamento file
 */
async function syncPricesFromDatabaseAutomatic() {
    if (!AppState.currentFilepath) {
        return;
    }
    
    showLoading('💰 Sincronizzazione automatica prezzi...');
    
    try {
        const response = await apiCall('sync_prices_from_db', {
            filepath: AppState.currentFilepath
        });
        
        hideLoading();
        
        if (response.success) {
            const data = response.data;
            
            // Salva row numbers aggiornati per evidenziarli nella UI
            AppState.updatedRows = data.updated_rows || [];
            
            // Toast compatto per auto-sync
            if (data.updated_count > 0) {
                showToast(
                    `✅ Auto-sync prezzi: ${data.updated_count} prezzi aggiornati`,
                    'success'
                );
                
                // Ricarica tabella per mostrare prezzi aggiornati
                await loadExcelRowsQuiet(AppState.currentFilepath);
            }
        } else {
            showToast(`⚠️ Auto-sync prezzi: ${response.message || response.error}`, 'warning');
        }
    } catch (error) {
        hideLoading();
        showToast('⚠️ Errore auto-sync prezzi', 'warning');
    }
}

/**
 * Carica righe Excel SENZA mostrare toast (per auto-reload dopo sync)
 */
async function loadExcelRowsQuiet(filepath) {
    try {
        const response = await apiCall('load_excel_rows', { filepath: filepath });
        
        if (response.success) {
            AppState.rows = response.data.rows;
            AppState.headers = response.data.metadata.headers;
            AppState.columnOrder = response.data.metadata.column_order || Object.values(response.data.metadata.headers);
            AppState.dropdowns = response.data.metadata.dropdown_values || {};
            
            // Aggiorna verifiche
            if (response.data.metadata.sku_verification) {
                const verification = response.data.metadata.sku_verification;
                AppState.verifiedSkuRows = verification.matched_sku_rows || [];
                AppState.verifiedEanRows = verification.matched_ean_rows || [];
                AppState.verifiedAsinRows = verification.matched_asin_rows || [];
            }
            
            // Re-render tabella (mostra celle verdi)
            renderProductsTable();
        }
    } catch (error) {
        // Silenzioso - non disturbare l'utente
    }
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================

/**
 * Keyboard shortcuts nel modal editor
 */
document.addEventListener('keydown', function(e) {
    // Ctrl+D o Cmd+D per duplicare (solo nel modal aperto)
    if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
        const rowEditor = document.getElementById('rowEditor');
        if (rowEditor && rowEditor.style.display === 'block') {
            e.preventDefault();
            duplicateCurrentRow();
        }
    }
});

