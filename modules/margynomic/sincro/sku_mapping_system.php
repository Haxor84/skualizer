<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__, 2) . '/mapping/MappingRepository.php';
require_once dirname(__DIR__, 2) . '/mapping/MappingService.php';

class SkuMappingSystem {

    private $mappingService;
    private $mappingRepository;

    public function __construct() {
        $pdo = getDbConnection();
        $config = getMappingConfig(); // Assicurati che questa funzione sia disponibile globalmente o includi mapping_config.php
        $this->mappingRepository = new MappingRepository($pdo, $config);
        $this->mappingService = new MappingService($this->mappingRepository, $config);
    }


    /**
     * Processa SKU non mappati (METODO PRINCIPALE per batch_processor)
     * Ora delega al nuovo sistema di mapping.
     */
    public function processUnmappedSkus($userId) {
        // Rimosso log verbose - MappingService logga già consolidato

        try {
            // Il nuovo MappingService gestisce la logica di mapping, inclusi i controlli sulle tabelle.
            $result = $this->mappingService->executeFullMapping($userId);

            if ($result['success']) {
                // Log già gestito da MappingService con CentralLogger
                return [
                    'success' => true,
                    'mapped_count' => $result['mapped_skus'],
                    'unmapped_before' => null, // Non più calcolato qui direttamente
                    'unmapped_after' => null,  // Non più calcolato qui direttamente
                    'strategies' => $result['strategies'] ?? []
                ];
            } else {
                CentralLogger::log('mapping', 'ERROR', 
                    sprintf('SKU mapping error for user %d: %s', $userId, $result['error']));
                return $result;
            }

        } catch (Exception $e) {
            CentralLogger::log('mapping', 'ERROR', 
                sprintf('SKU mapping exception for user %d: %s', $userId, $e->getMessage()));
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * DEPRECATED: Questa funzione non è più utilizzata direttamente.
     * La logica di auto-mapping è ora gestita da MappingService.
     */
    public function performAutoMapping($userId, $sourceTable = null) {
        CentralLogger::log('mapping', 'WARNING', 'performAutoMapping deprecata - usare processUnmappedSkus');
        return ['success' => false, 'error' => 'Funzione deprecata. Usare processUnmappedSkus.'];
    }

    /**
     * DEPRECATED: Questa funzione non è più utilizzata direttamente.
     * La logica di sincronizzazione è ora gestita da MappingService o dovrebbe essere migrata.
     */
    public function syncBidirectional($userId) {
        CentralLogger::log('mapping', 'WARNING', 'syncBidirectional deprecata - non utilizzare');
        return ['success' => false, 'error' => 'Funzione deprecata.'];
    }

    /**
     * DEPRECATED: Questa funzione non è più utilizzata direttamente.
     * La logica di conteggio è ora gestita da MappingService o dovrebbe essere migrata.
     */
    private function countUnmappedSkus($userId) {
        CentralLogger::log('mapping', 'WARNING', 'countUnmappedSkus deprecata - non utilizzare');
        return 0;
    }

    /**
     * DEPRECATED: Questa funzione non è più utilizzata direttamente.
     * La logica di dettaglio è ora gestita da MappingService o dovrebbe essere migrata.
     */
    public function getUnmappedSkuDetails($userId) {
        CentralLogger::log('mapping', 'WARNING', 'getUnmappedSkuDetails deprecata - non utilizzare');
        return [];
    }

    /**
     * Helper per verificare l'esistenza di una tabella.
     * Mantenuto per compatibilità, ma la logica dovrebbe essere nel Repository.
     */
    private function tableExists($tableName) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            CentralLogger::log('mapping', 'ERROR', sprintf('tableExists error: %s', $e->getMessage()));
            return false;
        }
    }

    /**
     * Helper per verificare l'esistenza di una colonna in una tabella.
     * Mantenuto per compatibilità, ma la logica dovrebbe essere nel Repository.
     */
    private function columnExists($tableName, $columnName) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE ?");
            $stmt->execute([$columnName]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            CentralLogger::log('mapping', 'ERROR', sprintf('columnExists error: %s', $e->getMessage()));
            return false;
        }
    }
}

// Se il file viene incluso e non è già stato caricato il mapping_config, caricalo.
// Questo è necessario perché SkuMappingSystem ora dipende da getMappingConfig.
if (!function_exists('getMappingConfig')) {
    require_once dirname(__DIR__, 2) . '/mapping/config/mapping_config.php';
}

// Se necessario, istanzia e usa SkuMappingSystem
// $skuMapper = new SkuMappingSystem();
// $skuMapper->processUnmappedSkus($userId);

?>