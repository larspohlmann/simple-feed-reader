<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Service\Content\LinkCard\Model\PageCardModel;
use App\Service\Content\LinkCard\Model\PageIdentityModel;
use App\Service\Content\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Content\LinkCard\PostedUrlCheck;
use App\Service\Content\Reader\Exception\PageFetchException;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class PostedUrlCheckTest extends TestCase
{
    private const string CLEANED = 'https://news.example/a?id=1';
    private const string POSTED = 'https://news.example/a?cmp=rss&id=1';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testTheSamePageKeepsTheCleanedUrl(): void
    {
        $card = $this->check([
            self::CLEANED => self::page(self::CLEANED, 'Story'),
            self::POSTED => self::page(self::POSTED, 'Story'),
        ])->resolveCard(self::CLEANED, self::POSTED);

        self::assertSame(self::CLEANED, $card->card?->url);
        self::assertSame([], $this->logger->records);
    }

    public function testAPostedPageThatRedirectsToTheCleanedOneIsTheSamePage(): void
    {
        $card = $this->check([
            self::CLEANED => self::page(self::CLEANED, 'Story'),
            self::POSTED => self::page(self::CLEANED . '&utm_source=x', 'Story'),
        ])->resolveCard(self::CLEANED, self::POSTED);

        self::assertSame(self::CLEANED, $card->card?->url);
    }

    public function testAnotherTitleKeepsThePostedUrlAndLogsTheStrippedKeys(): void
    {
        $card = $this->check([
            self::CLEANED => self::page(self::CLEANED, 'Front page'),
            self::POSTED => self::page(self::POSTED, 'Story'),
        ])->resolveCard(self::CLEANED, self::POSTED);

        self::assertNotNull($card->card);
        self::assertSame(self::POSTED, $card->card->url);
        self::assertSame('Story', $card->card->title);
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::INFO, $this->logger->records[0]['level']);
        self::assertStringContainsString('showed another page', $this->logger->records[0]['message']);
        self::assertSame(
            ['host' => 'news.example', 'strippedKeys' => ['cmp']],
            $this->logger->records[0]['context'],
        );
    }

    public function testAStrippedFragmentIsLoggedByItsKey(): void
    {
        $posted = self::CLEANED . '#xtor=RSS-3';

        $this->check([
            self::CLEANED => self::page(self::CLEANED, 'Front page'),
            $posted => self::page($posted, 'Story'),
        ])->resolveCard(self::CLEANED, $posted);

        self::assertSame(['xtor'], $this->logger->records[0]['context']['strippedKeys'] ?? null);
    }

    public function testACleanedUrlThatFailsKeepsAPostedUrlThatWorks(): void
    {
        $card = $this->check([self::POSTED => self::page(self::POSTED, 'Story')])
            ->resolveCard(self::CLEANED, self::POSTED);

        self::assertSame(self::POSTED, $card->card?->url);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('cleaned URL failed', $this->logger->records[0]['message']);
        self::assertStringNotContainsString('another page', $this->logger->records[0]['message']);
        self::assertSame(
            ['host' => 'news.example', 'strippedKeys' => ['cmp']],
            $this->logger->records[0]['context'],
        );
    }

    public function testAPostedUrlThatFailsTrustsTheCleanedOne(): void
    {
        $card = $this->check([self::CLEANED => self::page(self::CLEANED, 'Story')])
            ->resolveCard(self::CLEANED, self::POSTED);

        self::assertSame(self::CLEANED, $card->card?->url);
        self::assertSame([], $this->logger->records);
    }

    public function testBothFailingFailsTheCard(): void
    {
        $this->expectException(PageFetchException::class);

        $this->check([])->resolveCard(self::CLEANED, self::POSTED);
    }

    public function testAFileTrustsTheCleanedUrl(): void
    {
        $file = new PageCardModel(null, null);

        $card = $this->check([self::CLEANED => $file, self::POSTED => self::page(self::POSTED, 'Story')])
            ->resolveCard(self::CLEANED, self::POSTED);

        self::assertSame($file, $card);
    }

    /** @param array<string, PageCardModel> $pages */
    private function check(array $pages): PostedUrlCheck
    {
        $reader = new class ($pages) implements PageCardReaderInterface {
            /** @param array<string, PageCardModel> $pages */
            public function __construct(private readonly array $pages)
            {
            }

            public function read(string $sharedUrl): PageCardModel
            {
                return $this->pages[$sharedUrl] ?? throw new PageFetchException('Not found: ' . $sharedUrl);
            }
        };

        return new PostedUrlCheck($reader, $this->logger);
    }

    private static function page(string $finalUrl, string $title): PageCardModel
    {
        return new PageCardModel(
            new LinkCardContent(self::CLEANED, $title, null, null, 'news.example'),
            null,
            new PageIdentityModel($finalUrl, null, $title),
        );
    }
}
