<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Enum\CanonicalItemKind;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;

final class MastodonStatusKey
{
    private function __construct()
    {
    }

    public static function parseKey(?string $address): ?CanonicalKeyModel
    {
        return self::parseAddressKey($address) ?? self::parseAbsoluteKey($address);
    }

    public static function parseEntryKey(?string $guid, ?string $url): ?CanonicalKeyModel
    {
        $status = self::parseAddressKey($guid) ?? self::parseAddressKey($url);

        return $status ?? self::parseAbsoluteKey($guid) ?? self::parseAbsoluteKey($url);
    }

    public static function parseStatusKey(JsonNodeModel $status): ?CanonicalKeyModel
    {
        return self::parseEntryKey($status->string('uri'), $status->string('url'));
    }

    private static function parseAddressKey(?string $address): ?CanonicalKeyModel
    {
        if (null === $address) {
            return null;
        }
        $status = MastodonStatusAddress::parseAddress($address);

        return null === $status ? null : new CanonicalKeyModel(CanonicalItemKind::Mastodon, $status->uri());
    }

    private static function parseAbsoluteKey(?string $address): ?CanonicalKeyModel
    {
        $absolute = AbsoluteHttpUrl::keepIfAbsolute($address);

        return null === $absolute ? null : new CanonicalKeyModel(CanonicalItemKind::Mastodon, $absolute);
    }
}
