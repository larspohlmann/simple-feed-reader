<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostAddressRule;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** How one network names a post: the address every interaction with it starts from, keyed by SocialNetwork. */
#[AutoconfigureTag('app.social_post_address_rule')]
interface PostAddressRuleInterface
{
    /** The post an entry of this network's feed is; null when the entry is none. */
    public function findPost(Entry $entry): ?SocialPostAddressModel;

    /** The post an entry's discussion is, whatever feed carries the entry; null on a network whose entries are posts. */
    public function findDiscussionPost(Entry $entry): ?SocialPostAddressModel;

    public function parseReply(string $address): ?SocialPostAddressModel;

    /** $url arrives without its fragment. */
    public function parsePostUrl(string $url): ?SocialPostAddressModel;

    public function findAuthorIdentity(Entry $entry, SocialPostAddressModel $post): ?string;

    /** @throws SocialPostRefusedException */
    public function requireOwnPostId(SocialPostAddressModel $post, SocialAccount $account): string;

    /** @throws SocialPostRefusedException */
    public function requireOwnReplyId(string $url, SocialAccount $account): string;

    /**
     * Every guid a feed may store one of the account's own thread replies under: a timeline page's and an RSS item's.
     *
     * @return list<string>
     *
     * @throws SocialPostRefusedException
     */
    public function requireOwnReplyGuids(string $url, SocialAccount $account): array;
}
