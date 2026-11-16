<?php

declare(strict_types=1);

namespace App\Service\Social\Notification\Support;

/** Mastodon ids are numeric strings that grow with time: a longer one is newer, equal lengths compare by digits. */
final class MastodonIds
{
    public static function atOrBefore(string $id, string $other): bool
    {
        return self::compare($id, $other) <= 0;
    }

    /** @param non-empty-list<string> $ids */
    public static function newest(array $ids): string
    {
        return array_reduce(
            $ids,
            static fn (string $newest, string $id): string => self::compare($id, $newest) > 0 ? $id : $newest,
            $ids[0],
        );
    }

    private static function compare(string $left, string $right): int
    {
        return [\strlen($left), $left] <=> [\strlen($right), $right];
    }

    private function __construct()
    {
    }
}
