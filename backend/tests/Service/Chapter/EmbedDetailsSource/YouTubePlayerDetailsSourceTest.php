<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\EmbedDetailsSource;

use App\Enum\ProxyType;
use App\Service\Chapter\EmbedDetailsSource\YouTubePlayerDetailsSource;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubePlayerDetailsSourceTest extends TestCase
{
    private const string WITHOUT_THE_VIDEO = 'The youtube description of dQw4w9WgXcQ answered without the video.';
    private const string WALL = '{"playabilityStatus":{"status":"LOGIN_REQUIRED","reason":"Sign in"}}';

    public function testAsksThePlayerEndpointForTheVideo(): void
    {
        $seenMethod = '';
        $seenUrl = '';
        $seenBody = '';
        $http = new MockHttpClient(static function (
            string $method,
            string $url,
            array $options
        ) use (
            &$seenMethod,
            &$seenUrl,
            &$seenBody,
        ) {
            $seenMethod = $method;
            $seenUrl = $url;
            $seenBody = \is_string($options['body']) ? $options['body'] : '';

            return new MockResponse('{"videoDetails":{}}');
        });

        $this->source($http)->detailsOf($this->embed());

        $body = json_decode($seenBody, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('POST', $seenMethod);
        self::assertSame('https://www.youtube.com/youtubei/v1/player?prettyPrint=false', $seenUrl);
        self::assertIsArray($body);
        self::assertSame('dQw4w9WgXcQ', $body['videoId']);
        self::assertSame('WEB', $this->clientNameOf($body));
    }

    public function testReadsTheShortDescription(): void
    {
        $http = new MockHttpClient(new MockResponse('{"videoDetails":{"shortDescription":"0:00 Intro\n1:00 Main"}}'));

        self::assertSame("0:00 Intro\n1:00 Main", $this->source($http)->detailsOf($this->embed())->description);
    }

    #[DataProvider('answersWithoutADescription')]
    public function testAnAnswerWithoutADescriptionGivesNone(string $body): void
    {
        self::assertSame('', $this->detailsAnswering($body)->description);
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutADescription(): iterable
    {
        yield 'no short description' => ['{"videoDetails":{}}'];
        yield 'description not a string' => ['{"videoDetails":{"shortDescription":5}}'];
    }

    public function testReadsTheTitleAndTheLength(): void
    {
        $details = $this->detailsAnswering('{"videoDetails":{"title":"A mix","lengthSeconds":"4964"}}');

        self::assertSame('A mix', $details->title);
        self::assertSame(4964, $details->durationSeconds);
    }

    public function testALengthGivenAsANumberCounts(): void
    {
        self::assertSame(4964, $this->detailsAnswering('{"videoDetails":{"lengthSeconds":4964}}')->durationSeconds);
    }

    #[DataProvider('answersWithoutATitle')]
    public function testAnAnswerWithoutATitleGivesNone(string $body): void
    {
        self::assertNull($this->detailsAnswering($body)->title);
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutATitle(): iterable
    {
        yield 'no title' => ['{"videoDetails":{}}'];
        yield 'an empty title' => ['{"videoDetails":{"title":""}}'];
        yield 'a title not a string' => ['{"videoDetails":{"title":5}}'];
    }

    #[DataProvider('answersWithoutALength')]
    public function testAnAnswerWithoutALengthGivesNone(string $body): void
    {
        self::assertNull($this->detailsAnswering($body)->durationSeconds);
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutALength(): iterable
    {
        yield 'no length' => ['{"videoDetails":{}}'];
        yield 'a length of zero' => ['{"videoDetails":{"lengthSeconds":"0"}}'];
        yield 'a length of zero as a number' => ['{"videoDetails":{"lengthSeconds":0}}'];
        yield 'a negative length' => ['{"videoDetails":{"lengthSeconds":-5}}'];
        yield 'a length not made of digits' => ['{"videoDetails":{"lengthSeconds":"1:22:44"}}'];
        yield 'an empty length' => ['{"videoDetails":{"lengthSeconds":""}}'];
        yield 'a fractional length' => ['{"videoDetails":{"lengthSeconds":4964.5}}'];
    }

    #[DataProvider('answersWithoutTheVideo')]
    public function testAnAnswerWithoutTheVideoIsAnOutage(string $body): void
    {
        $this->expectException(EmbedDescriptionUnreachableException::class);
        $this->expectExceptionMessage(self::WITHOUT_THE_VIDEO);

        $this->source(new MockHttpClient(new MockResponse($body)))->detailsOf($this->embed());
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutTheVideo(): iterable
    {
        yield 'a login wall' => ['{"playabilityStatus":{"status":"LOGIN_REQUIRED","reason":"Sign in"}}'];
        yield 'no video details' => ['{}'];
        yield 'video details not an object' => ['{"videoDetails":"x"}'];
    }

    public function testAnAnswerWalledAtTheProxyIsAskedAgainDirectly(): void
    {
        $seenOptions = [];
        $direct = '{"playabilityStatus":{"status":"OK"},"videoDetails":{"title":"A mix"}}';
        $http = self::answeringThroughTheProxy(self::WALL, $direct, $seenOptions);
        $logger = new RecordingLogger();

        $details = $this->source($http, self::proxy(directFallback: true), $logger)->detailsOf($this->embed());

        self::assertSame('A mix', $details->title);
        self::assertCount(2, $seenOptions);
        self::assertArrayHasKey('proxy', $seenOptions[0]);
        self::assertArrayNotHasKey('proxy', $seenOptions[1]);
        self::assertSame(['www.youtube.com' => '93.184.216.34'], $seenOptions[1]['resolve'] ?? null);
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'YouTube walled the proxied player request for {videoId}; asked again directly.',
            'context' => ['videoId' => 'dQw4w9WgXcQ', 'walledAgain' => false],
        ]], $logger->records);
    }

    public function testAWalledAnswerStaysAnOutageWhenTheProxyForbidsGoingDirect(): void
    {
        $seenOptions = [];
        $http = self::answeringThroughTheProxy(self::WALL, '{"videoDetails":{}}', $seenOptions);
        $logger = new RecordingLogger();

        try {
            $this->source($http, self::proxy(directFallback: false), $logger)->detailsOf($this->embed());
            self::fail('expected the walled answer to be an outage');
        } catch (EmbedDescriptionUnreachableException $exception) {
            self::assertSame(self::WITHOUT_THE_VIDEO, $exception->getMessage());
        }
        self::assertCount(1, $seenOptions);
        self::assertSame([], $logger->records);
    }

    public function testAWalledAnswerWithoutAProxyIsNotAskedAgain(): void
    {
        $http = new MockHttpClient(new MockResponse(self::WALL));

        try {
            $this->source($http)->detailsOf($this->embed());
            self::fail('expected the walled answer to be an outage');
        } catch (EmbedDescriptionUnreachableException) {
            self::assertSame(1, $http->getRequestsCount());
        }
    }

    public function testAnAnswerWalledOnBothRoutesIsAnOutage(): void
    {
        $seenOptions = [];
        $http = self::answeringThroughTheProxy(self::WALL, self::WALL, $seenOptions);
        $logger = new RecordingLogger();

        try {
            $this->source($http, self::proxy(directFallback: true), $logger)->detailsOf($this->embed());
            self::fail('expected the walled answer to be an outage');
        } catch (EmbedDescriptionUnreachableException $exception) {
            self::assertSame(self::WITHOUT_THE_VIDEO, $exception->getMessage());
        }
        self::assertCount(2, $seenOptions);
        self::assertSame(['videoId' => 'dQw4w9WgXcQ', 'walledAgain' => true], $logger->records[0]['context'] ?? null);
    }

    public function testAnAnswerWithTheVideoThroughTheProxyIsAskedOnce(): void
    {
        $seenOptions = [];
        $http = self::answeringThroughTheProxy('{"videoDetails":{"title":"A mix"}}', self::WALL, $seenOptions);

        $details = $this->source($http, self::proxy(directFallback: true))->detailsOf($this->embed());

        self::assertSame('A mix', $details->title);
        self::assertCount(1, $seenOptions);
    }

    public function testIsTheYouTubeSource(): void
    {
        self::assertSame('youtube', YouTubePlayerDetailsSource::provider());
    }

    /** @param array<mixed> $body */
    private function clientNameOf(array $body): mixed
    {
        $context = $body['context'] ?? [];
        $client = \is_array($context) ? ($context['client'] ?? []) : [];

        return \is_array($client) ? ($client['clientName'] ?? null) : null;
    }

    private function detailsAnswering(string $body): EmbedDescriptionModel
    {
        return $this->source(new MockHttpClient(new MockResponse($body)))->detailsOf($this->embed());
    }

    private function embed(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::YouTube, 'dQw4w9WgXcQ');
    }

    private function source(
        MockHttpClient $http,
        ?ProxyConfigModel $proxy = null,
        LoggerInterface $logger = new NullLogger(),
    ): YouTubePlayerDetailsSource {
        $guard = new UrlGuard(new StaticDnsResolver(['www.youtube.com' => ['93.184.216.34']]), new IpValidator());

        $request = new GuardedDescriptionRequest(FetchWiring::guardedApiClient($http, $guard, $proxy));

        return new YouTubePlayerDetailsSource($request, $logger);
    }

    /** @param list<array<string, mixed>> $seenOptions */
    private static function answeringThroughTheProxy(
        string $proxied,
        string $direct,
        array &$seenOptions,
    ): MockHttpClient {
        return new MockHttpClient(
            static function (string $method, string $url, array $options) use ($proxied, $direct, &$seenOptions) {
                $seenOptions[] = $options;

                return new MockResponse(isset($options['proxy']) ? $proxied : $direct);
            },
        );
    }

    private static function proxy(bool $directFallback): ProxyConfigModel
    {
        return new ProxyConfigModel(ProxyType::Socks5, 'proxy.example.test', 1080, null, null, $directFallback);
    }
}
