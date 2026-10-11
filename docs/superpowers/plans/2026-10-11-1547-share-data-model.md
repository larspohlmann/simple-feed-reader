# #1547 Share data model: drop share_url, take the share out of EntryLocation

**Goal:** an entry stores only what it shares (`share_kind`); the target is derived in one place
(`ShareTargets`), and the share no longer lives in `EntryLocation`.

## Verified against the code

- Every non-null `Share::$url` comes from `EntryShareClassifier::titledShare()` →
  `TitledShareTarget::of($entry)`, which returns the entry's own url. Reads: `ShareTargets::uncardedOf()`,
  `ShareLinkCards::settleMediaTarget()`, `EntryShareClassifier::describeTitledShare()`, the backup line.
- `EntryJson` exposes only the kind (`'share' => 'link'|'media'|null`); the reader payload never reads the
  url, so no `ReaderCacheService` VERSION bump.
- `Entry` holds exactly 15 fields (PHPMD `TooManyFields`).
- Reddit `[link]` targets are out of scope in the #1524 spec; `share_url` was not reserved for them.

## Rulings

1. **Derived target.** `ShareTargets::titledOf($entry)`: null for a post (title derived), else
   `TitledShareTarget::of($entry)`. Recomputing the host rule (not "the entry's url") keeps today's
   behaviour exactly where the stored url was null for a titled entry:
   - a titled AT post awaiting its embed is `link` with no target today; on a Bluesky feed its url is on the
     feed's site, so `TitledShareTarget` gives null and it still takes its body's links (pinned by a test);
   - a titled YouTube short is `media` with no target; `VisualMedium::at()` of a shorts url is null, so
     `describeTitledShare()` still puts no medium first.
   Residual difference, accepted: a titled share whose feed later changes its site url derives its target
   anew rather than keeping the one stored at classification.
2. **`Share` goes.** With the url gone it is only its kind, so `Entry` takes a plain `?ShareKind`
   (`getShareKind()`/`setShareKind()`); the entry JSON's `share` field keeps its shape.
3. **Field cap.** `guid` + `guidHash` pair into an `EntryGuid` embeddable (columns `guid`/`guid_hash`
   unchanged, `columnPrefix: false`), mirroring `EntryLocation`'s url + urlHash. DQL reads `e.guid.hash`.
4. **Backup.** `shareUrl` leaves the entry line and `EntryLine`; an old file's `shareUrl` key is ignored
   (pinned by a restore test).
5. **Migration** drops `entry.share_url`; `down()` re-adds it and refills it from `url` for titled shares.
6. `EntryShareTest` → `EntryShareKindTest`.

## Files

- `src/Entity/{Entry,EntryLocation,EntryGuid(new),Share(delete)}.php`
- `src/Service/Share/{EntryShareClassifier.php,Support/ShareTargets.php}`
- `src/Service/LinkCard/ShareLinkCards.php`, `src/Service/Bluesky/{PostEnricher,EntryEmbedWriter/EntryEmbedWriter}.php`,
  `src/Service/Completion/ShareEnrichmentQueue.php`, `src/Http/EntryJson.php`
- `src/Service/Backup/{BackupLines,Dto/EntryLine}.php`, `src/Repository/{EntryBatchInserter,EntryRepository}.php`
- `migrations/Version20261011200000.php`
- tests mirroring the above

## Tasks

1. Tests first: `ShareTargetsTest` (titled target derived; titled AT post on a Bluesky feed takes its body links),
   restore tolerates `shareUrl`.
2. `EntryGuid` embeddable; `Entry` gets `?ShareKind $shareKind`; `EntryLocation` back to url + urlHash; delete `Share`.
3. Callers move to `ShareKind` and to `ShareTargets::titledOf()`.
4. Backup + batch inserter drop `shareUrl`/`share_url`.
5. Migration; migrate-from-empty on SQLite and MySQL + `doctrine:schema:validate`; apply to the live Docker DB.
6. Gates, simplify pass, PR.
