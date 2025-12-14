<?php
/**
 * Import Manuale da Distinta Imballaggio
 * 
 * Importa spedizioni e items da CSV/TXT ottenuto da:
 * - Export Seller Central
 * - Distinte di imballaggio PDF → Testo
 * - Qualsiasi formato CSV con colonne minime richieste
 * 
 * Formato CSV atteso (separato da virgola o tab):
 * ShipmentId,SKU,Quantity,ProductName,ASIN,FNSKU,Status,DestinationFC
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $basePath = dirname(__DIR__, 2);
    
    $dbConfigPaths = [
        __DIR__ . '/../../modules/margynomic/config/database.php',
        __DIR__ . '/../margynomic/config/database.php',
        $basePath . '/modules/margynomic/config/database.php',
        $basePath . '/config/database.php'
    ];
    
    $dbLoaded = false;
    foreach ($dbConfigPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $dbLoaded = true;
            break;
        }
    }
    
    if (!$dbLoaded) {
        throw new Exception("Database config not found");
    }
    
    require_once __DIR__ . '/../margynomic/config/CentralLogger.php';
    require_once __DIR__ . '/inbound_core.php';
    require_once __DIR__ . '/../margynomic/admin/admin_helpers.php';
    
} catch (Exception $e) {
    die("<h1>Configuration Error</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>");
}

// Admin auth check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isAdminLogged()) {
    header('Location: ../margynomic/admin/admin_login.php');
    exit;
}

// Get user_id from GET/POST or default to first active user
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (isset($_POST['user_id']) ? (int)$_POST['user_id'] : null);

// If no user_id, get first active user
$db = getDbConnection();
if (!$userId) {
    $stmt = $db->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1");
    $userId = $stmt->fetchColumn();
}

// Get user info
$stmt = $db->prepare("SELECT id, nome, email FROM users WHERE id = ? AND is_active = 1");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("<h1>Error</h1><p>User not found or inactive</p>");
}

// Get all active users for selector
$stmt = $db->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY nome");
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$uploadDir = __DIR__ . '/uploads/';

// Crea directory upload se non esiste
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

/**
 * Parser intelligente per formato Amazon TXT nativo
 * 
 * Gestisce file con struttura multi-blocco:
 * - Header spedizione (chiave\tvalue)
 * - Items header + rows
 * - Riga vuota
 * - Ripete per ogni spedizione
 */
function parseAmazonTxtFormat($handle) {
    $shipments = [];
    $currentShipment = null;
    $itemsHeader = null;
    $inItemsSection = false;
    
    $lineNum = 0;
    while (($line = fgets($handle)) !== false) {
        $lineNum++;
        $line = trim($line);
        
        // Skip empty lines (delimiters between shipments)
        if (empty($line)) {
            $inItemsSection = false;
            $itemsHeader = null;
            continue;
        }
        
        // Parse shipment header fields
        if (strpos($line, "\t") !== false && !$inItemsSection) {
            $parts = explode("\t", $line, 2);
            $key = trim($parts[0]);
            $value = isset($parts[1]) ? trim($parts[1]) : '';
            
            // Check if this is start of new shipment
            if ($key === 'Numero spedizione') {
                // Save previous shipment if exists
                if ($currentShipment && isset($currentShipment['shipment_id'])) {
                    $shipments[$currentShipment['shipment_id']] = $currentShipment;
                }
                
                // Start new shipment
                $currentShipment = [
                    'shipment_id' => $value,
                    'name' => '',
                    'destination_fc' => '',
                    'items' => []
                ];
            } elseif ($key === 'Nome' && $currentShipment) {
                $currentShipment['name'] = $value;
            } elseif ($key === 'Paese di destinazione' && $currentShipment) {
                $currentShipment['destination_fc'] = $value;
            } elseif ($key === 'SKU venditore') {
                // This is the items header row
                $itemsHeader = explode("\t", $line);
                $itemsHeader = array_map('trim', $itemsHeader);
                $inItemsSection = true;
            }
        }
        
        // Parse item rows (separate if to avoid conflicts)
        if ($inItemsSection && $itemsHeader && $currentShipment && strpos($line, "\t") !== false) {
            // Skip if this is the header row itself
            if (trim(explode("\t", $line)[0]) === 'SKU venditore') {
                continue;
            }
            
            // Parse item row
            $itemParts = explode("\t", $line);
            $itemParts = array_pad($itemParts, count($itemsHeader), '');
            
            $item = [];
            foreach ($itemsHeader as $idx => $headerCol) {
                $item[$headerCol] = isset($itemParts[$idx]) ? trim($itemParts[$idx]) : '';
            }
            
            // Map to expected format
            $sku = $item['SKU venditore'] ?? '';
            $qty = (int)($item['Unità spedite'] ?? 0);
            
            if (!empty($sku) && $qty > 0) {
                $currentShipment['items'][] = [
                    'SellerSKU' => $sku,
                    'QuantityShipped' => $qty,
                    'QuantityReceived' => $qty,
                    'ProductName' => $item['Titolo'] ?? '',
                    'ASIN' => $item['ASIN'] ?? '',
                    'FulfillmentNetworkSKU' => $item['FNSKU'] ?? ''
                ];
            }
        }
    }
    
    // Don't forget last shipment
    if ($currentShipment && isset($currentShipment['shipment_id'])) {
        $shipments[$currentShipment['shipment_id']] = $currentShipment;
    }
    
    // Convert to expected format
    $result = [];
    foreach ($shipments as $shipmentId => $shipmentData) {
        $result[$shipmentId] = [
            'status' => 'MANUAL',
            'destination_fc' => strtoupper($shipmentData['destination_fc']),
            'name' => $shipmentData['name'],
            'items' => $shipmentData['items']
        ];
    }
    
    return $result;
}

// Process upload (supporta upload multiplo)
$uploadSuccess = false;
$uploadError = null;
$uploadedFiles = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['packing_list'])) {
    $files = $_FILES['packing_list'];
    
    // Check if multiple files
    if (is_array($files['name'])) {
        // Multiple files
        $fileCount = count($files['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $filename = 'packing_list_' . time() . '_' . $i . '_' . basename($files['name'][$i]);
                $targetPath = $uploadDir . $filename;
                
                if (move_uploaded_file($files['tmp_name'][$i], $targetPath)) {
                    $uploadedFiles[] = $targetPath;
                } else {
                    $uploadError = "Errore durante il salvataggio del file: " . $files['name'][$i];
                    break;
                }
            } else {
                $uploadError = "Errore durante l'upload del file: " . $files['name'][$i];
                break;
            }
        }
        
        if (count($uploadedFiles) > 0 && !$uploadError) {
            $uploadSuccess = true;
        }
    } else {
        // Single file
        if ($files['error'] === UPLOAD_ERR_OK) {
            $filename = 'packing_list_' . time() . '_' . basename($files['name']);
            $targetPath = $uploadDir . $filename;
            
            if (move_uploaded_file($files['tmp_name'], $targetPath)) {
                $uploadSuccess = true;
                $uploadedFiles[] = $targetPath;
            } else {
                $uploadError = "Errore durante il salvataggio del file";
            }
        } else {
            $uploadError = "Errore durante l'upload: " . $files['error'];
        }
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import da Distinta Imballaggio - User 8</title>
    <link rel="stylesheet" href="inbound.css">
    <style>
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 2rem; }
        .upload-box {
            border: 2px dashed #007bff;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            background: #f8f9fa;
            margin: 2rem 0;
        }
        .upload-box input[type="file"] {
            margin: 1rem 0;
        }
        .template-box {
            background: #e7f3ff;
            border: 1px solid #007bff;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 2rem 0;
        }
        .terminal {
            background: #1e1e1e;
            color: #00ff00;
            padding: 1.5rem;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            line-height: 1.6;
            margin: 2rem 0;
            max-height: 600px;
            overflow-y: auto;
        }
        .success { color: #00ff00; }
        .error { color: #ff0000; }
        .warning { color: #ffaa00; }
        .info { color: #00aaff; }
        pre { background: #f4f4f4; padding: 1rem; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>

<?php echo getAdminNavigation('inbound'); ?>

    <div class="container">
        <div class="header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h1>📦 Import Manuale da Distinta Imballaggio</h1>
                <p>👤 Utente: <strong><?= htmlspecialchars($user['nome']) ?></strong> (ID: <?= $userId ?>)</p>
            </div>
            
            <!-- User Selector -->
            <div style="text-align: right;">
                <form method="GET" style="display: inline-block;">
                    <label for="user_id" style="font-size: 0.9rem; color: #6c757d; margin-right: 0.5rem;">Cambia Utente:</label>
                    <select name="user_id" id="user_id" onchange="this.form.submit()" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #ccc;">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $u['id'] == $userId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['nome']) ?> (ID: <?= $u['id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </div>
        
        <!-- Navigation Breadcrumb -->
        <div style="margin-bottom: 2rem; padding: 1rem; background: #f8f9fa; border-radius: 8px; display: flex; gap: 1rem; flex-wrap: wrap;">
            <a href="inbound.php?user_id=<?= $userId ?>" style="color: #007bff; text-decoration: none;">🏠 Dashboard</a>
            <span style="color: #6c757d;">→</span>
            <a href="inbound_views.php?view=shipments&user_id=<?= $userId ?>" style="color: #007bff; text-decoration: none;">📦 Spedizioni</a>
            <span style="color: #6c757d;">→</span>
            <strong style="color: #495057;">📥 Import Manuale</strong>
        </div>

        <?php if (!$uploadSuccess): ?>
            <!-- STEP 1: Istruzioni + Upload -->
            <div class="card">
                <div class="card-header">📝 STEP 1: Prepara il File CSV</div>
                <div class="card-body">
                    <h3>✅ METODO 1: Upload Multiplo (TUTTI I 36 FILE) - RACCOMANDATO</h3>
                    <ol>
                        <li>Vai su <strong>Amazon Seller Central → Inventario → Spedizioni FBA</strong></li>
                        <li>Per ogni spedizione:
                            <ul>
                                <li>Apri spedizione (es. FBA15HQ6C5JP)</li>
                                <li>Click <strong>"Stampa distinta di imballaggio"</strong></li>
                                <li><strong>Ctrl+A → Ctrl+C</strong> (copia tutto)</li>
                                <li>Apri Notepad → <strong>Ctrl+V</strong></li>
                                <li>Salva come: <code>FBA15HQ6C5JP.txt</code></li>
                            </ul>
                        </li>
                        <li>Ripeti per tutte le 36 spedizioni (avrai 36 file .txt)</li>
                        <li>Caricali tutti insieme usando il form qui sotto (Ctrl+Click per selezione multipla)</li>
                        <li><strong>✨ Il sistema processa tutti automaticamente!</strong></li>
                    </ol>
                    
                    <h3 style="margin-top: 2rem;">✅ METODO 2: File Singolo Multi-Shipment</h3>
                    <ol>
                        <li>Apri Notepad/TextEdit</li>
                        <li>Copia/incolla le distinte di TUTTE le spedizioni in fondo una all'altra</li>
                        <li>Salva: <code>tutte_spedizioni.txt</code></li>
                        <li>Carica il singolo file</li>
                    </ol>
                    <p style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 6px; margin-top: 1rem;">
                        <strong>✨ NESSUNA CONVERSIONE NECESSARIA!</strong><br>
                        Il sistema rileva automaticamente il formato nativo Amazon e importa tutto!
                    </p>
                    
                    <h3 style="margin-top: 2rem;">📊 METODO ALTERNATIVO: Export CSV</h3>
                    <ol>
                        <li>Vai su <strong>Seller Central → Spedizioni FBA</strong></li>
                        <li>Click su <strong>"Esporta"</strong> o <strong>"Download Report"</strong></li>
                        <li>Salva il file CSV e caricalo</li>
                    </ol>
                    
                    <h3 style="margin-top: 2rem;">Formato CSV Accettati:</h3>
                    <div class="template-box">
                        <strong>FORMATO 1: Export Amazon Seller Central</strong>
                        <pre>Numero spedizione,Nome,Paese di destinazione,SKU venditore,Titolo,ASIN,FNSKU,Unità spedite</pre>
                        
                        <strong>Esempio righe:</strong>
                        <pre>FBA15K3K2S4Y,163 spezie miste,TRN3,50gr -,Semi di Finocchietto Selvatico...,B0CPQ6R1LV,B0CPQ6R1LV,50
FBA15K3K2S4Y,163 spezie miste,TRN3,50gr - Anice stellato,Anice Stellato Intero...,B0CPQ4STY5,B0CPQ4STY5,10</pre>
                        
                        <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid #ccc;">
                        
                        <strong>FORMATO 2: Semplificato</strong>
                        <pre>ShipmentId,SKU,Quantity,ProductName,ASIN,FNSKU,Status,DestinationFC</pre>
                        
                        <strong>Esempio righe:</strong>
                        <pre>FBA15K3K2S4Y,50gr -,50,Semi di Finocchietto...,B0CPQ6R1LV,B0CPQ6R1LV,MANUAL,TRN3
FBA15K3K2S4Y,50gr - Anice stellato,10,Anice Stellato...,B0CPQ4STY5,B0CPQ4STY5,MANUAL,TRN3</pre>
                        
                        <p style="margin-top: 1rem;"><strong>Note Importanti:</strong></p>
                        <ul>
                            <li>✅ Prima riga DEVE essere l'intestazione</li>
                            <li>✅ Ogni riga = 1 item (SKU) della spedizione</li>
                            <li>✅ Se spedizione ha 10 SKU diversi = 10 righe con stesso ShipmentId</li>
                            <li>✅ Separatore: virgola <code>,</code> o tab <code>\t</code> (auto-rilevato)</li>
                            <li>✅ Se mancano dati (es. ASIN), lascia vuoto</li>
                            <li>🏷️ <strong>Status automatico: MANUAL</strong> (badge giallo tratteggiato nella dashboard)</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">📤 STEP 2: Carica il File CSV</div>
                <div class="card-body">
                    <?php if ($uploadError): ?>
                        <div class="alert alert-danger">
                            <span class="alert-icon">❌</span>
                            <strong>Errore Upload:</strong> <?= htmlspecialchars($uploadError) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <div class="upload-box">
                            <h3>📁 Seleziona File TXT, CSV o TSV</h3>
                            <p><strong>✨ Formato Automatico:</strong> TXT (distinta diretta), CSV, TSV</p>
                            <p><strong>🚀 UPLOAD MULTIPLO:</strong> Puoi selezionare TUTTI i 36 file contemporaneamente!</p>
                            <input type="file" name="packing_list[]" accept=".csv,.txt,.tsv,.tab" multiple required style="font-size: 1rem; padding: 0.5rem;">
                            <br><br>
                            <button type="submit" style="background: #28a745; color: white; border: none; padding: 1rem 2rem; border-radius: 6px; cursor: pointer; font-size: 1rem;">
                                🚀 Carica e Processa Tutti i File
                            </button>
                            <p style="margin-top: 1rem; font-size: 0.875rem; color: #6c757d;">
                                💡 Usa Ctrl+Click (Windows) o Cmd+Click (Mac) per selezionare più file!<br>
                                📦 Puoi caricare fino a 50 file contemporaneamente (max 10MB per file)
                            </p>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card" style="margin-top: 2rem;">
                <div class="card-header">💡 Template CSV Scaricabili</div>
                <div class="card-body">
                    <p><strong>Opzione 1: Template Vuoto (36 spedizioni precompilate)</strong></p>
                    <a href="template_packing_list_empty.csv" 
                       download="template_36_missing_shipments.csv"
                       style="background: #6c757d; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; display: inline-block; margin-bottom: 1rem;">
                        📄 Download Template Vuoto (36 IDs già inseriti)
                    </a>
                    
                    <p style="margin-top: 1.5rem;"><strong>Opzione 2: Esempio Completo (FBA15K3K2S4Y - 163 spezie miste)</strong></p>
                    <a href="template_packing_list_example.csv" 
                       download="esempio_FBA15K3K2S4Y.csv"
                       style="background: #28a745; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; text-decoration: none; display: inline-block;">
                        📥 Download Esempio Completo (8 SKU, 163 units)
                    </a>
                    
                    <p style="margin-top: 1.5rem; color: #6c757d; font-size: 0.875rem;">
                        💡 <strong>Consiglio:</strong> Scarica l'esempio completo per vedere il formato corretto, poi modifica con i tuoi dati!
                    </p>
                </div>
            </div>

        <?php else: ?>
            <!-- STEP 3: Processamento -->
            <div class="alert alert-success">
                <span class="alert-icon">✅</span>
                <strong>File caricati con successo!</strong><br>
                Totale: <?= count($uploadedFiles) ?> file
            </div>

            <div class="terminal">
<?php
// Disable buffering
while (ob_get_level()) {
    ob_end_flush();
}
ob_implicit_flush(true);

$startTime = microtime(true);

echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
echo "[" . date('H:i:s') . "] <span class='info'>IMPORT DA DISTINTA - START</span>\n";
echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
echo "[" . date('H:i:s') . "] User ID: $userId\n";
echo "[" . date('H:i:s') . "] Files to process: " . count($uploadedFiles) . "\n";
echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n\n";
flush();

$db = getDbConnection();
$core = new InboundCore($userId, ['dry_run' => false]);

// GLOBAL STATS (across all files)
$globalStats = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'files_processed' => 0];

// LOOP THROUGH ALL UPLOADED FILES
foreach ($uploadedFiles as $fileIndex => $uploadedFile) {
    $fileNum = $fileIndex + 1;
    echo "[" . date('H:i:s') . "] \n";
    echo "[" . date('H:i:s') . "] ┌──────────────────────────────────────────┐\n";
    echo "[" . date('H:i:s') . "] │ <span class='info'>FILE $fileNum/" . count($uploadedFiles) . ": " . basename($uploadedFile) . "</span>\n";
    echo "[" . date('H:i:s') . "] └──────────────────────────────────────────┘\n";
    flush();

try {
    // Parse file
    echo "[" . date('H:i:s') . "] <span class='info'>Analyzing file format...</span>\n";
    flush();
    
    $handle = fopen($uploadedFile, 'r');
    if (!$handle) {
        throw new Exception("Cannot open file");
    }
    
    // Detect format: Amazon TXT (multi-block) vs CSV/TSV (flat)
    $firstLine = fgets($handle);
    rewind($handle);
    
    $isAmazonTxtFormat = (strpos($firstLine, 'Numero spedizione') === 0);
    
    if ($isAmazonTxtFormat) {
        echo "[" . date('H:i:s') . "] <span class='success'>Format detected: Amazon TXT (multi-shipment)</span>\n";
        echo "[" . date('H:i:s') . "] <span class='info'>Using intelligent parser...</span>\n\n";
        flush();
        
        $shipments = parseAmazonTxtFormat($handle);
        fclose($handle);
        
        echo "[" . date('H:i:s') . "] <span class='success'>✓ Amazon TXT parsed</span>\n";
        echo "[" . date('H:i:s') . "] Shipments found: " . count($shipments) . "\n";
        
        // DEBUG: Show items per shipment
        foreach ($shipments as $sid => $sdata) {
            $itemCount = count($sdata['items'] ?? []);
            echo "[" . date('H:i:s') . "] - $sid: $itemCount items\n";
            if ($itemCount === 0) {
                echo "[" . date('H:i:s') . "]   <span class='warning'>⚠️ WARNING: 0 items parsed for this shipment!</span>\n";
            }
        }
        
        echo "[" . date('H:i:s') . "] Total items: " . array_sum(array_map(fn($s) => count($s['items']), $shipments)) . "\n\n";
        flush();
        
    } else {
        // Original CSV/TSV parser
        $separator = (strpos($firstLine, "\t") !== false) ? "\t" : ",";
        echo "[" . date('H:i:s') . "] Format detected: " . ($separator === "\t" ? "TSV" : "CSV") . " (flat)\n";
        echo "[" . date('H:i:s') . "] Separator: " . ($separator === "\t" ? "TAB" : "COMMA") . "\n";
        flush();
    
    // Read header
    $header = fgetcsv($handle, 0, $separator);
    if (!$header) {
        throw new Exception("Cannot read header");
    }
    
    // Normalize header (trim + lowercase)
    $headerOriginal = $header;
    $header = array_map(function($h) {
        return strtolower(trim($h));
    }, $header);
    
    // Map Italian column names to English
    $columnMap = [
        'numero spedizione' => 'shipmentid',
        'nome' => 'shipmentname',
        'paese di destinazione' => 'destinationfc',
        'sku venditore' => 'sku',
        'titolo' => 'productname',
        'unità spedite' => 'quantity',
        'quantità spedite' => 'quantity',
        'quantityshipped' => 'quantity'
    ];
    
    // Apply mapping
    $header = array_map(function($h) use ($columnMap) {
        return $columnMap[$h] ?? $h;
    }, $header);
    
    echo "[" . date('H:i:s') . "] Columns found: " . implode(', ', $header) . "\n";
    echo "[" . date('H:i:s') . "] Format detected: " . (in_array('numero spedizione', array_map('strtolower', $headerOriginal)) ? "Amazon IT" : "Standard") . "\n\n";
    flush();
    
    // Required columns (any format)
    $hasShipmentId = in_array('shipmentid', $header);
    $hasSku = in_array('sku', $header);
    $hasQuantity = in_array('quantity', $header);
    
    if (!$hasShipmentId || !$hasSku || !$hasQuantity) {
        throw new Exception("Missing required columns. Need: ShipmentId (or 'Numero spedizione'), SKU (or 'SKU venditore'), Quantity (or 'Unità spedite')");
    }
    
    // Group by shipment
    $shipments = [];
    $lineNum = 1;
    
    while (($row = fgetcsv($handle, 0, $separator)) !== false) {
        $lineNum++;
        
        if (count($row) < 3) continue; // Skip empty/invalid lines
        
        $data = array_combine($header, array_pad($row, count($header), ''));
        
        $shipmentId = trim($data['shipmentid'] ?? '');
        $sku = trim($data['sku'] ?? '');
        $qty = (int)($data['quantity'] ?? 0);
        
        if (empty($shipmentId) || empty($sku) || $qty <= 0) {
            echo "[" . date('H:i:s') . "] <span class='warning'>⚠ Line $lineNum: skipped (invalid data)</span>\n";
            continue;
        }
        
        if (!isset($shipments[$shipmentId])) {
            // Determina nome spedizione (priorità: colonna Nome > prima SKU > default)
            $shipmentName = trim($data['shipmentname'] ?? $data['name'] ?? $sku);
            
            $shipments[$shipmentId] = [
                'status' => strtoupper(trim($data['status'] ?? 'MANUAL')),
                'destination_fc' => strtoupper(trim($data['destinationfc'] ?? '')),
                'name' => $shipmentName,
                'items' => []
            ];
        }
        
        $shipments[$shipmentId]['items'][] = [
            'SellerSKU' => $sku,
            'QuantityShipped' => $qty,
            'QuantityReceived' => (int)($data['quantityreceived'] ?? $qty),
            'ProductName' => trim($data['productname'] ?? $data['name'] ?? ''),
            'ASIN' => trim($data['asin'] ?? ''),
            'FulfillmentNetworkSKU' => trim($data['fnsku'] ?? '')
        ];
    }
    
        fclose($handle);
        
        echo "[" . date('H:i:s') . "] <span class='success'>✓ CSV parsed</span>\n";
        echo "[" . date('H:i:s') . "] Shipments found: " . count($shipments) . "\n";
        echo "[" . date('H:i:s') . "] Total items: " . array_sum(array_map(fn($s) => count($s['items']), $shipments)) . "\n\n";
        flush();
    }
    
    // Import shipments (common for both formats)
    $stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];
    
    foreach ($shipments as $shipmentId => $shipmentData) {
        echo "[" . date('H:i:s') . "] Processing: <strong>$shipmentId</strong>... ";
        flush();
        
        try {
            // Check if exists
            $stmt = $db->prepare("SELECT id FROM inbound_shipments WHERE user_id = ? AND amazon_shipment_id = ?");
            $stmt->execute([$userId, $shipmentId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                echo "<span class='warning'>SKIP (exists)</span>\n";
                $stats['skipped']++;
                continue;
            }
            
            // Simulate API response format
            $mockShipment = [
                'ShipmentId' => $shipmentId,
                'ShipmentName' => $shipmentData['name'],
                'ShipmentStatus' => $shipmentData['status'],
                'DestinationFulfillmentCenterId' => $shipmentData['destination_fc'],
                'LabelPrepType' => 'SELLER_LABEL',
                'AreCasesRequired' => false,
                'ConfirmedNeedByDate' => null,
                'BoxContentsSource' => 'NONE',
                'EstimatedBoxContentsFee' => null,
                '_is_manual_import' => true,
                '_manual_items' => $shipmentData['items']
            ];
            
            // Use InboundCore to process (will calculate fingerprints, etc.)
            $result = $core->processManualShipment($mockShipment);
            
            if ($result['status'] === 'complete') {
                echo "<span class='success'>✓ OK (items: {$result['items_count']})</span>\n";
                $stats['imported']++;
            } else {
                echo "<span class='warning'>⚠ PARTIAL</span>\n";
                $stats['imported']++;
            }
            
        } catch (Exception $e) {
            echo "<span class='error'>✗ ERROR: " . htmlspecialchars($e->getMessage()) . "</span>\n";
            $stats['errors']++;
        }
        
        flush();
    }
    
    // File summary
    echo "\n[" . date('H:i:s') . "] <span class='info'>FILE $fileNum SUMMARY:</span>\n";
    echo "[" . date('H:i:s') . "] - Imported: {$stats['imported']}\n";
    echo "[" . date('H:i:s') . "] - Skipped: {$stats['skipped']}\n";
    echo "[" . date('H:i:s') . "] - Errors: {$stats['errors']}\n";
    flush();
    
    // Update global stats
    $globalStats['imported'] += $stats['imported'];
    $globalStats['skipped'] += $stats['skipped'];
    $globalStats['errors'] += $stats['errors'];
    $globalStats['files_processed']++;
    
} catch (Exception $e) {
    echo "\n<span class='error'>✗ FILE ERROR: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    $globalStats['errors']++;
    flush();
}

} // END FOREACH FILES

$duration = round(microtime(true) - $startTime, 2);

echo "\n[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
echo "[" . date('H:i:s') . "] <span class='success'>🎉 ALL FILES IMPORT COMPLETED</span>\n";
echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
echo "[" . date('H:i:s') . "] Files processed: <span class='info'>{$globalStats['files_processed']}</span>/" . count($uploadedFiles) . "\n";
echo "[" . date('H:i:s') . "] Imported: <span class='success'>{$globalStats['imported']}</span>\n";
echo "[" . date('H:i:s') . "] Skipped: <span class='warning'>{$globalStats['skipped']}</span>\n";
echo "[" . date('H:i:s') . "] Errors: <span class='error'>{$globalStats['errors']}</span>\n";
echo "[" . date('H:i:s') . "] Duration: {$duration}s\n";
echo "[" . date('H:i:s') . "] ═══════════════════════════════════════════\n";
?>
            </div>

            <div class="alert alert-success" style="margin-top: 2rem;">
                <span class="alert-icon">✅</span>
                <strong>Import Completato!</strong><br>
                Files processati: <strong><?= $globalStats['files_processed'] ?></strong><br>
                Spedizioni importate: <strong><?= $globalStats['imported'] ?></strong><br>
                Spedizioni skipped (già esistenti): <strong><?= $globalStats['skipped'] ?></strong><br>
                <br>
                Verifica il risultato nel database:<br>
                <code>SELECT COUNT(*) FROM inbound_shipments WHERE user_id = <?= $userId ?>;</code>
            </div>

            <div style="margin-top: 2rem;">
                <a href="inbound.php?user_id=<?= $userId ?>" class="btn btn-primary">📊 Torna alla Dashboard</a>
                <a href="inbound_views.php?view=shipments&user_id=<?= $userId ?>" class="btn btn-secondary">📦 Vedi Spedizioni</a>
                <a href="import_from_packing_list.php?user_id=<?= $userId ?>" class="btn btn-secondary">🔄 Importa Altri File</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

