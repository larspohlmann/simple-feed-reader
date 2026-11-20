<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem\Support;

final class SavedCopyGuid
{
    public static function stripSuffix(string $guid): string
    {
        return preg_replace('/#\d+\z/', '', $guid) ?? $guid;
    }

    public static function build(string $guid, int $feedId): string
    {
        return $guid . '#' . $feedId;
    }

    private function __construct()
    {
    }
}
