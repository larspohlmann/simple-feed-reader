# Lemmy Link Posts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Share a link to Lemmy as a link post (community, title, URL, body), alone or as a third pill beside Bluesky and Mastodon.

**Architecture:** The backend's Lemmy writer creates posts with `POST /api/v3/post`. The Lemmy link-composer context carries the postable subscribed communities and a starting title. The frontend `ComposerLane` gains Lemmy fields (community, title) that are seeded from that context and from a per-account last-community memory in `localStorage`. `LINK_POST_NETWORKS` gains `'lemmy'`.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (PHPUnit, PHPStan max, PHPMD, phptramp); Angular 20 signals, Transloco (en/de/fr/es), Jest in the Docker frontend container, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-17-lemmy-link-posts-design.md` (builds on `docs/superpowers/specs/2026-11-17-crosspost-pills-design.md`)

## Global Constraints

- Follow CLAUDE.md:
  - Clean Code names, guard clauses, `final readonly`, typed exceptions next to their service.
  - Domain code knows no HTTP. `src/Http/*Json` shapes responses.
  - Default to no comment.
- Every touched `backend/src` file must be PHPMD-clean before commit. Run `composer check` and `composer md` from `backend/`, and lint changed PHP with PhpStorm (`mcp__phpstorm__lint_files`).
- HTTP status codes are always `Response::HTTP_*`.
- Frontend:
  - Run `docker compose exec -T frontend npm run check` (never Jest natively).
  - `npm run check` does not type-check templates. Also confirm the dev build is clean with `docker compose exec -T frontend npx ng build --configuration development`, or a clean dev-server log.
- i18n: every new key in `frontend/public/i18n/{en,de,fr,es}.json`. de/fr/es match their siblings' wording.
- Commit messages: `type(#2295): …`, no Claude attribution.
- The title limit is **200**. The Lemmy body limit is **10000** (`LemmyComposerDefaults::MAX_CHARACTERS`).
- Lemmy link posts send **no `language_id`**.
- Never post to a real social network in tests or checks.
- Bluesky and Mastodon behaviour must not change (anti-goal).

## Review Focus

1. **The remembered community is no longer in the list** (unsubscribed, or now mod-only). No community is preselected, and Send stays off for Lemmy. Test in Task 4.
2. **The account is switched on the Lemmy lane.** Communities reload for the new account, and the community resets to that account's remembered one, or none. Test in Task 4.
3. **`localStorage` throws** (private mode, blocked storage). The composer opens and posts normally, and nothing is remembered. Test in Task 4.
4. **The page title is longer than 200 characters, or empty.** A long title is cut at a word to 200 or fewer. An empty title leaves the title blank, so Send stays off until the reader types one. Tests in Tasks 2 and 4.
5. **The title is only whitespace.** The client blocks Send, and the server answers 422 `social_post_title_missing`. Tests in Tasks 3 and 4.

---

### Task 1: Postable subscribed communities

**Files:**
- Create: `backend/src/Service/Social/Social/LemmyCommunities.php`
- Create: `backend/src/Service/Social/Social/Model/LemmyCommunityModel.php`
- Modify: `backend/src/Service/Social/Social/CandidateSource/LemmyCandidateSource.php` (use the shared fetch)
- Test: `backend/tests/Service/Social/Social/LemmyCommunitiesTest.php`, and keep `tests/Service/Social/Social/CandidateSource/LemmyCandidateSourceTest.php` green

**Interfaces:**
- Produces:
  - `final readonly class LemmyCommunityModel { public function __construct(public int $id, public string $handle) }`. The handle is `!name@host`, lower-cased like `LemmyCommunity::follow`.
  - `LemmyCommunities::subscribedViews(SocialAccount $account): list<JsonNodeModel>` is the paged `community/list?type_=Subscribed&sort=Old&limit=50` fetch, moved out of `LemmyCandidateSource` with its `CollectedItemsModel::MAX_ITEMS` cap.
  - `LemmyCommunities::postable(SocialAccount $account): list<LemmyCommunityModel>`:
    - leaves out views whose `community.removed`, `community.deleted` or `community.posting_restricted_to_mods` is true, and views without an https `actor_id`
    - sorts by handle A–Z

- [ ] **Step 1: Write the failing tests.** Use a `RoutedHttpClient` pattern like `LemmyCandidateSourceTest`:
  - `testPostableListsSubscribedCommunitiesByHandle`: two communities come back as `[new LemmyCommunityModel(7, '!rss@lemmy.world'), new LemmyCommunityModel(3, '!selfhosted@lemmy.world')]`, sorted A–Z whatever the API order.
  - `testPostableLeavesOutRemovedDeletedAndModOnlyCommunities`: three flagged views and one normal view give a list with only the normal one.
  - `testPostableReadsEveryPage`: 50 views on page 1 and 1 on page 2 give 51 entries.
- [ ] **Step 2:** Run `php bin/phpunit tests/Service/Social/Social/LemmyCommunitiesTest.php`. Expected: FAIL (the class doesn't exist).
- [ ] **Step 3:** Implement both classes. Make `LemmyCandidateSource` use `subscribedViews` and delete its private `communities`/`pageOf`.
- [ ] **Step 4:** Run the new test and `LemmyCandidateSourceTest`. Expected: PASS. Then run `composer check`, `composer md` and the PhpStorm lint on the changed files: all clean.
- [ ] **Step 5:** Commit `feat(#2295): Lemmy's postable subscribed communities, read with the candidate source's paged fetch`.

### Task 2: Lemmy link-composer context

**Files:**
- Create: `backend/src/Service/Social/Social/Model/LemmyLinkModel.php`
- Modify:
  - `backend/src/Service/Social/Social/Model/ComposerDefaultsModel.php` (add `public ?LemmyLinkModel $lemmyLink = null`)
  - `backend/src/Service/Social/Social/ComposerDefaults/ComposerDefaultsInterface.php`, plus its Bluesky, Mastodon and Lemmy implementations: `forLinkFrom(SocialAccount $account, SharedLinkModel $link)`
  - `backend/src/Service/Social/Social/ComposerContexts.php` (`ofLink`)
  - `backend/src/Http/ComposerContextJson.php`
- Test:
  - `tests/Service/Social/Social/ComposerDefaults/LemmyComposerDefaultsTest.php`
  - `tests/Service/Social/Social/ComposerContextsTest.php` (or wherever `ofLink` is tested now)
  - `tests/Http/ComposerContextJsonTest.php` (or its existing equivalent)
  - the link-composer controller test

**Interfaces:**
- Consumes: `LemmyCommunities::postable` from Task 1.
- Produces:
  - `final readonly class LemmyLinkModel { public const int MAX_TITLE_CHARACTERS = 200; public function __construct(public array $communities /* list<LemmyCommunityModel> */, public string $title) }`
  - `LemmyComposerDefaults::forLinkFrom` returns defaults whose `lemmyLink` holds `postable($account)` and the link title, cut with `WordBoundary::clipped($title, 200)`. Bluesky and Mastodon return `lemmyLink: null`. Their behaviour is otherwise unchanged.
  - `ComposerContexts::ofLink`: when `$defaults->lemmyLink !== null`, the starting text is `ComposerStartingText::quoting($link->quote, $defaults->maxCharacters)` (the quote or `''`). Otherwise it stays `sharing(...)`.
  - The JSON gains `lemmy`, which is null or this shape:
    ```
    lemmy: null | { communities: list<{id: int, handle: string}>, title: string, maxTitleCharacters: 200 }
    ```

- [ ] **Step 1: Write the failing tests.**
  - `LemmyComposerDefaultsTest::testALinkCarriesThePostableCommunitiesAndThePageTitle`
  - `…testALongPageTitleIsCutAtAWordToTwoHundred`: a 230-character title gives `mb_strlen <= 200` and no cut word.
  - `ComposerContexts…testALemmyLinkStartsFromTheQuoteAloneNotTheTitle`: no quote gives `text === ''`. With quote `foo` the text is `“foo”`.
  - A JSON test asserts the `lemmy` row for Lemmy and `null` for Bluesky.
  - The link-composer controller test, for a Lemmy account with stubbed `community/list`, gets `lemmy.communities[0].handle`.
- [ ] **Step 2:** Run the tests. Expected: FAIL.
- [ ] **Step 3:** Implement. Update every `forLinkFrom` caller and test double.
- [ ] **Step 4:** Run the affected test files, then `composer check`, `composer md` and the PhpStorm lint. All pass and are clean.
- [ ] **Step 5:** Commit `feat(#2295): the Lemmy link composer offers the postable communities and the page title`.

### Task 3: Posting a Lemmy link

**Files:**
- Create:
  - `backend/src/Service/Social/Social/Exception/SocialPostTitleMissingException.php`
  - `backend/src/Service/Social/Social/Exception/SocialPostCommunityMissingException.php`
- Modify:
  - `backend/src/Service/Social/Social/Model/PostDraftModel.php` (add `public ?string $title = null, public ?int $communityId = null` after `idempotencyKey`)
  - `backend/src/Dto/Social/LinkPostDraftRequest.php` (add `#[Assert\Length(max: 200)] public ?string $title = null`, `#[Assert\Positive] public ?int $communityId = null`, and pass them to the draft)
  - `backend/src/Service/Social/Social/PostWriter/LemmyPostWriter.php` (`share`)
  - `backend/src/Http/Problem/ExceptionProblems/SocialInteractionProblems.php`
- Test:
  - `tests/Service/Social/Social/PostWriter/LemmyPostWriterTest.php`
  - the `SocialInteractionProblems` test
  - `tests/Service/Social/Social/SharedPostQuoteFinderTest.php` (create it if absent)
  - the link-post controller test

**Interfaces:**
- Produces:
  - Request JSON fields `title` and `communityId` on `POST /api/social/links`.
  - Problem types, both 422:
    - `social_post_title_missing`, title "The post needs a title"
    - `social_post_community_missing`, title "The post needs a community"
  - `share` throws, in this order:
    - `SocialPostCommunityMissingException` when `communityId` is null
    - `SocialPostTitleMissingException` when the trimmed title is empty
    - `SocialPostTooLongException` when the title is over 200 or the text is over 10000

- [ ] **Step 1: Write the failing tests.**
  - `testALinkIsALinkPostInTheChosenCommunity`: the request to `/api/v3/post` carries exactly `{"name":"Title","url":"https://example.com/a","body":"Worth a read.","community_id":7}` (no `language_id`, even when the draft's language is `de`). The published address is `post_view.post.ap_id`.
  - `testABlankTextLeavesOutTheBody`: the request has no `body` key.
  - `testALinkWithoutACommunityIsRefused`, `testAWhitespaceTitleIsRefused`, `testATitleOverTwoHundredIsRefused` and `testABodyOverTenThousandIsRefused` each expect their exception, and no request is sent.
  - A problem-mapping test for both new types.
  - `SharedPostQuoteFinderTest::testALemmyPostUrlIsNeverQuotedByALemmyAccount` gives `null`. If `LemmyPostResolver`'s response context already refuses quotes, the test only pins that. If not, add the guard in `quotableAt`.
  - A controller test: `POST /api/social/links` with a Lemmy account, `title`, `communityId` and a stubbed Lemmy answers 201.
- [ ] **Step 2:** Run the tests. Expected: FAIL.
- [ ] **Step 3:** Implement. `reply` and `quote` stay unchanged.
- [ ] **Step 4:** Run the affected tests, `composer check`, `composer md` and the PhpStorm lint. All pass and are clean.
- [ ] **Step 5:** Commit `feat(#2295): a shared link goes to Lemmy as a link post in the chosen community`.

### Task 4: The Lemmy lane

**Files:**
- Create:
  - `frontend/src/app/reader/article/composer/lemmy-community-memory.ts`, plus its spec
- Modify:
  - `frontend/src/app/reader/entry-social.ts` (`ComposerContextDto.lemmy`, `PostDraftDto.title?`/`communityId?`)
  - `frontend/src/app/reader/article/composer/composer-lane.ts`, plus its spec
  - `frontend/src/app/reader/article/composer/composer-dialog.component.{ts,html,scss}`, plus its spec

**Interfaces:**
- Consumes: the `lemmy` JSON from Task 2 and the request fields from Task 3.
- Produces:
  - `ComposerContextDto.lemmy: { communities: { id: number; handle: string }[]; title: string; maxTitleCharacters: number } | null`
  - `LemmyCommunityMemory` (`providedIn: 'root'`):
    - `recall(accountId: number): number | null`
    - `remember(accountId: number, communityId: number): void`
    - one `localStorage` key `sfr.composer.lemmyCommunity` holding a JSON object of account id to community id
    - every access is in try/catch, as in `reader/audio/playback-rate.ts`
  - `ComposerLane` adds:
    - `readonly community: WritableSignal<number | null>`
    - `readonly title: WritableSignal<string>`
    - `readonly fieldsReady: Signal<boolean>`: true when `context()?.lemmy` is null. Otherwise it needs a chosen community and a trimmed title of 1–200 characters.
  - The lane behaves like this:
    - On `adopt`, a context with `lemmy` sets `title` to `lemmy.title`, and `community` to the recalled id when it is in `lemmy.communities`, else `null`. An account switch re-adopts, so both reset.
    - The draft gets `title` and `communityId` only when `lemmy` is set.
    - A successful send calls `remember(accountId, community)`.

- [ ] **Step 1: Write the failing Jest specs.**
  - `LemmyCommunityMemory`:
    - remembers per account
    - `recall` gives `null` for an unknown account and for garbage JSON
    - `remember` and `recall` don't throw when `localStorage.getItem`/`setItem` throw (Review Focus 3)
  - Lane:
    - seeds the title and the remembered community
    - a remembered id that's absent from the list gives `community() === null` and `fieldsReady() === false` (Review Focus 1)
    - an account switch resets to that account's memory (Review Focus 2)
    - a whitespace title gives `fieldsReady() === false` (Review Focus 5)
    - an empty context title leaves Send off until typed (Review Focus 4)
    - the draft carries `title` and `communityId`
    - a successful send remembers, a failed one doesn't
    - a Bluesky lane is always `fieldsReady()`
  - Dialog:
    - with the Lemmy lane active, a Community select (first option "Choose a community", then the handles) and a Title input with counter "34 / 200" render under the text, after Mastodon's block
    - Send is disabled while `fieldsReady()` is false
    - with `lemmy.communities` empty, the note "Subscribe to a community on Lemmy first" shows instead of the select, and Send stays off for a Lemmy-only composer
- [ ] **Step 2:** Run `docker compose exec -T frontend npx jest src/app/reader/article/composer`. Expected: FAIL.
- [ ] **Step 3:** Implement.
  - The dialog's `pendingFit` also needs `lane.fieldsReady()` for each pending lane.
  - The Lemmy block goes inside the existing per-lane `@for` under the text field. It uses `controlId('community', lane)`/`controlId('title', lane)` and the existing `app-field`.
  - The title counter reuses `reader.composer.counter` and goes red over 200.
  - Use `networkName(lane)`-style typed methods in templates, not untyped `ng-template` context indexing.
- [ ] **Step 4:** Run `docker compose exec -T frontend npm run check` and the dev build (Global Constraints). Both are clean.
- [ ] **Step 5:** Commit `feat(#2295): the composer's Lemmy lane picks a community and a title, remembering the community per account`.

### Task 5: Lemmy in the share menu and pills, copy, e2e

**Files:**
- Modify:
  - `frontend/src/app/core/social-network.ts` (`LINK_POST_NETWORKS = ['bluesky', 'mastodon', 'lemmy']`), plus `social-network.spec.ts`
  - `frontend/public/i18n/{en,de,fr,es}.json`
  - `frontend/e2e/crosspost-composer.spec.ts`
  - the share-menu specs that pin the network list

**Interfaces:**
- Consumes: Tasks 2–4.
- Produces these en keys (exact copy; de/fr/es translated to match their siblings):
  - `reader.link.lemmy` "Post on Lemmy"
  - `reader.quote.lemmy` "Quote on Lemmy"
  - `reader.posted.link.lemmy` "Posted on Lemmy"
  - `reader.composer.community` "Community"
  - `reader.composer.chooseCommunity` "Choose a community"
  - `reader.composer.title` "Title"
  - `reader.composer.noCommunities` "Subscribe to a community on Lemmy first"
  - Keys added in Task 4 move here only if Task 4 did not already add them in all four languages.

- [ ] **Step 1: Write the failing specs.**
  - `social-network.spec` expects the three networks.
  - The share-menu spec shows "Post on Lemmy" for a connected Lemmy account.
  - Extend the e2e `crosspost-composer.spec.ts`:
    - stub a Lemmy account plus `link-composer` (with `lemmy.communities`) and `links`
    - turn the Lemmy pill on, pick a community and post to all three
    - assert exactly one Lemmy POST carrying `title` and `communityId`
- [ ] **Step 2:** Run the Jest specs. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `docker compose exec -T frontend npm run check`, the dev build, and `cd frontend && npx playwright test e2e/crosspost-composer.spec.ts` (Docker stack up). All pass.
- [ ] **Step 5:** Commit `feat(#2295): Lemmy joins the share menu and the crosspost pills`.
