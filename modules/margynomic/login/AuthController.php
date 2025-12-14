<?php
require_once 'cookie_helpers.php';

/**
 * AuthController - Controller per autenticazione Margynomic
 * File: controllers/AuthController.php
 */

class AuthController {
    private $userModel;
    
    public function __construct() {
        $this->userModel = new UserModel();
    }
    
    /**
     * Gestisce registrazione utente
     */
    public function register() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['success' => false, 'message' => 'Metodo non consentito'];
        }
        
        // Verifica CSRF token
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            return ['success' => false, 'message' => 'Token di sicurezza non valido'];
        }
        
        // Rate limiting
        if (!checkRateLimit('register', 3, 300)) {
            return ['success' => false, 'message' => 'Troppi tentativi di registrazione. Riprova tra 5 minuti.'];
        }
        
        // Sanitizza e valida input
        $nome = sanitizeInput($_POST['nome'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validazioni
        $errors = [];
        
        if (empty($nome)) {
            $errors[] = 'Il nome è obbligatorio';
        }
        
        if (empty($email) || !validateEmail($email)) {
            $errors[] = 'Email non valida';
        }
        
        if (empty($password)) {
            $errors[] = 'La password è obbligatoria';
        } else {
            $passwordErrors = validatePassword($password);
            $errors = array_merge($errors, $passwordErrors);
        }
        
        if ($password !== $confirmPassword) {
            $errors[] = 'Le password non corrispondono';
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'message' => implode('<br>', $errors)];
        }
        
        // Crea utente
        $userData = [
            'nome' => $nome,
            'email' => $email,
            'password_hash' => hashPassword($password),
            'role' => 'seller',
            'ruolo' => 'user'
        ];
        
        $result = $this->userModel->createUser($userData);
        
        if ($result['success']) {
            // Log successo
            logLoginAttempt($email, true);
            
            // Imposta messaggio flash
            setFlashMessage('success', 'Registrazione completata! Ora puoi effettuare il login.');
        }
        
        return $result;
    }
    
    /**
     * Gestisce login utente
     */
    public function login() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['success' => false, 'message' => 'Metodo non consentito'];
        }
        
        // Verifica CSRF token
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            return ['success' => false, 'message' => 'Token di sicurezza non valido'];
        }
        
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']);
        
        // Validazioni base
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Email e password sono obbligatori'];
        }
        
        // Verifica se account è bloccato
if (isAccountLocked($email)) {
    return ['success' => false, 'message' => 'Account temporaneamente bloccato per troppi tentativi falliti. <a href="forgot_password.php">Reimposta la password</a> per sbloccare l\'account.'];
}
        
        // Rate limiting
        if (!checkRateLimit('login', 10, 300)) {
            return ['success' => false, 'message' => 'Troppi tentativi di login. Riprova tra 5 minuti.'];
        }
        
        // Valida credenziali
        $result = $this->userModel->validateCredentials($email, $password);
        
        // Log tentativo
        logLoginAttempt($email, $result['success']);
        
        if (!$result['success']) {
            return $result;
        }
        
        // Avvia sessione utente
        $this->startUserSession($result['user'], $rememberMe);
        
        // Aggiorna ultimo accesso
        $this->userModel->updateLastLogin($result['user']['id']);
        
        return ['success' => true, 'message' => 'Login effettuato con successo'];
    }
    
    /**
     * Gestisce logout utente
     */
    public function logout() {
        // Distruggi sessione
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            
            // Elimina cookie di sessione
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            clearRememberMeCookie();
session_destroy();

        }
        
        return ['success' => true, 'message' => 'Logout effettuato con successo'];
    }
    
    /**
     * Gestisce richiesta reset password
     */
    public function forgotPassword() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['success' => false, 'message' => 'Metodo non consentito'];
        }
        
        // Verifica CSRF token
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            return ['success' => false, 'message' => 'Token di sicurezza non valido'];
        }
        
        // Rate limiting
        if (!checkRateLimit('forgot_password', 3, 300)) {
            return ['success' => false, 'message' => 'Troppi tentativi. Riprova tra 5 minuti.'];
        }
        
        $email = sanitizeInput($_POST['email'] ?? '');
        
        if (empty($email) || !validateEmail($email)) {
            return ['success' => false, 'message' => 'Email non valida'];
        }
        
        // Verifica se utente esiste
        $user = $this->userModel->findByEmail($email);
        
        // Per sicurezza, restituisci sempre successo anche se email non esiste
        if (!$user) {
            return ['success' => true, 'message' => 'Se l\'email esiste, riceverai le istruzioni per il reset'];
        }
        
        // Genera token
        $token = generateSecureToken();
        
        // Salva token nel database
        if (!$this->userModel->storeResetToken($email, $token)) {
            return ['success' => false, 'message' => 'Errore nel sistema. Riprova più tardi.'];
        }
        
        // Invia email
        $resetLink = BASE_URL . "/login/reset_password.php?token=" . $token;
        $subject = "Reset Password - " . SITE_NAME;
        $message = $this->getResetEmailTemplate($user['nome'], $resetLink);
        
        if (sendEmail($email, $subject, $message)) {
            return ['success' => true, 'message' => 'Email con istruzioni inviata'];
        } else {
            return ['success' => false, 'message' => 'Errore nell\'invio email'];
        }
    }
    
    /**
     * Gestisce reset password
     */
    public function resetPassword() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return ['success' => false, 'message' => 'Metodo non consentito'];
        }
        
        // Verifica CSRF token
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            return ['success' => false, 'message' => 'Token di sicurezza non valido'];
        }
        
        $token = sanitizeInput($_POST['token'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validazioni
        if (empty($token)) {
            return ['success' => false, 'message' => 'Token mancante'];
        }
        
        if (empty($password)) {
            return ['success' => false, 'message' => 'La password è obbligatoria'];
        }
        
        $passwordErrors = validatePassword($password);
        if (!empty($passwordErrors)) {
            return ['success' => false, 'message' => implode('<br>', $passwordErrors)];
        }
        
        if ($password !== $confirmPassword) {
            return ['success' => false, 'message' => 'Le password non corrispondono'];
        }
        
        // Verifica token
        $tokenResult = $this->userModel->verifyResetToken($token);
        if (!$tokenResult['success']) {
            return $tokenResult;
        }
        
        // Trova utente
        $user = $this->userModel->findByEmail($tokenResult['email']);
        if (!$user) {
            return ['success' => false, 'message' => 'Utente non trovato'];
        }
        
// Aggiorna password
$newPasswordHash = hashPassword($password);
if (!$this->userModel->updatePasswordHash($user['id'], $newPasswordHash)) {
    return ['success' => false, 'message' => 'Errore nell\'aggiornamento password'];
}

// Pulisci tentativi di login falliti (sblocca account)
$this->userModel->clearLoginAttempts($tokenResult['email']);

// Marca token come utilizzato
$this->userModel->markTokenAsUsed($token);

// Pulisci token vecchi
$this->userModel->cleanupResetTokens($user['email']);

return ['success' => true, 'message' => 'Password aggiornata con successo'];
    }
    
    /**
     * Avvia sessione utente
     */
    private function startUserSession($user, $rememberMe = false) {
        // Rigenera ID sessione per sicurezza
        session_regenerate_id(true);
        
        // Imposta dati sessione
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_nome'] = $user['nome'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_ruolo'] = $user['ruolo'];
        $_SESSION['login_time'] = time();
        
        // Imposta cookie "ricordami" se richiesto
if ($rememberMe) {
    $cookieValue = base64_encode($user['id'] . ':' . $user['email']);
    setcookie('remember_me', $cookieValue, time() + REMEMBER_ME_LIFETIME, '/', '', true, true);
} else {
    // Assicurati che non ci siano cookie "remember_me" residui
    setcookie('remember_me', '', time() - 3600, '/', '', true, true);
}
    }
    
    /**
     * Template email per reset password
     */
    private function getResetEmailTemplate($nome, $resetLink) {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Reset Password - " . SITE_NAME . "</title>
        </head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #008CFF;'>Reset Password - " . SITE_NAME . "</h2>
                
                <p>Ciao <strong>" . htmlspecialchars($nome) . "</strong>,</p>
                
                <p>Hai richiesto il reset della tua password. Clicca sul link sottostante per impostare una nuova password:</p>
                
                <p style='margin: 30px 0;'>
                    <a href='" . htmlspecialchars($resetLink) . "'
                       style='background: #008CFF; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Reset Password
                    </a>
                </p>
                
                <p><strong>Importante:</strong></p>
                <ul>
                    <li>Questo link è valido per 1 ora</li>
                    <li>Se non hai richiesto il reset, ignora questa email</li>
                    <li>Non condividere questo link con nessuno</li>
                </ul>
                
                <p>Se il pulsante non funziona, copia e incolla questo link nel browser:</p>
                <p style='word-break: break-all; color: #666;'>" . htmlspecialchars($resetLink) . "</p>
                
                <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
                <p style='color: #666; font-size: 12px;'>
                    Questa email è stata inviata automaticamente dal sistema " . SITE_NAME . " per conto di www.SkuAlizer.com<br>
                    Non rispondere a questa email.
                </p>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Verifica sessione esistente (per auto-login)
     */
    public function checkExistingSession() {
        // Verifica cookie "ricordami"
        if (!isLoggedIn() && isset($_COOKIE['remember_me'])) {
            $cookieData = base64_decode($_COOKIE['remember_me']);
            list($userId, $email) = explode(':', $cookieData, 2);
            
            if ($userId && $email) {
                $user = $this->userModel->findById($userId);
                if ($user && $user['email'] === $email) {
                    $this->startUserSession($user, true);
                    // Redirect intelligente dopo auto-login
                    redirectToDashboard();
                }
            }
            
// Cookie non valido, eliminalo
clearRememberMeCookie();
        }
        
        return isLoggedIn();
    }
}
?>

