# Link Cards in the Internal Reader — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An always-visible overlay button on an entry's link preview card opens the linked page in the internal reader; tapping the card itself still opens the original in a new tab.

**Architecture:** The backend gains a card-bound endpoint, `GET /api/entries/{id}/link-cards/{position}/reader`. It reuses the entry reader's ownership check and rate limiter, and runs the existing URL-based extractor on the card's URL. The frontend first extracts two pieces out of `reader-view`, as a pure refactor: an `ArticleLayer` (frame, toolbar, scroller, rail, gestures) and an `ArticleContent` (typography plus body decoration). It then composes a small `LinkReaderComponent` from them. The linked article is addressed by a new `?link=<entryId>.<position>` query parameter, which either layers over `?entry=` or stands alone.

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit 12; Angular 20 standalone + signals, Jest, Playwright.

**Spec:** GitHub issue [#1664](https://github.com/larspohlmann/simple-feed-reader/issues/1664). The decisions there are binding; the ones that matter are restated in each task.

## Global Constraints

- Branch `feature/1664-link-card-reader` off `develop`; commits `type(#1664): …`; no Claude attribution anywhere.
- Card-bound only: the server never fetches a URL a client supplied. A card is the `(entry_id, position)` pair; `position` is the `link_card.position` SMALLINT, starting at 0.
- Ownership before the limiter: an unowned entry or unknown position 404s **without** spending the caller's `reader` budget (same order as `EntryReaderController`).
- Shared limiter: the existing `limiter.reader` (60 per 5 minutes per user). Do not add a new one.
- No coverage gate (`ExtractionCoverageGate`) for card extraction, because a card has no feed body to compare against.
- No server-side cache. Client caches in the IndexedDB `sfr-reader` store under the key `card:<entryId>.<position>`, with the same cap and the same version invalidation.
- Overlay button: entry cards only (`<app-link-cards>` in the article view, and the magazine's first card). **Not** on comment cards, old `<figure class="link-card">` body markup, the unplayable-video card, or `reader-card` teasers.
- `?link=` layers over `?entry=` from the article view (back returns to the post, whose reader-view stays mounted beneath, so scroll is kept). From the magazine it stands alone (`entry` cleared): the post is not opened, highlighted or marked viewed, and back returns to the list.
- One level only: a card inside a linked article opens its original (`ArticleContent` already forces `_blank` on body links, and the link reader renders no `<app-link-cards>`).
- Reduced chrome: back, open original (primary), reload. No favourite, read state, prev/next, Reader/Original toggle, comments or share (share dropped by decision).
- Header while loading: the card's title and site name (from the entries store when the entry is loaded; otherwise the response's title and site name once it arrives).
- Failure: the usual warning box, with a prominent "Open original" link. Never fall back silently. The card image leads that failure view (Q10's hero fallback; on success, the extraction carries its own lead inside `contentHtml`, #681).
- iOS viability: JSON and `application/problem+json` only (architecture.md §6).
- Frontend: component styles in sibling `.scss`, no hex colours or ad-hoc `px` outside the theme, standalone components with signals.
- Clean Code rules from CLAUDE.md apply to every file touched (no boolean flag parameters on methods, no abbreviations, comments only when a reader would otherwise get the code wrong).

---

## File Structure

**Backend**
- Modify `backend/src/Repository/LinkCardRepository.php`: add `contentAt(int $entryId, int $position): LinkCardContent`.
- Create `backend/src/Service/Reader/LinkCardArticleReader.php`: extracts a card's page and gives its lead picture.
- Create `backend/src/Controller/Api/LinkCardReaderController.php`: the endpoint.
- Tests: `backend/tests/Repository/LinkCardRepositoryTest.php` (extend), `backend/tests/Service/Reader/LinkCardArticleReaderTest.php`, `backend/tests/Controller/Api/LinkCardReaderControllerTest.php`.

**Frontend refactor** (`frontend/src/app/reader/article/`)
- Create `article-content/article-content.component.{ts,html,scss,spec.ts}`. The `.scss` is `reader-view.component.content.scss` moved verbatim.
- Create `article-layer/article-layer.component.{ts,html,scss,spec.ts}`.
- Create `reader-view/_article-page.scss`: the article column, title, meta, lead image and fallback-note rules both readers share.
- Create `reader-view/_article-toolbar.scss`: the nameplate and toolbar action rules both readers share.
- Modify `reader-view/reader-view.component.{ts,html,scss}`; delete `reader-view/reader-view.component.content.scss`.

**Frontend feature**
- Create `frontend/src/app/reader/query/link-param.ts` (+ spec): parses and builds `?link=`.
- Modify `query/query.ts`: add `'link'` to `SELECTION_PARAM_NAMES`.
- Modify `shell/reader-route-state.service.ts`: add `linkCard`.
- Create `shell/link-reader-navigation.service.ts` (+ spec): layer, open alone, close.
- Modify `shell/entry-state-actions.service.ts` (`open` clears `link`) and `shell/reader-shell.component.{ts,html,scss}`.
- Modify `reader-api.ts`, `article/content/reader-cache.service.ts` and `article/content/reader-content.service.ts`.
- Create `article/link-reader/link-article-source.service.ts` and `article/link-reader/link-reader.component.{ts,html,scss,spec.ts}`.
- Modify `link-card/link-card.component.{ts,html,scss}`, `article/link-cards/link-cards.component.{ts,html}`, `list/magazine/blocks/entry-link/entry-link.component.{ts,html}`, `public/i18n/{en,de}.json`.
- Create `frontend/e2e/link-card-reader.spec.ts`.

---

### Task 1: Look up one stored card by entry and position

**Files:**
- Modify: `backend/src/Repository/LinkCardRepository.php`
- Test: `backend/tests/Repository/LinkCardRepositoryTest.php`

**Interfaces:**
- Produces: `LinkCardRepository::contentAt(int $entryId, int $position): LinkCardContent`, which throws `App\Repository\Exception\RecordNotFoundException` when no row matches. That exception is already mapped to 404 in `src/Http/Problem/ExceptionProblems/RequestProblems.php`.

- [ ] **Step 1: Write the failing tests.** Append to `LinkCardRepositoryTest`, using its existing `entry()`, `content()` and `repository()` helpers:

```php
    public function testFindsTheCardAtAPosition(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->append($entry, [], [$this->content('a'), $this->content('b')]);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame('b', $cards->contentAt($entry->requireId(), 1)->title);
    }

    public function testAPositionPastTheLastCardIsNotFound(): void
    {
        $entry = $this->entry('one');
        $cards = $this->repository();
        $cards->append($entry, [], [$this->content('a')]);
        $this->entityManager->flush();

        $this->expectException(RecordNotFoundException::class);
        $cards->contentAt($entry->requireId(), 1);
    }

    public function testAnotherEntrysCardIsNotFound(): void
    {
        $holder = $this->entry('holder');
        $other = $this->entry('other');
        $cards = $this->repository();
        $cards->append($holder, [], [$this->content('a')]);
        $this->entityManager->flush();

        $this->expectException(RecordNotFoundException::class);
        $cards->contentAt($other->requireId(), 0);
    }
```

Add `use App\Repository\Exception\RecordNotFoundException;`.

- [ ] **Step 2: Run the tests and see them fail.** Run `cd backend && php bin/phpunit --filter LinkCardRepositoryTest`. Expected: an error saying the method `contentAt` is undefined.

- [ ] **Step 3: Implement.** In `LinkCardRepository`, add `use App\Repository\Exception\RecordNotFoundException;` and:

```php
    public function contentAt(int $entryId, int $position): LinkCardContent
    {
        /** @var array{url: string, title: string, description: ?string, imageUrl: ?string, siteName: string}|null $row */
        $row = $this->createQueryBuilder('card')
            ->select('card.url', 'card.title', 'card.description', 'card.imageUrl', 'card.siteName')
            ->andWhere('card.entry = :entryId')
            ->andWhere('card.position = :position')
            ->setParameter('entryId', $entryId)
            ->setParameter('position', $position)
            ->getQuery()
            ->getOneOrNullResult();

        return $row === null
            ? throw new RecordNotFoundException('No such link card.')
            : new LinkCardContent($row['url'], $row['title'], $row['description'], $row['imageUrl'], $row['siteName']);
    }
```

If the same `new LinkCardContent($row[...] …)` mapping is now written twice in the file (here and in `contentsByEntryIds`), extract a `private static function contentOf(array $row): LinkCardContent` and use it in both places.

- [ ] **Step 4: Run the tests and see them pass.** Run `php bin/phpunit --filter LinkCardRepositoryTest`. Expected: PASS.

- [ ] **Step 5: Commit.** Run `composer cs && composer stan`, then:

```bash
git add backend/src/Repository/LinkCardRepository.php backend/tests/Repository/LinkCardRepositoryTest.php
git commit -m "feat(#1664): look up one link card by entry and position"
```

---

### Task 2: Extract a card's page

**Files:**
- Create: `backend/src/Service/Reader/LinkCardArticleReader.php`
- Test: `backend/tests/Service/Reader/LinkCardArticleReaderTest.php`

**Interfaces:**
- Consumes: `ArticleExtractorInterface::extract(string $url, EntryHintsModel $hints = new EntryHintsModel()): ExtractionResultModel`.
- Produces: `LinkCardArticleReader::read(LinkCardContent $card): ExtractionResultModel` and `LinkCardArticleReader::lead(LinkCardContent $card): ?DeclaredImageModel`.

- [ ] **Step 1: Write the failing test.**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\LinkCardContent;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Reader\LinkCardArticleReader;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\TestCase;

final class LinkCardArticleReaderTest extends TestCase
{
    public function testExtractsTheCardsPageWithItsTitleAsTheHint(): void
    {
        $extractor = new FakeArticleExtractor();
        $extracted = ExtractionResultModel::ok('https://example.com/a', 'A', null, null, '<p>Body</p>', null);
        $extractor->willReturn($extracted);

        $result = (new LinkCardArticleReader($extractor))->read($this->card('https://example.com/a.jpg'));

        self::assertSame($extracted, $result);
        self::assertSame(['https://example.com/a'], $extractor->calls);
        self::assertSame('The card title', $extractor->hints[0]->title);
    }

    public function testTheCardImageLeads(): void
    {
        $lead = (new LinkCardArticleReader(new FakeArticleExtractor()))->lead($this->card('https://example.com/a.jpg'));

        self::assertEquals(new DeclaredImageModel('https://example.com/a.jpg'), $lead);
    }

    public function testACardWithoutAnImageHasNoLead(): void
    {
        self::assertNull((new LinkCardArticleReader(new FakeArticleExtractor()))->lead($this->card(null)));
    }

    private function card(?string $imageUrl): LinkCardContent
    {
        return new LinkCardContent('https://example.com/a', 'The card title', null, $imageUrl, 'Example');
    }
}
```

- [ ] **Step 2: Run the test and see it fail.** Run `php bin/phpunit --filter LinkCardArticleReaderTest`. Expected: an error saying the class `LinkCardArticleReader` was not found.

- [ ] **Step 3: Implement.**

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\LinkCardContent;
use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;

final readonly class LinkCardArticleReader
{
    public function __construct(private ArticleExtractorInterface $extractor)
    {
    }

    public function read(LinkCardContent $card): ExtractionResultModel
    {
        return $this->extractor->extract($card->url, new EntryHintsModel(title: $card->title));
    }

    public function lead(LinkCardContent $card): ?DeclaredImageModel
    {
        return $card->imageUrl === null ? null : new DeclaredImageModel($card->imageUrl);
    }
}
```

The class gets no coverage-gate comment: the gate's absence is a decision recorded in #1664 and in the commit message below.

- [ ] **Step 4: Run the test and see it pass.** Expected: PASS.

- [ ] **Step 5: Commit.** Run `composer cs && composer stan && composer md`, then:

```bash
git add backend/src/Service/Reader/LinkCardArticleReader.php backend/tests/Service/Reader/LinkCardArticleReaderTest.php
git commit -m "feat(#1664): extract a link card's page

No coverage gate: a card has no feed body to compare the extraction against."
```

---

### Task 3: The card reader endpoint

**Files:**
- Create: `backend/src/Controller/Api/LinkCardReaderController.php`
- Test: `backend/tests/Controller/Api/LinkCardReaderControllerTest.php`

**Interfaces:**
- Consumes: `EntryListRepository::getOneSubscribedForUser(int $userId, int $entryId): Entry`, `LinkCardRepository::contentAt()` (Task 1), `LinkCardArticleReader` (Task 2), `ReaderJson::one(ExtractionResultModel, ?DeclaredImageModel, \DateTimeImmutable)`, `RateLimitGuard::enforceForUser()`.
- Produces: `GET /api/entries/{id}/link-cards/{position}/reader`, route name `api_entries_link_card_reader`. The JSON body has the same shape as the entry reader; on the failed branch, `originalHero` is the card image.

- [ ] **Step 1: Write the failing functional test.** Copy `setUp`, `auth`, `spendReaderBudget` and `installFake` from `EntryReaderControllerTest` verbatim. They are test helpers in another class; if a third copy ever appears, move them into a `tests/Support` trait. Then add:

```php
    private function seedEntryWithCard(User $user, ?string $imageUrl = 'https://example.com/card.jpg'): Entry
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $feed = new Feed('https://example.com/feed-' . uniqid('', true) . '.xml');
        $feed->setTitle('Seeded');
        $entityManager->persist($feed);
        $entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'g-' . uniqid('', true),
            'https://social.example/post/1',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entityManager->persist($entry);
        $linkCards = self::getContainer()->get(LinkCardRepository::class);
        self::assertInstanceOf(LinkCardRepository::class, $linkCards);
        $linkCards->append($entry, [], [
            new LinkCardContent('https://example.com/article', 'Card title', null, $imageUrl, 'Example'),
        ]);
        $entityManager->flush();

        return $entry;
    }

    /** @return array<string, mixed> */
    private function bodyOf(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    public function testExtractsTheCardsPage(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-ok@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResultModel::ok(
            url: 'https://example.com/article',
            title: 'Extracted',
            byline: null,
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: null,
        ));
        $entry = $this->seedEntryWithCard($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->bodyOf($client);
        self::assertSame('ok', $body['status']);
        self::assertSame('<p>Body</p>', $body['contentHtml']);
        self::assertSame(['https://example.com/article'], $fake->calls);
    }

    public function testAFailedExtractionLeadsWithTheCardImage(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-fail@example.com');
        $this->installFake()->willReturn(
            ExtractionResultModel::failed('https://example.com/article', ExtractionFailure::Fetch, 'HTTP 403 Forbidden'),
        );
        $entry = $this->seedEntryWithCard($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        $body = $this->bodyOf($client);
        self::assertSame('failed', $body['status']);
        self::assertSame(
            ['url' => 'https://example.com/card.jpg', 'width' => null, 'height' => null],
            $body['originalHero'],
        );
    }

    public function testAnUnknownPositionIsNotFoundAndSpendsNoBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-position@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntryWithCard($user);
        $this->spendReaderBudget($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/1/reader', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame([], $fake->calls);
    }

    public function testAnotherUsersCardIsNotFound(): void
    {
        $client = self::createClient();
        [, $owner] = $this->auth('card-reader-owner@example.com');
        [$headers] = $this->auth('card-reader-stranger@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntryWithCard($owner);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $fake->calls);
    }

    public function testSharesTheEntryReadersBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-limit@example.com');
        $fake = $this->installFake();
        $entry = $this->seedEntryWithCard($user);
        $this->spendReaderBudget($user);

        $client->request('GET', '/api/entries/' . $entry->requireId() . '/link-cards/0/reader', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame([], $fake->calls);
    }

    public function testRequiresAToken(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/entries/1/link-cards/0/reader');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
```

Check what the existing tests in this suite assert for the problem content type: run `grep -rn "problem+json" backend/tests/Controller | head`. Match the header assertion (exact value, or `str_contains`) to that convention.

- [ ] **Step 2: Run the tests and see them fail.** Run `php bin/phpunit --filter LinkCardReaderControllerTest`. Expected: 404s from routing on the success tests, so the ok and failed assertions fail.

- [ ] **Step 3: Implement the controller.**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReaderJson;
use App\Repository\EntryListRepository;
use App\Repository\LinkCardRepository;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\LinkCardArticleReader;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class LinkCardReaderController
{
    public function __construct(
        private EntryListRepository $entryList,
        private LinkCardRepository $linkCards,
        private ClockInterface $clock,
        private LinkCardArticleReader $articleReader,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $readerLimiter,
    ) {
    }

    #[Route(
        '/{id}/link-cards/{position}/reader',
        name: 'api_entries_link_card_reader',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'position' => '\d+'],
    )]
    public function reader(int $id, int $position, #[CurrentUser] User $user): JsonResponse
    {
        // Ownership is checked BEFORE the limiter so an unowned card 404s without spending the caller's reader budget.
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $card = $this->linkCards->contentAt($entry->requireId(), $position);
        $this->rateLimitGuard->enforceForUser($this->readerLimiter, $user);

        return new JsonResponse(ReaderJson::one(
            $this->articleReader->read($card),
            $this->articleReader->lead($card),
            $this->clock->now(),
        ));
    }
}
```

Three arguments, one of them the `#[CurrentUser]` attribute, is within the house limit. `ThinControllerRule` is satisfied: there are no private methods and only `requireId()` is called on the entity.

- [ ] **Step 4: Run the tests and see them pass.** Run `php bin/phpunit --filter LinkCardReaderControllerTest`. Expected: PASS. Then run the architecture checklist (`docs/architecture.md` §6) against the endpoint: bearer token, stateless, JSON in and out, problem+json errors, nothing browser-only. Note the result in the PR body.

- [ ] **Step 5: Commit.** Run `composer check && composer md`, then:

```bash
git add backend/src/Controller/Api/LinkCardReaderController.php backend/tests/Controller/Api/LinkCardReaderControllerTest.php
git commit -m "feat(#1664): GET /api/entries/{id}/link-cards/{position}/reader"
```

---

### Task 4: Refactor — `ArticleContent` out of `reader-view`

A pure refactor: no behaviour change, and `reader-view.component.spec.ts` stays green **without** edits to its assertions (only its imports or providers may change, if a test overrides a provider that moved).

**Files:**
- Create: `frontend/src/app/reader/article/article-content/article-content.component.ts`, `.html`, `.scss`, `.spec.ts`
- Move: `article/reader-view/reader-view.component.content.scss` to `article/article-content/article-content.component.scss` (with `git mv`; fix its relative `@use` paths, which stay at the same depth: `../../../theme/…` and `../../link-card/link-card-layout`)
- Modify: `article/reader-view/reader-view.component.ts`, `.html`

**Interfaces:**
- Produces: `<app-article-content [html] [pageUrl] [enclosureUrl] (decorated)>`. `ArticleContentComponent.element: Signal<ElementRef<HTMLElement> | undefined>` is the rendered `.content` element.

- [ ] **Step 1: Write the component spec** (`article-content.component.spec.ts`). It pins the contract: the html renders into `.content`, `decorated` fires with that element after the decorators run, and body links get `target=_blank`.

```ts
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTransloco } from '@jsverse/transloco'; // use the project's test transloco helper if one exists (grep "provideTranslocoTesting\|translocoTesting" in src)
import { ArticleContentComponent } from './article-content.component';
import { ListAutoplayCoordinator } from '../../media/autoplay/list-autoplay-coordinator';
import { UnlockedVideoElements } from '../../media/autoplay/unlocked-video-elements';

@Component({
  imports: [ArticleContentComponent],
  providers: [ListAutoplayCoordinator, UnlockedVideoElements],
  template: `<app-article-content
    [html]="html()"
    [pageUrl]="null"
    [enclosureUrl]="null"
    (decorated)="decorated.push($event)"
  />`,
})
class HostComponent {
  readonly html = signal('<p>One <a href="https://example.com">link</a></p>');
  readonly decorated: HTMLElement[] = [];
}

describe('ArticleContentComponent', () => {
  it('renders the html, decorates it, then reports the decorated element', async () => {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    await fixture.whenStable();
    await Promise.resolve(); // decoration runs in a microtask

    const content = fixture.nativeElement.querySelector('.content') as HTMLElement;
    expect(content.querySelector('p')?.textContent).toContain('One');
    expect(content.querySelector('a')?.target).toBe('_blank');
    expect(fixture.componentInstance.decorated).toEqual([content]);
  });
});
```

Copy the TestBed setup (Transloco, `HttpClient` test providers) that `article-body.service.spec.ts` already uses; it constructs `ArticleBody` and so needs the same providers.

- [ ] **Step 2: Run the spec and see it fail.** Run `docker compose exec -T frontend npx jest src/app/reader/article/article-content`. Expected: FAIL, because the component cannot be found.

- [ ] **Step 3: Implement.**

`article-content.component.html`:

```html
<div #content class="content" [innerHTML]="html()"></div>
```

`article-content.component.ts`:

```ts
import { Component, ElementRef, input, output, viewChild } from '@angular/core';
import { ArticleBody } from '../reader-view/article-body.service';

/** The article body: its typography and every decoration `ArticleBody` gives it. */
@Component({
  selector: 'app-article-content',
  templateUrl: './article-content.component.html',
  styleUrl: './article-content.component.scss',
  providers: [ArticleBody],
})
export class ArticleContentComponent {
  readonly html = input.required<string>();
  readonly pageUrl = input.required<string | null>();
  readonly enclosureUrl = input.required<string | null>();
  readonly decorated = output<HTMLElement>();

  readonly element = viewChild<ElementRef<HTMLElement>>('content');

  constructor() {
    inject(ArticleBody).connect({
      content: this.element,
      html: this.html,
      pageUrl: () => this.pageUrl(),
      enclosureUrl: () => this.enclosureUrl(),
      decorated: (host) => this.decorated.emit(host),
    });
  }
}
```

(Import `inject` too.) The moved stylesheet's `.content` rules keep working unchanged: they are scoped under `.content`, which this component now owns, and `::ng-deep` still reaches the `[innerHTML]` body. Its `@container reader-pane` rule matches across component boundaries, because container queries follow the DOM.

In `reader-view.component.ts`:
- Remove `ArticleBody` from `providers` and its `inject`; import `ArticleContentComponent` instead.
- Replace `private readonly content = viewChild<ElementRef<HTMLElement>>('content');` with:

```ts
  private readonly articleContent = viewChild(ArticleContentComponent);
  private readonly content = computed(() => this.articleContent()?.element());
```

- Delete the `this.body.connect({...})` block and move its `decorated` body into a method:

```ts
  protected onDecorated(host: HTMLElement): void {
    this.toc.set(collectToc(host));
    this.scope.refresh();
    // Runs on the original render and again when the reader content swaps in.
    this.restore.reseat(() => this.entry()?.id);
  }
```

- `styleUrls` drops `./reader-view.component.content.scss`.

In `reader-view.component.html`, replace `<div #content class="content" [innerHTML]="source.displayHtml()"></div>` with:

```html
            <app-article-content
              [html]="source.displayHtml()"
              [pageUrl]="e.url"
              [enclosureUrl]="audio.attachment()?.url ?? null"
              (decorated)="onDecorated($event)"
            />
```

`scrollToHeading` keeps reading `this.content()?.nativeElement`, which is unchanged because `content` is still a signal of an `ElementRef`.

**Effect-order risk:** `ArticleBody`'s effects used to be created in reader-view's constructor after `scope.connect`. They are now created by a child component, so they run after the parent's effects. The reader-view spec's TOC, scroll-restore and reading-scope tests are the check. If one fails, the fix lives in the order of operations in `onDecorated`, never in the spec.

- [ ] **Step 4: Run both specs.** Run `docker compose exec -T frontend npx jest src/app/reader/article`. Expected: PASS, including every existing `reader-view.component.spec.ts` case.

- [ ] **Step 5: Commit.** Run `docker compose exec -T frontend npm run check`, then:

```bash
git add -A frontend/src/app/reader/article
git commit -m "refactor(#1664): ArticleContent owns the article body and its typography"
```

---

### Task 5: Refactor — `ArticleLayer` out of `reader-view`

Also a pure refactor, with the same bar: `reader-view.component.spec.ts` assertions unchanged.

**Files:**
- Create: `frontend/src/app/reader/article/article-layer/article-layer.component.ts`, `.html`, `.scss`, `.spec.ts`
- Create: `article/reader-view/_article-page.scss`, `article/reader-view/_article-toolbar.scss`
- Modify: `article/reader-view/reader-view.component.ts`, `.html`, `.scss`

**Interfaces:**
- Consumes: `ArticleGestures` (provided by the layer; it needs an `ArticleScrollRestore` from the host's injector), `ScrollProgressRail`.
- Produces:

```ts
// <app-article-layer [fullscreen] [progressRail] [withTail] [backLabel] (close) (scrolled) (toTop) (barResized)>
//   <span articleMini>…</span>   projected into the fullscreen nameplate strip
//   <… articleBar>…</…>          projected into the toolbar, after the back button
//   default content              projected into .reader (the article + its loading overlay)
export class ArticleLayerComponent {
  readonly fullscreen: InputSignal<boolean>;
  readonly progressRail: InputSignal<ScrollProgressRail>;
  readonly withTail: InputSignal<boolean>;
  readonly backLabel: InputSignal<string>;
  readonly close: OutputEmitterRef<void>;
  readonly scrolled: OutputEmitterRef<number>;
  readonly toTop: OutputEmitterRef<void>;
  readonly barResized: OutputEmitterRef<void>;
  readonly scroller: Signal<HTMLElement | undefined>;
  readonly leaving: Signal<boolean>;
  back(): void;          // fullscreen: slide out; split: emit close
  resetChrome(): void;   // toolbar shown, to-top hidden, scroll baseline 0
  scrollToTop(behavior: ScrollBehavior): void;
}
```

- [ ] **Step 1: Write the layer spec.** It pins: the back button emits `close` when not fullscreen; scrolling past `BACK_TO_TOP_AFTER_PX` shows `app-to-top-button`, and activating it emits `toTop`; when fullscreen, scrolling down hides the toolbar (`.bar.hidden`) and scrolling up shows it; `scrolled` carries `scrollTop`. Host it in a test component that projects `<span articleBar class="probe">x</span>` and `<article>body</article>`, and assert both land inside `.bar` and `.reader`. Provide `ArticleScrollRestore` in the host component, since `ArticleGestures` needs it.

- [ ] **Step 2: Run the spec and see it fail.** Run `docker compose exec -T frontend npx jest src/app/reader/article/article-layer`. Expected: FAIL, because the component cannot be found.

- [ ] **Step 3: Implement the layer.** Move code out of `reader-view`; don't rewrite it.
- **Template:** the `.frame` div with its bindings; the `.mini` strip (under `@if (fullscreen())`, holding `<ng-content select="[articleMini]" />`); `#bar` with the `.close` back button (`[attr.aria-label]="backLabel()"`, `(click)="back()"`) followed by `<ng-content select="[articleBar]" />`; `#scroller` with `[appScrollProgress]="progressRail()"` and `(scroll)="onScroll(scroller.scrollTop)"`; `.reader` with `[class.with-tail]="withTail()"` and the pull transform, holding `<ng-content />` and the `.pull-back` spinner; `<app-progress-rail [progress]="progressRail()" />`; and, outside `.frame`, `@if (showToTop()) { <app-to-top-button (activate)="toTop.emit()" /> }`.
- **Class:** moved from reader-view: `toolbarHidden`, `showToTop`, `lastToolbarScrollTop`, the toolbar half of `onScroll` (then `this.scrolled.emit(scrollTop)`), `reserveToolbarHeight` (set `--reader-bar-h` on the layer's own host and `this.barResized.emit()` where it called `scope.refresh()`), `onBack` (renamed `back`), and the `gestures.connect({ fullscreen, scroller, close: () => this.close.emit() })` call. `leaving = this.gestures.leaving`. `resetChrome()` is the four toolbar and to-top resets from reader-view's entry-change effect. `scrollToTop(behavior)` is `this.scroller()?.scrollTo({ top: 0, behavior })`.
- **Styles** move from `reader-view.component.scss` to `article-layer.component.scss`: `:host` (as is), `.frame`, `.scroller`, `.reader`, `.with-bar .scroller`, `.pull-back`, `app-to-top-button` with its `:host-context(.is-narrow)` rule, `.mini`, `.bar`, `.bar.hidden`, `.with-bar .bar`, the reduced-motion `.bar` rule, and `.close`. Turn `.reader.with-tail article { --reader-focus-tail: 50cqb; }` into `.reader.with-tail { --reader-focus-tail: 50cqb; }`: the article is projected content, which the layer's emulated styles cannot select, and the custom property inherits to it.
- **Shared partials.** `_article-toolbar.scss` gets a mixin `article-toolbar` holding `.bar-name`, `.mini-title`, `.bar-title` and their font-size rules, `.nav`, and the `bp.$bp-sm` `.nav` / `.mobile-icon-only` media rule. These elements are projected, so the **host** component's styles must carry them. `_article-page.scss` gets a mixin `article-page` holding `article`, `@container reader-pane … .reader article:has(.reader-cinema--on)` (rewritten as `article:has(.reader-cinema--on)`), `.title-row`, `.title`, `.meta` with its `a` rules, `.lead-image`, `.reader-fallback`, `.reader-note`, `.reader-fallback-quiet`, `.reader-error` with its `summary` / `pre` rules, and `.placeholder`. `reader-view.component.scss` becomes `@use` of both partials with `@include`s, plus its entry-only rules: `.categories`, `.tags`, `.actions`, `.body-error`.

In `reader-view.component.ts`:
- `ArticleGestures` leaves `providers`.
- `scrollerRef`, `bar`, `toolbarHidden`, `showToTop`, `lastToolbarScrollTop` and `reserveToolbarHeight` are deleted.
- `private readonly layer = viewChild(ArticleLayerComponent); private readonly scroller = computed(() => this.layer()?.scroller());`
- The entry-change effect calls `this.layer()?.resetChrome()` in place of the four resets.
- `onScroll(scrollTop)` keeps only `if (!this.layer()?.leaving()) this.restore.remember(this.entry()?.id, scrollTop);`
- `scrollToTop()` becomes `this.restore.abort(); this.layer()?.scrollToTop(this.reduceMotion ? 'auto' : 'smooth'); this.titleHeading()?.nativeElement.focus({ preventScroll: true });`
- `onBack()` is deleted.

In `reader-view.component.html`, the `@if (entry(); as e)` branch becomes:

```html
  <app-article-layer
    [fullscreen]="fullscreen()"
    [progressRail]="progressRail"
    [withTail]="scope.hasTail()"
    [backLabel]="'reader.backToList' | transloco"
    (close)="close.emit()"
    (scrolled)="onScroll($event)"
    (toTop)="scrollToTop()"
    (barResized)="scope.refresh()"
  >
    <ng-container articleMini>…the favicon + .mini-title from the old .mini div…</ng-container>
    @if (!fullscreen()) {
      <span articleBar class="bar-name" aria-hidden="true">…unchanged…</span>
    }
    <div articleBar class="nav">…unchanged…</div>
    <article class="text-scaled" …>…unchanged…</article>
    <app-loading-overlay [shown]="source.loading()" [label]="'common.loading' | transloco" />
  </app-article-layer>
```

`ng-content select` matches attributes on top-level projected nodes, and `<ng-container articleMini>` projects as a unit. Keep the explanatory HTML comments that sat on the moved blocks with the blocks they explain: on the layer's template for frame and strip, on reader-view's for `.bar-name`. If the reader-view spec reaches into `component.gestures` or `component.toolbarHidden`, query the child instead (`fixture.debugElement.query(By.directive(ArticleLayerComponent)).componentInstance`). That changes how the spec reaches state, not what it asserts.

- [ ] **Step 4: Run all article specs.** Run `docker compose exec -T frontend npx jest src/app/reader`. Expected: PASS.

- [ ] **Step 5: Check the real render.** Start the stack (`docker compose up -d`), open http://localhost:4200, and compare against `develop`. Check: the split pane (toolbar under the app bar, never retracting); a phone viewport (375×812: fullscreen overlay, retracting toolbar, standing nameplate, swipe-back, pull-to-return spinner); to-top focusing the title; the progress rail; and cinema mode on a landscape video. All should be visually identical. Reproduce on the real render per house rule, not only in jsdom.

- [ ] **Step 6: Commit.** Run `npm run check`, then:

```bash
git add -A frontend/src/app/reader/article
git commit -m "refactor(#1664): ArticleLayer owns the reader's frame, toolbar, scroller and gestures"
```

---

### Task 6: The `?link=` parameter, route state and navigation

**Files:**
- Create: `frontend/src/app/reader/query/link-param.ts`, `link-param.spec.ts`
- Create: `frontend/src/app/reader/shell/link-reader-navigation.service.ts`, `.spec.ts`
- Modify: `query/query.ts`, `shell/reader-route-state.service.ts`, `shell/entry-state-actions.service.ts` (+ spec), `shell/reader-shell.component.ts` (provider and `onCloseReader`)

**Interfaces:**
- Produces:

```ts
// link-param.ts
export interface LinkCardRef { readonly entryId: number; readonly position: number; }
export function linkParam(ref: LinkCardRef): string;                 // "514.0"
export function linkCardFromParam(value: string | null): LinkCardRef | null;
export function sameLinkCard(left: LinkCardRef | null, right: LinkCardRef | null): boolean;

// ReaderRouteState
readonly linkCard: Signal<LinkCardRef | null>;   // equal: sameLinkCard

// LinkReaderNavigation (@Injectable(), provided by ReaderShellComponent)
layer(ref: LinkCardRef): void;      // merge { link }        — keeps ?entry=
openAlone(ref: LinkCardRef): void;  // merge { link, entry: null }
close(): void;                      // merge { link: null }
```

- [ ] **Step 1: Write the failing specs.**

```ts
// link-param.spec.ts
import { linkCardFromParam, linkParam, sameLinkCard } from './link-param';

describe('link param', () => {
  it('round-trips an entry id and card position', () => {
    expect(linkCardFromParam(linkParam({ entryId: 514, position: 2 }))).toEqual({ entryId: 514, position: 2 });
  });

  it.each([null, '', '514', '514.', '.2', '0.1', '514.-1', '514.2.3', 'abc'])('reads %p as no link', (value) => {
    expect(linkCardFromParam(value)).toBeNull();
  });

  it('compares by value', () => {
    expect(sameLinkCard({ entryId: 1, position: 0 }, { entryId: 1, position: 0 })).toBe(true);
    expect(sameLinkCard({ entryId: 1, position: 0 }, { entryId: 1, position: 1 })).toBe(false);
    expect(sameLinkCard(null, null)).toBe(true);
  });
});
```

`link-reader-navigation.service.spec.ts`: with a `Router` spy (`navigate`) and a stub `ActivatedRoute`, assert that the three methods call `navigate([], { relativeTo, queryParams, queryParamsHandling: 'merge' })` with `{ link: '7.1' }`, `{ link: '7.1', entry: null }` and `{ link: null }` respectively.

`entry-state-actions.service.spec.ts`: the existing `open` test's expected `queryParams` gains `link: null`. That makes it fail first, which proves opening an entry from the list drops a standalone link.

`query.spec.ts` (or wherever `selectionQueryParams` is tested): assert that `selectionQueryParams({ tag: 3 })` contains `link: null`.

- [ ] **Step 2: Run the specs and see them fail.** Run `docker compose exec -T frontend npx jest src/app/reader/query src/app/reader/shell`. Expected: FAIL.

- [ ] **Step 3: Implement.**

```ts
// link-param.ts
/** The `?link=` value: the card's entry id and its position among that entry's cards. */
export interface LinkCardRef {
  readonly entryId: number;
  readonly position: number;
}

export function linkParam(ref: LinkCardRef): string {
  return `${ref.entryId}.${ref.position}`;
}

export function linkCardFromParam(value: string | null): LinkCardRef | null {
  const match = /^(\d+)\.(\d+)$/.exec(value ?? '');
  if (match === null) return null;
  const entryId = Number(match[1]);
  const position = Number(match[2]);
  return entryId > 0 && Number.isSafeInteger(entryId) && Number.isSafeInteger(position)
    ? { entryId, position }
    : null;
}

export function sameLinkCard(left: LinkCardRef | null, right: LinkCardRef | null): boolean {
  return left?.entryId === right?.entryId && left?.position === right?.position;
}
```

- `query.ts`: add `'link'` to `SELECTION_PARAM_NAMES`. It is a selection-identity name only in the sense that a list change must clear it. `selectionFromParams` never reads it.
- `ReaderRouteState`: `readonly linkCard = computed(() => linkCardFromParam(this.queryParameters().get('link')), { equal: sameLinkCard });`
- `LinkReaderNavigation`: inject `Router` and `ActivatedRoute`, with one private `navigate(queryParams: Record<string, string | null>)` that the three public methods call.
- `EntryStateActions.open`: `queryParams: { entry: entryParam(entry.id, entry.title), link: null }`.
- `ReaderShellComponent`: add `LinkReaderNavigation` to `providers`. `onCloseReader` sets `queryParams: { entry: null, link: null }`, because closing the post closes anything layered on it.

- [ ] **Step 4: Run the specs and see them pass.**

- [ ] **Step 5: Commit.**

```bash
git add -A frontend/src/app/reader/query frontend/src/app/reader/shell
git commit -m "feat(#1664): ?link= addresses a link card in the reader"
```

---

### Task 7: Card reader content: API, cache key, load

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts` (+ spec), `article/content/reader-cache.service.ts` (+ spec), `article/content/reader-content.service.ts` (+ spec)

**Interfaces:**
- Consumes: `LinkCardRef` (Task 6).
- Produces:

```ts
// ReaderApi
linkCardReaderContent(ref: LinkCardRef): Observable<ReaderContent>; // GET /api/entries/{entryId}/link-cards/{position}/reader
// ReaderCacheService
export type ReaderCacheKey = number | `card:${string}`;
get(key: ReaderCacheKey); put(key: ReaderCacheKey, article); delete(key: ReaderCacheKey);
// ReaderContentService
loadLinkCard(ref: LinkCardRef): Observable<ReaderContent>;
reloadLinkCard(ref: LinkCardRef): Observable<ReaderContent>;
```

- [ ] **Step 1: Write the failing specs.**
- `reader-api.spec.ts`: `linkCardReaderContent({ entryId: 7, position: 1 })` issues `GET …/api/entries/7/link-cards/1/reader`. Mirror the existing `readerContent` test.
- `reader-cache.service.spec.ts`: a `put('card:7.1', article)` is read back by `get('card:7.1')` and does not collide with `get(7)`. Mirror the existing put and get test (it uses `fake-indexeddb`; check its imports).
- `reader-content.service.spec.ts`: `loadLinkCard` is cache-first under `card:7.1`; on a miss it calls `linkCardReaderContent` and caches only `status: 'ok'`. `reloadLinkCard` deletes `card:7.1`, then fetches. Mirror the existing `load` and `reload` tests.

- [ ] **Step 2: Run the specs and see them fail.**

- [ ] **Step 3: Implement.**
- **Cache:** rename the record field and `keyPath` from `entryId` to `key: ReaderCacheKey`, rename the parameters to match, and bump `VERSION` from `30` to `31`. The bump is the store's own schema cache-bust (see the `onupgradeneeded` comment), so old records are dropped, not misread. Update the class doc comment: "keyed by entry id, or `card:<entryId>.<position>` for a link card's page".
- **ReaderContentService:** generalise the private `fetchAndCache(entryId)` into `fetchAndCache(key: ReaderCacheKey, request: Observable<ReaderContent>)`. `load` and `reload` pass `(entryId, this.api.readerContent(entryId))`; the card methods pass `(cardKey(ref), this.api.linkCardReaderContent(ref))`, where `cardKey` is a module-level function `(ref) => \`card:${linkParam(ref)}\``. Factor the `from(cache.get) … timeout … switchMap` chain into a private `cacheFirst(key, request)`, so `load` and `loadLinkCard` are one line each.

- [ ] **Step 4: Run the specs and see them pass.**

- [ ] **Step 5: Commit.**

```bash
git add -A frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts frontend/src/app/reader/article/content
git commit -m "feat(#1664): load and cache a link card's reader content"
```

---

### Task 8: `LinkReaderComponent`

**Files:**
- Create: `frontend/src/app/reader/article/link-reader/link-article-source.service.ts`, `link-reader.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `de.json`

**Interfaces:**
- Consumes: `ArticleLayerComponent` (Task 5), `ArticleContentComponent` (Task 4), `ReaderContentService.loadLinkCard` / `reloadLinkCard` (Task 7), `LinkCardRef` (Task 6), `LinkCardDto`.
- Produces: `<app-link-reader [ref] [card] [fullscreen] [returnsTo] (close)>`, where `card: LinkCardDto | null` is the store's card (null on an unlisted deep link) and `returnsTo: 'article' | 'list'` picks the back label.

- [ ] **Step 1: Add the i18n keys.** In the `reader` object of both files, next to `openOriginal`:
- en: `"openInReader": "Open in reader"`, `"backToArticle": "Back to article"`, `"linkFailed": "This page could not be read here."`
- de: `"openInReader": "Im Reader öffnen"`, `"backToArticle": "Zurück zum Artikel"`, `"linkFailed": "Diese Seite lässt sich hier nicht lesen."`

The failure note reuses the existing `reader.fallback.<reason>` keys where they fit. Check what those say (`grep -n '"fallback"' -A20 public/i18n/en.json`). If they speak of "the feed's text" (true for entries but not for cards), use `reader.linkFailed` for every reason instead, and keep the `<details>` error disclosure.

- [ ] **Step 2: Write the failing spec** (`link-reader.component.spec.ts`), with a stub `ReaderContentService` whose `loadLinkCard` returns a `Subject`:
1. While loading, the header shows `card.title` and `card.siteName`, and `app-loading-overlay` is shown.
2. An ok payload renders its `contentHtml` inside `.content` and its `title` as the `h1`.
3. A failed payload shows the warning box, an `Open original` link with `href` = card url, `target=_blank`, and the card image (`originalHero`) as `.lead-image`.
4. A transport error (the subject errors, e.g. a 404 for `?link=<id>.99`) shows the same failure state with no image; with `card` null and no payload, the Open original link is absent.
5. The back button emits `close`. Its aria-label is `reader.backToArticle` when `returnsTo` is `'article'` and `reader.backToList` when it is `'list'`.
6. Changing `ref` loads the new card (`loadLinkCard` called with it).
7. The rendered template contains no `app-link-cards`, `app-entry-comments` or `app-entry-actions` (pins the one-level, reduced-chrome decisions).

- [ ] **Step 3: Run the spec and see it fail.**

- [ ] **Step 4: Implement `LinkArticleSource`.** It is component-scoped (`@Injectable()`), so each reader instance owns its load lifecycle.

```ts
type LinkSourceState =
  | { status: 'loading' }
  | { status: 'ok'; article: ReaderArticle }
  | { status: 'failed'; failure: ReaderFailure | null; error: unknown };

@Injectable()
export class LinkArticleSource {
  private readonly reader = inject(ReaderContentService);
  private loadSub: Subscription | null = null;
  private readonly state = signal<LinkSourceState>({ status: 'loading' });

  readonly loading = computed(() => this.state().status === 'loading');
  readonly article = computed(() => { const s = this.state(); return s.status === 'ok' ? s.article : null; });
  readonly failure = computed(() => { const s = this.state(); return s.status === 'failed' ? s : null; });
  readonly errorDetail = computed(() => { const f = this.failure(); return f === null ? null : f.failure ? f.failure.detail : describeLoadError(f.error); });
  readonly lead = computed(() => this.failure()?.failure?.originalHero ?? null);

  constructor() { inject(DestroyRef).onDestroy(() => this.loadSub?.unsubscribe()); }

  open(ref: LinkCardRef): void { this.run(this.reader.loadLinkCard(ref)); }
  reload(ref: LinkCardRef): void { this.run(this.reader.reloadLinkCard(ref)); }

  private run(source: Observable<ReaderContent>): void {
    this.loadSub?.unsubscribe();
    this.state.set({ status: 'loading' });
    this.loadSub = source.pipe(timeout({ first: READER_LOAD_TIMEOUT_MS })).subscribe({
      next: (content) => this.state.set(content.status === 'ok' ? { status: 'ok', article: content } : { status: 'failed', failure: content, error: null }),
      error: (error: unknown) => this.state.set({ status: 'failed', failure: null, error }),
    });
  }
}
```

Write it out with full names: `state` rather than `s`, `failed` rather than `f`; `AbbreviatedNameRule` doesn't run on TypeScript, but CLAUDE.md's naming rule does. `READER_LOAD_TIMEOUT_MS` is currently a module constant in `article-source.service.ts`. Export it from `article/content/reader-load-timeout.ts`, and import it in both places rather than duplicating the 30 000.

- [ ] **Step 5: Implement the component.**
- **Providers:** `ArticleScrollRestore` (`ArticleGestures` needs it), `ReadingScope`, `LinkArticleSource`, `ListAutoplayCoordinator`, `UnlockedVideoElements`, and `{ provide: AUTOPLAY_REGION, useValue: () => undefined }`. Nothing autoplays in a linked page. The coordinator reads an `undefined` region as "covers nothing" (`list-autoplay-coordinator.ts:146`).
- **Progress rail:** `ScrollProgressRail` with `isWide: inject(LayoutService).isWide`, `contentBottom: this.scope.readingEnd`, `layout: this.scope.readingEnd`. `ReadingScope.connect({ scroller, content, trailing: [] })`, where `scroller` and `content` come from the layer and the content child, as in reader-view after Task 5. Read `reading-scope.service.ts` on the branch to confirm the field names; #1663 renamed `contentBottom` to `readingEnd`.
- **Effect** on `ref()`: `untracked(() => { this.layer()?.resetChrome(); this.source.open(ref); })`.
- **Derived values:** `title = computed(() => this.card()?.title ?? this.source.article()?.title ?? '')`; `siteName` likewise (`card.siteName` → `article.siteName`); `originalUrl = computed(() => this.card()?.url ?? this.source.article()?.url ?? this.source.failure()?.failure?.url ?? null)`.

Template, inside `<app-article-layer [fullscreen] [progressRail] [withTail]="scope.hasTail()" [backLabel]="backLabelKey() | transloco" (close)="close.emit()" (toTop)="scrollToTop()" (barResized)="scope.refresh()">`:
- `articleMini`: `<span class="mini-title">{{ title() }}</span>`.
- `articleBar` (split only): `<span class="bar-name" aria-hidden="true"><span class="bar-title">{{ siteName() }}</span></span>`.
- `articleBar`: a `.nav` holding a refresh button. It is shown when an article is loaded and calls `source.reload(ref())` (parity with the entry reader's "Reload article", same `appListAction mobile-icon-only` markup and `reader.refreshArticle` key).
- `<article class="text-scaled" [attr.aria-busy]="source.loading() ? 'true' : null">`:
  - `.title-row > h1.title #titleHeading tabindex="-1"` with the title.
  - `p.meta`: the site name, then `·` and the Open original link (`[href]="originalUrl()"`, `target="_blank"`, `rel="noopener noreferrer"`, label `reader.openOriginal` + `open_in_new` icon), shown when `originalUrl()` is not null.
  - Under `@if (!source.loading())`: `app-paywall-notice` when `source.article()?.paywalled`. When `source.failure()`: the `app-warning-box.reader-fallback` (same markup as reader-view, minus the Retry button, whose role the toolbar refresh covers), then the `.lead-image` from `source.lead()`, then a primary-styled Open original link (`class="open-original"`). When `source.article()`: `<app-article-content [html]="article.contentHtml" [pageUrl]="article.url" [enclosureUrl]="null" (decorated)="scope.refresh()" />`.
- `<app-loading-overlay [shown]="source.loading()" …/>` as a sibling of `<article>`.

`backLabelKey = computed(() => this.returnsTo() === 'article' ? 'reader.backToArticle' : 'reader.backToList')`.

`link-reader.component.scss`: `@use` the two partials from Task 5 and `@include` them, plus `.open-original`. Style it as the primary action with existing tokens: look at how `docs/design-language.md` names a primary link button (`grep -n "primary" docs/design-language.md`) and reuse that class or mixin. Don't invent a new look.

- [ ] **Step 6: Run the spec and see it pass.** Run `docker compose exec -T frontend npx jest src/app/reader/article/link-reader`.

- [ ] **Step 7: Commit.**

```bash
git add -A frontend/src/app/reader/article frontend/public/i18n
git commit -m "feat(#1664): LinkReader shows a link card's page in the reader"
```

---

### Task 9: Mount the link reader in the shell

**Files:**
- Modify: `frontend/src/app/reader/shell/reader-shell.component.ts`, `.html`, `.scss`, `reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `ReaderRouteState.linkCard`, `LinkReaderNavigation.close()` (Task 6), `LinkReaderComponent` (Task 8).

- [ ] **Step 1: Write the failing shell specs.** Add a `describe('link reader (#1664)')` to `reader-shell.component.spec.ts`. Drive the query params the way the existing open-entry tests do (`grep -n "entry=" src/app/reader/shell/reader-shell.component.spec.ts`).
1. `?entry=<id>&link=<id>.0` in split view renders `app-reader-view` **and** `app-link-reader` (`returnsTo` = `'article'`), with the link reader inside `section.reader`.
2. `?link=<id>.0` with no entry, in the narrow layout, renders `app-link-reader` in an `.article-overlay` (`returnsTo` = `'list'`), with no entry highlighted (`openEntryId` input on the list is null).
3. The link reader's `close` navigates with `{ link: null }` and keeps `entry`.
4. `articleFullscreen()` is true for a standalone link on the narrow layout, which disables the drawer swipe.
5. `openLinkCard()` is the store entry's `linkCards[position]`, or null when the entry is not in the store.

- [ ] **Step 2: Run the specs and see them fail.**

- [ ] **Step 3: Implement.**
- `reader-shell.component.ts`:

```ts
  readonly linkCard = this.routeState.linkCard;
  readonly openLinkCard = computed(() => {
    const ref = this.linkCard();
    return ref === null ? null : (this.entries.entry(ref.entryId)?.linkCards[ref.position] ?? null);
  });
  readonly linkReturnsTo = computed(() => (this.openEntry() === null ? 'list' : 'article'));
  private readonly linkNavigation = inject(LinkReaderNavigation);

  onCloseLink(): void {
    this.linkNavigation.close();
  }
```

  Change `articleFullscreen` to `(this.openEntry() !== null || this.linkCard() !== null) && !this.splitView()`.

- `reader-shell.component.html`: add an `ng-template #linkPane let-fullscreen="fullscreen"` with `<app-link-reader [ref]="linkCard()!" [card]="openLinkCard()" [fullscreen]="fullscreen" [returnsTo]="linkReturnsTo()" (close)="onCloseLink()" />`. Instead of the non-null assertion, guard it with `@if (linkCard(); as ref)` and pass `ref`.
  - **Split branch:** inside `section.reader`, after the reader-pane outlet, add `@if (linkCard()) { <div class="link-overlay"><ng-container *ngTemplateOutlet="linkPane; context: { fullscreen: false }" /></div> }`.
  - **Single-pane branch:** after the `@if (openEntry())` overlay, add `@if (linkCard()) { <div class="article-overlay"><ng-container *ngTemplateOutlet="linkPane; context: { fullscreen: !screen.isWide() }" /></div> }`. It comes later in the DOM at the same z-index, so it stacks above the entry overlay, and the entry stays mounted beneath with its scroll.
- `reader-shell.component.scss`: `.main.split .reader { position: relative; }` (add `position` to the existing rule), and

```scss
/* The linked article covers the reader pane; the entry beneath stays mounted so back keeps its scroll (#1664). */
.link-overlay {
  position: absolute;
  inset: 0;
  z-index: 1;
  animation: article-enter 0.22s ease-out;
}

@media (prefers-reduced-motion: reduce) {
  .link-overlay {
    animation: none;
  }
}
```

  `z-index: 1` sits inside `.reader`'s own stacking context. Check it against the pane divider (`z-index: 1`, a sibling of `.reader`, not a parent) in the real render, and give `.main.split .reader` `isolation: isolate` if the divider's hairline paints over the overlay.

- [ ] **Step 4: Run the specs and see them pass.** Run `docker compose exec -T frontend npx jest src/app/reader/shell`.

- [ ] **Step 5: Commit.**

```bash
git add -A frontend/src/app/reader/shell
git commit -m "feat(#1664): the shell layers the link reader over the article or the list"
```

---

### Task 10: The overlay button on entry cards

**Files:**
- Modify: `frontend/src/app/reader/link-card/link-card.component.ts`, `.html`, `.scss`, `link-card.component.spec.ts` (create it if absent)
- Modify: `article/link-cards/link-cards.component.ts`, `.html` (+ spec)
- Modify: `list/magazine/blocks/entry-link/entry-link.component.ts`, `.html` (+ spec)

**Interfaces:**
- Consumes: `LinkReaderNavigation.layer()` / `openAlone()`, `LinkCardRef` (Task 6).
- Produces: `LinkCardComponent` inputs/outputs `openableInReader = input(false)` and `openInReader = output<void>()`. Comment cards (`entry-comments.component.html`) leave the input at its default, so they get no button, per Q18.

- [ ] **Step 1: Write the failing specs.**
- `link-card.component.spec.ts`:
  1. With `openableInReader` false (default), there is no `button.open-in-reader`.
  2. With it true, a button with aria-label `reader.openInReader` exists outside the `<a>` (`button.closest('a')` is null, since an interactive element may not nest in a link).
  3. Clicking the button emits `openInReader` once, and the dispatched click event has `defaultPrevented` / propagation stopped, so neither the card's `<a>` nor a parent `appOpensEntry` fires. Assert this with a parent `(click)` spy that stays uncalled.
  4. Enter and Space on the button also stay inside it (a parent `keydown` spy stays uncalled).
- `link-cards.component.spec.ts`: each card renders with `openableInReader`; clicking the second card's button calls `LinkReaderNavigation.layer({ entryId: entry.id, position: 1 })` (a stub provided in TestBed).
- `entry-link.component.spec.ts`: the magazine card's button calls `openAlone({ entryId: entry.id, position: 0 })` and does not call `EntryStateActions.open` (i.e. the `appOpensEntry` handler, stubbed the way the existing spec stubs it).

- [ ] **Step 2: Run the specs and see them fail.**

- [ ] **Step 3: Implement.**
- `link-card.component.html`: keep the `<a>` exactly as is, then after it:

```html
@if (openableInReader()) {
  <button
    type="button"
    class="open-in-reader"
    [attr.aria-label]="'reader.openInReader' | transloco"
    [attr.title]="'reader.openInReader' | transloco"
    (click)="onOpenInReader($event)"
    (keydown.enter)="$event.stopPropagation()"
    (keydown.space)="$event.stopPropagation()"
  >
    <app-icon name="chrome_reader_mode" size="sm" />
  </button>
}
```

- `link-card.component.ts`: import `IconComponent` and `TranslocoPipe`; add `readonly openableInReader = input(false);` and `readonly openInReader = output<void>();`; and `protected onOpenInReader(event: Event): void { event.stopPropagation(); this.openInReader.emit(); }`. Also add `'[class.openable]': 'openableInReader()'` to `host`.
- `link-card.component.scss`: `:host { position: relative; }` (check `_link-card-layout.scss` first; if the host is already positioned, leave it). Then:

```scss
/* Always visible, not hover-only: touch is a first-class target (#1664). Top-right corner, over the image when the
   card leads with one; the scrim keeps the glyph legible over any picture. */
.open-in-reader {
  position: absolute;
  top: var(--space-2);
  right: var(--space-2);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-1);
  border: none;
  border-radius: var(--radius);
  background: var(--media-scrim);
  color: var(--on-media, var(--text-primary));
  cursor: pointer;
}

@media (pointer: coarse) {
  .open-in-reader {
    min-width: var(--tap-target);
    min-height: var(--tap-target);
  }
}

@media (hover: hover) {
  .open-in-reader:hover {
    background: var(--media-scrim-strong, var(--media-scrim));
  }
}
```

  Before committing, check every token used against `src/styles` and `theme/` (`grep -rn -- "--media-scrim\|--tap-target\|--on-media" src/styles* src/app/theme`). Use only tokens that exist, and drop the fallback form when a token does; `docs/design-language.md` lists the overlay-on-media convention the GIF badge follows. On a card with no image, the button would cover the title's first line, so give the text side room: `:host(.openable) strong { padding-inline-end: calc(var(--tap-target) + var(--space-2)); }`, scoped to the `article` and `block` layouts in whatever way `_link-card-layout.scss` structures them.
- `link-cards.component.html`: `@for (card of entry().linkCards; track card.url; let position = $index)` → `<app-link-card [card]="card" layout="article" [openableInReader]="true" (openInReader)="openInReader(position)" />`. In the `.ts`: `private readonly navigation = inject(LinkReaderNavigation); protected openInReader(position: number): void { this.navigation.layer({ entryId: this.entry().id, position }); }`.
- `entry-link.component.html`: the magazine card gains `[openableInReader]="true" (openInReader)="openInReaderAlone()"`. In the `.ts`, inject `LinkReaderNavigation` and add `protected openInReaderAlone(): void { this.navigation.openAlone({ entryId: this.entry().id, position: 0 }); }`. Check that `EntryBlockBase` exposes `entry` as an input signal of that name.

`LinkReaderNavigation` is provided by the shell. The magazine and the article view both live under it; components rendered outside the shell (none today) would fail to inject it, and the specs provide a stub.

- [ ] **Step 4: Run the specs and see them pass.** Run `docker compose exec -T frontend npx jest src/app/reader`.

- [ ] **Step 5: Commit.**

```bash
git add -A frontend/src/app/reader/link-card frontend/src/app/reader/article/link-cards frontend/src/app/reader/list/magazine
git commit -m "feat(#1664): an open-in-reader button on entry link cards"
```

---

### Task 11: Playwright smoke

**Files:**
- Create: `frontend/e2e/link-card-reader.spec.ts`

- [ ] **Step 1: Write the smoke.** Follow `frontend/e2e/magazine-kicker-one-line.spec.ts`: it owns its data by stubbing routes, and leaves the fixture as it was found. Stub the list endpoint to return one post entry with one link card (read the stub in that spec for the response shape). Stub `**/api/entries/*/link-cards/0/reader` to return an ok payload (`contentHtml: '<p>Linked page body</p>'`). Then, at a desktop viewport:
1. On the magazine card, click `Open in reader`. Expect the link reader to show "Linked page body", the URL to contain `link=<id>.0` and no `entry=`.
2. Click back. Expect the magazine with no reader open.
3. Open the post (click the card's block), then click the card's button in the article view. Expect `entry=` and `link=` both present.
4. Click back. Expect the post's reader view, with `link=` gone.

Use one viewport for the whole spec; never resize mid-test (house rule).

- [ ] **Step 2: Run it.** With the Docker stack up, run `cd frontend && npx playwright test e2e/link-card-reader.spec.ts`. Expected: PASS. This suite is outside the CI gate on purpose; record the run in the PR body.

- [ ] **Step 3: Commit.**

```bash
git add frontend/e2e/link-card-reader.spec.ts
git commit -m "test(#1664): smoke the link card reader from the magazine and the article"
```

---

### Task 12: Verification and PR

- [ ] **Step 1: Merge in the latest `develop`.** Run `git fetch origin develop && git merge origin/develop` and resolve any conflicts.
- [ ] **Step 2: Run the backend gates.** From `backend/`: `composer check`, `composer md` (every touched `src` file PHPMD-clean), `composer test:parallel` (SQLite) and `docker compose exec php composer test` (MySQL), then `composer infection:diff`. Paste the failing output, if any; don't summarise it.
- [ ] **Step 3: Run the frontend gate.** Run `docker compose exec -T frontend npm run check`.
- [ ] **Step 4: Scan the dev log.** Run `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'` after exercising the endpoint. Expect no new deprecations or errors.
- [ ] **Step 5: Check the real browser.** Confirm the containers serve this branch first (standing rule). In the in-app browser at a mobile viewport and at desktop:
  - Open from a magazine card (standalone; back goes to the list, whose scroll is kept).
  - Open from the article view (layered; back goes to the post at its scroll).
  - Open a card that fails extraction (failure state, card image, primary Open original).
  - Tap the card body itself (the original still opens in a new tab).
  - Check that comment cards show no button.
  - Check that a link inside the linked article opens in a new tab.
  - Deep-link `?link=<id>.99` (not-found state).
  - Swipe back on the phone (pops only the link layer).
- [ ] **Step 6: Open the PR** into `develop`. Title: `feat(#1664): open link preview cards in the internal reader`. The body says `Closes #1664`, lists the two refactor commits as reviewable on their own, gives the §6 checklist result, and records the Playwright run.

---

## Self-Review

**Spec coverage** (issue #1664 → tasks):

| Requirement | Task |
|---|---|
| Card-bound endpoint (entry + position) | 1, 3 |
| Ownership checked before the limiter; shared limiter | 3 |
| No coverage gate; card image leads | 2, 3, 8 |
| No server cache; JSON and problem+json | 3 |
| Client cache under `card:` keys, same cap and versioning | 7 |
| `?link=` layered vs standalone; cleared on list change and entry open | 6, 9 |
| One level only | 8 (no `app-link-cards`), 4 (body links `_blank`) |
| Reduced chrome, header from card while loading | 8 |
| Failure state with Open original, never silent | 8 |
| Split pane, phone fullscreen, swipe-back pops only the link | 5, 9 |
| No highlight or viewed marking for standalone | 9 (`openEntryId` null; nothing calls `openOriginal`) |
| Not-found deep link | 3 (404), 8 (spec item 4) |
| Button on entry cards only, always visible, tap target, stops propagation | 10 |
| Shared article frame and content (Q20 b) | 4, 5 |

**Not-found state:** a 404 is a transport error to `HttpClient`, so `?link=<id>.99` lands in Task 8's failure state, with the HTTP detail behind "show error" and no Open original link. The entry reader has no dedicated not-found view either (an unknown `?entry=` shows the placeholder), so this is the equivalent. Task 8 spec item 4 pins it.

**Placeholder scan:** Tasks 4 and 5 move existing code rather than restating 600 lines of SCSS and template. Each move names its exact source block and destination, which is a concrete instruction, not a placeholder. Where a token or class must be confirmed in the tree first (Task 10's scrim tokens, Task 8's primary link style), the step gives the grep and the rule for choosing.

**Type consistency:** these names are used identically across tasks 6–10: `LinkCardRef { entryId, position }`, `linkParam`, `linkCardFromParam`, `sameLinkCard`, `ReaderRouteState.linkCard`, `LinkReaderNavigation.layer/openAlone/close`, `ReaderCacheKey`, `ReaderContentService.loadLinkCard/reloadLinkCard`, `ArticleLayerComponent.scroller/leaving/resetChrome/scrollToTop/back`, `ArticleContentComponent.element`, and `LinkCardComponent.openableInReader/openInReader`.
