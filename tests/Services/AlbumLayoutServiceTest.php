<?php

declare(strict_types=1);

use App\Services\AlbumLayoutService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class AlbumLayoutServiceTest extends TestCase
{
    private Database $db;
    private AlbumLayoutService $layouts;

    protected function setUp(): void
    {
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->db->execute("INSERT INTO albums (id,title,slug,category_id) VALUES (81,'Layout','layout',1), (82,'Other','other',1)");
        $this->layouts = new AlbumLayoutService($this->db);
    }

    public function testMigrationCanUpgradeAnExistingDatabase(): void
    {
        $root = dirname(__DIR__, 2);
        $this->db->pdo()->exec('DROP TABLE album_layouts');
        $migration = (string) file_get_contents($root . '/database/migrations/migrate_1.4.32_sqlite.sql');
        $this->db->pdo()->exec($migration);
        $this->layouts->save(81, 0, $this->layout(), 'publish');
        $this->db->pdo()->exec($migration);
        self::assertSame($this->layout(), $this->layouts->state(81)['published']);
    }

    private function layout(): array
    {
        return $this->layouts->normalize([
            'blocks' => [
                ['id' => 'lead', 'type' => 'photo', 'images' => [11]],
                ['id' => 'copy', 'type' => 'text', 'text' => '<script>alert(1)</script>'],
                ['id' => 'grid', 'type' => 'grid', 'images' => [12,13], 'columns' => 2],
            ],
            'include_remaining' => true,
        ], [11,12,13]);
    }

    public function testReadingDoesNotCreateARow(): void
    {
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM album_layouts')->fetchColumn());
    }

    public function testMissingPhotosArePrunedOnReadAndSave(): void
    {
        $this->layouts->save(81, 0, $this->layout(), 'draft');
        $state = $this->layouts->state(81, [12]);
        self::assertTrue($state['photos_removed']);
        self::assertSame(['copy', 'grid'], array_column($state['draft']['blocks'], 'id'));
        self::assertSame([12], $state['draft']['blocks'][1]['images']);
        $updated = $this->layouts->normalize($this->layout(), [12]);
        self::assertSame($state['draft'], $updated);
        self::assertSame(2, $this->layouts->save(81, $state['revision'], $updated, 'draft'));
    }

    public function testCorruptPublishedLayoutFallsBackWithoutLosingDraft(): void
    {
        $layout = $this->layout();
        $this->layouts->save(81, 0, $layout, 'publish');
        foreach (['broken JSON', '{"version":1,"blocks":[{"type":"unsupported"}]}'] as $json) {
            $this->db->execute('UPDATE album_layouts SET published_json = ? WHERE album_id = ?', [$json, 81]);
            self::assertNull($this->layouts->state(81)['published']);
            self::assertSame($layout, $this->layouts->state(81)['draft']);
        }
    }

    public function testDraftIsPrivateUntilPublishedAndDefaultKeepsTheDraft(): void
    {
        $layout = $this->layout();
        self::assertSame(1, $this->layouts->save(81, 0, $layout, 'draft'));
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame($layout, $this->layouts->state(81)['draft']);
        $this->layouts->save(81, 1, $layout, 'publish');
        self::assertSame($layout, $this->layouts->state(81)['published']);
        $draft = $layout;
        $draft['blocks'][1]['text'] = 'Unpublished edit';
        $this->layouts->save(81, 2, $draft, 'draft');
        self::assertSame($layout, $this->layouts->state(81)['published']);
        $this->layouts->save(81, 3, null, 'default');
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame($draft, $this->layouts->state(81)['draft']);
    }

    public function testStaleEditorCannotOverwritePublishedWork(): void
    {
        $this->layouts->save(81, 0, $this->layout(), 'publish');
        try {
            $this->layouts->save(81, 0, null, 'default');
            self::fail('Stale revision accepted');
        } catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode());
        }
        self::assertSame($this->layout(), $this->layouts->state(81)['published']);
    }

    public function testInvalidImageAndCssAreRejected(): void
    {
        foreach ([
            ['id'=>'bad','type'=>'photo','images'=>['11']],
            ['id'=>'bad','type'=>'grid','width'=>'100%;color:red'],
            ['id'=>'bad','type'=>'grid','columns'=>0],
            ['id'=>'bad','type'=>'html','text'=>'<iframe>'],
        ] as $block) {
            try {
                $this->layouts->normalize(['blocks'=>[$block]], [11,12]);
                self::fail('Invalid layout accepted');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testDeletedPhotosAreSkippedAndNewUploadsJoinRemainingGrid(): void
    {
        $images = [['id'=>11], ['id'=>12], ['id'=>14]];
        $resolved = $this->layouts->resolve($this->layout(), $images);
        self::assertSame([12], array_column($resolved['blocks'][2]['photos'], 'id'));
        self::assertSame([14], array_column($resolved['remaining'], 'id'));
        $layout = $this->layout();
        $layout['include_remaining'] = false;
        self::assertSame([], $this->layouts->resolve($layout, $images)['remaining']);
    }

    public function testAlbumDeletionRemovesItsLayoutsOnly(): void
    {
        foreach ([81,82] as $id) {
            $this->layouts->save($id, 0, $this->layout(), 'publish');
        }
        $this->db->execute('DELETE FROM albums WHERE id=81');
        self::assertNull($this->layouts->state(81)['published']);
        self::assertSame($this->layout(), $this->layouts->state(82)['published']);
    }
}
