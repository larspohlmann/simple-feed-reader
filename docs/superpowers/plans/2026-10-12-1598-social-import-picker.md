# Social import picker (#1598) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From Settings → Social Media, a user opens a rerunnable picker per connected account that lists the accounts they follow (marked new / subscribed / no longer followed), their lists and their custom feeds, and imports chosen follows as ordinary profile-RSS subscriptions or unsubscribes chosen no-longer-followed ones.

**Architecture:** The stored credential is read for the first time (`StoredCredential` over `SocialCredentialCipher::open`). Per-network `CandidateSourceInterface` strategies (Bluesky, Mastodon), dispatched by a keyed locator behind `SocialCandidatesInterface` exactly like `NetworkAccountVerifier`, page the networks through `GuardedApiClient` (which gains response headers for Mastodon's `Link` paging). `ImportCandidates` matches the follows against the user's subscriptions by identity (`Support/ProfileFeedUrl`, `Pass/FollowMatching`, `FollowMatcher`), marks a refused credential `needs_reconnect`, and orders the result. `SocialImporter` validates profile-feed URLs and runs `BulkSubscriber` and `SubscriptionService::unsubscribeAll`. A new thin `SocialImportController` exposes `GET …/{id}/import-candidates` (rate-limited) and `POST …/{id}/import`. The frontend adds a CDK dialog with a dialog-scoped service, opened from each connected account row.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM, symfony/http-client (`MockHttpClient` through `RoutedHttpClient` in tests); Angular 20 (standalone, signals, CDK Dialog), Transloco, Jest.

**Spec:** GitHub #1596 ("Import picker") and #1598, plus the binding design brief `/private/tmp/claude-501/-Users-lars-Documents-work-eigenes-simple-feed-reader/67891348-6acf-4396-bb8b-0e3735c2a620/scratchpad/1598-design-brief.md` (its rulings are restated in Global Constraints so this plan stands alone).

## Global Constraints

- CLAUDE.md "PHP code style" applies in full: `final readonly` services in a module root, role folders (`Model/`, `Pass/`, `Support/`, `Exception/`, a folder per interface), names without abbreviations, no boolean flag parameters, guard clauses, no `toArray()` in `App\Service`, typed exceptions next to the service, comments only when a future reader would otherwise get the code wrong.
- **HTTP statuses are `Symfony\Component\HttpFoundation\Response::HTTP_*` constants, in `src` and `tests`** — never a literal, never a home-made constant, `MockResponse` `http_code` included.
- Scope (user ruling): all three groups are listed, but **only followed accounts are importable/unsubscribable**. Lists and custom feeds are read-only rows with a "arrives with private feeds" note; #1599 makes them selectable.
- Follow feed URLs: Bluesky `https://bsky.app/profile/<did>/rss`; Mastodon `<account.url>.rss` when `account.url` is `https://host/@name` (path exactly `/@name`), else `https://<domain>/@<user>.rss`.
- Identity: Bluesky DID; Mastodon `user@domain` with a lower-cased domain (a local `acct` gets the instance host).
- "Subscribed" is decided by identity, never by exact URL. A Bluesky handle-keyed subscription that matched no follow is resolved handle → DID through public `com.atproto.identity.resolveHandle` (never the DID document); failure leaves it unmatched, never an error.
- At most **5000** follows (and Bluesky lists) per load; beyond that `truncated = true`.
- New subscriptions are tagged `Bluesky` / `Mastodon`; existing subscriptions are not retagged; nothing is fetched at import (`BulkSubscriber` schedules the first fetch).
- Rate limiter `social_import`: **10 per 15 minutes per user**, on the candidates endpoint only. Order there: ownership → needs-reconnect → budget → network. The import endpoint makes no network call and has no limiter.
- A refused credential while loading marks the account `needs_reconnect` and answers the existing `social_credentials_rejected` 422; an account already `needs_reconnect` answers 409 `social_account_needs_reconnect` without a network call.
- The domain takes Service values, never a DTO (`$request->toImport()`).
- Datetimes are naive UTC. No schema change in this issue: **no migration**.
- Commit format `type(#1598): subject`, no attribution lines. Branch `feature/1598-social-import-picker` (already checked out, off `develop` d299f89a6 which contains #1597).
- Backend tests: `php bin/phpunit <path>` from `backend/` (SQLite). Frontend tests only in the container: `docker compose exec -T frontend npm test -- <path>`; gate `docker compose exec -T frontend npm run check`.
- The plan's code fixes content, not final line wrapping: where `composer cs` (PSR-12, 120-column soft limit) or Prettier (100 columns) asks for a wrap, wrap.
- Every backend task ends with `composer check` (needs a warm cache: `bin/console cache:warmup`), `composer md` clean (it scans all of `src`; every touched file must be clean), and PhpStorm inspections (`mcp__phpstorm__lint_files`) on the changed PHP files — ERROR and WARNING block.

## Pre-flight notes for the executor

1. **Concurrent sessions share this checkout.** Run `git status` and `git branch --show-current` before every task; do not `checkout`, `reset` or `stash` without checking.
2. `BlueskyVerifier` is refactored in Task 2: its session fixtures in `tests/Service/Social/NetworkVerifier/BlueskyVerifierTest.php` (the three `MockResponse('{"did":…,"handle":…}')` answers) must gain an `"accessJwt"`, because a session without a token is now unusable.
3. `JsonNodeModel` (`Service/Bluesky/Model/`) reads both networks' JSON. It is network-agnostic despite its module; the new `Social → Bluesky` edge closes no cycle (nothing in `Service/Bluesky` names `Service/Social`). `ServiceModuleCycleRule` confirms it in `composer stan`.
4. Measured during planning: `MockHttpClient` hands the callback the URL **verbatim** (`did%3Aplc%3Aalice` stays encoded, `at://…` stays raw), and `getHeaders(false)` returns lower-cased names with list values (`['link' => ['<…>; rel="next"']]`). Route keys in tests are therefore exact strings.
5. Measured during planning: `SocialProblems::resolve()` with nine `match` arms is still PHPMD-clean; this plan adds two (seven today).
6. Bluesky limits `createSession` (about 30 per 5 minutes, 300 per day per account). The picker opens one session per load; the `social_import` limiter keeps a user well inside that.
7. `infection:diff` ignores untracked files and diffs against `origin/develop`: commit everything and `git fetch origin develop` before Task 10's run.
8. Do not reach the real network from tests. Controller and service tests stub `SocialCandidatesInterface` and route every request through `RoutedHttpClient`; controller tests use only DID-keyed Bluesky URLs so `FollowMatcher` never resolves a handle.

## File structure

Backend (`backend/`):

| File | Responsibility |
|---|---|
| `src/Service/Fetch/Model/ApiResponseModel.php` (modify) | carries response headers; `header()` |
| `src/Service/Fetch/GuardedApiClient.php` (modify) | fills the headers |
| `src/Service/Social/StoredCredential.php` | opens an account's sealed secret; unreadable → refused |
| `src/Service/Social/BlueskySessionOpener.php` | `createSession` and its classification (shared by verifier and source) |
| `src/Service/Social/Model/BlueskySessionModel.php` | accessJwt, did, handle, pdsUrl |
| `src/Service/Social/NetworkVerifier/BlueskyVerifier.php` (modify) | delegates the session to the opener |
| `src/Service/Social/BlueskyIdentityResolver.php` (modify) | `didOf()` becomes public |
| `src/Service/Social/Support/ProfileFeedUrl.php` | builds a Bluesky profile feed URL; reads any URL as a network profile feed key |
| `src/Service/Social/Model/{FollowedAccountModel,SocialCollectionModel,NetworkCandidatesModel,CollectedItemsModel}.php` | what a network answers |
| `src/Service/Social/CandidateSource/{CandidateSourceInterface,BlueskyCandidateSource,MastodonCandidateSource}.php` | per-network paging |
| `src/Service/Social/SocialCandidates/{SocialCandidatesInterface,NetworkSocialCandidates}.php` | keyed-locator facade (the seam controller tests stub) |
| `src/Service/Social/Model/MastodonSessionModel.php` | instance origin + token, builds authorised requests |
| `src/Service/Social/Support/{LinkHeader,MastodonAccount}.php` | `Link` rel=next on the same origin; a Mastodon account answer → `FollowedAccountModel` |
| `src/Service/Social/Model/{ImportCandidateState,ImportCandidateModel,ImportCandidatesModel,ProfileFeedSubscriptionModel}.php` | the picker's answer |
| `src/Service/Social/Pass/FollowMatching.php` | one matching run: follows keyed by identity, claims, leftovers |
| `src/Service/Social/Support/CandidateOrder.php` | follows by state then handle; collections by name |
| `src/Service/Social/FollowMatcher.php` | runs the matching, resolves leftover handles |
| `src/Service/Social/ImportCandidates.php` | orchestrates a picker load |
| `src/Service/Social/SocialAccountForUser.php` (modify) | `requireConnected()` |
| `src/Service/Social/Exception/{SocialAccountNeedsReconnectException,InvalidSocialImportFeedException}.php` | new failures |
| `src/Service/Social/Model/{SocialImportModel,SocialImportFeedModel,SocialImportResultModel}.php` | the import call |
| `src/Service/Social/SocialImporter.php` | validates, subscribes, unsubscribes |
| `src/Dto/Social/{SocialImportRequest,SocialImportFeedItem}.php` | request body |
| `src/Http/SocialImportJson.php` | response shapes |
| `src/Http/Problem/ExceptionProblems/SocialProblems.php` (modify) | two arms |
| `src/Controller/Api/SocialImportController.php` | the two endpoints |
| `config/packages/rate_limiter.yaml` (modify) | `social_import` |
| `tests/Support/{SocialAccounts,StubSocialCandidates}.php` | test fixtures |

Frontend (`frontend/`): `src/app/settings/social/social-import.service.ts` (+spec), `social-import-dialog.component.{ts,html,scss,spec.ts}`, `social-section.component.{ts,html,spec.ts}` (modify), `public/i18n/{en,de}.json` (modify).

Docs: `docs/security.md` (Social accounts section).

---

### Task 1: Response headers on the guarded API client

**Files:**
- Modify: `backend/src/Service/Fetch/Model/ApiResponseModel.php`
- Modify: `backend/src/Service/Fetch/GuardedApiClient.php:48`
- Test: `backend/tests/Service/Fetch/Model/ApiResponseModelTest.php`, `backend/tests/Service/Fetch/GuardedApiClientTest.php`

**Interfaces:**
- Produces: `new ApiResponseModel(int $status, string $body, array $headers = [])` with `@param array<array<string>> $headers` (lower-cased names, Symfony's `getHeaders(false)` shape); `ApiResponseModel::header(string $name): ?string` — first value, name matched case-insensitively.
- `grep -rn "new ApiResponseModel" src tests` finds exactly two sites (`GuardedApiClient.php:48`, `ApiResponseModelTest.php:27`); the third parameter defaults, so neither breaks.

- [ ] **Step 1: Write the failing tests**

Append to `ApiResponseModelTest`:

```php
    public function testAHeaderReadsItsFirstValueWhateverTheCaseOfItsName(): void
    {
        $response = new ApiResponseModel(
            Response::HTTP_OK,
            '',
            ['link' => ['<https://a.example.test/2>; rel="next"', 'second']],
        );

        self::assertSame('<https://a.example.test/2>; rel="next"', $response->header('Link'));
        self::assertNull($response->header('X-Absent'));
    }
```

Append to `GuardedApiClientTest`:

```php
    public function testTheAnswerCarriesItsResponseHeaders(): void
    {
        $http = new MockHttpClient(new MockResponse('[]', [
            'http_code' => Response::HTTP_OK,
            'response_headers' => ['Link' => '<https://api.example.test/x?page=2>; rel="next"'],
        ]));

        $response = $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame('<https://api.example.test/x?page=2>; rel="next"', $response->header('link'));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/Model/ApiResponseModelTest.php tests/Service/Fetch/GuardedApiClientTest.php`
Expected: 2 errors, `Call to undefined method App\Service\Fetch\Model\ApiResponseModel::header()`.

- [ ] **Step 3: Implement**

`ApiResponseModel`:

```php
final readonly class ApiResponseModel
{
    /** @param array<array<string>> $headers keyed by lower-cased name, as Symfony's HttpClient returns them */
    public function __construct(
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    // isSuccessful() and json() unchanged
```

`GuardedApiClient::send()`, the return line:

```php
            return new ApiResponseModel(
                $response->getStatusCode(),
                $response->getContent(false),
                $response->getHeaders(false),
            );
```

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Fetch`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `cd backend && bin/console cache:warmup && composer check && composer md`, then `mcp__phpstorm__lint_files` on the two `src` files.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Fetch backend/tests/Service/Fetch
git commit -m "feat(#1598): the guarded API client hands back response headers"
```

---

### Task 2: Reading the stored credential and opening a Bluesky session

**Files:**
- Create: `backend/src/Service/Social/StoredCredential.php`
- Create: `backend/src/Service/Social/BlueskySessionOpener.php`
- Create: `backend/src/Service/Social/Model/BlueskySessionModel.php`
- Modify: `backend/src/Service/Social/NetworkVerifier/BlueskyVerifier.php`
- Create: `backend/tests/Support/SocialAccounts.php`
- Test: `backend/tests/Service/Social/StoredCredentialTest.php`, `backend/tests/Service/Social/BlueskySessionOpenerTest.php`
- Modify: `backend/tests/Service/Social/NetworkVerifier/BlueskyVerifierTest.php`

**Interfaces:**
- Consumes: `SocialCredentialCipher::open(int $userId, SealedSecret $sealed): string` (throws `App\Service\Crypto\Exception\SecretUnreadableException`); `SocialAccount::getSealedCredential()`, `getUser()`, `getIdentity()`, `getServiceUrl()`; `BlueskyIdentityModel(string $did, string $pdsUrl)`.
- Produces:
  - `StoredCredential::open(SocialAccount $account): string` — throws `SocialCredentialsRejectedException` when the sealed secret no longer opens.
  - `BlueskySessionOpener::open(BlueskyIdentityModel $identity, string $appPassword): BlueskySessionModel` and `BlueskySessionOpener::openForAccount(SocialAccount $account): BlueskySessionModel` — throw `SocialCredentialsRejectedException` (401, or a session for another DID) and `SocialNetworkUnreachableException` (transport, other non-2xx, a session without `did`/`handle`/`accessJwt`).
  - `BlueskySessionModel` public readonly `accessJwt`, `did`, `handle`, `pdsUrl`.
  - `BlueskyVerifier::__construct(BlueskyIdentityResolver $identities, BlueskySessionOpener $sessions)`.
  - Test support `SocialAccounts::cipher()`, `::bluesky()` (identity `did:plc:alice`, serviceUrl `https://pds.example.test`, secret `SocialAccounts::APP_PASSWORD`), `::mastodon()` (identity `alice@mastodon.example`, serviceUrl `https://mastodon.example`, secret `SocialAccounts::ACCESS_TOKEN`); owner id `SocialAccounts::OWNER_ID` = 7.

- [ ] **Step 1: Write the test support and the failing tests**

`tests/Support/SocialAccounts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;

/** In-memory social accounts for unit tests, their secrets sealed with cipher() for owner OWNER_ID. */
final class SocialAccounts
{
    use AssignsEntityIds;

    public const int OWNER_ID = 7;
    public const string APP_PASSWORD = 'abcd-efgh-ijkl-mnop';
    public const string ACCESS_TOKEN = 'token-1234567890';

    public static function cipher(): SocialCredentialCipher
    {
        return new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('k', 32)));
    }

    public static function bluesky(): SocialAccount
    {
        return new SocialAccount(
            self::owner(),
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://pds.example.test',
            self::cipher()->seal(self::OWNER_ID, self::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    public static function mastodon(): SocialAccount
    {
        return new SocialAccount(
            self::owner(),
            SocialNetwork::Mastodon,
            'alice@mastodon.example',
            '@alice@mastodon.example',
            'https://mastodon.example',
            self::cipher()->seal(self::OWNER_ID, self::ACCESS_TOKEN),
            '7890',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    private static function owner(): User
    {
        return self::withId(new User('alice@example.test', new \DateTimeImmutable('2026-07-01 10:00:00')), self::OWNER_ID);
    }
}
```

`tests/Service/Social/StoredCredentialTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\StoredCredential;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class StoredCredentialTest extends TestCase
{
    public function testTheSealedSecretOpensForItsOwner(): void
    {
        $credential = new StoredCredential(SocialAccounts::cipher());

        self::assertSame(SocialAccounts::APP_PASSWORD, $credential->open(SocialAccounts::bluesky()));
    }

    public function testASecretThatNoLongerOpensReadsAsARefusedCredential(): void
    {
        $rotatedKey = new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('x', 32)));

        $this->expectException(SocialCredentialsRejectedException::class);

        (new StoredCredential($rotatedKey))->open(SocialAccounts::bluesky());
    }
}
```

`tests/Service/Social/BlueskySessionOpenerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskySessionOpenerTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';

    private function opener(RoutedHttpClient $http): BlueskySessionOpener
    {
        $guard = new UrlGuard(new StaticDnsResolver(['pds.example.test' => ['93.184.216.34']]), new IpValidator());

        return new BlueskySessionOpener(
            FetchWiring::guardedApiClient($http->client(), $guard),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }

    public function testTheStoredAppPasswordOpensASessionAtTheAccountsPds(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}'),
        ]);

        $session = $this->opener($http)->openForAccount(SocialAccounts::bluesky());

        self::assertSame('jwt-1', $session->accessJwt);
        self::assertSame('did:plc:alice', $session->did);
        self::assertSame('alice.bsky.social', $session->handle);
        self::assertSame('https://pds.example.test', $session->pdsUrl);
        self::assertSame('{"identifier":"did:plc:alice","password":"abcd-efgh-ijkl-mnop"}', $http->requests[0]['body']);
    }

    public function testARefusedAppPasswordIsRejected(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => new MockResponse(
                '{"error":"AuthenticationRequired"}',
                ['http_code' => Response::HTTP_UNAUTHORIZED],
            ),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->opener($http)->openForAccount(SocialAccounts::bluesky());
    }

    public function testASessionForAnotherDidIsRejected(): void
    {
        $http = new RoutedHttpClient([
            self::SESSION => new MockResponse('{"did":"did:plc:mallory","handle":"mallory.bsky.social","accessJwt":"jwt"}'),
        ]);

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->opener($http)->openForAccount(SocialAccounts::bluesky());
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unusableAnswers(): iterable
    {
        yield 'an outage' => [new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE])];
        yield 'a session without a token' => [new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social"}')];
        yield 'a session without a handle' => [new MockResponse('{"did":"did:plc:alice","accessJwt":"jwt"}')];
        yield 'a session without a did' => [new MockResponse('{"handle":"alice.bsky.social","accessJwt":"jwt"}')];
        yield 'a transport failure' => [new MockResponse('', ['error' => 'Connection refused'])];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsUnreachable(MockResponse $answer): void
    {
        $http = new RoutedHttpClient([self::SESSION => $answer]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->opener($http)->openForAccount(SocialAccounts::bluesky());
    }
}
```

In `BlueskyVerifierTest`: replace `verifier()`'s last lines with

```php
        $api = FetchWiring::guardedApiClient($http->client(), $guard);

        return new BlueskyVerifier(
            new BlueskyIdentityResolver($api),
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
        );
```

(add the `use` lines for `BlueskySessionOpener`, `StoredCredential`, `SocialAccounts`), and add `,"accessJwt":"jwt"` to the three session answers (the `SESSION` routes in `testAnAcceptedAppPasswordVerifiesTheAccountAtItsPds`, `testASessionForAnotherDidIsRejected`, `testATrailingSlashEndpointCreatesTheSessionWithoutADoubleSlash`), e.g. `'{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt"}'`.

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: errors — `Class "App\Service\Social\StoredCredential" not found`, `Class "App\Service\Social\BlueskySessionOpener" not found` (the verifier test errors on the same class).

- [ ] **Step 3: Implement**

`src/Service/Social/StoredCredential.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Exception\SocialCredentialsRejectedException;

final readonly class StoredCredential
{
    public function __construct(private SocialCredentialCipher $cipher)
    {
    }

    /** @throws SocialCredentialsRejectedException when the sealed secret no longer opens: only a reconnect helps */
    public function open(SocialAccount $account): string
    {
        try {
            return $this->cipher->open($account->getUser()->requireId(), $account->getSealedCredential());
        } catch (SecretUnreadableException $exception) {
            throw new SocialCredentialsRejectedException(
                'The stored credential can no longer be read; reconnect the account.',
                previous: $exception,
            );
        }
    }
}
```

`src/Service/Social/Model/BlueskySessionModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class BlueskySessionModel
{
    public function __construct(
        public string $accessJwt,
        public string $did,
        public string $handle,
        public string $pdsUrl,
    ) {
    }
}
```

`src/Service/Social/BlueskySessionOpener.php` (the classification moves here verbatim from `BlueskyVerifier`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskyIdentityModel;
use App\Service\Social\Model\BlueskySessionModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class BlueskySessionOpener
{
    private const string CREATE_SESSION = '/xrpc/com.atproto.server.createSession';

    public function __construct(
        private GuardedApiClient $api,
        private StoredCredential $storedCredential,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function openForAccount(SocialAccount $account): BlueskySessionModel
    {
        return $this->open(
            new BlueskyIdentityModel($account->getIdentity(), $account->getServiceUrl()),
            $this->storedCredential->open($account),
        );
    }

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function open(BlueskyIdentityModel $identity, string $appPassword): BlueskySessionModel
    {
        $answer = $this->createSession($identity, $appPassword);
        if (Response::HTTP_UNAUTHORIZED === $answer->status) {
            throw new SocialCredentialsRejectedException('Bluesky refused this handle and app password.');
        }

        $session = JsonNodeModel::of($answer->isSuccessful() ? $answer->json() : []);
        $did = $session->string('did');
        $handle = $session->string('handle');
        $accessJwt = $session->string('accessJwt');
        if (null === $did || null === $handle || null === $accessJwt) {
            throw new SocialNetworkUnreachableException('Bluesky gave no usable session.');
        }
        if ($did !== $identity->did) {
            throw new SocialCredentialsRejectedException('Bluesky opened a session for a different account.');
        }

        return new BlueskySessionModel($accessJwt, $did, $handle, $identity->pdsUrl);
    }

    private function createSession(BlueskyIdentityModel $identity, string $appPassword): ApiResponseModel
    {
        try {
            return $this->api->send(ApiRequestModel::postJson(
                $identity->pdsUrl . self::CREATE_SESSION,
                ['identifier' => $identity->did, 'password' => $appPassword],
            ));
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Bluesky server could not be reached.',
                previous: $exception,
            );
        }
    }
}
```

`BlueskyVerifier` becomes:

```php
final readonly class BlueskyVerifier implements NetworkVerifierInterface
{
    public function __construct(
        private BlueskyIdentityResolver $identities,
        private BlueskySessionOpener $sessions,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $identity = $this->identities->resolve($credentials->address);
        $session = $this->sessions->open($identity, $credentials->secret);

        return new VerifiedAccountModel($identity->did, '@' . $session->handle, $identity->pdsUrl);
    }
}
```

(drop the now-unused imports: `FetchException`, `GuardedApiClient`, `ApiRequestModel`, both exceptions, `Response`; add `BlueskySessionOpener`).

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Controller/Api/SocialAccountControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `cd backend && composer check && composer md`, then `mcp__phpstorm__lint_files` on the four `src` files.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Social backend/tests/Support/SocialAccounts.php backend/tests/Service/Social
git commit -m "feat(#1598): read the stored credential and open a Bluesky session from it"
```

---

### Task 3: Candidate shapes, profile feed URLs and the Bluesky source

**Files:**
- Create: `backend/src/Service/Social/Support/ProfileFeedUrl.php`
- Create: `backend/src/Service/Social/Model/FollowedAccountModel.php`, `SocialCollectionModel.php`, `NetworkCandidatesModel.php`, `CollectedItemsModel.php`
- Create: `backend/src/Service/Social/CandidateSource/CandidateSourceInterface.php`, `BlueskyCandidateSource.php`
- Create: `backend/src/Service/Social/SocialCandidates/SocialCandidatesInterface.php`, `NetworkSocialCandidates.php`
- Test: `backend/tests/Service/Social/Support/ProfileFeedUrlTest.php`, `backend/tests/Service/Social/SocialCandidates/NetworkSocialCandidatesTest.php`, `backend/tests/Service/Social/CandidateSource/BlueskyCandidateSourceTest.php`

**Interfaces:**
- Consumes: `BlueskySessionOpener::openForAccount()` (Task 2), `GuardedApiClient::send()`, `JsonNodeModel` (`of()`, `string()`, `type()`, `nodes()`).
- Produces:
  - `ProfileFeedUrl::bluesky(string $did): string` → `https://bsky.app/profile/<did>/rss`.
  - `ProfileFeedUrl::identityOf(SocialNetwork $network, string $url): ?string` — Bluesky: host `bsky.app` (any case, http/https), path `/profile/<actor>/rss` with an optional trailing slash, actor URL-decoded; a `did:` actor as is, a handle lower-cased. Mastodon: any host, path `/@<user>.rss` → `user@<lower-cased host>`. `null` means "not a profile feed of that network".
  - `FollowedAccountModel(string $identity, string $handle, ?string $displayName, string $feedUrl)`.
  - `SocialCollectionModel(string $id, string $name)`.
  - `NetworkCandidatesModel(list<FollowedAccountModel> $follows, list<SocialCollectionModel> $lists, list<SocialCollectionModel> $customFeeds, bool $truncated)`.
  - `CollectedItemsModel::MAX_ITEMS = 5000`; `CollectedItemsModel::capped(list<JsonNodeModel> $items): CollectedItemsModel` with public `items` (at most MAX_ITEMS) and `truncated`.
  - `CandidateSourceInterface` (tag `app.social_candidate_source`): `static network(): string`, `candidates(SocialAccount $account): NetworkCandidatesModel` (throws rejected / unreachable).
  - `SocialCandidatesInterface::candidates(SocialAccount $account): NetworkCandidatesModel`, implemented by `NetworkSocialCandidates` (locator keyed by `SocialNetwork` value).

- [ ] **Step 1: Write the failing tests**

`tests/Service/Social/Support/ProfileFeedUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\Support\ProfileFeedUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProfileFeedUrlTest extends TestCase
{
    public function testABlueskyProfileFeedIsBuiltFromTheDid(): void
    {
        self::assertSame('https://bsky.app/profile/did:plc:bob/rss', ProfileFeedUrl::bluesky('did:plc:bob'));
    }

    /** @return iterable<string, array{SocialNetwork, string, ?string}> */
    public static function urls(): iterable
    {
        yield 'bluesky by did' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/did:plc:bob/rss', 'did:plc:bob'];
        yield 'bluesky over http, upper-case host, trailing slash' => [
            SocialNetwork::Bluesky,
            'HTTP://BSKY.APP/profile/did:plc:bob/rss/',
            'did:plc:bob',
        ];
        yield 'bluesky encoded did' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/did%3Aplc%3Abob/rss', 'did:plc:bob'];
        yield 'bluesky handle, lower-cased' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/Bob.Example.com/rss', 'bob.example.com'];
        yield 'bluesky profile page, not its feed' => [SocialNetwork::Bluesky, 'https://bsky.app/profile/did:plc:bob', null];
        yield 'bluesky path on another host' => [SocialNetwork::Bluesky, 'https://example.com/profile/did:plc:bob/rss', null];
        yield 'bluesky over ftp' => [SocialNetwork::Bluesky, 'ftp://bsky.app/profile/did:plc:bob/rss', null];
        yield 'mastodon, host lower-cased' => [SocialNetwork::Mastodon, 'https://Mastodon.Example/@bob.rss', 'bob@mastodon.example'];
        yield 'mastodon profile page, not its feed' => [SocialNetwork::Mastodon, 'https://mastodon.example/@bob', null];
        yield 'mastodon remote form' => [SocialNetwork::Mastodon, 'https://mastodon.example/@bob@other.example.rss', null];
        yield 'mastodon users path' => [SocialNetwork::Mastodon, 'https://mastodon.example/users/bob.rss', null];
        yield 'a bluesky feed read as mastodon' => [SocialNetwork::Mastodon, 'https://bsky.app/profile/did:plc:bob/rss', null];
        yield 'no host' => [SocialNetwork::Mastodon, 'https:///@bob.rss', null];
    }

    #[DataProvider('urls')]
    public function testAUrlReadsAsTheIdentityItFollows(SocialNetwork $network, string $url, ?string $identity): void
    {
        self::assertSame($identity, ProfileFeedUrl::identityOf($network, $url));
    }
}
```

`tests/Service/Social/SocialCandidates/NetworkSocialCandidatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\CandidateSource\CandidateSourceInterface;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\SocialCandidates\NetworkSocialCandidates;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NetworkSocialCandidatesTest extends TestCase
{
    private function sourceAnswering(NetworkCandidatesModel $answer): CandidateSourceInterface
    {
        return new class ($answer) implements CandidateSourceInterface {
            public function __construct(private NetworkCandidatesModel $answer)
            {
            }

            public static function network(): string
            {
                return 'stub';
            }

            public function candidates(SocialAccount $account): NetworkCandidatesModel
            {
                return $this->answer;
            }
        };
    }

    public function testEachAccountReachesItsOwnNetworksSource(): void
    {
        $bluesky = new NetworkCandidatesModel([], [], [], false);
        $mastodon = new NetworkCandidatesModel([], [], [], true);
        $candidates = new NetworkSocialCandidates(new ServiceLocator([
            'bluesky' => fn () => $this->sourceAnswering($bluesky),
            'mastodon' => fn () => $this->sourceAnswering($mastodon),
        ]));

        self::assertSame($bluesky, $candidates->candidates(SocialAccounts::bluesky()));
        self::assertSame($mastodon, $candidates->candidates(SocialAccounts::mastodon()));
    }
}
```

`tests/Service/Social/CandidateSource/BlueskyCandidateSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\CandidateSource;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\CandidateSource\BlueskyCandidateSource;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class BlueskyCandidateSourceTest extends TestCase
{
    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const string PREFERENCES = 'GET https://pds.example.test/xrpc/app.bsky.actor.getPreferences';
    private const string FOLLOWS = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getFollows'
        . '?actor=did%3Aplc%3Aalice&limit=100';
    private const string LISTS = 'GET https://public.api.bsky.app/xrpc/app.bsky.graph.getLists'
        . '?actor=did%3Aplc%3Aalice&limit=100';
    private const string GENERATORS = 'GET https://public.api.bsky.app/xrpc/app.bsky.feed.getFeedGenerators?';
    private const string DISCOVER = 'at://did:plc:whats-hot/app.bsky.feed.generator/discover';
    private const string GONE = 'at://did:plc:retired/app.bsky.feed.generator/gone';

    private function source(RoutedHttpClient $http): BlueskyCandidateSource
    {
        $address = ['93.184.216.34'];
        $guard = new UrlGuard(
            new StaticDnsResolver(['public.api.bsky.app' => $address, 'pds.example.test' => $address]),
            new IpValidator(),
        );
        $api = FetchWiring::guardedApiClient($http->client(), $guard);

        return new BlueskyCandidateSource(
            $api,
            new BlueskySessionOpener($api, new StoredCredential(SocialAccounts::cipher())),
        );
    }

    /**
     * @param array<string, MockResponse> $overrides
     *
     * @return array<string, MockResponse>
     */
    private function routes(array $overrides = []): array
    {
        return $overrides + [
            self::SESSION => new MockResponse('{"did":"did:plc:alice","handle":"alice.bsky.social","accessJwt":"jwt-1"}'),
            self::PREFERENCES => self::json(['preferences' => []]),
            self::FOLLOWS => self::json(['follows' => []]),
            self::LISTS => self::json(['lists' => []]),
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $uris */
    private static function feedsQuery(array $uris): string
    {
        return implode('&', array_map(static fn (string $uri): string => 'feeds=' . rawurlencode($uri), $uris));
    }

    /** @return list<array{did: string, handle: string}> */
    private static function manyFollows(int $count): array
    {
        return array_map(
            static fn (int $index): array => ['did' => 'did:plc:f' . $index, 'handle' => 'f' . $index . '.bsky.social'],
            range(1, $count),
        );
    }

    public function testTheNetworkKeyIsBluesky(): void
    {
        self::assertSame('bluesky', BlueskyCandidateSource::network());
    }

    public function testFollowsArePagedByCursorAndReadAsDidKeyedProfileFeeds(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json([
                'follows' => [
                    ['did' => 'did:plc:bob', 'handle' => 'bob.example.com', 'displayName' => 'Bob'],
                    ['handle' => 'no-did.example.com'],
                ],
                'cursor' => 'page 2',
            ]),
            self::FOLLOWS . '&cursor=page%202' => self::json([
                'follows' => [['did' => 'did:plc:carol', 'handle' => 'carol.bsky.social', 'displayName' => '']],
            ]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

        self::assertEquals([
            new FollowedAccountModel('did:plc:bob', '@bob.example.com', 'Bob', 'https://bsky.app/profile/did:plc:bob/rss'),
            new FollowedAccountModel('did:plc:carol', '@carol.bsky.social', null, 'https://bsky.app/profile/did:plc:carol/rss'),
        ], $candidates->follows);
        self::assertFalse($candidates->truncated);
    }

    public function testOnlyCuratedListsAreOffered(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => self::json(['lists' => [
                ['uri' => 'at://did:plc:alice/app.bsky.graph.list/news', 'name' => 'News', 'purpose' => 'app.bsky.graph.defs#curatelist'],
                ['uri' => 'at://did:plc:alice/app.bsky.graph.list/blocks', 'name' => 'Blocks', 'purpose' => 'app.bsky.graph.defs#modlist'],
            ]]),
        ]));

        $lists = $this->source($http)->candidates(SocialAccounts::bluesky())->lists;

        self::assertEquals([new SocialCollectionModel('at://did:plc:alice/app.bsky.graph.list/news', 'News')], $lists);
    }

    public function testSavedFeedsAreNamedByTheirGeneratorAndAnUnknownOneKeepsItsUri(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::json(['preferences' => [
                ['$type' => 'app.bsky.actor.defs#adultContentPref', 'enabled' => false],
                ['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => [
                    ['type' => 'timeline', 'value' => 'following', 'pinned' => true, 'id' => '1'],
                    ['type' => 'feed', 'value' => self::DISCOVER, 'pinned' => true, 'id' => '2'],
                    ['type' => 'feed', 'value' => self::GONE, 'pinned' => false, 'id' => '3'],
                    ['type' => 'list', 'value' => 'at://did:plc:alice/app.bsky.graph.list/news', 'pinned' => false, 'id' => '4'],
                ]],
            ]]),
            self::GENERATORS . 'feeds=' . rawurlencode(self::DISCOVER) . '&feeds=' . rawurlencode(self::GONE)
                => self::json(['feeds' => [['uri' => self::DISCOVER, 'displayName' => 'Discover']]]),
        ]));

        $customFeeds = $this->source($http)->candidates(SocialAccounts::bluesky())->customFeeds;

        self::assertEquals([
            new SocialCollectionModel(self::DISCOVER, 'Discover'),
            new SocialCollectionModel(self::GONE, self::GONE),
        ], $customFeeds);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[1]['headers']);
    }

    public function testGeneratorNamesAreAskedForTwentyFiveFeedsAtATime(): void
    {
        $uris = array_map(
            static fn (int $index): string => 'at://did:plc:maker/app.bsky.feed.generator/f' . $index,
            range(1, 26),
        );
        $items = array_map(static fn (string $uri): array => ['type' => 'feed', 'value' => $uri], $uris);
        $http = new RoutedHttpClient($this->routes([
            self::PREFERENCES => self::json(['preferences' => [
                ['$type' => 'app.bsky.actor.defs#savedFeedsPrefV2', 'items' => $items],
            ]]),
            self::GENERATORS . self::feedsQuery(\array_slice($uris, 0, 25)) => self::json(['feeds' => []]),
            self::GENERATORS . self::feedsQuery(\array_slice($uris, 25)) => self::json(['feeds' => []]),
        ]));

        $customFeeds = $this->source($http)->candidates(SocialAccounts::bluesky())->customFeeds;

        self::assertCount(26, $customFeeds);
        $generatorCalls = array_filter(
            $http->requestedUrls(),
            static fn (string $url): bool => str_contains($url, 'getFeedGenerators'),
        );
        self::assertCount(2, $generatorCalls);
    }

    public function testFollowsBeyondTheCapAreCutAndMarkedTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json([
                'follows' => self::manyFollows(CollectedItemsModel::MAX_ITEMS + 1),
                'cursor' => 'more',
            ]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertTrue($candidates->truncated);
        self::assertNotContains(
            'https://public.api.bsky.app/xrpc/app.bsky.graph.getFollows?actor=did%3Aplc%3Aalice&limit=100&cursor=more',
            $http->requestedUrls(),
        );
    }

    public function testExactlyTheCapStillAsksForTheNextPageAndIsNotTruncated(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json([
                'follows' => self::manyFollows(CollectedItemsModel::MAX_ITEMS),
                'cursor' => 'more',
            ]),
            self::FOLLOWS . '&cursor=more' => self::json(['follows' => []]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::bluesky());

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertFalse($candidates->truncated);
    }

    public function testAnEmptyPageEndsPagingEvenWithACursor(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => self::json(['follows' => [], 'cursor' => 'again']),
        ]));

        $this->source($http)->candidates(SocialAccounts::bluesky());

        $followCalls = array_filter(
            $http->requestedUrls(),
            static fn (string $url): bool => str_contains($url, 'getFollows'),
        );
        self::assertCount(1, $followCalls);
    }

    public function testARefusedAppPasswordIsRejectedBeforeAnyPublicCall(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::SESSION => new MockResponse('{"error":"AuthenticationRequired"}', ['http_code' => Response::HTTP_UNAUTHORIZED]),
        ]));

        try {
            $this->source($http)->candidates(SocialAccounts::bluesky());
            self::fail('A refused app password must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testAFailingPublicCallIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->candidates(SocialAccounts::bluesky());
    }

    public function testATransportFailureIsUnreachable(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWS => new MockResponse('', ['error' => 'Connection refused']),
        ]));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->candidates(SocialAccounts::bluesky());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support tests/Service/Social/SocialCandidates tests/Service/Social/CandidateSource`
Expected: errors, `Class "App\Service\Social\Support\ProfileFeedUrl" not found` and the same for `NetworkCandidatesModel` / `BlueskyCandidateSource`.

- [ ] **Step 3: Implement**

`src/Service/Social/Support/ProfileFeedUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Enum\SocialNetwork;

final class ProfileFeedUrl
{
    private const string BLUESKY_HOST = 'bsky.app';
    private const string BLUESKY_PATH = '#^/profile/([^/]+)/rss/?$#';
    private const string MASTODON_PATH = '#^/@([^/@]+)\.rss$#';

    public static function bluesky(string $did): string
    {
        return 'https://' . self::BLUESKY_HOST . '/profile/' . $did . '/rss';
    }

    /** @return string|null the DID, handle or user@host the URL follows; null when it is no profile feed of $network */
    public static function identityOf(SocialNetwork $network, string $url): ?string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host']) || !self::isWeb($parts['scheme'] ?? '')) {
            return null;
        }
        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        return match ($network) {
            SocialNetwork::Bluesky => self::blueskyIdentity($host, $path),
            SocialNetwork::Mastodon => self::mastodonIdentity($host, $path),
        };
    }

    private static function isWeb(string $scheme): bool
    {
        return \in_array(strtolower($scheme), ['http', 'https'], true);
    }

    private static function blueskyIdentity(string $host, string $path): ?string
    {
        if (self::BLUESKY_HOST !== $host || 1 !== preg_match(self::BLUESKY_PATH, $path, $match)) {
            return null;
        }
        $actor = rawurldecode($match[1]);

        return str_starts_with($actor, 'did:') ? $actor : strtolower($actor);
    }

    private static function mastodonIdentity(string $host, string $path): ?string
    {
        return 1 === preg_match(self::MASTODON_PATH, $path, $match) ? $match[1] . '@' . $host : null;
    }

    private function __construct()
    {
    }
}
```

The four models (`src/Service/Social/Model/`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class FollowedAccountModel
{
    public function __construct(
        public string $identity,
        public string $handle,
        public ?string $displayName,
        public string $feedUrl,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialCollectionModel
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class NetworkCandidatesModel
{
    /**
     * @param list<FollowedAccountModel>  $follows
     * @param list<SocialCollectionModel> $lists
     * @param list<SocialCollectionModel> $customFeeds
     */
    public function __construct(
        public array $follows,
        public array $lists,
        public array $customFeeds,
        public bool $truncated,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Bluesky\Model\JsonNodeModel;

final readonly class CollectedItemsModel
{
    public const int MAX_ITEMS = 5000;

    /** @param list<JsonNodeModel> $items */
    private function __construct(
        public array $items,
        public bool $truncated,
    ) {
    }

    /** @param list<JsonNodeModel> $items everything paged in, which may run past MAX_ITEMS */
    public static function capped(array $items): self
    {
        return new self(\array_slice($items, 0, self::MAX_ITEMS), \count($items) > self::MAX_ITEMS);
    }
}
```

`src/Service/Social/CandidateSource/CandidateSourceInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\NetworkCandidatesModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's follows, lists and custom feeds, keyed by its SocialNetwork value in NetworkSocialCandidates' locator. */
#[AutoconfigureTag('app.social_candidate_source')]
interface CandidateSourceInterface
{
    public static function network(): string;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function candidates(SocialAccount $account): NetworkCandidatesModel;
}
```

`src/Service/Social/SocialCandidates/SocialCandidatesInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\NetworkCandidatesModel;

interface SocialCandidatesInterface
{
    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function candidates(SocialAccount $account): NetworkCandidatesModel;
}
```

`src/Service/Social/SocialCandidates/NetworkSocialCandidates.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\SocialCandidates;

use App\Entity\SocialAccount;
use App\Service\Social\CandidateSource\CandidateSourceInterface;
use App\Service\Social\Model\NetworkCandidatesModel;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkSocialCandidates implements SocialCandidatesInterface
{
    public function __construct(
        #[AutowireLocator('app.social_candidate_source', defaultIndexMethod: 'network')]
        private ContainerInterface $sources,
    ) {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $source = $this->sources->get($account->getNetwork()->value);
        \assert($source instanceof CandidateSourceInterface);

        return $source->candidates($account);
    }
}
```

`src/Service/Social/CandidateSource/BlueskyCandidateSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskySessionOpener;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\Support\ProfileFeedUrl;

final readonly class BlueskyCandidateSource implements CandidateSourceInterface
{
    private const string APP_VIEW = 'https://public.api.bsky.app/xrpc/';
    private const string GET_PREFERENCES = '/xrpc/app.bsky.actor.getPreferences';
    private const string SAVED_FEEDS = 'app.bsky.actor.defs#savedFeedsPrefV2';
    private const string CURATED_LIST = 'app.bsky.graph.defs#curatelist';
    private const int PAGE_SIZE = 100;
    private const int GENERATORS_PER_CALL = 25;

    public function __construct(
        private GuardedApiClient $api,
        private BlueskySessionOpener $sessions,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $customFeeds = $this->customFeeds($this->sessions->openForAccount($account));
        $follows = $this->collect(self::actorPage('app.bsky.graph.getFollows', $account->getIdentity()), 'follows');
        $lists = $this->collect(self::actorPage('app.bsky.graph.getLists', $account->getIdentity()), 'lists');

        return new NetworkCandidatesModel(
            self::follows($follows->items),
            self::curatedLists($lists->items),
            $customFeeds,
            $follows->truncated || $lists->truncated,
        );
    }

    private static function actorPage(string $method, string $did): string
    {
        return self::APP_VIEW . $method . '?actor=' . rawurlencode($did) . '&limit=' . self::PAGE_SIZE;
    }

    private function collect(string $firstPage, string $field): CollectedItemsModel
    {
        $items = [];
        $cursor = null;
        do {
            $page = $this->publicJson(null === $cursor ? $firstPage : $firstPage . '&cursor=' . rawurlencode($cursor));
            $fresh = $page->nodes($field);
            $items = [...$items, ...$fresh];
            $cursor = $page->string('cursor');
        } while (null !== $cursor && [] !== $fresh && \count($items) <= CollectedItemsModel::MAX_ITEMS);

        return CollectedItemsModel::capped($items);
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<FollowedAccountModel>
     */
    private static function follows(array $nodes): array
    {
        $follows = [];
        foreach ($nodes as $node) {
            $did = $node->string('did');
            $handle = $node->string('handle');
            if (null !== $did && null !== $handle) {
                $follows[] = new FollowedAccountModel(
                    $did,
                    '@' . $handle,
                    $node->string('displayName'),
                    ProfileFeedUrl::bluesky($did),
                );
            }
        }

        return $follows;
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<SocialCollectionModel>
     */
    private static function curatedLists(array $nodes): array
    {
        $lists = [];
        foreach ($nodes as $node) {
            $uri = $node->string('uri');
            $name = $node->string('name');
            if (self::CURATED_LIST === $node->string('purpose') && null !== $uri && null !== $name) {
                $lists[] = new SocialCollectionModel($uri, $name);
            }
        }

        return $lists;
    }

    /** @return list<SocialCollectionModel> */
    private function customFeeds(BlueskySessionModel $session): array
    {
        $uris = $this->savedFeedUris($session);
        $names = $this->generatorNames($uris);

        return array_map(
            static fn (string $uri): SocialCollectionModel => new SocialCollectionModel($uri, $names[$uri] ?? $uri),
            $uris,
        );
    }

    /** @return list<string> */
    private function savedFeedUris(BlueskySessionModel $session): array
    {
        $preferences = $this->json(ApiRequestModel::get(
            $session->pdsUrl . self::GET_PREFERENCES,
            ['Authorization' => 'Bearer ' . $session->accessJwt],
        ))->nodes('preferences');

        $uris = [];
        foreach ($preferences as $preference) {
            if (self::SAVED_FEEDS === $preference->type()) {
                $uris = [...$uris, ...self::feedUris($preference->nodes('items'))];
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * @param list<JsonNodeModel> $items
     *
     * @return list<string>
     */
    private static function feedUris(array $items): array
    {
        $uris = [];
        foreach ($items as $item) {
            $uri = $item->string('value');
            if ('feed' === $item->string('type') && null !== $uri) {
                $uris[] = $uri;
            }
        }

        return $uris;
    }

    /**
     * @param list<string> $uris
     *
     * @return array<string, string>
     */
    private function generatorNames(array $uris): array
    {
        $names = [];
        foreach (array_chunk($uris, self::GENERATORS_PER_CALL) as $batch) {
            $query = implode('&', array_map(static fn (string $uri): string => 'feeds=' . rawurlencode($uri), $batch));
            $answer = $this->publicJson(self::APP_VIEW . 'app.bsky.feed.getFeedGenerators?' . $query);
            $names = [...$names, ...self::namesIn($answer)];
        }

        return $names;
    }

    /** @return array<string, string> */
    private static function namesIn(JsonNodeModel $answer): array
    {
        $names = [];
        foreach ($answer->nodes('feeds') as $generator) {
            $uri = $generator->string('uri');
            $name = $generator->string('displayName');
            if (null !== $uri && null !== $name) {
                $names[$uri] = $name;
            }
        }

        return $names;
    }

    private function publicJson(string $url): JsonNodeModel
    {
        return $this->json(ApiRequestModel::get($url));
    }

    private function json(ApiRequestModel $request): JsonNodeModel
    {
        try {
            $answer = $this->api->send($request);
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException('Bluesky could not be reached.', previous: $exception);
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('Bluesky answered %d.', $answer->status));
        }

        return JsonNodeModel::of($answer->json());
    }
}
```

(`[...$names, ...$other]` with string keys merges by key in PHP 8.1+, which is what `namesIn` needs.)

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `cd backend && composer check && composer md`, then `mcp__phpstorm__lint_files` on the new `src` files. `composer stan` also confirms the new `Social → Bluesky` edge closes no cycle and every class sits in its role folder.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Social backend/tests/Service/Social
git commit -m "feat(#1598): page a Bluesky account's follows, lists and saved feeds"
```

---

### Task 4: The Mastodon source

**Files:**
- Create: `backend/src/Service/Social/Model/MastodonSessionModel.php`
- Create: `backend/src/Service/Social/Support/LinkHeader.php`, `backend/src/Service/Social/Support/MastodonAccount.php`
- Create: `backend/src/Service/Social/CandidateSource/MastodonCandidateSource.php`
- Test: `backend/tests/Service/Social/Support/LinkHeaderTest.php`, `backend/tests/Service/Social/CandidateSource/MastodonCandidateSourceTest.php`

**Interfaces:**
- Consumes: `ApiResponseModel::header()` (Task 1), `StoredCredential::open()` (Task 2), `CollectedItemsModel`, `NetworkCandidatesModel`, `FollowedAccountModel`, `SocialCollectionModel`, `CandidateSourceInterface` (Task 3), `UrlOrigin::of()`.
- Produces:
  - `MastodonSessionModel(string $instanceUrl, string $accessToken)` with `url(string $path): string`, `get(string $url): ApiRequestModel` (Bearer), `host(): string`.
  - `LinkHeader::nextOn(string $origin, ?string $header): ?string` — the `rel="next"` target only when `UrlOrigin::of()` of it equals `$origin`.
  - `MastodonAccount::follow(JsonNodeModel $account, string $instanceHost): ?FollowedAccountModel` — `null` when the answer carries no `acct`.
  - `MastodonCandidateSource` (network `mastodon`): follows from `verify_credentials` → `/api/v1/accounts/<id>/following?limit=80` paged by `Link`; lists from `/api/v1/lists`; custom feeds always empty. 401/403 → `SocialCredentialsRejectedException`; transport / other non-2xx / no account id → `SocialNetworkUnreachableException`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Social/Support/LinkHeaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\LinkHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinkHeaderTest extends TestCase
{
    private const string ORIGIN = 'https://mastodon.example';

    /** @return iterable<string, array{?string, ?string}> */
    public static function headers(): iterable
    {
        yield 'next among several' => [
            '<https://mastodon.example/a?max_id=5>; rel="next", <https://mastodon.example/a?since_id=9>; rel="prev"',
            'https://mastodon.example/a?max_id=5',
        ];
        yield 'prev only' => ['<https://mastodon.example/a?since_id=9>; rel="prev"', null];
        yield 'next on another host' => ['<https://evil.example/a>; rel="next"', null];
        yield 'next on another port' => ['<https://mastodon.example:8443/a>; rel="next"', null];
        yield 'next over http' => ['<http://mastodon.example/a>; rel="next"', null];
        yield 'no header' => [null, null];
        yield 'not a link header' => ['nonsense', null];
    }

    #[DataProvider('headers')]
    public function testOnlyANextPageOnTheInstancesOwnOriginIsFollowed(?string $header, ?string $next): void
    {
        self::assertSame($next, LinkHeader::nextOn(self::ORIGIN, $header));
    }
}
```

`tests/Service/Social/CandidateSource/MastodonCandidateSourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\CandidateSource;

use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\CandidateSource\MastodonCandidateSource;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class MastodonCandidateSourceTest extends TestCase
{
    private const string VERIFY = 'GET https://mastodon.example/api/v1/accounts/verify_credentials';
    private const string FOLLOWING = 'GET https://mastodon.example/api/v1/accounts/109/following?limit=80';
    private const string NEXT_PAGE = 'https://mastodon.example/api/v1/accounts/109/following?limit=80&max_id=5';
    private const string LISTS = 'GET https://mastodon.example/api/v1/lists';

    private function source(RoutedHttpClient $http): MastodonCandidateSource
    {
        $guard = new UrlGuard(
            new StaticDnsResolver(['mastodon.example' => ['93.184.216.34'], 'evil.example' => ['93.184.216.35']]),
            new IpValidator(),
        );

        return new MastodonCandidateSource(
            FetchWiring::guardedApiClient($http->client(), $guard),
            new StoredCredential(SocialAccounts::cipher()),
        );
    }

    /**
     * @param array<string, MockResponse> $overrides
     *
     * @return array<string, MockResponse>
     */
    private function routes(array $overrides = []): array
    {
        return $overrides + [
            self::VERIFY => self::json(['id' => '109', 'username' => 'alice', 'acct' => 'alice']),
            self::FOLLOWING => self::json([]),
            self::LISTS => self::json([]),
        ];
    }

    /** @param array<mixed> $body */
    private static function json(array $body, string $link = ''): MockResponse
    {
        return new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            '' === $link ? [] : ['response_headers' => ['Link' => $link]],
        );
    }

    public function testTheNetworkKeyIsMastodon(): void
    {
        self::assertSame('mastodon', MastodonCandidateSource::network());
    }

    public function testFollowsReadAsUserAtDomainWithTheirProfileFeed(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json([
                ['id' => '1', 'username' => 'bob', 'acct' => 'bob', 'display_name' => 'Bob', 'url' => 'https://mastodon.example/@bob'],
                ['id' => '2', 'username' => 'Carol', 'acct' => 'Carol@Other.Example', 'display_name' => '', 'url' => 'https://other.example/@Carol'],
                ['id' => '3', 'username' => 'dave', 'acct' => 'dave@pleroma.example', 'display_name' => 'Dave', 'url' => 'https://pleroma.example/users/dave'],
                ['id' => '4', 'username' => 'eve', 'acct' => 'eve@insecure.example', 'url' => 'http://insecure.example/@eve'],
                ['id' => '5', 'username' => 'nobody'],
            ]),
        ]));

        $follows = $this->source($http)->candidates(SocialAccounts::mastodon())->follows;

        self::assertEquals([
            new FollowedAccountModel('bob@mastodon.example', '@bob@mastodon.example', 'Bob', 'https://mastodon.example/@bob.rss'),
            new FollowedAccountModel('Carol@other.example', '@Carol@other.example', null, 'https://other.example/@Carol.rss'),
            new FollowedAccountModel('dave@pleroma.example', '@dave@pleroma.example', 'Dave', 'https://pleroma.example/@dave.rss'),
            new FollowedAccountModel('eve@insecure.example', '@eve@insecure.example', null, 'https://insecure.example/@eve.rss'),
        ], $follows);
        foreach ($http->requests as $request) {
            self::assertContains('Authorization: Bearer ' . SocialAccounts::ACCESS_TOKEN, $request['headers']);
        }
    }

    public function testFollowingIsPagedThroughTheLinkHeader(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                [['id' => '1', 'acct' => 'bob', 'url' => 'https://mastodon.example/@bob']],
                '<' . self::NEXT_PAGE . '>; rel="next", <https://mastodon.example/api/v1/accounts/109/following?since_id=9>; rel="prev"',
            ),
            'GET ' . self::NEXT_PAGE => self::json([['id' => '2', 'acct' => 'carol', 'url' => 'https://mastodon.example/@carol']]),
        ]));

        $follows = $this->source($http)->candidates(SocialAccounts::mastodon())->follows;

        self::assertSame(
            ['bob@mastodon.example', 'carol@mastodon.example'],
            array_map(static fn (FollowedAccountModel $follow): string => $follow->identity, $follows),
        );
    }

    public function testANextPageOnAnotherOriginEndsPaging(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json(
                [['id' => '1', 'acct' => 'bob', 'url' => 'https://mastodon.example/@bob']],
                '<https://evil.example/api/v1/accounts/109/following?max_id=5>; rel="next"',
            ),
        ]));

        $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertNotContains('https://evil.example/api/v1/accounts/109/following?max_id=5', $http->requestedUrls());
    }

    public function testListsAreOfferedAndThereAreNoCustomFeeds(): void
    {
        $http = new RoutedHttpClient($this->routes([
            self::LISTS => self::json([['id' => '12', 'title' => 'Friends'], ['id' => '13']]),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertEquals([new SocialCollectionModel('12', 'Friends')], $candidates->lists);
        self::assertSame([], $candidates->customFeeds);
    }

    public function testFollowsBeyondTheCapAreCutAndMarkedTruncated(): void
    {
        $many = array_map(
            static fn (int $index): array => ['id' => (string) $index, 'acct' => 'f' . $index],
            range(1, CollectedItemsModel::MAX_ITEMS + 1),
        );
        $http = new RoutedHttpClient($this->routes([
            self::FOLLOWING => self::json($many, '<' . self::NEXT_PAGE . '>; rel="next"'),
        ]));

        $candidates = $this->source($http)->candidates(SocialAccounts::mastodon());

        self::assertCount(CollectedItemsModel::MAX_ITEMS, $candidates->follows);
        self::assertTrue($candidates->truncated);
        self::assertNotContains(self::NEXT_PAGE, $http->requestedUrls());
    }

    /** @return iterable<string, array{string, int}> */
    public static function refusals(): iterable
    {
        yield 'unauthorized on the account' => [self::VERIFY, Response::HTTP_UNAUTHORIZED];
        yield 'forbidden on the follows' => [self::FOLLOWING, Response::HTTP_FORBIDDEN];
    }

    #[DataProvider('refusals')]
    public function testARefusedTokenIsRejected(string $route, int $status): void
    {
        $http = new RoutedHttpClient($this->routes([$route => new MockResponse('{}', ['http_code' => $status])]));

        $this->expectException(SocialCredentialsRejectedException::class);

        $this->source($http)->candidates(SocialAccounts::mastodon());
    }

    /** @return iterable<string, array{string, MockResponse}> */
    public static function unusableAnswers(): iterable
    {
        yield 'a server error on the lists' => [
            self::LISTS,
            new MockResponse('', ['http_code' => Response::HTTP_INTERNAL_SERVER_ERROR]),
        ];
        yield 'an account without an id' => [self::VERIFY, new MockResponse('{"username":"alice"}')];
        yield 'a transport failure' => [self::FOLLOWING, new MockResponse('', ['error' => 'Connection refused'])];
    }

    #[DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerIsUnreachable(string $route, MockResponse $answer): void
    {
        $http = new RoutedHttpClient($this->routes([$route => $answer]));

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->source($http)->candidates(SocialAccounts::mastodon());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/Support/LinkHeaderTest.php tests/Service/Social/CandidateSource/MastodonCandidateSourceTest.php`
Expected: errors, `Class "App\Service\Social\Support\LinkHeader" not found`, `Class "App\Service\Social\CandidateSource\MastodonCandidateSource" not found`.

- [ ] **Step 3: Implement**

`src/Service/Social/Model/MastodonSessionModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\ApiRequestModel;

final readonly class MastodonSessionModel
{
    public function __construct(
        public string $instanceUrl,
        private string $accessToken,
    ) {
    }

    public function url(string $path): string
    {
        return $this->instanceUrl . $path;
    }

    public function get(string $url): ApiRequestModel
    {
        return ApiRequestModel::get($url, ['Authorization' => 'Bearer ' . $this->accessToken]);
    }

    public function host(): string
    {
        return (string) parse_url($this->instanceUrl, \PHP_URL_HOST);
    }
}
```

`src/Service/Social/Support/LinkHeader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Url\Support\UrlOrigin;

/** The RFC 8288 Link header Mastodon pages its collections with. */
final class LinkHeader
{
    private const string NEXT = '/<([^>]+)>\s*;\s*rel="?next"?/';

    public static function nextOn(string $origin, ?string $header): ?string
    {
        if (null === $header || 1 !== preg_match(self::NEXT, $header, $match)) {
            return null;
        }

        return UrlOrigin::of($match[1]) === $origin ? $match[1] : null;
    }

    private function __construct()
    {
    }
}
```

`src/Service/Social/Support/MastodonAccount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Model\FollowedAccountModel;

/** A Mastodon account answer read as a followed account with its public RSS feed. */
final class MastodonAccount
{
    private const string REMOTE_ACCT = '/^([^@]+)@([^@]+)$/';
    private const string PROFILE_PATH = '#^/@[^/@]+$#';

    /** @return FollowedAccountModel|null null when the answer names no account */
    public static function follow(JsonNodeModel $account, string $instanceHost): ?FollowedAccountModel
    {
        $acct = $account->string('acct');
        if (null === $acct) {
            return null;
        }
        [$user, $domain] = self::userAndDomain($acct, $instanceHost);
        $identity = $user . '@' . $domain;

        return new FollowedAccountModel(
            $identity,
            '@' . $identity,
            $account->string('display_name'),
            self::profileFeed($account->string('url')) ?? 'https://' . $domain . '/@' . $user . '.rss',
        );
    }

    /** @return array{string, string} a local acct is "user", a remote one "user@domain" */
    private static function userAndDomain(string $acct, string $instanceHost): array
    {
        return 1 === preg_match(self::REMOTE_ACCT, $acct, $match)
            ? [$match[1], strtolower($match[2])]
            : [$acct, strtolower($instanceHost)];
    }

    private static function profileFeed(?string $profileUrl): ?string
    {
        $parts = null === $profileUrl ? false : parse_url($profileUrl);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? null) || !isset($parts['host'])) {
            return null;
        }

        return 1 === preg_match(self::PROFILE_PATH, $parts['path'] ?? '') ? $profileUrl . '.rss' : null;
    }

    private function __construct()
    {
    }
}
```

`src/Service/Social/CandidateSource/MastodonCandidateSource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\CandidateSource;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\MastodonSessionModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\StoredCredential;
use App\Service\Social\Support\LinkHeader;
use App\Service\Social\Support\MastodonAccount;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonCandidateSource implements CandidateSourceInterface
{
    private const string VERIFY_CREDENTIALS = '/api/v1/accounts/verify_credentials';
    private const string LISTS = '/api/v1/lists';
    private const int PAGE_SIZE = 80;
    private const array REFUSALS = [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN];

    public function __construct(
        private GuardedApiClient $api,
        private StoredCredential $storedCredential,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        $session = new MastodonSessionModel($account->getServiceUrl(), $this->storedCredential->open($account));
        $follows = $this->collectFollows($session, $this->ownAccountId($session));

        return new NetworkCandidatesModel(
            self::follows($follows->items, $session->host()),
            $this->lists($session),
            [],
            $follows->truncated,
        );
    }

    private function ownAccountId(MastodonSessionModel $session): string
    {
        $answer = $this->call($session->get($session->url(self::VERIFY_CREDENTIALS)));

        return JsonNodeModel::of($answer->json())->string('id')
            ?? throw new SocialNetworkUnreachableException('The Mastodon instance gave no account for this token.');
    }

    private function collectFollows(MastodonSessionModel $session, string $accountId): CollectedItemsModel
    {
        $items = [];
        $url = $session->url('/api/v1/accounts/' . rawurlencode($accountId) . '/following?limit=' . self::PAGE_SIZE);
        do {
            $answer = $this->call($session->get($url));
            $fresh = self::nodesOf($answer);
            $items = [...$items, ...$fresh];
            $url = LinkHeader::nextOn($session->instanceUrl, $answer->header('link'));
        } while (null !== $url && [] !== $fresh && \count($items) <= CollectedItemsModel::MAX_ITEMS);

        return CollectedItemsModel::capped($items);
    }

    /** @return list<SocialCollectionModel> */
    private function lists(MastodonSessionModel $session): array
    {
        $lists = [];
        foreach (self::nodesOf($this->call($session->get($session->url(self::LISTS)))) as $node) {
            $id = $node->string('id');
            $title = $node->string('title');
            if (null !== $id && null !== $title) {
                $lists[] = new SocialCollectionModel($id, $title);
            }
        }

        return $lists;
    }

    /**
     * @param list<JsonNodeModel> $nodes
     *
     * @return list<FollowedAccountModel>
     */
    private static function follows(array $nodes, string $instanceHost): array
    {
        $follows = [];
        foreach ($nodes as $node) {
            $follow = MastodonAccount::follow($node, $instanceHost);
            if (null !== $follow) {
                $follows[] = $follow;
            }
        }

        return $follows;
    }

    /** @return list<JsonNodeModel> */
    private static function nodesOf(ApiResponseModel $answer): array
    {
        $json = $answer->json();

        return array_is_list($json) ? array_map(JsonNodeModel::of(...), $json) : [];
    }

    private function call(ApiRequestModel $request): ApiResponseModel
    {
        try {
            $answer = $this->api->send($request);
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException(
                'The Mastodon instance could not be reached.',
                previous: $exception,
            );
        }
        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw new SocialCredentialsRejectedException('The Mastodon instance refused the stored access token.');
        }
        if (!$answer->isSuccessful()) {
            throw new SocialNetworkUnreachableException(sprintf('The Mastodon instance answered %d.', $answer->status));
        }

        return $answer;
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `cd backend && composer check && composer md`, then `mcp__phpstorm__lint_files` on the four new `src` files.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Social backend/tests/Service/Social
git commit -m "feat(#1598): page a Mastodon account's follows and lists"
```

---

### Task 5: Matching follows against subscriptions

**Files:**
- Modify: `backend/src/Service/Social/BlueskyIdentityResolver.php` (`didOf` becomes public)
- Create: `backend/src/Service/Social/Model/ImportCandidateState.php`, `ImportCandidateModel.php`, `ImportCandidatesModel.php`, `ProfileFeedSubscriptionModel.php`
- Create: `backend/src/Service/Social/Pass/FollowMatching.php`
- Create: `backend/src/Service/Social/Support/CandidateOrder.php`
- Create: `backend/src/Service/Social/FollowMatcher.php`
- Create: `backend/src/Service/Social/ImportCandidates.php`
- Create: `backend/tests/Support/StubSocialCandidates.php`
- Test: `backend/tests/Service/Social/FollowMatcherTest.php`, `backend/tests/Service/Social/ImportCandidatesTest.php`

**Interfaces:**
- Consumes: `ProfileFeedUrl::identityOf()`, `NetworkCandidatesModel`, `FollowedAccountModel`, `SocialCollectionModel`, `SocialCandidatesInterface` (Task 3); `SubscriptionRepository::findForUserWithTags(int $userId): list<Subscription>`; `SubscriptionDisplayTitle::from(?string $customTitle, ?string $feedTitle, string $feedUrl): string`; `SocialAccount::markNeedsReconnect()`.
- Produces:
  - `BlueskyIdentityResolver::didOf(string $handle): string` (public; throws `SocialAccountUnresolvableException` on 400, `SocialNetworkUnreachableException` otherwise).
  - `enum ImportCandidateState: string { New = 'new'; Subscribed = 'subscribed'; NoLongerFollowed = 'no_longer_followed' }` with `listingRank(): int`.
  - `ProfileFeedSubscriptionModel(int $subscriptionId, string $identity, string $feedUrl, string $title)` with `handle(): string` (a DID as is, else `@` + identity).
  - `ImportCandidateModel` public `account` (`FollowedAccountModel`), `state`, `?int subscriptionId`; factories `notYetSubscribed(FollowedAccountModel)`, `subscribed(FollowedAccountModel, int)`, `noLongerFollowed(ProfileFeedSubscriptionModel)`.
  - `ImportCandidatesModel(list<ImportCandidateModel> $follows, list<SocialCollectionModel> $lists, list<SocialCollectionModel> $customFeeds, bool $truncated)`.
  - `FollowMatching` (Pass): `new FollowMatching(SocialNetwork $network, list<FollowedAccountModel> $follows)`; `offer(ProfileFeedSubscriptionModel)`, `unmatchedHandles(): list<ProfileFeedSubscriptionModel>`, `claimAs(ProfileFeedSubscriptionModel, string $did)`, `candidates(): list<ImportCandidateModel>`.
  - `FollowMatcher::match(FollowMatching $matching, list<ProfileFeedSubscriptionModel> $profileFeeds): list<ImportCandidateModel>` — resolves at most 25 leftover handles per load; stops at the first unreachable resolution.
  - `CandidateOrder::follows(list<ImportCandidateModel>)` (new, subscribed, no longer followed; then handle, case-insensitive) and `CandidateOrder::collections(list<SocialCollectionModel>)` (by name, natural, case-insensitive).
  - `ImportCandidates::forAccount(SocialAccount $account): ImportCandidatesModel` — on `SocialCredentialsRejectedException` marks the account `needs_reconnect`, flushes, rethrows.
  - Test support `StubSocialCandidates(NetworkCandidatesModel|\Throwable $outcome)` with public `int $calls`.

- [ ] **Step 1: Write the test support and the failing tests**

`tests/Support/StubSocialCandidates.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;

final class StubSocialCandidates implements SocialCandidatesInterface
{
    public int $calls = 0;

    public function __construct(private readonly NetworkCandidatesModel|\Throwable $outcome)
    {
    }

    public function candidates(SocialAccount $account): NetworkCandidatesModel
    {
        ++$this->calls;
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }

        return $this->outcome;
    }
}
```

`tests/Service/Social/FollowMatcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Enum\SocialNetwork;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\FollowMatcher;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class FollowMatcherTest extends TestCase
{
    private const string RESOLVE = 'GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=';

    private function matcher(RoutedHttpClient $http): FollowMatcher
    {
        $guard = new UrlGuard(new StaticDnsResolver(['public.api.bsky.app' => ['93.184.216.34']]), new IpValidator());

        return new FollowMatcher(new BlueskyIdentityResolver(FetchWiring::guardedApiClient($http->client(), $guard)));
    }

    private static function bob(): FollowedAccountModel
    {
        return new FollowedAccountModel('did:plc:bob', '@bob.example.com', 'Bob', 'https://bsky.app/profile/did:plc:bob/rss');
    }

    private static function blueskyFeed(int $subscriptionId, string $url): ProfileFeedSubscriptionModel
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Bluesky, $url);
        self::assertNotNull($identity);

        return new ProfileFeedSubscriptionModel($subscriptionId, $identity, $url, 'Title ' . $subscriptionId);
    }

    private static function mastodonFeed(int $subscriptionId, string $url): ProfileFeedSubscriptionModel
    {
        $identity = ProfileFeedUrl::identityOf(SocialNetwork::Mastodon, $url);
        self::assertNotNull($identity);

        return new ProfileFeedSubscriptionModel($subscriptionId, $identity, $url, 'Title ' . $subscriptionId);
    }

    /**
     * @param list<ImportCandidateModel> $candidates
     *
     * @return list<array{string, string, ?int}>
     */
    private static function summary(array $candidates): array
    {
        return array_map(
            static fn (ImportCandidateModel $candidate): array => [
                $candidate->account->handle,
                $candidate->state->value,
                $candidate->subscriptionId,
            ],
            $candidates,
        );
    }

    public function testAFeedKeyedByTheFollowsDidIsSubscribed(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(1, 'https://bsky.app/profile/did:plc:bob/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFeedKeyedByTheFollowsCurrentHandleMatchesWithoutANetworkCall(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(1, 'https://bsky.app/profile/Bob.Example.com/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAFeedKeyedByAnOldHandleIsResolvedToItsDid(): void
    {
        $http = new RoutedHttpClient([self::RESOLVE . 'old-bob.bsky.social' => new MockResponse('{"did":"did:plc:bob"}')]);

        $candidates = $this->matcher($http)->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(1, 'https://bsky.app/profile/old-bob.bsky.social/rss')],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAnUnresolvableHandleIsNoLongerFollowedWithoutAnError(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'gone.bsky.social' => new MockResponse('{"error":"InvalidRequest"}', ['http_code' => Response::HTTP_BAD_REQUEST]),
        ]);

        $candidates = $this->matcher($http)->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [self::blueskyFeed(2, 'https://bsky.app/profile/gone.bsky.social/rss')],
        );

        self::assertSame(
            [['@bob.example.com', 'new', null], ['@gone.bsky.social', 'no_longer_followed', 2]],
            self::summary($candidates),
        );
    }

    public function testAnUnreachableResolverStopsResolvingTheRest(): void
    {
        $http = new RoutedHttpClient([
            self::RESOLVE . 'first.bsky.social' => new MockResponse('', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]),
        ]);

        $candidates = $this->matcher($http)->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [
                self::blueskyFeed(1, 'https://bsky.app/profile/first.bsky.social/rss'),
                self::blueskyFeed(2, 'https://bsky.app/profile/second.bsky.social/rss'),
            ],
        );

        self::assertCount(1, $http->requests);
        self::assertCount(3, $candidates);
    }

    public function testAtMostTwentyFiveHandlesAreResolvedPerLoad(): void
    {
        $routes = [];
        $feeds = [];
        foreach (range(1, 26) as $index) {
            $routes[self::RESOLVE . 'h' . $index . '.bsky.social'] = new MockResponse(
                '{"error":"InvalidRequest"}',
                ['http_code' => Response::HTTP_BAD_REQUEST],
            );
            $feeds[] = self::blueskyFeed($index, 'https://bsky.app/profile/h' . $index . '.bsky.social/rss');
        }
        $http = new RoutedHttpClient($routes);

        $this->matcher($http)->match(new FollowMatching(SocialNetwork::Bluesky, [self::bob()]), $feeds);

        self::assertCount(25, $http->requests);
    }

    public function testTwoFeedsOfOneFollowLeaveNothingBehind(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, [self::bob()]),
            [
                self::blueskyFeed(1, 'https://bsky.app/profile/did:plc:bob/rss'),
                self::blueskyFeed(2, 'https://bsky.app/profile/bob.example.com/rss'),
            ],
        );

        self::assertSame([['@bob.example.com', 'subscribed', 1]], self::summary($candidates));
    }

    public function testAMastodonFeedMatchesByIdentityOrByTheFollowsOwnFeedAndNeverResolves(): void
    {
        $follows = [
            new FollowedAccountModel('bob@mastodon.example', '@bob@mastodon.example', 'Bob', 'https://mastodon.example/@bob.rss'),
            new FollowedAccountModel('carol@example.com', '@carol@example.com', 'Carol', 'https://social.example.com/@carol.rss'),
        ];

        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Mastodon, $follows),
            [
                self::mastodonFeed(1, 'https://mastodon.example/@Bob.rss'),
                self::mastodonFeed(2, 'https://social.example.com/@carol.rss'),
                self::mastodonFeed(3, 'https://other.example/@dave.rss'),
            ],
        );

        self::assertSame([
            ['@bob@mastodon.example', 'subscribed', 1],
            ['@carol@example.com', 'subscribed', 2],
            ['@dave@other.example', 'no_longer_followed', 3],
        ], self::summary($candidates));
    }

    public function testANoLongerFollowedFeedCarriesItsSubscriptionsTitleAndUrl(): void
    {
        $candidates = $this->matcher(new RoutedHttpClient([]))->match(
            new FollowMatching(SocialNetwork::Bluesky, []),
            [self::blueskyFeed(3, 'https://bsky.app/profile/did:plc:gone/rss')],
        );

        self::assertEquals(
            new FollowedAccountModel('did:plc:gone', 'did:plc:gone', 'Title 3', 'https://bsky.app/profile/did:plc:gone/rss'),
            $candidates[0]->account,
        );
    }
}
```

`tests/Service/Social/ImportCandidatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Repository\SubscriptionRepository;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\FollowMatcher;
use App\Service\Social\ImportCandidates;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StaticDnsResolver;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ImportCandidatesTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('import-candidates@example.test');
    }

    private function importCandidates(SocialCandidatesInterface $network): ImportCandidates
    {
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        $guard = new UrlGuard(new StaticDnsResolver([]), new IpValidator());
        $resolver = new BlueskyIdentityResolver(
            FetchWiring::guardedApiClient((new RoutedHttpClient([]))->client(), $guard),
        );

        return new ImportCandidates($network, $subscriptions, new FollowMatcher($resolver), $this->entityManager);
    }

    private function account(): SocialAccount
    {
        $account = new SocialAccount(
            $this->user,
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://bsky.social',
            SocialAccounts::cipher()->seal($this->user->requireId(), SocialAccounts::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function subscribe(string $url, ?string $title = null): Subscription
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    private static function follow(string $did, string $handle): FollowedAccountModel
    {
        return new FollowedAccountModel($did, $handle, null, 'https://bsky.app/profile/' . $did . '/rss');
    }

    public function testFollowsAreListedNewThenSubscribedThenNoLongerFollowedEachByHandle(): void
    {
        $account = $this->account();
        $bob = $this->subscribe('https://bsky.app/profile/did:plc:bob/rss');
        $gone = $this->subscribe('https://bsky.app/profile/did:plc:gone/rss', 'Gone Account');
        $this->subscribe('https://example.com/feed.xml');
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [
                self::follow('did:plc:zed', '@Zed.bsky.social'),
                self::follow('did:plc:bob', '@bob.bsky.social'),
                self::follow('did:plc:amy', '@amy.bsky.social'),
            ],
            [],
            [],
            false,
        ));

        $follows = $this->importCandidates($network)->forAccount($account)->follows;

        self::assertSame(
            [
                ['@amy.bsky.social', 'new', null],
                ['@Zed.bsky.social', 'new', null],
                ['@bob.bsky.social', 'subscribed', $bob->requireId()],
                ['did:plc:gone', 'no_longer_followed', $gone->requireId()],
            ],
            array_map(
                static fn (ImportCandidateModel $candidate): array => [
                    $candidate->account->handle,
                    $candidate->state->value,
                    $candidate->subscriptionId,
                ],
                $follows,
            ),
        );
        self::assertSame('Gone Account', $follows[3]->account->displayName);
    }

    public function testListsAndCustomFeedsAreSortedByNameAndTruncationIsKept(): void
    {
        $network = new StubSocialCandidates(new NetworkCandidatesModel(
            [],
            [new SocialCollectionModel('at://list/b', 'writers'), new SocialCollectionModel('at://list/a', 'Artists')],
            [new SocialCollectionModel('at://feed/b', 'Feed 10'), new SocialCollectionModel('at://feed/a', 'Feed 9')],
            true,
        ));

        $candidates = $this->importCandidates($network)->forAccount($this->account());

        self::assertSame(['Artists', 'writers'], array_map(static fn (SocialCollectionModel $list): string => $list->name, $candidates->lists));
        self::assertSame(['Feed 9', 'Feed 10'], array_map(static fn (SocialCollectionModel $feed): string => $feed->name, $candidates->customFeeds));
        self::assertTrue($candidates->truncated);
    }

    public function testARefusedCredentialMarksTheAccountForReconnectingAndStillFails(): void
    {
        $account = $this->account();
        $network = new StubSocialCandidates(new SocialCredentialsRejectedException('Refused.'));

        try {
            $this->importCandidates($network)->forAccount($account);
            self::fail('The refusal must reach the caller.');
        } catch (SocialCredentialsRejectedException) {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(SocialAccount::class, $account->requireId());
            self::assertSame(SocialAccountStatus::NeedsReconnect, $reloaded?->getStatus());
        }
    }

    public function testAnUnreachableNetworkLeavesTheAccountConnected(): void
    {
        $account = $this->account();
        $network = new StubSocialCandidates(new SocialNetworkUnreachableException('Down.'));

        try {
            $this->importCandidates($network)->forAccount($account);
            self::fail('The outage must reach the caller.');
        } catch (SocialNetworkUnreachableException) {
            $this->entityManager->clear();
            $reloaded = $this->entityManager->find(SocialAccount::class, $account->requireId());
            self::assertSame(SocialAccountStatus::Connected, $reloaded?->getStatus());
        }
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/FollowMatcherTest.php tests/Service/Social/ImportCandidatesTest.php`
Expected: errors, `Class "App\Service\Social\Pass\FollowMatching" not found` / `Class "App\Service\Social\ImportCandidates" not found`.

- [ ] **Step 3: Implement**

`BlueskyIdentityResolver`: change `private function didOf(string $handle): string` to

```php
    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function didOf(string $handle): string
```

(body unchanged; move it below `resolve()` so the public methods stay together).

`src/Service/Social/Model/ImportCandidateState.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

enum ImportCandidateState: string
{
    case New = 'new';
    case Subscribed = 'subscribed';
    case NoLongerFollowed = 'no_longer_followed';

    public function listingRank(): int
    {
        return match ($this) {
            self::New => 0,
            self::Subscribed => 1,
            self::NoLongerFollowed => 2,
        };
    }
}
```

`src/Service/Social/Model/ProfileFeedSubscriptionModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ProfileFeedSubscriptionModel
{
    public function __construct(
        public int $subscriptionId,
        public string $identity,
        public string $feedUrl,
        public string $title,
    ) {
    }

    public function handle(): string
    {
        return str_starts_with($this->identity, 'did:') ? $this->identity : '@' . $this->identity;
    }
}
```

`src/Service/Social/Model/ImportCandidateModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ImportCandidateModel
{
    private function __construct(
        public FollowedAccountModel $account,
        public ImportCandidateState $state,
        public ?int $subscriptionId,
    ) {
    }

    public static function notYetSubscribed(FollowedAccountModel $follow): self
    {
        return new self($follow, ImportCandidateState::New, null);
    }

    public static function subscribed(FollowedAccountModel $follow, int $subscriptionId): self
    {
        return new self($follow, ImportCandidateState::Subscribed, $subscriptionId);
    }

    public static function noLongerFollowed(ProfileFeedSubscriptionModel $profileFeed): self
    {
        return new self(
            new FollowedAccountModel(
                $profileFeed->identity,
                $profileFeed->handle(),
                $profileFeed->title,
                $profileFeed->feedUrl,
            ),
            ImportCandidateState::NoLongerFollowed,
            $profileFeed->subscriptionId,
        );
    }
}
```

`src/Service/Social/Model/ImportCandidatesModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class ImportCandidatesModel
{
    /**
     * @param list<ImportCandidateModel>  $follows
     * @param list<SocialCollectionModel> $lists
     * @param list<SocialCollectionModel> $customFeeds
     */
    public function __construct(
        public array $follows,
        public array $lists,
        public array $customFeeds,
        public bool $truncated,
    ) {
    }
}
```

`src/Service/Social/Pass/FollowMatching.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Support\ProfileFeedUrl;

/** One picker load's matching: each follow answers to its identity, its handle and its own feed's key. */
final class FollowMatching
{
    /** @var array<string, int> lower-cased key => follow position */
    private readonly array $followByKey;

    /** @var array<int, int> follow position => subscription id */
    private array $subscriptionOfFollow = [];

    /** @var array<int, ProfileFeedSubscriptionModel> by subscription id */
    private array $unmatched = [];

    /** @param list<FollowedAccountModel> $follows */
    public function __construct(
        private readonly SocialNetwork $network,
        private readonly array $follows,
    ) {
        $this->followByKey = $this->indexFollows();
    }

    public function offer(ProfileFeedSubscriptionModel $profileFeed): void
    {
        if (!$this->claim($profileFeed, $profileFeed->identity)) {
            $this->unmatched[$profileFeed->subscriptionId] = $profileFeed;
        }
    }

    /** @return list<ProfileFeedSubscriptionModel> leftover Bluesky feeds keyed by a handle rather than a DID */
    public function unmatchedHandles(): array
    {
        if (SocialNetwork::Bluesky !== $this->network) {
            return [];
        }

        return array_values(array_filter(
            $this->unmatched,
            static fn (ProfileFeedSubscriptionModel $profileFeed): bool => !str_starts_with($profileFeed->identity, 'did:'),
        ));
    }

    public function claimAs(ProfileFeedSubscriptionModel $profileFeed, string $did): void
    {
        if ($this->claim($profileFeed, $did)) {
            unset($this->unmatched[$profileFeed->subscriptionId]);
        }
    }

    /** @return list<ImportCandidateModel> */
    public function candidates(): array
    {
        $candidates = [];
        foreach ($this->follows as $position => $follow) {
            $subscriptionId = $this->subscriptionOfFollow[$position] ?? null;
            $candidates[] = null === $subscriptionId
                ? ImportCandidateModel::notYetSubscribed($follow)
                : ImportCandidateModel::subscribed($follow, $subscriptionId);
        }
        foreach ($this->unmatched as $profileFeed) {
            $candidates[] = ImportCandidateModel::noLongerFollowed($profileFeed);
        }

        return $candidates;
    }

    private function claim(ProfileFeedSubscriptionModel $profileFeed, string $key): bool
    {
        $position = $this->followByKey[strtolower($key)] ?? null;
        if (null === $position) {
            return false;
        }
        $this->subscriptionOfFollow[$position] ??= $profileFeed->subscriptionId;

        return true;
    }

    /** @return array<string, int> */
    private function indexFollows(): array
    {
        $index = [];
        foreach ($this->follows as $position => $follow) {
            foreach ($this->keysOf($follow) as $key) {
                $index[strtolower($key)] ??= $position;
            }
        }

        return $index;
    }

    /** @return array<int, string> */
    private function keysOf(FollowedAccountModel $follow): array
    {
        return array_filter([
            $follow->identity,
            ltrim($follow->handle, '@'),
            ProfileFeedUrl::identityOf($this->network, $follow->feedUrl),
        ]);
    }
}
```

`src/Service/Social/Support/CandidateOrder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\SocialCollectionModel;

final class CandidateOrder
{
    /**
     * @param list<ImportCandidateModel> $candidates
     *
     * @return list<ImportCandidateModel>
     */
    public static function follows(array $candidates): array
    {
        usort(
            $candidates,
            static fn (ImportCandidateModel $left, ImportCandidateModel $right): int => [
                $left->state->listingRank(),
                strtolower($left->account->handle),
            ] <=> [
                $right->state->listingRank(),
                strtolower($right->account->handle),
            ],
        );

        return $candidates;
    }

    /**
     * @param list<SocialCollectionModel> $collections
     *
     * @return list<SocialCollectionModel>
     */
    public static function collections(array $collections): array
    {
        usort(
            $collections,
            static fn (SocialCollectionModel $left, SocialCollectionModel $right): int => strnatcasecmp(
                $left->name,
                $right->name,
            ),
        );

        return $collections;
    }

    private function __construct()
    {
    }
}
```

`src/Service/Social/FollowMatcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;

final readonly class FollowMatcher
{
    private const int MAX_HANDLE_RESOLUTIONS = 25;

    public function __construct(private BlueskyIdentityResolver $identities)
    {
    }

    /**
     * @param list<ProfileFeedSubscriptionModel> $profileFeeds
     *
     * @return list<ImportCandidateModel>
     */
    public function match(FollowMatching $matching, array $profileFeeds): array
    {
        foreach ($profileFeeds as $profileFeed) {
            $matching->offer($profileFeed);
        }
        $this->resolveLeftoverHandles($matching);

        return $matching->candidates();
    }

    private function resolveLeftoverHandles(FollowMatching $matching): void
    {
        foreach (\array_slice($matching->unmatchedHandles(), 0, self::MAX_HANDLE_RESOLUTIONS) as $profileFeed) {
            try {
                $matching->claimAs($profileFeed, $this->identities->didOf($profileFeed->identity));
            } catch (SocialAccountUnresolvableException) {
                continue;
            } catch (SocialNetworkUnreachableException) {
                return;
            }
        }
    }
}
```

`src/Service/Social/ImportCandidates.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Repository\SubscriptionDisplayTitle;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\ImportCandidatesModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\ProfileFeedSubscriptionModel;
use App\Service\Social\Pass\FollowMatching;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Service\Social\Support\CandidateOrder;
use App\Service\Social\Support\ProfileFeedUrl;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ImportCandidates
{
    public function __construct(
        private SocialCandidatesInterface $networkCandidates,
        private SubscriptionRepository $subscriptions,
        private FollowMatcher $followMatcher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws SocialCredentialsRejectedException once the account is marked as needing a reconnect
     * @throws SocialNetworkUnreachableException
     */
    public function forAccount(SocialAccount $account): ImportCandidatesModel
    {
        $network = $this->networkCandidatesOf($account);
        $follows = $this->followMatcher->match(
            new FollowMatching($account->getNetwork(), $network->follows),
            $this->profileFeedsOf($account),
        );

        return new ImportCandidatesModel(
            CandidateOrder::follows($follows),
            CandidateOrder::collections($network->lists),
            CandidateOrder::collections($network->customFeeds),
            $network->truncated,
        );
    }

    private function networkCandidatesOf(SocialAccount $account): NetworkCandidatesModel
    {
        try {
            return $this->networkCandidates->candidates($account);
        } catch (SocialCredentialsRejectedException $exception) {
            $account->markNeedsReconnect();
            $this->entityManager->flush();

            throw $exception;
        }
    }

    /** @return list<ProfileFeedSubscriptionModel> */
    private function profileFeedsOf(SocialAccount $account): array
    {
        $profileFeeds = [];
        foreach ($this->subscriptions->findForUserWithTags($account->getUser()->requireId()) as $subscription) {
            $feed = $subscription->getFeed();
            $identity = ProfileFeedUrl::identityOf($account->getNetwork(), $feed->getUrl());
            if (null !== $identity) {
                $title = SubscriptionDisplayTitle::from($subscription->getCustomTitle(), $feed->getTitle(), $feed->getUrl());
                $profileFeeds[] = new ProfileFeedSubscriptionModel(
                    $subscription->requireId(),
                    $identity,
                    $feed->getUrl(),
                    $title,
                );
            }
        }

        return $profileFeeds;
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `cd backend && composer check && composer md`, then `mcp__phpstorm__lint_files` on the changed `src` files. Watch `composer tramp`: `$account` is read at every hop, so no chain is expected.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Social backend/tests/Support/StubSocialCandidates.php backend/tests/Service/Social
git commit -m "feat(#1598): match a network's follows against the user's subscriptions by identity"
```

---

### Task 6: The candidates endpoint

**Files:**
- Create: `backend/src/Service/Social/Exception/SocialAccountNeedsReconnectException.php`
- Modify: `backend/src/Service/Social/SocialAccountForUser.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`
- Modify: `backend/config/packages/rate_limiter.yaml` (after `social_account`)
- Create: `backend/src/Http/SocialImportJson.php`
- Create: `backend/src/Controller/Api/SocialImportController.php`
- Test: `backend/tests/Controller/Api/SocialImportControllerTest.php`

**Interfaces:**
- Consumes: `ImportCandidates::forAccount()`, `ImportCandidatesModel`, `ImportCandidateModel`, `SocialCollectionModel` (Task 5); `StubSocialCandidates` (Task 5); `RateLimitGuard::enforceForUser(RateLimiterFactoryInterface, User)`.
- Produces:
  - `SocialAccountForUser::requireConnected(User $user, int $accountId): SocialAccount` — 404 as `require()`, then `SocialAccountNeedsReconnectException` → 409 `social_account_needs_reconnect`.
  - `GET /api/me/social-accounts/{id}/import-candidates` (route `api_me_social_accounts_import_candidates`) → `{follows: [{identity, handle, displayName, feedUrl, state, subscriptionId}], lists: [{id, name}], customFeeds: [{id, name}], truncated}`.
  - `SocialImportJson::candidates(ImportCandidatesModel): array<string, mixed>`.

- [ ] **Step 1: Write the failing tests**

`tests/Controller/Api/SocialImportControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\FollowedAccountModel;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubSocialCandidates;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class SocialImportControllerTest extends ApiTestCase
{
    /** Must match framework.rate_limiter.social_import.limit in rate_limiter.yaml. */
    private const int IMPORT_BUDGET = 10;

    protected function setUp(): void
    {
        self::bootKernel();
        $rateLimiterCache = self::getContainer()->get('test.cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $rateLimiterCache);
        $rateLimiterCache->clear();
        self::ensureKernelShutdown();
    }

    public function testTheCandidatesRefuseAnAnonymousCaller(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/me/social-accounts/1/import-candidates');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheCandidatesListFollowsByStateThenHandleBesideListsAndFeeds(): void
    {
        $client = $this->clientWith(new StubSocialCandidates(new NetworkCandidatesModel(
            [
                self::follow('did:plc:zed', '@zed.bsky.social'),
                self::follow('did:plc:bob', '@bob.bsky.social'),
                self::follow('did:plc:amy', '@amy.bsky.social'),
            ],
            [new SocialCollectionModel('at://list/b', 'Writers'), new SocialCollectionModel('at://list/a', 'Artists')],
            [new SocialCollectionModel('at://feed/a', 'Discover')],
            false,
        )));
        $user = $this->signIn($client, 'import-candidates-shape@example.test');
        $account = $this->blueskyAccountOf($user);
        $bob = $this->subscribe($user, 'https://bsky.app/profile/did:plc:bob/rss');
        $gone = $this->subscribe($user, 'https://bsky.app/profile/did:plc:gone/rss', 'Gone Account');
        $this->subscribe($user, 'https://example.com/feed.xml');

        $this->requestCandidates($client, $account);

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([
            self::row('did:plc:amy', '@amy.bsky.social', null, 'new', null),
            self::row('did:plc:zed', '@zed.bsky.social', null, 'new', null),
            self::row('did:plc:bob', '@bob.bsky.social', null, 'subscribed', $bob->requireId()),
            self::row('did:plc:gone', 'did:plc:gone', 'Gone Account', 'no_longer_followed', $gone->requireId()),
        ], $payload['follows']);
        self::assertSame(
            [['id' => 'at://list/a', 'name' => 'Artists'], ['id' => 'at://list/b', 'name' => 'Writers']],
            $payload['lists'],
        );
        self::assertSame([['id' => 'at://feed/a', 'name' => 'Discover']], $payload['customFeeds']);
        self::assertFalse($payload['truncated']);
    }

    public function testAnotherUsersAccountAnswers404BeforeTheBudget(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $owner = $this->signIn($client, 'import-owner@example.test');
        $account = $this->blueskyAccountOf($owner);
        $intruder = $this->signIn($client, 'import-intruder@example.test');
        $ownAccount = $this->blueskyAccountOf($intruder);
        for ($attempt = 0; $attempt < self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $ownAccount);
        }

        $this->requestCandidates($client, $account);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame('social_account_not_found', $this->payload($client)['type']);
    }

    public function testAnAccountThatNeedsReconnectingIsRefusedBeforeTheBudgetAndTheNetwork(): void
    {
        $stub = $this->nothingFollowed();
        $client = $this->clientWith($stub);
        $user = $this->signIn($client, 'import-needs-reconnect@example.test');
        $account = $this->blueskyAccountOf($user);
        $account->markNeedsReconnect();
        $this->entityManager()->flush();

        for ($attempt = 0; $attempt <= self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $account);
            $this->assertRejected($client, Response::HTTP_CONFLICT);
            self::assertSame('social_account_needs_reconnect', $this->payload($client)['type']);
        }
        self::assertSame(0, $stub->calls);
    }

    public function testARefusedCredentialMarksTheAccountForReconnecting(): void
    {
        $client = $this->clientWith(new StubSocialCandidates(new SocialCredentialsRejectedException('Refused.')));
        $user = $this->signIn($client, 'import-refused@example.test');
        $account = $this->blueskyAccountOf($user);

        $this->requestCandidates($client, $account);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_credentials_rejected', $this->payload($client)['type']);
        $client->request('GET', '/api/me/social-accounts');
        $accounts = $this->payload($client)['accounts'];
        self::assertIsArray($accounts);
        self::assertIsArray($accounts[0]);
        self::assertSame('needs_reconnect', $accounts[0]['status']);
    }

    public function testAnUnreachableNetworkAnswers502(): void
    {
        $client = $this->clientWith(new StubSocialCandidates(new SocialNetworkUnreachableException('Down.')));
        $user = $this->signIn($client, 'import-unreachable@example.test');

        $this->requestCandidates($client, $this->blueskyAccountOf($user));

        $this->assertRejected($client, Response::HTTP_BAD_GATEWAY);
        self::assertSame('social_network_unreachable', $this->payload($client)['type']);
    }

    public function testTheCandidatesBudgetIsTenPerQuarterHour(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-budget@example.test');
        $account = $this->blueskyAccountOf($user);
        for ($attempt = 0; $attempt < self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $account);
            self::assertResponseIsSuccessful();
        }

        $this->requestCandidates($client, $account);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /** @return array<string, mixed> */
    private static function row(string $identity, string $handle, ?string $displayName, string $state, ?int $subscriptionId): array
    {
        return [
            'identity' => $identity,
            'handle' => $handle,
            'displayName' => $displayName,
            'feedUrl' => 'https://bsky.app/profile/' . $identity . '/rss',
            'state' => $state,
            'subscriptionId' => $subscriptionId,
        ];
    }

    private static function follow(string $did, string $handle): FollowedAccountModel
    {
        return new FollowedAccountModel($did, $handle, null, 'https://bsky.app/profile/' . $did . '/rss');
    }

    private function nothingFollowed(): StubSocialCandidates
    {
        return new StubSocialCandidates(new NetworkCandidatesModel([], [], [], false));
    }

    private function clientWith(SocialCandidatesInterface $candidates): KernelBrowser
    {
        $client = static::createClient();
        // KernelBrowser rebuilds the container after every request, which would discard the stub.
        $client->disableReboot();
        self::getContainer()->set(SocialCandidatesInterface::class, $candidates);

        return $client;
    }

    private function signIn(KernelBrowser $client, string $email): User
    {
        $user = $this->factory()->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer ' . $tokens->create($user));

        return $user;
    }

    private function blueskyAccountOf(User $user): SocialAccount
    {
        $account = new SocialAccount(
            $user,
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://bsky.social',
            SocialAccounts::cipher()->seal($user->requireId(), SocialAccounts::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function subscribe(User $user, string $url, ?string $title = null): Subscription
    {
        $feed = new Feed($url);
        $feed->setTitle($title);
        $this->entityManager()->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->entityManager()->persist($subscription);
        $this->entityManager()->flush();

        return $subscription;
    }

    private function requestCandidates(KernelBrowser $client, SocialAccount $account): void
    {
        $client->request('GET', sprintf('/api/me/social-accounts/%d/import-candidates', $account->requireId()));
    }
}
```

(The `factory()->create()` call returns the `User`; read `tests/Support/UserFactory.php` if the signature has moved.)

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SocialImportControllerTest.php`
Expected: FAIL — the authorised requests answer 404 `No route found for "GET /api/me/social-accounts/…/import-candidates"` (the anonymous test already passes on the firewall).

- [ ] **Step 3: Implement**

`src/Service/Social/Exception/SocialAccountNeedsReconnectException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class SocialAccountNeedsReconnectException extends \RuntimeException
{
}
```

`SocialAccountForUser` gains (import `App\Enum\SocialAccountStatus` and the exception):

```php
    /**
     * @throws SocialAccountNotFoundException
     * @throws SocialAccountNeedsReconnectException
     */
    public function requireConnected(User $user, int $accountId): SocialAccount
    {
        $account = $this->require($user, $accountId);
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            throw new SocialAccountNeedsReconnectException(
                sprintf('%s needs reconnecting before it can be read.', $account->getHandle()),
            );
        }

        return $account;
    }
```

`SocialProblems::resolve()` — a new arm right after `SocialAccountAlreadyConnectedException`'s:

```php
            $exception instanceof SocialAccountNeedsReconnectException => new ResolvedProblem(new ApiProblem(
                'social_account_needs_reconnect',
                'The account needs reconnecting',
                Response::HTTP_CONFLICT,
                $exception->getMessage(),
            )),
```

`config/packages/rate_limiter.yaml`, after the `social_account` block:

```yaml
        # Each picker load logs in to the network and pages the whole follow list.
        social_import:
            policy: 'sliding_window'
            limit: 10
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
```

`src/Http/SocialImportJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\ImportCandidateModel;
use App\Service\Social\Model\ImportCandidatesModel;
use App\Service\Social\Model\SocialCollectionModel;

final readonly class SocialImportJson
{
    /** @return array<string, mixed> */
    public function candidates(ImportCandidatesModel $candidates): array
    {
        return [
            'follows' => array_map(self::follow(...), $candidates->follows),
            'lists' => array_map(self::collection(...), $candidates->lists),
            'customFeeds' => array_map(self::collection(...), $candidates->customFeeds),
            'truncated' => $candidates->truncated,
        ];
    }

    /** @return array<string, mixed> */
    private static function follow(ImportCandidateModel $candidate): array
    {
        return [
            'identity' => $candidate->account->identity,
            'handle' => $candidate->account->handle,
            'displayName' => $candidate->account->displayName,
            'feedUrl' => $candidate->account->feedUrl,
            'state' => $candidate->state->value,
            'subscriptionId' => $candidate->subscriptionId,
        ];
    }

    /** @return array{id: string, name: string} */
    private static function collection(SocialCollectionModel $collection): array
    {
        return ['id' => $collection->id, 'name' => $collection->name];
    }
}
```

`src/Controller/Api/SocialImportController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\SocialImportJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\ImportCandidates;
use App\Service\Social\SocialAccountForUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/me/social-accounts')]
final readonly class SocialImportController
{
    public function __construct(
        private SocialAccountForUser $socialAccount,
        private ImportCandidates $importCandidates,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialImportLimiter,
        private SocialImportJson $importJson,
    ) {
    }

    #[Route(
        '/{id}/import-candidates',
        name: 'api_me_social_accounts_import_candidates',
        requirements: ['id' => '\d+'],
        methods: ['GET'],
    )]
    public function candidates(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $account = $this->socialAccount->requireConnected($user, $id);
        $this->rateLimitGuard->enforceForUser($this->socialImportLimiter, $user);

        return new JsonResponse($this->importJson->candidates($this->importCandidates->forAccount($account)));
    }
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/SocialImportControllerTest.php tests/Controller/Api/SocialAccountControllerTest.php tests/Service/Social`
Expected: PASS.

- [ ] **Step 5: Gates**

Run: `cd backend && bin/console cache:clear && composer check && composer md`, then `mcp__phpstorm__lint_files` on the changed `src` files. `ThinControllerRule` and `ControllerMutatesNoEntityRule` run in `composer stan`. Run the architecture §6 checklist against the endpoint (bearer, stateless, JSON / problem+json, no browser inputs, no redirect): all boxes hold.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/config/packages/rate_limiter.yaml backend/tests/Controller/Api/SocialImportControllerTest.php
git commit -m "feat(#1598): GET the import candidates of a social account"
```

---

### Task 7: The import endpoint

**Files:**
- Create: `backend/src/Service/Social/Model/SocialImportModel.php`, `SocialImportFeedModel.php`, `SocialImportResultModel.php`
- Create: `backend/src/Service/Social/Exception/InvalidSocialImportFeedException.php`
- Create: `backend/src/Service/Social/SocialImporter.php`
- Create: `backend/src/Dto/Social/SocialImportRequest.php`, `backend/src/Dto/Social/SocialImportFeedItem.php`
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`
- Modify: `backend/src/Http/SocialImportJson.php`
- Modify: `backend/src/Controller/Api/SocialImportController.php`
- Test: `backend/tests/Service/Social/SocialImporterTest.php`; extend `backend/tests/Controller/Api/SocialImportControllerTest.php`

**Interfaces:**
- Consumes: `BulkSubscriber::subscribeAll(User, iterable<BulkSubscribeItemModel>): BulkSubscribeResultModel`; `new BulkSubscribeItemModel(string $feedUrl, ?string $feedTitle = null, ?string $tagName = null, …)`; `OwnedSubscriptions::resolve(int $userId, list<int> $ids): array<int, Subscription>` (throws `App\Exception\InvalidSelectionException`, already mapped to 422); `SubscriptionService::unsubscribeAll(list<Subscription>): int`; `SubscriptionService::MAX_BULK_REQUEST_IDS` (5000); `CollectedItemsModel::MAX_ITEMS` (5000); `ProfileFeedUrl::identityOf()`.
- Produces:
  - `SocialImportFeedModel(string $feedUrl, ?string $title)`; `SocialImportModel(list<SocialImportFeedModel> $subscribe, list<int> $unsubscribe)`.
  - `SocialImportResultModel::from(BulkSubscribeResultModel $subscribed, int $removed)` with public `imported`, `alreadySubscribed`, `invalid`, `skippedOverLimit`, `removed`.
  - `SocialImporter::import(SocialAccount $account, SocialImportModel $import): SocialImportResultModel` — throws `InvalidSocialImportFeedException` (422 `social_import_invalid_feed`) before anything is written.
  - `POST /api/me/social-accounts/{id}/import` (route `api_me_social_accounts_import`), body `{subscribe: [{feedUrl, title}], unsubscribe: [int]}` → `{imported, alreadySubscribed, invalid, skippedOverLimit, removed}`. No limiter.
  - `SocialImportJson::result(SocialImportResultModel): array<string, int>`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/Social/SocialImporterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Exception\InvalidSelectionException;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\SocialImporter;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialImporterTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-importer@example.test');
    }

    private function importer(): SocialImporter
    {
        $importer = self::getContainer()->get(SocialImporter::class);
        self::assertInstanceOf(SocialImporter::class, $importer);

        return $importer;
    }

    private function account(SocialNetwork $network): SocialAccount
    {
        return new SocialAccount(
            $this->user,
            $network,
            SocialNetwork::Bluesky === $network ? 'did:plc:alice' : 'alice@mastodon.example',
            '@alice',
            SocialNetwork::Bluesky === $network ? 'https://bsky.social' : 'https://mastodon.example',
            SocialAccounts::cipher()->seal($this->user->requireId(), SocialAccounts::APP_PASSWORD),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    private function subscribe(string $url, ?Tag $tag = null): Subscription
    {
        $feed = new Feed($url);
        $this->entityManager->persist($feed);
        $subscription = new Subscription($this->user, $feed, new \DateTimeImmutable('2026-10-01 10:00:00'));
        if (null !== $tag) {
            $subscription->addTag($tag, 0);
        }
        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    /** @return list<string> */
    private function tagNamesOf(string $url): array
    {
        $this->entityManager->clear();
        $subscriptions = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $subscriptions);
        foreach ($subscriptions->findForUserWithTags($this->user->requireId()) as $subscription) {
            if ($subscription->getFeed()->getUrl() === $url) {
                return array_values(array_map(static fn (Tag $tag): string => $tag->getName(), $subscription->getTags()->toArray()));
            }
        }
        self::fail('No subscription to ' . $url);
    }

    public function testChosenFollowsBecomeSubscriptionsUnderTheNetworksTagWithTheirTitle(): void
    {
        $result = $this->importer()->import($this->account(SocialNetwork::Bluesky), new SocialImportModel([
            new SocialImportFeedModel('https://bsky.app/profile/did:plc:bob/rss', 'Bob'),
            new SocialImportFeedModel('https://bsky.app/profile/did:plc:carol/rss', null),
        ], []));

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->removed);
        self::assertSame(['Bluesky'], $this->tagNamesOf('https://bsky.app/profile/did:plc:bob/rss'));
        $feed = $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://bsky.app/profile/did:plc:bob/rss']);
        self::assertSame('Bob', $feed?->getTitle());
    }

    public function testAnExistingSubscriptionIsNotRetagged(): void
    {
        $friends = new Tag($this->user, 'Friends');
        $friends->setPosition(0);
        $this->entityManager->persist($friends);
        $this->subscribe('https://mastodon.example/@bob.rss', $friends);

        $result = $this->importer()->import($this->account(SocialNetwork::Mastodon), new SocialImportModel([
            new SocialImportFeedModel('https://mastodon.example/@bob.rss', 'Bob'),
        ], []));

        self::assertSame(0, $result->imported);
        self::assertSame(1, $result->alreadySubscribed);
        self::assertSame(['Friends'], $this->tagNamesOf('https://mastodon.example/@bob.rss'));
    }

    public function testAFeedThatIsNoProfileFeedOfTheAccountsNetworkRefusesTheWholeImport(): void
    {
        $kept = $this->subscribe('https://bsky.app/profile/did:plc:gone/rss');
        $keptId = $kept->requireId();

        try {
            $this->importer()->import($this->account(SocialNetwork::Bluesky), new SocialImportModel([
                new SocialImportFeedModel('https://bsky.app/profile/did:plc:bob/rss', 'Bob'),
                new SocialImportFeedModel('https://mastodon.example/@bob.rss', 'Bob'),
            ], [$keptId]));
            self::fail('A foreign feed must refuse the import.');
        } catch (InvalidSocialImportFeedException) {
            $this->entityManager->clear();
            self::assertNotNull($this->entityManager->find(Subscription::class, $keptId));
            self::assertNull(
                $this->entityManager->getRepository(Feed::class)->findOneBy(['url' => 'https://bsky.app/profile/did:plc:bob/rss']),
            );
        }
    }

    public function testChosenSubscriptionsAreRemoved(): void
    {
        $gone = $this->subscribe('https://bsky.app/profile/did:plc:gone/rss');
        $goneId = $gone->requireId();

        $result = $this->importer()->import($this->account(SocialNetwork::Bluesky), new SocialImportModel([], [$goneId]));

        self::assertSame(1, $result->removed);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Subscription::class, $goneId));
    }

    public function testAnotherUsersSubscriptionCannotBeRemoved(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stranger = (new UserFactory($this->entityManager, $hasher))->create('social-importer-stranger@example.test');
        $feed = new Feed('https://bsky.app/profile/did:plc:theirs/rss');
        $this->entityManager->persist($feed);
        $theirs = new Subscription($stranger, $feed, new \DateTimeImmutable('2026-10-01 10:00:00'));
        $this->entityManager->persist($theirs);
        $this->entityManager->flush();

        $this->expectException(InvalidSelectionException::class);

        $this->importer()->import($this->account(SocialNetwork::Bluesky), new SocialImportModel([], [$theirs->requireId()]));
    }
}
```

Append to `SocialImportControllerTest`:

```php
    public function testImportSubscribesTheChosenFollowsAndRemovesTheChosenSubscriptions(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-post@example.test');
        $account = $this->blueskyAccountOf($user);
        $gone = $this->subscribe($user, 'https://bsky.app/profile/did:plc:gone/rss');

        $this->postImport($client, $account, [
            'subscribe' => [['feedUrl' => 'https://bsky.app/profile/did:plc:bob/rss', 'title' => 'Bob']],
            'unsubscribe' => [$gone->requireId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['imported' => 1, 'alreadySubscribed' => 0, 'invalid' => 0, 'skippedOverLimit' => 0, 'removed' => 1],
            $this->payload($client),
        );
    }

    public function testImportRefusesAFeedOfAnotherNetwork(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-foreign-feed@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), [
            'subscribe' => [['feedUrl' => 'https://mastodon.example/@bob.rss', 'title' => 'Bob']],
            'unsubscribe' => [],
        ]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('social_import_invalid_feed', $this->payload($client)['type']);
    }

    public function testImportWithNothingChosenIsAValidationError(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-empty@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['subscribe' => [], 'unsubscribe' => []]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('validation_error', $this->payload($client)['type']);
    }

    public function testImportCannotRemoveAnotherUsersSubscription(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $stranger = $this->signIn($client, 'import-stranger@example.test');
        $theirs = $this->subscribe($stranger, 'https://bsky.app/profile/did:plc:theirs/rss');
        $user = $this->signIn($client, 'import-thief@example.test');

        $this->postImport($client, $this->blueskyAccountOf($user), ['subscribe' => [], 'unsubscribe' => [$theirs->requireId()]]);

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testImportOnAnotherUsersAccountAnswers404(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $owner = $this->signIn($client, 'import-post-owner@example.test');
        $account = $this->blueskyAccountOf($owner);
        $this->signIn($client, 'import-post-intruder@example.test');

        $this->postImport($client, $account, [
            'subscribe' => [['feedUrl' => 'https://bsky.app/profile/did:plc:bob/rss', 'title' => 'Bob']],
        ]);

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testImportSpendsNoCandidatesBudget(): void
    {
        $client = $this->clientWith($this->nothingFollowed());
        $user = $this->signIn($client, 'import-no-budget@example.test');
        $account = $this->blueskyAccountOf($user);
        for ($attempt = 0; $attempt <= self::IMPORT_BUDGET; ++$attempt) {
            $this->requestCandidates($client, $account);
        }
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $this->postImport($client, $account, [
            'subscribe' => [['feedUrl' => 'https://bsky.app/profile/did:plc:bob/rss', 'title' => 'Bob']],
        ]);

        self::assertResponseIsSuccessful();
    }

    /** @param array<string, mixed> $body */
    private function postImport(KernelBrowser $client, SocialAccount $account, array $body): void
    {
        $client->request(
            'POST',
            sprintf('/api/me/social-accounts/%d/import', $account->requireId()),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialImporterTest.php tests/Controller/Api/SocialImportControllerTest.php`
Expected: errors `Class "App\Service\Social\Model\SocialImportModel" not found`; the new controller tests fail with 404 (no route).

- [ ] **Step 3: Implement**

Models (`src/Service/Social/Model/`):

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialImportFeedModel
{
    public function __construct(
        public string $feedUrl,
        public ?string $title,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class SocialImportModel
{
    /**
     * @param list<SocialImportFeedModel> $subscribe
     * @param list<int>                   $unsubscribe subscription ids
     */
    public function __construct(
        public array $subscribe,
        public array $unsubscribe,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Subscription\Model\BulkSubscribeResultModel;

final readonly class SocialImportResultModel
{
    private function __construct(
        public int $imported,
        public int $alreadySubscribed,
        public int $invalid,
        public int $skippedOverLimit,
        public int $removed,
    ) {
    }

    public static function from(BulkSubscribeResultModel $subscribed, int $removed): self
    {
        return new self(
            $subscribed->imported,
            $subscribed->alreadySubscribed,
            $subscribed->invalid,
            $subscribed->skippedOverLimit,
            $removed,
        );
    }
}
```

`src/Service/Social/Exception/InvalidSocialImportFeedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Exception;

final class InvalidSocialImportFeedException extends \RuntimeException
{
}
```

`src/Service/Social/SocialImporter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Exception\InvalidSelectionException;
use App\Service\Social\Exception\InvalidSocialImportFeedException;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Social\Model\SocialImportResultModel;
use App\Service\Social\Support\ProfileFeedUrl;
use App\Service\Subscription\BulkSubscriber;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\OwnedSubscriptions;
use App\Service\Subscription\SubscriptionService;

final readonly class SocialImporter
{
    public function __construct(
        private BulkSubscriber $bulkSubscriber,
        private OwnedSubscriptions $ownedSubscriptions,
        private SubscriptionService $subscriptionService,
    ) {
    }

    /**
     * @throws InvalidSocialImportFeedException before anything is written
     * @throws InvalidSelectionException        when a subscription to remove is not the owner's
     */
    public function import(SocialAccount $account, SocialImportModel $import): SocialImportResultModel
    {
        $network = $account->getNetwork();
        $this->assertProfileFeedsOf($network, $import->subscribe);
        $toRemove = $this->ownedSubscriptions->resolve($account->getUser()->requireId(), $import->unsubscribe);

        $subscribed = $this->bulkSubscriber->subscribeAll($account->getUser(), self::items($network, $import->subscribe));
        $removed = $this->subscriptionService->unsubscribeAll(array_values($toRemove));

        return SocialImportResultModel::from($subscribed, $removed);
    }

    /** @param list<SocialImportFeedModel> $feeds */
    private function assertProfileFeedsOf(SocialNetwork $network, array $feeds): void
    {
        foreach ($feeds as $feed) {
            if (null === ProfileFeedUrl::identityOf($network, $feed->feedUrl)) {
                throw new InvalidSocialImportFeedException(
                    sprintf('%s is no %s profile feed.', $feed->feedUrl, self::nameOf($network)),
                );
            }
        }
    }

    /**
     * @param list<SocialImportFeedModel> $feeds
     *
     * @return list<BulkSubscribeItemModel>
     */
    private static function items(SocialNetwork $network, array $feeds): array
    {
        $tagName = self::nameOf($network);

        return array_map(
            static fn (SocialImportFeedModel $feed): BulkSubscribeItemModel => new BulkSubscribeItemModel(
                $feed->feedUrl,
                feedTitle: $feed->title,
                tagName: $tagName,
            ),
            $feeds,
        );
    }

    private static function nameOf(SocialNetwork $network): string
    {
        return match ($network) {
            SocialNetwork::Bluesky => 'Bluesky',
            SocialNetwork::Mastodon => 'Mastodon',
        };
    }
}
```

`src/Dto/Social/SocialImportFeedItem.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\SocialImportFeedModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportFeedItem
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 750)]
        public string $feedUrl = '',
        #[Assert\Length(max: 512)]
        public string $title = '',
    ) {
    }

    public function toFeed(): SocialImportFeedModel
    {
        $title = trim($this->title);

        return new SocialImportFeedModel(trim($this->feedUrl), '' === $title ? null : $title);
    }
}
```

`src/Dto/Social/SocialImportRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\CollectedItemsModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Service\Social\Model\SocialImportModel;
use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SocialImportRequest
{
    /**
     * @param list<SocialImportFeedItem> $subscribe
     * @param list<int>                  $unsubscribe
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(max: CollectedItemsModel::MAX_ITEMS)]
        public array $subscribe = [],
        #[Assert\Count(max: SubscriptionService::MAX_BULK_REQUEST_IDS)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $unsubscribe = [],
    ) {
    }

    #[Assert\IsTrue(message: 'Choose at least one feed to subscribe to or unsubscribe from.')]
    public function hasSelection(): bool
    {
        return [] !== $this->subscribe || [] !== $this->unsubscribe;
    }

    public function toImport(): SocialImportModel
    {
        return new SocialImportModel(
            array_map(static fn (SocialImportFeedItem $item): SocialImportFeedModel => $item->toFeed(), $this->subscribe),
            $this->unsubscribe,
        );
    }
}
```

`SocialProblems::resolve()` — a new arm right after `NotAnAppPasswordException`'s:

```php
            $exception instanceof InvalidSocialImportFeedException => new ResolvedProblem(new ApiProblem(
                'social_import_invalid_feed',
                'Not a profile feed of this network',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
```

`SocialImportJson` gains:

```php
    /** @return array<string, int> */
    public function result(SocialImportResultModel $result): array
    {
        return [
            'imported' => $result->imported,
            'alreadySubscribed' => $result->alreadySubscribed,
            'invalid' => $result->invalid,
            'skippedOverLimit' => $result->skippedOverLimit,
            'removed' => $result->removed,
        ];
    }
```

`SocialImportController`: add `private SocialImporter $importer,` to the constructor (after `ImportCandidates`) and

```php
    #[Route('/{id}/import', name: 'api_me_social_accounts_import', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function import(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] SocialImportRequest $request,
    ): JsonResponse {
        $account = $this->socialAccount->require($user, $id);

        return new JsonResponse($this->importJson->result($this->importer->import($account, $request->toImport())));
    }
```

(imports: `App\Dto\Social\SocialImportRequest`, `App\Service\Social\SocialImporter`, `Symfony\Component\HttpKernel\Attribute\MapRequestPayload`.)

- [ ] **Step 4: Run them to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Controller/Api/SocialImportControllerTest.php tests/Service/Subscription`
Expected: PASS. If the `hasSelection()` violation does not surface as `validation_error`, read `src/Http/Problem/ProblemCatalog.php` (it unwraps `ValidationFailedException`) before changing the DTO; the constraint must stay a validation error, not a domain exception.

- [ ] **Step 5: Gates**

Run: `cd backend && composer check && composer md`, then `mcp__phpstorm__lint_files` on the changed `src` files.
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/tests/Service/Social/SocialImporterTest.php backend/tests/Controller/Api/SocialImportControllerTest.php
git commit -m "feat(#1598): import chosen follows and unsubscribe chosen no-longer-followed feeds"
```

---

### Task 8: Frontend service and import dialog

**Files:**
- Create: `frontend/src/app/settings/social/social-import.service.ts`, `social-import.service.spec.ts`
- Create: `frontend/src/app/settings/social/social-import-dialog.component.ts`, `.html`, `.scss`, `.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.social`)

**Interfaces:**
- Consumes: the two endpoints (Tasks 6, 7); `SocialNetwork` from `social-accounts.service.ts`; `pluralKey` (`core/i18n/plural-key`); `OverlayPanelComponent`, `DisclosureComponent` (`label`, `appearance="row"`), `ButtonComponent` (`variant`, `size`, `disabled`, `loading`), `ErrorBannerComponent` (`message`, `actionLabel`, `(action)`), `SpinnerComponent`, `WarningBoxComponent`; `SubscriptionsStore.load()`, `TagsStore.load()`, `RefreshService.run(onDone)`, `ToastService.show({message, durationMs})`, `CONFIRMATION_DURATION_MS`.
- Produces:
  - `SocialImportService` (provided by the dialog): signals `candidates`, `loading`, `importing`, `failure`; `load(accountId)`, `importSelection(accountId, selection): Observable<SocialImportResult>`.
  - Types `ImportCandidateState`, `FollowCandidate`, `SocialCollection`, `ImportCandidates`, `SocialImportSelection`, `SocialImportResult`.
  - `SocialImportDialogComponent` with `SocialImportDialogData { accountId: number; network: SocialNetwork; handle: string }`; closes with the `SocialImportResult` after an import, `undefined` on cancel.

- [ ] **Step 1: Add the i18n keys** (the specs run against the real dictionaries)

Under `settings.social` in `en.json`:

```json
    "import": "Import…",
    "importTitle": "Import from {{handle}}",
    "importLoading": "Loading the accounts you follow…",
    "importFilter": "Filter by name or handle",
    "importSelectAllNew": "Select all new",
    "importFollowsOne": "Account you follow ({{count}})",
    "importFollowsOther": "Accounts you follow ({{count}})",
    "importListsOne": "List ({{count}})",
    "importListsOther": "Lists ({{count}})",
    "importCustomFeedsOne": "Custom feed ({{count}})",
    "importCustomFeedsOther": "Custom feeds ({{count}})",
    "importStateNew": "New",
    "importStateSubscribed": "Subscribed",
    "importStateNoLongerFollowed": "No longer followed",
    "importSubscribe": "Subscribe to {{handle}}",
    "importUnsubscribe": "Unsubscribe from {{handle}}",
    "importUnsubscribeHint": "Tick an account marked “No longer followed” to unsubscribe from it.",
    "importCollectionsLater": "Importing lists and custom feeds arrives with private timeline feeds.",
    "importEmpty": "Nothing to show.",
    "importTruncated": "Only the first 5,000 are listed.",
    "importApply": "Import",
    "importDone": "{{imported}} added, {{removed}} removed, {{alreadySubscribed}} already subscribed.",
    "importOverLimit": "{{count}} not added: subscription limit reached."
```

and in `de.json`:

```json
    "import": "Importieren…",
    "importTitle": "Aus {{handle}} importieren",
    "importLoading": "Lade die Konten, denen du folgst…",
    "importFilter": "Nach Name oder Handle filtern",
    "importSelectAllNew": "Alle neuen auswählen",
    "importFollowsOne": "Konto, dem du folgst ({{count}})",
    "importFollowsOther": "Konten, denen du folgst ({{count}})",
    "importListsOne": "Liste ({{count}})",
    "importListsOther": "Listen ({{count}})",
    "importCustomFeedsOne": "Eigener Feed ({{count}})",
    "importCustomFeedsOther": "Eigene Feeds ({{count}})",
    "importStateNew": "Neu",
    "importStateSubscribed": "Abonniert",
    "importStateNoLongerFollowed": "Nicht mehr gefolgt",
    "importSubscribe": "{{handle}} abonnieren",
    "importUnsubscribe": "{{handle}} abbestellen",
    "importUnsubscribeHint": "Hake ein als „Nicht mehr gefolgt“ markiertes Konto an, um es abzubestellen.",
    "importCollectionsLater": "Listen und eigene Feeds lassen sich importieren, sobald private Timeline-Feeds kommen.",
    "importEmpty": "Nichts anzuzeigen.",
    "importTruncated": "Nur die ersten 5.000 werden angezeigt.",
    "importApply": "Importieren",
    "importDone": "{{imported}} hinzugefügt, {{removed}} entfernt, {{alreadySubscribed}} schon abonniert.",
    "importOverLimit": "{{count}} nicht hinzugefügt: Abo-Limit erreicht."
```

(`settings.social.import` is used by Task 9; it lands here so both dictionaries change once.)

- [ ] **Step 2: Write the failing specs**

`social-import.service.spec.ts`:

```ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { ImportCandidates, SocialImportService } from './social-import.service';

const BASE = 'https://api.test';
const CANDIDATES = `${BASE}/api/me/social-accounts/4/import-candidates`;
const IMPORT = `${BASE}/api/me/social-accounts/4/import`;
const EMPTY: ImportCandidates = { follows: [], lists: [], customFeeds: [], truncated: false };

describe('SocialImportService', () => {
  let http: HttpTestingController;
  let service: SocialImportService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        SocialImportService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    service = TestBed.inject(SocialImportService);
  });

  afterEach(() => http.verify());

  it('loads the candidates of one account', () => {
    service.load(4);
    expect(service.loading()).toBe(true);

    http.expectOne(CANDIDATES).flush(EMPTY);

    expect(service.candidates()).toEqual(EMPTY);
    expect(service.loading()).toBe(false);
  });

  it('keeps the problem message when loading fails and clears it on the next load', () => {
    service.load(4);
    http
      .expectOne(CANDIDATES)
      .flush(
        { type: 'social_network_unreachable', title: 'x', status: 502, detail: 'Bluesky could not be reached.' },
        { status: 502, statusText: 'Bad Gateway' },
      );
    expect(service.failure()).toBe('Bluesky could not be reached.');

    service.load(4);
    expect(service.failure()).toBeNull();
    http.expectOne(CANDIDATES).flush(EMPTY);
  });

  it('posts the selection and reports importing until the answer arrives', () => {
    const result = { imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 };
    let answered: unknown = null;

    service
      .importSelection(4, { subscribe: [{ feedUrl: 'https://bsky.app/profile/did:plc:bob/rss', title: 'Bob' }], unsubscribe: [] })
      .subscribe((answer) => (answered = answer));
    expect(service.importing()).toBe(true);
    const post = http.expectOne(IMPORT);
    expect(post.request.method).toBe('POST');
    post.flush(result);

    expect(answered).toEqual(result);
    expect(service.importing()).toBe(false);
  });
});
```

`social-import-dialog.component.spec.ts`:

```ts
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { RefreshService } from '../../reader/state/refresh.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SocialNetwork } from './social-accounts.service';
import { SocialImportDialogComponent } from './social-import-dialog.component';
import { FollowCandidate, ImportCandidates } from './social-import.service';

const BASE = 'https://api.test';
const CANDIDATES = `${BASE}/api/me/social-accounts/4/import-candidates`;
const IMPORT = `${BASE}/api/me/social-accounts/4/import`;

function follow(over: Partial<FollowCandidate> = {}): FollowCandidate {
  return {
    identity: 'did:plc:bob',
    handle: '@bob.bsky.social',
    displayName: 'Bob',
    feedUrl: 'https://bsky.app/profile/did:plc:bob/rss',
    state: 'new',
    subscriptionId: null,
    ...over,
  };
}

const CAROL = follow({
  identity: 'did:plc:carol',
  handle: '@carol.bsky.social',
  displayName: 'Carol',
  feedUrl: 'https://bsky.app/profile/did:plc:carol/rss',
  state: 'subscribed',
  subscriptionId: 9,
});
const DAVE = follow({
  identity: 'did:plc:dave',
  handle: 'did:plc:dave',
  displayName: 'Dave’s posts',
  feedUrl: 'https://bsky.app/profile/did:plc:dave/rss',
  state: 'no_longer_followed',
  subscriptionId: 10,
});

function candidates(over: Partial<ImportCandidates> = {}): ImportCandidates {
  return { follows: [], lists: [], customFeeds: [], truncated: false, ...over };
}

describe('SocialImportDialogComponent', () => {
  let http: HttpTestingController;
  const close = jest.fn();
  const subsStub = { load: jest.fn() };
  const tagsStub = { load: jest.fn() };
  const refreshStub = { run: jest.fn() };
  const toastStub = { show: jest.fn() };

  function mount(network: SocialNetwork = 'bluesky'): ComponentFixture<SocialImportDialogComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [SocialImportDialogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: DIALOG_DATA, useValue: { accountId: 4, network, handle: '@alice.bsky.social' } },
        { provide: DialogRef, useValue: { close } },
        { provide: SubscriptionsStore, useValue: subsStub },
        { provide: TagsStore, useValue: tagsStub },
        { provide: RefreshService, useValue: refreshStub },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(SocialImportDialogComponent);
    fixture.detectChanges();
    return fixture;
  }

  function loaded(
    body: ImportCandidates,
    network: SocialNetwork = 'bluesky',
  ): ComponentFixture<SocialImportDialogComponent> {
    const fixture = mount(network);
    http.expectOne(CANDIDATES).flush(body);
    fixture.detectChanges();
    return fixture;
  }

  const byTestId = <T extends HTMLElement>(
    fixture: ComponentFixture<SocialImportDialogComponent>,
    testId: string,
  ): T | null => (fixture.nativeElement as HTMLElement).querySelector<T>(`[data-testid="${testId}"]`);

  const checkboxOf = (
    fixture: ComponentFixture<SocialImportDialogComponent>,
    identity: string,
  ): HTMLInputElement =>
    byTestId(fixture, `social-import-follow-${identity}`)?.querySelector(
      'input[type="checkbox"]',
    ) as HTMLInputElement;

  function tick(fixture: ComponentFixture<SocialImportDialogComponent>, identity: string): void {
    checkboxOf(fixture, identity).click();
    fixture.detectChanges();
  }

  function press(fixture: ComponentFixture<SocialImportDialogComponent>, testId: string): void {
    byTestId(fixture, testId)?.querySelector('button')?.click();
    fixture.detectChanges();
  }

  function filterBy(fixture: ComponentFixture<SocialImportDialogComponent>, text: string): void {
    const input = byTestId<HTMLInputElement>(fixture, 'social-import-filter') as HTMLInputElement;
    input.value = text;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  beforeEach(() => {
    close.mockReset();
    subsStub.load.mockReset();
    tagsStub.load.mockReset();
    refreshStub.run.mockReset();
    toastStub.show.mockReset();
  });

  afterEach(() => http.verify());

  it('shows a spinner until the candidates arrive', () => {
    const fixture = mount();
    expect(byTestId(fixture, 'social-import-loading')).not.toBeNull();

    http.expectOne(CANDIDATES).flush(candidates());
    fixture.detectChanges();

    expect(byTestId(fixture, 'social-import-loading')).toBeNull();
  });

  it('labels each group with its count', () => {
    const fixture = loaded(
      candidates({ follows: [follow(), CAROL], lists: [{ id: 'at://list/a', name: 'News' }] }),
    );

    expect(byTestId(fixture, 'social-import-follows')?.textContent).toContain('Accounts you follow (2)');
    expect(byTestId(fixture, 'social-import-lists')?.textContent).toContain('List (1)');
    expect(byTestId(fixture, 'social-import-feeds')?.textContent).toContain('Custom feeds (0)');
  });

  it('has no custom feeds group on Mastodon', () => {
    const fixture = loaded(candidates(), 'mastodon');

    expect(byTestId(fixture, 'social-import-feeds')).toBeNull();
  });

  it('marks each follow with its state and shows subscribed ones checked and fixed', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL, DAVE] }));

    expect(checkboxOf(fixture, 'did:plc:bob').checked).toBe(false);
    expect(checkboxOf(fixture, 'did:plc:bob').disabled).toBe(false);
    expect(checkboxOf(fixture, 'did:plc:carol').checked).toBe(true);
    expect(checkboxOf(fixture, 'did:plc:carol').disabled).toBe(true);
    expect(checkboxOf(fixture, 'did:plc:dave').checked).toBe(false);
    const text = byTestId(fixture, 'social-import-follows')?.textContent ?? '';
    expect(text).toContain('New');
    expect(text).toContain('Subscribed');
    expect(text).toContain('No longer followed');
  });

  it('lists lists and custom feeds without a checkbox', () => {
    const fixture = loaded(
      candidates({
        lists: [{ id: 'at://list/a', name: 'News' }],
        customFeeds: [{ id: 'at://feed/a', name: 'Discover' }],
      }),
    );

    const lists = byTestId(fixture, 'social-import-lists') as HTMLElement;
    expect(lists.textContent).toContain('News');
    expect(lists.textContent).toContain('arrives with private timeline feeds');
    expect(lists.querySelector('input')).toBeNull();
    expect(byTestId(fixture, 'social-import-feeds')?.querySelector('input')).toBeNull();
  });

  it('filters by handle or display name', () => {
    const fixture = loaded(candidates({ follows: [follow(), CAROL] }));

    filterBy(fixture, 'caro');

    expect(byTestId(fixture, 'social-import-follow-did:plc:bob')).toBeNull();
    expect(byTestId(fixture, 'social-import-follow-did:plc:carol')).not.toBeNull();
  });

  it('warns when the follow list was cut at the cap', () => {
    const fixture = loaded(candidates({ truncated: true }));

    expect(byTestId(fixture, 'social-import-truncated')?.textContent).toContain('first 5,000');
  });

  it('keeps Import disabled until something is chosen', () => {
    const fixture = loaded(candidates({ follows: [follow()] }));
    const apply = () =>
      byTestId(fixture, 'social-import-apply')?.querySelector('button') as HTMLButtonElement;

    expect(apply().disabled).toBe(true);
    tick(fixture, 'did:plc:bob');
    expect(apply().disabled).toBe(false);
  });

  it('selects every visible new follow at once', () => {
    const erin = follow({
      identity: 'did:plc:erin',
      handle: '@erin.bsky.social',
      displayName: 'Erin',
      feedUrl: 'https://bsky.app/profile/did:plc:erin/rss',
    });
    const fixture = loaded(candidates({ follows: [follow(), erin, CAROL] }));
    filterBy(fixture, 'bob');

    press(fixture, 'social-import-select-new');
    filterBy(fixture, '');
    press(fixture, 'social-import-apply');

    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({
      subscribe: [{ feedUrl: 'https://bsky.app/profile/did:plc:bob/rss', title: 'Bob' }],
      unsubscribe: [],
    });
    post.flush({ imported: 1, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 0 });
  });

  it('imports the chosen follows, unsubscribes the chosen leftovers and reloads the reader', () => {
    const nameless = follow({
      identity: 'did:plc:fay',
      handle: '@fay.bsky.social',
      displayName: null,
      feedUrl: 'https://bsky.app/profile/did:plc:fay/rss',
    });
    const fixture = loaded(candidates({ follows: [follow(), nameless, DAVE] }));
    tick(fixture, 'did:plc:bob');
    tick(fixture, 'did:plc:fay');
    tick(fixture, 'did:plc:dave');

    press(fixture, 'social-import-apply');
    const post = http.expectOne(IMPORT);
    expect(post.request.body).toEqual({
      subscribe: [
        { feedUrl: 'https://bsky.app/profile/did:plc:bob/rss', title: 'Bob' },
        { feedUrl: 'https://bsky.app/profile/did:plc:fay/rss', title: '@fay.bsky.social' },
      ],
      unsubscribe: [10],
    });
    const result = { imported: 2, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 1, removed: 1 };
    post.flush(result);

    expect(toastStub.show).toHaveBeenCalledWith({
      message: '2 added, 1 removed, 0 already subscribed. 1 not added: subscription limit reached.',
      durationMs: CONFIRMATION_DURATION_MS,
    });
    expect(subsStub.load).toHaveBeenCalled();
    expect(tagsStub.load).toHaveBeenCalled();
    expect(refreshStub.run).toHaveBeenCalled();
    expect(close).toHaveBeenCalledWith(result);
  });

  it('skips the refresh when nothing new was added', () => {
    const fixture = loaded(candidates({ follows: [DAVE] }));
    tick(fixture, 'did:plc:dave');

    press(fixture, 'social-import-apply');
    http
      .expectOne(IMPORT)
      .flush({ imported: 0, alreadySubscribed: 0, invalid: 0, skippedOverLimit: 0, removed: 1 });

    expect(refreshStub.run).not.toHaveBeenCalled();
    expect(subsStub.load).toHaveBeenCalled();
  });

  it('shows the problem and retries the load', () => {
    const fixture = mount();
    http
      .expectOne(CANDIDATES)
      .flush(
        {
          type: 'social_credentials_rejected',
          title: 'x',
          status: 422,
          detail: 'Bluesky refused this handle and app password.',
        },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    fixture.detectChanges();

    const banner = byTestId(fixture, 'social-import-failure') as HTMLElement;
    expect(banner.textContent).toContain('Bluesky refused this handle and app password.');
    (banner.querySelector('button.action') as HTMLButtonElement).click();
    fixture.detectChanges();

    http.expectOne(CANDIDATES).flush(candidates());
  });

  it('closes without a result on cancel', () => {
    const fixture = loaded(candidates());

    press(fixture, 'social-import-cancel');

    expect(close).toHaveBeenCalledWith(undefined);
  });
});
```

- [ ] **Step 3: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social/social-import`
Expected: FAIL, `Cannot find module './social-import.service'` / `'./social-import-dialog.component'`.

- [ ] **Step 4: Implement**

`social-import.service.ts`:

```ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, finalize, tap } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { parseProblem, problemMessage } from '../../core/problem';

export type ImportCandidateState = 'new' | 'subscribed' | 'no_longer_followed';

export interface FollowCandidate {
  readonly identity: string;
  readonly handle: string;
  readonly displayName: string | null;
  readonly feedUrl: string;
  readonly state: ImportCandidateState;
  readonly subscriptionId: number | null;
}

export interface SocialCollection {
  readonly id: string;
  readonly name: string;
}

export interface ImportCandidates {
  readonly follows: readonly FollowCandidate[];
  readonly lists: readonly SocialCollection[];
  readonly customFeeds: readonly SocialCollection[];
  readonly truncated: boolean;
}

export interface SocialImportSelection {
  readonly subscribe: readonly { readonly feedUrl: string; readonly title: string }[];
  readonly unsubscribe: readonly number[];
}

export interface SocialImportResult {
  readonly imported: number;
  readonly alreadySubscribed: number;
  readonly invalid: number;
  readonly skippedOverLimit: number;
  readonly removed: number;
}

@Injectable()
export class SocialImportService {
  private readonly http = inject(HttpClient);
  private readonly endpoint = `${inject(API_BASE_URL)}/api/me/social-accounts`;

  readonly candidates = signal<ImportCandidates | null>(null);
  readonly loading = signal(false);
  readonly importing = signal(false);
  readonly failure = signal<string | null>(null);

  load(accountId: number): void {
    this.loading.set(true);
    this.failure.set(null);
    this.http
      .get<ImportCandidates>(`${this.endpoint}/${accountId}/import-candidates`)
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (candidates) => this.candidates.set(candidates),
        error: (error: HttpErrorResponse) => this.fail(error),
      });
  }

  importSelection(accountId: number, selection: SocialImportSelection): Observable<SocialImportResult> {
    this.importing.set(true);
    this.failure.set(null);
    return this.http.post<SocialImportResult>(`${this.endpoint}/${accountId}/import`, selection).pipe(
      tap({ error: (error: HttpErrorResponse) => this.fail(error) }),
      finalize(() => this.importing.set(false)),
    );
  }

  private fail(error: HttpErrorResponse): void {
    this.failure.set(problemMessage(parseProblem(error)));
  }
}
```

`social-import-dialog.component.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { A11yModule } from '@angular/cdk/a11y';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { pluralKey } from '../../core/i18n/plural-key';
import { RefreshService } from '../../reader/state/refresh.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { ButtonComponent } from '../../shared/button/button.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { SpinnerComponent } from '../../shared/spinner/spinner.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { WarningBoxComponent } from '../../shared/warning-box/warning-box.component';
import { SocialNetwork } from './social-accounts.service';
import {
  FollowCandidate,
  ImportCandidateState,
  SocialImportResult,
  SocialImportService,
} from './social-import.service';

export interface SocialImportDialogData {
  readonly accountId: number;
  readonly network: SocialNetwork;
  readonly handle: string;
}

const STATE_KEYS: Readonly<Record<ImportCandidateState, string>> = {
  new: 'settings.social.importStateNew',
  subscribed: 'settings.social.importStateSubscribed',
  no_longer_followed: 'settings.social.importStateNoLongerFollowed',
};

@Component({
  selector: 'app-social-import-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    OverlayPanelComponent,
    SpinnerComponent,
    TranslocoPipe,
    WarningBoxComponent,
  ],
  providers: [SocialImportService],
  templateUrl: './social-import-dialog.component.html',
  styleUrl: './social-import-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SocialImportDialogComponent {
  private readonly ref = inject<DialogRef<SocialImportResult | undefined>>(DialogRef);
  protected readonly data = inject<SocialImportDialogData>(DIALOG_DATA);
  protected readonly svc = inject(SocialImportService);
  private readonly subs = inject(SubscriptionsStore);
  private readonly tags = inject(TagsStore);
  private readonly refresh = inject(RefreshService);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(TranslocoService);

  protected readonly showsCustomFeeds = this.data.network === 'bluesky';
  protected readonly filter = signal('');
  protected readonly toSubscribe = signal<ReadonlySet<string>>(new Set());
  protected readonly toUnsubscribe = signal<ReadonlySet<number>>(new Set());

  private readonly needle = computed(() => this.filter().trim().toLowerCase());

  protected readonly follows = computed(() =>
    (this.svc.candidates()?.follows ?? []).filter((follow) =>
      this.matches(follow.handle, follow.displayName),
    ),
  );
  protected readonly lists = computed(() =>
    (this.svc.candidates()?.lists ?? []).filter((list) => this.matches(list.name)),
  );
  protected readonly customFeeds = computed(() =>
    (this.svc.candidates()?.customFeeds ?? []).filter((feed) => this.matches(feed.name)),
  );
  protected readonly visibleNew = computed(() =>
    this.follows().filter((follow) => follow.state === 'new'),
  );
  protected readonly hasLeftovers = computed(() =>
    this.follows().some((follow) => follow.state === 'no_longer_followed'),
  );
  protected readonly selectedCount = computed(
    () => this.toSubscribe().size + this.toUnsubscribe().size,
  );

  protected readonly followsKey = computed(() =>
    pluralKey('settings.social.importFollows', this.follows().length),
  );
  protected readonly listsKey = computed(() =>
    pluralKey('settings.social.importLists', this.lists().length),
  );
  protected readonly customFeedsKey = computed(() =>
    pluralKey('settings.social.importCustomFeeds', this.customFeeds().length),
  );

  constructor() {
    this.svc.load(this.data.accountId);
  }

  protected typed(event: Event): string {
    return (event.target as HTMLInputElement).value;
  }

  protected stateKey(follow: FollowCandidate): string {
    return STATE_KEYS[follow.state];
  }

  protected isMarkedForRemoval(follow: FollowCandidate): boolean {
    return follow.subscriptionId !== null && this.toUnsubscribe().has(follow.subscriptionId);
  }

  protected toggleSubscribe(follow: FollowCandidate): void {
    this.toSubscribe.update((current) => toggled(current, follow.feedUrl));
  }

  protected toggleUnsubscribe(follow: FollowCandidate): void {
    const subscriptionId = follow.subscriptionId;
    if (subscriptionId === null) return;
    this.toUnsubscribe.update((current) => toggled(current, subscriptionId));
  }

  protected selectAllNew(): void {
    const feedUrls = this.visibleNew().map((follow) => follow.feedUrl);
    this.toSubscribe.update((current) => new Set([...current, ...feedUrls]));
  }

  protected retry(): void {
    this.svc.load(this.data.accountId);
  }

  protected apply(): void {
    const subscribe = (this.svc.candidates()?.follows ?? [])
      .filter((follow) => follow.state === 'new' && this.toSubscribe().has(follow.feedUrl))
      .map((follow) => ({ feedUrl: follow.feedUrl, title: follow.displayName ?? follow.handle }));
    const unsubscribe = [...this.toUnsubscribe()];

    this.svc.importSelection(this.data.accountId, { subscribe, unsubscribe }).subscribe({
      next: (result) => this.finish(result),
      error: () => undefined,
    });
  }

  protected cancel(): void {
    this.ref.close(undefined);
  }

  private finish(result: SocialImportResult): void {
    this.announce(result);
    this.subs.load();
    this.tags.load();
    if (result.imported > 0) {
      this.refresh.run(() => this.subs.load());
    }
    this.ref.close(result);
  }

  private announce(result: SocialImportResult): void {
    const done = this.i18n.translate('settings.social.importDone', {
      imported: result.imported,
      removed: result.removed,
      alreadySubscribed: result.alreadySubscribed,
    });
    const overLimit =
      result.skippedOverLimit > 0
        ? ' ' + this.i18n.translate('settings.social.importOverLimit', { count: result.skippedOverLimit })
        : '';
    this.toast.show({ message: done + overLimit, durationMs: CONFIRMATION_DURATION_MS });
  }

  private matches(...texts: readonly (string | null)[]): boolean {
    const needle = this.needle();
    return needle === '' || texts.some((text) => (text ?? '').toLowerCase().includes(needle));
  }
}

function toggled<T>(current: ReadonlySet<T>, value: T): ReadonlySet<T> {
  const next = new Set(current);
  if (next.has(value)) {
    next.delete(value);
  } else {
    next.add(value);
  }
  return next;
}
```

`social-import-dialog.component.html`:

```html
<app-overlay-panel
  cdkTrapFocus
  [heading]="'settings.social.importTitle' | transloco: { handle: data.handle }"
>
  @if (svc.candidates(); as candidates) {
    <div class="toolbar">
      <input
        type="search"
        class="filter"
        cdkFocusInitial
        data-testid="social-import-filter"
        [attr.aria-label]="'settings.social.importFilter' | transloco"
        [placeholder]="'settings.social.importFilter' | transloco"
        [value]="filter()"
        (input)="filter.set(typed($event))"
      />
      <app-button
        size="sm"
        data-testid="social-import-select-new"
        [disabled]="visibleNew().length === 0"
        (click)="selectAllNew()"
      >
        {{ 'settings.social.importSelectAllNew' | transloco }}
      </app-button>
    </div>

    @if (candidates.truncated) {
      <app-warning-box data-testid="social-import-truncated">
        {{ 'settings.social.importTruncated' | transloco }}
      </app-warning-box>
    }

    <app-disclosure
      appearance="row"
      data-testid="social-import-follows"
      [label]="followsKey() | transloco: { count: follows().length }"
    >
      @if (hasLeftovers()) {
        <p class="note">{{ 'settings.social.importUnsubscribeHint' | transloco }}</p>
      }
      <ul class="rows">
        @for (follow of follows(); track follow.feedUrl) {
          <li>
            <label class="row" [attr.data-testid]="'social-import-follow-' + follow.identity">
              @switch (follow.state) {
                @case ('subscribed') {
                  <input
                    type="checkbox"
                    checked
                    disabled
                    [attr.aria-label]="'settings.social.importStateSubscribed' | transloco"
                  />
                }
                @case ('new') {
                  <input
                    type="checkbox"
                    [checked]="toSubscribe().has(follow.feedUrl)"
                    [attr.aria-label]="
                      'settings.social.importSubscribe' | transloco: { handle: follow.handle }
                    "
                    (change)="toggleSubscribe(follow)"
                  />
                }
                @default {
                  <input
                    type="checkbox"
                    [checked]="isMarkedForRemoval(follow)"
                    [attr.aria-label]="
                      'settings.social.importUnsubscribe' | transloco: { handle: follow.handle }
                    "
                    (change)="toggleUnsubscribe(follow)"
                  />
                }
              }
              <span class="who">
                <span class="handle">{{ follow.handle }}</span>
                @if (follow.displayName) {
                  <span class="name">{{ follow.displayName }}</span>
                }
              </span>
              <span
                class="state"
                [class.is-new]="follow.state === 'new'"
                [class.is-gone]="follow.state === 'no_longer_followed'"
              >
                {{ stateKey(follow) | transloco }}
              </span>
            </label>
          </li>
        } @empty {
          <li class="empty">{{ 'settings.social.importEmpty' | transloco }}</li>
        }
      </ul>
    </app-disclosure>

    <app-disclosure
      appearance="row"
      data-testid="social-import-lists"
      [label]="listsKey() | transloco: { count: lists().length }"
    >
      <p class="note">{{ 'settings.social.importCollectionsLater' | transloco }}</p>
      <ul class="rows">
        @for (list of lists(); track list.id) {
          <li class="row"><span class="handle">{{ list.name }}</span></li>
        } @empty {
          <li class="empty">{{ 'settings.social.importEmpty' | transloco }}</li>
        }
      </ul>
    </app-disclosure>

    @if (showsCustomFeeds) {
      <app-disclosure
        appearance="row"
        data-testid="social-import-feeds"
        [label]="customFeedsKey() | transloco: { count: customFeeds().length }"
      >
        <p class="note">{{ 'settings.social.importCollectionsLater' | transloco }}</p>
        <ul class="rows">
          @for (feed of customFeeds(); track feed.id) {
            <li class="row"><span class="handle">{{ feed.name }}</span></li>
          } @empty {
            <li class="empty">{{ 'settings.social.importEmpty' | transloco }}</li>
          }
        </ul>
      </app-disclosure>
    }
  } @else if (svc.loading()) {
    <p class="loading" data-testid="social-import-loading">
      <app-spinner [size]="16" [decorative]="true" />
      {{ 'settings.social.importLoading' | transloco }}
    </p>
  }

  @if (svc.failure(); as message) {
    <app-error-banner
      data-testid="social-import-failure"
      [message]="message"
      [actionLabel]="svc.candidates() === null ? ('common.retry' | transloco) : null"
      (action)="retry()"
    />
  }

  <app-button footer data-testid="social-import-cancel" (click)="cancel()">
    {{ 'common.cancel' | transloco }}
  </app-button>
  <app-button
    footer
    variant="primary"
    data-testid="social-import-apply"
    [disabled]="selectedCount() === 0"
    [loading]="svc.importing()"
    (click)="apply()"
  >
    {{ 'settings.social.importApply' | transloco }}
  </app-button>
</app-overlay-panel>
```

`social-import-dialog.component.scss`:

```scss
@use '../../theme/glass-rim';

:host {
  display: contents;

  --panel-w: 520px;
}

.toolbar {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.filter {
  flex: 1;
  min-width: 0;
}

.rows {
  margin: 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-1) 0;
}

.who {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}

.handle {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.name {
  color: var(--text-muted);
  font-size: var(--fs-sm);
}

.state {
  @include glass-rim.filled(var(--surface-1), var(--border));

  padding: var(--space-0) var(--space-2);
  border-radius: var(--radius-pill);
  color: var(--text-secondary);
  font-size: var(--fs-xs);
  white-space: nowrap;
}

.state.is-new {
  color: var(--accent);
}

.state.is-gone {
  color: var(--warning);
}

.note,
.empty,
.loading {
  margin: 0 0 var(--space-2);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}

.loading {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}
```

Read `docs/design-language.md` (overlay and density sections) before running the gate; if Stylelint rejects a token or the `--panel-w` literal, copy the exact form `reader/feeds/add-feed/add-feed-dialog.component.scss` uses.

- [ ] **Step 5: Run the specs to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social src/app/core/i18n`
Expected: PASS (the i18n spec checks both dictionaries hold the same keys).

- [ ] **Step 6: Frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: clean (ESLint, Prettier at 100 columns, Stylelint, tsc, Jest).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings/social frontend/public/i18n
git commit -m "feat(#1598): the social import picker dialog"
```

---

### Task 9: Wire the picker into Settings → Social Media; docs

**Files:**
- Modify: `frontend/src/app/settings/social/social-section.component.ts`, `.html`, `.spec.ts`
- Modify: `docs/security.md` ("Social accounts")

**Interfaces:**
- Consumes: `SocialImportDialogComponent`, `SocialImportDialogData`, `SocialImportResult` (Task 8); `Dialog` from `@angular/cdk/dialog`; `SocialAccountsService.load()`; key `settings.social.import` (Task 8).
- Produces: a per-account button `data-testid="social-import-<id>"`, disabled while busy or when the account needs reconnecting; opening the dialog with `panelClass: 'app-dialog'`; reloading the accounts whenever the dialog closes (so a credential refused during the load shows "Needs reconnecting").

- [ ] **Step 1: Write the failing specs**

In `social-section.component.spec.ts`: add imports `Dialog` (`@angular/cdk/dialog`), `of` (`rxjs`), `SocialImportDialogComponent` (`./social-import-dialog.component`); declare

```ts
  const dialogStub = { open: jest.fn(() => ({ closed: of(undefined) })) };
```

add `{ provide: Dialog, useValue: dialogStub }` to `mount()`'s providers, `dialogStub.open.mockClear();` to `beforeEach`, and the tests:

```ts
  it('offers Import on a connected account and disables it on one that needs reconnecting', () => {
    const fixture = mount([
      account(),
      account({ id: 2, handle: 'bob.bsky.social', isDefault: false, status: 'needs_reconnect' }),
    ]);

    const button = (id: number) =>
      byTestId(fixture, `social-import-${id}`)?.querySelector('button') as HTMLButtonElement;
    expect(button(1).disabled).toBe(false);
    expect(button(1).textContent).toContain('Import…');
    expect(button(2).disabled).toBe(true);
  });

  it('opens the picker for the account and reloads the accounts when it closes', () => {
    const fixture = mount([account()]);

    press(fixture, 'social-import-1');

    expect(dialogStub.open).toHaveBeenCalledWith(SocialImportDialogComponent, {
      data: { accountId: 1, network: 'bluesky', handle: 'alice.bsky.social' },
      panelClass: 'app-dialog',
    });
    http.expectOne(ENDPOINT).flush({ accounts: [account({ status: 'needs_reconnect' })] });
    fixture.detectChanges();
    expect(byTestId(fixture, 'social-needs-reconnect-1')).not.toBeNull();
  });
```

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social/social-section`
Expected: FAIL — `social-import-1` not found (`Cannot read properties of null`).

- [ ] **Step 3: Implement**

`social-section.component.ts`: import `Dialog` from `@angular/cdk/dialog` and the dialog types; inject `private readonly dialog = inject(Dialog);` and add

```ts
  protected openImport(account: SocialAccount): void {
    const data: SocialImportDialogData = {
      accountId: account.id,
      network: account.network,
      handle: account.handle,
    };
    this.dialog
      .open<SocialImportResult | undefined>(SocialImportDialogComponent, {
        data,
        panelClass: 'app-dialog',
      })
      .closed.subscribe(() => this.svc.load());
  }
```

`social-section.component.html`, inside `.account-actions` before the Disconnect button:

```html
        <app-button
          size="sm"
          [attr.data-testid]="'social-import-' + account.id"
          [disabled]="svc.busy() || account.status !== 'connected'"
          (click)="openImport(account)"
        >
          {{ 'settings.social.import' | transloco }}
        </app-button>
```

`docs/security.md`, append to the "Social accounts" bullet list:

```markdown
- The stored credential is read back only to load the import picker (#1598): Bluesky opens one session per load
  (`BlueskySessionOpener`), Mastodon sends the token as a bearer header. A load is rate-limited per user
  (`social_import`, 10 per 15 minutes). A refused or unreadable credential marks the account as needing a reconnect,
  and an account in that state is refused before any network call.
- The picker follows a Mastodon `Link` next page only on the instance's own origin, and reads at most 5000 follows.
```

- [ ] **Step 4: Run them to verify they pass**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social`
Expected: PASS.

- [ ] **Step 5: Frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/settings/social docs/security.md
git commit -m "feat(#1598): open the import picker from each connected account"
```

---

### Task 10: Full gates, real-network check, PR

**Files:** none new (fixes only, if a gate finds something).

- [ ] **Step 1: Backend on both legs**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && composer test:parallel
docker compose exec php composer test
```

Expected: all green. Scan today's dev log for deprecations and swallowed errors:

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'
```

- [ ] **Step 2: Mutation gate**

```bash
git fetch origin develop
cd backend && composer infection:diff
```

Expected: MSI ≥ `minMsi` (80). For each escaped mutant, add the test that kills it (never lower `minMsi`, never add a blanket ignore); an equivalent mutant gets a one-mutator, one-method ignore in `infection.json5` with the reason, as the existing entries do. Commit any added tests as `test(#1598): …`.

- [ ] **Step 3: Frontend gate**

```bash
docker compose exec -T frontend npm run check
```

- [ ] **Step 4: Real-network check (the user does the clicking)**

Make the Docker dev stack current first: `docker compose up -d`, `docker compose exec php bin/console cache:clear`, `docker compose restart worker nginx`, and confirm `http://localhost:4200` serves the new bundle (hard reload; the Import button must be visible). No credentials pass through the agent: both accounts are already connected in the dev database from #1597.

Ask Lars to:
1. Open Settings → Social Media → **Import…** on `@lars-pohlmann.de` (Bluesky). Confirm: a spinner, then three collapsed groups with counts; follows marked New / Subscribed / No longer followed; existing handle-based `bsky.app/profile/<handle>/rss` subscriptions show as Subscribed; lists and custom feeds listed read-only with the note; the filter narrows all groups; "Select all new" ticks only visible new rows.
2. Import two or three new follows. Confirm the toast counts, the sidebar shows them under the `Bluesky` tag, and reopening the picker shows them as Subscribed.
3. Repeat on `@lars_pohlmann@mastodon.social`: remote follows' feed URLs (`https://<their instance>/@user.rss`), lists, no custom feeds group, the `Mastodon` tag.
4. If a "No longer followed" row exists, tick it and import; confirm the subscription is gone.

Then scan the dev log as in Step 1 and the worker's first fetch of the imported feeds. Report the outcome; if Lars has not run the check, say so rather than marking the step done.

- [ ] **Step 5: Open the PR**

Push the branch and open a PR against `develop` with body `Closes #1598` and `Part of #1596`, covering: the guarded client's headers; `StoredCredential`/`BlueskySessionOpener` (one Bluesky session per picker load, rate-limited); identity matching (DID, handle, Mastodon feed-URL key; at most 25 handle resolutions per load, stopping at the first unreachable one); only curated Bluesky lists are listed (moderation lists are no feeds); lists and custom feeds are read-only until #1599; the known limitation that with two accounts on one network a feed followed only by the other account shows as "No longer followed" (offered unchecked, so harmless); no migration.

---

## Self-review

1. **Spec coverage.** Headers (T1); credential reading, session, DRY with the verifier (T2); Bluesky follows / curated lists / saved feeds with batched names, cap, truncation (T3); Mastodon follows with same-origin `Link` paging, identity and feed-URL rules, lists, 401/403 vs unreachable (T4); identity matching incl. handle → DID via `resolveHandle` only, no-longer-followed with display title, `needs_reconnect` marking, ordering (T5); candidates endpoint with ownership → needs-reconnect → budget → network, `social_import` 10/15 min, 409 arm (T6); import endpoint with profile-feed validation, `BulkSubscriber` tagging without retagging, owned unsubscribe, not-both-empty validation, no limiter, 422 arm (T7); dialog with three collapsed groups, counts via `pluralKey`, filter with `cdkFocusInitial`, select-all-new, truncation note, read-only collections, primary loading Import, toast, store reloads and refresh (T8); per-account button disabled on `needs_reconnect`, accounts reload after close (T9); security docs (T9); both suites, infection, real-network check (T10).
2. **Deviations from the brief, made deliberately:** `BlueskySessionModel` also carries `handle` (the verifier needs it); `SocialCollectionModel` has no `description` (the JSON contract is `{id, name}`); moderation lists are filtered out; handle resolution is capped at 25 per load and stops at the first unreachable answer (a 10 s timeout per handle could otherwise exceed the web worker's time limit); a no-longer-followed handle is shown `@`-prefixed unless it is a DID; an unreadable stored secret counts as a refused credential.
3. **Type consistency.** `SocialCandidatesInterface::candidates(SocialAccount)` (T3) is what `ImportCandidates` (T5) and `StubSocialCandidates` (T5, T6) use; `FollowMatching(SocialNetwork, list<FollowedAccountModel>)` and `FollowMatcher::match(FollowMatching, list<ProfileFeedSubscriptionModel>)` match across T5; `SocialImportJson::candidates()` (T6) and `::result()` (T7) read the models' public fields as declared; the frontend `FollowCandidate` fields mirror `SocialImportJson::follow()` key for key.
