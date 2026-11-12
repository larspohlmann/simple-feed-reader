# Feed directory: edit a subscribed feed, filter by a row's category — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In Settings → Feed directory, a subscribed feed's "Subscribed" opens that subscription's edit dialog (#2183), and a row's category pill sets the Category filter to just that category (#2184).

**Architecture:** The directory stops reading a one-off `GET /api/feed-directory/subscribed` snapshot. Its store derives each feed's `subscribed` flag and each row's `following` flag live from `SubscriptionsStore` (every `SubscriptionDto.feedUrl`, normalised by the existing `feedDirectoryUrl()` rule), so a subscribe, an unsubscribe or an edit anywhere flips the rows by itself. The backend endpoint and its service, model, mapper and URL rule are deleted. The row, the multi-feed popover and the action sheet then route a subscribed feed to `ManageActions.editSubscription()`, and the row's category chips become toggle buttons wired to a new `FeedDirectoryStore.focusCategory()`.

**Tech Stack:** Angular 20 (standalone, signals, CDK overlay/dialog), Transloco, Jest (in Docker), Playwright; Symfony 8.1 / PHP 8.5 for the deletions.

**Spec:** GitHub issues #2183 and #2184 (`gh issue view 2183`, `gh issue view 2184`), plus Lars's ruling of 2026-11-12: *derive the subscribed state live from the subscriptions store and delete the `/subscribed` endpoint*. The directory's own design is `docs/superpowers/specs/2026-11-11-2176-feed-directory-design.md`.

## Global Constraints

- Read `CLAUDE.md` first; its PHP Clean Code rules and frontend conventions bind every task.
- Commit messages: `type(#NN): …` with `#2183` or `#2184` as the task says. **No Claude attribution** of any kind in commits.
- Frontend tests run in Docker: `docker compose exec -T frontend npx jest <path>` from the repo root; the gate is `docker compose exec -T frontend npm run check`.
- Backend gates from `backend/`: `php bin/phpunit`, `composer check`, `composer md`.
- No hex colours, ad-hoc `px` or media-query literals in `.scss`; styles live in the sibling `.scss`.
- Comments: default to none (CLAUDE.md "Default to no comment, and mean it").
- Every user-visible string goes through Transloco, with English **and** German keys in `frontend/public/i18n/en.json` and `de.json`.
- The row height is fixed for the virtual scroll (`FEED_DIRECTORY_ROW_HEIGHT`): nothing in a row may grow taller.
- Never clear the dev database; never touch `develop` or deploy.

---

## File map

| File | Change |
|---|---|
| `frontend/src/app/settings/feed-directory/feed-directory-rows.ts` | rows built without subscription state; new `subscribedLookup()` + `withSubscriptions()`; `withSubscribed()` deleted |
| `frontend/src/app/settings/feed-directory/feed-directory.store.ts` | reads `SubscriptionsStore`; `subscriptionFor()`; `focusCategory()`; `onlyCategory`; `markSubscribed()` deleted |
| `frontend/src/app/settings/feed-directory/feed-directory-api.ts`, `feed-directory.models.ts` | `subscribed()` and `FeedDirectorySubscribed` deleted |
| `frontend/src/app/settings/feed-directory/feed-directory-subscriber.ts` | `edit(url)`; sheet and popover route subscribed feeds to it |
| `frontend/src/app/settings/feed-directory/row/*` | `edit` and `focusCategory` outputs, `focusedCategory` input, chips become buttons |
| `frontend/src/app/settings/feed-directory/feeds-popover/*` | `edit` output for subscribed entries |
| `frontend/src/app/settings/feed-directory/feed-directory-section.component.html` | wires the new outputs/inputs |
| `frontend/public/i18n/en.json`, `de.json` | three new keys |
| `frontend/e2e/feed-directory.spec.ts` | stubs the subscriptions list instead of `/subscribed`; two new smokes |
| backend `FeedDirectoryController::subscribed`, `Service/FeedDirectory/SubscribedFeeds.php`, `Service/FeedDirectory/Model/SubscribedFeedsModel.php`, `Http/SubscribedFeedsJson.php`, `Service/FeedDirectory/Support/FeedDirectoryUrl.php` + their tests | deleted |

---

### Task 1: The directory derives subscribed state from the subscriptions store (#2183)

**Files:**
- Modify: `frontend/src/app/settings/feed-directory/feed-directory-rows.ts`
- Modify: `frontend/src/app/settings/feed-directory/feed-directory.store.ts`
- Modify: `frontend/src/app/settings/feed-directory/feed-directory-api.ts`, `feed-directory.models.ts`, `feed-directory-subscriber.ts`
- Test: `feed-directory-rows.spec.ts`, `feed-directory.store.spec.ts`, `feed-directory-api.spec.ts`, `feed-directory-subscriber.spec.ts` (same folder)

**Interfaces:**
- Consumes: `SubscriptionsStore` (`frontend/src/app/reader/state/subscriptions.store.ts`): `subscriptions: Signal<SubscriptionDto[]>`, `resolved: Signal<boolean>`, `loadIfStale(): void`, `reloadQuietly()`. `SubscriptionDto.feedUrl: string`. `feedDirectoryUrl(url)`, `feedDirectoryHost(url)` from `feed-directory-url.ts`.
- Produces:
  - `prepareRows(file: FeedDirectoryCountryFile, scopeLanguages: ReadonlySet<string>): FeedDirectoryRow[]` — every feed `subscribed: false`, every row `following: false`.
  - `export interface SubscribedLookup { readonly urls: ReadonlySet<string>; readonly domains: ReadonlySet<string>; }`
  - `subscribedLookup(feedUrls: readonly string[]): SubscribedLookup`
  - `withSubscriptions(rows: readonly FeedDirectoryRow[], lookup: SubscribedLookup): FeedDirectoryRow[]`
  - `FeedDirectoryStore.subscriptionFor(feedUrl: string): SubscriptionDto | null`
  - `FeedDirectoryStore.status: Signal<'loading' | 'ready' | 'failed'>` (now a computed, still read the same way)

- [ ] **Step 1: Rewrite the rows tests for the new split**

In `feed-directory-rows.spec.ts`, drop the third `prepareRows` argument everywhere, delete the `withSubscribed` tests, and add:

```ts
describe('withSubscriptions', () => {
  const rows = (): FeedDirectoryRow[] => prepareRows(FILE, new Set(['de', 'en']));

  it('marks a feed whose normalised address is subscribed', () => {
    const marked = withSubscriptions(rows(), subscribedLookup(['http://heise.de/rss/heise.rdf/']));
    const heise = marked.find((row) => row.host === 'heise.de')!;
    expect(heise.feeds.map((feed) => feed.subscribed)).toEqual([true, false]);
  });

  it('follows a site when any subscribed feed sits on it or a subdomain', () => {
    const marked = withSubscriptions(rows(), subscribedLookup(['https://www.heise.de/other.xml']));
    expect(marked.find((row) => row.host === 'heise.de')!.following).toBe(true);
    expect(marked.find((row) => row.host === 'heise.de')!.feeds.every((feed) => !feed.subscribed)).toBe(true);
  });

  it('leaves every row unmarked without subscriptions', () => {
    const marked = withSubscriptions(rows(), subscribedLookup([]));
    expect(marked.some((row) => row.following || row.feeds.some((feed) => feed.subscribed))).toBe(false);
  });
});
```

Use the spec's existing country-file fixture under whatever name it already has (rename `FILE` above to it); it must hold a `heise.de` domain with the two feeds `https://www.heise.de/rss/heise.rdf` and `https://www.heise.de/security/rss` — add that domain to the fixture if it lacks it. Port the existing "following" cases (enclosing domains: a feed on `blog.heise.de` makes `heise.de` followed; a `www.` row host is compared bare) from the old `prepareRows(…, subscribed)` tests to `withSubscriptions`.

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-rows.spec.ts`
Expected: FAIL — `withSubscriptions` / `subscribedLookup` are not exported.

- [ ] **Step 3: Split row building from subscription marking**

In `feed-directory-rows.ts`:

```ts
export interface SubscribedLookup {
  readonly urls: ReadonlySet<string>;
  readonly domains: ReadonlySet<string>;
}

export function subscribedLookup(feedUrls: readonly string[]): SubscribedLookup {
  return {
    urls: new Set(feedUrls.map(feedDirectoryUrl)),
    domains: enclosingDomains(feedUrls.map(feedDirectoryHost)),
  };
}

export function prepareRows(
  file: FeedDirectoryCountryFile,
  scopeLanguages: ReadonlySet<string>,
): FeedDirectoryRow[] {
  return file.domains
    .filter(([, , , languageIndexes]) =>
      languageIndexes.some((index) => scopeLanguages.has(file.languages[index])),
    )
    .map((domain) => toRow(file, domain));
}

export function withSubscriptions(
  rows: readonly FeedDirectoryRow[],
  lookup: SubscribedLookup,
): FeedDirectoryRow[] {
  return rows.map((row) => ({
    ...row,
    following: lookup.domains.has(bareHost(row.host)),
    feeds: row.feeds.map((feed) => ({
      ...feed,
      subscribed: lookup.urls.has(feedDirectoryUrl(feed.url)),
    })),
  }));
}
```

`toRow` loses its `subscribed` parameter and sets `subscribed: false` / `following: false`. Delete `withSubscribed`, the private `SubscribedLookup`, and the `FeedDirectorySubscribed` import. `byMode` stays computed in `toRow` (it does not depend on subscription state). An empty `feedDirectoryHost` (unparsable URL) adds `''` to the domains set harmlessly; leave it.

- [ ] **Step 4: Run the rows tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-rows.spec.ts`
Expected: PASS.

- [ ] **Step 5: Rewrite the store tests**

In `feed-directory.store.spec.ts`:
- Delete `SUBSCRIBED` and the `FeedDirectorySubscribed` import; the `FeedDirectoryApi` double loses `subscribed`.
- Provide a `SubscriptionsStore` double:

```ts
const subscriptions = signal<Partial<SubscriptionDto>[]>([
  { id: 7, feedUrl: 'https://www.heise.de/rss/heise.rdf' },
]);
const resolved = signal(true);
const loadIfStale = jest.fn();
// in providers:
{ provide: SubscriptionsStore, useValue: { subscriptions, resolved, loadIfStale } },
```

(reset all three in `beforeEach`; cast the double's array with `as SubscriptionDto[]` where the type demands it.)

Replace the tests that asserted the snapshot / `markSubscribed` with:

```ts
it('marks the feeds the subscriptions store holds', () => {
  loadedStore();
  const heise = store.rows().find((row) => row.host === 'heise.de')!;
  expect(heise.feeds.map((feed) => feed.subscribed)).toEqual([true, false]);
  expect(heise.following).toBe(true);
});

it('flips a row back when its subscription goes away', () => {
  loadedStore();
  subscriptions.set([]);
  const heise = store.rows().find((row) => row.host === 'heise.de')!;
  expect(heise.feeds.some((feed) => feed.subscribed)).toBe(false);
  expect(heise.following).toBe(false);
});

it('asks the subscriptions store to load when it is stale', () => {
  loadedStore();
  expect(loadIfStale).toHaveBeenCalled();
});

it('stays loading until the subscriptions store has answered', () => {
  resolved.set(false);
  loadedStore();
  expect(store.status()).toBe('loading');
  resolved.set(true);
  expect(store.status()).toBe('ready');
});

it('finds the subscription behind a directory feed by its normalised address', () => {
  expect(store.subscriptionFor('http://heise.de/rss/heise.rdf/')?.id).toBe(7);
  expect(store.subscriptionFor('https://heise.de/security/rss')).toBeNull();
});
```

Keep every other existing store test; where one compared against `prepareRows(DE, …, SUBSCRIBED)`, drop the third argument.

- [ ] **Step 6: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory.store.spec.ts`
Expected: FAIL (compile errors on `subscriptionFor`, then on behaviour).

- [ ] **Step 7: Derive in the store**

In `feed-directory.store.ts`:

```ts
private readonly subscriptionStore = inject(SubscriptionsStore);

private readonly fileStatus = signal<'loading' | 'ready' | 'failed'>('loading');
readonly status = computed(() =>
  this.fileStatus() === 'ready' && !this.subscriptionStore.resolved() ? 'loading' : this.fileStatus(),
);

private readonly subscriptionsByUrl = computed(
  () =>
    new Map(
      this.subscriptionStore
        .subscriptions()
        .map((subscription) => [feedDirectoryUrl(subscription.feedUrl), subscription] as const),
    ),
);
private readonly marked = computed(() =>
  withSubscriptions(this.loaded(), subscribedLookup(
    this.subscriptionStore.subscriptions().map((subscription) => subscription.feedUrl),
  )),
);

subscriptionFor(feedUrl: string): SubscriptionDto | null {
  return this.subscriptionsByUrl().get(feedDirectoryUrl(feedUrl)) ?? null;
}
```

- `rows` filters `this.marked()` instead of `this.loaded()`. `countryCounts`, `total` and `categoryOptions` keep reading `this.loaded()` (they never look at subscription state, so they must not recompute when it changes).
- Every `this.status.set(…)` becomes `this.fileStatus.set(…)`.
- `load()`: call `this.subscriptionStore.loadIfStale();` first; `forkJoin({ index: this.api.index() })` loses `subscribed` — simplify to `this.api.index().pipe(switchMap((index) => this.countryFiles(knownCountries(scope, index)).pipe(map((files) => ({ index, files })))))`; `prepareRows(file, scopeLanguages)`.
- Delete `markSubscribed()`.

In `feed-directory-api.ts` delete `subscribed()` (and its test in `feed-directory-api.spec.ts`); in `feed-directory.models.ts` delete `FeedDirectorySubscribed`. In `feed-directory-subscriber.ts`, `subscribeTo` keeps `this.subscriptions.reloadQuietly()` and drops `this.store.markSubscribed(host, url)` (and the now-unused `host` parameter if nothing else reads it — `openSheet` and `openPopover` still pass `host` for their titles; keep only what is read). Update `feed-directory-subscriber.spec.ts`: the assertion on `markSubscribed` becomes an assertion that `reloadQuietly` ran.

Grep for leftovers: `grep -rn "markSubscribed\|FeedDirectorySubscribed\|withSubscribed\|feed-directory/subscribed" frontend/src` must print nothing.

- [ ] **Step 8: Run the folder's tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add frontend/src/app/settings/feed-directory
git commit -m "refactor(#2183): the feed directory reads what you subscribe to from the subscriptions store"
```

---

### Task 2: Delete the `/api/feed-directory/subscribed` endpoint (#2183)

**Files:**
- Modify: `backend/src/Controller/Api/FeedDirectoryController.php` (drop the `subscribed` action, the `SubscribedFeeds` constructor parameter and unused imports)
- Delete: `backend/src/Service/FeedDirectory/SubscribedFeeds.php`, `backend/src/Service/FeedDirectory/Model/SubscribedFeedsModel.php`, `backend/src/Http/SubscribedFeedsJson.php`, `backend/src/Service/FeedDirectory/Support/FeedDirectoryUrl.php`
- Delete: `backend/tests/Service/FeedDirectory/SubscribedFeedsTest.php`, `backend/tests/Service/FeedDirectory/Support/FeedDirectoryUrlTest.php`
- Modify: `backend/tests/Controller/Api/FeedDirectoryControllerTest.php` (delete the `/subscribed` tests; keep index/country)
- Modify: `frontend/src/app/settings/feed-directory/feed-directory-url.ts` line 1 comment

**Interfaces:**
- Consumes: Task 1 removed the only client.
- Produces: nothing.

- [ ] **Step 1: Delete**

Delete the files above and the controller action. `FeedRepository::subscribedUrlSetForUser()` **stays** — `CatalogController` still uses it. If `Service/FeedDirectory/Model/` or `Support/` is left empty, remove the empty folder.

The frontend `feed-directory-url.ts` doc comment names the backend rule; replace it with:

```ts
/** How the directory matches a subscription to its feeds; feed-directory-url-cases.json holds the cases. */
```

`feed-directory-url-cases.json` stays as the frontend spec's fixture.

- [ ] **Step 2: Prove nothing refers to the deleted code**

Run from the repo root:
`grep -rn "SubscribedFeeds\b\|SubscribedFeedsModel\|SubscribedFeedsJson\|FeedDirectoryUrl\b\|api_feed_directory_subscribed" backend/src backend/tests backend/config docs/architecture.md`
Expected: no output.

- [ ] **Step 3: Run the backend gates**

From `backend/`: `bin/console cache:warmup && php bin/phpunit tests/Controller/Api/FeedDirectoryControllerTest.php && composer check && composer md`
Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add -A backend frontend/src/app/settings/feed-directory/feed-directory-url.ts
git commit -m "refactor(#2183): drop the feed directory's subscribed endpoint, now the browser derives it"
```

---

### Task 3: A subscribed feed opens its edit dialog (#2183)

**Files:**
- Modify: `frontend/src/app/settings/feed-directory/row/feed-directory-row.component.{ts,html,scss}` + spec
- Modify: `frontend/src/app/settings/feed-directory/feeds-popover/feed-directory-feeds-popover.component.{ts,html,scss}` + spec
- Modify: `frontend/src/app/settings/feed-directory/feed-directory-subscriber.ts` + spec
- Modify: `frontend/src/app/settings/feed-directory/feed-directory-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `de.json`

**Interfaces:**
- Consumes: `FeedDirectoryStore.subscriptionFor(feedUrl): SubscriptionDto | null` (Task 1); `ManageActions.editSubscription(subscription: SubscriptionDto): void` (`frontend/src/app/reader/feeds/manage/manage-actions.service.ts`, `providedIn: 'root'`).
- Produces: `FeedDirectoryRowComponent.edit = output<string>()` (the feed URL); `FeedDirectoryFeedsPopoverComponent.edit = output<string>()`; `FeedDirectorySubscriber.edit(feedUrl: string): void`.

i18n keys, under `settings.feedDirectory`:

| key | en | de |
|---|---|---|
| `editSubscription` | `Edit subscription` | `Abonnement bearbeiten` |
| `editSubscriptionTo` | `Edit subscription to {{feed}}` | `Abonnement von {{feed}} bearbeiten` |

- [ ] **Step 1: Row test**

Replace the row spec's "marks the one feed in the mode as subscribed instead of offering it" test with:

```ts
it('opens the subscribed feed for editing from its Subscribed status', () => {
  const url = 'https://www.tagesschau.de/podcast.xml';
  const element = render(
    { ...ROW, feeds: [feed(url, { podcast: true, subscribed: true })] },
    'podcasts',
  );
  const edits: string[] = [];
  fixture.componentInstance.edit.subscribe((edited) => edits.push(edited));
  expect(element.querySelector('[data-test="subscribe"]')).toBeNull();
  const status = element.querySelector<HTMLElement>('[data-test="edit"] button')!;
  expect(text(status)).toContain('Subscribed');
  expect(status.getAttribute('aria-label')).toBe('Edit subscription');
  status.click();
  expect(edits).toEqual([url]);
});
```

- [ ] **Step 2: Popover test**

Replace "marks a subscribed feed instead of offering it" with:

```ts
it('opens a subscribed feed for editing instead of offering it', () => {
  const edited: string[] = [];
  fixture.componentInstance.edit.subscribe((url) => edited.push(url));
  const subscribed = element().querySelectorAll('.feed')[0];
  const button = subscribed.querySelector<HTMLButtonElement>('[data-test="edit"] button')!;
  expect(text(button)).toContain('Subscribed');
  expect(button.getAttribute('aria-label')).toBe('Edit subscription to /kultur/index.rss');
  button.click();
  expect(edited).toEqual(['https://www.spiegel.de/kultur/index.rss']);
});
```

The existing "emits the chosen feed address" and "names each Subscribe button after its feed" tests select `.feed app-button button` by index; give the Subscribe buttons `data-test="subscribe"` and change those selectors to `.feed [data-test="subscribe"] button` so the new Edit buttons do not shift the indexes. The initial focus (`afterNextRender`) keeps focusing the first `.feed button`, which may now be an Edit button — that is fine.

- [ ] **Step 3: Subscriber tests**

In `feed-directory-subscriber.spec.ts` provide `ManageActions` as `{ editSubscription: jest.fn() }` and a store double whose `subscriptionFor` returns `{ id: 7 }` for the subscribed URL and `null` otherwise. Add:

```ts
it('opens the edit dialog for a subscribed feed', () => {
  subscriber.edit(SUBSCRIBED_URL);
  expect(manage.editSubscription).toHaveBeenCalledWith(expect.objectContaining({ id: 7 }));
});

it('does nothing for a feed it holds no subscription for', () => {
  subscriber.edit('https://nowhere.example/feed');
  expect(manage.editSubscription).not.toHaveBeenCalled();
});

it('offers a subscribed feed in the sheet and edits it when chosen', () => {
  // coarse pointer, a row with one subscribed and one unsubscribed feed
  // assert the subscribed action is not disabled, has symbol 'check',
  // and choosing its id calls manage.editSubscription, not the add-feed dialog
});

it('closes the popover and edits a subscribed feed chosen there', () => {
  // fine pointer; emit the popover instance's `edit` with the subscribed url
  // assert the overlay detached and manage.editSubscription was called
});
```

Write the last two using the spec's existing sheet and popover test helpers (the file already drives `openSheet` via a coarse `LayoutService` double and the popover via its `choose` output — mirror those tests exactly, swapping `choose` for `edit` and the add-feed `Dialog.open` assertion for `editSubscription`).

- [ ] **Step 4: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: FAIL on the new tests.

- [ ] **Step 5: Implement**

Row (`feed-directory-row.component.ts`): `readonly edit = output<string>();`. Template, replacing the `<span class="done">` branch:

```html
@if (feed.subscribed) {
  <app-button
    size="sm"
    variant="ghost"
    data-test="edit"
    [ariaLabel]="'settings.feedDirectory.editSubscription' | transloco"
    (click)="edit.emit(feed.url)"
  >
    <app-icon name="check" size="sm" />{{ 'settings.feedDirectory.subscribed' | transloco }}
  </app-button>
}
```

Remove the row's now-unused `.done` rule from its `.scss`.

Popover: `readonly edit = output<string>();`; the subscribed branch becomes

```html
<app-button
  size="sm"
  variant="ghost"
  data-test="edit"
  [ariaLabel]="'settings.feedDirectory.editSubscriptionTo' | transloco: { feed: feed.path }"
  (click)="edit.emit(feed.url)"
>
  <app-icon name="check" size="sm" />{{ 'settings.feedDirectory.subscribed' | transloco }}
</app-button>
```

and the Subscribe `app-button` gets `data-test="subscribe"`. Remove the popover's unused `.done` rule.

Subscriber:

```ts
private readonly manage = inject(ManageActions);

edit(feedUrl: string): void {
  const subscription = this.store.subscriptionFor(feedUrl);
  if (subscription) this.manage.editSubscription(subscription);
}
```

- `open()`: a single feed that is subscribed never reaches `open()` (the row emits `edit` for it), so `open()` is unchanged.
- `openSheet`: subscribed actions drop `disabled`; the choice handler routes by the chosen feed:

```ts
const byUrl = new Map(feeds.map((feed) => [feed.url, feed]));
this.sheet.open({ title: `${host} · ${total}`, actions }).subscribe((url) => {
  if (!url) return;
  if (byUrl.get(url)?.subscribed) this.edit(url);
  else this.subscribeTo(url);
});
```

- `openPopover`: subscribe to `outputToObservable(content.instance.edit).pipe(takeUntil(closed))` exactly like `choose`, calling `closeByUser(); this.edit(url);`.

Section template: `<app-feed-directory-row … (edit)="subscriber.edit($event)" />`.

Add the two i18n keys to both dictionaries next to `subscribed`.

- [ ] **Step 6: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings/feed-directory frontend/public/i18n
git commit -m "feat(#2183): a subscribed feed in the directory opens its edit dialog"
```

---

### Task 4: A row's category pill filters by that category (#2184)

**Files:**
- Modify: `frontend/src/app/settings/feed-directory/feed-directory.store.ts` + spec
- Modify: `frontend/src/app/settings/feed-directory/row/feed-directory-row.component.{ts,html,scss}` + spec
- Modify: `frontend/src/app/settings/feed-directory/feed-directory-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `de.json`

**Interfaces:**
- Consumes: `FeedDirectoryStore.categories` (a `linkedSignal<ReadonlySet<string>>`).
- Produces: `FeedDirectoryStore.focusCategory(name: string): void`; `FeedDirectoryStore.onlyCategory: Signal<string | null>`; `FeedDirectoryRowComponent.focusedCategory = input<string | null>(null)`; `FeedDirectoryRowComponent.focusCategory = output<string>()`.

i18n key under `settings.feedDirectory`: `showOnlyCategory` — en `Show only {{category}}`, de `Nur {{category}} zeigen`.

- [ ] **Step 1: Store tests**

```ts
it('narrows the category filter to the one category a pill names', () => {
  loadedStore();
  store.toggleCategory('Sports');
  store.focusCategory('Politics & Society');
  expect([...store.categories()]).toEqual(['Politics & Society']);
  expect(store.onlyCategory()).toBe('Politics & Society');
});

it('clears the category filter when the pill of the only chosen category is clicked again', () => {
  loadedStore();
  store.focusCategory('Sports');
  store.focusCategory('Sports');
  expect(store.categories().size).toBe(0);
  expect(store.onlyCategory()).toBeNull();
});

it('leaves the other filters alone', () => {
  loadedStore();
  store.query.set('heise');
  store.sort.set('az');
  store.focusCategory('Sports');
  expect(store.query()).toBe('heise');
  expect(store.sort()).toBe('az');
  expect(store.mode()).toBe('channels');
});

it('has no single category while two are chosen', () => {
  loadedStore();
  store.toggleCategory('Sports');
  store.toggleCategory('Politics & Society');
  expect(store.onlyCategory()).toBeNull();
});
```

- [ ] **Step 2: Row tests**

Replace the row spec's chip assertion in the first test (`.chip` text list) so it still reads the chip texts (now buttons), and add:

```ts
it('asks to filter by a category from its pill', () => {
  const element = render(ROW);
  const chosen: string[] = [];
  fixture.componentInstance.focusCategory.subscribe((category) => chosen.push(category));
  element.querySelectorAll<HTMLButtonElement>('button.chip')[1].click();
  expect(chosen).toEqual(['Science & Technology']);
});

it('presses the pill of the only category the filter shows', () => {
  const element = render(ROW);
  fixture.componentRef.setInput('focusedCategory', 'Science & Technology');
  fixture.detectChanges();
  const pills = element.querySelectorAll<HTMLButtonElement>('button.chip');
  expect(pills[0].getAttribute('aria-pressed')).toBe('false');
  expect(pills[1].getAttribute('aria-pressed')).toBe('true');
  expect(pills[1].getAttribute('title')).toBe('Show only Science & Technology');
});
```

- [ ] **Step 3: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory.store.spec.ts src/app/settings/feed-directory/row`
Expected: FAIL.

- [ ] **Step 4: Implement**

Store:

```ts
readonly onlyCategory = computed(() => {
  const picked = this.categories();
  return picked.size === 1 ? [...picked][0] : null;
});

focusCategory(name: string): void {
  this.categories.set(this.onlyCategory() === name ? new Set() : new Set([name]));
}
```

Row: `readonly focusedCategory = input<string | null>(null);` and `readonly focusCategory = output<string>();`. Template:

```html
<div class="chips">
  @for (category of categories(); track $index) {
    <button
      type="button"
      class="chip"
      [attr.aria-pressed]="category === focusedCategory()"
      [title]="'settings.feedDirectory.showOnlyCategory' | transloco: { category }"
      (click)="focusCategory.emit(category)"
    >
      {{ category }}
    </button>
  }
</div>
```

`.scss`: the `.chip` rule gains a button reset that keeps the pill's exact box (`font: inherit; font-size: var(--fs-xs); line-height: inherit; cursor: pointer;` and the same padding/radius/border as now), a `@media (hover: hover) { &:hover { color: var(--text-primary); } }` state, a `&:focus-visible` ring using the project's focus-ring token or mixin (grep `focus-visible` in `src/app/shared/button/button.component.scss` and reuse what it uses), and `&[aria-pressed='true']` using the accent tokens the filter pill uses for a selected option (grep `selected` in `filter-pill/*.scss` and reuse those tokens). The chip must not grow taller: compare `getBoundingClientRect().height` of a chip before and after in the live check (Task 5).

Section template: `<app-feed-directory-row … [focusedCategory]="store.onlyCategory()" (focusCategory)="store.focusCategory($event)" />`.

Add the i18n key to both dictionaries.

- [ ] **Step 5: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/settings/feed-directory frontend/public/i18n
git commit -m "feat(#2184): a row's category pill filters the directory by that category"
```

---

### Task 5: Smokes, gate and live check (#2183, #2184)

**Files:**
- Modify: `frontend/e2e/feed-directory.spec.ts`

**Interfaces:**
- Consumes: everything above; `SubscriptionsResponse` / `SubscriptionDto` from `frontend/src/app/reader/models.ts`.

- [ ] **Step 1: Stub the subscriptions list instead of `/subscribed`**

In `stubFeedDirectory`, delete the `**/api/feed-directory/subscribed` route. The existing `(url) => url.pathname === '/api/subscriptions'` route falls back for GET; change its GET branch to fetch the real response and replace its `subscriptions` with the spec's own list, so the spec owns what it asserts on:

```ts
if (route.request().method() === 'GET') {
  const response = await route.fetch();
  const real = (await response.json()) as SubscriptionsResponse;
  return route.fulfill({ json: { ...real, subscriptions: [SUBSCRIBED_ALPHA] } });
}
```

`SUBSCRIBED_ALPHA` is a full `SubscriptionDto` literal typed `const SUBSCRIBED_ALPHA: SubscriptionDto = { … }` (tsc then forces every field) whose `feedUrl` is one of the fixture country file's feeds that the existing tests do **not** subscribe to, and whose `id` is an unused number such as `990001`. Read `SubscriptionDto` in `models.ts` for the fields; use neutral values (`unreadCount: 0`, `tags: []`, `null` where allowed). Check that no existing test in the file depends on that feed being unsubscribed; pick another fixture feed if one does.

- [ ] **Step 2: Add two smokes**

```ts
test('a subscribed feed opens its edit dialog', async ({ page }) => {
  await stubFeedDirectory(page);
  // open the directory the way the existing tests do (reuse their helper/steps)
  await page.getByRole('button', { name: 'Edit subscription' }).first().click();
  await expect(page.getByRole('dialog')).toBeVisible();
  // close with the dialog's Cancel; nothing is written
});

test("a row's category pill narrows the filter to that category", async ({ page }) => {
  await stubFeedDirectory(page);
  // open the directory as above, count the rows, click a pill whose category not every row has
  // expect fewer rows, every visible row carrying that pill, and the pill aria-pressed="true"
  // click the same pill again; expect the original row count back
});
```

Fill in the navigation from the existing tests in the file (copy their exact steps). If the edit dialog's PATCH/DELETE could be reached, stub them to fail (the spec must never write to the real account) — Cancel alone does not write.

- [ ] **Step 3: Run the smoke**

Run from `frontend/`: `npx playwright test e2e/feed-directory.spec.ts` (Docker stack up).
Expected: all pass.

- [ ] **Step 4: Full frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: PASS.

- [ ] **Step 5: Live check on :4200** (controller does this, not a subagent)

On `http://localhost:4200/settings/feed-directory` (dev account, scope DE/de), at desktop width and 375px:
1. A single-feed row you subscribe to shows "Subscribed"; clicking it opens the edit dialog. Unsubscribe there → the row flips to "Subscribe" without a reload.
2. A multi-feed row: the popover (desktop) and the action sheet (375px) list the subscribed feed with a check; choosing it opens the edit dialog.
3. Subscribe to a feed from the directory → its row shows "Subscribed" after the dialog closes.
4. A category pill narrows the list to that category, shows pressed, and the Category filter pill shows it selected; a second click clears it; mode/search/sort stay.
5. Pill height unchanged (row height fixed).

Re-subscribe anything the live check unsubscribed, so the dev account ends as it started.

- [ ] **Step 6: Commit**

```bash
git add frontend/e2e/feed-directory.spec.ts
git commit -m "test(#2183): the directory smokes own the subscriptions they assert on and cover edit and the category pill"
```
