<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon\Support;

use App\Enum\CanonicalItemKind;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\Json\Model\JsonNodeModel;

final class MastodonStatusKey
{
    private function __construct()
    {
    }

    public static function parseEntryKey(?string $guid, ?string $url): ?CanonicalKeyModel
    {
        $address = MastodonStatusAddress::pickEntryAddress($guid, $url);
        if (null === $address) {
            return null;
        }

        return new CanonicalKeyModel(
            CanonicalItemKind::Mastodon,
            MastodonStatusAddress::parseAddress($address)?->uri() ?? $address,
        );
    }

    public static function parseStatusKey(JsonNodeModel $status): ?CanonicalKeyModel
    {
        return self::parseEntryKey($status->string('uri'), $status->string('url'));
    }
}
