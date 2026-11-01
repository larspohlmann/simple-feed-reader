<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\LinkCardContent;
use App\Service\Image\Exception\GuardedImageRejectedException;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Image\Exception\ImageProcessingException;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\ImageResizer\ImageResizerInterface;
use App\Service\LinkCard\Model\PageCardModel;
use App\Service\LinkCard\PageCardReader\PageCardReaderInterface;
use App\Service\Reader\Exception\PageFetchException;
use App\Service\Social\BlueskyLinkCards;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use App\Tests\Support\StubGuardedImageDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyLinkCardsTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string UPLOAD_BLOB = 'POST https://pds.example.test/xrpc/com.atproto.repo.uploadBlob';
    private const string URL = 'https://example.com/article';
    private const string IMAGE_URL = 'https://example.com/lead.jpg';
    private const array BLOB = [
        '$type' => 'blob',
        'ref' => ['$link' => 'bafkrei-thumb'],
        'mimeType' => 'image/jpeg',
        'size' => 4321,
    ];

    private StubGuardedImageDownloader $downloader;
    private RecordingLogger $logger;
    private PageCardModel $page;

    protected function setUp(): void
    {
        $this->downloader = new StubGuardedImageDownloader();
        $this->downloader->willReturnBytes(self::IMAGE_URL, 'original-bytes');
        $this->logger = new RecordingLogger();
        $this->page = self::pageDeclaring('A summary', self::IMAGE_URL);
    }

    public function testTheCardCarriesThePagesOwnCardAndTheUploadedCoverThumbnail(): void
    {
        $http = $this->uploading(new MockResponse(json_encode(['blob' => self::BLOB], \JSON_THROW_ON_ERROR)));
        $resizer = $this->createMock(ImageResizerInterface::class);
        $resizer->expects($this->once())->method('coverJpeg')
            ->with('original-bytes', 1200, 630)->willReturn('jpeg-bytes');

        $card = $this->cards($http, $resizer)->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertSame([
            '$type' => 'app.bsky.embed.external',
            'external' => [
                'uri' => self::URL,
                'title' => 'An article',
                'description' => 'A summary',
                'thumb' => self::BLOB,
            ],
        ], $card);
        self::assertSame('jpeg-bytes', $http->requests[1]['body']);
        self::assertContains('Content-Type: image/jpeg', $http->requests[1]['headers']);
    }

    public function testAPageWithoutDescriptionSendsAnEmptyOne(): void
    {
        $this->page = self::pageDeclaring(null, self::IMAGE_URL);
        $http = $this->uploading(new MockResponse(json_encode(['blob' => self::BLOB], \JSON_THROW_ON_ERROR)));

        $card = $this->cards($http, $this->resizer())->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertSame('', self::externalOf($card)['description']);
    }

    public function testAPageWithoutImageDownloadsNothingAndHasNoThumbnail(): void
    {
        $this->page = self::pageDeclaring('A summary', null);

        $card = $this->cardsNeverDownloading()->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertSame(
            ['uri' => self::URL, 'title' => 'An article', 'description' => 'A summary'],
            self::externalOf($card),
        );
    }

    public function testAPageWithoutCardOfItsOwnIsTitledWithItsHost(): void
    {
        $this->page = new PageCardModel(null, null);

        $card = $this->cardsNeverDownloading()->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertSame(['uri' => self::URL, 'title' => 'example.com', 'description' => ''], self::externalOf($card));
        self::assertSame([], $this->logger->records);
    }

    public function testAPageThatCannotBeReadIsTitledWithItsHost(): void
    {
        $pageCards = $this->createStub(PageCardReaderInterface::class);
        $pageCards->method('read')->willThrowException(new PageFetchException('gone'));

        $card = $this->cardsReading($pageCards, $this->neverDownloading(), new RoutedHttpClient([]), $this->resizer())
            ->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertSame(['uri' => self::URL, 'title' => 'example.com', 'description' => ''], self::externalOf($card));
        $this->assertLoggedAtDebug();
        self::assertSame(['url' => self::URL, 'reason' => 'gone'], $this->logger->records[0]['context']);
    }

    public function testAnUploadAnswerWithoutBlobLeavesTheThumbnailOut(): void
    {
        $http = $this->uploading(new MockResponse('{}'));

        $card = $this->cards($http, $this->resizer())->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertArrayNotHasKey('thumb', self::externalOf($card));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function imageFailures(): iterable
    {
        yield 'a refused download' => [new GuardedImageRejectedException('blocked')];
        yield 'an unavailable image' => [new GuardedImageUnavailableException('gone')];
    }

    #[DataProvider('imageFailures')]
    public function testAnImageThatCannotBeDownloadedLeavesTheThumbnailOut(\Throwable $failure): void
    {
        $this->downloader->willFail(self::IMAGE_URL, $failure);

        $card = $this->cards(new RoutedHttpClient([]), $this->resizer())
            ->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertArrayNotHasKey('thumb', self::externalOf($card));
        $this->assertLoggedAtDebug();
    }

    public function testAnImageThatCannotBeResizedLeavesTheThumbnailOut(): void
    {
        $resizer = $this->createStub(ImageResizerInterface::class);
        $resizer->method('coverJpeg')->willThrowException(new ImageProcessingException('not an image'));

        $card = $this->cards(new RoutedHttpClient([]), $resizer)
            ->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertArrayNotHasKey('thumb', self::externalOf($card));
        $this->assertLoggedAtDebug();
        self::assertSame(
            ['image' => self::IMAGE_URL, 'reason' => 'not an image'],
            $this->logger->records[0]['context'],
        );
    }

    /** @return iterable<string, array{int}> */
    public static function uploadFailures(): iterable
    {
        yield 'a refused upload' => [Response::HTTP_BAD_REQUEST];
        yield 'an unreachable network' => [Response::HTTP_SERVICE_UNAVAILABLE];
        yield 'a missing endpoint' => [Response::HTTP_NOT_FOUND];
        yield 'a throttled network' => [Response::HTTP_TOO_MANY_REQUESTS];
    }

    #[DataProvider('uploadFailures')]
    public function testAFailedUploadLeavesTheThumbnailOut(int $status): void
    {
        $http = $this->uploading(new MockResponse('{"error":"BlobTooLarge"}', ['http_code' => $status]));

        $card = $this->cards($http, $this->resizer())->cardOf(SocialApiWiring::blueskyAccount(), self::URL);

        self::assertArrayNotHasKey('thumb', self::externalOf($card));
        $this->assertLoggedAtDebug();
    }

    private function assertLoggedAtDebug(): void
    {
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::DEBUG, $this->logger->records[0]['level']);
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return array<mixed>
     */
    private static function externalOf(array $card): array
    {
        self::assertIsArray($card['external']);

        return $card['external'];
    }

    private function uploading(MockResponse $answer): RoutedHttpClient
    {
        return new RoutedHttpClient([
            self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-1'),
            self::UPLOAD_BLOB => $answer,
        ]);
    }

    private function resizer(): ImageResizerInterface
    {
        $resizer = $this->createStub(ImageResizerInterface::class);
        $resizer->method('coverJpeg')->willReturn('jpeg-bytes');

        return $resizer;
    }

    private function cards(RoutedHttpClient $http, ImageResizerInterface $resizer): BlueskyLinkCards
    {
        $pageCards = $this->createStub(PageCardReaderInterface::class);
        $pageCards->method('read')->willReturn($this->page);

        return $this->cardsReading($pageCards, $this->downloader, $http, $resizer);
    }

    private function cardsNeverDownloading(): BlueskyLinkCards
    {
        $pageCards = $this->createStub(PageCardReaderInterface::class);
        $pageCards->method('read')->willReturn($this->page);

        return $this->cardsReading($pageCards, $this->neverDownloading(), new RoutedHttpClient([]), $this->resizer());
    }

    private function cardsReading(
        PageCardReaderInterface $pageCards,
        GuardedImageDownloaderInterface $downloader,
        RoutedHttpClient $http,
        ImageResizerInterface $resizer,
    ): BlueskyLinkCards {
        return new BlueskyLinkCards(
            $pageCards,
            $downloader,
            $resizer,
            SocialApiWiring::blueskySessions($http->client()),
            $this->logger,
        );
    }

    private function neverDownloading(): GuardedImageDownloaderInterface
    {
        $downloader = $this->createMock(GuardedImageDownloaderInterface::class);
        $downloader->expects($this->never())->method('download');

        return $downloader;
    }

    private static function pageDeclaring(?string $description, ?string $imageUrl): PageCardModel
    {
        return new PageCardModel(
            new LinkCardContent(self::URL, 'An article', $description, $imageUrl, 'Example'),
            null,
        );
    }
}
