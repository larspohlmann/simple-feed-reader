# #1725 — Signed link-card reader

**Goal:** every link card — root post, self-reply, comment — opens in the internal reader through one endpoint that
trusts a URL only when it carries the server's signature, replacing the entry/position lookup.

**Decided (chat, 2026-10-20):** HMAC over the URL alone, not bound to user or entry (binding the user would thread it
through every mapper; a token only proves the server showed that URL as a card). Key derived from `kernel.secret`
via HKDF. No expiry. The JSON mappers that emit cards become injected services so they can sign.

## Backend

1. **`Service/LinkCard/LinkCardSignature`** — `final readonly`, `#[Autowire('%kernel.secret%')]`.
   `tokenFor(string $url): string` = base64url(HMAC-SHA256(hkdf(secret, info `link-card-reader`), url)), unpadded.
   `verify(string $url, string $token): void` (`hash_equals`) throws
   `Service/LinkCard/Exception/LinkCardTokenMismatchException`. New `Http/Problem/ExceptionProblems/LinkCardProblems`
   maps it to 404. Unit test: round trip, other URL, tampered token, other secret.
2. **Drop `position` from the read side.** `PlacedLinkCardModel` goes; `LinkCardRepository` /
   `ThreadPostRepository` hand out `LinkCardContent` (still ordered by the stored position column).
   `EntryListRowEnrichment::$linkCards` and `ThreadPostModel::$linkCard` take `LinkCardContent`.
   `LinkCardRepository::contentAt()` and its tests go.
3. **Injected mappers.** `LinkCardJson` (instance, holds `LinkCardSignature`; `of()` adds `readerToken`, `placed()`
   goes), and so `ThreadPostJson`, `CommentsJson`, `EntryJson`, `EntryPage`, `SearchPage`, `SavedSearchPage`,
   `RecommendationFeedJson` become `final readonly` services; every caller injects them. Tests build them with a
   real `LinkCardSignature('test-secret')`.
4. **Endpoint.** `LinkCardReaderController`: `GET /api/link-cards/reader?url=&token=`
   (`#[MapQueryParameter]`), verify → reader rate limit → `LinkCardArticleReader::read(string $url)` →
   `ReaderJson::one($result, null, now)`. The card's title hint and lead image are dropped server-side; the client
   leads with the card image it holds. Controller test: ok, forged token 404, token of another URL 404, missing
   params 404, rate limited 429, unauthenticated 401.

## Frontend

5. `LinkCardDto.readerToken`; `PlacedLinkCardDto` and `linkCardAt()` go.
6. `query/link-param.ts`: `LinkCardRef = { url, token }`, `?link=<token>~<url>`; parse splits at the first `~`,
   requires a 43-char base64url token and an http(s) URL.
7. `LinkReaderNavigation.layer(card)` / `openAlone(card)` take the card and remember it;
   `ReaderRouteState.openLinkCard` is that card when its URL matches, else the first card with that URL among the
   listed entries' root and reply cards (deep link), else null.
8. `ReaderApi.linkCardReaderContent` → `/api/link-cards/reader` with `url`/`token` params; cache key `card:<url>`.
9. `LinkArticleSource.lead` falls back to the card's `imageUrl` when the failure carries no hero.
10. Call sites pass the card: `link-cards`, `thread-replies`, `entry-row`, `entry-link`, `entry-thread`; and
    `entry-comments` gets `[openableInReader]="true"` + `layer(card)` — the feature itself.

## Verify

`composer check`, `composer md`, `php bin/phpunit` (SQLite) and the MySQL leg, `infection:diff`;
`npm run check` in the frontend container; live: open a comment's card on entry 626026.
