<?php
/**
 * WorkflowEngine
 * Orchestratore pipeline multi-step per AI content generation
 * 
 * Responsabilità:
 * - Coordina esecuzione sequenziale step
 * - Gestisce context globale workflow
 * - Error handling e logging
 * - Instanzia step con LLM clients specifici
 */
class WorkflowEngine
{
    private PDO $pdo;
    private int $userId;
    private array $config;
    private array $aiConfig;
    private array $context = [];
    private array $results = [];
    
    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param int $userId User ID per logging e metrics
     */
    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        
        // Load configurations
        $this->config = require __DIR__ . '/../config/workflow_config.php';
        $this->aiConfig = require __DIR__ . '/../config/ai_config.php';
        
        CentralLogger::info('workflow_engine', 'Engine initialized', [
            'user_id' => $userId
        ]);
    }
    
    /**
     * Esegue workflow completo per fields richiesti
     * 
     * @param array $fields Field names richiesti (es: ['item_name', 'product_description', 'bullet_point1'])
     * @param array $context Dati prodotto (brand, weight, sku, current_title, etc)
     * @return array Results per field con validation
     * @throws Exception Se workflow critico fallisce
     */
    public function execute(array $fields, array $context): array
    {
        $startTime = microtime(true);
        
        CentralLogger::info('workflow_engine', 'Workflow started', [
            'user_id' => $this->userId,
            'fields_requested' => $fields,
            'context_sku' => $context['sku'] ?? 'N/D'
        ]);
        
        try {
            // Initialize context globale
            $this->context = $context;
            $this->results = [];
            
            // STEP 1: Keyword Research (UNA VOLTA SOLA)
            // Questo step è SEMPRE eseguito perché fornisce keywords per tutti gli altri step
            $this->executeKeywordResearch();
            
            // STEP 2-5: Content Generation per ogni field richiesto
            // ✅ OTTIMIZZAZIONE BULLETS: Genera TUTTI in 1 chiamata invece di 5
            $bulletFields = array_filter($fields, fn($f) => strpos($f, 'bullet_point') === 0);
            $nonBulletFields = array_diff($fields, $bulletFields);
            
            // Genera campi NON-BULLET normalmente
            foreach ($nonBulletFields as $fieldName) {
                try {
                    $result = $this->executeFieldGeneration($fieldName);
                    $this->results[$fieldName] = $result;
                    
                    // Salva content generato in context per step successivi
                    $this->context["generated_{$fieldName}"] = $result['content'] ?? '';
                    
                    CentralLogger::debug('workflow_engine', 'Field generated and saved to context', [
                        'user_id' => $this->userId,
                        'field' => $fieldName,
                        'success' => $result['success'] ?? false,
                        'content_length' => strlen($result['content'] ?? '')
                    ]);
                    
                } catch (Exception $e) {
                    CentralLogger::error('workflow_engine', 'Field generation failed', [
                        'user_id' => $this->userId,
                        'field' => $fieldName,
                        'error' => $e->getMessage()
                    ]);
                    
                    $this->results[$fieldName] = [
                        'content' => '',
                        'success' => false,
                        'validation' => [
                            'valid' => false,
                            'errors' => ['Workflow step failed: ' . $e->getMessage()]
                        ]
                    ];
                }
            }
            
            // ✅ Genera TUTTI i bullets in UNA chiamata (se richiesti)
            if (!empty($bulletFields)) {
                try {
                    CentralLogger::info('workflow_engine', 'Generating ALL bullets in single batch', [
                        'user_id' => $this->userId,
                        'bullet_count' => count($bulletFields)
                    ]);
                    
                    // Crea Step4 SENZA setTargetBullet per generare tutti i bullets
                    $policyFile = $this->aiConfig['paths']['policy_file'] ?? __DIR__ . '/../config/amazon_policy.json';
                    $policyManager = new PolicyManager($policyFile);
                    $stepConfig = $this->config['steps']['bullets_generation'];
                    $llmClient = new LlmClient(
                        $this->aiConfig,
                        $stepConfig['provider'],
                        $stepConfig['model']
                    );
                    require_once __DIR__ . '/steps/Step4_BulletsGeneration.php';
                    $bulletsStep = new Step4_BulletsGeneration($llmClient, $policyManager, $stepConfig);
                    
                    // Inject structured prompts from Step1
                    if (!empty($this->context['structured_prompts']['bullets'])) {
                        $bulletsStep->setStructuredInstructions($this->context['structured_prompts']['bullets']);
                    }
                    
                    // NO setTargetBullet() → genera TUTTI i bullets
                    $bulletsStep->setContext($this->context);
                    $allBulletsResult = $this->executeStep($bulletsStep, $this->context);
                    
                    // Estrai tutti i bullets dal risultato
                    $allBullets = $allBulletsResult['all_bullets'] ?? [];
                    
                    CentralLogger::info('workflow_engine', 'Bullets batch result received', [
                        'user_id' => $this->userId,
                        'all_bullets_count' => count($allBullets),
                        'requested_bullets' => $bulletFields
                    ]);
                    
                    // Salva ogni bullet richiesto nei results
                    foreach ($bulletFields as $bulletField) {
                        if (isset($allBullets[$bulletField])) {
                            $this->results[$bulletField] = $allBullets[$bulletField];
                            $this->context["generated_{$bulletField}"] = $allBullets[$bulletField]['content'] ?? '';
                            
                            CentralLogger::debug('workflow_engine', 'Bullet saved from batch', [
                                'bullet' => $bulletField,
                                'success' => $allBullets[$bulletField]['success'] ?? false,
                                'content_length' => strlen($allBullets[$bulletField]['content'] ?? '')
                            ]);
                        } else {
                            // Fallback se bullet non generato
                            CentralLogger::warning('workflow_engine', 'Bullet missing from batch', [
                                'bullet' => $bulletField
                            ]);
                            
                            $this->results[$bulletField] = [
                                'content' => '',
                                'success' => false,
                                'validation' => ['valid' => false, 'errors' => ['Bullet not in batch result']]
                            ];
                        }
                    }
                    
                    CentralLogger::info('workflow_engine', 'Bullets batch generation completed', [
                        'user_id' => $this->userId,
                        'bullets_saved' => count(array_filter($bulletFields, fn($f) => isset($this->results[$f])))
                    ]);
                    
                } catch (Exception $e) {
                    CentralLogger::error('workflow_engine', 'Bullets batch generation failed', [
                        'user_id' => $this->userId,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Fallback: bullets vuoti
                    foreach ($bulletFields as $bulletField) {
                        $this->results[$bulletField] = [
                            'content' => '',
                            'success' => false,
                            'validation' => ['valid' => false, 'errors' => ['Batch failed: ' . $e->getMessage()]]
                        ];
                    }
                }
            }
            
            $duration = microtime(true) - $startTime;
            
            CentralLogger::info('workflow_engine', 'Workflow completed', [
                'user_id' => $this->userId,
                'fields_generated' => count($this->results),
                'duration_seconds' => round($duration, 2)
            ]);
            
            return $this->results;
            
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            CentralLogger::error('workflow_engine', 'Workflow failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'duration_seconds' => round($duration, 2)
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Esegue Step 1: Keyword Research (PUBBLICO per ConversationalWorkflow)
     * Output salvato in $this->context['keywords']
     */
    public function executeKeywordResearch(): void
    {
        CentralLogger::info('workflow_engine', 'Step 1: Keyword Research starting', [
            'user_id' => $this->userId
        ]);
        
        try {
            // ═══════════════════════════════════════════════════════════
            // CHECK CACHE KEYWORDS + ANALYSIS (90 giorni)
            // ═══════════════════════════════════════════════════════════
            $sku = $this->context['sku'] ?? null;
            if ($sku) {
                $cached = $this->loadKeywordsFromCache($sku);
                if ($cached) {
                    $this->context['keywords'] = $cached['keywords'] ?? [];
                    $this->context['analysis'] = $cached['analysis'] ?? '';
                    $this->context['structured_prompts'] = $cached['structured_prompts'] ?? []; // NEW
                    
                    CentralLogger::info('workflow_engine', 'Using cached keywords + analysis + structured prompts', [
                        'user_id' => $this->userId,
                        'sku' => $sku,
                        'keyword_count' => count($this->context['keywords']),
                        'has_analysis' => !empty($this->context['analysis']),
                        'has_structured_prompts' => !empty($this->context['structured_prompts']) // NEW
                    ]);
                    
                    return; // Skip web research e Step1
                }
            }
            
            // ═══════════════════════════════════════════════════════════
            // SCRAPING COMPETITOR ASIN (se forniti)
            // ═══════════════════════════════════════════════════════════
            if (!empty($this->context['competitor_asins'])) {
                CentralLogger::info('workflow_engine', 'Scraping competitor ASIN', [
                    'user_id' => $this->userId,
                    'asin_count' => count($this->context['competitor_asins'])
                ]);
                
                try {
                    // Usa AiEngine per scraping
                    require_once __DIR__ . '/../core/AiEngine.php';
                    $aiEngine = new AiEngine($this->userId);
                    
                    $competitorData = [];
                    
                    foreach ($this->context['competitor_asins'] as $asin) {
                        CentralLogger::debug('workflow_engine', 'Scraping ASIN', [
                            'user_id' => $this->userId,
                            'asin' => $asin
                        ]);
                        
                        // Scrape singolo ASIN
                        $result = $aiEngine->analyzeCompetitors([$asin], 0, 1);
                        
                        if ($result['success'] && !empty($result['results'])) {
                            $competitorData[] = reset($result['results']); // Prendi primo elemento
                            
                            CentralLogger::debug('workflow_engine', 'ASIN scraped successfully', [
                                'user_id' => $this->userId,
                                'asin' => $asin,
                                'has_title' => !empty(reset($result['results'])['title'])
                            ]);
                        } else {
                            CentralLogger::warning('workflow_engine', 'ASIN scraping failed', [
                                'user_id' => $this->userId,
                                'asin' => $asin,
                                'error' => $result['error'] ?? 'Unknown'
                            ]);
                        }
                        
                        // Rate limiting: sleep 2s tra ASIN
                        if (count($this->context['competitor_asins']) > 1) {
                            sleep(2);
                        }
                    }
                    
                    // Salva competitor data in context
                    $this->context['competitor_data'] = $competitorData;
                    
                    CentralLogger::info('workflow_engine', 'Competitor scraping completed', [
                        'user_id' => $this->userId,
                        'scraped_count' => count($competitorData),
                        'failed_count' => count($this->context['competitor_asins']) - count($competitorData)
                    ]);
                    
                } catch (Exception $e) {
                    CentralLogger::error('workflow_engine', 'Competitor scraping failed', [
                        'user_id' => $this->userId,
                        'error' => $e->getMessage()
                    ]);
                    
                    // Continue senza competitor data (Step1 userà fallback)
                    $this->context['competitor_data'] = [];
                }
            }
            
            // Crea LlmClient per Gemini 3 Pro
            $stepConfig = $this->config['steps']['keyword_research'];
            $llmClient = new LlmClient(
                $this->aiConfig,
                $stepConfig['provider'],
                $stepConfig['model']
            );
            
            // Crea PolicyManager
            $policyFile = $this->aiConfig['paths']['policy_file'] ?? 
                         __DIR__ . '/../config/amazon_policy.json';
            $policyManager = new PolicyManager($policyFile);
            
            // Load step class
            require_once __DIR__ . '/steps/Step1_KeywordResearch.php';
            $step = new Step1_KeywordResearch($llmClient, $policyManager, $stepConfig);
            
            // Set context per validation
            $step->setContext($this->context);
            
            // Execute step
            $result = $this->executeStep($step, $this->context);
            
            // Salva keywords + analysis + structured_prompts in context
            $this->context['keywords'] = $result['content'] ?? [];
            $this->context['analysis'] = $result['analysis'] ?? '';
            $this->context['structured_prompts'] = $result['structured_prompts'] ?? []; // NEW
            
            CentralLogger::info('workflow_engine', 'Step 1: Keywords + Analysis + Structured Prompts extracted', [
                'user_id' => $this->userId,
                'keyword_count' => count($this->context['keywords']),
                'has_analysis' => !empty($this->context['analysis']),
                'analysis_length' => strlen($this->context['analysis']),
                'has_structured_prompts' => !empty($this->context['structured_prompts'])
            ]);
            
            // SALVA IN DATABASE CACHE (keywords + analysis + structured_prompts)
            if (!empty($this->context['keywords']) && $sku) {
                $this->saveKeywordsToCache(
                    $sku,
                    $this->context['keywords'],
                    $this->context['analysis'], // NEW
                    $this->context['structured_prompts'] ?? [], // NEW
                    $this->context['competitor_asins'] ?? [],
                    'web_research_analysis' // NEW method
                );
            }
            
        } catch (Exception $e) {
            CentralLogger::error('workflow_engine', 'Step 1: Failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            
            // Fallback: usa keywords vuote (step successivi useranno fallback)
            $this->context['keywords'] = [];
        }
    }
    
    /**
     * Esegue generazione per field specifico
     * 
     * @param string $fieldName Nome campo (item_name, product_description, etc)
     * @return array Result con content, success, validation
     */
    private function executeFieldGeneration(string $fieldName): array
    {
        CentralLogger::info('workflow_engine', 'Field generation starting', [
            'user_id' => $this->userId,
            'field' => $fieldName
        ]);
        
        // Determina quale step usare
        $step = $this->getStepForField($fieldName);
        
        // Set context per validation
        $step->setContext($this->context);
        
        // Execute step
        $result = $this->executeStep($step, $this->context);
        
        CentralLogger::info('workflow_engine', 'Field generation completed', [
            'user_id' => $this->userId,
            'field' => $fieldName,
            'success' => $result['success'] ?? false
        ]);
        
        return $result;
    }
    
    /**
     * Determina quale step usare per field specifico
     * 
     * @param string $fieldName Nome campo
     * @return WorkflowStep Instance dello step appropriato
     * @throws Exception Se field non supportato
     */
    private function getStepForField(string $fieldName): WorkflowStep
    {
        $policyFile = $this->aiConfig['paths']['policy_file'] ?? 
                     __DIR__ . '/../config/amazon_policy.json';
        $policyManager = new PolicyManager($policyFile);
        
        switch ($fieldName) {
            case 'item_name':
                $stepConfig = $this->config['steps']['title_generation'];
                $llmClient = new LlmClient(
                    $this->aiConfig,
                    $stepConfig['provider'],
                    $stepConfig['model']
                );
                require_once __DIR__ . '/steps/Step2_TitleGeneration.php';
                $step = new Step2_TitleGeneration($llmClient, $policyManager, $stepConfig);
                
                // Inject structured prompts from Step1 (if available)
                if (!empty($this->context['structured_prompts']['title'])) {
                    $step->setStructuredInstructions($this->context['structured_prompts']['title']);
                }
                
                return $step;
                
            case 'product_description':
                $stepConfig = $this->config['steps']['description_generation'];
                $llmClient = new LlmClient(
                    $this->aiConfig,
                    $stepConfig['provider'],
                    $stepConfig['model']
                );
                require_once __DIR__ . '/steps/Step3_DescriptionGen.php';
                $step = new Step3_DescriptionGen($llmClient, $policyManager, $stepConfig);
                
                // Inject structured prompts from Step1 (if available)
                if (!empty($this->context['structured_prompts']['description'])) {
                    $step->setStructuredInstructions($this->context['structured_prompts']['description']);
                }
                
                return $step;
                
            case 'bullet_point1':
            case 'bullet_point2':
            case 'bullet_point3':
            case 'bullet_point4':
            case 'bullet_point5':
                $stepConfig = $this->config['steps']['bullets_generation'];
                $llmClient = new LlmClient(
                    $this->aiConfig,
                    $stepConfig['provider'],
                    $stepConfig['model']
                );
                require_once __DIR__ . '/steps/Step4_BulletsGeneration.php';
                $bulletsStep = new Step4_BulletsGeneration($llmClient, $policyManager, $stepConfig);
                // Passa field name specifico
                $bulletsStep->setTargetBullet($fieldName);
                
                // Inject structured prompts from Step1 (if available)
                if (!empty($this->context['structured_prompts']['bullets'])) {
                    $bulletsStep->setStructuredInstructions($this->context['structured_prompts']['bullets']);
                }
                
                return $bulletsStep;
                
            case 'generic_keywords':
                $stepConfig = $this->config['steps']['hidden_keywords'];
                // No LLM per questo step
                $llmClient = new LlmClient($this->aiConfig); // Dummy, non sarà usato
                require_once __DIR__ . '/steps/Step5_HiddenKeywords.php';
                return new Step5_HiddenKeywords($llmClient, $policyManager, $stepConfig);
                
            default:
                throw new Exception("Unsupported field for workflow: {$fieldName}");
        }
    }
    
    /**
     * Esegue singolo step con error handling
     * 
     * @param WorkflowStep $step Step da eseguire
     * @param array $context Context da passare allo step
     * @return array Result dello step
     */
    private function executeStep(WorkflowStep $step, array $context): array
    {
        $stepName = get_class($step);
        
        try {
            CentralLogger::debug('workflow_engine', 'Step execution starting', [
                'user_id' => $this->userId,
                'step' => $stepName
            ]);
            
            $result = $step->execute($context);
            
            CentralLogger::debug('workflow_engine', 'Step execution completed', [
                'user_id' => $this->userId,
                'step' => $stepName,
                'success' => $result['success'] ?? false
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            CentralLogger::error('workflow_engine', 'Step execution failed, using fallback', [
                'user_id' => $this->userId,
                'step' => $stepName,
                'error' => $e->getMessage()
            ]);
            
            // Usa fallback strategy
            try {
                $fallbackResult = $step->fallback($context);
                
                CentralLogger::info('workflow_engine', 'Fallback successful', [
                    'user_id' => $this->userId,
                    'step' => $stepName
                ]);
                
                return $fallbackResult;
                
            } catch (Exception $fallbackError) {
                CentralLogger::error('workflow_engine', 'Fallback also failed', [
                    'user_id' => $this->userId,
                    'step' => $stepName,
                    'error' => $fallbackError->getMessage()
                ]);
                
                // Return empty result se anche fallback fallisce
                return [
                    'content' => '',
                    'success' => false,
                    'validation' => [
                        'valid' => false,
                        'errors' => ['Step and fallback both failed']
                    ]
                ];
            }
        }
    }
    
    /**
     * Get current context (PUBLIC for ConversationalWorkflow)
     * 
     * @return array Current context with keywords
     */
    public function getContext(): array
    {
        return $this->context;
    }
    
    /**
     * Set context (PUBLIC for ConversationalWorkflow)
     * 
     * @param array $context Context to inject (es: competitor_asins)
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }
    
    /**
     * Salva keywords + analysis in cache database (90 giorni)
     * 
     * @param string $sku SKU prodotto
     * @param array $keywords Lista parole singole uniche
     * @param string $analysis Product analysis (500-800 words)
     * @param array $asins ASIN competitor usati (può essere vuoto per web research)
     * @param string $method Metodo extraction (web_research_analysis | competitor_analysis | ai_generated)
     */
    private function saveKeywordsToCache(string $sku, array $keywords, string $analysis, array $structuredPrompts, array $asins, string $method): void
    {
        try {
            // Converti array keywords in JSON (per mantenere array structure)
            $keywordsJson = json_encode($keywords);
            
            // Converti structured_prompts in JSON
            $structuredPromptsJson = json_encode($structuredPrompts);
            
            // Prepara ASIN JSON
            $asinsJson = json_encode($asins);
            
            // Calcola expiry (90 giorni per analysis-based, più stabile)
            $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
            
            // Insert/Update cache
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_keyword_cache 
                (user_id, sku, keywords, analysis, structured_prompts, competitor_asins, extraction_method, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    keywords = VALUES(keywords),
                    analysis = VALUES(analysis),
                    structured_prompts = VALUES(structured_prompts),
                    competitor_asins = VALUES(competitor_asins),
                    extraction_method = VALUES(extraction_method),
                    created_at = NOW(),
                    expires_at = VALUES(expires_at)
            ");
            
            $stmt->execute([
                $this->userId,
                $sku,
                $keywordsJson,
                $analysis,
                $structuredPromptsJson, // NEW
                $asinsJson,
                $method,
                $expiresAt
            ]);
            
            CentralLogger::info('workflow_engine', 'Keywords + Analysis + Structured Prompts saved to cache', [
                'user_id' => $this->userId,
                'sku' => $sku,
                'keyword_count' => count($keywords),
                'has_analysis' => !empty($analysis),
                'analysis_length' => strlen($analysis),
                'has_structured_prompts' => !empty($structuredPrompts), // NEW
                'method' => $method,
                'expires_at' => $expiresAt
            ]);
            
        } catch (Exception $e) {
            CentralLogger::error('workflow_engine', 'Failed to save keywords cache', [
                'user_id' => $this->userId,
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            // Non bloccare workflow se cache fallisce
        }
    }
    
    /**
     * Carica keywords + analysis da cache se esistono e non sono scadute
     * 
     * @param string $sku SKU prodotto
     * @return array|null {keywords: array, analysis: string} o null se non esistono
     */
    private function loadKeywordsFromCache(string $sku): ?array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT keywords, analysis, structured_prompts, competitor_asins, extraction_method, created_at
                FROM ai_keyword_cache
                WHERE user_id = ? AND sku = ? AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$this->userId, $sku]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) return null;
            
            // Converti JSON → array
            $keywords = json_decode($row['keywords'], true);
            
            if (!is_array($keywords)) return null;
            
            // Extract analysis (può essere NULL per cache vecchie)
            $analysis = $row['analysis'] ?? '';
            
            // Extract structured_prompts (può essere NULL per cache vecchie)
            $structuredPrompts = !empty($row['structured_prompts']) 
                ? json_decode($row['structured_prompts'], true) 
                : [];
            
            CentralLogger::info('workflow_engine', 'Keywords + Analysis + Structured Prompts loaded from cache', [
                'user_id' => $this->userId,
                'sku' => $sku,
                'keyword_count' => count($keywords),
                'has_analysis' => !empty($analysis),
                'analysis_length' => strlen($analysis),
                'has_structured_prompts' => !empty($structuredPrompts), // NEW
                'cache_age_days' => round((time() - strtotime($row['created_at'])) / 86400, 1)
            ]);
            
            return [
                'keywords' => $keywords,
                'analysis' => $analysis,
                'structured_prompts' => $structuredPrompts // NEW
            ];
            
        } catch (Exception $e) {
            CentralLogger::error('workflow_engine', 'Failed to load keywords cache', [
                'user_id' => $this->userId,
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}

