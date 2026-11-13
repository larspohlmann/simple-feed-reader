# Inclusion Checkboxes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every row menu and action sheet that switches a feed, a network or a tag in or out of All items, For You and saved searches offers all three as checkboxes (checked = included) that toggle in place and keep the menu open. Networks gain an All items switch; tags gain all three, stored on the tag.

**Architecture:**
- *Backend.* The network switches For You and All items share one entity, `NetworkExclusion(user, network, surface)` with `NetworkExclusionSurface::{AllItems, ForYou}`. It replaces `ForYouNetworkExclusion`, and its migration moves the existing rows. Saved searches keep `SavedSearchNetworkInclusion` because their default is the opposite. `Tag` gets three boolean columns (default true), set through the existing `PATCH /api/tags/{id}` and returned by `TagJson`. Each surface's scope predicate is the one place that decides it, and each gains "no tag the subscription carries, nor that tag's parent, has the surface off" (`TagExclusionDql`). The All items predicate also gains "its network is not excluded from All items".
- *Frontend.* One shared `MenuCheckboxComponent`. The action sheet gets an `ActionSheetCheck` item, with `checked: Signal<boolean>` and `toggle()`, that does not close the sheet. The feed, network, tag and Organise menus switch to it.

**Tech Stack:** Symfony 8.1 / Doctrine (SQLite + MySQL), Angular 20 standalone + signals, PHPUnit, Jest.

**Spec:** GitHub issue #2202, plus the rulings below.

## Rulings (from the 2026-11-13 conversation)

- Network → All items: a stored network switch. Included by default; a row excludes it, and that covers feeds added later.
- Tags: a stored tag switch. Included by default. It overrides the per-feed flag and covers feeds added later.
- **Exclusion wins** (my default, flag in review): a feed is in surface S only if its own flag, its network rule and **every** tag it carries (plus each tag's parent, since nesting is one level deep, #1736) all let it in. A feed in tags A (off) and B (on) is out.
- A parent tag switched off covers its subtags' feeds. A subtag switched off covers only its own feeds.

## Global Constraints

- CLAUDE.md applies in full: Clean Code, queries only in `src/Repository`, thin controllers, `final readonly`, PHPMD-clean touched files, `composer check` + `composer md` + `npm run check` green.
- Migrations must work on SQLite and MySQL. CI migrates from empty and validates the schema. Apply the new migration to the live Docker DB.
- Bearer/JSON only. Keep `GET|PUT|DELETE /api/for-you/excluded-networks[/{network}]` byte-compatible (iOS client).
- Naive-UTC and `TEST_TOKEN` rules unchanged. Commit format `type(#2202): …`.
- `shared/` knows no feature i18n keys.

## Review Focus

- Mark-all-read on All items (`MarkReadService` → `findIncludedInAllItemsForUser`) must leave feeds excluded by tag or network unread, the same as the list hides them (Task 3 test).
- The For You candidate pool, its duplicate collapse, the saved-search matches **and the digest** must all honour a tag switch: the predicates are shared, so prove each caller with one integration test (Tasks 3–4).
- A feed that carries no tags must stay in. The predicate is `NOT EXISTS`, never an inner join (Task 3 test).
- Two quick ticks in a **sheet** must flip from the live store value, not the snapshot the sheet opened with (Task 8 test).
- On Visibility, a feed whose own box is ticked but which a tag or network excludes must not look included: the cell is dimmed and names the cause (Task 9 test).
- Arrow keys walk checkboxes: `stepMenuFocus` must also collect `menuitemcheckbox` (Task 6 test).

---

## Part A — Backend

### Task 1: `NetworkExclusion` replaces `ForYouNetworkExclusion`

**Files:**
- Create: `backend/src/Enum/NetworkExclusionSurface.php`, `backend/src/Entity/NetworkExclusion.php`, `backend/src/Repository/NetworkExclusionRepository.php`, `backend/migrations/Version20261113100000.php`
- Delete: `backend/src/Entity/ForYouNetworkExclusion.php`, `backend/src/Repository/ForYouNetworkExclusionRepository.php`
- Modify: `ForYouScopeDql.php`, `Service/Recommendation/Feed/ForYouNetworks.php`, `AccountWipeRepository.php`, backup exporter/restore (`AccountNetworkSwitchesModel`, `RestoredFoundationFactory`, `RestoreLoadPass`), their tests.

**Interfaces:**
- `enum NetworkExclusionSurface: string { case AllItems = 'all_items'; case ForYou = 'for_you'; }`
- Table `network_exclusion(id, user_id FK CASCADE, network varchar(16), surface varchar(16))`, unique `(user_id, network, surface)`, index `user_id`.
- Repository: `excludedNetworks(int $userId, NetworkExclusionSurface $surface): list<SocialNetwork>`, `findOneFor(int $userId, SocialNetwork $network, NetworkExclusionSurface $surface): ?NetworkExclusion`.
- Migration: create the table, `INSERT INTO network_exclusion (user_id, network, surface) SELECT user_id, network, 'for_you' FROM for_you_network_exclusion`, drop the old table. `down()` reverses it.
- Backup: `AccountNetworkSwitchesModel` gains `allItemsExcludedNetworks` (list of network values, restored as `AllItems` rows). A backup without the key restores none, so older backups still load.

- [ ] **Step 1:** Run the existing For You network tests (`ForYouNetworks*Test`, the `ForYouScopeDql` integration tests, the backup round-trip test) as a baseline: `php bin/phpunit --filter 'ForYou|Backup'` → green.
- [ ] **Step 2:** Add the failing test `NetworkExclusionMigrationTest`, which migrates a SQLite DB holding one `for_you_network_exclusion` row → one `network_exclusion` row with surface `for_you`. Add the backup round-trip assertion for `allItemsExcludedNetworks`.
- [ ] **Step 3:** Implement and repoint every caller. Old tests stay green; the new ones pass.
- [ ] **Step 4:** Run `php bin/phpunit`, `bin/console doctrine:migrations:migrate` on SQLite from empty, then `doctrine:schema:validate` → OK. Run the MySQL leg in Docker.
- [ ] **Step 5: Commit** `refactor(#2202): one network exclusion table for For You and All items`

### Task 2: Network → All items switch (API + scope)

**Files:**
- Create: `backend/src/Service/Reading/AllItemsNetworks.php` (mirrors `ForYouNetworks`: `exclude`, `include`, `excludedNetworks`), `backend/src/Controller/Api/AllItemsNetworksController.php`, both tests, an e2e case.
- Modify: `EntryScopePredicates::inAllItems`, `SubscriptionScopeRepository::findIncludedInAllItemsForUser`.

**Interfaces:**
- `GET /api/all-items/excluded-networks` → `{ excludedNetworks: string[] }`; `PUT|DELETE /api/all-items/excluded-networks/{network}` → 204. The shape matches the For You endpoints.
- If `AllItemsNetworks` and `ForYouNetworks` turn out identical except for the surface, merge them into one `NetworkExclusions` service, called with the surface from each controller (DRY). Put it in the lower of the two modules, so no cycle forms.
- `inAllItems` adds `NOT EXISTS (feed f WHERE f = entry.feed AND EXISTS (network_exclusion x WHERE x.user = :user AND x.network = f.network AND x.surface = 'all_items'))`. It binds only `:user`, like `ForYouScopeDql`. `findIncludedInAllItemsForUser` gets the same condition on `s.feed`.

- [ ] **Step 1: Failing tests.** `UnreadCountRepositoryTest`: a Bluesky feed with the network excluded from All items counts 0 toward `allItems`. Entry list: the All items view hides it, but the network's own view still shows it. `MarkReadServiceTest`: marking All read leaves it unread. Controller functional test: PUT → GET lists it → DELETE → GET empty. Fixtures need real network URLs (`https://bsky.app/profile/<h>/rss`); `FeedNetworkListener` reclassifies them.
- [ ] **Step 2:** Run `php bin/phpunit --filter 'AllItems|UnreadCount|MarkRead'` → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Rerun → PASS.
- [ ] **Step 5: Commit** `feat(#2202): switch a social network out of All items`

### Task 3: Tag switches — storage, PATCH, All items scope

**Files:**
- Modify: `backend/src/Entity/Tag.php` (three `bool` columns `include_in_all_items`, `include_in_for_you`, `include_in_saved_searches`, default true), `Dto/Tag/UpdateTagRequest.php` (three optional `?bool`), the tag update service (an inclusion-change model, as `InclusionChangeModel` does for subscriptions; reuse it if the fields line up), `Http/TagJson.php`, backup tag lines, migration `Version20261113110000.php`.
- Create: `backend/src/Repository/TagExclusionDql.php`.

**Interfaces:**
- `TagExclusionDql::excludes(string $subscriptionAlias, string $flag): string` returns `NOT EXISTS (SELECT 1 FROM SubscriptionTag st JOIN st.tag t LEFT JOIN t.parent p WHERE st.subscription = <alias> AND (t.<flag> = false OR p.<flag> = false))`. `$flag` is one of the three property names, matched on a private enum or constant set; it is never interpolated from input. Aliases are suffixed per caller so the collapse subquery doesn't clash, as `ForYouScopeDql` does.
- `TagJson` adds `includeInAllItems`, `includeInForYou`, `includeInSavedSearches`.
- `inAllItems` and `findIncludedInAllItemsForUser` add `excludes(…, 'includeInAllItems')`.

- [ ] **Step 1: Failing tests.** PATCH a tag with `{includeInAllItems:false}` → 200, and GET shows the field false while the others stay true. The All items list/count excludes a feed tagged with it. A feed on a **subtag** of it is excluded too. A feed carrying it plus an included tag is excluded. An untagged feed is still included. The tag's own view still lists its feeds. Mark-all-read skips them. Backup round-trip keeps the flags.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement. Use `ALTER TABLE ADD COLUMN … DEFAULT 1 NOT NULL` on both dialects; `tag` has no FTS triggers.
- [ ] **Step 4:** Run → PASS; migrate from empty on both dialects; `schema:validate`.
- [ ] **Step 5: Commit** `feat(#2202): tags switch their feeds out of All items`

### Task 4: Tag switches — For You and saved searches (incl. digest)

**Files:** Modify `ForYouScopeDql.php`, `EntryScopePredicates::inSavedSearches`, tests.

- [ ] **Step 1: Failing tests.** A tag with `includeInForYou:false` → `RecommendationCandidateRepository` leaves its feeds out (both the primary path and the collapse path). A tag with `includeInSavedSearches:false` → `SavedSearchEntryRepository` matches none of its entries, and `DigestComposer` (or its repository call) leaves them out.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Add `TagExclusionDql::excludes` to both. **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** `feat(#2202): tags switch their feeds out of For You and saved searches`

## Part B — Frontend

### Task 5: Shared menu checkbox + action-sheet check item

**Files:** Create `frontend/src/app/shared/menu-checkbox/menu-checkbox.component.{ts,html,scss,spec.ts}`. Modify `shared/action-sheet/action-sheet.component.{ts,html,scss}`, `action-sheet.service.spec.ts`, `shared/menu-keys.ts`.

**Interfaces:**
- `<app-menu-checkbox [label] [checked] (toggled) />`. Its template: `<label class="menu-check"><input type="checkbox" role="menuitemcheckbox" [checked]="checked()" [attr.aria-checked]="checked()" (change)="toggled.emit()" (click)="$event.stopPropagation()" /><span>{{ label() }}</span></label>`. It uses the row-menu button metrics (`--row-pad-y/x`, `--fs-dense`, gap `--space-2`, `--tap-target` on coarse, `--surface-0` hover).
- `interface ActionSheetCheck { readonly id: string; readonly label: string; readonly checked: Signal<boolean>; readonly toggle: () => void }` joins `ActionSheetItem`, with an `isCheck()` guard (`'toggle' in item`).
- `stepMenuFocus` selector: `'[role="menuitem"], [role="menuitemcheckbox"]'`.

- [ ] **Step 1: Failing tests.** It reflects `checked` (checked + aria-checked) and emits `toggled` once on click. The click does not reach a container `(click)`. A sheet check item calls `toggle` without emitting `closed`, and re-renders when its signal flips. ArrowDown moves from an action onto a checkbox.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/shared` → FAIL. **Step 3:** Implement. **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(#2202): a menu checkbox, and check items on the action sheet`

### Task 6: Stores and API for the new switches

**Files:**
- Modify: `reader/reader-api.ts` (`allItemsExcludedNetworks()`, `excludeNetworkFromAllItems(n)`, `includeNetworkInAllItems(n)`, and `updateTag` accepting the three flags), `reader/models.ts` (`TagDto` + three `boolean`s), `reader/testing/*` factories, `state/tags.store.ts` (`setTagInclusion(tag, surface: ExclusionSurface, included: boolean)`: patch locally, then PATCH, then reload subscriptions/counts as `ManageActions.patchFlags` does).
- Create: `state/all-items-networks.store.ts`, modelled on `ForYouNetworksStore` and loaded at the same points (boot + refresh).
- Create: `reader/state/visibility.ts`. It is pure and has one export each:
  - `type ExclusionCause = { kind: 'network'; network: SocialNetworkName } | { kind: 'tag'; tag: TagDto }`
  - `interface VisibilitySwitches { readonly tags: readonly TagDto[]; readonly excludedNetworks: Readonly<Record<ExclusionSurface, ReadonlySet<SocialNetworkName>>> }`. Saved searches enter as "all networks minus the included set".
  - `inheritedExclusions(subscription, surface, switches): ExclusionCause[]` lists the network first, then the tags it carries and their parents with the surface off, deduplicated.
  - `isVisibleIn(subscription, surface, switches): boolean` is the feed's own flag AND no inherited exclusion. This is the client copy of the backend rule.
- Modify: `state/subscription-derivations.ts`. `sumEntries` counts a feed only when `isVisibleIn(…, 'allItems', …)`; it takes `switches`, and its callers build that from the stores.
- Modify: `reader/feed-exclusions.ts`. Delete `excludeKey` and `feedFlagLabelKey`; `includeKey` is the checkbox label.

- [ ] **Step 1: Failing tests.** Store: toggling adds or removes, PUT/DELETE goes out, and a write error reverts. `setTagInclusion` patches optimistically and calls the API with only the changed flag. `inheritedExclusions` covers each case: network, own tag, parent tag, two causes in order, none for an untagged non-social feed. Saved searches count a network excluded unless it is in the included set. `sumEntries` skips a feed whose tag, parent tag or network is excluded.
- [ ] **Step 2:** → FAIL. **Step 3:** Implement. **Step 4:** → PASS. **Step 5: Commit** `feat(#2202): client stores for network and tag inclusion`

### Task 7: Sidebar feed, network and tag menus

**Files:** `shell/sidebar/sidebar-row-actions.service.ts` (+spec), `sidebar-feed-row.component.{ts,html}`, `sidebar-social-section.component.html` (+spec), `sidebar-tag-row-menu.component.{ts,html}` (+spec), `sidebar-group-row` for the tag-row exclusion marker.

**Interfaces (on `SidebarRowActions`):**
- `toggleFeedFlag` and `toggleNetworkSurface(section, surface: ExclusionSurface)` no longer close the menu.
- `NetworkSurfaceSwitch` gains an `allItems` entry backed by `AllItemsNetworksStore`. It loses `excludeKey`; its labels are `reader.includeInAllItems`, `reader.includeInForYou` and `reader.includeInSavedSearches`. The order is All items, For You, saved searches, matching the feed menu. `TITLE_ORDER` gains `allItems` first.
- `networkSurfaceChecks(section): ActionSheetCheck[]` replaces `networkSurfaceActions`, with `checked = computed(() => !isExcluded(network))`.
- `tagSurfaceChecks(tag): ActionSheetCheck[]`: one per surface. `checked` reads the live tag from `TagsStore` by id; `toggle` calls `setTagInclusion`.
- The feed sheet adds one `ActionSheetCheck` per `FEED_FLAG_TOGGLES` entry. `checked`/`toggle` read the live subscription from `SubscriptionsStore` by id (a private `liveSubscription()`).
- Every popover renders `<app-menu-checkbox>`s after the existing actions, behind an `<hr />`. The tag menu places them above Delete. The tag sheet adds them before the divider.
- The tag row shows the same `visibility_off` marker and "Excluded from …" title (`exclusionTitle`) that feed rows show, whenever any surface is off.

- [ ] **Step 1: Failing tests.** A toggle keeps `menuFor`. A network gets three checks that follow the stores. A tag gets three checks that follow `TagsStore`. **The feed sheet flips from the live subscription** (toggle, the store patches, toggle again → `false` then `true`, and `closed` never emits). A tag row with For You off shows the marker.
- [ ] **Step 2:** `npm test -- src/app/reader/shell/sidebar` → FAIL. **Step 3:** Implement. **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(#2202): sidebar inclusion switches are checkboxes that keep the menu open`

### Task 8: Settings → Organise feed row

**Files:** `settings/organise/organise-feed-row.component.{ts,html}` (+spec).

- The popover's flag buttons become `<app-menu-checkbox [attr.data-test]="flag.dataTest" [label]="flag.includeKey | transloco" [checked]="flag.isIncluded(subscription())" (toggled)="toggleFlag(flag)" />` with no `closeMenu()`. The sheet uses `ActionSheetCheck`s with `checked = computed(() => toggle.isIncluded(this.subscription()))`.
- [ ] **Step 1:** Update the three `data-test` specs: click the input, the output is emitted, and `.pop` is still rendered. Add a test that sheet checks follow the input. **Step 2:** → FAIL. **Step 3:** Implement. **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(#2202): Organise inclusion switches are checkboxes too`

### Task 9: Settings → Visibility, the whole matrix

**Files:**
- Create: `frontend/src/app/settings/visibility/visibility-section.component.{ts,html,scss,spec.ts}`, `visibility-row.component.{ts,html,scss,spec.ts}`
- Modify: `settings/settings-sections.ts` (the entry `{ path: 'visibility', icon: 'visibility', labelKey: 'settings.visibility.title', group: 'general', wide: true }`, placed right after `organise`), `settings/settings.routes.ts` (a lazy route, as Organise has), `settings-sections.spec.ts` / `settings.routes.spec.ts`, `public/i18n/{en,de}.json` (`settings.visibility.*`: title "Visibility"/"Sichtbarkeit", intro, group heads "Networks"/"Tags"/"Feeds", filter placeholder, "Excluded by {{causes}}" / "Ausgeschlossen durch {{causes}}", and the empty-filter message).

**Layout.** Read `docs/design-language.md` before building, and reuse its shared components.
- Columns: a name column, then **All items**, **For You** and **Saved searches**. They use the existing `reader.allItems`/`reader.forYou`/`reader.savedSearches` labels. The header row is sticky.
- Three row groups, in order:
  - **Networks:** every network that has feeds, from `socialSections`, with its logo.
  - **Tags:** each root tag in sidebar order, its subtags indented beneath it.
  - **Feeds:** every subscription by title, with favicon. A text filter above the matrix narrows only this group (case-insensitive substring of the title).
- **Every cell is editable**: no cell is ever `disabled` or read-only, greyed ones included, and a tick saves at once with no Save button. Each cell holds a native checkbox (`aria-label` = "<row name>: <column>") bound to that row's **own** switch. Networks use the three network stores, tags use `TagsStore.setTagInclusion`, and feeds use `ManageActions.setIncludeIn*`.
- Inherited exclusion: when a feed's own box is checked but `inheritedExclusions` is non-empty, the box stays checked and the cell is dimmed (`--text-muted`) with a `visibility_off` icon. Its title is "Excluded by Bluesky, Tag A". A subtag under a parent that is switched off shows the same dimming, with the parent as the cause. No other row inherits.
- Phone width: the name column truncates with an ellipsis. The three checkbox columns use the narrow `--tap-target` width, and the page itself never scrolls sideways.
- The page reads only existing stores; no new endpoint.

**Interfaces:**
- Consumes: Task 6's stores, `inheritedExclusions` and `VisibilitySwitches`, plus `SubscriptionsStore.socialSections`.
- `VisibilityRowComponent` inputs: `name: string`, `icon` slot (content projection), `cells: readonly VisibilityCell[]`. Output: `toggled: ExclusionSurface`. `interface VisibilityCell { surface: ExclusionSurface; checked: boolean; inheritedFrom: readonly string[] }`, where an empty `inheritedFrom` means not dimmed.

- [ ] **Step 1: Failing tests.**
  - The section renders the three groups in order.
  - A tag with For You off shows its For You box unchecked, and its subtag's For You cell dimmed with the parent's name in the title.
  - A Bluesky feed with Bluesky excluded from All items shows that cell checked but dimmed, titled "Excluded by Bluesky".
  - Ticking a feed cell calls `setIncludeInForYou(feed, false)`; ticking a network cell calls the matching store's toggle; ticking a tag cell calls `setTagInclusion(tag, 'savedSearches', …)`.
  - A greyed (inherited) cell is not disabled, and ticking it still writes the feed's own flag.
  - The filter narrows the feeds and leaves networks and tags alone.
  - The nav lists Visibility after Organise, and `/settings/visibility` resolves.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/settings` → FAIL. **Step 3:** Implement. **Step 4:** → PASS.
- [ ] **Step 5: Commit** `feat(#2202): Settings → Visibility shows every inclusion switch in one matrix`

### Task 11: Trending as a fourth surface — backend

Lars (2026-11-13): "Add trending to the matrix and menus." Trending is a fourth surface, with the same three switch levels (feed, tag, network). It is included by default at every level, and exclusion wins.

**Files:**
- Modify: `Enum` `InclusionSurface` (`Trending`) and `NetworkExclusionSurface` (`Trending = 'trending'`).
- Modify: `Entity/Subscription.php` (`include_in_trending`, default true) and `Entity/Tag.php` (same).
- Modify: the subscription inclusion model, single PATCH, bulk PATCH, `SubscriptionJson`, `TagJson`, tag PATCH, backup lines and their declarations.
- Create: migration `Version20261113120000.php`.
- Create: `Controller/Api/TrendingNetworksController.php` (`/api/trending/excluded-networks`, same shape as All items).
- Modify: the per-reader Trending SQL in `Repository/TrendingStoryRepository.php`, plus anything else that counts the reader's Trending stories (the unopened badge in `TrendingStories`). Every `JOIN subscription … AND user_id = :user` that decides what the reader sees or counts gains "this subscription is in Trending".
- Create: a native-SQL fragment in `src/Repository`, because these queries are DBAL, not DQL. It encodes the same rule as the DQL helpers: own flag, then network not excluded, then no tag on the lineage switched off. Keep the rule in one place per query language and name it after its job.
- The build-time grouping (`TrendingGrouping::stories`, raw feed counts) stays unchanged. Only read time filters, as #2056 settled.

- [ ] **Step 1: Failing tests.**
  - A story whose only sources are excluded feeds is not shown and not counted in the badge.
  - The excluded feed's source no longer counts toward `outlet_count` or the coverage threshold.
  - An excluded earlier copy no longer suppresses the included later copy (`NO_EARLIER_COPY`).
  - Tag off, subtag under an off parent, and network off each exclude the feed.
  - The PATCH, bulk, tag and network endpoints round-trip, and the backup keeps the flags.
- [ ] **Step 2:** → FAIL. **Step 3:** Implement. **Step 4:** Full SQLite and MySQL suites, cs, stan and md all pass. Migrate from empty and validate the schema, then apply the migration to the live Docker DB.
- [ ] **Step 5: Commit** `feat(#2202): switch feeds, tags and networks out of Trending`

### Task 12: Trending in the menus and the matrix — frontend

- `ExclusionSurface` gains `'trending'`.
- `FEED_FLAG_TOGGLES` gains a fourth entry with `includeKey` `reader.includeInTrending` ("Show in Trending" / "In Trends anzeigen"; check `de.json` for the existing Trending term). `SubscriptionDto`, `TagDto` and the factories gain `includeInTrending`.
- Add `TrendingNetworksStore` on `NetworkExclusionsStore` with surface `'trending'`, loaded with the others.
- `VisibilitySwitches` gains the Trending set, and `visibility.ts` handles the surface.
- The sidebar network/tag/feed menus and sheets, the Organise row and the Visibility matrix all render from the surface lists, so each gains the fourth checkbox and column. Check the 375px matrix with 4 columns.
- `exclusionTitle` and the surface labels gain Trending.
- After a switch, reload the Trending list and badge if they are open or counted.
- [ ] Failing specs for each: four checks in each menu, four columns, the store round trip, and visibility causes for Trending. Then implement, `npm run check`, and commit `feat(#2202): Trending in the inclusion menus and the matrix`.

### Task 10: Dead keys, gates, live check

- [ ] **Step 1:** Delete `reader.excludeFromAllItems`, `excludeFromForYou`, `excludeFromSavedSearches`, `includeNetworkInSavedSearches` and `excludeNetworkFromSavedSearches` from `en.json`/`de.json`, after `grep` shows no reference.
- [ ] **Step 2:** Run `composer check`, `composer md`, `php bin/phpunit` and the Docker `composer test:parallel` in parallel, plus `npm run check`. All green. Scan today's dev log.
- [ ] **Step 3:** Apply the migrations to the live Docker DB. Open Settings → Visibility at desktop and at 375px wide: no sideways scroll, ticks persist, and a cell excluded by a tag or network is dimmed. On :4200 (fine pointer and mobile preset), tick two boxes in a row on a feed, a network and a tag: the menu stays open, and both survive a reload. Excluding a tag from All items drops its feeds from All items and its count. Arrow keys walk the boxes.
- [ ] **Step 4: Commit** `chore(#2202): drop the exclude/include menu labels`
- [ ] **Step 5:** Add a release-note line for the PR: the For You network table moved, and tags/networks gained switches.
