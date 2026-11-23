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

    public function testRenderingUsesCallerProvidedRowsInLayoutOrderWithoutDuplicates(): void
    {
        $layout = $this->layout();
        $layout['blocks'][0]['images'] = [12];
        $images = [
            ['id' => 14, 'gps_lat' => null],
            ['id' => 12, 'gps_lat' => 52.09],
            ['id' => 13, 'gps_lat' => null],
        ];
        $resolved = $this->layouts->resolve($layout, $images);
        self::assertSame([$images[1]], $resolved['blocks'][0]['photos']);
        self::assertSame([$images[2]], $resolved['blocks'][2]['photos']);
        self::assertSame([$images[0]], $resolved['remaining']);
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

    public function testSaveNormalizesTheLayoutItself(): void
    {
        // A caller that forgets normalize() must not store a layout that
        // state() would later discard (missing version) or an invalid one.
        $raw = ['blocks' => [['id' => 'copy', 'type' => 'text', 'text' => '  Hello  ']]];
        $this->layouts->save(81, 0, $raw, 'publish');
        $published = $this->layouts->state(81)['published'];
        self::assertNotNull($published);
        self::assertSame(1, $published['version']);
        self::assertSame('Hello', $published['blocks'][0]['text']);

        $this->expectException(InvalidArgumentException::class);
        $this->layouts->save(81, 1, ['blocks' => [['id' => 'x', 'type' => 'html']]], 'draft');
    }

    public function testOversizedLayoutIsRejectedBeforeReachingTheDatabase(): void
    {
        // 200 valid text blocks of escaped control characters encode to ~24 MB,
        // more than MySQL MEDIUMTEXT can hold.
        $text = 'a' . str_repeat("\x01", AlbumLayoutService::MAX_TEXT_CHARS - 2) . 'a';
        $blocks = [];
        for ($i = 0; $i < AlbumLayoutService::MAX_BLOCKS; $i++) {
            $blocks[] = ['id' => "t{$i}", 'type' => 'text', 'text' => $text];
        }
        try {
            $this->layouts->save(81, 0, ['blocks' => $blocks], 'draft');
            self::fail('Oversized layout accepted');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('too large', $error->getMessage());
        }
        self::assertSame(0, $this->layouts->state(81)['revision']);
        self::assertNull($this->layouts->state(81)['draft']);
    }

    public function testTimestampsTrackEditsAndPublishing(): void
    {
        $this->layouts->save(81, 0, $this->layout(), 'draft');
        $state = $this->layouts->state(81);
        self::assertNotNull($state['updated_at']);
        self::assertNull($state['published_at']);

        $this->layouts->save(81, 1, $this->layout(), 'publish');
        self::assertNotNull($this->layouts->state(81)['published_at']);

        $this->layouts->save(81, 2, null, 'default');
        $state = $this->layouts->state(81);
        self::assertNull($state['published_at']);
        self::assertNotNull($state['updated_at']);
    }

    public function testRemainingGridSettingsAreValidatedAndResolved(): void
    {
        $defaults = $this->layout();
        self::assertSame(3, $defaults['remaining_columns']);
        self::assertSame(8, $defaults['remaining_gap']);

        $custom = $this->layouts->normalize(['blocks' => [], 'remaining_columns' => 4, 'remaining_gap' => 24], null);
        $resolved = $this->layouts->resolve($custom, [['id' => 11]]);
        self::assertSame(4, $resolved['remaining_columns']);
        self::assertSame(24, $resolved['remaining_gap']);

        foreach ([['remaining_columns' => 0], ['remaining_columns' => 9], ['remaining_gap' => -1], ['remaining_gap' => '8']] as $bad) {
            try {
                $this->layouts->normalize(['blocks' => []] + $bad, null);
                self::fail('Invalid remaining grid accepted: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPublishingInvalidatesThePublicPageButDraftsDoNot(): void
    {
        $settings = new \App\Services\SettingsService($this->db);
        $spy = new class ($settings, $this->db) extends \App\Services\PageCacheService {
            /** @var list<string> */
            public array $calls = [];
            public function invalidateByTag(string $tag): int
            {
                $this->calls[] = 'tag:' . $tag;
                return 0;
            }
            public function invalidate(string $type): int
            {
                $this->calls[] = 'key:' . $type;
                return 0;
            }
        };
        $layouts = new AlbumLayoutService($this->db, $spy);
        $expected = ['tag:' . \App\Services\CacheTags::album(81), 'key:album_81'];

        $layouts->save(81, 0, $this->layout(), 'draft');
        self::assertSame([], $spy->calls, 'a private draft must not touch the public cache');

        $layouts->save(81, 1, $this->layout(), 'publish');
        self::assertSame($expected, $spy->calls);

        $spy->calls = [];
        $layouts->save(81, 2, null, 'default');
        self::assertSame($expected, $spy->calls);
    }

    public function testMissingTableMeansNoPublishedLayout(): void
    {
        $this->db->pdo()->exec('DROP TABLE album_layouts');
        self::assertNull($this->layouts->published(81));
    }
}
