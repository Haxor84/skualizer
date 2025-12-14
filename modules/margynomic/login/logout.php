<?php

/**
 * Logout - Margynomic
 * File: logout.php
 */

require_once 'cookie_helpers.php';

// Avvia la sessione se non è già attiva
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$sessionName = session_name();
$paths       = ['/', '/modules/', '/modules/margynomic/', '/modules/margynomic/login/'];

// 1. Svuota l’array di sessione
$_SESSION = [];

// 2. Elimina i cookie di sessione e PHPSESSID su tutti i path
foreach ($paths as $p) {
    setcookie($sessionName, '', time() - 3600, $p, '', true, true);
    setcookie('PHPSESSID',  '', time() - 3600, $p, '', true, true);
}

// 3. Elimina il cookie “remember_me”
clearRememberMeCookie($paths);

// 4. Distruggi la sessione
session_destroy();

// 5. Redirect con cache busting
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Verifica se c'è un redirect specifico
$redirectTo = $_GET['redirect_to'] ?? 'login';
$redirectPage = ($redirectTo === 'forgot_password') ? 'forgot_password.php' : 'login.php';

header("Location: $redirectPage");
exit();
?>
