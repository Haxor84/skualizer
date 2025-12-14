<?php
/**
 * KeywordParser
 * Parse JSON response da LLM per estrarre array keywords
 */
class KeywordParser
{
    /**
     * Parse response LLM contenente JSON array di keywords o {analysis, keywords}
     * 
     * @param string $response Response raw da LLM
     * @return array|array{analysis: string, keywords: array} Array di keywords o object con analysis + keywords
     * @throws Exception Se parsing fallisce
     */
    public function parse(string $response): array
    {
        CentralLogger::debug('keyword_parser', 'Parsing response', [
            'response_length' => strlen($response),
            'starts_with' => substr($response, 0, 50),
            'ends_with' => substr($response, -50)
        ]);
        
        // Cleanup response: rimuovi markdown code blocks se presenti
        $response = trim($response);
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*$/', '', $response);
        $response = trim($response);
        
        // Tenta JSON decode
        $decoded = json_decode($response, true);
        
        CentralLogger::debug('keyword_parser', 'JSON decode attempt', [
            'json_error' => json_last_error(),
            'json_error_msg' => json_last_error_msg(),
            'is_array' => is_array($decoded),
            'decoded_keys' => is_array($decoded) ? array_keys($decoded) : 'NOT_ARRAY'
        ]);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON parsing failed: ' . json_last_error_msg() . ". Response: " . substr($response, 0, 200));
        }
        
        // Verifica che sia array
        if (!is_array($decoded)) {
            throw new Exception('Parsed JSON is not an array');
        }
        
        // CHECK: NEW FORMAT v2 {analysis_markdown: "...", keywords: [...], structured_prompts: {...}}
        if (isset($decoded['analysis_markdown']) && isset($decoded['keywords']) && isset($decoded['structured_prompts'])) {
            // Extract and clean keywords
            $keywords = array_filter($decoded['keywords'], 'is_string');
            $keywords = array_map('trim', $keywords);
            $keywords = array_map('strtolower', $keywords);
            $keywords = array_unique($keywords);
            $keywords = array_filter($keywords, function($k) {
                return strlen($k) > 2;
            });
            
            return [
                'analysis' => trim($decoded['analysis_markdown']),
                'keywords' => array_values($keywords),
                'structured_prompts' => $decoded['structured_prompts'] // NEW: Pass structured instructions
            ];
        }
        
        // FALLBACK: OLD FORMAT v1 {analysis: "...", keywords: [...]}
        if (isset($decoded['analysis']) && isset($decoded['keywords'])) {
            // Extract and clean keywords
            $keywords = array_filter($decoded['keywords'], 'is_string');
            $keywords = array_map('trim', $keywords);
            $keywords = array_map('strtolower', $keywords);
            $keywords = array_unique($keywords);
            $keywords = array_filter($keywords, function($k) {
                return strlen($k) > 2;
            });
            
            return [
                'analysis' => trim($decoded['analysis']),
                'keywords' => array_values($keywords)
            ];
        }
        
        // FALLBACK: OLD FORMAT - array diretto di keywords
        $keywords = array_filter($decoded, 'is_string');
        
        // Cleanup keywords: trim, lowercase, dedup
        $keywords = array_map('trim', $keywords);
        $keywords = array_map('strtolower', $keywords);
        $keywords = array_unique($keywords);
        
        // Remove empty
        $keywords = array_filter($keywords, function($k) {
            return strlen($k) > 2;
        });
        
        return array_values($keywords);
    }
}

