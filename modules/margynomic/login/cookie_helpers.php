<?php
/**
 * Funzioni per la gestione del cookie "remember_me"
 */
function clearRememberMeCookie(array $paths = ['/']) {
    foreach ($paths as $p) {
        // Usa gli stessi flag impostati in fase di login → secure=true, httponly=true
        setcookie('remember_me', '', time() - 3600, $p, '', true, true);
    }
    unset($_COOKIE['remember_me']);
}
