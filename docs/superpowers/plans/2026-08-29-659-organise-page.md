# Organise Page and Bulk Subscription Endpoints — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a wide settings section at `/settings/organise` that shows every tag and every feed as a collapsible tree (plus a flat list view), supports multi-select with a bulk action bar, and writes through two new bulk endpoints that flush once and reject any id the caller does not own.

**Architecture:** The backend gains one ownership resolver (`OwnedSubscriptions`), one bulk writer (`BulkSubscriptionUpdater`) that loops over the existing `SubscriptionTagSync` and flushes once, and `SubscriptionService::unsubscribeAll`, which reclaims each distinct orphaned feed after that single flush. Two thin controller actions map request DTOs onto them. The frontend gains one selection-only store (`OrganiseStore`, provided by the page component so the selection dies with the page), three presentational components, one popover, and five new methods on `ManageActions` — the page itself holds no `ReaderApi` call.

**Tech Stack:** PHP 8.4, Symfony 7.4 LTS, Doctrine ORM, PHPUnit, Infection; Angular 20 standalone components with signals, Transloco, Angular CDK `drag-drop` and `dialog`, Jest, Playwright.

**Spec:** [docs/superpowers/specs/2026-08-29-659-organise-page-design.md](../specs/2026-08-29-659-organise-page-design.md)

## Execution order

Run the tasks **1, 2, 3, 4, 5, 6, 7, 12, 8, 9, 10, 11, 13, 14** — Task 12
(translations) moves ahead of the components. `provideTranslocoTesting` loads the
real shipped `en.json`, and `settings.routes.spec.ts` asserts `hasTranslation()`
for every registered section, so a component task that lands before its keys
red-fails on a missing translation rather than on its own logic.

## Global Constraints

**Both sides**

- Branch `feature/659-organise-page`, off `develop`. Commit message format `type(#659): summary`.
- TDD without exception: write the failing test, run it and watch it fail for the stated reason, implement the minimum, run it and watch it pass, commit.
- Concurrent Claude sessions share this checkout. Check `git status` before any `checkout`, `reset` or `stash`.

**Backend** (all commands run from `backend/`)

- `declare(strict_types=1);` in every PHP file.
- Clean Code is mandatory: names reveal intent (no `$data`, `$info`, `$tmp`); functions do one thing at one level of abstraction; three parameters is a lot; **no boolean flag parameters**; guard clauses over nesting; no hidden side effects behind a `get…`; `final readonly class` with constructor promotion; depend on interfaces; comments explain *why*.
- Every `src` file touched must be clean under `composer cs`, `composer stan` (level max, needs `bin/console cache:warmup` first), `composer md` (PHPMD codesize) and `composer tramp`. Not merely free of *new* findings — clean.
- `ThinControllerRule` (`tests/PhpStan/ThinControllerRule.php`): a controller action reads the request, delegates, and returns a response. No private controller method may carry responsibility. Its allow-list only ever shrinks.
- A service may throw `UnprocessableEntityHttpException` directly — `Service/Reader/ExactSetGuard.php` is the precedent, and `ApiExceptionListener` renders it as `application/problem+json`. Do **not** invent a custom exception class for this plan.
- Datetimes are stored as naive UTC. Nothing in this plan writes one.
- Run the suite natively with `php bin/phpunit` (SQLite) and again with `docker compose exec php vendor/bin/phpunit` (MySQL) before the PR.

**Frontend** (all commands run from `frontend/`)

- Standalone components and signals. No NgModules. `ChangeDetectionStrategy.OnPush` on every new component.
- Component styles live in a **sibling `.scss` file** referenced by `styleUrl`, never inline in the `.ts`: Stylelint has no TS syntax installed and would silently skip them.
- **No hex colours, no raw `px` spacing values, no media-query literals** outside `src/app/theme/`. Use `var(--space-*)`, `var(--radius*)`, `var(--surface-*)`, `var(--text-*)`, `var(--border*)`, `var(--accent*)`, `var(--danger)`, and `@use '../../theme/breakpoints' as bp;` with `bp.$bp-sm` / `bp.$bp-md` / `bp.$bp-lg`.
- Read [docs/design-language.md](../../design-language.md) §2 (component catalog), §3 (density, sticky, overlay) and §8 (adding a settings section) before writing a template.
- Every new string goes into **both** `public/i18n/en.json` and `public/i18n/de.json`. A key in one file only fails review.
- Never nest `cdkDropList`s. Sibling lists only — nesting silently breaks cross-list drag.
- Unit tests run inside Docker: `docker compose exec -T frontend npm test`. The CI gate is `npm run check` (ESLint + Prettier + Stylelint + Jest). Prettier's print width is 100.

**Existing pieces this plan builds on — do not reimplement them**

| Thing | Where | What it already does |
|---|---|---|
| `SubscriptionTagSync::sync(Subscription, list<int> $tagIds, int $userId)` | `src/Service/Subscription/SubscriptionTagSync.php` | Diffs the tag set, appends a new tag at that tag's next position, and appends a feed that lost its **last** tag to the untagged list. |
| `OrphanedFeedReclaimer::reclaim(int $feedId): bool` | `src/Service/OrphanedFeedReclaimer.php` | Deletes a feed nobody subscribes to. Must run **after** the flush. |
| `SubscriptionRepository::findAllByIdsForUser(array $ids, int $userId): list<Subscription>` | `src/Repository/SubscriptionRepository.php:73` | Ownership-scoped lookup. |
| `TagRepository::findAllByIdsForUser(array $ids, int $userId): list<Tag>` | `src/Repository/TagRepository.php:29` | Same, for tags. |
| `ManageActions` | `src/app/reader/manage/manage-actions.service.ts` | The single place a management dialog opens and its side effects apply. |
| `ConfirmDialogComponent` / `ConfirmData` | `src/app/shared/confirm-dialog/` | `{ title, message, confirmLabel, danger?, requireText? }`. `requireText` disables Confirm until the user types that exact string. |
| `ToastService.show({ message, durationMs })` | `src/app/shared/toast/toast.service.ts` | `CONFIRMATION_DURATION_MS` is the 3000 ms confirmation duration. |
| `ActionSheet.open({ title, actions })` | `src/app/shared/action-sheet/` | The coarse-pointer row menu. Actions are `{ id, label, danger? }`. |
| `OverlayPanelComponent` | `src/app/shared/overlay-panel/` | The dialog shell. Its title input is `heading`, **not** `title`. |

---

## File Structure

### Backend

| File | Change | Responsibility |
|---|---|---|
| `src/Dto/Subscription/BulkUpdateSubscriptionsRequest.php` | Create | Subscription ids, add/remove tag ids, the two nullable flags. Carries the 500 cap. |
| `src/Dto/Subscription/BulkUnsubscribeRequest.php` | Create | Subscription ids only, same cap. |
| `src/Service/Subscription/OwnedSubscriptions.php` | Create | Resolves ids to the caller's subscriptions, keyed by id, or throws `422`. |
| `src/Service/Subscription/BulkSubscriptionUpdater.php` | Create | Applies one tag add, one tag remove and the two flags across many feeds through `SubscriptionTagSync`; one flush. |
| `src/Service/Subscription/SubscriptionService.php` | Modify | Add `unsubscribeAll(array $subscriptions): int`. |
| `src/Controller/Api/SubscriptionController.php` | Modify | Two new actions; `reorder` moves onto `OwnedSubscriptions`. |
| `tests/Service/Subscription/OwnedSubscriptionsTest.php` | Create | Kernel test with real entities. |
| `tests/Service/Subscription/BulkSubscriptionUpdaterTest.php` | Create | Kernel test: add, remove, last-tag-lost, flags, contradiction, foreign tag. |
| `tests/Service/Subscription/UnsubscribeAllTest.php` | Create | Kernel test: distinct-feed reclaim, return value. |
| `tests/Controller/Api/SubscriptionBulkTest.php` | Create | Functional tests for both endpoints. |

### Frontend

| File | Change | Responsibility |
|---|---|---|
| `src/app/reader/models.ts` | Modify | `BulkSubscriptionUpdate` interface. |
| `src/app/reader/reader-api.ts` | Modify | `bulkUpdateSubscriptions`, `bulkUnsubscribe`. |
| `src/app/reader/manage/manage-actions.service.ts` | Modify | `bulkAddTag`, `bulkRemoveTag`, `bulkSetFlags`, `bulkUnsubscribe`, `addFeed`. Moving a feed between tags reuses the existing `retag`. |
| `src/app/settings/organise/organise.store.ts` | Create | Selection, expanded groups, view, filters, sort. **No writes.** |
| `src/app/settings/organise/organise-feed-row.component.{ts,html,scss}` | Create | One feed row; used by the tree and the list. |
| `src/app/settings/organise/organise-tag-group.component.{ts,html,scss}` | Create | One tag panel: the header row plus its feed rows and its drop list. |
| `src/app/settings/organise/bulk-tag-dialog.component.{ts,html,scss}` | Create | The Add-tag and Remove-tag popover; one component, two modes. |
| `src/app/settings/organise/organise-section.component.{ts,html,scss}` | Create | The page: toolbar, bulk bar, tree view, list view. |
| `src/app/settings/settings-sections.ts` | Modify | One `organise` entry, first in `general`, `wide: true`. |
| `src/app/settings/settings.routes.ts` | Modify | One lazy child route. |
| `public/i18n/en.json`, `public/i18n/de.json` | Modify | The `settings.organise.*` keys. |
| `e2e/organise-bulk-tag.spec.ts` | Create | One Playwright smoke that owns its fixture. |

---

# Backend

## Task 1: `OwnedSubscriptions`

`SubscriptionController::reorder` already inlines "resolve the ids, count them, throw `422` on a mismatch". Both new endpoints need the same rule. That is the third occurrence, so it becomes a collaborator and `reorder` moves onto it.

**Files:**
- Create: `backend/src/Service/Subscription/OwnedSubscriptions.php`
- Modify: `backend/src/Controller/Api/SubscriptionController.php` (the `reorder` action, around line 130-152)
- Test: `backend/tests/Service/Subscription/OwnedSubscriptionsTest.php`

**Interfaces:**
- Consumes: `App\Repository\SubscriptionRepository::findAllByIdsForUser(array $ids, int $userId): list<Subscription>`.
- Produces: `OwnedSubscriptions::resolve(array $ids, int $userId): array<int, Subscription>` — keyed by subscription id, in no guaranteed order. Tasks 5 and 4 rely on it.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Subscription/OwnedSubscriptionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Subscription\OwnedSubscriptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class OwnedSubscriptionsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private OwnedSubscriptions $owned;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $owned = self::getContainer()->get(OwnedSubscriptions::class);
        self::assertInstanceOf(OwnedSubscriptions::class, $owned);
        $this->owned = $owned;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);

        return $subscription;
    }

    public function testResolvesOwnedIdsKeyedById(): void
    {
        $user = $this->user('owner-resolves@example.com');
        $first = $this->subscription($user, 'https://first.example/feed.xml');
        $second = $this->subscription($user, 'https://second.example/feed.xml');
        $this->em->flush();

        $resolved = $this->owned->resolve(
            [(int) $second->getId(), (int) $first->getId()],
            (int) $user->getId(),
        );

        self::assertCount(2, $resolved);
        self::assertSame($first, $resolved[(int) $first->getId()]);
        self::assertSame($second, $resolved[(int) $second->getId()]);
    }

    public function testRejectsAnIdThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('owner-mine@example.com');
        $theirs = $this->user('owner-theirs@example.com');
        $ours = $this->subscription($mine, 'https://ours.example/feed.xml');
        $foreign = $this->subscription($theirs, 'https://foreign.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->owned->resolve(
            [(int) $ours->getId(), (int) $foreign->getId()],
            (int) $mine->getId(),
        );
    }

    public function testRejectsAnIdThatDoesNotExist(): void
    {
        $user = $this->user('owner-missing@example.com');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->owned->resolve([999_999], (int) $user->getId());
    }

    public function testRejectsADuplicateId(): void
    {
        $user = $this->user('owner-duplicate@example.com');
        $subscription = $this->subscription($user, 'https://dupe.example/feed.xml');
        $this->em->flush();

        $id = (int) $subscription->getId();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->owned->resolve([$id, $id], (int) $user->getId());
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
cd backend && php bin/phpunit tests/Service/Subscription/OwnedSubscriptionsTest.php
```

Expected: every case errors with `Service "App\Service\Subscription\OwnedSubscriptions" not found` — the class does not exist yet.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/Subscription/OwnedSubscriptions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Resolves a request's subscription ids to the caller's own subscriptions.
 *
 * Every endpoint that takes a list of subscription ids needs the same refusal:
 * an id the caller does not own, an id that does not exist, and a duplicate are
 * all the same answer — 422, and nothing written. Three endpoints needed it
 * (reorder, bulk update, bulk unsubscribe), so the rule lives here rather than
 * three times in the controller.
 *
 * The count comparison catches all three cases at once: the repository only
 * returns rows the user owns, so a short result means at least one id was
 * foreign or absent — and a repeated id is short too, because `IN (...)`
 * answers a duplicate once. Comparing against the *unique* ids instead would
 * let `[5, 5]` through, which is the bug this replaces.
 */
final readonly class OwnedSubscriptions
{
    public function __construct(private SubscriptionRepository $subscriptions)
    {
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Subscription> the resolved subscriptions, keyed by id
     */
    public function resolve(array $ids, int $userId): array
    {
        $owned = $this->subscriptions->findAllByIdsForUser($ids, $userId);
        if (\count($owned) !== \count($ids)) {
            throw new UnprocessableEntityHttpException(
                'subscriptionIds must all be your feeds, without duplicates.',
            );
        }

        $byId = [];
        foreach ($owned as $subscription) {
            $byId[(int) $subscription->getId()] = $subscription;
        }

        return $byId;
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
cd backend && php bin/phpunit tests/Service/Subscription/OwnedSubscriptionsTest.php
```

Expected: 4 tests, 4 passing.

- [ ] **Step 5: Move `reorder` onto the collaborator**

In `backend/src/Controller/Api/SubscriptionController.php`, add `private OwnedSubscriptions $ownedSubscriptions,` to the constructor and the matching `use App\Service\Subscription\OwnedSubscriptions;` import. Replace the body of the `reorder` action's resolution block:

```php
    #[Route('/reorder', name: 'api_subscriptions_reorder', methods: ['PATCH'])]
    public function reorder(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ReorderSubscriptionsRequest $request,
    ): JsonResponse {
        $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, (int) $user->getId());

        foreach ($request->subscriptionIds as $index => $subscriptionId) {
            $byId[$subscriptionId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

Delete the now-unused `SubscriptionRepository` import **only if** no other action uses it — `update`, `delete` and `list` still do, so it stays. Delete the `UnprocessableEntityHttpException` import only if no other action throws it; check with `grep -n UnprocessableEntityHttpException src/Controller/Api/SubscriptionController.php` after the edit.

- [ ] **Step 6: Run the reorder tests unchanged**

```bash
cd backend && php bin/phpunit tests/Controller/Api/ReorderTest.php
```

Expected: PASS, with no test file edited. If a test needed changing, the refactor changed behaviour and is wrong.

- [ ] **Step 7: Gates and commit**

```bash
cd backend && bin/console cache:warmup && composer check && composer md
git add backend/src/Service/Subscription/OwnedSubscriptions.php backend/src/Controller/Api/SubscriptionController.php backend/tests/Service/Subscription/OwnedSubscriptionsTest.php
git commit -m "refactor(#659): extract OwnedSubscriptions from the reorder action"
```

---

## Task 2: The two request DTOs

**Files:**
- Create: `backend/src/Dto/Subscription/BulkUpdateSubscriptionsRequest.php`
- Create: `backend/src/Dto/Subscription/BulkUnsubscribeRequest.php`
- Test: `backend/tests/Dto/Subscription/BulkRequestValidationTest.php`

**Interfaces:**
- Produces: two readonly DTOs. `BulkUpdateSubscriptionsRequest` has public `array $subscriptionIds`, `array $addTagIds`, `array $removeTagIds`, `?bool $includeInAllItems`, `?bool $includeInForYou`. `BulkUnsubscribeRequest` has public `array $subscriptionIds`. Tasks 3 and 5 read these property names.

The cap is `SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER` (500), the existing constant — a bulk request may name at most as many feeds as an account can own, so the two numbers are the same number by definition and must not drift.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Dto/Subscription/BulkRequestValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\BulkUnsubscribeRequest;
use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Service\Subscription\SubscriptionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BulkRequestValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);
        $this->validator = $validator;
    }

    public function testIdsOnlyIsValid(): void
    {
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: [1, 2, 3]);

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testAnEmptyIdListIsRejected(): void
    {
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: []);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    public function testMoreIdsThanAnAccountCanOwnAreRejected(): void
    {
        $tooMany = range(1, SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 1);
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: $tooMany);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    public function testExactlyTheCapIsAccepted(): void
    {
        $atCap = range(1, SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER);
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: $atCap);

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testANegativeTagIdIsRejected(): void
    {
        $request = new BulkUpdateSubscriptionsRequest(subscriptionIds: [1], addTagIds: [-4]);

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    public function testUnsubscribeRequestSharesTheSameCap(): void
    {
        $tooMany = range(1, SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER + 1);

        self::assertCount(0, $this->validator->validate(new BulkUnsubscribeRequest([1])));
        self::assertGreaterThan(0, \count($this->validator->validate(new BulkUnsubscribeRequest($tooMany))));
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
cd backend && php bin/phpunit tests/Dto/Subscription/BulkRequestValidationTest.php
```

Expected: `Error: Class "App\Dto\Subscription\BulkUpdateSubscriptionsRequest" not found`.

- [ ] **Step 3: Write the implementations**

Create `backend/src/Dto/Subscription/BulkUpdateSubscriptionsRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One bulk change across many feeds: at most one tag added, at most one tag
 * removed, and either inclusion flag set.
 *
 * The flags are nullable and default to null, meaning "leave the stored value
 * unchanged" — the same convention UpdateSubscriptionRequest and
 * EntryController::updateState use (#695).
 *
 * The id cap is the per-account subscription limit: a bulk request may name at
 * most every feed the caller could possibly own. Reading the constant rather
 * than repeating 500 keeps the two from drifting.
 */
final readonly class BulkUpdateSubscriptionsRequest
{
    /**
     * @param list<int> $subscriptionIds the feeds to change
     * @param list<int> $addTagIds       tags to add to every listed feed
     * @param list<int> $removeTagIds    tags to remove from every listed feed
     */
    public function __construct(
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $subscriptionIds = [],
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $addTagIds = [],
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $removeTagIds = [],
        public ?bool $includeInAllItems = null,
        public ?bool $includeInForYou = null,
    ) {
    }
}
```

Create `backend/src/Dto/Subscription/BulkUnsubscribeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Subscription;

use App\Service\Subscription\SubscriptionService;
use Symfony\Component\Validator\Constraints as Assert;

/** The feeds to unsubscribe from in one request. Same id cap as a bulk update. */
final readonly class BulkUnsubscribeRequest
{
    /** @param list<int> $subscriptionIds */
    public function __construct(
        #[Assert\Count(min: 1, max: SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER)]
        #[Assert\All([new Assert\Type('integer'), new Assert\Positive()])]
        public array $subscriptionIds = [],
    ) {
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
cd backend && php bin/phpunit tests/Dto/Subscription/BulkRequestValidationTest.php
```

Expected: 6 tests, 6 passing.

- [ ] **Step 5: Gates and commit**

```bash
cd backend && composer check
git add backend/src/Dto/Subscription/ backend/tests/Dto/Subscription/
git commit -m "feat(#659): add the bulk subscription request DTOs"
```

---

## Task 3: `BulkSubscriptionUpdater`

**Files:**
- Create: `backend/src/Service/Subscription/BulkSubscriptionUpdater.php`
- Test: `backend/tests/Service/Subscription/BulkSubscriptionUpdaterTest.php`

**Interfaces:**
- Consumes: `OwnedSubscriptions::resolve` (Task 1), `BulkUpdateSubscriptionsRequest` (Task 2), `SubscriptionTagSync::sync(Subscription, list<int>, int)`, `TagRepository::findAllByIdsForUser`.
- Produces: `BulkSubscriptionUpdater::apply(BulkUpdateSubscriptionsRequest $request, int $userId): list<Subscription>` — the changed subscriptions, in the order the request listed them. Task 5 relies on it.

The resulting tag set for one feed is `(current ∪ add) \ remove`, with the current tags keeping their relative order so `SubscriptionTagSync` preserves each kept tag's per-tag position. One flush after the loop, never inside it.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Subscription/BulkSubscriptionUpdaterTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\BulkSubscriptionUpdater;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class BulkSubscriptionUpdaterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BulkSubscriptionUpdater $updater;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $updater = self::getContainer()->get(BulkSubscriptionUpdater::class);
        self::assertInstanceOf(BulkSubscriptionUpdater::class, $updater);
        $this->updater = $updater;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function tag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->em->persist($tag);

        return $tag;
    }

    private function subscription(User $user, string $url, ?Tag $tag = null, int $tagPosition = 0): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        if (null !== $tag) {
            $subscription->addTag($tag, $tagPosition);
        }
        $this->em->persist($subscription);

        return $subscription;
    }

    /** @return list<string> */
    private function tagNames(Subscription $subscription): array
    {
        return array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $subscription->getTags()->toArray(),
        );
    }

    public function testAddsATagToEveryListedFeed(): void
    {
        $user = $this->user('bulk-add@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $first = $this->subscription($user, 'https://a.example/feed.xml');
        $second = $this->subscription($user, 'https://b.example/feed.xml');
        $this->em->flush();

        $changed = $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $first->getId(), (int) $second->getId()],
                addTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );

        self::assertCount(2, $changed);
        self::assertSame(['Tech'], $this->tagNames($first));
        self::assertSame(['Tech'], $this->tagNames($second));
    }

    public function testAFeedThatAlreadyCarriesTheTagKeepsItsPosition(): void
    {
        $user = $this->user('bulk-idempotent@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $first = $this->subscription($user, 'https://a.example/feed.xml', $tech, 0);
        $second = $this->subscription($user, 'https://b.example/feed.xml', $tech, 1);
        $this->em->flush();

        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $first->getId()],
                addTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );

        $this->em->refresh($first);
        $this->em->refresh($second);
        self::assertSame(['Tech'], $this->tagNames($first));
        self::assertSame(['Tech'], $this->tagNames($second));
    }

    public function testRemovingTheLastTagAppendsTheFeedToTheUntaggedList(): void
    {
        $user = $this->user('bulk-last-tag@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $untagged = $this->subscription($user, 'https://untagged.example/feed.xml');
        $untagged->setPosition(0);
        $tagged = $this->subscription($user, 'https://tagged.example/feed.xml', $tech, 0);
        $tagged->setPosition(0);
        $this->em->flush();

        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $tagged->getId()],
                removeTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );

        self::assertSame([], $this->tagNames($tagged));
        self::assertGreaterThan(
            $untagged->getPosition(),
            $tagged->getPosition(),
            'A feed that lost its last tag must be appended, not left at a stale position.',
        );
    }

    public function testAppliesOnlyTheFlagsThatAreNotNull(): void
    {
        $user = $this->user('bulk-flags@example.com');
        $subscription = $this->subscription($user, 'https://flags.example/feed.xml');
        $subscription->setIncludeInForYou(false);
        $this->em->flush();

        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $subscription->getId()],
                includeInAllItems: false,
            ),
            (int) $user->getId(),
        );

        self::assertFalse($subscription->isIncludeInAllItems());
        self::assertFalse($subscription->isIncludeInForYou(), 'A null flag must not be written.');
    }

    public function testRejectsATagNamedInBothAddAndRemove(): void
    {
        $user = $this->user('bulk-contradiction@example.com');
        $tech = $this->tag($user, 'Tech', 0);
        $subscription = $this->subscription($user, 'https://c.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $subscription->getId()],
                addTagIds: [(int) $tech->getId()],
                removeTagIds: [(int) $tech->getId()],
            ),
            (int) $user->getId(),
        );
    }

    public function testRejectsATagThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('bulk-mine@example.com');
        $theirs = $this->user('bulk-theirs@example.com');
        $foreignTag = $this->tag($theirs, 'Theirs', 0);
        $subscription = $this->subscription($mine, 'https://d.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(
                subscriptionIds: [(int) $subscription->getId()],
                addTagIds: [(int) $foreignTag->getId()],
            ),
            (int) $mine->getId(),
        );
    }

    public function testRejectsASubscriptionThatBelongsToAnotherUser(): void
    {
        $mine = $this->user('bulk-sub-mine@example.com');
        $theirs = $this->user('bulk-sub-theirs@example.com');
        $foreign = $this->subscription($theirs, 'https://e.example/feed.xml');
        $this->em->flush();

        $this->expectException(UnprocessableEntityHttpException::class);
        $this->updater->apply(
            new BulkUpdateSubscriptionsRequest(subscriptionIds: [(int) $foreign->getId()]),
            (int) $mine->getId(),
        );
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
cd backend && php bin/phpunit tests/Service/Subscription/BulkSubscriptionUpdaterTest.php
```

Expected: `Service "App\Service\Subscription\BulkSubscriptionUpdater" not found`.

- [ ] **Step 3: Write the implementation**

Create `backend/src/Service/Subscription/BulkSubscriptionUpdater.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Repository\TagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Applies one tag and flag change across many subscriptions in a single
 * request.
 *
 * The per-feed tag work is delegated to SubscriptionTagSync, which owns two
 * rules that are easy to get subtly wrong: a newly added tag appends at that
 * tag's next position, and a feed that loses its LAST tag is appended to the
 * untagged list so a stale position does not float it to the top. Reproducing
 * either of them here would be the second copy this collaborator exists to
 * prevent.
 *
 * One flush for the whole request, after the loop. A flush per feed would turn
 * a 176-feed selection into 176 transactions.
 */
final readonly class BulkSubscriptionUpdater
{
    public function __construct(
        private OwnedSubscriptions $ownedSubscriptions,
        private TagRepository $tags,
        private SubscriptionTagSync $tagSync,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<Subscription> the changed subscriptions, in request order
     */
    public function apply(BulkUpdateSubscriptionsRequest $request, int $userId): array
    {
        $this->assertNoContradictoryTagChange($request);

        $addTagIds = $this->assertOwnedTagIds($request->addTagIds, $userId);
        $removeTagIds = $this->assertOwnedTagIds($request->removeTagIds, $userId);
        $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, $userId);

        $changed = [];
        foreach ($request->subscriptionIds as $subscriptionId) {
            $subscription = $byId[$subscriptionId];
            $this->tagSync->sync($subscription, $this->resultingTagIds($subscription, $addTagIds, $removeTagIds), $userId);
            $this->applyFlags($subscription, $request);
            $changed[] = $subscription;
        }

        $this->entityManager->flush();

        return $changed;
    }

    private function assertNoContradictoryTagChange(BulkUpdateSubscriptionsRequest $request): void
    {
        if ([] === array_intersect($request->addTagIds, $request->removeTagIds)) {
            return;
        }

        throw new UnprocessableEntityHttpException(
            'A tag cannot be added and removed in the same request.',
        );
    }

    /**
     * @param list<int> $tagIds
     *
     * @return list<int>
     */
    private function assertOwnedTagIds(array $tagIds, int $userId): array
    {
        if ([] === $tagIds) {
            return [];
        }

        $owned = $this->tags->findAllByIdsForUser($tagIds, $userId);
        if (\count($owned) !== \count($tagIds)) {
            throw new UnprocessableEntityHttpException(
                'addTagIds and removeTagIds must all be your tags, without duplicates.',
            );
        }

        return array_values(array_map(static fn (Tag $tag): int => (int) $tag->getId(), $owned));
    }

    /**
     * The feed's tags after this request: what it has, plus what was added,
     * minus what was removed. The current ids come first and keep their order,
     * so every kept tag holds its per-tag position through the sync.
     *
     * @param list<int> $addTagIds
     * @param list<int> $removeTagIds
     *
     * @return list<int>
     */
    private function resultingTagIds(Subscription $subscription, array $addTagIds, array $removeTagIds): array
    {
        $current = array_map(
            static fn (Tag $tag): int => (int) $tag->getId(),
            $subscription->getTags()->toArray(),
        );

        return array_values(array_diff(array_unique([...$current, ...$addTagIds]), $removeTagIds));
    }

    private function applyFlags(Subscription $subscription, BulkUpdateSubscriptionsRequest $request): void
    {
        if (null !== $request->includeInAllItems) {
            $subscription->setIncludeInAllItems($request->includeInAllItems);
        }
        if (null !== $request->includeInForYou) {
            $subscription->setIncludeInForYou($request->includeInForYou);
        }
    }
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
cd backend && php bin/phpunit tests/Service/Subscription/BulkSubscriptionUpdaterTest.php
```

Expected: 7 tests, 7 passing.

- [ ] **Step 5: Gates and commit**

```bash
cd backend && composer check && composer md
git add backend/src/Service/Subscription/BulkSubscriptionUpdater.php backend/tests/Service/Subscription/BulkSubscriptionUpdaterTest.php
git commit -m "feat(#659): add BulkSubscriptionUpdater"
```

If `composer tramp` reports a chain, the fix is a context object or a field — never a longer signature. `$userId` travelling from `apply` into two private helpers stays inside one class, and `minClasses: 2` means it is not counted.

---

## Task 4: `SubscriptionService::unsubscribeAll`

`unsubscribe()` flushes and reclaims per call. Calling it in a loop would produce one transaction and one orphan sweep per feed. The bulk path removes every entity, flushes once, then reclaims each **distinct** feed id.

**Files:**
- Modify: `backend/src/Service/Subscription/SubscriptionService.php` (after `unsubscribe`, around line 44)
- Test: `backend/tests/Service/Subscription/UnsubscribeAllTest.php`

**Interfaces:**
- Consumes: `OrphanedFeedReclaimer::reclaim(int $feedId): bool`.
- Produces: `SubscriptionService::unsubscribeAll(array $subscriptions): int` — takes `list<Subscription>`, returns how many were removed. Task 5 relies on it.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Service/Subscription/UnsubscribeAllTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UnsubscribeAllTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SubscriptionService $subscriptions;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $service = self::getContainer()->get(SubscriptionService::class);
        self::assertInstanceOf(SubscriptionService::class, $service);
        $this->subscriptions = $service;
    }

    private function user(string $email): User
    {
        $user = new User($email, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($user);

        return $user;
    }

    private function subscribe(User $user, Feed $feed): Subscription
    {
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);

        return $subscription;
    }

    private function feed(string $url): Feed
    {
        $feed = new Feed($url);
        $this->em->persist($feed);

        return $feed;
    }

    public function testRemovesEveryListedSubscriptionAndReturnsTheCount(): void
    {
        $user = $this->user('unsub-all@example.com');
        $kept = $this->subscribe($user, $this->feed('https://kept.example/feed.xml'));
        $goingOne = $this->subscribe($user, $this->feed('https://one.example/feed.xml'));
        $goingTwo = $this->subscribe($user, $this->feed('https://two.example/feed.xml'));
        $this->em->flush();

        $keptId = (int) $kept->getId();

        $removed = $this->subscriptions->unsubscribeAll([$goingOne, $goingTwo]);

        self::assertSame(2, $removed);
        $repository = self::getContainer()->get(SubscriptionRepository::class);
        self::assertInstanceOf(SubscriptionRepository::class, $repository);
        self::assertNotNull($repository->findOneOwnedBy($keptId, (int) $user->getId()));
        self::assertCount(1, $repository->findForUserWithTags((int) $user->getId()));
    }

    public function testReclaimsAFeedNobodySubscribesToAnyMore(): void
    {
        $user = $this->user('unsub-orphan@example.com');
        $orphaned = $this->feed('https://orphan.example/feed.xml');
        $subscription = $this->subscribe($user, $orphaned);
        $this->em->flush();
        $orphanedId = (int) $orphaned->getId();

        $this->subscriptions->unsubscribeAll([$subscription]);

        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNull($feeds->find($orphanedId), 'A feed with no subscriber left must be reclaimed.');
    }

    public function testKeepsAFeedAnotherAccountStillSubscribesTo(): void
    {
        $mine = $this->user('unsub-shared-mine@example.com');
        $theirs = $this->user('unsub-shared-theirs@example.com');
        $shared = $this->feed('https://shared.example/feed.xml');
        $ours = $this->subscribe($mine, $shared);
        $this->subscribe($theirs, $shared);
        $this->em->flush();
        $sharedId = (int) $shared->getId();

        $this->subscriptions->unsubscribeAll([$ours]);

        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNotNull($feeds->find($sharedId));
    }

    public function testTwoSubscriptionsToOneFeedReclaimItOnce(): void
    {
        $mine = $this->user('unsub-two-mine@example.com');
        $theirs = $this->user('unsub-two-theirs@example.com');
        $shared = $this->feed('https://both.example/feed.xml');
        $ours = $this->subscribe($mine, $shared);
        $alsoOurs = $this->subscribe($theirs, $shared);
        $this->em->flush();
        $sharedId = (int) $shared->getId();

        $removed = $this->subscriptions->unsubscribeAll([$ours, $alsoOurs]);

        self::assertSame(2, $removed);
        $feeds = self::getContainer()->get(FeedRepository::class);
        self::assertInstanceOf(FeedRepository::class, $feeds);
        self::assertNull($feeds->find($sharedId));
    }

    public function testAnEmptyListRemovesNothing(): void
    {
        self::assertSame(0, $this->subscriptions->unsubscribeAll([]));
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
cd backend && php bin/phpunit tests/Service/Subscription/UnsubscribeAllTest.php
```

Expected: `Error: Call to undefined method App\Service\Subscription\SubscriptionService::unsubscribeAll()`.

- [ ] **Step 3: Write the implementation**

In `backend/src/Service/Subscription/SubscriptionService.php`, add directly after `unsubscribe()`:

```php
    /**
     * Removes many subscriptions in one transaction, then reclaims each feed
     * that lost its last subscriber.
     *
     * The single flush is the point: unsubscribe() flushes and reclaims per
     * call, which a 176-feed selection would turn into 176 transactions and 176
     * orphan sweeps. Reclaiming per DISTINCT feed after the flush also matters
     * — two of the removed subscriptions can point at the same feed, and
     * reclaim() must not be asked about it twice.
     *
     * @param list<Subscription> $subscriptions
     *
     * @return int how many subscriptions were removed
     */
    public function unsubscribeAll(array $subscriptions): int
    {
        if ([] === $subscriptions) {
            return 0;
        }

        $feedIds = [];
        foreach ($subscriptions as $subscription) {
            $feedIds[(int) $subscription->getFeed()->getId()] = true;
            $this->em->remove($subscription);
        }
        $this->em->flush();

        foreach (array_keys($feedIds) as $feedId) {
            $this->orphanedFeeds->reclaim($feedId);
        }

        return \count($subscriptions);
    }
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
cd backend && php bin/phpunit tests/Service/Subscription/UnsubscribeAllTest.php
```

Expected: 5 tests, 5 passing.

- [ ] **Step 5: Run the whole existing suite — nothing else may move**

```bash
cd backend && php bin/phpunit
```

Expected: the full suite green.

- [ ] **Step 6: Gates and commit**

```bash
cd backend && composer check && composer md
git add backend/src/Service/Subscription/SubscriptionService.php backend/tests/Service/Subscription/UnsubscribeAllTest.php
git commit -m "feat(#659): add SubscriptionService::unsubscribeAll"
```

---

## Task 5: The two endpoints

**Files:**
- Modify: `backend/src/Controller/Api/SubscriptionController.php`
- Test: `backend/tests/Controller/Api/SubscriptionBulkTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-4, plus `App\Http\SubscriptionJson::one(Subscription $sub, int $unreadCount = 0): array`.
- Produces: `PATCH /api/subscriptions/bulk` answering `{"subscriptions": [...]}` and `POST /api/subscriptions/bulk-unsubscribe` answering `{"removed": N}`. Task 6 and Task 13 rely on both shapes.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Controller/Api/SubscriptionBulkTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionBulkTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em(), $hasher))->create($email);
    }

    /** @return array<string, string> */
    private function headers(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function makeTag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition(0);
        $this->em()->persist($tag);

        return $tag;
    }

    private function makeSub(User $user, string $url, ?Tag $tag = null): Subscription
    {
        $feed = new Feed($url);
        $this->em()->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        if (null !== $tag) {
            $subscription->addTag($tag, 0);
        }
        $this->em()->persist($subscription);

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function send(KernelBrowser $client, User $user, string $method, string $url, array $body): void
    {
        $client->request(
            $method,
            $url,
            server: $this->headers($user),
            content: json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('PATCH', '/api/subscriptions/bulk');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAddsATagToEveryListedFeed(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-add@example.com');
        $tech = $this->makeTag($user, 'Tech');
        $first = $this->makeSub($user, 'https://a.example/feed.xml');
        $second = $this->makeSub($user, 'https://b.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $first->getId(), (int) $second->getId()],
            'addTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertIsArray($body['subscriptions']);
        self::assertCount(2, $body['subscriptions']);
        foreach ($body['subscriptions'] as $subscription) {
            self::assertIsArray($subscription);
            self::assertSame('Tech', $subscription['tags'][0]['name']);
        }
    }

    public function testSetsAnInclusionFlagInTheSameRequest(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-flags@example.com');
        $subscription = $this->makeSub($user, 'https://flags.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $subscription->getId()],
            'includeInAllItems' => false,
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json($client);
        self::assertIsArray($body['subscriptions'][0]);
        self::assertFalse($body['subscriptions'][0]['includeInAllItems']);
        self::assertTrue($body['subscriptions'][0]['includeInForYou']);
    }

    public function testRejectsAForeignSubscriptionAndWritesNothing(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-mine@example.com');
        $theirs = $this->user('bulk-endpoint-theirs@example.com');
        $tech = $this->makeTag($mine, 'Tech');
        $ours = $this->makeSub($mine, 'https://ours.example/feed.xml');
        $foreign = $this->makeSub($theirs, 'https://foreign.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $mine, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $ours->getId(), (int) $foreign->getId()],
            'addTagIds' => [(int) $tech->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Subscription::class)->find((int) $ours->getId());
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertCount(0, $reloaded->getTags(), 'A rejected bulk request must write nothing.');
    }

    public function testRejectsAForeignTag(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-tag-mine@example.com');
        $theirs = $this->user('bulk-endpoint-tag-theirs@example.com');
        $foreignTag = $this->makeTag($theirs, 'Theirs');
        $ours = $this->makeSub($mine, 'https://ours2.example/feed.xml');
        $this->em()->flush();

        $this->send($client, $mine, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => [(int) $ours->getId()],
            'addTagIds' => [(int) $foreignTag->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsMoreIdsThanTheCap(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-cap@example.com');
        $this->em()->flush();

        $this->send($client, $user, 'PATCH', '/api/subscriptions/bulk', [
            'subscriptionIds' => range(1, 501),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnsubscribesEveryListedFeedAndKeepsTheRest(): void
    {
        $client = self::createClient();
        $user = $this->user('bulk-endpoint-unsub@example.com');
        $kept = $this->makeSub($user, 'https://kept.example/feed.xml');
        $goingOne = $this->makeSub($user, 'https://going1.example/feed.xml');
        $goingTwo = $this->makeSub($user, 'https://going2.example/feed.xml');
        $this->em()->flush();
        $keptId = (int) $kept->getId();

        $this->send($client, $user, 'POST', '/api/subscriptions/bulk-unsubscribe', [
            'subscriptionIds' => [(int) $goingOne->getId(), (int) $goingTwo->getId()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['removed' => 2], $this->json($client));
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Subscription::class)->find($keptId));
    }

    public function testUnsubscribeRejectsAForeignIdAndRemovesNothing(): void
    {
        $client = self::createClient();
        $mine = $this->user('bulk-endpoint-unsub-mine@example.com');
        $theirs = $this->user('bulk-endpoint-unsub-theirs@example.com');
        $ours = $this->makeSub($mine, 'https://mine.example/feed.xml');
        $foreign = $this->makeSub($theirs, 'https://theirs.example/feed.xml');
        $this->em()->flush();
        $ourId = (int) $ours->getId();

        $this->send($client, $mine, 'POST', '/api/subscriptions/bulk-unsubscribe', [
            'subscriptionIds' => [$ourId, (int) $foreign->getId()],
        ]);

        self::assertResponseStatusCodeSame(422);
        $this->em()->clear();
        self::assertNotNull($this->em()->getRepository(Subscription::class)->find($ourId));
    }
}
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
cd backend && php bin/phpunit tests/Controller/Api/SubscriptionBulkTest.php
```

Expected: the routed cases answer 404 — the routes do not exist yet.

- [ ] **Step 3: Write the two actions**

In `backend/src/Controller/Api/SubscriptionController.php`, add the imports:

```php
use App\Dto\Subscription\BulkUnsubscribeRequest;
use App\Dto\Subscription\BulkUpdateSubscriptionsRequest;
use App\Service\Subscription\BulkSubscriptionUpdater;
```

Add `private BulkSubscriptionUpdater $bulkUpdater,` to the constructor, and add both actions directly after `reorder`:

```php
    /**
     * Change tags and inclusion flags across many feeds in one request. Every
     * id must be the caller's; one that is not answers 422 and writes nothing.
     */
    #[Route('/bulk', name: 'api_subscriptions_bulk_update', methods: ['PATCH'])]
    public function bulkUpdate(
        #[CurrentUser] User $user,
        #[MapRequestPayload] BulkUpdateSubscriptionsRequest $request,
    ): JsonResponse {
        $changed = $this->bulkUpdater->apply($request, (int) $user->getId());

        return new JsonResponse([
            'subscriptions' => array_map(
                static fn (Subscription $subscription): array => SubscriptionJson::one($subscription),
                $changed,
            ),
        ]);
    }

    /**
     * Unsubscribe from many feeds in one request. No undo: the entries go with
     * the subscription, so the client's confirmation is the only guard.
     */
    #[Route('/bulk-unsubscribe', name: 'api_subscriptions_bulk_unsubscribe', methods: ['POST'])]
    public function bulkUnsubscribe(
        #[CurrentUser] User $user,
        #[MapRequestPayload] BulkUnsubscribeRequest $request,
    ): JsonResponse {
        $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, (int) $user->getId());

        return new JsonResponse(['removed' => $this->subscriptions->unsubscribeAll(array_values($byId))]);
    }
```

Both actions read the request, delegate and return. Neither adds a private method, so `ThinControllerRule` stays satisfied and its allow-list is untouched.

- [ ] **Step 4: Run the test and watch it pass**

```bash
cd backend && php bin/phpunit tests/Controller/Api/SubscriptionBulkTest.php
```

Expected: 8 tests, 8 passing.

- [ ] **Step 5: Run every gate, including the MySQL leg**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && php bin/phpunit
docker compose exec php vendor/bin/phpunit
```

`composer check` runs `cs`, `stan` and `tramp`. If `tramp` fails, read `composer show larspohlmann/phptramp` first — CI runs that tool's `develop` tip, so a red gate can come from the tool rather than from this branch.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Controller/Api/SubscriptionController.php backend/tests/Controller/Api/SubscriptionBulkTest.php
git commit -m "feat(#659): add the bulk update and bulk unsubscribe endpoints"
```

---

# Frontend

## Task 6: The API calls and the bulk actions on `ManageActions`

Every write on the new page goes through `ManageActions`. That is the rule that stops the three management surfaces (the sidebar's Organise mode, `settings/tags`, and this page) from drifting apart, so the page component must never inject `ReaderApi`.

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (add two interfaces at the end)
- Modify: `frontend/src/app/reader/reader-api.ts` (after `reorderSubscriptions`, around line 121)
- Modify: `frontend/src/app/reader/manage/manage-actions.service.ts`
- Test: `frontend/src/app/reader/manage/manage-actions.service.spec.ts` (extend)

**Interfaces:**
- Consumes: `PATCH /api/subscriptions/bulk` and `POST /api/subscriptions/bulk-unsubscribe` from Task 5.
- Produces, all on `ManageActions`:
  - `bulkAddTag(subscriptionIds: number[], tag: TagDto): Observable<void>`
  - `bulkRemoveTag(subscriptionIds: number[], tag: TagDto): Observable<void>`
  - `bulkSetFlags(subscriptionIds: number[], flags: SubscriptionFlags): Observable<void>`
  - `bulkUnsubscribe(subscriptions: SubscriptionDto[]): Observable<boolean>` — `false` when the user cancelled.
  - `addFeed(): Observable<SubscriptionDto | undefined>` — opens `AddFeedDialogComponent` and reloads the subscriptions on a successful subscribe.

  Tasks 9 and 11 call all five. Moving a feed between tags needs **no** new method: the caller computes the resulting tag ids and calls the existing `retag(sub, tagIds)`.

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/app/reader/manage/manage-actions.service.spec.ts`. Follow the file's existing `TestBed` setup; these are the cases that must exist:

```ts
describe('bulk actions', () => {
  it('posts one bulk patch when a tag is added to several feeds', () => {
    const { actions, http } = setup();

    actions.bulkAddTag([1, 2, 3], TAG).subscribe();

    const req = http.expectOne(`${BASE}/api/subscriptions/bulk`);
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ subscriptionIds: [1, 2, 3], addTagIds: [TAG.id] });
  });

  it('posts removeTagIds when a tag is removed', () => {
    const { actions, http } = setup();

    actions.bulkRemoveTag([4], TAG).subscribe();

    expect(http.expectOne(`${BASE}/api/subscriptions/bulk`).request.body).toEqual({
      subscriptionIds: [4],
      removeTagIds: [TAG.id],
    });
  });

  it('sends only the flag that was named', () => {
    const { actions, http } = setup();

    actions.bulkSetFlags([7], { includeInAllItems: false }).subscribe();

    expect(http.expectOne(`${BASE}/api/subscriptions/bulk`).request.body).toEqual({
      subscriptionIds: [7],
      includeInAllItems: false,
    });
  });

  it('opens the add-feed dialog and reloads on a successful subscribe', () => {
    const { actions, dialogOpen, subLoad, closed } = setup();
    closed.next(SUBSCRIPTION);

    actions.addFeed().subscribe();

    expect(dialogOpen).toHaveBeenCalled();
    expect(subLoad).toHaveBeenCalled();
  });

  it('reloads the subscriptions store after a successful bulk patch', () => {
    const { actions, http, subLoad } = setup();

    actions.bulkAddTag([1], TAG).subscribe();
    http.expectOne(`${BASE}/api/subscriptions/bulk`).flush({ subscriptions: [] });

    expect(subLoad).toHaveBeenCalled();
  });

  it('asks the user to type the count from five feeds up', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subs(5)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.requireText).toBe('5');
  });

  it('does not ask for typed text at four feeds', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subs(4)).subscribe();

    expect(dialogOpen.mock.calls[0][1].data.requireText).toBeUndefined();
  });

  it('names at most five titles and counts the rest', () => {
    const { actions, dialogOpen } = setup();

    actions.bulkUnsubscribe(subs(7)).subscribe();

    const message: string = dialogOpen.mock.calls[0][1].data.message;
    expect(message).toContain('Feed 1');
    expect(message).toContain('Feed 5');
    expect(message).not.toContain('Feed 6');
  });

  it('writes nothing when the confirmation is dismissed', () => {
    const { actions, http } = setup({ confirmAnswer: false });
    let outcome: boolean | undefined;

    actions.bulkUnsubscribe(subs(2)).subscribe((ok) => (outcome = ok));

    expect(outcome).toBe(false);
    http.expectNone(`${BASE}/api/subscriptions/bulk-unsubscribe`);
  });

  it('unsubscribes and reloads after a confirmed bulk unsubscribe', () => {
    const { actions, http, subLoad } = setup({ confirmAnswer: true });

    actions.bulkUnsubscribe(subs(2)).subscribe();
    const req = http.expectOne(`${BASE}/api/subscriptions/bulk-unsubscribe`);
    expect(req.request.body).toEqual({ subscriptionIds: [1, 2] });
    req.flush({ removed: 2 });

    expect(subLoad).toHaveBeenCalled();
  });
});
```

Add these helpers at the top of the new `describe`, next to the file's existing fixtures:

```ts
const subs = (count: number): SubscriptionDto[] =>
  Array.from({ length: count }, (_, index) => ({ ...SUBSCRIPTION, id: index + 1, title: `Feed ${index + 1}` }));
```

- [ ] **Step 2: Run the tests and watch them fail**

```bash
docker compose exec -T frontend npx jest src/app/reader/manage/manage-actions.service.spec.ts
```

Expected: `actions.bulkAddTag is not a function`.

- [ ] **Step 3: Add the request shapes to `models.ts`**

```ts
/** The two per-feed inclusion switches, as a bulk change. An omitted field
 *  leaves the stored value alone — the API's null-means-unchanged convention. */
export interface SubscriptionFlags {
  includeInAllItems?: boolean;
  includeInForYou?: boolean;
}

/** One bulk change across many feeds. At most one tag is added and at most one
 *  removed per request; the page never needs more, and a single tag keeps the
 *  confirmation text exact. */
export interface BulkSubscriptionUpdate extends SubscriptionFlags {
  subscriptionIds: number[];
  addTagIds?: number[];
  removeTagIds?: number[];
}
```

- [ ] **Step 4: Add the two calls to `ReaderApi`**

Directly after `reorderSubscriptions`:

```ts
  /** Change tags and inclusion flags across many feeds in one request. */
  bulkUpdateSubscriptions(
    body: BulkSubscriptionUpdate,
  ): Observable<{ subscriptions: SubscriptionDto[] }> {
    return this.http.patch<{ subscriptions: SubscriptionDto[] }>(
      `${this.base}/api/subscriptions/bulk`,
      body,
    );
  }

  /** Unsubscribe from many feeds in one request; answers how many went. */
  bulkUnsubscribe(subscriptionIds: number[]): Observable<{ removed: number }> {
    return this.http.post<{ removed: number }>(`${this.base}/api/subscriptions/bulk-unsubscribe`, {
      subscriptionIds,
    });
  }
```

Add `BulkSubscriptionUpdate` to the existing `models` import.

- [ ] **Step 5: Add the bulk actions to `ManageActions`**

Add the imports and two module constants at the top of the file:

```ts
import { Observable, map, of, switchMap, tap } from 'rxjs';
import { ToastService, CONFIRMATION_DURATION_MS } from '../../shared/toast/toast.service';
import { BulkSubscriptionUpdate, SubscriptionFlags } from '../models';
import { AddFeedDialogComponent } from '../add-feed/add-feed-dialog.component';

/** The most feed titles a bulk confirmation names before it says "and N more".
 *  Five is enough to recognise the selection and short enough to read. */
const CONFIRM_TITLE_LIMIT = 5;
/** From this many feeds up, the confirmation makes the user type the count.
 *  One feed keeps its single click; a mass delete is what requireText is for. */
const TYPED_CONFIRM_THRESHOLD = 5;
```

Add `private readonly toast = inject(ToastService);` beside the other injections, then the methods:

```ts
  /** Add one tag to many feeds. Not optimistic: a bulk tag change moves feeds
   *  between lists under the server's position rules, and re-deriving those
   *  here would be the second copy SubscriptionTagSync exists to prevent. */
  bulkAddTag(subscriptionIds: number[], tag: TagDto): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, addTagIds: [tag.id] },
      this.i18n.translate('manage.bulk.tagAdded', { count: subscriptionIds.length, name: tag.name }),
    );
  }

  /** Remove one tag from many feeds. */
  bulkRemoveTag(subscriptionIds: number[], tag: TagDto): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, removeTagIds: [tag.id] },
      this.i18n.translate('manage.bulk.tagRemoved', {
        count: subscriptionIds.length,
        name: tag.name,
      }),
    );
  }

  /** Set one or both inclusion flags across many feeds. */
  bulkSetFlags(subscriptionIds: number[], flags: SubscriptionFlags): Observable<void> {
    return this.bulkPatch(
      { subscriptionIds, ...flags },
      this.i18n.translate('manage.bulk.flagsSet', { count: subscriptionIds.length }),
    );
  }

  /** Confirm, then unsubscribe from many feeds. Emits false when the user
   *  dismissed the confirmation, so the caller can leave the selection alone. */
  bulkUnsubscribe(subscriptions: SubscriptionDto[]): Observable<boolean> {
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data: this.bulkUnsubscribeConfirm(subscriptions),
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });

    return ref.closed.pipe(
      switchMap((confirmed) => {
        if (!confirmed) return of(false);
        return this.api.bulkUnsubscribe(subscriptions.map((s) => s.id)).pipe(
          tap((result) => {
            this.subs.load();
            this.toast.show({
              message: this.i18n.translate('manage.bulk.unsubscribed', { count: result.removed }),
              durationMs: CONFIRMATION_DURATION_MS,
            });
          }),
          map(() => true),
        );
      }),
    );
  }

  /** Open the add-feed dialog. The reader shell opens the same dialog and then
   *  navigates to the new feed; the Organise page only wants the feed to appear
   *  in its list, so the navigation stays with the shell and the dialog opening
   *  lives here, where every other management dialog already does. */
  addFeed(): Observable<SubscriptionDto | undefined> {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
    });

    return ref.closed.pipe(
      tap((subscription) => {
        if (subscription) this.subs.load();
      }),
    );
  }

  private bulkPatch(body: BulkSubscriptionUpdate, confirmation: string): Observable<void> {
    return this.api.bulkUpdateSubscriptions(body).pipe(
      tap(() => {
        this.subs.load();
        this.toast.show({ message: confirmation, durationMs: CONFIRMATION_DURATION_MS });
      }),
      map(() => undefined),
    );
  }

  private bulkUnsubscribeConfirm(subscriptions: SubscriptionDto[]): ConfirmData {
    const count = subscriptions.length;
    const named = subscriptions
      .slice(0, CONFIRM_TITLE_LIMIT)
      .map((s) => s.title)
      .join(', ');
    const rest = count - Math.min(count, CONFIRM_TITLE_LIMIT);

    return {
      title: this.i18n.translate('manage.bulk.unsubscribeTitle', { count }),
      message:
        rest > 0
          ? this.i18n.translate('manage.bulk.unsubscribeMessageMore', { named, rest })
          : this.i18n.translate('manage.bulk.unsubscribeMessage', { named }),
      confirmLabel: this.i18n.translate('manage.unsubscribeConfirm'),
      danger: true,
      requireText: count >= TYPED_CONFIRM_THRESHOLD ? String(count) : undefined,
    };
  }
```

- [ ] **Step 6: Run the tests and watch them pass**

```bash
docker compose exec -T frontend npx jest src/app/reader/manage/manage-actions.service.spec.ts
```

Expected: the new `describe` green, every existing case still green.

- [ ] **Step 7: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/manage/
git commit -m "feat(#659): add the bulk actions to ManageActions"
```

`npm run check` will fail on the missing `manage.bulk.*` translation keys only if a spec asserts translated text. Add the keys now if it does; Task 12 adds the rest.

---

## Task 7: `OrganiseStore`

The store holds the selection, the expanded groups, the view and the filters — and nothing else. **It must not import `ReaderApi`.** That is the boundary this task exists to establish; a reviewer should reject the task if the import appears.

It is provided by the page component, not `providedIn: 'root'`: a selection must not survive leaving the page.

**Files:**
- Create: `frontend/src/app/settings/organise/organise.store.ts`
- Test: `frontend/src/app/settings/organise/organise.store.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionsStore.subscriptions()` (`Signal<SubscriptionDto[]>`), `TagsStore.tags()` (`Signal<TagDto[]>`).
- Produces:
  - `interface OrganiseGroup { readonly key: GroupKey; readonly tag: TagDto | null; readonly subscriptions: SubscriptionDto[]; readonly totalCount: number }`
  - `type GroupKey = number | 'untagged'`, `type OrganiseView = 'tree' | 'list'`, `type OrganiseSort = 'title' | 'added'`
  - Signals `selectedIds`, `expandedKeys`, `view`, `titleFilter`, `tagFilter`, `sort`, `busy`
  - Computed `filterActive`, `filteredSubscriptions`, `groups`, `listRows`, `visibleIds`, `selectedCount`, `hiddenSelectedCount`, `selectedSubscriptions`, `allVisibleSelected`
  - Methods `toggleFeed`, `setGroupSelected`, `groupState`, `toggleSelectAllVisible`, `clearSelection`, `toggleGroup`, `expandAll`, `collapseAll`, `isExpanded`

  Tasks 9 and 11 use all of them.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/settings/organise/organise.store.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { OrganiseStore } from './organise.store';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { SubscriptionDto, TagDto } from '../../reader/models';

const tag = (id: number, name: string, position: number): TagDto => ({
  id,
  name,
  color: null,
  icon: null,
  position,
});

const sub = (id: number, title: string, tagIds: number[] = [], position = 0): SubscriptionDto =>
  ({
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://feed-${id}.example/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: `Tag ${tagId}`,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

describe('OrganiseStore', () => {
  const TAGS = [tag(1, 'Nachrichten', 0), tag(2, 'Tech', 1)];
  const SUBS = [
    sub(10, 'taz', [1], 0),
    sub(11, 'heise', [2], 0),
    sub(12, 'netzpolitik', [1, 2], 1),
    sub(13, 'Untagged feed', [], 0),
  ];

  function make(subs: SubscriptionDto[] = SUBS, tags: TagDto[] = TAGS): OrganiseStore {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        OrganiseStore,
        { provide: SubscriptionsStore, useValue: { subscriptions: signal(subs) } },
        { provide: TagsStore, useValue: { tags: signal(tags) } },
      ],
    });

    return TestBed.inject(OrganiseStore);
  }

  it('groups feeds under every tag and puts untagged last', () => {
    const store = make();

    const keys = store.groups().map((g) => g.key);
    expect(keys).toEqual([1, 2, 'untagged']);
    expect(store.groups()[0].subscriptions.map((s) => s.id)).toEqual([10, 12]);
    expect(store.groups()[2].subscriptions.map((s) => s.id)).toEqual([13]);
  });

  it('shows a feed with two tags in both groups', () => {
    const store = make();

    expect(store.groups()[0].subscriptions.map((s) => s.id)).toContain(12);
    expect(store.groups()[1].subscriptions.map((s) => s.id)).toContain(12);
  });

  it('selects a feed everywhere it appears, and counts it once', () => {
    const store = make();

    store.toggleFeed(12);

    expect(store.selectedIds().has(12)).toBe(true);
    expect(store.selectedCount()).toBe(1);
    expect(store.groupState(store.groups()[0])).toBe('some');
    expect(store.groupState(store.groups()[1])).toBe('all');
  });

  it('reports a group as all when every one of its feeds is selected', () => {
    const store = make();

    store.setGroupSelected(store.groups()[0], true);

    expect(store.groupState(store.groups()[0])).toBe('all');
    expect(store.selectedCount()).toBe(2);
  });

  it('narrows the groups by the title filter', () => {
    const store = make();

    store.titleFilter.set('heise');

    expect(store.groups().map((g) => g.key)).toEqual([2]);
    expect(store.groups()[0].subscriptions.map((s) => s.id)).toEqual([11]);
  });

  it('expands every matching group while a filter is active', () => {
    const store = make();
    store.collapseAll();

    store.titleFilter.set('heise');

    expect(store.isExpanded(2)).toBe(true);
  });

  it('finds untagged feeds through the tag filter', () => {
    const store = make();

    store.tagFilter.set(new Set(['untagged']));

    expect(store.groups().map((g) => g.key)).toEqual(['untagged']);
  });

  it('select all takes only what the filter shows', () => {
    const store = make();
    store.titleFilter.set('heise');

    store.toggleSelectAllVisible();

    expect([...store.selectedIds()]).toEqual([11]);
  });

  it('counts the selected feeds the filter hides', () => {
    const store = make();
    store.toggleFeed(10);
    store.toggleFeed(11);

    store.titleFilter.set('heise');

    expect(store.selectedCount()).toBe(2);
    expect(store.hiddenSelectedCount()).toBe(1);
  });

  it('keeps the selection when the view switches', () => {
    const store = make();
    store.toggleFeed(10);

    store.view.set('list');

    expect(store.selectedCount()).toBe(1);
  });

  it('sorts the list view by title', () => {
    const store = make();

    store.view.set('list');

    expect(store.listRows().map((s) => s.title)).toEqual([
      'heise',
      'netzpolitik',
      'taz',
      'Untagged feed',
    ]);
  });

  it('persists the collapsed groups under its own key, not the sidebar key', () => {
    const store = make();

    store.toggleGroup(1);

    expect(localStorage.getItem('sfr.tags.collapsed')).toBeNull();
    expect(localStorage.getItem('sfr.organise.expanded')).not.toBeNull();
  });
});
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise.store.spec.ts
```

Expected: `Cannot find module './organise.store'`.

- [ ] **Step 3: Write the implementation**

Create `frontend/src/app/settings/organise/organise.store.ts`:

```ts
// src/app/settings/organise/organise.store.ts
import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { SubscriptionDto, TagDto } from '../../reader/models';

/** A group is one tag, or the untagged bucket that always sits last. */
export type GroupKey = number | 'untagged';
export type OrganiseView = 'tree' | 'list';
export type OrganiseSort = 'title' | 'added';
/** What a group's checkbox shows: nothing, some of its feeds, or all of them. */
export type GroupState = 'none' | 'some' | 'all';

export interface OrganiseGroup {
  readonly key: GroupKey;
  /** null for the untagged group. */
  readonly tag: TagDto | null;
  /** The feeds this group shows, already filtered and in their stored order. */
  readonly subscriptions: SubscriptionDto[];
  /** How many feeds the group holds before the filter — the header's count. */
  readonly totalCount: number;
}

/** Its own key, deliberately not the sidebar's `sfr.tags.collapsed`: collapsing
 *  a group here must not collapse the sidebar the user navigates with. */
const EXPANDED_KEY = 'sfr.organise.expanded';

function readExpanded(): ReadonlySet<GroupKey> {
  try {
    const raw = localStorage.getItem(EXPANDED_KEY);
    if (raw === null) return new Set();
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? new Set(parsed as GroupKey[]) : new Set();
  } catch {
    // A corrupt or unreadable value is not worth a broken page: start closed.
    return new Set();
  }
}

/**
 * The feeds carrying one tag, in that tag's own order.
 *
 * `buildTagTree` in subscriptions.store.ts sorts the same way, but it drops a
 * tag that has no feeds and computes unread counts. This page shows every tag,
 * including the empty ones, and shows no unread count — so it sorts here rather
 * than bending that function to two callers.
 */
function feedsInTag(subscriptions: SubscriptionDto[], tagId: number): SubscriptionDto[] {
  const position = (s: SubscriptionDto): number => s.tags.find((t) => t.id === tagId)?.position ?? 0;

  return subscriptions
    .filter((s) => s.tags.some((t) => t.id === tagId))
    .sort((a, b) => position(a) - position(b));
}

function untaggedFeeds(subscriptions: SubscriptionDto[]): SubscriptionDto[] {
  return subscriptions.filter((s) => s.tags.length === 0).sort((a, b) => a.position - b.position);
}

/**
 * The page's own state: what is selected, what is open, what is filtered.
 *
 * It performs no writes and never injects ReaderApi. Every change to the data
 * goes through ManageActions, which is what keeps this page, the sidebar's
 * Organise mode and settings/tags from drifting apart.
 *
 * Provided by the page component, not in root: leaving the page must drop the
 * selection rather than leave it waiting.
 */
@Injectable()
export class OrganiseStore {
  private readonly subs = inject(SubscriptionsStore);
  private readonly tagsStore = inject(TagsStore);

  readonly selectedIds = signal<ReadonlySet<number>>(new Set());
  readonly expandedKeys = signal<ReadonlySet<GroupKey>>(readExpanded());
  readonly view = signal<OrganiseView>('tree');
  readonly titleFilter = signal('');
  readonly tagFilter = signal<ReadonlySet<GroupKey>>(new Set());
  readonly sort = signal<OrganiseSort>('title');
  /** True while a bulk write is in flight; the bulk bar disables itself. */
  readonly busy = signal(false);

  readonly tags: Signal<TagDto[]> = this.tagsStore.tags;

  readonly filterActive = computed(
    () => this.titleFilter().trim() !== '' || this.tagFilter().size > 0,
  );

  readonly filteredSubscriptions = computed<SubscriptionDto[]>(() => {
    const term = this.titleFilter().trim().toLocaleLowerCase();
    const tagKeys = this.tagFilter();

    return this.subs.subscriptions().filter((s) => {
      if (term !== '' && !s.title.toLocaleLowerCase().includes(term)) return false;
      if (tagKeys.size === 0) return true;
      if (tagKeys.has('untagged') && s.tags.length === 0) return true;
      return s.tags.some((t) => tagKeys.has(t.id));
    });
  });

  readonly groups = computed<OrganiseGroup[]>(() => {
    const visible = this.filteredSubscriptions();
    const all = this.subs.subscriptions();

    const tagGroups: OrganiseGroup[] = this.tags().map((tag) => ({
      key: tag.id,
      tag,
      subscriptions: feedsInTag(visible, tag.id),
      totalCount: feedsInTag(all, tag.id).length,
    }));

    const groups: OrganiseGroup[] = [
      ...tagGroups,
      {
        key: 'untagged',
        tag: null,
        subscriptions: untaggedFeeds(visible),
        totalCount: untaggedFeeds(all).length,
      },
    ];

    // With no filter every tag shows, empty ones included — that IS the
    // arrangement. With a filter, a group that matches nothing is noise.
    return this.filterActive() ? groups.filter((g) => g.subscriptions.length > 0) : groups;
  });

  /** The flat view's rows: every filtered feed once, in the chosen sort. */
  readonly listRows = computed<SubscriptionDto[]>(() => {
    const rows = [...this.filteredSubscriptions()];
    if (this.sort() === 'added') {
      return rows.sort((a, b) => b.createdAt.localeCompare(a.createdAt));
    }

    return rows.sort((a, b) => a.title.localeCompare(b.title));
  });

  /** Every feed the filter currently shows, counted once. */
  readonly visibleIds = computed<ReadonlySet<number>>(
    () => new Set(this.filteredSubscriptions().map((s) => s.id)),
  );

  readonly selectedCount = computed(() => this.selectedIds().size);

  readonly hiddenSelectedCount = computed(() => {
    const visible = this.visibleIds();
    return [...this.selectedIds()].filter((id) => !visible.has(id)).length;
  });

  readonly selectedSubscriptions = computed<SubscriptionDto[]>(() => {
    const selected = this.selectedIds();
    return this.subs.subscriptions().filter((s) => selected.has(s.id));
  });

  readonly allVisibleSelected = computed(() => {
    const visible = this.visibleIds();
    if (visible.size === 0) return false;
    const selected = this.selectedIds();

    return [...visible].every((id) => selected.has(id));
  });

  toggleFeed(id: number): void {
    this.selectedIds.update((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);

      return next;
    });
  }

  setGroupSelected(group: OrganiseGroup, selected: boolean): void {
    this.selectedIds.update((current) => {
      const next = new Set(current);
      for (const s of group.subscriptions) {
        if (selected) next.add(s.id);
        else next.delete(s.id);
      }

      return next;
    });
  }

  groupState(group: OrganiseGroup): GroupState {
    if (group.subscriptions.length === 0) return 'none';
    const selected = this.selectedIds();
    const hits = group.subscriptions.filter((s) => selected.has(s.id)).length;
    if (hits === 0) return 'none';

    return hits === group.subscriptions.length ? 'all' : 'some';
  }

  toggleSelectAllVisible(): void {
    const visible = this.visibleIds();
    const selectAll = !this.allVisibleSelected();
    this.selectedIds.update((current) => {
      const next = new Set(current);
      for (const id of visible) {
        if (selectAll) next.add(id);
        else next.delete(id);
      }

      return next;
    });
  }

  clearSelection(): void {
    this.selectedIds.set(new Set());
  }

  /** A filter forces its matching groups open — a match inside a closed group
   *  is a match the user cannot see. */
  isExpanded(key: GroupKey): boolean {
    return this.filterActive() || this.expandedKeys().has(key);
  }

  toggleGroup(key: GroupKey): void {
    this.expandedKeys.update((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      persistExpanded(next);

      return next;
    });
  }

  expandAll(): void {
    const next = new Set<GroupKey>(this.groups().map((g) => g.key));
    persistExpanded(next);
    this.expandedKeys.set(next);
  }

  collapseAll(): void {
    const next = new Set<GroupKey>();
    persistExpanded(next);
    this.expandedKeys.set(next);
  }
}

function persistExpanded(keys: ReadonlySet<GroupKey>): void {
  try {
    localStorage.setItem(EXPANDED_KEY, JSON.stringify([...keys]));
  } catch {
    // A full or blocked storage must not break the page; the state simply
    // does not survive a reload.
  }
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise.store.spec.ts
```

Expected: 13 tests, 13 passing.

- [ ] **Step 5: Prove the boundary**

```bash
grep -n "ReaderApi\|HttpClient" frontend/src/app/settings/organise/organise.store.ts
```

Expected: no output. If either name appears, the store has grown a write path and the task is not done.

- [ ] **Step 6: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/settings/organise/
git commit -m "feat(#659): add the Organise selection store"
```

---

## Task 8: `OrganiseFeedRowComponent`

One feed row, purely presentational. The tree and the list both render it; only the parent knows what an arrow means, so the row emits and never acts.

**Files:**
- Create: `frontend/src/app/settings/organise/organise-feed-row.component.ts`, `.html`, `.scss`
- Test: `frontend/src/app/settings/organise/organise-feed-row.component.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionDto`, `LayoutService.isCoarse()`, `ActionSheet.open`.
- Produces: selector `app-organise-feed-row`. Inputs `subscription` (required), `selected`, `sortable` (shows the drag handle), `reorderable` (shows the arrows), `canMoveUp`, `canMoveDown`. Outputs `selectedChange: boolean`, `moveUp: void`, `moveDown: void`, `edit: void`, `toggleAllItems: void`, `toggleForYou: void`, `unsubscribe: void`. Tasks 9 and 11 bind all of them.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/settings/organise/organise-feed-row.component.spec.ts`:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { SubscriptionDto } from '../../reader/models';

const SUBSCRIPTION = {
  id: 7,
  feedId: 7,
  title: 'heise online',
  faviconUrl: null,
  customTitle: null,
  feedUrl: 'https://heise.example/rss',
  siteUrl: null,
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: '2026-01-01T00:00:00Z',
  lastFetchedAt: null,
  position: 0,
  tags: [{ id: 2, name: 'Tech', color: null, icon: null, position: 0 }],
  unreadCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
} as SubscriptionDto;

describe('OrganiseFeedRowComponent', () => {
  let fixture: ComponentFixture<OrganiseFeedRowComponent>;
  const sheetOpen = jest.fn(() => of(undefined));

  async function render(inputs: Record<string, unknown> = {}) {
    sheetOpen.mockClear();
    await TestBed.configureTestingModule({
      imports: [OrganiseFeedRowComponent, provideTranslocoTesting()],
      providers: [
        { provide: LayoutService, useValue: { isCoarse: signal(false) } },
        { provide: ActionSheet, useValue: { open: sheetOpen } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(OrganiseFeedRowComponent);
    fixture.componentRef.setInput('subscription', SUBSCRIPTION);
    fixture.componentRef.setInput('sortable', true);
    fixture.componentRef.setInput('reorderable', true);
    fixture.componentRef.setInput('canMoveUp', true);
    fixture.componentRef.setInput('canMoveDown', true);
    for (const [key, value] of Object.entries(inputs)) {
      fixture.componentRef.setInput(key, value);
    }
    fixture.detectChanges();
  }

  it('renders the feed title and its tag pills', async () => {
    await render();

    const text = fixture.nativeElement.textContent as string;
    expect(text).toContain('heise online');
    expect(text).toContain('Tech');
  });

  it('disables the up arrow at the top of a group', async () => {
    await render({ canMoveUp: false });

    const up = fixture.debugElement.query(By.css('[data-test="move-up"]'));
    expect(up.nativeElement.disabled).toBe(true);
  });

  it('emits moveDown when the down arrow is pressed', async () => {
    await render();
    const moved = jest.fn();
    fixture.componentInstance.moveDown.subscribe(moved);

    fixture.debugElement.query(By.css('[data-test="move-down"]')).nativeElement.click();

    expect(moved).toHaveBeenCalled();
  });

  it('hides the drag handle but keeps the arrows when only dragging is off', async () => {
    await render({ sortable: false });

    expect(fixture.debugElement.query(By.css('[data-test="drag-handle"]'))).toBeNull();
    expect(fixture.debugElement.query(By.css('[data-test="move-up"]'))).not.toBeNull();
  });

  it('hides the arrows in an unordered list', async () => {
    await render({ reorderable: false });

    expect(fixture.debugElement.query(By.css('[data-test="move-up"]'))).toBeNull();
  });

  it('emits selectedChange when the checkbox is toggled', async () => {
    await render();
    const changed = jest.fn();
    fixture.componentInstance.selectedChange.subscribe(changed);

    const box = fixture.debugElement.query(By.css('[data-test="select"]')).nativeElement;
    box.click();

    expect(changed).toHaveBeenCalledWith(true);
  });
});
```

Add the coarse-pointer case to the same file. It needs its own provider set, so give it its own `TestBed`:

```ts
  it('opens the action sheet instead of a popover on a coarse pointer', async () => {
    sheetOpen.mockClear();
    TestBed.resetTestingModule();
    await TestBed.configureTestingModule({
      imports: [OrganiseFeedRowComponent, provideTranslocoTesting()],
      providers: [
        { provide: LayoutService, useValue: { isCoarse: signal(true) } },
        { provide: ActionSheet, useValue: { open: sheetOpen } },
      ],
    }).compileComponents();

    const coarse = TestBed.createComponent(OrganiseFeedRowComponent);
    coarse.componentRef.setInput('subscription', SUBSCRIPTION);
    coarse.detectChanges();

    coarse.debugElement.query(By.css('[data-test="more"]')).nativeElement.click();

    expect(sheetOpen).toHaveBeenCalled();
    expect(coarse.debugElement.query(By.css('.pop'))).toBeNull();
  });
```

- [ ] **Step 2: Run the test and watch it fail**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise-feed-row.component.spec.ts
```

Expected: `Cannot find module './organise-feed-row.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/settings/organise/organise-feed-row.component.ts`:

```ts
// src/app/settings/organise/organise-feed-row.component.ts
import { ChangeDetectionStrategy, Component, DestroyRef, inject, input, output } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { CdkDragHandle } from '@angular/cdk/drag-drop';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';

/**
 * One feed row on the Organise page. Presentational on purpose: the tree and
 * the flat list both render it, and only the parent knows what "move up" means
 * in its own context, so the row emits and never writes.
 */
@Component({
  selector: 'app-organise-feed-row',
  imports: [
    TranslocoPipe,
    IconComponent,
    FaviconComponent,
    CdkDragHandle,
    DismissOnOutsideDirective,
  ],
  templateUrl: './organise-feed-row.component.html',
  styleUrl: './organise-feed-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseFeedRowComponent {
  readonly subscription = input.required<SubscriptionDto>();
  readonly selected = input(false);
  /** Whether this row can be dragged: false in the flat list, and false on a
   *  coarse pointer. It hides the handle only — the arrows are governed by
   *  `reorderable`, so a phone keeps them. */
  readonly sortable = input(false);
  /** Whether this row belongs to an ordered group at all. False only in the
   *  flat list, which has no one order to change. */
  readonly reorderable = input(false);
  readonly canMoveUp = input(false);
  readonly canMoveDown = input(false);

  readonly selectedChange = output<boolean>();
  readonly moveUp = output<void>();
  readonly moveDown = output<void>();
  readonly edit = output<void>();
  readonly toggleAllItems = output<void>();
  readonly toggleForYou = output<void>();
  readonly unsubscribe = output<void>();

  protected readonly screen = inject(LayoutService);
  private readonly sheet = inject(ActionSheet);
  private readonly i18n = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly menuOpen = signal(false);

  protected toggleMenu(): void {
    this.menuOpen.update((open) => !open);
  }

  protected closeMenu(): void {
    this.menuOpen.set(false);
  }

  /** Coarse pointers get the action sheet the sidebar already uses; a hover
   *  popover has nothing to hover. */
  protected openSheet(): void {
    const sub = this.subscription();
    this.sheet
      .open({
        title: sub.title,
        actions: [
          { id: 'edit', label: this.i18n.translate('reader.editFeed') },
          {
            id: 'allItems',
            label: this.i18n.translate(
              sub.includeInAllItems ? 'reader.excludeFromAllItems' : 'reader.includeInAllItems',
            ),
          },
          {
            id: 'forYou',
            label: this.i18n.translate(
              sub.includeInForYou ? 'reader.excludeFromForYou' : 'reader.includeInForYou',
            ),
          },
          { id: 'unsubscribe', label: this.i18n.translate('reader.unsubscribe'), danger: true },
        ],
      })
      // The sheet can outlive this row (a reload replaces the list); a late
      // choice must not emit into a destroyed output.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.edit.emit();
        if (choice === 'allItems') this.toggleAllItems.emit();
        if (choice === 'forYou') this.toggleForYou.emit();
        if (choice === 'unsubscribe') this.unsubscribe.emit();
      });
  }
}
```

Add `signal` to the `@angular/core` import list.

Create `frontend/src/app/settings/organise/organise-feed-row.component.html`:

```html
<div class="row" [class.selected]="selected()">
  @if (sortable()) {
    <span class="handle" cdkDragHandle aria-hidden="true" data-test="drag-handle">
      <app-icon name="drag_indicator" size="sm" />
    </span>
  }

  <input
    class="pick"
    type="checkbox"
    data-test="select"
    [checked]="selected()"
    [attr.aria-label]="'settings.organise.selectFeed' | transloco: { title: subscription().title }"
    (change)="selectedChange.emit($any($event.target).checked)"
  />

  <app-favicon [url]="subscription().faviconUrl" />
  <span class="title">{{ subscription().title }}</span>

  <span class="pills">
    @for (t of subscription().tags; track t.id) {
      <span class="pill">{{ t.name }}</span>
    }
  </span>

  @if (!subscription().includeInAllItems || !subscription().includeInForYou) {
    <app-icon class="excluded" name="visibility_off" size="xs" />
  }

  <span class="acts">
    @if (reorderable()) {
      <button
        type="button"
        class="ib"
        data-test="move-up"
        [disabled]="!canMoveUp()"
        [attr.aria-label]="'settings.organise.moveUp' | transloco"
        (click)="moveUp.emit()"
      >
        <app-icon name="arrow_upward" size="sm" />
      </button>
      <button
        type="button"
        class="ib"
        data-test="move-down"
        [disabled]="!canMoveDown()"
        [attr.aria-label]="'settings.organise.moveDown' | transloco"
        (click)="moveDown.emit()"
      >
        <app-icon name="arrow_downward" size="sm" />
      </button>
    }

    <button
      type="button"
      class="ib"
      data-test="edit"
      [attr.aria-label]="'reader.editFeed' | transloco"
      (click)="edit.emit()"
    >
      <app-icon name="edit" size="sm" />
    </button>

    @if (screen.isCoarse()) {
      <button
        type="button"
        class="ib"
        data-test="more"
        [attr.aria-label]="'reader.manage' | transloco: { name: subscription().title }"
        (click)="openSheet()"
      >
        <app-icon name="more_horiz" size="sm" />
      </button>
    } @else {
      <div class="menu" [appDismissOnOutside]="menuOpen()" (dismiss)="closeMenu()">
        <button
          type="button"
          class="ib"
          data-test="more"
          [attr.aria-label]="'reader.manage' | transloco: { name: subscription().title }"
          (click)="toggleMenu()"
        >
          <app-icon name="more_horiz" size="sm" />
        </button>
        @if (menuOpen()) {
          <div class="pop" role="menu">
            <button role="menuitem" (click)="toggleAllItems.emit(); closeMenu()">
              {{
                (subscription().includeInAllItems
                  ? 'reader.excludeFromAllItems'
                  : 'reader.includeInAllItems'
                ) | transloco
              }}
            </button>
            <button role="menuitem" (click)="toggleForYou.emit(); closeMenu()">
              {{
                (subscription().includeInForYou
                  ? 'reader.excludeFromForYou'
                  : 'reader.includeInForYou'
                ) | transloco
              }}
            </button>
            <button role="menuitem" class="danger" (click)="unsubscribe.emit(); closeMenu()">
              {{ 'reader.unsubscribe' | transloco }}
            </button>
          </div>
        }
      </div>
    }
  </span>
</div>
```

Create `frontend/src/app/settings/organise/organise-feed-row.component.scss`. Compact density, every value a token:

```scss
// src/app/settings/organise/organise-feed-row.component.scss
:host {
  display: block;
}

.row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) var(--row-pad-x);
  min-height: var(--tap-target);
}

.row.selected {
  background: var(--accent-soft);
}

.handle {
  display: inline-flex;
  color: var(--text-muted);
  cursor: grab;
}

.title {
  font-size: var(--fs-sm);
  color: var(--text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pills {
  display: flex;
  gap: var(--space-1);
  overflow: hidden;
}

.pill {
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--surface-0);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  font-size: var(--fs-xs);
  line-height: var(--lh-normal);
  white-space: nowrap;
}

.excluded {
  color: var(--text-muted);
}

.acts {
  display: flex;
  align-items: center;
  gap: var(--space-0);
  margin-left: auto;
}

.ib {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--icon-lg);
  height: var(--icon-lg);
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
}

.ib:disabled {
  opacity: 0.35;
  cursor: default;
}

.ib:hover:not(:disabled) {
  background: var(--surface-0);
  color: var(--text-primary);
}

.menu {
  position: relative;
}

.pop {
  position: absolute;
  right: 0;
  z-index: 2;
  display: flex;
  flex-direction: column;
  min-width: 12rem;
  padding: var(--space-1);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-2);
  box-shadow: var(--panel-shadow);
}

.pop button {
  padding: var(--row-pad-y) var(--row-pad-x);
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-primary);
  font-size: var(--fs-sm);
  text-align: left;
  cursor: pointer;
}

.pop button:hover {
  background: var(--surface-0);
}

.pop button.danger {
  color: var(--danger);
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise-feed-row.component.spec.ts
```

Expected: 5 tests, 5 passing. The transloco keys do not exist yet, so the rendered text may be the key itself — the two text assertions read the feed title and the tag name, which are data, not keys.

- [ ] **Step 5: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/settings/organise/
git commit -m "feat(#659): add the Organise feed row"
```

---

## Task 9: `OrganiseTagGroupComponent`

One tag panel: the header row, its feeds, and its drop lists. It injects `OrganiseStore` (provided by the page) and `ManageActions`, so the page does not have to relay a dozen row events.

**Two sibling drop lists per group, never nested** — a header list that accepts a tag drag (reorder) or a feed drag (move the feed into this tag), and a feed list that accepts feed drags. `sidebar.component.html` is the working precedent for exactly this shape; copy its structure, not its semantics (there a cross-tag drop **adds**, here it **moves**).

**Files:**
- Create: `frontend/src/app/settings/organise/organise-tag-group.component.ts`, `.html`, `.scss`
- Test: `frontend/src/app/settings/organise/organise-tag-group.component.spec.ts`

**Interfaces:**
- Consumes: `OrganiseGroup`, `OrganiseStore` (Task 7), `OrganiseFeedRowComponent` (Task 8), `ManageActions` (Task 6, plus the existing `editSubscription`, `setIncludeInAllItems`, `setIncludeInForYou`, `unsubscribe`, `retag`, `reorderTagFeeds`, `reorderUntagged`, `editTag`, `deleteTag`).
- Produces: selector `app-organise-tag-group`. Inputs `group` (required), `canMoveTagUp`, `canMoveTagDown`. Outputs `moveTagUp: void`, `moveTagDown: void`. Task 11 binds both and renders the list of groups.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/settings/organise/organise-tag-group.component.spec.ts`. The harness in full — the store is real (it holds no writes), the actions are mocked:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { OrganiseGroup, OrganiseStore } from './organise.store';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { SubscriptionDto, TagDto } from '../../reader/models';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };
const OTHER_TAG: TagDto = { id: 4, name: 'Nachrichten', color: null, icon: null, position: 1 };

const feed = (id: number, title: string, tagIds: number[]): SubscriptionDto =>
  ({
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://feed-${id}.example/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: `Tag ${tagId}`,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

const SUB_A = feed(10, 'taz', [TECH.id]);
const SUB_B = feed(11, 'heise', [TECH.id]);
const SUB_WITH_TWO_TAGS = feed(12, 'netzpolitik', [TECH.id, OTHER_TAG.id]);

const GROUP: OrganiseGroup = { key: TECH.id, tag: TECH, subscriptions: [SUB_A, SUB_B], totalCount: 2 };
const UNTAGGED_GROUP: OrganiseGroup = {
  key: 'untagged',
  tag: null,
  subscriptions: [SUB_A, SUB_B],
  totalCount: 2,
};

describe('OrganiseTagGroupComponent', () => {
  let fixture: ComponentFixture<OrganiseTagGroupComponent>;

  const manage = {
    reorderTagFeeds: jest.fn(),
    reorderUntagged: jest.fn(),
    retag: jest.fn(),
    editTag: jest.fn(),
    deleteTag: jest.fn(),
    editSubscription: jest.fn(),
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    unsubscribe: jest.fn(),
  };

  async function render(
    group: OrganiseGroup,
    options: { expanded?: boolean; selected?: number[]; coarse?: boolean } = {},
  ) {
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseTagGroupComponent, provideTranslocoTesting()],
        providers: [
            OrganiseStore,
          { provide: ManageActions, useValue: manage },
          { provide: LayoutService, useValue: { isCoarse: signal(options.coarse ?? false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          {
            provide: SubscriptionsStore,
            useValue: { subscriptions: signal([SUB_A, SUB_B, SUB_WITH_TWO_TAGS]) },
          },
          { provide: TagsStore, useValue: { tags: signal([TECH, OTHER_TAG]) } },
        ],
      })
      .compileComponents();

    const store = TestBed.inject(OrganiseStore);
    if (options.expanded !== false) store.toggleGroup(group.key);
    for (const id of options.selected ?? []) store.toggleFeed(id);

    fixture = TestBed.createComponent(OrganiseTagGroupComponent);
    fixture.componentRef.setInput('group', group);
    fixture.detectChanges();

    return { store, manage, component: fixture.componentInstance };
  }
```

The cases:

```ts
it('shows the total feed count, not the filtered count', async () => {
  await render({ ...GROUP, subscriptions: [SUB_A], totalCount: 12 });

  expect(fixture.nativeElement.textContent).toContain('12');
});

it('renders no feed rows while the group is collapsed', async () => {
  await render(GROUP, { expanded: false });

  expect(fixture.debugElement.queryAll(By.css('app-organise-feed-row'))).toHaveLength(0);
});

it('marks the header checkbox indeterminate when some feeds are selected', async () => {
  await render(GROUP, { selected: [SUB_A.id] });

  const box = fixture.debugElement.query(By.css('[data-test="group-select"]')).nativeElement;
  expect(box.indeterminate).toBe(true);
  expect(box.checked).toBe(false);
});

it('selects every feed of the group from the header checkbox', async () => {
  const store = await render(GROUP);

  fixture.debugElement.query(By.css('[data-test="group-select"]')).nativeElement.click();

  expect(store.selectedCount()).toBe(GROUP.subscriptions.length);
});

it('reorders within the tag when a feed moves down', async () => {
  const { manage } = await render(GROUP);

  fixture.debugElement.queryAll(By.css('app-organise-feed-row'))[0].componentInstance.moveDown.emit();

  expect(manage.reorderTagFeeds).toHaveBeenCalledWith(GROUP.tag.id, [SUB_B.id, SUB_A.id]);
});

it('reorders the untagged list when the group is the untagged bucket', async () => {
  const { manage } = await render(UNTAGGED_GROUP);

  fixture.debugElement.queryAll(By.css('app-organise-feed-row'))[0].componentInstance.moveDown.emit();

  expect(manage.reorderUntagged).toHaveBeenCalledWith([SUB_B.id, SUB_A.id]);
});

it('moves a feed out of the source tag when it is dropped on another group', async () => {
  const { manage, component } = await render(GROUP);

  component.onFeedDropped({
    previousContainer: { data: { key: 4, tag: OTHER_TAG } },
    container: { data: GROUP },
    item: { data: SUB_WITH_TWO_TAGS },
    previousIndex: 0,
    currentIndex: 0,
  } as never);

  // tag 4 dropped, this group's tag added — a move, not a copy.
  expect(manage.retag).toHaveBeenCalledWith(SUB_WITH_TWO_TAGS, [GROUP.tag.id]);
});

it('clears every tag when a feed is dropped on the untagged group', async () => {
  const { manage, component } = await render(UNTAGGED_GROUP);

  component.onFeedDropped({
    previousContainer: { data: GROUP },
    container: { data: UNTAGGED_GROUP },
    item: { data: SUB_A },
    previousIndex: 0,
    currentIndex: 0,
  } as never);

  expect(manage.retag).toHaveBeenCalledWith(SUB_A, []);
});

it('turns drag off on a coarse pointer, keeping the arrows', async () => {
  await render(GROUP, { coarse: true });

  const rows = fixture.debugElement.queryAll(By.css('app-organise-feed-row'));
  expect(rows[0].componentInstance.sortable()).toBe(false);
  expect(fixture.debugElement.query(By.css('[data-test="arrows-only"]'))).not.toBeNull();
});
```

Close the `describe` block after the last case.

- [ ] **Step 2: Run the test and watch it fail**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise-tag-group.component.spec.ts
```

Expected: `Cannot find module './organise-tag-group.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/settings/organise/organise-tag-group.component.ts`:

```ts
// src/app/settings/organise/organise-tag-group.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { CdkDrag, CdkDragDrop, CdkDropList } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { OrganiseGroup, OrganiseStore } from './organise.store';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';

/**
 * One tag panel: a header row, and — when open — its feeds.
 *
 * Two sibling drop lists, never nested: CDK does not connect a list inside
 * another list, so a wrapping list here would silently break every drop. The
 * sidebar solves the same problem the same way (see sidebar.component.html).
 *
 * A cross-group drop MOVES the feed: the source tag is removed and this group's
 * tag is added. That differs from the sidebar, where a drop only ever adds —
 * on a page that shows the whole arrangement, dragging from one group to
 * another reads as "put it there".
 */
@Component({
  selector: 'app-organise-tag-group',
  imports: [
    TranslocoPipe,
    IconComponent,
    TagGlyphComponent,
    OrganiseFeedRowComponent,
    CdkDropList,
    CdkDrag,
  ],
  templateUrl: './organise-tag-group.component.html',
  styleUrl: './organise-tag-group.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseTagGroupComponent {
  readonly group = input.required<OrganiseGroup>();
  readonly canMoveTagUp = input(false);
  readonly canMoveTagDown = input(false);

  readonly moveTagUp = output<void>();
  readonly moveTagDown = output<void>();

  protected readonly store = inject(OrganiseStore);
  protected readonly manage = inject(ManageActions);
  protected readonly screen = inject(LayoutService);

  /** Drag is pointer-only. On a phone a drag inside a scrolling page fights the
   *  scroll — the sidebar needed a long-press guard and a whole Organise mode
   *  to make it work. The arrows do the same job with none of that. */
  protected readonly dragDisabled = computed(() => this.screen.isCoarse());

  protected readonly expanded = computed(() => this.store.isExpanded(this.group().key));
  protected readonly state = computed(() => this.store.groupState(this.group()));

  protected toggle(): void {
    this.store.toggleGroup(this.group().key);
  }

  protected onGroupSelect(selected: boolean): void {
    this.store.setGroupSelected(this.group(), selected);
  }

  protected moveFeed(subscription: SubscriptionDto, offset: number): void {
    const ids = this.group().subscriptions.map((s) => s.id);
    const from = ids.indexOf(subscription.id);
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];

    const tag = this.group().tag;
    if (tag === null) {
      this.manage.reorderUntagged(ids);
      return;
    }
    this.manage.reorderTagFeeds(tag.id, ids);
  }

  /** A drop inside this group reorders it; a drop from another group moves the
   *  feed between tags. */
  onFeedDropped(event: CdkDragDrop<OrganiseGroup>): void {
    const subscription = event.item.data as SubscriptionDto;

    if (event.previousContainer === event.container) {
      this.reorderTo(subscription, event.previousIndex, event.currentIndex);
      return;
    }

    this.manage.retag(subscription, this.tagIdsAfterMove(subscription, event.previousContainer.data));
  }

  private reorderTo(subscription: SubscriptionDto, from: number, to: number): void {
    if (from === to) return;
    const ids = this.group().subscriptions.map((s) => s.id);
    ids.splice(to, 0, ...ids.splice(from, 1));

    const tag = this.group().tag;
    if (tag === null) {
      this.manage.reorderUntagged(ids);
      return;
    }
    this.manage.reorderTagFeeds(tag.id, ids);
  }

  /** The feed's tags after a move into this group: the source tag goes, this
   *  group's tag arrives. A drop on the untagged group clears every tag. */
  private tagIdsAfterMove(subscription: SubscriptionDto, source: OrganiseGroup): number[] {
    const target = this.group().tag;
    if (target === null) return [];

    const kept = subscription.tags
      .map((t) => t.id)
      .filter((id) => id !== source.tag?.id && id !== target.id);

    return [...kept, target.id];
  }
}
```

Create `frontend/src/app/settings/organise/organise-tag-group.component.html`:

```html
<section class="panel">
  <!-- The header is its own single-item drop list so a tag can be reordered by
       drag without nesting it inside the feed list below. -->
  <div
    class="head-drop"
    cdkDropList
    [cdkDropListData]="group()"
    [cdkDropListSortingDisabled]="true"
    (cdkDropListDropped)="onFeedDropped($event)"
  >
    <div class="head" cdkDrag [cdkDragData]="group()" [cdkDragDisabled]="dragDisabled()">
      <button
        type="button"
        class="chev"
        data-test="toggle"
        [attr.aria-expanded]="expanded()"
        [attr.aria-label]="'settings.organise.toggleGroup' | transloco: { name: label() }"
        (click)="toggle()"
      >
        <app-icon [name]="expanded() ? 'expand_more' : 'chevron_right'" size="sm" />
      </button>

      <input
        class="pick"
        type="checkbox"
        data-test="group-select"
        [checked]="state() === 'all'"
        [indeterminate]="state() === 'some'"
        [attr.aria-label]="'settings.organise.selectGroup' | transloco: { name: label() }"
        (change)="onGroupSelect($any($event.target).checked)"
      />

      @if (group().tag; as tag) {
        <app-tag-glyph [name]="tag.icon" [color]="tag.color" size="sm" />
      } @else {
        <app-icon name="inbox" size="sm" />
      }

      <span class="name">{{ label() }}</span>
      <span class="count">{{
        (group().totalCount === 1
          ? 'settings.organise.feedCountOne'
          : 'settings.organise.feedCountOther'
        ) | transloco: { count: group().totalCount }
      }}</span>

      <span class="acts">
        <button
          type="button"
          class="ib"
          data-test="tag-up"
          [disabled]="!canMoveTagUp()"
          [attr.aria-label]="'settings.organise.moveUp' | transloco"
          (click)="moveTagUp.emit()"
        >
          <app-icon name="arrow_upward" size="sm" />
        </button>
        <button
          type="button"
          class="ib"
          data-test="tag-down"
          [disabled]="!canMoveTagDown()"
          [attr.aria-label]="'settings.organise.moveDown' | transloco"
          (click)="moveTagDown.emit()"
        >
          <app-icon name="arrow_downward" size="sm" />
        </button>
        @if (group().tag; as tag) {
          <button
            type="button"
            class="ib"
            [attr.aria-label]="'reader.editTag' | transloco"
            (click)="manage.editTag(tag)"
          >
            <app-icon name="edit" size="sm" />
          </button>
          <button
            type="button"
            class="ib danger"
            [attr.aria-label]="'reader.deleteTag' | transloco"
            (click)="manage.deleteTag(tag)"
          >
            <app-icon name="delete" size="sm" />
          </button>
        }
      </span>
    </div>
  </div>

  @if (expanded()) {
    <div
      class="feeds"
      cdkDropList
      [cdkDropListData]="group()"
      (cdkDropListDropped)="onFeedDropped($event)"
    >
      @for (s of group().subscriptions; track s.id; let i = $index, last = $last) {
        <div cdkDrag [cdkDragData]="s" [cdkDragDisabled]="dragDisabled()">
          <app-organise-feed-row
            [subscription]="s"
            [sortable]="!dragDisabled()"
            [reorderable]="true"
            [attr.data-test]="dragDisabled() ? 'arrows-only' : null"
            [selected]="store.selectedIds().has(s.id)"
            [canMoveUp]="i > 0"
            [canMoveDown]="!last"
            (selectedChange)="store.toggleFeed(s.id)"
            (moveUp)="moveFeed(s, -1)"
            (moveDown)="moveFeed(s, 1)"
            (edit)="manage.editSubscription(s)"
            (toggleAllItems)="manage.setIncludeInAllItems(s, !s.includeInAllItems)"
            (toggleForYou)="manage.setIncludeInForYou(s, !s.includeInForYou)"
            (unsubscribe)="manage.unsubscribe(s)"
          />
        </div>
      }
      @if (group().subscriptions.length === 0) {
        <p class="empty">{{ 'settings.organise.groupEmpty' | transloco }}</p>
      }
    </div>
  }
</section>
```

Add the `label` computed to the component:

```ts
  protected readonly label = computed(
    () => this.group().tag?.name ?? this.i18n.translate('settings.organise.untagged'),
  );
```

with `private readonly i18n = inject(TranslocoService);` and the matching import.

Create `frontend/src/app/settings/organise/organise-tag-group.component.scss` — the settings panel shape at compact density:

```scss
// src/app/settings/organise/organise-tag-group.component.scss
:host {
  display: block;
}

.panel {
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface-1);
  box-shadow: var(--panel-shadow);
  overflow: hidden;
}

.head {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--row-pad-y) var(--row-pad-x);
  min-height: var(--tap-target);
}

.head-drop.cdk-drop-list-dragging,
.head-drop:has(.cdk-drag-preview) {
  background: var(--accent-soft);
}

.chev,
.ib {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: var(--icon-lg);
  height: var(--icon-lg);
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-muted);
  cursor: pointer;
}

.ib:disabled {
  opacity: 0.35;
  cursor: default;
}

.ib.danger {
  color: var(--danger);
}

.name {
  font-size: var(--fs-sm);
  font-weight: 600;
  color: var(--text-primary);
}

.count {
  font-size: var(--fs-xs);
  color: var(--text-muted);
}

.acts {
  display: flex;
  align-items: center;
  gap: var(--space-0);
  margin-left: auto;
}

.feeds {
  border-top: 1px solid var(--border);
}

.feeds > div + div {
  border-top: 1px solid var(--border);
}

.empty {
  margin: 0;
  padding: var(--panel-inset-y) var(--panel-inset-x);
  color: var(--text-muted);
  font-size: var(--fs-sm);
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise-tag-group.component.spec.ts
```

Expected: 8 tests, 8 passing.

- [ ] **Step 5: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/settings/organise/
git commit -m "feat(#659): add the Organise tag group"
```

---

## Task 10: `BulkTagDialogComponent`

One component, two modes. Add mode lists every tag with `(n/N)` — how many of the selection already carry it. Remove mode lists only the tags the selection actually carries, and says how many feeds will lose their last tag. One tag per confirm. Nothing is written until Apply; the dialog closes with the chosen tag and the caller does the write.

**Files:**
- Create: `frontend/src/app/settings/organise/bulk-tag-dialog.component.ts`, `.html`, `.scss`
- Test: `frontend/src/app/settings/organise/bulk-tag-dialog.component.spec.ts`

**Interfaces:**
- Consumes: `DIALOG_DATA` of shape `BulkTagDialogData { mode: 'add' | 'remove'; subscriptions: SubscriptionDto[]; tags: TagDto[] }`, `OverlayPanelComponent`, `ButtonComponent`, `TagGlyphComponent`.
- Produces: closes with `TagDto | undefined`. Task 11 opens it and calls `bulkAddTag` / `bulkRemoveTag` with the result.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/settings/organise/bulk-tag-dialog.component.spec.ts`. The harness in full:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { BulkTagDialogComponent, BulkTagDialogData } from './bulk-tag-dialog.component';
import { SubscriptionDto, TagDto } from '../../reader/models';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };
const NEWS: TagDto = { id: 3, name: 'Nachrichten', color: null, icon: null, position: 1 };

const feed = (id: number, tagIds: number[]): SubscriptionDto =>
  ({
    id,
    feedId: id,
    title: `Feed ${id}`,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://feed-${id}.example/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: tagId === TECH.id ? TECH.name : NEWS.name,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

const SUB_WITH_TECH = feed(10, [TECH.id]);
const SUB_WITHOUT = feed(11, []);

describe('BulkTagDialogComponent', () => {
  let fixture: ComponentFixture<BulkTagDialogComponent>;

  async function render(data: BulkTagDialogData) {
    const close = jest.fn();
    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [BulkTagDialogComponent, provideTranslocoTesting()],
        providers: [
            { provide: DIALOG_DATA, useValue: data },
          { provide: DialogRef, useValue: { close } },
        ],
      })
      .compileComponents();

    fixture = TestBed.createComponent(BulkTagDialogComponent);
    fixture.detectChanges();

    return { close };
  }
```

The cases:

```ts
it('counts how many of the selection already carry each tag', async () => {
  await render({ mode: 'add', subscriptions: [SUB_WITH_TECH, SUB_WITHOUT], tags: [TECH, NEWS] });

  const text = fixture.nativeElement.textContent as string;
  expect(text).toContain('1/2');
  expect(text).toContain('0/2');
});

it('offers every tag in add mode', async () => {
  await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH, NEWS] });

  expect(fixture.debugElement.queryAll(By.css('[data-test="tag-pill"]'))).toHaveLength(2);
});

it('offers only the tags the selection carries in remove mode', async () => {
  await render({ mode: 'remove', subscriptions: [SUB_WITH_TECH], tags: [TECH, NEWS] });

  const pills = fixture.debugElement.queryAll(By.css('[data-test="tag-pill"]'));
  expect(pills).toHaveLength(1);
  expect(pills[0].nativeElement.textContent).toContain('Tech');
});

it('warns how many feeds lose their last tag', async () => {
  await render({ mode: 'remove', subscriptions: [SUB_WITH_TECH], tags: [TECH] });
  fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
  fixture.detectChanges();

  expect(fixture.componentInstance.losingLastTag()).toBe(1);
});

it('closes with the chosen tag on apply', async () => {
  const { close } = await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });
  fixture.debugElement.query(By.css('[data-test="tag-pill"]')).nativeElement.click();
  fixture.detectChanges();

  fixture.debugElement.query(By.css('[data-test="apply"]')).nativeElement.click();

  expect(close).toHaveBeenCalledWith(TECH);
});

it('closes with nothing on cancel', async () => {
  const { close } = await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });

  fixture.debugElement.query(By.css('[data-test="cancel"]')).nativeElement.click();

  expect(close).toHaveBeenCalledWith(undefined);
});

it('keeps apply disabled until a tag is chosen', async () => {
  await render({ mode: 'add', subscriptions: [SUB_WITHOUT], tags: [TECH] });

  expect(fixture.debugElement.query(By.css('[data-test="apply"]')).componentInstance.disabled()).toBe(true);
});
```

Close the `describe` block after the last case.

- [ ] **Step 2: Run the test and watch it fail**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/bulk-tag-dialog.component.spec.ts
```

Expected: `Cannot find module './bulk-tag-dialog.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/settings/organise/bulk-tag-dialog.component.ts`:

```ts
// src/app/settings/organise/bulk-tag-dialog.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { OverlayPanelComponent } from '../../shared/overlay-panel/overlay-panel.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { SubscriptionDto, TagDto } from '../../reader/models';

export interface BulkTagDialogData {
  readonly mode: 'add' | 'remove';
  readonly subscriptions: SubscriptionDto[];
  readonly tags: TagDto[];
}

/**
 * Choose one tag to add to, or remove from, the current selection.
 *
 * Nothing is written here: the dialog closes with the chosen tag and the caller
 * performs the bulk write through ManageActions. That keeps the one-write-path
 * rule intact and makes a wrong click free — until Apply, the user has changed
 * nothing.
 */
@Component({
  selector: 'app-bulk-tag-dialog',
  imports: [TranslocoPipe, OverlayPanelComponent, ButtonComponent, TagGlyphComponent],
  templateUrl: './bulk-tag-dialog.component.html',
  styleUrl: './bulk-tag-dialog.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class BulkTagDialogComponent {
  readonly ref = inject<DialogRef<TagDto | undefined>>(DialogRef);
  readonly data = inject<BulkTagDialogData>(DIALOG_DATA);

  readonly chosen = signal<TagDto | null>(null);

  readonly total = this.data.subscriptions.length;

  /** Add mode offers every tag; remove mode offers only what the selection
   *  actually carries — a tag nobody has is not a tag anybody can lose. */
  readonly offered = computed<TagDto[]>(() => {
    if (this.data.mode === 'add') return this.data.tags;

    return this.data.tags.filter((tag) => this.carriedBy(tag) > 0);
  });

  /** How many of the selected feeds already carry this tag. */
  carriedBy(tag: TagDto): number {
    return this.data.subscriptions.filter((s) => s.tags.some((t) => t.id === tag.id)).length;
  }

  /** In remove mode, how many feeds would be left with no tag at all. */
  readonly losingLastTag = computed<number>(() => {
    const tag = this.chosen();
    if (tag === null || this.data.mode !== 'remove') return 0;

    return this.data.subscriptions.filter(
      (s) => s.tags.length === 1 && s.tags[0].id === tag.id,
    ).length;
  });

  /** How many feeds the Apply button will actually change. */
  readonly affected = computed<number>(() => {
    const tag = this.chosen();
    if (tag === null) return 0;
    const carried = this.carriedBy(tag);

    return this.data.mode === 'add' ? this.total - carried : carried;
  });

  choose(tag: TagDto): void {
    this.chosen.set(tag);
  }

  apply(): void {
    const tag = this.chosen();
    if (tag === null) return;
    this.ref.close(tag);
  }

  cancel(): void {
    this.ref.close(undefined);
  }
}
```

Create `frontend/src/app/settings/organise/bulk-tag-dialog.component.html`:

```html
<app-overlay-panel
  [heading]="
    (data.mode === 'add' ? 'settings.organise.addTagTitle' : 'settings.organise.removeTagTitle')
      | transloco: { count: total }
  "
>
  <p class="hint">{{ 'settings.organise.tagCountHint' | transloco }}</p>

  @if (offered().length === 0) {
    <p class="hint">{{ 'settings.organise.noTagsToRemove' | transloco }}</p>
  } @else {
    <div class="pills">
      @for (tag of offered(); track tag.id) {
        <button
          type="button"
          class="pill"
          data-test="tag-pill"
          [class.chosen]="chosen()?.id === tag.id"
          [attr.aria-pressed]="chosen()?.id === tag.id"
          (click)="choose(tag)"
        >
          <app-tag-glyph [name]="tag.icon" [color]="tag.color" size="sm" />
          <span>{{ tag.name }}</span>
          <span class="n">{{ carriedBy(tag) }}/{{ total }}</span>
        </button>
      }
    </div>
  }

  @if (chosen(); as tag) {
    <p class="effect">
      {{
        (data.mode === 'add' ? 'settings.organise.addEffect' : 'settings.organise.removeEffect')
          | transloco: { name: tag.name, count: affected() }
      }}
      @if (losingLastTag() > 0) {
        {{ 'settings.organise.losingLastTag' | transloco: { count: losingLastTag() } }}
      }
    </p>
  }

  <div class="foot">
    <app-button data-test="cancel" (click)="cancel()">
      {{ 'settings.tags.cancel' | transloco }}
    </app-button>
    <app-button
      data-test="apply"
      variant="primary"
      [disabled]="chosen() === null"
      (click)="apply()"
    >
      {{
        (data.mode === 'add' ? 'settings.organise.addTagApply' : 'settings.organise.removeTagApply')
          | transloco
      }}
    </app-button>
  </div>
</app-overlay-panel>
```

Create `frontend/src/app/settings/organise/bulk-tag-dialog.component.scss`:

```scss
// src/app/settings/organise/bulk-tag-dialog.component.scss
.hint,
.effect {
  margin: 0 0 var(--space-3);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}

.pills {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-1) var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-pill);
  background: var(--surface-1);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  cursor: pointer;
}

.pill.chosen {
  border-color: var(--accent);
  background: var(--accent-soft);
  color: var(--accent);
  font-weight: 600;
}

.pill .n {
  font-variant-numeric: tabular-nums;
  font-size: var(--fs-xs);
  opacity: 0.75;
}

.foot {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-2);
}
```

- [ ] **Step 4: Run the test and watch it pass**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/bulk-tag-dialog.component.spec.ts
```

Expected: 7 tests, 7 passing.

- [ ] **Step 5: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/settings/organise/
git commit -m "feat(#659): add the bulk tag dialog"
```

---

## Task 11: The section, the toolbar and the bulk bar

**Files:**
- Create: `frontend/src/app/settings/organise/organise-section.component.ts`, `.html`, `.scss`
- Test: `frontend/src/app/settings/organise/organise-section.component.spec.ts`
- Modify: `frontend/src/app/settings/settings-sections.ts`
- Modify: `frontend/src/app/settings/settings.routes.ts`

**Interfaces:**
- Consumes: everything from Tasks 6-10.
- Produces: the route `/settings/organise`.

The bulk bar sits **in the flow** under the toolbar and pushes the list down. It does not float: the toast docks bottom-centre, and a floating bar would both collide with it and cover the last rows.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/app/settings/organise/organise-section.component.spec.ts`. The harness in full — `render` gives you the real store, `renderWithMocks` additionally hands back the mocked actions:

```ts
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { By } from '@angular/platform-browser';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { OrganiseSectionComponent } from './organise-section.component';
import { OrganiseStore } from './organise.store';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { SubscriptionDto, TagDto } from '../../reader/models';

const TECH: TagDto = { id: 2, name: 'Tech', color: null, icon: null, position: 0 };

const feed = (id: number, title: string, tagIds: number[]): SubscriptionDto =>
  ({
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://feed-${id}.example/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: TECH.name,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  }) as SubscriptionDto;

const SUBS = [feed(10, 'taz', [TECH.id]), feed(11, 'heise', [])];

describe('OrganiseSectionComponent', () => {
  let fixture: ComponentFixture<OrganiseSectionComponent>;

  const manage = {
    bulkAddTag: jest.fn(() => of(undefined)),
    bulkRemoveTag: jest.fn(() => of(undefined)),
    bulkSetFlags: jest.fn(() => of(undefined)),
    bulkUnsubscribe: jest.fn(() => of(true)),
    addFeed: jest.fn(() => of(undefined)),
    createTag: jest.fn(),
    editTag: jest.fn(),
    deleteTag: jest.fn(),
    editSubscription: jest.fn(),
    setIncludeInAllItems: jest.fn(),
    setIncludeInForYou: jest.fn(),
    unsubscribe: jest.fn(),
    retag: jest.fn(),
    reorderTags: jest.fn(),
    reorderTagFeeds: jest.fn(),
    reorderUntagged: jest.fn(),
  };

  async function renderWithMocks() {
    localStorage.clear();
    for (const spy of Object.values(manage)) spy.mockReset();
    manage.bulkAddTag.mockReturnValue(of(undefined));
    manage.bulkRemoveTag.mockReturnValue(of(undefined));
    manage.bulkSetFlags.mockReturnValue(of(undefined));
    manage.bulkUnsubscribe.mockReturnValue(of(true));

    await TestBed.resetTestingModule()
      .configureTestingModule({
        imports: [OrganiseSectionComponent, provideTranslocoTesting()],
        providers: [
            { provide: ManageActions, useValue: manage },
          { provide: Dialog, useValue: { open: jest.fn(() => ({ closed: of(undefined) })) } },
          { provide: LayoutService, useValue: { isCoarse: signal(false) } },
          { provide: ActionSheet, useValue: { open: jest.fn(() => of(undefined)) } },
          {
            provide: SubscriptionsStore,
            useValue: { subscriptions: signal(SUBS), loading: signal(false), load: jest.fn() },
          },
          { provide: TagsStore, useValue: { tags: signal([TECH]), load: jest.fn() } },
        ],
      })
      .compileComponents();

    fixture = TestBed.createComponent(OrganiseSectionComponent);
    fixture.detectChanges();
    const component = fixture.componentInstance;

    return { component, manage, store: component.store };
  }

  async function render(): Promise<OrganiseStore> {
    const { store } = await renderWithMocks();

    return store;
  }
```

The cases:

```ts
it('hides the bulk bar at zero selection', async () => {
  await render();

  expect(fixture.debugElement.query(By.css('[data-test="bulk-bar"]'))).toBeNull();
});

it('shows the exact count once something is selected', async () => {
  const store = await render();

  store.toggleFeed(10);
  fixture.detectChanges();

  expect(fixture.debugElement.query(By.css('[data-test="bulk-count"]')).nativeElement.textContent)
    .toContain('1');
});

it('names how many selected feeds the filter hides', async () => {
  const store = await render();
  store.toggleFeed(10);
  store.toggleFeed(11);

  store.titleFilter.set('heise');
  fixture.detectChanges();

  expect(fixture.debugElement.query(By.css('[data-test="bulk-hidden"]')).nativeElement.textContent)
    .toContain('1');
});

it('select all takes exactly the visible rows', async () => {
  const store = await render();
  store.titleFilter.set('heise');
  fixture.detectChanges();

  fixture.debugElement.query(By.css('[data-test="select-all"]')).nativeElement.click();

  expect([...store.selectedIds()]).toEqual([11]);
});

it('renders no arrows and no handles in the list view', async () => {
  const store = await render();

  store.view.set('list');
  fixture.detectChanges();

  expect(fixture.debugElement.query(By.css('[data-test="move-up"]'))).toBeNull();
  expect(fixture.debugElement.query(By.css('[data-test="drag-handle"]'))).toBeNull();
});

it('disables the bulk bar while a write is in flight', async () => {
  const store = await render();
  store.toggleFeed(10);
  store.busy.set(true);
  fixture.detectChanges();

  expect(fixture.debugElement.query(By.css('[data-test="bulk-unsubscribe"]')).componentInstance.disabled())
    .toBe(true);
});

it('clears the selection after an unsubscribe but keeps it after a tag write', async () => {
  const { component, manage } = await renderWithMocks();
  manage.bulkAddTag.mockReturnValue(of(undefined));
  manage.bulkUnsubscribe.mockReturnValue(of(true));
  component.store.toggleFeed(10);

  component.applyTag(TECH, 'add');
  expect(component.store.selectedCount()).toBe(1);

  component.unsubscribeSelected();
  expect(component.store.selectedCount()).toBe(0);
});

it('keeps the selection when a bulk write fails, and shows the error', async () => {
  const { component, manage } = await renderWithMocks();
  manage.bulkAddTag.mockReturnValue(throwError(() => new HttpErrorResponse({ status: 422 })));
  component.store.toggleFeed(10);

  component.applyTag(TECH, 'add');

  expect(component.store.selectedCount()).toBe(1);
  expect(component.error()).not.toBeNull();
});

it('opens the add-feed dialog from the page header', async () => {
  const { manage } = await renderWithMocks();

  fixture.debugElement.query(By.css('[data-test="add-feed"]')).nativeElement.click();

  expect(manage.addFeed).toHaveBeenCalled();
});

it('narrows the list to untagged feeds through the tag filter', async () => {
  const store = await render();

  store.tagFilter.set(new Set(['untagged']));
  fixture.detectChanges();

  expect(store.filteredSubscriptions().map((s) => s.id)).toEqual([11]);
});
```

Close the `describe` block after the last case.

- [ ] **Step 2: Run the test and watch it fail**

```bash
docker compose exec -T frontend npx jest src/app/settings/organise/organise-section.component.spec.ts
```

Expected: `Cannot find module './organise-section.component'`.

- [ ] **Step 3: Write the component**

Create `frontend/src/app/settings/organise/organise-section.component.ts`:

```ts
// src/app/settings/organise/organise-section.component.ts
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { CdkDropListGroup } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { IconComponent } from '../../shared/icon/icon.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { OrganiseStore, OrganiseGroup, GroupKey } from './organise.store';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { BulkTagDialogComponent, BulkTagDialogData } from './bulk-tag-dialog.component';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { Problem, parseProblem } from '../../core/problem';
import { SubscriptionFlags, TagDto } from '../../reader/models';

/**
 * The Organise page: every tag and every feed, in a tree or a flat list, with
 * multi-select and one bulk bar.
 *
 * The store is provided here, not in root: a selection must not survive leaving
 * the page. Every write goes through ManageActions — this component injects no
 * ReaderApi.
 */
@Component({
  selector: 'app-organise-section',
  imports: [
    TranslocoPipe,
    IconComponent,
    ButtonComponent,
    SkeletonComponent,
    ErrorBannerComponent,
    OrganiseTagGroupComponent,
    OrganiseFeedRowComponent,
    DismissOnOutsideDirective,
    CdkDropListGroup,
  ],
  providers: [OrganiseStore],
  templateUrl: './organise-section.component.html',
  styleUrl: './organise-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseSectionComponent implements OnInit {
  readonly store = inject(OrganiseStore);
  protected readonly manage = inject(ManageActions);
  protected readonly subs = inject(SubscriptionsStore);
  protected readonly tags = inject(TagsStore);
  private readonly dialog = inject(Dialog);

  /** The last refused bulk write, shown as a banner above the list. */
  readonly error = signal<Problem | null>(null);
  protected readonly tagFilterOpen = signal(false);
  protected readonly visibilityOpen = signal(false);

  protected toggleTagFilter(key: GroupKey): void {
    this.store.tagFilter.update((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);

      return next;
    });
  }

  ngOnInit(): void {
    this.tags.load();
    this.subs.load();
  }

  protected openTagDialog(mode: 'add' | 'remove'): void {
    const data: BulkTagDialogData = {
      mode,
      subscriptions: this.store.selectedSubscriptions(),
      tags: this.tags.tags(),
    };
    const ref = this.dialog.open<TagDto | undefined>(BulkTagDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((tag) => {
      if (tag) this.applyTag(tag, mode);
    });
  }

  /** Selection survives a tag write: tagging N feeds is usually followed by a
   *  flag change on the same N. */
  applyTag(tag: TagDto, mode: 'add' | 'remove'): void {
    const ids = [...this.store.selectedIds()];
    const write$ =
      mode === 'add' ? this.manage.bulkAddTag(ids, tag) : this.manage.bulkRemoveTag(ids, tag);
    this.runBulk(write$);
  }

  setFlags(flags: SubscriptionFlags): void {
    this.runBulk(this.manage.bulkSetFlags([...this.store.selectedIds()], flags));
  }

  /** Selection clears after an unsubscribe: the feeds are gone. */
  unsubscribeSelected(): void {
    const selected = this.store.selectedSubscriptions();
    this.store.busy.set(true);
    this.error.set(null);
    this.manage.bulkUnsubscribe(selected).subscribe({
      next: (removed) => {
        this.store.busy.set(false);
        if (removed) this.store.clearSelection();
      },
      error: (e: HttpErrorResponse) => {
        this.store.busy.set(false);
        this.error.set(parseProblem(e));
        this.subs.load();
      },
    });
  }

  private runBulk(write$: Observable<void>): void {
    this.store.busy.set(true);
    this.error.set(null);
    write$.subscribe({
      next: () => this.store.busy.set(false),
      error: (e: HttpErrorResponse) => {
        this.store.busy.set(false);
        this.error.set(parseProblem(e));
        // Reload so the row that caused the 422 — a feed another tab already
        // deleted — disappears. The selection stays; the user decides.
        this.subs.load();
      },
    });
  }

  protected moveTag(group: OrganiseGroup, offset: number): void {
    const ids = this.tags.tags().map((t) => t.id);
    const from = ids.indexOf(group.tag?.id ?? -1);
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];
    this.manage.reorderTags(ids);
  }
}
```

Create `frontend/src/app/settings/organise/organise-section.component.html`:

```html
<h1 class="title"><app-icon name="rss_feed" size="lg" /> {{ 'settings.organise.title' | transloco }}</h1>
<p class="sub">
  {{
    'settings.organise.summary'
      | transloco: { feeds: subs.subscriptions().length, tags: tags.tags().length }
  }}
</p>

<div class="tools">
  <label class="selectall">
    <input
      type="checkbox"
      data-test="select-all"
      [checked]="store.allVisibleSelected()"
      (change)="store.toggleSelectAllVisible()"
    />
    {{ 'settings.organise.selectAll' | transloco }}
  </label>

  @if (store.view() === 'tree') {
    <app-button size="sm" (click)="store.expandAll()">
      <app-icon name="unfold_more" size="sm" /> {{ 'settings.organise.expandAll' | transloco }}
    </app-button>
    <app-button size="sm" (click)="store.collapseAll()">
      <app-icon name="unfold_less" size="sm" /> {{ 'settings.organise.collapseAll' | transloco }}
    </app-button>
  }

  <input
    class="filter"
    type="search"
    data-test="filter"
    [value]="store.titleFilter()"
    [attr.placeholder]="'settings.organise.filterPlaceholder' | transloco"
    [attr.aria-label]="'settings.organise.filterPlaceholder' | transloco"
    (input)="store.titleFilter.set($any($event.target).value)"
  />

  <!-- The tag filter answers what the tree cannot: "which feeds carry both
       Tech and Nachrichten?" and "which have no tag at all?" -->
  <div class="menu" [appDismissOnOutside]="tagFilterOpen()" (dismiss)="tagFilterOpen.set(false)">
    <button type="button" class="chip" data-test="tag-filter" (click)="tagFilterOpen.set(!tagFilterOpen())">
      <app-icon name="sell" size="sm" />
      {{ 'settings.organise.tagFilter' | transloco }}
      @if (store.tagFilter().size > 0) {
        <span class="n">{{ store.tagFilter().size }}</span>
      }
    </button>
    @if (tagFilterOpen()) {
      <div class="pop">
        <label>
          <input
            type="checkbox"
            [checked]="store.tagFilter().has('untagged')"
            (change)="toggleTagFilter('untagged')"
          />
          {{ 'settings.organise.untagged' | transloco }}
        </label>
        @for (t of tags.tags(); track t.id) {
          <label>
            <input
              type="checkbox"
              [checked]="store.tagFilter().has(t.id)"
              (change)="toggleTagFilter(t.id)"
            />
            {{ t.name }}
          </label>
        }
      </div>
    }
  </div>

  @if (store.view() === 'list') {
    <select
      class="sort"
      data-test="sort"
      [value]="store.sort()"
      [attr.aria-label]="'settings.organise.sort' | transloco"
      (change)="store.sort.set($any($event.target).value)"
    >
      <option value="title">{{ 'settings.organise.sortTitle' | transloco }}</option>
      <option value="added">{{ 'settings.organise.sortAdded' | transloco }}</option>
    </select>
  }

  <span class="spacer"></span>

  <div class="seg" role="group" [attr.aria-label]="'settings.organise.view' | transloco">
    <button
      type="button"
      data-test="view-tree"
      [class.on]="store.view() === 'tree'"
      [attr.aria-pressed]="store.view() === 'tree'"
      (click)="store.view.set('tree')"
    >
      {{ 'settings.organise.viewTree' | transloco }}
    </button>
    <button
      type="button"
      data-test="view-list"
      [class.on]="store.view() === 'list'"
      [attr.aria-pressed]="store.view() === 'list'"
      (click)="store.view.set('list')"
    >
      {{ 'settings.organise.viewList' | transloco }}
    </button>
  </div>

  <app-button size="sm" (click)="manage.createTag()">
    <app-icon name="add" size="sm" /> {{ 'settings.tags.new' | transloco }}
  </app-button>
  <app-button size="sm" variant="primary" data-test="add-feed" (click)="manage.addFeed().subscribe()">
    <app-icon name="add" size="sm" /> {{ 'reader.addFeed' | transloco }}
  </app-button>
</div>

@if (store.selectedCount() > 0) {
  <div class="bulk" data-test="bulk-bar">
    <span class="count" data-test="bulk-count">{{
      'settings.organise.selectedCount' | transloco: { count: store.selectedCount() }
    }}</span>
    @if (store.hiddenSelectedCount() > 0) {
      <span class="hidden" data-test="bulk-hidden">{{
        'settings.organise.hiddenCount' | transloco: { count: store.hiddenSelectedCount() }
      }}</span>
    }
    <span class="spacer"></span>
    <app-button size="sm" [disabled]="store.busy()" (click)="openTagDialog('add')">
      {{ 'settings.organise.addTag' | transloco }}
    </app-button>
    <app-button size="sm" [disabled]="store.busy()" (click)="openTagDialog('remove')">
      {{ 'settings.organise.removeTag' | transloco }}
    </app-button>
    <!-- Four commands, not two toggles: a toggle over a mixed selection has no
         correct starting position. They sit behind one menu so the bar stays
         narrow enough to hold every action on one line. -->
    <div class="menu" [appDismissOnOutside]="visibilityOpen()" (dismiss)="visibilityOpen.set(false)">
      <app-button
        size="sm"
        data-test="visibility"
        [disabled]="store.busy()"
        (click)="visibilityOpen.set(!visibilityOpen())"
      >
        {{ 'settings.organise.visibility' | transloco }}
        <app-icon name="expand_more" size="sm" />
      </app-button>
      @if (visibilityOpen()) {
        <div class="pop" role="menu">
          <button role="menuitem" (click)="setFlags({ includeInAllItems: true }); visibilityOpen.set(false)">
            {{ 'settings.organise.showInAllItems' | transloco }}
          </button>
          <button role="menuitem" (click)="setFlags({ includeInAllItems: false }); visibilityOpen.set(false)">
            {{ 'settings.organise.hideFromAllItems' | transloco }}
          </button>
          <button role="menuitem" (click)="setFlags({ includeInForYou: true }); visibilityOpen.set(false)">
            {{ 'settings.organise.showInForYou' | transloco }}
          </button>
          <button role="menuitem" (click)="setFlags({ includeInForYou: false }); visibilityOpen.set(false)">
            {{ 'settings.organise.hideFromForYou' | transloco }}
          </button>
        </div>
      }
    </div>
    <app-button
      size="sm"
      variant="danger-outline"
      data-test="bulk-unsubscribe"
      [disabled]="store.busy()"
      (click)="unsubscribeSelected()"
    >
      {{ 'reader.unsubscribe' | transloco }}
    </app-button>
    <app-button size="sm" [disabled]="store.busy()" (click)="store.clearSelection()">
      {{ 'settings.organise.clear' | transloco }}
    </app-button>
  </div>
}

@if (error(); as problem) {
  <app-error-banner class="state" [message]="problem.detail || problem.title" />
}

@if (subs.loading()) {
  <app-skeleton class="state" [label]="'settings.organise.loading' | transloco" [rows]="6" />
} @else if (store.view() === 'tree') {
  <div class="tree" cdkDropListGroup>
    @for (group of store.groups(); track group.key; let i = $index, last = $last) {
      <app-organise-tag-group
        [group]="group"
        [canMoveTagUp]="group.tag !== null && i > 0"
        [canMoveTagDown]="group.tag !== null && !last && store.groups()[i + 1].tag !== null"
        (moveTagUp)="moveTag(group, -1)"
        (moveTagDown)="moveTag(group, 1)"
      />
    }
  </div>
} @else {
  <div class="list">
    @for (s of store.listRows(); track s.id) {
      <app-organise-feed-row
        [subscription]="s"
        [selected]="store.selectedIds().has(s.id)"
        (selectedChange)="store.toggleFeed(s.id)"
        (edit)="manage.editSubscription(s)"
        (toggleAllItems)="manage.setIncludeInAllItems(s, !s.includeInAllItems)"
        (toggleForYou)="manage.setIncludeInForYou(s, !s.includeInForYou)"
        (unsubscribe)="manage.unsubscribe(s)"
      />
    }
  </div>
}
```

Create `frontend/src/app/settings/organise/organise-section.component.scss`:

```scss
// src/app/settings/organise/organise-section.component.scss
@use '../../theme/breakpoints' as bp;

:host {
  display: block;
  padding-bottom: var(--space-7);
}

.title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin: 0 0 var(--space-1);
  font-size: var(--fs-xl);
}

.sub {
  margin: 0 0 var(--space-4);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
}

.tools {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
  padding-bottom: var(--space-3);
  border-bottom: 1px solid var(--border);
}

.selectall {
  display: inline-flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}

.filter {
  height: var(--control-h);
  min-width: 12rem;
  padding: 0 var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-primary);
  font-size: var(--fs-sm);
}

.spacer {
  flex: 1;
}

.seg {
  display: inline-flex;
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  overflow: hidden;
}

.seg button {
  padding: 0 var(--space-3);
  height: var(--control-h);
  border: 0;
  background: transparent;
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  cursor: pointer;
}

.seg button.on {
  background: var(--accent-soft);
  color: var(--accent);
  font-weight: 600;
}

.chip {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  height: var(--control-h);
  padding: 0 var(--space-3);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  cursor: pointer;
}

.chip .n {
  padding: 0 var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--fs-xs);
}

.sort {
  height: var(--control-h);
  padding: 0 var(--space-2);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-1);
  color: var(--text-primary);
  font-size: var(--fs-sm);
}

.menu {
  position: relative;
}

.menu .pop {
  position: absolute;
  left: 0;
  z-index: 2;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  min-width: 14rem;
  max-height: 18rem;
  overflow-y: auto;
  padding: var(--space-2);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius);
  background: var(--surface-2);
  box-shadow: var(--panel-shadow);
}

.menu .pop label,
.menu .pop button {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-1) var(--space-2);
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--text-primary);
  font-size: var(--fs-sm);
  text-align: left;
  cursor: pointer;
}

.menu .pop label:hover,
.menu .pop button:hover {
  background: var(--surface-0);
}

/* In the flow, never floating: the toast docks bottom-centre, and a floating
   bar would both collide with it and cover the rows nearest the bottom. */
.bulk {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin: var(--space-3) 0;
  padding: var(--space-2) var(--space-3);
  border: 1px solid var(--accent);
  border-radius: var(--radius);
  background: var(--surface-2);
  box-shadow: var(--panel-shadow);
}

.bulk .count {
  font-size: var(--fs-sm);
  font-weight: 600;
}

.bulk .hidden {
  font-size: var(--fs-xs);
  color: var(--text-muted);
}

.tree {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
  margin-top: var(--space-3);
}

.list {
  margin-top: var(--space-3);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  background: var(--surface-1);
  box-shadow: var(--panel-shadow);
  overflow: hidden;
}

.list app-organise-feed-row + app-organise-feed-row {
  border-top: 1px solid var(--border);
}

@media (width <= bp.$bp-md) {
  .bulk {
    position: sticky;
    bottom: 0;
    z-index: 1;
  }
}
```

- [ ] **Step 4: Register the section and the route**

In `frontend/src/app/settings/settings-sections.ts`, add as the **first** entry of `SETTINGS_SECTIONS`:

```ts
  {
    path: 'organise',
    icon: 'rss_feed',
    labelKey: 'settings.organise.title',
    group: 'general',
    wide: true,
  },
```

In `frontend/src/app/settings/settings.routes.ts`, add as the first child after the index route:

```ts
      {
        path: 'organise',
        title: sectionLabelKey('organise'),
        loadComponent: () =>
          import('./organise/organise-section.component').then((m) => m.OrganiseSectionComponent),
      },
```

- [ ] **Step 5: Run the tests and watch them pass**

```bash
docker compose exec -T frontend npx jest src/app/settings
```

Expected: the new section spec green, and `settings-sections.spec.ts` and `settings.routes.spec.ts` still green. If either fails, it asserts a section count or an order — update the assertion to include `organise`; those specs exist to catch an entry added in one file and not the other.

- [ ] **Step 6: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/src/app/settings/
git commit -m "feat(#659): add the Organise settings section"
```

---

## Task 12: Translations

**Files:**
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`

- [ ] **Step 1: Add the keys to `en.json`**

Under `settings`, add an `organise` object, and under `manage`, a `bulk` object:

```json
"organise": {
  "title": "Organise",
  "summary": "{{feeds}} feeds in {{tags}} tags",
  "loading": "Loading your feeds…",
  "selectAll": "Select all",
  "expandAll": "Expand all",
  "collapseAll": "Collapse all",
  "filterPlaceholder": "Filter feeds…",
  "view": "View",
  "viewTree": "Tree",
  "viewList": "List",
  "tagFilter": "Tags",
  "sort": "Sort",
  "sortTitle": "By title",
  "sortAdded": "Recently added",
  "visibility": "Visibility",
  "untagged": "Untagged",
  "groupEmpty": "No feeds in this tag.",
  "feedCountOne": "{{count}} feed",
  "feedCountOther": "{{count}} feeds",
  "selectFeed": "Select {{title}}",
  "selectGroup": "Select every feed in {{name}}",
  "toggleGroup": "Show or hide {{name}}",
  "moveUp": "Move up",
  "moveDown": "Move down",
  "selectedCount": "{{count}} selected",
  "hiddenCount": "{{count}} hidden by the filter",
  "addTag": "Add tag…",
  "removeTag": "Remove tag…",
  "addTagTitle": "Add a tag to {{count}} feeds",
  "removeTagTitle": "Remove a tag from {{count}} feeds",
  "tagCountHint": "The number says how many of the selected feeds already carry the tag.",
  "noTagsToRemove": "None of the selected feeds carries a tag.",
  "addEffect": "Adds {{name}} to {{count}} feeds.",
  "removeEffect": "Removes {{name}} from {{count}} feeds.",
  "losingLastTag": "{{count}} of them lose their last tag and move to Untagged.",
  "addTagApply": "Add tag",
  "removeTagApply": "Remove tag",
  "showInAllItems": "Show in All items",
  "hideFromAllItems": "Hide from All items",
  "showInForYou": "Show in For you",
  "hideFromForYou": "Hide from For you",
  "clear": "Clear"
}
```

```json
"bulk": {
  "tagAdded": "Added {{name}} to {{count}} feeds",
  "tagRemoved": "Removed {{name}} from {{count}} feeds",
  "flagsSet": "Updated {{count}} feeds",
  "unsubscribed": "Unsubscribed from {{count}} feeds",
  "unsubscribeTitle": "Unsubscribe from {{count}} feeds?",
  "unsubscribeMessage": "{{named}}. This also removes their articles. It cannot be undone.",
  "unsubscribeMessageMore": "{{named}} and {{rest}} more. This also removes their articles. It cannot be undone."
}
```

- [ ] **Step 2: Add the same keys to `de.json`**

German, same key paths. For example: `"title": "Verwalten"`, `"viewTree": "Baum"`, `"viewList": "Liste"`, `"untagged": "Ohne Tag"`, `"addTag": "Tag hinzufügen…"`, `"removeTag": "Tag entfernen…"`, `"unsubscribeTitle": "{{count}} Feeds abbestellen?"`. Translate every key; leaving an English string in `de.json` is the same defect as a missing key.

- [ ] **Step 3: Prove both files carry the same keys**

```bash
cd frontend
node -e "const a=require('./public/i18n/en.json'),b=require('./public/i18n/de.json');const k=o=>Object.entries(o).flatMap(([x,v])=>v&&typeof v==='object'?k(v).map(s=>x+'.'+s):[x]);const A=new Set(k(a)),B=new Set(k(b));const miss=[...A].filter(x=>!B.has(x)).concat([...B].filter(x=>!A.has(x)));console.log(miss.length?miss:'in sync')"
```

Expected: `in sync`.

- [ ] **Step 4: Gates and commit**

```bash
cd frontend && npm run check
git add frontend/public/i18n/
git commit -m "feat(#659): add the Organise translations"
```

---

## Task 13: The Playwright smoke

The spec **owns its fixture**: it stubs `/api/subscriptions` and `/api/tags` with its own data, so it passes on a fresh database and leaves nothing behind. It drives the arrows and the checkboxes, not drag-and-drop — drag is slow and brittle in Playwright, and the arrows do the same job through a plain click.

**Files:**
- Create: `frontend/e2e/organise-bulk-tag.spec.ts`

- [ ] **Step 1: Write the spec**

```ts
// e2e/organise-bulk-tag.spec.ts
import { test, expect, Page } from '@playwright/test';

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL ?? 'e2e-admin@example.com';
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? 'e2e-admin-password-123';

const TAGS = [
  { id: 1, name: 'Nachrichten', color: null, icon: null, position: 0 },
  { id: 2, name: 'Tech', color: null, icon: null, position: 1 },
];

function feed(id: number, title: string, tagIds: number[]) {
  return {
    id,
    feedId: id,
    title,
    faviconUrl: null,
    customTitle: null,
    feedUrl: `https://fixtures.invalid/${id}/rss`,
    siteUrl: null,
    description: null,
    imageUrl: null,
    status: 'active',
    sourceFormat: 'xml',
    createdAt: '2026-01-01T00:00:00Z',
    lastFetchedAt: null,
    position: id,
    tags: tagIds.map((tagId, index) => ({
      id: tagId,
      name: TAGS.find((t) => t.id === tagId)!.name,
      color: null,
      icon: null,
      position: index,
    })),
    unreadCount: 0,
    includeInAllItems: true,
    includeInForYou: true,
  };
}

const SUBSCRIPTIONS = [feed(10, 'Fixture taz', [1]), feed(11, 'Fixture heise', []), feed(12, 'Fixture Golem', [])];

async function stubFixture(page: Page): Promise<void> {
  await page.route('**/api/tags', (route) =>
    route.fulfill({ json: { tags: TAGS } }),
  );
  await page.route('**/api/subscriptions', (route) =>
    route.fulfill({
      json: { subscriptions: SUBSCRIPTIONS, favoritesCount: 0, keptCount: 0, viewedCount: 0 },
    }),
  );
}

test('adds a tag to two selected feeds in one request', async ({ page }) => {
  await stubFixture(page);

  let body: unknown = null;
  await page.route('**/api/subscriptions/bulk', async (route) => {
    body = route.request().postDataJSON();
    await route.fulfill({ json: { subscriptions: [] } });
  });

  await page.goto('/login');
  await page.getByLabel(/email/i).fill(ADMIN_EMAIL);
  await page.getByLabel(/password/i).fill(ADMIN_PASSWORD);
  await page.getByRole('button', { name: /sign in|log in/i }).click();

  await page.goto('/settings/organise');
  await page.getByRole('button', { name: /expand all/i }).click();

  await page.getByLabel('Select Fixture heise').check();
  await page.getByLabel('Select Fixture Golem').check();
  await expect(page.getByTestId('bulk-count')).toContainText('2');

  await page.getByRole('button', { name: 'Add tag…' }).click();
  await page.getByRole('button', { name: /Tech/ }).click();
  await page.getByRole('button', { name: 'Add tag', exact: true }).click();

  await expect.poll(() => body).toEqual({ subscriptionIds: [11, 12], addTagIds: [2] });
});
```

- [ ] **Step 2: Run it against the Docker stack**

```bash
docker compose up -d
cd frontend && npm run e2e -- organise-bulk-tag.spec.ts
```

Expected: PASS. If the login step fails, read the selectors used by `e2e/magazine-smoke.spec.ts` and copy them — the login form is shared and its labels are the source of truth.

- [ ] **Step 3: Commit**

```bash
git add frontend/e2e/organise-bulk-tag.spec.ts
git commit -m "test(#659): add the Organise bulk-tag smoke"
```

---

## Task 14: The full gate and the manual pass

Gates green is not the deliverable. Drive the real page.

- [ ] **Step 1: Backend, both database legs**

```bash
cd backend && bin/console cache:warmup && composer check && composer md && php bin/phpunit
docker compose exec php vendor/bin/phpunit
```

- [ ] **Step 2: Mutation testing on the changed files**

```bash
cd backend && composer infection:diff
```

Expected: at or above `minMsi` in `infection.json5`. If an escaped mutant points at a real gap, add the test. Never lower `minMsi`.

- [ ] **Step 3: Frontend**

```bash
cd frontend && npm run check
```

- [ ] **Step 4: PhpStorm inspections on every changed PHP file**

Run `mcp__phpstorm__lint_files` over the files in the backend file-structure table. Block on ERROR and WARNING; weak warnings are advisory. To claim a finding is pre-existing, lint the file at its base version and show it there too.

- [ ] **Step 5: Read today's dev log**

```bash
ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200
```

Expected: no deprecation and no swallowed error from the new code paths.

- [ ] **Step 6: Apply the migrations to the running Docker database**

This branch adds no migration, so there is nothing to apply. Confirm it:

```bash
ls backend/migrations | tail -3 && git status --short backend/migrations
```

Expected: no new file.

- [ ] **Step 7: Drive the page**

With `docker compose up -d` running, open `https://localhost:8443/settings/organise` and confirm each of these by hand:

1. Every group is closed on load; the header count matches the sidebar.
2. Expand all, then Collapse all.
3. A feed with two tags shows in both groups, and ticking it in one ticks it in the other.
4. A group header checkbox goes empty → all → empty, and shows the mixed state.
5. Type a filter: matching groups open, the bulk bar names the hidden count.
6. Add a tag to several feeds; the toast names the count and **the selection stays**.
7. Remove a tag from a feed that has only that tag; it moves to Untagged, at the bottom.
8. Set each of the four visibility commands; the sidebar's `visibility_off` marker follows.
9. Reorder a feed with the arrows, then by drag; reload and confirm the order held.
10. Drag a feed from one tag to another: it **leaves** the first one.
11. Drag a feed onto Untagged: it loses the tag it came from.
12. Unsubscribe two feeds (one click), then five (type the count).
13. Switch to List: no handles, no arrows, tag pills present, the selection survived.
14. On a phone width: no drag handles, the arrows work, the bulk bar sticks to the bottom.
15. Switch the theme to dark and check every surface again.

- [ ] **Step 8: Open the pull request**

```bash
git push -u origin feature/659-organise-page
gh pr create --base develop --title "feat(#659): manage feeds and tags on one Organise page" --body "Closes #659

Spec: docs/superpowers/specs/2026-08-29-659-organise-page-design.md
Plan: docs/superpowers/plans/2026-08-29-659-organise-page.md"
```

After the merge, verify #659 actually closed rather than closing it by hand.
