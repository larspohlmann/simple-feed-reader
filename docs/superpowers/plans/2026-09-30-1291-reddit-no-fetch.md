# #1291 — No article fetch for any Reddit entry

**Goal:** Reddit link posts take the self-post path (`url = null`, so the reader never
extracts), and the `body_is_opening_post` flag that only existed to gate those fetches
is deleted. Net diff negative.

## Task 1 — `RedditEntryRule` keeps the feed body and drops the article url

- `ParsedEntryModel::withPlatformRewrite($url, $contentHtml, $discussion)` becomes
  `asDiscussionThread(Discussion $thread)`: `url` null, everything else kept.
- `RedditEntryRule::apply()` builds the thread discussion and calls it. Delete
  `LINK_TARGET`, `FOOTER`, `footerOf()`, `externalArticle()`, `withoutFooter()`,
  the `AbsoluteHttpUrl` import and `->withOpeningPostBody()`.
- `RedditEntryRuleTest`: first a failing test that a link post has `url` null and keeps
  its footer (the `[link]` anchor survives in `contentHtml`); then delete the tests for
  article extraction, footer stripping, entity decoding, non-absolute and Reddit-hosted
  targets, and the opening-post flag; the fixture test asserts both entries have `url` null.

## Task 2 — delete the `body_is_opening_post` flag

- `Discussion`: the `$bodyIsOpeningPost` promoted property and `withOpeningPostBody()`.
- `EntryDiscussion`: the column and its `store()`/`read()` handling.
- `Entry::getArticleContentHtml()`; `EntryReaderController` hands the gate `getContentHtml()`.
- `ReaderAuditRepository`: `e.content_html` in place of the `CASE`, keeping the
  `article_content_html` alias only if `AuditSampler` reads it (otherwise rename back).
- Backup: `BackupLines` key, `EntryLine` field, `EntryBatchInserter` column and value;
  `docs/backup.md` table row. Restoring an old backup that carries the key still works
  (unknown keys are ignored) — pin that with the existing `EntryLineTest` shape.
- Tests: `DiscussionTest`, `EntryDiscussionTest`, `EntryTest`, `EntryReaderControllerTest`,
  `AuditSamplerTest`, `EntryBatchInserterTest`, `EntryLineTest`, `AccountRestorerTest`,
  `BackupFieldDeclarations`, `FullyPopulatedAccount`.

## Task 3 — migration

`VersionYYYYMMDDHHMMSS`: `UPDATE entry SET url = NULL, url_hash = NULL WHERE
body_is_opening_post = 1`, then `ALTER TABLE entry DROP COLUMN body_is_opening_post`.
`down()` re-adds the column (default 0); the cleared urls are not restored.
Verify: migrate from empty on SQLite, `doctrine:schema:validate`.

## Verification

Native `php bin/phpunit`, `composer check`, `composer md` on touched files,
`composer infection:diff`. The MySQL leg and the visual check need the main checkout's
Docker stack (another session holds it): hand those over as a checklist.
