<?php
/**
 * Password Dimenticata Mobile - Margynomic
 * Versione ottimizzata per dispositivi mobili e PWA
 */

require_once '../../margynomic/config/config.php';
require_once '../../margynomic/login/auth_helpers.php';
require_once '../../margynomic/login/AuthController.php';

// Redirect se già autenticato
requireGuest('../Profilo.php');

// Inizializza controller
$authController = new AuthController();

// Gestisci form submission
$message = '';
$messageType = '';
$showForm = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $authController->forgotPassword();
    
    $message = $result['message'];
    $messageType = $result['success'] ? 'success' : 'error';
    
    if ($result['success']) {
        $showForm = false;
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Password Dimenticata - <?php echo SITE_NAME; ?></title>
    
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
        }
        
        .btn-submit:active {
            transform: scale(0.98);
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
        
        .success-icon {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .success-message {
            text-align: center;
        }
        
        .success-message h3 {
            color: #00C281;
            margin-bottom: 1rem;
        }
        
        .success-message p {
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.5;
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
            
            <h2><?php echo $showForm ? 'Password Dimenticata' : 'Email Inviata'; ?></h2>
            <div class="subtitle">
                <?php echo $showForm ? 'Inserisci la tua email per recuperare l\'accesso' : 'Controlla la tua email per le istruzioni'; ?>
            </div>
            
            <!-- Messaggi -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($showForm): ?>
                <!-- Form -->
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="form-group">
                        <label for="email" class="form-label">Email</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-control" 
                            placeholder="mario.rossi@email.com"
                            value="<?php echo sanitizeInput($_POST['email'] ?? ''); ?>"
                            required
                            autocomplete="email"
                            autofocus
                        >
                    </div>
                    
                    <button type="submit" class="btn-submit">Invia Istruzioni</button>
                </form>
                
                <!-- Info Box -->
                <div class="info-box">
                    <h4>🔒 Informazioni di Sicurezza</h4>
                    <ul>
                        <li>• Il link sarà valido per 60 minuti</li>
                        <li>• Controlla anche lo spam</li>
                        <li>• Valido per un solo utilizzo</li>
                    </ul>
                </div>
            <?php else: ?>
                <!-- Success Message -->
                <div class="success-message">
                    <div class="success-icon">📧</div>
                    <h3>Email Inviata!</h3>
                    <p>Se l'indirizzo email esiste, riceverai le istruzioni per reimpostare la password.</p>
                    
                    <a href="login.php" class="btn-submit" style="display: block; text-align: center; text-decoration: none;">Torna al Login</a>
                    <button onclick="location.reload()" class="btn-outline">Invia di Nuovo</button>
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
    </script>
</body>
</html>

