<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;

/**
 * Service for querying the Lensfun camera/lens database.
 * Parses XML files from https://github.com/lensfun/lensfun
 */
class LensfunService
{
    private string $dataDir;
    private ?array $cameras = null;
    private ?array $lenses = null;
    private ?string $cacheFile = null;

    public function __construct(?string $dataDir = null)
    {
        $this->dataDir = $dataDir ?? dirname(__DIR__, 2) . '/storage/lensfun';
        $this->cacheFile = dirname(__DIR__, 2) . '/storage/cache/lensfun.json';
    }

    /**
     * Search unique camera makers by query string.
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @param bool $withCount If true, returns ['results' => [...], 'total' => N]
     * @return array Unique matching makers or array with results and total count
     */
    public function searchMakers(string $query, int $limit = 20, bool $withCount = false): array
    {
        $this->loadData();

        $makers = array_unique(array_column($this->cameras, 'maker'));
        sort($makers, SORT_STRING | SORT_FLAG_CASE);

        if (empty($query)) {
            $total = count($makers);
            $results = array_slice(array_map(fn($m) => ['maker' => $m], $makers), 0, $limit);
            return $withCount ? ['results' => $results, 'total' => $total] : $results;
        }

        $query = strtolower(trim($query));
        $allMatches = [];

        foreach ($makers as $maker) {
            if (str_contains(strtolower($maker), $query)) {
                $allMatches[] = ['maker' => $maker];
            }
        }

        $total = count($allMatches);
        $results = array_slice($allMatches, 0, $limit);

        return $withCount ? ['results' => $results, 'total' => $total] : $results;
    }

    /**
     * Search cameras by query string.
     * Supports multi-word search: all words must be present (but not necessarily contiguous).
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @param string|null $maker Optional: filter by maker
     * @param bool $withCount If true, returns ['results' => [...], 'total' => N]
     * @return array Matching cameras or array with results and total count
     */
    public function searchCameras(string $query, int $limit = 20, ?string $maker = null, bool $withCount = false): array
    {
        $this->loadData();

        // Pre-filter by maker if specified
        $cameraPool = $this->cameras;
        if ($maker !== null && $maker !== '') {
            $makerLower = strtolower(trim($maker));
            $cameraPool = array_filter($this->cameras, fn($c) =>
                strtolower($c['maker']) === $makerLower
            );
        }

        if (empty($query)) {
            $total = count($cameraPool);
            $results = array_slice(array_values($cameraPool), 0, $limit);
            return $withCount ? ['results' => $results, 'total' => $total] : $results;
        }

        $query = strtolower(trim($query));
        $queryWords = preg_split('/\s+/', $query);
        $allMatches = [];

        foreach ($cameraPool as $camera) {
            $searchText = strtolower($camera['maker'] . ' ' . $camera['model']);

            // All query words must be present in searchText
            $allMatch = true;
            foreach ($queryWords as $word) {
                if (!str_contains($searchText, $word)) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                $allMatches[] = $camera;
            }
        }

        $total = count($allMatches);
        $results = array_slice($allMatches, 0, $limit);

        return $withCount ? ['results' => $results, 'total' => $total] : $results;
    }

    /**
     * Search lenses by query string.
     * Supports multi-word search: all words must be present (but not necessarily contiguous).
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @param string|null $maker Optional: filter by maker
     * @param bool $withCount If true, returns ['results' => [...], 'total' => N]
     * @return array Matching lenses or array with results and total count
     */
    public function searchLenses(string $query, int $limit = 20, ?string $maker = null, bool $withCount = false): array
    {
        $this->loadData();

        // Pre-filter by maker if specified
        $lensPool = $this->lenses;
        if ($maker !== null && $maker !== '') {
            $makerLower = strtolower(trim($maker));
            $lensPool = array_filter($this->lenses, fn($l) =>
                strtolower($l['maker']) === $makerLower
            );
        }

        if (empty($query)) {
            $total = count($lensPool);
            $results = array_slice(array_values($lensPool), 0, $limit);
            return $withCount ? ['results' => $results, 'total' => $total] : $results;
        }

        $query = strtolower(trim($query));
        $queryWords = preg_split('/\s+/', $query);
        $allMatches = [];

        foreach ($lensPool as $lens) {
            $searchText = strtolower($lens['maker'] . ' ' . $lens['model']);

            // All query words must be present in searchText
            $allMatch = true;
            foreach ($queryWords as $word) {
                if (!str_contains($searchText, $word)) {
                    $allMatch = false;
                    break;
                }
            }

            if ($allMatch) {
                $allMatches[] = $lens;
            }
        }

        $total = count($allMatches);
        $results = array_slice($allMatches, 0, $limit);

        return $withCount ? ['results' => $results, 'total' => $total] : $results;
    }

    /**
     * Search unique lens makers by query string.
     *
     * @param string $query Search query
     * @param int $limit Maximum results
     * @param bool $withCount If true, returns ['results' => [...], 'total' => N]
     * @return array Unique matching lens makers or array with results and total count
     */
    public function searchLensMakers(string $query, int $limit = 20, bool $withCount = false): array
    {
        $this->loadData();

        $makers = array_unique(array_column($this->lenses, 'maker'));
        sort($makers, SORT_STRING | SORT_FLAG_CASE);

        if (empty($query)) {
            $total = count($makers);
            $results = array_slice(array_map(fn($m) => ['maker' => $m], $makers), 0, $limit);
            return $withCount ? ['results' => $results, 'total' => $total] : $results;
        }

        $query = strtolower(trim($query));
        $allMatches = [];

        foreach ($makers as $maker) {
            if (str_contains(strtolower($maker), $query)) {
                $allMatches[] = ['maker' => $maker];
            }
        }

        $total = count($allMatches);
        $results = array_slice($allMatches, 0, $limit);

        return $withCount ? ['results' => $results, 'total' => $total] : $results;
    }

    /**
     * Get all unique camera makers.
     */
    public function getCameraMakers(): array
    {
        $this->loadData();
        return array_values(array_unique(array_column($this->cameras, 'maker')));
    }

    /**
     * Get all unique lens makers.
     */
    public function getLensMakers(): array
    {
        $this->loadData();
        return array_values(array_unique(array_column($this->lenses, 'maker')));
    }

    /**
     * Get cameras by maker.
     */
    public function getCamerasByMaker(string $maker): array
    {
        $this->loadData();
        return array_values(array_filter($this->cameras, fn($c) =>
            strcasecmp($c['maker'], $maker) === 0
        ));
    }

    /**
     * Get lenses by maker.
     */
    public function getLensesByMaker(string $maker): array
    {
        $this->loadData();
        return array_values(array_filter($this->lenses, fn($l) =>
            strcasecmp($l['maker'], $maker) === 0
        ));
    }

    /**
     * Get database statistics.
     */
    public function getStats(): array
    {
        $this->loadData();
        return [
            'cameras' => count($this->cameras),
            'lenses' => count($this->lenses),
            'camera_makers' => count($this->getCameraMakers()),
            'lens_makers' => count($this->getLensMakers()),
        ];
    }

    /**
     * Load data from cache or parse XML files.
     */
    private function loadData(): void
    {
        if ($this->cameras !== null && $this->lenses !== null) {
            return;
        }

        // Try to load from cache
        if ($this->loadFromCache()) {
            return;
        }

        // Parse XML files
        $this->parseXmlFiles();

        // Save to cache
        $this->saveToCache();
    }

    /**
     * Load data from JSON cache file.
     */
    private function loadFromCache(): bool
    {
        if (!$this->cacheFile || !is_file($this->cacheFile)) {
            return false;
        }

        // Check if cache is older than 24 hours
        if (filemtime($this->cacheFile) < time() - 86400) {
            return false;
        }

        try {
            $data = json_decode(file_get_contents($this->cacheFile), true);
            if (isset($data['cameras']) && isset($data['lenses'])) {
                $this->cameras = $data['cameras'];
                $this->lenses = $data['lenses'];
                return true;
            }
        } catch (\Throwable $e) {
            Logger::warning('LensfunService: Failed to load cache', ['error' => $e->getMessage()], 'app');
        }

        return false;
    }

    /**
     * Save data to JSON cache file.
     */
    private function saveToCache(): void
    {
        if (!$this->cacheFile) {
            return;
        }

        try {
            $cacheDir = dirname($this->cacheFile);
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0775, true);
            }

            file_put_contents($this->cacheFile, json_encode([
                'cameras' => $this->cameras,
                'lenses' => $this->lenses,
                'generated' => date('c'),
            ], JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Logger::warning('LensfunService: Failed to save cache', ['error' => $e->getMessage()], 'app');
        }
    }

    /**
     * Parse all XML files in the data directory.
     */
    private function parseXmlFiles(): void
    {
        $this->cameras = [];
        $this->lenses = [];

        if (!is_dir($this->dataDir)) {
            Logger::warning('LensfunService: Data directory not found', ['dir' => $this->dataDir], 'app');
            return;
        }

        $xmlFiles = glob($this->dataDir . '/*.xml');

        foreach ($xmlFiles as $file) {
            $this->parseXmlFile($file);
        }

        // Sort alphabetically
        usort($this->cameras, fn($a, $b) =>
            strcasecmp($a['maker'] . ' ' . $a['model'], $b['maker'] . ' ' . $b['model'])
        );
        usort($this->lenses, fn($a, $b) =>
            strcasecmp($a['maker'] . ' ' . $a['model'], $b['maker'] . ' ' . $b['model'])
        );

        Logger::info('LensfunService: Parsed database', [
            'cameras' => count($this->cameras),
            'lenses' => count($this->lenses),
            'files' => count($xmlFiles)
        ], 'app');
    }

    /**
     * Parse a single XML file.
     */
    private function parseXmlFile(string $file): void
    {
        try {
            $xml = @simplexml_load_file($file);
            if (!$xml) {
                return;
            }

            // Parse cameras
            foreach ($xml->camera ?? [] as $camera) {
                $maker = trim((string)($camera->maker ?? ''));
                $model = trim((string)($camera->model ?? ''));

                if ($maker && $model) {
                    // Clean model - remove maker prefix if present
                    $cleanModel = $model;
                    if (stripos($model, $maker) === 0) {
                        $cleanModel = trim(substr($model, strlen($maker)));
                    }

                    $this->cameras[] = [
                        'maker' => $this->normalizeMaker($maker),
                        'model' => $cleanModel ?: $model,
                        'full_name' => $maker . ' ' . ($cleanModel ?: $model),
                        'mount' => trim((string)($camera->mount ?? '')),
                        'cropfactor' => (float)($camera->cropfactor ?? 1.0),
                    ];
                }
            }

            // Parse lenses
            foreach ($xml->lens ?? [] as $lens) {
                $maker = trim((string)($lens->maker ?? ''));
                $model = trim((string)($lens->model ?? ''));

                if ($model) {
                    // Extract focal length range from model if present
                    $focalRange = $this->extractFocalLength($model);

                    $this->lenses[] = [
                        'maker' => $this->normalizeMaker($maker ?: 'Unknown'),
                        'model' => $model,
                        'full_name' => ($maker ? $maker . ' ' : '') . $model,
                        'mount' => trim((string)($lens->mount ?? '')),
                        'cropfactor' => (float)($lens->cropfactor ?? 1.0),
                        'focal_min' => $focalRange['min'],
                        'focal_max' => $focalRange['max'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('LensfunService: Failed to parse XML', [
                'file' => basename($file),
                'error' => $e->getMessage()
            ], 'app');
        }
    }

    /**
     * Normalize maker name for consistency.
     */
    private function normalizeMaker(string $maker): string
    {
        // Pattern-based normalization for common variations
        $patterns = [
            '/^canon/i' => 'Canon',
            '/^nikon/i' => 'Nikon',
            '/^sony/i' => 'Sony',
            '/^fuji/i' => 'Fujifilm',
            '/^olympus|^om digital/i' => 'Olympus',
            '/^panasonic|^lumix/i' => 'Panasonic',
            '/^pentax|^ricoh imaging/i' => 'Pentax',
            '/^sigma/i' => 'Sigma',
            '/^tamron/i' => 'Tamron',
            '/^samyang|^rokinon/i' => 'Samyang',
            '/^tokina/i' => 'Tokina',
            '/^zeiss|^carl zeiss/i' => 'Zeiss',
            '/^leica/i' => 'Leica',
            '/^hasselblad/i' => 'Hasselblad',
            '/^voigtl/i' => 'Voigtländer',
            '/^samsung/i' => 'Samsung',
            '/^konica|^minolta/i' => 'Konica Minolta',
            '/^mamiya/i' => 'Mamiya',
            '/^ricoh(?! imaging)/i' => 'Ricoh',
            '/^gopro/i' => 'GoPro',
            '/^dji/i' => 'DJI',
            '/^viltrox/i' => 'Viltrox',
            '/^venus|^laowa/i' => 'Laowa',
            '/^casio/i' => 'Casio',
        ];

        foreach ($patterns as $pattern => $normalized) {
            if (preg_match($pattern, $maker)) {
                return $normalized;
            }
        }

        // Fallback: capitalize first letter of each word
        return ucwords(strtolower($maker));
    }

    /**
     * Extract focal length range from lens model string.
     */
    private function extractFocalLength(string $model): array
    {
        $result = ['min' => null, 'max' => null];

        // Match patterns like "24-70mm", "50mm", "24-70 mm"
        if (preg_match('/(\d+)(?:\s*-\s*(\d+))?\s*mm/i', $model, $matches)) {
            $result['min'] = (int)$matches[1];
            $result['max'] = isset($matches[2]) ? (int)$matches[2] : (int)$matches[1];
        }

        return $result;
    }

    /**
     * Force rebuild of cache from XML files.
     */
    public function rebuildCache(): array
    {
        // Clear existing data
        $this->cameras = null;
        $this->lenses = null;

        // Delete cache file
        if ($this->cacheFile && is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }

        // Reload
        $this->loadData();

        return $this->getStats();
    }
}
