<?php

declare(strict_types=1);

use App\Services\AnalyticsService;
use PHPUnit\Framework\TestCase;

final class AnalyticsDeviceDetectionTest extends TestCase
{
    public function testMobilePlatformsAreDetectedBeforeDesktopCompatibilityTokens(): void
    {
        $service = new AnalyticsService(new PDO('sqlite::memory:'));
        foreach ([
            ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36', 'Android', 'mobile'],
            ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36', 'Android', 'tablet'],
            ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'iOS', 'mobile'],
            ['Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1', 'iOS', 'tablet'],
            ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36', 'Windows', 'desktop'],
            ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15', 'macOS', 'desktop'],
            ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36', 'Linux', 'desktop'],
        ] as [$agent, $platform, $device]) {
            $parsed = $service->parseUserAgent($agent);
            $this->assertSame($platform, $parsed['platform'], $agent);
            $this->assertSame($device, $parsed['device_type'], $agent);
        }
    }
}
