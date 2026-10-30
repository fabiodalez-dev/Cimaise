<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Read-only HTTP checks of the speed-related behavior of a running site:
 *
 *   CIMAISE_SMOKE_URL=http://localhost:8000 vendor/bin/phpunit tests/Smoke/PerformanceSmokeTest.php
 *
 * Optional CIMAISE_SMOKE_ALBUM=<slug> for the album checks. Skipped unless
 * CIMAISE_SMOKE_URL is set.
 */
final class PerformanceSmokeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $base = rtrim((string) getenv('CIMAISE_SMOKE_URL'), '/');
        if ($base === '') {
            self::markTestSkipped('Set CIMAISE_SMOKE_URL to run the performance smoke test');
        }
        if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#', $base)) {
            self::fail('CIMAISE_SMOKE_URL must be a plain http(s) URL');
        }
        $this->base = $base;
    }

    /**
     * @param string[] $headers
     * @return array{status: int, headers: array<string, string[]>, body: string}
     */
    private function get(string $path, array $headers = [], bool $decode = true): array
    {
        $received = [];
        $curl = curl_init($this->base . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => $decode ? '' : null,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $received[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'headers' => $received, 'body' => is_string($body) ? $body : ''];
    }

    private static function header(array $response, string $name): string
    {
        return implode(', ', $response['headers'][strtolower($name)] ?? []);
    }

    private function albumPath(): string
    {
        $slug = (string) getenv('CIMAISE_SMOKE_ALBUM');
        if ($slug === '') {
            preg_match('#href="(?:[^"]*)?/album/([A-Za-z0-9._~-]+)"#', $this->get('/')['body'], $match);
            $slug = $match[1] ?? '';
        }
        if ($slug === '') {
            self::markTestSkipped('No public album found; set CIMAISE_SMOKE_ALBUM');
        }
        return '/album/' . $slug;
    }

    public function testCookieLessHomeCreatesNoSession(): void
    {
        $home = $this->get('/');
        self::assertSame(200, $home['status']);
        self::assertStringNotContainsString('PHPSESSID', self::header($home, 'Set-Cookie'));
        self::assertSame('', self::header($home, 'X-CSRF-Token'));
    }

    public function testPagesWithFormsStillGetAWorkingSession(): void
    {
        $login = $this->get('/admin/login');
        self::assertSame(200, $login['status']);
        self::assertStringContainsString('PHPSESSID=', self::header($login, 'Set-Cookie'));
        $token = self::header($login, 'X-CSRF-Token');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertStringContainsString($token, $login['body'], 'The token the cookie belongs to is the one in the form');
    }

    public function testLayoutRuntimeIsAnExternalLongCachedScript(): void
    {
        $home = $this->get('/');
        self::assertMatchesRegularExpression('#src="[^"]*/assets/js/frontend-layout\.js\?v=[^"]+"#', $home['body']);
        preg_match('#src="([^"]*/assets/js/frontend-layout\.js\?v=[^"]+)"#', $home['body'], $m);
        $script = $this->get(parse_url($m[1], PHP_URL_PATH) . '?' . parse_url($m[1], PHP_URL_QUERY));
        self::assertSame(200, $script['status']);
        self::assertStringContainsString('immutable', self::header($script, 'Cache-Control'));
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $home['body'], $inline);
        self::assertLessThan(20 * 1024, max(array_map('strlen', $inline[1] ?: [''])), 'No huge inline script on every page');
    }

    public function testNoPreloadOfAssetsThePageDoesNotUse(): void
    {
        $album = $this->get($this->albumPath());
        self::assertStringNotContainsString('/fonts/typography.css>', self::header($album, 'Link'), 'Unversioned typography.css is a second download of the stylesheet');
        foreach ($album['headers']['link'] ?? [] as $link) {
            preg_match_all('#<([^>]+)>#', $link, $urls);
            foreach ($urls[1] as $url) {
                self::assertStringContainsString($url, $album['body'], "Link header preloads $url but the page never references it");
            }
        }
    }

    public function testCookieLessRepeatViewIsServedFromTheHtmlCacheWithAFreshNonce(): void
    {
        $path = $this->albumPath();
        $this->get($path);
        $second = $this->get($path);
        self::assertSame(200, $second['status']);
        self::assertSame('hit', self::header($second, 'X-Cimaise-Cache'));
        preg_match("/'nonce-([^']+)'/", self::header($second, 'Content-Security-Policy'), $nonce);
        self::assertNotEmpty($nonce[1] ?? '');
        self::assertStringContainsString('nonce="' . $nonce[1] . '"', $second['body']);
        self::assertSame(0, preg_match('/__CIMAISE_CSP_NONCE__/', $second['body']));
    }

    public function testRequestsWithCookiesBypassTheHtmlCache(): void
    {
        $path = $this->albumPath();
        $this->get($path);
        $withCookie = $this->get($path, ['Cookie: smoke=1']);
        self::assertSame(200, $withCookie['status']);
        self::assertSame('', self::header($withCookie, 'X-Cimaise-Cache'));
    }

    public function testMediaFastPathKeepsHeadersAndRevalidation(): void
    {
        preg_match('#(/media/\d+_[a-z0-9]+\.(?:avif|webp|jpg))#', $this->get($this->albumPath())['body'], $m);
        if (!isset($m[1])) {
            self::markTestSkipped('No public variant on the album page');
        }
        $image = $this->get($m[1]);
        self::assertSame(200, $image['status']);
        self::assertStringStartsWith('image/', self::header($image, 'Content-Type'));
        self::assertSame('nosniff', self::header($image, 'X-Content-Type-Options'));
        self::assertNotSame('', self::header($image, 'Content-Security-Policy'));
        $etag = self::header($image, 'ETag');
        self::assertNotSame('', $etag);
        self::assertSame(304, $this->get($m[1], ['If-None-Match: ' . $etag])['status']);
        self::assertSame(404, $this->get('/media/999999999_lg.jpg')['status']);
    }

    public function testWebServerCompressionIsNotDuplicatedByPhp(): void
    {
        $brotli = $this->get('/', ['Accept-Encoding: br'], false);
        if (self::header($brotli, 'Content-Encoding') !== 'br') {
            self::markTestSkipped('Server does not compress with brotli');
        }
        // mod_deflate only speaks gzip; PHP alone would answer "deflate".
        self::assertNotSame('deflate', self::header($this->get('/', ['Accept-Encoding: deflate'], false), 'Content-Encoding'));
    }
}
