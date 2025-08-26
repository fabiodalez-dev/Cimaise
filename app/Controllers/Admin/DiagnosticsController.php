<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DiagnosticsController extends BaseController
{
    public function __construct(private Database $db, private Twig $view) 
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $diagnostics = $this->runDiagnostics();
        
        return $this->view->render($response, 'admin/diagnostics.twig', [
            'diagnostics' => $diagnostics,
            'page_title' => 'System Diagnostics'
        ]);
    }

    private function runDiagnostics(): array
    {
        $results = [];
        
        // PHP Version
        $results['php'] = [
            'name' => 'PHP Version',
            'status' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'warning',
            'value' => PHP_VERSION,
            'message' => version_compare(PHP_VERSION, '8.2.0', '>=') ? 'PHP version is compatible' : 'PHP 8.2+ recommended',
            'details' => [
                'Required' => '8.2.0+',
                'Current' => PHP_VERSION
            ]
        ];
        
        // Database Connection
        try {
            $this->db->pdo();
            $results['database'] = [
                'name' => 'Database Connection',
                'status' => 'ok',
                'value' => 'Connected',
                'message' => 'Database connection successful'
            ];
        } catch (\Throwable $e) {
            $results['database'] = [
                'name' => 'Database Connection',
                'status' => 'error',
                'value' => 'Failed',
                'message' => 'Database connection failed: ' . $e->getMessage()
            ];
        }
        
        // Extensions
        $extensions = [
            'PDO' => 'Database access',
            'pdo_mysql' => 'MySQL database support',
            'mbstring' => 'Multibyte string support',
            'json' => 'JSON processing',
            'fileinfo' => 'File type detection',
            'exif' => 'EXIF data reading (optional)',
            'gd' => 'Image processing with GD (recommended)',
            'imagick' => 'Image processing with ImageMagick (recommended)'
        ];
        
        foreach ($extensions as $ext => $description) {
            $loaded = extension_loaded($ext);
            $isOptional = in_array($ext, ['exif', 'imagick']);
            $isRecommended = in_array($ext, ['gd', 'imagick']);
            
            $status = 'ok';
            $message = "Extension loaded";
            
            if (!$loaded) {
                if ($isOptional) {
                    $status = 'warning';
                    $message = "Extension not loaded (optional)";
                } elseif ($isRecommended) {
                    $status = 'warning';
                    $message = "Extension not loaded (recommended)";
                } else {
                    $status = 'error';
                    $message = "Extension not loaded (required)";
                }
            }
            
            $results['ext_' . $ext] = [
                'name' => "Extension: $ext",
                'status' => $status,
                'value' => $loaded ? 'Loaded' : 'Not loaded',
                'message' => "$message - $description"
            ];
        }
        
        // Directory Permissions
        $directories = [
            'storage' => dirname(__DIR__, 2) . '/storage',
            'storage/originals' => dirname(__DIR__, 2) . '/storage/originals',
            'storage/tmp' => dirname(__DIR__, 2) . '/storage/tmp',
            'public/media' => dirname(__DIR__, 2) . '/public/media'
        ];
        
        foreach ($directories as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            
            if (!$exists) {
                $status = 'error';
                $message = 'Directory does not exist';
                $value = 'Missing';
            } elseif (!$writable) {
                $status = 'error';
                $message = 'Directory is not writable';
                $value = 'Read-only';
            } else {
                $status = 'ok';
                $message = 'Directory exists and is writable';
                $value = 'OK';
            }
            
            $results['dir_' . str_replace('/', '_', $name)] = [
                'name' => "Directory: $name",
                'status' => $status,
                'value' => $value,
                'message' => $message,
                'details' => [
                    'Path' => $path,
                    'Exists' => $exists ? 'Yes' : 'No',
                    'Writable' => $writable ? 'Yes' : 'No'
                ]
            ];
        }
        
        // Memory Limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = $this->parseMemoryLimit($memoryLimit);
        $recommendedBytes = 256 * 1024 * 1024; // 256MB
        
        $results['memory'] = [
            'name' => 'Memory Limit',
            'status' => $memoryBytes >= $recommendedBytes ? 'ok' : 'warning',
            'value' => $memoryLimit,
            'message' => $memoryBytes >= $recommendedBytes ? 
                'Memory limit is sufficient' : 
                'Memory limit may be low for image processing',
            'details' => [
                'Current' => $memoryLimit,
                'Recommended' => '256M+'
            ]
        ];
        
        // Upload Settings
        $maxFilesize = ini_get('upload_max_filesize');
        $maxPost = ini_get('post_max_size');
        $maxExecutionTime = ini_get('max_execution_time');
        
        $results['upload'] = [
            'name' => 'Upload Settings',
            'status' => 'info',
            'value' => "Max file: $maxFilesize, Max post: $maxPost",
            'message' => 'Current upload configuration',
            'details' => [
                'Max file size' => $maxFilesize,
                'Max post size' => $maxPost,
                'Max execution time' => $maxExecutionTime . 's'
            ]
        ];
        
        // Database Stats
        try {
            $pdo = $this->db->pdo();
            
            $stats = [];
            $tables = ['albums', 'images', 'categories', 'tags', 'cameras', 'lenses', 'films', 'developers', 'labs'];
            
            foreach ($tables as $table) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table");
                $stmt->execute();
                $count = $stmt->fetchColumn();
                $stats[$table] = $count;
            }
            
            $results['db_stats'] = [
                'name' => 'Database Statistics',
                'status' => 'info',
                'value' => 'Data available',
                'message' => 'Database contains data',
                'details' => $stats
            ];
            
        } catch (\Throwable $e) {
            $results['db_stats'] = [
                'name' => 'Database Statistics',
                'status' => 'warning',
                'value' => 'Unavailable',
                'message' => 'Could not retrieve database statistics: ' . $e->getMessage()
            ];
        }
        
        // System Info
        $results['system'] = [
            'name' => 'System Information',
            'status' => 'info',
            'value' => PHP_OS_FAMILY,
            'message' => 'System information',
            'details' => [
                'OS' => PHP_OS,
                'PHP SAPI' => php_sapi_name(),
                'Server Software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'Document Root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown'
            ]
        ];
        
        return $results;
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return PHP_INT_MAX;
        }
        
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int)$limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}