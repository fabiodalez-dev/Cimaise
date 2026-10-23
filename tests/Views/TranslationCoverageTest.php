<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every key of the English packs exists in the other bundled languages, so
 * no screen of a French or Italian install falls back to the raw key.
 */
final class TranslationCoverageTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function packs(): array
    {
        $cases = [];
        foreach (['fr', 'it'] as $language) {
            $cases["{$language} frontend"] = ['en.json', "{$language}.json"];
            $cases["{$language} admin"] = ['en_admin.json', "{$language}_admin.json"];
        }
        return $cases;
    }

    /** @return array<string, true> */
    private function keys(string $file): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/storage/translations/' . $file), true, 512, JSON_THROW_ON_ERROR);
        unset($data['_meta']);
        $keys = [];
        foreach ($data as $section => $entries) {
            foreach (array_keys((array) $entries) as $key) {
                $keys[$section . ' / ' . $key] = true;
            }
        }
        return $keys;
    }

    /** @dataProvider packs */
    public function testPackCoversEveryEnglishKey(string $reference, string $pack): void
    {
        $missing = array_keys(array_diff_key($this->keys($reference), $this->keys($pack)));
        self::assertSame([], $missing, "{$pack} is missing keys present in {$reference}");
    }
}
