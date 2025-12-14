<?php
/**
 * WorkflowStep (Abstract Base Class)
 * Template per tutti gli step del workflow
 * 
 * Pattern: Template Method
 * Ogni step implementa execute(), fallback(), validate()
 */
abstract class WorkflowStep
{
    protected LlmClient $llmClient;
    protected PolicyManager $policyManager;
    protected array $config;
    protected array $context;
    protected ?string $retryHint = null; // NEW: Hint per retry contestuale
    
    /**
     * Constructor
     * 
     * @param LlmClient $llmClient Client LLM configurato per questo step
     * @param PolicyManager $policyManager Manager policy Amazon
     * @param array $config Configurazione step da workflow_config.php
     */
    public function __construct(LlmClient $llmClient, PolicyManager $policyManager, array $config)
    {
        $this->llmClient = $llmClient;
        $this->policyManager = $policyManager;
        $this->config = $config;
    }
    
    /**
     * Esegue lo step - DEVE essere implementato dalle subclass
     * 
     * @param array $context Context workflow completo
     * @return array ['content' => mixed, 'success' => bool, 'validation' => array]
     */
    abstract public function execute(array $context): array;
    
    /**
     * Strategia fallback se step fallisce dopo tutti i retry
     * 
     * @param array $context Context workflow
     * @return array Stesso formato di execute()
     */
    abstract public function fallback(array $context): array;
    
    /**
     * Valida output dello step
     * 
     * @param mixed $output Output da validare
     * @return bool True se valido
     */
    abstract protected function validate($output): bool;
    
    /**
     * Set context per uso in validate() e altri metodi
     * 
     * @param array $context Context workflow
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }
    
    /**
     * Esegue callable con retry logic (exponential backoff)
     * 
     * @param callable $fn Funzione da eseguire
     * @param int $maxAttempts Numero massimo tentativi
     * @return mixed Risultato della funzione
     * @throws Exception Se tutti i retry falliscono
     */
    protected function executeWithRetry(callable $fn, int $maxAttempts = 3)
    {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                $result = $fn();
                
                // Valida risultato
                if ($this->validate($result)) {
                    if ($attempt > 1) {
                        CentralLogger::info('workflow_step', 'Retry successful', [
                            'step' => get_class($this),
                            'attempt' => $attempt
                        ]);
                    }
                    return $result;
                }
                
                // Validation failed - extract error for contextual retry
                $validationError = $this->extractValidationError($result);
                
                CentralLogger::warning('workflow_step', 'Validation failed, retrying with context', [
                    'step' => get_class($this),
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'validation_hint' => substr($validationError, 0, 100)
                ]);
                
                if ($attempt >= $maxAttempts) {
                    // Reset retry hint
                    $this->retryHint = null;
                    throw new Exception('Validation failed after max retry attempts');
                }
                
                // Set retry hint for next attempt
                $this->retryHint = $validationError;
                
            } catch (Exception $e) {
                $lastException = $e;
                
                CentralLogger::warning('workflow_step', 'Step execution failed', [
                    'step' => get_class($this),
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
            }
            
            // Exponential backoff: 2^attempt seconds
            if ($attempt < $maxAttempts) {
                $sleepTime = pow(2, $attempt);
                CentralLogger::debug('workflow_step', 'Backing off before retry', [
                    'step' => get_class($this),
                    'sleep_seconds' => $sleepTime
                ]);
                sleep($sleepTime);
            }
        }
        
        // Se arriviamo qui, tutti i retry sono falliti
        throw $lastException ?? new Exception('Max retry attempts exceeded');
    }
    
    /**
     * Load prompt template da file
     * 
     * @param string $filename Nome file template (es: 'keyword_research.txt')
     * @return string Contenuto template
     * @throws Exception Se file non trovato
     */
    protected function loadPromptTemplate(string $filename): string
    {
        $path = __DIR__ . "/prompts/{$filename}";
        
        if (!file_exists($path)) {
            throw new Exception("Prompt template not found: {$filename} (expected at {$path})");
        }
        
        return file_get_contents($path);
    }
    
    /**
     * Append retry hint al prompt se disponibile
     * Usare alla fine di buildPrompt() per aggiungere feedback contestuale
     * 
     * @param string $prompt Prompt base
     * @return string Prompt con retry hint (se disponibile)
     */
    protected function appendRetryHint(string $prompt): string
    {
        if (empty($this->retryHint)) {
            return $prompt;
        }
        
        return $prompt . "\n\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
            "🚨 TENTATIVO PRECEDENTE FALLITO - CORREZIONE RICHIESTA\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
            $this->retryHint . "\n\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
            "⚠️ REGOLE PER QUESTO RETRY:\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n" .
            "1. Leggi ATTENTAMENTE il feedback sopra\n" .
            "2. Applica ESATTAMENTE le correzioni richieste\n" .
            "3. VERIFICA lunghezza caratteri PRIMA di rispondere\n" .
            "4. NON ripetere gli stessi errori del tentativo precedente\n" .
            "5. Se in dubbio, stai SOTTO il limite (margine sicurezza)\n\n" .
            "QUESTO È L'ULTIMO TENTATIVO - DEVE ESSERE PERFETTO.";
    }
    
    /**
     * Estrai validation error specifico per retry contestuale
     * Analizza result validation e crea hint intelligente per AI
     * 
     * @param array $result Result con validation errors
     * @return string Hint per retry (vuoto se nessun errore specifico)
     */
    protected function extractValidationError(array $result): string
    {
        if (!isset($result['validation'])) return '';
        
        $validation = $result['validation'];
        $hints = [];
        
        // Length errors (troppo lungo/corto)
        if (!empty($validation['errors'])) {
            foreach ($validation['errors'] as $error) {
                // Troppo lungo: "Troppo lungo: 387 caratteri (massimo 250)"
                if (preg_match('/Troppo lungo[:\s]+(\d+).*?massimo[:\s]+(\d+)/i', $error, $matches)) {
                    $actual = (int)$matches[1];
                    $max = (int)$matches[2];
                    $diff = $actual - $max;
                    
                    // Hint specifico per bullet vs altri campi
                    if ($max == 250) {
                        // È un bullet (max 250)
                        $hints[] = "⚠️ BULLET TROPPO LUNGO: {$actual} caratteri su MAX 250 TASSATIVO.\n\n" .
                                  "AZIONE RICHIESTA:\n" .
                                  "1. RIDUCI di {$diff} caratteri\n" .
                                  "2. Mantieni SOLO: ✓ KEYWORD_CAPS: descrizione essenziale\n" .
                                  "3. Elimina aggettivi ridondanti, subordinate lunghe\n" .
                                  "4. TARGET: 200-230 caratteri (margine sicurezza)\n" .
                                  "5. VERIFICA lunghezza PRIMA di rispondere\n\n" .
                                  "ESEMPIO CORRETTO (215 char):\n" .
                                  "✓ VERSATILE: Ideale per dolci e salato, arricchisce torte, " .
                                  "panature gourmet e pesto. Perfetto per dieta keto o gluten free, " .
                                  "dona sapore intenso senza additivi.";
                    } else {
                        // Altri campi (title, description)
                        $hints[] = "⚠️ OUTPUT TROPPO LUNGO: {$actual} caratteri (max {$max}). " .
                                  "RIDUCI di {$diff} caratteri mantenendo i concetti chiave. " .
                                  "Privilegia la parte centrale del contenuto, elimina ripetizioni.";
                    }
                }
                
                // Troppo corto: "Troppo corto: 100 caratteri (minimo 150)"
                if (preg_match('/Troppo corto[:\s]+(\d+).*?minimo[:\s]+(\d+)/i', $error, $matches)) {
                    $actual = (int)$matches[1];
                    $min = (int)$matches[2];
                    $diff = $min - $actual;
                    
                    $hints[] = "⚠️ OUTPUT TROPPO CORTO: {$actual} caratteri (min {$min}). " .
                              "ESPANDI di {$diff} caratteri aggiungendo dettagli rilevanti, " .
                              "benefici specifici o casi d'uso pratici.";
                }
                
                // Brand errors
                if (stripos($error, 'brand') !== false || stripos($error, 'marca') !== false) {
                    $hints[] = "⚠️ BRAND MANCANTE O ERRATO: Verifica che il brand sia posizionato " .
                              "all'inizio del titolo seguito da ' - '. Usa esattamente il brand fornito nel context.";
                }
                
                // Structure errors
                if (stripos($error, 'struttura') !== false || stripos($error, 'formato') !== false) {
                    $hints[] = "⚠️ STRUTTURA NON CONFORME: Segui ESATTAMENTE lo schema richiesto. " .
                              "Non invertire l'ordine degli elementi, usa i separatori corretti.";
                }
            }
        }
        
        // Warnings (soft errors)
        if (!empty($validation['warnings'])) {
            foreach ($validation['warnings'] as $warning) {
                if (preg_match('/(\d+)\s*char.*?ideale.*?(\d+)/i', $warning, $matches)) {
                    $actual = (int)$matches[1];
                    $ideal = (int)$matches[2];
                    
                    if ($actual > $ideal) {
                        $diff = $actual - $ideal;
                        $hints[] = "💡 SUGGERIMENTO: Riduci di circa {$diff} caratteri per ottimizzare " .
                                  "la visibilità mobile (ideale: {$ideal} char).";
                    }
                }
            }
        }
        
        return implode("\n\n", $hints);
    }
    
    /**
     * Estrai keywords da testo (helper method)
     * 
     * @param string $text Testo da cui estrarre keywords
     * @return array Array di keywords
     */
    protected function extractKeywordsFromText(string $text): array
    {
        // Remove HTML
        $text = strip_tags($text);
        
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');
        
        // Tokenize
        $words = preg_split('/\s+/', $text);
        
        // Filter stopwords e parole corte
        $stopwords = [
            'il', 'lo', 'la', 'i', 'gli', 'le',
            'un', 'uno', 'una',
            'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
            'e', 'o', 'ma', 'se', 'che', 'non',
            'è', 'sono', 'sia', 'del', 'della', 'delle', 'dei', 'degli',
            'al', 'allo', 'alla', 'ai', 'agli', 'alle',
            'nel', 'nello', 'nella', 'nei', 'negli', 'nelle',
            'sul', 'sullo', 'sulla', 'sui', 'sugli', 'sulle',
            'dal', 'dallo', 'dalla', 'dai', 'dagli', 'dalle'
        ];
        
        $words = array_filter($words, function($w) use ($stopwords) {
            return strlen($w) > 3 && !in_array($w, $stopwords);
        });
        
        return array_values(array_unique($words));
    }
}

