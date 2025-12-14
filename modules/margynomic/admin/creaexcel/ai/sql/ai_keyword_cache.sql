-- AI Keyword Cache Table
-- Stores extracted keywords for SKUs with 30-day expiration

CREATE TABLE IF NOT EXISTS ai_keyword_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    keywords JSON NOT NULL COMMENT 'Array[100] keywords SEO',
    analysis TEXT COMMENT 'Product analysis from web research (500-800 words)',
    structured_prompts JSON COMMENT 'Structured instructions for Step2-5 (title, description, bullets)',
    competitor_asins JSON COMMENT 'ASIN usati per extraction',
    extraction_method ENUM('competitor_analysis', 'ai_generated', 'fallback', 'web_research_analysis') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL COMMENT 'created_at + 30/90 days',
    
    UNIQUE KEY unique_sku_user (user_id, sku),
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at),
    INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migration: Add structured_prompts column (if table already exists)
ALTER TABLE ai_keyword_cache 
ADD COLUMN IF NOT EXISTS structured_prompts JSON COMMENT 'Structured instructions for Step2-5 (title, description, bullets)' 
AFTER analysis;

-- Auto-cleanup expired cache (optional cron job)
-- DELETE FROM ai_keyword_cache WHERE expires_at < NOW();

