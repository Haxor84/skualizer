<?php
/**
 * API: Sync Prices from Products
 * File: modules/margynomic/admin/creaexcel/api/sync_prices.php
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../admin_helpers.php';
require_once __DIR__ . '/../BulkOperations.php';

// Verifica admin
if (!isAdminLogged()) {
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

// Leggi input JSON
$input = json_decode(file_get_contents('php://input'), true);

$excelId = $input['excel_id'] ?? null;

if (!$excelId) {
    echo json_encode(['success' => false, 'error' => 'ID Excel mancante']);
    exit;
}

$bulkOps = new BulkOperations();
$result = $bulkOps->syncPricesFromProducts($excelId);

echo json_encode($result);
?>

