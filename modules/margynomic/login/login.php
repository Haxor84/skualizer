<?php

/**
 * Pagina di Login - Margynomic
 * File: login.php
 * Descrizione: Form di login con validazione e gestione errori
 */

// Includi configurazione e helpers
require_once '../config/config.php';
require_once 'auth_helpers.php';
require_once 'UserModel.php';
require_once 'AuthController.php';

// ⚡ REDIRECT MOBILE IMMEDIATO (prima di qualsiasi output)
if (isMobileDevice() && strpos($_SERVER['REQUEST_URI'], '/mobile/') === false) {
    // Costruisci URL mobile preservando query string
    $mobileUrl = '/modules/mobile/login/' . basename($_SERVER['PHP_SELF']);
    if (!empty($_SERVER['QUERY_STRING'])) {
        $mobileUrl .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $mobileUrl);
    exit();
}

// Redirect se già autenticato
requireGuest();

// Inizializza controller
$authController = new AuthController();

// Verifica sessione esistente
if ($authController->checkExistingSession()) {
    redirect('../profilo_utente.php');
}

// Gestisci form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $authController->login();
    
if ($result['success']) {
    // Redirect intelligente basato su device
    redirectToDashboard();
} else {
        $message = $result['message'];
        $messageType = 'error';
    }
}

// Ottieni messaggi flash
$flashMessages = getFlashMessage();
if (!empty($flashMessages)) {
    foreach ($flashMessages as $type => $msg) {
        $message = $msg;
        $messageType = $type;
        break; // Mostra solo il primo messaggio
    }
}

// Genera CSRF token
$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi - <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="../css/margynomic.css">
    <meta name="description" content="Accedi a Margynomic per gestire i tuoi margini Amazon con intelligenza">
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card">
            <!-- Logo e Header -->
            <div class="auth-header">
                <div class="auth-logo" style="text-align: center;">
                    <img src="../uploads/img/MARGYNOMIC.PNG" alt="Margynomic Logo" style="width: 100%; height: auto; display: block;">
                </div>
                <h2 class="auth-title" style="text-align: center;">Il tuo nuovo alleato per dominare Amazon</h2>
            </div>

            <!-- Messaggi di errore/successo -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <span><?php echo $message; ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Form di login -->
            <form id="loginForm" method="POST" action="" class="auth-form" novalidate>
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

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember_me" value="1">
                        <span>Ricordami</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    <span class="btn-text">Accedi</span>
                    <span class="btn-loader" style="display: none;">
                        <div class="loader"></div>
                        Accesso in corso...
                    </span>
                </button>
            </form>

            <!-- Link password dimenticata -->
            <div class="auth-links" style="text-align: center; margin: 1.5rem 0;">
                <a href="forgot_password.php" class="auth-link">Password dimenticata?</a>
            </div>

            <!-- Link alla registrazione -->
            <div class="auth-links" style="text-align: center; margin-top: 20px;">
                <span>Non hai un account?</span><br>
                <a href="register.php" class="btn btn-success" style="margin-top:10px;">REGISTRATI</a>
            </div>
        </div>
    </div>

    <!-- JavaScript per UX -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');

            // Auto-fill credenziali demo
            const demoCredentials = document.querySelectorAll('.credential-item');
            demoCredentials.forEach(item => {
                item.addEventListener('click', function() {
                    const text = this.textContent;
                    const emailMatch = text.match(/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/);
                    const passwordMatch = text.match(/\/\s*(\w+)/);
                    
                    if (emailMatch && passwordMatch) {
                        emailInput.value = emailMatch[1];
                        passwordInput.value = passwordMatch[1];
                        
                        // Evidenzia temporaneamente
                        this.style.background = '#008CFF';
                        this.style.color = 'white';
                        this.style.borderRadius = '4px';
                        this.style.padding = '4px 8px';
                        
                        setTimeout(() => {
                            this.style.background = '';
                            this.style.color = '';
                            this.style.borderRadius = '';
                            this.style.padding = '';
                        }, 1000);
                    }
                });
            });

            // Gestione submit form
form.addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    const btnText = submitBtn.querySelector('.btn-text');
    const btnLoader = submitBtn.querySelector('.btn-loader');
    
    // Validazione base
    if (!emailInput.value || !passwordInput.value) {
        e.preventDefault();
        showAlert('Inserisci email e password', 'error');
        return;
    }
    
    // Mostra loader
    btnText.style.display = 'none';
    btnLoader.style.display = 'flex';
    submitBtn.disabled = true;
    
    // Reset loader dopo 10 secondi per evitare blocchi
    setTimeout(() => {
        btnText.style.display = 'inline';
        btnLoader.style.display = 'none';
        submitBtn.disabled = false;
    }, 5000);
});

            // Funzione per mostrare alert
            function showAlert(message, type) {
                // Rimuovi alert esistenti
                const existingAlert = document.querySelector('.alert');
                if (existingAlert) {
                    existingAlert.remove();
                }
                
                const alert = document.createElement('div');
                alert.className = `alert alert-${type}`;
                alert.innerHTML = `
                    <span>${message}</span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                `;
                
                // Inserisci prima del form
                const form = document.querySelector('.auth-form');
                form.parentNode.insertBefore(alert, form);
                
                // Auto-rimozione dopo 5 secondi
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 5000);
            }

            // Gestione Enter key
            [emailInput, passwordInput].forEach(input => {
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        form.dispatchEvent(new Event('submit'));
                    }
                });
            });
        });
    </script>

    <style>
        .auth-demo-credentials {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #E5E5E5;
        }
        
        .auth-demo-credentials h4 {
            font-size: 1rem;
            margin-bottom: 1rem;
            color: #1C1C1C;
            text-align: center;
        }
        
        .demo-credentials {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .credential-item {
            padding: 0.75rem;
            background: #F8F9FA;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        
        .credential-item:hover {
            background: #E3F2FD;
            border-color: #008CFF;
            transform: translateY(-1px);
        }
        
        .credential-item strong {
            color: #008CFF;
        }
        
        @media (max-width: 767px) {
            .demo-credentials {
                gap: 0.75rem;
            }
            
            .credential-item {
                font-size: 0.8rem;
            }
        }
    </style>
</body>
</html>

