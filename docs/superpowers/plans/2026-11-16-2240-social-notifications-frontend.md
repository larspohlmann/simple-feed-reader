# Social Notifications Frontend (#2240, PR 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The SPA shows each connected network's notifications: a "Notifications" row with an unseen pill in the network's sidebar card, a per-network list in the reader's list pane with "Mark all seen", an unseen badge on the account avatar, and a redesigned account menu that lists the networks with unseen notifications.

**Architecture:** The counts arrive in the existing subscriptions/counts poll (`notifications: [{network, unseenCount}]`), and `SubscriptionsStore` is their one source for the sidebar, the header badge and the menu. The list is a new `notifications` **selection kind**, URL `/?view=notifications&network=<network>`: it rides the reader's own selection vocabulary (`selectionQueryParams`, `ListHeading`, the tab title, drawer-close-on-navigation, the split pane) instead of a separate page; the shell's list pane hosts a new `<app-notification-list>` in place of `<app-entry-list>` for it, and `queryFromSelection` returns `null` so no entry list loads. A root `NotificationsStore` reads `GET /api/me/notifications` and posts `/seen`. One shared `<app-count-pill>` draws the soft accent count in the sidebar row and the menu.

**Tech Stack:** Angular 20 (standalone, signals), Transloco, Jest (jsdom, in the Docker `frontend` container), Playwright.

**Spec:** issue #2240 and its decisions comment (`gh api repos/larspohlmann/simple-feed-reader/issues/comments/6921751762`), plus the approved mockup: `/private/tmp/claude-501/-Users-lars--claude-spares-spare-ZQJand/630c1491-be28-4d9b-82c2-5feac1d8e35a/scratchpad/mock/*.png` and its CSS in `zz-mockup-2240.spec.ts` there (a scratch folder: every value the build needs is copied into this plan). The mockup wins on visuals. Backend API (merged, PR #2286): `backend/src/Http/SocialNotificationsJson.php`, `backend/src/Controller/Api/SocialNotificationController.php`, `backend/src/Http/SubscriptionCountsJson.php`.

## Global Constraints

- Frontend only; no backend change. The PR body says `Closes #2240`.
- Standalone components + signals; component styles in a sibling `.scss` (`styleUrl`), never inline.
- No hex colours and no ad-hoc `px` for padding/margin/gap/font-size/radius/sizing outside `src/app/theme/`, `src/styles/`; a tuned dimension uses the escape hatch `/* stylelint-disable-next-line declaration-property-unit-allowed-list -- <reason> */`.
- Breakpoints only via `@use '…/theme/breakpoints' as bp;`. Any full border is `glass-rim.filled(…)`/`glass-rim.ring(…)`; single-edge borders (`border-bottom: 1px solid var(--border)`) are fine.
- Rows use comfortable density: `padding: var(--row-pad-comfy-y) var(--row-pad-comfy-x)`.
- Jest runs only in Docker: `docker compose exec -T frontend npm test -- <path>`; the gate is `docker compose exec -T frontend npm run check`. Playwright: `cd frontend && npm run e2e -- <spec>` against the running Docker stack.
- Strings in en, de, fr, es, exactly the **Copy** table below; German uses du, French vous, Spanish tú. Plurals follow the app's `…One`/`…Other` key pairs.
- Commits: `feat(#2240): …` / `refactor(#2240): …` / `test(#2240): …` / `docs(#2240): …`; no Claude attribution.
- `docs/design-language.md` is the design source of truth: the count pill (§2), the notification list and the sidebar row (§3 *Sidebar cards*), the account menu and avatar badge (§3) are documented there in the task that builds them.

### Copy

All under `reader.notifications` unless the key starts with `reader.`. `{{…}}` placeholders must match English exactly.

| Key | en | de | fr | es |
|---|---|---|---|---|
| `title` | Notifications | Benachrichtigungen | Notifications | Notificaciones |
| `loading` | Loading notifications… | Benachrichtigungen werden geladen… | Chargement des notifications… | Cargando notificaciones… |
| `markAllSeen` | Mark all seen | Alle als gesehen markieren | Tout marquer comme vu | Marcar todo como visto |
| `end` | That's everything from the last 30 days. | Das ist alles aus den letzten 30 Tagen. | C'est tout pour les 30 derniers jours. | Eso es todo de los últimos 30 días. |
| `empty` | No notifications from the last 30 days. | Keine Benachrichtigungen aus den letzten 30 Tagen. | Aucune notification ces 30 derniers jours. | No hay notificaciones de los últimos 30 días. |
| `scopeMissing` | {{network}} needs permission to read your notifications. | {{network}} braucht die Berechtigung, deine Benachrichtigungen zu lesen. | {{network}} a besoin d'une autorisation pour lire vos notifications. | {{network}} necesita permiso para leer tus notificaciones. |
| `needsReconnect` | {{network}} needs reconnecting before new notifications arrive. | {{network}} muss neu verbunden werden, damit neue Benachrichtigungen ankommen. | {{network}} doit être reconnecté pour recevoir de nouvelles notifications. | Hay que volver a conectar {{network}} para que lleguen nuevas notificaciones. |
| `reconnect` | Reconnect {{network}} | {{network}} neu verbinden | Reconnecter {{network}} | Volver a conectar {{network}} |
| `unseen` | Unseen | Ungesehen | Non vue | Sin ver |
| `poll` | A poll you voted in has ended | Eine Umfrage, an der du teilgenommen hast, ist beendet | Un sondage auquel vous avez participé est terminé | Ha terminado una encuesta en la que votaste |
| `who.one` | {{first}} | {{first}} | {{first}} | {{first}} |
| `who.two` | {{first}} and {{second}} | {{first}} und {{second}} | {{first}} et {{second}} | {{first}} y {{second}} |
| `who.twoAndOne` | {{first}}, {{second}} and 1 other | {{first}}, {{second}} und 1 weitere Person | {{first}}, {{second}} et 1 autre personne | {{first}}, {{second}} y 1 persona más |
| `who.twoAndOthers` | {{first}}, {{second}} and {{count}} others | {{first}}, {{second}} und {{count}} weitere | {{first}}, {{second}} et {{count}} autres | {{first}}, {{second}} y {{count}} personas más |
| `action.like.one` / `.other` | {{who}} liked your post / same | {{who}} hat deinen Beitrag geliked / {{who}} haben deinen Beitrag geliked | {{who}} a aimé votre publication / {{who}} ont aimé votre publication | A {{who}} le gustó tu publicación / A {{who}} les gustó tu publicación |
| `action.repost.one` / `.other` | {{who}} reposted your post / same | {{who}} hat deinen Beitrag repostet / {{who}} haben deinen Beitrag repostet | {{who}} a republié votre publication / {{who}} ont republié votre publication | {{who}} compartió tu publicación / {{who}} compartieron tu publicación |
| `action.reply.one` / `.other` | {{who}} replied to your post / same | {{who}} hat auf deinen Beitrag geantwortet / {{who}} haben auf deinen Beitrag geantwortet | {{who}} a répondu à votre publication / {{who}} ont répondu à votre publication | {{who}} respondió a tu publicación / {{who}} respondieron a tu publicación |
| `action.mention.one` / `.other` | {{who}} mentioned you / same | {{who}} hat dich erwähnt / {{who}} haben dich erwähnt | {{who}} vous a mentionné / {{who}} vous ont mentionné | {{who}} te mencionó / {{who}} te mencionaron |
| `action.quote.one` / `.other` | {{who}} quoted your post / same | {{who}} hat deinen Beitrag zitiert / {{who}} haben deinen Beitrag zitiert | {{who}} a cité votre publication / {{who}} ont cité votre publication | {{who}} citó tu publicación / {{who}} citaron tu publicación |
| `action.follow.one` / `.other` | {{who}} followed you / same | {{who}} folgt dir jetzt / {{who}} folgen dir jetzt | {{who}} vous suit désormais / {{who}} vous suivent désormais | {{who}} empezó a seguirte / {{who}} empezaron a seguirte |
| `reader.signedIn` | Signed in | Angemeldet | Connecté | Sesión iniciada |
| `reader.accountWithNotificationsOne` | Account, 1 unseen notification | Konto, 1 ungesehene Benachrichtigung | Compte, 1 notification non vue | Cuenta, 1 notificación sin ver |
| `reader.accountWithNotificationsOther` | Account, {{count}} unseen notifications | Konto, {{count}} ungesehene Benachrichtigungen | Compte, {{count}} notifications non vues | Cuenta, {{count}} notificaciones sin ver |

The network's display name is the existing `settings.social.<network>` key. The list's error banner reuses `reader.errorOffline`, `reader.errorServer`, `reader.retry`, `reader.dismiss`; the refresh button reuses `reader.refresh`.

## Review Focus

1. **Switching networks while a page is in flight** (Bluesky → Mastodon in the sidebar before Bluesky answers): the Mastodon list must never show Bluesky's rows. Pinned by Task 3's `drops a page answered for a network the user has left`.
2. **Mark all seen fails** (502 unreachable, 503 + Retry-After, 429): dots stay, counts are not reloaded, a toast says why, the button is usable again. Pinned by Task 3's `keeps the dots and toasts the problem when marking fails`.
3. **An actor with no display name** (Mastodon/Lemmy often send none): the line names the handle instead of rendering empty bold text. Pinned by Task 5's `names an actor by handle when the display name is missing`.
4. **A hand-edited or stale URL** (`?view=notifications` without a network, or `network=myspace`): opens All items, as an unknown `network` does today. Pinned by Task 4's `reads notifications without a known network as all items`.
5. **A big unseen total on the avatar badge** (a popular account after a weekend, 140 unseen): the 16px badge shows `99+`, the accessible name the real number. Pinned by Task 8's `caps the badge at 99+`.

---

### Task 1: Notification counts from the counts poll

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`SubscriptionsResponse`, `SubscriptionCountsResponse`)
- Create: `frontend/src/app/reader/state/notification-counts.ts`
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`
- Modify: `frontend/e2e/support/reader.ts` (`subscriptionsJson` default)
- Test: `frontend/src/app/reader/state/notification-counts.spec.ts`, `frontend/src/app/reader/state/subscriptions.store.spec.ts`

**Interfaces:**
- Produces:
  - `models.ts`: `export interface NotificationCountDto { network: SocialNetworkName; unseenCount: number }`; `notifications: NotificationCountDto[]` on both `SubscriptionsResponse` and `SubscriptionCountsResponse` (`SubscriptionJson` spreads `surfaceTotals`, so the full list carries it too).
  - `notification-counts.ts`: `type NotificationCounts = ReadonlyMap<SocialNetworkName, number>`; `const NO_NOTIFICATION_COUNTS: NotificationCounts`; `notificationCountsFrom(dtos: readonly NotificationCountDto[]): NotificationCounts`; `sameNotificationCounts(left, right): boolean`; `interface UnseenNetwork { readonly network: SocialNetworkName; readonly unseenCount: number }`; `unseenNetworks(counts: NotificationCounts): readonly UnseenNetwork[]` (in `SOCIAL_NETWORKS` order, `> 0` only); `totalUnseen(counts: NotificationCounts): number`.
  - `SubscriptionsStore`: `readonly notificationCounts: Signal<NotificationCounts>`, `readonly unseenNotifications: Signal<readonly UnseenNetwork[]>`, `readonly unseenNotificationsTotal: Signal<number>`.

- [ ] **Step 1: Write the failing tests.**
  - `notification-counts.spec.ts`:
    - `reads the counts per network`: `notificationCountsFrom([{network:'bluesky',unseenCount:4},{network:'mastodon',unseenCount:2}]).get('bluesky')` is `4`, `.get('lemmy')` is `undefined`.
    - `lists only networks with unseen notifications, in network order`: from `[{mastodon,2},{lemmy,0},{bluesky,4}]` → `unseenNetworks` equals `[{network:'bluesky',unseenCount:4},{network:'mastodon',unseenCount:2}]`.
    - `totals the unseen across networks`: same input → `6`; `totalUnseen(NO_NOTIFICATION_COUNTS)` → `0`.
    - `compares by value`: two maps built from equal arrays are `sameNotificationCounts` → `true`; one count differing → `false`.
  - `subscriptions.store.spec.ts`: extend the `counts` helper with `notifications: totals.notifications ?? []` (add `notifications?: NotificationCountDto[]` to `CountTotals`), then:
    - `holds the notification counts the full load carries`: after `load()` flushed with `notifications: [{network:'bluesky',unseenCount:4}]`, `store.notificationCounts().get('bluesky')` is `4` and `store.unseenNotificationsTotal()` is `4`.
    - `takes notification counts from a counts tick even when overtaken by a local edit` (in the quiet-reload describe): settle and age, call `reloadCountsIfStale()`, `bumpFavorites(1)` before flushing `/api/subscriptions/counts` with `notifications: [{network:'mastodon',unseenCount:2}]` → `store.unseenNotifications()` equals `[{network:'mastodon',unseenCount:2}]`.
    - `forgets the notification counts on an identity change`: after a load with counts, switch the token as the existing identity tests do → `unseenNotificationsTotal()` is `0`.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/state/notification-counts.spec.ts src/app/reader/state/subscriptions.store.spec.ts` — expect FAIL (module missing, properties missing).
- [ ] **Step 3: Implement.** `notification-counts.ts` as in Interfaces. In the store: a private `storedNotificationCounts = signal(NO_NOTIFICATION_COUNTS, { equal: sameNotificationCounts })` with `notificationCounts = …asReadonly()` and the two `computed`s; `applyCounts` and `quietFetch` set it from `response.notifications` (in `quietFetch` beside the unread totals, before the overtaken check: only the server moves these counts); widen `quietReload`/`quietFetch`'s `T` constraint with `notifications: NotificationCountDto[]`; `invalidate()` resets it. In `e2e/support/reader.ts`, `subscriptionsJson` defaults `notifications: []`.
- [ ] **Step 4:** Re-run Step 2's command — expect PASS.
- [ ] **Step 5: Commit** `feat(#2240): hold the unseen notification counts the counts poll carries`.

---

### Task 2: `<app-count-pill>`

**Files:**
- Create: `frontend/src/app/shared/count-pill/count-pill.component.{ts,html,scss}`
- Modify: `docs/design-language.md` (§2 catalog entry after `<app-avatar>`)
- Test: `frontend/src/app/shared/count-pill/count-pill.component.spec.ts`

**Interfaces:**
- Produces: `CountPillComponent`, selector `app-count-pill`, `readonly count = input.required<number>()`, OnPush. Renders the number only; callers decide not to render it at 0.

- [ ] **Step 1: Failing test** `count-pill.component.spec.ts` — `shows the count`: with `count` set to `4`, the host's `textContent.trim()` is `'4'`.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/shared/count-pill` — FAIL.
- [ ] **Step 3: Implement.** Host styles (`:host` in the `.scss`): `display: inline-block; box-sizing: border-box; min-width: calc(var(--space-5) - var(--space-0))` (22px); `padding: var(--space-0) var(--space-2); border-radius: var(--radius-pill); background: var(--accent-soft); color: var(--accent); font-size: var(--fs-xs); font-weight: 700; line-height: 1.3; font-variant-numeric: tabular-nums; text-align: right`. Catalog entry: what it is (a soft accent count for a surface that counts unseen things, not unread posts), its one input, "render it only for a count above 0", the users (sidebar Notifications row, account menu), and that a row which aligns its digit with sibling counts pulls the pill right by its own horizontal padding (`margin-right: calc(var(--space-2) * -1)`).
- [ ] **Step 4:** Re-run — PASS.
- [ ] **Step 5: Commit** `feat(#2240): add the shared count pill`.

---

### Task 3: Notifications API and store

**Files:**
- Create: `frontend/src/app/reader/notifications/notification.models.ts`, `notifications-api.ts`, `notifications.store.ts`
- Test: `frontend/src/app/reader/notifications/notifications-api.spec.ts`, `notifications.store.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionsStore.reloadCountsQuietly()` (existing), `ToastService.showProblem(problem)`, `parseProblem` (`core/problem`), `accountSignal` (`core/auth/session-identity`), `API_BASE_URL`.
- Produces:
  - `notification.models.ts`: `type NotificationKind = 'like' | 'repost' | 'reply' | 'mention' | 'quote' | 'follow' | 'poll'`; `interface NotificationActorDto { handle: string; displayName: string | null; avatarUrl: string | null; profileUrl: string }`; `interface NotificationGroupDto { id: number; kind: NotificationKind; account: string; actors: NotificationActorDto[]; actorCount: number; excerpt: string | null; linkUrl: string; latestAt: string; seen: boolean }` (`account` is the receiving account's handle); `interface NotificationsPageDto { groups: NotificationGroupDto[]; nextCursor: number | null; scopeMissing: string[]; needsReconnect: string[] }` (both lists hold account handles).
  - `NotificationsApi` (root): `page(network: SocialNetworkName, cursor: number | null): Observable<NotificationsPageDto>` → `GET {base}/api/me/notifications?network=…[&cursor=…]`; `markSeen(network: SocialNetworkName, upTo: number): Observable<void>` → `POST {base}/api/me/notifications/seen` body `{ network, upTo }`.
  - `NotificationsStore` (root), all state in `accountSignal`s: `network: Signal<SocialNetworkName | null>`, `groups: Signal<readonly NotificationGroupDto[]>`, `scopeMissing`, `needsReconnect: Signal<readonly string[]>`, `nextCursor: Signal<number | null>`, `loading`, `loadingMore`, `marking: Signal<boolean>`, `error: Signal<Problem | null>`, `hasUnseen = computed(() => groups().some(group => !group.seen))`; methods `open(network): void` (first page, replacing the list), `reload(): void` (first page of the open network; no-op before `open`), `loadMore(): void` (no-op without cursor or while loading), `markAllSeen(): void`, `dismissError(): void`.

- [ ] **Step 1: Failing tests** (HttpTestingController, `API_BASE_URL = 'https://api.test'`, a `ToastService` stub `{ showProblem: jest.fn() }`, a `SubscriptionsStore` stub `{ reloadCountsQuietly: jest.fn() }`; a `group(id, over?)` fixture builder):
  - API: `asks for a network's first page` (URL `https://api.test/api/me/notifications?network=bluesky`, no `cursor` param); `asks for the next page by cursor` (`cursor=41`); `posts the seen position` (method POST, body `{ network: 'mastodon', upTo: 77 }`).
  - Store:
    - `opens a network's first page`: `open('bluesky')`, flush `{groups:[group(9), group(7)], nextCursor: 7, scopeMissing: ['@a'], needsReconnect: []}` → `groups().map(g => g.id)` is `[9, 7]`, `nextCursor()` `7`, `scopeMissing()` `['@a']`, `loading()` false.
    - `appends the next page`: then `loadMore()`, flush `{groups:[group(5)], nextCursor: null, …}` → ids `[9, 7, 5]`, `nextCursor()` `null`.
    - `drops a page answered for a network the user has left`: `open('bluesky')`, `open('mastodon')` → the bluesky request is cancelled (`expectOne(...bluesky).cancelled` is `true`); flush mastodon with `[group(3)]` → ids `[3]`, `network()` `'mastodon'`.
    - `marks everything shown seen up to the newest group`: open with `[group(9,{seen:false}), group(7,{seen:false})]`; `markAllSeen()` → one POST body `{network:'bluesky', upTo: 9}`; flush `null` with status 204 → every `groups()` item has `seen: true`, `reloadCountsQuietly` called once, `marking()` false.
    - `keeps the dots and toasts the problem when marking fails`: same, flush the POST with status 502 and a problem body `{type:'social_network_unreachable', title:'…', status:502}` → `groups().some(g => !g.seen)` true, `showProblem` called once with an object whose `status` is 502, `reloadCountsQuietly` not called, `marking()` false.
    - `marks nothing when the list is empty`: open with `[]`, `markAllSeen()` → `expectNone` on `/seen`.
    - `reports a failed load and retries it on reload`: flush the GET with status 500 → `error()?.status` 500, `groups()` empty; `reload()` issues the same GET again.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/notifications` — FAIL.
- [ ] **Step 3: Implement.** The store keeps the in-flight list request in a private `Subscription` and unsubscribes it on every `open`/`reload` (the `EntriesStore.load` pattern), so a stale answer cannot land. `markAllSeen` returns early when `groups()` is empty or `marking()`; `upTo = groups()[0].id` (the backend orders groups by `latestId DESC`, so the first is the newest shown, and `/seen` compares notification ids `<= upTo`); success sets `seen: true` on every loaded group and calls `subscriptions.reloadCountsQuietly()`; failure calls `toast.showProblem(parseProblem(error))`.
- [ ] **Step 4:** Re-run — PASS.
- [ ] **Step 5: Commit** `feat(#2240): read and mark social notifications`.

---

### Task 4: The `notifications` selection kind

**Files:**
- Modify: `frontend/src/app/reader/query/query.ts`, `query/entry-query.ts`, `query/mark-read-target.ts`
- Modify: `frontend/src/app/reader/state/entries.store.ts` (`load`)
- Modify: `frontend/src/app/reader/shell/list-heading.service.ts`, `frontend/src/app/reader/list/list-header/list-header.component.{ts,html}`
- Modify (type ripple only): `frontend/src/app/reader/shell/list-reload.service.ts`, `refresh-actions.service.ts`, `mark-read-actions.service.ts`
- Test: `frontend/src/app/reader/query/query.spec.ts`, `entry-query.spec.ts`, `mark-read-target.spec.ts`, `frontend/src/app/reader/state/entries.store.spec.ts`, new `frontend/src/app/reader/list/list-header/list-header.component.spec.ts`

**Interfaces:**
- Produces:
  - `SelectionFields['notifications'] = { network: SocialNetworkName }` (no unread switch, no order).
  - `notificationsNetworkOf(selection: Selection): SocialNetworkName | null` (beside `trendingWindowOf`).
  - `offersRefresh(selection: Selection): boolean` = `canScopedRefresh(selection) || selection.kind === 'notifications'`.
  - `queryFromSelection(selection: Selection): EntryQuery | null` (`notifications` → `null`); `EntriesStore.load(query: EntryQuery | null)`.
  - Link: `selectionQueryParams({ view: 'notifications', network })`.

- [ ] **Step 1: Failing tests.**
  - `query.spec.ts`:
    - `reads a network's notifications`: `selectionFromParams(pm({ view: 'notifications', network: 'bluesky' }), ON).selection` equals `{ kind: 'notifications', network: 'bluesky' }`.
    - `reads notifications without a known network as all items`: `pm({ view: 'notifications' })` and `pm({ view: 'notifications', network: 'myspace' })` both → `kind: 'all'`.
    - `tells two networks' notifications apart`: `identityKey` is `'bluesky'`; `sameSelection` of bluesky vs mastodon is `false`; `hasUnreadFilter`, `hasListOrder`, `canScopedRefresh`, `offersMarkAboveRead`, `isSingleStreamView` are all `false`; `offersRefresh` is `true`; `listOrderKey` is `null`; `notificationsNetworkOf` gives `'bluesky'` (and `null` for `{kind:'all',unread:false}`).
  - `entry-query.spec.ts` `loads no entry list for notifications`: `queryFromSelection({ kind: 'notifications', network: 'lemmy' })` is `null`.
  - `mark-read-target.spec.ts`: add `{ kind: 'notifications', network: 'bluesky' }` → `null`.
  - `entries.store.spec.ts` `drops a load in flight when the selection has no entry list`: `load({view:'all'})`, then `load(null)` → the first request is cancelled, `loading()` false, `nextCursor()` null, and `reload()` sends nothing.
  - `list-header.component.spec.ts` (selection `{kind:'notifications', network:'bluesky'}`, title `'Notifications'`): `leads with the network logo and offers only refresh`: an `app-network-logo` is in the `h2`; `.refresh` exists; `.list-order`, `.unread-switch`, `.mark-all` and `.title-count` do not.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/query src/app/reader/state/entries.store.spec.ts src/app/reader/list/list-header` — FAIL (type errors count as failures).
- [ ] **Step 3: Implement.**
  - `query.ts`: the `SelectionFields` entry; `IDENTITY_KEY.notifications = (selection) => selection.network`; `OrderedSelection` and `hasListOrder` also exclude `notifications`; `offersMarkAboveRead` is false for it; `viewFromParams` handles `view === 'notifications'` before the state views: a known `network` param gives the selection, anything else falls through to `feedScopedSelection` with no URL correction.
  - `entry-query.ts`: `VIEW_QUERY: BySelectionKind<EntryQuery | null>` with `notifications: () => null`. `mark-read-target.ts`: `notifications: () => null`.
  - `EntriesStore.load(null)`: unsubscribe the list request, set `query` to `null`, `loading`/`loadingMore` false, `nextCursor` null, clear the failure; rows stay (the pane no longer shows them). The three shell callers pass the nullable query through unchanged.
  - `ListHeading`: `titleByKind.notifications = () => this.i18n.translate('reader.notifications.title')`; `countByKind.notifications = () => items(0)`; `lastRefreshedByKind.notifications = () => null`.
  - `ListHeaderComponent`: `LIST_ICON.notifications = null`; `titleNetwork` also answers for `notifications`; `canRefresh = computed(() => offersRefresh(this.selection()))`.
  - Add the `reader.notifications.title` row of the Copy table to all four dictionaries.
- [ ] **Step 4:** Re-run — PASS; also `docker compose exec -T frontend npm test -- src/app/reader/shell` stays green.
- [ ] **Step 5: Commit** `feat(#2240): add the notifications list to the reader's selections`.

---

### Task 5: Notification row

**Files:**
- Create: `frontend/src/app/reader/notifications/notification-line.ts`
- Create: `frontend/src/app/reader/notifications/notification-row/notification-row.component.{ts,html,scss}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`poll`, `unseen`, `who.*`, `action.*`)
- Test: `frontend/src/app/reader/notifications/notification-line.spec.ts`, `notification-row/notification-row.component.spec.ts`

**Interfaces:**
- Consumes: `NotificationGroupDto` (Task 3).
- Produces:
  - `interface LinePart { readonly text: string; readonly strong: boolean }`; `type Translate = (key: string, params?: Record<string, unknown>) => string`; `notificationLine(group: NotificationGroupDto, translate: Translate): readonly LinePart[]`; `NOTIFICATION_GLYPH: Readonly<Record<NotificationKind, string>>` = like `favorite`, repost `repeat`, reply `chat_bubble`, mention `alternate_email`, quote `format_quote`, follow `person_add`, poll `ballot`.
  - `NotificationRowComponent`, selector `app-notification-row`, inputs `group = input.required<NotificationGroupDto>()`, `showsAccount = input(false)`.

- [ ] **Step 1: Failing tests.**
  - `notification-line.spec.ts` (with a real English Transloco from `provideTranslocoTesting()`; join parts as `parts.map(p => p.strong ? `**${p.text}**` : p.text).join('')`):
    - `names one actor`: follow, 1 actor `LeVar Burton`, `actorCount 1` → `**LeVar Burton** followed you`.
    - `names two actors`: repost, `heise online`, `Laravel News`, `actorCount 2` → `**heise online** and **Laravel News** reposted your post`.
    - `names two and counts the rest`: like, 3 actors, `actorCount 7` → `**Hank Green**, **Jay Graber** and 5 others liked your post`; `actorCount 3` → `… and 1 other liked your post`.
    - `names an actor by handle when the display name is missing`: `displayName: null`, handle `@anna@social.example` → `**@anna@social.example** mentioned you`.
    - `says a poll ended with nobody named`: poll, no actors, `actorCount 0` → `A poll you voted in has ended` with no strong part.
    - `keeps a name containing the marker text intact`: a display name `a ** b` comes back as one strong part with that exact text.
  - `notification-row.component.spec.ts`:
    - `links out to the post in a new tab`: the `a` has `href` = `linkUrl`, `target="_blank"`, `rel="noopener noreferrer"`.
    - `draws the kind glyph filled in the kind's tone`: like → `app-icon` with name `favorite`, `fill` true, and the `.kind` element has class `like`.
    - `overlaps at most three avatars`: 4 actors given → 3 `app-avatar`, each `size` `md`, `toneSeed` = handle.
    - `shows the excerpt only when there is one`: `excerpt: null` → no `.excerpt`.
    - `marks an unseen row with a dot`: `seen:false` → a `.dot` plus an `.sr-only` "Unseen"; `seen:true` → neither; the row element never gets a background class.
    - `names the receiving account only when asked`: `showsAccount` true → `.account` text `@lars.bsky.social`; default → none.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/notifications/notification-line.spec.ts src/app/reader/notifications/notification-row` — FAIL.
- [ ] **Step 3: Implement.**
  - `notificationLine`: the bold names survive translation through a marker character the strings never contain. Shown names are `displayName ?? handle`:
    ```ts
    const MARK = '\u0000';
    if (group.kind === 'poll') return [{ text: translate('reader.notifications.poll'), strong: false }];
    const names = group.actors.map((actor) => actor.displayName ?? actor.handle);
    const named = Math.min(names.length, group.actorCount > 2 ? 2 : group.actorCount);
    const others = group.actorCount - named;
    const form = named === 1 ? 'one' : others === 0 ? 'two' : others === 1 ? 'twoAndOne' : 'twoAndOthers';
    const who = translate(`reader.notifications.who.${form}`, {
      first: MARK + names[0] + MARK, second: MARK + names[1] + MARK, count: others,
    });
    const number = group.actorCount > 1 ? 'other' : 'one';
    return translate(`reader.notifications.action.${group.kind}.${number}`, { who })
      .split(MARK)
      .map((text, index) => ({ text, strong: index % 2 === 1 }))
      .filter((part) => part.text !== '');
    ```
    (The backend sends up to three actors; a group of three or more names two and counts the rest, as the mockup does.)
  - Row component: injects `TranslocoService` and `LanguageService`; `parts = computed(() => { this.language.lang(); return notificationLine(this.group(), (key, params) => this.i18n.translate(key, params)); })`; `time = computed(() => relativeTimeNarrow(group.latestAt, lang))`; `avatars = computed(() => group.actors.slice(0, 3))`.
  - Template: `<a class="row" [href] target="_blank" rel="noopener noreferrer">` (a real link rather than `openExternal`, so middle-click and the status bar work) holding `.kind.<kind>` with `<app-icon [name] size="md" [fill]="true">`, `.body` (`.avatars` of `<app-avatar size="md" [url]="actor.avatarUrl" [name]="actor.displayName ?? actor.handle" [toneSeed]="actor.handle">`, `<p class="line">` of `@for` parts as `<b>` or text, `<p class="excerpt">`, `<p class="account">` when `showsAccount`), `.side` (`<span class="time">`, and when unseen `<span class="dot" aria-hidden="true">` + `<span class="sr-only">`).
  - SCSS, from the mockup: `.row` grid `var(--icon-lg) 1fr auto`, `column-gap: var(--space-3)`, comfortable padding, `border-bottom: 1px solid var(--border)`, `color: inherit; text-decoration: none`, hover `--surface-sunken` on `(hover: hover)`; `.kind` `place-items: start center; padding-top: var(--space-0)`, tones `like` `--danger`, `repost` `--success`, `reply|mention|quote|follow` `--accent`, `poll` `--warning`; `.body` column gap `--space-1`, `min-width: 0`; `.avatars app-avatar { box-shadow: 0 0 0 var(--space-0) var(--surface-0) }`, `+ app-avatar { margin-left: calc(var(--space-2) * -1) }`; `.line` `--fs-base`, `line-height: 1.35`, `--text-primary`, `b` 600; `.excerpt` `--text-secondary` `--fs-sm` `line-height: 1.4`, two-line clamp (`display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden`); `.account` `--text-muted` `--fs-xs`; `.side` column, `align-items: flex-end`, gap `--space-2`; `.time` `--text-muted` `--fs-xs` nowrap; `.dot` `width/height: var(--space-2)`, `border-radius: var(--radius-pill)`, `background: var(--accent)`. No tint on unseen rows.
- [ ] **Step 4:** Re-run — PASS.
- [ ] **Step 5: Commit** `feat(#2240): draw a grouped notification row`.

---

### Task 6: Notification list in the reader's list pane

**Files:**
- Create: `frontend/src/app/reader/notifications/notification-list/notification-list.component.{ts,html,scss}`
- Create: `frontend/src/app/theme/_list-chrome.scss`; Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.scss` (adopt it, no visual change)
- Modify: `frontend/src/app/reader/shell/reader-shell.component.{html,ts}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`loading`, `markAllSeen`, `end`, `empty`, `scopeMissing`, `needsReconnect`, `reconnect`)
- Modify: `docs/design-language.md` (§3: a "Notifications list" entry after *Sidebar cards*)
- Test: `frontend/src/app/reader/notifications/notification-list/notification-list.component.spec.ts`, `frontend/src/app/reader/shell/reader-shell.component.spec.ts`

**Interfaces:**
- Consumes: `NotificationsStore` (Task 3), `NotificationRowComponent` (Task 5), `notificationsNetworkOf` (Task 4), `SubscriptionsStore.socialSections()`, `ListHeaderComponent`, `ScrollHosts.forPage`, `PageLayer` (optional inject), `prefetchMargin` (`reader/list/paging.ts`), `observeResize`.
- Produces: `NotificationListComponent`, selector `app-notification-list`, inputs `selection = input.required<SelectionOf<'notifications'>>()`, `title = input.required<string>()`, `headerLeading = input<TemplateRef<unknown> | null>(null)`. `_list-chrome.scss`: `$header-clear` (moved from entry-list) and `@mixin floating-header` (the body of entry-list's `.list-header` rule plus its `bp.$bp-lg` `min-block-size`/`padding-block` block).

- [ ] **Step 1: Failing tests.**
  - `notification-list.component.spec.ts` (real `NotificationsStore` over HttpTestingController; `IntersectionObserver` from `testing/intersection-observer-testing`; `SubscriptionsStore` stub with `socialSections: signal([])` and `reloadCountsQuietly: jest.fn()`):
    - `opens the network's stored notifications`: selection bluesky → one GET `…/api/me/notifications?network=bluesky`; after flushing two groups, two `app-notification-row`.
    - `reopens when the network changes`: set selection to mastodon → a GET for mastodon.
    - `offers refresh and mark all seen, nothing else`: header has `.refresh` and `.mark-seen` (`aria-label` "Mark all seen", icon `done_all`), no `.list-order`/`.unread-switch`; `.mark-seen` is disabled when every group is seen.
    - `refresh reads the stored list again`: click `.refresh` → a second GET, no request to any refresh endpoint.
    - `marks all seen from the header`: click `.mark-seen` → POST body `{network:'bluesky', upTo:<first id>}`; after 204 no `.dot` remains.
    - `shows a reconnect hint per problem`: page with `scopeMissing:['@a']`, `needsReconnect:['@a']` → two `app-warning-box`, texts "Bluesky needs permission to read your notifications." and "Bluesky needs reconnecting before new notifications arrive.", each with an `a` to `/settings/social` reading "Reconnect Bluesky".
    - `ends with the 30-day line once nothing more is out there`: `nextCursor: null` → `.end` "That's everything from the last 30 days."; `nextCursor: 5` → no `.end`, a `.foot` sentinel.
    - `loads the next page when the sentinel nears`: trigger the observer intersecting → a GET with `cursor=5`.
    - `says so when there is nothing`: empty page → `.empty` "No notifications from the last 30 days." and no `.end`.
    - `names the receiving account when the network has several`: a section for bluesky with two `networkAccounts` → rows get `showsAccount` true.
  - `reader-shell.component.spec.ts` `hosts the notification list for a notifications selection`: route `?view=notifications&network=bluesky` → an `app-notification-list`, no `app-entry-list`, and no `/api/entries` request.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/notifications/notification-list src/app/reader/shell/reader-shell.component.spec.ts` — FAIL.
- [ ] **Step 3: Implement.**
  - Extract `_list-chrome.scss` and switch entry-list to `@use '../../../theme/list-chrome'` (`.list-header { @include list-chrome.floating-header; }`, `list-chrome.$header-clear` at its five uses). No rendered change.
  - Component: an `effect` calls `store.open(selection().network)` untracked. A `.list-frame` (`page-chrome.list-column-box`, `pointer-events: none`, `z-index: 2`) holds `<header class="list-header" #listHdr>` (`floating-header`, `pointer-events: auto`) with `<app-list-header [selection] [title] [headerLeading] [headerActions]="markSeen" [refreshing]="store.loading()" (refresh)="refresh()">`, and the error banner as entry-list shows it (retry → `store.reload()`, dismiss → `store.dismissError()`). `refresh()` = `store.reload()` + `subscriptions.reloadCountsQuietly()`. The `#markSeen` template: `<button appListAction class="mark-seen" [disabled]="!store.hasUnseen() || store.marking()">` with `<app-icon name="done_all" size="sm" />` and `<span class="txt">`. The header's height is published as `--list-bar-h` on the host via `observeResize` (entry-list's measure, without the collapse guard: this header never collapses).
  - `.rows` (`#rows`, `padding-top: list-chrome.$header-clear`, `min-height: calc(100dvh - var(--audio-bar-h, 0rem))`, bottom room for the corner cluster as entry-list's `$corner-clear`): hints first (`app-warning-box` with `margin: var(--space-3) var(--space-4) var(--space-1)`, `<app-icon name="warning" size="sm">` in `--warning`, `--fs-sm` text, the link `--accent` 600 nowrap via `routerLink="/settings/social"`), then `<app-skeleton [label]="'reader.notifications.loading' | transloco">` while loading an empty list, rows, then `.empty`, `.end` (`padding: var(--space-5) var(--space-4)`, centred, `--text-muted`, `--fs-sm`), or the `.foot` sentinel while `nextCursor()` is set. The hint's network name is `settings.social.<network>`.
  - Paging: `scrollHost = computed(() => rows ? scrollHosts.forPage(rows, pageLayer) : undefined)`; an `IntersectionObserver` on `.foot` with `root: scroller.intersectionRoot`, `rootMargin: prefetchMargin(scroller.clientHeight)` calls `store.loadMore()` (entry-list's `_wire` effect, minus `hasMore` which the sentinel's presence already says).
  - `showsAccount = computed(() => (section for this network)?.networkAccounts.length > 1)`.
  - Shell: `readonly notificationsSelection = computed(() => { const selection = this.selection(); return selection.kind === 'notifications' ? selection : null; })`; inside `#entryListPane`'s `<section class="list">`, `@if (notificationsSelection(); as current)` renders `<app-notification-list [selection]="current" [title]="heading.title()" [headerLeading]="sidebarShow" />`, `@else` the existing `<app-entry-list>`. The reader pane and its placeholder stay as they are.
  - Doc: list anatomy (the list header with only Refresh and Mark all seen, rows `icon-lg` · body · side, comfortable density, unseen = 8px accent dot under the time and never a tint, hints as `app-warning-box`, the 30-day end line, links open the network in a new tab, the list reads only what is stored).
- [ ] **Step 4:** Re-run — PASS; then `docker compose exec -T frontend npm test -- src/app/reader/list/entry-list` stays green.
- [ ] **Step 5: Commit** `refactor(#2240): share the floating list header styles` (the extraction alone), then `feat(#2240): list a network's notifications in the reader`.

---

### Task 7: Sidebar Notifications row

**Files:**
- Create: `frontend/src/app/reader/shell/sidebar/sidebar-notifications-row.component.{ts,html,scss}`
- Modify: `frontend/src/app/reader/state/social-sections.ts` (`SocialSection`, `buildSocialSections`), `frontend/src/app/reader/state/subscriptions.store.ts` (`socialSections` passes the counts)
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html}`
- Modify: `docs/design-language.md` (§3 *Sidebar cards*: the Notifications row)
- Test: `frontend/src/app/reader/state/social-sections.spec.ts`, `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts`

**Interfaces:**
- Consumes: `NotificationCounts` (Task 1), `CountPillComponent` (Task 2), `selectionQueryParams`/`notificationsNetworkOf` (Task 4).
- Produces: `SocialSection.notificationsUnseen: number`; `buildSocialSections(subscriptions, socialAccounts, totals, notifications: NotificationCounts)`; `SidebarNotificationsRowComponent`, selector `app-sidebar-notifications-row`, inputs `network = input.required<SocialNetworkName>()`, `count = input(0)`, `active = input(false)`; host classes `current` from `active` (feed-row look).

- [ ] **Step 1: Failing tests.**
  - `social-sections.spec.ts` `carries each network's unseen notifications, apart from its unread count`: counts `{bluesky: 4}` → bluesky section `notificationsUnseen` `4`, `unreadCount` unchanged; mastodon section `0`.
  - `sidebar-social-section.component.spec.ts` (add `notificationsUnseen` to `ONE_ACCOUNT` and every literal built from it):
    - `leads the rows with Notifications`: `.rows` first child contains `app-sidebar-notifications-row` with text "Notifications", an `app-icon` named `notifications`, a link whose query params are `{ view: 'notifications', network: 'bluesky', …nulls }`, an empty `.slot` and a `.trail`, and no `app-sidebar-row-menu` or chevron inside the row.
    - `shows the unseen count as a pill`: `notificationsUnseen: 4` → `app-count-pill` text `4`.
    - `keeps the row without a pill at zero`: `0` → row present, no `app-count-pill`.
    - `leaves the head's count alone`: `unreadCount: 3, notificationsUnseen: 4` → `.section-count` text `3`.
    - `marks the row current for this network's notifications`: selection `{kind:'notifications', network:'bluesky'}` → the row host has class `current`; `{…, network:'mastodon'}` → not.
    - `offers no Notifications row without a connected account`: `networkAccounts: []` → no `app-sidebar-notifications-row`.
    - `hides the row while organising`: `organising` true → none.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/state/social-sections.spec.ts src/app/reader/shell/sidebar` — FAIL.
- [ ] **Step 3: Implement.**
  - `buildSocialSections` sets `notificationsUnseen: notifications.get(network) ?? 0`; the store's `socialSections` passes `this.notificationCounts()`.
  - Row template: `<a class="nav" [class.active]="active()" [routerLink]="['/']" [queryParams]="selectionQueryParams({ view: 'notifications', network: network() })" queryParamsHandling="merge">` → `<span class="lead favicon-slot"><app-icon name="notifications" size="md" /></span>`, `<span class="name">{{ 'reader.notifications.title' | transloco }}</span>`, `@if (count() > 0) { <app-count-pill [count]="count()" /> }`; then `<span class="slot" aria-hidden="true"></span><span class="trail" aria-hidden="true"></span>`.
  - Row SCSS: `@use 'sidebar-row' as row;` with `row.row-parts`, `row.row-link('.nav')`, `row.row-states(':host', ':host(.current)')`, `:host { @include row.row-geometry; }`, `.favicon-slot { margin-inline: var(--space-0); }` (as the feed row), and `app-count-pill { flex: none; margin-left: auto; margin-right: calc(var(--space-2) * -1); }` — the pull that lines the digit up with the card's other counts.
  - Section: first in `.rows`, `@if (!organising() && current.networkAccounts.length > 0)` → `<div class="feedrow"><app-sidebar-notifications-row [network]="current.network" [count]="current.notificationsUnseen" [active]="notificationsActive()" /></div>`; `notificationsActive = computed(() => notificationsNetworkOf(this.selection()) === this.section().network)`. An account that needs reconnecting still counts as connected here: the list then explains it with its hint.
  - Doc: the row's anatomy (bell lead, no ⋯/chevron: `.slot` + `.trail`, the count pill pulled right so its digit aligns, no pill at 0, not added to the head's count, only with an account on the network).
- [ ] **Step 4:** Re-run — PASS.
- [ ] **Step 5: Commit** `feat(#2240): add a Notifications row to each network's sidebar card`.

---

### Task 8: Avatar badge and account menu redesign

**Files:**
- Modify: `frontend/src/app/reader/shell/header/reader-header.component.{ts,html,scss}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`reader.signedIn`, `reader.accountWithNotificationsOne/Other`)
- Modify: `docs/design-language.md` (§3: "Account menu and avatar badge")
- Test: `frontend/src/app/reader/shell/header/reader-header.component.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionsStore.unseenNotifications` / `.unseenNotificationsTotal` (Task 1), `CountPillComponent`, `NetworkLogoComponent`, `selectionQueryParams` (Task 4).
- Produces: `ReaderHeaderComponent.badgeText = computed(() => total > 99 ? '99+' : String(total))`, `accountLabel` computed (key `reader.account` at 0, else the One/Other key with `count`).

- [ ] **Step 1: Failing tests** (provide `{ provide: SubscriptionsStore, useValue: { unseenNotifications: signal([...]), unseenNotificationsTotal: signal(n) } }`):
  - `badges the avatar with the unseen total`: total 6 → `.account > button .badge` text `6`, `aria-hidden="true"`; the button's `aria-label` is "Account, 6 unseen notifications".
  - `shows no badge without unseen notifications`: total 0 → no `.badge`, `aria-label` "Account".
  - `caps the badge at 99+`: total 140 → badge `99+`, label says 140.
  - `heads the menu with the signed-in account`: open → `.who-email` `a@b.c`, `.who-sub` "Signed in".
  - `lists the networks with unseen notifications`: unseen `[{bluesky,4},{mastodon,2}]` → a `[role=group]` labelled "Notifications" holding a `.head` "Notifications" and two `a[role=menuitem]` with `app-network-logo`, names "Bluesky"/"Mastodon", `app-count-pill` `4`/`2`, links with `view=notifications&network=bluesky`; clicking one closes the menu.
  - `leaves the notifications out when nothing is unseen`: `[]` → no `[role=group]`, no `.head`.
  - `keeps settings, admin and sign out with their icons`: icons `settings`, `admin_panel_settings` (admin only, with `isAdmin: () => true`), `logout`; the sign-out item has class `signout`; every item keeps `role="menuitem"` and the menu `role="menu"`.
- [ ] **Step 2:** `docker compose exec -T frontend npm test -- src/app/reader/shell/header` — FAIL.
- [ ] **Step 3: Implement** (markup order and values from the approved mockup).
  - Button: wrap `<app-user-avatar [size]="26">` in `<span class="avatar">` (relative) with `@if (total > 0) <span class="badge" aria-hidden="true">{{ badgeText() }}</span>`; `[attr.aria-label]="accountLabel()"`.
  - Menu: `.who` (avatar 32 · `.who-text` column: `.who-email` `--fs-sm` 600 ellipsis, `.who-sub` `--fs-xs` `--text-muted`, gap `--space-0`; `padding: var(--space-3)`, gap `--space-3`), `<hr role="separator">`; when `unseenNotifications().length`, `<div role="group" [attr.aria-label]>` with `<p class="head" aria-hidden="true">` and one `a.item[role=menuitem]` per network (`[routerLink]="['/']"`, `[queryParams]="selectionQueryParams({ view: 'notifications', network })"`, `queryParamsHandling="merge"`, `(click)="menuOpen.set(false)"`: `<app-network-logo>` · `.label` · `<app-count-pill>`), then `<hr role="separator">`; Settings (`settings`), Admin (`admin_panel_settings`, admins only), `<hr role="separator">`, Sign out button `.item.signout` (`logout`). Each item: `<app-icon size="md">` + `<span class="label">`. The `DismissOnOutside` wiring and `aria-haspopup`/`aria-expanded` stay.
  - SCSS: `.menu` keeps `glass-rim.filled(var(--surface-2))`, `right: 0`, `z-index: 30`, `top: 46px` (positioning offset, as its comment says), `width: 288px` (escape hatch: tuned menu measure), `max-width: calc(100vw - var(--space-6))`, `padding: var(--space-1)`, `border-radius: var(--radius-lg)`, `box-shadow: 0 12px 32px rgb(0 0 0 / 16%), 0 2px 6px rgb(0 0 0 / 8%)`; drop the old `min-width`/`max-width` and the `.who` bottom border. `hr` `border: none; border-top: 1px solid var(--border); margin: var(--space-1) var(--space-2)`. `.head` `padding: var(--space-2) var(--space-3) var(--space-1)`, `--fs-sm`, 600, `--text-secondary`. `.item` flex, `gap: var(--space-3)`, `width: 100%`, `min-height: var(--control-h)` (`var(--tap-target)` and `--fs-base` under `@media (pointer: coarse)`), `padding: 0 var(--space-3)`, `border-radius: var(--radius)`, `--text-primary`, `font: inherit; font-size: var(--fs-sm)`, no background/border, hover `--surface-sunken`; `.item app-icon` `--text-secondary`; `.item app-network-logo` `--icon-md` square, `flex: none`; `.label { flex: 1; text-align: left }`; `.signout` `--text-secondary`. Badge: `position: absolute; top: calc(var(--space-0) * -1); left: calc(var(--icon-md) - var(--space-0)); min-width: var(--icon-sm); height: var(--icon-sm); padding: 0 var(--space-1); box-sizing: border-box; border-radius: var(--radius-pill); background: var(--accent); color: var(--surface-0); font-weight: 700; line-height: 1; display: grid; place-items: center; box-shadow: 0 0 0 var(--space-0) var(--surface-0)`, and `font-size: 10px` under the escape hatch ("the numeral fits a 16px disc; --fs-xs overflows it at two digits").
  - Doc: the menu's anatomy and the badge (accent disc, `--surface-0` ring, hidden at 0, `99+` cap), and that the menu's Notifications rows reuse `<app-count-pill>`.
- [ ] **Step 4:** Re-run — PASS.
- [ ] **Step 5: Commit** `feat(#2240): badge the avatar and redesign the account menu`.

---

### Task 9: Playwright smoke

**Files:**
- Create: `frontend/e2e/social-notifications.spec.ts`

**Interfaces:**
- Consumes: `stubOneFeedReader`, `subscriptionsJson`, `presetLocalStorage` (`e2e/support`), `aSubscription` (`src/testing/subscription-testing`), the types from Tasks 1 and 3.

- [ ] **Step 1: Write the spec.** Every API read is stubbed after `stubOneFeedReader(page, 'Fixture feed')`; the spec owns all its data. Fixture: a Bluesky Home (`accountId 1`, unread 41), a Mastodon Home (`accountId 2`), a Lemmy Subscribed (`accountId 3`), three connected `socialAccounts`, `unreadTotals.networks` bluesky 51; `notifications: [{bluesky,4},{mastodon,2}]` (Lemmy absent). Stubs: `/api/subscriptions` (that body), `/api/subscriptions/counts` (counts derived from the same fixture, returning the post-mark counts once the `/seen` POST has been seen), `GET /api/me/notifications` per `network` (Bluesky: four groups ids 40, 31, 22, 13 — like with 3 actors and `actorCount 7`, follow, reply, repost — two unseen, `nextCursor: null`), `POST /api/me/notifications/seen` → 204 recording the JSON body, `**/api/*/excluded-networks` → `{ excludedNetworks: [] }`, `**/api/client-errors` → 204. Desktop 1280×860, `sfr.layout: 'pane'`, `sfr.lang: 'en'`.
  - `the network card leads with Notifications, its pill aligned with the counts`: the Bluesky card's first row reads "Notifications" with `app-count-pill` "4"; Lemmy's row has no pill; the Bluesky head count is still "51". Alignment: the right edge of the pill's text (a `Range` over its text node, `getBoundingClientRect().right`) equals the right edge of the Home row's `.count` text within 1px.
  - `the list shows the grouped notifications`: click the Bluesky Notifications row → URL has `view=notifications` and `network=bluesky`; the list header shows the Bluesky logo and "Notifications", has `.refresh` and `.mark-seen`, no `.list-order`/`.unread-switch`; 4 `app-notification-row`; the first line reads "Hank Green, Jay Graber and 5 others liked your post"; 2 `.dot`; the first row's link has `target="_blank"`; `.end` reads "That's everything from the last 30 days."
  - `Mark all seen posts the newest shown position and clears the dots`: click `.mark-seen` → the recorded body is `{ network: 'bluesky', upTo: 40 }`; `.dot` count 0; the Bluesky row's `app-count-pill` disappears after the quiet counts reload.
  - `the account menu lists only networks with unseen notifications`: open `.account > button` → a group "Notifications" with Bluesky "4" and Mastodon "2", no Lemmy; clicking Mastodon lands on `network=mastodon` and closes the menu. With every count 0 (a second test overriding `/api/subscriptions`), the menu has no Notifications group.
  - `the avatar carries the unseen total`: `.account .badge` reads "6"; with every count 0, no `.badge`.
- [ ] **Step 2:** `cd frontend && npm run e2e -- social-notifications` with the Docker stack up from this checkout — PASS. (Written after Tasks 1–8, so it is the end-to-end proof rather than a red-first test.)
- [ ] **Step 3: Commit** `test(#2240): smoke the notifications sidebar, list, menu and badge`.

---

### Task 10: Gate

- [ ] **Step 1:** `docker compose exec -T frontend npm run check` — ESLint, Prettier, Stylelint, tsc (app, spec, e2e) and Jest all green.
- [ ] **Step 2:** `cd frontend && npm run e2e -- social-notifications list-header reader-smoke sidebar` — green (the entry-list chrome extraction must not move the list header).
- [ ] **Step 3:** Compare the running app at 1280×860 light/dark and at 390×760 against `desktop-*.png`, `desktop-hint-*.png`, `desktop-menu-*.png`, `phone-list-*.png`, `phone-sidebar-*.png`, `phone-menu-*.png`; fix any drift in the owning task's files and commit `fix(#2240): …`.

---

**Notes on open points**
- `needsReconnect` hint copy is not in the spec; the Copy table's wording is this plan's choice.
- The hints name the network, not the account handles the API returns; with several accounts on a network the user learns which one from Settings.
- An empty list says `empty` rather than the 30-day end line; the spec names only the end line.
- German keeps "Benachrichtigungen" (no natural loanword) but "geliked"/"repostet".
