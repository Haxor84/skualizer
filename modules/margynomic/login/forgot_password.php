<?php

/**
 * Password Dimenticata - Margynomic
 * File: forgot_password.php
 * Descrizione: Form per richiedere reset password via email
 */

// Includi configurazione e helpers
require_once '../config/config.php';
require_once 'auth_helpers.php';

// ⚡ REDIRECT MOBILE IMMEDIATO (prima di qualsiasi output)
if (isMobileDevice() && strpos($_SERVER['REQUEST_URI'], '/mobile/') === false) {
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

// Gestisci form submission
$message = '';
$messageType = '';
$showForm = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $authController->forgotPassword();
    
    $message = $result['message'];
    $messageType = $result['success'] ? 'success' : 'error';
    
    // Se successo, nascondi form
    if ($result['success']) {
        $showForm = false;
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
    <title>Password Dimenticata - <?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="../css/margynomic.css">
    <meta name="description" content="Recupera la password del tuo account Margynomic">
    <meta name="robots" content="noindex, nofollow">
</head>
<body class="auth-body">
    <div class="auth-container">
        <div class="auth-card">
            <!-- Logo e Header -->
            <div class="auth-header">
                <div class="auth-logo">
                    <img src="../uploads/img/MARGYNOMIC.PNG" alt="Margynomic Logo" style="width: 100%; height: auto; display: block;">
                </div>
                <h2 class="auth-title" style="text-align: center;">Password Dimenticata</h2>
                <p class="auth-subtitle" style="text-align: center;">
                    <?php if ($showForm): ?>
                        Inserisci l'email associata al tuo account
                    <?php else: ?>
                        Controlla la tua email per le istruzioni
                    <?php endif; ?>
                </p>
            </div>

            <!-- Messaggi di errore/successo -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <span><?php echo $message; ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($showForm): ?>
                <!-- Form reset password -->
                <form id="forgotForm" method="POST" action="" class="auth-form" novalidate>
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

                    <button type="submit" class="btn btn-primary w-full">
                        <span class="btn-text">Invia Istruzioni</span>
                        <span class="btn-loader" style="display: none;">
                            <div class="loader"></div>
                            Invio in corso...
                        </span>
                    </button>
                </form>

<!-- Card Informazioni di Sicurezza -->
<div style="margin-top: 2rem; padding: 1.5rem; border-radius: 12px; background: #F4F6FB; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
    <h4 style="margin-bottom: 1rem; color: #1F2937; text-align: center;">🔒 Informazioni di Sicurezza</h4>
    <ul style="list-style: none; padding-left: 0; color: #374151; font-size: 0.95rem;">
        <li>• Il link sarà utilizzabile per 60 minuti</li>
        <li>• Potrai richiedere un nuovo link</li>
        <li>• Se non ricevi l'email, controlla lo spam</li>
        <li>• Il link sarà valido per un solo utilizzo</li>
    </ul>
</div>

            <?php else: ?>
                <!-- Messaggio di successo -->
                <div class="auth-success-message">
                    <div class="success-icon">📧</div>
                    <h3>Email Inviata!</h3>
                    <p>Se l'indirizzo email esiste, riceverai le istruzioni per reimpostare la password.</p>
                    
                    <div class="success-actions">
                        <a href="login.php" class="btn btn-primary">Torna al Login</a>
                        <button onclick="location.reload()" class="btn btn-outline">Invia di Nuovo</button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Supporto -->
            <div class="auth-support">
                <h4>Hai bisogno di aiuto?</h4>
                <p>Scrivici a:</p>
                <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="auth-link"><?php echo ADMIN_EMAIL; ?></a>
            </div>
        </div>
    </div>

    <!-- JavaScript per UX -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('forgotForm');
            
            if (form) {
                form.addEventListener('submit', function(e) {
                    const emailInput = document.getElementById('email');
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const btnText = submitBtn.querySelector('.btn-text');
                    const btnLoader = submitBtn.querySelector('.btn-loader');
                    
                    // Validazione email
                    if (!emailInput.value || !isValidEmail(emailInput.value)) {
                        e.preventDefault();
                        showAlert('Inserisci un indirizzo email valido', 'error');
                        return;
                    }
                    
                    // Mostra loader
                    btnText.style.display = 'none';
                    btnLoader.style.display = 'flex';
                    submitBtn.disabled = true;
                });
            }

            // Funzione validazione email
            function isValidEmail(email) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                return emailRegex.test(email);
            }

            // Funzione per mostrare alert
            function showAlert(message, type) {
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
                
                const form = document.querySelector('.auth-form');
                if (form) {
                    form.parentNode.insertBefore(alert, form);
                }
                
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 5000);
            }
        });
    </script>

    <style>
        .auth-security-info,
        .auth-next-steps,
        .auth-support {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #E5E5E5;
        }
        
        .auth-security-info h4,
        .auth-next-steps h4,
        .auth-support h4 {
            font-size: 1rem;
            margin-bottom: 1rem;
            color: #1C1C1C;
        }
        
        .auth-security-info ul,
        .auth-next-steps ol {
            padding-left: 1.5rem;
            color: #6B7280;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        
        .auth-security-info li,
        .auth-next-steps li {
            margin-bottom: 0.5rem;
        }
        
        .auth-success-message {
            text-align: center;
            padding: 2rem 0;
        }
        
        .success-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .auth-success-message h3 {
            color: #00C281;
            margin-bottom: 1rem;
        }
        
        .auth-success-message p {
            color: #6B7280;
            margin-bottom: 2rem;
            line-height: 1.5;
        }
        
        .success-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .auth-support {
            text-align: center;
        }
        
        .auth-support p {
            color: #6B7280;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 767px) {
            .success-actions {
                flex-direction: column;
            }
            
            .auth-links {
                text-align: center;
                font-size: 0.875rem;
            }
        }
    </style>
</body>
</html>

