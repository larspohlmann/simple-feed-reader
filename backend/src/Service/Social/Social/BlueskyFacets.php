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
        $spans = BlueskyTextSpans::mentions($text);
        $dids = $this->didsOf(array_map(static fn (TextSpanModel $span): string => $span->value, $spans));
        $facets = [];
        foreach ($spans as $span) {
            $did = $dids[$span->value] ?? null;
            if (null !== $did) {
                $facets[] = self::facet($span, ['did' => $did], 'mention');
            }
        }

        return $facets;
    }

    /**
     * Each handle is asked once; once the network is unreachable the remaining handles stay unresolved.
     *
     * @param list<string> $handles
     *
     * @return array<string, string|null>
     */
    private function didsOf(array $handles): array
    {
        $dids = [];
        try {
            foreach (array_unique($handles) as $handle) {
                $dids[$handle] = $this->didOrNull($handle);
            }
        } catch (SocialNetworkUnreachableException) {
        }

        return $dids;
    }

    /** @throws SocialNetworkUnreachableException */
    private function didOrNull(string $handle): ?string
    {
        try {
            return $this->identities->didOf($handle);
        } catch (SocialAccountUnresolvableException) {
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
