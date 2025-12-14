<?php
/**
 * AI Excel Editor - Main UI
 * Path: modules/margynomic/admin/creaexcel/ai/views/creator.php
 * 
 * Excel Editor con AI Assistant:
 * - Upload Excel template
 * - Mostra tabella prodotti
 * - Edit righe esistenti
 * - Add nuove righe
 * - AI suggestions per ogni campo
 */

session_start();

require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../admin_helpers.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: /modules/margynomic/admin/admin_login.php');
    exit;
}

// Admin può selezionare per quale user sta lavorando
$selectedUserId = (int)($_GET['user_id'] ?? $_SESSION['selected_user_id'] ?? $_SESSION['admin_id']);
$_SESSION['selected_user_id'] = $selectedUserId; // Persisti la selezione (INT)

// Carica lista utenti per il selettore
$pdo = getDbConnection();
$stmt = $pdo->query("SELECT id, nome, email FROM users WHERE is_active = 1 ORDER BY nome ASC");
$availableUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Carica categorie Amazon per l'utente selezionato
$stmt = $pdo->prepare("
    SELECT id, category_name, category_slug
    FROM amazon_categories
    WHERE user_id = ?
    ORDER BY category_name ASC
");
$stmt->execute([$selectedUserId]);
$userCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

$userId = $_SESSION['admin_id'];
$userEmail = $_SESSION['admin_email'] ?? 'Admin';

// Include admin navigation
echo getAdminHeader('AI Excel Editor');
echo getAdminNavigation('excel_creator');
?>

<!-- Excel Editor Custom CSS -->
<link rel="stylesheet" href="../assets/ai_creator.css?v=<?= time() ?>">
<link rel="stylesheet" href="../assets/ai_chat_modern.css?v=<?= time() ?>">

<style>
    /* Override admin styles for Excel Editor */
    body {
        margin: 0;
        padding: 0;
        background: #f5f5f5;
    }
    .ai-creator-container {
        margin-top: 0;
        padding-top: 20px;
    }
</style>

    <div class="ai-creator-container">

        <!-- Sub-Header (sotto menu admin) -->
        <header class="ai-header" style="margin-top: 0;">
            <div class="header-left">
                <h1>🤖 AI Excel Editor</h1>
                <span class="header-subtitle">Modifica listing Amazon con AI Assistant</span>
            </div>
            <div class="header-right">
                <select id="userSelector" class="user-selector" onchange="window.location.href='?user_id=' + this.value">
                    <?php foreach ($availableUsers as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $selectedUserId == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?> (ID: <?= $user['id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <a href="/modules/margynomic/admin/creaexcel/ai_costs_dashboard.php?user_id=<?= $selectedUserId ?>" class="btn-secondary" style="text-decoration: none;">
                    💰 Dashboard Costi AI
                </a>
                <button id="btnUploadExcel" class="btn-primary">📤 Carica Nuovo Excel</button>
            </div>
        </header>

        <!-- Main Content -->
        <div id="mainContent" class="main-content">

            <!-- Welcome Screen (mostrato se nessun file caricato) -->
            <div id="welcomeScreen" class="welcome-screen">
                
                <!-- Selettore Cartella Cliente -->
                <div class="welcome-section folder-selector">
                    <div class="section-header">
                        <h2>📁 Seleziona Cliente/Progetto</h2>
                        <p>Organizza i file per cliente o progetto</p>
                    </div>
                    
                    <div class="folder-controls">
                        <select id="folderSelect" class="folder-dropdown">
                            <option value="">🏠 Root (tutti i file)</option>
                        </select>
                        <button id="btnNewFolder" class="btn-secondary">
                            ➕ Nuova Cartella
                        </button>
                    </div>
                </div>
                
                <!-- Progetti Recenti -->
                <div class="welcome-section">
                    <div class="section-header">
                        <h2>📂 File Recenti</h2>
                        <p id="folderSubtitle">Tutti i file</p>
                    </div>
                    
                    <div id="recentFilesContainer" class="recent-files-container">
                        <div class="loading-spinner">🔄 Caricamento file recenti...</div>
                    </div>
                </div>

                <!-- Features List -->
                <div class="welcome-features">
                    <h3>✨ Funzionalità</h3>
                    <ul>
                        <li>✅ Modifica prodotti esistenti</li>
                        <li>✅ Aggiungi nuovi prodotti con EAN automatico</li>
                        <li>✅ AI suggestions per ogni campo</li>
                        <li>✅ Validazione real-time Amazon policies</li>
                        <li>✅ Download file aggiornato</li>
                    </ul>
                </div>
            </div>

            <!-- Products Table (mostrato dopo upload) -->
            <div id="productsTable" class="products-table-container" style="display: none;">

                <div class="table-toolbar">
                    <div class="toolbar-left">
                        <h2 id="fileName">File: -</h2>
                        <span id="rowsCount" class="badge">0 prodotti</span>
                        <span style="color: #6b7280; font-size: 13px; margin-left: 15px;">ℹ️ Le modifiche sono salvate
                            automaticamente nel file</span>
                    </div>
                    <div class="toolbar-right">
                        <button id="btnAddRow" class="btn-primary">➕ Aggiungi Prodotto</button>
                        <button id="btnGenerateVariants" class="btn-success" style="display: none;" title="Genera contenuti per varianti selezionate">
                            🔄 Genera Varianti
                        </button>
                        <button id="btnSyncPrices" class="btn-warning" title="Sincronizza prezzi da database">
                            🔄 Sync Prezzi DB
                        </button>
                        <button id="btnSyncEan" class="btn-warning" title="Sincronizza codici EAN da Excel a database">
                            🏷️ Sync Codice EAN
                        </button>
                        <button id="btnValidateAll" class="btn-secondary">✅ Valida Tutti</button>
                        <button id="btnSaveExcel" class="btn-success">💾 Scarica Excel Aggiornato</button>
                    </div>
                </div>

                <div class="table-scroll">
                    <table id="tableProducts" class="products-table">
                        <thead>
                            <tr>
                                <th width="50">#</th>
                                <th width="300">Title</th>
                                <th width="150">SKU</th>
                                <th width="120">EAN</th>
                                <th width="120">ASIN</th>
                                <th width="100">Price</th>
                                <th width="80">Status</th>
                                <th width="120">Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- Populated by JavaScript -->
                        </tbody>
                    </table>
                </div>

            </div>

            <!-- Row Editor (slide-in panel) -->
            <div id="rowEditor" class="row-editor" style="display: none;">
                <div class="editor-header">
                    <h2 id="editorTitle">Modifica Prodotto - Riga #4</h2>
                    <button id="btnCloseEditor" class="btn-icon">×</button>
                </div>

                <div class="editor-content">

                    <div id="dynamicFieldsContainer">
                        <!-- Fields will be generated here by JavaScript -->
                    </div>

                </div>

                <div class="editor-footer">
                    <button id="btnSaveRow" class="btn-success">💾 Salva Riga</button>
                    <button id="btnDuplicateRow" class="btn-info">📋 Duplica Riga</button>
                    <button id="btnDeleteRow" class="btn-danger">🗑️ Elimina Riga</button>
                    <button id="btnCancelEdit" class="btn-secondary">❌ Annulla</button>
                </div>
            </div>

        </div>

    </div>

    <!-- Modal: Upload Excel -->
    <div id="modalUploadExcel" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Carica File Excel</h3>
                <button class="modal-close">×</button>
            </div>
            <div class="modal-body">
                <input type="file" id="fileExcelInput" accept=".xlsx,.xlsm" style="display: none;">
                <div class="upload-area" id="uploadArea">
                    <div class="upload-icon">📤</div>
                    <p>Trascina qui il file Excel oppure clicca per selezionare</p>
                    <p class="upload-hint">Formato: .xlsx o .xlsm (max 50MB)</p>
                </div>

                <div class="form-group">
                    <label for="excelCategoria">Categoria Amazon</label>
                    <div style="display: flex; gap: 10px;">
                        <select id="excelCategoria" class="form-input" style="flex: 1;">
                            <option value="">✔ Seleziona categoria</option>
                            <?php foreach ($userCategories as $cat): ?>
                                <option value="<?= htmlspecialchars($cat['category_slug']) ?>">
                                    <?= htmlspecialchars($cat['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="btnManageCategories" class="btn-secondary" style="flex-shrink: 0;">
                            ⚙️ Gestisci
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="btnUploadConfirm" class="btn-primary">Carica</button>
                <button class="btn-secondary modal-close">Annulla</button>
            </div>
        </div>
    </div>

    <!-- Modal Nuova Cartella -->
    <div id="modalNewFolder" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📁 Nuova Cartella Cliente</h2>
                <button class="modal-close">✖</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="folderName">Nome Cliente/Progetto</label>
                    <input type="text" id="folderName" class="form-input" placeholder="es: Cliente_Rossi" maxlength="50">
                    <p class="upload-hint">Solo lettere, numeri, trattini e underscore</p>
                </div>
            </div>
            <div class="modal-footer">
                <button id="btnCreateFolder" class="btn-primary">Crea Cartella</button>
                <button class="btn-secondary modal-close">Annulla</button>
            </div>
        </div>
    </div>

    <!-- Modal Gestione Categorie -->
    <div id="modalManageCategories" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2>⚙️ Gestione Categorie Amazon</h2>
                <button class="modal-close">✖</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="newCategoryName">Aggiungi Nuova Categoria</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="text" id="newCategoryName" class="form-input" placeholder="es: Pet Supplies" style="flex: 1;">
                        <button id="btnAddCategory" class="btn-primary" style="flex-shrink: 0;">➕ Aggiungi</button>
                    </div>
                </div>
                
                <hr style="margin: 20px 0;">
                
                <div class="form-group">
                    <label>Categorie Esistenti</label>
                    <div id="categoriesList" class="categories-list">
                        <!-- Populated by JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-secondary modal-close">Chiudi</button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"></div>

    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay" style="display: none;">
        <div class="spinner"></div>
        <p id="loadingText">Caricamento...</p>
    </div>

    <!-- Config -->
    <script>
        window.AI_CONFIG = {
            userId: <?= $userId ?>,
            userEmail: '<?= htmlspecialchars($userEmail, ENT_QUOTES) ?>',
            apiUrl: '/modules/margynomic/admin/creaexcel/ai/api/ai_api.php',
            currentFile: null,
            currentFilepath: null,
            currentRowNumber: null,
            rows: [],
            headers: {}
        };
    </script>
    <script src="../assets/ai_creator.js?v=<?= time() ?>"></script>
    <script src="../assets/ai_chat_logic.js?v=<?= time() ?>"></script>
    <script src="../assets/ai_conversational.js?v=<?= time() ?>"></script>
    <script src="../assets/variant_adapter.js?v=<?= time() ?>"></script>

    <!-- AI Chat Assistant Modal -->
    <div id="aiChatModal" class="ai-chat-modal">
        <div class="ai-chat-container">
            <!-- Header -->
            <div class="ai-chat-header">
                <div class="ai-chat-title">
                    <span class="ai-icon">🤖</span>
                    <span>AI Content Assistant</span>
                    <span class="ai-product-context" id="aiProductInfo"></span>
                </div>
                <button onclick="closeAiChat()" class="ai-chat-close">✕</button>
            </div>
            
            <!-- Messages Area -->
            <div class="ai-chat-messages" id="aiChatMessages">
                <!-- Messages will be dynamically added here -->
            </div>
        </div>
    </div>

</body>

</html>