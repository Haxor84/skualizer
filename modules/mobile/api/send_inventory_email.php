<?php
/**
 * API Mobile - Invia Report Inventario via Email
 * Riusa completamente il sistema email esistente da email_notifications.php
 */

// IMPORTANTE: Cattura TUTTO l'output per JSON pulito
ob_start();

// Disabilita display_errors (solo error_log)
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../../margynomic/config/config.php';
require_once __DIR__ . '/../../margynomic/login/auth_helpers.php';

// Pulisci TUTTI gli output buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Riavvia output buffering pulito
ob_start();

header('Content-Type: application/json');

// Verifica autenticazione
if (!isLoggedIn()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

$currentUser = getCurrentUser();
$userId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);

if (!$userId) {
    ob_end_clean();
    echo json_encode(['success' => false, 'error' => 'User ID non valido']);
    exit;
}

try {
    // Ottieni dati utente completi
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, nome, email FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Utente non trovato']);
        exit;
    }
    
    // Prepara dati per email_notifications (richiede campo email_address)
    $user['email_address'] = $user['email'];
    
    // IMPORTANTE: Previene inclusione header HTML in inventory.php
    define('NO_HTML_OUTPUT', true);
    $_SERVER['HTTP_USER_AGENT'] = 'MobileAPI/1.0';
    
    // Includi sistema email esistente (solo definizioni funzioni, no esecuzione)
    ob_start();
    require_once __DIR__ . '/../../previsync/email_notifications.php';
    ob_end_clean();
    
    // Genera PDF usando funzione esistente (cattura TUTTO l'output)
    ob_start();
    $pdfPath = generateInventoryPDF($userId);
    ob_end_clean();
    
    if (!$pdfPath) {
        while (ob_get_level()) ob_end_clean();
        echo json_encode([
            'success' => false, 
            'error' => 'Nessun prodotto da rifornire o errore generazione PDF'
        ]);
        exit;
    }
    
    // Invia email usando funzione esistente (cattura TUTTO l'output)
    ob_start();
    $emailSent = sendInventoryEmail($user, $pdfPath);
    ob_end_clean();
    
    // Pulisci file temporaneo
    if (file_exists($pdfPath)) {
        unlink($pdfPath);
    }
    
    // Pulisci tutto l'output accumulato prima di rispondere
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    if ($emailSent) {
        echo json_encode([
            'success' => true,
            'message' => 'Report inviato con successo',
            'email' => $user['email']
        ]);
        exit;
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Errore invio email. Riprova più tardi.'
        ]);
        exit;
    }
    
} catch (Exception $e) {
    // Pulisci TUTTI gli output buffer prima di rispondere
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Errore interno'
    ]);
    exit;
}