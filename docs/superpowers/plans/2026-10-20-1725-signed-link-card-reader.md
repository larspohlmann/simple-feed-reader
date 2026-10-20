# Signed Link-Card Reader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every link card (root post, self-reply, social-media comment) opens in the internal reader through one
endpoint that extracts a URL only when it carries the server's signature, replacing the entry/position lookup.

**Architecture:** The server signs each link card's URL (HMAC-SHA256, key derived from `kernel.secret`) and sends the
token with the card as `readerToken`. `GET /api/link-cards/reader?url=&token=` verifies it and extracts the page. The
client keys the link layer (`?link=<token>~<url>`) and its article cache by the URL. The card's stored `position` stops
going over the wire. Since the JSON mappers that emit cards must now hold the signature, they become injected services.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12, PHPStan max, PHPMD codesize, phptramp), Angular 20 signals + Jest,
Playwright e2e.

**Spec:** GitHub issue #1725, plus the decisions below (agreed in chat on 2026-10-20; there is no separate spec file).

## Global Constraints

- Branch `feature/1725-signed-link-card-reader` off `develop`; commits are `type(#1725): …`; no Claude attribution.
- Token = unpadded base64url of `hash_hmac('sha256', $url, hash_hkdf('sha256', kernel.secret, 0, 'link-card-reader'), true)`, which is always 43 chars, `[A-Za-z0-9_-]`.
- The token signs the URL **alone**. It is not bound to a user or an entry, and it does not expire.
- A token that does not match is answered `404 application/problem+json`, before any rate-limit spend or fetch.
- The endpoint keeps the reader's per-user rate limit (`limiter.reader`, `RateLimitGuard::enforceForUser`).
- The server no longer passes the card's title as an extraction hint, and no longer returns a lead image (`originalHero: null`). The client leads a failed page with the image of the card it holds.
- No `@phpstan-ignore`, no baseline, every touched `src` file PHPMD-clean, no new comments unless the CLAUDE.md bar is met.
- HTTP statuses in tests are `Response::HTTP_*`, never literals.
- Frontend tests run inside the Docker frontend container: `docker compose exec -T frontend npx jest <path>`.

## File Map

Backend
- `src/Service/LinkCard/LinkCardSignature.php`, `Exception/LinkCardTokenMismatchException.php`, `src/Http/Problem/ExceptionProblems/LinkCardProblems.php`: **done in Task 1**.
- `src/Controller/Api/LinkCardReaderController.php`: rewritten for the new route.
- `src/Service/Reader/LinkCardArticleReader.php`: `read(string $url)`; `lead()` goes.
- `src/Repository/LinkCardRepository.php`: `contentAt()` and `placedByEntryIds()` go.
- `src/Repository/ThreadPostRepository.php`, `EntryListRowEnrichment.php`, `EntryListRowEnricher.php`, `src/Service/Social/Model/ThreadPostModel.php`: carry `LinkCardContent`.
- `src/Service/LinkCard/Model/PlacedLinkCardModel.php`: deleted.
- `src/Http/{LinkCardJson,ThreadPostJson,CommentsJson,EntryJson,EntryPage,SearchPage,SavedSearchPage,RecommendationFeedJson}.php`: become instance services.
- `src/Controller/Api/EntryDetailController.php` (new): takes `GET /api/entries/{id}` out of `EntryController`, which would otherwise reach PHPMD's 10-parameter limit.
- `tests/Support/JsonMappers.php` (new): builds the mapper graph for unit tests.

Frontend (`frontend/src/app/reader/…`)
- `link-card/link-card.ts`: `readerToken`; `PlacedLinkCardDto` goes.
- `link-card/link-card-at.ts`, and its spec, are replaced by `link-card/link-card-with-url.ts`.
- `query/link-param.ts`: `LinkCardRef = { url, token }`.
- `shell/link-reader-navigation.service.ts`: takes a card and remembers it.
- `shell/reader-route-state.service.ts`: `openLinkCard` resolves by URL.
- `reader-api.ts`, `article/content/reader-cache.service.ts`, `article/link-reader/link-article-source.service.ts` (+ its template use of `lead`).
- Call sites: `article/link-cards`, `article/thread-replies`, `list/entry-row`, `list/magazine/blocks/entry-link`, `list/magazine/blocks/entry-thread`, `article/entry-comments`.
- `src/testing/entry-testing.ts`, `e2e/support/reader.ts`, `e2e/link-card-reader.spec.ts`, `e2e/self-reply-thread.spec.ts`.

---

### Task 1: LinkCardSignature — DONE (commit 56d676d08)

`App\Service\LinkCard\LinkCardSignature`, constructor `#[Autowire('%kernel.secret%')] string $secret`:
- `tokenFor(string $url): string`
- `verify(string $url, string $token): void`, which throws `App\Service\LinkCard\Exception\LinkCardTokenMismatchException`.

`LinkCardProblems` maps that exception to 404, and `tests/Service/LinkCard/LinkCardSignatureTest.php` covers it.
Nothing is left to do here; later tasks consume these signatures.

---

### Task 2: One signed reader endpoint

**Files:**
- Modify: `backend/src/Controller/Api/LinkCardReaderController.php` (whole file)
- Modify: `backend/src/Service/Reader/LinkCardArticleReader.php` (whole file)
- Modify: `backend/src/Repository/LinkCardRepository.php`: delete `contentAt()`; drop the `Subscription` and `RecordNotFoundException` imports
- Test: `backend/tests/Controller/Api/LinkCardReaderControllerTest.php` (rewrite), `backend/tests/Service/Reader/LinkCardArticleReaderTest.php` (rewrite), `backend/tests/Repository/LinkCardRepositoryTest.php`

**Interfaces:**
- Consumes: `LinkCardSignature::verify(string $url, string $token): void` (Task 1).
- Produces: `GET /api/link-cards/reader?url=<url>&token=<token>`, route name `api_link_card_reader`, answering the existing `ReaderJson::one()` shape with `originalHero: null`; and `LinkCardArticleReader::read(string $url): ExtractionResultModel`.

- [ ] **Step 1: Write the failing controller test.** Replace `tests/Controller/Api/LinkCardReaderControllerTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\User;
use App\Service\LinkCard\LinkCardSignature;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\ReaderEndpointFixtures;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LinkCardReaderControllerTest extends ApiTestCase
{
    use ReaderEndpointFixtures;

    private const string URL = 'https://example.com/article';

    protected function setUp(): void
    {
        $this->clearReaderRateLimiter();
    }

    public function testExtractsTheSignedCardsPage(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-ok@example.com');
        $fake = $this->installFake();
        $fake->willReturn(ExtractionResultModel::ok(
            url: self::URL,
            title: 'Extracted',
            byline: null,
            siteName: 'Example',
            contentHtml: '<p>Body</p>',
            excerpt: null,
        ));

        $client->request('GET', self::readerPath(self::URL, $this->tokenFor(self::URL)), server: $headers);

        self::assertResponseIsSuccessful();
        $body = $this->payload($client);
        self::assertSame('ok', $body['status']);
        self::assertSame('<p>Body</p>', $body['contentHtml']);
        self::assertNull($body['originalHero']);
        self::assertSame([self::URL], $fake->calls);
    }

    public function testAFailedExtractionIsAnsweredWithoutALead(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-fail@example.com');
        $this->installFake()->willReturn(
            ExtractionResultModel::failed(self::URL, ExtractionFailure::Fetch, 'HTTP 403 Forbidden'),
        );

        $client->request('GET', self::readerPath(self::URL, $this->tokenFor(self::URL)), server: $headers);

        $body = $this->payload($client);
        self::assertSame('failed', $body['status']);
        self::assertNull($body['originalHero']);
    }

    public function testAForgedTokenIsNotFoundAndSpendsNoBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-forged@example.com');
        $fake = $this->installFake();
        $this->spendReaderBudget($user);

        $client->request('GET', self::readerPath(self::URL, str_repeat('A', 43)), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertSame([], $fake->calls);
    }

    public function testAnotherCardsTokenIsNotFound(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-swapped@example.com');
        $fake = $this->installFake();

        $client->request(
            'GET',
            self::readerPath('https://elsewhere.example/page', $this->tokenFor(self::URL)),
            server: $headers,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $fake->calls);
    }

    public function testAMissingTokenIsNotFound(): void
    {
        $client = self::createClient();
        [$headers] = $this->auth('card-reader-tokenless@example.com');
        $fake = $this->installFake();

        $client->request('GET', '/api/link-cards/reader?' . http_build_query(['url' => self::URL]), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $fake->calls);
    }

    public function testSharesTheEntryReadersBudget(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('card-reader-limit@example.com');
        $fake = $this->installFake();
        $this->spendReaderBudget($user);

        $client->request('GET', self::readerPath(self::URL, $this->tokenFor(self::URL)), server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSame([], $fake->calls);
    }

    public function testRequiresSigningIn(): void
    {
        $client = self::createClient();

        $client->request('GET', self::readerPath(self::URL, str_repeat('A', 43)));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array{0: array<string,string>, 1: User} */
    private function auth(string $email): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($entityManager, $hasher))->create($email);

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    private function tokenFor(string $url): string
    {
        $signature = self::getContainer()->get(LinkCardSignature::class);
        self::assertInstanceOf(LinkCardSignature::class, $signature);

        return $signature->tokenFor($url);
    }

    private static function readerPath(string $url, string $token): string
    {
        return '/api/link-cards/reader?' . http_build_query(['url' => $url, 'token' => $token]);
    }
}
```

- [ ] **Step 2: Run it to see it fail.** Run `cd backend && php bin/phpunit tests/Controller/Api/LinkCardReaderControllerTest.php`.
  Expected: FAIL. The new route gives 404 everywhere, so `testExtractsTheSignedCardsPage` and the 429 test fail.

- [ ] **Step 3: Rewrite the article reader and its test.** Replace `src/Service/Reader/LinkCardArticleReader.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\ArticleExtractor\ArticleExtractorInterface;
use App\Service\Reader\Model\EntryHintsModel;
use App\Service\Reader\Model\ExtractionResultModel;

final readonly class LinkCardArticleReader
{
    public function __construct(private ArticleExtractorInterface $extractor)
    {
    }

    public function read(string $url): ExtractionResultModel
    {
        return $this->extractor->extract($url, new EntryHintsModel());
    }
}
```

Then replace `tests/Service/Reader/LinkCardArticleReaderTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LinkCardArticleReader;
use App\Service\Reader\Model\ExtractionResultModel;
use App\Tests\Support\FakeArticleExtractor;
use PHPUnit\Framework\TestCase;

final class LinkCardArticleReaderTest extends TestCase
{
    public function testExtractsTheUrlWithoutHints(): void
    {
        $extractor = new FakeArticleExtractor();
        $extracted = ExtractionResultModel::ok('https://example.com/a', 'A', null, null, '<p>Body</p>', null);
        $extractor->willReturn($extracted);

        $result = (new LinkCardArticleReader($extractor))->read('https://example.com/a');

        self::assertSame($extracted, $result);
        self::assertSame(['https://example.com/a'], $extractor->calls);
        self::assertNull($extractor->hints[0]->title);
    }
}
```

- [ ] **Step 4: Rewrite the controller.** Replace `src/Controller/Api/LinkCardReaderController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\ReaderJson;
use App\Service\LinkCard\LinkCardSignature;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Reader\LinkCardArticleReader;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/link-cards')]
final readonly class LinkCardReaderController
{
    public function __construct(
        private LinkCardSignature $signature,
        private ClockInterface $clock,
        private LinkCardArticleReader $articleReader,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $readerLimiter,
    ) {
    }

    #[Route('/reader', name: 'api_link_card_reader', methods: ['GET'])]
    public function reader(
        #[MapQueryParameter] string $url,
        #[MapQueryParameter] string $token,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->signature->verify($url, $token);
        $this->rateLimitGuard->enforceForUser($this->readerLimiter, $user);

        return new JsonResponse(ReaderJson::one($this->articleReader->read($url), null, $this->clock->now()));
    }
}
```

- [ ] **Step 5: Delete `contentAt()` and its tests.** In `src/Repository/LinkCardRepository.php`:
  - Delete the whole `contentAt()` method.
  - Delete `use App\Entity\Subscription;` and `use App\Repository\Exception\RecordNotFoundException;`.

  In `tests/Repository/LinkCardRepositoryTest.php`:
  - Delete the four `contentAt` tests: `testFindsTheCardAtAPositionForASubscriber`, `testAPositionPastTheLastCardIsNotFound`, `testAnotherEntrysCardIsNotFound`, `testACardOfAFeedTheUserDoesNotSubscribeToIsNotFound`.
  - Delete the `RecordNotFoundException` import, and the `subscriberOf()` helper if nothing else uses it.
  - In `testAThreadCardTakesTheNextPositionAndStaysOutOfTheRootCards`, delete the `$subscriber` line. Replace its final `contentAt` assertion with:

```php
        $replyCard = $this->entityManager->getRepository(LinkCard::class)->findOneBy(['threadPost' => $post]);
        self::assertInstanceOf(LinkCard::class, $replyCard);
        self::assertSame(1, $replyCard->getPosition());
```

  (Add `use App\Entity\LinkCard;` if the file lacks it.)

- [ ] **Step 6: Run the touched tests and make sure they pass.** Run:
  `php bin/phpunit tests/Controller/Api/LinkCardReaderControllerTest.php tests/Service/Reader/LinkCardArticleReaderTest.php tests/Repository/LinkCardRepositoryTest.php`.
  Expected: PASS.

- [ ] **Step 7: Commit.** Run:

```bash
git add backend/src backend/tests
git commit -m "feat(#1725): one reader endpoint for every link card, trusting a signed URL instead of a stored position"
```

---

### Task 3: The JSON mappers become injected services

There is no behaviour change in this task: the JSON output is byte-identical. It is a mechanical move from static mappers to instances.

**Files:**
- Modify: `backend/src/Http/LinkCardJson.php`, `ThreadPostJson.php`, `CommentsJson.php`, `EntryJson.php`, `EntryPage.php`, `SearchPage.php`, `SavedSearchPage.php`, `RecommendationFeedJson.php`
- Create: `backend/src/Controller/Api/EntryDetailController.php`
- Modify: `backend/src/Controller/Api/EntryController.php`, `EntryCompletionController.php`, `EntryCommentsController.php`, `EntrySearchController.php`, `SavedSearchEntriesController.php`
- Create: `backend/tests/Support/JsonMappers.php`
- Modify tests: `backend/tests/Http/{EntryJsonTest,EntryPageTest,SearchPageTest,SavedSearchPageTest,ThreadPostJsonTest,CommentsJsonTest,RecommendationFeedJsonTest}.php`, `backend/tests/Service/Recommendation/Feed/ForYouFeedTest.php`, `backend/tests/Service/Recommendation/Scoring/{ScoringPipelineTest,RerankPipelineTest}.php`

**Interfaces:**
- Consumes: `LinkCardSignature` (Task 1).
- Produces, each a `final readonly class` constructed by the container:
  - `LinkCardJson()`, with no constructor yet (Task 4 adds `LinkCardSignature`) and public instance `of()`, `placed()` and `ofOrNull()`, unchanged in output.
  - `ThreadPostJson(LinkCardJson $linkCardJson)`: `of(ThreadPostModel $post): array`.
  - `CommentsJson(LinkCardJson $linkCardJson)`: `one(CommentsResultModel $result): array`.
  - `EntryJson(LinkCardJson $linkCardJson, ThreadPostJson $threadPostJson)`: `listRow()`, `completed()`, `detail()`.
  - `EntryPage(EntryJson $entryJson)`: `of()`, `withMatchCount()`.
  - `SearchPage(EntryPage $entryPage)`: `of()`.
  - `SavedSearchPage(EntryPage $entryPage)`: `of()`.
  - `RecommendationFeedJson(EntryJson $entryJson)`: `page()`.
  - Test helper `App\Tests\Support\JsonMappers`, whose static `linkCardJson()`, `threadPostJson()`, `commentsJson()`, `entryJson()`, `entryPage()`, `searchPage()`, `savedSearchPage()` and `recommendationFeedJson()` build the graph. Task 4 adds `SECRET` and the signature.

- [ ] **Step 1: Convert each mapper.** The same edits apply to all eight classes in `src/Http`:
  - Declare it `final readonly class`.
  - Delete the `private function __construct() {}`.
  - Add a promoted constructor for the collaborators listed under Interfaces.
  - Turn each public `static function` into an instance `function`.
  - Inside each class, call collaborators as instance methods:
    - `LinkCardJson::placed(...)` → `$this->linkCardJson->placed(...)`, and `LinkCardJson::ofOrNull(...)` → `$this->linkCardJson->ofOrNull(...)`
    - `ThreadPostJson::of(...)` → `$this->threadPostJson->of(...)`
    - `EntryJson::listRow(...)` → `$this->entryJson->listRow(...)`
    - `EntryPage::of(...)` / `EntryPage::withMatchCount(...)` → `$this->entryPage->…`
  - Self-calls to public methods become `$this->…`. In `EntryJson` these are `self::completed($row)`, `self::listRow(...)` (the `duplicates` map) and `self::listRow($row)` in `detail`. In `EntryPage` it is `self::withMatchCount`.
  - Private static helpers that touch no collaborator stay `private static`: `EntryJson::discussionOf`/`posterOf`/`socialOf`, `EntryPage::cursorFromRow`, `CommentsJson::post`, `RecommendationFeedJson::entries` (make this one an instance method, since it calls `$this->entryJson`).
  - `LinkCardJson` gets no constructor in this task; Task 4 adds `LinkCardSignature`.

- [ ] **Step 2: Split `get` out of `EntryController`.** Create `src/Controller/Api/EntryDetailController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\EntryJson;
use App\Repository\EntryListRepository;
use App\Repository\EntryListRowEnricher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/entries')]
final readonly class EntryDetailController
{
    public function __construct(
        private EntryListRepository $entryList,
        private EntryListRowEnricher $enricher,
        private EntryJson $entryJson,
    ) {
    }

    #[Route('/{id}', name: 'api_entries_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $row = $this->entryList->getRowForUser($user->requireId(), $id);
        $row = $this->enricher->enrich([$row], $user->requireId())[0];

        return new JsonResponse([
            'entry' => $this->entryJson->detail($row),
            'completed' => $this->entryJson->completed($row),
        ]);
    }
}
```

  Delete `get()` from `EntryController`, along with any imports only it used (`EntryJson`).
  Add `private EntryPage $entryPage, private RecommendationFeedJson $recommendationFeedJson` to its constructor, then:
  - `RecommendationFeedJson::page(` → `$this->recommendationFeedJson->page(`
  - `EntryPage::of(` → `$this->entryPage->of(`

- [ ] **Step 3: Inject into the other controllers.**
  - `EntryCompletionController`: add `private EntryJson $entryJson`; use `array_map($this->entryJson->completed(...), $rows)`.
  - `EntryCommentsController`: add `private CommentsJson $commentsJson`; use `$this->commentsJson->one($result)`.
  - `EntrySearchController`: add `private SearchPage $searchPage`; use `$this->searchPage->of(...)`.
  - `SavedSearchEntriesController`: add `private SavedSearchPage $savedSearchPage`; change both calls to `$this->savedSearchPage->of(...)`.

- [ ] **Step 4: Add the test helper** `tests/Support/JsonMappers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\CommentsJson;
use App\Http\EntryJson;
use App\Http\EntryPage;
use App\Http\LinkCardJson;
use App\Http\RecommendationFeedJson;
use App\Http\SavedSearchPage;
use App\Http\SearchPage;
use App\Http\ThreadPostJson;

final class JsonMappers
{
    public static function linkCardJson(): LinkCardJson
    {
        return new LinkCardJson();
    }

    public static function threadPostJson(): ThreadPostJson
    {
        return new ThreadPostJson(self::linkCardJson());
    }

    public static function commentsJson(): CommentsJson
    {
        return new CommentsJson(self::linkCardJson());
    }

    public static function entryJson(): EntryJson
    {
        return new EntryJson(self::linkCardJson(), self::threadPostJson());
    }

    public static function entryPage(): EntryPage
    {
        return new EntryPage(self::entryJson());
    }

    public static function searchPage(): SearchPage
    {
        return new SearchPage(self::entryPage());
    }

    public static function savedSearchPage(): SavedSearchPage
    {
        return new SavedSearchPage(self::entryPage());
    }

    public static function recommendationFeedJson(): RecommendationFeedJson
    {
        return new RecommendationFeedJson(self::entryJson());
    }
}
```

- [ ] **Step 5: Move the tests onto instances.** In each test, replace the static calls with calls on the helper:

```bash
cd backend
sed -i '' -E 's/EntryJson::(listRow|completed|detail)\(/JsonMappers::entryJson()->\1(/g' tests/Http/EntryJsonTest.php
sed -i '' -E 's/EntryPage::(of|withMatchCount)\(/JsonMappers::entryPage()->\1(/g' tests/Http/EntryPageTest.php
sed -i '' -E 's/SearchPage::of\(/JsonMappers::searchPage()->of(/g' tests/Http/SearchPageTest.php
sed -i '' -E 's/SavedSearchPage::of\(/JsonMappers::savedSearchPage()->of(/g' tests/Http/SavedSearchPageTest.php
sed -i '' -E 's/ThreadPostJson::of\(/JsonMappers::threadPostJson()->of(/g' tests/Http/ThreadPostJsonTest.php
sed -i '' -E 's/CommentsJson::one\(/JsonMappers::commentsJson()->one(/g' tests/Http/CommentsJsonTest.php
sed -i '' -E 's/RecommendationFeedJson::page\(/JsonMappers::recommendationFeedJson()->page(/g' tests/Http/RecommendationFeedJsonTest.php tests/Service/Recommendation/Feed/ForYouFeedTest.php tests/Service/Recommendation/Scoring/ScoringPipelineTest.php tests/Service/Recommendation/Scoring/RerankPipelineTest.php
```

  Then, in each of those files, add `use App\Tests\Support\JsonMappers;`. Drop the mapper's own `use` only where it
  is now unused, which is every file except one that still names the class, for instance in an `instanceof`.
  `SavedSearchPageTest`'s `SearchPage` sed must not touch `SavedSearchPage::of(`; the `SearchPage` sed runs only on
  `SearchPageTest.php`, so it doesn't. The PhpStan fixtures (`tests/PhpStan/data/*`) refer to `::class` only: leave them alone.

- [ ] **Step 6: Run the suite and the gates.**
  - `php bin/phpunit` (SQLite): expected PASS, with the same count as before minus nothing.
  - `composer stan`: expected 0 errors. This includes `ThinControllerRule` and `ServiceRoleRule`, which already admits instance `*Json` mappers (see `MeProfileJson`).
  - `composer md`: expected no finding in any touched file.
  - `composer cs` and `composer tramp`: expected clean.

- [ ] **Step 7: Commit.** Run:

```bash
git add backend/src backend/tests
git commit -m "refactor(#1725): the entry and comment JSON mappers become injected services"
```

---

### Task 4: Cards carry a reader token instead of their position

**Files:**
- Modify: `backend/src/Http/LinkCardJson.php`, `ThreadPostJson.php`, `EntryJson.php`, `CommentsJson.php`
- Modify: `backend/src/Repository/LinkCardRepository.php`: delete `placedByEntryIds()` and its `PlacedLinkCardModel` import
- Modify: `backend/src/Repository/ThreadPostRepository.php`: `cardsByPostId()` returns `array<int, LinkCardContent>`
- Modify: `backend/src/Repository/EntryListRowEnricher.php:54`: `placedByEntryIds` → `contentsByEntryIds`
- Modify: `backend/src/Repository/EntryListRowEnrichment.php`, `backend/src/Service/Social/Model/ThreadPostModel.php`
- Delete: `backend/src/Service/LinkCard/Model/PlacedLinkCardModel.php`
- Tests: `backend/tests/Support/JsonMappers.php`, `backend/tests/Http/{EntryJsonTest,CommentsJsonTest,ThreadPostJsonTest}.php`, `backend/tests/Repository/{LinkCardRepositoryTest,EntryListRowEnricherTest}.php`, `backend/tests/Controller/Api/{EntryControllerTest,EntryCommentsControllerTest}.php`

**Interfaces:**
- Consumes: `LinkCardSignature::tokenFor()` (Task 1) and the injected mappers (Task 3).
- Produces: every card on the wire is `{url, title, description, imageUrl, siteName, readerToken}`, in that key order; there is no `position` key anywhere. `LinkCardJson::of(LinkCardContent): LinkCardRow` and `ofOrNull()` remain; `placed()` is gone. `EntryListRowEnrichment::$linkCards` is a `list<LinkCardContent>`, and `ThreadPostModel::$linkCard` is a `?LinkCardContent`.

- [ ] **Step 1: Write the failing tests.**
  - In `tests/Http/EntryJsonTest.php`, replace `testACardCarriesItsStoredPosition` with:

```php
    public function testACardCarriesTheTokenThatOpensItInTheReader(): void
    {
        $card = new LinkCardContent('https://i.example/chart', 'Chart', null, null, 'i.example');
        $row = $this->row($this->entryTitled('Carded'), new EntryListRowEnrichment(linkCards: [$card]));

        self::assertSame(
            [
                'url' => 'https://i.example/chart',
                'title' => 'Chart',
                'description' => null,
                'imageUrl' => null,
                'siteName' => 'i.example',
                'readerToken' => (new LinkCardSignature(JsonMappers::SECRET))->tokenFor('https://i.example/chart'),
            ],
            JsonMappers::entryJson()->listRow($row)['linkCards'][0],
        );
    }
```

  - In `testARowCarriesItsThreadWithEachRepliesCardPosition`, rename the test to `testARowCarriesItsThreadWithEachRepliesSignedCard`. Pass `$card` instead of `new PlacedLinkCardModel(3, $card)`. Replace the `position` assertion with:

```php
        self::assertSame(
            (new LinkCardSignature(JsonMappers::SECRET))->tokenFor('https://m.example/a'),
            $thread[0]['linkCard']['readerToken'] ?? null,
        );
```

  - Replace every remaining `new PlacedLinkCardModel(<n>, $card)` in `EntryJsonTest` with `$card`, and drop the import.
  - In `tests/Http/CommentsJsonTest.php`, in the test around line 36 that builds `$card`, add:

```php
        self::assertSame(
            (new LinkCardSignature(JsonMappers::SECRET))->tokenFor('https://site.test/a'),
            $json['comments'][0]['linkCard']['readerToken'] ?? null,
        );
```

    Adapt the `$json` access to the test's existing variable. If it asserts the full card array, add the
    `'readerToken' => …` key instead.
  - In `tests/Controller/Api/EntryControllerTest.php::expectedLinkCards()`, replace both `'position' => 0|1` lines with
    `'readerToken' => $this->readerTokenFor('<that card's url>')`, and add the helper:

```php
    private function readerTokenFor(string $url): string
    {
        $signature = self::getContainer()->get(LinkCardSignature::class);
        self::assertInstanceOf(LinkCardSignature::class, $signature);

        return $signature->tokenFor($url);
    }
```

  - In `tests/Controller/Api/EntryCommentsControllerTest.php::testAMastodonPostsRepliesComeFromItsOriginWithoutAnAccount`, add:

```php
        $firstCard = $body['comments'][0]['linkCard'] ?? null;
        self::assertIsArray($firstCard);
        self::assertSame('https://example.org/review', $firstCard['url']);
        self::assertSame($this->readerTokenFor('https://example.org/review'), $firstCard['readerToken']);
```

    Add the same `readerTokenFor()` helper here.

- [ ] **Step 2: Run them to see them fail.** Run
  `php bin/phpunit tests/Http tests/Controller/Api/EntryControllerTest.php tests/Controller/Api/EntryCommentsControllerTest.php`.
  Expected: FAIL. `PlacedLinkCardModel` is still required, and there is no `readerToken` key yet.

- [ ] **Step 3: Implement.** `src/Http/LinkCardJson.php` becomes:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\LinkCardContent;
use App\Service\LinkCard\LinkCardSignature;

/**
 * @phpstan-type LinkCardRow array{
 *   url: string, title: string, description: string|null, imageUrl: string|null, siteName: string,
 *   readerToken: string
 * }
 */
final readonly class LinkCardJson
{
    public function __construct(private LinkCardSignature $signature)
    {
    }

    /** @return LinkCardRow */
    public function of(LinkCardContent $card): array
    {
        return [
            'url' => $card->url,
            'title' => $card->title,
            'description' => $card->description,
            'imageUrl' => $card->imageUrl,
            'siteName' => $card->siteName,
            'readerToken' => $this->signature->tokenFor($card->url),
        ];
    }

    /** @return LinkCardRow|null */
    public function ofOrNull(?LinkCardContent $card): ?array
    {
        return $card === null ? null : $this->of($card);
    }
}
```

  Then:
  - `ThreadPostJson`: `'linkCard' => $this->linkCardJson->ofOrNull($post->linkCard)`, and in its phpstan-types `PlacedLinkCardRow` → `LinkCardRow` (`@phpstan-import-type LinkCardRow from LinkCardJson`).
  - `EntryJson::completed`: `'linkCards' => array_map($this->linkCardJson->of(...), $row->enrichment->linkCards)`, and `list<PlacedLinkCardRow>` → `list<LinkCardRow>` in its phpstan-type, including the import line.
  - `EntryListRowEnrichment`: `/** @var list<LinkCardContent> the root post's links, in post order */`, with the import `App\Entity\LinkCardContent` instead of `PlacedLinkCardModel`.
  - `ThreadPostModel`: `public ?LinkCardContent $linkCard`.
  - `ThreadPostRepository::cardsByPostId()`: return type doc `array<int, LinkCardContent>`. Drop `'card.position'` from the select and `position` from the row shape; keep the `orderBy('card.position', 'ASC')`. Build with `$cards[(int) $row['postId']] = LinkCardContent::fromRow($row);`, renaming `$placed` → `$cards`.
  - `EntryListRowEnricher:54`: `$this->linkCards->contentsByEntryIds($entryIds)`.
  - `LinkCardRepository`: delete `placedByEntryIds()`. `rootCardRows()` keeps `position` only if `contentsByEntryIds` needs it; it does not, so drop `'card.position'` from that select and from both shape docs, keeping the `orderBy`.
  - Delete `src/Service/LinkCard/Model/PlacedLinkCardModel.php`. If `src/Service/LinkCard/Model/` is then empty, delete the folder.
  - In `tests/Support/JsonMappers.php`, add `use App\Service\LinkCard\LinkCardSignature;` and
    `public const string SECRET = 'test-link-card-secret';`, and make `linkCardJson()` return
    `new LinkCardJson(new LinkCardSignature(self::SECRET));`.
  - In `EntryJsonTest` and `CommentsJsonTest`, add `use App\Service\LinkCard\LinkCardSignature;`; in
    `EntryControllerTest` and `EntryCommentsControllerTest`, add the same import for `readerTokenFor()`.

- [ ] **Step 4: Fix the repository tests that read positions through `placedByEntryIds`.** In `LinkCardRepositoryTest`, `testRootCardsAppendedAfterAThreadCardDoNotCollide` and `testUnflushedCardsCountWhenNumberingTheNext` become position reads on the entity:

```php
        self::assertSame([1, 2], $this->rootPositionsOf($entry));
```

  and `self::assertSame([0, 1], $this->rootPositionsOf($entry));`, with the helper:

```php
    /** @return list<int> */
    private function rootPositionsOf(Entry $entry): array
    {
        $cards = $this->entityManager->getRepository(LinkCard::class)
            ->findBy(['entry' => $entry, 'threadPost' => null], ['position' => 'ASC']);

        return array_map(static fn (LinkCard $card): int => $card->getPosition(), $cards);
    }
```

  Then:
  - Drop the `PlacedLinkCardModel` import.
  - In `EntryListRowEnricherTest`, change the `@param list<PlacedLinkCardModel>` helper to `list<LinkCardContent>` and map `static fn (LinkCardContent $card): string => $card->url`.

- [ ] **Step 5: Run the tests and the gates.** Run `php bin/phpunit` (expected PASS), then
  `composer stan && composer cs && composer md && composer tramp` (expected clean).
  Grep to confirm nothing is left:

```bash
grep -rn "PlacedLinkCard\|placedByEntryIds\|contentAt\|'position' =>" src tests | grep -iv "tag\|subscription\|catalog\|category\|recommendation\|moveFeed"
```

  Expected: no link-card hit.

- [ ] **Step 6: Commit.** Run:

```bash
git add -A backend/src backend/tests
git commit -m "feat(#1725): a link card carries the token that opens it in the reader, not its stored position"
```

---

### Task 5: The client opens cards by signed URL

**Files (all under `frontend/`):**
- Modify: `src/app/reader/link-card/link-card.ts`, `src/app/reader/models.ts:231`, `src/app/reader/thread-post.ts:13`
- Delete: `src/app/reader/link-card/link-card-at.ts`, `src/app/reader/link-card/link-card-at.spec.ts`
- Create: `src/app/reader/link-card/link-card-with-url.ts`, `src/app/reader/link-card/link-card-with-url.spec.ts`
- Modify: `src/app/reader/query/link-param.ts` (+ spec), `src/app/reader/shell/link-reader-navigation.service.ts` (+ spec), `src/app/reader/shell/reader-route-state.service.ts`, `src/app/reader/reader-api.ts` (+ spec), `src/app/reader/article/content/reader-cache.service.ts` (+ `reader-cache.service.spec.ts`, `reader-content.service.spec.ts`), `src/app/reader/article/link-reader/link-article-source.service.ts` + `link-reader.component.html` (+ specs)
- Modify call sites (+ their specs): `article/link-cards/link-cards.component.{ts,html}`, `article/thread-replies/thread-replies.component.html`, `list/entry-row/entry-row.component.{ts,html}`, `list/magazine/blocks/entry-link/entry-link.component.{ts,html}`, `list/magazine/blocks/entry-thread/entry-thread.component.{ts,html}`
- Modify: `src/testing/entry-testing.ts`, `src/app/reader/shell/reader-shell.component.spec.ts` (link-reader block, about lines 4616–4714), `e2e/support/reader.ts`, `e2e/link-card-reader.spec.ts`, `e2e/self-reply-thread.spec.ts`

**Interfaces:**
- Consumes: the wire card `{url, title, description, imageUrl, siteName, readerToken}`, and `GET /api/link-cards/reader?url&token` (Tasks 2 and 4).
- Produces:
  - `LinkCardDto` gains `readerToken: string`.
  - `LinkCardRef { readonly url: string; readonly token: string }`, plus `linkParam(ref): string` = `` `${token}~${url}` ``, `linkCardFromParam(value): LinkCardRef | null`, `sameLinkCard(left, right): boolean`, and `refOf(card: LinkCardDto): LinkCardRef`.
  - `linkCardWithUrl(entries: readonly EntryDto[], url: string): LinkCardDto | null`.
  - `LinkReaderNavigation.layer(card: LinkCardDto)`, `openAlone(card: LinkCardDto)`, and `readonly opened: Signal<LinkCardDto | null>`.
  - `ReaderCacheKey = number | `card:${string}``.
  - `LinkArticleSource.lead: Signal<string | null>`, the card's image URL.

- [ ] **Step 1: Write the failing pure-function specs.** Replace `src/app/reader/query/link-param.spec.ts` with:

```ts
import { linkCardFromParam, linkParam, refOf, sameLinkCard } from './link-param';
import { aLinkCard, A_READER_TOKEN } from '../../../testing/entry-testing';

describe('link param', () => {
  const ref = { url: 'https://news.test/a~b?c=1', token: A_READER_TOKEN };

  it('round-trips a signed url, a tilde in the url included', () => {
    expect(linkCardFromParam(linkParam(ref))).toEqual(ref);
  });

  it('reads a card as its url and token', () => {
    expect(refOf(aLinkCard({ url: 'https://x.test/1' }))).toEqual({
      url: 'https://x.test/1',
      token: A_READER_TOKEN,
    });
  });

  it.each([
    null,
    '',
    '514.2',
    `${A_READER_TOKEN}`,
    `${A_READER_TOKEN}~`,
    `short~https://news.test/a`,
    `${A_READER_TOKEN}~javascript:alert(1)`,
    `${A_READER_TOKEN}~/relative`,
  ])('reads %p as no link', (value) => {
    expect(linkCardFromParam(value)).toBeNull();
  });

  it('compares by value', () => {
    expect(sameLinkCard({ ...ref }, { ...ref })).toBe(true);
    expect(sameLinkCard(ref, { ...ref, url: 'https://news.test/other' })).toBe(false);
    expect(sameLinkCard(null, null)).toBe(true);
  });
});
```

  Create `src/app/reader/link-card/link-card-with-url.spec.ts`:

```ts
import { anEntry, aLinkCard } from '../../../testing/entry-testing';
import { linkCardWithUrl } from './link-card-with-url';

describe('linkCardWithUrl', () => {
  const root = aLinkCard({ url: 'https://root.test/', title: 'Root' });
  const reply = aLinkCard({ url: 'https://reply.test/', title: 'Reply' });
  const entries = [
    anEntry({ id: 1 }),
    anEntry({
      id: 2,
      linkCards: [root],
      thread: [{ url: null, publishedAt: null, html: '', text: '', media: [], linkCard: reply }],
    }),
  ];

  it('finds a root card', () => {
    expect(linkCardWithUrl(entries, 'https://root.test/')?.title).toBe('Root');
  });

  it('finds a reply’s card', () => {
    expect(linkCardWithUrl(entries, 'https://reply.test/')?.title).toBe('Reply');
  });

  it('is null for a url no listed entry carries', () => {
    expect(linkCardWithUrl(entries, 'https://elsewhere.test/')).toBeNull();
  });
});
```

  Check the name of the entry builder in `src/testing/entry-testing.ts` (`anEntry` or similar) and the `ThreadPostDto`
  fields in `thread-post.ts`, and use exactly those.

- [ ] **Step 2: Run them to see them fail.** Run
  `docker compose exec -T frontend npx jest src/app/reader/query/link-param.spec.ts src/app/reader/link-card/link-card-with-url.spec.ts`.
  Expected: FAIL, because `refOf`, `A_READER_TOKEN` and `link-card-with-url` do not exist.

- [ ] **Step 3: Implement the types and pure functions.**
  - `src/app/reader/link-card/link-card.ts` becomes:

```ts
export interface LinkCardDto {
  url: string;
  title: string;
  description: string | null;
  imageUrl: string | null;
  siteName: string;
  /** The server's signature of `url`: what the reader endpoint and `?link=` require (#1725). */
  readerToken: string;
}
```

  - `models.ts:231` changes to `linkCards: LinkCardDto[];` and `thread-post.ts:13` to `linkCard: LinkCardDto | null;`, with imports updated.
  - `src/testing/entry-testing.ts`:

```ts
export const A_READER_TOKEN = 'a'.repeat(43);

export function aLinkCard(overrides: Partial<LinkCardDto> = {}): LinkCardDto {
  return {
    url: 'https://news.test/story',
    title: 'A shared story',
    description: null,
    imageUrl: null,
    siteName: 'news.test',
    readerToken: A_READER_TOKEN,
    ...overrides,
  };
}
```

  - `src/app/reader/query/link-param.ts` becomes:

```ts
import { LinkCardDto } from '../link-card/link-card';

/** The `?link=` value: a card's URL and the server's signature of it. */
export interface LinkCardRef {
  readonly url: string;
  readonly token: string;
}

const PARAM = /^([A-Za-z0-9_-]{43})~(https?:\/\/.+)$/;

export function refOf(card: LinkCardDto): LinkCardRef {
  return { url: card.url, token: card.readerToken };
}

export function linkParam(ref: LinkCardRef): string {
  return `${ref.token}~${ref.url}`;
}

export function linkCardFromParam(value: string | null): LinkCardRef | null {
  const match = PARAM.exec(value ?? '');
  return match === null ? null : { token: match[1], url: match[2] };
}

export function sameLinkCard(left: LinkCardRef | null, right: LinkCardRef | null): boolean {
  return left?.url === right?.url && left?.token === right?.token;
}

/** Opening or closing an entry drops the link layer above it. */
export function entryChangeParams(entry: string | null): { entry: string | null; link: null } {
  return { entry, link: null };
}
```

  - Create `src/app/reader/link-card/link-card-with-url.ts`:

```ts
import { EntryDto } from '../models';
import { LinkCardDto } from './link-card';

export function linkCardWithUrl(entries: readonly EntryDto[], url: string): LinkCardDto | null {
  for (const entry of entries) {
    const card =
      entry.linkCards.find((candidate) => candidate.url === url) ??
      entry.thread.map((reply) => reply.linkCard).find((candidate) => candidate?.url === url);
    if (card) return card;
  }
  return null;
}
```

  - Delete `link-card-at.ts` and `link-card-at.spec.ts`.

- [ ] **Step 4: Run the pure specs.** Run the Step 2 command again. Expected: PASS.

- [ ] **Step 5: Update the navigation spec, then the navigation.** Replace the two opening tests in
  `shell/link-reader-navigation.service.spec.ts` with:

```ts
  const card = aLinkCard({ url: 'https://news.test/a' });
  const param = `${A_READER_TOKEN}~https://news.test/a`;

  it('layers the card over the open post and remembers it', () => {
    navigation.layer(card);

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: param },
      queryParamsHandling: 'merge',
    });
    expect(navigation.opened()).toBe(card);
  });

  it('opens the card alone, dropping the post', () => {
    navigation.openAlone(card);

    expect(navigate).toHaveBeenCalledWith([], {
      relativeTo: route,
      queryParams: { link: param, entry: null },
      queryParamsHandling: 'merge',
    });
    expect(navigation.opened()).toBe(card);
  });
```

  Add the import `import { A_READER_TOKEN, aLinkCard } from '../../../testing/entry-testing';`.
  Then `link-reader-navigation.service.ts`:

```ts
import { Injectable, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { LinkCardDto } from '../link-card/link-card';
import { linkParam, refOf } from '../query/link-param';

@Injectable()
export class LinkReaderNavigation {
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly openedCard = signal<LinkCardDto | null>(null);

  /** The card last opened from the page; a deep link has none, and the route state looks the URL up instead. */
  readonly opened = this.openedCard.asReadonly();

  layer(card: LinkCardDto): void {
    this.openedCard.set(card);
    this.navigate({ link: linkParam(refOf(card)) });
  }

  openAlone(card: LinkCardDto): void {
    this.openedCard.set(card);
    this.navigate({ link: linkParam(refOf(card)), entry: null });
  }

  close(): void {
    this.navigate({ link: null });
  }

  private navigate(queryParams: Record<string, string | null>): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams,
      queryParamsHandling: 'merge',
    });
  }
}
```

- [ ] **Step 6: Route state resolves the open card by URL.** In `shell/reader-route-state.service.ts`:
  - Swap the `linkCardAt` import for `import { linkCardWithUrl } from '../link-card/link-card-with-url';`.
  - Inject `private readonly linkNavigation = inject(LinkReaderNavigation);` (`./link-reader-navigation.service`).
  - Replace `openLinkCard` with:

```ts
  /** The open link's card: the one just clicked, else the first listed card with its URL; null on a deep link to neither. */
  readonly openLinkCard = computed(() => {
    const ref = this.linkCard();
    if (ref === null) return null;
    const opened = this.linkNavigation.opened();
    if (opened?.url === ref.url) return opened;
    const open = this.openEntry();
    return linkCardWithUrl(open === null ? this.entries.entries() : [open, ...this.entries.entries()], ref.url);
  });
```

  Any spec that builds `ReaderRouteState` through TestBed must now provide `LinkReaderNavigation`. The shell provides
  both, so only isolated route-state specs, if any, need the provider.

- [ ] **Step 7: API and cache.**
  - `reader-api.ts`:

```ts
  linkCardReaderContent(ref: LinkCardRef): Observable<ReaderContent> {
    return this.http.get<ReaderContent>(`${this.base}/api/link-cards/reader`, {
      params: { url: ref.url, token: ref.token },
    });
  }
```

    In its spec, the test becomes:

```ts
  it('GETs reader content for a signed link card', () => {
    let received: ReaderContent | undefined;
    api
      .linkCardReaderContent({ url: 'https://news.test/a?b=1', token: A_READER_TOKEN })
      .subscribe((content) => (received = content));

    const testRequest = ctrl.expectOne(
      (request) =>
        request.url === 'https://api.test/api/link-cards/reader' &&
        request.params.get('url') === 'https://news.test/a?b=1' &&
        request.params.get('token') === A_READER_TOKEN,
    );
    expect(testRequest.request.method).toBe('GET');
    testRequest.flush({
      status: 'failed',
      url: null,
      reason: 'no_url',
      detail: null,
      originalHero: null,
    } satisfies ReaderContent);

    expect(received?.status).toBe('failed');
  });
```

  - `article/content/reader-cache.service.ts`:

```ts
export type ReaderCacheKey = number | `card:${string}`;

export function readerCacheKey(target: ReaderTarget): ReaderCacheKey {
  return typeof target === 'number' ? target : `card:${target.url}`;
}
```

    Leave `VERSION` alone: old `card:7.1` keys simply never hit again and age out through eviction.
  - Specs:
    - In `reader-cache.service.spec.ts:87-88`, `'card:7.1'` → `'card:https://x/card'`.
    - In `reader-content.service.spec.ts`, set `const CARD = { url: 'https://x/card', token: A_READER_TOKEN };` and change every `'card:7.1'` to `'card:https://x/card'`.

- [ ] **Step 8: Lead with the card's image.** In `article/link-reader/link-article-source.service.ts`, replace `lead` with:

```ts
  readonly lead = computed(() => (this.leadFailed() ? null : (this.card()?.imageUrl ?? null)));
```

  In `link-reader.component.html`, inside `@if (source.lead(); as image)`, bind `[src]="image"` and delete the
  `[attr.width]` / `[attr.height]` lines.

  Specs:
  - `link-article-source.service.spec.ts`: `const LINK: LinkCardRef = { url: 'https://news.test/story', token: A_READER_TOKEN };`.
  - `link-reader.component.spec.ts`:
    - Set `FIRST_CARD`/`SECOND_CARD` to `{ url: 'https://news.test/story', token: A_READER_TOKEN }` and `{ url: 'https://news.test/second', token: A_READER_TOKEN }`.
    - The lead-image test (about line 128) expects the src of the card that `routeState.openLinkCard` returns (give that card `imageUrl: 'https://news.test/hero.jpg'`) rather than the failure's `originalHero`.
    - The no-lead test (about line 142) gives the open card `imageUrl: null`.

- [ ] **Step 9: Call sites pass the card.**
  - `article/link-cards/link-cards.component.html`: `(openInReader)="navigation.layer(card)"`. In the `.ts`, make `navigation` `protected` and delete `openInReader()`.
  - `article/thread-replies/thread-replies.component.html:17`: `(openInReader)="navigation.layer(card)"`.
  - `list/entry-row/entry-row.component.html:44`: `(openInReader)="navigation?.openAlone(card)"`. Delete `openInReaderAlone()` from the `.ts`.
  - `list/magazine/blocks/entry-link/entry-link.component.html:14`: `(openInReader)="navigation.openAlone(shared)"`. In the `.ts`, make `navigation` `protected` and delete `openInReaderAlone()`.
  - `list/magazine/blocks/entry-thread/entry-thread.component.html:81`: `(openInReader)="navigation.openAlone(card)"`. In the `.ts`, make `navigation` `protected` and delete `openInReaderAlone()`.

  In each call site's spec, change the expectation from `{ entryId, position }` to the card object the test
  rendered, e.g. `expect(navigation.layer).toHaveBeenCalledWith(entry.linkCards[0])` and
  `expect(navigation.openAlone).toHaveBeenCalledWith(card)`. Drop the `position:` overrides on `aLinkCard(...)`, and
  give two cards in one test distinct `url`s so the assertion tells them apart.

  `thread-replies.component.spec.ts:48` is renamed to `'opens a reply’s card in the reader layer'`.

- [ ] **Step 10: Shell spec link block.** In `shell/reader-shell.component.spec.ts`, the `link reader (#1664)` block,
  add at the top of the `describe`:

```ts
    const LINK = `${A_READER_TOKEN}~https://news.test/story`;
    const UNKNOWN_LINK = `${A_READER_TOKEN}~https://news.test/unknown`;
```

  Then replace every `link: '1.0'` with `link: LINK`, and every `link: '99.0'` with `link: UNKNOWN_LINK`.
  `https://news.test/story` is `aLinkCard()`'s default URL. Rename the last test to
  `'resolves the open link card from a listed entry by its url, or null when none carries it'`.

- [ ] **Step 11: Playwright fixtures.**
  - `e2e/support/reader.ts`: the e2e `aLinkCard` (if it is a separate builder there, otherwise the one it imports) gains `readerToken: 'a'.repeat(43)` and loses `position`.
  - `e2e/link-card-reader.spec.ts:47` and `e2e/self-reply-thread.spec.ts:61`: route `'**/api/link-cards/reader?*'` instead of the position paths.
  - `self-reply-thread.spec.ts:29` drops `position: 0`.

- [ ] **Step 12: Run the frontend gate.** Run `docker compose exec -T frontend npm run check`.
  Expected: PASS, covering ESLint, Prettier, Stylelint, tsc for app/spec/e2e, and Jest.
  Then confirm nothing is left:

```bash
grep -rnE "PlacedLinkCardDto|linkCardAt|link-cards/[0-9]|position: card|entryId: .*position" frontend/src frontend/e2e
```

  Expected: no hits.

- [ ] **Step 13: Commit.** Run:

```bash
git add -A frontend
git commit -m "feat(#1725): the client opens a link card by its signed URL"
```

---

### Task 6: Comment cards open in the reader

**Files:**
- Modify: `frontend/src/app/reader/article/entry-comments/entry-comments.component.html` (the `@if (c.linkCard)` block) and `entry-comments.component.ts`
- Test: `frontend/src/app/reader/article/entry-comments/entry-comments.component.spec.ts`

**Interfaces:**
- Consumes: `LinkReaderNavigation.layer(card: LinkCardDto)` (Task 5).

- [ ] **Step 1: Write the failing spec.** In `entry-comments.component.spec.ts`:
  - Declare `const navigation = { layer: jest.fn() };` at file scope, and call `navigation.layer.mockReset()` in the top `beforeEach`.
  - Add `{ provide: LinkReaderNavigation, useValue: navigation }` to the `providers` of the `TestBed.configureTestingModule` at about line 54. Import `LinkReaderNavigation` from `'../../shell/link-reader-navigation.service'`.
  - Add this test after `"renders a reply's media, quote and link card"`:

```ts
  it('opens a comment’s link card in the reader layer', () => {
    show('auto');
    const card = aLinkCard({ url: 'https://news.test/from-a-comment' });
    const element = settleComments([anEntryComment({ linkCard: card })]);

    element.querySelector<HTMLButtonElement>('app-link-card button.open-in-reader')!.click();

    expect(navigation.layer).toHaveBeenCalledWith(card);
  });
```

- [ ] **Step 2: Run it to see it fail.** Run
  `docker compose exec -T frontend npx jest src/app/reader/article/entry-comments/entry-comments.component.spec.ts`.
  Expected: FAIL, because the button is absent while `openableInReader` is false.

- [ ] **Step 3: Implement.** In `entry-comments.component.ts`, add
  `protected readonly navigation = inject(LinkReaderNavigation);`
  (import from `../../shell/link-reader-navigation.service`). In the template:

```html
              @if (c.linkCard; as card) {
                <app-link-card
                  [card]="card"
                  layout="article"
                  [openableInReader]="true"
                  (openInReader)="navigation.layer(card)"
                />
              }
```

  If any other spec mounts `EntryCommentsComponent` without the shell, it now needs a `LinkReaderNavigation` provider:
  `grep -rln "EntryCommentsComponent" frontend/src --include=*.spec.ts`.

- [ ] **Step 4: Run the spec, then the gate.** Run the Step 2 command (expected PASS), then
  `docker compose exec -T frontend npm run check` (expected PASS).

- [ ] **Step 5: Commit.** Run:

```bash
git add frontend
git commit -m "feat(#1725): a comment's link card opens in the reader"
```

---

### Task 7: Verification

- [ ] **Backend gates.** Run `cd backend && composer check && composer md`. Expected: clean.
- [ ] **Both suite legs.** Run `composer test:parallel` (SQLite) and `docker compose exec php composer test` (MySQL) in parallel. Expected: PASS.
- [ ] **Mutation gate.** Commit everything first; `infection:diff` ignores untracked files. Then run `composer infection:diff`. Expected: MSI ≥ `minMsi`. Kill any escaped mutant in `LinkCardSignature`, `LinkCardJson` or the controller with a test.
- [ ] **Containers are current.** Recreate or restart `php` and the worker if `docker compose ps` shows stale images. Clear the dev cache: `docker compose exec php bin/console cache:clear`.
- [ ] **Live check** in the built-in browser at `http://green-tara.local:4200/?subscription=1903&entry=626026-die-bundesregierung-will-das-informationsfreiheitsgesetz-faktisch-abschaffen`:
  - Open the comments, then a comment's card via "Open in reader". The layer loads the page.
  - Reload the URL. The deep link reopens the same page.
  - Open a body card and a magazine card. Both still work.
  - Edit the `token` part of the `?link=` URL. The layer shows the failure state, and the network tab shows a 404.
- [ ] **Dev log.** Run `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. Expected: no new warnings.
- [ ] **Playwright smokes** (stack up, from this checkout): `cd frontend && npx playwright test e2e/link-card-reader.spec.ts e2e/self-reply-thread.spec.ts`. Expected: PASS.
