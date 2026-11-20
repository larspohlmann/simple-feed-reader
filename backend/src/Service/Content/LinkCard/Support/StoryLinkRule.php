<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard\Support;

use App\Service\Outbound\Fetch\Support\HostKey;

final class StoryLinkRule
{
    private const string ACCOUNT_HANDLE = '/@[\p{L}\p{N}_]/u';
    private const string NOT_A_LETTER_OR_DIGIT = '/[^\p{L}\p{N}]+/u';

    public static function namesAStory(string $url, string $title, string $siteName): bool
    {
        return !self::isAFrontPage($url)
            && preg_match(self::ACCOUNT_HANDLE, $title) !== 1
            && !self::isTitledWithItsSiteName($url, $title, $siteName);
    }

    private static function isAFrontPage(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH);

        return !\is_string($path) || trim($path, '/') === '';
    }

    private static function isTitledWithItsSiteName(string $url, string $title, string $siteName): bool
    {
        $reducedTitle = self::reduced($title);
        if ($reducedTitle === '') {
            return false;
        }
        $host = HostKey::tryForUrl($url) ?? '';
        $siteNames = [$siteName, $host, explode('.', $host)[0]];

        return \in_array($reducedTitle, array_map(self::reduced(...), $siteNames), true);
    }

    private static function reduced(string $name): string
    {
        return mb_strtolower(preg_replace(self::NOT_A_LETTER_OR_DIGIT, '', $name) ?? '');
    }

    private function __construct()
    {
    }
}
