<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\NextDueDate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextDueDateTest extends TestCase
{
    /** @return iterable<string,array{string,int,string,string}> */
    public static function cases(): iterable
    {
        yield '31 janvier + 1 mois (2026)' => ['2026-01-31', 1, 'month', '2026-02-28'];
        yield '31 janvier + 1 mois (2028 bissextile)' => ['2028-01-31', 1, 'month', '2028-02-29'];
        yield '29 février 2028 + 1 an' => ['2028-02-29', 1, 'year', '2029-02-28'];
        yield '29 février 2028 + 4 ans' => ['2028-02-29', 4, 'year', '2032-02-29'];
        yield '29 février 2028 + 100 ans (2128 bissextile)' => ['2028-02-29', 100, 'year', '2128-02-29'];
        yield '29 février 2096 + 4 ans (2100 non bissextile)' => ['2096-02-29', 4, 'year', '2100-02-28'];
        yield '31 décembre + 1 mois' => ['2026-12-31', 1, 'month', '2027-01-31'];
        yield '31 mars + 1 mois (avril a 30 jours)' => ['2026-03-31', 1, 'month', '2026-04-30'];
        yield '15 janvier + 12 mois' => ['2026-01-15', 12, 'month', '2027-01-15'];
        yield 'dérive : 28 février + 1 mois' => ['2026-02-28', 1, 'month', '2026-03-28'];
        yield '1 semaine' => ['2026-03-01', 1, 'week', '2026-03-08'];
        yield '2 semaines à cheval sur un mois' => ['2026-12-25', 2, 'week', '2027-01-08'];
        yield '10 jours' => ['2026-02-25', 10, 'day', '2026-03-07'];
        yield '1 jour 28 février bissextile' => ['2028-02-28', 1, 'day', '2028-02-29'];
        yield '9999-12-30 + 1 jour accepté' => ['9999-12-30', 1, 'day', '9999-12-31'];
        yield '9999-11-30 + 1 mois accepté' => ['9999-11-30', 1, 'month', '9999-12-30'];
        yield '9998-12-31 + 1 an accepté' => ['9998-12-31', 1, 'year', '9999-12-31'];
    }

    #[DataProvider('cases')]
    public function testComputesNextDueDate(string $date, int $interval, string $unit, string $expected): void
    {
        self::assertSame($expected, NextDueDate::compute($date, $interval, $unit));
    }

    /** @return iterable<string,array{string,int,string}> */
    public static function outOfRange(): iterable
    {
        yield '9999-12-31 + 1 jour' => ['9999-12-31', 1, 'day'];
        yield '9999-12-31 + 1 semaine' => ['9999-12-31', 1, 'week'];
        yield '9999-12-31 + 1 mois' => ['9999-12-31', 1, 'month'];
        yield '9999-12-31 + 1 an' => ['9999-12-31', 1, 'year'];
        yield '9999-06-15 + 1 an' => ['9999-06-15', 1, 'year'];
        yield 'jours immenses' => ['2026-01-01', 2147483647, 'day'];
        yield 'semaines immenses' => ['2026-01-01', 2147483647, 'week'];
        yield 'mois immenses' => ['2026-01-01', 2147483647, 'month'];
        yield 'années immenses' => ['2026-01-01', 2147483647, 'year'];
        yield 'limite jours juste hors plage' => ['0001-01-01', 3_700_000, 'day'];
    }

    #[DataProvider('outOfRange')]
    public function testOutOfRangeThrows(string $date, int $interval, string $unit): void
    {
        $this->expectException(\RangeException::class);
        NextDueDate::compute($date, $interval, $unit);
    }

    public function testInvalidInputIsACallerBug(): void
    {
        foreach ([['2026-02-30', 1, 'day'], ['2026-01-01', 0, 'day'], ['2026-01-01', 1, 'decade'], ['pas une date', 1, 'day']] as [$d, $i, $u]) {
            try {
                NextDueDate::compute($d, $i, $u);
                self::fail('InvalidArgumentException attendue');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
