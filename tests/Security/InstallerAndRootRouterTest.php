<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Regressions for audit finding C17 (installer reopens when .env is missing)
 * and the root-router media lead (public/media served without access control).
 * Everything runs in a disposable AppSandbox copy, in child PHP processes.
 */
final class InstallerAndRootRouterTest extends TestCase
{
    private \AppSandbox $sandbox;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Support/TestFilesystem.php';
        require_once dirname(__DIR__) . '/Support/AppSandbox.php';
        $this->sandbox = \AppSandbox::create();
    }

    protected function tearDown(): void
    {
        $this->sandbox->destroy();
    }

    public function testInstallerStaysLockedWhenEnvIsMissingButDatabaseHasAnAdmin(): void
    {
        $pdo = $this->sandbox->database();
        $pdo->exec((string) file_get_contents($this->sandbox->root . '/database/schema.sqlite.sql'));
        $pdo->exec("INSERT INTO users (email, password_hash, role, is_active) VALUES ('owner@x.test', 'h', 'admin', 1)");
        $pdo->exec("INSERT INTO albums (category_id, title, slug, is_published) VALUES (1, 'Keep me', 'keep-me', 1)");
        $pdo = null;
        self::assertFileDoesNotExist($this->sandbox->root . '/.env');

        [, $out] = $this->sandbox->php(
            '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/installer.php?step=requirements";'
            . '$_SERVER["SCRIPT_NAME"] = "/installer.php"; $_SERVER["HTTP_HOST"] = "localhost";'
            . 'require "public/installer.php";'
        );
        self::assertStringContainsString('Existing installation found', $out);

        $count = (int) $this->sandbox->database()->query("SELECT COUNT(*) FROM albums WHERE slug = 'keep-me'")->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testFreshSystemStillOpensTheInstaller(): void
    {
        @unlink($this->sandbox->root . '/database/database.sqlite');
        [, $out] = $this->sandbox->php(
            '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = "/installer.php";'
            . '$_SERVER["SCRIPT_NAME"] = "/installer.php"; $_SERVER["HTTP_HOST"] = "localhost";'
            . 'require "public/installer.php";'
        );
        self::assertStringNotContainsString('Existing installation found', $out);
        self::assertGreaterThan(1000, strlen($out), 'installer page is rendered');
    }

    public function testRootRouterNeverServesPublicMediaDirectly(): void
    {
        copy(dirname(__DIR__, 2) . '/index.php', $this->sandbox->root . '/index.php');
        file_put_contents($this->sandbox->root . '/public/media/1_lg.jpg', "\xFF\xD8\xFFMARKER-UNPUBLISHED");
        foreach (['/assets/../media/1_lg.jpg', '/fonts/../media/1_lg.jpg', '/Media/1_lg.jpg'] as $uri) {
            [, $out] = $this->sandbox->php(
                '$_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . ';'
                . '$_SERVER["SCRIPT_NAME"] = "/index.php"; $_SERVER["HTTP_HOST"] = "localhost";'
                . 'ob_start(); try { require "index.php"; } catch (\Throwable $e) {} echo ob_get_clean();'
            );
            self::assertStringNotContainsString('MARKER-UNPUBLISHED', $out, $uri);
        }
    }
}
