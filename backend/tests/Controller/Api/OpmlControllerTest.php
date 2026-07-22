<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class OpmlControllerTest extends WebTestCase
{
    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    public function testExportRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/opml/export');
        self::assertResponseStatusCodeSame(401);
    }

    public function testExportReturnsOpmlAttachment(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('opml-export@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = new Feed('https://example.com/feed.xml');
        $feed->setTitle('Example');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $em->flush();

        $client->request('GET', '/api/opml/export', server: $headers);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/x-opml', (string) $client->getResponse()->headers->get('Content-Type'));
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('xmlUrl="https://example.com/feed.xml"', $body);
    }
}
