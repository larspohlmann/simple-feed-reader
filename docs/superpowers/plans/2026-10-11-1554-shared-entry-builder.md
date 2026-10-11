# #1554 — Frontend specs: one shared EntryDto builder

**Goal:** a new `EntryDto` field is a one-file change in the Jest specs.

## Rulings

- `src/testing/entry-testing.ts` exports `anEntry(overrides)`, built from the current
  `reader/models.ts` `EntryDto`. Defaults are neutral: no image, no summary, nothing marked.
- Each migrated literal keeps only the fields that differ from the builder's defaults. A spec
  whose local factory carries its own defaults (e.g. `title: 'Big headline'`) keeps a thin local
  wrapper that passes them to `anEntry`; the tests keep asserting what they asserted.
- The quirky `createdAt: 'x'` default is dropped; `audio-attachment` read it as the
  `publishedAt` fallback and now passes a named `createdAt` explicitly.
- `reader-shell` holds one `EntryDto` literal today (the other three the issue counted are
  `state:` bodies of a PATCH response); only that one migrates.
- Specs that build a partial entry with `as EntryDto` (`for-you-runs`, `hidden-overlay`,
  `entry-audio`, `pending-entries-poll`) are untouched: a new field never edits them.
- `aLinkCard()` joins it: five specs spelled out a full `LinkCardDto`.
- A `title`, `source` or `subscriptionId` override that no test reads is dropped, kept only
  where removing it fails the spec (checked one field at a time).

## Files

- Create `frontend/src/testing/entry-testing.ts`.
- Modify the 25 spec files that hold a full `EntryDto` literal, and `link-card.component.spec.ts`.

## Tasks

1. Add `anEntry`.
2. Replace each literal with `anEntry({...})`, dropping default-valued fields.
3. `docker compose exec -T frontend npm run check` green.
