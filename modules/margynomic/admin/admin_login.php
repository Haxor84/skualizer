<?php

/**
 * Login Admin Margynomic
 * File: admin/admin_login.php
 * 
 * Login semplice con credenziali fisse
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'admin_helpers.php';

// Se già loggato, redirect alla dashboard
if (isAdminLogged()) {
    redirect('admin_dashboard.php');
}

$error = '';

// Gestione form login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    
    // Verifica credenziali dal database
    $admin = verifyAdminCredentials($email, $password);
    if ($admin) {
        $_SESSION['admin_logged'] = true;
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_nome'] = $admin['nome'];
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_login_time'] = time();
        
        redirect('admin_dashboard.php');
    } else {
        $error = 'Credenziali non valide';
    }
}

echo getAdminHeader('Login Admin');
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header text-center bg-primary text-white">
                    <h4>Margynomic Admin</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo e($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label">Email Admin</label>
                            <input type="email" 
                                   class="form-control" 
                                   id="email" 
                                   name="email" 
                                   value="<?php echo e($_POST['email'] ?? ''); ?>"
                                   required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   required>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Accedi</button>
                        </div>
                    </form>
                    
                    <div class="mt-3 text-center">
                        <small class="text-muted">
                            Accesso riservato agli amministratori
                        </small>
                    </div>
                </div>
            </div>
            
            <!-- Info credenziali per sviluppo -->
            <div class="card mt-3 border-warning">
                <div class="card-body">
                    <h6 class="card-title text-warning">Credenziali di Test</h6>
                    <p class="card-text small">
                        <strong>Email:</strong> admin@margynomic.com<br>
                        <strong>Password:</strong> MargyAdmin2024!
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.container {
    margin-top: 5rem;
}
.card {
    border: none;
    border-radius: 10px;
}
.card-header {
    border-radius: 10px 10px 0 0 !important;
}
.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
}
.btn-primary:hover {
    background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
}
</style>

<?php echo getAdminFooter(); ?>

