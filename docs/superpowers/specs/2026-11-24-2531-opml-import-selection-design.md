# OPML import: choose the feeds, set a parent tag, tag single feeds (#2531)

Agreed with Lars on 2026-11-24 in a question-by-question review. The issue carries the same text.

## Flow

1. Settings → OPML takes a **file** only. The paste box and its code go.
2. `POST /api/opml/preview` reads the file with `OpmlBodyReader` and returns the folder tree. Each feed carries a
   state (new, already subscribed, not supported with its reason) and the response carries the account's free
   subscription slots. An unreadable file shows its error in the card and opens nothing.
3. A dialog opens:
   - The OPML folders as a tree. A folder checkbox selects or clears its importable feeds and shows a partly-selected
     state. Select all / none. Everything importable starts selected.
   - Feeds already subscribed and feeds that cannot be imported are greyed out and not selectable; the unsupported
     ones show why.
   - A feed URL that appears in several folders is listed once, under its first folder.
   - An optional **parent tag** field: pick an existing top-level tag or type a new name. A typed name is created on
     import, not before.
   - A row shows checkbox, title, host and a muted destination line (`→ From Feedly / Tech (1)`).
   - **Change tags** on a row swaps the destination line for the shared tag picker (`app-tag-picker`). The picked
     tags replace the folder and parent placement for that feed only; **Use folder** undoes it. A tag created in the
     picker is saved right away, as in the add-feed dialog.
   - A counter (`12 selected · 8 slots left`). Import is disabled while the selection exceeds the free slots.
   - When the folders are deeper than `Tag::MAX_DEPTH` allows once the parent is added, the outer folders are kept
     and the preview names the folders that are dropped.
4. **Import** sends the file again with the selected feed URLs, the per-feed tags and the parent tag; the server
   reads the file again. This replaces the raw-XML `POST /api/opml/import`. On success the dialog closes, the card
   shows imported / already subscribed / invalid, and the refresh starts. On failure the dialog stays open with the
   choices kept.

## Placement rules (OPML import only)

- With a parent tag, every imported feed lands under it, feeds outside any folder included.
- A folder reuses an existing tag only when that tag already sits at exactly the same spot (same parent). Any other
  tag of that name leaves the folder a new tag named `Tech (1)`, `Tech (2)`, … inside the import structure,
  untranslated, the base name shortened to stay within 100 characters. Existing tags are never moved.
- A typed parent name that matches a nested tag becomes a top-level `Name (1)` by the same rule.
- A tag is created only for a folder that ends up with at least one imported feed.
- A feed's title is seeded from the OPML (`text`, then `title`).
- Catalog bundles, social imports and private-feed sync keep their current tagging (`BulkTagger::tag`).

## Rulings made while planning

1. **The numbered name is found, not counted.** A folder named `N` at spot `P` takes the first of `N`, `N (1)`,
   `N (2)`, … that is either free or already a tag sitting at `P`. So importing the same file into the same parent a
   second time reuses `Tech (1)` instead of making `Tech (2)`.
2. **Names are planned for every folder in the file, in document order, whatever is selected.** The destination a row
   shows is the one the import produces. The price: with `A/Tech` and `B/Tech` in one file, `B/Tech` is `Tech (1)`
   even when nothing from `A/Tech` is imported.
3. **Names compare as the tag table does**: case-insensitive, accent-sensitive (`TagRepository::existsForUserAndName`).
   A folder that reuses a tag takes the stored spelling.
4. **A chosen tag id that is not the account's is ignored**, as `PATCH /api/subscriptions/{id}` ignores it.
5. **Change tags with no tag picked means untagged**, not "use folder".
6. **Wire format.** Both endpoints take JSON. Preview: `{ "opml": string, "parentTagName": string|null }`. Import adds
   `"feedUrls": list<string>` and `"tagIdsByFeedUrl": { url: list<int> }`, which holds only the feeds whose tags were
   changed. The file travels as a string field: no multipart, so a native client can send it (architecture §6).
7. **A parent change asks the server for the preview again** (debounced). The naming rule exists once, in PHP.
8. **The dialog is an `app-overlay-panel`**, 760px wide with `fillOnMobile`: the full screen on a phone, a card on a
   desktop, as the design language asks of every dialog. Lars agreed to "full-screen"; a desktop full-screen surface
   would be the first, so this is flagged for him at the plan review.
9. **`skippedOverLimit` stays in the import response** for a selection that outruns the cap between preview and
   import; the settings card's result line no longer shows it.
10. **Nameless groups pass through**, as today: their feeds and folders belong to the folder above.

## Wire shapes

`POST /api/opml/preview` → `200`

```json
{
  "freeSlots": 8,
  "root": {
    "name": null,
    "dropped": false,
    "destination": ["From Feedly"],
    "feeds": [{ "url": "https://a.example/feed.xml", "title": "A", "state": "new" }],
    "folders": [
      {
        "name": "Tech",
        "dropped": false,
        "destination": ["From Feedly", "Tech (1)"],
        "feeds": [],
        "folders": []
      }
    ]
  }
}
```

`state` is one of `new`, `subscribed`, `scraped`, `invalid_url`. A dropped folder carries the destination of the
nearest folder above it that is kept.

`POST /api/opml/import` → `200 { "imported": 3, "alreadySubscribed": 0, "invalid": 0, "skippedOverLimit": 0 }`

An unreadable, empty or over-1-MB file answers `422 invalid_opml` on both.
