<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Ingest\Support;

use App\Service\Ingestion\Ingest\Model\CrossPostModel;
use App\Service\Ingestion\Ingest\Support\CrossPostHash;
use PHPUnit\Framework\TestCase;

final class CrossPostHashTest extends TestCase
{
    private const string AUTHOR = 'https://lemmy.world/u/PerfectDark';
    private const string LINK = 'https://x.example/a';

    public function testApiAndRssShapesOfOneLinkPostShareAKey(): void
    {
        $fromApi = CrossPostHash::build(new CrossPostModel(
            self::AUTHOR,
            self::LINK,
            'Title',
            '<p><a href="https://x.example/a">https://x.example/a</a></p><p>body</p>',
        ));
        $fromRss = CrossPostHash::build(new CrossPostModel(
            'https://lemmy.world/u/perfectdark/',
            self::LINK,
            'Title',
            '<a href="https://x.example/a">https://x.example/a</a><blockquote>q</blockquote>',
        ));

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $fromApi);
        self::assertSame($fromApi, $fromRss);
    }

    public function testAnotherAuthorIsAnotherKey(): void
    {
        $left = CrossPostHash::build(new CrossPostModel(self::AUTHOR, self::LINK, 'Title', null));
        $right = CrossPostHash::build(new CrossPostModel('https://lemmy.world/u/other', self::LINK, 'Title', null));

        self::assertNotSame($left, $right);
    }

    public function testTextPostsMatchOnTitleAndBodyText(): void
    {
        $left = CrossPostHash::build(
            new CrossPostModel(self::AUTHOR, null, 'Ask  Lemmy', '<p>Hello   <em>world</em></p>'),
        );
        $right = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'ask lemmy', "<div>Hello world</div>\n"));
        $other = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'ask lemmy', '<p>Hello there</p>'));

        self::assertNotNull($left);
        self::assertSame($left, $right);
        self::assertNotSame($left, $other);
    }

    public function testALinkPostAndATextPostNeverShareAKey(): void
    {
        $link = CrossPostHash::build(new CrossPostModel(self::AUTHOR, self::LINK, 'Title', null));
        $text = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', null));

        self::assertNotSame($link, $text);
    }

    public function testNoAuthorNoKey(): void
    {
        self::assertNull(CrossPostHash::build(new CrossPostModel(null, self::LINK, 'Title', null)));
    }

    public function testNothingToMatchNoKey(): void
    {
        self::assertNull(CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, '', '<p> </p>')));
    }

    public function testBlockElementsSeparateTheTextOfAnApiAndAnRssBody(): void
    {
        $fromApi = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', "a\nb"));
        $fromParagraphs = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', '<p>a</p><p>b</p>'));
        $fromDivisions = CrossPostHash::build(
            new CrossPostModel(self::AUTHOR, null, 'Title', '<div>a</div> <div>b</div>'),
        );

        self::assertNotNull($fromApi);
        self::assertSame($fromApi, $fromParagraphs);
        self::assertSame($fromApi, $fromDivisions);
    }

    public function testInlineElementsDoNotSplitAWord(): void
    {
        $split = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', 'Hel<b>lo</b>'));
        $whole = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', 'Hello'));

        self::assertSame($whole, $split);
    }

    public function testATitleAloneKeysATextPost(): void
    {
        self::assertNotNull(CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', null)));
    }

    public function testABodyAloneKeysATextPost(): void
    {
        self::assertNotNull(CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, '', '<p>Body</p>')));
    }

    public function testAnEmptyAuthorIsNoAuthor(): void
    {
        self::assertNull(CrossPostHash::build(new CrossPostModel('', self::LINK, 'Title', null)));
    }

    public function testAnEmptyLinkedUrlIsNoLink(): void
    {
        $emptyLink = CrossPostHash::build(new CrossPostModel(self::AUTHOR, '', 'Title', null));
        $noLink = CrossPostHash::build(new CrossPostModel(self::AUTHOR, null, 'Title', null));

        self::assertNotNull($emptyLink);
        self::assertSame($noLink, $emptyLink);
    }
}
