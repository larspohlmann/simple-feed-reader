# #1539 Embed providers: one target() replaces matches/normalize/poster/label

**Goal:** `EmbedProviderInterface` shrinks from six methods to three: `target(string $url): ?EmbedTargetModel`,
`frames()`, `sourceHosts()`. Each provider parses a URL once; `EmbedProviders::resolve()` returns the first
non-null target.

## Rulings

- Pure refactor: `/api/entries/{id}/reader` output is unchanged, so the frontend `ReaderCacheService` VERSION
  stays.
- Each provider's label becomes a private `LABEL` constant passed into the model it builds.
- YouTube URL parsing does not move (#1538 owns that); `target()` just parses once and derives the embed URL
  and the poster from the same parts.
- Tests: `normalize($url)` becomes `target($url)?->url`, `poster()` becomes `target(...)?->posterUrl`,
  `label()` becomes `target(valid)->label`, a `matches()` false becomes `assertNull(target(...))`, and a
  `matches()` true that sat next to a `normalize()` assertion goes (the URL assertion already proves it).

## Files

- `backend/src/Service/Reader/Media/EmbedProvider/EmbedProviderInterface.php`
- `backend/src/Service/Reader/Media/EmbedProvider/{Brightcove,Dailymotion,SoundCloud,Spotify,Vimeo,YouTube}EmbedProvider.php`
- `backend/src/Service/Reader/Media/EmbedProviders.php`
- `backend/tests/Service/Reader/Media/EmbedProvider/*EmbedProviderTest.php`

## Tasks

1. Swap the interface's four methods for `target()`.
2. Port each provider and `EmbedProviders::resolve()`.
3. Port the provider tests.
4. Gates: cs, stan, tramp, md, test:parallel, MySQL leg, infection:diff; simplify pass; PR.
