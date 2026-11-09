<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdminCatalogControllerTest extends ApiTestCase
{
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

    public function testAdminCanCreateAFeed(): void
    {
        $client = self::createClient();
        $headers = $this->jsonBearerFor($this->factory()->create('admin2@example.com', roles: ['ROLE_ADMIN']));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $entityManager->persist($category);
        $entityManager->flush();

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
        self::assertNotNull(
            $entityManager->getRepository(CatalogFeed::class)->findOneBy(['title' => 'Quanta Magazine']),
        );
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
