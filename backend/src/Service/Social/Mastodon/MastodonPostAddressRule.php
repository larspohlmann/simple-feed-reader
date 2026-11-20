<?php

declare(strict_types=1);

namespace App\Service\Social\Mastodon;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Mastodon\Support\MastodonAuthor;
use App\Service\Social\Mastodon\Support\MastodonStatusAddress;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\PostAddressRule\PostAddressRuleInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPostAddressRule implements PostAddressRuleInterface
{
    public function findPost(Entry $entry): ?SocialPostAddressModel
    {
        return SocialPostAddressModel::fromNullable(
            SocialNetwork::Mastodon,
            AbsoluteHttpUrl::orNull($entry->getGuid()) ?? AbsoluteHttpUrl::orNull($entry->getUrl()),
        );
    }

    public function findDiscussionPost(Entry $entry): ?SocialPostAddressModel
    {
        return null;
    }

    public function parseReply(string $address): ?SocialPostAddressModel
    {
        return SocialPostAddressModel::fromNullable(SocialNetwork::Mastodon, AbsoluteHttpUrl::orNull($address));
    }

    public function parsePostUrl(string $url): ?SocialPostAddressModel
    {
        $status = null === MastodonStatusAddress::parseAddress($url) ? null : $url;

        return SocialPostAddressModel::fromNullable(SocialNetwork::Mastodon, $status);
    }

    public function findAuthorIdentity(Entry $entry, SocialPostAddressModel $post): ?string
    {
        return MastodonAuthor::identityOfProfileUrl(
            $entry->getAuthorUrl() ?? MastodonStatusAddress::parseAddress($post->address)?->authorPrefix,
        );
    }

    public function requireOwnPostId(SocialPostAddressModel $post, SocialAccount $account): string
    {
        return self::requireOwnStatusId($post->address, $account);
    }

    public function requireOwnReplyId(string $url, SocialAccount $account): string
    {
        return self::requireOwnStatusId($url, $account);
    }

    public function requireOwnReplyGuids(string $url, SocialAccount $account): array
    {
        $statusUri = MastodonStatusAddress::parseAddress($url)?->uri()
            ?? throw new SocialPostRefusedException('A reply in the thread names no Mastodon status.');

        return [$statusUri, $url];
    }

    private static function requireOwnStatusId(string $address, SocialAccount $account): string
    {
        $status = MastodonStatusAddress::parseAddress($address);
        $ownHost = $account->getServiceHost();
        if (null === $status || null === $ownHost || 0 !== strcasecmp($status->host, $ownHost)) {
            throw new SocialPostRefusedException('The post is not on this account\'s own Mastodon server.');
        }

        return $status->id;
    }
}
