<?php

declare(strict_types=1);

use App\Services\OriginalImage;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/TestFilesystem.php';

final class OriginalImageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cimaise-original-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/originals', 0777, true);
        mkdir($this->root . '/public/media/originals', 0777, true);
        file_put_contents($this->root . '/storage/originals/current.jpg', 'a');
        file_put_contents($this->root . '/public/media/originals/legacy.jpg', 'b');
        file_put_contents($this->root . '/storage/secret.env', 'c');
        file_put_contents($this->root . '/public/media/1_sm.jpg', 'd');
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        TestFilesystem::remove($this->root);
    }

    public function testResolvesCurrentAndLegacyLocations(): void
    {
        self::assertSame(realpath($this->root . '/storage/originals/current.jpg'), OriginalImage::resolve('/storage/originals/current.jpg', $this->root));
        // Legacy rows point below public/ without naming it.
        self::assertSame(realpath($this->root . '/public/media/originals/legacy.jpg'), OriginalImage::resolve('/media/originals/legacy.jpg', $this->root));
        self::assertSame(realpath($this->root . '/storage/originals/current.jpg'), OriginalImage::resolve('current.jpg', $this->root));
    }

    public function testRefusesAnythingOutsideTheOriginalsDirectories(): void
    {
        foreach ([
            '',
            '/storage/secret.env',
            '/storage/originals/../secret.env',
            '/media/1_sm.jpg',
            '..\\storage\\secret.env',
            "/storage/originals/current.jpg\0.png",
            '/storage/originals/missing.jpg',
        ] as $path) {
            self::assertNull(OriginalImage::resolve($path, $this->root), var_export($path, true));
        }
    }

    public function testSymlinkLeavingTheOriginalsDirectoryIsRefused(): void
    {
        if (!@symlink($this->root . '/storage/secret.env', $this->root . '/storage/originals/link.jpg')) {
            self::markTestSkipped('symlinks unavailable');
        }
        self::assertNull(OriginalImage::resolve('/storage/originals/link.jpg', $this->root));
    }
}
