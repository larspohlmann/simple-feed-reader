<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\Entry;
use App\Enum\CanonicalItemKind;
use App\Enum\SocialNetwork;
use App\Service\Content\Comments\Support\LemmyPostAddress;
use App\Service\Foundation\CanonicalItem\CanonicalKeyRule\CanonicalKeyRuleInterface;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\CanonicalItem\Support\SavedCopyGuid;
use App\Service\Foundation\Url\Model\LemmyPostPageModel;
use App\Service\Foundation\Url\Support\LemmyPostUrl;

final readonly class LemmyCanonicalKeyRule implements CanonicalKeyRuleInterface
{
    public function findKey(Entry $entry): ?CanonicalKeyModel
    {
        $page = $this->findPage($entry);

        return null === $page ? null : new CanonicalKeyModel(
            CanonicalItemKind::Lemmy,
            sprintf('https://%s/post/%d', $page->host, $page->postId),
        );
    }

    private function findPage(Entry $entry): ?LemmyPostPageModel
    {
        if (SocialNetwork::Lemmy === $entry->getFeed()->getNetwork()) {
            return LemmyPostUrl::parse(SavedCopyGuid::stripSuffix($entry->getGuid()))
                ?? LemmyPostUrl::parse($entry->getDiscussion()->url);
        }
        $post = LemmyPostAddress::findPost($entry);

        return null === $post ? null : LemmyPostUrl::parse($post->pageUrl());
    }
}
