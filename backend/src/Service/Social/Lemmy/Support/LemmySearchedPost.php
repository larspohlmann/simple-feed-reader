<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy\Support;

use App\Service\Foundation\Text\Support\PlainText;
use App\Service\Social\Social\Model\SearchedPostModel;

final class LemmySearchedPost
{
    private const string PROFILE_URL = '~^https://([a-z0-9.-]+)/u/([^/?#@]+)/?$~iD';

    public static function buildSearchedPost(string $title, ?string $contentHtml, ?string $authorUrl): SearchedPostModel
    {
        return new SearchedPostModel(
            self::parseAuthorNames($authorUrl),
            [$title, PlainText::fromHtmlBlocks($contentHtml) ?? ''],
        );
    }

    /** @return list<string> */
    private static function parseAuthorNames(?string $authorUrl): array
    {
        if (null === $authorUrl || 1 !== preg_match(self::PROFILE_URL, $authorUrl, $match)) {
            return [];
        }

        return [$match[2] . '@' . mb_strtolower($match[1]), $match[2]];
    }

    private function __construct()
    {
    }
}
