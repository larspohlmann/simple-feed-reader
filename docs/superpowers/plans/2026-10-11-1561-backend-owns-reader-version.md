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
  `src/Http/ReaderJson.php`, `config/services.yaml` (step order) and the installed packages
  (`vendor/composer/installed.php`: readability.php, the sanitizer). Today that is 9 modules.
- **Over-invalidation is accepted.** A refactor in those modules drops each client's cache once per
  deploy, costing a re-extraction only when a user reopens an article. Under-invalidation (the
  bug) is what the issue is about.
- **Dev freshness:** the pass registers the sources as container resources, so the dev container
  (and the version) rebuilds when one changes.
- **Channel: a `Reader-Version` header on every response, and no body field.** The client learns
  the current version from whatever call it makes first, and the reader response carries it too,
  so a cached record is stamped from the very response that brought it. A body field would repeat
  the header (and `models.ts` sits at its lint line cap). A plain header, stateless, bearer-only,
  JSON body unchanged: passes the §6 checklist; an iOS client reads it the same way.
- **Frontend:** an interceptor records the header; `ReaderCacheService.put` stamps the record with
  it and `get` hits only when the stamp equals the current version. Unknown current version =
  miss. The IndexedDB `VERSION` stays, now only the schema of the store the frontend owns.
- **No CORS expose header:** every SPA build calls `/api` same-origin (`apiBaseUrl` is `''` or
  `/reader`), and a native client is not subject to CORS.

## Files

- `backend/src/DependencyInjection/ReaderOutputVersionPass.php`, `ReaderOutputSources.php`, `ImportClosure.php` (new)
- `backend/src/Kernel.php` (register the pass)
- `backend/src/EventListener/ReaderVersionHeaderListener.php` (new)
- `frontend/src/app/core/http/reader-output-version.ts` (service + interceptor, new), `app.config.ts`
- `frontend/src/app/reader/article/content/reader-cache.service.ts`
- tests next to each (no living doc describes the manual bump; the old design specs stay history)

## Tasks

1. Pass + sources, test-first: same tree → same version; touching a reached module, `ReaderJson`,
   `services.yaml` or the installed packages moves it; an unreached module does not.
2. Header listener, with a functional test over the real container.
3. Frontend: interceptor, cache stamp and comparison; specs pin mismatch → miss, match → hit, unknown → miss.
4. Gates, simplify, PR.
