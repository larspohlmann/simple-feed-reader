# LinkedIn release announcements (#2204) — implementation plan

**Goal:** `announce-release.yml` posts each release's highlights to LinkedIn as a
single post from Lars's personal profile, next to the Bluesky and Mastodon threads.

**Architecture:** a third network in `scripts/announce/announce.mjs`. Each network
now composes its own posts (`compose(release)`), so Mastodon and Bluesky keep
`buildThread` and LinkedIn gets the single-post `buildPost`. `linkedin.mjs` escapes
the commentary to little text, uploads `docs/homepage/share.png` through the
Images API and posts an article card through `/rest/posts`.

**API facts (Microsoft Learn, read 2026-11-13):**
- Every call sends `Linkedin-Version: 202610` (newest listed) and
  `X-Restli-Protocol-Version: 2.0.0`. A version is supported for about a year.
- `POST /rest/images?action=initializeUpload` `{initializeUploadRequest: {owner}}`
  → `value.uploadUrl`, `value.image`; then `PUT` the bytes to `uploadUrl` with the
  bearer token. A person may own the image (`w_member_social`).
- `POST /rest/posts` with `author`, `commentary`, `visibility: PUBLIC`,
  `distribution: {feedDistribution: MAIN_FEED, targetEntities: [], thirdPartyDistributionChannels: []}`,
  `content.article {source, thumbnail, title, description}`,
  `lifecycleState: PUBLISHED`, `isReshareDisabledByAuthor: false` → `201`, the
  post URN in the `x-restli-id` header; the post lives at
  `https://www.linkedin.com/feed/update/<urn>/`.
- little text reserves `| { } @ [ ] ( ) < > # \ * _ ~`; each is escaped with a
  backslash. A plain `#word` is a hashtag, so `#` stays unescaped (the issue's
  ruling: `#rss #rssreader` stay as they are).

## Tasks

1. **`buildPost` in `thread.mjs`** (test first in `thread.test.mjs`): one string —
   `simple-feed-reader <tag> is out. #rss #rssreader`, a blank line, `Highlights:`,
   each highlight as a `• ` bullet, a blank line, `Full release notes: <url>`.
   The homepage rides on the article card, not in the text.
2. **`linkedin.mjs`** (test first in `linkedin.test.mjs`, `fakeFetch` gains optional
   response headers):
   - `escapeLittleText(text)` backslash-escapes the reserved set minus `#`.
   - `postLinkedIn(posts, { token, author, card, fetch })` uploads the thumb when
     there is one (none → article without `thumbnail`), posts the escaped
     commentary with the article, returns the feed URL. A refused call throws
     naming the step and the HTTP status, like the other two networks.
3. **`announce.mjs`**: networks carry `compose`; LinkedIn is configured by
   `LINKEDIN_TOKEN` + `LINKEDIN_AUTHOR`; the dry-run header says `1 post` /
   `3 posts`. Update `announce.test.mjs` (dry run lists LinkedIn, a configured
   LinkedIn posts once, missing secrets warn).
4. **Workflow + docs**: pass the two secrets in `announce-release.yml`, name
   LinkedIn in `release.yml`'s header comment, and in `docs/releasing.md#announcements`
   describe the single post, the two secrets, the app setup and the 60-day renewal
   recipe (token generator → `gh secret set LINKEDIN_TOKEN`), and the
   `Linkedin-Version` bump once a year.
5. **Verify**: `node --test "scripts/test/announce/*.test.mjs"`, a `--dry-run`
   against a real release body, `/simplify`, then the PR.

A live post is not part of the tests; the first real release announcement (or a
manual workflow run on an older tag) is the live check.
