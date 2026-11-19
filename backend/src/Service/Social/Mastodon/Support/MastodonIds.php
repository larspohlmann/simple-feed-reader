<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Service\Social\Mastodon\Support\MastodonTimelinePage;

final class MastodonIds
{
    public static function atOrBefore(string $id, string $other): bool
    {
        return MastodonTimelinePage::compareIds($id, $other) <= 0;
    }

    /** @param non-empty-list<string> $ids */
    public static function newest(array $ids): string
    {
        return array_reduce(
            $ids,
            static fn (string $newest, string $id): string => MastodonTimelinePage::compareIds($id, $newest) > 0
                ? $id
                : $newest,
            $ids[0],
        );
    }

    private function __construct()
    {
    }
}
