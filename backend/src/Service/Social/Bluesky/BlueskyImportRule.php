<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\BlueskyIdentityResolver;
use App\Service\Social\Bluesky\HiddenImportFollows;
use App\Service\Social\Bluesky\Model\HiddenProfileModel;
use App\Service\Social\Bluesky\Support\BlueskyProfileActor;
use App\Service\Social\BlueskyPost\Support\BlueskyActorKey;
use App\Service\Social\Social\ImportRule\ImportRuleInterface;
use App\Service\Social\Social\Model\PrivateFeedModel;
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
        return BlueskyProfileActor::parseFollowedIdentity($feedUrl);
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
