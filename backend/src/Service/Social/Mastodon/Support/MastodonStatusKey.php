<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Enum\CanonicalItemKind;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

final class MastodonStatusKey
{
    private function __construct()
    {
    }

    public static function parseKey(?string $address): ?CanonicalKeyModel
    {
        return self::parseStatusKey($address) ?? self::parseAbsoluteKey($address);
    }

    public static function parseStatusKey(?string $address): ?CanonicalKeyModel
    {
        if (null === $address) {
            return null;
        }
        $status = MastodonStatusAddress::parseAddress($address);

        return null === $status ? null : new CanonicalKeyModel(CanonicalItemKind::Mastodon, $status->uri());
    }

    public static function parseAbsoluteKey(?string $address): ?CanonicalKeyModel
    {
        $absolute = AbsoluteHttpUrl::keepIfAbsolute($address);

        return null === $absolute ? null : new CanonicalKeyModel(CanonicalItemKind::Mastodon, $absolute);
    }
}
