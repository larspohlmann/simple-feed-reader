# Share an article as a link post (#1968) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** From the article view's share menu, post the article as a link post on a connected Bluesky or Mastodon account, written in the #1964 composer.

**Architecture:** A link post is a third thing the post writers publish, beside a reply and a quote, but it has no target post: it is written *as an account* about *an entry*. So the writers gain `share(SocialAccount, SharedLinkModel, PostDraftModel)`, a new `LinkPosts` service resolves the account and the entry's link, and two new endpoints sit beside the reply/quote ones. Bluesky needs the card built by us (`app.bsky.embed.external` with an uploaded thumbnail); Mastodon builds its card from the URL. The frontend composer learns a third kind, `link`, whose header is an article card instead of the target post; the share menu opens it.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Angular 20 signals, CDK overlay/dialog, Transloco.

**Spec:** issue #1968 ("Agreed design" section), umbrella #1962. #1970 (post a text selection) builds on this: keep the composer's prefill an input.

## Global Constraints

- Clean Code rules in CLAUDE.md; every touched `src` file PHPMD-clean; PHPStan max; phptramp; PhpStorm lint (block on WARNING); `infection:diff` gate.
- Commits `type(#1968): summary`, no Claude attribution.
- HTTP statuses as `Response::HTTP_*`, never literals (tests too).
- Writes draw on `socialWritesLimiter`; the composer GET on `socialViewerLimiter`; subscription check (`EntryListRepository::getOneSubscribedForUser`) first.
- Bluesky: post ≤ 300 graphemes (`BlueskyPostWriter::MAX_GRAPHEMES`); card = `{"$type":"app.bsky.embed.external","external":{"uri","title","description","thumb"?}}`; `thumb` is the blob object `com.atproto.repo.uploadBlob` answers under `blob`. Blob limit 1,000,000 bytes.
- The thumbnail is always re-encoded (cover-cropped JPEG 1200×630), which also strips image metadata as Bluesky asks. Any failure fetching, resizing or uploading it sends the card without `thumb`; it never fails the post.
- Mastodon: plain `POST /api/v1/statuses` (no attachment field), `Idempotency-Key` as for replies, `write:statuses` scope path unchanged.
- The card/status URL is the entry's URL through `UrlNormalizer::normalize` (strips tracking parameters).
- Acting account for a link post: the explicit `accountId` when given (must be connected and on the asked network), else `SocialAccountRepository::findReadyActingOnNetwork($user, $network)`; none → `NoSocialAccountForNetworkException`. Do **not** use `ActingAccountChooser` here: for a private-feed entry it returns that feed's account whatever network was asked.
- Entry without a URL → `SocialPostRefusedException('This entry has no link to share.')` (409 `social_post_refused`).
- Mastodon composer visibility for a link post = the account's own default (`source.privacy`), no mentions.
- Frontend: Prettier 100 cols, ESLint `max-params` 3, en/de i18n parity, no hex colours outside theme, component styles in `.scss`. Run `npm run check` **and** `ng build` in the frontend container.
- Only the reader view's share button offers the new items (it has an entry); the link reader (`link-reader.component`) shares an arbitrary URL with no entry and stays as is.

---

### Task 1: Plumbing the link post needs (no behaviour change)

**Files:**
- Move: `backend/src/Service/Mail/Digest/DigestImageResizer/{DigestImageResizerInterface,GdImageResizer}.php` → `backend/src/Service/Image/ImageResizer/{ImageResizerInterface,GdImageResizer}.php` (namespace `App\Service\Image\ImageResizer`); its test `tests/Service/Mail/Digest/GdImageResizerTest.php` (and anything under `tests/Service/Mail/Digest/DigestImageResizer/`) moves to `tests/Service/Image/ImageResizer/`. `DigestImageEmbedder` now takes `ImageResizerInterface $resizer`. The exception it throws (`ImageProcessingException`, find its namespace with grep) stays where it is unless it lives under `Mail`, in which case it moves to `Service/Image/Exception/`. Use PhpStorm `rename_refactoring` / `git mv`, then `grep -rn "DigestImageResizer" backend` must be empty.
- Modify: `backend/src/Service/Fetch/Model/ApiRequestModel.php` — add `public ?string $body` (constructor param after `$json`, all existing factories pass `null`) and `public static function postBytes(string $url, string $bytes, string $contentType): self` (headers `['Content-Type' => $contentType]`). `withHeaders` carries the body.
- Modify: `backend/src/Service/Fetch/GuardedApiClient.php::optionsFor` — `...(null === $request->body ? [] : ['body' => $request->body])`.
- Modify: `backend/src/Service/Social/BlueskySessions.php` — add
  ```php
  /** @throws (same list as actAs) */
  public function uploadAs(SocialAccount $account, string $path, string $bytes, string $contentType): ApiResponseModel
  {
      $request = ApiRequestModel::postBytes($account->getServiceUrl() . $path, $bytes, $contentType);

      return self::actionAccepted($this->sendRetryingStale($account, $request));
  }
  ```
- Modify: `backend/src/Service/Social/SocialAccountForUser.php` — add `requireConnectedOn(User $user, int $accountId, SocialNetwork $network): SocialAccount`, the body of `ResponseTargetFinder::chosen()` (requireConnected + network check throwing `SocialAccountNotFoundException`); `ResponseTargetFinder` calls it and loses `chosen()`.
- Tests: `tests/Service/Fetch/Model/ApiRequestModelTest` (or the nearest existing test of it) — `postBytes` carries method, body, content type, and `withHeaders` keeps the body; `GuardedApiClientTest` — a bytes request sends `body` (mirror how the existing test asserts `json`); `BlueskySessionsTest` — `uploadAs` posts the raw bytes with the bearer and the content type, retries once on a stale session, maps 400 → `SocialPostRefusedException`; `SocialAccountForUserTest` — wrong-network account refused (move the existing `ResponseTargetFinder` test of that case if there is one).

- [ ] Write the failing tests above, run them, see them fail.
- [ ] Implement; run `php bin/phpunit tests/Service/Fetch tests/Service/Social tests/Service/Image tests/Service/Mail`.
- [ ] `composer check`, `composer md` on touched files.
- [ ] Commit `refactor(#1968): the image resizer moves to Image, requests can carry bytes, Bluesky sessions upload`.

### Task 2: The writers publish a link post

**Interfaces (produces):**
- `App\Service\Social\Model\SharedLinkModel` — `final readonly`, `public string $url, public string $title, public ?string $description, public ?string $imageUrl`.
- `App\Service\Social\Support\SharedLink::of(Entry $entry, string $url): SharedLinkModel` — title = `$entry->getTitle()`; description = `EntryExcerpt::of($entry->getSummary(), $entry->getContentHtml())` cut to 300 characters at a word boundary (`App\Service\Text\Support\WordBoundary::cut`), `null` when empty; imageUrl = `$entry->getImageUrl()`. (`$url` is the already-normalised URL; the caller normalises, because `UrlNormalizer` is a service.)
- `PostWriterInterface::share(SocialAccount $account, SharedLinkModel $link, PostDraftModel $draft): PublishedPostModel` (same `@throws` as `reply`, minus `SocialPostNotFoundException`).
- `PostWriting::share(SocialAccount $account, SharedLinkModel $link, PostDraftModel $draft): PublishedPostModel` — through `ActingAccountGuard::actAs($account, …)` like `publishAs`.
- `ComposerDefaultsInterface::forAccount(SocialAccount $account): ComposerDefaultsModel` — Bluesky: `new ComposerDefaultsModel(MAX_GRAPHEMES)`; Mastodon: instance `max_characters` (fallback 500) and the account's `source.privacy` (fallback `public`), no mentions. `MastodonComposerDefaults::defaultsFor` reuses the same two lookups (extract private helpers; no duplicate HTTP code).

**Bluesky (`BlueskyPostWriter`):**
- `published()` takes `SocialAccount $account` instead of the target (reply/quote pass `$target->account`).
- `share()` = `published($account, $draft, ['embed' => $this->cardOf($account, $link)])`.
- The card lives in a new collaborator so the writer stays small: `App\Service\Social\BlueskyLinkCards` (`final readonly`, root of the module) with `cardOf(SocialAccount $account, SharedLinkModel $link): array` returning the embed. It injects `GuardedImageDownloaderInterface`, `ImageResizerInterface`, `BlueskySessions`, `LoggerInterface`. `thumb` = `uploadAs($account, '/xrpc/com.atproto.repo.uploadBlob', $jpeg, 'image/jpeg')->json()['blob']` (read through `JsonNodeModel`; a missing `blob` = no thumb). It catches `GuardedImageRejectedException | GuardedImageUnavailableException | ImageProcessingException | SocialNetworkUnreachableException | SocialPostRefusedException`, logs at debug, and returns the card without `thumb`. No image URL → no download. `description` is `''` when null (the lexicon requires the field).

**Mastodon (`MastodonPostWriter`):** `published()` takes `SocialAccount $account`; `share()` = `published($account, $draft, [])`. The URL is already in the draft text (the composer prefills it); the writer does not add it.

**Tests:**
- `SharedLinkTest`: summary preferred over content, cut at 300 on a word boundary, empty → null description, image passed through.
- `BlueskyLinkCardsTest`: full card with thumb (fake downloader returns bytes, fake resizer returns `jpeg-bytes`, sessions double answers `{"blob":{…}}`, assert the upload got `jpeg-bytes` and `image/jpeg`); each caught failure → card without `thumb`; no image URL → downloader never called; null description → `''`.
- `BlueskyPostWriterTest`: `share` writes `text`, facets, langs and the `embed` from the card collaborator (double it), no `reply` key; over 300 graphemes → `SocialPostTooLongException`.
- `MastodonPostWriterTest`: `share` body has `status`, `visibility`, `language`, no `in_reply_to_id`/`quoted_status_id`; Idempotency-Key header present.
- `PostWritingTest` (or where publishAs is tested): `share` acts through the guard as the given account.
- Composer defaults tests: `forAccount` for both networks (Mastodon: instance limit and account privacy, fallbacks when the calls fail).
- `tests/Support/FakeMastodonNetwork` and any other `PostWriterInterface` / `ComposerDefaultsInterface` fakes implement the new methods.

- [ ] Tests first, watch them fail; implement; run `php bin/phpunit tests/Service/Social`.
- [ ] `composer check`, `composer md`; PhpStorm lint on touched files.
- [ ] Commit `feat(#1968): Bluesky and Mastodon writers publish a link post, Bluesky with a card`.

### Task 3: `LinkPosts` and its endpoints

**Interfaces (produces):**
- `App\Service\Social\LinkPosts` (`final readonly`), injecting `SocialAccountRepository`, `SocialAccountForUser`, `UrlNormalizer`, `PostWriting`, and the `app.social_composer_defaults` locator (as `PostComposer` does):
  - `contextFor(User $user, Entry $entry, SocialNetwork $network, ?int $accountId): ComposerContextModel` — accounts = `findConnectedOnNetwork`, account = acting account (Global Constraints), `canSend: true`, defaults = `forAccount($account)`.
  - `share(User $user, Entry $entry, LinkPostModel $post): PublishedPostModel` where `App\Service\Social\Model\LinkPostModel` holds `SocialNetwork $network, ?int $accountId, PostDraftModel $draft` (keeps `share` at three parameters).
  - Entry without URL (or a URL `UrlNormalizer` rejects) → `SocialPostRefusedException('This entry has no link to share.')`, checked **before** the account is resolved.
- Controller (`EntrySocialPostController`, inject `LinkPosts $links`):
  - `GET /api/entries/{id}/social/link-composer?network=bluesky|mastodon&accountId=` → `ComposerContextJson`; `network` is a required `SocialNetwork` `#[MapQueryParameter(validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]`.
  - `POST /api/entries/{id}/social/links?network=` with `PostDraftRequest` body → 201 `PublishedPostJson`. Add `PostDraftRequest::toLinkPost(SocialNetwork $network): LinkPostModel`.
  - Route names `api_entries_social_link_composer`, `api_entries_social_link`.
- Run the §6 checklist of `docs/architecture.md` against both endpoints (bearer, JSON, problem+json; nothing browser-only).

**Tests:**
- `LinkPostsTest` (DbTestCase, fakes as in `SocialResponsesTest`): default account used; explicit account on the wrong network → `SocialAccountNotFoundException`; no account → `NoSocialAccountForNetworkException`; no URL → refused before any account lookup; a private-feed entry of the *other* network still posts as this network's default (the trap in Global Constraints); the writer receives the normalised URL (feed a `?utm_source=x` URL).
- `EntrySocialPostControllerTest`: composer 200 shape for each network; missing/unknown `network` → 400; link post 201 on Mastodon (assert the status body sent through the fake network); draws on the write budget (mirror the quote test); foreign entry → 404.

- [ ] Tests first; implement; `php bin/phpunit tests/Service/Social tests/Controller/Api/EntrySocialPostControllerTest.php`.
- [ ] `composer check`, `composer md`, lint.
- [ ] Commit `feat(#1968): compose and send a link post for an entry`.

### Task 4: Frontend — the composer writes a link post

**Files:** `frontend/src/app/reader/entry-social.ts`, `reader/social-post-api.ts`, `reader/article/composer/{composer-dialog.component.*,response-actions.service.ts}` and their specs; `public/i18n/{en,de}.json`.

**Interfaces (produces):**
- `entry-social.ts`: `export interface LinkTargetDto { kind: 'link'; entryId: number; network: SocialNetworkName }`; `export type PostTargetDto = ResponseTargetDto | LinkTargetDto`.
- `SocialPostApi.composer(target: PostTargetDto, accountId?)`: `link` → `GET social/link-composer` with `network` (+ `accountId`); else as today. `SocialPostApi.send(target: PostTargetDto, draft)`: `link` → `POST social/links?network=`; else as today.
- `composer-dialog.component.ts`: `ComposeTarget` becomes a union:
  ```ts
  export interface ResponseComposeTarget extends ResponseTargetDto { network; authorName; authorHandle; excerpt }
  export interface LinkComposeTarget extends LinkTargetDto { title: string; site: string | null; imageUrl: string | null; prefill: string }
  export type ComposeTarget = ResponseComposeTarget | LinkComposeTarget;
  ```
  The header renders the target post for a response and a compact article card (image left, title, site) for a link; style it in the component `.scss` with existing tokens. On first context load, a link target's text starts as `prefill` (mentions stay the response path). `copy`, `headingKey`, `forbiddenKey` keep working by kind (`reader.composer.link.*`, `reader.link.{bluesky,mastodon}`; no forbidden banner is reachable for link, `canSend` is always true, so no `linkForbidden` keys).
- `composer/link-prefill.ts`: `linkPrefill(network: SocialNetworkName, title: string, url: string): string` — Bluesky: `title`; Mastodon: `` `${title}\n\n${url}` ``; an empty title gives just the URL on Mastodon and `''` on Bluesky. (Lives here so #1970 can prefill a quote the same way.)
- `ResponseActions.respond(target)`: `link` → `linked(sent)`: toast `reader.linkPosted` with the open action. Extract the toast both quote and link use into one private method; rename the action key `reader.openQuote` → `reader.openPost` (same values) in en/de and code.
- i18n en/de: `reader.link.{bluesky,mastodon}` ("Post on Bluesky"/"Auf Bluesky posten", "Post on Mastodon"/"Auf Mastodon posten"), `reader.composer.link.{loading,account,text,send}` (send: "Post"/"Posten"), `reader.linkPosted` ("Link posted"/"Link veröffentlicht"), `reader.openPost`.

**Specs:** api — both link URLs and params; dialog — article card shown, prefill used once (an account switch reload keeps typed text), send goes to `social/links`; link-prefill per network and empty title; actions — link toast with Open, no count stepping, no comment insertion.

- [ ] Specs first; implement; `docker compose exec -T frontend npm run check` and `docker compose exec -T frontend npx ng build`.
- [ ] Commit `feat(#1968): the composer writes a link post with an article card`.

### Task 5: Frontend — the share menu offers it

**Files:** `reader/article/share/{share-button.component.*,share-targets.ts}`, `public/share-targets/share-targets.json`, `reader/article/reader-view/reader-view.component.html`, i18n.

- `ShareButtonComponent` gains `readonly entry = input<EntryDto | null>(null)` (only `reader-view` passes it: `[entry]="e"`). It reads `SubscriptionsStore.socialAccounts`, keeps `status === 'connected'`, and builds `postTargets`: one per network that has an account, Bluesky first, each `{ network, accounts: handles }`. Empty when `entry()` is null or has no `url`.
- Menu (overlay and action sheet): when `postTargets` is non-empty, a group at the top — one item per network (`app-network-logo` / the sheet's `logo`, label `reader.link.<network>`), then a divider, then the existing targets. The item's `title` (tooltip) is `reader.share.postAs` ("as {{handle}}") with one account, `reader.share.pickAccount` ("Choose the account in the composer") with several; the sheet shows the same text as its `hint`.
- Choosing it calls `ResponseActions.respond({ kind: 'link', entryId, network, title, site, imageUrl, prefill: linkPrefill(network, title, stripTracking(url)) })` (`site` = the entry's feed/site name field — read `EntryDto` for the right one).
- `share-targets.json`: the Bluesky entry becomes `"labelKey": "reader.share.blueskyApp"` ("Open in Bluesky app"/"In der Bluesky-App öffnen") with `"external": true`; `ShareEntry` gains `external?: boolean`; the overlay item renders a trailing `open_in_new` icon for it (sheet: label only).
- Specs: no items without an entry or without accounts; one item per connected network, Bluesky first; `needs_reconnect` accounts ignored; tooltip per account count; choosing opens the composer with the right target and prefill; the renamed Bluesky app item still opens bsky.app and shows the external glyph.

- [ ] Specs first; implement; `npm run check` and `ng build` in the container.
- [ ] Commit `feat(#1968): Post on Bluesky / Mastodon from the article's share menu`.

### Task 6: Gates, review, live check, PR

- [ ] `composer check`, `composer md`, `composer tramp`, both suites (`php bin/phpunit` natively and `docker compose exec -T php composer test:parallel`), `composer infection:diff`; frontend `npm run check` + `ng build`; scan today's `backend/var/log/dev-*.log`.
- [ ] `/simplify`, independent bug review; fix confirmed findings.
- [ ] Live on :4200 (`docker compose exec -T php bin/console cache:clear` first): post a non-social RSS entry to Bluesky and Mastodon through the UI; check the Bluesky record has the card with a thumb and the Mastodon status shows the instance card; then delete both posts (Bluesky `deleteRecord`, Mastodon `DELETE /api/v1/statuses/{id}`) via a one-off console call.
- [ ] PR into develop, `Closes #1968`.

---

## Revision (2026-11-01, after Lars tried it): share a URL, not an entry

Lars: "I don't want to share an entry, but I want to share an external url." The share menu posts **whatever URL it shares** — the entry's URL in the article view, the opened link in the link reader. The backend no longer knows entries for a link post.

**Changed Global Constraints (these replace the entry-based ones above):**
- Endpoints are not entry-scoped: `GET /api/social/link-composer?network=&accountId=` and `POST /api/social/links?network=` with body = the draft fields plus `url` (required, absolute http(s), ≤ `LinkCardContent::URL_MAX`). No subscription check; same limiters (`socialViewerLimiter` / `socialWritesLimiter`).
- The URL is normalised with `UrlNormalizer::normalize` (tracking parameters stripped); a URL it rejects → `SocialPostRefusedException('This is no link to share.')`.
- The Bluesky card comes from the page itself: `PageCardReaderInterface::read($url)` (LinkCard module; SSRF-guarded fetch) gives `LinkCardContent {url, title, description, imageUrl, siteName}`. The card uses its title/description/imageUrl; when the page read fails (`PageFetchException`) or declares no card, the card goes out with `title` = the URL's host and `description` `''`, no thumb. Thumbnail rules unchanged. Mastodon never reads the page (the instance builds its card).
- Acting account rule unchanged (explicit `accountId` on the asked network, else `findConnectedOnNetwork` first / `findReadyActingOnNetwork`).

### Task 7: Backend — a link post shares a URL

- `PostWriterInterface::share(SocialAccount $account, string $url, PostDraftModel $draft)`; `PostWriting::share` likewise. Delete `SharedLinkModel` and `Support/SharedLink` (+ tests) — nothing else uses them.
- `BlueskyLinkCards::cardOf(SocialAccount $account, string $url)` reads the page card through `PageCardReaderInterface` (inject it), then builds the embed and the thumbnail from the card's `imageUrl` exactly as today. A failed/empty page read → the fallback card above (catch `PageFetchException`; log at debug like the thumbnail failures).
- `LinkPosts`: no `Entry`. `contextFor(User $user, SocialNetwork $network, ?int $accountId)`; `share(User $user, LinkPostModel $post)` where `LinkPostModel` = `network, accountId, url, draft`. URL check first (before any account lookup), as today.
- Controller: move both actions out of `EntrySocialPostController` into a new `SocialLinkPostController` (`#[Route('/api/social')]`), route names `api_social_link_composer`, `api_social_link`. New request DTO `Dto/Social/LinkPostRequest` = `PostDraftRequest`'s fields + `#[Assert\NotBlank] #[Assert\Url(requireTld: true)] #[Assert\Length(max: LinkCardContent::URL_MAX)] public string $url`, with `toLinkPost(SocialNetwork $network): LinkPostModel`; drop `PostDraftRequest::toLinkPost`. Avoid duplicating the five draft fields by composition if the validator allows (`#[Assert\Valid]` nested) — otherwise extend nothing and keep it flat; pick the cleaner, note it.
- Tests: rewrite `LinkPostsTest`, the link-composer/link tests (move them to `SocialLinkPostControllerTest`), `BlueskyLinkCardsTest` (page card read: full card, page fails → host fallback, page without image → no download), writer tests for the new signature. Bad/missing `url` → 422 per the project's MapRequestPayload convention (check an existing test for the status used).
- Commit `refactor(#1968): a link post shares a URL, and Bluesky reads its card from the page`.

### Task 8: Frontend — share the URL the menu shares

- `LinkTargetDto` = `{ kind: 'link'; network; url: string }` (no `entryId`); `SocialPostApi` link calls go to `/api/social/link-composer` and `/api/social/links?network=` with `url` in the POST body.
- `LinkComposeTarget` = `LinkTargetDto & { title: string; site: string; imageUrl: string | null; prefill: string }`.
- `ShareButtonComponent`: drop the `entry` input; post targets depend only on connected accounts and the `url` input, so **both** the reader view and the link reader offer them. Optional input `imageUrl = input<string | null>(null)`; `site` = the URL's host. Reader view passes `[imageUrl]="e.imageUrl"`; the link reader passes its card/article image if it has one (read `link-reader.component.ts`: `source.card()` / `source.article()`).
- The composer's article card shows title, site (host) and image as before.
- Update every spec touched; i18n unchanged.
- Commit `refactor(#1968): the share menu posts the URL it shares, in the link reader too`.
