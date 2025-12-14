<?php
$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);

/**
 * Helper Functions per Admin Margynomic - VERSIONE COMPLETA
 * File: admin/admin_helpers.php
 * 
 * Tutte le funzioni essenziali per il sistema admin
 */

// Avvia sessione se non già attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Includi configurazione database
require_once __DIR__ . '/../config/config.php';

// === AUTENTICAZIONE ===

/**
 * Verifica se l'admin è loggato
 */
function isAdminLogged() {
    return isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;
}

/**
 * Verifica credenziali admin dal database
 */
function verifyAdminCredentials($email, $password) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("SELECT id, email, password_hash, nome FROM users WHERE email = ? AND role = 'admin' AND is_active = 1");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        
        if ($admin && (password_verify($password, $admin['password_hash']) || $admin['password_hash'] === $password)) {
            return $admin;
        }
        return false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Richiede autenticazione admin - redirect se non loggato
 */
function requireAdmin() {
    if (!isAdminLogged()) {
        header('Location: /modules/margynomic/admin/admin_login.php');
        exit;
    }
}

/**
 * Redirect helper (solo se non già definita)
 */
if (!function_exists('redirect')) {
    function redirect($url) {
        header('Location: ' . $url);
        exit;
    }
}

/**
 * Escape HTML per sicurezza
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Connessione database admin
 */
function getAdminDbConnection() {
    return getDbConnection();
}

// === STATISTICHE BASE ===

/**
 * Conta utenti attivi
 */
function countActiveUsers() {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Conta credenziali Amazon attive
 */
function countActiveCredentials() {
    try {
        $pdo = getAdminDbConnection();
        
        // Conta configurazioni admin nella tabella amazon_credentials
        $stmt = $pdo->query("SELECT COUNT(*) FROM amazon_credentials WHERE is_active = 1");
        $adminCredentials = $stmt->fetchColumn();
        
        // Conta token utenti nella tabella amazon_client_tokens  
        $stmt = $pdo->query("SELECT COUNT(*) FROM amazon_client_tokens WHERE is_active = 1");
        $userTokens = $stmt->fetchColumn();
        
        // Restituisci il totale (credenziali admin + token utenti)
        return $adminCredentials + $userTokens;
        
    } catch (PDOException $e) {
        return 0;
    }
}

/**
 * Conta log debug totali
 */
function countDebugLogs() {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->query("SELECT COUNT(*) FROM sync_debug_logs");
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// === GESTIONE UTENTI ===

/**
 * Ottieni lista utenti
 */
function getUsers() {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->query("
            SELECT id, nome, email, is_active, creato_il
            FROM users 
            ORDER BY creato_il DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Ottieni utenti con status sync
 */
function getUsersWithSyncStatus($limit = 50) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, u.nome, u.creato_il, u.is_active
            FROM users u 
            WHERE u.is_active = 1
            ORDER BY u.id ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $users = $stmt->fetchAll();
        
        // Aggiungi statistiche reali settlement per ogni utente
        foreach ($users as &$user) {
            $userId = $user['id'];
            $tableName = "report_settlement_{$userId}";
            
            // Verifica esistenza tabella settlement
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = ?
            ");
            $stmt->execute([$tableName]);
            
            if ($stmt->fetchColumn() > 0) {
                // Conta righe nella tabella settlement
                $stmt = $pdo->query("SELECT COUNT(*) FROM `{$tableName}`");
                $settlementCount = $stmt->fetchColumn();
                
                // Ottieni data ultima sincronizzazione (data più recente nella tabella)
                $stmt = $pdo->query("SELECT MAX(date_uploaded) FROM `{$tableName}`");
                $lastSync = $stmt->fetchColumn();
                
                $user['settlement_count'] = $settlementCount;
                $user['last_sync'] = $lastSync;
                $user['stato_sync'] = $settlementCount > 0 ? 'Sincronizzato' : 'Non sincronizzato';
            } else {
                $user['settlement_count'] = 0;
                $user['last_sync'] = null;
                $user['stato_sync'] = 'Non sincronizzato';
            }
        }
        
        return $users;
        
    } catch (PDOException $e) {
        error_log("Errore getUsersWithSyncStatus: " . $e->getMessage());
        return [];
    }
}

/**
 * Elimina utente
 */
function deleteUser($userId) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Disattiva utente
 */
function deactivateUser($userId) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        return $stmt->execute([$userId]);
    } catch (PDOException $e) {
        return false;
    }
}

// === MAPPING SKU ===

/**
* Ottieni SKU non mappati per utente
*/
function getUnmappedSkus($userId, $limit = 50) {
   try {
       $pdo = getAdminDbConnection();
       $tableName = "report_settlement_{$userId}";
       
       // Verifica esistenza tabella
       $stmt = $pdo->prepare("
           SELECT COUNT(*) 
           FROM information_schema.tables 
           WHERE table_schema = DATABASE() 
           AND table_name = ?
       ");
       $stmt->execute([$tableName]);
       if ($stmt->fetchColumn() == 0) {
           return [];
       }
       
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(p.id, 'unmapped') as product_id,
        COALESCE(p.nome, '') as product_name,
        GROUP_CONCAT(DISTINCT combined.sku ORDER BY combined.sku SEPARATOR '|') as skus
    FROM (
        SELECT r.sku, r.product_id, 1 as priority
        FROM `{$tableName}` r
        WHERE r.sku IS NOT NULL AND r.sku != ''
        UNION
        SELECT i.sku, i.product_id, 2 as priority
        FROM inventory i
        WHERE i.user_id = ? AND i.sku IS NOT NULL AND i.sku != ''
UNION
SELECT DISTINCT f.seller_sku as sku, f.product_id, 3 as priority
FROM inventory_fbm f
WHERE f.user_id = ? 
AND f.seller_sku IS NOT NULL 
AND f.seller_sku != ''
AND f.seller_sku != f.item_name
AND LENGTH(f.seller_sku) < 80
    ) combined
    LEFT JOIN products p ON combined.product_id = p.id
    GROUP BY COALESCE(p.id, CONCAT('sku_', combined.sku))
    ORDER BY 
        CASE WHEN p.id IS NULL THEN 0 ELSE 1 END,
        COALESCE(p.nome, combined.sku)
    LIMIT ?
");
       $stmt->execute([$userId, $userId, $limit]);
       
       return $stmt->fetchAll();
   } catch (PDOException $e) {
       return [];
   }
}

/**
 * Salva mapping singolo SKU
 */
function saveSingleMapping($userId, $sku, $productId = null, $productName = null) {
    try {
        logAdminOperation("SINGLE_MAPPING_START", $userId, "Inizio saveSingleMapping", [
            'sku' => $sku,
            'productId' => $productId,
            'productName' => $productName
        ]);
        
        $pdo = getAdminDbConnection();
        $pdo->beginTransaction();
        
        $finalProductId = null;
        $isNewProduct = false;
        
        // Caso 1: Reset (svuota tutto)
        if (empty($productId) && empty($productName)) {
            $finalProductId = null;
        }
        // Caso 2: Solo Product ID fornito
        elseif (!empty($productId) && empty($productName)) {
            $finalProductId = (int)$productId;
        }
        // Caso 3: Nome fornito (con o senza Product ID)
elseif (!empty($productName)) {
    $cleanName = trim($productName);
    
    // CONTROLLO ANTI-DUPLICATI SEMPRE
// Controlla sempre duplicati, escludendo il prodotto corrente se stiamo aggiornando
$sql = "SELECT id, nome FROM products WHERE user_id = ? AND nome = ?";
$params = [$userId, $cleanName];

if (!empty($productId)) {
    $sql .= " AND id != ?";
    $params[] = $productId;
}
$sql .= " LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
    $duplicate = $stmt->fetch();
    
if ($duplicate) {
    // DUPLICATO TROVATO - USA LO STESSO PRODUCT_ID (RAGGRUPPA SKU)
    logAdminOperation("SINGLE_MAPPING_MERGE", $userId, "Nome prodotto esistente, raggruppando SKU", [
        'sku' => $sku,
        'existing_product_id' => $duplicate['id'],
        'existing_name' => $duplicate['nome']
    ]);
    $finalProductId = $duplicate['id'];
} else {
    
if (!empty($productId)) {
    // FORZA l'aggiornamento del prodotto esistente
    $stmt = $pdo->prepare("
        UPDATE products 
        SET nome = ?, aggiornato_il = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $result = $stmt->execute([$cleanName, $productId, $userId]);
    
    if ($stmt->rowCount() > 0) {
        $finalProductId = (int)$productId;
        logAdminOperation("PRODUCT_UPDATED", $userId, "Prodotto aggiornato", [
            'product_id' => $productId,
            'new_name' => $cleanName
        ]);
    } else {
        throw new Exception("Impossibile aggiornare prodotto ID $productId");
    }
} else {
        // Crea nuovo prodotto (già verificato non duplicato)
        $stmt = $pdo->prepare("
            INSERT INTO products (user_id, sku, nome, creato_il, aggiornato_il)
            VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$userId, $sku, $cleanName]);
        $finalProductId = $pdo->lastInsertId();
        $isNewProduct = true;
    }
}
            }
        
        
// Aggiorna settlement mapping
$tableName = "report_settlement_{$userId}";
$stmt = $pdo->prepare("
    UPDATE `{$tableName}` 
    SET product_id = ?
    WHERE sku = ?
");
$stmt->execute([$finalProductId, $sku]);

// Aggiorna anche inventory mapping se presente
$stmt = $pdo->prepare("
    UPDATE inventory 
    SET product_id = ?
    WHERE user_id = ? AND sku = ?
");
$stmt->execute([$finalProductId, $userId, $sku]);

// Aggiorna anche inventory_fbm mapping se presente
$stmt = $pdo->prepare("
    UPDATE inventory_fbm 
    SET product_id = ?
    WHERE user_id = ? AND seller_sku = ?
");
$stmt->execute([$finalProductId, $userId, $sku]);

        // Aggiorna mapping_states - CRITICO per visualizzazione interfaccia
        if ($finalProductId) {
            $stmt = $pdo->prepare("
                INSERT INTO mapping_states (user_id, sku, product_id, source_table, mapping_type, confidence_score, updated_at)
                VALUES (?, ?, ?, 'settlement', 'manual', 1.0, NOW())
                ON DUPLICATE KEY UPDATE 
                product_id = VALUES(product_id),
                mapping_type = VALUES(mapping_type),
                confidence_score = VALUES(confidence_score),
                updated_at = VALUES(updated_at)
            ");
            $stmt->execute([$userId, $sku, $finalProductId]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'product_id' => $finalProductId,
            'product_name' => $productName,
            'is_new_product' => $isNewProduct,
            'message' => $finalProductId ? 'Mapping salvato' : 'Mapping rimosso'
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Salva mapping multipli (bulk)
 */
function saveBulkMappings($userId, $mappings) {
    try {
        // Log nel sistema centralizzato
        logAdminOperation("BULK_SAVE_START", $userId, "Inizio bulk save", [
            'mappings_count' => count($mappings),
            'data' => $mappings
        ]);
        
        $successCount = 0;
        $errorCount = 0;
        $results = [];
        
        // Salva ogni mapping individualmente senza transazione globale
        foreach ($mappings as $mapping) {
            $sku = $mapping['sku'] ?? '';
            $productId = $mapping['product_id'] ?? null;
            $productName = $mapping['product_name'] ?? null;
            
            if (empty($sku)) {
    logAdminOperation("BULK_SAVE_ERROR", $userId, "SKU vuoto", ['mapping' => $mapping]);
    $errorCount++;
    $results[] = [
        'sku' => $sku,
        'success' => false,
        'message' => 'SKU vuoto'
    ];
    continue;
}

logAdminOperation("BULK_SAVE_PROCESS", $userId, "Processing mapping", [
    'sku' => $sku,
    'product_name' => $productName
]);
            
            logAdminOperation("BULK_SAVE_DEBUG", $userId, "Chiamando saveSingleMapping", [
    'sku' => $sku,
    'productId' => $productId,
    'productName' => $productName
]);

$result = saveSingleMapping($userId, $sku, $productId, $productName);

logAdminOperation("BULK_SAVE_RESULT", $userId, "Risultato saveSingleMapping", [
    'sku' => $sku,
    'result' => $result
]);

if ($result['success']) {
    $successCount++;
    logAdminOperation("BULK_SAVE_SUCCESS", $userId, "Mapping salvato con successo", ['sku' => $sku]);
} else {
    $errorCount++;
    logAdminOperation("BULK_SAVE_FAIL", $userId, "Mapping fallito", [
        'sku' => $sku,
        'error' => $result['error'] ?? 'Unknown error',
        'message' => $result['message'] ?? 'No message'
    ]);
}

$results[] = [
    'sku' => $sku,
    'success' => $result['success'],
    'message' => $result['message'] ?? $result['error'] ?? ''
];
        }
        
        return [
            'success' => true,
            'processed' => count($mappings),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Ottieni suggerimento AI per SKU
 */
function getAiSuggestionForSku($userId, $sku) {
    try {
        require_once '../sincro/ai_sku_processor.php';
        
        $processor = new AiSkuProcessor();
        return $processor->getSuggestionForSku($sku);
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Errore AI: ' . $e->getMessage()
        ];
    }
}

/**
 * Verifica unicità Product ID
 */
function checkProductIdUniqueness($userId, $productId, $excludeSku = null) {
    try {
        $pdo = getAdminDbConnection();
        $tableName = "report_settlement_{$userId}";
        
        $sql = "SELECT DISTINCT sku FROM `{$tableName}` WHERE product_id = ?";
        $params = [$productId];
        
        if ($excludeSku) {
            $sql .= " AND sku != ?";
            $params[] = $excludeSku;
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $conflictSkus = array_column($stmt->fetchAll(), 'sku');
        
        return [
            'is_unique' => empty($conflictSkus),
            'conflicts' => $conflictSkus
        ];
        
    } catch (Exception $e) {
        return [
            'is_unique' => true,
            'conflicts' => []
        ];
    }
}

// === GESTIONE CREDENZIALI AMAZON ===

/**
 * Ottieni credenziali Amazon ADMIN (rinominata per evitare conflitti)
 */
function getAdminAmazonCredentials() {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->query("
            SELECT id, aws_access_key_id, aws_region, spapi_client_id, 
                   is_active, created_at, updated_at
            FROM amazon_credentials 
            ORDER BY created_at DESC
        ");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Aggiungi credenziali Amazon
 */
function addAmazonCredentials($awsAccessKey, $awsSecretKey, $awsRegion, $clientId, $clientSecret) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("
            INSERT INTO amazon_credentials 
            (aws_access_key_id, aws_secret_access_key, aws_region, spapi_client_id, spapi_client_secret, is_active) 
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        return $stmt->execute([$awsAccessKey, $awsSecretKey, $awsRegion, $clientId, $clientSecret]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Toggle status credenziali
 */
function toggleCredentialStatus($credentialId) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("
            UPDATE amazon_credentials 
            SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        return $stmt->execute([$credentialId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Elimina credenziali
 */
function deleteCredential($credentialId) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("DELETE FROM amazon_credentials WHERE id = ?");
        return $stmt->execute([$credentialId]);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Maschera parzialmente una stringa sensibile
 */
function maskSensitiveString($string, $visibleStart = 10, $visibleEnd = 4) {
    if (empty($string)) {
        return '-';
    }
    $length = strlen($string);
    if ($length <= ($visibleStart + $visibleEnd)) {
        return substr($string, 0, 4) . '***' . substr($string, -2);
    }
    return substr($string, 0, $visibleStart) . '***' . substr($string, -$visibleEnd);
}

/**
 * Ottieni credenziale per ID
 */
function getCredentialById($credentialId) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("
            SELECT id, aws_access_key_id, aws_secret_access_key, aws_region, 
                   spapi_client_id, spapi_client_secret, is_active, created_at, updated_at
            FROM amazon_credentials 
            WHERE id = ?
        ");
        $stmt->execute([$credentialId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Aggiorna credenziali Amazon esistenti
 */
function updateAmazonCredentials($credentialId, $awsAccessKey, $awsSecretKey, $awsRegion, $clientId, $clientSecret) {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->prepare("
            UPDATE amazon_credentials 
            SET aws_access_key_id = ?, 
                aws_secret_access_key = ?, 
                aws_region = ?, 
                spapi_client_id = ?, 
                spapi_client_secret = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        return $stmt->execute([$awsAccessKey, $awsSecretKey, $awsRegion, $clientId, $clientSecret, $credentialId]);
    } catch (PDOException $e) {
        return false;
    }
}

// === LOG DEBUG ===

/**
 * Ottieni log debug recenti
 */
function getRecentDebugLogs($limit = 100, $level = null, $userId = null) {
    try {
        $pdo = getAdminDbConnection();
        
        $where = [];
        $params = [];
        
        if ($level) {
            $where[] = "sdl.log_level = ?";
            $params[] = $level;
        }
        
        if ($userId) {
            $where[] = "sdl.user_id = ?";
            $params[] = $userId;
        }
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        $stmt = $pdo->prepare("
            SELECT sdl.*, u.email 
            FROM sync_debug_logs sdl 
            LEFT JOIN users u ON sdl.user_id = u.id 
            {$whereClause}
            ORDER BY sdl.created_at DESC 
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Ottieni utenti per dropdown
 */
function getUsersForDropdown() {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->query("SELECT id, email FROM users WHERE is_active = 1 ORDER BY email");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

// === MAPPING STATISTICS ===

/**
 * Statistiche mapping per admin_mapping.php
 */
function getMappingStatistics() {
    try {
        $pdo = getAdminDbConnection();
        $users = getUsers();
        $stats = [];
        
        foreach ($users as $user) {
            $userId = $user['id'];
            $tableName = "report_settlement_{$userId}";
            
            // Verifica esistenza tabella
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() 
                AND table_name = ?
            ");
            $stmt->execute([$tableName]);
            if ($stmt->fetchColumn() == 0) {
                continue;
            }
            
            // Conta SKU totali e mappati
            // Conta SKU totali (settlement + inventory)
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sku) FROM (
        SELECT DISTINCT sku FROM `{$tableName}` WHERE sku IS NOT NULL AND sku != ''
        UNION
        SELECT DISTINCT sku FROM inventory WHERE user_id = ? AND sku IS NOT NULL AND sku != ''
    ) as all_skus
");
$stmt->execute([$userId]);
$totalSkus = $stmt->fetchColumn();

// Conta SKU mappati (settlement + inventory)
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT sku) FROM (
        SELECT DISTINCT sku FROM `{$tableName}` WHERE product_id IS NOT NULL
        UNION
        SELECT DISTINCT sku FROM inventory WHERE user_id = ? AND product_id IS NOT NULL
    ) as mapped_skus
");
$stmt->execute([$userId]);
$mappedSkus = $stmt->fetchColumn();

            $mappingPercentage = $totalSkus > 0 ? round(($mappedSkus / $totalSkus) * 100, 1) : 0;
            
            $stats[] = [
                'user_id' => $userId,
                'user_name' => $user['nome'],
                'total_skus' => $totalSkus,
                'mapped_skus' => $mappedSkus,
                'unmapped_skus' => $totalSkus - $mappedSkus,
                'mapping_percentage' => $mappingPercentage
            ];
        }
        
        return $stats;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Esegui mapping completo per utente
 */
function executeCompleteMapping($userId) {
    try {
        // Auto-mapping standard
        require_once '../sincro/sku_mapping_system.php';
        $mapper = new SkuMappingSystem();
        $standardResult = $mapper->processUnmappedSkus($userId);
        
        // AI processing
        require_once '../sincro/ai_sku_processor.php';
        $aiProcessor = new AiSkuProcessor();
        $aiResult = $aiProcessor->processUnmappedSkus($userId);
        
        // Calcola statistiche finali
        $stats = getMappingStatistics();
        $userStats = array_filter($stats, function($s) use ($userId) {
            return $s['user_id'] == $userId;
        });
        $userStats = reset($userStats);
        
        return [
            'success' => true,
            'auto_mapped' => $standardResult['mapped_count'] ?? 0,
            'ai_processed' => $aiResult['processed_count'] ?? 0,
            'mapping_percentage' => $userStats['mapping_percentage'] ?? 0
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Esegui mapping globale per tutti gli utenti
 */
function executeGlobalMapping() {
    $users = getUsers();
    $results = [];
    
    foreach ($users as $user) {
        $results[] = executeCompleteMapping($user['id']);
    }
    
    return $results;
}

// === UTILITY FUNCTIONS ===

/**
 * Formatta data per visualizzazione
 */
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('d/m/Y H:i', strtotime($date));
}

/**
 * Formatta dimensione file
 */
function formatBytes($size, $precision = 2) {
    if ($size <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $base = log($size, 1024);
    return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
}

// === UI HELPERS ===

/**
 * Header HTML comune
 */
function getAdminHeader($title = 'Admin') {
    return '<!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . htmlspecialchars($title) . ' - Margynomic Admin</title>
        <!-- <link rel="stylesheet" href="../css/margynomic.css"> -->
        <style>
            body { font-family: Arial, sans-serif; margin: 0; background: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
            .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
            .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center; }
            .stat-number { font-size: 28px; font-weight: bold; color: #007bff; margin-bottom: 5px; }
            .stat-label { color: #666; font-size: 14px; }
            .btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
            .btn-primary { background: #007bff; color: white; }
            .btn-success { background: #28a745; color: white; }
            .btn-warning { background: #ffc107; color: black; }
            .btn-danger { background: #dc3545; color: white; }
            .table { width: 100%; border-collapse: collapse; background: white; }
            .table th, .table td { padding: 12px; border: 1px solid #ddd; text-align: left; }
            .table th { background: #f8f9fa; font-weight: bold; }
            .alert { padding: 15px; margin: 15px 0; border-radius: 4px; }
            .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        </style>
    </head>
    <body>';
}

function getAdminNavigation($currentPage = '') {
    // Rileva il contesto per calcolare i percorsi corretti
    $isInMapping = strpos($_SERVER['REQUEST_URI'], '/mapping/') !== false;
    $isInMargini = strpos($_SERVER['REQUEST_URI'], '/margini/') !== false;
    $isInListing = strpos($_SERVER['REQUEST_URI'], '/listing/') !== false;
    $isInInbound = strpos($_SERVER['REQUEST_URI'], '/inbound/') !== false;
    $isInInboundSubfolder = strpos($_SERVER['REQUEST_URI'], '/inbound/trid/') !== false;
    $isInCreaexcel = strpos($_SERVER['REQUEST_URI'], '/creaexcel/') !== false;
    
    if ($isInCreaexcel) {
        // Percorsi per pagine Excel Creator (creaexcel/ai/)
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = '/modules/mapping/sku_aggregation_interface.php';
        $mappingUrl = '/modules/mapping/mapping_dashboard.php';
        $feeMappingUrl = '/modules/margynomic/margini/admin_fee_mappings.php';
        $feeDashboardUrl = '/modules/margynomic/margini/admin_fee_dashboard.php';
        $feeOverrideUrl = '/modules/margynomic/margini/admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs
        $inboundDashboardUrl = '/modules/inbound/inbound.php';
        $inboundSyncUrl = '/modules/inbound/inbound_sync.php';
        $removalOrdersUrl = '/modules/inbound/removal_orders.php';
        $tridUrl = '/modules/inbound/trid/trid.php';
        $inboundShipmentsUrl = '/modules/inbound/inbound_views.php?view=shipments';
        $inboundImportUrl = '/modules/inbound/import_from_packing_list.php';
        $inboundStatsUrl = '/modules/inbound/inbound_views.php?view=stats';
        $inboundLogsUrl = '/modules/inbound/inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    } elseif ($isInMapping) {
        // Percorsi per pagine mapping
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = 'sku_aggregation_interface.php';
        $mappingUrl = 'mapping_dashboard.php';
        $feeMappingUrl = '/modules/margynomic/margini/admin_fee_mappings.php';
        $feeDashboardUrl = '/modules/margynomic/margini/admin_fee_dashboard.php';
        $feeOverrideUrl = '/modules/margynomic/margini/admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs
        $inboundDashboardUrl = '../inbound/inbound.php';
        $inboundSyncUrl = '../inbound/inbound_sync.php';
        $removalOrdersUrl = '../inbound/removal_orders.php';
        $tridUrl = '../inbound/trid/trid.php';
        $inboundShipmentsUrl = '../inbound/inbound_views.php?view=shipments';
        $inboundImportUrl = '../inbound/import_from_packing_list.php';
        $inboundStatsUrl = '../inbound/inbound_views.php?view=stats';
        $inboundLogsUrl = '../inbound/inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    } elseif ($isInMargini) {
        // Percorsi per pagine margini
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = '/modules/mapping/sku_aggregation_interface.php';
        $mappingUrl = '/modules/mapping/mapping_dashboard.php';
        $feeMappingUrl = 'admin_fee_mappings.php';
        $feeDashboardUrl = 'admin_fee_dashboard.php';
        $feeOverrideUrl = 'admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs
        $inboundDashboardUrl = '../../inbound/inbound.php';
        $inboundSyncUrl = '../../inbound/inbound_sync.php';
        $removalOrdersUrl = '../../inbound/removal_orders.php';
        $tridUrl = '../../inbound/trid/trid.php';
        $inboundShipmentsUrl = '../../inbound/inbound_views.php?view=shipments';
        $inboundImportUrl = '../../inbound/import_from_packing_list.php';
        $inboundStatsUrl = '../../inbound/inbound_views.php?view=stats';
        $inboundLogsUrl = '../../inbound/inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    } elseif ($isInListing) {
        // Percorsi per pagine listing
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = '/modules/mapping/sku_aggregation_interface.php';
        $mappingUrl = '/modules/mapping/mapping_dashboard.php';
        $feeMappingUrl = '/modules/margynomic/margini/admin_fee_mappings.php';
        $feeDashboardUrl = '/modules/margynomic/margini/admin_fee_dashboard.php';
        $feeOverrideUrl = '/modules/margynomic/margini/admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs
        $inboundDashboardUrl = '../inbound/inbound.php';
        $inboundSyncUrl = '../inbound/inbound_sync.php';
        $removalOrdersUrl = '../inbound/removal_orders.php';
        $tridUrl = '../inbound/trid/trid.php';
        $inboundShipmentsUrl = '../inbound/inbound_views.php?view=shipments';
        $inboundImportUrl = '../inbound/import_from_packing_list.php';
        $inboundStatsUrl = '../inbound/inbound_views.php?view=stats';
        $inboundLogsUrl = '../inbound/inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    } elseif ($isInInboundSubfolder) {
        // Percorsi per pagine in sottocartelle di inbound (es. trid/)
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = '/modules/mapping/sku_aggregation_interface.php';
        $mappingUrl = '/modules/mapping/mapping_dashboard.php';
        $feeMappingUrl = '/modules/margynomic/margini/admin_fee_mappings.php';
        $feeDashboardUrl = '/modules/margynomic/margini/admin_fee_dashboard.php';
        $feeOverrideUrl = '/modules/margynomic/margini/admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs (risali a livello inbound)
        $inboundDashboardUrl = '../inbound.php';
        $inboundSyncUrl = '../inbound_sync.php';
        $removalOrdersUrl = '../removal_orders.php';
        $tridUrl = 'trid.php';
        $inboundShipmentsUrl = '../inbound_views.php?view=shipments';
        $inboundImportUrl = '../import_from_packing_list.php';
        $inboundStatsUrl = '../inbound_views.php?view=stats';
        $inboundLogsUrl = '../inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    } elseif ($isInInbound) {
        // Percorsi per pagine inbound (cartella principale)
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = '/modules/mapping/sku_aggregation_interface.php';
        $mappingUrl = '/modules/mapping/mapping_dashboard.php';
        $feeMappingUrl = '/modules/margynomic/margini/admin_fee_mappings.php';
        $feeDashboardUrl = '/modules/margynomic/margini/admin_fee_dashboard.php';
        $feeOverrideUrl = '/modules/margynomic/margini/admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs (tutte nella stessa directory)
        $inboundDashboardUrl = 'inbound.php';
        $inboundSyncUrl = 'inbound_sync.php';
        $removalOrdersUrl = 'removal_orders.php';
        $inventoryReceiptsDebugUrl = 'inventory_receipts_simple.php';
        $tridUrl = 'trid/trid.php';
        $inboundShipmentsUrl = 'inbound_views.php?view=shipments';
        $inboundImportUrl = 'import_from_packing_list.php';
        $inboundStatsUrl = 'inbound_views.php?view=stats';
        $inboundLogsUrl = 'inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    } else {
        // Percorsi per pagine admin (default)
        $dashboardUrl = '/modules/margynomic/admin/admin_dashboard.php';
        $credenzialiUrl = '/modules/margynomic/admin/admin_credenziali.php';
        $utentiUrl = '/modules/margynomic/admin/admin_utenti.php';
        $fnskuUrl = '/modules/margynomic/admin/admin_fnsku.php';
        $cleanupUrl = '/modules/margynomic/admin/admin_cleanup.php';
        $aggregationUrl = '/modules/mapping/sku_aggregation_interface.php';
        $mappingUrl = '/modules/mapping/mapping_dashboard.php';
        $feeMappingUrl = '/modules/margynomic/margini/admin_fee_mappings.php';
        $feeDashboardUrl = '/modules/margynomic/margini/admin_fee_dashboard.php';
        $feeOverrideUrl = '/modules/margynomic/margini/admin_fee_user_overrides.php';
        $historicalUrl = '/modules/margynomic/admin/admin_historical.php';
        $logUrl = '/modules/margynomic/admin/admin_log.php';
        $serverLogsUrl = '/modules/margynomic/admin/admin_server_logs.php';
        $productListingUrl = '/modules/listing/admin_list.php';
        $easyshipUrl = '/modules/margynomic/admin/admin_easyship.php';
        $logoutUrl = '/modules/margynomic/admin/admin_logout.php';
        // Inbound URLs
        $inboundDashboardUrl = '../../inbound/inbound.php';
        $inboundSyncUrl = '../../inbound/inbound_sync.php';
        $removalOrdersUrl = '../../inbound/removal_orders.php';
        $tridUrl = '../../inbound/trid/trid.php';
        $inboundShipmentsUrl = '../../inbound/inbound_views.php?view=shipments';
        $inboundImportUrl = '../../inbound/import_from_packing_list.php';
        $inboundStatsUrl = '../../inbound/inbound_views.php?view=stats';
        $inboundLogsUrl = '../../inbound/inbound_views.php?view=logs';
        $priceExportUrl = '/modules/margynomic/admin/admin_amazon_price_export.php';
        $excelCreatorUrl = '/modules/margynomic/admin/creaexcel/ai/views/creator.php';
        $promptGeneratorUrl = '/prompt/simple_prompt.php';
    }
    
    // URLs assoluti per sitemap e database
    $sitemapUrl = 'https://www.skualizer.com/generate_sitemap.php';
    $databaseUrl = 'https://www.skualizer.com/generate_database_sitemap.php';
    
    $nav = '
    <style>
    .dropdown {
        position: relative;
        display: inline-block;
    }
    .dropdown-content {
        display: none;
        position: absolute;
        background-color: #1a1a1a;
        min-width: 200px;
        box-shadow: 0px 8px 16px rgba(0,0,0,0.3);
        z-index: 1000;
        border-radius: 5px;
        top: 100%;
        left: 0;
    }
    .dropdown-content a {
        color: white !important;
        padding: 12px 16px !important;
        text-decoration: none !important;
        display: block !important;
        margin: 0 !important;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .dropdown-content a:hover {
        background-color: rgba(255,255,255,0.1);
    }
    .dropdown:hover .dropdown-content {
        display: block;
    }
    .dropdown > a:after {
        content: " ▼";
        font-size: 10px;
    }
    .admin-nav-link { 
        color: white !important; 
        text-decoration: none !important; 
        margin-right: 20px !important; 
        padding: 8px 12px !important; 
        border-radius: 4px !important; 
        transition: background 0.3s ease !important; 
        font-weight: 500 !important; 
    }
    .admin-nav-link:hover { 
        background: rgba(255,255,255,0.1) !important; 
    }
    .admin-nav-link.active { 
        background: rgba(255,255,255,0.15) !important; 
        font-weight: 600 !important; 
    }
    .admin-nav-logout { 
        color: #dc3545 !important; 
        text-decoration: none !important; 
        padding: 8px 12px !important; 
        border-radius: 4px !important; 
        transition: background 0.3s ease !important; 
    }
    .admin-nav-logout:hover { 
        background: rgba(220,53,69,0.1) !important; 
    }
    </style>
    
    <div style="background: #343a40; padding: 15px 0; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
        <div style="max-width: 1200px; margin: 0 auto; padding: 0 20px; display: flex; align-items: center; justify-content: space-between;">
            
            <!-- Admin dropdown -->
            <div class="dropdown">
                <a href="#" class="admin-nav-link">👥 Admin</a>
                <div class="dropdown-content">
                    <a href="' . $dashboardUrl . '">Dashboard</a>
                    <a href="' . $utentiUrl . '">Utenti</a>
                    <a href="' . $credenzialiUrl . '">Credenziali</a>
                    <a href="' . $cleanupUrl . '">🧹 Cleanup</a>
                    <a href="' . $sitemapUrl . '" target="_blank">Sitemap</a>
                    <a href="' . $databaseUrl . '" target="_blank">Database</a>
                </div>
            </div>
            
            <!-- SKU Management dropdown -->
            <div class="dropdown">
                <a href="#" class="admin-nav-link">⚙️ SKU Management</a>
                <div class="dropdown-content">
                    <a href="' . $fnskuUrl . '">FNSKU</a>
                    <a href="' . $aggregationUrl . '">Aggrega SKU</a>
                    <a href="' . $mappingUrl . '">Mapping SKU</a>
                </div>
            </div>
            
            <!-- Fee Mapping dropdown -->
            <div class="dropdown">
                <a href="#" class="admin-nav-link">€ Fee Mapping</a>
                <div class="dropdown-content">
                    <a href="' . $feeMappingUrl . '">Fee Mapping</a>
                    <a href="' . $feeDashboardUrl . '">Fee Dashboard</a>
                    <a href="' . $feeOverrideUrl . '">Fee Override</a>
                </div>
            </div>
            
            <!-- Inbound dropdown -->
            <div class="dropdown">
                <a href="#" class="admin-nav-link">📦 Inbound</a>
                <div class="dropdown-content">
                    <a href="' . $inboundDashboardUrl . '">Dashboard</a>
                    <a href="' . $inboundSyncUrl . '">🔄 Sincronizza Inbound</a>
                    <a href="' . $removalOrdersUrl . '">📦 Sincronizza Removal</a>
                    <a href="' . $tridUrl . '">🎫 TRID Tracking</a>
                    <a href="' . $inboundShipmentsUrl . '">Spedizioni</a>
                    <a href="' . $inboundImportUrl . '">📥 Import Manuale</a>
                    <a href="' . $inboundStatsUrl . '">Statistiche</a>
                    <a href="' . $inboundLogsUrl . '">Log</a>
                </div>
            </div>
            
            <!-- Moduli dropdown -->
            <div class="dropdown">
                <a href="#" class="admin-nav-link">🚚 Moduli</a>
                <div class="dropdown-content">
                    <a href="' . $easyshipUrl . '">EasyShip</a>
                    <a href="' . $productListingUrl . '">📋 Product Listing</a>
                    <a href="' . $priceExportUrl . '">💰 Export Prezzi Amazon</a>
                    <a href="' . $excelCreatorUrl . '">🤖 Excel Creator (AI)</a>
                    <a href="' . $promptGeneratorUrl . '">⚡ Prompt Generator</a>
                    <a href="/modules/margynomic/admin/creaexcel/views/upload.php">📊 Excel Management</a>
                    <a href="' . $historicalUrl . '">Historical</a>
                </div>
            </div>
            
            <!-- Logs dropdown -->
            <div class="dropdown">
                <a href="#" class="admin-nav-link">📋 Logs</a>
                <div class="dropdown-content">
                    <a href="' . $logUrl . '">Log Debug</a>
                    <a href="' . $serverLogsUrl . '">Log Server</a>
                </div>
            </div>
            
            <!-- Logout -->
            <a href="' . $logoutUrl . '" class="admin-nav-logout">Logout</a>
        </div>
    </div>';
    
    return $nav;
}

/**
 * Footer HTML comune
 */
function getAdminFooter() {
    return '</body></html>';
}

/**
 * Mostra messaggio alert
 */
function showMessage($message, $type = 'success') {
    $class = ($type === 'error') ? 'alert-error' : 'alert-success';
    return '<div class="alert ' . $class . '">' . htmlspecialchars($message) . '</div>';
}


// === GESTIONE TABELLE SETTLEMENT ===

/**
 * Crea tabella settlement per utente specifico
 */
function createUserSettlementTable($userId) {
    try {
        $pdo = getAdminDbConnection();
        $tableName = "report_settlement_{$userId}";
        
        $createTableSQL = "CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `settlement_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `settlement_start_date` date,
            `settlement_end_date` date,
            `deposit_date` date,
            `total_amount` decimal(10,2),
            `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `transaction_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `order_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `merchant_order_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `adjustment_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `shipment_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `marketplace_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `shipment_fee_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `shipment_fee_amount` decimal(10,2),
            `order_fee_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `order_fee_amount` decimal(10,2),
            `fulfillment_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `posted_date` datetime,
            `order_item_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `merchant_order_item_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `merchant_adjustment_item_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `sku` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `quantity_purchased` decimal(10,2),
            `price_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `price_amount` decimal(10,2),
            `item_related_fee_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `item_related_fee_amount` decimal(10,2),
            `misc_fee_amount` decimal(10,2),
            `other_fee_amount` decimal(10,2),
            `other_fee_reason_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `promotion_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `promotion_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `promotion_amount` decimal(10,2),
            `direct_payment_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            `direct_payment_amount` decimal(10,2),
            `other_amount` decimal(10,2),
            `product_id` int(11),
            `date_uploaded` datetime DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_hash` (`hash`),
            KEY `idx_settlement_id` (`settlement_id`),
            KEY `idx_sku` (`sku`),
            KEY `idx_product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($createTableSQL);
        return true;
        
    } catch (Exception $e) {
        error_log("Errore creazione tabella settlement per user {$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Crea tabelle settlement per tutti gli utenti esistenti
 */
function createSettlementTablesForExistingUsers() {
    try {
        $pdo = getAdminDbConnection();
        $stmt = $pdo->query("SELECT id FROM users WHERE is_active = 1");
        $users = $stmt->fetchAll();
        
        $created = 0;
        $errors = 0;
        $debugMessages = [];
        
        foreach ($users as $user) {
            $debugMessages[] = "Processando user ID: {$user['id']}";
            if (createUserSettlementTable($user['id'])) {
                $created++;
                $debugMessages[] = "✓ Tabella report_settlement_{$user['id']} creata";
            } else {
                $errors++;
                $debugMessages[] = "✗ Errore creazione tabella per user {$user['id']}";
            }
        }
        
        return ['created' => $created, 'errors' => $errors, 'debug' => $debugMessages];
        
    } catch (Exception $e) {
        error_log("Errore creazione batch tabelle settlement: " . $e->getMessage());
        return false;
    }
}

/**
 * Unisce prodotti (merge product_id)
 */
function mergeProducts($userId, $sourceSku, $targetProductId) {
    try {
        $pdo = getAdminDbConnection();
        $pdo->beginTransaction();
        
        $tableName = "report_settlement_{$userId}";
        
        // Verifica che il prodotto target esista
        $stmt = $pdo->prepare("SELECT id, nome FROM products WHERE id = ? AND user_id = ?");
        $stmt->execute([$targetProductId, $userId]);
        $targetProduct = $stmt->fetch();
        
        if (!$targetProduct) {
            throw new Exception('Prodotto target non trovato');
        }
        
        // Trova il prodotto sorgente dal SKU
        $stmt = $pdo->prepare("SELECT DISTINCT product_id FROM `{$tableName}` WHERE sku = ? AND product_id IS NOT NULL LIMIT 1");
        $stmt->execute([$sourceSku]);
        $sourceProductId = $stmt->fetchColumn();
        
        if ($sourceProductId && $sourceProductId != $targetProductId) {
            // Aggiorna tutti gli SKU del prodotto sorgente
            $stmt = $pdo->prepare("UPDATE `{$tableName}` SET product_id = ? WHERE product_id = ?");
            $stmt->execute([$targetProductId, $sourceProductId]);
            $updatedRows = $stmt->rowCount();
            
            // Elimina il prodotto sorgente se non ha più SKU associati
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$tableName}` WHERE product_id = ?");
            $stmt->execute([$sourceProductId]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $pdo->prepare("DELETE FROM products WHERE id = ? AND user_id = ?");
                $stmt->execute([$sourceProductId, $userId]);
            }
        } else {
            // Solo aggiorna il SKU specifico
            $stmt = $pdo->prepare("UPDATE `{$tableName}` SET product_id = ? WHERE sku = ?");
            $stmt->execute([$targetProductId, $sourceSku]);
            $updatedRows = $stmt->rowCount();
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'updated_rows' => $updatedRows,
            'target_product' => $targetProduct,
            'message' => "Merge completato! {$updatedRows} righe aggiornate al prodotto '{$targetProduct['nome']}'"
        ];
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Crea tabella settlement per utente se non esiste
 */
function createSettlementTableForUser($userId) {
    try {
        $pdo = getAdminDbConnection();
        $tableName = "report_settlement_{$userId}";
        
        // Controlla se esiste
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = ?
        ");
        $stmt->execute([$tableName]);
        
        if ($stmt->fetchColumn() == 0) {
            // Crea tabella copiando schema completo da report_settlement_1
            $sql = "CREATE TABLE `{$tableName}` LIKE `report_settlement_1`";
            $pdo->exec($sql);
            
            // Aggiorna user_id default
            $alterSql = "ALTER TABLE `{$tableName}` CHANGE `user_id` `user_id` int(11) NOT NULL DEFAULT {$userId}";
            $pdo->exec($alterSql);
            return true;
        }
        return false; // Già esisteva
        
    } catch (Exception $e) {
        error_log("Errore creazione tabella settlement_{$userId}: " . $e->getMessage());
        return false;
    }
}

/**
 * Log operazioni admin nel sistema centralizzato
 */
function logAdminOperation($operation, $userId = null, $message = '', $context = []) {
    // Migrato a CentralLogger - non più su file disco
    CentralLogger::log('admin', 'INFO', 
        sprintf('[%s] %s', $operation, $message),
        array_merge(['user_id' => $userId], $context)
    );
}
?>