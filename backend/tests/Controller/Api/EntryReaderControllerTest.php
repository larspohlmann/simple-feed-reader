<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Reader\ArticleExtractorInterface;
use App\Service\Reader\ExtractionResult;
use App\Tests\Support\FakeArticleExtractor;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EntryReaderControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        // The reader limiter counts in a FILESYSTEM pool that outlives the run,
        // so a prior case's spend must not bleed into this one and trip a 429.
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

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

    private function seedEntry(User $user, ?string $url): Entry
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));

        $entry = new Entry(
            $feed,
            'g-' . uniqid('', true),
            $url,
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setContentHtml('<p>The feed body.</p>');
        $entry->setImage('https://example.com/feed.jpg', 800, 450);
        $em->persist($entry);
        $em->flush();

        return $entry;
    }

    private function installFake(): FakeArticleExtractor
    {
        $fake = new FakeArticleExtractor();
        self::getContainer()->set(ArticleExtractorInterface::class, $fake);

        return $fake;
    }

    public function testOwnedEntryOkReturnsExtractedArticle(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-ok@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResult::ok(
            url: 'https://example.com/article',
            title: 'The Title',
            byline: 'A. Writer',
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: 'An excerpt.',
            imageCandidate: 'https://example.com/lead.jpg',
        ));
        $entry = $this->seedEntry($user, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('ok', $body['status']);
        self::assertSame('The Title', $body['title']);
        self::assertSame('<p>Body</p>', $body['contentHtml']);
        self::assertSame('A. Writer', $body['byline']);
        self::assertSame('Example', $body['siteName']);
        self::assertSame('An excerpt.', $body['excerpt']);
        self::assertSame(
            ['url' => 'https://example.com/lead.jpg', 'width' => null, 'height' => null],
            $body['readerHero'],
        );
        // The feed body carries no picture of its own, so the feed's own image
        // leads the original view.
        self::assertSame(
            ['url' => 'https://example.com/feed.jpg', 'width' => 800, 'height' => 450],
            $body['originalHero'],
        );
        self::assertArrayNotHasKey('leadImage', $body);
        self::assertSame('https://example.com/article', $body['url']);
        self::assertArrayHasKey('extractedAt', $body);
        self::assertSame(['https://example.com/article'], $fake->calls);
    }

    public function testOwnedEntryFetchFailureReturnsFailedStatus(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-fail@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResult::failed('https://example.com/article', 'fetch'));
        $entry = $this->seedEntry($user, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('failed', $body['status']);
        self::assertSame('fetch', $body['reason']);
        self::assertSame(['https://example.com/article'], $fake->calls);
        // A failed extraction is exactly when the feed's own picture is the only
        // one there is, so the original hero must still be resolved.
        self::assertNull($body['readerHero']);
        self::assertSame(
            ['url' => 'https://example.com/feed.jpg', 'width' => 800, 'height' => 450],
            $body['originalHero'],
        );
    }

    public function testOffTopicExtractionOfAFullFeedArticleFallsBackToTheFeed(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-mismatch@example.com');
        $fake = $this->installFake();
        // Readability grabbed page furniture, not the story (#654).
        $fake->willReturn(ExtractionResult::ok(
            url: 'https://example.com/article',
            title: 'The Title',
            byline: null,
            siteName: null,
            contentHtml: '<p>+++ dein shop gegen meerweh +++ neu im shop eingetroffen +++</p>',
            excerpt: null,
            imageCandidate: null,
        ));
        $entry = $this->seedEntry($user, 'https://example.com/article');
        $this->setFeedBody($entry, '<div>' . $this->fullFeedArticle() . '</div>');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('failed', $body['status']);
        self::assertSame('mismatch', $body['reason']);
        self::assertNull($body['readerHero']);
    }

    public function testExtractionThatReflectsTheFullFeedArticleStaysOk(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-reflects@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResult::ok(
            url: 'https://example.com/article',
            title: 'The Title',
            byline: null,
            siteName: null,
            // A clean extraction of the same page shares the feed's wording.
            contentHtml: '<article>' . $this->fullFeedArticle() . '</article>',
            excerpt: null,
            imageCandidate: null,
        ));
        $entry = $this->seedEntry($user, 'https://example.com/article');
        $this->setFeedBody($entry, '<div>' . $this->fullFeedArticle() . '</div>');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('ok', $body['status']);
    }

    private function setFeedBody(Entry $entry, string $html): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $entry->setContentHtml($html);
        $em->flush();
    }

    /** A distinct-worded feed body past the gate's substantial-feed bar. */
    private function fullFeedArticle(): string
    {
        return '<p>Gegen zwanzig Uhr fünfzig empfing die Rettungsleitstelle einen kaum '
            . 'verständlichen Notruf von einer polnischen Segelyacht nördlich von Sassnitz. '
            . 'Sechs Menschen, darunter drei Kinder, standen bereits bis zu den Schienbeinen '
            . 'im Wasser der Ostsee, während viel Seewasser in das zwölf Meter lange Boot '
            . 'eindrang und es langsam zu sinken drohte. Der Seenotrettungskreuzer erreichte '
            . 'den Havaristen nach wenigen Minuten, brachte eine leistungsstarke Lenzpumpe an '
            . 'Bord und stabilisierte die Lage. Anschließend nahm das Tochterboot die Yacht in '
            . 'Schlepp und brachte sie sicher in den Stadthafen von Sassnitz, wo die Feuerwehren '
            . 'die weiteren Sicherungsarbeiten übernahmen. Alle sechs Menschen an Bord blieben '
            . 'unverletzt, die Ursache des Wassereinbruchs blieb zunächst ungeklärt. Die '
            . 'Steilküste des Nationalparks Jasmund erschwerte die Verständigung über Seefunk '
            . 'erheblich, sodass die Besatzung ihre genaue Position erst nach mehreren Versuchen '
            . 'durchgeben konnte. Zum Zeitpunkt des Seenotfalls herrschten schwache Winde aus '
            . 'südwestlicher Richtung und eine vergleichsweise ruhige See.</p>';
    }

    public function testEntryWithoutUrlShortCircuitsWithoutCallingExtractor(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('reader-nourl@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntry($user, null);

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('failed', $body['status']);
        self::assertSame('no_url', $body['reason']);
        self::assertNull($body['url']);
        self::assertSame([], $fake->calls);
    }

    public function testEntryOfAnotherUserIs404AndDoesNotCallExtractor(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('reader-idor@example.com');
        [, $stranger] = $this->auth('reader-owner@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntry($stranger, 'https://example.com/article');

        $client->request('GET', '/api/entries/' . $entry->getId() . '/reader', server: $headers);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $fake->calls);
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/entries/1/reader');
        self::assertResponseStatusCodeSame(401);
    }
}
