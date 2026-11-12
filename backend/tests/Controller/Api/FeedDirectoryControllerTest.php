<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class FeedDirectoryControllerTest extends ApiTestCase
{
    public function testTheIndexListsTheCountries(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-index@example.test');

        $client->request('GET', '/api/feed-directory/index');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $index = json_decode((string) $client->getInternalResponse()->getContent(), true);
        self::assertIsArray($index);
        self::assertIsArray($index['countries']);
        self::assertSame(['AT', 'DE'], array_column($index['countries'], 'code'));
    }

    public function testACountryFileIsStreamedGzipped(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-country@example.test');

        $client->request('GET', '/api/feed-directory/countries/DE');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Encoding', 'gzip');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHasHeader('ETag');
        $body = gzdecode((string) $client->getInternalResponse()->getContent());
        self::assertIsString($body);
        self::assertStringStartsWith('{"country":"DE"', $body);
    }

    public function testAMatchingETagAnswersNotModified(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-etag@example.test');
        $client->request('GET', '/api/feed-directory/countries/DE');
        $etag = (string) $client->getResponse()->headers->get('ETag');

        $client->request('GET', '/api/feed-directory/countries/DE', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
    }

    public function testAnUnknownCountryIsNotFound(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-unknown@example.test');

        $client->request('GET', '/api/feed-directory/countries/FR');

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/feed-directory/index');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
