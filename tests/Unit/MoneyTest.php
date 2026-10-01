<?php
namespace Tests\Unit;
use App\Support\Money;
use PHPUnit\Framework\TestCase;
class MoneyTest extends TestCase
{
    public function test_decimal_math_is_integer_based(): void
    {
        $this->assertSame(30, Money::minor('0.10') + Money::minor('0.20'));
        $this->assertSame('0.30', Money::decimal(30));
        $this->assertSame('-35000', Money::decimal(-3500000));
        $this->assertSame(999999999999999, Money::minor('9999999999999.99'));
    }
}
