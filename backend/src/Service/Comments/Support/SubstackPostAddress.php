<?php

declare(strict_types=1);

namespace App\Service\Comments\Support;

use App\Entity\Entry;
use App\Enum\FeedPlatform;
use App\Service\Comments\Model\SubstackPostModel;

final class SubstackPostAddress
{
    private const string POST_PATH = '#^/p/([A-Za-z0-9-]+)/?$#';

    public static function of(Entry $entry): ?SubstackPostModel
    {
        $url = $entry->getUrl();
        if (null === $url || FeedPlatform::Substack !== $entry->getFeed()->getPlatform()) {
            return null;
        }
        $host = parse_url($url, \PHP_URL_HOST);
        if ('https' !== parse_url($url, \PHP_URL_SCHEME) || !\is_string($host)) {
            return null;
        }
        if (1 !== preg_match(self::POST_PATH, (string) parse_url($url, \PHP_URL_PATH), $slug)) {
            return null;
        }

        return new SubstackPostModel('https://' . strtolower($host), $slug[1]);
    }

    private function __construct()
    {
    }
}
