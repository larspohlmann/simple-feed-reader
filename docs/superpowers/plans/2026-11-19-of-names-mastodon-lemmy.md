# Retire `…Of` Names: Mastodon and Lemmy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No method in `Service/Social/Mastodon` or `Service/Social/Lemmy` is named `of`, `of…` or `…Of`; with this batch none is left in `backend/src` outside the kept names.

**Architecture:** Renames only, the eighth and last PR for #2360. Each method gets the dictionary verb for what it does; the tables below are the decision. Callers anywhere in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2360 and the method-verb table in `CLAUDE.md`. The rules of the first plan (`2026-11-19-of-names-identity-sourcing-outbound.md`, Global Constraints and Decisions) apply unchanged and are repeated here in short.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables (`self::of(...)`), string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No PHPStan rule for the names, no `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class.
- Commits `refactor(#2360): …`. This PR says `Closes #2360`. No attribution lines.
- Renames go through search and replace or the IDE's code-only rename: no tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these areas that matches the pattern but is not in a table, a new name that collides in its class, or a name that does not say what the body does: stop and report the row; do not pick a name.

## Decisions

- **A name says what the method really does** (Lars, 2026-11-19: "always look at what the method really does and chose the name based on that"). Every row below was named from the method's full body and its callers, not from its return type or its old name. A dictionary verb is used where its meaning matches; where no row of the dictionary fits, the plain verb for what happens (`clip`, `split`, `strip`, `collect`, `detect`, `pick`, `hash`).
- `of()` on a named constructor or a conversion becomes `from<Source>()` or `to<Target>()`.
- `parse…` interprets a decoded reply or a string into a value that may be absent; `read…` is a plain access with a default; `collect…` gathers from a list; `pick…` chooses one value; `select…` picks an adapter for a key; `compute…` works a number out.
- The interface implementations in these modules (`buildPage`, `fetchCounts`, `buildSelfReply`, `findContexts`) were renamed with their interfaces in the Social core batch and have no row here.
- The same act carries the name the Bluesky batch gave it: `requireAnswer`, `buildPath`, `buildSelfReply`, `buildComment`, `buildNodes`, `parseReplies`, `parsePublishedAt`, `buildAuthor`, `buildHtml`, `buildEmbeds`, `buildSubject`, `parseKind`, `buildResponseContext`, `buildPreview`, `collectAuthorNames`, `buildSearchedPost`, `buildSearchTexts`, `detectSelfReply`, `parseEntry`, `parseNestedQuote`.
- **Kept names, for the issue's closing comment:** `ImageIdentityModel::isRenditionOf`, `RecommendationRun::guardStatusOneOf`.

## The renames

The last column is what the method does, which is what the reviewer checks the name against. Paths are under `backend/src/Service/Social/`.

### Mastodon, under `backend/src/Service/Social/`

| File | Old | New | What it does |
|---|---|---|---|
| `Mastodon/MastodonApiClient.php` | `answerOf` | `requireAnswer` | the answer of a fetch outcome, whatever its status; throws unreachable when none arrived |
| `Mastodon/MastodonCandidateSource.php` | `sessionOf` (private) | `buildSession` | the account's instance URL with its opened credential |
| `Mastodon/MastodonCandidateSource.php` | `tagsOf` (private) | `buildTagCollections` | the followed tags that are valid, as collections |
| `Mastodon/MastodonCandidateSource.php` | `nodesOf` (private) | `requireNodes` | an answer's list as nodes; throws unreachable without a list |
| `Mastodon/MastodonComposerDefaults.php` | `maxCharactersOf` (private) | `resolveMaxCharacters` | the instance's status length when it answers and says so, else the default |
| `Mastodon/MastodonComposerDefaults.php` | `privacyOf` (private) | `resolvePrivacy` | the account's default visibility when the instance answers and says so, else the default |
| `Mastodon/MastodonComposerDefaults.php` | `mentionsOf` (private) | `collectMentions` | the authors a reply mentions, the account itself left out |
| `Mastodon/MastodonComposerDefaults.php` | `answerOf` (private) | `fetchAnswerOrEmpty` | asks a path as the account; an empty node when unreachable or rejected |
| `Mastodon/MastodonContextReplies.php` | `answerOf` | `buildEnvelope` | a post id and its context JSON as one JSON envelope |
| `Mastodon/MastodonContextReplies.php` | `of` | `parseReplies` | the direct replies of a context envelope; throws without post id or descendants |
| `Mastodon/MastodonContextReplies.php` | `nodesOf` (private) | `buildNodes` | a reply node for each status |
| `Mastodon/MastodonContextReplies.php` | `commentOf` (private) | `buildComment` | a status as a comment |
| `Mastodon/MastodonContextReplies.php` | `selfReplyOf` | `buildSelfReply` | the author's own reply as a comment |
| `Mastodon/MastodonContextReplies.php` | `publishedAtOf` (private) | `parsePublishedAt` | a status's created time, or null |
| `Mastodon/MastodonContextReplies.php` | `authorOf` (private) | `buildAuthor` | an account node as a comment author |
| `Mastodon/MastodonContextReplies.php` | `htmlOf` (private) | `buildHtml` | a status's content, folded under its content warning |
| `Mastodon/MastodonContextReplies.php` | `embedsOf` (private) | `buildEmbeds` | a status's media, card and quote as a comment's embeds |
| `Mastodon/MastodonNotificationContextSource.php` | `statusesOf` (private) | `findStatuses` | asks for a batch of statuses by id |
| `Mastodon/MastodonNotificationContextSource.php` | `withExcerptOf` (private) | `withAnsweredExcerpt` | the context with the answered status's excerpt, unchanged without one |
| `Mastodon/MastodonNotificationSource.php` | `actorOf` (private) | `buildActor` | who a notification comes from, or null without an acct |
| `Mastodon/MastodonNotificationSource.php` | `subjectOf` (private) | `buildSubject` | what a notification is about |
| `Mastodon/MastodonNotificationSource.php` | `referencesOf` (private) | `buildReferences` | the status a reply notification answers |
| `Mastodon/MastodonNotificationSource.php` | `contextOf` (private) | `buildContext` | a notification's context by its kind |
| `Mastodon/MastodonNotificationSource.php` | `quotedContextOf` (private) | `buildQuotedContext` | the context of a quote notification from the quoted status |
| `Mastodon/MastodonNotificationSource.php` | `kindOf` (private) | `parseKind` | the notification kind an entry's type names, or null |
| `Mastodon/MastodonPostResolver.php` | `responseContextOf` (private) | `buildResponseContext` | author, visibility and what the viewer may do with a status |
| `Mastodon/MastodonPostResolver.php` | `previewOf` (private) | `buildPreview` | a status as a preview |
| `Mastodon/MastodonPostWriter.php` | `statusOf` (private) | `buildStatusPayload` | the fields of the status to post |
| `Mastodon/MastodonPrivateFeedRule.php` | `authorNamesOf` (private) | `parseAuthorNames` | the names a stored post's author goes by on the account's instance |
| `Mastodon/MastodonTimelineSource.php` | `pathOf` (private) | `buildPath` | the API path a feed is read from |
| `Mastodon/Support/MastodonAttachments.php` | `of` | `parseMedia` | the usable media of a list of attachments |
| `Mastodon/Support/MastodonAttachments.php` | `entryMediaOf` | `assembleEntryMedia` | a status's attachments as entry media |
| `Mastodon/Support/MastodonAttachments.php` | `mediumOf` (private) | `parseMedium` | one attachment as a medium, or null |
| `Mastodon/Support/MastodonCard.php` | `of` | `fromNode` | a card node as a link card, or null without URL or title |
| `Mastodon/Support/MastodonFollowedTag.php` | `collectionOf` | `parseCollection` | a followed tag as a collection, or null for an invalid name |
| `Mastodon/Support/MastodonQuotedStatus.php` | `ofQuote` | `fromQuote` | an accepted quote with its whole status as a quoted post, or null |
| `Mastodon/Support/MastodonQuotedStatus.php` | `of` | `fromStatus` | a status as a quoted post, or null without URL or handle |
| `Mastodon/Support/MastodonQuotedStatus.php` | `textOf` (private) | `buildText` | a status's lines as text, its content warning first |
| `Mastodon/Support/MastodonQuotedStatus.php` | `nestedOf` (private) | `resolveNestedQuote` | the post a quoted status itself quotes: its accepted quote when it carries one, else the link already found in its content, or null |
| `Mastodon/Support/MastodonSearchPage.php` | `of` | `fromAnswers` | the search answers as one page, each status once |
| `Mastodon/Support/MastodonSearchPage.php` | `distinctStatusesOf` (private) | `collectDistinctStatuses` | the statuses of all answers, each once; throws without a list |
| `Mastodon/Support/MastodonSearchPage.php` | `searchedPostOf` (private) | `buildSearchedPost` | author names and own texts of a status |
| `Mastodon/Support/MastodonSearchPage.php` | `ownTextsOf` (private) | `buildSearchTexts` | the two texts a search matches against: the status's and its quoted status's |
| `Mastodon/Support/MastodonStatusAddress.php` | `ofOrNull` | `parseAddress` | host, id, prefix and account of a status address, or null |
| `Mastodon/Support/MastodonStatusUrl.php` | `of` | `resolveUrl` | a status's `url` when absolute, else its `uri` when absolute, or null |
| `Mastodon/Support/MastodonTimelinePage.php` | `of` | `fromStatuses` | a timeline's statuses as a page |
| `Mastodon/Support/MastodonTimelinePage.php` | `selfReplyOf` (private) | `detectSelfReply` | recognises a status that continues the author's own thread on the page and returns it as a self-reply, or null |
| `Mastodon/Support/MastodonTimelinePage.php` | `rootOf` (private) | `findRoot` | the first post of the own thread a status continues, or null |
| `Mastodon/Support/MastodonTimelinePage.php` | `ownParentOf` (private) | `findOwnParent` | the post a status answers when its author answers themself and it is on the page |
| `Mastodon/Support/MastodonTimelinePage.php` | `knownSharesOf` (private) | `buildKnownShares` | the cards and quotes the page's posts carry |
| `Mastodon/Support/MastodonTimelinePage.php` | `entryOf` (private) | `parseEntry` | a status as a parsed entry, or null |
| `Mastodon/Support/MastodonTimelinePage.php` | `originalOf` | `resolveOriginal` | the boosted status of a boost, else the status itself |
| `Mastodon/Support/MastodonTimelinePage.php` | `bodyOf` (private) | `buildBody` | a post's content under its content warning, or null when empty |
| `Mastodon/Support/MastodonTimelinePage.php` | `mediaOf` (private) | `buildMedia` | a post's attachments as entry media |
| `Mastodon/Support/MastodonTimelinePage.php` | `leadImageOf` (private) | `pickLeadImage` | the first image among the media as the entry's lead image, or null |
| `Mastodon/Support/MastodonTimelinePage.php` | `newestIdOf` (private) | `pickNewestId` | the highest status id of the page, or null |
| `Mastodon/Support/MastodonVisibility.php` | `stricterOf` | `pickStricter` | the more closed of two visibilities |

### Lemmy, under `backend/src/Service/Social/`

| File | Old | New | What it does |
|---|---|---|---|
| `Lemmy/LemmyApiClient.php` | `answerOf` | `requireAnswer` | the answer of a fetch outcome, whatever its status; throws unreachable when none arrived |
| `Lemmy/LemmyCommunities.php` | `pageOf` (private) | `fetchPage` | asks for one page of the account's subscribed communities |
| `Lemmy/LemmyNotificationContextSource.php` | `excerptOf` (private) | `fetchExcerpt` | asks for the answered comment and returns its excerpt, or null |
| `Lemmy/LemmyNotificationSource.php` | `excerptOf` (private) | `buildExcerpt` | a Markdown text as an excerpt, or null |
| `Lemmy/LemmyNotificationSource.php` | `remoteIdOf` (private) | `buildRemoteId` | `kind:itemId` |
| `Lemmy/LemmyNotificationSource.php` | `contextOf` (private) | `buildContext` | thread title and community of a notification |
| `Lemmy/LemmyNotificationSource.php` | `itemIdOf` (private) | `parseItemId` | the item id in a remote id |
| `Lemmy/LemmyNotificationSource.php` | `actorOf` (private) | `buildActor` | who a notification comes from, or null without a handle |
| `Lemmy/LemmyPostEntries.php` | `postViewsOf` (private) | `requirePostViews` | an answer's `posts`; throws unreachable without them |
| `Lemmy/LemmyPostEntries.php` | `distinctPostViewsOf` (private) | `collectDistinctPostViews` | the post views of all answers, each post once |
| `Lemmy/LemmyPostEntries.php` | `entriesOf` (private) | `buildEntries` | the entries of the post views that can be shown |
| `Lemmy/LemmyPostEntries.php` | `entryOf` (private) | `parseEntry` | a post view as a parsed entry; null when it is deleted, removed or incomplete |
| `Lemmy/LemmyPostEntries.php` | `communityOf` (private) | `parseCommunity` | a community node as a post community, or null |
| `Lemmy/LemmyPostEntries.php` | `postEntryOf` (private) | `buildPostEntry` | the parsed entry of a post view at an address |
| `Lemmy/LemmyPostEntries.php` | `bodyOf` (private) | `buildBody` | a post's link and Markdown body as HTML, or null when empty |
| `Lemmy/LemmyPostEntries.php` | `postPageOf` (private) | `resolvePostPage` | the post's own address when it is a post page, else its page on the home instance |
| `Lemmy/LemmyPostEntries.php` | `countsOf` (private) | `buildCounts` | score and comment count as discussion counts |
| `Lemmy/LemmyPostEntries.php` | `mediaOf` (private) | `buildMedia` | a post's thumbnail as entry media |
| `Lemmy/LemmyPostEntries.php` | `newestIdOf` (private) | `pickNewestId` | the highest post id of the views, or null |
| `Lemmy/LemmyPostResolver.php` | `responseContextOf` (private) | `buildResponseContext` | author and what the viewer may do with a post or comment |
| `Lemmy/LemmyPostWriter.php` | `linkPostOf` (private) | `buildLinkPostPayload` | the fields of a link post; throws without a community |
| `Lemmy/LemmyPostWriter.php` | `titleOf` (private) | `requireValidTitle` | the draft's title, trimmed; throws when it is too short or too long |
| `Lemmy/LemmyPostWriter.php` | `commentOf` (private) | `buildCommentPayload` | the fields of a comment on a post or a comment |
| `Lemmy/LemmyPosts.php` | `homePostIdOf` | `resolveHomePostId` | a post's id on the account's instance: its own there, looked up elsewhere; throws when not found |
| `Lemmy/LemmyProfilePictures.php` | `pictureOf` (private) | `fetchPicture` | asks for a user's or community's picture by identity |
| `Lemmy/LemmyProfilePictures.php` | `communityPictureOf` (private) | `fetchCommunityPicture` | asks a community's instance for its icon |
| `Lemmy/LemmyProfilePictures.php` | `userPictureOf` (private) | `fetchUserPicture` | asks a user's instance for their avatar |
| `Lemmy/LemmyProfilePictures.php` | `answerOf` (private) | `fetchAnswer` | asks an instance path and returns the named view; throws when missing |
| `Lemmy/LemmyTimelineSource.php` | `listingPathOf` (private) | `buildListingPath` | the API path of a listing feed; throws for a kind Lemmy has not |
| `Lemmy/Model/LemmyListing.php` | `ofFeedKind` | `fromFeedKind` | the listing a feed kind reads, or null |
| `Lemmy/Support/LemmyCommentPath.php` | `parentOf` | `parseParentId` | the parent comment's id out of a path, or null for a top-level comment |
| `Lemmy/Support/LemmyCommunity.php` | `identityOf` | `parseIdentity` | the lower-cased `name@host` of a community on an https instance, or null |
| `Lemmy/Support/LemmyCommunity.php` | `httpsHostOf` (private) | `parseHttpsHost` | the host of an https actor id, or null |
| `Lemmy/Support/LemmyLanguageTag.php` | `of` | `fromLanguageId` | the language tag of a Lemmy language id, or null when none is stated |
| `Lemmy/Support/LemmySearchTerms.php` | `of` | `fromAlternatives` | one search term per alternative; throws when one has no word |
| `Lemmy/Support/LemmySearchTerms.php` | `longestTermOf` (private) | `pickLongestTerm` | the longest positive term of an alternative's factors |
| `Lemmy/Support/LemmySearchTerms.php` | `longestOf` (private) | `pickLongest` | the longest of the factors' word runs |
| `Lemmy/Support/LemmySearchedPost.php` | `of` | `buildSearchedPost` | title, text and author names of a stored post |
| `Lemmy/Support/LemmySearchedPost.php` | `authorNamesOf` (private) | `parseAuthorNames` | the names in an author's profile URL |
| `Lemmy/Support/LemmyView.php` | `ofPost` | `fromPostView` | a post view as a response target; throws without an id |
| `Lemmy/Support/LemmyView.php` | `ofComment` | `fromCommentView` | a comment view as a response target; throws without ids |

## Review Focus

- **A name that does not say what the body does.** I read about the first six lines of each body. Where the reviewer reads a body differently, the row comes back to the planner with what the method does; the reviewer picks no name.
- **Two `answerOf` in one family mean different things.** `MastodonApiClient::answerOf` is `requireAnswer`; `MastodonContextReplies::answerOf` builds a JSON envelope (`buildEnvelope`); the private ones in `MastodonComposerDefaults` and `LemmyProfilePictures` ask the network (`fetchAnswer`).
- **Wide fan-out:** the two `ApiClient::answerOf`, `MastodonCard::of`, `MastodonStatusUrl::of`, `MastodonTimelinePage::of` and `originalOf`, `MastodonAttachments::of`, `LemmyCommunity::identityOf`, `LemmyView::ofPost`/`ofComment`, `MastodonStatusAddress::ofOrNull`.
- **Callables:** `MastodonFollowedTag::collectionOf(...)`, `self::mediumOf(...)`, `self::longestTermOf(...)`.
- **Collisions.** `MastodonTimelinePage` gets eleven new names beside `ownStatusesById()` and `isBoost()`; `LemmyPostEntries` already has `page()` and `searchPage()`; `LemmySearchTerms` has `termless()` and `isPositive()`.
- **The last check of the issue.** After this batch the pattern must find only the two kept names in all of `backend/src`.

---

### Task 1: Social/Mastodon

- [ ] **Step 1:** Rename the Mastodon rows and every caller in `backend/src`, `backend/tests` and `backend/migrations`.
- [ ] **Step 2:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src/Service/Social/Mastodon`. Expected: no output.
- [ ] **Step 3:** `composer cs:fix && composer check && composer md && php bin/phpunit`. Expected: green, the same test count as develop.
- [ ] **Step 4:** Commit `refactor(#2360): Mastodon methods say what they do`.

### Task 2: Social/Lemmy

- [ ] **Step 1:** Rename the Lemmy rows and every caller.
- [ ] **Step 2:** Run the Task 1 grep over `backend/src/Service/Social/Lemmy`. Expected: no output.
- [ ] **Step 3:** Gates as in Task 1 Step 3.
- [ ] **Step 4:** Commit `refactor(#2360): Lemmy methods say what they do`.

### Task 3: The issue's done-when, and PR

- [ ] **Step 1:** Run `grep -rnE 'function\s+&?(of[A-Z]\w*|of|\w+Of)\s*\(' backend/src`. Expected: exactly `ImageIdentityModel::isRenditionOf`, `RecommendationRun::guardStatusOneOf`. Anything else: stop and report.
- [ ] **Step 2:** In `CLAUDE.md`, the sentence "Existing `…Of` and `of` names are renamed as #2360 reaches them; write no new one." is now out of date. Do not edit `CLAUDE.md`; quote the sentence in the PR text so Lars can decide.
- [ ] **Step 3:** Grep `backend/src` and `backend/tests` for each old name of the tables in quotes. Expected: no hit that refers to a renamed method.
- [ ] **Step 4:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green.
- [ ] **Step 5:** Run `/simplify` on the branch; a finding that changes more than a name goes into the PR text.
- [ ] **Step 6:** Push, open the PR into `develop` with `Closes #2360`, the tables, the three kept names and the follow-ups collected over the eight PRs. Merge when CI is green; verify #2360 closed.
