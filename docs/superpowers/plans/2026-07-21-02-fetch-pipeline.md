# Fetch Pipeline Implementation Plan (Plan 2 of 6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Everything needed to fetch, parse, sanitize, and store feed content on a schedule: SSRF-guarded HTTP fetcher, RSS 2.0/Atom/RSS 1.0 parsers, HTML sanitizer, adaptive scheduler, retention pruning, the budgeted lock-guarded `RefreshRunner`, a CLI command, and the token-protected `/maintenance/refresh` endpoint.

**Architecture:** `RefreshRunner` orchestrates: `FeedRepository::findDue` → `FeedFetcherInterface` (conditional GET, SSRF guard) → `FeedParser` (format detection → 3 parsers → `ParsedFeed` DTO) → `EntryIngestor` (dedupe by guidHash, sanitize, persist) → `FeedScheduler` (adaptive interval + backoff) → flush per feed. A Symfony Lock (doctrine store) serializes runs; a time budget makes every caller resumable. `EntryPruner` deletes old entries not marked favorite/kept.

**Tech Stack:** Symfony 7.3, PHP 8.3, symfony/http-client, symfony/clock, symfony/lock, symfony/html-sanitizer, symfony/monolog-bundle, Doctrine ORM 3, PHPUnit 12.

---

## Context for implementers

- **Work in `backend/` inside the worktree.** All commands below assume `cd <worktree>/backend`.
- **Quality gates before every commit:** `composer check` (PHPCS PSR-12 + PHPStan max). If PHPStan fails with a missing container XML after config changes, run `php bin/console cache:warmup` first. Check exit codes explicitly — do not pipe through `tail`/`head` (zsh masks exit codes).
- **Tests:** `php vendor/bin/phpunit`. Schema is built once per process by `tests/bootstrap.php`; DAMA wraps each test in a rolled-back transaction. `App\Tests\DbTestCase` boots the kernel and exposes `protected EntityManagerInterface $em`.
- **Existing APIs you will use** (do not re-read the entities unless needed):
  - `new Feed(string $url)`; getters/setters for `url`, `siteUrl`, `title`, `description`, `status` (`FeedStatus::Active|Erroring|Gone`), `lastFetchedAt`, `nextFetchAt`, `fetchIntervalMinutes` (default 60), `consecutiveFailures`, `lastErrorMessage`, `etag`, `lastModified` (all nullable except url/status/interval/failures).
  - `new Entry(Feed $feed, string $guid, ?string $url, string $title, \DateTimeImmutable $createdAt)` — computes `guidHash = sha256(guid)` itself; setters for `author`, `summary`, `contentHtml`, `publishedAt`. Column limits: title 1024, author 255, url 2048.
  - `new User(string $email, \DateTimeImmutable $createdAt)`; `new Subscription(User $user, Feed $feed, \DateTimeImmutable $createdAt)`; `new EntryState(User $user, Entry $entry)` with `setIsRead/setIsFavorite/setIsKept` (bool properties `isRead`, `isFavorite`, `isKept`).
- **Clock discipline:** no `new \DateTimeImmutable('now')` in services — inject `Symfony\Component\Clock\ClockInterface`. Tests use `Symfony\Component\Clock\MockClock`.
- The maintenance endpoint uses a plain controller token check (`hash_equals`) — security-bundle is not installed yet; JWT/security arrives in Plan 3.

## File map

```
backend/src/
├── Command/RefreshFeedsCommand.php
├── Controller/MaintenanceController.php
├── Repository/FeedRepository.php         (add findDue/countDue)
├── Repository/EntryRepository.php        (add findExistingGuidHashes)
└── Service/
    ├── EntryIngestor.php
    ├── EntryPruner.php
    ├── EntrySanitizer.php
    ├── FeedScheduler.php
    ├── Fetch/
    │   ├── DnsResolverInterface.php  NativeDnsResolver.php
    │   ├── FeedFetcherInterface.php  HttpFeedFetcher.php
    │   ├── FetchResponse.php  GuardedUrl.php  UrlGuard.php  UrlResolver.php  IpValidator.php
    │   └── Exception/{FetchException,SsrfBlockedException,FeedUnreachableException,FeedGoneException,ResponseTooLargeException}.php
    ├── Parser/
    │   ├── FeedParser.php  Rss2Parser.php  AtomParser.php  Rss1Parser.php
    │   ├── ParsedFeed.php  ParsedEntry.php
    │   ├── DateParser.php  GuidFallback.php  XmlHelper.php
    │   └── Exception/FeedParseException.php
    └── Refresh/
        ├── RefreshRunner.php  RefreshRequest.php  RefreshReport.php  FeedOutcome.php
backend/tests/
├── Support/StubFeedFetcher.php
├── Fixtures/feeds/{rss2-basic,rss2-no-guid,atom-basic,rss1-basic}.xml
├── Service/{EntrySanitizerTest,FeedSchedulerTest,EntryIngestorTest,EntryPrunerTest}.php
├── Service/Fetch/{IpValidatorTest,UrlGuardTest,HttpFeedFetcherTest}.php
├── Service/Parser/FeedParserTest.php
├── Service/Refresh/RefreshRunnerTest.php
├── Repository/FeedRepositoryTest.php
├── Command/RefreshFeedsCommandTest.php
└── Controller/MaintenanceControllerTest.php
```

---

### Task 1: Install packages and configuration

**Files:**
- Modify: `backend/composer.json` / `backend/composer.lock` (via composer)
- Modify: `backend/.env`, `backend/.env.test`
- Modify: `backend/config/packages/monolog.yaml` (created by recipe)
- Modify: `backend/config/packages/lock.yaml` (created by recipe)

- [ ] **Step 1: Install packages**

```bash
composer require symfony/http-client symfony/clock symfony/lock symfony/html-sanitizer symfony/monolog-bundle
```

Expected: recipes add `config/packages/lock.yaml`, `config/packages/monolog.yaml`, and a `LOCK_DSN` line to `.env`.

- [ ] **Step 2: Point the lock store at Doctrine**

Delete the `LOCK_DSN` block the recipe wrote to `backend/.env` — it is not used.
`config/packages/lock.yaml` must read:

```yaml
framework:
    lock: 'doctrine.dbal.default_connection'
```

This is a **service reference**, not a DSN. `symfony/lock` has no `doctrine://`
scheme (see `StoreFactory::createStore()`), and FrameworkBundle only converts a
lock config value into a service `Reference` when the value contains no colon
**and** no env placeholder was used (`FrameworkExtension.php`, lock section) —
so a `%env(LOCK_DSN)%` value can never become a Doctrine store. Referencing the
connection service directly yields a `DoctrineDbalStore` on the app's own
connection.

The Doctrine bundle registers `LockStoreSchemaListener`, which adds the
`lock_keys` table to `doctrine:schema:create`, so the test bootstrap keeps
working unchanged (verified: `lock_keys` is created in the test schema).

- [ ] **Step 3: Production logging = rotating file, 7 days**

Replace the `when@prod` block in `config/packages/monolog.yaml` with:

```yaml
when@prod:
    monolog:
        handlers:
            main:
                type: rotating_file
                path: '%kernel.logs_dir%/%kernel.environment%.log'
                level: info
                max_files: 7
```

(Leave the `when@dev` and `when@test` blocks as the recipe wrote them.)

- [ ] **Step 4: Maintenance token env plumbing**

Append to `backend/.env`:

```dotenv
###> app/maintenance ###
MAINTENANCE_TOKEN=''
###< app/maintenance ###
```

Append to `backend/.env.test`:

```dotenv
MAINTENANCE_TOKEN='test-maintenance-token'
```

- [ ] **Step 5: Verify gates and tests**

```bash
php bin/console cache:warmup
composer check
php vendor/bin/phpunit
```

Expected: all green (19 tests still passing).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock symfony.lock .env .env.test config/
git commit -m "Add http-client, clock, lock, html-sanitizer, monolog"
```

---

### Task 2: Fetch contract — DTOs, exceptions, interface

**Files:**
- Create: `backend/src/Service/Fetch/Exception/FetchException.php`
- Create: `backend/src/Service/Fetch/Exception/SsrfBlockedException.php`
- Create: `backend/src/Service/Fetch/Exception/FeedUnreachableException.php`
- Create: `backend/src/Service/Fetch/Exception/FeedGoneException.php`
- Create: `backend/src/Service/Fetch/Exception/ResponseTooLargeException.php`
- Create: `backend/src/Service/Fetch/FetchResponse.php`
- Create: `backend/src/Service/Fetch/FeedFetcherInterface.php`
- Test: `backend/tests/Service/Fetch/FetchResponseTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\FetchResponse;
use PHPUnit\Framework\TestCase;

final class FetchResponseTest extends TestCase
{
    public function testFetchedCarriesBodyAndCachingHeaders(): void
    {
        $response = FetchResponse::fetched('https://example.com/feed', false, '<rss/>', '"abc"', 'Mon, 20 Jul 2026 08:30:00 GMT');

        self::assertFalse($response->notModified);
        self::assertSame('https://example.com/feed', $response->finalUrl);
        self::assertFalse($response->permanentRedirect);
        self::assertSame('<rss/>', $response->body);
        self::assertSame('"abc"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
    }

    public function testNotModifiedHasNoBody(): void
    {
        $response = FetchResponse::notModified('https://example.com/feed', true, '"abc"', null);

        self::assertTrue($response->notModified);
        self::assertTrue($response->permanentRedirect);
        self::assertNull($response->body);
        self::assertSame('"abc"', $response->etag);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Service/Fetch/FetchResponseTest.php`
Expected: FAIL — class `FetchResponse` not found.

- [ ] **Step 3: Implement**

`src/Service/Fetch/Exception/FetchException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

abstract class FetchException extends \RuntimeException
{
}
```

`SsrfBlockedException.php`, `FeedUnreachableException.php`, `FeedGoneException.php`, `ResponseTooLargeException.php` — identical shape, e.g.:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Exception;

final class SsrfBlockedException extends FetchException
{
}
```

(repeat for the other three class names).

`src/Service/Fetch/FetchResponse.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

final readonly class FetchResponse
{
    private function __construct(
        public bool $notModified,
        public string $finalUrl,
        public bool $permanentRedirect,
        public ?string $body,
        public ?string $etag,
        public ?string $lastModified,
    ) {
    }

    public static function fetched(
        string $finalUrl,
        bool $permanentRedirect,
        string $body,
        ?string $etag,
        ?string $lastModified,
    ): self {
        return new self(false, $finalUrl, $permanentRedirect, $body, $etag, $lastModified);
    }

    public static function notModified(
        string $finalUrl,
        bool $permanentRedirect,
        ?string $etag,
        ?string $lastModified,
    ): self {
        return new self(true, $finalUrl, $permanentRedirect, null, $etag, $lastModified);
    }
}
```

`src/Service/Fetch/FeedFetcherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FetchException;

interface FeedFetcherInterface
{
    /**
     * Fetch a feed URL with SSRF protection and conditional-GET support.
     *
     * @throws FetchException
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit tests/Service/Fetch/FetchResponseTest.php`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/Fetch tests/Service/Fetch
git commit -m "Add fetch contract: FeedFetcherInterface, FetchResponse, exceptions"
```

---

### Task 3: SSRF guard — IpValidator, DnsResolver, UrlGuard

**Files:**
- Create: `backend/src/Service/Fetch/IpValidator.php`
- Create: `backend/src/Service/Fetch/DnsResolverInterface.php`
- Create: `backend/src/Service/Fetch/NativeDnsResolver.php`
- Create: `backend/src/Service/Fetch/GuardedUrl.php`
- Create: `backend/src/Service/Fetch/UrlGuard.php`
- Test: `backend/tests/Service/Fetch/IpValidatorTest.php`
- Test: `backend/tests/Service/Fetch/UrlGuardTest.php`

- [ ] **Step 1: Write the failing IpValidator test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\IpValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedIps(): iterable
    {
        yield 'loopback v4' => ['127.0.0.1'];
        yield 'rfc1918 10/8' => ['10.1.2.3'];
        yield 'rfc1918 172.16/12' => ['172.16.0.1'];
        yield 'rfc1918 192.168/16' => ['192.168.1.1'];
        yield 'link-local' => ['169.254.169.254'];
        yield 'cgnat 100.64/10' => ['100.64.0.1'];
        yield 'this-net 0/8' => ['0.0.0.0'];
        yield 'multicast' => ['224.0.0.1'];
        yield 'reserved 240/4' => ['255.255.255.255'];
        yield 'v6 loopback' => ['::1'];
        yield 'v6 unspecified' => ['::'];
        yield 'v6 ula' => ['fd12:3456::1'];
        yield 'v6 link-local' => ['fe80::1'];
        yield 'v6 multicast' => ['ff02::1'];
        yield 'v4-mapped private' => ['::ffff:192.168.1.1'];
        yield 'v4-mapped loopback' => ['::ffff:127.0.0.1'];
        yield 'v4-compatible loopback' => ['::7f00:1'];
        yield 'v4-compatible loopback dotted' => ['::127.0.0.1'];
        yield 'nat64 private' => ['64:ff9b::a00:1'];
        yield 'nat64 loopback' => ['64:ff9b::7f00:1'];
        yield 'nat64 local-use' => ['64:ff9b:1::7f00:1'];
        yield '6to4 loopback' => ['2002:7f00:1::'];
        yield 'v6 site-local' => ['fec0::1'];
        yield 'garbage' => ['not-an-ip'];
    }

    #[DataProvider('blockedIps')]
    public function testBlocked(string $ip): void
    {
        self::assertFalse((new IpValidator())->isPublic($ip));
    }

    /** @return iterable<string, array{string}> */
    public static function publicIps(): iterable
    {
        yield 'public v4' => ['93.184.216.34'];
        yield 'public dns' => ['8.8.8.8'];
        yield 'public v6' => ['2606:4700:4700::1111'];
        yield 'v4-mapped public' => ['::ffff:8.8.8.8'];
    }

    #[DataProvider('publicIps')]
    public function testPublic(string $ip): void
    {
        self::assertTrue((new IpValidator())->isPublic($ip));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`IpValidator` not found):
`php vendor/bin/phpunit tests/Service/Fetch/IpValidatorTest.php`

- [ ] **Step 3: Implement IpValidator**

`src/Service/Fetch/IpValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

/**
 * Decides whether an IP address is publicly routable. Everything private,
 * loopback, link-local, reserved, or multicast is rejected (SSRF guard).
 */
final class IpValidator
{
    private const BLOCKED_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        // ::/96 covers the unspecified address, ::1 loopback, and the
        // deprecated IPv4-compatible format (::127.0.0.1 reaching loopback).
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001:db8::/32',
        // 6to4: 2002:7f00:1:: encapsulates 127.0.0.1.
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        // Deprecated site-local, still routable on some networks.
        'fec0::/10',
        'ff00::/8',
    ];

    private const V4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    public function isPublic(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return false;
        }

        if (\strlen($binary) === 16 && str_starts_with($binary, self::V4_MAPPED_PREFIX)) {
            $mapped = inet_ntop(substr($binary, 12));

            return $mapped !== false && $this->isPublic($mapped);
        }

        foreach (self::BLOCKED_RANGES as $range) {
            if ($this->inRange($binary, $range)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $binaryIp, string $range): bool
    {
        $parts = explode('/', $range);
        $binaryNetwork = inet_pton($parts[0]);
        $bits = (int) ($parts[1] ?? '0');

        if ($binaryNetwork === false || \strlen($binaryNetwork) !== \strlen($binaryIp)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if (substr($binaryIp, 0, $fullBytes) !== substr($binaryNetwork, 0, $fullBytes)) {
            return false;
        }

        $remainder = $bits % 8;
        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($binaryIp[$fullBytes]) & $mask) === (\ord($binaryNetwork[$fullBytes]) & $mask);
    }
}
```

- [ ] **Step 4: Run it — expect PASS.**

- [ ] **Step 5: Write the failing UrlGuard test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    /** @param array<string, list<string>> $dnsMap */
    private function guard(array $dnsMap = []): UrlGuard
    {
        $resolver = new class ($dnsMap) implements DnsResolverInterface {
            /** @param array<string, list<string>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->map[$hostname] ?? [];
            }
        };

        return new UrlGuard($resolver, new IpValidator());
    }

    public function testAllowsPublicHostAndReturnsResolvedIp(): void
    {
        $guarded = $this->guard(['example.com' => ['93.184.216.34']])
            ->assertSafe('https://example.com/feed.xml');

        self::assertSame('https://example.com/feed.xml', $guarded->url);
        self::assertSame('https', $guarded->scheme);
        self::assertSame('example.com', $guarded->host);
        self::assertSame(443, $guarded->port);
        self::assertSame('93.184.216.34', $guarded->ip);
    }

    public function testAllowsPublicIpLiteralWithoutDns(): void
    {
        $guarded = $this->guard()->assertSafe('http://93.184.216.34/feed');

        self::assertSame('93.184.216.34', $guarded->ip);
        self::assertSame(80, $guarded->port);
    }

    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://example.com/feed'];
        yield 'loopback literal' => ['http://127.0.0.1/feed'];
        yield 'private literal' => ['http://10.0.0.5/feed'];
        yield 'v6 loopback literal' => ['http://[::1]/feed'];
        yield 'credentials in url' => ['https://user:pass@example.com/feed'];
        yield 'malformed' => ['http:///nohost'];
    }

    #[DataProvider('blockedUrls')]
    public function testBlockedWithoutDns(string $url): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard(['example.com' => ['93.184.216.34']])->assertSafe($url);
    }

    public function testBlocksHostResolvingToPrivateIp(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard(['evil.example.com' => ['169.254.169.254']])->assertSafe('https://evil.example.com/');
    }

    public function testBlocksWhenAnyRecordIsPrivate(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard(['dual.example.com' => ['93.184.216.34', '10.0.0.1']])->assertSafe('https://dual.example.com/');
    }

    public function testBlocksWhenDnsResolutionFails(): void
    {
        $this->expectException(SsrfBlockedException::class);
        $this->guard()->assertSafe('https://unresolvable.example.com/');
    }
}
```

- [ ] **Step 6: Run it — expect FAIL** (`UrlGuard` not found).

- [ ] **Step 7: Implement resolver, GuardedUrl, UrlGuard**

`src/Service/Fetch/DnsResolverInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

interface DnsResolverInterface
{
    /**
     * @return list<string> IPv4/IPv6 addresses; empty when resolution fails
     */
    public function resolve(string $hostname): array;
}
```

`src/Service/Fetch/NativeDnsResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

final class NativeDnsResolver implements DnsResolverInterface
{
    public function resolve(string $hostname): array
    {
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);
        if ($records === false) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($ip) && $ip !== '') {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }
}
```

`src/Service/Fetch/GuardedUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

final readonly class GuardedUrl
{
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {
    }
}
```

`src/Service/Fetch/UrlGuard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\SsrfBlockedException;

/**
 * Validates an outbound URL before any connection: scheme allowlist, DNS
 * resolution up front, and rejection of private/reserved target IPs. The
 * resolved IP is returned so the HTTP client can pin the connection to it
 * (closes the DNS-rebinding window).
 */
final class UrlGuard
{
    public function __construct(
        private readonly DnsResolverInterface $dnsResolver,
        private readonly IpValidator $ipValidator,
    ) {
    }

    public function assertSafe(string $url): GuardedUrl
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfBlockedException(sprintf('Malformed URL "%s"', $url));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfBlockedException(sprintf('Credentials in URL "%s"', $url));
        }

        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new SsrfBlockedException(sprintf('Scheme "%s" is not allowed', $scheme));
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->dnsResolver->resolve($host);

        if ($ips === []) {
            throw new SsrfBlockedException(sprintf('DNS resolution failed for "%s"', $host));
        }

        foreach ($ips as $ip) {
            if (!$this->ipValidator->isPublic($ip)) {
                throw new SsrfBlockedException(sprintf('Host "%s" resolves to non-public address %s', $host, $ip));
            }
        }

        return new GuardedUrl($url, $scheme, $host, $port, $ips[0]);
    }
}
```

- [ ] **Step 8: Run both test files — expect PASS.**

- [ ] **Step 9: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/Fetch tests/Service/Fetch
git commit -m "Add SSRF guard: IpValidator, DNS resolver, UrlGuard"
```

---

### Task 4: HttpFeedFetcher

**Files:**
- Create: `backend/src/Service/Fetch/UrlResolver.php`
- Create: `backend/src/Service/Fetch/HttpFeedFetcher.php`
- Modify: `backend/config/services.yaml` (interface aliases)
- Test: `backend/tests/Service/Fetch/HttpFeedFetcherTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Service\Fetch\DnsResolverInterface;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Exception\SsrfBlockedException;
use App\Service\Fetch\HttpFeedFetcher;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\UrlGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpFeedFetcherTest extends TestCase
{
    /**
     * @param callable|iterable<MockResponse> $responses
     * @param array<string, list<string>>     $dnsMap
     */
    private function fetcher(callable|iterable $responses, array $dnsMap = ['example.com' => ['93.184.216.34']]): HttpFeedFetcher
    {
        $resolver = new class ($dnsMap) implements DnsResolverInterface {
            /** @param array<string, list<string>> $map */
            public function __construct(private readonly array $map)
            {
            }

            public function resolve(string $hostname): array
            {
                return $this->map[$hostname] ?? [];
            }
        };

        return new HttpFeedFetcher(new MockHttpClient($responses), new UrlGuard($resolver, new IpValidator()));
    }

    public function testFetchesBodyAndCachingHeaders(): void
    {
        $fetcher = $this->fetcher([new MockResponse('<rss/>', [
            'http_code' => 200,
            'response_headers' => ['etag' => '"v1"', 'last-modified' => 'Mon, 20 Jul 2026 08:30:00 GMT'],
        ])]);

        $response = $fetcher->fetch('https://example.com/feed');

        self::assertFalse($response->notModified);
        self::assertSame('<rss/>', $response->body);
        self::assertSame('"v1"', $response->etag);
        self::assertSame('Mon, 20 Jul 2026 08:30:00 GMT', $response->lastModified);
        self::assertSame('https://example.com/feed', $response->finalUrl);
    }

    public function testSendsConditionalGetHeaders(): void
    {
        $seenHeaders = [];
        $factory = static function (string $method, string $url, array $options) use (&$seenHeaders): MockResponse {
            $seenHeaders = $options['normalized_headers'] ?? [];

            return new MockResponse('', ['http_code' => 304]);
        };

        $response = $this->fetcher($factory)->fetch('https://example.com/feed', '"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');

        self::assertTrue($response->notModified);
        self::assertArrayHasKey('if-none-match', $seenHeaders);
        self::assertArrayHasKey('if-modified-since', $seenHeaders);
    }

    public function testFollowsRedirectAndReportsPermanentMove(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => 301, 'response_headers' => ['location' => 'https://example.com/new-feed']]),
            new MockResponse('<rss/>', ['http_code' => 200]),
        ];

        $response = $this->fetcher($responses)->fetch('https://example.com/old-feed');

        self::assertTrue($response->permanentRedirect);
        self::assertSame('https://example.com/new-feed', $response->finalUrl);
        self::assertSame('<rss/>', $response->body);
    }

    public function testRevalidatesRedirectTargetAgainstGuard(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/latest']]),
        ];

        $this->expectException(SsrfBlockedException::class);
        $this->fetcher($responses)->fetch('https://example.com/feed');
    }

    public function testTooManyRedirects(): void
    {
        $redirect = static fn (): MockResponse => new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['location' => 'https://example.com/loop'],
        ]);
        $responses = [$redirect(), $redirect(), $redirect(), $redirect(), $redirect(), $redirect(), $redirect()];

        $this->expectException(FeedUnreachableException::class);
        $this->fetcher($responses)->fetch('https://example.com/feed');
    }

    public function testHttp410ThrowsFeedGone(): void
    {
        $this->expectException(FeedGoneException::class);
        $this->fetcher([new MockResponse('', ['http_code' => 410])])->fetch('https://example.com/feed');
    }

    public function testHttp500ThrowsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->fetcher([new MockResponse('oops', ['http_code' => 500])])->fetch('https://example.com/feed');
    }

    public function testOversizedResponseThrows(): void
    {
        $body = str_repeat('a', 5_000_001);

        $this->expectException(ResponseTooLargeException::class);
        $this->fetcher([new MockResponse($body, ['http_code' => 200])])->fetch('https://example.com/feed');
    }

    public function testNetworkErrorThrowsUnreachable(): void
    {
        $this->expectException(FeedUnreachableException::class);
        $this->fetcher([new MockResponse('', ['error' => 'connection refused'])])->fetch('https://example.com/feed');
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`HttpFeedFetcher` not found):
`php vendor/bin/phpunit tests/Service/Fetch/HttpFeedFetcherTest.php`

- [ ] **Step 3: Implement UrlResolver and HttpFeedFetcher**

`src/Service/Fetch/UrlResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedUnreachableException;

/**
 * Resolves a Location header value against the URL that produced it.
 */
final class UrlResolver
{
    public static function resolve(string $baseUrl, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new FeedUnreachableException(sprintf('Cannot resolve redirect target "%s"', $location));
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $location;
    }
}
```

`src/Service/Fetch/HttpFeedFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpFeedFetcher implements FeedFetcherInterface
{
    private const MAX_REDIRECTS = 5;
    private const MAX_BYTES = 5_000_000;
    private const TIMEOUT_SECONDS = 10.0;
    private const USER_AGENT = 'SimpleFeedReader/1.0 (+https://github.com/larspohlmann/simple-feed-reader)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UrlGuard $urlGuard,
    ) {
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        $currentUrl = $url;
        $permanentRedirect = false;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $guarded = $this->urlGuard->assertSafe($currentUrl);
            $response = $this->request($currentUrl, $guarded, $etag, $lastModified);
            $status = $this->statusCode($response, $currentUrl);

            if (\in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $this->header($response, 'location');
                $response->cancel();
                if ($location === null) {
                    throw new FeedUnreachableException(sprintf('%s: redirect without Location header', $currentUrl));
                }
                $permanentRedirect = $permanentRedirect || \in_array($status, [301, 308], true);
                $currentUrl = UrlResolver::resolve($currentUrl, $location);
                continue;
            }

            if ($status === 304) {
                $response->cancel();

                return FetchResponse::notModified($currentUrl, $permanentRedirect, $etag, $lastModified);
            }

            if ($status === 410) {
                $response->cancel();

                throw new FeedGoneException(sprintf('%s: HTTP 410 Gone', $currentUrl));
            }

            if ($status < 200 || $status >= 300) {
                $response->cancel();

                throw new FeedUnreachableException(sprintf('%s: HTTP %d', $currentUrl, $status));
            }

            $body = $this->content($response, $currentUrl);
            if (\strlen($body) > self::MAX_BYTES) {
                throw new ResponseTooLargeException(sprintf('%s: response exceeds %d bytes', $currentUrl, self::MAX_BYTES));
            }

            return FetchResponse::fetched(
                $currentUrl,
                $permanentRedirect,
                $body,
                $this->header($response, 'etag'),
                $this->header($response, 'last-modified'),
            );
        }

        throw new FeedUnreachableException(sprintf('%s: more than %d redirects', $url, self::MAX_REDIRECTS));
    }

    private function request(string $url, GuardedUrl $guarded, ?string $etag, ?string $lastModified): ResponseInterface
    {
        $headers = [
            'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.8, */*;q=0.1',
            'User-Agent' => self::USER_AGENT,
        ];
        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }
        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        try {
            return $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS * 2,
                'resolve' => [$guarded->host => $guarded->ip],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new ResponseTooLargeException(sprintf('response exceeds %d bytes', self::MAX_BYTES));
                    }
                },
            ]);
        } catch (ExceptionInterface $e) {
            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function statusCode(ResponseInterface $response, string $url): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function content(ResponseInterface $response, string $url): string
    {
        try {
            return $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->rethrowTooLarge($e);

            throw new FeedUnreachableException(sprintf('%s: %s', $url, $e->getMessage()), previous: $e);
        }
    }

    private function header(ResponseInterface $response, string $name): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface) {
            return null;
        }

        return $headers[$name][0] ?? null;
    }

    /**
     * The HTTP client wraps exceptions thrown inside on_progress; unwrap and
     * rethrow our size-limit exception so callers see the real cause.
     */
    private function rethrowTooLarge(?\Throwable $e): void
    {
        while ($e !== null) {
            if ($e instanceof ResponseTooLargeException) {
                throw $e;
            }
            $e = $e->getPrevious();
        }
    }
}
```

- [ ] **Step 4: Wire the interface aliases**

Append to `backend/config/services.yaml` (under `services:`):

```yaml
    App\Service\Fetch\DnsResolverInterface: '@App\Service\Fetch\NativeDnsResolver'
    App\Service\Fetch\FeedFetcherInterface: '@App\Service\Fetch\HttpFeedFetcher'
```

- [ ] **Step 5: Run the test — expect PASS.** Note: if `testSendsConditionalGetHeaders` fails because `normalized_headers` is absent from `$options`, read the `headers` key instead (list of `Name: value` strings) and assert with `self::assertContains('if-none-match: "v1"', array_map('strtolower', $options['headers']))`.

- [ ] **Step 6: Gates and commit**

```bash
php bin/console cache:warmup && composer check && php vendor/bin/phpunit
git add src/Service/Fetch tests/Service/Fetch config/services.yaml
git commit -m "Add HttpFeedFetcher: conditional GET, guarded redirects, size cap"
```

---

### Task 5: Parser DTOs, helpers, RSS 2.0, and format detection

**Files:**
- Create: `backend/src/Service/Parser/Exception/FeedParseException.php`
- Create: `backend/src/Service/Parser/ParsedFeed.php`
- Create: `backend/src/Service/Parser/ParsedEntry.php`
- Create: `backend/src/Service/Parser/XmlHelper.php`
- Create: `backend/src/Service/Parser/DateParser.php`
- Create: `backend/src/Service/Parser/GuidFallback.php`
- Create: `backend/src/Service/Parser/Rss2Parser.php`
- Create: `backend/src/Service/Parser/FeedParser.php`
- Create: `backend/tests/Fixtures/feeds/rss2-basic.xml`
- Create: `backend/tests/Fixtures/feeds/rss2-no-guid.xml`
- Test: `backend/tests/Service/Parser/FeedParserTest.php`

- [ ] **Step 1: Create the fixtures**

`tests/Fixtures/feeds/rss2-basic.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title>Example Tech Blog</title>
    <link>https://blog.example.com/</link>
    <description>News from Example</description>
    <item>
      <title><![CDATA[Big <Announcement> & More]]></title>
      <link>https://blog.example.com/announcement</link>
      <guid isPermaLink="false">tag:blog.example.com,2026:announcement</guid>
      <pubDate>Mon, 20 Jul 2026 08:30:00 +0200</pubDate>
      <dc:creator>Jane Doe</dc:creator>
      <description>Short teaser text.</description>
      <content:encoded><![CDATA[<p>Full <strong>story</strong> with an <img src="https://blog.example.com/pic.jpg" alt="pic"> image.</p>]]></content:encoded>
    </item>
    <item>
      <title>Second post</title>
      <link>https://blog.example.com/second</link>
      <guid>https://blog.example.com/second</guid>
      <pubDate>Sun, 19 Jul 2026 10:00:00 +0200</pubDate>
      <description><![CDATA[<p>Description-only body.</p>]]></description>
    </item>
  </channel>
</rss>
```

`tests/Fixtures/feeds/rss2-no-guid.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>No-GUID Feed</title>
    <link>https://noguid.example.com/</link>
    <description>Items without guid elements</description>
    <item>
      <title>Post without guid</title>
      <link>https://noguid.example.com/post-1</link>
      <pubDate>this is not a date</pubDate>
      <description>Body one.</description>
    </item>
    <item>
      <title>Another guidless post</title>
      <link>https://noguid.example.com/post-2</link>
      <description>Body two.</description>
    </item>
  </channel>
</rss>
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser;

use App\Service\Parser\AtomParser;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use PHPUnit\Framework\TestCase;

final class FeedParserTest extends TestCase
{
    private function parser(): FeedParser
    {
        return new FeedParser(new Rss2Parser(), new AtomParser(), new Rss1Parser());
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../../Fixtures/feeds/' . $name);
    }

    public function testParsesRss2Basic(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss2-basic.xml'));

        self::assertSame('Example Tech Blog', $feed->title);
        self::assertSame('https://blog.example.com/', $feed->siteUrl);
        self::assertSame('News from Example', $feed->description);
        self::assertCount(2, $feed->entries);

        $first = $feed->entries[0];
        self::assertSame('tag:blog.example.com,2026:announcement', $first->guid);
        self::assertSame('Big <Announcement> & More', $first->title);
        self::assertSame('https://blog.example.com/announcement', $first->url);
        self::assertSame('Jane Doe', $first->author);
        self::assertSame('Short teaser text.', $first->summary);
        self::assertStringContainsString('<strong>story</strong>', (string) $first->contentHtml);
        self::assertSame('2026-07-20T08:30:00+02:00', $first->publishedAt?->format(DATE_ATOM));

        $second = $feed->entries[1];
        self::assertSame('https://blog.example.com/second', $second->guid);
        self::assertNull($second->summary);
        self::assertStringContainsString('Description-only body', (string) $second->contentHtml);
    }

    public function testMissingGuidFallsBackToHashAndBrokenDateBecomesNull(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss2-no-guid.xml'));

        self::assertCount(2, $feed->entries);
        $first = $feed->entries[0];
        self::assertSame(
            'urn:sfr:' . hash('sha256', 'https://noguid.example.com/post-1|Post without guid'),
            $first->guid,
        );
        self::assertNull($first->publishedAt);
        self::assertNotSame($feed->entries[1]->guid, $first->guid);
    }

    public function testRejectsNonXml(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('this is { not xml');
    }

    public function testRejectsUnknownRootElement(): void
    {
        $this->expectException(FeedParseException::class);
        $this->parser()->parse('<?xml version="1.0"?><html><body>nope</body></html>');
    }
}
```

- [ ] **Step 3: Run it — expect FAIL** (classes not found):
`php vendor/bin/phpunit tests/Service/Parser/FeedParserTest.php`

- [ ] **Step 4: Implement DTOs and helpers**

`src/Service/Parser/Exception/FeedParseException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser\Exception;

final class FeedParseException extends \RuntimeException
{
}
```

`src/Service/Parser/ParsedFeed.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

final readonly class ParsedFeed
{
    /**
     * @param list<ParsedEntry> $entries
     */
    public function __construct(
        public ?string $title,
        public ?string $siteUrl,
        public ?string $description,
        public array $entries,
    ) {
    }
}
```

`src/Service/Parser/ParsedEntry.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

final readonly class ParsedEntry
{
    public function __construct(
        public string $guid,
        public ?string $url,
        public string $title,
        public ?string $author,
        public ?string $summary,
        public ?string $contentHtml,
        public ?\DateTimeImmutable $publishedAt,
    ) {
    }
}
```

`src/Service/Parser/XmlHelper.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class XmlHelper
{
    /**
     * Trimmed text content of the first matching direct child element, or
     * null when absent/empty. When $namespaceUri is null, any namespace
     * matches.
     */
    public static function childText(\DOMElement $parent, string $localName, ?string $namespaceUri = null): ?string
    {
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== $localName) {
                continue;
            }
            if ($namespaceUri !== null && $child->namespaceURI !== $namespaceUri) {
                continue;
            }
            $text = trim($child->textContent);

            return $text === '' ? null : $text;
        }

        return null;
    }
}
```

`src/Service/Parser/DateParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class DateParser
{
    /**
     * Lenient date parsing: RFC 2822, ISO 8601, and anything else PHP
     * understands. Unparsable input becomes null — a missing date must never
     * kill the whole feed.
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
```

`src/Service/Parser/GuidFallback.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

final class GuidFallback
{
    /**
     * Entries without a GUID get a stable synthetic one derived from link and
     * title, so re-fetches dedupe correctly.
     */
    public static function for(?string $guid, ?string $url, ?string $title): string
    {
        if ($guid !== null && $guid !== '') {
            return $guid;
        }

        return 'urn:sfr:' . hash('sha256', ($url ?? '') . '|' . ($title ?? ''));
    }
}
```

- [ ] **Step 5: Implement Rss2Parser and FeedParser**

`src/Service/Parser/Rss2Parser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class Rss2Parser
{
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    public function parse(\DOMDocument $document): ParsedFeed
    {
        $channel = $document->getElementsByTagName('channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS document without <channel>');
        }

        $entries = [];
        foreach ($document->getElementsByTagName('item') as $item) {
            $entry = $this->parseItem($item);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ParsedFeed(
            XmlHelper::childText($channel, 'title'),
            XmlHelper::childText($channel, 'link'),
            XmlHelper::childText($channel, 'description'),
            $entries,
        );
    }

    private function parseItem(\DOMElement $item): ?ParsedEntry
    {
        $title = XmlHelper::childText($item, 'title');
        $link = XmlHelper::childText($item, 'link');
        if ($title === null && $link === null) {
            return null;
        }

        $description = XmlHelper::childText($item, 'description');
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);

        return new ParsedEntry(
            guid: GuidFallback::for(XmlHelper::childText($item, 'guid'), $link, $title),
            url: $link,
            title: $title ?? '(untitled)',
            author: XmlHelper::childText($item, 'author') ?? XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(
                XmlHelper::childText($item, 'pubDate') ?? XmlHelper::childText($item, 'date', self::DC_NS),
            ),
        );
    }
}
```

`src/Service/Parser/FeedParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class FeedParser
{
    public function __construct(
        private readonly Rss2Parser $rss2Parser,
        private readonly AtomParser $atomParser,
        private readonly Rss1Parser $rss1Parser,
    ) {
    }

    public function parse(string $xml): ParsedFeed
    {
        $document = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        $root = $document->documentElement;
        if ($loaded === false || $root === null) {
            throw new FeedParseException('Document is not well-formed XML');
        }

        return match ($root->localName) {
            'rss' => $this->rss2Parser->parse($document),
            'feed' => $this->atomParser->parse($document),
            'RDF' => $this->rss1Parser->parse($document),
            default => throw new FeedParseException(sprintf('Unknown feed root element <%s>', (string) $root->localName)),
        };
    }
}
```

**Note:** `AtomParser` and `Rss1Parser` are implemented in Task 6. To keep this task compiling and its tests green, create them now as minimal stubs that will be replaced:

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class AtomParser
{
    public function parse(\DOMDocument $document): ParsedFeed
    {
        throw new FeedParseException('Atom parsing not implemented yet');
    }
}
```

(and the same shape for `Rss1Parser`).

- [ ] **Step 6: Run the test — expect PASS.**

- [ ] **Step 7: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/Parser tests/Service/Parser tests/Fixtures
git commit -m "Add feed parsing: format detection, RSS 2.0, guid/date fallbacks"
```

---

### Task 6: AtomParser and Rss1Parser

**Files:**
- Modify: `backend/src/Service/Parser/AtomParser.php` (replace stub)
- Modify: `backend/src/Service/Parser/Rss1Parser.php` (replace stub)
- Create: `backend/tests/Fixtures/feeds/atom-basic.xml`
- Create: `backend/tests/Fixtures/feeds/rss1-basic.xml`
- Test: extend `backend/tests/Service/Parser/FeedParserTest.php`

- [ ] **Step 1: Create the fixtures**

`tests/Fixtures/feeds/atom-basic.xml`:

```xml
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom Example</title>
  <subtitle>An atom feed</subtitle>
  <link href="https://atom.example.com/" rel="alternate"/>
  <link href="https://atom.example.com/feed.atom" rel="self"/>
  <id>urn:uuid:60a76c80-d399-11d9-b93C-0003939e0af6</id>
  <updated>2026-07-20T10:00:00Z</updated>
  <entry>
    <title>Atom entry one</title>
    <link rel="alternate" href="https://atom.example.com/one"/>
    <id>urn:uuid:1225c695-cfb8-4ebb-aaaa-80da344efa6a</id>
    <published>2026-07-19T18:30:02Z</published>
    <updated>2026-07-20T09:00:00Z</updated>
    <author><name>Ada Lovelace</name></author>
    <summary>Plain text teaser.</summary>
    <content type="html">&lt;p&gt;Escaped &lt;em&gt;html&lt;/em&gt; body.&lt;/p&gt;</content>
  </entry>
  <entry>
    <title>Atom entry two (xhtml)</title>
    <link href="https://atom.example.com/two"/>
    <id>urn:uuid:2225c695-cfb8-4ebb-aaaa-80da344efa6b</id>
    <updated>2026-07-18T12:00:00Z</updated>
    <content type="xhtml">
      <div xmlns="http://www.w3.org/1999/xhtml"><p>Inline <strong>xhtml</strong> body.</p></div>
    </content>
  </entry>
</feed>
```

`tests/Fixtures/feeds/rss1-basic.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
         xmlns="http://purl.org/rss/1.0/"
         xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel rdf:about="https://rss1.example.com/">
    <title>RSS 1.0 Example</title>
    <link>https://rss1.example.com/</link>
    <description>An RDF site summary</description>
  </channel>
  <item rdf:about="https://rss1.example.com/item-1">
    <title>RDF item one</title>
    <link>https://rss1.example.com/item-1</link>
    <description>First RDF body.</description>
    <dc:creator>Grace Hopper</dc:creator>
    <dc:date>2026-07-17T08:00:00+00:00</dc:date>
  </item>
</rdf:RDF>
```

- [ ] **Step 2: Add failing tests to `FeedParserTest`**

```php
    public function testParsesAtom(): void
    {
        $feed = $this->parser()->parse($this->fixture('atom-basic.xml'));

        self::assertSame('Atom Example', $feed->title);
        self::assertSame('https://atom.example.com/', $feed->siteUrl);
        self::assertSame('An atom feed', $feed->description);
        self::assertCount(2, $feed->entries);

        $first = $feed->entries[0];
        self::assertSame('urn:uuid:1225c695-cfb8-4ebb-aaaa-80da344efa6a', $first->guid);
        self::assertSame('https://atom.example.com/one', $first->url);
        self::assertSame('Ada Lovelace', $first->author);
        self::assertSame('Plain text teaser.', $first->summary);
        self::assertSame('<p>Escaped <em>html</em> body.</p>', $first->contentHtml);
        self::assertSame('2026-07-19T18:30:02+00:00', $first->publishedAt?->format(DATE_ATOM));

        $second = $feed->entries[1];
        self::assertSame('https://atom.example.com/two', $second->url);
        self::assertStringContainsString('<strong>xhtml</strong>', (string) $second->contentHtml);
        self::assertSame('2026-07-18T12:00:00+00:00', $second->publishedAt?->format(DATE_ATOM));
    }

    public function testParsesRss1(): void
    {
        $feed = $this->parser()->parse($this->fixture('rss1-basic.xml'));

        self::assertSame('RSS 1.0 Example', $feed->title);
        self::assertCount(1, $feed->entries);

        $item = $feed->entries[0];
        self::assertSame('https://rss1.example.com/item-1', $item->guid);
        self::assertSame('Grace Hopper', $item->author);
        self::assertStringContainsString('First RDF body', (string) $item->contentHtml);
        self::assertSame('2026-07-17T08:00:00+00:00', $item->publishedAt?->format(DATE_ATOM));
    }
```

- [ ] **Step 3: Run — expect FAIL** ("Atom parsing not implemented yet").

- [ ] **Step 4: Implement both parsers**

`src/Service/Parser/AtomParser.php` (replace the stub):

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class AtomParser
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';

    public function parse(\DOMDocument $document): ParsedFeed
    {
        $root = $document->documentElement;
        if ($root === null) {
            throw new FeedParseException('Atom document without root element');
        }

        $entries = [];
        foreach ($document->getElementsByTagNameNS(self::ATOM_NS, 'entry') as $entryElement) {
            $entry = $this->parseEntry($entryElement);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ParsedFeed(
            XmlHelper::childText($root, 'title', self::ATOM_NS),
            $this->alternateLink($root),
            XmlHelper::childText($root, 'subtitle', self::ATOM_NS),
            $entries,
        );
    }

    private function parseEntry(\DOMElement $entry): ?ParsedEntry
    {
        $title = XmlHelper::childText($entry, 'title', self::ATOM_NS);
        $link = $this->alternateLink($entry);
        if ($title === null && $link === null) {
            return null;
        }

        return new ParsedEntry(
            guid: GuidFallback::for(XmlHelper::childText($entry, 'id', self::ATOM_NS), $link, $title),
            url: $link,
            title: $title ?? '(untitled)',
            author: $this->authorName($entry),
            summary: XmlHelper::childText($entry, 'summary', self::ATOM_NS),
            contentHtml: $this->contentHtml($entry),
            publishedAt: DateParser::parse(
                XmlHelper::childText($entry, 'published', self::ATOM_NS)
                ?? XmlHelper::childText($entry, 'updated', self::ATOM_NS),
            ),
        );
    }

    private function alternateLink(\DOMElement $parent): ?string
    {
        $fallback = null;
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'link' || $child->namespaceURI !== self::ATOM_NS) {
                continue;
            }
            $href = trim($child->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $rel = $child->getAttribute('rel');
            if ($rel === 'alternate') {
                return $href;
            }
            if ($rel === '') {
                $fallback ??= $href;
            }
        }

        return $fallback;
    }

    private function authorName(\DOMElement $entry): ?string
    {
        foreach ($entry->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'author' && $child->namespaceURI === self::ATOM_NS) {
                return XmlHelper::childText($child, 'name', self::ATOM_NS);
            }
        }

        return null;
    }

    private function contentHtml(\DOMElement $entry): ?string
    {
        foreach ($entry->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'content' || $child->namespaceURI !== self::ATOM_NS) {
                continue;
            }

            if ($child->getAttribute('type') === 'xhtml') {
                $html = '';
                foreach ($child->childNodes as $inner) {
                    $html .= (string) $child->ownerDocument?->saveXML($inner);
                }
                $html = trim($html);

                return $html === '' ? null : $html;
            }

            $text = trim($child->textContent);

            return $text === '' ? null : $text;
        }

        return null;
    }
}
```

`src/Service/Parser/Rss1Parser.php` (replace the stub):

```php
<?php

declare(strict_types=1);

namespace App\Service\Parser;

use App\Service\Parser\Exception\FeedParseException;

final class Rss1Parser
{
    private const RSS1_NS = 'http://purl.org/rss/1.0/';
    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

    public function parse(\DOMDocument $document): ParsedFeed
    {
        $channel = $document->getElementsByTagNameNS(self::RSS1_NS, 'channel')->item(0);
        if (!$channel instanceof \DOMElement) {
            throw new FeedParseException('RSS 1.0 document without <channel>');
        }

        $entries = [];
        foreach ($document->getElementsByTagNameNS(self::RSS1_NS, 'item') as $item) {
            $entry = $this->parseItem($item);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return new ParsedFeed(
            XmlHelper::childText($channel, 'title', self::RSS1_NS),
            XmlHelper::childText($channel, 'link', self::RSS1_NS),
            XmlHelper::childText($channel, 'description', self::RSS1_NS),
            $entries,
        );
    }

    private function parseItem(\DOMElement $item): ?ParsedEntry
    {
        $title = XmlHelper::childText($item, 'title', self::RSS1_NS);
        $link = XmlHelper::childText($item, 'link', self::RSS1_NS);
        if ($title === null && $link === null) {
            return null;
        }

        $about = trim($item->getAttributeNS(self::RDF_NS, 'about'));
        $description = XmlHelper::childText($item, 'description', self::RSS1_NS);
        $contentEncoded = XmlHelper::childText($item, 'encoded', self::CONTENT_NS);

        return new ParsedEntry(
            guid: GuidFallback::for($about === '' ? null : $about, $link, $title),
            url: $link ?? ($about === '' ? null : $about),
            title: $title ?? '(untitled)',
            author: XmlHelper::childText($item, 'creator', self::DC_NS),
            summary: $contentEncoded !== null ? $description : null,
            contentHtml: $contentEncoded ?? $description,
            publishedAt: DateParser::parse(XmlHelper::childText($item, 'date', self::DC_NS)),
        );
    }
}
```

- [ ] **Step 5: Run the full parser test — expect PASS.**

- [ ] **Step 6: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/Parser tests
git commit -m "Add Atom and RSS 1.0 parsers"
```

---

### Task 7: EntrySanitizer

**Files:**
- Create: `backend/src/Service/EntrySanitizer.php`
- Test: `backend/tests/Service/EntrySanitizerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EntrySanitizer;
use PHPUnit\Framework\TestCase;

final class EntrySanitizerTest extends TestCase
{
    private EntrySanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new EntrySanitizer();
    }

    public function testStripsScriptsAndEventHandlers(): void
    {
        $dirty = '<p onclick="evil()">Hi</p><script>alert(1)</script><img src="x" onerror="evil()">';
        $clean = (string) $this->sanitizer->sanitize($dirty);

        self::assertStringNotContainsString('script', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('onerror', $clean);
        self::assertStringContainsString('<p>Hi</p>', $clean);
    }

    public function testStripsJavascriptUrls(): void
    {
        $clean = (string) $this->sanitizer->sanitize('<a href="javascript:alert(1)">x</a>');

        self::assertStringNotContainsString('javascript:', $clean);
    }

    public function testKeepsFormattingImagesAndLinks(): void
    {
        $html = '<p>Some <strong>bold</strong> text with <img src="https://example.com/pic.jpg" alt="pic"> '
            . 'and a <a href="https://example.com/">link</a>.</p>';
        $clean = (string) $this->sanitizer->sanitize($html);

        self::assertStringContainsString('<strong>bold</strong>', $clean);
        self::assertStringContainsString('src="https://example.com/pic.jpg"', $clean);
        self::assertStringContainsString('href="https://example.com/"', $clean);
    }

    public function testForcesSafeLinkAttributes(): void
    {
        $clean = (string) $this->sanitizer->sanitize('<a href="https://example.com/">link</a>');

        self::assertStringContainsString('rel="noopener noreferrer"', $clean);
        self::assertStringContainsString('target="_blank"', $clean);
    }

    public function testEmptyInputBecomesNull(): void
    {
        self::assertNull($this->sanitizer->sanitize(null));
        self::assertNull($this->sanitizer->sanitize('   '));
        self::assertNull($this->sanitizer->sanitize('<script>only evil</script>'));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`EntrySanitizer` not found):
`php vendor/bin/phpunit tests/Service/EntrySanitizerTest.php`

- [ ] **Step 3: Implement**

`src/Service/EntrySanitizer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Sanitizes third-party article HTML before storage. Config lives in code
 * (not framework yaml) so the service is constructible in any test without a
 * container.
 */
final class EntrySanitizer
{
    private const MAX_INPUT_LENGTH = 150_000;

    private readonly HtmlSanitizerInterface $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowSafeElements()
            ->allowElement('img', ['src', 'alt', 'title', 'width', 'height', 'loading'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('a', 'target', '_blank')
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https'])
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim($this->sanitizer->sanitize($html));

        return $clean === '' ? null : $clean;
    }
}
```

- [ ] **Step 4: Run it — expect PASS.** (Keep the parenthesized `(new HtmlSanitizerConfig())->…` form — the paren-less variant is PHP 8.4 syntax and this project pins PHP 8.3.)

- [ ] **Step 5: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/EntrySanitizer.php tests/Service/EntrySanitizerTest.php
git commit -m "Add EntrySanitizer for third-party article HTML"
```

---

### Task 8: FeedScheduler — adaptive intervals and backoff

**Files:**
- Create: `backend/src/Service/FeedScheduler.php`
- Test: `backend/tests/Service/FeedSchedulerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Service\FeedScheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FeedSchedulerTest extends TestCase
{
    private MockClock $clock;
    private FeedScheduler $scheduler;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->scheduler = new FeedScheduler($this->clock);
    }

    public function testSuccessWithNewEntriesHalvesIntervalDownToFloor(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(120);

        $this->scheduler->recordSuccess($feed, 3);

        self::assertSame(60, $feed->getFetchIntervalMinutes());
        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
        self::assertSame('2026-07-21 12:00:00', $feed->getLastFetchedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-07-21 13:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $feed->setFetchIntervalMinutes(40);
        $this->scheduler->recordSuccess($feed, 1);
        self::assertSame(30, $feed->getFetchIntervalMinutes());
    }

    public function testQuietSuccessGrowsIntervalUpToCeiling(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(60);

        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(90, $feed->getFetchIntervalMinutes());

        $feed->setFetchIntervalMinutes(1200);
        $this->scheduler->recordSuccess($feed, 0);
        self::assertSame(1440, $feed->getFetchIntervalMinutes());
    }

    public function testSuccessClearsPreviousFailureState(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setConsecutiveFailures(5);
        $feed->setLastErrorMessage('boom');
        $feed->setStatus(FeedStatus::Erroring);

        $this->scheduler->recordSuccess($feed, 0);

        self::assertSame(0, $feed->getConsecutiveFailures());
        self::assertNull($feed->getLastErrorMessage());
        self::assertSame(FeedStatus::Active, $feed->getStatus());
    }

    public function testFailureBacksOffExponentially(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(60);

        $this->scheduler->recordFailure($feed, 'timeout');

        self::assertSame(1, $feed->getConsecutiveFailures());
        self::assertSame(FeedStatus::Erroring, $feed->getStatus());
        self::assertSame('timeout', $feed->getLastErrorMessage());
        // 60 * 2^1 = 120 minutes
        self::assertSame('2026-07-21 14:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));

        $this->scheduler->recordFailure($feed, 'timeout again');
        // 60 * 2^2 = 240 minutes
        self::assertSame('2026-07-21 16:00:00', $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testBackoffIsCappedAtSevenDays(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setFetchIntervalMinutes(1440);
        $feed->setConsecutiveFailures(10);

        $this->scheduler->recordFailure($feed, 'still broken');

        $cap = $this->clock->now()->modify('+10080 minutes');
        self::assertSame($cap->format('Y-m-d H:i:s'), $feed->getNextFetchAt()?->format('Y-m-d H:i:s'));
    }

    public function testThirtiethFailureMarksFeedGone(): void
    {
        $feed = new Feed('https://example.com/feed');
        $feed->setConsecutiveFailures(29);

        $this->scheduler->recordFailure($feed, 'the end');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertSame(30, $feed->getConsecutiveFailures());
        self::assertNull($feed->getNextFetchAt());
    }

    public function testRecordGone(): void
    {
        $feed = new Feed('https://example.com/feed');

        $this->scheduler->recordGone($feed, 'HTTP 410 Gone');

        self::assertSame(FeedStatus::Gone, $feed->getStatus());
        self::assertNull($feed->getNextFetchAt());
        self::assertSame('HTTP 410 Gone', $feed->getLastErrorMessage());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`FeedScheduler` not found).

- [ ] **Step 3: Implement**

`src/Service/FeedScheduler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Feed;
use App\Enum\FeedStatus;
use Symfony\Component\Clock\ClockInterface;

/**
 * Owns all fetch-schedule state transitions on Feed: adaptive interval on
 * success, exponential backoff on failure, and the "gone" terminal state.
 */
final class FeedScheduler
{
    private const FLOOR_MINUTES = 30;
    private const CEILING_MINUTES = 1440;      // 24 h
    private const FAILURE_CAP_MINUTES = 10080; // 7 days
    private const FAILURES_UNTIL_GONE = 30;
    private const MAX_BACKOFF_EXPONENT = 9;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function recordSuccess(Feed $feed, int $newEntryCount): void
    {
        $interval = $newEntryCount > 0
            ? max(self::FLOOR_MINUTES, intdiv($feed->getFetchIntervalMinutes(), 2))
            : min(self::CEILING_MINUTES, (int) round($feed->getFetchIntervalMinutes() * 1.5));

        $now = $this->clock->now();
        $feed->setFetchIntervalMinutes($interval);
        $feed->setConsecutiveFailures(0);
        $feed->setLastErrorMessage(null);
        $feed->setStatus(FeedStatus::Active);
        $feed->setLastFetchedAt($now);
        $feed->setNextFetchAt($now->modify(sprintf('+%d minutes', $interval)));
    }

    public function recordFailure(Feed $feed, string $message): void
    {
        $failures = $feed->getConsecutiveFailures() + 1;
        $now = $this->clock->now();

        $feed->setConsecutiveFailures($failures);
        $feed->setLastErrorMessage(mb_substr($message, 0, 1000));
        $feed->setLastFetchedAt($now);

        if ($failures >= self::FAILURES_UNTIL_GONE) {
            $feed->setStatus(FeedStatus::Gone);
            $feed->setNextFetchAt(null);

            return;
        }

        $feed->setStatus(FeedStatus::Erroring);
        $backoffMinutes = min(
            self::FAILURE_CAP_MINUTES,
            max($feed->getFetchIntervalMinutes(), self::FLOOR_MINUTES) * (2 ** min($failures, self::MAX_BACKOFF_EXPONENT)),
        );
        $feed->setNextFetchAt($now->modify(sprintf('+%d minutes', $backoffMinutes)));
    }

    public function recordGone(Feed $feed, string $message): void
    {
        $now = $this->clock->now();
        $feed->setStatus(FeedStatus::Gone);
        $feed->setConsecutiveFailures($feed->getConsecutiveFailures() + 1);
        $feed->setLastErrorMessage(mb_substr($message, 0, 1000));
        $feed->setLastFetchedAt($now);
        $feed->setNextFetchAt(null);
    }
}
```

- [ ] **Step 4: Run it — expect PASS.**

- [ ] **Step 5: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/FeedScheduler.php tests/Service/FeedSchedulerTest.php
git commit -m "Add FeedScheduler: adaptive interval, backoff, gone state"
```

---

### Task 9: EntryIngestor + guidHash dedupe query

**Files:**
- Modify: `backend/src/Repository/EntryRepository.php` (add `findExistingGuidHashes`)
- Create: `backend/src/Service/EntryIngestor.php`
- Test: `backend/tests/Service/EntryIngestorTest.php`

- [ ] **Step 1: Write the failing test** (integration — extends `DbTestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\EntryIngestor;
use App\Service\EntrySanitizer;
use App\Service\Parser\ParsedEntry;
use App\Service\Parser\ParsedFeed;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class EntryIngestorTest extends DbTestCase
{
    private EntryIngestor $ingestor;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);
        $this->ingestor = new EntryIngestor(
            $this->em,
            $entryRepository,
            new EntrySanitizer(),
            new MockClock('2026-07-21 12:00:00', 'UTC'),
        );
    }

    private function parsedEntry(string $guid, string $title, ?string $contentHtml = null): ParsedEntry
    {
        return new ParsedEntry(
            guid: $guid,
            url: 'https://example.com/' . $guid,
            title: $title,
            author: 'Author',
            summary: '<p>A &amp; B summary</p>',
            contentHtml: $contentHtml ?? '<p>Body</p><script>evil()</script>',
            publishedAt: new \DateTimeImmutable('2026-07-20 08:00:00'),
        );
    }

    public function testIngestsNewEntriesSanitizedAndDeduped(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $parsed = new ParsedFeed('Feed Title', 'https://example.com/', 'Desc', [
            $this->parsedEntry('g1', 'One'),
            $this->parsedEntry('g2', 'Two'),
            $this->parsedEntry('g1', 'Duplicate of one'),
        ]);

        $created = $this->ingestor->ingest($feed, $parsed);
        $this->em->flush();

        self::assertSame(2, $created);
        $entries = $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]);
        self::assertCount(2, $entries);

        $first = $entries[0];
        self::assertStringNotContainsString('script', (string) $first->getContentHtml());
        self::assertSame('A & B summary', $first->getSummary());
        self::assertSame('Feed Title', $feed->getTitle());
        self::assertSame('https://example.com/', $feed->getSiteUrl());
    }

    public function testSecondIngestOnlyAddsUnseenGuids(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, [
            $this->parsedEntry('g1', 'One'),
        ]));
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed(null, null, null, [
            $this->parsedEntry('g1', 'One again'),
            $this->parsedEntry('g3', 'Three'),
        ]));
        $this->em->flush();

        self::assertSame(1, $created);
        self::assertCount(2, $this->em->getRepository(Entry::class)->findBy(['feed' => $feed]));
    }

    public function testEmptyParsedFeedStillUpdatesMetadata(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $this->em->flush();

        $created = $this->ingestor->ingest($feed, new ParsedFeed('New Title', null, null, []));

        self::assertSame(0, $created);
        self::assertSame('New Title', $feed->getTitle());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`EntryIngestor` not found):
`php vendor/bin/phpunit tests/Service/EntryIngestorTest.php`

- [ ] **Step 3: Add the repository query**

Add to `backend/src/Repository/EntryRepository.php`:

```php
    /**
     * @param list<string> $guidHashes
     *
     * @return list<string> the subset of hashes that already exist for this feed
     */
    public function findExistingGuidHashes(Feed $feed, array $guidHashes): array
    {
        if ($guidHashes === []) {
            return [];
        }

        /** @var list<string> $existing */
        $existing = $this->createQueryBuilder('e')
            ->select('e.guidHash')
            ->andWhere('e.feed = :feed')
            ->andWhere('e.guidHash IN (:hashes)')
            ->setParameter('feed', $feed)
            ->setParameter('hashes', $guidHashes)
            ->getQuery()
            ->getSingleColumnResult();

        return $existing;
    }
```

(add `use App\Entity\Feed;` to the imports).

- [ ] **Step 4: Implement EntryIngestor**

`src/Service/EntryIngestor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Repository\EntryRepository;
use App\Service\Parser\ParsedFeed;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Turns a ParsedFeed into persisted Entry rows: dedupes against existing
 * guidHashes (and within the batch), sanitizes content, truncates to column
 * limits, and refreshes feed metadata. Caller flushes.
 */
final class EntryIngestor
{
    private const TITLE_MAX = 1024;
    private const AUTHOR_MAX = 255;
    private const URL_MAX = 2048;
    private const FEED_TITLE_MAX = 512;
    private const SUMMARY_MAX = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entryRepository,
        private readonly EntrySanitizer $sanitizer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function ingest(Feed $feed, ParsedFeed $parsed): int
    {
        $this->updateFeedMetadata($feed, $parsed);

        if ($parsed->entries === []) {
            return 0;
        }

        $hashes = array_map(
            static fn ($entry): string => hash('sha256', $entry->guid),
            $parsed->entries,
        );
        $seen = array_fill_keys($this->entryRepository->findExistingGuidHashes($feed, $hashes), true);

        $created = 0;
        foreach ($parsed->entries as $parsedEntry) {
            $hash = hash('sha256', $parsedEntry->guid);
            if (isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;

            $entry = new Entry(
                $feed,
                $parsedEntry->guid,
                $parsedEntry->url === null ? null : mb_substr($parsedEntry->url, 0, self::URL_MAX),
                mb_substr($parsedEntry->title, 0, self::TITLE_MAX),
                $this->clock->now(),
            );
            $entry->setAuthor($parsedEntry->author === null ? null : mb_substr($parsedEntry->author, 0, self::AUTHOR_MAX));
            $entry->setSummary($this->summarize($parsedEntry->summary));
            $entry->setContentHtml($this->sanitizer->sanitize($parsedEntry->contentHtml));
            $entry->setPublishedAt($parsedEntry->publishedAt);

            $this->em->persist($entry);
            $created++;
        }

        return $created;
    }

    private function updateFeedMetadata(Feed $feed, ParsedFeed $parsed): void
    {
        if ($parsed->title !== null) {
            $feed->setTitle(mb_substr($parsed->title, 0, self::FEED_TITLE_MAX));
        }
        if ($parsed->siteUrl !== null) {
            $feed->setSiteUrl(mb_substr($parsed->siteUrl, 0, self::URL_MAX));
        }
        if ($parsed->description !== null) {
            $feed->setDescription($parsed->description);
        }
    }

    private function summarize(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        $text = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5));
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::SUMMARY_MAX);
    }
}
```

- [ ] **Step 5: Run it — expect PASS.**

- [ ] **Step 6: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/EntryIngestor.php src/Repository/EntryRepository.php tests/Service/EntryIngestorTest.php
git commit -m "Add EntryIngestor with guidHash dedupe and sanitized content"
```

---

### Task 10: FeedRepository::findDue / countDue

**Files:**
- Modify: `backend/src/Repository/FeedRepository.php`
- Test: `backend/tests/Repository/FeedRepositoryTest.php`

- [ ] **Step 1: Write the failing test** (integration — extends `DbTestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedStatus;
use App\Repository\FeedRepository;
use App\Tests\DbTestCase;

final class FeedRepositoryTest extends DbTestCase
{
    private FeedRepository $repository;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var FeedRepository $repository */
        $repository = $this->em->getRepository(Feed::class);
        $this->repository = $repository;
        $this->now = new \DateTimeImmutable('2026-07-21 12:00:00');
    }

    private function feed(string $url, ?\DateTimeImmutable $nextFetchAt, FeedStatus $status = FeedStatus::Active): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt($nextFetchAt);
        $feed->setStatus($status);
        $this->em->persist($feed);

        return $feed;
    }

    public function testFindsDueFeedsOrderedNeverFetchedFirst(): void
    {
        $overdue = $this->feed('https://a.example.com/feed', $this->now->modify('-2 hours'));
        $neverFetched = $this->feed('https://b.example.com/feed', null);
        $this->feed('https://c.example.com/feed', $this->now->modify('+1 hour'));
        $this->feed('https://d.example.com/feed', $this->now->modify('-1 day'), FeedStatus::Gone);
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10);

        self::assertSame(
            [$neverFetched->getId(), $overdue->getId()],
            array_map(static fn (Feed $feed): ?int => $feed->getId(), $due),
        );
        self::assertSame(2, $this->repository->countDue($this->now));
    }

    public function testLimitIsApplied(): void
    {
        $this->feed('https://a.example.com/feed', $this->now->modify('-3 hours'));
        $this->feed('https://b.example.com/feed', $this->now->modify('-2 hours'));
        $this->feed('https://c.example.com/feed', $this->now->modify('-1 hour'));
        $this->em->flush();

        self::assertCount(2, $this->repository->findDue($this->now, 2));
        self::assertSame(3, $this->repository->countDue($this->now));
    }

    public function testForceIgnoresScheduleButHonorsCooldown(): void
    {
        $fresh = $this->feed('https://a.example.com/feed', $this->now->modify('+1 hour'));
        $fresh->setLastFetchedAt($this->now->modify('-1 minute'));
        $stale = $this->feed('https://b.example.com/feed', $this->now->modify('+1 hour'));
        $stale->setLastFetchedAt($this->now->modify('-10 minutes'));
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10, force: true, cooldownCutoff: $this->now->modify('-5 minutes'));

        self::assertSame([$stale->getId()], array_map(static fn (Feed $feed): ?int => $feed->getId(), $due));
    }

    public function testUserScopeOnlyReturnsSubscribedFeeds(): void
    {
        $user = new User('reader@example.com', $this->now);
        $this->em->persist($user);
        $mine = $this->feed('https://mine.example.com/feed', null);
        $this->feed('https://other.example.com/feed', null);
        $this->em->persist(new Subscription($user, $mine, $this->now));
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10, userId: $user->getId());

        self::assertSame([$mine->getId()], array_map(static fn (Feed $feed): ?int => $feed->getId(), $due));
        self::assertSame(1, $this->repository->countDue($this->now, userId: $user->getId()));
    }

    public function testFeedScopeIncludesGoneFeeds(): void
    {
        $gone = $this->feed('https://gone.example.com/feed', null, FeedStatus::Gone);
        $this->em->flush();

        $due = $this->repository->findDue($this->now, 10, feedId: $gone->getId(), force: true);

        self::assertSame([$gone->getId()], array_map(static fn (Feed $feed): ?int => $feed->getId(), $due));
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`findDue` undefined):
`php vendor/bin/phpunit tests/Repository/FeedRepositoryTest.php`

- [ ] **Step 3: Implement**

Add to `backend/src/Repository/FeedRepository.php` (imports: `App\Entity\Subscription`, `App\Enum\FeedStatus`, `Doctrine\ORM\QueryBuilder`):

```php
    /**
     * Feeds eligible for refresh, never-fetched first, then most overdue.
     *
     * Scopes: $feedId selects exactly one feed (including "gone" ones — this
     * is the manual-retry path); $userId restricts to feeds the user is
     * subscribed to; $force ignores the schedule but respects
     * $cooldownCutoff (feeds fetched after the cutoff are skipped).
     *
     * @return list<Feed>
     */
    public function findDue(
        \DateTimeImmutable $now,
        int $limit,
        ?int $userId = null,
        ?int $feedId = null,
        bool $force = false,
        ?\DateTimeImmutable $cooldownCutoff = null,
    ): array {
        /** @var list<Feed> $feeds */
        $feeds = $this->dueQueryBuilder($now, $userId, $feedId, $force, $cooldownCutoff)
            ->addSelect('COALESCE(f.nextFetchAt, :epoch) AS HIDDEN due_order')
            ->setParameter('epoch', new \DateTimeImmutable('@0'))
            ->orderBy('due_order', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $feeds;
    }

    public function countDue(
        \DateTimeImmutable $now,
        ?int $userId = null,
        ?int $feedId = null,
        bool $force = false,
        ?\DateTimeImmutable $cooldownCutoff = null,
    ): int {
        return (int) $this->dueQueryBuilder($now, $userId, $feedId, $force, $cooldownCutoff)
            ->select('COUNT(f.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function dueQueryBuilder(
        \DateTimeImmutable $now,
        ?int $userId,
        ?int $feedId,
        bool $force,
        ?\DateTimeImmutable $cooldownCutoff,
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('f');

        if ($feedId !== null) {
            return $qb->andWhere('f.id = :feedId')->setParameter('feedId', $feedId);
        }

        $qb->andWhere('f.status != :gone')->setParameter('gone', FeedStatus::Gone);

        if ($force) {
            if ($cooldownCutoff !== null) {
                $qb->andWhere('(f.lastFetchedAt IS NULL OR f.lastFetchedAt <= :cooldownCutoff)')
                    ->setParameter('cooldownCutoff', $cooldownCutoff);
            }
        } else {
            $qb->andWhere('(f.nextFetchAt IS NULL OR f.nextFetchAt <= :now)')
                ->setParameter('now', $now);
        }

        if ($userId !== null) {
            $qb->andWhere(sprintf(
                'EXISTS (SELECT s.id FROM %s s WHERE s.feed = f AND s.user = :userId)',
                Subscription::class,
            ))->setParameter('userId', $userId);
        }

        return $qb;
    }
```

- [ ] **Step 4: Run it — expect PASS.** (This runs on SQLite locally; the CI matrix proves the `COALESCE … HIDDEN` ordering and `EXISTS` subquery on MySQL.) If Doctrine rejects the `:epoch` parameter inside the SELECT clause, replace it with a DQL literal: `COALESCE(f.nextFetchAt, '1970-01-01 00:00:00') AS HIDDEN due_order` and drop the parameter.

- [ ] **Step 5: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Repository/FeedRepository.php tests/Repository/FeedRepositoryTest.php
git commit -m "Add due-feed selection queries with scope, force, and cooldown"
```

---

### Task 11: EntryPruner — retention with keep/favorite protection

**Files:**
- Create: `backend/src/Service/EntryPruner.php`
- Test: `backend/tests/Service/EntryPrunerTest.php`

- [ ] **Step 1: Write the failing test** (integration — extends `DbTestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\User;
use App\Service\EntryPruner;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class EntryPrunerTest extends DbTestCase
{
    private EntryPruner $pruner;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->pruner = new EntryPruner($this->em, $this->clock);
    }

    private function entry(Feed $feed, string $guid, \DateTimeImmutable $publishedAt): Entry
    {
        $entry = new Entry($feed, $guid, null, 'Title ' . $guid, $publishedAt);
        $entry->setPublishedAt($publishedAt);
        $this->em->persist($entry);

        return $entry;
    }

    public function testPrunesOldEntriesButKeepsProtectedAndRecent(): void
    {
        $feed = new Feed('https://example.com/feed');
        $user = new User('reader@example.com', $this->clock->now());
        $this->em->persist($feed);
        $this->em->persist($user);

        $old = $this->clock->now()->modify('-120 days');
        $prunable = $this->entry($feed, 'old-plain', $old);
        $favorite = $this->entry($feed, 'old-favorite', $old);
        $kept = $this->entry($feed, 'old-kept', $old);
        $oldButRead = $this->entry($feed, 'old-read', $old);
        $recent = $this->entry($feed, 'recent', $this->clock->now()->modify('-5 days'));

        $favoriteState = new EntryState($user, $favorite);
        $favoriteState->setIsFavorite(true);
        $keptState = new EntryState($user, $kept);
        $keptState->setIsKept(true);
        $readState = new EntryState($user, $oldButRead);
        $readState->setIsRead(true);
        $this->em->persist($favoriteState);
        $this->em->persist($keptState);
        $this->em->persist($readState);
        $this->em->flush();
        $this->em->clear();

        $pruned = $this->pruner->prune();

        // old-plain and old-read go; favorite, kept, and recent stay
        self::assertSame(2, $pruned);
        $remainingGuids = array_map(
            static fn (Entry $entry): string => $entry->getGuid(),
            $this->em->getRepository(Entry::class)->findAll(),
        );
        sort($remainingGuids);
        self::assertSame(['old-favorite', 'old-kept', 'recent'], $remainingGuids);
    }

    public function testEntryWithoutPublishedAtUsesCreatedAt(): void
    {
        $feed = new Feed('https://example.com/feed');
        $this->em->persist($feed);
        $undated = new Entry($feed, 'undated', null, 'No date', $this->clock->now()->modify('-200 days'));
        $this->em->persist($undated);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(1, $this->pruner->prune());
    }

    public function testNothingToPruneReturnsZero(): void
    {
        self::assertSame(0, $this->pruner->prune());
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (`EntryPruner` not found):
`php vendor/bin/phpunit tests/Service/EntryPrunerTest.php`

- [ ] **Step 3: Implement**

`src/Service/EntryPruner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Retention: deletes entries older than 90 days unless any user marked them
 * favorite or kept. Selects ids first, then deletes in chunks — portable
 * across SQLite and MySQL. Read-state rows die with their entry via the DB
 * FK cascade.
 */
final class EntryPruner
{
    private const RETENTION_DAYS = 90;
    private const DELETE_CHUNK_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function prune(): int
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d days', self::RETENTION_DAYS));

        /** @var list<int> $ids */
        $ids = $this->em->createQuery(sprintf(
            'SELECT e.id FROM %s e
             WHERE COALESCE(e.publishedAt, e.createdAt) < :cutoff
             AND NOT EXISTS (
                 SELECT s FROM %s s
                 WHERE s.entry = e AND (s.isFavorite = true OR s.isKept = true)
             )',
            Entry::class,
            EntryState::class,
        ))->setParameter('cutoff', $cutoff)->getSingleColumnResult();

        if ($ids === []) {
            return 0;
        }

        foreach (array_chunk($ids, self::DELETE_CHUNK_SIZE) as $chunk) {
            $this->em->createQuery(sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', Entry::class))
                ->setParameter('ids', $chunk)
                ->execute();
        }

        return \count($ids);
    }
}
```

**Note:** if DQL rejects `SELECT s` on the composite-key entity inside `EXISTS`, use `SELECT IDENTITY(s.user)` instead — same semantics here.

- [ ] **Step 4: Run it — expect PASS.**

- [ ] **Step 5: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/EntryPruner.php tests/Service/EntryPrunerTest.php
git commit -m "Add EntryPruner: 90-day retention, favorite/kept protected"
```

---

### Task 12: RefreshRunner

**Files:**
- Create: `backend/src/Service/Refresh/FeedOutcome.php`
- Create: `backend/src/Service/Refresh/RefreshRequest.php`
- Create: `backend/src/Service/Refresh/RefreshReport.php`
- Create: `backend/src/Service/Refresh/RefreshRunner.php`
- Create: `backend/tests/Support/StubFeedFetcher.php`
- Test: `backend/tests/Service/Refresh/RefreshRunnerTest.php`

- [ ] **Step 1: Create the test support fetcher**

`tests/Support/StubFeedFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use Symfony\Component\Clock\MockClock;

final class StubFeedFetcher implements FeedFetcherInterface
{
    /** @var array<string, FetchResponse|FetchException> */
    private array $results = [];

    /** @var list<string> */
    public array $fetchedUrls = [];

    public int $secondsPerFetch = 0;

    public function __construct(private readonly ?MockClock $clock = null)
    {
    }

    public function willReturn(string $url, FetchResponse $response): void
    {
        $this->results[$url] = $response;
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->results[$url] = $exception;
    }

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FetchResponse
    {
        $this->fetchedUrls[] = $url;
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        $result = $this->results[$url] ?? throw new \LogicException('No stubbed result for ' . $url);
        if ($result instanceof FetchException) {
            throw $result;
        }

        return $result;
    }
}
```

- [ ] **Step 2: Write the failing test** (integration — extends `DbTestCase`)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Enum\FeedStatus;
use App\Repository\EntryRepository;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\EntrySanitizer;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\AtomParser;
use App\Service\Parser\FeedParser;
use App\Service\Parser\Rss1Parser;
use App\Service\Parser\Rss2Parser;
use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RefreshRunnerTest extends DbTestCase
{
    private MockClock $clock;
    private StubFeedFetcher $fetcher;
    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-07-21 12:00:00', 'UTC');
        $this->fetcher = new StubFeedFetcher($this->clock);
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    private function runner(): RefreshRunner
    {
        /** @var FeedRepository $feedRepository */
        $feedRepository = $this->em->getRepository(Feed::class);
        /** @var EntryRepository $entryRepository */
        $entryRepository = $this->em->getRepository(Entry::class);

        return new RefreshRunner(
            $feedRepository,
            $this->em,
            $this->fetcher,
            new FeedParser(new Rss2Parser(), new AtomParser(), new Rss1Parser()),
            new EntryIngestor($this->em, $entryRepository, new EntrySanitizer(), $this->clock),
            new FeedScheduler($this->clock),
            new EntryPruner($this->em, $this->clock),
            $this->lockFactory,
            $this->clock,
            new NullLogger(),
        );
    }

    private function dueFeed(string $url): Feed
    {
        $feed = new Feed($url);
        $feed->setNextFetchAt($this->clock->now()->modify('-1 hour'));
        $this->em->persist($feed);

        return $feed;
    }

    private function rss(string $title, string $guid): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>{$title}</title>
            <item><title>Post</title><link>https://example.com/p</link><guid>{$guid}</guid></item>
            </channel></rss>
            XML;
    }

    public function testRefreshesDueFeedsAndReports(): void
    {
        $feedA = $this->dueFeed('https://a.example.com/feed');
        $feedB = $this->dueFeed('https://b.example.com/feed');
        $this->em->flush();

        $this->fetcher->willReturn($feedA->getUrl(), FetchResponse::fetched($feedA->getUrl(), false, $this->rss('A', 'a-1'), '"etag-a"', null));
        $this->fetcher->willReturn($feedB->getUrl(), FetchResponse::notModified($feedB->getUrl(), false, null, null));

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('completed', $report->status);
        self::assertSame(2, $report->total);
        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->notModified);
        self::assertSame(0, $report->failed);
        self::assertSame(0, $report->remaining);

        self::assertSame('"etag-a"', $feedA->getEtag());
        self::assertSame('A', $feedA->getTitle());
        self::assertNotNull($feedA->getNextFetchAt());
        self::assertGreaterThan($this->clock->now(), $feedA->getNextFetchAt());
        self::assertCount(1, $this->em->getRepository(Entry::class)->findAll());
    }

    public function testFailedFeedIsRecordedAndOthersContinue(): void
    {
        $bad = $this->dueFeed('https://bad.example.com/feed');
        $good = $this->dueFeed('https://good.example.com/feed');
        $this->em->flush();

        $this->fetcher->willThrow($bad->getUrl(), new FeedUnreachableException('connection refused'));
        $this->fetcher->willReturn($good->getUrl(), FetchResponse::fetched($good->getUrl(), false, $this->rss('G', 'g-1'), null, null));

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->fetched);
        self::assertSame(1, $report->failed);
        self::assertSame(FeedStatus::Erroring, $bad->getStatus());
        self::assertSame(1, $bad->getConsecutiveFailures());
        self::assertStringContainsString('connection refused', (string) $bad->getLastErrorMessage());
    }

    public function testBudgetExhaustionSkipsRemainingFeeds(): void
    {
        $first = $this->dueFeed('https://one.example.com/feed');
        $second = $this->dueFeed('https://two.example.com/feed');
        $third = $this->dueFeed('https://three.example.com/feed');
        $this->em->flush();

        foreach ([$first, $second, $third] as $index => $feed) {
            $this->fetcher->willReturn($feed->getUrl(), FetchResponse::fetched($feed->getUrl(), false, $this->rss('F' . $index, 'g-' . $index), null, null));
        }
        $this->fetcher->secondsPerFetch = 100;

        $report = $this->runner()->run(RefreshRequest::allDue(205));

        // 100 s + 100 s spent leaves 5 s — below the 10 s safety margin, so
        // the third feed is skipped
        self::assertSame('partial', $report->status);
        self::assertSame(2, $report->fetched);
        self::assertSame(1, $report->skippedForBudget);
        self::assertSame(1, $report->remaining);
        self::assertCount(2, $this->fetcher->fetchedUrls);
    }

    public function testBusyWhenLockIsHeld(): void
    {
        $lock = $this->lockFactory->createLock('feed-refresh');
        self::assertTrue($lock->acquire());

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('busy', $report->status);
        self::assertSame(0, $report->total);
        $lock->release();
    }

    public function testPermanentRedirectUpdatesFeedUrl(): void
    {
        $feed = $this->dueFeed('https://old.example.com/feed');
        $this->em->flush();

        $this->fetcher->willReturn($feed->getUrl(), FetchResponse::fetched('https://new.example.com/feed', true, $this->rss('Moved', 'm-1'), null, null));

        $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame('https://new.example.com/feed', $feed->getUrl());
    }

    public function testAllDueRunPrunesOldEntries(): void
    {
        $feed = $this->dueFeed('https://a.example.com/feed');
        $ancient = new Entry($feed, 'ancient', null, 'Ancient', $this->clock->now()->modify('-200 days'));
        $ancient->setPublishedAt($this->clock->now()->modify('-200 days'));
        $this->em->persist($ancient);
        $this->em->flush();

        $this->fetcher->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));

        $report = $this->runner()->run(RefreshRequest::allDue(300));

        self::assertSame(1, $report->pruned);
    }
}
```

- [ ] **Step 3: Run it — expect FAIL** (classes not found):
`php vendor/bin/phpunit tests/Service/Refresh/RefreshRunnerTest.php`

- [ ] **Step 4: Implement the Refresh namespace**

`src/Service/Refresh/FeedOutcome.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

enum FeedOutcome
{
    case Fetched;
    case NotModified;
    case Failed;
}
```

`src/Service/Refresh/RefreshRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

final readonly class RefreshRequest
{
    private function __construct(
        public ?int $userId,
        public ?int $feedId,
        public bool $force,
        public int $budgetSeconds,
        public bool $prune,
    ) {
    }

    public static function allDue(int $budgetSeconds, bool $prune = true, bool $force = false): self
    {
        return new self(null, null, $force, $budgetSeconds, $prune);
    }

    public static function forUser(int $userId, int $budgetSeconds): self
    {
        return new self($userId, null, true, $budgetSeconds, false);
    }

    public static function forFeed(int $feedId, int $budgetSeconds): self
    {
        return new self(null, $feedId, true, $budgetSeconds, false);
    }
}
```

`src/Service/Refresh/RefreshReport.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

final readonly class RefreshReport
{
    private function __construct(
        public string $status,
        public int $total,
        public int $fetched,
        public int $notModified,
        public int $failed,
        public int $skippedForBudget,
        public int $remaining,
        public int $pruned,
    ) {
    }

    public static function busy(): self
    {
        return new self('busy', 0, 0, 0, 0, 0, 0, 0);
    }

    public static function finished(
        int $total,
        int $fetched,
        int $notModified,
        int $failed,
        int $skippedForBudget,
        int $remaining,
        int $pruned,
    ): self {
        return new self(
            $remaining > 0 ? 'partial' : 'completed',
            $total,
            $fetched,
            $notModified,
            $failed,
            $skippedForBudget,
            $remaining,
            $pruned,
        );
    }

    /**
     * @return array{status: string, total: int, fetched: int, notModified: int,
     *     failed: int, skippedForBudget: int, remaining: int, pruned: int}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'total' => $this->total,
            'fetched' => $this->fetched,
            'notModified' => $this->notModified,
            'failed' => $this->failed,
            'skippedForBudget' => $this->skippedForBudget,
            'remaining' => $this->remaining,
            'pruned' => $this->pruned,
        ];
    }
}
```

`src/Service/Refresh/RefreshRunner.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Entity\Feed;
use App\Repository\FeedRepository;
use App\Service\EntryIngestor;
use App\Service\EntryPruner;
use App\Service\FeedScheduler;
use App\Service\Fetch\Exception\FeedGoneException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * The one refresh implementation behind all three callers (CLI, maintenance
 * endpoint, user endpoint). Globally lock-guarded, budget-bound, flushes per
 * feed so a budget exit never loses committed work.
 */
final class RefreshRunner
{
    private const LOCK_NAME = 'feed-refresh';
    private const LOCK_TTL_SECONDS = 300.0;
    private const BATCH_LIMIT = 50;
    private const SAFETY_MARGIN_SECONDS = 10;
    private const COOLDOWN_MINUTES = 5;

    public function __construct(
        private readonly FeedRepository $feedRepository,
        private readonly EntityManagerInterface $em,
        private readonly FeedFetcherInterface $fetcher,
        private readonly FeedParser $parser,
        private readonly EntryIngestor $ingestor,
        private readonly FeedScheduler $scheduler,
        private readonly EntryPruner $pruner,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function run(RefreshRequest $request): RefreshReport
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME, self::LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            return RefreshReport::busy();
        }

        try {
            return $this->refresh($request);
        } finally {
            $lock->release();
        }
    }

    private function refresh(RefreshRequest $request): RefreshReport
    {
        $now = $this->clock->now();
        $deadline = $now->getTimestamp() + $request->budgetSeconds;
        $cooldownCutoff = $request->force
            ? $now->modify(sprintf('-%d minutes', self::COOLDOWN_MINUTES))
            : null;

        $feeds = $this->feedRepository->findDue(
            $now,
            self::BATCH_LIMIT,
            $request->userId,
            $request->feedId,
            $request->force,
            $cooldownCutoff,
        );

        $fetched = 0;
        $notModified = 0;
        $failed = 0;
        $skippedForBudget = 0;

        foreach ($feeds as $index => $feed) {
            if ($deadline - $this->clock->now()->getTimestamp() < self::SAFETY_MARGIN_SECONDS) {
                $skippedForBudget = \count($feeds) - $index;
                break;
            }

            match ($this->refreshFeed($feed)) {
                FeedOutcome::Fetched => $fetched++,
                FeedOutcome::NotModified => $notModified++,
                FeedOutcome::Failed => $failed++,
            };
        }

        $remaining = $this->feedRepository->countDue(
            $this->clock->now(),
            $request->userId,
            $request->feedId,
            $request->force,
            $cooldownCutoff,
        );

        $pruned = $request->prune ? $this->pruner->prune() : 0;

        return RefreshReport::finished(\count($feeds), $fetched, $notModified, $failed, $skippedForBudget, $remaining, $pruned);
    }

    private function refreshFeed(Feed $feed): FeedOutcome
    {
        try {
            $response = $this->fetcher->fetch($feed->getUrl(), $feed->getEtag(), $feed->getLastModified());

            if ($response->notModified) {
                $this->scheduler->recordSuccess($feed, 0);
                $this->em->flush();

                return FeedOutcome::NotModified;
            }

            $parsed = $this->parser->parse((string) $response->body);
            $created = $this->ingestor->ingest($feed, $parsed);

            $feed->setEtag($response->etag);
            $feed->setLastModified($response->lastModified);
            $this->applyPermanentRedirect($feed, $response);
            $this->scheduler->recordSuccess($feed, $created);
            $this->em->flush();

            return FeedOutcome::Fetched;
        } catch (FeedGoneException $e) {
            $this->scheduler->recordGone($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed gone: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        } catch (FetchException | FeedParseException $e) {
            $this->scheduler->recordFailure($feed, $e->getMessage());
            $this->em->flush();
            $this->logger->warning('Feed refresh failed: {url}', ['url' => $feed->getUrl(), 'exception' => $e]);

            return FeedOutcome::Failed;
        }
    }

    private function applyPermanentRedirect(Feed $feed, FetchResponse $response): void
    {
        if (!$response->permanentRedirect || $response->finalUrl === $feed->getUrl()) {
            return;
        }
        // Only adopt the new URL if no other feed already claims it (unique index).
        if ($this->feedRepository->findOneBy(['url' => $response->finalUrl]) !== null) {
            return;
        }
        $feed->setUrl($response->finalUrl);
    }
}
```

- [ ] **Step 5: Run it — expect PASS.**

- [ ] **Step 6: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Service/Refresh tests/Service/Refresh tests/Support
git commit -m "Add RefreshRunner: lock-guarded, budget-bound, resumable"
```

---

### Task 13: CLI command app:feeds:refresh

**Files:**
- Create: `backend/src/Command/RefreshFeedsCommand.php`
- Test: `backend/tests/Command/RefreshFeedsCommandTest.php`

- [ ] **Step 1: Write the failing test** (extends `DbTestCase` for the schema + DAMA rollback)

```php
<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Feed;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Tests\DbTestCase;
use App\Tests\Support\StubFeedFetcher;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RefreshFeedsCommandTest extends DbTestCase
{
    public function testRefreshesDueFeedsAndPrintsReport(): void
    {
        $feed = new Feed('https://cli.example.com/feed');
        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($feed);
        $this->em->flush();

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        self::getContainer()->set(FeedFetcherInterface::class, $stub);

        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:feeds:refresh'));
        $exitCode = $tester->execute(['--budget' => '60']);

        self::assertSame(0, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('status', $display);
        self::assertStringContainsString('completed', $display);
        self::assertStringContainsString('notModified', $display);
        self::assertSame([$feed->getUrl()], $stub->fetchedUrls);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (command not found):
`php vendor/bin/phpunit tests/Command/RefreshFeedsCommandTest.php`

- [ ] **Step 3: Implement**

`src/Command/RefreshFeedsCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:feeds:refresh', description: 'Fetch due feeds and ingest new entries')]
final class RefreshFeedsCommand extends Command
{
    public function __construct(private readonly RefreshRunner $refreshRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Time budget in seconds', '180')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore the schedule (5-minute cooldown still applies)')
            ->addOption('feed', null, InputOption::VALUE_REQUIRED, 'Refresh a single feed by id')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Refresh only feeds this user id subscribes to')
            ->addOption('no-prune', null, InputOption::VALUE_NONE, 'Skip retention pruning');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $budgetOption = $input->getOption('budget');
        $budget = max(1, \is_string($budgetOption) ? (int) $budgetOption : 180);
        $feedOption = $input->getOption('feed');
        $userOption = $input->getOption('user');

        $request = match (true) {
            \is_string($feedOption) => RefreshRequest::forFeed((int) $feedOption, $budget),
            \is_string($userOption) => RefreshRequest::forUser((int) $userOption, $budget),
            default => RefreshRequest::allDue(
                $budget,
                prune: !(bool) $input->getOption('no-prune'),
                force: (bool) $input->getOption('force'),
            ),
        };

        $report = $this->refreshRunner->run($request);

        if ($report->status === 'busy') {
            $io->warning('Another refresh run is already in progress.');

            return Command::SUCCESS;
        }

        foreach ($report->toArray() as $key => $value) {
            $io->writeln(sprintf('%-18s %s', $key, (string) $value));
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run it — expect PASS.**

- [ ] **Step 5: Gates and commit**

```bash
composer check && php vendor/bin/phpunit
git add src/Command tests/Command
git commit -m "Add app:feeds:refresh command"
```

---

### Task 14: Maintenance refresh endpoint

**Files:**
- Create: `backend/src/Controller/MaintenanceController.php`
- Test: `backend/tests/Controller/MaintenanceControllerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Feed;
use App\Service\Fetch\FeedFetcherInterface;
use App\Service\Fetch\FetchResponse;
use App\Tests\Support\StubFeedFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MaintenanceControllerTest extends WebTestCase
{
    public function testRejectsMissingToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/refresh');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsWrongToken(): void
    {
        $client = self::createClient();
        $client->request('POST', '/maintenance/refresh?token=wrong');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRefreshesWithValidToken(): void
    {
        $client = self::createClient();

        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $feed = new Feed('https://maint.example.com/feed');
        $feed->setNextFetchAt(new \DateTimeImmutable('-1 hour'));
        $em->persist($feed);
        $em->flush();

        $stub = new StubFeedFetcher();
        $stub->willReturn($feed->getUrl(), FetchResponse::notModified($feed->getUrl(), false, null, null));
        self::getContainer()->set(FeedFetcherInterface::class, $stub);

        // token from .env.test
        $client->request('POST', '/maintenance/refresh?token=test-maintenance-token');

        self::assertResponseIsSuccessful();
        /** @var array{status: string, notModified: int} $payload */
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('completed', $payload['status']);
        self::assertSame(1, $payload['notModified']);
    }

    public function testGetMethodIsNotAllowed(): void
    {
        $client = self::createClient();
        $client->request('GET', '/maintenance/refresh?token=test-maintenance-token');

        self::assertResponseStatusCodeSame(405);
    }
}
```

- [ ] **Step 2: Run it — expect FAIL** (404, route does not exist):
`php vendor/bin/phpunit tests/Controller/MaintenanceControllerTest.php`

- [ ] **Step 3: Implement**

`src/Controller/MaintenanceController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Refresh\RefreshRequest;
use App\Service\Refresh\RefreshRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine-facing maintenance actions, authenticated by a shared token
 * (constant-time comparison) instead of JWT. Called by the scheduled GitHub
 * Actions pinger or any external cron service.
 */
final class MaintenanceController
{
    private const REFRESH_BUDGET_SECONDS = 20;

    public function __construct(
        #[Autowire('%env(MAINTENANCE_TOKEN)%')]
        private readonly string $maintenanceToken,
        private readonly RefreshRunner $refreshRunner,
    ) {
    }

    #[Route('/maintenance/refresh', name: 'maintenance_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $token = (string) $request->query->get('token', '');
        if ($this->maintenanceToken === '' || !hash_equals($this->maintenanceToken, $token)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $report = $this->refreshRunner->run(RefreshRequest::allDue(self::REFRESH_BUDGET_SECONDS));

        return new JsonResponse(
            $report->toArray(),
            $report->status === 'busy' ? Response::HTTP_CONFLICT : Response::HTTP_OK,
        );
    }
}
```

- [ ] **Step 4: Run it — expect PASS.**

- [ ] **Step 5: Full gates, full suite, commit**

```bash
php bin/console cache:warmup && composer check && php vendor/bin/phpunit
git add src/Controller tests/Controller
git commit -m "Add token-protected maintenance refresh endpoint"
```

---

## Final verification (after all tasks)

- [ ] Full suite green: `php vendor/bin/phpunit` (expect ~60+ tests)
- [ ] Gates green: `php bin/console cache:warmup && composer check`
- [ ] CI matrix must pass on **both** SQLite and MySQL after push — the `COALESCE`/`EXISTS` DQL and the pruner subquery are exactly the portability-sensitive spots the matrix exists for.
- [ ] Dispatch the final whole-branch code reviewer per superpowers:subagent-driven-development.

## Deferred (explicitly not in this plan)

- **Feed autodiscovery** (HTML `<link rel="alternate">` scan) — Plan 4, with `POST /subscriptions`.
- **User refresh endpoint** `POST /api/refresh` + rate limiting — Plan 4 (needs JWT auth from Plan 3). `RefreshRequest::forUser` is ready for it.
  **Carry these forward from Task 12's review:**
  - Give the user slice a budget **well above** `SAFETY_MARGIN_SECONDS` (10 s). At a 10 s budget the runner completes exactly one feed per HTTP call, so a 50-feed user needs 50 round trips. ~30 s is the sensible floor; alternatively make the margin proportional to the budget.
  - The client progress loop must treat `status: 'aborted'` as terminal (persistence is broken — stop and surface an error), distinct from `'partial'` (call again). Looping on `'aborted'` would spin forever.
- **Scheduled GitHub Actions pinger** (`refresh.yml`) — Plan 6 (deployment).
- **MaintenanceTokenAuthenticator** as a proper security authenticator — Plan 3, when security-bundle lands; the controller check moves there if it pulls its weight.
