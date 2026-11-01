<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TextSpanModel;
use App\Service\Social\Support\BlueskyTextSpans;

/** Turns a post's links, hashtags and mentions into Bluesky rich-text facets; a handle that does not resolve stays text. */
final readonly class BlueskyFacets
{
    private const string FEATURE = 'app.bsky.richtext.facet#';

    public function __construct(private BlueskyIdentityResolver $identities)
    {
    }

    /** @return list<array{index: array{byteStart: int, byteEnd: int}, features: list<array<string, string>>}> */
    public function of(string $text): array
    {
        return [
            ...array_map(
                static fn (TextSpanModel $span): array => self::facet($span, ['uri' => $span->value], 'link'),
                BlueskyTextSpans::links($text),
            ),
            ...array_map(
                static fn (TextSpanModel $span): array => self::facet($span, ['tag' => $span->value], 'tag'),
                BlueskyTextSpans::tags($text),
            ),
            ...$this->mentionsIn($text),
        ];
    }

    /** @return list<array{index: array{byteStart: int, byteEnd: int}, features: list<array<string, string>>}> */
    private function mentionsIn(string $text): array
    {
        $facets = [];
        foreach (BlueskyTextSpans::mentions($text) as $span) {
            $did = $this->didOrNull($span->value);
            if (null !== $did) {
                $facets[] = self::facet($span, ['did' => $did], 'mention');
            }
        }

        return $facets;
    }

    private function didOrNull(string $handle): ?string
    {
        try {
            return $this->identities->didOf($handle);
        } catch (SocialAccountUnresolvableException | SocialNetworkUnreachableException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $feature
     *
     * @return array{index: array{byteStart: int, byteEnd: int}, features: list<array<string, string>>}
     */
    private static function facet(TextSpanModel $span, array $feature, string $kind): array
    {
        return [
            'index' => ['byteStart' => $span->byteStart, 'byteEnd' => $span->byteEnd],
            'features' => [['$type' => self::FEATURE . $kind, ...$feature]],
        ];
    }
}
