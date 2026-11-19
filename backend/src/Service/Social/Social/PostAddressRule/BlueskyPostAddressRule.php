<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostAddressRule;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\Support\AtPostUri;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyPostAddressRule implements PostAddressRuleInterface
{
    public function findPost(Entry $entry): ?SocialPostAddressModel
    {
        return $this->parseReply($entry->getGuid());
    }

    public function findDiscussionPost(Entry $entry): ?SocialPostAddressModel
    {
        return null;
    }

    public function parseReply(string $address): ?SocialPostAddressModel
    {
        $post = AtPostUri::matches($address) ? $address : null;

        return SocialPostAddressModel::fromNullable(SocialNetwork::Bluesky, $post);
    }

    public function parsePostUrl(string $url): ?SocialPostAddressModel
    {
        $post = AtPostUri::matches($url) ? $url : AtPostUri::ofWebUrl($url);

        return SocialPostAddressModel::fromNullable(SocialNetwork::Bluesky, $post);
    }

    public function findAuthorIdentity(Entry $entry, SocialPostAddressModel $post): ?string
    {
        return AtPostUri::authorityOf($post->address);
    }

    public function requireOwnPostId(SocialPostAddressModel $post, SocialAccount $account): string
    {
        return $post->address;
    }

    public function requireOwnReplyId(string $url, SocialAccount $account): string
    {
        $post = AtPostUri::ofWebUrl($url)
            ?? throw new SocialPostRefusedException('A reply in the thread names no Bluesky post.');

        return AtPostUri::withAuthority($post, $account->getIdentity());
    }

    public function requireOwnReplyGuids(string $url, SocialAccount $account): array
    {
        return [$this->requireOwnReplyId($url, $account)];
    }
}
