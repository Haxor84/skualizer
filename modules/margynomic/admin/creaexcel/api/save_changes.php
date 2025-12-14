<?php
/**
 * API: Save Changes from Editor
 * File: modules/margynomic/admin/creaexcel/api/save_changes.php
 */

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../admin_helpers.php';
require_once __DIR__ . '/../ExcelListingManager.php';

// Verifica admin
if (!isAdminLogged()) {
    echo json_encode(['success' => false, 'error' => 'Non autorizzato']);
    exit;
}

// Leggi input JSON
$input = json_decode(file_get_contents('php://input'), true);

$excelId = $input['excel_id'] ?? null;
$changes = $input['changes'] ?? [];

if (!$excelId || empty($changes)) {
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

$manager = new ExcelListingManager();
$result = $manager->saveChanges($excelId, $changes);

echo json_encode($result);
?>

