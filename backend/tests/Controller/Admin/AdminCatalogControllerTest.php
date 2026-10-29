<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloader;
use App\Service\Image\GuardedImageDownloader\GuardedImageDownloaderInterface;
use App\Service\Image\Exception\GuardedImageUnavailableException;
use App\Service\Fetch\FaviconResolver\FaviconResolver;
use App\Service\Fetch\FaviconResolver\FaviconResolverInterface;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminCatalogControllerTest extends ApiTestCase
{
    /**
     * Resolution returns a canned URL and every download fails, so the warm and refresh endpoints run their real wiring
     * without the network. The doubles replace the concrete service ids the interfaces alias to.
     */
    private function stubFaviconServicesToFail(): void
    {
        $downloader = $this->createStub(GuardedImageDownloaderInterface::class);
        $downloader->method('download')->willThrowException(new GuardedImageUnavailableException('offline'));
        self::getContainer()->set(GuardedImageDownloader::class, $downloader);

        $resolver = $this->createStub(FaviconResolverInterface::class);
        $resolver->method('resolveAll')->willReturnCallback(
            static fn (array $bases): array => array_map(
                static fn (): string => 'https://example.com/favicon.ico',
                $bases,
            ),
        );
        self::getContainer()->set(FaviconResolver::class, $resolver);
    }

    private function reloadedCategory(int $id): ?CatalogCategory
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->clear();

        return $entityManager->find(CatalogCategory::class, $id);
    }

    public function testANonAdminIsRefused(): void
    {
        $client = self::createClient();
        $client->request(
            'GET',
            '/api/admin/catalog',
            server: $this->jsonBearerFor($this->factory()->create('plain@example.com')),
        );
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanCreateUpdateAndDeleteACategory(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('admin@example.com', roles: ['ROLE_ADMIN']));

        $client->request(
            'POST',
            '/api/admin/catalog/categories',
            server: $headers,
            content: json_encode(
                [
                    'key' => 'technology',
                    'name' => 'Technology',
                    'icon' => 'memory',
                    'color' => '#3b82f6',
                    'enabled' => true,
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload($client);
        $category = $created['category'];
        self::assertIsArray($category);
        $id = $category['id'];
        self::assertIsInt($id);

        $client->request(
            'PATCH',
            '/api/admin/catalog/categories/' . $id,
            server: $headers,
            content: json_encode(
                ['key' => 'technology', 'name' => 'Tech', 'icon' => 'memory', 'color' => '#3b82f6', 'enabled' => false],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseIsSuccessful();
        $updated = $this->reloadedCategory($id);
        self::assertNotNull($updated);
        self::assertSame('Tech', $updated->getName());
        self::assertFalse($updated->isEnabled());

        $client->request('DELETE', '/api/admin/catalog/categories/' . $id, server: $headers);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->reloadedCategory($id));
    }

    public function testAdminCanCreateAFeedAndRefreshItsFavicon(): void
    {
        $client = self::createClient();
        // Keep one container across both requests: the favicon stub set below has
        // to survive from here to the refresh call. KernelBrowser otherwise reboots
        // the kernel before every request and would discard it.
        $client->disableReboot();
        $headers = $this->jsonBearerFor($this->factory()->create('admin2@example.com', roles: ['ROLE_ADMIN']));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $entityManager->persist($category);
        $entityManager->flush();

        // Stub BEFORE the first admin request: AdminCatalogController eagerly builds
        // CatalogFaviconWarmer, which eagerly builds the downloader — once a request has
        // constructed it, the test container refuses to replace it.
        $this->stubFaviconServicesToFail();

        $client->request(
            'POST',
            '/api/admin/catalog/feeds',
            server: $headers,
            content: json_encode([
                'categoryId' => $category->getId(),
                'title' => 'Quanta Magazine',
                'url' => 'https://api.quantamagazine.org/feed/',
                'siteUrl' => 'https://www.quantamagazine.org',
                'description' => 'Maths and physics reporting',
                'enabled' => true,
            ], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);

        $feed = $entityManager->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Quanta Magazine']);
        self::assertNotNull($feed);

        // The refresh action must answer even when the download fails — a dead
        // icon is a recorded failure, not a 500. The failing stub is already in
        // place (set above, before the warmer was constructed).
        $client->request('POST', '/api/admin/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);
        self::assertResponseIsSuccessful();
    }

    public function testWarmingReportsASliceAndWhatIsLeft(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('warm@example.com', roles: ['ROLE_ADMIN']));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $feed = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $entityManager->persist($category);
        $entityManager->persist($feed);
        $entityManager->flush();

        // The favicon services are stubbed: this asserts the endpoint's contract,
        // not that the internet is reachable from CI.
        $this->stubFaviconServicesToFail();

        $client->request('POST', '/api/admin/catalog/favicons/warm', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);

        self::assertSame(0, $body['warmed']);
        self::assertSame(1, $body['failed']);
        self::assertArrayHasKey('remaining', $body);
    }

    public function testReorderingRewritesPositions(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('admin3@example.com', roles: ['ROLE_ADMIN']));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $first = new CatalogCategory('a', 'A', 'memory', '#111111');
        $first->setPosition(0);
        $second = new CatalogCategory('b', 'B', 'memory', '#222222');
        $second->setPosition(1);
        $entityManager->persist($first);
        $entityManager->persist($second);
        $entityManager->flush();

        $client->request(
            'PATCH',
            '/api/admin/catalog/categories/reorder',
            server: $headers,
            content: json_encode(['ids' => [$second->getId(), $first->getId()]], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $entityManager->clear();
        $reloaded = $entityManager->getRepository(CatalogCategory::class)->findOneBy(['key' => 'b']);
        self::assertNotNull($reloaded);
        self::assertSame(0, $reloaded->getPosition());
    }
}
