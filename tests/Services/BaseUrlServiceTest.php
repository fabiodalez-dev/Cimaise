<?php

declare(strict_types=1);

use App\Services\BaseUrlService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class BaseUrlServiceTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverSnapshot = [];
    private mixed $appUrlSnapshot;

    protected function setUp(): void
    {
        foreach (['HTTPS', 'REQUEST_SCHEME', 'SERVER_PORT', 'HTTP_HOST', 'SCRIPT_NAME'] as $key) {
            $this->serverSnapshot[$key] = $_SERVER[$key] ?? null;
        }
        $this->appUrlSnapshot = $_ENV['APP_URL'] ?? null;
        unset($_ENV['APP_URL']);
    }

    protected function tearDown(): void
    {
        foreach ($this->serverSnapshot as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
        if ($this->appUrlSnapshot === null) {
            unset($_ENV['APP_URL']);
        } else {
            $_ENV['APP_URL'] = $this->appUrlSnapshot;
        }
    }

    public function testCanonicalRootsPreserveRequestAuthorityAndSubdirectory(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'https://photos.example:8443/cms/album/night'
        );

        self::assertSame([
            'root' => 'https://photos.example:8443',
            'base' => 'https://photos.example:8443/cms',
        ], BaseUrlService::canonicalRoots($request, '/cms'));
    }

    public function testConfiguredCanonicalPathIsReducedToOriginWithoutDoublingBasePath(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://internal/cms/album/night');

        self::assertSame([
            'root' => 'https://public.example:9443',
            'base' => 'https://public.example:9443/cms',
        ], BaseUrlService::canonicalRoots($request, '/cms', 'https://public.example:9443/cms/'));
    }

    public function testAutomaticBaseUrlAppendsPortToBracketedIpv6Host(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['SERVER_PORT'] = '8443';
        $_SERVER['HTTP_HOST'] = '[2001:db8::1]';
        $_SERVER['SCRIPT_NAME'] = '/public/index.php';

        self::assertSame('https://[2001:db8::1]:8443', BaseUrlService::getCurrentBaseUrl());
    }
}
