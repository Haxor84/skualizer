<?php
/**
 * AI Product Creator - Engine Core
 * Path: modules/margynomic/admin/creaexcel/ai/core/AiEngine.php
 * 
 * Monolite backend con tutta la logica AI Product Creator:
 * - EAN-13 generation con checksum
 * - Template upload e parsing
 * - Competitor scraping Amazon.it
 * - Keyword extraction e scoring
 * - LLM integration (Claude Sonnet 4 + GPT-4 Turbo)
 * - Policy validation engine
 * - Excel export con PhpSpreadsheet
 * - Session state machine
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../config/CentralLogger.php';
require_once __DIR__ . '/../../ExcelParser.php';
require_once __DIR__ . '/../../DropdownExtractor.php';
require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/AiContentGenerator.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Settings;
use PhpOffice\PhpSpreadsheet\Shared\File;

class AiEngine
{

    private $pdo;
    private $config;
    private $userId;
    private $contentGenerator; // AI Content Generator orchestrator

    // ============================================
    // CONSTRUCTOR & SETUP
    // ============================================

    public function __construct($userId)
    {
        $this->pdo = getDbConnection();
        $this->config = require __DIR__ . '/../config/ai_config.php';
        $this->userId = $userId;

        // Aumenta memory limit per file Excel grandi
        ini_set('memory_limit', '512M');

        // Inizializza AI Content Generator orchestrator
        $this->contentGenerator = new AiContentGenerator($this->config, $userId);

        // PhpSpreadsheet usa sys_get_temp_dir() di default
        // Se open_basedir impedisce accesso, PhpSpreadsheet fallisce
        // Workaround: assicurati che sys_get_temp_dir() sia accessibile

        // Aumenta memory limit per file Excel grandi
        ini_set('memory_limit', '512M');

        // Crea directory se non esistono
        $dirs = [
            $this->config['paths']['templates'],
            $this->config['paths']['exports']
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        CentralLogger::info('ai_creator', 'AiEngine initialized', ['user_id' => $userId]);
    }

    // ============================================
    // SAFE EXCEL LOADER (FIX open_basedir)
    // ============================================

    /**
     * Carica file Excel con fix per open_basedir restrictions
     * 
     * Disabilita temporaneamente open_basedir per permettere caricamento
     * completo inclusi dropdown e data validations.
     * 
     * @param string $filepath Path al file Excel
     * @return \PhpOffice\PhpSpreadsheet\Spreadsheet
     * @throws Exception
     */
    private function loadExcelSafe($filepath)
    {
        if (!file_exists($filepath)) {
            throw new Exception("File not found: $filepath");
        }

        try {
            // Salva e disabilita open_basedir temporaneamente
            $oldOpenBasedir = ini_get('open_basedir');
            @ini_set('open_basedir', '');

            // Carica DIRETTAMENTE dal filepath originale
            // Non copiare in /tmp/ perché poi il sistema cerca di riaccedere al file
            $spreadsheet = IOFactory::load($filepath);
            
            // Ripristina open_basedir
            if ($oldOpenBasedir) {
                @ini_set('open_basedir', $oldOpenBasedir);
            }
            
            CentralLogger::info('ai_creator', 'Excel loaded successfully', [
                'filepath' => basename($filepath),
                'size' => filesize($filepath)
            ]);
            
            return $spreadsheet;
            
        } catch (Exception $e) {
            // Ripristina open_basedir anche in caso di errore
            if (isset($oldOpenBasedir) && $oldOpenBasedir) {
                @ini_set('open_basedir', $oldOpenBasedir);
            }
            
            CentralLogger::error('ai_creator', 'Failed to load Excel file', [
                'filepath' => basename($filepath),
                'error' => $e->getMessage()
            ]);
            
            throw new Exception("Unable to load Excel file: " . $e->getMessage());
        }
    }

    // ============================================
    // EAN-13 GENERATION
    // ============================================

    /**
     * Genera nuovo EAN-13 con checksum valido
     */
    public function generateEan()
    {
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("
                SELECT MAX(CAST(SUBSTRING(ean, 7, 6) AS UNSIGNED)) as max_seq
                FROM products
                WHERE user_id = ? AND ean IS NOT NULL AND ean LIKE ?
                FOR UPDATE
            ");

            $prefix = $this->config['ean']['prefix'];
            $stmt->execute([$this->userId, $prefix . '%']);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            $nextSeq = $result['max_seq'] ? ($result['max_seq'] + 1) : $this->config['ean']['start_sequential'];

            $eanBase = $prefix . sprintf('%06d', $nextSeq);
            $checksum = $this->calculateEan13Checksum($eanBase);
            $eanComplete = $eanBase . $checksum;

            if (strlen($eanComplete) !== 13 || !ctype_digit($eanComplete)) {
                throw new Exception('EAN generato non valido: ' . $eanComplete);
            }

            $this->pdo->commit();

            CentralLogger::info('ai_creator', 'EAN generated', [
                'user_id' => $this->userId,
                'ean' => $eanComplete,
                'sequence' => $nextSeq
            ]);

            return [
                'success' => true,
                'ean' => $eanComplete,
                'sequence' => $nextSeq
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();

            CentralLogger::error('ai_creator', 'EAN generation failed', [
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
     * Calcola checksum EAN-13 (algoritmo standard)
     */
    private function calculateEan13Checksum($eanBase)
    {
        if (strlen($eanBase) !== 12 || !ctype_digit($eanBase)) {
            throw new Exception('EAN base deve essere 12 cifre numeriche');
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $eanBase[$i];
            $weight = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $weight;
        }

        $checksum = (10 - ($sum % 10)) % 10;
        return (string) $checksum;
    }

    /**
     * Valida EAN-13 esistente
     */
    public function validateEan($ean)
    {
        if (strlen($ean) !== 13 || !ctype_digit($ean)) {
            return ['valid' => false, 'error' => 'EAN deve essere 13 cifre numeriche'];
        }

        $eanBase = substr($ean, 0, 12);
        $checksumProvided = substr($ean, 12, 1);
        $checksumCalculated = $this->calculateEan13Checksum($eanBase);

        if ($checksumProvided !== $checksumCalculated) {
            return [
                'valid' => false,
                'error' => "Checksum non valido (atteso: $checksumCalculated, ricevuto: $checksumProvided)"
            ];
        }

        return ['valid' => true];
    }

    // ============================================
    // TEMPLATE UPLOAD & PARSING
    // ============================================

    /**
     * Upload e parsing template Excel Amazon
     * 
     * @param array $file File da $_FILES
     * @param string|null $categoria Categoria Amazon
     * @param string $folder Nome cartella cliente/progetto (opzionale)
     * @return array Result con filepath, template_id, metadata
     */
    public function uploadTemplate($file, $categoria = null, $folder = '')
    {
        try {
            if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new Exception('File upload non valido');
            }

            $maxSize = 50 * 1024 * 1024;
            if ($file['size'] > $maxSize) {
                throw new Exception('File troppo grande (max 50MB)');
            }

            $allowedExt = ['xlsm', 'xlsx'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) {
                throw new Exception('Estensione non permessa. Solo .xlsm o .xlsx');
            }

            $originalFilename = $file['name'];
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
            $timestamp = time();
            $newFilename = "{$this->userId}_{$timestamp}_{$safeName}";

            // Build destination directory (include folder if specified)
            $destDir = $this->config['paths']['templates'] . $this->userId . '/';
            
            if (!empty($folder)) {
                // Validate folder name
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $folder)) {
                    throw new Exception('Nome cartella non valido');
                }
                $destDir .= $folder . '/';
            }
            
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            
            $destPath = $destDir . $newFilename;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new Exception('Errore spostamento file');
            }

            // Parsing con ExcelParser esistente
            $parser = new ExcelParser($this->userId);
            $parseResult = $parser->parse($destPath, $this->userId);

            $metadata = [
                'column_mapping' => $parseResult['column_mapping'] ?? [],
                'headers' => $parseResult['headers'] ?? [],
                'num_rows' => $parseResult['num_rows'] ?? 0,
                'num_columns' => $parseResult['num_columns'] ?? 0,
                'first_data_row' => $parseResult['first_data_row'] ?? 5
            ];

            // Estrai dropdown values
            $spreadsheet = $this->loadExcelSafe($destPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $extractor = new DropdownExtractor();
            $dropdowns = $extractor->extractDropdowns($worksheet, $parseResult['headers'] ?? []);

            $metadata['dropdown_values'] = $dropdowns;

            $requiredFields = [];
            foreach ($parseResult['column_mapping'] ?? [] as $field => $col) {
                if (in_array($field, ['sku', 'title', 'description'])) {
                    $requiredFields[] = $field;
                }
            }
            $metadata['required_fields'] = $requiredFields;

            $stmt = $this->pdo->prepare("
                INSERT INTO ai_templates 
                (user_id, categoria_amazon, template_name, filepath, metadata, is_validated, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");

            $stmt->execute([
                $this->userId,
                $categoria,
                $originalFilename,
                $destPath,
                json_encode($metadata, JSON_UNESCAPED_UNICODE)
            ]);

            $templateId = $this->pdo->lastInsertId();

            CentralLogger::info('ai_creator', 'Template uploaded', [
                'user_id' => $this->userId,
                'template_id' => $templateId,
                'filename' => $originalFilename,
                'folder' => $folder ?: 'root',
                'columns' => count($metadata['column_mapping'])
            ]);

            return [
                'success' => true,
                'template_id' => $templateId,
                'template_name' => $originalFilename,
                'filepath' => $destPath,
                'metadata' => $metadata
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Template upload failed', [
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
     * Lista template disponibili per user
     */
    public function listTemplates($categoria = null)
    {
        try {
            $sql = "SELECT id, categoria_amazon, template_name, is_validated, created_at 
                    FROM ai_templates 
                    WHERE user_id = ?";

            $params = [$this->userId];

            if ($categoria) {
                $sql .= " AND categoria_amazon = ?";
                $params[] = $categoria;
            }

            $sql .= " ORDER BY created_at DESC LIMIT 50";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return [
                'success' => true,
                'templates' => $stmt->fetchAll(PDO::FETCH_ASSOC)
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============================================
    // COMPETITOR SCRAPING AMAZON.IT
    // ============================================

    /**
     * Scraping competitor ASINs da Amazon.it
     */
    public function analyzeCompetitors($asins, $offset = 0, $limit = 3)
    {
        try {
            if (!$this->config['scraping']['enabled']) {
                throw new Exception('Scraping disabilitato');
            }

            $asins = array_filter($asins, function ($asin) {
                return preg_match('/^B[A-Z0-9]{9}$/', trim($asin));
            });

            if (empty($asins)) {
                throw new Exception('Nessun ASIN valido fornito');
            }

            $totalAsins = count($asins);
            $asinsChunk = array_slice($asins, $offset, $limit);

            $results = [];
            $errors = [];

            foreach ($asinsChunk as $asin) {
                $asin = trim($asin);

                $scraped = $this->scrapeAmazonProduct($asin);

                if ($scraped['success']) {
                    $results[$asin] = $scraped['data'];
                } else {
                    $errors[$asin] = $scraped['error'];
                }

                sleep($this->config['scraping']['rate_limit_sec']);
            }

            $processedCount = $offset + count($asinsChunk);
            $progress = round(($processedCount / $totalAsins) * 100);
            $isCompleted = $processedCount >= $totalAsins;

            CentralLogger::info('ai_creator', 'Competitor analysis chunk', [
                'user_id' => $this->userId,
                'total_asins' => $totalAsins,
                'processed' => $processedCount,
                'progress' => $progress
            ]);

            return [
                'success' => true,
                'status' => $isCompleted ? 'completed' : 'processing',
                'progress' => $progress,
                'processed_count' => $processedCount,
                'total_count' => $totalAsins,
                'results' => $results,
                'errors' => $errors,
                'next_offset' => $isCompleted ? null : $processedCount
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Competitor analysis failed', [
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
     * Scraping singolo prodotto Amazon.it con retry
     */
    private function scrapeAmazonProduct($asin)
    {
        // Check mock mode
        if ($this->config['debug']['mock_mode']) {
            return $this->getMockScrapingData($asin);
        }

        $maxRetries = $this->config['scraping']['max_retries'];
        $timeout = $this->config['scraping']['timeout'];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $url = $this->config['scraping']['base_url'] . "/dp/{$asin}";
                $userAgent = $this->getRandomUserAgent();

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => $userAgent,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_HTTPHEADER => [
                        'Accept-Language: it-IT,it;q=0.9,en;q=0.8',
                        'Accept: text/html,application/xhtml+xml'
                    ]
                ]);

                $html = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($curlError) {
                    throw new Exception("cURL error: $curlError");
                }

                if ($httpCode !== 200) {
                    throw new Exception("HTTP $httpCode");
                }

                if (empty($html)) {
                    throw new Exception("Empty response");
                }

                $parsed = $this->parseAmazonHtml($html, $asin);

                if (!$parsed['title']) {
                    throw new Exception("Title not found");
                }

                return [
                    'success' => true,
                    'data' => $parsed
                ];

            } catch (Exception $e) {
                $lastError = $e->getMessage();

                if ($attempt < $maxRetries) {
                    sleep(pow(2, $attempt));
                    continue;
                }

                CentralLogger::warning('ai_creator', 'Scraping failed after retries', [
                    'asin' => $asin,
                    'attempts' => $maxRetries,
                    'error' => $lastError
                ]);

                return [
                    'success' => false,
                    'error' => $lastError
                ];
            }
        }
    }

    /**
     * Parsing HTML Amazon con fallback multipli
     */
    private function parseAmazonHtml($html, $asin)
    {
        $selectors = $this->config['scraping']['selectors'];

        // Title extraction
        $title = $this->extractFromHtmlWithFallback($html, $selectors['title']);

        // Description extraction
        $description = $this->extractFromHtmlWithFallback($html, $selectors['description']);

        // Bullets extraction
        $bullets = [];
        foreach ($selectors['bullets'] as $selector) {
            if (preg_match_all('/<li[^>]*>\s*<span[^>]*class="[^"]*a-list-item[^"]*"[^>]*>(.*?)<\/span>/s', $html, $matches)) {
                foreach ($matches[1] as $bullet) {
                    $bulletClean = strip_tags($bullet);
                    $bulletClean = html_entity_decode($bulletClean, ENT_QUOTES, 'UTF-8');
                    $bulletClean = trim($bulletClean);
                    if (!empty($bulletClean) && strlen($bulletClean) > 10) {
                        $bullets[] = $bulletClean;
                    }
                }
                if (!empty($bullets))
                    break;
            }
        }

        return [
            'asin' => $asin,
            'title' => $this->cleanText($title),
            'description' => $this->cleanText($description),
            'bullets' => array_slice($bullets, 0, 5)
        ];
    }

    /**
     * Estrai elemento HTML con fallback multipli
     */
    private function extractFromHtmlWithFallback($html, $selectors)
    {
        foreach ($selectors as $selector) {
            // ID selector (#productTitle)
            if (strpos($selector, '#') === 0) {
                $id = substr($selector, 1);
                if (preg_match('/<[^>]*id=["\']' . preg_quote($id, '/') . '["\'][^>]*>(.*?)<\/[^>]+>/s', $html, $matches)) {
                    $content = strip_tags($matches[1]);
                    $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
                    $content = trim($content);
                    if (!empty($content))
                        return $content;
                }
            }

            // Class selector (.product-title)
            if (strpos($selector, '.') === 0) {
                $class = substr($selector, 1);
                if (preg_match('/<[^>]*class=["\'][^"\']*' . preg_quote($class, '/') . '[^"\']*["\'][^>]*>(.*?)<\/[^>]+>/s', $html, $matches)) {
                    $content = strip_tags($matches[1]);
                    $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
                    $content = trim($content);
                    if (!empty($content))
                        return $content;
                }
            }
        }

        return '';
    }

    /**
     * Random User-Agent rotation
     */
    private function getRandomUserAgent()
    {
        $userAgents = $this->config['scraping']['user_agents'];
        return $userAgents[array_rand($userAgents)];
    }

    /**
     * Clean text extracted
     */
    private function cleanText($text)
    {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return $text;
    }

    /**
     * Mock scraping data per testing
     */
    private function getMockScrapingData($asin)
    {
        return [
            'success' => true,
            'data' => [
                'asin' => $asin,
                'title' => 'Mandorle Sgusciate Premium 500g Origine Californiana USA Naturali Non Salate Ricche Proteine',
                'description' => 'Scopri le mandorle californiane di qualità superiore. Coltivate sotto il sole degli Stati Uniti.',
                'bullets' => [
                    '🥜 Mandorle Premium - Origine Californiana USA certificata',
                    '💪 Ricche di Proteine - 21g per 100g, ideali per sportivi',
                    '🌱 100% Naturali - Non salate, non tostate, senza conservanti',
                    '✨ Versatili - Perfette per snack, cucina, dolci e latte vegetale',
                    '📦 Confezione Richiudibile - Mantiene freschezza e croccantezza'
                ]
            ]
        ];
    }

    // ============================================
    // KEYWORD EXTRACTION & SCORING
    // ============================================

    /**
     * Estrai keywords da risultati competitor scraped
     */
    public function extractKeywords($competitorData, $categoria)
    {
        try {
            $allText = '';
            $asinList = [];

            foreach ($competitorData as $asin => $data) {
                $asinList[] = $asin;
                $allText .= ' ' . ($data['title'] ?? '');
                $allText .= ' ' . ($data['description'] ?? '');
                if (!empty($data['bullets'])) {
                    $allText .= ' ' . implode(' ', $data['bullets']);
                }
            }

            // Tokenization
            $allText = mb_strtolower($allText, 'UTF-8');
            $allText = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $allText);
            $words = preg_split('/\s+/', $allText, -1, PREG_SPLIT_NO_EMPTY);

            // Remove stopwords
            $stopwords = $this->config['scraping']['stopwords_it'];
            $words = array_diff($words, $stopwords);

            // Filtra parole troppo corte
            $words = array_filter($words, function ($word) {
                return mb_strlen($word, 'UTF-8') >= $this->config['global']['min_keyword_length'] ?? 3;
            });

            // Calcola frequenza
            $frequency = array_count_values($words);
            arsort($frequency);

            // Calcola relevance score
            $keywords = [];
            $maxFreq = max($frequency);

            foreach (array_slice($frequency, 0, 30, true) as $word => $freq) {
                $score = round($freq / $maxFreq, 2);

                $keywords[] = [
                    'keyword' => $word,
                    'frequency' => $freq,
                    'relevance_score' => $score
                ];
            }

            // Salva in keyword library
            $this->saveKeywordsToLibrary($keywords, $asinList, $categoria);

            CentralLogger::info('ai_creator', 'Keywords extracted', [
                'user_id' => $this->userId,
                'categoria' => $categoria,
                'keywords_count' => count($keywords),
                'asins_analyzed' => count($asinList)
            ]);

            return [
                'success' => true,
                'keywords' => $keywords,
                'total_keywords' => count($keywords),
                'asins_analyzed' => $asinList
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Keyword extraction failed', [
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
     * Salva keywords in library per riuso futuro
     */
    private function saveKeywordsToLibrary($keywords, $asinList, $categoria)
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO ai_keyword_library 
                (user_id, categoria_amazon, keyword, frequency, relevance_score, competitor_asins, extracted_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    frequency = VALUES(frequency),
                    relevance_score = VALUES(relevance_score),
                    competitor_asins = VALUES(competitor_asins),
                    extracted_at = VALUES(extracted_at)
            ");

            $competitorAsinsJson = json_encode($asinList);

            foreach ($keywords as $kw) {
                $stmt->execute([
                    $this->userId,
                    $categoria,
                    $kw['keyword'],
                    $kw['frequency'],
                    $kw['relevance_score'],
                    $competitorAsinsJson
                ]);
            }

        } catch (Exception $e) {
            CentralLogger::warning('ai_creator', 'Failed to save keywords to library', [
                'error' => $e->getMessage()
            ]);
        }
    }

    // ============================================
    // LLM INTEGRATION (Claude + GPT-4)
    // ============================================

    /**
     * Genera contenuto campo singolo con LLM
     * NUOVO: Delega a AiContentGenerator orchestrator
     */
    public function generateFieldContent($fieldName, $context, $llmPreference = 'gpt4')
    {
        // Delega al nuovo AiContentGenerator
        return $this->contentGenerator->generateField($fieldName, $context);
    }

    /**
     * Genera MULTIPLI campi in modo coordinato
     * NUOVO: Delega a AiContentGenerator per generazione multi-campo
     */
    public function generateMultipleFields($fieldNames, $context)
    {
        // Delega al nuovo AiContentGenerator per generazione coordinata
        return $this->contentGenerator->generateMultipleFields($fieldNames, $context);
    }

    /**
     * Test configurazione AI
     * NUOVO: Verifica setup completo (policy, LLM, validator)
     */
    public function testAiConfiguration()
    {
        return $this->contentGenerator->testConfiguration();
    }

    /**
     * Ottieni policy per campo specifico
     * NUOVO: Espone PolicyManager via AiContentGenerator
     */
    public function getPolicyForField($fieldName)
    {
        return $this->contentGenerator->getPolicyForField($fieldName);
    }

    /**
     * Valida contenuto esistente
     * NUOVO: Espone ContentValidator via AiContentGenerator
     */
    public function validateContent($fieldName, $content)
    {
        return $this->contentGenerator->validateContent($fieldName, $content);
    }

    /**
     * Chiamata API Anthropic Claude
     */
    private function callClaudeApi($prompt)
    {
        $apiKey = $this->config['llm']['anthropic_api_key'];

        if (empty($apiKey) || strpos($apiKey, 'YOUR_KEY_HERE') !== false) {
            throw new Exception('API key Anthropic non configurata');
        }

        $endpoint = $this->config['llm']['claude_endpoint'];
        $model = $this->config['llm']['claude_model'];
        $timeout = $this->config['llm']['timeout'];

        $payload = [
            'model' => $model,
            'max_tokens' => 16384, // AUMENTATO - no limiti
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . $this->config['llm']['claude_version'],
                'content-type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("Claude API cURL error: $curlError");
        }

        if ($httpCode !== 200) {
            throw new Exception("Claude API HTTP $httpCode: $response");
        }

        $data = json_decode($response, true);

        if (!isset($data['content'][0]['text'])) {
            throw new Exception("Claude API response malformed");
        }

        return [
            'success' => true,
            'content' => trim($data['content'][0]['text'])
        ];
    }

    /**
     * Chiamata API OpenAI GPT-4
     */
    private function callGpt4Api($prompt)
    {
        $apiKey = $this->config['llm']['openai_api_key'];

        if (empty($apiKey) || strpos($apiKey, 'YOUR_KEY_HERE') !== false) {
            throw new Exception('API key OpenAI non configurata');
        }

        $endpoint = $this->config['llm']['gpt_endpoint'];
        $model = $this->config['llm']['gpt_model'];
        $timeout = $this->config['llm']['timeout'];

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => $this->config['llm']['gpt_temperature']
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception("GPT-4 API cURL error: $curlError");
        }

        if ($httpCode !== 200) {
            throw new Exception("GPT-4 API HTTP $httpCode: $response");
        }

        $data = json_decode($response, true);

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new Exception("GPT-4 API response malformed");
        }

        return [
            'success' => true,
            'content' => trim($data['choices'][0]['message']['content'])
        ];
    }

    /**
     * Costruisci prompt per LLM basato su field e context
     */
    private function buildPromptForField($fieldName, $context, $policyRules)
    {
        $keywords = isset($context['keywords']) ? (is_array($context['keywords']) ? implode(', ', array_slice($context['keywords'], 0, 10)) : $context['keywords']) : '';
        $categoria = $context['categoria'] ?? 'Generic';
        $productType = $context['product_type'] ?? '';
        $weight = $context['weight'] ?? '';
        $country = $context['country'] ?? '';

        $rules = "Policy Rules:\n";
        foreach ($policyRules as $rule) {
            $cfg = json_decode($rule['rule_config'], true);
            if ($rule['rule_type'] === 'length') {
                $min = $cfg['min_length'] ?? $cfg['min'] ?? 0;
                $max = $cfg['max_length'] ?? $cfg['max'] ?? 1000;
                $rules .= "- Lunghezza: min {$min} char, max {$max} char\n";
            } elseif ($rule['rule_type'] === 'forbidden_words') {
                $words = $cfg['forbidden'] ?? $cfg['words'] ?? [];
                $rules .= "- Parole vietate: " . implode(', ', $words) . "\n";
            }
        }

        switch ($fieldName) {
            case 'title':
                return "Sei un copywriter Amazon esperto. Genera un titolo SEO-ottimizzato per marketplace italiano.

Context:
- Prodotto: {$productType}
- Categoria: {$categoria}
- Peso: {$weight}
- Origine: {$country}
- Keywords primarie: {$keywords}

{$rules}

Struttura titolo: [Prodotto] + [Peso] + [Origine] + [Benefit 1] + [Benefit 2] + [Keywords]

Task: Genera titolo ottimizzato che:
1. Include TUTTE keyword primarie naturalmente
2. Rispetta lunghezza policy
3. È persuasivo, chiaro, professionale
4. NON usa parole vietate
5. Segue sintassi italiana corretta

Output: SOLO il titolo, senza spiegazioni o virgolette.";

            case 'description':
                return "Sei un copywriter Amazon esperto. Genera descrizione prodotto con HTML per marketplace italiano.

Context:
- Prodotto: {$productType}
- Categoria: {$categoria}
- Keywords: {$keywords}

{$rules}

Task: Genera descrizione HTML che:
1. Inizia con <strong>Hook emotivo</strong>
2. Include lista benefici con <ul><li>
3. Usa keywords naturalmente
4. Max 1000 char plain text
5. HTML tags consentiti: <strong>, <br>, <ul>, <li>, <p>

Output: SOLO HTML, senza markdown o backticks.";

            case 'bullet_point':
                $index = $context['bullet_index'] ?? 1;
                return "Sei un copywriter Amazon esperto. Genera bullet point #{$index} per marketplace italiano.

Context:
- Prodotto: {$productType}
- Keywords: {$keywords}

{$rules}

Task: Genera bullet point che:
1. Inizia con emoji pertinente
2. Evidenzia UN beneficio specifico
3. Max 500 char
4. È conciso e persuasivo

Output: SOLO il bullet point, senza numero o prefisso.";

            default:
                return "Genera contenuto per campo {$fieldName}.\nContext: " . json_encode($context);
        }
    }

    /**
     * Mock content per testing senza API
     */
    private function getMockContent($fieldName, $context)
    {
        $mockData = $this->config['mock'];

        $defaultMock = [
            'title' => $mockData['fake_title'] ?? 'Prodotto Premium 500g | Origine USA | Qualità Superiore',
            'description' => $mockData['fake_description'] ?? '<strong>Descrizione Prodotto</strong><br>Contenuto mock generato.',
            'bullet_point' => '✨ Caratteristica Premium - Descrizione beneficio principale'
        ];

        return [
            'success' => true,
            'content' => $defaultMock[$fieldName] ?? 'Mock content',
            'validation' => ['valid' => true, 'errors' => [], 'warnings' => []],
            'llm_used' => 'mock'
        ];
    }

    // ============================================
    // POLICY VALIDATION ENGINE
    // ============================================

    /**
     * Carica policy rules da DB per field specifico
     */
    private function loadPolicyRulesForField($fieldName, $categoria = null)
    {
        try {
            $sql = "SELECT * FROM ai_policy_rules 
                    WHERE field_name = ? AND is_active = 1";

            $params = [$fieldName];

            if ($categoria) {
                $sql .= " AND (categoria_amazon = ? OR categoria_amazon IS NULL)";
                $params[] = $categoria;
            } else {
                $sql .= " AND categoria_amazon IS NULL";
            }

            $sql .= " ORDER BY priority ASC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            CentralLogger::warning('ai_creator', 'Failed to load policy rules', [
                'field' => $fieldName,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Valida contenuto campo contro policy rules
     */
    public function validateFieldContent($fieldName, $content, $policyRules = null)
    {
        if ($policyRules === null) {
            $policyRules = $this->loadPolicyRulesForField($fieldName);
        }

        $errors = [];
        $warnings = [];
        $suggestions = [];
        $autoFixable = true;

        foreach ($policyRules as $rule) {
            $ruleConfig = json_decode($rule['rule_config'], true);

            switch ($rule['rule_type']) {
                case 'length':
                    $length = mb_strlen(strip_tags($content), 'UTF-8');
                    $min = $ruleConfig['min_length'] ?? $ruleConfig['min'] ?? 0;
                    $max = $ruleConfig['max_length'] ?? $ruleConfig['max'] ?? PHP_INT_MAX;

                    if ($length < $min) {
                        $errors[] = "Lunghezza minima: $min caratteri (attuale: $length)";
                        $autoFixable = false;
                    } elseif ($length > $max) {
                        $errors[] = "Lunghezza massima: $max caratteri (attuale: $length)";
                        $suggestions[] = "Auto-fix: Tronca a $max caratteri";
                    }
                    break;

                case 'forbidden_words':
                    $forbiddenWords = $ruleConfig['forbidden'] ?? $ruleConfig['words'] ?? [];
                    $contentLower = mb_strtolower($content, 'UTF-8');

                    foreach ($forbiddenWords as $word) {
                        if (strpos($contentLower, mb_strtolower($word, 'UTF-8')) !== false) {
                            $errors[] = "Parola vietata trovata: '$word'";
                            $suggestions[] = "Auto-fix: Rimuovi '$word'";
                        }
                    }
                    break;

                case 'html_tags':
                    $allowedTags = $ruleConfig['allowed_html'] ?? $ruleConfig['allowed_tags'] ?? [];
                    $allowedTagsStr = empty($allowedTags) ? '' : '<' . implode('><', $allowedTags) . '>';
                    $stripped = strip_tags($content, $allowedTagsStr);

                    if ($stripped !== $content) {
                        $warnings[] = "HTML tags non consentiti presenti";
                        $suggestions[] = "Auto-fix: Rimuovi tags non consentiti";
                    }
                    break;

                case 'format':
                    $pattern = $ruleConfig['pattern'] ?? '';
                    if (!empty($pattern) && !preg_match($pattern, $content)) {
                        $errors[] = "Formato non valido";
                        $autoFixable = false;
                    }
                    break;
            }
        }

        $isValid = empty($errors);

        return [
            'valid' => $isValid,
            'errors' => $errors,
            'warnings' => $warnings,
            'suggestions' => $suggestions,
            'auto_fixable' => $autoFixable && !empty($errors)
        ];
    }

    /**
     * Auto-fix contenuto based on validation errors
     */
    public function autoFixContent($fieldName, $content, $validationResult)
    {
        if (!$validationResult['auto_fixable']) {
            return ['success' => false, 'error' => 'Content not auto-fixable'];
        }

        $fixed = $content;
        $policyRules = $this->loadPolicyRulesForField($fieldName);

        foreach ($policyRules as $rule) {
            $ruleConfig = json_decode($rule['rule_config'], true);

            switch ($rule['rule_type']) {
                case 'length':
                    $max = $ruleConfig['max_length'] ?? $ruleConfig['max'] ?? PHP_INT_MAX;
                    $plainText = strip_tags($fixed);

                    if (mb_strlen($plainText, 'UTF-8') > $max) {
                        $fixed = mb_substr($plainText, 0, $max, 'UTF-8');
                        $lastSpace = mb_strrpos($fixed, ' ', 0, 'UTF-8');
                        if ($lastSpace !== false) {
                            $fixed = mb_substr($fixed, 0, $lastSpace, 'UTF-8');
                        }
                    }
                    break;

                case 'forbidden_words':
                    $forbiddenWords = $ruleConfig['forbidden'] ?? $ruleConfig['words'] ?? [];
                    foreach ($forbiddenWords as $word) {
                        $fixed = preg_replace('/\b' . preg_quote($word, '/') . '\b/iu', '', $fixed);
                    }
                    break;

                case 'html_tags':
                    $allowedTags = $ruleConfig['allowed_html'] ?? $ruleConfig['allowed_tags'] ?? [];
                    $allowedTagsStr = empty($allowedTags) ? '' : '<' . implode('><', $allowedTags) . '>';
                    $fixed = strip_tags($fixed, $allowedTagsStr);
                    break;
            }
        }

        $fixed = preg_replace('/\s+/', ' ', $fixed);
        $fixed = trim($fixed);

        return [
            'success' => true,
            'fixed_content' => $fixed,
            'original_content' => $content
        ];
    }

    // ============================================
    // EXCEL EXPORT
    // ============================================

    /**
     * Genera Excel finale da sessione completata
     */
    public function exportExcel($sessionId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT s.*, t.filepath as template_path, t.metadata as template_metadata
                FROM ai_chat_sessions s
                JOIN ai_templates t ON s.template_id = t.id
                WHERE s.session_uuid = ? AND s.user_id = ?
            ");
            $stmt->execute([$sessionId, $this->userId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new Exception('Session not found');
            }

            $workingData = json_decode($session['working_data'], true);
            $templateMetadata = json_decode($session['template_metadata'], true);
            $columnMapping = $templateMetadata['column_mapping'] ?? [];

            if (!file_exists($session['template_path'])) {
                throw new Exception('Template file not found');
            }

            $spreadsheet = $this->loadExcelSafe($session['template_path']);
            $worksheet = $spreadsheet->getActiveSheet();

            $dataRow = $templateMetadata['first_data_row'] ?? 5;

            foreach ($columnMapping as $field => $columnLetter) {
                $value = $workingData[$field] ?? '';

                if (is_array($value)) {
                    $value = implode("\n", $value);
                }

                $worksheet->setCellValue($columnLetter . $dataRow, $value);
            }

            $exportDir = $this->config['paths']['exports'] . $this->userId . '/';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0755, true);
            }

            $ean = $workingData['ean'] ?? 'unknown';
            $timestamp = time();
            $exportFilename = "product_{$ean}_{$timestamp}.xlsx";
            $exportPath = $exportDir . $exportFilename;

            $writer = new Xlsx($spreadsheet);
            $writer->save($exportPath);

            $this->saveProductToDatabase($workingData);

            $stmt = $this->pdo->prepare("
                UPDATE ai_chat_sessions
                SET current_state = 'completed', export_path = ?, updated_at = NOW()
                WHERE session_uuid = ?
            ");
            $stmt->execute([$exportPath, $sessionId]);

            CentralLogger::info('ai_creator', 'Excel exported', [
                'user_id' => $this->userId,
                'session_id' => $sessionId,
                'ean' => $ean,
                'file' => $exportFilename
            ]);

            return [
                'success' => true,
                'export_path' => $exportPath,
                'export_filename' => $exportFilename,
                'download_url' => "/modules/margynomic/admin/creaexcel/ai/api/download.php?session_id={$sessionId}"
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Excel export failed', [
                'user_id' => $this->userId,
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Salva prodotto in database products
     */
    private function saveProductToDatabase($productData)
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO products 
                (user_id, sku, ean, ean_status, nome, ean_generated_at, creato_il, aggiornato_il)
                VALUES (?, ?, ?, 'utilizzato', ?, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    nome = VALUES(nome),
                    ean_status = 'utilizzato',
                    aggiornato_il = NOW()
            ");

            $stmt->execute([
                $this->userId,
                $productData['sku'] ?? 'AI-' . substr($productData['ean'], -8),
                $productData['ean'],
                $productData['title'] ?? 'Prodotto AI'
            ]);

            CentralLogger::info('ai_creator', 'Product saved to database', [
                'user_id' => $this->userId,
                'ean' => $productData['ean']
            ]);

        } catch (Exception $e) {
            CentralLogger::warning('ai_creator', 'Failed to save product to database', [
                'error' => $e->getMessage()
            ]);
        }
    }

    // ============================================
    // SESSION MANAGEMENT
    // ============================================

    /**
     * Crea nuova sessione chat AI
     */
    public function createSession($templateId, $initialData = [])
    {
        try {
            $eanResult = $this->generateEan();
            if (!$eanResult['success']) {
                throw new Exception('Failed to generate EAN: ' . $eanResult['error']);
            }

            $ean = $eanResult['ean'];
            $sessionUuid = $this->generateUuid();

            $workingData = array_merge([
                'ean' => $ean,
                'created_at' => date('Y-m-d H:i:s')
            ], $initialData);

            $stmt = $this->pdo->prepare("
                INSERT INTO ai_chat_sessions
                (session_uuid, user_id, template_id, ean, current_state, working_data, conversation_history, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'init', ?, '[]', NOW(), NOW())
            ");

            $stmt->execute([
                $sessionUuid,
                $this->userId,
                $templateId,
                $ean,
                json_encode($workingData, JSON_UNESCAPED_UNICODE)
            ]);

            CentralLogger::info('ai_creator', 'Session created', [
                'user_id' => $this->userId,
                'session_uuid' => $sessionUuid,
                'ean' => $ean
            ]);

            return [
                'success' => true,
                'session_id' => $sessionUuid,
                'ean' => $ean,
                'state' => 'init'
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Session creation failed', [
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
     * Aggiorna session state
     */
    public function updateSessionState($sessionId, $newState, $workingData = null, $conversationEntry = null)
    {
        try {
            $updates = ['current_state = ?', 'updated_at = NOW()'];
            $params = [$newState];

            if ($workingData !== null) {
                $updates[] = 'working_data = ?';
                $params[] = json_encode($workingData, JSON_UNESCAPED_UNICODE);
            }

            if ($conversationEntry !== null) {
                $stmt = $this->pdo->prepare("
                    SELECT conversation_history FROM ai_chat_sessions WHERE session_uuid = ?
                ");
                $stmt->execute([$sessionId]);
                $current = $stmt->fetchColumn();
                $history = json_decode($current, true) ?? [];
                $history[] = $conversationEntry;

                $updates[] = 'conversation_history = ?';
                $params[] = json_encode($history, JSON_UNESCAPED_UNICODE);
            }

            $sql = "UPDATE ai_chat_sessions SET " . implode(', ', $updates) . " WHERE session_uuid = ? AND user_id = ?";
            $params[] = $sessionId;
            $params[] = $this->userId;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return ['success' => true];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Session update failed', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Carica session esistente
     */
    public function loadSession($sessionId)
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM ai_chat_sessions WHERE session_uuid = ? AND user_id = ?
            ");
            $stmt->execute([$sessionId, $this->userId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new Exception('Session not found');
            }

            $session['working_data'] = json_decode($session['working_data'], true);
            $session['conversation_history'] = json_decode($session['conversation_history'], true);

            return [
                'success' => true,
                'session' => $session
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update ASIN post-upload Amazon
     */
    public function updateProductAsin($ean, $asin)
    {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE products
                SET asin = ?, ean_status = 'caricato', aggiornato_il = NOW()
                WHERE ean = ? AND user_id = ?
            ");

            $stmt->execute([$asin, $ean, $this->userId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Product with EAN not found');
            }

            CentralLogger::info('ai_creator', 'ASIN updated', [
                'user_id' => $this->userId,
                'ean' => $ean,
                'asin' => $asin
            ]);

            return ['success' => true];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'ASIN update failed', [
                'ean' => $ean,
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ============================================
    // HELPER METHODS
    // ============================================

    /**
     * Genera UUID v4
     */
    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    // ============================================
    // EXCEL EDITOR - ROW MANAGEMENT
    // ============================================

    /**
     * Carica tutte le righe prodotti da Excel file
     * 
     * @param string $filepath Path file Excel
     * @return array ['success', 'rows' => [...], 'metadata' => ...]
     */
    public function loadExcelRows($filepath)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);

            // Fix: Select "Modello" or "Template" sheet if present
            $sheetNames = $spreadsheet->getSheetNames();
            $targetSheetIndex = null;

            foreach ($sheetNames as $index => $name) {
                if (stripos($name, 'Template') !== false || stripos($name, 'Modello') !== false) {
                    $targetSheetIndex = $index;
                    break;
                }
            }

            if ($targetSheetIndex !== null) {
                $spreadsheet->setActiveSheetIndex($targetSheetIndex);
            }

            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Leggi headers SOLO da riga 3 (nome tecnico API)
            $headers = [];
            $headersForExtractor = []; // Formato speciale per DropdownExtractor
            
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                
                // Riga 3: nome tecnico (item_name, item_sku, etc.)
                $technicalValue = $worksheet->getCell($letter . '3')->getValue();
                
                // Converti oggetti in stringhe (fix per RichText headers)
                if (is_object($technicalValue)) {
                    if (method_exists($technicalValue, '__toString')) {
                        $technicalValue = (string)$technicalValue;
                    } elseif ($technicalValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $technicalValue = $technicalValue->getPlainText();
                    } else {
                        $technicalValue = (string)$technicalValue;
                    }
                }
                
                // Fix: handle null values
                if ($technicalValue === null) {
                    $technicalValue = '';
                }
                
                if (!empty($technicalValue)) {
                    $headers[$letter] = $technicalValue;
                    
                    // Formato per DropdownExtractor: usa SOLO nome tecnico (riga 3)
                    // NON usare riga 4 perché contiene DATI, non headers!
                    $headersForExtractor[$letter] = [
                        'original' => $technicalValue,
                        'normalized' => strtolower(trim($technicalValue))
                    ];
                }
            }

            // Crea mapping inverso PRIMA di leggere i dati
            // headers: letter => field_name ('A' => 'feed_product_type')
            // headersForJS: field_name => readable_label ('feed_product_type' => 'Feed Product Type')
            $headersForJS = [];
            foreach ($headers as $letter => $technicalName) {
                // Converti item_sku → Item Sku
                $readableLabel = ucwords(str_replace('_', ' ', $technicalName));
                $headersForJS[$technicalName] = $readableLabel;
            }

            // Leggi righe dati (dalla riga 4 in poi)
            $rows = [];

            for ($row = 4; $row <= $highestRow; $row++) {
                $rowData = [
                    'row_number' => $row,
                    'is_empty' => true,
                    'data' => []
                ];

                // USA $headers (letter => field_name) per leggere i dati
                foreach ($headers as $letter => $headerName) {
                    $cell = $worksheet->getCell($letter . $row);
                    $value = $cell->getValue();
                    
                    // Converti oggetti in stringhe
                    if (is_object($value)) {
                        if (method_exists($value, '__toString')) {
                            $value = (string)$value;
                        } elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $value = $value->getPlainText();
                        } elseif ($value instanceof \DateTime) {
                            $value = $value->format('Y-m-d H:i:s');
                        } else {
                            // Usa il valore formattato come fallback
                            $value = $cell->getFormattedValue();
                        }
                    }
                    
                    $rowData['data'][$headerName] = $value;

                    if (!empty($value)) {
                        $rowData['is_empty'] = false;
                    }
                }

                $rows[] = $rowData;
            }

            // Metadata (preserva ordine colonne Excel)
            $columnOrder = array_values($headers); // Mantiene l'ordine A, B, C, D...
            
            $metadata = [
                'total_rows' => $highestRow,
                'data_rows' => $highestRow - 3,
                'headers' => $headersForJS, // field_name => label (per JS: 'item_sku' in headers = true)
                'headers_mapping' => $headers, // letter => field_name (per backend: 'B' => 'item_sku')
                'column_order' => $columnOrder, // ['item_sku', 'brand_name', 'item_name', ...] in ordine Excel
                'highest_column' => $highestColumn,
                'filepath' => $filepath
            ];

            // Extract dropdown values
            $metadata['dropdown_values'] = [];
            try {
                if (!class_exists('DropdownExtractor')) {
                    throw new Exception('DropdownExtractor class not found');
                }
                
                $extractor = new DropdownExtractor();
                $dropdownsRaw = $extractor->extractDropdowns($worksheet, $headersForExtractor);
                
                // Converti da column letters a field names
                $dropdownsByField = [];
                
                if (is_array($dropdownsRaw)) {
                    foreach ($dropdownsRaw as $columnLetter => $data) {
                        if (is_array($data) && !empty($data['values']) && isset($headers[$columnLetter])) {
                            $fieldName = $headers[$columnLetter]; // Es: 'parent_child'
                            $dropdownsByField[$fieldName] = $data['values'];
                        }
                    }
                }
                
                $metadata['dropdown_values'] = $dropdownsByField;
                
                CentralLogger::info('ai_creator', 'Dropdowns extracted', [
                    'user_id' => $this->userId,
                    'dropdown_count' => count($dropdownsByField),
                    'fields_with_dropdowns' => array_keys($dropdownsByField),
                    'sample' => !empty($dropdownsByField) ? array_slice($dropdownsByField, 0, 3, true) : []
                ]);
                
            } catch (Exception $e) {
                CentralLogger::warning('ai_creator', 'Dropdown extraction failed', [
                    'error' => $e->getMessage(),
                    'file' => basename($filepath),
                    'line' => $e->getLine()
                ]);
                // Continue anche se dropdown fallisce
            }
            
            // Debug log finale
            CentralLogger::info('ai_creator', 'Metadata prepared', [
                'headers_count' => count($headers),
                'dropdown_fields' => count($metadata['dropdown_values']),
                'sample_dropdown' => !empty($metadata['dropdown_values']) ? array_slice($metadata['dropdown_values'], 0, 2, true) : []
            ]);

            CentralLogger::info('ai_creator', 'Excel rows loaded', [
                'user_id' => $this->userId,
                'file' => basename($filepath),
                'total_rows' => count($rows),
                'filled_rows' => count(array_filter($rows, fn($r) => !$r['is_empty']))
            ]);

            // AUTO-VERIFICA SKU e colora celle verdi
            $skuVerification = $this->verifyAndColorSkus($filepath);
            $metadata['sku_verification'] = $skuVerification;

            return [
                'success' => true,
                'rows' => $rows,
                'metadata' => $metadata
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Load Excel rows failed', [
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
     * Carica dati singola riga per editing
     * 
     * @param string $filepath Path file Excel
     * @param int $rowNumber Numero riga (4, 5, 6...)
     * @return array ['success', 'data' => [...]]
     */
    public function getRow($filepath, $rowNumber)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Leggi header riga 3
            $headers = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $value = $worksheet->getCell($letter . '3')->getValue();
                
                // Converti oggetti in stringhe (fix per RichText headers)
                if (is_object($value)) {
                    if (method_exists($value, '__toString')) {
                        $value = (string)$value;
                    } elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $value = $value->getPlainText();
                    } else {
                        $value = (string)$value;
                    }
                }
                
                if (!empty($value)) {
                    $headers[$letter] = $value;
                }
            }

            // Leggi dati riga specifica
            $rowData = [];
            foreach ($headers as $letter => $headerName) {
                $cell = $worksheet->getCell($letter . $rowNumber);
                $value = $cell->getValue();
                
                // Converti oggetti in stringhe
                if (is_object($value)) {
                    if (method_exists($value, '__toString')) {
                        $value = (string)$value;
                    } elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $value = $value->getPlainText();
                    } elseif ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d H:i:s');
                    } else {
                        $value = $cell->getFormattedValue();
                    }
                }
                
                $rowData[$headerName] = $value;
            }

            return [
                'success' => true,
                'row_number' => $rowNumber,
                'data' => $rowData,
                'headers' => $headers
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Salva modifiche a singola riga Excel
     * 
     * @param string $filepath Path file Excel
     * @param int $rowNumber Numero riga da modificare
     * @param array $rowData Dati riga ['item_sku' => '...', 'item_name' => '...', ...]
     * @return array ['success', 'filepath']
     */
    public function saveRow($filepath, $rowNumber, $rowData)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
                $worksheet->getHighestColumn()
            );

            // Leggi mapping header (riga 3) → column letter
            $headerMapping = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headerCell = $worksheet->getCell($letter . '3');
                $headerName = $headerCell->getValue();
                
                // Converti oggetti in stringhe
                if (is_object($headerName)) {
                    if (method_exists($headerName, '__toString')) {
                        $headerName = (string)$headerName;
                    } elseif ($headerName instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $headerName = $headerName->getPlainText();
                    } else {
                        $headerName = $headerCell->getFormattedValue();
                    }
                }
                
                if (!empty($headerName)) {
                    $headerMapping[$headerName] = $letter;
                }
            }

            // Debug specifico per item_sku
            $itemSkuColumn = $headerMapping['item_sku'] ?? null;
            $itemSkuValue = $rowData['item_sku'] ?? null;
            
            error_log("========== SAVE ROW DEBUG ==========");
            error_log("Row number: $rowNumber");
            error_log("item_sku column letter: " . ($itemSkuColumn ?: 'NOT FOUND'));
            error_log("item_sku value from JS: " . ($itemSkuValue ?: '(empty)'));
            error_log("item_sku cell address: " . ($itemSkuColumn ? $itemSkuColumn . $rowNumber : 'N/A'));
            
            CentralLogger::info('ai_creator', 'SaveRow - Header mapping', [
                'headers_count' => count($headerMapping),
                'headers_sample' => array_slice($headerMapping, 0, 10, true),
                'row_data_fields' => array_keys($rowData),
                'row_data_sample' => array_slice($rowData, 0, 5, true),
                'item_sku_column' => $itemSkuColumn,
                'item_sku_value' => $itemSkuValue
            ]);

            // Scrivi dati nella riga
            $fieldsWritten = 0;
            foreach ($rowData as $fieldName => $value) {
                if (isset($headerMapping[$fieldName])) {
                    $letter = $headerMapping[$fieldName];
                    $worksheet->setCellValue($letter . $rowNumber, $value);
                    $fieldsWritten++;
                    
                    // Log solo per item_sku
                    if ($fieldName === 'item_sku') {
                        error_log("✅ Writing item_sku: '$value' to cell {$letter}{$rowNumber}");
                    }
                } else {
                    // Log solo campi importanti
                    if (in_array($fieldName, ['item_sku', 'item_name', 'brand_name'])) {
                        error_log("❌ Field '$fieldName' NOT FOUND in header mapping!");
                    }
                }
            }

            // Verifica PRIMA di salvare
            $beforeSave = [];
            if (isset($headerMapping['item_sku'])) {
                $itemSkuColumn = $headerMapping['item_sku'];
                $beforeSave['item_sku'] = $worksheet->getCell($itemSkuColumn . $rowNumber)->getValue();
            }

            // Salva file
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);
            
            error_log("✅ File saved to: $filepath");

            // ✅ VERIFICA POST-SAVE DISABILITATA per performance
            // La ricarica del file Excel è pesante e può causare timeout
            // Verifica solo che i dati siano stati scritti correttamente in memoria
            $verification = [
                'item_sku_before' => $beforeSave['item_sku'] ?? null,
                'item_sku_sent_from_js' => $rowData['item_sku'] ?? null,
                'verification_method' => 'memory_only (no reload)'
            ];
            
            error_log("🔍 VERIFICATION (memory only, no reload):");
            error_log("  - Before save: " . ($beforeSave['item_sku'] ?? '(empty)'));
            error_log("  - Sent from JS: " . ($rowData['item_sku'] ?? '(empty)'));

            CentralLogger::info('ai_creator', 'Row saved successfully', [
                'user_id' => $this->userId,
                'file' => basename($filepath),
                'row' => $rowNumber,
                'fields_written' => $fieldsWritten,
                'total_fields' => count($rowData),
                'verification' => $verification,
                'note' => 'Post-save reload disabled for performance'
            ]);

            return [
                'success' => true,
                'filepath' => $filepath,
                'row_number' => $rowNumber
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Save row failed', [
                'user_id' => $this->userId,
                'row' => $rowNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Aggiungi nuova riga prodotto
     * 
     * @param string $filepath Path file Excel
     * @param array $rowData Dati nuova riga
     * @param bool $generateEan Se true, genera EAN automaticamente
     * @return array ['success', 'row_number', 'ean']
     */
    public function addRow($filepath, $rowData, $generateEan = true)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            // Genera EAN se richiesto e non fornito
            if ($generateEan && empty($rowData['external_product_id'])) {
                $eanResult = $this->generateEan();
                if ($eanResult['success']) {
                    $rowData['external_product_id'] = $eanResult['ean'];
                    $rowData['external_product_id_type'] = 'EAN';
                }
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            // Trova prima riga vuota (dalla riga 4 in poi)
            $highestRow = $worksheet->getHighestRow();
            $firstEmptyRow = null;

            for ($row = 4; $row <= $highestRow + 1; $row++) {
                $isEmpty = true;
                $cellA = $worksheet->getCell('A' . $row)->getValue();
                $cellB = $worksheet->getCell('B' . $row)->getValue();

                if (!empty($cellA) || !empty($cellB)) {
                    $isEmpty = false;
                }

                if ($isEmpty) {
                    $firstEmptyRow = $row;
                    break;
                }
            }

            if (!$firstEmptyRow) {
                $firstEmptyRow = $highestRow + 1;
            }

            // Salva nella riga vuota
            $saveResult = $this->saveRow($filepath, $firstEmptyRow, $rowData);

            if (!$saveResult['success']) {
                throw new Exception($saveResult['error']);
            }

            return [
                'success' => true,
                'row_number' => $firstEmptyRow,
                'ean' => $rowData['external_product_id'] ?? null,
                'filepath' => $filepath
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Elimina riga prodotto (svuota celle)
     * 
     * @param string $filepath Path file Excel
     * @param int $rowNumber Numero riga da eliminare
     * @return array ['success']
     */
    public function deleteRow($filepath, $rowNumber)
    {
        try {
            if ($rowNumber < 4) {
                throw new Exception('Non puoi eliminare righe metadata (1-3)');
            }

            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            // Svuota tutte le celle della riga
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $worksheet->setCellValue($letter . $rowNumber, null);
            }

            // Salva file
            $writer = new Xlsx($spreadsheet);
            $writer->save($filepath);

            CentralLogger::info('ai_creator', 'Row deleted', [
                'user_id' => $this->userId,
                'file' => basename($filepath),
                'row' => $rowNumber
            ]);

            return [
                'success' => true,
                'row_number' => $rowNumber
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Valida tutte le righe prodotti
     * 
     * @param string $filepath Path file Excel
     * @return array ['success', 'validation_results' => [...]]
     */
    public function validateAllRows($filepath)
    {
        try {
            $loadResult = $this->loadExcelRows($filepath);

            if (!$loadResult['success']) {
                throw new Exception($loadResult['error']);
            }

            $rows = $loadResult['rows'];
            $validationResults = [];

            foreach ($rows as $row) {
                if ($row['is_empty']) {
                    continue;
                }

                $rowValidation = [
                    'row_number' => $row['row_number'],
                    'fields' => []
                ];

                // Valida campi principali
                $fieldsToValidate = ['item_name', 'item_sku', 'external_product_id'];

                foreach ($fieldsToValidate as $field) {
                    $content = $row['data'][$field] ?? '';
                    $validation = $this->validateFieldContent($field, $content);
                    $rowValidation['fields'][$field] = $validation;
                }

                $validationResults[] = $rowValidation;
            }

            return [
                'success' => true,
                'validation_results' => $validationResults,
                'total_validated' => count($validationResults)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Debug: Controlla direttamente inventory per vedere cosa c'è
     * 
     * @param string $sku
     * @return string Debug info
     */
    private function debugDirectInventoryCheck($sku)
    {
        try {
            // Controlla TUTTI gli SKU che iniziano con le prime lettere (per user corrente)
            $prefix = substr($sku, 0, 5);
            $stmt = $this->pdo->prepare("
                SELECT sku, product_id, user_id, LENGTH(sku) as sku_length, HEX(sku) as sku_hex
                FROM inventory 
                WHERE user_id = ? AND sku LIKE ?
                LIMIT 3
            ");
            $stmt->execute([$this->userId, $prefix . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($results)) {
                // Prova cross-user per vedere se esiste per altri user
                $stmt = $this->pdo->prepare("
                    SELECT sku, product_id, user_id
                    FROM inventory 
                    WHERE sku LIKE ?
                    LIMIT 3
                ");
                $stmt->execute([$prefix . '%']);
                $crossUserResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($crossUserResults)) {
                    return 'No SKUs starting with "' . $prefix . '" found in inventory (any user)';
                }
                
                $debug = [];
                foreach ($crossUserResults as $r) {
                    $match = ($r['sku'] === $sku) ? 'EXACT MATCH!' : 'similar';
                    $debug[] = "'{$r['sku']}' (user:{$r['user_id']}, pid:{$r['product_id']}) - {$match} [WRONG USER!]";
                }
                
                return implode(' | ', $debug);
            }
            
            $debug = [];
            foreach ($results as $r) {
                $match = ($r['sku'] === $sku) ? 'EXACT MATCH!' : 'different';
                $debug[] = "'{$r['sku']}' (len:{$r['sku_length']}, pid:{$r['product_id']}, hex:{$r['sku_hex']}) - {$match}";
            }
            
            return implode(' | ', $debug);
            
        } catch (PDOException $e) {
            return 'Query error: ' . $e->getMessage();
        }
    }

    /**
     * Cerca matching parziali per debug (LIKE search)
     * 
     * @param string $sku
     * @return string Descrizione match parziali trovati
     */
    private function findPartialSkuMatches($sku)
    {
        // Estrai solo i primi caratteri o numeri per matching parziale
        $skuPattern = '%' . substr($sku, 0, 10) . '%';
        
        $matches = [];
        
        // Cerca solo in inventory per performance (le altre tabelle hanno stessa logica)
        try {
            $stmt = $this->pdo->prepare("
                SELECT sku, product_id 
                FROM inventory 
                WHERE user_id = ? AND (sku LIKE ? OR sku LIKE ?)
                LIMIT 3
            ");
            $stmt->execute([$this->userId, $skuPattern, '%' . trim($sku) . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($results)) {
                foreach ($results as $r) {
                    $matches[] = "inventory: '{$r['sku']}' (pid:{$r['product_id']})";
                }
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        // Cerca anche in products per nome
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, sku, nome 
                FROM products 
                WHERE user_id = ? AND (sku LIKE ? OR nome LIKE ?)
                LIMIT 3
            ");
            $stmt->execute([$this->userId, $skuPattern, '%' . trim($sku) . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($results)) {
                foreach ($results as $r) {
                    $matches[] = "products: '{$r['sku']}'/'{$r['nome']}' (id:{$r['id']})";
                }
            }
        } catch (PDOException $e) {
            // Ignore
        }
        
        return empty($matches) ? 'No partial matches' : implode('; ', $matches);
    }

    /**
     * Trova product_id per uno SKU cercando in tutte le tabelle del sistema
     * Usa la stessa logica di sku_aggregation_interface.php
     * 
     * @param string $sku
     * @return int|null product_id o null se non trovato
     */
    private function findProductIdBySku($sku)
    {
        $settlementTable = "report_settlement_{$this->userId}";
        
        // Definisci le tabelle e colonne da controllare (stesso ordine di sku_aggregation_interface.php)
        $tablesToCheck = [
            'inventory' => [
                'sku_column' => 'sku',
                'check_sql' => "SELECT product_id FROM inventory WHERE user_id = ? AND sku = ? AND product_id IS NOT NULL AND product_id > 0 LIMIT 1",
                'params' => [$this->userId, $sku]
            ],
            'inventory_fbm' => [
                'sku_column' => 'seller_sku',
                'check_sql' => "SELECT product_id FROM inventory_fbm WHERE user_id = ? AND seller_sku = ? AND product_id IS NOT NULL AND product_id > 0 LIMIT 1",
                'params' => [$this->userId, $sku]
            ],
            'inbound_shipment_items' => [
                'sku_column' => 'seller_sku',
                'check_sql' => "SELECT product_id FROM inbound_shipment_items WHERE user_id = ? AND seller_sku = ? AND product_id IS NOT NULL AND product_id > 0 LIMIT 1",
                'params' => [$this->userId, $sku]
            ],
            'removal_orders' => [
                'sku_column' => 'sku',
                'check_sql' => "SELECT product_id FROM removal_orders WHERE user_id = ? AND sku = ? AND product_id IS NOT NULL AND product_id > 0 LIMIT 1",
                'params' => [$this->userId, $sku]
            ],
            'settlement' => [
                'sku_column' => 'sku',
                'check_sql' => "SELECT product_id FROM `{$settlementTable}` WHERE sku = ? AND product_id IS NOT NULL AND product_id > 0 LIMIT 1",
                'params' => [$sku]  // Settlement non ha user_id
            ],
            'shipments_trid' => [
                'sku_column' => 'msku',
                'check_sql' => "SELECT product_id FROM shipments_trid WHERE user_id = ? AND msku = ? AND product_id IS NOT NULL AND product_id > 0 LIMIT 1",
                'params' => [$this->userId, $sku]
            ]
        ];
        
        // Cerca in ogni tabella finché non trovi un product_id
        foreach ($tablesToCheck as $tableName => $config) {
            try {
                $stmt = $this->pdo->prepare($config['check_sql']);
                $stmt->execute($config['params']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && isset($result['product_id']) && $result['product_id'] > 0) {
                    // Trovato! Registra quale tabella ha fornito il match
                    CentralLogger::info('ai_creator', 'SKU found via mapping', [
                        'user_id' => $this->userId,
                        'sku' => $sku,
                        'product_id' => $result['product_id'],
                        'source_table' => $tableName
                    ]);
                    
                    return (int)$result['product_id'];
                }
                
            } catch (PDOException $e) {
                // Tabella potrebbe non esistere (es: settlement per user diverso)
                // Continua a cercare nelle altre tabelle
                CentralLogger::warning('ai_creator', 'Table check failed during SKU search', [
                    'user_id' => $this->userId,
                    'sku' => $sku,
                    'table' => $tableName,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        // Non trovato in nessuna tabella
        return null;
    }

    /**
     * Sincronizza prezzi da database products
     * 
     * @param string $filepath Path file Excel
     * @return array ['success', 'total_rows', 'updated_count', 'not_found_count', 'skipped_parent_count']
     */
    public function syncPricesFromDatabase($filepath)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Leggi headers da riga 3
            $headers = [];
            $skuColumn = null;
            $priceColumn = null;
            $parentChildColumn = null;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headerValue = $worksheet->getCell($letter . '3')->getValue();
                
                if ($headerValue) {
                    $headerValue = strtolower(trim((string)$headerValue));
                    $headers[$letter] = $headerValue;
                    
                    if ($headerValue === 'item_sku') {
                        $skuColumn = $letter;
                    } elseif ($headerValue === 'standard_price') {
                        $priceColumn = $letter;
                    } elseif ($headerValue === 'parent_child') {
                        $parentChildColumn = $letter;
                    }
                }
            }

            if (!$skuColumn) {
                throw new Exception('Colonna item_sku non trovata nell\'Excel');
            }

            if (!$priceColumn) {
                throw new Exception('Colonna standard_price non trovata nell\'Excel');
            }

            CentralLogger::info('ai_creator', 'Price sync columns found', [
                'sku_column' => $skuColumn,
                'price_column' => $priceColumn,
                'parent_child_column' => $parentChildColumn ?: 'not found'
            ]);

            // Contatori
            $stats = [
                'total_rows' => 0,
                'updated_count' => 0,
                'not_found_count' => 0,
                'skipped_parent_count' => 0,
                'skipped_empty_count' => 0,
                'updated_rows' => [] // Array di numeri riga aggiornati
            ];

            // Debug: log primi 5 SKU processati
            $debugSamples = [];
            $sampleCount = 0;

            // Processa righe dati (dalla riga 4 in poi)
            for ($row = 4; $row <= $highestRow; $row++) {
                $stats['total_rows']++;
                
                // Leggi SKU
                $sku = $worksheet->getCell($skuColumn . $row)->getValue();
                
                if (empty($sku)) {
                    $stats['skipped_empty_count']++;
                    continue;
                }
                
                // Converti a stringa e normalizza
                $sku = (string)$sku;
                $skuOriginal = $sku; // Salva originale per debug
                
                // Normalizza: trim, rimuovi spazi multipli, normalizza Unicode
                $sku = trim($sku);
                $sku = preg_replace('/\s+/', ' ', $sku); // Spazi multipli → singolo
                $sku = mb_convert_encoding($sku, 'UTF-8', 'UTF-8'); // Fix encoding issues
                
                // Check se è Parent (skip prezzi per Parent)
                if ($parentChildColumn) {
                    $parentChild = $worksheet->getCell($parentChildColumn . $row)->getValue();
                    if ($parentChild && strtolower(trim((string)$parentChild)) === 'parent') {
                        if ($sampleCount < 5) {
                            $debugSamples[] = [
                                'row' => $row,
                                'sku' => $sku,
                                'action' => 'SKIPPED (Parent)'
                            ];
                            $sampleCount++;
                        }
                        $stats['skipped_parent_count']++;
                        continue;
                    }
                }
                
                // Query database per prezzo usando sistema di mapping cross-tabelle
                // Cerca product_id nelle 6 tabelle principali, poi prendi prezzo da products
                $productId = $this->findProductIdBySku($sku);
                
                if (!$productId) {
                    if ($sampleCount < 5) {
                        // Prova matching parziale per debug
                        $partialMatches = $this->findPartialSkuMatches($sku);
                        
                        // Prova anche query diretta inventory per debug
                        $directCheck = $this->debugDirectInventoryCheck($sku);
                        
                        $debugSamples[] = [
                            'row' => $row,
                            'excel_sku' => $sku,
                            'excel_sku_original' => $skuOriginal,
                            'excel_sku_length' => strlen($sku),
                            'excel_sku_bytes' => bin2hex($sku),
                            'tables_checked' => 'inventory, inventory_fbm, inbound_shipment_items, removal_orders, settlement, shipments_trid',
                            'partial_matches_found' => $partialMatches,
                            'direct_inventory_check' => $directCheck,
                            'action' => 'NOT FOUND - exact match failed'
                        ];
                        $sampleCount++;
                    }
                    $stats['not_found_count']++;
                    continue;
                }
                
                // Ora prendi il prezzo da products usando product_id
                $stmt = $this->pdo->prepare("
                    SELECT prezzo_attuale, sku, nome
                    FROM products 
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$productId]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Leggi prezzo corrente Excel
                $currentPrice = $worksheet->getCell($priceColumn . $row)->getValue();
                
                if (!$product || $product['prezzo_attuale'] === null) {
                    if ($sampleCount < 5) {
                        $debugSamples[] = [
                            'row' => $row,
                            'excel_sku' => $sku,
                            'excel_price' => $currentPrice,
                            'db_product' => $product ? 'found but no price' : 'not found',
                            'action' => 'NOT FOUND in DB'
                        ];
                        $sampleCount++;
                    }
                    $stats['not_found_count']++;
                    continue;
                }
                
                $dbPrice = (float)$product['prezzo_attuale'];
                
                // Formatta prezzo con punto (xx.yy)
                $formattedPrice = number_format($dbPrice, 2, '.', '');
                
                // Debug logging per primi 5 match
                if ($sampleCount < 5) {
                    $debugSamples[] = [
                        'row' => $row,
                        'excel_sku' => $sku,
                        'db_sku' => $product['sku'],
                        'db_nome' => $product['nome'],
                        'match_type' => ($product['sku'] === $sku) ? 'exact_sku' : 'nome_match',
                        'excel_price' => $currentPrice,
                        'db_price' => $dbPrice,
                        'formatted_price' => $formattedPrice,
                        'are_equal' => ($currentPrice == $formattedPrice),
                        'action' => ($currentPrice != $formattedPrice) ? 'UPDATED' : 'SKIPPED (same price)'
                    ];
                    $sampleCount++;
                }
                
                // Aggiorna solo se diverso
                if ($currentPrice != $formattedPrice) {
                    $worksheet->setCellValue($priceColumn . $row, $formattedPrice);
                    
                    // Colora cella di verde
                    $worksheet->getStyle($priceColumn . $row)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('C6EFCE'); // Verde chiaro
                    
                    $stats['updated_count']++;
                    $stats['updated_rows'][] = $row; // Track row number
                }
            }

            // Log debug samples
            CentralLogger::info('ai_creator', 'Price sync debug samples', [
                'user_id' => $this->userId,
                'samples' => $debugSamples
            ]);

            // Salva Excel modificato
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);

            CentralLogger::info('ai_creator', 'Price sync completed', [
                'user_id' => $this->userId,
                'stats' => $stats
            ]);

            return array_merge(['success' => true], $stats);

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Price sync failed', [
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
     * Sincronizza codici EAN da Excel a database products.ean
     * Salva External Product Id nella colonna ean per ogni SKU matchato
     * 
     * @param string $filepath Path file Excel
     * @return array ['success', 'updated_count', 'skipped_count', 'errors']
     */
    public function syncEanCodes($filepath)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Trova colonne necessarie
            $headers = [];
            $skuColumn = null;
            $eanColumn = null;
            $parentChildColumn = null;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headerCell = $worksheet->getCell($letter . '3');
                $headerValue = $headerCell->getValue();
                
                if (is_object($headerValue)) {
                    $headerValue = (string)$headerValue;
                }
                
                if ($headerValue) {
                    $headerValue = strtolower(trim((string)$headerValue));
                    $headers[$letter] = $headerValue;
                    
                    if ($headerValue === 'item_sku') {
                        $skuColumn = $letter;
                    } elseif ($headerValue === 'external_product_id') {
                        $eanColumn = $letter;
                    } elseif ($headerValue === 'parent_child') {
                        $parentChildColumn = $letter;
                    }
                }
            }

            if (!$skuColumn) {
                throw new Exception('Colonna item_sku non trovata');
            }
            if (!$eanColumn) {
                throw new Exception('Colonna external_product_id non trovata');
            }

            // Prepara statement per update
            $stmt = $this->pdo->prepare("
                UPDATE products 
                SET ean = ? 
                WHERE user_id = ? AND sku = ?
            ");

            $stats = [
                'total_rows' => 0,
                'updated_count' => 0,
                'skipped_empty' => 0,
                'skipped_parent' => 0,
                'skipped_asin' => 0,
                'not_found' => 0,
                'errors' => []
            ];

            // Processa righe dati (dalla riga 4)
            for ($row = 4; $row <= $highestRow; $row++) {
                $stats['total_rows']++;
                
                try {
                    // Leggi SKU
                    $skuCell = $worksheet->getCell($skuColumn . $row);
                    $sku = $skuCell->getValue();
                    if (is_object($sku)) {
                        if (method_exists($sku, '__toString')) {
                            $sku = (string)$sku;
                        } elseif ($sku instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $sku = $sku->getPlainText();
                        } else {
                            $sku = $skuCell->getFormattedValue();
                        }
                    }
                    
                    if (empty($sku)) {
                        $stats['skipped_empty']++;
                        continue;
                    }
                    $sku = trim((string)$sku);
                    
                    // Check se è Parent
                    if ($parentChildColumn) {
                        $parentChildCell = $worksheet->getCell($parentChildColumn . $row);
                        $parentChild = $parentChildCell->getValue();
                        if (is_object($parentChild)) {
                            $parentChild = (string)$parentChild;
                        }
                        if ($parentChild && strtolower(trim((string)$parentChild)) === 'parent') {
                            $stats['skipped_parent']++;
                            continue;
                        }
                    }
                    
                    // Leggi EAN
                    $eanCell = $worksheet->getCell($eanColumn . $row);
                    $ean = $eanCell->getValue();
                    if (is_object($ean)) {
                        if (method_exists($ean, '__toString')) {
                            $ean = (string)$ean;
                        } elseif ($ean instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $ean = $ean->getPlainText();
                        } else {
                            $ean = $eanCell->getFormattedValue();
                        }
                    }
                    
                    if (empty($ean)) {
                        $stats['skipped_empty']++;
                        continue;
                    }
                    $ean = trim((string)$ean);
                    
                    // Skip se è ASIN (inizia con B0)
                    if (preg_match('/^B0[A-Z0-9]{8}$/i', $ean)) {
                        $stats['skipped_asin']++;
                        continue;
                    }
                    
                    // Update EAN nel database
                    $stmt->execute([$ean, $this->userId, $sku]);
                    
                    if ($stmt->rowCount() > 0) {
                        $stats['updated_count']++;
                    } else {
                        $stats['not_found']++;
                    }
                    
                } catch (Exception $e) {
                    $stats['errors'][] = "Riga $row: " . $e->getMessage();
                }
            }

            CentralLogger::info('ai_creator', 'EAN sync completed', [
                'user_id' => $this->userId,
                'stats' => $stats
            ]);

            return array_merge(['success' => true], $stats);

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'EAN sync failed', [
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
     * Verifica e colora SKU matching in products
     * Colora di verde celle item_sku che esistono in products.sku
     * 
     * @param string $filepath Path file Excel
     * @return array ['success', 'matched_count', 'matched_rows']
     */
    public function verifyAndColorSkus($filepath)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Trova colonne item_sku, external_product_id, parent_child
            $headers = [];
            $skuColumn = null;
            $eanColumn = null;
            $parentChildColumn = null;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headerCell = $worksheet->getCell($letter . '3');
                $headerValue = $headerCell->getValue();
                
                // Converti oggetti in stringhe
                if (is_object($headerValue)) {
                    $headerValue = (string)$headerValue;
                }
                
                if ($headerValue) {
                    $headerValue = strtolower(trim((string)$headerValue));
                    $headers[$letter] = $headerValue;
                    
                    if ($headerValue === 'item_sku') {
                        $skuColumn = $letter;
                    } elseif ($headerValue === 'external_product_id') {
                        $eanColumn = $letter;
                    } elseif ($headerValue === 'parent_child') {
                        $parentChildColumn = $letter;
                    }
                }
            }

            if (!$skuColumn) {
                CentralLogger::warning('ai_creator', 'SKU verification skipped - column not found', [
                    'user_id' => $this->userId
                ]);
                return [
                    'success' => true,
                    'matched_count' => 0,
                    'matched_rows' => [],
                    'skipped' => true
                ];
            }

            CentralLogger::info('ai_creator', 'SKU verification columns found', [
                'user_id' => $this->userId,
                'sku_column' => $skuColumn,
                'ean_column' => $eanColumn ?: 'not found',
                'parent_child_column' => $parentChildColumn ?: 'not found'
            ]);

            // Prepara query per verificare SKU esistenti
            $stmtSku = $this->pdo->prepare("
                SELECT sku, ean, asin 
                FROM products 
                WHERE user_id = ? AND sku = ?
                LIMIT 1
            ");
            
            // Prepara query per verificare EAN
            $stmtEan = $this->pdo->prepare("
                SELECT sku, ean 
                FROM products 
                WHERE user_id = ? AND ean = ?
                LIMIT 1
            ");

            // Contatori
            $stats = [
                'total_rows' => 0,
                'sku_matched_count' => 0,
                'sku_not_matched_count' => 0,
                'ean_matched_count' => 0,
                'ean_not_matched_count' => 0,
                'asin_matched_count' => 0,
                'asin_not_matched_count' => 0,
                'skipped_parent_count' => 0,
                'skipped_empty_count' => 0,
                'matched_sku_rows' => [],
                'matched_ean_rows' => [],
                'matched_asin_rows' => []
            ];

            // Processa righe dati (dalla riga 4 in poi)
            for ($row = 4; $row <= $highestRow; $row++) {
                $stats['total_rows']++;
                
                // Leggi SKU
                $cell = $worksheet->getCell($skuColumn . $row);
                $sku = $cell->getValue();
                
                // Converti oggetti in stringhe
                if (is_object($sku)) {
                    if (method_exists($sku, '__toString')) {
                        $sku = (string)$sku;
                    } elseif ($sku instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $sku = $sku->getPlainText();
                    } else {
                        $sku = $cell->getFormattedValue();
                    }
                }
                
                if (empty($sku)) {
                    $stats['skipped_empty_count']++;
                    continue;
                }
                
                // Normalizza SKU
                $sku = trim((string)$sku);
                
                // Check se è Parent (skip coloring per Parent)
                if ($parentChildColumn) {
                    $parentChildCell = $worksheet->getCell($parentChildColumn . $row);
                    $parentChild = $parentChildCell->getValue();
                    if (is_object($parentChild)) {
                        $parentChild = (string)$parentChild;
                    }
                    if ($parentChild && strtolower(trim((string)$parentChild)) === 'parent') {
                        $stats['skipped_parent_count']++;
                        continue;
                    }
                }
                
                // Verifica se SKU esiste in products
                $stmtSku->execute([$this->userId, $sku]);
                $product = $stmtSku->fetch(PDO::FETCH_ASSOC);
                
                if ($product) {
                    // SKU trovato → Colora di verde
                    $worksheet->getStyle($skuColumn . $row)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('C6EFCE');
                    
                    $stats['sku_matched_count']++;
                    $stats['matched_sku_rows'][] = $row;
                } else {
                    $stats['sku_not_matched_count']++;
                }
                
                // Verifica EAN/ASIN se colonna presente
                if ($eanColumn) {
                    $eanCell = $worksheet->getCell($eanColumn . $row);
                    $eanValue = $eanCell->getValue();
                    
                    if (is_object($eanValue)) {
                        if (method_exists($eanValue, '__toString')) {
                            $eanValue = (string)$eanValue;
                        } elseif ($eanValue instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                            $eanValue = $eanValue->getPlainText();
                        } else {
                            $eanValue = $eanCell->getFormattedValue();
                        }
                    }
                    
                    if (!empty($eanValue)) {
                        $eanValue = trim((string)$eanValue);
                        
                        // Check se è ASIN (formato B0XXXXXXXXX)
                        if (preg_match('/^B0[A-Z0-9]{8}$/i', $eanValue)) {
                            // È un ASIN → verifica se corrisponde al prodotto trovato
                            if ($product && isset($product['asin']) && strtolower($product['asin']) === strtolower($eanValue)) {
                                $stats['asin_matched_count']++;
                                $stats['matched_asin_rows'][] = $row;
                            } else {
                                $stats['asin_not_matched_count']++;
                            }
                        } else {
                            // È un EAN → verifica se corrisponde nel DB
                            $stmtEan->execute([$this->userId, $eanValue]);
                            $eanExists = $stmtEan->fetch(PDO::FETCH_ASSOC);
                            
                            if ($eanExists && $eanExists['sku'] === $sku) {
                                $stats['ean_matched_count']++;
                                $stats['matched_ean_rows'][] = $row;
                            } else {
                                $stats['ean_not_matched_count']++;
                            }
                        }
                    }
                }
            }

            // Salva Excel modificato
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);

            CentralLogger::info('ai_creator', 'SKU verification completed', [
                'user_id' => $this->userId,
                'stats' => $stats
            ]);

            return array_merge(['success' => true], $stats);

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'SKU verification failed', [
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
     * Duplica un file Excel
     * 
     * @param string $filepath Path file originale
     * @param string $newName Nuovo nome file (opzionale)
     * @return array ['success', 'new_filepath', 'new_filename']
     */
    public function duplicateExcel($filepath, $newName = null)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File non trovato');
            }

            $pathInfo = pathinfo($filepath);
            $directory = $pathInfo['dirname'];
            $originalName = $pathInfo['filename'];
            $extension = $pathInfo['extension'];

            // Genera nuovo nome se non fornito
            if (empty($newName)) {
                $timestamp = time();
                $newName = $originalName . '_copy_' . $timestamp;
            } else {
                // Rimuovi estensione se presente nel nuovo nome
                $newName = preg_replace('/\.' . preg_quote($extension) . '$/i', '', $newName);
            }

            // Sanitizza nome file
            $newName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $newName);
            
            $newFilepath = $directory . '/' . $newName . '.' . $extension;

            // Check se file esiste già
            if (file_exists($newFilepath)) {
                throw new Exception('Un file con questo nome esiste già');
            }

            // Copia file
            if (!copy($filepath, $newFilepath)) {
                throw new Exception('Errore durante la copia del file');
            }

            CentralLogger::info('ai_creator', 'File duplicated', [
                'user_id' => $this->userId,
                'original' => basename($filepath),
                'duplicate' => basename($newFilepath)
            ]);

            return [
                'success' => true,
                'new_filepath' => $newFilepath,
                'new_filename' => $newName . '.' . $extension
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'File duplication failed', [
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
     * Rinomina un file Excel
     * 
     * @param string $filepath Path file originale
     * @param string $newName Nuovo nome file
     * @return array ['success', 'new_filepath', 'new_filename']
     */
    public function renameExcel($filepath, $newName)
    {
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File non trovato');
            }

            if (empty($newName)) {
                throw new Exception('Nome file non valido');
            }

            $pathInfo = pathinfo($filepath);
            $directory = $pathInfo['dirname'];
            $extension = $pathInfo['extension'];

            // Rimuovi estensione se presente nel nuovo nome
            $newName = preg_replace('/\.' . preg_quote($extension) . '$/i', '', $newName);

            // Sanitizza nome file
            $newName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $newName);
            
            if (empty($newName)) {
                throw new Exception('Nome file non valido dopo sanitizzazione');
            }

            $newFilepath = $directory . '/' . $newName . '.' . $extension;

            // Check se file esiste già
            if (file_exists($newFilepath)) {
                throw new Exception('Un file con questo nome esiste già');
            }

            // Rinomina file
            if (!rename($filepath, $newFilepath)) {
                throw new Exception('Errore durante la rinomina del file');
            }

            CentralLogger::info('ai_creator', 'File renamed', [
                'user_id' => $this->userId,
                'old_name' => basename($filepath),
                'new_name' => basename($newFilepath)
            ]);

            return [
                'success' => true,
                'new_filepath' => $newFilepath,
                'new_filename' => $newName . '.' . $extension
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'File rename failed', [
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
     * Duplica una riga nell'Excel
     * Inserisce copia della riga subito sotto l'originale
     * 
     * @param string $filepath Path file Excel
     * @param int $rowNumber Numero riga da duplicare
     * @return array ['success', 'new_row_number', 'row_data']
     */
    public function duplicateRow($filepath, $rowNumber)
    {
        $oldOpenBasedir = ini_get('open_basedir');
        @ini_set('open_basedir', '');
        
        try {
            if (!file_exists($filepath)) {
                throw new Exception('File Excel non trovato');
            }

            $spreadsheet = $this->loadExcelSafe($filepath);
            $worksheet = $spreadsheet->getActiveSheet();

            $highestRow = $worksheet->getHighestRow();
            $highestColumn = $worksheet->getHighestColumn();
            $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

            // Verifica che la riga esista
            if ($rowNumber < 4 || $rowNumber > $highestRow) {
                throw new Exception('Numero riga non valido');
            }

            // La nuova riga sarà subito dopo l'originale
            $newRowNumber = $rowNumber + 1;

            // Inserisci nuova riga vuota
            $worksheet->insertNewRowBefore($newRowNumber, 1);

            // Copia tutti i valori e stili dalla riga originale alla nuova riga
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                
                // Copia valore
                $sourceCell = $worksheet->getCell($letter . $rowNumber);
                $targetCell = $worksheet->getCell($letter . $newRowNumber);
                
                $value = $sourceCell->getValue();
                
                // Converti oggetti in stringhe
                if (is_object($value)) {
                    if (method_exists($value, '__toString')) {
                        $value = (string)$value;
                    } elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $value = $value->getPlainText();
                    } elseif ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d H:i:s');
                    } else {
                        $value = $sourceCell->getFormattedValue();
                    }
                }
                
                $targetCell->setValue($value);
                
                // Copia stile (font, fill, borders, etc.)
                $worksheet->duplicateStyle(
                    $sourceCell->getStyle(),
                    $letter . $newRowNumber
                );
            }

            // Salva Excel
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filepath);

            // Leggi dati nuova riga per ritorno
            $headers = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
                $headerCell = $worksheet->getCell($letter . '3');
                $headerValue = $headerCell->getValue();
                
                if (is_object($headerValue)) {
                    $headerValue = (string)$headerValue;
                }
                
                if ($headerValue) {
                    $headerValue = strtolower(trim((string)$headerValue));
                    $headers[$letter] = $headerValue;
                }
            }

            $rowData = [];
            foreach ($headers as $letter => $headerName) {
                $cell = $worksheet->getCell($letter . $newRowNumber);
                $value = $cell->getValue();
                
                if (is_object($value)) {
                    if (method_exists($value, '__toString')) {
                        $value = (string)$value;
                    } elseif ($value instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
                        $value = $value->getPlainText();
                    } elseif ($value instanceof \DateTime) {
                        $value = $value->format('Y-m-d H:i:s');
                    } else {
                        $value = $cell->getFormattedValue();
                    }
                }
                
                $rowData[$headerName] = $value;
            }

            CentralLogger::info('ai_creator', 'Row duplicated', [
                'user_id' => $this->userId,
                'original_row' => $rowNumber,
                'new_row' => $newRowNumber
            ]);

            return [
                'success' => true,
                'new_row_number' => $newRowNumber,
                'row_data' => $rowData
            ];

        } catch (Exception $e) {
            CentralLogger::error('ai_creator', 'Row duplication failed', [
                'user_id' => $this->userId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } finally {
            if (isset($oldOpenBasedir) && $oldOpenBasedir) {
                @ini_set('open_basedir', $oldOpenBasedir);
            }
        }
    }
}

