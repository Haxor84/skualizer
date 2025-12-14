<?php
/**
 * Excel Download Handler
 * File: modules/margynomic/admin/creaexcel/views/download.php
 */

// Avvia sessione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../admin_helpers.php';
require_once __DIR__ . '/../ExcelListingManager.php';

requireAdmin();

$excelId = $_GET['id'] ?? null;

if (!$excelId) {
    die('ID Excel non specificato');
}

$manager = new ExcelListingManager();
$manager->downloadFile($excelId);
?>

