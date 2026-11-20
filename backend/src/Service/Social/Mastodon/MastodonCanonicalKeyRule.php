<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\Entry;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\CanonicalItem\Support\SavedCopyGuid;
use App\Service\Social\Mastodon\Support\MastodonStatusAddress;

final readonly class MastodonCanonicalKeyRule implements CanonicalKeyRuleInterface
{
    public function findKey(Entry $entry): ?CanonicalKeyModel
    {
        if (SocialNetwork::Mastodon !== $entry->getFeed()->getNetwork()) {
            return null;
        }
        $status = MastodonStatusAddress::parseAddress(SavedCopyGuid::stripSuffix($entry->getGuid()))
            ?? MastodonStatusAddress::parseAddress((string) $entry->getUrl());

        return null === $status ? null : new CanonicalKeyModel(CanonicalItemKind::Mastodon, $status->uri());
    }
}
