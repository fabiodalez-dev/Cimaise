<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\CacheWarmService;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use App\Support\QueryCache;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CacheController extends BaseController
{
    private PageCacheService $pageCacheService;

    public function __construct(
        private Database $db,
        private Twig $view,
        private SettingsService $settings
    ) {
        parent::__construct();
        $this->pageCacheService = new PageCacheService($this->settings, $this->db);
    }

    /**
     * Show cache management page.
     */
    public function index(Request $request, Response $response): Response
    {
        $pageStats = $this->pageCacheService->getStats();
        $queryStats = QueryCache::getInstance()->getStats();

        // Get settings
        $cacheEnabled = (bool) $this->settings->get('cache.pages_enabled', true);
        $cacheTtl = (int) $this->settings->get('cache.pages_ttl', 3600);
        $autoWarm = (bool) $this->settings->get('cache.auto_warm', false);

        // Database storage settings
        $storageBackend = (string) $this->settings->get('cache.storage_backend', 'database');
        $compressionEnabled = (bool) $this->settings->get('cache.compression_enabled', true);
        $compressionLevel = (int) $this->settings->get('cache.compression_level', 6);

        return $this->view->render($response, 'admin/cache.twig', [
            'page_title' => 'Cache Management',
            'page_stats' => $pageStats,
            'query_stats' => $queryStats,
            'cache_enabled' => $cacheEnabled,
            'cache_ttl' => $cacheTtl,
            'auto_warm' => $autoWarm,
            'storage_backend' => $storageBackend,
            'compression_enabled' => $compressionEnabled,
            'compression_level' => $compressionLevel,
            'csrf' => $_SESSION['csrf'] ?? '',
        ]);
    }

    /**
     * Clear all caches.
     */
    public function clearAll(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $pageCleared = $this->pageCacheService->clearAll();
        QueryCache::getInstance()->flush();

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Cache cleared: {$pageCleared} page cache entries deleted",
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => "Cache cleared successfully. {$pageCleared} entries deleted.",
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Clear specific cache type.
     */
    public function clearType(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $type = $args['type'] ?? '';
        $cleared = 0;
        $regenerated = false;
        $cacheService = new CacheWarmService($this->db);

        switch ($type) {
            case 'home':
                $cleared = $this->pageCacheService->invalidateHome();
                $regenerated = $cacheService->buildHomeCache();
                break;
            case 'galleries':
                $cleared = $this->pageCacheService->invalidateGalleries();
                $regenerated = $cacheService->buildGalleriesCache();
                break;
            case 'albums':
                // Clear all album caches
                $stats = $this->pageCacheService->getStats();
                foreach ($stats['items'] as $item) {
                    if (str_starts_with($item['type'], 'album:')) {
                        $cleared += $this->pageCacheService->invalidate($item['type']);
                    }
                }
                // Regenerate all album caches
                $albumsRegenerated = $cacheService->buildAlbumCaches();
                $regenerated = $albumsRegenerated > 0;
                break;
            case 'query':
                QueryCache::getInstance()->flush();
                $cleared = 1;
                break;
            default:
                // Handle individual album cache keys (album:slug-name)
                if (str_starts_with($type, 'album:')) {
                    // Validate slug format to prevent path traversal
                    $slug = substr($type, 6); // Remove 'album:' prefix
                    if (preg_match('/^[a-z0-9\-]+$/', $slug)) {
                        $cleared = $this->pageCacheService->invalidate($type);
                        $regenerated = $cacheService->buildAlbumCache($slug);
                        break;
                    }
                }

                // Reject unknown types to prevent path traversal
                if ($this->isAjaxRequest($request)) {
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'message' => 'Unknown cache type',
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
                }
                $_SESSION['flash'][] = [
                    'type' => 'error',
                    'message' => 'Unknown cache type.',
                ];
                return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
        }

        $statusMsg = $regenerated
            ? "Cache '{$type}' refreshed"
            : "Cache '{$type}' cleared";

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'cleared' => $cleared,
                'regenerated' => $regenerated,
                'message' => $statusMsg,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => $statusMsg,
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Warm all page caches.
     *
     * Builds cache data directly without HTTP requests.
     */
    public function warmAll(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        // Clear existing cache first
        $cleared = $this->pageCacheService->clearAll();
        QueryCache::getInstance()->flush();

        // Build cache data directly using CacheWarmService
        $cacheService = new CacheWarmService($this->db);
        $stats = $cacheService->warmAll();

        // Build summary message
        $cached = ($stats['home'] ? 1 : 0) + ($stats['galleries'] ? 1 : 0) + $stats['albums'];
        $message = "Cache warmed: {$cached} pages cached";

        // Build details list to avoid unbalanced parentheses
        $details = [];
        if ($stats['home']) $details[] = 'home';
        if ($stats['galleries']) $details[] = 'galleries';
        if ($stats['albums'] > 0) $details[] = "{$stats['albums']} albums";

        if (!empty($details)) {
            $message .= ' (' . implode(', ', $details) . ')';
        }

        if (!empty($stats['errors'])) {
            $message .= '. Errors: ' . count($stats['errors']);
        }

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => empty($stats['errors']),
                'message' => $message,
                'cleared' => $cleared,
                'cached' => $cached,
                'stats' => $stats,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = [
            'type' => empty($stats['errors']) ? 'success' : 'warning',
            'message' => $message,
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Save cache settings.
     */
    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $enabled = isset($body['cache_enabled']) && $body['cache_enabled'] === 'on';
        $ttl = max(60, min(86400, (int) ($body['cache_ttl'] ?? 3600))); // 1 min to 24 hours
        $autoWarm = isset($body['auto_warm']) && $body['auto_warm'] === 'on';

        // Storage backend settings
        $storageBackend = (string) ($body['storage_backend'] ?? 'database');
        if (!in_array($storageBackend, ['database', 'file'], true)) {
            $storageBackend = 'database';
        }
        $compressionEnabled = isset($body['compression_enabled']) && $body['compression_enabled'] === 'on';
        $compressionLevel = max(1, min(9, (int) ($body['compression_level'] ?? 6)));

        $this->settings->set('cache.pages_enabled', $enabled);
        $this->settings->set('cache.pages_ttl', $ttl);
        $this->settings->set('cache.auto_warm', $autoWarm);
        $this->settings->set('cache.storage_backend', $storageBackend);
        $this->settings->set('cache.compression_enabled', $compressionEnabled);
        $this->settings->set('cache.compression_level', $compressionLevel);

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Cache settings saved',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => 'Cache settings saved successfully.',
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Generate LQIP for all public images (via CLI command).
     */
    public function generateLQIP(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $consolePath = dirname(__DIR__, 3) . '/bin/console';
        if (!is_file($consolePath) || !is_readable($consolePath)) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Console script not available.',
            ];
            return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
        }

        // Run LQIP generation in background
        $logFile = sys_get_temp_dir() . '/lqip_generation.log';
        $cmd = 'nohup php ' . escapeshellarg($consolePath)
            . ' images:generate-lqip'
            . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => 'LQIP generation started in background. Log: ' . $logFile,
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Force regenerate LQIP for all public images (via CLI command).
     */
    public function generateLQIPForce(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $consolePath = dirname(__DIR__, 3) . '/bin/console';
        if (!is_file($consolePath) || !is_readable($consolePath)) {
            $_SESSION['flash'][] = [
                'type' => 'error',
                'message' => 'Console script not available.',
            ];
            return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
        }

        // Run LQIP generation with --force in background
        $logFile = sys_get_temp_dir() . '/lqip_generation.log';
        $cmd = 'nohup php ' . escapeshellarg($consolePath)
            . ' images:generate-lqip --force'
            . ' > ' . escapeshellarg($logFile) . ' 2>&1 &';
        exec($cmd);

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => 'LQIP force regeneration started in background. Log: ' . $logFile,
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Clear Twig template cache.
     */
    public function clearTwig(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $twigCachePath = dirname(__DIR__, 3) . '/storage/cache/twig';
        $cleared = 0;

        if (is_dir($twigCachePath)) {
            $cleared = $this->deleteDirectory($twigCachePath, true);
        }

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Twig cache cleared: {$cleared} files deleted",
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => "Twig cache cleared successfully. {$cleared} files deleted.",
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Clear absolutely all caches (page, query, Twig).
     */
    public function clearEverything(Request $request, Response $response): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        // Clear page cache
        $pageCleared = $this->pageCacheService->clearAll();

        // Clear query cache
        QueryCache::getInstance()->flush();

        // Clear Twig cache
        $twigCachePath = dirname(__DIR__, 3) . '/storage/cache/twig';
        $twigCleared = 0;
        if (is_dir($twigCachePath)) {
            $twigCleared = $this->deleteDirectory($twigCachePath, true);
        }

        $message = "All caches cleared: {$pageCleared} page entries, query cache, {$twigCleared} Twig files";

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => $message,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'][] = [
            'type' => 'success',
            'message' => $message,
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Recursively delete directory contents.
     *
     * @param string $path Directory path
     * @param bool $keepRoot Keep the root directory itself
     * @return int Number of files deleted
     */
    private function deleteDirectory(string $path, bool $keepRoot = false): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $deleted = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                if (@unlink($item->getPathname())) {
                    $deleted++;
                }
            }
        }

        if (!$keepRoot) {
            @rmdir($path);
        }

        return $deleted;
    }

}
