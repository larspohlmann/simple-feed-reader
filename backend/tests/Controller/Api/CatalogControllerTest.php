<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures are built in-test on purpose: tests/bootstrap.php creates the schema
 * from ORM metadata, so no migration ever runs and the catalog tables are EMPTY
 * here until something imports one. A test written against the shipped catalog
 * would depend on an import having run, which no test fixture guarantees.
 */
final class CatalogControllerTest extends WebTestCase
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

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/catalog');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCategoriesAndFeedsComeBackInOrderWithoutDisabledRows(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('catalog@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $technology = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $technology->setPosition(0);
        $science = new CatalogCategory('science', 'Science', 'science', '#14b8a6');
        $science->setPosition(1);

        $verge = new CatalogFeed($technology, 'The Verge', 'https://www.theverge.com/rss/index.xml');
        $verge->setDescription('Tech, science and culture');
        $verge->setSiteUrl('https://www.theverge.com');
        $retired = new CatalogFeed($technology, 'Retired', 'https://example.com/gone.xml');
        $retired->setEnabled(false);
        $quanta = new CatalogFeed($science, 'Quanta Magazine', 'https://api.quantamagazine.org/feed/');

        foreach ([$technology, $science, $verge, $retired, $quanta] as $row) {
            $em->persist($row);
        }
        $em->flush();

        $client->request('GET', '/api/catalog', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['categories']);
        self::assertIsArray($body['categories'][0]);
        self::assertIsArray($body['categories'][0]['feeds']);
        self::assertIsArray($body['categories'][0]['feeds'][0]);

        self::assertSame(['Technology', 'Science'], array_column($body['categories'], 'name'));
        self::assertSame('#3b82f6', $body['categories'][0]['color']);
        self::assertSame(['The Verge'], array_column($body['categories'][0]['feeds'], 'title'));
        self::assertFalse($body['categories'][0]['feeds'][0]['subscribed']);
        self::assertSame(
            '/api/catalog/feeds/' . $verge->getId() . '/favicon',
            $body['categories'][0]['feeds'][0]['faviconUrl'],
        );
    }

    public function testAFeedTheUserAlreadySubscribesToIsMarkedSubscribed(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('subscribed@example.com');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        $category = new CatalogCategory('technology', 'Technology', 'memory', '#3b82f6');
        $catalogFeed = new CatalogFeed($category, 'The Verge', 'https://www.theverge.com/rss/index.xml');

        $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['email' => 'subscribed@example.com']);
        self::assertNotNull($user);

        $feed = new Feed('https://www.theverge.com/rss/index.xml');
        $subscription = new Subscription($user, $feed, $clock->now());

        foreach ([$category, $catalogFeed, $feed, $subscription] as $row) {
            $em->persist($row);
        }
        $em->flush();

        $client->request('GET', '/api/catalog', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['categories']);
        self::assertIsArray($body['categories'][0]);
        self::assertIsArray($body['categories'][0]['feeds']);
        self::assertIsArray($body['categories'][0]['feeds'][0]);
        self::assertTrue($body['categories'][0]['feeds'][0]['subscribed']);
    }
}
