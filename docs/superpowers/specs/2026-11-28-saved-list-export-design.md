# Saved list export (#2585)

## Intent

Readers keep articles in Favorites and Kept and sort them with labels (#2581). They want to take that
collection elsewhere: into a bookmark or read-later tool, into another feed reader, or into an archive of
their own. Today the only way out is the account backup, which nothing else reads.

## Goals

- Export **Favorites**, **Kept**, **one label** inside either, or either list's **Unlabeled** folder as a file.
  The file holds exactly the entries the matching sidebar list shows, in the order the list shows them.
- Two formats:
  - **Netscape Bookmark HTML** (`.html`): for browsers, Raindrop, Linkding, Wallabag, Pinboard.
  - **Google Reader starred JSON** (`…-starred.json`): for feed readers, FreshRSS first. It carries the content,
    the star and the labels.
- **One Export item** in the `…` menu of each exportable row. It opens a dialog to pick the format, then the file
  downloads.
- Adding a format later is one backend writer and one route, one frontend list entry, and its i18n strings. The
  menus and the dialog layout stay as they are.

## Non-goals

- Atom, Markdown-zip, CSV, EPUB/PDF. Research for the issue found no reader that imports an Atom file of saved
  articles. FreshRSS takes OPML, Google Reader-style JSON and Tiny Tiny RSS XML; Miniflux and NetNewsWire take
  OPML only.
- Exporting the scraped article. It is never stored, and fetching it per entry during an export is a network call
  per article, too slow and too heavy for Strato's worker. The export carries the feed's content (Lars, this
  session).
- New `favoritedAt`/`keptAt` timestamps. Only flags are stored, and existing favorites have nothing to backfill
  from.
- A live, token-protected label feed. That's a possible follow-up, and the right place for Atom.
- Import of either format into this app.

## Anti-goals

- An export that holds entries the sidebar list doesn't show, or misses some it shows, or that leaks another
  user's entries or labels.
- A file the target tool rejects or half-imports: bad escaping, invalid JSON, labels or the star lost.
- An export that loads the whole list into memory.
- Anything only a browser can use. A native iOS client must be able to call the endpoints with a Bearer token and
  get the file (architecture §6).

## Approaches

- **A. One endpoint with a format registry.** `GET /api/exports/entries?format=…`, with writers picked through a
  keyed locator. A new format would be just a writer.
- **B. One endpoint per format (picked by Lars).** `GET /api/exports/bookmarks` and
  `GET /api/exports/google-reader`. Each route is explicit; a new format adds a route and controller action next to
  its writer.
- **C. Build the file in the browser** by paging through `/api/entries`, like the OPML export. Rejected: a large Kept
  list would sit in the phone's memory, a native client would have to rebuild both formats itself, and the issue
  asks for a streamed endpoint.

## Design

### Endpoints

- `GET /api/exports/bookmarks?view=favorites|kept[&label=<id>|unlabeled][&order=asc]`
- `GET /api/exports/google-reader?view=favorites|kept[&label=<id>|unlabeled][&order=asc]`

Both behave the same way:

- **Order** (*my call*): `order` is read with `ListOrder::fromRequestValue`, as `/api/entries` reads it. It
  defaults to newest first. The dialog passes `ListOrderService.orderFor(selection)`, so the file follows the
  direction the reader set for that list on this device. The direction is part of the keyset cursor, and both
  directions are tested.
- **Auth:** Bearer only, and they act for the signed-in user only.
- **Response:** a `StreamedResponse` with `Content-Disposition: attachment; filename="…"`. Media types are
  `text/html; charset=UTF-8` and `application/json`.
- **Errors:** `application/problem+json`, decided before the first byte streams.
  - A `view` other than favorites or kept, or a malformed `label`, is 422 `validation_error`, as `LabelScope`
    already throws.
  - A label id that doesn't exist or isn't the user's is 404, through `LabelRepository::requireOneForUser`. *My
    call:* the entry list returns an empty page here, but an export of a label you don't have should say so rather
    than download an empty file.
- **File names** (*my call*): `simplefeedreader-<list>[-<label>]-<Ymd>.html` and
  `simplefeedreader-<list>[-<label>]-<Ymd>-starred.json`. `<list>` is favorites or kept, and `<label>` is the label
  name, or `unlabeled`, sanitised to `[a-z0-9_-]` like `BackupFilenameModel`, with the date from `ClockInterface`.
  The JSON name must contain `starred` and end in `.json`, because FreshRSS picks its importer by file name.
- **Controllers** stay thin: they read the request, hand the service a scope value and the user, and return the
  streamed response.

### Backend shape

- **New module:** `Service/Library/SavedListExport`, in the `Library` group next to `EntryList`, `Opml` and
  `SavedPosts`.
- **Walk:**
  - A service yields the scope's entries in batches of 500, in the sidebar list's order (`effectiveDate`, then
    id), on a keyset cursor.
  - It clears the entity manager after each batch and bulk-loads each batch's labels
    (`EntryLabelRepository::findLabelsByEntryIds`). The feed and the user's subscription name are loaded with the
    batch.
  - It uses the same scope predicates as the entry list, so the export can't drift from the sidebar: the user's
    subscriptions, view flag, label `EXISTS`/`NOT EXISTS`, and duplicate collapse. Whether it reuses
    `EntryListRepository::listForUser` with a cursor or a new repository method that composes the same predicates
    is left to the builder. The query lives in `src/Repository`.
- **Writers:** one per format.
  - Each writes a header, a chunk per batch and a footer to an output stream, and gives its media type and file
    extension.
  - Writers live in `src/Http`, since they shape a response. The walk yields module models.
  - The two controllers call their writer directly; there's no locator, per approach B.
- **Data per entry:**
  - The entry's GUID, URL, title, author name and effective date.
  - Its content: `contentHtml`, falling back to `summary` (*my call*). Both are already sanitized at ingest by
    `EntrySanitizer`.
  - Its feed (title, feed URL, site URL), with the subscription's own name where the user set one (*my call*).
  - Its label names.

### Bookmark HTML

- The standard header: `<!DOCTYPE NETSCAPE-Bookmark-file-1>`, the `META` charset, `<TITLE>Bookmarks</TITLE>`,
  `<H1>Bookmarks</H1>`.
- **Structure:** one `<DL><p>` holding one `<H3>` folder named after the export. The name is the translated list
  name for a whole list, or "Kept – <label>" for a label or Unlabeled. The entries go in a `<DL><p>` inside it.
  - *My call:* the issue only asked for a folder when exporting a whole list, but a folder per export keeps imports
    from different exports apart in the target tool. Folder names are built in English on the server, since the
    endpoint has no locale. That's a small known gap, and the builder may pass a locale if it's trivial.
- **Each entry:** `<DT><A HREF="<url>" ADD_DATE="<unix seconds>" TAGS="<label,label>"><title></A>`.
  - `ADD_DATE` is the entry's effective date, since no favorited or kept date is stored.
  - `TAGS` is the entry's label names joined by commas. A comma inside a label name becomes a space (*my call*),
    since `TAGS` has no escape for it.
  - The attribute values and the title are HTML-escaped with `ENT_QUOTES | ENT_SUBSTITUTE`.
- An entry without a URL is skipped (*my call*): a bookmark has nothing to point at.

### Google Reader starred JSON

The JSON is streamed as `{"id":…,"title":…,"updated":…,"items":[` first, then items separated by commas, then
`]}`.

- **Top level:**
  - `id`: `user/-/state/com.google/starred`.
  - `title`: the export's name, as for the bookmark folder.
  - `updated`: now, in Unix seconds.
- **Each item, in the shape FreshRSS's `importJson()` reads:**
  - `id`: the entry's GUID.
  - `title`, and `author`, omitted when empty.
  - `published` and `updated`: the effective date, in Unix seconds, UTC.
  - `alternate`: `[{"href": <url>, "type": "text/html"}]`, omitted when the entry has no URL.
  - `content`: `{"direction": "ltr", "content": <html>}`.
  - `origin`: `{"streamId": "feed/<feed url>", "title": <feed name>, "htmlUrl": <site url>}`. This applies only
    when the feed URL is http(s).
    - Internal feeds get `{"title": <feed name>}` alone (*my call*). These are the `saved:<userId>` placeholder
      that rescued posts move to (`SavedFeedUrl`) and the `social:…` feeds (`SocialFeedUrl`).
    - Why: FreshRSS takes `feedUrl`, then `streamId`, then `htmlUrl` as the feed to subscribe to. With none of them
      it files the item under a disabled "Import" feed (`http://import.localhost/import.xml`).
    - So internal entries import without a broken subscription, and no internal user or account id leaves in the
      file.
  - `categories`: `user/-/state/com.google/starred`, plus `user/-/label/<name>` for each label.
- **Encoding:** each item is encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
  JSON_INVALID_UTF8_SUBSTITUTE`.
- **Kept entries are starred too.** Every item carries the starred state, Kept or Favorites. That's how FreshRSS
  marks an imported article as saved, and the list's own name survives as the top-level title.

### Frontend

- **Menus:**
  - **Favorites and Kept heads:** add a `…` menu (`app-sidebar-row-menu`) in the slot they leave empty today. On
    coarse screens it becomes a `.dots` button that opens an action sheet, the way label rows work.
  - **Label rows:** Export goes beside Rename and Delete, in both the menu and the action sheet.
  - **Unlabeled row:** a `…` menu, or an action sheet on touch, holding only Export.
- **Export dialog:**
  - Built with CDK Dialog and `app-overlay-panel`, modelled on `new-label-dialog`.
  - The heading names what is exported ("Export Kept – Reading list"). Below it is a radio list of formats, each
    with its name and a one-line description of where it imports. The JSON line mentions that FreshRSS subscribes to
    the entry's feed on import.
  - Cancel, plus a primary Export button that shows a pending state while the download runs.
  - A problem response shows in the dialog through `parseProblemAsync`.
  - On success: blob, `filenameFromContentDisposition`, `saveAs`, and the dialog closes.
- **The format list:** one exported array of `{ id, nameKey, descriptionKey, path, fallbackFilename }`. The dialog
  renders it, and a new format is one entry.
- **i18n:** de, en, fr and es, under `reader.export.*`.

## Testing and done

- **Backend functional tests** for each endpoint:
  - Scope: Favorites, Kept, a label, Unlabeled, and a duplicate-collapsed entry.
  - Ownership: another user's label gives 404, and another user's entries and labels never appear.
  - 422 for a bad view or label.
  - Escaping of `&`, `<` and quotes in titles, URLs, labels and content.
  - Valid JSON with non-ASCII, control characters and invalid UTF-8.
  - UTC Unix dates, an empty list giving a valid empty file, and an entry without a URL.
  - Entries from a `saved:` and a `social:` feed, which get an `origin` with only a title.
  - Both list orders.
  - A list longer than one batch, to cover the batching.
- **Frontend specs:** the menu items on each row kind and on coarse screens, the dialog's format list and download
  path, and the error display.
- **Live checks:**
  - The bookmark file imports into a browser with titles, dates and tags intact.
  - The JSON file imports into a FreshRSS instance (Docker, throwaway) with content, star and labels intact.

## Left to the builder

- Reusing `listForUser` or composing a new repository method, as long as the predicates are shared, not copied.
- The model names inside `SavedListExport`, and how the writer interface looks.
- Whether the export name ("Kept – <label>") is built in the module or in `src/Http`.
