<?php

/**
 * Logout Admin Margynomic
 * File: admin/admin_logout.php
 * 
 * Logout e pulizia sessione
 */

require_once 'admin_helpers.php';

// Distruggi sessione
session_destroy();

// Redirect al login
redirect('admin_login.php');
?>

