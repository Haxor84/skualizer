<?php
require_once __DIR__ . '/PolicyManager.php';
require_once __DIR__ . '/PromptBuilder.php';
require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/ContentValidator.php';

/**
 * AiContentGenerator
 * Orchestrator principale - coordina tutti i componenti
 */
class AiContentGenerator
{
    private $config;
    private $policyManager;
    private $promptBuilder;
    private $llmClient;
    private $validator;
    private $userId;

    public function __construct($config, $userId)
    {
        $this->config = $config;
        $this->userId = $userId;
        
        // Inizializza componenti
        $policyFile = $config['paths']['policy_file'] ?? __DIR__ . '/../config/amazon_policy.json';
        $this->policyManager = new PolicyManager($policyFile);
        $this->promptBuilder = new PromptBuilder($this->policyManager);
        $this->llmClient = new LlmClient($config);
        $this->validator = new ContentValidator($this->policyManager);
    }

    /**
     * Genera contenuto per campo con auto-retry se brand sbagliato
     * 
     * @param string $fieldName Nome campo da generare
     * @param array $context Contesto prodotto
     * @param int $attempt Numero tentativo (1 o 2)
     * @return array ['success', 'content', 'validation']
     */
    public function generateField($fieldName, $context, $attempt = 1)
    {
        try {
            CentralLogger::info('ai_content_generator', 'Generation started', [
                'user_id' => $this->userId,
                'field' => $fieldName,
                'attempt' => $attempt,
                'context_keys' => array_keys($context)
            ]);
            
            // 1. Verifica se campo ha policy
            if (!$this->policyManager->hasPolicy($fieldName)) {
                CentralLogger::warning('ai_content_generator', 'No policy for field', [
                    'user_id' => $this->userId,
                    'field' => $fieldName
                ]);
            }
            
            // 2. Costruisci prompt
            $prompt = $this->promptBuilder->buildPrompt($fieldName, $context);
            
            CentralLogger::debug('ai_content_generator', 'Prompt built', [
                'user_id' => $this->userId,
                'field' => $fieldName,
                'prompt_length' => strlen($prompt),
                'brand_enforcement' => !empty($context['_brand_enforcement'])
            ]);
            
            // 3. Chiama LLM
            $maxTokens = $this->getMaxTokensForField($fieldName);
            $content = $this->llmClient->generate($prompt, $maxTokens);
            
            CentralLogger::info('ai_content_generator', 'Content generated', [
                'user_id' => $this->userId,
                'field' => $fieldName,
                'raw_length' => strlen($content)
            ]);
            
            // 4. Pulisci contenuto, auto-fix titolo se necessario, auto-trim
            $content = $this->cleanContent($content);
            
            // Auto-fix titolo mobile-first se necessario
            if ($fieldName === 'item_name') {
                $content = $this->autoFixTitleStructure($content, $context);
            }
            
            $content = $this->autoTrimIfNeeded($fieldName, $content);
            
            // 5. Valida
            $validation = $this->validator->validate($fieldName, $content);
            
            // 5b. Validazione brand (per title)
            $brandValidation = $this->validateBrandPreservation($fieldName, $content, $context);
            if (!$brandValidation['valid']) {
                // Se brand sbagliato e primo tentativo, RIGENERARE
                if ($attempt === 1 && $fieldName === 'item_name') {
                    CentralLogger::warning('ai_content_generator', 'Brand wrong, retrying...', [
                        'user_id' => $this->userId,
                        'expected_brand' => $context['brand'],
                        'generated_content' => substr($content, 0, 100),
                        'attempt' => $attempt
                    ]);
                    
                    // Aggiungi enforcement più forte al context
                    $context['_brand_enforcement'] = true;
                    
                    // RETRY una volta
                    return $this->generateField($fieldName, $context, 2);
                }
                
                // Se secondo tentativo fallito, aggiungi warning
                if (isset($brandValidation['error'])) {
                    $validation['errors'][] = $brandValidation['error'];
                    $validation['valid'] = false;
                } elseif (isset($brandValidation['warning'])) {
                    $validation['warnings'][] = $brandValidation['warning'];
                }
            }
            
            // 5c. RETRY se lunghezza non rispettata (max 2 tentativi)
            if (!$validation['valid'] && $attempt < 2) {
                $hasLengthError = false;
                foreach ($validation['errors'] as $error) {
                    if (stripos($error, 'lungo') !== false || stripos($error, 'corto') !== false) {
                        $hasLengthError = true;
                        break;
                    }
                }
                
                if ($hasLengthError) {
                    $policy = $this->policyManager->getPolicyForField($fieldName);
                    
                    CentralLogger::warning('ai_content_generator', 'Length error, retrying', [
                        'user_id' => $this->userId,
                        'field' => $fieldName,
                        'attempt' => $attempt,
                        'current_length' => $validation['length'] ?? 0,
                        'min_required' => $policy['min_length'] ?? 0,
                        'max_required' => $policy['max_length'] ?? 0
                    ]);
                    
                    // Aggiungi enforcement lunghezza al context
                    $context['_length_retry'] = true;
                    $context['_current_length'] = $validation['length'] ?? 0;
                    
                    return $this->generateField($fieldName, $context, $attempt + 1);
                }
            }
            
            // 6. Log risultato
            CentralLogger::info('ai_content_generator', 'Field generated', [
                'user_id' => $this->userId,
                'field' => $fieldName,
                'attempt' => $attempt,
                'length' => strlen($content),
                'valid' => $validation['valid'],
                'brand_validation' => $brandValidation['valid'],
                'errors_count' => count($validation['errors']),
                'warnings_count' => count($validation['warnings'])
            ]);
            
            return [
                'success' => true,
                'content' => $content,
                'validation' => $validation,
                'provider' => $this->llmClient->getProviderInfo()['provider'],
                'model' => $this->llmClient->getProviderInfo()['model'],
                'attempt' => $attempt
            ];
            
        } catch (Exception $e) {
            CentralLogger::error('ai_content_generator', 'Generation failed', [
                'user_id' => $this->userId,
                'field' => $fieldName,
                'attempt' => $attempt,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Valida che il brand sia preservato nel contenuto generato
     */
    private function validateBrandPreservation($fieldName, $content, $context)
    {
        // Solo per title
        if ($fieldName !== 'item_name') {
            return ['valid' => true];
        }
        
        $expectedBrand = $context['brand'] ?? '';
        if (empty($expectedBrand)) {
            return ['valid' => true]; // No brand to validate
        }
        
        // Check se brand è presente nel contenuto
        $brandPresent = stripos($content, $expectedBrand) !== false;
        
        if (!$brandPresent) {
            return [
                'valid' => false,
                'error' => "Brand '{$expectedBrand}' non trovato nel titolo generato"
            ];
        }
        
        // Check se brand è all'inizio (primi 50 caratteri)
        $contentStart = substr($content, 0, 50);
        $brandAtStart = stripos($contentStart, $expectedBrand) !== false;
        
        if (!$brandAtStart) {
            return [
                'valid' => false,
                'warning' => "Brand dovrebbe essere all'inizio del titolo"
            ];
        }
        
        return ['valid' => true];
    }

    /**
     * Genera MULTIPLI campi in modo coordinato
     * Mantiene coerenza narrativa e NO ripetizioni
     */
    public function generateMultipleFields($fieldNames, $context)
    {
        try {
            // Log operazione
            CentralLogger::info('ai_content_generator', 'Multi-field generation started', [
                'user_id' => $this->userId,
                'fields' => $fieldNames,
                'sku' => $context['sku'] ?? 'N/D'
            ]);
            
            // 1. Costruisci prompt UNICO per tutti i campi
            $prompt = $this->promptBuilder->buildMultiFieldPrompt($fieldNames, $context);
            
            CentralLogger::debug('ai_content_generator', 'Multi-field prompt built', [
                'user_id' => $this->userId,
                'prompt_length' => strlen($prompt),
                'fields_count' => count($fieldNames)
            ]);
            
            // 2. Calcola tokens necessari (con target alto per lunghezze complete)
            // Target chars: Title(175) + Desc(1000) + 5*Bullets(220) + Keywords(165) = ~2,440 chars
            // Conversione: 2440 chars / 3.5 = ~697 tokens output
            // + 50% margine + 300 formattazione = ~1,350 tokens
            $calculatedTokens = $this->calculateRequiredTokens($fieldNames);
            // Usa MASSIMO disponibile (no limiti artificiali)
            $maxTokens = 16384; // Ampio per Gemini 3 thinking (usa molti tokens)
            CentralLogger::info('ai_content_generator', 'Token allocation', [
                'user_id' => $this->userId,
                'fields_count' => count($fieldNames),
                'calculated_tokens' => $calculatedTokens,
                'using_max_tokens' => $maxTokens,
                'model' => $this->config['default_llm_model'] ?? 'unknown'
            ]);
            $content = $this->llmClient->generate($prompt, $maxTokens);
            
            // Retrieve thinking if available (Extended Thinking feature)
            $thinking = $this->llmClient->getLastThinking();
            
            // 🔍 DEBUG: Log risposta raw per capire cosa torna
            CentralLogger::info('ai_content_generator', 'Multi-field RAW response', [
                'user_id' => $this->userId,
                'response_length' => strlen($content),
                'response_preview' => substr($content, 0, 500),
                'response_end' => substr($content, -200),
                'fields_requested' => $fieldNames,
                'has_thinking' => !empty($thinking),
                'thinking_length' => $thinking ? strlen($thinking) : 0
            ]);
            
            // 3. Parse risposta per estrarre singoli campi
            $parsedFields = $this->parseMultiFieldResponse($content, $fieldNames);
            
            // 🔍 DEBUG: Log parsing results
            CentralLogger::info('ai_content_generator', 'Parsed fields result', [
                'user_id' => $this->userId,
                'fields_found' => array_keys($parsedFields),
                'fields_lengths' => array_map('strlen', $parsedFields)
            ]);
            
            // 4. Valida ogni campo
            $results = [];
            $hasLengthErrors = false;
            foreach ($fieldNames as $fieldName) {
                $fieldContent = $parsedFields[$fieldName] ?? '';
                
                if (empty($fieldContent)) {
                    $results[$fieldName] = [
                        'success' => false,
                        'error' => 'Campo non generato correttamente'
                    ];
                    continue;
                }
                
                // Pulisci, auto-fix titolo, auto-trim, e valida
                $fieldContent = $this->cleanContent($fieldContent);
                
                // Auto-fix titolo mobile-first se necessario
                if ($fieldName === 'item_name') {
                    $fieldContent = $this->autoFixTitleStructure($fieldContent, $context);
                }
                
                $fieldContent = $this->autoTrimIfNeeded($fieldName, $fieldContent);
                $validation = $this->validator->validate($fieldName, $fieldContent);
                
                // Check se errore di lunghezza
                foreach ($validation['errors'] ?? [] as $error) {
                    if (strpos($error, 'corto') !== false || strpos($error, 'lungo') !== false) {
                        $hasLengthErrors = true;
                        break;
                    }
                }
                
                $results[$fieldName] = [
                    'success' => $validation['valid'],
                    'content' => $fieldContent,
                    'validation' => $validation
                ];
            }
            
            // 5. AVVISO se troppi campi con errori di lunghezza
            if ($hasLengthErrors) {
                $shortFields = [];
                foreach ($results as $fname => $result) {
                    foreach ($result['validation']['errors'] ?? [] as $error) {
                        if (strpos($error, 'corto') !== false) {
                            $shortFields[] = $fname . ' (' . ($result['validation']['length'] ?? 0) . ' chars)';
                        }
                    }
                }
                
                CentralLogger::warning('ai_content_generator', 'Length errors detected', [
                    'user_id' => $this->userId,
                    'short_fields' => $shortFields,
                    'will_add_warning' => true
                ]);
                
                // Non retry automatico, ma aggiungi warning chiaro
                $results['_length_warning'] = [
                    'message' => 'Alcuni campi sono troppo corti. Considera di rigenerare.',
                    'short_fields' => $shortFields
                ];
            }
            
            // 6. Verifica keyword overlap
            $overlapCheck = $this->checkKeywordOverlap($results);
            
            // ══════════════════════════════════════════════════════════
            // 7. AUTO-REPAIR SE NECESSARIO (Self-Repair Loop)
            // ══════════════════════════════════════════════════════════
            
            $wasRepaired = false;
            
            if ($this->config['enable_self_repair'] ?? false) {
                // Raccogli campi con errori gravi (non solo warnings)
                $needsRepair = false;
                $repairInstructions = [];
                
                foreach ($results as $fname => $result) {
                    if (strpos($fname, '_') === 0) continue; // Skip special fields
                    
                    if (!empty($result['validation']['errors'])) {
                        $needsRepair = true;
                        $errorList = implode('; ', $result['validation']['errors']);
                        $repairInstructions[] = "• {$fname}: {$errorList}";
                    }
                }
                
                // Se ci sono errori, tenta auto-correzione
                if ($needsRepair && !empty($repairInstructions)) {
                    CentralLogger::info('ai_content_generator', 'Starting self-repair', [
                        'user_id' => $this->userId,
                        'errors_count' => count($repairInstructions)
                    ]);
                    
                    $repairedResults = $this->attemptSelfRepair($results, $repairInstructions, $fieldNames, $context);
                    
                    if ($repairedResults !== null) {
                        $results = $repairedResults;
                        $wasRepaired = true;
                        
                        // Re-check overlap dopo repair
                        $overlapCheck = $this->checkKeywordOverlap($results);
                    }
                }
            }
            
            CentralLogger::info('ai_content_generator', 'Multi-field generation completed', [
                'user_id' => $this->userId,
                'fields_generated' => count(array_filter($results, fn($r) => isset($r['success']) && $r['success'])),
                'has_overlap' => $overlapCheck['has_overlap'],
                'was_repaired' => $wasRepaired
            ]);
            
            return [
                'success' => true,
                'fields' => $results,
                'overlap_analysis' => $overlapCheck,
                'thinking' => $thinking, // Extended Thinking process (if available)
                'was_repaired' => $wasRepaired,
                'provider' => $this->llmClient->getProviderInfo()['provider'],
                'model' => $this->llmClient->getProviderInfo()['model']
            ];
            
        } catch (Exception $e) {
            CentralLogger::error('ai_content_generator', 'Multi-field generation failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Parse risposta AI per estrarre campi singoli
     */
    private function parseMultiFieldResponse($content, $fieldNames)
    {
        $fields = [];
        
        // Log contenuto per debug
        CentralLogger::info('ai_content_generator', 'Parsing multi-field response', [
            'user_id' => $this->userId,
            'content_length' => strlen($content),
            'first_200_chars' => substr($content, 0, 200)
        ]);
        
        // Pattern: [FIELD_NAME]...[/FIELD_NAME]
        foreach ($fieldNames as $fieldName) {
            $tagName = strtoupper($fieldName);
            
            // Pattern con flag DOTALL per match multilinea
            $pattern = '/\[' . preg_quote($tagName, '/') . '\](.*?)\[\/' . preg_quote($tagName, '/') . '\]/s';
            
            if (preg_match($pattern, $content, $matches)) {
                $extracted = trim($matches[1]);
                $fields[$fieldName] = $extracted;
                
                CentralLogger::info('ai_content_generator', "Field extracted: $fieldName", [
                    'user_id' => $this->userId,
                    'length' => strlen($extracted),
                    'preview' => substr($extracted, 0, 100)
                ]);
            } else {
                CentralLogger::warning('ai_content_generator', "Field NOT found: $fieldName", [
                    'user_id' => $this->userId,
                    'pattern' => $pattern,
                    'searched_in' => substr($content, 0, 300)
                ]);
            }
        }
        
        // Se nessun campo trovato con tag, prova parsing alternativo
        if (empty($fields)) {
            CentralLogger::warning('ai_content_generator', 'No fields found with tags, attempting fallback parsing', [
                'user_id' => $this->userId
            ]);
            
            $fields = $this->fallbackParsing($content, $fieldNames);
        }
        
        return $fields;
    }
    
    /**
     * Parsing fallback se l'AI non usa i tag
     */
    private function fallbackParsing($content, $fieldNames)
    {
        $fields = [];
        
        // Prova a splittare per separatori comuni (doppio a capo)
        $sections = preg_split('/\n\n+/', $content);
        
        // Se abbiamo tante sezioni quanti campi richiesti, assegna in ordine
        if (count($sections) === count($fieldNames)) {
            foreach ($fieldNames as $i => $fieldName) {
                $fields[$fieldName] = trim($sections[$i]);
            }
            
            CentralLogger::info('ai_content_generator', 'Fallback parsing succeeded', [
                'user_id' => $this->userId,
                'sections_found' => count($sections)
            ]);
        }
        
        return $fields;
    }
    
    /**
     * Verifica overlap keywords tra campi
     */
    private function checkKeywordOverlap($results)
    {
        $allWords = [];
        $overlap = [];
        
        foreach ($results as $fieldName => $result) {
            // Salta elementi speciali (iniziano con _) e campi falliti
            if (strpos($fieldName, '_') === 0) continue;
            if (!isset($result['success']) || !$result['success']) continue;
            
            $content = $result['content'];
            $words = str_word_count(strtolower(strip_tags($content)), 1, 'àèéìòù');
            
            // Stopwords italiane + parole marketing comuni legittime (OK se ripetute)
            $stopwords = [
                // Articoli, preposizioni, congiunzioni
                'della', 'delle', 'dello', 'degli', 'alla', 'alle', 'allo',
                'nella', 'nelle', 'nello', 'questa', 'questo', 'questi',
                'quella', 'quello', 'quelli', 'sono', 'siamo', 'siete',
                'essere', 'avere', 'fare', 'dire', 'andare', 'potere',
                'volere', 'dovere', 'sapere', 'dare', 'stare', 'vedere',
                'molto', 'poco', 'tanto', 'troppo', 'più', 'meno',
                'anche', 'ancora', 'sempre', 'mai', 'già', 'ora',
                'dopo', 'prima', 'mentre', 'quando', 'dove', 'come',
                // Parole marketing comuni (OK se ripetute)
                'naturale', 'premium', 'qualità', 'ideale', 'perfetto',
                'italiano', 'italiana', 'artigianale', 'certificato',
                'originale', 'autentico', 'eccellente', 'superiore',
                'garantito', 'professionale', 'esclusivo', 'unico',
                'tradizionale', 'moderno', 'innovativo', 'caratteri'
            ];
            
            $words = array_filter($words, function($w) use ($stopwords) {
                return strlen($w) > 3 && !in_array(strtolower($w), $stopwords);
            });
            
            foreach ($words as $word) {
                if (!isset($allWords[$word])) {
                    $allWords[$word] = [];
                }
                $allWords[$word][] = $fieldName;
            }
        }
        
        // Trova duplicati (ripetuti in 3+ campi)
        foreach ($allWords as $word => $fields) {
            $uniqueFields = array_unique($fields);
            if (count($uniqueFields) > 2) { // Ripetuta in 3+ campi DIVERSI
                $overlap[] = [
                    'word' => $word,
                    'fields' => $uniqueFields,
                    'count' => count($uniqueFields)
                ];
            }
        }
        
        return [
            'has_overlap' => !empty($overlap),
            'duplicates' => $overlap,
            'total_unique_words' => count($allWords)
        ];
    }

    /**
     * Pulizia contenuto generato
     */
    private function cleanContent($content)
    {
        // Rimuovi eventuali wrapper markdown
        $content = preg_replace('/```.*?```/s', '', $content);
        $content = preg_replace('/`([^`]+)`/', '$1', $content);
        
        // Rimuovi preamble comuni
        $content = preg_replace('/^(Ecco|Here is|Here\'s|Ecco il|Ecco la).*?:/i', '', $content);
        $content = preg_replace('/^(Il|La|Lo)\s+(titolo|descrizione|bullet point).*?:/i', '', $content);
        
        // Rimuovi virgolette esterne
        $content = trim($content, '"\'');
        
        // Normalizza whitespace
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // Ripristina <br> se presente (non devono diventare spazi)
        $content = str_replace(' <br> ', '<br>', $content);
        $content = str_replace(' <br/> ', '<br/>', $content);

        return $content;
    }
    
    /**
     * Auto-fix struttura titolo mobile-first se non rispetta template
     */
    private function autoFixTitleStructure($content, $context)
    {
        $brand = $context['brand'] ?? '';
        $format = $context['weight'] ?? '';
        
        if (!$brand) return $content;
        
        // Check 1: Brand all'inizio
        if (stripos($content, $brand) !== 0) {
            // Rimuovi brand da dove si trova
            $content = preg_replace('/' . preg_quote($brand, '/') . '\s*/i', '', $content);
            
            // Metti brand all'inizio
            $content = $brand . ' - ' . ltrim($content, '- ');
            
            CentralLogger::info('ai_content_generator', 'Auto-fix: brand moved to beginning', [
                'user_id' => $this->userId
            ]);
        }
        
        // Check 2: Formato dopo posizione 50
        if ($format && strpos($content, $format) > 50) {
            // Estrai nome prodotto (dopo brand fino a primo pipe)
            if (preg_match('/^' . preg_quote($brand, '/') . '\s*-?\s*([^|]+)/i', $content, $matches)) {
                $productPart = trim($matches[1]);
                
                // Rimuovi formato da dove si trova
                $productPart = str_replace($format, '', $productPart);
                $productPart = preg_replace('/\s+/', ' ', trim($productPart));
                
                // Ricostruisci: Brand - Prodotto Formato | Resto
                if (preg_match('/\|(.+)$/', $content, $restMatches)) {
                    $rest = trim($restMatches[1]);
                    $content = "$brand - $productPart $format | $rest";
                } else {
                    $content = "$brand - $productPart $format";
                }
                
                CentralLogger::info('ai_content_generator', 'Auto-fix: format moved to first 50 chars', [
                    'user_id' => $this->userId,
                    'new_format_position' => strpos($content, $format)
                ]);
            }
        }
        
        return $content;
    }
    
    /**
     * Tronca contenuto se leggermente sopra limite
     */
    private function autoTrimIfNeeded($fieldName, $content)
    {
        $policy = $this->policyManager->getPolicyForField($fieldName);
        if (!$policy || !isset($policy['max_length'])) {
            return $content;
        }
        
        $maxChars = $policy['max_length'];
        $currentLength = strlen($content);
        
        // Se sopra di max 10 chars, tronca intelligentemente
        if ($currentLength > $maxChars && $currentLength <= $maxChars + 10) {
            // Trova ultimo spazio prima del limite
            $truncated = substr($content, 0, $maxChars);
            $lastSpace = strrpos($truncated, ' ');
            
            if ($lastSpace !== false && $lastSpace > $maxChars * 0.9) {
                CentralLogger::info('ai_content_generator', 'Auto-trim applied', [
                    'user_id' => $this->userId,
                    'field' => $fieldName,
                    'original_length' => $currentLength,
                    'trimmed_length' => $lastSpace
                ]);
                return substr($content, 0, $lastSpace);
            }
        }
        
        return $content;
    }

    /**
     * Ottieni max tokens per tipo di campo
     */
    private function getMaxTokensForField($fieldName)
    {
        $limits = $this->policyManager->getCharLimits($fieldName);
        
        // Stima: 1 token ≈ 4 caratteri, aggiungi buffer generoso
        // Gemini 3 usa MOLTI tokens per thinking, quindi 3x buffer
        if (isset($limits['max'])) {
            return (int) ceil($limits['max'] / 4 * 3); // 3x per Gemini 3 thinking
        }
        
        // Defaults per tipo di campo (3x per Gemini 3 thinking)
        $defaults = [
            'item_name' => 1000,           // 350 * 3 per Gemini 3
            'product_description' => 6000, // 2000 * 3 per Gemini 3
            'bullet_point1' => 1200,       // 400 * 3
            'bullet_point2' => 1200,
            'bullet_point3' => 1200,
            'bullet_point4' => 1200,
            'bullet_point5' => 1200,
            'generic_keywords' => 600      // 200 * 3
        ];
        
        return $defaults[$fieldName] ?? 1500;
    }

    /**
     * Test configurazione
     */
    public function testConfiguration()
    {
        $results = [];
        
        // Test policy file
        try {
            $policies = $this->policyManager->getAllPolicies();
            $results['policy_file'] = [
                'success' => true,
                'fields_count' => count($policies['fields'] ?? [])
            ];
        } catch (Exception $e) {
            $results['policy_file'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Test LLM connection
        $results['llm_connection'] = $this->llmClient->testConnection();
        
        // Test prompt builder
        try {
            $testPrompt = $this->promptBuilder->buildPrompt('item_name', [
                'item_sku' => 'TEST-001',
                'brand_name' => 'Test Brand',
                'keywords' => ['test', 'keyword']
            ]);
            
            $results['prompt_builder'] = [
                'success' => true,
                'prompt_length' => strlen($testPrompt)
            ];
        } catch (Exception $e) {
            $results['prompt_builder'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Test validator
        try {
            $testValidation = $this->validator->validate('item_name', 'Test Product Title');
            
            $results['content_validator'] = [
                'success' => true,
                'test_validation' => $testValidation
            ];
        } catch (Exception $e) {
            $results['content_validator'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
        
        // Summary
        $allSuccess = true;
        foreach ($results as $component => $result) {
            if (!$result['success']) {
                $allSuccess = false;
                break;
            }
        }
        
        return [
            'all_success' => $allSuccess,
            'components' => $results,
            'provider_info' => $this->llmClient->getProviderInfo()
        ];
    }

    /**
     * Ottieni policy per campo (espone PolicyManager)
     */
    public function getPolicyForField($fieldName)
    {
        return $this->policyManager->getPolicyForField($fieldName);
    }

    /**
     * Valida contenuto (espone ContentValidator)
     */
    public function validateContent($fieldName, $content)
    {
        return $this->validator->validate($fieldName, $content);
    }
    
    /**
     * Calcola tokens necessari basato su target caratteri
     */
    private function calculateRequiredTokens($fieldNames)
    {
        // Target caratteri per campo (ideali per qualità)
        $charTargets = [
            'item_name' => 175,
            'product_description' => 1000,
            'bullet_point1' => 220,
            'bullet_point2' => 220,
            'bullet_point3' => 220,
            'bullet_point4' => 220,
            'bullet_point5' => 220,
            'generic_keywords' => 165,
        ];
        
        $totalChars = 0;
        foreach ($fieldNames as $fieldName) {
            $totalChars += $charTargets[$fieldName] ?? 200;
        }
        
        // Conversione chars → tokens (3.5 chars per token in italiano)
        $tokens = (int)($totalChars / 3.5);
        
        // Aggiungi 50% margine + 300 per tag XML e formattazione
        $tokens = (int)($tokens * 1.5) + 300;
        
        return $tokens;
    }
    
    /**
     * Tenta auto-correzione guidata per campi con errori
     */
    private function attemptSelfRepair($results, $repairInstructions, $fieldNames, $context)
    {
        try {
            // 1. Costruisci prompt di correzione
            $repairPrompt = $this->buildRepairPrompt($results, $repairInstructions, $fieldNames, $context);
            
            CentralLogger::debug('ai_content_generator', 'Repair prompt built', [
                'user_id' => $this->userId,
                'repair_prompt_length' => strlen($repairPrompt)
            ]);
            
            // 2. Chiamata LLM per correzione
            $maxTokens = 16384; // Ampio per Gemini 3
            $repairedContent = $this->llmClient->generate($repairPrompt, $maxTokens);
            
            CentralLogger::info('ai_content_generator', 'Repair generation completed', [
                'user_id' => $this->userId,
                'repaired_response_length' => strlen($repairedContent)
            ]);
            
            // 3. Parse risposta riparata
            $repairedFields = $this->parseMultiFieldResponse($repairedContent, $fieldNames);
            
            // 4. Sovrascrivi solo i campi che avevano errori
            foreach ($fieldNames as $fieldName) {
                // Solo campi falliti vengono sovrascritti
                if ($results[$fieldName]['success'] === false && isset($repairedFields[$fieldName])) {
                    $repairedFieldContent = $this->cleanContent($repairedFields[$fieldName]);
                    
                    // Auto-fix titolo se necessario
                    if ($fieldName === 'item_name') {
                        $repairedFieldContent = $this->autoFixTitleStructure($repairedFieldContent, $context);
                    }
                    
                    // Auto-trim
                    $repairedFieldContent = $this->autoTrimIfNeeded($fieldName, $repairedFieldContent);
                    
                    // Ri-valida
                    $revalidation = $this->validator->validate($fieldName, $repairedFieldContent);
                    
                    $results[$fieldName] = [
                        'success' => $revalidation['valid'],
                        'content' => $repairedFieldContent,
                        'validation' => $revalidation,
                        'repaired' => true
                    ];
                    
                    CentralLogger::info('ai_content_generator', "Field repaired: {$fieldName}", [
                        'user_id' => $this->userId,
                        'repaired_valid' => $revalidation['valid'],
                        'repaired_length' => strlen($repairedFieldContent)
                    ]);
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            CentralLogger::error('ai_content_generator', 'Self-repair failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            
            // Ritorna results originali se repair fallisce
            return null;
        }
    }
    
    /**
     * Costruisce prompt per auto-correzione guidata dagli errori
     */
    private function buildRepairPrompt($results, $repairInstructions, $fieldNames, $context)
    {
        $prompt = "═══════════════════════════════════════════════════════════\n";
        $prompt .= "🔧 AUTO-CORREZIONE CONTENUTI GUIDATA\n";
        $prompt .= "═══════════════════════════════════════════════════════════\n\n";
        
        $prompt .= "Hai generato dei contenuti che presentano ALCUNI ERRORI.\n";
        $prompt .= "Il tuo compito è CORREGGERE solo i campi problematici.\n";
        $prompt .= "NON riscrivere i campi già corretti.\n\n";
        
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "⚠️ ERRORI DA CORREGGERE:\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($repairInstructions as $instruction) {
            $prompt .= $instruction . "\n";
        }
        
        $prompt .= "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "📋 CONTENUTI ORIGINALI (da correggere):\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        foreach ($results as $fieldName => $data) {
            if (strpos($fieldName, '_') === 0) continue; // Skip special fields
            
            $prompt .= "[" . strtoupper($fieldName) . "]\n";
            $prompt .= $data['content'] ?: '(VUOTO - da generare)';
            $prompt .= "\n[/" . strtoupper($fieldName) . "]\n\n";
        }
        
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "🎯 ISTRUZIONI CORREZIONE:\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        
        $prompt .= "1. CAMPO TROPPO LUNGO:\n";
        $prompt .= "   • Riduci accorciando frasi, NON tagliando concetti\n";
        $prompt .= "   • Usa sinonimi più brevi\n";
        $prompt .= "   • Elimina ridondanze\n";
        $prompt .= "   • Esempio: 'accuratamente selezionato con cura' → 'selezionato con cura'\n\n";
        
        $prompt .= "2. CAMPO TROPPO CORTO:\n";
        $prompt .= "   • Aggiungi dettagli specifici, numeri, esempi concreti\n";
        $prompt .= "   • NON ripetere concetti già presenti\n";
        $prompt .= "   • Esempio: 'Prodotto premium' → 'Prodotto premium realizzato con ingredienti selezionati'\n\n";
        
        $prompt .= "3. PAROLA VIETATA - USA IL CONTESTO:\n";
        $prompt .= "   ✅ 'cura' in 'accuratamente' = OK (parte della parola)\n";
        $prompt .= "   ✅ 'premium', 'naturale', 'qualità' = OK (descrittori generici)\n";
        $prompt .= "   ❌ 'cura il cancro' = VIETATO (claim medico)\n";
        $prompt .= "   ❌ 'guarisce malattie' = VIETATO (claim medico)\n";
        $prompt .= "   ❌ 'garanzia 100%' = VIETATO (claim assoluto)\n";
        $prompt .= "   ❌ 'migliore' / 'best' / '#1' = VIETATO (claim comparativo)\n";
        $prompt .= "   \n   Se trovi claim vietato, RIFORMULA:\n";
        $prompt .= "   • 'cura lo stress' → 'supporta il benessere'\n";
        $prompt .= "   • 'migliore sul mercato' → 'qualità premium'\n";
        $prompt .= "   • 'garanzia 100%' → 'standard elevati di qualità'\n\n";
        
        $prompt .= "4. TITOLO - FORMATO MOBILE-FIRST:\n";
        $prompt .= "   • STRUTTURA: Brand - Prodotto Formato | Caratteristiche\n";
        $prompt .= "   • Formato DEVE essere entro 50 caratteri dall'inizio\n";
        $prompt .= "   • Esempio: 'Valsapori - Granella di Pistacchio 100g | Puro Crudo'\n";
        $prompt .= "   • NO parentesi: usa '100g' non '(100g)'\n\n";
        
        $prompt .= "5. MANTIENI:\n";
        $prompt .= "   • STESSO significato e STESSO tono\n";
        $prompt .= "   • Stile marketing emozionale\n";
        $prompt .= "   • Keywords strategiche\n\n";
        
        $prompt .= "⚠️ IMPORTANTE:\n";
        $prompt .= "Genera TUTTI i campi usando i tag XML:\n";
        $prompt .= "[FIELD_NAME]contenuto corretto[/FIELD_NAME]\n\n";
        
        $prompt .= "INIZIA LA CORREZIONE ORA:\n\n";
        
        return $prompt;
    }
}

