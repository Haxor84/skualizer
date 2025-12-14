/**
 * AI Conversational Workflow
 * Gestisce il flusso step-by-step con UI moderna
 */

// Conversation State
const ConvState = {
    currentState: null,
    sku: null,
    context: {},
    keywords: [],
    analysis: '', // NEW: Product analysis from Step1
    selectedFields: [],
    generatedFields: null // NEW: Store generated fields for apply/regenerate
};

/**
 * Apri chat conversazionale per row
 */
function openAiConversation(rowIndex) {
    const row = AppState.rows[rowIndex];
    
    if (!row) {
        showToast('Errore: Prodotto non trovato', 'error');
        return;
    }
    
    // Save context
    const sku = row.data.item_sku || row.data.external_product_id || 'SKU-' + rowIndex;
    ConvState.sku = sku;
    ConvState.context = collectContextFromRow(row.data);
    
    // Update header
    const productInfo = document.getElementById('aiProductInfo');
    if (productInfo) {
        productInfo.textContent = `• ${sku}`;
        productInfo.title = row.data.item_name || 'Prodotto';
    }
    
    // Show modal
    const modal = document.getElementById('aiChatModal');
    if (modal) {
        modal.classList.add('active');
        
        // Start conversation
        startConversation(sku, ConvState.context);
    }
}

/**
 * Chiudi chat
 */
function closeAiConversation() {
    const modal = document.getElementById('aiChatModal');
    if (modal) {
        modal.classList.remove('active');
    }
    
    // Reset state
    ConvState.currentState = null;
    ConvState.keywords = [];
    ConvState.selectedFields = [];
}

/**
 * Start conversation
 */
async function startConversation(sku, context) {
    const messagesContainer = document.getElementById('aiChatMessages');
    if (!messagesContainer) return;
    
    // Clear previous messages
    messagesContainer.innerHTML = '';
    
    // Add welcome message with loading
    addAiMessage('👋 Inizializzo conversazione...', 'loading');
    
    try {
        const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'conversation_start',
                sku: sku,
                context: JSON.stringify(context)
            })
        });
        
        const result = await response.json();
        
        // Remove loading
        removeLastMessage();
        
        if (result.success) {
            handleConversationState(result.data);
        } else {
            addAiMessage('❌ ' + (result.error || 'Errore inizializzazione'), 'error');
        }
        
    } catch (error) {
        console.error('Conversation error:', error);
        removeLastMessage();
        addAiMessage('❌ Errore di connessione', 'error');
    }
}

/**
 * Handle conversation state
 */
function handleConversationState(data) {
    console.log('🎯 [CONV] handleConversationState called', data);
    
    ConvState.currentState = data.state;
    
    // Add AI message
    addAiMessage(data.message, 'ai');
    
    switch (data.state) {
        case 'asin_input':
            console.log('📝 [CONV] State: asin_input');
            renderAsinInput();
            break;
            
        case 'keyword_extraction':
            console.log('🔑 [CONV] State: keyword_extraction', { 
                has_keywords: !!data.keywords,
                trigger_auto_research: data.trigger_auto_research,
                asins: data.asins 
            });
            if (data.keywords) {
                ConvState.keywords = data.keywords;
                renderFieldSelection();
            } else if (data.trigger_auto_research) {
                // NEW: Auto-trigger web research (NO ASIN needed!)
                console.log('🌐 [CONV] Auto-triggering web research');
                extractKeywordsViaWebResearch();
            } else {
                // OLD: Start extraction with ASIN (backward compatibility)
                extractKeywords(data.asins || [], data.extraction_mode || 'ai_generated');
            }
            break;
            
        case 'field_generation':
            console.log('📋 [CONV] State: field_generation', { 
                keyword_count: (data.keywords || []).length,
                has_analysis: !!data.analysis,
                cached: data.cached 
            });
            ConvState.keywords = data.keywords || [];
            ConvState.analysis = data.analysis || ''; // NEW: Save analysis
            renderFieldSelection();
            
            // If cached, show actions
            if (data.cached && data.actions) {
                renderActions(data.actions);
            }
            break;
            
        default:
            console.warn('Unknown state:', data.state);
    }
}

/**
 * Render ASIN input form
 */
function renderAsinInput() {
    const messagesContainer = document.getElementById('aiChatMessages');
    
    const formHTML = `
        <div class="ai-asin-input-form">
            <div class="ai-asin-inputs">
                <input type="text" class="ai-asin-input" placeholder="ASIN 1: B0XXXXXXXXX" maxlength="10" />
                <input type="text" class="ai-asin-input" placeholder="ASIN 2: B0XXXXXXXXX (opzionale)" maxlength="10" />
                <input type="text" class="ai-asin-input" placeholder="ASIN 3: B0XXXXXXXXX (opzionale)" maxlength="10" />
            </div>
            <div class="ai-skip-checkbox">
                <input type="checkbox" id="skipAsinResearch" />
                <label for="skipAsinResearch">Salta ricerca (genera keywords AI)</label>
            </div>
            <div class="ai-actions">
                <button onclick="submitAsinInput()" class="ai-action-btn primary">
                    🔍 Avvia Ricerca
                </button>
            </div>
        </div>
    `;
    
    messagesContainer.insertAdjacentHTML('beforeend', formHTML);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

/**
 * Submit ASIN input
 */
async function submitAsinInput() {
    const inputs = document.querySelectorAll('.ai-asin-input');
    const skipCheckbox = document.getElementById('skipAsinResearch');
    
    const asins = Array.from(inputs)
        .map(input => input.value.trim().toUpperCase())
        .filter(asin => asin.length > 0);
    
    const skipResearch = skipCheckbox ? skipCheckbox.checked : false;
    
    // Add user message
    if (skipResearch) {
        addUserMessage('Salta ricerca, genera keywords AI');
    } else {
        addUserMessage('ASIN: ' + (asins.join(', ') || 'Nessuno'));
    }
    
    // Disable form
    document.querySelectorAll('.ai-asin-input, #skipAsinResearch').forEach(el => el.disabled = true);
    
    // Process
    addAiMessage('⏳ Elaborazione...', 'loading');
    
    try {
        const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'conversation_process_asin',
                sku: ConvState.sku,
                asins: JSON.stringify(asins),
                skip_research: skipResearch ? '1' : '0'
            })
        });
        
        const result = await response.json();
        
        removeLastMessage();
        
        if (result.success) {
            handleConversationState(result.data);
        } else {
            addAiMessage('❌ ' + (result.error || 'Errore elaborazione'), 'error');
        }
        
    } catch (error) {
        console.error('ASIN processing error:', error);
        removeLastMessage();
        addAiMessage('❌ Errore di connessione', 'error');
    }
}

/**
 * Extract keywords via web research (NO ASIN needed)
 */
async function extractKeywordsViaWebResearch() {
    console.log('🌐 [CONV] extractKeywordsViaWebResearch called');
    
    addAiMessage('🌐 Ricerca web in corso...', 'loading');
    
    try {
        console.log('📤 [CONV] Calling conversation_process_asin with empty ASIN (web research mode)');
        
        const response = await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'conversation_process_asin',
                sku: ConvState.sku,
                asins: JSON.stringify([]), // Empty ASIN = web research
                skip_research: '0'
            })
        });
        
        const result = await response.json();
        
        console.log('📥 [CONV] Web research response:', result);
        
        removeLastMessage();
        
        if (result.success) {
            handleConversationState(result.data);
        } else {
            addAiMessage('❌ ' + (result.error || 'Errore ricerca web'), 'error');
        }
        
    } catch (error) {
        console.error('Web research error:', error);
        removeLastMessage();
        addAiMessage('❌ Errore durante la ricerca web', 'error');
    }
}

/**
 * Extract keywords (LEGACY - con ASIN competitor)
 */
async function extractKeywords(asins, mode) {
    console.log('🔍 [CONV] extractKeywords called', { asins, mode });
    
    addAiMessage('🔍 Estrazione keywords in corso...', 'loading');
    
    try {
        // Inject ASIN into context
        const contextWithAsins = {
            ...ConvState.context,
            competitor_asins: asins
        };
        
        console.log('📤 [CONV] Calling generate_multiple_fields with context:', contextWithAsins);
        
        const response = await apiCall('generate_multiple_fields', {
            fields: ['_keywords_only'],
            context: contextWithAsins
        });
        
        console.log('📥 [CONV] Response received:', response);
        
        removeLastMessage();
        
        if (response.success && response.keywords) {
            ConvState.keywords = response.keywords;
            console.log('✅ [CONV] Keywords extracted:', response.keywords.length);
            
            addAiMessage(
                `✅ Keywords estratte: **${response.keywords.length}**\n\n` +
                `🔝 Top keywords: ${response.keywords.slice(0, 10).join(', ')}...`,
                'success'
            );
            
            // Save to cache via API
            await fetch('/modules/margynomic/admin/creaexcel/ai/api/ai_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'conversation_save_keywords',
                    sku: ConvState.sku,
                    keywords: JSON.stringify(response.keywords),
                    asins: JSON.stringify(asins),
                    mode: mode
                })
            });
            
            // Show field selection
            setTimeout(() => renderFieldSelection(), 500);
            
        } else {
            addAiMessage('⚠️ Nessuna keyword estratta. Uso fallback.', 'warning');
            ConvState.keywords = [];
            renderFieldSelection();
        }
        
    } catch (error) {
        console.error('Keyword extraction error:', error);
        removeLastMessage();
        addAiMessage('❌ Errore estrazione keywords', 'error');
    }
}

/**
 * Render field selection
 */
function renderFieldSelection() {
    addAiMessage('📋 Seleziona i campi da generare:', 'ai');
    
    const messagesContainer = document.getElementById('aiChatMessages');
    
    const fields = [
        { id: 'item_name', label: '📝 Titolo (Item Name)' },
        { id: 'product_description', label: '📄 Descrizione' },
        { id: 'bullet_point1', label: '• Bullet Point 1' },
        { id: 'bullet_point2', label: '• Bullet Point 2' },
        { id: 'bullet_point3', label: '• Bullet Point 3' },
        { id: 'bullet_point4', label: '• Bullet Point 4' },
        { id: 'bullet_point5', label: '• Bullet Point 5' },
        { id: 'generic_keywords', label: '🔑 Keywords SEO' }
    ];
    
    let fieldHTML = '<div class="ai-field-selector"><div class="ai-field-checkboxes">';
    
    fields.forEach(field => {
        fieldHTML += `
            <div class="ai-field-checkbox">
                <input type="checkbox" id="field_${field.id}" value="${field.id}" checked />
                <label for="field_${field.id}">${field.label}</label>
            </div>
        `;
    });
    
    fieldHTML += `
        </div>
        <div class="ai-actions" style="margin-top: 16px;">
            <button onclick="generateSelectedFields()" class="ai-action-btn primary">
                ✨ Genera Campi Selezionati
            </button>
            <button onclick="closeAiConversation()" class="ai-action-btn secondary">
                ✕ Annulla
            </button>
        </div>
    </div>`;
    
    messagesContainer.insertAdjacentHTML('beforeend', fieldHTML);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

/**
 * Generate selected fields
 */
async function generateSelectedFields() {
    const checkboxes = document.querySelectorAll('.ai-field-checkbox input[type="checkbox"]:checked');
    const fields = Array.from(checkboxes).map(cb => cb.value);
    
    console.log('🎯 [CONV] generateSelectedFields called', { 
        fields_count: fields.length, 
        fields: fields 
    });
    
    if (fields.length === 0) {
        showToast('Seleziona almeno un campo', 'warning');
        return;
    }
    
    ConvState.selectedFields = fields;
    
    // Disable checkboxes
    document.querySelectorAll('.ai-field-checkbox input, .ai-action-btn').forEach(el => el.disabled = true);
    
    addUserMessage(`Genera: ${fields.join(', ')}`);
    addAiMessage(`🤖 Generazione **${fields.length} campi** in corso...\n\nQuesto potrebbe richiedere 30-60 secondi.`, 'loading');
    
    try {
        // Inject keywords + analysis into context
        const contextWithKeywords = {
            ...ConvState.context,
            keywords: ConvState.keywords,
            analysis: ConvState.analysis // NEW: Pass analysis to Step2-5
        };
        
        console.log('📤 [CONV] Calling generate_multiple_fields API', {
            fields: fields,
            context_keys: Object.keys(contextWithKeywords),
            keywords_count: ConvState.keywords.length,
            has_analysis: !!ConvState.analysis,
            analysis_length: ConvState.analysis.length
        });
        
        const response = await apiCall('generate_multiple_fields', {
            fields: fields,
            context: contextWithKeywords
        });
        
        console.log('📥 [CONV] generate_multiple_fields response', response);
        
        removeLastMessage();
        
        if (response.success && response.fields) {
            // Store generated fields for later use
            ConvState.generatedFields = response.fields;
            displayGeneratedFields(response.fields);
        } else {
            addAiMessage('❌ ' + (response.error || 'Errore generazione'), 'error');
        }
        
    } catch (error) {
        console.error('Generation error:', error);
        removeLastMessage();
        addAiMessage('❌ Errore durante la generazione', 'error');
    }
}

/**
 * Display generated fields con preview completo
 */
function displayGeneratedFields(fields) {
    const fieldLabels = {
        'item_name': '📝 Titolo',
        'product_description': '📄 Descrizione',
        'bullet_point1': '• Bullet Point 1',
        'bullet_point2': '• Bullet Point 2',
        'bullet_point3': '• Bullet Point 3',
        'bullet_point4': '• Bullet Point 4',
        'bullet_point5': '• Bullet Point 5',
        'generic_keywords': '🔑 Keywords SEO'
    };
    
    let html = '<div class="generated-fields-container">';
    html += '<h3>✅ Contenuti Generati</h3>';
    
    let allValid = true;
    
    for (const [fieldName, fieldData] of Object.entries(fields)) {
        if (fieldName.startsWith('_')) continue; // Skip meta fields
        
        const label = fieldLabels[fieldName] || fieldName;
        const isValid = fieldData.success && (fieldData.validation?.valid ?? true);
        const content = fieldData.content || '';
        const charCount = fieldData.validation?.char_count || content.length;
        
        if (!isValid) allValid = false;
        
        html += `
            <div class="field-result ${isValid ? 'valid' : 'invalid'}">
                <div class="field-header">
                    <span class="field-label">${label}</span>
                    <span class="field-status">${isValid ? '✓' : '✗'}</span>
                    <span class="field-length">${charCount} char</span>
                </div>
                <div class="field-content">
                    ${formatFieldContent(fieldName, content)}
                </div>
                ${!isValid ? `
                    <div class="field-errors">
                        ${(fieldData.validation?.errors || []).map(e => `<span class="error">⚠️ ${e}</span>`).join('')}
                    </div>
                ` : ''}
            </div>
        `;
    }
    
    html += '</div>';
    
    // Add action buttons
    const validCount = Object.entries(fields).filter(([name, data]) => 
        !name.startsWith('_') && data.success
    ).length;
    const totalCount = Object.keys(fields).filter(name => !name.startsWith('_')).length;
    
    html += `
        <div class="generated-actions">
            <button class="btn-apply-all" 
                    onclick="window.aiConversational.applyAllFields()">
                ✅ Applica Campi Validi (${validCount}/${totalCount})
            </button>
            <button class="btn-regenerate" onclick="window.aiConversational.regenerateFields()">
                🔄 Rigenera Campi Non Validi
            </button>
        </div>
    `;
    
    addAiMessage(html, 'html'); // Add as HTML
}

/**
 * Format field content per display (HTML-safe)
 */
function formatFieldContent(fieldName, content) {
    if (fieldName === 'product_description') {
        // Mostra HTML renderizzato
        return `<div class="html-preview">${content}</div>`;
    }
    
    // Altri campi: escape HTML e mostra come testo
    return `<div class="text-preview">${escapeHtml(content)}</div>`;
}

/**
 * Escape HTML per display sicuro
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Apply all generated fields to Excel
 */
async function applyAllFields() {
    if (!ConvState.generatedFields) {
        showToast('Nessun contenuto da applicare', 'error');
        return;
    }
    
    try {
        addAiMessage('📝 Salvataggio contenuti in corso...', 'loading');
        
        // Find current row
        const currentRow = AppState.rows.find(r => 
            (r.data.item_sku || r.data.external_product_id) === ConvState.sku
        );
        
        if (!currentRow) {
            throw new Error('Prodotto non trovato');
        }
        
        // Apply ALL fields (both valid and invalid)
        let appliedCount = 0;
        let invalidCount = 0;
        const invalidFields = []; // Track fields non conformi per colorarli rossi
        
        for (const [fieldName, fieldData] of Object.entries(ConvState.generatedFields)) {
            if (fieldName.startsWith('_')) continue;
            
            // Applica TUTTI i campi con content (anche se success=false)
            if (fieldData.content) {
                currentRow.data[fieldName] = fieldData.content;
                appliedCount++;
                
                // Marca come non valido se success=false
                if (!fieldData.success) {
                    invalidFields.push(fieldName);
                    invalidCount++;
                }
            }
        }
        
        // Salva lista campi non validi nel row per rendering rosso
        currentRow.invalidFields = invalidFields;
        
        // ✅ Persisti invalidFields in localStorage per mantenere colore rosso dopo reload
        if (invalidFields.length > 0) {
            const storageKey = `invalidFields_${AppState.currentFilepath}_${currentRow.row_number}`;
            localStorage.setItem(storageKey, JSON.stringify(invalidFields));
            
            console.log('💾 [CONV] Salvato invalidFields in localStorage:', {
                row: currentRow.row_number,
                invalidFields: invalidFields,
                storageKey: storageKey
            });
        }
        
        // Save to file
        const response = await apiCall('save_row', {
            filepath: AppState.currentFilepath,
            row_number: currentRow.row_number,
            row_data: currentRow.data
        });
        
        removeLastMessage();
        
        if (response.success) {
            let message = '';
            if (invalidCount > 0) {
                message = `✅ ${appliedCount} campi applicati!\n` +
                         `⚠️ ${invalidCount} contenuti NON conformi (celle ROSSE):\n` +
                         `${invalidFields.map(f => '• ' + f.replace(/_/g, ' ')).join('\n')}\n\n` +
                         `Revisiona manualmente i contenuti evidenziati in rosso.`;
            } else {
                message = `✅ Tutti i ${appliedCount} campi salvati con successo!`;
            }
            
            addAiMessage(message, invalidCount > 0 ? 'warning' : 'success');
            
            // Reload table
            renderProductsTable();
            
            // Close modal after 2 seconds
            setTimeout(() => {
                closeAiConversation();
            }, 2000);
        } else {
            throw new Error(response.error || 'Errore salvataggio');
        }
        
    } catch (error) {
        console.error('Apply fields error:', error);
        removeLastMessage();
        addAiMessage(`❌ Errore: ${error.message}`, 'error');
    }
}

/**
 * Regenerate only invalid fields
 */
async function regenerateFields() {
    if (!ConvState.generatedFields) {
        showToast('Nessun contenuto da rigenerare', 'error');
        return;
    }
    
    // Collect invalid fields
    const invalidFields = [];
    for (const [fieldName, fieldData] of Object.entries(ConvState.generatedFields)) {
        if (fieldName.startsWith('_')) continue;
        if (!fieldData.success || !fieldData.validation?.valid) {
            invalidFields.push(fieldName);
        }
    }
    
    if (invalidFields.length === 0) {
        showToast('Tutti i campi sono validi', 'info');
        return;
    }
    
    addAiMessage(`🔄 Rigenerazione di ${invalidFields.length} campi in corso...`, 'loading');
    
    try {
        // Inject already generated fields into context for cross-field references
        const generatedContext = {};
        if (ConvState.generatedFields) {
            for (const [fieldName, fieldData] of Object.entries(ConvState.generatedFields)) {
                if (!fieldName.startsWith('_') && fieldData.content) {
                    generatedContext[`generated_${fieldName}`] = fieldData.content;
                }
            }
        }
        
        const contextWithKeywords = {
            ...ConvState.context,
            ...generatedContext, // NEW: Include already generated fields
            keywords: ConvState.keywords,
            analysis: ConvState.analysis
        };
        
        const response = await apiCall('generate_multiple_fields', {
            fields: invalidFields,
            context: contextWithKeywords
        });
        
        removeLastMessage();
        
        if (response.success && response.fields) {
            // Merge with existing fields
            ConvState.generatedFields = {
                ...ConvState.generatedFields,
                ...response.fields
            };
            displayGeneratedFields(ConvState.generatedFields);
            addAiMessage('✅ Campi rigenerati!', 'success');
        } else {
            addAiMessage('❌ ' + (response.error || 'Errore rigenerazione'), 'error');
        }
        
    } catch (error) {
        console.error('Regenerate error:', error);
        removeLastMessage();
        addAiMessage('❌ Errore durante la rigenerazione', 'error');
    }
}

// Export functions to window for onclick handlers
window.aiConversational = {
    applyAllFields,
    regenerateFields
};

/**
 * Render action buttons
 */
function renderActions(actions) {
    const messagesContainer = document.getElementById('aiChatMessages');
    
    let actionsHTML = '<div class="ai-actions">';
    
    actions.forEach(action => {
        const btnClass = action.primary ? 'primary' : 'secondary';
        actionsHTML += `
            <button onclick="handleAction('${action.id}')" class="ai-action-btn ${btnClass}">
                ${action.label}
            </button>
        `;
    });
    
    actionsHTML += '</div>';
    
    messagesContainer.insertAdjacentHTML('beforeend', actionsHTML);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

/**
 * Handle action click
 */
function handleAction(actionId) {
    switch (actionId) {
        case 'use_cache':
            renderFieldSelection();
            break;
            
        case 'regenerate':
            addUserMessage('Rigenera keywords');
            extractKeywords([], 'ai_generated');
            break;
            
        default:
            console.warn('Unknown action:', actionId);
    }
}

/**
 * Add AI message
 */
function addAiMessage(text, type = 'ai') {
    const messagesContainer = document.getElementById('aiChatMessages');
    if (!messagesContainer) return;
    
    const icons = {
        ai: '🤖',
        loading: '⏳',
        success: '✅',
        warning: '⚠️',
        error: '❌',
        html: '🤖'
    };
    
    // For HTML type, render content as-is without markdown processing
    const content = type === 'html' 
        ? text 
        : `<p>${text.replace(/\n/g, '<br>').replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')}</p>`;
    
    const messageHTML = `
        <div class="ai-message">
            <div class="ai-avatar">${icons[type] || icons.ai}</div>
            <div class="ai-message-content">
                ${type === 'loading' ? '<div class="ai-loading"><div class="ai-loading-dot"></div><div class="ai-loading-dot"></div><div class="ai-loading-dot"></div></div>' : ''}
                ${content}
            </div>
        </div>
    `;
    
    messagesContainer.insertAdjacentHTML('beforeend', messageHTML);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

/**
 * Add user message
 */
function addUserMessage(text) {
    const messagesContainer = document.getElementById('aiChatMessages');
    if (!messagesContainer) return;
    
    const messageHTML = `
        <div class="ai-message" style="flex-direction: row-reverse; text-align: right;">
            <div class="ai-avatar" style="background: linear-gradient(135deg, #10b981, #059669);">👤</div>
            <div class="ai-message-content" style="background: linear-gradient(135deg, #ecfdf5, #d1fae5);">
                <p>${text}</p>
            </div>
        </div>
    `;
    
    messagesContainer.insertAdjacentHTML('beforeend', messageHTML);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

/**
 * Remove last message
 */
function removeLastMessage() {
    const messagesContainer = document.getElementById('aiChatMessages');
    if (!messagesContainer) return;
    
    const lastMessage = messagesContainer.lastElementChild;
    if (lastMessage) {
        lastMessage.remove();
    }
}

// Export functions to global scope
window.openAiConversation = openAiConversation;
window.closeAiConversation = closeAiConversation;
window.submitAsinInput = submitAsinInput;
window.generateSelectedFields = generateSelectedFields;
window.handleAction = handleAction;

