<?php
/**
 * Backfill Fingerprints - Interfaccia Web
 * 
 * Popola fingerprint da DB esistente via browser
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
// HELPER FUNCTIONS (Inline)
// ============================================
function headerFingerprint(array $shipmentData): string {
    $fields = [
        trim((string)($shipmentData['shipment_status'] ?? '')),
        trim((string)($shipmentData['destination_fc'] ?? '')),
        trim((string)($shipmentData['label_prep_type'] ?? '')),
        (int)($shipmentData['are_cases_required'] ?? 0),
        trim((string)($shipmentData['box_contents_source'] ?? '')),
        trim((string)($shipmentData['shipment_name'] ?? ''))
    ];
    return hash('sha256', implode('|', $fields));
}

function normalizeItemsForHash(array $items): string {
    if (empty($items)) {
        return '';
    }
    
    foreach ($items as &$it) {
        $it['_k'] = mb_strtolower(trim($it['seller_sku'] ?? ''), 'UTF-8');
    }
    
    usort($items, function($a, $b) {
        return strcmp($a['_k'], $b['_k']);
    });
    
    $buffer = '';
    foreach ($items as $it) {
        $sku = trim((string)($it['seller_sku'] ?? ''));
        $sku = preg_replace('/[[:cntrl:]]+/', '', $sku);
        $qtyShipped = (int)($it['quantity_shipped'] ?? 0);
        $qtyReceived = (int)($it['quantity_received'] ?? 0);
        $buffer .= "{$sku}|{$qtyShipped}|{$qtyReceived};";
    }
    
    return $buffer;
}

function itemsFingerprint(array $items): string {
    $normalized = normalizeItemsForHash($items);
    return hash('sha256', $normalized);
}

// ============================================
// EXECUTION MODE
// ============================================
$executing = isset($_POST['execute']) && $_POST['execute'] === '1';

if ($executing) {
    // Parametri POST
    $userArg = $_POST['user_id'] ?? 'all';
    $limit = (int)($_POST['limit'] ?? 1000);
    $offset = (int)($_POST['offset'] ?? 0);
    $dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] === '1';
    
    // Output buffer per mostrare progressi
    ob_implicit_flush(true);
    ob_end_flush();
    
    // Header per output progressivo
    header('Content-Type: text/html; charset=utf-8');
    header('X-Accel-Buffering: no'); // Disable nginx buffering
    
    echo '<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backfill Fingerprints - Esecuzione</title>
    <link rel="stylesheet" href="inbound.css">
    <style>
        .terminal {
            background: #1e1e1e;
            color: #00ff00;
            font-family: "Courier New", monospace;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1rem 0;
            max-height: 600px;
            overflow-y: auto;
            font-size: 14px;
            line-height: 1.6;
        }
        .terminal-line { margin: 0.2rem 0; }
        .terminal-header { color: #ffcc00; font-weight: bold; }
        .terminal-success { color: #00ff00; }
        .terminal-info { color: #00ccff; }
        .terminal-warning { color: #ffaa00; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Backfill Fingerprints - Esecuzione</h1>
            <a href="inbound.php" class="btn btn-secondary">← Dashboard</a>
        </div>
        
        <div class="card">
            <div class="card-header">📊 Configurazione</div>
            <div class="card-body">
                <p><strong>Utente:</strong> ' . htmlspecialchars($userArg) . '</p>
                <p><strong>Limit:</strong> ' . $limit . '</p>
                <p><strong>Offset:</strong> ' . $offset . '</p>
                <p><strong>Dry Run:</strong> ' . ($dryRun ? '<span class="badge badge-warning">SÌ (Simulazione)</span>' : '<span class="badge badge-success">NO (Scrittura Reale)</span>') . '</p>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">💻 Output</div>
            <div class="card-body">
                <div class="terminal" id="terminal">';
    
    flush();
    
    function logTerminal($message, $type = 'info') {
        $class = 'terminal-' . $type;
        echo '<div class="terminal-line ' . $class . '">' . htmlspecialchars($message) . '</div>';
        flush();
    }
    
    // START BACKFILL
    logTerminal('═══════════════════════════════════════════════════════', 'header');
    logTerminal('BACKFILL FINGERPRINTS - START', 'header');
    logTerminal('═══════════════════════════════════════════════════════', 'header');
    logTerminal('Time: ' . date('Y-m-d H:i:s'), 'info');
    logTerminal('');
    
    try {
        // Query shipments
        $userWhere = ($userArg === 'all') ? '' : 'AND s.user_id = :uid';
        
        $sql = "
            SELECT 
                s.id AS shipment_id, 
                s.user_id,
                s.shipment_status, 
                s.destination_fc, 
                s.label_prep_type,
                s.are_cases_required, 
                s.box_contents_source, 
                s.shipment_name
            FROM inbound_shipments s
            WHERE 1=1 {$userWhere}
            ORDER BY s.id
            LIMIT :lim OFFSET :off
        ";
        
        $stmt = $db->prepare($sql);
        if ($userWhere) {
            $stmt->bindValue(':uid', (int)$userArg, PDO::PARAM_INT);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($rows)) {
            logTerminal('⚠️  No shipments found.', 'warning');
            logTerminal('');
        } else {
            logTerminal('✓ Found ' . count($rows) . ' shipments to process.', 'success');
            logTerminal('');
            
            $processed = 0;
            $withItems = 0;
            $withoutItems = 0;
            
            foreach ($rows as $r) {
                // Calcola header fingerprint
                $headerFp = headerFingerprint([
                    'shipment_status' => $r['shipment_status'],
                    'destination_fc' => $r['destination_fc'],
                    'label_prep_type' => $r['label_prep_type'],
                    'are_cases_required' => $r['are_cases_required'],
                    'box_contents_source' => $r['box_contents_source'],
                    'shipment_name' => $r['shipment_name']
                ]);
                
                // Leggi items dal DB
                $stmtItems = $db->prepare("
                    SELECT seller_sku, quantity_shipped, quantity_received 
                    FROM inbound_shipment_items 
                    WHERE shipment_id = ?
                ");
                $stmtItems->execute([$r['shipment_id']]);
                $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                
                $itemsFp = itemsFingerprint($items);
                
                if (!empty($items)) {
                    $withItems++;
                } else {
                    $withoutItems++;
                }
                
                if (!$dryRun) {
                    // Upsert shipment_sync_state
                    $stmtUpdate = $db->prepare("
                        INSERT INTO shipment_sync_state 
                        (shipment_id, user_id, shipment_fingerprint, items_fingerprint, internal_updated_at, sync_status)
                        VALUES (?, ?, ?, ?, COALESCE((SELECT internal_updated_at FROM (SELECT * FROM shipment_sync_state) AS ss WHERE ss.shipment_id = ?), NOW()), 'complete')
                        ON DUPLICATE KEY UPDATE
                            shipment_fingerprint = VALUES(shipment_fingerprint),
                            items_fingerprint = VALUES(items_fingerprint),
                            internal_updated_at = COALESCE(shipment_sync_state.internal_updated_at, VALUES(internal_updated_at))
                    ");
                    
                    $stmtUpdate->execute([
                        $r['shipment_id'],
                        $r['user_id'],
                        $headerFp,
                        $itemsFp,
                        $r['shipment_id']
                    ]);
                }
                
                $processed++;
                
                // Progress ogni 50 (più frequente per web)
                if ($processed % 50 === 0) {
                    logTerminal("⏳ Processed: {$processed}/{" . count($rows) . "} (with items: {$withItems}, without: {$withoutItems})", 'info');
                    flush();
                }
            }
            
            logTerminal('');
            logTerminal('═══════════════════════════════════════════════════════', 'header');
            logTerminal('BACKFILL COMPLETED', 'header');
            logTerminal('═══════════════════════════════════════════════════════', 'header');
            logTerminal('✓ Total processed: ' . $processed, 'success');
            logTerminal('✓ With items: ' . $withItems, 'success');
            logTerminal('✓ Without items: ' . $withoutItems, 'success');
            logTerminal('Mode: ' . ($dryRun ? 'DRY RUN (no changes written)' : 'REAL RUN (changes committed)'), $dryRun ? 'warning' : 'success');
            logTerminal('Duration: ' . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) . 's', 'info');
            logTerminal('═══════════════════════════════════════════════════════', 'header');
        }
        
    } catch (Exception $e) {
        logTerminal('');
        logTerminal('❌ ERROR: ' . $e->getMessage(), 'warning');
        logTerminal('');
    }
    
    echo '
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-body" style="text-align: center;">
                <a href="backfill_fingerprints_web.php" class="btn btn-primary">← Nuova Esecuzione</a>
                <a href="inbound.php" class="btn btn-secondary">Dashboard</a>
                <a href="inbound_sync.php" class="btn btn-success">Test Sync</a>
            </div>
        </div>
    </div>
</body>
</html>';
    
    exit;
}

// ============================================
// GET USERS (for dropdown)
// ============================================
$availableUsers = $db->query("
    SELECT DISTINCT u.id, u.nome, u.email 
    FROM users u
    INNER JOIN amazon_client_tokens t ON t.user_id = u.id
    WHERE u.is_active = 1 AND t.is_active = 1
    ORDER BY u.nome
")->fetchAll(PDO::FETCH_ASSOC);

// Count shipments
$totalShipments = $db->query("SELECT COUNT(*) FROM inbound_shipments")->fetchColumn();
$shipmentsWithFingerprints = $db->query("
    SELECT COUNT(*) 
    FROM shipment_sync_state 
    WHERE shipment_fingerprint IS NOT NULL 
      AND items_fingerprint IS NOT NULL
")->fetchColumn();

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backfill Fingerprints</title>
    <link rel="stylesheet" href="inbound.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Backfill Fingerprints</h1>
            <a href="inbound.php" class="btn btn-secondary">← Dashboard</a>
        </div>

        <!-- Status Card -->
        <div class="card">
            <div class="card-header">📊 Stato Attuale</div>
            <div class="card-body">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <div>
                        <strong>Spedizioni Totali:</strong><br>
                        <span style="font-size: 1.5rem; color: var(--primary);"><?= number_format($totalShipments) ?></span>
                    </div>
                    <div>
                        <strong>Con Fingerprint:</strong><br>
                        <span style="font-size: 1.5rem; color: var(--success);"><?= number_format($shipmentsWithFingerprints) ?></span>
                    </div>
                    <div>
                        <strong>Mancanti:</strong><br>
                        <span style="font-size: 1.5rem; color: <?= ($totalShipments - $shipmentsWithFingerprints) > 0 ? 'var(--warning)' : 'var(--success)' ?>;">
                            <?= number_format($totalShipments - $shipmentsWithFingerprints) ?>
                        </span>
                    </div>
                    <div>
                        <strong>Completamento:</strong><br>
                        <span style="font-size: 1.5rem; color: var(--primary);">
                            <?= $totalShipments > 0 ? round(($shipmentsWithFingerprints / $totalShipments) * 100, 1) : 0 ?>%
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($totalShipments - $shipmentsWithFingerprints > 0): ?>
        <div class="alert alert-warning">
            <span class="alert-icon">⚠️</span>
            <div>
                <strong>Azione Richiesta</strong><br>
                Ci sono <strong><?= number_format($totalShipments - $shipmentsWithFingerprints) ?></strong> spedizioni senza fingerprint. 
                Esegui il backfill per abilitare la strategia di skip intelligente.
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <div>
                <strong>Sistema Pronto</strong><br>
                Tutte le spedizioni hanno fingerprint. La strategia di skip intelligente è attiva!
            </div>
        </div>
        <?php endif; ?>

        <!-- Configuration Form -->
        <form method="POST" action="">
            <input type="hidden" name="execute" value="1">
            
            <div class="card">
                <div class="card-header">⚙️ Configurazione Backfill</div>
                <div class="card-body">
                    
                    <div class="form-group">
                        <label for="user_id">
                            <strong>Utente:</strong>
                            <span class="text-muted">(Filtra per utente specifico o processa tutti)</span>
                        </label>
                        <select name="user_id" id="user_id" style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 4px;">
                            <option value="all">Tutti gli utenti (<?= count($availableUsers) ?>)</option>
                            <?php foreach ($availableUsers as $user): ?>
                            <option value="<?= $user['id'] ?>">
                                User #<?= $user['id'] ?> - <?= htmlspecialchars($user['nome']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="limit">
                            <strong>Limit:</strong>
                            <span class="text-muted">(Max spedizioni per run - usa batch per grandi volumi)</span>
                        </label>
                        <input type="number" name="limit" id="limit" value="1000" min="1" max="10000" 
                               style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 4px;">
                    </div>

                    <div class="form-group">
                        <label for="offset">
                            <strong>Offset:</strong>
                            <span class="text-muted">(Salta le prime N spedizioni - utile per batch progressivi)</span>
                        </label>
                        <input type="number" name="offset" id="offset" value="0" min="0" 
                               style="width: 100%; padding: 0.5rem; border: 1px solid var(--border); border-radius: 4px;">
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" name="dry_run" value="1" id="dry_run" checked>
                            <strong>Dry Run (Simulazione)</strong>
                            <span class="text-muted">- Calcola fingerprint ma NON scrive nel DB</span>
                        </label>
                    </div>

                    <div class="alert alert-info" style="margin-top: 1rem;">
                        <span class="alert-icon">ℹ️</span>
                        <div>
                            <strong>Suggerimento:</strong><br>
                            1. Esegui prima con <strong>Dry Run</strong> attivo per verificare<br>
                            2. Se tutto OK, disattiva Dry Run e riesegui per scrivere nel DB<br>
                            3. Dopo il backfill, testa la sync per vedere gli skip in azione
                        </div>
                    </div>

                </div>
            </div>

            <div class="card">
                <div class="card-body" style="text-align: center;">
                    <button type="submit" class="btn btn-primary" style="font-size: 1.1rem; padding: 0.75rem 2rem;">
                        🚀 Avvia Backfill
                    </button>
                </div>
            </div>
        </form>

        <!-- Help Section -->
        <div class="card">
            <div class="card-header">❓ Domande Frequenti</div>
            <div class="card-body">
                <details style="margin-bottom: 1rem;">
                    <summary style="cursor: pointer; font-weight: bold;">Cos'è il backfill fingerprints?</summary>
                    <p style="margin-top: 0.5rem; color: var(--text-muted);">
                        Il backfill calcola gli hash (fingerprint) per le spedizioni già presenti nel database. 
                        Questi hash permettono al sistema di confrontare rapidamente se una spedizione è cambiata, 
                        evitando chiamate API inutili (strategia skip intelligente).
                    </p>
                </details>

                <details style="margin-bottom: 1rem;">
                    <summary style="cursor: pointer; font-weight: bold;">Quando devo eseguirlo?</summary>
                    <p style="margin-top: 0.5rem; color: var(--text-muted);">
                        - Dopo la prima installazione/migrazione<br>
                        - Dopo un import manuale di spedizioni<br>
                        - Se modifichi l'algoritmo di calcolo fingerprint<br>
                        - Dopo un ripristino da backup
                    </p>
                </details>

                <details style="margin-bottom: 1rem;">
                    <summary style="cursor: pointer; font-weight: bold;">È sicuro eseguirlo?</summary>
                    <p style="margin-top: 0.5rem; color: var(--text-muted);">
                        Sì! Il backfill legge solo dati esistenti dal DB e calcola hash. 
                        Non fa chiamate API Amazon e non modifica dati business. 
                        Usa "Dry Run" per testare senza scrivere.
                    </p>
                </details>

                <details>
                    <summary style="cursor: pointer; font-weight: bold;">Quanto tempo richiede?</summary>
                    <p style="margin-top: 0.5rem; color: var(--text-muted);">
                        Molto veloce! ~1000 spedizioni in 2-5 secondi (solo query DB, no API). 
                        Per volumi enormi (>10k) usa batch multipli con offset.
                    </p>
                </details>
            </div>
        </div>

    </div>
</body>
</html>

