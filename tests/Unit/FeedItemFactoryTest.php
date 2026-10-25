<?php

declare(strict_types=1);

namespace WSPS\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WSPS\Feed\FeedItem;
use WSPS\Feed\FeedItemFactory;
use WSPS\Feed\FieldMapping;
use WSPS\Feed\InvalidValueException;
use WSPS\Feed\NumberParser;
use WSPS\Feed\Row;

#[CoversClass(FeedItemFactory::class)]
#[CoversClass(FieldMapping::class)]
#[CoversClass(FeedItem::class)]
final class FeedItemFactoryTest extends TestCase
{
    private static function factory(FieldMapping $mapping, string $separator = '.'): FeedItemFactory
    {
        return new FeedItemFactory($mapping, new NumberParser($separator), 2);
    }

    public function testMapsCustomColumnNames(): void
    {
        $mapping = new FieldMapping('Article', 'Qty', 'Price', 'Promo');
        $row = new Row(2, ['Article' => 'PET-01', 'Name' => 'Bowl', 'Qty' => '12', 'Price' => '9,90', 'Promo' => '7,50']);

        $item = self::factory($mapping, ',')->create($row);

        self::assertSame('PET-01', $item->sku);
        self::assertSame(2, $item->row);
        self::assertSame(12, $item->stock);
        self::assertSame('9.90', $item->regular_price);
        self::assertSame('7.50', $item->sale_price);
    }

    public function testReadsNestedJsonValuesWithDotNotation(): void
    {
        $mapping = new FieldMapping('sku', 'inventory.available', 'price.regular', 'price.sale');
        $row = new Row(1, ['sku' => 'PET-01', 'inventory' => ['available' => 4], 'price' => ['regular' => 19.9, 'sale' => null]]);

        $item = self::factory($mapping)->create($row);

        self::assertSame(4, $item->stock);
        self::assertSame('19.90', $item->regular_price);
        self::assertSame('', $item->sale_price, 'A null sale price removes the sale.');
    }

    public function testUnmappedFieldsAreLeftAlone(): void
    {
        $mapping = new FieldMapping('sku', 'stock');
        $row = new Row(2, ['sku' => 'PET-01', 'stock' => '3', 'regular_price' => '10.00', 'sale_price' => '8.00']);

        $item = self::factory($mapping)->create($row);

        self::assertSame(3, $item->stock);
        self::assertNull($item->regular_price);
        self::assertNull($item->sale_price);
    }

    public function testEmptyStockAndRegularPriceAreLeftAloneButEmptySaleRemovesTheSale(): void
    {
        $mapping = new FieldMapping('sku', 'stock', 'price', 'sale');
        $row = new Row(2, ['sku' => 'PET-01', 'stock' => ' ', 'price' => '', 'sale' => '']);

        $item = self::factory($mapping)->create($row);

        self::assertNull($item->stock);
        self::assertNull($item->regular_price);
        self::assertSame('', $item->sale_price);
    }

    public function testMissingJsonKeysAreLeftAlone(): void
    {
        $mapping = new FieldMapping('sku', 'stock', 'price', 'sale');

        $item = self::factory($mapping)->create(new Row(1, ['sku' => 'PET-01']));

        self::assertNull($item->stock);
        self::assertNull($item->regular_price);
        self::assertNull($item->sale_price, 'An absent key is not the same as an empty value.');
    }

    public function testNumericSkuIsReadAsText(): void
    {
        $item = self::factory(new FieldMapping('id'))->create(new Row(1, ['id' => 4006381333931]));

        self::assertSame('4006381333931', $item->sku);
    }

    public function testRejectsRowWithoutSku(): void
    {
        $factory = self::factory(new FieldMapping('sku', 'stock'));

        foreach ([['sku' => ' ', 'stock' => '1'], ['stock' => '1'], ['sku' => true], ['sku' => ['A']]] as $data) {
            try {
                $factory->create(new Row(2, $data));
                self::fail('Expected InvalidValueException.');
            } catch (InvalidValueException $e) {
                self::assertSame(InvalidValueException::MISSING_SKU, $e->get_reason());
            }
        }
    }

    public function testInvalidValueRejectsTheWholeRow(): void
    {
        $factory = self::factory(new FieldMapping('sku', 'stock', 'price'));

        try {
            $factory->create(new Row(5, ['sku' => 'PET-01', 'stock' => '-2', 'price' => '10.00']));
            self::fail('Expected InvalidValueException.');
        } catch (InvalidValueException $e) {
            self::assertSame(InvalidValueException::NEGATIVE, $e->get_reason());
            self::assertSame('stock', $e->get_field());
            self::assertSame('-2', $e->get_value());
        }

        self::assertSame('PET-01', $factory->sku_of(new Row(5, ['sku' => ' PET-01 '])), 'The SKU is still available to label the invalid row.');
    }

    public function testRejectsMalformedRow(): void
    {
        $row = new Row(4, [], Row::PROBLEM_COLUMN_COUNT, ['expected' => 3, 'actual' => 4]);

        try {
            self::factory(new FieldMapping('sku'))->create($row);
            self::fail('Expected InvalidValueException.');
        } catch (InvalidValueException $e) {
            self::assertSame(InvalidValueException::MALFORMED_ROW, $e->get_reason());
            self::assertSame(['problem' => 'column_count', 'expected' => 3, 'actual' => 4], $e->get_context());
        }
    }

    public function testRejectsZeroPricesUnlessAllowed(): void
    {
        $mapping = new FieldMapping('sku', 'stock', 'price', 'sale');
        $row = new Row(2, ['sku' => 'PET-01', 'stock' => '3', 'price' => '0,00', 'sale' => '']);

        try {
            self::factory($mapping, ',')->create($row);
            self::fail('Expected InvalidValueException.');
        } catch (InvalidValueException $e) {
            self::assertSame(InvalidValueException::ZERO_PRICE, $e->get_reason());
            self::assertSame('regular_price', $e->get_field());
            self::assertSame('0,00', $e->get_value());
        }

        $item = (new FeedItemFactory($mapping, new NumberParser(','), 2, true))->create($row);
        self::assertSame('0.00', $item->regular_price);
        self::assertSame(3, $item->stock, 'Stock 0 or 3 is never affected by the zero-price rule.');
    }

    public function testReportsJsonKeysThatNoItemHas(): void
    {
        $rows = [
            new Row(1, ['sku' => 'A', 'quantity' => 1, 'price' => ['regular' => 5]]),
            new Row(2, [], Row::PROBLEM_NOT_AN_OBJECT),
            new Row(3, ['sku' => 'B', 'quantity' => 2]),
        ];

        $mapping = new FieldMapping('sku', 'qty', 'price.regular', 'price.sale');

        self::assertSame(['qty'], $mapping->missing_paths($rows), 'A sale key may be absent from every item; stock and price keys may not.');
        self::assertSame([], (new FieldMapping('sku', 'quantity', 'price.regular'))->missing_paths($rows));
        self::assertSame([], $mapping->missing_paths([]), 'An empty feed has nothing to check.');
        self::assertSame(['sku', 'quantity', 'price'], FieldMapping::sample_keys($rows));
    }

    public function testReportsMappedColumnsMissingFromHeader(): void
    {
        $mapping = new FieldMapping('sku', 'qty', 'price', '');

        self::assertSame(['qty'], $mapping->missing_columns(['sku', 'stock', 'price']));
        self::assertSame([], $mapping->missing_columns(['price', 'qty', 'sku', 'name']));
    }

    public function testMappingRequiresSku(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FieldMapping('  ', 'stock');
    }

    public function testMappingTrimsSources(): void
    {
        $mapping = new FieldMapping(' sku ', ' stock ');

        self::assertSame('sku', $mapping->source(FieldMapping::SKU));
        self::assertTrue($mapping->is_mapped(FieldMapping::STOCK));
        self::assertFalse($mapping->is_mapped(FieldMapping::SALE_PRICE));
    }
}
