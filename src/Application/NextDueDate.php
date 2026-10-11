<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Calcul pur de la date butée suivante d'une tâche périodique.
 * Mois et année : le jour est conservé, ramené au dernier jour du mois d'arrivée s'il n'existe pas.
 * Calcul sur (année, mois, jour) entiers : jamais DateTime::modify('+1 month'), qui déborde sur le mois suivant.
 */
final class NextDueDate
{
    private const MAX_YEAR = 9999;

    /**
     * @param string $dueDate AAAA-MM-JJ valide
     * @param string $unit day|week|month|year
     * @throws \RangeException le résultat dépasse 9999-12-31
     * @throws \InvalidArgumentException date, intervalle ou unité invalide (bogue de l'appelant)
     */
    public static function compute(string $dueDate, int $interval, string $unit): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $dueDate, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[1] < 1) {
            throw new \InvalidArgumentException('Date invalide.');
        }
        if ($interval < 1) {
            throw new \InvalidArgumentException('Intervalle invalide.');
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        return match ($unit) {
            'day' => self::addDays($dueDate, $interval),
            'week' => self::addDays($dueDate, $interval, 7),
            'month' => self::addMonths($year, $month, $day, $interval),
            'year' => self::addMonths($year, $month, $day, $interval, 12),
            default => throw new \InvalidArgumentException('Unité invalide.'),
        };
    }

    private static function addDays(string $dueDate, int $interval, int $factor = 1): string
    {
        // 9999 ans = environ 3,65 millions de jours : au-delà, hors plage sans rien additionner.
        if ($interval > intdiv(4_000_000, $factor)) {
            throw new \RangeException('Date butée suivante hors plage.');
        }
        $date = new \DateTimeImmutable($dueDate . ' UTC');
        $next = $date->add(new \DateInterval('P' . ($interval * $factor) . 'D'));
        if ((int) $next->format('Y') > self::MAX_YEAR) {
            throw new \RangeException('Date butée suivante hors plage.');
        }

        return $next->format('Y-m-d');
    }

    private static function addMonths(int $year, int $month, int $day, int $interval, int $factor = 1): string
    {
        $total = $year * 12 + ($month - 1) + $interval * $factor; // entiers 64 bits : max 2^31 x 12 tient
        $newYear = intdiv($total, 12);
        $newMonth = $total % 12 + 1;
        if ($newYear > self::MAX_YEAR) {
            throw new \RangeException('Date butée suivante hors plage.');
        }
        $newDay = min($day, self::daysInMonth($newYear, $newMonth));

        return sprintf('%04d-%02d-%02d', $newYear, $newMonth, $newDay);
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2) {
            return ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 29 : 28;
        }

        return in_array($month, [4, 6, 9, 11], true) ? 30 : 31;
    }
}
