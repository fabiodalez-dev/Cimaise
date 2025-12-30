<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

use ZipArchive;

class TemplateValidationService
{
    private const MAX_ZIP_SIZE = 10 * 1024 * 1024; // 10 MB
    private const ALLOWED_EXTENSIONS = ['twig', 'json', 'css', 'js', 'md', 'jpg', 'jpeg', 'png', 'svg'];
    private const REQUIRED_FILES = ['metadata.json'];

    private const MALWARE_PATTERNS = [
        '/eval\s*\(/i',
        '/base64_decode/i',
        '/system\s*\(/i',
        '/exec\s*\(/i',
        '/passthru\s*\(/i',
        '/shell_exec/i',
        '/proc_open/i',
        '/popen\s*\(/i',
        '/<\?php/i',
        '/\$_GET/i',
        '/\$_POST/i',
        '/\$_REQUEST/i',
        '/file_get_contents\s*\(/i',
        '/file_put_contents\s*\(/i',
        '/fopen\s*\(/i',
        '/curl_exec/i',
        '/\.\.\//', // Path traversal
    ];

    private const DANGEROUS_TWIG_FUNCTIONS = [
        'include(',
        'source(',
        'import(',
        '_self',
        'attribute(',
    ];

    public array $errors = [];

    /**
     * Valida un file ZIP caricato
     */
    public function validateZip(string $zipPath, string $type): bool
    {
        $this->errors = [];

        // Verifica esistenza file
        if (!file_exists($zipPath)) {
            $this->errors[] = 'Il file ZIP non esiste';
            return false;
        }

        // Verifica dimensione
        if (filesize($zipPath) > self::MAX_ZIP_SIZE) {
            $this->errors[] = 'Il file ZIP supera la dimensione massima di 10 MB';
            return false;
        }

        // Verifica che sia un file ZIP valido
        $zip = new ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            $this->errors[] = 'Il file non è un archivio ZIP valido';
            return false;
        }

        // Estrae lista file
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[] = $zip->getNameIndex($i);
        }

        // Verifica file richiesti
        if (!$this->validateRequiredFiles($files)) {
            $zip->close();
            return false;
        }

        // Verifica estensioni
        if (!$this->validateFileExtensions($files)) {
            $zip->close();
            return false;
        }

        // Verifica path traversal
        if (!$this->validateFilePaths($files)) {
            $zip->close();
            return false;
        }

        // Leggi e valida metadata.json (cerca alla root o dentro una cartella)
        $metadataContent = $zip->getFromName('metadata.json');

        // Se non trovato alla root, cerca dentro una cartella
        if ($metadataContent === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (preg_match('#^[^/]+/metadata\.json$#', $name)) {
                    $metadataContent = $zip->getFromName($name);
                    break;
                }
            }
        }

        $zip->close();

        if ($metadataContent === false) {
            $this->errors[] = 'Impossibile leggere metadata.json';
            return false;
        }

        if (!$this->validateMetadata($metadataContent, $type)) {
            return false;
        }

        return true;
    }

    /**
     * Valida i file richiesti
     * Cerca i file sia alla root che dentro una singola cartella
     */
    private function validateRequiredFiles(array $files): bool
    {
        foreach (self::REQUIRED_FILES as $required) {
            $found = false;

            // Cerca il file direttamente o dentro una cartella
            foreach ($files as $file) {
                // Match esatto o match con prefisso cartella (es. "template-name/metadata.json")
                if ($file === $required || preg_match('#^[^/]+/' . preg_quote($required, '#') . '$#', $file)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $this->errors[] = "File obbligatorio mancante: {$required}";
                return false;
            }
        }
        return true;
    }

    /**
     * Valida le estensioni dei file
     */
    private function validateFileExtensions(array $files): bool
    {
        foreach ($files as $file) {
            // Skip directory
            if (substr($file, -1) === '/') {
                continue;
            }

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if (!in_array($ext, self::ALLOWED_EXTENSIONS)) {
                $this->errors[] = "Estensione non consentita: {$file}";
                return false;
            }
        }
        return true;
    }

    /**
     * Valida i path dei file (no path traversal)
     */
    private function validateFilePaths(array $files): bool
    {
        foreach ($files as $file) {
            // Verifica path traversal
            if (strpos($file, '..') !== false) {
                $this->errors[] = "Path non sicuro rilevato: {$file}";
                return false;
            }

            // Verifica caratteri speciali
            if (preg_match('/[^a-zA-Z0-9\/_.-]/', $file)) {
                $this->errors[] = "Caratteri non consentiti nel nome file: {$file}";
                return false;
            }
        }
        return true;
    }

    /**
     * Valida il contenuto di metadata.json
     */
    public function validateMetadata(string $jsonContent, string $expectedType): bool
    {
        $metadata = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->errors[] = 'metadata.json non è un JSON valido: ' . json_last_error_msg();
            return false;
        }

        // Campi obbligatori
        $required = ['type', 'name', 'slug', 'version'];
        foreach ($required as $field) {
            if (!isset($metadata[$field]) || empty($metadata[$field])) {
                $this->errors[] = "Campo obbligatorio mancante in metadata.json: {$field}";
                return false;
            }
        }

        // Verifica tipo
        if ($metadata['type'] !== $expectedType) {
            $this->errors[] = "Tipo template non corretto. Atteso: {$expectedType}, ricevuto: {$metadata['type']}";
            return false;
        }

        // Valida slug
        if (!preg_match('/^[a-z0-9-]+$/', $metadata['slug'])) {
            $this->errors[] = 'Lo slug deve contenere solo lettere minuscole, numeri e trattini';
            return false;
        }

        // Valida versione
        if (!preg_match('/^\d+\.\d+\.\d+$/', $metadata['version'])) {
            $this->errors[] = 'La versione deve essere in formato semver (es. 1.0.0)';
            return false;
        }

        return true;
    }

    /**
     * Valida sintassi di un template Twig
     */
    public function validateTwigSyntax(string $twigContent): bool
    {
        try {
            // Verifica funzioni pericolose
            foreach (self::DANGEROUS_TWIG_FUNCTIONS as $dangerous) {
                if (stripos($twigContent, $dangerous) !== false) {
                    $this->errors[] = "Funzione Twig non consentita rilevata: {$dangerous}";
                    return false;
                }
            }

            // Verifica bilanciamento tag Twig
            if (!$this->checkTwigBalance($twigContent)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->errors[] = 'Errore nella validazione della sintassi Twig: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Verifica bilanciamento tag Twig
     */
    private function checkTwigBalance(string $content): bool
    {
        $openBraces = substr_count($content, '{{');
        $closeBraces = substr_count($content, '}}');
        $openBlocks = substr_count($content, '{%');
        $closeBlocks = substr_count($content, '%}');

        if ($openBraces !== $closeBraces) {
            $this->errors[] = 'Tag Twig {{ }} non bilanciati';
            return false;
        }

        if ($openBlocks !== $closeBlocks) {
            $this->errors[] = 'Tag Twig {% %} non bilanciati';
            return false;
        }

        return true;
    }

    /**
     * Scansiona file per pattern sospetti (malware)
     */
    public function scanForMalware(string $content, string $filename): bool
    {
        foreach (self::MALWARE_PATTERNS as $pattern) {
            if (preg_match($pattern, $content)) {
                $this->errors[] = "Pattern sospetto rilevato in {$filename}";
                return false;
            }
        }
        return true;
    }

    /**
     * Valida file CSS
     */
    public function validateCSS(string $cssContent): bool
    {
        // Verifica @import non consentiti
        if (preg_match('/@import\s+url\s*\(/i', $cssContent)) {
            $this->errors[] = 'Import di URL esterni non consentiti in CSS';
            return false;
        }

        // Verifica expression() di IE (pericoloso)
        if (stripos($cssContent, 'expression(') !== false) {
            $this->errors[] = 'CSS expression() non consentito';
            return false;
        }

        return true;
    }

    /**
     * Valida file JavaScript
     */
    public function validateJavaScript(string $jsContent): bool
    {
        // Scansione malware
        if (!$this->scanForMalware($jsContent, 'script.js')) {
            return false;
        }

        // Verifica che non ci siano eval
        if (stripos($jsContent, 'eval(') !== false) {
            $this->errors[] = 'eval() non consentito in JavaScript';
            return false;
        }

        // Verifica che non ci siano Function constructor
        if (preg_match('/new\s+Function\s*\(/i', $jsContent)) {
            $this->errors[] = 'Function constructor non consentito';
            return false;
        }

        return true;
    }

    /**
     * Restituisce gli errori di validazione
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Restituisce il primo errore
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
