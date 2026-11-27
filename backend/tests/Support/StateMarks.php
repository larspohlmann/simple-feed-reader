<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\EntryMarks;
use App\Entity\EntryState;

final class StateMarks
{
    private function __construct()
    {
    }

    public static function markFavorite(EntryState $state): EntryState
    {
        return self::replaceMarks($state, $state->marks()->withFavorite(true));
    }

    public static function clearFavorite(EntryState $state): EntryState
    {
        return self::replaceMarks($state, $state->marks()->withFavorite(false));
    }

    public static function markKept(EntryState $state): EntryState
    {
        return self::replaceMarks($state, $state->marks()->withKept(true));
    }

    public static function clearKept(EntryState $state): EntryState
    {
        return self::replaceMarks($state, $state->marks()->withKept(false));
    }

    public static function markLiked(EntryState $state, \DateTimeImmutable $likedAt): EntryState
    {
        return self::replaceMarks($state, $state->marks()->withLikedAt($likedAt));
    }

    private static function replaceMarks(EntryState $state, EntryMarks $marks): EntryState
    {
        $state->replaceMarks($marks);

        return $state;
    }
}
