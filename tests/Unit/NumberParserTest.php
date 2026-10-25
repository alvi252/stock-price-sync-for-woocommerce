<?php

declare(strict_types=1);

namespace WSPS\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSPS\Feed\InvalidValueException;
use WSPS\Feed\NumberParser;

#[CoversClass(NumberParser::class)]
#[CoversClass(InvalidValueException::class)]
final class NumberParserTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string, int}>
     */
    public static function validStock(): iterable
    {
        yield 'plain' => ['12', '.', 12];
        yield 'surrounding spaces' => [' 7 ', '.', 7];
        yield 'zero' => ['0', '.', 0];
        yield 'leading zeros' => ['007', '.', 7];
        yield 'plus sign' => ['+3', '.', 3];
        yield 'integer' => [5, '.', 5];
        yield 'integral float' => [5.0, '.', 5];
        yield 'zero fraction' => ['12.00', '.', 12];
        yield 'thousands, dot mode' => ['1,200', '.', 1200];
        yield 'thousands, comma mode' => ['1.200', ',', 1200];
        yield 'zero fraction, comma mode' => ['12,0', ',', 12];
        yield 'no-break space group' => ["1\u{00A0}200", ',', 1200];
        yield 'negative zero' => ['-0', '.', 0];
        yield 'maximum' => ['999999999', '.', NumberParser::MAX_STOCK];
    }

    #[DataProvider('validStock')]
    public function testParsesStock(mixed $raw, string $separator, int $expected): void
    {
        self::assertSame($expected, (new NumberParser($separator))->parse_stock($raw));
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function invalidStock(): iterable
    {
        yield 'negative text' => ['-2', '.', InvalidValueException::NEGATIVE];
        yield 'negative integer' => [-1, '.', InvalidValueException::NEGATIVE];
        yield 'negative float' => [-0.5, '.', InvalidValueException::NEGATIVE];
        yield 'fraction' => ['5.5', '.', InvalidValueException::NOT_INTEGER];
        yield 'fraction, comma mode' => ['5,5', ',', InvalidValueException::NOT_INTEGER];
        yield 'fractional float' => [2.25, '.', InvalidValueException::NOT_INTEGER];
        yield 'word' => ['ten', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'empty' => ['', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'exponent' => ['1e3', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'unit' => ['5 pcs', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'boolean' => [true, '.', InvalidValueException::NOT_A_NUMBER];
        yield 'null' => [null, '.', InvalidValueException::NOT_A_NUMBER];
        yield 'array' => [[5], '.', InvalidValueException::NOT_A_NUMBER];
        yield 'infinity' => [INF, '.', InvalidValueException::NOT_A_NUMBER];
        yield 'not a number' => [NAN, '.', InvalidValueException::NOT_A_NUMBER];
        yield 'above maximum' => ['1000000000', '.', InvalidValueException::TOO_LARGE];
        yield 'huge' => ['99999999999999999999999', '.', InvalidValueException::TOO_LARGE];
    }

    #[DataProvider('invalidStock')]
    public function testRejectsInvalidStock(mixed $raw, string $separator, string $reason): void
    {
        try {
            (new NumberParser($separator))->parse_stock($raw);
            self::fail('Expected InvalidValueException.');
        } catch (InvalidValueException $e) {
            self::assertSame($reason, $e->get_reason());
            self::assertSame('stock', $e->get_field());
        }
    }

    /**
     * @return iterable<string, array{mixed, string, int, string}>
     */
    public static function validPrices(): iterable
    {
        yield 'pads decimals' => ['18.9', '.', 2, '18.90'];
        yield 'integer text' => ['12', '.', 2, '12.00'];
        yield 'integer' => [12, '.', 2, '12.00'];
        yield 'float' => [9.99, '.', 2, '9.99'];
        yield 'zero' => ['0', '.', 2, '0.00'];
        yield 'rounds half up' => ['12.345', '.', 2, '12.35'];
        yield 'float that is binary-inexact' => [1.005, '.', 2, '1.01'];
        yield 'thousands, dot mode' => ['1,299.00', '.', 2, '1299.00'];
        yield 'space thousands, dot mode' => ['1 299.5', '.', 2, '1299.50'];
        yield 'decimal comma' => ['22,90', ',', 2, '22.90'];
        yield 'thousands, comma mode' => ['1.299,00', ',', 2, '1299.00'];
        yield 'no-break space, comma mode' => ["1\u{00A0}299,5", ',', 2, '1299.50'];
        yield 'narrow no-break space, comma mode' => ["12\u{202F}345,00", ',', 2, '12345.00'];
        yield 'zero decimals' => ['12.6', '.', 0, '13'];
        yield 'three decimals' => ['0.5', '.', 3, '0.500'];
    }

    #[DataProvider('validPrices')]
    public function testParsesPrices(mixed $raw, string $separator, int $decimals, string $expected): void
    {
        self::assertSame($expected, (new NumberParser($separator))->parse_price($raw, $decimals));
    }

    /**
     * @return iterable<string, array{mixed, string, string}>
     */
    public static function invalidPrices(): iterable
    {
        yield 'decimal comma in dot mode' => ['12,5', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'decimal dot in comma mode' => ['12.5', ',', InvalidValueException::NOT_A_NUMBER];
        yield 'two decimal points' => ['1.2.3', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'bad grouping' => ['12,34,5', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'mixed group separators' => ['1,234 567', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'currency symbol' => ['€12.00', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'placeholder' => ['N/A', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'letter O instead of zero' => ['12.9O', '.', InvalidValueException::NOT_A_NUMBER];
        yield 'negative' => ['-0.01', '.', InvalidValueException::NEGATIVE];
        yield 'negative, comma mode' => ['-5,00', ',', InvalidValueException::NEGATIVE];
    }

    #[DataProvider('invalidPrices')]
    public function testRejectsInvalidPrices(mixed $raw, string $separator, string $reason): void
    {
        try {
            (new NumberParser($separator))->parse_price($raw, 2, 'sale_price');
            self::fail('Expected InvalidValueException.');
        } catch (InvalidValueException $e) {
            self::assertSame($reason, $e->get_reason());
            self::assertSame('sale_price', $e->get_field());
            self::assertSame((string) $raw, $e->get_value());
        }
    }

    public function testRejectsUnknownDecimalSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NumberParser(';');
    }
}
