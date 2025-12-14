<?php

/**
 * Reset Password - Margynomic
 * File: reset_password.php
 * Descrizione: Form per impostare nuova password tramite token email
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
    $_POST['token'] = $token; // Aggiungi token ai dati POST
    $result = $authController->resetPassword();
    
    $message = $result['message'];
    $messageType = $result['success'] ? 'success' : 'error';
    
    // Se successo, nascondi form
    if ($result['success']) {
        $tokenValid = false;
        $showSuccessMessage = true;
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
    <title>Reset Password - <?php echo SITE_NAME; ?></title>
 <link rel="stylesheet" href="../css/margynomic.css">
    <meta name="description" content="Imposta una nuova password per il tuo account Margynomic">
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
                <h2 class="auth-title" style="text-align: center;">
                    <?php if ($tokenValid): ?>
                        Nuova Password
                    <?php elseif (isset($showSuccessMessage)): ?>
                        Password Aggiornata
                    <?php else: ?>
                        Link Non Valido
                    <?php endif; ?>
                </h2>
                <p class="auth-subtitle" style="text-align: center;">
                    <?php if ($tokenValid): ?>
                        Scegli una nuova password sicura
                    <?php elseif (isset($showSuccessMessage)): ?>
                        Password aggiornata con successo
                    <?php else: ?>
                        Il link di reset non più valido
                    <?php endif; ?>
                </p>
            </div>

            <!-- Messaggi di errore/successo -->
            <?php if (isset($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <span><?php echo $message; ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <?php if ($tokenValid): ?>
                <!-- Form reset password -->
                <form id="resetForm" method="POST" action="" class="auth-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    
                    <!-- Mostra email (mascherata) -->
                    <div class="form-group">
                        <label class="form-label">Account</label>
                        <div class="form-control-static">
                            <?php echo maskEmail($userEmail); ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label" >Nuova Password *</label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="form-control" 
                            placeholder="Crea una password sicura"
                            required
                            autocomplete="new-password"
                            autofocus
                        >
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

                    <button type="submit" class="btn btn-primary w-full">
                        <span class="btn-text">Aggiorna Password</span>
                        <span class="btn-loader" style="display: none;">
                            <div class="loader"></div>
                            Aggiornamento...
                        </span>
                    </button>
                </form>
<!-- Card Requisiti Password -->
<div style="margin-top: 2rem; padding: 1.5rem; border-radius: 12px; background: #F4F6FB; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
    <h4 style="margin-bottom: 1rem; color: #1F2937; text-align: center;">🔐 Requisiti Password</h4>
    <ul style="list-style: none; padding-left: 0; color: #374151; font-size: 0.95rem;">
        <li>• Almeno 8 caratteri</li>
        <li>• Una lettera maiuscola</li>
        <li>• Una lettera minuscola</li>
        <li>• Un numero o simbolo</li>
    </ul>
</div>


            <?php elseif (isset($showSuccessMessage)): ?>
                <!-- Messaggio di successo -->
                <div class="auth-success-message">
                    <div class="success-icon">✅</div>
                    <h3>Password Aggiornata!</h3>
                    <p>La tua password è stata aggiornata con successo. Accedi con le nuove credenziali.</p>
                    
                    <div class="success-actions">
                        <a href="login.php" class="btn btn-primary">Accedi Ora</a>
                    </div>
                </div>

                <!-- Consigli di sicurezza -->
                <div class="auth-security-tips">
                    <h4>💡 Consigli di Sicurezza:</h4>
                    <ul>
                        <li>Non condividere mai la tua password</li>
                        <li>Usa password diverse per ogni servizio</li>
                        <li>Considera l'uso di un password manager</li>
                        <li>Attiva l'autenticazione a due fattori</li>
                    </ul>
                </div>

            <?php else: ?>
                <!-- Token non valido -->
                <div class="auth-error-message">
                    <div class="error-icon">❌</div>
                    <h3>Link Non Valido</h3>
                    <p>Questo link di reset non è più valido.</p>
                    
                    <div class="error-actions">
                        <a href="forgot_password.php" class="btn btn-primary">Richiedi Nuovo link</a>
                        <a href="login.php" class="btn btn-outline">Torna al Login</a>
                    </div>
                </div>

                <!-- Possibili cause -->
                <div class="auth-error-causes">
                    <h4>Possibili Cause:</h4>
                    <ul>
                        <li>Il link è scaduto (valido per 1 ora)</li>
                        <li>Il link è già stato utilizzato</li>
                        <li>Il link è stato copiato in modo incompleto</li>
                        <li>È stato richiesto un nuovo reset</li>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Link di ritorno -->
            <div class="auth-links" style="text-align: center;">
                <a href="login.php" class="auth-link">← Torna al Login</a>
                <span style="margin: 0 1rem;">|</span>
                <a href="register.php" class="auth-link">Crea Account</a>
            </div>
        </div>
    </div>

    <!-- JavaScript per validazione -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('resetForm');
            
            if (form) {
                const passwordInput = document.getElementById('password');
                const confirmPasswordInput = document.getElementById('confirm_password');
                const strengthBar = document.querySelector('.strength-fill');
                const strengthText = document.querySelector('.strength-text');

                // Validazione password in tempo reale
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    const strength = calculatePasswordStrength(password);
                    
                    strengthBar.style.width = strength.percentage + '%';
                    strengthBar.className = 'strength-fill strength-' + strength.level;
                    strengthText.textContent = strength.text;
                });

                // Verifica corrispondenza password
                confirmPasswordInput.addEventListener('input', function() {
                    if (this.value && this.value !== passwordInput.value) {
                        this.setCustomValidity('Le password non corrispondono');
                        this.classList.add('error');
                    } else {
                        this.setCustomValidity('');
                        this.classList.remove('error');
                    }
                });

                // Gestione submit form
                form.addEventListener('submit', function(e) {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const btnText = submitBtn.querySelector('.btn-text');
                    const btnLoader = submitBtn.querySelector('.btn-loader');
                    
                    // Validazioni
                    if (!passwordInput.value) {
                        e.preventDefault();
                        showAlert('La password è obbligatoria', 'error');
                        return;
                    }
                    
                    if (passwordInput.value !== confirmPasswordInput.value) {
                        e.preventDefault();
                        showAlert('Le password non corrispondono', 'error');
                        return;
                    }
                    
                    const strength = calculatePasswordStrength(passwordInput.value);
                    if (strength.percentage < 75) {
                        e.preventDefault();
                        showAlert('La password non è abbastanza sicura', 'error');
                        return;
                    }
                    
                    // Mostra loader
                    btnText.style.display = 'none';
                    btnLoader.style.display = 'flex';
                    submitBtn.disabled = true;
                });
            }

            // Funzione calcolo forza password
            function calculatePasswordStrength(password) {
                let score = 0;
                let feedback = [];
                
                if (password.length >= 8) score += 25;
                else feedback.push('almeno 8 caratteri');
                
                if (/[a-z]/.test(password)) score += 25;
                else feedback.push('lettere minuscole');
                
                if (/[A-Z]/.test(password)) score += 25;
                else feedback.push('lettere maiuscole');
                
                if (/[\d\W]/.test(password)) score += 25;
                else feedback.push('numeri o simboli');
                
                let level, text;
                if (score < 50) {
                    level = 'weak';
                    text = 'Debole - Aggiungi: ' + feedback.slice(0, 2).join(', ');
                } else if (score < 75) {
                    level = 'medium';
                    text = 'Media - Aggiungi: ' + feedback.join(', ');
                } else if (score < 100) {
                    level = 'good';
                    text = 'Buona - Aggiungi: ' + feedback.join(', ');
                } else {
                    level = 'strong';
                    text = 'Forte - Password sicura';
                }
                
                return { percentage: score, level: level, text: text };
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
        .form-control-static {
            padding: 0.75rem;
            background: #F8F9FA;
            border: 2px solid #E5E5E5;
            border-radius: 0.5rem;
            color: #6B7280;
            font-family: monospace;
            letter-spacing: 1px;
        }
        
        .auth-password-requirements,
        .auth-security-tips,
        .auth-error-causes {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #E5E5E5;
        }
        
        .auth-password-requirements h4,
        .auth-security-tips h4,
        .auth-error-causes h4 {
            font-size: 1rem;
            margin-bottom: 1rem;
            color: #1C1C1C;
        }
        
        .auth-password-requirements ul,
        .auth-security-tips ul,
        .auth-error-causes ul {
            padding-left: 1.5rem;
            color: #6B7280;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        
        .auth-password-requirements li,
        .auth-security-tips li,
        .auth-error-causes li {
            margin-bottom: 0.5rem;
        }
        
        .auth-success-message,
        .auth-error-message {
            text-align: center;
            padding: 2rem 0;
        }
        
        .success-icon,
        .error-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        
        .auth-success-message h3 {
            color: #00C281;
            margin-bottom: 1rem;
        }
        
        .auth-error-message h3 {
            color: #FF3B3B;
            margin-bottom: 1rem;
        }
        
        .auth-success-message p,
        .auth-error-message p {
            color: #6B7280;
            margin-bottom: 2rem;
            line-height: 1.5;
        }
        
        .success-actions,
        .error-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        @media (max-width: 767px) {
            .success-actions,
            .error-actions {
                flex-direction: column;
            }
        }
    </style>
</body>
</html>

<?php
/**
 * Funzione helper per mascherare email
 */
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    
    $username = $parts[0];
    $domain = $parts[1];
    
    $maskedUsername = substr($username, 0, 2) . str_repeat('*', max(1, strlen($username) - 4)) . substr($username, -2);
    
    return $maskedUsername . '@' . $domain;
}
?>

