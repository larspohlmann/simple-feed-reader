<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\EmbedDetailsSource;

use App\Service\Chapter\EmbedDetailsSource\YouTubePlayerDetailsSource;
use App\Service\Chapter\Exception\EmbedDescriptionUnreachableException;
use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Chapter\Model\EmbedDescriptionModel;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class YouTubePlayerDetailsSourceTest extends TestCase
{
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
        $this->expectExceptionMessage('The youtube description of dQw4w9WgXcQ answered without the video.');

        $this->source(new MockHttpClient(new MockResponse($body)))->detailsOf($this->embed());
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutTheVideo(): iterable
    {
        yield 'a login wall' => ['{"playabilityStatus":{"status":"LOGIN_REQUIRED","reason":"Sign in"}}'];
        yield 'no video details' => ['{}'];
        yield 'video details not an object' => ['{"videoDetails":"x"}'];
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

    private function source(MockHttpClient $http): YouTubePlayerDetailsSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['www.youtube.com' => ['93.184.216.34']]), new IpValidator());

        $request = new GuardedDescriptionRequest(FetchWiring::guardedApiClient($http, $guard));

        return new YouTubePlayerDetailsSource($request);
    }
}
