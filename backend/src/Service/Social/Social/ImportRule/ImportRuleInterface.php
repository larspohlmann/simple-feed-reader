<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ImportRule;

use App\Service\Social\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SocialImportFeedModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** How one network's follows come in on an import, keyed by SocialNetwork. */
#[AutoconfigureTag('app.social_import_rule')]
interface ImportRuleInterface
{
    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return array<string, PrivateFeedModel> by the chosen identity: the follows that come in through the account
     */
    public function findAccountOnlyFeeds(array $feeds): array;

    /** the identity a subscribed feed follows on this network; null for any other feed */
    public function parseFollowedIdentity(string $feedUrl): ?string;

    public function isResolvedIdentity(string $identity): bool;

    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function fetchResolvedIdentity(string $identity): string;

    public function allowsFollowAvatarCache(): bool;
}
