<?php
/**
 * Admin EasyShip - Pannello Amministrativo Spedizioni
 * File: modules/margynomic/admin/admin_easyship.php
 * 
 * Pannello admin centralizzato per gestione spedizioni EasyShip
 * Integrato nel sistema admin Margynomic
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'admin_helpers.php';

// Verifica autenticazione admin
requireAdmin();

// Include funzioni EasyShip
require_once '../../easyship/config_easyship.php';

/**
 * Sanitizza nome file per sicurezza
 */
function sanitizeFileName($name) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
}

// === GESTIONE AJAX ===
$action = $_REQUEST['action'] ?? null;
if ($action) {
    header('Content-Type: application/json');
    
    try {
        switch ($action) {
            case 'getAllShipments':
                $db = getDbConnection();
                $stmt = $db->prepare("
                    SELECT s.id, s.name, s.status, s.user_id,
                           DATE_FORMAT(s.created_at, '%d-%m-%Y %H:%i') as created_at,
                           COUNT(DISTINCT si.box_no) as total_boxes,
                           SUM(si.quantity) as total_units,
                           u.nome as user_name, u.email as user_email
                    FROM shipments s
                    LEFT JOIN shipment_items si ON s.id = si.shipment_id
                    LEFT JOIN users u ON s.user_id = u.id
                    GROUP BY s.id
                    ORDER BY s.id DESC
                ");
                $stmt->execute();
                $shipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $shipments]);
                break;
                
            case 'getShipmentDetails':
                $id = intval($_GET['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'error' => 'ID spedizione non valido']);
                    break;
                }
                
                $db = getDbConnection();
                
                // Info spedizione
                $stmt = $db->prepare("
                    SELECT s.*, u.nome as user_name, u.email as user_email
                    FROM shipments s
                    LEFT JOIN users u ON s.user_id = u.id
                    WHERE s.id = ?
                ");
                $stmt->execute([$id]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_encode(['success' => false, 'error' => 'Spedizione non trovata']);
                    break;
                }
                
                // Items per box
                $stmt = $db->prepare("
                    SELECT si.box_no, si.product_name, si.quantity, si.expiry_date,
                           sb.peso, sb.larghezza, sb.lunghezza, sb.altezza
                    FROM shipment_items si
                    LEFT JOIN shipment_boxes sb ON (si.shipment_id = sb.shipment_id AND si.box_no = sb.box_no)
                    WHERE si.shipment_id = ?
                    ORDER BY si.box_no, si.product_name
                ");
                $stmt->execute([$id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Organizza per box
                $boxes = [];
                foreach ($items as $item) {
                    $boxNum = $item['box_no'];
                    if (!isset($boxes[$boxNum])) {
                        $boxes[$boxNum] = [
                            'numero' => $boxNum,
                            'peso' => $item['peso'],
                            'larghezza' => $item['larghezza'],
                            'lunghezza' => $item['lunghezza'],
                            'altezza' => $item['altezza'],
                            'prodotti' => []
                        ];
                    }
                    $boxes[$boxNum]['prodotti'][] = [
                        'nome' => $item['product_name'],
                        'quantita' => $item['quantity'],
                        'scadenza' => $item['expiry_date']
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'shipment' => $shipment,
                    'boxes' => array_values($boxes)
                ]);
                break;
                
            case 'uploadBollaFile':
                $spedizioneId = intval($_POST['spedizione_id'] ?? 0);
                $boxNum = intval($_POST['box_num'] ?? 0);
                
                if ($spedizioneId <= 0 || $boxNum <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
                    break;
                }
                
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'error' => 'File non valido']);
                    break;
                }
                
                $file = $_FILES['file'];
                if ($file['type'] !== 'application/pdf') {
                    echo json_encode(['success' => false, 'error' => 'Solo file PDF sono accettati']);
                    break;
                }
                
                if ($file['size'] > EASYSHIP_MAX_FILE_SIZE) {
                    echo json_encode(['success' => false, 'error' => 'File troppo grande (max 5MB)']);
                    break;
                }
                
                // Ottieni nome spedizione
                $db = getDbConnection();
                $stmt = $db->prepare("SELECT name FROM shipments WHERE id = ?");
                $stmt->execute([$spedizioneId]);
                $shipmentName = $stmt->fetchColumn();
                
                if (!$shipmentName) {
                    echo json_encode(['success' => false, 'error' => 'Spedizione non trovata']);
                    break;
                }
                
                // Crea directory se non esiste
                $dirPath = EASYSHIP_BASE_DIR . sanitizeFileName($shipmentName) . '/';
                if (!is_dir($dirPath)) {
                    mkdir($dirPath, 0755, true);
                }
                
                // Nome file sicuro
                $fileName = "Box{$boxNum}_" . sanitizeFileName($shipmentName) . '.pdf';
                $filePath = $dirPath . $fileName;
                
                if (move_uploaded_file($file['tmp_name'], $filePath)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'File caricato con successo',
                        'file_path' => $filePath
                    ]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Errore salvataggio file']);
                }
                break;
                
            case 'sendEmailBolla':
                $spedizioneId = intval($_POST['spedizione_id'] ?? 0);
                $boxNum = intval($_POST['box_num'] ?? 0);
                
                if ($spedizioneId <= 0 || $boxNum <= 0) {
                    echo json_encode(['success' => false, 'error' => 'Parametri mancanti']);
                    break;
                }
                
                
                $db = getDbConnection();
                
                // Info spedizione e utente
                $stmt = $db->prepare("
                    SELECT s.name, s.user_id, u.nome as user_name, u.email as user_email
                    FROM shipments s
                    LEFT JOIN users u ON s.user_id = u.id
                    WHERE s.id = ?
                ");
                $stmt->execute([$spedizioneId]);
                $shipment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$shipment) {
                    echo json_encode(['success' => false, 'error' => 'Spedizione non trovata']);
                    break;
                }
                
                // Prodotti del box
                $stmt = $db->prepare("
                    SELECT product_name, quantity, expiry_date
                    FROM shipment_items
                    WHERE shipment_id = ? AND box_no = ?
                ");
                $stmt->execute([$spedizioneId, $boxNum]);
                $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($products)) {
                    echo json_encode(['success' => false, 'error' => 'Nessun prodotto trovato']);
                    break;
                }
                
                // Genera contenuto email
                $prodottiLista = "";
                foreach ($products as $prod) {
                    $scadenza = $prod['expiry_date'] ? date('d/m/Y', strtotime($prod['expiry_date'])) : 'N/A';
                    $prodottiLista .= "<tr>";
                    $prodottiLista .= "<td>{$prod['product_name']}</td>";
                    $prodottiLista .= "<td style='text-align: center;'>{$prod['quantity']}</td>";
                    $prodottiLista .= "<td style='text-align: center;'>{$scadenza}</td>";
                    $prodottiLista .= "</tr>";
                }
                
                $subject = "📋 Bolla Box {$boxNum} - {$shipment['name']}";
                
                $htmlContent = "
                <div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);'>
                    <div style='text-align: center; margin-bottom: 30px; padding: 20px; background: linear-gradient(135deg, #dc2626, #ef4444); color: white; border-radius: 10px;'>
                        <h2>🚚 EasyShip - Bolla Amministrativa</h2>
                    </div>
                    
                    <h3>📦 Box {$boxNum} - {$shipment['name']}</h3>
                    <p><strong>Cliente:</strong> {$shipment['user_name']} ({$shipment['user_email']})</p>
                    
                    <h4>📋 Prodotti nel Box:</h4>
                    <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                        <thead>
                            <tr style='background: #f8f9fa;'>
                                <th style='border: 1px solid #ddd; padding: 10px; text-align: left;'>Prodotto</th>
                                <th style='border: 1px solid #ddd; padding: 10px; text-align: center;'>Quantità</th>
                                <th style='border: 1px solid #ddd; padding: 10px; text-align: center;'>Scadenza</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$prodottiLista}
                        </tbody>
                    </table>
                    
                    <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; text-align: center; color: #666; font-size: 12px;'>
                        Questo messaggio è stato generato automaticamente dal sistema EasyShip Admin di Margynomic.
                    </div>
                </div>";
                
                $result = inviaEmailSMTP(EASYSHIP_DEFAULT_EMAIL, $subject, $htmlContent);
                
                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Email inviata con successo']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Errore invio email']);
                }
                break;
                
            case 'getEasyShipStats':
                $db = getDbConnection();
                
                // Statistiche generali
                $stats = [
                    'total_shipments' => 0,
                    'active_users' => 0,
                    'completed_shipments' => 0,
                    'draft_shipments' => 0,
                    'total_boxes' => 0,
                    'total_items' => 0
                ];
                
                $stmt = $db->query("SELECT COUNT(*) FROM shipments");
                $stats['total_shipments'] = $stmt->fetchColumn();
                
                $stmt = $db->query("SELECT COUNT(DISTINCT user_id) FROM shipments");
                $stats['active_users'] = $stmt->fetchColumn();
                
                $stmt = $db->query("SELECT COUNT(*) FROM shipments WHERE status = 'Completed'");
$stats['completed_shipments'] = $stmt->fetchColumn();

$stmt = $db->query("SELECT COUNT(*) FROM shipments WHERE status = 'Draft'");
$stats['draft_shipments'] = $stmt->fetchColumn();
                
                $stmt = $db->query("SELECT COUNT(DISTINCT CONCAT(si.shipment_id, '-', si.box_no)) FROM shipment_items si JOIN shipments s ON si.shipment_id = s.id WHERE s.status = 'Completed'");
$stats['total_boxes'] = $stmt->fetchColumn();
                
                $stmt = $db->query("SELECT SUM(si.quantity) FROM shipment_items si JOIN shipments s ON si.shipment_id = s.id WHERE s.status = 'Completed'");
$stats['total_items'] = $stmt->fetchColumn() ?? 0;
                
                echo json_encode(['success' => true, 'stats' => $stats]);
                break;
                
            default:
                echo json_encode(['success' => false, 'error' => 'Azione non riconosciuta']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Errore server: ' . $e->getMessage()]);
    }
    
    exit;
}

// === PARTE HTML ===
echo getAdminHeader('EasyShip Admin');
echo getAdminNavigation('easyship');
?>

<div class="container">
    <h2>🚚 EasyShip - Pannello Amministrativo</h2>
    <p class="text-muted">Gestione centralizzata di tutte le spedizioni multi-box</p>
    
    <!-- Statistiche Dashboard -->
    <div class="stats-grid" id="easyship-stats">
        <div class="stat-card">
            <div class="stat-number" id="total-shipments">0</div>
            <div class="stat-label">Spedizioni Totali</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="active-users">0</div>
            <div class="stat-label">Utenti Attivi</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="completed-shipments">0</div>
            <div class="stat-label">Completate</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" id="total-boxes">0</div>
            <div class="stat-label">Box Totali</div>
        </div>
    </div>
    
    <!-- Filtri e Controlli -->
    <div class="admin-controls" style="margin: 30px 0; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <select id="filter-status" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="">Tutti gli stati</option>
                <option value="draft">Bozza</option>
                <option value="completed">Completata</option>
                <option value="cancelled">Annullata</option>
            </select>
            
            <select id="filter-user" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="">Tutti gli utenti</option>
            </select>
            
            <button onclick="loadShipments()" class="btn btn-primary">
                <i class="fas fa-sync"></i> Aggiorna Lista
            </button>
            
            <button onclick="exportShipments()" class="btn btn-secondary">
                <i class="fas fa-download"></i> Esporta CSV
            </button>
        </div>
    </div>
    
    <!-- Tabella Spedizioni -->
    <div class="table-responsive">
        <table class="table table-striped" id="shipments-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome Spedizione</th>
                    <th>Utente</th>
                    <th>Stato</th>
                    <th>Data</th>
                    <th>Box</th>
                    <th>Unità</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <!-- Righe dinamiche -->
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Dettagli Spedizione -->
<div id="shipment-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
    <div style="position: relative; margin: 2% auto; width: 90%; max-width: 1000px; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; overflow-y: auto;">
        <div style="padding: 20px; border-bottom: 1px solid #eee; background: #f8f9fa; border-radius: 8px 8px 0 0;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 id="modal-title">Dettagli Spedizione</h3>
                <button onclick="closeModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999;">×</button>
            </div>
        </div>
        <div id="modal-content" style="padding: 20px;">
            <!-- Contenuto dinamico -->
        </div>
    </div>
</div>

<!-- Toast Messaggi -->
<div id="toast" style="position: fixed; top: 20px; right: 20px; background: #28a745; color: white; padding: 12px 20px; border-radius: 4px; display: none; z-index: 1100;">
    <span id="toast-message"></span>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    loadStats();
    loadShipments();
    loadUsers();
});

function loadStats() {
    $.ajax({
        url: window.location.href,
        type: 'GET',
        data: { action: 'getEasyShipStats' },
        success: function(response) {
            if (response.success) {
                const stats = response.stats;
                $('#total-shipments').text(stats.total_shipments);
                $('#active-users').text(stats.active_users);
                $('#completed-shipments').text(stats.completed_shipments);
                $('#total-boxes').text(stats.total_boxes);
            }
        }
    });
}

function loadShipments() {
    const statusFilter = $('#filter-status').val();
    const userFilter = $('#filter-user').val();
    
    $.ajax({
        url: window.location.href,
        type: 'GET',
        data: { action: 'getAllShipments' },
        success: function(response) {
            if (response.success) {
                let html = '';
                response.data.forEach(shipment => {
                    // Applica filtri
                    if (statusFilter && shipment.status !== statusFilter) return;
                    if (userFilter && shipment.user_id != userFilter) return;
                    
                    const statusBadge = getStatusBadge(shipment.status);
                    
                    html += `
                        <tr>
                            <td>${shipment.id}</td>
                            <td>${shipment.name}</td>
                            <td>${shipment.user_name}<br><small>${shipment.user_email}</small></td>
                            <td>${statusBadge}</td>
                            <td>${shipment.created_at}</td>
                            <td>${shipment.total_boxes}</td>
                            <td>${shipment.total_items}</td>
                            <td>
                                <button onclick="viewShipment(${shipment.id})" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> Dettagli
                                </button>
                            </td>
                        </tr>
                    `;
                });
                
                $('#shipments-table tbody').html(html);
            }
        }
    });
}

function loadUsers() {
    // Carica utenti per filtro
    $.ajax({
        url: '../admin_utenti.php',
        type: 'GET', 
        data: { action: 'getUsers' },
        success: function(response) {
            if (response && response.length) {
                let html = '<option value="">Tutti gli utenti</option>';
                response.forEach(user => {
                    html += `<option value="${user.id}">${user.nome} (${user.email})</option>`;
                });
                $('#filter-user').html(html);
            }
        }
    });
}

function getStatusBadge(status) {
    const badges = {
        'draft': '<span style="background: #ffc107; color: #212529; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Bozza</span>',
        'completed': '<span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Completata</span>',
        'cancelled': '<span style="background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Annullata</span>'
    };
    return badges[status] || status;
}

function viewShipment(id) {
    $.ajax({
        url: window.location.href,
        type: 'GET',
        data: { action: 'getShipmentDetails', id: id },
        success: function(response) {
            if (response.success) {
                showShipmentModal(response.shipment, response.boxes);
            } else {
                showToast('Errore: ' + response.error, 'error');
            }
        }
    });
}

function showShipmentModal(shipment, boxes) {
    $('#modal-title').text(`${shipment.name} - ID: ${shipment.id}`);
    
    let content = `
        <div style="margin-bottom: 20px;">
            <h4>📋 Informazioni Generali</h4>
            <p><strong>Cliente:</strong> ${shipment.user_name} (${shipment.user_email})</p>
            <p><strong>Stato:</strong> ${getStatusBadge(shipment.status)}</p>
            <p><strong>Data Creazione:</strong> ${shipment.created_at}</p>
        </div>
        
        <h4>📦 Dettagli Box</h4>
    `;
    
    boxes.forEach(box => {
        content += `
            <div style="border: 1px solid #ddd; border-radius: 8px; margin: 15px 0; padding: 15px; background: #f9f9f9;">
                <div style="display: flex; justify-content: between; align-items: center; margin-bottom: 10px;">
                    <h5>Box ${box.numero}</h5>
                    <div style="margin-left: auto;">
                        ${shipment.status === 'completed' ? `
                            <button onclick="uploadBolla(${shipment.id}, ${box.numero})" class="btn btn-sm btn-secondary" style="margin-right: 5px;">
                                <i class="fas fa-upload"></i> Upload Bolla
                            </button>
                            <button onclick="sendEmailBolla(${shipment.id}, ${box.numero})" class="btn btn-sm btn-primary">
                                <i class="fas fa-envelope"></i> Invia Email
                            </button>
                        ` : ''}
                    </div>
                </div>
                
                ${box.peso ? `<p><strong>Dimensioni:</strong> ${box.peso}kg - ${box.larghezza}×${box.lunghezza}×${box.altezza}cm</p>` : ''}
                
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Prodotto</th>
                            <th>Quantità</th>
                            <th>Scadenza</th>
                        </tr>
                    </thead>
                    <tbody>
        `;
        
        box.prodotti.forEach(prod => {
            const scadenza = prod.scadenza ? new Date(prod.scadenza).toLocaleDateString('it-IT') : 'N/A';
            content += `
                <tr>
                    <td>${prod.nome}</td>
                    <td>${prod.quantita}</td>
                    <td>${scadenza}</td>
                </tr>
            `;
        });
        
        content += `
                    </tbody>
                </table>
            </div>
        `;
    });
    
    // Input file nascosto per upload
    content += `
        <input type="file" id="file-upload" accept=".pdf" style="display: none;" onchange="handleFileUpload()">
        <input type="hidden" id="upload-shipment-id">
        <input type="hidden" id="upload-box-num">
    `;
    
    $('#modal-content').html(content);
    $('#shipment-modal').show();
}

function closeModal() {
    $('#shipment-modal').hide();
}

function uploadBolla(shipmentId, boxNum) {
    $('#upload-shipment-id').val(shipmentId);
    $('#upload-box-num').val(boxNum);
    $('#file-upload').click();
}

function handleFileUpload() {
    const file = $('#file-upload')[0].files[0];
    const shipmentId = $('#upload-shipment-id').val();
    const boxNum = $('#upload-box-num').val();
    
    if (!file) return;
    
    if (file.type !== 'application/pdf') {
        showToast('Solo file PDF sono accettati', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'uploadBollaFile');
    formData.append('spedizione_id', shipmentId);
    formData.append('box_num', boxNum);
    formData.append('file', file);
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                showToast('File caricato con successo!', 'success');
            } else {
                showToast('Errore: ' + response.error, 'error');
            }
        }
    });
}

function sendEmailBolla(shipmentId, boxNum) {
    if (!confirm('Inviare email bolla per questo box?')) return;
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            action: 'sendEmailBolla',
            spedizione_id: shipmentId,
            box_num: boxNum
        },
        success: function(response) {
            if (response.success) {
                showToast('Email inviata con successo!', 'success');
            } else {
                showToast('Errore: ' + response.error, 'error');
            }
        }
    });
}

function exportShipments() {
    // Implementa export CSV
    window.location.href = window.location.href + '?action=exportCSV';
}

function showToast(message, type = 'success') {
    const toast = $('#toast');
    toast.removeClass('success error').addClass(type);
    
    if (type === 'error') {
        toast.css('background', '#dc3545');
    } else {
        toast.css('background', '#28a745');
    }
    
    $('#toast-message').text(message);
    toast.show();
    
    setTimeout(() => {
        toast.hide();
    }, 3000);
}

// Chiudi modal cliccando fuori
$(document).click(function(e) {
    if (e.target.id === 'shipment-modal') {
        closeModal();
    }
});
</script>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    text-align: center;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: bold;
    color: #007bff;
    margin-bottom: 5px;
}

.stat-label {
    color: #6c757d;
    font-size: 0.9rem;
}

.table-responsive {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}

.admin-controls {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin: 20px 0;
}

#toast.error {
    background: #dc3545 !important;
}

#toast.success {
    background: #28a745 !important;
}
</style>

<?php echo getAdminFooter(); ?>