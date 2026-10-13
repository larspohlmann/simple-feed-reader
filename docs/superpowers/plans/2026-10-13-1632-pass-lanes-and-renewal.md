# One engine run per pass, per-kind lanes, typed slots (#1632) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A refresh pass reads all its feeds on exactly one engine run: a stale Bluesky session renews inside that run
and its reads go out once more there; a feed's kind is decided once, as its lane; and slot kinds are types that never
share a cap.

**Architecture:** Slots become a Fetch model interface with one implementation per kind (`HostSlotModel` in Fetch, the
account and server slots in Social), and `RequestSlots` keys each count by the slot's class. The engine takes one
pass-side `AnswerSettlerInterface` instead of the dispatch gate: it asks `admits()` before every first send and routes
every raw outcome through `settle()`, which yields the key's finished value or a follow-up ticket that `FetchQueue`
sends as a fresh attempt for the same key, never through the budgeted ticket source. `TimelineRound::settle()` then
renews a stale session in place (once per account per pass, a feed's second stale answer is a refusal), and
`ConcurrentPassFetcher` loses its second run. Finally `PassFeeds` hands each feed id its lane (`FeedLane` or
`TimelineLane`), `PassTickets` delegates every call to the key's lane, and the seam yields a
`PassOutcomeModelInterface` that stores itself through `PassOutcomeStore`.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Symfony HttpClient (`MockHttpClient`), PHPUnit 12, PHPStan max (generics),
PHPMD, phptramp, Infection.

**Spec:** `docs/superpowers/specs/2026-10-13-1632-pass-lanes-and-renewal-design.md` (binding). Read it before you start
a task. Where this plan rules on something the spec leaves open, the ruling is listed under "Preflight notes" at the end.

## Global Constraints

- CLAUDE.md applies in full: Clean Code, names that reveal intent, no abbreviations, guard clauses, few parameters,
  no boolean flag parameters, `final readonly` by default, `declare(strict_types=1)`, PSR-12, `Response::HTTP_*` for
  every status in `src` and `tests` (never a literal).
- Role folders (docs/architecture.md §10): `Model/` for data, `Pass/` for per-call objects, `Support/` for static
  helpers, an interface's folder named after it. Inside a mixed `Model/` an interface and its family sit in a subfolder
  named after the interface without `ModelInterface` (`RoleFolderInterfaces`).
- Module cycles (§9, `ServiceModuleCycleRule`): `Service/Fetch` imports nothing from `Service/Social` or
  `Service/Refresh`; `Service/Social` imports nothing from `Service/Refresh`. After each task, from `backend/`:
  `grep -rnE 'App\\Service\\(Social|Refresh)' src/Service/Fetch; grep -rn 'App\\Service\\Refresh' src/Service/Social`
  must print nothing.
- **No needless comments.** One line where a future reader would get the code wrong without it; delete a comment a
  moved line made false. No `@param`/`@return` that repeats the signature (generic and array shapes are not repeats).
- Every line is at most 120 columns.
- Every `src` file you touch is PHPMD-clean (`composer md`): `TooManyPublicMethods` is 10.
- phptramp: a value forwarded through 4 or more methods across 2 or more classes fails, 3 warns. Hold it in a field.
- `AbbreviatedNameRule`, `NoCollaboratorDefaultRule`, `InvocationMatchersOnThisRule`, `ServiceRoleRule` stay clean.
- **The lazy-start invariant.** A feed counts as started only when the engine pulls its ticket. Never map, buffer or
  `iterator_to_array` a ticket stream ahead of the engine in `src`. A follow-up never comes from the ticket source.
- **Every started feed yields exactly one outcome.** A follow-up yields nothing itself; its key yields once, when it
  settles to a value.
- Avoid `(new X())->call()` in `src`: pdepend, which `composer md` runs, cannot parse it.
- Commands run from `backend/` unless a step says "from the repo root". **Git commands run from the repo root.**
- Commits are `type(#1632): …`. No `Co-Authored-By`, no "Generated with", no session link. **Do not push.**
- **At the start of every task after Task 1:** from the repo root, `git status`, then
  `git fetch origin develop && git merge --no-edit origin/develop`. Resolve any conflict, then rerun the previous
  task's filtered tests before writing code.
- Concurrent sessions share this checkout. Never `git checkout --`, `git reset` or `git stash`. Undo a break test by
  editing the line back by hand.
- No migration, and never touch the dev database.
- `composer stan` needs a warm cache: run `bin/console cache:warmup` after every `config/` change.
- Never run two test suites at once.
- Never deploy.

## File structure

| File | Task | Responsibility |
|---|---|---|
| `src/Service/Fetch/Model/RequestSlot/RequestSlotModelInterface.php` (create) | 1 | one cap a ticket holds in flight |
| `src/Service/Fetch/Model/RequestSlot/HostSlotModel.php` (create) | 1 | the host the attempt has reached |
| `src/Service/Fetch/Model/RequestSlotModel.php` (delete) | 1 | — |
| `src/Service/Fetch/Model/RequestTicketModel.php` (modify) | 1 | slots typed by the interface |
| `src/Service/Fetch/Pass/RequestSlots.php` (modify) | 1 | counts keyed by slot class and key |
| `src/Service/Social/Model/SocialAccountSlotModel.php`, `SocialServerSlotModel.php` (create) | 1 | a timeline read's two caps |
| `src/Service/Social/Model/TimelineSlotsModel.php` (modify) | 1 | builds the ticket with both |
| `tests/Support/FixedKeySlot.php` (create) | 1 | test-only fixed-key slot |
| `src/Service/Fetch/AnswerSettler/AnswerSettlerInterface.php`, `PassThroughSettler.php` (create) | 2 | the engine's pass-side collaborator |
| `src/Service/Fetch/Model/SettlementModel.php` (create) | 2 | a finished value or a follow-up ticket |
| `src/Service/Fetch/Pass/SettledAnswers.php` (create) | 2 | one run's settler and queue |
| `src/Service/Fetch/Pass/FetchQueue.php` (modify) | 2 | `followUp()` |
| `src/Service/Fetch/ConcurrentRequestEngine.php` (modify) | 2 | settles every outcome |
| `src/Service/Fetch/DispatchGate/` (delete) | 2 | — |
| `src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php`, `config/services.yaml` (modify) | 2 | pass-through settler |
| `tests/Support/ScriptedSettler.php` (create) | 2 | engine-test settler |
| `src/Service/Social/Pass/{TimelineRound,TimelineAccounts}.php` (modify) | 2, 3 | renewal inside the run |
| `src/Service/Refresh/Pass/PassTickets.php`, `PassFetcher/ConcurrentPassFetcher.php` (modify) | 2, 3, 4 | one loop, lanes |
| `tests/Support/CountingArrayAdapter.php` (create) | 3 | counts dropped sessions |
| `docs/security.md` (modify) | 3 | renewal sentence |
| `src/Service/Refresh/Pass/PassLane/{PassLaneInterface,FeedLane,TimelineLane}.php` (create) | 4 | per-kind lanes |
| `src/Service/Refresh/Model/PassOutcome/{PassOutcomeModelInterface,FeedPassOutcomeModel,TimelinePassOutcomeModel}.php` (create) | 4 | an outcome that stores itself |
| `src/Service/Refresh/Pass/{PassFeeds,PassOutcomeStore}.php`, `PassFetcher/PassFetcherInterface.php`, `RefreshPassRecorder.php` (modify) | 4 | lanes decided once, typed store |
| `tests/Support/StubPassFetcher.php` (modify) | 4 | wraps scripted outcomes |

---

### Task 1: Slot kinds are types

**Files:**
- Create: `backend/src/Service/Fetch/Model/RequestSlot/RequestSlotModelInterface.php`
- Create: `backend/src/Service/Fetch/Model/RequestSlot/HostSlotModel.php`
- Delete: `backend/src/Service/Fetch/Model/RequestSlotModel.php`
- Modify: `backend/src/Service/Fetch/Model/RequestTicketModel.php`
- Modify: `backend/src/Service/Fetch/Pass/RequestSlots.php`
- Create: `backend/src/Service/Social/Model/SocialAccountSlotModel.php`
- Create: `backend/src/Service/Social/Model/SocialServerSlotModel.php`
- Modify: `backend/src/Service/Social/Model/TimelineSlotsModel.php`
- Create: `backend/tests/Support/FixedKeySlot.php`
- Create: `backend/tests/Service/Fetch/Model/RequestSlot/HostSlotModelTest.php`
- Delete: `backend/tests/Service/Fetch/Model/RequestSlotModelTest.php`
- Create: `backend/tests/Service/Social/Model/SocialAccountSlotModelTest.php`
- Create: `backend/tests/Service/Social/Model/SocialServerSlotModelTest.php`
- Modify: `backend/tests/Service/Fetch/Pass/RequestSlotsTest.php`, `backend/tests/Service/Fetch/Model/RequestTicketModelTest.php`,
  `backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php`, `backend/tests/Service/Social/TimelineRoundsTest.php`,
  `backend/tests/Service/Refresh/Pass/PassTicketsTest.php`

**Interfaces:**
- Consumes: `CapacityGuard::atLeastOne(int $capacity, string $name): void`, `HostKey::forUrl(string $url): string`.
- Produces:
  - `App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface` — `keyFor(string $url): string`, `capacity(): int`.
  - `App\Service\Fetch\Model\RequestSlot\HostSlotModel::__construct(int $capacity)`.
  - `App\Service\Social\Model\SocialAccountSlotModel::__construct(int $accountId, int $capacity)`.
  - `App\Service\Social\Model\SocialServerSlotModel::__construct(string $requestUrl, int $capacity)`.
  - `RequestTicketModel::$slots` is `non-empty-list<RequestSlotModelInterface>`;
    `RequestTicketModel::api(ApiRequestModel $request, list<RequestSlotModelInterface> $slots)`;
    `RequestTicketModel::feed(FetchTicketModel $ticket, int $hostCapacity)` unchanged.
  - `App\Tests\Support\FixedKeySlot::__construct(string $key, int $capacity)`.

- [ ] **Step 1: Write the failing slot-model tests**

`backend/tests/Service/Fetch/Model/RequestSlot/HostSlotModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model\RequestSlot;

use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use PHPUnit\Framework\TestCase;

final class HostSlotModelTest extends TestCase
{
    public function testItsKeyIsTheHostTheAttemptHasReached(): void
    {
        $slot = new HostSlotModel(2);

        self::assertSame('example.com', $slot->keyFor('https://www.example.com:443/feed'));
        self::assertSame('other.example.com', $slot->keyFor('https://other.example.com/feed'));
    }

    public function testCarriesItsCapacity(): void
    {
        $slot = new HostSlotModel(2);

        self::assertSame(2, $slot->capacity());
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        new HostSlotModel(0);
    }
}
```

`backend/tests/Service/Social/Model/SocialAccountSlotModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Social\Model\SocialAccountSlotModel;
use PHPUnit\Framework\TestCase;

final class SocialAccountSlotModelTest extends TestCase
{
    public function testItsKeyIsTheAccountWhateverTheUrl(): void
    {
        $slot = new SocialAccountSlotModel(7, 3);

        self::assertSame('7', $slot->keyFor('https://one.example.com/a'));
        self::assertSame('7', $slot->keyFor('https://two.example.com/b'));
    }

    public function testCarriesItsCapacity(): void
    {
        $slot = new SocialAccountSlotModel(7, 3);

        self::assertSame(3, $slot->capacity());
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        new SocialAccountSlotModel(7, 0);
    }
}
```

`backend/tests/Service/Social/Model/SocialServerSlotModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Model;

use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\RequestSlots;
use App\Service\Social\Model\SocialServerSlotModel;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\TestCase;

final class SocialServerSlotModelTest extends TestCase
{
    private const string HOME = 'https://mastodon.example/api/v1/timelines/home';

    public function testItsKeyIsTheRequestsHostFixedAtConstruction(): void
    {
        $slot = new SocialServerSlotModel('https://www.Mastodon.example:443/api/v1/timelines/home', 4);

        self::assertSame('mastodon.example', $slot->keyFor('https://other.example/elsewhere'));
    }

    public function testCarriesItsCapacity(): void
    {
        $slot = new SocialServerSlotModel(self::HOME, 4);

        self::assertSame(4, $slot->capacity());
    }

    public function testRejectsACapacityBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Slot capacity must be at least 1, got 0.');

        new SocialServerSlotModel(self::HOME, 0);
    }

    public function testAnRssHostSlotAndASocialServerSlotOnOneHostNeverShareACap(): void
    {
        $slots = new RequestSlots();
        $slots->acquire(self::timelineAttempt());

        $rss = FetchAttemptModel::start(2, FeedTickets::cappedAt('https://mastodon.example/@bob.rss', 1));

        self::assertFalse($slots->hasCapacityFor(self::timelineAttempt()), 'the server slot is full');
        self::assertTrue($slots->hasCapacityFor($rss), 'the RSS host slot on the same host is not');
    }

    private static function timelineAttempt(): FetchAttemptModel
    {
        return FetchAttemptModel::start(
            1,
            RequestTicketModel::api(ApiRequestModel::get(self::HOME), [new SocialServerSlotModel(self::HOME, 1)]),
        );
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'HostSlotModelTest|SocialAccountSlotModelTest|SocialServerSlotModelTest'`
Expected: errors, `Class "App\Service\Fetch\Model\RequestSlot\HostSlotModel" not found` (and the two Social classes).

- [ ] **Step 3: Create the interface and the three slot types**

`backend/src/Service/Fetch/Model/RequestSlot/RequestSlotModelInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model\RequestSlot;

/** One cap a ticket holds while in flight. Slots of two classes never share a cap, whatever their keys spell. */
interface RequestSlotModelInterface
{
    public function keyFor(string $url): string;

    public function capacity(): int;
}
```

`backend/src/Service/Fetch/Model/RequestSlot/HostSlotModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model\RequestSlot;

use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\HostKey;

/** The host of whatever URL the attempt has reached, so a followed redirect counts against its new host. */
final readonly class HostSlotModel implements RequestSlotModelInterface
{
    public function __construct(private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public function keyFor(string $url): string
    {
        return HostKey::forUrl($url);
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
```

`backend/src/Service/Social/Model/SocialAccountSlotModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\CapacityGuard;

/** A timeline read's account: Bluesky and Mastodon ration requests per account. */
final readonly class SocialAccountSlotModel implements RequestSlotModelInterface
{
    public function __construct(private int $accountId, private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public function keyFor(string $url): string
    {
        return (string) $this->accountId;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
```

`backend/src/Service/Social/Model/SocialServerSlotModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\HostKey;

/** A timeline read's Mastodon instance or Bluesky PDS, all its accounts together; an API ticket never redirects. */
final readonly class SocialServerSlotModel implements RequestSlotModelInterface
{
    private string $host;

    public function __construct(string $requestUrl, private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
        $this->host = HostKey::forUrl($requestUrl);
    }

    public function keyFor(string $url): string
    {
        return $this->host;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
```

`backend/tests/Support/FixedKeySlot.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;
use App\Service\Fetch\Support\CapacityGuard;

/** A slot whose key is fixed whatever URL its attempt has reached: an engine or slot test's named cap. */
final readonly class FixedKeySlot implements RequestSlotModelInterface
{
    public function __construct(private string $key, private int $capacity)
    {
        CapacityGuard::atLeastOne($capacity, 'Slot capacity');
    }

    public function keyFor(string $url): string
    {
        return $this->key;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
```

- [ ] **Step 4: Run the slot-model tests to verify they pass**

Run: `php bin/phpunit --filter 'HostSlotModelTest|SocialAccountSlotModelTest|SocialServerSlotModelTest'`
Expected: `testAnRssHostSlotAndASocialServerSlotOnOneHostNeverShareACap` still fails or errors (`RequestTicketModel::api`
takes `RequestSlotModel` and `RequestSlots` reads `->capacity`); the other eight pass.

- [ ] **Step 5: Switch the ticket and the slot counter to the interface**

`backend/src/Service/Fetch/Model/RequestTicketModel.php` — replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;

/** One request the concurrent engine sends: what goes on the wire, which exchange reads the answer, what it holds. */
final readonly class RequestTicketModel
{
    public ResponseExchangeKind $exchange;

    /** @param non-empty-list<RequestSlotModelInterface> $slots */
    private function __construct(
        public ApiRequestModel $request,
        public array $slots,
        private ?FetchTicketModel $feedTicket,
    ) {
        $this->exchange = null === $feedTicket ? ResponseExchangeKind::Api : ResponseExchangeKind::Feed;
    }

    public static function feed(FetchTicketModel $ticket, int $hostCapacity): self
    {
        return new self(
            ApiRequestModel::get($ticket->url, self::validatorHeaders($ticket)),
            [new HostSlotModel($hostCapacity)],
            $ticket,
        );
    }

    /**
     * Any status comes back as the answer; the ticket goes out only while every slot it names has room.
     *
     * @param list<RequestSlotModelInterface> $slots
     *
     * @throws \InvalidArgumentException when no slot is named, which would leave the ticket uncapped
     */
    public static function api(ApiRequestModel $request, array $slots): self
    {
        if ([] === $slots) {
            throw new \InvalidArgumentException('An API ticket must name at least one slot.');
        }

        return new self($request, $slots, null);
    }

    public function feedTicket(): FetchTicketModel
    {
        return $this->feedTicket ?? throw new \LogicException('Only a feed ticket carries cache validators.');
    }

    /** @return array<string, string> */
    private static function validatorHeaders(FetchTicketModel $ticket): array
    {
        $headers = [];
        if (null !== $ticket->etag) {
            $headers['If-None-Match'] = $ticket->etag;
        }
        if (null !== $ticket->lastModified) {
            $headers['If-Modified-Since'] = $ticket->lastModified;
        }

        return $headers;
    }
}
```

`backend/src/Service/Fetch/Pass/RequestSlots.php` — replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\RequestSlot\RequestSlotModelInterface;

/**
 * In-flight requests per slot, counted under the slot's class and its key, so two kinds of slot never share a cap. A
 * ticket goes out only while every slot it names has room, so it waits instead of earning a 429, and it holds all of
 * them until it retires.
 */
final class RequestSlots
{
    /** @var array<string, int> */
    private array $inFlightBySlot = [];

    public function hasCapacityFor(FetchAttemptModel $attempt): bool
    {
        foreach ($attempt->ticket->slots as $slot) {
            if ($this->inFlightIn(self::keyOf($slot, $attempt->url)) >= $slot->capacity()) {
                return false;
            }
        }

        return true;
    }

    public function acquire(FetchAttemptModel $attempt): void
    {
        foreach ($this->keysOf($attempt) as $key) {
            $this->inFlightBySlot[$key] = $this->inFlightIn($key) + 1;
        }
    }

    public function release(FetchAttemptModel $attempt): void
    {
        foreach ($this->keysOf($attempt) as $key) {
            $this->inFlightBySlot[$key] = max(0, $this->inFlightIn($key) - 1);
        }
    }

    private function inFlightIn(string $key): int
    {
        return $this->inFlightBySlot[$key] ?? 0;
    }

    /** @return list<string> */
    private function keysOf(FetchAttemptModel $attempt): array
    {
        $keys = [];
        foreach ($attempt->ticket->slots as $slot) {
            $keys[] = self::keyOf($slot, $attempt->url);
        }

        return $keys;
    }

    private static function keyOf(RequestSlotModelInterface $slot, string $url): string
    {
        return $slot::class . "\0" . $slot->keyFor($url);
    }
}
```

`backend/src/Service/Social/Model/TimelineSlotsModel.php` — replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Model;

use App\Entity\SocialAccount;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\RequestTicketModel;

/** A timeline read holds its account's slot and its server's. */
final readonly class TimelineSlotsModel
{
    public function __construct(private int $perAccount, private int $perHost)
    {
    }

    public function ticketFor(SocialAccount $account, ApiRequestModel $request): RequestTicketModel
    {
        return RequestTicketModel::api($request, [
            new SocialAccountSlotModel($account->requireId(), $this->perAccount),
            new SocialServerSlotModel($request->url, $this->perHost),
        ]);
    }
}
```

Delete the old model and its test (from the repo root):

```bash
git rm backend/src/Service/Fetch/Model/RequestSlotModel.php backend/tests/Service/Fetch/Model/RequestSlotModelTest.php
```

- [ ] **Step 6: Move the existing tests onto the typed slots**

`backend/tests/Service/Fetch/Pass/RequestSlotsTest.php`:

1. Mechanical renames (from `backend/`):

```bash
sed -i '' \
  -e 's/use App\\Service\\Fetch\\Model\\RequestSlotModel;/use App\\Service\\Fetch\\Model\\RequestSlot\\RequestSlotModelInterface;/' \
  -e 's/RequestSlotModel::named(/new FixedKeySlot(/g' \
  -e 's/non-empty-list<RequestSlotModel>/non-empty-list<RequestSlotModelInterface>/' \
  tests/Service/Fetch/Pass/RequestSlotsTest.php
```

2. Add `use App\Tests\Support\FixedKeySlot;` after `use App\Tests\Support\FeedTickets;`.
3. Replace the three tests `testANamedSlotIsSharedWhateverTheHost`, `testANamedSlotNeverTakesAHostsCapacity` and
   `testANamedSlotSpelledLikeAHostKeyNeverTakesThatHostsCapacity` with:

```php
    public function testAFixedKeySlotIsSharedWhateverTheHost(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::apiAttempt('https://one.example.com/a', [new FixedKeySlot('account-7', 1)]));

        self::assertFalse($slots->hasCapacityFor(
            self::apiAttempt('https://two.example.com/b', [new FixedKeySlot('account-7', 1)]),
        ));
        self::assertTrue($slots->hasCapacityFor(
            self::apiAttempt('https://one.example.com/a', [new FixedKeySlot('account-8', 1)]),
        ));
    }

    public function testTwoSlotTypesWithAnEqualKeyNeverShareACap(): void
    {
        $slots = new RequestSlots();

        $slots->acquire(self::apiAttempt('https://example.com/a', [new FixedKeySlot('example.com', 1)]));

        self::assertTrue($slots->hasCapacityFor(self::attempt('https://example.com/feed', 1)));
    }
```

`backend/tests/Service/Fetch/Model/RequestTicketModelTest.php`:

1. Replace `use App\Service\Fetch\Model\RequestSlotModel;` with `use App\Service\Fetch\Model\RequestSlot\HostSlotModel;`
   and add `use App\Tests\Support\FixedKeySlot;` after the `ResponseExchangeKind` import.
2. In `testAnApiTicketIsReadAsAnAnswer` and `testAnApiTicketCarriesNoFeedTicket` replace
   `RequestSlotModel::named('account-7', 3)` with `new FixedKeySlot('account-7', 3)`.
3. Replace `testAFeedTicketHoldsOneSlotOnTheHostItsAttemptHasReached` with:

```php
    public function testAFeedTicketHoldsOneSlotOnTheHostItsAttemptHasReached(): void
    {
        $ticket = RequestTicketModel::feed(new FetchTicketModel('https://www.example.com/feed'), 2);

        self::assertEquals([new HostSlotModel(2)], $ticket->slots);
    }
```

4. In `testAnApiTicketHoldsEverySlotItNamesInOrder` replace the two slot lines with
   `$account = new FixedKeySlot('account-7', 3);` and `$server = new FixedKeySlot('server', 4);`.

`backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php` (Task 2 replaces this file whole; for now only):

```bash
sed -i '' \
  -e 's/use App\\Service\\Fetch\\Model\\RequestSlotModel;/use App\\Tests\\Support\\FixedKeySlot;/' \
  -e 's/RequestSlotModel::named(/new FixedKeySlot(/g' \
  tests/Service/Fetch/ConcurrentRequestEngineTest.php
```

Then move the `use App\Tests\Support\FixedKeySlot;` line down into the `App\Tests\Support` block so the imports stay
sorted (php-cs / PhpStorm order).

`backend/tests/Service/Social/TimelineRoundsTest.php`:

1. Replace `use App\Service\Fetch\Model\RequestTicketModel;` with
   `use App\Service\Social\Model\SocialAccountSlotModel;` and `use App\Service\Social\Model\SocialServerSlotModel;`
   (sorted after `use App\Enum\SocialFeedKind;`).
2. Replace the assertion in `testATimelineTicketHoldsItsAccountsSlotAndItsServersSlot` with:

```php
        self::assertEquals(
            [new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(self::HOME, 4)],
            $tickets[1]->slots,
        );
```

3. Delete the private `capacitiesByKey()` helper.

`backend/tests/Service/Refresh/Pass/PassTicketsTest.php`:

1. Replace `use App\Service\Fetch\Model\RequestTicketModel;` with
   `use App\Service\Fetch\Model\RequestSlot\HostSlotModel;`, and add
   `use App\Service\Social\Model\SocialAccountSlotModel;` and `use App\Service\Social\Model\SocialServerSlotModel;`.
2. In `testAnRssFeedBecomesAFeedTicketOnItsHostCarryingItsValidators` replace the last assertion with
   `self::assertEquals([new HostSlotModel(2)], $tickets[1]->slots);`.
3. In `testAPrivateFeedBecomesItsTimelineTicketOnItsAccountAndServer` replace the `assertSame([...], capacitiesByKey…)`
   with:

```php
        self::assertEquals(
            [new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(self::HOME, 4)],
            $tickets[2]->slots,
        );
```

4. Delete the private `capacitiesByKey()` helper.

- [ ] **Step 7: Run the focused tests**

Run: `php bin/phpunit --filter 'HostSlotModelTest|SocialAccountSlotModelTest|SocialServerSlotModelTest|RequestSlotsTest|RequestTicketModelTest|ConcurrentRequestEngineTest|TimelineRoundsTest|PassTicketsTest|ConcurrentPassFetcherTest|FetchQueueTest'`
Expected: all pass (`testAFeedAndATimelineOnOneHostNeverCountAgainstEachOther` included).

Run: `grep -rn 'RequestSlotModel::\|Model\\RequestSlotModel;\|social-host:\|slot:social' src tests`
Expected: no output.

- [ ] **Step 8: Gates**

Run: `composer check` — Expected: PHPCS, PHPStan, phptramp clean.
Run: `composer md` — Expected: no finding in a touched file.
Run: `composer test:parallel` — Expected: green.

- [ ] **Step 9: Commit** (from the repo root)

```bash
git add backend/src/Service/Fetch/Model/RequestSlot backend/src/Service/Fetch/Model/RequestTicketModel.php \
  backend/src/Service/Fetch/Pass/RequestSlots.php backend/src/Service/Social/Model/SocialAccountSlotModel.php \
  backend/src/Service/Social/Model/SocialServerSlotModel.php backend/src/Service/Social/Model/TimelineSlotsModel.php \
  backend/tests/Support/FixedKeySlot.php backend/tests/Service/Fetch/Model/RequestSlot \
  backend/tests/Service/Social/Model/SocialAccountSlotModelTest.php \
  backend/tests/Service/Social/Model/SocialServerSlotModelTest.php backend/tests/Service/Fetch/Pass/RequestSlotsTest.php \
  backend/tests/Service/Fetch/Model/RequestTicketModelTest.php backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php \
  backend/tests/Service/Social/TimelineRoundsTest.php backend/tests/Service/Refresh/Pass/PassTicketsTest.php
git commit -m "refactor(#1632): slot kinds are types, counted under their class so two kinds never share a cap"
```

---

### Task 2: The engine settles every answer through the pass

**Files:**
- Create: `backend/src/Service/Fetch/AnswerSettler/AnswerSettlerInterface.php`
- Create: `backend/src/Service/Fetch/AnswerSettler/PassThroughSettler.php`
- Create: `backend/src/Service/Fetch/Model/SettlementModel.php`
- Create: `backend/src/Service/Fetch/Pass/SettledAnswers.php`
- Modify: `backend/src/Service/Fetch/Pass/FetchQueue.php`
- Modify: `backend/src/Service/Fetch/ConcurrentRequestEngine.php`
- Modify: `backend/src/Service/Fetch/Model/FetchOutcomeModel.php` (one docblock)
- Modify: `backend/src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php`, `backend/config/services.yaml`
- Delete: `backend/src/Service/Fetch/DispatchGate/DispatchGateInterface.php`
- Modify: `backend/src/Service/Social/Pass/TimelineRound.php` (drops `implements`)
- Modify: `backend/src/Service/Refresh/Pass/PassTickets.php`, `backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php`
- Create: `backend/tests/Support/ScriptedSettler.php`
- Create: `backend/tests/Service/Fetch/Model/SettlementModelTest.php`
- Modify: `backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php` (replaced whole), `backend/tests/Service/Fetch/Pass/FetchQueueTest.php`,
  `backend/tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherTest.php`,
  `backend/tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php`,
  `backend/tests/Service/Fetch/FeedFetcher/HttpFeedFetcherTest.php`, `backend/tests/Service/Refresh/Pass/PassTicketsTest.php`

**Interfaces:**
- Consumes (Task 1): `RequestTicketModel`, `FixedKeySlot(string, int)`.
- Produces:
  - `App\Service\Fetch\AnswerSettler\AnswerSettlerInterface` (`@template-covariant T`):
    `admits(int|string $key): bool`; `settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel` (`SettlementModel<T>`).
  - `App\Service\Fetch\AnswerSettler\PassThroughSettler` — `AnswerSettlerInterface<FetchOutcomeModel>`.
  - `App\Service\Fetch\Model\SettlementModel` (`@template-covariant T`): `static outcome(mixed $outcome): self` (`self<V>`),
    `static followUp(RequestTicketModel $ticket): self` (`self<never>`), `isFollowUp(): bool`,
    `followUpTicket(): RequestTicketModel` (throws `\LogicException` on a value), `settledOutcome(): mixed` (`T`, throws
    `\LogicException` on a follow-up), `mapOutcome(\Closure $mapping): self` (`\Closure(T): U` → `self<U>`).
  - `FetchQueue::followUp(int|string $key, RequestTicketModel $ticket): void`.
  - `ConcurrentRequestEngine::sendAll(iterable $tickets, AnswerSettlerInterface $settler): \Generator`
    (`@template T`, `AnswerSettlerInterface<T>` → `\Generator<int|string, T>`).
  - `ConcurrentFeedFetcher::__construct(ConcurrentRequestEngine $engine, int $hostConcurrency, AnswerSettlerInterface $settler)`.
  - `App\Tests\Support\ScriptedSettler::__construct(int $openings = PHP_INT_MAX, list<int|string> $refused = [])`,
    public `$openings`, `$asked`, `$settled`, and `followUpWith(int|string $key, RequestTicketModel $ticket): void`.
  - Interim (until Task 3): `PassTickets implements AnswerSettlerInterface<FetchOutcomeModel|TimelineOutcomeModelInterface|null>`.

- [ ] **Step 0: Merge develop** (repo root): `git status`, `git fetch origin develop && git merge --no-edit origin/develop`,
  then rerun Task 1's Step 7 filter.

- [ ] **Step 1: Write the failing settlement-model test**

`backend/tests/Service/Fetch/Model/SettlementModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch\Model;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Tests\Support\FeedTickets;
use PHPUnit\Framework\TestCase;

final class SettlementModelTest extends TestCase
{
    public function testAnOutcomeCarriesItsValueAndAsksForNoFollowUp(): void
    {
        $outcome = FetchOutcomeModel::withheld();

        $settlement = SettlementModel::outcome($outcome);

        self::assertFalse($settlement->isFollowUp());
        self::assertSame($outcome, $settlement->settledOutcome());
    }

    public function testANullValueIsStillAFinishedValue(): void
    {
        $settlement = SettlementModel::outcome(null);

        self::assertFalse($settlement->isFollowUp());
        self::assertNull($settlement->settledOutcome());
    }

    public function testAFollowUpCarriesItsTicket(): void
    {
        $ticket = FeedTickets::of('https://example.com/feed');

        $settlement = SettlementModel::followUp($ticket);

        self::assertTrue($settlement->isFollowUp());
        self::assertSame($ticket, $settlement->followUpTicket());
    }

    public function testAFollowUpCarriesNoOutcomeYet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A follow-up carries no outcome yet.');

        SettlementModel::followUp(FeedTickets::of('https://example.com/feed'))->settledOutcome();
    }

    public function testAFinishedValueAsksForNoFollowUp(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A finished value asks for no follow-up.');

        SettlementModel::outcome(1)->followUpTicket();
    }

    public function testMappingChangesAFinishedValue(): void
    {
        $mapped = SettlementModel::outcome(1)->mapOutcome(static fn (int $value): int => $value + 1);

        self::assertSame(2, $mapped->settledOutcome());
    }

    public function testMappingKeepsAFollowUpsTicket(): void
    {
        $ticket = FeedTickets::of('https://example.com/feed');

        $mapped = SettlementModel::followUp($ticket)->mapOutcome(static fn (mixed $value): mixed => $value);

        self::assertSame($ticket, $mapped->followUpTicket());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit --filter SettlementModelTest`
Expected: errors, `Class "App\Service\Fetch\Model\SettlementModel" not found`.

- [ ] **Step 3: Create the settlement model, the settler interface and the pass-through settler**

`backend/src/Service/Fetch/Model/SettlementModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Model;

/**
 * What a pass made of one raw answer: the key's finished value, or one more request for the same key.
 *
 * @template-covariant T
 */
final readonly class SettlementModel
{
    /** @param list<T> $outcome empty for a follow-up, so a follow-up is a settlement of `never` */
    private function __construct(private array $outcome, private ?RequestTicketModel $followUpTicket)
    {
    }

    /**
     * @template V
     *
     * @param V $outcome
     *
     * @return self<V>
     */
    public static function outcome(mixed $outcome): self
    {
        return new self([$outcome], null);
    }

    /** @return self<never> */
    public static function followUp(RequestTicketModel $ticket): self
    {
        return new self([], $ticket);
    }

    public function isFollowUp(): bool
    {
        return null !== $this->followUpTicket;
    }

    /** @throws \LogicException when this is a finished value */
    public function followUpTicket(): RequestTicketModel
    {
        return $this->followUpTicket ?? throw new \LogicException('A finished value asks for no follow-up.');
    }

    /**
     * @return T
     *
     * @throws \LogicException when this is a follow-up
     */
    public function settledOutcome(): mixed
    {
        if ([] === $this->outcome) {
            throw new \LogicException('A follow-up carries no outcome yet.');
        }

        return $this->outcome[0];
    }

    /**
     * @template U
     *
     * @param \Closure(T): U $mapping
     *
     * @return self<U>
     */
    public function mapOutcome(\Closure $mapping): self
    {
        if (null !== $this->followUpTicket) {
            return self::followUp($this->followUpTicket);
        }

        return self::outcome($mapping($this->settledOutcome()));
    }
}
```

`backend/src/Service/Fetch/AnswerSettler/AnswerSettlerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\AnswerSettler;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;

/**
 * The pass's side of an engine run: whether a request may go on the wire, and what each raw outcome comes to.
 *
 * @template-covariant T
 */
interface AnswerSettlerInterface
{
    /**
     * Asked once per first send, a follow-up included, as it is about to go on the wire rather than when it is pulled:
     * a parked request may wait a while. A retry or a redirect of a request already on the wire is never asked about.
     */
    public function admits(int|string $key): bool;

    /** @return SettlementModel<T> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel;
}
```

`backend/src/Service/Fetch/AnswerSettler/PassThroughSettler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\AnswerSettler;

use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;

/**
 * For callers that fetch feeds without a pass: every request goes out, and every outcome is yielded as it is.
 *
 * @implements AnswerSettlerInterface<FetchOutcomeModel>
 */
final readonly class PassThroughSettler implements AnswerSettlerInterface
{
    public function admits(int|string $key): bool
    {
        return true;
    }

    /** @return SettlementModel<FetchOutcomeModel> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return SettlementModel::outcome($outcome);
    }
}
```

- [ ] **Step 4: Run the settlement test to verify it passes**

Run: `php bin/phpunit --filter SettlementModelTest`
Expected: 7 tests, OK.

- [ ] **Step 5: Write the failing queue and engine tests**

Add to `backend/tests/Service/Fetch/Pass/FetchQueueTest.php` (after `testStartsDirectAttemptsWhenNoProxyIsResolved`):

```php
    public function testAFollowUpGoesAheadOfUnstartedTicketsAsAFreshAttemptOverTheBatchProxy(): void
    {
        $proxy = new ProxyConfigModel(ProxyType::Socks5, 'proxy.example.com', 1080, null, null);
        $queue = $this->queue([
            11 => FeedTickets::of('https://one.example.com/feed'),
            22 => FeedTickets::of('https://two.example.com/feed'),
        ], batchProxy: $proxy);
        $this->runnable($queue);

        $queue->followUp(11, FeedTickets::of('https://one.example.com/again'));
        $followUp = $this->runnable($queue);

        self::assertSame(11, $followUp->key);
        self::assertSame('https://one.example.com/again', $followUp->url);
        self::assertTrue($followUp->isFirstSend());
        self::assertSame($proxy, $followUp->proxy);
    }

    public function testAFollowUpNeverAdvancesTheTicketSource(): void
    {
        $resumptions = [];
        $tickets = (static function () use (&$resumptions): \Generator {
            $resumptions[] = 1;
            yield 11 => FeedTickets::of('https://one.example.com/feed');
            $resumptions[] = 2;
            yield 22 => FeedTickets::of('https://two.example.com/feed');
        })();
        $queue = new FetchQueue($tickets, new RequestSlots(), 100);
        $queue->takeRunnable();

        $queue->followUp(11, FeedTickets::of('https://one.example.com/again'));
        $queue->takeRunnable();

        self::assertSame([1], $resumptions);
    }
```

Create `backend/tests/Support/ScriptedSettler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;

/**
 * An engine test's settler: it admits `$openings` sends and never a refused key, answers a key's scripted follow-ups
 * in turn, else passes the raw outcome through, and records every key it was asked about and settled.
 *
 * @implements AnswerSettlerInterface<FetchOutcomeModel>
 */
final class ScriptedSettler implements AnswerSettlerInterface
{
    /** @var list<int|string> */
    public array $asked = [];

    /** @var list<int|string> */
    public array $settled = [];

    /** @var array<int|string, list<RequestTicketModel>> */
    private array $followUps = [];

    /** @param list<int|string> $refused */
    public function __construct(public int $openings = \PHP_INT_MAX, private readonly array $refused = [])
    {
    }

    public function followUpWith(int|string $key, RequestTicketModel $ticket): void
    {
        $this->followUps[$key][] = $ticket;
    }

    public function admits(int|string $key): bool
    {
        $this->asked[] = $key;
        if ($this->openings < 1 || \in_array($key, $this->refused, true)) {
            return false;
        }
        --$this->openings;

        return true;
    }

    /** @return SettlementModel<FetchOutcomeModel> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        $this->settled[] = $key;
        $pending = $this->followUps[$key] ?? [];
        if ([] === $pending) {
            return SettlementModel::outcome($outcome);
        }
        $this->followUps[$key] = \array_slice($pending, 1);

        return SettlementModel::followUp($pending[0]);
    }
}
```

Replace `backend/tests/Service/Fetch/ConcurrentRequestEngineTest.php` whole (every pre-existing test is kept; a call
without a gate now passes `new PassThroughSettler()`; the three gate tests use `ScriptedSettler`; six tests are new):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Fetch;

use App\Enum\ProxyType;
use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\AnswerSettler\PassThroughSettler;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\IpValidator;
use App\Service\Fetch\Model\ApiRequestModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ProxyConfigModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\ResponseSizeLimit;
use App\Service\Fetch\ResponseClassifier;
use App\Service\Fetch\ResponseExchange\FeedResponseExchange;
use App\Service\Fetch\UrlGuard;
use App\Tests\Support\FeedTickets;
use App\Tests\Support\FetchWiring;
use App\Tests\Support\FixedKeySlot;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\ScriptedSettler;
use App\Tests\Support\StaticDnsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ConcurrentRequestEngineTest extends TestCase
{
    private const string API = 'https://api.example.test/timeline';
    private const string OTHER = 'https://other.example.test/timeline';
    private const string SECOND = 'https://other.example.test/second';
    private const string DUAL = 'https://dual.example.test/timeline';
    private const string FEED = 'https://other.example.test/feed';
    private const string IPV6 = '2a02:2e0:3fe:1001:7777:772e:2:85';
    private const string IPV4 = '193.99.144.85';

    private static function guard(): UrlGuard
    {
        return new UrlGuard(new StaticDnsResolver([
            'api.example.test' => ['93.184.216.34'],
            'other.example.test' => ['93.184.216.35'],
            'dual.example.test' => [self::IPV6, self::IPV4],
        ]), new IpValidator());
    }

    private function engine(HttpClientInterface $client, ?ProxyConfigModel $proxy = null): ConcurrentRequestEngine
    {
        return FetchWiring::requestEngine($client, self::guard(), FetchWiring::egressProxy($proxy));
    }

    private function engineWithUnreadableProxy(HttpClientInterface $client): ConcurrentRequestEngine
    {
        $egressProxySource = $this->createStub(EgressProxySourceInterface::class);
        $egressProxySource->method('egressProxy')->willThrowException(
            new SecretUnreadableException('The stored secret failed its integrity check.'),
        );

        return FetchWiring::requestEngine($client, self::guard(), $egressProxySource);
    }

    private static function ticket(
        string $url = self::API,
        string $slotName = 'account-1',
        int $capacity = 3,
    ): RequestTicketModel {
        return RequestTicketModel::api(
            ApiRequestModel::get($url, ['Authorization' => 'Bearer jwt-1']),
            [new FixedKeySlot($slotName, $capacity)],
        );
    }

    /**
     * @param iterable<int|string, FetchOutcomeModel> $outcomes
     *
     * @return array<int|string, FetchOutcomeModel>
     */
    private static function collect(iterable $outcomes): array
    {
        $collected = [];
        foreach ($outcomes as $key => $outcome) {
            $collected[$key] = $outcome;
        }

        return $collected;
    }

    private function onlyAnswer(HttpClientInterface $client, string $url = self::API): FetchOutcomeModel
    {
        return self::collect($this->engine($client)->sendAll([1 => self::ticket($url)], new PassThroughSettler()))[1];
    }

    public function testAnErrorStatusIsAnAnswerWithItsBodyAndHeaders(): void
    {
        $client = new MockHttpClient([new MockResponse('{"error":"RateLimitExceeded"}', [
            'http_code' => Response::HTTP_TOO_MANY_REQUESTS,
            'response_headers' => ['Retry-After' => '120'],
        ])]);

        $answer = $this->onlyAnswer($client)->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame('{"error":"RateLimitExceeded"}', $answer->body);
        self::assertSame('120', $answer->header('retry-after'));
    }

    public function testARedirectIsAnAnswerAndNeverFollowed(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('', [
            'http_code' => Response::HTTP_FOUND,
            'response_headers' => ['Location' => self::OTHER],
        ])]);

        $answer = $this->onlyAnswer($http->client())->answerOrThrow();

        self::assertSame(Response::HTTP_FOUND, $answer->status);
        self::assertSame([self::API], $http->requestedUrls());
    }

    public function testAnErrorStatusIsAskedAgainOverTheNextFamily(): void
    {
        $respond = static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];

            return self::IPV4 === $resolve['dual.example.test']
                ? new MockResponse('{"feed":[]}')
                : new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
        };

        $answer = $this->onlyAnswer(new MockHttpClient($respond), self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
    }

    public function testTheLastFamilysErrorStatusStandsAsTheAnswer(): void
    {
        /** @var list<string> $pins */
        $pins = [];
        $respond = static function (string $method, string $url, array $options) use (&$pins): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];
            $pins[] = $resolve['dual.example.test'];

            return new MockResponse('{"error":"InvalidToken"}', ['http_code' => Response::HTTP_UNAUTHORIZED]);
        };

        $answer = $this->onlyAnswer(new MockHttpClient($respond), self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_UNAUTHORIZED, $answer->status);
        self::assertSame('{"error":"InvalidToken"}', $answer->body);
        self::assertSame([self::IPV6 . ',' . self::IPV4, self::IPV4, self::IPV6], $pins);
    }

    public function testAMissingResourceIsNotAskedAgain(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_NOT_FOUND]);
        });

        self::assertSame(Response::HTTP_NOT_FOUND, $this->onlyAnswer($client, self::DUAL)->answerOrThrow()->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedRefusalIsAskedOnceDirectly(): void
    {
        /** @var list<bool> $proxied */
        $proxied = [];
        $respond = static function (string $method, string $url, array $options) use (&$proxied): MockResponse {
            $proxied[] = isset($options['proxy']);

            return isset($options['proxy'])
                ? new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN])
                : new MockResponse('[]');
        };
        $engine = $this->engine(
            new MockHttpClient($respond),
            new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null),
        );

        $answer = self::collect($engine->sendAll([1 => self::ticket()], new PassThroughSettler()))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame([true, false], $proxied);
    }

    public function testAProxiedRefusalStandsWhenDirectFallbackIsOff(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => Response::HTTP_FORBIDDEN]);
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null, false));

        $answer = self::collect($engine->sendAll([1 => self::ticket()], new PassThroughSettler()))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_FORBIDDEN, $answer->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedResetIsAskedOnceDirectly(): void
    {
        /** @var list<bool> $proxied */
        $proxied = [];
        $respond = static function (string $method, string $url, array $options) use (&$proxied): MockResponse {
            $proxied[] = isset($options['proxy']);

            return isset($options['proxy'])
                ? new MockResponse('', ['error' => 'Connection reset by peer'])
                : new MockResponse('[]');
        };
        $engine = $this->engine(
            new MockHttpClient($respond),
            new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null),
        );

        $answer = self::collect($engine->sendAll([1 => self::ticket()], new PassThroughSettler()))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_OK, $answer->status);
        self::assertSame([true, false], $proxied);
    }

    public function testSendsTheTicketsMethodHeadersAndJsonBody(): void
    {
        $http = new RoutedHttpClient(['POST ' . self::API => new MockResponse('{}')]);
        $request = ApiRequestModel::postJson(
            self::API,
            ['identifier' => 'did:plc:alice'],
            ['Authorization' => 'Bearer jwt-1'],
        );
        $ticket = RequestTicketModel::api($request, [new FixedKeySlot('account-1', 3)]);

        $outcomes = self::collect($this->engine($http->client())->sendAll([1 => $ticket], new PassThroughSettler()));

        self::assertNull($outcomes[1]->failure());
        self::assertSame('{"identifier":"did:plc:alice"}', $http->requests[0]['body']);
        self::assertContains('Accept: application/json', $http->requests[0]['headers']);
        self::assertContains('Authorization: Bearer jwt-1', $http->requests[0]['headers']);
        self::assertContains('User-Agent: TestAgent/1.0', $http->requests[0]['headers']);
    }

    public function testAGetSendsNoBody(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);

        $this->onlyAnswer($http->client());

        self::assertSame('', $http->requests[0]['body']);
        self::assertNotContains('Content-Type: application/json', $http->requests[0]['headers']);
    }

    public function testATicketCannotOverrideTheHeadersTheEngineOwns(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);
        $ticket = RequestTicketModel::api(ApiRequestModel::get(self::API, [
            'accept-encoding' => 'gzip',
            'User-Agent' => 'Spoofed/1.0',
            'Accept' => 'text/html',
            'Authorization' => 'Bearer jwt-1',
        ]), [new FixedKeySlot('account-1', 3)]);

        $outcomes = self::collect($this->engine($http->client())->sendAll([1 => $ticket], new PassThroughSettler()));

        self::assertNull($outcomes[1]->failure());
        $sent = $http->requests[0]['headers'];
        self::assertContains('Accept-Encoding: identity', $sent);
        self::assertContains('User-Agent: TestAgent/1.0', $sent);
        self::assertContains('Accept: application/json', $sent);
        self::assertContains('Authorization: Bearer jwt-1', $sent);
        self::assertNotContains('accept-encoding: gzip', $sent);
        self::assertNotContains('User-Agent: Spoofed/1.0', $sent);
        self::assertNotContains('Accept: text/html', $sent);
    }

    public function testAFixedKeySlotCapsItsRequestsWhateverTheirHost(): void
    {
        $inFlight = 0;
        $peak = 0;
        $client = new MockHttpClient(static function () use (&$inFlight, &$peak): MockResponse {
            ++$inFlight;
            $peak = max($peak, $inFlight);
            $body = (static function () use (&$inFlight): \Generator {
                yield '{}';
                --$inFlight;
            })();

            return new MockResponse($body);
        });

        $outcomes = self::collect($this->engine($client)->sendAll([
            1 => self::ticket(self::API, 'account-1', 1),
            2 => self::ticket(self::OTHER, 'account-1', 1),
            3 => self::ticket(self::API, 'account-1', 1),
        ], new PassThroughSettler()));

        self::assertCount(3, $outcomes);
        self::assertSame(1, $peak);
    }

    public function testATicketGoesOutOnlyWhileEverySlotItNamesHasRoom(): void
    {
        $inFlight = 0;
        $peak = 0;
        $client = new MockHttpClient(static function () use (&$inFlight, &$peak): MockResponse {
            ++$inFlight;
            $peak = max($peak, $inFlight);
            $body = (static function () use (&$inFlight): \Generator {
                yield '{}';
                --$inFlight;
            })();

            return new MockResponse($body);
        });
        $onTheSharedServer = static fn (string $account): RequestTicketModel => RequestTicketModel::api(
            ApiRequestModel::get(self::API),
            [new FixedKeySlot($account, 3), new FixedKeySlot('server', 1)],
        );

        $outcomes = self::collect($this->engine($client)->sendAll([
            1 => $onTheSharedServer('account-1'),
            2 => $onTheSharedServer('account-2'),
            3 => $onTheSharedServer('account-3'),
        ], new PassThroughSettler()));

        self::assertCount(3, $outcomes, 'every slot a ticket held is released when it retires');
        self::assertSame(1, $peak, 'three accounts with room share one full server slot');
    }

    public function testAWithheldTicketGoesNowhereAndIsSettledLikeAnyOther(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);
        $settler = new ScriptedSettler(refused: [1]);

        $outcomes = self::collect($this->engine($http->client())->sendAll(
            [1 => self::ticket(self::OTHER, 'account-2'), 2 => self::ticket(self::API)],
            $settler,
        ));

        self::assertTrue($outcomes[1]->isWithheld());
        self::assertFalse($outcomes[2]->isWithheld());
        self::assertSame([self::API], $http->requestedUrls());
        self::assertSame([1, 2], $settler->settled);
    }

    public function testTheSettlerDecidesWhenAParkedTicketWouldGoOut(): void
    {
        $settler = new ScriptedSettler();
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);

        $outcomes = [];
        $sending = $this->engine($http->client())->sendAll(
            [1 => self::ticket(capacity: 1), 2 => self::ticket(capacity: 1)],
            $settler,
        );
        foreach ($sending as $key => $outcome) {
            $outcomes[$key] = $outcome;
            $settler->openings = 0;
        }

        self::assertFalse($outcomes[1]->isWithheld());
        self::assertTrue($outcomes[2]->isWithheld());
        self::assertCount(1, $http->requests);
    }

    public function testATicketAlreadyOnTheWireIsAskedAgainWithoutTheSettler(): void
    {
        $settler = new ScriptedSettler();
        $respond = static function (string $method, string $url, array $options): MockResponse {
            /** @var array<string, string> $resolve */
            $resolve = $options['resolve'];

            return self::IPV4 === $resolve['dual.example.test']
                ? new MockResponse('{"feed":[]}')
                : new MockResponse('{}', ['http_code' => Response::HTTP_SERVICE_UNAVAILABLE]);
        };

        $outcomes = self::collect(
            $this->engine(new MockHttpClient($respond))->sendAll([1 => self::ticket(self::DUAL)], $settler),
        );

        self::assertSame(Response::HTTP_OK, $outcomes[1]->answerOrThrow()->status);
        self::assertSame([1], $settler->asked);
    }

    public function testAFollowUpGoesOutUnderItsKeyAndOnlyItsOutcomeIsYielded(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::API => new MockResponse('{"first":true}'),
            'GET ' . self::OTHER => new MockResponse('{"second":true}'),
        ]);
        $settler = new ScriptedSettler();
        $settler->followUpWith(1, self::ticket(self::OTHER));

        $outcomes = self::collect($this->engine($http->client())->sendAll([1 => self::ticket()], $settler));

        self::assertSame([1], array_keys($outcomes));
        self::assertSame('{"second":true}', $outcomes[1]->answerOrThrow()->body);
        self::assertSame([self::API, self::OTHER], $http->requestedUrls());
        self::assertSame([1, 1], $settler->settled);
    }

    public function testTheSettlerIsAskedAboutAFollowUp(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::API => new MockResponse('{}'),
            'GET ' . self::OTHER => new MockResponse('{}'),
        ]);
        $settler = new ScriptedSettler();
        $settler->followUpWith(1, self::ticket(self::OTHER));

        self::collect($this->engine($http->client())->sendAll([1 => self::ticket()], $settler));

        self::assertSame([1, 1], $settler->asked);
    }

    public function testARefusedFollowUpIsSettledAsWithheld(): void
    {
        $http = new RoutedHttpClient(['GET ' . self::API => new MockResponse('{}')]);
        $settler = new ScriptedSettler(openings: 1);
        $settler->followUpWith(1, self::ticket(self::OTHER));

        $outcomes = self::collect($this->engine($http->client())->sendAll([1 => self::ticket()], $settler));

        self::assertTrue($outcomes[1]->isWithheld());
        self::assertSame([self::API], $http->requestedUrls());
    }

    public function testAFollowUpGoesAheadOfUnstartedTicketsWithoutDrawingOnTheirSource(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::API => new MockResponse('{}'),
            'GET ' . self::OTHER => new MockResponse('{}'),
            'GET ' . self::SECOND => new MockResponse('{}'),
        ]);
        /** @var list<int> $pulled */
        $pulled = [];
        $tickets = (static function () use (&$pulled): \Generator {
            $pulled[] = 1;
            yield 1 => self::ticket();
            $pulled[] = 2;
            yield 2 => self::ticket(self::SECOND);
        })();
        $settler = new ScriptedSettler();
        $settler->followUpWith(1, self::ticket(self::OTHER));
        $engine = FetchWiring::requestEngine($http->client(), self::guard(), FetchWiring::egressProxy(null), 1);

        $sending = $engine->sendAll($tickets, $settler);

        self::assertSame(1, $sending->key());
        self::assertSame([1], $pulled, 'the follow-up never drew on the ticket source');
        self::assertSame([self::API, self::OTHER], $http->requestedUrls(), 'it went ahead of the unstarted ticket');
    }

    public function testEveryFailureOfAnUnreadableProxyIsSettled(): void
    {
        $settler = new ScriptedSettler();

        $outcomes = self::collect($this->engineWithUnreadableProxy(new MockHttpClient())->sendAll(
            [1 => self::ticket(), 2 => self::ticket(self::OTHER)],
            $settler,
        ));

        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());
        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[2]->failure());
        self::assertSame([1, 2], $settler->settled);
    }

    public function testAFollowUpForAnUnreadableProxyFailureIsALogicError(): void
    {
        $settler = new ScriptedSettler();
        $settler->followUpWith(1, self::ticket(self::OTHER));

        $engine = $this->engineWithUnreadableProxy(new MockHttpClient());

        $this->expectException(\LogicException::class);

        self::collect($engine->sendAll([1 => self::ticket()], $settler));
    }

    public function testAnOversizedAnswerFailsAtTheApiLimit(): void
    {
        $client = new MockHttpClient([new MockResponse(str_repeat('x', ResponseSizeLimit::Api->bytes() + 1))]);

        self::assertInstanceOf(ResponseTooLargeException::class, $this->onlyAnswer($client)->failure());
    }

    public function testATicketNoExchangeReadsFailsAloneAndTheOthersStillLand(): void
    {
        $http = new RoutedHttpClient([
            'GET ' . self::API => new MockResponse('{}'),
            'GET ' . self::FEED => new MockResponse('<rss/>', ['response_headers' => ['Content-Type' => 'text/xml']]),
        ]);
        $feedOnly = new ServiceLocator([
            FeedResponseExchange::kind() => static fn (): FeedResponseExchange => new FeedResponseExchange(
                new ResponseClassifier(new MockClock()),
            ),
        ]);
        $engine = new ConcurrentRequestEngine(
            $http->client(),
            self::guard(),
            FetchWiring::egressProxy(null),
            FetchWiring::retryPolicy(self::guard()),
            $feedOnly,
            4,
            'TestAgent/1.0',
        );

        $outcomes = self::collect($engine->sendAll(
            [1 => self::ticket(), 2 => FeedTickets::of(self::FEED)],
            new PassThroughSettler(),
        ));

        self::assertInstanceOf(FeedUnreachableException::class, $outcomes[1]->failure());
        self::assertNull($outcomes[2]->failure());
    }

    public function testATooManyRequestsAnswerIsNotAskedAgainOverTheNextFamily(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });

        $answer = $this->onlyAnswer($client, self::DUAL)->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame(1, $requests);
    }

    public function testAProxiedTooManyRequestsAnswerIsNotAskedAgainDirectly(): void
    {
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}', ['http_code' => Response::HTTP_TOO_MANY_REQUESTS]);
        });
        $engine = $this->engine($client, new ProxyConfigModel(ProxyType::Socks5, 'p', 1080, null, null));

        $answer = self::collect($engine->sendAll([1 => self::ticket()], new PassThroughSettler()))[1]->answerOrThrow();

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $answer->status);
        self::assertSame(1, $requests);
    }
}
```

- [ ] **Step 6: Run them to verify they fail**

Run: `php bin/phpunit --filter 'FetchQueueTest|ConcurrentRequestEngineTest'`
Expected: errors — `Call to undefined method …FetchQueue::followUp()` and a `TypeError` on `sendAll()` (argument 2
must be `?DispatchGateInterface`).

- [ ] **Step 7: Queue follow-ups, add the per-run settling object, rewrite the engine**

`backend/src/Service/Fetch/Pass/FetchQueue.php` (it already imports `RequestTicketModel`) — add this method after
`requeue()`:

```php
    /** Another request for a key already sent: it joins the continuations and never draws on the ticket source. */
    public function followUp(int|string $key, RequestTicketModel $ticket): void
    {
        $this->continuations[] = FetchAttemptModel::start($key, $ticket, $this->batchProxy);
    }
```

`backend/src/Service/Fetch/Pass/SettledAnswers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\Pass;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;

/**
 * One engine run's settler and queue: a first send goes out only when the settler admits it, and a raw outcome
 * becomes the key's finished value or a follow-up the queue sends as a fresh attempt.
 *
 * @template T
 */
final readonly class SettledAnswers
{
    /** @param AnswerSettlerInterface<T> $settler */
    public function __construct(private AnswerSettlerInterface $settler, private FetchQueue $queue)
    {
    }

    public function admits(FetchAttemptModel $attempt): bool
    {
        return !$attempt->isFirstSend() || $this->settler->admits($attempt->key);
    }

    /** @return \Generator<int|string, T> nothing when the outcome earned a follow-up */
    public function settle(int|string $key, FetchOutcomeModel $outcome): \Generator
    {
        $settlement = $this->settler->settle($key, $outcome);
        if ($settlement->isFollowUp()) {
            $this->queue->followUp($key, $settlement->followUpTicket());

            return;
        }

        yield $key => $settlement->settledOutcome();
    }
}
```

`backend/src/Service/Fetch/ConcurrentRequestEngine.php` — replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch;

use App\Service\Crypto\Exception\SecretUnreadableException;
use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\EgressProxySource\EgressProxySourceInterface;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Exception\ResponseTooLargeException;
use App\Service\Fetch\Model\ExchangeStepModel;
use App\Service\Fetch\Model\FetchAttemptModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Pass\FetchQueue;
use App\Service\Fetch\Pass\RequestSlots;
use App\Service\Fetch\Pass\SettledAnswers;
use App\Service\Fetch\ResponseExchange\ResponseExchangeInterface;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Fetch\Support\EgressOptions;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends many guarded requests at once over Symfony's multiplexing HTTP client and yields each settled outcome under its
 * ticket's key as it lands. SECURITY: every attempt, a failover retry, a followed redirect or a follow-up included,
 * passes UrlGuard and goes out pinned or proxied with `max_redirects` 0; the ticket's exchange reads the answer.
 */
final readonly class ConcurrentRequestEngine
{
    private const float TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private UrlGuard $urlGuard,
        private EgressProxySourceInterface $egressProxySource,
        private FetchRetryPolicy $retryPolicy,
        #[AutowireLocator('app.response_exchange', defaultIndexMethod: 'kind')]
        private ContainerInterface $exchanges,
        private int $concurrency,
        private string $userAgent,
    ) {
        // A cap below one opens no request, so an empty run would read as a clean one: the sweep's `remaining` never
        // decrements and the frontend polls forever on `partial`.
        CapacityGuard::atLeastOne($concurrency, 'Concurrency');
    }

    /**
     * A failed request arrives as a failed outcome, never a throw, so one bad ticket cannot abandon the others;
     * abandoning the generator cancels whatever is still in flight, follow-ups included. A request goes out only while
     * every slot it names has room and the settler admits it, and every raw outcome is settled before it is yielded.
     *
     * @template T
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     * @param AnswerSettlerInterface<T>                $settler
     *
     * @return \Generator<int|string, T>
     */
    public function sendAll(iterable $tickets, AnswerSettlerInterface $settler): \Generator
    {
        try {
            $batchProxy = $this->egressProxySource->egressProxy();
        } catch (SecretUnreadableException $exception) {
            // An unreadable proxy password fails every ticket, one outcome each: the sweep's `remaining` only
            // decrements on a yielded outcome, so letting the exception escape would strand the run.
            yield from $this->failEvery($tickets, $exception, $settler);

            return;
        }

        // More full-slot candidates than there are slots cannot open a request this pass.
        $lookAhead = $this->concurrency;
        $queue = new FetchQueue($this->iterator($tickets), new RequestSlots(), $lookAhead, $batchProxy);
        $answers = new SettledAnswers($settler, $queue);
        /** @var \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight */
        $inFlight = new \SplObjectStorage();

        try {
            while (true) {
                yield from $this->fill($queue, $inFlight, $answers);

                if (0 === $inFlight->count()) {
                    return;
                }

                yield from $this->awaitNext($queue, $inFlight, $answers);
            }
        } finally {
            // Reached on `break` as well as on completion: an aborted run must
            // not leave sockets open behind the caller's back.
            foreach ($inFlight as $response) {
                $response->cancel();
            }
        }
    }

    /**
     * @template T
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     * @param SettledAnswers<T>                                       $answers
     *
     * @return \Generator<int|string, T>
     */
    private function fill(FetchQueue $queue, \SplObjectStorage $inFlight, SettledAnswers $answers): \Generator
    {
        while ($inFlight->count() < $this->concurrency) {
            $attempt = $queue->takeRunnable();
            if (null === $attempt) {
                return;
            }
            if (!$answers->admits($attempt)) {
                yield from $answers->settle($attempt->key, FetchOutcomeModel::withheld());
                continue;
            }

            try {
                $response = $this->send($attempt);
            } catch (FetchException $exception) {
                $fallback = $this->retryPolicy->directFallbackFor($attempt);
                if (null !== $fallback) {
                    $queue->requeue($fallback);
                    continue;
                }

                yield from $answers->settle($attempt->key, FetchOutcomeModel::failed($exception));
                continue;
            }

            $queue->onSent($attempt);
            $inFlight[$response] = $attempt;
        }
    }

    /**
     * Streams until one response resolves, then returns so the freed slot is refilled. A follow-up attempt goes back
     * on the queue instead of being sent inline, so a feed on its fourth hop shares the loop with one on its first.
     *
     * @template T
     *
     * @param \SplObjectStorage<ResponseInterface, FetchAttemptModel> $inFlight
     * @param SettledAnswers<T>                                       $answers
     *
     * @return \Generator<int|string, T>
     */
    private function awaitNext(FetchQueue $queue, \SplObjectStorage $inFlight, SettledAnswers $answers): \Generator
    {
        foreach ($this->httpClient->stream($inFlight, self::TIMEOUT_SECONDS) as $response => $chunk) {
            $attempt = $inFlight[$response];

            try {
                $step = $this->advance($response, $chunk, $attempt);
            } catch (FetchException $exception) {
                $this->retire($queue, $inFlight, $response);

                $requeue = $this->retryPolicy->nextAttemptAfter($attempt, $exception);
                if (null !== $requeue) {
                    $queue->requeue($requeue);

                    return;
                }

                yield from $answers->settle($attempt->key, FetchOutcomeModel::failed($exception));

                return;
            }

            if ($step->isAwaitingBody()) {
                continue;
            }

            $this->retire($queue, $inFlight, $response);

            if (null !== $step->nextAttempt) {
                $queue->requeue($step->nextAttempt);

                return;
            }

            \assert(null !== $step->outcome);

            yield from $answers->settle($attempt->key, $step->outcome);

            return;
        }
    }
```

…and the rest of the class: `advance()`, `exchangeFor()`, `retire()`, `send()`, `headersOf()` and `iterator()` stay
exactly as they are today; replace only `failEvery()` with:

```php
    /**
     * @template T
     *
     * @param iterable<int|string, RequestTicketModel> $tickets
     * @param AnswerSettlerInterface<T>                $settler
     *
     * @return \Generator<int|string, T>
     *
     * @throws \LogicException when the settler answers a failure with a follow-up: there is no queue to send it on
     */
    private function failEvery(iterable $tickets, \Throwable $cause, AnswerSettlerInterface $settler): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            $failure = FetchOutcomeModel::failed(new FeedUnreachableException(
                sprintf('%s: the instance egress proxy is unusable: %s', $ticket->request->url, $cause->getMessage()),
                previous: $cause,
            ));

            yield $key => $settler->settle($key, $failure)->settledOutcome();
        }
    }
}
```

`backend/src/Service/Fetch/Model/FetchOutcomeModel.php` — the `withheld()` docblock becomes
`/** The settler kept the request off the wire. */`.

Delete the gate (repo root): `git rm -r backend/src/Service/Fetch/DispatchGate`.

- [ ] **Step 8: Give the single-feed fetcher the pass-through settler**

`backend/src/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcher.php` — replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Fetch\BatchFeedFetcher;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Support\CapacityGuard;

/** Fetches many feeds at once on the shared request engine; the caller still processes results one at a time. */
final readonly class ConcurrentFeedFetcher implements BatchFeedFetcherInterface
{
    /** @param AnswerSettlerInterface<FetchOutcomeModel> $settler */
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private int $hostConcurrency,
        private AnswerSettlerInterface $settler,
    ) {
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-host concurrency');
    }

    /**
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, FetchOutcomeModel>
     */
    public function fetchAll(iterable $tickets): \Generator
    {
        return $this->engine->sendAll($this->requestTickets($tickets), $this->settler);
    }

    /**
     * One ticket at a time, so a lazy source is never read ahead of the engine's free slots.
     *
     * @param iterable<int|string, FetchTicketModel> $tickets
     *
     * @return \Generator<int|string, RequestTicketModel>
     */
    private function requestTickets(iterable $tickets): \Generator
    {
        foreach ($tickets as $key => $ticket) {
            yield $key => RequestTicketModel::feed($ticket, $this->hostConcurrency);
        }
    }
}
```

`backend/config/services.yaml` — the `ConcurrentFeedFetcher` block becomes:

```yaml
    App\Service\Fetch\BatchFeedFetcher\ConcurrentFeedFetcher:
        arguments:
            $hostConcurrency: '%fetch_host_concurrency%'
            $settler: '@App\Service\Fetch\AnswerSettler\PassThroughSettler'
```

Add `new PassThroughSettler()` as the third constructor argument at the four test sites, and
`use App\Service\Fetch\AnswerSettler\PassThroughSettler;` to each file:
- `tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherTest.php` (`return new ConcurrentFeedFetcher(…, $hostConcurrency, new PassThroughSettler());`)
- `tests/Service/Fetch/BatchFeedFetcher/ConcurrentFeedFetcherProxyTest.php` (two sites: `…, 100, new PassThroughSettler())`)
- `tests/Service/Fetch/FeedFetcher/HttpFeedFetcherTest.php` (`…, 100, new PassThroughSettler()))`)

- [ ] **Step 9: Move the pass onto the settler, keeping today's renewal for one task**

`backend/src/Service/Social/Pass/TimelineRound.php` — delete `use App\Service\Fetch\DispatchGate\DispatchGateInterface;`
and change `final class TimelineRound implements DispatchGateInterface` to `final class TimelineRound`. Nothing else.

`backend/src/Service/Refresh/Pass/PassTickets.php` — replace the whole file:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineRound;

/**
 * One pass's engine tickets in due order, an RSS or Atom feed by its URL, a private feed through its round, and the
 * engine's settler. Only a held account's private tickets are withheld.
 *
 * @implements AnswerSettlerInterface<FetchOutcomeModel|TimelineOutcomeModelInterface|null>
 */
final readonly class PassTickets implements AnswerSettlerInterface
{
    public function __construct(
        private PassFeeds $feeds,
        private TimelineRound $round,
        private int $hostCapacity,
    ) {
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds->started() as $feedId => $feed) {
            if ($this->feeds->isPrivate($feedId)) {
                yield from $this->round->ticketFor($feedId);
                continue;
            }

            yield $feedId => RequestTicketModel::feed(
                new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified()),
                $this->hostCapacity,
            );
        }
    }

    public function admits(int|string $key): bool
    {
        return !$this->feeds->isPrivate($key) || $this->round->admits($key);
    }

    /**
     * @return SettlementModel<FetchOutcomeModel|TimelineOutcomeModelInterface|null> null while a private feed waits
     *     for the renewal wave
     */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return SettlementModel::outcome(
            $this->feeds->isPrivate($key) ? $this->round->settle($key, $outcome) : $outcome,
        );
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        return $this->round->drainUnasked();
    }
}
```

`backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php` — only `run()` changes:

```php
    /**
     * @param iterable<int|string, RequestTicketModel> $requests
     *
     * @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>
     */
    private function run(iterable $requests, PassTickets $tickets): \Generator
    {
        foreach ($this->engine->sendAll($requests, $tickets) as $feedId => $settled) {
            yield from $tickets->drainUnasked();
            if (null !== $settled) {
                yield $feedId => $settled;
            }
        }

        yield from $tickets->drainUnasked();
    }
```

`backend/tests/Service/Refresh/Pass/PassTicketsTest.php` — the last two assertions of
`testAnRssOutcomeIsPassedThroughAndAPrivateOneIsSettledByItsRound` become:

```php
        self::assertSame($rssOutcome, $passTickets->settle(2, $rssOutcome)->settledOutcome());
        self::assertInstanceOf(
            AccountPausedTimelineModel::class,
            $passTickets->settle(1, FetchOutcomeModel::withheld())->settledOutcome(),
        );
```

- [ ] **Step 10: Run the focused tests**

Run: `bin/console cache:warmup` then
`php bin/phpunit --filter 'SettlementModelTest|FetchQueueTest|ConcurrentRequestEngineTest|ConcurrentFeedFetcher|HttpFeedFetcherTest|PassTicketsTest|ConcurrentPassFetcherTest|TimelineRoundTest|PassFetcherWiringTest|RefreshRunnerConcurrentFetchTest'`
Expected: all pass.

Run: `grep -rn 'DispatchGate' src tests config`
Expected: no output.

Break test (verify a guard by breaking it): in `SettledAnswers::admits()` change `!$attempt->isFirstSend() ||` to
`true ||`, rerun `--filter ConcurrentRequestEngineTest`: `testTheSettlerIsAskedAboutAFollowUp` and
`testAWithheldTicketGoesNowhereAndIsSettledLikeAnyOther` fail. Restore the line by hand and rerun: green.

- [ ] **Step 11: Gates**

Run: `composer check`, `composer md`, `composer test:parallel`
Expected: all clean and green; the module-cycle greps from Global Constraints print nothing.

- [ ] **Step 12: Commit** (repo root)

```bash
git add -A backend/src/Service/Fetch backend/src/Service/Social/Pass/TimelineRound.php \
  backend/src/Service/Refresh/Pass/PassTickets.php backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php \
  backend/config/services.yaml backend/tests/Support/ScriptedSettler.php \
  backend/tests/Service/Fetch backend/tests/Service/Refresh/Pass/PassTicketsTest.php
git commit -m "refactor(#1632): the engine settles every answer through the pass, which may ask for a follow-up"
```

---

### Task 3: A stale session renews inside the one run

**Files:**
- Modify: `backend/src/Service/Social/Pass/TimelineAccounts.php` (replaced whole)
- Modify: `backend/src/Service/Social/Pass/TimelineRound.php` (replaced whole)
- Modify: `backend/src/Service/Refresh/Pass/PassTickets.php` (`settle`, `@implements`)
- Modify: `backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php` (replaced whole)
- Modify: `docs/security.md`
- Create: `backend/tests/Support/CountingArrayAdapter.php`
- Modify: `backend/tests/Service/Social/Pass/TimelineAccountsTest.php` (replaced whole),
  `backend/tests/Service/Social/Pass/TimelineRoundTest.php` (replaced whole),
  `backend/tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php`

**Interfaces:**
- Consumes (Task 2): `SettlementModel::outcome/followUp/isFollowUp/followUpTicket/settledOutcome`, `PassTickets` as the
  engine's settler, `ConcurrentRequestEngine::sendAll(iterable, AnswerSettlerInterface)`.
- Produces:
  - `TimelineAccounts`: `mayAsk(SocialAccount): bool`, `heldOutcomeOf(SocialAccount): ?TimelineOutcomeModelInterface`,
    `isRenewed(SocialAccount): bool`, `markRenewed(SocialAccount): void`,
    `settleFailure(SocialAccount, SocialCredentialsRejectedException|SocialNetworkUnreachableException): TimelineOutcomeModelInterface`.
    Deleted: `settleStale()`, `renewSessions()`, `$awaitingSession`.
  - `TimelineRound`: `ticketFor(int|string $feedId): \Generator<int|string, RequestTicketModel>`,
    `admits(int|string $key): bool`,
    `settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel` (`SettlementModel<TimelineOutcomeModelInterface>`, never null),
    `drainUnasked(): \Generator<int|string, TimelineOutcomeModelInterface>`. Deleted: `startRenewalWave()`,
    `settledOrQueued()`, `$staleFeedIds`.
  - `PassTickets implements AnswerSettlerInterface<FetchOutcomeModel|TimelineOutcomeModelInterface>`.
  - `ConcurrentPassFetcher::fetchAll(PassFeeds $feeds): \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface>`, one engine run.
  - `App\Tests\Support\CountingArrayAdapter` with public `int $deletedItems`.

- [ ] **Step 0: Merge develop** (repo root) and rerun Task 2's Step 10 filter.

- [ ] **Step 1: Write the failing round and account tests**

`backend/tests/Support/CountingArrayAdapter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** An ArrayAdapter that counts the items deleted from it: a dropped Bluesky session is one. */
final class CountingArrayAdapter extends ArrayAdapter
{
    public int $deletedItems = 0;

    public function deleteItem(mixed $key): bool
    {
        ++$this->deletedItems;

        return parent::deleteItem($key);
    }
}
```

Replace `backend/tests/Service/Social/Pass/TimelineAccountsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Pass\TimelineAccounts;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class TimelineAccountsTest extends TestCase
{
    use AssignsEntityIds;

    public function testAnAccountCountsAsRenewedOnceMarked(): void
    {
        $accounts = new TimelineAccounts();
        $alice = self::bluesky(3);

        self::assertFalse($accounts->isRenewed($alice));
        $accounts->markRenewed($alice);

        self::assertTrue($accounts->isRenewed($alice));
    }

    public function testARenewalBelongsToItsOwnAccount(): void
    {
        $accounts = new TimelineAccounts();

        $accounts->markRenewed(self::bluesky(3));

        self::assertFalse($accounts->isRenewed(self::bluesky(4)));
    }

    public function testAThrottledAccountMayNotAskAgain(): void
    {
        $accounts = new TimelineAccounts();
        $alice = self::bluesky(3);

        $accounts->settleFailure($alice, new SocialNetworkThrottledException('Bluesky is rate limiting.', 60));

        self::assertFalse($accounts->mayAsk($alice));
    }

    private static function bluesky(int $accountId): SocialAccount
    {
        return self::withId(SocialAccounts::bluesky(), $accountId);
    }
}
```

Replace `backend/tests/Service/Social/Pass/TimelineRoundTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Model\BlueskySessionModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\Pass\TimelineRound;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\CountingArrayAdapter;
use App\Tests\Support\RoutedHttpClient;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Response;

final class TimelineRoundTest extends TestCase
{
    use AssignsEntityIds;

    private const string SESSION = 'POST https://pds.example.test/xrpc/com.atproto.server.createSession';
    private const int HOME = 1;
    private const int LIST = 2;

    private CountingArrayAdapter $cache;

    private RoutedHttpClient $http;

    private SocialAccount $account;

    protected function setUp(): void
    {
        $this->cache = new CountingArrayAdapter();
        $this->http = new RoutedHttpClient([self::SESSION => SocialApiWiring::blueskySessionAnswer('jwt-2')]);
        $this->account = SocialApiWiring::blueskyAccount();
        SocialApiWiring::blueskySessionCache($this->cache)
            ->remember($this->account, new BlueskySessionModel('stale-jwt', 'alice.bsky.social'));
    }

    public function testAFeedWithoutItsSocialFeedSettlesAsOrphanedWithoutATicket(): void
    {
        $round = self::orphanRound();

        self::assertSame([], iterator_to_array($round->ticketFor(999)));
        $unasked = iterator_to_array($round->drainUnasked());

        self::assertSame([999], array_keys($unasked));
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[999]);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[999]->failure);
        self::assertSame([], iterator_to_array($round->drainUnasked()));
    }

    public function testTheGateCannotAskAboutAFeedWithoutItsSocialFeed(): void
    {
        $this->expectException(PrivateFeedOrphanedException::class);

        self::orphanRound()->admits(999);
    }

    public function testAStaleAnswerRenewsTheSessionOnceAndFollowsUpOnTheFreshOne(): void
    {
        $round = $this->ticketedRound();

        $home = $round->settle(self::HOME, self::stale());
        $list = $round->settle(self::LIST, self::stale());

        self::assertSame('Bearer jwt-2', $home->followUpTicket()->request->headers['Authorization']);
        self::assertSame('Bearer jwt-2', $list->followUpTicket()->request->headers['Authorization']);
        self::assertSame(1, $this->cache->deletedItems, 'the session is dropped once');
        self::assertSame([self::SESSION], $this->http->requestLines(), 'and opened once');
    }

    public function testAFeedsSecondStaleAnswerRefusesItsAccountAndTheGateWithholdsItsOtherFeeds(): void
    {
        $round = $this->ticketedRound();
        $round->settle(self::HOME, self::stale());

        $refused = $round->settle(self::HOME, self::stale())->settledOutcome();

        self::assertInstanceOf(FailedTimelineModel::class, $refused);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $refused->failure);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $this->account->getStatus());
        self::assertFalse($round->admits(self::LIST));
        self::assertInstanceOf(
            AccountPausedTimelineModel::class,
            $round->settle(self::LIST, FetchOutcomeModel::withheld())->settledOutcome(),
        );
    }

    public function testATicketBuiltBeforeItsAccountsRenewalIsWithheldAndBuiltAgain(): void
    {
        $round = $this->ticketedRound();
        self::assertTrue($round->admits(self::LIST));
        $round->settle(self::HOME, self::stale());

        self::assertFalse($round->admits(self::LIST), 'its ticket carries the dropped session');
        $rebuilt = $round->settle(self::LIST, FetchOutcomeModel::withheld());

        self::assertSame('Bearer jwt-2', $rebuilt->followUpTicket()->request->headers['Authorization']);
        self::assertTrue($round->admits(self::LIST));
    }

    public function testATicketBuiltAfterItsAccountsRenewalIsAdmitted(): void
    {
        $round = $this->round();
        iterator_to_array($round->ticketFor(self::HOME));
        $round->settle(self::HOME, self::stale());

        iterator_to_array($round->ticketFor(self::LIST));

        self::assertTrue($round->admits(self::LIST));
    }

    private function round(): TimelineRound
    {
        return SocialApiWiring::timelineRounds($this->http->client(), $this->cache)->start([
            self::HOME => $this->socialFeed(self::HOME, SocialFeedKind::Home, ''),
            self::LIST => $this->socialFeed(
                self::LIST,
                SocialFeedKind::UserList,
                'at://did:plc:bob/app.bsky.graph.list/3l1',
            ),
        ]);
    }

    private function ticketedRound(): TimelineRound
    {
        $round = $this->round();
        iterator_to_array($round->ticketFor(self::HOME));
        iterator_to_array($round->ticketFor(self::LIST));

        return $round;
    }

    private function socialFeed(int $feedId, SocialFeedKind $kind, string $remoteId): SocialFeed
    {
        $feed = self::withId(new Feed(sprintf('social:bluesky:3:%d', $feedId)), $feedId);

        return new SocialFeed($this->account, $feed, $kind, $remoteId);
    }

    private static function stale(): FetchOutcomeModel
    {
        return FetchOutcomeModel::answered(
            new ApiResponseModel(Response::HTTP_BAD_REQUEST, '{"error":"ExpiredToken"}'),
        );
    }

    private static function orphanRound(): TimelineRound
    {
        $sources = SocialApiWiring::timelineSources(new MockHttpClient(), new ArrayAdapter());

        return new TimelineRound($sources, new TimelineSlotsModel(3, 4), []);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'TimelineAccountsTest|TimelineRoundTest'`
Expected: errors — `Call to undefined method …TimelineAccounts::isRenewed()`, and `settle()` returns
`?TimelineOutcomeModelInterface` (`Call to a member function followUpTicket() on null`).

- [ ] **Step 3: Rewrite the account verdicts and the round**

Replace `backend/src/Service/Social/Pass/TimelineAccounts.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Enum\SocialAccountStatus;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** One refresh's verdict per account: throttled, paused, or renewed once already. */
final class TimelineAccounts
{
    /** @var array<int, SocialAccount> */
    private array $throttled = [];

    /** @var array<int, true> */
    private array $renewed = [];

    public function mayAsk(SocialAccount $account): bool
    {
        return null === $this->heldOutcomeOf($account);
    }

    /** Why the account's feeds stay unasked this refresh, or null when nothing holds them. */
    public function heldOutcomeOf(SocialAccount $account): ?TimelineOutcomeModelInterface
    {
        if (SocialAccountStatus::NeedsReconnect === $account->getStatus()) {
            return new AccountPausedTimelineModel();
        }

        return isset($this->throttled[$account->requireId()]) ? new AccountThrottledTimelineModel() : null;
    }

    public function isRenewed(SocialAccount $account): bool
    {
        return isset($this->renewed[$account->requireId()]);
    }

    public function markRenewed(SocialAccount $account): void
    {
        $this->renewed[$account->requireId()] = true;
    }

    public function settleFailure(
        SocialAccount $account,
        SocialCredentialsRejectedException|SocialNetworkUnreachableException $failure,
    ): TimelineOutcomeModelInterface {
        if ($failure instanceof SocialNetworkThrottledException) {
            $this->throttled[$account->requireId()] = $account;

            return new ThrottledTimelineModel($failure->retryAfterSeconds);
        }
        if ($failure instanceof SocialCredentialsRejectedException) {
            $account->markNeedsReconnect();
        }

        return new FailedTimelineModel($failure);
    }
}
```

Replace `backend/src/Service/Social/Pass/TimelineRound.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Pass;

use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialSessionStaleException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Model\TimelineSlotsModel;
use App\Service\Social\TimelineSources;

/**
 * One refresh's private-feed reads: a ticket per feed, a gate that keeps a held account's tickets off the wire, and
 * each answer settled into an outcome or a follow-up. A stale session is renewed once per account in the refresh; a
 * feed settled without a request waits in `unasked` until drained.
 */
final class TimelineRound
{
    /** @var array<int|string, TimelineOutcomeModelInterface> */
    private array $unasked = [];

    /** @var array<int|string, true> */
    private array $staleOnce = [];

    /** @var array<int|string, true> feeds whose current ticket was built after their account's renewal */
    private array $onRenewedSession = [];

    private readonly TimelineAccounts $accounts;

    /** @param array<int, SocialFeed> $socialFeedsByFeedId each with its account loaded */
    public function __construct(
        private readonly TimelineSources $sources,
        private readonly TimelineSlotsModel $slots,
        private readonly array $socialFeedsByFeedId,
    ) {
        $this->accounts = new TimelineAccounts();
    }

    /** @return \Generator<int|string, RequestTicketModel> none when the feed settles without a request */
    public function ticketFor(int|string $feedId): \Generator
    {
        try {
            $socialFeed = $this->socialFeedOf($feedId);
        } catch (PrivateFeedOrphanedException $orphaned) {
            $this->unasked[$feedId] = new FailedTimelineModel($orphaned);

            return;
        }

        $settlement = $this->ticketOf($feedId, $socialFeed);
        if ($settlement->isFollowUp()) {
            yield $feedId => $settlement->followUpTicket();

            return;
        }

        $this->unasked[$feedId] = $settlement->settledOutcome();
    }

    public function admits(int|string $key): bool
    {
        $account = $this->socialFeedOf($key)->getSocialAccount();
        if (!$this->accounts->mayAsk($account)) {
            return false;
        }

        // A ticket built before its account's renewal carries the dropped session: withheld, it is built again.
        return !$this->accounts->isRenewed($account) || isset($this->onRenewedSession[$key]);
    }

    /** @return SettlementModel<TimelineOutcomeModelInterface> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        $socialFeed = $this->socialFeedOf($feedId);
        if ($outcome->isWithheld()) {
            return $this->ticketOf($feedId, $socialFeed);
        }

        $account = $socialFeed->getSocialAccount();
        try {
            $page = $this->sources->of($account)->pageOf($socialFeed, $outcome);
        } catch (SocialSessionStaleException $stale) {
            return $this->settleStale($feedId, $socialFeed, $stale);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return SettlementModel::outcome($this->accounts->settleFailure($account, $failure));
        }
        $socialFeed->recordNewestSeen($page->newestId);

        return SettlementModel::outcome(new FetchedTimelineModel($page));
    }

    /** @return \Generator<int|string, TimelineOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        $unasked = $this->unasked;
        $this->unasked = [];

        yield from $unasked;
    }

    /**
     * A feed's second stale answer is a refusal, whatever session it went out on.
     *
     * @return SettlementModel<TimelineOutcomeModelInterface>
     */
    private function settleStale(
        int|string $feedId,
        SocialFeed $socialFeed,
        SocialSessionStaleException $stale,
    ): SettlementModel {
        $account = $socialFeed->getSocialAccount();
        if (isset($this->staleOnce[$feedId])) {
            return SettlementModel::outcome($this->accounts->settleFailure(
                $account,
                new SocialCredentialsRejectedException($stale->getMessage(), previous: $stale),
            ));
        }
        $this->staleOnce[$feedId] = true;
        $this->renewOnce($account);

        return $this->ticketOf($feedId, $socialFeed);
    }

    private function renewOnce(SocialAccount $account): void
    {
        if ($this->accounts->isRenewed($account)) {
            return;
        }
        $this->accounts->markRenewed($account);
        $this->sources->of($account)->dropSession($account);
    }

    /** @return SettlementModel<TimelineOutcomeModelInterface> the feed's ticket, or what it settles at without one */
    private function ticketOf(int|string $feedId, SocialFeed $socialFeed): SettlementModel
    {
        $account = $socialFeed->getSocialAccount();
        $held = $this->accounts->heldOutcomeOf($account);
        if (null !== $held) {
            return SettlementModel::outcome($held);
        }

        try {
            $request = $this->sources->of($account)->request($socialFeed);
        } catch (SocialCredentialsRejectedException | SocialNetworkUnreachableException $failure) {
            return SettlementModel::outcome($this->accounts->settleFailure($account, $failure));
        }
        if ($this->accounts->isRenewed($account)) {
            $this->onRenewedSession[$feedId] = true;
        }

        return SettlementModel::followUp($this->slots->ticketFor($account, $request));
    }

    /** @throws PrivateFeedOrphanedException when the private feed has lost its social feed */
    private function socialFeedOf(int|string $feedId): SocialFeed
    {
        return $this->socialFeedsByFeedId[$feedId] ?? throw new PrivateFeedOrphanedException();
    }
}
```

- [ ] **Step 4: Run the round and account tests**

Run: `php bin/phpunit --filter 'TimelineAccountsTest|TimelineRoundTest'`
Expected: PASS (7 + 3). The pass still fails to compile against the round (`startRenewalWave()` is gone); Step 6 fixes
it.

- [ ] **Step 5: Write the failing pass-level tests**

`backend/tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php`:

1. Imports: replace `use Symfony\Component\Cache\Adapter\ArrayAdapter;` with
   `use App\Tests\Support\CountingArrayAdapter;` (sorted into the `App\Tests\Support` block).
2. Constants — add after `BLUESKY_LIST`:

```php
    private const string BLUESKY_HOME = 'GET https://pds.example.test/xrpc/app.bsky.feed.getTimeline?limit=50';
    private const string BLUESKY_LIST_READ = 'GET https://pds.example.test/xrpc/app.bsky.feed.getListFeed'
        . '?list=at%3A%2F%2Fdid%3Aplc%3Abob%2Fapp.bsky.graph.list%2F3l1&limit=50';
```

3. Property and setUp: `private CountingArrayAdapter $cache;` and `$this->cache = new CountingArrayAdapter();`.
4. Delete `testAStaleSessionsFeedsRunInASecondWaveAfterTheRssFeedsLanded` and add, in its place:

```php
    public function testAStaleSessionsFeedIsSentAgainInsideTheOneRun(): void
    {
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');

        $outcomes = self::read($this->fetcher(self::renewingBesideASlowFeed()), [$home, $rss]);

        self::assertSame(
            [self::idOf($home), $rss->requireId()],
            array_keys($outcomes),
            'the renewed read lands while the RSS feed is still on the wire: no second run',
        );
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame([self::SESSION], $this->requestsTo('POST'));
    }

    public function testTwoInFlightStaleReadsOfOneAccountRenewItsSessionOnceAndAreEachSentOnceMore(): void
    {
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher(self::renewingStaleSessions()), [$home, $list]);

        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame(1, $this->cache->deletedItems, 'one dropSession');
        self::assertSame(
            [self::BLUESKY_HOME => 2, self::BLUESKY_LIST_READ => 2, self::SESSION => 1],
            array_count_values($this->http->requestLines()),
        );
    }

    public function testAFeedsSecondStaleAnswerPausesItsAccountAndTheGateWithholdsItsOtherFeeds(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => self::SESSION === $method . ' ' . $url
            ? self::freshSession()
            : self::expiredToken();
        $bluesky = $this->blueskyAccount('stale-jwt');
        $home = $this->feedOf($bluesky);
        $list = $this->feedOf($bluesky, SocialFeedKind::UserList, self::BLUESKY_LIST);

        $outcomes = self::read($this->fetcher($respond, accountConcurrency: 1), [$home, $list]);

        $failure = self::failureOf($outcomes[self::idOf($home)]);
        self::assertInstanceOf(SocialCredentialsRejectedException::class, $failure);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $outcomes[self::idOf($list)]);
        self::assertSame(SocialAccountStatus::NeedsReconnect, $bluesky->getStatus());
        self::assertSame(
            [self::BLUESKY_HOME, self::SESSION, self::BLUESKY_HOME],
            $this->http->requestLines(),
            'the list never reaches the wire',
        );
    }

    public function testAFollowUpIsNeverCountedAsAStartAgainstTheBudget(): void
    {
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $pass = new RefreshPass([$home->getFeed(), $rss], $clock, $clock->now()->getTimestamp() + 5);

        $outcomes = iterator_to_array(
            $this->fetcher(self::renewingStaleSessions())->fetchAll(new PassFeeds($pass, [self::idOf($home) => $home])),
        );

        self::assertSame([self::idOf($home)], $pass->startedFeedIds(), 'the budget started one feed');
        self::assertSame([self::idOf($home)], array_keys($outcomes));
        self::assertInstanceOf(FetchedTimelineModel::class, $outcomes[self::idOf($home)]);
        self::assertSame([self::BLUESKY_HOME, self::SESSION, self::BLUESKY_HOME], $this->http->requestLines());
    }

    public function testAbandoningThePassCancelsAFollowUpInFlight(): void
    {
        $followUpReadToTheEnd = false;
        $respond = static function (
            string $method,
            string $url,
            array $headers,
        ) use (&$followUpReadToTheEnd): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::freshSession();
            }
            if (str_starts_with($url, self::NEWS)) {
                return self::rss();
            }
            if (\in_array('Authorization: Bearer stale-jwt', $headers, true)) {
                return self::expiredToken();
            }

            return new MockResponse((static function () use (&$followUpReadToTheEnd): \Generator {
                yield from str_split(self::blueskyTimelineBody());
                $followUpReadToTheEnd = true;
            })());
        };
        $home = $this->feedOf($this->blueskyAccount('stale-jwt'));
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $outcomes = $this->fetcher($respond)->fetchAll(self::passFeeds([$home, $rss]));

        self::assertSame($rss->requireId(), $outcomes->key(), 'the RSS feed lands while the follow-up is on the wire');
        unset($outcomes);

        self::assertSame(
            [self::BLUESKY_HOME, 'GET ' . self::NEWS . '/one.xml', self::SESSION, self::BLUESKY_HOME],
            $this->http->requestLines(),
        );
        self::assertTrue($this->responses[3]->getInfo('canceled'));
        self::assertFalse($followUpReadToTheEnd);
    }
```

5. Helpers — replace `renewingStaleSessions()` and `blueskyTimeline()` with these, and add the four new ones next to
   them:

```php
    /** @return \Closure(string, string, list<string>): MockResponse a PDS that refuses the stale token only */
    private static function renewingStaleSessions(): \Closure
    {
        return static function (string $method, string $url, array $headers): MockResponse {
            if (self::SESSION === $method . ' ' . $url) {
                return self::freshSession();
            }
            if (str_starts_with($url, self::NEWS)) {
                return self::rss();
            }
            if (!str_starts_with($url, self::PDS)) {
                return self::statuses();
            }

            return \in_array('Authorization: Bearer stale-jwt', $headers, true)
                ? self::expiredToken()
                : self::blueskyTimeline();
        };
    }

    /** @return \Closure(string, string, list<string>): MockResponse renewingStaleSessions(), its RSS answer slow */
    private static function renewingBesideASlowFeed(): \Closure
    {
        return static function (string $method, string $url, array $headers): MockResponse {
            if (str_starts_with($url, self::NEWS)) {
                return new MockResponse((static function (): \Generator {
                    yield from str_split('<rss/>' . str_repeat(' ', 14));
                })());
            }
            if (self::SESSION === $method . ' ' . $url) {
                return self::freshSession();
            }

            return \in_array('Authorization: Bearer stale-jwt', $headers, true)
                ? self::expiredToken()
                : self::blueskyTimeline();
        };
    }

    private static function freshSession(): MockResponse
    {
        return self::json(['did' => 'did:plc:alice', 'handle' => 'alice.bsky.social', 'accessJwt' => 'jwt-2']);
    }

    private static function expiredToken(): MockResponse
    {
        return self::json(['error' => 'ExpiredToken', 'message' => 'Token has expired'], Response::HTTP_BAD_REQUEST);
    }

    private static function blueskyTimelineBody(): string
    {
        return json_encode(['feed' => [[
            'post' => [
                'uri' => 'at://did:plc:bob/app.bsky.feed.post/3abc',
                'author' => ['did' => 'did:plc:bob', 'handle' => 'bob.bsky.social'],
                'record' => ['text' => 'Hello', 'createdAt' => '2026-10-12T10:00:00Z'],
            ],
        ]]], \JSON_THROW_ON_ERROR);
    }

    private static function blueskyTimeline(): MockResponse
    {
        return new MockResponse(self::blueskyTimelineBody());
    }
```

6. In `testAStaleAnswerToARenewedSessionRefusesTheAccount`, replace the inline session `json([...'jwt-2'])` with
   `self::freshSession()` (DRY: third occurrence).

- [ ] **Step 6: Run them to verify they fail**

Run: `php bin/phpunit --filter ConcurrentPassFetcherTest`
Expected: errors — `ConcurrentPassFetcher` still calls `TimelineRound::startRenewalWave()`, which no longer exists.

- [ ] **Step 7: One loop in the pass fetcher, no null in the settler**

`backend/src/Service/Refresh/Pass/PassTickets.php`:
- `@implements AnswerSettlerInterface<FetchOutcomeModel|TimelineOutcomeModelInterface>` (no `|null`);
- `settle()` becomes:

```php
    /** @return SettlementModel<FetchOutcomeModel|TimelineOutcomeModelInterface> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return $this->feeds->isPrivate($key)
            ? $this->round->settle($key, $outcome)
            : SettlementModel::outcome($outcome);
    }
```

Replace `backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\TimelineRounds;

/** Reads a pass's due feeds on one engine run, in due order whatever their kind; a stale session renews inside it. */
final readonly class ConcurrentPassFetcher implements PassFetcherInterface
{
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineRounds $timelineRounds,
        private int $hostConcurrency,
    ) {
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-host concurrency');
    }

    /** @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> */
    public function fetchAll(PassFeeds $feeds): \Generator
    {
        $round = $this->timelineRounds->start($feeds->socialFeedsByFeedId);
        $tickets = new PassTickets($feeds, $round, $this->hostConcurrency);

        foreach ($this->engine->sendAll($tickets->tickets(), $tickets) as $feedId => $outcome) {
            yield from $tickets->drainUnasked();
            yield $feedId => $outcome;
        }

        yield from $tickets->drainUnasked();
    }
}
```

- [ ] **Step 8: Docs**

`docs/security.md`, the refresh-worker bullet of the social-accounts section: replace

```
  entry. The token is only ever sent to the account's stored service URL, never to a URL read from the cache. A
  token the PDS calls expired or invalid is replaced once with a fresh session. Timeline reads run concurrently,
  at most three in flight per account (`social_account_concurrency`, #1607) and four per Mastodon instance or
  Bluesky PDS across its accounts (`social_host_concurrency`, #1608); a throttled or refused account's waiting
  reads are withheld before they reach the wire.
```

with

```
  entry. The token is only ever sent to the account's stored service URL, never to a URL read from the cache. A
  token the PDS calls expired or invalid is replaced once per refresh with a fresh session, inside the refresh's one
  engine run, and each read it refused goes out once more on the fresh session (#1632); a read refused again marks
  the account as needing a reconnect. Timeline reads run concurrently, at most three in flight per account
  (`social_account_concurrency`, #1607) and four per Mastodon instance or Bluesky PDS across its accounts
  (`social_host_concurrency`, #1608), never counted against an RSS host's cap. A throttled or refused account's
  waiting reads, and any read built on a session a renewal dropped, are withheld before they reach the wire.
```

- [ ] **Step 9: Run the focused tests**

Run: `php bin/phpunit --filter 'TimelineAccountsTest|TimelineRoundTest|PassTicketsTest|ConcurrentPassFetcherTest|PassFetcherWiringTest|RefreshPassRecorderBlueskyTest|RefreshRunnerConcurrentFetchTest'`
Expected: all pass, the pre-existing renewal tests included
(`testAStaleBlueskySessionIsRenewedOnceAndItsFeedsAskedAgain`, `testAFeedWaitingForARenewedSessionIsNotSentOnTheStaleOne`,
`testEveryAccountWithAStaleSessionIsRenewed`, `testAStaleAnswerToARenewedSessionRefusesTheAccount`).

Run: `grep -rn 'startRenewalWave\|staleFeedIds\|settledOrQueued\|awaitingSession\|renewSessions\|settleStale(\$account' src tests`
Expected: no output.

Break test: in `TimelineRound::admits()` change `|| isset($this->onRenewedSession[$key])` to `|| true`; rerun
`--filter 'TimelineRoundTest|ConcurrentPassFetcherTest'`: `testATicketBuiltBeforeItsAccountsRenewalIsWithheldAndBuiltAgain`
and `testAFeedWaitingForARenewedSessionIsNotSentOnTheStaleOne` fail. Restore by hand; green again.

- [ ] **Step 10: Gates**

Run: `composer check`, `composer md`, `composer test:parallel`
Expected: clean and green; module-cycle greps print nothing.

- [ ] **Step 11: Commit** (repo root)

```bash
git add backend/src/Service/Social/Pass backend/src/Service/Refresh/Pass/PassTickets.php \
  backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php docs/security.md \
  backend/tests/Support/CountingArrayAdapter.php backend/tests/Service/Social/Pass \
  backend/tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php
git commit -m "feat(#1632): a stale session renews inside the pass's one engine run"
```

---

### Task 4: A feed's kind is decided once, as its lane

**Files:**
- Create: `backend/src/Service/Refresh/Pass/PassLane/PassLaneInterface.php`, `FeedLane.php`, `TimelineLane.php`
- Create: `backend/src/Service/Refresh/Model/PassOutcome/PassOutcomeModelInterface.php`, `FeedPassOutcomeModel.php`,
  `TimelinePassOutcomeModel.php`
- Modify (replace whole): `backend/src/Service/Refresh/Pass/PassFeeds.php`, `PassTickets.php`, `PassOutcomeStore.php`,
  `backend/src/Service/Refresh/PassFetcher/PassFetcherInterface.php`, `ConcurrentPassFetcher.php`,
  `backend/src/Service/Refresh/RefreshPassRecorder.php`, `backend/tests/Support/StubPassFetcher.php`
- Create: `backend/tests/Service/Refresh/Pass/PassLane/FeedLaneTest.php`, `TimelineLaneTest.php`
- Modify (replace whole): `backend/tests/Service/Refresh/Pass/PassFeedsTest.php`, `PassTicketsTest.php`
- Modify: `backend/tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php`,
  `backend/tests/Service/Refresh/PassFetcher/PassFetcherWiringTest.php`

**Interfaces:**
- Consumes (Tasks 2–3): `AnswerSettlerInterface`, `SettlementModel::outcome/mapOutcome`, `TimelineRound::ticketFor/admits/settle/drainUnasked`,
  `TimelineRounds::start(array<int, SocialFeed>): TimelineRound`, `PassOutcomeStore(FeedOutcomePersister, \DateTimeImmutable)`.
- Produces:
  - `App\Service\Refresh\Pass\PassLane\PassLaneInterface`: `ticketsFor(Feed $feed): \Generator<int|string, RequestTicketModel>`,
    `admits(int|string $feedId): bool`, `settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel`
    (`SettlementModel<PassOutcomeModelInterface>`), `drainUnasked(): \Generator<int|string, PassOutcomeModelInterface>`.
  - `FeedLane::__construct(int $hostCapacity)`, `TimelineLane::__construct(TimelineRound $round)`.
  - `App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface` (sealed):
    `storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel`;
    `FeedPassOutcomeModel(public FetchOutcomeModel $outcome)`, `TimelinePassOutcomeModel(public TimelineOutcomeModelInterface $outcome)`.
  - `PassOutcomeStore::storeFetched(Feed, FetchOutcomeModel): FeedRefreshResultModel`,
    `storeTimeline(Feed, TimelineOutcomeModelInterface): FeedRefreshResultModel`; `store()` deleted.
  - `PassFeeds::__construct(RefreshPass $pass, PassLaneInterface $feedLane, PassLaneInterface $timelineLane)`,
    `static privateFeedIdsOf(RefreshPass): list<int>`, `started()`, `laneOf(int|string $feedId): PassLaneInterface`,
    `lanes(): list<PassLaneInterface>`; `isPrivate()` and `$socialFeedsByFeedId` deleted.
  - `PassTickets::__construct(PassFeeds $feeds)`, `AnswerSettlerInterface<PassOutcomeModelInterface>`.
  - `PassFetcherInterface::fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): iterable<int|string, PassOutcomeModelInterface>`.
  - `StubPassFetcher::fetchAll(RefreshPass, array<int, SocialFeed>)`.

- [ ] **Step 0: Merge develop** (repo root) and rerun Task 3's Step 9 filter.

- [ ] **Step 1: Write the failing lane and pass tests**

`backend/tests/Service/Refresh/Pass/PassLane/FeedLaneTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Fetch\Exception\FeedUnreachableException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestSlot\HostSlotModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;

final class FeedLaneTest extends TestCase
{
    use AssignsEntityIds;

    public function testAFeedIsOneTicketOnItsHostCarryingItsValidators(): void
    {
        $feed = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $feed->recordCacheValidators('"v1"', 'Mon, 20 Jul 2026 08:30:00 GMT');
        $lane = new FeedLane(2);

        $tickets = iterator_to_array($lane->ticketsFor($feed));

        self::assertSame([1], array_keys($tickets));
        self::assertSame(ResponseExchangeKind::Feed, $tickets[1]->exchange);
        self::assertSame('https://news.example/feed.xml', $tickets[1]->request->url);
        self::assertSame(
            ['If-None-Match' => '"v1"', 'If-Modified-Since' => 'Mon, 20 Jul 2026 08:30:00 GMT'],
            $tickets[1]->request->headers,
        );
        self::assertEquals([new HostSlotModel(2)], $tickets[1]->slots);
    }

    public function testEveryFeedIsAdmitted(): void
    {
        $lane = new FeedLane(2);

        self::assertTrue($lane->admits(1));
    }

    public function testARawOutcomeIsStoredAsFetched(): void
    {
        $outcome = FetchOutcomeModel::failed(new FeedUnreachableException('https://news.example/feed.xml: timed out'));
        $lane = new FeedLane(2);

        $passOutcome = $lane->settle(1, $outcome)->settledOutcome();

        self::assertInstanceOf(FeedPassOutcomeModel::class, $passOutcome);
        self::assertSame($outcome, $passOutcome->outcome);
    }

    public function testNothingSettlesWithoutARequest(): void
    {
        $lane = new FeedLane(2);

        self::assertSame([], iterator_to_array($lane->drainUnasked()));
    }
}
```

`backend/tests/Service/Refresh/Pass/PassLane/TimelineLaneTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\ResponseExchangeKind;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\PassLane\TimelineLane;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\SocialAccountSlotModel;
use App\Service\Social\Model\SocialServerSlotModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class TimelineLaneTest extends TestCase
{
    use AssignsEntityIds;

    private const string HOME = 'https://mastodon.example/api/v1/timelines/home?limit=40';

    public function testAPrivateFeedsTicketComesFromItsRound(): void
    {
        $timeline = self::privateFeed(self::account(), 2);

        $tickets = iterator_to_array(self::lane([2 => $timeline])->ticketsFor($timeline->getFeed()));

        self::assertSame([2], array_keys($tickets));
        self::assertSame(ResponseExchangeKind::Api, $tickets[2]->exchange);
        self::assertEquals(
            [new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(self::HOME, 4)],
            $tickets[2]->slots,
        );
    }

    public function testTheGateAsksTheRound(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 2);
        $lane = self::lane([2 => $timeline]);
        iterator_to_array($lane->ticketsFor($timeline->getFeed()));
        self::assertTrue($lane->admits(2));

        $account->markNeedsReconnect();

        self::assertFalse($lane->admits(2));
    }

    public function testAnAnswerIsStoredAsItsTimelineOutcome(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 2);
        $lane = self::lane([2 => $timeline]);
        iterator_to_array($lane->ticketsFor($timeline->getFeed()));
        $account->markNeedsReconnect();

        $passOutcome = $lane->settle(2, FetchOutcomeModel::withheld())->settledOutcome();

        self::assertInstanceOf(TimelinePassOutcomeModel::class, $passOutcome);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $passOutcome->outcome);
    }

    public function testAnOrphanIsDrainedAsItsTimelineOutcome(): void
    {
        $orphan = self::privateFeed(self::account(), 2)->getFeed();
        $lane = self::lane([]);

        self::assertSame([], iterator_to_array($lane->ticketsFor($orphan)));
        $unasked = iterator_to_array($lane->drainUnasked());

        self::assertSame([2], array_keys($unasked));
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $unasked[2]);
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[2]->outcome);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[2]->outcome->failure);
    }

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    private static function lane(array $socialFeedsByFeedId): TimelineLane
    {
        $rounds = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter());

        return new TimelineLane($rounds->start($socialFeedsByFeedId));
    }

    private static function account(): SocialAccount
    {
        return self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
    }

    private static function privateFeed(SocialAccount $account, int $feedId): SocialFeed
    {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);

        return new SocialFeed($account, $feed, SocialFeedKind::Home, '');
    }
}
```

Replace `backend/tests/Service/Refresh/Pass/PassFeedsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Service\Refresh\Pass\RefreshPass;
use App\Tests\Support\AssignsEntityIds;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PassFeedsTest extends TestCase
{
    use AssignsEntityIds;

    public function testGivesEachFeedTheLaneOfItsKind(): void
    {
        $feedLane = new FeedLane(2);
        $timelineLane = new FeedLane(3);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);

        $feeds = new PassFeeds(self::pass([$rss, self::privateFeed(2)]), $feedLane, $timelineLane);

        self::assertSame($feedLane, $feeds->laneOf(1));
        self::assertSame($timelineLane, $feeds->laneOf(2));
        self::assertSame([$feedLane, $timelineLane], $feeds->lanes());
    }

    public function testNamesEveryPrivateFeedIdInOrderWhetherTheBudgetStartsItOrNot(): void
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);

        $pass = new RefreshPass(
            [$rss, self::privateFeed(7), self::privateFeed(4)],
            $clock,
            $clock->now()->getTimestamp() + 5,
        );

        self::assertSame([7, 4], PassFeeds::privateFeedIdsOf($pass));
    }

    public function testStartsWhatThePassStarts(): void
    {
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 1);
        $feeds = new PassFeeds(self::pass([self::privateFeed(2), $rss]), new FeedLane(2), new FeedLane(3));

        self::assertSame([2, 1], array_keys(iterator_to_array($feeds->started())));
    }

    /** @param list<Feed> $feeds */
    private static function pass(array $feeds): RefreshPass
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');

        return new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);
    }

    private static function privateFeed(int $feedId): Feed
    {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return $feed;
    }
}
```

Replace `backend/tests/Service/Refresh/Pass/PassTicketsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Entity\SocialAccount;
use App\Entity\SocialFeed;
use App\Enum\SocialFeedKind;
use App\Enum\SourceFormat;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Service\Refresh\Pass\PassLane\TimelineLane;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Tests\Support\AssignsEntityIds;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

final class PassTicketsTest extends TestCase
{
    use AssignsEntityIds;

    private RefreshPass $pass;

    public function testFeedsAreTicketedInTheOrderThePassStartsThemWhateverTheirLane(): void
    {
        $account = self::account();
        $home = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $list = self::privateFeed($account, 3, SocialFeedKind::UserList, '42');

        $tickets = $this->passTickets([$home->getFeed(), $rss, $list->getFeed()], [$home, $list])->tickets();

        self::assertSame([1, 2, 3], array_keys(iterator_to_array($tickets)));
    }

    public function testAFeedCountsAsStartedOnlyWhenTheEngineWantsItsTicket(): void
    {
        $one = self::withId(new Feed('https://news.example/one.xml'), 1);
        $two = self::withId(new Feed('https://news.example/two.xml'), 2);

        $this->passTickets([$one, $two], [])->tickets()->current();

        self::assertSame([1], $this->pass->startedFeedIds());
    }

    public function testAnOrphanSettlesWithoutATicketAndTheNextFeedStillStarts(): void
    {
        $orphan = self::privateFeed(self::account(), 1)->getFeed();
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$orphan, $rss], []);

        $tickets = iterator_to_array($passTickets->tickets());
        $unasked = iterator_to_array($passTickets->drainUnasked());

        self::assertSame([2], array_keys($tickets));
        self::assertSame([1], array_keys($unasked));
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $unasked[1]);
        self::assertInstanceOf(FailedTimelineModel::class, $unasked[1]->outcome);
        self::assertInstanceOf(PrivateFeedOrphanedException::class, $unasked[1]->outcome->failure);
        self::assertSame([1, 2], $this->pass->startedFeedIds());
    }

    public function testAnOrphanIsDrainedOnlyOnce(): void
    {
        $orphan = self::privateFeed(self::account(), 1)->getFeed();
        $passTickets = $this->passTickets([$orphan], []);
        iterator_to_array($passTickets->tickets());

        self::assertSame([1], array_keys(iterator_to_array($passTickets->drainUnasked())));
        self::assertSame([], iterator_to_array($passTickets->drainUnasked()));
    }

    public function testAPausedAccountsFeedSettlesWithoutATicket(): void
    {
        $paused = self::account();
        $paused->markNeedsReconnect();
        $timeline = self::privateFeed($paused, 1);
        $passTickets = $this->passTickets([$timeline->getFeed()], [$timeline]);

        self::assertSame([], iterator_to_array($passTickets->tickets()));
        $unasked = iterator_to_array($passTickets->drainUnasked());
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $unasked[1]);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $unasked[1]->outcome);
    }

    public function testTheGateAsksEachFeedsOwnLane(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());

        $account->markNeedsReconnect();

        self::assertTrue($passTickets->admits(2));
        self::assertFalse($passTickets->admits(1));
    }

    public function testEachOutcomeIsSettledByItsFeedsLane(): void
    {
        $account = self::account();
        $timeline = self::privateFeed($account, 1);
        $rss = self::withId(new Feed('https://news.example/feed.xml'), 2);
        $passTickets = $this->passTickets([$timeline->getFeed(), $rss], [$timeline]);
        iterator_to_array($passTickets->tickets());
        $account->markNeedsReconnect();
        $rssOutcome = FetchOutcomeModel::withheld();

        $fetched = $passTickets->settle(2, $rssOutcome)->settledOutcome();
        $held = $passTickets->settle(1, FetchOutcomeModel::withheld())->settledOutcome();

        self::assertInstanceOf(FeedPassOutcomeModel::class, $fetched);
        self::assertSame($rssOutcome, $fetched->outcome);
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $held);
        self::assertInstanceOf(AccountPausedTimelineModel::class, $held->outcome);
    }

    /**
     * @param list<Feed>       $feeds       in due order
     * @param list<SocialFeed> $socialFeeds the private feeds' rows
     */
    private function passTickets(array $feeds, array $socialFeeds): PassTickets
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $this->pass = new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);
        $socialFeedsByFeedId = [];
        foreach ($socialFeeds as $socialFeed) {
            $socialFeedsByFeedId[$socialFeed->getFeed()->requireId()] = $socialFeed;
        }
        $round = SocialApiWiring::timelineRounds(new MockHttpClient(), new ArrayAdapter())->start($socialFeedsByFeedId);

        return new PassTickets(new PassFeeds($this->pass, new FeedLane(2), new TimelineLane($round)));
    }

    private static function account(): SocialAccount
    {
        return self::withId(SocialAccounts::mastodonOn('https://mastodon.example'), 7);
    }

    private static function privateFeed(
        SocialAccount $account,
        int $feedId,
        SocialFeedKind $kind = SocialFeedKind::Home,
        string $remoteId = '',
    ): SocialFeed {
        $feed = self::withId(new Feed(sprintf('social:mastodon:7:%d', $feedId)), $feedId);
        $feed->setSourceFormat(SourceFormat::MASTODON_API);

        return new SocialFeed($account, $feed, $kind, $remoteId);
    }
}
```

(The RSS-ticket shape and the timeline-ticket slots moved to `FeedLaneTest` and `TimelineLaneTest`.)

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'FeedLaneTest|TimelineLaneTest|PassFeedsTest|PassTicketsTest'`
Expected: errors, `Class "App\Service\Refresh\Pass\PassLane\FeedLane" not found`.

- [ ] **Step 3: Create the pass outcomes and the lanes**

`backend/src/Service/Refresh/Model/PassOutcome/PassOutcomeModelInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Pass\PassOutcomeStore;

/**
 * One started feed's outcome as its lane settled it; it knows which of the store's paths it takes.
 *
 * @phpstan-sealed FeedPassOutcomeModel|TimelinePassOutcomeModel
 */
interface PassOutcomeModelInterface
{
    /** @throws \DateMalformedStringException */
    public function storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel;
}
```

`backend/src/Service/Refresh/Model/PassOutcome/FeedPassOutcomeModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Pass\PassOutcomeStore;

/** An RSS or Atom feed's raw fetch outcome. */
final readonly class FeedPassOutcomeModel implements PassOutcomeModelInterface
{
    public function __construct(public FetchOutcomeModel $outcome)
    {
    }

    /** @throws \DateMalformedStringException */
    public function storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel
    {
        return $store->storeFetched($feed, $this->outcome);
    }
}
```

`backend/src/Service/Refresh/Model/PassOutcome/TimelinePassOutcomeModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Model\PassOutcome;

use App\Entity\Feed;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Pass\PassOutcomeStore;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** A private feed's timeline outcome. */
final readonly class TimelinePassOutcomeModel implements PassOutcomeModelInterface
{
    public function __construct(public TimelineOutcomeModelInterface $outcome)
    {
    }

    /** @throws \DateMalformedStringException */
    public function storeIn(PassOutcomeStore $store, Feed $feed): FeedRefreshResultModel
    {
        return $store->storeTimeline($feed, $this->outcome);
    }
}
```

`backend/src/Service/Refresh/Pass/PassLane/PassLaneInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;

/** How one kind of feed goes through a pass: its tickets, the gate, its answers, and what settles without a request. */
interface PassLaneInterface
{
    /** @return \Generator<int|string, RequestTicketModel> none when the feed settles without a request */
    public function ticketsFor(Feed $feed): \Generator;

    public function admits(int|string $feedId): bool;

    /** @return SettlementModel<PassOutcomeModelInterface> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel;

    /** @return \Generator<int|string, PassOutcomeModelInterface> */
    public function drainUnasked(): \Generator;
}
```

`backend/src/Service/Refresh/Pass/PassLane/FeedLane.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;

/** An RSS or Atom feed: one conditional request on its host, always admitted, its raw outcome stored as fetched. */
final readonly class FeedLane implements PassLaneInterface
{
    public function __construct(private int $hostCapacity)
    {
    }

    public function ticketsFor(Feed $feed): \Generator
    {
        yield $feed->requireId() => RequestTicketModel::feed(
            new FetchTicketModel($feed->getUrl(), $feed->getEtag(), $feed->getLastModified()),
            $this->hostCapacity,
        );
    }

    public function admits(int|string $feedId): bool
    {
        return true;
    }

    /** @return SettlementModel<FeedPassOutcomeModel> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        return SettlementModel::outcome(new FeedPassOutcomeModel($outcome));
    }

    public function drainUnasked(): \Generator
    {
        yield from [];
    }
}
```

`backend/src/Service/Refresh/Pass/PassLane/TimelineLane.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass\PassLane;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use App\Service\Social\Pass\TimelineRound;

/** A private feed: its ticket, gate and answers through the pass's timeline round. */
final readonly class TimelineLane implements PassLaneInterface
{
    public function __construct(private TimelineRound $round)
    {
    }

    public function ticketsFor(Feed $feed): \Generator
    {
        return $this->round->ticketFor($feed->requireId());
    }

    public function admits(int|string $feedId): bool
    {
        return $this->round->admits($feedId);
    }

    /** @return SettlementModel<TimelinePassOutcomeModel> */
    public function settle(int|string $feedId, FetchOutcomeModel $outcome): SettlementModel
    {
        return $this->round->settle($feedId, $outcome)->mapOutcome(
            static fn (TimelineOutcomeModelInterface $timeline): TimelinePassOutcomeModel
                => new TimelinePassOutcomeModel($timeline),
        );
    }

    public function drainUnasked(): \Generator
    {
        foreach ($this->round->drainUnasked() as $feedId => $timeline) {
            yield $feedId => new TimelinePassOutcomeModel($timeline);
        }
    }
}
```

- [ ] **Step 4: Rewire the pass onto its lanes**

Replace `backend/src/Service/Refresh/Pass/PassFeeds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Enum\SourceFormat;
use App\Service\Refresh\Pass\PassLane\PassLaneInterface;

/** One pass's feeds as its budget starts them, each feed id's lane decided here, once. */
final readonly class PassFeeds
{
    /** @var array<int, true> */
    private array $privateFeedIds;

    public function __construct(
        private RefreshPass $pass,
        private PassLaneInterface $feedLane,
        private PassLaneInterface $timelineLane,
    ) {
        $this->privateFeedIds = array_fill_keys(self::privateFeedIdsOf($pass), true);
    }

    /** @return list<int> every private feed's id, started or not */
    public static function privateFeedIdsOf(RefreshPass $pass): array
    {
        $privateFeedIds = [];
        foreach ($pass->feeds as $feed) {
            if (SourceFormat::isSocial($feed->getSourceFormat())) {
                $privateFeedIds[] = $feed->requireId();
            }
        }

        return $privateFeedIds;
    }

    /** @return \Generator<int, Feed, mixed, void> */
    public function started(): \Generator
    {
        return $this->pass->startedFeeds();
    }

    public function laneOf(int|string $feedId): PassLaneInterface
    {
        return isset($this->privateFeedIds[$feedId]) ? $this->timelineLane : $this->feedLane;
    }

    /** @return list<PassLaneInterface> */
    public function lanes(): array
    {
        return [$this->feedLane, $this->timelineLane];
    }
}
```

Replace `backend/src/Service/Refresh/Pass/PassTickets.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Service\Fetch\AnswerSettler\AnswerSettlerInterface;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\RequestTicketModel;
use App\Service\Fetch\Model\SettlementModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;

/**
 * One pass's engine tickets in due order and the engine's settler: every call goes to the key's lane.
 *
 * @implements AnswerSettlerInterface<PassOutcomeModelInterface>
 */
final readonly class PassTickets implements AnswerSettlerInterface
{
    public function __construct(private PassFeeds $feeds)
    {
    }

    /** @return \Generator<int|string, RequestTicketModel> */
    public function tickets(): \Generator
    {
        foreach ($this->feeds->started() as $feedId => $feed) {
            yield from $this->feeds->laneOf($feedId)->ticketsFor($feed);
        }
    }

    public function admits(int|string $key): bool
    {
        return $this->feeds->laneOf($key)->admits($key);
    }

    /** @return SettlementModel<PassOutcomeModelInterface> */
    public function settle(int|string $key, FetchOutcomeModel $outcome): SettlementModel
    {
        return $this->feeds->laneOf($key)->settle($key, $outcome);
    }

    /** @return \Generator<int|string, PassOutcomeModelInterface> */
    public function drainUnasked(): \Generator
    {
        foreach ($this->feeds->lanes() as $lane) {
            yield from $lane->drainUnasked();
        }
    }
}
```

Replace `backend/src/Service/Refresh/Pass/PassOutcomeStore.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\Pass;

use App\Entity\Feed;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Refresh\FeedOutcomePersister;
use App\Service\Refresh\Model\FeedOutcome;
use App\Service\Refresh\Model\FeedRefreshResultModel;
use App\Service\Refresh\Model\IncomingFeedModel;
use App\Service\Social\Model\TimelineOutcome\AccountPausedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\AccountThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\FetchedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\ThrottledTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;

/** Stores one refresh's outcomes as they land, each as of the moment the refresh started. */
final readonly class PassOutcomeStore
{
    public function __construct(private FeedOutcomePersister $outcomePersister, private \DateTimeImmutable $now)
    {
    }

    /** @throws \DateMalformedStringException */
    public function storeFetched(Feed $feed, FetchOutcomeModel $outcome): FeedRefreshResultModel
    {
        return $this->outcomePersister->persist($feed, $outcome, $this->now);
    }

    /** @throws \DateMalformedStringException */
    public function storeTimeline(Feed $feed, TimelineOutcomeModelInterface $outcome): FeedRefreshResultModel
    {
        return match (true) {
            $outcome instanceof FetchedTimelineModel => $this->outcomePersister->storeParsed(
                $feed,
                new IncomingFeedModel($outcome->page->parsed, $outcome->page->knownShares),
                $this->now,
            ),
            $outcome instanceof ThrottledTimelineModel => $this->outcomePersister->storeThrottled(
                $feed,
                $outcome->retryAfterSeconds,
            ),
            $outcome instanceof FailedTimelineModel => $this->outcomePersister->storeFailure($feed, $outcome->failure),
            // Held back this refresh: the feed keeps its schedule for the next one.
            $outcome instanceof AccountThrottledTimelineModel => FeedRefreshResultModel::of(FeedOutcome::Throttled),
            $outcome instanceof AccountPausedTimelineModel => FeedRefreshResultModel::of(FeedOutcome::Failed),
        };
    }
}
```

Replace `backend/src/Service/Refresh/PassFetcher/PassFetcherInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Entity\SocialFeed;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Refresh\Pass\RefreshPass;

interface PassFetcherInterface
{
    /**
     * Each started feed's outcome under its feed id, as it lands. Every started feed yields exactly one. Abandoning the
     * iterator cancels what is in flight.
     *
     * @param array<int, SocialFeed> $socialFeedsByFeedId the pass's private feeds' rows, each with its account loaded
     *
     * @return iterable<int|string, PassOutcomeModelInterface>
     */
    public function fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): iterable;
}
```

Replace `backend/src/Service/Refresh/PassFetcher/ConcurrentPassFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh\PassFetcher;

use App\Entity\SocialFeed;
use App\Service\Fetch\ConcurrentRequestEngine;
use App\Service\Fetch\Support\CapacityGuard;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassLane\FeedLane;
use App\Service\Refresh\Pass\PassLane\TimelineLane;
use App\Service\Refresh\Pass\PassTickets;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Social\TimelineRounds;

/** Reads a pass's due feeds on one engine run, in due order whatever their kind; a stale session renews inside it. */
final readonly class ConcurrentPassFetcher implements PassFetcherInterface
{
    public function __construct(
        private ConcurrentRequestEngine $engine,
        private TimelineRounds $timelineRounds,
        private int $hostConcurrency,
    ) {
        CapacityGuard::atLeastOne($hostConcurrency, 'Per-host concurrency');
    }

    /**
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     *
     * @return \Generator<int|string, PassOutcomeModelInterface>
     */
    public function fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): \Generator
    {
        $timelineLane = new TimelineLane($this->timelineRounds->start($socialFeedsByFeedId));
        $tickets = new PassTickets(new PassFeeds($pass, new FeedLane($this->hostConcurrency), $timelineLane));

        foreach ($this->engine->sendAll($tickets->tickets(), $tickets) as $feedId => $outcome) {
            yield from $tickets->drainUnasked();
            yield $feedId => $outcome;
        }

        yield from $tickets->drainUnasked();
    }
}
```

Replace `backend/src/Service/Refresh/RefreshPassRecorder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Refresh;

use App\Repository\SocialFeedRepository;
use App\Service\Refresh\Pass\PassFeeds;
use App\Service\Refresh\Pass\PassOutcomeStore;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;

/** Stores each of a pass's outcomes as it lands, whatever its feed's kind, one at a time on the one EntityManager. */
final readonly class RefreshPassRecorder
{
    public function __construct(
        private PassFetcherInterface $fetcher,
        private FeedOutcomePersister $outcomePersister,
        private SocialFeedRepository $socialFeeds,
    ) {
    }

    /** @throws \DateMalformedStringException */
    public function record(RefreshPass $pass, \DateTimeImmutable $now): void
    {
        $outcomes = new PassOutcomeStore($this->outcomePersister, $now);
        $socialFeedsByFeedId = $this->socialFeeds->findWithAccountByFeedIds(PassFeeds::privateFeedIdsOf($pass));
        foreach ($this->fetcher->fetchAll($pass, $socialFeedsByFeedId) as $feedId => $outcome) {
            $feed = $pass->feed($feedId);
            $pass->tally->record($outcome->storeIn($outcomes, $feed), $feed);
            if ($pass->tally->isAborted()) {
                return;
            }
        }
    }
}
```

Replace `backend/tests/Support/StubPassFetcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Feed;
use App\Entity\SocialFeed;
use App\Enum\SourceFormat;
use App\Service\Fetch\Exception\FetchException;
use App\Service\Fetch\Model\FetchOutcomeModel;
use App\Service\Fetch\Model\FetchResponseModel;
use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;
use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;
use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;
use App\Service\Refresh\Pass\RefreshPass;
use App\Service\Refresh\PassFetcher\PassFetcherInterface;
use App\Service\Social\Exception\PrivateFeedOrphanedException;
use App\Service\Social\Model\TimelineOutcome\FailedTimelineModel;
use App\Service\Social\Model\TimelineOutcome\TimelineOutcomeModelInterface;
use PHPUnit\Framework\Assert;
use Symfony\Component\Clock\MockClock;

/** A refresh pass scripted by URL: each RSS feed's answer and each private feed's timeline outcome, in waves. */
final class StubPassFetcher implements PassFetcherInterface
{
    private readonly StubFeedFetcher $feeds;

    /** @var array<string, TimelineOutcomeModelInterface> */
    private array $timelines = [];

    /** @var list<int> */
    public array $asked = [];

    /** @var array<int, SocialFeed> */
    public array $socialFeedsByFeedId = [];

    /** Wall-clock cost of one wave of concurrent fetches, not of one fetch. */
    public int $secondsPerFetch = 0;

    public function __construct(private readonly ?MockClock $clock = null, private readonly int $concurrency = 8)
    {
        $this->feeds = new StubFeedFetcher();
    }

    public function willReturn(string $url, FetchResponseModel $response): void
    {
        $this->feeds->willReturn($url, $response);
    }

    public function willReturnBody(string $url, string $body): void
    {
        $this->feeds->willReturnBody($url, $body);
    }

    public function willThrow(string $url, FetchException $exception): void
    {
        $this->feeds->willThrow($url, $exception);
    }

    public function willReadTimeline(string $url, TimelineOutcomeModelInterface $outcome): void
    {
        $this->timelines[$url] = $outcome;
    }

    /** @return list<string> */
    public function fetchedUrls(): array
    {
        return $this->feeds->fetchedUrls;
    }

    /**
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     *
     * @return \Generator<int, PassOutcomeModelInterface>
     */
    public function fetchAll(RefreshPass $pass, array $socialFeedsByFeedId): \Generator
    {
        $wave = [];
        foreach ($pass->startedFeeds() as $feedId => $feed) {
            $wave[$feedId] = $feed;
            if (\count($wave) < $this->concurrency) {
                continue;
            }

            yield from $this->runWave($socialFeedsByFeedId, $wave);
            $wave = [];
        }

        if ([] !== $wave) {
            yield from $this->runWave($socialFeedsByFeedId, $wave);
        }
    }

    /**
     * @param array<int, SocialFeed> $socialFeedsByFeedId
     * @param array<int, Feed>       $wave
     *
     * @return \Generator<int, PassOutcomeModelInterface>
     */
    private function runWave(array $socialFeedsByFeedId, array $wave): \Generator
    {
        if ($this->secondsPerFetch > 0) {
            $this->clock?->sleep($this->secondsPerFetch);
        }

        foreach ($wave as $feedId => $feed) {
            yield $feedId => SourceFormat::isSocial($feed->getSourceFormat())
                ? new TimelinePassOutcomeModel($this->timelineOf($socialFeedsByFeedId, $feedId))
                : new FeedPassOutcomeModel($this->fetched($feed));
        }
    }

    private function fetched(Feed $feed): FetchOutcomeModel
    {
        return $this->feeds->fetchAll([new FetchTicketModel($feed->getUrl())])->current();
    }

    /** @param array<int, SocialFeed> $socialFeedsByFeedId */
    private function timelineOf(array $socialFeedsByFeedId, int $feedId): TimelineOutcomeModelInterface
    {
        $socialFeed = $socialFeedsByFeedId[$feedId] ?? null;
        if (null === $socialFeed) {
            return new FailedTimelineModel(new PrivateFeedOrphanedException());
        }
        $this->asked[] = $feedId;
        $this->socialFeedsByFeedId[$feedId] = $socialFeed;
        $url = $socialFeed->getFeed()->getUrl();

        return $this->timelines[$url] ?? Assert::fail('No timeline outcome scripted for ' . $url);
    }
}
```

- [ ] **Step 5: Move the pass-fetcher tests onto the new seam**

`backend/tests/Service/Refresh/PassFetcher/PassFetcherWiringTest.php`:
1. Replace `use App\Service\Refresh\Pass\PassFeeds;` with
   `use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;` (sorted).
2. Replace the loop in `testTheContainerReadsEachNetworksTimelineThroughItsOwnWiring` with:

```php
        $outcomes = [];
        foreach ($fetcher->fetchAll($pass, [1 => $mastodon, 2 => $bluesky]) as $feedId => $outcome) {
            self::assertInstanceOf(TimelinePassOutcomeModel::class, $outcome);
            $outcomes[$feedId] = $outcome->outcome::class;
        }
```

`backend/tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php`:
1. Imports: delete `use App\Service\Refresh\Pass\PassFeeds;`; add
   `use App\Service\Refresh\Model\PassOutcome\FeedPassOutcomeModel;`,
   `use App\Service\Refresh\Model\PassOutcome\PassOutcomeModelInterface;`,
   `use App\Service\Refresh\Model\PassOutcome\TimelinePassOutcomeModel;` (sorted).
2. Replace the private static `passFeeds()` helper with these two:

```php
    /**
     * @param list<Feed|SocialFeed> $entries in due order: an RSS feed as itself, a private feed as its social feed
     *
     * @return \Generator<int|string, FetchOutcomeModel|TimelineOutcomeModelInterface> each lane's outcome, unwrapped
     */
    private static function fetchAllOf(ConcurrentPassFetcher $fetcher, array $entries): \Generator
    {
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $feeds = [];
        $socialFeedsByFeedId = [];
        foreach ($entries as $entry) {
            $feed = $entry instanceof SocialFeed ? $entry->getFeed() : $entry;
            $feeds[] = $feed;
            if ($entry instanceof SocialFeed) {
                $socialFeedsByFeedId[$feed->requireId()] = $entry;
            }
        }
        $pass = new RefreshPass($feeds, $clock, $clock->now()->getTimestamp() + 300);

        foreach ($fetcher->fetchAll($pass, $socialFeedsByFeedId) as $feedId => $outcome) {
            yield $feedId => self::unwrapped($outcome);
        }
    }

    private static function unwrapped(
        PassOutcomeModelInterface $outcome,
    ): FetchOutcomeModel|TimelineOutcomeModelInterface {
        return match (true) {
            $outcome instanceof FeedPassOutcomeModel => $outcome->outcome,
            $outcome instanceof TimelinePassOutcomeModel => $outcome->outcome,
        };
    }
```

3. Replace every `$fetcher->fetchAll(self::passFeeds($X))` and `->fetchAll(self::passFeeds($X))` with
   `self::fetchAllOf($fetcher, $X)` (for a chained `$this->fetcher(...)->fetchAll(self::passFeeds($X))`, assign the
   fetcher to `$fetcher` first). The sites: `testAbandoningTheReadSendsNothingMore`,
   `testAbandoningTheReadCancelsWhatIsInFlight`, `testAbandoningAMixedPassCancelsEveryRequestInFlight`,
   `testAbandoningThePassCancelsAFollowUpInFlight`, `onTheWireAtFirstAnswer()`, `landings()`. Check with
   `grep -n 'passFeeds\|PassFeeds' tests/Service/Refresh/PassFetcher/ConcurrentPassFetcherTest.php` → no output.
4. `testAFollowUpIsNeverCountedAsAStartAgainstTheBudget` — replace its `$outcomes = iterator_to_array(...)` with:

```php
        $outcomes = [];
        $fetcher = $this->fetcher(self::renewingStaleSessions());
        foreach ($fetcher->fetchAll($pass, [self::idOf($home) => $home]) as $feedId => $outcome) {
            $outcomes[$feedId] = self::unwrapped($outcome);
        }
```

5. Add one test that the seam yields lane-typed outcomes:

```php
    public function testEachOutcomeComesInItsFeedsLane(): void
    {
        $respond = static fn (string $method, string $url): MockResponse => str_starts_with($url, self::NEWS)
            ? self::rss()
            : self::statuses();
        $rss = $this->rssFeed(self::NEWS . '/one.xml');
        $home = $this->feedOf(self::mastodonAccount(self::MASTODON, 1));
        $clock = new MockClock('2026-10-13 12:00:00', 'UTC');
        $pass = new RefreshPass([$rss, $home->getFeed()], $clock, $clock->now()->getTimestamp() + 300);

        $outcomes = iterator_to_array($this->fetcher($respond)->fetchAll($pass, [self::idOf($home) => $home]));

        self::assertInstanceOf(FeedPassOutcomeModel::class, $outcomes[$rss->requireId()]);
        self::assertInstanceOf(TimelinePassOutcomeModel::class, $outcomes[self::idOf($home)]);
    }
```

- [ ] **Step 6: Run the focused tests**

Run: `php bin/phpunit --filter 'FeedLaneTest|TimelineLaneTest|PassFeedsTest|PassTicketsTest|ConcurrentPassFetcherTest|PassFetcherWiringTest|RefreshPassRecorder|RefreshRunner|MaintenanceTick|RefreshDueFeedsHandlerTest|RefreshControllerTest|MaintenanceControllerTest|RefreshFeedsCommandTest'`
Expected: all pass.

Run: `grep -rn 'isPrivate(\|->store(\$feed\|socialFeedsByFeedId' src/Service/Refresh`
Expected: only `PassFetcherInterface.php`, `ConcurrentPassFetcher.php` and `RefreshPassRecorder.php` mention
`socialFeedsByFeedId`; no `isPrivate(` and no `->store(`.

- [ ] **Step 7: Gates**

Run: `bin/console cache:warmup`, then `composer check`, `composer md`, `composer test:parallel`
Expected: clean and green; module-cycle greps print nothing; `ServiceRoleRule` reports nothing for `Pass/PassLane/` or
`Model/PassOutcome/`.

- [ ] **Step 8: Commit** (repo root)

```bash
git add -A backend/src/Service/Refresh backend/tests/Support/StubPassFetcher.php \
  backend/tests/Service/Refresh/Pass backend/tests/Service/Refresh/PassFetcher
git commit -m "refactor(#1632): a feed's kind is decided once, as its lane, and its outcome stores itself"
```

---

### Task 5: Final gates

**Files:** none unless a gate finds something; fix it in the task's own style and commit as `fix(#1632): …`.

- [ ] **Step 0: Merge develop** (repo root) and rerun Task 4's Step 6 filter.

- [ ] **Step 1: Static gates**

Run (from `backend/`): `composer check` and `composer md`
Expected: clean. phptramp prints its version line first; if it fails on code this branch did not touch, check
`composer show larspohlmann/phptramp` before hunting in application code (CLAUDE.md).

- [ ] **Step 2: PhpStorm inspections** on every PHP file this branch changed:
  `git diff --name-only origin/develop... -- '*.php'` → `mcp__phpstorm__lint_files` on that list. Block on ERROR and
  WARNING.

- [ ] **Step 3: Both test legs**

Run (from `backend/`): `composer test:parallel` — Expected: green.
Run (repo root, after confirming the containers are current per the standing rule):
`docker compose exec php composer test` — Expected: green (the MySQL leg).

- [ ] **Step 4: Mutation gate**

Run (from `backend/`): `composer infection:diff` — Expected: MSI at or above `minMsi`. Kill an escaped mutant with a
test, never by lowering the threshold. (A first-run 512M OOM is a known flake: rerun.)

- [ ] **Step 5: Dev log**

Run: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`
Expected: nothing new from this branch's code.

- [ ] **Step 6: Report** the gate results to the planner. Do not push, do not open a PR unless asked.

---

## Self-review

- **Spec coverage:**
  - §1 settler (`admits`/`settle`, `SettlementModel` with `outcome`/`followUp`, engine output `\Generator<int|string, T>`,
    every yield through `settle`, follow-up via `FetchQueue` as `FetchAttemptModel::start` with the batch proxy,
    `admits` asked again, `failEvery` settles and a follow-up there is a `\LogicException`, pass-through settler for
    `ConcurrentFeedFetcher`, `DispatchGateInterface` deleted, abort cancels follow-ups): Task 2, abort in Task 3.
  - §1 renewal rule (second stale answer per feed is a refusal; at most one renewal per account per pass;
    `ticketOf` returning held / failure / follow-up; deletions; one loop in `ConcurrentPassFetcher`): Task 3.
  - §2 lanes, `laneOf`, `isPrivate()` deleted, `PassTickets` delegates, `PassOutcomeModelInterface` + two models,
    `storeFetched`/`storeTimeline`, seam yields the interface, recorder calls `storeIn`, stub wraps by source format:
    Task 4.
  - §3 typed slots, class-namespaced keys, Social's two slots, `RequestSlotModel` deleted, test-only fixed-key slot,
    `CapacityGuard` in every slot type: Task 1.
  - Done-when tests:
    - stale session renewed and re-sent inside one run → `testAStaleSessionsFeedIsSentAgainInsideTheOneRun` (Task 3);
    - two in-flight stale reads, one `dropSession`, one session open, both re-sent once →
      `testTwoInFlightStaleReadsOfOneAccountRenewItsSessionOnceAndAreEachSentOnceMore` and
      `TimelineRoundTest::testAStaleAnswerRenewsTheSessionOnceAndFollowsUpOnTheFreshOne` (Task 3);
    - second stale answer → needs-reconnect, gate withholds the others →
      `testAFeedsSecondStaleAnswerPausesItsAccountAndTheGateWithholdsItsOtherFeeds` (pass) and
      `TimelineRoundTest::testAFeedsSecondStaleAnswerRefusesItsAccountAndTheGateWithholdsItsOtherFeeds` (Task 3);
    - follow-up not a budget start, gate asked → `testAFollowUpIsNeverCountedAsAStartAgainstTheBudget` (Task 3),
      `testTheSettlerIsAskedAboutAFollowUp`, `testAFollowUpGoesAheadOfUnstartedTicketsWithoutDrawingOnTheirSource`,
      `FetchQueueTest::testAFollowUpNeverAdvancesTheTicketSource` (Task 2);
    - abort cancels an in-flight follow-up → `testAbandoningThePassCancelsAFollowUpInFlight` (Task 3);
    - RSS host slot vs social-server slot on one host → `SocialServerSlotModelTest::testAnRssHostSlotAndASocialServerSlotOnOneHostNeverShareACap`
      (Task 1) plus the existing `testAFeedAndATimelineOnOneHostNeverCountAgainstEachOther`;
    - two slot types with an equal key → `RequestSlotsTest::testTwoSlotTypesWithAnEqualKeyNeverShareACap` (Task 1);
    - every existing refresh, timeline, engine and slot test keeps passing: each task's filtered run plus
      `composer test:parallel`.
- **Placeholder scan:** every new class and test is shown whole; edits to existing files name the file, the method or
  a `sed`/grep, and the exact replacement.
- **Type consistency:** see the Interfaces blocks; the preflight below checks each pair of tasks.

## Preflight notes

Conflict scan between tasks (produced vs consumed):

- Task 1 → 2: `ConcurrentRequestEngineTest` uses `FixedKeySlot(string, int)` in both; Task 2 replaces the file whole,
  so Task 1's `sed` edit only has to keep the suite green in between. `RequestTicketModel::api(ApiRequestModel, list<RequestSlotModelInterface>)`
  is what `TimelineSlotsModel` (Task 1) and `ScriptedSettler` tickets (Task 2) call.
- Task 1 → 4: `PassTicketsTest`'s slot assertions written in Task 1 move to `FeedLaneTest`/`TimelineLaneTest` in Task 4
  with the same `assertEquals([new HostSlotModel(2)], …)` / `[new SocialAccountSlotModel(7, 3), new SocialServerSlotModel(self::HOME, 4)]`.
- Task 2 → 3: `PassTickets::settle()` returns `SettlementModel<…|null>` in Task 2 and `SettlementModel<…>` in Task 3;
  `ConcurrentPassFetcher::run()` (Task 2) is deleted by Task 3's whole-file replacement. `TimelineRound` drops
  `implements DispatchGateInterface` in Task 2 and is replaced whole in Task 3. `SettlementModel` methods consumed in
  Task 3 (`outcome`, `followUp`, `isFollowUp`, `followUpTicket`, `settledOutcome`) all exist from Task 2.
- Task 3 → 4: `TimelineRound::ticketFor/admits/settle/drainUnasked` signatures are what `TimelineLane` calls;
  `settle()` returns `SettlementModel<TimelineOutcomeModelInterface>`, mapped with `mapOutcome()` (Task 2). The tests
  Task 3 adds to `ConcurrentPassFetcherTest` call `fetchAll(new PassFeeds(...))`/`self::passFeeds()`; Task 4 Step 5
  lists each of them for the move to `fetchAll(RefreshPass, array)`/`fetchAllOf()`.
- Task 4 internal: `PassFetcherInterface::fetchAll(RefreshPass, array)` is implemented by `ConcurrentPassFetcher` and
  `StubPassFetcher`, called by `RefreshPassRecorder`, `PassFetcherWiringTest` and `ConcurrentPassFetcherTest`; no other
  caller (`grep -rn 'fetchAll(' src/Service/Refresh tests | grep -i pass`).
- `SettlementModel`'s generics were checked with PHPStan max on a scratch copy: `followUp()` infers `self<never>`,
  `mapOutcome()` maps `SettlementModel<TimelineOutcomeModelInterface>` to `SettlementModel<TimelinePassOutcomeModel>`,
  which is accepted where `SettlementModel<PassOutcomeModelInterface>` is declared (covariant `T`), and the engine's
  `@template T` flows from the settler to the generator's values.

Rulings on what the spec leaves open:

1. **Interface folders.** `RequestSlotModelInterface` sits in `Fetch/Model/RequestSlot/` and `PassOutcomeModelInterface`
   in `Refresh/Model/PassOutcome/`, not flat in `Model/`: `RoleFolderInterfaces` puts an interface family in its own
   subfolder whenever `Model/` holds other classes.
2. **Lanes live in `Refresh/Pass/PassLane/`**, not `Refresh/PassLane/`. Outside a role folder, `ServiceShapes` treats a
   class implementing a same-module interface as a service and rejects `TimelineLane` for taking the per-call
   `TimelineRound`. Under `Pass/` they are per-call objects, excluded from the container.
3. **`PassFeeds` holds the lanes, so `ConcurrentPassFetcher` builds it.** The lanes need the pass's `TimelineRound`,
   which only the fetcher starts. `PassFetcherInterface::fetchAll()` therefore takes the `RefreshPass` and the private
   feeds' rows (`array<int, SocialFeed>`) instead of a `PassFeeds`; `RefreshPassRecorder` loads the rows as before.
4. **A parked ticket built on the dropped session.** The spec's renewal rule covers in-flight requests only. A ticket
   pulled before its account's renewal and still parked would otherwise go out on the stale token and cost one more
   request than today, and `testAFeedWaitingForARenewedSessionIsNotSentOnTheStaleOne` would fail. `TimelineRound::admits()`
   therefore also refuses a ticket built before its account's renewal; its withheld settlement goes through `ticketOf`
   and comes back as a follow-up on the fresh session, which is admitted. This is the "renewed once" check's second
   use, and it keeps the request count per stale feed at today's one more.
5. **Names.** The settler interface is `AnswerSettlerInterface` (the spec's working name) in `Fetch/AnswerSettler/`, with
   `PassThroughSettler` beside it; `ConcurrentFeedFetcher` gets it injected (wired in `services.yaml`) rather than
   building it, per "no `new` on a collaborator". The engine's per-run pair of settler and queue is a Fetch `Pass/`
   object, `SettledAnswers`, which keeps `fill()`/`awaitNext()` at three parameters.
6. **Follow-ups join `FetchQueue`'s continuations**, ahead of unstarted tickets like a redirect hop, as fresh first-send
   attempts (`FetchAttemptModel::start` with the batch proxy), so they finish an open feed before new feeds start.
7. **`SettlementModel` stores its value as a list of zero or one item**, so `followUp()` is typed `self<never>` without
   an unsafe cast. Its accessors are `isFollowUp()`, `followUpTicket()` and `settledOutcome()` (both throw
   `\LogicException` on the wrong kind) and `mapOutcome()`; the static constructors keep the spec's names.
8. **`failEvery` asks no `admits()`**, as today: nothing goes on the wire on that path.
9. **Task 2 keeps the renewal wave for one task:** `PassTickets` settles to `SettlementModel<…|null>` and
   `ConcurrentPassFetcher` still filters null, so Tasks 2 and 3 can be reviewed apart. Task 3 removes both.
10. **`dropSession` is counted through the session cache** (`CountingArrayAdapter` counts `deleteItem`), since
    `BlueskySessions::forget()` is the only path that deletes from it during a pass.
11. **The "one run" test is about timing, not request lines.** With one run or two, the request lines of a stale
    session beside an RSS feed are the same, so the test makes the RSS answer slow and asserts the renewed read lands
    first, which a second run cannot do. Measured on `MockHttpClient`: a 20-chunk body lands after a follow-up started
    later.
