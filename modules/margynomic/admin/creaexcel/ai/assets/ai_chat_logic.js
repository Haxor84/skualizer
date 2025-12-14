/**
 * AI CHAT MODAL LOGIC
 * Gestisce l'interfaccia chat-style per generazione multi-campo
 */

// Stato chat
console.log('=== ai_chat_logic.js: Inizializzazione ===');
console.log('window.AppState PRIMA del check:', window.AppState);
if (!window.AppState) {
    console.log('⚠️ AppState non esiste, creo oggetto vuoto');
    window.AppState = {};
} else {
    console.log('✅ AppState già esistente, NON sovrascrivo');
}
AppState.aiChatContext = null;
AppState.generatedContent = null;
console.log('window.AppState DOPO aggiunta props:', window.AppState);

/**
 * Apre chat AI per prodotto specifico
 */
function openAiChat(rowIndex) {
    // Usa nuovo sistema conversazionale se disponibile
    if (typeof openAiConversation === 'function') {
        openAiConversation(rowIndex);
        return;
    }
    
    // Fallback a vecchio sistema
    const row = AppState.rows[rowIndex];
    
    if (!row) {
        alert('Errore: Prodotto non trovato');
        return;
    }
    
    // Salva context prodotto corrente
    AppState.aiChatContext = {
        rowIndex: rowIndex,
        data: row.data
    };
    
    // Aggiorna info prodotto in header
    const productInfo = document.getElementById('aiProductInfo');
    const sku = row.data.item_sku || 'N/D';
    const title = row.data.item_name || 'Prodotto';
    productInfo.textContent = `• ${sku}`;
    productInfo.title = title;
    
    // Mostra modal
    const modal = document.getElementById('aiChatModal');
    modal.classList.add('active');
    
    // Reset conversazione (vecchio sistema)
    resetAiConversationLegacy();
}

/**
 * Chiude chat AI
 */
function closeAiChat() {
    const modal = document.getElementById('aiChatModal');
    modal.classList.remove('active');
    AppState.aiChatContext = null;
}

/**
 * Reset conversazione AI
 */
function resetAiConversationLegacy() {
    const conversation = document.getElementById('aiChatConversation');
    
    if (!conversation) return; // New modal doesn't have this element
    
    // Mantieni solo messaggio di benvenuto
    const welcomeMsg = conversation.querySelector('.ai-welcome');
    conversation.innerHTML = '';
    if (welcomeMsg) {
        conversation.appendChild(welcomeMsg.cloneNode(true));
    }
}

/**
 * Seleziona/Deseleziona tutti i campi
 */
function selectAllFields() {
    const checkboxes = document.querySelectorAll('.ai-field-checkboxes input[type="checkbox"]');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(cb => {
        cb.checked = !allChecked;
    });
}

/**
 * Genera contenuti AI per campi selezionati
 */
async function generateAiContent() {
    // Verifica context
    if (!AppState.aiChatContext) {
        alert('Errore: Nessun prodotto selezionato');
        return;
    }
    
    // Raccogli campi selezionati
    const checkboxes = document.querySelectorAll('.ai-field-checkboxes input[type="checkbox"]:checked');
    const selectedFields = Array.from(checkboxes).map(cb => cb.value);
    
    if (selectedFields.length === 0) {
        alert('Seleziona almeno un campo da generare');
        return;
    }
    
    // Mostra loading
    addAiMessage('user', `Genera: ${selectedFields.map(f => f.replace('_', ' ')).join(', ')}`);
    addAiMessage('ai', '⏳ Sto generando contenuti ottimizzati...', true);
    
    try {
        // Raccogli context COMPLETO
        const context = collectContextFromRow(AppState.aiChatContext.data);
        
        // Chiama API multi-field
        const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'generate_multiple_fields',
                fields: JSON.stringify(selectedFields),
                context: JSON.stringify(context)
            })
        });
        
        const result = await response.json();
        
        // Rimuovi loading
        removeLastAiMessage();
        
        if (result.success) {
            // Mostra preview contenuti generati (con thinking se disponibile)
            displayGeneratedContent(result.fields, result.overlap_analysis, result.thinking);
        } else {
            addAiMessage('ai', `❌ Errore: ${result.error || 'Generazione fallita'}`);
        }
        
    } catch (error) {
        console.error('AI generation error:', error);
        removeLastAiMessage();
        addAiMessage('ai', `❌ Errore di connessione: ${error.message}`);
    }
}

/**
 * Raccoglie context da riga (versione completa)
 * Priority: 1) brand_name CAMPO, 2) manufacturer, 3) brand dal titolo (fallback), 4) NOBRAND
 */
function collectContextFromRow(rowData) {
    // Estrai brand con priorità corretta
    const brandFromTitle = extractBrandFromTitle(rowData.item_name);
    const brandFromField = rowData.brand_name || '';
    const brandFromManufacturer = rowData.manufacturer || '';
    
    // Priority CORRETTA: brand_name > manufacturer > Title (fallback) > NOBRAND
    const finalBrand = brandFromField || brandFromManufacturer || brandFromTitle || 'NOBRAND';
    
    return {
        sku: rowData.item_sku || '',
        brand_from_title: brandFromTitle,
        brand_field: brandFromField,
        brand: finalBrand,
        manufacturer: brandFromManufacturer,
        current_title: rowData.item_name || '',
        current_description: rowData.product_description || '',
        category: rowData.feed_product_type || 'grocery',
        product_type: rowData.item_type_name || '',
        unit_count: rowData.unit_count || '',
        unit_count_type: rowData.unit_count_type || '',
        weight: extractWeight(rowData),
        country: rowData.country_of_origin || '',
        external_product_id: rowData.external_product_id || '',
        external_product_id_type: rowData.external_product_id_type || '',
        price: rowData.standard_price || '',
        bullets: extractBulletPoints(rowData),
        keywords: extractExistingKeywords(rowData),
        attributes: extractProductAttributes(rowData),
        existing_content: {
            generic_keywords: rowData.generic_keywords || '',
            style_name: rowData.style_name || '',
            flavor_name: rowData.flavor_name || '',
            size_name: rowData.size_name || '',
            color_name: rowData.color_name || ''
        }
    };
}

/**
 * Aggiunge messaggio nella chat
 */
function addAiMessage(type, content, isLoading = false) {
    const conversation = document.getElementById('aiChatConversation');
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `ai-message ${type === 'user' ? 'ai-user-message' : ''}`;
    if (isLoading) messageDiv.classList.add('ai-loading');
    
    messageDiv.innerHTML = `
        <div class="ai-avatar">${type === 'user' ? '👤' : '🤖'}</div>
        <div class="ai-content">
            <p>${content}</p>
        </div>
    `;
    
    conversation.appendChild(messageDiv);
    conversation.scrollTop = conversation.scrollHeight;
}

/**
 * Rimuovi ultimo messaggio (per loading)
 */
function removeLastAiMessage() {
    const conversation = document.getElementById('aiChatConversation');
    const lastMsg = conversation.querySelector('.ai-loading');
    if (lastMsg) lastMsg.remove();
}

/**
 * Mostra contenuti generati con preview
 */
function displayGeneratedContent(fields, overlapAnalysis, thinking) {
    let html = '<div class="ai-generated-preview">';
    
    // Show thinking if available (Extended Thinking feature)
    if (thinking) {
        html += '<div class="ai-thinking">';
        html += '<div class="ai-thinking-label">🧠 AI Thinking Process:</div>';
        html += '<div class="ai-thinking-content">' + escapeHtml(thinking) + '</div>';
        html += '</div>';
    }
    
    html += '<strong>✅ Contenuti generati con successo!</strong>';
    
    // Preview campi
    for (const [fieldName, fieldData] of Object.entries(fields)) {
        // Salta campi speciali (iniziano con _)
        if (fieldName.startsWith('_')) continue;
        
        // ⚠️ MOSTRA ANCHE SE success=false MA content presente
        // Questo è importante per description che può avere warnings ma essere comunque valida
        if (!fieldData || (!fieldData.content && fieldData.content !== '')) {
            continue; // Skip solo se realmente vuoto
        }
        
        const label = fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        const content = fieldData.content || '';
        const validation = fieldData.validation || {};
        const isSuccess = fieldData.success;
        const icon = isSuccess ? '✅' : '❌';
        
        html += `<div class="ai-field-preview">`;
        html += `<div class="ai-field-label">${icon} ${label} (${validation.length || content.length || 0} caratteri)</div>`;
        html += `<div class="ai-field-content">${escapeHtml(content.substring(0, 300))}${content.length > 300 ? '...' : ''}</div>`;
        
        // Errors (usando classe CSS)
        if (validation.errors && validation.errors.length > 0) {
            html += `<div class="validation-error">❌ ${validation.errors.join('<br>❌ ')}</div>`;
        }
        
        // Warnings (usando classe CSS)
        const hasWarnings = validation.warnings && validation.warnings.length > 0;
        if (hasWarnings) {
            html += `<div class="validation-warning">⚠️ ${validation.warnings.join('<br>⚠️ ')}</div>`;
        }
        
        // NON mostrare più pulsanti singoli, solo pulsante batch globale
        
        html += `</div>`;
    }
    
    // Length warning
    if (fields._length_warning) {
        html += `<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 6px;">`;
        html += `<strong>⚠️ Lunghezza:</strong> ${fields._length_warning.message}<br>`;
        html += `<small>Campi corti: ${fields._length_warning.short_fields.join(', ')}</small>`;
        html += `</div>`;
    }
    
    // Overlap analysis
    if (overlapAnalysis && overlapAnalysis.has_overlap) {
        html += `<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 6px;">`;
        html += `<strong>⚠️ Attenzione:</strong> Alcune parole si ripetono troppo: `;
        html += overlapAnalysis.duplicates.map(d => d.word).join(', ');
        html += `</div>`;
    }
    
    // Bottoni azioni
    html += `<div class="ai-apply-buttons">`;
    
    // Conta campi con errori
    let errorCount = 0;
    for (const [fieldName, fieldData] of Object.entries(fields)) {
        if (fieldName.startsWith('_')) continue;
        if (!fieldData.success || (fieldData.validation?.warnings?.length > 0)) {
            errorCount++;
        }
    }
    
    // Mostra bottone rigenera solo se ci sono errori
    if (errorCount > 0) {
        html += `<button onclick="regenerateAllErrors()" class="btn-regenerate-all" style="background: #f59e0b;">🔄 Rigenera ${errorCount} Campi con Errori</button>`;
    }
    
    html += `<button onclick="applyGeneratedContent()" class="btn-apply-changes">✅ Applica Modifiche</button>`;
    html += `<button onclick="discardGeneratedContent()" class="btn-discard-changes">❌ Scarta</button>`;
    html += `</div>`;
    
    html += '</div>';
    
    addAiMessage('ai', html);
    
    // Salva contenuti generati per applicazione
    AppState.generatedContent = fields;
}

/**
 * Applica contenuti generati alla riga
 */
async function applyGeneratedContent() {
    if (!AppState.generatedContent || !AppState.aiChatContext) {
        alert('Errore: Nessun contenuto da applicare');
        return;
    }
    
    const rowIndex = AppState.aiChatContext.rowIndex;
    const row = AppState.rows[rowIndex];
    
    // Applica ogni campo (escludi campi speciali che iniziano con _)
    for (const [fieldName, fieldData] of Object.entries(AppState.generatedContent)) {
        if (fieldName.startsWith('_')) continue;
        if (fieldData.success && fieldData.content) {
            row.data[fieldName] = fieldData.content;
        }
    }
    
    // Aggiorna tabella
    renderProductsTable();
    
    // Salva automaticamente nel file Excel
    addAiMessage('ai', '💾 Salvataggio automatico nel file Excel...', true);
    
    try {
        const response = await apiCall('save_row', {
            filepath: AppState.currentFilepath,
            row_number: row.row_number,
            row_data: row.data
        });
        
        // Rimuovi messaggio loading
        removeLastAiMessage();
        
        if (response.success) {
            addAiMessage('ai', '✅ Modifiche salvate con successo nel file Excel!');
        } else {
            addAiMessage('ai', '❌ Errore salvataggio: ' + response.error);
        }
    } catch (error) {
        removeLastAiMessage();
        console.error('Save error:', error);
        addAiMessage('ai', '❌ Errore salvataggio nel file Excel: ' + error.message);
    }
    
    // Pulisci
    AppState.generatedContent = null;
}

/**
 * Scarta contenuti generati
 */
function discardGeneratedContent() {
    AppState.generatedContent = null;
    addAiMessage('ai', 'Contenuti scartati. Puoi selezionare altri campi e generare di nuovo.');
}

/**
 * Rigenera un singolo campo con errori/warnings
 */
async function regenerateField(fieldName) {
    if (!AppState.aiChatContext) {
        showToast('Errore: Context non disponibile', 'error');
        return;
    }
    
    const fieldLabel = fieldName.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    addAiMessage('ai', `🔄 Rigenerazione ${fieldLabel} in corso...`, true);
    
    try {
        // Raccogli context COMPLETO (come fa generateAiContent)
        const context = collectContextFromRow(AppState.aiChatContext.data);
        
        // Chiama API con formato corretto (URLSearchParams)
        const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'generate_multiple_fields',
                fields: JSON.stringify([fieldName]),
                context: JSON.stringify(context)
            })
        });
        
        const result = await response.json();
        
        removeLastAiMessage();
        
        if (result.success && result.fields && result.fields[fieldName]) {
            // Aggiorna il campo generato
            if (AppState.generatedContent) {
                AppState.generatedContent[fieldName] = result.fields[fieldName];
                
                // Ri-renderizza preview completo
                displayGeneratedContent(
                    AppState.generatedContent, 
                    result.overlap_analysis,
                    result.thinking
                );
            }
            
            addAiMessage('ai', `✅ ${fieldLabel} rigenerato!`);
        } else {
            throw new Error(result.error || 'Rigenerazione fallita');
        }
    } catch (error) {
        removeLastAiMessage();
        console.error('Errore rigenerazione:', error);
        addAiMessage('ai', `❌ Errore: ${error.message}`, false);
    }
}

/**
 * Rigenera TUTTI i campi con errori o warnings
 */
async function regenerateAllErrors() {
    if (!AppState.generatedContent || !AppState.aiChatContext) {
        showToast('Nessun contenuto da rigenerare', 'error');
        return;
    }
    
    // Raccogli campi con problemi
    const fieldsToRegenerate = [];
    for (const [fieldName, fieldData] of Object.entries(AppState.generatedContent)) {
        if (fieldName.startsWith('_')) continue;
        
        const hasErrors = !fieldData.success;
        const hasWarnings = fieldData.validation?.warnings?.length > 0;
        
        if (hasErrors || hasWarnings) {
            fieldsToRegenerate.push(fieldName);
        }
    }
    
    if (fieldsToRegenerate.length === 0) {
        showToast('Nessun campo con errori da rigenerare', 'info');
        return;
    }
    
    addAiMessage('ai', `🔄 Rigenerazione ${fieldsToRegenerate.length} campi con errori...`, true);
    
    try {
        const context = collectContextFromRow(AppState.aiChatContext.data);
        
        const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'generate_multiple_fields',
                fields: JSON.stringify(fieldsToRegenerate),
                context: JSON.stringify(context)
            })
        });
        
        const result = await response.json();
        
        removeLastAiMessage();
        
        if (result.success) {
            // Merge campi rigenerati
            for (const [fieldName, fieldData] of Object.entries(result.fields)) {
                if (!fieldName.startsWith('_')) {
                    AppState.generatedContent[fieldName] = fieldData;
                }
            }
            
            // Ri-renderizza preview completo
            displayGeneratedContent(
                AppState.generatedContent,
                result.overlap_analysis,
                result.thinking
            );
            
            addAiMessage('ai', `✅ ${fieldsToRegenerate.length} campi rigenerati!`);
        } else {
            throw new Error(result.error || 'Rigenerazione fallita');
        }
    } catch (error) {
        removeLastAiMessage();
        console.error('Errore rigenerazione batch:', error);
        addAiMessage('ai', `❌ Errore: ${error.message}`, false);
    }
}

/**
 * Escape HTML helper for preview
 */
function escapeHtmlForPreview(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}


