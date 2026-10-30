<?php

declare(strict_types=1);

use App\Services\HtmlPageCache;
use PHPUnit\Framework\TestCase;

final class HtmlPageCacheTest extends TestCase
{
    private string $dir;
    private HtmlPageCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cimaise-html-' . bin2hex(random_bytes(6));
        $this->cache = new HtmlPageCache($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function page(string $nonce): string
    {
        return '<html><script nonce="' . $nonce . '">a()</script><style nonce="' . $nonce . '"></style></html>';
    }

    public function testServesTheStoredPageWithTheCurrentRequestNonce(): void
    {
        self::assertTrue($this->cache->put('k', '"e1"', 'OLDNONCE==', $this->page('OLDNONCE=='), ['Content-Type' => ['text/html; charset=UTF-8']]));
        $hit = $this->cache->get('k', '"e1"', 'NEWNONCE==');
        self::assertNotNull($hit);
        self::assertSame($this->page('NEWNONCE=='), $hit['body']);
        self::assertStringNotContainsString('OLDNONCE', $hit['body']);
        self::assertSame(['text/html; charset=UTF-8'], $hit['headers']['Content-Type']);
    }

    public function testAChangedContentValidatorIsAMiss(): void
    {
        $this->cache->put('k', '"e1"', 'N1', $this->page('N1'), []);
        self::assertNull($this->cache->get('k', '"e2"', 'N2'));
    }

    public function testPerVisitorAndRecomputedHeadersAreNotStored(): void
    {
        $this->cache->put('k', '"e1"', 'N1', $this->page('N1'), [
            'Set-Cookie' => ['PHPSESSID=abc'],
            'X-CSRF-Token' => ['secret'],
            'Cache-Control' => ['public'],
            'ETag' => ['"e1"'],
            'X-Robots-Tag' => ['noindex'],
        ]);
        $headers = $this->cache->get('k', '"e1"', 'N2')['headers'] ?? [];
        self::assertSame(['X-Robots-Tag'], array_keys($headers));
    }

    public function testCorruptOrMissingEntriesAreMisses(): void
    {
        self::assertNull($this->cache->get('missing', '"e"', 'N'));
        $this->cache->put('k', '"e1"', 'N1', $this->page('N1'), []);
        file_put_contents($this->dir . '/k.cache', 'not-json');
        self::assertNull($this->cache->get('k', '"e1"', 'N2'));
    }

    public function testRefusesToStoreWithoutANonce(): void
    {
        self::assertFalse($this->cache->put('k', '"e1"', '', '<html></html>', []));
    }

    public function testClearDropsEveryEntry(): void
    {
        $this->cache->put('a', '"e"', 'N', $this->page('N'), []);
        $this->cache->put('b', '"e"', 'N', $this->page('N'), []);
        $this->cache->clear();
        self::assertNull($this->cache->get('a', '"e"', 'N'));
        self::assertNull($this->cache->get('b', '"e"', 'N'));
    }

    public function testKeySeparatesHostSchemePortAndPath(): void
    {
        $base = HtmlPageCache::key('https', 'example.org', null, '/album/x');
        self::assertSame($base, HtmlPageCache::key('HTTPS', 'Example.org', null, '/album/x'));
        self::assertNotSame($base, HtmlPageCache::key('http', 'example.org', null, '/album/x'));
        self::assertNotSame($base, HtmlPageCache::key('https', 'other.org', null, '/album/x'));
        self::assertNotSame($base, HtmlPageCache::key('https', 'example.org', 8443, '/album/x'));
        self::assertNotSame($base, HtmlPageCache::key('https', 'example.org', null, '/album/y'));
    }
}
