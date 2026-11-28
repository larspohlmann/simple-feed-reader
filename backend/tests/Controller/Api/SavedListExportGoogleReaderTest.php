<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryAuthor;
use App\Entity\EntryLabel;
use App\Entity\EntryState;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\Label;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryQuery;
use App\Service\Library\SavedPosts\Support\SavedFeedUrl;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NamesLabels;
use App\Tests\Support\StateMarks;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * @phpstan-type StarredItem array{
 *     id: string,
 *     title: string,
 *     published: int,
 *     updated: int,
 *     author?: string,
 *     alternate?: list<array{href: string, type: string}>,
 *     content: array{direction: string, content: string},
 *     origin: array<string, string>,
 *     categories: list<string>,
 * }
 * @phpstan-type StarredDocument array{id: string, title: string, updated: int, items: list<StarredItem>}
 */
final class SavedListExportGoogleReaderTest extends ApiTestCase
{
    use NamesLabels;

    private const string STARRED = 'user/-/state/com.google/starred';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testItDownloadsAParseableStarredFile(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-parse@example.com');
        $this->keptEntry($user, $this->subscribe($user), 'One', 'https://export.example/1');

        $document = $this->downloadDocument($headers, 'view=kept');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="simplefeedreader-kept-\d{8}-starred\.json"$/',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertSame(self::STARRED, $document['id']);
        self::assertSame('Kept', $document['title']);
        self::assertGreaterThan(0, $document['updated']);
        self::assertCount(1, $document['items']);
    }

    public function testAnItemCarriesWhatFreshRssReads(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-item@example.com');
        $feed = $this->subscribe($user);
        $feed->setTitle('Site title');
        $feed->setSiteUrl('https://export.example/');
        $entry = $this->keptEntry($user, $feed, 'Entry One', 'https://export.example/1');
        $entry->setAuthor(new EntryAuthor('Jane'));
        $entry->recordText(new EntryText(null, '<p>body</p>'));
        $this->label($user, $entry, 'Beta');
        $this->label($user, $entry, 'Alpha');

        $items = $this->downloadDocument($headers, 'view=kept')['items'];

        $timestamp = (new \DateTimeImmutable('2026-07-01T00:00:00Z'))->getTimestamp();
        self::assertSame([
            'id' => $entry->getGuid(),
            'title' => 'Entry One',
            'published' => $timestamp,
            'updated' => $timestamp,
            'author' => 'Jane',
            'alternate' => [['href' => 'https://export.example/1', 'type' => 'text/html']],
            'content' => ['direction' => 'ltr', 'content' => '<p>body</p>'],
            'origin' => [
                'streamId' => 'feed/' . $feed->getUrl(),
                'title' => 'Site title',
                'htmlUrl' => 'https://export.example/',
            ],
            'categories' => [self::STARRED, 'user/-/label/Alpha', 'user/-/label/Beta'],
        ], $items[0]);
    }

    public function testAFavoritesExportIsStarredToo(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-fav@example.com');
        $entry = $this->entry($this->subscribe($user), 'Fav', 'https://export.example/f');
        $state = new EntryState($user, $entry);
        StateMarks::markFavorite($state);
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();

        $items = $this->downloadDocument($headers, 'view=favorites')['items'];

        self::assertCount(1, $items);
        self::assertContains(self::STARRED, $items[0]['categories']);
    }

    public function testASavedFeedEntryHasATitleOnlyOrigin(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-saved@example.com');
        $feed = $this->subscribeTo($user, SavedFeedUrl::buildUrl($user->requireId(), null));
        $feed->setTitle('Saved posts');
        $this->keptEntry($user, $feed, 'Rescued', 'https://export.example/r');

        $body = $this->download($headers, 'view=kept');

        self::assertSame(['title' => 'Saved posts'], $this->decode($body)['items'][0]['origin']);
        self::assertStringNotContainsString('saved:', $body);
    }

    public function testASocialFeedEntryHasATitleOnlyOrigin(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-social@example.com');
        $feed = $this->subscribeTo($user, 'social:bluesky:1:home');
        $feed->setTitle('Home timeline');
        $this->keptEntry($user, $feed, 'Post', 'https://bsky.example/p');

        $body = $this->download($headers, 'view=kept');

        self::assertSame(['title' => 'Home timeline'], $this->decode($body)['items'][0]['origin']);
        self::assertStringNotContainsString('social:', $body);
    }

    public function testInvalidUtf8AndControlCharactersStillParse(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-utf8@example.com');
        $entry = $this->keptEntry($user, $this->subscribe($user), 'Bad', 'https://export.example/b');
        $entry->recordText(new EntryText(null, "ok \xB1 \x01 end"));
        $this->entityManager()->flush();

        $items = $this->downloadDocument($headers, 'view=kept')['items'];

        self::assertStringStartsWith('ok ', $items[0]['content']['content']);
        self::assertStringEndsWith(' end', $items[0]['content']['content']);
    }

    public function testAnEntryWithoutAUrlHasNoAlternate(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-nourl@example.com');
        $this->keptEntry($user, $this->subscribe($user), 'No url', null);

        $items = $this->downloadDocument($headers, 'view=kept')['items'];

        self::assertCount(1, $items);
        self::assertArrayNotHasKey('alternate', $items[0]);
    }

    public function testAnEmptyListHasNoItems(): void
    {
        [$headers] = $this->bearerAndUser('export-gr-empty@example.com');

        self::assertSame([], $this->downloadDocument($headers, 'view=favorites')['items']);
    }

    public function testMarkupInALabelSurvivesAsItsCategory(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-markup@example.com');
        $entry = $this->keptEntry($user, $this->subscribe($user), 'Marked', 'https://export.example/m');
        $label = $this->label($user, $entry, 'A, "B" & <C>');

        $document = $this->downloadDocument($headers, 'view=kept&label=' . $label->requireId());

        self::assertContains('user/-/label/A, "B" & <C>', $document['items'][0]['categories']);
    }

    public function testMoreThanOneBatchIsOneValidDocument(): void
    {
        [$headers, $user] = $this->bearerAndUser('export-gr-batches@example.com');
        $feed = $this->subscribe($user);
        $total = EntryQuery::MAX_LIMIT + 1;
        for ($index = 0; $index < $total; ++$index) {
            $this->keptEntry($user, $feed, 'Entry ' . $index, 'https://export.example/e' . $index, flush: false);
        }
        $this->entityManager()->flush();

        $items = $this->downloadDocument($headers, 'view=kept')['items'];

        self::assertCount($total, $items);
        self::assertCount($total, array_unique(array_column($items, 'id')));
    }

    /**
     * @param array<string, string> $headers
     *
     * @return StarredDocument
     */
    private function downloadDocument(array $headers, string $queryString): array
    {
        return $this->decode($this->download($headers, $queryString));
    }

    /** @return StarredDocument */
    private function decode(string $body): array
    {
        $document = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        /** @var StarredDocument $document */
        return $document;
    }

    /** @param array<string, string> $headers */
    private function download(array $headers, string $queryString): string
    {
        $this->client->request('GET', '/api/exports/google-reader?' . $queryString, server: $headers);

        return (string) $this->client->getInternalResponse()->getContent();
    }

    private function subscribe(User $user): Feed
    {
        return $this->subscribeTo($user, 'https://export.example/' . uniqid('', true) . '.xml');
    }

    private function subscribeTo(User $user, string $url): Feed
    {
        $feed = new Feed($url);
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager()->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $title, ?string $url): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, uniqid($title, true), $url, $title, $moment, $moment);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    private function keptEntry(User $user, Feed $feed, string $title, ?string $url, bool $flush = true): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-01T00:00:00Z');
        $entry = new Entry($feed, uniqid($title, true), $url, $title, $moment, $moment);
        $this->entityManager()->persist($entry);
        $state = new EntryState($user, $entry);
        StateMarks::markKept($state);
        $this->entityManager()->persist($state);
        if ($flush) {
            $this->entityManager()->flush();
        }

        return $entry;
    }

    private function label(User $user, Entry $entry, string $name): Label
    {
        $label = $this->labelNamed($user, $name);
        $this->entityManager()->persist(new EntryLabel($label, $entry));
        $this->entityManager()->flush();

        return $label;
    }
}
