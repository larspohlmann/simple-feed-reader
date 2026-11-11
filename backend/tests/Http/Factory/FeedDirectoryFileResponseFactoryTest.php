<?php

declare(strict_types=1);

namespace App\Tests\Http\Factory;

use App\Http\Factory\FeedDirectoryFileResponseFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FeedDirectoryFileResponseFactoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'feed-directory-file');
        file_put_contents($this->path, '{"countries":[]}');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function testJsonIsServedAsPlainJsonCachedPrivatelyForADay(): void
    {
        $response = (new FeedDirectoryFileResponseFactory())->json(new Request(), $this->path);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertNull($response->headers->get('Content-Encoding'));
        self::assertNotNull($response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        self::assertSame(86400, $response->getMaxAge());
    }

    public function testGzippedJsonDeclaresItsEncodingAndIsCachedLikePlainJson(): void
    {
        $response = (new FeedDirectoryFileResponseFactory())->gzippedJson(new Request(), $this->path);

        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
        self::assertNotNull($response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame(86400, $response->getMaxAge());
    }

    public function testARepeatRequestWithTheCurrentEtagGetsNotModified(): void
    {
        $factory = new FeedDirectoryFileResponseFactory();
        $etag = (string) $factory->json(new Request(), $this->path)->getEtag();
        $revalidation = new Request();
        $revalidation->headers->set('If-None-Match', $etag);

        $response = $factory->json($revalidation, $this->path);

        self::assertSame(Response::HTTP_NOT_MODIFIED, $response->getStatusCode());
    }
}
