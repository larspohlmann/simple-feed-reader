<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Command\DumpEmbedFrameAllowlistCommand;
use App\Service\Reader\Media\EmbedProviders;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmbedFrameAllowlistTest extends KernelTestCase
{
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

    public function testEveryPatternIsFullyAnchored(): void
    {
        foreach ($this->providers()->framePatterns() as $pattern) {
            self::assertStringStartsWith('^', $pattern, $pattern . ' is not anchored at the start.');
            self::assertStringEndsWith('$', $pattern, $pattern . ' is not anchored at the end.');
        }
    }

    /** @return iterable<string, array{0: string}> one real source URL per embed provider */
    public static function sourceUrls(): iterable
    {
        yield 'youtube' => ['https://www.youtube.com/watch?v=M1j_uRqKMKI'];
        yield 'vimeo' => ['https://vimeo.com/1226652197/'];
        yield 'unlisted vimeo' => ['https://vimeo.com/76979871/8272103f6e'];
        yield 'soundcloud' => ['https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/2370150908'];
        yield 'brightcove' => [
            'https://players.brightcove.net/665003303001/6tKQRAx7lu_default/index.html?videoId=6403736850112',
        ];
    }

    #[DataProvider('sourceUrls')]
    public function testEveryProvidersNormalisedUrlMatchesAGeneratedPattern(string $sourceUrl): void
    {
        $providers = $this->providers();
        $target = $providers->resolve($sourceUrl);
        self::assertNotNull($target, $sourceUrl . ' did not resolve to an embed.');

        $matched = false;
        foreach ($providers->framePatterns() as $pattern) {
            if (preg_match('#' . $pattern . '#', $target->url) === 1) {
                $matched = true;
                break;
            }
        }
        self::assertTrue($matched, $target->url . ' matches no generated frame pattern.');
    }
}
