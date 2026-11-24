<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class OpmlControllerTest extends ApiTestCase
{
    public function testExportRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/opml/export');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
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

    public function testImportSubscribesTheListedFeedsUnderTheParent(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('opml-import@example.com');

        $this->postImport($client, $headers, [
            'opml' => $this->fixture(),
            'parentTagName' => 'From Feedly',
            'feedUrls' => ['https://www.heise.de/rss/heise.rdf'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(1, $this->payload($client)['imported']);
        $entityManager = $this->entityManager();
        self::assertCount(1, $entityManager->getRepository(Subscription::class)->findBy(['user' => $user]));
        self::assertNotNull(
            $entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'From Feedly']),
        );
    }

    public function testImportWithoutFeedsIsRejected(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-no-feeds@example.com')), [
            'opml' => $this->fixture(),
            'feedUrls' => [],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testImportWithoutAFileIsRejected(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-no-file@example.com')), [
            'feedUrls' => ['https://www.heise.de/rss/heise.rdf'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testImportRejectsATagIdThatIsNotANumber(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-bad-tag@example.com')), [
            'opml' => $this->fixture(),
            'feedUrls' => ['https://www.heise.de/rss/heise.rdf'],
            'tagIdsByFeedUrl' => ['u' => ['x']],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testImportRejectsOversizedBody(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-big@example.com')), [
            'opml' => str_repeat('a', 1_048_577),
            'feedUrls' => ['https://www.heise.de/rss/heise.rdf'],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_opml', $this->payload($client)['type']);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/opml/subscriptions.opml');
    }

    /**
     * @param array{HTTP_AUTHORIZATION: string} $headers
     * @param array<string, mixed>               $body
     */
    private function postImport(KernelBrowser $client, array $headers, array $body): void
    {
        $client->request(
            'POST',
            '/api/opml/import',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
