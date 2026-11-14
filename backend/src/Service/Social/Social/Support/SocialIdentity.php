<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;

/** Whether an author a network names is one of the user's accounts; handles compare without regard to case. */
final class SocialIdentity
{
    private function __construct()
    {
    }

    public static function isAccount(?string $author, SocialAccount $account): bool
    {
        return null !== $author && mb_strtolower($author) === mb_strtolower($account->getIdentity());
    }

    /** @param list<SocialAccount> $accounts */
    public static function accountAmong(?string $author, array $accounts): ?SocialAccount
    {
        return array_find($accounts, static fn (SocialAccount $account): bool => self::isAccount($author, $account));
    }
}
