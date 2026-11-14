<?php

declare(strict_types=1);

namespace App\Service\Reader\Media;

/**
 * Orders media candidates by the tokens their URL shares with the page slug. It reorders and never drops, so a
 * publisher whose filenames do not echo the slug still gets its media; dropping is DurableMediaUrl's job.
 */
final readonly class MediaRelevance
{
    /** Below this length a token is noise ("de", "der", "the"). */
    private const int MIN_TOKEN_LENGTH = 4;

    /**
     * @param list<string> $urls
     *
     * @return list<string>
     */
    public function rank(array $urls, string $pageUrl): array
    {
        $slugTokens = $this->tokens($this->path($pageUrl));
        if ($slugTokens === []) {
            return $urls;
        }

        $ordered = $urls;
        usort(
            $ordered,
            fn (string $left, string $right): int
                => $this->score($right, $slugTokens) <=> $this->score($left, $slugTokens),
        );

        return $ordered;
    }

    /** @param list<string> $slugTokens */
    private function score(string $url, array $slugTokens): int
    {
        $urlTokens = $this->tokens($this->path($url));

        return \count(array_intersect($slugTokens, $urlTokens));
    }

    private function path(string $url): string
    {
        $path = parse_url($url, \PHP_URL_PATH);

        return \is_string($path) ? $path : '';
    }

    /** @return list<string> */
    private function tokens(string $path): array
    {
        $words = preg_split('#[^a-z0-9]+#i', strtolower($path)) ?: [];
        $keep = array_filter(
            $words,
            static fn (string $word): bool => \strlen($word) >= self::MIN_TOKEN_LENGTH && !ctype_digit($word),
        );

        return array_values(array_unique($keep));
    }
}
