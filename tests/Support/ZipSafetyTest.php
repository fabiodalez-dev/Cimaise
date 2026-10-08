<?php

declare(strict_types=1);

use App\Support\ZipSafety;
use PHPUnit\Framework\TestCase;

/**
 * The zip-slip guard must reject every entry shape that lets
 * ZipArchive::extractTo() write outside the extraction directory, while
 * accepting ordinary nested plugin layouts.
 */
final class ZipSafetyTest extends TestCase
{
    /** @var string[] */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
    }

    public function testPlainNestedArchiveIsSafe(): void
    {
        $zip = $this->openZip(static function (ZipArchive $z): void {
            $z->addFromString('my-plugin/plugin.json', '{"name":"x"}');
            $z->addFromString('my-plugin/plugin.php', '<?php');
            $z->addFromString('my-plugin/assets/..hidden.css', 'a{}'); // dots in a name are fine
            $z->addEmptyDir('my-plugin/templates');
        });

        self::assertFalse(ZipSafety::hasUnsafeEntries($zip));
        $zip->close();
    }

    public function testTraversalEntryIsRejected(): void
    {
        $zip = $this->openZip(static function (ZipArchive $z): void {
            $z->addFromString('ok.txt', 'x');
            $z->addFromString('sub/../../escape.php', '<?php');
        });

        self::assertTrue(ZipSafety::hasUnsafeEntries($zip));
        $zip->close();
    }

    public function testAbsolutePathEntryIsRejected(): void
    {
        $zip = $this->openZip(static function (ZipArchive $z): void {
            $z->addFromString('/etc/cron.d/evil', 'x');
        });

        self::assertTrue(ZipSafety::hasUnsafeEntries($zip));
        $zip->close();
    }

    public function testSymlinkEntryIsRejected(): void
    {
        $zip = $this->openZip(static function (ZipArchive $z): void {
            $z->addFromString('link', '/etc');
            // S_IFLNK (0xA000) | 0777 in the high 16 bits of the Unix attributes.
            $z->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, (0xA000 | 0777) << 16);
            $z->addFromString('link/passwd', 'root::0:0');
        });

        self::assertTrue(ZipSafety::hasUnsafeEntries($zip));
        $zip->close();
    }

    public function testDarwinMadeSymlinkEntryIsRejected(): void
    {
        $zip = $this->openZip(static function (ZipArchive $z): void {
            $z->addFromString('link', '/etc');
            // "Made by" host 19 (OS X/Darwin) carries the same Unix mode bits.
            $z->setExternalAttributesName('link', 19, (0xA000 | 0777) << 16);
            $z->addFromString('link/passwd', 'root::0:0');
        });

        self::assertTrue(ZipSafety::hasUnsafeEntries($zip));
        $zip->close();
    }

    public function testRegularFileWithDarwinOpsysIsSafe(): void
    {
        $zip = $this->openZip(static function (ZipArchive $z): void {
            $z->addFromString('plugin.json', '{}');
            $z->setExternalAttributesName('plugin.json', 19, (0x8000 | 0644) << 16);
        });

        self::assertFalse(ZipSafety::hasUnsafeEntries($zip));
        $zip->close();
    }

    /**
     * @dataProvider entryNames
     */
    public function testEntryNameClassification(string $name, bool $safe): void
    {
        self::assertSame($safe, ZipSafety::isSafeEntryName($name), $name);
    }

    /** @return array<string, array{string, bool}> */
    public static function entryNames(): array
    {
        return [
            'nested file' => ['a/b/c.php', true],
            'dotfile' => ['a/.htaccess', true],
            'double dot inside name' => ['a/foo..bar', true],
            'leading traversal' => ['../x', false],
            'trailing traversal' => ['a/..', false],
            'middle traversal' => ['a/../b', false],
            'backslash traversal' => ['a\\..\\b', false],
            'unix absolute' => ['/x', false],
            'windows drive' => ['C:\\x', false],
            'nul byte' => ["a\0b", false],
        ];
    }

    private function openZip(callable $build): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'zipsafety_') . '.zip';
        $this->tmpFiles[] = $path;

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $build($zip);
        $zip->close();

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));

        return $zip;
    }
}
