<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryLabel;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Label;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\NamesLabels;
use App\Tests\Support\StateMarks;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class EntryLabelScopeTest extends ApiTestCase
{
    use NamesLabels;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testALabelNarrowsTheFavorites(): void
    {
        [$headers, $work] = $this->seedThreeEntries('label-scope-favorites@example.com');

        self::assertSame(['a', 'c'], $this->listTitles($headers, 'view=favorites&label=' . $work->requireId()));
    }

    public function testALabelNarrowsTheKept(): void
    {
        [$headers, $work] = $this->seedThreeEntries('label-scope-kept@example.com');

        self::assertSame(['a'], $this->listTitles($headers, 'view=kept&label=' . $work->requireId()));
    }

    public function testUnlabeledListsTheKeptWithoutALabel(): void
    {
        [$headers] = $this->seedThreeEntries('label-scope-kept-unlabeled@example.com');

        self::assertSame(['b'], $this->listTitles($headers, 'view=kept&label=unlabeled'));
    }

    public function testUnlabeledFavoritesAreEmptyWhenEveryFavoriteHasALabel(): void
    {
        [$headers] = $this->seedThreeEntries('label-scope-favorites-unlabeled@example.com');

        self::assertSame([], $this->listTitles($headers, 'view=favorites&label=unlabeled'));
    }

    public function testALabelOnTheAllViewIsAValidationError(): void
    {
        [$headers, $work] = $this->seedThreeEntries('label-scope-all@example.com');

        $this->client->request('GET', '/api/entries?view=all&label=' . $work->requireId(), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $body = $this->payload($this->client);
        self::assertSame('validation_error', $body['type']);
        self::assertSame(['label' => ['A label needs view=favorites or view=kept.']], $body['errors']);
    }

    public function testAnotherUsersLabelListsNothing(): void
    {
        [$headers] = $this->seedThreeEntries('label-scope-own@example.com');
        [, $foreignWork] = $this->seedThreeEntries('label-scope-stranger@example.com');

        self::assertSame([], $this->listTitles($headers, 'view=favorites&label=' . $foreignWork->requireId()));
    }

    public function testAnotherUsersLabelOnTheSameEntryDoesNotHideItFromUnlabeled(): void
    {
        [$headers, , $entryB] = $this->seedThreeEntries('label-scope-shared-feed@example.com');
        $stranger = $this->factory()->create('label-scope-shared-stranger@example.com');
        $strangerLabel = $this->labelNamed($stranger, 'Theirs');
        $this->entityManager()->persist(new EntryLabel($strangerLabel, $entryB));
        $this->entityManager()->flush();

        self::assertSame(['b'], $this->listTitles($headers, 'view=kept&label=unlabeled'));
    }

    public function testCursorPagingWalksEveryLabeledFavorite(): void
    {
        [$headers, $user] = $this->bearerAndUser('label-scope-paging@example.com');
        $work = $this->labelNamed($user, 'Work');
        $feed = $this->subscribe($user);
        foreach (['p1' => '03', 'p2' => '02', 'p3' => '01'] as $title => $day) {
            $entry = $this->entry($feed, $title, $day);
            $this->state($user, $entry, favorite: true, kept: false);
            $this->entityManager()->persist(new EntryLabel($work, $entry));
        }
        $this->entityManager()->flush();

        $firstPageQuery = 'view=favorites&limit=2&label=' . $work->requireId();
        $this->client->request('GET', '/api/entries?' . $firstPageQuery, server: $headers);
        $firstPage = $this->payload($this->client);
        self::assertSame(['p1', 'p2'], $this->collectTitles($firstPage));
        self::assertIsString($firstPage['nextCursor']);

        $nextPageQuery = $firstPageQuery . '&cursor=' . urlencode($firstPage['nextCursor']);
        self::assertSame(['p3'], $this->listTitles($headers, $nextPageQuery));
    }

    /**
     * A, B, C as in the label counts test: A favorite and kept with Work, B kept without a label, C favorite with Work.
     *
     * @return array{array<string, string>, Label, Entry}
     */
    private function seedThreeEntries(string $email): array
    {
        [$headers, $user] = $this->bearerAndUser($email);
        $work = $this->labelNamed($user, 'Work');
        $feed = $this->subscribe($user);
        $entryA = $this->entry($feed, 'a', '03');
        $entryB = $this->entry($feed, 'b', '02');
        $entryC = $this->entry($feed, 'c', '01');
        $this->state($user, $entryA, favorite: true, kept: true);
        $this->state($user, $entryB, favorite: false, kept: true);
        $this->state($user, $entryC, favorite: true, kept: false);
        $this->entityManager()->persist(new EntryLabel($work, $entryA));
        $this->entityManager()->persist(new EntryLabel($work, $entryC));
        $this->entityManager()->flush();

        return [$headers, $work, $entryB];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string>
     */
    private function listTitles(array $headers, string $queryString): array
    {
        $this->client->request('GET', '/api/entries?' . $queryString, server: $headers);
        self::assertResponseIsSuccessful();

        return $this->collectTitles($this->payload($this->client));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function collectTitles(array $body): array
    {
        $entries = $body['entries'];
        self::assertIsArray($entries);

        return array_map(static function (mixed $entry): string {
            self::assertIsArray($entry);
            self::assertIsString($entry['title']);

            return $entry['title'];
        }, array_values($entries));
    }

    private function subscribe(User $user): Feed
    {
        $feed = new Feed('https://label-scope.example/' . uniqid('', true) . '.xml');
        $this->entityManager()->persist($feed);
        $this->entityManager()->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->entityManager()->flush();

        return $feed;
    }

    private function entry(Feed $feed, string $title, string $day): Entry
    {
        $moment = new \DateTimeImmutable('2026-07-' . $day . 'T00:00:00Z');
        $guid = uniqid($title, true);
        $entry = new Entry($feed, $guid, 'https://label-scope.example/' . $guid, $title, $moment, $moment);
        $this->entityManager()->persist($entry);
        $this->entityManager()->flush();

        return $entry;
    }

    private function state(User $user, Entry $entry, bool $favorite, bool $kept): void
    {
        $state = new EntryState($user, $entry);
        if ($favorite) {
            StateMarks::favorite($state);
        }
        if ($kept) {
            StateMarks::kept($state);
        }
        $this->entityManager()->persist($state);
        $this->entityManager()->flush();
    }
}
