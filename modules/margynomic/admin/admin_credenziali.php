<?php

/**
 * Gestione Credenziali Amazon SP-API
 * File: admin/admin_credenziali.php
 * 
 * Gestione completa credenziali Amazon - Versione Moderna
 */

require_once 'admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

$message = '';
$messageType = 'success';

// Gestione modalità edit
$editMode = false;
$editCredential = null;
if (isset($_GET['edit_id'])) {
    $editId = intval($_GET['edit_id']);
    $editCredential = getCredentialById($editId);
    if ($editCredential) {
        $editMode = true;
    }
}

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $awsAccessKey = trim($_POST['aws_access_key'] ?? '');
            $awsSecretKey = trim($_POST['aws_secret_key'] ?? '');
            $awsRegion = trim($_POST['aws_region'] ?? 'eu-west-1');
            $clientId = trim($_POST['client_id'] ?? '');
            $clientSecret = trim($_POST['client_secret'] ?? '');
            
            if (empty($awsAccessKey) || empty($awsSecretKey) || empty($clientId) || empty($clientSecret)) {
                $message = 'Tutti i campi sono obbligatori';
                $messageType = 'error';
            } else {
                if (addAmazonCredentials($awsAccessKey, $awsSecretKey, $awsRegion, $clientId, $clientSecret)) {
                    $message = 'Credenziali Amazon aggiunte con successo';
                } else {
                    $message = 'Errore durante l\'aggiunta delle credenziali';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'update':
            $credentialId = intval($_POST['credential_id'] ?? 0);
            $awsAccessKey = trim($_POST['aws_access_key'] ?? '');
            $awsSecretKey = trim($_POST['aws_secret_key'] ?? '');
            $awsRegion = trim($_POST['aws_region'] ?? 'eu-west-1');
            $clientId = trim($_POST['client_id'] ?? '');
            $clientSecret = trim($_POST['client_secret'] ?? '');
            
            if (empty($awsAccessKey) || empty($awsSecretKey) || empty($clientId) || empty($clientSecret)) {
                $message = 'Tutti i campi sono obbligatori';
                $messageType = 'error';
            } else {
                if (updateAmazonCredentials($credentialId, $awsAccessKey, $awsSecretKey, $awsRegion, $clientId, $clientSecret)) {
                    $message = 'Credenziali aggiornate con successo! ✅';
                    // Redirect per rimuovere edit_id dall'URL
                    header('Location: admin_credenziali.php?updated=1');
                    exit;
                } else {
                    $message = 'Errore durante l\'aggiornamento delle credenziali';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'toggle':
            $credentialId = intval($_POST['credential_id'] ?? 0);
            if ($credentialId > 0) {
                if (toggleCredentialStatus($credentialId)) {
                    $message = 'Stato credenziali aggiornato';
                } else {
                    $message = 'Errore durante l\'aggiornamento';
                    $messageType = 'error';
                }
            }
            break;
            
        case 'delete':
            $credentialId = intval($_POST['credential_id'] ?? 0);
            if ($credentialId > 0) {
                if (deleteCredential($credentialId)) {
                    $message = 'Credenziali eliminate con successo';
                } else {
                    $message = 'Errore durante l\'eliminazione';
                    $messageType = 'error';
                }
            }
            break;
    }
}

// Messaggio di successo dopo redirect
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $message = 'Credenziali aggiornate con successo! ✅';
}

// Ottieni credenziali esistenti
$credentials = getAdminAmazonCredentials();

echo getAdminHeader('Gestione Credenziali');
echo getAdminNavigation('credenziali');
?>

<style>
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        background: #f8f9fa;
        color: #333;
    }
    
    .main-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    .dashboard-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 12px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }

    .dashboard-title {
        font-size: 28px;
        font-weight: 700;
        margin: 0 0 8px 0;
    }

    .dashboard-subtitle {
        font-size: 16px;
        opacity: 0.9;
        margin: 0;
    }

    .section {
        background: white;
        padding: 24px;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        margin-bottom: 30px;
    }

    .section:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(0,0,0,0.12);
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #f1f3f4;
    }

    .section-title {
        font-size: 20px;
        font-weight: 600;
        color: #1a1a1a;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .form-control {
        border: 2px solid #e9ecef;
        border-radius: 6px;
        padding: 8px 12px;
        font-size: 14px;
        transition: all 0.3s ease;
        background: #fafbfc;
        width: 100%;
        box-sizing: border-box;
    }

    .form-control:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        background: white;
    }

    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }

    .btn-primary { 
        background: #667eea; 
        color: white; 
    }
    
    .btn-primary:hover { 
        background: #5a6fd8; 
    }
    
    .btn-secondary { 
        background: #6c757d; 
        color: white; 
    }
    
    .btn-secondary:hover { 
        background: #545b62; 
    }
    
    .btn-success { 
        background: #28a745; 
        color: white; 
    }
    
    .btn-success:hover { 
        background: #218838; 
    }
    
    .btn-danger { 
        background: #dc3545; 
        color: white; 
    }
    
    .btn-danger:hover { 
        background: #c82333; 
    }

    .btn-sm { 
        padding: 6px 12px; 
        font-size: 12px; 
    }
    
    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .status-active { 
        background: #d4edda; 
        color: #155724; 
    }
    
    .status-inactive { 
        background: #f8d7da; 
        color: #721c24; 
    }

    .users-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 16px;
        font-size: 13px;
    }

    .users-table th,
    .users-table td {
        padding: 10px 8px;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }

    .users-table th {
        background: #f8f9fa;
        font-weight: 600;
        color: #495057;
        font-size: 13px;
        white-space: nowrap;
    }

    .users-table td {
        font-size: 13px;
    }

    .users-table tr:hover {
        background: #f8f9fa;
    }
    
    .table-wrapper {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }



    .alert {
        padding: 12px 16px;
        border-radius: 6px;
        margin-bottom: 20px;
        font-weight: 500;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .info-section {
        background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        padding: 25px;
        border-radius: 15px;
        margin-top: 30px;
    }

    .info-section h4 {
        color: #2c3e50;
        margin-bottom: 15px;
        font-weight: 700;
    }

    .info-list {
        margin: 0;
        padding-left: 20px;
    }

    .info-list li {
        margin-bottom: 8px;
        color: #34495e;
    }

    .security-warning {
        background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
        padding: 20px;
        border-radius: 10px;
        margin-top: 20px;
        border-left: 4px solid #f39c12;
    }

    .credential-id {
        background: #e9ecef;
        padding: 4px 8px;
        border-radius: 6px;
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        color: #6c757d;
    }

    .form-label {
        font-weight: 600;
        color: #495057;
        margin-bottom: 8px;
        display: block;
    }

    .row {
        display: flex;
        flex-wrap: wrap;
        margin: -10px;
    }

    .col-md-6 {
        flex: 0 0 50%;
        padding: 10px;
    }

    .col-12 {
        flex: 0 0 100%;
        padding: 10px;
    }

    .mb-3 {
        margin-bottom: 20px;
    }

    .text-center {
        text-align: center;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 4rem;
        margin-bottom: 20px;
        opacity: 0.5;
    }

    @media (max-width: 768px) {
        .main-container {
            padding: 15px;
        }
        
        .dashboard-header {
            padding: 20px;
        }
        
        .dashboard-title {
            font-size: 24px;
        }
        
        .col-md-6 {
            flex: 0 0 100%;
        }
        
        .users-table {
            font-size: 0.9rem;
        }
        
        .users-table th,
        .users-table td {
            padding: 12px 10px;
        }
    }
</style>

<div class="main-container">
    <div class="dashboard-header">
        <h1 class="dashboard-title">
            <i class="fas fa-key"></i>
            Gestione Credenziali
        </h1>
        <p class="dashboard-subtitle">Configura le credenziali per l'accesso alle API Amazon SP-API</p>
    </div>
    
    <?php if ($message): ?>
        <div class="alert <?php echo $messageType === 'success' ? 'alert-success' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Form Aggiunta/Modifica Credenziali -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-<?php echo $editMode ? 'edit' : 'plus'; ?>"></i>
                <?php echo $editMode ? '✏️ Modifica Credenziali Amazon' : 'Aggiungi Nuove Credenziali Amazon'; ?>
            </h2>
            <?php if ($editMode): ?>
                <a href="admin_credenziali.php" class="btn btn-secondary btn-sm">
                    ❌ Annulla Modifica
                </a>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($editMode): ?>
                <div class="alert" style="background: #e3f2fd; color: #1565c0; border: 1px solid #90caf9; margin-bottom: 20px;">
                    <strong>ℹ️ Modalità Modifica:</strong> I campi sono pre-compilati con i valori attuali. Modifica solo i campi che vuoi aggiornare (es. solo il Client Secret per la rotazione).
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update' : 'add'; ?>">
                <?php if ($editMode): ?>
                    <input type="hidden" name="credential_id" value="<?php echo $editCredential['id']; ?>">
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="aws_access_key" class="form-label">🔑 AWS Access Key ID</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="aws_access_key" 
                                   name="aws_access_key" 
                                   placeholder="AKIA..."
                                   value="<?php echo $editMode ? htmlspecialchars($editCredential['aws_access_key_id']) : ''; ?>"
                                   required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="aws_secret_key" class="form-label">🔐 AWS Secret Access Key</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="aws_secret_key" 
                                   name="aws_secret_key" 
                                   placeholder="Chiave segreta AWS"
                                   value="<?php echo $editMode ? htmlspecialchars($editCredential['aws_secret_access_key']) : ''; ?>"
                                   required>
                            <?php if ($editMode): ?>
                                <small style="color: #666; font-size: 0.85em;">💡 Campo visibile in modifica per verificare il valore</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="aws_region" class="form-label">🌍 AWS Region</label>
                            <select class="form-control" id="aws_region" name="aws_region">
                                <option value="eu-west-1" <?php echo ($editMode && $editCredential['aws_region'] === 'eu-west-1') ? 'selected' : ''; ?>>EU West 1 (Irlanda)</option>
                                <option value="us-east-1" <?php echo ($editMode && $editCredential['aws_region'] === 'us-east-1') ? 'selected' : ''; ?>>US East 1 (Virginia)</option>
                                <option value="us-west-2" <?php echo ($editMode && $editCredential['aws_region'] === 'us-west-2') ? 'selected' : ''; ?>>US West 2 (Oregon)</option>
                                <option value="ap-southeast-1" <?php echo ($editMode && $editCredential['aws_region'] === 'ap-southeast-1') ? 'selected' : ''; ?>>Asia Pacific (Singapore)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="client_id" class="form-label">🆔 SP-API Client ID</label>
                            <input type="text" 
                                   class="form-control" 
                                   id="client_id" 
                                   name="client_id" 
                                   placeholder="amzn1.application-oa2-client..."
                                   value="<?php echo $editMode ? htmlspecialchars($editCredential['spapi_client_id']) : ''; ?>"
                                   required>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label for="client_secret" class="form-label">
                        🔒 SP-API Client Secret
                        <?php if ($editMode): ?>
                            <span style="color: #dc3545; font-weight: bold;">⚠️ ROTAZIONE</span>
                        <?php endif; ?>
                    </label>
                    <input type="text" 
                           class="form-control" 
                           id="client_secret" 
                           name="client_secret" 
                           placeholder="<?php echo $editMode ? 'Inserisci il nuovo Client Secret' : 'Client Secret dell\'applicazione'; ?>"
                           value="<?php echo $editMode ? htmlspecialchars($editCredential['spapi_client_secret']) : ''; ?>"
                           required
                           style="<?php echo $editMode ? 'border: 2px solid #ffc107; background: #fffbf0;' : ''; ?>">
                    <?php if ($editMode): ?>
                        <small style="color: #f39c12; font-size: 0.85em; font-weight: 600;">
                            ⚠️ Questo è il campo principale da aggiornare per la rotazione del secret
                        </small>
                    <?php endif; ?>
                </div>
                
                <div class="text-center">
                    <button type="submit" class="btn btn-primary" style="padding: 12px 32px; font-size: 16px;">
                        <?php echo $editMode ? '💾 Aggiorna Credenziali' : '✅ Salva Credenziali'; ?>
                    </button>
                    <?php if ($editMode): ?>
                        <a href="admin_credenziali.php" class="btn btn-secondary" style="padding: 12px 32px; font-size: 16px;">
                            ❌ Annulla
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista Credenziali Esistenti -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <i class="fas fa-list"></i>
                Credenziali Configurate (<?php echo count($credentials); ?>)
            </h2>
        </div>
        <div>
            <?php if (empty($credentials)): ?>
                <div class="empty-state">
                    <div style="font-size: 4rem; margin-bottom: 20px; opacity: 0.5;">🔑</div>
                    <h4>Nessuna credenziale configurata</h4>
                    <p>Aggiungi le tue credenziali Amazon per iniziare ad utilizzare Margynomic</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="users-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>🔑 AWS Access Key</th>
                                <th>🆔 SP-API Client ID</th>
                                <th>🌍 Regione</th>
                                <th>📊 Stato</th>
                                <th>📅 Creato</th>
                                <th>🔄 Aggiornato</th>
                                <th style="width: 200px;">⚙️ Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($credentials as $cred): ?>
                                <tr style="<?php echo ($editMode && $editCredential['id'] == $cred['id']) ? 'background: #fff3cd;' : ''; ?>">
                                    <td>
                                        <span class="credential-id">#<?php echo $cred['id']; ?></span>
                                    </td>
                                    <td>
                                        <code style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; color: #495057;">
                                            <?php echo htmlspecialchars($cred['aws_access_key_id']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <code style="background: #e3f2fd; padding: 4px 8px; border-radius: 4px; font-size: 0.85em; color: #1565c0;">
                                            <?php echo htmlspecialchars($cred['spapi_client_id']); ?>
                                        </code>
                                    </td>
                                    <td>
                                        <strong><?php echo strtoupper($cred['aws_region']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $cred['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo $cred['is_active'] ? '✅ ATTIVA' : '⏸️ INATTIVA'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y H:i', strtotime($cred['created_at'])); ?>
                                    </td>
                                    <td>
                                        <?php echo $cred['updated_at'] ? date('d/m/Y H:i', strtotime($cred['updated_at'])) : '-'; ?>
                                    </td>
                                    <td>
                                        <a href="admin_credenziali.php?edit_id=<?php echo $cred['id']; ?>" 
                                           class="btn btn-primary btn-sm"
                                           title="Modifica credenziali">
                                            ✏️
                                        </a>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="credential_id" value="<?php echo $cred['id']; ?>">
                                            <button type="submit" 
                                                    class="btn btn-success btn-sm"
                                                    title="<?php echo $cred['is_active'] ? 'Disattiva' : 'Attiva'; ?>">
                                                <?php echo $cred['is_active'] ? '⏸️' : '▶️'; ?>
                                            </button>
                                        </form>
                                        
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="credential_id" value="<?php echo $cred['id']; ?>">
                                            <button type="submit" 
                                                    class="btn btn-danger btn-sm"
                                                    onclick="return confirm('Sei sicuro di voler eliminare queste credenziali? L\'azione non può essere annullata.')"
                                                    title="Elimina">
                                                🗑️
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Informazioni e Guida -->
    <div class="info-section">
        <h4>📋 Informazioni Configurazione</h4>
        <ul class="info-list">
            <li><strong>AWS Access Key:</strong> Chiave di accesso per i servizi AWS, necessaria per l'autenticazione</li>
            <li><strong>AWS Secret Key:</strong> Chiave segreta associata alla Access Key per la sicurezza</li>
            <li><strong>SP-API Client ID:</strong> ID dell'applicazione registrata in Amazon Developer Console</li>
            <li><strong>SP-API Client Secret:</strong> Segreto dell'applicazione per l'autenticazione OAuth</li>
            <li><strong>Regione:</strong> Regione AWS dove sono ospitati i servizi (eu-west-1 consigliata per Europa)</li>
        </ul>
    </div>
    
    <div class="security-warning">
        <h4>🔒 Avviso Sicurezza</h4>
        <p><strong>Importante:</strong> Le credenziali sono memorizzate in modo sicuro nel database. Assicurati che:</p>
        <ul style="margin-top: 10px;">
            <li>Il database sia protetto con password robuste</li>
            <li>L'accesso sia limitato solo al personale autorizzato</li>
            <li>Non condividere mai le credenziali AWS o SP-API</li>
            <li>Monitora regolarmente l'utilizzo delle API Amazon</li>
        </ul>
    </div>
</div>

<?php echo getAdminFooter(); ?>