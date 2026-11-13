<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

use Twig\Environment as TwigEnvironment;
use Twig\Error\SyntaxError as TwigSyntaxError;
use Twig\Loader\ArrayLoader as TwigArrayLoader;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\HasEveryBinary;
use Twig\Node\Expression\Binary\HasSomeBinary;
use Twig\Node\Node as TwigNode;
use Twig\Source as TwigSource;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;
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
        '/preg_replace\s*\(.*\/e/i',
        '/\bassert\s*\(/i',
        '/curl_exec/i',
        '/\.\.\//', // Path traversal
    ];

    // SSTI/RCE vectors in a Twig template rendered by the app's REAL Twig
    // environment (the uploaded template's directory is added to the shared
    // loader — there is no sandbox). Fast, space-tolerant regex pre-filter;
    // the authoritative check is the AST walk in findUnsafeTwigConstruct(),
    // which a regex cannot be (e.g. `{% apply sort('system') %}` has no pipe).
    private const DANGEROUS_TWIG_PATTERNS = [
        // map/filter/reduce: blocked outright (never used by bundled templates).
        '/\|\s*(map|filter|reduce)\s*\(/i',
        // Functions that reach arbitrary PHP or compile attacker strings:
        //  - attribute(): can invoke arbitrary object methods
        //  - dump(): leaks the whole context
        //  - source(): reads raw file contents (info disclosure)
        //  - template_from_string(): compiles an arbitrary string as a template (SSTI)
        '/\b(attribute|dump|source|template_from_string)\s*\(/i',
    ];

    // Core Twig filters whose first argument is invoked as a PHP callable.
    // Outside a sandbox Twig accepts ANY callable there (a string such as
    // 'system' only triggers a deprecation), so the argument must be an
    // arrow function: its body can only use Twig expressions.
    private const CALLABLE_TWIG_FILTERS = ['map', 'filter', 'reduce', 'find', 'sort'];

    // Functions that are never acceptable in an uploaded template.
    private const FORBIDDEN_TWIG_FUNCTIONS = ['attribute', 'dump', 'source', 'template_from_string'];

    public array $errors = [];

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

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
            // Reject known SSTI/RCE vectors (see DANGEROUS_TWIG_PATTERNS).
            foreach (self::DANGEROUS_TWIG_PATTERNS as $pattern) {
                if (preg_match($pattern, $twigContent)) {
                    $this->errors[] = 'Costrutto Twig non consentito rilevato (potenziale esecuzione di codice).';
                    return false;
                }
            }

            // Authoritative check on the parsed template (fail closed).
            $unsafe = $this->findUnsafeTwigConstruct($twigContent);
            if ($unsafe !== null) {
                $this->errors[] = 'Costrutto Twig non consentito rilevato (potenziale esecuzione di codice): ' . $unsafe;
                return false;
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
     * Parse the template with Twig's own lexer/parser and walk the AST for
     * constructs that invoke an attacker-chosen PHP callable. Unknown
     * filters/functions/tests (app and plugin extensions) are accepted as
     * inert placeholders so that only structure is judged; anything Twig
     * cannot parse is rejected.
     *
     * @return string|null description of the first unsafe construct, or null
     */
    public function findUnsafeTwigConstruct(string $twigContent): ?string
    {
        $env = new TwigEnvironment(new TwigArrayLoader(), ['autoescape' => 'html', 'optimizations' => 0]);
        $env->registerUndefinedFilterCallback(static fn (string $name): TwigFilter => new TwigFilter($name, static fn (): null => null));
        $env->registerUndefinedFunctionCallback(static fn (string $name): TwigFunction => new TwigFunction($name, static fn (): null => null));
        $env->registerUndefinedTestCallback(static fn (string $name): TwigTest => new TwigTest($name, static fn (): bool => false));

        try {
            $ast = $env->parse($env->tokenize(new TwigSource($twigContent, 'uploaded-template.twig')));
        } catch (TwigSyntaxError $e) {
            return 'template non analizzabile (' . $e->getRawMessage() . ')';
        }

        return $this->walkTwigNode($ast);
    }

    private function walkTwigNode(TwigNode $node): ?string
    {
        if ($node instanceof HasSomeBinary || $node instanceof HasEveryBinary) {
            if (!$node->getNode('right') instanceof ArrowFunctionExpression) {
                return "operatore 'has some/has every' con callable non-arrow";
            }
        } elseif ($node->hasAttribute('type') && $node->hasAttribute('name')) {
            $type = (string) $node->getAttribute('type');
            $name = strtolower((string) $node->getAttribute('name'));
            if ($type === 'function' && in_array($name, self::FORBIDDEN_TWIG_FUNCTIONS, true)) {
                return "funzione {$name}()";
            }
            if ($type === 'filter' && in_array($name, self::CALLABLE_TWIG_FILTERS, true) && $node->hasNode('arguments')) {
                foreach ($node->getNode('arguments') as $key => $argument) {
                    // Only the callable slot (first positional or named "arrow");
                    // reduce()'s "initial" value is data, not a callable.
                    if (($key === 0 || $key === '0' || $key === 'arrow') && !$argument instanceof ArrowFunctionExpression) {
                        return "filtro {$name} con callable non-arrow";
                    }
                }
            }
        }

        foreach ($node as $child) {
            $found = $this->walkTwigNode($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
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
        if (preg_match('/@import\s+(url\s*\()?/i', $cssContent)) {
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
