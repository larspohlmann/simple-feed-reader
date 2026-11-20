# Verbs for Bare `…For/In/At/Among/OrNull` Names: Content Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In `Service/Content`, no method is a bare noun phrase ending in `For`, `In`, `At`, `Among`, `OrNull` or `OrRefuse`, or a bare `or…`.

**Architecture:** Renames only, the third of five PRs for #2400 (then Curation, Social). The table below is the decision. Callers in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2400 and the method-name paragraph in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock or comment changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables, string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Several classes share an old name (`candidateFor` four times, `figureFor` three times, `sourceFor`, `commentOrNull`, `declaredIn`). Rename per class through the type, never by a tree-wide text replace: the same name in a module outside this batch stays until its own batch.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class, no `CLAUDE.md` edit.
- Commits `refactor(#2400): …`. The PR says `Refs #2400` (the last batch closes it). No attribution lines.
- A living document under `docs/` (not `docs/superpowers/`) that names a renamed method is updated by hand in a separate `docs(#2400)` commit. Search `docs/` for each old name before the PR.
- A bare noun-led method the endings pattern misses because a word follows the preposition (`requestForNetwork`) or the name starts with `for`: report it when it sits in a class this batch already touches; do not sweep the group for more. The rest goes to the follow-up named in the PR.
- No tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these groups that is a bare name with one of the endings but is in no table and not under Kept names, a new name that collides in its class, or a name that does not say what the body does: stop and report the row to the planner; do not pick a name.

## Decisions

- Every row was named from the method's full body and its callers. A `CLAUDE.md` verb where its meaning matches; otherwise the plain verb the tree already uses for that act. The verbs #2360 settled apply: `build…` assembles, `parse…` interprets a string or decoded array into a value that may be absent, `find…` looks up (null when absent), `resolve…` settles between sources or falls back, `read…` is a plain access with a default, `collect…` gathers from a list, `keepIf…` passes a value through or drops it, `try…` swallows failure.
- In scope is a name that does not start with a verb. `findSubscriptionFor`, `getCreatedAt`, `isDueAt` and the like are not touched.
- `fetch…` asks a remote and throws on failure; `find…` also names a best-effort network lookup that logs and skips failures, as settled in #2360. `ensure…` is find-or-create.
- A yes/no question starts with `is…` or `has…`: `foundOutsideFurnitureIn` becomes `hasMatchOutsideFurniture`.

## The renames

The last column is what the reviewer checks the name against.

### Chapter, Comments, Transcript, ReaderAudit (paths under `backend/src/Service/Content/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Chapter/ChapterFileSource/ChapterFileSourceInterface.php` and every implementation | `chaptersAt` | `fetchChapters` | Requests a chapter file and returns its chapters; throws `ChapterFileUnreachableException` when it cannot be reached, `[]` when the request is refused or the answer is no success |
| `Chapter/EmbedDetails.php` | `chaptersIn` | `parseChapters` | Reads the chapters out of an embed description; `[]` when the description yields no HTML |
| `Chapter/EmbedDetails.php` | `sourceFor` | `selectSource` | Picks the embed details source registered for a provider from the keyed locator |
| `Chapter/EmbedDetailsSource/YouTubePlayerDetailsSource.php` | `playerRequestFor` | `buildPlayerRequest` | Assembles the POST to YouTube's player endpoint for an embed |
| `Chapter/EmbedDetailsSource/YouTubePlayerDetailsSource.php` | `secondsIn` | `parseSeconds` | Reads a positive number of seconds out of an int or a digit string; null otherwise |
| `Chapter/EmbedDetailsSource/YouTubePlayerDetailsSource.php` | `videoDetailsIn` | `readVideoDetails` | The `videoDetails` array of a decoded player answer, null when absent or no array |
| `Comments/CommentsLoader.php` | `sourceOrNull` | `findSource` | The first comments source that serves the entry, null when none does |
| `Comments/CommentsLoader.php` | `sourceFor` | `requireSource` | `findSource`, throwing `NoCommentsFeedException` when none serves the entry |
| `Comments/LemmyThreadReader/LemmyThreadReaderInterface.php` and every implementation | `readerFor` | `findReader` | The social account a user reads Lemmy threads with, null when they have none |
| `Comments/LemmyThreadReader/LemmyThreadReaderInterface.php` and every implementation | `threadAs` | `fetchThread` | Asks the instance for the post's thread as the given account, rate-limited; returns the body; throws `CommentsUnavailableException`/`CommentsThrottledException` on failure |
| `Comments/Pass/LemmyThread.php` | `commentOrNull` | `parseComment` | Interprets a comment node into a comment model; null when it is removed, deleted or has no usable HTML |
| `Comments/Pass/SubstackThread.php` | `commentOrNull` | `parseComment` | Interprets a comment node into a comment model; null when it has no id, no usable HTML or is deleted |
| `ReaderAudit/Model/PhraseFamilyModel.php` | `matchIn` | `findPhrase` | The first of the family's phrases a block contains; null when none or the block is too long |
| `ReaderAudit/PhraseMarkers.php` | `scopeFor` | `collectBlocksInScope` | The body blocks a family looks at: the leading blocks; for `OnlyWhenNoArticle` all blocks when the body has no article text, `[]` when it has |
| `Transcript/Support/TimedCues.php` | `cueAt` | `parseCueText` | Interprets a cue's text lines (voice tag, inline label, plain text) into a cue with its start time; null when no text is left. `parseCue` finds the timing line and delegates to it |
| `Transcript/TranscriptSource/TranscriptSourceInterface.php` and every implementation | `bodyAt` | `fetchBody` | Requests a transcript file within a budget and returns its body; throws unreachable or unusable exceptions otherwise |

### LinkCard (paths under `backend/src/Service/Content/LinkCard/`)

| File | Old | New | What it does |
|---|---|---|---|
| `PostedUrlCheck.php` | `cardAt` | `resolveCard` | Reads the cleaned URL's card and settles between it and the posted URL's: falls back to the posted one when the cleaned read fails or lands on another page |
| `QuotedPostSource/QuotedPostSourceInterface.php` and every implementation | `quotedPostAt` | `fetchQuotedPost` | Asks the network for the post at an address; throws `QuotedPostUnavailableException` when it names no post or reading failed |
| `ShareTargetReader.php` | `cardsFor` | `findEntryCards` | Looks up an entry's share targets over the network (page cards of its uncarded URLs, its quoted post), logging and skipping failures, and returns them with the stored cards |
| `ShareTargetReader.php` | `pageCardAt` | `resolvePageCard` | Settles one URL's card between three sources: the known one from the plan, the posted-URL check, a page read; the last two throw `PageFetchException` |
| `ShareTargetReader.php` | `pageCardsFor` | `findPageCards` | Fetches the card of each URL, logging and skipping the ones that fail |
| `ShareTargetReader.php` | `quotedTargetAmong` | `findQuotedTarget` | The quoted post behind the body's quote line when its link is one of the URLs; logs and answers null when the post is unavailable |
| `SharedLinkWriter.php` | `linksFor` | `ensureLinks` | Claims the stored links of the cards' URLs, inserts the missing ones and returns a link per URL |
| `SharedLinkWriter.php` | `linkFor` | `ensureLink` | `ensureLinks` for one card |
| `SharedLinkWriter.php` | `idsFor` | `ensureLinkIds` | `ensureLinks`, mapped to the links' ids |
| `SharedLinkWriter.php` | `knownFor` | `claimKnownLinks` | Claims the already stored links of the URLs and returns them; a URL without one is left out, nothing is inserted |
| `Support/QuoteLine.php` | `targetIn` | `parseTarget` | Parses the HTML and returns the href of the first link in a "RE:" paragraph, null when it quotes nothing |
| `Support/QuoteLine.php` | `trackingFreeTargetIn` | `parseTrackingFreeTarget` | `parseTarget` without tracking parameters |
| `Support/QuoteLine.php` | `removedFrom` | `stripQuote` | Returns the HTML without its "RE:" quote paragraph (`TrailingUrl::removedFrom` waits for its batch) |

### Reader (paths under `backend/src/Service/Content/Reader/`)

| File | Old | New | What it does |
|---|---|---|---|
| `BodyCleaning/BodyCleaningStep/SubstackPosterLink.php` | `linkIn` | `linkPosters` | Runs `linkOne` for every image of the body |
| `Media/MediaCandidateSource/JsonLdMediaSource.php` | `declarationsIn` | `collectDeclarations` | Gathers, per JSON-LD node that carries media URLs, those URLs and the node's thumbnail |
| `Media/MediaCandidateSource/JsonLdMediaSource.php` | `thumbnailIn` | `readThumbnail` | The node's `thumbnailUrl` as a string, or the first of a list; null otherwise |
| `Media/MediaCandidateSource/JsonLdMediaSource.php` | `urlsIn` | `collectUrls` | Gathers the string values under the node's known URL keys |
| `Media/MediaCandidateSource/SemanticMediaSource.php` | `candidateFor` | `buildCandidate` | Assembles a media candidate from an audio/video element; null when its source does not resolve |
| `Media/MediaCandidateSource/ZdfPlayerConfigSource.php` | `candidateFor` | `buildCandidate` | Assembles a stream candidate for a ZDF id; null when the URL is no stream or no poster is nearby |
| `Media/Sibling/Pass/SiblingSearch.php` | `candidateFor` | `buildCandidate` | Assembles the candidate for a sibling id by substituting it into the seed's URL; null when the id sits inside a URL or has no poster |
| `Media/Sibling/Pass/SiblingSearch.php` | `siblingsIn` | `findOccurrenceSiblings` | Searches the page for other ids under one occurrence's key that share its context; `[]` when there are too many. The public `findSiblings` exists and calls it per occurrence |
| `Media/Sibling/Support/KeyedOccurrences.php` | `firstKeyIn` | `extractFirstKey` | The first key the pattern matches in a text, `''` when none |
| `Media/Sibling/Support/KeyedOccurrences.php` | `lastKeyIn` | `extractLastKey` | The last key the pattern matches in a text, `''` when none |
| `Media/Sibling/Support/KeyedOccurrences.php` | `at` | `findOccurrenceAt` | The keyed occurrence of an id at a position in the text; null when no key precedes it |
| `Media/PlayerPoster.php` | `firstImageIn` | `findFirstImage` | The `src` of the first `https://` image in a scope, null when none |
| `Media/PlayerPoster.php` | `near` | `findNearbyPoster` | The first usable image in the holder or its ancestors; null when none |
| `Media/Teaser/TeaserPlayerMarkup.php` | `figureFor` | `buildFigure` | Creates the teaser's `<figure>` element with player and caption |
| `RecipeFacts/RecipeFactsMarkup.php` | `figureFor` | `buildFigure` | Creates the recipe facts `<figure>` with a `<dl>` of the facts |
| `Slideshow/SlideshowMarkup.php` | `figureFor` | `buildFigure` | Creates the slideshow `<figure>` with caption and slide list |
| `Model/FeedMediaModel.php` | `declaredAttachmentFor` | `findDeclaredAttachment` | The feed attachment whose bare URL equals the given URL's, null when none |
| `Model/FeedMediaModel.php` | `declaredMediumFor` | `findDeclaredMedium` | The feed medium matching a URL by image identity or bare URL, null when none |
| `Model/LeadFigureCaptionsModel.php` | `captionFor` | `findCaption` | The caption of the figure that shows the same asset as the lead image, null when none |
| `PageRepair/LazyImageSources.php` | `candidateFor` | `findCandidate` | The first usable URL among an image's lazy-load attributes, srcsets and enclosing picture; null when none |
| `PageRepair/LazyImageSources.php` | `candidateFromEnclosingPicture` | `findCandidateInPicture` | The first usable URL among the sources of the image's enclosing picture; null when none |
| `Paywall/MembershipCheckout.php` | `foundOutsideFurnitureIn` | `hasMatchOutsideFurniture` | Whether a checkout link appears in the document outside page furniture |
| `Paywall/PaywallBlocks.php` | `foundOutsideFurnitureIn` | `hasMatchOutsideFurniture` | Whether a paywall-classed block appears in the document outside page furniture |
| `Paywall/Support/SchemaOrgAccess.php` | `declaredIn` | `detectAccess` | Recognises the page's schema.org access declaration: paywalled on the first `false`, free when only `true` was seen, else undeclared |
| `ReaderUrlSignature.php` | `tokenFor` | `computeToken` | The base64url HMAC-SHA256 of a URL under the signing key |

## Review Focus

- Four interfaces are renamed with every implementation and test double: `ChapterFileSourceInterface`, `TranscriptSourceInterface`, `LemmyThreadReaderInterface` and `QuotedPostSourceInterface`. The last two have their implementations in `Service/Social`; they follow in this PR.
- `candidateFor`, `figureFor`, `sourceFor`, `commentOrNull` and `declaredIn` exist in several classes with different new names (`buildCandidate` three times, `findCandidate` once). Rename per class through the type.
- `sourceFor` in `Curation/Trending/TrendingStories`, `keyFor`, `nameFor` and other same-named methods outside `Service/Content` wait for their batch.
- The comment in `LeadFigureCaptionsModel` that names `captionFor()`, and any `{@see}` naming an old method.
- `parseComment` here may answer null; the Bluesky reply code of #2360 uses `buildComment`. If the two do the same act with the same absence rule, report it instead of leaving two verbs.
- String method names in mocks: search `backend/tests` for each bare old name.

---

### Task 1: Chapter, Comments, Transcript, ReaderAudit

- [ ] **Step 1:** Rename the rows of the first table, interfaces, implementations and test doubles included, and every caller.
- [ ] **Step 2:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): chapter, comments and transcript methods start with their verb`.

### Task 2: LinkCard

- [ ] **Step 1:** Rename the LinkCard rows, the `QuotedPostSourceInterface` implementations and doubles included.
- [ ] **Step 2:** Run the same four commands. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): link card methods start with their verb`.

### Task 3: Reader

- [ ] **Step 1:** Rename the Reader rows and every caller.
- [ ] **Step 2:** Run `grep -rnoE 'function\s+&?(\w+(OrNull|OrRefuse|For|In|At|Among)|or[A-Z]\w*)\s*\(' backend/src/Service/Content`. Expected: only names that start with a verb.
- [ ] **Step 3:** Run the same four commands. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2400): reader methods start with their verb`.

### Task 4: Gates and PR

- [ ] **Step 1:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; escaped mutants only on lines whose logic this PR did not change, listed in the PR.
- [ ] **Step 2:** Open the PR into `develop` with the rename tables and `Refs #2400`; merge when green.
