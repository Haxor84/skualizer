<?php
/**
 * Reset Password Mobile - Margynomic
 * Versione ottimizzata per dispositivi mobili e PWA
 */

require_once '../../margynomic/config/config.php';
require_once '../../margynomic/login/auth_helpers.php';
require_once '../../margynomic/login/UserModel.php';
require_once '../../margynomic/login/AuthController.php';

// Redirect se già autenticato
requireGuest('../Profilo.php');

// Inizializza controller
$authController = new AuthController();

// Ottieni token da URL
$token = sanitizeInput($_GET['token'] ?? '');

// Verifica token
$tokenValid = false;
$userEmail = '';

if ($token) {
    $userModel = new UserModel();
    $tokenResult = $userModel->verifyResetToken($token);
    
    if ($tokenResult['success']) {
        $tokenValid = true;
        $userEmail = $tokenResult['email'];
    } else {
        $message = $tokenResult['message'];
        $messageType = 'error';
    }
} else {
    $message = 'Token mancante o non valido';
    $messageType = 'error';
}

// Gestisci form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $_POST['token'] = $token;
    $result = $authController->resetPassword();
    
    $message = $result['message'];
    $messageType = $result['success'] ? 'success' : 'error';
    
    if ($result['success']) {
        $tokenValid = false;
        $showSuccessMessage = true;
    }
}

$csrfToken = generateCSRFToken();

// Funzione helper per mascherare email
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    
    $username = $parts[0];
    $domain = $parts[1];
    
    $maskedUsername = substr($username, 0, 2) . str_repeat('*', max(1, strlen($username) - 4)) . substr($username, -2);
    
    return $maskedUsername . '@' . $domain;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
    
    <!-- PWA Meta Tags -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#008CFF">
    
    <!-- Manifest -->
    <link rel="manifest" href="../manifest.json">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .container {
            width: 100%;
            max-width: 400px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .logo img {
            max-width: 160px;
            height: auto;
        }
        
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
        }
        
        .subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 1.5rem;
            font-size: 14px;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 14px;
        }
        
        .alert-error {
            background: #fee;
            color: #c00;
            border-left: 4px solid #c00;
        }
        
        .alert-success {
            background: #efe;
            color: #060;
            border-left: 4px solid #060;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 1rem;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #008CFF;
            box-shadow: 0 0 0 3px rgba(0, 140, 255, 0.1);
        }
        
        .form-control-static {
            padding: 1rem;
            background: #f8f9fa;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            color: #666;
            font-family: monospace;
            font-size: 14px;
            text-align: center;
        }
        
        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: #008CFF;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-submit:active {
            transform: scale(0.98);
        }
        
        .btn-outline {
            width: 100%;
            padding: 1rem;
            background: white;
            color: #008CFF;
            border: 2px solid #008CFF;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 0.5rem;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .auth-links {
            text-align: center;
            margin-top: 1.5rem;
        }
        
        .auth-link {
            color: #008CFF;
            text-decoration: none;
            font-size: 14px;
        }
        
        .info-box {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 12px;
            margin-top: 1.5rem;
        }
        
        .info-box h4 {
            font-size: 14px;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        
        .info-box ul {
            list-style: none;
            padding: 0;
            font-size: 13px;
            color: #666;
        }
        
        .info-box li {
            padding: 0.25rem 0;
        }
        
        .success-icon, .error-icon {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .message-box {
            text-align: center;
        }
        
        .message-box h3 {
            margin-bottom: 1rem;
        }
        
        .message-box h3.success {
            color: #00C281;
        }
        
        .message-box h3.error {
            color: #FF3B3B;
        }
        
        .message-box p {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <!-- Logo -->
            <div class="logo">
                <img src="../../margynomic/uploads/img/MARGYNOMIC.PNG" alt="Margynomic">
            </div>
            
            <?php if ($tokenValid): ?>
                <h2>Nuova Password</h2>
                <div class="subtitle">Scegli una password sicura per il tuo account</div>
            <?php elseif (isset($showSuccessMessage)): ?>
                <h2>Password Aggiornata</h2>
                <div class="subtitle">La tua password è stata cambiata con successo</div>
            <?php else: ?>
                <h2>Link Non Valido</h2>
                <div class="subtitle">Questo link non è più utilizzabile</div>
            <?php endif; ?>
            
            <!-- Messaggi -->
            <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($tokenValid): ?>
                <!-- Form -->
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Account</label>
                        <div class="form-control-static">
                            <?php echo maskEmail($userEmail); ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password" class="form-label">Nuova Password *</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Almeno 8 caratteri"
                            required
                            autocomplete="new-password"
                            autofocus
                        >
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Conferma Password *</label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-control" 
                            placeholder="Ripeti la nuova password"
                            required
                            autocomplete="new-password"
                        >
                    </div>
                    
                    <button type="submit" class="btn-submit">Aggiorna Password</button>
                </form>
                
                <!-- Info Box -->
                <div class="info-box">
                    <h4>🔐 Requisiti Password</h4>
                    <ul>
                        <li>• Almeno 8 caratteri</li>
                        <li>• Una lettera maiuscola</li>
                        <li>• Una lettera minuscola</li>
                        <li>• Un numero o simbolo</li>
                    </ul>
                </div>
                
            <?php elseif (isset($showSuccessMessage)): ?>
                <!-- Success Message -->
                <div class="message-box">
                    <div class="success-icon">✅</div>
                    <h3 class="success">Password Aggiornata!</h3>
                    <p>La tua password è stata aggiornata con successo. Accedi ora con le nuove credenziali.</p>
                    
                    <a href="login.php" class="btn-submit">Accedi Ora</a>
                </div>
                
            <?php else: ?>
                <!-- Error Message -->
                <div class="message-box">
                    <div class="error-icon">❌</div>
                    <h3 class="error">Link Non Valido</h3>
                    <p>Questo link di reset non è più valido o è già stato utilizzato.</p>
                    
                    <a href="forgot_password.php" class="btn-submit">Richiedi Nuovo Link</a>
                    <a href="login.php" class="btn-outline">Torna al Login</a>
                </div>
                
                <div class="info-box">
                    <h4>Possibili Cause:</h4>
                    <ul>
                        <li>• Il link è scaduto (valido 1 ora)</li>
                        <li>• Il link è già stato utilizzato</li>
                        <li>• Il link è stato copiato male</li>
                        <li>• È stato richiesto un nuovo reset</li>
                    </ul>
                </div>
            <?php endif; ?>
            
            <!-- Links -->
            <div class="auth-links">
                <a href="login.php" class="auth-link">← Torna al Login</a>
            </div>
        </div>
    </div>
    
    <script>
        // Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('../service-worker.js').catch(err => {
                console.log('ServiceWorker registration failed:', err);
            });
        }
        
        // Password validation
        const passwordInput = document.getElementById('password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                if (this.value && this.value !== passwordInput.value) {
                    this.setCustomValidity('Le password non corrispondono');
                } else {
                    this.setCustomValidity('');
                }
            });
        }
    </script>
</body>
</html>

