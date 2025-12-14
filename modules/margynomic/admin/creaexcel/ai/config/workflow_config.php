<?php
/**
 * Workflow Configuration
 * Definisce modelli, costi, timeout per ogni step
 */
return [
    'steps' => [
        'keyword_research' => [
            'model' => 'gemini-3-pro-preview',
            'provider' => 'gemini',
            'timeout' => 30,
            'retry_attempts' => 2,
            'cost_estimate' => 0.04,
            'max_tokens' => 8000
        ],
        
        'title_generation' => [
            'model' => 'gemini-3-pro-preview',
            'provider' => 'gemini',
            'timeout' => 30,
            'retry_attempts' => 2,
            'cost_estimate' => 0.03,
            'max_tokens' => 3000
        ],
        
        'description_generation' => [
            'model' => 'gemini-3-pro-preview',
            'provider' => 'gemini',
            'timeout' => 30,
            'retry_attempts' => 2,
            'cost_estimate' => 0.06,
            'max_tokens' => 8000  // ✅ Aumentato per evitare troncamenti
        ],
        
        'bullets_generation' => [
            'model' => 'gemini-3-pro-preview',
            'provider' => 'gemini',
            'timeout' => 30,
            'retry_attempts' => 2,
            'cost_estimate' => 0.15,
            'max_tokens' => 5000
        ],
        
        'hidden_keywords' => [
            'model' => null,  // No LLM
            'provider' => null,
            'timeout' => 1,
            'retry_attempts' => 1,
            'cost_estimate' => 0,
            'max_tokens' => 0
        ]
    ]
];

