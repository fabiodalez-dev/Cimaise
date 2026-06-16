<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\Updater;

/**
 * Coverage for Updater::isTransientGitHubFailure() — the decision that drives
 * the in-request retry loop in makeGitHubRequest().
 *
 * A transient failure (dropped connection, any 5xx — GitHub's "Unicorn!"
 * 502/503/504 — or a 429 soft-throttle) is retried; it must NEVER surface to
 * the user as "Version not found". A 4xx is a real, non-retryable answer
 * (404 = release absent; 401/403 auth are handled separately in httpGet).
 */
final class UpdaterTransientRetryTest extends TestCase
{
    public function testConnectionFailureIsTransientRegardlessOfStatus(): void
    {
        // No HTTP response at all (file_get_contents returned false): status is 0.
        $this->assertTrue(Updater::isTransientGitHubFailure(0, true));
        // Even a would-be-2xx status is moot when the connection itself dropped.
        $this->assertTrue(Updater::isTransientGitHubFailure(200, true));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function serverErrorStatuses(): array
    {
        return [
            '500 Internal Server Error' => [500],
            '502 Bad Gateway'           => [502],
            '503 Service Unavailable'   => [503],
            '504 Gateway Timeout'       => [504], // the exact code that triggered this fix
            '599 (upper bound)'         => [599],
        ];
    }

    /**
     * @dataProvider serverErrorStatuses
     */
    public function testFiveXxIsTransient(int $status): void
    {
        $this->assertTrue(Updater::isTransientGitHubFailure($status, false));
    }

    public function testRateLimitSoftThrottleIsTransient(): void
    {
        $this->assertTrue(Updater::isTransientGitHubFailure(429, false));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonTransientStatuses(): array
    {
        return [
            '200 OK'              => [200],
            '301 Moved'           => [301],
            '400 Bad Request'     => [400],
            '401 Unauthorized'    => [401],
            '403 Forbidden'       => [403],
            '404 Not Found'       => [404], // genuine missing release — do NOT retry
            '422 Unprocessable'   => [422],
        ];
    }

    /**
     * @dataProvider nonTransientStatuses
     */
    public function testNonTransientStatusesAreNotRetried(int $status): void
    {
        $this->assertFalse(Updater::isTransientGitHubFailure($status, false));
    }

    public function testExposesStableTransientErrorCode(): void
    {
        // The 503 transient marker code must stay stable: getReleaseByVersion()
        // re-throws on it (instead of collapsing to null → "Version not found").
        $rc = new ReflectionClass(Updater::class);
        $const = $rc->getConstant('ERR_GITHUB_UNAVAILABLE');
        $this->assertSame(503, $const);
    }
}
