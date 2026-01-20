<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\UpdateDownloadResolver;

final class UpdateDownloadResolverTest extends TestCase
{
    public function testPrefersCustomZipAsset(): void
    {
        $release = [
            'tag_name' => 'v1.2.3',
            'zipball_url' => 'https://api.github.com/repos/owner/repo/zipball/v1.2.3',
            'assets' => [
                ['name' => 'cimaise-v1.2.3.zip', 'browser_download_url' => 'https://example.com/custom.zip'],
                ['name' => 'other.tar.gz', 'browser_download_url' => 'https://example.com/other.tar.gz'],
            ],
        ];

        $urls = UpdateDownloadResolver::buildCandidates('owner', 'repo', $release);

        $this->assertSame('https://example.com/custom.zip', $urls[0], 'Custom asset should be first');
        $this->assertContains('https://github.com/owner/repo/archive/refs/tags/v1.2.3.zip', $urls);
    }

    public function testFallsBackToArchiveWhenNoAssets(): void
    {
        $release = [
            'tag_name' => 'v2.0.0',
            'zipball_url' => 'https://api.github.com/repos/owner/repo/zipball/v2.0.0',
            'assets' => [],
        ];

        $urls = UpdateDownloadResolver::buildCandidates('owner', 'repo', $release);

        $this->assertSame(
            'https://github.com/owner/repo/archive/refs/tags/v2.0.0.zip',
            $urls[0],
            'Archive URL should be first when no custom assets exist'
        );
        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/v2.0.0', $urls[1]);
    }

    public function testDeduplicatesUrls(): void
    {
        $release = [
            'tag_name' => 'v3.0.0',
            'zipball_url' => 'https://api.github.com/repos/owner/repo/zipball/v3.0.0',
            'assets' => [
                ['name' => 'cimaise-v3.0.0.zip', 'browser_download_url' => 'https://example.com/cimaise-v3.0.0.zip'],
                ['name' => 'dup.zip', 'browser_download_url' => 'https://example.com/cimaise-v3.0.0.zip'], // duplicate
            ],
        ];

        $urls = UpdateDownloadResolver::buildCandidates('owner', 'repo', $release);

        $this->assertCount(3, $urls, 'Duplicates should be removed while preserving order');
        $this->assertSame([
            'https://example.com/cimaise-v3.0.0.zip',
            'https://github.com/owner/repo/archive/refs/tags/v3.0.0.zip',
            'https://api.github.com/repos/owner/repo/zipball/v3.0.0',
        ], $urls);
    }
}
