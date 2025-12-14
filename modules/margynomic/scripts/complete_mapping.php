<?php
/**
 * Script Mapping Completo - Solo Admin
 * File: scripts/complete_mapping.php
 */

require_once '../config/config.php';
require_once '../login/auth_helpers.php';
require_once '../sincro/retroactive_mapping.php';

// Verifica autenticazione e privilegi admin
if (!isLoggedIn()) {
    header('Location: ../login/login.php');
    exit();
}

$currentUser = getCurrentUser();
if ($currentUser['ruolo'] !== 'admin') {
    die('❌ Accesso negato. Solo gli amministratori possono utilizzare questa funzione.');
}

$retroMapper = new RetroactiveMapping();
$message = '';
$messageType = '';

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'single_user' && !empty($_POST['user_id'])) {
        $userId = intval($_POST['user_id']);
        $result = $retroMapper->processAllUnmappedSkus($userId);
        
        if ($result['success']) {
            $message = "✅ Mapping completato per User ID {$userId}! 
                       Auto-mappati: {$result['auto_mapped']}, 
                       AI processati: {$result['ai_processed']}, 
                       Mapping: {$result['mapping_percentage']}%";
            $messageType = 'success';
        } else {
            $message = "❌ Errore: " . $result['error'];
            $messageType = 'error';
        }
    }
    
    elseif ($action === 'all_users') {
        $results = $retroMapper->processAllUsers();
        $successCount = 0;
        $totalProcessed = 0;
        
        foreach ($results as $result) {
            if ($result['success']) {
                $successCount++;
                $totalProcessed += $result['ai_processed'];
            }
        }
        
        $message = "✅ Mapping completato per {$successCount} utenti! 
                   Totale SKU processati con AI: {$totalProcessed}";
        $messageType = 'success';
        
        // Salva risultati dettagliati
        $_SESSION['mapping_results'] = $results;
    }
}

// Ottieni lista utenti
$allUsers = $retroMapper->getAllUsers();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🔄 Mapping Completo - Admin</title>
    <link rel="stylesheet" href="../css/margynomic.css">
    <style>
        .admin-container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .admin-card { background: white; border-radius: 1rem; padding: 2rem; margin-bottom: 2rem; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .user-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 1rem 0; }
        .stat-card { background: #f8f9fa; padding: 1rem; border-radius: 0.5rem; text-align: center; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #000; }
        .results-table { max-height: 400px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="admin-container">
        <h1>🔄 Sistema Mapping Completo</h1>
        <p>Strumento amministrativo per mappare tutti i report esistenti nel sistema.</p>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistiche Utenti -->
        <div class="admin-card">
            <h2>📊 Statistiche Utenti</h2>
            <div class="user-stats">
                <?php foreach ($allUsers as $user): ?>
                    <?php $stats = $retroMapper->getUserStats($user['id']); ?>
                    <div class="stat-card">
                        <strong><?php echo htmlspecialchars($user['nome']); ?></strong><br>
                        <small>ID: <?php echo $user['id']; ?></small><br>
                        <?php if ($stats['exists']): ?>
                            Righe: <?php echo number_format($stats['total_rows']); ?><br>
                            Mappate: <?php echo $stats['mapping_percentage']; ?>%<br>
                            SKU non mappati: <?php echo $stats['unmapped_skus']; ?>
                        <?php else: ?>
                            <em>Nessun dato</em>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Azioni Mapping -->
        <div class="admin-card">
            <h2>🎯 Azioni Mapping</h2>
            
            <form method="POST" style="margin-bottom: 2rem;">
                <h3>👤 Mapping Singolo Utente</h3>
                <div style="display: flex; gap: 1rem; align-items: end;">
                    <div class="form-group" style="flex: 1;">
                        <label>Seleziona Utente:</label>
                        <select name="user_id" class="form-control" required>
                            <option value="">-- Seleziona Utente --</option>
                            <?php foreach ($allUsers as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['nome']); ?> (ID: <?php echo $user['id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="action" value="single_user" class="btn btn-primary">
                        🚀 Mappa Utente
                    </button>
                </div>
            </form>
            
            <form method="POST" onsubmit="return confirm('⚠️ Questa operazione processerà TUTTI gli utenti. Sei sicuro?');">
                <h3>👥 Mapping Globale</h3>
                <p><strong>⚠️ ATTENZIONE:</strong> Questa operazione processerà tutti gli utenti del sistema. Può richiedere diversi minuti.</p>
                <button type="submit" name="action" value="all_users" class="btn btn-danger">
                    🌍 Mappa Tutti gli Utenti
                </button>
            </form>
        </div>
        
        <!-- Risultati Dettagliati -->
        <?php if (isset($_SESSION['mapping_results'])): ?>
            <div class="admin-card">
                <h2>📋 Risultati Dettagliati</h2>
                <div class="results-table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Utente</th>
                                <th>Auto-Mappati</th>
                                <th>AI Processati</th>
                                <th>Errori AI</th>
                                <th>Mapping %</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($_SESSION['mapping_results'] as $userId => $result): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($result['user_name'] ?? "User {$userId}"); ?></strong><br>
                                        <small>ID: <?php echo $userId; ?></small>
                                    </td>
                                    <td><?php echo $result['auto_mapped'] ?? 0; ?></td>
                                    <td><?php echo $result['ai_processed'] ?? 0; ?></td>
                                    <td><?php echo $result['ai_errors'] ?? 0; ?></td>
                                    <td><?php echo $result['mapping_percentage'] ?? 0; ?>%</td>
                                    <td>
                                        <?php if ($result['success']): ?>
                                            <span class="status-badge status-mapped">✅ OK</span>
                                        <?php else: ?>
                                            <span class="status-badge status-unmapped">❌ Errore</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php unset($_SESSION['mapping_results']); ?>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 2rem;">
            <a href="../profilo_utente.php" class="btn btn-outline">🔙 Torna al Profilo</a>
            <a href="../margini/margini.php" class="btn btn-primary">📊 Vai alla Dashboard</a>
        </div>
    </div>
</body>
</html>