<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Service\Image\Exception\ImageRefusedException;
use App\Service\Image\Exception\InvalidImageUrlException;
use App\Service\Image\ImageProxy\ImageProxyInterface;
use App\Service\Image\Model\ProxiedImageModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StubImageProxy;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ImageProxyControllerTest extends ApiTestCase
{
    private const string IMAGE = 'https://www.oxmoxhh.de/wp-content/uploads/2026/09/cover-413x450.png';

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    /** @return array<string, string> */
    private function bearer(string $email): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($this->factory()->create($email))];
    }

    private function install(ProxiedImageModel|\Throwable $answer): StubImageProxy
    {
        $proxy = new StubImageProxy($answer);
        self::getContainer()->set(ImageProxyInterface::class, $proxy);

        return $proxy;
    }

    public function testServesTheProxiedImage(): void
    {
        $client = self::createClient();
        $headers = $this->bearer('image-proxy-ok@example.com');
        $proxy = $this->install(new ProxiedImageModel('png-bytes', 'image/png'));

        $client->request('GET', '/api/image-proxy', ['url' => self::IMAGE], [], $headers);

        self::assertResponseIsSuccessful();
        self::assertSame('png-bytes', $client->getResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame([self::IMAGE], $proxy->fetched);
    }

    public function testAnUnavailableImageIsA404Problem(): void
    {
        $client = self::createClient();
        $headers = $this->bearer('image-proxy-refused@example.com');
        $this->install(new ImageRefusedException(self::IMAGE . ': HTTP 403'));

        $client->request('GET', '/api/image-proxy', ['url' => self::IMAGE], [], $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
    }

    public function testABadUrlIsA400Problem(): void
    {
        $client = self::createClient();
        $headers = $this->bearer('image-proxy-bad@example.com');
        $this->install(new InvalidImageUrlException('Not an http(s) URL: file:///etc/passwd'));

        $client->request('GET', '/api/image-proxy', ['url' => 'file:///etc/passwd'], [], $headers);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRequiresABearer(): void
    {
        $client = self::createClient();
        $proxy = $this->install(new ProxiedImageModel('png-bytes', 'image/png'));

        $client->request('GET', '/api/image-proxy', ['url' => self::IMAGE]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame([], $proxy->fetched);
    }

    public function testRateLimitsPerUser(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $headers = $this->bearer('image-proxy-limit@example.com');
        $this->install(new ProxiedImageModel('png-bytes', 'image/png'));

        for ($request = 0; $request < 300; $request++) {
            $client->request('GET', '/api/image-proxy', ['url' => self::IMAGE], [], $headers);
        }
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/image-proxy', ['url' => self::IMAGE], [], $headers);
        self::assertResponseStatusCodeSame(429);
    }
}
