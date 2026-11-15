<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\Model;

use App\Service\Curation\Trending\Support\ArticleSite;

/** An entry's first top-level link card, as the window reads it. */
final readonly class WindowLinkCardModel
{
    private const string ACCOUNT_HANDLE = '/@[\p{L}\p{N}_]/u';
    private const string NOT_A_LETTER_OR_DIGIT = '/[^\p{L}\p{N}]+/u';

    public function __construct(
        public string $url,
        public string $title,
        public ?string $description,
        public string $siteName,
    ) {
    }

    public function namesAStory(): bool
    {
        return !$this->isAFrontPage()
            && preg_match(self::ACCOUNT_HANDLE, $this->title) !== 1
            && !$this->isTitledWithItsSiteName();
    }

    public function site(): ?string
    {
        return ArticleSite::of($this->url);
    }

    private function isAFrontPage(): bool
    {
        $path = parse_url($this->url, PHP_URL_PATH);

        return !\is_string($path) || trim($path, '/') === '';
    }

    private function isTitledWithItsSiteName(): bool
    {
        $title = self::reduced($this->title);
        if ($title === '') {
            return false;
        }
        $site = $this->site() ?? '';
        $siteNames = [$this->siteName, $site, explode('.', $site)[0]];

        return \in_array($title, array_map(self::reduced(...), $siteNames), true);
    }

    private static function reduced(string $name): string
    {
        return mb_strtolower(preg_replace(self::NOT_A_LETTER_OR_DIGIT, '', $name) ?? '');
    }
}
