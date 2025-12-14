<?php

/**
 * Callback Amazon SP-API
 * File: sincro/amazon_callback.php
 * Gestisce processo completo: token → download report → salvataggio
 */

// Debug per vedere gli errori
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// CONFIGURAZIONE SESSIONI IDENTICA A oauth_controller.php
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'None');
session_start();

require_once '../config/config.php';
require_once 'api_config.php';
require_once 'sync_helpers.php';

/**
 * Log Callback - Utilizza CentralLogger  
 */
function logCallbackOperation($message, $level = 'INFO', $context = []) {
    CentralLogger::log('oauth', $level, $message, $context);
}

// ROUTER: OAuth vs Download diretto
if (isset($_GET['code']) || isset($_GET['spapi_oauth_code'])) {
    // FLUSSO OAUTH: Recupera user_id dal database
    $isOAuthCallback = true;
    $authCode = $_GET['code'] ?? $_GET['spapi_oauth_code'];
    $state = $_GET['state'] ?? '';
    
    // Recupera user_id dal database usando state
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT user_id FROM oauth_states WHERE state_token = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute([$state]);
    $result = $stmt->fetch();
    
    if (!$result) {
        die('State OAuth non valido o scaduto');
    }
    
    $userId = $result['user_id'];
$_SESSION['user_id'] = $userId;

// Ripristina dati utente completi nella sessione
$userStmt = $pdo->prepare("SELECT nome, email, ruolo FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch();

if ($userData) {
    $_SESSION['user_nome'] = $userData['nome'];
    $_SESSION['user_email'] = $userData['email'];
    $_SESSION['user_ruolo'] = $userData['ruolo'];
}
    
    // Elimina state usato
    $stmt = $pdo->prepare("DELETE FROM oauth_states WHERE state_token = ?");
    $stmt->execute([$state]);
    
} else {
    // FLUSSO DOWNLOAD: Utente già loggato
    $isOAuthCallback = false;
    
    if (!isset($_SESSION['user_id'])) {
       header('Location: ../login/login.php');
       exit;
    }
    $userId = $_SESSION['user_id'];
}

echo "<h3>DEBUG FLUSSO</h3>";
echo "isOAuthCallback: " . ($isOAuthCallback ? 'TRUE' : 'FALSE') . "<br>";
echo "authCode presente: " . (isset($authCode) ? 'YES' : 'NO') . "<br>";
echo "GET spapi_oauth_code: " . ($_GET['spapi_oauth_code'] ?? 'MISSING') . "<br>";
echo "userId: " . $userId . "<br>";
echo "<hr>";
echo "<h3>DEBUG FUNZIONI</h3>";
echo "exchangeCodeForRefreshToken esiste: " . (function_exists('exchangeCodeForRefreshToken') ? 'YES' : 'NO') . "<br>";
echo "saveRefreshTokenToDb esiste: " . (function_exists('saveRefreshTokenToDb') ? 'YES' : 'NO') . "<br>";

// Test chiamata OAuth
if ($isOAuthCallback) {
    echo "<h3>ESECUZIONE OAUTH</h3>";
    echo "Tentativo scambio code...<br>";
    
    $tokenData = exchangeCodeForRefreshToken($authCode);
    echo "Risultato tokenData: " . ($tokenData ? 'SUCCESS' : 'FAILED') . "<br>";
    
    if ($tokenData) {
        echo "Refresh token presente: " . (isset($tokenData['refresh_token']) ? 'YES' : 'NO') . "<br>";
        if (isset($tokenData['refresh_token'])) {
            echo "Tentativo salvataggio...<br>";
            $saved = saveRefreshTokenToDb($userId, $tokenData['refresh_token']);
            echo "Salvataggio risultato: " . ($saved ? 'SUCCESS' : 'FAILED') . "<br>";
        }
    }
}
echo "<hr>";

// ROUTER: OAuth vs Download diretto
if (isset($_GET['code'])) {
    // FLUSSO OAUTH: Nuovo utente da Amazon
    $isOAuthCallback = true;
    $authCode = $_GET['code'] ?? $_GET['spapi_oauth_code'];
    $state = $_GET['state'] ?? '';
    
    // Verifica state CSRF contro sessione
$sessionState = $_SESSION['amazon_oauth_state'] ?? '';
$sessionUserId = $_SESSION['amazon_oauth_user_id'] ?? 0;

if (empty($sessionState) || $state !== $sessionState) {
    die('State OAuth non valido - possibile attacco CSRF');
}

if ($sessionUserId <= 0) {
    die('User ID mancante nella sessione OAuth');
}

// Usa user_id dalla sessione, NON dal state
$userId = $sessionUserId;

echo "DEBUG: State verificato correttamente. User ID: " . $userId . "<br>";
    
    // Imposta sessione per il resto del processo
    $_SESSION['user_id'] = $userId;
    
} else {
    // FLUSSO DOWNLOAD: Utente già loggato
    $isOAuthCallback = false;
    
    // Verifica autenticazione esistente
    if (!isset($_SESSION['user_id'])) {
       header('Location: ../login/login.php');
       exit;
    }
}



// FUNZIONE OAUTH: Scambia code con refresh token
function exchangeCodeForRefreshToken($authCode) {
    $postData = [
        'grant_type' => 'authorization_code',
        'code' => $authCode,
        'client_id' => AMAZON_CLIENT_ID,
        'client_secret' => AMAZON_CLIENT_SECRET,
        'redirect_uri' => BASE_URL . '/sincro/amazon_callback.php'
    ];
    
    $ch = curl_init('https://api.amazon.com/auth/o2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200) ? $response : false;
}

// FUNZIONE: Salva refresh token in database
function saveRefreshTokenToDb($userId, $refreshToken) {
    try {
        $pdo = getDbConnection();
        
        // Elimina token precedenti
        $deleteStmt = $pdo->prepare("DELETE FROM amazon_client_tokens WHERE user_id = ?");
        $deleteStmt->execute([$userId]);
        
        // Inserisci nuovo token (created_at e updated_at sono automatici)
        $stmt = $pdo->prepare("
            INSERT INTO amazon_client_tokens 
            (user_id, marketplace_id, refresh_token, is_active) 
            VALUES (?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([$userId, 'APJ6JRA9NG5V4', $refreshToken, 1]);
        echo "DEBUG: Token save result: " . ($result ? 'SUCCESS' : 'FAILED') . "<br>";
        return $result;
    } catch (Exception $e) {
        echo "DEBUG: Database error: " . $e->getMessage() . "<br>";
        return false;
    }
}





$userId = $_SESSION['user_id'];
$results = [];
$step = 1;

// GESTIONE OAUTH SE NECESSARIO
if ($isOAuthCallback) {
    // STEP OAUTH: Scambia code con refresh token
    $tokenData = exchangeCodeForRefreshToken($authCode);
    
    if ($tokenData && isset($tokenData['refresh_token'])) {
        // Salva token in database
        // Log centralizzato callback
logCallbackOperation("Tentativo salvataggio token per user_id: " . $userId, 'INFO');
$saved = saveRefreshTokenToDb($userId, $tokenData['refresh_token']);
logCallbackOperation("Risultato salvataggio: " . ($saved ? 'SUCCESS' : 'FAILED'), $saved ? 'INFO' : 'ERROR');

echo "DEBUG: Tentativo salvataggio token per user_id: " . $userId . "<br>";
echo "DEBUG: Risultato salvataggio: " . ($saved ? 'SUCCESS' : 'FAILED') . "<br>";
        
        if ($saved) {
            $results['oauth'] = ['success' => true, 'message' => 'Token salvato con successo'];
        } else {
            $results['oauth'] = ['success' => false, 'error' => 'Errore salvataggio token'];
        }
    } else {
        $results['oauth'] = ['success' => false, 'error' => 'Errore scambio code con token'];
    }
    
    // Se OAuth fallisce, mostra errore e fermati
    if (!$results['oauth']['success']) {
        $step = 0; // Ferma il processo
    }
}

// STEP 1: Verifica token esistente (funziona per entrambi i flussi)
if ($step >= 1) {
    $results['step1'] = verificaToken($userId);
}

if ($results['step1']['success']) {
   // STEP 2: Ottieni access token
   $results['step2'] = ottieniAccessToken($results['step1']['refresh_token']);
   
   if ($results['step2']['success']) {
       // STEP 3: Scarica report
       $results['step3'] = scaricaReport($userId, $results['step2']['access_token']);
       $step = 4;
   } else {
       $step = 3;
   }
} else {
   $step = 2;
}

/**
* Verifica se l'utente ha un refresh token
*/
function verificaToken($userId) {
   try {
       $credentials = getAmazonCredentials($userId);
       
       if ($credentials && !empty($credentials['refresh_token'])) {
           logSyncOperation($userId, 'token_found', 'info', 'Token refresh trovato per utente da database');
           
           return [
               'success' => true,
               'refresh_token' => $credentials['refresh_token'],
               'marketplace' => $credentials['marketplace_id']
           ];
       } else {
           logSyncOperation($userId, 'token_missing', 'warning', 'Token refresh non trovato per utente');
           
           return [
               'success' => false,
               'error' => 'Token non trovato. Contatta amministratore.'
           ];
       }
       
   } catch (Exception $e) {
       logSyncOperation($userId, 'token_check_error', 'error', 'Errore verifica token: ' . $e->getMessage());
       
       return [
           'success' => false,
           'error' => 'Errore verifica token: ' . $e->getMessage()
       ];
   }
}

/**
* Converte refresh token in access token
*/
function ottieniAccessToken($refreshToken) {
   try {
       $lwaToken = getAccessToken(
           AMAZON_CLIENT_ID,
           AMAZON_CLIENT_SECRET,
           $refreshToken
       );
       
       return [
           'success' => true,
           'access_token' => $lwaToken
       ];
       
   } catch (Exception $e) {
       return [
           'success' => false,
           'error' => 'Errore token: ' . $e->getMessage()
       ];
   }
}

/**
* Scarica report settlement con firma AWS
*/
function scaricaReport($userId, $accessToken) {
   try {
       // Crea directory se non esiste
       $baseDir = __DIR__ . '/../downloads';
       $userDir = $baseDir . '/user_' . $userId;
       $reportDir = $userDir . '/GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE';
       
       if (!is_dir($reportDir)) {
           mkdir($reportDir, 0755, true);
       }
       
       // Lista report con firma AWS
       $listJson = signedRequest(
           method:      'GET',
           url:         'https://sellingpartnerapi-eu.amazon.com/reports/2021-06-30/reports'
                        . '?reportTypes=GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE'
                        . '&processingStatuses=DONE'
                        . '&pageSize=5',
           accessToken: $accessToken,
           awsKey:      AWS_ACCESS_KEY_ID,
           awsSecret:   AWS_SECRET_ACCESS_KEY
       );

       $list = json_decode($listJson, true);
       if (empty($list['reports'])) {
           return [
               'success' => false,
               'error' => 'Nessun settlement report DONE disponibile'
           ];
       }
       $report = $list['reports'][0];
       
       // Ottieni URL documento
       $docJson = signedRequest(
           method:      'GET',
           url:         'https://sellingpartnerapi-eu.amazon.com/reports/2021-06-30/documents/'
                        . $report['reportDocumentId'],
           accessToken: $accessToken,
           awsKey:      AWS_ACCESS_KEY_ID,
           awsSecret:   AWS_SECRET_ACCESS_KEY
       );
       $doc = json_decode($docJson, true);

       if (!isset($doc['url'])) {
           return [
               'success' => false,
               'error' => 'URL download non trovato nella risposta'
           ];
       }

       $downloadUrl = $doc['url'];
       $compression = $doc['compressionAlgorithm'] ?? 'NONE';
       
       // Scarica il file
       $fileName = date('d-m-Y') . '_GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE_' . $userId . '.tsv';
       $filePath = $reportDir . '/' . $fileName;
       
       if (strtoupper($compression) === 'GZIP') {
           $fileName .= '.gz';
           $filePath .= '.gz';
       }
       
       if (@file_put_contents($filePath, file_get_contents($downloadUrl)) === false) {
           return [
               'success' => false,
               'error' => 'Errore download file'
           ];
       }

       // Decomprimi se necessario
       if (strtoupper($compression) === 'GZIP') {
           $outPath = str_replace('.gz', '', $filePath);
           $gz  = gzopen($filePath, 'rb');
           $fp  = fopen($outPath, 'wb');
           while (!gzeof($gz)) {
               fwrite($fp, gzread($gz, 8192));
           }
           gzclose($gz);
           fclose($fp);
           unlink($filePath);
           $filePath = $outPath;
           $fileName = str_replace('.gz', '', $fileName);
       }
       
       // Usa la stessa funzione di margini.php per coerenza
       require_once '../admin/admin_helpers.php';
       createSettlementTableForUser($userId);
       
       // Importa automaticamente il file scaricato
       require_once 'settlement_parser.php';
       $parser = new SettlementParser();
       $importResult = $parser->parseAndImportSettlement($userId, $filePath);
       
       return [
           'success' => true,
           'file_path' => $filePath,
           'file_name' => $fileName,
           'file_size' => filesize($filePath),
           'report_id' => $report['reportId'],
           'import_result' => $importResult
       ];
       
   } catch (Exception $e) {
       return [
           'success' => false,
           'error' => 'Errore scaricamento report: ' . $e->getMessage()
       ];
   }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Sincronizzazione Amazon - Margynomic</title>
   <link rel="stylesheet" href="../css/margynomic.css">
   <style>
       .sync-container {
           min-height: 100vh;
           background: linear-gradient(135deg, #008CFF 0%, #00C281 100%);
           display: flex;
           align-items: center;
           justify-content: center;
           padding: 2rem;
       }
       
       .sync-card {
           background: white;
           border-radius: 20px;
           padding: 3rem;
           max-width: 600px;
           width: 100%;
           text-align: center;
           box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
       }
       
       .progress-steps {
           display: flex;
           justify-content: space-between;
           margin-bottom: 2rem;
       }
       
       .step {
           flex: 1;
           padding: 1rem;
           border-radius: 8px;
           margin: 0 0.5rem;
           font-weight: 600;
       }
       
       .step.completed {
           background: #d4edda;
           color: #155724;
       }
       
       .step.active {
           background: #008CFF;
           color: white;
       }
       
       .step.pending {
           background: #f8f9fa;
           color: #6c757d;
       }
       
       .result-box {
           background: #f8f9fa;
           border-radius: 12px;
           padding: 1.5rem;
           margin: 1rem 0;
           text-align: left;
       }
       
       .success { background: #d4edda; }
       .error { background: #f8d7da; }
       
       .file-info {
           background: #e3f2fd;
           border-radius: 8px;
           padding: 1rem;
           margin: 1rem 0;
       }
   </style>
</head>
<body>
   <div class="sync-container">
       <div class="sync-card">
           <h1>🔄 Sincronizzazione Amazon</h1>
           <p>
    <?php if ($isOAuthCallback): ?>
        🔗 Autorizzazione Amazon completata - Download automatico report per utente ID: <?php echo $userId; ?>
    <?php else: ?>
        📥 Processo automatico di download report per utente ID: <?php echo $userId; ?>
    <?php endif; ?>
</p>
           
           <!-- Progress Steps -->
           <div class="progress-steps">
               <div class="step <?php echo $step >= 2 ? 'completed' : ($step == 1 ? 'active' : 'pending'); ?>">
                   1. Verifica Token
               </div>
               <div class="step <?php echo $step >= 3 ? 'completed' : ($step == 2 ? 'active' : 'pending'); ?>">
                   2. Access Token
               </div>
               <div class="step <?php echo $step >= 4 ? 'completed' : ($step == 3 ? 'active' : 'pending'); ?>">
                   3. Download Report
               </div>
           </div>
           
           <!-- Risultati -->
<?php foreach ($results as $stepName => $result): ?>
    <div class="result-box <?php echo $result['success'] ? 'success' : 'error'; ?>">
        <strong>
            <?php 
            if ($stepName === 'oauth') echo 'AUTORIZZAZIONE AMAZON';
            else echo strtoupper($stepName); 
            ?>:
        </strong>
        <?php echo $result['success'] ? '✅ SUCCESSO' : '❌ ERRORE'; ?>
        
        <?php if (!$result['success']): ?>
            <br><small><?php echo htmlspecialchars($result['error']); ?></small>
        <?php elseif ($stepName === 'oauth'): ?>
            <br><small><?php echo htmlspecialchars($result['message']); ?></small>
        <?php endif; ?>
                   
                   <?php if ($stepName === 'step3' && $result['success']): ?>
                       <div class="file-info">
                           <strong>📁 File scaricato:</strong><br>
                           <strong>Nome:</strong> <?php echo htmlspecialchars($result['file_name']); ?><br>
                           <strong>Dimensione:</strong> <?php echo formatFileSize($result['file_size']); ?>
                       </div>
                   <?php endif; ?>
               </div>
           <?php endforeach; ?>
           
           <script>
               // Redirect automatico dopo OAuth completato (anche senza report)
               <?php if ($isOAuthCallback && isset($results['oauth']) && $results['oauth']['success']): ?>
                   setTimeout(function() {
                       window.location.href = '../profilo_utente.php';
                   }, 5000);
               <?php elseif (isset($results['step3']) && $results['step3']['success']): ?>
                   setTimeout(function() {
                       window.location.href = '../profilo_utente.php';
                   }, 3000);
               <?php endif; ?>
           </script>
       </div>
   </div>
</body>
</html>