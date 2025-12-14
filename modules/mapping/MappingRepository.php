<?php
/**
 * Enterprise Mapping System - Data Access Layer (Repository)
 * File: /modules/mapping/MappingRepository.php
 *
 * Questo file centralizza tutte le interazioni con il database per il sistema di mapping.
 * Incapsula la logica SQL e fornisce un'interfaccia pulita per la logica di business.
 */

require_once dirname(__DIR__) . '/margynomic/config/config.php';

class MappingRepository
{
    private PDO $db;
    private array $config;

    public function __construct(PDO $db, array $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Salva o aggiorna lo stato di mapping di un SKU.
     * @param int $userId
     * @param string $sku
     * @param string $sourceTable
     * @param array $mappingData
     * @return array
     */
    public function saveMappingState(int $userId, string $sku, string $sourceTable, array $mappingData): array
    {
        $needTransaction = !$this->db->inTransaction();
        try {
            if ($needTransaction) {
                $this->db->beginTransaction();
            }

            // Prepara dati
            $productId = $mappingData['product_id'] ?? null;
            $mappingType = $mappingData['mapping_type'] ?? 'auto_exact';
            $confidenceScore = $mappingData['confidence_score'] ?? 1.00;
            $metadata = json_encode($mappingData['metadata'] ?? []);
            $isLocked = ($confidenceScore >= $this->config['auto_lock_threshold']) ? 1 : 0;

            // Verifica se esiste già uno stato per questo SKU e source_table
            $stmt = $this->db->prepare("
                SELECT id FROM mapping_states
                WHERE user_id = ? AND sku = ? AND source_table = ?
            ");
            $stmt->execute([$userId, $sku, $sourceTable]);
            $existingStateId = $stmt->fetchColumn();

            if ($existingStateId) {
                // Aggiorna stato esistente
                $stmt = $this->db->prepare("
                    UPDATE mapping_states
                    SET product_id = ?, mapping_type = ?, confidence_score = ?,
                        is_locked = ?, metadata = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $productId, $mappingType, $confidenceScore,
                    $isLocked, $metadata, $existingStateId
                ]);
            } else {
                // Inserisci nuovo stato con ON DUPLICATE KEY UPDATE
                $stmt = $this->db->prepare("
                    INSERT INTO mapping_states
                    (user_id, sku, product_id, source_table, mapping_type, confidence_score, is_locked, metadata)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        product_id = VALUES(product_id),
                        mapping_type = VALUES(mapping_type),
                        confidence_score = VALUES(confidence_score),
                        is_locked = VALUES(is_locked),
                        metadata = VALUES(metadata),
                        updated_at = CURRENT_TIMESTAMP
                ");
                $stmt->execute([
                    $userId, $sku, $productId, $sourceTable, $mappingType, $confidenceScore, $isLocked, $metadata
                ]);
                $existingStateId = $this->db->lastInsertId();
                if (!$existingStateId) {
                    $stmt2 = $this->db->prepare("SELECT id FROM mapping_states WHERE user_id = ? AND sku = ? AND source_table = ?");
                    $stmt2->execute([$userId, $sku, $sourceTable]);
                    $existingStateId = $stmt2->fetchColumn();
                }
            }

            if ($needTransaction) {
                $this->db->commit();
            }

            return [
                'success' => true,
                'state_id' => $existingStateId,
                'is_locked' => $isLocked,
                'confidence' => $confidenceScore
            ];

        } catch (PDOException $e) {
            if ($needTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->logError("Errore saveMappingState: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ottiene lo stato di mapping per un SKU specifico da una sorgente.
     * @param int $userId
     * @param string $sku
     * @param string $sourceTable
     * @return array|null
     */
    public function getMappingState(int $userId, string $sku, string $sourceTable): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM mapping_states
                WHERE user_id = ? AND sku = ? AND source_table = ?
            ");
            $stmt->execute([$userId, $sku, $sourceTable]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['metadata']) {
                $result['metadata'] = json_decode($result['metadata'], true);
            }
            return $result ?: null;

        } catch (PDOException $e) {
            $this->logError("Errore getMappingState: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Ripristina tutti i mapping esistenti per un utente nelle tabelle sorgente.
     * @param int $userId
     * @return array
     */
    public function restoreAllMappings(int $userId): array
    {
        try {
            $restored = ['inventory' => 0, 'inventory_fbm' => 0, 'settlement' => 0];

            // Ripristina mapping inventory
            $stmt = $this->db->prepare("
                UPDATE inventory i
                INNER JOIN mapping_states ms ON i.sku = ms.sku AND i.user_id = ms.user_id
                SET i.product_id = ms.product_id
                WHERE ms.user_id = ? AND ms.source_table = 'inventory'
                AND ms.product_id IS NOT NULL AND (i.product_id IS NULL OR i.product_id != ms.product_id)
            ");
            $stmt->execute([$userId]);
            $restored['inventory'] = $stmt->rowCount();

            // Ripristina mapping inventory_fbm
            if ($this->tableExists('inventory_fbm')) {
                $stmt = $this->db->prepare("
                    UPDATE inventory_fbm f
                    INNER JOIN mapping_states ms ON f.seller_sku = ms.sku AND f.user_id = ms.user_id
                    SET f.product_id = ms.product_id
                    WHERE ms.user_id = ? AND ms.source_table = 'inventory_fbm'
                    AND ms.product_id IS NOT NULL AND (f.product_id IS NULL OR f.product_id != ms.product_id)
                ");
                $stmt->execute([$userId]);
                $restored['inventory_fbm'] = $stmt->rowCount();
            }

            // Ripristina mapping settlement (tabella dinamica)
            $settlementTable = "report_settlement_{$userId}";
            if ($this->tableExists($settlementTable)) {
                $stmt = $this->db->prepare("
                    UPDATE `{$settlementTable}` s
                    INNER JOIN mapping_states ms ON s.sku = ms.sku AND ms.user_id = ?
                    SET s.product_id = ms.product_id
                    WHERE ms.user_id = ? AND ms.source_table = 'settlement'
                    AND ms.product_id IS NOT NULL AND (s.product_id IS NULL OR s.product_id != ms.product_id)
                ");
                $stmt->execute([$userId, $userId]); // Passa userId due volte per i due placeholder
                $restored['settlement'] = $stmt->rowCount();
            }

            $totalRestored = array_sum($restored);
            $this->logInfo("Ripristinati {$totalRestored} mapping per user {$userId}: " . json_encode($restored));

            return ['success' => true, 'restored' => $restored, 'total' => $totalRestored];

        } catch (PDOException $e) {
            $this->logError("Errore restoreAllMappings per user {$userId}: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Ottiene statistiche sui mapping per un utente.
     * @param int $userId
     * @return array
     */
    public function getMappingStatistics(int $userId): array
    {
        try {
            $stats = [
                'total_states' => 0,
                'locked_states' => 0,
                'by_source' => [],
                'by_type' => [],
                'confidence_distribution' => [],
                'last_updated' => null
            ];

            // Statistiche generali
            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_locked = 1 THEN 1 ELSE 0 END) as locked,
                    source_table,
                    mapping_type,
                    AVG(confidence_score) as avg_confidence,
                    MAX(updated_at) as last_update
                FROM mapping_states
                WHERE user_id = ?
                GROUP BY source_table, mapping_type
            ");
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as $row) {
                $stats['total_states'] += $row['total'];
                $stats['locked_states'] += $row['locked'];

                $stats['by_source'][$row['source_table']] = ($stats['by_source'][$row['source_table']] ?? 0) + $row['total'];
                $stats['by_type'][$row['mapping_type']] = ($stats['by_type'][$row['mapping_type']] ?? 0) + $row['total'];

                if (!$stats['last_updated'] || $row['last_update'] > $stats['last_updated']) {
                    $stats['last_updated'] = $row['last_update'];
                }
            }

            // Distribuzione confidence
            $stmt = $this->db->prepare("
                SELECT
                    CASE
                        WHEN confidence_score >= 0.95 THEN 'very_high'
                        WHEN confidence_score >= 0.85 THEN 'high'
                        WHEN confidence_score >= 0.70 THEN 'medium'
                        ELSE 'low'
                    END as confidence_range,
                    COUNT(*) as count
                FROM mapping_states
                WHERE user_id = ?
                GROUP BY confidence_range
            ");
            $stmt->execute([$userId]);
            $stats['confidence_distribution'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            return ['success' => true, 'stats' => $stats];

        } catch (PDOException $e) {
            $this->logError("Errore getMappingStatistics: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Blocca/sblocca un mapping specifico.
     * @param int $userId
     * @param string $sku
     * @param string $sourceTable
     * @param bool $locked
     * @return array
     */
    public function toggleMappingLock(int $userId, string $sku, string $sourceTable, bool $locked): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mapping_states
                SET is_locked = ?, updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ? AND sku = ? AND source_table = ?
            ");
            $stmt->execute([$locked ? 1 : 0, $userId, $sku, $sourceTable]);

            if ($stmt->rowCount() > 0) {
                return ['success' => true, 'locked' => $locked];
            }

            return ['success' => false, 'error' => 'Mapping state not found'];

        } catch (PDOException $e) {
            $this->logError("Errore toggleMappingLock: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Elimina stati mapping obsoleti.
     * @param int $daysOld
     * @return int
     */
    public function cleanupObsoleteStates(int $daysOld = 90): int
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM mapping_states
                WHERE updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                AND is_locked = 0
            ");
            $stmt->execute([$daysOld]);

            return $stmt->rowCount();

        } catch (PDOException $e) {
            $this->logError("Errore cleanupObsoleteStates: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Registra un'azione di mapping nell'audit log.
     * @param array $logEntry
     * @return bool
     */
    public function logMappingAction(array $logEntry): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mapping_audit_log
                (user_id, sku, old_product_id, new_product_id, action_type, trigger_source,
                 confidence_before, confidence_after, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            return $stmt->execute([
                $logEntry['user_id'],
                $logEntry['sku'],
                $logEntry['old_product_id'],
                $logEntry['new_product_id'],
                $logEntry['action_type'],
                $logEntry['trigger_source'],
                $logEntry['confidence_before'] ?? null,
                $logEntry['confidence_after'] ?? null,
                json_encode($logEntry['metadata'] ?? []),
                $logEntry['created_at'] ?? date('Y-m-d H:i:s')
            ]);

        } catch (PDOException $e) {
            $this->logError("Errore logMappingAction: " . $e->getMessage());
            return false;
        }
    }

    // === METODI PER PENDING MAPPINGS ===

    /**
     * Ottiene mapping pending per approvazione
     * @param int $userId
     * @return array
     */
    public function getPendingMappingsForApproval(int $userId): array
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

            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }

            return $results;

        } catch (PDOException $e) {
            $this->logError("Errore getPendingMappingsForApproval: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Salva mapping come pending (per FuzzyStrategy)
     * @param int $userId
     * @param string $sku
     * @param string $sourceTable
     * @param int $suggestedProductId
     * @param float $confidence
     * @param array $metadata
     * @return array
     */
    public function savePendingMapping(int $userId, string $sku, string $sourceTable, int $suggestedProductId, float $confidence, array $metadata = []): array
    {
        try {
            // Aggiungi suggested_product_id ai metadata
            $metadata['suggested_product_id'] = $suggestedProductId;
            $metadata['pending_since'] = date('Y-m-d H:i:s');

            return $this->saveMappingState($userId, $sku, $sourceTable, [
                'product_id' => null, // NULL = pending
                'mapping_type' => 'auto_fuzzy',
                'confidence_score' => $confidence,
                'metadata' => $metadata
            ]);

        } catch (Exception $e) {
            $this->logError("Errore savePendingMapping: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Conta mapping pending per utente
     * @param int $userId
     * @return int
     */
    public function countPendingMappings(int $userId): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) 
                FROM mapping_states 
                WHERE user_id = ? 
                  AND product_id IS NULL 
                  AND mapping_type = 'auto_fuzzy'
            ");
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();

        } catch (PDOException $e) {
            $this->logError("Errore countPendingMappings: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Approva mapping pending (wrapper per PendingMappingHandler)
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

            // Aggiorna mapping_states → approva
            $stmt = $this->db->prepare("
                UPDATE mapping_states 
                SET product_id = ?, 
                    mapping_type = 'manual',
                    metadata = JSON_SET(metadata, '$.approved_at', NOW(), '$.approved_by', 'admin'),
                    updated_at = NOW()
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$suggestedProductId, $mappingStateId, $userId]);

            $this->db->commit();

            return [
                'success' => true,
                'sku' => $pendingMapping['sku'],
                'product_id' => $suggestedProductId,
                'source_table' => $pendingMapping['source_table']
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logError("Errore approvePendingMapping: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rifiuta mapping pending
     * @param int $mappingStateId
     * @param int $userId
     * @return array
     */
    public function rejectPendingMapping(int $mappingStateId, int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mapping_states
                SET mapping_type = 'manual',
                    metadata = JSON_SET(metadata, '$.rejected_at', NOW(), '$.rejected_by', 'admin'),
                    updated_at = NOW()
                WHERE id = ? AND user_id = ? AND product_id IS NULL AND mapping_type = 'auto_fuzzy'
            ");
            $stmt->execute([$mappingStateId, $userId]);

            if ($stmt->rowCount() > 0) {
                return ['success' => true];
            }

            return ['success' => false, 'error' => 'Mapping pending non trovato'];

        } catch (Exception $e) {
            $this->logError("Errore rejectPendingMapping: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

/**
     * Ottiene statistiche pending mappings
     * @param int $userId
     * @return array
     */
    public function getPendingMappingStatistics(int $userId): array
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

        } catch (Exception $e) {
            $this->logError("Errore getPendingMappingStatistics: " . $e->getMessage());
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
     * Ottiene la cronologia di audit per un SKU.
     * @param int $userId
     * @param string $sku
     * @param int $limit
     * @return array
     */
    public function getSkuAuditHistory(int $userId, string $sku, int $limit = 20): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    mal.*,
                    p_old.nome as old_product_name,
                    p_new.nome as new_product_name
                FROM mapping_audit_log mal
                LEFT JOIN products p_old ON mal.old_product_id = p_old.id
                LEFT JOIN products p_new ON mal.new_product_id = p_new.id
                WHERE mal.user_id = ? AND mal.sku = ?
                ORDER BY mal.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $sku, $limit]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as &$result) {
                if ($result['metadata']) {
                    $result['metadata'] = json_decode($result['metadata'], true);
                }
            }
            return $results;

        } catch (PDOException $e) {
            $this->logError("Errore getSkuAuditHistory: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ottiene statistiche di audit per un utente.
     * @param int $userId
     * @param int $days
     * @return array
     */
    public function getUserAuditStats(int $userId, int $days = 30): array
    {
        try {
            $stats = [];

            $stmt = $this->db->prepare("
                SELECT
                    COUNT(*) as total_actions,
                    COUNT(DISTINCT sku) as unique_skus,
                    action_type,
                    trigger_source,
                    DATE(created_at) as action_date,
                    COUNT(*) as daily_count
                FROM mapping_audit_log
                WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY action_type, trigger_source, DATE(created_at)
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId, $days]);
            $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats['total_actions'] = 0;
            $stats['by_action_type'] = [];
            $stats['by_trigger_source'] = [];
            $stats['daily_activity'] = [];

            foreach ($dailyStats as $row) {
                $stats['total_actions'] += $row['daily_count'];
                $stats['by_action_type'][$row['action_type']] = ($stats['by_action_type'][$row['action_type']] ?? 0) + $row['daily_count'];
                $stats['by_trigger_source'][$row['trigger_source']] = ($stats['by_trigger_source'][$row['trigger_source']] ?? 0) + $row['daily_count'];

                if (!isset($stats['daily_activity'][$row['action_date']])) {
                    $stats['daily_activity'][$row['action_date']] = 0;
                }
                $stats['daily_activity'][$row['action_date']] += $row['daily_count'];
            }

            return ['success' => true, 'stats' => $stats];

        } catch (PDOException $e) {
            $this->logError("Errore getUserAuditStats: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pulisce i log di audit obsoleti.
     * @param int|null $retentionDays
     * @return int
     */
    public function cleanupOldAuditLogs(?int $retentionDays = null): int
    {
        try {
            $days = $retentionDays ?? $this->config['audit']['retention_days'] ?? 90;

            $stmt = $this->db->prepare("
                DELETE FROM mapping_audit_log
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$days]);

            $deletedCount = $stmt->rowCount();
            $this->logInfo("Cleanup audit logs: eliminati {$deletedCount} record più vecchi di {$days} giorni");

            return $deletedCount;

        } catch (PDOException $e) {
            $this->logError("Errore cleanupOldAuditLogs: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Salva un conflitto di mapping nel database.
     * @param int $userId
     * @param string $sku
     * @param array $conflictData
     * @return bool
     */
    public function saveConflict(int $userId, string $sku, array $conflictData): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mapping_conflicts (user_id, sku, conflict_data)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                conflict_data = VALUES(conflict_data),
                resolution_status = 'pending',
                created_at = CURRENT_TIMESTAMP
            ");

            return $stmt->execute([
                $userId,
                $sku,
                json_encode($conflictData)
            ]);

        } catch (PDOException $e) {
            $this->logError("Errore saveConflict: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Marca un conflitto come risolto.
     * @param int $conflictId
     * @param string $resolutionStatus
     * @param string $resolvedBy
     * @return bool
     */
    public function markConflictResolved(int $conflictId, string $resolutionStatus, string $resolvedBy = 'system'): bool
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mapping_conflicts
                SET resolution_status = ?, resolved_by = ?, resolved_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");

            return $stmt->execute([$resolutionStatus, $resolvedBy, $conflictId]);

        } catch (PDOException $e) {
            $this->logError("Errore markConflictResolved: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottiene i conflitti pendenti per un utente.
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getPendingConflicts(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    mc.*,
                    p_existing.nome as existing_product_name,
                    p_suggested.nome as suggested_product_name
                FROM mapping_conflicts mc
                LEFT JOIN products p_existing ON JSON_EXTRACT(mc.conflict_data, '$.existing_product_id') = p_existing.id
                LEFT JOIN products p_suggested ON JSON_EXTRACT(mc.conflict_data, '$.suggested_product_id') = p_suggested.id
                WHERE mc.user_id = ? AND mc.resolution_status IN ('pending', 'manual_required')
                ORDER BY mc.created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$userId, $limit]);
            $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($conflicts as &$conflict) {
                if ($conflict['conflict_data']) {
                    $conflict['conflict_data'] = json_decode($conflict['conflict_data'], true);
                }
            }
            return $conflicts;

        } catch (PDOException $e) {
            $this->logError("Errore getPendingConflicts: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Recupera gli SKU non mappati da una specifica sorgente.
     * @param string $sourceName
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUnmappedSkusFromSource(string $sourceName, int $userId, int $limit): array
    {
        try {
            $skus = [];
            switch ($sourceName) {
                case 'inbound_shipments':
                    $stmt = $this->db->prepare("
                        SELECT DISTINCT seller_sku as sku, product_name as nome, fnsku, asin
                        FROM inbound_shipment_items
                        WHERE user_id = ? AND product_id IS NULL AND seller_sku IS NOT NULL
                        ORDER BY created_at DESC
                        LIMIT ?
                    ");
                    $stmt->execute([$userId, $limit]);
                    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                case 'inventory':
                    // FIX: Escludi SKU null/vuoti per consistenza
                    $stmt = $this->db->prepare("
                        SELECT sku, asin, product_name 
                        FROM inventory 
                        WHERE user_id = ? 
                        AND product_id IS NULL 
                        AND sku IS NOT NULL 
                        AND sku != ''
                        LIMIT ?
                    ");
                    $stmt->execute([$userId, $limit]);
                    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                case 'inventory_fbm':
                    // FIX: Escludi SKU null/vuoti per consistenza
                    $stmt = $this->db->prepare("
                        SELECT seller_sku as sku, asin1 as asin, item_name as product_name 
                        FROM inventory_fbm 
                        WHERE user_id = ? 
                        AND product_id IS NULL 
                        AND seller_sku IS NOT NULL 
                        AND seller_sku != ''
                        LIMIT ?
                    ");
                    $stmt->execute([$userId, $limit]);
                    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                case 'removal_orders':
                    $stmt = $this->db->prepare("
                        SELECT DISTINCT sku, fnsku
                        FROM removal_orders 
                        WHERE user_id = ? 
                        AND product_id IS NULL 
                        AND sku IS NOT NULL 
                        AND sku != ''
                        LIMIT ?
                    ");
                    $stmt->execute([$userId, $limit]);
                    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                case 'settlement':
                    $settlementTable = "report_settlement_{$userId}";
                    if ($this->tableExists($settlementTable)) {
                        // FIX: Escludi SKU null/vuoti (fees, adjustments senza SKU)
                        $stmt = $this->db->prepare("
                            SELECT DISTINCT sku 
                            FROM `{$settlementTable}` 
                            WHERE product_id IS NULL 
                            AND sku IS NOT NULL 
                            AND sku != '' 
                            AND sku NOT LIKE '%Fee%'
                            AND sku NOT LIKE '%Adjustment%'
                            LIMIT ?
                        ");
                        $stmt->execute([$limit]);
                        $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                    break;
                case 'shipments_trid':
                    $stmt = $this->db->prepare("
                        SELECT DISTINCT msku as sku, fnsku, asin, title as product_name
                        FROM shipments_trid
                        WHERE user_id = ?
                        AND product_id IS NULL
                        AND msku IS NOT NULL
                        AND msku != ''
                        LIMIT ?
                    ");
                    $stmt->execute([$userId, $limit]);
                    $skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    break;
                default:
                    $this->logError("Sorgente sconosciuta: {$sourceName}");
                    break;
            }
            return $skus;
        } catch (PDOException $e) {
            $this->logError("Errore getUnmappedSkusFromSource ({$sourceName}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Aggiorna il product_id per uno SKU in una tabella sorgente.
     * @param string $sourceName
     * @param int $userId
     * @param string $sku
     * @param int $productId
     * @return bool
     */
    public function updateSourceMapping(string $sourceName, int $userId, string $sku, int $productId): bool
    {
        try {
            switch ($sourceName) {
                case 'inventory':
                    $stmt = $this->db->prepare("UPDATE inventory SET product_id = ? WHERE user_id = ? AND sku = ?");
                    $stmt->execute([$productId, $userId, $sku]);
                    break;
                case 'inventory_fbm':
                    $stmt = $this->db->prepare("UPDATE inventory_fbm SET product_id = ? WHERE user_id = ? AND seller_sku = ?");
                    $stmt->execute([$productId, $userId, $sku]);
                    break;
                case 'settlement':
                    $settlementTable = "report_settlement_{$userId}";
                    if ($this->tableExists($settlementTable)) {
                        $stmt = $this->db->prepare("UPDATE `{$settlementTable}` SET product_id = ? WHERE sku = ?");
                        $stmt->execute([$productId, $sku]);
                    }
                    break;
                case 'inbound_shipments':
                    $stmt = $this->db->prepare("UPDATE inbound_shipment_items SET product_id = ? WHERE user_id = ? AND seller_sku = ?");
                    $stmt->execute([$productId, $userId, $sku]);
                    break;
                case 'removal_orders':
                    $stmt = $this->db->prepare("UPDATE removal_orders SET product_id = ? WHERE user_id = ? AND sku = ?");
                    $stmt->execute([$productId, $userId, $sku]);
                    break;
                case 'shipments_trid':
                    $stmt = $this->db->prepare("UPDATE shipments_trid SET product_id = ? WHERE user_id = ? AND msku = ?");
                    $stmt->execute([$productId, $userId, $sku]);
                    break;
                default:
                    $this->logError("Sorgente sconosciuta per update: {$sourceName}");
                    return false;
            }
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Errore updateSourceMapping ({$sourceName}): " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cerca prodotti esistenti per il mapping.
     * @param int $userId
     * @param string $searchTerm
     * @param string $searchField
     * @param int $limit
     * @return array
     */
    public function findProducts(int $userId, string $searchTerm, string $searchField = 'sku', int $limit = 10): array
{
    try {
        if ($searchField === 'all') {
            // Cerca in tutti i campi
            $query = "SELECT id, nome, sku, asin, fnsku FROM products 
                     WHERE user_id = ? 
                     AND (nome LIKE ? OR sku LIKE ? OR asin LIKE ? OR fnsku LIKE ?) 
                     LIMIT ?";
            $searchPattern = '%' . $searchTerm . '%';
            $stmt = $this->db->prepare($query);
            $stmt->execute([$userId, $searchPattern, $searchPattern, $searchPattern, $searchPattern, $limit]);
        } else {
            // Cerca in campo specifico
            $query = "SELECT id, nome, sku, asin, fnsku FROM products WHERE user_id = ? AND {$searchField} LIKE ? LIMIT ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$userId, '%' . $searchTerm . '%', $limit]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $this->logError("Errore findProducts: " . $e->getMessage());
        return [];
    }
}

    /**
     * Ottiene un prodotto per ID.
     * @param int $productId
     * @return array|null
     */
    public function getProductById(int $productId): ?array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, nome, sku, asin, fnsku FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            $this->logError("Errore getProductById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Crea un nuovo prodotto.
     * @param int $userId
     * @param string $nome
     * @param string|null $sku
     * @param string|null $asin
     * @param string|null $fnsku
     * @return int|false ID del nuovo prodotto o false in caso di errore.
     */
    public function createProduct(int $userId, string $nome, ?string $sku = null, ?string $asin = null, ?string $fnsku = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO products (user_id, nome, sku, asin, fnsku)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $nome, $sku, $asin, $fnsku]);
            return $this->db->lastInsertId();
        } catch (PDOException $e) {
            $this->logError("Errore createProduct: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Aggiorna un prodotto esistente.
     * @param int $productId
     * @param array $data
     * @return bool
     */
    public function updateProduct(int $productId, array $data): bool
    {
        try {
            $setClauses = [];
            $params = [];
            foreach ($data as $key => $value) {
                $setClauses[] = "{$key} = ?";
                $params[] = $value;
            }
            $params[] = $productId;

            $query = "UPDATE products SET " . implode(', ', $setClauses) . " WHERE id = ?";
            $stmt = $this->db->prepare($query);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $this->logError("Errore updateProduct: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Ottiene tutti gli SKU mappati a un product_id specifico.
     * @param int $productId
     * @return array
     */
    public function getSkusByProductId(int $productId): array
    {
        try {
            $skus = [];
            
            // 1. Cerca in inventory
            $stmt = $this->db->prepare("SELECT DISTINCT sku FROM inventory WHERE product_id = ?");
            $stmt->execute([$productId]);
            $skus['inventory'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // 2. Cerca in inventory_fbm
            if ($this->tableExists('inventory_fbm')) {
                $stmt = $this->db->prepare("SELECT DISTINCT seller_sku FROM inventory_fbm WHERE product_id = ?");
                $stmt->execute([$productId]);
                $skus['inventory_fbm'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // 3. Cerca in inbound_shipment_items
            if ($this->tableExists('inbound_shipment_items')) {
                $stmt = $this->db->prepare("SELECT DISTINCT seller_sku FROM inbound_shipment_items WHERE product_id = ?");
                $stmt->execute([$productId]);
                $skus['inbound_shipment_items'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // 4. Cerca in removal_orders
            if ($this->tableExists('removal_orders')) {
                $stmt = $this->db->prepare("SELECT DISTINCT sku FROM removal_orders WHERE product_id = ?");
                $stmt->execute([$productId]);
                $skus['removal_orders'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }

            // 5. Cerca in mapping_states (per coprire anche settlement e altri casi)
            $stmt = $this->db->prepare("SELECT DISTINCT sku, source_table FROM mapping_states WHERE product_id = ?");
            $stmt->execute([$productId]);
            $mappingStatesSkus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($mappingStatesSkus as $msSku) {
                if (!isset($skus[$msSku['source_table']])) {
                    $skus[$msSku['source_table']] = [];
                }
                if (!in_array($msSku['sku'], $skus[$msSku['source_table']])) {
                    $skus[$msSku['source_table']][] = $msSku['sku'];
                }
            }

            // 6. Cerca in settlement (tabelle dinamiche per user)
            // Estrai user_id dal prodotto
            $stmtUser = $this->db->prepare("SELECT user_id FROM products WHERE id = ?");
            $stmtUser->execute([$productId]);
            $product = $stmtUser->fetch(PDO::FETCH_ASSOC);

            if ($product && $product['user_id']) {
                $userId = $product['user_id'];
                $settlementTable = "report_settlement_{$userId}";
                
                if ($this->tableExists($settlementTable)) {
                    try {
                        $stmt = $this->db->prepare("SELECT DISTINCT sku FROM `{$settlementTable}` WHERE product_id = ?");
                        $stmt->execute([$productId]);
                        $settlementSkus = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if (!empty($settlementSkus)) {
                            $skus['settlement'] = $settlementSkus;
                        }
                    } catch (PDOException $e) {
                        $this->logError("Error fetching settlement SKUs: " . $e->getMessage());
                    }
                }
            }

            // Rimuovi array vuoti
            $skus = array_filter($skus, function($arr) {
                return !empty($arr);
            });

            return $skus;
        } catch (PDOException $e) {
            $this->logError("Errore getSkusByProductId: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Ottiene tutti i prodotti per un utente.
     * @param int $userId
     * @return array
     */
    public function getAllProducts(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("SELECT id, nome, sku, asin, fnsku FROM products WHERE user_id = ? ORDER BY nome ASC");
            $stmt->execute([$userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $this->logError("Errore getAllProducts: " . $e->getMessage());
            return [];
        }
    }

/**
     * Collega più SKU a un singolo product_id.
     * LOGICA INTELLIGENTE: Propaga automaticamente il mapping a TUTTE le tabelle dove esiste lo SKU.
     * @param int $userId
     * @param int $productId
     * @param array $skusToAggregate Array associativo con 'source_table' => ['sku1', 'sku2']
     * @return array Risultato dell'operazione.
     */
    public function aggregateSkusToProduct(int $userId, int $productId, array $skusToAggregate): array
    {
        $results = ['success' => true, 'updated_count' => 0, 'cross_table_updates' => 0, 'errors' => []];
        try {
            $this->db->beginTransaction();

            foreach ($skusToAggregate as $sourceTable => $skus) {
                foreach ($skus as $sku) {
                    // NUOVA LOGICA: Propaga mapping a TUTTE le tabelle dove esiste questo SKU
                    $crossTableResults = $this->propagateSkuMappingToAllTables($userId, $sku, $productId);
                    
                    $results['updated_count'] += $crossTableResults['updated'];
                    $results['cross_table_updates'] += $crossTableResults['cross_updates'];
                    
                    if (!empty($crossTableResults['errors'])) {
                        $results['errors'] = array_merge($results['errors'], $crossTableResults['errors']);
                    }

                    // Salva mapping_state per la tabella principale
                    $saveStateResult = $this->saveMappingState($userId, $sku, $sourceTable, [
                        'product_id' => $productId,
                        'mapping_type' => 'manual_aggregation',
                        'confidence_score' => 1.00,
                        'metadata' => ['aggregated_by_user' => true, 'cross_table_propagated' => true]
                    ]);

                    if (!$saveStateResult['success']) {
                        $results['errors'][] = "Fallito salvataggio stato per {$sku}: " . ($saveStateResult['error'] ?? 'Errore sconosciuto');
                    }

                    // Log aggregazione cross-tabella
                    $this->logMappingAction([
                        'user_id' => $userId,
                        'sku' => $sku,
                        'old_product_id' => null,
                        'new_product_id' => $productId,
                        'action_type' => 'aggregate_cross_table',
                        'trigger_source' => 'manual_aggregation',
                        'confidence_after' => 1.00,
                        'metadata' => [
                            'source_table' => $sourceTable,
                            'cross_table_updates' => $crossTableResults['cross_updates'],
                            'tables_updated' => $crossTableResults['tables']
                        ]
                    ]);
                }
            }

            if (empty($results['errors'])) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
                $results['success'] = false;
            }

        } catch (PDOException $e) {
            $this->db->rollBack();
            $this->logError("Errore aggregateSkusToProduct: " . $e->getMessage());
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }
        return $results;
    }

    /**
     * Propaga mapping SKU a tutte le tabelle dove esiste.
     * Cerca lo SKU in inventory, inventory_fbm, inbound_shipment_items, settlement
     * e aggiorna automaticamente tutte le occorrenze.
     * @param int $userId
     * @param string $sku
     * @param int $productId
     * @return array Statistiche aggiornamento: ['updated', 'cross_updates', 'tables', 'errors']
     */
    private function propagateSkuMappingToAllTables(int $userId, string $sku, int $productId): array
    {
        $result = ['updated' => 0, 'cross_updates' => 0, 'tables' => [], 'errors' => []];
        
        // Definisci tutte le tabelle con mapping
        $tableMappings = [
            'inventory' => ['table' => 'inventory', 'sku_column' => 'sku', 'has_user_id' => true],
            'inventory_fbm' => ['table' => 'inventory_fbm', 'sku_column' => 'seller_sku', 'has_user_id' => true],
            'inbound_shipment_items' => ['table' => 'inbound_shipment_items', 'sku_column' => 'seller_sku', 'has_user_id' => true],
            'removal_orders' => ['table' => 'removal_orders', 'sku_column' => 'sku', 'has_user_id' => true],
            'settlement' => ['table' => "report_settlement_{$userId}", 'sku_column' => 'sku', 'has_user_id' => false],
            'shipments_trid' => ['table' => 'shipments_trid', 'sku_column' => 'msku', 'has_user_id' => true]
        ];
        
        foreach ($tableMappings as $key => $config) {
            try {
                // Verifica esistenza tabella (solo per settlement dinamica)
                if ($key === 'settlement' && !$this->tableExists($config['table'])) {
                    continue;
                }
                
                // Costruisci query dinamica
                if ($config['has_user_id']) {
                    $sql = "UPDATE {$config['table']} SET product_id = ? WHERE {$config['sku_column']} = ? AND user_id = ? AND (product_id IS NULL OR product_id != ?)";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([$productId, $sku, $userId, $productId]);
                } else {
                    $sql = "UPDATE `{$config['table']}` SET product_id = ? WHERE {$config['sku_column']} = ? AND (product_id IS NULL OR product_id != ?)";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([$productId, $sku, $productId]);
                }
                
                $rowsAffected = $stmt->rowCount();
                if ($rowsAffected > 0) {
                    $result['updated'] += $rowsAffected;
                    $result['cross_updates']++;
                    $result['tables'][] = $key;
                }
                
            } catch (PDOException $e) {
                $result['errors'][] = "Errore update {$key}: " . $e->getMessage();
                $this->logError("Propagate mapping error in {$key}: " . $e->getMessage());
            }
        }
        
        return $result;
    }

    /**
     * Verifica se una tabella esiste nel database.
     * @param string $tableName
     * @return bool
     */
    private function tableExists(string $tableName): bool
    {
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE " . $this->db->quote($tableName));
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logError("Errore tableExists per {$tableName}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Scrive un messaggio di errore nel log.
     * @param string $message
     */
    private function logError(string $message): void
    {
        CentralLogger::log('mapping', 'ERROR', $message);
    }

    /**
     * Scrive un messaggio informativo nel log (se debug_mode è abilitato).
     * @param string $message
     */
    private function logInfo(string $message): void
    {
        if (isset($this->config['logging']['file']) && ($this->config['debug_mode'] ?? false)) {
            $timestamp = date('Y-m-d H:i:s');
            file_put_contents($this->config['logging']['file'], "[{$timestamp}] [INFO] [MappingRepository] {$message}" . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
?>

