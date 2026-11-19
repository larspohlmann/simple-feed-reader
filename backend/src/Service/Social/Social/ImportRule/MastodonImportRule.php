<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ImportRule;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Support\MastodonProfileFeed;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonImportRule implements ImportRuleInterface
{
    public function findAccountOnlyFeeds(SocialAccount $account, array $feeds): array
    {
        return [];
    }

    public function parseFollowedIdentity(string $feedUrl): ?string
    {
        return MastodonProfileFeed::parseIdentity($feedUrl);
    }

    public function isResolvedIdentity(string $identity): bool
    {
        return true;
    }

    public function fetchResolvedIdentity(string $identity): string
    {
        return $identity;
    }

    /** A Mastodon instance is the user's pick and may name any picture. */
    public function allowsFollowAvatarCache(): bool
    {
        return false;
    }
}
