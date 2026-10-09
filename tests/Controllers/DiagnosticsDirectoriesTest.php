<?php

declare(strict_types=1);

use App\Controllers\Admin\DiagnosticsController;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;

/**
 * The diagnostics page must probe the project's own storage and media
 * directories. Regression: the paths were built with dirname(__DIR__, 2) from
 * app/Controllers/Admin, which resolves to app/, so every healthy install
 * reported storage, originals, tmp and public/media as "Missing".
 */
final class DiagnosticsDirectoriesTest extends TestCase
{
    public function testDirectoryChecksPointAtTheProjectRoot(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $dbFile = sys_get_temp_dir() . '/cimaise_diag_test_' . uniqid('', true) . '.sqlite';
        $db = new Database(null, null, $dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);
        $controller = new DiagnosticsController($db, new Twig(new ArrayLoader([])));

        $run = new ReflectionMethod($controller, 'runDiagnostics');
        $results = $run->invoke($controller);
        @unlink($dbFile);

        $root = dirname(__DIR__, 2);
        $found = 0;
        foreach ($results as $check) {
            $name = (string) ($check['name'] ?? '');
            if (!str_starts_with($name, 'Directory: ')) {
                continue;
            }
            $found++;
            $relative = substr($name, strlen('Directory: '));
            self::assertSame($root . '/' . $relative, $check['details']['Path'] ?? null, $name);
            self::assertDirectoryExists($root . '/' . $relative);
            self::assertNotSame('error', $check['status'] ?? null, $name . ' reported missing on a healthy checkout');
        }
        self::assertSame(4, $found);
    }
}
