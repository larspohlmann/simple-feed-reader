<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\Entry;
use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\CanonicalItem\Support\SavedCopyGuid;
use App\Service\Social\Bluesky\Support\BlueskyPostKey;

final readonly class BlueskyCanonicalKeyRule implements CanonicalKeyRuleInterface
{
    public function findKey(Entry $entry): ?CanonicalKeyModel
    {
        if (SocialNetwork::Bluesky !== $entry->getFeed()->getNetwork()) {
            return null;
        }

        return BlueskyPostKey::parseKey(SavedCopyGuid::stripSuffix($entry->getGuid()));
    }
}
