<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\CanonicalItem\Support\SavedCopyGuid;
use App\Service\Social\Mastodon\Support\MastodonStatusKey;

final readonly class MastodonCanonicalKeyRule implements CanonicalKeyRuleInterface
{
    public function findKey(Entry $entry): ?CanonicalKeyModel
    {
        if (SocialNetwork::Mastodon !== $entry->getFeed()->getNetwork()) {
            return null;
        }
        $guid = SavedCopyGuid::stripSuffix($entry->getGuid());
        $url = $entry->getUrl();
        $status = MastodonStatusKey::parseStatusKey($guid) ?? MastodonStatusKey::parseStatusKey($url);

        return $status ?? MastodonStatusKey::parseAbsoluteKey($guid) ?? MastodonStatusKey::parseAbsoluteKey($url);
    }
}
