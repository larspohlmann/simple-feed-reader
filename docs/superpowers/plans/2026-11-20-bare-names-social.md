# Verbs for Bare `…For/In/At/Among/OrNull` Names: Social Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In `Service/Social`, no method is a bare noun phrase ending in `For`, `In`, `At`, `Among`, `OrNull` or `OrRefuse`, or a bare `or…`.

**Architecture:** Renames only, the fifth and last PR for #2400. The table below is the decision. Callers in `backend/src`, `backend/tests` and `backend/migrations` follow.

**Tech Stack:** PHP 8.5, PHPUnit, PHPStan, PHPMD.

**Spec:** GitHub issue #2400 and the method-name paragraph in `CLAUDE.md`.

## Global Constraints

- Renames only: no signature, body, visibility or behaviour change. A docblock or comment changes only where it names the old method.
- Every caller in `backend/src`, `backend/tests` and `backend/migrations` follows, first-class callables, string method names in mocks and docblock references included. Test method names are not renamed.
- An interface method is renamed together with every implementation and test double, wherever it lives.
- Several classes share an old name (`hiddenAmong` four times, `defaultsFor` four times, `ticketFor`, `postAt`, `contextFor`, `sourceFor`, `repliesIn`). Rename per class through the type, never by a tree-wide text replace: the same name in a module outside this batch stays until its own batch.
- Per task: `composer cs:fix`, `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no new class, no `CLAUDE.md` edit.
- Commits `refactor(#2400): …`. This PR says `Closes #2400`. No attribution lines.
- A living document under `docs/` (not `docs/superpowers/`) that names a renamed method is updated by hand in a separate `docs(#2400)` commit. Search `docs/` for each old name before the PR.
- A bare noun-led method the endings pattern misses because a word follows the preposition (`requestForNetwork`) or the name starts with `for`: report it when it sits in a class this batch already touches; do not sweep the group for more. The rest goes to the follow-up named in the PR.
- No tool may rewrite text in `docs/`. Before each commit `git status` shows no change under `docs/` other than this plan's own table updates.
- A method of these groups that is a bare name with one of the endings but is in no table and not under Kept names, a new name that collides in its class, or a name that does not say what the body does: stop and report the row to the planner; do not pick a name.

## Decisions

- Every row was named from the method's full body and its callers. A `CLAUDE.md` verb where its meaning matches; otherwise the plain verb the tree already uses for that act. The verbs #2360 settled apply: `build…` assembles, `parse…` interprets a string or decoded array into a value that may be absent, `find…` looks up (null when absent), `resolve…` settles between sources or falls back, `read…` is a plain access with a default, `collect…` gathers from a list, `keepIf…` passes a value through or drops it, `try…` swallows failure.
- In scope is a name that does not start with a verb. `findSubscriptionFor`, `getCreatedAt`, `isDueAt` and the like are not touched.
- `…IfSet` marks a method that passes a null argument through and otherwise does its act, as `requireOwnedTagIfSet`. `pick…` chooses one value among given ones; `select…` picks a strategy object for a key; `load…` queries stored state and builds from it; `compute…` works a number or a time out.
- `keepIf…` passes a value through or answers null; `try…` swallows the failure of the act it names; `ensure…` is find-or-create; `issue…` hands out a ticket and may settle without one, as `PassLaneInterface::issueTickets`.
- The closing comment on #2400 lists the kept names of all five batches and names the shapes the issue did not cover (verb without object such as `cleanIn`; a preposition in the middle or at the start such as `idsForUser`, `forFeed`; bare nouns and participles such as `kept`, `existing`).

## The renames

The last column is what the reviewer checks the name against.

### Bluesky (paths under `backend/src/Service/Social/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Bluesky/BlueskyAppViewCountsSource.php` | `postAt` | `requirePost` | The one post view the AppView returns for an address; throws `SocialPostNotFoundException` when it returns none |
| `Bluesky/BlueskyCandidateSource.php` | `collectionIn` | `requireList` | The list under a field of the answer; throws `SocialNetworkUnreachableException` when the answer has none |
| `Bluesky/BlueskyFacets.php` | `didOrNull` | `tryFetchDid` | Fetches a handle's DID; null when the account cannot be resolved, an unreachable network still throws |
| `Bluesky/BlueskyFacets.php` | `mentionsIn` | `findMentionFacets` | Finds the mention spans of a text and builds a facet for each whose DID could be looked up; an unresolvable handle or an unreachable network leaves the mention plain |
| `Bluesky/BlueskyNotificationContextSource.php` | `postAt` | `findPost` | The post under a URI in a map of posts; null for a null URI or an unknown one |
| `Bluesky/BlueskyPostKeys.php` | `recordKeyFor` | `ensureRecordKey` | The record key remembered for an idempotency key, or a new one that is remembered; says which |
| `Bluesky/BlueskyPostResolver.php` | `referenceOrNull` | `parseReference` | Interprets a node into a post reference; null when uri or cid is missing or the uri is no post URI |
| `Bluesky/BlueskySavedCollections.php` | `curatedListIn` | `parseCuratedList` | Interprets a list node into a collection; null when the list is not a curated one (parse's absence); a list missing uri, purpose or name is a broken answer and throws |
| `Bluesky/BlueskySavedCollections.php` | `namesIn` | `collectNames` | Gathers the display name of each feed generator in an answer, by uri and by handle form, null where a generator has none; throws when the answer carries no feeds |
| `Bluesky/HiddenProfileAccountOnlyFeed.php` | `reasonFor` | `detectReason` | Recognises why there is no preview from whether the user has a ready Bluesky account: "connect a Bluesky account" without one, else "hidden profile" |
| `Bluesky/HiddenProfiles/HiddenProfilesInterface.php` and every implementation | `hiddenAmong` | `fetchHiddenProfiles` | Asks Bluesky, directly or behind the cache, which of the given actors hide their profile from logged-out readers, by actor; throws `SocialNetworkUnreachableException` when it cannot say (`AppViewHiddenProfiles`, `CachedHiddenProfiles`, `tests/Support/StubHiddenProfiles`) |
| `Bluesky/Support/BlueskyProfileLabels.php` | `hiddenAmong` | `collectHiddenProfiles` | Gathers, from fetched profiles, a hidden-profile model for each one whose labels hide it |
| `Bluesky/TolerantHiddenProfiles.php` | `hiddenAt` | `findHiddenProfile` | The hidden profile a Bluesky profile URL names; null for any other URL and when Bluesky cannot say |
| `Bluesky/TolerantHiddenProfiles.php` | `hiddenAmongOrNone` | `fetchHiddenProfilesOrEmpty` | The swallowing twin of `fetchHiddenProfiles`: logs the given warning and answers an empty list when Bluesky cannot say; its docblock reference follows |
| `Bluesky/HiddenImportFollows.php` | `among` | `findHiddenByIdentity` | Collects the follows' actors, asks through the tolerant wrapper and keys the hidden ones by identity |
| `Bluesky/Model/SealedBlueskySessionModel.php` | `textOrNull` | `readText` | The string under a key of the stored array, null when absent or no string |
| `Bluesky/Support/BlueskyProfileFeed.php` | `actorIn` | `decodeActor` | URL-decodes a path segment and normalises it as an actor key |
| `Bluesky/Support/BlueskyRecordUri.php` | `likeOrNull` | `keepIfLike` | Passes a URI through when it names a like record, else null, and null for a null input or a URI over 512 characters |
| `Bluesky/Support/BlueskyRecordUri.php` | `postOrNull` | `keepIfPost` | Passes a URI through when it names a post record, else null, and null for a null input or a URI over 512 characters |
| `Bluesky/Support/BlueskyRecordUri.php` | `repostOrNull` | `keepIfRepost` | Passes a URI through when it names a repost record, else null, and null for a null input or a URI over 512 characters |
| `Bluesky/Support/BlueskyTimelinePage.php` | `postedAt` | `resolvePostedAt` | A fallback chain between three fields for the time a timeline item landed: the repost's `indexedAt`, else the record's `createdAt`, else the post's `indexedAt` |
| `Bluesky/Support/AppViewAnswer.php` | `orSocialFailure` | `askAppView` | Refuses with the throttled exception while the AppView asks us to wait; else runs the given ask and turns a throttle or a failed answer into the social exceptions |
| `BlueskyPost/AppViewClient.php` | `listIn` | `decodeList` | Decodes a JSON body and returns the list under a field as nodes; throws `AppViewAnswerException` when there is no list |

### Mastodon, Lemmy, Completion, Share (paths under `backend/src/Service/Social/`)

| File | Old | New | What it does |
|---|---|---|---|
| `Mastodon/MastodonQuotedPostSource.php` | `answerFor` | `fetchAnswer` | Requests a status URL and returns the decoded answer; throws `QuotedPostUnavailableException` when the instance does not answer usably |
| `Lemmy/LemmyPostWriter.php` | `publishedAt` | `requirePublishedPost` | The published post named by the answer's `ap_id`; throws when the answer names no https address |
| `Completion/Model/KnownSharesModel.php` | `blueskyPostFor` | `findBlueskyPost` | The known Bluesky post of a guid, null when none |
| `Completion/Model/KnownSharesModel.php` | `linkCardFor` | `findLinkCard` | The known link card of a guid, null when none |
| `Completion/Model/KnownSharesModel.php` | `quotedPostFor` | `findQuotedPost` | The known quoted post of a guid, null when none |
| `Share/Support/SharedLinks.php` | `hrefsToCheckIn` | `collectSharedUrls` | Gathers the document's share anchors by tracking-free URL, in post order; the value is the posted href where an undocumented key was stripped and has to be checked, else null |
| `Share/Support/SharedLinks.php` | `linkUrlsIn` | `linkBareUrlsInText` | Replaces the bare URLs of one text node with anchors and returns how many it linked. `linkBareUrls` exists in the class |
| `Share/EntryShareClassifier.php` | `orProvisionalLink` | `resolveProvisionalKind` | A given kind stands; a missing one falls back to `Link` while the entry awaits its embed, else stays null |

### Social core (paths under `backend/src/Service/Social/Social/`)

| File | Old | New | What it does |
|---|---|---|---|
| `ActingAccountChooser.php` | `accountFor` | `requireAccount` | The account that acts on an entry for a network; throws `NoSocialAccountForNetworkException` when none is connected |
| `ComposerContexts.php` | `threadStarterOrNull` | `tryFindThreadStarter` | Finds the post that starts the target's thread as the post's account; null when it is gone, the account cannot ask or the network is unreachable |
| `ComposerDefaults/ComposerDefaultsInterface.php` and every implementation | `defaultsFor` | `resolveDefaults` | The composer defaults for a target; never fails, what the network does not answer falls back to its documented default |
| `DeletedPostTombstones.php` | `buriedIn` | `findBuriedHashes` | The guid hashes of a feed's posts deleted within the last seven days, from the cache |
| `ImportedProfilePictures.php` | `avatarFor` | `findAvatar` | The imported avatar of the identity a feed URL names, when it is an absolute URL that fits the column; null otherwise |
| `LinkPosts.php` | `contextFor` | `prepareContext` | Gets the link composer context ready: resolves the share for a link target over the network and builds the link or quoted-link context from it |
| `SocialResponses.php` | `contextFor` | `prepareContext` | Gets the response composer context ready: finds the post a response targets over the network and builds the reply or quote context from it |
| `SocialResponses.php` | `ownReplyAt` | `findOwnReply` | The reply when one of the accounts wrote it; throws the first account failure, or `SocialPostRefusedException` when another author wrote it or no account is connected; null only when every account says it is gone |
| `Model/TimelineSlotsModel.php` | `ticketFor` | `buildTicket` | Assembles the API request ticket with the per-account and per-server slots |
| `Pass/TimelineRound.php` | `ticketFor` | `issueTicket` | Starts the read of a feed and yields its request ticket; records a failed or settled outcome and yields nothing when the feed needs no request |
| `PrivateFeedSyncApplier.php` | `planFor` | `loadPlan` | Queries the account's subscribed and known collections and builds the sync plan against the network's |
| `ReplyThreadSource/ReplyThreadSourceInterface.php` and every implementation | `repliesIn` | `parseReplies` | Interprets a thread answer into the replies under an address; throws `CommentsUnavailableException` when the answer lacks the thread |
| `SharedPostQuoteFinder.php` | `quotableAt` | `findQuotable` | The post a URL names when it is on the account's network, can be found and allows quoting; null for another network, unreachable or not found, or quoting disallowed; still throws `SocialAccountNeedsReconnectException` and `SocialCredentialsRejectedException` |
| `SocialPostFinder.php` | `resolvedFor` | `findAsActingAccount` | Finds the post at an address as the account that acts on the entry for the user |
| `SocialRepliesSource.php` | `accountOrNull` | `findAccount` | The acting account for an entry and network, null when none is connected |
| `Support/SocialIdentity.php` | `accountAmong` | `findAccount` | The first of the accounts whose identity is the given author, null when none |
| `Support/NetworkAnswer.php` | `orUnreachable` | `askNetwork` | Runs the given ask against a network and returns its response; a transport failure becomes `SocialNetworkUnreachableException`, any status passes. The clients' `requireAnswer` keeps its name and calls it |
| `TimelineSelfThreads.php` | `sourceFor` | `selectSource` | Picks the reply thread source registered for the feed's network |

### One leftover in Content (paths under `backend/src/Service/Content/`)

| File | Old | New | What it does |
|---|---|---|---|
| `LinkCard/Support/TrailingUrl.php` | `removedFrom` | `stripTrailingUrl` | Returns the HTML without the given URL at the end of its last text, removing the text node when it ends up empty with a preceding `<br>` and a now-empty `<p>`; unchanged when it does not end with it |

## Review Focus

- Three interfaces are renamed with every implementation and test double: `HiddenProfilesInterface::hiddenAmong`, `ComposerDefaultsInterface::defaultsFor`, `ReplyThreadSourceInterface::repliesIn`. `BlueskyProfileLabels::hiddenAmong` is a static helper with the same old name and a different new one.
- `requireAnswer` on the three API clients keeps its name; after this batch it calls `NetworkAnswer::askNetwork`. `AppViewAnswer::askAppView` is a different act (throttle check and a wider catch).
- `ticketFor` is two methods with two new names (`buildTicket`, `issueTicket`); `TimelineLane` calls the second.
- `publishedAt` and `postedAt` are also properties and array keys across the tree: only `LemmyPostWriter::publishedAt` and `BlueskyTimelinePage::postedAt` are renamed.
- `hiddenAmongOrNone` and `HiddenImportFollows::among` got rows after review. Contested and kept: `parseCuratedList`, `findOwnReply`, `resolveDefaults`, `parseReplies`, `parseReference`, `linkBareUrlsInText`, `findHiddenProfile`, `collectHiddenProfiles`. Callers outside Social: `Ingestion/Refresh/IncomingPageStore.php` (`buriedIn`), `Content/.../EntryEmbedWriter.php` (`removedFrom`). `SearchAnswers` calls `$slots->ticketFor` in a closure. `FakeReplyThreadSource` logs 'repliesIn ', which follows the method name, and `SocialRepliesSourceTest:285` follows with it. Namesakes that stay: test-private `blueskyPostFor` (`EntrySocialViewerControllerTest`) and `publishedAt` (`SocialSearchControllerTest`), the `hiddenAt`/`postedAt` fields and keys, `namesIn` in `tests/PhpStan`, `DurableEmissionTest::sourceFor`.
- `findAccount` becomes the name of two methods in different classes (`SocialRepliesSource`, `SocialIdentity`); `ActingAccountChooser` already has a private `findAccount`. Check each class for a collision in its parents.

---

### Task 1: Bluesky

- [ ] **Step 1:** Rename the Bluesky rows, the `HiddenProfilesInterface` implementations and test doubles included, and every caller.
- [ ] **Step 2:** Run `composer cs:fix && composer check && composer md && php bin/phpunit` in `backend/`. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): Bluesky methods start with their verb`.

### Task 2: Mastodon, Lemmy, Completion, Share and the Content leftover

- [ ] **Step 1:** Rename the rows of the second and the last table and every caller.
- [ ] **Step 2:** Run the same four commands. Expected: all green.
- [ ] **Step 3:** Commit `refactor(#2400): network and share methods start with their verb`.

### Task 3: Social core

- [ ] **Step 1:** Rename the Social core rows, the interface implementations and test doubles included.
- [ ] **Step 2:** Run `grep -rnoE 'function\s+&?(\w+(OrNull|OrRefuse|For|In|At|Among)|or[A-Z]\w*)\s*\(' backend/src`. Expected: only names that start with a verb, the kept typed field readers and `AiSettingsJson::configurationFor`.
- [ ] **Step 3:** Run the same four commands. Expected: all green.
- [ ] **Step 4:** Commit `refactor(#2400): Social methods start with their verb`.

### Task 4: Gates and PR

- [ ] **Step 1:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; escaped mutants only on lines whose logic this PR did not change, listed in the PR.
- [ ] **Step 2:** Open the PR into `develop` with the rename tables, the kept names of all five batches, one consolidated follow-up list and `Closes #2400`; merge when green; verify #2400 closed and add the closing comment.
