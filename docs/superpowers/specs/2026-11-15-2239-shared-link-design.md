# Link cards normalized into one `shared_link` row per URL (#2239) — design

## Intent

A link card says what an article is: its URL, title, description, image, site and language. Today each post stores
its own copy of that (`link_card` per entry and position, and `entry.quoted_link_card` JSON for a quoted post's
card). The article's own facts have no home: #2229 guesses a card's language again on every Trending rebuild, and
#2236 needs a place for a resolved URL.

After this change each article is stored once, in `shared_link`, keyed by the hash of its cleaned URL (#2237). A card
is a post's reference to that row. The first card for a URL fills the preview, later posts reuse the row as it is,
and the share sweep fetches no page for a URL that already has a row. Opening the article in the link reader
refreshes the row from the page the reader fetches anyway.

Lars (verbatim, 2026-11-15): "Save the data for a link once and reuse it on new post. This should improve
performance of ingestion. We could refresh the link card, when an article view for the link was opened". And: "then
we don't need that extra check when i open a link".

## Goals

- G1. One `shared_link` row per `UrlNormalizer::hash()` of a card URL. Every card, reply card and quoted-post card
  references one.
- G2. Every path that writes a card goes through one service, `SharedLinkWriter`. It finds the row or creates it with
  that card's preview, and it never rewrites an existing row's preview.
- G3. The share sweep reads no page for a post link that already has a row.
- G4. The signed link reader refreshes the row's preview, and an unset or guessed language, from the page it fetched.
- G5. Language per URL: a language the page states (never replaced; it replaces a guess), else the #2229 guess for a text of 150 characters
  or more, else `null`. Trending reads the stored value and guesses only for a row that has none.
- G6. Trending's snapshot records each member's `shared_link`, so `TrendingStories` stops hashing card URLs per
  request.
- G7. The JSON the reader, Trending and the backup see is byte-for-byte what it is today for the same preview.
- G8. Existing data is merged by Doctrine migrations, the oldest card winning, on MySQL and SQLite, from empty and
  filled.

## Non-goals

- **The resolved URL (#2236).** Its columns are designed by #2236 (D12).
- **A new backup line type.** The card stays embedded in its entry line (D10).
- **Stored declared media.** A titled share's page is still read, because its declared video or photo is not stored
  (D3).
- **Rewriting a stated language.** A stated language is never changed, and a guess never replaces a set language. A
  stated tag replaces a guessed one (D5, ruling of the final review).
- **Any client change**, apart from deleting the card branch of `withoutBareUrl` (D13).
- **Reusing rows when the reader composes a share** (`SharedPageCards`), and the Mastodon card's own `language` field.
  Both are costed under *Optional scope*.

## Research

Dev MySQL, read only, 2026-11-15. Scripts are in the session scratchpad under `2239/`: `research.php`, `sweep.php`,
`lang.php`, `d2.php` and `migrate-proto.php`. The week is 2026-11-08 12:00 to 2026-11-15 12:00 UTC, by the card's
entry `created_at`.

### R1. What is stored

| | Count |
|---|---|
| Cards | 8,461: 7,814 root, 448 reply, 199 quoted-post |
| Distinct `UrlNormalizer::hash()` | 6,067; no card URL fails to hash |
| URLs carried by 1 / 2 / 3 / 4 / 5+ cards | 4,666 / 938 / 255 / 105 / 103 |
| Preview bytes (url, title, description, image, site) | 3.25 MB as cards, 2.28 MB as one row per URL (−30 %) |
| InnoDB on disk | `link_card` 5.4 MB data + 0.5 MB index today; after the prototype merge `shared_link` 3.6 + 1.5 MB (the 64-character unique hash) and `link_card` 0.3 + 0.5 MB |

On disk the two layouts weigh about the same at dev's size: InnoDB pages are not full, and the unique hash index is
new. The saving is in rows written and in page fetches (R2), not in storage.

### R2. A week of ingest

- 6,422 cards over 4,196 distinct URLs. 4,189 would create a row and **2,233 (35 %) would reuse one**.
- The share sweep queued 1,684 link-card rows, and their entries hold 1,514 cards. 353 of those cards are on a URL
  that already had a card:

| Sweep card on a known URL | Cards |
|---|---|
| Bluesky post link | 163 |
| Mastodon post link | 98 |
| Lemmy titled link post | 86 |
| Outlet titled share | 6 |

  A post link on a known URL is no longer read (D3): **about 261 page fetches a week (17 % of the sweep's cards)**,
  each up to `PageHeadFetcher::MAX_DURATION_SECONDS` (8 s). The 92 titled targets keep their read. The count is an
  upper bound: an entry's first card can be its network's payload card, not a fetch.
- Bare-URL descriptions: no stored card or quoted card has one. 365 Lemmy, 5 Mastodon, 1 Bluesky and 1 outlet *entry
  summary* is a bare URL (D13).

### R3. What "oldest card wins" changes

- 358 URLs (5.9 %) have cards that do not all match their oldest card. 721 cards differ from it:
  - about 630 only by the image URL (signed or resized CDN variants of one picture);
  - 256 by the title or description (typographic quotes, a Politico shortlink suffix that varies per post, heise
    teasers with and without a description);
  - 20 only by the URL's spelling (fragment, host case).
- Under D2's story-card test, one URL's oldest card names no story while a later card does: `youtu.be/8TLKB-HNtVc`,
  whose oldest card carries YouTube's consent title *- YouTube*. Accepted by the ruling. It is the case the refresh
  exists for, and the link reader does not refresh a player page (`PlayerPageExtractor`).

### R4. Language

Over the 6,002 card URLs, the four-language detector applied to the title plus the first 300 plain characters of the
description (the text `SharedArticles` guesses on today):
- 4,048 rows (67 %) reach 150 characters and get a guess: en 2,994, de 987, es 50, fr 17;
- 1,954 are shorter and store `null`;
- guessing all 6,002 takes 913 ms (0.15 ms each);
- 43 URLs are shared by posts of more than one language.

The French and Spanish guesses include the known wrong ones: long English `politi.co` cards and GitHub/Russian titles
read as fr or es (#2229, #2235 M3). Those are guessed wrongly today too. A page's stated language (D5) is what can
fix them.

### R5. The migration, prototyped on a copy

The three migrations' statements ran on `feedreader_2239_copy` (124,792 entries, 8,262 cards, 199 quoted cards). The
copy was dropped after.

| Step | Time |
|---|---|
| Create `shared_link`, add the three reference columns | 0.05 s |
| `link_card` and `trending_story_entry` foreign keys | 0.3 s |
| `entry.quoted_shared_link_id` foreign key and index | 4.9 s |
| Read cards and quoted JSON, group by hash, oldest first | 0.8 s |
| Insert 6,076 rows (500 a statement) | 0.15 s |
| Point the cards: 17 `CASE` updates of 500 cards each | 0.16 s |
| Point the quotes (138 statements) | 0.18 s |
| Drop the card columns and `entry.quoted_link_card` | 0.11 s |
| **Total** | **6.8 s wall, 47 MB peak RSS, 28 MB PHP** |

0 cards were left unlinked. One `UPDATE … WHERE id IN (…)` per row instead of the batched `CASE` took 6.8 s for that
step alone (13.9 s in total), so the plan specifies the batched form.

## Approaches considered

1. **Keep cards per post and add `link_card.language`** (#2229 R2's alternative). This changes nothing for #2236's
   resolved URL, keeps every page fetch, and stores the guess once per post instead of once per URL. Rejected: it
   answers none of the issue's three missing homes.
2. **`shared_link`, merged by a one-off command after deploy.** The new code reads cards through the link, so until
   the command runs every card has `shared_link_id IS NULL` and renders nothing. Two deploys would be needed: the old
   columns kept, then dropped. Rejected.
3. **`shared_link`, merged by Doctrine migrations in the deploy that ships the code.** The hash is PHP, so the merge
   migration hashes in PHP through `UrlNormalizer`. That has precedent: `Version20261114160000` classifies feeds
   through `SocialFeedClassifier`. **Chosen** (D8).

## Design

### Schema

**`shared_link`** (`App\Entity\SharedLink`, `final`, repository `SharedLinkRepository`):

| Column | Type | Notes |
|---|---|---|
| `id` | INT, auto | |
| `url` | VARCHAR(2048), not null | the first card's URL as stored (cleaned since #2237; a kept posted href when `PostedUrlCheck` kept one) |
| `url_hash` | VARCHAR(64), not null, **unique** `uniq_shared_link_url_hash` | `UrlNormalizer::hash(url)`, the same function as `entry.url_hash` |
| `title` | VARCHAR(500), not null | `LinkCardContent::TITLE_MAX` |
| `description` | TEXT, null | at most 300 characters (`StorableLinkCard`); never a bare URL (D13) |
| `image_url` | VARCHAR(2048), null | https only |
| `site_name` | VARCHAR(255), not null | |
| `language` | VARCHAR(2), null, `enumType: FeedLanguage` | D5 |
| `language_source` | VARCHAR(8), null, `enumType: App\Enum\LanguageSource` (`stated`, `guessed`) | null exactly when `language` is null |
| `created_at` | DATETIME, not null | naive UTC; when the first card stored the row |
| `unreferenced_since` | DATETIME, null, indexed | naive UTC; set by the prune that finds the row unreferenced, cleared by a writer's reuse (D11) |

**`link_card`** becomes `id`, `entry_id` (FK cascade, as today), `thread_post_id` (FK cascade, as today), `position`,
and `shared_link_id` INT NOT NULL (FK to `shared_link`, no `ON DELETE`: restrict). The unique `(entry_id, position)`
stays. `url`, `title`, `description`, `image_url` and `site_name` are dropped.

**`entry.quoted_shared_link_id`** INT NULL (FK, restrict, with an index) replaces `entry.quoted_link_card` JSON. A
Doctrine embeddable cannot hold an association, so it is a `ManyToOne` on `Entry` itself (`Entry::$quotedLink`), not
on `EntryQuotedPost` (D4).

**`trending_story_entry.shared_link_id`** INT NULL (FK `ON DELETE SET NULL`): the member's first top-level card's
link, null when it has none (D7).

**Delete behaviour.** An entry purge cascades to its `link_card` rows, never to a `shared_link`. A `shared_link` with
no card and no quote left is deleted by the pruner a day after a prune first marks it (D11). Both references are restrict, so
nothing can delete a row a card or quote still uses. A snapshot reference is set null.

### Values and services

- `App\Entity\LinkCardContent` stays the preview value (url, title, description, imageUrl, siteName). `toStored()`,
  `fromRow()` and `fromStoredOrNull()` are unchanged, so every JSON mapper is unchanged (G7).
- `App\Entity\LinkLanguage` (`final readonly`): `FeedLanguage $language`, `LanguageSource $source`;
  `static stated(FeedLanguage)`, `static guessed(FeedLanguage)`.
- `SharedLink`:
  - `__construct` is not used by writers, which insert through the repository (concurrency, below);
  - `preview(): LinkCardContent`, `getUrlHash()`, `getLanguage(): ?FeedLanguage`, `requireId()`;
  - `refresh(LinkCardContent $preview): void` keeps `url` and replaces title, description, image and site;
  - `settleLanguage(LinkLanguage $language): void` is a no-op when a language is already set.
- `App\Service\Ingestion\Language\Support\PreviewText::of(string $title, ?string $description): string` is
  `$title . "\n" . WordBoundary::cut(EntryPlainText::of($description) ?? '', 300)`. It is the text `SharedArticles`
  guesses on today, moved where both the writer and Trending can reach it.
- `App\Service\Ingestion\Language\PreviewLanguage` (root service) has one method,
  `of(LinkCardContent $preview, ?FeedLanguage $statedLanguage): ?LinkLanguage`. It returns:
  - `stated($statedLanguage)` when the page stated one of the four;
  - else `guessed(bestFor(text))` when `overrulesAStatedLanguage(text)` (150 characters or more) and the guess names
    one of the four;
  - else `null`.

  It injects the four-language `LanguageGuess`. That service id is renamed from `app.trending.card_language_guess` to
  `app.card_language_guess`, since two modules now use it.
- `App\Service\Content\LinkCard\SharedLinkWriter` (root service) is the one writer (G2):
  - `linkFor(LinkCardContent $card, ?FeedLanguage $statedLanguage): SharedLink` returns the row for
    `UrlNormalizer::hash($card->url)`. When there is none, it computes `PreviewLanguage::of()` and inserts the row with
    `created_at` now.
  - `idsFor(list<LinkCardContent> $cards): array<string, int>` returns the link id by card URL, found or created, for
    the batch callers (restore). It runs one lookup, one multi-row insert of the missing rows and one lookup of their
    ids.
- **Concurrency.** Ingest, the share sweep and a web-triggered refresh can create one URL's row at the same time. The
  repository inserts with `INSERT IGNORE` / `INSERT OR IGNORE` (`DatabasePlatform::insertIgnoreClause`, as
  `EntryShareRepository` does), then reads the id. A collision is never a `UniqueConstraintViolationException` that
  closes the entity manager. The writer looks up first, so a reused URL burns no auto-increment value.
- `SharedLinkRepository`:
  - `findOneByUrlHash(string)`;
  - `byUrlHashes(list<string>): array<string, SharedLink>`;
  - `insertMissing(list<NewSharedLinkModel>)`;
  - `idsByUrlHash(list<string>): array<string, int>`;
  - `deleteUnreferencedCreatedBefore(\DateTimeImmutable): int`;
  - and, moved from `LinkCardRepository`, #2237's `urlsAfterId()` and `moveTo()` (D15).

  `Content/LinkCard/Model/NewSharedLinkModel` holds `urlHash`, `preview`, `?LinkLanguage` and `createdAt`.

### Writers

| Writer | Today | After |
|---|---|---|
| Bluesky embed (`EntryEmbedWriter::fill`) | `LinkCardRepository::append($entry, $embed->linkCards)`; `$entry->shareQuotedPost($embed->quote)` | each card through `linkFor($card, null)`, then `append($entry, list<SharedLink>)`; the quote's card through `linkFor`, then `shareQuotedPost($quote, ?SharedLink)` |
| Mastodon/Lemmy payload card (`KnownShareSettler::settleCarded` → `EntryCardWriter`) | `append` with the card | `EntryCardWriter` maps each page card to a link (below) |
| Share sweep (`ShareLinkCards` → `ShareTargetReader` → `PageCardReader` / `PostedUrlCheck` → `EntryCardWriter`) | reads every uncarded link | a post link with a row is attached without a read (D3); a read card is written with the page's stated language; a kept posted href becomes the new row's `url` |
| Quoted post (`EntryCardWriter`, Mastodon `QuotedPostSource`) | `shareQuotedPost($quote)` stores the card JSON | `shareQuotedPost($quote, linkFor($quote->linkCard, null))` |
| Thread posts (`SelfThreadFolder::foldAt`) | `appendToThreadPost($post, $card)` | `appendToThreadPost($post, linkFor($card, null))` |
| Saved copies (`SavedPostRepository::copyCompanions`, `copySql`) | copies the card columns; `copySql` copies `quoted_link_card` with every entry column | copies `shared_link_id`; `copySql` adds `quoted_shared_link_id` explicitly, because `ClassMetadata::getColumnNames()` lists no association column |
| Backup restore (`RestoreEntryLoader` → `EntryBatchInserter`) | inserts card columns and the quoted JSON | `idsFor(every card and quoted card of the fresh lines)` before the entry insert; inserts `quoted_shared_link_id` and `link_card (entry_id, position, shared_link_id)` |

`EntryCardWriter::write()` maps each `PageCardModel` to `$pageCard->link ?? linkFor($pageCard->card,
$pageCard->statedLanguage)`. A known link is a `PageCardModel` too (`PageCardModel::known(SharedLink)`), so cards stay
in post order and `quoteLineUrls()` still drops each carded link's "RE:" line.

### The share sweep skips known post links (G3)

- `ShareTargets::reusableUrlsOf(Entry $entry, ShareFetchPlanModel $plan): list<string>` returns the plan's URLs that
  may reuse a stored row:
  - none for a titled share, whose page may declare the video or photo that turns it into a media share
    (`EntryCardWriter::settleMediaTarget`);
  - every URL but the "RE:" quote-line target for a post, because that target is read as a quoted post, not a page.
- `ShareLinkCards::complete()` hashes the reusable URLs of every queued row and looks them up with **one**
  `byUrlHashes()`. It then calls `$plan->withKnown(array<string, SharedLink>)` before the budget step.
- `ShareFetchPlanModel` gains `array<string, SharedLink> $known`. `fetches()` counts only the unknown URLs and their
  posted forms, so a known link costs no budget. A known URL never gets a posted-form check.
- `ShareTargetReader::pageCardsFor()` yields `PageCardModel::known($link)` for a known URL and reads every other one,
  as today.

### The page's stated language and declared preview

- `App\Service\Content\Reader\Support\DeclaredPreview::of(\Dom\HTMLDocument $document, string $sharedUrl, string
  $finalUrl): PagePreviewModel` (`Reader/Model`) reads what `PageCardReader` reads today. That is the title
  (`og:title`, `twitter:title`, else `<title>`), the description, the image, and the site (`og:site_name`, else the
  host). It adds the language: `<html lang>`, else `og:locale`, through `FeedLanguage::fromTag()`. A tag outside the
  four is no statement, so the guess decides.
- `PagePreviewModel::card(): ?LinkCardContent` is `StorableLinkCard::of()` of its fields.
- `PageCardReader::read()` builds its `PageCardModel` from it. It gains `?FeedLanguage $statedLanguage`, and the
  identity and declared medium are unchanged.
- The module direction holds: `Content/LinkCard` already depends on `Content/Reader`. Reader gains no dependency on
  LinkCard.

### The article-view refresh (G4)

- `ArticlePageReader::read()` already parses the raw page (`RawPageModel`). It adds
  `DeclaredPreview::of($rawPage->document, $page->finalUrl, $page->finalUrl)` to `ArticlePageModel`. The site
  fallback is then the final host, and the refresh never takes the preview's URL.
  `ExtractionResultModel::ok()` carries it as `?PagePreviewModel $preview`, and `ReaderJson` ignores it, so the
  `/api/reader` JSON is unchanged.
- `App\Service\Content\LinkCard\LinkArticleReader::extract(string $url): ExtractionResultModel` calls
  `ArticleExtractorInterface::extract($url)`. Only on an ok result with a storable preview, and only when a row
  exists for `hash($url)`, it calls `refresh()` with the preview and `settleLanguage()` with
  `PreviewLanguage::of($preview, $stated)` when that is not null, then flushes. No row is created from a view.
- `SignedUrlReaderController::reader()` delegates to `LinkArticleReader` instead of the extractor. It stays one
  delegation. The entry article view (`EntryArticleReader`) and the reader audit do not refresh.
- A failed extraction never refreshes. A consent wall or bot challenge fails as `Fetch` or `Unextractable`, so a good
  preview is not replaced by *Client Challenge*. A player page is refused before any fetch.
- No cleaned-link verification runs here (Lars's ruling).

### Readers

| Reader | Change |
|---|---|
| `LinkCardRepository::contentsByEntryIds` (list enrichment, `SelfThreadEnricher`, `ShareLinkCards`, backup export) | joins `shared_link`; returns the same `LinkCardContent` lists |
| `ThreadPostRepository::cardsByPostId` | joins `shared_link` |
| `Entry::getQuotedPost()` | `$this->share->quotedPost()?->withLinkCard($this->quotedLink?->preview())`; `QuotedPostContent` gains a `#[\NoDiscard]` wither; `EntryQuotedPost` stores and reads no card |
| `EntrySharedContent::classify()` / `Entry::setShareKind()` | a kind other than Quote also clears `Entry::$quotedLink` |
| `TrendingEntryRepository::chunkAfter` | `LEFT JOIN shared_link link ON link.id = card.shared_link_id`; selects `link.id`, `link.url_hash`, `link.url`, `link.title`, `link.site_name`, `SUBSTR(link.description, 1, 600)`, `link.language` |
| `WindowLinkCardModel` | gains `int $sharedLinkId`, `string $urlHash` and `?FeedLanguage $language` |
| `TrendingGrouping::urlHashesOf` | uses `$card->urlHash` instead of `UrlNormalizer::hash($card->url)` |
| `SharedArticles` | `$card->language ?? CardLanguage::of(PreviewText::of($card->title, $card->description), $postLanguage)`; memo keyed by link id, plus the post language when the row has no language |
| `TrendingRun` / `StoryMemberModel` / `TrendingSnapshotRepository::insertStory` / `TrendingStoryEntry` | carry and write `shared_link_id` |
| `TrendingStoryRepository::shownEntryIdsByStory` | `LEFT JOIN shared_link`; returns `array<int, array<int, ?string>>` (story ⇒ entry ⇒ its link's `url_hash`) |
| `TrendingStories::articleKeyOf` | an outlet's row by `getUrlHash()` as today, a post's by the snapshot's link hash; `UrlNormalizer` leaves `TrendingStories` (G6) |

**Trending output is unchanged by construction for guessed rows.** Today: `CardLanguage::of(text, post)` gives the
post's language below 150 characters, else `bestFor(text) ?? post`. After: a row of 150 characters or more stores
`bestFor(text)` from the same detector and the same text. A shorter row stores null and falls back to the same call.
Two things alone move pairs:
- the merge's oldest preview (R3: 256 cards change title or description);
- stated languages on rows read after the deploy.

**Rebuild guesses.** Today there are 3,971 a week (#2229 Outcome). After the fill, only a story card with no stored
language whose post has no language is guessed: below 150 characters and an unlanguaged post, which is about 1 % of
posts (#2229 R1).

### Migrations (D8)

Three classes, platform-aware DDL as `Version20261017160000`, `isTransactional(): false`. Constraint and index names
are taken from `doctrine:migrations:diff` on each platform, so `doctrine:schema:validate` passes.

1. **`Version20261115140000` (schema).**
   - Create `shared_link`.
   - Add `link_card.shared_link_id` INT NULL (FK + index), `entry.quoted_shared_link_id` INT NULL (FK + index) and
     `trending_story_entry.shared_link_id` INT NULL (FK `ON DELETE SET NULL` + index).
   - SQLite uses `ALTER TABLE … ADD COLUMN … REFERENCES …`, as `Version20261017160000` does. No `entry` rebuild
     happens, so the FTS5 triggers stay: checked on SQLite 3.54. CI runs 3.45, and `ADD COLUMN` and `DROP COLUMN`
     exist since 3.35.
2. **`Version20261115140100` (merge, data only).**
   - Read `link_card` with `entry.created_at`, and `entry.quoted_link_card` with its entry's `created_at`, through
     `$this->connection`.
   - Order them by `created_at`, a card before a quote at equal times, then by id.
   - Key each by `(new UrlNormalizer())->hash($url)`. The first of a key gives the row: its preview, with a bare-URL
     description nulled (D13), `created_at` = that card's entry `created_at`, and `language` null.
   - Explicit ids `1..n` are assigned in PHP. The table was created empty one migration earlier; the migration aborts
     if it is not.
   - Inserts go 500 rows a statement. Card pointers go as `UPDATE link_card SET shared_link_id = CASE id WHEN … END
     WHERE id IN (…)`, 500 a statement. Quote pointers go one statement per link.
   - A card or quote whose URL does not hash is deleted (cards) or loses its card (quotes), with a `write()` count.
     There are none on dev (R1).
   - `down()` nulls the pointers and empties `shared_link`.
3. **`Version20261115140200` (slim).**
   - MySQL: drop the five card columns, make `shared_link_id` NOT NULL, drop `entry.quoted_link_card`.
   - SQLite: rebuild `link_card` (create `link_card_new` in the final shape with its three FKs, copy, drop, rename,
     recreate the three indexes; `link_card` has no trigger), then `ALTER TABLE entry DROP COLUMN quoted_link_card`.
   - `down()` re-adds the columns and copies the previews back from `shared_link`.

The migrate-from-empty CI leg covers all three on both platforms. A filled run is pinned by a test (plan Task 11) and
measured on a MySQL copy (R5, and again in the measurement task).

### Language fill (one-off)

`app:shared-links:fill-language` (`FillSharedLinkLanguageCommand` → `Service/Operations/LinkLanguageFill`) walks
`shared_link` by id in batches of 500 where `language IS NULL`. It sets `PreviewLanguage::of($row->preview(), null)`
where that is not null, and has `--dry-run`. Idempotent: a short row stays null. It covers every row, not only the
week's, because new posts reuse old rows (R2: 35 %), and a week-only fill would leave old long rows guessed at every
rebuild. It runs on dev and Strato after the deploy, and a follow-up commit deletes it (Lars's rule for #2236/#2237).

### Backup (D10)

- **Export:** unchanged. An entry line embeds its root cards as today, from the row's preview
  (`contentsByEntryIds`), and a quote embeds its card from `getQuotedPost()`.
- **Restore:** `EntryLine::fromLine` is unchanged. `RestoreEntryLoader` resolves every card and quoted card of the
  fresh lines through `SharedLinkWriter::idsFor()`. An existing row is kept, which is the issue's "merged on the way
  in". A missing one is created from the file's card, with a guessed language.
- **Older backups** have the same shape, so they import unchanged. `BackupSchema::VERSION` stays 3.

### What the client sees

- **API:** no field changes. Cards are built from the row, so two posts sharing a URL show the same preview, which is
  the ruling's "per-post preview variance is accepted as lost". Native iOS needs nothing.
- **Frontend:** `shared-article-card.ts` drops `withoutBareUrl` from the card branch (`dek: card.description ?? ''`),
  since storage now refuses a bare-URL description. The entry-dek branch keeps it: 365 Lemmy posts' own summaries
  are a bare URL (R2), and those are not cards.

### Garbage collection (D11)

`EntryPruner::prune()` calls `SharedLinkRepository::sweepUnreferenced(now, now − 1 day)` after the entry passes and
before the empty-run pass. Mark, then delete (final review I2):
1. a row that is referenced again loses its `unreferenced_since` mark;
2. a row no card and no quote references, and not yet marked, is marked with now;
3. a row marked before now − 1 day, and still unreferenced, is deleted.

Every writer calls `keepAlive()` (clears the mark) before it looks a row up, so a row a writer is about to reference is
never older-marked than its lookup: a sweep between the lookup and the flush can only mark it again, and deletion needs
a further day. `idx_shared_link_unreferenced_since` serves the sweep. The report's entry count is unchanged.

## Decisions

Lars delegated every decision. Each is "what — why — cost if wrong".

- **D1. The key is `UrlNormalizer::hash()` of the card URL; the row's `url` is the first card's as stored.** — The
  same function writes `entry.url_hash`, so a post folds onto an outlet's row by equal hashes. #2237's cleaner is
  inside it, and the fragment is dropped. — If wrong: two shares of one page that differ only in `#section` show the
  first card's fragment (20 cards on dev, R3).
- **D2. The first card fills the preview; only the link reader refreshes it, and only from an ok extraction.** — This
  is Lars's ruling. The ok-only guard keeps consent walls and challenges (which fail extraction) from overwriting a
  good preview. — If wrong: a page whose ok extraction comes from a redirect to a front page refreshes to the front
  page's card. A later open fixes it.
- **D3. A post link with a row is not read; a titled share's target and a post's "RE:" target still are.** — A titled
  share's page can declare the video that makes it a media share (`settleMediaTarget`), and that is not stored. The
  quote target is read as a quoted post. Storing a declared medium on `shared_link` would cover the first case and is
  costed under *Optional scope*. — If wrong: about 92 titled fetches a week stay (R2). That is today's cost, not a
  regression.
- **D4. The quoted card is `Entry::$quotedLink` (`quoted_shared_link_id`), composed into `getQuotedPost()`.** — An
  embeddable cannot map an association. A quoted card as a `link_card` row would need a role column and a filter in
  five readers. — If wrong: a list page lazy-loads one `SharedLink` per quote post with a card (199 of 751 quotes on
  dev). That is one query each.
- **D5. Language: the page's stated tag, else the four-language guess on 150 characters or more, else null; a statement
  replaces a guess, nothing replaces a statement; `language_source` stored.** — This follows the issue's rule. #2235 M3 found short fr/es guesses 71–88 % wrong and
  long ones 88–96 % right. `<html lang>` is the cheap stated source on every page we read. The source column lets a
  later re-guess leave stated rows alone and lets the measurement split accuracy by source. — If wrong: a template
  default `lang="en"` on a German site sets English for good; the eye check samples stated rows that differ from the
  guess.
- **D5 ruling (final review, 2026-11-15).** The issue says "a language the source states wins". So a stated
  language replaces a guessed one, a stated one is never replaced, and a guess never replaces anything set
  (`SharedLink::settleLanguage`). The link reader's stated `<html lang>` therefore fixes a wrong guess when the
  article is opened; the measured French and Spanish misses (Outcome) are exactly where it differed, 4 of 4 right.
- **D6. Trending reads the stored language, with `CardLanguage` unchanged as the fallback.** — Identical output for
  every guessed row (Design, Readers). The only movers are the merge and stated rows, so the A/B isolates them. — If
  wrong: none for guessed rows.
- **D7. `trending_story_entry.shared_link_id`; the shown-entries query returns each member's link hash as its
  article key.** — This is the ruling. The key is decided at build from the stored link, and `TrendingStories` stops
  hashing. `SET NULL` keeps the snapshot from blocking garbage collection, and a null key degrades to the row's own key
  until the next build. — If wrong: one more column on a table rebuilt hourly.
- **D8. The merge is three Doctrine migrations in the deploy that ships the code, hashing in PHP.** — The code reads
  cards through the link from the first request, so a post-deploy command would leave every card blank until it
  ran. 6.8 s on dev (R5). Using an app class in a migration has precedent (`Version20261114160000`). — If wrong:
  `UrlNormalizer` renamed later breaks this migration on a filled database. On an empty one, no row reaches the
  hashing call. Strato is unmeasured: it is expected to be a small multiple of dev's 6.8 s, and the entry index build
  dominates.
- **D9. The language fill is a one-off command over every row, deleted after the dev and Strato runs.** — This is
  Lars's rule for one-off data. Every row, not only the week's, because the window reuses old rows. About 1 s for
  6,000 rows. — If wrong: an instance that never runs it guesses old long rows at rebuild, which is today's cost.
- **D10. The backup format is unchanged; restore merges through the writer; no link lines.** — Backups are written in
  byte-budgeted parts, and a separate link line would need each part to repeat the links its entries reference, or a
  cross-part reference. Both cost more than embedding a 300-character preview per card. Old backups import as they
  are. The language is not carried: restore guesses it again, and a stated language is lost. — If wrong: a backup
  repeats a shared article's preview per card, which is what it does today.
- **D11. Unreferenced rows are deleted by the pruner a day after a prune first finds them unreferenced (mark, then
  delete; final review I2).** — Retention bounds `entry` and
  `link_card`. Without collection `shared_link` would grow forever (R2: 4,189 new rows a week), and a URL re-shared
  after its window would keep a stale preview. The one-day grace covers a row created by one process and referenced
  at another's flush. — If wrong: a URL re-shared more than a day after its last card was purged is read again.
- **D12. No resolved-URL columns now.** — #2236 designs its resolution (when, with what outcome, retries). Unused
  nullable columns would fix its shape before its spec. — If wrong: #2236 adds one small migration.
- **D13. A description that is one bare http(s) URL is not stored (`StorableLinkCard`); the card branch of
  `withoutBareUrl` goes, the entry branch stays.** — Moved here from #2237's review (A10). The entry branch guards
  Lemmy summaries, which are not cards (R2: 365). — If wrong: none; dev holds no such card.
- **D14. The writer looks up first, then `INSERT IGNORE` and reads the id.** — Concurrent creators never raise a
  unique violation that closes the entity manager mid-ingest, and a reuse burns no id. — If wrong: one extra SELECT
  for a new URL.
- **D15. #2237's `app:urls:clean-tracking` cleans `shared_link.url` instead of `link_card.url`.** — Strato has not run
  it yet, and #2239 removes `link_card.url`. The hash does not change when a URL is cleaned (`normalize` strips the
  same keys), so no two rows collide. — If wrong: none; the follow-up deletes it with its two repository methods.
- **D16. The API shape is unchanged because the repositories, not the mappers, change.** — `LinkCardJson`,
  `QuotedPostJson`, `ThreadPostJson` and `EntryJson` keep their input type, `LinkCardContent`. — If wrong: none.
- **D17. Only the signed link reader refreshes; the declared preview is parsed once, in `Content/Reader`.** — The
  ruling names the article view for the link. `PageCardReader` and the extractor share one parser, and Reader gains no
  dependency on LinkCard. — If wrong: an outlet entry's article view, whose URL a post shares, does not refresh that
  row.

## Optional scope, costed and not built

- **The Mastodon card's own `language`.** One `FeedLanguage::fromTag($card->string('language'))` in `MastodonCard`,
  plus a fixture. Not built: the field is not in our fixtures, and its presence on `/api/v1` statuses is unverified.
- **Reuse rows in the composer** (`SharedPageCards::cardOf` for an outgoing share). A lookup before the read. It is
  outside the issue's ingest goal.
- **A stored declared medium** (`shared_link.medium` JSON). This would let titled shares skip the read too (92 a week,
  R2). It needs a column, `PageCardModel` plumbing and a backup field.
- **A refresh throttle** (`refreshed_at`). Each open of the link reader already fetches the page; the write is one
  `UPDATE`. A throttle would save writes, not fetches.
- **Backup link lines and a version-4 schema.** See D10.

## Measurement plan

From the issue, with A = develop at 4266db727 on an unmigrated copy, and B = HEAD on a copy migrated by the three
migrations and filled by the command. Both copies come from one `mysqldump` of `feedreader`, so the worker cannot
move the window between them.

- **Storage:** distinct card URLs in the week, cards per URL, rows and preview bytes saved (R1 and R2 recomputed on
  B), and InnoDB `data_length + index_length` of the two layouts.
- **Migration:** wall time and peak RSS of `doctrine:migrations:migrate` on the B copy; 0 unlinked cards; and every
  card's `contentsByEntryIds` preview on B equal to its URL's oldest card on A (a script).
- **Fill:** rows filled per language, wall time, a second run reporting 0.
- **Ingestion:** sweep page reads a week that a known row saves, recomputed on B (R2's method).
- **Language, by eye:**
  - 20 guessed rows each for de and en, and every fr and es row (17 and 50 on dev), marked right or wrong;
  - French and Spanish counted on their own;
  - a live `PageCardReader` read of 40 week URLs (10 per language where they exist), comparing the stated tag with
    the guess and checking each disagreement by eye.
- **Trending:** groups and grouped pairs, new / lost / net, for day and week, and French-only and Spanish-only day
  and week, per language pair (de–de, en–en, fr–fr, es–es, mixed or none). Pin `2026-11-15 12:00:00` or the run
  day's equivalent. Explain time and peak RSS for each. Expected: changes only where the merge changed a card's text
  (R3); every changed group is checked by eye and traced to its card.
- **Rebuild:** the week's collect phase, A vs B (the language guess and the hash per card leave it).
- **Merge eye check:** 20 posts whose preview changed through the merge, at least 5 French or Spanish where they
  exist, each called acceptable or not.

## Risks

- **Strato's migration time is unmeasured.** The entry index build dominates (4.9 s of 6.8 s on 124,792 entries).
  The deploy runs migrations before traffic, so only the deploy is slower.
- **A wrong stated language is permanent** (D5). The eye check samples it. A later fix re-guesses `guessed` rows
  only.
- **`Entry` gains its 15th field** (PHPMD `TooManyFields` reports above 15). The next field on `Entry` needs a
  design, not a 16th.
- **The merge migration depends on `UrlNormalizer`** (D8).

## Outcome

Measured 2026-11-15 on one `mysqldump` of dev `feedreader` (124,873 entries, 8,280 cards, 196 quoted cards) restored
into `feedreader_2239_a` (develop 4266db727, unmigrated) and `feedreader_2239_b` (HEAD, migrated and filled). Both
copies were dropped after. Scripts are in the session scratchpad under `2239/final/`.

**Migration (B).** 3 migrations, 8.37 s wall, 68 MB peak RSS for the whole `bin/console` process. 6,083 shared links
from 8,280 cards and 196 quoted cards; 0 cards deleted, 0 quotes dropped, 0 cards unlinked, 196 quotes linked.
`doctrine:schema:validate` in sync. From empty: SQLite 3.54 and MySQL 8.4 in sync, the six FTS5 triggers present on
SQLite; MySQL down ×3 then up again in sync.

**Merge check.** Every one of the 8,476 cards and quoted cards reads, on B, its URL's oldest card on A (0 differ, 0
missing). 719 now differ from their own old preview (R3: 721): image only 359, title or description 269 (en 190, de
70, fr 4, es 1, none 4), URL spelling 20.

**Fill.** `--dry-run` 1.73 s; the run 3.26 s, 100 MB: 4,110 rows filled (de 1,010, en 3,033, fr 16, es 51), 1,973
left short; a second run filled 0.

**Storage.** Preview bytes 3.20 MB as cards → 2.29 MB as rows (−29 %). InnoDB `data + index`: A `link_card` 4.7 +
0.5 MB; B `link_card` 0.3 + 0.5 MB and `shared_link` 3.7 + 1.6 MB, about the same in all (R1). References per row:
1 → 4,687, 2 → 931, 3 → 257, 4 → 106, 5+ → 102.

**Ingest, the week 2026-11-08 12:00 – 11-15 12:00.** 6,350 cards over 4,155 links: 4,148 new rows, 2,202 reuses
(35 %). The sweep queued 1,663 rows holding 1,495 cards; 254 are post links on a known row (Bluesky 156, Mastodon
98), the page reads G3 saves (17 % of the sweep's cards). 92 titled targets on a known row keep their read (Lemmy 86,
outlet 6).

**Trending A/B**, pinned at 2026-11-15 12:00:00, equal entry counts on both sides.

| Window | Entries | Groups A → B | Pairs A → B | New / lost / net | A run | B run |
|---|---|---|---|---|---|---|
| day | 6,525 | 801 → 801 | 3,002 → 3,002 | 0 / 0 / 0 | 7.7 s, 99 MB | 7.7 s, 105 MB |
| week | 30,533 | 3,900 → 3,900 | 20,351 → 20,383 | 45 / 13 / +32 | 92.6 s, 262 MB | 87.2 s, 266 MB |
| fr day | 608 | 72 → 72 | 236 → 236 | 0 / 0 / 0 | 1.5 s, 68 MB | 1.2 s, 68 MB |
| fr week | 722 | 87 → 87 | 273 → 273 | 0 / 0 / 0 | 1.3 s, 69 MB | 1.3 s, 69 MB |
| es day | 495 | 27 → 27 | 42 → 42 | 0 / 0 / 0 | 1.2 s, 64 MB | 1.2 s, 64 MB |
| es week | 958 | 46 → 46 | 85 → 85 | 0 / 0 / 0 | 1.5 s, 71 MB | 1.5 s, 71 MB |

By language pair the week's change is en–en +23 (35 new, 12 lost) and mixed/none +9 (10 / 1); de–de, fr–fr and es–es
0. All of it is one member: Ars Technica's "FAA orders third nationwide ground stop" moves from the 16-entry Politico
group into the 46-entry ground-stop group. Traced to the merge (R3): 12 posts sharing one Politico live-updates URL
carried four different per-post headlines and now all show its oldest, "FAA orders nationwide grounding of passenger
planes". Checked by eye: the move joins the article to the story it is about. No change without a cause.

**Rebuild.** The week's collect phase (3 runs, median): A 7,107 ms → B 6,401 ms (−10 %), peak equal. Of the 5,426
week entries with a first card, 3,861 were language-guessed per rebuild on A; on B at most 18 are (no stored language
and no post language).

**Language by eye.** de 20/20 and en 20/20 guessed rows right. **French on its own: 4 of 16 right (25 %)**; the 12
wrong are English (Politico ×5, TechCrunch, GitHub ×2, Washington Examiner), German (heise ×2) and Russian. **Spanish
on its own: 33 of 51 right (65 %)**; the 18 wrong are English (Politico ×6, EFE English), Galician ×3, Catalan,
Portuguese, Polish, Turkish, Indonesian ×2, Chinese and Russian. These are the same detector's guesses on the same text
as develop's per-rebuild guess (#2229, #2235 M3), now stored once.

**Live stated tags.** `PageCardReader::read()` from HEAD on 40 week URLs (10 per language; Politico left out, it is
the known cluster): 3 fetches failed. de 9/9 agree; en 8 agree, 1 none (YouTube); es 6 agree, 3 none (Catalan,
Indonesian, a `.md` homepage); fr 4 agree, **4 differ, and in all 4 the stated tag is right** (en ×2, de ×2: TechCrunch,
Washington Examiner, heise ×2). A stated tag fixes exactly the rows the four-language guess gets wrong — but only for a
row read after the deploy, or a guessed row whose article is opened in the link reader (D5 ruling).

**Merge eye check**, 20 posts whose preview changed by title or description (all 5 French and Spanish ones, 15 drawn at
random): 18 acceptable, 2 not.
- Acceptable: 7 headline revisions (Politico's per-post headlines ×5, a Politico Europe and a heise retitle), 4
  typography or whitespace drifts, 1 Instagram like count, 1 Lemmy description, 1 TechCrunch suffix dropped, 1 Tagesspiegel teaser
  revision; 3 are better than the post's own (a heise teaser and a description gained, and a Cloudflare *Just a
  moment...* title replaced by the article's).
- Not acceptable: a Mother Jones title keeps the typo "aho" its outlet corrected later, and a Hackernoon card loses
  the description a later card had. Both are what the refresh exists for; an open in the link reader fixes them.
