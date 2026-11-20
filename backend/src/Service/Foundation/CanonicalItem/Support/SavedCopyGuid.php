<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem\Support;

final class SavedCopyGuid
{
    public static function stripSuffix(string $guid): string
    {
        return preg_replace('/#\d+\z/', '', $guid) ?? $guid;
    }

    private function __construct()
    {
    }
}
