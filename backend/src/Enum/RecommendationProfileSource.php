<?php

declare(strict_types=1);

namespace App\Enum;

enum RecommendationProfileSource: string
{
    case Own = 'own';
    case Borrowed = 'borrowed';

    public function isMissing(?string $frozenProfile): bool
    {
        return self::Borrowed === $this && null === $frozenProfile;
    }
}
