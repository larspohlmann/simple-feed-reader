<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingest\PlatformEntryRule;

use App\Service\Ingest\PlatformEntryRule\VideoPageEntryRule;
use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\ParsedAttachmentModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use App\Service\Reader\Media\EmbedProvider\VimeoEmbedProvider;
use App\Service\Reader\Media\EmbedProvider\YouTubeEmbedProvider;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaMarkup;
use PHPUnit\Framework\TestCase;

final class VideoPageEntryRuleTest extends TestCase
{
    private const string WATCH = 'https://www.youtube.com/watch?v=Xic3faS00Qs';
    private const string PLAYER_LINK = '<a href="https://www.youtube-nocookie.com/embed/Xic3faS00Qs">'
        . '<img src="https://i.ytimg.com/vi/Xic3faS00Qs/hqdefault.jpg" alt="Watch on YouTube"></a>';

    private static function rule(): VideoPageEntryRule
    {
        return new VideoPageEntryRule(
            new EmbedProviders([new YouTubeEmbedProvider(), new VimeoEmbedProvider()]),
            new MediaMarkup(),
        );
    }

    private static function entry(
        ?string $url,
        ?string $contentHtml,
        ?ParsedMediaBundleModel $bundle = null,
    ): ParsedEntryModel {
        return new ParsedEntryModel(
            'yt:video:1',
            $url,
            'T',
            null,
            null,
            $contentHtml,
            null,
            new ParsedEntryMediaModel(null, $bundle),
        );
    }

    public function testAVideoPagesBodyLeadsWithItsPlayerLink(): void
    {
        $entry = self::entry(self::WATCH, '<p>Description</p>');

        self::assertTrue(self::rule()->supports($entry));
        self::assertSame(self::PLAYER_LINK . '<p>Description</p>', self::rule()->apply($entry)->contentHtml);
    }

    public function testABodylessVideoPageGetsThePlayerLinkAsItsBody(): void
    {
        self::assertSame(self::PLAYER_LINK, self::rule()->apply(self::entry(self::WATCH, null))->contentHtml);
    }

    public function testAnArticleOrAMissingUrlIsNotAVideoPage(): void
    {
        self::assertFalse(self::rule()->supports(self::entry('https://example.com/post', '<p>x</p>')));
        self::assertFalse(self::rule()->supports(self::entry(null, '<p>x</p>')));
    }

    public function testABodyThatAlreadyEmbedsTheVideoIsLeftAlone(): void
    {
        $iframe = '<iframe src="https://www.youtube.com/embed/Xic3faS00Qs"></iframe>';

        self::assertFalse(self::rule()->supports(self::entry(self::WATCH, $iframe)));
        self::assertFalse(self::rule()->supports(self::entry(self::WATCH, self::PLAYER_LINK)));
    }

    public function testABodyEmbeddingAnotherVideoStillGetsItsOwn(): void
    {
        self::assertTrue(self::rule()->supports(
            self::entry(self::WATCH, '<a href="https://youtu.be/aaaaaaaaaaa">other</a>'),
        ));
    }

    public function testAnEpisodeThatAlreadyPlaysItsEnclosureGetsNoPlayer(): void
    {
        $bundle = new ParsedMediaBundleModel([], [
            new ParsedAttachmentModel('https://example.com/episode.mp3', FeedMediaKind::Audio, 'audio/mpeg'),
        ]);

        self::assertFalse(self::rule()->supports(self::entry(self::WATCH, null, $bundle)));
    }
}
