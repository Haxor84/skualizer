<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * Pagina di Registrazione - Margynomic
 * File: register.php
 * Descrizione: Prima pagina del sistema, form di registrazione con validazione
 */

// Includi configurazione e helpers
require_once '../config/config.php';
require_once 'auth_helpers.php';
require_once 'UserModel.php';
require_once 'AuthController.php';

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

// Verifica sessione esistente
$authController->checkExistingSession();

// Gestisci form submission
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $authController->register();
    
    if ($result['success']) {
        // Redirect a login con messaggio di successo
        setFlashMessage('success', $result['message']);
redirect('login.php');
    } else {
        $message = $result['message'];
        $messageType = 'error';
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
    <title>Registrazione - <?php echo SITE_NAME; ?></title>
<link rel="stylesheet" href="../css/margynomic.css">
    <meta name="description" content="Registrati a Margynomic per analizzare i tuoi margini Amazon con precisione">
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
<p class="auth-subtitle" style="text-align: center;">Analizza ogni costo nascosto e scopri il vero potenziale dei tuoi prodotti.</p>


            <!-- Messaggi di errore/successo -->
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <span><?php echo $message; ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Form di registrazione -->
            <form id="registerForm" method="POST" action="" class="auth-form" novalidate>
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
    <div class="form-help">Riceverai il codice di accesso qui</div>
</div>

                <div class="form-group">
                    <label for="password" class="form-label">Password *</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="form-control" 
                        placeholder="Crea una password sicura"
                        required
                        autocomplete="new-password"
                    >
                    
                    <!-- Indicatore forza password -->
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-fill"></div>
                        </div>
                        <div class="strength-text">Inserisci una password</div>
                    </div>
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

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="terms" required>
                        <span>Accetto i <a href="#" class="auth-link">Termini di Servizio</a> e la <a href="#" class="auth-link">Privacy Policy</a> *</span>
                    </label>
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="newsletter" checked>
                        <span>Voglio ricevere sconti e promozioni</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-full">
    <span class="btn-text">Crea Account</span>
    <span class="btn-loader" style="display: none;">
        <div class="loader"></div>
        Creazione account...
    </span>
</button>
            </form>

            <!-- Link al login -->
<div class="auth-links" style="text-align: center; margin-top: 20px;">
    <span>Hai già un account?</span><br>
    <a href="login.php" class="btn btn-success" style="margin-top:10px;">Accedi ora</a>
</div>


            <!-- Card Benefici -->
<div class="auth-benefits" style="margin-top: 30px; padding: 20px; border-radius: 16px; background: #f4f6fb; box-shadow: 0 4px 12px rgba(0,0,0,0.08); text-align: center;">
    <h3 style="margin-bottom: 12px; color: #1a1a1a;">🚀 Perché sceglierci?</h3>
    <p style="font-size: 1rem; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto;">
        Scopri cosa ti fa davvero guadagnare. Margynomic scompone ogni costo, evidenzia i prodotti più profittevoli e ti mostra dove stai perdendo soldi. È lo strumento pensato per imprenditori che vogliono dominare Amazon con numeri reali e decisioni intelligenti.
    </p>
</div>

        </div>
    </div>

    <!-- JavaScript per validazione e UX -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('registerForm');
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
                
                // Mostra loader
                btnText.style.display = 'none';
                btnLoader.style.display = 'flex';
                submitBtn.disabled = true;
            });

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
        });
    </script>
</body>
</html>

