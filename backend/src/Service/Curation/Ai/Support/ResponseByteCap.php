<?php

declare(strict_types=1);

namespace App\Service\Curation\Ai\Support;

use App\Service\Curation\Ai\Exception\ProviderUnreachableException;

final class ResponseByteCap
{
    /**
     * An `on_progress` option that refuses the body on the wire as the bytes arrive, not truncated into an unparseable
     * one; the caller reports the aborted transfer as unreachable.
     *
     * @return \Closure(int): void
     */
    public static function onProgress(int $maximumBytes): \Closure
    {
        return static function (int $downloaded) use ($maximumBytes): void {
            if ($downloaded > $maximumBytes) {
                throw ProviderUnreachableException::answeredMoreThan($maximumBytes);
            }
        };
    }

    private function __construct()
    {
    }
}
