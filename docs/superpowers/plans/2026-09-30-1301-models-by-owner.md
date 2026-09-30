# Settings-owned shapes and endpoints leave the reader — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Settings-owned endpoints move out of `reader/reader-api.ts` and their types out of `reader/models.ts`, into `settings/settings-api.ts` and `settings/settings.models.ts`. Two small duplicate shapes get one home each (#1301).

**Architecture:** This is a verbatim move. Method bodies, URLs and type fields don't change. `ReaderApi` keeps what the reader uses; `SettingsApi` takes OPML, backup/restore, reading activity, the recommendation debug log and run history. `RecommendationRunReport` stays in the reader, because `RecommendationsService` owns it.

**Out of scope (lean):**
- `SubscriptionTagDto` vs `TagDto`: the same fields with different `position` semantics, and the doc says so.
- `magazineStyle: string`: a wire value that `asMagazineStyle()` validates.
- Admin tag shapes: they carry extra fields.
- Other small splits of `models.ts`.

## Global Constraints

- Starts after #1300 has merged. Branch `refactor/1301-models-by-owner` off `develop`. Commit format `type(#1301): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`, plus `npm run build` in the container.

---

### Task 1: SettingsApi and settings.models

**Files:**
- Create: `frontend/src/app/settings/settings-api.ts`, `frontend/src/app/settings/settings.models.ts`
- Rename: `frontend/src/app/settings/backup-api.spec.ts` → `settings-api.spec.ts`
- Modify: `reader/reader-api.ts`, `reader/reader-api.spec.ts`, `reader/models.ts`, and the consumers listed in Step 4

- [ ] **Step 1: Types.** Move these interfaces verbatim, with their doc comments, from `reader/models.ts` into `settings/settings.models.ts`:
  - `OpmlImportResult`
  - `DebugLogEntry`, `DebugLogRunChoice`, `DebugLogRunSummary`, `DebugLogPayload`, `DebugLogDetail`
  - `RunHistoryRow`, `RunHistoryMonth`, `RunHistoryMonthPage`, `RunHistoryOverview`
  - `ReadingDay`, `FeedReadCount`, `ReadingActivity`
  - `RestoreCounts`, `RestorePreview`, `RestoreResult`

  If any of them references a type that stays in `reader/models.ts`, import that type from `../reader/models` (settings→reader is allowed).

- [ ] **Step 2: API.** Create `settings/settings-api.ts`:

```ts
import { HttpClient, HttpResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  DebugLogDetail,
  DebugLogPayload,
  OpmlImportResult,
  ReadingActivity,
  RestorePreview,
  RestoreResult,
  RunHistoryMonthPage,
  RunHistoryOverview,
} from './settings.models';

@Injectable({ providedIn: 'root' })
export class SettingsApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
}
```

  Then move these methods verbatim (signature, body and doc comment) from `ReaderApi` into the class body:
  - `exportOpml`, `importOpml`
  - `downloadAccountBackup`, `previewAccountRestore`, `startAccountRestore`, `restoreEntryPart`
  - `debugLog`, `debugLogEntry`
  - `readingActivity`
  - `runHistory`, `runHistoryMonth`

  Add `HttpParams` to the import if one of them uses it. Afterwards, delete from `ReaderApi` any imports that are now unused.

- [ ] **Step 3: Specs.**
  - `git mv frontend/src/app/settings/backup-api.spec.ts frontend/src/app/settings/settings-api.spec.ts`, then point it at `SettingsApi`.
  - Move the `exportOpml`, `importOpml`, `downloadAccountBackup`, `debugLog` and `debugLogEntry` cases (around lines 333–355 and 468–480) from `reader/reader-api.spec.ts` into it, retargeted to `SettingsApi`. Keep each assertion unchanged.
  - Add the `API_BASE_URL`/HTTP testing providers there only if the file doesn't already have them.

- [ ] **Step 4: Consumers.** Switch these from `ReaderApi` to `SettingsApi` for the moved calls. Keep `ReaderApi` in a file only if it still calls a reader method.
  - `settings/opml-export.ts`: its parameter becomes `api: SettingsApi`.
  - `settings/opml-section.component.ts` and `settings/backup-section.component.ts`: they pass their api to `downloadOpmlExport`.
  - `settings/backup-restore-run.ts`
  - `settings/about-section.component.ts`
  - `settings/recommendation-debug-log.component.ts`
  - `settings/recommendation-run-history.component.ts`

  Then fix each moved type's importers with `git grep -l "<TypeName>" frontend/src`. The importers are:
  - `reading-chart`
  - `backup-restore-run`
  - `backup-section`
  - `about-section`
  - `recommendation-debug-log`
  - `recommendation-run-history`
  - `recommendation-run-history-month`
  - `run-history-status-icon`
  - `opml-section`
  - their specs

  Update the specs that provide or spy on `ReaderApi` for these calls so they provide `SettingsApi` instead.

- [ ] **Step 5: Verify.** `git grep -n "exportOpml\|importOpml\|AccountBackup\|AccountRestore\|restoreEntryPart\|readingActivity\|debugLog\|runHistory" frontend/src/app/reader` prints nothing. Run the gate and the build. Commit `refactor(#1301): settings endpoints and shapes live in settings`.

### Task 2: One home for two duplicate shapes

**Files:** `frontend/src/app/core/auth.service.ts`, `frontend/src/app/settings/recommendation-debug-log.component.ts`

- [ ] **Step 1: Digest config.** In `core/auth.service.ts`, replace the field list of `UserDigestPreferences` with an extension of `DigestConfig` (from `./digest-writer`). Keep its `timezone` doc comment, trimmed to one line:

```ts
export interface UserDigestPreferences extends DigestConfig {
  /** The instance's `APP_TIMEZONE`, read-only: the send hour is interpreted in it. */
  timezone: string;
}
```

- [ ] **Step 2: Name clash.** In `settings/recommendation-debug-log.component.ts`, rename `RunGroup` to `DebugLogRunGroup` in every occurrence in that file and its spec. `reader/for-you-runs.ts` keeps `RunGroup`.

- [ ] **Step 3:** Run the gate, commit `refactor(#1301): digest preferences extend DigestConfig; debug-log run group has its own name`, open the PR (`Closes #1301`) and merge when green.
