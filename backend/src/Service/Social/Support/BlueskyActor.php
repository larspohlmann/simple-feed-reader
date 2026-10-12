<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

/** The at-identifier syntax the AppView validates every actor against: a handle or a DID. */
final class BlueskyActor
{
    private const string HANDLE = '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/iD';
    private const string DID = '/^did:[a-z]+:[a-zA-Z0-9._:%-]*[a-zA-Z0-9._-]$/D';
    private const int MAX_LENGTH = 253;

    public static function isValid(string $actor): bool
    {
        return \strlen($actor) <= self::MAX_LENGTH
            && (1 === preg_match(self::HANDLE, $actor) || 1 === preg_match(self::DID, $actor));
    }

    private function __construct()
    {
    }
}
