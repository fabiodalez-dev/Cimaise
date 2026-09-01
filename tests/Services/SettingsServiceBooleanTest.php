<?php

declare(strict_types=1);

use App\Services\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsServiceBooleanTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool, bool}> */
    public static function values(): iterable
    {
        yield 'native false' => [false, true, false];
        yield 'native true' => [true, false, true];
        yield 'string false' => ['false', true, false];
        yield 'string true' => ['true', false, true];
        yield 'zero' => ['0', true, false];
        yield 'one' => ['1', false, true];
        yield 'off' => ['off', true, false];
        yield 'on' => ['on', false, true];
        yield 'invalid uses default' => ['not-a-boolean', true, true];
        yield 'null uses default' => [null, true, true];
    }

    #[DataProvider('values')]
    public function testBooleanSettingsAreParsedSemantically(mixed $value, bool $default, bool $expected): void
    {
        self::assertSame($expected, SettingsService::boolean($value, $default));
    }
}
