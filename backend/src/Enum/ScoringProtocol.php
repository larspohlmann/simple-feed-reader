<?php

declare(strict_types=1);

namespace App\Enum;

/** How a scoring model is asked; a connection and a run store it, and the value keys its protocol's implementation. */
enum ScoringProtocol: string
{
    case SystemOne = 'system_one';

    public function family(): ScoringFamily
    {
        return match ($this) {
            self::SystemOne => ScoringFamily::Decision,
        };
    }
}
