# Entry `media[]` + `attachments[]` — design (#906)

**Status:** approved design, pre-implementation.
**Scope:** foundation only. This spec builds the additive feed-media model. It
does **not** change the reader scraper pipeline. The complexity-removal wins the
model enables (poster rescue, kind/dimension trust, podcast player, dedicated
media views) become separate follow-on issues.

## 1. Problem

An entry stores exactly one lead image. The feed parser already walks the media
nodes that carry richer media — `<enclosure>`, `<media:content>`,
`<media:thumbnail>`, `<media:group>` — but `MediaImageClassifier` and
`ItemImageExtractor` classify-and-drop everything that is not the one best
image. So we discard, at the parser, media metadata the feed hands us for free
in XML we already parse: podcast and video enclosures, `duration`, `mime_type`,
`sizeInBytes`, extra images, and their per-item `width`/`height`.

## 2. Goal

Keep that feed-supplied media. Add two additive lists to the entry, populated
only from feed metadata that is already parsed. No image fetch, no decode, no new
outbound HTTP. The single lead image stays exactly as it is today, so every
current reader, magazine, hero, and preview consumer is unaffected.

## 3. The two lists

**`media[]`** — visual media the client can display. Each element:
`{ url, kind, width?, height?, previewImageUrl? }`, where `kind` is `image` or
`video`. `media[0]` is the lead image (self-contained list, per the approved
contract): a native client renders the whole visual set from this one list.

**`attachments[]`** — playable or downloadable enclosures. Each element:
`{ url, mimeType?, durationInSeconds?, sizeInBytes?, title? }`.

**Routing rule (one media node → which list):**

| Feed node | `media[]` | `attachments[]` |
|---|---|---|
| image (`<media:thumbnail>`, `image/*`, `medium=image`, image extension) | Image | — |
| video (`video/*`, `medium=video`, video extension) | Video (+ poster from a sibling `<media:thumbnail>`) | video enclosure (mime/duration/size) |
| audio (`audio/*`, `medium=audio`, audio extension) | — | audio enclosure |
| enclosure with no recognizable type/extension | — | only when clearly non-image (the classifier's image test, inverted); otherwise skipped |

A video is both displayable and playable, so it appears in both lists. This is
the only intentional duplication and it matches the issue.

**`media[]` ordering.** `media[0]` is the persisted lead image (the unchanged
widest-wins selection, passed through the same URL gate as today). The remaining
images follow in document order, deduplicated by URL. When the lead is dropped by
the URL gate, `media[]` simply starts with the next surviving image, so
`media[0].url === getImageUrl()` holds whenever a lead survives.

## 4. Parser

`MediaImageClassifier::isImage` is unchanged in behaviour but stops being a
*drop* gate — it becomes the *router* the new extractor consults.

New `ItemMediaExtractor` (sits beside `ItemImageExtractor`, same static-method
shape, URLs returned verbatim). It walks the same nodes — `<media:content>`,
`<media:thumbnail>`, `<media:group>` children, RSS `<enclosure>`, Atom
`<link rel="enclosure">` — and returns a small `ParsedMediaBundle`
(`media: list<ParsedMedium>`, `attachments: list<ParsedAttachment>`). Dimensions
come from the `width`/`height` attributes already parsed by
`ItemImageExtractor::positiveInt`; `duration` is a plain integer of seconds;
`sizeInBytes` from `<enclosure length=…>` / `fileSize`.

New VOs next to `DeclaredImage` in `App\Service\Image` (or `App\Service\Parser`,
matching where `DeclaredImage` lives):
- `ParsedMedium` (`final readonly`): `url`, `kind` (`ParsedMediaKind { Image, Video }`),
  `?width`, `?height`, `?previewImageUrl`.
- `ParsedAttachment` (`final readonly`): `url`, `?mimeType`, `?durationInSeconds`,
  `?sizeInBytes`, `?title`.

`ParsedEntry` gains `media: list<ParsedMedium> = []` and
`attachments: list<ParsedAttachment> = []`.

The four format parsers (`Rss2Parser`, `Rss1Parser`, the Atom parsers,
`WordPressJsonParser`) call `ItemMediaExtractor` once per item next to their
existing image call and pass the bundle into `ParsedEntry`. The lead-image
precedence chains are untouched. The extractor is invoked from each parser (not
threaded through a shared signature) to avoid tramp data.

## 5. Ingest / persist

`EntryIngestor` maps the two lists onto the entity after `applyImage`.

**URL gate.** Every media and attachment URL passes the same
`HttpsImageUrl::orNull` rule the lead image uses: a protocol-relative `//host` is
upgraded to `https:`, anything not `https://` is dropped, and a URL over the
2048-char column limit is dropped (never truncated). Rationale: the SPA is served
over https, so `http:` media is mixed-content-blocked; the same gate already
governs every persisted image URL, and a native client is no worse off. An
element whose URL is dropped is omitted from its list. `media[0]` is built from
the already-gated lead URL so it stays consistent with `getImageUrl()`.

The lists are built together with the lead, mirroring `applyImage`'s
"set together, never leave stale data" invariant.

## 6. Entity + storage

`Entry` must stay within PHPMD's `TooManyFields` ceiling (default 15; `Entry` is
at 14 today, which is exactly why `EntryImage` was bundled into an embeddable).
Two loose columns would breach it. So both lists live in **one** new embeddable:

`EntryMedia` (`#[ORM\Embeddable]`) with two `#[ORM\Column(type: Types::JSON, nullable: true)]`
columns, `media` and `attachments`, embedded into `Entry` with
`columnPrefix: false` — one new field on `Entry` (→ 15), two new columns on the
`entry` table. This parallels `EntryImage` exactly.

The array↔VO mapping is owned by the embeddable and two entity-level readonly
VOs (`EntryMedium`, `EntryAttachment`, `JsonSerializable`), so `Entry` gains only
thin typed accessors: `getMedia(): list<EntryMedium>`,
`getAttachments(): list<EntryAttachment>`, and one `setMedia(list, list)` that
stamps both together. Persisted JSON is a list of plain objects; empty lists
persist as `null` (the "no media" first-class case, like a null image).

`getImageUrl()/Width()/Height()` and the `EntryImage` embeddable are untouched.

## 7. Migration

One Doctrine migration adds the two nullable columns to `entry` (`json` on MySQL,
`TEXT`-backed `json` on SQLite via Doctrine's platform mapping). Verified against
the dedicated migrate-from-empty CI leg on **both SQLite and MySQL**, then
`doctrine:schema:validate` stays green. The test suite builds schema from ORM
metadata and will not catch a bad migration, so this leg is the only proof — do
not skip it.

## 8. Backup

`BackupSchema::VERSION` → 3. The backup is JSONL; each entry line gains two
nested arrays `media` and `attachments`.

- `AccountBackupExporter::entryLine()` emits `media`/`attachments` from the entity
  getters (VOs are `JsonSerializable`).
- `EntryLine` DTO gains `media`/`attachments` (typed lists), read back in
  `fromLine()` via a new `LineField` list helper.
- `EntryBatchInserter` adds the two columns to its `COLUMNS` list and binds each
  as a `json_encode`d string (raw DBAL bypasses Doctrine's json type), or `null`
  for an empty list.
- `BackupFieldDeclarations::BACKED_UP[Entry::class]` gains the two field
  decisions (`mediaSet.media` => `media`, `mediaSet.attachments` => `attachments`,
  matching the ClassMetadata dotted paths the drift guard reads).
- A backup round-trip test proves the fields survive export → restore, including
  an entry with a podcast attachment and one with multiple `media[]` images.

## 9. API

`EntryJson::one()` emits `media` and `attachments` as structured JSON arrays
(from the entity's `JsonSerializable` VOs). The `application/json` contract stays
native-client-friendly: structured fields, no HTML to parse.

`EntryDto` in `frontend/src/app/reader/models.ts` gains the two typed fields so
the contract is typed and flows through `reader-api.ts` untouched. **No new
rendering** — no player, no media view. Existing magazine/reader code ignores the
new fields.

## 10. Testing

- **Parser** (`ItemMediaExtractorTest`): a podcast feed (`<enclosure>` +
  `<itunes:duration>`) yields one `attachments[]` entry with url, `mimeType`, and
  `durationInSeconds`; an MRSS feed with multiple `<media:content>` images with
  width/height yields a `media[]` list with dimensions; a plain single-image
  article yields `media[0]` equal to the lead and no attachments; an unknown-type
  enclosure is skipped, not mis-filed.
- **Lead invariant**: `getImageUrl()` unchanged across the existing image fixtures.
- **Ingest** (`EntryIngestorTest`): the https gate drops an `http:` media URL and
  an over-length one; `media[0]` tracks the gated lead.
- **Backup**: round-trip test above; `BackupSchemaCoverageTest` and
  `AccountRestorerTest` stay green (driven off the new declarations).
- **Migration**: migrate-from-empty CI leg green on SQLite and MySQL.
- **Mutation**: `composer infection:diff` covers the new routing branches
  (image vs video vs audio vs skip) and the ordering/dedup rule.
- **No outbound HTTP**: asserted structurally — the extractor reads the parsed
  DOM only; no fetch collaborator is introduced.

## 11. Acceptance (from the issue)

- Podcast feed → entry with one `attachments[]` entry carrying url, `mime_type`,
  `durationInSeconds`. ✔ §4, §10
- MRSS multi-image feed → `media[]` with dimensions; lead unchanged. ✔ §3, §10
- Plain single-image article → `getImageUrl()` unchanged. ✔ §5, §10
- Entry API response includes `media[]` and `attachments[]`. ✔ §9
- Backup round-trips the new fields; migration CI leg green on both engines. ✔ §7, §8
- No new outbound HTTP. ✔ §5, §10

## 12. Out of scope → follow-on issues

1. **Poster rescue** — feed `<media:thumbnail>`/lead image supplies a poster for a
   scraped poster-less video the reader currently drops (removes three data-loss
   branches in `AttributeMediaSource`, `SemanticMediaSource`, `JsonLdMediaSource`).
2. **Trust feed kind/dimensions** inside the reader pipeline (lean on feed
   `type`/`width`/`height` instead of extension and URL-query guessing).
3. **Podcast/audio player** UX (persisted player state, OS media-session keys).
4. **Dedicated Pictures / Videos / Audios views**.
5. Blurhash / image-proxy / thumbnail CDN — deliberately excluded on Strato
   (all need image fetching).
