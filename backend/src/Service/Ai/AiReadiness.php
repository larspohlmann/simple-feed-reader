<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\AiProviderSettings;

final readonly class AiReadiness
{
    /**
     * No verifiedAt check: chooseModel() is the model's only writer and stamps
     * verifiedAt; replaceConnection() clears the model.
     */
    public static function of(?AiProviderSettings $settings): bool
    {
        return null !== $settings && $settings->hasModel();
    }

    private function __construct()
    {
    }
}
