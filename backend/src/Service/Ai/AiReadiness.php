<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;

final readonly class AiReadiness
{
    /** No verifiedAt term: chooseModel() is the only writer of `model` and stamps verifiedAt in the same call. */
    public static function of(?AiProviderSettings $settings): bool
    {
        return null !== $settings && $settings->hasModel();
    }

    private function __construct()
    {
    }
}
