<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PrivateFeedRule;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SearchQueryTooBroadException;
use App\Service\Social\Social\Model\PrivateFeedModel;
use App\Service\Social\Social\Model\SearchedPostModel;
use App\Service\Social\Social\Model\StoredPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Which private feeds one network has, the page each stands for, and how it runs a search; keyed by SocialNetwork. */
#[AutoconfigureTag('app.social_private_feed_rule')]
interface PrivateFeedRuleInterface
{
    public function allows(PrivateFeedModel $privateFeed): bool;

    /** The web page a private feed stands for, so its favicon and "visit site" link never see `social:`. */
    public function buildSiteUrl(SocialAccount $account, PrivateFeedModel $privateFeed): string;

    /** Whether a list the account holds is its own rather than one it saved. Only asked for a UserList. */
    public function ownsList(SocialAccount $account, PrivateFeedModel $list): bool;

    /** @return 'bluesky-api'|'mastodon-api'|'lemmy-api' */
    public function sourceFormat(): string;

    /**
     * The searches to send, one per `||` alternative where the network has no `||`.
     *
     * @return non-empty-list<string>
     *
     * @throws SearchQueryTooBroadException
     */
    public function buildSearchQueries(string $query): array;

    /** A stored search result as the reader judges it, its author named as the live search names them. */
    public function buildSearchedPost(SocialAccount $account, StoredPostModel $post): SearchedPostModel;
}
