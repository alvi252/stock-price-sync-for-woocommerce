<?php

declare(strict_types=1);

namespace WSPS\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WSPS\Feed\FeedItem;
use WSPS\Feed\InvalidValueException;
use WSPS\Sync\Change;
use WSPS\Sync\DiffCalculator;
use WSPS\Sync\ProductSnapshot;
use WSPS\Sync\SyncOptions;

#[CoversClass(DiffCalculator::class)]
#[CoversClass(Change::class)]
final class DiffCalculatorTest extends TestCase
{
    private static function product(
        bool $manage_stock = true,
        ?int $stock = 10,
        string $regular = '20.00',
        string $sale = ''
    ): ProductSnapshot {
        return new ProductSnapshot(42, 'Grain-Free Dog Food 2 kg', 'PET-01', $manage_stock, $stock, $regular, $sale);
    }

    private static function diff(ProductSnapshot $product, FeedItem $item, ?SyncOptions $options = null): array
    {
        $changes = (new DiffCalculator($options ?? new SyncOptions()))->diff($product, $item);

        return array_map(static fn (Change $change): array => $change->to_array(), $changes);
    }

    public function testNoChangesWhenEverythingMatches(): void
    {
        $item = new FeedItem('PET-01', 2, 10, '20.00', '');

        self::assertSame([], self::diff(self::product(), $item));
    }

    public function testStoredPricesAreComparedAfterNormalization(): void
    {
        $item = new FeedItem('PET-01', 2, null, '18.90', '15.00');

        self::assertSame([], self::diff(self::product(true, 10, '18.9', '15'), $item));
    }

    public function testDetectsStockChange(): void
    {
        $item = new FeedItem('PET-01', 2, 4);

        self::assertSame([['field' => 'stock', 'from' => 10, 'to' => 4]], self::diff(self::product(), $item));
    }

    public function testStartsTrackingStockWhenProductDidNotTrackIt(): void
    {
        $item = new FeedItem('PET-01', 2, 0);

        self::assertSame(
            [['field' => 'stock', 'from' => null, 'to' => 0]],
            self::diff(self::product(false, null), $item)
        );
    }

    public function testDetectsPriceChanges(): void
    {
        $item = new FeedItem('PET-01', 2, null, '22.90', '19.90');

        self::assertSame(
            [
                ['field' => 'regular_price', 'from' => '20.00', 'to' => '22.90'],
                ['field' => 'sale_price', 'from' => '', 'to' => '19.90'],
            ],
            self::diff(self::product(), $item)
        );
    }

    public function testEmptySalePriceRemovesExistingSale(): void
    {
        $item = new FeedItem('PET-01', 2, null, null, '');

        self::assertSame(
            [['field' => 'sale_price', 'from' => '17.50', 'to' => '']],
            self::diff(self::product(true, 10, '20.00', '17.50'), $item)
        );
    }

    public function testFieldsNotInTheFeedAreLeftAlone(): void
    {
        $item = new FeedItem('PET-01', 2);

        self::assertSame([], self::diff(self::product(false, null, '', '5.00'), $item));
    }

    public function testRespectsWhatTheRunMayUpdate(): void
    {
        $item = new FeedItem('PET-01', 2, 3, '25.00', '');

        self::assertSame(
            [['field' => 'regular_price', 'from' => '20.00', 'to' => '25.00']],
            self::diff(self::product(), $item, new SyncOptions(false, true, 2))
        );
        self::assertSame(
            [['field' => 'stock', 'from' => 10, 'to' => 3]],
            self::diff(self::product(), $item, new SyncOptions(true, false, 2))
        );
    }

    public function testRejectsSaleAboveNewRegularPrice(): void
    {
        $item = new FeedItem('PET-01', 2, 5, '20.00', '21.00');

        try {
            self::diff(self::product(), $item);
            self::fail('Expected InvalidValueException.');
        } catch (InvalidValueException $e) {
            self::assertSame(InvalidValueException::SALE_ABOVE_REGULAR, $e->get_reason());
            self::assertSame('21.00', $e->get_value());
            self::assertSame(['regular' => '20.00'], $e->get_context());
        }
    }

    public function testSaleEqualToRegularMeansNoSale(): void
    {
        $item = new FeedItem('PET-01', 2, 4, '20.00', '20.00');

        self::assertSame(
            [
                ['field' => 'stock', 'from' => 10, 'to' => 4],
                ['field' => 'sale_price', 'from' => '15.00', 'to' => ''],
            ],
            self::diff(self::product(true, 10, '20.00', '15.00'), $item),
            'ERPs export sale = regular when there is no promotion; the stock update must still happen.'
        );
        self::assertSame([], self::diff(self::product(true, 4, '20.00', ''), $item));
    }

    public function testRejectsSaleAboveCurrentRegularPrice(): void
    {
        $item = new FeedItem('PET-01', 2, null, null, '24.00');

        $this->expectException(InvalidValueException::class);

        self::diff(self::product(true, 10, '20.00'), $item);
    }

    public function testSaleIsComparedWithTheNewRegularPrice(): void
    {
        $item = new FeedItem('PET-01', 2, null, '30.00', '25.00');

        self::assertCount(2, self::diff(self::product(true, 10, '20.00'), $item));
    }

    public function testExistingBadSaleIsIgnoredWhenTheFeedDoesNotTouchPrices(): void
    {
        $item = new FeedItem('PET-01', 2, 3);

        self::assertSame(
            [['field' => 'stock', 'from' => 10, 'to' => 3]],
            self::diff(self::product(true, 10, '20.00', '25.00'), $item)
        );
    }

    public function testNonNumericStoredPriceIsCorrected(): void
    {
        $item = new FeedItem('PET-01', 2, null, '20.00');

        self::assertSame(
            [['field' => 'regular_price', 'from' => 'call us', 'to' => '20.00']],
            self::diff(self::product(true, 10, 'call us'), $item)
        );
    }

    public function testCurrentValueUsesStoreDecimals(): void
    {
        $calculator = new DiffCalculator(new SyncOptions(true, true, 0));
        $product = self::product(false, 7, '19.6', '');

        self::assertSame('20', $calculator->current_value($product, 'regular_price'));
        self::assertSame('', $calculator->current_value($product, 'sale_price'));
        self::assertNull($calculator->current_value($product, 'stock'), 'Untracked stock has no value.');
        self::assertNull($calculator->current_value($product, 'unknown'));
    }

    public function testChangeRoundTripsThroughArray(): void
    {
        $change = new Change('stock', null, 5);

        self::assertEquals($change, Change::from_array($change->to_array()));
    }
}
