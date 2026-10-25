<?php

declare(strict_types=1);

namespace WSPS\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WSPS\Sync\Change;
use WSPS\Sync\SyncReport;

#[CoversClass(SyncReport::class)]
final class SyncReportTest extends TestCase
{
    private static function sample(): SyncReport
    {
        $report = new SyncReport(SyncReport::TRIGGER_CLI, true, 1_760_000_000);
        $report->add(SyncReport::STATUS_UPDATED, 'PET-01', 2, 42, 'Dog Food', [new Change('stock', 10, 4), new Change('regular_price', '20.00', '22.90')]);
        $report->add(SyncReport::STATUS_UNCHANGED, 'PET-02', 3, 43, 'Cat Food');
        $report->add(SyncReport::STATUS_NOT_FOUND, 'PET-99', 4);
        $report->add(SyncReport::STATUS_INVALID, 'PET-03', 5, 0, '', [], 'Stock cannot be negative: "-2".');
        $report->finish(1_760_000_003);

        return $report;
    }

    public function testCountsEveryRowButListsOnlyRowsWorthReading(): void
    {
        $report = self::sample();

        self::assertSame(
            ['updated' => 1, 'unchanged' => 1, 'not_found' => 1, 'invalid' => 1, 'error' => 0],
            $report->counts()
        );
        self::assertSame(4, $report->total());
        self::assertCount(3, $report->items(), 'Unchanged rows are counted, not listed.');
        self::assertSame(2, $report->change_count());
        self::assertSame('PET-99', $report->items_with_status(SyncReport::STATUS_NOT_FOUND)[0]['sku']);
    }

    public function testRoundTripsThroughArray(): void
    {
        $original = self::sample();

        $restored = SyncReport::from_array(json_decode((string) json_encode($original->to_array()), true));

        self::assertSame($original->to_array(), $restored->to_array());
        self::assertTrue($restored->is_dry_run());
        self::assertSame(SyncReport::TRIGGER_CLI, $restored->trigger());
        self::assertSame(1_760_000_003, $restored->finished_at());
        self::assertInstanceOf(Change::class, $restored->items()[0]['changes'][0]);
        self::assertSame(10, $restored->items()[0]['changes'][0]->from, 'Stock stays an integer, so it compares strictly.');
    }

    public function testTruncatesListedItemsButKeepsCounts(): void
    {
        $data = self::sample()->to_array(1);

        self::assertCount(1, $data['items']);
        self::assertSame(2, $data['items_truncated']);

        $restored = SyncReport::from_array($data);
        self::assertSame(1, $restored->count(SyncReport::STATUS_INVALID));
        self::assertSame(2, $restored->truncated_items());
    }

    public function testPreviewStorageKeepsEveryPlannedChange(): void
    {
        $report = new SyncReport(SyncReport::TRIGGER_MANUAL, true);
        for ($i = 1; $i <= 5; $i++) {
            $report->add(SyncReport::STATUS_NOT_FOUND, 'UNKNOWN-' . $i, $i);
        }
        $report->add(SyncReport::STATUS_UPDATED, 'PET-01', 6, 42, 'Dog Food', [new Change('stock', 10, 4)]);

        $data = $report->to_array(2, true);

        self::assertSame(['not_found', 'not_found', 'updated'], array_column($data['items'], 'status'));
        self::assertSame(3, $data['items_truncated']);
        self::assertSame(5, $data['counts']['not_found']);
    }

    public function testFailure(): void
    {
        $report = new SyncReport(SyncReport::TRIGGER_CRON, false);
        self::assertFalse($report->is_failed());

        $report->fail('The feed server answered with HTTP 401 Unauthorized.');

        self::assertTrue($report->is_failed());
        self::assertSame('The feed server answered with HTTP 401 Unauthorized.', SyncReport::from_array($report->to_array())->failure());
    }

    public function testCarryOverCopiesSelectedStatuses(): void
    {
        $applied = new SyncReport(SyncReport::TRIGGER_MANUAL, false);
        $applied->add(SyncReport::STATUS_UPDATED, 'PET-01', 2, 42, 'Dog Food', [new Change('stock', 10, 4)]);

        $applied->carry_over(self::sample(), [SyncReport::STATUS_UNCHANGED, SyncReport::STATUS_NOT_FOUND, SyncReport::STATUS_INVALID]);

        self::assertSame(
            ['updated' => 1, 'unchanged' => 1, 'not_found' => 1, 'invalid' => 1, 'error' => 0],
            $applied->counts()
        );
        self::assertCount(3, $applied->items());

        $applied->sort_by_row();
        self::assertSame([2, 4, 5], array_column($applied->items(), 'row'));
    }

    public function testRejectsUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SyncReport(SyncReport::TRIGGER_MANUAL, false))->add('skipped', 'PET-01', 2);
    }
}
