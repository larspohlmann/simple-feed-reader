<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\DurableMediaUrl;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaRelevance;
use App\Service\Reader\Media\MediaUrlKind;
use App\Service\Reader\Media\Provider\SoundCloudEmbedProvider;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\AttributeMediaSource;
use App\Service\Reader\Media\Source\JsonLdMediaSource;
use App\Service\Reader\Media\Source\LinkedFileMediaSource;
use App\Service\Reader\Media\Source\MetaMediaSource;
use App\Service\Reader\Media\Source\SemanticMediaSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A query-bearing (potentially signed) media url must never reach an emitted
 * candidate, whichever layer finds it — MediaUrlKind::resolve() is the one
 * place that strips it, so every file-emitting layer is proven here.
 */
final class DurableEmissionTest extends TestCase
{
    private function urlKind(): MediaUrlKind
    {
        return new MediaUrlKind(
            new DurableMediaUrl(),
            new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]),
        );
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function layerProvider(): iterable
    {
        $signedUrl = 'https://x.test/episode.mp3?Expires=1&Signature=abc';
        $pageUrl = 'https://x.test/episode-100.html';

        yield 'JsonLd' => [
            'jsonLd',
            '<html><body><script type="application/ld+json">'
                . '{"@type":"AudioObject","contentUrl":"' . $signedUrl . '"}'
                . '</script></body></html>',
            $pageUrl,
        ];

        yield 'Meta' => [
            'meta',
            '<html><head><meta property="og:audio" content="' . $signedUrl . '"></head></html>',
            $pageUrl,
        ];

        yield 'Semantic' => [
            'semantic',
            '<body><audio src="' . $signedUrl . '"></audio></body>',
            $pageUrl,
        ];

        yield 'Attribute' => [
            'attribute',
            '<body><div data-audio-src="' . $signedUrl . '"></div></body>',
            $pageUrl,
        ];

        yield 'Linked' => [
            'linked',
            '<body><a href="' . $signedUrl . '">Listen</a></body>',
            $pageUrl,
        ];
    }

    #[DataProvider('layerProvider')]
    public function testEmitsNoQueryForASignedMediaUrl(string $layer, string $html, string $pageUrl): void
    {
        $source = $this->sourceFor($layer);

        $found = $source->find($html, $pageUrl);

        self::assertNotSame([], $found);
        self::assertStringNotContainsString('?', $found[0]->url);
    }

    private function sourceFor(string $layer): MediaCandidateSourceInterface
    {
        $urlKind = $this->urlKind();
        $providers = new EmbedProviders([new YouTubeEmbedProvider(), new SoundCloudEmbedProvider()]);

        return match ($layer) {
            'jsonLd' => new JsonLdMediaSource($urlKind, $providers),
            'meta' => new MetaMediaSource($urlKind),
            'semantic' => new SemanticMediaSource($urlKind),
            'attribute' => new AttributeMediaSource($urlKind, new MediaRelevance()),
            default => new LinkedFileMediaSource($urlKind, new MediaRelevance()),
        };
    }
}
