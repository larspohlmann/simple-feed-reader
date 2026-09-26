<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CatalogFaviconControllerTest extends WebTestCase
{
    /** @return array<string, string> */
    private function authHeader(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = (new UserFactory($em, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)];
    }

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
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $em->persist($feed->getCategory());
        $em->persist($feed);
        $em->flush();

        return $feed;
    }

    public function testServesTheCachedBytesWithAnEtag(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('icons@example.com');
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
        $headers = $this->authHeader('placeholder@example.com');
        $feed = $this->persistFeedWithoutIcon();

        $client->request('GET', '/api/catalog/feeds/' . $feed->getId() . '/favicon', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/svg+xml');
        self::assertStringContainsString('>T<', (string) $client->getResponse()->getContent());
    }

    public function testAnUnknownFeedIs404(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('missing@example.com');

        $client->request('GET', '/api/catalog/feeds/999999/favicon', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"detail":"No such feed."', (string) $client->getResponse()->getContent());
    }

    /**
     * The picker renders these with plain <img> tags, which cannot carry the
     * bearer JWT, so the endpoint MUST be reachable anonymously. It holds no user
     * data — the subscribed flags live on the authenticated /api/catalog list —
     * so serving the bytes without auth is safe. Guards the security.yaml rule
     * against a regression that would render every favicon as a broken image.
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
