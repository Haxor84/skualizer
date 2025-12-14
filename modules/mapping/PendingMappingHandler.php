<?php
/**
 * Handler Dedicato per Gestione Mapping Pending
 * File: /modules/mapping/PendingMappingHandler.php
 * 
 * Gestisce logica di approvazione/rifiuto mapping fuzzy in modo isolato
 */

class PendingMappingHandler
{
    private PDO $db;
    private array $config;
    private MappingRepository $repository;

    public function __construct(PDO $db, array $config, MappingRepository $repository)
    {
        $this->db = $db;
        $this->config = $config;
        $this->repository = $repository;
    }

    /**
     * Ottiene tutti i mapping pending per un utente
     * @param int $userId
     * @return array
     */
    public function getPendingMappings(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    ms.id,
                    ms.sku,
                    ms.source_table,
                    ms.confidence_score,
                    ms.metadata,
                    ms.created_at,
                    p.nome as suggested_product_name,
                    JSON_EXTRACT(ms.metadata, '$.suggested_product_id') as suggested_product_id
                FROM mapping_states ms
                LEFT JOIN products p ON JSON_EXTRACT(ms.metadata, '$.suggested_product_id') = p.id
                WHERE ms.user_id = ? 
                  AND ms.product_id IS NULL 
                  AND ms.mapping_type = 'auto_fuzzy'
                ORDER BY ms.created_at DESC
            ");
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decodifica metadata per ogni risultato
            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return $results;

        } catch (PDOException $e) {
            $this->logError("Errore getPendingMappings: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Approva un mapping pending
     * @param int $mappingStateId
     * @param int $userId
     * @return array
     */
    public function approvePendingMapping(int $mappingStateId, int $userId): array
    {
        try {
            $this->db->beginTransaction();

            // Ottieni dati mapping pending
            $stmt = $this->db->prepare("
                SELECT sku, source_table, metadata 
                FROM mapping_states 
                WHERE id = ? AND user_id = ? AND product_id IS NULL AND mapping_type = 'auto_fuzzy'
            ");
            $stmt->execute([$mappingStateId, $userId]);
            $pendingMapping = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pendingMapping) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Mapping pending non trovato'];
            }

            $metadata = json_decode($pendingMapping['metadata'], true);
            $suggestedProductId = $metadata['suggested_product_id'] ?? null;

            if (!$suggestedProductId) {
                $this->db->rollBack();
                return ['success' => false, 'error' => 'Product ID suggerito non trovato'];
            }

            // 1. Aggiorna mapping_states → approva
            $stmt = $this->db->prepare("
                UPDATE mapping_states 
                SET product_id = ?, 
                    mapping_type = 'manual',
                    metadata = JSON_SET(metadata, '$.approved_at', NOW(), '$.approved_by', 'admin'),
                    updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$suggestedProductId, $mappingStateId, $userId]);

            // 2. Aggiorna tabella sorgente con product_id
            $this->updateSourceTable($pendingMapping['source_table'], $pendingMapping['sku'], $suggestedProductId, $userId);

            $this->db->commit();

            $this->logInfo("Mapping approvato: SKU {$pendingMapping['sku']} → Product {$suggestedProductId}");

            return [
                'success' => true,
                'sku' => $pendingMapping['sku'],
                'product_id' => $suggestedProductId,
                'source_table' => $pendingMapping['source_table']
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logError("Errore approvazione mapping: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rifiuta un mapping pending
     * @param int $mappingStateId
     * @param int $userId
     * @return array
     */
    public function rejectPendingMapping(int $mappingStateId, int $userId): array
    {
        try {
            // Ottieni dati mapping per log
            $stmt = $this->db->prepare("
                SELECT sku, source_table 
                FROM mapping_states 
                WHERE id = ? AND user_id = ? AND product_id IS NULL AND mapping_type = 'auto_fuzzy'
            ");
            $stmt->execute([$mappingStateId, $userId]);
            $pendingMapping = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pendingMapping) {
                return ['success' => false, 'error' => 'Mapping pending non trovato'];
            }

            // Aggiorna stato → rifiutato
            $stmt = $this->db->prepare("
                UPDATE mapping_states
                SET mapping_type = 'manual',
                    metadata = JSON_SET(metadata, '$.rejected_at', NOW(), '$.rejected_by', 'admin'),
                    updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$mappingStateId, $userId]);

            $this->logInfo("Mapping rifiutato: SKU {$pendingMapping['sku']}");

            return [
                'success' => true,
                'sku' => $pendingMapping['sku'],
                'source_table' => $pendingMapping['source_table']
            ];

        } catch (Exception $e) {
            $this->logError("Errore rifiuto mapping: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Approvazione/rifiuto multiplo
     * @param array $mappingIds
     * @param string $action 'approve' | 'reject'
     * @param int $userId
     * @return array
     */
    public function bulkProcessPendingMappings(array $mappingIds, string $action, int $userId): array
    {
        $results = [
            'success' => true,
            'processed' => 0,
            'approved' => 0,
            'rejected' => 0,
            'errors' => []
        ];

        foreach ($mappingIds as $mappingId) {
            $mappingId = (int)$mappingId;
            
            if ($action === 'approve') {
                $result = $this->approvePendingMapping($mappingId, $userId);
                if ($result['success']) {
                    $results['approved']++;
                } else {
                    $results['errors'][] = "ID {$mappingId}: " . $result['error'];
                }
            } elseif ($action === 'reject') {
                $result = $this->rejectPendingMapping($mappingId, $userId);
                if ($result['success']) {
                    $results['rejected']++;
                } else {
                    $results['errors'][] = "ID {$mappingId}: " . $result['error'];
                }
            }
            
            $results['processed']++;
        }

        if (!empty($results['errors'])) {
            $results['success'] = false;
        }

        return $results;
    }

    /**
     * Statistiche pending mappings per dashboard
     * @param int $userId
     * @return array
     */
    public function getPendingStatistics(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_pending,
                    AVG(confidence_score) as avg_confidence,
                    MIN(created_at) as oldest_pending,
                    COUNT(CASE WHEN confidence_score >= 0.90 THEN 1 END) as high_confidence,
                    COUNT(CASE WHEN confidence_score BETWEEN 0.75 AND 0.89 THEN 1 END) as medium_confidence,
                    COUNT(CASE WHEN confidence_score < 0.75 THEN 1 END) as low_confidence
                FROM mapping_states 
                WHERE user_id = ? 
                  AND product_id IS NULL 
                  AND mapping_type = 'auto_fuzzy'
            ");
            $stmt->execute([$userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            return [
                'total_pending' => (int)$stats['total_pending'],
                'avg_confidence' => round((float)$stats['avg_confidence'], 2),
                'oldest_pending' => $stats['oldest_pending'],
                'high_confidence' => (int)$stats['high_confidence'],
                'medium_confidence' => (int)$stats['medium_confidence'],
                'low_confidence' => (int)$stats['low_confidence']
            ];

        } catch (PDOException $e) {
            $this->logError("Errore getPendingStatistics: " . $e->getMessage());
            return [
                'total_pending' => 0,
                'avg_confidence' => 0,
                'oldest_pending' => null,
                'high_confidence' => 0,
                'medium_confidence' => 0,
                'low_confidence' => 0
            ];
        }
    }

    /**
     * Aggiorna la tabella sorgente con il product_id approvato
     * @param string $sourceTable
     * @param string $sku
     * @param int $productId
     * @param int $userId
     */
    private function updateSourceTable(string $sourceTable, string $sku, int $productId, int $userId): void
    {
        switch ($sourceTable) {
            case 'inventory':
                $stmt = $this->db->prepare("UPDATE inventory SET product_id = ? WHERE sku = ? AND user_id = ?");
                $stmt->execute([$productId, $sku, $userId]);
                break;

            case 'inventory_fbm':
                $stmt = $this->db->prepare("UPDATE inventory_fbm SET product_id = ? WHERE seller_sku = ? AND user_id = ?");
                $stmt->execute([$productId, $sku, $userId]);
                break;

            case 'settlement':
                $settlementTable = "report_settlement_{$userId}";
                $stmt = $this->db->prepare("UPDATE `{$settlementTable}` SET product_id = ? WHERE sku = ?");
                $stmt->execute([$productId, $sku]);
                break;
        }
    }

    /**
     * Log info
     */
    private function logInfo(string $message): void
    {
        CentralLogger::log('mapping', 'INFO', $message);
    }

    /**
     * Log errori
     */
    private function logError(string $message): void
    {
        CentralLogger::log('mapping', 'ERROR', $message);
    }
}
?>