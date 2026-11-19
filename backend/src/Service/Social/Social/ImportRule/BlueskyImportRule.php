<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ImportRule;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\BlueskyActorKey;
use App\Service\Social\Social\BlueskyIdentityResolver;
use App\Service\Social\Social\HiddenImportFollows;
use App\Service\Social\Social\Model\HiddenProfileModel;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Support\BlueskyProfileActor;
use App\Service\Social\Social\Support\BlueskyProfileFeed;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyImportRule implements ImportRuleInterface
{
    public function __construct(
        private HiddenImportFollows $hiddenFollows,
        private BlueskyIdentityResolver $identities,
    ) {
    }

    public function findAccountOnlyFeeds(SocialAccount $account, array $feeds): array
    {
        return array_map(
            static fn (HiddenProfileModel $profile): PrivateFeedModel => $profile->toAuthorFeed(),
            $this->hiddenFollows->among($feeds),
        );
    }

    public function parseFollowedIdentity(string $feedUrl): ?string
    {
        return BlueskyProfileFeed::parseIdentity($feedUrl) ?? BlueskyProfileActor::parseAuthorDid($feedUrl);
    }

    public function isResolvedIdentity(string $identity): bool
    {
        return BlueskyActorKey::isDid($identity);
    }

    public function fetchResolvedIdentity(string $identity): string
    {
        return $this->identities->didOf($identity);
    }

    public function allowsFollowAvatarCache(): bool
    {
        return true;
    }
}
