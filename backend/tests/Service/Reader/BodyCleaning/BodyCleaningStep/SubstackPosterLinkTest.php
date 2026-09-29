<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\SubstackPosterLink;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use PHPUnit\Framework\TestCase;

final class SubstackPosterLinkTest extends TestCase
{
    use ParsesHtml;

    private SubstackPosterLink $rule;

    protected function setUp(): void
    {
        $this->rule = new SubstackPosterLink();
    }

    private function link(string $html): string
    {
        $document = $this->document($html);
        $this->rule->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }

    /** The poster URL carries the video id, so no re-fetch is needed. */
    public function testWrapsTheYouTubePosterInAWatchLink(): void
    {
        $out = $this->link(
            '<body><figure><img src="https://substackcdn.com/image/youtube/w_728,c_limit/_ipOL6Zq7Z8" '
            . 'alt=""></figure></body>'
        );

        self::assertStringContainsString('href="https://www.youtube-nocookie.com/embed/_ipOL6Zq7Z8"', $out);
    }

    /**
     * #627's gated placeholder inserts its own poster anchor before readability,
     * so an already-linked image belongs to that rule and must be left alone.
     */
    public function testLeavesAnImageThatIsAlreadyLinked(): void
    {
        $html = '<body><a href="https://example.test/post"><img '
            . 'src="https://substackcdn.com/image/youtube/w_728,c_limit/_ipOL6Zq7Z8" alt=""></a></body>';

        self::assertStringNotContainsString('youtube-nocookie', $this->link($html));
    }

    public function testLeavesAnOrdinarySubstackImage(): void
    {
        $html = '<body><img src="https://substackcdn.com/image/fetch/w_1456/photo.jpg" alt=""></body>';

        self::assertStringNotContainsString('youtube-nocookie', $this->link($html));
    }

    public function testLeavesAMalformedId(): void
    {
        $html = '<body><img src="https://substackcdn.com/image/youtube/w_728,c_limit/tooshort" alt=""></body>';

        self::assertStringNotContainsString('youtube-nocookie', $this->link($html));
    }
}
