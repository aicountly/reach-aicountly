<?php

namespace Tests\Unit\Support;

use App\Libraries\Support\PgBoolean;
use PHPUnit\Framework\TestCase;

final class PgBooleanTest extends TestCase
{
    /**
     * @dataProvider trueProvider
     */
    public function testIsTrue(mixed $value): void
    {
        $this->assertTrue(PgBoolean::isTrue($value));
        $this->assertFalse(PgBoolean::isFalse($value));
    }

    /**
     * @dataProvider falseProvider
     */
    public function testIsFalse(mixed $value): void
    {
        $this->assertFalse(PgBoolean::isTrue($value));
        $this->assertTrue(PgBoolean::isFalse($value));
    }

    /**
     * @return list<array{0:mixed}>
     */
    public static function trueProvider(): array
    {
        return [
            [true],
            [1],
            ['1'],
            ['t'],
            ['T'],
            ['true'],
            ['yes'],
            ['on'],
        ];
    }

    /**
     * @return list<array{0:mixed}>
     */
    public static function falseProvider(): array
    {
        return [
            [false],
            [0],
            ['0'],
            ['f'],
            ['F'],
            ['false'],
            ['no'],
            ['off'],
            [null],
            [''],
        ];
    }
}
