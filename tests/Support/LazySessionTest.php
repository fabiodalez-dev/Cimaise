<?php

declare(strict_types=1);

use App\Support\LazySession;
use PHPUnit\Framework\TestCase;

final class LazySessionTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    public function testOnlyCookieLessReadsAreCandidates(): void
    {
        self::assertTrue(LazySession::isCandidate('GET', [], 'PHPSESSID'));
        self::assertTrue(LazySession::isCandidate('head', ['other' => 'x'], 'PHPSESSID'));
        self::assertFalse(LazySession::isCandidate('POST', [], 'PHPSESSID'));
        self::assertFalse(LazySession::isCandidate('GET', ['PHPSESSID' => 'abc'], 'PHPSESSID'));
    }

    public function testSessionWithOnlyAnUnusedTokenIsDropped(): void
    {
        self::assertFalse(LazySession::shouldKeep(['csrf' => self::TOKEN], '<html>no forms here</html>'));
        self::assertFalse(LazySession::shouldKeep(['csrf' => self::TOKEN], null));
    }

    public function testPageEmbeddingTheTokenKeepsTheSession(): void
    {
        $html = '<form><input type="hidden" name="csrf" value="' . self::TOKEN . '"></form>';
        self::assertTrue(LazySession::shouldKeep(['csrf' => self::TOKEN], $html));
        self::assertTrue(LazySession::shouldKeep(['csrf' => self::TOKEN], "const csrfToken = '" . self::TOKEN . "';"));
    }

    public function testRealSessionDataKeepsTheSession(): void
    {
        self::assertTrue(LazySession::shouldKeep(['csrf' => self::TOKEN, 'nsfw_confirmed_global' => true], ''));
        self::assertTrue(LazySession::shouldKeep(['album_access' => [5 => time()]], null));
    }

    public function testEmptyLeftoversDoNotKeepTheSession(): void
    {
        self::assertFalse(LazySession::shouldKeep(['csrf' => self::TOKEN, 'flash' => [], 'x' => null, 'y' => '', 'z' => false], 'body'));
    }

    public function testOnlyTextualBodiesAreScanned(): void
    {
        self::assertTrue(LazySession::isScannable('text/html; charset=UTF-8'));
        self::assertTrue(LazySession::isScannable('application/json'));
        self::assertTrue(LazySession::isScannable(''));
        self::assertFalse(LazySession::isScannable('image/avif'));
        self::assertFalse(LazySession::isScannable('application/zip'));
    }

    public function testTokenIsFoundInsideABodyCompressedByPhp(): void
    {
        $html = '<input name="csrf" value="' . self::TOKEN . '">';
        $session = ['csrf' => self::TOKEN];
        self::assertTrue(LazySession::decide($session, 'text/html', gzencode($html), 'gzip'));
        self::assertTrue(LazySession::decide($session, 'text/html', gzdeflate($html), 'deflate'));
        self::assertFalse(LazySession::decide($session, 'text/html', gzencode('<p>no form</p>'), 'gzip'));
    }

    public function testUnreadableTextBodiesKeepTheSession(): void
    {
        $session = ['csrf' => self::TOKEN];
        self::assertTrue(LazySession::decide($session, 'text/html', 'not-gzip-bytes', 'gzip'), 'Undecodable: keep rather than break a form');
        self::assertTrue(LazySession::decide($session, 'text/html', null, ''), 'Body too large or not seekable');
        self::assertFalse(LazySession::decide($session, 'image/avif', null, ''), 'Images never carry the token');
    }

    public function testFinalizeIsANoOpWhenNoLazySessionWasStarted(): void
    {
        LazySession::reset();
        $response = (new \Slim\Psr7\Response())->withHeader('X-CSRF-Token', self::TOKEN);
        self::assertSame($response, LazySession::finalize($response));
    }
}
