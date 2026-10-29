<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class CatalogFaviconControllerTest extends ApiTestCase
{
    private function persistFeedWithIcon(): CatalogFeed
    {
        $feed = $this->newFeed();
        $feed->storeFavicon(
            'https://www.theverge.com/favicon.ico',
            'PNGBYTES',
            'image/png',
            new \DateTimeImmutable('2026-07-26 10:00:00'),
        );

        return $this->persisted($feed);
    }

    private function persistFeedWithoutIcon(): CatalogFeed
    {
        return $this->persisted($this->newFeed());
    }

    private function newFeed(): CatalogFeed
    {
        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');

        return new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');
    }

    private function persisted(CatalogFeed $feed): CatalogFeed
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $entityManager->persist($feed->getCategory());
        $entityManager->persist($feed);
        $entityManager->flush();

        return $feed;
    }

    public function testServesTheCachedBytesWithAnEtag(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('icons@example.com'));
        $feed = $this->persistFeedWithIcon();

        $client->request('GET', '/api/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertSame('PNGBYTES', $client->getResponse()->getContent());
        self::assertNotNull($client->getResponse()->getEtag());
    }

    public function testServesTheMonogramWhenNoIconIsCached(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('placeholder@example.com'));
        $feed = $this->persistFeedWithoutIcon();

        $client->request('GET', '/api/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('>T<', (string) $client->getResponse()->getContent());
    }

    public function testAnUnknownFeedIs404(): void
    {
        $client = self::createClient();
        $headers = $this->bearerFor($this->factory()->create('missing@example.com'));

        $client->request('GET', '/api/catalog/feeds/999999/favicon', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such feed."', (string) $client->getResponse()->getContent());
    }

    /**
     * Plain <img> tags cannot carry the bearer JWT, so the favicon must be public; it holds no user data. Guards
     * security.yaml's PUBLIC_ACCESS rule for it.
     */
    public function testTheFaviconIsPubliclyReachableWithoutAuthentication(): void
    {
        $client = self::createClient();
        $feed = $this->persistFeedWithoutIcon();

        $client->request('GET', '/api/catalog/feeds/' . $feed->getId() . '/favicon');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
    }
}
