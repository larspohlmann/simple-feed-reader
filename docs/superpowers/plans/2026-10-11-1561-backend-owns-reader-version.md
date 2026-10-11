# #1561 — The backend owns the reader-output version

**Goal:** a change to what `/api/entries/{id}/reader` returns invalidates the SPA's IndexedDB
article cache without anyone remembering a bump. A backend extraction PR stays backend-only.

## Rulings

- **The version is derived, not bumped.** A compiler pass fingerprints the sources that shape the
  reader output and stores the hash as the `app.reader_output_version` parameter. Nothing to
  forget, so no guard test is needed for forgetting; the tests pin that the fingerprint moves
  when a source moves.
- **Sources:** the `Service/Reader` module and every `Service/*` module it reaches through `use`
  imports (the module graph is acyclic, `ServiceModuleCycleRule`), the non-service `App\` classes
  those modules import directly (one hop, not recursive: following entities reaches all of `src`),
  `src/Http/ReaderJson.php`, `config/services.yaml` (step order) and the non-dev packages of
  `composer.lock` (readability.php, the sanitizer). Today that is 9 modules.
- **Over-invalidation is accepted.** A refactor in those modules drops each client's cache once per
  deploy, costing a re-extraction only when a user reopens an article. Under-invalidation (the
  bug) is what the issue is about.
- **Dev freshness:** the pass registers the sources as container resources, so the dev container
  (and the version) rebuilds when one changes.
- **Channel:** a `Reader-Version` header on every `/api` response (the client learns the current
  version from whatever call it makes first), plus a `readerVersion` field on the `ok` reader
  body, so a cached article records which version produced it. JSON body, a plain header,
  stateless, bearer-only: passes the §6 checklist; an iOS client reads both the same way.
- **Frontend:** an interceptor records the header; `ReaderCacheService.get` hits only when the
  stored article's `readerVersion` equals the current one. Unknown current version = miss. The
  IndexedDB `VERSION` stays, now only the storage-schema version the frontend owns.

## Files

- `backend/src/DependencyInjection/ReaderOutputVersionPass.php`, `ReaderOutputSources.php` (new)
- `backend/src/Kernel.php` (register the pass)
- `backend/src/EventListener/ReaderVersionHeaderListener.php` (new)
- `backend/src/Http/ReaderJson.php` (service with the version; `readerVersion` field)
- `backend/src/Controller/Api/EntryReaderController.php`
- `frontend/src/app/core/http/reader-version.ts` (service + interceptor, new), `app.config.ts`
- `frontend/src/app/reader/models.ts`, `reader/article/content/reader-cache.service.ts`
- tests next to each; CLAUDE.md-adjacent docs where the manual-bump rule is described

## Tasks

1. Pass + sources, test-first: same tree → same version; touching a reached module, `ReaderJson`,
   `services.yaml` or a non-dev package moves it; an unreached module does not.
2. Header listener and `readerVersion` field, with a functional test over the real container.
3. Frontend: interceptor, cache comparison; specs pin mismatch → miss, match → hit, unknown → miss.
4. Gates, simplify, PR.
