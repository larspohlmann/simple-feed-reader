<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\LinkCardContent;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Social\SharedPageCards;
use App\Tests\Support\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

final class SharedPageCardsTest extends TestCase
{
    private const string URL = 'https://example.com/article';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
    }

    public function testTheCardIsThePagesOwn(): void
    {
        $own = new LinkCardContent(self::URL, 'An article', 'A summary', 'https://example.com/lead.jpg', 'Example');

        self::assertSame($own, $this->cardsReading(new PageCardModel($own, null))->cardOf(self::URL));
        self::assertSame([], $this->logger->records);
    }

    public function testAPageWithoutCardOfItsOwnIsTitledWithItsHost(): void
    {
        $card = $this->cardsReading(new PageCardModel(null, null))->cardOf(self::URL);

        self::assertEquals(new LinkCardContent(self::URL, 'example.com', null, null, 'example.com'), $card);
        self::assertSame([], $this->logger->records);
    }

    public function testTheHostTitleDropsCaseAndALeadingWwwLikeEveryOtherCard(): void
    {
        $address = 'https://WWW.Example.com/article';

        $card = $this->cardsReading(new PageCardModel(null, null))->cardOf($address);

        self::assertEquals(new LinkCardContent($address, 'example.com', null, null, 'example.com'), $card);
    }

    public function testAnAddressWithoutHostIsTitledWithItself(): void
    {
        $address = 'mailto:someone';

        $card = $this->cardsReading(new PageCardModel(null, null))->cardOf($address);

        self::assertEquals(new LinkCardContent($address, $address, null, null, $address), $card);
    }

    public function testAPageThatCannotBeReadIsTitledWithItsHostAndLoggedAtDebug(): void
    {
        $pageCards = $this->createStub(PageCardReaderInterface::class);
        $pageCards->method('read')->willThrowException(new PageFetchException('gone'));

        $card = (new SharedPageCards($pageCards, $this->logger))->cardOf(self::URL);

        self::assertEquals(new LinkCardContent(self::URL, 'example.com', null, null, 'example.com'), $card);
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::DEBUG, $this->logger->records[0]['level']);
        self::assertSame(['url' => self::URL, 'reason' => 'gone'], $this->logger->records[0]['context']);
    }

    private function cardsReading(PageCardModel $page): SharedPageCards
    {
        $pageCards = $this->createStub(PageCardReaderInterface::class);
        $pageCards->method('read')->willReturn($page);

        return new SharedPageCards($pageCards, $this->logger);
    }
}
