<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;
use App\Entity\SharedLink;
use App\Enum\FeedLanguage;
use App\Enum\LanguageSource;
use PHPUnit\Framework\TestCase;

final class SharedLinkTest extends TestCase
{
    public function testRefreshKeepsTheUrl(): void
    {
        $link = self::link(null);

        $link->refresh(new LinkCardContent('https://other.example/b', 'Fresh', 'Now', 'https://x.example/i.jpg', 'X'));

        self::assertEquals(
            new LinkCardContent('https://example.com/a', 'Fresh', 'Now', 'https://x.example/i.jpg', 'X'),
            $link->preview(),
        );
    }

    public function testARefreshKeepsAnImageAndDescriptionThePageDoesNotDeclare(): void
    {
        $link = new SharedLink(
            'hash',
            new LinkCardContent('https://example.com/a', 'First', 'Said first', 'https://cdn.example/t.jpg', 'Ex'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );

        $link->refresh(new LinkCardContent('https://example.com/a', 'Fresh', null, null, 'Example'));

        self::assertEquals(
            new LinkCardContent('https://example.com/a', 'Fresh', 'Said first', 'https://cdn.example/t.jpg', 'Example'),
            $link->preview(),
        );
    }

    public function testARefreshReplacesAnImageAndDescriptionThePageDeclares(): void
    {
        $link = new SharedLink(
            'hash',
            new LinkCardContent('https://example.com/a', 'First', 'Said first', 'https://cdn.example/t.jpg', 'Ex'),
            null,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );

        $link->refresh(
            new LinkCardContent('https://example.com/a', 'Fresh', 'Said now', 'https://cdn.example/n.jpg', 'Ex'),
        );

        self::assertSame('Said now', $link->preview()->description);
        self::assertSame('https://cdn.example/n.jpg', $link->preview()->imageUrl);
    }

    public function testAStatedLanguageReplacesAGuessedOne(): void
    {
        $link = self::link(LinkLanguage::guessed(FeedLanguage::German));

        $link->settleLanguage(LinkLanguage::stated(FeedLanguage::English));

        self::assertSame(FeedLanguage::English, $link->getLanguage());
        self::assertSame(LanguageSource::Stated, $link->getLanguageSource());
    }

    public function testAStatedLanguageIsNeverReplaced(): void
    {
        $link = self::link(LinkLanguage::stated(FeedLanguage::French));

        $link->settleLanguage(LinkLanguage::stated(FeedLanguage::Spanish));
        $link->settleLanguage(LinkLanguage::guessed(FeedLanguage::German));

        self::assertSame(FeedLanguage::French, $link->getLanguage());
        self::assertSame(LanguageSource::Stated, $link->getLanguageSource());
    }

    public function testAGuessNeverReplacesAGuess(): void
    {
        $link = self::link(LinkLanguage::guessed(FeedLanguage::German));

        $link->settleLanguage(LinkLanguage::guessed(FeedLanguage::English));

        self::assertSame(FeedLanguage::German, $link->getLanguage());
    }

    public function testAnUnsetLanguageIsSettled(): void
    {
        $link = self::link(null);

        $link->settleLanguage(LinkLanguage::stated(FeedLanguage::French));

        self::assertSame(FeedLanguage::French, $link->getLanguage());
        self::assertSame(LanguageSource::Stated, $link->getLanguageSource());
    }

    public function testAnUnsetLanguageHasNoSource(): void
    {
        $link = self::link(null);

        self::assertNull($link->getLanguage());
        self::assertNull($link->getLanguageSource());
    }

    private static function link(?LinkLanguage $language): SharedLink
    {
        return new SharedLink(
            'hash',
            new LinkCardContent('https://example.com/a', 'First', null, null, 'example.com'),
            $language,
            new \DateTimeImmutable('2026-11-15 10:00:00'),
        );
    }
}
