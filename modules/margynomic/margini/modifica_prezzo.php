<?php
/**
 * Notifica Email Cambio Prezzo
 */

// Include configurazione e sistema email
require_once 'config_shared.php';
require_once dirname(__DIR__) . '/gestione_vendor.php';

/**
 * Invia notifica email per cambio prezzo
 */
function inviaNotificaCambioPrezzo($userId, $productId, $nuovoPrezzo) {
    try {
        // Recupera dati utente e prodotto
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            SELECT u.nome as user_name, p.nome as product_name, p.asin 
            FROM users u 
            INNER JOIN products p ON p.user_id = u.id 
            WHERE u.id = ? AND p.id = ?
        ");
        $stmt->execute([$userId, $productId]);
        $data = $stmt->fetch();
        
        if (!$data) {
            throw new Exception("Dati utente/prodotto non trovati");
        }
        
        // Compone email
        $subject = "🔄 Cambio Prezzo Prodotto - {$data['product_name']}";
        
        $htmlBody = "
        <h3>Notifica Cambio Prezzo</h3>
        <p><strong>Utente:</strong> {$data['user_name']}</p>
        <p><strong>Prodotto:</strong> {$data['product_name']}</p>
        <p><strong>ASIN:</strong> {$data['asin']}</p>
        <p><strong>Nuovo Prezzo:</strong> €" . number_format($nuovoPrezzo, 2, ',', '.') . "</p>
        <p><em>Margynomic AI - " . date('d/m/Y H:i') . "</em></p>
        ";
        
        // Invia email admin
        return inviaEmailSMTP('haxor84@gmail.com', $subject, $htmlBody);
        
    } catch (Exception $e) {
        CentralLogger::log('margini', 'ERROR', 'Errore notifica cambio prezzo: ' . $e->getMessage());
        return false;
    }
}

// Se chiamato direttamente con parametri
if (isset($_GET['user_id']) && isset($_GET['product_id']) && isset($_GET['nuovo_prezzo'])) {
    $result = inviaNotificaCambioPrezzo(
        (int)$_GET['user_id'],
        (int)$_GET['product_id'], 
        (float)$_GET['nuovo_prezzo']
    );
    
    echo json_encode(['success' => $result]);
}
?>