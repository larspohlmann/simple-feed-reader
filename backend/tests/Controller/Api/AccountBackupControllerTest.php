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

final class AccountBackupControllerTest extends WebTestCase
{
    /** @return array{0: array<string, string>, 1: User} */
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

    public function testBackupRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/account/backup');
        self::assertResponseStatusCodeSame(401);
    }

    public function testBackupStreamsAGzipNdjsonAttachment(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('backup-download@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = new Feed('https://dl.example/feed.xml');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $em->flush();

        ob_start();
        $client->request('GET', '/api/account/backup', server: $headers);
        ob_get_clean();

        self::assertResponseIsSuccessful();
        self::assertSame('application/gzip', $client->getResponse()->headers->get('Content-Type'));
        self::assertNull($client->getResponse()->headers->get('Content-Encoding'));
        self::assertStringContainsString(
            'attachment; filename="account-backup-',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );

        // Get the streamed content from the server variable (set by the factory for testing)
        $content = $_SERVER['BACKUP_DOWNLOAD_CONTENT'] ?? '';
        self::assertIsString($content);
        $streamed = $content;

        $ndjson = gzdecode($streamed);
        self::assertIsString($ndjson);
        $lines = explode("\n", trim($ndjson));
        $first = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertSame('header', $first['kind']);
        $last = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($last);
        self::assertSame('footer', $last['kind']);
        self::assertIsArray($last['counts']);
        self::assertSame(1, $last['counts']['subscription']);
    }
}
