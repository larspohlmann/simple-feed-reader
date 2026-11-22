<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

final class LemmyPostLimits
{
    public const int MAX_CHARACTERS = 10000;
    public const int MIN_TITLE_CHARACTERS = 3;
    public const int MAX_TITLE_CHARACTERS = 200;

    private function __construct()
    {
    }
}
