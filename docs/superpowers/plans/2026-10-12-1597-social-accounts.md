# Social accounts (#1597) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A user connects Bluesky and Mastodon accounts in Settings → Social Media; the credential is verified against the network once, then stored encrypted; accounts can be reconnected, made the network default, and disconnected.

**Architecture:** A new `Service/Social` module owns the `SocialAccount` entity's lifecycle. A `SocialCredentialsModel` (network, address, secret) is verified by `AccountVerifierInterface`, whose one implementation dispatches through a tagged keyed locator to a per-network `NetworkVerifierInterface` (Bluesky, Mastodon), mirroring `Service/Refresh/FeedBodyParser`. Every outbound call goes through a new `Service/Fetch/GuardedApiClient`, which runs `UrlGuard` and pins the validated IPs exactly as the feed fetcher does (no redirects, size-capped). The secret is sealed by a `SocialCredentialCipher` over `InstanceSecretCipher`. A thin `SocialAccountController` under `/api/me/social-accounts` shapes responses through `Http/SocialAccountJson`; failures map through `Http/Problem/ExceptionProblems/SocialProblems`. The frontend adds one settings section.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Doctrine ORM, symfony/http-client (MockHttpClient in tests), libsodium via `InstanceSecretCipher`; Angular 20 (standalone, signals), Transloco, Jest.

**Spec:** issues #1596 (joint design) and #1597 (this slice).

## Global Constraints

- CLAUDE.md "PHP code style" applies in full: `final readonly` services, role folders (`Model/`, `Factory/`, `Exception/`, folder per interface), no boolean flag parameters, no abbreviations, no `toArray()` in `App\Service`, typed exceptions next to the service, guard clauses, comments only when a reader would otherwise get the code wrong.
- Every `src` file touched must be clean under `composer cs`, `composer stan`, `composer md`, `composer tramp`.
- **Never bypass `UrlGuard`.** `ProviderCredentialsModel`'s skip is a recorded exception for AI only (docs/security.md "AI provider endpoints"); social calls are guarded.
- Credentials, not OAuth: some reader installs are unreachable from outside (#1596).
- Bluesky secret is an **app password** only: `^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$`. Mastodon secret is a personal access token; the hint text names scopes `read` and `write:favourites`.
- Instance URLs are `https` only.
- At most `20` social accounts per user.
- Out of scope here (later sub-issues): Bluesky session caching (#1599, its first consumer), marking an account "needs reconnecting" from a failed poll (#1599), private feeds and their deletion on disconnect (#1599), import (#1598), likes (#1600). This slice ships the `status` column and the reconnect flow; nothing sets `needs_reconnect` yet except tests.
- Commit format: `type(#1597): subject`. Branch: `feature/1597-social-accounts` (already created off `develop`).
- Backend tests: `php bin/phpunit <path>` natively (SQLite); before the PR also `docker compose exec php composer test` (MySQL). Frontend tests only in the container: `docker compose exec -T frontend npm test -- <path>`; gate `docker compose exec -T frontend npm run check`.

---

### Task 1: Guarded API client in the Fetch module

**Files:**
- Create: `backend/src/Service/Fetch/GuardedApiClient.php`
- Create: `backend/src/Service/Fetch/Model/ApiRequestModel.php`
- Create: `backend/src/Service/Fetch/Model/ApiResponseModel.php`
- Modify: `backend/src/Service/Fetch/Model/ResponseSizeLimit.php` (add `case Api = 2;`)
- Test: `backend/tests/Service/Fetch/GuardedApiClientTest.php`

**Interfaces:**
- Produces: `GuardedApiClient::send(ApiRequestModel $request): ApiResponseModel` — throws `SsrfBlockedException` (guard), `FetchException` (transport, via `FetchException::from`), `ResponseTooLargeException`.
- `ApiRequestModel::get(string $url, array $headers = []): self`, `ApiRequestModel::postJson(string $url, array $json, array $headers = []): self`; public readonly `method`, `url`, `headers`, `json` (`?array`).
- `ApiResponseModel` public readonly `int $status`, `string $body`; `isSuccessful(): bool` (2xx); `json(): array<mixed>` returns `[]` when the body is not a JSON object/array.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GuardedApiClientTest extends TestCase
{
    private function client(MockHttpClient $http): GuardedApiClient
    {
        $guard = new UrlGuard(
            new StaticDnsResolver(['api.example.test' => ['93.184.216.34'], 'lan.example.test' => ['192.168.1.5']]),
            new IpValidator(),
        );

        return new GuardedApiClient($http, $guard, 'TestAgent/1.0');
    }

    public function testAPostSendsJsonPinnedToTheGuardedAddressWithoutFollowingRedirects(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen) {
            $seen = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $response = $this->client($http)->send(
            ApiRequestModel::postJson('https://api.example.test/x', ['a' => 1], ['Authorization' => 'Bearer t']),
        );

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.example.test/x', $seen['url']);
        self::assertSame('{"a":1}', $seen['options']['body']);
        self::assertSame(0, $seen['options']['max_redirects']);
        self::assertSame(['api.example.test' => '93.184.216.34'], $seen['options']['resolve']);
        self::assertContains('Authorization: Bearer t', $seen['options']['headers']);
        self::assertContains('User-Agent: TestAgent/1.0', $seen['options']['headers']);
        self::assertTrue($response->isSuccessful());
        self::assertSame(['ok' => true], $response->json());
    }

    public function testAnErrorStatusIsReturnedNotThrown(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":"AuthenticationRequired"}', ['http_code' => 401]));

        $response = $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'));

        self::assertSame(401, $response->status);
        self::assertFalse($response->isSuccessful());
        self::assertSame(['error' => 'AuthenticationRequired'], $response->json());
    }

    public function testAPrivateTargetIsRefusedBeforeAnyRequest(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('No request may leave for a private address.'));

        $this->expectException(SsrfBlockedException::class);

        $this->client($http)->send(ApiRequestModel::get('https://lan.example.test/x'));
    }

    public function testANonJsonBodyReadsAsEmpty(): void
    {
        $http = new MockHttpClient(new MockResponse('<html>', ['http_code' => 502]));

        self::assertSame([], $this->client($http)->send(ApiRequestModel::get('https://api.example.test/x'))->json());
    }
}
```

(MockHttpClient normalises `headers` into `"Name: value"` strings in `$options['headers']`; if the installed version keeps them in `normalized_headers` instead, assert on that key — read `vendor/symfony/http-client/MockHttpClient.php` before adjusting.)

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/GuardedApiClientTest.php`
Expected: FAIL, class `GuardedApiClient` not found.

- [ ] **Step 3: Implement**

`ResponseSizeLimit` gains:

```php
    /** A JSON API answer: a timeline page or a session, never a document. */
    case Api = 2;
```

`ApiRequestModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

final readonly class ApiRequestModel
{
    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $json
     */
    private function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?array $json,
    ) {
    }

    /** @param array<string, string> $headers */
    public static function get(string $url, array $headers = []): self
    {
        return new self('GET', $url, $headers, null);
    }

    /**
     * @param array<string, mixed>  $json
     * @param array<string, string> $headers
     */
    public static function postJson(string $url, array $json, array $headers = []): self
    {
        return new self('POST', $url, $headers, $json);
    }
}
```

`ApiResponseModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

final readonly class ApiResponseModel
{
    private const int FIRST_SUCCESS = 200;
    private const int LAST_SUCCESS = 299;

    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= self::FIRST_SUCCESS && $this->status <= self::LAST_SUCCESS;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
```

`GuardedApiClient`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\Support\EgressOptions;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** One JSON call to a public API, through the same SSRF guard and IP pin as a feed fetch. A redirect is an answer. */
final readonly class GuardedApiClient
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
        private string $userAgent,
    ) {
    }

    /** @throws FetchException */
    public function send(ApiRequestModel $request): ApiResponseModel
    {
        $guarded = $this->urlGuard->assertSafe($request->url);

        try {
            $response = $this->httpClient->request($request->method, $request->url, [
                'headers' => ['Accept' => 'application/json', 'User-Agent' => $this->userAgent, ...$request->headers],
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'on_progress' => static function (int $downloaded): void {
                    ResponseTooLargeException::throwIfExceeded(ResponseSizeLimit::Api, $downloaded);
                },
                ...(null === $request->json ? [] : ['json' => $request->json]),
                ...EgressOptions::pinned($guarded, 0),
            ]);

            return new ApiResponseModel($response->getStatusCode(), $response->getContent(false));
        } catch (ExceptionInterface $exception) {
            throw FetchException::from($request->url, $exception);
        }
    }
}
```

No services.yaml change: `$userAgent` is bound globally to `%outbound_user_agent%` (services.yaml:41-43).

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd backend && php bin/phpunit tests/Service/Fetch/GuardedApiClientTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Fetch backend/tests/Service/Fetch/GuardedApiClientTest.php
git commit -m "feat(#1597): a guarded JSON API client over UrlGuard"
```

---

### Task 2: SocialAccount entity, enums, repository, migration

**Files:**
- Create: `backend/src/Enum/SocialNetwork.php`, `backend/src/Enum/SocialAccountStatus.php`
- Create: `backend/src/Entity/SocialAccount.php`
- Create: `backend/src/Repository/SocialAccountRepository.php`
- Create: `backend/migrations/Version20261012120000.php`
- Test: `backend/tests/Entity/SocialAccountTest.php`, `backend/tests/Repository/SocialAccountRepositoryTest.php`

**Interfaces:**
- `enum SocialNetwork: string { case Bluesky = 'bluesky'; case Mastodon = 'mastodon'; }`
- `enum SocialAccountStatus: string { case Connected = 'connected'; case NeedsReconnect = 'needs_reconnect'; }`
- `SocialAccount::__construct(User $user, SocialNetwork $network, string $identity, string $handle, string $serviceUrl, SealedSecret $credential, string $credentialHint, \DateTimeImmutable $connectedAt)`; getters `getId()`, `requireId()` (via `PersistedId`), `getUser()`, `getNetwork()`, `getIdentity()`, `getHandle()`, `getServiceUrl()`, `getSealedCredential(): SealedSecret`, `getCredentialHint()`, `getStatus()`, `isDefault()`, `getConnectedAt()`; mutators `replaceCredential(string $handle, SealedSecret $credential, string $credentialHint, \DateTimeImmutable $connectedAt)` (status back to Connected), `markNeedsReconnect()`, `becomeDefault()`, `stopBeingDefault()`.
- `SocialAccountRepository`: `findOneForUser(User, int $accountId): ?SocialAccount`, `findAllForUser(User): list<SocialAccount>` (ordered `network ASC, id ASC`), `countForUser(User): int`, `findOneByIdentity(User, SocialNetwork, string $identity): ?SocialAccount`, `findAllOnNetwork(User, SocialNetwork): list<SocialAccount>` (ordered `id ASC`).

Columns (`social_account`): `id`, `user_id` (FK users, `ON DELETE CASCADE`), `network VARCHAR(16)`, `identity VARCHAR(255)` (Bluesky DID; Mastodon `user@host`), `handle VARCHAR(255)` (display: `@alice.bsky.social` / `@alice@mastodon.social`), `service_url VARCHAR(512)` (PDS URL / instance URL), `credential_ciphertext VARCHAR(1024)`, `credential_nonce VARCHAR(64)`, `credential_salt VARCHAR(64)`, `credential_hint VARCHAR(8)`, `credential_version INT DEFAULT 1`, `status VARCHAR(24)`, `is_default TINYINT/BOOLEAN DEFAULT 0`, `connected_at DATETIME` (naive UTC). Unique index `(user_id, network, identity)`.

- [ ] **Step 1: Write the failing entity test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use PHPUnit\Framework\TestCase;

final class SocialAccountTest extends TestCase
{
    private function account(): SocialAccount
    {
        return new SocialAccount(
            new User('social-entity@example.test'),
            SocialNetwork::Bluesky,
            'did:plc:alice',
            '@alice.bsky.social',
            'https://pds.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
    }

    public function testANewAccountIsConnectedAndNotTheDefault(): void
    {
        $account = $this->account();

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertFalse($account->isDefault());
    }

    public function testReplacingTheCredentialReconnectsAndTakesTheCurrentHandle(): void
    {
        $account = $this->account();
        $account->markNeedsReconnect();

        $account->replaceCredential(
            '@alice.example.com',
            new SealedSecret('c2', 'n2', 's2', 1),
            'qrst',
            new \DateTimeImmutable('2026-10-13 10:00:00'),
        );

        self::assertSame(SocialAccountStatus::Connected, $account->getStatus());
        self::assertSame('@alice.example.com', $account->getHandle());
        self::assertSame('c2', $account->getSealedCredential()->ciphertext);
        self::assertSame('qrst', $account->getCredentialHint());
        self::assertSame('2026-10-13 10:00:00', $account->getConnectedAt()->format('Y-m-d H:i:s'));
    }

    public function testTheDefaultFlagFollowsItsTwoMutators(): void
    {
        $account = $this->account();

        $account->becomeDefault();
        self::assertTrue($account->isDefault());

        $account->stopBeingDefault();
        self::assertFalse($account->isDefault());
    }
}
```

Read `backend/src/Entity/User.php`'s constructor first and build the user exactly as `tests/Support/UserFactory.php` does if `new User(email)` is not its signature.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Entity/SocialAccountTest.php`
Expected: FAIL, class `SocialAccount` not found.

- [ ] **Step 3: Implement the enums and the entity**

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialNetwork: string
{
    case Bluesky = 'bluesky';
    case Mastodon = 'mastodon';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum SocialAccountStatus: string
{
    case Connected = 'connected';
    case NeedsReconnect = 'needs_reconnect';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SocialAccountStatus;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A Bluesky or Mastodon account the user connected. No readable secret is stored; the hint is its last four characters. */
#[ORM\Entity(repositoryClass: SocialAccountRepository::class)]
#[ORM\Table(name: 'social_account')]
#[ORM\UniqueConstraint(name: 'uniq_social_account_identity', columns: ['user_id', 'network', 'identity'])]
final class SocialAccount
{
    use PersistedId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: SocialNetwork::class)]
    private SocialNetwork $network;

    #[ORM\Column(length: 255)]
    private string $identity;

    #[ORM\Column(length: 255)]
    private string $handle;

    #[ORM\Column(length: 512)]
    private string $serviceUrl;

    #[ORM\Column(length: 1024)]
    private string $credentialCiphertext;

    #[ORM\Column(length: 64)]
    private string $credentialNonce;

    #[ORM\Column(length: 64)]
    private string $credentialSalt;

    #[ORM\Column(length: 8)]
    private string $credentialHint;

    #[ORM\Column(options: ['default' => 1])]
    private int $credentialVersion;

    #[ORM\Column(length: 24, enumType: SocialAccountStatus::class)]
    private SocialAccountStatus $status = SocialAccountStatus::Connected;

    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $connectedAt;

    public function __construct(
        User $user,
        SocialNetwork $network,
        string $identity,
        string $handle,
        string $serviceUrl,
        SealedSecret $credential,
        string $credentialHint,
        \DateTimeImmutable $connectedAt,
    ) {
        $this->user = $user;
        $this->network = $network;
        $this->identity = $identity;
        $this->serviceUrl = $serviceUrl;
        $this->replaceCredential($handle, $credential, $credentialHint, $connectedAt);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNetwork(): SocialNetwork
    {
        return $this->network;
    }

    public function getIdentity(): string
    {
        return $this->identity;
    }

    public function getHandle(): string
    {
        return $this->handle;
    }

    public function getServiceUrl(): string
    {
        return $this->serviceUrl;
    }

    public function getSealedCredential(): SealedSecret
    {
        return new SealedSecret(
            $this->credentialCiphertext,
            $this->credentialNonce,
            $this->credentialSalt,
            $this->credentialVersion,
        );
    }

    public function getCredentialHint(): string
    {
        return $this->credentialHint;
    }

    public function getStatus(): SocialAccountStatus
    {
        return $this->status;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function getConnectedAt(): \DateTimeImmutable
    {
        return $this->connectedAt;
    }

    public function replaceCredential(
        string $handle,
        SealedSecret $credential,
        string $credentialHint,
        \DateTimeImmutable $connectedAt,
    ): void {
        $this->handle = $handle;
        $this->credentialCiphertext = $credential->ciphertext;
        $this->credentialNonce = $credential->nonce;
        $this->credentialSalt = $credential->salt;
        $this->credentialVersion = $credential->version;
        $this->credentialHint = $credentialHint;
        $this->connectedAt = $connectedAt;
        $this->status = SocialAccountStatus::Connected;
    }

    public function markNeedsReconnect(): void
    {
        $this->status = SocialAccountStatus::NeedsReconnect;
    }

    public function becomeDefault(): void
    {
        $this->isDefault = true;
    }

    public function stopBeingDefault(): void
    {
        $this->isDefault = false;
    }
}
```

`replaceCredential` has 4 parameters; if PHPMD or review objects, introduce `Service/Social/Model/…` is wrong (an entity may not import a service) — keep it, as `AiProviderSettings::replaceConnection` does with 4.

- [ ] **Step 4: Run the entity test**

Run: `cd backend && php bin/phpunit tests/Entity/SocialAccountTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the failing repository test**

Read `tests/Repository/` for an existing `KernelTestCase` repository test (e.g. one that persists users through `UserFactory`) and copy its setup. Cases:

```php
public function testFindAllForUserListsOnlyThatUsersAccountsByNetworkThenAge(): void
// persist: owner mastodon(id1), owner bluesky(id2), owner bluesky(id3), stranger bluesky
// assert identities in order: bluesky id2, bluesky id3, mastodon id1; stranger absent

public function testFindOneForUserHidesAnotherUsersAccount(): void
// owner's account id looked up with the stranger → null; with the owner → the account

public function testFindOneByIdentityMatchesNetworkAndIdentity(): void
// owner bluesky 'did:plc:a' → found for (owner, Bluesky, 'did:plc:a'); null for (owner, Mastodon, 'did:plc:a')

public function testFindAllOnNetworkAndCountForUser(): void
// owner: 2 bluesky + 1 mastodon → findAllOnNetwork(owner, Bluesky) has 2 oldest first; countForUser = 3
```

Write each body in full with a private `persistAccount(User $user, SocialNetwork $network, string $identity): SocialAccount` helper using the constructor above and `new SealedSecret('c', 'n', 's', 1)`.

- [ ] **Step 6: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Repository/SocialAccountRepositoryTest.php`
Expected: FAIL, class `SocialAccountRepository` not found.

- [ ] **Step 7: Implement the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialAccount>
 */
final class SocialAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialAccount::class);
    }

    public function findOneForUser(User $user, int $accountId): ?SocialAccount
    {
        return $this->findOneBy(['id' => $accountId, 'user' => $user]);
    }

    /** @return list<SocialAccount> */
    public function findAllForUser(User $user): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['user' => $user], ['network' => 'ASC', 'id' => 'ASC']));
    }

    public function countForUser(User $user): int
    {
        return $this->count(['user' => $user]);
    }

    public function findOneByIdentity(User $user, SocialNetwork $network, string $identity): ?SocialAccount
    {
        return $this->findOneBy(['user' => $user, 'network' => $network, 'identity' => $identity]);
    }

    /** @return list<SocialAccount> */
    public function findAllOnNetwork(User $user, SocialNetwork $network): array
    {
        // @phpstan-ignore arrayValues.list (deliberate: findBy() promises a list only in its @phpstan-return)
        return array_values($this->findBy(['user' => $user, 'network' => $network], ['id' => 'ASC']));
    }
}
```

- [ ] **Step 8: Run the repository test**

Run: `cd backend && php bin/phpunit tests/Repository/SocialAccountRepositoryTest.php`
Expected: PASS (4 tests).

- [ ] **Step 9: Write the migration from the ORM's own DDL**

Do not hand-guess the SQL. Produce each dialect's DDL from the mapping:

```bash
cd backend
php bin/console doctrine:schema:update --dump-sql --env=test | grep -i social_account
docker compose exec php bin/console doctrine:schema:update --dump-sql | grep -i social_account
```

Write `migrations/Version20261012120000.php` in the shape of `Version20261011150000.php`: `getDescription()` → `'Create social_account: connected Bluesky and Mastodon accounts (#1597).'`; `up()` skips if the table exists, calls `abortIfUnsupportedPlatform()`, and emits the MySQL statements (CREATE TABLE … `DEFAULT CHARACTER SET utf8mb4`, the FK `ALTER TABLE`) or the SQLite statements (CREATE TABLE with inline FK, `CREATE UNIQUE INDEX`, `CREATE INDEX` on user_id) exactly as dumped; `down()` skips if absent and drops the table; `isTransactional()` returns false; copy `abortIfUnsupportedPlatform()` verbatim.

- [ ] **Step 10: Verify the migration on both dialects**

```bash
cd backend
rm -f var/migrate-check.db && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:migrations:migrate --no-interaction --env=dev && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:schema:validate --env=dev; rm -f var/migrate-check.db
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:schema:validate
```

Expected: both report "The database schema is in sync with the mapping files." Applying it to the Docker dev DB is required anyway (memory: apply new migrations to the live Docker DB). Then `docker compose exec php bin/console doctrine:migrations:migrate prev --no-interaction` followed by `migrate --no-interaction` to prove `down()` runs.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Enum/SocialNetwork.php backend/src/Enum/SocialAccountStatus.php backend/src/Entity/SocialAccount.php backend/src/Repository/SocialAccountRepository.php backend/migrations/Version20261012120000.php backend/tests/Entity/SocialAccountTest.php backend/tests/Repository/SocialAccountRepositoryTest.php
git commit -m "feat(#1597): the social_account table"
```

---

### Task 3: Credentials model and credential cipher

**Files:**
- Create: `backend/src/Service/Social/Model/SocialCredentialsModel.php`
- Create: `backend/src/Service/Social/Model/VerifiedAccountModel.php`
- Create: `backend/src/Service/Social/Exception/InvalidInstanceUrlException.php`
- Create: `backend/src/Service/Social/Crypto/SocialCredentialCipher.php`
- Test: `backend/tests/Service/Social/Model/SocialCredentialsModelTest.php`, `backend/tests/Service/Social/Crypto/SocialCredentialCipherTest.php`

**Interfaces:**
- `SocialCredentialsModel::bluesky(string $handle, string $appPassword): self` — address = handle lowercased, a leading `@` and surrounding whitespace removed; a `did:` value is kept verbatim.
- `SocialCredentialsModel::mastodon(string $instanceUrl, string $accessToken): self` — address = `https://host[:port]` (scheme added when missing, lowercased host, path/query/trailing slash dropped); throws `InvalidInstanceUrlException` for a non-https scheme, a missing host, or credentials in the URL.
- `SocialCredentialsModel::forReconnect(SocialAccount $account, string $secret): self` — Bluesky: address = the stored DID (`getIdentity()`); Mastodon: address = `getServiceUrl()`.
- Public readonly `SocialNetwork $network`, `string $address`, `string $secret`; `hint(): string` = last 4 characters of the secret.
- `VerifiedAccountModel(string $identity, string $handle, string $serviceUrl)` public readonly.
- `SocialCredentialCipher::seal(int $userId, string $secret): SealedSecret`, `open(int $userId, SealedSecret $sealed): string`; purpose `'social-account-credential'`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Enum\SocialNetwork;
use App\Service\Social\Exception\InvalidInstanceUrlException;
use App\Service\Social\Model\SocialCredentialsModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SocialCredentialsModelTest extends TestCase
{
    public function testABlueskyHandleIsTrimmedLowercasedAndLosesItsAt(): void
    {
        $credentials = SocialCredentialsModel::bluesky('  @Alice.BSKY.social ', 'abcd-efgh-ijkl-mnop');

        self::assertSame(SocialNetwork::Bluesky, $credentials->network);
        self::assertSame('alice.bsky.social', $credentials->address);
        self::assertSame('mnop', $credentials->hint());
    }

    public function testABlueskyDidIsKeptVerbatim(): void
    {
        self::assertSame(
            'did:plc:AbC123',
            SocialCredentialsModel::bluesky('did:plc:AbC123', 'abcd-efgh-ijkl-mnop')->address,
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function instanceUrls(): iterable
    {
        yield 'bare host' => ['mastodon.social', 'https://mastodon.social'];
        yield 'trailing slash and path' => ['https://Mastodon.Social/@lars/', 'https://mastodon.social'];
        yield 'port kept' => ['https://social.example.test:8443', 'https://social.example.test:8443'];
    }

    #[DataProvider('instanceUrls')]
    public function testAMastodonInstanceUrlIsNormalised(string $typed, string $expected): void
    {
        self::assertSame($expected, SocialCredentialsModel::mastodon($typed, 'token-1234')->address);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedInstanceUrls(): iterable
    {
        yield 'plain http' => ['http://mastodon.social'];
        yield 'credentials' => ['https://user:pass@mastodon.social'];
        yield 'no host' => ['https://'];
    }

    #[DataProvider('refusedInstanceUrls')]
    public function testAnUnsafeInstanceUrlIsRefused(string $typed): void
    {
        $this->expectException(InvalidInstanceUrlException::class);

        SocialCredentialsModel::mastodon($typed, 'token-1234');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Crypto;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Crypto\InstanceSecretCipher;
use App\Service\Social\Crypto\SocialCredentialCipher;
use PHPUnit\Framework\TestCase;

final class SocialCredentialCipherTest extends TestCase
{
    private function cipher(): SocialCredentialCipher
    {
        return new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('k', 32)));
    }

    public function testASealedCredentialOpensForItsOwner(): void
    {
        $sealed = $this->cipher()->seal(7, 'abcd-efgh-ijkl-mnop');

        self::assertSame('abcd-efgh-ijkl-mnop', $this->cipher()->open(7, $sealed));
    }

    public function testASealedCredentialDoesNotOpenForAnotherUser(): void
    {
        $sealed = $this->cipher()->seal(7, 'abcd-efgh-ijkl-mnop');

        $this->expectException(SecretUnreadableException::class);

        $this->cipher()->open(8, $sealed);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: FAIL, classes not found.

- [ ] **Step 3: Implement**

`InvalidInstanceUrlException`: `final class InvalidInstanceUrlException extends \InvalidArgumentException {}` (namespace `App\Service\Social\Exception`).

`SocialCredentialsModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\InvalidInstanceUrlException;

final readonly class SocialCredentialsModel
{
    private const int HINT_LENGTH = 4;

    private function __construct(
        public SocialNetwork $network,
        public string $address,
        public string $secret,
    ) {
    }

    public static function bluesky(string $handle, string $appPassword): self
    {
        $address = trim($handle);
        if (!str_starts_with($address, 'did:')) {
            $address = strtolower(ltrim($address, '@'));
        }

        return new self(SocialNetwork::Bluesky, $address, $appPassword);
    }

    /** @throws InvalidInstanceUrlException */
    public static function mastodon(string $instanceUrl, string $accessToken): self
    {
        return new self(SocialNetwork::Mastodon, self::instanceOrigin($instanceUrl), $accessToken);
    }

    public static function forReconnect(SocialAccount $account, string $secret): self
    {
        $address = SocialNetwork::Bluesky === $account->getNetwork()
            ? $account->getIdentity()
            : $account->getServiceUrl();

        return new self($account->getNetwork(), $address, $secret);
    }

    public function hint(): string
    {
        return substr($this->secret, -self::HINT_LENGTH);
    }

    /** @throws InvalidInstanceUrlException */
    private static function instanceOrigin(string $instanceUrl): string
    {
        $typed = trim($instanceUrl);
        $parts = parse_url(str_contains($typed, '://') ? $typed : 'https://' . $typed);

        if (false === $parts || !isset($parts['host']) || '' === $parts['host']) {
            throw new InvalidInstanceUrlException(sprintf('"%s" names no instance.', $instanceUrl));
        }
        if ('https' !== strtolower($parts['scheme'] ?? '')) {
            throw new InvalidInstanceUrlException('An instance is reached over https only.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidInstanceUrlException('An instance URL carries no credentials.');
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return 'https://' . strtolower($parts['host']) . $port;
    }
}
```

(`parse_url('https://')` returns `false` on PHP 8.4 — covered by the first guard.)

`VerifiedAccountModel`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

final readonly class VerifiedAccountModel
{
    public function __construct(
        public string $identity,
        public string $handle,
        public string $serviceUrl,
    ) {
    }
}
```

`SocialCredentialCipher` — copy `Service/Ai/Crypto/ApiKeyCipher.php` with `PURPOSE = 'social-account-credential'`, method parameter `$secret`, and docblock `/** A connected social account's app password or access token, bound to its user. */`.

- [ ] **Step 4: Run them**

Run: `cd backend && php bin/phpunit tests/Service/Social`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Social backend/tests/Service/Social
git commit -m "feat(#1597): social credentials model and credential cipher"
```

---

### Task 4: Network verifiers (Bluesky, Mastodon) and the dispatching verifier

**Files:**
- Create: `backend/src/Service/Social/Exception/SocialCredentialsRejectedException.php`
- Create: `backend/src/Service/Social/Exception/SocialAccountUnresolvableException.php`
- Create: `backend/src/Service/Social/Exception/SocialNetworkUnreachableException.php`
- Create: `backend/src/Service/Social/Model/BlueskyIdentityModel.php`
- Create: `backend/src/Service/Social/BlueskyIdentityResolver.php`
- Create: `backend/src/Service/Social/NetworkVerifier/NetworkVerifierInterface.php`
- Create: `backend/src/Service/Social/NetworkVerifier/BlueskyVerifier.php`
- Create: `backend/src/Service/Social/NetworkVerifier/MastodonVerifier.php`
- Create: `backend/src/Service/Social/AccountVerifier/AccountVerifierInterface.php`
- Create: `backend/src/Service/Social/AccountVerifier/NetworkAccountVerifier.php`
- Test: `backend/tests/Service/Social/BlueskyIdentityResolverTest.php`, `backend/tests/Service/Social/NetworkVerifier/BlueskyVerifierTest.php`, `backend/tests/Service/Social/NetworkVerifier/MastodonVerifierTest.php`, `backend/tests/Service/Social/AccountVerifier/NetworkAccountVerifierTest.php`

**Interfaces:**
- Consumes: `GuardedApiClient::send()`, `ApiRequestModel`, `ApiResponseModel` (Task 1); `SocialCredentialsModel`, `VerifiedAccountModel` (Task 3).
- `NetworkVerifierInterface` (`#[AutoconfigureTag('app.social_network_verifier')]`): `public static function network(): string;` (the `SocialNetwork` value) and `verify(SocialCredentialsModel $credentials): VerifiedAccountModel` throwing the three exceptions below.
- `AccountVerifierInterface::verify(SocialCredentialsModel $credentials): VerifiedAccountModel` — the seam the connector depends on and controller tests stub.
- `BlueskyIdentityResolver::resolve(string $handleOrDid): BlueskyIdentityModel` (`did`, `pdsUrl`).
- Exceptions (all `final class … extends \RuntimeException`): `SocialCredentialsRejectedException` (the network refused the secret), `SocialAccountUnresolvableException` (handle/DID/instance account cannot be found), `SocialNetworkUnreachableException` (transport failure, guard refusal, or an answer that is not the expected shape; wraps the `FetchException` as `previous`).

Network facts the code relies on:
- Handle → DID: `GET https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle?handle=<h>` → `{"did": "did:plc:…"}`; HTTP 400 means unknown handle.
- DID document: `did:plc:X` → `GET https://plc.directory/did:plc:X`; `did:web:host` → `GET https://host/.well-known/did.json`. The PDS is the `service` entry whose `id` ends with `#atproto_pds`; its `serviceEndpoint` is the URL.
- Session: `POST <pds>/xrpc/com.atproto.server.createSession` with `{"identifier": <did>, "password": <app password>}` → 200 `{"did", "handle", …}`; 401 means rejected credentials. The answer's `did` must equal the resolved DID.
- Mastodon: `GET <instance>/api/v1/accounts/verify_credentials` with `Authorization: Bearer <token>` → 200 `{"id", "username", "acct", "url"}`; 401/403 means rejected token. Identity is `<username>@<instance host>`; handle is `@<username>@<instance host>`.

- [ ] **Step 1: Write the failing resolver test**

Build `GuardedApiClient` over a `MockHttpClient` whose callback answers by URL, and `UrlGuard` over `StaticDnsResolver` mapping `public.api.bsky.app`, `plc.directory`, `alice.example.com`, `pds.example.test` to `93.184.216.34`. Cases:

```php
public function testAHandleResolvesThroughPlcToItsPds(): void
// resolveHandle → {"did":"did:plc:alice"}; plc.directory/did:plc:alice →
// {"id":"did:plc:alice","service":[{"id":"#atproto_pds","type":"AtprotoPersonalDataServer","serviceEndpoint":"https://pds.example.test"}]}
// assert did 'did:plc:alice', pdsUrl 'https://pds.example.test'

public function testADidWebResolvesThroughItsWellKnownDocument(): void
// resolve('did:web:alice.example.com') → no resolveHandle call; GET https://alice.example.com/.well-known/did.json
// with service id 'did:web:alice.example.com#atproto_pds' → pdsUrl from it

public function testAnUnknownHandleIsUnresolvable(): void
// resolveHandle answers 400 {"error":"InvalidRequest"} → SocialAccountUnresolvableException

public function testADocumentWithoutAPdsIsUnresolvable(): void
// plc answers {"service":[]} → SocialAccountUnresolvableException

public function testATransportFailureIsUnreachable(): void
// MockResponse with ['error' => 'connection refused'] → SocialNetworkUnreachableException
```

Write every body in full; assert the exact URLs the mock received.

- [ ] **Step 2: Run it to verify it fails**

Run: `cd backend && php bin/phpunit tests/Service/Social/BlueskyIdentityResolverTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement the exceptions, `BlueskyIdentityModel` and `BlueskyIdentityResolver`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\BlueskyIdentityModel;

/** Finds the PDS that hosts a Bluesky account, so a self-hosted account signs in where it lives. */
final readonly class BlueskyIdentityResolver
{
    private const string RESOLVE_HANDLE = 'https://public.api.bsky.app/xrpc/com.atproto.identity.resolveHandle';
    private const string PLC_DIRECTORY = 'https://plc.directory/';
    private const string WEB_DID_PREFIX = 'did:web:';
    private const string PDS_SERVICE_SUFFIX = '#atproto_pds';

    public function __construct(private GuardedApiClient $api)
    {
    }

    /**
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function resolve(string $handleOrDid): BlueskyIdentityModel
    {
        $did = str_starts_with($handleOrDid, 'did:') ? $handleOrDid : $this->didOf($handleOrDid);

        return new BlueskyIdentityModel($did, $this->pdsOf($did));
    }

    private function didOf(string $handle): string
    {
        $answer = $this->call(ApiRequestModel::get(self::RESOLVE_HANDLE . '?handle=' . rawurlencode($handle)));
        $did = $answer->json()['did'] ?? null;

        if (!$answer->isSuccessful() || !\is_string($did)) {
            throw new SocialAccountUnresolvableException(sprintf('No Bluesky account has the handle "%s".', $handle));
        }

        return $did;
    }

    private function pdsOf(string $did): string
    {
        $answer = $this->call(ApiRequestModel::get($this->documentUrl($did)));
        $services = $answer->isSuccessful() ? $answer->json()['service'] ?? [] : [];

        foreach (\is_array($services) ? $services : [] as $service) {
            if (self::isPds($service)) {
                return $service['serviceEndpoint'];
            }
        }

        throw new SocialAccountUnresolvableException(sprintf('The account %s names no PDS.', $did));
    }

    private function documentUrl(string $did): string
    {
        return str_starts_with($did, self::WEB_DID_PREFIX)
            ? 'https://' . substr($did, \strlen(self::WEB_DID_PREFIX)) . '/.well-known/did.json'
            : self::PLC_DIRECTORY . $did;
    }

    /** @phpstan-assert-if-true array{id: string, serviceEndpoint: string} $service */
    private static function isPds(mixed $service): bool
    {
        return \is_array($service)
            && \is_string($service['id'] ?? null)
            && str_ends_with($service['id'], self::PDS_SERVICE_SUFFIX)
            && \is_string($service['serviceEndpoint'] ?? null);
    }

    private function call(ApiRequestModel $request): ApiResponseModel
    {
        try {
            return $this->api->send($request);
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException('Bluesky could not be reached.', previous: $exception);
        }
    }
}
```

A `did:web` with a path segment (`did:web:host:path`) is not supported: Bluesky accounts use bare-host did:web. Leave it out.

- [ ] **Step 4: Run the resolver test** — `php bin/phpunit tests/Service/Social/BlueskyIdentityResolverTest.php`, expected PASS.

- [ ] **Step 5: Write the failing verifier tests**

`BlueskyVerifierTest` (resolver built over the same mock as Step 1, so the full chain is exercised):

```php
public function testAnAcceptedAppPasswordVerifiesTheAccountAtItsPds(): void
// createSession at https://pds.example.test/xrpc/com.atproto.server.createSession answers 200
// {"did":"did:plc:alice","handle":"alice.bsky.social"}; assert the POST body is
// {"identifier":"did:plc:alice","password":"abcd-efgh-ijkl-mnop"} and the result is
// identity 'did:plc:alice', handle '@alice.bsky.social', serviceUrl 'https://pds.example.test'

public function testARefusedAppPasswordIsRejected(): void
// createSession 401 {"error":"AuthenticationRequired"} → SocialCredentialsRejectedException

public function testASessionForAnotherDidIsRejected(): void
// createSession 200 with did 'did:plc:mallory' → SocialCredentialsRejectedException

public function testTheNetworkKeyIsBluesky(): void
// BlueskyVerifier::network() === 'bluesky'
```

`MastodonVerifierTest` (`StaticDnsResolver` maps `mastodon.example.test`):

```php
public function testAnAcceptedTokenVerifiesTheAccount(): void
// verify_credentials 200 {"id":"1","username":"lars","acct":"lars","url":"https://mastodon.example.test/@lars"}
// assert header 'Authorization: Bearer token-1234' sent; result identity 'lars@mastodon.example.test',
// handle '@lars@mastodon.example.test', serviceUrl 'https://mastodon.example.test'

public function testARefusedTokenIsRejected(): void          // 401 → SocialCredentialsRejectedException
public function testAForbiddenTokenIsRejected(): void        // 403 → SocialCredentialsRejectedException
public function testAnUnexpectedAnswerIsUnreachable(): void  // 200 "<html>" → SocialNetworkUnreachableException
public function testTheNetworkKeyIsMastodon(): void
```

`NetworkAccountVerifierTest`: build `NetworkAccountVerifier` over a `ServiceLocator` (`new \Symfony\Component\DependencyInjection\ServiceLocator(['bluesky' => fn () => $blueskyStub, 'mastodon' => fn () => $mastodonStub])`) with two anonymous `NetworkVerifierInterface` stubs returning distinct `VerifiedAccountModel`s; assert each network's credentials reach its own stub.

- [ ] **Step 6: Run them to verify they fail** — `php bin/phpunit tests/Service/Social`, expected FAIL (classes missing).

- [ ] **Step 7: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Service\Social\Exception\SocialAccountUnresolvableException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's live credential check, keyed by its SocialNetwork value in NetworkAccountVerifier's locator. */
#[AutoconfigureTag('app.social_network_verifier')]
interface NetworkVerifierInterface
{
    public static function network(): string;

    /**
     * @throws SocialCredentialsRejectedException
     * @throws SocialAccountUnresolvableException
     * @throws SocialNetworkUnreachableException
     */
    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel;
}
```

`BlueskyVerifier`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\BlueskyIdentityResolver;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

final readonly class BlueskyVerifier implements NetworkVerifierInterface
{
    private const string CREATE_SESSION = '/xrpc/com.atproto.server.createSession';

    public function __construct(
        private BlueskyIdentityResolver $identities,
        private GuardedApiClient $api,
    ) {
    }

    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $identity = $this->identities->resolve($credentials->address);

        try {
            $answer = $this->api->send(ApiRequestModel::postJson(
                $identity->pdsUrl . self::CREATE_SESSION,
                ['identifier' => $identity->did, 'password' => $credentials->secret],
            ));
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException('The Bluesky server could not be reached.', previous: $exception);
        }

        $session = $answer->json();
        if (!$answer->isSuccessful() || ($session['did'] ?? null) !== $identity->did || !\is_string($session['handle'] ?? null)) {
            throw new SocialCredentialsRejectedException('Bluesky refused this handle and app password.');
        }

        return new VerifiedAccountModel($identity->did, '@' . $session['handle'], $identity->pdsUrl);
    }
}
```

Split the long condition into a private static `isSessionFor(array $session, string $did): bool` if `composer cs` flags the line length.

`MastodonVerifier`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\NetworkVerifier;

use App\Enum\SocialNetwork;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\GuardedApiClient;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;

final readonly class MastodonVerifier implements NetworkVerifierInterface
{
    private const string VERIFY_CREDENTIALS = '/api/v1/accounts/verify_credentials';
    private const array REFUSALS = [401, 403];

    public function __construct(private GuardedApiClient $api)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        try {
            $answer = $this->api->send(ApiRequestModel::get(
                $credentials->address . self::VERIFY_CREDENTIALS,
                ['Authorization' => 'Bearer ' . $credentials->secret],
            ));
        } catch (FetchException $exception) {
            throw new SocialNetworkUnreachableException('The Mastodon instance could not be reached.', previous: $exception);
        }

        if (\in_array($answer->status, self::REFUSALS, true)) {
            throw new SocialCredentialsRejectedException('The Mastodon instance refused this access token.');
        }

        $username = $answer->json()['username'] ?? null;
        if (!$answer->isSuccessful() || !\is_string($username)) {
            throw new SocialNetworkUnreachableException('The Mastodon instance gave no account for this token.');
        }

        $host = (string) parse_url($credentials->address, \PHP_URL_HOST);

        return new VerifiedAccountModel($username . '@' . $host, '@' . $username . '@' . $host, $credentials->address);
    }
}
```

`AccountVerifierInterface` (folder `AccountVerifier/`, docblock: `/** Checks a credential against its network before anything is stored. */`, same `@throws` as above) and `NetworkAccountVerifier`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\AccountVerifier;

use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use App\Service\Social\NetworkVerifier\NetworkVerifierInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class NetworkAccountVerifier implements AccountVerifierInterface
{
    public function __construct(
        #[AutowireLocator('app.social_network_verifier', defaultIndexMethod: 'network')]
        private ContainerInterface $verifiers,
    ) {
    }

    public function verify(SocialCredentialsModel $credentials): VerifiedAccountModel
    {
        $verifier = $this->verifiers->get($credentials->network->value);
        \assert($verifier instanceof NetworkVerifierInterface);

        return $verifier->verify($credentials);
    }
}
```

Being the interface's only implementation, Symfony aliases `AccountVerifierInterface` to it automatically; confirm with `php bin/console debug:container 'App\Service\Social\AccountVerifier\AccountVerifierInterface'`.

- [ ] **Step 8: Run all Social tests** — `php bin/phpunit tests/Service/Social`, expected PASS.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Service/Social backend/tests/Service/Social
git commit -m "feat(#1597): verify Bluesky app passwords and Mastodon tokens against their network"
```

---

### Task 5: Connector, editor, owner lookup and factory

**Files:**
- Create: `backend/src/Service/Social/Factory/SocialAccountFactory.php`
- Create: `backend/src/Service/Social/SocialAccountConnector.php`
- Create: `backend/src/Service/Social/SocialAccountEditor.php`
- Create: `backend/src/Service/Social/SocialAccountForUser.php`
- Create: `backend/src/Service/Social/Exception/SocialAccountNotFoundException.php`
- Create: `backend/src/Service/Social/Exception/TooManySocialAccountsException.php`
- Create: `backend/src/Service/Social/Exception/SocialAccountAlreadyConnectedException.php`
- Create: `backend/src/Service/Social/Exception/SocialAccountMismatchException.php`
- Test: `backend/tests/Service/Social/SocialAccountConnectorTest.php`, `backend/tests/Service/Social/SocialAccountEditorTest.php`, `backend/tests/Support/StubAccountVerifier.php`

**Interfaces:**
- Consumes: `AccountVerifierInterface`, `SocialCredentialsModel`, `VerifiedAccountModel`, `SocialCredentialCipher`, `SocialAccountRepository`, `SocialAccount`.
- `SocialAccountFactory::create(User $user, SocialCredentialsModel $credentials, VerifiedAccountModel $verified): SocialAccount` — seals the secret with `SocialCredentialCipher` (`$user->requireId()`), hint from `$credentials->hint()`, `connectedAt` from `ClockInterface::now()` converted to UTC (`->setTimezone(new \DateTimeZone('UTC'))`).
- `SocialAccountConnector::connect(User $user, SocialCredentialsModel $credentials): SocialAccount` — order: count ≥ 20 → `TooManySocialAccountsException`; `verify()`; `findOneByIdentity()` hit → `SocialAccountAlreadyConnectedException`; create; `becomeDefault()` when `findAllOnNetwork()` is empty; persist + flush.
- `SocialAccountConnector::reconnect(SocialAccount $account, string $secret): SocialAccount` — verifies `SocialCredentialsModel::forReconnect($account, $secret)`; a verified identity ≠ stored identity → `SocialAccountMismatchException`; otherwise `replaceCredential(verified handle, sealed, hint, now)`; flush.
- `SocialAccountEditor::accountsOf(User $user): list<SocialAccount>` — `findAllForUser()`; the controller's one read path (controllers take no repository, as `AiSettingsController` reads through its service).
- `SocialAccountEditor::makeDefault(SocialAccount $account): void` — every other account on the user's network `stopBeingDefault()`, this one `becomeDefault()`; flush.
- `SocialAccountEditor::disconnect(SocialAccount $account): void` — remove; if it was the default, the oldest remaining account on that network `becomeDefault()`; flush.
- `SocialAccountForUser::require(User $user, int $accountId): SocialAccount` — `SocialAccountNotFoundException` otherwise.
- `tests/Support/StubAccountVerifier` implements `AccountVerifierInterface`: constructed with `VerifiedAccountModel|\Throwable`, returns or throws it; records the last credentials in a public property `?SocialCredentialsModel $lastCredentials` — so it cannot be `readonly`; make it `final class`.

- [ ] **Step 1: Write the failing tests**

Use a `KernelTestCase` (copy setup from an existing service integration test that persists through `UserFactory`), wire `SocialAccountConnector` by hand with `StubAccountVerifier`, the container's `SocialAccountRepository`, `EntityManagerInterface`, a real `SocialAccountFactory` over `new SocialCredentialCipher(new InstanceSecretCipher(str_repeat('k', 32)))` and a `Symfony\Component\Clock\MockClock('2026-10-12 10:00:00 UTC')`. Cases, each written in full:

```php
// SocialAccountConnectorTest
testTheFirstAccountOnANetworkBecomesItsDefault
testASecondAccountOnTheSameNetworkIsNotTheDefault
testAnAccountOnTheOtherNetworkIsItsOwnNetworksDefault
testTheSecretIsStoredSealedAndOpensToWhatWasTyped        // cipher->open(userId, getSealedCredential()) === secret; ciphertext !== secret
testConnectingTheSameAccountTwiceIsRefused                // SocialAccountAlreadyConnectedException, count stays 1
testTheTwentyFirstAccountIsRefusedBeforeAnyNetworkCall   // persist 20, stub throws if called (pass a \LogicException) → TooManySocialAccountsException
testARejectedCredentialStoresNothing                     // stub throws SocialCredentialsRejectedException → same exception, count 0
testReconnectReplacesTheSecretAndClearsNeedsReconnect   // markNeedsReconnect(), reconnect with new secret → status Connected, hint updated, handle from verified
testReconnectSendsTheStoredIdentityAsTheAddress          // Bluesky account: stub->lastCredentials->address === 'did:plc:alice'
testReconnectWithAnotherAccountsCredentialIsRefused      // stub returns identity 'did:plc:mallory' → SocialAccountMismatchException, stored hint unchanged

// SocialAccountEditorTest
testMakeDefaultMovesTheFlagWithinTheNetworkOnly          // 2 bluesky + 1 mastodon default; makeDefault(bluesky#2) → bluesky#1 false, bluesky#2 true, mastodon stays true
testDisconnectingTheDefaultPromotesTheOldestRemaining    // bluesky#1 default, #2, #3 → disconnect #1 → #2 default
testDisconnectingANonDefaultLeavesTheDefault
testDisconnectingTheLastAccountOnANetworkLeavesNoDefault  // no exception, table empty for that network
```

- [ ] **Step 2: Run them to verify they fail** — `php bin/phpunit tests/Service/Social`, expected FAIL.

- [ ] **Step 3: Implement**

Exceptions: four `final class … extends \RuntimeException {}` in `Service/Social/Exception/`.

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Factory;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Model\SocialCredentialsModel;
use App\Service\Social\Model\VerifiedAccountModel;
use Psr\Clock\ClockInterface;

final readonly class SocialAccountFactory
{
    public function __construct(
        private SocialCredentialCipher $cipher,
        private ClockInterface $clock,
    ) {
    }

    public function create(User $user, SocialCredentialsModel $credentials, VerifiedAccountModel $verified): SocialAccount
    {
        return new SocialAccount(
            $user,
            $credentials->network,
            $verified->identity,
            $verified->handle,
            $verified->serviceUrl,
            $this->cipher->seal($user->requireId(), $credentials->secret),
            $credentials->hint(),
            $this->now(),
        );
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use App\Service\Social\AccountVerifier\AccountVerifierInterface;
use App\Service\Social\Crypto\SocialCredentialCipher;
use App\Service\Social\Exception\SocialAccountAlreadyConnectedException;
use App\Service\Social\Exception\SocialAccountMismatchException;
use App\Service\Social\Exception\TooManySocialAccountsException;
use App\Service\Social\Factory\SocialAccountFactory;
use App\Service\Social\Model\SocialCredentialsModel;
use Doctrine\ORM\EntityManagerInterface;

/** Every write here follows a successful live check; a failed one persists nothing. */
final readonly class SocialAccountConnector
{
    private const int MAX_ACCOUNTS = 20;

    public function __construct(
        private AccountVerifierInterface $verifier,
        private SocialAccountRepository $socialAccounts,
        private SocialAccountFactory $accountFactory,
        private SocialCredentialCipher $cipher,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function connect(User $user, SocialCredentialsModel $credentials): SocialAccount
    {
        if ($this->socialAccounts->countForUser($user) >= self::MAX_ACCOUNTS) {
            throw new TooManySocialAccountsException('This account already holds the maximum number of social accounts.');
        }

        $verified = $this->verifier->verify($credentials);
        if (null !== $this->socialAccounts->findOneByIdentity($user, $credentials->network, $verified->identity)) {
            throw new SocialAccountAlreadyConnectedException(sprintf('%s is already connected.', $verified->handle));
        }

        $account = $this->accountFactory->create($user, $credentials, $verified);
        if ([] === $this->socialAccounts->findAllOnNetwork($user, $credentials->network)) {
            $account->becomeDefault();
        }

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    public function reconnect(SocialAccount $account, string $secret): SocialAccount
    {
        $credentials = SocialCredentialsModel::forReconnect($account, $secret);
        $verified = $this->verifier->verify($credentials);
        if ($verified->identity !== $account->getIdentity()) {
            throw new SocialAccountMismatchException(sprintf('This credential belongs to %s, not %s.', $verified->handle, $account->getHandle()));
        }

        $account->replaceCredential(
            $verified->handle,
            $this->cipher->seal($account->getUser()->requireId(), $secret),
            $credentials->hint(),
            $this->accountFactory->now(),
        );
        $this->entityManager->flush();

        return $account;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Repository\SocialAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/** One default per network and user: the account a like from an RSS post goes out as (#1600). */
final readonly class SocialAccountEditor
{
    public function __construct(
        private SocialAccountRepository $socialAccounts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<SocialAccount> */
    public function accountsOf(User $user): array
    {
        return $this->socialAccounts->findAllForUser($user);
    }

    public function makeDefault(SocialAccount $account): void
    {
        foreach ($this->siblingsOf($account) as $sibling) {
            $sibling->stopBeingDefault();
        }
        $account->becomeDefault();
        $this->entityManager->flush();
    }

    public function disconnect(SocialAccount $account): void
    {
        $successor = $account->isDefault() ? ($this->siblingsOf($account)[0] ?? null) : null;

        $this->entityManager->remove($account);
        $successor?->becomeDefault();
        $this->entityManager->flush();
    }

    /** @return list<SocialAccount> the user's other accounts on the same network, oldest first */
    private function siblingsOf(SocialAccount $account): array
    {
        $onNetwork = $this->socialAccounts->findAllOnNetwork($account->getUser(), $account->getNetwork());

        return array_values(array_filter($onNetwork, static fn (SocialAccount $sibling): bool => $sibling !== $account));
    }
}
```

`SocialAccountForUser` — copy `Service/Ai/AiConfigurationForUser.php` with `SocialAccountRepository $socialAccounts` and `SocialAccountNotFoundException(sprintf('No social account %d for this account.', $accountId))`.

`now()` on a factory is not "build"; if `ServiceRoleRule` or review objects, inject `ClockInterface` into the connector instead and drop `now()` from the factory's public surface.

- [ ] **Step 4: Run them** — `php bin/phpunit tests/Service/Social`, expected PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Social backend/tests/Service/Social backend/tests/Support/StubAccountVerifier.php
git commit -m "feat(#1597): connect, reconnect, default and disconnect social accounts"
```

---

### Task 6: HTTP surface — controller, DTOs, JSON mapper, problems, rate limit

**Files:**
- Create: `backend/src/Controller/Api/SocialAccountController.php`
- Create: `backend/src/Dto/Social/ConnectBlueskyRequest.php`, `ConnectMastodonRequest.php`, `ReplaceCredentialRequest.php`
- Create: `backend/src/Http/SocialAccountJson.php`
- Create: `backend/src/Http/Problem/ExceptionProblems/SocialProblems.php`
- Modify: `backend/config/packages/rate_limiter.yaml` (add `social_account`)
- Test: `backend/tests/Controller/Api/SocialAccountControllerTest.php`

**Interfaces:**
- Consumes: Task 5 services, `StubAccountVerifier`.
- Routes (prefix `/api/me/social-accounts`, all need a bearer token):
  - `GET ''` → 200 `{"accounts": [Account…]}`
  - `POST '/bluesky'` body `{"handle", "appPassword"}` → 201 Account
  - `POST '/mastodon'` body `{"instanceUrl", "accessToken"}` → 201 Account
  - `PUT '/{id}/credential'` body `{"secret"}` → 200 Account
  - `PUT '/{id}/default'` → 200 `{"accounts": [...]}` (the whole list: two rows change)
  - `DELETE '/{id}'` → 200 `{"accounts": [...]}` (a successor may have become default)
- Account JSON: `{"id": int, "network": "bluesky"|"mastodon", "handle": string, "serviceUrl": string, "status": "connected"|"needs_reconnect", "isDefault": bool, "credentialHint": string, "connectedAt": ATOM string}` — `connectedAt` is formatted exactly as `PasskeyJson` formats `createdAt` (`->format(\DateTimeInterface::ATOM)`).
- Problems (`type`, title, status):
  - `SocialAccountNotFoundException` → `social_account_not_found`, 'Social account not found', 404
  - `TooManySocialAccountsException` → `social_account_limit`, 'Too many social accounts', 409
  - `SocialAccountAlreadyConnectedException` → `social_account_already_connected`, 'Account already connected', 409, detail = message
  - `SocialCredentialsRejectedException`, `SocialAccountMismatchException` → `social_credentials_rejected`, 'The network refused these credentials', 422, detail = message
  - `SocialAccountUnresolvableException`, `InvalidInstanceUrlException` → `social_account_unresolvable`, 'Account not found on the network', 422, detail = message
  - `SocialNetworkUnreachableException` → `social_network_unreachable`, 'The network could not be reached', 502, detail = message
- Rate limiter `social_account`: `sliding_window`, `limit: 10`, `interval: '15 minutes'`, `cache_pool: cache.rate_limiter`; injected as `RateLimiterFactoryInterface $socialAccountLimiter`; enforced on both POSTs and the credential PUT, after the `{id}` lookup.

- [ ] **Step 1: Write the failing controller test**

Copy the scaffolding of `tests/Controller/Api/AiSettingsControllerTest.php` and `tests/Support/AiConfigurationRequests.php` (client with `disableReboot()`, `self::getContainer()->set(AccountVerifierInterface::class, $stub)`, `accountOn()`, `payload()`, rate-limiter reset in `setUp()` — read how `resetTheProviderBudget()` works and do the same for `social_account`). Cases, each in full:

```php
testTheListRefusesAnAnonymousCaller                          // 401
testAnAccountWithoutConnectionsListsNothing                   // {"accounts": []}
testConnectingBlueskyReturnsTheAccountWithoutTheSecret       // 201; exact JSON keys; credentialHint 'mnop'; isDefault true;
                                                              // response body does not contain 'abcd-efgh-ijkl-mnop'
testTheBlueskyHandleReachesTheVerifierNormalised              // handle '@Alice.bsky.social' → stub->lastCredentials->address 'alice.bsky.social'
testAMainPasswordIsRefusedBeforeAnyNetworkCall                // appPassword 'hunter2hunter2' → 422, stub never called
testConnectingMastodonNormalisesTheInstance                   // instanceUrl 'mastodon.example.test/' → address 'https://mastodon.example.test'
testAPlainHttpInstanceIsRefused                               // 422 type social_account_unresolvable
testRejectedCredentialsAnswer422                              // stub throws SocialCredentialsRejectedException → 422 social_credentials_rejected
testAnUnreachableNetworkAnswers502                            // stub throws SocialNetworkUnreachableException → 502
testConnectingTheSameAccountTwiceAnswers409
testReconnectReplacesTheHint                                  // PUT credential → 200, credentialHint changed, status 'connected'
testAnotherUsersAccountAnswers404OnEveryIdRoute               // credential, default, DELETE
testMakeDefaultReturnsTheListWithTheFlagMoved
testDisconnectReturnsTheListWithoutTheAccount
testTheConnectBudgetIsTenPerQuarterHour                       // 10 connects (stub rejecting) then the 11th → 429
```

- [ ] **Step 2: Run it to verify it fails** — `php bin/phpunit tests/Controller/Api/SocialAccountControllerTest.php`, expected FAIL (404 routes).

- [ ] **Step 3: Implement**

DTOs:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Social;

use App\Service\Social\Model\SocialCredentialsModel;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ConnectBlueskyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $handle,
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/', message: 'Use an app password, not your account password.')]
        public string $appPassword,
    ) {
    }

    public function toCredentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::bluesky($this->handle, $this->appPassword);
    }
}
```

```php
final readonly class ConnectMastodonRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $instanceUrl,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $accessToken,
    ) {
    }

    /** @throws InvalidInstanceUrlException */
    public function toCredentials(): SocialCredentialsModel
    {
        return SocialCredentialsModel::mastodon($this->instanceUrl, $this->accessToken);
    }
}
```

```php
final readonly class ReplaceCredentialRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $secret,
    ) {
    }
}
```

(A reconnect of a Bluesky account is not regex-checked here; `createSession` decides. Accept that.)

`SocialAccountJson`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SocialAccount;

final readonly class SocialAccountJson
{
    /**
     * @param list<SocialAccount> $accounts
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function list(array $accounts): array
    {
        return ['accounts' => array_map($this->account(...), $accounts)];
    }

    /** @return array<string, mixed> */
    public function account(SocialAccount $account): array
    {
        return [
            'id' => $account->requireId(),
            'network' => $account->getNetwork()->value,
            'handle' => $account->getHandle(),
            'serviceUrl' => $account->getServiceUrl(),
            'status' => $account->getStatus()->value,
            'isDefault' => $account->isDefault(),
            'credentialHint' => $account->getCredentialHint(),
            'connectedAt' => $account->getConnectedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
```

(Match `PasskeyJson`'s date handling if it differs from the line above.)

Controller:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Social\ConnectBlueskyRequest;
use App\Dto\Social\ConnectMastodonRequest;
use App\Dto\Social\ReplaceCredentialRequest;
use App\Entity\User;
use App\Http\SocialAccountJson;
use App\Service\RateLimit\RateLimitGuard;
use App\Service\Social\SocialAccountConnector;
use App\Service\Social\SocialAccountEditor;
use App\Service\Social\SocialAccountForUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Every `{id}` resolves through SocialAccountForUser: another user's account answers 404. */
#[Route('/api/me/social-accounts')]
final readonly class SocialAccountController
{
    public function __construct(
        private SocialAccountConnector $connector,
        private SocialAccountEditor $editor,
        private SocialAccountForUser $socialAccount,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $socialAccountLimiter,
        private SocialAccountJson $accountJson,
    ) {
    }

    #[Route('', name: 'api_me_social_accounts_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse($this->accountJson->list($this->editor->accountsOf($user)));
    }

    #[Route('/bluesky', name: 'api_me_social_accounts_connect_bluesky', methods: ['POST'])]
    public function connectBluesky(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ConnectBlueskyRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialAccountLimiter, $user);
        $account = $this->connector->connect($user, $request->toCredentials());

        return new JsonResponse($this->accountJson->account($account), Response::HTTP_CREATED);
    }

    #[Route('/mastodon', name: 'api_me_social_accounts_connect_mastodon', methods: ['POST'])]
    public function connectMastodon(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ConnectMastodonRequest $request,
    ): JsonResponse {
        $this->rateLimitGuard->enforceForUser($this->socialAccountLimiter, $user);
        $account = $this->connector->connect($user, $request->toCredentials());

        return new JsonResponse($this->accountJson->account($account), Response::HTTP_CREATED);
    }

    #[Route('/{id}/credential', name: 'api_me_social_accounts_credential', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function replaceCredential(
        #[CurrentUser] User $user,
        int $id,
        #[MapRequestPayload] ReplaceCredentialRequest $request,
    ): JsonResponse {
        $account = $this->socialAccount->require($user, $id);
        $this->rateLimitGuard->enforceForUser($this->socialAccountLimiter, $user);

        return new JsonResponse($this->accountJson->account($this->connector->reconnect($account, $request->secret)));
    }

    #[Route('/{id}/default', name: 'api_me_social_accounts_default', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function makeDefault(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->editor->makeDefault($this->socialAccount->require($user, $id));

        return $this->list($user);
    }

    #[Route('/{id}', name: 'api_me_social_accounts_disconnect', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function disconnect(#[CurrentUser] User $user, int $id): JsonResponse
    {
        $this->editor->disconnect($this->socialAccount->require($user, $id));

        return $this->list($user);
    }
}
```

`InvalidInstanceUrlException` thrown from `toCredentials()` inside the action reaches `SocialProblems` like any other.

`SocialProblems` — same shape as `AiProblems` (`implements ExceptionProblemsInterface`, a `match (true)` with the arms listed above, `$exception->getMessage()` as detail where noted; group the two-class arms with private static `is…` helpers as `AiProblems::isProviderRefusal` does).

`rate_limiter.yaml`, next to `ai_provider`:

```yaml
        # Each connect or reconnect is a live login against a third-party network.
        social_account:
            policy: 'sliding_window'
            limit: 10
            interval: '15 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 4: Run it** — `php bin/phpunit tests/Controller/Api/SocialAccountControllerTest.php`, expected PASS.

- [ ] **Step 5: Run the backend gates**

```bash
cd backend && composer cs && bin/console cache:warmup && composer stan && composer md && composer tramp && php bin/phpunit
```

Expected: all clean, suite green. Then PhpStorm inspections on every changed PHP file (`mcp__phpstorm__lint_files`); fix ERROR and WARNING.

- [ ] **Step 6: Commit**

```bash
git add backend/src backend/config/packages/rate_limiter.yaml backend/tests
git commit -m "feat(#1597): the social accounts API under /api/me/social-accounts"
```

---

### Task 7: Settings → Social Media page

**Files:**
- Create: `frontend/src/app/settings/social/social-accounts.service.ts` (+ `.spec.ts`)
- Create: `frontend/src/app/settings/social/social-section.component.ts`, `.html`, `.scss` (+ `.spec.ts`)
- Modify: `frontend/src/app/settings/settings-sections.ts` (entry after `ai`)
- Modify: `frontend/src/app/settings/settings.routes.ts` (route after `ai`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.social.*`)

**Interfaces:**
- Consumes: the API of Task 6.
- `SocialAccountsService` (component-provided, like `ProxySettingsService`): signals `accounts: Signal<readonly SocialAccount[] | null>`, `failure: Signal<string | null>`, `busy: Signal<boolean>`; methods `load()`, `connectBluesky(handle, appPassword): Observable<SocialAccount>`, `connectMastodon(instanceUrl, accessToken): Observable<SocialAccount>`, `reconnect(id, secret): Observable<SocialAccount>`, `makeDefault(id)`, `disconnect(id)`. Every write updates `accounts` from its answer (the account upserted, or the returned list); errors set `failure` from `parseProblem(error).detail`.
- `SocialAccount` interface mirrors the Account JSON of Task 6 exactly.

Before writing markup, read `docs/design-language.md` (tokens, settings-group/row conventions, overlay rules) and `settings/admin/proxy/proxy-section.component.html` for the settings-group/row/password-input/save idiom.

Page content:
- One `app-settings-group` per network ("Bluesky", "Mastodon"), each listing its accounts as rows: handle, a "Default" marker or a "Make default" button, status (`needs_reconnect` shows a warning chip "Needs reconnecting" and an inline secret field + "Reconnect"), "Disconnect" (through `ConfirmService.confirmThen` with `danger: true`).
- Under each group a connect form: Bluesky — handle + app password (`app-password-input`), hint text "Create an app password in Bluesky under Settings → Privacy and security → App passwords."; Mastodon — instance URL + access token, hint "Create a token under Preferences → Development → New application with the scopes read and write:favourites." (long hints go in the description, not the placeholder — memory). The plaintext secret is a local signal cleared on success; it never enters the service.
- Errors render through `app-error-banner`; success toasts `settings.social.connected`.

- [ ] **Step 1: Write the failing service spec**

Copy the `HttpTestingController` setup from `settings/admin/proxy/proxy-settings.service.spec.ts`. Cases: `load()` GETs `/api/me/social-accounts` and sets `accounts`; `connectBluesky` POSTs `{handle, appPassword}` to `/bluesky` and appends the answer; `reconnect` PUTs `{secret}` to `/{id}/credential` and replaces that row; `makeDefault` PUTs `/{id}/default` and replaces the list; `disconnect` DELETEs and replaces the list; a 422 problem sets `failure` to its `detail`.

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npm test -- src/app/settings/social/social-accounts.service.spec.ts`
Expected: FAIL, module not found.

- [ ] **Step 3: Implement the service**

```ts
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { parseProblem } from '../../core/problem';

export type SocialNetwork = 'bluesky' | 'mastodon';
export type SocialAccountStatus = 'connected' | 'needs_reconnect';

export interface SocialAccount {
  readonly id: number;
  readonly network: SocialNetwork;
  readonly handle: string;
  readonly serviceUrl: string;
  readonly status: SocialAccountStatus;
  readonly isDefault: boolean;
  readonly credentialHint: string;
  readonly connectedAt: string;
}

interface SocialAccountList {
  readonly accounts: SocialAccount[];
}

@Injectable()
export class SocialAccountsService {
  private readonly http = inject(HttpClient);
  private readonly endpoint = `${inject(API_BASE_URL)}/api/me/social-accounts`;

  readonly accounts = signal<readonly SocialAccount[] | null>(null);
  readonly failure = signal<string | null>(null);
  readonly busy = signal(false);

  load(): void {
    this.http.get<SocialAccountList>(this.endpoint).subscribe({
      next: (list) => this.accounts.set(list.accounts),
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  connectBluesky(handle: string, appPassword: string): Observable<SocialAccount> {
    return this.upserting(this.http.post<SocialAccount>(`${this.endpoint}/bluesky`, { handle, appPassword }));
  }

  connectMastodon(instanceUrl: string, accessToken: string): Observable<SocialAccount> {
    return this.upserting(
      this.http.post<SocialAccount>(`${this.endpoint}/mastodon`, { instanceUrl, accessToken }),
    );
  }

  reconnect(id: number, secret: string): Observable<SocialAccount> {
    return this.upserting(this.http.put<SocialAccount>(`${this.endpoint}/${id}/credential`, { secret }));
  }

  makeDefault(id: number): void {
    this.replacingList(this.http.put<SocialAccountList>(`${this.endpoint}/${id}/default`, {}));
  }

  disconnect(id: number): void {
    this.replacingList(this.http.delete<SocialAccountList>(`${this.endpoint}/${id}`));
  }

  private upserting(request: Observable<SocialAccount>): Observable<SocialAccount> {
    this.begin();
    return request.pipe(
      tap({
        next: (account) => {
          this.busy.set(false);
          this.accounts.update((current) => {
            const others = (current ?? []).filter((candidate) => candidate.id !== account.id);
            return current?.some((candidate) => candidate.id === account.id)
              ? (current ?? []).map((candidate) => (candidate.id === account.id ? account : candidate))
              : [...others, account];
          });
        },
        error: (error: HttpErrorResponse) => this.fail(error),
      }),
    );
  }

  private replacingList(request: Observable<SocialAccountList>): void {
    this.begin();
    request.subscribe({
      next: (list) => {
        this.busy.set(false);
        this.accounts.set(list.accounts);
      },
      error: (error: HttpErrorResponse) => this.fail(error),
    });
  }

  private begin(): void {
    this.busy.set(true);
    this.failure.set(null);
  }

  private fail(error: HttpErrorResponse): void {
    this.busy.set(false);
    this.failure.set(parseProblem(error).detail ?? null);
  }
}
```

(Prettier will rewrap; run it. Simplify the upsert to one `map`/append if Prettier makes it unreadable.)

- [ ] **Step 4: Run the service spec** — expected PASS.

- [ ] **Step 5: Write the failing component spec**

Copy the TestBed setup of `settings/admin/proxy/proxy-section.component.spec.ts` (Transloco testing module, `provideHttpClientTesting`). Cases: renders a Bluesky and a Mastodon group with their accounts; the default account shows the default marker and no "Make default" button; a `needs_reconnect` row shows the warning and a reconnect field; submitting the Bluesky form calls the API with the typed values and clears the password field on success; a 422 shows the banner with the problem detail; "Disconnect" asks for confirmation (stub `ConfirmService.confirmThen` to run the action) then DELETEs.

- [ ] **Step 6: Run it to verify it fails**, then implement the component (`ChangeDetectionStrategy.OnPush`, `providers: [SocialAccountsService]`, `styleUrl`, shared components: `SettingsStackComponent`, `SettingsGroupComponent`, `SettingsRowComponent`, `PasswordInputComponent`, `ButtonComponent`, `ErrorBannerComponent`, `TranslocoPipe`), the section entry

```ts
  { path: 'social', icon: 'forum', labelKey: 'settings.social.title', group: 'general' },
```

and the route

```ts
      {
        path: 'social',
        title: sectionLabelKey('social'),
        loadComponent: () =>
          import('./social/social-section.component').then(
            (module) => module.SocialSectionComponent,
          ),
      },
```

i18n keys (`en` / `de`): `settings.social.title` "Social Media" / "Soziale Medien"; `bluesky` "Bluesky"; `mastodon` "Mastodon"; `handle` "Handle"; `appPassword` "App password" / "App-Passwort"; `appPasswordHint` (text above); `instanceUrl` "Instance" / "Instanz"; `accessToken` "Access token" / "Zugriffstoken"; `accessTokenHint` (text above, German: "Lege unter Einstellungen → Entwicklung → Neue Anwendung ein Token mit den Berechtigungen read und write:favourites an."); `connect` "Connect" / "Verbinden"; `connected` "Account connected" / "Konto verbunden"; `default` "Default" / "Standard"; `makeDefault` "Make default" / "Als Standard"; `needsReconnect` "Needs reconnecting" / "Neu verbinden nötig"; `reconnect` "Reconnect" / "Neu verbinden"; `disconnect` "Disconnect" / "Trennen"; `disconnectTitle` "Disconnect {{handle}}?" / "{{handle}} trennen?"; `disconnectMessage` "The stored credential is deleted. Feeds you imported stay." / "Die gespeicherten Zugangsdaten werden gelöscht. Importierte Feeds bleiben."; `empty` "No account connected." / "Kein Konto verbunden."; German app-password hint: "Lege in Bluesky unter Einstellungen → Datenschutz und Sicherheit → App-Passwörter ein App-Passwort an."

Run `settings-sections.spec.ts` and `settings.routes.spec.ts` too — they likely enumerate sections and need the new entry.

- [ ] **Step 7: Run the frontend gate**

```bash
docker compose exec -T frontend npm test -- src/app/settings
docker compose exec -T frontend npm run check
```

Expected: green.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#1597): Settings → Social Media"
```

---

### Task 8: Docs, real-network check, PR

**Files:**
- Modify: `docs/security.md` ("Stored secrets": add "a user's Bluesky app password or Mastodon access token" to the list; add a short "Social accounts" subsection: credentials not OAuth because some installs are unreachable from outside; every call passes `UrlGuard`; the app password regex refuses a main password; the token needs only `read` and `write:favourites`)
- Modify: `docs/architecture.md` §6 checklist note only if it lists endpoints — read it first; leave it untouched otherwise

- [ ] **Step 1: Write the docs** as described above.

- [ ] **Step 2: Real-network check in the Docker dev stack** (memory: verify against a real run). Confirm the containers are current first (`verify-containers-are-current`), apply the migration, then in the browser at the dev frontend connect a real Bluesky account with a fresh app password and a real Mastodon token Lars provides; confirm list, make-default, reconnect with the same secret, disconnect. Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. If Lars has not supplied credentials, report this step as not done rather than skipping silently.

- [ ] **Step 3: Full gates on both legs**

```bash
cd backend && composer check && composer md && php bin/phpunit
docker compose exec php composer test
cd backend && composer infection:diff
```

- [ ] **Step 4: Commit and open the PR** against `develop`, body `Closes #1597` and `Part of #1596`, summarising the decisions (guarded client, app-password regex, default promotion on disconnect, what is deferred to #1598–#1600).
