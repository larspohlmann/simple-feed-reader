<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\Entry;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\CanonicalItem\Support\SavedCopyGuid;
use App\Service\Social\BlueskyPost\Support\AtPostUri;

final readonly class BlueskyCanonicalKeyRule implements CanonicalKeyRuleInterface
{
    public function findKey(Entry $entry): ?CanonicalKeyModel
    {
        if (SocialNetwork::Bluesky !== $entry->getFeed()->getNetwork()) {
            return null;
        }
        $guid = SavedCopyGuid::stripSuffix($entry->getGuid());

        return AtPostUri::matches($guid) ? new CanonicalKeyModel(CanonicalItemKind::Bluesky, $guid) : null;
    }
}
