<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

use App\Enum\CanonicalItemKind;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use App\Service\Foundation\Url\Model\LemmyPostPageModel;
use App\Service\Foundation\Url\Support\LemmyPostUrl;

final class LemmyPostKey
{
    private function __construct()
    {
    }

    public static function fromPage(LemmyPostPageModel $page): CanonicalKeyModel
    {
        $address = sprintf('https://%s/post/%d', $page->host, $page->postId);

        return new CanonicalKeyModel(CanonicalItemKind::Lemmy, $address);
    }

    public static function parseKey(?string $url): ?CanonicalKeyModel
    {
        $page = LemmyPostUrl::parse($url);

        return null === $page ? null : self::fromPage($page);
    }

    public static function resolvePostPage(?string $address, string $homeInstance, int $postId): string
    {
        if (null !== $address && null !== LemmyPostUrl::parse($address)) {
            return $address;
        }

        return self::buildPostPage($homeInstance, $postId);
    }

    public static function buildPostPage(string $instance, int $postId): string
    {
        return rtrim($instance, '/') . '/post/' . $postId;
    }
}
