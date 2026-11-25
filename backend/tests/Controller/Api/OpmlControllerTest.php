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

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }

    public function testImportWithoutAFileIsRejected(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-no-file@example.com')), [
            'feedUrls' => ['https://www.heise.de/rss/heise.rdf'],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_opml', $this->payload($client)['type']);
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

    public function testPreviewRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/opml/preview');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testPreviewListsTheFileWithoutSubscribing(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('opml-preview@example.com');

        $this->postPreview($client, $headers, ['opml' => $this->fixture()]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $body = $this->payload($client);
        self::assertIsInt($body['freeSlots']);
        self::assertIsArray($body['root']);
        self::assertCount(0, $this->entityManager()->getRepository(Subscription::class)->findBy(['user' => $user]));
    }

    public function testPreviewListsEachFeedsSiteUrl(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-site@example.com')), [
            'opml' => '<?xml version="1.0"?><opml version="2.0"><body>'
                . '<outline text="always beta" xmlUrl="https://alwaysbeta.de/feed/" htmlUrl="https://alwaysbeta.de/"/>'
                . '<outline text="bare" xmlUrl="https://bare.example/feed"/>'
                . '</body></opml>',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $root = $this->payload($client)['root'];
        self::assertIsArray($root);
        $feeds = $root['feeds'];
        self::assertIsArray($feeds);
        $siteUrls = [];
        foreach ($feeds as $feed) {
            self::assertIsArray($feed);
            $siteUrls[] = $feed['siteUrl'];
        }
        self::assertSame(['https://alwaysbeta.de/', null], $siteUrls);
    }

    public function testPreviewOfAnUnreadableFileIsAProblem(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-bad@example.com')), [
            'opml' => 'not xml',
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $type = $this->payload($client)['type'];
        self::assertIsString($type);
        self::assertStringEndsWith('invalid_opml', $type);
    }

    public function testPreviewWithoutAFileIsRejected(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-nofile@example.com')), []);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_opml', $this->payload($client)['type']);
    }

    public function testPreviewCarriesTheParentIntoTheDestinations(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-parent@example.com')), [
            'opml' => $this->fixture(),
            'parentTagName' => 'From Feedly',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $root = $this->payload($client)['root'];
        self::assertIsArray($root);
        self::assertSame(['From Feedly'], $root['destination']);
    }

    public function testPreviewCarriesAFolderRenameIntoTheDestinations(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-rename@example.com')), [
            'opml' => $this->techFolderOpml(),
            'folderNames' => ['0' => 'Gadgets'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $root = $this->payload($client)['root'];
        self::assertIsArray($root);
        $folders = $root['folders'];
        self::assertIsArray($folders);
        $folder = $folders[0];
        self::assertIsArray($folder);
        self::assertSame(['Gadgets'], $folder['destination']);
        self::assertSame('Tech', $folder['name']);
    }

    public function testPreviewTrimsAFolderRename(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-rename-trim@example.com')), [
            'opml' => $this->techFolderOpml(),
            'folderNames' => ['0' => '  Gadgets  '],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $root = $this->payload($client)['root'];
        self::assertIsArray($root);
        $folders = $root['folders'];
        self::assertIsArray($folders);
        $folder = $folders[0];
        self::assertIsArray($folder);
        self::assertSame(['Gadgets'], $folder['destination']);
    }

    public function testImportFilesTheFeedUnderTheRenamedFolder(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('opml-import-rename@example.com');

        $this->postImport($client, $headers, [
            'opml' => $this->techFolderOpml(),
            'feedUrls' => ['https://tech.example/feed'],
            'folderNames' => ['0' => 'Gadgets'],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $tags = $this->entityManager()->getRepository(Tag::class);
        self::assertNotNull($tags->findOneBy(['user' => $user, 'name' => 'Gadgets']));
        self::assertNull($tags->findOneBy(['user' => $user, 'name' => 'Tech']));
    }

    public function testABlankFolderNameIsRejected(): void
    {
        $client = self::createClient();

        $this->postPreview($client, $this->bearerFor($this->factory()->create('opml-rename-blank@example.com')), [
            'opml' => $this->techFolderOpml(),
            'folderNames' => ['0' => '  '],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testImportTrimsAFolderRename(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('opml-import-trim@example.com');

        $this->postImport($client, $headers, [
            'opml' => $this->techFolderOpml(),
            'feedUrls' => ['https://tech.example/feed'],
            'folderNames' => ['0' => '  Gadgets  '],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $tags = $this->entityManager()->getRepository(Tag::class)->findBy(['user' => $user]);
        self::assertSame(['Gadgets'], array_map(static fn (Tag $tag): string => $tag->getName(), $tags));
    }

    public function testImportWithABlankFolderNameIsRejected(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-import-blank@example.com')), [
            'opml' => $this->techFolderOpml(),
            'feedUrls' => ['https://tech.example/feed'],
            'folderNames' => ['0' => '  '],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testImportWithBothChoicesForOneFeedIsAProblem(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->bearerAndUser('opml-import-both@example.com');
        $tag = new Tag($user, 'Mine');
        $this->entityManager()->persist($tag);
        $this->entityManager()->flush();

        $this->postImport($client, $headers, [
            'opml' => $this->techFolderOpml(),
            'feedUrls' => ['https://tech.example/feed'],
            'tagIdsByFeedUrl' => ['https://tech.example/feed' => [$tag->requireId()]],
            'addedTagIdsByFeedUrl' => ['https://tech.example/feed' => [$tag->requireId()]],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('conflicting_tag_choice', $this->payload($client)['type']);
    }

    public function testAnOverlongFolderNameIsRejected(): void
    {
        $client = self::createClient();

        $this->postImport($client, $this->bearerFor($this->factory()->create('opml-rename-long@example.com')), [
            'opml' => $this->techFolderOpml(),
            'feedUrls' => ['https://tech.example/feed'],
            'folderNames' => ['0' => str_repeat('a', 101)],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function techFolderOpml(): string
    {
        return '<?xml version="1.0"?><opml version="2.0"><head/><body><outline text="Tech">'
            . '<outline type="rss" text="Tech Feed" xmlUrl="https://tech.example/feed"/>'
            . '</outline></body></opml>';
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

    /**
     * @param array{HTTP_AUTHORIZATION: string} $headers
     * @param array<string, mixed>               $body
     */
    private function postPreview(KernelBrowser $client, array $headers, array $body): void
    {
        $client->request(
            'POST',
            '/api/opml/preview',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
}
