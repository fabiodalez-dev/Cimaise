<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\Admin\CommandsController;
use PHPUnit\Framework\TestCase;

/** Regression for audit finding C2: no shell, strict option keys. */
final class AdminCommandsArgvTest extends TestCase
{
    public function testTrailingNewlineKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandsController::buildArgv('php', 'bin/console', 'db:test', ["a\n" => true, '1' => '/usr/bin/id']);
    }

    public function testArrayValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandsController::buildArgv('php', 'bin/console', 'db:test', ['opt' => ['x']]);
    }

    public function testArgvKeepsShellMetacharactersInert(): void
    {
        $argv = CommandsController::buildArgv('php', 'bin/console', 'user:create', [
            'reset' => true,
            'role' => 'admin;id',
            0 => 'a@b.test',
            1 => "p\nw",
            'empty' => '',
        ]);
        self::assertSame(
            ['php', 'bin/console', 'user:create', '--reset', '--role=admin;id', '--', 'a@b.test', "p\nw"],
            $argv
        );
    }

    public function testRunCommandUsesNoShell(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/Admin/CommandsController.php');
        self::assertStringNotContainsString('exec($cmd', $src);
        self::assertStringContainsString('proc_open($argv', $src);
    }
}
