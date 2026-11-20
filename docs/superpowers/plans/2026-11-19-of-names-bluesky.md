# Retire `…Of` Names: Bluesky Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Social/Bluesky` or `Service/Social/BlueskyPost` is named `of`, `of…` or `…Of`.

**Architecture:** Renames only, the seventh PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`. The rules of the first plan (`2026-11-19-of-names-identity-sourcing-outbound.md`, Global Constraints and Decisions) apply unchanged and are repeated here in short.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::of(...)`), string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule for the names, no `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class.
- Commits `refactor(#2360): …`. The PR says `Refs #2360`, not `Closes`. No attribution lines.
- Renames go through search and replace or the IDE's code-only rename: no tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these areas that matches the pattern but is not in a table, a new name that collides in its class, or a name that does not say what the body does: stop and report the row; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19: "always look at what the method really does and chose the name based on that"). Every row below was named from the method's full body and its callers, not from its return type or its old name. A dictionary verb is used where its meaning matches; where no row of the dictionary fits, the plain verb for what happens (`clip`, `split`, `strip`, `collect`, `detect`, `pick`, `hash`).
- `of()` on a named constructor or a conversion becomes `from<Source>()` or `to<Target>()`.
- `parse…` interprets a decoded reply or a string into a value that may be absent; `read…` is a plain access with a default; `collect…` gathers from a list; `pick…` chooses one value; `select…` picks an adapter for a key; `compute…` works a number out.
- The interface implementations in these modules (`buildPage`, `fetchCounts`, `buildSelfReply`, `findContexts`) were renamed with their interfaces in the Social core batch and have no row here.
- The same act carries the same name in the Mastodon and Lemmy batch: `requireAnswer` on each API client, `buildKey`, `collectActors`, `buildPath`.

## The renames

The last column is what the method does, which is what the reviewer checks the name against.

### Bluesky and BlueskyPost, under `backend/src/Service/Social/`

| File | Old | New | What it does |
|---|---|---|---|
| `Bluesky/BlueskyApiClient.php` | `answerOf` | `requireAnswer` | the answer of a fetch outcome, whatever its status; throws unreachable when none arrived |
| `Bluesky/BlueskyCandidateSource.php` | `urisOf` (private) | `collectUris` | the lists' ids as a set |
| `Bluesky/BlueskyExternalEmbeds.php` | `embedOf` | `prepareEmbed` | reads the page's card, uploads its image as a thumbnail when it can, and returns the external embed for a post |
| `Bluesky/BlueskyExternalEmbeds.php` | `thumbnailOf` (private) | `tryUploadThumbnail` | uploads the card image and returns the thumb field; empty without an image or when the upload fails |
| `Bluesky/BlueskyFacets.php` | `of` | `buildFacets` | the link, mention and tag facets of a text; mentions are looked up on the network |
| `Bluesky/BlueskyFacets.php` | `didsOf` (private) | `findDids` | the DID of each handle that resolves; stops asking once the network is unreachable |
| `Bluesky/BlueskyIdentityResolver.php` | `didOf` | `fetchDid` | asks for a handle's DID; throws unresolvable or unreachable |
| `Bluesky/BlueskyIdentityResolver.php` | `pdsOf` (private) | `fetchPds` | asks for a DID's PDS from its DID document; throws |
| `Bluesky/BlueskyIdentityResolver.php` | `originOf` (private) | `requireOrigin` | a PDS endpoint's https origin; throws when it is not https |
| `Bluesky/BlueskyNotificationContextSource.php` | `ownAnsweredOf` (private) | `detectOwnAnswered` | the answered post's id when the account wrote it, else null |
| `Bluesky/BlueskyNotificationContextSource.php` | `threadRootOf` (private) | `detectForeignThreadRoot` | the thread root's id unless the account wrote it, else null |
| `Bluesky/BlueskyNotificationContextSource.php` | `contextOf` (private) | `buildContext` | a row's context from the looked-up posts, or null |
| `Bluesky/BlueskyNotificationSource.php` | `subjectOf` (private) | `buildSubject` | what a notification is about |
| `Bluesky/BlueskyNotificationSource.php` | `kindOf` (private) | `parseKind` | the notification kind an entry's reason names, or null |
| `Bluesky/BlueskyPostResolver.php` | `responseContextOf` (private) | `buildResponseContext` | author, thread root and what the viewer may do with a post |
| `Bluesky/BlueskyPostResolver.php` | `previewOf` (private) | `buildPreview` | a post view as a preview |
| `Bluesky/BlueskyPostWriter.php` | `replyRefsOf` (private) | `buildReplyRefs` | the root and parent references of a reply |
| `Bluesky/BlueskyPostWriter.php` | `facetsOf` (private) | `buildFacetsField` | the record's `facets` field, left out when there are none |
| `Bluesky/BlueskyPrivateFeedRule.php` | `authorNamesOf` (private) | `collectAuthorNames` | the handle and DID a stored post's author goes by |
| `Bluesky/BlueskyProfilePictures.php` | `actorsOf` (private) | `collectActors` | the followed actor of each feed that names one, by index |
| `Bluesky/BlueskyRecords.php` | `uriOf` (private) | `requireUri` | the `uri` of an answer; throws unreachable without one |
| `Bluesky/BlueskySavedCollections.php` | `savedItemsOf` | `fetchSavedItems` | asks for the account's saved feeds and lists |
| `Bluesky/BlueskySavedCollections.php` | `handleFormOf` (private) | `buildHandleForm` | an at:// URI with the creator's handle as authority |
| `Bluesky/BlueskySessionCache.php` | `keyOf` (private) | `buildKey` | the cache key of an account's session, bound to its credential |
| `Bluesky/BlueskyThreadReplies.php` | `of` | `parseReplies` | the direct replies of a thread answer; throws when it holds no thread |
| `Bluesky/BlueskyThreadReplies.php` | `nodesOf` (private) | `buildNodes` | a reply node for each reply that is shown, muted, blocked and non-thread ones dropped, recursively |
| `Bluesky/BlueskyThreadReplies.php` | `selfReplyOf` | `buildSelfReply` | the author's own reply as a comment |
| `Bluesky/BlueskyThreadReplies.php` | `commentOf` (private) | `buildComment` | a post view as a comment |
| `Bluesky/BlueskyThreadReplies.php` | `publishedAtOf` (private) | `parsePublishedAt` | a post's created or indexed time, or null |
| `Bluesky/BlueskyThreadReplies.php` | `authorOf` (private) | `buildAuthor` | an author node as a comment author |
| `Bluesky/BlueskyThreadReplies.php` | `htmlOf` (private) | `buildHtml` | a record's rich text as sanitised HTML, an uncarded link appended |
| `Bluesky/BlueskyThreadReplies.php` | `embedsOf` (private) | `buildEmbeds` | a rendered embed as a comment's embeds |
| `Bluesky/BlueskyTimelineSource.php` | `timelinePageOf` (private) | `buildTimelinePage` | the page of a timeline answer; throws without a feed |
| `Bluesky/BlueskyTimelineSource.php` | `pathOf` (private) | `buildPath` | the API path a feed is read from |
| `Bluesky/HiddenImportFollows.php` | `actorsOf` (private) | `collectActors` | the actor key of each feed with a valid identity |
| `Bluesky/HiddenProfileSwitchSweep.php` | `candidateOf` (private) | `buildCandidate` | a refused subscription to switch; null for a feed without actor or a public one |
| `Bluesky/HiddenProfileSwitchSweep.php` | `actorsOf` (private) | `collectActors` | the RSS actor of each subscription that names one, by index |
| `Bluesky/ProfileVisibilityCache.php` | `visibilitiesOf` | `readVisibilities` | each actor's cached visibility, unknown when not cached |
| `Bluesky/ProfileVisibilityCache.php` | `itemsOf` (private) | `readItems` | the cache items of the actors by key |
| `Bluesky/ProfileVisibilityCache.php` | `keyOf` (private) | `hashKey` | the xxh128 of an actor |
| `Bluesky/HiddenProfiles/AppViewHiddenProfiles.php` | `profilesOf` (private) | `fetchProfiles` | asks the AppView for a batch of profiles; throws unreachable |
| `Bluesky/Model/RefusedFeedPositionModel.php` | `of` | `fromSubscription` | a subscription's feed id and id as a keyset position |
| `Bluesky/Support/BlueskyProfileLabels.php` | `hiddenProfileOf` (private) | `buildHiddenProfile` | a profile node as a hidden profile |
| `Bluesky/Support/BlueskyRecordUri.php` | `recordKeyOf` | `parseRecordKey` | the record key of a record URI, or null |
| `Bluesky/Support/BlueskyRecordUri.php` | `ofCollection` | `keepIfInCollection` | the URI unchanged when it names a record of the collection and is not over length, else null |
| `Bluesky/Support/BlueskyRecordUri.php` | `partsOf` (private) | `parseParts` | DID, collection and record key of a record URI, or null |
| `Bluesky/Support/BlueskyReplyReferences.php` | `of` | `fromRecord` | the posts a reply or quote record refers to |
| `Bluesky/Support/BlueskyReplyReferences.php` | `replyOf` (private) | `fromReply` | parent and root of a reply field |
| `Bluesky/Support/BlueskySearchPage.php` | `of` | `fromAnswer` | a search answer as a timeline page; throws without results |
| `Bluesky/Support/BlueskySearchPage.php` | `itemsOf` (private) | `filterItems` | the posts that are no replies and match the query, each wrapped as a timeline item |
| `Bluesky/Support/BlueskySearchPage.php` | `searchedPostOf` (private) | `buildSearchedPost` | author names and own texts of a post |
| `Bluesky/Support/BlueskySearchPage.php` | `ownTextsOf` (private) | `buildSearchTexts` | the two texts a search matches against: the post's and its quoted post's, '' without one |
| `Bluesky/Support/BlueskyStrongRef.php` | `of` | `fromPost` | a post's URI and CID |
| `Bluesky/Support/BlueskyTimelinePage.php` | `of` | `fromItems` | a timeline's shown items as a page |
| `Bluesky/Support/BlueskyTimelinePage.php` | `ofAuthor` | `fromAuthorItems` | an author's own posts and self-replies as a page |
| `Bluesky/Support/BlueskyTimelinePage.php` | `pageOf` (private) | `buildPage` | the page of the items a check lets through |
| `Bluesky/Support/BlueskyTimelinePage.php` | `selfReplyOf` (private) | `detectSelfReply` | recognises an item that continues the author's own thread and returns it as a self-reply, or null |
| `Bluesky/Support/BlueskyTimelinePage.php` | `entryOf` (private) | `parseEntry` | an item as a parsed entry, or null |
| `BlueskyPost/EntryEmbedWriter/EntryEmbedWriter.php` | `textBodyOf` (private) | `buildTextBody` | the entry body, rebuilt from the post's facets when it links a web page |
| `BlueskyPost/EntryEmbedWriter/EntryEmbedWriter.php` | `shareKindOf` (private) | `classifyShareKind` | link, media or no share, from the rendered embed |
| `BlueskyPost/Support/AtPostUri.php` | `authorityOf` | `parseAuthority` | the authority of a post URI, or null |
| `BlueskyPost/Support/AtPostUri.php` | `ofWebUrl` | `fromWebUrl` | the post URI a bsky.app address shows, or null |
| `BlueskyPost/Support/AtPostUri.php` | `of` (private) | `buildUri` | the post URI of an authority and record key |
| `BlueskyPost/Support/BlueskyActorKey.php` | `of` | `normalizeActor` | a DID as it is, a handle lower-cased |
| `BlueskyPost/Support/BlueskyEmbedMedia.php` | `dimensionOf` (private) | `parseDimension` | a positive dimension from a string value, or null |
| `BlueskyPost/Support/BlueskyExternalCard.php` | `ofEmbed` | `fromEmbed` | the link card of an external embed, with or without media, or null |
| `BlueskyPost/Support/BlueskyExternalCard.php` | `of` | `fromExternal` | the link card of an external node, or null without URL or title |
| `BlueskyPost/Support/BlueskyExternalCard.php` | `ofNonGif` (private) | `fromNonGifExternal` | the same, null for a GIF |
| `BlueskyPost/Support/BlueskyRichText.php` | `spanOf` (private) | `parseSpan` | a facet's byte range and link target, or null |
| `BlueskyPost/Support/BlueskyRichText.php` | `hrefOf` (private) | `resolveHref` | where a feature points: a link's own URL when valid, a mention's profile, a tag's hashtag page, else null |
| `BlueskyPost/Support/BlueskyRichText.php` | `profileOf` (private) | `buildProfileUrl` | a DID's profile URL, or null |
| `BlueskyPost/Support/BlueskyRichText.php` | `hashtagOf` (private) | `buildHashtagUrl` | a tag's hashtag URL, or null |
| `BlueskyPost/Support/QuotedViewRecord.php` | `of` | `fromRecord` | a quoted record view as a quoted post, or null |
| `BlueskyPost/Support/QuotedViewRecord.php` | `mediaOf` (private) | `parseMedia` | the media of a quoted post's embed |
| `BlueskyPost/Support/QuotedViewRecord.php` | `nestedQuoteOf` (private) | `parseNestedQuote` | the post a quoted post itself quotes, or null |

## Review Focus

- **A name that does not say what the body does.** I read about the first seven lines of each body. Where the reviewer reads a body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **Wide fan-out:** `BlueskyActorKey::of`, `AtPostUri::authorityOf` and `ofWebUrl`, `BlueskyApiClient::answerOf`, `BlueskyExternalCard::of`, `BlueskyTimelinePage::of`, `BlueskySearchPage::of`, `BlueskyStrongRef::of`.
- **Instance calls a `Class::` grep misses:** `$this->facets->of()`, `$this->replies->of()`, `$this->identities->didOf()`, `$this->pageCards->cardOf()` (already `resolveCard` since the Social core batch).
- **Collisions.** `BlueskyThreadReplies` gets seven `build…`; `BlueskyTimelinePage` gets `buildPage`, `fromItems`, `findSelfReply`, `parseEntry`; `BlueskyExternalCard` three `from…`; `AtPostUri` gets `buildUri` beside `webUrl()`.
- **Same new name on purpose:** `collectActors` in three classes, `buildKey`/`hashKey` in the caches, `classifyShareKind` as in `Share/EntryShareClassifier`.

---

### Task 1: Social/Bluesky

- [ ] **Step 1:** Rename the `Bluesky/` rows and every caller in `backend/src`, `backend/tests` and `backend/migrations`.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Social/Bluesky`. Expected: no output.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Bluesky account methods say what they do`.

### Task 2: Social/BlueskyPost

- [ ] **Step 1:** Rename the `BlueskyPost/` rows and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Social/BlueskyPost`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Bluesky post methods say what they do`.

### Task 3: Cross-checks and PR

- [ ] **Step 1:** Grep `backend/src` and `backend/tests` for each old name of the table in quotes. Expected: no hit that refers to a renamed method.
- [ ] **Step 2:** Read the diff once for a renamed `of(` that belongs to a class outside the table. Expected: none.
- [ ] **Step 3:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 4:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 5:** Push, open the PR into `develop` with `Refs #2360` and the table. Merge when CI is green, then comment on #2360 with the areas done and the count that remains.
