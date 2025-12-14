<?php
/**
 * Registrazione Mobile - Margynomic
 * Versione ottimizzata per dispositivi mobili e PWA
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../margynomic/config/config.php';
require_once '../../margynomic/login/auth_helpers.php';
require_once '../../margynomic/login/UserModel.php';
require_once '../../margynomic/login/AuthController.php';

// Redirect se già autenticato
requireGuest('../Profilo.php');

// Inizializza controller
$authController = new AuthController();

// Gestisci form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $authController->register();
    
    if ($result['success']) {
        setFlashMessage('success', $result['message']);
        redirect('login.php');
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
    <title>Registrati - <?php echo SITE_NAME; ?></title>
    
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
            padding: 1rem;
        }
        
        .register-container {
            width: 100%;
            max-width: 400px;
            margin: 2rem auto;
        }
        
        .register-card {
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
        
        .form-group {
            margin-bottom: 1rem;
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
            padding: 0.875rem;
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
        
        .form-help {
            font-size: 12px;
            color: #999;
            margin-top: 0.25rem;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            margin-top: 2px;
        }
        
        .checkbox-wrapper label {
            font-size: 13px;
            color: #666;
            line-height: 1.4;
        }
        
        .checkbox-wrapper a {
            color: #008CFF;
            text-decoration: none;
        }
        
        .btn-register {
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
        
        .btn-register:active {
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
            margin: 1rem 0;
            color: #999;
            font-size: 14px;
        }
        
        .benefits {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 12px;
            margin-top: 1.5rem;
        }
        
        .benefits h3 {
            font-size: 16px;
            margin-bottom: 0.5rem;
            text-align: center;
        }
        
        .benefits p {
            font-size: 13px;
            color: #666;
            line-height: 1.5;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <!-- Logo -->
            <div class="logo">
                <img src="../../margynomic/uploads/img/MARGYNOMIC.PNG" alt="Margynomic">
            </div>
            
            <h2>Crea Account</h2>
            <div class="subtitle">Inizia subito ad analizzare i tuoi margini Amazon</div>
            
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
                    <label for="nome" class="form-label">Nome completo *</label>
                    <input 
                        type="text" 
                        id="nome" 
                        name="nome" 
                        class="form-control" 
                        placeholder="Mario Rossi"
                        value="<?php echo sanitizeInput($_POST['nome'] ?? ''); ?>"
                        required
                        autocomplete="name"
                    >
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email *</label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="form-control" 
                        placeholder="mario.rossi@email.com"
                        value="<?php echo sanitizeInput($_POST['email'] ?? ''); ?>"
                        required
                        autocomplete="email"
                    >
                    <div class="form-help">Usa una email valida per accedere</div>
                </div>
                
                <div class="form-group">
                    <label for="telefono" class="form-label">Telefono *</label>
                    <input 
                        type="tel" 
                        id="telefono" 
                        name="telefono" 
                        class="form-control" 
                        placeholder="+39 123 456 7890"
                        required
                        autocomplete="tel"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password *</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Almeno 8 caratteri"
                        required
                        autocomplete="new-password"
                    >
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Conferma Password *</label>
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        class="form-control" 
                        placeholder="Ripeti la password"
                        required
                        autocomplete="new-password"
                    >
                </div>
                
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="terms" id="terms" required>
                    <label for="terms">Accetto i <a href="#" onclick="return false;">Termini di Servizio</a> e la <a href="#" onclick="return false;">Privacy Policy</a> *</label>
                </div>
                
                <div class="checkbox-wrapper">
                    <input type="checkbox" name="newsletter" id="newsletter" checked>
                    <label for="newsletter">Voglio ricevere sconti e promozioni</label>
                </div>
                
                <button type="submit" class="btn-register">Crea Account</button>
            </form>
            
            <!-- Links -->
            <div class="separator">Hai già un account?</div>
            
            <div class="auth-links">
                <a href="login.php" class="auth-link" style="font-weight: 600;">Accedi Ora</a>
            </div>
            
            <!-- Benefits -->
            <div class="benefits">
                <h3>🚀 Perché sceglierci?</h3>
                <p>Scopri cosa ti fa davvero guadagnare. Margynomic analizza ogni costo e ti mostra dove stai perdendo soldi.</p>
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
        
        confirmPasswordInput.addEventListener('input', function() {
            if (this.value && this.value !== passwordInput.value) {
                this.setCustomValidity('Le password non corrispondono');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>

