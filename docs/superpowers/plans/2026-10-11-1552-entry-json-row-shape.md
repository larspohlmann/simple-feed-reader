# #1552 EntryJson: declare the row shape once

**Goal:** `EntryJson` states its wire row in one `@phpstan-type` alias, so a new entry field is one shape edit
instead of three.

## Rulings

- PHPStan has no array-shape spread, so an alias for the common fields cannot be extended by `listRow()` and
  `detail()`. Returning `array<string, mixed>` would cost the tests their typed nested access
  (`$json['duplicates'][0]['id']`). Instead: one alias `EntryRow` for the full row, with `contentHtml` as its one
  optional key (only `detail()` adds it).
- `commonFields()` only existed to hold the shared part; `listRow()` now builds the whole row itself and
  `commonFields()` goes. `detail()` stays `listRow() + contentHtml`.
- PHPStan merges no intersection of array shapes (`EntryRow&array{contentHtml: …}` loses every other key), and the
  project carries no phpstan-phpunit to narrow on `assertArrayHasKey`, so the one test reading `contentHtml` reads
  it as `$json['contentHtml'] ?? null` — a missing key still fails the assertion.
- Consumers (`EntryPage`, `RecommendationFeedJson`, the two controllers) are untouched.

## Files

- `backend/src/Http/EntryJson.php`
- `backend/tests/Http/EntryJsonTest.php`

## Tasks

1. Add the `EntryRow` alias to the class docblock; `listRow()` and `detail()` return it.
2. Inline `commonFields()` into `listRow()`, adding `excerpt` and `duplicates` in place.
3. Gates: cs, stan, md, tramp, tests (SQLite + MySQL), infection:diff.
