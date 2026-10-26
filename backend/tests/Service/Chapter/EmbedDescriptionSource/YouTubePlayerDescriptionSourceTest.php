<?php

declare(strict_types=1);

namespace App\Tests\Service\Chapter\EmbedDescriptionSource;

use App\Service\Chapter\EmbedDescriptionSource\YouTubePlayerDescriptionSource;
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

final class YouTubePlayerDescriptionSourceTest extends TestCase
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

            return new MockResponse('{}');
        });

        $this->source($http)->descriptionOf($this->embed());

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
        yield 'no video details' => ['{"playabilityStatus":{}}'];
        yield 'video details not an object' => ['{"videoDetails":"x"}'];
        yield 'no short description' => ['{"videoDetails":{}}'];
        yield 'description not a string' => ['{"videoDetails":{"shortDescription":5}}'];
    }

    public function testIsTheYouTubeSource(): void
    {
        self::assertSame('youtube', YouTubePlayerDescriptionSource::provider());
    }

    /** @param array<mixed> $body */
    private function clientNameOf(array $body): mixed
    {
        $context = $body['context'] ?? [];
        $client = \is_array($context) ? ($context['client'] ?? []) : [];

        return \is_array($client) ? ($client['clientName'] ?? null) : null;
    }

    private function embed(): EmbedAddressModel
    {
        return new EmbedAddressModel(ChapteredEmbed::YouTube, 'dQw4w9WgXcQ');
    }

    private function source(MockHttpClient $http): YouTubePlayerDescriptionSource
    {
        $guard = new UrlGuard(new StaticDnsResolver(['www.youtube.com' => ['93.184.216.34']]), new IpValidator());

        $request = new GuardedDescriptionRequest(FetchWiring::guardedApiClient($http, $guard));

        return new YouTubePlayerDescriptionSource($request);
    }
}
