<?php
/**
 * Funzioni di supporto per autenticazione
 * File: auth_helpers.php
 */

/**
 * Verifica se l'utente è autenticato
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Rileva se l'utente è su dispositivo mobile
 */
function isMobileDevice() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return preg_match('/(android|iphone|ipad|ipod|webos|blackberry|windows phone)/i', $userAgent);
}

/**
 * Verifica se l'utente è admin
 */
function isAdmin() {
    return isLoggedIn() && ($_SESSION['user_role'] === 'admin' || $_SESSION['user_ruolo'] === 'admin');
}

/**
 * Ottieni dati utente dalla sessione
 * Se mancano dati nella sessione, li recupera dal database
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    // Verifica se mancano dati nella sessione
    $sessionIncomplete = !isset($_SESSION['user_email']) 
                      || !isset($_SESSION['user_nome']) 
                      || !isset($_SESSION['user_ruolo'])
                      || !isset($_SESSION['user_role']);
    
    // Se la sessione è incompleta, recupera i dati dal database
    if ($sessionIncomplete) {
        try {
            if (!function_exists('getDbConnection')) {
                require_once __DIR__ . '/../config/config.php';
            }
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT email, nome, ruolo, role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userData) {
                // Ripopola la sessione con i dati corretti dal database
                $_SESSION['user_email'] = $userData['email'];
                $_SESSION['user_nome'] = $userData['nome'];
                $_SESSION['user_ruolo'] = $userData['ruolo'];
                $_SESSION['user_role'] = $userData['role'];
            } else {
                // Utente non trovato nel database, invalida la sessione
                session_destroy();
                return null;
            }
        } catch (Exception $e) {
            error_log("Errore nel recupero dati utente: " . $e->getMessage());
            // In caso di errore DB, continua con i dati disponibili nella sessione
        }
    }
    
    return [
        'id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'],
        'nome' => $_SESSION['user_nome'],
        'role' => $_SESSION['user_role'],
        'ruolo' => $_SESSION['user_ruolo']
    ];
}

/**
 * Redirect con controllo autenticazione
 */
function requireAuth($redirectTo = 'login.php') {
    if (!isLoggedIn()) {
        redirect($redirectTo);
    }
}

/**
 * Redirect intelligente basato su device
 */
function redirectToDashboard() {
    if (isMobileDevice()) {
        redirect('../../mobile/Profilo.php');
    } else {
        redirect('../profilo_utente.php');
    }
}

/**
 * Redirect se già autenticato
 */
function requireGuest($redirectTo = null) {
    if (isLoggedIn()) {
        if ($redirectTo === null) {
            redirectToDashboard(); // Usa redirect intelligente
        } else {
            redirect($redirectTo);
        }
    }
}

/**
 * Redirect sicuro
 */
if (!function_exists('redirect')) {
    function redirect($url, $statusCode = 302) {
        // Previeni header injection
        $url = filter_var($url, FILTER_SANITIZE_URL);
        
        // Se URL relativo, usa percorso relativo semplice
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            // Non aggiungere BASE_URL, usa redirect relativo
            header("Location: $url", true, $statusCode);
            exit();
        }
        
        header("Location: $url", true, $statusCode);
        exit();
    }
}

/**
 * Sanitizza input
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Valida email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida password
 */
function validatePassword($password) {
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "La password deve essere di almeno " . PASSWORD_MIN_LENGTH . " caratteri";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "La password deve contenere almeno una lettera maiuscola";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "La password deve contenere almeno una lettera minuscola";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "La password deve contenere almeno un numero";
    }
    
    return $errors;
}

/**
 * Genera token sicuro
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Hash password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verifica password
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Genera CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateSecureToken();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Ottieni indirizzo IP del client
 */
function getClientIP() {
    $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            // Se ci sono più IP, prendi il primo
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Registra tentativo di login
 */
function logLoginAttempt($email, $success = false) {
    try {
        if (!function_exists('getDbConnection')) {
            require_once __DIR__ . '/../config/config.php';
        }
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (email, ip_address, success) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$email, getClientIP(), $success ? 1 : 0]);
    } catch (Exception $e) {
        error_log("Errore nel logging del tentativo di login: " . $e->getMessage());
    }
}

/**
 * Verifica se l'account è bloccato per troppi tentativi
 */
function isAccountLocked($email) {
    try {
        if (!function_exists('getDbConnection')) {
            require_once __DIR__ . '/../config/config.php';
        }
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE email = ? 
            AND success = 0 
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
        ");
        $stmt->execute([$email, LOCKOUT_TIME]);
        $result = $stmt->fetch();
        
        return $result['attempts'] >= MAX_LOGIN_ATTEMPTS;
    } catch (Exception $e) {
        error_log("Errore nel controllo blocco account: " . $e->getMessage());
        return false;
    }
}

/**
 * Pulisci tentativi di login vecchi
 */
function cleanupLoginAttempts() {
    try {
        if (!function_exists('getDbConnection')) {
            require_once __DIR__ . '/../config/config.php';
        }
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            DELETE FROM login_attempts 
            WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Errore nella pulizia tentativi di login: " . $e->getMessage());
    }
}

/**
 * Invia email con PHPMailer (wrapper centralizzato)
 */
require_once __DIR__ . '/../gestione_vendor.php';

function sendEmail($to, $subject, $htmlBody, $headers = []) {
    return inviaEmailSMTP($to, $subject, $htmlBody);
}


/**
 * Formatta messaggio di errore per display
 */
function formatErrorMessage($message) {
    return '<div class="alert alert-danger">' . sanitizeInput($message) . '</div>';
}

/**
 * Formatta messaggio di successo per display
 */
function formatSuccessMessage($message) {
    return '<div class="alert alert-success">' . sanitizeInput($message) . '</div>';
}

/**
 * Ottieni messaggio flash dalla sessione
 */
function getFlashMessage($type = null) {
    if ($type) {
        $message = $_SESSION["flash_$type"] ?? null;
        unset($_SESSION["flash_$type"]);
        return $message;
    }
    
    $messages = [];
    foreach (['success', 'error', 'warning', 'info'] as $msgType) {
        if (isset($_SESSION["flash_$msgType"])) {
            $messages[$msgType] = $_SESSION["flash_$msgType"];
            unset($_SESSION["flash_$msgType"]);
        }
    }
    
    return $messages;
}

/**
 * Imposta messaggio flash
 */
function setFlashMessage($type, $message) {
    $_SESSION["flash_$type"] = $message;
}

/**
 * Rate limiting per azioni sensibili
 */
function checkRateLimit($action, $limit = 5, $window = 300) {
    $key = "rate_limit_{$action}_" . getClientIP();
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset_time' => time() + $window];
    }
    
    $data = $_SESSION[$key];
    
    // Reset se finestra temporale scaduta
    if (time() > $data['reset_time']) {
        $_SESSION[$key] = ['count' => 1, 'reset_time' => time() + $window];
        return true;
    }
    
    // Incrementa contatore
    $_SESSION[$key]['count']++;
    
    return $_SESSION[$key]['count'] <= $limit;
}
?>

