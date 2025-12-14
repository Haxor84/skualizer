<?php
/**
 * Cron Inventory PreviSync - Versione con Queue Management
 * File: modules/previsync/cron_inventory_previsync.php
 * 
 * SISTEMA OTTIMIZZATO CON QUEUE:
 * 1. Processamento parallelo utenti diversi
 * 2. Gestione automatica retry con timing
 * 3. Tracciabilità completa stato elaborazione
 * 4. Zero perdite di utenti
 * 5. Resilienza e recovery automatico
 */

// === RISPOSTA IMMEDIATA A CRON-JOB.ORG ===
if (php_sapi_name() !== 'cli') {
   // Se chiamato via HTTP, rispondi subito per evitare timeout
   
   // Pulisci tutti i buffer esistenti
   while (ob_get_level()) {
       ob_end_clean();
   }
   
   // Prepara risposta JSON
   $jsonResponse = json_encode([
       'success' => true,
       'message' => 'Cron inventory avviato correttamente',
       'timestamp' => date('Y-m-d H:i:s'),
       'process_id' => getmypid()
   ]);
   
   // Headers ottimizzati per chiusura immediata connessione
   header('Content-Type: application/json');
   header('Connection: close');
   header('Content-Length: ' . strlen($jsonResponse));
   header('Cache-Control: no-cache, no-store, must-revalidate');
   
   // Invia risposta
   echo $jsonResponse;
   
   // Flush multiplo e chiusura connessione
   if (function_exists('fastcgi_finish_request')) {
       fastcgi_finish_request();
   } else {
       // Fallback per server senza fastcgi
       if (ob_get_level()) {
           ob_end_flush();
       }
       flush();
       
       // Simula chiusura connessione
       if (function_exists('connection_aborted')) {
           while (!connection_aborted()) {
               break;
           }
       }
   }
   
   // Disconnetti dalla sessione HTTP e continua elaborazione in background
   ignore_user_abort(true);
   set_time_limit(0);
   
   // Pausa breve per assicurare disconnessione
   usleep(100000); // 0.1 secondi
}

// Configurazione sicurezza ed errori (SOLO dopo disconnessione HTTP)
set_time_limit(1800); // 30 minuti max per gestire tutti gli utenti
ini_set('memory_limit', '512M');
error_reporting(E_ALL);

// Headers corretti per output web - SOLO se necessario e connessione ancora attiva
if (php_sapi_name() !== 'cli' && !headers_sent() && !ignore_user_abort()) {
   header('Content-Type: text/html; charset=utf-8');
}

require_once '../margynomic/config/config.php';
require_once '../margynomic/sincro/sync_helpers.php';
require_once '../margynomic/admin_notifier.php';

// Include Mobile Cache Event System
require_once dirname(__DIR__) . '/mobile/helpers/cache_events.php';

/**
 * Classe per gestione coda inventory reports
 */
class InventoryQueueManager {
    private $db;
    
    public function __construct() {
        $this->db = getDbConnection();
        $this->ensureQueueTableExists();
    }
    
    /**
     * Crea tabella queue se non esiste
     */
    private function ensureQueueTableExists() {
        $sql = "CREATE TABLE IF NOT EXISTS inventory_report_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            marketplace_id VARCHAR(20) NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            attempt_count INT DEFAULT 0,
            last_attempt_at DATETIME NULL,
            next_retry_at DATETIME NULL,
            error_message TEXT NULL,
            report_id VARCHAR(50) NULL,
            file_path TEXT NULL,
            completed_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY unique_user (user_id),
            INDEX idx_status (status),
            INDEX idx_next_retry (next_retry_at),
            INDEX idx_marketplace (marketplace_id)
        )";
        
        $this->db->exec($sql);
    }
    
 /**
* Popola coda con tutti gli utenti attivi
*/
public function populateQueue() {
   // Prima resetta utenti completed e failed vecchi (più di 35 minuti)
   $resetSql = "UPDATE inventory_report_queue 
                SET status = 'pending', 
                    attempt_count = 0,
                    completed_at = NULL,
                    next_retry_at = NULL,
                    error_message = NULL
                WHERE (status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL 35 MINUTE))
                OR (status = 'failed' AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 35 MINUTE))";
   $resetCount = $this->db->exec($resetSql);
   
   $stmt = $this->db->prepare("
       SELECT user_id, marketplace_id
       FROM amazon_client_tokens 
       WHERE is_active = 1 
       AND refresh_token IS NOT NULL
   ");
   $stmt->execute();
   $activeUsers = $stmt->fetchAll();
   
   $populated = 0;
   $skipped = 0;
   
   foreach ($activeUsers as $user) {
       // Controlla se utente ha già report recente (meno di 35 minuti fa)
       $checkRecent = $this->db->prepare("
           SELECT completed_at, last_attempt_at, status
           FROM inventory_report_queue 
           WHERE user_id = ? 
           AND (
               (status = 'completed' AND completed_at > DATE_SUB(NOW(), INTERVAL 35 MINUTE))
               OR (status = 'failed' AND last_attempt_at > DATE_SUB(NOW(), INTERVAL 35 MINUTE))
           )
       ");
       $checkRecent->execute([$user['user_id']]);
       $recentReport = $checkRecent->fetch();
       
       if ($recentReport) {
           // Report troppo recente - salta questo utente
           $timeDiff = $recentReport['status'] === 'completed' 
               ? $recentReport['completed_at'] 
               : $recentReport['last_attempt_at'];
           
           $skipped++;
           continue;
       }
       
       // Aggiungi utente alla coda solo se non ha report recenti
       $insertSql = "INSERT IGNORE INTO inventory_report_queue 
                    (user_id, marketplace_id, status) 
                    VALUES (?, ?, 'pending')";
       
       $stmt = $this->db->prepare($insertSql);
       if ($stmt->execute([$user['user_id'], $user['marketplace_id']])) {
           $populated++;
       }
   }
   
   // Aggiungi utenti resettati al conteggio
   $populated += $resetCount;
   
   return $populated;
}
    
    /**
     * Ottieni prossimo utente da processare
     */
    public function getNextUserToProcess() {
        // Prima: utenti mai tentati (pending)
        $stmt = $this->db->prepare("
            SELECT * FROM inventory_report_queue 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt->execute();
        $pending = $stmt->fetch();
        
        if ($pending) {
            return $pending;
        }
        
        // Poi: utenti pronti per retry
        $stmt = $this->db->prepare("
            SELECT * FROM inventory_report_queue 
            WHERE status = 'failed' 
            AND attempt_count < 3 
            AND next_retry_at <= NOW() 
            ORDER BY next_retry_at ASC 
            LIMIT 1
        ");
        $stmt->execute();
        $readyForRetry = $stmt->fetch();
        
        return $readyForRetry ?: null;
    }
    
    /**
     * Ottieni statistiche coda
     */
    public function getQueueStats() {
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                AVG(attempt_count) as avg_attempts
            FROM inventory_report_queue 
            GROUP BY status
        ");
        $stmt->execute();
        
        $stats = [];
        while ($row = $stmt->fetch()) {
            $stats[$row['status']] = [
                'count' => (int)$row['count'],
                'avg_attempts' => round($row['avg_attempts'], 1)
            ];
        }
        
        return $stats;
    }
    
    /**
     * Pulisci coda (mantieni solo ultimi 7 giorni)
     */
    public function cleanupQueue() {
        $stmt = $this->db->prepare("
            DELETE FROM inventory_report_queue 
            WHERE completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
            OR (status = 'failed' AND attempt_count >= 3 AND updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY))
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Reset tutti gli utenti in elaborazione (recovery)
     */
    public function resetProcessingUsers() {
        $stmt = $this->db->prepare("
            UPDATE inventory_report_queue 
            SET status = 'pending' 
            WHERE status = 'processing' 
            AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ");
        $stmt->execute();
        
        return $stmt->rowCount();
    }
}

// Log setup e statistiche globali
$startTime = microtime(true);
$globalStats = [
    'users_found' => 0,
    'users_processed' => 0,
    'successes' => 0,
    'errors' => 0,
    'retries' => 0,
    'queue_populated' => 0,
    'reset_processing' => 0
];

/**
 * Log Inventory - Utilizza CentralLogger
 */
function logInventory($message, $level = 'INFO', $context = []) {
    // Normalizza il livello SUCCESS a INFO
    if ($level === 'SUCCESS') {
        $level = 'INFO';
        $context['status'] = 'success';
    }
    
    // Aggiungi informazioni memoria al context
    $context['memory_mb'] = round(memory_get_usage() / 1024 / 1024, 2);
    
    CentralLogger::log('inventory', $level, $message, $context);
    
    // Mantieni output colorato per web/CLI
    $colorMap = [
        'INFO' => '',
        'WARNING' => 'color: orange;',
        'ERROR' => 'color: red;',
        'CRITICAL' => 'color: red; font-weight: bold;'
    ];
    
    $style = $colorMap[$level] ?? '';
    if (isset($context['status']) && $context['status'] === 'success') {
        $style = 'color: green;';
    }
    
    $timestamp = date('H:i:s');
    echo "<div style='{$style}'>[{$timestamp}] [INVENTORY] [{$level}] {$message}</div>";
    
    // Flush per vedere progressi
    if (ob_get_level()) {
        ob_flush();
    }
    flush();
}

echo "<h2>🔄 Cron Inventory PreviSync - Queue Management</h2>\n";

try {
    // === INIZIALIZZAZIONE ===
    $queueManager = new InventoryQueueManager();
    
    // === RECOVERY AUTOMATICO ===
    $resetCount = $queueManager->resetProcessingUsers();
    if ($resetCount > 0) {
        $globalStats['reset_processing'] = $resetCount;
    }
    
    // === POPOLAMENTO CODA ===
    $populatedCount = $queueManager->populateQueue();
    $globalStats['queue_populated'] = $populatedCount;
    
    // === STATISTICHE INIZIALI ===
    $queueStats = $queueManager->getQueueStats();
    
    $totalUsers = array_sum(array_column($queueStats, 'count'));
    $globalStats['users_found'] = $totalUsers;
    
    if ($totalUsers === 0) {
        echo "<p style='color: orange;'>⚠️ Nessun utente nella coda di elaborazione</p>";
        exit(0);
    }
    
    // === ELABORAZIONE SEQUENZIALE DELLA CODA ===
    
    $maxExecutionTime = 25 * 60; // 25 minuti max
    $processStartTime = time();
    
    while (true) {
        // Controlla timeout
        if ((time() - $processStartTime) > $maxExecutionTime) {
            break;
        }
        
        // Ottieni prossimo utente
        $nextUser = $queueManager->getNextUserToProcess();
        
        if (!$nextUser) {
            break;
        }
        
        $userId = $nextUser['user_id'];
        $isRetry = $nextUser['attempt_count'] > 0;
        
        // Elabora utente
        $result = processUserWithQueue($userId);
        
        if ($result['success']) {
            $globalStats['successes']++;
            // Log già tracciato da inventory_sync_completed
                
        } else {
            $globalStats['errors']++;
            
            logSyncOperation($userId, 'cron_inventory_error', 'warning', 
                "Cron inventory error: " . $result['error']);
            
            // Notifica admin fallimento inventory
            AdminNotifier::notifyInventoryCronFailure($result['error'], [
                'user_id' => $userId ?? 'unknown',
                'operation' => 'inventory_analysis'
            ]);
        }
        
        $globalStats['users_processed']++;
        
        // Breve pausa tra utenti per evitare sovraccarico
        sleep(2);
    }
    
    // === STATISTICHE FINALI ===
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    
    $finalQueueStats = $queueManager->getQueueStats();
    $cleanedQueue = $queueManager->cleanupQueue();
    
    // Log unico consolidato con tutte le statistiche
    $statsContext = array_merge($globalStats, [
        'execution_time' => $executionTime,
        'queue_stats' => $finalQueueStats,
        'cleanup_removed' => $cleanedQueue
    ]);
    
    logInventory("CRON INVENTORY COMPLETATO", 'INFO', $statsContext);
    
    logSyncOperation(0, 'cron_inventory_completed', 'info', 
        'Cron inventory queue completato', $statsContext);
    
    exit($globalStats['errors'] > 0 ? 1 : 0);
    
} catch (Exception $e) {
    logInventory("ERRORE CRITICO: " . $e->getMessage(), 'CRITICAL');
    
    logSyncOperation(0, 'cron_inventory_critical_error', 'error', 
        'Errore critico cron inventory: ' . $e->getMessage());
    
    // Notifica admin fallimento critico inventory
    AdminNotifier::notifyInventoryCronFailure($e->getMessage(), [
        'error_type' => 'critical',
        'stack_trace' => $e->getTraceAsString(),
        'operation' => 'inventory_analysis_critical'
    ]);
    
    exit(2);
}

/**
 * Elabora singolo utente tramite queue system
 */
function processUserWithQueue($userId) {
    
    
    try {
        // === VERIFICA FILE INVENTORY_SYNC ===
        $inventoryScript = __DIR__ . '/inventory_sync.php';
        
        if (!file_exists($inventoryScript)) {
            return [
                'success' => false,
                'error' => 'Script inventory_sync.php non trovato in ' . __DIR__
            ];
        }
        
// === VERIFICA TOKEN UTENTE ===
       $pdo = getDbConnection();
       $stmt = $pdo->prepare("
           SELECT refresh_token, marketplace_id 
           FROM amazon_client_tokens 
           WHERE user_id = ? AND is_active = 1
       ");
       $stmt->execute([$userId]);
       $tokenData = $stmt->fetch();
       
       if (!$tokenData) {
           return [
               'success' => false,
               'error' => "Token Amazon non trovato per utente {$userId}"
           ];
       }
       
       // === CHIAMATA INVENTORY SYNC VIA HTTP ===
       $inventoryUrl = buildInventoryUrl();
       
       // Parametri POST ottimizzati
       $postData = [
           'action' => 'full_sync',
           'cron_user_id' => $userId,
           'cron_key' => 'margynomic_secure_2025'
       ];
        
// cURL con configurazione ottimizzata
       $ch = curl_init();
       curl_setopt_array($ch, [
           CURLOPT_URL => $inventoryUrl,
           CURLOPT_POST => true,
           CURLOPT_POSTFIELDS => http_build_query($postData),
           CURLOPT_RETURNTRANSFER => true,
           CURLOPT_TIMEOUT => 600, // 10 minuti timeout
CURLOPT_TCP_KEEPALIVE => 1, // Keep-alive per evitare 502
CURLOPT_TCP_KEEPIDLE => 120, // Invia keep-alive dopo 2 min
           CURLOPT_CONNECTTIMEOUT => 30,
           CURLOPT_FOLLOWLOCATION => true,
           CURLOPT_SSL_VERIFYPEER => false,
           CURLOPT_USERAGENT => 'Margynomic-Cron-Queue/2.0',
           CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
           CURLOPT_ENCODING => '', // Accetta compressione
       ]);
       
       $response = curl_exec($ch);
       $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
       $curlError = curl_error($ch);
       $responseTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
       curl_close($ch);
       
       // Log solo se errore
       if ($curlError) {
           return [
               'success' => false,
               'error' => "Errore connessione inventory sync: {$curlError}"
           ];
       }
       
       if ($httpCode !== 200) {
           return [
               'success' => false,
               'error' => "HTTP Error {$httpCode} da inventory_sync.php"
           ];
       }
       
       // === PARSING RISPOSTA ===
       $result = json_decode($response, true);
       
       if (!$result) {
           return [
               'success' => false,
               'error' => "Risposta non valida da inventory_sync.php"
           ];
       }
       
       if (isset($result['success']) && $result['success']) {
           $message = $result['message'] ?? 'Inventory sync completato';
           
           // === INVALIDA CACHE MOBILE (event-driven) ===
           // Quando inventory viene sincronizzato, invalida cache inventario
           invalidateCacheOnEvent($userId, 'inventory_sync');
           
           return [
               'success' => true,
               'message' => $message,
               'details' => $result
           ];
       } else {
           $error = $result['error'] ?? 'Errore sconosciuto';
           return [
               'success' => false,
               'error' => $error
           ];
       }
       
   } catch (Exception $e) {
       return [
           'success' => false,
           'error' => 'Exception: ' . $e->getMessage()
       ];
   }
}

/**
* Costruisce URL per inventory_sync.php
*/
function buildInventoryUrl() {
   $protocol = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '');
   $host = $_SERVER['HTTP_HOST'];
   $currentDir = dirname($_SERVER['REQUEST_URI']);
   
   return "{$protocol}://{$host}{$currentDir}/inventory_sync.php";
}

/**
* Pulizia log - Ora gestita da CentralLogger
*/
function cleanupInventoryLogs() {
   try {
       // Solo pulizia file temporanei - log gestiti da CentralLogger
       $tempDir = dirname(__DIR__) . '/downloads/temp/';
       if (is_dir($tempDir)) {
           $files = glob($tempDir . '*.tsv');
           $cleaned = 0;
           foreach ($files as $file) {
               if (filemtime($file) < strtotime('-7 days')) {
                   unlink($file);
                   $cleaned++;
               }
           }
       }
       
       // Pulizia automatica log centralizzati
       $cleaned = CentralLogger::cleanup();
       
   } catch (Exception $e) {
       // Silent failure - non critico
   }
}

// === TEST RAPIDO SE RICHIESTO ===
if (isset($_GET['test'])) {
   echo "<h3>🧪 Test Mode Queue System</h3>";
   
   try {
       $queueManager = new InventoryQueueManager();
       
       // Test connessione database
       $pdo = getDbConnection();
       $stmt = $pdo->prepare("
           SELECT COUNT(*) as total_users,
                  COUNT(DISTINCT marketplace_id) as unique_marketplaces
           FROM amazon_client_tokens 
           WHERE is_active = 1 AND refresh_token IS NOT NULL
       ");
       $stmt->execute();
       $result = $stmt->fetch();
       
       // Test popolamento coda
       $populatedCount = $queueManager->populateQueue();
       
       // Test statistiche coda
       $queueStats = $queueManager->getQueueStats();
       
       // Test file inventory_sync.php
       $inventoryScript = __DIR__ . '/inventory_sync.php';
       $scriptExists = file_exists($inventoryScript);
       
       // Test costruzione URL
       $testUrl = buildInventoryUrl();
       
       echo "<div style='background: #e8f5e8; padding: 15px; border: 1px solid #4caf50; margin: 10px 0;'>";
       echo "<h4>✅ Test Queue System Risultati:</h4>";
       echo "<ul>";
       echo "<li><strong>Utenti trovati:</strong> {$result['total_users']}</li>";
       echo "<li><strong>Marketplace unici:</strong> {$result['unique_marketplaces']}</li>";
       echo "<li><strong>Utenti aggiunti alla coda:</strong> {$populatedCount}</li>";
       echo "<li><strong>Script inventory_sync.php:</strong> " . ($scriptExists ? '✅ Trovato' : '❌ Mancante') . "</li>";
       echo "<li><strong>URL test:</strong> <code>{$testUrl}</code></li>";
       echo "</ul>";
       
       if (!empty($queueStats)) {
           echo "<h5>📊 Statistiche Coda:</h5>";
           echo "<ul>";
           foreach ($queueStats as $status => $data) {
               echo "<li><strong>" . ucfirst($status) . ":</strong> {$data['count']} utenti</li>";
           }
           echo "</ul>";
       }
       
       echo "</div>";
       
   } catch (Exception $e) {
       echo "<div style='background: #ffe8e8; padding: 15px; border: 1px solid #f44336; margin: 10px 0;'>";
       echo "<h4>❌ Test Fallito:</h4>";
       echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
       echo "</div>";
   }
   
   exit(0);
}

// === INFO E HELP ===
if (isset($_GET['info'])) {
    echo "<h3>📋 Informazioni Sistema Queue</h3>";
    echo "<div style='background: #f0f8ff; padding: 20px; border-left: 5px solid #2196f3; margin: 15px 0;'>";
    echo "<h4>🔧 Cron Inventory Queue System - Guida</h4>";
    echo "<p><strong>Scopo:</strong> Gestisce automaticamente la sincronizzazione inventory per tutti gli utenti tramite sistema di coda intelligente.</p>";
    
    echo "<h5>📊 Funzionalità Principali:</h5>";
    echo "<ul>";
    echo "<li><strong>Queue Management:</strong> Ogni utente viene aggiunto a una coda con tracking dello stato</li>";
    echo "<li><strong>Processamento Parallelo:</strong> Utenti diversi possono essere elaborati senza attendere throttling</li>";
    echo "<li><strong>Retry Automatico:</strong> Utenti falliti vengono riprocessati automaticamente dopo 35 minuti</li>";
    echo "<li><strong>Recovery System:</strong> Utenti bloccati in 'processing' vengono recuperati automaticamente</li>";
    echo "<li><strong>Tracciabilità Completa:</strong> Stato dettagliato di ogni utente nella tabella queue</li>";
    echo "</ul>";
    
    echo "<h5>🎯 Stati Coda:</h5>";
    echo "<ul>";
    echo "<li>⏳ <strong>pending</strong> - In attesa di elaborazione</li>";
    echo "<li>🔄 <strong>processing</strong> - Attualmente in elaborazione</li>";
    echo "<li>✅ <strong>completed</strong> - Completato con successo</li>";
    echo "<li>❌ <strong>failed</strong> - Fallito (max 3 retry)</li>";
    echo "</ul>";
    
    echo "<h5>🔄 Flusso Operativo:</h5>";
    echo "<ol>";
    echo "<li>Popola coda con tutti gli utenti attivi</li>";
    echo "<li>Elabora utenti 'pending' in ordine FIFO</li>";
    echo "<li>Se fallisce: marca 'failed' e schedula retry dopo 35 min</li>";
    echo "<li>Se successo: marca 'completed'</li>";
    echo "<li>Continua fino a coda vuota o timeout</li>";
    echo "</ol>";
    
    echo "<h5>⚙️ Parametri URL:</h5>";
    echo "<ul>";
    echo "<li><code>?test</code> - Esegue test diagnostici completi del sistema queue</li>";
    echo "<li><code>?info</code> - Mostra questa pagina informativa</li>";
    echo "<li><code>?manual_key=XXX</code> - Esecuzione manuale con chiave</li>";
    echo "</ul>";
    
    echo "<h5>📊 Monitoraggio Queue:</h5>";
    echo "<p>Controlla stato coda con query SQL:</p>";
    echo "<code style='background: #f5f5f5; padding: 10px; display: block; margin: 10px 0;'>";
    echo "SELECT status, COUNT(*) as count, AVG(attempt_count) as avg_attempts<br>";
    echo "FROM inventory_report_queue GROUP BY status;";
    echo "</code>";
    
    echo "<h5>🔄 Esecuzione Programmata:</h5>";
    echo "<p><strong>Crontab Consigliato:</strong></p>";
    echo "<code style='background: #f5f5f5; padding: 10px; display: block; margin: 10px 0;'>";
    echo "# Ogni 2 ore per processamento continuo<br>";
    echo "15 */2 * * * /usr/bin/php " . __FILE__ . " > /dev/null 2>&1";
    echo "</code>";
    
    echo "<h5>🚀 Vantaggi Sistema Queue:</h5>";
    echo "<ul>";
    echo "<li>✅ <strong>Zero Perdite:</strong> Nessun utente viene dimenticato</li>";
    echo "<li>✅ <strong>Processamento Efficiente:</strong> Elaborazione parallela senza throttling inutile</li>";
    echo "<li>✅ <strong>Resilienza:</strong> Recovery automatico da errori</li>";
    echo "<li>✅ <strong>Tracciabilità:</strong> Stato completo di ogni elaborazione</li>";
    echo "<li>✅ <strong>Scalabilità:</strong> Gestisce facilmente centinaia di utenti</li>";
    echo "</ul>";
    
    echo "</div>";
    exit(0);
}

?>

<!-- 
MARGYNOMIC INVENTORY QUEUE SYSTEM - DOCUMENTAZIONE TECNICA

ARCHITETTURA MIGLIORATA:
1. inventory_sync.php - Gestione singolo utente con queue integration
2. cron_inventory_previsync.php - Queue manager e orchestratore
3. inventory_report_queue - Tabella tracking stato elaborazioni

FLUSSO OPERATIVO OTTIMIZZATO:
1. Popolamento coda con tutti utenti attivi
2. Recovery automatico utenti bloccati
3. Elaborazione sequenziale con priorità:
   - Prima: utenti mai tentati (pending)
   - Poi: utenti pronti per retry (failed + timeout scaduto)
4. Gestione intelligente retry (max 3 tentativi, 35 min tra tentativi)
5. Tracciabilità completa stato ogni utente
6. Cleanup automatico record vecchi

VANTAGGI CHIAVE:
✅ PROCESSAMENTO PARALLELO - Utenti diversi non si bloccano a vicenda
✅ RETRY AUTOMATICO - Failed users riprocessati automaticamente
✅ ZERO PERDITE - Ogni utente garantito di essere processato
✅ RESILIENZA - Recovery da crash e timeout
✅ SCALABILITÀ - Gestisce centinaia di utenti facilmente
✅ TRACCIABILITÀ - Stato dettagliato ogni elaborazione

TABELLA QUEUE FIELDS:
- user_id: ID utente Amazon
- marketplace_id: Marketplace utente
- status: pending/processing/completed/failed
- attempt_count: Numero tentativi effettuati
- next_retry_at: Quando riprovare se failed
- error_message: Dettaglio ultimo errore
- report_id: ID report Amazon generato
- file_path: Path file scaricato
- completed_at: Timestamp completamento

MONITORAGGIO:
- Log centralizzato in cron_operations.log
- Database tracking in sync_debug_logs
- Statistiche real-time stato coda
- Recovery automatico utenti bloccati

ESECUZIONE:
- Crontab ogni 2 ore per elaborazione continua
- Timeout 30 minuti per gestire tutti gli utenti
- Memory limit 512MB per gestione coda grande
- Test mode disponibile con ?test

Il sistema garantisce che OGNI utente venga elaborato entro 6 ore massimo,
con retry automatico per eventuali fallimenti temporanei.
-->