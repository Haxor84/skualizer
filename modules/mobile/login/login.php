<?php
/**
 * Login Mobile - Margynomic
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

// Verifica sessione esistente
if ($authController->checkExistingSession()) {
    redirect('../Profilo.php');
}

// Gestisci form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $authController->login();
    
    if ($result['success']) {
        // Redirect a profilo mobile
        header('Location: ../Profilo.php');
        exit();
    } else {
        $message = $result['message'];
        $messageType = 'error';
    }
}

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Accedi - <?php echo SITE_NAME; ?></title>
    
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
        
        .login-container {
            width: 100%;
            max-width: 400px;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo img {
            max-width: 180px;
            height: auto;
        }
        
        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
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
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }
        
        .checkbox-wrapper label {
            font-size: 14px;
            color: #666;
        }
        
        .btn-login {
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
        
        .btn-login:active {
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
        
        .separator {
            text-align: center;
            margin: 1.5rem 0;
            color: #999;
            font-size: 14px;
        }
        
        /* Install Prompt */
        .install-prompt {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #fff;
            padding: 1rem;
            box-shadow: 0 -2px 20px rgba(0, 0, 0, 0.15);
            display: none;
            z-index: 1000;
            border-radius: 20px 20px 0 0;
        }
        
        .install-prompt.show {
            display: block;
        }
        
        .install-prompt-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 16px;
        }
        
        .install-prompt-text {
            color: #666;
            font-size: 14px;
            margin-bottom: 1rem;
            line-height: 1.4;
        }
        
        .install-buttons {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-install {
            flex: 1;
            padding: 0.75rem;
            background: #008CFF;
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
        }
        
        .btn-dismiss {
            flex: 1;
            padding: 0.75rem;
            background: #f5f5f5;
            color: #666;
            border: none;
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <!-- Logo -->
            <div class="logo">
                <img src="../../margynomic/uploads/img/MARGYNOMIC.PNG" alt="Margynomic">
            </div>
            
            <h2>Benvenuto</h2>
            
            <!-- Messaggi -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
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
                        required
                        autocomplete="email"
                        autofocus
                    >
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="La tua password"
                        required
                        autocomplete="current-password"
                    >
                </div>
                
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="remember_me" value="1" id="remember">
                    <label for="remember">Ricordami</label>
                </div>
                
                <button type="submit" class="btn-login">Accedi</button>
            </form>
            
            <!-- Links -->
            <div class="auth-links">
                <a href="forgot_password.php" class="auth-link">Password dimenticata?</a>
            </div>
            
            <div class="separator">Non hai un account?</div>
            
            <div class="auth-links">
                <a href="register.php" class="auth-link" style="font-weight: 600;">Registrati Ora</a>
            </div>
        </div>
    </div>
    
    <!-- Install Prompt -->
    <div class="install-prompt" id="installPrompt">
        <div class="install-prompt-title">📲 Installa l'App</div>
        <div class="install-prompt-text">
            Per un'esperienza migliore, installa Margynomic sulla tua home screen e usala come un'app nativa.
        </div>
        <div class="install-buttons">
            <button onclick="installApp()" class="btn-install">Installa</button>
            <button onclick="closeInstallPrompt()" class="btn-dismiss">Non ora</button>
        </div>
    </div>
    
    <script>
        // Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('../service-worker.js').catch(err => {
                console.log('ServiceWorker registration failed:', err);
            });
        }
        
        // PWA Install Prompt
        let deferredPrompt;
        
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            
            // Check if dismissed recently
            const dismissed = localStorage.getItem('installPromptDismissed');
            if (dismissed) {
                const dismissedTime = parseInt(dismissed);
                const dayAgo = Date.now() - (24 * 60 * 60 * 1000);
                if (dismissedTime > dayAgo) {
                    return; // Don't show if dismissed in last 24h
                }
            }
            
            // Show prompt after 3 seconds
            setTimeout(() => {
                document.getElementById('installPrompt').classList.add('show');
            }, 3000);
        });
        
        function installApp() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    deferredPrompt = null;
                    closeInstallPrompt();
                });
            }
        }
        
        function closeInstallPrompt() {
            document.getElementById('installPrompt').classList.remove('show');
            localStorage.setItem('installPromptDismissed', Date.now().toString());
        }
    </script>
</body>
</html>

