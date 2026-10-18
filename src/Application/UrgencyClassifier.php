<?php

declare(strict_types=1);

namespace Actions\Application;

/**
 * Classement d'urgence d'une date butée par rapport à un jour de référence (calcul pur : ni base ni horloge).
 *
 * Colonnes : `week` (0 à 6 jours, ou date passée avec `late`), `month` (de 7 jours jusqu'au même quantième du mois
 * suivant, exclu) et `later` (au-delà). Les écarts se calculent sur des dates en UTC à minuit : aucun effet d'heure d'été.
 */
final class UrgencyClassifier
{
    public const WEEK = 'week';
    public const MONTH = 'month';
    public const LATER = 'later';
    public const COLUMNS = [self::WEEK, self::MONTH, self::LATER];

    /**
     * @param string $dueDate AAAA-MM-JJ valide
     * @param string $today AAAA-MM-JJ valide
     * @return array{column:string,late:bool}
     * @throws \InvalidArgumentException date invalide (bogue de l'appelant)
     */
    public static function classify(string $dueDate, string $today): array
    {
        $days = self::daysBetween($today, $dueDate);
        if ($days < 7) { // passé (en retard) ou 0 à 6 jours
            return ['column' => self::WEEK, 'late' => $days < 0];
        }
        // Limite exclue : même quantième du mois suivant (règle des périodicités). AAAA-MM-JJ : l'ordre des chaînes est l'ordre des dates.
        $limit = NextDueDate::compute($today, 1, 'month');

        return ['column' => $dueDate < $limit ? self::MONTH : self::LATER, 'late' => false];
    }

    private static function daysBetween(string $from, string $to): int
    {
        foreach ([$from, $to] as $date) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                throw new \InvalidArgumentException('Date invalide.');
            }
        }
        $utc = new \DateTimeZone('UTC');
        $a = new \DateTimeImmutable($from . ' 00:00:00', $utc);
        $b = new \DateTimeImmutable($to . ' 00:00:00', $utc);

        return intdiv($b->getTimestamp() - $a->getTimestamp(), 86400);
    }
}
