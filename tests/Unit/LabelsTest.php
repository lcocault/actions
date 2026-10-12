<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Portal\Labels;
use PHPUnit\Framework\TestCase;

final class LabelsTest extends TestCase
{
    public function testStatuses(): void
    {
        self::assertSame('À faire', Labels::status('todo'));
        self::assertSame('En cours', Labels::status('in_progress'));
        self::assertSame('Terminée', Labels::status('done'));
    }

    public function testRecurrence(): void
    {
        self::assertSame('', Labels::recurrence(null));
        self::assertSame('Tous les 2 jours', Labels::recurrence(['interval' => 2, 'unit' => 'day']));
        self::assertSame('Tous les 3 semaines', Labels::recurrence(['interval' => 3, 'unit' => 'week']));
        self::assertSame('Tous les 2 mois', Labels::recurrence(['interval' => 2, 'unit' => 'month']));
        self::assertSame('Tous les 5 ans', Labels::recurrence(['interval' => 5, 'unit' => 'year']));
        self::assertSame('Chaque jour', Labels::recurrence(['interval' => 1, 'unit' => 'day']));
        self::assertSame('Chaque semaine', Labels::recurrence(['interval' => 1, 'unit' => 'week']));
        self::assertSame('Chaque mois', Labels::recurrence(['interval' => 1, 'unit' => 'month']));
        self::assertSame('Chaque an', Labels::recurrence(['interval' => 1, 'unit' => 'year']));
    }

    public function testDate(): void
    {
        self::assertSame('28/02/2026', Labels::date('2026-02-28'));
        self::assertSame('', Labels::date(null));
    }

    public function testDateTimeUsesParisTimeInWinterAndSummer(): void
    {
        self::assertSame('15/01/2026 13:30', Labels::dateTime('2026-01-15T12:30:00Z'));
        self::assertSame('15/07/2026 14:30', Labels::dateTime('2026-07-15T12:30:00Z'));
        self::assertSame('01/01/2027 00:30', Labels::dateTime('2026-12-31T23:30:00Z'));
        self::assertSame('', Labels::dateTime(null));
    }

    public function testDuration(): void
    {
        self::assertSame('90 minutes', Labels::duration(90));
        self::assertSame('1 minute', Labels::duration(1));
        self::assertSame('', Labels::duration(null));
    }
}
