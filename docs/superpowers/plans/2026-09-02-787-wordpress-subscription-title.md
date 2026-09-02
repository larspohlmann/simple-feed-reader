# WordPress Subscription Title Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a user selects a WordPress REST candidate, store the candidate's site title on a newly created shared feed instead of showing the full REST URL as its name.

**Architecture:** Carry an optional `title` field through the existing subscribe HTTP request. The add-feed dialog sends it only for a selected `wp-json` candidate. The backend validates it at the `Feed.title` storage limit and passes it only through the WordPress verbatim path. `SubscriptionCreator` applies it inside the new-feed branch, so no existing shared feed is changed.

**Tech Stack:** Angular 20 with standalone components and signals, Symfony 7.4, PHP 8.4, Doctrine ORM, Jest, PHPUnit.

**Spec:** GitHub issue #787: <https://github.com/larspohlmann/simple-feed-reader/issues/787>

## Global Constraints

- Work on branch `fix/787-wordpress-subscription-title`, based on `develop`. Commit subjects use `type(#787): lower-case summary`.
- The subscribe request field is named `title`. It is optional and nullable.
- Only a selected `wp-json` candidate sends and uses `title`. Direct URL, XML, unknown-format, and scraped subscriptions keep their current behavior.
- The backend accepts at most 512 characters, matching `Feed.title`.
- Apply the candidate title only when `SubscriptionCreator` creates a new `Feed` row.
- Never fill or replace the title on an existing shared `Feed`, including an existing row whose title is `null`.
- Keep the candidate URL, source format, tags, discovery behavior, scrape permission check, duplicate check, and response shape unchanged.
- Add behavior tests at the dialog, HTTP client, request-validation, controller, and service boundaries. Each test must name the production break that it catches.
- Follow strict TDD. Record the initial expected failures and the first green runs. After the first green run, temporarily remove the production title propagation, prove that the focused tests fail, restore it, and rerun them green.
- Run PHP checks from `backend/` and frontend checks from `frontend/`. Do not use the main checkout's Docker mounts for this worktree.

## Shared Interface

| Producer | Contract | Consumer |
|---|---|---|
| Add-feed dialog / `ReaderApi` | `POST /api/subscriptions` can include `title: string` with a `wp-json` candidate | `SubscribeRequest` / `SubscriptionController` |
| `SubscriptionService` WordPress path | Passes an optional initial feed title with the verbatim source | `SubscriptionCreator` new-feed branch |

---

### Task 1: Send the selected WordPress candidate title

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts`
- Modify: `frontend/src/app/reader/reader-api.spec.ts`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.ts`
- Modify: `frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts`

**Interfaces:**
- Preserve the current `ReaderApi.subscribe(url, format?, tagIds?)` call behavior. Add an optional final `title` argument so existing callers need no change.
- Add `title` to the request body only when it has a non-empty value.
- In `AddFeedDialogComponent.pick`, pass the candidate title only when `candidate.format === 'wp-json'`. Keep the scraped candidate request free of `title`.

- [ ] **Step 1: Write failing frontend tests**

Update the existing WordPress dialog test so its literal subscribe body includes `title: 'WP'`. Keep the existing scraped candidate test unchanged; its literal body without `title` is the regression guard for the scraped path.

Add a focused `ReaderApi` test that calls the new optional title position and expects this literal request body:

```ts
{
  url: 'https://wp.example/wp-json/wp/v2/posts',
  format: 'wp-json',
  title: 'WordPress Example',
}
```

The production changes that must make these tests fail are: the dialog stops forwarding the selected candidate title, or `ReaderApi` stops serializing the optional title.

- [ ] **Step 2: Run the focused tests and record RED**

Run:

```bash
npm test -- --runInBand src/app/reader/reader-api.spec.ts src/app/reader/add-feed/add-feed-dialog.component.spec.ts
```

Expected: the new literal title assertions fail because the production request body does not yet contain `title`.

- [ ] **Step 3: Implement the smallest frontend change**

Extend `ReaderApi.subscribe` with an optional final `title?: string` parameter and an optional `title` request-body member. Serialize it only when non-empty.

Extend the dialog's private subscribe helper with the optional title, pass it to `ReaderApi`, and make `pick` supply `c.title` only for `wp-json`. Do not send the title during the first discovery request or for scraped candidates.

- [ ] **Step 4: Run GREEN and the deletion proof**

Run the focused command from Step 2. It must pass. Then temporarily remove either the dialog forwarding line or the API body assignment, rerun the focused tests, and confirm the relevant test fails. Restore the production line and rerun the focused tests green.

- [ ] **Step 5: Run frontend quality checks**

Run:

```bash
npm run check
```

Expected: PASS with no new warnings.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-api.ts frontend/src/app/reader/reader-api.spec.ts frontend/src/app/reader/add-feed/add-feed-dialog.component.ts frontend/src/app/reader/add-feed/add-feed-dialog.component.spec.ts
git commit -m "fix(#787): send the WordPress candidate title"
```

---

### Task 2: Seed new WordPress feeds from the candidate title

**Files:**
- Modify: `backend/src/Dto/Subscription/SubscribeRequest.php`
- Modify: `backend/src/Controller/Api/SubscriptionController.php`
- Modify: `backend/src/Service/Subscription/SubscriptionService.php`
- Modify: `backend/src/Service/Subscription/SubscriptionCreator.php`
- Modify: `backend/tests/Dto/Subscription/SubscribeRequestTest.php`
- Modify: `backend/tests/Controller/Api/SubscriptionControllerTest.php`
- Modify: `backend/tests/Service/Subscription/SubscriptionServiceTest.php`

**Interfaces:**
- Add `SubscribeRequest::$title` as `?string` with `Assert\Length(max: 512)`. Add it as the final constructor argument to preserve existing positional calls.
- Pass the title from `SubscriptionController` as the final optional `SubscriptionService::subscribe` argument.
- `SubscriptionService` passes that title only through its `SourceFormat::WP_JSON` branch. Scraped and discovery-backed paths pass no initial title.
- Add an optional final `?string $initialTitle = null` argument to `SubscriptionCreator::create`. Call `Feed::setTitle($initialTitle)` only inside the `null === $feed` creation branch.

- [ ] **Step 1: Write failing backend tests**

In `SubscribeRequestTest`, add a boundary test that proves 512 characters are valid and 513 characters cause a validation violation. Use named constructor arguments for `title`.

In `SubscriptionServiceTest`:

- Extend the WordPress verbatim test to pass `WordPress Example` and assert the created feed has that exact title.
- Add a test that pre-creates a shared feed with the same WordPress URL and a `null` title, subscribes a different user with a candidate title, and asserts the existing feed title stays `null`.
- Add or extend a scraped test to pass an initial title and assert a newly created scraped feed title stays `null`.

In `SubscriptionControllerTest`, add a real POST test for a `wp-json` body with `title`. Install an empty `StubFeedFetcher` so any network fetch fails. Assert status 201, zero fetched URLs, the response title, stored source format, and stored feed title.

The production changes that must make these tests fail are: the controller drops `title`, the WordPress service branch drops it, the creator does not seed new feeds, the creator changes existing rows, the scraped branch uses it, or validation permits 513 characters.

- [ ] **Step 2: Run the focused tests and record RED**

Run:

```bash
php bin/phpunit tests/Dto/Subscription/SubscribeRequestTest.php tests/Service/Subscription/SubscriptionServiceTest.php tests/Controller/Api/SubscriptionControllerTest.php
```

Expected: the new title assertions and 513-character validation assertion fail before implementation.

- [ ] **Step 3: Implement the backend propagation**

Add the optional validated DTO field. Carry it through the controller and the WordPress branch only. In `SubscriptionCreator`, set the initial title in the same new-feed block that sets the source format. Keep the existing-feed branch free of title writes.

Use the name `initialTitle` in the creator so its creation-only meaning is clear. Update short PHPDoc comments where the old text says that metadata always waits for refresh.

- [ ] **Step 4: Run GREEN and the deletion proof**

Run the focused command from Step 2. It must pass. Then temporarily remove `Feed::setTitle($initialTitle)` from the new-feed branch and rerun the focused tests. Confirm that the WordPress title tests fail. Restore the line and rerun the focused tests green.

- [ ] **Step 5: Run backend quality checks**

Run:

```bash
php bin/phpunit
composer check
vendor/bin/phpmd src/Dto/Subscription/SubscribeRequest.php,src/Controller/Api/SubscriptionController.php,src/Service/Subscription/SubscriptionService.php,src/Service/Subscription/SubscriptionCreator.php text phpmd.xml
```

Expected: PASS. Existing PHPUnit notices may remain, but no new notice or warning is acceptable.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Dto/Subscription/SubscribeRequest.php backend/src/Controller/Api/SubscriptionController.php backend/src/Service/Subscription/SubscriptionService.php backend/src/Service/Subscription/SubscriptionCreator.php backend/tests/Dto/Subscription/SubscribeRequestTest.php backend/tests/Controller/Api/SubscriptionControllerTest.php backend/tests/Service/Subscription/SubscriptionServiceTest.php
git commit -m "fix(#787): seed new WordPress feed titles"
```

---

## Final Verification

- [ ] Run `git diff --check origin/develop...HEAD`.
- [ ] Run `npm run check` from `frontend/`.
- [ ] Run `php bin/phpunit` and `composer check` from `backend/`.
- [ ] Run PHPMD on each touched backend source file.
- [ ] Stage or commit all source and test files, then run `composer infection:diff` from `backend/`.
- [ ] Review the full branch against issue #787. Confirm the request remains backward-compatible and no existing shared feed is changed.
