<?php
/**
 * Inbound Sync Runner - Esecuzione Web Sincronizzazione
 * File: modules/inbound/inbound_sync.php
 * 
 * Features:
 * - Form configurazione sync (dry_run, max_shipments, from_date)
 * - Output real-time stile terminale
 * - Progress callback live
 * - Summary finale con statistiche
 * - Solo ADMIN access
 * 
 * @version 2.0
 * @date 2025-10-17
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';
require_once __DIR__ . '/../margynomic/admin/admin_helpers.php';

// ============================================
// AUTHENTICATION: SOLO ADMIN
// ============================================
$isAdmin = isAdminLogged();

if (!$isAdmin) {
    header('Location: ../margynomic/admin/admin_login.php');
    exit;
}

$db = getDbConnection();

// ============================================
// USER SELECTION (con selettore)
// ============================================
$selectedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : ($_SESSION['inbound_selected_user'] ?? 2);
$_SESSION['inbound_selected_user'] = $selectedUserId;

$userId = $selectedUserId;

// Get user info
$stmt = $db->prepare("SELECT id, nome, email FROM users WHERE id = ?");
$stmt->execute([$selectedUserId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $user = ['id' => $selectedUserId, 'nome' => 'Unknown', 'email' => 'N/A'];
}

// Get available users (con token Amazon attivo)
try {
    $availableUsers = $db->query("
        SELECT DISTINCT u.id, u.nome, u.email 
        FROM users u
        INNER JOIN amazon_client_tokens t ON t.user_id = u.id
        WHERE u.is_active = 1 AND t.is_active = 1
        ORDER BY u.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $availableUsers = [$user]; // Fallback: solo utente corrente
}

// ============================================
// EXECUTION MODE
// ============================================
$isRunning = isset($_POST['action']) && $_POST['action'] === 'run';
$dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
$maxShipments = isset($_POST['max_shipments']) ? (int)$_POST['max_shipments'] : 1000;
$apiCallsLimit = isset($_POST['api_calls_limit']) ? (int)$_POST['api_calls_limit'] : 100;
$fromDate = isset($_POST['from_date']) && !empty($_POST['from_date']) ? $_POST['from_date'] : null;
$syncMode = isset($_POST['sync_mode']) ? $_POST['sync_mode'] : 'incremental';
$windowDays = isset($_POST['window_days']) ? (int)$_POST['window_days'] : 7;  // ✅ Default: 7 giorni (rolling window)
$minDate = isset($_POST['min_date']) && !empty($_POST['min_date']) ? $_POST['min_date'] : '2010-01-01';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbound Sync - Esegui Sincronizzazione</title>
    <link rel="stylesheet" href="inbound.css">
    <style>
        .terminal {
            background: #1a1a1a;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            padding: 1.5rem;
            border-radius: var(--radius-md);
            max-height: 600px;
            overflow-y: auto;
            margin: 1.5rem 0;
            box-shadow: var(--shadow-lg);
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        .terminal-line {
            margin: 0.25rem 0;
        }
        .terminal-line.error {
            color: #ff5555;
        }
        .terminal-line.warning {
            color: #ffb86c;
        }
        .terminal-line.success {
            color: #50fa7b;
        }
        .terminal-line.info {
            color: #8be9fd;
        }
        .summary-box {
            background: var(--bg-card);
            padding: 1.5rem;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            margin: 1.5rem 0;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .summary-item {
            text-align: center;
        }
        .summary-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }
        .summary-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>

<?php echo getAdminNavigation('inbound'); ?>

    <div class="container">
        <!-- Header -->
        <div class="inbound-header">
            <div class="header-content">
                <div class="header-title">
                    🔄 Sincronizzazione Inbound
                </div>
            </div>
        </div>

        <!-- User Selector -->
        <div class="card">
            <div class="card-header">👤 Seleziona Utente</div>
            <div class="card-body">
                <form method="GET" style="display: flex; gap: 1rem; align-items: end;">
                    <div class="form-group" style="flex: 1; margin: 0;">
                        <label for="user_id">Utente da sincronizzare:</label>
                        <select name="user_id" id="user_id" onchange="this.form.submit()">
                            <?php foreach ($availableUsers as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['nome']) ?> (ID: <?= $u['id'] ?>) - <?= htmlspecialchars($u['email']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!$isRunning): ?>
        <!-- Configuration Form -->
        <div class="card">
            <div class="card-header">⚙️ Configurazione Sincronizzazione</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="run">

                    <div class="form-group">
                        <label><strong>🔄 Modalità Sincronizzazione:</strong></label>
                        <div style="margin-top: 0.5rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem; cursor: pointer;">
                                <input type="radio" name="sync_mode" value="incremental" checked onchange="toggleSyncMode(this.value)">
                                <div>
                                    <strong>Sync Incrementale</strong> (veloce, solo nuove/modificate)<br>
                                    <span style="font-size: 0.875rem; color: var(--text-muted);">Usa LastUpdatedAfter per sync regolare</span>
                                </div>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="radio" name="sync_mode" value="historical" onchange="toggleSyncMode(this.value)">
                                <div>
                                    <strong>Sweep Storico per Stato</strong> (scan completo per stato, finestre fisse)<br>
                                    <span style="font-size: 0.875rem; color: var(--text-muted);">Partiziona per status + finestre 30gg. Niente early-exit, scan fino a min_date.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" id="dry_run" name="dry_run" value="1">
                            <label for="dry_run">
                                <strong>🧪 Dry Run</strong> - Simula senza scrivere nel database (solo preview)
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="max_shipments">📦 Max Spedizioni per Run:</label>
                        <input type="number" id="max_shipments" name="max_shipments" value="1000" min="1" max="10000">
                        <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                            Limite di sicurezza per evitare timeout. Default: 1000
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="api_calls_limit">🔢 Max API Calls per Run:</label>
                        <select id="api_calls_limit" name="api_calls_limit">
                            <option value="50">50 (test veloce)</option>
                            <option value="100" selected>100 (standard)</option>
                            <option value="200">200 (medio)</option>
                            <option value="300">300 (alto)</option>
                            <option value="500">500 (massimo, sweep veloce) ⚡</option>
                        </select>
                        <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                            Più alto = meno run necessarie. 500 = sweep completo in 1-2 run invece di 4-5.
                        </small>
                    </div>

                    <!-- Incremental Mode Fields -->
                    <div id="incremental-fields">
                        <div class="form-group">
                            <label for="window_days_incremental">🪟 Finestra Rolling (giorni):</label>
                            <select id="window_days_incremental" name="window_days">
                                <option value="3">3 giorni (ultra-frequente)</option>
                                <option value="7" selected>7 giorni (consigliato per cron giornaliero)</option>
                                <option value="14">14 giorni</option>
                                <option value="30">30 giorni</option>
                            </select>
                            <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                                ✅ <strong>Rolling Window:</strong> scarica sempre gli ultimi N giorni.<br>
                                Se nessuna modifica → tutte skippate (header fingerprint).<br>
                                💰 <strong>Costo minimo:</strong> 1-5 API calls se nessuna modifica.
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="from_date">📅 Override Data Inizio (opzionale):</label>
                            <input type="date" id="from_date" name="from_date" value="">
                            <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                                ⚠️ Solo per test/debug. Lascia vuoto per rolling window automatico.
                            </small>
                        </div>
                    </div>

                    <!-- Historical Mode Fields -->
                    <div id="historical-fields" style="display: none;">
                        <div class="form-group">
                            <label><strong>📦 Stati da Processare:</strong></label>
                            <div style="margin-top: 0.5rem; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0.5rem;">
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="statuses[]" value="CLOSED" checked>
                                    CLOSED
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="statuses[]" value="RECEIVING" checked>
                                    RECEIVING
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="statuses[]" value="SHIPPED" checked>
                                    SHIPPED
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="statuses[]" value="WORKING" checked>
                                    WORKING
                                </label>
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                    <input type="checkbox" name="statuses[]" value="CANCELLED" checked>
                                    CANCELLED
                                </label>
                            </div>
                            <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                                Ordine di processamento: CLOSED → RECEIVING → SHIPPED → WORKING → CANCELLED
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="window_days">🪟 Dimensione Finestra (giorni):</label>
                            <select id="window_days" name="window_days">
                                <option value="21">21 giorni (veloce, datasets densi)</option>
                                <option value="30" selected>30 giorni (consigliato)</option>
                                <option value="60">60 giorni</option>
                                <option value="90">90 giorni</option>
                            </select>
                            <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                                Finestre più strette evitano loop API Amazon. 30 giorni = equilibrio ottimale.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="min_date">📅 Data Minima (stop):</label>
                            <input type="date" id="min_date" name="min_date" value="2010-01-01">
                            <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                                Il sweep continua sempre fino a questa data (niente early-exit su finestre vuote).
                            </small>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-group">
                                <input type="checkbox" id="resume" name="resume" value="1" checked>
                                <label for="resume">
                                    <strong>🔄 Resume da cursori salvati</strong>
                                </label>
                            </div>
                            <small style="color: var(--text-muted); display: block; margin-top: 0.5rem;">
                                Se attivo, riprende da dove si era fermato (per stato). Disattiva per ricominciare da zero.
                            </small>
                        </div>

                        <div class="alert alert-info" style="margin-top: 1rem;">
                            <span class="alert-icon">ℹ️</span>
                            <div>
                                <strong>Sweep Storico Deterministico:</strong><br>
                                Partiziona per stato → finestre fisse da 30gg → scan fino a min_date.<br>
                                Niente salti, niente early-exit. Robusto contro bug paginazione Amazon v0.<br>
                                Budget: 100 API calls/run. Cursori persistenti permettono resume tra run.
                            </div>
                        </div>
                    </div>

                    <script>
                        function toggleSyncMode(mode) {
                            document.getElementById('incremental-fields').style.display = mode === 'incremental' ? 'block' : 'none';
                            document.getElementById('historical-fields').style.display = mode === 'historical' ? 'block' : 'none';
                        }
                    </script>

                    <div style="margin-top: 1.5rem;">
                        <button type="submit" class="btn btn-primary btn-lg">
                            🚀 Avvia Sincronizzazione
                        </button>
                        <a href="inbound.php?user_id=<?= $userId ?>" class="btn btn-outline">
                            ← Annulla
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <!-- Execution Output -->
        <div class="card">
            <div class="card-header">
                🔄 Sincronizzazione in Corso...
                <?php if ($dryRun): ?>
                    <span class="badge badge-working">DRY RUN</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="terminal" id="terminal">
                    <?php
                    // Flush output immediately
                    if (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    
                    // Set execution limits
                    set_time_limit(1800); // 30 minuti
                    ini_set('memory_limit', '512M');
                    
                    // Output function
                    function outputLine($message, $type = 'info') {
                        $timestamp = date('H:i:s');
                        $class = "terminal-line {$type}";
                        echo "<div class='{$class}'>[{$timestamp}] {$message}</div>";
                        flush();
                    }
                    
                    try {
                        require_once __DIR__ . '/inbound_core.php';
                        
                        outputLine("═══════════════════════════════════════════════════════", 'info');
                        outputLine("INBOUND SYNC - START", 'success');
                        outputLine("═══════════════════════════════════════════════════════", 'info');
                        outputLine("User ID: {$userId}", 'info');
                        outputLine("User: {$user['nome']}", 'info');
                        outputLine("Sync Mode: " . strtoupper($syncMode), 'info');
                        outputLine("Dry Run: " . ($dryRun ? 'YES' : 'NO'), 'info');
                        outputLine("Max Shipments: {$maxShipments}", 'info');
                        if ($syncMode === 'incremental' && $fromDate) {
                            outputLine("From Date: {$fromDate}", 'info');
                        } elseif ($syncMode === 'historical') {
                            outputLine("Window Size: {$windowDays} days", 'info');
                            outputLine("Min Date: {$minDate}", 'info');
                        }
                        outputLine("═══════════════════════════════════════════════════════", 'info');
                        outputLine("");
                        
                        // Create InboundCore instance
                        $core = new InboundCore($userId, [
                            'dry_run' => $dryRun,
                            'api_calls_limit' => $apiCallsLimit,
                            'run_timeout' => 1800,
                            'shipments_limit' => $maxShipments
                        ]);
                        
                        // Set progress callback
                        $core->setProgressCallback(function($msg) {
                            outputLine($msg);
                        });
                        
                        // Check circuit breaker
                        if ($core->isCircuitOpen()) {
                            outputLine("⚠️  Circuit Breaker OPEN - Sync sospeso", 'error');
                            outputLine("Troppi errori consecutivi. Riprova tra qualche ora.", 'error');
                            throw new Exception("Circuit breaker open");
                        }
                        
                        // Acquire lock
                        outputLine("Acquiring lock...");
                        if (!$core->acquireLock()) {
                            outputLine("⚠️  Lock failed - Sync già in corso per questo utente", 'error');
                            throw new Exception("Lock acquisition failed");
                        }
                        outputLine("✓ Lock acquired", 'success');
                        outputLine("");
                        
                        // Run sync (choose mode)
                        if ($syncMode === 'historical') {
                            // Historical Sweep per Stato
                            $statuses = isset($_POST['statuses']) && is_array($_POST['statuses']) 
                                ? $_POST['statuses'] 
                                : ['CLOSED', 'RECEIVING', 'SHIPPED', 'WORKING', 'CANCELLED'];
                            
                            $resume = isset($_POST['resume']) && $_POST['resume'] === '1';
                            
                            $options = [
                                'window_days' => $windowDays,
                                'min_date' => $minDate . 'T00:00:00Z',
                                'statuses' => $statuses,
                                'resume' => $resume
                            ];
                            $summary = $core->syncHistoricalFull($options);
                        } else {
                            // ✅ Incremental Sync con rolling window
                            $options = [
                                'window_days' => $windowDays
                            ];
                            
                            // Override manuale se specificato (per test/debug)
                            if ($fromDate) {
                                $options['from_date'] = $fromDate . 'T00:00:00Z';
                            }
                            
                            $summary = $core->syncIncremental($options);
                        }
                        
                        // Release lock
                        $core->releaseLock();
                        outputLine("✓ Lock released", 'success');
                        outputLine("");
                        
                        // Output summary
                        outputLine("═══════════════════════════════════════════════════════", 'info');
                        outputLine("SYNC COMPLETED", 'success');
                        outputLine("═══════════════════════════════════════════════════════", 'info');
                        outputLine("Shipments Synced: {$summary['synced']}", 'success');
                        outputLine("Shipments Skipped: {$summary['skipped']}", 'info');
                        outputLine("Shipments Partial: {$summary['partial']}", $summary['partial'] > 0 ? 'warning' : 'info');
                        outputLine("Errors: {$summary['errors']}", $summary['errors'] > 0 ? 'error' : 'success');
                        
                        // Historical mode extra metrics
                        if ($syncMode === 'historical') {
                            outputLine("Windows Processed: {$summary['windows_processed']}", 'info');
                            outputLine("Windows Empty: {$summary['windows_empty']}", 'info');
                            
                            // Check se sweep storico è completato
                            $stmt = $db->prepare("
                                SELECT current_status, historic_cursors 
                                FROM sync_state 
                                WHERE user_id = ?
                            ");
                            $stmt->execute([$userId]);
                            $sweepState = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($sweepState && $sweepState['current_status'] === null) {
                                outputLine("", 'info');
                                outputLine("🎉 SWEEP STORICO COMPLETATO! 🎉", 'success');
                                outputLine("Tutti gli stati sono stati processati fino a min_date.", 'success');
                                outputLine("Puoi ora usare solo Sync Incrementale per aggiornamenti futuri.", 'info');
                            } else {
                                $currentStatus = $sweepState['current_status'] ?? 'UNKNOWN';
                                outputLine("", 'info');
                                outputLine("⏸️  Sweep in pausa: stato corrente = {$currentStatus}", 'warning');
                                outputLine("Rilancia per continuare con stati rimanenti.", 'info');
                            }
                        }
                        
                        // Early-exit metrics (incremental mode)
                        if (isset($summary['early_exit']) && $summary['early_exit']) {
                            outputLine("Early Exit: YES (saved {$summary['pages_wasted']} pages)", 'success');
                        }
                        
                        outputLine("API Calls: {$summary['api_calls']}", 'info');
                        outputLine("Duration: {$summary['duration']}s", 'info');
                        outputLine("═══════════════════════════════════════════════════════", 'info');
                        
                        if ($dryRun) {
                            outputLine("");
                            outputLine("⚠️  DRY RUN: Nessuna modifica scritta nel database", 'warning');
                        }
                        
                        // Store summary for display
                        $finalSummary = $summary;
                        
                    } catch (Exception $e) {
                        outputLine("");
                        outputLine("═══════════════════════════════════════════════════════", 'error');
                        outputLine("ERROR: " . $e->getMessage(), 'error');
                        outputLine("═══════════════════════════════════════════════════════", 'error');
                        
                        // Try to release lock on error
                        if (isset($core)) {
                            try {
                                $core->releaseLock();
                                outputLine("Lock released (cleanup)", 'info');
                            } catch (Exception $lockErr) {
                                // Ignore
                            }
                        }
                        
                        $finalSummary = [
                            'synced' => 0,
                            'skipped' => 0,
                            'partial' => 0,
                            'errors' => 1,
                            'api_calls' => 0,
                            'duration' => 0
                        ];
                    }
                    ?>
                </div>

                <?php if (isset($finalSummary)): ?>
                <!-- Summary Box -->
                <div class="summary-box">
                    <h3 style="margin-bottom: 1rem;">📊 Summary</h3>
                    <div class="summary-grid">
                        <div class="summary-item">
                            <div class="summary-value" style="color: var(--success);">
                                <?= $finalSummary['synced'] ?? 0 ?>
                            </div>
                            <div class="summary-label">Synced</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" style="color: var(--text-muted);">
                                <?= $finalSummary['skipped'] ?? 0 ?>
                            </div>
                            <div class="summary-label">Skipped</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" style="color: var(--warning);">
                                <?= $finalSummary['partial'] ?? 0 ?>
                            </div>
                            <div class="summary-label">Partial</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" style="color: <?= ($finalSummary['errors'] ?? 0) > 0 ? 'var(--danger)' : 'var(--success)' ?>;">
                                <?= $finalSummary['errors'] ?? 0 ?>
                            </div>
                            <div class="summary-label">Errors</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" style="color: var(--info);">
                                <?= $finalSummary['api_calls'] ?? 0 ?>
                            </div>
                            <div class="summary-label">API Calls</div>
                        </div>
                        <div class="summary-item">
                            <div class="summary-value" style="color: var(--primary);">
                                <?= round($finalSummary['duration'] ?? 0, 1) ?>s
                            </div>
                            <div class="summary-label">Duration</div>
                        </div>
                    </div>

                    <?php if ($dryRun): ?>
                    <div class="alert alert-warning" style="margin-top: 1.5rem;">
                        <span class="alert-icon">🧪</span>
                        <div>
                            <strong>Dry Run Completato</strong><br>
                            Questa era una simulazione. Nessun dato è stato scritto nel database.
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="btn-group">
                    <a href="inbound_sync.php?user_id=<?= $userId ?>" class="btn btn-primary">
                        🔄 Nuova Sincronizzazione
                    </a>
                    <a href="inbound_views.php?view=shipments&user_id=<?= $userId ?>" class="btn btn-secondary">
                        📦 Vedi Spedizioni
                    </a>
                    <a href="inbound.php?user_id=<?= $userId ?>" class="btn btn-outline">
                        ← Dashboard
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

