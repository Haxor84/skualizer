<?php
/**
 * Excel Editor View - Grid Handsontable
 * File: modules/margynomic/admin/creaexcel/views/editor.php
 */

// ========================================
// SOPPRIMI WARNING PHPSPREADSHEET
// ========================================
error_reporting(E_ERROR | E_PARSE); // Solo errori fatali
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Avvia sessione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../admin_helpers.php';
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../ExcelListingManager.php';

requireAdmin();

$excelId = $_GET['id'] ?? null;

if (!$excelId) {
    die('ID Excel non specificato');
}

// Carica dati Excel
$manager = new ExcelListingManager();
$data = $manager->loadExcelForEditor($excelId);

if (!$data['success']) {
    die('Errore caricamento dati: ' . $data['error']);
}

$excel = $data['excel'];
$metadata = $data['metadata'];
$rows = $data['rows'];
$productNames = $data['product_names'];
$amazonHeaders = $data['amazon_headers'] ?? [];
$dropdownValues = $data['dropdown_values'] ?? [];

// Prepara dati per Handsontable
$columnMapping = $metadata['column_mapping'];
$headers = [];
$columnsConfig = [];
$rowsData = [];
$amazonHeadersData = [];

// Estrai tutte le colonne dal primo prodotto
if (!empty($rows)) {
    $firstRow = $rows[0]['row_data'];
    
    foreach ($firstRow as $columnLetter => $value) {
        $headers[] = $columnLetter;
        
        // Configurazione base colonna
        $columnsConfig[] = [
            'type' => 'text',
            'data' => $columnLetter
        ];
    }
    
    // Prepara intestazioni Amazon come prime 3 righe (se disponibili)
    if (!empty($amazonHeaders) && !empty($headers)) {
        foreach ($amazonHeaders as $amazonRow) {
            // Converti in array indicizzato con stesse colonne dei dati prodotti
            $formattedRow = [];
            for ($i = 0; $i < count($headers); $i++) {
                $formattedRow[] = isset($amazonRow[$i]) ? $amazonRow[$i] : '';
            }
            $amazonHeadersData[] = $formattedRow;
        }
    }
    
    // Converti rows in formato 2D array per Handsontable
    foreach ($rows as $row) {
        $rowArray = [];
        foreach ($headers as $col) {
            $rowArray[] = $row['row_data'][$col] ?? '';
        }
        $rowsData[] = [
            'id' => $row['id'],
            'row_number' => $row['row_number'],
            'sku' => $row['sku'],
            'product_id' => $row['product_id'],
            'validation_status' => $row['validation_status'],
            'validation_errors' => $row['validation_errors'] ?? [],
            'data' => $rowArray
        ];
    }
}

$adminNav = getAdminNavigation();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Excel Editor - <?php echo htmlspecialchars($excel['filename_originale']); ?></title>
    
    <!-- Handsontable CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/handsontable/dist/handsontable.full.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../../css/margynomic.css">
    <link rel="stylesheet" href="../assets/excel_editor.css">
    
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
        }
        
        .editor-container {
            padding: 20px;
        }
        
        .editor-header {
            background: white;
            padding: 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .editor-info h2 {
            margin: 0 0 10px 0;
        }
        
        .editor-stats {
            color: #666;
            font-size: 14px;
        }
        
        .toolbar {
            background: white;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary { background: #007bff; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-info { background: #17a2b8; color: white; }
        
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .grid-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        #excel-grid {
            height: 70vh;
            overflow: hidden;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 8px;
            max-width: 600px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
        }
        
        .modal-body {
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .validation-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .validation-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 10px;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-error { background: #f8d7da; color: #721c24; }
        
        /* Stili per celle intestazioni Amazon */
        .amazon-header-cell {
            font-weight: 600 !important;
            font-size: 11px !important;
            cursor: not-allowed !important;
            user-select: none !important;
        }
        
        .amazon-row-1 {
            background: #bbdefb !important;
            color: #0d47a1 !important;
        }
        
        .amazon-row-2 {
            background: #e3f2fd !important;
            color: #1565c0 !important;
        }
        
        .amazon-row-3 {
            background: #f0f8ff !important;
            color: #1976d2 !important;
        }
        
        /* Fix altezza righe intestazioni Amazon */
        .handsontable tbody tr:nth-child(1) td,
        .handsontable tbody tr:nth-child(2) td,
        .handsontable tbody tr:nth-child(3) td {
            height: 40px !important;
            vertical-align: top !important;
            padding: 6px !important;
            white-space: normal !important;
            word-wrap: break-word !important;
        }
        
        /* Validazione errors */
        .validation-error {
            background: #ffebee !important;
            border-left: 3px solid #f44336 !important;
        }
        
        .validation-warning {
            background: #fff9c4 !important;
            border-left: 3px solid #ffc107 !important;
        }
        
        /* Selettore colonne nel modal Find/Replace */
        #columnsSelector {
            font-size: 13px;
        }
        
        #columnsSelector label {
            padding: 6px 10px;
            margin-bottom: 3px !important;
            border-radius: 3px;
            transition: background 0.2s;
        }
        
        #columnsSelector label:hover {
            background: #e3f2fd;
        }
        
        #columnsSelector input[type="checkbox"] {
            margin-right: 8px;
            cursor: pointer;
        }
        
        #selectAllColumns {
            cursor: pointer;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <?php echo $adminNav; ?>
    
    <div class="editor-container">
        
        <!-- Header -->
        <div class="editor-header">
            <div class="editor-info">
                <h2>📊 <?php echo htmlspecialchars($excel['filename_originale']); ?></h2>
                <div class="editor-stats">
                    <span>📄 Righe: <?php echo $excel['num_righe']; ?></span> | 
                    <span>📋 Colonne: <?php echo $excel['num_colonne']; ?></span> | 
                    <span>👤 Utente: <?php echo $excel['user_id']; ?></span> | 
                    <span>🕒 Caricato: <?php echo isset($excel['uploaded_at']) && $excel['uploaded_at'] ? date('d/m/Y H:i', strtotime($excel['uploaded_at'])) : 'N/A'; ?></span>
                </div>
            </div>
            <div>
                <a href="upload.php" class="btn btn-info">
                    ← Torna alla lista
                </a>
            </div>
        </div>
        
        <!-- Toolbar -->
        <div class="toolbar">
            <button id="btnSyncPrices" class="btn btn-primary">
                🔄 Sync Prezzi
            </button>
            <button id="btnFindReplace" class="btn btn-warning">
                🔍 Trova e Sostituisci
            </button>
            <button id="btnValidate" class="btn btn-success">
                ✅ Valida Tutto
            </button>
            <button id="btnSave" class="btn btn-success">
                💾 Salva Modifiche
            </button>
            <button id="btnExport" class="btn btn-primary">
                ⬇️ Esporta Excel
            </button>
        </div>
        
        <!-- Validation Summary -->
        <div id="validationSummary" class="validation-summary" style="display: none;">
            <strong>📊 Stato Validazione:</strong><br>
            <span class="validation-badge badge-success">✅ <span id="validCount">0</span> Validi</span>
            <span class="validation-badge badge-warning">⚠️ <span id="warningCount">0</span> Warning</span>
            <span class="validation-badge badge-error">❌ <span id="errorCount">0</span> Errori</span>
            <button id="btnToggleErrors" class="btn btn-sm" style="margin-left: 15px;">
                📋 Mostra Dettagli Errori
            </button>
        </div>
        
        <!-- Validation Errors Detail -->
        <div id="validationErrorsDetail" style="display: none; background: #fff; border: 1px solid #ddd; border-radius: 5px; padding: 15px; margin-bottom: 20px; max-height: 300px; overflow-y: auto;">
            <strong>🔍 Dettagli Errori e Warning:</strong>
            <div id="errorsList"></div>
        </div>
        
        <!-- Info Box -->
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; padding: 12px; margin-bottom: 20px; font-size: 13px;">
            <strong>ℹ️ Struttura Griglia:</strong><br>
            • <strong style="color: #e3f2fd;">R2-R3 (azzurre)</strong>: Intestazioni Amazon - <strong>SOLO LETTURA</strong><br>
            • <strong style="color: #333;">Riga 4</strong>: Header colonne (già visualizzato sopra)<br>
            • <strong style="color: #28a745;">Righe 5+</strong>: Dati prodotti - <strong>MODIFICABILI</strong><br>
            • <strong style="color: #dc3545;">Celle rosse</strong>: Errori di validazione | <strong style="color: #ffc107;">Celle gialle</strong>: Warning
        </div>
        
        <!-- Grid Container -->
        <div class="grid-container">
            <div id="excel-grid"></div>
        </div>
        
    </div>
    
    <!-- Modal Find/Replace -->
    <div id="modalFindReplace" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🔍 Trova e Sostituisci</h3>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Cerca:</label>
                    <input type="text" id="findText" class="form-control" placeholder="Testo da cercare">
                </div>
                <div class="form-group">
                    <label>Sostituisci con:</label>
                    <input type="text" id="replaceText" class="form-control" placeholder="Nuovo testo">
                </div>
                
                <div class="form-group">
                    <label><strong>🎯 Cerca in colonne:</strong></label>
                    <div style="margin-bottom: 10px;">
                        <label style="margin-right: 15px; cursor: pointer;">
                            <input type="checkbox" id="selectAllColumns" checked> 
                            <strong>Seleziona/Deseleziona Tutte</strong>
                        </label>
                    </div>
                    <div id="columnsSelector" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9; border-radius: 5px;">
                        <!-- Popolato dinamicamente da JavaScript -->
                    </div>
                    <small style="color: #666; display: block; margin-top: 5px;">
                        💡 Seleziona le colonne dove cercare. Se nessuna è selezionata, cerca in tutte.
                    </small>
                </div>
                
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="caseSensitive"> Case sensitive
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button id="btnCancelReplace" class="btn btn-danger">Annulla</button>
                <button id="btnApplyReplace" class="btn btn-success">Applica</button>
            </div>
        </div>
    </div>
    
    <!-- Handsontable JS -->
    <script src="https://cdn.jsdelivr.net/npm/handsontable/dist/handsontable.full.min.js"></script>
    
    <script>
        // Dati caricati da PHP
        const excelId = <?php echo json_encode($excelId); ?>;
        const headers = <?php echo json_encode($headers); ?>;
        const rowsData = <?php echo json_encode($rowsData); ?>;
        const amazonHeadersData = <?php echo json_encode($amazonHeadersData); ?>;
        const columnMapping = <?php echo json_encode($columnMapping); ?>;
        const dropdownValues = <?php echo json_encode($dropdownValues); ?>;
        
        // Column mapping disponibile per debug se necessario
        // console.log('📊 Column Mapping:', columnMapping);
        
        // Prepara dati per Handsontable: unisci intestazioni Amazon + dati prodotti
        let gridData = [];
        
        // Aggiungi SOLO R2 e R3 (salta R1)
        if (amazonHeadersData && amazonHeadersData.length > 0) {
            gridData = amazonHeadersData.slice(1); // Skip R1 (index 0), prendi R2 e R3
        }
        
        // Aggiungi i dati prodotti
        gridData = gridData.concat(rowsData.map(row => row.data));
        
        const numAmazonRows = amazonHeadersData && amazonHeadersData.length > 0 ? 2 : 0; // Solo R2 e R3 visibili
        
        // Custom row headers per mostrare numero riga reale
        const customRowHeaders = function(index) {
            if (index < numAmazonRows) {
                // R2 e R3 (R1 è nascosta)
                return '<strong style="background: #e3f2fd; padding: 4px;">R' + (index + 2) + '</strong>';
            } else {
                // Righe prodotti iniziano da riga 5 nel file originale
                return (index - numAmazonRows + 5);
            }
        };
        
        // Configura colonne con dropdown
        const columnsConfig = [];
        headers.forEach((columnLetter, colIndex) => {
            const config = {
                type: 'text',
                strict: false
            };
            
            // Se la colonna ha dropdown, configuralo come SUGGERIMENTO (non restrizione)
            if (dropdownValues[columnLetter] && dropdownValues[columnLetter].length > 0) {
                config.type = 'dropdown';
                config.source = dropdownValues[columnLetter];
                config.strict = false; // Permetti anche valori custom
                config.allowInvalid = true; // Permetti valori non nella lista
            }
            
            columnsConfig.push(config);
        });
        
        // Inizializza Handsontable
        const container = document.getElementById('excel-grid');
        const hot = new Handsontable(container, {
            data: gridData,
            colHeaders: headers,
            columns: columnsConfig,
            rowHeaders: customRowHeaders,
            height: '70vh',
            width: '100%',
            fixedRowsTop: numAmazonRows, // Fissa le intestazioni Amazon
            stretchH: 'all',
            autoWrapRow: true,
            autoWrapCol: true,
            manualColumnResize: true,
            manualRowResize: true,
            contextMenu: true,
            copyPaste: true,
            fillHandle: {
                autoInsertRow: false
            },
            renderAllRows: false, // Virtual rendering per performance
            viewportRowRenderingOffset: 30,
            viewportColumnRenderingOffset: 10,
            licenseKey: 'non-commercial-and-evaluation',
            dropdownMenu: true, // Abilita menu dropdown per colonne
            
            afterChange: function(changes, source) {
                if (source === 'edit' || source === 'CopyPaste.paste' || source === 'Autofill.fill') {
                    // Impedisci modifiche alle prime 2 righe (intestazioni Amazon R2 e R3)
                    if (changes) {
                        for (let i = 0; i < changes.length; i++) {
                            const [row, prop, oldVal, newVal] = changes[i];
                            if (row < numAmazonRows) {
                                // Ripristina valore originale
                                this.setDataAtCell(row, prop, oldVal, 'revert');
                                alert('⚠️ Le intestazioni Amazon (R2-R3) non sono modificabili!');
                                return;
                            }
                        }
                    }
                    
                    console.log('Dati modificati:', changes);
                    // Mark as modified
                    updateModifiedStatus();
                }
            },
            
            cells: function(row, col) {
                const cellProperties = {};
                
                // Prime 2 righe visibili: R2 e R3 (R1 nascosta)
                if (row < numAmazonRows) {
                    cellProperties.readOnly = true;
                    cellProperties.className = 'amazon-header-cell';
                    
                    // Stile diverso per ogni riga (row 0 = R2, row 1 = R3)
                    if (row === 0) {
                        cellProperties.className += ' amazon-row-2';
                    } else if (row === 1) {
                        cellProperties.className += ' amazon-row-3';
                    }
                } else {
                    // Color coding based on validation per righe prodotti
                    const productRowIndex = row - numAmazonRows;
                    if (rowsData[productRowIndex]) {
                        const status = rowsData[productRowIndex].validation_status;
                        if (status === 'error') {
                            cellProperties.className = 'validation-error';
                        } else if (status === 'warning') {
                            cellProperties.className = 'validation-warning';
                        }
                    }
                }
                
                return cellProperties;
            }
        });
        
        // Mostra pannello validazione se ci sono errori/warning
        function updateValidationSummary() {
            let validCount = 0;
            let warningCount = 0;
            let errorCount = 0;
            const errorsList = [];
            
            rowsData.forEach((row, index) => {
                if (row.validation_status === 'valid') {
                    validCount++;
                } else if (row.validation_status === 'warning') {
                    warningCount++;
                    // validation_errors è già un array (decodificato da PHP)
                    if (row.validation_errors && Array.isArray(row.validation_errors) && row.validation_errors.length > 0) {
                        errorsList.push({
                            rowNumber: row.row_number,
                            status: 'warning',
                            errors: row.validation_errors
                        });
                    }
                } else if (row.validation_status === 'error') {
                    errorCount++;
                    // validation_errors è già un array (decodificato da PHP)
                    if (row.validation_errors && Array.isArray(row.validation_errors) && row.validation_errors.length > 0) {
                        errorsList.push({
                            rowNumber: row.row_number,
                            status: 'error',
                            errors: row.validation_errors
                        });
                    }
                }
            });
            
            // Mostra pannello solo se ci sono errori o warning
            if (errorCount > 0 || warningCount > 0) {
                document.getElementById('validCount').textContent = validCount;
                document.getElementById('warningCount').textContent = warningCount;
                document.getElementById('errorCount').textContent = errorCount;
                document.getElementById('validationSummary').style.display = 'block';
                
                // Popola lista errori
                let errorsListHtml = '';
                
                if (errorsList.length === 0) {
                    // Se errorsList è vuoto ma ci sono errori, mostra messaggio
                    errorsListHtml = `
                        <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; margin-top: 10px;">
                            ℹ️ Gli errori sono stati rilevati ma i dettagli non sono disponibili.<br>
                            Possibile causa: validazione eseguita prima dell'ultimo aggiornamento del sistema.<br>
                            <strong>Soluzione:</strong> Clicca nuovamente su "✅ Valida Tutto" per generare i dettagli.
                        </div>
                    `;
                } else {
                    errorsListHtml = errorsList.map(item => {
                        const icon = item.status === 'error' ? '❌' : '⚠️';
                        const color = item.status === 'error' ? '#dc3545' : '#ffc107';
                        const errorsHtml = item.errors.map(err => 
                            `<div style="margin-left: 20px; font-size: 12px;">• ${err.message}</div>`
                        ).join('');
                        return `
                            <div style="margin: 10px 0; padding: 10px; background: #f8f9fa; border-left: 3px solid ${color};">
                                <strong>${icon} Riga ${item.rowNumber}:</strong>
                                ${errorsHtml}
                            </div>
                        `;
                    }).join('');
                }
                
                document.getElementById('errorsList').innerHTML = errorsListHtml;
            }
        }
        
        // Esegui al caricamento
        updateValidationSummary();
        
        // Toggle dettagli errori
        document.getElementById('btnToggleErrors').addEventListener('click', () => {
            const detailDiv = document.getElementById('validationErrorsDetail');
            const btn = document.getElementById('btnToggleErrors');
            if (detailDiv.style.display === 'none') {
                detailDiv.style.display = 'block';
                btn.textContent = '📋 Nascondi Dettagli Errori';
            } else {
                detailDiv.style.display = 'none';
                btn.textContent = '📋 Mostra Dettagli Errori';
            }
        });
        
        // Update modified status
        let isModified = false;
        function updateModifiedStatus() {
            isModified = true;
            document.getElementById('btnSave').textContent = '💾 Salva Modifiche *';
        }
        
        // Sync Prezzi
        document.getElementById('btnSyncPrices').addEventListener('click', async () => {
            if (!confirm('Sincronizzare prezzi da database? Questo sovrascriverà i prezzi attuali nell\'Excel.')) {
                return;
            }
            
            try {
                const response = await fetch('../api/sync_prices.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ excel_id: excelId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ Prezzi sincronizzati! ${result.synced} prodotti aggiornati.`);
                    location.reload();
                } else {
                    alert('❌ Errore: ' + result.error);
                }
            } catch (error) {
                alert('❌ Errore connessione: ' + error.message);
            }
        });
        
        // Find/Replace
        document.getElementById('btnFindReplace').addEventListener('click', () => {
            // Popola selettore colonne
            const columnsSelector = document.getElementById('columnsSelector');
            columnsSelector.innerHTML = '';
            
            headers.forEach((columnLetter, index) => {
                // Usa nome display dalla riga 2 (R2) se disponibile
                const columnName = amazonHeadersData && amazonHeadersData.length > 1 && amazonHeadersData[1][index]
                    ? amazonHeadersData[1][index] 
                    : columnLetter;
                
                const checkbox = document.createElement('label');
                checkbox.style.display = 'block';
                checkbox.style.marginBottom = '5px';
                checkbox.style.cursor = 'pointer';
                checkbox.innerHTML = `
                    <input type="checkbox" class="column-checkbox" value="${columnLetter}" checked>
                    <strong>${columnLetter}:</strong> ${columnName}
                `;
                columnsSelector.appendChild(checkbox);
            });
            
            document.getElementById('modalFindReplace').style.display = 'block';
        });
        
        // Toggle Seleziona/Deseleziona Tutte
        document.getElementById('selectAllColumns').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.column-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
        
        document.getElementById('btnCancelReplace').addEventListener('click', () => {
            document.getElementById('modalFindReplace').style.display = 'none';
        });
        
        document.getElementById('btnApplyReplace').addEventListener('click', async () => {
            const findText = document.getElementById('findText').value;
            const replaceText = document.getElementById('replaceText').value;
            const caseSensitive = document.getElementById('caseSensitive').checked;
            
            // Raccogli colonne selezionate
            const selectedColumns = [];
            document.querySelectorAll('.column-checkbox:checked').forEach(cb => {
                selectedColumns.push(cb.value);
            });
            
            if (!findText) {
                alert('Inserisci testo da cercare');
                return;
            }
            
            if (selectedColumns.length === 0) {
                if (!confirm('⚠️ Nessuna colonna selezionata. Cercare in TUTTE le colonne?')) {
                    return;
                }
            }
            
            try {
                const response = await fetch('../api/bulk_replace.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        excel_id: excelId,
                        search: findText,
                        replace: replaceText,
                        case_sensitive: caseSensitive,
                        columns: selectedColumns.length > 0 ? selectedColumns : null // null = tutte
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const columnsMsg = selectedColumns.length > 0 
                        ? ` in ${selectedColumns.length} colonne` 
                        : ' (tutte le colonne)';
                    alert(`✅ Sostituiti ${result.replaced_count} occorrenze in ${result.affected_rows} righe${columnsMsg}.`);
                    document.getElementById('modalFindReplace').style.display = 'none';
                    location.reload();
                } else {
                    alert('❌ Errore: ' + result.error);
                }
            } catch (error) {
                alert('❌ Errore connessione: ' + error.message);
            }
        });
        
        // Validate
        document.getElementById('btnValidate').addEventListener('click', async () => {
            try {
                const response = await fetch('../api/validate.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ excel_id: excelId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ Validazione completata!\n\nValidi: ${result.valid_count}\nWarning: ${result.warning_count}\nErrori: ${result.error_count}\n\n⏳ La pagina si ricaricherà per mostrare i risultati...`);
                    
                    // Ricarica pagina per mostrare i colori delle celle e i dettagli errori
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    alert('❌ Errore validazione: ' + result.error);
                }
            } catch (error) {
                alert('❌ Errore connessione: ' + error.message);
            }
        });
        
        // Save
        document.getElementById('btnSave').addEventListener('click', async () => {
            const currentData = hot.getData();
            
            // Prepara changes (skip le prime 2 righe visibili = R2 e R3)
            const changes = [];
            rowsData.forEach((row, index) => {
                const rowDataObj = {};
                const actualRowIndex = index + numAmazonRows; // Offset per intestazioni Amazon (R2 e R3)
                
                headers.forEach((col, colIndex) => {
                    rowDataObj[col] = currentData[actualRowIndex][colIndex];
                });
                
                changes.push({
                    row_id: row.id,
                    row_data: rowDataObj
                });
            });
            
            console.log('Salvataggio di', changes.length, 'righe prodotti (escluse intestazioni Amazon)');
            
            try {
                const response = await fetch('../api/save_changes.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        excel_id: excelId,
                        changes: changes
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Modifiche salvate con successo!');
                    isModified = false;
                    document.getElementById('btnSave').textContent = '💾 Salva Modifiche';
                } else {
                    alert('❌ Errore salvataggio: ' + result.error);
                }
            } catch (error) {
                alert('❌ Errore connessione: ' + error.message);
            }
        });
        
        // Export
        document.getElementById('btnExport').addEventListener('click', async () => {
            if (isModified) {
                if (!confirm('Ci sono modifiche non salvate. Vuoi salvare prima di esportare?')) {
                    return;
                }
                // Trigger save
                document.getElementById('btnSave').click();
                await new Promise(resolve => setTimeout(resolve, 1000)); // Wait for save
            }
            
            try {
                const response = await fetch('../api/export.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ excel_id: excelId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Redirect to download
                    window.location.href = 'download.php?id=' + excelId;
                } else {
                    alert('❌ Errore export: ' + result.error);
                }
            } catch (error) {
                alert('❌ Errore connessione: ' + error.message);
            }
        });
        
        // Warn on unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (isModified) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>

