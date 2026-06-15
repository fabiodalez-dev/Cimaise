<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\Updater;

/**
 * Coverage for Updater::mergeTranslationData() — the merge that lets new
 * release translation keys reach installs whose storage/translations/*.json is
 * preserved across updates (so raw "admin.x.y" keys no longer leak into the UI).
 *
 * Invariants: missing keys are added; existing values are NEVER overwritten
 * (admin edits win); whole new groups are added; "_meta" is left untouched.
 */
final class UpdaterTranslationMergeTest extends TestCase
{
    public function testAddsMissingKeysWithoutClobberingEdits(): void
    {
        $package = [
            '_meta' => ['code' => 'it', 'version' => '2.0.0'],
            'admin' => [
                'admin.dashboard.quick_actions' => 'Azioni rapide',   // NEW
                'admin.sidebar.collections'     => 'Raccolte',        // NEW
                'admin.albums.title'            => 'Default Album',   // exists (edited locally)
            ],
        ];
        $current = [
            '_meta' => ['code' => 'it', 'version' => '1.0.0'],
            'admin' => [
                'admin.albums.title' => 'Album EDIT UTENTE',          // user edit must survive
            ],
        ];

        [$merged, $added] = Updater::mergeTranslationData($package, $current);

        $this->assertSame(2, $added, 'only the two missing keys are added');
        $this->assertSame('Azioni rapide', $merged['admin']['admin.dashboard.quick_actions']);
        $this->assertSame('Raccolte', $merged['admin']['admin.sidebar.collections']);
        // Existing (edited) value preserved, NOT overwritten by the package default.
        $this->assertSame('Album EDIT UTENTE', $merged['admin']['admin.albums.title']);
        // _meta is left as the install's own.
        $this->assertSame('1.0.0', $merged['_meta']['version']);
    }

    public function testAddsWholeNewGroup(): void
    {
        $package = [
            'admin'    => ['admin.a' => 'A'],
            'frontend' => ['front.b' => 'B', 'front.c' => 'C'],   // group absent locally
        ];
        $current = [
            'admin' => ['admin.a' => 'A'],
        ];

        [$merged, $added] = Updater::mergeTranslationData($package, $current);

        $this->assertSame(2, $added);
        $this->assertSame(['front.b' => 'B', 'front.c' => 'C'], $merged['frontend']);
    }

    public function testNoChangesWhenPackageIsSubsetOfCurrent(): void
    {
        $package = ['admin' => ['admin.a' => 'A']];
        $current = ['admin' => ['admin.a' => 'EDIT', 'admin.b' => 'local-only']];

        [$merged, $added] = Updater::mergeTranslationData($package, $current);

        $this->assertSame(0, $added);
        $this->assertSame($current, $merged, 'identical structure, no overwrites');
    }

    public function testIgnoresNonArrayGroupsAndMeta(): void
    {
        $package = [
            '_meta'  => ['version' => '9.9.9'],
            'broken' => 'not-an-array',
            'admin'  => ['admin.new' => 'N'],
        ];
        $current = ['admin' => []];

        [$merged, $added] = Updater::mergeTranslationData($package, $current);

        $this->assertSame(1, $added);
        $this->assertSame('N', $merged['admin']['admin.new']);
        $this->assertArrayNotHasKey('broken', $merged);
        $this->assertArrayNotHasKey('_meta', $merged);
    }
}
