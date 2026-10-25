<?php

declare(strict_types=1);

namespace WSPS\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSPS\Feed\CsvParser;
use WSPS\Feed\ParseException;
use WSPS\Feed\Row;

#[CoversClass(CsvParser::class)]
final class CsvParserTest extends TestCase
{
    public function testParsesHeaderAndRows(): void
    {
        $feed = (new CsvParser(','))->parse("sku,stock,price\nA-1,5,9.90\nB-2,0,12.00\n");

        self::assertSame(['sku', 'stock', 'price'], $feed->columns);
        self::assertCount(2, $feed->rows);
        self::assertSame(['sku' => 'A-1', 'stock' => '5', 'price' => '9.90'], $feed->rows[0]->data);
        self::assertSame(2, $feed->rows[0]->number, 'The header is row 1, so the first record is row 2.');
        self::assertSame(3, $feed->rows[1]->number);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function delimiters(): iterable
    {
        yield 'semicolon' => [';', "sku;stock\nA-1;5\n"];
        yield 'tab' => ["\t", "sku\tstock\nA-1\t5\n"];
        yield 'pipe' => ['|', "sku|stock\nA-1|5\n"];
    }

    #[DataProvider('delimiters')]
    public function testSupportsDelimiters(string $delimiter, string $body): void
    {
        $feed = (new CsvParser($delimiter))->parse($body);

        self::assertSame(['sku' => 'A-1', 'stock' => '5'], $feed->rows[0]->data);
    }

    public function testCommaInSemicolonFeedIsPartOfTheValue(): void
    {
        $feed = (new CsvParser(';'))->parse("sku;price\nA-1;22,90\n");

        self::assertSame('22,90', $feed->rows[0]->data['price']);
    }

    public function testStripsUtf8ByteOrderMark(): void
    {
        $feed = (new CsvParser(','))->parse("\xEF\xBB\xBFsku,stock\nA-1,5\n");

        self::assertSame(['sku', 'stock'], $feed->columns);
        self::assertArrayHasKey('sku', $feed->rows[0]->data);
    }

    public function testHandlesWindowsLineEndings(): void
    {
        $feed = (new CsvParser(','))->parse("sku,stock\r\nA-1,5\r\nB-2,6\r\n");

        self::assertCount(2, $feed->rows);
        self::assertSame('5', $feed->rows[0]->data['stock']);
        self::assertSame('6', $feed->rows[1]->data['stock']);
    }

    public function testHandlesClassicMacLineEndings(): void
    {
        $feed = (new CsvParser(','))->parse("sku,stock\rA-1,5\rB-2,6");

        self::assertCount(2, $feed->rows);
        self::assertSame('B-2', $feed->rows[1]->data['sku']);
    }

    public function testHandlesQuotedValues(): void
    {
        $body = "sku,name,stock\n"
            . "A-1,\"Bowl, steel\",5\n"
            . "B-2,\"Toy \"\"Rope\"\"\nlarge\",3\n";

        $feed = (new CsvParser(','))->parse($body);

        self::assertSame('Bowl, steel', $feed->rows[0]->data['name']);
        self::assertSame("Toy \"Rope\"\nlarge", $feed->rows[1]->data['name']);
        self::assertSame('3', $feed->rows[1]->data['stock']);
    }

    public function testBackslashIsNotAnEscapeCharacter(): void
    {
        $feed = (new CsvParser(','))->parse("sku,name\nA-1,\"C:\\\\path\\\"\n");

        self::assertSame('C:\\\\path\\', $feed->rows[0]->data['name']);
    }

    public function testSkipsBlankLinesAndTrimsValues(): void
    {
        $feed = (new CsvParser(','))->parse("\n sku , stock \n\n A-1 , 5 \n,\n\n");

        self::assertSame(['sku', 'stock'], $feed->columns);
        self::assertCount(1, $feed->rows);
        self::assertSame(['sku' => 'A-1', 'stock' => '5'], $feed->rows[0]->data);
    }

    public function testPadsRowsWithMissingTrailingCells(): void
    {
        $feed = (new CsvParser(','))->parse("sku,stock,sale\nA-1,5\n");

        self::assertNull($feed->rows[0]->problem);
        self::assertSame(['sku' => 'A-1', 'stock' => '5', 'sale' => ''], $feed->rows[0]->data);
    }

    public function testFlagsRowsWithTooManyValues(): void
    {
        $feed = (new CsvParser(','))->parse("sku,price\nA-1,22,90\nB-2,5\n");

        self::assertSame(Row::PROBLEM_COLUMN_COUNT, $feed->rows[0]->problem);
        self::assertSame(['expected' => 2, 'actual' => 3], $feed->rows[0]->problem_context);
        self::assertSame([], $feed->rows[0]->data);
        self::assertNull($feed->rows[1]->problem, 'Other rows are still read.');
    }

    public function testHeaderOnlyGivesNoRows(): void
    {
        $feed = (new CsvParser(','))->parse("sku,stock\n");

        self::assertSame(['sku', 'stock'], $feed->columns);
        self::assertSame([], $feed->rows);
    }

    public function testNamesEmptyHeaderCellsByPosition(): void
    {
        $feed = (new CsvParser(','))->parse("sku,,stock\nA-1,x,5\n");

        self::assertSame(['sku', '#2', 'stock'], $feed->columns);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \n\r\n "];
        yield 'BOM only' => ["\xEF\xBB\xBF"];
        yield 'only delimiters' => [",,,\n,,\n"];
    }

    #[DataProvider('emptyBodies')]
    public function testRejectsEmptyFeed(string $body): void
    {
        try {
            (new CsvParser(','))->parse($body);
            self::fail('Expected ParseException.');
        } catch (ParseException $e) {
            self::assertSame(ParseException::EMPTY_FEED, $e->get_reason());
        }
    }

    public function testRejectsDuplicateColumns(): void
    {
        try {
            (new CsvParser(','))->parse("sku,stock,stock\nA-1,1,2\n");
            self::fail('Expected ParseException.');
        } catch (ParseException $e) {
            self::assertSame(ParseException::DUPLICATE_COLUMN, $e->get_reason());
            self::assertSame(['column' => 'stock'], $e->get_context());
        }
    }

    public function testRejectsMultiCharacterDelimiter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsvParser(';;');
    }
}
