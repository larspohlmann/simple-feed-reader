<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Command\DumpEmbedFrameAllowlistCommand;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\Model\EmbedKind;
use App\Service\Reader\Media\Model\EmbedShape;
use App\Tests\Service\Reader\Media\EmbedProvider\MatchesEmbedFrames;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmbedFrameAllowlistTest extends KernelTestCase
{
    use MatchesEmbedFrames;

    private function providers(): EmbedProviders
    {
        self::bootKernel();
        $providers = self::getContainer()->get(EmbedProviders::class);
        self::assertInstanceOf(EmbedProviders::class, $providers);

        return $providers;
    }

    private function projectDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir');
    }

    public function testTheCommittedClientAllowlistMatchesTheProviders(): void
    {
        $providers = $this->providers();
        $path = DumpEmbedFrameAllowlistCommand::allowlistPath($this->projectDir());

        self::assertFileExists($path);
        self::assertSame(
            $providers->allowlistJson(),
            (string) file_get_contents($path),
            'The reader client embed allow-list is stale. Run: bin/console app:embed:dump-frame-allowlist',
        );
    }

    public function testEveryCommittedEntryDeclaresAKindAndAShape(): void
    {
        self::bootKernel();
        $entries = json_decode(
            (string) file_get_contents(DumpEmbedFrameAllowlistCommand::allowlistPath($this->projectDir())),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($entries);
        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['pattern'] ?? null);
            self::assertContains($entry['kind'] ?? null, array_column(EmbedKind::cases(), 'value'));
            self::assertContains($entry['shape'] ?? null, array_column(EmbedShape::cases(), 'value'));
        }
    }

    public function testEveryPatternIsFullyAnchored(): void
    {
        foreach ($this->providers()->frames() as $frame) {
            $pattern = $frame->pattern;
            self::assertStringStartsWith('^', $pattern, $pattern . ' is not anchored at the start.');
            self::assertStringEndsWith('$', $pattern, $pattern . ' is not anchored at the end.');
        }
    }

    /** @return iterable<string, array{0: string, 1: EmbedShape}> one real source URL per embed frame */
    public static function sourceUrls(): iterable
    {
        yield 'youtube' => ['https://www.youtube.com/watch?v=M1j_uRqKMKI', EmbedShape::Landscape];
        yield 'youtube short' => ['https://www.youtube.com/shorts/GhUuOxrCato', EmbedShape::Portrait];
        yield 'vimeo' => ['https://vimeo.com/1226652197/', EmbedShape::Landscape];
        yield 'unlisted vimeo' => ['https://vimeo.com/76979871/8272103f6e', EmbedShape::Landscape];
        yield 'soundcloud' => [
            'https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908',
            EmbedShape::Landscape,
        ];
        yield 'brightcove' => [
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
            EmbedShape::Landscape,
        ];
        yield 'spotify playlist' => [
            'https://open.spotify.com/embed/playlist/27uRYdAHvcKADidfnR8BN4?utm_source=generator',
            EmbedShape::Tall,
        ];
        yield 'spotify track' => ['https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT', EmbedShape::Landscape];
        yield 'dailymotion' => ['https://www.dailymotion.com/video/x7tgad0_some-title-slug', EmbedShape::Landscape];
    }

    #[DataProvider('sourceUrls')]
    public function testEveryNormalisedUrlMatchesExactlyOneFrameOfItsShape(string $sourceUrl, EmbedShape $shape): void
    {
        $providers = $this->providers();
        $target = $providers->resolve($sourceUrl);
        self::assertNotNull($target, $sourceUrl . ' did not resolve to an embed.');

        self::assertSame($shape, self::frameMatching($providers->frames(), $target->url)->shape);
    }
}
