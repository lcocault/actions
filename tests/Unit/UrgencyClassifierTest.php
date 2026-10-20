<?php

declare(strict_types=1);

namespace Actions\Tests\Unit;

use Actions\Application\UrgencyClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Bornes du classement d'urgence (calcul pur, date du jour fournie). */
final class UrgencyClassifierTest extends TestCase
{
    /** @return array<string,array{string,string,string,bool}> */
    public static function cases(): array
    {
        return [
            'veille : en retard' => ['2026-10-18', '2026-10-17', 'week', true],
            'très en retard' => ['2026-10-18', '2020-01-01', 'week', true],
            'jour même' => ['2026-10-18', '2026-10-18', 'week', false],
            'J+1' => ['2026-10-18', '2026-10-19', 'week', false],
            'J+6' => ['2026-10-18', '2026-10-24', 'week', false],
            'J+7 (fin de l\'heure d\'été le 25/10)' => ['2026-10-18', '2026-10-25', 'month', false],
            'J+8' => ['2026-10-18', '2026-10-26', 'month', false],
            'J+30' => ['2026-10-18', '2026-11-17', 'month', false],
            'un jour avant le mois exact' => ['2026-10-18', '2026-11-17', 'month', false],
            'un mois exact' => ['2026-10-18', '2026-11-18', 'later', false],
            'un mois et un jour' => ['2026-10-18', '2026-11-19', 'later', false],
            'très loin' => ['2026-10-18', '2031-01-01', 'later', false],
            'fin de mois : veille de la limite' => ['2027-01-31', '2027-02-27', 'month', false],
            'fin de mois : limite = 28 février' => ['2027-01-31', '2027-02-28', 'later', false],
            'année bissextile : veille de la limite' => ['2028-01-31', '2028-02-28', 'month', false],
            'année bissextile : limite = 29 février' => ['2028-01-31', '2028-02-29', 'later', false],
            '30 avril : limite = 30 mai' => ['2027-04-30', '2027-05-29', 'month', false],
            'changement d\'année : dernier jour du mois' => ['2026-12-20', '2027-01-19', 'month', false],
            'changement d\'année : limite' => ['2026-12-20', '2027-01-20', 'later', false],
            'changement d\'année : J+7' => ['2026-12-27', '2027-01-03', 'month', false],
            'passage à l\'heure d\'été : J+6' => ['2026-03-28', '2026-04-03', 'week', false],
            'passage à l\'heure d\'été : J+7' => ['2026-03-28', '2026-04-04', 'month', false],
            'passage à l\'heure d\'été : veille en retard' => ['2026-03-30', '2026-03-29', 'week', true],
        ];
    }

    #[DataProvider('cases')]
    public function testClassify(string $today, string $due, string $column, bool $late): void
    {
        self::assertSame(['column' => $column, 'late' => $late], UrgencyClassifier::classify($due, $today));
    }

    public function testLateOnlyInWeekColumn(): void
    {
        foreach (self::cases() as $name => [$today, $due, $column, $late]) {
            self::assertTrue(!$late || $column === UrgencyClassifier::WEEK, $name);
        }
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDateIsACallerBug(string $today, string $due): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UrgencyClassifier::classify($due, $today);
    }

    /** @return array<string,array{string,string}> */
    public static function invalidDates(): array
    {
        return [
            'jour invalide' => ['2026-10-18', '2026-02-30'],
            'forme invalide' => ['2026-10-18', '18/10/2026'],
            'aujourd\'hui invalide' => ['pas une date', '2026-10-18'],
        ];
    }

    /** Placement : réutilise les cas de classify pour les non terminées avec date. */
    #[DataProvider('cases')]
    public function testPlacementOfOpenTaskWithDateMatchesClassify(string $today, string $due, string $column, bool $late): void
    {
        foreach (['todo', 'in_progress'] as $status) {
            self::assertSame(['zone' => 'grid', 'column' => $column, 'late' => $late], UrgencyClassifier::placement($status, $due, $today));
        }
    }

    public function testPlacementDoneIsOutsideWithOrWithoutDate(): void
    {
        $done = ['zone' => 'done', 'column' => null, 'late' => false];
        self::assertSame($done, UrgencyClassifier::placement('done', null, '2026-10-18'));
        self::assertSame($done, UrgencyClassifier::placement('done', '2026-10-01', '2026-10-18'), 'une Terminée en retard reste hors grille');
        self::assertSame($done, UrgencyClassifier::placement('done', '2030-01-01', '2026-10-18'));
    }

    public function testPlacementOpenWithoutDateIsNoDueDate(): void
    {
        foreach (['todo', 'in_progress'] as $status) {
            self::assertSame(['zone' => 'no_due_date', 'column' => null, 'late' => false], UrgencyClassifier::placement($status, null, '2026-10-18'));
        }
    }
}
