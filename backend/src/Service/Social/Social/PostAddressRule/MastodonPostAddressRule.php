<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostAddressRule;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use App\Service\Social\Social\Support\MastodonAuthor;
use App\Service\Social\Social\Support\MastodonStatusAddress;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPostAddressRule implements PostAddressRuleInterface
{
    public function findPost(Entry $entry): ?SocialPostAddressModel
    {
        return self::statusAt(AbsoluteHttpUrl::orNull($entry->getGuid()) ?? AbsoluteHttpUrl::orNull($entry->getUrl()));
    }

    public function findDiscussionPost(Entry $entry): ?SocialPostAddressModel
    {
        return null;
    }

    public function parseReply(string $address): ?SocialPostAddressModel
    {
        return self::statusAt(AbsoluteHttpUrl::orNull($address));
    }

    public function parsePostUrl(string $url): ?SocialPostAddressModel
    {
        return null === MastodonStatusAddress::ofOrNull($url) ? null : self::statusAt($url);
    }

    public function findAuthorIdentity(Entry $entry, SocialPostAddressModel $post): ?string
    {
        return MastodonAuthor::identityOfProfileUrl(
            $entry->getAuthorUrl() ?? MastodonStatusAddress::ofOrNull($post->address)?->authorPrefix,
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
        $statusUri = MastodonStatusAddress::ofOrNull($url)?->uri()
            ?? throw new SocialPostRefusedException('A reply in the thread names no Mastodon status.');

        return [$statusUri, $url];
    }

    private static function statusAt(?string $statusAddress): ?SocialPostAddressModel
    {
        return null === $statusAddress ? null : new SocialPostAddressModel(SocialNetwork::Mastodon, $statusAddress);
    }

    private static function requireOwnStatusId(string $address, SocialAccount $account): string
    {
        $status = MastodonStatusAddress::ofOrNull($address);
        $ownHost = $account->getServiceHost();
        if (null === $status || null === $ownHost || 0 !== strcasecmp($status->host, $ownHost)) {
            throw new SocialPostRefusedException('The post is not on this account\'s own Mastodon server.');
        }

        return $status->id;
    }
}
