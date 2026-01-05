<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use App\Services\SettingsService;

class CompressionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SettingsService $settings
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        // Get compression settings
        $compressionEnabled = $this->settings->get('performance.compression_enabled', true);
        $compressionType = $this->settings->get('performance.compression_type', 'auto');
        $compressionLevel = $this->settings->get('performance.compression_level', 6);

        if (!$compressionEnabled) {
            return $handler->handle($request);
        }

        // Get response
        $response = $handler->handle($request);

        // Don't compress if already compressed or if response is too small
        $contentLength = $response->getBody()->getSize();
        if ($response->hasHeader('Content-Encoding') || $contentLength < 860) {
            return $response;
        }

        // Check content type - only compress compressible types
        $contentType = $response->getHeaderLine('Content-Type');
        if (!$this->isCompressible($contentType)) {
            return $response;
        }

        // Get accepted encodings from client
        $acceptEncoding = $request->getHeaderLine('Accept-Encoding');

        // Determine which compression to use
        $useCompression = $this->selectCompression($acceptEncoding, $compressionType);

        if (!$useCompression) {
            return $response;
        }

        // Get response body
        $body = (string) $response->getBody();

        // Compress based on selected method
        $compressed = match($useCompression) {
            'br' => $this->compressBrotli($body, $compressionLevel),
            'gzip' => $this->compressGzip($body, $compressionLevel),
            'deflate' => $this->compressDeflate($body, $compressionLevel),
            default => null
        };

        if ($compressed === null) {
            return $response;
        }

        // Update response with compressed content
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $compressed);
        rewind($stream);

        $compressedBody = new \Slim\Psr7\Stream($stream);

        return $response
            ->withBody($compressedBody)
            ->withHeader('Content-Encoding', $useCompression)
            ->withHeader('Vary', 'Accept-Encoding')
            ->withoutHeader('Content-Length'); // Let the server recalculate
    }

    private function selectCompression(string $acceptEncoding, string $preferredType): ?string
    {
        $acceptEncoding = strtolower($acceptEncoding);

        // If auto, prefer brotli > gzip > deflate
        if ($preferredType === 'auto') {
            if (str_contains($acceptEncoding, 'br') && function_exists('brotli_compress')) {
                return 'br';
            }
            if (str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
                return 'gzip';
            }
            if (str_contains($acceptEncoding, 'deflate') && function_exists('gzdeflate')) {
                return 'deflate';
            }
            return null;
        }

        // Use specific compression if available and supported
        if ($preferredType === 'brotli' && str_contains($acceptEncoding, 'br') && function_exists('brotli_compress')) {
            return 'br';
        }
        if ($preferredType === 'gzip' && str_contains($acceptEncoding, 'gzip') && function_exists('gzencode')) {
            return 'gzip';
        }

        return null;
    }

    private function compressBrotli(string $data, int $level): ?string
    {
        if (!function_exists('brotli_compress')) {
            return null;
        }

        // Brotli level ranges from 0 to 11
        $brotliLevel = min(11, max(0, $level));

        $mode = defined('BROTLI_TEXT') ? BROTLI_TEXT : 1;
        $compressed = brotli_compress($data, $brotliLevel, $mode);
        return $compressed !== false ? $compressed : null;
    }

    private function compressGzip(string $data, int $level): ?string
    {
        if (!function_exists('gzencode')) {
            return null;
        }

        // Gzip level ranges from 1 to 9
        $gzipLevel = min(9, max(1, $level));

        $compressed = gzencode($data, $gzipLevel);
        return $compressed !== false ? $compressed : null;
    }

    private function compressDeflate(string $data, int $level): ?string
    {
        if (!function_exists('gzdeflate')) {
            return null;
        }

        // Deflate level ranges from 1 to 9
        $deflateLevel = min(9, max(1, $level));

        $compressed = gzdeflate($data, $deflateLevel);
        return $compressed !== false ? $compressed : null;
    }

    private function isCompressible(string $contentType): bool
    {
        $compressibleTypes = [
            'text/html',
            'text/css',
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
            'application/json',
            'application/xml',
            'text/xml',
            'application/rss+xml',
            'application/atom+xml',
            'text/plain',
            'image/svg+xml',
            'application/font-woff',
            'application/font-woff2',
            'application/x-font-ttf',
            'application/x-font-opentype',
            'application/vnd.ms-fontobject'
        ];

        foreach ($compressibleTypes as $type) {
            if (str_contains(strtolower($contentType), $type)) {
                return true;
            }
        }

        return false;
    }
}
