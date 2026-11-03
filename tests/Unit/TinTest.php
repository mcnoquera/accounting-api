<?php

namespace Tests\Unit;

use App\Support\Tin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TinTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['123456789', '123-456-789-000'],
            ['123-456-789-000', '123-456-789-000'],
            ['123 456 789 001', '123-456-789-001'],
            ['123-456-789-00002', '123-456-789-00002'],
            ['12345678', null],
            ['1234567890', null],
            ['', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Tin::normalize($input));
        $this->assertSame($expected !== null, Tin::isValid($input));
    }

    public function test_rejects_letters(): void
    {
        $this->assertFalse(Tin::isValid('123-456-789-ABC'));
    }
}
