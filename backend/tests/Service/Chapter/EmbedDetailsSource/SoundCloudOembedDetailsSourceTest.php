<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\EmbedDetailsSource;

use App\Service\Chapter\EmbedDetailsSource\SoundCloudOembedDetailsSource;
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

final class SoundCloudOembedDetailsSourceTest extends TestCase
{
    public function testAsksTheOembedEndpointForTheTrack(): void
    {
        $seenMethod = '';
        $seenUrl = '';
        $http = new MockHttpClient(static function (string $method, string $url) use (&$seenMethod, &$seenUrl) {
            $seenMethod = $method;
            $seenUrl = $url;

            return new MockResponse('{}');
        });

        $this->source($http)->detailsOf($this->embed());

        $parts = parse_url($seenUrl);
        self::assertIsArray($parts);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame('GET', $seenMethod);
        self::assertSame('soundcloud.com', $parts['host'] ?? null);
        self::assertSame('/oembed', $parts['path'] ?? null);
        self::assertSame('json', $query['format']);
        self::assertSame('https://api.soundcloud.com/tracks/1234567', $query['url']);
    }

    public function testReadsTheDescription(): void
    {
        $http = new MockHttpClient(new MockResponse('{"description":"0:00 Intro\n1:00 Main"}'));

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
        yield 'no description' => ['{"title":"x"}'];
        yield 'description not a string' => ['{"description":5}'];
    }

    public function testReadsTheTitleAndTellsNoLength(): void
    {
        $details = $this->detailsAnswering(
            '{"title":"Catching Flies | Anjunadeep Open Air: London 2026 by Anjunadeep","duration":4964}',
        );

        self::assertSame('Catching Flies | Anjunadeep Open Air: London 2026 by Anjunadeep', $details->title);
        self::assertNull($details->durationSeconds);
    }

    #[DataProvider('answersWithoutATitle')]
    public function testAnAnswerWithoutATitleGivesNone(string $body): void
    {
        self::assertNull($this->detailsAnswering($body)->title);
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutATitle(): iterable
    {
        yield 'no title' => ['{}'];
        yield 'an empty title' => ['{"title":""}'];
        yield 'a title not a string' => ['{"title":5}'];
    }

    public function testReadsTheArtwork(): void
    {
        $details = $this->detailsAnswering('{"thumbnail_url":"https://i1.sndcdn.com/artworks-a-t500x500.jpg"}');

        self::assertSame('https://i1.sndcdn.com/artworks-a-t500x500.jpg', $details->artworkUrl);
    }

    #[DataProvider('answersWithoutArtwork')]
    public function testAnAnswerWithoutHttpsArtworkGivesNone(string $body): void
    {
        self::assertNull($this->detailsAnswering($body)->artworkUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutArtwork(): iterable
    {
        yield 'no artwork' => ['{}'];
        yield 'artwork not a string' => ['{"thumbnail_url":5}'];
        yield 'artwork over plain http' => ['{"thumbnail_url":"http://i1.sndcdn.com/a.jpg"}'];
    }

    public function testIsTheSoundCloudSource(): void
    {
        self::assertSame('soundcloud', SoundCloudOembedDetailsSource::provider());
    }

    private function detailsAnswering(string $body): EmbedDescriptionModel
    {
        return $this->source(new MockHttpClient(new MockResponse($body)))->detailsOf($this->embed());
    }

    private function embed(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::SoundCloud, '1234567');
    }

    private function source(MockHttpClient $http): SoundCloudOembedDetailsSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['soundcloud.com' => ['93.184.216.34']]), new IpValidator());

        $request = new GuardedDescriptionRequest(FetchWiring::guardedApiClient($http, $guard));

        return new SoundCloudOembedDetailsSource($request);
    }
}
