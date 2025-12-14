<?php
/**
 * VariantAdapter
 * Genera contenuti per varianti prodotto basandosi su master
 * 
 * Input: 1 master row + N variant rows (da file Excel)
 * Output: N varianti con contenuti adattati
 * 
 * Risparmio: 67% costi ($0.30 × 4 → $0.40 totale)
 */

require_once __DIR__ . '/LlmClient.php';
require_once __DIR__ . '/ContentValidator.php';
require_once __DIR__ . '/PolicyManager.php';
require_once __DIR__ . '/../../../../config/CentralLogger.php';
require_once __DIR__ . '/AiEngine.php';

class VariantAdapter
{
    private int $userId;
    private LlmClient $llmClient;
    private PolicyManager $policyManager;
    private AiEngine $engine;
    
    public function __construct(int $userId)
    {
        $this->userId = $userId;
        
        // LLM Client: Gemini 3 Pro
        $aiConfig = require __DIR__ . '/../config/ai_config.php';
        $this->llmClient = new LlmClient($aiConfig, 'gemini', 'gemini-3-pro-preview');
        
        // Policy Manager
        $policyFile = $aiConfig['paths']['policy_file'] ?? __DIR__ . '/../config/amazon_policy.json';
        $this->policyManager = new PolicyManager($policyFile);
        
        // AI Engine
        $this->engine = new AiEngine($userId);
    }
    
    /**
     * Adatta contenuti master a N varianti
     * 
     * @param string $filepath Path file Excel
     * @param int $masterRowNumber Numero riga master
     * @param array $variantRowNumbers Array numeri righe varianti
     * @return array Results per ogni variante [rowNumber => [fields => results]]
     */
    public function adaptVariants(string $filepath, int $masterRowNumber, array $variantRowNumbers): array
    {
        CentralLogger::info('variant_adapter', 'Adaptation started', [
            'user_id' => $this->userId,
            'filepath' => basename($filepath),
            'master_row' => $masterRowNumber,
            'variant_count' => count($variantRowNumbers)
        ]);
        
        try {
            // 1. Carica master content
            $masterResult = $this->engine->getRow($filepath, $masterRowNumber);
            if (!$masterResult['success']) {
                throw new Exception('Failed to load master row: ' . ($masterResult['error'] ?? 'unknown'));
            }
            $master = $masterResult['data'] ?? [];
            
            // Debug: Log campi disponibili
            CentralLogger::debug('variant_adapter', 'Master row loaded', [
                'row_number' => $masterRowNumber,
                'fields_available' => array_keys($master),
                'has_item_sku' => isset($master['item_sku']),
                'has_item_name' => isset($master['item_name']),
                'item_sku_value' => $master['item_sku'] ?? '(missing)'
            ]);
            
            // 2. Carica variants data
            $variants = [];
            foreach ($variantRowNumbers as $rowNumber) {
                $variantResult = $this->engine->getRow($filepath, $rowNumber);
                if (!$variantResult['success']) {
                    throw new Exception("Failed to load variant row {$rowNumber}");
                }
                $variants[$rowNumber] = $variantResult['data'] ?? [];
            }
            
            // 3. Estrai differenze
            $differences = $this->extractDifferences($master, $variants);
            
            // 4. Build prompt
            $prompt = $this->buildAdaptationPrompt($master, $variants, $differences);
            
            // 5. Call Gemini 3 Pro
            CentralLogger::debug('variant_adapter', 'Calling Gemini 3 Pro', [
                'prompt_length' => strlen($prompt)
            ]);
            
            $response = $this->llmClient->generate($prompt, 12000);
            
            // 6. Parse JSON
            $adapted = $this->parseResponse($response);
            
            // 7. Validate + Format results
            $results = $this->validateResults($adapted, $variantRowNumbers);
            
            // 8. Save results back to Excel
            $this->saveResultsToExcel($filepath, $results);
            
            CentralLogger::info('variant_adapter', 'Adaptation completed', [
                'user_id' => $this->userId,
                'variants_generated' => count($results)
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            CentralLogger::error('variant_adapter', 'Adaptation failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Estrai differenze tra master e variants
     */
    private function extractDifferences(array $master, array $variants): array
    {
        $diffs = [];
        
        foreach ($variants as $rowNumber => $variant) {
            $diff = [];
            
            // Size/Weight
            if (($master['size_name'] ?? '') !== ($variant['size_name'] ?? '')) {
                $diff['size'] = [
                    'from' => $master['size_name'] ?? '',
                    'to' => $variant['size_name'] ?? ''
                ];
            }
            
            // Color
            if (($master['color_name'] ?? '') !== ($variant['color_name'] ?? '')) {
                $diff['color'] = [
                    'from' => $master['color_name'] ?? '',
                    'to' => $variant['color_name'] ?? ''
                ];
            }
            
            // Unit Count
            if (($master['unit_count'] ?? '') !== ($variant['unit_count'] ?? '')) {
                $diff['unit_count'] = [
                    'from' => $master['unit_count'] ?? '',
                    'to' => $variant['unit_count'] ?? ''
                ];
            }
            
            // Style
            if (($master['style_name'] ?? '') !== ($variant['style_name'] ?? '')) {
                $diff['style'] = [
                    'from' => $master['style_name'] ?? '',
                    'to' => $variant['style_name'] ?? ''
                ];
            }
            
            // SKU (importante per identificare le varianti)
            if (($master['item_sku'] ?? '') !== ($variant['item_sku'] ?? '')) {
                $diff['sku'] = [
                    'from' => $master['item_sku'] ?? '',
                    'to' => $variant['item_sku'] ?? ''
                ];
            }
            
            $diffs[$rowNumber] = $diff;
        }
        
        return $diffs;
    }
    
    /**
     * Build prompt per adaptation
     */
    private function buildAdaptationPrompt(array $master, array $variants, array $diffs): string
    {
        $prompt = "SEI UN ESPERTO Amazon content adapter per varianti prodotto.\n\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "MASTER PRODUCT (RIFERIMENTO)\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "SKU: " . ($master['item_sku'] ?? '') . "\n\n";
        $prompt .= "ITEM_NAME:\n" . ($master['item_name'] ?? '') . "\n\n";
        $prompt .= "PRODUCT_DESCRIPTION:\n" . ($master['product_description'] ?? '') . "\n\n";
        $prompt .= "BULLET_POINT_1:\n" . ($master['bullet_point1'] ?? '') . "\n\n";
        $prompt .= "BULLET_POINT_2:\n" . ($master['bullet_point2'] ?? '') . "\n\n";
        $prompt .= "BULLET_POINT_3:\n" . ($master['bullet_point3'] ?? '') . "\n\n";
        $prompt .= "BULLET_POINT_4:\n" . ($master['bullet_point4'] ?? '') . "\n\n";
        $prompt .= "BULLET_POINT_5:\n" . ($master['bullet_point5'] ?? '') . "\n\n";
        $prompt .= "GENERIC_KEYWORDS:\n" . ($master['generic_keywords'] ?? '') . "\n\n";
        
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "TASK: ADATTA CONTENUTI ALLE VARIANTI\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "Genera contenuti per queste varianti modificando SOLO ciò che è necessario:\n\n";
        
        $variantIndex = 1;
        foreach ($variants as $rowNumber => $variant) {
            $diffText = $this->formatDifferences($diffs[$rowNumber]);
            
            $prompt .= "VARIANTE {$variantIndex}:\n";
            $prompt .= "SKU: " . ($variant['item_sku'] ?? '') . "\n";
            $prompt .= "Differenze rispetto al master:\n";
            $prompt .= "{$diffText}\n\n";
            $variantIndex++;
        }
        
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "REGOLE CRITICHE\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "1. **Mantieni IDENTICI**: tone, stile, struttura, keywords chiave\n";
        $prompt .= "2. **Sostituisci SOLO**: attributi variante dove esplicitamente menzionati\n";
        $prompt .= "3. **Adatta contesto**:\n";
        $prompt .= "   - \"formato mini\" → \"formato famiglia\" (se 100g→1000g)\n";
        $prompt .= "   - \"snack portatile\" → \"uso cucina frequente\" (se small→large)\n";
        $prompt .= "   - \"dose singola\" → \"scorta conveniente\" (se piccolo→grande)\n";
        $prompt .= "4. **Mantieni lunghezze**: Item name 150-200 char, Bullets 180-240 char\n";
        $prompt .= "5. **NO claim**: Evita \"migliore\", \"il più\", comparativi assoluti\n\n";
        
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "OUTPUT FORMATO JSON\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "{\n";
        $prompt .= "  \"variant_1\": {\n";
        $prompt .= "    \"item_name\": \"...\",\n";
        $prompt .= "    \"product_description\": \"...\",\n";
        $prompt .= "    \"bullet_point1\": \"...\",\n";
        $prompt .= "    \"bullet_point2\": \"...\",\n";
        $prompt .= "    \"bullet_point3\": \"...\",\n";
        $prompt .= "    \"bullet_point4\": \"...\",\n";
        $prompt .= "    \"bullet_point5\": \"...\",\n";
        $prompt .= "    \"generic_keywords\": \"...\"\n";
        $prompt .= "  },\n";
        $prompt .= "  \"variant_2\": { ... },\n";
        $prompt .= "  \"variant_N\": { ... }\n";
        $prompt .= "}\n\n";
        $prompt .= "⚠️ SOLO JSON puro, NO markdown code blocks.\n\n";
        $prompt .= "INIZIA OUTPUT:\n";
        
        return $prompt;
    }
    
    /**
     * Formatta differenze in testo leggibile
     */
    private function formatDifferences(array $diff): string
    {
        if (empty($diff)) {
            return "Nessuna differenza rilevata";
        }
        
        $lines = [];
        foreach ($diff as $attr => $change) {
            $lines[] = "- {$attr}: {$change['from']} → {$change['to']}";
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Parse JSON response
     */
    private function parseResponse(string $response): array
    {
        // Cleanup markdown blocks
        $response = trim($response);
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*$/', '', $response);
        $response = trim($response);
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON parsing failed: ' . json_last_error_msg());
        }
        
        return $decoded;
    }
    
    /**
     * Validate results per ogni variante
     */
    private function validateResults(array $adapted, array $variantRowNumbers): array
    {
        $validator = new ContentValidator($this->policyManager);
        $results = [];
        
        $variantIndex = 1;
        foreach ($variantRowNumbers as $rowNumber) {
            $variantKey = "variant_{$variantIndex}";
            
            if (!isset($adapted[$variantKey])) {
                CentralLogger::warning('variant_adapter', 'Variant missing in response', [
                    'row_number' => $rowNumber,
                    'variant_key' => $variantKey
                ]);
                continue;
            }
            
            $content = $adapted[$variantKey];
            $fields = [];
            
            // Validate ogni campo
            foreach ($content as $fieldName => $fieldValue) {
                $validation = $validator->validate($fieldName, $fieldValue);
                
                $fields[$fieldName] = [
                    'content' => $fieldValue,
                    'success' => $validation['valid'],
                    'validation' => $validation
                ];
            }
            
            $results[$rowNumber] = $fields;
            $variantIndex++;
        }
        
        return $results;
    }
    
    /**
     * Save results to Excel file
     */
    private function saveResultsToExcel(string $filepath, array $results): void
    {
        foreach ($results as $rowNumber => $fields) {
            // Prepara row_data
            $rowData = [];
            foreach ($fields as $fieldName => $fieldInfo) {
                $rowData[$fieldName] = $fieldInfo['content'];
            }
            
            // Salva riga
            $saveResult = $this->engine->saveRow($filepath, $rowNumber, $rowData);
            
            if (!$saveResult['success']) {
                CentralLogger::warning('variant_adapter', 'Failed to save variant row', [
                    'row_number' => $rowNumber,
                    'error' => $saveResult['error'] ?? 'unknown'
                ]);
            }
        }
    }
}
