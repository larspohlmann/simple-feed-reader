<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostAddressRule;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Foundation\Url\Support\AbsoluteHttpUrl;
use App\Service\Social\Social\Exception\SocialPostRefusedException;
use App\Service\Social\Social\Model\SocialPostAddressModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyPostAddressRule implements PostAddressRuleInterface
{
    private const string NOT_DELETED = 'Lemmy posts are not deleted from this reader.';

    public function findPost(Entry $entry): ?SocialPostAddressModel
    {
        return null;
    }

    public function findDiscussionPost(Entry $entry): ?SocialPostAddressModel
    {
        $post = LemmyPostAddress::of($entry);

        return SocialPostAddressModel::fromNullable(SocialNetwork::Lemmy, $post?->pageUrl());
    }

    public function parseReply(string $address): ?SocialPostAddressModel
    {
        $comment = AbsoluteHttpUrl::httpsOrNull($address);

        return SocialPostAddressModel::fromNullable(SocialNetwork::Lemmy, $comment);
    }

    public function parsePostUrl(string $url): ?SocialPostAddressModel
    {
        return null;
    }

    public function findAuthorIdentity(Entry $entry, SocialPostAddressModel $post): ?string
    {
        return null;
    }

    public function requireOwnPostId(SocialPostAddressModel $post, SocialAccount $account): string
    {
        throw new SocialPostRefusedException(self::NOT_DELETED);
    }

    public function requireOwnReplyId(string $url, SocialAccount $account): string
    {
        throw new SocialPostRefusedException(self::NOT_DELETED);
    }

    public function requireOwnReplyGuids(string $url, SocialAccount $account): array
    {
        throw new SocialPostRefusedException(self::NOT_DELETED);
    }
}
