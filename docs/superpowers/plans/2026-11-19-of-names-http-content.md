# Retire `…Of` Names: Http and Content Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `backend/src/Http` or `Service/Content` is named `of`, `of…` or `…Of`.

**Architecture:** Renames only, 123 methods, the fourth PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`. The rules of the first plan (`2026-11-19-of-names-identity-sourcing-outbound.md`, Global Constraints and Decisions) apply unchanged and are repeated here in short.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::of(...)`), string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule for the names, no `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class.
- Commits `refactor(#2360): …`. The PR says `Refs #2360`, not `Closes`. No attribution lines.
- A method of these areas that matches the pattern but is not in a table, a new name that collides in its class, or a name that does not say what the body does: stop and report the row; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19: "always look at what the method really does and chose the name based on that"). Every row below was named from the method's full body and its callers, not from its return type or its old name. A dictionary verb is used where its meaning matches; where no row of the dictionary fits, the plain verb for what happens (`clip`, `split`, `strip`, `collect`, `detect`, `pick`, `hash`).
- `of()` on a named constructor or a conversion becomes `from<Source>()` or `to<Target>()`.
- A `…Json` mapper, page or response built from one source is `from<Source>()`; a private helper that shapes one part of a response is `build…Row()`.
- A method that asks a remote and throws on failure is `fetch…`; one that follows redirects or settles between sources and answers without throwing is `resolve…`.
- An interface method is renamed with every implementation: `StatusReasonPhrasesInterface` (implemented in `Http`), `EmbedDetailsSourceInterface`, `CommentsSourceInterface`.

## The renames

The last column is what the method does, which is what the reviewer checks the name against.

### Http (43), under `backend/src/Http/`

| File | Old | New | What it does |
|---|---|---|---|
| `ActiveAiJson.php` | `of` | `fromUser` | the user's active AI connection: ready, model, capabilities |
| `ChaptersJson.php` | `of` | `fromChapters` | the chapters as a JSON list; null without any |
| `ComposerContextJson.php` | `of` | `fromContext` | the composer context as its response shape |
| `ComposerContextJson.php` | `lemmyOf` (private) | `buildLinkPostRow` | communities, title and title limits of a link post |
| `ComposerContextJson.php` | `postOf` (private) | `buildPostTargetRow` | a post the composer answers |
| `ComposerContextJson.php` | `linkOf` (private) | `buildLinkTargetRow` | a link the composer shares |
| `EmbedDetailsJson.php` | `of` | `fromDetails` | title, duration, chapters and artwork of an embed |
| `EntryJson.php` | `discussionOf` (private) | `buildDiscussionRow` | discussion URL, comments mode, score and comment count of a row |
| `EntryJson.php` | `communityOf` (private) | `buildCommunityRow` | name and URL of the row's community, or null |
| `EntryJson.php` | `socialOf` (private) | `buildSocialRow` | network and like state of a Bluesky or Mastodon row, or null |
| `EntryJson.php` | `posterOf` (private) | `buildPosterRow` | who posted the entry when that is not the feed, or null |
| `EntryJson.php` | `authorPosterOf` (private) | `buildAuthorPosterRow` | the entry's author as poster, or null |
| `EntryJson.php` | `originPosterOf` (private) | `buildOriginPosterRow` | the saved entry's origin as poster, or null |
| `EntryPage.php` | `of` | `fromRows` | a page of entries with its next cursor |
| `EntryTranscriptJson.php` | `of` | `fromTranscript` | language and paragraphs of a transcript, or null |
| `LinkCardJson.php` | `of` | `fromCard` | a card's fields with its reader token |
| `LinkCardJson.php` | `fieldsOf` | `buildFields` | a card's stored fields, without a token |
| `LinkCardJson.php` | `ofOrNull` | `fromOptionalCard` | as `fromCard()`, null for no card |
| `MeProfileJson.php` | `of` | `fromUser` | the profile, preferences, AI and catalog block of `/me` |
| `PostDeletionJson.php` | `of` | `fromDeletion` | the deletion outcome |
| `PreviewedDiscoveryJson.php` | `of` | `fromDiscovery` | the candidates and scrape-failure reason of a discovery |
| `PreviewedDiscoveryJson.php` | `labelOf` (private) | `formatLabel` | a format's display label |
| `ProxiedImageResponse.php` | `of` | `fromImage` | the HTTP response that serves a proxied image |
| `PublishedPostJson.php` | `of` | `fromPost` | address and URL of a published post |
| `QuotedPostJson.php` | `ofOrNull` | `fromOptionalQuote` | a quoted post as its row, null for none |
| `ReadingActivityJson.php` | `of` | `fromActivity` | the reading activity payload |
| `RecommendationCapabilitiesJson.php` | `of` | `fromConnection` | the capabilities of a connection's engine |
| `RecommendationCapabilitiesJson.php` | `ofKind` | `fromKind` | the capabilities of an engine kind |
| `RecommendationRunHistoryJson.php` | `completionOf` (private) | `readCompletedAt` | the run's completed-at, only when its status is terminal |
| `SavedSearchPage.php` | `of` | `fromResult` | a saved search's entries as a page |
| `SearchPage.php` | `of` | `fromResult` | a search result as a page with matched words |
| `SocialAccountJson.php` | `syncOf` (private) | `buildSyncRow` | last check, skip and recent changes of an account |
| `SocialCountsJson.php` | `of` | `fromCounts` | a post's counts |
| `SocialCountsJson.php` | `ofReply` | `fromReplyCounts` | a reply's counts |
| `SocialLikeJson.php` | `of` | `fromLiked` | the liked flag as its response |
| `SocialRepostJson.php` | `of` | `fromReposted` | the reposted flag as its response |
| `SocialViewerJson.php` | `of` | `fromPost` | what the viewer did and may do with a post |
| `SymfonyStatusReasonPhrases.php` | `of` | `findPhrase` | implements `StatusReasonPhrasesInterface`, see Content |
| `ThreadPostJson.php` | `of` | `fromPost` | a thread post as its row |
| `TrendingStoryPage.php` | `of` | `fromResult` | a story's entries as a page with join times |
| `TrialEndJson.php` | `of` | `fromUser` | the user's trial end as ATOM, or null |
| `VersionJson.php` | `of` | `fromReport` | running and latest version |
| `Admin/SelfUpdateStatusJson.php` | `of` | `fromStatus` | the self-update status |

### Content (80), under `backend/src/Service/Content/`

| File | Old | New | What it does |
|---|---|---|---|
| `Chapter/ChaptersOnOpen.php` | `chaptersOf` (private) | `serveChapters` | fills an entry's chapters on open and returns what the reader is served |
| `Chapter/ChaptersOnOpen.php` | `audioOf` (private) | `findAudio` | the entry's first audio attachment, or null |
| `Chapter/EmbedDetails.php` | `of` | `fetchDetails` | an embed's details from the cache, else from its provider; throws when unreachable |
| `Chapter/EmbedDetailsCache.php` | `keyOf` (private) | `buildKey` | the cache key of an embed |
| `Chapter/GuardedDescriptionRequest.php` | `answerOf` | `fetchAnswer` | sends the request within the guard and returns the decoded answer |
| `Chapter/GuardedDescriptionRequest.php` | `directAnswerOf` | `fetchDirectAnswer` | the same, directly, after the proxy was refused |
| Chapter/EmbedDetailsSource/EmbedDetailsSourceInterface.php, SoundCloudOembedDetailsSource.php, YouTubePlayerDetailsSource.php | `detailsOf` | `fetchDescription` | asks the provider for an embed's title, duration and description; throws when unreachable (3 methods) |
| `Chapter/EmbedDetailsSource/YouTubePlayerDetailsSource.php` | `videoOf` (private) | `fetchVideo` | the player answer's video details, asked through the proxy, then directly; throws without them |
| `Chapter/Support/ChapterLine.php` | `chapterOf` | `parseChapter` | the chapter a description line states, or null |
| `Chapter/Support/ChapterLine.php` | `positionOf` | `detectStampPosition` | whether a line's time stamp leads, trails or sits inside |
| `Chapter/Support/ChapterLine.php` | `rangeChapterOf` (private) | `parseRangeChapter` | the chapter of a line that leads with a time range, or null |
| `Chapter/Support/DescriptionChapters.php` | `blocksOf` (private) | `collectBlocks` | the distinct blocks a run of lines sits in |
| `Chapter/Support/DescriptionLines.php` | `of` | `fromDocument` | the non-blank lines of a document's body |
| `Chapter/Support/JsonChapters.php` | `chapterOf` (private) | `parseChapter` | the chapter a JSON row states, or null |
| `Comments/CommentsCache.php` | `lifetimeOf` (private) | `pickLifetime` | the shorter lifetime while a thread still gathers, else the settled one |
| `Comments/CommentsCache.php` | `keyOf` (private) | `hashKey` | the xxh128 of a cache key |
| Comments/CommentsSource/CommentsSourceInterface.php, CommentsFeedSource.php, LemmyCommentsSource.php, SubstackCommentsSource.php | `audienceOf` | `buildAudience` | the cache scope that gets the same answer (4 methods) |
| `Comments/CommentsSource/CommentsFeedSource.php` | `feedUrlOf` (private) | `requireFeedUrl` | the entry's comments feed URL; throws without one |
| `Comments/CommentsSource/LemmyCommentsSource.php` | `postOf` (private) | `requirePost` | the entry's Lemmy post; throws when it is none |
| `Comments/CommentsSource/SubstackCommentsSource.php` | `postOf` (private) | `requirePost` | the entry's Substack post; throws when it is none |
| `Comments/CommentsSource/SubstackCommentsSource.php` | `postIdOf` (private) | `fetchPostId` | asks Substack for the post's id; throws without one |
| `Comments/Pass/LemmyThread.php` | `idOf` (private) | `readId` | a comment node's id |
| `Comments/Pass/LemmyThread.php` | `parentOf` (private) | `extractParentId` | the parent id out of a comment's path |
| `Comments/Support/LemmyPostAddress.php` | `of` | `findPost` | the Lemmy post an entry is, or null (as `SocialPostAddresses::findPost()`) |
| `Comments/Support/SubstackPostAddress.php` | `of` | `findPost` | the Substack post an entry is, or null |
| `LinkCard/ShareLinkCards.php` | `plansOf` (private) | `buildPlans` | every queued row's fetch plan, with the stored links it can reuse |
| `LinkCard/Model/LinkCardBudgetModel.php` | `durationOf` (private) | `computeDuration` | the time a number of fetches may take |
| `LinkCard/Support/DeclaredPageMedium.php` | `of` | `fromMeta` | the video a page's meta tags declare, or null |
| `LinkCard/Support/ReusableShareUrls.php` | `of` | `filterUrls` | the plan's URLs that are not the quoted target; none for a titled share |
| `LinkCard/Support/StorableLinkCard.php` | `of` | `fromCard` | the card cleaned to what can be stored, or null |
| `LinkCard/Support/StorableQuotedPost.php` | `of` | `fromQuote` | the quote cleaned to what can be stored, or null |
| `LinkResolution/LinkLanding.php` | `of` | `resolveLanding` | follows a link and says where it lands: changed, unchanged, retryable or unresolved |
| `LinkResolution/LinkLanding.php` | `landingOf` (private) | `fetchLanding` | follows the link by HEAD, else by GET; throws on a transport failure |
| `LinkResolution/LinkLanding.php` | `outcomeOf` (private) | `buildOutcome` | the landing outcome for a landing URL |
| `Reader/ArticleContentGate.php` | `contentOf` | `requireContent` | the article's content; throws when it is empty or too short without media |
| `Reader/RelatedTeaserGridRemover.php` | `cardOf` (private) | `findCard` | the image-carrying ancestor of a heading that holds no real prose, or null |
| `Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizer.php` | `clipOf` (private) | `buildClip` | the clip of a player; null without a source or for a live stream |
| `Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizer.php` | `durationOf` (private) | `findDuration` | the duration of the player's first `<time>`, or null |
| `Reader/BodyCleaning/BodyCleaningStep/AudioPlayerNormalizer.php` | `sourceOf` (private) | `findSource` | the player's own src, else its first audio `<source>`, or null |
| `Reader/Media/AudioClipFacts.php` | `titleOf` | `findTitle` | the first non-empty title among the holders, or null |
| `Reader/Media/AudioClipFacts.php` | `durationOf` | `findDuration` | the first readable duration among the holders, or null |
| `Reader/Media/MediaLanding.php` | `urlOf` | `resolveUrl` | follows a media URL's redirects and returns where it lands, or null |
| `Reader/Media/MediaCandidateSource/SemanticMediaSource.php` | `resolvedSourceOf` (private) | `resolveSource` | the element's first source whose kind fits the element, or null |
| `Reader/Media/MediaCandidateSource/YouTubeIdAttributeSource.php` | `targetOf` (private) | `resolveTarget` | the embed target of an element's YouTube id, or null |
| `Reader/Media/Sibling/Pass/SiblingSearch.php` | `siblingsOf` | `findSiblings` | the other renditions of a seed's media id in the page |
| `Reader/Media/Sibling/Pass/SiblingSearch.php` | `idOf` (private) | `parseId` | the media id in a URL's file name, or null |
| `Reader/Media/Sibling/Support/KeyedOccurrences.php` | `of` | `findOccurrences` | every keyed occurrence of an id in the HTML |
| `Reader/Model/PageHeadModel.php` | `ofPage` | `fromPage` | a head read from an HTML page |
| `Reader/Model/PageHeadModel.php` | `ofFile` | `fromFile` | a head for a file of a given type |
| `Reader/Model/ParseOffsetUnit.php` | `lengthOf` | `measureLength` | a text's length in this unit, bytes or code points |
| `Reader/PageRepair/ImageWrapperClassRemover.php` | `stripWrappersOf` (private) | `stripWrapperAttributes` | removes class and id from an image's sole-image wrappers |
| `Reader/PageRepair/NoscriptImageUnwrapper.php` | `promoteImageOutOf` (private) | `unwrapImage` | replaces a `<noscript>` holding an image with its children |
| `Reader/RecipeFacts/RecipeFactsRecognizer.php` | `factsOf` (private) | `collectFacts` | the facts of a container's items |
| `Reader/RecipeFacts/RecipeFactsRecognizer.php` | `factOf` (private) | `parseFact` | label and value of one item, or null when both are empty |
| `Reader/RecipeFacts/RecipeFactsRecognizer.php` | `textOf` (private) | `readText` | the trimmed text of an item's child by class, `''` without it |
| `Reader/Slideshow/SlideshowRecognizer/MarkupCarouselRecognizer.php` | `slidesOf` (private) | `findSlides` | the container's slides by the rule's selector, else its children |
| `Reader/Slideshow/SlideshowRecognizer/MarkupCarouselRecognizer.php` | `altOf` (private) | `readAlt` | the first non-empty image alt of a slide, `''` without one |
| `Reader/Slideshow/SlideshowRecognizer/TagesschauCarouselRecognizer.php` | `stringOf` (private) | `readString` | the value when it is a string, else `''` |
| Reader/StatusReasonPhrases/StatusReasonPhrasesInterface.php and `Http/SymfonyStatusReasonPhrases.php` | `of` | `findPhrase` | the standard reason phrase of a status code, `''` for a code without one |
| `Reader/Support/DeclaredPreview.php` | `of` | `fromDocument` | the preview a page declares in its meta tags and title |
| `Reader/Support/StylesheetHiddenIds.php` | `idsOf` (private) | `extractIds` | the ids named by a selector list |
| `ReaderAudit/AuditSampler.php` | `detailsOf` (private) | `loadDetails` | the sampled entries' detail rows as models by id |
| `ReaderAudit/LeadingRegion.php` | `blocksOf` | `collectLeadingBlocks` | the body's blocks before its first prose block |
| `ReaderAudit/SocialWidgetMarkers.php` | `socialHostOf` (private) | `findSocialHost` | the social host a link points at, or null |
| `ReaderAudit/Model/ExtractedBodyModel.php` | `blockOf` (private) | `buildBlock` | a body block from an element with its links |
| `ReaderAudit/Model/ExtractedBodyModel.php` | `linkOf` (private) | `buildLink` | a body link from an anchor |
| `Transcript/TranscriptFiller.php` | `contextOf` (private) | `buildLogContext` | the log context of a failed transcript |
| `Transcript/TranscriptReader.php` | `paragraphsOf` (private) | `parseParagraphs` | a transcript body as paragraphs, by its format |
| `Transcript/Support/JsonCues.php` | `of` | `parseCues` | the cues of a JSON transcript; throws when malformed |
| `Transcript/Support/JsonCues.php` | `cueOf` (private) | `parseCue` | one segment as a cue, or null without text |
| `Transcript/Support/JsonCues.php` | `startOf` (private) | `parseStart` | a usable start time as whole seconds, or null |
| `Transcript/Support/TimedCues.php` | `of` | `parseCues` | the cues of a VTT or SRT transcript |
| `Transcript/Support/TimedCues.php` | `cueOf` (private) | `parseCue` | a block's cue from its timing line on, or null |
| `Transcript/Support/TimedCues.php` | `startOf` (private) | `parseStart` | the start of a timing line in seconds, or null |
| `Transcript/Support/TranscriptParagraphs.php` | `searchTextOf` | `toSearchText` | the paragraphs as one text, speakers prefixed |

## Review Focus

- **A name that does not say what the body does.** The last column is my reading of each body; I read about the first ten lines of the longer ones. Where the reviewer reads a body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **`of()` on injected mappers is an instance call** (`$this->linkCardJson->of()`, `$this->entryPage->of()`, `$this->activeAi->of()`, `$this->capabilities->of()`, `$this->embedDetails->of()`); a `Class::of` grep misses them, and controllers are the main callers.
- **Three interfaces.** `StatusReasonPhrasesInterface` is implemented in `Http`; `EmbedDetailsSourceInterface` and `CommentsSourceInterface` have several implementations and test doubles.
- **Same new name on purpose:** `fromUser`, `fromPost`, `fromResult` on several mappers; `parseCues`, `parseCue`, `parseStart` on both cue helpers; `requirePost` and `findPost` on the Lemmy and Substack classes; `findDuration` on two audio classes.
- **Name collisions.** `EntryJson` and `ComposerContextJson` get several `build…Row`; `LinkLanding` gets `fetchLanding` beside `landingByGet()`; `LinkCardJson` gets `fromCard`, `buildFields`, `fromOptionalCard`.
- **Left alone:** `LinkLanding::outcomeOfFailure()` and other names with `Of` in the middle are not in the pattern and not in this batch.

---

### Task 1: Http

- [ ] **Step 1:** Rename the 43 Http methods and every caller in `backend/src`, `backend/tests` and `backend/migrations`. `SymfonyStatusReasonPhrases::of()` waits for Task 2.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Http`. Expected: one line, `SymfonyStatusReasonPhrases::of`.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Http mappers say what they do`.

### Task 2: Content/Chapter, Comments, LinkCard, LinkResolution

- [ ] **Step 1:** Rename the 39 methods of these four modules with their interfaces, implementations, doubles and callers.
- [ ] **Step 2:** Run the Task 1 grep over those four folders. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Content chapter, comments and link methods say what they do`.

### Task 3: Content/Reader, ReaderAudit, Transcript

- [ ] **Step 1:** Rename the 41 methods of these three modules, `StatusReasonPhrasesInterface::of()` with its `Http` implementation, and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Content backend/src/Http`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Content reader and transcript methods say what they do`.

### Task 4: Cross-checks and PR

- [ ] **Step 1:** Grep `backend/src` and `backend/tests` for each old name of the tables in quotes. Expected: no hit that refers to a renamed method.
- [ ] **Step 2:** Read the diff once for a renamed `of(` that belongs to a class outside the tables. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the two tables. Merge when CI is green, then comment on #2360 with the areas done and the count that remains.
