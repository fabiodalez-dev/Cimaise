<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Support\Database;
use App\Tasks\ImagesGenerateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** images:generate keeps its historical contract on the shared encoder. */
final class ImagesGenerateCommandTest extends TestCase
{
    private Database $db;
    private mixed $settingsSnapshot;

    protected function setUp(): void
    {
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        // One image without an original: generation fails fast, but the run
        // still reaches its summary, which states whether it was forced.
        $this->db->pdo()->exec("INSERT INTO categories(id,name,slug) VALUES(765440,'Fixture','fixture-generate');
            INSERT INTO albums(id,title,slug,category_id,is_published) VALUES(765440,'Fixture','fixture-generate',765440,1);
            INSERT INTO images(id,album_id,original_path,file_hash,width,height,mime) VALUES(765440,765440,'/storage/originals/absent-765440.jpg','absent',10,10,'image/jpeg')");
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        @unlink(dirname(__DIR__, 2) . '/storage/image-jobs/765440.lock');
    }

    private function generate(array $input): string
    {
        $tester = new CommandTester(new ImagesGenerateCommand($this->db));
        $tester->execute($input);
        return $tester->getDisplay();
    }

    public function testWithoutOptionsEveryVariantIsRegenerated(): void
    {
        self::assertStringContainsString('FORCE MODE', $this->generate([]));
    }

    public function testMissingOnlyFillsInAbsentVariants(): void
    {
        self::assertStringNotContainsString('FORCE MODE', $this->generate(['--missing' => true]));
    }

    public function testForceStillWinsOverMissing(): void
    {
        self::assertStringContainsString('FORCE MODE', $this->generate(['--missing' => true, '--force' => true]));
    }

    public function testDescriptionIsTheHistoricalOne(): void
    {
        self::assertStringContainsString('unless --missing', (new ImagesGenerateCommand($this->db))->getDescription());
    }
}
