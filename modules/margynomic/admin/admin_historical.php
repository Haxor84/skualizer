<?php
/**
 * Historical Parser - Admin Interface
 * File: admin/admin_historical.php
 * 
 * Interfaccia per processare report settlement storici dalle cartelle download
 */

require_once 'admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

// === AJAX HANDLERS - DEVONO ESSERE PRIMA DI TUTTO ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    // Include historical scanner
    require_once '../sincro/historical_scanner.php';
    
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'scan_historical':
                $userId = (int)($_POST['user_id'] ?? 0);
                
                if (!$userId) {
                    throw new Exception('User ID mancante');
                }
                
                // Verifica struttura cartelle
                $structure = verifyUserDownloadStructure($userId);
                if (!$structure['settlement_exists']) {
                    throw new Exception('Cartella download non trovata per utente ' . $userId);
                }
                
                // Scansiona file storici
                $result = scanHistoricalFiles($userId);
                
                if (!$result['success']) {
                    throw new Exception($result['error']);
                }
                
                echo json_encode([
                    'success' => true,
                    'files' => $result['files'],
                    'stats' => $result['stats'],
                    'scan_path' => $result['scan_path']
                ]);
                break;
                
            case 'process_file':
                $userId = (int)($_POST['user_id'] ?? 0);
                $filePath = $_POST['file_path'] ?? '';
                
                if (!$userId || !$filePath) {
                    throw new Exception('Parametri mancanti (user_id, file_path)');
                }
                
                // Verifica file exists
                if (!file_exists($filePath)) {
                    throw new Exception('File non trovato: ' . basename($filePath));
                }
                
                // Processa file
                $result = processHistoricalFile($userId, $filePath);
                
                if (!$result['success']) {
                    throw new Exception($result['error']);
                }
                
                echo json_encode([
                    'success' => true,
                    'processed' => $result['processed'],
                    'skipped' => $result['skipped'] ?? 0,
                    'settlement_id' => $result['settlement_id'] ?? null,
                    'message' => $result['message']
                ]);
                break;
                
            default:
                throw new Exception('Azione non riconosciuta: ' . $action);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    exit;
}

$message = '';
$messageType = 'success';

// Ottieni lista utenti
$users = getUsers();

echo getAdminHeader('📁 Historical Parser');
echo getAdminNavigation('historical');
?>

<style>
    .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .scanner-section { background: white; border-radius: 8px; padding: 25px; margin: 20px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
    .user-selector { margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #007bff; }
    .scan-controls { display: flex; gap: 15px; align-items: center; margin: 20px 0; flex-wrap: wrap; }
    .file-table-container { background: white; border-radius: 8px; margin: 20px 0; overflow: hidden; border: 1px solid #e0e0e0; }
    .file-table { width: 100%; border-collapse: collapse; margin: 0; }
    .file-table th, .file-table td { padding: 12px; border-bottom: 1px solid #e0e0e0; text-align: left; }
    .file-table th { background: #f8f9fa; font-weight: bold; }
    .file-table tbody tr:hover { background: #f8f9fa; }
    .log-area { background: #1a1a1a; color: #00ff00; font-family: 'Courier New', monospace; padding: 20px; border-radius: 8px; height: 300px; overflow-y: auto; margin: 20px 0; font-size: 13px; line-height: 1.4; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
    .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; border-left: 4px solid #28a745; }
    .stat-number { font-size: 24px; font-weight: bold; color: #28a745; margin-bottom: 5px; }
    .stat-label { color: #666; font-size: 14px; }
    .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-block; font-size: 14px; }
    .btn-primary { background: #007bff; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-warning { background: #ffc107; color: #000; }
    .btn-danger { background: #dc3545; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn:hover { opacity: 0.9; transform: translateY(-1px); }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }
    .progress-container { background: #e9ecef; border-radius: 10px; height: 25px; margin: 15px 0; overflow: hidden; }
    .progress-bar { height: 100%; background: linear-gradient(90deg, #28a745, #20c997); border-radius: 10px; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px; }
    .file-status { padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
    .status-new { background: #d1ecf1; color: #0c5460; }
    .status-processed { background: #d4edda; color: #155724; }
    .status-error { background: #f8d7da; color: #721c24; }
    .status-processing { background: #fff3cd; color: #856404; }
    .alert { padding: 15px; margin: 15px 0; border-radius: 8px; }
    .alert-info { background: #d1ecf1; color: #0c5460; border-left: 4px solid #17a2b8; }
    .alert-warning { background: #fff3cd; color: #856404; border-left: 4px solid #ffc107; }
    .checkbox-cell { text-align: center; width: 50px; }
    .file-actions { text-align: center; width: 120px; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    .hidden { display: none; }
</style>

<div class="container">
    <h2>📁 Historical Parser</h2>
    <p class="text-muted">Scansiona e importa report settlement storici dalle cartelle download</p>
    
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Selezione Utente -->
    <div class="scanner-section">
        <div class="user-selector">
            <h4>👤 Seleziona Utente</h4>
            <p>Scegli l'utente di cui vuoi scansionare i file storici</p>
            <select id="user-selector" class="form-control" style="width: 350px;" onchange="resetScanner()">
                <option value="">-- Seleziona Utente --</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>">
                        <?php echo htmlspecialchars($user['nome']); ?> (ID: <?php echo $user['id']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- Controlli Scanner -->
        <div id="scan-controls" class="scan-controls hidden">
            <button id="scan-btn" class="btn btn-primary" onclick="scanHistoricalFiles()">
                🔍 Scansiona File Storici
            </button>
            <button id="refresh-btn" class="btn btn-secondary" onclick="refreshFileList()" disabled>
    🔄 Aggiorna Lista
</button>
<button class="btn btn-warning" onclick="location.reload()">
    🔄 Ricarica Pagina
</button>
            <span id="scan-status" style="color: #666; font-style: italic;"></span>
        </div>
    </div>
    
    <!-- Statistiche -->
    <div id="stats-section" class="hidden">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" id="total-files">0</div>
                <div class="stat-label">File Trovati</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="new-files">0</div>
                <div class="stat-label">Nuovi da Processare</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="processed-files">0</div>
                <div class="stat-label">Già Processati</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="total-size">0 MB</div>
                <div class="stat-label">Dimensione Totale</div>
            </div>
        </div>
    </div>
    
    <!-- Tabella File -->
    <div id="files-section" class="hidden">
        <div class="file-table-container">
            <div style="padding: 15px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
                <h5 style="margin: 0;">📄 File Storici Trovati</h5>
                <div>
                    <button id="select-all-btn" class="btn btn-sm btn-secondary" onclick="toggleSelectAll()">
                        ☑️ Seleziona Tutti
                    </button>
                    <button id="process-selected-btn" class="btn btn-sm btn-success" onclick="processSelectedFiles()" disabled>
                        ⚡ Processa Selezionati
                    </button>
                    <button id="process-all-btn" class="btn btn-sm btn-warning" onclick="processAllNewFiles()" disabled>
                        🚀 Processa Tutti Nuovi
                    </button>
                </div>
            </div>
            
            <!-- Progress Bar -->
            <div id="progress-section" class="hidden" style="padding: 15px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span id="progress-text">Elaborazione in corso...</span>
                    <span id="progress-percent">0%</span>
                </div>
                <div class="progress-container">
                    <div id="progress-bar" class="progress-bar">0%</div>
                </div>
            </div>
            
            <table class="file-table" id="files-table">
                <thead>
                    <tr>
                        <th class="checkbox-cell">
                            <input type="checkbox" id="select-all-checkbox" onchange="toggleSelectAll()">
                        </th>
                        <th>Nome File</th>
                        <th>Dimensione</th>
                        <th>Data Modifica</th>
                        <th>Stato</th>
                        <th>Settlement ID</th>
                        <th class="file-actions">Azioni</th>
                    </tr>
                </thead>
                <tbody id="files-tbody">
                    <!-- Files will be populated via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Area Log Real-time -->
    <div class="scanner-section">
        <h4>📋 Log Operazioni</h4>
        <div id="log-area" class="log-area">
            <div style="color: #00ff00;">🟢 HISTORICAL PARSER READY</div>
            <div style="color: #888;">Seleziona un utente per iniziare la scansione...</div>
        </div>
        <div style="margin-top: 10px;">
            <button class="btn btn-sm btn-secondary" onclick="clearLog()">🗑️ Pulisci Log</button>
            <button class="btn btn-sm btn-secondary" onclick="scrollLogToBottom()">⬇️ Vai in Fondo</button>
        </div>
    </div>
</div>

<script>
// Global variables
let currentUserId = null;
let currentFiles = [];
let isProcessing = false;

// Reset scanner when user changes
function resetScanner() {
    const userId = document.getElementById('user-selector').value;
    currentUserId = userId;
    
    if (userId) {
        document.getElementById('scan-controls').classList.remove('hidden');
        addLog(`👤 Utente selezionato: ID ${userId}`, 'info');
    } else {
        document.getElementById('scan-controls').classList.add('hidden');
        document.getElementById('stats-section').classList.add('hidden');
        document.getElementById('files-section').classList.add('hidden');
        addLog('📋 Seleziona un utente per continuare', 'info');
    }
    
    // Reset UI
    currentFiles = [];
    updateFilesTable();
    updateStats();
}

// Scan historical files for selected user
function scanHistoricalFiles() {
    if (!currentUserId) {
        alert('⚠️ Seleziona prima un utente!');
        return;
    }
    
    const scanBtn = document.getElementById('scan-btn');
    const scanStatus = document.getElementById('scan-status');
    
    scanBtn.disabled = true;
    scanBtn.textContent = '🔄 Scansionando...';
    scanStatus.textContent = 'Analizzando cartelle...';
    
    addLog(`🔍 Avvio scansione file storici per User ID: ${currentUserId}`, 'info');
    
addLog(`🔄 Invio richiesta AJAX a: ${window.location.href}`, 'info');
addLog(`📤 Body: ajax=1&action=scan_historical&user_id=${currentUserId}`, 'info');

fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `ajax=1&action=scan_historical&user_id=${currentUserId}`
})
.then(response => {
    addLog(`📥 Response status: ${response.status} ${response.statusText}`, 'info');
    addLog(`📥 Response headers: ${JSON.stringify([...response.headers])}`, 'info');
    
    return response.text(); // Cambia in text() per vedere raw response
})
.then(text => {
    addLog(`📄 Raw response (primi 500 char): ${text.substring(0, 500)}`, 'info');
    
    try {
        const result = JSON.parse(text);
        addLog(`✅ JSON parsing OK`, 'success');
        
        if (result.success) {
            currentFiles = result.files;
            updateFilesTable();
            updateStats();
            
            document.getElementById('stats-section').classList.remove('hidden');
            document.getElementById('files-section').classList.remove('hidden');
            document.getElementById('refresh-btn').disabled = false;
            
            addLog(`✅ Scansione completata: ${result.files.length} file trovati`, 'success');
            addLog(`📊 Nuovi: ${result.stats.new_files}, Processati: ${result.stats.processed_files}`, 'info');
        } else {
            addLog(`❌ Errore scansione: ${result.error}`, 'error');
        }
    } catch (parseError) {
        addLog(`❌ Errore parsing JSON: ${parseError.message}`, 'error');
        addLog(`🔍 Response tipo: ${typeof text}`, 'error');
        addLog(`🔍 Response length: ${text.length}`, 'error');
        
        // Se response inizia con <, è HTML
        if (text.trim().startsWith('<')) {
            addLog(`⚠️ Response sembra HTML invece di JSON!`, 'error');
            addLog(`📄 HTML content: ${text.substring(0, 1000)}`, 'error');
        }
    }
})
.catch(error => {
    addLog(`❌ Errore fetch: ${error.message}`, 'error');
    addLog(`🔍 Error stack: ${error.stack}`, 'error');
})
    .finally(() => {
        scanBtn.disabled = false;
        scanBtn.textContent = '🔍 Scansiona File Storici';
        scanStatus.textContent = '';
    });
}

// Refresh file list
function refreshFileList() {
    addLog('🔄 Aggiornamento lista file...', 'info');
    scanHistoricalFiles();
}

// Update files table
function updateFilesTable() {
    const tbody = document.getElementById('files-tbody');
    tbody.innerHTML = '';
    
    if (currentFiles.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #666;">Nessun file trovato</td></tr>';
        return;
    }
    
    currentFiles.forEach((file, index) => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td class="checkbox-cell">
                <input type="checkbox" class="file-checkbox" value="${index}" 
                       onchange="updateSelectionButtons()" ${file.status !== 'new' ? 'disabled' : ''}>
            </td>
            <td>
                <strong>${file.name}</strong>
                <br><small style="color: #666;">${file.path}</small>
            </td>
            <td>${formatFileSize(file.size)}</td>
            <td>${formatDate(file.modified)}</td>
            <td>
                <span class="file-status status-${file.status}">
                    ${getStatusLabel(file.status)}
                </span>
            </td>
            <td>${file.settlement_id || 'N/A'}</td>
            <td class="file-actions">
                ${file.status === 'new' ? 
                    `<button class="btn btn-sm btn-primary" onclick="processSingleFile(${index})">
                        📥 Processa
                    </button>` : 
                    `<button class="btn btn-sm btn-secondary" disabled>
                        ✅ Fatto
                    </button>`
                }
            </td>
        `;
        tbody.appendChild(row);
    });
    
    updateSelectionButtons();
}

// Update statistics
function updateStats() {
    const stats = {
        total: currentFiles.length,
        new: currentFiles.filter(f => f.status === 'new').length,
        processed: currentFiles.filter(f => f.status === 'processed').length,
        totalSize: currentFiles.reduce((sum, f) => sum + f.size, 0)
    };
    
    document.getElementById('total-files').textContent = stats.total;
    document.getElementById('new-files').textContent = stats.new;
    document.getElementById('processed-files').textContent = stats.processed;
    document.getElementById('total-size').textContent = formatFileSize(stats.totalSize);
    
    // Update button states
    document.getElementById('process-all-btn').disabled = stats.new === 0 || isProcessing;
}

// Toggle select all checkboxes
function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('select-all-checkbox');
    const fileCheckboxes = document.querySelectorAll('.file-checkbox:not(:disabled)');
    
    fileCheckboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateSelectionButtons();
}

// Update selection buttons state
function updateSelectionButtons() {
    const selectedCount = document.querySelectorAll('.file-checkbox:checked').length;
    document.getElementById('process-selected-btn').disabled = selectedCount === 0 || isProcessing;
    
    const enabledCheckboxes = document.querySelectorAll('.file-checkbox:not(:disabled)');
    const checkedEnabledBoxes = document.querySelectorAll('.file-checkbox:not(:disabled):checked');
    document.getElementById('select-all-checkbox').checked = enabledCheckboxes.length > 0 && enabledCheckboxes.length === checkedEnabledBoxes.length;
}

// Process selected files
function processSelectedFiles() {
    const selectedIndexes = Array.from(document.querySelectorAll('.file-checkbox:checked'))
        .map(cb => parseInt(cb.value));
    
    if (selectedIndexes.length === 0) {
        alert('⚠️ Seleziona almeno un file!');
        return;
    }
    
    const selectedFiles = selectedIndexes.map(i => currentFiles[i]);
    processBatch(selectedFiles);
}

// Process all new files
function processAllNewFiles() {
    const newFiles = currentFiles.filter(f => f.status === 'new');
    
    if (newFiles.length === 0) {
        alert('ℹ️ Nessun file nuovo da processare!');
        return;
    }
    
    if (!confirm(`🚀 Processare tutti i ${newFiles.length} file nuovi?\n\nQuesta operazione può richiedere diversi minuti.`)) {
        return;
    }
    
    processBatch(newFiles);
}

// Process single file
function processSingleFile(index) {
    const file = currentFiles[index];
    
    if (file.status !== 'new') {
        alert('⚠️ Questo file è già stato processato!');
        return;
    }
    
    processBatch([file]);
}

// Process batch of files
function processBatch(files) {
    if (isProcessing) {
        alert('⚠️ Elaborazione già in corso!');
        return;
    }
    
    isProcessing = true;
    showProgress();
    updateProgress(0, `Preparazione elaborazione ${files.length} file...`);
    
    addLog(`🚀 Avvio elaborazione batch: ${files.length} file`, 'info');
    
    // Disable all action buttons
    document.querySelectorAll('button').forEach(btn => {
        if (!btn.id.includes('log')) btn.disabled = true;
    });
    
    // Process files sequentially
    processFilesSequentially(files, 0);
}

// Process files one by one
function processFilesSequentially(files, currentIndex) {
    if (currentIndex >= files.length) {
        // All done
        completeProcessing();
        return;
    }
    
    const file = files[currentIndex];
    const progress = ((currentIndex + 1) / files.length) * 100;
    
    updateProgress(progress, `Processando: ${file.name} (${currentIndex + 1}/${files.length})`);
    addLog(`📥 Processando: ${file.name}`, 'info');
    
fetch('', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: `ajax=1&action=process_file&user_id=${currentUserId}&file_path=${encodeURIComponent(file.path)}`
})
.then(response => {
    addLog(`📥 Process response status: ${response.status}`, 'info');
    addLog(`📥 Process headers: ${JSON.stringify([...response.headers])}`, 'info');
    return response.text(); // Cambia in text() per debug
})
.then(text => {
    addLog(`📄 Process raw (primi 200): ${text.substring(0, 200)}`, 'info');
    
try {
    const result = JSON.parse(text);
    
    if (result && result.success) {
        addLog(`✅ ${file.name}: ${result.processed || 0} righe processate`, 'success');
        
        // Update file status in current list
        const fileIndex = currentFiles.findIndex(f => f.path === file.path);
        if (fileIndex !== -1) {
            currentFiles[fileIndex].status = 'processed';
        }
    } else {
        addLog(`❌ ${file.name}: ${result?.error || 'Errore sconosciuto'}`, 'error');
    }
} catch (parseError) {
    addLog(`❌ ${file.name}: JSON parse error - ${parseError.message}`, 'error');
    addLog(`📄 Raw response era: ${text}`, 'error');
}
})
    
    .catch(error => {
        addLog(`❌ ${file.name}: Errore connessione - ${error.message}`, 'error');
    })
    .finally(() => {
        // Process next file
        setTimeout(() => {
            processFilesSequentially(files, currentIndex + 1);
        }, 500); // Small delay between files
    });
}

// Complete processing
function completeProcessing() {
    updateProgress(100, 'Elaborazione completata!');
    addLog('🎉 Elaborazione batch completata!', 'success');
    
    setTimeout(() => {
        hideProgress();
        isProcessing = false;
        
        // Re-enable buttons
        document.querySelectorAll('button').forEach(btn => btn.disabled = false);
        
 // NON aggiornare automaticamente - mantieni stato attuale
addLog('🔄 Elaborazione completata - ricarica pagina per aggiornamento completo', 'info');
    }, 2000);
}

// Show/hide progress bar
function showProgress() {
    document.getElementById('progress-section').classList.remove('hidden');
}

function hideProgress() {
    document.getElementById('progress-section').classList.add('hidden');
}

// Update progress bar
function updateProgress(percent, text) {
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const progressPercent = document.getElementById('progress-percent');
    
    progressBar.style.width = percent + '%';
    progressBar.textContent = Math.round(percent) + '%';
    progressText.textContent = text;
    progressPercent.textContent = Math.round(percent) + '%';
}

// Add log entry
function addLog(message, type = 'info') {
    const logArea = document.getElementById('log-area');
    const timestamp = new Date().toLocaleTimeString();
    const icon = type === 'error' ? '🔴' : type === 'success' ? '🟢' : type === 'warning' ? '🟡' : '🔵';
    const color = type === 'error' ? '#ff6b6b' : type === 'success' ? '#51cf66' : type === 'warning' ? '#ffd43b' : '#74c0fc';
    
    const logEntry = document.createElement('div');
    logEntry.innerHTML = `<span style="color: #888;">[${timestamp}]</span> <span style="color: ${color};">${icon}</span> ${message}`;
    logArea.appendChild(logEntry);
    
    scrollLogToBottom();
}

// Clear log
function clearLog() {
    const logArea = document.getElementById('log-area');
    logArea.innerHTML = '<div style="color: #00ff00;">🟢 HISTORICAL PARSER READY</div>';
}

// Scroll log to bottom
function scrollLogToBottom() {
    const logArea = document.getElementById('log-area');
    logArea.scrollTop = logArea.scrollHeight;
}

// Utility functions
function formatFileSize(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatDate(timestamp) {
    return new Date(timestamp * 1000).toLocaleString('it-IT');
}

function getStatusLabel(status) {
    switch(status) {
        case 'new': return 'Nuovo';
        case 'processed': return 'Processato';
        case 'error': return 'Errore';
        case 'processing': return 'In corso';
        default: return 'Sconosciuto';
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    addLog('🚀 Historical Parser inizializzato', 'success');
});
</script>

<?php echo getAdminFooter(); ?>