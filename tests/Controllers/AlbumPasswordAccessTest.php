<?php

declare(strict_types=1);

use App\Controllers\BaseController;
use PHPUnit\Framework\TestCase;

final class AlbumPasswordAccessTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testRotatingPasswordRevokesExistingAlbumGrant(): void
    {
        $controller = new AlbumPasswordAccessHarness();
        $controller->grant(42, '$hash-in-force');

        self::assertTrue($controller->hasAccess(42, '$hash-in-force'));
        self::assertFalse($controller->hasAccess(42, '$rotated-hash'));
        self::assertArrayNotHasKey(42, $_SESSION['album_access'] ?? []);
        self::assertArrayNotHasKey(42, $_SESSION['album_access_fp'] ?? []);
    }
}

final class AlbumPasswordAccessHarness extends BaseController
{
    public function grant(int $albumId, string $hash): void
    {
        $this->grantAlbumPasswordAccess($albumId, $hash);
    }

    public function hasAccess(int $albumId, string $hash): bool
    {
        return $this->hasAlbumPasswordAccess($albumId, $hash);
    }
}
