<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class OpmlControllerTest extends ApiTestCase
{
    public function testExportRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/opml/export');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExportReturnsOpmlAttachment(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('opml-export@example.com');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entityManager->flush();

        $client->request('GET', '/api/opml/export', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/x-opml', (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('xmlUrl="https://example.com/feed.xml"', $body);
    }

    public function testImportCreatesSubscriptions(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('opml-import@example.com'));
        $opml = (string) file_get_contents(__DIR__ . '/../../Fixtures/opml/subscriptions.opml');

        $server = $headers + ['CONTENT_TYPE' => 'text/x-opml'];
        $client->request('POST', '/api/opml/import', server: $server, content: $opml);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(3, $body['imported']);

        $client->request('GET', '/api/subscriptions', server: $headers);
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertIsArray($list['subscriptions']);
        self::assertCount(3, $list['subscriptions']);
    }

    public function testImportRejectsEmptyBody(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('opml-empty@example.com'));
        $client->request('POST', '/api/opml/import', server: $headers + ['CONTENT_TYPE' => 'text/x-opml'], content: '');
        self::assertResponseStatusCodeSame(422);
    }

    public function testImportRejectsOversizedBody(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('opml-big@example.com'));
        $server = $headers + ['CONTENT_TYPE' => 'text/x-opml'];
        $client->request('POST', '/api/opml/import', server: $server, content: str_repeat('a', 1_048_577));
        self::assertResponseStatusCodeSame(422);
    }
}
