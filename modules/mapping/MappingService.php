<?php
/**
 * Enterprise Mapping System - Business Logic (Service Layer)
 * File: /modules/mapping/MappingService.php
 *
 * Questo file contiene la logica di business principale per il sistema di mapping.
 * Agisce come orchestratore, coordinando le operazioni tra il repository, le strategie e le sorgenti.
 */

require_once __DIR__ . 
'/MappingRepository.php';
require_once __DIR__ . '/MappingInterfaces.php';
// Non includiamo mapping_config.php direttamente qui, ma passiamo la configurazione via costruttore

class MappingService
{
    private MappingRepository $repository;
    private array $config;
    private array $sources = [];
    private array $strategies = [];

    public function __construct(MappingRepository $repository, array $config)
    {
        $this->repository = $repository;
        $this->config = $config;
        $this->initializeComponents();
        $this->loadSources();
        $this->loadStrategies();
    }

    /**
     * Inizializza componenti core (simulazione di iniezione dipendenze per AuditLogger e ConflictResolver)
     */
    private function initializeComponents()
    {
        // In un sistema più complesso, questi potrebbero essere servizi separati iniettati.
        // Qui, le loro funzionalità sono delegate al repository o integrate direttamente.
    }

    /**
     * Carica le implementazioni concrete delle sorgenti dati.
     */
    private function loadSources(): void
    {
        $this->sources = [
            'inventory' => new InventoryMappingSource($this->repository),
            'inventory_fbm' => new FbmMappingSource($this->repository),
            'settlement' => new SettlementMappingSource($this->repository),
            'inbound_shipments' => new InboundShipmentsMappingSource($this->repository),
            'removal_orders' => new RemovalOrdersMappingSource($this->repository),
            'shipments_trid' => new ShipmentsTridMappingSource($this->repository)
        ];
    }

    /**
     * Carica le implementazioni concrete delle strategie di mapping.
     */
    private function loadStrategies(): void
    {
        $this->strategies = [
            new ExactMatchStrategy($this->repository, $this->config),
            new FuzzyMatchStrategy($this->repository, $this->config),
            new AiAssistedStrategy($this->repository, $this->config)
        ];

        // Ordina le strategie per priorità
        usort($this->strategies, function ($a, $b) {
            return $a->getPriority() - $b->getPriority();
        });
    }

    /**
     * Esegue il processo di mapping completo per un utente.
     * @param int $userId
     * @param string|null $sourceFilter Filtra le sorgenti da processare (es. 'inventory', 'settlement').
     * @return array Risultato del mapping.
     */
    public function executeFullMapping(int $userId, ?string $sourceFilter = null): array
    {
        $this->log("=== START executeFullMapping user {$userId}, filter: " . ($sourceFilter ?? 'all'), 'DEBUG');

        $startTime = microtime(true);
        $results = [
            'success' => true,
            'user_id' => $userId,
            'processed_skus' => 0,
            'mapped_skus' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'sources_processed' => [],
            'execution_time' => 0
        ];

        // Step 1: Ripristina stati esistenti
        $restoreResult = $this->repository->restoreAllMappings($userId);
        $this->log("Ripristinati " . json_encode($restoreResult) . " mapping esistenti", 'DEBUG');

        // Step 2: Processa ogni sorgente
        foreach ($this->sources as $sourceName => $source) {
            $this->log("Checking source: {$sourceName}", 'DEBUG');

            if ($sourceFilter && $sourceFilter !== $sourceName) {
                $this->log("Skipping source {$sourceName} (filtered)", 'DEBUG');
                continue;
            }

            if (!$source->isAvailable()) {
                $this->log("Skipping source {$sourceName} (not available)", 'DEBUG');
                continue;
            }

            $sourceResult = $this->processMappingSource($userId, $sourceName, $source);
            $this->log("Source {$sourceName} result: " . json_encode($sourceResult), 'DEBUG');

            $results['sources_processed'][$sourceName] = $sourceResult;
            $results['processed_skus'] += $sourceResult['processed'];
            $results['mapped_skus'] += $sourceResult['mapped'];
            $results['conflicts'] += $sourceResult['conflicts'];
            $results['errors'] += $sourceResult['errors'];
        }

        // Step 3: Risolvi conflitti pendenti
        $conflictResults = $this->resolveAllConflicts($userId);
        $results['conflicts_resolved'] = $conflictResults['resolved'];

        $results['execution_time'] = round((microtime(true) - $startTime) * 1000, 2);

        $this->log("=== END mapping completato per user {$userId}: {$results['mapped_skus']} SKU mappati in {$results['execution_time']}ms", 'INFO');
        
        // Log consolidato su sync_debug_logs (sempre attivo)
        CentralLogger::log('mapping', 
            $results['errors'] > 0 ? 'WARNING' : 'INFO',
            sprintf('Mapping completato: %d/%d SKU mappati (%.1f%% confidence)',
                $results['mapped_skus'],
                $results['processed_skus'],
                $results['processed_skus'] > 0 ? 
                    ($results['mapped_skus'] / $results['processed_skus'] * 100) : 0
            ),
            [
                'user_id' => $userId,
                'mapped_skus' => $results['mapped_skus'],
                'processed_skus' => $results['processed_skus'],
                'errors' => $results['errors'],
                'execution_time_ms' => $results['execution_time']
            ]
        );

        return $results;
    }

    /**
     * Processa il mapping per una singola sorgente.
     * @param int $userId
     * @param string $sourceName
     * @param MappingSourceInterface $source
     * @return array
     */
    private function processMappingSource(int $userId, string $sourceName, MappingSourceInterface $source): array
    {
        $result = [
            'source' => $sourceName,
            'processed' => 0,
            'mapped' => 0,
            'conflicts' => 0,
            'errors' => 0
        ];

        try {
            $unmappedSkus = $source->getUnmappedSkus($userId, $this->config['batch_size']);
            $this->log("Source {$sourceName}: trovati " . count($unmappedSkus) . " SKU da processare", 'DEBUG');

            foreach ($unmappedSkus as $skuData) {
                $result['processed']++;
                $sku = $skuData['sku'] ?? null;

                // SAFETY CHECK: Skip SKU null/vuoti (fees, adjustments senza SKU)
                if (empty($sku) || !is_string($sku)) {
                    $this->log("SKIPPED: SKU vuoto/invalido da source {$sourceName} - Type: " . gettype($sku), 'WARNING');
                    $result['errors']++;
                    continue;
                }

                $this->log("Processing SKU: {$sku} from source {$sourceName}", 'DEBUG');

                $mappingResult = $this->executeSingleMapping($userId, $sku, $sourceName, $skuData);

                $this->log("SKU {$sku} mapping result: " . json_encode($mappingResult), 'DEBUG');

                if ($mappingResult['success']) {
                    $result['mapped']++;
                } elseif (isset($mappingResult['conflict']) && $mappingResult['conflict']) {
                    $result['conflicts']++;
                } else {
                    $result['errors']++;
                }
            }

        } catch (Exception $e) {
            $this->log("Errore processing source {$sourceName}: " . $e->getMessage(), 'ERROR');
            $result['errors']++;
        }

        return $result;
    }

    /**
     * Esegue il mapping per un singolo SKU utilizzando le strategie disponibili.
     * @param int $userId
     * @param string $sku
     * @param string $sourceName
     * @param array $context
     * @return array
     */
    private function executeSingleMapping(int $userId, string $sku, string $sourceName, array $context = []): array
    {
        try {
            // Verifica se mapping già esistente e locked
            $existingState = $this->repository->getMappingState($userId, $sku, $sourceName);
            if ($existingState && $existingState['is_locked']) {
                return ['success' => true, 'reason' => 'locked', 'product_id' => $existingState['product_id']];
            }

            $this->log("Tentativo mapping SKU {$sku} - testing " . count($this->strategies) . " strategie", 'DEBUG');

            // Esegui strategie in ordine di priorità
            foreach ($this->strategies as $strategy) {
                $strategyName = $strategy->getName();
                $this->log("Testing strategia {$strategyName} per SKU {$sku}", 'DEBUG');

                // Verifica se strategia può gestire questo SKU
                if (!$strategy->canHandle($sku, $context)) {
                    $this->log("Strategia {$strategyName} non può gestire SKU {$sku}", 'DEBUG');
                    continue;
                }

                // Esegui strategia
                $strategyResult = $strategy->executeMapping($userId, $sku, $context);
                $this->log("Strategia {$strategyName} per SKU {$sku}: " . json_encode($strategyResult), 'DEBUG');

                // Se strategia ha successo E confidence sufficiente
                if ($strategyResult['success'] &&
                    isset($strategyResult['confidence']) &&
                    $strategyResult['confidence'] >= $this->config['min_confidence']) {

                    $this->log("SUCCESS: Strategia {$strategyName} ha mappato SKU {$sku} con confidence {$strategyResult['confidence']}", 'INFO');

                    // Salva stato mapping
                    $stateResult = $this->repository->saveMappingState($userId, $sku, $sourceName, [
                        'product_id' => $strategyResult['product_id'],
                        'mapping_type' => $strategyName,
                        'confidence_score' => $strategyResult['confidence'],
                        'metadata' => $strategyResult['metadata'] ?? []
                    ]);

                    // Log audit
                    $this->repository->logMappingAction([
                        'user_id' => $userId,
                        'sku' => $sku,
                        'old_product_id' => null,
                        'new_product_id' => $strategyResult['product_id'],
                        'action_type' => 'create',
                        'trigger_source' => $strategyName,
                        'confidence_after' => $strategyResult['confidence']
                    ]);

                    // Aggiorna sorgente
                    $source = $this->sources[$sourceName];
                    $source->updateMapping($userId, $sku, $strategyResult['product_id']);

                    return [
                        'success' => true,
                        'product_id' => $strategyResult['product_id'],
                        'strategy' => $strategyName,
                        'confidence' => $strategyResult['confidence']
                    ];
                } else {
                    // Strategia fallita - continua con prossima
                    $reason = !($strategyResult['success'] ?? false) ? 'strategy_failed' : 'confidence_too_low';
                    $confidence = $strategyResult['confidence'] ?? 0;
                    $this->log("FALLITA: Strategia {$strategyName} per SKU {$sku} - {$reason} (confidence: {$confidence})", 'DEBUG');
                }
            }

            // TUTTE le strategie hanno fallito
            $this->log("NO_MATCH: Tutte le strategie hanno fallito per SKU {$sku}", 'WARNING');
            return ['success' => false, 'reason' => 'no_match'];

        } catch (Exception $e) {
            $this->log("Errore mapping SKU {$sku}: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Rileva e gestisce un conflitto di mapping.
     * @param int $userId
     * @param string $sku
     * @param array $existingMapping
     * @param array $newMapping
     * @return array
     */
    public function detectAndResolveConflict(int $userId, string $sku, array $existingMapping, array $newMapping): array
    {
        // Nessun conflitto se mapping identici
        if ($existingMapping['product_id'] === $newMapping['product_id']) {
            return ['has_conflict' => false];
        }

        // Nessun conflitto se esistente è null
        if (!$existingMapping['product_id']) {
            return ['has_conflict' => false];
        }

        // Nessun conflitto se existing è locked e confidence alta
        if ($existingMapping['is_locked'] && $existingMapping['confidence_score'] >= $this->config['auto_lock_threshold']) {
            return ['has_conflict' => false, 'reason' => 'existing_locked'];
        }

        // Conflitto rilevato
        $conflictData = [
            'has_conflict' => true,
            'conflict_type' => $this->determineConflictType($existingMapping, $newMapping),
            'existing_product_id' => $existingMapping['product_id'],
            'existing_confidence' => $existingMapping['confidence_score'],
            'existing_type' => $existingMapping['mapping_type'],
            'suggested_product_id' => $newMapping['product_id'],
            'suggested_confidence' => $newMapping['confidence_score'],
            'suggested_type' => $newMapping['mapping_type'],
            'resolution_suggestion' => $this->suggestResolution($existingMapping, $newMapping)
        ];

        // Salva conflitto
        $this->repository->saveConflict($userId, $sku, $conflictData);

        return $conflictData;
    }

    /**
     * Risolve tutti i conflitti pendenti per un utente.
     * @param int $userId
     * @return array
     */
    public function resolveAllConflicts(int $userId): array
    {
        try {
            $conflicts = $this->repository->getPendingConflicts($userId);

            $results = [
                'total_conflicts' => count($conflicts),
                'resolved' => 0,
                'manual_required' => 0,
                'errors' => 0,
                'resolutions' => []
            ];

            foreach ($conflicts as $conflict) {
                $conflictData = $conflict['conflict_data']; // Già decodificato dal repository
                $resolution = $this->resolveConflictAutomatically($userId, $conflict['sku'], $conflictData);

                $results['resolutions'][] = [
                    'sku' => $conflict['sku'],
                    'resolution' => $resolution
                ];

                if ($resolution['success']) {
                    if ($resolution['manual_required'] ?? false) {
                        $results['manual_required']++;
                    } else {
                        $results['resolved']++;
                        $this->repository->markConflictResolved($conflict['id'], 'auto_resolved');
                    }
                } else {
                    $results['errors']++;
                }
            }

            return $results;

        } catch (Exception $e) {
            $this->log("Errore resolveAllConflicts: " . $e->getMessage(), 'ERROR');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tenta di risolvere un conflitto automaticamente.
     * @param int $userId
     * @param string $sku
     * @param array $conflictData
     * @return array
     */
    private function resolveConflictAutomatically(int $userId, string $sku, array $conflictData): array
    {
        $resolution = $conflictData['resolution_suggestion'];

        switch ($resolution['action']) {
            case 'keep_existing':
                return $this->resolveKeepExisting($userId, $sku, $conflictData);
            case 'accept_new':
                return $this->resolveAcceptNew($userId, $sku, $conflictData);
            case 'require_manual':
                return $this->resolveRequireManual($userId, $sku, $conflictData);
            case 'merge_data':
                return $this->resolveMergeData($userId, $sku, $conflictData);
            default:
                return ['success' => false, 'error' => 'Unknown resolution action'];
        }
    }

    /**
     * Determina il tipo di conflitto.
     * @param array $existing
     * @param array $new
     * @return string
     */
    private function determineConflictType(array $existing, array $new): string
    {
        if (abs($existing['confidence_score'] - $new['confidence_score']) > 0.1) {
            return 'confidence_mismatch';
        }
        if ($existing['mapping_type'] !== $new['mapping_type']) {
            return 'type_mismatch';
        }
        return 'product_mismatch';
    }

    /**
     * Suggerisce una risoluzione per un conflitto.
     * @param array $existing
     * @param array $new
     * @return array
     */
    private function suggestResolution(array $existing, array $new): array
    {
        if ($new['confidence_score'] - $existing['confidence_score'] > 0.15) {
            return [
                'action' => 'accept_new',
                'reason' => 'new_higher_confidence',
                'confidence' => 0.9
            ];
        }
        if ($existing['confidence_score'] - $new['confidence_score'] > 0.15) {
            return [
                'action' => 'keep_existing',
                'reason' => 'existing_higher_confidence',
                'confidence' => 0.9
            ];
        }
        if ($existing['confidence_score'] > 0.8 && $new['confidence_score'] > 0.8) {
            return [
                'action' => 'require_manual',
                'reason' => 'both_high_confidence',
                'confidence' => 0.5
            ];
        }
        if ($existing['mapping_type'] === 'manual') {
            return [
                'action' => 'keep_existing',
                'reason' => 'preserve_manual_mapping',
                'confidence' => 0.95
            ];
        }
        if ($new['confidence_score'] >= ($this->config['conflict_threshold'] ?? 0.60)) {
            return [
                'action' => 'accept_new',
                'reason' => 'new_meets_threshold',
                'confidence' => 0.7
            ];
        }
        return [
            'action' => 'require_manual',
            'reason' => 'ambiguous_conflict',
            'confidence' => 0.3
        ];
    }

    /**
     * Risoluzione: mantieni esistente.
     * @param int $userId
     * @param string $sku
     * @param array $conflictData
     * @return array
     */
    private function resolveKeepExisting(int $userId, string $sku, array $conflictData): array
    {
        $this->repository->logMappingAction([
            'user_id' => $userId, 'sku' => $sku, 'old_product_id' => null,
            'new_product_id' => $conflictData['existing_product_id'], 'action_type' => 'conflict_resolved',
            'trigger_source' => 'auto_resolver', 'confidence_after' => $conflictData['existing_confidence'],
            'metadata' => ['resolution' => 'keep_existing', 'conflict_data' => $conflictData]
        ]);

        return [
            'success' => true,
            'action_taken' => 'keep_existing',
            'product_id' => $conflictData['existing_product_id'],
            'confidence' => $conflictData['existing_confidence']
        ];
    }

    /**
     * Risoluzione: accetta nuovo.
     * @param int $userId
     * @param string $sku
     * @param array $conflictData
     * @return array
     */
    private function resolveAcceptNew(int $userId, string $sku, array $conflictData): array
    {
        // Determina la source table appropriata per lo SKU
        // Questo è un punto debole nell'architettura originale, poiché non c'è un modo diretto per sapere la source_table
        // dal solo SKU. Assumiamo che il conflictData contenga la source_table o che sia recuperabile.
        // Per ora, useremo una logica semplificata o assumeremo che il conflictData la contenga.
        $sourceTable = $conflictData['source_table'] ?? 'inventory'; // Default o recupero più intelligente

        $saveResult = $this->repository->saveMappingState($userId, $sku, $sourceTable, [
            'product_id' => $conflictData['suggested_product_id'],
            'mapping_type' => $conflictData['suggested_type'],
            'confidence_score' => $conflictData['suggested_confidence'],
            'metadata' => ['resolved_conflict' => true]
        ]);

        $this->repository->logMappingAction([
            'user_id' => $userId, 'sku' => $sku, 'old_product_id' => $conflictData['existing_product_id'],
            'new_product_id' => $conflictData['suggested_product_id'], 'action_type' => 'conflict_resolved',
            'trigger_source' => 'auto_resolver', 'confidence_after' => $conflictData['suggested_confidence'],
            'metadata' => ['resolution' => 'accept_new', 'conflict_data' => $conflictData]
        ]);

        return [
            'success' => true,
            'action_taken' => 'accept_new',
            'product_id' => $conflictData['suggested_product_id'],
            'confidence' => $conflictData['suggested_confidence']
        ];
    }

    /**
     * Risoluzione: richiedi intervento manuale.
     * @param int $userId
     * @param string $sku
     * @param array $conflictData
     * @return array
     */
    private function resolveRequireManual(int $userId, string $sku, array $conflictData): array
    {
        // Aggiorna stato conflitto nel DB a 'manual_required'
        // Nota: il repository gestisce già l'aggiornamento dello stato del conflitto
        // Questa funzione è più per la logica di notifica o escalation

        $this->repository->logMappingAction([
            'user_id' => $userId, 'sku' => $sku, 'old_product_id' => null, 'new_product_id' => null,
            'action_type' => 'conflict_escalated', 'trigger_source' => 'auto_resolver',
            'confidence_after' => null,
            'metadata' => ['resolution' => 'require_manual', 'conflict_data' => $conflictData]
        ]);

        return [
            'success' => true,
            'action_taken' => 'require_manual',
            'manual_required' => true,
            'message' => 'Conflitto richiede intervento manuale'
        ];
    }

    /**
     * Risoluzione: merge dati (per ora, prende quello con confidence più alta).
     * @param int $userId
     * @param string $sku
     * @param array $conflictData
     * @return array
     */
    private function resolveMergeData(int $userId, string $sku, array $conflictData): array
    {
        $useExisting = $conflictData['existing_confidence'] >= $conflictData['suggested_confidence'];

        if ($useExisting) {
            return $this->resolveKeepExisting($userId, $sku, $conflictData);
        } else {
            return $this->resolveAcceptNew($userId, $sku, $conflictData);
        }
    }

    /**
     * Logging unificato per il servizio.
     * @param string $message
     * @param string $level
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        if ($this->config['debug_mode'] ?? false) {
            $timestamp = date('Y-m-d H:i:s');
            $logMessage = "[{$timestamp}] [{$level}] [MappingService] {$message}" . PHP_EOL;

            if (isset($this->config['logging']['file'])) {
                file_put_contents($this->config['logging']['file'], $logMessage, FILE_APPEND | LOCK_EX);
            }

            if ($level === 'ERROR') {
                error_log($logMessage);
            }
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
        return $this->repository->getMappingState($userId, $sku, $sourceTable);
    }

    /**
     * Ottiene tutti i prodotti per un utente.
     * @param int $userId
     * @return array
     */
    public function getAllProducts(int $userId): array
    {
        return $this->repository->getAllProducts($userId);
    }

    /**
     * Ottiene tutti gli SKU mappati a un product_id specifico.
     * @param int $productId
     * @return array
     */
    public function getSkusByProductId(int $productId): array
    {
        return $this->repository->getSkusByProductId($productId);
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
        return $this->repository->createProduct($userId, $nome, $sku, $asin, $fnsku);
    }

    /**
     * Aggiorna un prodotto esistente.
     * @param int $productId
     * @param array $data
     * @return bool
     */
    public function updateProduct(int $productId, array $data): bool
    {
        return $this->repository->updateProduct($productId, $data);
    }

    /**
     * Collega più SKU a un singolo product_id.
     * @param int $userId
     * @param int $productId
     * @param array $skusToAggregate Array associativo con 'source_table' => ['sku1', 'sku2']
     * @return array Risultato dell'operazione.
     */
    public function aggregateSkusToProduct(int $userId, int $productId, array $skusToAggregate): array
    {
        return $this->repository->aggregateSkusToProduct($userId, $productId, $skusToAggregate);
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
        return $this->repository->findProducts($userId, $searchTerm, $searchField, $limit);
    }

    /**
     * Ottiene gli SKU non mappati da una specifica sorgente.
     * @param string $sourceName
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUnmappedSkusFromSource(string $sourceName, int $userId, int $limit): array
{
    if (!isset($this->sources[$sourceName])) {
        $this->log("Sorgente '{$sourceName}' non trovata.", 'ERROR');
        return [];
    }
    return $this->sources[$sourceName]->getUnmappedSkus($userId, $limit);
}

/**
 * Ottiene tutti gli SKU non mappati da tutte le sorgenti combinate
 */
public function getAllUnmappedSkusCombined(int $userId, int $limit): array
{
    $allSkus = [];
    
    foreach ($this->sources as $sourceName => $source) {
        if (!$source->isAvailable()) continue;
        
        $skus = $source->getUnmappedSkus($userId, $limit);
        foreach ($skus as $sku) {
            $sku['source'] = $sourceName; // Aggiungi la sorgente
            $allSkus[] = $sku;
        }
    }
    
    // Ordina per nome SKU
    usort($allSkus, function($a, $b) {
        return strcmp($a['sku'], $b['sku']);
    });
    
    return array_slice($allSkus, 0, $limit);
}

    // === WRAPPER METODI PENDING MAPPINGS ===

    /**
     * Ottiene mapping pending per approvazione (wrapper)
     * @param int $userId
     * @return array
     */
    public function getPendingMappingsForApproval(int $userId): array
    {
        return $this->repository->getPendingMappingsForApproval($userId);
    }

    /**
     * Conta mapping pending (wrapper)
     * @param int $userId
     * @return int
     */
    public function countPendingMappings(int $userId): int
    {
        return $this->repository->countPendingMappings($userId);
    }

    /**
     * Approva mapping pending (wrapper con logica business)
     * @param int $mappingStateId
     * @param int $userId
     * @return array
     */
    public function approvePendingMapping(int $mappingStateId, int $userId): array
    {
        $result = $this->repository->approvePendingMapping($mappingStateId, $userId);
        
        if ($result['success']) {
            // Aggiorna anche la tabella sorgente
            $this->updateSourceTableMapping($result['source_table'], $result['sku'], $result['product_id'], $userId);
            
            // Log dell'approvazione
            $this->log("Mapping approvato: SKU {$result['sku']} → Product {$result['product_id']}", 'INFO');
        }
        
        return $result;
    }

    /**
     * Rifiuta mapping pending (wrapper)
     * @param int $mappingStateId
     * @param int $userId
     * @return array
     */
    public function rejectPendingMapping(int $mappingStateId, int $userId): array
    {
        $result = $this->repository->rejectPendingMapping($mappingStateId, $userId);
        
        if ($result['success']) {
            $this->log("Mapping rifiutato: mapping state ID {$mappingStateId}", 'INFO');
        }
        
        return $result;
    }

    /**
     * Processamento bulk di mapping pending
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

        $this->log("Bulk processing completato: {$action} - {$results['processed']} processati", 'INFO');

        return $results;
    }

    /**
     * Statistiche pending per dashboard (wrapper)
     * @param int $userId
     * @return array
     */
    public function getPendingMappingStatistics(int $userId): array
    {
        return $this->repository->getPendingMappingStatistics($userId);
    }

    /**
     * Aggiorna tabella sorgente dopo approvazione
     * @param string $sourceTable
     * @param string $sku
     * @param int $productId
     * @param int $userId
     */
    private function updateSourceTableMapping(string $sourceTable, string $sku, int $productId, int $userId): void
{
    try {
        // Usa il metodo del repository invece di accesso diretto al DB
        $updated = $this->repository->updateSourceMapping($sourceTable, $userId, $sku, $productId);
        
        if ($updated) {
            $this->log("Tabella sorgente {$sourceTable} aggiornata: SKU {$sku} → Product {$productId}", 'INFO');
        } else {
            $this->log("ATTENZIONE: Aggiornamento tabella sorgente {$sourceTable} fallito per SKU {$sku}", 'WARNING');
        }
    } catch (Exception $e) {
        $this->log("Errore aggiornamento tabella sorgente {$sourceTable}: " . $e->getMessage(), 'ERROR');
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
        return $this->repository->getPendingConflicts($userId, $limit);
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
        return $this->repository->toggleMappingLock($userId, $sku, $sourceTable, $locked);
    }

    /**
     * Ottiene statistiche sui mapping per un utente.
     * @param int $userId
     * @return array
     */
    public function getMappingStatistics(int $userId): array
    {
        return $this->repository->getMappingStatistics($userId);
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
        return $this->repository->getSkuAuditHistory($userId, $sku, $limit);
    }

    /**
     * Pulisce i log di audit obsoleti.
     * @param int|null $retentionDays
     * @return int
     */
    public function cleanupOldAuditLogs(?int $retentionDays = null): int
    {
        return $this->repository->cleanupOldAuditLogs($retentionDays);
    }

    /**
     * Pulisce gli stati di mapping obsoleti.
     * @param int $daysOld
     * @return int
     */
    public function cleanupObsoleteStates(int $daysOld = 90): int
    {
        return $this->repository->cleanupObsoleteStates($daysOld);
    }
}
?>

