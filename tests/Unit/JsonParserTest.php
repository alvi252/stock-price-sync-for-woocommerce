<?php

declare(strict_types=1);

namespace WSPS\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WSPS\Feed\ArrayPath;
use WSPS\Feed\JsonParser;
use WSPS\Feed\ParseException;
use WSPS\Feed\Row;

#[CoversClass(JsonParser::class)]
#[CoversClass(ArrayPath::class)]
final class JsonParserTest extends TestCase
{
    public function testParsesListAtRoot(): void
    {
        $feed = (new JsonParser())->parse('[{"sku":"A-1","stock":5},{"sku":"B-2","stock":0}]');

        self::assertNull($feed->columns, 'JSON items may have different keys, so there is no header.');
        self::assertCount(2, $feed->rows);
        self::assertSame(['sku' => 'A-1', 'stock' => 5], $feed->rows[0]->data);
        self::assertSame(1, $feed->rows[0]->number);
        self::assertSame(2, $feed->rows[1]->number);
    }

    public function testReadsItemsAtNestedPath(): void
    {
        $body = '{"meta":{"generated":"2026-10-25"},"data":{"items":[{"sku":"A-1","price":{"regular":"9.90"}}]}}';

        $feed = (new JsonParser('data.items'))->parse($body);

        self::assertCount(1, $feed->rows);
        self::assertSame(['regular' => '9.90'], $feed->rows[0]->data['price']);
    }

    public function testStripsUtf8ByteOrderMark(): void
    {
        $feed = (new JsonParser())->parse("\xEF\xBB\xBF[{\"sku\":\"A-1\"}]");

        self::assertSame('A-1', $feed->rows[0]->data['sku']);
    }

    public function testKeepsBigIntegersAsStrings(): void
    {
        $feed = (new JsonParser())->parse('[{"sku":12345678901234567890123}]');

        self::assertSame('12345678901234567890123', $feed->rows[0]->data['sku']);
    }

    public function testFlagsItemsThatAreNotObjects(): void
    {
        $feed = (new JsonParser())->parse('[{"sku":"A-1"},"B-2",[1,2],{}]');

        self::assertNull($feed->rows[0]->problem);
        self::assertSame(Row::PROBLEM_NOT_AN_OBJECT, $feed->rows[1]->problem);
        self::assertSame(Row::PROBLEM_NOT_AN_OBJECT, $feed->rows[2]->problem);
        self::assertNull($feed->rows[3]->problem, 'An empty object is an object; it fails later for its missing SKU.');
    }

    public function testEmptyListGivesNoRows(): void
    {
        self::assertSame([], (new JsonParser('items'))->parse('{"items":[]}')->rows);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function brokenFeeds(): iterable
    {
        yield 'empty body' => ['', '', ParseException::EMPTY_FEED];
        yield 'syntax error' => ['[{"sku":"A-1",}]', '', ParseException::INVALID_JSON];
        yield 'object without path' => ['{"items":[]}', '', ParseException::NOT_A_LIST];
        yield 'scalar' => ['"hello"', '', ParseException::NOT_A_LIST];
        yield 'path missing' => ['{"data":{"products":[]}}', 'data.items', ParseException::ITEMS_PATH_NOT_FOUND];
        yield 'path through scalar' => ['{"data":"x"}', 'data.items', ParseException::ITEMS_PATH_NOT_FOUND];
        yield 'path on list root' => ['[1,2]', 'items', ParseException::ITEMS_PATH_NOT_FOUND];
        yield 'path to object' => ['{"items":{"sku":"A-1"}}', 'items', ParseException::NOT_A_LIST];
    }

    #[DataProvider('brokenFeeds')]
    public function testRejectsBrokenFeeds(string $body, string $path, string $reason): void
    {
        try {
            (new JsonParser($path))->parse($body);
            self::fail('Expected ParseException.');
        } catch (ParseException $e) {
            self::assertSame($reason, $e->get_reason());
        }
    }

    public function testArrayPathPrefersExactKeyOverDotNotation(): void
    {
        $data = ['price.eur' => '1', 'price' => ['eur' => '2']];

        self::assertSame('1', ArrayPath::get($data, 'price.eur', $found));
        self::assertTrue($found);
    }

    public function testArrayPathDistinguishesNullFromMissing(): void
    {
        $data = ['price' => ['sale' => null]];

        self::assertNull(ArrayPath::get($data, 'price.sale', $found));
        self::assertTrue($found);

        self::assertNull(ArrayPath::get($data, 'price.regular', $found));
        self::assertFalse($found);
    }
}
