<?php

declare(strict_types=1);

namespace App\Tests\Service\Image;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Image\OriginCookies;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OriginCookiesTest extends TestCase
{
    use NoEgressProxy;

    private const string IMAGE = 'https://www.oxmoxhh.de/wp-content/uploads/2026/09/cover-413x450.png';

    /** @var list<string> */
    private array $requested = [];

    /** @var array<mixed> */
    private array $sentOptions = [];

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
    }

    /** @param list<MockResponse> $responses */
    private function originCookies(array $responses): OriginCookies
    {
        $client = new MockHttpClient(function (
            string $method,
            string $url,
            array $options,
        ) use (&$responses): MockResponse {
            $this->requested[] = $url;
            $this->sentOptions = $options;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new OriginCookies(
            FetchWiring::redirectFollower(
                $client,
                $this->noEgressProxy(),
                new UrlGuard(new StaticDnsResolver([
                    'www.oxmoxhh.de' => ['85.13.150.207'],
                    'oxmoxhh.de' => ['85.13.150.207'],
                    'plain.example' => ['93.184.216.35'],
                    'elsewhere.example' => ['93.184.216.34'],
                ]), new IpValidator()),
            ),
            new ArrayAdapter(clock: $this->clock),
            'TestAgent/1.0',
        );
    }

    private static function homepage(string ...$setCookies): MockResponse
    {
        return new MockResponse('<html lang="de">home</html>', [
            'http_code' => 200,
            'response_headers' => array_map(
                static fn (string $cookie): string => 'Set-Cookie: ' . $cookie,
                $setCookies,
            ),
        ]);
    }

    public function testVisitsTheImageHostsHomepageAndReturnsItsCookies(): void
    {
        $cookies = $this->originCookies([self::homepage('conz_bild=1; Path=/; Secure', 'visit=7f3; HttpOnly')]);

        self::assertSame('conz_bild=1; visit=7f3', $cookies->for(self::IMAGE));
        self::assertSame(['https://www.oxmoxhh.de/'], $this->requested);
    }

    public function testRemembersTheAnswerPerHost(): void
    {
        $cookies = $this->originCookies([self::homepage('conz_bild=1'), self::homepage('conz_bild=2')]);

        $cookies->for(self::IMAGE);
        $second = $cookies->for('https://www.oxmoxhh.de/wp-content/uploads/2026/09/other.png');

        self::assertSame('conz_bild=1', $second);
        self::assertCount(1, $this->requested);
    }

    public function testKeepsEachHostsCookiesSeparate(): void
    {
        $cookies = $this->originCookies([self::homepage('a=1'), self::homepage()]);

        self::assertSame('a=1', $cookies->for(self::IMAGE));
        self::assertSame('', $cookies->for('https://plain.example/pic.png'));
    }

    public function testFollowsARedirectOnTheSameHostFamily(): void
    {
        $cookies = $this->originCookies([
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://oxmoxhh.de/']]),
            self::homepage('conz_bild=1'),
        ]);

        self::assertSame('conz_bild=1', $cookies->for(self::IMAGE));
    }

    public function testIgnoresCookiesFromAnotherHost(): void
    {
        $cookies = $this->originCookies([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://elsewhere.example/']]),
            self::homepage('tracker=9'),
        ]);

        self::assertSame('', $cookies->for(self::IMAGE));
    }

    public function testAFailedVisitIsNoCookies(): void
    {
        $cookies = $this->originCookies([
            new MockResponse('', ['http_code' => 503, 'response_headers' => ['Set-Cookie: err=1']]),
        ]);

        self::assertSame('', $cookies->for(self::IMAGE));
    }

    public function testABlockedVisitIsNoCookies(): void
    {
        $cookies = $this->originCookies([]);

        self::assertSame('', $cookies->for('https://unresolvable.example/x.png'));
    }

    public function testVisitsTheHostAgainAfterAnHour(): void
    {
        $cookies = $this->originCookies([self::homepage('conz_bild=1'), self::homepage('conz_bild=2')]);

        $cookies->for(self::IMAGE);
        $this->clock->sleep(3601);

        self::assertSame('conz_bild=2', $cookies->for(self::IMAGE));
    }

    public function testAsksForTheHomepageAsABrowserWould(): void
    {
        $cookies = $this->originCookies([self::homepage()]);

        $cookies->for(self::IMAGE);

        $headers = \is_array($this->sentOptions['headers'] ?? null) ? $this->sentOptions['headers'] : [];
        self::assertContains('Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8', $headers);
        self::assertContains('Accept-Encoding: identity', $headers);
        self::assertContains('User-Agent: TestAgent/1.0', $headers);
    }

    public function testGivesTheVisitTwiceTheIdleTimeoutInAll(): void
    {
        $cookies = $this->originCookies([self::homepage()]);

        $cookies->for(self::IMAGE);

        self::assertSame(10.0, $this->sentOptions['timeout'] ?? null);
        self::assertSame(20.0, $this->sentOptions['max_duration'] ?? null);
    }

    public function testAnOversizedHomepageIsNoCookies(): void
    {
        $cookies = $this->originCookies([
            new MockResponse(str_repeat('x', 5_000_001), [
                'http_code' => 200,
                'response_headers' => ['Set-Cookie: big=1'],
            ]),
        ]);

        self::assertSame('', $cookies->for(self::IMAGE));
    }
}
