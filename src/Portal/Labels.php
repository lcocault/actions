<?php

declare(strict_types=1);

namespace Actions\Portal;

/** Libellés et formats d'affichage (présentation uniquement; les valeurs stockées ne changent jamais). */
final class Labels
{
    private const STATUS = ['todo' => 'À faire', 'in_progress' => 'En cours', 'done' => 'Terminée'];
    /** Singulier, pluriel (périodicité affichée « Chaque jour » / « Tous les 2 jours »). */
    private const UNIT = [
        'day' => ['jour', 'jours'],
        'week' => ['semaine', 'semaines'],
        'month' => ['mois', 'mois'],
        'year' => ['an', 'ans'],
    ];
    /** Libellés du formulaire de saisie (« Répéter tous les [N] [unité] »). */
    private const UNIT_FORM = ['day' => 'jour(s)', 'week' => 'semaine(s)', 'month' => 'mois', 'year' => 'année(s)'];

    public static function status(string $code): string
    {
        return self::STATUS[$code] ?? $code;
    }

    /** Libellé du bouton d'une transition (présentation; les transitions permises viennent de TaskStatus). */
    public static function transition(string $from, string $to): string
    {
        return match ([$from, $to]) {
            ['todo', 'in_progress'] => 'Démarrer',
            ['in_progress', 'done'] => 'Terminer',
            ['in_progress', 'todo'] => 'Remettre à faire',
            ['done', 'todo'] => 'Rouvrir',
            default => self::status($to),
        };
    }

    private const URGENCY = ['week' => 'Moins d\'une semaine', 'month' => 'Moins d\'un mois', 'later' => 'Au-delà'];

    private const URGENCY_LOWER = ['week' => 'moins d\'une semaine', 'month' => 'moins d\'un mois', 'later' => 'au-delà'];

    /** Colonne de la matrice (code de UrgencyClassifier) -> en-tête. */
    public static function urgency(string $column): string
    {
        return self::URGENCY[$column] ?? $column;
    }

    /** @return array<string,string> code => libellé (liste du filtre d'échéance) */
    public static function urgencies(): array
    {
        return self::URGENCY;
    }

    /** Tournure en minuscules pour un nom accessible : « moins d'une semaine ». */
    public static function urgencyLower(string $column): string
    {
        return self::URGENCY_LOWER[$column] ?? $column;
    }

    /** « action » ou « actions » (0 et 1 au singulier) : suite du nombre dans le nom accessible des liens de la matrice. */
    public static function actionWord(int $count): string
    {
        return $count > 1 ? 'actions' : 'action';
    }

    /** @return array<string,string> code => libellé */
    public static function statuses(): array
    {
        return self::STATUS;
    }

    /** @return array<string,string> code => libellé de saisie */
    public static function formUnits(): array
    {
        return self::UNIT_FORM;
    }

    /** @param array{interval:int,unit:string}|null $recurrence */
    public static function recurrence(?array $recurrence): string
    {
        if ($recurrence === null) {
            return '';
        }
        [$one, $many] = self::UNIT[$recurrence['unit']] ?? [$recurrence['unit'], $recurrence['unit']];

        return $recurrence['interval'] === 1
            ? 'Chaque ' . $one
            : 'Tous les ' . $recurrence['interval'] . ' ' . $many;
    }

    /** `AAAA-MM-JJ` -> `JJ/MM/AAAA`, par découpage de chaîne (aucun fuseau). */
    public static function date(?string $iso): string
    {
        if ($iso === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) !== 1) {
            return $iso ?? '';
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    /** Horodatage ISO 8601 UTC -> `JJ/MM/AAAA HH:MM` en Europe/Paris. */
    public static function dateTime(?string $iso): string
    {
        if ($iso === null) {
            return '';
        }
        try {
            $utc = new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return $iso;
        }

        return $utc->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y H:i');
    }

    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
    }
}
