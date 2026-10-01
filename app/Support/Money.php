<?php
namespace App\Support;
use Illuminate\Validation\ValidationException;

/** Exact integer arithmetic, including SQLite development. Public amounts are decimal strings. */
final class Money
{
    public static function minor(mixed $value, string $field = 'amount', bool $signed = false): int
    {
        $value = (string) $value;
        $pattern = $signed ? '/^-?\d{1,13}(?:\.\d{1,2})?$/' : '/^\d{1,13}(?:\.\d{1,2})?$/';
        if (! preg_match($pattern, $value)) throw ValidationException::withMessages([$field => 'Enter a valid amount with at most two decimal places.']);
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        return ((int) $whole * 100 + (int) str_pad($fraction, 2, '0')) * ($negative ? -1 : 1);
    }
    public static function decimal(int|string|null $value): string
    {
        $value = (int) $value; $negative = $value < 0; $value = abs($value); $fraction = $value % 100;
        return ($negative ? '-' : '').intdiv($value, 100).($fraction ? '.'.str_pad((string) $fraction, 2, '0', STR_PAD_LEFT) : '');
    }
}
