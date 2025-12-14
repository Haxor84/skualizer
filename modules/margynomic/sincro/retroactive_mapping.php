<?php
/**
 * Sistema Mapping Retroattivo Completo
 * File: sincro/retroactive_mapping.php
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/mapping/MappingService.php';
require_once dirname(__DIR__, 2) . '/mapping/MappingRepository.php';
require_once dirname(__DIR__, 2) . '/mapping/config/mapping_config.php';
require_once 'ai_sku_processor.php';

class RetroactiveMapping {
    
    private $db;
    
    public function __construct() {
        $this->db = getDbConnection();
    }
    
    /**
     * Processa TUTTI gli SKU non mappati di un utente (settlement + inventory)
     */
    public function processAllUnmappedSkus($userId) {
        try {
            // 1. Auto-mapping enterprise su TUTTI i dati esistenti
            $pdo = getDbConnection();
            $config = getMappingConfig();
            $mappingRepo = new MappingRepository($pdo, $config);
            $mappingService = new MappingService($mappingRepo, $config);
            $mappingResult = $mappingService->executeFullMapping($userId);
            
            // 2. Conta SKU ancora non mappati
            $unmappedCount = $this->getUnmappedCount($userId);
            
            $result = [
                'success' => true,
                'user_id' => $userId,
                'auto_mapped' => $mappingResult['mapped_skus'] ?? 0,
                'remaining_unmapped' => $unmappedCount,
                'ai_processed' => 0,
                'ai_errors' => 0
            ];
            
            // 3. Se ci sono ancora SKU non mappati, usa AI
            if ($unmappedCount > 0) {
                $processor = new AiSkuProcessor();
                $aiResult = $processor->processUnmappedSkus($userId);
                
                if ($aiResult['success']) {
                    $result['ai_processed'] = $aiResult['processed'] ?? 0;
                    $result['ai_errors'] = $aiResult['errors'] ?? 0;
                    
                    // Aggiorna conteggio finale
                    $result['final_unmapped'] = $this->getUnmappedCount($userId);
                } else {
                    $result['ai_error'] = $aiResult['error'] ?? 'Errore AI sconosciuto';
                }
            } else {
                $result['final_unmapped'] = 0;
            }
            
            // 4. Statistiche finali
            $result['total_rows'] = $this->getTotalRows($userId);
            $result['mapped_rows'] = $this->getMappedRows($userId);
            $result['total_skus'] = $this->getTotalSkus($userId);
            $result['mapping_percentage'] = $result['total_rows'] > 0 ? 
                round(($result['mapped_rows'] / $result['total_rows']) * 100, 1) : 0;
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Conta SKU non mappati da settlement + inventory
     */
    private function getUnmappedCount($userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            $totalUnmapped = 0;
            
            // 1. SKU non mappati da settlement (se tabella esiste)
            $stmt = $this->db->query("SHOW TABLES LIKE '{$tableName}'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(DISTINCT sku) 
                    FROM `{$tableName}` 
                    WHERE product_id IS NULL 
                      AND sku IS NOT NULL 
                      AND sku != ''
                ");
                $stmt->execute();
                $totalUnmapped += $stmt->fetchColumn();
            }
            
            // 2. SKU non mappati da inventory
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT sku) 
                FROM inventory 
                WHERE user_id = ? 
                  AND product_id IS NULL 
                  AND sku IS NOT NULL 
                  AND sku != ''
            ");
            $stmt->execute([$userId]);
            $totalUnmapped += $stmt->fetchColumn();
            
            return $totalUnmapped;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Conta righe totali da settlement + inventory
     */
    private function getTotalRows($userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            $totalRows = 0;
            
            // 1. Righe da settlement (se tabella esiste)
            $stmt = $this->db->query("SHOW TABLES LIKE '{$tableName}'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$tableName}`");
                $stmt->execute();
                $totalRows += $stmt->fetchColumn();
            }
            
            // 2. Righe da inventory
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM inventory WHERE user_id = ?");
            $stmt->execute([$userId]);
            $totalRows += $stmt->fetchColumn();
            
            return $totalRows;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Conta righe mappate da settlement + inventory
     */
    private function getMappedRows($userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            $mappedRows = 0;
            
            // 1. Righe mappate da settlement (se tabella esiste)
            $stmt = $this->db->query("SHOW TABLES LIKE '{$tableName}'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) 
                    FROM `{$tableName}` 
                    WHERE product_id IS NOT NULL
                ");
                $stmt->execute();
                $mappedRows += $stmt->fetchColumn();
            }
            
            // 2. Righe mappate da inventory
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM inventory 
                WHERE user_id = ? AND product_id IS NOT NULL
            ");
            $stmt->execute([$userId]);
            $mappedRows += $stmt->fetchColumn();
            
            return $mappedRows;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Conta SKU unici totali da settlement + inventory (SAFE VERSION)
     */
    private function getTotalSkus($userId) {
        try {
            $tableName = "report_settlement_{$userId}";
            $allSkus = [];
            
            // 1. SKU da settlement (se tabella esiste)
            $stmt = $this->db->query("SHOW TABLES LIKE '{$tableName}'");
            if ($stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    SELECT DISTINCT sku 
                    FROM `{$tableName}` 
                    WHERE sku IS NOT NULL AND sku != ''
                ");
                $stmt->execute();
                $settlementSkus = array_column($stmt->fetchAll(), 'sku');
                $allSkus = array_merge($allSkus, $settlementSkus);
            }
            
            // 2. SKU da inventory
            $stmt = $this->db->prepare("
                SELECT DISTINCT sku 
                FROM inventory 
                WHERE user_id = ? AND sku IS NOT NULL AND sku != ''
            ");
            $stmt->execute([$userId]);
            $inventorySkus = array_column($stmt->fetchAll(), 'sku');
            $allSkus = array_merge($allSkus, $inventorySkus);
            
            // Rimuovi duplicati e conta
            return count(array_unique($allSkus));
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Processa tutti gli utenti del sistema
     */
    public function processAllUsers() {
        $users = $this->getAllUsers();
        $results = [];
        
        foreach ($users as $user) {
            $results[$user['id']] = $this->processAllUnmappedSkus($user['id']);
            $results[$user['id']]['user_name'] = $user['nome'];
        }
        
        return $results;
    }
    
    /**
     * Ottieni tutti gli utenti attivi
     */
    private function getAllUsers() {
        try {
            $stmt = $this->db->prepare("
                SELECT id, nome 
                FROM users 
                WHERE is_active = 1
            ");
            $stmt->execute();
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Verifica esistenza tabella
     */
    private function tableExists($tableName) {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE() AND table_name = ?
            ");
            $stmt->execute([$tableName]);
            return $stmt->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Ottieni statistiche rapide utente (settlement + inventory)
     */
    public function getUserStats($userId) {
        try {
            $stats = [
                'exists' => true,
                'total_rows' => $this->getTotalRows($userId),
                'mapped_rows' => $this->getMappedRows($userId),
                'total_skus' => $this->getTotalSkus($userId),
                'unmapped_skus' => $this->getUnmappedCount($userId)
            ];
            
            // Calcola SKU mappati
            $stats['mapped_skus'] = $stats['total_skus'] - $stats['unmapped_skus'];
            
            // Calcola percentuale mapping
            $stats['mapping_percentage'] = $stats['total_rows'] > 0 ? 
                round(($stats['mapped_rows'] / $stats['total_rows']) * 100, 1) : 0;
            
            return $stats;
            
        } catch (Exception $e) {
            return ['exists' => false, 'error' => $e->getMessage()];
        }
    }
}
?>