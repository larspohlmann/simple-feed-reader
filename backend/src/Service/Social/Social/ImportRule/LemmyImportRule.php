<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ImportRule;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Support\LemmyProfileFeed;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyImportRule implements ImportRuleInterface
{
    public function findAccountOnlyFeeds(SocialAccount $account, array $feeds): array
    {
        return [];
    }

    public function parseFollowedIdentity(string $feedUrl): ?string
    {
        return LemmyProfileFeed::parseIdentity($feedUrl);
    }

    public function isResolvedIdentity(string $identity): bool
    {
        return true;
    }

    public function fetchResolvedIdentity(string $identity): string
    {
        return $identity;
    }

    public function allowsFollowAvatarCache(): bool
    {
        return false;
    }
}
