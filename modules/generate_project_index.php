#!/usr/bin/env php
<?php
/**
 * PROJECT_INDEX.json Generator
 * 
 * Genera documentazione automatica per ogni modulo in /modules/
 * Scansiona tutti i file PHP, estrae classi/metodi pubblici con line numbers
 * 
 * Usage:
 *   php generate_project_index.php [module_name]
 *   php generate_project_index.php all (tutti i moduli)
 */

class ProjectIndexGenerator
{
    private $basePath;
    private $stats = [
        'files_scanned' => 0,
        'classes_found' => 0,
        'methods_found' => 0,
        'errors' => []
    ];

    public function __construct($basePath)
    {
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Genera index per un singolo modulo
     */
    public function generateModuleIndex($moduleName)
    {
        $modulePath = $this->basePath . '/' . $moduleName;
        
        if (!is_dir($modulePath)) {
            echo "❌ Modulo '{$moduleName}' non trovato in {$modulePath}\n";
            return false;
        }

        echo "📂 Scansione modulo: {$moduleName}\n";
        
        $index = [
            'module' => $moduleName,
            'generated_at' => date('Y-m-d H:i:s'),
            'base_path' => $moduleName . '/',
            'files' => []
        ];

        // Scansiona tutti i file PHP ricorsivamente
        $files = $this->scanPhpFiles($modulePath);
        
        foreach ($files as $filePath) {
            $relativePath = str_replace($this->basePath . '/', '', $filePath);
            echo "  📄 {$relativePath}\n";
            
            $fileData = $this->analyzePhpFile($filePath, $relativePath);
            if ($fileData) {
                $index['files'][] = $fileData;
            }
        }

        // Salva PROJECT_INDEX.json
        $indexPath = $modulePath . '/PROJECT_INDEX.json';
        file_put_contents($indexPath, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        echo "✅ Index generato: {$indexPath}\n";
        echo "📊 Files: {$this->stats['files_scanned']}, Classi: {$this->stats['classes_found']}, Metodi: {$this->stats['methods_found']}\n\n";
        
        return true;
    }

    /**
     * Genera index per tutti i moduli
     */
    public function generateAllIndexes()
    {
        $modules = array_filter(glob($this->basePath . '/*'), 'is_dir');
        
        echo "🔍 Trovati " . count($modules) . " moduli\n\n";
        
        foreach ($modules as $modulePath) {
            $moduleName = basename($modulePath);
            
            // Skip cartelle di sistema
            if (in_array($moduleName, ['.', '..', 'vendor', 'node_modules'])) {
                continue;
            }
            
            $this->generateModuleIndex($moduleName);
        }
        
        echo "🎉 Generazione completata per tutti i moduli!\n";
    }

    /**
     * Scansiona tutti i file PHP in una directory (ricorsivo)
     */
    private function scanPhpFiles($directory)
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Analizza un file PHP ed estrae classi/metodi
     */
    private function analyzePhpFile($filePath, $relativePath)
    {
        $this->stats['files_scanned']++;
        
        $content = file_get_contents($filePath);
        $lines = file($filePath);
        
        $fileData = [
            'file' => $relativePath,
            'size' => filesize($filePath),
            'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
            'classes' => []
        ];

        // Estrai namespace
        if (preg_match('/namespace\s+([^;]+);/', $content, $match)) {
            $fileData['namespace'] = trim($match[1]);
        }

        // Estrai classi
        preg_match_all('/^(abstract\s+)?(class|interface|trait)\s+(\w+)/m', $content, $matches, PREG_OFFSET_CAPTURE);
        
        foreach ($matches[3] as $index => $classMatch) {
            $className = $classMatch[0];
            $classType = $matches[2][$index][0];
            $lineNumber = $this->getLineNumber($content, $classMatch[1]);
            
            $this->stats['classes_found']++;
            
            $classData = [
                'name' => $className,
                'type' => $classType,
                'line' => $lineNumber,
                'public_methods' => []
            ];

            // Estrai metodi pubblici della classe
            $classData['public_methods'] = $this->extractPublicMethods($content, $className, $lines);
            
            $fileData['classes'][] = $classData;
        }

        // Estrai funzioni standalone (non dentro classi)
        $fileData['functions'] = $this->extractStandaloneFunctions($content, $lines);

        return $fileData;
    }

    /**
     * Estrae metodi pubblici di una classe
     */
    private function extractPublicMethods($content, $className, $lines)
    {
        $methods = [];
        
        // Regex per metodi pubblici
        preg_match_all('/public\s+(static\s+)?function\s+(\w+)\s*\(([^)]*)\)/m', $content, $matches, PREG_OFFSET_CAPTURE);
        
        foreach ($matches[2] as $index => $methodMatch) {
            $methodName = $methodMatch[0];
            $params = $matches[3][$index][0];
            $lineNumber = $this->getLineNumber($content, $methodMatch[1]);
            
            $this->stats['methods_found']++;
            
            // Estrai docblock
            $description = $this->extractDocblock($lines, $lineNumber);
            
            $methods[] = [
                'name' => $methodName,
                'line' => $lineNumber,
                'parameters' => $this->parseParameters($params),
                'description' => $description,
                'static' => !empty($matches[1][$index][0])
            ];
        }

        return $methods;
    }

    /**
     * Estrae funzioni standalone (fuori da classi)
     */
    private function extractStandaloneFunctions($content, $lines)
    {
        $functions = [];
        
        // Cerca function non dentro classi (approssimativo)
        preg_match_all('/^function\s+(\w+)\s*\(([^)]*)\)/m', $content, $matches, PREG_OFFSET_CAPTURE);
        
        foreach ($matches[1] as $index => $funcMatch) {
            $funcName = $funcMatch[0];
            $params = $matches[2][$index][0];
            $lineNumber = $this->getLineNumber($content, $funcMatch[1]);
            
            $description = $this->extractDocblock($lines, $lineNumber);
            
            $functions[] = [
                'name' => $funcName,
                'line' => $lineNumber,
                'parameters' => $this->parseParameters($params),
                'description' => $description
            ];
        }

        return $functions;
    }

    /**
     * Estrae docblock prima di una linea
     */
    private function extractDocblock($lines, $lineNumber)
    {
        $description = '';
        $docLines = [];
        
        // Cerca docblock sopra la funzione
        for ($i = $lineNumber - 2; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            
            if (strpos($line, '*/') !== false) {
                continue; // Fine docblock
            }
            
            if (strpos($line, '/**') !== false) {
                break; // Inizio docblock
            }
            
            if (strpos($line, '*') === 0) {
                $line = trim(substr($line, 1));
                if (!empty($line) && strpos($line, '@') !== 0) {
                    $docLines[] = $line;
                }
            }
        }
        
        return implode(' ', array_reverse($docLines));
    }

    /**
     * Parse parametri funzione
     */
    private function parseParameters($paramsString)
    {
        if (empty(trim($paramsString))) {
            return [];
        }

        $params = [];
        $parts = explode(',', $paramsString);
        
        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/(\$\w+)/', $part, $match)) {
                $params[] = $match[1];
            }
        }

        return $params;
    }

    /**
     * Calcola numero linea da offset
     */
    private function getLineNumber($content, $offset)
    {
        return substr_count(substr($content, 0, $offset), "\n") + 1;
    }
}

// ============================================
// MAIN EXECUTION
// ============================================

$basePath = __DIR__;
$generator = new ProjectIndexGenerator($basePath);

// Parse arguments
$module = $argv[1] ?? null;

if (!$module) {
    echo "Usage:\n";
    echo "  php generate_project_index.php <module_name>\n";
    echo "  php generate_project_index.php all\n";
    echo "\nEsempio:\n";
    echo "  php generate_project_index.php margynomic\n";
    echo "  php generate_project_index.php all\n";
    exit(1);
}

if ($module === 'all') {
    $generator->generateAllIndexes();
} else {
    $generator->generateModuleIndex($module);
}

