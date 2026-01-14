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
        $this->pageCacheService = new PageCacheService($this->settings);
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

        return $this->view->render($response, 'admin/cache.twig', [
            'page_title' => 'Cache Management',
            'page_stats' => $pageStats,
            'query_stats' => $queryStats,
            'cache_enabled' => $cacheEnabled,
            'cache_ttl' => $cacheTtl,
            'auto_warm' => $autoWarm,
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

        $_SESSION['flash'] = [
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

        switch ($type) {
            case 'home':
                $cleared = $this->pageCacheService->invalidateHome();
                break;
            case 'galleries':
                $cleared = $this->pageCacheService->invalidateGalleries();
                break;
            case 'albums':
                // Clear all album caches
                $stats = $this->pageCacheService->getStats();
                foreach ($stats['items'] as $item) {
                    if (str_starts_with($item['type'], 'album:')) {
                        $cleared += $this->pageCacheService->invalidate($item['type']);
                    }
                }
                break;
            case 'query':
                QueryCache::getInstance()->flush();
                $cleared = 1;
                break;
            default:
                // Try as specific page type
                $cleared = $this->pageCacheService->invalidate($type);
        }

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'cleared' => $cleared,
                'message' => "Cleared {$cleared} cache entries for: {$type}",
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => "Cache '{$type}' cleared. {$cleared} entries deleted.",
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

        $_SESSION['flash'] = [
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

        $body = $request->getParsedBody();

        $enabled = isset($body['cache_enabled']) && $body['cache_enabled'] === 'on';
        $ttl = max(60, min(86400, (int) ($body['cache_ttl'] ?? 3600))); // 1 min to 24 hours
        $autoWarm = isset($body['auto_warm']) && $body['auto_warm'] === 'on';

        $this->settings->set('cache.pages_enabled', $enabled);
        $this->settings->set('cache.pages_ttl', $ttl);
        $this->settings->set('cache.auto_warm', $autoWarm);

        if ($this->isAjaxRequest($request)) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Cache settings saved',
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'Cache settings saved successfully.',
        ];

        return $response->withHeader('Location', $this->redirect('/admin/cache'))->withStatus(302);
    }

    /**
     * Format bytes to human readable size.
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
