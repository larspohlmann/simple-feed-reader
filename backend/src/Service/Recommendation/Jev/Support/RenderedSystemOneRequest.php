<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev\Support;

use App\Service\Recommendation\Jev\Model\SystemOneRequestModel;

final class RenderedSystemOneRequest
{
    /** Pretty-printed for the human the debug view exists for: the body as sent, minus transport framing. */
    public static function of(SystemOneRequestModel $request): string
    {
        return json_encode(
            $request->payload(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
    }

    private function __construct()
    {
    }
}
