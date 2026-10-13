<?php

namespace Tests\Unit;

use App\Enums\AccountType;
use App\Exceptions\DomainRuleException;
use App\Services\JournalEntryService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyAndAccountTypeTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            ['0', 0], ['1', 100], ['1.5', 150], ['1.05', 105], ['0.1', 10], ['1250.50', 125050],
            [12, 1200], [0.29, 29], ['', 0], [null, 0], ['9999999999999.99', 999999999999999],
        ];
    }

    #[DataProvider('amounts')]
    public function test_to_cents_is_exact(string|int|float|null $input, int $expected): void
    {
        $this->assertSame($expected, JournalEntryService::toCents($input));
    }

    public function test_to_cents_rejects_garbage(): void
    {
        $this->expectException(DomainRuleException::class);
        JournalEntryService::toCents('1.234');
    }

    public function test_normal_balance_direction(): void
    {
        $this->assertSame(70, AccountType::Asset->balance(100, 30));
        $this->assertSame(70, AccountType::Expense->balance(100, 30));
        $this->assertSame(-70, AccountType::Liability->balance(100, 30));
        $this->assertSame(70, AccountType::Revenue->balance(30, 100));
    }
}
