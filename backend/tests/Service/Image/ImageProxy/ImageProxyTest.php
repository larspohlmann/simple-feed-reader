<?php

declare(strict_types=1);

namespace App\Tests\Service\Image\ImageProxy;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Image\Exception\ImageRefusedException;
use App\Service\Image\Exception\ImageUnavailableException;
use App\Service\Image\Exception\InvalidImageUrlException;
use App\Service\Image\ImageDownloader;
use App\Service\Image\ImageProxy\ImageProxy;
use App\Service\Image\OriginCookies;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\NoEgressProxy;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImageProxyTest extends TestCase
{
    use NoEgressProxy;

    private const string IMAGE = 'https://www.oxmoxhh.de/wp-content/uploads/2026/09/cover-413x450.png';

    /** @var list<string> "url cookie" per request */
    private array $requests = [];

    /** @return list<string> */
    private static function headerLines(mixed $headers): array
    {
        return \is_array($headers) ? array_values(array_filter($headers, \is_string(...))) : [];
    }

    /** A host that serves the image only with the cookie its homepage sets, like oxmoxhh.de. */
    private function proxy(string $homepageCookie = 'conz_bild=1'): ImageProxy
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($homepageCookie) {
            $cookie = '';
            foreach (self::headerLines($options['headers'] ?? null) as $header) {
                $cookie = str_starts_with($header, 'Cookie: ') ? substr($header, 8) : $cookie;
            }
            $this->requests[] = trim($url . ' ' . $cookie);

            if ($url === 'https://www.oxmoxhh.de/') {
                $headers = $homepageCookie === '' ? [] : ['Set-Cookie: ' . $homepageCookie . '; Path=/; Secure'];

                return new MockResponse('<html></html>', ['http_code' => 200, 'response_headers' => $headers]);
            }

            return $cookie === 'conz_bild=1'
                ? new MockResponse('png-bytes', ['http_code' => 200, 'response_headers' => ['Content-Type: image/png']])
                : new MockResponse('Forbidden', ['http_code' => 403]);
        });
        $redirects = FetchWiring::redirectFollower(
            $client,
            $this->noEgressProxy(),
            new UrlGuard(new StaticDnsResolver(['www.oxmoxhh.de' => ['85.13.150.207']]), new IpValidator()),
        );

        return new ImageProxy(
            new ImageDownloader($redirects, 'TestAgent/1.0'),
            new OriginCookies($redirects, new ArrayAdapter(), 'TestAgent/1.0'),
        );
    }

    public function testRetriesARefusedImageWithTheCookiesTheHomepageSets(): void
    {
        $image = $this->proxy()->fetch(self::IMAGE);

        self::assertSame('png-bytes', $image->bytes);
        self::assertSame([
            self::IMAGE,
            'https://www.oxmoxhh.de/',
            self::IMAGE . ' conz_bild=1',
        ], $this->requests);
    }

    public function testARefusalStandsWhenTheHomepageSetsNoCookie(): void
    {
        $proxy = $this->proxy(homepageCookie: '');

        try {
            $proxy->fetch(self::IMAGE);
            self::fail('A refused image with no cookies to offer must throw.');
        } catch (ImageRefusedException) {
            self::assertCount(2, $this->requests);
        }
    }

    public function testARefusalWithTheWrongCookieStaysUnavailable(): void
    {
        $this->expectException(ImageUnavailableException::class);
        $this->proxy(homepageCookie: 'conz_bild=0')->fetch(self::IMAGE);
    }

    public function testRejectsANonHttpUrlWithoutAnyRequest(): void
    {
        try {
            $this->proxy()->fetch('file:///etc/passwd');
            self::fail('A non-HTTP URL must throw.');
        } catch (InvalidImageUrlException) {
            self::assertSame([], $this->requests);
        }
    }
}
