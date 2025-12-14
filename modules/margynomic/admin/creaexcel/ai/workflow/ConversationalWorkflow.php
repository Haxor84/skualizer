<?php
/**
 * ConversationalWorkflow
 * Gestisce workflow step-by-step con UI conversazionale
 * 
 * States: asin_input → keyword_extraction → field_generation → completed
 */
class ConversationalWorkflow
{
    private PDO $pdo;
    private int $userId;
    
    // Conversation states
    const STATE_ASIN_INPUT = 'asin_input';
    const STATE_KEYWORD_EXTRACTION = 'keyword_extraction';
    const STATE_FIELD_GENERATION = 'field_generation';
    const STATE_COMPLETED = 'completed';
    
    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }
    
    /**
     * Start conversation per SKU
     */
    public function startConversation(string $sku, array $context): array
    {
        // Check cache keywords (90 giorni)
        $cachedKeywords = $this->getCachedKeywords($sku);
        
        if ($cachedKeywords) {
            return [
                'state' => self::STATE_FIELD_GENERATION,
                'message' => "✅ Ho trovato **analisi recente** per questo SKU.\n\n" .
                            "📊 **{$cachedKeywords['count']} keywords** estratte {$this->timeAgo($cachedKeywords['created_at'])}.\n\n" .
                            "🔝 Top keywords: " . implode(', ', array_slice($cachedKeywords['keywords'], 0, 8)) . "...\n\n" .
                            "📝 Seleziona i campi da generare.",
                'keywords' => $cachedKeywords['keywords'],
                'analysis' => $cachedKeywords['analysis'] ?? '',
                'cached' => true
            ];
        }
        
        // NO CACHE: Avvia direttamente web research (NO ASIN RICHIESTI!)
        return [
            'state' => self::STATE_KEYWORD_EXTRACTION,
            'message' => "👋 Ciao! Generiamo contenuto per:\n\n" .
                        "**📦 SKU:** {$sku}\n\n" .
                        "🔍 **Avvio ricerca web automatica...**\n\n" .
                        "Sto cercando informazioni autorevoli su questo prodotto per generare un'analisi completa.",
            'sku' => $sku,
            'context' => $context,
            'trigger_auto_research' => true // NEW: trigger immediate research
        ];
    }
    
    /**
     * Process ASIN input
     */
    public function processAsinInput(string $sku, array $asins, bool $skipResearch): array
    {
        // Validate ASIN (se forniti)
        $validAsins = array_filter($asins, function($asin) {
            return preg_match('/^B[A-Z0-9]{9}$/', trim(strtoupper($asin)));
        });
        
        // Se NO ASIN forniti e NO skip → usa WEB RESEARCH
        if (count($validAsins) === 0) {
            CentralLogger::info('conversational_workflow', 'No ASIN provided, using web research', [
                'user_id' => $this->userId,
                'sku' => $sku
            ]);
            
            return $this->extractKeywords($sku, [], 'web_research_analysis', [
                'sku' => $sku,
                'brand' => 'Unknown',
                'weight' => '100g',
                'category' => 'Grocery'
            ]);
        }
        
        // Extract keywords from competitor ASIN
        return $this->extractKeywords($sku, $validAsins, 'competitor_analysis', [
            'sku' => $sku,
            'brand' => 'Unknown',
            'weight' => '100g',
            'category' => 'Grocery',
            'competitor_asins' => $validAsins
        ]);
    }
    
    /**
     * Extract keywords using WorkflowEngine
     */
    public function extractKeywords(string $sku, array $asins, string $mode, array $context): array
    {
        CentralLogger::info('conversational_workflow', 'Keyword extraction starting', [
            'user_id' => $this->userId,
            'sku' => $sku,
            'asin_count' => count($asins),
            'mode' => $mode
        ]);
        
        try {
            // Initialize WorkflowEngine
            require_once __DIR__ . '/WorkflowEngine.php';
            $workflowEngine = new WorkflowEngine($this->pdo, $this->userId);
            
            // Inject ASIN into context
            $context['competitor_asins'] = $asins;
            $workflowEngine->setContext($context);
            
            // Execute ONLY Step 1 (keyword research)
            $workflowEngine->executeKeywordResearch();
            
            // Get extracted keywords + analysis from context
            $workflowContext = $workflowEngine->getContext();
            $keywords = $workflowContext['keywords'] ?? [];
            $analysis = $workflowContext['analysis'] ?? ''; // NEW
            
            if (empty($keywords)) {
                CentralLogger::warning('conversational_workflow', 'No keywords extracted, using fallback', [
                    'user_id' => $this->userId,
                    'sku' => $sku
                ]);
                
                return [
                    'state' => self::STATE_FIELD_GENERATION,
                    'message' => "⚠️ **Nessuna keyword estratta.** Uso fallback AI.\n\n" .
                                "Procedo comunque con generazione contenuti.",
                    'keywords' => [],
                    'analysis' => '', // NEW
                    'asins' => $asins,
                    'mode' => $mode
                ];
            }
            
            // Save to cache (già fatto da WorkflowEngine, ma manteniamo per sicurezza)
            // $this->saveKeywordsCache($sku, $keywords, $asins, $mode);
            
            CentralLogger::info('conversational_workflow', 'Keywords + Analysis extracted successfully', [
                'user_id' => $this->userId,
                'sku' => $sku,
                'keyword_count' => count($keywords),
                'has_analysis' => !empty($analysis),
                'analysis_length' => strlen($analysis)
            ]);
            
            return [
                'state' => self::STATE_FIELD_GENERATION,
                'message' => "✅ **Ricerca completata!**\n\n" .
                            "📊 **Keywords estratte:** " . count($keywords) . "\n" .
                            "📚 **Analysis generata:** " . (strlen($analysis) > 0 ? strlen($analysis) . " caratteri" : "No") . "\n\n" .
                            "🔝 **Top keywords:** " . implode(', ', array_slice($keywords, 0, 10)) . "...\n\n" .
                            "📝 Seleziona ora i campi da generare.",
                'keywords' => $keywords,
                'analysis' => $analysis, // NEW
                'asins' => $asins,
                'mode' => $mode
            ];
            
        } catch (Exception $e) {
            CentralLogger::error('conversational_workflow', 'Keyword extraction failed', [
                'user_id' => $this->userId,
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            
            return [
                'state' => self::STATE_FIELD_GENERATION,
                'message' => "❌ **Errore estrazione keywords.**\n\n" .
                            $e->getMessage() . "\n\n" .
                            "Procedo con fallback AI.",
                'keywords' => [],
                'asins' => $asins,
                'mode' => $mode
            ];
        }
    }
    
    /**
     * Get cached keywords + analysis per SKU
     */
    private function getCachedKeywords(string $sku): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT keywords, analysis, competitor_asins, extraction_method, created_at
            FROM ai_keyword_cache
            WHERE user_id = ? AND sku = ? AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$this->userId, $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$row) return null;
        
        $keywords = json_decode($row['keywords'], true);
        
        return [
            'keywords' => $keywords,
            'analysis' => $row['analysis'] ?? '', // NEW
            'count' => count($keywords),
            'asins' => json_decode($row['competitor_asins'], true) ?? [],
            'method' => $row['extraction_method'],
            'created_at' => $row['created_at']
        ];
    }
    
    /**
     * Save keywords to cache
     */
    public function saveKeywordsCache(string $sku, array $keywords, array $asins, string $mode): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO ai_keyword_cache 
            (user_id, sku, keywords, competitor_asins, extraction_method, expires_at)
            VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
            ON DUPLICATE KEY UPDATE
                keywords = VALUES(keywords),
                competitor_asins = VALUES(competitor_asins),
                extraction_method = VALUES(extraction_method),
                created_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute([
            $this->userId,
            $sku,
            json_encode($keywords),
            json_encode($asins),
            $mode
        ]);
    }
    
    /**
     * Clear cache per SKU
     */
    public function clearCache(string $sku): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM ai_keyword_cache
            WHERE user_id = ? AND sku = ?
        ");
        $stmt->execute([$this->userId, $sku]);
    }
    
    /**
     * Time ago formatter
     */
    private function timeAgo(string $datetime): string
    {
        $time = strtotime($datetime);
        $diff = time() - $time;
        
        if ($diff < 60) return 'pochi secondi fa';
        if ($diff < 3600) return floor($diff / 60) . ' minuti fa';
        if ($diff < 86400) return floor($diff / 3600) . ' ore fa';
        return floor($diff / 86400) . ' giorni fa';
    }
}

