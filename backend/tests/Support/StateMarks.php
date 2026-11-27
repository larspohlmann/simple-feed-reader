<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\EntryMarks;
use App\Entity\EntryState;

final class StateMarks
{
    private function __construct()
    {
        // Static helpers only; never instantiated.
    }

    public static function favorite(EntryState $state): EntryState
    {
        $state->replaceMarks($state->marks()->withFavorite(true));

        return $state;
    }

    public static function unfavorite(EntryState $state): EntryState
    {
        $state->replaceMarks($state->marks()->withFavorite(false));

        return $state;
    }

    public static function kept(EntryState $state): EntryState
    {
        $state->replaceMarks($state->marks()->withKept(true));

        return $state;
    }

    public static function unkept(EntryState $state): EntryState
    {
        $state->replaceMarks($state->marks()->withKept(false));

        return $state;
    }

    public static function liked(EntryState $state, \DateTimeImmutable $when): EntryState
    {
        $marks = $state->marks();
        $state->replaceMarks(new EntryMarks($marks->isFavorite, $marks->isKept, $when));

        return $state;
    }
}
