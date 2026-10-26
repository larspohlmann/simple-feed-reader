<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\EmbedDescriptionSource;

use App\Service\Chapter\EmbedDescriptionSource\SoundCloudOembedDescriptionSource;
use App\Service\Chapter\GuardedDescriptionRequest;
use App\Service\Chapter\Model\ChapteredEmbed;
use App\Service\Chapter\Model\EmbedAddressModel;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SoundCloudOembedDescriptionSourceTest extends TestCase
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

        $this->source($http)->descriptionOf($this->embed());

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

        self::assertSame("0:00 Intro\n1:00 Main", $this->source($http)->descriptionOf($this->embed()));
    }

    #[DataProvider('answersWithoutADescription')]
    public function testAnAnswerWithoutADescriptionGivesNone(string $body): void
    {
        self::assertSame('', $this->source(new MockHttpClient(new MockResponse($body)))->descriptionOf($this->embed()));
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutADescription(): iterable
    {
        yield 'no description' => ['{"title":"x"}'];
        yield 'description not a string' => ['{"description":5}'];
    }

    public function testIsTheSoundCloudSource(): void
    {
        self::assertSame('soundcloud', SoundCloudOembedDescriptionSource::provider());
    }

    private function embed(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::SoundCloud, '1234567');
    }

    private function source(MockHttpClient $http): SoundCloudOembedDescriptionSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['soundcloud.com' => ['93.184.216.34']]), new IpValidator());

        $request = new GuardedDescriptionRequest(FetchWiring::guardedApiClient($http, $guard));

        return new SoundCloudOembedDescriptionSource($request);
    }
}
