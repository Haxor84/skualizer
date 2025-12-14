<?php
$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);

$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);

$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);

$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);

$caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] ?? 'ENTRY POINT';
$log_entry = ['caller' => $caller, 'included' => __FILE__, 'timestamp' => time()];
$log_file = '/data/vhosts/skualizer.com/httpdocs/inclusion_log.json';
$existing_data = file_exists($log_file) ? json_decode(file_get_contents($log_file), true) ?? [] : [];
$existing_data[] = $log_entry;
file_put_contents($log_file, json_encode($existing_data, JSON_PRETTY_PRINT), LOCK_EX);


/**
 * Self Authorization Amazon SP-API
 * File: sincro/self_authorization.php
 * 
 * Gestisce processo completo: token → download report → salvataggio
 */

// Debug per vedere gli errori
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
   header('Location: ../login/login.php');
   exit;
}

require_once '../config/config.php';
require_once 'api_config.php';
require_once 'sync_helpers.php';

function canonicalQuery(string $raw): string
{
   if ($raw === '') return '';
   parse_str($raw, $params);
   ksort($params);
   $pairs = [];
   foreach ($params as $k => $v) {
       $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
   }
   return implode('&', $pairs);
}

function signedRequest(
   string $method,
   string $url,
   string $accessToken,
   string $awsKey,
   string $awsSecret,
   string $region  = 'eu-west-1',
   string $service = 'execute-api',
   string $payload = ''
): string
{
   $u      = parse_url($url);
   $host   = $u['host'];
   $uri    = $u['path'] ?? '/';
   $qRaw   = $u['query'] ?? '';
   $query  = canonicalQuery($qRaw);

   /* ---------- HEADERS BASE ---------- */
   $headers = [
       'host'               => $host,
       'x-amz-access-token' => $accessToken,
       'content-type'       => 'application/json'
   ];
   if ($payload !== '') {
       $headers['content-length'] = strlen($payload);
   }

   /* ---------- x-amz-date ---------- */
   $amzDate  = gmdate('Ymd\THis\Z');
   $dateOnly = gmdate('Ymd');
   $headers['x-amz-date'] = $amzDate;

   /* ---------- CANONICAL HEADERS ---------- */
   ksort($headers);
   $canonicalHeaders = '';
   $signedHeaders    = '';
   foreach ($headers as $k => $v) {
       $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
       $signedHeaders    .= strtolower($k) . ';';
   }
   $signedHeaders = rtrim($signedHeaders, ';');

   /* ---------- HASHES ---------- */
   $payloadHash = hash('sha256', $payload);
   $canonicalRequest = implode("\n", [
       $method,
       $uri,
       $query,
       $canonicalHeaders,
       $signedHeaders,
       $payloadHash
   ]);

   $scope      = "$dateOnly/$region/$service/aws4_request";
   $stringToSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonicalRequest);

   /* ---------- SIGNATURE ---------- */
   $kDate    = hash_hmac('sha256', $dateOnly,  'AWS4' . $awsSecret, true);
   $kRegion  = hash_hmac('sha256', $region,    $kDate,   true);
   $kService = hash_hmac('sha256', $service,   $kRegion, true);
   $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
   $signature = hash_hmac('sha256', $stringToSign, $kSigning);

   $auth = 'AWS4-HMAC-SHA256 '
         . "Credential=$awsKey/$scope, "
         . "SignedHeaders=$signedHeaders, "
         . "Signature=$signature";

   /* ---------- CURL CALL ---------- */
   $curlHeaders = [];
   foreach ($headers as $k => $v) {
       $curlHeaders[] = "$k: $v";
   }
   $curlHeaders[] = "Authorization: $auth";

   $ch = curl_init($url);
   curl_setopt_array($ch, [
       CURLOPT_CUSTOMREQUEST  => $method,
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_HTTPHEADER     => $curlHeaders,
       CURLOPT_TIMEOUT        => 30
   ]);
   if ($payload !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

   $res  = curl_exec($ch);
   $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);

   if ($http < 200 || $http >= 300) {
       throw new Exception("API error HTTP $http\n$res\n");
   }
   return $res;
}

function getAccessToken(string $clientId, string $clientSecret, string $refreshToken): string
{
   $post = http_build_query([
       'grant_type'    => 'refresh_token',
       'refresh_token' => $refreshToken,
       'client_id'     => $clientId,
       'client_secret' => $clientSecret
   ]);

   $ch = curl_init('https://api.amazon.com/auth/o2/token');
   curl_setopt_array($ch, [
       CURLOPT_POST           => true,
       CURLOPT_POSTFIELDS     => $post,
       CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
       CURLOPT_RETURNTRANSFER => true,
       CURLOPT_TIMEOUT        => 30
   ]);
   $res  = curl_exec($ch);
   $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
   curl_close($ch);

   if ($http !== 200) {
       throw new Exception("LWA token error (HTTP $http)\n$res\n");
   }
   return json_decode($res, true)['access_token'];
}



$userId = $_SESSION['user_id'];
$results = [];
$step = 1;

// STEP 1: Verifica token esistente
$results['step1'] = verificaToken($userId);

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
       
       return [
           'success' => true,
           'file_path' => $filePath,
           'file_name' => $fileName,
           'file_size' => filesize($filePath),
           'report_id' => $report['reportId']
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
           <p>Processo automatico di download report per utente ID: <?php echo $userId; ?></p>
           
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
                   <strong><?php echo strtoupper($stepName); ?>:</strong>
                   <?php echo $result['success'] ? '✅ SUCCESSO' : '❌ ERRORE'; ?>
                   
                   <?php if (!$result['success']): ?>
                       <br><small><?php echo htmlspecialchars($result['error']); ?></small>
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
           
           <!-- Azioni -->
           <div style="margin-top: 2rem;">
               <a href="../profilo_utente.php" class="btn btn-primary">
                   🏠 Torna al Profilo
               </a>
               
               <?php if ($step >= 4 && $results['step3']['success']): ?>
                   <a href="self_authorization.php" class="btn btn-outline">
                       🔄 Scarica Altro Report
                   </a>
               <?php else: ?>
                   <a href="self_authorization.php" class="btn btn-outline">
                       🔄 Riprova
                   </a>
               <?php endif; ?>
           </div>
       </div>
   </div>
</body>
</html>