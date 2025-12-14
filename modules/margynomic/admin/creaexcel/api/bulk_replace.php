<?php
/**
 * API: Bulk Find & Replace
 * File: modules/margynomic/admin/creaexcel/api/bulk_replace.php
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
$search = $input['search'] ?? null;
$replace = $input['replace'] ?? '';
$caseSensitive = $input['case_sensitive'] ?? false;
$columns = $input['columns'] ?? null; // Array di column letters o null = tutte

if (!$excelId || !$search) {
    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
    exit;
}

$bulkOps = new BulkOperations();
$result = $bulkOps->findReplace($excelId, $search, $replace, $caseSensitive, $columns);

echo json_encode($result);
?>

