<?php
/**
 * Mobile Index - Landing Page Mobile
 * Redirect alla home mobile predefinita (OrderInsights)
 */

// Config e Auth
require_once __DIR__ . '/../margynomic/config/config.php';
require_once __DIR__ . '/../margynomic/login/auth_helpers.php';

if (!isLoggedIn()) {
    redirect('/modules/margynomic/login/login.php');
}

// Redirect alla home predefinita (OrderInsights come dashboard principale)
redirect('/modules/mobile/OrderInsights.php');

