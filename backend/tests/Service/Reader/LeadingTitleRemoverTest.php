<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadingTitleRemover;
use PHPUnit\Framework\TestCase;

final class LeadingTitleRemoverTest extends TestCase
{
    private LeadingTitleRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new LeadingTitleRemover();
    }

    public function testRemovesFirstHeadingMatchingPageTitle(): void
    {
        $content = '<div><h2>My Article</h2><p>Body text.</p></div>';

        $result = $this->remover->remove($content, ['My Article', null]);

        self::assertStringNotContainsString('<h2>', $result);
        self::assertStringContainsString('Body text.', $result);
    }

    public function testRemovesFirstHeadingMatchingEntryTitle(): void
    {
        // The page <title> is an SEO variant; the feed entry title matches.
        $content = '<div><h2>My Article</h2><p>Body text.</p></div>';

        $result = $this->remover->remove($content, ['SEO Variant Title', 'My Article']);

        self::assertStringNotContainsString('<h2>', $result);
    }

    public function testNormalizesWhitespaceAndCase(): void
    {
        $content = "<div><h1>  my   ARTICLE\n</h1><p>Body.</p></div>";

        $result = $this->remover->remove($content, ['My Article']);

        self::assertStringNotContainsString('<h1>', $result);
    }

    public function testKeepsHeadingThatDoesNotMatch(): void
    {
        $content = '<div><h2>A Real Section</h2><p>Body.</p></div>';

        $result = $this->remover->remove($content, ['My Article']);

        self::assertStringContainsString('A Real Section', $result);
    }

    public function testOnlyTheFirstHeadingIsConsidered(): void
    {
        // A later heading that happens to equal the title is content, not a
        // duplicated headline — it stays.
        $content = '<div><h2>Intro</h2><p>Body.</p><h2>My Article</h2></div>';

        $result = $this->remover->remove($content, ['My Article']);

        self::assertStringContainsString('Intro', $result);
        self::assertStringContainsString('My Article', $result);
    }

    public function testNoCandidatesReturnsInputUnchanged(): void
    {
        $content = '<div><h2>My Article</h2></div>';

        self::assertSame($content, $this->remover->remove($content, [null, '', '  ']));
    }

    public function testContentWithoutHeadingsIsUnchanged(): void
    {
        $content = '<div><p>Only paragraphs here.</p></div>';

        self::assertSame($content, $this->remover->remove($content, ['My Article']));
    }
}
