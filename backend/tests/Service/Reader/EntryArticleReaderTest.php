<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Reader\EntryArticleReader;
use App\Service\Reader\ExtractionCoverageGate;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryArticleReaderTest extends TestCase
{
    private FakeArticleExtractor $extractor;

    private EntryArticleReader $reader;

    protected function setUp(): void
    {
        $this->extractor = new FakeArticleExtractor();
        $this->reader = new EntryArticleReader($this->extractor, new ExtractionCoverageGate());
    }

    public function testAPostAnswersFeedBodyIsPostWithoutFetching(): void
    {
        $post = $this->entry('https://example.social/@a/1');
        $post->markTitleDerived();

        $result = $this->reader->read($post);

        self::assertSame(ExtractionFailure::FeedBodyIsPost, $result->reason);
        self::assertNull($result->url);
        self::assertSame([], $this->extractor->calls);
        self::assertFalse($this->reader->hasPageToFetch($post));
    }

    public function testAUrlLessPostIsStillAPost(): void
    {
        $post = $this->entry(null);
        $post->markTitleDerived();

        self::assertSame(ExtractionFailure::FeedBodyIsPost, $this->reader->read($post)->reason);
    }

    #[DataProvider('missingUrls')]
    public function testAnEntryWithoutUrlAnswersNoUrlWithoutFetching(?string $url): void
    {
        $entry = $this->entry($url);

        $result = $this->reader->read($entry);

        self::assertSame(ExtractionFailure::NoUrl, $result->reason);
        self::assertNull($result->url);
        self::assertSame([], $this->extractor->calls);
        self::assertFalse($this->reader->hasPageToFetch($entry));
    }

    /** @return iterable<string, array{?string}> */
    public static function missingUrls(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
    }

    public function testExtractsTheEntryPageWithItsHints(): void
    {
        $entry = $this->entry('https://example.com/article');
        $entry->setAuthor('Jana Steger');
        $extracted = $this->extraction('<p>Body</p>');
        $this->extractor->willReturn($extracted);

        $result = $this->reader->read($entry);

        self::assertSame($extracted, $result);
        self::assertSame(['https://example.com/article'], $this->extractor->calls);
        self::assertSame('Post', $this->extractor->hints[0]->title);
        self::assertSame('Jana Steger', $this->extractor->hints[0]->author);
        self::assertTrue($this->reader->hasPageToFetch($entry));
    }

    public function testAnExtractionThatMissesTheFullFeedArticleFailsAsMismatch(): void
    {
        $entry = $this->entry('https://example.com/article');
        $entry->setContentHtml('<p>' . $this->fullFeedArticle() . '</p>');
        $this->extractor->willReturn($this->extraction('<p>+++ neu im shop eingetroffen +++</p>'));

        $result = $this->reader->read($entry);

        self::assertSame(ExtractionFailure::Mismatch, $result->reason);
        self::assertSame('https://example.com/article', $result->url);
    }

    private function entry(?string $url): Entry
    {
        $published = new \DateTimeImmutable('2026-07-01T00:00:00Z');

        return new Entry(new Feed('https://example.com/feed.xml'), 'guid-1', $url, 'Post', $published, $published);
    }

    private function extraction(string $contentHtml): ExtractionResultModel
    {
        return ExtractionResultModel::ok('https://example.com/article', 'Post', null, null, $contentHtml, null);
    }

    private function fullFeedArticle(): string
    {
        return implode(' ', array_map(static fn (int $index): string => 'wort' . $index, range(1, 300)));
    }
}
