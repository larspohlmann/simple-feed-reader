# Remove entries whose post was deleted (#1975) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When an opened social entry's post can no longer be found and the post's home server confirms it deleted, the reader removes the entry (every copy, tombstoned) and the article closes with an info toast "Deleted on Bluesky — removed here."

**Architecture:** A new per-network strategy `Social\HomeServerCheck\HomeServerCheckInterface` (tag `app.social_home_server_check`, keyed by `network()`, the `PostResolver` pattern) asks the post's home server anonymously through the SSRF-guarded clients: Bluesky `com.atproto.repo.getRecord` on the author's PDS (resolved by the existing `BlueskyIdentityResolver`), Mastodon `GET /api/v1/statuses/<id>` on the status's own instance. A new `Social\OpenedPost` wraps `SocialLikes::syncFromNetwork`: on `SocialPostNotFoundException` it runs the check and, only on a confirmed deletion, calls the #1972 `LocalPostRemoval::remove($entry, [])` and throws a new `SocialPostRemovedException`, mapped to `410 social_post_removed`; otherwise the original 404 `social_post_not_found` stands. `EntrySocialViewerController` calls `OpenedPost` instead of `SocialLikes`. In the SPA, `SocialCountsService` (the only caller of the viewer endpoint) announces the removal on a new root `RemovedPosts` stream; `EntryStateActions` listens, shows the toast and lets the row leave / the article close exactly as #1972's `deletePost` does.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Symfony HttpClient behind `GuardedApiClient`; Angular 20 signals, RxJS, Transloco, Jest.

**Spec:** GitHub issue #1975 (`gh issue view 1975`), part of #1962. Binding decision (Lars, 2026-11-02): remove automatically, whoever wrote the post, only after the post's home server confirms it gone. This plan only resolves what the issue leaves open (see "Resolved ambiguities").

## Global Constraints

- CLAUDE.md is binding: Clean Code, role folders (§10: an interface and its implementations in a folder named after it; exceptions in `Exception/`; stateless `final readonly` services in the module root), thin controllers, no comments by default (one line, a real *why* only), PHPStan max, PHPMD-clean on every touched `src` file, phptramp, PhpStorm lint (block on ERROR/WARNING), `infection:diff`.
- HTTP statuses as `Response::HTTP_*`, never literals, in `src` and `tests`.
- SSRF boundary: every outbound call goes through `BlueskyApiClient` / `MastodonApiClient`, both of which send through `App\Service\Fetch\GuardedApiClient`. No new HTTP client, no `HttpClientInterface` in `src`.
- The confirmation is anonymous: no `Authorization` header, no session, no account.
- Only these answers prove a deletion: Bluesky — `400` whose JSON `error` is `RecordNotFound`; Mastodon — `404`. Everything else (2xx, 401, 403, 429, 5xx, a transport failure, an unresolvable DID, an address the check cannot read) proves nothing and keeps the entry with the existing `404 social_post_not_found`.
- Local removal is #1972's `LocalPostRemoval::remove()` unchanged: every copy by `url_hash` except saved feeds, 7-day tombstones per feed, counts cache and search index forgotten.
- New problem: type `social_post_removed`, status `Response::HTTP_GONE` (410), in `SocialInteractionProblems`.
- Toast copy, network name substituted: en `Deleted on {{ network }} — removed here.` (em dash, as `deletePost.alreadyGone`), de `Auf {{ network }} gelöscht – hier entfernt.` (en dash, as the German `alreadyGone`).
- Commits `type(#1975): summary`; no Claude attribution anywhere.
- Frontend: Prettier 100 cols, ESLint `max-params` 3, en + de i18n parity. Frontend tests only inside the container (`docker compose exec -T frontend npx jest <path>`), **never two jest processes at once**; run `npm run check` **and** `npx ng build` in the container.
- Out of scope: any background sweep of old entries; removal happens only when an entry is opened.

## Resolved ambiguities

1. **The trigger is `GET /api/entries/{id}/social/viewer` only.** Its sole caller in the SPA is `SocialCountsService.networkViewer()` (article social row). Counts (`GET …/social`) and comments keep their behaviour; they never remove anything.
2. **The viewer request must survive a failed counts request.** `SocialCountsService.fetch()` runs `forkJoin([counts$, viewer$])`; for a deleted post the counts request 404s, and `forkJoin` errors on the first failure and **unsubscribes the viewer request** — the 410 would never be read. Task 3 turns a counts failure into `null` inside the join, so the viewer answer always arrives; the state still ends `failed` exactly as before.
3. **Thread replies are not tombstoned.** #1972 tombstoned the post's own folded replies because it deleted them. Here only the root is proven gone; the replies may still exist on the network, so `remove($entry, [])` passes no reply guids. If they come back on refresh as standalone entries, that is correct: they exist.
4. **Where the address comes from.** `SocialPostAddress::of($entry)` — the same rule the viewer lookup used. Bluesky: the AT URI guid; its authority may be a handle (old entries), so the check resolves it with `BlueskyIdentityResolver::resolve()` (handle → DID → DID document → PDS origin; `did:web` hosts go through the guarded client too). Mastodon: `MastodonStatusAddress::ofOrNull()` reads the host and id from the two Mastodon address shapes (`/@user/<id>`, `/users/user/statuses/<id>`), which are the status's own instance and its own id there. Any other shape (Pixelfed, Misskey, a remote copy's address) proves nothing and asks nothing.
5. **An account that cannot be resolved proves nothing.** A DID with no document or no PDS (`SocialAccountUnresolvableException`) is "not proven", not "gone": the check answers `false` for it. Only `SocialNetworkUnreachableException` (no answer at all; `SocialNetworkThrottledException` extends it) leaves the check, and `OpenedPost` reads it as "not proven" too.
6. **Mastodon answers 404 to anonymous readers for followers-only statuses as well.** Such a status reaches the reader only through a private (home-timeline) feed, and `ActingAccountChooser::accountFor()` acts with that feed's own account, which sees it — so the viewer lookup does not fail on it and no check runs. Accepted edge: the reader unfollowed the author after ingest. Documented here, not guarded.
7. **The problem carries no network name.** The SPA already knows the network from `entry.social.network` and builds the toast from it; the problem `detail` names it for API clients ("The post was deleted on Bluesky.").
8. **Whose copies go.** All of them, by #1972's rule (spec: "Locally, it's the same removal as #1972"). The deletion is confirmed on the home server, so it holds for every reader.
9. **Network wiring test.** The functional test drives Mastodon end to end; Bluesky is covered by `BlueskyHomeServerCheckTest` against the routed HTTP harness. Both checks are found through the same keyed locator, so a Bluesky functional test (which needs a full session handshake) adds nothing the unit test and the locator key do not already pin.

## File map

Backend (`backend/`):
- Create `src/Service/Social/HomeServerCheck/HomeServerCheckInterface.php`, `BlueskyHomeServerCheck.php`, `MastodonHomeServerCheck.php`.
- Create `src/Service/Social/Exception/SocialPostRemovedException.php`.
- Create `src/Service/Social/OpenedPost.php`.
- Modify `src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`, `src/Controller/Api/EntrySocialViewerController.php`.
- Tests: create `tests/Service/Social/HomeServerCheck/{BlueskyHomeServerCheckTest,MastodonHomeServerCheckTest}.php`; modify `tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php`, `tests/Controller/Api/EntrySocialViewerControllerTest.php`.

Frontend (`frontend/`):
- Create `src/app/reader/removed-posts.service.ts`.
- Modify `src/app/reader/entry-social.ts`, `src/app/reader/article/social-counts/social-counts.service.ts`, `src/app/reader/shell/entry-state-actions.service.ts`, `public/i18n/{en,de}.json`.
- Specs: `src/app/reader/article/social-counts/social-counts.service.spec.ts`, `src/app/reader/shell/entry-state-actions.service.spec.ts`.

---

### Task 1: Home-server checks — does the post's own server say it is deleted?

**Files:**
- Create: `backend/src/Service/Social/HomeServerCheck/HomeServerCheckInterface.php`
- Create: `backend/src/Service/Social/HomeServerCheck/MastodonHomeServerCheck.php`
- Create: `backend/src/Service/Social/HomeServerCheck/BlueskyHomeServerCheck.php`
- Test: `backend/tests/Service/Social/HomeServerCheck/MastodonHomeServerCheckTest.php`
- Test: `backend/tests/Service/Social/HomeServerCheck/BlueskyHomeServerCheckTest.php`

**Interfaces:**
- Consumes (existing): `BlueskyApiClient::answer(ApiRequestModel): ApiResponseModel` and `MastodonApiClient::answer(ApiRequestModel): ApiResponseModel` (any status returned; throw `SocialNetworkUnreachableException` only when no answer arrived); `BlueskyIdentityResolver::resolve(string $handleOrDid): BlueskyIdentityModel` (`->did`, `->pdsUrl` an `https://host` origin; throws `SocialAccountUnresolvableException`, `SocialNetworkUnreachableException`); `AtPostUri::authorityOf()`, `AtPostUri::withAuthority()`; `BlueskyRecordUri::recordKeyOf()`; `BlueskyRecords::POST_COLLECTION`; `MastodonStatusAddress::ofOrNull()` → `MastodonStatusModel::apiUrl()`; test helpers `RoutedHttpClient`, `SocialApiWiring::mastodonApiClient()`, `FetchWiring::guardedApiClient()`, `UrlGuard`, `StaticDnsResolver`, `IpValidator`.
- Produces: `App\Service\Social\HomeServerCheck\HomeServerCheckInterface` with `public static function network(): string` and `public function confirmsDeleted(string $address): bool` (throws `SocialNetworkUnreachableException`), tagged `app.social_home_server_check`; implementations `BlueskyHomeServerCheck`, `MastodonHomeServerCheck`.

- [ ] **Step 1: Write the failing Mastodon test**

`backend/tests/Service/Social/HomeServerCheck/MastodonHomeServerCheckTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HomeServerCheck;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HomeServerCheck\MastodonHomeServerCheck;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonHomeServerCheckTest extends TestCase
{
    private const string PROFILE_ADDRESS = 'https://mastodon.social/@Gargron/110';
    private const string USERS_ADDRESS = 'https://mastodon.social/users/Gargron/statuses/110';
    private const string STATUS = 'GET https://mastodon.social/api/v1/statuses/110';

    public function testAStatusItsOwnInstanceNoLongerHasIsConfirmedDeletedWithoutAToken(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(Response::HTTP_NOT_FOUND)]);

        self::assertTrue($this->check($http)->confirmsDeleted(self::PROFILE_ADDRESS));
        self::assertSame([self::STATUS], $http->requestLines());
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[0]['headers']));
    }

    public function testAUsersAddressAsksTheSameInstanceForTheSameStatus(): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer(Response::HTTP_NOT_FOUND)]);

        self::assertTrue($this->check($http)->confirmsDeleted(self::USERS_ADDRESS));
    }

    #[DataProvider('unprovenAnswers')]
    public function testAnyOtherAnswerProvesNothing(int $status): void
    {
        $http = new RoutedHttpClient([self::STATUS => self::answer($status)]);

        self::assertFalse($this->check($http)->confirmsDeleted(self::PROFILE_ADDRESS));
    }

    /** @return iterable<string, array{int}> */
    public static function unprovenAnswers(): iterable
    {
        yield 'still there' => [Response::HTTP_OK];
        yield 'closed to anonymous readers' => [Response::HTTP_UNAUTHORIZED];
        yield 'forbidden' => [Response::HTTP_FORBIDDEN];
        yield 'gone is not a status answer' => [Response::HTTP_GONE];
        yield 'throttled' => [Response::HTTP_TOO_MANY_REQUESTS];
        yield 'failing' => [Response::HTTP_INTERNAL_SERVER_ERROR];
    }

    public function testAnAddressOfAnotherShapeProvesNothingAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        self::assertFalse($this->check($http)->confirmsDeleted('https://pixelfed.example/p/alice/123'));
        self::assertSame([], $http->requests);
    }

    public function testAnInstanceThatDoesNotAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient([self::STATUS => new MockResponse('', ['error' => 'Connection refused'])]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->check($http)->confirmsDeleted(self::PROFILE_ADDRESS);
    }

    private function check(RoutedHttpClient $http): MastodonHomeServerCheck
    {
        return new MastodonHomeServerCheck(SocialApiWiring::mastodonApiClient($http->client()));
    }

    private static function answer(int $status): MockResponse
    {
        return new MockResponse('{}', ['http_code' => $status]);
    }
}
```

- [ ] **Step 2: Write the failing Bluesky test**

`backend/tests/Service/Social/HomeServerCheck/BlueskyHomeServerCheckTest.php` (the guard set-up is the one `BlueskyIdentityResolverTest` uses; Symfony HttpClient writes `%3A` in a query back as `:`, so the routes name the DID with colons):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HomeServerCheck;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HomeServerCheck\BlueskyHomeServerCheck;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyHomeServerCheckTest extends TestCase
{
    private const string POST = 'at://did:plc:alice/app.bsky.feed.post/3kpost';
    private const string DID_DOCUMENT = 'GET https://plc.directory/did:plc:alice';
    private const string GET_RECORD = 'GET https://pds.example.test/xrpc/com.atproto.repo.getRecord'
        . '?repo=did:plc:alice&collection=app.bsky.feed.post&rkey=3kpost';
    private const string RESOLVE_ALICE = 'GET https://public.api.bsky.app/xrpc/'
        . 'com.atproto.identity.resolveHandle?handle=alice.bsky.social';

    public function testARecordTheAuthorsPdsCannotFindIsConfirmedDeletedWithoutAToken(): void
    {
        $http = new RoutedHttpClient([
            self::DID_DOCUMENT => self::didDocument(),
            self::GET_RECORD => self::recordError(Response::HTTP_BAD_REQUEST, 'RecordNotFound'),
        ]);

        self::assertTrue($this->check($http)->confirmsDeleted(self::POST));
        self::assertSame([self::DID_DOCUMENT, self::GET_RECORD], $http->requestLines());
        self::assertSame([], preg_grep('/^Authorization:/i', $http->requests[1]['headers']));
    }

    public function testAPostNamedByHandleAsksThePdsByDid(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE_ALICE => new MockResponse('{"did":"did:plc:alice"}'),
            self::DID_DOCUMENT => self::didDocument(),
            self::GET_RECORD => self::recordError(Response::HTTP_BAD_REQUEST, 'RecordNotFound'),
        ]);

        self::assertTrue(
            $this->check($http)->confirmsDeleted('at://alice.bsky.social/app.bsky.feed.post/3kpost'),
        );
        self::assertSame([self::RESOLVE_ALICE, self::DID_DOCUMENT, self::GET_RECORD], $http->requestLines());
    }

    #[DataProvider('unprovenAnswers')]
    public function testAnyOtherAnswerProvesNothing(MockResponse $answer): void
    {
        $http = new RoutedHttpClient([self::DID_DOCUMENT => self::didDocument(), self::GET_RECORD => $answer]);

        self::assertFalse($this->check($http)->confirmsDeleted(self::POST));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unprovenAnswers(): iterable
    {
        yield 'still there' => [new MockResponse('{"uri":"' . self::POST . '","value":{}}')];
        yield 'a taken-down repo' => [self::recordError(Response::HTTP_BAD_REQUEST, 'RepoTakendown')];
        yield 'not found without its error' => [self::recordError(Response::HTTP_NOT_FOUND, 'NotFound')];
        yield 'failing' => [self::recordError(Response::HTTP_INTERNAL_SERVER_ERROR, 'InternalServerError')];
    }

    public function testAnAuthorWithoutADidDocumentProvesNothing(): void
    {
        $http = new RoutedHttpClient([
            self::DID_DOCUMENT => new MockResponse('Not Found', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);

        self::assertFalse($this->check($http)->confirmsDeleted(self::POST));
        self::assertSame([self::DID_DOCUMENT], $http->requestLines());
    }

    public function testAnAddressThatNamesNoPostProvesNothingAndAsksNothing(): void
    {
        $http = new RoutedHttpClient([]);

        self::assertFalse($this->check($http)->confirmsDeleted('at://did:plc:alice/app.bsky.feed.like/3klike'));
        self::assertSame([], $http->requests);
    }

    public function testAPdsThatDoesNotAnswerIsUnreachable(): void
    {
        $http = new RoutedHttpClient([
            self::DID_DOCUMENT => self::didDocument(),
            self::GET_RECORD => new MockResponse('', ['error' => 'Connection refused']),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->check($http)->confirmsDeleted(self::POST);
    }

    private function check(RoutedHttpClient $http): BlueskyHomeServerCheck
    {
        $address = ['93.184.216.34'];
        $guard = new UrlGuard(
            new StaticDnsResolver([
                'public.api.bsky.app' => $address,
                'plc.directory' => $address,
                'pds.example.test' => $address,
            ]),
            new IpValidator(),
        );
        $bluesky = new BlueskyApiClient(FetchWiring::guardedApiClient($http->client(), $guard));

        return new BlueskyHomeServerCheck($bluesky, new BlueskyIdentityResolver($bluesky));
    }

    private static function didDocument(): MockResponse
    {
        return new MockResponse(
            '{"id":"did:plc:alice","service":[{"id":"#atproto_pds",'
            . '"type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}',
        );
    }

    private static function recordError(int $status, string $error): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => $error, 'message' => 'x'], \JSON_THROW_ON_ERROR),
            ['http_code' => $status],
        );
    }
}
```

- [ ] **Step 3: Run both tests to verify they fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Social/HomeServerCheck`
Expected: FAIL / Error — `Class "App\Service\Social\HomeServerCheck\MastodonHomeServerCheck" not found` (and the Bluesky one).

- [ ] **Step 4: Write the interface**

`backend/src/Service/Social/HomeServerCheck/HomeServerCheckInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\HomeServerCheck;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's anonymous question to a post's own server, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_home_server_check')]
interface HomeServerCheckInterface
{
    public static function network(): string;

    /**
     * True only when the post's own server answers that the post is gone; any other answer proves nothing.
     *
     * @throws SocialNetworkUnreachableException when no answer arrived
     */
    public function confirmsDeleted(string $address): bool;
}
```

- [ ] **Step 5: Write the Mastodon check**

`backend/src/Service/Social/HomeServerCheck/MastodonHomeServerCheck.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\HomeServerCheck;

use App\Enum\SocialNetwork;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Support\MastodonStatusAddress;
use Symfony\Component\HttpFoundation\Response;

/** Asks the status's own instance, anonymously: only its 404 proves the status deleted. */
final readonly class MastodonHomeServerCheck implements HomeServerCheckInterface
{
    public function __construct(private MastodonApiClient $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function confirmsDeleted(string $address): bool
    {
        $status = MastodonStatusAddress::ofOrNull($address);
        if (null === $status) {
            return false;
        }

        return Response::HTTP_NOT_FOUND === $this->mastodon->answer(ApiRequestModel::get($status->apiUrl()))->status;
    }
}
```

- [ ] **Step 6: Write the Bluesky check**

`backend/src/Service/Social/HomeServerCheck/BlueskyHomeServerCheck.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\HomeServerCheck;

use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Bluesky\Support\AtPostUri;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyApiClient;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\BlueskyRecords;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Model\BlueskyIdentityModel;
use App\Service\Social\Support\BlueskyRecordUri;
use Symfony\Component\HttpFoundation\Response;

/** Asks the author's own PDS, anonymously, for the post record: only its RecordNotFound proves the post deleted. */
final readonly class BlueskyHomeServerCheck implements HomeServerCheckInterface
{
    private const string GET_RECORD = '/xrpc/com.atproto.repo.getRecord?';
    private const string RECORD_NOT_FOUND = 'RecordNotFound';

    public function __construct(
        private BlueskyApiClient $bluesky,
        private BlueskyIdentityResolver $identities,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function confirmsDeleted(string $address): bool
    {
        $author = AtPostUri::authorityOf($address);
        if (null === $author) {
            return false;
        }
        try {
            $identity = $this->identities->resolve($author);
        } catch (SocialAccountUnresolvableException) {
            return false;
        }

        return $this->pdsHasNoRecord($identity, AtPostUri::withAuthority($address, $identity->did));
    }

    private function pdsHasNoRecord(BlueskyIdentityModel $identity, string $post): bool
    {
        $recordKey = BlueskyRecordUri::recordKeyOf($post);
        if (null === $recordKey) {
            return false;
        }
        $query = http_build_query(
            ['repo' => $identity->did, 'collection' => BlueskyRecords::POST_COLLECTION, 'rkey' => $recordKey],
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );
        $answer = $this->bluesky->answer(ApiRequestModel::get($identity->pdsUrl . self::GET_RECORD . $query));

        return Response::HTTP_BAD_REQUEST === $answer->status
            && self::RECORD_NOT_FOUND === JsonNodeModel::of($answer->json())->string('error');
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Service/Social/HomeServerCheck`
Expected: PASS (all cases). If a Bluesky route is reported `Unrouted request: GET …`, compare the printed URL with `GET_RECORD` character by character — the query must be `repo=did:plc:alice&collection=app.bsky.feed.post&rkey=3kpost`.

- [ ] **Step 8: Lint the new files**

Run: `vendor/bin/phpcs src/Service/Social/HomeServerCheck tests/Service/Social/HomeServerCheck && vendor/bin/phpstan analyse src/Service/Social/HomeServerCheck tests/Service/Social/HomeServerCheck --memory-limit=1G && vendor/bin/phpmd src/Service/Social/HomeServerCheck text phpmd.xml.dist`
Expected: no findings. (If `phpmd.xml.dist` is named differently, use the ruleset `composer md` uses — see `composer.json` `scripts.md`.)

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Social/HomeServerCheck backend/tests/Service/Social/HomeServerCheck
git commit -m "feat(#1975): ask a post's home server whether it was deleted"
```

---

### Task 2: The viewer removes a post its home server confirms deleted (`410 social_post_removed`)

**Files:**
- Create: `backend/src/Service/Social/Exception/SocialPostRemovedException.php`
- Create: `backend/src/Service/Social/OpenedPost.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php` (new arm after `SocialPostNotFoundException`'s)
- Modify: `backend/src/Controller/Api/EntrySocialViewerController.php`
- Test: `backend/tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php`
- Test: `backend/tests/Controller/Api/EntrySocialViewerControllerTest.php`

**Interfaces:**
- Consumes: Task 1's `HomeServerCheckInterface` (tag `app.social_home_server_check`, `network()` index); existing `SocialLikes::syncFromNetwork(User, Entry): SocialPostModel` (throws `SocialPostNotFoundException` when the acting account's lookup finds nothing), `LocalPostRemoval::remove(Entry, list<string> $replyGuids): void`, `SocialPostAddress::ofOrRefuse(Entry): SocialPostAddressModel`, `SocialNetwork::displayName()`, `SocialViewerJson::of(SocialPostModel)`.
- Produces: `App\Service\Social\OpenedPost::sync(User $user, Entry $entry): SocialPostModel` (throws `SocialPostRemovedException`, `SocialPostNotFoundException`, plus whatever `syncFromNetwork` throws); `App\Service\Social\Exception\SocialPostRemovedException`; HTTP problem `{"type":"social_post_removed","status":410,…}` on `GET /api/entries/{id}/social/viewer`, which Task 3 consumes.

- [ ] **Step 1: Write the failing problem-mapping case**

In `backend/tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php`, add the import `use App\Service\Social\Exception\SocialPostRemovedException;` and, in `problems()`, right after the `'post gone'` yield:

```php
        yield 'post removed here' => [
            new SocialPostRemovedException('x'),
            'social_post_removed',
            Response::HTTP_GONE,
        ];
```

- [ ] **Step 2: Write the failing viewer tests**

In `backend/tests/Controller/Api/EntrySocialViewerControllerTest.php`:

Add imports `use App\Entity\Entry;` and `use PHPUnit\Framework\Attributes\DataProvider;`. Change the routed hosts and add the origin route:

```php
    private const array ROUTED_HOSTS = ['mastodon.example', 'mastodon.social'];
```

```php
    private const string ORIGIN_STATUS = 'GET https://mastodon.social/api/v1/statuses/110';
```

Add these tests (before `viewerPath()`):

```php
    public function testAPostItsHomeServerNoLongerHasIsRemovedHereAndSaysSo(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::noStatus(),
            self::ORIGIN_STATUS => new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]),
        ]);
        $user = $this->signIn($client, 'viewer-deleted@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_GONE);
        self::assertSame('social_post_removed', $this->payload($client)['type']);
        self::assertSame([self::SEARCH, self::ORIGIN_STATUS], $this->routesAsked());
        $this->entityManager()->clear();
        self::assertNull($this->entityManager()->find(Entry::class, $entryId));
    }

    #[DataProvider('unprovenDeletions')]
    public function testAPostItsHomeServerDoesNotProveDeletedIsKept(MockResponse $originAnswer): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::noStatus(),
            self::ORIGIN_STATUS => $originAnswer,
        ]);
        $user = $this->signIn($client, 'viewer-unproven@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', $this->viewerPath($entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_post_not_found', $this->payload($client)['type']);
        $this->entityManager()->clear();
        self::assertNotNull($this->entityManager()->find(Entry::class, $entryId));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unprovenDeletions(): iterable
    {
        yield 'still there for anyone' => [new MockResponse('{"id":"110"}')];
        yield 'closed to anonymous readers' => [new MockResponse('{}', ['http_code' => Response::HTTP_UNAUTHORIZED])];
        yield 'forbidden' => [new MockResponse('{}', ['http_code' => Response::HTTP_FORBIDDEN])];
        yield 'failing' => [new MockResponse('{}', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR])];
        yield 'unreachable' => [new MockResponse('', ['error' => 'Connection refused'])];
    }
```

and the helper beside `foundStatus()`:

```php
    private static function noStatus(): MockResponse
    {
        return new MockResponse('{"statuses":[]}');
    }
```

- [ ] **Step 3: Run them to verify they fail**

Run: `php bin/phpunit tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php tests/Controller/Api/EntrySocialViewerControllerTest.php`
Expected: FAIL — `Class "App\Service\Social\Exception\SocialPostRemovedException" not found`; once that exists, the removal test fails with `404` instead of `410` (only `SEARCH` was asked; the origin was never consulted).

- [ ] **Step 4: Write the exception**

`backend/src/Service/Social/Exception/SocialPostRemovedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class SocialPostRemovedException extends \RuntimeException
{
}
```

- [ ] **Step 5: Map it to its problem**

In `SocialInteractionProblems.php`, import `use App\Service\Social\Exception\SocialPostRemovedException;` and add this arm directly after the `SocialPostNotFoundException` arm:

```php
            $exception instanceof SocialPostRemovedException => new ResolvedProblem(new ApiProblem(
                'social_post_removed',
                'Post deleted on its network and removed here',
                Response::HTTP_GONE,
                $exception->getMessage(),
            )),
```

- [ ] **Step 6: Write `OpenedPost`**

`backend/src/Service/Social/OpenedPost.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRemovedException;
use App\Service\Social\HomeServerCheck\HomeServerCheckInterface;
use App\Service\Social\Model\SocialPostAddressModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** The post an opened article shows; one the acting account cannot find and its home server confirms deleted goes. */
final readonly class OpenedPost
{
    public function __construct(
        private SocialLikes $likes,
        private LocalPostRemoval $localRemoval,
        #[AutowireLocator('app.social_home_server_check', defaultIndexMethod: 'network')]
        private ContainerInterface $homeServers,
    ) {
    }

    /**
     * @throws SocialPostRemovedException when the post's home server confirms it deleted; every copy here is gone
     * @throws SocialPostNotFoundException when the acting account cannot find the post and nothing proves it deleted
     */
    public function sync(User $user, Entry $entry): SocialPostModel
    {
        try {
            return $this->likes->syncFromNetwork($user, $entry);
        } catch (SocialPostNotFoundException $notFound) {
            $this->removeIfDeleted($entry);

            throw $notFound;
        }
    }

    /** @throws SocialPostRemovedException */
    private function removeIfDeleted(Entry $entry): void
    {
        $post = SocialPostAddress::ofOrRefuse($entry);
        if (!$this->confirmsDeleted($post)) {
            return;
        }
        // Only the post is proven gone; its thread replies may still exist, so none is tombstoned.
        $this->localRemoval->remove($entry, []);

        throw new SocialPostRemovedException(sprintf('The post was deleted on %s.', $post->network->displayName()));
    }

    private function confirmsDeleted(SocialPostAddressModel $post): bool
    {
        try {
            return $this->homeServerOn($post->network)->confirmsDeleted($post->address);
        } catch (SocialNetworkUnreachableException) {
            return false;
        }
    }

    private function homeServerOn(SocialNetwork $network): HomeServerCheckInterface
    {
        $check = $this->homeServers->get($network->value);
        \assert($check instanceof HomeServerCheckInterface);

        return $check;
    }
}
```

- [ ] **Step 7: Point the controller at it**

In `EntrySocialViewerController.php`, replace `use App\Service\Social\SocialLikes;` with `use App\Service\Social\OpenedPost;`, the constructor parameter `private SocialLikes $socialLikes,` with `private OpenedPost $openedPost,`, and the return line with:

```php
        return new JsonResponse(SocialViewerJson::of($this->openedPost->sync($user, $entry)));
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php bin/phpunit tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php tests/Controller/Api/EntrySocialViewerControllerTest.php tests/Service/Social`
Expected: PASS, the existing viewer tests (liked/unliked/direct/no account/stranger/budget) included.

- [ ] **Step 9: Lint**

Run: `vendor/bin/phpcs src/Service/Social/OpenedPost.php src/Service/Social/Exception/SocialPostRemovedException.php src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php src/Controller/Api/EntrySocialViewerController.php tests/Controller/Api/EntrySocialViewerControllerTest.php tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php` and `composer stan` (needs a warm dev cache: `bin/console cache:warmup`).
Expected: clean. `ThinControllerRule`, `ServiceRoleRule`, `DomainKnowsNoHttpRule`, `ServiceModuleCycleRule` stay green.

- [ ] **Step 10: Commit**

```bash
git add backend/src/Service/Social/OpenedPost.php backend/src/Service/Social/Exception/SocialPostRemovedException.php backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php backend/src/Controller/Api/EntrySocialViewerController.php backend/tests/Controller/Api/EntrySocialViewerControllerTest.php backend/tests/Http/Problem/ExceptionProblems/SocialInteractionProblemsTest.php
git commit -m "feat(#1975): remove an opened post its home server confirms deleted"
```

---

### Task 3: Frontend — the article closes, the row leaves, a toast says why

**Files:**
- Create: `frontend/src/app/reader/removed-posts.service.ts`
- Modify: `frontend/src/app/reader/entry-social.ts` (export the problem type)
- Modify: `frontend/src/app/reader/article/social-counts/social-counts.service.ts`
- Modify: `frontend/src/app/reader/shell/entry-state-actions.service.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/article/social-counts/social-counts.service.spec.ts`
- Test: `frontend/src/app/reader/shell/entry-state-actions.service.spec.ts`

**Interfaces:**
- Consumes: Task 2's `410` problem with `type: 'social_post_removed'` on `SocialPostApi.viewer(entryId)`; existing `parseProblem(HttpErrorResponse): Problem` (`core/problem`), `likeableSocial()`, `SOCIAL_NETWORK_NAMES`, `ToastService.show({ message })`, `EntryStateActions.markLeaving/closeEntry`, `SubscriptionsStore.reloadCountsQuietly()`, `provideTranslocoTesting()` (`src/testing/transloco-testing.ts`).
- Produces: `RemovedPosts` (root) with `announce(post: RemovedPost): void` and `readonly removed$: Observable<RemovedPost>`; `RemovedPost = { id: number; network: SocialNetworkName }`; `SOCIAL_POST_REMOVED = 'social_post_removed'` in `entry-social.ts`; i18n key `reader.postDeletedThere`.

- [ ] **Step 1: Add the strings (en + de)**

In `frontend/public/i18n/en.json`, directly after the `"deletePost": { … },` block inside `"reader"`:

```json
    "postDeletedThere": "Deleted on {{ network }} — removed here.",
```

In `frontend/public/i18n/de.json`, at the same place:

```json
    "postDeletedThere": "Auf {{ network }} gelöscht – hier entfernt.",
```

- [ ] **Step 2: Write the failing counts-service tests**

In `social-counts.service.spec.ts`, add imports `import { RemovedPost, RemovedPosts } from '../../removed-posts.service';` and add this `describe` before `it('maps any error to failed', …)`:

```ts
  describe('a post deleted on its network (#1975)', () => {
    const problem = (type: string, status: number) =>
      throwError(() => new HttpErrorResponse({ status, error: { type, title: 'x', status } }));
    let announced: RemovedPost[];

    beforeEach(() => {
      announced = [];
      TestBed.inject(RemovedPosts).removed$.subscribe((removed) => announced.push(removed));
    });

    it('announces the removal the viewer reports, though the counts failed first', () => {
      api.counts.mockReturnValue(problem('social_post_not_found', 404));
      api.viewer.mockReturnValue(problem('social_post_removed', 410));

      service.load(post(1));

      expect(announced).toEqual([{ id: 1, network: 'bluesky' }]);
      expect(service.state(1)()).toMatchObject({ status: 'failed' });
    });

    it('announces nothing for a post the reader merely cannot find', () => {
      api.viewer.mockReturnValue(problem('social_post_not_found', 404));

      service.load(post(1));

      expect(announced).toEqual([]);
      expect(service.state(1)()).toMatchObject({ status: 'ok', actions: null });
    });
  });
```

- [ ] **Step 3: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/social-counts/social-counts.service.spec.ts`
Expected: FAIL — `Cannot find module '../../removed-posts.service'`. (After Step 4 alone, the first test still fails with `announced` equal to `[]`: `forkJoin` dropped the viewer request on the counts error.)

- [ ] **Step 4: Write the removal stream and the problem type**

`frontend/src/app/reader/removed-posts.service.ts`:

```ts
import { Injectable } from '@angular/core';
import { Observable, Subject } from 'rxjs';
import { SocialNetworkName } from '../core/social-network';

/** An entry the server removed because its post was deleted on its network (#1975). */
export interface RemovedPost {
  id: number;
  network: SocialNetworkName;
}

/** Where the article's social row reports a removed post to the shell that owns the list and the route. */
@Injectable({ providedIn: 'root' })
export class RemovedPosts {
  private readonly removed = new Subject<RemovedPost>();
  readonly removed$: Observable<RemovedPost> = this.removed.asObservable();

  announce(post: RemovedPost): void {
    this.removed.next(post);
  }
}
```

In `frontend/src/app/reader/entry-social.ts`, above `export interface SocialViewerDto`:

```ts
/** The problem type of the viewer answer for a post deleted on its network and removed here (#1975). */
export const SOCIAL_POST_REMOVED = 'social_post_removed';
```

- [ ] **Step 5: Keep the viewer answer and announce the removal**

In `social-counts.service.ts`:

Imports — add `HttpErrorResponse` and `parseProblem`, `SOCIAL_POST_REMOVED`, `RemovedPosts`:

```ts
import { HttpErrorResponse } from '@angular/common/http';
import { Problem, parseProblem } from '../../../core/problem';
import {
  PostActionsView,
  SOCIAL_POST_REMOVED,
  SocialCountsDto,
  SocialViewerDto,
  likeableSocial,
} from '../../entry-social';
import { RemovedPosts } from '../../removed-posts.service';
```

(replace the existing `import { Problem } from '../../../core/problem';` and the existing `entry-social` import.)

Field, beside `entries`:

```ts
  private readonly removedPosts = inject(RemovedPosts);
```

Replace the body of `fetch()` from `forkJoin(` to the end of the method with:

```ts
    // A failed count must not cancel the viewer request: its answer is what reports a deleted post.
    forkJoin([counts$.pipe(catchError(() => of(null))), this.networkViewer(entry)]).subscribe(
      ([counts, viewer]) => {
        if (generation !== this.generation) return;
        if (counts === null) {
          state.set({ status: 'failed', failedAt: Date.now() });
          return;
        }
        if (viewer !== null)
          this.entries.adoptLike(entry.id, { from: likedBefore, to: viewer.isLiked });
        state.set(loadedState(counts, viewer, likedBefore));
      },
    );
  }
```

Replace `networkViewer()` with:

```ts
  /** What the network says the reader did to the post; null when it cannot say. */
  private networkViewer(entry: CountedEntry): Observable<SocialViewerDto | null> {
    const social = likeableSocial({ social: entry.social });
    if (!social) return of(null);
    return this.api.viewer(entry.id).pipe(
      catchError((error: unknown) => {
        if (isPostRemoved(error)) this.removedPosts.announce({ id: entry.id, network: social.network });
        return of(null);
      }),
    );
  }
```

Add module functions after `isStale()`:

```ts
function loadedState(
  counts: SocialCountsDto,
  viewer: SocialViewerDto | null,
  likedBefore: boolean,
): SocialCountsState {
  return {
    status: 'ok',
    counts,
    likedAtLoad: viewer?.isLiked ?? likedBefore,
    actions: viewer && {
      isReposted: viewer.isReposted,
      canRepost: viewer.canRepost,
      canReply: viewer.canReply,
      canQuote: viewer.canQuote,
    },
    loadedAt: Date.now(),
  };
}

function isPostRemoved(error: unknown): boolean {
  return error instanceof HttpErrorResponse && parseProblem(error).type === SOCIAL_POST_REMOVED;
}
```

Run Prettier on the file (`docker compose exec -T frontend npx prettier --write src/app/reader/article/social-counts/social-counts.service.ts`).

- [ ] **Step 6: Run the counts spec to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/article/social-counts/social-counts.service.spec.ts`
Expected: PASS — the new cases and every existing one ("maps any error to failed", "retries a failed load…", "forgets every count, and drops a late error…" included).

- [ ] **Step 7: Write the failing shell test**

In `entry-state-actions.service.spec.ts`, add imports:

```ts
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { RemovedPosts } from '../removed-posts.service';
```

Because `EntryStateActions` will inject `TranslocoService`, add `imports: [provideTranslocoTesting()],` as the first property of **each** of the four existing `TestBed.configureTestingModule({ … })` calls (liking, opening, reading counts, deleting my own post).

Append this block at the end of the file:

```ts
describe('EntryStateActions on a post deleted on its network (#1975)', () => {
  const show = jest.fn();
  const reloadCountsQuietly = jest.fn();
  const openEntryId = signal<number | null>(null);
  let actions: EntryStateActions;
  let navigate: jest.SpyInstance;

  beforeEach(() => {
    show.mockReset();
    reloadCountsQuietly.mockReset();
    openEntryId.set(null);
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        EntryStateActions,
        provideRouter([]),
        { provide: EntriesStore, useValue: {} },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly } },
        { provide: SavedSearchesStore, useValue: {} },
        {
          provide: ReaderRouteState,
          useValue: { openEntryId, openEntry: signal(null), selection: () => ({ kind: 'all' }) },
        },
        { provide: ToastService, useValue: { show } },
        { provide: PostActions, useValue: {} },
      ],
    });
    actions = TestBed.inject(EntryStateActions);
    navigate = jest.spyOn(TestBed.inject(Router), 'navigate').mockResolvedValue(true);
  });

  it('says so, lets the row leave and closes its open article as Back does', () => {
    openEntryId.set(7);

    TestBed.inject(RemovedPosts).announce({ id: 7, network: 'bluesky' });

    expect(show).toHaveBeenCalledWith({ message: 'Deleted on Bluesky — removed here.' });
    expect(actions.leavingIds().has(7)).toBe(true);
    expect(reloadCountsQuietly).toHaveBeenCalled();
    expect(navigate).toHaveBeenCalledWith(
      [],
      expect.objectContaining({ queryParams: { entry: null, link: null } }),
    );
  });

  it('leaves another open article alone', () => {
    openEntryId.set(8);

    TestBed.inject(RemovedPosts).announce({ id: 7, network: 'mastodon' });

    expect(show).toHaveBeenCalledWith({ message: 'Deleted on Mastodon — removed here.' });
    expect(actions.leavingIds().has(7)).toBe(true);
    expect(navigate).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 8: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/entry-state-actions.service.spec.ts`
Expected: the two new tests FAIL (`show` not called); the existing blocks PASS.

- [ ] **Step 9: Listen in `EntryStateActions`**

In `entry-state-actions.service.ts`:

Imports:

```ts
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { SOCIAL_NETWORK_NAMES } from '../../core/social-network';
import { RemovedPost, RemovedPosts } from '../removed-posts.service';
```

Field, after `postActions`:

```ts
  private readonly transloco = inject(TranslocoService);
```

At the end of the constructor, after the `effect(…)`:

```ts
    inject(RemovedPosts)
      .removed$.pipe(takeUntilDestroyed())
      .subscribe((removed) => this.dropRemovedPost(removed));
```

Replace `deletePost()` with:

```ts
  deletePost(entry: EntryDto): void {
    this.postActions.deletePost(entry).subscribe(() => this.leaveGonePost(entry.id));
  }
```

Add private methods before `markOpenedViewed()`:

```ts
  /** The gone post's row leaves the list as an excluded one does; its open article closes. */
  private leaveGonePost(id: number): void {
    this.markLeaving(id);
    if (this.routeState.openEntryId() === id) this.closeEntry();
    this.subscriptions.reloadCountsQuietly();
  }

  private dropRemovedPost({ id, network }: RemovedPost): void {
    this.toast.show({
      message: this.transloco.translate('reader.postDeletedThere', {
        network: SOCIAL_NETWORK_NAMES[network],
      }),
    });
    this.leaveGonePost(id);
  }
```

(The old doc comment on `deletePost()` moves to `leaveGonePost()` as shown; delete it from `deletePost()`.) Run Prettier on the file.

- [ ] **Step 10: Run the shell and counts specs to verify they pass**

Run, one after the other (never in parallel): `docker compose exec -T frontend npx jest src/app/reader/shell/entry-state-actions.service.spec.ts` then `docker compose exec -T frontend npx jest src/app/reader/article`
Expected: PASS, the #1972 "deleting my own post" block included.

- [ ] **Step 11: Typecheck and lint**

Run: `docker compose exec -T frontend npm run check` then `docker compose exec -T frontend npx ng build`
Expected: both green (i18n parity, ESLint `max-params`, Prettier).

- [ ] **Step 12: Commit**

```bash
git add frontend/src/app/reader/removed-posts.service.ts frontend/src/app/reader/entry-social.ts frontend/src/app/reader/article/social-counts frontend/src/app/reader/shell/entry-state-actions.service.ts frontend/src/app/reader/shell/entry-state-actions.service.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#1975): close and drop an entry whose post was deleted on its network"
```

---

### Task 4: Simplify, full gates, live check

- [ ] **Step 1: Merge `develop`** into the branch (`git fetch && git merge origin/develop`), resolve, re-run the touched tests.
- [ ] **Step 2: `/simplify`** over the branch diff (`git diff develop...HEAD`); apply its fixes; re-run the touched tests; commit `refactor(#1975): simplify pass`.
- [ ] **Step 3: Backend gates** (from `backend/`): `composer check` (cs + stan + tramp; `composer show larspohlmann/phptramp` first if tramp fails without a cause in this diff), `composer md` (every touched `src` file clean), `composer test:parallel` (SQLite) **and**, at the same time, `docker compose exec php composer test:parallel` (MySQL), then `composer infection:diff` (everything committed first). PhpStorm `mcp__phpstorm__lint_files` on every changed PHP file: no ERROR/WARNING.
- [ ] **Step 4: Frontend gates** — `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build`.
- [ ] **Step 5: Containers current** — the Docker `php` container serves this checkout and its DI container is rebuilt (`docker compose exec php bin/console cache:clear`); restart the worker (`docker compose restart worker`); `:4200` serves the new chunk (hard reload). No migration.
- [ ] **Step 6: Find the live-check entry (read-only).** Entry **685281** is Lars's own deleted `share:` test post; removing it locally **is** the intended outcome. Confirm it is still there before opening it:
  `docker compose exec -T php bin/console dbal:run-sql "SELECT id, feed_id, url FROM entry WHERE id = 685281"`
  At the time this plan was written (2026-11-02) that query already returned **no row** — the entry may have been removed by an earlier run. If it is absent, do not post or delete anything on any network to make a new one: stop and ask Lars (through the controller) for another entry whose post he has deleted.
- [ ] **Step 7: Live check on `http://localhost:4200`**, signed in as Lars, the network panel open. Open the entry once (`?entry=685281`, or the id Lars named). It is gone after this one open — observe everything in that one pass:
  - `GET /api/entries/685281/social/viewer` answers **410** `application/problem+json` with `type: "social_post_removed"`; `GET …/social` (counts) answers its 404 and does **not** cancel the viewer request.
  - The toast reads "Deleted on Bluesky — removed here." (or Mastodon), the article closes as Back does, the row fades out of the list, and no error toast covers it.
  - Afterwards the entry is gone: the `SELECT` from Step 6 returns no row; `GET /api/entries/685281` answers 404.
  - Optionally also open a post that merely cannot be seen (none required): it keeps the old broken-row behaviour and its entry stays.
  - Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .` — no new errors or deprecations; the home-server request appears as an outbound call through the guarded client.
- [ ] **Step 8: Report** to the controller: what passed, the live-check observations (or that it waits for a new entry id from Lars), and that the Bluesky confirmation path is verified by `BlueskyHomeServerCheckTest` and, live, only if the checked entry is a Bluesky post.
