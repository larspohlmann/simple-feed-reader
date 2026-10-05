<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Scoring\Support;

/** The key a candidate's question travels under: a string, so `questions` encodes as a JSON object, never a list. */
final class QuestionId
{
    private const string PREFIX = 'entry-';

    public static function of(int $entryId): string
    {
        return self::PREFIX . $entryId;
    }

    private function __construct()
    {
    }
}
