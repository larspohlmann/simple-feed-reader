# Crosspost Pills Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The share-menu composer gets Bluesky and Mastodon pills that send one shared text to both networks, with per-network failure, retry and duplicate protection.

**Architecture:** Backend: a Mastodon link post's context drops the URL from the text and nets it out of the limit, `MastodonPostWriter::share` appends it, the link endpoint accepts blank text, and Bluesky posts get a remembered TID record key per idempotency key. Frontend: the composer's per-network state moves into `ComposerLane` objects held by a dialog-scoped `ComposerLanes`. Replies and quotes are the one-lane case. Link posts get a lane per connected network, with pills.

**Tech Stack:** Symfony 8.1 / PHP 8.5, PHPUnit, Infection; Angular 20 signals, CDK Dialog, Transloco, Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-17-crosspost-pills-design.md` (read it first; decision numbers below refer to it).

## Global Constraints

- Branch `feature/2287-crosspost-pills`. Commits `type(#2287): …`. No Claude attribution.
- All CLAUDE.md PHP rules: `final readonly`, typed exceptions in `Exception/`, static helpers in `Support/`, thin controllers, no PHPStan rule edits or ignores, PHPMD-clean touched `src` files, `Response::HTTP_*` constants.
- Frontend: signals, standalone, sibling `.scss`, no hex/px literals outside theme, i18n in en, de, fr, es for every new key.
- Endpoints and JSON shapes unchanged; no migration.
- Mastodon link length: 23 characters per link; the link and its blank-line gap cost 25 characters.
- Bluesky `MAX_GRAPHEMES` stays 300.
- Replies and Repost → Quote composers must look and behave exactly as today. The existing `composer-dialog.component.spec.ts` cases are the regression net; edit them only where the plan says to.
- Run Jest only in the Docker frontend container: `docker compose exec -T frontend npx jest <path>`.

## Review Focus

1. **Account switch on one lane while the other lane is sent or loading.** Reloading that lane must not reset the other lane's sent lock or cancel its load. Owner: Task 6.
2. **Second pill switched off while its context is still loading.** The load is cancelled, and a late answer never re-activates the lane or shows its controls. Owner: Task 6.
3. **Pills during a send.** All pills, the account selects and Clear are disabled while any request is on the wire. No lane can be toggled mid-send. Owner: Task 7.
4. **Retry reuses the lane's idempotency key.** A retry after a partial failure sends the failed lane's original key, never a new one, and never re-sends the succeeded lane. Owner: Task 7.
5. **Mastodon switched off with a content warning typed.** The warning and visibility controls hide, and Bluesky's draft carries none. Switching Mastodon back on shows the earlier values. Owner: Task 6.

---

### Task 1: Mastodon link posts carry the URL outside the text

**Files:**
- Modify: `backend/src/Service/Social/Social/Support/ComposerStartingText.php`
- Modify: `backend/src/Service/Social/Social/ComposerContexts.php` (`ofLink`)
- Modify: `backend/src/Service/Social/Social/Model/ComposerDefaultsModel.php` (add a wither if none fits)
- Modify: `backend/src/Service/Social/Social/PostWriter/MastodonPostWriter.php` (`share`)
- Test: `backend/tests/Service/Social/Social/Support/ComposerStartingTextTest.php`, `backend/tests/Service/Social/Social/PostWriter/MastodonPostWriterTest.php`, `backend/tests/Controller/Api/SocialLinkPostControllerTest.php`

**Interfaces:**
- Produces: `ComposerStartingText::LINK_ROOM` (public `int` const = 25). `ComposerDefaultsModel::withMaxCharacters(int $maxCharacters): self` (`#[\NoDiscard]`, `clone`), used only by `ofLink`.

- [ ] **Step 1: Write the failing tests**
  - `ComposerStartingTextTest`: `testMastodonLinkStartsWithTheTitleAlone` (`sharing(Mastodon, link('https://e.x/a', 'Title', ''), 475)` === `'Title'`). `testMastodonLinkWithoutTitleStartsEmpty` (=== `''`). `testMastodonQuoteStartsWithTheClippedQuoteAlone`: a 600-char quote with max 475 gives a string without `https://` whose `mb_strlen` ≤ 475. Delete or adjust the existing cases that expect `"\n\n" . url`.
  - `MastodonPostWriterTest`: `testShareAppendsTheUrlAfterABlankLine` (status `"Title\n\nhttps://e.x/a"`). `testShareOfBlankTextPostsTheUrlAlone` (status `'https://e.x/a'`). `testShareKeepsTextThatAlreadyCarriesTheUrl`: the text `"Title\n\nhttps://e.x/a"` is sent unchanged, with the URL once. `testReplyAndQuoteAppendNothing`.
  - `SocialLinkPostControllerTest` (link-composer on a Mastodon account whose instance limit is 500): `maxCharacters` is `475` and `text` has no URL. A Mastodon *quoted* link (a Mastodon post URL) still answers `500`.
- [ ] **Step 2: Run them; expect FAIL**: `php bin/phpunit --filter 'ComposerStartingTextTest|MastodonPostWriterTest|SocialLinkPostControllerTest'`
- [ ] **Step 3: Implement**
  - `sharing()` returns the title, or `quoting($quote, $maxCharacters)`, for every network. The selection is clipped by the caller's already-netted limit.
  - `ofLink` nets `LINK_ROOM` off Mastodon's defaults before both the context and `sharing()`. Bluesky is untouched.
  - `share()` builds the status text as follows: the URL alone if the text is blank; the text unchanged if `str_contains($text, $url)`; else `$text . "\n\n" . $url`. Pass it through a new draft copy rather than mutating.
- [ ] **Step 4: Run them; expect PASS**, then `composer stan` and `composer md` on the touched files.
- [ ] **Step 5: Commit** `feat(#2287): Mastodon link posts carry their URL outside the composer text`

### Task 2: Link posts may go out with blank text

**Files:**
- Create: `backend/src/Dto/Social/LinkPostDraftRequest.php` (the same fields as `PostDraftRequest`, `text` with `Length(max: 10000)` but no `NotBlank`, with `toDraft()`)
- Create: `backend/src/Service/Social/Social/Exception/SocialPostTextMissingException.php`
- Modify: `backend/src/Controller/Api/SocialLinkPostController.php` (`link` maps `LinkPostDraftRequest`)
- Modify: `backend/src/Service/Social/Social/LinkPosts.php` (`share`)
- Modify: `backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`
- Test: `backend/tests/Controller/Api/SocialLinkPostControllerTest.php`, `backend/tests/Service/Social/Social/LinkPostsTest.php`, `backend/tests/Service/Social/Social/PostWriter/BlueskyPostWriterTest.php`

**Interfaces:**
- Produces: `SocialPostTextMissingException`, mapped to `ApiProblem('social_post_text_missing', 'The post needs some text', Response::HTTP_UNPROCESSABLE_ENTITY)`.

- [ ] **Step 1: Write the failing tests**
  - Controller: `testBlankTextLinkPostIsPublishedOnBluesky` (201; the record's `text` is `''` and the external embed is present). `testBlankTextLinkPostIsPublishedOnMastodon` (201; the status is the URL alone). `testBlankTextOnAQuotedLinkIsRefused` (a Bluesky post URL on Bluesky with text `'  '` → 422 `social_post_text_missing`). `testBlankReplyIsStillRefused` (the entry reply endpoint with `''` → 422, as today).
  - `LinkPostsTest::testBlankTextQuoteThrows`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement.** `LinkPosts::share` throws `SocialPostTextMissingException` when it would quote and `'' === trim($draft->text)`. `BlueskyPostWriter` already accepts `''`; check the lexicon (`app.bsky.feed.post`: `text` is required, with no minimum length) and add nothing if the test passes.
- [ ] **Step 4: Run; expect PASS**, plus `composer stan`.
- [ ] **Step 5: Commit** `feat(#2287): a shared link may go out with blank text; a quote still needs some`

### Task 3: Bluesky honours the idempotency key

**Files:**
- Create: `backend/src/Service/Social/Social/Support/BlueskyTid.php`
- Create: `backend/src/Service/Social/Social/BlueskyPostKeys.php`
- Modify: `backend/src/Service/Social/Social/BlueskyRecords.php` (`createPost`, new `findPost`)
- Modify: `backend/src/Service/Social/Social/PostWriter/BlueskyPostWriter.php` (`published`)
- Modify: `backend/config/packages/cache.yaml` (pool `social.bluesky_post_keys.cache`: filesystem, `default_lifetime: 86400`; array adapter under `when@test`)
- Test: `backend/tests/Service/Social/Social/Support/BlueskyTidTest.php`, `backend/tests/Service/Social/Social/BlueskyPostKeysTest.php`, `backend/tests/Service/Social/Social/PostWriter/BlueskyPostWriterTest.php`

**Interfaces:**
- Produces:
  - `BlueskyTid::at(\DateTimeImmutable $moment, int $clockId): string`: 13 characters of base32-sortable (`234567abcdefghijklmnopqrstuvwxyz`). It encodes a 64-bit value: top bit 0, 53 bits of microseconds since the Unix epoch, then 10 bits of `$clockId % 1024`.
  - `BlueskyPostKeys::recordKeyFor(SocialAccount $account, string $idempotencyKey): BlueskyPostKey`. `BlueskyPostKey` lives in `Social/Social/Model/` and is `final readonly` with `string $recordKey` and `bool $seenBefore`. The method mints a TID from `NaiveUtcClock` now and `crc32($idempotencyKey)` on first use and remembers it, keyed by `account id + key`. Later calls return the same TID with `seenBefore: true`.
  - `BlueskyRecords::createPost(SocialAccount $account, array $fields, ?string $recordKey): string` sends `rkey` only when it isn't null. Update the other callers to pass `null`.
  - `BlueskyRecords::findPost(SocialAccount $account, string $recordKey): ?string` calls `com.atproto.repo.getRecord` and returns the AT URI, or null when the record doesn't exist.

- [ ] **Step 1: Check the duplicate behaviour.** Read the reference PDS (`bluesky-social/atproto`, `packages/pds/src/repo/prepare.ts` and the repo transactor's `processWrites`). Record two things in the commit message: the error a create on an existing rkey returns, and what `getRecord` answers for a missing key (status and `error` name). Don't post to a live account.
- [ ] **Step 2: Write the failing tests**
  - `BlueskyTidTest`: `testEncodesAKnownMoment`. Compute the expected value for `2026-11-17T12:00:00.000000Z`, clock id 0, by hand from the bit layout and pin it. `testIsThirteenSortableCharacters` (regex `^[234567a-z]{13}$` and first char in `234567abcdefghij`). `testLaterMomentsSortAfter`.
  - `BlueskyPostKeysTest`: `testSameKeyYieldsTheSameRecordKey` (second call `seenBefore: true`). `testOtherAccountOrKeyYieldsAnother`.
  - `BlueskyPostWriterTest`: `testFirstSendCreatesUnderTheRememberedKey` (`createRecord` body has `rkey`; no `getRecord` call). `testRetryReturnsThePostAlreadyCreated` (`seenBefore`; `getRecord` finds it; no `createRecord`; it answers that URI and its web URL). `testRetryCreatesWhenTheFirstAttemptNeverLanded` (`seenBefore`; `getRecord` → null; `createRecord` with the same rkey). `testRaceOnCreateResolvesToTheExistingPost` (`createRecord` throws `SocialPostRefusedException`, `getRecord` then finds it → success). `testWithoutAKeyNothingChanges` (no `rkey`, no lookup).
- [ ] **Step 3: Run; expect FAIL.**
- [ ] **Step 4: Implement** `BlueskyTid`, `BlueskyPostKey`, `BlueskyPostKeys` (inject the pool by its `#[Target]` name), `findPost`, and the writer flow from the tests. The flow is: lookup only when `seenBefore`, create with the rkey, and on `SocialPostRefusedException` look up once more and rethrow if nothing is there.
- [ ] **Step 5: Run; expect PASS**, plus `composer check`, `composer md`.
- [ ] **Step 6: Commit** `feat(#2287): a Bluesky post goes out once per idempotency key`

### Task 4: Composer state moves into lanes (no behaviour change)

**Files:**
- Create: `frontend/src/app/reader/article/composer/composer-lane.ts`
- Create: `frontend/src/app/reader/article/composer/composer-lanes.ts`
- Modify: `frontend/src/app/reader/article/composer/composer-dialog.component.{ts,html}`
- Test: `frontend/src/app/reader/article/composer/composer-lanes.spec.ts`. The existing `composer-dialog.component.spec.ts` must stay green unchanged.

**Interfaces:**
- Produces:
  - `class ComposerLane` (a per-call object, built with `new` by `ComposerLanes`), constructed from `(target: PostTargetDto, api: SocialPostApi, i18n: TranslocoService)`. Members:
    - `readonly network: SocialNetworkName`, `readonly target: PostTargetDto`, `readonly idempotencyKey: string` (minted once in the constructor)
    - `readonly state: Signal<ContextState>`, `readonly context: Signal<ComposerContextDto | null>`, `readonly loadError: Signal<string | null>`
    - `readonly accountId: Signal<number | undefined>`, `readonly visibility: WritableSignal<MastodonVisibility | null>`
    - `readonly sent: Signal<SentPost | null>`, `readonly sendError: Signal<string | null>`
    - `load(): void` (cancels the load still on the wire), `chooseAccount(id: number): void`, `chooseVisibility(value: MastodonVisibility): void`
    - `send(draft: Omit<PostDraftDto, 'accountId' | 'idempotencyKey' | 'visibility'>, text: string): Observable<SentPost>`: adds its own `accountId`, key and visibility, records `sent`/`sendError`, and errors on failure
    - `dispose(): void`
  - `@Injectable() class ComposerLanes` (in the dialog's `providers`): `init(target: PostTargetDto): void`, `readonly lanes: Signal<readonly ComposerLane[]>`, `readonly primary: Signal<ComposerLane>` (the lane whose subject and starting text the dialog shows). It disposes its lanes on destroy.
  - `ContextState` moves to `composer-lane.ts`.

- [ ] **Step 1: Write `composer-lanes.spec.ts`**: `loads the target's context on init`, `a newer load drops the one on the wire`, `choosing an account reloads with it`, `send carries the key minted once, across retries`, `a failed send keeps sent null and sets sendError`.
- [ ] **Step 2: Run; expect FAIL**: `docker compose exec -T frontend npx jest src/app/reader/article/composer`
- [ ] **Step 3: Move** the load, account, visibility and send state out of `ComposerDialogComponent` into the lane. The dialog keeps text, `textTouched`, caret, content warning, `copy`/`headingKey`/`used`/`over`/`canSend`, and reads its context from `lanes.primary()`. It still closes with a single `SentPost` in this task.
- [ ] **Step 4: Run** the composer folder plus `docker compose exec -T frontend npm run check`. Expect PASS, with the existing dialog spec unchanged.
- [ ] **Step 5: Commit** `refactor(#2287): composer keeps each network's state in a lane`

### Task 5: A composer answers a list of sent posts

**Files:**
- Modify: `composer-dialog.component.ts` (`DialogRef<SentPost[]>`), `post-actions.service.ts`, `post-sent-dialog.component.{ts,html,scss}`, `share/share-button.component.ts`, `selection-share/selection-actions.component.ts`
- Modify: `public/i18n/{en,de,fr,es}.json` (`reader.posted.both`)
- Test: `post-actions.service.spec.ts`, `post-sent-dialog.component.spec.ts`, `composer-dialog.component.spec.ts` (only the assertions on `close` change: `[sent]` instead of `sent`)

**Interfaces:**
- Produces: `PostActions.postLink(network, link): Observable<SentPost[]>`. `PostSentDialogComponent` takes `DIALOG_DATA: SentPost[]`.

- [ ] **Step 1: Failing tests.**
  - `post-sent-dialog`: `lists each post with its handle and its own Open`. For two posts there are two "Open post" buttons, and each opens its own URL. `heading names both networks` ("Posted on Bluesky and Mastodon"). `one post reads as today`.
  - `post-actions`: `postLink emits the list and records once`. `reply and quote still use the single post`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement.** With one post, the overlay is exactly today's markup. With two, it shows each post's handle, text, subject and Open in a stacked list, with focus on the first Open. The en heading is `"Posted on Bluesky and Mastodon"`; translate it in de, fr and es.
- [ ] **Step 4: Run** the Jest folders above and `npm run check`; expect PASS.
- [ ] **Step 5: Commit** `feat(#2287): the posted overlay lists every post that went out`

### Task 6: Pills, the second lane and per-network controls

**Files:**
- Create: `frontend/src/app/reader/article/composer/network-pills.component.{ts,html,scss}`
- Modify: `composer-lanes.ts`, `composer-dialog.component.{ts,html,scss}`, `public/i18n/*.json`
- Test: `network-pills.component.spec.ts`, `composer-lanes.spec.ts`, `composer-dialog.component.spec.ts` (new `describe('crosspost')`)

**Interfaces:**
- Consumes: `PostNetworks.connected` (`share/post-networks.service.ts`), `LINK_POST_NETWORKS`.
- Produces:
  - `ComposerLanes.init` for a link target builds one lane per connected network in `LINK_POST_NETWORKS` order. The picked network is active and loads; the other is inactive and loads only when first activated.
  - `ComposerLanes.active: Signal<readonly ComposerLane[]>`, `ComposerLanes.toggle(network): void` (refuses to switch off the last active lane), `ComposerLanes.showsPills: Signal<boolean>` (two lanes). `ComposerLane.active: WritableSignal<boolean>`.
  - `primary` = the picked lane while it is active, else the first active lane.
  - `<app-network-pills [lanes] (toggle)>`: each pill is a `<button type="button" aria-pressed>` with `<app-network-logo>` and the name, using the `pill-toggle` mixin. States: `on`, `sent` (success colours, "✓ Posted", `aria-disabled`, not toggleable), `failed` (danger ring, warning mark).

- [ ] **Step 1: Failing tests** (dialog spec, `crosspost` describe, `PostNetworks` stubbed with both networks):
  - `opened from Bluesky, Bluesky is on and Mastodon off`, and the reverse.
  - `one connected network shows no pills`.
  - `activating Mastodon loads its context once`; `the text is not replaced`.
  - `the last active pill cannot be switched off`.
  - `the picked pill can be switched off once the other is on, and the preview follows`.
  - `a failed Mastodon load turns its pill off with an error and a retry; Bluesky stays sendable` (decision 4).
  - `a failed load of the picked lane keeps it on with the error under the pills; the other can be activated` (the spec's calls after the builder check).
  - `heading reads "Post to Bluesky and Mastodon" and Send "Post to both"`.
  - `counters are named when both are on ("Bluesky 34 / 300", "Mastodon 34 / 475") and unnamed with one` (decision 14).
  - `an over-limit active lane turns Send off`; `Send is off while an active lane is loading`.
  - `account select per active lane with more than one account, labelled by network when both are on`.
  - Review Focus 1, 2 and 5: `switching account on Mastodon leaves Bluesky's load alone`, `switching Mastodon off mid-load ignores the late answer`, `Mastodon's warning and visibility hide when it is off and come back with their values`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement.** Pills sit between the heading and `<app-composer-subject>`. The load or send error line for a lane sits directly under the pills, prefixed with the network name (decision 8 and mockup 4A). New i18n keys, with the en copy:
  - `reader.link.both` = "Post to Bluesky and Mastodon"
  - `reader.composer.link.sendBoth` = "Post to both"
  - `reader.composer.namedCounter` = "{{ network }} {{ used }} / {{ max }}"
  - `reader.composer.networkError` = "{{ network }}: {{ message }}"
  - `reader.composer.accountOn` = "Post as on {{ network }}"
  - `reader.composer.pillPosted` = "Posted"

  Add de, fr and es translations.
- [ ] **Step 4: Run** the composer folder and `npm run check`; expect PASS.
- [ ] **Step 5: Commit** `feat(#2287): Bluesky and Mastodon pills in the share-menu composer`

### Task 7: Sending to both, partial failure and retry

**Files:**
- Modify: `composer-lanes.ts`, `composer-dialog.component.{ts,html}`, `public/i18n/*.json`
- Test: `composer-dialog.component.spec.ts` (`crosspost` describe)

**Interfaces:**
- Produces: `ComposerLanes.pending: Signal<readonly ComposerLane[]>` (active, not sent). `ComposerLanes.sendAll(draft, text): Observable<void>` fires every pending lane in parallel and completes once all have answered. `ComposerLanes.sentPosts: Signal<SentPost[]>`.

- [ ] **Step 1: Failing tests:**
  - `sends one request per active network, each with its own key`.
  - `closes with both posts when both succeed`.
  - `when Mastodon fails, Bluesky locks as Posted, the error names Mastodon, the text is kept, and Send reads "Retry Mastodon"`.
  - `retry sends only Mastodon, under its first key` (Review Focus 4).
  - `Cancel after a partial failure closes with the Bluesky post`.
  - `while sending, pills, account selects and Clear are disabled` (Review Focus 3).
  - `a sent pill cannot be toggled`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement.** `disableClose` is on while sending. Cancel with any sent lane closes with `sentPosts()`, and with none it closes empty, as today. New i18n key: `reader.composer.retry` = "Retry {{ network }}".
- [ ] **Step 4: Run** the composer folder and `npm run check`; expect PASS.
- [ ] **Step 5: Commit** `feat(#2287): a crosspost sends to each network once and retries only the one that failed`

### Task 8: The Mastodon URL line, the kind note and blank link text

**Files:**
- Modify: `composer-dialog.component.{ts,html,scss}`, `public/i18n/*.json`
- Test: `composer-dialog.component.spec.ts`

- [ ] **Step 1: Failing tests:**
  - `a Mastodon link lane shows the fixed line "Mastodon adds <url>" while active`.
  - `a Mastodon quote lane shows no URL line`.
  - `a link post with blank text can be sent`.
  - `a blank quote cannot`.
  - `with both on and kinds differing, the note "Mastodon shares this post as a link" shows under the preview` (and the reverse wording for Bluesky).
  - `no note with one lane or matching kinds`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement.** The URL line has a link glyph, muted `--fs-sm`, is not focusable, and sits under the textarea and above the Clear row. New i18n keys, with the en copy:
  - `reader.composer.linkAdded` = "{{ network }} adds {{ url }}"
  - `reader.composer.sharesAsLink` = "{{ network }} shares this post as a link"

  `canSend` allows blank text when every pending lane is `kind: 'link'`.
- [ ] **Step 4: Run** the composer folder and `npm run check`; expect PASS.
- [ ] **Step 5: Commit** `feat(#2287): the composer shows Mastodon's link outside the text and takes a blank link post`

### Task 9: Playwright smoke and full verification

**Files:**
- Create: `frontend/e2e/crosspost-composer.spec.ts`

- [ ] **Step 1: Write the smoke.** It stubs `GET /api/social/link-composer` for both networks and `POST /api/social/links`: Bluesky 201, Mastodon 503 first and then 201. Pattern: `frontend/e2e/magazine-kicker-one-line.spec.ts`. The test opens the composer from "Post to Bluesky", activates Mastodon, sends, sees "Retry Mastodon", retries, and sees two "Open post" buttons. It asserts exactly one Bluesky POST went out.
- [ ] **Step 2: Run** `npm run e2e -- crosspost-composer` against the Docker stack; expect PASS.
- [ ] **Step 3: Run the gates.**
  - `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`.
  - `docker compose exec -T frontend npm run check`.
  - `composer infection:diff`.
  - Scan today's `backend/var/log/dev-*.log` with `jq`.

  Everything must be green, with no new log errors.
- [ ] **Step 4: Live check at http://localhost:4200** at 375px and desktop. Open the composer from both menu entries and toggle the pills, comparing against mockup section 1. Don't send to a real network without Lars's go.
- [ ] **Step 5: Commit** `test(#2287): crosspost composer smoke`
