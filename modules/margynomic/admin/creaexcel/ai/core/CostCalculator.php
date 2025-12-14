<?php
/**
 * CostCalculator
 * Calcola costi API basati su token usage
 * Supporta pricing Gemini con threshold 200K tokens
 */
class CostCalculator
{
    /**
     * Prezzi Gemini per milione di token (USD)
     * Fonte: https://ai.google.dev/pricing
     * NOTA: thinking tokens sono fatturati separatamente al prezzo dell'output
     */
    private const GEMINI_PRICES = [
        'input' => [
            'standard' => 2.00,    // <=200K tokens
            'high' => 4.00         // >200K tokens
        ],
        'output' => [
            'standard' => 12.00,   // <=200K tokens (candidatesTokenCount)
            'high' => 18.00        // >200K tokens (candidatesTokenCount)
        ],
        'cache' => [
            'standard' => 0.20,    // <=200K tokens
            'high' => 0.40         // >200K tokens
        ]
    ];
    
    /**
     * Threshold per pricing tier (tokens)
     */
    private const TIER_THRESHOLD = 200000;
    
    /**
     * Calcola costo per Gemini
     * 
     * @param int $inputTokens Input tokens (promptTokenCount)
     * @param int $outputTokens Output tokens (candidatesTokenCount)
     * @param int $thinkingTokens Thinking tokens (thoughtsTokenCount) - fatturato separatamente
     * @return array [input_cost, output_cost, thinking_cost, total_cost]
     */
    public static function calculateGeminiCost($inputTokens, $outputTokens, $thinkingTokens = 0)
    {
        // Determina tier pricing basato su input tokens
        $inputTier = $inputTokens <= self::TIER_THRESHOLD ? 'standard' : 'high';
        $outputTier = $inputTokens <= self::TIER_THRESHOLD ? 'standard' : 'high';
        
        // Calcola costi
        $inputCost = ($inputTokens / 1000000) * self::GEMINI_PRICES['input'][$inputTier];
        
        // Output cost (solo candidatesTokenCount)
        $outputCost = ($outputTokens / 1000000) * self::GEMINI_PRICES['output'][$outputTier];
        
        // Thinking cost (thoughtsTokenCount) - STESSO PREZZO dell'output
        $thinkingCost = ($thinkingTokens / 1000000) * self::GEMINI_PRICES['output'][$outputTier];
        
        // Total = input + output + thinking
        $totalCost = $inputCost + $outputCost + $thinkingCost;
        
        return [
            'input_cost' => round($inputCost, 6),
            'output_cost' => round($outputCost, 6),
            'thinking_cost' => round($thinkingCost, 6),
            'total_cost' => round($totalCost, 6)
        ];
    }
    
    /**
     * Salva usage nel database
     */
    public static function saveUsage($pdo, $data)
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO ai_usage_costs (
                    user_id, provider, model, operation,
                    input_tokens, output_tokens, thinking_tokens, total_tokens,
                    input_cost, output_cost, thinking_cost, total_cost,
                    field_name, product_sku, attempt, was_repaired
                ) VALUES (
                    :user_id, :provider, :model, :operation,
                    :input_tokens, :output_tokens, :thinking_tokens, :total_tokens,
                    :input_cost, :output_cost, :thinking_cost, :total_cost,
                    :field_name, :product_sku, :attempt, :was_repaired
                )
            ");
            
            return $stmt->execute($data);
            
        } catch (PDOException $e) {
            // Log solo errori critici
            CentralLogger::error('cost_calculator', 'Failed to save usage', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Ottieni statistiche costi per utente
     */
    public static function getUserStats($pdo, $userId, $daysBack = 30)
    {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_calls,
                SUM(input_tokens) as total_input_tokens,
                SUM(output_tokens) as total_output_tokens,
                SUM(thinking_tokens) as total_thinking_tokens,
                SUM(total_tokens) as total_tokens,
                SUM(total_cost) as total_cost,
                AVG(total_cost) as avg_cost_per_call,
                provider,
                model
            FROM ai_usage_costs
            WHERE user_id = :user_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY provider, model
            ORDER BY total_cost DESC
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'days' => $daysBack
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ottieni breakdown per operazione
     */
    public static function getOperationBreakdown($pdo, $userId, $daysBack = 30)
    {
        $stmt = $pdo->prepare("
            SELECT 
                operation,
                COUNT(*) as calls,
                SUM(total_cost) as total_cost,
                AVG(total_cost) as avg_cost,
                SUM(input_tokens) as input_tokens,
                SUM(output_tokens) as output_tokens
            FROM ai_usage_costs
            WHERE user_id = :user_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY operation
            ORDER BY total_cost DESC
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'days' => $daysBack
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ottieni andamento giornaliero
     */
    public static function getDailyTrend($pdo, $userId, $daysBack = 30)
    {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as calls,
                SUM(total_cost) as cost,
                SUM(total_tokens) as tokens
            FROM ai_usage_costs
            WHERE user_id = :user_id
            AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        
        $stmt->execute([
            'user_id' => $userId,
            'days' => $daysBack
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Formatta costo per display
     */
    public static function formatCost($cost)
    {
        if ($cost < 0.01) {
            return '$' . number_format($cost, 6);
        }
        return '$' . number_format($cost, 4);
    }
}
